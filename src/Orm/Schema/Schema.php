<?php

declare(strict_types=1);

namespace Tinvar\Orm\Schema;

use Tinvar\Orm\Connection;

/**
 * What a migration is handed: the schema builder, and the connection for
 * what the builder does not say -- a data backfill, a statement only one
 * database has.
 *
 * Each call runs its statements as it is made, in order, so a backfill
 * between two changes sees the first and not the second. The migrator runs
 * a migration in a transaction where the database allows it, so a failure
 * part way through takes back everything before it.
 *
 * Migrations run with no tenant ("Scheduled tasks and migrations run with
 * no tenant", tinvar-spec.md), and on Postgres a table under the tenant
 * policy shows them no rows: a backfill of one goes in acrossTenants().
 */
final class Schema
{
    /**
     * Every statement this schema has run to completion, for MigrationFailed
     * to report: on MySQL, which commits each schema change as it runs,
     * they are what a failed migration left behind.
     *
     * @var list<string>
     */
    private array $ran = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly Dialect $dialect,
    ) {}

    /**
     * Makes a table. $ifNotExists leaves one already there as it is, with
     * its indexes: for a first migration adopting a database built before
     * there were migrations.
     *
     * @param \Closure(Table): void $define
     */
    public function create(string $table, \Closure $define, bool $ifNotExists = false): void
    {
        $definition = new Table($table, creating: true);
        $define($definition);
        if ($definition->columns === []) {
            throw new \LogicException("Table '{$table}' has no columns.");
        }

        $this->run($this->dialect->createTable($definition, $ifNotExists));
    }

    /**
     * Changes a table: columns added, renamed, changed (Column::change())
     * or dropped, indexes added or dropped.
     *
     * @param \Closure(Table): void $define
     */
    public function table(string $table, \Closure $define): void
    {
        $definition = new Table($table, creating: false);
        $define($definition);

        $this->dialect->applyAlter($this->connection, $definition, $this->run(...));
    }

    public function drop(string $table): void
    {
        $this->run($this->dialect->dropTable($table));
    }

    public function dropIfExists(string $table): void
    {
        $this->run($this->dialect->dropTable($table, ifExists: true));
    }

    public function rename(string $from, string $to): void
    {
        $this->run($this->dialect->renameTable($from, $to));
    }

    /**
     * Holds an existing table's tenant column to row-level security on
     * Postgres (RowLevelSecurity); nothing elsewhere. A table made with
     * Table::tenant() has it already.
     */
    public function tenantPolicy(string $table, string $column = 'tenant_id'): void
    {
        Identifier::assert($table, 'table');
        Identifier::assert($column, 'column');
        $this->run($this->dialect->tenantPolicy($this->connection, $table, $column));
    }

    public function dropTenantPolicy(string $table): void
    {
        $this->run($this->dialect->dropTenantPolicy($table));
    }

    /**
     * A statement of the migration's own: a backfill, or what only one
     * database says. Counted among the statements MigrationFailed reports.
     *
     * @param list<mixed> $bindings
     * @return int rows affected
     */
    public function sql(string $sql, array $bindings = []): int
    {
        $affected = $this->connection->execute($sql, $bindings);
        $this->ran[] = $sql;

        return $affected;
    }

    /**
     * @param list<mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->connection->select($sql, $bindings);
    }

    /**
     * Runs $work with the tenant policy lifted, as Orm::acrossTenants()
     * lifts the ORM's filter: for a backfill of a tenant table, which on
     * Postgres would otherwise see none of its rows.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function acrossTenants(\Closure $work): mixed
    {
        return $this->dialect->acrossTenants($this->connection, $work);
    }

    public function hasTable(string $table): bool
    {
        return $this->dialect->hasTable($this->connection, $table);
    }

    public function hasColumn(string $table, string $column): bool
    {
        return \array_key_exists($column, $this->dialect->columns($this->connection, $table));
    }

    /** sqlite, pgsql or mysql: for a statement only one of them has. */
    public function driver(): string
    {
        return $this->dialect->name();
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    /** @return list<string> */
    public function statements(): array
    {
        return $this->ran;
    }

    /** @param list<string> $statements */
    private function run(array $statements): void
    {
        foreach ($statements as $statement) {
            $this->connection->execute($statement);
            $this->ran[] = $statement;
        }
    }
}
