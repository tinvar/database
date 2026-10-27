<?php

declare(strict_types=1);

/**
 * Postgres row-level security behind the ORM's tenant filter.
 *
 * "Row-level security on by default in the shared tier. PHP sets the tenant
 * as a session variable on each connection; with none set, policies match
 * no rows." The ORM fails closed already; these are about the query that
 * went around it and forgot the predicate. The Postgres group is skipped,
 * saying why, without a server (tests/Orm/Fixtures/databases.php says
 * how one is found); its policies are held to a role that is not a
 * superuser, since a superuser bypasses every policy there is.
 */

namespace Tinvar\Tests\Orm\RowLevelSecurity;

use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\NoTenantContext;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\RowLevelSecurity;
use Tinvar\Support\Uuid;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Orm\Fixtures\Tenancy;

use function Tinvar\Tests\Orm\Fixtures\withPolicies;

require_once __DIR__ . '/Fixtures/models.php';
require_once __DIR__ . '/Fixtures/tenant.php';
require_once __DIR__ . '/Fixtures/databases.php';
require_once __DIR__ . '/Fixtures/policies.php';
require_once __DIR__ . '/Fixtures/tenancy.php';

describe('the policy', function () {
    it('is enabled, forced on the table owner, and matches the tenant setting -- or nothing', function () {
        $statements = RowLevelSecurity::policy('scripts', 'tenant_id', 'uuid');

        expect($statements)->toBe([
            'ALTER TABLE "scripts" ENABLE ROW LEVEL SECURITY',
            'ALTER TABLE "scripts" FORCE ROW LEVEL SECURITY',
            'DROP POLICY IF EXISTS tinvar_tenant ON "scripts"',
            'CREATE POLICY tinvar_tenant ON "scripts" USING ('
                . "current_setting('app.across_tenants', true) = 'on' OR \"tenant_id\" = NULLIF(current_setting('app.tenant', true), '')::uuid"
                . " OR \"tenant_id\" = ANY (NULLIF(current_setting('app.subtree', true), '')::uuid[])"
                . ') WITH CHECK ('
                . "current_setting('app.across_tenants', true) = 'on' OR \"tenant_id\" = NULLIF(current_setting('app.tenant', true), '')::uuid"
                . " OR \"tenant_id\" = ANY (NULLIF(current_setting('app.subtree', true), '')::uuid[])"
                . ')',
        ]);
    });

    // Quoted element by element, so an id holding a comma, a brace or a
    // quote is one tenant in the array and not several, or a parse error.
    it('writes a subtree as an array literal with every tenant quoted', function () {
        expect(RowLevelSecurity::arrayLiteral([]))->toBe('')
            ->and(RowLevelSecurity::arrayLiteral(['acme', 'a,"b}', 'c\\d']))->toBe('{"acme","a,\\"b}","c\\\\d"}');
    });

    it('reads the table and tenant column off a #[TenantScoped] model', function () {
        expect(RowLevelSecurity::forModel(Script::class)[0])->toBe('ALTER TABLE "scripts" ENABLE ROW LEVEL SECURITY')
            ->and(RowLevelSecurity::policy('app.scripts')[0])->toBe('ALTER TABLE "app"."scripts" ENABLE ROW LEVEL SECURITY');
    });

    it('refuses a model with no tenant column, and names or types that are not plain', function () {
        expect(fn () => RowLevelSecurity::forModel(Rack::class))->toThrow(\InvalidArgumentException::class, 'not #[TenantScoped]')
            ->and(fn () => RowLevelSecurity::policy('scripts; DROP TABLE x'))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => RowLevelSecurity::policy('scripts', 'tenant"id'))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => RowLevelSecurity::policy('scripts', 'tenant_id', 'uuid); DROP'))->toThrow(\InvalidArgumentException::class);
    });

    // SQLite has no policies to read a setting: nothing is sent to it.
    it('sets nothing on a connection other than Postgres', function () {
        $connection = new PdoConnection(new \PDO('sqlite::memory:'));
        $orm = new Orm($connection, new IdentityMap('test'), tenants: Tenancy::flat());

        $orm->actAs('acme', static fn (): array => $connection->select('SELECT 1'));

        expect($connection->statementLog())->toBe(['SELECT 1']);
    });
});

/** @return list<string> the names a raw query with no tenant predicate reads */
function rawNames(PdoConnection $connection): array
{
    return array_column($connection->select('SELECT name FROM scripts ORDER BY name COLLATE "C"'), 'name');
}

