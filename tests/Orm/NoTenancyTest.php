<?php

declare(strict_types=1);

/**
 * An Orm no tenancy was registered with reads and writes no tenant's rows.
 *
 * Tenancy is a package an application opts into (ADR 0056). One that never
 * did -- tinvar/tenancy not installed, or installed and never handed to the
 * Orm -- may still mark a model #[TenantScoped], by a copied model or a
 * forgotten migration, and that model must fail closed: no tenant in force
 * is one anybody vouched for, and acrossTenants() has nothing to lift. The
 * database's half follows: raw SQL around such an Orm is told no tenant, and
 * is never lifted.
 */

namespace Tinvar\Tests\Orm\NoTenancy;

use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\NoTenantContext;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Tests\Orm\Fixtures\TenantFixture;

use function Tinvar\Tests\Orm\Fixtures\withPolicies;

require_once __DIR__ . '/Fixtures/models.php';
require_once __DIR__ . '/Fixtures/schema.php';
require_once __DIR__ . '/Fixtures/tenant.php';
require_once __DIR__ . '/Fixtures/policies.php';

function bare(TenantFixture $fixture): Orm
{
    return new Orm($fixture->connection, new IdentityMap('no-tenancy'));
}

describe('with no tenancy registered', function () {
    it('says so', function () {
        $fixture = new TenantFixture();

        expect(bare($fixture)->hasTenancy())->toBeFalse()
            ->and(bare($fixture)->tenants())->toBeNull()
            ->and(new Orm($fixture->connection, tenants: Tenancy::flat())->hasTenancy())->toBeTrue();
    });

    it('reads no tenant-scoped row with no tenant, and none as a tenant either', function () {
        $fixture = new TenantFixture();
        $orm = bare($fixture);
        $id = $fixture->scripts['acme'][0];

        expect(fn () => $orm->all(Script::class))->toThrow(NoTenantContext::class, 'no tenancy is registered')
            ->and(fn () => $orm->actAs('acme', static fn () => $orm->all(Script::class)))->toThrow(NoTenantContext::class, 'no tenancy is registered')
            ->and(fn () => $orm->actAs('acme', static fn () => $orm->find(Script::class, $id)))->toThrow(NoTenantContext::class, 'no tenancy is registered')
            ->and(fn () => $orm->actAs('acme', static fn () => $orm->whereIn(Script::class, 'id', [$id])))->toThrow(NoTenantContext::class, 'no tenancy is registered');
    });

    it('lifts nothing inside acrossTenants(), which has no intent to state', function () {
        $orm = bare(new TenantFixture());

        expect(fn () => $orm->acrossTenants(static fn () => $orm->all(Script::class)))->toThrow(NoTenantContext::class, 'no tenancy is registered')
            ->and(fn () => $orm->actAs('acme', static fn () => $orm->acrossTenants(static fn () => $orm->all(Script::class))))->toThrow(NoTenantContext::class);
    });

    it('writes no tenant-scoped row, and changes and deletes none', function () {
        $fixture = new TenantFixture();
        $registered = new Orm($fixture->connection, new IdentityMap('registered'), Tenancy::flat());
        $script = $registered->actAs('acme', static fn (): Script => $registered->all(Script::class)[0]);
        $orm = bare($fixture);

        $new = new Script();
        $new->name = 'written with no tenancy';
        $script->name = 'renamed with no tenancy';

        expect(fn () => $orm->actAs('acme', static fn () => $orm->save($new)))->toThrow(NoTenantContext::class, 'no tenancy is registered')
            ->and(fn () => $orm->actAs('acme', static fn () => $orm->save($script)))->toThrow(NoTenantContext::class, 'no tenancy is registered')
            ->and(fn () => $orm->actAs('acme', static fn () => $orm->delete($script)))->toThrow(NoTenantContext::class, 'no tenancy is registered')
            ->and($fixture->connection->select("SELECT name FROM scripts WHERE name LIKE '%no tenancy'"))->toBe([]);
    });

    it('reads across no subtree, granted or not', function () {
        $orm = bare(new TenantFixture());

        expect(fn () => $orm->actAs('acme', static fn () => $orm->withinSubtree(static fn () => null)))->toThrow(\LogicException::class)
            ->and(fn () => $orm->actAs('acme', static fn () => $orm->grantSubtree(static fn () => $orm->withinSubtree(static fn () => $orm->all(Script::class)))))
            ->toThrow(\LogicException::class);
    });

    it('leaves models with no tenant column as they were', function () {
        $orm = new Orm(new Fixture(racks: 2)->connection);

        expect($orm->all(Rack::class))->toHaveCount(2)
            ->and($orm->actAs('acme', static fn (): array => $orm->all(Rack::class)))->toHaveCount(2);
    });

    // The second layer, for SQL that went around the ORM: told no tenant,
    // and never lifted, whatever the Orm's own context says.
    it('tells Postgres no tenant, even as a tenant and inside acrossTenants()', function () {
        withPolicies(function (PdoConnection $connection): void {
            $orm = new Orm($connection, new IdentityMap('no-tenancy'));
            $raw = static fn (): array => $connection->select('SELECT name FROM scripts');

            expect($orm->actAs('acme', $raw))->toBe([])
                ->and($orm->acrossTenants($raw))->toBe([])
                ->and($orm->actAs('acme', static fn (): array => $orm->acrossTenants($raw)))->toBe([]);
        });
    })->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');
});

describe('a tenant\'s name', function () {
    // A control character is how a token minted with no tenancy says so: no
    // tenant may be spelled with one, and Postgres could not be told NUL.
    it('has no control character in it', function () {
        $orm = new Orm((new TenantFixture())->connection, tenants: Tenancy::flat());

        foreach (["acme\0", "\x7fnone", "a\nb", "\x1f"] as $name) {
            expect(fn () => $orm->actAs($name, static fn () => null))->toThrow(\InvalidArgumentException::class, 'control character')
                ->and(fn () => $orm->switchTenant($name))->toThrow(\InvalidArgumentException::class, 'control character');
        }

        expect($orm->tenant())->toBe('')
            ->and($orm->actAs('acme', static fn (): string => $orm->tenant()))->toBe('acme');
    });
});

describe('an actAs() refused for its tenant\'s name', function () {
    // Refused before it changes anything: the scope around it -- an
    // acrossTenants(), a subtree read -- goes on exactly as it was, rather
    // than narrowed, or widened, for the rest of the work it encloses.
    it('leaves the scope around it as it was', function () {
        $orm = new Orm((new TenantFixture())->connection, tenants: Tenancy::flat());

        $after = $orm->actAs('acme', static fn (): array => $orm->acrossTenants(static function () use ($orm): array {
            try {
                $orm->actAs("acme\n", static fn () => null);
            } catch (\InvalidArgumentException) {
            }

            return [$orm->tenant(), $orm->isUnscoped()];
        }));

        expect($after)->toBe(['acme', true]);
    });
});
