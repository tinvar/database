<?php

declare(strict_types=1);

/**
 * The query builder (ADR 0057): immutable, scopes over the pipe operator,
 * identifiers from compiled metadata only, every value bound, and the
 * tenant predicate on every table a statement reads.
 */

namespace Tinvar\Tests\Orm\QueryBuilder;

use Tinvar\Orm\Direction;
use Tinvar\Orm\MappingException;
use Tinvar\Orm\NoTenantContext;
use Tinvar\Orm\Orm;
use Tinvar\Orm\Query;
use Tinvar\Tests\Orm\Fixtures\Brand;
use Tinvar\Tests\Orm\Fixtures\Item;
use Tinvar\Tests\Orm\Fixtures\Shelf;
use Tinvar\Tests\Orm\Fixtures\Shelves;
use Tinvar\Tests\Orm\Fixtures\Tenancy;

use function Tinvar\Orm\fetch;

require_once __DIR__ . '/Fixtures/shelves.php';

/** @param list<Shelf|Item|Brand> $models @return list<string> */
function names(array $models): array
{
    return array_map(static fn (Shelf|Item|Brand $model): string => $model->name, $models);
}

/** A scope, as the spec writes them: a plain function over the builder. */
function onAisle(string $code): \Closure
{
    return static fn (Query $q): Query => $q->where('aisle.code', $code);
}

/** @return \Closure(Query<Shelf>): Query<Shelf> */
function positionAbove(int $position): \Closure
{
    return static fn (Query $q): Query => $q->where('position', '>', $position);
}

