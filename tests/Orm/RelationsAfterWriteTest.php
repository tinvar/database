<?php

declare(strict_types=1);

/**
 * "The identity map keeps loaded relations consistent after a write, with no
 * reload." (tinvar-spec.md, "Writes")
 *
 * It did not. A child moved to another parent stayed in the old parent's
 * loaded relation and never appeared in the new one's; a deleted child was
 * still listed, and an inserted one was missing. The relation-row cache those
 * collections are hydrated from was just as stale, so a parent read later in
 * the request -- the Revalidator's re-render after an action, say -- was
 * served the rows as they were before the write.
 */

namespace Tinvar\Tests\Orm\RelationsAfterWrite;

use Tinvar\Orm\Attributes\BelongsTo;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\HasMany;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\TenantScoped;
use Tinvar\Orm\Collection;
use Tinvar\Orm\DanglingRelation;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Support\Uuid;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Site;
use Tinvar\Tests\Orm\Fixtures\Tenancy;

require_once __DIR__ . '/Fixtures/models.php';
require_once __DIR__ . '/Fixtures/schema.php';
require_once __DIR__ . '/Fixtures/tenancy.php';
require_once __DIR__ . '/Fixtures/databases.php';

/** @param iterable<Rack> $racks @return list<string> */
function names(iterable $racks): array
{
    $names = [];
    foreach ($racks as $rack) {
        $names[] = $rack->name;
    }

    return $names;
}

/** @return array{Fixture, Orm, Site, Site} rack 0 at site 0, rack 1 at site 1, both relations loaded */
function twoSites(): array
{
    $fixture = new Fixture(racks: 2, unitsPerRack: 0, sites: 2);
    $orm = new Orm($fixture->connection, new IdentityMap('test'), tenants: Tenancy::flat());
    [$first, $second] = $orm->all(Site::class, 'code');

    expect(names($first->racks))->toBe(['R-01'])
        ->and(names($second->racks))->toBe(['R-02']);

    return [$fixture, $orm, $first, $second];
}

function rack(Orm $orm, string $name): Rack
{
    foreach ($orm->all(Rack::class) as $rack) {
        if ($rack->name === $name) {
            return $rack;
        }
    }

    throw new \LogicException("no rack {$name}");
}

it('moves a child between the loaded relations of its old and new parent', function () {
    [$fixture, $orm, $first, $second] = twoSites();
    $moved = rack($orm, 'R-01');

    $moved->site = $second;
    $orm->save($moved);
    $fixture->connection->resetStatementCount();

    expect(names($first->racks))->toBe([])
        ->and(names($second->racks))->toBe(['R-01', 'R-02'])
        ->and($second->racks->first())->toBe($moved, 'the same instance, not a copy')
        ->and($fixture->connection->statementCount())->toBe(0, 'no reload');
});

it('drops a deleted child from its parent\'s loaded relation', function () {
    [$fixture, $orm, , $second] = twoSites();

    $orm->delete(rack($orm, 'R-02'));
    $fixture->connection->resetStatementCount();

    expect(names($second->racks))->toBe([])
        ->and($fixture->connection->statementCount())->toBe(0);
});

it('adds an inserted child to its parent\'s loaded relation, in the relation\'s order', function () {
    [$fixture, $orm, $first] = twoSites();
    $new = (new \ReflectionClass(Rack::class))->newInstanceWithoutConstructor();
    $new->name = 'R-00';
    $new->heightU = 42;
    $new->site = $first;

    $orm->save($new);
    $fixture->connection->resetStatementCount();

    expect(names($first->racks))->toBe(['R-00', 'R-01'])
        ->and($first->racks->first())->toBe($new)
        ->and($fixture->connection->statementCount())->toBe(0);
});

it('re-sorts a loaded relation when a write changes the column it is ordered by', function () {
    [, $orm, , $second] = twoSites();
    $moved = rack($orm, 'R-01');
    $moved->site = $second;
    $orm->save($moved);

    $moved->name = 'R-99';
    $orm->save($moved);

    expect(names($second->racks))->toBe(['R-02', 'R-99']);
});

