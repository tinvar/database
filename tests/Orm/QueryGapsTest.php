<?php

declare(strict_types=1);

/**
 * What ADR 0073 added to the query builder, alike on SQLite, Postgres --
 * under row-level security, as a role that is not a superuser -- and
 * MySQL: aggregates along a path of relations and over a #[BelongsTo],
 * aggregates over a collection inside a report, orHaving(), intersect()
 * and except(), pluck(), aggregates and writes through a unionAll(),
 * update() with a Change, and insert() running #[BeforeSave] methods.
 *
 * Each is held to the tenant on every table it reads: the fixture has
 * globex's item on acme's shelf, acme's shelf on globex's aisle and
 * globex's shelf on acme's aisle, so any of them showing as acme is a
 * leak; across tenants each sees the rest; with no tenant each throws.
 */

namespace Tinvar\Tests\Orm\QueryGaps;

use Tinvar\Orm\Aggregate;
use Tinvar\Orm\Aggregated;
use Tinvar\Orm\Attributes\BeforeSave;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\HasMany;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\TenantScoped;
use Tinvar\Orm\Change;
use Tinvar\Orm\Collection;
use Tinvar\Orm\MappingException;
use Tinvar\Orm\Model;
use Tinvar\Orm\NoTenantContext;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\ReadOnlyViolation;
use Tinvar\Orm\SignedCursors;
use Tinvar\Support\Signer;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Orm\Fixtures\Item;
use Tinvar\Tests\Orm\Fixtures\Shelf;
use Tinvar\Tests\Orm\Fixtures\Shelves;
use Tinvar\Tests\Orm\Fixtures\UnscopedShelf;

require_once __DIR__ . '/Fixtures/shelves.php';

/** The aisles table, with its shelves as a collection: a path of two #[HasMany]. */
#[Table('aisles'), TenantScoped]
final class StockedAisle extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $code;

    /** @var Collection<Shelf> */
    #[HasMany(Shelf::class, foreignKey: 'aisle_id')]
    public Collection $shelves;
}

/** The aisles table read as a model that forgot #[TenantScoped]: the policy's to hold. */
#[Table('aisles')]
final class UnscopedAisle extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $code;

    /** @var Collection<UnscopedShelf> */
    #[HasMany(UnscopedShelf::class, foreignKey: 'aisle_id')]
    public Collection $shelves;
}

/** The aisles table, its code kept trimmed and upper case by a hook. */
#[Table('aisles'), TenantScoped]
final class TidyAisle extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $code;

    #[BeforeSave]
    private function tidy(): void
    {
        $this->code = strtoupper(trim($this->code));
    }
}

/** A hook that reads the key, which a new row has not got until it is minted. */
#[Table('aisles'), TenantScoped]
final class KeyedAisle extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $code;

    #[BeforeSave]
    private function stamp(): void
    {
        $this->code = substr($this->id, 0, 8);
    }
}

/** A report's row, as each database's driver spells its numbers. */
final readonly class Totals
{
    public function __construct(
        public ?string $key = null,
        public int $count = 0,
        public int $cents = 0,
        public ?float $average = null,
        public ?int $dearest = null,
    ) {}
}

/** @param list<Model> $models @return list<string> */
function names(array $models): array
{
    return array_map(static fn (Model $model): string => $model->name ?? $model->code, $models);
}

/**
 * @param list<Aggregated<Model>> $rows
 * @return array<string, array<string, int|float|string|null>>
 */
function byName(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $out[$row->model->name ?? $row->model->code] = $row->values;
    }

    return $out;
}

