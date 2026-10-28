<?php

declare(strict_types=1);

namespace Tinvar\Orm;

use Tinvar\Orm\Attributes\BeforeSave;
use Tinvar\Orm\Attributes\BelongsTo;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\HasMany;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\TenantScoped;
use Tinvar\Orm\Attributes\Unique;
use Tinvar\Support\KnownAttributes;
use Tinvar\Support\Str;

/**
 * The metadata compiler. Reflection runs once per model and the result is cached
 * for the life of the worker; nothing here runs per request
 * (tinvar-spec.md, "Engine layers").
 */
final class Metadata
{
    /** @var array<class-string<Model>, ModelMeta> */
    private static array $compiled = [];

    /**
     * By value class, the first instance property it or a class it extends
     * declares that is not readonly -- what makes it mutable -- or false for
     * none. Shallow, as PHP's readonly is: a readonly property may still
     * hold a mutable object.
     *
     * @var array<string, string|false>
     */
    private static array $mutable = [];

    /** @param class-string<Model> $class */
    public static function for(string $class): ModelMeta
    {
        return self::$compiled[$class] ??= self::compile($class);
    }

    /**
     * The tables of the #[TenantScoped] models compiled so far: what the
     * dev toolbar flags raw SQL on (RawSql). A model this worker has not
     * compiled yet is not in it -- the static check, given the models,
     * covers those.
     *
     * @return list<string>
     */
    public static function tenantTables(): array
    {
        $tables = [];
        foreach (self::$compiled as $meta) {
            if ($meta->tenantColumn !== null) {
                $tables[$meta->table] = true;
            }
        }

        return array_keys($tables);
    }

    /**
     * Every compiled relation whose target is $class: the relations a write
     * to one of its rows can change. Only compiled models are searched, which
     * is enough -- a relation of a model never compiled has loaded nothing.
     *
     * @param class-string<Model> $class
     * @return list<RelationMeta>
     */
    public static function relationsTo(string $class): array
    {
        $found = [];
        foreach (self::$compiled as $meta) {
            foreach ($meta->relations as $relation) {
                if ($relation->target === $class) {
                    $found[] = $relation;
                }
            }
        }

        return $found;
    }

