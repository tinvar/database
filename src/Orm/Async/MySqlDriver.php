<?php

declare(strict_types=1);

namespace Tinvar\Orm\Async;

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\Internal\ConnectionProcessor;
use Amp\Mysql\MysqlConnection;
use Amp\Mysql\SocketMysqlConnection;
use Amp\Postgres\PostgresConnection;
use Amp\Sql\SqlQueryError;
use Amp\Sql\SqlResult;

use function Amp\Mysql\connect;

/**
 * MySQL through amphp/mysql, which speaks the protocol itself over a
 * non-blocking socket.
 *
 * MySQL has no row-level security, and the sync driver tells a MySQL
 * session no tenant (ADR 0026), so neither does this: the ORM's predicate
 * is the one line there. A session is told whether to refuse writes, by
 * transaction_read_only, which statements outside a transaction run under
 * as well -- where PdoConnection holds a MySQL read-only scope in PHP alone
 * -- and cleared on its way back by MySQL's own reset, which takes
 * everything a raw statement left on it as well (clear()).
 *
 * @internal AsyncConnection's, inside this package
 */
final readonly class MySqlDriver implements Driver
{
    public function __construct(private MysqlConfig $config) {}

    public function name(): string
    {
        return 'mysql';
    }

    public function connect(): MysqlConnection
    {
        return connect($this->config);
    }

    public function backend(PostgresConnection|MysqlConnection $link): int
    {
        // A prepared statement, whose answer is typed: a plain query's is text.
        $id = $link->execute('SELECT CONNECTION_ID() AS id')->fetchRow()['id'] ?? null;

        return \is_int($id) ? $id : throw new \UnexpectedValueException('MySQL did not say which connection a session is.');
    }

    /** KILL QUERY, which a user may send for its own connections. */
    public function cancel(int $backend): void
    {
        $helper = connect($this->config);
        try {
            $helper->query('KILL QUERY ' . $backend);
        } finally {
            $helper->close();
        }
    }

    public function tenantKey(string $tenant, bool $across, array $subtree): string
    {
        return '';
    }

    public function tell(PostgresConnection|MysqlConnection $link, string $tenant, bool $across, array $subtree, bool $readOnly): void
    {
        $link->query('SET SESSION transaction_read_only = ' . ($readOnly ? 'ON' : 'OFF'));
    }

    public function tellTenant(PostgresConnection|MysqlConnection $link, string $tenant, bool $across, array $subtree): void {}

    public function begin(bool $readOnly): string
    {
        return $readOnly ? 'START TRANSACTION READ ONLY' : 'START TRANSACTION READ WRITE';
    }

    /** MySQL will not change a transaction's access mode once it has begun. */
    public function readOnlySavepoint(): ?array
    {
        return null;
    }

    /**
     * COM_RESET_CONNECTION, MySQL's own reset: an open transaction rolled
     * back, table locks and GET_LOCK() locks released, temporary tables and
     * prepared statements dropped, user variables gone, every session
     * variable back to the server's -- whatever a raw statement left. Then
     * what the session was opened with, its character set and SQL mode,
     * which the reset set back to the server's too, is set again.
     *
     * amphp/mysql sends the command but keeps it to its connection's
     * internals, so it is reached by reflection, on the exact version
     * pinned. Should that ever fail -- another class, the property gone --
     * the clearing fails, and the pool closes the session rather than lend
     * it on uncleared.
     */
    public function clear(PostgresConnection|MysqlConnection $link): void
    {
        if (!$link instanceof SocketMysqlConnection) {
            throw new \LogicException('Only amphp/mysql\'s socket connection can be reset.');
        }

        $processor = new \ReflectionProperty(SocketMysqlConnection::class, 'processor')->getValue($link);
        if (!$processor instanceof ConnectionProcessor) {
            throw new \LogicException('amphp/mysql\'s connection no longer keeps the processor this resets it through.');
        }
        $processor->resetConnection()->await();

        $link->useCharacterSet($this->config->getCharset(), $this->config->getCollation());
        $mode = $this->config->getSqlMode();
        if ($mode !== null) {
            $link->execute('SET SESSION sql_mode = ?', [$mode]);
        }
    }

    /** @return SqlResult<mixed> */
    public function execute(PostgresConnection|MysqlConnection $link, string $sql, array $bindings): SqlResult
    {
        try {
            return $link->execute($sql, Bindings::asText($bindings));
        } catch (SqlQueryError $e) {
            // ER_UNSUPPORTED_PS: a statement MySQL will not prepare --
            // START TRANSACTION, LOCK TABLES. pdo_mysql sends one of these
            // as text instead, and so does this, when there is nothing to
            // bind: its answer is text, as PDO's is then.
            if ($bindings !== [] || !str_starts_with($e->getMessage(), 'MySQL error (1295)')) {
                throw $e;
            }

            return $link->query($sql);
        }
    }

    public function error(\Throwable $failure): \PDOException
    {
        if ($failure instanceof QueryError) {
            return $failure;
        }

        // The client spells the server's error packet into its message:
        // "MySQL error (1062): #23000 Duplicate entry ...".
        if ($failure instanceof SqlQueryError
            && preg_match('/^MySQL error \((\d+)\): (?:#?([0-9A-Z]{5})|Unknown state) (.*)$/s', $failure->getMessage(), $m) === 1) {
            return new QueryError($m[2] !== '' ? $m[2] : 'HY000', (int) $m[1], $m[3]);
        }

        return QueryError::sessionFailed('HY000', 2013, $failure);
    }

    public function refusedWrite(\PDOException $failure): bool
    {
        // ER_CANT_EXECUTE_IN_READ_ONLY_TRANSACTION
        return ($failure->errorInfo[1] ?? null) === 1792;
    }
}