/** Reads: paths, collections in reports, orHaving(), intersect(), except() and a unionAll()'s columns. */
function readsAlike(Orm $orm): void
{
    $acme = static fn (\Closure $read): mixed => $orm->actAs('acme', $read);
    $across = static fn (\Closure $read): mixed => $orm->acrossTenants($read);

    // A path of two collections: each aisle's shelves' items. As acme,
    // Echo -- globex's shelf on A1 -- and ghost -- globex's item on Alpha
    // -- are not on the way; G1 is globex's aisle.
    $aisles = static fn (): array => byName($orm->query(StockedAisle::class)->orderBy('code')
        ->withCount('shelves.items')->withSum('shelves.items', 'priceCents')->withMax('shelves.items', 'name', as: 'last')
        ->fetchAggregated());
    expect($acme($aisles))->toEqual([
        'A1' => ['shelvesItemsCount' => 2, 'shelvesItemsSumPriceCents' => 350, 'last' => 'apricot'],
        'A2' => ['shelvesItemsCount' => 1, 'shelvesItemsSumPriceCents' => 50, 'last' => 'banana'],
    ])
        ->and($across($aisles))->toEqual([
            'A1' => ['shelvesItemsCount' => 4, 'shelvesItemsSumPriceCents' => 1474, 'last' => 'ghost'],
            'A2' => ['shelvesItemsCount' => 1, 'shelvesItemsSumPriceCents' => 50, 'last' => 'banana'],
            'G1' => ['shelvesItemsCount' => 2, 'shelvesItemsSumPriceCents' => 375, 'last' => 'fig'],
        ])
        ->and(fn () => $aisles())->toThrow(NoTenantContext::class);

    // A #[BelongsTo], one row: Foxtrot's aisle is globex's, so none.
    $shelves = static fn (): array => byName($orm->query(Shelf::class)->orderBy('name')
        ->withCount('aisle')->withMax('aisle', 'code')->fetchAggregated());
    expect($acme($shelves))->toEqual([
        'Alpha' => ['aisleCount' => 1, 'aisleMaxCode' => 'A1'],
        'Bravo' => ['aisleCount' => 1, 'aisleMaxCode' => 'A2'],
        'Charlie' => ['aisleCount' => 1, 'aisleMaxCode' => 'A1'],
        'Foxtrot' => ['aisleCount' => 0, 'aisleMaxCode' => null],
    ])
        // A path of #[BelongsTo]: the table between is held to the tenant
        // too, so fig's shelf is acme's and its aisle is not.
        ->and($acme(static fn (): array => byName($orm->query(Item::class)->orderBy('name')
            ->withMax('shelf.aisle', 'code', as: 'aisle')->fetchAggregated())))
        ->toEqual(['apple' => ['aisle' => 'A1'], 'apricot' => ['aisle' => 'A1'], 'banana' => ['aisle' => 'A2'], 'fig' => ['aisle' => null]])
        ->and($acme(static fn (): array => names($orm->query(StockedAisle::class)
            ->orderByRelation('shelves.items', Aggregate::Sum, 'priceCents', 'desc')->fetch())))->toBe(['A1', 'A2']);

    // Collections inside a report: each group's shelves' items.
    $report = static fn (): array => $orm->query(Shelf::class)->groupBy('aisle.code')->orderBy('aisle.code')
        ->report(Totals::class, [
            'key' => 'aisle.code',
            'count' => [Aggregate::Count, 'items'],
            'cents' => [Aggregate::Sum, 'items.priceCents'],
            'average' => [Aggregate::Avg, 'items.priceCents'],
            'dearest' => [Aggregate::Max, 'items.priceCents'],
        ]);
    expect($acme($report))->toEqual([
        new Totals('A1', 2, 350, 175.0, 250),
        new Totals('A2', 1, 50, 50.0, 50),
        new Totals(null, 1, 300, 300.0, 300),
    ])
        ->and($across($report))->toEqual([
            new Totals('A1', 4, 1474, 368.5, 999),
            new Totals('A2', 1, 50, 50.0, 50),
            new Totals('G1', 2, 375, 187.5, 300),
        ])
        ->and(fn () => $report())->toThrow(NoTenantContext::class)
        // orHaving(), over a collection: two items, or under a dollar.
        ->and($acme(static fn (): array => $orm->query(Shelf::class)->groupBy('aisle.code')->orderBy('aisle.code')
            ->having(Aggregate::Count, 'items', '>=', 2)->orHaving(Aggregate::Sum, 'items.priceCents', '<', 100)
            ->report(Totals::class, ['key' => 'aisle.code'])))
        ->toEqual([new Totals('A1'), new Totals('A2')])
        ->and($acme(static fn (): array => $orm->query(Item::class)->groupBy('shelf.name')->orderBy('shelf.name')
            ->having(Aggregate::Count, null, '>=', 2)->orHaving(Aggregate::Sum, 'priceCents', '<', 100)
            ->report(Totals::class, ['key' => 'shelf.name', 'count' => Aggregate::Count])))
        ->toEqual([new Totals('Alpha', 2), new Totals('Bravo', 1)]);

    // intersect() and except(): an arm naming globex's rows reads none.
    $named = static fn (string ...$names): mixed => $orm->query(Shelf::class)->whereIn('name', $names);
    expect($acme(static fn (): array => names($orm->query(Shelf::class)->where('position', '>=', 2)
        ->intersect($orm->query(Shelf::class)->where('items.priceCents', '>', 0))->orderBy('name')->fetch())))
        ->toBe(['Bravo', 'Foxtrot'])
        ->and($acme(static fn (): array => names($orm->query(Shelf::class)->intersect($named('Delta', 'Echo', 'Bravo'))->fetch())))
        ->toBe(['Bravo'])
        // The orWhere() before it is grouped: Alpha is not let through.
        ->and($acme(static fn (): array => names($orm->query(Shelf::class)->where('name', 'Alpha')->orWhere('name', 'Bravo')
            ->intersect($orm->query(Shelf::class)->where('position', 2))->fetch())))->toBe(['Bravo'])
        ->and($acme(static fn (): array => names($orm->query(Shelf::class)->except($orm->query(Shelf::class)->where('items.name', 'ghost'))
            ->orderBy('name')->fetch())))->toBe(['Alpha', 'Bravo', 'Charlie', 'Foxtrot'])
        ->and($acme(static fn (): array => names($orm->query(Shelf::class)->except($named('Bravo', 'Delta'))->orderBy('name')->fetch())))
        ->toBe(['Alpha', 'Charlie', 'Foxtrot'])
        ->and($acme(static fn (): int => $orm->query(Shelf::class)->except($named('Bravo'))->paginate(1, 2)->total))->toBe(3)
        ->and($across(static fn (): array => names($orm->query(Shelf::class)->intersect($named('Delta', 'Echo', 'Bravo'))->orderBy('name')->fetch())))
        ->toBe(['Bravo', 'Delta', 'Echo'])
        ->and(fn () => $orm->query(Shelf::class)->intersect($named('Bravo'))->count())->toThrow(NoTenantContext::class);

    // A cursor over an intersection pages as any query does.
    $cursors = new SignedCursors(new Signer('test-key', SignedCursors::PURPOSE), 'session');
    $mine = $orm->query(Shelf::class)->except($named('Charlie'))->orderBy('position');
    $first = $acme(static fn (): mixed => $mine->cursorPaginate(2, null, $cursors));
    expect(names($first->items))->toBe(['Alpha', 'Bravo'])
        ->and(names($acme(static fn (): mixed => $mine->cursorPaginate(2, $first->next, $cursors))->items))->toBe(['Foxtrot']);

    // A unionAll()'s own columns: a row both arms read counts twice.
    $both = $orm->query(Shelf::class)->where('name', 'Alpha')
        ->unionAll($orm->query(Shelf::class)->where('position', '<=', 2))
        ->unionAll($named('Delta'));
    expect($acme(static fn (): array => $both->orderBy('name')->pluck('position')))->toEqual([1, 1, 2])
        ->and($acme(static fn (): int|float => $both->sum('position')))->toBe(4)
        ->and($acme(static fn (): mixed => $both->max('position')))->toEqual(2)
        ->and($acme(static fn (): array => $both->distinct()->orderBy('name')->pluck('name')))->toBe(['Alpha', 'Bravo'])
        ->and($across(static fn (): array => $both->orderBy('name')->pluck('name')))->toBe(['Alpha', 'Alpha', 'Bravo', 'Delta', 'Delta', 'Echo'])
        ->and(fn () => $both->pluck('name'))->toThrow(NoTenantContext::class)
        // A cursor names a row by its key and the arm that read it (ADR 0123).
        ->and(names($acme(static fn (): mixed => $both->orderBy('name')->cursorPaginate(2, null, $cursors))->items))->toBe(['Alpha', 'Alpha']);
}

