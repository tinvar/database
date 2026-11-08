<?php

declare(strict_types=1);

namespace Tinvar\Orm\Builder;

use Tinvar\Orm\Aggregate;
use Tinvar\Orm\Direction;
use Tinvar\Orm\Nulls;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\ModelMeta;
use Tinvar\Orm\Orm;
use Tinvar\Orm\NamesDriver;
use Tinvar\Orm\RelationKind;
use Tinvar\Orm\RelationMeta;
use Tinvar\Orm\Trashed;

/**
 * Turns a query's resolved parts into SQL, with the tenant predicate on
 * every table the statement reads.
 *
 * The predicate is the Orm's (Orm::tenantsInScope()), the one find() and
 * all() are held to, read when the statement is compiled -- so a query
 * built in one scope and run in another reads the scope it runs in, as a
 * statement would. It goes on every table, not only the model's:
 *
 *   - the model's own, first in the WHERE, ANDed with the query's
 *     conditions in parentheses of their own;
 *   - each table a column's #[BelongsTo] path joins, in the join's ON, so a
 *     row whose related row is another tenant's joins nothing and sorts and
 *     filters as if it had none;
 *   - each subquery whereHas() makes, inside it -- and a filter on a
 *     #[HasMany]'s column is one;
 *   - each subquery an aggregate over a row's related rows makes
 *     (withCount(), orderByRelation()), inside it;
 *   - each arm of a union, a statement of its own.
 *
 * Nothing a caller wrote is interpolated: identifiers are the metadata's,
 * quoted by Identifiers::quote(); aliases are made here from them and a
 * counter; values are `?` and go in the binding list beside the SQL they
 * belong to, in the order the SQL names them.
 *
 * One compiler per statement: its alias counter is the statement's.
 *
 * @internal for the query builder, inside this package
 */
final class Compiler
{
    private int $aliases = 0;

    public function __construct(private readonly Orm $orm) {}

    /**
     * @param list<Condition> $conditions
     * @param list<array{ColumnRef|Measure|RelationMeasure, Direction, Nulls|null}> $orders by a column, by an
     *        aggregate over the rows (a report's), or by one over each row's related rows
     * @param list<ColumnRef> $also further columns the statement reads, which need their joins
     * @param Trashed $trashed which of a #[SoftDeletes] model's rows: the live ones unless the query said (ADR 0191)
     */
    public function scope(ModelMeta $meta, array $conditions, array $orders, array $also = [], Trashed $trashed = Trashed::Without): Scope
    {
        $alias = $this->alias();
        $columns = $also;
        foreach ($orders as [$by]) {
            if ($by instanceof ColumnRef) {
                $columns[] = $by;
            } elseif ($by instanceof Measure && $by->column !== null) {
                $columns[] = $by->column;
            }
        }
        foreach ($conditions as $condition) {
            array_push($columns, ...$condition->columns());
        }

        [$from, $fromBindings, $aliases] = $this->from($meta, $alias, $columns);

        $where = [];
        $whereBindings = [];
        $tenant = $this->tenantPredicate($meta, $alias);
        if ($tenant !== null) {
            $where[] = $tenant[0];
            array_push($whereBindings, ...$tenant[1]);
        }
        $live = $this->trashedPredicate($meta, $alias, $trashed);
        if ($live !== null) {
            $where[] = $live;
        }
        if ($conditions !== []) {
            [$sql, $bindings] = $this->conditions($meta, $alias, $aliases, $conditions);
            $where[] = "({$sql})";
            array_push($whereBindings, ...$bindings);
        }

        $scope = new Scope($alias, $from, $fromBindings, implode(' AND ', $where), $whereBindings, [], $aliases);

        $resolved = [];
        foreach ($orders as [$by, $direction, $nulls]) {
            [$expression, $bindings] = match (true) {
                $by instanceof ColumnRef => [$scope->expression($by), []],
                $by instanceof Measure => $this->measure($meta, $scope, $by),
                default => $this->relationMeasure($meta, $alias, $by),
            };
            $resolved[] = [$expression, $direction, $nulls, $bindings];
        }

        return new Scope($alias, $from, $fromBindings, $scope->where, $whereBindings, $resolved, $aliases);
    }

    /**
     * An aggregate over the rows $scope reads, as SQL: the function is
     * Aggregate's own word, the column the scope's quoted expression. A
     * sum is 0 where it has nothing to add -- a group whose column is NULL
     * throughout -- as it is answered, so a HAVING or a sort reads the
     * value the report shows.
     *
     * @return array{string, list<mixed>}
     */
    public function measure(ModelMeta $meta, Scope $scope, Measure $measure): array
    {
        if ($measure->over !== null) {
            return $this->measureOver($meta, $scope, $measure->function, $measure->over);
        }
        $sql = $measure->function->sql($measure->column === null ? null : $scope->expression($measure->column));

        return [$measure->function === Aggregate::Sum ? "COALESCE({$sql}, 0)" : $sql, []];
    }

