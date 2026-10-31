<?php

declare(strict_types=1);

namespace Tinvar\Orm\Async;

use Amp\Mysql\MysqlConnection;
use Amp\Postgres\Internal\PgSqlHandle;
use Amp\Postgres\Internal\PostgresHandleConnection;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresQueryError;
use Amp\Postgres\PostgresConnection;
use Amp\Sql\SqlResult;
use Revolt\EventLoop;
use Tinvar\Orm\RowLevelSecurity;

use function Amp\Postgres\connect;

/**
 * Postgres through amphp/postgres, over ext-pgsql's non-blocking calls.
 *
 * A session is told its tenant as PdoConnection tells its one session
 * (ADR 0026): set_config() of the three settings row-level security reads,
 * session-wide. Outside a transaction it is told in the same round trip
 * whether to refuse writes, by default_transaction_read_only, which every
 * statement outside a transaction runs under.
 *
 * Cleared by DISCARD ALL, what a pooler clears a session with: every
 * setting back to how the session began -- the three above, the read-only
 * default, a role or search path a raw statement set -- and its temporary
 * tables, prepared statements, advisory locks and LISTENs gone. What the
 * session began with, a search path or role given in the connection's
 * options, is what it goes back to.
 *
 * @internal AsyncConnection's, inside this package
 */
