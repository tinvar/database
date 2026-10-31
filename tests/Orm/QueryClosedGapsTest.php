<?php

declare(strict_types=1);

/**
 * What ADR 0123 closed in the query builder, alike on SQLite, Postgres --
 * under row-level security -- and MySQL: a HAVING on a column the report
 * groups by, distinct() reading each row of a unionAll() once, cursor
 * pages over a unionAll(), an upsert updating with a Change, and a
 * tenant's upsert on MySQL in one guarded statement.
 *
 * Held to the tenant as every read and write is: the fixture has globex's
 * item on acme's shelf, acme's shelf on globex's aisle and globex's shelf
 * on acme's aisle, so any of them showing as acme is a leak.
 */

namespace Tinvar\Tests\Orm\QueryClosedGaps;

use Tinvar\Orm\Aggregate;
use Tinvar\Orm\Attributes\BelongsTo;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\TenantScoped;
use Tinvar\Orm\Attributes\Unique;
use Tinvar\Orm\Change;
use Tinvar\Orm\Connection;
use Tinvar\Orm\InvalidCursor;
use Tinvar\Orm\Model;
use Tinvar\Orm\NoTenantContext;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\SignedCursors;
use Tinvar\Support\Signer;
use Tinvar\Tests\Orm\Fixtures\Aisle;
use Tinvar\Tests\Orm\Fixtures\Brand;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Orm\Fixtures\Shelf;
use Tinvar\Tests\Orm\Fixtures\Shelves;

require_once __DIR__ . '/Fixtures/shelves.php';

/** The shelves table, its name a tenant's key, for an upsert. */
#[Table('shelves'), TenantScoped, Unique('name')]
final class KeyedShelf extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;
    #[Column] public int $position;
    #[Column] public ?string $note = null;
    #[Column] public string $secret;

    #[BelongsTo] public Aisle $aisle;
}

/** A report's row. */
final readonly class Group
{
    public function __construct(public ?string $key = null, public int $count = 0) {}
}

/** The key an upsert conflicts on, and a secret unique across every tenant. */
function indexes(\PDO $pdo): void
{
    $pdo->exec('CREATE UNIQUE INDEX shelves_tenant_id_name_unique ON shelves (tenant_id, name)');
    $pdo->exec('CREATE UNIQUE INDEX shelves_secret_unique ON shelves (secret)');
}

/** @param list<Model> $models @return list<string> */
function names(array $models): array
{
    return array_map(static fn (Model $model): string => $model->name, $models);
}

/** @return array<string, array{int, ?string}> each of $tenant's shelves: position and note */
function shelves(Orm $orm, string $tenant): array
{
    $out = [];
    foreach ($orm->actAs($tenant, static fn (): array => $orm->query(KeyedShelf::class)->orderBy('name')->fetch()) as $shelf) {
        $out[$shelf->name] = [$shelf->position, $shelf->note];
    }

    return $out;
}

