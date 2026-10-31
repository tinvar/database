<?php

declare(strict_types=1);

/**
 * The query builder past ADR 0057 (ADR 0064): filters on a #[HasMany]'s
 * column, sorts by and reads aggregates over a row's related rows,
 * reports grouped into a class, distinct values, unions, and writes that
 * add to a column.
 *
 * The fixture's rows point across the tenant line -- `ghost` is globex's
 * item on acme's Alpha, at 999 cents; Foxtrot is acme's shelf on globex's
 * aisle; Echo is globex's shelf on acme's aisle -- so each feature is read
 * as acme, where any of them showing is a leak, and across tenants, where
 * it shows.
 */

namespace Tinvar\Tests\Orm\QueryAggregates;

use Tinvar\Orm\Aggregate;
use Tinvar\Orm\Aggregated;
use Tinvar\Orm\Direction;
use Tinvar\Orm\ForeignTenantRow;
use Tinvar\Orm\Increment;
use Tinvar\Orm\MappingException;
use Tinvar\Orm\NoTenantContext;
use Tinvar\Orm\Orm;
use Tinvar\Orm\Query;
use Tinvar\Orm\SignedCursors;
use Tinvar\Support\Signer;
use Tinvar\Tests\Orm\Fixtures\Brand;
use Tinvar\Tests\Orm\Fixtures\Item;
use Tinvar\Tests\Orm\Fixtures\Shelf;
use Tinvar\Tests\Orm\Fixtures\Shelves;

require_once __DIR__ . '/Fixtures/shelves.php';

/** @param list<Shelf|Item|Brand> $models @return list<string> */
function names(array $models): array
{
    return array_map(static fn (Shelf|Item|Brand $model): string => $model->name, $models);
}

/** @param list<Aggregated<Shelf>> $rows @return array<string, array<string, int|float|string|null>> */
function byName(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $out[$row->model->name] = $row->values;
    }

    return $out;
}

final readonly class AisleTally
{
    public function __construct(public ?string $code, public int $items, public int $cents) {}
}

final readonly class ShelfTally
{
    public function __construct(public string $shelf, public int $items) {}
}

final readonly class Totals
{
    public function __construct(
        public int $items,
        public int $cents,
        public ?float $average,
        public ?string $cheapest,
        public int $shelves = -1,
    ) {}
}

final readonly class Code
{
    public function __construct(public ?string $code) {}
}

final readonly class NoteTally
{
    public function __construct(public ?Note $note, public int $shelves) {}
}

final readonly class Typed
{
    public function __construct(public int $value) {}
}

final readonly class Dated
{
    public function __construct(public \DateTimeImmutable $value) {}
}

enum Note: string
{
    case Top = 'top';
    case Low = 'low';
}

describe('a filter on a collection\'s column', function () {
    it('reads as an EXISTS over the related rows, each row once', function () {
        $fixture = Shelves::sqlite();
        $connection = $fixture->connection();
        $orm = $fixture->orm(connection: $connection);
        $acme = static fn (\Closure $read): mixed => $orm->actAs('acme', $read);

        expect($acme(static fn (): array => names($orm->query(Shelf::class)->where('items.priceCents', '>', 200)->orderBy('name')->fetch())))
            ->toBe(['Alpha', 'Foxtrot'])
            // Alpha has two items over 0, and is read once.
            ->and($acme(static fn (): array => names($orm->query(Shelf::class)->where('items.priceCents', '>', 0)->orderBy('name')->fetch())))
            ->toBe(['Alpha', 'Bravo', 'Foxtrot'])
            ->and($acme(static fn (): int => $orm->query(Shelf::class)->where('items.priceCents', '>', 0)->count()))->toBe(3)
            ->and($acme(static fn (): array => names($orm->query(Shelf::class)->whereIn('items.name', ['banana', 'ghost'])->fetch())))
            ->toBe(['Bravo'])
            ->and($acme(static fn (): array => names($orm->query(Shelf::class)->where('position', 3)->orWhere('items.name', 'fig')->orderBy('name')->fetch())))
            ->toBe(['Charlie', 'Foxtrot'])
            ->and($acme(static fn (): array => names($orm->query(Shelf::class)->whereNotNull('items.name')->orderBy('name')->fetch())))
            ->toBe(['Alpha', 'Bravo', 'Foxtrot'])
            // Through a #[BelongsTo] and then a #[HasMany]: items on a shelf
            // that holds an apple.
            ->and($acme(static fn (): array => names($orm->query(Item::class)->where('shelf.items.name', 'apple')->orderBy('name')->fetch())))
            ->toBe(['apple', 'apricot']);

        $sql = implode("\n", $connection->statementLog());
        expect($sql)->toContain('EXISTS (SELECT 1 FROM "items"')
            ->and($sql)->not->toContain('JOIN "items"');
    });

    it('never finds another tenant\'s child', function () {
        $orm = Shelves::sqlite()->orm();
        $dear = static fn (): array => names($orm->query(Shelf::class)->where('items.priceCents', '>', 900)->fetch());

        expect($orm->actAs('acme', $dear))->toBe([])
            ->and($orm->acrossTenants($dear))->toBe(['Alpha'])
            ->and($orm->actAs('acme', static fn (): int => $orm->query(Shelf::class)->where('items.name', 'ghost')->count()))->toBe(0)
            ->and(fn () => $orm->query(Shelf::class)->where('items.name', 'ghost')->count())->toThrow(NoTenantContext::class);
    });
});

