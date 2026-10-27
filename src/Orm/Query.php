<?php

declare(strict_types=1);

namespace Tinvar\Orm;

use Tinvar\Orm\Builder\ColumnRef;
use Tinvar\Orm\Builder\Compiler;
use Tinvar\Orm\Builder\Condition;
use Tinvar\Orm\Builder\Expression;
use Tinvar\Orm\Builder\Identifiers;
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
 * every table a column joins, and every subquery whereHas() makes: the
 * predicate find() and all() use, failing closed with NoTenantContext where
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

    /** Comparison operators, as a caller may spell them, and as they are written. */
    private const array OPERATORS = [
        '=' => '=', '!=' => '<>', '<>' => '<>', '<' => '<', '<=' => '<=', '>' => '>', '>=' => '>=',
        'like' => 'LIKE', 'not like' => 'NOT LIKE',
    ];

    /**
     * @param class-string<T> $class
     * @param list<Condition> $conditions
     * @param list<array{ColumnRef, Direction, Nulls|null}> $orders
     * @param list<string> $with relation paths to load with the rows
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
     * @param string|\BackedEnum|(\Closure(self<T>): self<T>) $column
     * @return static
     */
    #[\NoDiscard]
    public function where(string|\BackedEnum|\Closure $column, mixed $operator = null, mixed $value = null): self
    {
        return $this->compare(false, $column, $operator, $value, \func_num_args());
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
        return $this->compare(true, $column, $operator, $value, \func_num_args());
    }

    /**
     * @param iterable<mixed> $values
     * @return static
     */
    #[\NoDiscard]
    public function whereIn(string|\BackedEnum $column, iterable $values): self
    {
        return $this->in(false, $column, $values, false);
    }

    /**
     * @param iterable<mixed> $values
     * @return static
     */
    #[\NoDiscard]
    public function orWhereIn(string|\BackedEnum $column, iterable $values): self
    {
        return $this->in(true, $column, $values, false);
    }

    /**
     * @param iterable<mixed> $values
     * @return static
     */
    #[\NoDiscard]
    public function whereNotIn(string|\BackedEnum $column, iterable $values): self
    {
        return $this->in(false, $column, $values, true);
    }

    /** @return static */
    #[\NoDiscard]
    public function whereNull(string|\BackedEnum $column): self
    {
        return $this->adding(Condition::null(false, Identifiers::column($this->meta, $column), false));
    }

    /** @return static */
    #[\NoDiscard]
    public function orWhereNull(string|\BackedEnum $column): self
    {
        return $this->adding(Condition::null(true, Identifiers::column($this->meta, $column), false));
    }

    /** @return static */
    #[\NoDiscard]
    public function whereNotNull(string|\BackedEnum $column): self
    {
        return $this->adding(Condition::null(false, Identifiers::column($this->meta, $column), true));
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

    // ------------------------------------------------------------- reading

    /**
     * The rows, as models.
     *
     * @return list<T>
     */
    #[\NoDiscard]
    public function fetch(): array
    {
        $this->assertOffsetLimited();

        $scope = new Compiler($this->orm)->scope($this->meta, $this->conditions, $this->orders);
        [$sql, $bindings] = $this->select($scope, limit: $this->limit, offset: $this->offset);

        return $this->hydrate($this->orm->connection()->select($sql, $bindings));
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
        $scope = new Compiler($this->orm)->scope($this->meta, $this->conditions, []);
        [$body, $bindings] = $scope->body();

        return (int) ($this->orm->connection()->select('SELECT COUNT(*) AS "aggregate"' . $body, $bindings)[0]['aggregate'] ?? 0);
    }

    /** Whether any row matches. */
    #[\NoDiscard]
    public function exists(): bool
    {
        $scope = new Compiler($this->orm)->scope($this->meta, $this->conditions, []);
        [$body, $bindings] = $scope->body();

        return $this->orm->connection()->select('SELECT 1 AS "found"' . $body . ' LIMIT ?', [...$bindings, 1]) !== [];
    }

    /**
     * One column's values, as the database returns them, in the query's
     * order and within its limit. No model is made, so none is recorded or
     * cached: what comes back is the column, not a row.
     *
     * @return list<mixed>
     */
    #[\NoDiscard]
    public function pluck(string|\BackedEnum $column): array
    {
        $ref = Identifiers::column($this->meta, $column);
        $this->assertOffsetLimited();
        $scope = new Compiler($this->orm)->scope($this->meta, $this->conditions, $this->orders, [$ref]);
        [$body, $bindings] = $scope->body();
        [$tail, $tailBindings] = $this->tail($this->limit, $this->offset);

        $rows = $this->orm->connection()->select(
            'SELECT ' . $scope->expression($ref) . ' AS "value"' . $body . $scope->orderBy() . $tail,
            [...$bindings, ...$tailBindings],
        );

        return array_column($rows, 'value');
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

        $orders = $this->orders;
        $hasKey = false;
        foreach ($orders as [$column]) {
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

        $scope = new Compiler($this->orm)->scope($this->meta, $this->conditions, $orders);
        [$body, $bindings] = $scope->body();
        $fingerprint = $this->class . "\n" . $body . $scope->orderBy() . "\n" . serialize($bindings);

        $after = true;
        $keyset = '';
        $keysetBindings = [];
        if ($cursor !== null && $cursor !== '') {
            [$anchor, $after] = $cursors->open($fingerprint, $cursor) ?? throw InvalidCursor::unopened();
            [$keyset, $keysetBindings] = self::keyset($scope, $this->anchorValues($orders, $anchor), $after);
        }

        [$sql, $selectBindings] = $this->select($scope, $keyset, $keysetBindings, reversed: !$after, limit: $perPage + 1);
        $models = $this->hydrate($this->orm->connection()->select($sql, $selectBindings));

        $more = \count($models) > $perPage;
        $models = \array_slice($models, 0, $perPage);
        if (!$after) {
            $models = array_reverse($models);
        }

        $first = $models[0] ?? null;
        $last = $models === [] ? null : $models[\count($models) - 1];
        $seal = static fn (?Model $model, bool $forward): ?string => $model === null
            ? null
            : $cursors->seal($fingerprint, $model->primaryKey(), $forward);

        return $after
            ? new CursorPage($models, $perPage, $more ? $seal($last, true) : null, $keyset === '' ? null : $seal($first, false))
            : new CursorPage($models, $perPage, $seal($last, true), $more ? $seal($first, false) : null);
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
        $scope = new Compiler($this->orm)->scope($this->meta, $this->conditions, $this->orders);

        return $this->select($scope, limit: $this->limit, offset: $this->offset);
    }

    // ------------------------------------------------------------- internals

    /** @return static */
    private function adding(Condition $condition): self
    {
        return clone($this, ['conditions' => [...$this->conditions, $condition]]);
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
        if ($query->orders !== [] || $query->limit !== null || $query->offset !== null || $query->with !== []) {
            throw new \LogicException("{$what} only adds conditions: its orders, limit and eager loads would be lost.");
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
        $ref = Identifiers::column($this->meta, $column);
        $scope = new Compiler($this->orm)->scope($this->meta, $this->conditions, [], [$ref]);
        [$body, $bindings] = $scope->body();

        return $this->orm->connection()->select("SELECT {$function}(" . $scope->expression($ref) . ') AS "aggregate"' . $body, $bindings)[0]['aggregate'] ?? null;
    }

    /**
     * SELECT the model's columns, as the table names them -- so a joined
     * table's never shadow them -- with $extra ANDed to the WHERE.
     *
     * @param list<mixed> $extraBindings
     * @return array{string, list<mixed>}
     */
    private function select(Scope $scope, string $extra = '', array $extraBindings = [], bool $reversed = false, ?int $limit = null, ?int $offset = null): array
    {
        $alias = Identifiers::quote($scope->alias);
        $columns = implode(', ', array_map(
            static fn (string $column): string => $alias . '.' . Identifiers::quote($column) . ' AS ' . Identifiers::quote($column),
            $this->meta->selectColumns(),
        ));

        [$body, $bindings] = $scope->body($extra, $extraBindings);
        [$tail, $tailBindings] = $this->tail($limit, $offset);

        return ["SELECT {$columns}{$body}" . $scope->orderBy($reversed) . $tail, [...$bindings, ...$tailBindings]];
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

        $row = $this->orm->connection()->select('SELECT ' . implode(', ', $select) . $body, $bindings)[0] ?? null;
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