    /**
     * An aggregate over the collections of a group's rows: each row's own,
     * a correlated subquery under the related model's tenant predicate,
     * combined by the group's (Measure). A count or a sum adds the rows'
     * and is 0 over none; MIN and MAX take the least and the most of
     * theirs, which a row with no related row adds nothing to; an average
     * is the group's sum over its count, so a row with three children
     * weighs three times one with one.
     *
     * @return array{string, list<mixed>}
     */
    private function measureOver(ModelMeta $meta, Scope $scope, Aggregate $function, RelationMeasure $over): array
    {
        if ($function === Aggregate::Avg) {
            [$sum, $sumBindings] = $this->relationMeasure($meta, $scope->alias, $over->as(Aggregate::Sum, $over->column));
            [$count, $countBindings] = $this->relationMeasure($meta, $scope->alias, $over->as(Aggregate::Count, null));

            // * 1.0, so Postgres and SQLite divide as numbers rather than
            // as integers, which would round 175.5 down to 175.
            return ["SUM({$sum}) * 1.0 / NULLIF(SUM({$count}), 0)", [...$sumBindings, ...$countBindings]];
        }

        [$sql, $bindings] = $this->relationMeasure($meta, $scope->alias, $over);

        return [match ($function) {
            Aggregate::Count, Aggregate::Sum => "COALESCE(SUM({$sql}), 0)",
            Aggregate::Min => "MIN({$sql})",
            Aggregate::Max => "MAX({$sql})",
            default => throw new \LogicException("{$function->name} over a collection is no aggregate of each row's."),
        }, $bindings];
    }

    /**
     * An aggregate over one row's related rows, as a correlated subquery:
     * the related model's table, its joins and its WHERE -- tenant
     * predicate first, then the measure's own conditions -- with the link
     * to the row ANDed after them. So a related row of another tenant's,
     * which a row may well have (a child is stamped with its own tenant,
     * not its parent's), is never counted, summed or compared.
     *
     * A subquery rather than a join with GROUP BY: it reads one row's
     * children and answers one value, so the row is never repeated, and
     * two aggregates over two relations never multiply each other's rows.
     * A sum over no row is 0, as Aggregate::read() answers it.
     *
     * @return array{string, list<mixed>}
     */
    public function relationMeasure(ModelMeta $meta, string $alias, RelationMeasure $measure): array
    {
        $path = [...$measure->through, $measure->relation];
        $metas = [$meta];
        foreach ($path as $relation) {
            $metas[] = Metadata::for($relation->target);
        }
        $last = \count($path);
        $inner = $this->scope($metas[$last], $measure->conditions, [], $measure->column === null ? [] : [$measure->column], self::reached($path[$last - 1]));

        // Along a path ('shelves.items'), the model it ends at is read, as
        // any relation's rows are, and each table between joined back to
        // the row by INNER JOIN, its tenant predicate in the ON: a row the
        // tenant may not see breaks the path there, as a whereHas() along
        // it would find nothing (ADR 0073).
        $joins = '';
        $joinBindings = [];
        $aliases = [$last => $inner->alias];
        for ($i = $last - 1; $i >= 1; $i--) {
            $aliases[$i] = $this->alias();
            [$on, $onBindings] = $this->link($path[$i], $metas[$i], $aliases[$i], $metas[$i + 1], $aliases[$i + 1]);
            $joins .= ' JOIN ' . Identifiers::quote($metas[$i]->table) . ' AS ' . Identifiers::quote($aliases[$i]) . ' ON ' . $on;
            array_push($joinBindings, ...$onBindings);
            $tenant = $this->tenantPredicate($metas[$i], $aliases[$i]);
            if ($tenant !== null) {
                $joins .= ' AND ' . $tenant[0];
                array_push($joinBindings, ...$tenant[1]);
            }
            $live = $this->trashedPredicate($metas[$i], $aliases[$i], self::reached($path[$i - 1]));
            if ($live !== null) {
                $joins .= ' AND ' . $live;
            }
        }

        [$first, $firstBindings] = $this->link($path[0], $meta, $alias, $metas[1], $aliases[1]);
        $where = array_values(array_filter([$inner->where, $first], static fn (string $part): bool => $part !== ''));
        $expression = $measure->function->sql($measure->column === null ? null : $inner->expression($measure->column));
        if ($measure->function === Aggregate::Sum) {
            $expression = "COALESCE({$expression}, 0)";
        }

        return [
            "(SELECT {$expression} FROM {$inner->from}{$joins} WHERE " . implode(' AND ', $where) . ')',
            [...$inner->fromBindings, ...$joinBindings, ...$inner->whereBindings, ...$firstBindings],
        ];
    }

