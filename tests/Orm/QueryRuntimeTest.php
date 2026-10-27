<?php

declare(strict_types=1);

/**
 * The query builder inside the ORM's runtime (ADR 0057): rows through the
 * identity map, relation reads batched across a result set and across a
 * render's fibers, eager loads that are no lazy load, the read-only scope,
 * fiber-local tenants and the worker's reset.
 */

namespace Tinvar\Tests\Orm\QueryRuntime;

use Tinvar\Orm\LazyLoad;
use Tinvar\Orm\LazyLoads;
use Tinvar\Orm\NoTenantContext;
use Tinvar\Orm\ReadOnlyViolation;
use Tinvar\Tests\Orm\Fixtures\Aisle;
use Tinvar\Tests\Orm\Fixtures\Item;
use Tinvar\Tests\Orm\Fixtures\Shelf;
use Tinvar\Tests\Orm\Fixtures\Shelves;

require_once __DIR__ . '/Fixtures/shelves.php';

describe('the identity map', function () {
    it('hands back the same object for the same row, however it was read', function () {
        $fixture = Shelves::sqlite();
        $orm = $fixture->orm();

        [$built, $again, $found] = $orm->actAs('acme', static fn (): array => [
            $orm->query(Shelf::class)->where('name', 'Alpha')->first(),
            $orm->query(Shelf::class)->orderBy('position')->fetch()[0],
            $orm->find(Shelf::class, $fixture->ids['Alpha']),
        ]);

        expect($again)->toBe($built)->and($found)->toBe($built);
    });

    it('keeps rows read across tenants out of it', function () {
        $fixture = Shelves::sqlite();
        $orm = $fixture->orm();

        $orm->acrossTenants(static fn (): array => $orm->query(Shelf::class)->fetch());

        expect($orm->identityMap()->count())->toBe(0)
            ->and($orm->actAs('acme', static fn (): ?Shelf => $orm->query(Shelf::class)->where('id', $fixture->ids['Delta'])->first()))
            ->toBeNull();
    });
});

describe('relations', function () {
    it('loads a relation read on one row for every row the query returned', function () {
        $fixture = Shelves::sqlite();
        $connection = $fixture->connection();
        $orm = $fixture->orm(connection: $connection);

        $counts = $orm->actAs('acme', static function () use ($orm): array {
            $counts = [];
            foreach ($orm->query(Shelf::class)->orderBy('position')->fetch() as $shelf) {
                $counts[$shelf->name] = $shelf->items->count();
            }

            return $counts;
        });

        expect($counts)->toBe(['Alpha' => 2, 'Bravo' => 1, 'Charlie' => 0, 'Foxtrot' => 1])
            ->and($connection->statementCount())->toBe(2);
    });

    it('eager-loads a relation in one statement each, recorded as no lazy load', function () {
        $fixture = Shelves::sqlite();
        $connection = $fixture->connection();
        $orm = $fixture->orm(connection: $connection);
        $loads = new LazyLoads();
        $orm->recordLazyLoads($loads);

        $read = $orm->actAs('acme', static function () use ($orm): array {
            $read = [];
            foreach ($orm->query(Item::class)->with('shelf.aisle', 'shelf')->orderBy('name')->fetch() as $item) {
                $read[] = "{$item->name}@{$item->shelf->name}/" . ($item->shelf->name === 'Foxtrot' ? '-' : $item->shelf->aisle->code);
            }

            return $read;
        });

        // Foxtrot's aisle is globex's: not loaded, and still a ghost.
        expect($read)->toBe(['apple@Alpha/A1', 'apricot@Alpha/A1', 'banana@Bravo/A2', 'fig@Foxtrot/-'])
            ->and($connection->statementCount())->toBe(3)
            ->and($loads->since())->toBe([]);
    });

    it('eager-loads a collection, the other tenant\'s children left out', function () {
        $fixture = Shelves::sqlite();
        $connection = $fixture->connection();
        $orm = $fixture->orm(connection: $connection);

        $items = $orm->actAs('acme', static function () use ($orm): array {
            $alpha = $orm->query(Shelf::class)->with('items')->where('name', 'Alpha')->first();
            \assert($alpha !== null);

            return array_map(static fn (Item $item): string => $item->name, $alpha->items->all());
        });

        expect($items)->toBe(['apple', 'apricot'])->and($connection->statementCount())->toBe(2);
    });

    it('eager-loads across tenants inside acrossTenants(), into the rows read there', function () {
        $fixture = Shelves::sqlite();
        $connection = $fixture->connection();
        $orm = $fixture->orm(connection: $connection);

        $read = $orm->acrossTenants(static function () use ($orm): array {
            $read = [];
            foreach ($orm->query(Shelf::class)->with('items', 'aisle')->orderBy('name')->fetch() as $shelf) {
                $read[$shelf->name] = [$shelf->aisle->code, \count($shelf->items->all())];
            }

            return $read;
        });

        expect($read['Alpha'])->toBe(['A1', 3])
            ->and($read['Echo'])->toBe(['A1', 1])
            ->and($read['Foxtrot'])->toBe(['G1', 1])
            ->and($connection->statementCount())->toBe(3)
            ->and($orm->identityMap()->count())->toBe(0);
    });

    it('batches a relation read on builder rows across a render\'s fibers', function () {
        $fixture = Shelves::sqlite();
        $connection = $fixture->connection();
        $orm = $fixture->orm(connection: $connection);
        $loads = new LazyLoads();
        $orm->recordLazyLoads($loads);

        $results = $orm->actAs('acme', static function () use ($orm): array {
            $scheduler = $orm->beginRender();
            try {
                return $scheduler->run([
                    'first' => static fn (): int => $orm->query(Shelf::class)->where('position', 1)->first()?->items->count() ?? -1,
                    'second' => static fn (): int => $orm->query(Shelf::class)->where('position', 2)->first()?->items->count() ?? -1,
                ]);
            } finally {
                $orm->endRender();
            }
        });

        expect($results)->toBe(['first' => 2, 'second' => 1])
            // Two builder statements, one for each fiber's shelf, and one for
            // both fibers' items.
            ->and($connection->statementCount())->toBe(3)
            ->and(array_map(static fn (LazyLoad $load): string => $load->batch, $loads->since()))->toBe([LazyLoad::RENDER]);
    });

    it('records every model it reads, for the model-leak check', function () {
        $orm = Shelves::sqlite()->orm();
        $orm->recordReads(true);

        $orm->actAs('acme', static fn (): array => $orm->query(Shelf::class)->with('aisle')->where('name', 'Alpha')->fetch());

        expect(array_map(static fn (object $model): string => $model::class, $orm->readsRecorded()))
            ->toBe([Shelf::class, Aisle::class]);
    });
});

