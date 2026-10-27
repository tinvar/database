<?php

declare(strict_types=1);

/**
 * Writes through the query builder (ADR 0057): update() and delete() held
 * to exactly what a read of the same query is held to -- the tenant
 * predicate on every table, failing closed, the read-only scope, the
 * metadata's identifiers, bound values -- and the ORM's state brought up to
 * date with what they wrote, as after save() and delete().
 */

namespace Tinvar\Tests\Orm\QueryWrites;

use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\ForeignTenantRow;
use Tinvar\Orm\Model;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\MappingException;
use Tinvar\Orm\NoTenantContext;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Query;
use Tinvar\Orm\ReadOnlyViolation;
use Tinvar\Tests\Orm\Fixtures\Aisle;
use Tinvar\Tests\Orm\Fixtures\Brand;
use Tinvar\Tests\Orm\Fixtures\Item;
use Tinvar\Tests\Orm\Fixtures\Shelf;
use Tinvar\Tests\Orm\Fixtures\Shelves;
use Tinvar\Tests\Orm\Fixtures\TrimmedBrand;
use Tinvar\Tests\Orm\Fixtures\UnscopedShelf;

require_once __DIR__ . '/Fixtures/shelves.php';

/** The brands table read as a model whose rank only it sets. */
#[Table('brands')]
final class RankedBrand extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;
    #[Column] public private(set) int $rank;
}

/** @return array<string, mixed> each shelf's $column by name, read past the ORM */
function column(Shelves $fixture, string $column, string $table = 'shelves'): array
{
    $rows = $fixture->pdo->query("SELECT name, {$column} AS value FROM {$table} ORDER BY name")?->fetchAll(\PDO::FETCH_KEY_PAIR);

    return \is_array($rows) ? $rows : [];
}

