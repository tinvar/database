<?php

declare(strict_types=1);

/**
 * The spec's factory: `new Rack(name: $this->faker->bothify('R-##'), heightU:
 * 42)`, then `RackFactory::new()->for($site)->count(20)->create()`
 * (tinvar-spec.md, "Factories").
 */

namespace Tinvar\Tests\Orm\FactoryFaker;

use Tinvar\Orm\Factory;
use Tinvar\Orm\Fake;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Site;

require_once __DIR__ . '/Fixtures/models.php';
require_once __DIR__ . '/Fixtures/schema.php';

/** @extends Factory<Rack> */
final class RackFactory extends Factory
{
    public function definition(): Rack
    {
        $rack = new Rack();
        $rack->name = $this->faker->bothify('R-##');
        $rack->heightU = $this->faker->randomElement([42, 48]);

        return $rack;
    }
}

it('makes the spec\'s racks: twenty, at one site, each named by the faker', function () {
    $fixture = new Fixture(racks: 0, unitsPerRack: 0, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $site = $orm->find(Site::class, $fixture->ids['sites'][0]) ?? throw new \LogicException('no site');

    $racks = RackFactory::new()->for($site)->count(20)->create($orm);

    expect($racks)->toHaveCount(20);
    foreach ($racks as $rack) {
        expect($rack->name)->toMatch('/^R-\d\d$/')
            ->and($rack->heightU)->toBeIn([42, 48])
            ->and($rack->site)->toBe($site);
    }
    expect((int) $fixture->connection->select('SELECT COUNT(*) AS n FROM racks')[0]['n'])->toBe(20);
});

it('makes the same values again from the same seed', function () {
    $names = static fn (int $seed): array => array_map(
        static fn (Rack $rack): string => $rack->name,
        RackFactory::new()->seeded($seed)->count(5)->make(),
    );

    expect($names(7))->toBe($names(7))
        ->and($names(7))->not->toBe($names(8));
});

it('fills each placeholder from its own alphabet and leaves the rest', function () {
    $fake = new Fake(1);

    expect($fake->numerify('#-#'))->toMatch('/^\d-\d$/')
        ->and($fake->lexify('??.x'))->toMatch('/^[a-z]{2}\.x$/')
        ->and($fake->bothify('#?*é'))->toMatch('/^\d[a-z][a-z0-9]é$/u')
        ->and($fake->numberBetween(3, 3))->toBe(3)
        ->and($fake->boolean(0))->toBeFalse()
        ->and($fake->boolean(100))->toBeTrue()
        ->and($fake->email())->toMatch('/^[a-z]{8}\d{2}@example\.test$/');
});

it('refuses what it cannot make', function (\Closure $call) {
    expect($call)->toThrow(\InvalidArgumentException::class);
})->with([
    'a range upside down' => [static fn () => (new Fake())->numberBetween(5, 1)],
    'nothing to pick' => [static fn () => (new Fake())->randomElement([])],
    'a chance past certain' => [static fn () => (new Fake())->boolean(101)],
]);