    /**
     * The condition a relation holds between a row of its model, read as
     * $parentAlias, and a row of its target, read as $childAlias: the
     * child's foreign key for a #[HasMany], the parent's for a #[BelongsTo],
     * and for a #[BelongsToMany] a pivot row naming both, of the tenant's
     * (ADR 0192) -- EXISTS rather than a join, so a row is never repeated
     * for each pivot row, and what it binds.
     *
     * @return array{string, list<mixed>}
     */
    private function link(RelationMeta $relation, ModelMeta $parent, string $parentAlias, ModelMeta $child, string $childAlias): array
    {
        return match ($relation->kind) {
            RelationKind::HasMany => [
                Identifiers::quote($childAlias) . '.' . Identifiers::quote($relation->foreignKey)
                    . ' = ' . Identifiers::quote($parentAlias) . '.' . Identifiers::quote($parent->idColumn),
                [],
            ],
            RelationKind::BelongsTo => [
                Identifiers::quote($childAlias) . '.' . Identifiers::quote($child->idColumn)
                    . ' = ' . Identifiers::quote($parentAlias) . '.' . Identifiers::quote($relation->foreignKey),
                [],
            ],
            RelationKind::BelongsToMany => $this->pivotLink($relation, $parent, $parentAlias, $child, $childAlias),
        };
    }

    /** @return array{string, list<mixed>} */
    private function pivotLink(RelationMeta $relation, ModelMeta $parent, string $parentAlias, ModelMeta $child, string $childAlias): array
    {
        $pivot = $relation->pivot ?? throw new \LogicException("{$relation->key()} names no pivot.");
        $pivotMeta = Metadata::for($pivot->class);
        $alias = $this->alias();
        $column = static fn (string $name): string => Identifiers::quote($alias) . '.' . Identifiers::quote($name);

        $where = [
            $column($pivot->ownerColumn) . ' = ' . Identifiers::quote($parentAlias) . '.' . Identifiers::quote($parent->idColumn),
            $column($pivot->targetColumn) . ' = ' . Identifiers::quote($childAlias) . '.' . Identifiers::quote($child->idColumn),
        ];
        $bindings = [];
        $tenant = $this->tenantPredicate($pivotMeta, $alias);
        if ($tenant !== null) {
            $where[] = $tenant[0];
            $bindings = $tenant[1];
        }
        // A trashed pivot row links nothing (ADR 0191).
        $live = $this->trashedPredicate($pivotMeta, $alias, Trashed::Without);
        if ($live !== null) {
            $where[] = $live;
        }

        return [
            'EXISTS (SELECT 1 FROM ' . Identifiers::quote($pivotMeta->table) . ' AS ' . Identifiers::quote($alias) . ' WHERE ' . implode(' AND ', $where) . ')',
            $bindings,
        ];
    }

    /**
     * The model's table and a LEFT JOIN for every #[BelongsTo] path the
     * columns walk, each with its target's tenant predicate in the ON.
     *
     * LEFT, so a row whose relation is unset -- a nullable foreign key -- or
     * not the tenant's is still read; a filter on the related column then
     * finds NULL there, as it would for no row.
     *
     * @param list<ColumnRef> $columns
     * @return array{string, list<mixed>, array<string, string>}
     */
    private function from(ModelMeta $meta, string $alias, array $columns): array
    {
        $paths = [];
        foreach ($columns as $column) {
            // Every prefix too: 'site.region' joins 'site' first.
            for ($depth = 1; $depth <= \count($column->path); $depth++) {
                $paths[implode('.', \array_slice($column->path, 0, $depth))] = \array_slice($column->path, 0, $depth);
            }
        }
        uasort($paths, static fn (array $a, array $b): int => \count($a) <=> \count($b) ?: strcmp(implode('.', $a), implode('.', $b)));

        $sql = Identifiers::quote($meta->table) . ' AS ' . Identifiers::quote($alias);
        $bindings = [];
        $aliases = ['' => $alias];
        $metas = ['' => $meta];

        foreach ($paths as $key => $path) {
            $parentKey = implode('.', \array_slice($path, 0, -1));
            $parent = $metas[$parentKey];
            $relation = Identifiers::relation($parent, $path[\count($path) - 1]);
            if ($relation->kind !== RelationKind::BelongsTo) {
                throw new \LogicException("Only a #[BelongsTo] is joined, and {$relation->key()} is not one.");
            }
            $target = Metadata::for($relation->target);
            $joined = $alias . '__' . implode('__', $path);

            $sql .= ' LEFT JOIN ' . Identifiers::quote($target->table) . ' AS ' . Identifiers::quote($joined)
                . ' ON ' . Identifiers::quote($joined) . '.' . Identifiers::quote($target->idColumn)
                . ' = ' . Identifiers::quote($aliases[$parentKey]) . '.' . Identifiers::quote($relation->foreignKey);

            $tenant = $this->tenantPredicate($target, $joined);
            if ($tenant !== null) {
                $sql .= ' AND ' . $tenant[0];
                array_push($bindings, ...$tenant[1]);
            }

            $aliases[$key] = $joined;
            $metas[$key] = $target;
        }

        return [$sql, $bindings, $aliases];
    }

