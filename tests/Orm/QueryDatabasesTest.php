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
use Tinvar\Tests\Orm\Fixtures\UnscopedShelf;

require_once __DIR__ . '/Fixtures/shelves.php';

/** @param list<Shelf|Item|Brand|UnscopedShelf> $models @return list<string> */
function names(array $models): array
{
    return array_map(static fn (Shelf|Item|Brand|UnscopedShelf $model): string => $model->name, $models);
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

describe('on Postgres, with row-level security', function () {
    it('reads as SQLite does, under the policy', function () {
        Shelves::onPostgres(static function (Shelves $fixture, PdoConnection $connection): void {
            readsAlike($fixture->orm(connection: $connection));
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
})->group('mysql')->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');

describe('on SQLite', function () {
    it('reads alike', function () {
        readsAlike(Shelves::sqlite()->orm());
    });
});
