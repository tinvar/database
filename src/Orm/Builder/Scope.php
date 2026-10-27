<?php

declare(strict_types=1);

namespace Tinvar\Orm\Builder;

use Tinvar\Orm\Direction;
use Tinvar\Orm\Nulls;

/**
 * One query, compiled as far as every statement made from it shares: its
 * FROM with the joins its columns need, its WHERE with the tenant predicate
 * first, and its ORDER BY. A fetch, a count, an exists and a cursor's
 * anchor are each this, with their own SELECT and tail.
 *
 * Compiled when the statement is about to run, not when the query was
 * built: the tenant predicate is the scope in force then (Compiler).
 *
 * @internal for the query builder, inside this package
 */
final readonly class Scope
{
    /**
     * @param list<mixed> $fromBindings  what the joins' tenant predicates bind
     * @param string      $where         the WHERE clause's body, or '' for none
     * @param list<mixed> $whereBindings
     * @param list<array{string, Direction, Nulls|null, list<mixed>}> $orders each ORDER BY expression, quoted, with
     *        its direction, where its NULLs go -- for one that may be NULL -- and what it binds: a sort by a
     *        relation's aggregate is a subquery, with the related model's tenant predicate inside
     * @param array<string, string> $aliases by path key ('' for the model read), the alias it is read as
     */
    public function __construct(
        public string $alias,
        public string $from,
        public array $fromBindings,
        public string $where,
        public array $whereBindings,
        public array $orders,
        public array $aliases,
    ) {}

    /** A column the query's joins reach, as it is written in SQL. */
    public function expression(ColumnRef $column): string
    {
        $alias = $this->aliases[$column->pathKey()] ?? throw new \LogicException(
            "The column '{$column->column}' is reached through '{$column->pathKey()}', which this query does not join.",
        );

        return Identifiers::quote($alias) . '.' . Identifiers::quote($column->column);
    }

    /**
     * FROM and WHERE, with $extra ANDed to the conditions -- a cursor's
     * keyset, or the anchor's id -- after the tenant predicate.
     *
     * @param list<mixed> $extraBindings
     * @return array{string, list<mixed>}
     */
    public function body(string $extra = '', array $extraBindings = []): array
    {
        $sql = ' FROM ' . $this->from;
        $where = array_values(array_filter([$this->where, $extra], static fn (string $part): bool => $part !== ''));
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        return [$sql, [...$this->fromBindings, ...$this->whereBindings, ...$extraBindings]];
    }

    /**
     * ORDER BY for the query's orders, or with every direction -- and every
     * NULLs placement -- turned round.
     *
     * A column that may be NULL sorts by `(x IS NULL)` first, which is
     * false for a value and true for a NULL on every database, so its NULLs
     * go where the query said on SQLite, Postgres and MySQL alike (Nulls).
     */
    public function orderBy(bool $reversed = false): string
    {
        if ($this->orders === []) {
            return '';
        }

        $terms = [];
        foreach ($this->orders as [$expression, $direction, $nulls]) {
            if ($nulls !== null) {
                $terms[] = "({$expression} IS NULL) " . (($nulls === Nulls::Last) !== $reversed ? 'ASC' : 'DESC');
            }
            $terms[] = $expression . ' ' . (($direction === Direction::Asc) !== $reversed ? 'ASC' : 'DESC');
        }

        return ' ORDER BY ' . implode(', ', $terms);
    }

    /**
     * What orderBy() binds, in its order: each term's own -- twice for one
     * that sorts its NULLs first, which names its expression twice. The same
     * either way round, since turning the order round turns no term.
     *
     * @return list<mixed>
     */
    public function orderBindings(): array
    {
        $bindings = [];
        foreach ($this->orders as [, , $nulls, $values]) {
            if ($nulls !== null) {
                array_push($bindings, ...$values);
            }
            array_push($bindings, ...$values);
        }

        return $bindings;
    }
}
