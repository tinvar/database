<?php

declare(strict_types=1);

/**
 * A model's moments as CarbonImmutable: read from the column in UTC,
 * written back in the form the dialect keeps a timestamp in, stamped by
 * the ORM as CarbonImmutable where the property says so, and following
 * the clock a test moves. Mutable Carbon is refused, as every mutable
 * value is (ADR 0122).
 */

namespace Tinvar\Tests\Orm\CarbonTimestamps;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\CarbonClock;
use Tinvar\Orm\Cast;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\MappingException;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\TimestampStorage;
use Tinvar\Tests\Orm\Fixtures\Databases;

require_once __DIR__ . '/Fixtures/databases.php';

#[Table('shipments')]
final class Shipment extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public CarbonImmutable $placedAt;
    #[Column] public ?CarbonImmutable $shippedAt = null;
    #[Column] public private(set) CarbonImmutable $createdAt;
    #[Column] public private(set) CarbonImmutable $updatedAt;
}

#[Table('shipments')]
final class MutableShipment extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public Carbon $placedAt;
}

#[Table('shipments')]
final class InterfaceShipment extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public CarbonInterface $placedAt;
}

#[Table('shipments')]
final class DateTimeShipment extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public \DateTime $placedAt;
}

/** A value object holding a moment: immutable, since CarbonImmutable is. */
final readonly class Window implements Cast
{
    public function __construct(public CarbonImmutable $opens) {}

    public static function columnType(): string
    {
        return 'string';
    }

    public static function fromColumn(mixed $stored): object
    {
        return new self(new CarbonImmutable((string) $stored, 'UTC'));
    }

    public static function toColumn(object $value): string
    {
        return $value->opens->format('c');
    }
}

#[Table('windows')]
final class Slot extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public Window $window;
}

const TABLE = 'CREATE TABLE shipments (id TEXT PRIMARY KEY, placed_at TEXT NOT NULL, shipped_at TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)';

function sqliteOrm(?\Closure $clock = null): Orm
{
    $pdo = new \PDO('sqlite::memory:');
    $pdo->exec(TABLE);

    return new Orm(new PdoConnection($pdo), new IdentityMap('test'), clock: $clock);
}

function shipment(CarbonImmutable $placedAt): Shipment
{
    $shipment = new Shipment();
    $shipment->placedAt = $placedAt;

    return $shipment;
}

afterEach(function () {
    CarbonClock::follow(null);
});

describe('reading and writing', function () {
    it('writes ISO 8601 text in UTC on SQLite, at a width that sorts, and reads it back as CarbonImmutable in UTC', function () {
        $orm = sqliteOrm();
        $at = new CarbonImmutable('2026-01-01 10:00:00.250001', 'America/New_York');
        $id = $orm->save(shipment($at))->primaryKey();
        $orm->flush();

        $stored = $orm->connection()->select('SELECT placed_at, shipped_at FROM shipments')[0];
        $back = $orm->find(Shipment::class, $id) ?? throw new \LogicException('no shipment');

        expect($stored)->toBe(['placed_at' => '2026-01-01T15:00:00.250001Z', 'shipped_at' => null])
            ->and($back->placedAt)->toBeInstanceOf(CarbonImmutable::class)
            ->and($back->placedAt->format('Y-m-d H:i:s.u e'))->toBe('2026-01-01 15:00:00.250001 UTC')
            ->and($back->placedAt->equalTo($at))->toBeTrue()
            ->and($back->shippedAt)->toBeNull();
    });

    it('reads what a column held before the ORM wrote it: an ISO string to the second, or a time with an offset', function () {
        [$old, $offset, $bare] = sqliteOrm()->hydrateAll(Shipment::class, [
            ['id' => 'a', 'placed_at' => '2026-10-24T09:30:00Z', 'created_at' => '2026-10-24T09:30:00Z', 'updated_at' => '2026-10-24T09:30:00Z'],
            ['id' => 'b', 'placed_at' => '2026-10-24 11:30:00+02:00', 'created_at' => '2026-10-24T09:30:00Z', 'updated_at' => '2026-10-24T09:30:00Z'],
            ['id' => 'c', 'placed_at' => '2026-10-24 09:30:00', 'created_at' => '2026-10-24T09:30:00Z', 'updated_at' => '2026-10-24T09:30:00Z'],
        ]);

        foreach ([$old, $offset, $bare] as $shipment) {
            expect($shipment->placedAt->toIso8601ZuluString())->toBe('2026-10-24T09:30:00Z')
                ->and($shipment->placedAt->getTimezone()->getName())->toBe('UTC');
        }
    });

    it('counts the same instant in another zone as no change', function () {
        $orm = sqliteOrm();
        $shipment = $orm->save(shipment(new CarbonImmutable('2026-01-01 10:00:00', 'America/New_York')));

        $shipment->placedAt = new CarbonImmutable('2026-01-01 15:00:00', 'UTC');

        expect($shipment->isDirty())->toBeFalse();
    });

    it('compares a column with a CarbonImmutable in the form it is stored in', function () {
        $orm = sqliteOrm();
        foreach (['2026-01-01 09:00', '2026-01-01 11:00', '2026-01-02 08:00'] as $at) {
            $orm->save(shipment(new CarbonImmutable($at, 'UTC')));
        }

        $after = Shipment::query($orm)->where('placedAt', '>', new CarbonImmutable('2026-01-01 05:00:00', 'America/New_York'))->orderBy('placedAt')->fetch();

        expect(array_map(static fn (Shipment $s): string => $s->placedAt->format('d H'), $after))->toBe(['01 11', '02 08']);
    });

    // A column is read as what it holds, as a DateTimeImmutable column is:
    // Carbon's constructor, like parse(), reads a relative time against the
    // clock a test moved, so a row read would change with the test's clock.
    it('reads a column without asking the clock a test moved', function () {
        $orm = sqliteOrm();
        $orm->connection()->execute("INSERT INTO shipments VALUES ('01a166b1-0816-74c0-b4d5-2e9eb49b0838', 'today', NULL, 'today', 'today')");
        CarbonClock::follow(static fn (): \DateTimeImmutable => new \DateTimeImmutable('2031-02-03 04:05:06', new \DateTimeZone('UTC')));

        $placedAt = $orm->find(Shipment::class, '01a166b1-0816-74c0-b4d5-2e9eb49b0838')?->placedAt;

        // The followed clock's today would be 2031-02-03; the system's is not.
        expect($placedAt?->format('Y-m-d'))->not->toBe('2031-02-03');
    });

    it('takes a value object holding a CarbonImmutable as immutable', function () {
        expect(Metadata::for(Slot::class)->columns)->toBe(['id' => 'id', 'window' => 'window']);
    });
});

