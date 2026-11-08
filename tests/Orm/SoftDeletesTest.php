<?php

declare(strict_types=1);

/**
 * #[SoftDeletes] (ADR 0191): delete() stamps the row's deleted_at rather
 * than removing it, and every read -- find(), all(), the query builder,
 * a #[HasMany], whereHas() and the aggregates over related rows -- passes
 * a trashed row by unless it asks for it: withTrashed(), onlyTrashed(),
 * find(..., Trashed::With). restore() brings the row back, forceDelete()
 * removes it; the audit log hears each as its own event. On SQLite,
 * Postgres under its row-level security, and MySQL alike.
 */

namespace Tinvar\Tests\Orm\SoftDeletes;

use Carbon\CarbonImmutable;
use Tinvar\Orm\Attributes\AfterDelete;
use Tinvar\Orm\Attributes\Audited;
use Tinvar\Orm\Attributes\BeforeDelete;
use Tinvar\Orm\Attributes\BeforeSave;
use Tinvar\Orm\Attributes\BelongsTo;
use Tinvar\Orm\Attributes\BelongsToMany;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\HasMany;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\SoftDeletes;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\TenantScoped;
use Tinvar\Orm\Attributes\Unique;
use Tinvar\Orm\Collection;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\MappingException;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\MissingRow;
use Tinvar\Orm\Model;
use Tinvar\Orm\ModelChange;
use Tinvar\Orm\ModelChanges;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Query;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table as Blueprint;
use Tinvar\Orm\Trashed;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Orm\Fixtures\Tenancy;

require_once __DIR__ . '/Fixtures/tenancy.php';
require_once __DIR__ . '/Fixtures/databases.php';

#[Table('soft_bins'), TenantScoped, SoftDeletes]
final class Bin extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $label;
    #[Column] public ?CarbonImmutable $deletedAt = null;

    /** @var Collection<Part> */
    #[HasMany(Part::class, orderBy: 'name')]
    public Collection $parts;
}

#[Table('soft_parts'), TenantScoped, SoftDeletes, Audited]
final class Part extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;
    #[Column] public int $grams = 0;
    #[Column] public ?CarbonImmutable $deletedAt = null;
    #[BelongsTo] public Bin $bin;

    /** @var list<string> */
    public static array $heard = [];

    #[BeforeDelete]
    private function before(): void
    {
        self::$heard[] = "before {$this->name}" . ($this->isTrashed() ? ' (trashed)' : '');
    }

    #[AfterDelete]
    private function after(): void
    {
        self::$heard[] = "after {$this->name}" . ($this->isTrashed() ? ' (trashed)' : '') . ($this->isPersisted() ? '' : ' (gone)');
    }
}

final class Recorder implements ModelChanges
{
    /** @var list<ModelChange> */
    public array $changes = [];

    public function record(ModelChange $change): void
    {
        $this->changes[] = $change;
    }

    /** @return list<string> each change as kind:id */
    public function lines(): array
    {
        return array_map(static fn (ModelChange $c): string => "{$c->kind->value}:{$c->id}", $this->changes);
    }
}

#[Table('soft_unmapped'), SoftDeletes]
final class Unmapped extends Model
{
    #[Id] public private(set) string $id;
}

#[Table('soft_required'), SoftDeletes]
final class Required extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public CarbonImmutable $deletedAt;
}

#[Table('soft_named'), SoftDeletes(column: 'removed_at')]
final class Named extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public ?\DateTimeImmutable $removedAt = null;
}

#[Table('soft_kits'), TenantScoped]
final class Kit extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;

    /** @var Collection<Label> */
    #[BelongsToMany(Label::class, through: KitLabel::class, orderBy: 'name')]
    public Collection $labels;
}

#[Table('soft_labels'), TenantScoped, SoftDeletes]
final class Label extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;
    #[Column] public ?CarbonImmutable $deletedAt = null;
}

/** A pivot that soft deletes too: a trashed link links nothing. */
#[Table('soft_kit_labels'), TenantScoped, SoftDeletes, Unique('kit', 'label')]
final class KitLabel extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public int $weight = 0;
    #[Column] public ?CarbonImmutable $deletedAt = null;
    #[BelongsTo] public Kit $kit;
    #[BelongsTo] public Label $label;

    /** What another request does as a link is saved: a test's race. */
    public static ?\Closure $racer = null;

    #[BeforeSave]
    private function race(): void
    {
        if (self::$racer !== null) {
            (self::$racer)($this);
        }
    }
}

