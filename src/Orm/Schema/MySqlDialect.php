<?php

declare(strict_types=1);

namespace Tinvar\Orm\Schema;

use Tinvar\Orm\Connection;
use Tinvar\Orm\Migrations\MigrationRefused;

/**
 * MySQL 8, as a dialect layer under the same builder.
 *
 * MySQL commits every schema change as it runs, so a migration here is not
 * one transaction: one that fails part way leaves the changes before the
 * failure in place, and MigrationFailed lists them. Tables default to a
 * binary collation, so text compares by case as it does on SQLite and
 * Postgres; caseInsensitive() asks for the other. Indexes are declared
 * inside CREATE TABLE, which is how CREATE TABLE IF NOT EXISTS covers them:
 * MySQL has no CREATE INDEX IF NOT EXISTS.
 */
final class MySqlDialect extends Dialect
{
    public function name(): string
    {
        return 'mysql';
    }

    public function transactionalDdl(): bool
    {
        return false;
    }

    protected function quoteOne(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    protected function type(Column $column): string
    {
        return match ($column->type) {
            'uuid' => 'CHAR(36)',
            'string' => 'VARCHAR(' . ($column->length ?? 255) . ')',
            'text' => 'TEXT',
            'integer' => 'INT',
            'bigInteger' => 'BIGINT',
            'boolean' => 'TINYINT(1)',
            'float' => 'DOUBLE',
            'decimal' => 'DECIMAL(' . ($column->length ?? 10) . ', ' . ($column->scale ?? 2) . ')',
            'json' => 'JSON',
            'timestamp' => 'DATETIME(6)',
            'date' => 'DATE',
            'binary' => 'LONGBLOB',
            default => $column->type,
        };
    }

    protected function caseInsensitiveCollation(): string
    {
        return ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    protected function tableOptions(): string
    {
        return ' ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_bin';
    }

    /** Backslashes too: MySQL reads one in a string literal as an escape unless told not to. */
    protected function stringLiteral(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $value) . "'";
    }

    protected function indexesInTable(Table $table): array
    {
        $lines = [];
        foreach ($table->indexes as $index) {
            $columns = [];
            foreach ($index->columns as [$column, $direction]) {
                $columns[] = $this->quote($column) . ($direction === '' ? '' : " {$direction}");
            }
            $lines[] = \sprintf('%sINDEX %s (%s)', $index->unique ? 'UNIQUE ' : '', $this->quote($index->name), implode(', ', $columns));
        }

        return $lines;
    }

    protected function indexesAfterTable(Table $table): array
    {
        return [];
    }

    /**
     * MySQL has no ON CONFLICT. INSERT IGNORE skips a row whose key is
     * taken and reports none affected, whatever the connection's
     * found-rows setting -- which would make ON DUPLICATE KEY UPDATE's
     * no-op report the duplicate as a row, and a replay as a claim won. It
     * also turns other errors into warnings, a value too long for its
     * column cut short among them, so the callers hold their values to
     * their columns' lengths before they insert.
     */
    public function insertUnlessTaken(string $table, array $columns): string
    {
        return \sprintf(
            'INSERT IGNORE INTO %s (%s) VALUES (%s)',
            $this->quote($table),
            $this->columnList($columns),
            implode(', ', array_fill(0, \count($columns), '?')),
        );
    }

    public function createIndex(Index $index, bool $ifNotExists): string
    {
        // CREATE TABLE IF NOT EXISTS declares its indexes inline; an index
        // added to a table later has no IF NOT EXISTS to ask for.
        return parent::createIndex($index, false);
    }

    public function dropIndex(string $table, string $index): string
    {
        return "DROP INDEX {$this->quote($index)} ON {$this->quote($table)}";
    }

    /**
     * MODIFY COLUMN, which takes the whole definition: what change() says is
     * the column's, and its foreign keys and checks -- constraints of the
     * table here -- stay.
     */
    protected function changeColumn(string $table, Column $column): array
    {
        return ["ALTER TABLE {$this->quote($table)} MODIFY COLUMN {$this->columnDefinition($column, inlineReference: false, inlineUnique: false)}"];
    }

    /** A column's REFERENCES is ignored here, so a foreign key added later is a constraint of its own. */
    protected function addColumn(string $table, Column $column): array
    {
        $statements = ["ALTER TABLE {$this->quote($table)} ADD COLUMN {$this->columnDefinition($column, inlineReference: false, inlineUnique: false)}"];
        if ($column->references !== null) {
            $statements[] = "ALTER TABLE {$this->quote($table)} ADD " . $this->foreignKey($table, $column);
        }

        return $statements;
    }

    public function tables(Connection $connection): array
    {
        return array_map(
            static fn (array $row): string => self::text($row, 'name'),
            $connection->select(
                "SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name",
            ),
        );
    }

    public function hasTable(Connection $connection, string $table): bool
    {
        return $connection->select(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table],
        ) !== [];
    }

