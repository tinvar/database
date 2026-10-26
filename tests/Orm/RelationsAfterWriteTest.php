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
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Site;
use Tinvar\Tests\Orm\Fixtures\Tenancy;

require_once __DIR__ . '/Fixtures/models.php';
require_once __DIR__ . '/Fixtures/schema.php';
require_once __DIR__ . '/Fixtures/tenancy.php';

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