describe('aggregates over a row\'s related rows', function () {
    it('reads them beside each model, in one statement', function () {
        $fixture = Shelves::sqlite();
        $connection = $fixture->connection();
        $orm = $fixture->orm(connection: $connection);

        $rows = $orm->actAs('acme', static fn (): array => $orm->query(Shelf::class)
            ->withCount('items')
            ->withSum('items', 'priceCents')
            ->withMax('items', 'name')
            ->withAvg('items', 'priceCents', as: 'average')
            ->withCount('items', static fn (Query $q): Query => $q->where('priceCents', '>', 200), as: 'dear')
            ->orderBy('name')
            ->fetchAggregated());

        expect(byName($rows))->toBe([
            'Alpha' => ['itemsCount' => 2, 'itemsSumPriceCents' => 350, 'itemsMaxName' => 'apricot', 'average' => 175.0, 'dear' => 1],
            'Bravo' => ['itemsCount' => 1, 'itemsSumPriceCents' => 50, 'itemsMaxName' => 'banana', 'average' => 50.0, 'dear' => 0],
            'Charlie' => ['itemsCount' => 0, 'itemsSumPriceCents' => 0, 'itemsMaxName' => null, 'average' => null, 'dear' => 0],
            'Foxtrot' => ['itemsCount' => 1, 'itemsSumPriceCents' => 300, 'itemsMaxName' => 'fig', 'average' => 300.0, 'dear' => 1],
        ])
            ->and($connection->statementCount())->toBe(1)
            ->and($rows[0]->int('itemsCount'))->toBe(2)
            ->and(fn () => $rows[0]->value('nothing'))->toThrow(\OutOfBoundsException::class);

        // The models are the identity map's, whole, as find() answers them.
        [$read, $found] = $orm->actAs('acme', static function () use ($orm): array {
            $read = $orm->query(Shelf::class)->withCount('items')->orderBy('name')->fetchAggregated()[0]->model;

            return [$read, $orm->find(Shelf::class, $read->id)];
        });
        expect($found)->toBe($read)
            ->and($read->isDirty())->toBeFalse();
    });

    it('counts another tenant\'s child only across tenants', function () {
        $orm = Shelves::sqlite()->orm();
        $alpha = static fn (): array => byName($orm->query(Shelf::class)->where('name', 'Alpha')->withCount('items')->withMax('items', 'priceCents')->fetchAggregated());

        expect($orm->actAs('acme', $alpha))->toBe(['Alpha' => ['itemsCount' => 2, 'itemsMaxPriceCents' => 250]])
            ->and($orm->acrossTenants($alpha))->toBe(['Alpha' => ['itemsCount' => 3, 'itemsMaxPriceCents' => 999]])
            ->and(fn () => $orm->query(Shelf::class)->withCount('items')->fetchAggregated())->toThrow(NoTenantContext::class);
    });

    it('sorts rows by an aggregate of their related rows, never by another tenant\'s', function () {
        $orm = Shelves::sqlite()->orm();
        $byDearest = static fn (): array => names($orm->query(Shelf::class)
            ->orderByRelation('items', Aggregate::Max, 'priceCents', 'desc')
            ->orderBy('name')
            ->fetch());

        // Charlie has none, so no MAX: last, as a NULL sorts by default.
        expect($orm->actAs('acme', $byDearest))->toBe(['Foxtrot', 'Alpha', 'Bravo', 'Charlie'])
            ->and($orm->acrossTenants($byDearest))->toBe(['Alpha', 'Foxtrot', 'Echo', 'Delta', 'Bravo', 'Charlie'])
            ->and($orm->actAs('acme', static fn (): array => names($orm->query(Shelf::class)
                ->orderByRelation('items', Aggregate::Sum, 'priceCents')
                ->orderBy('name')
                ->fetch())))
            ->toBe(['Charlie', 'Bravo', 'Foxtrot', 'Alpha'])
            ->and($orm->actAs('acme', static fn (): array => names($orm->query(Shelf::class)
                ->orderByRelation('items', Aggregate::Count, direction: Direction::Desc, constraint: static fn (Query $q): Query => $q->where('priceCents', '<', 200))
                ->orderBy('name')
                ->limit(2)
                ->fetch())))
            ->toBe(['Alpha', 'Bravo']);
    });

    it('refuses what is no relation, a name read twice, and fetch() dropping them', function () {
        $orm = Shelves::sqlite()->orm();

        expect(fn () => $orm->query(Shelf::class)->withCount('nothing'))->toThrow(MappingException::class)
            ->and(fn () => $orm->query(Shelf::class)->withCount('items.nothing'))->toThrow(MappingException::class)
            ->and(fn () => $orm->query(Shelf::class)->withSum('items', 'secret'))->toThrow(MappingException::class)
            ->and(fn () => $orm->query(Shelf::class)->withSum('items.shelf', 'nothing'))->toThrow(MappingException::class)
            ->and(fn () => $orm->query(Shelf::class)->withCount('items')->withCount('items'))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $orm->query(Shelf::class)->withAggregate('items', Aggregate::Sum))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $orm->actAs('acme', static fn (): array => $orm->query(Shelf::class)->withCount('items')->fetch()))
            ->toThrow(\LogicException::class)
            ->and(fn () => $orm->query(Shelf::class)->withCount('items', static fn (Query $q): Query => $q->orderBy('name')))
            ->toThrow(\LogicException::class);
    });
});

