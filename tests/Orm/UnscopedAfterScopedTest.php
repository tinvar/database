<?php

declare(strict_types=1);

/**
 * A relation already loaded under scope, read again inside acrossTenants().
 *
 * loadRelationRows() skipped every group the scoped cache held, and an
 * unscoped read takes its answer from the load alone, never the cache -- so
 * a group cached under scope came back as nothing: an empty #[HasMany], or a
 * DanglingRelation for a #[BelongsTo] whose row exists.
 */

namespace Tinvar\Tests\Orm\UnscopedAfterScoped;

use Tinvar\Orm\Attributes\BelongsTo;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\TenantScoped;
use Tinvar\Orm\DanglingRelation;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Support\Uuid;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Tests\Orm\Fixtures\TenantFixture;

require_once __DIR__ . '/Fixtures/models.php';
require_once __DIR__ . '/Fixtures/schema.php';
require_once __DIR__ . '/Fixtures/tenant.php';
require_once __DIR__ . '/Fixtures/tenancy.php';

#[Table('owners'), TenantScoped]
final class Owner extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;
}

/** Not tenant-scoped, pointing at a row that is. */
#[Table('pets')]
final class Pet extends Model
{
    #[Id] public private(set) string $id;
    #[BelongsTo] public Owner $owner;
}

/** @return array{Orm, string} acting as acme, and a pet whose owner is globex's */
function petOfAnotherTenant(): array
{
    $pdo = new \PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE owners (id TEXT PRIMARY KEY, name TEXT NOT NULL, tenant_id TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE pets (id TEXT PRIMARY KEY, owner_id TEXT NOT NULL)');
    $owner = Uuid::v7();
    $pet = Uuid::v7();
    $pdo->prepare('INSERT INTO owners VALUES (?, ?, ?)')->execute([$owner, 'globex-owner', 'globex']);
    $pdo->prepare('INSERT INTO pets VALUES (?, ?)')->execute([$pet, $owner]);

    $orm = new Orm(new PdoConnection($pdo), new IdentityMap('test'), tenants: Tenancy::flat());
    $orm->switchTenant('acme');

    return [$orm, $pet];
}

it('reads a hasMany inside acrossTenants that a scoped read already loaded', function () {
    $fixture = new TenantFixture();
    $orm = new Orm($fixture->connection, new IdentityMap('tenant-test'), tenants: Tenancy::flat());
    $orm->switchTenant('acme');
    $id = $fixture->scripts['acme'][0];

    expect($orm->find(Script::class, $id)?->runs->count())->toBe(1);

    $unscoped = $orm->acrossTenants(static fn (): int => $orm->find(Script::class, $id)?->runs->count() ?? -1);

    expect($unscoped)->toBe(1);
});

it('reads a belongsTo inside acrossTenants that a scoped read already loaded', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 0);
    $orm = new Orm($fixture->connection, new IdentityMap('test'), tenants: Tenancy::flat());
    $id = $fixture->ids['racks'][0];

    expect($orm->find(Rack::class, $id)?->site->code)->toBe('LAX0');

    $unscoped = $orm->acrossTenants(static fn (): string => $orm->find(Rack::class, $id)?->site->code ?? 'none');

    expect($unscoped)->toBe('LAX0');
});

// A ghost belongs to the scope its parent was read in. Retried inside
// acrossTenants() or as another tenant, it used to find the other tenant's
// row -- and keep it after the block ended, in scoped code that would have
// been refused it. It is still retried (the miss is not cached as final), but
// under its own scope, so it stays refused.
it('keeps refusing a belongsTo the scope hid, inside acrossTenants or as another tenant', function () {
    [$orm, $petId] = petOfAnotherTenant();
    $pet = $orm->find(Pet::class, $petId) ?? throw new \LogicException('no pet');

    expect(static fn (): string => $pet->owner->name)->toThrow(DanglingRelation::class);
    expect(static fn (): string => $orm->acrossTenants(static fn (): string => $pet->owner->name))
        ->toThrow(DanglingRelation::class);
    expect(static fn (): string => $orm->actAs('globex', static fn (): string => $pet->owner->name))
        ->toThrow(DanglingRelation::class);
});

// What acrossTenants() is for: a parent read inside it has ghosts that read
// across tenants too.
it('finds that belongsTo when the parent is read inside acrossTenants', function () {
    [$orm, $petId] = petOfAnotherTenant();

    $name = $orm->acrossTenants(static fn (): string => $orm->find(Pet::class, $petId)?->owner->name ?? 'none');

    expect($name)->toBe('globex-owner');
});
