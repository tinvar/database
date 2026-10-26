<?php

declare(strict_types=1);

/**
 * The migrator on SQLite: ordered, recorded, run in a transaction each,
 * rolled back by batch, and one run at a time per database.
 *
 * "make:migration diffs compiled metadata against the live database and
 * writes a migration file to review and commit. Hand-written migrations sit
 * alongside for data backfills." (tinvar-spec.md, "Migrations"). The same
 * runner is held to Postgres and MySQL in their own files, where a server is
 * found.
 */

namespace Tinvar\Tests\Orm\Migrator;

use Tinvar\Orm\Migrations\MigrationFailed;
use Tinvar\Orm\Migrations\MigrationRefused;
use Tinvar\Orm\Migrations\Migrator;
use Tinvar\Orm\PdoConnection;
use Tinvar\Tests\Orm\Fixtures\MigrationFiles;

require_once __DIR__ . '/Fixtures/migrations.php';

/** @return array{MigrationFiles, PdoConnection} migrations to write, and a database file to run them on */
function setUp(): array
{
    $files = new MigrationFiles();

    return [$files, PdoConnection::sqlite($files->database(), create: true)];
}

/** @return list<string> */
function tables(PdoConnection $connection): array
{
    return array_map(
        static fn (array $row): string => (string) $row['name'],
        $connection->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"),
    );
}

/** @return list<array{string, int}> the recorded migrations, oldest first, with their batches */
function recorded(PdoConnection $connection): array
{
    return array_map(
        static fn (array $row): array => [(string) $row['name'], (int) $row['batch']],
        $connection->select('SELECT name, batch FROM tinvar_migrations ORDER BY batch, name'),
    );
}

const CREATE_A = "\$schema->create('a', function (Table \$t): void { \$t->uuid('id')->primary(); });";
const CREATE_B = "\$schema->create('b', function (Table \$t): void { \$t->uuid('id')->primary(); \$t->uuid('a_id')->references('a'); });";

describe('running', function () {
    it('runs migrations in the order their names sort, and records each in one batch', function () {
        [$files, $connection] = setUp();
        // Written out of order: the name decides, not the order on disk.
        $files->add('2026_10_24_000002_create_b', CREATE_B, "\$schema->drop('b');");
        $files->add('2026_10_24_000001_create_a', CREATE_A, "\$schema->drop('a');");
        $log = [];

        $ran = new Migrator($connection, $files->directory, log: static function (string $line) use (&$log): void {
            $log[] = $line;
        })->migrate();

        expect($ran)->toBe(['2026_10_24_000001_create_a', '2026_10_24_000002_create_b'])
            ->and(tables($connection))->toBe(['a', 'b', 'tinvar_migrations'])
            ->and(recorded($connection))->toBe([['2026_10_24_000001_create_a', 1], ['2026_10_24_000002_create_b', 1]])
            ->and($log)->toHaveCount(2)
            ->and($log[0])->toStartWith('migrated  2026_10_24_000001_create_a');
    });

    it('runs nothing twice, and gives what is added later a batch of its own', function () {
        [$files, $connection] = setUp();
        $files->add('2026_10_24_000001_create_a', CREATE_A);
        $migrator = new Migrator($connection, $files->directory);
        $migrator->migrate();

        expect($migrator->migrate())->toBe([]);

        $files->add('2026_10_24_000002_create_b', CREATE_B);

        expect($migrator->pending())->toBe(['2026_10_24_000002_create_b'])
            ->and($migrator->migrate())->toBe(['2026_10_24_000002_create_b'])
            ->and(recorded($connection))->toBe([['2026_10_24_000001_create_a', 1], ['2026_10_24_000002_create_b', 2]]);
    });

    it('says where each migration stands, and names a recorded one whose file is gone', function () {
        [$files, $connection] = setUp();
        $gone = $files->add('2026_10_24_000001_create_a', CREATE_A);
        $migrator = new Migrator($connection, $files->directory);
        $migrator->migrate();
        unlink($gone);
        $files->add('2026_10_24_000002_create_b', "\$schema->create('b', function (Table \$t): void { \$t->uuid('id'); });");

        $status = new Migrator($connection, $files->directory)->status();

        expect(array_map(static fn (array $row): array => [$row['name'], $row['batch'], $row['file']], $status))->toBe([
            ['2026_10_24_000001_create_a', 1, false],
            ['2026_10_24_000002_create_b', null, true],
        ]);
    });

    it('runs a backfill between schema changes, seeing the first and not the second', function () {
        [$files, $connection] = setUp();
        $files->add('2026_10_24_000001_create_a', "\$schema->create('a', function (Table \$t): void { \$t->uuid('id')->primary(); \$t->string('name'); });"
            . " \$schema->sql('INSERT INTO a (id, name) VALUES (?, ?)', ['0192f3a1-0000-7000-8000-000000000001', 'Ada']);");
        $files->add('2026_10_24_000002_add_upper', "\$schema->table('a', function (Table \$t): void { \$t->string('upper')->nullable(); });"
            . " \$schema->sql('UPDATE a SET upper = UPPER(name)');"
            . " expect(\$schema->hasColumn('a', 'upper'))->toBeTrue()->and(\$schema->hasTable('a'))->toBeTrue()->and(\$schema->driver())->toBe('sqlite');");

        new Migrator($connection, $files->directory)->migrate();

        expect($connection->select('SELECT upper FROM a'))->toBe([['upper' => 'ADA']]);
    });

    it('lifts nothing for a backfill across tenants on SQLite, which has no policies to lift', function () {
        [$files, $connection] = setUp();
        $files->add('2026_10_24_000001_across', "\$schema->create('a', function (Table \$t): void { \$t->uuid('id')->primary(); \$t->tenant(); });"
            . " expect(\$schema->acrossTenants(static fn (): int => \$schema->sql(\"INSERT INTO a VALUES ('0192f3a1-0000-7000-8000-000000000001', 'acme')\")))->toBe(1);");

        new Migrator($connection, $files->directory)->migrate();

        expect($connection->select('SELECT tenant_id FROM a'))->toBe([['tenant_id' => 'acme']]);
    });
});

