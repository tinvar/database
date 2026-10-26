<?php

declare(strict_types=1);

/**
 * What each dialect makes of the schema builder, read as SQL without a
 * server: "Postgres first; MySQL as a dialect layer" (tinvar-spec.md,
 * "Migrations"), and SQLite, which the shop and every test run on. The
 * statements are run against real servers in the Postgres and MySQL
 * migration tests, where one is found.
 */

namespace Tinvar\Tests\Orm\SchemaDialect;

use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Expression;
use Tinvar\Orm\Schema\Index;
use Tinvar\Orm\Schema\MySqlDialect;
use Tinvar\Orm\Schema\PostgresDialect;
use Tinvar\Orm\Schema\SqliteDialect;
use Tinvar\Orm\Schema\Table;

/** The orders table every dialect is asked for: a key, a reference, a check, an index. */
function orders(): Table
{
    $table = new Table('orders', creating: true);
    $table->uuid('id')->primary();
    $table->string('number', 32)->unique();
    $table->string('email')->caseInsensitive();
    $table->uuid('customer_id')->references('customers')->onDelete('cascade');
    $table->integer('total_cents')->default(0)->check('total_cents >= 0');
    $table->boolean('gift')->default(false);
    $table->json('meta')->nullable();
    $table->timestamp('placed_at');
    $table->index(['customer_id', 'placed_at DESC'], 'orders_customer_placed_at');

    return $table;
}

describe('creating a table', function () {
    it('is SQLite affinities, NOCASE, and its indexes after it', function () {
        expect(new SqliteDialect()->createTable(orders()))->toBe([
            "CREATE TABLE \"orders\" (\n"
            . "    \"id\" TEXT NOT NULL PRIMARY KEY,\n"
            . "    \"number\" TEXT NOT NULL UNIQUE,\n"
            . "    \"email\" TEXT COLLATE NOCASE NOT NULL,\n"
            . "    \"customer_id\" TEXT NOT NULL,\n"
            . "    \"total_cents\" INTEGER NOT NULL DEFAULT 0 CHECK (total_cents >= 0),\n"
            . "    \"gift\" INTEGER NOT NULL DEFAULT 0,\n"
            . "    \"meta\" TEXT,\n"
            . "    \"placed_at\" TEXT NOT NULL,\n"
            . "    CONSTRAINT \"orders_customer_id_foreign\" FOREIGN KEY (\"customer_id\") REFERENCES \"customers\" (\"id\") ON DELETE CASCADE\n"
            . ')',
            'CREATE INDEX "orders_customer_placed_at" ON "orders" ("customer_id", "placed_at" DESC)',
        ]);
    });

    it('is Postgres types, citext installed first, and booleans as booleans', function () {
        expect(new PostgresDialect()->createTable(orders()))->toBe([
            'CREATE EXTENSION IF NOT EXISTS citext',
            "CREATE TABLE \"orders\" (\n"
            . "    \"id\" uuid NOT NULL PRIMARY KEY,\n"
            . "    \"number\" varchar(32) NOT NULL UNIQUE,\n"
            . "    \"email\" citext NOT NULL,\n"
            . "    \"customer_id\" uuid NOT NULL,\n"
            . "    \"total_cents\" integer NOT NULL DEFAULT 0 CHECK (total_cents >= 0),\n"
            . "    \"gift\" boolean NOT NULL DEFAULT FALSE,\n"
            . "    \"meta\" jsonb,\n"
            . "    \"placed_at\" timestamptz NOT NULL,\n"
            . "    CONSTRAINT \"orders_customer_id_foreign\" FOREIGN KEY (\"customer_id\") REFERENCES \"customers\" (\"id\") ON DELETE CASCADE\n"
            . ')',
            'CREATE INDEX "orders_customer_placed_at" ON "orders" ("customer_id", "placed_at" DESC)',
        ]);
    });

    // A column's REFERENCES is parsed and ignored by MySQL, so the key is a
    // table constraint everywhere; indexes go inside, where IF NOT EXISTS
    // covers them.
    it('is MySQL types, a binary collation but where asked, and its indexes inside', function () {
        expect(new MySqlDialect()->createTable(orders(), ifNotExists: true))->toBe([
            "CREATE TABLE IF NOT EXISTS `orders` (\n"
            . "    `id` CHAR(36) NOT NULL PRIMARY KEY,\n"
            . "    `number` VARCHAR(32) NOT NULL UNIQUE,\n"
            . "    `email` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,\n"
            . "    `customer_id` CHAR(36) NOT NULL,\n"
            . "    `total_cents` INT NOT NULL DEFAULT 0 CHECK (total_cents >= 0),\n"
            . "    `gift` TINYINT(1) NOT NULL DEFAULT 0,\n"
            . "    `meta` JSON,\n"
            . "    `placed_at` DATETIME(6) NOT NULL,\n"
            . "    CONSTRAINT `orders_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,\n"
            . "    INDEX `orders_customer_placed_at` (`customer_id`, `placed_at` DESC)\n"
            . ') ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_bin',
        ]);
    });

    it('makes a table and its indexes only where missing, when asked', function () {
        expect(new SqliteDialect()->createTable(orders(), ifNotExists: true))
            ->sequence(
                fn ($sql) => $sql->toStartWith('CREATE TABLE IF NOT EXISTS "orders"'),
                fn ($sql) => $sql->toBe('CREATE INDEX IF NOT EXISTS "orders_customer_placed_at" ON "orders" ("customer_id", "placed_at" DESC)'),
            );
    });

    it('holds a tenant column to the policy on Postgres, and only there', function () {
        $table = new Table('racks', creating: true);
        $table->uuid('id')->primary();
        $table->tenant();

        $postgres = new PostgresDialect()->createTable($table);

        expect($postgres[1])->toBe('CREATE INDEX "racks_tenant_id" ON "racks" ("tenant_id")')
            ->and(\array_slice($postgres, 2))->toBe(\Tinvar\Orm\RowLevelSecurity::policy('racks', 'tenant_id', 'text'))
            ->and($postgres[0])->toContain('"tenant_id" varchar(255) NOT NULL')
            ->and(new SqliteDialect()->createTable($table))->toHaveCount(2)
            ->and(new MySqlDialect()->createTable($table))->toHaveCount(1);
    });

    it('takes a primary key over several columns, and a type the builder has no name for', function () {
        $table = new Table('app.memberships', creating: true);
        $table->uuid('user_id');
        $table->uuid('team_id');
        $table->column('search', 'tsvector')->nullable();
        $table->primary(['user_id', 'team_id']);
        $table->unique(['team_id', 'user_id']);

        expect(new PostgresDialect()->createTable($table))->toBe([
            "CREATE TABLE \"app\".\"memberships\" (\n"
            . "    \"user_id\" uuid NOT NULL,\n"
            . "    \"team_id\" uuid NOT NULL,\n"
            . "    \"search\" tsvector,\n"
            . "    PRIMARY KEY (\"user_id\", \"team_id\")\n"
            . ')',
            'CREATE UNIQUE INDEX "memberships_team_id_user_id_unique" ON "app"."memberships" ("team_id", "user_id")',
        ]);
    });
});

