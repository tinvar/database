<?php

declare(strict_types=1);

/**
 * What make:migration sees now that the models can say it (ADR 0110): a
 * string's width and an integer's size where #[Column] declares one, a
 * check, a foreign key the database has otherwise, a unique index no model
 * declares -- and the renames whoever runs it says outright with --rename.
 * It still drops nothing: a key, a check or a unique index the models do
 * not want is named, for the reviewer to drop.
 */

namespace Tinvar\Tests\Orm\MigrationDiff;

use Tinvar\Orm\Attributes\BelongsTo;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\Unique;
use Tinvar\Orm\Migrations\MigrateCommand;
use Tinvar\Orm\Migrations\MigrationGenerator;
use Tinvar\Orm\Migrations\MigrationRefused;
use Tinvar\Orm\Migrations\Migrator;
use Tinvar\Orm\Model;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\MySqlDialect;
use Tinvar\Orm\Schema\PostgresDialect;
use Tinvar\Orm\Schema\SqliteDialect;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Orm\Fixtures\MigrationFiles;

require_once __DIR__ . '/Fixtures/migrations.php';
require_once __DIR__ . '/Fixtures/databases.php';

#[Table('diff_shelves')]
final class Shelf extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $label;
}

#[Table('diff_bins'), Unique('code')]
final class Bin extends Model
{
    #[Id] public private(set) string $id;
    #[Column(length: 40)] public string $code;
    #[Column(check: 'qty >= 0')] public int $qty = 0;
    #[Column(type: 'bigint')] public int $weight = 0;

    #[BelongsTo] public Shelf $shelf;
}

const MODELS = [Shelf::class, Bin::class];

/** Rows deleted with their shelf, and a spare shelf let go of (ADR 0122). */
#[Table('diff_crates')]
final class Crate extends Model
{
    #[Id] public private(set) string $id;

    #[BelongsTo(onDelete: 'cascade')] public Shelf $shelf;

    #[BelongsTo(foreignKey: 'spare_id', onDelete: 'Set  Null')] public ?Shelf $spare = null;
}

/** The crates table, whose shelf refuses a delete: RESTRICT, as NO ACTION does. */
#[Table('diff_crates')]
final class RefusingCrate extends Model
{
    #[Id] public private(set) string $id;

    #[BelongsTo(onDelete: 'restrict')] public Shelf $shelf;

    #[BelongsTo(foreignKey: 'spare_id', onDelete: 'set null')] public ?Shelf $spare = null;
}

#[Table('diff_crates')]
final class NeverNullCrate extends Model
{
    #[Id] public private(set) string $id;

    #[BelongsTo(onDelete: 'set null')] public Shelf $shelf;
}

#[Table('diff_crates')]
final class MisspeltCrate extends Model
{
    #[Id] public private(set) string $id;

    #[BelongsTo(onDelete: 'cascades')] public Shelf $shelf;
}

/**
 * Writes a relation's delete action on the key it makes, finds nothing
 * more to do, then finds the action the database has otherwise -- and a
 * table no model maps -- and names each, writing neither (ADR 0122).
 *
 * @param \Closure(string): void $make runs a CREATE TABLE as the database spells it
 * @param array{string, string} $types the key's type, and the text type
 */