/** Writes: through intersect() and a unionAll(), with a Change, and insert() running hooks. */
function writesAlike(Orm $orm, Shelves $fixture): void
{
    $acme = static fn (\Closure $write): mixed => $orm->actAs('acme', $write);
    $positions = static fn (string $tenant): array => $orm->actAs(
        $tenant,
        static fn (): array => array_combine(
            $orm->query(Shelf::class)->orderBy('name')->pluck('name'),
            $orm->query(Shelf::class)->orderBy('name')->pluck('position'),
        ),
    );
    $globex = ['Delta' => 1, 'Echo' => 2];

    // Echo is globex's, on acme's A1: never written as acme.
    expect($acme(static fn (): int => $orm->query(Shelf::class)->where('aisle.code', 'A1')->update(['position' => Change::multiply(10)])))->toBe(2)
        ->and($positions('acme'))->toEqual(['Alpha' => 10, 'Bravo' => 2, 'Charlie' => 30, 'Foxtrot' => 4])
        ->and($acme(static fn (): int => $orm->query(Shelf::class)->where('aisle.code', 'A1')->update(['position' => Change::add(-25)->atLeast(0)])))->toBe(2)
        ->and($positions('acme'))->toEqual(['Alpha' => 0, 'Bravo' => 2, 'Charlie' => 5, 'Foxtrot' => 4])
        ->and($acme(static fn (): int => $orm->query(Shelf::class)->update(['position' => Change::add(3)->atMost(6)])))->toBe(4)
        ->and($positions('acme'))->toEqual(['Alpha' => 3, 'Bravo' => 5, 'Charlie' => 6, 'Foxtrot' => 6])
        ->and($acme(static fn (): int => $orm->query(Shelf::class)->whereNull('note')->update(['note' => Change::copy('name')])))->toBe(2)
        ->and($acme(static fn (): array => $orm->query(Shelf::class)->orderBy('name')->pluck('note')))->toBe(['top', 'Bravo', 'low', 'Foxtrot'])
        ->and($positions('globex'))->toEqual($globex)
        ->and(fn () => $orm->query(Shelf::class)->update(['position' => Change::multiply(2)]))->toThrow(NoTenantContext::class);

    // Through intersect(), except() and a unionAll(): the rows a read of
    // each reaches, under the tenant, and no arm reaches globex's.
    expect($acme(static fn (): int => $orm->query(Shelf::class)->intersect($orm->query(Shelf::class)->whereIn('name', ['Bravo', 'Delta']))
        ->update(['position' => 20])))->toBe(1)
        ->and($acme(static fn (): int => $orm->query(Shelf::class)->except($orm->query(Shelf::class)->where('position', '<', 10))
        ->increment('position')))->toBe(1)
        ->and($acme(static fn (): int => $orm->query(Shelf::class)->where('name', 'Alpha')
            ->unionAll($orm->query(Shelf::class)->whereIn('name', ['Alpha', 'Echo']))->update(['position' => Change::add(1)])))->toBe(1)
        ->and($positions('acme'))->toEqual(['Alpha' => 4, 'Bravo' => 21, 'Charlie' => 6, 'Foxtrot' => 6])
        ->and($positions('globex'))->toEqual($globex)
        ->and($acme(static fn (): int => $orm->query(Item::class)->where('name', 'fig')
            ->unionAll($orm->query(Item::class)->whereIn('name', ['fig', 'ghost']))->delete()))->toBe(1)
        ->and($orm->acrossTenants(static fn (): array => $orm->query(Item::class)->orderBy('name')->pluck('name')))
        ->toBe(['apple', 'apricot', 'banana', 'date', 'eggplant', 'ghost']);

    // insert() runs the model's hooks on each new row and stamps the tenant
    // after them; upsert() and update() refuse a model that has hooks.
    expect($acme(static fn (): int => $orm->query(TidyAisle::class)->insert([['code' => ' a5 '], ['code' => 'b6']])))->toBe(2)
        ->and($fixture->pdo->query("SELECT code, tenant_id FROM aisles WHERE code IN ('A5', 'B6') ORDER BY code")?->fetchAll(\PDO::FETCH_KEY_PAIR))
        ->toBe(['A5' => 'acme', 'B6' => 'acme'])
        ->and(fn () => $acme(static fn (): int => $orm->query(KeyedAisle::class)->insert([['code' => 'k']])))->toThrow(\Error::class)
        ->and(fn () => $orm->query(TidyAisle::class)->insert([['code' => 'c7']]))->toThrow(NoTenantContext::class)
        ->and(fn () => $acme(static fn (): int => $orm->query(TidyAisle::class)->update(['code' => 'x'])))->toThrow(\LogicException::class, '#[BeforeSave]')
        ->and($fixture->pdo->query("SELECT COUNT(*) FROM aisles WHERE code IN ('k', 'K', 'C7', 'c7', 'x')")?->fetchColumn())->toEqual(0);
}

