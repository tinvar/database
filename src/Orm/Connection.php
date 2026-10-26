<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * The ORM's one driver interface. PDO backs the sync path; an amphp driver
 * backs fiber mode later (tinvar-spec.md, "Engine layers").
 *
 * Statement counting is part of the interface because query budgets and the
 * batching assertions in the test layer are built on it.
 */
interface Connection
{
    /**
     * @param list<mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array;

    /** @param list<mixed> $bindings */
    public function execute(string $sql, array $bindings = []): int;

    /** Statements issued since the last reset: the number batching must hold down. */
    public function statementCount(): int;

    public function resetStatementCount(): void;

    /** @return list<string> the SQL issued since the last reset, for assertions and the dev toolbar */
    public function statementLog(): array;

    public function name(): string;

    /**
     * Runs $work in a transaction, committing on return and rolling back on a
     * throw. A nested call runs in a savepoint of the outer transaction: a
     * throw rolls back that call's writes and no others, and still propagates,
     * so the outer caller decides whether to carry on. What an inner call
     * that returned wrote commits or rolls back with the outer one.
     *
     * A statement that fails inside it and is caught there is undone alone
     * on SQLite. On Postgres it aborts the transaction, which can then only
     * roll back: the outermost call does, and throws rather than return as
     * if it had committed. A statement that may fail goes in a nested call,
     * whose savepoint takes the failure back alone, on both.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function transaction(\Closure $work): mixed;

    /**
     * transaction(), with its commit on disk before it returns: for a write
     * a security decision rests on, which a power cut must not take back --
     * a session ended, an auth epoch moved, a permission taken away.
     *
     * Where every commit is synced already -- Postgres's and MySQL's
     * defaults, SQLite at FULL -- it is transaction(). A SQLite connection
     * opened to sync NORMAL (PdoConnection::sqlite(), ADR 0050) syncs this
     * one commit in full, and with it every commit before it.
     *
     * Only the outermost transaction commits, so this must be the outermost:
     * called inside another, it throws rather than run undurably.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function durableTransaction(\Closure $work): mixed;

    public function inTransaction(): bool;

    /**
     * Runs $work with the database refusing writes: the read-only
     * transaction loaders and query bodies run in ("Safe reads").
     *
     * Scopes are counted, not stacked. A render's fibers each open one,
     * suspend inside it and finish in any order, all on this one
     * connection, which can hold one transaction at a time -- so the
     * database is read-only from the first scope opened to the last one
     * closed, and every statement in between, from any fiber, runs in it.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function readOnly(\Closure $work): mixed;

    /** Whether a read-only scope is open on this connection, in any fiber. */
    public function isReadOnly(): bool;

    /**
     * Rolls back a transaction still open, savepoints and all, and ends a
     * read-only scope still open; nothing when neither is. For the end of a
     * request: whatever left one open, the next request on a worker must not
     * start inside it.
     *
     * @return bool whether a transaction was open
     */
    public function rollBackOpenTransaction(): bool;
}
