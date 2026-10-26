<?php

declare(strict_types=1);

/**
 * Level 2 keeps Level 1's widening to the result set.
 *
 * Inside a render, await() registered only the parent being read. A fiber
 * that iterates a result set and reads a relation on each row suspended once
 * per row, so the loop that costs 1+1 statements outside a render cost 1+N
 * inside one -- the pattern the batching exists to prevent.
 */

namespace Tinvar\Tests\Orm\Level2ResultSet;

use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;

require_once __DIR__ . '/Fixtures/models.php';
require_once __DIR__ . '/Fixtures/schema.php';

it('loads a relation for the whole result set when one fiber iterates it', function () {
    $fixture = new Fixture(racks: 10, unitsPerRack: 2);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $scheduler = $orm->beginRender();

    $results = $scheduler->run(['page' => static function () use ($orm, $fixture): int {
        $total = 0;
        foreach ($orm->whereIn(Rack::class, 'id', $fixture->ids['racks']) as $rack) {
            $total += $rack->units->count();
        }

        return $total;
    }]);

    expect($results['page'])->toBe(20)
        ->and($fixture->connection->statementCount())->toBe(2, 'one for the racks, one for every unit')
        ->and($scheduler->rounds())->toBe(1);
});

it('does not register parents whose relation is already loaded', function () {
    $fixture = new Fixture(racks: 4, unitsPerRack: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $racks = $orm->whereIn(Rack::class, 'id', $fixture->ids['racks']);

    // Loaded outside the render, for the whole set.
    $racks[0]->units->count();
    $fixture->connection->resetStatementCount();

    $scheduler = $orm->beginRender();
    $results = $scheduler->run(['page' => static fn (): int => $racks[3]->units->count()]);

    expect($results['page'])->toBe(1)
        ->and($fixture->connection->statementCount())->toBe(0)
        ->and($scheduler->rounds())->toBe(0);
});