function deleteActions(PdoConnection $connection, MigrationFiles $files, \Tinvar\Orm\Schema\Dialect $dialect, \Closure $make, array $types): void
{
    $generator = new MigrationGenerator($connection, $dialect);
    $source = (string) $generator->generate([Shelf::class, Crate::class], '2026-10-30');

    expect($source)->toContain("\$table->uuid('shelf_id')->references('diff_shelves')->onDelete('CASCADE');")
        ->and($source)->toContain("\$table->uuid('spare_id')->nullable()->references('diff_shelves')->onDelete('SET NULL');");
    migrate($files, $connection, $source);
    expect($generator->generate([Shelf::class, Crate::class], '2026-10-30'))->toBeNull()
        ->and($generator->warnings)->toBe([]);

    // The database's own: a shelf that refuses the delete, a table no
    // model maps, and one of the framework's, which is not named.
    [$key, $text] = $types;
    $make("CREATE TABLE diff_orphans (id {$key} PRIMARY KEY)");
    $make("CREATE TABLE tinvar_cache_example (id {$key} PRIMARY KEY, note {$text})");
    $connection->execute('DROP TABLE diff_crates');
    // Keys as table constraints: MySQL reads a REFERENCES on a column and
    // makes no key of it.
    $make("CREATE TABLE diff_crates (id {$key} PRIMARY KEY, shelf_id {$key} NOT NULL, spare_id {$key}, "
        . 'FOREIGN KEY (shelf_id) REFERENCES diff_shelves (id), FOREIGN KEY (spare_id) REFERENCES diff_shelves (id) ON DELETE SET NULL)');
    $make('CREATE INDEX diff_crates_shelf_id ON diff_crates (shelf_id)');
    $make('CREATE INDEX diff_crates_spare_id ON diff_crates (spare_id)');

    expect($generator->generate([Shelf::class, Crate::class], '2026-10-30'))->toBeNull();
    $warnings = implode("\n", $generator->warnings);
    expect($warnings)->toContain('diff_crates.shelf_id is deleted with ON DELETE CASCADE on the model, and ')
        ->and($warnings)->not->toContain('diff_crates.spare_id is deleted')
        ->and($warnings)->toContain('The database has tables no model maps: diff_orphans.')
        ->and($warnings)->not->toContain('tinvar_cache_example')
        ->and($warnings)->not->toContain('diff_bins');

    // RESTRICT and NO ACTION both refuse the delete: no news.
    expect($generator->generate([Shelf::class, RefusingCrate::class], '2026-10-30'))->toBeNull()
        ->and(implode("\n", $generator->warnings))->not->toContain('is deleted with');

    // Named in the file too, where there is a migration to write.
    $source = (string) $generator->generate([Shelf::class, RefusingCrate::class, Bin::class], '2026-10-30');
    expect(comments($source))->toContain('The database has tables no model maps: diff_orphans. A migration of the models drops none')
        ->and(strstr($source, 'public function down', true))->not->toContain('drop(');
}

describe('a relation\'s delete action, and a table no model maps', function () {
    it('refuses an action the model cannot hold when it compiles', function () {
        expect(fn () => \Tinvar\Orm\Metadata::for(NeverNullCrate::class))->toThrow(\Tinvar\Orm\MappingException::class, 'is never null')
            ->and(fn () => \Tinvar\Orm\Metadata::for(MisspeltCrate::class))->toThrow(\Tinvar\Orm\MappingException::class, "'cascades' is no ON DELETE action");
    });

    it('writes, compares and names them on SQLite', function () {
        [$files, $connection] = sqlite();
        deleteActions($connection, $files, new SqliteDialect(), static fn (string $sql): int => $connection->execute($sql), ['TEXT', 'TEXT']);
    });

    it('writes, compares and names them on Postgres', function () {
        Databases::inScratchSchema('pgsql', static function (\PDO $pdo, string $schema): void {
            $pdo->exec("SET search_path TO {$schema}, public");
            $connection = new PdoConnection($pdo, 'pgsql');
            deleteActions($connection, new MigrationFiles(), new PostgresDialect(), static fn (string $sql): int => $connection->execute($sql), ['uuid', 'text']);
        });
    })->skip(fn () => Databases::postgresMissing() ?? false);

    it('writes, compares and names them on MySQL', function () {
        Databases::inScratchSchema('mysql', static function (\PDO $pdo): void {
            $pdo->exec("SET SESSION sql_mode = CONCAT(@@sql_mode, ',ANSI_QUOTES')");
            $connection = new PdoConnection($pdo, 'mysql');
            // In the collation the builder makes its tables in, which a key
            // to one of them has to share.
            deleteActions($connection, new MigrationFiles(), new MySqlDialect(), static fn (string $sql): int => $connection->execute(
                str_starts_with($sql, 'CREATE TABLE') ? $sql . ' DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_bin' : $sql,
            ), ['CHAR(36)', 'TEXT']);
        });
    })->skip(fn () => Databases::mysqlMissing() ?? false);
});

/** @return array{MigrationFiles, PdoConnection} */
function sqlite(): array
{
    $files = new MigrationFiles();

    return [$files, PdoConnection::sqlite($files->database(), create: true)];
}

/** Writes $source as a migration and runs it. */
function migrate(MigrationFiles $files, PdoConnection $connection, string $source, string $name = '2026_10_29_000001_diff'): Migrator
{
    file_put_contents("{$files->directory}/{$name}.php", $source);
    $migrator = new Migrator($connection, $files->directory);
    $migrator->migrate();

    return $migrator;
}

