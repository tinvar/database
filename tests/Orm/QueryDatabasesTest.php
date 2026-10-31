<?php

declare(strict_types=1);

/**
 * The query builder on Postgres -- with row-level security holding every
 * tenant table, as a role that is not a superuser -- and on MySQL (ADR
 * 0057). Each group is skipped, saying why, without its server
 * (Fixtures/databases.php says how one is found).
 *
 * On Postgres the policy is the second line: the builder's own tenant
 * predicate is the first, and the reads below are what both let through.
 * UnscopedShelf is a model that forgot #[TenantScoped], so the builder adds
 * no predicate at all -- and the policy still holds it to the tenant.
 */

namespace Tinvar\Tests\Orm\QueryDatabases;

use Tinvar\Orm\Aggregate;
use Tinvar\Orm\Aggregated;
use Tinvar\Orm\AsyncConnection;
use Tinvar\Orm\CursorPage;
use Tinvar\Orm\NoTenantContext;
use Tinvar\Orm\Nulls;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Query;
use Tinvar\Orm\SignedCursors;
use Tinvar\Support\Signer;
use Tinvar\Tests\Orm\Fixtures\Brand;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Orm\Fixtures\Item;
use Tinvar\Tests\Orm\Fixtures\Shelf;
use Tinvar\Tests\Orm\Fixtures\Shelves;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Tests\Orm\Fixtures\UnscopedItem;
use Tinvar\Tests\Orm\Fixtures\UnscopedShelf;

require_once __DIR__ . '/Fixtures/shelves.php';

/** @param list<Shelf|Item|Brand|UnscopedShelf> $models @return list<string> */
function names(array $models): array
{
    return array_map(static fn (Shelf|Item|Brand|UnscopedShelf $model): string => $model->name, $models);
}

/** A report's row, as each database's driver spells its numbers. */
final readonly class Tally
{
    public function __construct(public ?string $key = null, public int $count = 0, public int $cents = 0, public ?float $average = null) {}
}

/**
 * @param list<Aggregated<Shelf|UnscopedShelf>> $rows
 * @return array<string, array<string, int|float|string|null>>
 */
function aggregatesByName(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $out[$row->model->name] = $row->values;
    }

    return $out;
}

/**
 * What every database must read alike past ADR 0057 (ADR 0064): a
 * collection's column, aggregates over related rows, reports, distinct
 * values, unions and increments -- each held to the tenant, where any of
 * the fixture's rows across the tenant line showing is a leak.
 */
