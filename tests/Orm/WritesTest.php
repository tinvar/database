<?php

declare(strict_types=1);

use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\NoTenantContext;
use Tinvar\Orm\Orm;
use Tinvar\Support\Uuid;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Tests\Orm\Fixtures\Site;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Tests\Orm\Fixtures\TenantFixture;

require_once __DIR__ . '/Fixtures/models.php';
require_once __DIR__ . '/Fixtures/schema.php';
require_once __DIR__ . '/Fixtures/tenant.php';
require_once __DIR__ . '/Fixtures/tenancy.php';

function writeOrm(Fixture $fixture): Orm
{
    return new Orm($fixture->connection, new IdentityMap('test'), tenants: Tenancy::flat());
}

function newRack(Orm $orm, Site $site, string $name): Rack
{
    $rack = (new ReflectionClass(Rack::class))->newInstanceWithoutConstructor();
    $meta = Rack::meta();
    $meta->set($rack, 'name', $name);
    $meta->set($rack, 'heightU', 42);
    $meta->set($rack, 'site', $site);

    return $rack;
}

describe('insert', function () {
    it('mints a UUIDv7 key and writes the row', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        $orm = writeOrm($fixture);
        $site = $orm->find(Site::class, $fixture->ids['sites'][0]);

        $rack = $orm->save(newRack($orm, $site, 'R-NEW'));

        expect(Uuid::isValid($rack->primaryKey()))->toBeTrue()
            ->and($rack->isPersisted())->toBeTrue()
            ->and($rack->isDirty())->toBeFalse();

        // Readable back through a fresh map, so it really is in the database.
        $reread = (new Orm($fixture->connection, new IdentityMap('fresh'), tenants: Tenancy::flat()))
            ->find(Rack::class, $rack->primaryKey());

        expect($reread->name)->toBe('R-NEW')->and($reread->heightU)->toBe(42);
    });

    it("writes a single relation's foreign key", function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        $orm = writeOrm($fixture);
        $site = $orm->find(Site::class, $fixture->ids['sites'][0]);

        $rack = $orm->save(newRack($orm, $site, 'R-LINKED'));

        $reread = (new Orm($fixture->connection, new IdentityMap('fresh'), tenants: Tenancy::flat()))
            ->find(Rack::class, $rack->primaryKey());

        expect($reread->site->primaryKey())->toBe($site->primaryKey());
    });

    it('puts the new row in the identity map', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        $orm = writeOrm($fixture);
        $site = $orm->find(Site::class, $fixture->ids['sites'][0]);

        $rack = $orm->save(newRack($orm, $site, 'R-MAPPED'));
        $fixture->connection->resetStatementCount();

        expect($orm->find(Rack::class, $rack->primaryKey()))->toBe($rack)
            ->and($fixture->connection->statementCount())->toBe(0, 'served from the map, not re-read');
    });
});

describe('update writes only the diff', function () {
    it('sends just the changed columns', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        $orm = writeOrm($fixture);
        $rack = $orm->find(Rack::class, $fixture->ids['racks'][0]);

        $fixture->connection->resetStatementCount();
        Rack::meta()->set($rack, 'name', 'R-RENAMED');

        expect($rack->isDirty())->toBeTrue()
            ->and(array_keys($rack->changes()))->toBe(['name']);

        $orm->save($rack);

        $sql = $fixture->connection->statementLog()[0];
        expect($sql)->toContain('UPDATE')->toContain('"name" = ?')
            ->and($sql)->not->toContain('height_u');
    });

    it('issues no statement when nothing changed', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        $orm = writeOrm($fixture);
        $rack = $orm->find(Rack::class, $fixture->ids['racks'][0]);

        $fixture->connection->resetStatementCount();
        $orm->save($rack);

        expect($fixture->connection->statementCount())->toBe(0);
    });

    it('is clean again after saving', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        $orm = writeOrm($fixture);
        $rack = $orm->find(Rack::class, $fixture->ids['racks'][0]);

        Rack::meta()->set($rack, 'name', 'R-X');
        $orm->save($rack);

        expect($rack->isDirty())->toBeFalse()->and($rack->changes())->toBe([]);
    });

    it('does not force a lazy relation to load', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 3);
        $orm = writeOrm($fixture);
        $rack = $orm->find(Rack::class, $fixture->ids['racks'][0]);

        $fixture->connection->resetStatementCount();
        Rack::meta()->set($rack, 'name', 'R-Y');
        $orm->save($rack);

        // One UPDATE. Reading the belongsTo to learn its key would have
        // issued a SELECT for the site as well.
        expect($fixture->connection->statementCount())->toBe(1);
    });
});