describe('building', function () {
    it('composes scopes with the pipe operator and fetch()', function () {
        $orm = Shelves::sqlite()->orm();

        $names = $orm->actAs('acme', static fn (): array => names(
            Shelf::query($orm)
                |> onAisle('A1')
                |> positionAbove(0)
                |> (static fn (Query $q): Query => $q->orderBy('name', 'desc'))
                |> fetch(...),
        ));

        expect($names)->toBe(['Charlie', 'Alpha']);
    });

    it('leaves the query it narrows as it was', function () {
        $orm = Shelves::sqlite()->orm();
        $all = $orm->query(Shelf::class)->orderBy('position');
        $some = $all->where('position', '<=', 2);

        expect($orm->actAs('acme', static fn (): array => names($all->fetch())))->toBe(['Alpha', 'Bravo', 'Charlie', 'Foxtrot'])
            ->and($orm->actAs('acme', static fn (): array => names($some->fetch())))->toBe(['Alpha', 'Bravo']);
    });

    it('compares, lists, groups and tests for NULL', function () {
        $orm = Shelves::sqlite()->orm();
        $read = static fn (\Closure $narrow): array => $orm->actAs('acme', static fn (): array => names(
            $narrow($orm->query(Shelf::class))->orderBy('position')->fetch(),
        ));

        expect($read(static fn (Query $q): Query => $q->where('name', 'Bravo')))->toBe(['Bravo'])
            ->and($read(static fn (Query $q): Query => $q->where('position', '!=', 2)))->toBe(['Alpha', 'Charlie', 'Foxtrot'])
            ->and($read(static fn (Query $q): Query => $q->where('name', 'like', '%ar%')))->toBe(['Charlie'])
            ->and($read(static fn (Query $q): Query => $q->whereIn('name', ['Alpha', 'Foxtrot', 'Delta'])))->toBe(['Alpha', 'Foxtrot'])
            ->and($read(static fn (Query $q): Query => $q->whereNotIn('position', [1, 4])))->toBe(['Bravo', 'Charlie'])
            ->and($read(static fn (Query $q): Query => $q->whereIn('name', [])))->toBe([])
            ->and($read(static fn (Query $q): Query => $q->whereNotIn('name', [])))->toBe(['Alpha', 'Bravo', 'Charlie', 'Foxtrot'])
            ->and($read(static fn (Query $q): Query => $q->whereNull('note')))->toBe(['Bravo', 'Foxtrot'])
            ->and($read(static fn (Query $q): Query => $q->whereNotNull('note')))->toBe(['Alpha', 'Charlie'])
            ->and($read(static fn (Query $q): Query => $q->where('position', 1)->orWhere('position', 3)))->toBe(['Alpha', 'Charlie'])
            ->and($read(static fn (Query $q): Query => $q->whereNull('note')->where(
                static fn (Query $g): Query => $g->where('position', 2)->orWhere('position', 4),
            )))->toBe(['Bravo', 'Foxtrot']);
    });

    it('reads a property name, a column name, a #[BelongsTo] and a backed enum as the same column', function () {
        $fixture = Shelves::sqlite();
        $orm = $fixture->orm();

        expect($orm->actAs('acme', static fn (): array => names($orm->query(Item::class)->where('priceCents', '>=', 250)->orderBy('price_cents')->fetch())))
            ->toBe(['apricot', 'fig'])
            ->and($orm->actAs('acme', static fn (): array => names($orm->query(Shelf::class)->where('aisle', $fixture->ids['A2'])->fetch())))
            ->toBe(['Bravo'])
            ->and($orm->actAs('acme', static fn (): array => names($orm->query(Shelf::class)->orderBy(ShelfSort::Position, Direction::Desc)->fetch())))
            ->toBe(['Foxtrot', 'Charlie', 'Bravo', 'Alpha']);
    });

    it('compares a model by its key', function () {
        $orm = Shelves::sqlite()->orm();

        $items = $orm->actAs('acme', static function () use ($orm): array {
            $alpha = $orm->query(Shelf::class)->where('name', 'Alpha')->first();

            return names($orm->query(Item::class)->where('shelf', $alpha)->orderBy('name')->fetch());
        });

        expect($items)->toBe(['apple', 'apricot']);
    });

    it('answers an id that is no UUID as no row, without asking the database', function () {
        $fixture = Shelves::sqlite();
        $orm = $fixture->orm();

        [$none, $all, $listed] = $orm->actAs('acme', static fn (): array => [
            $orm->query(Shelf::class)->where('id', 'nope')->count(),
            $orm->query(Shelf::class)->where('id', '!=', 'nope')->count(),
            $orm->query(Shelf::class)->whereIn('id', ['nope', $fixture->ids['Alpha']])->count(),
        ]);

        expect([$none, $all, $listed])->toBe([0, 4, 1]);
    });

    it('binds every value, so none is in the SQL', function () {
        $fixture = Shelves::sqlite();
        $connection = $fixture->connection();
        $orm = $fixture->orm(connection: $connection);

        $orm->actAs('acme', static fn (): array => $orm->query(Shelf::class)
            ->where('name', "Alpha' OR '1'='1")
            ->whereIn('note', ['top'])
            ->whereRaw('position * ? > ?', [2, 1])
            ->limit(5)
            ->offset(1)
            ->fetch());

        $sql = $connection->statementLog()[0];
        expect($sql)->not->toContain('Alpha')
            ->and($sql)->not->toContain('top')
            ->and($sql)->not->toContain('acme')
            ->and($sql)->toContain('LIMIT ? OFFSET ?')
            ->and($sql)->toContain('("t0"."position" * ? > ?)');
    });

    it('loads nothing until a terminal method runs, and reads the scope in force then', function () {
        $orm = Shelves::sqlite()->orm();
        $query = $orm->actAs('acme', static fn (): Query => $orm->query(Shelf::class)->orderBy('position'));

        expect($orm->actAs('globex', static fn (): array => names($query->fetch())))->toBe(['Delta', 'Echo'])
            ->and(fn () => $query->fetch())->toThrow(NoTenantContext::class);
    });
});