function aggregatesAlike(Orm $orm): void
{
    $acme = static fn (\Closure $read): mixed => $orm->actAs('acme', $read);

    expect($acme(static fn (): int => $orm->query(Shelf::class)->where('items.priceCents', '>', 900)->count()))->toBe(0)
        ->and($acme(static fn (): array => names($orm->query(Shelf::class)->where('items.priceCents', '>', 0)->orderBy('name')->fetch())))
        ->toBe(['Alpha', 'Bravo', 'Foxtrot'])
        ->and($acme(static fn (): array => aggregatesByName($orm->query(Shelf::class)->whereIn('name', ['Alpha', 'Charlie'])->orderBy('name')
            ->withCount('items')->withSum('items', 'priceCents')->withAvg('items', 'priceCents')->withMax('items', 'priceCents')
            ->fetchAggregated())))
        ->toEqual([
            'Alpha' => ['itemsCount' => 2, 'itemsSumPriceCents' => 350, 'itemsAvgPriceCents' => 175.0, 'itemsMaxPriceCents' => 250],
            'Charlie' => ['itemsCount' => 0, 'itemsSumPriceCents' => 0, 'itemsAvgPriceCents' => null, 'itemsMaxPriceCents' => null],
        ])
        ->and($acme(static fn (): array => names($orm->query(Shelf::class)->orderByRelation('items', Aggregate::Max, 'priceCents', 'desc')->fetch())))
        ->toBe(['Foxtrot', 'Alpha', 'Bravo', 'Charlie'])
        ->and($acme(static fn (): array => $orm->query(Item::class)->groupBy('shelf.aisle.code')->orderBy('shelf.aisle.code')
            ->report(Tally::class, ['key' => 'shelf.aisle.code', 'count' => Aggregate::Count, 'cents' => [Aggregate::Sum, 'priceCents'], 'average' => [Aggregate::Avg, 'priceCents']])))
        ->toEqual([new Tally('A1', 2, 350, 175.0), new Tally('A2', 1, 50, 50.0), new Tally(null, 1, 300, 300.0)])
        ->and($acme(static fn (): array => $orm->query(Item::class)->groupBy('shelf.name')->having(Aggregate::Sum, 'priceCents', '>=', 100)
            ->orderByAggregate(Aggregate::Count, direction: 'desc')->orderBy('shelf.name')
            ->report(Tally::class, ['key' => 'shelf.name', 'count' => Aggregate::Count])))
        ->toEqual([new Tally('Alpha', 2), new Tally('Foxtrot', 1)])
        ->and($acme(static fn (): array => $orm->query(Item::class)->distinct()->orderBy('shelf.aisle.code')->pluck('shelf.aisle.code')))
        ->toBe(['A1', 'A2', null])
        ->and($acme(static fn (): array => names($orm->query(Shelf::class)->where('name', 'Delta')
            ->union($orm->query(Shelf::class)->where('position', '>=', 3))
            ->union($orm->query(Shelf::class)->where('items.name', 'ghost'))
            ->orderBy('name')->fetch())))
        ->toBe(['Charlie', 'Foxtrot'])
        ->and($acme(static fn (): array => names($orm->query(Shelf::class)->where('name', 'Alpha')
            ->unionAll($orm->query(Shelf::class)->where('position', '<=', 2))
            ->unionAll($orm->query(Shelf::class)->where('name', 'Delta'))
            ->orderBy('name', 'desc')->fetch())))
        ->toBe(['Bravo', 'Alpha', 'Alpha'])
        ->and($acme(static fn (): int => $orm->query(Shelf::class)->where('aisle.code', 'A1')->increment('position', 10)))->toBe(2)
        // Through a union, its rows locked first where the database locks
        // rows -- FOR UPDATE beside a UNION in a subquery, on Postgres.
        ->and($acme(static fn (): int => $orm->query(Shelf::class)->where('name', 'Bravo')
            ->union($orm->query(Shelf::class)->where('items.name', 'ghost'))
            ->union($orm->query(Shelf::class)->where('name', 'Charlie'))
            ->orderByRelation('items', Aggregate::Count, direction: 'desc')
            ->limit(5)
            ->decrement('position')))->toBe(2)
        ->and($acme(static fn (): array => $orm->query(Shelf::class)->orderBy('name')->pluck('position')))->toEqual([11, 1, 12, 4])
        ->and($orm->actAs('globex', static fn (): array => $orm->query(Shelf::class)->orderBy('name')->pluck('position')))->toEqual([1, 2]);
}

/**
 * What every database must read alike: filters, joins, subqueries,
 * aggregates and both kinds of page, each under its tenant.
 */
