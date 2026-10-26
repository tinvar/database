<?php

declare(strict_types=1);

namespace Tinvar\Tests\Orm\Surface;

use Tinvar\Orm\Attributes\BelongsTo;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\BatchScheduler;
use Tinvar\Orm\Collection;
use Tinvar\Orm\Factory;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\MappingException;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Tests\Orm\Fixtures\Site;
use Tinvar\Tests\Orm\Fixtures\TenantFixture;
use Tinvar\Tests\Orm\Fixtures\Unit;

require_once __DIR__ . '/Fixtures/models.php';
require_once __DIR__ . '/Fixtures/schema.php';
require_once __DIR__ . '/Fixtures/tenant.php';

final class NoTable extends Model
{
    #[Id] public private(set) string $id;
}

#[Table('no_id')]
final class NoId extends Model
{
    #[Column] public string $name;
}

#[Table('untyped')]
final class UntypedRelation extends Model
{
    #[Id] public private(set) string $id;

    #[BelongsTo]
    public mixed $owner;
}

#[Table('odd')]
final class OddColumn extends Model
{
    #[Id] public private(set) string $id;

    #[Column] public \stdClass $blob;
}

#[Table('casts')]
final class Casts extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public float $ratio;
    #[Column] public bool $enabled;
    /** @var array<string, mixed> */
    #[Column] public array $tags;
    #[Column] public \DateTimeImmutable $at;
    #[Column] public int|string $either;
    #[Column] public mixed $anything;
}

#[Table('cabinets')]
final class Cabinet extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;
    #[BelongsTo] public ?Site $site;
}

/** @extends Factory<Rack> */
final class AmbiguousFactory extends Factory
{
    public function definition(): Rack|Unit
    {
        return new Rack();
    }
}

function sqlite(string ...$schema): Orm
{
    $pdo = new \PDO('sqlite::memory:');
    foreach ($schema as $statement) {
        $pdo->exec($statement);
    }

    return new Orm(new PdoConnection($pdo), new IdentityMap('test'));
}

function unit(string $name): Unit
{
    $unit = new Unit();
    $unit->position = 1;
    $unit->name = $name;

    return $unit;
}

describe('relation collections', function () {
    it('reads like a list', function () {
        $collection = Collection::of([unit('a'), unit('b'), unit('c')]);

        expect($collection->count())->toBe(3)
            ->and($collection->first()?->name)->toBe('a')
            ->and($collection->all())->toHaveCount(3)
            ->and($collection->map(fn (Unit $u): string => $u->name))->toBe(['a', 'b', 'c'])
            ->and($collection->filter(fn (Unit $u): bool => $u->name !== 'b'))->toHaveCount(2)
            ->and(array_map(fn (Unit $u): string => $u->name, iterator_to_array($collection)))->toBe(['a', 'b', 'c'])
            ->and(isset($collection[1]))->toBeTrue()
            ->and($collection[1]?->name)->toBe('b')
            ->and($collection[9])->toBeNull()
            ->and(Collection::of([])->first())->toBeNull();
    });

    // Lazy until read, so a relation nobody touches costs no statement.
    it('loads its contents once, on first read', function () {
        $loads = 0;
        $collection = Collection::lazy(function () use (&$loads): array {
            $loads++;

            return [unit('a')];
        });

        expect($collection->isLoaded())->toBeFalse();
        $collection->count();
        $collection->all();

        expect($collection->isLoaded())->toBeTrue()->and($loads)->toBe(1);
    });

    it('is read-only', function (\Closure $write) {
        expect(fn () => $write(Collection::of([unit('a')])))
            ->toThrow(\LogicException::class, 'Relation collections are read-only');
    })->with([
        'set' => [fn (Collection $c) => $c[0] = unit('b')],
        'append' => [fn (Collection $c) => $c[] = unit('b')],
        'unset' => [function (Collection $c): void {
            unset($c[0]);
        }],
    ]);
});

describe('model metadata', function () {
    it('refuses a model it cannot map', function (string $class, string $message) {
        expect(fn () => Metadata::for($class))->toThrow(MappingException::class, $message);
    })->with([
        'no table' => [NoTable::class, 'has no #[Table] attribute'],
        'no id' => [NoId::class, 'has no #[Id] property'],
        'untyped relation' => [UntypedRelation::class, 'has #[BelongsTo] but no class type'],
        'unsupported column' => [OddColumn::class, 'has unsupported column type stdClass'],
    ]);

    // A column name reaches SQL as an identifier, so it is checked against
    // the model's own columns, not merely quoted.
    it('refuses an order-by column the model does not have, or a malformed one', function (string $column, string $message) {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));

        expect(fn () => $orm->all(Rack::class, $column))->toThrow(MappingException::class, $message);
    })->with([
        'unknown' => ['password', 'password'],
        'malformed' => ['name; DROP TABLE racks', 'name; DROP TABLE racks'],
    ]);
});

// A fiber that suspends outside the scheduler can never be resumed; the
// render would hang until its deadline instead of failing.
it('reports a render whose fibers wait on nothing it can load', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $scheduler = new BatchScheduler(new Orm($fixture->connection, new IdentityMap('test')));

    expect(fn () => $scheduler->run(['stuck' => static fn () => \Fiber::suspend()]))
        ->toThrow(\LogicException::class, 'Render stalled: 1 fiber(s) suspended with no pending relation load.');
});

