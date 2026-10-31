<?php

declare(strict_types=1);

/**
 * #[AfterSave], #[BeforeDelete] and #[AfterDelete] (ADR 0122): what runs
 * around a write besides #[BeforeSave], each in one transaction with the
 * write, so a hook that throws takes the row back with what the hooks did;
 * and what a write through a query does with them.
 */

namespace Tinvar\Tests\Orm\LifecycleHooks;

use Tinvar\Orm\Attributes\AfterDelete;
use Tinvar\Orm\Attributes\AfterSave;
use Tinvar\Orm\Attributes\BeforeDelete;
use Tinvar\Orm\Attributes\BeforeSave;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Change;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\MappingException;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\ReadOnlyViolation;

require_once __DIR__ . '/Fixtures/hierarchy.php';

/** What every hook saw, in order, across the models of a test. */
final class Journal
{
    /** @var list<string> */
    public static array $lines = [];

    public static ?Orm $orm = null;

    /** Which hook throws, if any. */
    public static ?string $refuse = null;
}

#[Table('racks')]
final class Rack extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;
    #[Column] public int $units = 0;

    #[BeforeSave]
    private function before(): void
    {
        Journal::$lines[] = "before save {$this->name}" . ($this->isPersisted() ? ' (update)' : ' (insert)');
    }

    #[AfterSave]
    private function audit(): void
    {
        // The row is in, as the database holds it, and the model is clean.
        $stored = Journal::$orm?->connection()->select('SELECT name FROM racks WHERE id = ?', [$this->id])[0]['name'] ?? null;
        Journal::$lines[] = "after save {$this->name} stored={$stored} dirty=" . ($this->isDirty() ? 'yes' : 'no');
        Journal::$orm?->connection()->execute('INSERT INTO audit (line) VALUES (?)', ["saved {$this->name}"]);
        if (Journal::$refuse === 'after save') {
            throw new \DomainException('refused after save');
        }
    }

    #[BeforeDelete]
    private function guard(): void
    {
        Journal::$lines[] = "before delete {$this->name}";
        Journal::$orm?->connection()->execute('INSERT INTO audit (line) VALUES (?)', ["deleting {$this->name}"]);
        if (Journal::$refuse === 'before delete') {
            throw new \DomainException('refused before delete');
        }
    }

    #[AfterDelete]
    private function gone(): void
    {
        $left = Journal::$orm?->connection()->select('SELECT COUNT(*) AS n FROM racks WHERE id = ?', [$this->id])[0]['n'] ?? null;
        Journal::$lines[] = "after delete {$this->name} left={$left} persisted=" . ($this->isPersisted() ? 'yes' : 'no');
        if (Journal::$refuse === 'after delete') {
            throw new \DomainException('refused after delete');
        }
    }
}

/** Hooks of its own, in a parent and a trait: ordered as #[BeforeSave]'s are. */
trait Announces
{
    #[AfterSave]
    private function announce(): void
    {
        Journal::$lines[] = 'Announces::announce';
    }
}

abstract class Base extends Model
{
    #[AfterSave, AfterDelete]
    protected function base(): void
    {
        Journal::$lines[] = 'Base::base';
    }
}

#[Table('racks')]
final class Ordered extends Base
{
    use Announces;

    #[Id] public private(set) string $id;
    #[Column] public string $name = 'o';

    #[AfterSave]
    private function own(): void
    {
        Journal::$lines[] = 'Ordered::own';
    }
}

/** An #[AfterSave] that saves its own model, or another. */
#[Table('racks')]
final class Recursive extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name = 'r';

    public ?Rack $other = null;

    #[AfterSave]
    private function again(): void
    {
        if ($this->other !== null) {
            Journal::$orm?->save($this->other);

            return;
        }
        // A change, so the save writes, and would run this hook again.
        $this->name .= '!';
        Journal::$orm?->save($this);
    }
}

/** A #[BeforeDelete] that deletes its own model. */
#[Table('racks')]
final class SelfDeleting extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name = 's';

    #[BeforeDelete]
    private function again(): void
    {
        Journal::$orm?->delete($this);
    }
}