describe('update()', function () {
    // fig is on Foxtrot: never read, so neither held nor in any cached
    // group. All the write knows of it is the column it set, which is no
    // row to hydrate a model from.
    it('moves a row it never read into a loaded collection whole', function () {
        $fixture = Shelves::sqlite();
        $orm = $fixture->orm();

        $orm->actAs('acme', static function () use ($orm): void {
            $bravo = $orm->query(Shelf::class)->where('name', 'Bravo')->first();
            \assert($bravo instanceof Shelf);
            expect($bravo->items->count())->toBe(1);

            $orm->query(Item::class)->where('name', 'fig')->update(['shelf' => $bravo]);

            expect(array_map(static fn (Item $item): string => $item->name, $bravo->items->all()))->toBe(['banana', 'fig'])
                ->and(array_map(static fn (Item $item): int => $item->priceCents, $bravo->items->all()))->toBe([50, 300]);
        });
    });

    it('writes the rows the query reads, under the tenant in force and no other', function () {
        $fixture = Shelves::sqlite();
        $orm = $fixture->orm();

        $count = $orm->actAs('acme', static fn (): int => $orm->query(Shelf::class)->where('position', '>', 1)->update(['note' => 'moved']));

        expect($count)->toBe(3)
            ->and(column($fixture, 'note'))->toBe([
                'Alpha' => 'top', 'Bravo' => 'moved', 'Charlie' => 'moved', 'Delta' => 'g', 'Echo' => null, 'Foxtrot' => 'moved',
            ]);
    });

    it('holds a join and a subquery to the tenant, as a read is', function () {
        $fixture = Shelves::sqlite();
        $orm = $fixture->orm();

        // Echo is globex's, on acme's aisle A1; ghost is globex's, on Alpha.
        $orm->actAs('globex', static function () use ($orm): void {
            expect($orm->query(Shelf::class)->where('aisle.code', 'A1')->update(['note' => 'x']))->toBe(0)
                ->and($orm->query(Shelf::class)->whereHas('items', static fn (Query $q): Query => $q->where('name', 'apple'))->update(['note' => 'x']))->toBe(0);
        });
        $orm->actAs('acme', static function () use ($orm): void {
            expect($orm->query(Item::class)->where('shelf.name', 'Echo')->delete())->toBe(0);
        });

        expect(column($fixture, 'note'))->toMatchArray(['Delta' => 'g', 'Echo' => null])
            ->and(column($fixture, 'price_cents', 'items'))->toHaveCount(7);
    });

    it('writes within the order and limit', function () {
        $fixture = Shelves::sqlite();
        $orm = $fixture->orm();

        $orm->actAs('acme', static fn (): int => $orm->query(Shelf::class)->orderBy('position', 'desc')->limit(2)->update(['note' => 'last two']));

        expect(column($fixture, 'note'))->toMatchArray(['Charlie' => 'last two', 'Foxtrot' => 'last two', 'Bravo' => null]);
    });

    it('fails closed with no tenant, with no tenancy, and inside a loader or query body', function () {
        $fixture = Shelves::sqlite();
        $connection = $fixture->connection();
        $orm = $fixture->orm(connection: $connection);
        $bare = new Orm($fixture->connection(), new IdentityMap('bare'));

        expect(fn () => $orm->query(Shelf::class)->update(['note' => 'x']))->toThrow(NoTenantContext::class)
            ->and(fn () => $orm->query(Shelf::class)->delete())->toThrow(NoTenantContext::class)
            ->and(fn () => $bare->actAs('acme', static fn (): int => $bare->query(Shelf::class)->update(['note' => 'x'])))
            ->toThrow(NoTenantContext::class, 'no tenancy is registered')
            ->and(fn () => $bare->acrossTenants(static fn (): int => $bare->query(Shelf::class)->delete()))
            ->toThrow(NoTenantContext::class, 'no tenancy is registered');

        $connection->resetStatementCount();
        expect(fn () => $orm->actAs('acme', static fn (): int => $orm->readOnly(static fn (): int => $orm->query(Shelf::class)->update(['note' => 'x']))))
            ->toThrow(ReadOnlyViolation::class, 'update()')
            ->and(fn () => $orm->actAs('acme', static fn (): int => $orm->readOnly(static fn (): int => $orm->query(Shelf::class)->delete())))
            ->toThrow(ReadOnlyViolation::class, 'delete()')
            // Refused before anything is read for it.
            ->and(array_filter($connection->statementLog(), static fn (string $sql): bool => str_contains($sql, 'shelves')))->toBe([])
            ->and(column($fixture, 'note'))->toMatchArray(['Bravo' => null, 'Echo' => null]);
    });

    it('writes every tenant\'s rows inside acrossTenants(), as save() may', function () {
        $fixture = Shelves::sqlite();
        $orm = $fixture->orm();

        expect($orm->acrossTenants(static fn (): int => $orm->query(Shelf::class)->whereNull('note')->update(['note' => 'set'])))->toBe(3);
    });

    it('sets the model\'s own fields alone, stored as save() stores them', function (array $values, string $exception, string $message) {
        $fixture = Shelves::sqlite();
        $orm = $fixture->orm();

        expect(fn () => $orm->actAs('acme', static fn (): int => $orm->query(Shelf::class)->update($values)))->toThrow($exception, $message);
    })->with([
        'the key' => [['id' => '0192f0c4-1e5a-7000-8000-000000000000'], \InvalidArgumentException::class, 'never changes'],
        'the tenant' => [['tenant_id' => 'globex'], ForeignTenantRow::class, 'never updated'],
        'a joined column' => [['aisle.code' => 'A9'], MappingException::class, 'another table'],
        'an unknown field' => [['nope' => 1], MappingException::class, 'nope'],
        'a quoted name' => [['"name"' => 'x'], MappingException::class, ''],
        'the wrong type' => [['position' => '5'], \InvalidArgumentException::class, 'does not take string'],
        'a raw key for a relation' => [['aisle' => '0192f0c4-1e5a-7000-8000-000000000000'], \InvalidArgumentException::class, 'could name a row'],
        'nothing' => [[], \InvalidArgumentException::class, 'at least one field'],
        'a list' => [['x'], \InvalidArgumentException::class, 'names each field'],
    ]);

    it('refuses a property the model alone sets, and one field named twice', function () {
        $fixture = Shelves::sqlite();
        $orm = $fixture->orm();

        expect(fn () => $orm->query(RankedBrand::class)->update(['rank' => 1]))
            ->toThrow(\InvalidArgumentException::class, 'set by the model alone')
            ->and(fn () => $orm->actAs('acme', static fn (): int => $orm->query(Item::class)->update(['priceCents' => 1, 'price_cents' => 2])))
            ->toThrow(\InvalidArgumentException::class, 'sets price_cents, which this row sets already')
            ->and(column($fixture, '"rank"', 'brands'))->toBe(['Xi' => 3, 'Ypsilon' => 2, 'Zeta' => 1]);
    });

    it('refuses a model whose saves run #[BeforeSave] methods, which it would write past', function () {
        $fixture = Shelves::sqlite();
        $orm = $fixture->orm();

        expect(fn () => $orm->query(TrimmedBrand::class)->update(['name' => ' padded ']))->toThrow(\LogicException::class, '#[BeforeSave]')
            ->and(column($fixture, 'name', 'brands'))->toHaveKey('Xi');
    });

    it('brings a held model up to date, keeping a change it has to another field pending', function () {
        $fixture = Shelves::sqlite();
        $orm = $fixture->orm();

        $orm->actAs('acme', static function () use ($orm): void {
            $alpha = $orm->query(Shelf::class)->where('name', 'Alpha')->first();
            \assert($alpha instanceof Shelf);
            $alpha->secret = 'pending';

            $orm->query(Shelf::class)->where('name', 'Alpha')->update(['note' => 'written', 'position' => 9]);

            expect($alpha->note)->toBe('written')
                ->and($alpha->position)->toBe(9)
                ->and($alpha->changes())->toBe(['secret' => 'pending']);
        });
    });

    it('moves a held model to the relation it was given, and its loaded collections with it', function () {
        $fixture = Shelves::sqlite();
        $orm = $fixture->orm();

        $orm->actAs('acme', static function () use ($orm): void {
            [$alpha, $bravo] = $orm->query(Shelf::class)->whereIn('name', ['Alpha', 'Bravo'])->orderBy('name')->fetch();
            $before = [$alpha->items->count(), $bravo->items->count()];
            $apple = $alpha->items->all()[0];
            $a2 = $orm->query(Aisle::class)->where('code', 'A2')->first();
            \assert($a2 instanceof Aisle);

            $orm->query(Item::class)->where('name', 'apple')->update(['shelf' => $bravo]);
            $orm->query(Shelf::class)->where('name', 'Alpha')->update(['aisle' => $a2]);

            expect($before)->toBe([2, 1])
                ->and($apple->shelf)->toBe($bravo)
                ->and($apple->isDirty())->toBeFalse()
                ->and(array_map(static fn (Item $item): string => $item->name, $alpha->items->all()))->toBe(['apricot'])
                ->and(array_map(static fn (Item $item): string => $item->name, $bravo->items->all()))->toBe(['apple', 'banana'])
                ->and($alpha->aisle->code)->toBe('A2');
        });
    });

    it('puts a held model back as it was when the transaction around the write rolls back', function () {
        $fixture = Shelves::sqlite();
        $orm = $fixture->orm();

        $orm->actAs('acme', static function () use ($orm): void {
            $alpha = $orm->query(Shelf::class)->where('name', 'Alpha')->first();
            \assert($alpha instanceof Shelf);

            try {
                $orm->transaction(static function () use ($orm): void {
                    $orm->query(Shelf::class)->where('name', 'Alpha')->update(['note' => 'undone']);

                    throw new \RuntimeException('rolled back');
                });
            } catch (\RuntimeException) {
            }

            expect($alpha->note)->toBe('top')
                ->and($alpha->isDirty())->toBeFalse()
                ->and($orm->query(Shelf::class)->where('name', 'Alpha')->pluck('note'))->toBe(['top']);
        });
    });
});