describe('reports', function () {
    it('groups through joins held to the tenant, into a class of the caller\'s', function () {
        $orm = Shelves::sqlite()->orm();
        $report = static fn (): array => $orm->query(Item::class)
            ->groupBy('shelf.aisle.code')
            ->orderBy('shelf.aisle.code')
            ->report(AisleTally::class, [
                'code' => 'shelf.aisle.code',
                'items' => Aggregate::Count,
                'cents' => [Aggregate::Sum, 'priceCents'],
            ]);

        // fig is on Foxtrot, which is on globex's aisle: as acme, no aisle.
        expect($orm->actAs('acme', $report))->toEqual([
            new AisleTally('A1', 2, 350),
            new AisleTally('A2', 1, 50),
            new AisleTally(null, 1, 300),
        ])
            ->and($orm->acrossTenants($report))->toEqual([
                new AisleTally('A1', 4, 1474),
                new AisleTally('A2', 1, 50),
                new AisleTally('G1', 2, 375),
            ])
            ->and(fn () => $report())->toThrow(NoTenantContext::class);
    });

    it('keeps groups by HAVING and sorts them by an aggregate', function () {
        $orm = Shelves::sqlite()->orm();

        expect($orm->actAs('acme', static fn (): array => $orm->query(Item::class)
            ->groupBy('shelf.name')
            ->having(Aggregate::Count, null, '>', 1)
            ->report(ShelfTally::class, ['shelf' => 'shelf.name', 'items' => Aggregate::Count])))
            ->toEqual([new ShelfTally('Alpha', 2)])
            ->and($orm->actAs('acme', static fn (): array => $orm->query(Item::class)
                ->groupBy('shelf.name')
                ->having(Aggregate::Sum, 'priceCents', '>=', 100)
                ->orderByAggregate(Aggregate::Sum, 'priceCents', 'desc')
                ->report(ShelfTally::class, ['shelf' => 'shelf.name', 'items' => Aggregate::Count])))
            ->toEqual([new ShelfTally('Alpha', 2), new ShelfTally('Foxtrot', 1)]);
    });

    it('reads one row of aggregates, a distinct set of columns, or some columns of each row', function () {
        $orm = Shelves::sqlite()->orm();
        $acme = static fn (\Closure $read): mixed => $orm->actAs('acme', $read);

        expect($acme(static fn (): array => $orm->query(Item::class)->report(Totals::class, [
            'items' => Aggregate::Count,
            'cents' => [Aggregate::Sum, 'priceCents'],
            'average' => [Aggregate::Avg, 'priceCents'],
            'cheapest' => [Aggregate::Min, 'name'],
            'shelves' => [Aggregate::CountDistinct, 'shelf'],
        ])))->toEqual([new Totals(4, 700, 175.0, 'apple', 3)])
            ->and($acme(static fn (): array => $orm->query(Item::class)->where('name', 'nothing')->report(Totals::class, [
                'items' => Aggregate::Count,
                'cents' => [Aggregate::Sum, 'priceCents'],
                'average' => [Aggregate::Avg, 'priceCents'],
                'cheapest' => [Aggregate::Min, 'name'],
            ])))->toEqual([new Totals(0, 0, null, null)])
            ->and($acme(static fn (): array => $orm->query(Shelf::class)->distinct()->orderBy('aisle.code')->report(Code::class, ['code' => 'aisle.code'])))
            ->toEqual([new Code('A1'), new Code('A2'), new Code(null)])
            ->and($acme(static fn (): array => $orm->query(Shelf::class)->orderBy('position')->limit(2)->report(Code::class, ['code' => 'name'])))
            ->toEqual([new Code('Alpha'), new Code('Bravo')])
            ->and($acme(static fn (): array => $orm->query(Shelf::class)->groupBy('note')->orderBy('note')->report(NoteTally::class, [
                'note' => 'note',
                'shelves' => Aggregate::Count,
            ])))->toEqual([new NoteTally(Note::Low, 1), new NoteTally(Note::Top, 1), new NoteTally(null, 2)]);
    });

    it('refuses a class it cannot fill, and a value that is not its type', function (\Closure $report, string $exception) {
        $orm = Shelves::sqlite()->orm();

        expect(fn () => $orm->actAs('acme', static fn (): mixed => $report($orm->query(Shelf::class))))->toThrow($exception);
    })->with([
        'a model' => [static fn (Query $q): mixed => $q->report(Brand::class, ['name' => 'name']), \LogicException::class],
        'no such parameter' => [static fn (Query $q): mixed => $q->report(Code::class, ['code' => 'name', 'other' => 'name']), \InvalidArgumentException::class],
        'a parameter left empty' => [static fn (Query $q): mixed => $q->report(ShelfTally::class, ['shelf' => 'name']), \InvalidArgumentException::class],
        'a type no column is' => [static fn (Query $q): mixed => $q->report(Dated::class, ['value' => 'name']), \InvalidArgumentException::class],
        'text for an int' => [static fn (Query $q): mixed => $q->report(Typed::class, ['value' => 'name']), \UnexpectedValueException::class],
        'NULL for an int' => [static fn (Query $q): mixed => $q->where('name', 'Bravo')->report(Typed::class, ['value' => 'note']), \UnexpectedValueException::class],
        'an unknown column' => [static fn (Query $q): mixed => $q->report(Code::class, ['code' => 'password']), MappingException::class],
        'a collection\'s column' => [static fn (Query $q): mixed => $q->report(Code::class, ['code' => 'items.name']), MappingException::class],
        'a column beside aggregates, ungrouped' => [static fn (Query $q): mixed => $q->report(ShelfTally::class, ['shelf' => 'name', 'items' => Aggregate::Count]), \LogicException::class],
        'a column it does not group by' => [static fn (Query $q): mixed => $q->groupBy('note')->report(ShelfTally::class, ['shelf' => 'name', 'items' => Aggregate::Count]), \LogicException::class],
        'a sort by what it does not group by' => [static fn (Query $q): mixed => $q->groupBy('note')->orderBy('name')->report(Code::class, ['code' => 'note']), \LogicException::class],
        'HAVING with no group' => [static fn (Query $q): mixed => $q->having(Aggregate::Count, null, '>', 1)->report(Typed::class, ['value' => Aggregate::Count]), \LogicException::class],
        'an aggregate that reads a column, given none' => [static fn (Query $q): mixed => $q->report(Typed::class, ['value' => Aggregate::Sum]), \InvalidArgumentException::class],
        'an aggregate spelled otherwise' => [static fn (Query $q): mixed => $q->report(Typed::class, ['value' => ['sum', 'position']]), \InvalidArgumentException::class],
        'a sort by related rows' => [static fn (Query $q): mixed => $q->orderByRelation('items', Aggregate::Count)->report(Typed::class, ['value' => Aggregate::Count]), \LogicException::class],
        'a list' => [static fn (Query $q): mixed => $q->report(Code::class, ['name']), \InvalidArgumentException::class],
    ]);

    it('reads no models: fetch() and the rest refuse a grouped query', function () {
        $orm = Shelves::sqlite()->orm();
        $grouped = $orm->query(Shelf::class)->groupBy('note');

        expect(fn () => $orm->actAs('acme', static fn (): array => $grouped->fetch()))->toThrow(\LogicException::class)
            ->and(fn () => $orm->actAs('acme', static fn (): int => $grouped->count()))->toThrow(\LogicException::class)
            ->and(fn () => $orm->actAs('acme', static fn (): array => $grouped->pluck('name')))->toThrow(\LogicException::class)
            ->and(fn () => $orm->actAs('acme', static fn (): int => $grouped->update(['name' => 'x'])))->toThrow(\LogicException::class)
            ->and(fn () => $orm->actAs('acme', static fn (): array => $orm->query(Shelf::class)->orderByAggregate(Aggregate::Count)->fetch()))
            ->toThrow(\LogicException::class)
            ->and(fn () => $orm->query(Shelf::class)->having(Aggregate::Count, null, 'like', 1))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $orm->query(Shelf::class)->having(Aggregate::Count, null, '>', null))->toThrow(\InvalidArgumentException::class);
    });

    it('binds every value and quotes only the metadata\'s names', function () {
        $fixture = Shelves::sqlite();
        $connection = $fixture->connection();
        $orm = $fixture->orm(connection: $connection);

        $orm->actAs('acme', static fn (): array => $orm->query(Item::class)
            ->where('name', 'like', '%a%')
            ->groupBy('shelf.name')
            ->having(Aggregate::Sum, 'priceCents', '>', 1234)
            ->report(ShelfTally::class, ['shelf' => 'shelf.name', 'items' => Aggregate::Count]));

        $sql = $connection->statementLog()[0];
        expect($sql)->toContain('GROUP BY "t0__shelf"."name" HAVING COALESCE(SUM("t0"."price_cents"), 0) > ?')
            ->and($sql)->not->toContain('1234')
            ->and($sql)->not->toContain('acme')
            ->and($sql)->not->toContain('%a%');
    });

    it('records what it read of a column for the model-leak check', function () {
        $orm = Shelves::sqlite()->orm();
        $orm->recordReads(true);

        $orm->actAs('acme', static fn (): array => $orm->query(Shelf::class)->groupBy('secret')->orderBy('secret')->limit(1)
            ->report(Code::class, ['code' => 'secret']));

        expect($orm->columnReadsRecorded())->toBe([['model' => Shelf::class, 'column' => 'secret', 'value' => 's-a']]);
    });
});