    /** @param class-string<Model> $class */
    private static function compile(string $class): ModelMeta
    {
        $reflection = new \ReflectionClass($class);

        // And one naming a Tinvar class that does not exist -- an old name
        // with no alias made for it -- is refused rather than matched by
        // nothing, which would compile #[TenantScoped] as absent.
        KnownAttributes::check($reflection);

        // Every attribute is read as an instance of its class, not by the
        // name it was written with: a model still spelling an attribute by
        // its name from before the split (packages/database/aliases.php)
        // names an alias, and read by name alone #[TenantScoped] would match
        // nothing and the model would read every tenant's rows.
        $table = $reflection->getAttributes(Table::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
        if ($table === null) {
            throw MappingException::noTable($class);
        }
        $tenant = $reflection->getAttributes(TenantScoped::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;

        $columns = [];
        $relations = [];
        $idProperty = null;
        $createdAt = null;
        /** @var list<array{string, string, (\Closure(mixed): mixed)|null, (\Closure(object): mixed)|null}> $plan */
        $plan = [];

        foreach ($reflection->getProperties() as $property) {
            $name = $property->getName();

            if ($relation = self::relationFor($class, $property)) {
                $relations[$name] = $relation;
                continue;
            }

            $isId = $property->getAttributes(Id::class, \ReflectionAttribute::IS_INSTANCEOF) !== [];
            $columnAttribute = $property->getAttributes(Column::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
            if (!$isId && $columnAttribute === null) {
                continue;   // virtual properties, computed hooks and plain state are not columns
            }

            $column = $columnAttribute?->newInstance()->name ?? Str::snake($name);
            $columns[$name] = $column;
            $plan[] = [$name, $column, ...self::castFor($class, $property, $column)];

            if ($isId) {
                $idProperty = $name;
            } elseif ($name === 'createdAt' && self::isTimestamp($property)) {
                $createdAt = $name;
            }
        }

        if ($idProperty === null) {
            throw MappingException::noId($class);
        }
        $tenantColumn = $tenant?->newInstance()->column;

        return new ModelMeta(
            class: $class,
            table: $table->newInstance()->name,
            idProperty: $idProperty,
            idColumn: $columns[$idProperty],
            columns: $columns,
            relations: $relations,
            tenantColumn: $tenantColumn,
            decoder: self::compileDecoder($plan),
            filler: self::compileFiller($class),
            readKey: self::compileKeyReader($class, $idProperty),
            write: self::compileWriter($class),
            read: self::compileReader($class),
            columnReader: self::compileColumnReader($class, $plan),
            beforeSave: self::compileBeforeSave($reflection),
            uniqueKeys: self::compileUniqueKeys($reflection, $columns, $relations, $columns[$idProperty], $tenantColumn),
            createdAtProperty: $createdAt,
        );
    }

    /**
     * Whether a column property holds a moment the ORM can stamp: typed
     * DateTimeImmutable, or the interface it implements, and nothing else --
     * a string or an int column named createdAt is the model's own to fill.
     */
    private static function isTimestamp(\ReflectionProperty $property): bool
    {
        $type = $property->getType();

        return $type instanceof \ReflectionNamedType
            && \in_array($type->getName(), [\DateTimeImmutable::class, \DateTimeInterface::class], true);
    }

    /**
     * The model's #[Unique] keys, each as the columns it names, in the
     * order written: what an upsert may conflict on (ADR 0057).
     *
     * A name is looked up as the query builder looks one up -- a property
     * mapping a column, a #[BelongsTo] property for its foreign key, or a
     * column -- and one that is none of them is refused when the model
     * compiles, not at the first upsert. The key and the tenant column are
     * no part of one: the key is unique already, and the tenant column
     * leads every key of a #[TenantScoped] model by itself.
     *
     * @param \ReflectionClass<Model> $reflection
     * @param array<string, string> $columns property => column
     * @param array<string, RelationMeta> $relations
     * @return list<list<string>>
     */
    private static function compileUniqueKeys(\ReflectionClass $reflection, array $columns, array $relations, string $idColumn, ?string $tenantColumn): array
    {
        $byName = $columns;
        $own = array_values($columns);
        foreach ($relations as $property => $relation) {
            if ($relation->kind === RelationKind::BelongsTo) {
                $byName[$property] = $relation->foreignKey;
                $own[] = $relation->foreignKey;
            }
        }

        $keys = [];
        foreach ($reflection->getAttributes(Unique::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $names = $attribute->newInstance()->columns;
            if ($names === []) {
                throw new MappingException("{$reflection->getName()}: #[Unique] names no column.");
            }

            $key = [];
            foreach ($names as $name) {
                $column = $byName[$name] ?? (\in_array($name, [...$own, $tenantColumn], true) ? $name : null);
                if ($column === null) {
                    throw new MappingException(
                        "{$reflection->getName()}: #[Unique] names '{$name}', which is no column, property or #[BelongsTo] of the model.",
                    );
                }
                if ($column === $idColumn || $column === $tenantColumn) {
                    throw new MappingException(
                        "{$reflection->getName()}: #[Unique] names '{$name}'. The key is unique already, and the tenant "
                        . 'column leads every key of a #[TenantScoped] model by itself.',
                    );
                }
                if (\in_array($column, $key, true)) {
                    throw new MappingException("{$reflection->getName()}: #[Unique] names '{$column}' twice.");
                }
                $key[] = $column;
            }
            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * The generated hydrator's first half: a row's columns as the values of
     * the properties they map to, each cast to its property's type. This,
     * with the filler, is what the spec means by a generated hydrator: no
     * reflection on the hot path.
     *
     * Two halves so that every column is decoded before any is assigned. A
     * value-object column that does not decode then leaves the model as it
     * was -- a ghost still a ghost -- rather than half filled under an
     * identity every holder of it shares.
     *
     * @param list<array{string, string, (\Closure(mixed): mixed)|null, (\Closure(object): mixed)|null}> $plan
     * @return \Closure(array<string, mixed>): array<string, mixed>
     */
    private static function compileDecoder(array $plan): \Closure
    {
        return static function (array $row) use ($plan): array {
            $values = [];
            foreach ($plan as [$property, $column, $cast]) {
                if (!\array_key_exists($column, $row)) {
                    continue;
                }
                $value = $row[$column];
                $values[$property] = ($cast === null || $value === null) ? $value : $cast($value);
            }

            return $values;
        };
    }

    /**
     * The second half: decoded values assigned, from the model's own scope so
     * ORM-owned properties stay `private(set)` to everyone else.
     *
     * @param class-string<Model> $class
     * @return \Closure(Model, array<string, mixed>): void
     */
    private static function compileFiller(string $class): \Closure
    {
        /** @var \Closure(Model, array<string, mixed>): void $bound */
        $bound = \Closure::bind(
            static function (Model $model, array $values): void {
                foreach ($values as $property => $value) {
                    $model->{$property} = $value;
                }
            },
            null,
            $class,
        );

        return $bound;
    }

    /**
     * @param class-string<Model> $class
     * @return \Closure(Model): string
     */
    private static function compileKeyReader(string $class, string $idProperty): \Closure
    {
        /** @var \Closure(Model): string $bound */
        $bound = \Closure::bind(
            static fn (Model $model): string => (string) $model->{$idProperty},
            null,
            $class,
        );

        return $bound;
    }

    /**
     * The inverse of the hydrator: reads each mapped property back out as the
     * value the database stores.
     *
     * Bound to the model's scope like the hydrator, so private(set) fields are
     * readable here without being readable anywhere else.
     *
     * A column with a cast of its own -- a value object's -- is written by
     * it. What this returns is what change tracking compares, so a value
     * object is compared by its stored form: one left alone, or replaced by
     * an equal one, writes nothing.
     *
     * @param class-string<Model> $class
     * @param list<array{string, string, (\Closure(mixed): mixed)|null, (\Closure(object): mixed)|null}> $plan
     * @return \Closure(Model): array<string, mixed>
     */
    private static function compileColumnReader(string $class, array $plan): \Closure
    {
        /** @var \Closure(Model): array<string, mixed> $bound */
        $bound = \Closure::bind(
            static function (Model $model) use ($plan): array {
                $values = [];
                foreach ($plan as [$property, $column, $_cast, $store]) {
                    $reflection = new \ReflectionProperty($model, $property);
                    if (!$reflection->isInitialized($model)) {
                        continue;
                    }

                    $value = $model->{$property};
                    $values[$column] = match (true) {
                        $store !== null && $value !== null => $store($value),
                        // In UTC, to the microsecond, with the offset spelled
                        // out. 'Y-m-d H:i:s' wrote the wall-clock time in
                        // whatever zone the value carried and dropped the
                        // fraction, so a New York time came back hours off
                        // and truncated. The offset is what a timestamptz
                        // column needs to take the instant rather than the
                        // session zone's reading of it; a timestamp column
                        // ignores it, and gets the UTC time.
                        $value instanceof \DateTimeInterface => \DateTimeImmutable::createFromInterface($value)
                            ->setTimezone(new \DateTimeZone('UTC'))
                            ->format('Y-m-d H:i:s.uP'),
                        $value instanceof \BackedEnum => $value->value,
                        \is_array($value) => json_encode($value, JSON_THROW_ON_ERROR),
                        \is_bool($value) => $value ? 1 : 0,
                        default => $value,
                    };
                }

                return $values;
            },
            null,
            $class,
        );

        return $bound;
    }

    /**
     * @param class-string<Model> $class
     * @return \Closure(Model, string, mixed): void
     */
    private static function compileWriter(string $class): \Closure
    {
        /** @var \Closure(Model, string, mixed): void $bound */
        $bound = \Closure::bind(
            static function (Model $model, string $property, mixed $value): void {
                $model->{$property} = $value;
            },
            null,
            $class,
        );

        return $bound;
    }

    /**
     * @param class-string<Model> $class
     * @return \Closure(Model, string): mixed
     */
    private static function compileReader(string $class): \Closure
    {
        /** @var \Closure(Model, string): mixed $bound */
        $bound = \Closure::bind(
            static fn (Model $model, string $property): mixed => $model->{$property},
            null,
            $class,
        );

        return $bound;
    }

    /**
     * A relation's target, which the attribute declares as any class name,
     * checked to be a model when the model is compiled rather than when the
     * relation is first read.
     *
     * @param class-string<Model> $class
     * @return class-string<Model>
     */
    private static function modelTarget(string $class, \ReflectionProperty $property, string $target): string
    {
        if (!is_subclass_of($target, Model::class)) {
            throw MappingException::relationTargetNotModel($class, $property->getName(), $target);
        }

        return $target;
    }

    /** @param class-string<Model> $class */
    private static function relationFor(string $class, \ReflectionProperty $property): ?RelationMeta
    {
        if ($hasMany = ($property->getAttributes(HasMany::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null)) {
            $attribute = $hasMany->newInstance();

            return new RelationMeta(
                kind: RelationKind::HasMany,
                owner: $class,
                property: $property->getName(),
                target: self::modelTarget($class, $property, $attribute->target),
                foreignKey: $attribute->foreignKey ?? Str::snake(Str::shortName($class)) . '_id',
                orderBy: $attribute->orderBy,
            );
        }

        if ($belongsTo = ($property->getAttributes(BelongsTo::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null)) {
            $attribute = $belongsTo->newInstance();
            $target = $attribute->target ?? self::typeOf($property);
            if ($target === null || !is_subclass_of($target, Model::class)) {
                throw MappingException::untypedRelation($class, $property->getName());
            }

            return new RelationMeta(
                kind: RelationKind::BelongsTo,
                owner: $class,
                property: $property->getName(),
                target: $target,
                foreignKey: $attribute->foreignKey ?? Str::snake(Str::shortName($target)) . '_id',
            );
        }

        return null;
    }

    /** @return class-string|null */
    private static function typeOf(\ReflectionProperty $property): ?string
    {
        $type = $property->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $name = $type->getName();

        return class_exists($name) || interface_exists($name) ? $name : null;
    }

    /**
     * The Cast a column maps through, if any: the one #[Column(cast:)] names,
     * or else the property's own type, where that implements Cast. Public for
     * make:migration, which types the column from it.
     *
     * @param class-string $class the model
     * @return class-string<Cast<object>>|null
     */
    public static function castOf(string $class, \ReflectionProperty $property): ?string
    {
        $named = ($property->getAttributes(Column::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null)?->newInstance()->cast;
        $typeName = self::typeOf($property);

        if ($named !== null) {
            if (!is_subclass_of($named, Cast::class)) {
                throw MappingException::notACast($class, $property->getName(), $named);
            }
            // One class, so what fromColumn() returns can be checked to be
            // it before anything is assigned.
            if ($typeName === null) {
                throw MappingException::castNeedsClass($class, $property->getName());
            }

            return $named;
        }

        return $typeName !== null && is_subclass_of($typeName, Cast::class) ? $typeName : null;
    }

    /**
     * How a column is read, and how it is written where the column reader's
     * own rules do not say: [read, store], either null for as it is.
     *
     * @param class-string<Model> $class
     * @return array{(\Closure(mixed): mixed)|null, (\Closure(object): mixed)|null}
     */
    private static function castFor(string $class, \ReflectionProperty $property, string $column): array
    {
        $type = $property->getType();

        // castOf() returns a cast only for a property typed as one class.
        $cast = self::castOf($class, $property);
        $valueClass = self::typeOf($property);
        if ($cast !== null && $valueClass !== null) {
            return self::throughCast($class, $property->getName(), $column, $cast, $valueClass, $type?->allowsNull() ?? false);
        }

        if (!$type instanceof \ReflectionNamedType) {
            return [null, null];
        }

        if (enum_exists($type->getName())) {
            return [self::enumReader($class, $property->getName(), $column, $type->getName()), null];
        }

        return [self::builtinReader($class, $property, $type), null];
    }

    /**
     * A column mapped through a Cast.
     *
     * JSON is decoded here rather than by each cast, so it is always
     * json_decode() into arrays and never unserialize(), which builds
     * whatever object the stored text names. A value that does not decode,
     * that fromColumn() refuses, or that comes back as something else is a
     * MappingException naming the model and column, raised before the row
     * assigns anything (see compileDecoder()).
     *
     * @param class-string<Model> $class
     * @param class-string<Cast<object>> $cast
     * @param class-string $type the property's type, which the cast makes
     * @return array{\Closure(mixed): ?object, \Closure(object): mixed}
     */
    private static function throughCast(string $class, string $property, string $column, string $cast, string $type, bool $nullable): array
    {
        self::assertImmutable($class, $property, $type);
        $json = \in_array(strtolower($cast::columnType()), ['json', 'jsonb'], true);

        $read = static function (mixed $stored) use ($class, $property, $column, $cast, $type, $nullable, $json): ?object {
            // A row put together in PHP rather than read, as hydrateAll()
            // can be handed: the object is already there.
            if ($stored instanceof $type) {
                $value = $stored;
            } else {
                try {
                    $decoded = $json && \is_string($stored) ? json_decode($stored, true, flags: JSON_THROW_ON_ERROR) : $stored;
                } catch (\JsonException $e) {
                    throw MappingException::undecodable($class, $property, $column, $type, 'malformed JSON', $e);
                }

                // JSON's own null in a JSON column: no value, which only a
                // nullable property can hold.
                if ($decoded === null) {
                    return $nullable ? null : throw MappingException::undecodable($class, $property, $column, $type, 'null');
                }

                try {
                    $value = $cast::fromColumn($decoded);
                } catch (\Throwable $e) {
                    throw MappingException::undecodable($class, $property, $column, $type, get_debug_type($decoded), $e);
                }
            }

            if (!$value instanceof $type) {
                throw MappingException::undecodable($class, $property, $column, $type, get_debug_type($value));
            }
            if ($value::class !== $type) {
                self::assertImmutable($class, $property, $value::class);
            }

            return $value;
        };

        // Given what the property holds, never null: of the property's type.
        $store = static function (object $value) use ($class, $property, $cast, $type, $json): mixed {
            // A subclass of the declared type is held to it as well: checked
            // here, once per class, since the type alone cannot say.
            if ($value::class !== $type) {
                self::assertImmutable($class, $property, $value::class);
            }
            $stored = $cast::toColumn($value);

            if ($json) {
                // Zero fractions kept, so a float comes back a float.
                return json_encode($stored, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            }
            if (\is_array($stored)) {
                throw MappingException::castNotScalar($class, $property, $cast);
            }

            return \is_bool($stored) ? (int) $stored : $stored;
        };

        return [$read, $store];
    }

    /**
     * A backed enum, read by value: the case whose value the column holds.
     * The column reader writes ->value back, so change tracking compares the
     * value too. A value no case has is a MappingException naming the model
     * and column, as a value object's is.
     *
     * @param class-string<Model> $class
     * @return \Closure(mixed): \BackedEnum
     */
    private static function enumReader(string $class, string $property, string $column, string $enum): \Closure
    {
        if (!is_subclass_of($enum, \BackedEnum::class)) {
            throw MappingException::unbackedEnum($class, $property, $enum);
        }
        $int = (new \ReflectionEnum($enum))->getBackingType()?->getName() === 'int';

        return static function (mixed $stored) use ($class, $property, $column, $enum, $int): \BackedEnum {
            if ($stored instanceof $enum) {
                return $stored;
            }

            // As the driver read it: an integer column may come back as a
            // numeric string, a text column holding digits as an integer.
            $value = match (true) {
                $int && \is_int($stored), !$int && \is_string($stored) => $stored,
                $int && \is_string($stored) && preg_match('/^-?\d+\z/', $stored) === 1 => (int) $stored,
                !$int && \is_int($stored) => (string) $stored,
                default => null,
            };

            return ($value === null ? null : $enum::tryFrom($value))
                ?? throw MappingException::undecodable($class, $property, $column, $enum, get_debug_type($stored));
        };
    }

    /**
     * Refuses a value class that can change after it is made.
     *
     * Change tracking compares stored forms, so a mutated object would still
     * be written. It is refused anyway because of how it would be: changed
     * through a reference something else kept -- another model it was
     * assigned to, a variable from before -- and written by whichever save()
     * came next, as a change nobody made to the model. Immutable, a column
     * changes only by assignment to the model, which is what the model's
     * changes() then describe.
     *
     * @param class-string<Model> $class
     * @param class-string $valueClass
     */
    private static function assertImmutable(string $class, string $property, string $valueClass): void
    {
        if (!\array_key_exists($valueClass, self::$mutable)) {
            self::$mutable[$valueClass] = false;
            for ($level = new \ReflectionClass($valueClass); $level !== false; $level = $level->getParentClass()) {
                foreach ($level->getProperties() as $declared) {
                    if ($declared->getDeclaringClass()->getName() !== $level->getName()
                        || $declared->isStatic() || $declared->isReadOnly() || $declared->isVirtual()) {
                        continue;
                    }
                    self::$mutable[$valueClass] = "{$level->getName()}::\${$declared->getName()}";
                    break 2;
                }
            }
        }

        $mutable = self::$mutable[$valueClass];
        if ($mutable !== false) {
            throw MappingException::mutableValue($class, $property, $valueClass, $mutable);
        }
    }

    /**
     * The #[BeforeSave] methods, in the order save() calls them -- the
     * class's own top to bottom, then its traits', in the order it uses
     * them, then a parent class's by the same rule (see BeforeSave for why).
     * Within one class that is the order reflection lists its methods in,
     * and under the names the class gave them, so a trait's method imported
     * under an alias is called by its alias.
     *
     * Each is a closure bound to the class that declares it: a private
     * method can be called from there alone, and a parent's private one not
     * from the model at all.
     *
     * @param \ReflectionClass<Model> $reflection
     * @return list<\Closure(Model): void>
     */
    private static function compileBeforeSave(\ReflectionClass $reflection): array
    {
        $hooks = [];
        $registered = [];

        for ($level = $reflection; $level !== false && $level->getName() !== Model::class; $level = $level->getParentClass()) {
            $scope = $level->getName();
            foreach ($level->getMethods() as $method) {
                $name = $method->getName();
                if ($method->getDeclaringClass()->getName() !== $scope || $method->getAttributes(BeforeSave::class, \ReflectionAttribute::IS_INSTANCEOF) === []) {
                    continue;
                }
                // Overridden, it is still one method: called once, from the
                // class lowest down, and what runs is the override.
                if (!$method->isPrivate() && isset($registered[$name])) {
                    continue;
                }
                if ($method->isStatic() || $method->getNumberOfRequiredParameters() > 0) {
                    throw MappingException::unusableHook($reflection->getName(), "{$scope}::{$name}()");
                }
                if (!$method->isPrivate()) {
                    $registered[$name] = true;
                }

                /** @var \Closure(Model): void $hook */
                $hook = \Closure::bind(static function (Model $model) use ($name): void {
                    $model->{$name}();
                }, null, $scope);
                $hooks[] = $hook;
            }
        }

        return $hooks;
    }

    /**
     * @param class-string<Model> $class
     * @return (\Closure(mixed): mixed)|null
     */
    private static function builtinReader(string $class, \ReflectionProperty $property, \ReflectionNamedType $type): ?\Closure
    {
        return match ($type->getName()) {
            'string' => static fn (mixed $v): string => (string) $v,
            'int' => static fn (mixed $v): int => (int) $v,
            'float' => static fn (mixed $v): float => (float) $v,
            'bool' => static fn (mixed $v): bool => (bool) $v,
            'array' => static fn (mixed $v): array => \is_array($v)
                ? $v
                : (json_decode((string) $v, true, flags: JSON_THROW_ON_ERROR) ?: []),
            // As UTC, the zone the writer stores. A value with no offset -- a
            // Postgres timestamp, or a row written before the offset was --
            // was read in the default zone; one with an offset is converted.
            \DateTimeImmutable::class => static fn (mixed $v): \DateTimeImmutable => $v instanceof \DateTimeImmutable
                ? $v
                : (new \DateTimeImmutable((string) $v, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC')),
            'mixed' => null,
            default => throw MappingException::unsupportedColumnType($class, $property->getName(), $type->getName()),
        };
    }
}
