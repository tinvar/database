<?php

declare(strict_types=1);

/**
 * upsert() on a #[TenantScoped] model, alike on SQLite, Postgres (under
 * row-level security) and MySQL (ADR 0073). MySQL's ON DUPLICATE KEY UPDATE
 * answers to any unique index a row collides with, so there the builder
 * finds the conflict itself: these tests give the table a primary key and a
 * unique index the tenant column does not lead, and try to reach globex's
 * rows as acme through each.
 */

namespace Tinvar\Tests\Orm\QueryTenantUpsert;

use Tinvar\Orm\Attributes\BelongsTo;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\TenantScoped;
use Tinvar\Orm\Attributes\Unique;
use Tinvar\Orm\Model;
use Tinvar\Orm\NoTenantContext;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Tests\Orm\Fixtures\Aisle;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Orm\Fixtures\Shelves;

require_once __DIR__ . '/Fixtures/shelves.php';

/** The shelves table, its name a tenant's key. */
#[Table('shelves'), TenantScoped, Unique('name')]
final class NamedShelf extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;
    #[Column] public int $position;
    #[Column] public ?string $note = null;
    #[Column] public string $secret;

    #[BelongsTo] public Aisle $aisle;
}

/**
 * The key upsert() conflicts on, the tenant column leading it, and a
 * secret unique across every tenant: the index MySQL's ON DUPLICATE KEY
 * UPDATE would have answered to.
 */
function indexes(\PDO $pdo): void
{
    $pdo->exec('CREATE UNIQUE INDEX shelves_tenant_id_name_unique ON shelves (tenant_id, name)');
    $pdo->exec('CREATE UNIQUE INDEX shelves_secret_unique ON shelves (secret)');
}

/** @return array<string, array{int, ?string, string}> each shelf of $tenant's: position, note, secret */
function shelves(Orm $orm, string $tenant): array
{
    $out = [];
    foreach ($orm->actAs($tenant, static fn (): array => $orm->query(NamedShelf::class)->orderBy('name')->fetch()) as $shelf) {
        $out[$shelf->name] = [$shelf->position, $shelf->note, $shelf->secret];
    }

    return $out;
}

function upsertsWithinTheTenant(Orm $orm, Shelves $fixture): void
{
    $a1 = $orm->actAs('acme', static fn (): ?Aisle => $orm->query(Aisle::class)->where('code', 'A1')->first());
    \assert($a1 instanceof Aisle);
    $row = static fn (string $name, int $position, string $secret, ?string $note = null): array => [
        'name' => $name, 'position' => $position, 'note' => $note, 'secret' => $secret, 'aisle' => $a1,
    ];
    $upsert = static fn (array $rows, ?array $update = ['position', 'note']): int => $orm->actAs(
        'acme',
        static fn (): int => $orm->query(NamedShelf::class)->upsert($rows, 'name', update: $update),
    );
    $globex = ['Delta' => [1, 'g', 's-b'], 'Echo' => [2, null, 's-c']];

    // Alpha is acme's and is updated -- its note to NULL, the first row's,
    // which a derived table must carry as one. Delta is globex's name too:
    // as acme it is a new row of acme's, and globex's Delta is untouched.
    expect($upsert([$row('Alpha', 10, 's-m'), $row('Delta', 20, 's-d2', 'new'), $row('Golf', 30, 's-g')]))->toBe(2)
        ->and(shelves($orm, 'acme'))->toBe([
            'Alpha' => [10, null, 's-m'],
            'Bravo' => [2, null, 's-a'],
            'Charlie' => [3, 'low', 's-z'],
            'Delta' => [20, 'new', 's-d2'],
            'Foxtrot' => [4, null, 's-q'],
            'Golf' => [30, null, 's-g'],
        ])
        ->and(shelves($orm, 'globex'))->toBe($globex)
        // update: [] leaves a row there as it was.
        ->and($upsert([$row('Alpha', 99, 's-m'), $row('Hotel', 5, 's-h')], []))->toBe(1)
        ->and(shelves($orm, 'acme')['Alpha'])->toBe([10, null, 's-m']);

    // Across the line through every index there is: a new row with
    // globex's secret, an existing row given it, and a new row bringing the
    // primary key of globex's Delta. Each is refused by the database, the
    // statement's transaction rolled back, and globex's rows are as they were.
    $deltaId = $fixture->ids['Delta'];
    expect(fn () => $upsert([$row('India', 1, 's-b')]))->toThrow(\PDOException::class)
        ->and(fn () => $upsert([$row('Alpha', 1, 's-b')], ['secret']))->toThrow(\PDOException::class)
        ->and(fn () => $upsert([['id' => $deltaId, ...$row('Juliet', 1, 's-j')]]))->toThrow(\PDOException::class)
        ->and(shelves($orm, 'globex'))->toBe($globex)
        ->and(shelves($orm, 'acme')['Alpha'])->toBe([10, null, 's-m'])
        ->and(array_keys(shelves($orm, 'acme')))->toBe(['Alpha', 'Bravo', 'Charlie', 'Delta', 'Foxtrot', 'Golf', 'Hotel'])
        // Fails closed with no tenant, and inside acrossTenants(), which
        // lifts reads, not whose a new row is.
        ->and(fn () => $orm->query(NamedShelf::class)->upsert([$row('Kilo', 1, 's-k')], 'name'))->toThrow(NoTenantContext::class)
        ->and(fn () => $orm->acrossTenants(static fn (): int => $orm->query(NamedShelf::class)->upsert([$row('Kilo', 1, 's-k')], 'name')))
        ->toThrow(NoTenantContext::class)
        ->and($orm->acrossTenants(static fn (): int => $orm->query(NamedShelf::class)->count()))->toBe(9);
}

describe('a tenant\'s upsert', function () {
    it('updates the tenant\'s row a key names, inserts the rest, and reaches no other tenant\'s on SQLite', function () {
        $fixture = Shelves::sqlite();
        indexes($fixture->pdo);
        upsertsWithinTheTenant($fixture->orm(), $fixture);
    });

    it('does the same on Postgres, under the policy', function () {
        Shelves::onPostgres(static function (Shelves $fixture, PdoConnection $connection): void {
            upsertsWithinTheTenant($fixture->orm(connection: $connection), $fixture);
        }, indexes(...));
    })->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

    it('does the same on MySQL, which finds the conflict without ON DUPLICATE KEY UPDATE', function () {
        Shelves::onMySql(static function (Shelves $fixture, PdoConnection $connection): void {
            upsertsWithinTheTenant($fixture->orm(connection: $connection), $fixture);
        }, indexes(...));
    })->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');

    it('refuses on MySQL two rows its collation takes for one key', function () {
        Shelves::onMySql(static function (Shelves $fixture, PdoConnection $connection): void {
            $orm = $fixture->orm(connection: $connection);
            $a1 = $orm->actAs('acme', static fn (): ?Aisle => $orm->query(Aisle::class)->where('code', 'A1')->first());

            expect(fn () => $orm->actAs('acme', static fn (): int => $orm->query(NamedShelf::class)->upsert([
                ['name' => 'alpha', 'position' => 1, 'note' => null, 'secret' => 's-1', 'aisle' => $a1],
                ['name' => 'ALPHA', 'position' => 2, 'note' => null, 'secret' => 's-2', 'aisle' => $a1],
            ], 'name')))->toThrow(\InvalidArgumentException::class, 'as the database compares it')
                ->and($orm->actAs('acme', static fn (): array => $orm->query(NamedShelf::class)->where('name', 'Alpha')->pluck('position')))->toEqual([1]);
        }, indexes(...));
    })->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');
});
