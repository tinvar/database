<?php

declare(strict_types=1);

/**
 * Migrations on Postgres, "first" among the dialects (tinvar-spec.md,
 * "Migrations"): the builder's statements run for real, a failure taken
 * back whole, the advisory lock, and the row-level security policy a
 * #[TenantScoped] table is given by migration.
 *
 * Skipped, saying why, without a server (tests/Orm/Fixtures/databases.php
 * says how one is found). Each test works in a schema of its own; the
 * policy is held to a role that is not a superuser, since a superuser
 * bypasses every policy there is.
 */

namespace Tinvar\Tests\Orm\MigrationsPostgres;

use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Migrations\MigrateCommand;
use Tinvar\Orm\Migrations\MigrationFailed;
use Tinvar\Orm\Migrations\MigrationGenerator;
use Tinvar\Orm\Migrations\MigrationRefused;
use Tinvar\Orm\Migrations\Migrator;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\PostgresDialect;
use Tinvar\Tests\Orm\Fixtures\MigrationFiles;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Orm\Fixtures\Tenancy;

require_once __DIR__ . '/Fixtures/migrations.php';
require_once __DIR__ . '/Fixtures/models.php';
require_once __DIR__ . '/Fixtures/tenant.php';
require_once __DIR__ . '/Fixtures/databases.php';
require_once __DIR__ . '/Fixtures/tenancy.php';

/**
 * Runs $test in a scratch schema, with public after it on the search path
 * for an extension installed there.
 *
 * @param \Closure(PdoConnection, MigrationFiles, string, \PDO): void $test
 */
function inSchema(\Closure $test): void
{
    Databases::inScratchSchema('pgsql', static function (\PDO $pdo, string $schema) use ($test): void {
        $pdo->exec("SET search_path TO {$schema}, public");
        $test(new PdoConnection($pdo, 'pgsql'), new MigrationFiles(), $schema, $pdo);
    });
}

/**
 * Runs $test as a role that is neither a superuser nor BYPASSRLS, and owns
 * what it makes -- as the application's role would -- so the policies'
 * FORCE is what holds it.
 *
 * @param \Closure(PdoConnection, MigrationFiles, \PDO): void $test
 */
function asApplicationRole(\Closure $test): void
{
    inSchema(static function (PdoConnection $connection, MigrationFiles $files, string $schema, \PDO $pdo) use ($test): void {
        $role = 'tinvar_mig_' . bin2hex(random_bytes(4));
        $pdo->exec("CREATE ROLE {$role} NOLOGIN");
        $pdo->exec("GRANT USAGE, CREATE ON SCHEMA {$schema} TO {$role}");
        $pdo->exec("SET ROLE {$role}");

        try {
            $test($connection, $files, $pdo);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // The connection is shared by every Postgres test in the run.
            $pdo->exec('RESET ROLE');
            $pdo->exec("SELECT set_config('app.tenant', '', false), set_config('app.across_tenants', 'off', false)");
            $pdo->exec("DROP OWNED BY {$role}");
            $pdo->exec("DROP ROLE {$role}");
        }
    });
}

const ACME = '0192f3a1-0000-7000-8000-00000000ac3e';

describe('the builder on Postgres', function () {
    it('makes every type it names, and reads them back', function () {
        inSchema(static function (PdoConnection $connection, MigrationFiles $files): void {
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
                            $t->column('search', 'tsvector')->nullable();
                            $t->uuid('parent_id')->references('parents')->onDelete('cascade');
                            $t->index(['parent_id', 'at DESC']);
                        });
                PHP);

            $migrator = new Migrator($connection, $files->directory);
            $migrator->migrate();
            $dialect = $migrator->dialect();

            expect(array_map(static fn (array $c): string => $c['type'], $dialect->columns($connection, 'everything')))->toBe([
                'id' => 'uuid', 'code' => 'character varying(32)', 'email' => 'citext', 'body' => 'text', 'n' => 'integer',
                'big' => 'bigint', 'flag' => 'boolean', 'ratio' => 'double precision', 'price' => 'numeric(8,2)',
                'meta' => 'jsonb', 'at' => 'timestamp with time zone', 'day' => 'date', 'blob' => 'bytea',
                'search' => 'tsvector', 'parent_id' => 'uuid',
            ])
                ->and($dialect->columns($connection, 'everything')['body']['nullable'])->toBeTrue()
                ->and($dialect->columns($connection, 'everything')['code']['nullable'])->toBeFalse()
                ->and(array_column($dialect->indexes($connection, 'everything'), 'columns', 'name'))->toMatchArray([
                    'everything_parent_id_at' => ['parent_id', 'at'],
                    'everything_pkey' => ['id'],
                ])
                ->and($dialect->tables($connection))->toBe(['everything', 'parents', 'tinvar_migrations'])
                ->and($dialect->hasTable($connection, 'everything'))->toBeTrue();
        });
    })->skip(fn () => Databases::postgresMissing() ?? false);

    // citext: one account, however its email is cased, to the constraint
    // and to the lookup alike -- what NOCASE is on SQLite.
    it('compares a case-insensitive column without regard to case', function () {
        inSchema(static function (PdoConnection $connection, MigrationFiles $files): void {
            $files->add('2026_10_24_000001_customers', "\$schema->create('customers', function (Table \$t): void { \$t->string('email')->caseInsensitive()->unique(); });");
            new Migrator($connection, $files->directory)->migrate();
            $connection->execute("INSERT INTO customers VALUES ('Ada@shop.test')");

            expect($connection->select("SELECT email FROM customers WHERE email = 'ada@SHOP.test'"))->toBe([['email' => 'Ada@shop.test']])
                ->and(fn () => $connection->execute("INSERT INTO customers VALUES ('ADA@shop.test')"))->toThrow(\PDOException::class, 'unique');
        });
    })->skip(fn () => Databases::postgresMissing() ?? false);
});