/** A change to every part of a table at once. */
function changes(): Table
{
    $table = new Table('orders', creating: false);
    $table->renameColumn('total', 'total_cents');
    $table->dropIndex('orders_total');
    $table->dropColumn('legacy');
    $table->string('note')->nullable();
    $table->string('code')->unique()->default("it's");
    $table->uuid('site_id')->nullable()->references('sites');
    $table->index(['site_id']);

    return $table;
}

describe('changing a table', function () {
    it('renames, drops indexes before columns, and adds columns before their indexes', function () {
        expect(new SqliteDialect()->alterTable(changes()))->toBe([
            'ALTER TABLE "orders" RENAME COLUMN "total" TO "total_cents"',
            'DROP INDEX "orders_total"',
            'ALTER TABLE "orders" DROP COLUMN "legacy"',
            'ALTER TABLE "orders" ADD COLUMN "note" TEXT',
            // SQLite will not add a UNIQUE column; every dialect gets the index.
            "ALTER TABLE \"orders\" ADD COLUMN \"code\" TEXT NOT NULL DEFAULT 'it''s'",
            'ALTER TABLE "orders" ADD COLUMN "site_id" TEXT REFERENCES "sites" ("id")',
            'CREATE INDEX "orders_site_id" ON "orders" ("site_id")',
            'CREATE UNIQUE INDEX "orders_code_unique" ON "orders" ("code")',
        ]);
    });

    it('adds a foreign key on MySQL as a constraint of its own, and drops an index by its table', function () {
        expect(new MySqlDialect()->alterTable(changes()))->toBe([
            'ALTER TABLE `orders` RENAME COLUMN `total` TO `total_cents`',
            'DROP INDEX `orders_total` ON `orders`',
            'ALTER TABLE `orders` DROP COLUMN `legacy`',
            'ALTER TABLE `orders` ADD COLUMN `note` VARCHAR(255)',
            "ALTER TABLE `orders` ADD COLUMN `code` VARCHAR(255) NOT NULL DEFAULT 'it''s'",
            'ALTER TABLE `orders` ADD COLUMN `site_id` CHAR(36)',
            'ALTER TABLE `orders` ADD CONSTRAINT `orders_site_id_foreign` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`)',
            'CREATE INDEX `orders_site_id` ON `orders` (`site_id`)',
            'CREATE UNIQUE INDEX `orders_code_unique` ON `orders` (`code`)',
        ]);
    });

    it('drops a Postgres index in its table\'s schema, and undoes the tenant policy', function () {
        expect(new PostgresDialect()->dropIndex('app.orders', 'orders_total'))->toBe('DROP INDEX "app"."orders_total"')
            ->and(new PostgresDialect()->dropTenantPolicy('racks'))->toBe([
                'DROP POLICY IF EXISTS tinvar_tenant ON "racks"',
                'ALTER TABLE "racks" NO FORCE ROW LEVEL SECURITY',
                'ALTER TABLE "racks" DISABLE ROW LEVEL SECURITY',
            ])
            ->and(new SqliteDialect()->dropTenantPolicy('racks'))->toBe([]);
    });

    it('refuses a primary key added to a table that exists, and table changes in create()', function () {
        $table = new Table('orders', creating: false);
        $table->uuid('id')->primary();

        expect(fn () => new SqliteDialect()->alterTable($table))->toThrow(\LogicException::class, 'declared when the table is created')
            ->and(fn () => new Table('orders', creating: true)->dropColumn('x'))->toThrow(\LogicException::class, 'no place in create()')
            ->and(fn () => new Table('orders', creating: false)->primary(['id']))->toThrow(\LogicException::class);
    });
});

