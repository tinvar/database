<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * A model a query read, with the aggregates over its related rows that
 * withCount(), withSum() and the rest asked for (Query::fetchAggregated()).
 *
 * Beside the model rather than on it: a model is its row, whole, and holds
 * what save() writes back -- a count of its children is not a column, and
 * the same model, held once by the identity map, can be read by two queries
 * counting different things. So the values travel with the model they
 * describe, by the names they were asked for under, and the model stays
 * exactly what find() would answer (ADR 0064).
 *
 * @template T of Model
 */
final readonly class Aggregated
{
    /**
     * @param T $model
     * @param array<string, int|float|string|null> $values by name, as Aggregate::read() answers each
     */
    public function __construct(
        public Model $model,
        public array $values,
    ) {}

    /** One aggregate, by the name it was asked for under. */
    public function value(string $name): int|float|string|null
    {
        if (!\array_key_exists($name, $this->values)) {
            throw new \OutOfBoundsException(
                "No aggregate '{$name}' was asked for; asked: " . (implode(', ', array_keys($this->values)) ?: 'none') . '.',
            );
        }

        return $this->values[$name];
    }

    /** One aggregate that is a whole number: a count, or a sum of whole numbers. */
    public function int(string $name): int
    {
        $value = $this->value($name);

        return \is_int($value) ? $value : throw new \UnexpectedValueException(
            "The aggregate '{$name}' is " . get_debug_type($value) . ', not an int.',
        );
    }
}
