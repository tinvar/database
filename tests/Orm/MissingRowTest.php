<?php

declare(strict_types=1);

/**
 * A write that reaches no row says so.
 *
 * update() ignored the affected-row count, so a save() whose row was gone, or
 * belonged to another tenant, wrote nothing, reported success and marked the
 * model clean. delete() left the model marked persisted, so a later save()
 * was exactly that silent no-op UPDATE.
 */

namespace Tinvar\Tests\Orm\MissingRow;

use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\MissingRow;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Tests\Orm\Fixtures\TenantFixture;

require_once __DIR__ . '/Fixtures/models.php';
require_once __DIR__ . '/Fixtures/schema.php';
require_once __DIR__ . '/Fixtures/tenant.php';
require_once __DIR__ . '/Fixtures/tenancy.php';

function rackCount(Fixture $fixture): int
{
    return (int) $fixture->connection->select('SELECT COUNT(*) AS n FROM racks')[0]['n'];
}

it('refuses to update a row that is no longer there', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 0);
    $orm = new Orm($fixture->connection, new IdentityMap('test'), tenants: Tenancy::flat());
    $rack = $orm->find(Rack::class, $fixture->ids['racks'][0]) ?? throw new \LogicException('no rack');

    // Deleted by someone else since it was read.
    $fixture->connection->execute('DELETE FROM racks');
    $rack->name = 'renamed';

    expect(fn () => $orm->save($rack))->toThrow(MissingRow::class, 'matched no row')
        ->and($rack->isDirty())->toBeTrue('the change was not written, so it is still pending');
});

it('refuses to update a row that belongs to another tenant', function () {
    $fixture = new TenantFixture();
    $orm = new Orm($fixture->connection, new IdentityMap('tenant-test'), tenants: Tenancy::flat());
    $orm->switchTenant('acme');
    $id = $fixture->scripts['globex'][0];
    $globex = $orm->acrossTenants(static fn () => $orm->find(Script::class, $id))
        ?? throw new \LogicException('no script');

    $globex->name = 'changed-from-acme';

    expect(fn () => $orm->save($globex))->toThrow(MissingRow::class)
        ->and($fixture->connection->select('SELECT name FROM scripts WHERE id = ?', [$id])[0]['name'])
        ->toBe('globex-script-1')
        ->and($globex->isDirty())->toBeTrue();
});

it('words a missing row and another tenant\'s row the same way', function () {
    $fixture = new TenantFixture();
    $orm = new Orm($fixture->connection, new IdentityMap('tenant-test'), tenants: Tenancy::flat());
    $orm->switchTenant('acme');
    $id = $fixture->scripts['globex'][0];
    $globex = $orm->acrossTenants(static fn () => $orm->find(Script::class, $id))
        ?? throw new \LogicException('no script');
    $globex->name = 'changed';

    $foreign = null;
    try {
        $orm->save($globex);
    } catch (MissingRow $e) {
        $foreign = $e->getMessage();
    }

    $fixture->connection->execute('DELETE FROM scripts WHERE id = ?', [$id]);
    $gone = null;
    try {
        $orm->actAs('globex', static fn () => $orm->save($globex));
    } catch (MissingRow $e) {
        $gone = $e->getMessage();
    }

    // "A row you may not see" and "a row that does not exist" must read the
    // same, or the difference is a cross-tenant existence oracle.
    expect($foreign)->not->toBeNull()->and($foreign)->toBe($gone);
});

it('no longer counts a deleted model as persisted, so saving it inserts the row again', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 0);
    $orm = new Orm($fixture->connection, new IdentityMap('test'), tenants: Tenancy::flat());
    $rack = $orm->find(Rack::class, $fixture->ids['racks'][0]) ?? throw new \LogicException('no rack');

    $orm->delete($rack);

    expect($rack->isPersisted())->toBeFalse()
        ->and(rackCount($fixture))->toBe(0);

    $fixture->connection->resetStatementCount();
    $orm->save($rack);

    expect($fixture->connection->statementLog()[0])->toStartWith('INSERT')
        ->and(rackCount($fixture))->toBe(1)
        ->and($rack->isPersisted())->toBeTrue()
        ->and($orm->find(Rack::class, $rack->primaryKey()))->toBe($rack);
});