/** The `//` comments in $source, as one line. */
function comments(string $source): string
{
    preg_match_all('~^ *// (.*)$~m', $source, $lines);

    return implode(' ', $lines[1]);
}

describe('on SQLite', function () {
    it('makes a table with its check, width and key, and then finds nothing more to do', function () {
        [$files, $connection] = sqlite();
        $generator = new MigrationGenerator($connection, new SqliteDialect());

        $source = (string) $generator->generate(MODELS, '2026-10-29');
        expect($source)->toContain("\$table->string('code', 40);")
            ->and($source)->toContain("\$table->integer('qty')->default(0)->check('qty >= 0');")
            ->and($source)->toContain("\$table->bigInteger('weight')->default(0);");

        migrate($files, $connection, $source);

        expect($generator->generate(MODELS, '2026-10-29'))->toBeNull()
            ->and($generator->warnings)->toBe([])
            ->and(fn () => $connection->execute("INSERT INTO diff_bins (id, code, qty, weight, shelf_id) VALUES ('b', 'c', -1, 0, 's')"))
            ->toThrow(\PDOException::class);
    });

    it('names a key, check and unique index the database has otherwise, and writes none of them', function () {
        [, $connection] = sqlite();
        $connection->execute('CREATE TABLE diff_shelves (id TEXT PRIMARY KEY, label TEXT NOT NULL)');
        $connection->execute('CREATE TABLE diff_other (id TEXT PRIMARY KEY)');
        $connection->execute(
            'CREATE TABLE diff_bins (id TEXT PRIMARY KEY, code TEXT NOT NULL, qty INTEGER NOT NULL DEFAULT 0 CHECK (qty > 10), '
            . 'weight INTEGER NOT NULL DEFAULT 0, shelf_id TEXT NOT NULL REFERENCES diff_other (id), legacy TEXT)',
        );
        $connection->execute('CREATE INDEX diff_bins_shelf_id ON diff_bins (shelf_id)');
        $connection->execute('CREATE UNIQUE INDEX diff_bins_code ON diff_bins (code)');
        $connection->execute('CREATE UNIQUE INDEX diff_bins_legacy ON diff_bins (legacy)');
        $generator = new MigrationGenerator($connection, new SqliteDialect());

        // Comments alone are no migration; the warnings say each.
        expect($generator->generate(MODELS, '2026-10-29'))->toBeNull();
        $warnings = implode("\n", $generator->warnings);

        expect($warnings)->toContain('diff_bins.shelf_id references diff_shelves on the model, and diff_other in the database')
            ->and($warnings)->toContain('diff_bins.qty is held to CHECK (qty >= 0) on the model, and the database has no such check')
            ->and($warnings)->toContain('diff_bins has a check in the database that no model declares, "qty > 10"')
            ->and($warnings)->toContain("diff_bins.diff_bins_legacy is a unique index over (legacy) that no #[Unique] of the models declares. If it is not wanted any more: \$table->dropIndex('diff_bins_legacy')")
            ->and($warnings)->not->toContain('diff_bins_code is a unique');
    });

    it('renames what --rename says, and nothing it cannot be', function () {
        [$files, $connection] = sqlite();
        $connection->execute('CREATE TABLE diff_racks (id TEXT PRIMARY KEY, title TEXT NOT NULL)');
        $connection->execute("INSERT INTO diff_racks VALUES ('s1', 'Top')");

        // Neither reads as a rename by itself -- the table's columns differ,
        // so nothing would be asked -- and both are said.
        $generator = new MigrationGenerator($connection, new SqliteDialect(), renames: [
            ['table' => null, 'from' => 'diff_racks', 'to' => 'diff_shelves'],
            ['table' => 'diff_shelves', 'from' => 'title', 'to' => 'label'],
        ]);
        $source = (string) $generator->generate([Shelf::class], '2026-10-29');

        expect($source)->toContain("\$schema->rename('diff_racks', 'diff_shelves');")
            ->and($source)->toContain("\$table->renameColumn('title', 'label');");
        migrate($files, $connection, $source);
        expect($connection->select('SELECT label FROM diff_shelves'))->toBe([['label' => 'Top']]);
    });

    it('refuses a --rename that is not one, writing nothing', function (array $rename, string $why) {
        [, $connection] = sqlite();
        $connection->execute('CREATE TABLE diff_shelves (id TEXT PRIMARY KEY, title TEXT NOT NULL)');
        $connection->execute('CREATE TABLE diff_spare (id TEXT PRIMARY KEY)');

        expect(fn () => new MigrationGenerator($connection, new SqliteDialect(), renames: [$rename])->generate(MODELS, '2026-10-29'))
            ->toThrow(MigrationRefused::class, $why);
    })->with([
        'a table no model has' => [['table' => null, 'from' => 'diff_spare', 'to' => 'diff_nowhere'], "no model's table is diff_nowhere"],
        'onto a table there' => [['table' => null, 'from' => 'diff_spare', 'to' => 'diff_shelves'], 'diff_shelves is in the database already'],
        'from a model\'s table' => [['table' => null, 'from' => 'diff_shelves', 'to' => 'diff_bins'], 'diff_shelves is a model\'s table'],
        'a column not there' => [['table' => 'diff_shelves', 'from' => 'name', 'to' => 'label'], 'diff_shelves.name is not in the database'],
        'onto a column not wanted' => [['table' => 'diff_shelves', 'from' => 'title', 'to' => 'caption'], 'diff_shelves.caption is not on a model'],
    ]);

    it('reads --rename from the command line, and refuses one it cannot read', function () {
        [$files, $connection] = sqlite();
        $connection->execute('CREATE TABLE diff_shelves (id TEXT PRIMARY KEY, title TEXT NOT NULL)');
        $out = [];
        $err = [];
        $command = new MigrateCommand(
            static fn (): PdoConnection => $connection,
            $files->directory,
            [Shelf::class],
            static function (string $line) use (&$out): void {
                $out[] = $line;
            },
            static function (string $line) use (&$err): void {
                $err[] = $line;
            },
            // Asked, it would say no: the flag answers.
            ask: static fn (): bool => false,
        );

        expect($command->run('make:migration', ['rename_title', '--rename=diff_shelves.title:label']))->toBe(0)
            ->and((string) file_get_contents((string) glob("{$files->directory}/*_rename_title.php")[0]))->toContain("\$table->renameColumn('title', 'label');")
            ->and($command->run('make:migration', ['bad', '--rename=diff_shelves.title']))->toBe(MigrateCommand::USAGE)
            ->and($command->run('make:migration', ['bad', '--rename=a;b:c']))->toBe(MigrateCommand::USAGE)
            ->and($command->run('make:migration', ['bad', '--blank', '--rename=a:b']))->toBe(MigrateCommand::USAGE);
    });
});