describe('a failure on Postgres', function () {
    it('takes back the failed migration\'s schema changes, and keeps the ones before it', function () {
        inSchema(static function (PdoConnection $connection, MigrationFiles $files): void {
            $files->add('2026_10_24_000001_create_a', "\$schema->create('a', function (Table \$t): void { \$t->uuid('id')->primary(); });");
            $files->add('2026_10_24_000002_create_b_then_fail', "\$schema->create('b', function (Table \$t): void { \$t->uuid('id'); }); \$schema->sql('SELECT * FROM nowhere');");
            $migrator = new Migrator($connection, $files->directory);

            expect(fn () => $migrator->migrate())->toThrow(MigrationFailed::class, 'Nothing it did was kept')
                ->and($migrator->dialect()->tables($connection))->toBe(['a', 'tinvar_migrations'])
                ->and($migrator->pending())->toBe(['2026_10_24_000002_create_b_then_fail'])
                // Not left in an aborted transaction: the next statement runs.
                ->and($connection->inTransaction())->toBeFalse()
                ->and($connection->select('SELECT 1 AS one'))->toBe([['one' => 1]]);
        });
    })->skip(fn () => Databases::postgresMissing() ?? false);
});

describe('the advisory lock', function () {
    it('keeps a second run out while another session holds it, for this schema only', function () {
        inSchema(static function (PdoConnection $connection, MigrationFiles $files, string $schema): void {
            $files->add('2026_10_24_000001_create_a', "\$schema->create('a', function (Table \$t): void { \$t->uuid('id')->primary(); });");
            $other = Databases::another('pgsql');
            $other->exec("SET search_path TO {$schema}");
            $other->query('SELECT pg_advisory_lock(' . PostgresDialect::LOCK_KEY . ", hashtext('{$schema}'))");

            try {
                expect(fn () => new Migrator($connection, $files->directory, lockTimeout: 0.2)->migrate())
                    ->toThrow(MigrationRefused::class, 'Another migration has held the Postgres advisory lock for 0.2s')
                    ->and(new PostgresDialect()->hasTable($connection, 'a'))->toBeFalse();

                // Another schema's migrations do not wait on this one's.
                $elsewhere = "{$schema}_b";
                $connection->execute("CREATE SCHEMA {$elsewhere}");
                $connection->execute("SET search_path TO {$elsewhere}");
                try {
                    expect(new Migrator($connection, $files->directory, lockTimeout: 0)->migrate())->toBe(['2026_10_24_000001_create_a']);
                } finally {
                    $connection->execute("SET search_path TO {$schema}, public");
                    $connection->execute("DROP SCHEMA {$elsewhere} CASCADE");
                }
            } finally {
                $other->query('SELECT pg_advisory_unlock_all()');
            }

            expect(new Migrator($connection, $files->directory, lockTimeout: 0)->migrate())->toBe(['2026_10_24_000001_create_a'])
                // Let go once the run ends: another session can take it at once.
                ->and($other->query('SELECT pg_try_advisory_lock(' . PostgresDialect::LOCK_KEY . ", hashtext('{$schema}'))")?->fetchColumn())->toBeTrue();
            $other->query('SELECT pg_advisory_unlock_all()');
        });
    })->skip(fn () => Databases::postgresMissing() ?? false);
});

