<?php

declare(strict_types=1);

use Tinvar\Orm\Collection;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Site;
use Tinvar\Tests\Orm\Fixtures\Unit;

require_once __DIR__ . '/Fixtures/models.php';
require_once __DIR__ . '/Fixtures/schema.php';

function ormFor(Fixture $fixture): Orm
{
    return new Orm($fixture->connection, new IdentityMap('test'));
}

describe('identity map', function () {
    it('returns the same object for the same row', function () {
        $fixture = new Fixture(racks: 2, unitsPerRack: 1);
        $orm = ormFor($fixture);
        $id = $fixture->ids['racks'][0];

        $first = $orm->find(Rack::class, $id);
        $second = $orm->find(Rack::class, $id);

        expect($second)->toBe($first)
            ->and($fixture->connection->statementCount())->toBe(1, 'the second find was served from the map');
    });

    it('is keyed per class, so ids never collide across tables', function () {
        $map = new IdentityMap('test');
        $rack = new stdClass();
        $unit = new stdClass();

        $map->remember(Rack::class, 'shared-id', $rack);
        $map->remember(Unit::class, 'shared-id', $unit);

        expect($map->get(Rack::class, 'shared-id'))->toBe($rack)
            ->and($map->get(Unit::class, 'shared-id'))->toBe($unit)
            ->and($map->count())->toBe(2);
    });

    it('serves no row across a tenant switch', function () {
        $map = new IdentityMap('test');
        $map->remember(Rack::class, 'r1', new stdClass());
        $map->switchTenant('acme');

        expect($map->get(Rack::class, 'r1'))->toBeNull()
            ->and($map->tenant())->toBe('acme');
    });

    // What clearing is actually for: switching BACK to a tenant seen earlier
    // in the request must not serve rows cached before an intervening write.
    // The whole map is no longer dropped, because other fibers may be using
    // it -- the tenant being entered is.
    it('drops the entered tenant rows, so a switch back re-reads', function () {
        $map = new IdentityMap('test');
        $first = new stdClass();
        $map->remember(Rack::class, 'r1', $first);

        $map->switchTenant('acme');
        $map->switchTenant('');

        expect($map->get(Rack::class, 'r1'))->toBeNull('stale rows are not served on the way back');
    });

    it('does not serve a relation cached under one tenant to the next', function () {
        $fixture = new Fixture(racks: 2, unitsPerRack: 3);
        $orm = ormFor($fixture);

        $racks = $orm->whereIn(Rack::class, 'id', $fixture->ids['racks']);
        expect($racks[0]->units->count())->toBe(3);

        $orm->switchTenant('acme');
        $fixture->connection->resetStatementCount();

        // Same rows, new tenant: nothing may come from the previous tenant's cache.
        $again = $orm->whereIn(Rack::class, 'id', $fixture->ids['racks']);
        expect($again[0])->not->toBe($racks[0], 'a new tenant gets new instances')
            ->and($again[0]->units->count())->toBe(3)
            ->and($fixture->connection->statementCount())
            ->toBe(2, 'racks and units were both re-read, not served from the old tenant');
    });
});

describe('level 1: collection-aware lazy loading', function () {
    it('does not touch the database until the relation is read', function () {
        $fixture = new Fixture(racks: 3, unitsPerRack: 4);
        $orm = ormFor($fixture);

        $racks = $orm->whereIn(Rack::class, 'id', $fixture->ids['racks']);
        expect($fixture->connection->statementCount())->toBe(1);
        expect($racks[0]->units)->toBeInstanceOf(Collection::class)
            ->and($racks[0]->units->isLoaded())->toBeFalse();
    });

    it('loads one relation for the whole result set: 1+1 queries, never 1+N', function () {
        $fixture = new Fixture(racks: 42, unitsPerRack: 4);
        $orm = ormFor($fixture);

        $racks = $orm->whereIn(Rack::class, 'id', $fixture->ids['racks']);
        $fixture->connection->resetStatementCount();

        $total = 0;
        foreach ($racks as $rack) {
            $total += $rack->units->count();
        }

        expect($total)->toBe(42 * 4)
            ->and($fixture->connection->statementCount())->toBe(1, '42 racks, one units statement');
    });

    it('batches a single relation across the result set too', function () {
        $fixture = new Fixture(racks: 20, unitsPerRack: 1, sites: 2);
        $orm = ormFor($fixture);

        $racks = $orm->whereIn(Rack::class, 'id', $fixture->ids['racks']);
        $fixture->connection->resetStatementCount();

        $codes = array_map(static fn (Rack $r): string => $r->site->code, $racks);

        expect(array_unique($codes))->toHaveCount(2)
            ->and($fixture->connection->statementCount())->toBe(1, '20 racks, one sites statement');
    });

    it('gives every parent pointing at one row the same instance', function () {
        $fixture = new Fixture(racks: 4, unitsPerRack: 1, sites: 1);
        $orm = ormFor($fixture);

        $racks = $orm->whereIn(Rack::class, 'id', $fixture->ids['racks']);
        $sites = array_map(static fn (Rack $r): Site => $r->site, $racks);

        expect($sites[1])->toBe($sites[0])->and($sites[3])->toBe($sites[0]);
    });

    it('reads a property hook that crosses a lazy single relation', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        $orm = ormFor($fixture);

        $rack = $orm->find(Rack::class, $fixture->ids['racks'][0]);

        expect($rack->label)->toBe('LAX0-R-01');
    });

    it('orders a relation by the column the attribute names', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 5);
        $orm = ormFor($fixture);

        $rack = $orm->find(Rack::class, $fixture->ids['racks'][0]);
        $positions = $rack->units->map(static fn (Unit $u): int => $u->position);

        expect($positions)->toBe([1, 2, 3, 4, 5]);
    });
});

