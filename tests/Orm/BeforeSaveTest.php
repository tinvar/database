<?php

declare(strict_types=1);

/**
 * #[BeforeSave], as the spec's model has it:
 *
 *     #[BeforeSave]
 *     private function normalize(): void { $this->name = trim($this->name); }
 *
 * save() calls a model's hooks before it works out the diff, its traits'
 * too, in an order fixed when the model compiles: the class's own top to
 * bottom, then its traits' in the order it uses them, then a parent's.
 */

namespace Tinvar\Tests\Orm\BeforeSave;

use Tinvar\Orm\Attributes\BeforeSave;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\MappingException;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\ReadOnlyViolation;

#[Table('racks')]
final class Rack extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;

    /** Not a column: what the hooks did, in order. */
    public int $normalized = 0;

    public bool $refuse = false;

    public ?Orm $sawTransactionOn = null;

    public bool $inTransaction = false;

    #[BeforeSave]
    private function normalize(): void
    {
        $this->name = trim($this->name);
        $this->normalized++;
        $this->inTransaction = $this->sawTransactionOn?->connection()->inTransaction() ?? false;

        if ($this->refuse) {
            throw new \DomainException('refused');
        }
    }
}

/** Nested in StampsCode, so a trait's own trait is in the order too. */
trait Audits
{
    #[BeforeSave]
    private function audit(): void
    {
        $this->ran[] = 'Audits::audit';
    }
}

trait StampsCode
{
    use Audits;

    #[BeforeSave]
    private function stampCode(): void
    {
        $this->ran[] = 'StampsCode::stampCode';
        $this->code = strtoupper(str_replace(' ', '-', $this->name));
    }
}

trait Counts
{
    #[BeforeSave]
    private function count(): void
    {
        $this->ran[] = 'Counts::count';
    }
}

trait Shared
{
    #[BeforeSave]
    private function shared(): void
    {
        $this->ran[] = 'Shared::shared';
    }
}

/** An application's own base model: a parent's hooks, private ones too, run after the model's. */
abstract class Base extends Model
{
    use Shared;

    /** @var list<string> */
    public array $ran = [];

    #[BeforeSave]
    private function base(): void
    {
        $this->ran[] = 'Base::base';
    }

    #[BeforeSave]
    protected function overridden(): void
    {
        $this->ran[] = 'Base::overridden';
    }
}

#[Table('sites')]
final class Site extends Base
{
    use StampsCode;
    use Counts;

    #[Id] public private(set) string $id;
    #[Column] public string $name;
    #[Column] public string $code = '';

    #[BeforeSave]
    private function first(): void
    {
        $this->ran[] = 'Site::first';
        $this->name = trim($this->name);
    }

    public function notAHook(): void
    {
        $this->ran[] = 'Site::notAHook';
    }

    /** Overrides the parent's hook: one method, run once, from here. */
    #[BeforeSave]
    protected function overridden(): void
    {
        $this->ran[] = 'Site::overridden';
    }

    #[BeforeSave]
    private function last(): void
    {
        $this->ran[] = 'Site::last';
    }
}

trait Renamed
{
    #[BeforeSave]
    private function hook(): void
    {
        $this->ran[] = 'Renamed::hook';
    }
}

/** A trait's hook imported under an alias is called by the alias. */
#[Table('rooms')]
final class Room extends Model
{
    use Renamed { hook as private renamedHook; }

    #[Id] public private(set) string $id;

    /** @var list<string> */
    public array $ran = [];

    #[BeforeSave]
    private function hook(): void
    {
        $this->ran[] = 'Room::hook';
    }
}

#[Table('racks')]
final class StaticHook extends Model
{
    #[Id] public private(set) string $id;

    #[BeforeSave]
    private static function nope(): void {}
}

#[Table('racks')]
final class ArgumentHook extends Model
{
    #[Id] public private(set) string $id;

    #[BeforeSave]
    private function nope(string $why): void {}
}

/** A hook that saves its own model, and one that saves another. */
#[Table('racks')]
final class Recursive extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name = 'r';

    public ?Orm $orm = null;

    public ?Rack $other = null;

    #[BeforeSave]
    private function again(): void
    {
        if ($this->other !== null) {
            $this->orm?->save($this->other);

            return;
        }
        $this->orm?->save($this);
    }
}

