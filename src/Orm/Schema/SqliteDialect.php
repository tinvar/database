<?php

declare(strict_types=1);

namespace Tinvar\Orm\Schema;

use Tinvar\Orm\Connection;
use Tinvar\Orm\Migrations\MigrationRefused;

/**
 * SQLite: the shop's database, and every test's.
 *
 * Types are SQLite's affinities -- it stores what it is given -- so a uuid,
 * a timestamp and JSON are all TEXT, as the ORM writes them. Schema changes
 * are transactional, so a migration that fails leaves nothing behind.
 *
 * ALTER TABLE here renames and adds, and drops a column nothing else in the
 * table depends on. Anything more -- a column's type, nullability or default
 * changed, or a column dropped that an index, a key or a check is over -- is
 * the table rebuilt, the procedure SQLite documents: a new table made from
 * the old one's statement with that column changed, the rows copied across,
 * the old table dropped and the new one renamed into its place, its indexes
 * and triggers made again.
 */
final class SqliteDialect extends Dialect
{
    /** @var array{resource, string}|null the lock file's handle and path, while this dialect holds it */
    private ?array $lock = null;

    public function name(): string
    {
        return 'sqlite';
    }

    public function transactionalDdl(): bool
    {
        return true;
    }

    protected function quoteOne(string $identifier): string
    {
        // Doubled, though a checked name has none: dropAllTables() quotes
        // whatever names the database lists.
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    protected function type(Column $column): string
    {
        return match ($column->type) {
            'uuid', 'string', 'text', 'json', 'timestamp', 'date' => 'TEXT',
            'integer', 'bigInteger', 'boolean' => 'INTEGER',
            'float' => 'REAL',
            'decimal' => 'NUMERIC',
            'binary' => 'BLOB',
            default => $column->type,
        };
    }

    protected function caseInsensitiveCollation(): string
    {
        return ' COLLATE NOCASE';
    }

    /**
     * SQLite's affinity for a declared type, by the rules it applies itself:
     * what it stores a value as, whatever the type was called. So a uuid, a
     * string and a timestamp are one family here, and VARCHAR(255) is TEXT.
     */
    public function family(string $type): string
    {
        $type = strtoupper($type);

        return match (true) {
            str_contains($type, 'INT') => 'integer',
            str_contains($type, 'CHAR'), str_contains($type, 'CLOB'), str_contains($type, 'TEXT') => 'text',
            str_contains($type, 'BLOB'), trim($type) === '' => 'binary',
            str_contains($type, 'REAL'), str_contains($type, 'FLOA'), str_contains($type, 'DOUB') => 'float',
            default => 'numeric',
        };
    }

    public function builderType(string $type): ?array
    {
        return match (strtoupper($type)) {
            'TEXT' => ['type' => 'text', 'length' => null, 'scale' => null],
            'INTEGER' => ['type' => 'integer', 'length' => null, 'scale' => null],
            'REAL' => ['type' => 'float', 'length' => null, 'scale' => null],
            'NUMERIC' => ['type' => 'decimal', 'length' => null, 'scale' => null],
            'BLOB' => ['type' => 'binary', 'length' => null, 'scale' => null],
            default => self::databaseType($type),
        };
    }

    public function tables(Connection $connection): array
    {
        return array_map(
            static fn (array $row): string => self::text($row, 'name'),
            $connection->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name"),
        );
    }

    public function hasTable(Connection $connection, string $table): bool
    {
        return $connection->select("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]) !== [];
    }

    public function columns(Connection $connection, string $table): array
    {
        // Only for the collation, which nothing else reports: a statement
        // this cannot take apart costs the columns their case, not the
        // columns themselves.
        try {
            $definition = $this->definition($connection, $table);
        } catch (\InvalidArgumentException) {
            $definition = null;
        }
        $columns = [];
        foreach ($connection->select('SELECT name, type, "notnull", dflt_value, pk FROM pragma_table_info(?) ORDER BY cid', [$table]) as $row) {
            $name = self::text($row, 'name');
            [$kind, $default] = self::parseDefault(\is_string($row['dflt_value'] ?? null) ? $row['dflt_value'] : null);
            $columns[$name] = [
                'type' => self::text($row, 'type'),
                // A primary key column SQLite lets hold NULL -- a quirk it
                // keeps for old schemas -- still never does from the ORM.
                'nullable' => self::text($row, 'notnull') === '0' && self::text($row, 'pk') === '0',
                'default' => $default,
                'defaultKind' => $kind,
                'caseInsensitive' => $definition?->caseInsensitive($name) ?? false,
            ];
        }

        return $columns;
    }

    public function indexes(Connection $connection, string $table): array
    {
        $indexes = [];
        foreach ($connection->select('SELECT name, "unique" FROM pragma_index_list(?)', [$table]) as $row) {
            $name = self::text($row, 'name');
            $indexes[] = [
                'name' => $name,
                'columns' => array_map(
                    static fn (array $column): string => self::text($column, 'name'),
                    $connection->select('SELECT name FROM pragma_index_info(?) ORDER BY seqno', [$name]),
                ),
                'unique' => self::text($row, 'unique') === '1',
            ];
        }

        // An INTEGER PRIMARY KEY is the rowid, and has no index of its own
        // to list.
        $primary = [];
        foreach ($connection->select('SELECT name, pk FROM pragma_table_info(?) WHERE pk > 0 ORDER BY pk', [$table]) as $row) {
            $primary[] = self::text($row, 'name');
        }
        if ($primary !== [] && !\in_array($primary, array_column($indexes, 'columns'), true)) {
            $indexes[] = ['name' => 'PRIMARY', 'columns' => $primary, 'unique' => true];
        }

        return $indexes;
    }

    /** A column changes by a rebuild, which needs the table as it stands: applyAlter() does it. */
    protected function changeColumn(string $table, Column $column): array
    {
        throw new \LogicException("SQLite changes column '{$column->name}' by rebuilding table '{$table}', which reads the table from the database: Schema::table() does it.");
    }

    /**
     * Renames and dropped indexes first, as ALTER TABLE does them; then, if
     * a column is changed or a dropped one is one SQLite's DROP COLUMN
     * refuses, the table rebuilt from what it is after them; then what is
     * added.
     */
    public function applyAlter(Connection $connection, Table $table, \Closure $run): void
    {
        $changing = self::changing($table);
        if ($changing === [] && !$this->dropNeedsRebuild($connection, $table)) {
            $run($this->alterTable($table));

            return;
        }

        $run($this->renamesAndDroppedIndexes($table));
        $this->rebuild($connection, $table->name, $table->droppedColumns, $changing, $run);
        $run([...$this->beforeColumns($table), ...$this->additions($table)]);
    }

    /**
     * Whether a column the description drops is one SQLite's DROP COLUMN
     * refuses: a key, a column an index other than those dropped with it is
     * over, a foreign key's, or one a check or a generated column reads.
     */
    private function dropNeedsRebuild(Connection $connection, Table $table): bool
    {
        if ($table->droppedColumns === []) {
            return false;
        }

        $definition = $this->definition($connection, $table->name);
        $indexed = [];
        foreach ($this->indexes($connection, $table->name) as $index) {
            if (!\in_array($index['name'], $table->droppedIndexes, true)) {
                $indexed = [...$indexed, ...array_map('strtolower', $index['columns'])];
            }
        }
        $keys = array_map(
            static fn (array $row): string => strtolower(self::text($row, 'from')),
            $connection->select('SELECT "from" FROM pragma_foreign_key_list(?)', [$table->name]),
        );

        foreach ($table->droppedColumns as $column) {
            if (\in_array(strtolower($column), $indexed, true)
                || \in_array(strtolower($column), $keys, true)
                || ($definition?->namedElsewhere($column) ?? false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rebuilds a table with $dropped gone and $changing defined anew, as
     * SQLite's documentation lays out: within the migration's transaction,
     * with foreign keys not enforced -- the Migrator turns them off for
     * every migration, and checks them before it commits -- since dropping
     * the old table with them on would delete, or set to NULL, every row
     * that references it. Outside a migration, with them on, a table that
     * any table references is refused rather than rebuilt.
     *
     * The rename into place is made in legacy mode, which leaves the views
     * and triggers naming the table as they are: they name it again once it
     * is back, and without legacy mode the rename refuses, finding a view of
     * the table it has just dropped.
     *
     * @param list<string> $dropped
     * @param list<Column> $changing
     * @param \Closure(list<string>): void $run
     */
    private function rebuild(Connection $connection, string $table, array $dropped, array $changing, \Closure $run): void
    {
        $definition = $this->definition($connection, $table)
            ?? throw new \InvalidArgumentException("There is no table '{$table}' to change.");

        $enforced = self::text($connection->select('PRAGMA foreign_keys')[0] ?? [], 'foreign_keys') === '1';
        if ($enforced && $connection->select(
            "SELECT 1 FROM sqlite_master m JOIN pragma_foreign_key_list(m.name) f WHERE m.type = 'table' AND f.\"table\" = ? COLLATE NOCASE LIMIT 1",
            [$table],
        ) !== []) {
            throw new \LogicException(
                "SQLite rebuilds '{$table}' to make this change, and with foreign keys enforced, dropping the old table would delete or "
                . 'change the rows that reference it. Make the change in a migration: the Migrator lifts enforcement for it and checks every key before it commits.',
            );
        }

        $columns = $definition->columns();
        foreach ([...$dropped, ...array_map(static fn (Column $column): string => $column->name, $changing)] as $name) {
            if (!\in_array(strtolower($name), array_map('strtolower', $columns), true)) {
                throw new \InvalidArgumentException("Table '{$table}' has no column '{$name}'.");
            }
        }

        $changed = [];
        foreach ($changing as $column) {
            $changed[$column->name] = $this->columnDefinition($column, inlineReference: false, inlineUnique: false);
        }

        // What is copied: every stored column kept; a generated one is
        // computed again by the new table.
        $copied = [];
        foreach ($connection->select('SELECT name, hidden FROM pragma_table_xinfo(?) ORDER BY cid', [$table]) as $row) {
            $name = self::text($row, 'name');
            if (self::text($row, 'hidden') === '0' && !\in_array(strtolower($name), array_map('strtolower', $dropped), true)) {
                $copied[] = $this->quoteOne($name);
            }
        }

        // The indexes made again, but those over a dropped column, which go
        // with it; an index SQLite made for a key or a UNIQUE has no
        // statement, and is made again by the table's own.
        $remade = [];
        foreach ($connection->select("SELECT name, sql FROM sqlite_master WHERE tbl_name = ? AND type IN ('index', 'trigger') AND sql IS NOT NULL ORDER BY type, name", [$table]) as $row) {
            $over = array_map(
                static fn (array $column): string => strtolower(self::text($column, 'name')),
                $connection->select('SELECT name FROM pragma_index_info(?)', [self::text($row, 'name')]),
            );
            if (array_intersect($over, array_map('strtolower', $dropped)) === []) {
                $remade[] = self::text($row, 'sql');
            }
        }

        $quoted = $this->quoteOne($table);
        $building = $this->quoteOne("{$table}__tinvar_rebuild");
        $copy = implode(', ', $copied);
        $legacy = self::text($connection->select('PRAGMA legacy_alter_table')[0] ?? [], 'legacy_alter_table') === '1';
        $connection->execute('PRAGMA legacy_alter_table = ON');

        try {
            $run([
                "CREATE TABLE {$building} ({$definition->rebuilt($dropped, $changed)}){$definition->tail}",
                "INSERT INTO {$building} ({$copy}) SELECT {$copy} FROM {$quoted}",
                "DROP TABLE {$quoted}",
                "ALTER TABLE {$building} RENAME TO {$quoted}",
                ...$remade,
            ]);
        } finally {
            $connection->execute('PRAGMA legacy_alter_table = ' . ($legacy ? 'ON' : 'OFF'));
        }
    }

    /**
     * Turns enforcement off for a migration, which the documented rebuild
     * needs and which PRAGMA foreign_keys cannot do inside a transaction:
     * so off for every migration, outside its transaction, and every key
     * checked before it commits. Whether it was on, to put it back.
     */
    public function suspendForeignKeys(Connection $connection): bool
    {
        if (self::text($connection->select('PRAGMA foreign_keys')[0] ?? [], 'foreign_keys') !== '1') {
            return false;
        }
        $connection->execute('PRAGMA foreign_keys = OFF');

        return true;
    }

    public function resumeForeignKeys(Connection $connection): void
    {
        $connection->execute('PRAGMA foreign_keys = ON');
    }

    public function checkForeignKeys(Connection $connection): void
    {
        $broken = $connection->select('PRAGMA foreign_key_check');
        if ($broken === []) {
            return;
        }

        $first = $broken[0];
        throw new \RuntimeException(\sprintf(
            '%d row(s) reference rows that are not there, %s row %s among them, referencing %s. On SQLite a migration runs with '
            . 'foreign keys checked at its end, and a delete in it does not cascade: delete or repoint the rows that reference what it removes.',
            \count($broken),
            json_encode(self::text($first, 'table')),
            self::text($first, 'rowid'),
            json_encode(self::text($first, 'parent')),
        ));
    }

    /**
     * A lock file beside the database, held with flock(): the operating
     * system lets it go when the process holding it ends, however it ends,
     * so a deploy killed mid-migration leaves no lock for the next one to
     * wait out. A row in a table would have to be cleared by hand.
     *
     * The file is removed as the lock is let go, so it is there only while
     * a migration runs. Which means a waiter can be granted the lock on a
     * file already unlinked, while a newcomer locks the new one at the same
     * path: so the lock counts only once the path still names the file it
     * is held on, and otherwise is tried again on the file there now.
     *
     * An in-memory database takes no lock: no other connection can see it.
     */
    public function lock(Connection $connection, float $timeout): void
    {
        $file = $this->databaseFile($connection);
        if ($file === '') {
            return;
        }

        $path = "{$file}-migrate.lock";
        $held = null;
        $taken = self::waitFor(static function () use ($path, &$held): bool {
            $held = self::tryLock($path);

            return $held !== null;
        }, $timeout);

        if (!$taken || $held === null) {
            throw MigrationRefused::locked($timeout, $path);
        }

        $this->lock = [$held, $path];
    }

    /**
     * One attempt at the lock file: its handle, locked and still the file
     * at $path, or null.
     *
     * @return resource|null
     */
    private static function tryLock(string $path): mixed
    {
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new \RuntimeException("Could not open the migration lock file {$path}.");
        }

        if (flock($handle, LOCK_EX | LOCK_NB)) {
            clearstatcache(true, $path);
            $onDisk = @stat($path);
            $held = fstat($handle);
            if ($onDisk !== false && $held !== false && $onDisk['ino'] === $held['ino']) {
                return $handle;
            }
        }

        fclose($handle);

        return null;
    }

    public function unlock(Connection $connection): void
    {
        if (\is_array($this->lock)) {
            [$handle, $path] = $this->lock;
            // Unlinked while still held, so no one can lock this file again
            // after it is let go and believe it current.
            @unlink($path);
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        $this->lock = null;
    }

    /**
     * Every view, then every table, with foreign keys off while they go --
     * SQLite refuses to drop a table another still references -- and back
     * as they were. PRAGMA foreign_keys does nothing inside a transaction,
     * so this runs outside one. Triggers and indexes go with their tables.
     */
    public function dropAllTables(Connection $connection): array
    {
        $views = array_map(
            static fn (array $row): string => self::text($row, 'name'),
            $connection->select("SELECT name FROM sqlite_master WHERE type = 'view' ORDER BY name"),
        );
        $tables = $this->tables($connection);
        $enforced = self::text($connection->select('PRAGMA foreign_keys')[0] ?? [], 'foreign_keys') === '1';
        $connection->execute('PRAGMA foreign_keys = OFF');

        try {
            foreach ($views as $view) {
                $connection->execute('DROP VIEW IF EXISTS ' . $this->quoteOne($view));
            }
            foreach ($tables as $table) {
                $connection->execute('DROP TABLE IF EXISTS ' . $this->quoteOne($table));
            }
        } finally {
            if ($enforced) {
                $connection->execute('PRAGMA foreign_keys = ON');
            }
        }

        return [...$views, ...$tables];
    }

    /** The table's own CREATE TABLE statement, taken apart; null when there is no such table. */
    private function definition(Connection $connection, string $table): ?SqliteTable
    {
        $sql = $connection->select("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])[0]['sql'] ?? null;

        return \is_string($sql) ? SqliteTable::parse($sql) : null;
    }

    /** The main database's file, or '' for one in memory. */
    private function databaseFile(Connection $connection): string
    {
        foreach ($connection->select('PRAGMA database_list') as $row) {
            if (self::text($row, 'name') === 'main') {
                return self::text($row, 'file');
            }
        }

        return '';
    }
}
