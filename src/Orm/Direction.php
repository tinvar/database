<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * Which way a query sorts by a column. A sort direction taken from a request
 * is read with tryFrom(), so anything but `asc` or `desc` is no direction
 * at all rather than text that reaches the SQL.
 */
enum Direction: string
{
    case Asc = 'asc';
    case Desc = 'desc';

    /** A direction as a caller spells it: the enum, or `asc`/`desc` in any case. */
    public static function of(self|string $direction): self
    {
        if ($direction instanceof self) {
            return $direction;
        }

        return self::tryFrom(strtolower($direction))
            ?? throw new \InvalidArgumentException("A query sorts 'asc' or 'desc', not '{$direction}'.");
    }

    public function reversed(): self
    {
        return $this === self::Asc ? self::Desc : self::Asc;
    }
}