/** Makes the two tables through the schema builder, as a migration would. */
function migrate(Schema $schema, bool $policies): void
{
    $schema->create('soft_bins', static function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('label');
        $table->softDeletes();
        $table->tenant();
    });
    $schema->create('soft_parts', static function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('name');
        $table->integer('grams');
        $table->foreign(Bin::class);
        $table->softDeletes();
        $table->tenant();
    });
    $schema->create('soft_kits', static function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->tenant();
        $table->string('name');
    });
    $schema->create('soft_labels', static function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->tenant();
        $table->string('name');
        $table->softDeletes();
    });
    $schema->create('soft_kit_labels', static function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->tenant();
        $table->pivot(Kit::class, Label::class);
        $table->integer('weight')->default(0);
        $table->softDeletes();
    });
    if ($policies) {
        foreach (['soft_bins', 'soft_parts', 'soft_kits', 'soft_labels', 'soft_kit_labels'] as $table) {
            $schema->tenantPolicy($table);
        }
    }
}

/** @param iterable<Model> $models @return list<string> */
function labels(iterable $models): array
{
    $out = [];
    foreach ($models as $model) {
        $out[] = match (true) {
            $model instanceof Bin => $model->label,
            $model instanceof Part, $model instanceof Label => $model->name,
            default => '?',
        };
    }

    return $out;
}

/**
 * What every database must do alike: the soft delete, the reads that pass
 * it by or ask for it, restore and force delete, each audited and each
 * held to the tenant.
 */