function readsAlike(Orm $orm): void
{
    $acme = static fn (\Closure $read): mixed => $orm->actAs('acme', $read);

    expect($acme(static fn (): array => names($orm->query(Shelf::class)->where('position', '>', 1)->orderBy('position', 'desc')->fetch())))
        ->toBe(['Foxtrot', 'Charlie', 'Bravo'])
        ->and($acme(static fn (): array => names($orm->query(Shelf::class)->whereNull('aisle.code')->fetch())))->toBe(['Foxtrot'])
        ->and($acme(static fn (): array => names($orm->query(Shelf::class)->where('aisle.code', 'A1')->orderBy('name')->fetch())))->toBe(['Alpha', 'Charlie'])
        ->and($acme(static fn (): int => $orm->query(Shelf::class)->whereHas('items', static fn (Query $q): Query => $q->where('priceCents', '>', 900))->count()))->toBe(0)
        ->and($acme(static fn (): array => names($orm->query(Shelf::class)->whereDoesntHave('items')->fetch())))->toBe(['Charlie'])
        ->and($acme(static fn (): array => names($orm->query(Shelf::class)->whereRaw('position * ? > ?', [10, 25])->orderBy('name')->fetch())))->toBe(['Charlie', 'Foxtrot'])
        // A float, which PDO binds as text: compared as a number on each,
        // not as a string on SQLite nor refused for an integer on Postgres.
        ->and($acme(static fn (): array => names($orm->query(Shelf::class)->whereRaw('position * ? > ?', [1.5, 4.0])->orderBy('name')->fetch())))->toBe(['Charlie', 'Foxtrot'])
        ->and($acme(static fn (): array => names($orm->query(Shelf::class)->where('position', '>', 2.5)->orderBy('name')->fetch())))->toBe(['Charlie', 'Foxtrot'])
        ->and($acme(static fn (): array => names($orm->query(Shelf::class)->whereIn('position', [3.0, 4.5])->orderBy('name')->fetch())))->toBe(['Charlie'])
        ->and($acme(static fn (): int|float => $orm->query(Item::class)->sum('priceCents')))->toBe(700)
        ->and($acme(static fn (): array => array_map('strval', $orm->query(Item::class)->orderBy('priceCents')->limit(2)->offset(1)->pluck('priceCents'))))->toBe(['100', '250'])
        ->and($acme(static fn (): array => names($orm->query(Shelf::class)->orderBy('position')->paginate(2, 3)->items)))->toBe(['Foxtrot'])
        ->and($orm->actAs('globex', static fn (): array => names($orm->query(Shelf::class)->orderBy('name')->fetch())))->toBe(['Delta', 'Echo'])
        ->and($orm->acrossTenants(static fn (): int => $orm->query(Shelf::class)->count()))->toBe(6)
        ->and(fn () => $orm->query(Shelf::class)->count())->toThrow(NoTenantContext::class)
        ->and(names($orm->query(Brand::class)->orderBy('rank', 'desc')->fetch()))->toBe(['Xi', 'Ypsilon', 'Zeta']);

    // NULLs where the query says, not where each database would put them:
    // Postgres sorts one as larger than every value, SQLite and MySQL as
    // smaller.
    $byNote = static fn (string $direction, ?Nulls $nulls = null): array => $acme(
        static fn (): array => names($orm->query(Shelf::class)->orderBy('note', $direction, $nulls)->orderBy('name')->fetch()),
    );
    expect($byNote('asc'))->toBe(['Charlie', 'Alpha', 'Bravo', 'Foxtrot'])
        ->and($byNote('desc'))->toBe(['Alpha', 'Charlie', 'Bravo', 'Foxtrot'])
        ->and($byNote('asc', Nulls::First))->toBe(['Bravo', 'Foxtrot', 'Charlie', 'Alpha'])
        ->and($byNote('desc', Nulls::First))->toBe(['Bravo', 'Foxtrot', 'Alpha', 'Charlie'])
        // A joined column is NULL where the join finds no row the tenant's.
        ->and($acme(static fn (): array => names($orm->query(Shelf::class)->orderBy('aisle.code', 'desc')->orderBy('name')->fetch())))
        ->toBe(['Bravo', 'Alpha', 'Charlie', 'Foxtrot']);

    $cursors = new SignedCursors(new Signer('test-key', SignedCursors::PURPOSE), 'session');
    $query = $orm->query(Shelf::class)->orderBy('position', 'desc');
    $first = $acme(static fn (): CursorPage => $query->cursorPaginate(3, null, $cursors));
    $second = $acme(static fn (): CursorPage => $query->cursorPaginate(3, $first->next, $cursors));
    $back = $acme(static fn (): CursorPage => $query->cursorPaginate(3, $second->previous, $cursors));

    expect(names($first->items))->toBe(['Foxtrot', 'Charlie', 'Bravo'])
        ->and(names($second->items))->toBe(['Alpha'])
        ->and(names($back->items))->toBe(['Foxtrot', 'Charlie', 'Bravo']);
}

