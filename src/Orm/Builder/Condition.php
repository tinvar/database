<?php

declare(strict_types=1);

namespace Tinvar\Orm\Builder;

use Tinvar\Orm\RelationMeta;

/**
 * One condition of a query's WHERE, resolved and checked when it was added:
 * its columns are ColumnRefs, its values already the scalars that are
 * bound, its raw SQL already tokens. The compiler turns it into SQL and
 * nothing it holds came from anywhere but the metadata and the binding list.
 *
 * @internal for the query builder, inside this package
 */
final readonly class Condition
{
    public const string COMPARE = 'compare';
    public const string IN = 'in';
    public const string NULL = 'null';
    public const string GROUP = 'group';
    public const string RAW = 'raw';
    public const string HAS = 'has';
    public const string NEVER = 'never';
    public const string ALWAYS = 'always';

    /**
     * @param self::* $kind
     * @param bool $or joined to the condition before it by OR, not AND
     * @param list<int|float|string> $values the bound values, in order
     * @param list<Condition> $conditions a group's, or the related rows' for HAS
     * @param list<array{string, string|ColumnRef}> $tokens a raw condition's (Expression)
     */
    private function __construct(
        public string $kind,
        public bool $or,
        public ?ColumnRef $column = null,
        public string $operator = '',
        public array $values = [],
        public bool $not = false,
        public array $conditions = [],
        public array $tokens = [],
        public ?RelationMeta $relation = null,
    ) {}

    /** @param int|float|string $value */
    public static function compare(bool $or, ColumnRef $column, string $operator, int|float|string $value): self
    {
        return new self(self::COMPARE, $or, $column, $operator, [$value]);
    }

    /** @param list<int|float|string> $values */
    public static function in(bool $or, ColumnRef $column, array $values, bool $not): self
    {
        return new self(self::IN, $or, $column, values: $values, not: $not);
    }

    public static function null(bool $or, ColumnRef $column, bool $not): self
    {
        return new self(self::NULL, $or, $column, not: $not);
    }

    /** @param list<Condition> $conditions */
    public static function group(bool $or, array $conditions): self
    {
        return new self(self::GROUP, $or, conditions: $conditions);
    }

    /**
     * @param list<array{string, string|ColumnRef}> $tokens
     * @param list<int|float|string> $values
     */
    public static function raw(bool $or, array $tokens, array $values): self
    {
        return new self(self::RAW, $or, values: $values, tokens: $tokens);
    }

    /** @param list<Condition> $conditions the related rows' */
    public static function has(bool $or, RelationMeta $relation, array $conditions, bool $not): self
    {
        return new self(self::HAS, $or, not: $not, conditions: $conditions, relation: $relation);
    }

    /**
     * A condition no row meets: an IN over nothing, or an id that is no
     * id. Kept as a condition rather than short-circuited, so the statement
     * still runs -- and still fails closed on the tenant -- like any other.
     */
    public static function never(bool $or): self
    {
        return new self(self::NEVER, $or);
    }

    /** A condition every row meets: a NOT IN over nothing, or `<>` an id that is no id. */
    public static function always(bool $or): self
    {
        return new self(self::ALWAYS, $or);
    }

    /**
     * Every ColumnRef this condition reads at its own level -- not inside a
     * HAS, whose related rows join for themselves.
     *
     * @return list<ColumnRef>
     */
    public function columns(): array
    {
        return match ($this->kind) {
            self::COMPARE, self::IN, self::NULL => $this->column === null ? [] : [$this->column],
            self::GROUP => array_merge([], ...array_map(static fn (Condition $c): array => $c->columns(), $this->conditions)),
            self::RAW => array_values(array_filter(
                array_map(static fn (array $token): ?ColumnRef => $token[1] instanceof ColumnRef ? $token[1] : null, $this->tokens),
                static fn (?ColumnRef $ref): bool => $ref !== null,
            )),
            default => [],
        };
    }
}