describe('delete()', function () {
    it('deletes the rows the query reads, under the tenant in force and no other', function () {
        $fixture = Shelves::sqlite();
        $orm = $fixture->orm();

        $count = $orm->actAs('acme', static fn (): int => $orm->query(Item::class)->where('priceCents', '<', 200)->delete());

        expect($count)->toBe(2)
            ->and(array_keys(column($fixture, 'price_cents', 'items')))->toBe(['apricot', 'date', 'eggplant', 'fig', 'ghost']);
    });

    it('leaves a held model unsaved and out of every loaded relation', function () {
        $fixture = Shelves::sqlite();
        $orm = $fixture->orm();

        $orm->actAs('acme', static function () use ($orm): void {
            $alpha = $orm->query(Shelf::class)->where('name', 'Alpha')->first();
            \assert($alpha instanceof Shelf);
            $apple = $alpha->items->all()[0];

            $orm->query(Item::class)->where('name', 'apple')->delete();

            expect($apple->isPersisted())->toBeFalse()
                ->and(array_map(static fn (Item $item): string => $item->name, $alpha->items->all()))->toBe(['apricot'])
                ->and($orm->query(Item::class)->where('name', 'apple')->exists())->toBeFalse();
        });
    });
});

describe('insert() and upsert()', function () {
    it('stamps each new row with the tenant in force, and refuses one naming another', function () {
        $fixture = Shelves::sqlite();
        $orm = $fixture->orm();

        $count = $orm->actAs('acme', static fn (): int => $orm->query(Aisle::class)->insert([
            ['code' => 'A3'],
            ['code' => 'A4', 'tenant_id' => 'acme'],
        ]));

        expect($count)->toBe(2)
            ->and($fixture->pdo->query("SELECT code, tenant_id FROM aisles WHERE code IN ('A3', 'A4') ORDER BY code")?->fetchAll(\PDO::FETCH_KEY_PAIR))
            ->toBe(['A3' => 'acme', 'A4' => 'acme'])
            ->and(fn () => $orm->actAs('acme', static fn (): int => $orm->query(Aisle::class)->insert([['code' => 'A5', 'tenant_id' => 'globex']])))
            ->toThrow(ForeignTenantRow::class)
            ->and(fn () => $orm->query(Aisle::class)->insert([['code' => 'A5']]))->toThrow(NoTenantContext::class)
            ->and(fn () => $orm->acrossTenants(static fn (): int => $orm->query(Aisle::class)->insert([['code' => 'A5']])))->toThrow(NoTenantContext::class)
            ->and(fn () => new Orm($fixture->connection(), new IdentityMap('bare'))->query(Aisle::class)->insert([['code' => 'A5']]))
            ->toThrow(NoTenantContext::class, 'no tenancy is registered')
            ->and($fixture->pdo->query("SELECT COUNT(*) FROM aisles WHERE code = 'A5'")?->fetchColumn())->toBe(0);
    });

    it('is called on the query a model starts, with rows that each name the same fields', function () {
        $fixture = Shelves::sqlite();
        $orm = $fixture->orm();

        expect(fn () => $orm->query(Brand::class)->where('rank', 1)->insert([['name' => 'Omega', 'rank' => 9]]))->toThrow(\LogicException::class, 'reads nothing')
            ->and(fn () => $orm->query(Brand::class)->insert([['name' => 'Omega', 'rank' => 9], ['name' => 'Psi']]))->toThrow(\InvalidArgumentException::class, 'same fields')
            ->and(fn () => $orm->query(Brand::class)->insert([['name' => 'Omega', 'rank' => 9, 'id' => 'not-a-uuid']]))->toThrow(MappingException::class, 'UUID')
            ->and(fn () => $orm->query(TrimmedBrand::class)->insert([['name' => ' Omega ', 'rank' => 9]]))->toThrow(\LogicException::class, '#[BeforeSave]')
            ->and($orm->query(Brand::class)->insert([]))->toBe(0)
            ->and(fn () => $orm->readOnly(static fn (): int => $orm->query(Brand::class)->insert([['name' => 'Omega', 'rank' => 9]])))
            ->toThrow(ReadOnlyViolation::class, 'insert()');
    });

    it('puts a new row into the loaded relations it belongs to', function () {
        $fixture = Shelves::sqlite();
        $orm = $fixture->orm();

        $orm->actAs('acme', static function () use ($orm): void {
            $bravo = $orm->query(Shelf::class)->where('name', 'Bravo')->first();
            \assert($bravo instanceof Shelf);
            $before = $bravo->items->count();

            $orm->query(Item::class)->insert([['name' => 'avocado', 'priceCents' => 80, 'shelf' => $bravo]]);

            expect($before)->toBe(1)
                ->and(array_map(static fn (Item $item): string => $item->name, $bravo->items->all()))->toBe(['avocado', 'banana']);
        });
    });

    it('updates the row a key names, within the tenant, and inserts the rest', function () {
        $fixture = Shelves::sqlite();
        $orm = $fixture->orm();

        // A1 is acme's and globex's code alike; globex's is left alone.
        $orm->acrossTenants(static fn (): int => $orm->query(Aisle::class)->where('code', 'G1')->update(['code' => 'A1']));
        $inserted = $orm->actAs('acme', static fn (): int => $orm->query(Aisle::class)->upsert([
            ['code' => 'A1'],
            ['code' => 'A9'],
        ], uniqueBy: 'code'));

        expect($inserted)->toBe(1)
            ->and($fixture->pdo->query('SELECT tenant_id, COUNT(*) FROM aisles GROUP BY tenant_id ORDER BY tenant_id')?->fetchAll(\PDO::FETCH_KEY_PAIR))
            ->toBe(['acme' => 3, 'globex' => 1]);
    });

    it('takes its conflict target from a declared key alone, and brings a held model up to date', function () {
        $fixture = Shelves::sqlite();
        $orm = $fixture->orm();

        $xi = $orm->query(Brand::class)->where('name', 'Xi')->first();
        \assert($xi instanceof Brand);
        $inserted = $orm->query(Brand::class)->upsert([['name' => 'Xi', 'rank' => 30], ['name' => 'Omega', 'rank' => 40]], 'name');
        $kept = $orm->query(Brand::class)->upsert([['name' => 'Xi', 'rank' => 99]], ['name'], update: []);

        expect($inserted)->toBe(1)
            ->and($kept)->toBe(0)
            ->and($xi->rank)->toBe(30)
            ->and($xi->isDirty())->toBeFalse()
            ->and(column($fixture, '"rank"', 'brands'))->toBe(['Omega' => 40, 'Xi' => 30, 'Ypsilon' => 2, 'Zeta' => 1])
            ->and(fn () => $orm->query(Brand::class)->upsert([['name' => 'Xi', 'rank' => 1]], 'rank'))->toThrow(MappingException::class, 'declares no #[Unique] key over (rank)')
            ->and(fn () => $orm->query(Brand::class)->upsert([['name' => 'Xi', 'rank' => 1]], 'name', update: ['name']))->toThrow(\InvalidArgumentException::class, 'updates no key')
            ->and(fn () => $orm->query(Brand::class)->upsert([['rank' => 1]], 'name'))->toThrow(\InvalidArgumentException::class, 'leave out name')
            ->and(fn () => $orm->query(Brand::class)->upsert([['name' => 'Xi', 'rank' => 1], ['name' => 'Xi', 'rank' => 2]], 'name'))
            ->toThrow(\InvalidArgumentException::class, 'share a key');
    });
});