/**
 * Each function a raw condition may call (Expression::FUNCTIONS), as every
 * database runs it. MySQL reads a built-in function's name followed by a
 * space as an identifier, so `TRIM (name)` -- which the compiler wrote,
 * a space between every token -- was a syntax error there.
 */
function callsEachFunction(Orm $orm): void
{
    $acme = static fn (string $sql, array $bindings): array => $orm->actAs(
        'acme',
        static fn (): array => names($orm->query(Shelf::class)->whereRaw($sql, $bindings)->orderBy('name')->fetch()),
    );

    expect($acme('LOWER(name) = ?', ['alpha']))->toBe(['Alpha'])
        ->and($acme('UPPER(name) = ?', ['ALPHA']))->toBe(['Alpha'])
        ->and($acme('COALESCE(note, ?) = ?', ['none', 'none']))->toBe(['Bravo', 'Foxtrot'])
        ->and($acme('NULLIF(position, ?) IS NULL', [1]))->toBe(['Alpha'])
        ->and($acme('ABS(position - ?) = ?', [3, 0]))->toBe(['Charlie'])
        ->and($acme('LENGTH(name) = ?', [5]))->toBe(['Alpha', 'Bravo'])
        ->and($acme('ROUND(position) = ?', [3]))->toBe(['Charlie'])
        ->and($acme('TRIM(name) = ?', ['Alpha']))->toBe(['Alpha']);
}

