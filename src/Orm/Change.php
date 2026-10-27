<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * What update() sets a column to, worked out by the database from the row
 * as it is written: `update(['stock' => Change::add(-1)->atLeast(0)])`,
 * `['priceCents' => Change::multiply(2)]`, `['listedName' =>
 * Change::copy('name')]`.
 *
 * A closed set, typed, and never text: an amount, a factor or a bound is a
 * number and is bound; a column copied is a field resolved against the
 * metadata, as every identifier is, and must be the model's own. So an
 * UPDATE's assignment is always one the builder wrote (ADR 0073):
 *
 *   - add($n)       `c = c + ?`
 *   - multiply($n)  `c = c * ?`
 *   - copy($field)  `c = other`, another column of the same row, of the
 *                   same type
 *   - ->atLeast($n), ->atMost($n) bound what any of them makes, as `CASE
 *     WHEN x < ? THEN ? ELSE x END`, which every database spells alike
 *
 * Arithmetic and bounds are for a column mapped by a property typed int or
 * float that is never NULL (NULL plus one is NULL), and an int's take whole
 * numbers; a copy is between two properties of one built-in type, neither
 * mapped through a Cast, and a NULL only into a column that takes one.
 * Builder\Fields checks each against the metadata before any SQL is built.
 * Increment::by() is add().
 */
final readonly class Change
{
    public const string ADD = 'add';
    public const string MULTIPLY = 'multiply';
    public const string COPY = 'copy';

    /**
     * @param self::ADD|self::MULTIPLY|self::COPY $kind
     * @internal read by the query builder; made by the named constructors
     */
    private function __construct(
        public string $kind,
        public int|float|null $operand = null,
        public string|\BackedEnum|null $field = null,
        public int|float|null $floor = null,
        public int|float|null $ceiling = null,
    ) {}

    /** Adds $amount to what the row holds -- below zero, takes it away. */
    public static function add(int|float $amount): self
    {
        self::assertFinite($amount, 'An amount added');
        if ($amount == 0) {
            throw new \InvalidArgumentException('Adding nothing writes nothing; leave the column out.');
        }

        return new self(self::ADD, $amount);
    }

    /** Multiplies what the row holds by $factor. */
    public static function multiply(int|float $factor): self
    {
        self::assertFinite($factor, 'A factor');

        return new self(self::MULTIPLY, $factor);
    }

    /** What the same row holds in $field, a column of the model's own of the same type. */
    public static function copy(string|\BackedEnum $field): self
    {
        if ($field instanceof \BackedEnum && !\is_string($field->value)) {
            throw new \InvalidArgumentException('A field named by an enum is named by its string value.');
        }

        return new self(self::COPY, field: $field);
    }

    /** No less than $floor: `CASE WHEN x < ? THEN ? ELSE x END`. */
    #[\NoDiscard]
    public function atLeast(int|float $floor): self
    {
        self::assertFinite($floor, 'A bound');
        if ($this->ceiling !== null && $floor > $this->ceiling) {
            throw new \InvalidArgumentException('A change at least a number above its most leaves nothing to write.');
        }

        return new self($this->kind, $this->operand, $this->field, $floor, $this->ceiling);
    }

    /** No more than $ceiling: `CASE WHEN x > ? THEN ? ELSE x END`. */
    #[\NoDiscard]
    public function atMost(int|float $ceiling): self
    {
        self::assertFinite($ceiling, 'A bound');
        if ($this->floor !== null && $ceiling < $this->floor) {
            throw new \InvalidArgumentException('A change at most a number below its least leaves nothing to write.');
        }

        return new self($this->kind, $this->operand, $this->field, $this->floor, $ceiling);
    }

    /** Whether it is arithmetic or bounded, and so reads the column as a number. */
    public function isNumeric(): bool
    {
        return $this->kind !== self::COPY || $this->floor !== null || $this->ceiling !== null;
    }

    /** @return list<int|float> every number it binds */
    public function numbers(): array
    {
        return array_values(array_filter([$this->operand, $this->floor, $this->ceiling], static fn (int|float|null $n): bool => $n !== null));
    }

    private static function assertFinite(int|float $number, string $what): void
    {
        if (\is_float($number) && !is_finite($number)) {
            throw new \InvalidArgumentException("{$what} is a finite number.");
        }
    }
}
