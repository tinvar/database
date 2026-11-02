<?php

declare(strict_types=1);

/**
 * Orm::observeWrites(): every observer is told of every row written, so
 * search, an audit log and whatever comes next each hear of it -- one
 * registering never silences another. An owner registering again replaces
 * only its own observer; null takes only its own away.
 */

use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Tenancy;

require_once __DIR__ . '/Fixtures/models.php';
require_once __DIR__ . '/Fixtures/schema.php';
require_once __DIR__ . '/Fixtures/tenant.php';
require_once __DIR__ . '/Fixtures/tenancy.php';

/** @return array{Orm, Rack} an Orm over a fresh fixture, and one of its racks */
function observedRack(): array
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'), tenants: Tenancy::flat());

    return [$orm, $orm->find(Rack::class, $fixture->ids['racks'][0])];
}

/** An observer's owner, and what it was told. */
final class WriteLog
{
    /** @var list<string> */
    public array $told = [];

    public function observe(Orm $orm, string $as): void
    {
        $orm->observeWrites($this, function (string $class, string $id, bool $deleted) use ($as): void {
            $this->told[] = "{$as}:" . new ReflectionClass($class)->getShortName() . ($deleted ? ':deleted' : '');
        });
    }
}

it('tells each observer of a write, in the order they registered', function () {
    [$orm, $rack] = observedRack();
    $told = [];
    $search = new WriteLog();
    $audit = new WriteLog();
    $orm->observeWrites($search, static function () use (&$told): void {
        $told[] = 'search';
    });
    $orm->observeWrites($audit, static function () use (&$told): void {
        $told[] = 'audit';
    });

    $rack->name = 'renamed';
    $orm->save($rack);

    expect($told)->toBe(['search', 'audit']);
});

it('replaces only an owner\'s own observer when it registers again, and takes only its own away with null', function () {
    [$orm, $rack] = observedRack();
    $search = new WriteLog();
    $audit = new WriteLog();
    $search->observe($orm, 'first');
    $audit->observe($orm, 'audit');
    $search->observe($orm, 'again');

    $rack->name = 'renamed';
    $orm->save($rack);
    $orm->observeWrites($audit, null);
    // Kept across flush(), a request's reset: the application's wiring.
    $orm->flush();
    $orm->delete($rack);

    // Told once per write, by its latest observer -- never twice.
    expect($search->told)->toBe(['again:Rack', 'again:Rack:deleted'])
        ->and($audit->told)->toBe(['audit:Rack']);
});
