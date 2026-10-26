<?php

declare(strict_types=1);

namespace Tinvar\Orm\Schema;

use Tinvar\Orm\Connection;
use Tinvar\Orm\Migrations\MigrationRefused;
use Tinvar\Orm\RowLevelSecurity;

/**
 * Postgres: the dialect the spec names first, and the one with row-level
 * security, which a tenant column turns on (see Table::tenant()).
 *
 * Schema changes are transactional, so a migration that fails leaves
 * nothing behind. Names resolve on the search path, as the application's
 * own queries do, so a schema-per-test or schema-per-tenant setup migrates
 * the schema it is pointed at.
 */
final class PostgresDialect extends Dialect
{
    /**
     * The advisory lock's first key, 'tinv' as an int4; the second is the
     * current schema's hash, so migrations of two schemas in one database
     * do not wait on each other.
     */
    public const int LOCK_KEY = 0x74696E76;

    public function name(): string
    {
        return 'pgsql';
    }

    public function transactionalDdl(): bool
    {
        return true;
    }

    protected function quoteOne(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    protected function type(Column $column): string
    {
        if ($column->caseInsensitive) {
            return 'citext';
        }

        return match ($column->type) {
            'uuid' => 'uuid',
            'string' => 'varchar(' . ($column->length ?? 255) . ')',
            'text' => 'text',
            'integer' => 'integer',
            'bigInteger' => 'bigint',
            'boolean' => 'boolean',
            'float' => 'double precision',
            'decimal' => 'numeric(' . ($column->length ?? 10) . ', ' . ($column->scale ?? 2) . ')',
            'json' => 'jsonb',
            'timestamp' => 'timestamptz',
            'date' => 'date',
            'binary' => 'bytea',
            default => $column->type,
        };
    }

    protected function booleanLiteral(bool $value): string
    {
        return $value ? 'TRUE' : 'FALSE';
    }

    /**
     * citext is a contrib extension, trusted since Postgres 13, so a role
     * that may create in the database can install it; it is installed on
     * the search path's first schema, the one the table is made in.
     */
    protected function beforeColumns(Table $table): array
    {
        foreach ($table->columns as $column) {
            if ($column->caseInsensitive) {
                return ['CREATE EXTENSION IF NOT EXISTS citext'];
            }
        }

        return [];
    }

    /**
     * The tenant policy for a tenant column added here: the column is a
     * varchar, which compares with the setting cast to text and keeps its
     * index.
     */
    protected function afterColumns(Table $table): array
    {
        return $table->tenantColumn === null ? [] : RowLevelSecurity::policy($table->name, $table->tenantColumn, 'text');
    }

    /**
     * In place, one statement a step: the default dropped before the type
     * changes, since the old default may not cast to the new type, and set
     * again after. Each value converts by a cast to the new type -- what
     * Postgres does only implicitly between related types -- and one that
     * does not convert fails the migration.
     */
    protected function changeColumn(string $table, Column $column): array
    {
        $alter = "ALTER TABLE {$this->quote($table)} ALTER COLUMN {$this->quote($column->name)}";
        $type = $this->type($column);

        return [
            "{$alter} DROP DEFAULT",
            "{$alter} TYPE {$type} USING {$this->quote($column->name)}::{$type}",
            ...($column->hasDefault ? ["{$alter} SET DEFAULT {$this->literal($column->default)}"] : []),
            $column->nullable ? "{$alter} DROP NOT NULL" : "{$alter} SET NOT NULL",
        ];
    }

    public function dropIndex(string $table, string $index): string
    {
        // An index lives in its table's schema.
        $dot = strrpos($table, '.');

        return 'DROP INDEX ' . $this->quote(($dot === false ? '' : substr($table, 0, $dot + 1)) . $index);
    }

    /**
     * The policy for a column already there, cast to the column's own type
     * -- a uuid to uuid, text and varchar to text -- so the comparison can
     * use its index rather than casting every row.
     */
    public function tenantPolicy(Connection $connection, string $table, string $column): array
    {
        $type = $connection->select(
            'SELECT format_type(a.atttypid, a.atttypmod) AS type FROM pg_attribute a '
            . 'WHERE a.attrelid = to_regclass(?) AND a.attname = ? AND a.attnum > 0 AND NOT a.attisdropped',
            [$this->quote($table), $column],
        )[0]['type'] ?? null;
        if (!\is_string($type)) {
            throw new \InvalidArgumentException("Table '{$table}' has no column '{$column}' to hold to a tenant policy.");
        }

        $cast = match (true) {
            str_starts_with($type, 'character'), $type === 'text', $type === 'citext' => 'text',
            default => (string) preg_replace('/\(.*\z/', '', $type),
        };

        return RowLevelSecurity::policy($table, $column, $cast);
    }

    public function dropTenantPolicy(string $table): array
    {
        $name = $this->quote($table);

        return [
            'DROP POLICY IF EXISTS ' . RowLevelSecurity::POLICY . " ON {$name}",
            "ALTER TABLE {$name} NO FORCE ROW LEVEL SECURITY",
            "ALTER TABLE {$name} DISABLE ROW LEVEL SECURITY",
        ];
    }

    public function hasTenantPolicy(Connection $connection, string $table): bool
    {
        $row = $connection->select(
            'SELECT c.relrowsecurity AND c.relforcerowsecurity AND EXISTS ('
            . 'SELECT 1 FROM pg_policy p WHERE p.polrelid = c.oid AND p.polname = ?) AS held '
            . 'FROM pg_class c WHERE c.oid = to_regclass(?)',
            [RowLevelSecurity::POLICY, $this->quote($table)],
        )[0] ?? null;

        return $row !== null && ($row['held'] ?? false) === true;
    }

    /**
     * Lifted for the transaction alone where there is one, so a rollback
     * or commit puts it back whatever $work did; otherwise for the session,
     * and put back after. Postgres would take the setting back with an
     * aborted transaction anyway, so a restore that fails there is let go.
     */
    public function acrossTenants(Connection $connection, \Closure $work): mixed
    {
        $local = $connection->inTransaction();
        $previous = $connection->select('SELECT current_setting(?, true) AS v', [RowLevelSecurity::ACROSS_SETTING])[0]['v'] ?? null;
        $connection->select('SELECT set_config(?, ?, ?)', [RowLevelSecurity::ACROSS_SETTING, 'on', $local ? 'true' : 'false']);

        try {
            return $work();
        } finally {
            try {
                $connection->select('SELECT set_config(?, ?, ?)', [
                    RowLevelSecurity::ACROSS_SETTING,
                    \is_string($previous) && $previous !== '' ? $previous : 'off',
                    $local ? 'true' : 'false',
                ]);
            } catch (\PDOException) {
            }
        }
    }

    public function tables(Connection $connection): array
    {
        return array_map(
            static fn (array $row): string => self::text($row, 'name'),
            $connection->select(
                "SELECT table_name AS name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE' ORDER BY table_name",
            ),
        );
    }

    public function hasTable(Connection $connection, string $table): bool
    {
        return ($connection->select('SELECT to_regclass(?) IS NOT NULL AS found', [$this->quote($table)])[0]['found'] ?? false) === true;
    }

    public function columns(Connection $connection, string $table): array
    {
        $columns = [];
        foreach ($connection->select(
            'SELECT a.attname AS name, format_type(a.atttypid, a.atttypmod) AS type, a.attnotnull AS notnull, '
            . 'pg_get_expr(d.adbin, d.adrelid) AS default_sql FROM pg_attribute a '
            . 'LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum '
            . 'WHERE a.attrelid = to_regclass(?) AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attnum',
            [$this->quote($table)],
        ) as $row) {
            [$kind, $default] = self::parseDefault(\is_string($row['default_sql'] ?? null) ? $row['default_sql'] : null);
            $columns[self::text($row, 'name')] = [
                'type' => self::text($row, 'type'),
                'nullable' => ($row['notnull'] ?? false) !== true,
                'default' => $default,
                'defaultKind' => $kind,
                'caseInsensitive' => self::text($row, 'type') === 'citext',
            ];
        }

        return $columns;
    }

    public function family(string $type): string
    {
        // format_type() spells varchar(255) `character varying(255)`, and a
        // precision can sit mid-type: `timestamp(6) with time zone`.
        $bare = trim((string) preg_replace(['/\([^)]*\)/', '/\s+/'], ['', ' '], strtolower($type)));

        return match ($bare) {
            'character varying', 'varchar', 'character', 'char', 'bpchar', 'text', 'citext', 'uuid', 'name' => 'text',
            'integer', 'int', 'int4', 'bigint', 'int8', 'smallint', 'int2' => 'integer',
            'boolean', 'bool' => 'boolean',
            'double precision', 'float8', 'real', 'float4' => 'float',
            'numeric', 'decimal' => 'decimal',
            'jsonb', 'json' => 'json',
            'timestamptz', 'timestamp with time zone', 'timestamp', 'timestamp without time zone' => 'timestamp',
            'date' => 'date',
            'bytea' => 'binary',
            default => $bare,
        };
    }

    public function builderType(string $type): ?array
    {
        $type = strtolower(trim($type));

        return match (true) {
            preg_match('/^character varying\((\d+)\)\z/', $type, $m) === 1 => ['type' => 'string', 'length' => (int) $m[1], 'scale' => null],
            preg_match('/^numeric\((\d+),\s*(\d+)\)\z/', $type, $m) === 1 => ['type' => 'decimal', 'length' => (int) $m[1], 'scale' => (int) $m[2]],
            \in_array($type, ['text', 'citext', 'character varying'], true) => ['type' => 'text', 'length' => null, 'scale' => null],
            $type === 'uuid' => ['type' => 'uuid', 'length' => null, 'scale' => null],
            $type === 'integer' => ['type' => 'integer', 'length' => null, 'scale' => null],
            $type === 'bigint' => ['type' => 'bigInteger', 'length' => null, 'scale' => null],
            $type === 'boolean' => ['type' => 'boolean', 'length' => null, 'scale' => null],
            $type === 'double precision' => ['type' => 'float', 'length' => null, 'scale' => null],
            $type === 'jsonb' => ['type' => 'json', 'length' => null, 'scale' => null],
            $type === 'timestamp with time zone' => ['type' => 'timestamp', 'length' => null, 'scale' => null],
            $type === 'date' => ['type' => 'date', 'length' => null, 'scale' => null],
            $type === 'bytea' => ['type' => 'binary', 'length' => null, 'scale' => null],
            default => self::databaseType($type),
        };
    }

    public function indexes(Connection $connection, string $table): array
    {
        $indexes = [];
        foreach ($connection->select(
            'SELECT c.relname AS name, ix.indisunique AS is_unique, ('
            . 'SELECT string_agg(a.attname, \',\' ORDER BY k.ord) FROM unnest(ix.indkey) WITH ORDINALITY k(attnum, ord) '
            . 'JOIN pg_attribute a ON a.attrelid = ix.indrelid AND a.attnum = k.attnum) AS columns '
            . 'FROM pg_index ix JOIN pg_class c ON c.oid = ix.indexrelid WHERE ix.indrelid = to_regclass(?) ORDER BY c.relname',
            [$this->quote($table)],
        ) as $row) {
            $columns = self::text($row, 'columns');
            $indexes[] = [
                'name' => self::text($row, 'name'),
                'columns' => $columns === '' ? [] : explode(',', $columns),
                'unique' => ($row['is_unique'] ?? false) === true,
            ];
        }

        return $indexes;
    }

    /**
     * A session-level advisory lock, which Postgres lets go when the
     * session ends, however it ends. Tried rather than waited on, so the
     * wait has a timeout and the deploy that gives up says why.
     */
    public function lock(Connection $connection, float $timeout): void
    {
        $taken = self::waitFor(
            static fn (): bool => ($connection->select(
                'SELECT pg_try_advisory_lock(?, hashtext(coalesce(current_schema(), \'\'))) AS taken',
                [self::LOCK_KEY],
            )[0]['taken'] ?? false) === true,
            $timeout,
        );

        if (!$taken) {
            throw MigrationRefused::locked($timeout, 'the Postgres advisory lock');
        }
    }

    public function unlock(Connection $connection): void
    {
        $connection->select('SELECT pg_advisory_unlock(?, hashtext(coalesce(current_schema(), \'\')))', [self::LOCK_KEY]);
    }

    /**
     * Every view, materialized view and table in the current schema, each
     * kind at once and with CASCADE, so the foreign keys between them go
     * too. Other schemas -- another application's, or the extensions' --
     * are not touched, and nor are the schema's sequences and types: a type
     * there may be an extension's, citext's, which the tables made with it
     * need again.
     */
    public function dropAllTables(Connection $connection): array
    {
        $dropped = [];
        foreach ([
            'VIEW' => 'SELECT table_name AS name FROM information_schema.views WHERE table_schema = current_schema() ORDER BY table_name',
            'MATERIALIZED VIEW' => 'SELECT matviewname AS name FROM pg_matviews WHERE schemaname = current_schema() ORDER BY matviewname',
        ] as $kind => $query) {
            $names = array_map(static fn (array $row): string => self::text($row, 'name'), $connection->select($query));
            if ($names !== []) {
                $connection->execute("DROP {$kind} IF EXISTS " . implode(', ', array_map($this->quoteOne(...), $names)) . ' CASCADE');
                $dropped = [...$dropped, ...$names];
            }
        }

        $tables = $this->tables($connection);
        if ($tables !== []) {
            $connection->execute('DROP TABLE IF EXISTS ' . implode(', ', array_map($this->quoteOne(...), $tables)) . ' CASCADE');
        }

        return [...$dropped, ...$tables];
    }
}