function softDeletesAlike(Orm $orm, Recorder $recorder): void
{
    Part::$heard = [];
    $acme = static fn (\Closure $work): mixed => $orm->actAs('acme', $work);

    $acme(static function () use ($orm): void {
        $crate = new Bin();
        $crate->label = 'crate';
        $tray = new Bin();
        $tray->label = 'tray';
        $orm->save($crate);
        $orm->save($tray);
        foreach ([['bolt', 10], ['nut', 5], ['washer', 1]] as [$name, $grams]) {
            $part = new Part();
            $part->name = $name;
            $part->grams = $grams;
            $part->bin = $crate;
            $orm->save($part);
        }
    });
    $recorder->changes = [];

    // Read in the scope the rest runs in, so each holds its relations as
    // a model read does, and the identity map holds each.
    $tray = $acme(static fn (): ?Bin => $orm->query(Bin::class)->where('label', 'tray')->first());
    \assert($tray instanceof Bin);

    $tray = $acme(static function () use ($orm, $tray, $recorder): Bin {
        $crate = $orm->query(Bin::class)->where('label', 'crate')->first();
        [$bolt, $nut, $washer] = $orm->query(Part::class)->orderBy('name')->fetch();
        \assert($crate instanceof Bin);
        $tray = $orm->find(Bin::class, $tray->id);
        \assert($tray instanceof Bin);
        expect(labels($crate->parts))->toBe(['bolt', 'nut', 'washer']);

        // A soft delete: the row stays, stamped; the model is trashed.
        $orm->delete($nut);
        expect($nut->isTrashed())->toBeTrue()
            ->and($nut->isPersisted())->toBeTrue()
            ->and($nut->deletedAt)->toBeInstanceOf(CarbonImmutable::class)
            ->and(Part::$heard)->toBe(['before nut', 'after nut (trashed)'])
            ->and($recorder->lines())->toBe(['trashed:' . $nut->id])
            // The fields it held, as a delete records them.
            ->and($recorder->changes[0]->changes)->toHaveKey('name')
            ->and($recorder->changes[0]->changes)->not->toHaveKey('deletedAt')
            ->and((int) $orm->connection()->select('SELECT COUNT(*) AS n FROM soft_parts')[0]['n'])->toBe(3);

        // Passed by: find() -- though the identity map holds it -- a
        // query, the loaded collection it was in, whereHas() and an
        // aggregate over the relation.
        expect($orm->find(Part::class, $nut->id))->toBeNull()
            ->and($orm->find(Part::class, $nut->id, Trashed::With))->toBe($nut)
            ->and($orm->find(Part::class, $bolt->id, Trashed::Only))->toBeNull()
            ->and(labels($orm->all(Part::class, 'name')))->toBe(['bolt', 'washer'])
            ->and(labels($crate->parts))->toBe(['bolt', 'washer'])
            ->and(labels($orm->query(Part::class)->orderBy('name')->fetch()))->toBe(['bolt', 'washer'])
            ->and($orm->query(Part::class)->count())->toBe(2)
            ->and($orm->query(Part::class)->sum('grams'))->toBe(11)
            ->and(labels($orm->query(Part::class)->withTrashed()->orderBy('name')->fetch()))->toBe(['bolt', 'nut', 'washer'])
            ->and(labels($orm->query(Part::class)->onlyTrashed()->fetch()))->toBe(['nut'])
            ->and($orm->query(Part::class)->onlyTrashed()->count())->toBe(1)
            ->and($orm->query(Part::class)->where('name', 'nut')->exists())->toBeFalse()
            ->and(labels($orm->query(Bin::class)->whereHas('parts', static fn (Query $q): Query => $q->where('name', 'nut'))->fetch()))->toBe([])
            ->and(labels($orm->query(Bin::class)->where('parts.name', 'bolt')->fetch()))->toBe(['crate'])
            ->and(array_map(
                static fn ($row): array => $row->values,
                $orm->query(Bin::class)->where('label', 'crate')->withCount('parts')->withSum('parts', 'grams')->fetchAggregated(),
            ))->toEqual([['partsCount' => 2, 'partsSumGrams' => 11]]);

        // A fresh parent's collection loads without it too.
        $orm->identityMap()->forget(Bin::class, $crate->id);
        $again = $orm->find(Bin::class, $crate->id);
        expect($again)->not->toBe($crate)
            ->and(labels($again?->parts ?? []))->toBe(['bolt', 'washer']);
        $crate = $again;
        \assert($crate instanceof Bin);

        // A trashed parent is still its children's: a #[BelongsTo] names
        // the row its key does, trashed or not.
        $orm->delete($tray);
        $orm->delete($crate);
        $orm->identityMap()->forget(Part::class, $bolt->id);
        $reread = $orm->find(Part::class, $bolt->id);
        expect($reread?->bin->label)->toBe('crate')
            ->and($reread?->bin->isTrashed())->toBeTrue()
            ->and($orm->find(Bin::class, $crate->id))->toBeNull()
            ->and($orm->query(Part::class)->where('bin.label', 'crate')->count())->toBe(2)
            ->and($orm->query(Part::class)->whereHas('bin')->count())->toBe(2);

        // Restored: back in every read, and audited.
        $recorder->changes = [];
        $orm->restore($crate);
        $orm->restore($nut);
        expect($nut->isTrashed())->toBeFalse()
            ->and($nut->deletedAt)->toBeNull()
            ->and($orm->find(Part::class, $nut->id))->toBe($nut)
            ->and($orm->find(Bin::class, $crate->id))->toBe($crate)
            ->and(labels($crate->parts))->toBe(['bolt', 'nut', 'washer'])
            ->and($recorder->lines())->toBe(['restored:' . $nut->id]);

        // Restoring a row that is not trashed changes nothing.
        $orm->restore($nut);
        expect($recorder->lines())->toBe(['restored:' . $nut->id]);

        // Force deleted: gone, its hooks run, and audited as a delete.
        Part::$heard = [];
        $recorder->changes = [];
        $orm->forceDelete($washer);
        expect($washer->isPersisted())->toBeFalse()
            ->and(Part::$heard)->toBe(['before washer', 'after washer (gone)'])
            ->and($recorder->lines())->toBe(['deleted:' . $washer->id])
            ->and($orm->query(Part::class)->withTrashed()->where('name', 'washer')->exists())->toBeFalse()
            ->and(labels($crate->parts))->toBe(['bolt', 'nut']);

        // A trashed row is force deleted as well.
        $orm->delete($bolt);
        $orm->forceDelete($bolt);
        expect($orm->query(Part::class)->withTrashed()->count())->toBe(1);

        // A delete of a row trashed already is refused where it has hooks,
        // as one of a row gone is: the hooks' writes taken back with it.
        $orm->delete($nut);
        expect(fn () => $orm->delete($nut))->toThrow(MissingRow::class)
            ->and($nut->isTrashed())->toBeTrue();

        return $tray;
    });

    // Another tenant sees none of it, trashed or not, and cannot restore it.
    $orm->actAs('globex', static function () use ($orm, $tray): void {
        expect($orm->query(Bin::class)->onlyTrashed()->count())->toBe(0)
            ->and($orm->find(Bin::class, $tray->id, Trashed::With))->toBeNull();
    });
    expect(fn () => $orm->actAs('globex', static fn () => $orm->restore($tray)))->toThrow(MissingRow::class);
    expect($acme(static fn (): bool => $orm->query(Bin::class)->onlyTrashed()->where('label', 'tray')->exists()))->toBeTrue();
}