describe('level 2: render-wide batching over fibers', function () {
    it('collapses one relation per component into a single statement', function () {
        $fixture = new Fixture(racks: 42, unitsPerRack: 4);
        $orm = ormFor($fixture);

        $racks = $orm->whereIn(Rack::class, 'id', $fixture->ids['racks']);
        $fixture->connection->resetStatementCount();

        $scheduler = $orm->beginRender();
        $counts = $scheduler->run(array_map(
            static fn (Rack $rack): Closure => static fn (): int => $rack->units->count(),
            $racks,
        ));

        expect(array_sum($counts))->toBe(42 * 4)
            ->and($fixture->connection->statementCount())->toBe(1, '42 fibers, one statement')
            ->and($scheduler->rounds())->toBe(1);
    });

    it('batches across separate result sets, which Level 1 cannot', function () {
        $fixture = new Fixture(racks: 10, unitsPerRack: 3);
        $orm = ormFor($fixture);

        // Two independent loads: Level 1 would give one units statement each.
        $first = $orm->whereIn(Rack::class, 'id', array_slice($fixture->ids['racks'], 0, 5));
        $second = $orm->whereIn(Rack::class, 'id', array_slice($fixture->ids['racks'], 5));
        $fixture->connection->resetStatementCount();

        $scheduler = $orm->beginRender();
        $counts = $scheduler->run(array_map(
            static fn (Rack $rack): Closure => static fn (): int => $rack->units->count(),
            [...$first, ...$second],
        ));

        expect(array_sum($counts))->toBe(30)
            ->and($fixture->connection->statementCount())->toBe(1, 'both result sets in one statement');
    });

    // Regression for ADR 0002: a shared relation read by two fibers used to
    // return an empty collection to the second reader, with no error.
    it('serves two fibers reading the same relation correctly, with one statement', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 6);
        $orm = ormFor($fixture);

        $rack = $orm->find(Rack::class, $fixture->ids['racks'][0]);
        $fixture->connection->resetStatementCount();

        $scheduler = $orm->beginRender();
        $counts = $scheduler->run([
            'a' => static fn (): int => $rack->units->count(),
            'b' => static fn (): int => $rack->units->count(),
            'c' => static fn (): int => $rack->units->count(),
        ]);

        expect($counts)->toBe(['a' => 6, 'b' => 6, 'c' => 6])
            ->and($fixture->connection->statementCount())->toBe(1, 'one shared row, one statement');
    });

    it('runs a second round when a fiber needs a relation it could not know about', function () {
        $fixture = new Fixture(racks: 6, unitsPerRack: 2, sites: 3);
        $orm = ormFor($fixture);

        $racks = $orm->whereIn(Rack::class, 'id', $fixture->ids['racks']);
        $fixture->connection->resetStatementCount();

        $scheduler = $orm->beginRender();
        $labels = $scheduler->run(array_map(
            static fn (Rack $rack): Closure => static fn (): string => $rack->units->count() . ':' . $rack->site->code,
            $racks,
        ));

        expect($labels)->toHaveCount(6)
            ->and($fixture->connection->statementCount())
            ->toBeLessThanOrEqual(2, 'one statement for units, one for sites');
    });

    it('reports a failing task against its own id and lets the rest finish', function () {
        $fixture = new Fixture(racks: 3, unitsPerRack: 2);
        $orm = ormFor($fixture);

        $racks = $orm->whereIn(Rack::class, 'id', $fixture->ids['racks']);
        $scheduler = $orm->beginRender();

        $results = $scheduler->run([
            0 => static fn (): int => $racks[0]->units->count(),
            1 => static fn (): never => throw new RuntimeException('query blew up'),
            2 => static fn (): int => $racks[2]->units->count(),
        ]);

        expect($results[0])->toBe(2)
            ->and($results[1])->toBeInstanceOf(RuntimeException::class)
            ->and($results[2])->toBe(2);
    });
});