describe('row-level security by migration', function () {
    it('holds a tenant table to the policy from the migration that makes it, as the role that owns it', function () {
        asApplicationRole(static function (PdoConnection $connection, MigrationFiles $files): void {
            $files->add('2026_10_24_000001_create_scripts', "\$schema->create('scripts', function (Table \$t): void { \$t->uuid('id')->primary(); \$t->string('name'); \$t->tenant(); });");
            // The rows go in from a migration, across tenants, as a backfill's
            // would have to: under FORCE the owning role is held too.
            $files->add('2026_10_24_000002_backfill_scripts', <<<'PHP'
                $schema->acrossTenants(static function () use ($schema): void {
                            foreach (['acme', 'globex'] as $tenant) {
                                $schema->sql("INSERT INTO scripts VALUES (gen_random_uuid(), ?, ?)", ["{$tenant}-script", $tenant]);
                            }
                        });
                PHP);
            $migrator = new Migrator($connection, $files->directory);
            $migrator->migrate();
            $orm = new Orm($connection, new IdentityMap('pgsql'), tenants: Tenancy::flat());

            expect($migrator->dialect()->hasTenantPolicy($connection, 'scripts'))->toBeTrue()
                // With no tenant, no row may be written by the owner either.
                ->and(fn () => $connection->execute("INSERT INTO scripts VALUES (gen_random_uuid(), 'nobody''s', 'acme')"))
                ->toThrow(\PDOException::class, 'row-level security')
                // A raw query that forgot the predicate: the tenant's rows, or none.
                ->and($orm->actAs('acme', static fn (): array => $connection->select('SELECT name FROM scripts')))->toBe([['name' => 'acme-script']])
                ->and($connection->select('SELECT name FROM scripts'))->toBe([])
                ->and($orm->acrossTenants(static fn (): int => \count($connection->select('SELECT name FROM scripts'))))->toBe(2)
                // And the ORM's own reads, through the same policy.
                ->and($orm->actAs('globex', static fn (): array => array_map(static fn (Script $s): string => $s->name, $orm->all(Script::class))))->toBe(['globex-script']);
        });
    })->skip(fn () => Databases::postgresMissing() ?? false);

    it('puts the policy on a table that has its tenant column already, cast to the column\'s type, and takes it off', function () {
        asApplicationRole(static function (PdoConnection $connection, MigrationFiles $files): void {
            $connection->execute('CREATE TABLE runs (id uuid PRIMARY KEY, tenant_id uuid NOT NULL)');
            $connection->execute("INSERT INTO runs VALUES (gen_random_uuid(), '" . ACME . "'), (gen_random_uuid(), gen_random_uuid())");
            $files->add('2026_10_24_000001_hold_runs', "\$schema->tenantPolicy('runs');", "\$schema->dropTenantPolicy('runs');");
            $migrator = new Migrator($connection, $files->directory);
            $migrator->migrate();
            $orm = new Orm($connection, new IdentityMap('pgsql'), tenants: Tenancy::flat());

            expect($orm->actAs(ACME, static fn (): array => $connection->select('SELECT tenant_id FROM runs')))->toBe([['tenant_id' => ACME]])
                ->and($connection->select('SELECT tenant_id FROM runs'))->toBe([])
                ->and($connection->select("SELECT pg_get_expr(polqual, polrelid) AS q FROM pg_policy WHERE polname = 'tinvar_tenant' AND polrelid = 'runs'::regclass")[0]['q'])->toContain('::uuid');

            $migrator->rollback();

            expect($migrator->dialect()->hasTenantPolicy($connection, 'runs'))->toBeFalse()
                ->and($connection->select('SELECT tenant_id FROM runs'))->toHaveCount(2);
        });
    })->skip(fn () => Databases::postgresMissing() ?? false);

    // make:migration's half: a #[TenantScoped] table the policy does not
    // hold is a difference from the models, as a missing column is.
    it('is what make:migration writes for a tenant table without it, and what status warns of', function () {
        asApplicationRole(static function (PdoConnection $connection, MigrationFiles $files): void {
            $connection->execute('CREATE TABLE scripts (id uuid PRIMARY KEY, name varchar(255) NOT NULL, tenant_id varchar(255) NOT NULL)');
            $connection->execute('CREATE INDEX scripts_tenant_id ON scripts (tenant_id)');
            $generator = new MigrationGenerator($connection, new PostgresDialect());
            $err = [];
            $status = new MigrateCommand(
                static fn (): PdoConnection => $connection,
                $files->directory,
                [Script::class],
                static function (string $line): void {},
                static function (string $line) use (&$err): void {
                    $err[] = $line;
                },
            );

            $source = (string) $generator->generate([Script::class], '2026-10-24');
            $status->run('migrate:status', []);

            expect($source)->toContain("\$schema->tenantPolicy('scripts', 'tenant_id');")
                ->and($source)->toContain("\$schema->dropTenantPolicy('scripts');")
                ->and($err)->toBe(['warning: scripts is #[TenantScoped] and has no row-level security policy; `tinvar make:migration` writes the migration that adds it.']);

            file_put_contents("{$files->directory}/2026_10_24_000001_hold_scripts.php", $source);
            new Migrator($connection, $files->directory)->migrate();
            $err = [];
            $status->run('migrate:status', []);

            expect($generator->generate([Script::class], '2026-10-24'))->toBeNull()
                ->and($err)->toBe([]);
        });
    })->skip(fn () => Databases::postgresMissing() ?? false);
});