#[Table('racks')]
final class StaticHook extends Model
{
    #[Id] public private(set) string $id;

    #[AfterDelete]
    public static function nope(): void {}
}

function orm(): Orm
{
    $pdo = new \PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE racks (id TEXT PRIMARY KEY, name TEXT NOT NULL, units INTEGER NOT NULL DEFAULT 0)');
    $pdo->exec('CREATE TABLE audit (line TEXT NOT NULL)');
    Journal::$lines = [];
    Journal::$refuse = null;

    return Journal::$orm = new Orm(new PdoConnection($pdo), new IdentityMap('test'));
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

/** @return list<string> */
function audit(Orm $orm): array
{
    return array_column($orm->connection()->select('SELECT line FROM audit ORDER BY rowid'), 'line');
}

describe('#[AfterSave]', function () {
    it('runs once the row is written, on an insert and on an update that wrote', function () {
        $orm = orm();
        $rack = $orm->save(rack('R-01'));
        $rack->name = 'R-02';
        $orm->save($rack);

        expect(Journal::$lines)->toBe([
            'before save R-01 (insert)',
            'after save R-01 stored=R-01 dirty=no',
            'before save R-02 (update)',
            'after save R-02 stored=R-02 dirty=no',
        ])->and(audit($orm))->toBe(['saved R-01', 'saved R-02']);
    });

    it('runs none for a save that wrote nothing', function () {
        $orm = orm();
        $rack = $orm->save(rack('R-01'));
        Journal::$lines = [];
        $orm->save($rack);

        expect(Journal::$lines)->toBe(['before save R-01 (update)']);
    });

    it('takes the row back, and what the hooks wrote, when one throws', function () {
        $orm = orm();
        Journal::$refuse = 'after save';
        $rack = rack('R-01');

        expect(fn () => $orm->save($rack))->toThrow(\DomainException::class, 'refused after save');
        expect(names($orm))->toBe([])
            ->and(audit($orm))->toBe([])
            ->and($rack->isPersisted())->toBeFalse();

        // Saved again once nothing refuses, as a new model.
        Journal::$refuse = null;
        $orm->save($rack);
        expect(names($orm))->toBe(['R-01']);
    });

    it('leaves an update dirty when one throws, the row as it was', function () {
        $orm = orm();
        $rack = $orm->save(rack('R-01'));
        $rack->name = 'R-02';
        Journal::$refuse = 'after save';

        expect(fn () => $orm->save($rack))->toThrow(\DomainException::class);
        expect(names($orm))->toBe(['R-01'])
            ->and($rack->changes())->toBe(['name' => 'R-02'])
            ->and(audit($orm))->toBe(['saved R-01']);
    });

    it('rolls back alone, in a savepoint, inside a transaction that goes on', function () {
        $orm = orm();
        $orm->transaction(function () use ($orm): void {
            $orm->save(rack('R-01'));
            Journal::$refuse = 'after save';
            try {
                $orm->save(rack('R-02'));
            } catch (\DomainException) {
            }
            Journal::$refuse = null;
        });

        expect(names($orm))->toBe(['R-01'])
            ->and(audit($orm))->toBe(['saved R-01']);
    });

    it('runs the class first, then its traits, then its parent', function () {
        $orm = orm();
        $orm->save(new Ordered());

        expect(Journal::$lines)->toBe(['Ordered::own', 'Announces::announce', 'Base::base']);
    });

    it('refuses a hook that saves its own model, and allows one that saves another', function () {
        $orm = orm();
        $model = new Recursive();

        expect(fn () => $orm->save($model))->toThrow(\LogicException::class, '#[AfterSave] hook of ' . Recursive::class . ' saved its own model');
        expect(names($orm))->toBe([]);

        $other = new Recursive();
        $other->other = rack('R-09');
        $orm->save($other);
        expect(names($orm))->toBe(['R-09', 'r']);
    });

    it('runs none for a save refused in a read-only scope', function () {
        $orm = orm();

        expect(fn () => $orm->readOnly(fn () => $orm->save(rack('R-01'))))->toThrow(ReadOnlyViolation::class);
        expect(Journal::$lines)->toBe([]);
    });
});

/** The scripts table, its deletes heard by hooks, under a tenant tree. */
#[Table('scripts'), \Tinvar\Orm\Attributes\TenantScoped]
final class HookedScript extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;

    #[BeforeDelete]
    private function before(): void
    {
        Journal::$lines[] = "before delete {$this->name}";
    }

    #[AfterDelete]
    private function after(): void
    {
        Journal::$lines[] = "after delete {$this->name}";
    }
}

