<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * An aggregate function the query builder writes: over a group's rows in
 * a report (Query::report(), having(), orderByAggregate()), or over a
 * row's related rows (withCount(), withSum(), orderByRelation()).
 *
 * A closed set, so the function in the SQL is one of these words and never
 * text a caller passed; the column it reads is resolved against the
 * metadata as every other identifier is (ADR 0064).
 *
 * What each answers, the same on SQLite, Postgres and MySQL, whose drivers
 * hand back a count as an integer on one and a decimal string on another:
 *
 *   - Count, CountDistinct: an int;
 *   - Sum: an int, or a float for a column that is not whole -- 0, not
 *     NULL, over no row, as Query::sum() answers;
 *   - Avg: a float, or null over no row;
 *   - Min, Max: the column's value as the database returns it, or null.
 */
enum Aggregate: string
{
    case Count = 'count';
    case CountDistinct = 'countDistinct';
    case Sum = 'sum';
    case Min = 'min';
    case Max = 'max';
    case Avg = 'avg';

    /**
     * The SQL over $expression -- a column, quoted, from the compiler -- or
     * over every row, for a count given none.
     *
     * @internal for the query builder, inside this package
     */
    public function sql(?string $expression): string
    {
        if ($expression === null) {
            return $this === self::Count ? 'COUNT(*)' : throw new \LogicException("{$this->name} reads a column.");
        }

        return match ($this) {
            self::Count => "COUNT({$expression})",
            self::CountDistinct => "COUNT(DISTINCT {$expression})",
            self::Sum => "SUM({$expression})",
            self::Min => "MIN({$expression})",
            self::Max => "MAX({$expression})",
            self::Avg => "AVG({$expression})",
        };
    }

    /** Whether it reads a column: all but a plain count may not. */
    public function needsColumn(): bool
    {
        return $this !== self::Count;
    }

    /** Whether it can answer NULL: over no row, or over NULLs alone. Sum is answered as 0. */
    public function mayBeNull(): bool
    {
        return \in_array($this, [self::Min, self::Max, self::Avg], true);
    }

    /**
     * A value the database answered, as this function's PHP value: what
     * each driver spells its own way read the one way.
     *
     * @internal for the query builder, inside this package
     */
    public function read(mixed $value): int|float|string|null
    {
        return match ($this) {
            self::Count, self::CountDistinct => self::integer($value) ?? throw self::notANumber($this, $value),
            self::Sum => $value === null ? 0 : (self::integer($value) ?? self::float($value) ?? throw self::notANumber($this, $value)),
            self::Avg => $value === null ? null : (self::float($value) ?? throw self::notANumber($this, $value)),
            self::Min, self::Max => match (true) {
                $value === null, \is_int($value), \is_float($value), \is_string($value) => $value,
                \is_bool($value) => (int) $value,
                default => throw new \UnexpectedValueException("{$this->name} answered " . get_debug_type($value) . '.'),
            },
        };
    }

    /**
     * Every integer PHP holds -- nineteen digits among them -- read as
     * one: text that comes back the same from an int. A number past them
     * is not one, rather than cut to the largest.
     */
    private static function integer(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && preg_match('/^-?[0-9]+\z/', $value) === 1 && (string) (int) $value === $value) {
            return (int) $value;
        }

        return null;
    }

    private static function float(mixed $value): ?float
    {
        return \is_int($value) || \is_float($value) || (\is_string($value) && is_numeric($value)) ? (float) $value : null;
    }

    private static function notANumber(self $function, mixed $value): \UnexpectedValueException
    {
        return new \UnexpectedValueException("{$function->name} answered " . get_debug_type($value) . ', not a number.');
    }
}
