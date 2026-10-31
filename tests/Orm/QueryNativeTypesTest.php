<?php

declare(strict_types=1);

/**
 * The query builder over each database's own column types: a uuid key and
 * a boolean, which Postgres compares with neither text nor an empty string
 * (Fixtures/flags.php). Each group is skipped, saying why, without its
 * server.
 */

namespace Tinvar\Tests\Orm\QueryNativeTypes;

use Tinvar\Orm\CursorPage;
use Tinvar\Orm\Orm;
use Tinvar\Orm\SignedCursors;
use Tinvar\Support\Signer;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Orm\Fixtures\Flag;
use Tinvar\Tests\Orm\Fixtures\Flags;

require_once __DIR__ . '/Fixtures/flags.php';

/** @param list<Flag> $flags @return list<string> */
function names(array $flags): array
{
    return array_map(static fn (Flag $flag): string => $flag->name, $flags);
}

/**
 * A page anchored on a row whose sort value is false: the anchor's values,
 * read back as PHP's false, were bound as '' -- which Postgres refuses for
 * a boolean.
 */
function pagesByABoolean(Orm $orm): void
{
    $cursors = new SignedCursors(new Signer(str_repeat('k', 32), SignedCursors::PURPOSE), 'session');
    $query = $orm->query(Flag::class)->orderBy('done')->orderBy('name');

    $pages = [];
    $cursor = null;
    do {
        $page = $query->cursorPaginate(1, $cursor, $cursors);
        $pages[] = names($page->items);
        $cursor = $page->next;
    } while ($cursor !== null);

    $back = $query->cursorPaginate(1, $query->cursorPaginate(1, $query->cursorPaginate(1, null, $cursors)->next, $cursors)->previous, $cursors);

    expect($pages)->toBe([['a'], ['b'], ['c'], ['d']])
        ->and($back)->toBeInstanceOf(CursorPage::class)
        ->and(names($back->items))->toBe(['a'])
        ->and(names($orm->query(Flag::class)->where('done', false)->orderBy('name')->fetch()))->toBe(['a', 'b'])
        ->and(names($orm->query(Flag::class)->where('done', true)->orderBy('name')->fetch()))->toBe(['c', 'd']);
}

/**
 * A key compared by a range or a pattern: Postgres refuses text that is not
 * a UUID for a uuid column, with an error quoting it, and has no LIKE for
 * one at all -- a 500 for what SQLite answered.
 */
function comparesAKey(Orm $orm, Flags $flags): void
{
    $after = $flags->ids['b'];
    $expected = array_keys(array_filter($flags->ids, static fn (string $id): bool => strcmp($id, $after) > 0));
    sort($expected);

    expect(fn () => $orm->query(Flag::class)->where('id', 'like', 'a%'))
        ->toThrow(\InvalidArgumentException::class, 'LIKE')
        ->and(fn () => $orm->query(Flag::class)->where('id', 'not like', 'a%'))->toThrow(\InvalidArgumentException::class, 'LIKE')
        ->and(fn () => $orm->query(Flag::class)->where('id', '>', 'abc'))->toThrow(\InvalidArgumentException::class, 'UUID')
        ->and(names($orm->query(Flag::class)->where('id', '>', $after)->orderBy('name')->fetch()))->toBe($expected)
        ->and(names($orm->query(Flag::class)->where('id', '>=', '00000000-0000-0000-0000-000000000000')->orderBy('name')->fetch()))
        ->toBe(['a', 'b', 'c', 'd']);
}

/**
 * A sum past PHP's integers, which Postgres answers as a numeric's text:
 * sum() read it as the largest integer, cut off, where a report's Sum read
 * it as a float. Both read it one way now.
 */
function sumsPastAnInteger(Orm $orm): void
{
    $sum = $orm->query(Flag::class)->sum('weight');

    expect($sum)->toBeFloat()
        ->and($sum)->toBe((float) \PHP_INT_MAX + 1)
        ->and($orm->query(Flag::class)->where('name', 'a')->sum('weight'))->toBe(\PHP_INT_MAX);
}

describe('on Postgres', function () {
    it('sums past an integer as a report does', function () {
        Flags::onPostgres(sumsPastAnInteger(...));
    });

    it('pages by a boolean whose anchor is false', function () {
        Flags::onPostgres(pagesByABoolean(...));
    });

    it('compares a key by a range, and refuses a pattern', function () {
        Flags::onPostgres(comparesAKey(...));
    });
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

describe('on Postgres through the async driver', function () {
    it('pages by a boolean whose anchor is false', function () {
        Flags::onPostgresAsync(pagesByABoolean(...));
    });
})->group('pgsql')->skip(Databases::asyncPostgresMissing() !== null, Databases::asyncPostgresMissing() ?? '');

describe('on MySQL', function () {
    it('pages by a boolean whose anchor is false', function () {
        Flags::onMySql(pagesByABoolean(...));
    });

    it('compares a key by a range, and refuses a pattern', function () {
        Flags::onMySql(comparesAKey(...));
    });
})->group('mysql')->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');

describe('on MySQL through the async driver', function () {
    it('pages by a boolean whose anchor is false', function () {
        Flags::onMySqlAsync(pagesByABoolean(...));
    });
})->group('mysql')->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');

describe('on SQLite', function () {
    it('pages by a boolean whose anchor is false', function () {
        pagesByABoolean(Flags::sqlite());
    });

    it('compares a key by a range, and refuses a pattern', function () {
        Flags::onSqlite(comparesAKey(...));
    });
});
