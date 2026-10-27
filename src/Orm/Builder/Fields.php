<?php

declare(strict_types=1);

namespace Tinvar\Orm\Builder;

use Tinvar\Orm\Change;
use Tinvar\Orm\ForeignTenantRow;
use Tinvar\Orm\Increment;
use Tinvar\Orm\MappingException;
use Tinvar\Orm\Metadata;
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
     * @param bool $hooks run the model's #[BeforeSave] methods on the new row, as save() runs them on a new model
     * @return array<string, mixed> column => stored value, or an Assignment update() writes
     */
    public static function row(ModelMeta $meta, array $values, bool $inserting, ?string $tenant = null, bool $hooks = false): array
    {
        if ($hooks && !$inserting) {
            throw new \LogicException('#[BeforeSave] methods run on a whole new row, never on part of one.');
        }
        /** @var Model $scratch */
        $scratch = new \ReflectionClass($meta->class)->newInstanceWithoutConstructor();
        $properties = [];
        $stored = [];

        foreach ($values as $name => $value) {
            if (!\is_string($name)) {
                throw new \InvalidArgumentException('A write names each field it sets: a list of values says nothing of where they go.');
            }
            if ($value instanceof Increment) {
                $value = Change::add($value->amount);
            }
            if ($value instanceof Change && $inserting) {
                throw new \InvalidArgumentException("'{$name}' is a new row's: there is nothing there to work a change out from.");
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
                if ($hooks) {
                    $meta->set($scratch, $meta->idProperty, $value);
                }
                continue;
            }

            $property = array_search($column, $meta->columns, true);
            if (\is_string($property)) {
                self::assertSettable($meta, $property);
                if ($value instanceof Change) {
                    $stored[$column] = self::assignment($meta, $property, $value);   // written by Query::update()
                    continue;
                }
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

            if ($value instanceof Change) {
                throw new \InvalidArgumentException("'{$name}' is a #[BelongsTo]'s key: a key is not worked out from another value.");
            }
            $stored[$column] = self::relationKey($meta, $column, $value);
            if ($hooks) {
                // The hooks see the relation as save() would: the model.
                $meta->set($scratch, self::relationProperty($meta, $column), $value);
            }
        }

        if ($hooks) {
            return self::hooked($meta, $scratch, $stored, $tenant);
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

    /**
     * A Change checked against the property it sets.
     *
     * Arithmetic and bounds only on a number that is never NULL: a property
     * typed int or float -- a fraction added to an int column would be
     * stored as whatever the database rounds it to -- and not nullable,
     * since NULL plus anything is NULL. A value object's column is typed by
     * its class, so never one. A copy only from another column of the
     * model's own table, mapped by a property of the same built-in type,
     * neither through a Cast -- a column's stored form is then the same in
     * both -- and from one that may be NULL only into one that takes it;
     * never the key, the tenant column or a #[BelongsTo]'s key, which no
     * property maps.
     */
    private static function assignment(ModelMeta $meta, string $property, Change $change): Assignment
    {
        $target = self::builtin($meta, $property);
        $source = null;

        if ($change->kind === Change::COPY) {
            $field = $change->field instanceof \BackedEnum ? (string) $change->field->value : (string) $change->field;
            $ref = Identifiers::column($meta, $field);
            $from = $ref->path === [] ? array_search($ref->column, $meta->columns, true) : false;
            if (!\is_string($from) || $ref->column === $meta->idColumn || $ref->column === $meta->tenantColumn) {
                throw new MappingException("A change copies another column of {$meta->class}'s own, and '{$field}' is not one.");
            }
            $type = self::builtin($meta, $from);
            if ($target === null || $type === null || $type[0] !== $target[0]) {
                throw new \InvalidArgumentException(
                    "{$meta->class}::\${$from} and ::\${$property} are not of one built-in type, so the one is not copied into the other.",
                );
            }
            if ($type[1] && !$target[1]) {
                throw new \InvalidArgumentException("{$meta->class}::\${$from} may be NULL, and ::\${$property} never is.");
            }
            $source = $ref->column;
        }

        if ($change->isNumeric()) {
            $name = $target !== null && !$target[1] ? $target[0] : null;
            if ($name !== 'int' && $name !== 'float') {
                throw new \InvalidArgumentException(
                    "{$meta->class}::\${$property} is no number that is never NULL, so no arithmetic or bound is worked on it.",
                );
            }
            foreach ($change->numbers() as $number) {
                if ($name === 'int' && \is_float($number)) {
                    throw new \InvalidArgumentException("{$meta->class}::\${$property} is an int: work it out with whole numbers.");
                }
            }
        }

        return new Assignment($change, $source);
    }

    /**
     * A property's built-in type and whether it takes NULL; null for a class,
     * a union, or a column mapped through a Cast.
     *
     * @return array{string, bool}|null
     */
    private static function builtin(ModelMeta $meta, string $property): ?array
    {
        $reflection = new \ReflectionProperty($meta->class, $property);
        $type = $reflection->getType();
        if (!$type instanceof \ReflectionNamedType || !$type->isBuiltin() || Metadata::castOf($meta->class, $reflection) !== null) {
            return null;
        }

        return [$type->getName(), $type->allowsNull()];
    }

    /** The #[BelongsTo] property whose key is $column. */
    private static function relationProperty(ModelMeta $meta, string $column): string
    {
        foreach ($meta->relations as $property => $relation) {
            if ($relation->kind === RelationKind::BelongsTo && $relation->foreignKey === $column) {
                return $property;
            }
        }

        throw new MappingException("{$meta->class} maps no property to '{$column}'.");
    }

    /**
     * A new row after the model's #[BeforeSave] methods ran on it, as save()
     * runs them on a new model before it is inserted (ADR 0073): every
     * column the model now holds, through its column reader -- so a column
     * a hook derived is written, and one it changed is written as changed --
     * each #[BelongsTo] it holds, held again to a saved model of its target,
     * and the key, if one is set, held again to a UUID. The tenant is
     * stamped after them all, whatever a hook did.
     *
     * @param array<string, mixed> $stored what the row named, before the hooks
     * @return array<string, mixed>
     */
    private static function hooked(ModelMeta $meta, Model $scratch, array $stored, ?string $tenant): array
    {
        $meta->beforeSave($scratch);

        $row = $meta->columnValues($scratch);
        foreach ($meta->relations as $property => $relation) {
            if ($relation->kind !== RelationKind::BelongsTo || !new \ReflectionProperty($meta->class, $property)->isInitialized($scratch)) {
                continue;
            }
            $row[$relation->foreignKey] = self::relationKey($meta, $relation->foreignKey, $meta->get($scratch, $property));
        }
        if (new \ReflectionProperty($meta->class, $meta->idProperty)->isInitialized($scratch)) {
            $key = $meta->get($scratch, $meta->idProperty);
            if (!\is_string($key) || !Uuid::isValid($key)) {
                throw new MappingException("{$meta->class}: a preset key must be a UUID.");
            }
            $row[$meta->idColumn] = $key;
        } elseif (isset($stored[$meta->idColumn])) {
            $row[$meta->idColumn] = $stored[$meta->idColumn];
        }

        unset($row[$meta->tenantColumn ?? '']);
        if ($meta->tenantColumn !== null) {
            if ($tenant === null) {
                throw new \LogicException('A new row of a #[TenantScoped] model is stamped with the tenant in force.');
            }
            $row[$meta->tenantColumn] = $tenant;
        }

        return $row;
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
