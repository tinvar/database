<?php

declare(strict_types=1);

namespace Tinvar\Orm;

use Tinvar\Orm\Builder\Assignment;
use Tinvar\Orm\Builder\ColumnRef;
use Tinvar\Orm\Builder\Compiler;
use Tinvar\Orm\Builder\Condition;
use Tinvar\Orm\Builder\Expression;
use Tinvar\Orm\Builder\Fields;
use Tinvar\Orm\Builder\Identifiers;
use Tinvar\Orm\Builder\Measure;
use Tinvar\Orm\Builder\RelationMeasure;
use Tinvar\Orm\Builder\Report;
use Tinvar\Orm\Builder\Scope;
use Tinvar\Support\Uuid;

/**
 * The ORM's query builder: immutable, so a scope is a plain function over
 * it, composed with the pipe operator (tinvar-spec.md, "Querying"):
 *
 *     $racks = Rack::query($orm)
 *         |> scope\atSite($site)
 *         |> scope\withPowerAbove(5_000)
 *         |> fn (Query $q) => $q->orderBy('name')
 *         |> fetch(...);
 *
 * Every method that narrows returns a copy (`clone with`) and leaves the
 * query it was called on as it was, so a query handed to a scope cannot be
 * changed under its caller. Each is #[\NoDiscard]: a narrowing whose result
 * is dropped narrows nothing, which is a silent wrong answer.
 *
 * **Identifiers** are resolved when they are added, against compiled
 * metadata (Builder\Identifiers): a column, a property mapping one, a
 * #[BelongsTo] property for its key, a path through #[BelongsTo] relations
 * ('site.code'), or a backed enum whose value is one of those. Anything else
 * is refused before any SQL exists. A sort or filter field from a request is
 * narrowed by the caller first, to a backed enum or an #[In] list of the
 * fields a Data class shows -- the builder knows what a model maps, not
 * what a page shows (ADR 0057).
 *
 * **Values** are bound, every one: compared, listed, in a raw condition, a
 * limit, an offset, a cursor's anchor.
 *
 * **Tenancy** is the Orm's, applied when the statement is compiled -- by a
 * terminal method, not when the query is built -- to the model's table,
 * every table a column joins, every subquery whereHas(), a filter on a
 * collection's column or an aggregate over a row's related rows makes, and
 * every arm of a union: the predicate find() and all() use, failing closed with NoTenantContext where
 * they do, lifted only inside acrossTenants(), widened only inside
 * withinSubtree(). A query built in one scope and run in another reads the
 * scope it runs in; nothing about it is fixed when it is built, and it
 * holds nothing of the request but the Orm.
 *
 * Reads go through the identity map and the result set as find() and all()
 * do, so a relation read on a row it returns loads for all of them -- and
 * inside a render, batches with every other fiber's (BatchScheduler).
 *
 * @template T of Model
 */
