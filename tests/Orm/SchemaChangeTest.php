<?php

declare(strict_types=1);

/**
 * A column changed in place (Column::change()) and a column dropped that
 * something depends on: what Postgres and MySQL do with ALTER TABLE, and
 * SQLite -- which cannot -- by rebuilding the table around it, keeping its
 * rows, keys, checks, indexes and triggers.
 */

namespace Tinvar\Tests\Orm\SchemaChange;

use Tinvar\Orm\Migrations\MigrationFailed;
use Tinvar\Orm\Migrations\Migrator;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\Column;
use Tinvar\Orm\Schema\MySqlDialect;
use Tinvar\Orm\Schema\PostgresDialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\SqliteDialect;
use Tinvar\Orm\Schema\SqliteTable;
use Tinvar\Orm\Schema\Table;
use Tinvar\Tests\Orm\Fixtures\MigrationFiles;
use Tinvar\Tests\Orm\Fixtures\Databases;

require_once __DIR__ . '/Fixtures/migrations.php';
require_once __DIR__ . '/Fixtures/databases.php';

/** A table with something of everything a rebuild must keep: a key referencing another table, checks, a unique, indexes. */
function shop(Schema $schema): void
{
    $schema->create('parents', static function (Table $t): void {
        $t->uuid('id')->primary();
        $t->string('code')->unique()->caseInsensitive();
    });
    $schema->create('items', static function (Table $t): void {
        $t->uuid('id')->primary();
        $t->uuid('parent_id')->references('parents')->onDelete('cascade');
        $t->integer('qty')->check('qty > 0');
        $t->string('note')->nullable();
        $t->string('label')->default('x');
        $t->index(['parent_id', 'qty']);
        $t->index(['label']);
    });
}

function sqliteSchema(): array
{
    $connection = PdoConnection::sqlite(':memory:');

    return [$connection, new Schema($connection, new SqliteDialect())];
}

describe('change() spelled', function () {
    it('alters a Postgres column in place, the default dropped before the type changes', function () {
        $table = new Table('items', creating: false);
        $table->integer('qty')->default(1)->change();
        $table->text('note')->nullable()->caseInsensitive()->change();

        expect(new PostgresDialect()->alterTable($table))->toBe([
            'CREATE EXTENSION IF NOT EXISTS citext',
            'ALTER TABLE "items" ALTER COLUMN "qty" DROP DEFAULT',
            'ALTER TABLE "items" ALTER COLUMN "qty" TYPE integer USING "qty"::integer',
            'ALTER TABLE "items" ALTER COLUMN "qty" SET DEFAULT 1',
            'ALTER TABLE "items" ALTER COLUMN "qty" SET NOT NULL',
            'ALTER TABLE "items" ALTER COLUMN "note" DROP DEFAULT',
            'ALTER TABLE "items" ALTER COLUMN "note" TYPE citext USING "note"::citext',
            'ALTER TABLE "items" ALTER COLUMN "note" DROP NOT NULL',
        ]);
    });

    it('redefines a MySQL column whole', function () {
        $table = new Table('items', creating: false);
        $table->string('note', 40)->nullable()->default('none')->change();

        expect(new MySqlDialect()->alterTable($table))->toBe([
            "ALTER TABLE `items` MODIFY COLUMN `note` VARCHAR(40) DEFAULT 'none'",
        ]);
    });

    it('is refused in create(), and with a key, unique index or check, which are changes of their own', function () {
        $create = new Table('items', creating: true);
        $create->integer('qty')->change();
        $keyed = new Table('items', creating: false);
        $keyed->string('code')->unique()->change();

        expect(fn () => new PostgresDialect()->createTable($create))->toThrow(\LogicException::class, 'not create()')
            ->and(fn () => new PostgresDialect()->alterTable($keyed))->toThrow(\LogicException::class, 'added or dropped on its own');
    });

    it('is a rebuild on SQLite, which needs the table from the database', function () {
        $table = new Table('items', creating: false);
        $table->integer('qty')->change();

        expect(fn () => new SqliteDialect()->alterTable($table))->toThrow(\LogicException::class, 'rebuilding table');
    });
});

