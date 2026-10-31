<?php

declare(strict_types=1);

/**
 * A DateTimeImmutable column keeps its instant through a write and a read.
 *
 * It was written as 'Y-m-d H:i:s': the wall-clock time in whatever zone the
 * value carried, with no offset and no fraction. A New York time came back
 * read in the default zone -- five hours off -- and with its milliseconds cut.
 */

namespace Tinvar\Tests\Orm\DateTimeRoundTrip;

use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;

#[Table('events')]
final class Event extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public \DateTimeImmutable $at;
}

function eventsOrm(): Orm
{
    $pdo = new \PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE events (id TEXT PRIMARY KEY, at TEXT NOT NULL)');

    return new Orm(new PdoConnection($pdo), new IdentityMap('test'));
}

function event(\DateTimeImmutable $at): Event
{
    $event = new Event();
    $event->at = $at;

    return $event;
}

it('keeps the instant of a time written in another zone, to the microsecond', function () {
    $orm = eventsOrm();
    $at = new \DateTimeImmutable('2026-01-01 10:00:00.250001', new \DateTimeZone('America/New_York'));
    $id = $orm->save(event($at))->primaryKey();

    $orm->flush();
    $back = $orm->find(Event::class, $id) ?? throw new \LogicException('no event');

    expect($back->at->format('Y-m-d H:i:s.u'))->toBe('2026-01-01 15:00:00.250001')
        ->and($back->at->getTimezone()->getName())->toBe('UTC')
        ->and($back->at == $at)->toBeTrue('the same instant');
});

it('writes UTC, whatever zone the value carries', function () {
    $orm = eventsOrm();
    $orm->save(event(new \DateTimeImmutable('2026-01-01 10:00:00', new \DateTimeZone('Asia/Tokyo'))));

    expect($orm->connection()->select('SELECT at FROM events')[0]['at'])
        ->toBe('2026-01-01T01:00:00.000000Z');
});

it('reads a stored time with no offset as UTC, not the default zone', function () {
    $default = date_default_timezone_get();
    date_default_timezone_set('America/New_York');

    try {
        [$event] = eventsOrm()->hydrateAll(Event::class, [['id' => 'e1', 'at' => '2026-01-02 03:04:05']]);
    } finally {
        date_default_timezone_set($default);
    }

    expect($event->at->format('Y-m-d H:i:s e'))->toBe('2026-01-02 03:04:05 UTC');
});

it('counts the same instant in another zone as no change', function () {
    $orm = eventsOrm();
    $event = $orm->save(event(new \DateTimeImmutable('2026-01-01 10:00:00', new \DateTimeZone('America/New_York'))));

    $event->at = new \DateTimeImmutable('2026-01-01 15:00:00', new \DateTimeZone('UTC'));

    expect($event->isDirty())->toBeFalse();
});
