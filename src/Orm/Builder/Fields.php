<?php

declare(strict_types=1);

namespace Tinvar\Orm\Builder;

use Tinvar\Orm\ForeignTenantRow;
use Tinvar\Orm\MappingException;
use Tinvar\Orm\Model;
use Tinvar\Orm\ModelMeta;
use Tinvar\Orm\RelationKind;
use Tinvar\Support\Uuid;

/**
 * What a write through the query builder sets: each name resolved against
 * compiled metadata as a read's is (Identifiers), each value made into what
 * the ORM stores for it, the way save() would store it.
 *
 * A value goes through the model's own property, on a model made for the
 * purpose and never kept: assigned there, so PHP holds it to the
 * property's type as it would on a save -- an int property refuses '5' --
 * and read back through the model's compiled column reader, so a value
 * object goes through its Cast, a date is written in UTC to the
 * microsecond, an enum as its value, and an array as JSON, exactly as
 * save() writes them. No conversion is written twice.
 *
 * What a write may not set:
 *
 *   - a column through a #[BelongsTo] path: a write sets its own table;
 *   - the key, which never changes -- though a new row may bring one, if
 *     it is a UUID, as save() lets a preset key through;
 *   - the tenant column, which is stamped: a new row may name only the
 *     tenant in force (ForeignTenantRow), and nothing moves a row to
 *     another;
 *   - a property the model alone sets -- `private(set)`, `protected(set)`
 *     or readonly: asymmetric visibility keeps a timestamp or a counter the
 *     ORM's or the model's own, and a write from outside it is not;
 *   - a #[BelongsTo] as anything but a model of its target, saved, or null
 *     where the property allows it. Never a raw key: one that came from a
 *     request could name another tenant's row, where a model was read
 *     under the reader's scope.
 *
 * @internal for the query builder, inside this package
 */
final class Fields
{
    /**
     * One row of a write, as columns and the values the ORM stores.
     *
     * @param array<array-key, mixed> $values name => value
     * @param bool $inserting whether the row is new, and so may name its key, and its tenant
     * @param string|null $tenant the tenant in force, for a new row of a #[TenantScoped] model
     * @return array<string, mixed> column => stored value
     */
    public static function row(ModelMeta $meta, array $values, bool $inserting, ?string $tenant = null): array
    {
        /** @var Model $scratch */
        $scratch = new \ReflectionClass($meta->class)->newInstanceWithoutConstructor();
        $properties = [];
        $stored = [];

        foreach ($values as $name => $value) {
            if (!\is_string($name)) {
                throw new \InvalidArgumentException('A write names each field it sets: a list of values says nothing of where they go.');
            }
            $ref = Identifiers::column($meta, $name);
            if ($ref->path !== []) {
                throw new MappingException("'{$name}' is a column of another table; a write sets {$meta->class}'s own.");
            }
            $column = $ref->column;
            if (\array_key_exists($column, $stored) || \in_array($column, $properties, true)) {
                throw new \InvalidArgumentException("'{$name}' sets {$column}, which this row sets already.");
            }

            if ($column === $meta->tenantColumn) {
                if (!$inserting) {
                    throw ForeignTenantRow::moved($meta->class);
                }
                if ($tenant === null || $value !== $tenant) {
                    throw ForeignTenantRow::named($meta->class);
                }
                continue;   // stamped with the tenant in force, below
            }

            if ($column === $meta->idColumn) {
                if (!$inserting) {
                    throw new \InvalidArgumentException("A write never changes {$meta->class}'s key.");
                }
                if (!\is_string($value) || !Uuid::isValid($value)) {
                    // A key find() would never look up (Orm::insert()).
                    throw new MappingException("{$meta->class}: a preset key must be a UUID.");
                }
                $stored[$column] = $value;
                continue;
            }

            $property = array_search($column, $meta->columns, true);
            if (\is_string($property)) {
                self::assertSettable($meta, $property);
                try {
                    $meta->set($scratch, $property, $value);
                } catch (\TypeError $e) {
                    throw new \InvalidArgumentException(
                        "{$meta->class}::\${$property} does not take " . get_debug_type($value) . '.',
                        0,
                        $e,
                    );
                }
                $properties[$property] = $column;
                continue;
            }

            $stored[$column] = self::relationKey($meta, $column, $value);
        }

        // Each through the model's column reader, as save() stores it.
        $read = $meta->columnValues($scratch);
        foreach ($properties as $column) {
            $stored[$column] = $read[$column];
        }

        if ($inserting && $meta->tenantColumn !== null) {
            if ($tenant === null) {
                throw new \LogicException('A new row of a #[TenantScoped] model is stamped with the tenant in force.');
            }
            $stored[$meta->tenantColumn] = $tenant;
        }

        return $stored;
    }

    /** Refuses a property the model alone sets. */
    private static function assertSettable(ModelMeta $meta, string $property): void
    {
        $reflection = new \ReflectionProperty($meta->class, $property);
        if ($reflection->isReadOnly() || $reflection->isPrivateSet() || $reflection->isProtectedSet()) {
            throw new \InvalidArgumentException(
                "{$meta->class}::\${$property} is set by the model alone, so a write from outside it does not set it either.",
            );
        }
    }

    /** A #[BelongsTo]'s key, from a model of its target or null. */
    private static function relationKey(ModelMeta $meta, string $column, mixed $value): ?string
    {
        foreach ($meta->relations as $property => $relation) {
            if ($relation->kind !== RelationKind::BelongsTo || $relation->foreignKey !== $column) {
                continue;
            }

            if ($value === null) {
                if (!(new \ReflectionProperty($meta->class, $property))->getType()?->allowsNull()) {
                    throw new \InvalidArgumentException("{$meta->class}::\${$property} is never unset.");
                }

                return null;
            }
            if (!$value instanceof $relation->target) {
                throw new \InvalidArgumentException(
                    "{$meta->class}::\${$property} is set to a {$relation->target}, read under the reader's scope -- not to "
                    . get_debug_type($value) . '. A key from a request could name a row the reader may not see.',
                );
            }
            // A ghost stands for its key; any other model has to be saved.
            if (!(new \ReflectionClass($value))->isUninitializedLazyObject($value) && !$value->isPersisted()) {
                throw new \InvalidArgumentException("{$meta->class}::\${$property} is set to a {$relation->target} not saved yet.");
            }

            return $value->primaryKey();
        }

        throw new MappingException("{$meta->class} maps no property to '{$column}', so a write has nothing to set it from.");
    }
}