describe('on Postgres', function () {
    it('lets a query that forgot the tenant predicate read only the tenant in force', function () {
        withPolicies(function (PdoConnection $connection): void {
            $orm = new Orm($connection, new IdentityMap('test'), tenants: Tenancy::flat());

            expect($orm->actAs('acme', static fn (): array => rawNames($connection)))->toBe(['acme-script-1', 'acme-script-2'])
                ->and($orm->actAs('globex', static fn (): array => rawNames($connection)))->toBe(['globex-script-1', 'globex-script-2']);
        });
    });

    it('lets it read nothing with no tenant, where the ORM itself fails closed', function () {
        withPolicies(function (PdoConnection $connection): void {
            $orm = new Orm($connection, new IdentityMap('test'), tenants: Tenancy::flat());

            expect(rawNames($connection))->toBe([])
                ->and(fn () => $orm->all(Script::class))->toThrow(NoTenantContext::class);
        });
    });

    it('lifts the policy inside acrossTenants(), as the ORM lifts its filter, and puts it back after', function () {
        withPolicies(function (PdoConnection $connection): void {
            $orm = new Orm($connection, new IdentityMap('test'), tenants: Tenancy::flat());

            expect($orm->acrossTenants(static fn (): array => rawNames($connection)))->toHaveCount(4)
                ->and(rawNames($connection))->toBe([]);
        });
    });

    // withinSubtree() widens the database's half with the ORM's: a query
    // that forgot the predicate reads the subtree, and only the subtree,
    // while it is in force -- and the tenant alone again after.
    it('widens the policy to the subtree inside withinSubtree(), and to the tenant alone after', function () {
        withPolicies(function (PdoConnection $connection): void {
            $orm = new Orm($connection, new IdentityMap('test'), Tenancy::tree(['globex' => 'acme']));
            $across = static fn (string $tenant): array => $orm->actAs($tenant, static fn (): array => $orm->grantSubtree(
                static fn (): array => $orm->withinSubtree(static fn (): array => rawNames($connection)),
            ));

            expect($across('acme'))->toBe(['acme-script-1', 'acme-script-2', 'globex-script-1', 'globex-script-2'])
                ->and($across('globex'))->toBe(['globex-script-1', 'globex-script-2'])
                ->and($orm->actAs('acme', static fn (): array => rawNames($connection)))->toBe(['acme-script-1', 'acme-script-2'])
                ->and(rawNames($connection))->toBe([]);
        });
    });

    it('lets a raw write inside the subtree, and refuses one outside it', function () {
        withPolicies(function (PdoConnection $connection): void {
            $orm = new Orm($connection, new IdentityMap('test'), Tenancy::tree(['globex' => 'acme']));
            $write = static fn (string $tenant): int => $orm->actAs('acme', static fn (): int => $orm->grantSubtree(
                static fn (): int => $orm->withinSubtree(static fn (): int => $connection->execute(
                    'INSERT INTO scripts VALUES (?, ?, ?)',
                    [Uuid::v7(), "for-{$tenant}", $tenant],
                )),
            ));

            expect($write('globex'))->toBe(1)
                ->and(fn () => $write('initech'))->toThrow(\PDOException::class, 'row-level security');
        });
    });

    // The setting is session-wide, so a raw handle used inside the scope
    // sees it too -- and once the request is over, the reset contract takes
    // it back at once, not at the next statement.
    it('holds a raw PDO handle to it while the tenant is in force, and to nothing after the reset', function () {
        withPolicies(function (PdoConnection $connection, \PDO $pdo): void {
            $orm = new Orm($connection, new IdentityMap('test'), tenants: Tenancy::flat());
            $raw = static fn (): int => \count($pdo->query('SELECT name FROM scripts')->fetchAll());

            $inside = $orm->actAs('acme', static function () use ($connection, $raw): int {
                $connection->select('SELECT 1');

                return $raw();
            });
            $orm->flush();

            expect($inside)->toBe(2)->and($raw())->toBe(0);
        });
    });

    // The tenant is fiber-local and a render's fibers share the connection:
    // the setting follows whichever fiber runs the statement.
    it('follows the fiber running each statement, not the last one to switch', function () {
        withPolicies(function (PdoConnection $connection): void {
            $orm = new Orm($connection, new IdentityMap('test'), tenants: Tenancy::flat());
            /** @var \ArrayObject<string, list<string>> $seen */
            $seen = new \ArrayObject();

            $fiber = static fn (string $tenant): \Fiber => new \Fiber(static function () use ($orm, $connection, $tenant, $seen): void {
                $orm->actAs($tenant, static function () use ($connection, $tenant, $seen): void {
                    \Fiber::suspend();
                    $seen[$tenant] = rawNames($connection);
                });
            });

            $acme = $fiber('acme');
            $globex = $fiber('globex');
            $acme->start();
            $globex->start();
            $acme->resume();
            $globex->resume();

            expect($seen->getArrayCopy())->toBe([
                'acme' => ['acme-script-1', 'acme-script-2'],
                'globex' => ['globex-script-1', 'globex-script-2'],
            ]);
        });
    });

    it('holds inside the read-only transaction loaders run in', function () {
        withPolicies(function (PdoConnection $connection): void {
            $orm = new Orm($connection, new IdentityMap('test'), tenants: Tenancy::flat());

            $names = $orm->actAs('globex', static fn (): array => $orm->readOnly(static fn (): array => rawNames($connection)));

            expect($names)->toBe(['globex-script-1', 'globex-script-2']);
        });
    });

    // Postgres takes back a set_config() made in a transaction that rolls
    // back. Believing the session still held the tenant afterwards, the
    // connection would not tell it again, and the policy would read none.
    it('tells the session again after a rollback took the setting back', function () {
        withPolicies(function (PdoConnection $connection): void {
            $orm = new Orm($connection, new IdentityMap('test'), tenants: Tenancy::flat());

            $names = $orm->actAs('acme', static function () use ($connection): array {
                try {
                    $connection->transaction(static function () use ($connection): never {
                        $connection->select('SELECT 1');

                        throw new \RuntimeException('rolled back');
                    });
                } catch (\RuntimeException) {
                }

                return rawNames($connection);
            });

            expect($names)->toBe(['acme-script-1', 'acme-script-2']);
        });
    });

    // A COMMIT Postgres refuses rolls the transaction back, and a setting
    // made inside it with the rest, leaving no transaction open behind it.
    // Believing the session held acme, the connection never told it again,
    // and a query that forgot the predicate read the tenant before: here
    // globex's rows, for a job that went on as acme after the failure.
    it('tells the session again after a refused commit took the setting back', function () {
        withPolicies(function (PdoConnection $connection): void {
            $orm = new Orm($connection, new IdentityMap('test'), tenants: Tenancy::flat());
            $orm->actAs('globex', static fn (): array => rawNames($connection));

            $names = $orm->actAs('acme', static function () use ($orm, $connection): array {
                try {
                    $orm->transaction(static fn (): int => $connection->execute('INSERT INTO script_tags VALUES (?)', [Uuid::v7()]));
                } catch (\PDOException) {
                    // the deferred key, refused at the commit
                }

                return rawNames($connection);
            });

            expect($names)->toBe(['acme-script-1', 'acme-script-2']);
        });
    });

    // The same for a transaction a caught failure aborted, whose COMMIT
    // rolled back while PDO reported it committed: the setting went with it.
    it('tells the session again after a failed statement aborted the transaction', function () {
        withPolicies(function (PdoConnection $connection): void {
            $orm = new Orm($connection, new IdentityMap('test'), tenants: Tenancy::flat());
            $orm->actAs('globex', static fn (): array => rawNames($connection));

            $names = $orm->actAs('acme', static function () use ($orm, $connection): array {
                try {
                    $orm->transaction(static function () use ($connection): void {
                        $connection->select('SELECT 1');
                        try {
                            $connection->select("SELECT 'not-a-uuid'::uuid");
                        } catch (\PDOException) {
                        }
                    });
                } catch (\PDOException) {
                    // rolled back, not committed
                }

                return rawNames($connection);
            });

            expect($names)->toBe(['acme-script-1', 'acme-script-2']);
        });
    });

    it('refuses a raw write of another tenant\'s row', function () {
        withPolicies(function (PdoConnection $connection): void {
            $orm = new Orm($connection, new IdentityMap('test'), tenants: Tenancy::flat());

            expect(fn () => $orm->actAs('acme', static fn (): int => $connection->execute(
                'INSERT INTO scripts VALUES (?, ?, ?)',
                [Uuid::v7(), 'planted', 'globex'],
            )))->toThrow(\PDOException::class, 'row-level security')
                ->and($orm->actAs('acme', static fn (): int => $connection->execute("UPDATE scripts SET name = 'renamed'")))->toBe(2);
        });
    });
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');
