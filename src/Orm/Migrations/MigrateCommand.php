<?php

declare(strict_types=1);

namespace Tinvar\Orm\Migrations;

use Tinvar\Orm\Connection;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\Model;
use Tinvar\Support\DevMode;

/**
 * The migration commands.
 *
 *   tinvar migrate                    run every pending migration, as one batch
 *   tinvar migrate:rollback [--step=N] take back the last batch, or the last N
 *   tinvar migrate:status             each migration, and whether it has run
 *   tinvar migrate:fresh --force      drop every table and migrate from nothing
 *   tinvar make:migration <name> [--blank] [--rename=table.old:new | --rename=old:new ...]
 *                                     write the migration the models' difference
 *                                     from the database calls for, or an empty one
 *   tinvar db:seed [--fresh --force] [the seeder's options]
 *                                     migrate, then fill the database with the
 *                                     application's DatabaseSeeder; never in production
 *   tinvar db:mark-production         mark the database production's
 *
 * migrate:fresh and db:seed run only where something says this is not
 * production -- TINVAR_ENV local, development or testing, or
 * --non-production -- and nothing says it is (NonProduction): neither the
 * environment nor the database, which `migrate` marks production's
 * wherever the environment says production (ProductionMarker).
 *
 * migrate, rollback, fresh and db:seed wait up to --lock-timeout seconds
 * (60 by default) for another run to finish.
 */
final class MigrateCommand
{
    /** Exit status for a bad invocation, as sysexits(3) has it. */
    public const int USAGE = 64;

    /** Exit status for a run that failed or was refused. */
    public const int FAILED = 1;

    public const array COMMANDS = ['migrate', 'migrate:rollback', 'migrate:status', 'migrate:fresh', 'make:migration', 'db:seed', 'db:mark-production'];

    private const string MAKE_USAGE = 'tinvar make:migration <name> [--blank] [--rename=TABLE.OLD:NEW | --rename=OLD:NEW ...]';

    /** @var \Closure(string): void */
    private readonly \Closure $out;

    /** @var \Closure(string): void */
    private readonly \Closure $err;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /** @var \Closure(string): bool */
    private readonly \Closure $ask;

    /** @var array<string, string> */
    private readonly array $environment;

    /**
     * @param \Closure(bool): Connection $connect opens the database; given true where the
     *        command may create it (migrate, fresh, db:seed), false where it must already be there
     * @param list<class-string<Model>> $models what make:migration compares the database with
     * @param (\Closure(string): void)|null $out a line for standard output
     * @param (\Closure(string): void)|null $err a line for standard error
     * @param (\Closure(): int)|null $clock the time now, for tests: it names a new migration
     * @param DatabaseSeeder|null $seeder what db:seed fills the database with; db:seed is refused without one
     * @param (\Closure(string): bool)|null $ask a yes-or-no question for whoever runs make:migration --
     *        whether a column is another renamed -- answered no where nobody is there to ask:
     *        by default, unless standard input is a terminal
     * @param array<string, string>|null $environment the environment, for tests; the process's own by default
     */
    public function __construct(
        private readonly \Closure $connect,
        private readonly string $directory,
        private readonly array $models = [],
        ?\Closure $out = null,
        ?\Closure $err = null,
        ?\Closure $clock = null,
        private readonly ?DatabaseSeeder $seeder = null,
        ?\Closure $ask = null,
        ?array $environment = null,
    ) {
        $this->out = $out ?? static function (string $line): void {
            fwrite(STDOUT, $line . "\n");
        };
        $this->err = $err ?? static function (string $line): void {
            fwrite(STDERR, $line . "\n");
        };
        $this->clock = $clock ?? time(...);
        $this->ask = $ask ?? static function (string $question): bool {
            if (!\defined('STDIN') || !stream_isatty(STDIN)) {
                return false;
            }
            fwrite(STDERR, "{$question} [y/N] ");

            return \in_array(strtolower(trim((string) fgets(STDIN))), ['y', 'yes'], true);
        };
        $this->environment = $environment ?? getenv();
    }