/** The query builder's writes: delete() trashes, restore() and forceDelete() as the model's do. */
function statementsAlike(Orm $orm, Recorder $recorder): void
{
    $orm->actAs('acme', static function () use ($orm, $recorder): void {
        $bin = new Bin();
        $bin->label = 'drawer';
        $orm->save($bin);
        foreach (['cog', 'pin', 'gear'] as $name) {
            $part = new Part();
            $part->name = $name;
            $part->bin = $bin;
            $orm->save($part);
        }
        // Read back, so it holds its relations as a model read does.
        $orm->identityMap()->flush();
        $bin = $orm->query(Bin::class)->where('label', 'drawer')->first();
        \assert($bin instanceof Bin);
        $pin = $orm->query(Part::class)->where('name', 'pin')->first();
        \assert($pin instanceof Part);
        expect(labels($bin->parts))->toBe(['cog', 'gear', 'pin']);
        $recorder->changes = [];

        // Statements refuse a model with delete hooks, as they do today.
        expect(fn () => $orm->query(Part::class)->where('name', 'pin')->delete())->toThrow(\LogicException::class);

        expect($orm->query(Bin::class)->where('label', 'drawer')->delete())->toBe(1)
            ->and($bin->isTrashed())->toBeTrue()
            ->and($orm->find(Bin::class, $bin->id))->toBeNull()
            ->and($orm->query(Bin::class)->onlyTrashed()->restore())->toBe(1)
            ->and($bin->isTrashed())->toBeFalse()
            ->and($orm->query(Bin::class)->restore())->toBe(0);

        // A field the ORM keeps is no field to set.
        expect(fn () => $orm->query(Part::class)->update(['deletedAt' => CarbonImmutable::now()]))->toThrow(\LogicException::class);
        $pin->deletedAt = CarbonImmutable::now();
        expect(fn () => $orm->save($pin))->toThrow(\LogicException::class);
        $pin->deletedAt = null;

        $other = new Bin();
        $other->label = 'shelf';
        $orm->save($other);
        $orm->delete($other);
        expect($orm->query(Bin::class)->onlyTrashed()->forceDelete())->toBe(1)
            ->and($other->isPersisted())->toBeFalse()
            ->and($orm->query(Bin::class)->withTrashed()->pluck('label'))->toBe(['drawer']);

        // A child the query restores goes back into its parent's #[HasMany],
        // loaded and as read afresh -- the statement knows only the column
        // it wrote, so the parent's rows are read again.
        $bin = $orm->query(Bin::class)->where('label', 'drawer')->first();
        \assert($bin instanceof Bin);
        $cog = $orm->query(Part::class)->where('name', 'cog')->first();
        \assert($cog instanceof Part);
        $orm->delete($cog);
        expect(labels($bin->parts))->toBe(['gear', 'pin'])
            ->and($orm->query(Part::class)->onlyTrashed()->restore())->toBe(1)
            ->and(labels($bin->parts))->toBe(['cog', 'gear', 'pin']);
        $orm->identityMap()->forget(Bin::class, $bin->id);
        expect(labels($orm->find(Bin::class, $bin->id)?->parts ?? []))->toBe(['cog', 'gear', 'pin']);
    });
}

/** A soft delete inside a transaction that rolls back leaves the model as the row is. */
function rollsBackAlike(Orm $orm): void
{
    $orm->actAs('acme', static function () use ($orm): void {
        $bin = new Bin();
        $bin->label = 'box';
        $orm->save($bin);

        try {
            $orm->transaction(static function () use ($orm, $bin): void {
                $orm->delete($bin);
                throw new \DomainException('undo');
            });
        } catch (\DomainException) {
        }

        expect($bin->isTrashed())->toBeFalse()
            ->and($bin->deletedAt)->toBeNull()
            // The rollback let the identity map go, so the row is read afresh.
            ->and($orm->find(Bin::class, $bin->id)?->deletedAt)->toBeNull();
    });
}