final readonly class PostgresDriver implements Driver
{
    public function __construct(private PostgresConfig $config) {}

    public function name(): string
    {
        return 'pgsql';
    }

    public function connect(): PostgresConnection
    {
        $link = connect($this->config);
        self::closeOnceEnded($link);

        return $link;
    }

    /**
     * Closes the session as soon as libpq says the server ended it.
     *
     * When the server ends a session -- a restart, pg_terminate_backend(),
     * a proxy's timeout -- libpq reads its goodbye and closes the socket,
     * and amphp/postgres 2.2.1 can leave its readable watcher on that
     * socket: it reads the goodbye without asking the connection's status
     * after. The next time the event loop waits, stream_select() cannot use
     * the closed socket, and Revolt fails the whole loop -- every statement
     * of every fiber after it, in that process, until it restarts.
     *
     * So a second watcher on the same socket, registered after amphp's and
     * so run after it in the same tick, asks libpq's status each time the
     * socket is readable, and closes the session -- amphp's watchers with
     * it, its pending statement failed -- before the loop waits again. It
     * reaches the connection through amphp's private properties, which the
     * exact version the package requires holds still; a release that moves
     * them leaves the session unguarded, as it was before this.
     */
    private static function closeOnceEnded(PostgresConnection $link): void
    {
        try {
            $handle = new \ReflectionProperty(PostgresHandleConnection::class, 'handle')->getValue($link);
            $pgsql = $handle instanceof PgSqlHandle ? new \ReflectionProperty(PgSqlHandle::class, 'handle')->getValue($handle) : null;
        } catch (\ReflectionException|\TypeError) {
            return;
        }
        if (!$pgsql instanceof \PgSql\Connection) {
            return;
        }
        $socket = @pg_socket($pgsql);
        if ($socket === false) {
            return;
        }

        $session = \WeakReference::create($link);
        $watcher = EventLoop::onReadable($socket, static function (string $watcher) use ($pgsql, $session): void {
            if (pg_connection_status($pgsql) === \PGSQL_CONNECTION_OK) {
                return;
            }
            EventLoop::cancel($watcher);
            $link = $session->get();
            if ($link !== null && !$link->isClosed()) {
                $link->close();
            }
        });
        EventLoop::unreference($watcher);
        // Held until the session closes, so the connection -- and the
        // socket the watcher waits on -- outlives the watcher.
        $link->onClose(static function () use ($watcher): void {
            EventLoop::cancel($watcher);
        });
    }

    public function backend(PostgresConnection|MysqlConnection $link): int
    {
        $pid = $link->query('SELECT pg_backend_pid() AS pid')->fetchRow()['pid'] ?? null;

        return \is_int($pid) ? $pid : throw new \UnexpectedValueException('Postgres did not say which backend a session is.');
    }

    /**
     * pg_cancel_backend(), from a connection made without the config's
     * options: as the user that logged in, which may cancel its own
     * backends, where a role set by the options -- the policy's, which
     * logs in as no one -- may not.
     */
    public function cancel(int $backend): void
    {
        $helper = connect($this->config->withoutOptions());
        try {
            $helper->execute('SELECT pg_cancel_backend($1)', [$backend]);
        } finally {
            $helper->close();
        }
    }

    public function tenantKey(string $tenant, bool $across, array $subtree): string
    {
        return $tenant . "\0" . ($across ? 'on' : 'off') . "\0" . RowLevelSecurity::arrayLiteral($subtree);
    }

    public function tell(PostgresConnection|MysqlConnection $link, int $backend, string $tenant, bool $across, array $subtree, bool $readOnly): int
    {
        return self::backendOf($link->execute(
            // Numbered in the order they appear: the read-only setting's
            // in the list, before the backend expected in the FROM.
            RowLevelSecurity::tellOnBackend('$1', '$2', '$3', '$5', '$4'),
            [$tenant, $across ? 'on' : 'off', RowLevelSecurity::arrayLiteral($subtree), $readOnly ? 'on' : 'off', $backend],
        ));
    }

    public function tellTenant(PostgresConnection|MysqlConnection $link, int $backend, string $tenant, bool $across, array $subtree): int
    {
        return self::backendOf($link->execute(
            RowLevelSecurity::tellOnBackend('$1', '$2', '$3', '$4'),
            [$tenant, $across ? 'on' : 'off', RowLevelSecurity::arrayLiteral($subtree), $backend],
        ));
    }

    /**
     * The backend a tell's answer names; -1, never a backend, when it
     * names none.
     *
     * @param SqlResult<mixed> $result
     */
    private static function backendOf(SqlResult $result): int
    {
        $row = $result->fetchRow();
        $pid = \is_array($row) ? ($row['pid'] ?? null) : null;

        return \is_int($pid) ? $pid : -1;
    }

    public function begin(bool $readOnly): string
    {
        return $readOnly ? 'START TRANSACTION READ ONLY' : 'START TRANSACTION READ WRITE';
    }

    public function readOnlySavepoint(): array
    {
        return [
            'SAVEPOINT tinvar_read_only; SET LOCAL transaction_read_only = on',
            'ROLLBACK TO SAVEPOINT tinvar_read_only; RELEASE SAVEPOINT tinvar_read_only',
        ];
    }

    public function clear(PostgresConnection|MysqlConnection $link): void
    {
        $link->query('DISCARD ALL');
    }

    /** @return SqlResult<mixed> */
    public function execute(PostgresConnection|MysqlConnection $link, string $sql, array $bindings): SqlResult
    {
        return $link->execute(Bindings::numbered($sql), Bindings::asText($bindings));
    }

    public function error(\Throwable $failure): \PDOException
    {
        return match (true) {
            $failure instanceof QueryError => $failure,
            // The server ending the session answers as a statement's error
            // would -- admin_shutdown, crash_shutdown, cannot_connect_now, or
            // a connection exception -- and libpq has closed the socket by
            // the time it is read: the session failed, and goes.
            $failure instanceof PostgresQueryError => new QueryError(
                $state = self::sqlState($failure),
                7,
                $failure->getMessage(),
                sessionFailed: str_starts_with($state, '08') || \in_array($state, ['57P01', '57P02', '57P03'], true),
            ),
            // The session failed rather than the statement: gone, closed, or
            // answering what the client could not read.
            default => QueryError::sessionFailed('08006', 7, $failure),
        };
    }

    public function refusedWrite(\PDOException $failure): bool
    {
        // read_only_sql_transaction
        return ($failure->errorInfo[0] ?? null) === '25006';
    }

    private static function sqlState(PostgresQueryError $failure): string
    {
        $state = $failure->getDiagnostics()['sqlstate'] ?? null;

        return \is_string($state) && $state !== '' ? $state : 'HY000';
    }
}
