<?php

declare(strict_types=1);

/**
 * An updatedAt typed as a moment is the ORM's to stamp, as createdAt is
 * (tinvar-spec.md, "Models": "Asymmetric visibility keeps IDs and
 * timestamps read-only outside the ORM"): on insert, and on every update
 * through save() and a query's update(), unless that write sets it. An
 * upsert moves it on each row it updates, and leaves an existing row's
 * createdAt alone.
 */

namespace Tinvar\Tests\Orm\UpdatedAt;

use Tinvar\Orm\Attributes\BeforeSave;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\Unique;
use Tinvar\Orm\Change;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\MissingRow;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;

#[Table('bins')]
#[Unique('sku')]
final class Bin extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $sku = '';
    #[Column] public int $stock = 0;
    #[Column] public private(set) \DateTimeImmutable $createdAt;
    #[Column] public private(set) \DateTimeImmutable $updatedAt;

    /** An import keeping the time it carries. */
    public function touchedAt(\DateTimeImmutable $at): void
    {
        $this->updatedAt = $at;
    }
}

#[Table('bins')]
#[Unique('sku')]
final class OpenBin extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $sku = '';
    #[Column] public int $stock = 0;
    #[Column] public \DateTimeImmutable $createdAt;
    #[Column] public \DateTimeImmutable $updatedAt;
}

#[Table('bins')]
final class HookedBin extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $sku = '';
    #[Column] public int $stock = 0;
    #[Column] public private(set) \DateTimeImmutable $createdAt;
    #[Column] public private(set) \DateTimeImmutable $updatedAt;

    #[BeforeSave]
    private function tidy(): void
    {
        $this->sku = strtoupper($this->sku);
    }
}

#[Table('marks')]
final class Mark extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $updatedAt = 'never';
}

/** The moments the clock answers, one after another as the test moves it. */
final class Clock
{
    public static string $now = '2026-10-28 09:30:00';
}

