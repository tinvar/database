<?php

declare(strict_types=1);

/**
 * A LIKE has one escape character on every database, `!` (ADR 0208), and
 * Query::escapeLike() writes text a pattern matches literally: `%`, `_`
 * and `!` in a search term are characters, not wildcards. SQLite has no
 * default escape character at all, and Postgres and MySQL take a
 * backslash, so before ESCAPE was written the same escaped term matched
 * differently on each.
 */

namespace Tinvar\Tests\Orm\LikeEscape;

use Tinvar\Orm\Aggregate;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Query;
use Tinvar\Support\Uuid;
use Tinvar\Tests\Orm\Fixtures\Brand;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Orm\Fixtures\Shelves;

require_once __DIR__ . '/Fixtures/shelves.php';

final readonly class Named
{
    public function __construct(public ?string $name = null, public int $count = 0) {}
}

function brands(\PDO $pdo): void
{
    $rank = 10;
    foreach (['50% off', '50 percent off', 'a_b', 'axb', 'a!b', 'c\\%d', 'c\\xd'] as $name) {
        $pdo->prepare('INSERT INTO brands VALUES (?, ?, ?)')->execute([Uuid::v7(), $name, $rank++]);
    }
}

/** @return list<string> */
function matching(Orm $orm, string $operator, string $pattern): array
{
    $names = array_map(
        static fn (Brand $brand): string => $brand->name,
        $orm->query(Brand::class)->where('name', $operator, $pattern)->orderBy('rank')->fetch(),
    );
    sort($names);

    return $names;
}

/**
 * The same, through whereRaw(): its LIKE is written with the builder's
 * ESCAPE '!' too, so an escaped term matches literally there as well.
 *
 * @param list<string> $bindings
 * @return list<string>
 */
function matchingRaw(Orm $orm, string $sql, array $bindings): array
{
    $names = array_map(
        static fn (Brand $brand): string => $brand->name,
        $orm->query(Brand::class)->whereRaw($sql, $bindings)->orderBy('rank')->fetch(),
    );
    sort($names);

    return $names;
}

function escapesAlike(Orm $orm): void
{
    $contains = static fn (string $text): string => '%' . Query::escapeLike($text) . '%';

    expect(Query::escapeLike('50%_a!b'))->toBe('50!%!_a!!b')
        ->and(matching($orm, 'like', $contains('50%')))->toBe(['50% off'])
        ->and(matching($orm, 'like', $contains('a_b')))->toBe(['a_b'])
        ->and(matching($orm, 'like', $contains('a!b')))->toBe(['a!b'])
        // A backslash is a character like any other on every database.
        ->and(matching($orm, 'like', $contains('c\\%d')))->toBe(['c\\%d'])
        ->and(matching($orm, 'like', 'c\\_d'))->toBe(['c\\%d', 'c\\xd'])
        // Unescaped, the wildcards are wildcards still.
        ->and(matching($orm, 'like', '50%off'))->toBe(['50 percent off', '50% off'])
        ->and(matching($orm, 'like', 'a_b'))->toBe(['a!b', 'a_b', 'axb'])
        ->and(matching($orm, 'not like', 'a' . Query::escapeLike('_') . '%'))->not->toContain('a_b')
        ->and(matching($orm, 'not like', 'a' . Query::escapeLike('_') . '%'))->toContain('axb');

    // A raw condition's LIKE takes the same escape character.
    expect(matchingRaw($orm, 'name LIKE ?', [$contains('50%')]))->toBe(['50% off'])
        ->and(matchingRaw($orm, 'name LIKE ?', [$contains('a_b')]))->toBe(['a_b'])
        ->and(matchingRaw($orm, 'name LIKE ?', [$contains('a!b')]))->toBe(['a!b'])
        ->and(matchingRaw($orm, 'LOWER(name) LIKE LOWER(?)', [$contains('A_B')]))->toBe(['a_b'])
        ->and(matchingRaw($orm, '(name LIKE (?)) AND rank > ?', [$contains('a_b'), 0]))->toBe(['a_b'])
        ->and(matchingRaw($orm, 'name NOT LIKE ? AND name LIKE ?', ['a' . Query::escapeLike('_') . '%', 'a%']))->toBe(['a!b', 'axb'])
        ->and(matchingRaw($orm, 'name LIKE ?', ['c\\_d']))->toBe(['c\\%d', 'c\\xd']);

    // A group's column is compared by the same rules.
    $report = $orm->query(Brand::class)->groupBy('name')->havingColumn('name', 'like', $contains('a_b'))
        ->report(Named::class, ['name' => 'name', 'count' => Aggregate::Count]);
    expect($report)->toEqual([new Named('a_b', 1)]);
}

describe('LIKE on SQLite', function () {
    it('escapes with ! and matches an escaped term literally', function () {
        $fixture = Shelves::sqlite();
        brands($fixture->pdo);
        escapesAlike($fixture->orm());
    });
});

describe('LIKE on Postgres', function () {
    it('escapes as SQLite does', function () {
        Shelves::onPostgres(static function (Shelves $fixture, PdoConnection $connection): void {
            escapesAlike($fixture->orm(connection: $connection));
        }, brands(...));
    });
})->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

describe('LIKE on MySQL', function () {
    it('escapes as SQLite does', function () {
        Shelves::onMySql(static function (Shelves $fixture, PdoConnection $connection): void {
            escapesAlike($fixture->orm(connection: $connection));
        }, brands(...));
    });
})->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');