describe('identifiers', function () {
    it('refuses a name the model does not map, however it is spelled', function (string $name) {
        $orm = Shelves::sqlite()->orm();

        expect(fn () => $orm->query(Shelf::class)->where($name, 1))->toThrow(MappingException::class)
            ->and(fn () => $orm->query(Shelf::class)->orderBy($name))->toThrow(MappingException::class)
            ->and(fn () => $orm->query(Shelf::class)->whereIn($name, [1]))->toThrow(MappingException::class)
            ->and(fn () => $orm->query(Shelf::class)->pluck($name))->toThrow(MappingException::class);
    })->with([
        'unknown' => 'password',
        'another model\'s' => 'price_cents',
        'quoted' => '"name"',
        'backticked' => '`name`',
        'with a direction' => 'name DESC',
        'with a statement' => 'name; DROP TABLE shelves',
        'with a comment' => 'name--',
        'with an OR' => 'name OR 1=1',
        'cased otherwise' => 'Name',
        'padded' => ' name',
        'qualified' => 'shelves.name',
        'aliased' => 't0.name',
        'through a collection, misspelt' => 'items.nme',
        'through a collection, another table\'s' => 'items.shelf_id.name',
        // items' column, which a #[HasMany] on Shelf names as its key.
        'a child\'s foreign key' => 'shelf_id',
        'through nothing' => 'aisle.',
        'too deep' => 'aisle.aisle.aisle.aisle.code',
        'empty' => '',
        'a NUL' => "name\0",
    ]);

    // A filter reads a collection's column as an EXISTS (ADR 0064); a sort
    // or a column read has many values to choose from, and names which.
    it('refuses to sort by or read a collection\'s column, which has many values to a row', function () {
        $orm = Shelves::sqlite()->orm();

        expect(fn () => $orm->query(Shelf::class)->orderBy('items.name'))->toThrow(MappingException::class)
            ->and(fn () => $orm->query(Shelf::class)->pluck('items.name'))->toThrow(MappingException::class)
            ->and(fn () => $orm->query(Shelf::class)->whereRaw('items.name = ?', ['x']))->toThrow(MappingException::class)
            ->and(fn () => $orm->query(Shelf::class)->groupBy('items.name'))->toThrow(MappingException::class);
    });

    it('refuses an operator, a direction or a relation it does not know', function () {
        $orm = Shelves::sqlite()->orm();

        expect(fn () => $orm->query(Shelf::class)->where('name', '= 1 OR', 'x'))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $orm->query(Shelf::class)->orderBy('name', 'asc; DROP'))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $orm->query(Shelf::class)->with('secret'))->toThrow(MappingException::class)
            ->and(fn () => $orm->query(Shelf::class)->whereHas('aisles'))->toThrow(MappingException::class)
            ->and(fn () => $orm->query(Shelf::class)->orderBy(ByNumber::One))->toThrow(MappingException::class);
    });

    // The contract: the builder knows what a model maps, not what a page
    // shows. A sort field from a request is narrowed first (ADR 0057).
    it('accepts a mapped column no page shows, so a request\'s sort field is narrowed before it gets here', function () {
        $orm = Shelves::sqlite()->orm();

        expect($orm->actAs('acme', static fn (): array => names($orm->query(Shelf::class)->orderBy('secret')->fetch())))
            ->toBe(['Bravo', 'Alpha', 'Foxtrot', 'Charlie']);
    });

    it('refuses values that are not a scalar, an enum, a date or a saved model', function () {
        $orm = Shelves::sqlite()->orm();

        expect(fn () => $orm->query(Shelf::class)->where('name', ['a']))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $orm->query(Shelf::class)->where('name', new \stdClass()))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $orm->query(Shelf::class)->where('name', null))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $orm->query(Shelf::class)->where('position', \NAN))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $orm->query(Shelf::class)->whereIn('name', [null]))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $orm->query(Item::class)->where('shelf', new Shelf()))->toThrow(\InvalidArgumentException::class);
    });
});