    /**
     * MySQL reports a literal default as its value, unquoted, and one it
     * computes with DEFAULT_GENERATED among the column's extras.
     */
    public function columns(Connection $connection, string $table): array
    {
        $columns = [];
        foreach ($connection->select(
            'SELECT column_name AS name, column_type AS type, is_nullable AS nullable, column_default AS dflt, extra AS extra, '
            . 'collation_name AS collation FROM information_schema.columns '
            . 'WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position',
            [$table],
        ) as $row) {
            $default = $row['dflt'] ?? null;
            $kind = match (true) {
                str_contains(strtoupper(self::text($row, 'extra')), 'DEFAULT_GENERATED') => 'expression',
                $default === null => 'none',
                default => 'literal',
            };
            $columns[self::text($row, 'name')] = [
                'type' => self::text($row, 'type'),
                'nullable' => self::text($row, 'nullable') === 'YES',
                'default' => $kind === 'literal' && \is_scalar($default) ? (string) $default : null,
                'defaultKind' => $kind,
                'caseInsensitive' => str_ends_with(self::text($row, 'collation'), '_ci'),
            ];
        }

        return $columns;
    }

    /** A boolean is a TINYINT(1) here, an integer like any other. */
    public function family(string $type): string
    {
        $bare = trim((string) preg_replace(['/\([^)]*\)/', '/\b(unsigned|zerofill)\b/', '/\s+/'], ['', '', ' '], strtolower($type)));

        return match ($bare) {
            'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext' => 'text',
            'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'bool', 'boolean' => 'integer',
            'double', 'float', 'real' => 'float',
            'decimal', 'numeric' => 'decimal',
            'json' => 'json',
            'datetime', 'timestamp' => 'timestamp',
            'date' => 'date',
            'tinyblob', 'blob', 'mediumblob', 'longblob', 'binary', 'varbinary' => 'binary',
            default => $bare,
        };
    }

    public function builderType(string $type): ?array
    {
        $type = strtolower(trim($type));

        return match (true) {
            preg_match('/^varchar\((\d+)\)\z/', $type, $m) === 1 => ['type' => 'string', 'length' => (int) $m[1], 'scale' => null],
            preg_match('/^decimal\((\d+),(\d+)\)\z/', $type, $m) === 1 => ['type' => 'decimal', 'length' => (int) $m[1], 'scale' => (int) $m[2]],
            $type === 'char(36)' => ['type' => 'uuid', 'length' => null, 'scale' => null],
            $type === 'text' => ['type' => 'text', 'length' => null, 'scale' => null],
            $type === 'int' => ['type' => 'integer', 'length' => null, 'scale' => null],
            $type === 'bigint' => ['type' => 'bigInteger', 'length' => null, 'scale' => null],
            $type === 'tinyint(1)' => ['type' => 'boolean', 'length' => null, 'scale' => null],
            $type === 'double' => ['type' => 'float', 'length' => null, 'scale' => null],
            $type === 'json' => ['type' => 'json', 'length' => null, 'scale' => null],
            $type === 'datetime(6)' => ['type' => 'timestamp', 'length' => null, 'scale' => null],
            $type === 'date' => ['type' => 'date', 'length' => null, 'scale' => null],
            $type === 'longblob' => ['type' => 'binary', 'length' => null, 'scale' => null],
            default => self::databaseType($type),
        };
    }