describe('the moments the ORM stamps', function () {
    it('stamps createdAt and updatedAt as CarbonImmutable, from Carbon\'s now', function () {
        CarbonClock::follow(static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-28 09:30:00', new \DateTimeZone('UTC')));
        $orm = sqliteOrm();

        $shipment = $orm->save(shipment(new CarbonImmutable('2026-10-01', 'UTC')));

        expect($shipment->createdAt)->toBeInstanceOf(CarbonImmutable::class)
            ->and($shipment->createdAt->toIso8601ZuluString())->toBe('2026-10-28T09:30:00Z')
            ->and($shipment->updatedAt->toIso8601ZuluString())->toBe('2026-10-28T09:30:00Z')
            ->and($orm->connection()->select('SELECT created_at FROM shipments')[0]['created_at'])->toBe('2026-10-28T09:30:00.000000Z');
    });

    it('stamps from the clock the Orm was given, as CarbonImmutable all the same', function () {
        $orm = sqliteOrm(static fn (): \DateTimeImmutable => new \DateTimeImmutable('2030-05-05 05:05:05', new \DateTimeZone('UTC')));

        $shipment = $orm->save(shipment(new CarbonImmutable('2026-10-01', 'UTC')));
        $shipment->placedAt = new CarbonImmutable('2026-10-02', 'UTC');
        $orm->save($shipment);

        expect($shipment->createdAt)->toBeInstanceOf(CarbonImmutable::class)
            ->and($shipment->updatedAt)->toBeInstanceOf(CarbonImmutable::class)
            ->and($shipment->updatedAt->toIso8601ZuluString())->toBe('2030-05-05T05:05:05Z');
    });

    it('stamps a query\'s insert() as save() does', function () {
        CarbonClock::follow(static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-28 09:30:00', new \DateTimeZone('UTC')));
        $orm = sqliteOrm();

        Shipment::query($orm)->insert([['placedAt' => new CarbonImmutable('2026-10-01', 'UTC')]]);

        expect($orm->connection()->select('SELECT placed_at, created_at, updated_at FROM shipments')[0])->toBe([
            'placed_at' => '2026-10-01T00:00:00.000000Z',
            'created_at' => '2026-10-28T09:30:00.000000Z',
            'updated_at' => '2026-10-28T09:30:00.000000Z',
        ]);
    });
});

describe('mutable moments', function () {
    it('refuses mutable Carbon, naming CarbonImmutable', function (string $model, string $type) {
        expect(fn () => Metadata::for($model))->toThrow(
            MappingException::class,
            "{$model}::\$placedAt is typed {$type}, which can change in place. A model is immutable all the way down (ADR 0122), "
            . 'so a moment is a Carbon\CarbonImmutable (or a DateTimeImmutable): type the property CarbonImmutable.',
        );
    })->with([
        'Carbon' => [MutableShipment::class, Carbon::class],
        'CarbonInterface, which Carbon implements' => [InterfaceShipment::class, CarbonInterface::class],
        'DateTime' => [DateTimeShipment::class, \DateTime::class],
    ]);
});

describe('the clock', function () {
    it('makes Carbon\'s now follow a clock, and gives it back', function () {
        $at = new \DateTimeImmutable('2031-02-03 04:05:06.700000', new \DateTimeZone('UTC'));
        CarbonClock::follow(static fn (): \DateTimeImmutable => $at);

        $followed = [CarbonImmutable::now()->format('Y-m-d H:i:s.u'), Carbon::now()->format('Y-m-d H:i:s.u'), CarbonImmutable::now()->getTimezone()->getName()];
        CarbonClock::follow(null);

        expect($followed)->toBe(['2031-02-03 04:05:06.700000', '2031-02-03 04:05:06.700000', 'UTC'])
            // The system's time again: within seconds of it, which a year
            // compared with date('Y') was not on New Year's Eve's last tick.
            ->and(abs(CarbonImmutable::now()->getTimestamp() - time()))->toBeLessThan(5)
            ->and(CarbonClock::following())->toBeFalse();
    });

    // Carbon asks its hook as it makes any Carbon, the clock's own included.
    it('follows a clock that makes a Carbon itself, rather than asking it again without end', function () {
        CarbonClock::follow(static fn (): CarbonImmutable => CarbonImmutable::createFromTimestampUTC(1_800_000_000));

        expect(CarbonImmutable::now()->toIso8601ZuluString())->toBe('2027-01-15T08:00:00Z')
            ->and(CarbonImmutable::now()->subDay()->toIso8601ZuluString())->toBe('2027-01-14T08:00:00Z');
    });

    it('reads the clock again each time, so a clock moved later moves Carbon with it', function () {
        $now = 1_800_000_000;
        CarbonClock::follow(static function () use (&$now): \DateTimeImmutable {
            return new \DateTimeImmutable("@{$now}");
        });

        $first = CarbonImmutable::now()->getTimestamp();
        $now += 3600;

        expect([$first, CarbonImmutable::now()->getTimestamp()])->toBe([1_800_000_000, 1_800_003_600])
            ->and(CarbonClock::following())->toBeTrue();
    });
});

describe('the form each dialect keeps', function () {
    it('is ISO 8601 with Z on SQLite, an offset on Postgres, UTC wall-clock time on MySQL', function () {
        $at = new CarbonImmutable('2026-01-01 10:00:00.250001', 'Asia/Tokyo');

        expect(TimestampStorage::forDriver('sqlite')->format($at))->toBe('2026-01-01T01:00:00.250001Z')
            ->and(TimestampStorage::forDriver('pgsql')->format($at))->toBe('2026-01-01 01:00:00.250001+00:00')
            ->and(TimestampStorage::forDriver('mysql')->format($at))->toBe('2026-01-01 01:00:00.250001')
            ->and(TimestampStorage::forDriver(null)->format($at))->toBe('2026-01-01 01:00:00.250001+00:00');
    });

    it('round-trips a CarbonImmutable through timestamptz on Postgres', function () {
        Databases::inScratchSchema('pgsql', static function (\PDO $pdo): void {
            $pdo->exec('SET TIME ZONE \'America/Los_Angeles\'');
            $pdo->exec('CREATE TABLE shipments (id uuid PRIMARY KEY, placed_at timestamptz NOT NULL, shipped_at timestamptz, created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL)');
            $orm = new Orm(new PdoConnection($pdo, 'pgsql'), new IdentityMap('test'));
            $at = new CarbonImmutable('2026-01-01 10:00:00.250001', 'America/New_York');

            $id = $orm->save(shipment($at))->primaryKey();
            $orm->flush();
            $back = $orm->find(Shipment::class, $id) ?? throw new \LogicException('no shipment');

            expect($back->placedAt->format('Y-m-d H:i:s.u e'))->toBe('2026-01-01 15:00:00.250001 UTC')
                ->and(Shipment::query($orm)->where('placedAt', '>=', $at)->count())->toBe(1);
        });
    })->skip(fn () => Databases::postgresMissing() ?? false);

    it('round-trips a CarbonImmutable through DATETIME(6) on MySQL, whatever the session\'s zone', function () {
        Databases::inScratchSchema('mysql', static function (\PDO $pdo): void {
            $pdo->exec("SET SESSION sql_mode = CONCAT(@@sql_mode, ',ANSI_QUOTES')");
            $pdo->exec("SET time_zone = '-08:00'");
            $pdo->exec('CREATE TABLE shipments (id CHAR(36) PRIMARY KEY, placed_at DATETIME(6) NOT NULL, shipped_at DATETIME(6), created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL)');
            $orm = new Orm(new PdoConnection($pdo, 'mysql'), new IdentityMap('test'));
            $at = new CarbonImmutable('2026-01-01 10:00:00.250001', 'America/New_York');

            $id = $orm->save(shipment($at))->primaryKey();
            $orm->flush();
            $back = $orm->find(Shipment::class, $id) ?? throw new \LogicException('no shipment');

            expect($pdo->query('SELECT placed_at FROM shipments')->fetchColumn())->toBe('2026-01-01 15:00:00.250001')
                ->and($back->placedAt->format('Y-m-d H:i:s.u e'))->toBe('2026-01-01 15:00:00.250001 UTC')
                ->and(Shipment::query($orm)->where('placedAt', '>=', $at)->count())->toBe(1);
        });
    })->skip(fn () => Databases::mysqlMissing() ?? false);
});
