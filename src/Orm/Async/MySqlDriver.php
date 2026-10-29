<?php

declare(strict_types=1);

namespace Tinvar\Orm\Async;

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnection;
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
 * -- and cleared by setting it back. A session-wide setting a raw
 * statement makes is not cleared: MySQL's own reset is a protocol command
 * the client keeps to itself, and it would drop what the session was
 * opened with, the SQL mode, too.
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

    public function clear(PostgresConnection|MysqlConnection $link): void
    {
        $link->query('SET SESSION transaction_read_only = OFF');
    }

    /** @return SqlResult<mixed> */
    public function execute(PostgresConnection|MysqlConnection $link, string $sql, array $bindings): SqlResult
    {
        return $link->execute($sql, Bindings::asText($bindings));
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
