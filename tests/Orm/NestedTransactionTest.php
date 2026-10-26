<?php

declare(strict_types=1);

/**
 * A nested transaction() that throws rolls back its own writes.
 *
 * Nested calls used to join the outer transaction. An inner call that threw
 * left its writes in place, so an outer caller that caught the throw and
 * carried on committed them -- the opposite of "rolling back on a throw".
 */

namespace Tinvar\Tests\Orm\NestedTransaction;

use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;

require_once __DIR__ . '/Fixtures/models.php';
require_once __DIR__ . '/Fixtures/schema.php';

/** @return list<string> rack names in the database, in name order */
function rackNames(Fixture $fixture): array
{
    return array_map(
        static fn (array $row): string => (string) $row['name'],
        $fixture->connection->select('SELECT name FROM racks ORDER BY name'),
    );
}

function renameRack(Fixture $fixture, string $id, string $name): void
{
    $fixture->connection->execute('UPDATE racks SET name = ? WHERE id = ?', [$name, $id]);
}

it('rolls back an inner transaction that throws, and commits the outer one around it', function () {
    $fixture = new Fixture(racks: 2, unitsPerRack: 0);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    [$first, $second] = $fixture->ids['racks'];

    $orm->transaction(function () use ($orm, $fixture, $first, $second): void {
        renameRack($fixture, $first, 'outer-write');

        try {
            $orm->transaction(function () use ($fixture, $second): never {
                renameRack($fixture, $second, 'inner-write');

                throw new \RuntimeException('inner fails');
            });
        } catch (\RuntimeException) {
            // handled; the outer carries on
        }
    });

    expect(rackNames($fixture))->toBe(['R-02', 'outer-write']);
});

it('rolls back an inner write saved through the ORM', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 0);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $rack = $orm->find(Rack::class, $fixture->ids['racks'][0]) ?? throw new \LogicException('no rack');

    $orm->transaction(function () use ($orm, $rack): void {
        try {
            $orm->transaction(function () use ($orm, $rack): never {
                $rack->name = 'inner-write';
                $orm->save($rack);

                throw new \RuntimeException('inner fails');
            });
        } catch (\RuntimeException) {
            // handled; the outer carries on
        }
    });

    expect(rackNames($fixture))->toBe(['R-01']);
});

it('keeps an inner transaction that returned, and undoes it with the outer', function () {
    $fixture = new Fixture(racks: 2, unitsPerRack: 0);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    [$first, $second] = $fixture->ids['racks'];

    $orm->transaction(function () use ($orm, $fixture, $first): void {
        $orm->transaction(fn () => renameRack($fixture, $first, 'kept'));
    });

    expect(rackNames($fixture))->toBe(['R-02', 'kept']);

    try {
        $orm->transaction(function () use ($orm, $fixture, $second): never {
            $orm->transaction(fn () => renameRack($fixture, $second, 'undone'));

            throw new \RuntimeException('outer fails');
        });
    } catch (\RuntimeException) {
        // expected
    }

    expect(rackNames($fixture))->toBe(['R-02', 'kept'])
        ->and($fixture->connection->inTransaction())->toBeFalse();
});

it('nests more than one level deep, each level undoing only its own writes', function () {
    $fixture = new Fixture(racks: 3, unitsPerRack: 0);
    $connection = $fixture->connection;
    [$first, $second, $third] = $fixture->ids['racks'];

    $connection->transaction(function () use ($connection, $fixture, $first, $second, $third): void {
        renameRack($fixture, $first, 'a-level-1');

        $connection->transaction(function () use ($connection, $fixture, $second, $third): void {
            renameRack($fixture, $second, 'b-level-2');

            try {
                $connection->transaction(function () use ($fixture, $third): never {
                    renameRack($fixture, $third, 'c-level-3');

                    throw new \RuntimeException('innermost fails');
                });
            } catch (\RuntimeException) {
                // handled at level 2
            }
        });
    });

    expect(rackNames($fixture))->toBe(['R-03', 'a-level-1', 'b-level-2']);
});