describe('a migration that fails', function () {
    // The one that threw takes back everything it did, its record included;
    // the ones before it in the same run stay run and recorded.
    it('leaves the database as the migrations before it left it', function () {
        [$files, $connection] = setUp();
        $files->add('2026_10_24_000001_create_a', CREATE_A);
        $files->add('2026_10_24_000002_create_b_then_fail', CREATE_B . " \$schema->sql('INSERT INTO nowhere VALUES (1)');");
        $files->add('2026_10_24_000003_create_c', "\$schema->create('c', function (Table \$t): void { \$t->uuid('id'); });");
        $migrator = new Migrator($connection, $files->directory);

        expect(fn () => $migrator->migrate())->toThrow(MigrationFailed::class, '2026_10_24_000002_create_b_then_fail failed to migrate: ')
            ->and(tables($connection))->toBe(['a', 'tinvar_migrations'])
            ->and(recorded($connection))->toBe([['2026_10_24_000001_create_a', 1]])
            ->and($connection->inTransaction())->toBeFalse()
            ->and($migrator->pending())->toBe(['2026_10_24_000002_create_b_then_fail', '2026_10_24_000003_create_c']);
    });

    it('runs again, from where it stopped, once it is fixed', function () {
        [$files, $connection] = setUp();
        $files->add('2026_10_24_000001_create_a', CREATE_A);
        $files->add('2026_10_24_000002_create_b', CREATE_B . " throw new \\RuntimeException('not yet');");
        expect(fn () => new Migrator($connection, $files->directory)->migrate())->toThrow(MigrationFailed::class, 'not yet');

        $fixed = $files->add('2026_10_24_000002_create_b', CREATE_B);
        // PHP keeps an anonymous class by the file and line it was declared
        // on for the life of the process, and would hand back the broken
        // one; a deploy after the fix is a new process, which this line
        // stands in for by moving the declaration.
        file_put_contents($fixed, preg_replace('/^<\?php\n/', "<?php\n// Fixed.\n", (string) file_get_contents($fixed)));
        $ran = new Migrator($connection, $files->directory)->migrate();

        expect($ran)->toBe(['2026_10_24_000002_create_b'])
            ->and(recorded($connection))->toBe([['2026_10_24_000001_create_a', 1], ['2026_10_24_000002_create_b', 2]]);
    });

    // Outside a transaction, what ran stays; the failure lists it, and the
    // migration is not recorded, so it runs again once repaired.
    it('lists what it left behind when it ran outside a transaction', function () {
        [$files, $connection] = setUp();
        $files->add('2026_10_24_000001_outside', CREATE_A . " \$schema->sql('INSERT INTO nowhere VALUES (1)');", withinTransaction: false);

        try {
            new Migrator($connection, $files->directory)->migrate();
            $this->fail('The migration did not fail.');
        } catch (MigrationFailed $e) {
            expect($e->migration)->toBe('2026_10_24_000001_outside')
                ->and($e->leftBehind)->toHaveCount(1)
                ->and($e->leftBehind[0])->toStartWith('CREATE TABLE "a"')
                ->and($e->getMessage())->toContain('were kept; repair them before migrating again');
        }

        expect(tables($connection))->toBe(['a', 'tinvar_migrations'])
            ->and(recorded($connection))->toBe([]);
    });

    it('stops a run with nothing done when a pending file does not load', function () {
        [$files, $connection] = setUp();
        $files->add('2026_10_24_000001_create_a', CREATE_A);
        file_put_contents("{$files->directory}/2026_10_24_000002_not_a_migration.php", "<?php\nreturn 42;\n");

        expect(fn () => new Migrator($connection, $files->directory)->migrate())->toThrow(MigrationRefused::class, 'returns int, not a Tinvar\\Orm\\Migrations\\Migration')
            ->and(tables($connection))->toBe(['tinvar_migrations']);
    });

    it('refuses a file not named as a migration, rather than never running it', function () {
        [$files, $connection] = setUp();
        $files->add('2026_10_24_000001_create_a', CREATE_A);
        file_put_contents("{$files->directory}/create_b.php", "<?php\n");

        expect(fn () => new Migrator($connection, $files->directory)->migrate())->toThrow(MigrationRefused::class, 'is not named as a migration')
            ->and(fn () => new Migrator($connection, "{$files->directory}/missing")->pending())->toThrow(MigrationRefused::class, 'is not a directory');
    });

    it('refuses to run inside a transaction the caller holds', function () {
        [$files, $connection] = setUp();
        $files->add('2026_10_24_000001_create_a', CREATE_A);

        $connection->transaction(function () use ($connection, $files): void {
            expect(fn () => new Migrator($connection, $files->directory)->migrate())->toThrow(MigrationRefused::class, 'cannot run inside a transaction');
        });
        expect(tables($connection))->toBe([]);
    });
});