    /**
     * The tenant predicate on the model's table by its own name, for a
     * statement that writes it: UPDATE and DELETE name no alias. The
     * predicate a read of it is held to, failing closed where a read does.
     *
     * @return array{string, list<string>}|null
     */
    public function tenantOnTable(ModelMeta $meta): ?array
    {
        return $this->tenantPredicate($meta, $meta->table);
    }

    /**
     * The tenant predicate on one table, or null where the model is not
     * #[TenantScoped] or acrossTenants() lifted scoping. Throws
     * NoTenantContext where find() would.
     *
     * @return array{string, list<string>}|null
     */
    private function tenantPredicate(ModelMeta $meta, string $alias): ?array
    {
        $tenants = $this->orm->tenantsInScope($meta);
        if ($tenants === null || $meta->tenantColumn === null) {
            return null;
        }

        $column = Identifiers::quote($alias) . '.' . Identifiers::quote($meta->tenantColumn);

        return \count($tenants) === 1
            ? ["{$column} = ?", $tenants]
            : [$column . ' IN (' . self::placeholders(\count($tenants)) . ')', $tenants];
    }

    /**
     * Which rows of a #[SoftDeletes] model, read as $alias, the statement
     * takes, or null where it takes every one -- or the model does not
     * soft delete.
     */
    private function trashedPredicate(ModelMeta $meta, string $alias, Trashed $trashed): ?string
    {
        return $meta->deletedAtColumn === null
            ? null
            : $trashed->predicate(Identifiers::quote($alias) . '.' . Identifiers::quote($meta->deletedAtColumn));
    }

    /**
     * The rows a relation reaches of its target: a #[HasMany]'s or a
     * #[BelongsToMany]'s live ones, as a loaded collection holds them;
     * every row a #[BelongsTo]'s key may name, trashed or not, as it loads
     * one (ADR 0191).
     */
    private static function reached(RelationMeta $relation): Trashed
    {
        return $relation->kind === RelationKind::BelongsTo ? Trashed::With : Trashed::Without;
    }

    /**
     * @param array<string, string> $aliases
     * @param list<Condition> $conditions
     * @return array{string, list<mixed>}
     */
    private function conditions(ModelMeta $meta, string $alias, array $aliases, array $conditions): array
    {
        $sql = '';
        $bindings = [];

        foreach ($conditions as $i => $condition) {
            [$part, $values] = $this->condition($meta, $alias, $aliases, $condition);
            $sql .= ($i === 0 ? '' : ($condition->or ? ' OR ' : ' AND ')) . $part;
            array_push($bindings, ...$values);
        }

        return [$sql, $bindings];
    }