// The re-sort compared with PHP's <=>, which orders numeric strings as
// numbers: '9' before '10', where the database's order, by the text, puts
// '10' first. A fresh read and the patched relation disagreed.
it('re-sorts a loaded relation in the order the database reads it', function () {
    $fixture = new Fixture(racks: 2, unitsPerRack: 0, sites: 1);
    $fixture->connection->execute("UPDATE racks SET name = CASE name WHEN 'R-01' THEN '10' ELSE '20' END");
    $orm = new Orm($fixture->connection, new IdentityMap('test'), tenants: Tenancy::flat());
    [$site] = $orm->all(Site::class);
    expect(names($site->racks))->toBe(['10', '20']);

    $renamed = rack($orm, '20');
    $renamed->name = '9';
    $orm->save($renamed);
    $fixture->connection->resetStatementCount();

    $fresh = new Orm($fixture->connection, new IdentityMap('test'), tenants: Tenancy::flat());

    expect(names($site->racks))->toBe(['10', '9'])
        ->and($fixture->connection->statementCount())->toBe(0, 'SQLite orders text by its bytes, as strcmp() does: no reload')
        ->and(names($fresh->all(Site::class)[0]->racks))->toBe(['10', '9']);
});

// Postgres and MySQL order text by a collation no comparison in PHP
// reproduces -- 'a' before 'B' under most, after it under C -- so a
// re-sort by text there is not guessed: the group is read again.
it('reads a relation re-sorted by text again on Postgres, in the collation\'s order', function () {
    Databases::inScratchSchema('pgsql', static function (\PDO $pdo): void {
        $pdo->exec('CREATE TABLE sites (id TEXT PRIMARY KEY, code TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE racks (id TEXT PRIMARY KEY, name TEXT NOT NULL, height_u INTEGER NOT NULL, site_id TEXT NOT NULL)');
        $site = Uuid::v7();
        $pdo->prepare('INSERT INTO sites VALUES (?, ?)')->execute([$site, 'LAX']);
        foreach (['a', 'B', 'c'] as $name) {
            $pdo->prepare('INSERT INTO racks VALUES (?, ?, 42, ?)')->execute([Uuid::v7(), $name, $site]);
        }
        $connection = new PdoConnection($pdo, 'pgsql');
        $orm = new Orm($connection, new IdentityMap('test'), tenants: Tenancy::flat());
        [$read] = $orm->all(Site::class);
        names($read->racks);

        $renamed = rack($orm, 'c');
        $renamed->name = 'A';
        $orm->save($renamed);
        $kept = rack($orm, 'a');
        $kept->heightU = 40;
        $orm->save($kept);

        $fresh = new Orm($connection, new IdentityMap('test'), tenants: Tenancy::flat());

        expect(names($read->racks))->toBe(names($fresh->all(Site::class)[0]->racks))
            ->and($read->racks->isLoaded())->toBeTrue();
    });
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

// A parent whose relation was batched in but never read has nothing loaded
// to patch; what it reads later comes from the relation-row cache.
it('serves the write to a relation read afterwards, from the cache', function () {
    $fixture = new Fixture(racks: 2, unitsPerRack: 0, sites: 2);
    $orm = new Orm($fixture->connection, new IdentityMap('test'), tenants: Tenancy::flat());
    [$first, $second] = $orm->all(Site::class, 'code');
    expect(names($first->racks))->toBe(['R-01']);   // loads both sites' rows
    expect($second->racks->isLoaded())->toBeFalse();

    $moved = rack($orm, 'R-01');
    $moved->site = $second;
    $orm->save($moved);
    $fixture->connection->resetStatementCount();

    expect(names($second->racks))->toBe(['R-01', 'R-02'])
        ->and($fixture->connection->statementCount())->toBe(0);
});

it('does not serve a deleted row to a belongsTo hydrated afterwards', function () {
    $fixture = new Fixture(racks: 2, unitsPerRack: 0, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'), tenants: Tenancy::flat());
    [$firstId, $secondId] = $fixture->ids['racks'];

    $site = $orm->find(Rack::class, $firstId)?->site ?? throw new \LogicException('no site');
    expect($site->code)->toBe('LAX0');   // caches the site's row

    $orm->delete($site);
    $other = $orm->find(Rack::class, $secondId) ?? throw new \LogicException('no rack');

    expect(static fn (): string => $other->site->code)->toThrow(DanglingRelation::class);
});

it('puts loaded relations back when the write that changed them rolls back', function () {
    [, $orm, $first, $second] = twoSites();
    $moved = rack($orm, 'R-01');

    try {
        $orm->transaction(function () use ($orm, $moved, $second): never {
            $moved->site = $second;
            $orm->save($moved);

            throw new \RuntimeException('boom');
        });
    } catch (\RuntimeException) {
        // expected
    }

    expect(names($first->racks))->toBe(['R-01'])
        ->and(names($second->racks))->toBe(['R-02']);
});

#[Table('boards'), TenantScoped]
final class Board extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;

    /** @var Collection<Card> */
    #[HasMany(Card::class, orderBy: 'name')]
    public Collection $cards;
}

#[Table('cards'), TenantScoped]
final class Card extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;
    #[BelongsTo] public Board $board;
}

// Inside acrossTenants() a write can reach another tenant's row. Patching it
// into a scoped parent's loaded relation would leave it there after the
// escape hatch closed; the relation is reloaded, under the reader's scope.
it('does not patch another tenant\'s row into a loaded relation from an unscoped write', function () {
    $pdo = new \PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE boards (id TEXT PRIMARY KEY, name TEXT NOT NULL, tenant_id TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE cards (id TEXT PRIMARY KEY, name TEXT NOT NULL, board_id TEXT NOT NULL, tenant_id TEXT NOT NULL)');
    $acmeBoard = Uuid::v7();
    $globexBoard = Uuid::v7();
    $globexCard = Uuid::v7();
    $pdo->prepare('INSERT INTO boards VALUES (?, ?, ?)')->execute([$acmeBoard, 'acme-board', 'acme']);
    $pdo->prepare('INSERT INTO boards VALUES (?, ?, ?)')->execute([$globexBoard, 'globex-board', 'globex']);
    $pdo->prepare('INSERT INTO cards VALUES (?, ?, ?, ?)')->execute([Uuid::v7(), 'acme-card', $acmeBoard, 'acme']);
    $pdo->prepare('INSERT INTO cards VALUES (?, ?, ?, ?)')->execute([$globexCard, 'globex-card', $globexBoard, 'globex']);

    $orm = new Orm(new PdoConnection($pdo), new IdentityMap('test'), tenants: Tenancy::flat());
    $orm->switchTenant('acme');
    $board = $orm->find(Board::class, $acmeBoard) ?? throw new \LogicException('no board');
    expect(array_map(static fn (Card $c): string => $c->name, $board->cards->all()))->toBe(['acme-card']);

    $orm->acrossTenants(static function () use ($orm, $board, $globexCard): void {
        $card = $orm->find(Card::class, $globexCard) ?? throw new \LogicException('no card');
        $card->board = $board;
        $orm->save($card);
    });

    expect(array_map(static fn (Card $c): string => $c->name, $board->cards->all()))->toBe(['acme-card']);
});

it('fills a parent read before its child moved away, from the key it was read under', function () {
    // A #[BelongsTo] ghost names its row by the key it was made with. Since
    // a write remembers the keys it wrote (ADR 0191's review), the child's
    // key is its new parent's by the time the old one is first touched, and
    // a batch built from the children's keys alone left the ghost's own row
    // out: a DanglingRelation for a row that is there.
    $fixture = new Fixture(racks: 1, unitsPerRack: 0, sites: 2);
    [$first, $second] = (new Orm($fixture->connection, new IdentityMap('test'), tenants: Tenancy::flat()))->all(Site::class, 'code');
    // A fresh map: nothing read yet, so the rack's site is a ghost.
    $orm = new Orm($fixture->connection, new IdentityMap('test'), tenants: Tenancy::flat());
    $rack = rack($orm, 'R-01');
    $old = $rack->site;

    $rack->site = $orm->find(Site::class, $second->id);
    $orm->save($rack);

    expect($old->id)->toBe($first->id)
        ->and($old->code)->toBe($first->code);
});

it('fills such a parent inside acrossTenants() too', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 0, sites: 2);
    [$first, $second] = (new Orm($fixture->connection, new IdentityMap('test'), tenants: Tenancy::flat()))->all(Site::class, 'code');
    $orm = new Orm($fixture->connection, new IdentityMap('test'), tenants: Tenancy::flat());
    $rack = rack($orm, 'R-01');
    $old = $rack->site;

    $rack->site = $orm->find(Site::class, $second->id);
    $orm->save($rack);

    expect($orm->acrossTenants(static fn (): string => $old->code))->toBe($first->code);
});
