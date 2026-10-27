<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * What update() adds to a column, as the database holds it when the row is
 * written: `stock = stock + ?`, the amount bound. Query::increment() and
 * decrement() make one; update(['stock' => Increment::by(-1)]) is the same.
 *
 * The one expression a write through a query sets, and a value rather than
 * text, so no SQL a caller writes reaches an UPDATE. Its column is the
 * model's own, mapped by a property typed int or float that may not be
 * NULL -- NULL plus one is NULL, which no caller means -- and that the model
 * does not keep to itself (ADR 0064).
 */
final readonly class Increment
{
    private function __construct(public int|float $amount) {}

    /** Adds $amount -- below zero, takes it away. */
    public static function by(int|float $amount): self
    {
        if (\is_float($amount) && !is_finite($amount)) {
            throw new \InvalidArgumentException('An increment is a finite number.');
        }
        if ($amount == 0) {
            throw new \InvalidArgumentException('An increment of nothing writes nothing; leave the column out.');
        }

        return new self($amount);
    }
}
