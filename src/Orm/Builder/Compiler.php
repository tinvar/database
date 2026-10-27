<?php

declare(strict_types=1);

namespace Tinvar\Orm\Builder;

use Tinvar\Orm\Direction;
use Tinvar\Orm\Nulls;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\ModelMeta;
use Tinvar\Orm\Orm;
use Tinvar\Orm\RelationKind;

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
 *   - each subquery whereHas() makes, inside it.
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
     * @param list<array{ColumnRef, Direction, Nulls|null}> $orders
     * @param list<ColumnRef> $also further columns the statement reads, which need their joins
     */
    public function scope(ModelMeta $meta, array $conditions, array $orders, array $also = []): Scope
    {
        $alias = $this->alias();
        $columns = [...$also, ...array_map(static fn (array $order): ColumnRef => $order[0], $orders)];
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
        if ($conditions !== []) {
            [$sql, $bindings] = $this->conditions($meta, $alias, $aliases, $conditions);
            $where[] = "({$sql})";
            array_push($whereBindings, ...$bindings);
        }

        $scope = new Scope($alias, $from, $fromBindings, implode(' AND ', $where), $whereBindings, [], $aliases);

        return new Scope(
            $alias,
            $from,
            $fromBindings,
            $scope->where,
            $whereBindings,
            array_map(static fn (array $order): array => [$scope->expression($order[0]), $order[1], $order[2]], $orders),
            $aliases,
        );
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

                return [$expression($condition->column) . " {$condition->operator} ?", $condition->values];

            case Condition::IN:
                \assert($condition->column !== null);

                return [
                    $expression($condition->column) . ($condition->not ? ' NOT IN (' : ' IN (') . self::placeholders(\count($condition->values)) . ')',
                    $condition->values,
                ];

            case Condition::NULL:
                \assert($condition->column !== null);

                return [$expression($condition->column) . ($condition->not ? ' IS NOT NULL' : ' IS NULL'), []];

            case Condition::GROUP:
                [$sql, $bindings] = $this->conditions($meta, $alias, $aliases, $condition->conditions);

                return ["({$sql})", $bindings];

            case Condition::RAW:
                $parts = [];
                foreach ($condition->tokens as [$kind, $text]) {
                    $parts[] = $text instanceof ColumnRef ? $expression($text) : $text;
                }

                return ['(' . implode(' ', $parts) . ')', $condition->values];

            case Condition::NEVER:
                return ['1 = 0', []];

            case Condition::HAS:
                return $this->has($meta, $alias, $condition);

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
        $inner = $this->scope($target, $condition->conditions, []);

        $link = $relation->kind === RelationKind::HasMany
            ? Identifiers::quote($inner->alias) . '.' . Identifiers::quote($relation->foreignKey)
                . ' = ' . Identifiers::quote($alias) . '.' . Identifiers::quote($meta->idColumn)
            : Identifiers::quote($inner->alias) . '.' . Identifiers::quote($target->idColumn)
                . ' = ' . Identifiers::quote($alias) . '.' . Identifiers::quote($relation->foreignKey);

        // The related model's own WHERE -- its tenant predicate and its
        // conditions -- with the link ANDed after it.
        [$body, $bindings] = $inner->body($link);

        return [($condition->not ? 'NOT EXISTS (SELECT 1' : 'EXISTS (SELECT 1') . $body . ')', $bindings];
    }

    private function alias(): string
    {
        return 't' . $this->aliases++;
    }

    public static function placeholders(int $count): string
    {
        return implode(', ', array_fill(0, $count, '?'));
    }
}