describe('scope', function () {
    it('reads inside the read-only scope a loader runs in', function () {
        $orm = Shelves::sqlite()->orm();

        $count = $orm->actAs('acme', static fn (): int => $orm->readOnly(static function () use ($orm): int {
            $shelf = $orm->query(Shelf::class)->first();
            \assert($shelf !== null);
            $shelf->name = 'changed';
            expect(fn () => $orm->save($shelf))->toThrow(ReadOnlyViolation::class);

            return $orm->query(Shelf::class)->count();
        }));

        expect($count)->toBe(4);
    });

    it('reads the tenant of the fiber that runs it, not of the one that built it', function () {
        $orm = Shelves::sqlite()->orm();
        $query = $orm->query(Shelf::class)->orderBy('position');
        /** @var \ArrayObject<string, list<string>> $seen */
        $seen = new \ArrayObject();

        $fiber = static fn (string $tenant): \Fiber => new \Fiber(static function () use ($orm, $query, $tenant, $seen): void {
            $orm->actAs($tenant, static function () use ($query, $tenant, $seen): void {
                \Fiber::suspend();
                $seen[$tenant] = array_map(static fn (Shelf $s): string => $s->name, $query->fetch());
            });
        });

        $acme = $fiber('acme');
        $globex = $fiber('globex');
        $acme->start();
        $globex->start();
        $globex->resume();
        $acme->resume();

        expect($seen->getArrayCopy())->toBe([
            'globex' => ['Delta', 'Echo'],
            'acme' => ['Alpha', 'Bravo', 'Charlie', 'Foxtrot'],
        ]);
    });

    it('carries nothing of a request past the worker\'s reset', function () {
        $orm = Shelves::sqlite()->orm();
        $query = null;

        $orm->switchTenant('acme');
        $query = $orm->query(Shelf::class)->where('position', '>', 0);
        expect($query->count())->toBe(4);

        $orm->flush();

        expect(fn () => $query->count())->toThrow(NoTenantContext::class)
            ->and($orm->identityMap()->count())->toBe(0);
    });

    it('is a value: narrowing one copy never reaches another, in any fiber', function () {
        $orm = Shelves::sqlite()->orm();
        $base = $orm->query(Shelf::class);

        $narrowed = [];
        foreach (['Alpha', 'Bravo'] as $name) {
            $fiber = new \Fiber(static function () use ($base, $name, &$narrowed): void {
                $mine = $base->where('name', $name);
                \Fiber::suspend();
                $narrowed[$name] = $mine;
            });
            $fiber->start();
            $fiber->resume();
        }

        expect($orm->actAs('acme', static fn (): int => $base->count()))->toBe(4)
            ->and($orm->actAs('acme', static fn (): ?string => $narrowed['Alpha']->first()?->name))->toBe('Alpha')
            ->and($orm->actAs('acme', static fn (): ?string => $narrowed['Bravo']->first()?->name))->toBe('Bravo');
    });
});

describe('the read after a write', function () {
    it('reads what was written in the same request, through the map', function () {
        $orm = Shelves::sqlite()->orm();

        $names = $orm->actAs('acme', static function () use ($orm): array {
            $bravo = $orm->query(Shelf::class)->where('name', 'Bravo')->first();
            \assert($bravo !== null);
            $bravo->position = 9;
            $orm->save($bravo);

            return array_map(static fn (Shelf $s): string => $s->name, $orm->query(Shelf::class)->orderBy('position', 'desc')->limit(1)->fetch());
        });

        expect($names)->toBe(['Bravo']);
    });

    it('reads what a write through a query stored, on the model it already handed out', function () {
        $orm = Shelves::sqlite()->orm();

        [$bravo, $again] = $orm->actAs('acme', static function () use ($orm): array {
            $bravo = $orm->query(Shelf::class)->where('name', 'Bravo')->first();
            $orm->query(Shelf::class)->where('name', 'Bravo')->update(['position' => 9]);

            return [$bravo, $orm->query(Shelf::class)->orderBy('position', 'desc')->first()];
        });

        expect($again)->toBe($bravo)
            ->and($bravo?->position)->toBe(9);
    });
});
