<?php

declare(strict_types=1);

/**
 * Raw SQL on tenant tables, flagged (tinvar-spec.md, "Tenancy"): what
 * RawSql reads a statement for, and the flag the Orm raises -- for the dev
 * toolbar -- on each one sent through connection() that names a tenant
 * table, while the ORM's own statements and the query builder's, which
 * carry the predicate, go unflagged (ADR 0057).
 */

namespace Tinvar\Tests\Orm\RawSql;

use Tinvar\Orm\RawSql;
use Tinvar\Orm\RawStatement;
use Tinvar\Orm\Query;
use Tinvar\Tests\Orm\Fixtures\Brand;
use Tinvar\Tests\Orm\Fixtures\Shelf;
use Tinvar\Tests\Orm\Fixtures\Shelves;

require_once __DIR__ . '/Fixtures/shelves.php';

describe('RawSql', function () {
    it('finds the tenant tables a statement names, however it spells them', function (string $sql, array $found) {
        expect(RawSql::tenantTablesIn($sql, ['shelves', 'app.items']))->toBe($found);
    })->with([
        'bare' => ['SELECT * FROM shelves', ['shelves']],
        'any case' => ['select * from SHELVES', ['shelves']],
        'quoted' => ['SELECT * FROM "shelves" s', ['shelves']],
        'backticks' => ['SELECT * FROM `shelves`', ['shelves']],
        'a schema' => ['SELECT * FROM "app"."items"', ['app.items']],
        'its last part' => ['UPDATE items SET x = 1', ['app.items']],
        'both' => ['SELECT * FROM shelves JOIN app.items ON 1 = 1', ['shelves', 'app.items']],
        'a subquery' => ['SELECT id FROM brands WHERE id IN (SELECT brand_id FROM shelves)', ['shelves']],
        'in a literal' => ["SELECT 'shelves', 'it''s shelves' AS x FROM brands", []],
        'in a comment' => ["SELECT 1 -- shelves\n/* items */", []],
        'nothing' => ['SELECT 1', []],
        // Each read otherwise by a database than by the pattern, which may
        // hide a table there: flagged as naming every one.
        'a comment MySQL runs' => ['SELECT * FROM /*! shelves */', ['shelves', 'app.items']],
        'a backslash in a literal' => ["SELECT '\\'', x FROM shelves WHERE a = ''", ['shelves', 'app.items']],
        'a dollar quote, a name on MySQL' => ['SELECT id AS $x$ FROM shelves ORDER BY $x$', ['shelves', 'app.items']],
        '-- with no space, no comment on MySQL' => ["SELECT 5--1 FROM shelves\n", ['shelves', 'app.items']],
        // A bracket is a quoted name on SQL Server and an array on Postgres,
        // whose ARRAY[...] holds SQL: read inside, not taken for one name.
        'inside a Postgres array' => ['SELECT ARRAY[(SELECT name FROM shelves)]', ['shelves']],
        'inside an array in ANY()' => ['SELECT 1 FROM brands WHERE id = ANY(ARRAY[(SELECT shelf_id FROM items)])', ['app.items']],
        'beside an array subscript' => ['SELECT (ARRAY[1,2])[1], name FROM shelves', ['shelves']],
        'a bracketed name' => ['SELECT * FROM [shelves]', ['shelves']],
        'a literal inside an array it cannot read' => ["SELECT ARRAY['\\'', 1] FROM brands", ['shelves', 'app.items']],
    ]);

    it('shows a statement with its literals taken out, cut short', function () {
        expect(RawSql::redacted("SELECT * FROM t WHERE token = 'secret-123' AND n = 4"))->toBe("SELECT * FROM t WHERE token = '?' AND n = 4")
            ->and(\strlen(RawSql::redacted('SELECT ' . str_repeat('x, ', 2000) . '1')))->toBe(RawSql::MAX_SHOWN + 3);
    });
});

