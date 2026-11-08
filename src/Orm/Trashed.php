<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * Which rows of a #[SoftDeletes] model a read takes (ADR 0191): the live
 * ones -- every read's unless it says otherwise -- every row, trashed or
 * not, or the trashed ones alone. A model that does not soft delete has
 * no trashed row, and each reads every row it has.
 */
enum Trashed
{
    /** The rows no delete() has trashed: what find(), all(), a query and a #[HasMany] read. */
    case Without;

    /** Every row, trashed or not: withTrashed(). */
    case With;

    /** The trashed rows alone: onlyTrashed(). */
    case Only;

    /**
     * The predicate on $column -- quoted, and qualified as the statement
     * needs -- or null where this reads every row.
     */
    public function predicate(string $column): ?string
    {
        return match ($this) {
            self::Without => "{$column} IS NULL",
            self::Only => "{$column} IS NOT NULL",
            self::With => null,
        };
    }

    /** Whether a row in that state is one this reads. */
    public function admits(bool $trashed): bool
    {
        return match ($this) {
            self::Without => !$trashed,
            self::Only => $trashed,
            self::With => true,
        };
    }
}