    /**
     * @param array<string, string> $aliases
     * @return array{string, list<mixed>}
     */
    private function condition(ModelMeta $meta, string $alias, array $aliases, Condition $condition): array
    {
        $expression = static function (ColumnRef $column) use ($aliases): string {
            $at = $aliases[$column->pathKey()] ?? throw new \LogicException("No join for '{$column->pathKey()}'.");

            return Identifiers::quote($at) . '.' . Identifiers::quote($column->column);
        };

        switch ($condition->kind) {
            case Condition::COMPARE:
                \assert($condition->column !== null);

                return [$expression($condition->column) . " {$condition->operator} " . $this->placeholder($condition->values[0] ?? null), $condition->values];

            case Condition::IN:
                \assert($condition->column !== null);

                return [
                    $expression($condition->column) . ($condition->not ? ' NOT IN (' : ' IN (') . implode(', ', array_map($this->placeholder(...), $condition->values)) . ')',
                    $condition->values,
                ];

            case Condition::NULL:
                \assert($condition->column !== null);

                return [$expression($condition->column) . ($condition->not ? ' IS NOT NULL' : ' IS NULL'), []];

            case Condition::GROUP:
                [$sql, $bindings] = $this->conditions($meta, $alias, $aliases, $condition->conditions);

                return ["({$sql})", $bindings];

            case Condition::RAW:
                // A space between tokens, but none between a function and its
                // parenthesis: MySQL reads a built-in's name followed by a
                // space as an identifier, so `TRIM (name)` did not parse.
                $sql = '';
                $param = 0;
                $separator = '';
                foreach ($condition->tokens as [$kind, $text]) {
                    $sql .= $separator . match (true) {
                        $text instanceof ColumnRef => $expression($text),
                        $kind === 'param' => $this->placeholder($condition->values[$param++] ?? null),
                        default => $text,
                    };
                    $separator = $kind === 'function' ? '' : ' ';
                }

                return ["({$sql})", $condition->values];

            case Condition::NEVER:
                return ['1 = 0', []];

            case Condition::HAS:
                return $this->has($meta, $alias, $condition);

            case Condition::UNION:
                return $this->union($meta, $alias, $condition);

            default:
                throw new \LogicException("No such condition: {$condition->kind}.");
        }
    }

    /**
     * EXISTS over the related rows, scoped as a read of them would be: the
     * related model's tenant predicate inside, whatever the parent's is.
     *
     * @return array{string, list<mixed>}
     */
    private function has(ModelMeta $meta, string $alias, Condition $condition): array
    {
        $relation = $condition->relation ?? throw new \LogicException('A whereHas() condition names its relation.');
        $target = Metadata::for($relation->target);
        $inner = $this->scope($target, $condition->conditions, [], [], self::reached($relation));

        [$link, $linkBindings] = $this->link($relation, $meta, $alias, $target, $inner->alias);

        // The related model's own WHERE -- its tenant predicate and its
        // conditions -- with the link ANDed after it.
        [$body, $bindings] = $inner->body($link, $linkBindings);

        return [($condition->not ? 'NOT EXISTS (SELECT 1' : 'EXISTS (SELECT 1') . $body . ')', $bindings];
    }

    /**
     * The rows any arm matches: the key IN a UNION of each arm's keys.
     * Each arm is a statement over the model's table of its own, compiled
     * as any read is -- its own joins, each with its tenant predicate in the
     * ON, and its WHERE with the model's tenant predicate first -- so no arm
     * reads a row the query itself could not, whatever its conditions.
     *
     * Over keys rather than whole rows, so the rows come back once each --
     * UNION's own de-duplication, on a column every database can compare,
     * where a whole row may hold a JSON column Postgres has no equality for
     * -- and the query around it reads them as any query does: sorted,
     * joined, paged, counted, written.
     *
     * @return array{string, list<mixed>}
     */
    private function union(ModelMeta $meta, string $alias, Condition $condition): array
    {
        $arms = [];
        $bindings = [];
        foreach ($condition->arms as $arm) {
            // Every row, trashed or not: the read around the arms holds
            // its own rows to what it reads (Query::arm() has each arm
            // read as it does).
            $inner = $this->scope($meta, $arm, [], [], Trashed::With);
            [$body, $armBindings] = $inner->body();
            $arms[] = 'SELECT ' . $inner->expression(new ColumnRef([], $meta->idColumn)) . $body;
            array_push($bindings, ...$armBindings);
        }

        return [
            Identifiers::quote($alias) . '.' . Identifiers::quote($meta->idColumn) . ($condition->not ? ' NOT IN (' : ' IN (') . implode(' UNION ', $arms) . ')',
            $bindings,
        ];
    }

    private function alias(): string
    {
        return 't' . $this->aliases++;
    }

    /**
     * A value's placeholder: a float's cast to a float, elsewhere than on
     * MySQL. Bound, it goes as text -- PDO has no float type -- and SQLite
     * compares text with a number as greater where no column's affinity
     * converts it (`position * ? > ?`), while Postgres takes the text for
     * the column's type and refuses '2.5' for an integer. MySQL converts
     * text it compares with a number, and has no CAST(... AS DOUBLE
     * PRECISION) before 8.0.17.
     */
    public function placeholder(mixed $value): string
    {
        if (!\is_float($value)) {
            return '?';
        }
        $connection = $this->orm->statements();

        return $connection instanceof NamesDriver && $connection->driver() === 'mysql' ? '?' : 'CAST(? AS DOUBLE PRECISION)';
    }

    public static function placeholders(int $count): string
    {
        return implode(', ', array_fill(0, $count, '?'));
    }
}