/**
 * A #[BelongsToMany] passes by a trashed target and a trashed pivot row
 * alike -- loaded, filtered or counted -- and attach() restores a trashed
 * link rather than making one beside it (ADR 0191 on ADR 0192).
 */
function linksAlike(Orm $orm): void
{
    $orm->actAs('acme', static function () use ($orm): void {
        $kit = new Kit();
        $kit->name = 'starter';
        $orm->save($kit);
        $made = [];
        foreach (['blue', 'green', 'red'] as $name) {
            $label = new Label();
            $label->name = $name;
            $made[$name] = $orm->save($label);
        }
        $orm->attach($kit, 'labels', array_values($made));
        $orm->identityMap()->flush();
        $kit = $orm->find(Kit::class, $kit->id);
        \assert($kit instanceof Kit);
        expect(labels($kit->labels))->toBe(['blue', 'green', 'red']);

        // A trashed target drops out.
        $green = $orm->find(Label::class, $made['green']->id);
        \assert($green instanceof Label);
        $orm->delete($green);
        expect(labels($kit->labels))->toBe(['blue', 'red'])
            ->and($orm->query(Kit::class)->whereHas('labels', static fn (Query $q): Query => $q->where('name', 'green'))->exists())->toBeFalse()
            ->and(array_map(static fn ($row): array => $row->values, $orm->query(Kit::class)->withCount('labels')->fetchAggregated()))
            ->toEqual([['labelsCount' => 2]]);

        // A trashed pivot row drops out too, though its target is live.
        $orm->detach($kit, 'labels', [$made['red']->id]);
        expect($orm->query(KitLabel::class)->onlyTrashed()->count())->toBe(1)
            ->and(labels($kit->labels))->toBe(['blue'])
            ->and($orm->query(Kit::class)->where('labels.name', 'red')->exists())->toBeFalse()
            ->and(array_map(static fn ($row): array => $row->values, $orm->query(Kit::class)->withCount('labels')->fetchAggregated()))
            ->toEqual([['labelsCount' => 1]]);

        // Attached again, the trashed link is restored, not made twice.
        $change = $orm->attach($kit, 'labels', [$made['red']->id], ['weight' => 3]);
        expect($change->attached)->toBe([$made['red']->id])
            ->and($orm->query(KitLabel::class)->withTrashed()->count())->toBe(3)
            ->and($orm->query(KitLabel::class)->where('weight', 3)->count())->toBe(1)
            ->and(labels($kit->labels))->toBe(['blue', 'red']);

        // Restored, the target is back.
        $orm->restore($green);
        expect(labels($kit->labels))->toBe(['blue', 'green', 'red']);

        // A trashed target may not be linked: it is no row the tenant reads.
        $orm->detach($kit, 'labels', [$green->id]);
        $orm->delete($green);
        expect(fn () => $orm->attach($kit, 'labels', [$green->id]))->toThrow(MissingRow::class);
    });

    // Linked and unlinked in one request: the link made is the one detach()
    // and sync() find, by the target its row names.
    $orm->actAs('acme', static function () use ($orm): void {
        $kit = new Kit();
        $kit->name = 'travel';
        $orm->save($kit);
        $yellow = new Label();
        $yellow->name = 'yellow';
        $orm->save($yellow);

        $orm->attach($kit, 'labels', [$yellow]);
        $kept = $orm->sync($kit, 'labels', [$yellow]);
        expect($kept->changed())->toBeFalse()
            ->and($orm->query(KitLabel::class)->onlyTrashed()->where('kit', $kit->id)->count())->toBe(0)
            ->and($orm->detach($kit, 'labels', [$yellow])->detached)->toBe([$yellow->id])
            ->and($orm->query(KitLabel::class)->where('kit', $kit->id)->count())->toBe(0);

        // Another request restores the trashed link between this one's read
        // of it and its own restore: raced, so not attached, and the rest of
        // the attach() kept rather than taken back with it. (The race is
        // played on this connection, so the savepoint takes it back too.)
        $other = new Label();
        $other->name = 'other';
        $orm->save($other);
        KitLabel::$racer = static function (KitLabel $link) use ($orm): void {
            if ($link->isPersisted()) {
                $orm->connection()->execute('UPDATE soft_kit_labels SET deleted_at = NULL WHERE id = ?', [$link->id]);
            }
        };
        try {
            $change = $orm->attach($kit, 'labels', [$yellow, $other], ['weight' => 2]);
        } finally {
            KitLabel::$racer = null;
        }
        expect($change->attached)->toBe([$other->id])
            ->and($orm->query(KitLabel::class)->withTrashed()->where('kit', $kit->id)->count())->toBe(2);
    });
}