// A DELETE that removed nothing ran #[AfterDelete] as though it had: the
// row gone already, or out of the tenant's reach (review of ADR 0122).
describe('a delete that reaches no row', function () {
    it('throws MissingRow and runs no #[AfterDelete] for a row deleted since it was read, taking the #[BeforeDelete] writes back', function () {
        $orm = orm();
        $rack = $orm->save(rack('R-01'));
        $other = $orm->find(Rack::class, $rack->id);
        \assert($other instanceof Rack);
        // Another worker deletes it first.
        $orm->connection()->execute('DELETE FROM racks WHERE id = ?', [$rack->id]);
        Journal::$lines = [];

        expect(fn () => $orm->delete($other))->toThrow(\Tinvar\Orm\MissingRow::class, 'matched no row to delete');
        expect(Journal::$lines)->toBe(['before delete R-01'])
            ->and(audit($orm))->toBe(['saved R-01'])
            ->and($other->isPersisted())->toBeTrue();
    });

    it('refuses a second delete of the same model, and one never saved, before any hook runs', function () {
        $orm = orm();
        $rack = $orm->save(rack('R-01'));
        $orm->delete($rack);
        Journal::$lines = [];

        expect(fn () => $orm->delete($rack))->toThrow(\Tinvar\Orm\MissingRow::class, 'has no row')
            ->and(fn () => $orm->delete(rack('R-02')))->toThrow(\Tinvar\Orm\MissingRow::class, 'has no row')
            ->and(Journal::$lines)->toBe([])
            ->and(audit($orm))->toBe(['saved R-01', 'deleting R-01']);
    });

    it('throws MissingRow for a child\'s model read inside withinSubtree() and deleted after it ends', function () {
        $fixture = new \Tinvar\Tests\Orm\Fixtures\Hierarchy\Tenants();
        $orm = $fixture->orm();
        Journal::$lines = [];

        $child = $orm->actAs('reseller', static fn (): ?Model => $orm->grantSubtree(
            static fn (): ?Model => $orm->withinSubtree(static fn (): ?Model => $orm->find(HookedScript::class, $fixture->scripts['acme'])),
        ));
        \assert($child instanceof HookedScript);

        expect(fn () => $orm->actAs('reseller', static fn () => $orm->delete($child)))->toThrow(\Tinvar\Orm\MissingRow::class)
            ->and(Journal::$lines)->toBe(['before delete acme-script'])
            ->and($fixture->connection->select('SELECT COUNT(*) AS n FROM scripts WHERE id = ?', [$fixture->scripts['acme']])[0]['n'])->toBe(1);
    });
});