function readsAlike(Orm $orm): void
{
    $acme = static fn (\Closure $read): mixed => $orm->actAs('acme', $read);
    $across = static fn (\Closure $read): mixed => $orm->acrossTenants($read);

    // havingColumn() on what the report groups by. As acme, Foxtrot's
    // aisle is globex's, so its group's code is NULL -- and NULL <> 'A2'
    // keeps no group.
    $grouped = static fn (): mixed => $orm->query(Shelf::class)->groupBy('aisle.code')->orderBy('aisle.code');
    $report = static fn (mixed $query): array => $query->report(Group::class, ['key' => 'aisle.code', 'count' => Aggregate::Count]);
    expect($acme(static fn (): array => $report($grouped()->havingColumn('aisle.code', '<>', 'A2'))))->toEqual([new Group('A1', 2)])
        ->and($acme(static fn (): array => $report($grouped()->having(Aggregate::Count, null, '>=', 2)->orHavingColumn('aisle.code', '=', 'A2'))))
        ->toEqual([new Group('A1', 2), new Group('A2', 1)])
        ->and($across(static fn (): array => $report($grouped()->havingColumn('aisle.code', 'like', 'G%'))))->toEqual([new Group('G1', 2)])
        ->and(fn () => $report($grouped()->havingColumn('aisle.code', '=', 'A1')))->toThrow(NoTenantContext::class)
        ->and(fn () => $acme(static fn (): array => $orm->query(Shelf::class)->groupBy('name')->havingColumn('position', '>', 1)
            ->report(Group::class, ['key' => 'name'])))->toThrow(\LogicException::class, 'does not group by')
        ->and(fn () => $orm->query(Shelf::class)->groupBy('id')->havingColumn('id', '=', 'x'))->toThrow(\LogicException::class, 'is a key')
        ->and(fn () => $orm->query(Shelf::class)->orHavingColumn('name', '=', 'x'))->toThrow(\LogicException::class, 'having()')
        ->and(fn () => $orm->query(Shelf::class)->havingColumn('name', '=', null))->toThrow(\InvalidArgumentException::class);

    // distinct() on a unionAll(): each row once, as union() reads them.
    // Delta and Echo are globex's: no arm reaches them as acme.
    $both = $orm->query(Shelf::class)->where('name', 'Alpha')
        ->unionAll($orm->query(Shelf::class)->where('position', '<=', 2))
        ->unionAll($orm->query(Shelf::class)->where('name', 'Delta'));
    expect($acme(static fn (): array => names($both->orderBy('name')->fetch())))->toBe(['Alpha', 'Alpha', 'Bravo'])
        ->and($acme(static fn (): array => names($both->distinct()->orderBy('name')->fetch())))->toBe(['Alpha', 'Bravo'])
        ->and($acme(static fn (): int => $both->count()))->toBe(3)
        ->and($acme(static fn (): int => $both->distinct()->count()))->toBe(2)
        ->and($acme(static fn (): int|float => $both->distinct()->sum('position')))->toBe(3)
        ->and($acme(static fn (): int => $both->distinct()->paginate(1, 1)->total))->toBe(2)
        ->and($acme(static fn (): ?string => $both->distinct()->orderBy('name', 'desc')->first()?->name))->toBe('Bravo')
        ->and($across(static fn (): array => names($both->distinct()->orderBy('name')->fetch())))->toBe(['Alpha', 'Bravo', 'Delta', 'Echo'])
        ->and(fn () => $both->distinct()->fetch())->toThrow(NoTenantContext::class);

    // Cursor pages over a unionAll(): Alpha is read by two arms, two places
    // in the order, and each page takes up where the last left off.
    $cursors = new SignedCursors(new Signer('test-key', SignedCursors::PURPOSE), 'session');
    $ordered = $both->orderBy('name');
    $first = $acme(static fn (): mixed => $ordered->cursorPaginate(2, null, $cursors));
    $second = $acme(static fn (): mixed => $ordered->cursorPaginate(2, $first->next, $cursors));
    expect(names($first->items))->toBe(['Alpha', 'Alpha'])
        ->and($first->previous)->toBeNull()
        ->and(names($second->items))->toBe(['Bravo'])
        ->and($second->next)->toBeNull()
        ->and(names($acme(static fn (): mixed => $ordered->cursorPaginate(2, $second->previous, $cursors))->items))->toBe(['Alpha', 'Alpha']);

    // Across tenants, Delta is read twice too; one at a time, every row is
    // read once in each of the places it holds.
    $seen = [];
    $cursor = null;
    do {
        $page = $across(static fn (): mixed => $ordered->cursorPaginate(1, $cursor, $cursors));
        array_push($seen, ...names($page->items));
        $cursor = $page->next;
    } while ($cursor !== null);
    expect($seen)->toBe(['Alpha', 'Alpha', 'Bravo', 'Delta', 'Delta', 'Echo'])
        // A cursor is sealed for its query and its arm: another query,
        // or one altered, opens to nothing.
        ->and(fn () => $acme(static fn (): mixed => $orm->query(Shelf::class)->where('name', 'Alpha')
            ->unionAll($orm->query(Shelf::class)->where('position', '<=', 3))->orderBy('name')->cursorPaginate(2, $first->next, $cursors)))
        ->toThrow(InvalidCursor::class)
        ->and(fn () => $acme(static fn (): mixed => $ordered->cursorPaginate(2, $first->next . 'x', $cursors)))->toThrow(InvalidCursor::class)
        ->and(fn () => $ordered->cursorPaginate(2, null, $cursors))->toThrow(NoTenantContext::class)
        ->and(fn () => $acme(static fn (): mixed => $both->orderBy('note')->cursorPaginate(2, null, $cursors)))->toThrow(\LogicException::class, 'may be NULL');
}