// Namespacing the relation-row cache by tenant stops it reading ACROSS one,
// but not from going stale: a switch back must not serve rows cached before
// an intervening write, which is exactly what the identity map clears for.
it('drops the entered tenant relation rows on a switch', function () {
    $fixture = new Fixture(racks: 2, unitsPerRack: 2);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    $rack = $orm->all(Rack::class)[0];
    expect($rack->units->count())->toBe(2);

    $orm->switchTenant('other');
    $orm->switchTenant('');

    $before = $fixture->connection->statementCount();
    $orm->all(Rack::class)[0]->units->count();

    expect($fixture->connection->statementCount())
        ->toBeGreaterThan($before, 'the relation is read again, not served from before the switch');
});

/**
 * Relation reads that answered [] without saying so.
 *
 * The parents a load covers were chosen with `?? [$parent]`, which never
 * fired: an EMPTY result set is not null. Zero parents reached the loader, it
 * returned early, cached nothing, and the read fell through to an empty
 * collection -- no fibers, no tenancy, no scheduler involved.
 */
describe('a relation never answers empty by accident', function () {
    it('loads a hasMany on an owner reached through a belongsTo ghost', function () {
        $fixture = new Fixture(racks: 3, unitsPerRack: 1, sites: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));

        // Touching the ghost is the only way this Site enters the request.
        $site = $orm->all(Rack::class)[0]->site;

        expect($site->racks->count())->toBe(3);
    });

    it('agrees with the same site read directly', function () {
        $fixture = new Fixture(racks: 3, unitsPerRack: 1, sites: 1);

        $direct = new Orm($fixture->connection, new IdentityMap('test'));
        $viaGhost = new Orm($fixture->connection, new IdentityMap('test'));

        expect($viaGhost->all(Rack::class)[0]->site->racks->count())
            ->toBe($direct->all(Site::class)[0]->racks->count());
    });
});

/**
 * Every id went in one IN list, however many parents the result set held:
 * past SQLite's 32,766 bound values, or Postgres's and MySQL's 65,535, the
 * statement failed, and a result set of that size read a relation not at
 * all. The ids go MAX_VALUES at a time now, and the rows are merged.
 */
describe('a load for more ids than one IN list binds', function () {
    function manyRacks(int $count): Fixture
    {
        $fixture = new Fixture(racks: 0, unitsPerRack: 0, sites: 1);
        $pdo = $fixture->connection->pdo();
        $pdo->beginTransaction();
        $rack = $pdo->prepare('INSERT INTO racks VALUES (?, ?, 42, ?)');
        $unit = $pdo->prepare('INSERT INTO units VALUES (?, 1, ?, ?)');
        for ($i = 0; $i < $count; $i++) {
            $id = Tinvar\Support\Uuid::v7();
            $fixture->ids['racks'][] = $id;
            $rack->execute([$id, "r{$i}", $fixture->ids['sites'][0]]);
            if ($i % 1000 === 0) {
                $unit->execute([Tinvar\Support\Uuid::v7(), "u{$i}", $id]);
            }
        }
        $pdo->commit();
        $fixture->connection->resetStatementCount();

        return $fixture;
    }

    it('loads a relation in chunks, eagerly and lazily', function () {
        $fixture = manyRacks(33_000);

        $eager = new Orm($fixture->connection, new IdentityMap('test'));
        $racks = $eager->query(Rack::class)->with('units')->fetch();
        $units = array_sum(array_map(static fn (Rack $rack): int => $rack->units->count(), $racks));

        $lazy = new Orm($fixture->connection, new IdentityMap('test'));
        $all = $lazy->all(Rack::class);
        $fixture->connection->resetStatementCount();

        expect($units)->toBe(33)
            ->and($all[32_000]->units->count())->toBe(1)
            ->and($fixture->connection->statementCount())->toBe(4, '33,000 ids, 10,000 at a time');
    });

    it('reads every value whereIn() is handed, in chunks', function () {
        $fixture = manyRacks(33_000);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));

        $racks = $orm->whereIn(Rack::class, 'id', [...$fixture->ids['racks'], $fixture->ids['racks'][0]]);

        expect($racks)->toHaveCount(33_000)
            ->and($fixture->connection->statementCount())->toBe(4)
            ->and(fn () => $orm->whereIn(Rack::class, 'id', $fixture->ids['racks'], 'name'))
            ->toThrow(LengthException::class, 'whereIn() orders at most 10000 values');
    });
});