describe('rolling back', function () {
    it('takes back the last batch, newest first, and forgets it ran', function () {
        [$files, $connection] = setUp();
        $files->add('2026_10_24_000001_create_a', CREATE_A, "\$schema->drop('a');");
        $migrator = new Migrator($connection, $files->directory);
        $migrator->migrate();
        $files->add('2026_10_24_000002_create_b', CREATE_B, "\$schema->drop('b');");
        $files->add('2026_10_24_000003_create_c', "\$schema->create('c', function (Table \$t): void { \$t->uuid('id'); });", "\$schema->drop('c');");
        $migrator->migrate();

        expect($migrator->rollback())->toBe(['2026_10_24_000003_create_c', '2026_10_24_000002_create_b'])
            ->and(tables($connection))->toBe(['a', 'tinvar_migrations'])
            ->and(recorded($connection))->toBe([['2026_10_24_000001_create_a', 1]])
            ->and($migrator->rollback())->toBe(['2026_10_24_000001_create_a'])
            ->and($migrator->rollback())->toBe([])
            ->and(tables($connection))->toBe(['tinvar_migrations']);
    });

    it('takes back the last N migrations whatever their batches', function () {
        [$files, $connection] = setUp();
        $files->add('2026_10_24_000001_create_a', CREATE_A, "\$schema->drop('a');");
        $migrator = new Migrator($connection, $files->directory);
        $migrator->migrate();
        $files->add('2026_10_24_000002_create_b', CREATE_B, "\$schema->drop('b');");
        $migrator->migrate();

        expect($migrator->rollback(2))->toBe(['2026_10_24_000002_create_b', '2026_10_24_000001_create_a'])
            ->and(recorded($connection))->toBe([])
            ->and(fn () => $migrator->rollback(0))->toThrow(\InvalidArgumentException::class);
    });

    // Checked before the first is touched, so a rollback never stops half
    // way at a migration that cannot be taken back.
    it('refuses the whole batch when one has no down(), before running any', function () {
        [$files, $connection] = setUp();
        $files->add('2026_10_24_000001_create_a', CREATE_A);
        $files->add('2026_10_24_000002_create_b', CREATE_B, "\$schema->drop('b');");
        $migrator = new Migrator($connection, $files->directory);
        $migrator->migrate();

        expect(fn () => $migrator->rollback())->toThrow(MigrationRefused::class, '2026_10_24_000001_create_a has no down()')
            ->and(tables($connection))->toBe(['a', 'b', 'tinvar_migrations'])
            ->and(recorded($connection))->toHaveCount(2);
    });

    it('refuses a migration whose file is gone', function () {
        [$files, $connection] = setUp();
        $path = $files->add('2026_10_24_000001_create_a', CREATE_A, "\$schema->drop('a');");
        $migrator = new Migrator($connection, $files->directory);
        $migrator->migrate();
        unlink($path);

        expect(fn () => new Migrator($connection, $files->directory)->rollback())->toThrow(MigrationRefused::class, 'its file is gone');
    });

    it('keeps the record of a migration whose down() fails, and what it would have dropped', function () {
        [$files, $connection] = setUp();
        $files->add('2026_10_24_000001_create_a', CREATE_A, "\$schema->drop('a'); throw new \\RuntimeException('no');");
        $migrator = new Migrator($connection, $files->directory);
        $migrator->migrate();

        expect(fn () => $migrator->rollback())->toThrow(MigrationFailed::class, 'failed to roll back: no')
            ->and(tables($connection))->toBe(['a', 'tinvar_migrations'])
            ->and(recorded($connection))->toBe([['2026_10_24_000001_create_a', 1]]);
    });
});