    /** @param list<string> $arguments what follows the command */
    public function run(string $command, array $arguments): int
    {
        try {
            return match ($command) {
                'migrate' => $this->migrate(self::options($command, $arguments, ['lock-timeout'])),
                'migrate:rollback' => $this->rollback(self::options($command, $arguments, ['step', 'lock-timeout'])),
                'migrate:status' => $this->status(self::options($command, $arguments, [])),
                'migrate:fresh' => $this->fresh(
                    \in_array('--force', $arguments, true),
                    \in_array(NonProduction::FLAG, $arguments, true),
                    self::options($command, array_values(array_diff($arguments, ['--force', NonProduction::FLAG])), ['lock-timeout']),
                ),
                'make:migration' => $this->make($arguments),
                'db:seed' => $this->seed($arguments),
                'db:mark-production' => $this->markProduction(self::options($command, $arguments, [])),
                default => throw new \InvalidArgumentException("Unknown command '{$command}'."),
            };
        } catch (\InvalidArgumentException $e) {
            ($this->err)($e->getMessage());

            return self::USAGE;
        } catch (MigrationRefused|MigrationFailed $e) {
            ($this->err)($e->getMessage());

            return self::FAILED;
        } catch (\PDOException $e) {
            ($this->err)('The database refused: ' . $e->getMessage());

            return self::FAILED;
        }
    }

    /**
     * Runs what is pending; and where the environment says production,
     * marks the database production's, so a command run later from a
     * machine that says otherwise is still refused it (ProductionMarker).
     *
     * @param array<string, string> $options
     */
    private function migrate(array $options): int
    {
        $migrator = $this->migrator(true, $options);
        $ran = $migrator->migrate();
        ($this->out)($ran === [] ? 'Nothing to migrate.' : \sprintf('Ran %d migration(s).', \count($ran)));

        $production = DevMode::productionMarker($this->environment);
        if ($production !== null && ProductionMarker::mark($migrator->connection(), $migrator->dialect(), ($this->clock)())) {
            ($this->out)("Marked this database production's, as {$production} says.");
        }

        return 0;
    }

    /**
     * Marks the database production's by hand: one migrated before the mark
     * existed, or by a deploy whose environment said nothing.
     *
     * @param array<string, string> $options
     */
    private function markProduction(array $options): int
    {
        $migrator = $this->migrator(false, $options);
        ($this->out)(ProductionMarker::mark($migrator->connection(), $migrator->dialect(), ($this->clock)())
            ? 'Marked this database production\'s: migrate:fresh and db:seed are refused on it from now on.'
            : 'This database is marked production\'s already.');

        return 0;
    }

    /** @param array<string, string> $options */
    private function rollback(array $options): int
    {
        $steps = null;
        if (isset($options['step'])) {
            $steps = ctype_digit($options['step']) && (int) $options['step'] > 0
                ? (int) $options['step']
                : throw new \InvalidArgumentException('--step takes a number of migrations, 1 or more.');
        }

        $rolledBack = $this->migrator(false, $options)->rollback($steps);
        ($this->out)($rolledBack === [] ? 'Nothing to roll back.' : \sprintf('Rolled back %d migration(s).', \count($rolledBack)));

        return 0;
    }

    /** @param array<string, string> $options */
    private function status(array $options): int
    {
        $migrator = $this->migrator(false, $options);
        $rows = $migrator->status();
        if ($rows === []) {
            ($this->out)("No migrations in {$this->directory}.");
        } else {
            ($this->out)(\sprintf('%-4s %-6s %s', 'Ran', 'Batch', 'Migration'));
            foreach ($rows as $row) {
                ($this->out)(rtrim(\sprintf(
                    '%-4s %-6s %s%s',
                    $row['batch'] === null ? 'no' : 'yes',
                    $row['batch'] ?? '',
                    $row['name'],
                    $row['file'] ? '' : '  (recorded as run; its file is gone)',
                )));
            }
        }

        // A tenant table the policy does not hold is one a forgotten
        // predicate reads across tenants; worth saying wherever it is seen.
        $dialect = $migrator->dialect();
        $connection = $migrator->connection();
        foreach ($this->models as $model) {
            $meta = Metadata::for($model);
            if ($meta->tenantColumn !== null
                && $dialect->hasTable($connection, $meta->table)
                && !$dialect->hasTenantPolicy($connection, $meta->table)) {
                ($this->err)("warning: {$meta->table} is #[TenantScoped] and has no row-level security policy; `tinvar make:migration` writes the migration that adds it.");
            }
        }

        return 0;
    }