function sqliteOrm(Recorder $recorder): Orm
{
    $connection = new PdoConnection(new \PDO('sqlite::memory:'), 'sqlite');
    migrate(new Schema($connection, Dialect::for($connection)), false);
    $orm = new Orm($connection, new IdentityMap('test'), Tenancy::flat());
    $orm->recordChangesWith($recorder);

    return $orm;
}

/** @param \Closure(Orm, Recorder): void $test */
function onPostgres(\Closure $test): void
{
    Databases::inScratchSchema('pgsql', static function (\PDO $pdo, string $schema) use ($test): void {
        $connection = new PdoConnection($pdo, 'pgsql');
        migrate(new Schema($connection, Dialect::for($connection)), true);
        $role = 'tinvar_rls_' . bin2hex(random_bytes(4));
        $pdo->exec("CREATE ROLE {$role} NOLOGIN");
        $pdo->exec("GRANT USAGE ON SCHEMA {$schema} TO {$role}");
        $pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA {$schema} TO {$role}");
        $pdo->exec("SET ROLE {$role}");
        try {
            $recorder = new Recorder();
            $orm = new Orm($connection, new IdentityMap('test'), Tenancy::flat());
            $orm->recordChangesWith($recorder);
            $test($orm, $recorder);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->exec('RESET ROLE');
            $pdo->exec("SELECT set_config('app.tenant', '', false), set_config('app.across_tenants', 'off', false), set_config('app.subtree', '', false)");
            $pdo->exec("DROP OWNED BY {$role}");
            $pdo->exec("DROP ROLE {$role}");
        }
    });
}

/** @param \Closure(Orm, Recorder): void $test */
function onMySql(\Closure $test): void
{
    Databases::inScratchSchema('mysql', static function (\PDO $pdo) use ($test): void {
        $pdo->exec("SET SESSION sql_mode = CONCAT(@@sql_mode, ',ANSI_QUOTES')");
        $connection = new PdoConnection($pdo, 'mysql');
        migrate(new Schema($connection, Dialect::for($connection)), false);
        $recorder = new Recorder();
        $orm = new Orm($connection, new IdentityMap('test'), Tenancy::flat());
        $orm->recordChangesWith($recorder);
        $test($orm, $recorder);
    });
}

describe('on SQLite', function () {
    it('passes a trashed target and a trashed pivot row by in a #[BelongsToMany]', function () {
        linksAlike(sqliteOrm(new Recorder()));
    });

    it('trashes, passes by, restores and force deletes', function () {
        $recorder = new Recorder();
        softDeletesAlike(sqliteOrm($recorder), $recorder);
    });

    it('trashes, restores and force deletes through a statement', function () {
        $recorder = new Recorder();
        statementsAlike(sqliteOrm($recorder), $recorder);
    });

    it('puts a soft delete back when its transaction rolls back', function () {
        rollsBackAlike(sqliteOrm(new Recorder()));
    });

    it('loads a #[HasMany] eagerly without its trashed children, and reads a union under one rule', function () {
        $recorder = new Recorder();
        $orm = sqliteOrm($recorder);
        $orm->actAs('acme', static function () use ($orm): void {
            $bin = new Bin();
            $bin->label = 'case';
            $orm->save($bin);
            foreach (['a', 'b'] as $name) {
                $part = new Part();
                $part->name = $name;
                $part->bin = $bin;
                $orm->save($part);
            }
            $orm->delete($orm->query(Part::class)->where('name', 'a')->first() ?? throw new \LogicException());
            $orm->identityMap()->flush();

            [$read] = $orm->query(Bin::class)->with('parts')->fetch();
            expect($read->parts->isLoaded())->toBeTrue()
                ->and(labels($read->parts))->toBe(['b'])
                ->and(labels($orm->query(Part::class)->withTrashed()->where('name', 'a')
                    ->union($orm->query(Part::class)->withTrashed()->where('name', 'b'))->orderBy('name')->fetch()))->toBe(['a', 'b'])
                ->and(labels($orm->query(Part::class)->where('name', 'a')
                    ->union($orm->query(Part::class)->where('name', 'b'))->orderBy('name')->fetch()))->toBe(['b'])
                ->and(fn () => $orm->query(Part::class)->union($orm->query(Part::class)->withTrashed()))->toThrow(\LogicException::class, 'withTrashed')
                ->and(fn () => $orm->query(Bin::class)->whereHas('parts', static fn (Query $q): Query => $q->withTrashed()))->toThrow(\LogicException::class, 'withTrashed');
        });
    });

    it('refuses a trashed moment set by hand, and restore() of a model that does not soft delete', function () {
        $orm = sqliteOrm(new Recorder());
        $orm->actAs('acme', static function () use ($orm): void {
            $bin = new Bin();
            $bin->label = 'early';
            $bin->deletedAt = CarbonImmutable::now();

            expect(fn () => $orm->save($bin))->toThrow(\LogicException::class, 'delete() and restore()')
                ->and(fn () => $orm->query(Bin::class)->insert([['label' => 'x', 'deletedAt' => CarbonImmutable::now()]]))->toThrow(\LogicException::class, 'SoftDeletes')
                ->and($orm->query(Bin::class)->insert([['label' => 'y', 'deletedAt' => null]]))->toBe(1)
                ->and(fn () => $orm->query(Plain::class)->restore())->toThrow(\LogicException::class, 'SoftDeletes');
        });
    });
});