describe('distinct', function () {
    it('plucks each value once, NULL too, through a join held to the tenant', function () {
        $orm = Shelves::sqlite()->orm();
        $codes = static fn (): array => $orm->query(Item::class)->distinct()->orderBy('shelf.aisle.code')->pluck('shelf.aisle.code');

        expect($orm->actAs('acme', $codes))->toBe(['A1', 'A2', null])
            ->and($orm->acrossTenants($codes))->toBe(['A1', 'A2', 'G1'])
            ->and($orm->actAs('acme', static fn (): array => $orm->query(Shelf::class)->pluck('aisle.code')))->toHaveCount(4)
            ->and(fn () => $orm->actAs('acme', static fn (): array => $orm->query(Item::class)->distinct()->orderBy('name')->pluck('shelf')))
            ->toThrow(\LogicException::class);
    });

    it('reads each row once already, so a fetch is the same', function () {
        $orm = Shelves::sqlite()->orm();

        expect($orm->actAs('acme', static fn (): array => names($orm->query(Shelf::class)->distinct()->where('items.priceCents', '>', 0)->orderBy('name')->fetch())))
            ->toBe(['Alpha', 'Bravo', 'Foxtrot']);
    });
});

describe('union', function () {
    it('reads the rows either arm reads, each once, as a query like any other', function () {
        $fixture = Shelves::sqlite();
        $connection = $fixture->connection();
        $orm = $fixture->orm(connection: $connection);
        $acme = static fn (\Closure $read): mixed => $orm->actAs('acme', $read);
        $union = $orm->query(Shelf::class)->where('name', 'Alpha')->union($orm->query(Shelf::class)->where('position', '>=', 3));

        expect($acme(static fn (): array => names($union->orderBy('name')->fetch())))->toBe(['Alpha', 'Charlie', 'Foxtrot'])
            ->and($acme(static fn (): int => $union->count()))->toBe(3)
            ->and($acme(static fn (): array => names($union->where('position', '<', 4)->orderBy('name')->fetch())))->toBe(['Alpha', 'Charlie'])
            ->and($acme(static fn (): array => names($union->orderBy('aisle.code')->orderBy('name')->paginate(1, 2)->items)))->toBe(['Alpha', 'Charlie'])
            ->and($acme(static fn (): array => names($orm->query(Shelf::class)->where('name', 'Alpha')
                ->union($orm->query(Shelf::class)->where('position', 1))
                ->union($orm->query(Shelf::class)->whereHas('items', static fn (Query $q): Query => $q->where('name', 'apple')))
                ->fetch())))->toBe(['Alpha']);

        $cursors = new SignedCursors(new Signer('test-key', SignedCursors::PURPOSE), 'session');
        $first = $acme(static fn (): mixed => $union->orderBy('position')->cursorPaginate(2, null, $cursors));
        expect(names($first->items))->toBe(['Alpha', 'Charlie'])
            ->and(names($acme(static fn (): mixed => $union->orderBy('position')->cursorPaginate(2, $first->next, $cursors))->items))->toBe(['Foxtrot']);
    });

    it('holds every arm to the tenant', function () {
        $fixture = Shelves::sqlite();
        $connection = $fixture->connection();
        $orm = $fixture->orm(connection: $connection);
        $globex = static fn (): array => names($orm->query(Shelf::class)->where('name', 'Delta')
            ->union($orm->query(Shelf::class)->where('items.priceCents', '>', 900))
            ->union($orm->query(Shelf::class)->where('aisle.code', 'G1'))
            ->orderBy('name')
            ->fetch());

        expect($orm->actAs('acme', $globex))->toBe([])
            ->and($orm->acrossTenants($globex))->toBe(['Alpha', 'Delta', 'Foxtrot'])
            ->and(fn () => $globex())->toThrow(NoTenantContext::class);

        $connection->resetStatementCount();
        $orm->actAs('acme', $globex);
        $sql = $connection->statementLog()[0];
        // The outer read, three arms, an EXISTS and a join: each held.
        expect(substr_count($sql, '"tenant_id" = ?'))->toBe(6)
            ->and(substr_count($sql, ' UNION '))->toBe(2);
    });

    it('refuses an arm over another model, or one with an order of its own', function () {
        $orm = Shelves::sqlite()->orm();
        $other = Shelves::sqlite()->orm();

        expect(fn () => $orm->query(Shelf::class)->union($orm->query(Shelf::class)->orderBy('name')))->toThrow(\LogicException::class)
            ->and(fn () => $orm->query(Shelf::class)->union($orm->query(Shelf::class)->limit(1)))->toThrow(\LogicException::class)
            ->and(fn () => $orm->query(Shelf::class)->union($orm->query(Shelf::class)->withCount('items')))->toThrow(\LogicException::class)
            ->and(fn () => $orm->query(Shelf::class)->union($other->query(Shelf::class)))->toThrow(\LogicException::class)
            // @phpstan-ignore argument.type (the mistake the runtime check is for)
            ->and(fn () => $orm->query(Shelf::class)->union($orm->query(Item::class)))->toThrow(\LogicException::class);
    });

    it('writes the rows the union reads, and no other tenant\'s', function () {
        $fixture = Shelves::sqlite();
        $orm = $fixture->orm();

        $written = $orm->actAs('acme', static fn (): int => $orm->query(Shelf::class)
            ->where('name', 'Delta')
            ->union($orm->query(Shelf::class)->where('name', 'Bravo'))
            ->update(['note' => 'moved']));

        expect($written)->toBe(1)
            ->and($fixture->pdo->query("SELECT name FROM shelves WHERE note = 'moved'")?->fetchAll(\PDO::FETCH_COLUMN))->toBe(['Bravo']);
    });
});

