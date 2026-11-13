<?php

declare(strict_types=1);

/**
 * What ADR 0208 closed in the ORM beside LIKE's escape (LikeEscapeTest).
 */

namespace Tinvar\Tests\Orm\ClosedLows;

use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Support\Uuid;
use Tinvar\Tests\Orm\Fixtures\Brand;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Orm\Fixtures\Shelves;

require_once __DIR__ . '/Fixtures/shelves.php';

function numericNames(\PDO $pdo): void
{
    foreach ([['1000', 10], ['1e3', 11], ['10', 12], ['010', 13]] as [$name, $rank]) {
        $pdo->prepare('INSERT INTO brands VALUES (?, ?, ?)')->execute([Uuid::v7(), $name, $rank]);
    }
}

/** @return list<string> */
function whereInNames(Orm $orm, array $values): array
{
    $names = array_map(static fn (Brand $brand): string => $brand->name, $orm->whereIn(Brand::class, 'name', $values));
    sort($names, \SORT_STRING);

    return $names;
}

function keepsEveryValue(Orm $orm): void
{
    // Numeric strings are equal to PHP's ==, and are different text.
    expect(whereInNames($orm, ['1000', '1e3']))->toBe(['1000', '1e3'])
        ->and(whereInNames($orm, ['10', '010', '10']))->toBe(['010', '10'])
        ->and(whereInNames($orm, ['Zeta', 'Zeta']))->toBe(['Zeta']);
}

describe('Orm::whereIn() de-duplicates its values strictly', function () {
    it('on SQLite', function () {
        $fixture = Shelves::sqlite();
        numericNames($fixture->pdo);
        keepsEveryValue($fixture->orm());
    });

    it('on Postgres', function () {
        Shelves::onPostgres(static function (Shelves $fixture, PdoConnection $connection): void {
            keepsEveryValue($fixture->orm(connection: $connection));
        }, numericNames(...));
    })->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

    it('on MySQL', function () {
        Shelves::onMySql(static function (Shelves $fixture, PdoConnection $connection): void {
            keepsEveryValue($fixture->orm(connection: $connection));
        }, numericNames(...));
    })->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');
});