    /** @param array<string, string> $options */
    private function fresh(bool $force, bool $declared, array $options): int
    {
        $this->refuseFresh('migrate:fresh', $force, $declared);

        $ran = $this->migrator(true, $options)->fresh();
        ($this->out)(\sprintf('Dropped every table and ran %d migration(s).', \count($ran)));

        return 0;
    }

    /**
     * Every table dropped, every row with it: only where this is said not
     * to be production, and nowhere without saying so. A command typed into
     * the wrong shell, or a script pointed at the wrong DSN, must stop here.
     */
    private function refuseFresh(string $command, bool $force, bool $declared): void
    {
        NonProduction::require($command, 'drops every table', $this->environment, $declared);
        if (!$force) {
            throw new MigrationRefused("{$command} drops every table and every row in them. Run it again with --force to do that.");
        }
    }

    /**
     * Migrates the database -- from nothing with --fresh -- then has the
     * application's seeder fill it. Refused in production whatever it is
     * given, and wherever nothing says this is not production: a seeder
     * makes demo accounts with known passwords, and rows nobody entered.
     *
     * @param list<string> $arguments
     */
    private function seed(array $arguments): int
    {
        $declared = \in_array(NonProduction::FLAG, $arguments, true);
        $arguments = array_values(array_diff($arguments, [NonProduction::FLAG]));
        NonProduction::require('db:seed', 'fills a database with demo data', $this->environment, $declared);
        if ($this->seeder === null) {
            throw new MigrationRefused('This application has no seeder for db:seed to run.');
        }

        $fresh = \in_array('--fresh', $arguments, true);
        $force = \in_array('--force', $arguments, true);
        $given = self::options('db:seed', array_values(array_diff($arguments, ['--fresh', '--force'])), ['lock-timeout', ...array_keys($this->seeder->options())]);
        if ($force && !$fresh) {
            throw new \InvalidArgumentException('--force goes with --fresh, which drops every table first.');
        }
        if ($fresh) {
            $this->refuseFresh('db:seed --fresh', $force, $declared);
        }

        $migrator = $this->migrator(true, array_intersect_key($given, ['lock-timeout' => true]));
        ProductionMarker::refuse($migrator->connection(), 'db:seed', 'fills a database with demo data', $migrator->dialect());
        $ran = $fresh ? $migrator->fresh() : $migrator->migrate();
        ($this->out)(match (true) {
            $fresh => \sprintf('Dropped every table and ran %d migration(s).', \count($ran)),
            $ran === [] => 'Nothing to migrate.',
            default => \sprintf('Ran %d migration(s).', \count($ran)),
        });

        $this->seeder->seed($migrator->connection(), array_diff_key($given, ['lock-timeout' => true]), $this->out);

        return 0;
    }