function writesAlike(Orm $orm, Shelves $fixture): void
{
    $a1 = $orm->actAs('acme', static fn (): ?Aisle => $orm->query(Aisle::class)->where('code', 'A1')->first());
    \assert($a1 instanceof Aisle);
    $row = static fn (string $name, int $position, string $secret, ?string $note = null): array => [
        'name' => $name, 'position' => $position, 'note' => $note, 'secret' => $secret, 'aisle' => $a1,
    ];
    $upsert = static fn (array $rows, array $update): int => $orm->actAs(
        'acme',
        static fn (): int => $orm->query(KeyedShelf::class)->upsert($rows, 'name', update: $update),
    );
    $globex = ['Delta' => [1, 'g'], 'Echo' => [2, null]];

    // A Change in the update, worked out from the row there: Alpha's 1
    // plus 10; Kilo is new, and inserted as given. Delta is globex's name
    // too, a new row of acme's, globex's Delta untouched.
    expect($upsert([$row('Alpha', 5, 's-m', 'kept'), $row('Kilo', 7, 's-k'), $row('Delta', 3, 's-d2')], ['position' => Change::add(10), 'note']))->toBe(2)
        ->and(shelves($orm, 'acme'))->toBe([
            'Alpha' => [11, 'kept'], 'Bravo' => [2, null], 'Charlie' => [3, 'low'], 'Delta' => [3, null], 'Foxtrot' => [4, null], 'Kilo' => [7, null],
        ])
        ->and(shelves($orm, 'globex'))->toBe($globex)
        // Bounded, and a multiple: 11 * 3 held to 20; Kilo's 7 * 3 is 21,
        // held to 20 too.
        ->and($upsert([$row('Alpha', 0, 's-m'), $row('Kilo', 0, 's-k')], ['position' => Change::multiply(3)->atMost(20)]))->toBe(0)
        ->and(array_column(shelves($orm, 'acme'), 0))->toBe([20, 2, 3, 3, 4, 20])
        // A copy from a column the upsert does not set.
        ->and($upsert([$row('Charlie', 0, 's-z')], ['note' => Change::copy('secret')]))->toBe(0)
        ->and(shelves($orm, 'acme')['Charlie'])->toBe([3, 's-z']);

    // Across the line, with a Change: a new row with globex's secret is
    // refused by the index, and globex's rows are as they were.
    expect(fn () => $upsert([$row('Lima', 1, 's-b')], ['position' => Change::add(1)]))->toThrow(\PDOException::class)
        ->and(fn () => $upsert([['id' => $fixture->ids['Delta'], ...$row('Mike', 1, 's-mk')]], ['position' => Change::add(1)]))->toThrow(\PDOException::class)
        ->and(shelves($orm, 'globex'))->toBe($globex);

    // Held to what update() holds a Change to, and to what an upsert updates.
    expect(fn () => $upsert([$row('Alpha', 1, 's-m')], ['note' => Change::copy('secret'), 'secret']))->toThrow(\InvalidArgumentException::class, 'copies')
        ->and(fn () => $upsert([$row('Alpha', 1, 's-m')], ['name' => Change::add(1)]))->toThrow(\InvalidArgumentException::class, 'no key')
        ->and(fn () => $upsert([$row('Alpha', 1, 's-m')], ['position' => 4]))->toThrow(\InvalidArgumentException::class, 'with a Change')
        ->and(fn () => $upsert([$row('Alpha', 1, 's-m')], ['note' => Change::add(1)]))->toThrow(\InvalidArgumentException::class, 'no number')
        ->and(fn () => $upsert([$row('Alpha', 1, 's-m')], ['position', 'position' => Change::add(1)]))->toThrow(\InvalidArgumentException::class, 'once')
        ->and(fn () => $orm->query(KeyedShelf::class)->upsert([$row('Alpha', 1, 's-m')], 'name', update: ['position' => Change::add(1)]))
        ->toThrow(NoTenantContext::class)
        ->and(shelves($orm, 'acme')['Alpha'])->toBe([20, 'kept']);

    // A model with no tenant: the brands, whose rank adds up.
    expect($orm->query(Brand::class)->upsert([['name' => 'Zeta', 'rank' => 50], ['name' => 'Omega', 'rank' => 5]], 'name', update: ['rank' => Change::add(100)]))->toBe(1)
        ->and(array_column($fixture->pdo->query('SELECT name, "rank" FROM brands ORDER BY name')?->fetchAll(\PDO::FETCH_NUM) ?: [], 1, 0))
        ->toEqual(['Omega' => 5, 'Xi' => 3, 'Ypsilon' => 2, 'Zeta' => 101]);
}