describe('unionAll', function () {
    it('reads each arm\'s rows, a row both read twice', function () {
        $orm = Shelves::sqlite()->orm();
        $acme = static fn (\Closure $read): mixed => $orm->actAs('acme', $read);
        $both = $orm->query(Shelf::class)->where('name', 'Alpha')->unionAll($orm->query(Shelf::class)->where('position', '<=', 2));

        $rows = $acme(static fn (): array => $both->orderBy('name', 'desc')->fetch());
        expect(names($rows))->toBe(['Bravo', 'Alpha', 'Alpha'])
            ->and($rows[1])->toBe($rows[2])
            ->and($acme(static fn (): int => $both->count()))->toBe(3)
            ->and($acme(static fn (): bool => $both->exists()))->toBeTrue()
            ->and($acme(static fn (): array => names($both->orderBy('name')->paginate(2, 2)->items)))->toBe(['Bravo'])
            ->and($acme(static fn (): ?string => $both->orderBy('name', 'desc')->first()?->name))->toBe('Bravo');
    });

    it('holds every arm to the tenant', function () {
        $orm = Shelves::sqlite()->orm();
        $globex = static fn (): array => names($orm->query(Shelf::class)->where('name', 'Delta')
            ->unionAll($orm->query(Shelf::class)->where('items.priceCents', '>', 900))
            ->orderBy('name')
            ->fetch());

        expect($orm->actAs('acme', $globex))->toBe([])
            ->and($orm->acrossTenants($globex))->toBe(['Alpha', 'Delta'])
            ->and(fn () => $globex())->toThrow(NoTenantContext::class);
    });

    it('refuses what no arm can answer', function () {
        $orm = Shelves::sqlite()->orm();
        $both = $orm->query(Shelf::class)->unionAll($orm->query(Shelf::class));
        $acme = static fn (\Closure $read): mixed => $orm->actAs('acme', $read);

        expect(fn () => $both->where('name', 'x'))->toThrow(\LogicException::class)
            ->and(fn () => $acme(static fn (): array => $both->orderBy('aisle.code')->fetch()))->toThrow(\LogicException::class)
            ->and(fn () => $acme(static fn (): array => $both->pluck('aisle.code')))->toThrow(\LogicException::class)
            ->and(fn () => $acme(static fn (): int|float => $both->sum('aisle.code')))->toThrow(\LogicException::class)
            ->and(fn () => $acme(static fn (): int => $both->limit(2)->update(['name' => 'x'])))->toThrow(\LogicException::class)
            ->and(fn () => $acme(static fn (): int => $both->offset(1)->limit(2)->delete()))->toThrow(\LogicException::class)
            ->and(fn () => $acme(static fn (): mixed => $both->withCount('items')->fetchAggregated()))->toThrow(\LogicException::class)
            ->and(fn () => $both->union($orm->query(Shelf::class)))->toThrow(\LogicException::class);
    });
});