describe('the SQLite rebuild', function () {
    it('changes a column\'s type, nullability and default, keeping every row, key, check, index and trigger', function () {
        [$connection, $schema] = sqliteSchema();
        shop($schema);
        $connection->execute('CREATE VIEW labels AS SELECT label FROM items');
        $connection->execute('CREATE TRIGGER items_touched AFTER UPDATE ON items BEGIN SELECT 1; END');
        $connection->execute("INSERT INTO parents VALUES ('p', 'P1')");
        $connection->execute("INSERT INTO items (id, parent_id, qty, note) VALUES ('i', 'p', 2, 'n')");

        $dialect = new SqliteDialect();
        $dialect->suspendForeignKeys($connection);
        $connection->transaction(static function () use ($schema): void {
            $schema->table('items', static function (Table $t): void {
                $t->text('note')->default('none')->change();
                $t->integer('label')->nullable()->change();
            });
        });
        $dialect->checkForeignKeys($connection);
        $dialect->resumeForeignKeys($connection);

        $columns = $dialect->columns($connection, 'items');
        expect($columns['note'])->toMatchArray(['nullable' => false, 'default' => 'none', 'defaultKind' => 'literal'])
            ->and($columns['label'])->toMatchArray(['type' => 'INTEGER', 'nullable' => true, 'defaultKind' => 'none'])
            ->and($connection->select('SELECT id, parent_id, qty, note FROM items'))->toBe([['id' => 'i', 'parent_id' => 'p', 'qty' => 2, 'note' => 'n']])
            ->and(array_column($dialect->indexes($connection, 'items'), 'columns', 'name'))->toMatchArray([
                'items_parent_id_qty' => ['parent_id', 'qty'],
                'items_label' => ['label'],
            ])
            ->and($connection->select("SELECT name FROM sqlite_master WHERE type IN ('trigger', 'view') ORDER BY name"))
            ->toBe([['name' => 'items_touched'], ['name' => 'labels']])
            // The check and the key came across with the table.
            ->and(fn () => $connection->execute("INSERT INTO items (id, parent_id, qty) VALUES ('j', 'p', 0)"))->toThrow(\PDOException::class, 'CHECK')
            ->and(fn () => $connection->execute("INSERT INTO items (id, parent_id, qty) VALUES ('k', 'gone', 1)"))->toThrow(\PDOException::class, 'FOREIGN KEY');
    });

    it('drops a column a foreign key and an index are on, and the index with it, where DROP COLUMN refuses', function () {
        [$connection, $schema] = sqliteSchema();
        shop($schema);
        $connection->execute("INSERT INTO parents VALUES ('p', 'P1')");
        $connection->execute("INSERT INTO items (id, parent_id, qty) VALUES ('i', 'p', 2)");

        $schema->table('items', static fn (Table $t) => $t->dropColumn('parent_id'));

        $dialect = new SqliteDialect();
        expect(array_keys($dialect->columns($connection, 'items')))->toBe(['id', 'qty', 'note', 'label'])
            ->and(array_column($dialect->indexes($connection, 'items'), 'name'))->not->toContain('items_parent_id_qty')
            ->and(array_column($dialect->indexes($connection, 'items'), 'name'))->toContain('items_label')
            ->and($connection->select('SELECT * FROM pragma_foreign_key_list(?)', ['items']))->toBe([])
            ->and($connection->select('SELECT id, qty FROM items'))->toBe([['id' => 'i', 'qty' => 2]]);
    });

    it('drops the checks reading a dropped column, as Postgres does', function () {
        [$connection, $schema] = sqliteSchema();
        $connection->execute('CREATE TABLE spans (id TEXT PRIMARY KEY, low INTEGER, high INTEGER CHECK (high > low), CHECK (low >= 0))');

        $schema->table('spans', static fn (Table $t) => $t->dropColumn('low'));

        expect($connection->select('SELECT sql FROM sqlite_master WHERE name = ?', ['spans'])[0]['sql'])
            ->toBe("CREATE TABLE \"spans\" (\n    id TEXT PRIMARY KEY,\n    high INTEGER\n)");
    });

    it('refuses to rebuild a table others reference while foreign keys are enforced, rather than cascade into them', function () {
        [$connection, $schema] = sqliteSchema();
        shop($schema);
        $connection->execute("INSERT INTO parents VALUES ('p', 'P1')");
        $connection->execute("INSERT INTO items (id, parent_id, qty) VALUES ('i', 'p', 2)");

        expect(fn () => $schema->table('parents', static fn (Table $t) => $t->text('code')->change()))
            ->toThrow(\LogicException::class, 'would delete or change the rows that reference it')
            ->and($connection->select('SELECT count(*) AS n FROM items'))->toBe([['n' => 1]]);
    });

    it('rebuilds a referenced table in a migration, deleting nothing that references it', function () {
        $files = new MigrationFiles();
        $connection = PdoConnection::sqlite($files->database(), create: true);
        shop(new Schema($connection, new SqliteDialect()));
        $connection->execute("INSERT INTO parents VALUES ('p', 'P1')");
        $connection->execute("INSERT INTO items (id, parent_id, qty) VALUES ('i', 'p', 2)");
        $files->add('2026_10_24_000001_code_as_text', "\$schema->table('parents', fn (Table \$t) => \$t->text('code')->change());");

        new Migrator($connection, $files->directory)->migrate();

        expect($connection->select('SELECT id, parent_id FROM items'))->toBe([['id' => 'i', 'parent_id' => 'p']])
            ->and($connection->select('PRAGMA foreign_keys')[0]['foreign_keys'])->toBe(1)
            // The case-insensitivity went with the definition change() replaced.
            ->and(new SqliteDialect()->columns($connection, 'parents')['code']['caseInsensitive'])->toBeFalse();
    });

    it('fails a migration that leaves a row referencing one that is gone, since nothing cascades in one', function () {
        $files = new MigrationFiles();
        $connection = PdoConnection::sqlite($files->database(), create: true);
        shop(new Schema($connection, new SqliteDialect()));
        $connection->execute("INSERT INTO parents VALUES ('p', 'P1')");
        $connection->execute("INSERT INTO items (id, parent_id, qty) VALUES ('i', 'p', 2)");
        $files->add('2026_10_24_000001_prune', "\$schema->sql('DELETE FROM parents');");

        expect(fn () => new Migrator($connection, $files->directory)->migrate())
            ->toThrow(MigrationFailed::class)
            ->and($connection->select('SELECT count(*) AS n FROM parents'))->toBe([['n' => 1]])
            ->and($connection->select('PRAGMA foreign_keys')[0]['foreign_keys'])->toBe(1);

        try {
            new Migrator($connection, $files->directory)->migrate();
        } catch (MigrationFailed $e) {
            expect($e->getPrevious()?->getMessage())->toContain('1 row(s) reference rows that are not there, "items" row 1 among them');
        }
    });
});