/** @return list<string> the statements $work sends, but reads of nothing but the key */
function statements(Connection $connection, \Closure $work): array
{
    $before = \count($connection->statementLog());
    $work();

    return \array_slice($connection->statementLog(), $before);
}

describe('ADR 0123 on SQLite', function () {
    it('reads a HAVING on a grouped column, a distinct unionAll() and its cursor pages, held to the tenant', function () {
        readsAlike(Shelves::sqlite()->orm());
    });

    it('upserts with a Change, held to the tenant', function () {
        $fixture = Shelves::sqlite();
        indexes($fixture->pdo);
        writesAlike($fixture->orm(), $fixture);
    });
});

describe('ADR 0123 on Postgres, with row-level security', function () {
    it('reads as SQLite does, under the policy', function () {
        Shelves::onPostgres(static function (Shelves $fixture, PdoConnection $connection): void {
            readsAlike($fixture->orm(connection: $connection));
        });
    });

    it('writes as SQLite does, under the policy', function () {
        Shelves::onPostgres(static function (Shelves $fixture, PdoConnection $connection): void {
            writesAlike($fixture->orm(connection: $connection), $fixture);
        }, indexes(...));
    });
})->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

describe('ADR 0123 on MySQL', function () {
    it('reads as SQLite does', function () {
        Shelves::onMySql(static function (Shelves $fixture, PdoConnection $connection): void {
            readsAlike($fixture->orm(connection: $connection));
        });
    });

    it('writes as SQLite does', function () {
        Shelves::onMySql(static function (Shelves $fixture, PdoConnection $connection): void {
            writesAlike($fixture->orm(connection: $connection), $fixture);
        }, indexes(...));
    });

    it('upserts a tenant\'s rows in one statement, and reads nothing first', function () {
        Shelves::onMySql(static function (Shelves $fixture, PdoConnection $connection): void {
            $orm = $fixture->orm(connection: $connection);
            $a1 = $orm->actAs('acme', static fn (): ?Aisle => $orm->query(Aisle::class)->where('code', 'A1')->first());
            $sent = statements($connection, static fn (): int => $orm->actAs('acme', static fn (): int => $orm->query(KeyedShelf::class)->upsert([
                ['name' => 'Alpha', 'position' => 9, 'note' => null, 'secret' => 's-m', 'aisle' => $a1],
                ['name' => 'November', 'position' => 1, 'note' => null, 'secret' => 's-n', 'aisle' => $a1],
            ], 'name', update: ['position'])));

            // The write, then the rows read back by key for the identity map.
            expect(\count($sent))->toBe(2)
                ->and($sent[0])->toStartWith('INSERT INTO "shelves"')
                ->and($sent[0])->toContain('ON DUPLICATE KEY UPDATE "tenant_id" = IF(')
                ->and($sent[1])->toStartWith('SELECT')
                ->and(shelves($orm, 'acme')['Alpha'])->toBe([9, 'top']);
        }, indexes(...));
    });

    // The guard is an error under every SQL mode, on MySQL and MariaDB: a
    // NULL into a NOT NULL column would be a warning and a write outside
    // strict mode, and invalid JSON a warning and a NULL on MariaDB. Run
    // with TINVAR_TEST_MYSQL_DSN naming either server.
    it('refuses another tenant\'s row with the server out of strict mode', function () {
        Shelves::onMySql(static function (Shelves $fixture, PdoConnection $connection): void {
            $fixture->pdo->exec("SET SESSION sql_mode = 'ANSI_QUOTES'");
            $orm = $fixture->orm(connection: $connection);
            $a1 = $orm->actAs('acme', static fn (): ?Aisle => $orm->query(Aisle::class)->where('code', 'A1')->first());

            expect(fn () => $orm->actAs('acme', static fn (): int => $orm->query(KeyedShelf::class)->upsert([
                ['name' => 'Oscar', 'position' => 1, 'note' => null, 'secret' => 's-b', 'aisle' => $a1],
            ], 'name', update: ['position', 'note'])))->toThrow(\PDOException::class, 'Subquery returns more than 1 row')
                ->and(shelves($orm, 'globex'))->toBe(['Delta' => [1, 'g'], 'Echo' => [2, null]])
                ->and(array_keys(shelves($orm, 'acme')))->not->toContain('Oscar');
        }, indexes(...));
    });
})->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');