describe('hydration', function () {
    it('casts each column to its declared type', function () {
        [$row] = sqlite()->hydrateAll(Casts::class, [[
            'id' => 'c1', 'ratio' => '0.5', 'enabled' => 1, 'tags' => '{"a":1}',
            'at' => '2026-01-02 03:04:05', 'either' => 7, 'anything' => 'x',
        ]]);

        expect($row->ratio)->toBe(0.5)
            ->and($row->enabled)->toBeTrue()
            ->and($row->tags)->toBe(['a' => 1])
            ->and($row->at->format('Y-m-d H:i:s'))->toBe('2026-01-02 03:04:05')
            ->and($row->either)->toBe(7)
            ->and($row->anything)->toBe('x');
    });

    it('keeps an array or date that is already one, and reads an empty JSON value as empty', function () {
        $at = new \DateTimeImmutable('2026-01-01');
        [$row] = sqlite()->hydrateAll(Casts::class, [[
            'id' => 'c1', 'ratio' => 1, 'enabled' => 0, 'tags' => ['b' => 2], 'at' => $at, 'either' => 'y', 'anything' => null,
        ]]);
        [$empty] = sqlite()->hydrateAll(Casts::class, [[
            'id' => 'c2', 'ratio' => 1, 'enabled' => 0, 'tags' => 'null', 'at' => $at, 'either' => 'y', 'anything' => null,
        ]]);

        expect($row->tags)->toBe(['b' => 2])->and($row->at)->toBe($at)->and($empty->tags)->toBe([]);
    });

    // A partial row -- a projection, or a column added since -- leaves the
    // property unset rather than failing the read.
    it('leaves a column the row does not carry uninitialized', function () {
        [$row] = sqlite()->hydrateAll(Casts::class, [['id' => 'c1', 'ratio' => 2]]);

        expect($row->ratio)->toBe(2.0)
            ->and((new \ReflectionProperty($row, 'tags'))->isInitialized($row))->toBeFalse()
            ->and($row->isDirty())->toBeFalse();

        $row->ratio = 3.0;

        expect($row->changes())->toBe(['ratio' => 3.0]);
    });

    it('lists every set column as a change on a model never saved', function () {
        $unit = unit('a');

        expect($unit->isPersisted())->toBeFalse()
            ->and($unit->changes())->toBe(['position' => 1, 'name' => 'a']);
    });
});

describe('nullable relations', function () {
    it('leaves a relation with a null key unset, and saves it as null', function () {
        $orm = sqlite('CREATE TABLE cabinets (id TEXT PRIMARY KEY, name TEXT, site_id TEXT)');
        $orm->connection()->execute("INSERT INTO cabinets VALUES ('0190d1d8-3d2a-7000-8000-000000000001', 'a', NULL)");
        $cabinet = $orm->find(Cabinet::class, '0190d1d8-3d2a-7000-8000-000000000001');

        expect((new \ReflectionProperty($cabinet, 'site'))->isInitialized($cabinet))->toBeFalse();

        $cabinet->name = 'b';
        $orm->save($cabinet);

        expect($orm->connection()->select('SELECT name, site_id FROM cabinets'))
            ->toBe([['name' => 'b', 'site_id' => null]]);
    });
});

describe('unscoped reads', function () {
    // Outside any tenant the identity map is bypassed, so a relation is read
    // straight from the rows rather than from a shared ghost.
    it('reads a belongsTo across tenants without remembering it', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));

        $code = $orm->acrossTenants(fn () => $orm->find(Rack::class, $fixture->ids['racks'][0])?->site->code);

        expect($code)->toBeString()->not->toBe('')
            ->and($orm->identityMap()->has(Site::class, $fixture->ids['sites'][0]))->toBeFalse();
    });
});

it('deletes a tenant-scoped row only inside its own tenant', function () {
    $fixture = new TenantFixture();
    $orm = new Orm($fixture->connection, new IdentityMap('tenant-test'));
    $script = $orm->actAs('acme', fn () => $orm->find(Script::class, $fixture->scripts['acme'][0]));

    $fixture->connection->resetStatementCount();
    $orm->actAs('globex', fn () => $orm->delete($script));

    expect($fixture->connection->statementLog()[0])->toContain('tenant_id')
        ->and($orm->actAs('acme', fn () => $orm->find(Script::class, $fixture->scripts['acme'][0])))
        ->not->toBeNull('another tenant cannot delete it');
});

it('asks nothing of the database for an empty whereIn or relation load, or a flush with nothing pending', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    expect($orm->whereIn(Rack::class, 'id', []))->toBe([])
        ->and($orm->loadRelationRows(Rack::meta()->relations['units'], []))->toBe([]);
    (new BatchScheduler($orm))->flush();

    expect($fixture->connection->statementCount())->toBe(0);
});

it('stays in the same tenant without dropping what it has', function () {
    $map = new IdentityMap('test');
    $map->switchTenant('acme');
    $unit = unit('a');
    $map->remember(Unit::class, 'u1', $unit);

    $map->switchTenant('acme');

    expect($map->get(Unit::class, 'u1'))->toBe($unit);
});

it('refuses a factory whose definition does not name one model', function () {
    expect(fn () => AmbiguousFactory::model())->toThrow(\LogicException::class, 'must declare the model it returns');
});

describe('the PDO connection', function () {
    it('can stop logging statements while still counting them', function () {
        $connection = new PdoConnection(new \PDO('sqlite::memory:'));
        $connection->logSql(false);
        $connection->select('SELECT 1');

        expect($connection->statementLog())->toBe([])->and($connection->statementCount())->toBe(1);
    });

    it('connects to Postgres through PDO', function () {
        expect(fn () => PdoConnection::postgres('pgsql:host=127.0.0.1;port=1;dbname=none;connect_timeout=1'))
            ->toThrow(\PDOException::class);
    });
});