describe('ADR 0073 on SQLite', function () {
    it('reads along paths, over collections in reports, and through intersections, held to the tenant', function () {
        readsAlike(Shelves::sqlite()->orm());
    });

    it('writes with a Change, through intersections and a unionAll(), and runs hooks on insert(), held to the tenant', function () {
        $fixture = Shelves::sqlite();
        writesAlike($fixture->orm(), $fixture);
    });

    it('holds a Change to the metadata, and a copy to one type', function () {
        $orm = Shelves::sqlite()->orm();
        $update = static fn (array $values): \Closure => static fn (): int => $orm->actAs('acme', static fn (): int => $orm->query(Shelf::class)->update($values));

        expect($update(['note' => Change::copy('position')]))->toThrow(\InvalidArgumentException::class, 'one built-in type')
            ->and($update(['name' => Change::copy('note')]))->toThrow(\InvalidArgumentException::class, 'may be NULL')
            ->and($update(['note' => Change::copy('aisle')]))->toThrow(MappingException::class, 'own')
            ->and($update(['note' => Change::copy('aisle.code')]))->toThrow(MappingException::class)
            ->and($update(['note' => Change::copy('id')]))->toThrow(MappingException::class)
            ->and($update(['note' => Change::multiply(2)]))->toThrow(\InvalidArgumentException::class, 'no number')
            ->and($update(['position' => Change::multiply(1.5)]))->toThrow(\InvalidArgumentException::class, 'whole numbers')
            ->and($update(['position' => Change::add(1)->atLeast(0.5)]))->toThrow(\InvalidArgumentException::class, 'whole numbers')
            ->and($update(['note' => Change::copy('name')->atLeast(1)]))->toThrow(\InvalidArgumentException::class, 'no number')
            ->and($update(['aisle' => Change::add(1)]))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => Change::add(0))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => Change::multiply(INF))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => Change::add(1)->atLeast(5)->atMost(4))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $orm->actAs('acme', static fn (): int => $orm->query(Shelf::class)->insert([['name' => 'x', 'position' => Change::add(1)]])))
            ->toThrow(\InvalidArgumentException::class, 'new row')
            ->and(fn () => $orm->actAs('acme', static fn (): int => $orm->readOnly(static fn (): int => $orm->query(Shelf::class)->update(['position' => Change::add(1)]))))
            ->toThrow(ReadOnlyViolation::class)
            ->and($orm->actAs('acme', static fn (): array => $orm->query(Shelf::class)->orderBy('name')->pluck('position')))->toEqual([1, 2, 3, 4]);
    });

    it('refuses what a collection, an intersection or a unionAll() cannot answer', function () {
        $orm = Shelves::sqlite()->orm();
        $acme = static fn (\Closure $read): mixed => $orm->actAs('acme', $read);
        $grouped = $orm->query(Shelf::class)->groupBy('aisle.code');

        expect(fn () => $grouped->having(Aggregate::Sum, 'items', '>', 1))->toThrow(\LogicException::class, 'collection')
            ->and(fn () => $grouped->having(Aggregate::CountDistinct, 'items.name', '>', 1))->toThrow(\LogicException::class, 'distinct')
            ->and(fn () => $orm->query(Shelf::class)->orHaving(Aggregate::Count, null, '>', 1))->toThrow(\LogicException::class, 'having()')
            ->and(fn () => $orm->query(Shelf::class)->unionAll($orm->query(Shelf::class))->intersect($orm->query(Shelf::class)))
            ->toThrow(\LogicException::class, 'unionAll()')
            ->and(fn () => $orm->query(Shelf::class)->except($orm->query(Shelf::class)->limit(1)))->toThrow(\LogicException::class)
            ->and(fn () => $acme(static fn (): int => $orm->query(Shelf::class)->unionAll($orm->query(Shelf::class))->limit(1)->delete()))
            ->toThrow(\LogicException::class, 'union()');
    });
});