describe('a CREATE TABLE read back', function () {
    it('tells columns from table constraints, and keeps a clause\'s own NULL, DEFAULT and NOT with it', function () {
        $table = SqliteTable::parse(
            "CREATE TABLE \"t\" ( -- a comment, (with a parenthesis\n"
            . "  \"id\" TEXT PRIMARY KEY, [odd name] VARCHAR(10, 2) COLLATE NOCASE NOT NULL DEFAULT NULL,\n"
            . "  p TEXT REFERENCES parents (id) ON DELETE SET NULL NOT DEFERRABLE, d TEXT DEFAULT 'a, (b)' CHECK (d <> ')'),\n"
            . "  CONSTRAINT pk UNIQUE (p, d), CHECK (p IS NOT NULL)\n) WITHOUT ROWID",
        );

        expect($table->columns())->toBe(['id', 'odd name', 'p', 'd'])
            ->and($table->caseInsensitive('odd name'))->toBeTrue()
            ->and($table->caseInsensitive('p'))->toBeFalse()
            ->and($table->tail)->toBe(' WITHOUT ROWID')
            ->and($table->namedElsewhere('p'))->toBeTrue()
            ->and($table->namedElsewhere('odd name'))->toBeFalse()
            ->and($table->rebuilt(['p'], ['d' => '"d" INTEGER NOT NULL']))->toBe(
                "\n    \"id\" TEXT PRIMARY KEY,\n    [odd name] VARCHAR(10, 2) COLLATE NOCASE NOT NULL DEFAULT NULL,\n"
                . "    \"d\" INTEGER NOT NULL CHECK (d <> ')')\n",
            );
    });

    it('refuses to drop a column a generated column is computed from', function () {
        $table = SqliteTable::parse('CREATE TABLE t (a INTEGER, b INTEGER GENERATED ALWAYS AS (a * 2))');

        expect(fn () => $table->rebuilt(['a'], []))->toThrow(\LogicException::class, 'generated from a column being dropped');
    });
});