it('deletes a row and drops it from the identity map', function () {
    $fixture = new Fixture(racks: 2, unitsPerRack: 1);
    $orm = writeOrm($fixture);
    $rack = $orm->find(Rack::class, $fixture->ids['racks'][0]);

    $orm->delete($rack);

    expect($orm->identityMap()->has(Rack::class, $rack->primaryKey()))->toBeFalse()
        ->and((new Orm($fixture->connection, new IdentityMap('fresh'), tenants: Tenancy::flat()))
            ->find(Rack::class, $rack->primaryKey()))->toBeNull();
});

describe('transactions', function () {
    it('commits what the closure did', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        $orm = writeOrm($fixture);
        $site = $orm->find(Site::class, $fixture->ids['sites'][0]);

        $rack = $orm->transaction(fn (): Rack => $orm->save(newRack($orm, $site, 'R-TX')));

        expect((new Orm($fixture->connection, new IdentityMap('fresh'), tenants: Tenancy::flat()))
            ->find(Rack::class, $rack->primaryKey()))->not->toBeNull();
    });

    it('rolls back when the closure throws', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        $orm = writeOrm($fixture);
        $site = $orm->find(Site::class, $fixture->ids['sites'][0]);
        $id = null;

        try {
            $orm->transaction(function () use ($orm, $site, &$id): never {
                $id = $orm->save(newRack($orm, $site, 'R-GONE'))->primaryKey();

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // expected
        }

        expect((new Orm($fixture->connection, new IdentityMap('fresh'), tenants: Tenancy::flat()))
            ->find(Rack::class, $id))->toBeNull();
    });

    // A nested call is a savepoint inside the outer transaction, not a second
    // transaction; NestedTransactionTest covers what its rollback undoes.
    it('stays inside the outer transaction when nested', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        $orm = writeOrm($fixture);

        $depth = $orm->transaction(fn (): bool => $orm->transaction(
            fn (): bool => $fixture->connection->inTransaction(),
        ));

        expect($depth)->toBeTrue()
            ->and($fixture->connection->inTransaction())->toBeFalse('committed on the way out');
    });
});

describe('writes are tenant-scoped', function () {
    it('stamps the tenant rather than taking it from the model', function () {
        $fixture = new TenantFixture();
        $orm = new Orm($fixture->connection, new IdentityMap('tenant-test'), tenants: Tenancy::flat());

        $script = $orm->actAs('acme', function () use ($orm): Script {
            $new = (new ReflectionClass(Script::class))->newInstanceWithoutConstructor();
            Script::meta()->set($new, 'name', 'acme-new');

            return $orm->save($new);
        });

        // Visible to acme, invisible to globex.
        expect($orm->actAs('acme', fn () => $orm->find(Script::class, $script->primaryKey())))->not->toBeNull()
            ->and($orm->actAs('globex', fn () => $orm->find(Script::class, $script->primaryKey())))->toBeNull();
    });

    it('refuses to insert a tenant-scoped row with no tenant', function () {
        $fixture = new TenantFixture();
        $orm = new Orm($fixture->connection, new IdentityMap('tenant-test'), tenants: Tenancy::flat());
        $new = (new ReflectionClass(Script::class))->newInstanceWithoutConstructor();
        Script::meta()->set($new, 'name', 'orphan');

        expect(fn () => $orm->save($new))->toThrow(NoTenantContext::class);
    });

    it('puts the tenant predicate on an update', function () {
        $fixture = new TenantFixture();
        $orm = new Orm($fixture->connection, new IdentityMap('tenant-test'), tenants: Tenancy::flat());

        $orm->actAs('acme', function () use ($orm, $fixture): void {
            $script = $orm->find(Script::class, $fixture->scripts['acme'][0]);
            $fixture->connection->resetStatementCount();
            Script::meta()->set($script, 'name', 'renamed');
            $orm->save($script);

            expect($fixture->connection->statementLog()[0])->toContain('tenant_id');
        });
    });
});

// Postgres refuses a malformed value for a uuid column, so /racks/nope was a
// 500 -- and inside a transaction it would abort the transaction.
it('finds nothing for a key that is not a UUID, without asking the database', function (string $id) {
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'), tenants: Tenancy::flat());
    $fixture->connection->resetStatementCount();

    expect($orm->find(Rack::class, $id))->toBeNull()
        ->and($fixture->connection->statementCount())->toBe(0);
})->with(['nope', '', "01a166b1-0816-74c0-b4d5-2e9eb49b0838\n", '01a166b1-0816-74c0-b4d5-2e9eb49b0838 x']);

