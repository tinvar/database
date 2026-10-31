<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * The form a moment is written to its column in, on each database: always
 * the instant in UTC, to the microsecond, spelled as the column's type
 * keeps it best (ADR 0145).
 *
 *   - SQLite has no time type: the column is TEXT, so the moment is ISO
 *     8601 with a Z, at one width -- `2026-10-31T12:00:00.000000Z` -- and
 *     text order is time order, for an ORDER BY and a comparison alike.
 *   - Postgres keeps a timestamptz as the instant: the offset is spelled
 *     out, so the server takes the instant rather than its session zone's
 *     reading of a bare time.
 *   - MySQL's DATETIME(6) has no zone, and converts a time with an offset
 *     into the session's zone before it stores it: so the time is written
 *     bare, as UTC wall-clock time, which nothing converts, and read back
 *     as UTC.
 *
 * A connection that does not say its driver gets the offset form, which
 * reads as the same instant anywhere. Reading takes any of them, and the
 * ISO strings a column held before the ORM wrote it.
 */
enum TimestampStorage: string
{
    case Iso8601 = 'Y-m-d\TH:i:s.u\Z';
    case WithOffset = 'Y-m-d H:i:s.uP';
    case UtcWallClock = 'Y-m-d H:i:s.u';

    /** The form for PDO's name for a driver: 'sqlite', 'pgsql' or 'mysql'; null for one unnamed. */
    public static function forDriver(?string $driver): self
    {
        return match ($driver) {
            'sqlite' => self::Iso8601,
            'mysql' => self::UtcWallClock,
            default => self::WithOffset,
        };
    }

    /** The form for a connection, by the driver it names. */
    public static function for(Connection $connection): self
    {
        return self::forDriver($connection instanceof NamesDriver ? $connection->driver() : null);
    }

    /** $at as this form stores it: the same instant, in UTC. */
    public function format(\DateTimeInterface $at): string
    {
        return \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('UTC'))->format($this->value);
    }
}