final readonly class Query
{
    /** The most values one IN list binds: a list, not a table. */
    public const int MAX_VALUES = 10_000;

    /** The most values one INSERT binds: below what SQLite, Postgres and MySQL each take in one statement. */
    private const int MAX_BINDINGS = 30_000;

    /** What a unionAll()'s arms are read as, together. */
    private const string UNION = 'u';

    /** What a unionAll()'s arms each name their position as, for a cursor over it. */
    private const string ARM = 'tinvar_arm';

    /** What fetchAggregated() names each aggregate in its select list, with its position: never a name a caller gave. */
    private const string MEASURE = 'tinvar_aggregate_';

    /** Comparison operators, as a caller may spell them, and as they are written. */
    private const array OPERATORS = [
        '=' => '=', '!=' => '<>', '<>' => '<>', '<' => '<', '<=' => '<=', '>' => '>', '>=' => '>=',
        'like' => 'LIKE', 'not like' => 'NOT LIKE',
    ];

    /**
     * @param class-string<T> $class
     * @param list<Condition> $conditions
     * @param list<array{ColumnRef|Measure|RelationMeasure, Direction, Nulls|null}> $orders by a column, by an
     *        aggregate over the rows (a report's), or by one over each row's related rows
     * @param list<string> $with relation paths to load with the rows
     * @param list<ColumnRef> $groups what a report groups by
     * @param list<array{Measure|ColumnRef, string, int|float|string, bool}> $havings a report's conditions on its groups -- an
     *        aggregate, or a column it groups by -- each ORed or ANDed with those before
     * @param list<RelationMeasure> $measures the aggregates over related rows fetchAggregated() reads
     * @param list<list<Condition>>|null $unionAll each arm's conditions, once unionAll() joined them
     */
    private function __construct(
        private Orm $orm,
        private string $class,
        private ModelMeta $meta,
        private array $conditions = [],
        private array $orders = [],
        private ?int $limit = null,
        private ?int $offset = null,
        private array $with = [],
        private array $groups = [],
        private array $havings = [],
        private bool $distinct = false,
        private array $measures = [],
        private ?array $unionAll = null,
    ) {}

    /**
     * Every row of $class the scope in force lets the reader see, until
     * narrowed.
     *
     * @template M of Model
     * @param class-string<M> $class
     * @return self<M>
     */
    #[\NoDiscard]
    public static function of(Orm $orm, string $class): self
    {
        return new self($orm, $class, Metadata::for($class));
    }

    /** @return class-string<T> */
    public function model(): string
    {
        return $this->class;
    }

    // ------------------------------------------------------------ narrowing

    /**
     * Rows whose $column compares with $value: `where('name', 'x')` for
     * equality, `where('heightU', '>=', 42)` for another operator, or a
     * closure taking and returning a query for a group in parentheses.
     *
     * A value is a scalar, a backed enum, a date (as the ORM writes one) or
     * a saved model (its key). Null is refused: `= NULL` matches nothing in
     * SQL, and whereNull() says what is meant.
     *
     * A column reached through a #[HasMany] -- `where('items.priceCents',
     * '>', 200)` -- is rows with at least one related row it holds for:
     * whereHas('items', fn ($q) => $q->where('priceCents', '>', 200)), an
     * EXISTS under the related model's tenant predicate, never a join, so a
     * row is never repeated for each child that matches (ADR 0064). So too
     * whereIn(), whereNotIn(), whereNull() and whereNotNull(): "some child
     * has", never "every child has" -- whereDoesntHave() says "none has".
     *
     * @param string|\BackedEnum|(\Closure(self<T>): self<T>) $column
     * @return static
     */
    #[\NoDiscard]
    public function where(string|\BackedEnum|\Closure $column, mixed $operator = null, mixed $value = null): self
    {
        $arguments = \func_num_args();

        return $this->throughCollection(false, $column, static fn (self $q, string $c): self => $arguments === 2
            ? $q->where($c, $operator)
            : $q->where($c, $operator, $value))
            ?? $this->compare(false, $column, $operator, $value, $arguments);
    }

    /**
     * where(), joined to the conditions before it by OR. In a group, so it
     * ORs what the group holds and nothing outside it: the tenant
     * predicate is never one of the alternatives.
     *
     * @param string|\BackedEnum|(\Closure(self<T>): self<T>) $column
     * @return static
     */
    #[\NoDiscard]
    public function orWhere(string|\BackedEnum|\Closure $column, mixed $operator = null, mixed $value = null): self
    {
        $arguments = \func_num_args();

        return $this->throughCollection(true, $column, static fn (self $q, string $c): self => $arguments === 2
            ? $q->where($c, $operator)
            : $q->where($c, $operator, $value))
            ?? $this->compare(true, $column, $operator, $value, $arguments);
    }

    /**
     * @param iterable<mixed> $values
     * @return static
     */
    #[\NoDiscard]
    public function whereIn(string|\BackedEnum $column, iterable $values): self
    {
        return $this->throughCollection(false, $column, static fn (self $q, string $c): self => $q->whereIn($c, $values))
            ?? $this->in(false, $column, $values, false);
    }

    /**
     * @param iterable<mixed> $values
     * @return static
     */
    #[\NoDiscard]
    public function orWhereIn(string|\BackedEnum $column, iterable $values): self
    {
        return $this->throughCollection(true, $column, static fn (self $q, string $c): self => $q->whereIn($c, $values))
            ?? $this->in(true, $column, $values, false);
    }

    /**
     * @param iterable<mixed> $values
     * @return static
     */
    #[\NoDiscard]
    public function whereNotIn(string|\BackedEnum $column, iterable $values): self
    {
        return $this->throughCollection(false, $column, static fn (self $q, string $c): self => $q->whereNotIn($c, $values))
            ?? $this->in(false, $column, $values, true);
    }

    /** @return static */
    #[\NoDiscard]
    public function whereNull(string|\BackedEnum $column): self
    {
        return $this->throughCollection(false, $column, static fn (self $q, string $c): self => $q->whereNull($c))
            ?? $this->adding(Condition::null(false, Identifiers::column($this->meta, $column), false));
    }

    /** @return static */
    #[\NoDiscard]
    public function orWhereNull(string|\BackedEnum $column): self
    {
        return $this->throughCollection(true, $column, static fn (self $q, string $c): self => $q->whereNull($c))
            ?? $this->adding(Condition::null(true, Identifiers::column($this->meta, $column), false));
    }

    /** @return static */
    #[\NoDiscard]
    public function whereNotNull(string|\BackedEnum $column): self
    {
        return $this->throughCollection(false, $column, static fn (self $q, string $c): self => $q->whereNotNull($c))
            ?? $this->adding(Condition::null(false, Identifiers::column($this->meta, $column), true));
    }

    /**
     * A condition the other methods cannot spell -- arithmetic, a function,
     * a CASE -- over the model's columns, with every value a `?`:
     * `whereRaw('price_cents * ? > stock', [2])`.
     *
     * Read as tokens and written again from them (Builder\Expression): no
     * quote, no comment, no word the model or a short list of keywords and
     * functions does not know, and parentheses that balance. So it can
     * neither name another table nor reach past the tenant predicate.
     *
     * @param list<mixed> $bindings
     * @return static
     */
    #[\NoDiscard]
    public function whereRaw(string $sql, array $bindings = []): self
    {
        return $this->raw(false, $sql, $bindings);
    }

    /**
     * @param list<mixed> $bindings
     * @return static
     */
    #[\NoDiscard]
    public function orWhereRaw(string $sql, array $bindings = []): self
    {
        return $this->raw(true, $sql, $bindings);
    }

    /**
     * Rows with at least one related row -- through a dotted path for
     * nested relations ('lines.product') -- that $constraint's conditions
     * hold for, if it is given. The related rows are read under their own
     * model's tenant predicate, inside the subquery.
     *
     * @param (\Closure(self<Model>): self<Model>)|null $constraint given a query over the related
     *        model; only its conditions are taken
     * @return static
     */
    #[\NoDiscard]
    public function whereHas(string $relation, ?\Closure $constraint = null): self
    {
        return $this->adding($this->has(false, $relation, $constraint, false));
    }

    /**
     * @param (\Closure(self<Model>): self<Model>)|null $constraint
     * @return static
     */
    #[\NoDiscard]
    public function orWhereHas(string $relation, ?\Closure $constraint = null): self
    {
        return $this->adding($this->has(true, $relation, $constraint, false));
    }

    /**
     * Rows with no related row that $constraint holds for.
     *
     * @param (\Closure(self<Model>): self<Model>)|null $constraint
     * @return static
     */
    #[\NoDiscard]
    public function whereDoesntHave(string $relation, ?\Closure $constraint = null): self
    {
        return $this->adding($this->has(false, $relation, $constraint, true));
    }

    /**
     * Sorts by $column, after any sort already added. A column reached
     * through a #[BelongsTo] ('site.code') joins its table.
     *
     * A column that may be NULL -- by its property's type, or because it is
     * reached through a join, which finds no row for some -- puts its NULLs
     * last, either way it sorts, unless $nulls says first: the same order on
     * every database, where each would otherwise choose its own (Nulls).
     *
     * @return static
     */
    #[\NoDiscard]
    public function orderBy(string|\BackedEnum $column, Direction|string $direction = Direction::Asc, ?Nulls $nulls = null): self
    {
        $ref = Identifiers::column($this->meta, $column);
        $placed = $ref->path !== [] || $this->nullable($ref->column) ? ($nulls ?? Nulls::Last) : null;

        return clone($this, ['orders' => [...$this->orders, [$ref, Direction::of($direction), $placed]]]);
    }

    /** @return static */
    #[\NoDiscard]
    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new \InvalidArgumentException('A limit is never negative.');
        }

        return clone($this, ['limit' => $limit]);
    }

    /** @return static */
    #[\NoDiscard]
    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException('An offset is never negative.');
        }

        return clone($this, ['offset' => $offset]);
    }

    /**
     * Loads these relations -- dotted for nested ones ('lines.product') --
     * for every row the query returns, a statement per relation, so the
     * code reading them makes no lazy load at all.
     *
     * @return static
     */
    #[\NoDiscard]
    public function with(string ...$relations): self
    {
        foreach ($relations as $relation) {
            Identifiers::relationPath($this->meta, $relation);
        }

        return clone($this, ['with' => array_values(array_unique([...$this->with, ...$relations]))]);
    }

    /**
     * Each value once: pluck() answers each distinct value, and a report
     * of columns each distinct row of them. Written as a GROUP BY of what
     * is read rather than SELECT DISTINCT, which means the same and lets a
     * sort put NULLs where the query says on every database -- Postgres
     * refuses DISTINCT's ORDER BY any term it does not select (ADR 0064).
     *
     * fetch() reads each row once already: every join is to one row, and a
     * collection is filtered with EXISTS, so no row is repeated to remove.
     * The one query that repeats rows is a unionAll(), and on one,
     * distinct() reads each row once -- fetch(), first(), count(),
     * exists(), the paginators, sum(), min() and max() read the union() of
     * its arms, by key, as union() would (ADR 0123).
     *
     * @return static
     */
    #[\NoDiscard]
    public function distinct(): self
    {
        return clone($this, ['distinct' => true]);
    }

    /**
     * Groups the rows a report() reads by these columns -- a model's own,
     * or reached through #[BelongsTo] relations ('aisle.code'), whose joins
     * are held to the tenant like any other.
     *
     * A grouped query reads a report, not models: fetch() and the rest
     * refuse it. Models stay whole -- a model is a row, and a group is not one.
     *
     * @return static
     */
    #[\NoDiscard]
    public function groupBy(string|\BackedEnum ...$columns): self
    {
        if ($columns === []) {
            throw new \InvalidArgumentException('groupBy() names a column at least.');
        }
        $groups = $this->groups;
        foreach ($columns as $column) {
            $ref = Identifiers::column($this->meta, $column);
            if (!self::among($ref, $groups)) {
                $groups[] = $ref;
            }
        }

        return clone($this, ['groups' => $groups]);
    }

    /**
     * Keeps the groups whose aggregate compares with $value:
     * `having(Aggregate::Count, null, '>', 1)`, `having(Aggregate::Sum,
     * 'stock', '>=', 10)`. The function is a case, the column the
     * metadata's, the value bound.
     *
     * @return static
     */
    #[\NoDiscard]
    public function having(Aggregate $function, string|\BackedEnum|null $column, string $operator, mixed $value): self
    {
        return $this->keeping(false, $function, $column, $operator, $value);
    }

    /**
     * having(), as an alternative to the conditions before it: `having(Count,
     * null, '>', 10)->orHaving(Sum, 'stock', '>=', 100)`. SQL's precedence,
     * AND before OR, as where() and orWhere() have it. Every term compares a
     * group's aggregate, inside the HAVING alone: the tenant predicate is in
     * the WHERE, under every group, and no OR here reaches it (ADR 0073).
     *
     * @return static
     */
    #[\NoDiscard]
    public function orHaving(Aggregate $function, string|\BackedEnum|null $column, string $operator, mixed $value): self
    {
        if ($this->havings === []) {
            throw new \LogicException('orHaving() is an alternative to a having() before it.');
        }

        return $this->keeping(true, $function, $column, $operator, $value);
    }

    /**
     * Keeps the groups whose value of a column the report groups by
     * compares with $value: `groupBy('status')->having(Aggregate::Count,
     * null, '>', 10)->orHavingColumn('status', '=', 'vip')`. Only a column
     * grouped by, which is one value in each group -- any other holds many,
     * and which of them a database compared would be its own choice --
     * checked when report() runs, since groupBy() may come after. The
     * column is the metadata's and the value bound, as where() has them
     * (ADR 0123).
     *
     * @return static
     */
    #[\NoDiscard]
    public function havingColumn(string|\BackedEnum $column, string $operator, mixed $value): self
    {
        return $this->keepingColumn(false, $column, $operator, $value);
    }

    /**
     * havingColumn(), as an alternative to the conditions before it, with
     * SQL's precedence, as orHaving() has it.
     *
     * @return static
     */
    #[\NoDiscard]
    public function orHavingColumn(string|\BackedEnum $column, string $operator, mixed $value): self
    {
        if ($this->havings === []) {
            throw new \LogicException('orHavingColumn() is an alternative to a having() before it.');
        }

        return $this->keepingColumn(true, $column, $operator, $value);
    }

    /**
     * Sorts a report by an aggregate over each group:
     * `orderByAggregate(Aggregate::Count, direction: 'desc')`. One that may
     * be NULL -- a MIN, a MAX, an AVG over NULLs alone -- puts its NULLs
     * last unless $nulls says first, as orderBy() does.
     *
     * @return static
     */
    #[\NoDiscard]
    public function orderByAggregate(
        Aggregate $function,
        string|\BackedEnum|null $column = null,
        Direction|string $direction = Direction::Asc,
        ?Nulls $nulls = null,
    ): self {
        $measure = $this->measure($function, $column);
        $placed = $function->mayBeNull() ? ($nulls ?? Nulls::Last) : null;

        return clone($this, ['orders' => [...$this->orders, [$measure, Direction::of($direction), $placed]]]);
    }

    /**
     * Sorts the rows by an aggregate over each one's related rows:
     * `orderByRelation('items', Aggregate::Max, 'priceCents', 'desc')` --
     * a #[HasMany] has many values of a column, so a sort by one names
     * which: the largest, the smallest, the sum, the count. A correlated
     * subquery under the related model's tenant predicate, so another
     * tenant's child is never what a row sorts by, and no row is repeated.
     * A row with no related row sorts by a count or a sum of 0, and by a
     * MIN, MAX or AVG of NULL, last unless $nulls says first.
     *
     * @param (\Closure(self<Model>): self<Model>)|null $constraint narrows the related rows read
     * @return static
     */
    #[\NoDiscard]
    public function orderByRelation(
        string $relation,
        Aggregate $function,
        string|\BackedEnum|null $column = null,
        Direction|string $direction = Direction::Asc,
        ?Nulls $nulls = null,
        ?\Closure $constraint = null,
    ): self {
        $measure = $this->relationMeasure('', $relation, $function, $column, $constraint, 'An orderByRelation() constraint');
        $placed = $function->mayBeNull() ? ($nulls ?? Nulls::Last) : null;

        return clone($this, ['orders' => [...$this->orders, [$measure, Direction::of($direction), $placed]]]);
    }

    /**
     * Reads, beside each row, how many related rows it has -- that
     * $constraint holds for, if given -- answered under $as, by default
     * '{relation}Count', by fetchAggregated(). The related rows are counted
     * under their own model's tenant predicate, in the statement that reads
     * the rows: one statement, however many aggregates.
     *
     * @param (\Closure(self<Model>): self<Model>)|null $constraint
     * @return static
     */
    #[\NoDiscard]
    public function withCount(string $relation, ?\Closure $constraint = null, ?string $as = null): self
    {
        return $this->withAggregate($relation, Aggregate::Count, null, $constraint, $as);
    }

    /**
     * withCount()'s sum of a related column: 0 for a row with none.
     *
     * @param (\Closure(self<Model>): self<Model>)|null $constraint
     * @return static
     */
    #[\NoDiscard]
    public function withSum(string $relation, string|\BackedEnum $column, ?\Closure $constraint = null, ?string $as = null): self
    {
        return $this->withAggregate($relation, Aggregate::Sum, $column, $constraint, $as);
    }

    /**
     * @param (\Closure(self<Model>): self<Model>)|null $constraint
     * @return static
     */
    #[\NoDiscard]
    public function withMin(string $relation, string|\BackedEnum $column, ?\Closure $constraint = null, ?string $as = null): self
    {
        return $this->withAggregate($relation, Aggregate::Min, $column, $constraint, $as);
    }

    /**
     * @param (\Closure(self<Model>): self<Model>)|null $constraint
     * @return static
     */
    #[\NoDiscard]
    public function withMax(string $relation, string|\BackedEnum $column, ?\Closure $constraint = null, ?string $as = null): self
    {
        return $this->withAggregate($relation, Aggregate::Max, $column, $constraint, $as);
    }

    /**
     * @param (\Closure(self<Model>): self<Model>)|null $constraint
     * @return static
     */
    #[\NoDiscard]
    public function withAvg(string $relation, string|\BackedEnum $column, ?\Closure $constraint = null, ?string $as = null): self
    {
        return $this->withAggregate($relation, Aggregate::Avg, $column, $constraint, $as);
    }

    /**
     * Any aggregate over each row's related rows, read by fetchAggregated()
     * under $as -- by default the relation, the function and the column run
     * together ('itemsSumPriceCents'). The name is PHP's alone: the SQL
     * names each aggregate by its position.
     *
     * @param (\Closure(self<Model>): self<Model>)|null $constraint
     * @return static
     */
    #[\NoDiscard]
    public function withAggregate(
        string $relation,
        Aggregate $function,
        string|\BackedEnum|null $column = null,
        ?\Closure $constraint = null,
        ?string $as = null,
    ): self {
        $name = $as ?? lcfirst(str_replace('.', '', ucwords($relation, '.'))) . ucfirst($function->value)
            . ($column === null ? '' : ucfirst(str_replace('.', '', ucwords($column instanceof \BackedEnum ? (string) $column->value : $column, '.'))));
        foreach ($this->measures as $measure) {
            if ($measure->name === $name) {
                throw new \InvalidArgumentException("An aggregate is read under '{$name}' already; name this one with \$as.");
            }
        }

        $measure = $this->relationMeasure($name, $relation, $function, $column, $constraint, "A with{$function->name}() constraint");

        return clone($this, ['measures' => [...$this->measures, $measure]]);
    }

    /**
     * The rows this query reads and the rows $other reads, each once: a
     * UNION of the two queries' keys, each arm a statement of its own read
     * under the model's tenant predicate, and the rows read by key from it
     * under the predicate again. So the union is a query like any other --
     * sorted, narrowed further, joined, paged, counted, written -- and
     * reads nothing either arm could not (ADR 0064).
     *
     * $other is a query over the same model, narrowed by conditions alone:
     * its order, limit and eager loads would be the union's, which are this
     * query's.
     *
     * @param self<T> $other
     * @return static
     */
    #[\NoDiscard]
    public function union(self $other): self
    {
        $arm = $this->arm($other, 'union()');
        if ($this->unionAll !== null) {
            throw new \LogicException('union() takes in a query, and this one is a unionAll() already; join each arm the one way.');
        }

        // Another arm of the union this query is already, rather than a
        // union of a union.
        $conditions = \count($this->conditions) === 1 && $this->conditions[0]->kind === Condition::UNION && !$this->conditions[0]->or && !$this->conditions[0]->not
            ? [Condition::union(false, [...$this->conditions[0]->arms, $arm])]
            : [Condition::union(false, [$this->conditions, $arm])];

        return clone($this, ['conditions' => $conditions]);
    }

    /**
     * The rows this query reads that $other reads too. Compiled as union()
     * is, as a condition: this query's conditions, in parentheses of their
     * own, AND the key IN $other's keys -- a statement of its own over the
     * model's table, under the model's tenant predicate, its joins with
     * theirs in the ON -- and the read around it under the predicate again.
     * So the result is a query like any other: sorted, narrowed, counted,
     * paged by number or by cursor, and written (ADR 0073).
     *
     * @param self<T> $other
     * @return static
     */
    #[\NoDiscard]
    public function intersect(self $other): self
    {
        return $this->narrowedByArm($other, 'intersect()', false);
    }

    /**
     * The rows this query reads that $other does not: intersect()'s
     * converse, the key NOT IN $other's keys. A key is never NULL, so NOT
     * IN reads as it says; and $other's arm is read under the tenant
     * predicate like this query, so a row of another tenant's $other
     * might have named is no row this query could read in the first place.
     *
     * @param self<T> $other
     * @return static
     */
    #[\NoDiscard]
    public function except(self $other): self
    {
        return $this->narrowedByArm($other, 'except()', true);
    }

    /**
     * The rows this query reads, then the rows $other reads, a row both
     * read twice: UNION ALL of whole rows, read from as one table. Each arm
     * is a statement of its own under the model's tenant predicate.
     *
     * Read by fetch(), first(), count(), exists(), paginate() and
     * cursorPaginate() -- whose cursor names a row by its key and the arm
     * that read it, so a row read twice is two places in the order (ADR
     * 0123); sorted by the model's own columns alone, since what is sorted
     * is the arms' result. A condition added after it would be no arm's,
     * so it is refused: narrow each arm before joining them. distinct()
     * reads each row once.
     *
     * @param self<T> $other
     * @return static
     */
    #[\NoDiscard]
    public function unionAll(self $other): self
    {
        $arm = $this->arm($other, 'unionAll()');

        return clone($this, ['unionAll' => [...($this->unionAll ?? [$this->conditions]), $arm], 'conditions' => []]);
    }

    // ------------------------------------------------------------- reading

    /**
     * The rows, as models.
     *
     * @return list<T>
     */
    #[\NoDiscard]
    public function fetch(): array
    {
        $this->assertModelsRead('fetch()');
        $this->assertOffsetLimited();
        if ($this->distinctUnionAll()) {
            return $this->asUnion()->fetch();
        }

        [$sql, $bindings] = $this->select($this->readScope(new Compiler($this->orm)), limit: $this->limit, offset: $this->offset);

        return $this->hydrate($this->orm->statements()->select($sql, $bindings));
    }

    /**
     * The rows, as models, each with the aggregates over its related rows
     * that withCount(), withSum() and the rest asked for, by name: one
     * statement, each aggregate a subquery in it under the related model's
     * tenant predicate. The models are whole and the identity map's, as
     * fetch() returns them; the aggregates are beside them (ADR 0064).
     *
     * @return list<Aggregated<T>>
     */
    #[\NoDiscard]
    public function fetchAggregated(): array
    {
        $this->assertModelsRead('fetchAggregated()', aggregates: true);
        if ($this->unionAll !== null) {
            throw new \LogicException('fetchAggregated() reads rows of the model\'s table, and a unionAll() reads its arms\' rows.');
        }
        $this->assertOffsetLimited();

        $compiler = new Compiler($this->orm);
        $scope = $compiler->scope($this->meta, $this->conditions, $this->orders);
        $list = '';
        $listBindings = [];
        foreach ($this->measures as $i => $measure) {
            [$sql, $bindings] = $compiler->relationMeasure($this->meta, $scope->alias, $measure);
            $list .= ", {$sql} AS " . Identifiers::quote(self::MEASURE . $i);
            array_push($listBindings, ...$bindings);
        }

        [$sql, $bindings] = $this->select($scope, limit: $this->limit, offset: $this->offset, list: $list, listBindings: $listBindings);
        $rows = $this->orm->statements()->select($sql, $bindings);

        $values = [];
        foreach ($rows as $r => $row) {
            $values[$r] = [];
            foreach ($this->measures as $i => $measure) {
                $values[$r][$measure->name] = $measure->function->read($row[self::MEASURE . $i] ?? null);
                unset($rows[$r][self::MEASURE . $i]);
            }
        }

        // MIN() and MAX() answer one of the related column's own values,
        // which the model-leak check looks for as pluck()'s are.
        foreach ($this->measures as $measure) {
            $column = $measure->column;
            if ($column !== null && ($measure->function === Aggregate::Min || $measure->function === Aggregate::Max)) {
                $this->orm->recordColumnRead(
                    self::ownerIn(Metadata::for($measure->relation->target), $column),
                    $column->column,
                    array_column($values, $measure->name),
                );
            }
        }

        $out = [];
        foreach ($this->hydrate($rows) as $r => $model) {
            $out[] = new Aggregated($model, $values[$r] ?? []);
        }

        return $out;
    }

    /**
     * Reads the groups -- or, with no groupBy(), the one row of aggregates,
     * or each row of some columns -- into $class, one object per row: each
     * key of $columns is a parameter of its constructor, and each value
     * what fills it -- a column the report groups by ('category',
     * 'aisle.code'), Aggregate::Count for the rows in the group, or an
     * aggregate of a column (`[Aggregate::Sum, 'stock']`).
     *
     *     Product::query($orm)
     *         ->groupBy('category')
     *         ->orderBy('category')
     *         ->report(CategoryCount::class, [
     *             'category' => 'category',
     *             'products' => Aggregate::Count,
     *         ]);
     *
     * Into a class of the caller's, never a model: a model is a whole row,
     * the identity map's, and saved as one, and a group is no row. Typed,
     * where a list of arrays would leave every caller to read each
     * driver's spelling of a number: each value is read as its parameter's
     * type -- int, float, string, bool, a backed enum, or mixed for what
     * the database answered -- and one that is not that type is an
     * UnexpectedValueException, not a wrong number (ADR 0064).
     *
     * Every column is the metadata's, every value bound, and the tenant
     * predicate on every table the report reads, as for any read.
     *
     * @template R of object
     * @param class-string<R> $class
     * @param array<string, string|\BackedEnum|Aggregate|array{Aggregate, string|\BackedEnum}> $columns
     * @return list<R>
     */
    #[\NoDiscard]
    public function report(string $class, array $columns): array
    {
        if ($this->measures !== []) {
            throw new \LogicException('report() reads groups or columns, not rows: withCount() and the rest are read by fetchAggregated().');
        }
        if ($this->unionAll !== null) {
            throw new \LogicException('report() reads the model\'s table, and a unionAll() reads its arms\' rows.');
        }
        $this->assertOffsetLimited();

        /** @var array<string, ColumnRef|Measure> $selected */
        $selected = [];
        foreach ($columns as $name => $spec) {
            // A list's keys name no parameter: Report::plan() refuses them.
            $selected[$name] = match (true) {
                $spec instanceof Aggregate => $this->measure($spec, null),
                \is_array($spec) => $this->measureOf($spec),
                default => Identifiers::column($this->meta, $spec),
            };
        }
        if ($selected === []) {
            throw new \InvalidArgumentException('A report reads a column at least.');
        }

        $plain = array_filter($selected, static fn (ColumnRef|Measure $what): bool => $what instanceof ColumnRef);
        $measures = array_filter($selected, static fn (ColumnRef|Measure $what): bool => $what instanceof Measure);
        $grouped = $this->groups !== [];
        if (!$grouped && $plain !== [] && $measures !== []) {
            throw new \LogicException('A report that reads columns beside aggregates groups by those columns: call groupBy().');
        }
        if ($this->havings !== [] && !$grouped) {
            throw new \LogicException('having() keeps groups: call groupBy().');
        }
        /** @var list<ColumnRef> $plainRefs */
        $plainRefs = array_values($plain);
        $groups = $grouped ? $this->groups : ($this->distinct ? $plainRefs : []);
        foreach ($plain as $name => $ref) {
            if ($grouped && !self::among($ref, $groups)) {
                throw new \LogicException("A report reads '{$name}', which it does not group by: a group holds many of its values.");
            }
        }
        foreach ($this->havings as [$kept]) {
            if ($kept instanceof ColumnRef && !self::among($kept, $groups)) {
                throw new \LogicException("havingColumn() compares '{$kept->column}', which the report does not group by: a group holds many of its values.");
            }
        }

        // Each row a group's, or the one row of aggregates: what it sorts by
        // is one value for it.
        $oneValueEach = $groups !== [] || $plain === [];
        foreach ($this->orders as [$by]) {
            if ($by instanceof RelationMeasure) {
                throw new \LogicException('report() sorts by its columns and aggregates; orderByRelation() sorts rows by their related rows.');
            }
            if ($by instanceof Measure && !$oneValueEach) {
                throw new \LogicException('orderByAggregate() sorts groups: call groupBy().');
            }
            if ($by instanceof ColumnRef && $oneValueEach && !self::among($by, $groups)) {
                throw new \LogicException("A report of groups sorts by what it groups by, or by an aggregate -- not by '{$by->column}', which a group holds many of.");
            }
        }

        $plan = Report::plan($class, array_keys($selected));

        $compiler = new Compiler($this->orm);
        $also = [...$groups, ...$plainRefs];
        foreach ([...$measures, ...array_column($this->havings, 0)] as $measure) {
            if ($measure instanceof ColumnRef) {
                $also[] = $measure;
            } elseif ($measure->column !== null) {
                $also[] = $measure->column;
            }
        }
        $scope = $compiler->scope($this->meta, $this->conditions, $this->orders, $also);

        $list = [];
        $listBindings = [];
        $aliases = [];
        foreach (array_keys($selected) as $i => $name) {
            $what = $selected[$name];
            $aliases[$name] = "c{$i}";
            [$expression, $expressionBindings] = $what instanceof ColumnRef ? [$scope->expression($what), []] : $compiler->measure($this->meta, $scope, $what);
            $list[] = $expression . ' AS ' . Identifiers::quote("c{$i}");
            array_push($listBindings, ...$expressionBindings);
        }

        [$body, $bindings] = $scope->body();
        $sql = 'SELECT ' . implode(', ', $list) . $body;
        if ($groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', array_map($scope->expression(...), $groups));
        }
        $havingBindings = [];
        if ($this->havings !== []) {
            $terms = '';
            foreach ($this->havings as $i => [$measure, $operator, $value, $or]) {
                [$expression, $expressionBindings] = $measure instanceof ColumnRef
                    ? [$scope->expression($measure), []]
                    : $compiler->measure($this->meta, $scope, $measure);
                $terms .= ($i === 0 ? '' : ($or ? ' OR ' : ' AND ')) . $expression . " {$operator} " . $compiler->placeholder($value);
                array_push($havingBindings, ...$expressionBindings);
                $havingBindings[] = $value;
            }
            $sql .= ' HAVING ' . $terms;
        }
        [$tail, $tailBindings] = $this->tail($this->limit, $this->offset);

        $rows = $this->orm->statements()->select(
            $sql . $scope->orderBy() . $tail,
            [...$listBindings, ...$bindings, ...$havingBindings, ...$scope->orderBindings(), ...$tailBindings],
        );

        // What a column read without a model answered, for the model-leak
        // check, as pluck() records it: each column read, and each MIN or
        // MAX, which answers one of its column's own values.
        foreach ($selected as $name => $what) {
            $ref = $what instanceof ColumnRef
                ? $what
                : ($what->function === Aggregate::Min || $what->function === Aggregate::Max ? $what->column : null);
            if ($ref !== null) {
                $this->orm->recordColumnRead($this->ownerOf($ref), $ref->column, array_column($rows, $aliases[$name]));
            } elseif ($what->over?->column !== null
                && ($what->function === Aggregate::Min || $what->function === Aggregate::Max)) {
                $this->orm->recordColumnRead(
                    self::ownerIn(Metadata::for($what->over->relation->target), $what->over->column),
                    $what->over->column->column,
                    array_column($rows, $aliases[$name]),
                );
            }
        }

        $out = [];
        foreach ($rows as $row) {
            $values = [];
            foreach ($selected as $name => $what) {
                $raw = $row[$aliases[$name]] ?? null;
                $values[$name] = $what instanceof Measure ? $what->function->read($raw) : $raw;
            }
            $out[] = $plan->make($values);
        }

        return $out;
    }

    /**
     * The first row, or null.
     *
     * @return T|null
     */
    #[\NoDiscard]
    public function first(): ?Model
    {
        return $this->limit(1)->fetch()[0] ?? null;
    }

    /** How many rows match, whatever the limit and offset. */
    #[\NoDiscard]
    public function count(): int
    {
        $this->assertModelsRead('count()', aggregates: true);
        if ($this->distinctUnionAll()) {
            return $this->asUnion()->count();
        }
        [$body, $bindings] = $this->readScope(new Compiler($this->orm), orders: false)->body();

        return (int) ($this->orm->statements()->select('SELECT COUNT(*) AS "aggregate"' . $body, $bindings)[0]['aggregate'] ?? 0);
    }

    /** Whether any row matches. */
    #[\NoDiscard]
    public function exists(): bool
    {
        $this->assertModelsRead('exists()', aggregates: true);
        if ($this->distinctUnionAll()) {
            return $this->asUnion()->exists();
        }
        [$body, $bindings] = $this->readScope(new Compiler($this->orm), orders: false)->body();

        return $this->orm->statements()->select('SELECT 1 AS "found"' . $body . ' LIMIT ?', [...$bindings, 1]) !== [];
    }

    /**
     * One column's values, as the database returns them, in the query's
     * order and within its limit. No model is made, so none is recorded or
     * cached: what comes back is the column, not a row.
     *
     * After distinct(), each value once -- NULL too, once -- sorted only by
     * the column itself, if at all: a value stands for many rows, which
     * have no one order between them.
     *
     * @return list<mixed>
     */
    #[\NoDiscard]
    public function pluck(string|\BackedEnum $column): array
    {
        $this->assertModelsRead('pluck()', aggregates: true);
        $ref = Identifiers::column($this->meta, $column);
        $this->assertOffsetLimited();
        $scope = $this->unionAll === null
            ? new Compiler($this->orm)->scope($this->meta, $this->conditions, $this->orders, [$ref])
            : $this->readScope(new Compiler($this->orm), also: [$ref]);
        [$body, $bindings] = $scope->body();
        [$tail, $tailBindings] = $this->tail($this->limit, $this->offset);

        $expression = $scope->expression($ref);
        $group = '';
        if ($this->distinct) {
            foreach ($this->orders as [$by]) {
                if (!$by instanceof ColumnRef || !self::among($by, [$ref])) {
                    throw new \LogicException('A distinct pluck() sorts by the column it reads alone: each value stands for rows with no one order between them.');
                }
            }
            $group = " GROUP BY {$expression}";
        }

        $rows = $this->orm->statements()->select(
            "SELECT {$expression} AS \"value\"" . $body . $group . $scope->orderBy() . $tail,
            [...$bindings, ...$scope->orderBindings(), ...$tailBindings],
        );

        $values = array_column($rows, 'value');
        $this->orm->recordColumnRead($this->ownerOf($ref), $ref->column, $values);

        return $values;
    }

    /** The sum of a column over the matching rows: 0 for none. */
    #[\NoDiscard]
    public function sum(string|\BackedEnum $column): int|float
    {
        $sum = $this->aggregate('SUM', $column);

        return match (true) {
            $sum === null => 0,
            \is_int($sum), \is_float($sum) => $sum,
            \is_string($sum) && preg_match('/^-?[0-9]+\z/', $sum) === 1 => (int) $sum,
            is_numeric($sum) => (float) $sum,
            default => throw new \UnexpectedValueException("SUM over '{$this->meta->table}' did not answer a number."),
        };
    }

    /** The smallest value of a column over the matching rows, as the database returns it; null for none. */
    #[\NoDiscard]
    public function min(string|\BackedEnum $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    /** The largest value of a column over the matching rows, as the database returns it; null for none. */
    #[\NoDiscard]
    public function max(string|\BackedEnum $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }

    /**
     * One page of the rows, by number, with the total: two statements, a
     * count and the page. A page past the last is empty.
     *
     * The page number is a position in what the reader can see, so it
     * carries nothing to trust and needs no signing (ADR 0057).
     *
     * @return Page<T>
     */
    #[\NoDiscard]
    public function paginate(int $page, int $perPage): Page
    {
        self::assertPerPage($perPage);
        if ($page < 1) {
            throw new \InvalidArgumentException('Pages count from 1.');
        }
        $this->assertUnlimited('paginate()');
        // Before the count, which reads what withCount() asks for as well,
        // so the refusal names this rather than the fetch() after it.
        $this->assertModelsRead('paginate()');

        $total = $this->count();

        // A page number from a URL can be large; one whose offset would not
        // fit in an integer is past the end of any table there is.
        if ($page - 1 > intdiv(\PHP_INT_MAX, $perPage) || ($page - 1) * $perPage >= $total) {
            return new Page([], $page, $perPage, $total);
        }

        return new Page($this->limit($perPage)->offset(($page - 1) * $perPage)->fetch(), $page, $perPage, $total);
    }

    /**
     * One page of the rows after the row $cursor names (or before it), with
     * cursors for the pages either side: keyset pagination, which reads
     * from where the reader is however many rows were added before it.
     *
     * A cursor names its row by key and is sealed by $cursors -- signed,
     * bound to the reader and to this query, tenant predicate included --
     * so one from another session, user, tenant, build or query is refused
     * (InvalidCursor). The row's sort values are read again here, under the
     * reader's scope, never taken from the cursor (ADR 0057).
     *
     * Sorted by the query's orders, then by the key, so the order is total.
     * Each sort column has to be one that is never NULL -- a NULL has no
     * place either side of a keyset -- so a nullable column, or one reached
     * through a join, is refused.
     *
     * @return CursorPage<T>
     * @throws InvalidCursor for a cursor that does not open, or names a row no longer read
     */
    #[\NoDiscard]
    public function cursorPaginate(int $perPage, ?string $cursor, Cursors $cursors): CursorPage
    {
        self::assertPerPage($perPage);
        $this->assertUnlimited('cursorPaginate()');
        $this->assertModelsRead('cursorPaginate()');
        if ($this->distinctUnionAll()) {
            return $this->asUnion()->cursorPaginate($perPage, $cursor, $cursors);
        }

        $orders = [];
        $hasKey = false;
        foreach ($this->orders as [$column, $direction, $nulls]) {
            if (!$column instanceof ColumnRef) {
                throw new \LogicException('cursorPaginate() sorts by the model\'s own columns: an aggregate is a value no row holds to find its place by.');
            }
            $orders[] = [$column, $direction, $nulls];
            if ($column->path !== []) {
                throw new \LogicException('cursorPaginate() sorts by the model\'s own columns: a joined column is NULL where nothing joins.');
            }
            if ($this->nullable($column->column)) {
                throw new \LogicException("cursorPaginate() cannot sort by '{$column->column}', which may be NULL.");
            }
            $hasKey = $hasKey || $column->column === $this->meta->idColumn;
        }
        if (!$hasKey) {
            $last = $orders === [] ? Direction::Asc : $orders[\count($orders) - 1][1];
            $orders[] = [new ColumnRef([], $this->meta->idColumn), $last, null];
        }

        // A unionAll() may read one row twice, from two arms: the key alone
        // would be two places in the order at once. Each arm reads its own
        // position beside the row, sorted by last, and a cursor names the
        // row by key and arm -- the arm in what the cursor is sealed for,
        // so it is as signed as the key (ADR 0123).
        $armed = $this->unionAll !== null;
        $scope = $armed
            ? (clone($this, ['orders' => $orders]))->readScope(new Compiler($this->orm), armed: true)
            : new Compiler($this->orm)->scope($this->meta, $this->conditions, $orders);
        [$body, $bindings] = $scope->body();
        $fingerprint = $this->class . "\n" . $body . $scope->orderBy() . "\n" . serialize($bindings);
        $sealedFor = static fn (int $arm): string => $armed ? "{$fingerprint}\narm:{$arm}" : $fingerprint;

        $after = true;
        $keyset = '';
        $keysetBindings = [];
        if ($cursor !== null && $cursor !== '') {
            $opened = null;
            $arm = 0;
            foreach ($armed ? array_keys($this->unionAll ?? []) : [0] as $arm) {
                $opened = $cursors->open($sealedFor($arm), $cursor);
                if ($opened !== null) {
                    break;
                }
            }
            [$anchor, $after] = $opened ?? throw InvalidCursor::unopened();
            $values = $this->anchorValues($orders, $anchor);
            if ($armed) {
                $values[] = $arm;
            }
            [$keyset, $keysetBindings] = self::keyset($scope, $values, $after);
        }

        $armColumn = Identifiers::quote(self::ARM);
        [$sql, $selectBindings] = $this->select(
            $scope,
            $keyset,
            $keysetBindings,
            reversed: !$after,
            limit: $perPage + 1,
            list: $armed ? ', ' . Identifiers::quote(self::UNION) . ".{$armColumn} AS {$armColumn}" : '',
        );
        $rows = $this->orm->statements()->select($sql, $selectBindings);
        $arms = [];
        foreach ($rows as $r => $row) {
            $arms[$r] = (int) ($row[self::ARM] ?? 0);
            unset($rows[$r][self::ARM]);
        }
        $models = $this->hydrate($rows);

        $more = \count($models) > $perPage;
        $models = \array_slice($models, 0, $perPage);
        $arms = \array_slice($arms, 0, $perPage);
        if (!$after) {
            $models = array_reverse($models);
            $arms = array_reverse($arms);
        }

        $first = $models === [] ? null : 0;
        $last = $models === [] ? null : \count($models) - 1;
        $seal = static fn (?int $at, bool $forward): ?string => $at === null
            ? null
            : $cursors->seal($sealedFor($arms[$at]), $models[$at]->primaryKey(), $forward);

        // The cursor the page was read at, only once it has opened: the
        // caller's own string handed back, never one this did not verify.
        $at = $keyset === '' ? null : $cursor;

        return $after
            ? new CursorPage($models, $perPage, $more ? $seal($last, true) : null, $keyset === '' ? null : $seal($first, false), $at)
            : new CursorPage($models, $perPage, $seal($last, true), $more ? $seal($first, false) : null, $at);
    }

    /** Whether this is a unionAll() that distinct() asked to read each row of once. */
    private function distinctUnionAll(): bool
    {
        return $this->distinct && $this->unionAll !== null;
    }

    /**
     * A distinct unionAll() as the union() of its arms -- each row once,
     * by key -- with its order, limit and offset (ADR 0123).
     *
     * @return static
     */
    private function asUnion(): self
    {
        return clone($this, ['conditions' => [Condition::union(false, $this->unionAll ?? [])], 'unionAll' => null, 'distinct' => false]);
    }

    /**
     * The statement fetch() would run, compiled under the scope in force
     * now -- for a test, or a log. Throws as fetch() would for a read with
     * no tenant.
     *
     * @return array{string, list<mixed>}
     */
    #[\NoDiscard]
    public function toSql(): array
    {
        $this->assertModelsRead('toSql()', aggregates: true);
        if ($this->distinctUnionAll()) {
            return $this->asUnion()->toSql();
        }

        return $this->select($this->readScope(new Compiler($this->orm)), limit: $this->limit, offset: $this->offset);
    }

    // ------------------------------------------------------------- writing

    /**
     * Sets $values on every row the query matches -- within its order and
     * limit, if it has them -- and answers how many rows the database
     * counted.
     *
     * Held to what a read of the same query is held to: the rows are the
     * ones fetch() would return, under the tenant predicate in force when
     * it runs, failing closed with NoTenantContext where fetch() would; and
     * the UPDATE names them by key with the predicate again, so nothing
     * outside it is written whatever happens between. The fields are the
     * model's own columns, resolved against its metadata and stored as
     * save() stores them (Builder\Fields): never the key, the tenant column
     * or a property the model alone sets.
     *
     * Refused, like save(), inside a loader or query body. Refused, too, on
     * a model with #[BeforeSave] methods: they keep what a model must be
     * when it is saved, and a statement reads no row to run them on -- a
     * model made of the fields set would show them a row that is not the
     * one stored, and `$this->shippedAt ??= now()` would overwrite a date
     * the row holds -- so update() would write past them. Save such rows
     * one at a time (ADR 0073). So, too, on a model with #[AfterSave]
     * methods, which run on a model of each row saved (ADR 0122).
     *
     * Two statements, in one transaction: the matching keys, locked where
     * the database can (FOR UPDATE on Postgres and MySQL; SQLite's write
     * transaction holds the whole database), then the UPDATE -- one per
     * MAX_VALUES rows. A model the identity map holds for a written row is
     * read again and takes what was stored; loaded relations follow, as
     * after save() (Orm::followStatementWrite()).
     *
     * A field's value may be a Change -- `['stock' => Change::add(-1)->atLeast(0)]`,
     * a multiple, a copy of another of the row's columns; an Increment, or
     * increment() and decrement(), is an addition -- which works out what the
     * row holds when it is written, `stock = stock + ?`, rather than from
     * what a read of it held before: a closed set of expressions, each a
     * value and never text (ADR 0064, ADR 0073). Its rows are read again
     * afterwards, every one, so the ORM follows what was stored.
     *
     * @param array<string, mixed> $values field => value: a property, a column, or a #[BelongsTo] (a model)
     */
    public function update(array $values): int
    {
        if ($this->unionAll !== null) {
            return $this->rowsToWrite('update()')->update($values);
        }
        $this->orm->refuseWriteOf('update()');
        $this->refuseHooks('update()');
        $this->assertRowsWritten('update()');
        $this->assertOffsetLimited();

        // Every row it reaches takes updatedAt, as save() stamps it, unless
        // $values sets it.
        $set = Fields::row($this->meta, $values, inserting: false, now: $this->orm->now());
        if ($set === []) {
            throw new \InvalidArgumentException('update() sets at least one field.');
        }

        return $this->orm->transaction(function () use ($set): int {
            $ids = $this->idsToWrite();
            $compiler = new Compiler($this->orm);
            $assignments = [];
            $values = [];
            foreach ($set as $column => $value) {
                $quoted = Identifiers::quote($column);
                if ($value instanceof Assignment) {
                    [$sql, $bindings] = $value->sql($column, $compiler);
                    $assignments[] = "{$quoted} = {$sql}";
                    array_push($values, ...$bindings);
                } else {
                    $assignments[] = "{$quoted} = ?";
                    $values[] = $value;
                }
            }
            $assignments = implode(', ', $assignments);

            $count = 0;
            foreach (array_chunk($ids, self::MAX_VALUES) as $chunk) {
                [$where, $bindings] = $this->byKeys($chunk);
                $count += $this->orm->statements()->execute(
                    'UPDATE ' . Identifiers::quote($this->meta->table) . " SET {$assignments} WHERE {$where}",
                    [...$values, ...$bindings],
                );
            }

            $this->orm->noteRowTenants($this->meta, $ids);
            $this->followUpdate($ids, $set);

            return $count;
        });
    }

    /**
     * Deletes every row the query matches -- within its order and limit,
     * if it has them -- and answers how many the database counted.
     *
     * Held as update() is: the rows fetch() would return, under the tenant
     * predicate in force, the DELETE naming them by key with the predicate
     * again; refused in a loader or query body. A model the identity map
     * holds for one reads as unsaved afterwards, as after delete(), and
     * leaves every loaded relation it was in.
     *
     * Refused on a model with #[BeforeDelete] or #[AfterDelete] methods: a
     * statement reads no model to run them on (ADR 0122).
     */
    public function delete(): int
    {
        if ($this->unionAll !== null) {
            return $this->rowsToWrite('delete()')->delete();
        }
        $this->orm->refuseWriteOf('delete()');
        $this->refuseDeleteHooks();
        $this->assertRowsWritten('delete()');
        $this->assertOffsetLimited();

        return $this->orm->transaction(function (): int {
            $ids = $this->idsToWrite();
            // Read while the rows are there to read.
            $this->orm->noteRowTenants($this->meta, $ids);

            $count = 0;
            foreach (array_chunk($ids, self::MAX_VALUES) as $chunk) {
                [$where, $bindings] = $this->byKeys($chunk);
                $count += $this->orm->statements()->execute('DELETE FROM ' . Identifiers::quote($this->meta->table) . " WHERE {$where}", $bindings);
            }

            foreach ($ids as $id) {
                $this->orm->followStatementWrite($this->meta, $id, null, []);
            }

            return $count;
        });
    }

    /**
     * Adds $by to $column on every row the query matches, as the database
     * holds it when the row is written -- `stock = stock + ?` -- and sets
     * $also's fields beside it, as update() sets them; answers how many
     * rows the database counted. Held to what update() is held to.
     *
     * @param array<string, mixed> $also further fields to set, as update() takes them
     */
    public function increment(string|\BackedEnum $column, int|float $by = 1, array $also = []): int
    {
        if ($by <= 0) {
            throw new \InvalidArgumentException('increment() adds a number above zero; decrement() takes one away.');
        }

        return $this->update([...$also, self::fieldName($column) => Increment::by($by)]);
    }

    /**
     * increment()'s converse: takes $by from $column on every row the
     * query matches. Nothing stops a count going below zero but the
     * query's own conditions -- `where('stock', '>=', $by)` -- or the
     * table's CHECK.
     *
     * @param array<string, mixed> $also
     */
    public function decrement(string|\BackedEnum $column, int|float $by = 1, array $also = []): int
    {
        if ($by <= 0) {
            throw new \InvalidArgumentException('decrement() takes away a number above zero; increment() adds one.');
        }

        return $this->update([...$also, self::fieldName($column) => Increment::by(-$by)]);
    }

    /**
     * Inserts $rows, each a map of field => value as update() takes, in as
     * few statements as the database's binding limit allows; answers how
     * many. Each is stamped with the tenant in force, as save() stamps one
     * -- NoTenantContext with none -- and a row naming another tenant is
     * refused (ForeignTenantRow). A row without a key is given a UUIDv7.
     *
     * Called on the query a model starts, unnarrowed: an INSERT reads
     * nothing, and a condition on one would be one it ignored.
     *
     * A model's #[BeforeSave] methods run on each row, as save() runs them
     * on a new model: on a model made from the row's fields, its relations
     * the models given, before the key is minted; what the model holds
     * after them -- a column a hook derived or changed -- is what is
     * inserted, and the tenant is stamped after them whatever a hook did.
     * A hook that reads a field the row does not set throws, as it would
     * on a model missing it, and nothing is written (ADR 0073).
     *
     * Its #[AfterSave] methods run too, once every row is in: on each row
     * read back as a model, in the order given, in the insert's
     * transaction, so a hook that throws takes every row back (ADR 0122).
     * One statement more, per MAX_VALUES rows, for a model that has them.
     *
     * @param list<array<string, mixed>> $rows each naming the same fields
     */
    public function insert(array $rows): int
    {
        return $this->writeRows('insert()', $rows, null, null);
    }

    /**
     * Inserts $rows, and where one shares a #[Unique] key with a row there
     * already, updates that row instead: its $update fields -- every field
     * the rows set but the key's, by default; none to leave it as it was.
     * Answers how many rows it inserted. A createdAt the ORM stamps is a
     * new row's alone, and an updatedAt it stamps moves with each row
     * updated, as save() treats them.
     *
     * The conflict target is the metadata's: $uniqueBy has to be the
     * columns of a key the model declares with #[Unique], in any order --
     * a key it does not declare is refused before anything is sent. On a
     * #[TenantScoped] model the tenant column leads the target, so a row
     * conflicts with its own tenant's rows alone and never updates
     * another's; every row is stamped with the tenant in force, and one
     * naming another is refused, as insert() refuses it.
     *
     * In one transaction: the INSERTs, then the rows they wrote, read by
     * their keys under the tenant predicate, so a model the identity map
     * holds for an updated row takes what was stored, and loaded relations
     * follow. Two rows with one key are refused: Postgres refuses to update
     * a row twice in one statement, and the others would keep the last.
     *
     * An $update field keyed to a Change -- `['hits' => Change::add(1)]`,
     * a multiple, a copy, bounded or not -- is worked out by the database
     * from the row there, as update() works one out; the rows need not set
     * it, and a copy is never from a column the upsert sets (ADR 0123).
     *
     * MySQL has no conflict target: ON DUPLICATE KEY UPDATE answers to any
     * unique index the table has. The target is still checked against the
     * metadata; and on a #[TenantScoped] model the update is guarded, so a
     * collision with any row but the tenant's own by the key -- another
     * tenant's, through an index the tenant column does not lead or a
     * primary key a row brings -- is an error, and nothing is written: one
     * statement still (onConflict(), ADR 0123; ADR 0073 made it three).
     *
     * @param list<array<string, mixed>> $rows each naming the same fields
     * @param string|list<string> $uniqueBy the key's fields
     * @param array<array-key, string|Change|Increment>|null $update the fields an existing row takes from its new
     *        one, listed; or keyed to a Change it works out from what the row holds
     */
    public function upsert(array $rows, string|array $uniqueBy, ?array $update = null): int
    {
        return $this->writeRows('upsert()', $rows, \is_string($uniqueBy) ? [$uniqueBy] : $uniqueBy, $update);
    }

    // ------------------------------------------------------------- internals

    /**
     * insert() and upsert().
     *
     * @param array<mixed> $rows
     * @param list<string>|null $uniqueBy null for a plain insert
     * @param array<array-key, string|Change|Increment>|null $update
     */
    private function writeRows(string $what, array $rows, ?array $uniqueBy, ?array $update): int
    {
        $this->orm->refuseWriteOf($what);
        // insert() runs a model's #[BeforeSave] methods on each new row, a
        // whole row as save() would see it; upsert() refuses them, since a
        // row it updates is one it never read (ADR 0073).
        if ($uniqueBy !== null) {
            $this->refuseHooks($what);
        }
        if ($this->conditions !== [] || $this->orders !== [] || $this->limit !== null || $this->offset !== null || $this->with !== []
            || $this->groups !== [] || $this->havings !== [] || $this->distinct || $this->measures !== [] || $this->unionAll !== null) {
            throw new \LogicException("{$what} reads nothing: call it on the query a model starts, not on one narrowed.");
        }
        if ($rows === []) {
            return 0;
        }
        if (\count($rows) > self::MAX_VALUES) {
            throw new \LengthException("{$what} writes at most " . self::MAX_VALUES . ' rows at a time.');
        }

        $tenant = $this->tenantForNewRows();
        $now = $this->orm->now();
        $stored = [];
        $columns = null;
        $preset = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                throw new \InvalidArgumentException("{$what} takes rows, each a map of field => value.");
            }
            $one = Fields::row($this->meta, $row, inserting: true, tenant: $tenant, hooks: $uniqueBy === null && $this->meta->hasBeforeSave(), now: $now);
            if (isset($one[$this->meta->idColumn])) {
                $preset[] = (string) $one[$this->meta->idColumn];
            }
            $one[$this->meta->idColumn] ??= Uuid::v7();

            $names = array_keys($one);
            sort($names);
            if ($columns !== null && $names !== $columns) {
                throw new \InvalidArgumentException("Every row {$what} writes names the same fields.");
            }
            $columns ??= $names;
            $stored[] = $one;
        }

        [$target, $set, $changes] = $uniqueBy === null
            ? [null, [], []]
            : $this->conflict($what, $stored, $columns, $uniqueBy, $update, $this->stamped($rows[array_key_first($rows)]));

        return $this->orm->transaction(function () use ($stored, $columns, $target, $set, $changes, $tenant, $preset): int {
            // A row an upsert brings its key for may be the very row its
            // unique key finds: updated, not inserted, though its key is
            // one written. Which of them were there is asked first.
            $existed = $target === null ? [] : array_flip(array_column($this->rowsByKey($preset), $this->meta->idColumn));

            // A tenant's upsert on MySQL guards the row it finds, by the
            // tenant, the key and the keys this upsert mints (onConflict()).
            $minted = null;
            if ($target !== null && $tenant !== null && $this->onMySql()) {
                $minted = array_values(array_diff(array_map(fn (array $row): string => (string) $row[$this->meta->idColumn], $stored), $preset));
            }
            [$conflict, $conflictBindings] = $target === null ? ['', []] : $this->onConflict($target, $set, $changes, $minted);

            $table = Identifiers::quote($this->meta->table);
            $list = implode(', ', array_map(Identifiers::quote(...), $columns));
            $tuple = '(' . Compiler::placeholders(\count($columns)) . ')';
            $perStatement = max(1, intdiv(max(1, self::MAX_BINDINGS - \count($conflictBindings)), \count($columns)));

            foreach (array_chunk($stored, $perStatement) as $chunk) {
                $bindings = [];
                foreach ($chunk as $row) {
                    foreach ($columns as $column) {
                        $bindings[] = $row[$column];
                    }
                }
                $this->orm->statements()->execute(
                    "INSERT INTO {$table} ({$list}) VALUES " . implode(', ', array_fill(0, \count($chunk), $tuple)) . $conflict,
                    [...$bindings, ...$conflictBindings],
                );
            }

            if ($target !== null) {
                return $this->followUpserted($stored, $columns, $target, $set, $tenant, $existed);
            }
            $inserted = $this->followInserted($stored, $columns);
            if ($this->meta->hasAfterSave()) {
                $this->afterInserted($stored);
            }

            return $inserted;
        });
    }

    /**
     * The tenant a new row is stamped with, failing closed where save()
     * would: no tenancy registered, or no tenant in force -- inside
     * acrossTenants() too, which lifts reads, not whose a new row is.
     */
    private function tenantForNewRows(): ?string
    {
        if ($this->meta->tenantColumn === null) {
            return null;
        }
        if (!$this->orm->hasTenancy()) {
            throw NoTenantContext::noTenancy($this->class);
        }
        $tenant = $this->orm->tenant();
        if ($tenant === '') {
            throw new NoTenantContext($this->class);
        }

        return $tenant;
    }

    /**
     * An upsert's conflict target, from the metadata, and the columns an
     * existing row takes.
     *
     * @param list<array<string, mixed>> $stored
     * @param list<string> $columns
     * @param list<string> $uniqueBy
     * The ORM's stamps are held to what save() does with them: a createdAt
     * it stamped is a new row's, so an existing row keeps its own -- never
     * in the default, and refused by name; an updatedAt it stamped moves
     * with every row the upsert updates, named or not, and with none where
     * it updates nothing.
     *
     * @param array<array-key, mixed>|null $update
     * @param array{created: string|null, updated: string|null} $stamped the columns the ORM stamped, not the rows
     * @return array{non-empty-list<string>, list<string>, array<string, Assignment>}
     */
    private function conflict(string $what, array $stored, array $columns, array $uniqueBy, ?array $update, array $stamped): array
    {
        $key = array_map(fn (string $name): string => $this->ownColumn($name), $uniqueBy);
        $target = $this->meta->conflictTarget($key) ?? throw new MappingException(
            "{$this->class} declares no #[Unique] key over (" . implode(', ', $key) . "), so {$what} has nothing to conflict on. "
            . 'Declared: ' . (implode('; ', array_map(static fn (array $k): string => implode(', ', $k), $this->meta->uniqueKeys)) ?: 'none') . '.',
        );
        foreach ($target as $column) {
            if (!\in_array($column, $columns, true)) {
                throw new \InvalidArgumentException("Every row {$what} writes sets its key, and these leave out {$column}.");
            }
        }

        $seen = [];
        foreach ($stored as $row) {
            $tuple = json_encode(array_map(static fn (string $column): mixed => $row[$column], $target), JSON_THROW_ON_ERROR);
            if (isset($seen[$tuple])) {
                throw new \InvalidArgumentException("Two rows {$what} writes share a key; write each once.");
            }
            $seen[$tuple] = true;
        }

        $fixed = [...$target, $this->meta->idColumn];
        $stamps = array_values(array_filter($stamped, \is_string(...)));
        $changes = [];
        if ($update === null) {
            $set = array_values(array_diff($columns, $fixed, $stamps));
        } else {
            $set = [];
            foreach ($update as $key => $entry) {
                // A field by itself takes the new row's value; one keyed to
                // a Change takes what the database works out from the row
                // there (ADR 0123).
                $name = \is_string($key) ? $key : $entry;
                if (!\is_string($name)) {
                    throw new \InvalidArgumentException("{$what} updates fields, each named, or named and given a Change.");
                }
                if (\is_string($key) && !$entry instanceof Change && !$entry instanceof Increment) {
                    throw new \InvalidArgumentException("{$what} updates '{$name}' with the new row's value, or with a Change: list it, or give it one.");
                }
                $column = $this->ownColumn($name);
                if (\in_array($column, $fixed, true) || $column === $this->meta->tenantColumn) {
                    throw new \InvalidArgumentException("{$what} updates no key: '{$name}' is what the row is found by.");
                }
                if (\in_array($column, $set, true)) {
                    throw new \InvalidArgumentException("{$what} updates '{$name}' once.");
                }
                if (\is_string($key)) {
                    $assignment = Fields::row($this->meta, [$name => $entry], inserting: false)[$column] ?? null;
                    if (!$assignment instanceof Assignment) {
                        throw new \LogicException("'{$name}' resolved to no change of its own column.");
                    }
                    $changes[$column] = $assignment;
                } elseif (!\in_array($column, $columns, true)) {
                    throw new \InvalidArgumentException("{$what} updates '{$name}', which the rows do not set.");
                }
                if ($column === $stamped['created']) {
                    throw new \InvalidArgumentException("{$what} updates no createdAt the ORM stamped: '{$name}' is a new row's, and an existing row keeps its own.");
                }
                $set[] = $column;
            }
        }

        // A copy reads the row as it is written, and MySQL works the
        // assignments out left to right, each seeing those before it, where
        // Postgres and SQLite read each from the row as it was: a copy from
        // a column the same upsert sets would store one value on MySQL and
        // another elsewhere, so it is refused, as update() refuses it.
        foreach ($changes as $column => $assignment) {
            if ($assignment->source !== null && \in_array($assignment->source, $set, true)) {
                throw new \InvalidArgumentException("{$what} copies '{$assignment->source}' into {$column}, and sets '{$assignment->source}' too: copy it in an update() of its own.");
            }
        }

        if ($set !== [] && $stamped['updated'] !== null && !\in_array($stamped['updated'], $set, true)) {
            $set[] = $stamped['updated'];
        }

        return [$target, $set, $changes];
    }

    /**
     * The timestamp columns Fields::row() stamps on the rows of an insert()
     * or upsert() -- a createdAt and an updatedAt typed as moments -- that
     * the rows do not name themselves. Every row names the same fields, so
     * the first says it for all.
     *
     * @param mixed $row the first row, an array writeRows() has checked
     * @return array{created: string|null, updated: string|null}
     */
    private function stamped(mixed $row): array
    {
        $named = [];
        foreach (\is_array($row) ? array_keys($row) : [] as $name) {
            $named[] = $this->ownColumn((string) $name);
        }
        $column = fn (?string $property): ?string => $property === null || \in_array($this->meta->columns[$property], $named, true)
            ? null
            : $this->meta->columns[$property];

        return ['created' => $column($this->meta->createdAtProperty), 'updated' => $column($this->meta->updatedAtProperty)];
    }

    /** A column of the model's own table, by any name it is known by. */
    private function ownColumn(string $name): string
    {
        $ref = Identifiers::column($this->meta, $name);
        if ($ref->path !== []) {
            throw new MappingException("'{$name}' is a column of another table; a write sets {$this->class}'s own.");
        }

        return $ref->column;
    }

    /**
     * ON CONFLICT, as each database spells it, and what it binds: a column
     * an existing row takes from its new one, or works out with a Change
     * from what it holds, its columns named by the table's name.
     *
     * A #[TenantScoped] model's on MySQL ($minted not null) is guarded.
     * ON DUPLICATE KEY UPDATE answers to whichever unique index a new row
     * collides with first, whatever the statement names: the primary key
     * a row brings, or an index the tenant column does not lead -- an
     * email unique across every tenant -- would have it update another
     * tenant's row. So its first assignment checks the row it found, the
     * existing row as the database holds it: the tenant's, by the target's
     * key as the database compares it -- its collation decides what is one
     * key, as its unique index does -- and not a row this upsert inserted
     * itself, which is two new rows the collation takes for one key, and
     * refused as Postgres refuses to update a row twice. Any other row is an
     * error that rolls the statement back before it changes anything: a
     * scalar subquery answering two rows, an error under every SQL mode on
     * MySQL and on MariaDB alike, and made of the row's key so no server can
     * work it out before the branch is taken. Not a NULL into a NOT NULL
     * column, which a server outside strict mode turns into a warning and a
     * write; nor invalid JSON, which MariaDB answers with a warning and a
     * NULL (ADR 0123). Postgres and SQLite name their conflict target,
     * so a collision on any other index is an error there already.
     *
     * @param non-empty-list<string> $target
     * @param list<string> $set
     * @param array<string, Assignment> $changes by column, those $set works out from the row there
     * @param list<string>|null $minted the keys this upsert mints, for a tenant's on MySQL; null for any other
     * @return array{string, list<int|float|string>}
     */
    private function onConflict(array $target, array $set, array $changes, ?array $minted): array
    {
        $compiler = new Compiler($this->orm);
        $table = $this->meta->table;
        $existing = Identifiers::quote($table) . '.';
        $bindings = [];
        $assign = static function (string $column, string $incoming) use ($changes, $compiler, $table, &$bindings): string {
            if (!isset($changes[$column])) {
                return Identifiers::quote($column) . " = {$incoming}";
            }
            [$sql, $values] = $changes[$column]->sql($column, $compiler, $table);
            array_push($bindings, ...$values);

            return Identifiers::quote($column) . " = {$sql}";
        };

        if ($this->onMySql()) {
            $terms = [];
            if ($minted !== null) {
                $tenant = Identifiers::quote($this->meta->tenantColumn ?? throw new \LogicException("{$this->class} has no tenant column."));
                $id = Identifiers::quote($this->meta->idColumn);
                $same = ["{$existing}{$tenant} = VALUES({$tenant})"];
                foreach ($target as $column) {
                    if ($column !== $this->meta->tenantColumn) {
                        $same[] = $existing . Identifiers::quote($column) . ' = VALUES(' . Identifiers::quote($column) . ')';
                    }
                }
                if ($minted !== []) {
                    $same[] = "{$existing}{$id} NOT IN (" . Compiler::placeholders(\count($minted)) . ')';
                    array_push($bindings, ...$minted);
                }
                $terms[] = "{$tenant} = IF(" . implode(' AND ', $same) . ", {$existing}{$tenant}, "
                    . "(SELECT {$existing}{$id} UNION ALL SELECT {$existing}{$id}))";
            }
            foreach ($set as $column) {
                $terms[] = $assign($column, 'VALUES(' . Identifiers::quote($column) . ')');
            }
            if ($terms === []) {
                $id = Identifiers::quote($this->meta->idColumn);
                $terms[] = "{$id} = {$id}";
            }

            return [' ON DUPLICATE KEY UPDATE ' . implode(', ', $terms), $bindings];
        }

        $clause = ' ON CONFLICT (' . implode(', ', array_map(Identifiers::quote(...), $target)) . ')';
        if ($set === []) {
            return [$clause . ' DO NOTHING', []];
        }
        $terms = [];
        foreach ($set as $column) {
            $terms[] = $assign($column, 'excluded.' . Identifiers::quote($column));
        }

        return [$clause . ' DO UPDATE SET ' . implode(', ', $terms), $bindings];
    }

    private function onMySql(): bool
    {
        $connection = $this->orm->statements();

        return $connection instanceof NamesDriver && $connection->driver() === 'mysql';
    }

    /**
     * After insert(): each new row followed into the relations it is in.
     *
     * @param list<array<string, mixed>> $stored
     * @param list<string> $columns
     */
    private function followInserted(array $stored, array $columns): int
    {
        foreach ($stored as $row) {
            $this->orm->followStatementWrite($this->meta, (string) $row[$this->meta->idColumn], $row, $columns);
        }

        return \count($stored);
    }

    /**
     * After upsert(): the rows it wrote, read by their keys under the
     * tenant predicate -- the database's own comparison, so a key it took
     * for one whatever its case is found -- each followed as inserted, if
     * its key is one written, or as updated.
     *
     * @param list<array<string, mixed>> $stored
     * @param list<string> $columns
     * @param non-empty-list<string> $target
     * @param list<string> $set
     * @param array<array-key, int> $existed the keys rows brought that were there already: updated, never inserted
     */
    private function followUpserted(array $stored, array $columns, array $target, array $set, ?string $tenant, array $existed): int
    {
        $byId = [];
        foreach ($stored as $row) {
            $byId[(string) $row[$this->meta->idColumn]] = $row;
        }

        $key = array_values(array_diff($target, [$this->meta->tenantColumn]));
        $table = Identifiers::quote($this->meta->table);
        $select = implode(', ', array_map(
            static fn (string $column): string => $table . '.' . Identifiers::quote($column) . ' AS ' . Identifiers::quote($column),
            $this->meta->selectColumns(),
        ));

        $inserted = 0;
        $found = [];
        foreach (array_chunk($stored, max(1, intdiv(self::MAX_VALUES, \count($key)))) as $chunk) {
            $alternatives = [];
            $bindings = [];
            foreach ($chunk as $row) {
                $alternatives[] = '(' . implode(' AND ', array_map(static fn (string $c): string => $table . '.' . Identifiers::quote($c) . ' = ?', $key)) . ')';
                foreach ($key as $column) {
                    $bindings[] = $row[$column];
                }
            }
            // The tenant every row was stamped with, which leads the target:
            // inside acrossTenants() too, where a read's predicate is
            // lifted, the rows written are that tenant's alone.
            $where = '(' . implode(' OR ', $alternatives) . ')';
            if ($tenant !== null && $this->meta->tenantColumn !== null) {
                $where .= " AND {$table}." . Identifiers::quote($this->meta->tenantColumn) . ' = ?';
                $bindings[] = $tenant;
            }

            foreach ($this->orm->statements()->select("SELECT {$select} FROM {$table} WHERE {$where}", $bindings) as $row) {
                $id = (string) $row[$this->meta->idColumn];
                if (isset($found[$id])) {
                    continue;   // found again by a row of another chunk: see below
                }
                $found[$id] = true;
                if (isset($byId[$id]) && !isset($existed[$id])) {
                    $inserted++;
                    $this->orm->followStatementWrite($this->meta, $id, $byId[$id], $columns);
                } elseif ($set !== []) {
                    $this->orm->followStatementWrite($this->meta, $id, $row, $set);
                }
            }
        }

        // On MySQL, two new rows its collation takes for one key ('alpha',
        // 'ALPHA') are written to one row, the second updating what the
        // first wrote: found here as fewer rows than were written, each key
        // naming one row of the tenant's. Refused, inside the upsert's
        // transaction, so neither is kept -- as two rows with one key are,
        // and as Postgres refuses to update one row twice (ADR 0073). A key
        // holding a NULL conflicts with nothing and is found by nothing.
        if ($this->onMySql()) {
            $keyed = array_filter($stored, static fn (array $row): bool => array_filter($key, static fn (string $c): bool => $row[$c] === null) === []);
            if (\count($found) < \count($keyed)) {
                throw new \InvalidArgumentException('Two rows upsert() writes share a key as the database compares it; write each once.');
            }
        }

        return $inserted;
    }

    /**
     * Refuses a write through a statement on a model whose saves run
     * #[BeforeSave] or #[AfterSave] methods: it writes rows it never read,
     * whole, so it has no model to run them on (ADR 0073, ADR 0122).
     */
    private function refuseHooks(string $what): void
    {
        if ($this->meta->hasBeforeSave()) {
            throw new \LogicException(
                "{$this->class} has #[BeforeSave] methods, which keep what a model must be when it is saved; {$what} "
                . 'writes rows it never read, whole, to run them on, and would write past them. Save each row, or insert() new ones.',
            );
        }
        if ($this->meta->hasAfterSave()) {
            throw new \LogicException(
                "{$this->class} has #[AfterSave] methods, which run on each row saved; {$what} "
                . 'writes rows it never read, whole, to run them on, and would write past them. Save each row, or insert() new ones.',
            );
        }
    }

    /**
     * Refuses a delete through a statement on a model whose deletes run
     * #[BeforeDelete] or #[AfterDelete] methods: it deletes rows it never
     * read, so it has no model to run them on (ADR 0122).
     */
    private function refuseDeleteHooks(): void
    {
        if ($this->meta->hasDeleteHooks()) {
            throw new \LogicException(
                "{$this->class} has #[BeforeDelete] or #[AfterDelete] methods, which run on each row deleted; delete() through a query "
                . 'deletes rows it never read to run them on, and would delete past them. Delete each model with Orm::delete().',
            );
        }
    }

    /**
     * After insert(), on a model with #[AfterSave] methods: each row read
     * back as a model, by the keys just written, and its hooks run in the
     * order the rows were given -- what save() runs once a new row is in,
     * inside the same transaction, so a hook that throws takes every row
     * back (ADR 0122).
     *
     * @param list<array<string, mixed>> $stored
     */
    private function afterInserted(array $stored): void
    {
        $byKey = [];
        foreach (array_chunk(array_map(fn (array $row): string => (string) $row[$this->meta->idColumn], $stored), self::MAX_VALUES) as $chunk) {
            foreach (self::of($this->orm, $this->class)->whereIn($this->meta->idProperty, $chunk)->fetch() as $model) {
                $byKey[$model->primaryKey()] = $model;
            }
        }
        foreach ($stored as $row) {
            $model = $byKey[(string) $row[$this->meta->idColumn]]
                ?? throw new \LogicException("{$this->class}: a row insert() wrote could not be read back to run its #[AfterSave] methods on.");
            $this->meta->afterSave($model);
        }
    }

    /**
     * The keys of the rows a write through this query writes: what fetch()
     * would read, locked for the rest of the transaction where the
     * database can lock rows.
     *
     * @return list<string>
     */
    private function idsToWrite(): array
    {
        $scope = new Compiler($this->orm)->scope($this->meta, $this->conditions, $this->orders);
        $id = $scope->expression(new ColumnRef([], $this->meta->idColumn));
        [$body, $bindings] = $scope->body();
        [$tail, $tailBindings] = $this->tail($this->limit, $this->offset);

        // The model's own rows are locked alone. Postgres would refuse a
        // joined table's on the side of an outer join that may be NULL;
        // MySQL would lock every joined row it looked at -- another
        // tenant's aisle a shelf of this tenant's names, whose own writes
        // would then wait on this one. SQLite has no row locks, and needs
        // none: its write transaction began IMMEDIATE, holding the database.
        $connection = $this->orm->statements();
        $lock = match ($connection instanceof NamesDriver ? $connection->driver() : '') {
            'pgsql', 'mysql' => ' FOR UPDATE OF ' . Identifiers::quote($scope->alias),
            default => '',
        };

        $rows = $connection->select(
            "SELECT {$id} AS \"id\"" . $body . $scope->orderBy() . $tail . $lock,
            [...$bindings, ...$scope->orderBindings(), ...$tailBindings],
        );

        return array_values(array_unique(array_map(static fn (array $row): string => (string) $row['id'], $rows)));
    }

    /**
     * WHERE for a write to these rows of the model's table, by key, with
     * the tenant predicate in force again.
     *
     * @param list<string> $ids
     * @return array{string, list<string>}
     */
    private function byKeys(array $ids): array
    {
        $table = Identifiers::quote($this->meta->table);
        $where = $table . '.' . Identifiers::quote($this->meta->idColumn) . ' IN (' . Compiler::placeholders(\count($ids)) . ')';
        $bindings = $ids;

        $tenant = new Compiler($this->orm)->tenantOnTable($this->meta);
        if ($tenant !== null) {
            $where .= ' AND ' . $tenant[0];
            array_push($bindings, ...$tenant[1]);
        }

        return [$where, $bindings];
    }

    /**
     * After update(): each written row followed, a held model's read again
     * so it takes what was stored.
     *
     * Every row, where a Change was written: what it worked out is known
     * only to the database, and a relation's cached row must not be patched
     * with the Change itself.
     *
     * @param list<string> $ids
     * @param array<string, mixed> $set column => value as written
     */
    private function followUpdate(array $ids, array $set): void
    {
        $added = array_filter($set, static fn (mixed $value): bool => $value instanceof Assignment) !== [];
        $read = $added ? $ids : array_values(array_filter($ids, fn (string $id): bool => $this->orm->holdsModel($this->class, $id)));
        $rows = [];
        foreach (array_chunk($read, self::MAX_VALUES) as $chunk) {
            foreach ($this->rowsByKey($chunk) as $row) {
                $rows[(string) $row[$this->meta->idColumn]] = $row;
            }
        }

        foreach ($ids as $id) {
            $this->orm->followStatementWrite($this->meta, $id, $rows[$id] ?? $set, array_keys($set));
        }
    }

    /**
     * These rows of the model's table, every column a model is read with.
     *
     * @param list<string> $ids
     * @return list<array<string, mixed>>
     */
    private function rowsByKey(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        [$where, $bindings] = $this->byKeys($ids);
        $table = Identifiers::quote($this->meta->table);
        $columns = implode(', ', array_map(
            static fn (string $column): string => $table . '.' . Identifiers::quote($column) . ' AS ' . Identifiers::quote($column),
            $this->meta->selectColumns(),
        ));

        return $this->orm->statements()->select("SELECT {$columns} FROM {$table} WHERE {$where}", $bindings);
    }

    /** @return static */
    private function adding(Condition $condition): self
    {
        if ($this->unionAll !== null) {
            throw new \LogicException('A condition after unionAll() would be no arm\'s: narrow each arm before joining them.');
        }

        return clone($this, ['conditions' => [...$this->conditions, $condition]]);
    }

    /**
     * A filter on a column reached through a #[HasMany] -- 'items.name' --
     * as whereHas() over the relation path with $narrow's condition on the
     * related model's column: an EXISTS, never a join, so no row is
     * repeated for each child that matches, and the related rows are read
     * under their own tenant predicate. Null for any other column, which
     * the caller resolves as it always has.
     *
     * @param string|\BackedEnum|(\Closure(self<T>): self<T>) $column
     * @param \Closure(self<Model>, string): self<Model> $narrow
     * @return static|null
     */
    private function throughCollection(bool $or, string|\BackedEnum|\Closure $column, \Closure $narrow): ?self
    {
        if ($column instanceof \Closure) {
            return null;
        }
        $found = Identifiers::collectionColumn($this->meta, $column);
        if ($found === null) {
            return null;
        }
        [$path, $target, $name] = $found;

        return $this->adding($this->hasConditions($or, $path, $narrow(self::of($this->orm, $target->class), $name)->conditions, false));
    }

    /** An aggregate over this query's rows, its column resolved against the metadata. */
    /** @return static */
    private function keeping(bool $or, Aggregate $function, string|\BackedEnum|null $column, string $operator, mixed $value): self
    {
        $sql = self::OPERATORS[strtolower($operator)] ?? null;
        if ($sql === null || str_contains($sql, 'LIKE')) {
            throw new \InvalidArgumentException('An aggregate compares with =, <>, <, <=, > or >=.');
        }
        if ($value === null) {
            throw new \InvalidArgumentException('An aggregate compared with NULL matches no group; compare with a value.');
        }

        return clone($this, ['havings' => [...$this->havings, [$this->measure($function, $column), $sql, self::value($value), $or]]]);
    }

    /** @return static */
    private function keepingColumn(bool $or, string|\BackedEnum $column, string $operator, mixed $value): self
    {
        $sql = self::OPERATORS[strtolower($operator)] ?? null;
        if ($sql === null) {
            throw new \InvalidArgumentException('A group\'s column compares with ' . implode(', ', array_keys(self::OPERATORS)) . '.');
        }
        if ($value === null) {
            throw new \InvalidArgumentException('A comparison with NULL matches no group in SQL; narrow the rows with whereNull() instead.');
        }

        $ref = Identifiers::column($this->meta, $column);
        // A key groups one row, or none: narrow those with where(), which
        // answers a malformed key without asking the database.
        if ($this->isKey($ref)) {
            throw new \LogicException("havingColumn() compares a group's column, and '{$ref->column}' is a key: narrow the rows with where().");
        }

        return clone($this, ['havings' => [...$this->havings, [$ref, $sql, self::value($value), $or]]]);
    }

    private function measure(Aggregate $function, string|\BackedEnum|null $column): Measure
    {
        if ($column === null) {
            return new Measure($function, null);
        }

        // Over the rows' collections: a column a #[HasMany] reaches
        // ('items.priceCents'), or, counted, the collection itself ('items').
        $collection = Identifiers::collectionColumn($this->meta, $column);
        $path = $collection === null ? null : $collection[0];
        $name = $column instanceof \BackedEnum ? (string) $column->value : $column;
        if ($path === null && $this->isCollection($name)) {
            $path = $name;
        }
        if ($path === null) {
            return new Measure($function, Identifiers::column($this->meta, $column));
        }
        if ($function === Aggregate::CountDistinct) {
            throw new \LogicException('A distinct count over each row\'s collection does not add up across a group: group the related model instead.');
        }
        if ($collection === null && $function !== Aggregate::Count) {
            throw new \LogicException("'{$name}' is a collection: {$function->name} reads one of its columns.");
        }

        $inner = $function === Aggregate::Avg ? Aggregate::Sum : $function;

        return new Measure($function, null, $this->relationMeasure('', $path, $inner, $collection[2] ?? null, null, ''));
    }

    /** Whether $path is a path of relations from this model that walks a #[HasMany]. */
    private function isCollection(string $path): bool
    {
        try {
            $relations = Identifiers::relationPath($this->meta, $path);
        } catch (MappingException) {
            return false;
        }

        return array_filter($relations, static fn (RelationMeta $r): bool => $r->kind === RelationKind::HasMany) !== [];
    }

    /**
     * A report's `[Aggregate::Sum, 'stock']`.
     *
     * @param array<mixed> $spec
     */
    private function measureOf(array $spec): Measure
    {
        if (array_keys($spec) !== [0, 1] || !$spec[0] instanceof Aggregate || !(\is_string($spec[1]) || $spec[1] instanceof \BackedEnum)) {
            throw new \InvalidArgumentException('A report\'s aggregate of a column is [Aggregate::Sum, \'column\'].');
        }

        return $this->measure($spec[0], $spec[1]);
    }

    /**
     * An aggregate over each row's related rows: a #[HasMany] of this
     * model's, the related model's column, and the conditions its rows are
     * narrowed by -- each resolved against the metadata now.
     *
     * @param (\Closure(self<Model>): self<Model>)|null $constraint
     */
    private function relationMeasure(
        string $name,
        string $relation,
        Aggregate $function,
        string|\BackedEnum|null $column,
        ?\Closure $constraint,
        string $what,
    ): RelationMeasure {
        // A path of relations, each a #[HasMany] or a #[BelongsTo]: the
        // rows aggregated are the ones it ends at (ADR 0073).
        $path = Identifiers::relationPath($this->meta, $relation);
        $last = array_pop($path);
        $target = Metadata::for($last->target);
        $conditions = $constraint === null
            ? []
            : self::conditionsOf($constraint(self::of($this->orm, $last->target)), $last->target, $what)->conditions;

        return new RelationMeasure($name, $last, $function, $column === null ? null : Identifiers::column($target, $column), $conditions, $path);
    }

    /**
     * $other's conditions, as an arm of a union with this query: a query
     * over the same model, read by the same Orm, narrowed by conditions
     * alone -- its order, limit, eager loads and aggregates would be the
     * union's, which are this query's.
     *
     * @param self<T> $other
     * @return list<Condition>
     */
    private function arm(self $other, string $what): array
    {
        if ($other->orm !== $this->orm || $other->class !== $this->class) {
            throw new \LogicException("{$what} joins queries over one model, read by one Orm.");
        }
        if ($other->orders !== [] || $other->limit !== null || $other->offset !== null || $other->with !== [] || $other->groups !== []
            || $other->havings !== [] || $other->distinct || $other->measures !== [] || $other->unionAll !== null) {
            throw new \LogicException("{$what} takes a query narrowed by conditions alone: its order, limit, eager loads and aggregates would be the union's.");
        }

        return $other->conditions;
    }

    /**
     * intersect() and except(): this query's conditions grouped, so an
     * orWhere() among them never takes the arm as one of its alternatives.
     *
     * @param self<T> $other
     * @return static
     */
    private function narrowedByArm(self $other, string $what, bool $not): self
    {
        $arm = $this->arm($other, $what);
        if ($this->unionAll !== null) {
            throw new \LogicException("{$what} narrows the rows of the model's table, and this query is a unionAll() of its arms' rows.");
        }

        return clone($this, ['conditions' => [
            ...($this->conditions === [] ? [] : [Condition::group(false, $this->conditions)]),
            Condition::union(false, [$arm], $not),
        ]]);
    }

    /**
     * What a statement reads from: the model's table with its joins and
     * WHERE -- or, after unionAll(), its arms' rows, read as one table:
     * each arm a SELECT of the model's columns under its own tenant
     * predicate, UNION ALL'd, sorted by the model's own columns alone.
     *
     * @param list<ColumnRef> $also further columns the statement reads
     * @param bool $armed whether a unionAll()'s arms each read their own position too, sorted by
     *        last: what a cursor over one tells a row two arms read apart by (ADR 0123)
     */
    private function readScope(Compiler $compiler, bool $orders = true, array $also = [], bool $armed = false): Scope
    {
        if ($this->unionAll === null) {
            return $compiler->scope($this->meta, $this->conditions, $orders ? $this->orders : [], $also);
        }
        // What is read of a unionAll() is its arms' rows: the model's own
        // columns, which hold no join (ADR 0073).
        foreach ($also as $ref) {
            if ($ref->path !== [] || !\in_array($ref->column, $this->meta->selectColumns(), true)) {
                throw new \LogicException("A unionAll() reads its arms' rows, which hold {$this->class}'s own columns -- not '{$ref->column}' through a join.");
            }
        }

        $arms = [];
        $bindings = [];
        foreach ($this->unionAll as $i => $arm) {
            $scope = $compiler->scope($this->meta, $arm, []);
            [$body, $armBindings] = $scope->body();
            // Which arm read the row, for a cursor: a row two arms read is
            // two places in the order, told apart by it. The position is
            // the builder's own number, never a caller's.
            $arms[] = 'SELECT ' . $this->columnList($scope->alias) . ($armed ? ", {$i} AS " . Identifiers::quote(self::ARM) : '') . $body;
            array_push($bindings, ...$armBindings);
        }

        $resolved = [];
        foreach ($orders ? $this->orders : [] as [$by, $direction, $nulls]) {
            if (!$by instanceof ColumnRef || $by->path !== [] || !\in_array($by->column, $this->meta->selectColumns(), true)) {
                throw new \LogicException('A unionAll() sorts by the model\'s own columns: what it sorts is its arms\' rows, which hold no join and no aggregate.');
            }
            $resolved[] = [Identifiers::quote(self::UNION) . '.' . Identifiers::quote($by->column), $direction, $nulls, []];
        }
        if ($armed) {
            $last = $resolved === [] ? Direction::Asc : $resolved[\count($resolved) - 1][1];
            $resolved[] = [Identifiers::quote(self::UNION) . '.' . Identifiers::quote(self::ARM), $last, null, []];
        }

        return new Scope(
            self::UNION,
            '(' . implode(' UNION ALL ', $arms) . ') AS ' . Identifiers::quote(self::UNION),
            $bindings,
            '',
            [],
            $resolved,
            ['' => self::UNION],
        );
    }

    /** The model's columns, read from $alias under their own names. */
    private function columnList(string $alias): string
    {
        $alias = Identifiers::quote($alias);

        return implode(', ', array_map(
            static fn (string $column): string => $alias . '.' . Identifiers::quote($column) . ' AS ' . Identifiers::quote($column),
            $this->meta->selectColumns(),
        ));
    }

    /**
     * Refuses, for a read of models -- or of their count, a column of
     * theirs, or a page -- what has no place in one: a group, which is no
     * row; a sort by a group's aggregate; and, but where $aggregates says
     * they are read, aggregates over related rows, which fetch() would drop.
     */
    private function assertModelsRead(string $what, bool $aggregates = false): void
    {
        if ($this->groups !== [] || $this->havings !== []) {
            throw new \LogicException("{$what} reads rows, and a grouped query reads groups: call report().");
        }
        if (!$aggregates && $this->measures !== []) {
            throw new \LogicException("{$what} reads models alone: read what withCount() and the rest ask for with fetchAggregated(), paged by limit() and offset().");
        }
        foreach ($this->orders as [$by]) {
            if ($by instanceof Measure) {
                throw new \LogicException("{$what} reads rows: orderByAggregate() sorts a report's groups; orderByRelation() sorts rows by their related rows.");
            }
        }
    }

    /** Refuses a read of the model's own table on a unionAll(), which reads its arms' rows. */
    private function assertOwnTable(string $what): void
    {
        if ($this->unionAll !== null) {
            throw new \LogicException("{$what} reads the model's table, and a unionAll() reads its arms' rows: read each arm.");
        }
    }

    /** Refuses a write through a query that reads anything but rows of the model's own table. */
    private function assertRowsWritten(string $what): void
    {
        $this->assertModelsRead($what);
        $this->assertOwnTable($what);
    }

    /**
     * A unionAll(), as the rows it writes: a row its arms both read is one
     * row to write, so a write through one is a write through the union()
     * of its arms -- the key IN a UNION of each arm's keys, each under the
     * tenant predicate, and the rows locked and written by key with the
     * predicate again, as any write is (ADR 0073). With a limit or an
     * offset, which count a row read twice twice, the rows are no longer
     * the same ones, and it is refused.
     *
     * @return static
     */
    private function rowsToWrite(string $what): self
    {
        if ($this->unionAll === null) {
            return $this;
        }
        if ($this->limit !== null || $this->offset !== null) {
            throw new \LogicException("{$what} through a unionAll() writes each row once, and its limit counts a row read twice twice: write through union().");
        }

        return clone($this, ['conditions' => [Condition::union(false, $this->unionAll)], 'unionAll' => null]);
    }

    /**
     * Whether $ref is one of $refs: the same column, reached the same way.
     *
     * @param list<ColumnRef> $refs
     */
    private static function among(ColumnRef $ref, array $refs): bool
    {
        foreach ($refs as $one) {
            if ($one->column === $ref->column && $one->path === $ref->path) {
                return true;
            }
        }

        return false;
    }

    /** A field named by a string or a string-backed enum, as update() is keyed. */
    private static function fieldName(string|\BackedEnum $column): string
    {
        if ($column instanceof \BackedEnum) {
            return \is_string($column->value) ? $column->value : throw new MappingException($column::class . ' is backed by integers, and a column is named by a string.');
        }

        return $column;
    }

    /**
     * @param string|\BackedEnum|(\Closure(self<T>): self<T>) $column
     * @return static
     */
    private function compare(bool $or, string|\BackedEnum|\Closure $column, mixed $operator, mixed $value, int $arguments): self
    {
        if ($column instanceof \Closure) {
            if ($arguments !== 1) {
                throw new \InvalidArgumentException('A group is a closure alone: where(fn (Query $q) => $q->where(...)).');
            }
            $group = self::conditionsOf($column(self::of($this->orm, $this->class)), $this->class, 'A where() group');

            return $group->conditions === [] ? $this : $this->adding(Condition::group($or, $group->conditions));
        }

        if ($arguments === 2) {
            [$operator, $value] = ['=', $operator];
        } elseif ($arguments !== 3) {
            throw new \InvalidArgumentException('where() takes a column and a value, or a column, an operator and a value.');
        }

        $sql = \is_string($operator) ? (self::OPERATORS[strtolower($operator)] ?? null) : null;
        if ($sql === null) {
            throw new \InvalidArgumentException('A query compares with ' . implode(', ', array_keys(self::OPERATORS)) . '.');
        }
        if ($value === null) {
            throw new \InvalidArgumentException('A comparison with NULL matches no row in SQL; use whereNull() or whereNotNull().');
        }

        $ref = Identifiers::column($this->meta, $column);
        $bound = self::value($value);

        // A key is a UUID the ORM minted, so anything else names no row --
        // answered without asking the database, which on Postgres refuses
        // a malformed uuid with an error rather than a miss (find()). `<>`
        // one is every row with a key, as `<>` a real one would be: a NULL
        // foreign key is no more unequal to it than to any other.
        if (\in_array($sql, ['=', '<>'], true) && $this->isKey($ref) && !(\is_string($bound) && Uuid::isValid($bound))) {
            return $this->adding($sql === '=' ? Condition::never($or) : Condition::null($or, $ref, true));
        }

        // A pattern or a range has no such answer. Postgres has no LIKE for
        // a uuid column, and refuses a bound that is not a UUID with an
        // error quoting it: a 500 for what SQLite and MySQL answered, from
        // text that may be a request's.
        if (!\in_array($sql, ['=', '<>'], true) && $this->isKey($ref)) {
            if (\in_array($sql, ['LIKE', 'NOT LIKE'], true)) {
                throw new \InvalidArgumentException(
                    "A key is a UUID, which Postgres matches with no LIKE: compare '{$ref->column}' with =, whereIn() or a range.",
                );
            }
            if (!\is_string($bound) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $bound) !== 1) {
                throw new \InvalidArgumentException(
                    "A key is compared by a range with a UUID, which Postgres requires of a uuid column: '{$ref->column}' {$sql} takes one.",
                );
            }
        }

        return $this->adding(Condition::compare($or, $ref, $sql, $bound));
    }

    /**
     * @param iterable<mixed> $values
     * @return static
     */
    private function in(bool $or, string|\BackedEnum $column, iterable $values, bool $not): self
    {
        $ref = Identifiers::column($this->meta, $column);
        $key = $this->isKey($ref);

        $bound = [];
        foreach ($values as $value) {
            if ($value === null) {
                throw new \InvalidArgumentException('An IN list cannot hold NULL, which matches nothing; use whereNull().');
            }
            $one = self::value($value);
            if ($key && !(\is_string($one) && Uuid::isValid($one))) {
                continue;   // names no row, as in where()
            }
            $bound[] = $one;
            if (\count($bound) > self::MAX_VALUES) {
                throw new \LengthException('An IN list binds at most ' . self::MAX_VALUES . ' values.');
            }
        }
        // Each value once, by its type and its exact value: PHP's loose ==
        // would take '01', '1' and '1e0' for one, and bind the first alone,
        // so a NOT IN listing all three kept two of them.
        $unique = [];
        foreach ($bound as $one) {
            $unique[get_debug_type($one) . "\0" . $one] = $one;
        }
        $bound = array_values($unique);

        if ($bound === []) {
            return $this->adding($not ? Condition::null($or, $ref, true) : Condition::never($or));
        }

        return $this->adding(Condition::in($or, $ref, $bound, $not));
    }

    /**
     * @param list<mixed> $bindings
     * @return static
     */
    private function raw(bool $or, string $sql, array $bindings): self
    {
        $tokens = Expression::parse($this->meta, $sql, \count($bindings));
        $values = [];
        foreach ($bindings as $value) {
            if ($value === null) {
                throw new \InvalidArgumentException('A raw condition binds no NULL; write IS NULL.');
            }
            $values[] = self::value($value);
        }

        return $this->adding(Condition::raw($or, $tokens, $values));
    }

    /** @param (\Closure(self<Model>): self<Model>)|null $constraint */
    private function has(bool $or, string $path, ?\Closure $constraint, bool $not): Condition
    {
        $relations = Identifiers::relationPath($this->meta, $path);

        $conditions = [];
        if ($constraint !== null) {
            $target = $relations[\count($relations) - 1]->target;
            $conditions = self::conditionsOf($constraint(self::of($this->orm, $target)), $target, 'A whereHas() constraint')->conditions;
        }

        return $this->hasConditions($or, $path, $conditions, $not);
    }

    /**
     * EXISTS over the related rows along $path that $conditions -- on the
     * last model's rows -- hold for.
     *
     * @param list<Condition> $conditions
     */
    private function hasConditions(bool $or, string $path, array $conditions, bool $not): Condition
    {
        $relations = Identifiers::relationPath($this->meta, $path);

        // Innermost first: 'lines.product' is lines having a product.
        for ($i = \count($relations) - 1; $i > 0; $i--) {
            $conditions = [Condition::has(false, $relations[$i], $conditions, false)];
        }

        return Condition::has($or, $relations[0], $conditions, $not);
    }

    /**
     * What a group's or a constraint's closure returned, held to being a
     * query over $class that only adds conditions -- its orders, limit and
     * eager loads would be lost. Taken as mixed: a closure is typed by its
     * docblock alone, and may return anything.
     *
     * @return self<Model>
     */
    private static function conditionsOf(mixed $query, string $class, string $what): self
    {
        if (!$query instanceof self || $query->class !== $class) {
            throw new \LogicException("{$what} returns the query over {$class} it was given, narrowed.");
        }
        if ($query->orders !== [] || $query->limit !== null || $query->offset !== null || $query->with !== [] || $query->groups !== []
            || $query->havings !== [] || $query->distinct || $query->measures !== [] || $query->unionAll !== null) {
            throw new \LogicException("{$what} only adds conditions: its orders, limit, eager loads, groups and aggregates would be lost.");
        }

        return $query;
    }

    private function assertOffsetLimited(): void
    {
        if ($this->offset !== null && $this->limit === null) {
            throw new \LogicException('An offset needs a limit: offset() reads past rows, limit() says how many after them.');
        }
    }

    private function assertUnlimited(string $method): void
    {
        if ($this->limit !== null || $this->offset !== null) {
            throw new \LogicException("{$method} sets the limit and the offset itself.");
        }
    }

    private static function assertPerPage(int $perPage): void
    {
        if ($perPage < 1 || $perPage > Page::MAX_PER_PAGE) {
            throw new \InvalidArgumentException('A page holds between 1 and ' . Page::MAX_PER_PAGE . ' rows.');
        }
    }

    /**
     * Whether a column holds a model's key: its own, or a #[BelongsTo]'s
     * foreign key, at the end of the path it names.
     */
    private function isKey(ColumnRef $column): bool
    {
        $meta = $this->meta;
        foreach ($column->path as $property) {
            $meta = Metadata::for(Identifiers::relation($meta, $property)->target);
        }

        if ($column->column === $meta->idColumn) {
            return true;
        }
        foreach ($meta->relations as $relation) {
            if ($relation->kind === RelationKind::BelongsTo && $relation->foreignKey === $column->column) {
                return true;
            }
        }

        return false;
    }

    /** Whether the model's own column may be NULL, by the type of what maps it. */
    private function nullable(string $column): bool
    {
        $property = array_search($column, $this->meta->columns, true);
        if (\is_string($property)) {
            return (new \ReflectionProperty($this->class, $property))->getType()?->allowsNull() ?? true;
        }

        foreach ($this->meta->relations as $name => $relation) {
            if ($relation->kind === RelationKind::BelongsTo && $relation->foreignKey === $column) {
                return (new \ReflectionProperty($this->class, $name))->getType()?->allowsNull() ?? true;
            }
        }

        return true;   // a column no property types: nothing says it is never NULL
    }

    /**
     * A value as the ORM writes it, ready to bind.
     *
     * @return int|float|string
     */
    private static function value(mixed $value): int|float|string
    {
        if (\is_float($value) && !is_finite($value)) {
            throw new \InvalidArgumentException('A query compares with finite numbers.');
        }

        return match (true) {
            \is_int($value), \is_float($value), \is_string($value) => $value,
            \is_bool($value) => $value ? 1 : 0,
            $value instanceof \BackedEnum => $value->value,
            // As the ORM writes one (Metadata::compileColumnReader()).
            $value instanceof \DateTimeInterface => \DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s.uP'),
            $value instanceof Model => self::keyOf($value),
            default => throw new \InvalidArgumentException(
                'A query compares with a scalar, a backed enum, a date or a saved model, not ' . get_debug_type($value) . '.',
            ),
        };
    }

    private static function keyOf(Model $model): string
    {
        // A ghost stands for a key, and reading it loads the row; any other
        // model has to have been saved to have one.
        if (!(new \ReflectionClass($model))->isUninitializedLazyObject($model) && !$model->isPersisted()) {
            throw new \InvalidArgumentException('A model compared by its key has to be saved first.');
        }

        return $model->primaryKey();
    }

    private function aggregate(string $function, string|\BackedEnum $column): mixed
    {
        $this->assertModelsRead(strtolower($function) . '()', aggregates: true);
        if ($this->distinctUnionAll()) {
            return $this->asUnion()->aggregate($function, $column);
        }
        $ref = Identifiers::column($this->meta, $column);
        $scope = $this->unionAll === null
            ? new Compiler($this->orm)->scope($this->meta, $this->conditions, [], [$ref])
            : $this->readScope(new Compiler($this->orm), orders: false, also: [$ref]);
        [$body, $bindings] = $scope->body();

        $value = $this->orm->statements()->select("SELECT {$function}(" . $scope->expression($ref) . ') AS "aggregate"' . $body, $bindings)[0]['aggregate'] ?? null;
        // MIN() and MAX() answer one of the column's own values.
        $this->orm->recordColumnRead($this->ownerOf($ref), $ref->column, [$value]);

        return $value;
    }

    /**
     * The model whose table $ref's column is on: this query's, or the one
     * its #[BelongsTo] path ends at.
     *
     * @return class-string<Model>
     */
    private function ownerOf(ColumnRef $ref): string
    {
        return self::ownerIn($this->meta, $ref);
    }

    /**
     * The model whose table $ref's column is on, from $meta's.
     *
     * @return class-string<Model>
     */
    private static function ownerIn(ModelMeta $meta, ColumnRef $ref): string
    {
        foreach ($ref->path as $property) {
            $relation = $meta->relations[$property] ?? throw new \LogicException("No relation '{$property}' on {$meta->class}.");
            $meta = Metadata::for($relation->target);
        }

        return $meta->class;
    }

    /**
     * SELECT the model's columns, as the table names them -- so a joined
     * table's never shadow them -- and $list after them, with $extra ANDed
     * to the WHERE.
     *
     * @param list<mixed> $extraBindings
     * @param string $list further terms of the select list, each led by a comma: aggregates over related rows
     * @param list<mixed> $listBindings
     * @return array{string, list<mixed>}
     */
    private function select(
        Scope $scope,
        string $extra = '',
        array $extraBindings = [],
        bool $reversed = false,
        ?int $limit = null,
        ?int $offset = null,
        string $list = '',
        array $listBindings = [],
    ): array {
        [$body, $bindings] = $scope->body($extra, $extraBindings);
        [$tail, $tailBindings] = $this->tail($limit, $offset);

        return [
            'SELECT ' . $this->columnList($scope->alias) . $list . $body . $scope->orderBy($reversed) . $tail,
            [...$listBindings, ...$bindings, ...$scope->orderBindings(), ...$tailBindings],
        ];
    }

    /** @return array{string, list<int>} */
    private function tail(?int $limit, ?int $offset): array
    {
        if ($limit === null) {
            return ['', []];
        }

        return $offset === null || $offset === 0
            ? [' LIMIT ?', [$limit]]
            : [' LIMIT ? OFFSET ?', [$limit, $offset]];
    }

    /**
     * The anchor's sort values, read again under the reader's scope: the
     * tenant predicate, not the query's conditions -- a row that has since
     * left the filter still marks a place in the order.
     *
     * @param list<array{ColumnRef, Direction, Nulls|null}> $orders
     * @return list<mixed>
     */
    private function anchorValues(array $orders, string $anchor): array
    {
        $scope = new Compiler($this->orm)->scope($this->meta, [], $orders);
        $id = $scope->expression(new ColumnRef([], $this->meta->idColumn));
        [$body, $bindings] = $scope->body("{$id} = ?", [$anchor]);

        $select = [];
        foreach ($scope->orders as $i => [$expression]) {
            $select[] = "{$expression} AS \"k{$i}\"";
        }

        $row = $this->orm->statements()->select('SELECT ' . implode(', ', $select) . $body, $bindings)[0] ?? null;
        if ($row === null) {
            throw InvalidCursor::anchorGone();
        }

        $values = [];
        foreach (array_keys($scope->orders) as $i) {
            $value = $row["k{$i}"] ?? null;
            if ($value === null) {
                throw InvalidCursor::anchorGone();
            }
            $values[] = $value;
        }

        return $values;
    }

    /**
     * The rows after (or before) the anchor in the query's order:
     * `(a > ?) OR (a = ? AND b > ?) OR ...`, each comparison turned for a
     * descending column, every value bound.
     *
     * @param list<mixed> $values
     * @return array{string, list<mixed>}
     */
    private static function keyset(Scope $scope, array $values, bool $after): array
    {
        $alternatives = [];
        $bindings = [];
        foreach ($scope->orders as $i => [$expression, $direction]) {
            $parts = [];
            for ($j = 0; $j < $i; $j++) {
                $parts[] = $scope->orders[$j][0] . ' = ?';
                $bindings[] = $values[$j];
            }
            $parts[] = $expression . ((($direction === Direction::Asc) === $after) ? ' > ?' : ' < ?');
            $bindings[] = $values[$i];
            $alternatives[] = '(' . implode(' AND ', $parts) . ')';
        }

        return ['(' . implode(' OR ', $alternatives) . ')', $bindings];
    }

    /**
     * Rows as models, through the identity map, then the eager loads.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<T>
     */
    private function hydrate(array $rows): array
    {
        $models = $this->orm->hydrateAll($this->class, $rows);

        // Each relation once, however many paths share it: 'lines' and
        // 'lines.product' load lines one time.
        $loaded = ['' => $models];
        $paths = $this->with;
        sort($paths);
        foreach ($paths as $path) {
            $parts = explode('.', $path);
            $parent = '';
            $meta = $this->meta;
            foreach ($parts as $property) {
                $key = $parent === '' ? $property : "{$parent}.{$property}";
                $relation = Identifiers::relation($meta, $property);
                $loaded[$key] ??= $this->orm->eagerLoad($relation, $loaded[$parent]);
                $parent = $key;
                $meta = Metadata::for($relation->target);
            }
        }

        return $models;
    }
}