describe('on Postgres', function () {
    it('narrows a declared width and widens an integer, says so, and changes each back', function () {
        Databases::inScratchSchema('pgsql', static function (\PDO $pdo, string $schema): void {
            $pdo->exec("SET search_path TO {$schema}, public");
            $connection = new PdoConnection($pdo, 'pgsql');
            $files = new MigrationFiles();
            $connection->execute('CREATE TABLE diff_shelves (id uuid PRIMARY KEY, label varchar(255) NOT NULL)');
            $connection->execute(
                'CREATE TABLE diff_bins (id uuid PRIMARY KEY, code varchar(100) NOT NULL, qty integer NOT NULL DEFAULT 0 CHECK (qty >= 0), '
                . 'weight integer NOT NULL DEFAULT 0, shelf_id uuid NOT NULL REFERENCES diff_shelves (id))',
            );
            $connection->execute('CREATE INDEX diff_bins_shelf_id ON diff_bins (shelf_id)');
            $connection->execute('CREATE UNIQUE INDEX diff_bins_code ON diff_bins (code)');
            $generator = new MigrationGenerator($connection, new PostgresDialect());

            $source = (string) $generator->generate(MODELS, '2026-10-29');

            expect($source)->toContain("\$table->string('code', 40)->change();")
                ->and($source)->toContain("\$table->bigInteger('weight')->default(0)->change();")
                ->and($source)->toContain("\$table->string('code', 100)->change();")
                ->and($source)->toContain("\$table->integer('weight')->default(0)->change();")
                ->and(comments($source))->toContain('diff_bins.code is string(100) in the database and string(40) on the model')
                // The check is there as Postgres spells it, and the key.
                ->and(implode("\n", $generator->warnings))->toBe('');

            $migrator = migrate($files, $connection, $source);
            expect($generator->generate(MODELS, '2026-10-29'))->toBeNull()
                ->and($migrator->rollback())->toBe(['2026_10_29_000001_diff']);
        });
    })->skip(fn () => Databases::postgresMissing() ?? false);
});