function orm(): Orm
{
    $pdo = new \PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE racks (id TEXT PRIMARY KEY, name TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE sites (id TEXT PRIMARY KEY, name TEXT NOT NULL, code TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE rooms (id TEXT PRIMARY KEY)');

    return new Orm(new PdoConnection($pdo), new IdentityMap('test'));
}

function rack(string $name): Rack
{
    $rack = new Rack();
    $rack->name = $name;

    return $rack;
}

/** @return list<string> */
function names(Orm $orm): array
{
    return array_column($orm->connection()->select('SELECT name FROM racks ORDER BY name'), 'name');
}

it('runs a private hook before an insert, and writes what it changed', function () {
    $orm = orm();
    $orm->save(rack('  R-01  '));

    expect(names($orm))->toBe(['R-01']);
});

it('runs before the diff of an update, so a change the hook makes is written', function () {
    $orm = orm();
    $orm->connection()->execute("INSERT INTO racks VALUES ('0190d1d8-3d2a-7000-8000-000000000001', '  padded  ')");
    $rack = $orm->find(Rack::class, '0190d1d8-3d2a-7000-8000-000000000001') ?? throw new \LogicException('no rack');

    expect($rack->isDirty())->toBeFalse();

    $orm->save($rack);

    expect(names($orm))->toBe(['padded'])
        ->and($rack->isDirty())->toBeFalse()
        ->and($rack->normalized)->toBe(1);
});

it('runs on every save, the clean ones too', function () {
    $orm = orm();
    $rack = $orm->save(rack('R-01'));
    $orm->save($rack);
    $orm->save($rack);

    expect($rack->normalized)->toBe(3);
});

it('runs the class first, then its traits in use order, then its parent the same way', function () {
    $orm = orm();
    $site = new Site();
    $site->name = '  north hall ';
    $orm->save($site);

    expect($site->ran)->toBe([
        'Site::first',
        'Site::overridden',
        'Site::last',
        'StampsCode::stampCode',
        'Audits::audit',
        'Counts::count',
        'Base::base',
        'Shared::shared',
    ])
        // The trait's hook saw the name the class had trimmed.
        ->and($orm->connection()->select('SELECT name, code FROM sites'))->toBe([['name' => 'north hall', 'code' => 'NORTH-HALL']]);
});

it('calls a trait hook imported under an alias by the alias', function () {
    $orm = orm();
    $room = $orm->save(new Room());

    expect($room->ran)->toBe(['Room::hook', 'Renamed::hook']);
});

describe('a hook that throws', function () {
    it('writes nothing for an insert, and leaves the model new', function () {
        $orm = orm();
        $rack = rack(' R-01 ');
        $rack->refuse = true;

        expect(fn () => $orm->save($rack))->toThrow(\DomainException::class, 'refused');
        expect(names($orm))->toBe([])
            ->and($rack->isPersisted())->toBeFalse();

        $rack->refuse = false;
        $orm->save($rack);

        expect(names($orm))->toBe(['R-01']);
    });

    it('writes nothing for an update, and leaves the model dirty', function () {
        $orm = orm();
        $rack = $orm->save(rack('R-01'));
        $rack->name = 'R-02';
        $rack->refuse = true;

        expect(fn () => $orm->save($rack))->toThrow(\DomainException::class);
        expect(names($orm))->toBe(['R-01'])
            ->and($rack->changes())->toBe(['name' => 'R-02']);
    });

    it('runs inside the transaction the save is in, and rolls it back', function () {
        $orm = orm();
        $first = rack('R-01');
        $second = rack('R-02');
        $second->refuse = true;
        $second->sawTransactionOn = $orm;

        expect(fn () => $orm->transaction(function () use ($orm, $first, $second): void {
            $orm->save($first);
            $orm->save($second);
        }))->toThrow(\DomainException::class);

        expect($second->inTransaction)->toBeTrue()
            ->and(names($orm))->toBe([])
            ->and($first->isPersisted())->toBeFalse();
    });
});

// ADR 0022: a loader or query body may not write, and its hooks are not a
// way around that -- the save is refused before any of them runs.
it('runs no hook for a save refused in a read-only scope', function () {
    $orm = orm();
    $rack = rack(' R-01 ');

    expect(fn () => $orm->readOnly(fn () => $orm->save($rack)))->toThrow(ReadOnlyViolation::class);
    expect($rack->normalized)->toBe(0)
        ->and($rack->name)->toBe(' R-01 ');
});

// When the model compiles, not when it is first saved: the hooks are read
// once, into its metadata, and a save reflects on nothing.
it('refuses a hook it cannot call', function (string $class) {
    expect(fn () => Metadata::for($class))
        ->toThrow(MappingException::class, 'must be an instance method that takes no arguments');
})->with([
    'static' => [StaticHook::class],
    'with an argument' => [ArgumentHook::class],
]);

it('refuses a hook that saves its own model, and allows one that saves another', function () {
    $orm = orm();
    $model = new Recursive();
    $model->orm = $orm;

    expect(fn () => $orm->save($model))->toThrow(\LogicException::class, 'saved its own model');

    $model->other = rack('R-09');
    $orm->save($model);

    expect(names($orm))->toBe(['R-09', 'r']);
});