    /** @param list<string> $arguments */
    private function make(array $arguments): int
    {
        $blank = false;
        $name = null;
        $renames = [];
        foreach ($arguments as $argument) {
            if ($argument === '--blank') {
                $blank = true;
            } elseif (str_starts_with($argument, '--rename=')) {
                $renames[] = self::rename(substr($argument, \strlen('--rename=')));
            } elseif ($name === null && !str_starts_with($argument, '-')) {
                $name = $argument;
            } else {
                throw new \InvalidArgumentException("Unknown argument '{$argument}'. Usage: " . self::MAKE_USAGE);
            }
        }
        if ($name === null || preg_match('/^[a-z][a-z0-9_]{0,100}\z/', $name) !== 1) {
            throw new \InvalidArgumentException('Usage: ' . self::MAKE_USAGE . ', the name in snake_case: add_notes_to_orders.');
        }
        if ($blank && $renames !== []) {
            throw new \InvalidArgumentException('--rename says what a generated migration renames; a --blank one is written by hand.');
        }

        $now = ($this->clock)();
        $path = rtrim($this->directory, '/') . '/' . gmdate('Y_m_d_His', $now) . "_{$name}.php";
        if (file_exists($path)) {
            throw new MigrationRefused("{$path} already exists.");
        }

        if ($blank) {
            $source = MigrationGenerator::blank();
        } else {
            $migrator = $this->migrator(false, []);

            // The diff is against the database as it is; one behind its
            // migrations would be given a migration repeating them.
            $pending = is_dir($this->directory) ? $migrator->pending() : [];
            if ($pending !== []) {
                throw new MigrationRefused(\sprintf(
                    '%d migration(s) have not run here (%s); migrate first, or this one would repeat them.',
                    \count($pending),
                    implode(', ', $pending),
                ));
            }

            // Renames said outright are made; any other that reads as one
            // is asked about on a terminal, and only named where there is none.
            $generator = new MigrationGenerator($migrator->connection(), $migrator->dialect(), $this->ask, $renames);
            $source = $generator->generate($this->models, gmdate('Y-m-d', $now));
            foreach ($generator->warnings as $warning) {
                ($this->err)("warning: {$warning}");
            }
            if ($source === null) {
                ($this->out)('The database already has everything the models describe; nothing written. For a backfill or a change the models cannot say: tinvar make:migration <name> --blank');

                return 0;
            }
        }

        if (!is_dir($this->directory) && !mkdir($this->directory, 0o755, true) && !is_dir($this->directory)) {
            throw new MigrationRefused("Could not create {$this->directory}.");
        }
        file_put_contents($path, $source);
        ($this->out)("wrote {$path}");

        return 0;
    }

    /**
     * One --rename: `table.old:new` a column of the models' table, `old:new`
     * a table. Plain identifiers only, as a migration names them.
     *
     * @return array{table: string|null, from: string, to: string}
     */
    private static function rename(string $value): array
    {
        $name = '[A-Za-z_][A-Za-z0-9_]{0,62}';
        if (preg_match("/^(?:({$name})\\.)?({$name}):({$name})\\z/", $value, $m) !== 1 || $m[2] === $m[3]) {
            throw new \InvalidArgumentException("--rename takes table.old:new for a column or old:new for a table, plain names that differ. Usage: " . self::MAKE_USAGE);
        }

        return ['table' => $m[1] === '' ? null : $m[1], 'from' => $m[2], 'to' => $m[3]];
    }

    /** @param array<string, string> $options */
    private function migrator(bool $create, array $options): Migrator
    {
        $timeout = Migrator::LOCK_TIMEOUT;
        if (isset($options['lock-timeout'])) {
            $timeout = is_numeric($options['lock-timeout']) && (float) $options['lock-timeout'] >= 0
                ? (float) $options['lock-timeout']
                : throw new \InvalidArgumentException('--lock-timeout takes a number of seconds.');
        }

        return new Migrator(
            ($this->connect)($create),
            $this->directory,
            lockTimeout: $timeout,
            log: $this->out,
        );
    }

    /**
     * @param list<string> $arguments
     * @param list<string> $allowed
     * @return array<string, string>
     */
    private static function options(string $command, array $arguments, array $allowed): array
    {
        $options = [];
        foreach ($arguments as $argument) {
            if (preg_match('/^--([a-z-]+)=(.*)\z/', $argument, $m) !== 1 || !\in_array($m[1], $allowed, true)) {
                $usage = implode(' ', array_map(static fn (string $option): string => "[--{$option}=…]", $allowed));
                throw new \InvalidArgumentException("Unknown argument '{$argument}'. Usage: tinvar {$command}" . ($usage === '' ? '' : " {$usage}"));
            }
            $options[$m[1]] = $m[2];
        }

        return $options;
    }
}