describe('raw conditions', function () {
    it('takes arithmetic, functions and keywords over the model\'s columns, every value bound', function () {
        $orm = Shelves::sqlite()->orm();

        $names = $orm->actAs('acme', static fn (): array => names($orm->query(Shelf::class)
            ->whereRaw('LOWER(name) LIKE ? AND (position * ? > ? OR note IS NULL)', ['%a%', 10, 25])
            ->orderBy('position')
            ->fetch()));

        expect($names)->toBe(['Bravo', 'Charlie']);
    });

    it('refuses anything that could reach past the model or the tenant', function (string $sql, int $bindings = 0) {
        $orm = Shelves::sqlite()->orm();

        expect(fn () => $orm->query(Shelf::class)->whereRaw($sql, array_fill(0, $bindings, 1)))->toThrow(MappingException::class);
    })->with([
        'a string' => ["name = 'Alpha'"],
        'a quoted identifier' => ['"secret" = ?', 1],
        'a backtick' => ['`name` = ?', 1],
        'a comment' => ['position = ? -- AND', 1],
        'a block comment' => ['position = ? /* x */', 1],
        'a statement' => ['position = ?; DROP TABLE shelves', 1],
        'a subquery' => ['position IN (SELECT position FROM shelves)'],
        'another table' => ['items.price_cents > ?', 1],
        'an unknown word' => ['password = ?', 1],
        'an unknown function' => ['SLEEP(?) = 0', 1],
        'a parenthesis it did not open' => ['1 = 1) OR (1 = 1'],
        'a parenthesis it did not close' => ['(position = ?', 1],
        'concatenation' => ['name || ? = ?', 2],
        'too few values' => ['position = ? AND position = ?', 1],
        'too many values' => ['position = ?', 2],
        'a named placeholder' => ['position = :p', 1],
        'a dollar placeholder' => ['position = $1', 1],
        'a backslash' => ['name = \\?', 1],
        'nothing' => ['   '],
        'a word glued to a number' => ['position = 1abc'],
    ]);
});

describe('reading', function () {
    it('counts, checks, plucks and aggregates', function () {
        $orm = Shelves::sqlite()->orm();

        [$count, $exists, $none, $plucked, $sum, $min, $max, $empty] = $orm->actAs('acme', static fn (): array => [
            $orm->query(Item::class)->count(),
            $orm->query(Item::class)->where('name', 'fig')->exists(),
            $orm->query(Item::class)->where('name', 'date')->exists(),
            $orm->query(Item::class)->orderBy('priceCents', 'desc')->limit(2)->pluck('name'),
            $orm->query(Item::class)->sum('priceCents'),
            $orm->query(Item::class)->min('priceCents'),
            $orm->query(Item::class)->max('name'),
            $orm->query(Item::class)->where('name', 'nothing')->sum('priceCents'),
        ]);

        expect([$count, $exists, $none, $plucked, $sum, (int) $min, $max, $empty])
            ->toBe([4, true, false, ['fig', 'apricot'], 700, 50, 'fig', 0]);
    });

    it('takes the first row, or none', function () {
        $orm = Shelves::sqlite()->orm();

        expect($orm->actAs('acme', static fn (): ?string => $orm->query(Shelf::class)->orderBy('position', 'desc')->first()?->name))->toBe('Foxtrot')
            ->and($orm->actAs('acme', static fn (): ?Shelf => $orm->query(Shelf::class)->where('name', 'Delta')->first()))->toBeNull();
    });

    it('refuses an offset with no limit', function () {
        $orm = Shelves::sqlite()->orm();

        expect(fn () => $orm->actAs('acme', static fn (): array => $orm->query(Shelf::class)->offset(2)->fetch()))
            ->toThrow(\LogicException::class)
            ->and(fn () => $orm->actAs('acme', static fn (): array => $orm->query(Shelf::class)->offset(2)->pluck('name')))
            ->toThrow(\LogicException::class);
    });

    // PHP's == takes '01', '1' and '1e0' for one value; a list keeps each.
    it('keeps every value of a list that PHP would call equal', function () {
        $fixture = Shelves::sqlite();
        foreach (['01', '1', '1e0'] as $name) {
            $fixture->pdo->exec("INSERT INTO brands VALUES ('" . \Tinvar\Support\Uuid::v7() . "', '{$name}', 9)");
        }
        $orm = $fixture->orm();

        expect($orm->query(Brand::class)->whereIn('name', ['01', '1', '1e0'])->count())->toBe(3)
            ->and($orm->query(Brand::class)->whereNotIn('name', ['01', '1'])->where('rank', 9)->pluck('name'))->toBe(['1e0']);
    });

    // `<>` an id that is no id is every row with a key, as `<>` a real one
    // is: a NULL foreign key is unequal to neither.
    it('reads a key unequal to an id that is no id as one that is set', function () {
        $orm = Shelves::sqlite()->orm();

        expect($orm->actAs('acme', static fn (): string => $orm->query(Item::class)->where('shelf', '!=', 'nope')->toSql()[0]))
            ->toContain('"t0"."shelf_id" IS NOT NULL')
            ->and($orm->actAs('acme', static fn (): string => $orm->query(Item::class)->whereNotIn('shelf', ['nope'])->toSql()[0]))
            ->toContain('"t0"."shelf_id" IS NOT NULL');
    });

    it('reads a model not tenant-scoped with no tenant at all', function () {
        $orm = Shelves::sqlite()->orm();

        expect(names($orm->query(Brand::class)->orderBy('rank', 'desc')->fetch()))->toBe(['Xi', 'Ypsilon', 'Zeta']);
    });

    it('filters and sorts by a related row\'s column through a join', function () {
        $orm = Shelves::sqlite()->orm();

        expect($orm->actAs('acme', static fn (): array => names($orm->query(Shelf::class)->where('aisle.code', 'A1')->orderBy('name')->fetch())))
            ->toBe(['Alpha', 'Charlie'])
            ->and($orm->actAs('acme', static fn (): array => names($orm->query(Item::class)->where('shelf.aisle.code', 'A1')->orderBy('name')->fetch())))
            ->toBe(['apple', 'apricot'])
            ->and($orm->actAs('acme', static fn (): array => names($orm->query(Item::class)->whereRaw('shelf.position > ?', [1])->orderBy('name')->fetch())))
            ->toBe(['banana', 'fig']);
    });

    it('filters by related rows with whereHas() and whereDoesntHave()', function () {
        $orm = Shelves::sqlite()->orm();

        expect($orm->actAs('acme', static fn (): array => names($orm->query(Shelf::class)->whereHas('items')->orderBy('name')->fetch())))
            ->toBe(['Alpha', 'Bravo', 'Foxtrot'])
            ->and($orm->actAs('acme', static fn (): array => names($orm->query(Shelf::class)
                ->whereHas('items', static fn (Query $q): Query => $q->where('priceCents', '>', 200))
                ->orderBy('name')->fetch())))
            ->toBe(['Alpha', 'Foxtrot'])
            ->and($orm->actAs('acme', static fn (): array => names($orm->query(Shelf::class)->whereDoesntHave('items')->fetch())))
            ->toBe(['Charlie'])
            ->and($orm->actAs('acme', static fn (): array => names($orm->query(Item::class)
                ->whereHas('shelf.aisle', static fn (Query $q): Query => $q->where('code', 'A2'))->fetch())))
            ->toBe(['banana']);
    });

    it('refuses a constraint that would lose its orders or limit', function () {
        $orm = Shelves::sqlite()->orm();

        expect(fn () => $orm->query(Shelf::class)->whereHas('items', static fn (Query $q): Query => $q->orderBy('name')))
            ->toThrow(\LogicException::class)
            ->and(fn () => $orm->query(Shelf::class)->where(static fn (Query $q): Query => $q->limit(1)))
            ->toThrow(\LogicException::class);
    });
});