// find() answers a non-UUID key without querying, so a row stored under one
// could never be read back.
it('refuses to insert under a preset key that is not a UUID', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'), tenants: Tenancy::flat());
    $rack = new Rack('R-x');
    $rack->site = $orm->find(Site::class, $fixture->ids['sites'][0]);
    (new ReflectionProperty(Rack::class, 'id'))->setValue($rack, 'rack-1');

    expect(fn () => $orm->save($rack))->toThrow(Tinvar\Orm\MappingException::class);
});

/** A script that maps its tenant column, as an application may to show it. */
#[\Tinvar\Orm\Attributes\Table('scripts'), \Tinvar\Orm\Attributes\TenantScoped]
final class ScriptWithTenant extends \Tinvar\Orm\Model
{
    #[\Tinvar\Orm\Attributes\Id] public private(set) string $id;
    #[\Tinvar\Orm\Attributes\Column] public string $name;
    #[\Tinvar\Orm\Attributes\Column('tenant_id')] public string $tenantId;
}

describe('a model that maps its tenant column', function () {
    // The tenant is stamped on insert and never updated. A save that moved
    // it was dropped from the write without a word, leaving the model in
    // memory naming a tenant its row is not in -- refused instead, as a
    // builder's update of the column is (ForeignTenantRow::moved()).
    it('refuses a save that changes the tenant, and leaves the row where it was', function () {
        $fixture = new TenantFixture();
        $orm = new Orm($fixture->connection, new IdentityMap('tenant-test'), tenants: Tenancy::flat());
        $id = $fixture->scripts['acme'][0];

        $attempt = $orm->actAs('acme', function () use ($orm, $id): ?\Throwable {
            $script = $orm->find(ScriptWithTenant::class, $id);
            $script->tenantId = 'globex';
            $script->name = 'moved';
            try {
                $orm->save($script);
            } catch (\Throwable $e) {
                return $e;
            }

            return null;
        });

        expect($attempt)->toBeInstanceOf(\Tinvar\Orm\ForeignTenantRow::class)
            ->and($fixture->connection->select('SELECT name, tenant_id FROM scripts WHERE id = ?', [$id]))
            ->toBe([['name' => 'acme-script-1', 'tenant_id' => 'acme']])
            ->and($orm->actAs('globex', fn () => $orm->find(ScriptWithTenant::class, $id)))->toBeNull();
    });

    it('saves its other changes with the tenant it was read with', function () {
        $fixture = new TenantFixture();
        $orm = new Orm($fixture->connection, new IdentityMap('tenant-test'), tenants: Tenancy::flat());
        $id = $fixture->scripts['acme'][0];

        $orm->actAs('acme', function () use ($orm, $id): void {
            $script = $orm->find(ScriptWithTenant::class, $id);
            $script->name = 'renamed';
            $orm->save($script);
        });

        expect($fixture->connection->select('SELECT name, tenant_id FROM scripts WHERE id = ?', [$id]))
            ->toBe([['name' => 'renamed', 'tenant_id' => 'acme']]);
    });

    // The tenant is stamped on insert, never taken from the model. The model
    // kept what it held -- nothing, or another tenant -- so reading the
    // property after the save threw, and its next save read as moving the
    // row to the tenant it never left.
    it('holds the tenant a new one was stamped with once it is saved', function () {
        $fixture = new TenantFixture();
        $orm = new Orm($fixture->connection, new IdentityMap('tenant-test'), tenants: Tenancy::flat());

        $script = $orm->actAs('acme', function () use ($orm): ScriptWithTenant {
            $script = new ScriptWithTenant();
            $script->name = 'fresh';
            $orm->save($script);

            return $script;
        });

        expect($script->tenantId)->toBe('acme')
            ->and($script->isDirty())->toBeFalse();
    });

    it('saves later changes to a new one that named another tenant before its insert', function () {
        $fixture = new TenantFixture();
        $orm = new Orm($fixture->connection, new IdentityMap('tenant-test'), tenants: Tenancy::flat());

        $orm->actAs('acme', function () use ($orm, $fixture): void {
            $script = new ScriptWithTenant();
            $script->name = 'fresh';
            $script->tenantId = 'globex';
            $orm->save($script);

            $script->name = 'renamed';
            $orm->save($script);

            expect($script->tenantId)->toBe('acme')
                ->and($fixture->connection->select('SELECT name, tenant_id FROM scripts WHERE id = ?', [$script->id]))
                ->toBe([['name' => 'renamed', 'tenant_id' => 'acme']]);
        });
    });
});
