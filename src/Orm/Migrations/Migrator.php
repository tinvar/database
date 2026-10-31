<?php

declare(strict_types=1);

namespace Tinvar\Orm\Migrations;

use Tinvar\Orm\Connection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;

/**
 * Runs an application's migrations, in the order their names sort --
 * the timestamp each name starts with -- and records each one run in the
 * `tinvar_migrations` table, with the batch it ran in, so a rollback can
 * take back the last deploy's and no more.
 *
 * Each migration runs in a transaction of its own where the database
 * allows schema changes in one (SQLite and Postgres), with its record
 * written in the same transaction: a migration that throws leaves neither
 * its changes nor its record, and the ones before it stay run. MySQL
 * commits every schema change as it runs, so there a failure leaves what
 * ran before it, and MigrationFailed lists that.
 *
 * Every run that changes anything holds the dialect's migration lock -- an
 * advisory lock on Postgres, a named lock on MySQL, a lock file beside a
 * SQLite database -- and reads what has run only once it has it, so two
 * deploys starting together migrate one after the other and the second
 * finds nothing left to do.
 */
final class Migrator
{
    public const string TABLE = 'tinvar_migrations';

    /** How long a run waits for another to finish before giving up, in seconds. */
    public const float LOCK_TIMEOUT = 60.0;

    /** A migration's file name: when it was made, then what it does. */
    public const string NAME_PATTERN = '/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+\z/';

    private readonly Dialect $dialect;

    /** @var list<string> */
    private readonly array $paths;

    /** @var \Closure(string): void */
    private readonly \Closure $log;

    /** @var (\Closure(string, int, int, string): void)|null */
    private readonly ?\Closure $progress;

    /** @var array<string, Migration> loaded migrations, by path */
    private array $loaded = [];

    /**
     * @param string|list<string> $paths the directories migrations are read from
     * @param (\Closure(string): void)|null $log a line for each migration run, rolled back or table dropped
     * @param (\Closure(string, int, int, string): void)|null $progress told 'up' or 'down', how many
     *        of how many are done and the one just done: with 0 and '' before the first, then after each
     */
    public function __construct(
        private readonly Connection $connection,
        string|array $paths,
        ?Dialect $dialect = null,
        private readonly float $lockTimeout = self::LOCK_TIMEOUT,
        ?\Closure $log = null,
        ?\Closure $progress = null,
    ) {
        $this->dialect = $dialect ?? Dialect::for($connection);
        $this->paths = \is_string($paths) ? [$paths] : $paths;
        $this->log = $log ?? static function (string $line): void {};
        $this->progress = $progress;
    }

