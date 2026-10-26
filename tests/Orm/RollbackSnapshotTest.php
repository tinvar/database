<?php

declare(strict_types=1);

/**
 * A rolled-back transaction puts back the snapshots its writes took.
 *
 * save() snapshots the values it wrote, and a rollback left that snapshot in
 * place: a model whose insert was undone still read as persisted, and one
 * whose update was undone read as clean. A retry of the same save() then
 * wrote nothing, and said nothing.
 */

namespace Tinvar\Tests\Orm\RollbackSnapshot;

use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Site;

require_once __DIR__ . '/Fixtures/models.php';
require_once __DIR__ . '/Fixtures/schema.php';

/** @param \Closure(): void $work */
function rolledBack(Orm $orm, \Closure $work): void
{
    try {
        $orm->transaction(function () use ($work): never {
            $work();

            throw new \RuntimeException('boom');
        });
    } catch (\RuntimeException) {
        // expected
    }
}

function unsavedRack(Site $site, string $name): Rack
{
    $rack = (new \ReflectionClass(Rack::class))->newInstanceWithoutConstructor();
    $rack->name = $name;
    $rack->heightU = 42;
    $rack->site = $site;

    return $rack;
}

/** @return list<string> */
function rackNames(Fixture $fixture): array
{
    return array_map(
        static fn (array $row): string => (string) $row['name'],
        $fixture->connection->select('SELECT name FROM racks ORDER BY name'),
    );
}

it('leaves a model whose insert rolled back unpersisted, so a retry inserts it', function () {
    $fixture = new Fixture(racks: 0, unitsPerRack: 0);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $site = $orm->find(Site::class, $fixture->ids['sites'][0]) ?? throw new \LogicException('no site');
    $rack = unsavedRack($site, 'R-NEW');

    rolledBack($orm, fn () => $orm->save($rack));

    expect($rack->isPersisted())->toBeFalse()
        ->and(rackNames($fixture))->toBe([]);

    $orm->save($rack);

    expect(rackNames($fixture))->toBe(['R-NEW'])
        ->and($rack->isPersisted())->toBeTrue();
});

it('leaves a model whose update rolled back dirty, so a retry writes it', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 0);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $rack = $orm->find(Rack::class, $fixture->ids['racks'][0]) ?? throw new \LogicException('no rack');

    rolledBack($orm, function () use ($orm, $rack): void {
        $rack->name = 'renamed';
        $orm->save($rack);
    });

    expect(rackNames($fixture))->toBe(['R-01'])
        ->and($rack->isDirty())->toBeTrue()
        ->and($rack->changes())->toBe(['name' => 'renamed']);

    $orm->save($rack);

    expect(rackNames($fixture))->toBe(['renamed']);
});

it('restores the first snapshot of a model saved twice in the transaction', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 0);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $rack = $orm->find(Rack::class, $fixture->ids['racks'][0]) ?? throw new \LogicException('no rack');

    rolledBack($orm, function () use ($orm, $rack): void {
        $rack->name = 'first';
        $orm->save($rack);
        $rack->heightU = 48;
        $orm->save($rack);
    });

    expect($rack->changes())->toBe(['name' => 'first', 'height_u' => 48]);
});

it('counts a model whose delete rolled back as persisted again', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 0);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $rack = $orm->find(Rack::class, $fixture->ids['racks'][0]) ?? throw new \LogicException('no rack');

    rolledBack($orm, fn () => $orm->delete($rack));

    expect($rack->isPersisted())->toBeTrue()
        ->and($rack->isDirty())->toBeFalse();

    $rack->name = 'still-here';
    $orm->save($rack);

    expect(rackNames($fixture))->toBe(['still-here']);
});

it('restores only what a nested transaction that threw had written', function () {
    $fixture = new Fixture(racks: 2, unitsPerRack: 0);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    [$first, $second] = array_map(
        static fn (string $id): Rack => $orm->find(Rack::class, $id) ?? throw new \LogicException('no rack'),
        $fixture->ids['racks'],
    );

    $orm->transaction(function () use ($orm, $first, $second): void {
        $first->name = 'outer';
        $orm->save($first);

        rolledBack($orm, function () use ($orm, $second): void {
            $second->name = 'inner';
            $orm->save($second);
        });
    });

    expect($first->isDirty())->toBeFalse('committed with the outer transaction')
        ->and($second->isDirty())->toBeTrue('rolled back with the inner one')
        ->and(rackNames($fixture))->toBe(['R-02', 'outer']);
});

it('restores what a nested transaction that returned had written when the outer one rolls back', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 0);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $rack = $orm->find(Rack::class, $fixture->ids['racks'][0]) ?? throw new \LogicException('no rack');

    rolledBack($orm, function () use ($orm, $rack): void {
        $orm->transaction(function () use ($orm, $rack): void {
            $rack->name = 'inner';
            $orm->save($rack);
        });
    });

    expect($rack->isDirty())->toBeTrue()
        ->and(rackNames($fixture))->toBe(['R-01']);
});