describe('the flag', function () {
    it('is raised for raw SQL on a tenant table sent through connection(), with where it was sent from', function () {
        $fixture = Shelves::sqlite();
        $orm = $fixture->orm();
        $flagged = [];
        $orm->flagRawStatements(static function (RawStatement $statement) use (&$flagged): void {
            $flagged[] = $statement;
        });
        // A model compiled is one whose table the flag knows.
        $orm->actAs('acme', static fn (): int => $orm->query(Shelf::class)->count());

        $line = __LINE__ + 1;
        $orm->actAs('acme', static fn (): array => $orm->connection()->select("SELECT name FROM shelves WHERE secret = 's-m'"));
        $orm->acrossTenants(static fn (): int => $orm->connection()->execute('UPDATE shelves SET note = ? WHERE 1 = 0', ['x']));
        $orm->connection()->select('SELECT name FROM brands');

        expect($flagged)->toHaveCount(2)
            ->and($flagged[0]->sql)->toBe("SELECT name FROM shelves WHERE secret = '?'")
            ->and($flagged[0]->tables)->toBe(['shelves'])
            ->and($flagged[0]->site)->toBe(__FILE__ . ":{$line}")
            ->and($flagged[0]->tenant)->toBe('acme')
            ->and($flagged[0]->acrossTenants)->toBeFalse()
            ->and($flagged[1]->acrossTenants)->toBeTrue();
    });

    it('is not raised by the ORM or the query builder, which carry the predicate', function () {
        $fixture = Shelves::sqlite();
        $connection = $fixture->connection();
        $orm = $fixture->orm(connection: $connection);
        $flagged = 0;
        $orm->flagRawStatements(static function () use (&$flagged): void {
            $flagged++;
        });

        $orm->actAs('acme', static function () use ($orm): void {
            $alpha = $orm->find(Shelf::class, $orm->query(Shelf::class)->where('name', 'Alpha')->pluck('id')[0]);
            \assert($alpha instanceof Shelf);
            $alpha->items->count();
            $orm->query(Shelf::class)->whereHas('items', static fn (Query $q): Query => $q->where('priceCents', '>', 1))->update(['note' => 'x']);
            $alpha->note = 'y';
            $orm->save($alpha);
            $orm->query(Brand::class)->upsert([['name' => 'Xi', 'rank' => 4]], 'name');
        });

        expect($flagged)->toBe(0)
            ->and($connection->statementCount())->toBeGreaterThan(5)
            // Counted and logged as ever, through it or not.
            ->and($orm->connection()->statementCount())->toBe($connection->statementCount());
    });

    it('stops when it is turned off', function () {
        $fixture = Shelves::sqlite();
        $orm = $fixture->orm();
        $flagged = 0;
        $orm->flagRawStatements(static function () use (&$flagged): void {
            $flagged++;
        });
        $orm->actAs('acme', static fn (): int => $orm->query(Shelf::class)->count());
        $orm->flagRawStatements(null);

        $orm->connection()->select('SELECT name FROM shelves');

        expect($flagged)->toBe(0)
            ->and($orm->connection())->toBe($orm->statements());
    });
});

// ADR 0122: what a select() may write, for the guard a widened read runs.
it('reads a statement as one that may write by any word a write holds, and by text it cannot read', function () {
    expect(RawSql::writes('SELECT id, name FROM shelves WHERE note = ?'))->toBeFalse()
        ->and(RawSql::writes("SELECT 'delete' AS word -- update"))->toBeFalse()
        ->and(RawSql::writes('WITH gone AS (DELETE FROM shelves RETURNING id) SELECT COUNT(*) FROM gone'))->toBeTrue()
        ->and(RawSql::writes('SELECT * INTO copied FROM shelves'))->toBeTrue()
        ->and(RawSql::writes('select 1; update shelves set name = 1'))->toBeTrue()
        ->and(RawSql::writes('SELECT 1 /*! DELETE FROM shelves */'))->toBeTrue()
        ->and(RawSql::writes('SELECT ARRAY[(SELECT 1 FROM (DELETE FROM shelves RETURNING 1) x)]'))->toBeTrue()
        ->and(RawSql::writes('SELECT (ARRAY[1, 2])[1]'))->toBeFalse();
});