function orm(): Orm
{
    Clock::$now = '2026-10-28 09:30:00';
    $pdo = new \PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE bins (id TEXT PRIMARY KEY, sku TEXT NOT NULL UNIQUE, stock INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE marks (id TEXT PRIMARY KEY, updated_at TEXT NOT NULL)');

    return new Orm(
        new PdoConnection($pdo),
        new IdentityMap('test'),
        clock: static fn (): \DateTimeImmutable => new \DateTimeImmutable(Clock::$now, new \DateTimeZone('UTC')),
    );
}

/** @return list<array<string, mixed>> */
function rows(Orm $orm): array
{
    return $orm->connection()->select('SELECT sku, stock, created_at, updated_at FROM bins ORDER BY sku');
}

const THEN = '2026-10-28 09:30:00.000000+00:00';
const LATER = '2026-10-28 10:00:00.000000+00:00';

it('stamps a new row\'s updatedAt with its createdAt, on save and on a query\'s insert', function () {
    $orm = orm();
    $bin = new Bin();
    $bin->sku = 'A';
    $orm->save($bin);
    $orm->query(Bin::class)->insert([['sku' => 'B']]);
    $orm->query(HookedBin::class)->insert([['sku' => 'c']]);

    expect($bin->updatedAt)->toEqual($bin->createdAt)
        ->and(rows($orm))->toBe([
            ['sku' => 'A', 'stock' => 0, 'created_at' => THEN, 'updated_at' => THEN],
            ['sku' => 'B', 'stock' => 0, 'created_at' => THEN, 'updated_at' => THEN],
            ['sku' => 'C', 'stock' => 0, 'created_at' => THEN, 'updated_at' => THEN],
        ]);
});

it('moves it on every update save() writes, and not on one with nothing to write', function () {
    $orm = orm();
    $bin = new Bin();
    $bin->sku = 'A';
    $orm->save($bin);

    Clock::$now = '2026-10-28 09:45:00';
    $orm->save($bin);
    expect(rows($orm)[0]['updated_at'])->toBe(THEN);

    Clock::$now = '2026-10-28 10:00:00';
    $bin->stock = 3;
    $orm->save($bin);

    expect(rows($orm)[0])->toBe(['sku' => 'A', 'stock' => 3, 'created_at' => THEN, 'updated_at' => LATER])
        ->and($bin->updatedAt->format('H:i'))->toBe('10:00')
        ->and($bin->isDirty())->toBeFalse();
});

it('keeps one the save set itself', function () {
    $orm = orm();
    $bin = new Bin();
    $bin->sku = 'A';
    $orm->save($bin);

    Clock::$now = '2026-10-28 10:00:00';
    $bin->stock = 1;
    $bin->touchedAt(new \DateTimeImmutable('2025-01-01 00:00:00', new \DateTimeZone('UTC')));
    $orm->save($bin);

    expect(rows($orm)[0]['updated_at'])->toBe('2025-01-01 00:00:00.000000+00:00');
});

it('puts back what it held when the update is refused or rolled back, and stamps again on the next try', function () {
    $orm = orm();
    $bin = new Bin();
    $bin->sku = 'A';
    $orm->save($bin);
    $orm->connection()->execute('DELETE FROM bins', []);

    Clock::$now = '2026-10-28 10:00:00';
    $bin->stock = 1;
    expect(fn () => $orm->save($bin))->toThrow(MissingRow::class)
        ->and($bin->updatedAt->format('H:i'))->toBe('09:30');

    $other = new Bin();
    $other->sku = 'B';
    $orm->save($other);
    $other->stock = 2;
    Clock::$now = '2026-10-28 10:30:00';
    try {
        $orm->transaction(function () use ($orm, $other): void {
            $orm->save($other);
            throw new \RuntimeException('undo');
        });
    } catch (\RuntimeException) {
    }
    expect($other->updatedAt->format('H:i'))->toBe('10:00')   // its insert, at 10:00
        ->and($other->isDirty())->toBeTrue();

    Clock::$now = '2026-10-28 11:00:00';
    $orm->save($other);
    expect(rows($orm)[0]['updated_at'])->toBe('2026-10-28 11:00:00.000000+00:00');
});

it('stamps every row a query\'s update() reaches, through a Change too, and the models held follow', function () {
    $orm = orm();
    $orm->query(Bin::class)->insert([['sku' => 'A', 'stock' => 5], ['sku' => 'B', 'stock' => 5], ['sku' => 'C', 'stock' => 5]]);
    $held = $orm->query(Bin::class)->where('sku', 'A')->first();

    Clock::$now = '2026-10-28 10:00:00';
    $orm->query(Bin::class)->where('sku', '<', 'C')->update(['stock' => Change::add(-1)]);

    expect(array_column(rows($orm), 'updated_at'))->toBe([LATER, LATER, THEN])
        ->and(array_column(rows($orm), 'created_at'))->toBe([THEN, THEN, THEN])
        ->and($held?->updatedAt->format('H:i'))->toBe('10:00')
        ->and($held?->isDirty())->toBeFalse();
});

it('keeps one a query\'s update() sets, which a private(set) one cannot be', function () {
    $orm = orm();
    $orm->query(OpenBin::class)->insert([['sku' => 'A']]);

    Clock::$now = '2026-10-28 10:00:00';
    $orm->query(OpenBin::class)->update(['stock' => 1, 'updatedAt' => new \DateTimeImmutable('2025-01-01 00:00:00', new \DateTimeZone('UTC'))]);

    expect(rows($orm)[0]['updated_at'])->toBe('2025-01-01 00:00:00.000000+00:00')
        ->and(fn () => $orm->query(Bin::class)->update(['updatedAt' => new \DateTimeImmutable()]))
        ->toThrow(\InvalidArgumentException::class, 'set by the model alone')
        ->and(fn () => $orm->query(Bin::class)->update([]))
        ->toThrow(\InvalidArgumentException::class, 'at least one field');
});

it('moves an upserted row\'s updatedAt and keeps its createdAt; a new one takes both', function () {
    $orm = orm();
    $orm->query(Bin::class)->insert([['sku' => 'A', 'stock' => 1]]);

    Clock::$now = '2026-10-28 10:00:00';
    $orm->query(Bin::class)->upsert([['sku' => 'A', 'stock' => 2], ['sku' => 'B', 'stock' => 3]], 'sku');

    expect(rows($orm))->toBe([
        ['sku' => 'A', 'stock' => 2, 'created_at' => THEN, 'updated_at' => LATER],
        ['sku' => 'B', 'stock' => 3, 'created_at' => LATER, 'updated_at' => LATER],
    ]);
});

it('moves it on an upsert naming its fields, but not on one updating nothing', function () {
    $orm = orm();
    $orm->query(Bin::class)->insert([['sku' => 'A', 'stock' => 1], ['sku' => 'B', 'stock' => 1]]);

    Clock::$now = '2026-10-28 10:00:00';
    $orm->query(Bin::class)->upsert([['sku' => 'A', 'stock' => 2]], 'sku', ['stock']);
    $orm->query(Bin::class)->upsert([['sku' => 'B', 'stock' => 2]], 'sku', []);
    $orm->query(Bin::class)->upsert([['sku' => 'B']], 'sku');

    expect(rows($orm))->toBe([
        ['sku' => 'A', 'stock' => 2, 'created_at' => THEN, 'updated_at' => LATER],
        ['sku' => 'B', 'stock' => 1, 'created_at' => THEN, 'updated_at' => THEN],
    ])->and(fn () => $orm->query(Bin::class)->upsert([['sku' => 'A', 'stock' => 3]], 'sku', ['stock', 'createdAt']))
        ->toThrow(\InvalidArgumentException::class, 'createdAt');
});

it('stamps only a column typed as a moment', function () {
    expect(Metadata::for(Mark::class)->updatedAtProperty)->toBeNull()
        ->and(Metadata::for(Bin::class)->updatedAtProperty)->toBe('updatedAt');

    $orm = orm();
    $mark = $orm->save(new Mark());
    $mark->updatedAt = 'today';
    $orm->save($mark);

    expect($orm->connection()->select('SELECT updated_at FROM marks')[0]['updated_at'])->toBe('today');
});