// Every integer PHP holds is read as one -- nineteen digits among them --
// and a number past them as a float, never cut to the largest.
it('reads a sum as an integer whenever it is one PHP holds', function () {
    expect(Aggregate::Sum->read('9223372036854775807'))->toBe(\PHP_INT_MAX)
        ->and(Aggregate::Sum->read('-9223372036854775808'))->toBe(\PHP_INT_MIN)
        ->and(Aggregate::Sum->read('9223372036854775808'))->toBe(9.2233720368547758E18)
        ->and(Aggregate::Count->read('1234567890123456789'))->toBe(1234567890123456789)
        ->and(Aggregate::Sum->read('12.50'))->toBe(12.5)
        ->and(fn () => Aggregate::Count->read('9223372036854775808'))->toThrow(\UnexpectedValueException::class);
});

describe('increment and decrement', function () {
    it('adds to the column as the row holds it, on the tenant\'s rows alone', function () {
        $fixture = Shelves::sqlite();
        $connection = $fixture->connection();
        $orm = $fixture->orm(connection: $connection);

        // Echo is globex's, on acme's A1.
        $written = $orm->actAs('acme', static fn (): int => $orm->query(Shelf::class)->where('aisle.code', 'A1')->increment('position', 10));
        $positions = $fixture->pdo->query('SELECT name, position FROM shelves ORDER BY name')?->fetchAll(\PDO::FETCH_KEY_PAIR);

        expect($written)->toBe(2)
            ->and($positions)->toBe(['Alpha' => 11, 'Bravo' => 2, 'Charlie' => 13, 'Delta' => 1, 'Echo' => 2, 'Foxtrot' => 4])
            ->and(implode("\n", $connection->statementLog()))->toContain('SET "position" = "position" + ?')
            ->and(implode("\n", $connection->statementLog()))->not->toContain('+ 10');
    });

    it('sets other fields beside it, and the models held follow what was stored', function () {
        $fixture = Shelves::sqlite();
        $orm = $fixture->orm();

        [$apple, $count] = $orm->actAs('acme', static function () use ($orm): array {
            $apple = $orm->query(Item::class)->where('name', 'apple')->first();
            $count = $orm->query(Item::class)->where('name', 'apple')->decrement('priceCents', 30, ['name' => 'apple2']);

            return [$apple, $count];
        });

        expect($count)->toBe(1)
            ->and($apple?->priceCents)->toBe(70)
            ->and($apple?->name)->toBe('apple2')
            ->and($apple?->isDirty())->toBeFalse()
            ->and($orm->actAs('acme', static fn (): int => $orm->query(Item::class)->update(['priceCents' => Increment::by(1)])))->toBe(4)
            ->and($fixture->pdo->query('SELECT SUM(price_cents) FROM items')?->fetchColumn())->toBe(1873);
    });

    it('reaches the rows a sort by related rows and a limit pick', function () {
        $fixture = Shelves::sqlite();
        $orm = $fixture->orm();

        // Alpha has two items of acme's -- three, counting globex's ghost.
        $orm->actAs('acme', static fn (): int => $orm->query(Shelf::class)
            ->orderByRelation('items', Aggregate::Count, direction: 'desc')
            ->orderBy('name', 'desc')
            ->limit(2)
            ->increment('position', 100));

        expect($fixture->pdo->query('SELECT name FROM shelves WHERE position > 100 ORDER BY name')?->fetchAll(\PDO::FETCH_COLUMN))
            ->toBe(['Alpha', 'Foxtrot']);
    });

    it('refuses anything but a number of the model\'s own that is never NULL', function (\Closure $write, string $exception) {
        $orm = Shelves::sqlite()->orm();

        expect(fn () => $orm->actAs('acme', static fn (): mixed => $write($orm)))->toThrow($exception);
    })->with([
        'text' => [static fn (Orm $orm): mixed => $orm->query(Shelf::class)->increment('name'), \InvalidArgumentException::class],
        'a column that may be NULL' => [static fn (Orm $orm): mixed => $orm->query(Shelf::class)->increment('note'), \InvalidArgumentException::class],
        'the key' => [static fn (Orm $orm): mixed => $orm->query(Shelf::class)->increment('id'), \InvalidArgumentException::class],
        'the tenant' => [static fn (Orm $orm): mixed => $orm->query(Shelf::class)->increment('tenant_id'), ForeignTenantRow::class],
        'a #[BelongsTo]' => [static fn (Orm $orm): mixed => $orm->query(Shelf::class)->increment('aisle'), \InvalidArgumentException::class],
        'another table' => [static fn (Orm $orm): mixed => $orm->query(Shelf::class)->increment('aisle.code'), MappingException::class],
        'an unknown column' => [static fn (Orm $orm): mixed => $orm->query(Shelf::class)->increment('height'), MappingException::class],
        'a fraction of an int' => [static fn (Orm $orm): mixed => $orm->query(Shelf::class)->increment('position', 0.5), \InvalidArgumentException::class],
        'nothing' => [static fn (Orm $orm): mixed => $orm->query(Shelf::class)->increment('position', 0), \InvalidArgumentException::class],
        'a negative increment' => [static fn (Orm $orm): mixed => $orm->query(Shelf::class)->increment('position', -1), \InvalidArgumentException::class],
        'a new row' => [static fn (Orm $orm): mixed => $orm->query(Brand::class)->insert([['name' => 'n', 'rank' => Increment::by(1)]]), \InvalidArgumentException::class],
    ]);

    it('fails closed with no tenant, before it writes anything', function () {
        $fixture = Shelves::sqlite();
        $orm = $fixture->orm();

        expect(fn () => $orm->query(Shelf::class)->increment('position'))->toThrow(NoTenantContext::class)
            ->and(fn () => (new Orm($fixture->connection()))->acrossTenants(
                static fn (): int => (new Orm($fixture->connection()))->query(Shelf::class)->increment('position'),
            ))->toThrow(NoTenantContext::class)
            ->and($fixture->pdo->query('SELECT SUM(position) FROM shelves')?->fetchColumn())->toBe(13);
    });

    it('is refused inside a read-only scope before it reads anything', function () {
        $orm = Shelves::sqlite()->orm();

        expect(fn () => $orm->actAs('acme', static fn (): mixed => $orm->readOnly(static fn (): int => $orm->query(Shelf::class)->increment('position'))))
            ->toThrow(\Tinvar\Orm\ReadOnlyViolation::class);
    });
});