describe('ADR 0073 on Postgres, with row-level security', function () {
    it('reads as SQLite does, under the policy', function () {
        Shelves::onPostgres(static function (Shelves $fixture, PdoConnection $connection): void {
            readsAlike($fixture->orm(connection: $connection));
        });
    });

    it('writes as SQLite does, under the policy', function () {
        Shelves::onPostgres(static function (Shelves $fixture, PdoConnection $connection): void {
            writesAlike($fixture->orm(connection: $connection), $fixture);
        });
    });

    it('holds models that forgot #[TenantScoped] to the tenant along a path, in a report and through a Change', function () {
        Shelves::onPostgres(static function (Shelves $fixture, PdoConnection $connection): void {
            $orm = $fixture->orm(connection: $connection);
            $acme = static fn (\Closure $read): mixed => $orm->actAs('acme', $read);
            $path = static fn (): array => byName($orm->query(UnscopedAisle::class)->orderBy('code')->withCount('shelves.items')->fetchAggregated());
            $report = static fn (): array => $orm->query(UnscopedShelf::class)
                ->report(Totals::class, ['count' => [Aggregate::Count, 'items'], 'cents' => [Aggregate::Sum, 'items.priceCents']]);

            // The builder adds no predicate to these; the policy hides
            // globex's aisle, shelves and items inside every subquery.
            expect($acme($path))->toEqual(['A1' => ['shelvesItemsCount' => 2], 'A2' => ['shelvesItemsCount' => 1]])
                ->and($path())->toBe([])
                ->and($orm->acrossTenants($path))->toEqual([
                    'A1' => ['shelvesItemsCount' => 4], 'A2' => ['shelvesItemsCount' => 1], 'G1' => ['shelvesItemsCount' => 2],
                ])
                ->and($acme($report))->toEqual([new Totals(null, 4, 700)])
                ->and($report())->toEqual([new Totals(null, 0, 0)])
                ->and($acme(static fn (): int => $orm->query(UnscopedShelf::class)->update(['position' => Change::multiply(3)])))->toBe(4)
                ->and($orm->query(UnscopedShelf::class)->update(['position' => Change::multiply(3)]))->toBe(0)
                ->and($orm->acrossTenants(static fn (): array => $orm->query(UnscopedShelf::class)->orderBy('name')->pluck('position')))
                ->toEqual([3, 6, 9, 1, 2, 12]);
        });
    });
})->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

describe('ADR 0073 on MySQL', function () {
    it('reads as SQLite does', function () {
        Shelves::onMySql(static function (Shelves $fixture, PdoConnection $connection): void {
            readsAlike($fixture->orm(connection: $connection));
        });
    });

    it('writes as SQLite does', function () {
        Shelves::onMySql(static function (Shelves $fixture, PdoConnection $connection): void {
            writesAlike($fixture->orm(connection: $connection), $fixture);
        });
    });
})->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');