    public function dialect(): Dialect
    {
        return $this->dialect;
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    /**
     * Every migration file, by name, in the order they run.
     *
     * A file in the directory that is not named as a migration is refused
     * rather than skipped: skipped, a migration misnamed by hand would never
     * run, and nothing would say so.
     *
     * @return array<string, string> name => path
     */
    public function files(): array
    {
        $files = [];
        foreach ($this->paths as $directory) {
            if (!is_dir($directory)) {
                throw MigrationRefused::badFile($directory, 'is not a directory of migrations.');
            }
            foreach (glob(rtrim($directory, '/') . '/*.php') ?: [] as $path) {
                $name = basename($path, '.php');
                if (preg_match(self::NAME_PATTERN, $name) !== 1) {
                    throw MigrationRefused::badFile($path, 'is not named as a migration (YYYY_MM_DD_HHMMSS_what_it_does.php).');
                }
                if (isset($files[$name])) {
                    throw MigrationRefused::badFile($path, "has the same name as {$files[$name]}.");
                }
                $files[$name] = $path;
            }
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    /** @return list<string> the migrations not yet run, in the order they would run */
    public function pending(): array
    {
        $ran = array_column($this->recorded(), 'name');

        return array_values(array_diff(array_keys($this->files()), $ran));
    }

    /**
     * Each migration file and recorded migration, and where it stands.
     *
     * @return list<array{name: string, batch: int|null, appliedAt: string|null, file: bool}>
     */
    public function status(): array
    {
        $recorded = [];
        foreach ($this->recorded() as $row) {
            $recorded[$row['name']] = $row;
        }

        $rows = [];
        $files = $this->files();
        foreach (array_unique([...array_keys($files), ...array_keys($recorded)]) as $name) {
            $rows[$name] = [
                'name' => $name,
                'batch' => $recorded[$name]['batch'] ?? null,
                'appliedAt' => $recorded[$name]['appliedAt'] ?? null,
                'file' => isset($files[$name]),
            ];
        }
        ksort($rows, SORT_STRING);

        return array_values($rows);
    }

    /**
     * Runs every pending migration, as one batch.
     *
     * Every pending file is loaded before the first runs, so one that does
     * not load stops the run with nothing done, rather than half way.
     *
     * @return list<string> the migrations run
     */
    public function migrate(): array
    {
        return $this->locked(function (): array {
            $this->ensureTable();

            return $this->runPending();
        });
    }

    /**
     * Rolls back the last batch, or the last $steps migrations whatever
     * their batch, newest first.
     *
     * Every one of them is checked before the first is touched: one with
     * no down(), or whose file is gone, refuses the whole rollback rather
     * than stopping it part way.
     *
     * @return list<string> the migrations rolled back
     */
    public function rollback(?int $steps = null): array
    {
        if ($steps !== null && $steps < 1) {
            throw new \InvalidArgumentException('A rollback takes back one migration or more.');
        }

        return $this->locked(function () use ($steps): array {
            $files = $this->files();
            $migrations = [];
            foreach ($this->toRollBack($steps) as $name) {
                $path = $files[$name] ?? throw MigrationRefused::missingFile($name);
                $migration = $this->load($path);
                if (!$migration->reversible()) {
                    throw MigrationRefused::irreversible($name);
                }
                $migrations[$name] = $migration;
            }

            $this->tell('down', 0, \count($migrations), '');
            $done = 0;
            foreach ($migrations as $name => $migration) {
                $this->run($name, $migration, 'down', 0);
                $this->tell('down', ++$done, \count($migrations), $name);
            }

            return array_keys($migrations);
        });
    }

    /**
     * What rollback($steps) would take back, newest first: the last batch,
     * or the last $steps migrations whatever their batch. Read without the
     * lock, to say before it runs; rollback() reads it again under it.
     *
     * @return list<string>
     */
    public function toRollBack(?int $steps = null): array
    {
        $newestFirst = array_reverse($this->recorded());
        if ($newestFirst === []) {
            return [];
        }
        $targets = $steps === null
            ? array_values(array_filter($newestFirst, static fn (array $row): bool => $row['batch'] === $newestFirst[0]['batch']))
            : \array_slice($newestFirst, 0, $steps);

        return array_column($targets, 'name');
    }

    /**
     * Drops every table -- the current schema's, on Postgres -- and runs
     * every migration: a database as the migrations alone make it, for
     * development and tests. Whatever the tables held is gone. Refused for
     * a database marked production's (ProductionMarker).
     *
     * @return list<string> the migrations run
     */
    public function fresh(): array
    {
        return $this->locked(function (): array {
            // Under the lock, so a `migrate` marking it cannot slip between.
            ProductionMarker::refuse($this->connection, 'A fresh migration', 'drops every table', $this->dialect);
            foreach ($this->dialect->dropAllTables($this->connection) as $table) {
                ($this->log)("dropped   {$table}");
            }
            $this->ensureTable();

            return $this->runPending();
        });
    }

    /** @return list<string> */
    private function runPending(): array
    {
        $recorded = $this->recorded();
        $ran = array_column($recorded, 'name');
        $batch = $recorded === [] ? 1 : max(array_column($recorded, 'batch')) + 1;

        $pending = [];
        foreach ($this->files() as $name => $path) {
            if (!\in_array($name, $ran, true)) {
                $pending[$name] = $this->load($path);
            }
        }

        if ($pending !== []) {
            $this->tell('up', 0, \count($pending), '');
        }
        $done = 0;
        foreach ($pending as $name => $migration) {
            $this->run($name, $migration, 'up', $batch);
            $this->tell('up', ++$done, \count($pending), $name);
        }

        return array_keys($pending);
    }

    private function tell(string $direction, int $done, int $total, string $name): void
    {
        if ($this->progress !== null) {
            ($this->progress)($direction, $done, $total, $name);
        }
    }

    private function run(string $name, Migration $migration, string $direction, int $batch): void
    {
        $schema = new Schema($this->connection, $this->dialect);
        $transactional = $migration->withinTransaction && $this->dialect->transactionalDdl();
        $table = $this->dialect->quote(self::TABLE);

        // SQLite rebuilds a table to change a column, which foreign keys
        // enforced as it goes would turn into deletes of every row
        // referencing it; they are checked once, before the commit, instead.
        $suspended = $this->dialect->suspendForeignKeys($this->connection);

        $work = function () use ($migration, $schema, $direction, $name, $batch, $table, $suspended): void {
            if ($direction === 'up') {
                $migration->up($schema);
            } else {
                $migration->down($schema);
            }
            if ($suspended) {
                $this->dialect->checkForeignKeys($this->connection);
            }

            if ($direction === 'up') {
                $this->connection->execute(
                    "INSERT INTO {$table} (name, batch, applied_at) VALUES (?, ?, ?)",
                    [$name, $batch, gmdate('Y-m-d\TH:i:s\Z')],
                );
            } else {
                $this->connection->execute("DELETE FROM {$table} WHERE name = ?", [$name]);
            }
        };

        $started = hrtime(true);
        try {
            $transactional ? $this->connection->transaction($work) : $work();
        } catch (\Throwable $e) {
            throw new MigrationFailed($name, $direction, $transactional ? [] : $schema->statements(), $e);
        } finally {
            if ($suspended) {
                $this->dialect->resumeForeignKeys($this->connection);
            }
        }

        ($this->log)(\sprintf(
            '%s %s (%.1f ms)',
            $direction === 'up' ? 'migrated ' : 'rolled back',
            $name,
            (hrtime(true) - $started) / 1e6,
        ));
    }

    /**
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function locked(\Closure $work): mixed
    {
        // Each migration commits on its own, and a transaction the caller
        // holds would turn every commit into a savepoint of it -- and, on
        // MySQL, be committed by the first schema change regardless.
        if ($this->connection->inTransaction()) {
            throw MigrationRefused::inTransaction();
        }

        $this->dialect->lock($this->connection, $this->lockTimeout);
        try {
            return $work();
        } finally {
            $this->dialect->unlock($this->connection);
        }
    }

    private function ensureTable(): void
    {
        new Schema($this->connection, $this->dialect)->create(self::TABLE, static function (Table $table): void {
            $table->string('name')->primary();
            $table->integer('batch');
            $table->string('applied_at', 32);
        }, ifNotExists: true);
    }

    /** @return list<array{name: string, batch: int, appliedAt: string}> oldest first */
    private function recorded(): array
    {
        if (!$this->dialect->hasTable($this->connection, self::TABLE)) {
            return [];
        }

        $rows = [];
        foreach ($this->connection->select(
            'SELECT name, batch, applied_at FROM ' . $this->dialect->quote(self::TABLE) . ' ORDER BY batch, name',
        ) as $row) {
            $rows[] = [
                'name' => \is_string($row['name'] ?? null) ? $row['name'] : '',
                'batch' => is_numeric($row['batch'] ?? null) ? (int) $row['batch'] : 0,
                'appliedAt' => \is_string($row['applied_at'] ?? null) ? $row['applied_at'] : '',
            ];
        }

        return $rows;
    }

    private function load(string $path): Migration
    {
        if (isset($this->loaded[$path])) {
            return $this->loaded[$path];
        }

        // In a closure of its own, so the file sees none of this scope.
        $migration = (static fn (string $file): mixed => require $file)($path);
        if (!$migration instanceof Migration) {
            throw MigrationRefused::badFile($path, 'returns ' . get_debug_type($migration) . ', not a ' . Migration::class . '.');
        }

        return $this->loaded[$path] = $migration;
    }
}