describe('make:migration on Postgres', function () {
    it('changes a column the model has changed, converting its values, and changes it back', function () {
        inSchema(static function (PdoConnection $connection, MigrationFiles $files): void {
            $connection->execute('CREATE TABLE sites (id uuid PRIMARY KEY, code text)');
            $connection->execute(
                'CREATE TABLE racks (id uuid PRIMARY KEY, name varchar(100) NOT NULL, height_u text NOT NULL, '
                . 'site_id uuid NOT NULL REFERENCES sites (id))',
            );
            $connection->execute('CREATE INDEX racks_site_id_name ON racks (site_id, name)');
            $connection->execute(
                'CREATE TABLE units (id uuid PRIMARY KEY, position integer NOT NULL, name text NOT NULL, rack_id uuid NOT NULL REFERENCES racks (id))',
            );
            $connection->execute('CREATE INDEX units_rack_id_position ON units (rack_id, position)');
            $connection->execute("INSERT INTO sites VALUES ('00000000-0000-7000-8000-000000000001', 'LAX')");
            $connection->execute("INSERT INTO racks VALUES ('00000000-0000-7000-8000-000000000002', 'R-01', '42', '00000000-0000-7000-8000-000000000001')");
            $generator = new MigrationGenerator($connection, new PostgresDialect());
            $models = [\Tinvar\Tests\Orm\Fixtures\Site::class, \Tinvar\Tests\Orm\Fixtures\Rack::class, \Tinvar\Tests\Orm\Fixtures\Unit::class];

            $source = (string) $generator->generate($models, '2026-10-24');

            expect($source)->toContain("\$table->text('code')->change();")
                ->and($source)->toContain("\$table->text('code')->nullable()->change();")
                ->and($source)->toContain("\$table->integer('height_u')->change();")
                ->and($source)->toContain("\$table->text('height_u')->change();")
                // A varchar(100) holds a string as well as a varchar(255) does.
                ->and($source)->not->toContain("'name')->");

            file_put_contents("{$files->directory}/2026_10_24_000001_change.php", $source);
            $migrator = new Migrator($connection, $files->directory);
            $migrator->migrate();

            expect($connection->select('SELECT height_u FROM racks'))->toBe([['height_u' => 42]])
                ->and($generator->generate($models, '2026-10-24'))->toBeNull()
                ->and($migrator->rollback())->toBe(['2026_10_24_000001_change'])
                ->and($connection->select('SELECT height_u FROM racks'))->toBe([['height_u' => '42']]);
        });
    })->skip(fn () => Databases::postgresMissing() ?? false);
});

describe('fresh on Postgres', function () {
    it('drops every table in the schema, whatever references what, and no other schema\'s', function () {
        inSchema(static function (PdoConnection $connection, MigrationFiles $files, string $schema): void {
            $files->add('2026_10_24_000001_create_a', "\$schema->create('a', function (Table \$t): void { \$t->uuid('id')->primary(); });");
            $connection->execute('CREATE TABLE stray (a_id uuid)');
            // Views that depend on no table, which no CASCADE would reach.
            $connection->execute('CREATE VIEW answer AS SELECT 42 AS n');
            $connection->execute('CREATE MATERIALIZED VIEW answers AS SELECT 42 AS n');
            $connection->execute("CREATE SCHEMA {$schema}_other");
            $connection->execute("CREATE TABLE {$schema}_other.kept (x integer)");

            try {
                $ran = new Migrator($connection, $files->directory)->fresh();

                expect($ran)->toBe(['2026_10_24_000001_create_a'])
                    ->and(new PostgresDialect()->tables($connection))->toBe(['a', 'tinvar_migrations'])
                    ->and($connection->select("SELECT to_regclass('answer') IS NULL AND to_regclass('answers') IS NULL AS gone"))->toBe([['gone' => true]])
                    ->and($connection->select("SELECT to_regclass('{$schema}_other.kept') IS NOT NULL AS kept"))->toBe([['kept' => true]]);
            } finally {
                $connection->execute("DROP SCHEMA {$schema}_other CASCADE");
            }
        });
    })->skip(fn () => Databases::postgresMissing() ?? false);
});
