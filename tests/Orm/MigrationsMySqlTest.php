<?php

declare(strict_types=1);

/**
 * Migrations on MySQL, "a dialect layer" (tinvar-spec.md, "Migrations"):
 * the builder's statements run for real, the named lock, and what a failure
 * leaves where every schema change commits as it runs.
 *
 * Only TINVAR_TEST_MYSQL_DSN names a server, and without one these are
 * skipped, saying why. Each test works in a database of its own.
 */

namespace Tinvar\Tests\Orm\MigrationsMySql;

use Tinvar\Orm\Migrations\MigrationFailed;
use Tinvar\Orm\Migrations\MigrationRefused;
use Tinvar\Orm\Migrations\Migrator;
use Tinvar\Orm\PdoConnection;
use Tinvar\Tests\Orm\Fixtures\MigrationFiles;
use Tinvar\Tests\Orm\Fixtures\Databases;

require_once __DIR__ . '/Fixtures/migrations.php';
require_once __DIR__ . '/Fixtures/databases.php';

/** @param \Closure(PdoConnection, MigrationFiles, string): void $test */
function inDatabase(\Closure $test): void
{
    Databases::inScratchSchema('mysql', static function (\PDO $pdo, string $database) use ($test): void {
        $test(new PdoConnection($pdo, 'mysql'), new MigrationFiles(), $database);
    });
}

const CREATE_A = "\$schema->create('a', function (Table \$t): void { \$t->uuid('id')->primary(); });";

it('makes every type the builder names, with its keys, checks and indexes', function () {
    inDatabase(static function (PdoConnection $connection, MigrationFiles $files): void {
        $files->add('2026_10_24_000001_everything', <<<'PHP'
            $schema->create('parents', function (Table $t): void { $t->uuid('id')->primary(); });
                    $schema->create('everything', function (Table $t): void {
                        $t->uuid('id')->primary();
                        $t->string('code', 32)->unique();
                        $t->string('email')->caseInsensitive()->unique();
                        $t->text('body')->nullable();
                        $t->integer('n')->default(0)->check('n >= 0');
                        $t->bigInteger('big');
                        $t->boolean('flag')->default(true);
                        $t->float('ratio');
                        $t->decimal('price', 8, 2);
                        $t->json('meta');
                        $t->timestamp('at');
                        $t->date('day');
                        $t->binary('blob')->nullable();
                        $t->uuid('parent_id')->references('parents')->onDelete('cascade');
                        $t->index(['parent_id', 'at DESC']);
                    });
                    $schema->table('everything', function (Table $t): void { $t->string('note')->nullable(); $t->index(['note']); });
            PHP, "\$schema->drop('everything'); \$schema->drop('parents');");

        $migrator = new Migrator($connection, $files->directory);
        $migrator->migrate();
        $dialect = $migrator->dialect();

        expect(array_map(static fn (array $c): string => $c['type'], $dialect->columns($connection, 'everything')))->toMatchArray([
            'id' => 'char(36)', 'code' => 'varchar(32)', 'body' => 'text', 'flag' => 'tinyint(1)', 'meta' => 'json', 'at' => 'datetime(6)',
        ])
            ->and(array_column($dialect->indexes($connection, 'everything'), 'columns', 'name'))->toMatchArray([
                'everything_parent_id_at' => ['parent_id', 'at'],
                'everything_note' => ['note'],
            ])
            ->and($dialect->tables($connection))->toBe(['everything', 'parents', 'tinvar_migrations']);

    });
})->skip(fn () => Databases::mysqlMissing() ?? false);

it('compares text by case, and a case-insensitive column without regard to it', function () {
    inDatabase(static function (PdoConnection $connection, MigrationFiles $files): void {
        $files->add('2026_10_24_000001_people', "\$schema->create('people', function (Table \$t): void { \$t->string('name'); \$t->string('email')->caseInsensitive(); });");
        new Migrator($connection, $files->directory)->migrate();
        $connection->execute("INSERT INTO people VALUES ('Ada', 'Ada@shop.test')");

        expect($connection->select("SELECT name FROM people WHERE name = 'ada'"))->toBe([])
            ->and($connection->select("SELECT name FROM people WHERE email = 'ada@SHOP.test'"))->toBe([['name' => 'Ada']]);
    });
})->skip(fn () => Databases::mysqlMissing() ?? false);

// MySQL commits each schema change as it runs: the failure says what it
// left, and the migration is not recorded, so it runs again once repaired.
it('lists what a failed migration left behind, and does not record it', function () {
    inDatabase(static function (PdoConnection $connection, MigrationFiles $files): void {
        $files->add('2026_10_24_000001_create_a_then_fail', CREATE_A . " \$schema->sql('SELECT * FROM nowhere');");
        $migrator = new Migrator($connection, $files->directory);

        try {
            $migrator->migrate();
            throw new \LogicException('The migration did not fail.');
        } catch (MigrationFailed $e) {
            expect($e->leftBehind)->toHaveCount(1)
                ->and($e->leftBehind[0])->toStartWith('CREATE TABLE `a`');
        }

        expect($migrator->dialect()->tables($connection))->toBe(['a', 'tinvar_migrations'])
            ->and($migrator->pending())->toBe(['2026_10_24_000001_create_a_then_fail']);
    });
})->skip(fn () => Databases::mysqlMissing() ?? false);

it('keeps a second run out while another session holds the named lock', function () {
    inDatabase(static function (PdoConnection $connection, MigrationFiles $files, string $database): void {
        $files->add('2026_10_24_000001_create_a', CREATE_A);
        $other = Databases::another('mysql');
        $other->exec("USE {$database}");
        $other->query("SELECT GET_LOCK(CONCAT('tinvar_migrations:', SHA1(DATABASE())), 0)");

        try {
            expect(fn () => new Migrator($connection, $files->directory, lockTimeout: 1)->migrate())
                ->toThrow(MigrationRefused::class, 'the MySQL named lock');
        } finally {
            $other->query('SELECT RELEASE_ALL_LOCKS()');
        }

        expect(new Migrator($connection, $files->directory, lockTimeout: 0)->migrate())->toBe(['2026_10_24_000001_create_a']);
    });
})->skip(fn () => Databases::mysqlMissing() ?? false);

it('drops every table, foreign keys or not, and migrates afresh', function () {
    inDatabase(static function (PdoConnection $connection, MigrationFiles $files): void {
        $files->add('2026_10_24_000001_create_a', CREATE_A);
        $files->add('2026_10_24_000002_create_b', "\$schema->create('b', function (Table \$t): void { \$t->uuid('a_id')->references('a'); });");
        $migrator = new Migrator($connection, $files->directory);
        $migrator->migrate();

        expect($migrator->fresh())->toHaveCount(2)
            ->and($migrator->dialect()->tables($connection))->toBe(['a', 'b', 'tinvar_migrations']);
    });
})->skip(fn () => Databases::mysqlMissing() ?? false);