describe('on MySQL', function () {
    it('writes as SQLite does, and refuses an upsert it cannot hold to the tenant', function () {
        Shelves::onMySql(static function (Shelves $fixture, PdoConnection $connection): void {
            $orm = $fixture->orm(connection: $connection);

            $orm->actAs('acme', static function () use ($orm): void {
                expect($orm->query(Shelf::class)->where('aisle.code', 'A1')->update(['note' => 'my']))->toBe(2)
                    ->and($orm->query(Item::class)->where('priceCents', '<', 200)->delete())->toBe(2)
                    ->and(fn () => $orm->query(Aisle::class)->upsert([['code' => 'A1']], 'code'))->toThrow(\LogicException::class, 'refused on MySQL');
            });

            expect($orm->query(Brand::class)->upsert([['name' => 'Xi', 'rank' => 30], ['name' => 'Omega', 'rank' => 40]], 'name'))->toBe(1)
                ->and($orm->query(Brand::class)->orderBy('name')->pluck('rank'))->toEqual([40, 30, 2, 1]);
        });
    })->skip(fn () => \Tinvar\Tests\Orm\Fixtures\Databases::mysqlMissing() !== null, 'MySQL is not available');
});

describe('on Postgres, with row-level security', function () {
    it('upserts within the tenant, under the policy', function () {
        Shelves::onPostgres(static function (Shelves $fixture, PdoConnection $connection): void {
            $orm = $fixture->orm(connection: $connection);

            $inserted = $orm->actAs('globex', static fn (): int => $orm->query(Aisle::class)->upsert([['code' => 'G1'], ['code' => 'A1']], 'code'));

            expect($inserted)->toBe(1)
                ->and($orm->acrossTenants(static fn (): int => $orm->query(Aisle::class)->count()))->toBe(4)
                ->and($orm->actAs('globex', static fn (): array => $orm->query(Aisle::class)->orderBy('code')->pluck('code')))->toBe(['A1', 'G1']);
        });
    })->skip(fn () => \Tinvar\Tests\Orm\Fixtures\Databases::postgresMissing() !== null, 'Postgres is not available');

    it('writes the tenant\'s rows, and holds a model that forgot #[TenantScoped] to them too', function () {
        Shelves::onPostgres(static function (Shelves $fixture, PdoConnection $connection): void {
            $orm = $fixture->orm(connection: $connection);

            $orm->actAs('acme', static function () use ($orm): void {
                expect($orm->query(Shelf::class)->where('aisle.code', 'A1')->update(['note' => 'pg']))->toBe(2)
                    ->and($orm->query(Item::class)->whereHas('shelf', static fn (Query $q): Query => $q->where('name', 'Alpha'))->delete())->toBe(2)
                    // No predicate of the builder's: the policy alone.
                    ->and($orm->query(UnscopedShelf::class)->update(['name' => 'renamed']))->toBe(4);
            });

            expect(fn () => $orm->query(UnscopedShelf::class)->update(['name' => 'nobody']))->not->toThrow(\Throwable::class)
                ->and($orm->query(UnscopedShelf::class)->update(['name' => 'nobody']))->toBe(0)
                ->and($orm->acrossTenants(static fn (): array => $orm->query(Shelf::class)->orderBy('name')->pluck('name')))
                ->toBe(['Delta', 'Echo', 'renamed', 'renamed', 'renamed', 'renamed']);
        });
    })->skip(fn () => \Tinvar\Tests\Orm\Fixtures\Databases::postgresMissing() !== null, 'Postgres is not available');
});
