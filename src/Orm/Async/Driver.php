<?php

declare(strict_types=1);

namespace Tinvar\Orm\Async;

use Amp\Mysql\MysqlConnection;
use Amp\Postgres\PostgresConnection;
use Amp\Sql\SqlResult;

/**
 * What the async connection needs said in each database's own way: how to
 * open a session, tell it its tenant, clear it, begin a transaction, send a
 * statement, and read a failure.
 *
 * @internal AsyncConnection's, inside this package
 */
interface Driver
{
    /** PDO's name for the driver, 'pgsql' or 'mysql'. */
    public function name(): string;

    /** A new session, ready for statements: what every session of the pool's starts from. */
    public function connect(): PostgresConnection|MysqlConnection;

    /** The server's id for a session's backend, which cancel() names: read once, when it is opened. */
    public function backend(PostgresConnection|MysqlConnection $link): int;

    /**
     * Stops the statement a session's backend is running, from a
     * connection of its own: for a session given up mid-statement, before
     * it is closed.
     *
     * Closing alone is not enough. Postgres runs the statement on to its
     * end whatever the client does, and ext-pgsql, freeing a connection
     * with an answer still to come, waits for that answer -- so a session
     * closed under a long statement stalled the worker until the statement
     * finished, timeout or not. MySQL runs it on too, holding what it
     * holds. A failure here is the caller's to ignore: the session is
     * closed either way.
     */
    public function cancel(int $backend): void;

    /**
     * The tenant as this driver tells it, for comparing with what a session
     * was last told. A driver that tells none answers the same for every
     * tenant, so nothing is ever sent.
     *
     * @param list<string> $subtree
     */
    public function tenantKey(string $tenant, bool $across, array $subtree): string;

    /**
     * Tells a session outside a transaction its tenant and whether it
     * refuses writes, session-wide, in one round trip -- answering which
     * backend took it, where the tenant is a session setting a pooler could
     * have handed to another (SessionMoved), and null where it is not.
     *
     * @param list<string> $subtree
     */
    public function tell(PostgresConnection|MysqlConnection $link, string $tenant, bool $across, array $subtree, bool $readOnly): ?int;

    /**
     * Tells a session inside a transaction its tenant alone: whether it
     * refuses writes is the transaction's to say from inside it. Answers as
     * tell() does.
     *
     * @param list<string> $subtree
     */
    public function tellTenant(PostgresConnection|MysqlConnection $link, string $tenant, bool $across, array $subtree): ?int;

    /** The statement that begins a transaction, read-only or not. */
    public function begin(bool $readOnly): string;

    /**
     * The statements that make a transaction already open refuse writes
     * for a while and then let it write again -- a savepoint set read-only,
     * and rolled back to -- or null where the database has no way to.
     *
     * @return array{string, string}|null
     */
    public function readOnlySavepoint(): ?array;

    /**
     * Puts a session outside a transaction back as a new one stands:
     * everything this connection told it, and anything a raw statement set
     * on it besides, where the database can say so.
     */
    public function clear(PostgresConnection|MysqlConnection $link): void;

    /**
     * Sends one statement with its bindings, which are given as the sync
     * driver binds them (PdoConnection::bind()).
     *
     * @param list<mixed> $bindings
     * @return SqlResult<mixed>
     */
    public function execute(PostgresConnection|MysqlConnection $link, string $sql, array $bindings): SqlResult;

    /** A failure from the database client as the sync driver would have thrown it. */
    public function error(\Throwable $failure): \PDOException;

    /** Whether a failure is the database refusing a write it was told to refuse. */
    public function refusedWrite(\PDOException $failure): bool;
}