    public function indexes(Connection $connection, string $table): array
    {
        $indexes = [];
        foreach ($connection->select(
            'SELECT index_name AS name, non_unique AS non_unique, column_name AS col FROM information_schema.statistics '
            . 'WHERE table_schema = DATABASE() AND table_name = ? ORDER BY index_name, seq_in_index',
            [$table],
        ) as $row) {
            $name = self::text($row, 'name');
            $indexes[$name] ??= ['name' => $name, 'columns' => [], 'unique' => self::text($row, 'non_unique') === '0'];
            $indexes[$name]['columns'][] = self::text($row, 'col');
        }

        return array_values($indexes);
    }

    public function foreignKeys(Connection $connection, string $table): array
    {
        $keys = [];
        foreach ($connection->select(
            'SELECT k.column_name AS col, k.referenced_table_name AS target, k.referenced_column_name AS target_col, r.delete_rule AS on_delete '
            . 'FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r '
            . 'ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name AND r.table_name = k.table_name '
            . 'WHERE k.table_schema = DATABASE() AND k.table_name = ? AND k.referenced_table_name IS NOT NULL ORDER BY k.constraint_name, k.ordinal_position',
            [$table],
        ) as $row) {
            $keys[] = [
                'column' => self::text($row, 'col'),
                'table' => self::text($row, 'target'),
                'references' => self::text($row, 'target_col'),
                'onDelete' => strtoupper(self::text($row, 'on_delete')),
            ];
        }

        return $keys;
    }

    /** Each CHECK's expression, as MySQL keeps it. */
    public function checks(Connection $connection, string $table): array
    {
        $checks = [];
        foreach ($connection->select(
            'SELECT cc.check_clause AS clause FROM information_schema.check_constraints cc JOIN information_schema.table_constraints tc '
            . 'ON tc.constraint_schema = cc.constraint_schema AND tc.constraint_name = cc.constraint_name '
            . "WHERE tc.table_schema = DATABASE() AND tc.table_name = ? AND tc.constraint_type = 'CHECK' ORDER BY cc.constraint_name",
            [$table],
        ) as $row) {
            $checks[] = self::text($row, 'clause');
        }

        return $checks;
    }

    /**
     * GET_LOCK, which MySQL lets go when the session ends. Named for the
     * database, so two databases on one server migrate side by side; a
     * hash, since a lock's name is capped at 64 characters.
     */
    public function lock(Connection $connection, float $timeout): void
    {
        $taken = $connection->select(
            "SELECT GET_LOCK(CONCAT('tinvar_migrations:', SHA1(COALESCE(DATABASE(), ''))), ?) AS taken",
            [(int) ceil(max(0.0, $timeout))],
        )[0]['taken'] ?? null;

        if ((string) (\is_scalar($taken) ? $taken : '') !== '1') {
            throw MigrationRefused::locked($timeout, 'the MySQL named lock');
        }
    }

    public function unlock(Connection $connection): void
    {
        $connection->select("SELECT RELEASE_LOCK(CONCAT('tinvar_migrations:', SHA1(COALESCE(DATABASE(), '')))) AS released");
    }

    /** Every view and table in the database, the tables with foreign key checks off while they go. */
    public function dropAllTables(Connection $connection): array
    {
        $views = array_map(
            static fn (array $row): string => self::text($row, 'name'),
            $connection->select('SELECT table_name AS name FROM information_schema.views WHERE table_schema = DATABASE() ORDER BY table_name'),
        );
        foreach ($views as $view) {
            $connection->execute('DROP VIEW IF EXISTS ' . $this->quoteOne($view));
        }

        $tables = $this->tables($connection);
        $connection->execute('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($tables as $table) {
                $connection->execute('DROP TABLE IF EXISTS ' . $this->quoteOne($table));
            }
        } finally {
            $connection->execute('SET FOREIGN_KEY_CHECKS = 1');
        }

        return [...$views, ...$tables];
    }
}