describe('on Postgres, with row-level security', function () {
    it('passes a trashed target and a trashed pivot row by in a #[BelongsToMany]', function () {
        onPostgres(static fn (Orm $orm) => linksAlike($orm));
    });

    it('trashes, passes by, restores and force deletes', function () {
        onPostgres(softDeletesAlike(...));
    });

    it('trashes, restores and force deletes through a statement', function () {
        onPostgres(statementsAlike(...));
    });

    it('puts a soft delete back when its transaction rolls back', function () {
        onPostgres(static fn (Orm $orm) => rollsBackAlike($orm));
    });
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

describe('on MySQL', function () {
    it('passes a trashed target and a trashed pivot row by in a #[BelongsToMany]', function () {
        onMySql(static fn (Orm $orm) => linksAlike($orm));
    });

    it('trashes, passes by, restores and force deletes', function () {
        onMySql(softDeletesAlike(...));
    });

    it('trashes, restores and force deletes through a statement', function () {
        onMySql(statementsAlike(...));
    });

    it('puts a soft delete back when its transaction rolls back', function () {
        onMySql(static fn (Orm $orm) => rollsBackAlike($orm));
    });
})->group('mysql')->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');

describe('declaring it', function () {
    it('reads the column the attribute names, and the property mapping it', function () {
        $meta = Metadata::for(Named::class);

        expect($meta->deletedAtColumn)->toBe('removed_at')
            ->and($meta->deletedAtProperty)->toBe('removedAt')
            ->and(Metadata::for(Bin::class)->deletedAtColumn)->toBe('deleted_at')
            ->and(Metadata::for(Bin::class)->deletedAtProperty)->toBe('deletedAt');
    });

    it('refuses a model that maps no property to the column', function () {
        expect(fn () => Metadata::for(Unmapped::class))->toThrow(MappingException::class, 'deleted_at');
    });

    it('refuses a column property that cannot hold no moment', function () {
        expect(fn () => Metadata::for(Required::class))->toThrow(MappingException::class, 'nullable');
    });

    it('refuses restore() on a model that does not soft delete', function () {
        $orm = new Orm(new PdoConnection(new \PDO('sqlite::memory:'), 'sqlite'));
        $model = new Plain();

        expect(fn () => $orm->restore($model))->toThrow(\LogicException::class, 'SoftDeletes');
    });

    it('makes the column a nullable timestamp with softDeletes()', function () {
        $table = new Blueprint('things', creating: true);
        $column = $table->softDeletes();

        expect($column->name)->toBe('deleted_at')
            ->and($column->type)->toBe('timestamp')
            ->and($column->nullable)->toBeTrue();
    });
});

#[Table('soft_plain')]
final class Plain extends Model
{
    #[Id] public private(set) string $id;
}
