<?php

declare(strict_types=1);

/**
 * Reassigning a #[BelongsTo] and saving.
 *
 * The snapshot taken on hydrate holds the foreign keys, but the diff save()
 * wrote was taken over declared columns only, so moving a row to another
 * parent was silently never written -- and with nothing else changed, the
 * model did not even read as dirty.
 */

namespace Tinvar\Tests\Orm\BelongsToWrite;

use Tinvar\Orm\Attributes\BelongsTo;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Support\Uuid;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Site;

require_once __DIR__ . '/Fixtures/models.php';
require_once __DIR__ . '/Fixtures/schema.php';

#[Table('cabinets')]
final class Cabinet extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;
    #[BelongsTo] public ?Site $site;
}

/** @return array{0: Fixture, 1: Orm, 2: Rack, 3: Site} the rack starts at site 0; site 1 is the other one */
function rackAndOtherSite(): array
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 0, sites: 2);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    return [
        $fixture,
        $orm,
        $orm->find(Rack::class, $fixture->ids['racks'][0]) ?? throw new \LogicException('no rack'),
        $orm->find(Site::class, $fixture->ids['sites'][1]) ?? throw new \LogicException('no site'),
    ];
}

function siteIdInDatabase(Fixture $fixture): string
{
    return (string) $fixture->connection->select('SELECT site_id FROM racks')[0]['site_id'];
}

it('saves a row moved to another parent', function () {
    [$fixture, $orm, $rack, $other] = rackAndOtherSite();

    $rack->site = $other;

    expect($rack->isDirty())->toBeTrue()
        ->and($rack->changes())->toBe(['site_id' => $other->id]);

    $orm->save($rack);

    expect(siteIdInDatabase($fixture))->toBe($other->id)
        ->and($rack->isDirty())->toBeFalse();
});

it('writes the move alongside a column change, in one statement', function () {
    [$fixture, $orm, $rack, $other] = rackAndOtherSite();

    $rack->site = $other;
    $rack->name = 'moved';
    $fixture->connection->resetStatementCount();
    $orm->save($rack);

    expect($fixture->connection->statementLog())->toHaveCount(1)
        ->and($fixture->connection->statementLog()[0])->toContain('"name" = ?')->toContain('"site_id" = ?')
        ->and(siteIdInDatabase($fixture))->toBe($other->id);
});

it('does not count reading a relation, or setting it to the same row, as a change', function () {
    [$fixture, $orm, $rack] = rackAndOtherSite();

    $code = $rack->site->code;
    $rack->site = $orm->find(Site::class, $fixture->ids['sites'][0]) ?? throw new \LogicException('no site');
    $fixture->connection->resetStatementCount();
    $orm->save($rack);

    expect($code)->toBe('LAX0')
        ->and($rack->isDirty())->toBeFalse()
        ->and($fixture->connection->statementCount())->toBe(0);
});

// The other rack's relation is a ghost nothing has read. Taking the moved
// rack's own row key for any unread ghost wrote the move as no change.
it('saves a move to another row\'s relation that nothing has read yet', function () {
    $fixture = new Fixture(racks: 2, unitsPerRack: 0, sites: 2);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    [$first, $second] = $orm->whereIn(Rack::class, 'id', $fixture->ids['racks']);
    $from = $first->foreignKey('site');
    $target = $second->foreignKey('site');

    $first->site = $second->site;
    $orm->save($first);

    expect($target)->not->toBe($from)
        ->and($fixture->connection->select('SELECT site_id FROM racks WHERE id = ?', [$first->id])[0]['site_id'])
        ->toBe($target);
});

it('saves a nullable relation cleared to null', function () {
    $fixture = new Fixture(racks: 0, unitsPerRack: 0, sites: 1);
    $fixture->connection->execute('CREATE TABLE cabinets (id TEXT PRIMARY KEY, name TEXT, site_id TEXT)');
    $id = Uuid::v7();
    $fixture->connection->execute('INSERT INTO cabinets VALUES (?, ?, ?)', [$id, 'a', $fixture->ids['sites'][0]]);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $cabinet = $orm->find(Cabinet::class, $id) ?? throw new \LogicException('no cabinet');

    $cabinet->site = null;
    $orm->save($cabinet);

    expect($fixture->connection->select('SELECT site_id FROM cabinets')[0]['site_id'])->toBeNull();
});

it('does not load a relation it was never asked about to diff it', function () {
    [$fixture, $orm, $rack] = rackAndOtherSite();

    $rack->name = 'renamed';
    $fixture->connection->resetStatementCount();
    $orm->save($rack);

    expect($fixture->connection->statementLog())->toHaveCount(1)
        ->and($fixture->connection->statementLog()[0])->not->toContain('site_id');
});