describe('on Postgres, with row-level security', function () {
    it('reads as SQLite does, under the policy', function () {
        Shelves::onPostgres(static function (Shelves $fixture, PdoConnection $connection): void {
            readsAlike($fixture->orm(connection: $connection));
        });
    });

    it('groups, aggregates, unions and increments as SQLite does, under the policy', function () {
        Shelves::onPostgres(static function (Shelves $fixture, PdoConnection $connection): void {
            aggregatesAlike($fixture->orm(connection: $connection));
        });
    });

    it('calls each function a raw condition may', function () {
        Shelves::onPostgres(static function (Shelves $fixture, PdoConnection $connection): void {
            callsEachFunction($fixture->orm(connection: $connection));
        });
    });

    it('holds a model that forgot #[TenantScoped] to the tenant through every way ADR 0064 reads and writes', function () {
        Shelves::onPostgres(static function (Shelves $fixture, PdoConnection $connection): void {
            $orm = $fixture->orm(connection: $connection);
            $acme = static fn (\Closure $read): mixed => $orm->actAs('acme', $read);
            $dear = static fn (): int => $orm->query(UnscopedShelf::class)->where('items.priceCents', '>', 900)->count();
            $alpha = static fn (): array => aggregatesByName($orm->query(UnscopedShelf::class)->where('name', 'Alpha')
                ->withCount('items')->withMax('items', 'priceCents')->fetchAggregated());
            $tally = static fn (): array => $orm->query(UnscopedItem::class)
                ->report(Tally::class, ['count' => Aggregate::Count, 'cents' => [Aggregate::Sum, 'priceCents']]);

            // ghost is globex's item on acme's Alpha: the builder adds no
            // predicate to these, and the policy hides it inside every
            // subquery, every arm and every group.
            expect($acme($dear))->toBe(0)
                ->and($orm->acrossTenants($dear))->toBe(1)
                ->and($acme($alpha))->toBe(['Alpha' => ['itemsCount' => 2, 'itemsMaxPriceCents' => 250]])
                ->and($acme(static fn (): array => names($orm->query(UnscopedShelf::class)
                    ->orderByRelation('items', Aggregate::Max, 'priceCents', 'desc')->orderBy('name')->limit(1)->fetch())))
                ->toBe(['Foxtrot'])
                ->and($acme($tally))->toEqual([new Tally(null, 4, 700)])
                ->and($tally())->toEqual([new Tally(null, 0, 0)])
                ->and($acme(static fn (): array => $orm->query(UnscopedShelf::class)->distinct()->orderBy('position')->pluck('position')))
                ->toEqual([1, 2, 3, 4])
                ->and($acme(static fn (): int => $orm->query(UnscopedShelf::class)->where('name', 'Delta')
                    ->union($orm->query(UnscopedShelf::class)->where('name', 'Echo'))->count()))->toBe(0)
                ->and($acme(static fn (): int => $orm->query(UnscopedShelf::class)
                    ->unionAll($orm->query(UnscopedShelf::class))->count()))->toBe(8)
                // A write: the tenant's rows alone, and none with no tenant.
                ->and($acme(static fn (): int => $orm->query(UnscopedItem::class)->increment('priceCents')))->toBe(4)
                ->and($orm->query(UnscopedItem::class)->increment('priceCents'))->toBe(0)
                ->and($orm->acrossTenants(static fn (): int => $orm->query(UnscopedItem::class)->sum('priceCents')))->toBe(700 + 4 + 1199);
        });
    });

    it('holds a model that forgot #[TenantScoped] to the tenant, through every way the builder reads', function () {
        Shelves::onPostgres(static function (Shelves $fixture, PdoConnection $connection): void {
            $orm = $fixture->orm(connection: $connection);
            $acme = static fn (\Closure $read): mixed => $orm->actAs('acme', $read);

            expect($acme(static fn (): array => names($orm->query(UnscopedShelf::class)->orderBy('name')->fetch())))
                ->toBe(['Alpha', 'Bravo', 'Charlie', 'Foxtrot'])
                ->and($acme(static fn (): int => $orm->query(UnscopedShelf::class)->count()))->toBe(4)
                ->and($acme(static fn (): bool => $orm->query(UnscopedShelf::class)->where('name', 'Delta')->exists()))->toBeFalse()
                ->and($acme(static fn (): array => $orm->query(UnscopedShelf::class)->orderBy('name')->pluck('name')))
                ->toBe(['Alpha', 'Bravo', 'Charlie', 'Foxtrot'])
                ->and($acme(static fn (): int => $orm->query(UnscopedShelf::class)->paginate(1, 10)->total))->toBe(4)
                // With no tenant, the builder has no predicate to fail closed
                // with -- the model never asked for one -- and the policy
                // matches no row.
                ->and($orm->query(UnscopedShelf::class)->count())->toBe(0)
                ->and($orm->acrossTenants(static fn (): int => $orm->query(UnscopedShelf::class)->count()))->toBe(6);
        });
    });

    it('holds a read-only scope\'s reads to the tenant of the fiber that runs them', function () {
        Shelves::onPostgres(static function (Shelves $fixture, PdoConnection $connection): void {
            $orm = $fixture->orm(connection: $connection);
            /** @var \ArrayObject<string, list<string>> $seen */
            $seen = new \ArrayObject();

            $fiber = static fn (string $tenant): \Fiber => new \Fiber(static function () use ($orm, $tenant, $seen): void {
                $orm->actAs($tenant, static fn (): mixed => $orm->readOnly(static function () use ($orm, $tenant, $seen): void {
                    \Fiber::suspend();
                    $seen[$tenant] = names($orm->query(UnscopedShelf::class)->orderBy('name')->fetch());
                }));
            });

            $acme = $fiber('acme');
            $globex = $fiber('globex');
            $acme->start();
            $globex->start();
            $acme->resume();
            $globex->resume();

            expect($seen->getArrayCopy())->toBe([
                'acme' => ['Alpha', 'Bravo', 'Charlie', 'Foxtrot'],
                'globex' => ['Delta', 'Echo'],
            ]);
        });
    });

    it('widens the policy and the predicate together inside withinSubtree()', function () {
        Shelves::onPostgres(static function (Shelves $fixture, PdoConnection $connection): void {
            $orm = $fixture->orm(Tenancy::tree(['globex' => 'acme']), $connection);
            $within = static fn (\Closure $read): mixed => $orm->actAs('acme', static fn (): mixed => $orm->grantSubtree(
                static fn (): mixed => $orm->withinSubtree($read),
            ));

            expect($within(static fn (): int => $orm->query(Shelf::class)->count()))->toBe(6)
                ->and($within(static fn (): int => $orm->query(UnscopedShelf::class)->count()))->toBe(6)
                ->and($within(static fn (): array => names($orm->query(Shelf::class)->where('aisle.code', 'A1')->orderBy('name')->fetch())))
                ->toBe(['Alpha', 'Charlie', 'Echo'])
                ->and($orm->actAs('acme', static fn (): int => $orm->query(UnscopedShelf::class)->count()))->toBe(4);
        });
    });
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

describe('on MySQL', function () {
    it('reads as SQLite does', function () {
        Shelves::onMySql(static function (Shelves $fixture, PdoConnection $connection): void {
            readsAlike($fixture->orm(connection: $connection));
        });
    });

    it('groups, aggregates, unions and increments as SQLite does', function () {
        Shelves::onMySql(static function (Shelves $fixture, PdoConnection $connection): void {
            aggregatesAlike($fixture->orm(connection: $connection));
        });
    });

    it('calls each function a raw condition may', function () {
        Shelves::onMySql(static function (Shelves $fixture, PdoConnection $connection): void {
            callsEachFunction($fixture->orm(connection: $connection));
        });
    });
})->group('mysql')->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');

describe('on Postgres through the async driver, with row-level security', function () {
    it('reads as SQLite does, under the policy', function () {
        Shelves::onPostgresAsync(static function (Shelves $fixture, AsyncConnection $connection): void {
            readsAlike($fixture->orm(connection: $connection));
        });
    });

    it('groups, aggregates, unions and increments as SQLite does, under the policy', function () {
        Shelves::onPostgresAsync(static function (Shelves $fixture, AsyncConnection $connection): void {
            aggregatesAlike($fixture->orm(connection: $connection));
        });
    });
})->group('pgsql')->skip(Databases::asyncPostgresMissing() !== null, Databases::asyncPostgresMissing() ?? '');

describe('on MySQL through the async driver', function () {
    it('reads as SQLite does', function () {
        Shelves::onMySqlAsync(static function (Shelves $fixture, AsyncConnection $connection): void {
            readsAlike($fixture->orm(connection: $connection));
        });
    });

    it('groups, aggregates, unions and increments as SQLite does', function () {
        Shelves::onMySqlAsync(static function (Shelves $fixture, AsyncConnection $connection): void {
            aggregatesAlike($fixture->orm(connection: $connection));
        });
    });

    it('calls each function a raw condition may', function () {
        Shelves::onMySqlAsync(static function (Shelves $fixture, AsyncConnection $connection): void {
            callsEachFunction($fixture->orm(connection: $connection));
        });
    });
})->group('mysql')->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');

describe('on SQLite', function () {
    it('reads alike', function () {
        readsAlike(Shelves::sqlite()->orm());
    });

    it('groups, aggregates, unions and increments alike', function () {
        aggregatesAlike(Shelves::sqlite()->orm());
    });

    it('calls each function a raw condition may, its name against its parenthesis', function () {
        $shelves = Shelves::sqlite();
        $connection = $shelves->connection();
        callsEachFunction($shelves->orm(connection: $connection));

        $log = $connection->statementLog();
        expect($log[array_key_last($log)])->toContain('TRIM(')->not->toContain('TRIM (');
    });
});