describe('#[BeforeDelete] and #[AfterDelete]', function () {
    it('run around the DELETE, the model unsaved after it', function () {
        $orm = orm();
        $rack = $orm->save(rack('R-01'));
        Journal::$lines = [];
        $orm->delete($rack);

        expect(Journal::$lines)->toBe(['before delete R-01', 'after delete R-01 left=0 persisted=no'])
            ->and(names($orm))->toBe([])
            ->and(audit($orm))->toBe(['saved R-01', 'deleting R-01']);
    });

    it('stop the delete when the first throws, and take back what it wrote', function () {
        $orm = orm();
        $rack = $orm->save(rack('R-01'));
        Journal::$refuse = 'before delete';

        expect(fn () => $orm->delete($rack))->toThrow(\DomainException::class, 'refused before delete');
        expect(names($orm))->toBe(['R-01'])
            ->and(audit($orm))->toBe(['saved R-01'])
            ->and($rack->isPersisted())->toBeTrue();
    });

    it('put the row back when the second throws, the model persisted again', function () {
        $orm = orm();
        $rack = $orm->save(rack('R-01'));
        Journal::$refuse = 'after delete';

        expect(fn () => $orm->delete($rack))->toThrow(\DomainException::class, 'refused after delete');
        expect(names($orm))->toBe(['R-01'])
            ->and(audit($orm))->toBe(['saved R-01'])
            ->and($rack->isPersisted())->toBeTrue()
            ->and($orm->find(Rack::class, $rack->id)?->name)->toBe('R-01');
    });

    it('refuses a hook that deletes its own model', function () {
        $orm = orm();
        $model = $orm->save(new SelfDeleting());

        expect(fn () => $orm->delete($model))->toThrow(\LogicException::class, 'deleted its own model');
        expect(names($orm))->toBe(['s']);
    });

    it('runs none for a delete refused in a read-only scope', function () {
        $orm = orm();
        $rack = $orm->save(rack('R-01'));
        Journal::$lines = [];

        expect(fn () => $orm->readOnly(fn () => $orm->delete($rack)))->toThrow(ReadOnlyViolation::class);
        expect(Journal::$lines)->toBe([]);
    });

    it('refuses a hook it cannot call, naming the attribute', function () {
        expect(fn () => Metadata::for(StaticHook::class))
            ->toThrow(MappingException::class, '#[AfterDelete] ' . StaticHook::class . '::nope() must be an instance method');
    });
});

describe('a write through a query', function () {
    it('runs #[AfterSave] on a model of each row insert() wrote, in the order given', function () {
        $orm = orm();
        $count = $orm->query(Rack::class)->insert([['name' => 'R-02'], ['name' => 'R-01']]);

        expect($count)->toBe(2)
            ->and(Journal::$lines)->toBe([
                'before save R-02 (insert)',
                'before save R-01 (insert)',
                'after save R-02 stored=R-02 dirty=no',
                'after save R-01 stored=R-01 dirty=no',
            ])
            ->and(audit($orm))->toBe(['saved R-02', 'saved R-01']);
    });

    it('takes every row insert() wrote back when an #[AfterSave] throws', function () {
        $orm = orm();
        Journal::$refuse = 'after save';

        expect(fn () => $orm->query(Rack::class)->insert([['name' => 'R-01'], ['name' => 'R-02']]))->toThrow(\DomainException::class);
        expect(names($orm))->toBe([])
            ->and(audit($orm))->toBe([]);
    });

    it('refuses update(), increment() and upsert() on a model with #[AfterSave]', function () {
        $orm = orm();
        // #[BeforeSave] is refused first; a model with #[AfterSave] alone
        // is refused for its own.
        expect(fn () => $orm->query(Rack::class)->update(['name' => 'x']))->toThrow(\LogicException::class, '#[BeforeSave]');
        expect(fn () => $orm->query(Ordered::class)->update(['name' => 'x']))->toThrow(\LogicException::class, '#[AfterSave]')
            ->and(fn () => $orm->query(Ordered::class)->upsert([['name' => 'x']], 'name'))->toThrow(\LogicException::class, '#[AfterSave]');
        expect(fn () => $orm->query(Rack::class)->update(['units' => Change::add(1)]))->toThrow(\LogicException::class);
    });

    it('refuses delete() on a model with #[BeforeDelete] or #[AfterDelete], writing nothing', function () {
        $orm = orm();
        $orm->save(rack('R-01'));
        $orm->save(new Ordered());

        expect(fn () => $orm->query(Rack::class)->delete())->toThrow(\LogicException::class, '#[BeforeDelete] or #[AfterDelete]')
            ->and(fn () => $orm->query(Ordered::class)->delete())->toThrow(\LogicException::class, 'Delete each model with Orm::delete()');
        expect(names($orm))->toBe(['R-01', 'o']);
    });
});
