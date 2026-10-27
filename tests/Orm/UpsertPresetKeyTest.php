<?php

declare(strict_types=1);

/**
 * An upsert whose row brings the key of the very row its unique key finds
 * updates that row: it counts as no insert, and a model the identity map
 * holds for it takes what was stored -- not the new row's fields an
 * update: [] left as they were. It counted as inserted, and the model
 * showed a rank the database never held, on all three databases.
 */

namespace Tinvar\Tests\Orm\UpsertPresetKey;

use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Tests\Orm\Fixtures\Aisle;
use Tinvar\Tests\Orm\Fixtures\Brand;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Orm\Fixtures\Shelves;

require_once __DIR__ . '/Fixtures/shelves.php';

function updatesTheRowItNames(Shelves $fixture, Orm $orm): void
{
    $rank = static fn (): int => (int) $fixture->pdo->query('SELECT "rank" FROM brands WHERE name = \'Zeta\'')?->fetchColumn();
    $zeta = $orm->find(Brand::class, $fixture->ids['Zeta']);
    $row = ['id' => $fixture->ids['Zeta'], 'name' => 'Zeta', 'rank' => 9];

    expect($orm->query(Brand::class)->upsert([$row], 'name', update: []))->toBe(0)
        ->and($rank())->toBe(1)
        ->and($zeta?->rank)->toBe(1)
        ->and($orm->query(Brand::class)->upsert([$row], 'name', update: ['rank']))->toBe(0)
        ->and($rank())->toBe(9)
        ->and($zeta?->rank)->toBe(9)
        // A new row beside it still counts.
        ->and($orm->query(Brand::class)->upsert([['id' => $fixture->ids['Xi'], 'name' => 'Xi', 'rank' => 3], ['name' => 'Omega', 'rank' => 4]], 'name'))->toBe(1);

    // A tenant's row, on MySQL found by upsertLocked().
    expect($orm->actAs('acme', static fn (): int => $orm->query(Aisle::class)
        ->upsert([['id' => $fixture->ids['A1'], 'code' => 'A1']], 'code', update: [])))->toBe(0);
}

it('counts and follows a preset key\'s own row as updated, on SQLite', function () {
    $fixture = Shelves::sqlite();
    updatesTheRowItNames($fixture, $fixture->orm());
});

it('does on Postgres', function () {
    Shelves::onPostgres(static fn (Shelves $fixture, PdoConnection $connection) => updatesTheRowItNames($fixture, $fixture->orm(connection: $connection)));
})->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

it('does on MySQL', function () {
    Shelves::onMySql(static fn (Shelves $fixture, PdoConnection $connection) => updatesTheRowItNames($fixture, $fixture->orm(connection: $connection)));
})->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');