describe('tenancy', function () {
    it('fails closed with no tenant, on every way of reading', function (\Closure $read) {
        $orm = Shelves::sqlite()->orm();

        expect(fn () => $read($orm))->toThrow(NoTenantContext::class);
    })->with([
        'fetch' => [static fn (Orm $orm): mixed => $orm->query(Shelf::class)->fetch()],
        'first' => [static fn (Orm $orm): mixed => $orm->query(Shelf::class)->first()],
        'count' => [static fn (Orm $orm): mixed => $orm->query(Shelf::class)->count()],
        'exists' => [static fn (Orm $orm): mixed => $orm->query(Shelf::class)->exists()],
        'pluck' => [static fn (Orm $orm): mixed => $orm->query(Shelf::class)->pluck('name')],
        'sum' => [static fn (Orm $orm): mixed => $orm->query(Item::class)->sum('priceCents')],
        'paginate' => [static fn (Orm $orm): mixed => $orm->query(Shelf::class)->paginate(1, 10)],
        'toSql' => [static fn (Orm $orm): mixed => $orm->query(Shelf::class)->toSql()],
        'an id that is no id' => [static fn (Orm $orm): mixed => $orm->query(Shelf::class)->where('id', 'nope')->fetch()],
    ]);

    it('holds a join to the tenant, so another tenant\'s row joins as none', function () {
        $orm = Shelves::sqlite()->orm();

        // Foxtrot is acme's, on globex's aisle: as acme, it has no aisle code.
        expect($orm->actAs('acme', static fn (): array => names($orm->query(Shelf::class)->whereNull('aisle.code')->fetch())))
            ->toBe(['Foxtrot'])
            ->and($orm->actAs('acme', static fn (): int => $orm->query(Shelf::class)->where('aisle.code', 'G1')->count()))
            ->toBe(0)
            // Echo is globex's, on acme's aisle.
            ->and($orm->actAs('globex', static fn (): int => $orm->query(Shelf::class)->where('aisle.code', 'A1')->count()))
            ->toBe(0);
    });

    it('holds a subquery to the tenant, so another tenant\'s child is not one', function () {
        $orm = Shelves::sqlite()->orm();

        // ghost is globex's item, on acme's Alpha.
        expect($orm->actAs('acme', static fn (): int => $orm->query(Shelf::class)
            ->whereHas('items', static fn (Query $q): Query => $q->where('priceCents', '>', 900))->count()))
            ->toBe(0)
            ->and($orm->acrossTenants(static fn (): array => names($orm->query(Shelf::class)
                ->whereHas('items', static fn (Query $q): Query => $q->where('priceCents', '>', 900))->fetch())))
            ->toBe(['Alpha']);
    });

    it('cannot OR its way past the tenant predicate', function () {
        $orm = Shelves::sqlite()->orm();

        expect($orm->actAs('acme', static fn (): array => names($orm->query(Shelf::class)
            ->where('name', 'Delta')
            ->orWhere('position', '>=', 1)
            ->orWhereRaw('1 = 1 OR 1 = 1')
            ->orderBy('name')
            ->fetch())))->toBe(['Alpha', 'Bravo', 'Charlie', 'Foxtrot']);
    });

    it('reads every tenant inside acrossTenants(), and the tenant alone after', function () {
        $orm = Shelves::sqlite()->orm();

        expect($orm->acrossTenants(static fn (): int => $orm->query(Shelf::class)->count()))->toBe(6)
            ->and($orm->acrossTenants(static fn (): array => names($orm->query(Shelf::class)->where('aisle.code', 'A1')->orderBy('name')->fetch())))
            ->toBe(['Alpha', 'Charlie', 'Echo'])
            ->and($orm->actAs('acme', static fn (): int => $orm->query(Shelf::class)->count()))->toBe(4);
    });

    it('reads across a subtree a membership granted, and nowhere else', function () {
        $orm = Shelves::sqlite()->orm(Tenancy::tree(['globex' => 'acme']));
        $within = static fn (): array => $orm->grantSubtree(static fn (): array => $orm->withinSubtree(
            static fn (): array => names($orm->query(Shelf::class)->whereHas('items')->orderBy('name')->fetch()),
        ));

        expect($orm->actAs('acme', $within))->toBe(['Alpha', 'Bravo', 'Delta', 'Echo', 'Foxtrot'])
            ->and($orm->actAs('globex', $within))->toBe(['Delta', 'Echo']);
    });

    it('reads no tenant-scoped row with no tenancy registered, inside acrossTenants() too', function () {
        $fixture = Shelves::sqlite();
        $orm = new Orm($fixture->connection());

        expect(fn () => $orm->actAs('acme', static fn (): array => $orm->query(Shelf::class)->fetch()))->toThrow(NoTenantContext::class)
            ->and(fn () => $orm->acrossTenants(static fn (): int => $orm->query(Shelf::class)->count()))->toThrow(NoTenantContext::class)
            ->and(fn () => $orm->query(Brand::class)->where('rank', '>', 1)->whereHas('nothing'))->toThrow(MappingException::class)
            ->and(names($orm->query(Brand::class)->orderBy('rank')->fetch()))->toBe(['Zeta', 'Ypsilon', 'Xi']);
    });
});

enum ShelfSort: string
{
    case Name = 'name';
    case Position = 'position';
}

enum ByNumber: int
{
    case One = 1;
}