describe('fresh', function () {
    it('drops every table, the application\'s own included, and migrates from nothing', function () {
        [$files, $connection] = setUp();
        $files->add('2026_10_24_000001_create_a', CREATE_A);
        $files->add('2026_10_24_000002_create_b', CREATE_B);
        $migrator = new Migrator($connection, $files->directory);
        $migrator->migrate();
        $connection->execute("INSERT INTO a VALUES ('0192f3a1-0000-7000-8000-000000000001')");
        $connection->execute('CREATE TABLE stray (x INTEGER)');
        $connection->execute('CREATE VIEW strays AS SELECT x FROM stray');

        expect($migrator->fresh())->toHaveCount(2)
            ->and(tables($connection))->toBe(['a', 'b', 'tinvar_migrations'])
            ->and($connection->select("SELECT name FROM sqlite_master WHERE type = 'view'"))->toBe([])
            ->and($connection->select('SELECT COUNT(*) AS n FROM a'))->toBe([['n' => 0]])
            ->and($connection->select('PRAGMA foreign_keys'))->toBe([['foreign_keys' => 1]]);
    });
});

describe('the lock', function () {
    // Two deploys starting together: the second waits for the first, and
    // gives up after its timeout rather than migrating beside it.
    it('refuses a second run on the same database while the first holds the lock', function () {
        [$files, $connection] = setUp();
        $database = $files->database();
        $files->add('2026_10_24_000001_create_a', CREATE_A
            . " \$second = new \\Tinvar\\Orm\\Migrations\\Migrator(\\Tinvar\\Orm\\PdoConnection::sqlite('{$database}'), '{$files->directory}', lockTimeout: 0.2);"
            . " \$GLOBALS['refused'] = null;"
            . " try { \$second->migrate(); } catch (\\Tinvar\\Orm\\Migrations\\MigrationRefused \$e) { \$GLOBALS['refused'] = \$e->getMessage(); }");

        $started = microtime(true);
        new Migrator($connection, $files->directory)->migrate();

        // SQLite names the file by its real path: /private/tmp on macOS.
        $real = realpath($database);
        expect($GLOBALS['refused'])->toContain("Another migration has held {$real}-migrate.lock for 0.2s")
            ->and(microtime(true) - $started)->toBeGreaterThan(0.2)
            ->and(recorded($connection))->toBe([['2026_10_24_000001_create_a', 1]])
            // Gone once let go: a database's directory keeps no lock file.
            ->and(file_exists("{$database}-migrate.lock"))->toBeFalse();
        unset($GLOBALS['refused']);
    });

    it('is let go after a failure, so the next run is not kept waiting', function () {
        [$files, $connection] = setUp();
        $files->add('2026_10_24_000001_fail', "throw new \\RuntimeException('no');");
        expect(fn () => new Migrator($connection, $files->directory)->migrate())->toThrow(MigrationFailed::class);

        $files->add('2026_10_24_000001_fail', CREATE_A);

        expect(fn () => new Migrator(PdoConnection::sqlite($files->database()), $files->directory, lockTimeout: 0)->pending())->not->toThrow(MigrationRefused::class)
            ->and(fn () => new Migrator(PdoConnection::sqlite($files->database()), $files->directory, lockTimeout: 0)->rollback())->not->toThrow(MigrationRefused::class);
    });

    it('takes no lock on an in-memory database, which no other connection can see', function () {
        $files = new MigrationFiles();
        $files->add('2026_10_24_000001_create_a', CREATE_A);
        $connection = PdoConnection::sqlite(':memory:');

        expect(new Migrator($connection, $files->directory)->migrate())->toBe(['2026_10_24_000001_create_a'])
            ->and(tables($connection))->toBe(['a', 'tinvar_migrations']);
    });
});
