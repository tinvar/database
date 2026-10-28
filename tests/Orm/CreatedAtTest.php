<?php

declare(strict_types=1);

/**
 * "#[Column] public private(set) DateTimeImmutable $createdAt" and
 * "Asymmetric visibility keeps IDs and timestamps read-only outside the ORM"
 * (tinvar-spec.md, "Models"): the ORM stamps a new row's createdAt, as it
 * mints its key, through save() and through a query's insert().
 */

namespace Tinvar\Tests\Orm\CreatedAt;

use Tinvar\Orm\Attributes\BeforeSave;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;

#[Table('racks')]
final class Rack extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name = '';
    #[Column] public private(set) \DateTimeImmutable $createdAt;

    /** A backfill keeps the time it carries. */
    public static function from(string $name, \DateTimeImmutable $createdAt): self
    {
        $rack = new self();
        $rack->name = $name;
        $rack->createdAt = $createdAt;

        return $rack;
    }
}

#[Table('notes')]
final class Note extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $body = '';
    #[Column] public ?\DateTimeImmutable $createdAt = null;
}

#[Table('logs')]
final class Log extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $createdAt = 'yesterday';
}

#[Table('racks')]
final class HookedRack extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name = '';
    #[Column] public private(set) \DateTimeImmutable $createdAt;

    #[BeforeSave]
    private function tidy(): void
    {
        $this->name = trim($this->name);
    }
}

function orm(): Orm
{
    $pdo = new \PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE racks (id TEXT PRIMARY KEY, name TEXT NOT NULL, created_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE notes (id TEXT PRIMARY KEY, body TEXT NOT NULL, created_at TEXT)');
    $pdo->exec('CREATE TABLE logs (id TEXT PRIMARY KEY, created_at TEXT NOT NULL)');

    return new Orm(
        new PdoConnection($pdo),
        new IdentityMap('test'),
        clock: static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-28 09:30:00.000000', new \DateTimeZone('UTC')),
    );
}

it('stamps a new model\'s createdAt on save, from the Orm\'s clock', function () {
    $orm = orm();
    $rack = new Rack();
    $rack->name = 'R-14';
    $orm->save($rack);

    expect($rack->createdAt->format('Y-m-d H:i:s'))->toBe('2026-10-28 09:30:00')
        ->and($orm->connection()->select('SELECT created_at FROM racks')[0]['created_at'])
        ->toBe('2026-10-28 09:30:00.000000+00:00');
});

it('keeps one the model set itself, and never moves it on an update', function () {
    $orm = orm();
    $rack = $orm->save(Rack::from('R-1', new \DateTimeImmutable('2020-01-01 00:00:00', new \DateTimeZone('UTC'))));
    $rack->name = 'R-2';
    $orm->save($rack);

    expect($orm->connection()->select('SELECT name, created_at FROM racks')[0])
        ->toBe(['name' => 'R-2', 'created_at' => '2020-01-01 00:00:00.000000+00:00']);
});

it('stamps the rows a query inserts, which cannot name a private(set) one', function () {
    $orm = orm();
    $orm->query(Rack::class)->insert([['name' => 'R-1'], ['name' => 'R-2']]);

    expect(array_column($orm->connection()->select('SELECT created_at FROM racks'), 'created_at'))
        ->toBe(['2026-10-28 09:30:00.000000+00:00', '2026-10-28 09:30:00.000000+00:00'])
        ->and(fn () => $orm->query(Rack::class)->insert([['name' => 'R-3', 'createdAt' => new \DateTimeImmutable()]]))
        ->toThrow(\InvalidArgumentException::class, 'set by the model alone');
});

it('stamps through a query insert that runs #[BeforeSave] methods', function () {
    $orm = orm();
    $orm->query(HookedRack::class)->insert([['name' => '  R-1  ']]);

    expect($orm->connection()->select('SELECT name, created_at FROM racks')[0])
        ->toBe(['name' => 'R-1', 'created_at' => '2026-10-28 09:30:00.000000+00:00']);
});

it('leaves a nullable one the model initialised, and one a query row names', function () {
    $orm = orm();
    $orm->save(new Note());
    $orm->query(Note::class)->insert([['body' => 'b', 'createdAt' => null]]);

    expect(array_column($orm->connection()->select('SELECT created_at FROM notes'), 'created_at'))->toBe([null, null]);
});

it('stamps only a column typed as a moment', function () {
    expect(Metadata::for(Log::class)->createdAtProperty)->toBeNull()
        ->and(Metadata::for(Rack::class)->createdAtProperty)->toBe('createdAt')
        ->and(Metadata::for(Note::class)->createdAtProperty)->toBe('createdAt');

    $orm = orm();
    $orm->save(new Log());

    expect($orm->connection()->select('SELECT created_at FROM logs')[0]['created_at'])->toBe('yesterday');
});

it('reads the system clock in UTC when given none', function () {
    $now = new Orm(new PdoConnection(new \PDO('sqlite::memory:')), new IdentityMap('test'))->now();

    expect($now->getTimezone()->getName())->toBe('UTC')
        ->and(abs($now->getTimestamp() - time()))->toBeLessThan(5);
});