describe('what a statement may carry', function () {
    it('refuses names that are not plain identifiers', function () {
        expect(fn () => new Table('orders; DROP TABLE x', creating: true))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => new Table('orders', creating: true)->string('na"me'))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => new Table('orders', creating: true)->uuid('a')->references('x`y'))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => new Table('orders', creating: true)->index(['a; DROP']))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => new Table('orders', creating: true)->column('a', 'int); DROP TABLE x; --'))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => new Table('orders', creating: true)->uuid('a')->onDelete('cascade'))->toThrow(\LogicException::class, 'no references()')
            ->and(fn () => new Table('orders', creating: true)->uuid('a')->references('b')->onDelete('explode'))->toThrow(\InvalidArgumentException::class);
    });

    it('quotes string defaults as literals, MySQL\'s backslashes too, and writes an Expression as it is', function () {
        $table = new Table('t', creating: true);
        $table->string('a')->default("O'Brien \\ co");
        $table->timestamp('b')->default(new Expression('CURRENT_TIMESTAMP'));
        $table->float('c')->default(1.5);
        $table->string('d')->nullable()->default(null);

        expect(new SqliteDialect()->createTable($table)[0])->toContain("\"a\" TEXT NOT NULL DEFAULT 'O''Brien \\ co'")
            ->and(new MySqlDialect()->createTable($table)[0])->toContain("`a` VARCHAR(255) NOT NULL DEFAULT 'O''Brien \\\\ co'")
            ->and(new PostgresDialect()->createTable($table)[0])
            ->toContain('"b" timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP')
            ->toContain('"c" double precision NOT NULL DEFAULT 1.5')
            ->toContain('"d" varchar(255) DEFAULT NULL');
    });

    it('names an index for its table and columns unless told, without the table\'s schema', function () {
        expect(Index::of('order_lines', ['order_id', 'position'], false, null)->name)->toBe('order_lines_order_id_position')
            ->and(Index::of('app.orders', ['email'], true, null)->name)->toBe('orders_email_unique')
            ->and(Index::of('orders', ['placed_at desc'], false, 'by_date')->columns)->toBe([['placed_at', 'DESC']]);
    });

    it('declares a column once', function () {
        $table = new Table('t', creating: true);
        $table->uuid('id');

        expect(fn () => $table->string('id'))->toThrow(\LogicException::class, 'twice')
            ->and(fn () => $table->tenant('tenant_id') && $table->tenant('other'))->toThrow(\LogicException::class, 'already has a tenant column');
    });

    it('has no dialect for a driver it does not know', function () {
        expect(Dialect::for(new \Tinvar\Orm\PdoConnection(new \PDO('sqlite::memory:'))))->toBeInstanceOf(SqliteDialect::class)
            ->and(fn () => Dialect::for(new class implements \Tinvar\Orm\Connection {
                public function select(string $sql, array $bindings = []): array
                {
                    return [];
                }

                public function execute(string $sql, array $bindings = []): int
                {
                    return 0;
                }

                public function statementCount(): int
                {
                    return 0;
                }

                public function resetStatementCount(): void {}

                public function statementLog(): array
                {
                    return [];
                }

                public function name(): string
                {
                    return 'fake';
                }

                public function transaction(\Closure $work): mixed
                {
                    return $work();
                }

                public function durableTransaction(\Closure $work): mixed
                {
                    return $work();
                }

                public function inTransaction(): bool
                {
                    return false;
                }

                public function readOnly(\Closure $work): mixed
                {
                    return $work();
                }

                public function isReadOnly(): bool
                {
                    return false;
                }

                public function rollBackOpenTransaction(): bool
                {
                    return false;
                }
            }))->toThrow(\InvalidArgumentException::class, 'no dialect');
    });
});
