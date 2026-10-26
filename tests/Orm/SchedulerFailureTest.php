<?php

declare(strict_types=1);

/**
 * A batched relation load that fails fails only the calls that awaited it.
 *
 * run() promises "a task that throws yields its Throwable in place, so one
 * failed call is reported against its own id instead of failing the batch".
 * A load that failed inside flush() escaped run() instead: every call in the
 * tick failed, and the results of the ones that had already finished were
 * lost with them.
 */

namespace Tinvar\Tests\Orm\SchedulerFailure;

use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;

require_once __DIR__ . '/Fixtures/models.php';
require_once __DIR__ . '/Fixtures/schema.php';

it('fails only the calls that awaited a load that failed', function () {
    $fixture = new Fixture(racks: 2, unitsPerRack: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $racks = $orm->whereIn(Rack::class, 'id', $fixture->ids['racks']);
    $fixture->connection->execute('ALTER TABLE units RENAME TO units_gone');

    $results = $orm->beginRender()->run([
        'units' => static fn (): int => $racks[0]->units->count(),
        'plain' => static fn (): string => 'fine',
        'site' => static fn (): string => $racks[1]->site->code,
    ]);

    expect($results['units'])->toBeInstanceOf(\PDOException::class)
        ->and($results['plain'])->toBe('fine')
        ->and($results['site'])->toBe('LAX0');
});

it('still serves the other loads flushed in the same tick', function () {
    $fixture = new Fixture(racks: 2, unitsPerRack: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $racks = $orm->whereIn(Rack::class, 'id', $fixture->ids['racks']);
    $site = $racks[1]->site;
    $fixture->connection->execute('ALTER TABLE units RENAME TO units_gone');

    $scheduler = $orm->beginRender();
    $results = $scheduler->run([
        'units' => static fn (): int => $racks[0]->units->count(),
        'racks' => static fn (): int => $site->racks->count(),
    ]);

    expect($results['units'])->toBeInstanceOf(\PDOException::class)
        ->and($results['racks'])->toBe(2)
        ->and($scheduler->hasPending())->toBeFalse();
});
