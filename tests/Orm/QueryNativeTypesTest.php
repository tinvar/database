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

describe('on Postgres', function () {
    it('pages by a boolean whose anchor is false', function () {
        Flags::onPostgres(pagesByABoolean(...));
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
});
