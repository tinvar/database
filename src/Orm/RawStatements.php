<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * The connection Orm::connection() hands out while raw statements are
 * flagged (Orm::flagRawStatements()): the Orm's own, every call passed
 * straight through, with each select() and execute() that names a tenant
 * table reported first.
 *
 * Only what is sent through it is seen: the ORM's own statements, and the
 * query builder's, go to the connection itself and are never flagged --
 * they carry the tenant predicate. Raw PDO, and a connection held from
 * somewhere else, are not seen at all; the static check reads those.
 *
 * @internal made by Orm::connection(), inside this package
 */
final readonly class RawStatements implements Connection
{
    /**
     * @param \Closure(string, \Closure(): string): void $flag given each statement, and what says where
     *        it was sent from -- asked only for one that is flagged, since a backtrace is not free
     */
    public function __construct(private Connection $connection, private \Closure $flag) {}

    public function select(string $sql, array $bindings = []): array
    {
        ($this->flag)($sql, self::site(...));

        return $this->connection->select($sql, $bindings);
    }

    public function execute(string $sql, array $bindings = []): int
    {
        ($this->flag)($sql, self::site(...));

        return $this->connection->execute($sql, $bindings);
    }

    public function statementCount(): int
    {
        return $this->connection->statementCount();
    }

    public function resetStatementCount(): void
    {
        $this->connection->resetStatementCount();
    }

    public function statementLog(): array
    {
        return $this->connection->statementLog();
    }

    public function name(): string
    {
        return $this->connection->name();
    }

    public function transaction(\Closure $work): mixed
    {
        return $this->connection->transaction($work);
    }

    public function durableTransaction(\Closure $work): mixed
    {
        return $this->connection->durableTransaction($work);
    }

    public function inTransaction(): bool
    {
        return $this->connection->inTransaction();
    }

    public function readOnly(\Closure $work): mixed
    {
        return $this->connection->readOnly($work);
    }

    public function isReadOnly(): bool
    {
        return $this->connection->isReadOnly();
    }

    public function rollBackOpenTransaction(): bool
    {
        return $this->connection->rollBackOpenTransaction();
    }

    /** The file and line that called select() or execute() here. */
    private static function site(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8) as $frame) {
            if (($frame['class'] ?? null) === self::class && \in_array($frame['function'], ['select', 'execute'], true)) {
                return isset($frame['file'], $frame['line']) ? "{$frame['file']}:{$frame['line']}" : '';
            }
        }

        return '';
    }
}