describe('change() on a server', function () {
    /** @param \Closure(Schema): void $check */
    $behaves = static function (PdoConnection $connection, \Closure $check): void {
        $schema = new Schema($connection, \Tinvar\Orm\Schema\Dialect::for($connection));
        $schema->create('items', static function (Table $t): void {
            $t->uuid('id')->primary();
            $t->string('qty')->default('1');
            $t->string('note');
        });
        $connection->execute("INSERT INTO items (id, qty, note) VALUES ('00000000-0000-7000-8000-000000000001', '7', 'n')");

        $schema->table('items', static function (Table $t): void {
            $t->integer('qty')->default(2)->change();
            $t->text('note')->nullable()->change();
        });

        $check($schema);
    };

    it('converts the values of a Postgres column it changes', function () use ($behaves) {
        Databases::inScratchSchema('pgsql', static function (\PDO $pdo) use ($behaves): void {
            $connection = new PdoConnection($pdo, 'pgsql');
            $behaves($connection, static function () use ($connection): void {
                $columns = new PostgresDialect()->columns($connection, 'items');
                expect($columns['qty'])->toMatchArray(['type' => 'integer', 'nullable' => false, 'default' => 2])
                    ->and($columns['note'])->toMatchArray(['type' => 'text', 'nullable' => true])
                    ->and($connection->select('SELECT qty FROM items'))->toBe([['qty' => 7]]);
            });
        });
    })->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

    it('converts the values of a MySQL column it changes', function () use ($behaves) {
        Databases::inScratchSchema('mysql', static function (\PDO $pdo) use ($behaves): void {
            $connection = new PdoConnection($pdo, 'mysql');
            $behaves($connection, static function () use ($connection): void {
                $columns = new MySqlDialect()->columns($connection, 'items');
                expect($columns['qty'])->toMatchArray(['type' => 'int', 'nullable' => false, 'default' => '2'])
                    ->and($columns['note'])->toMatchArray(['type' => 'text', 'nullable' => true])
                    ->and($connection->select('SELECT qty FROM items'))->toBe([['qty' => 7]]);
            });
        });
    })->group('mysql')->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');
});

it('reads a default each database reports into its value, and nothing it computes', function () {
    $read = static function (?string $sql): array {
        return (static fn (?string $sql): array => self::parseDefault($sql))->bindTo(null, SqliteDialect::class)($sql);
    };

    expect($read(null))->toBe(['none', null])
        ->and($read('(0)'))->toBe(['literal', 0])
        ->and($read("'-1'::integer"))->toBe(['literal', '-1'])
        ->and($read("'it''s'::character varying"))->toBe(['literal', "it's"])
        ->and($read('1.5'))->toBe(['literal', 1.5])
        ->and($read('TRUE'))->toBe(['literal', true])
        ->and($read('NULL'))->toBe(['literal', null])
        ->and($read('CURRENT_TIMESTAMP'))->toBe(['expression', null])
        ->and($read('(1) + (2)'))->toBe(['expression', null])
        ->and($read("nextval('items_id_seq'::regclass)"))->toBe(['expression', null]);
});

it('names a family for each type, however the database spells it', function () {
    expect(new SqliteDialect()->family('VARCHAR(40)'))->toBe('text')
        ->and(new SqliteDialect()->family(new SqliteDialect()->typeOf(new Column('c', 'boolean'))))->toBe('integer')
        ->and(new PostgresDialect()->family('character varying(255)'))->toBe(new PostgresDialect()->family('varchar(40)'))
        ->and(new PostgresDialect()->family('timestamp(6) with time zone'))->toBe('timestamp')
        ->and(new PostgresDialect()->family('uuid'))->toBe('text')
        ->and(new MySqlDialect()->family('tinyint(1)'))->toBe('integer')
        ->and(new MySqlDialect()->family('int unsigned'))->toBe('integer')
        ->and(new PostgresDialect()->builderType('character varying(40)'))->toBe(['type' => 'string', 'length' => 40, 'scale' => null])
        ->and(new MySqlDialect()->builderType("enum('a','b')"))->toBeNull()
        ->and(new PostgresDialect()->builderType('timestamp without time zone'))->toBe(['type' => 'timestamp without time zone', 'length' => null, 'scale' => null])
        ->and(new SqliteDialect()->builderType('VARCHAR(40)'))->toBe(['type' => 'VARCHAR(40)', 'length' => null, 'scale' => null])
        ->and(new SqliteDialect()->builderType('integer references users'))->toBeNull()
        ->and(new SqliteDialect()->builderType('text not null'))->toBeNull();
});
