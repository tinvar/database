<?php

declare(strict_types=1);

namespace Tinvar\Orm;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Tinvar\Orm\Attributes\AfterDelete;
use Tinvar\Orm\Attributes\AfterSave;
use Tinvar\Orm\Attributes\BeforeDelete;
use Tinvar\Orm\Attributes\BeforeSave;
use Tinvar\Orm\Attributes\BelongsTo;
use Tinvar\Orm\Attributes\BelongsToMany;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\HasMany;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\Audited;
use Tinvar\Orm\Attributes\SoftDeletes;
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
     * Classes that never change once made, taken at their word since their
     * state is no property reflection sees as readonly: PHP's own, and
     * Carbon's immutable moment, whose every change returns a copy (ADR 0145).
     */
    private const array IMMUTABLE_CLASSES = [\DateTimeImmutable::class, \DateTimeZone::class, 'BcMath\Number', CarbonImmutable::class];

    /**
     * Moments that change in place, by type: refused as a column's type, with
     * the immutable one to use instead. CarbonInterface is here because
     * mutable Carbon implements it, and a property typed by it takes one.
     */
    private const array MUTABLE_MOMENTS = [\DateTime::class, \DateTimeInterface::class, Carbon::class, CarbonInterface::class];

    /** How deep a value object's own values are followed before it is refused. */
    private const int IMMUTABLE_DEPTH = 32;

    /**
     * By value class, why it is mutable -- a property it or a class it holds
     * declares that is not readonly, at any depth -- or false; and the
     * properties whose types cannot vouch for what they hold, checked on
     * each value (immutability()).
     *
     * @var array<string, array{string|false, list<array{class-string, string}>, int|null}>
     */
    private static array $immutability = [];

    /** @param class-string<Model> $class */
    public static function for(string $class): ModelMeta
    {
        return self::$compiled[$class] ??= self::compile($class);
    }

    /** A plain SQL identifier, as Builder\Identifiers takes one. */
    private const string IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]{0,62}\z/';

    /**
     * Every name an attribute gives that the ORM writes into SQL -- the
     * table, each column, the tenant column, each relation's key -- held
     * to a plain identifier when the model compiles (ADR 0208). Quoted in
     * many places by sprintf() alone, a name with a quote in it would
     * otherwise end the identifier and write SQL of its own; checked here
     * once, none reaches any of them.
     *
     * @param array<string, string> $columns
     * @param array<string, RelationMeta> $relations
     */
    private static function plainNames(string $table, array $columns, ?string $tenantColumn, array $relations): void
    {
        $names = [$table, ...array_values($columns)];
        if ($tenantColumn !== null) {
            $names[] = $tenantColumn;
        }
        foreach ($relations as $relation) {
            $names[] = $relation->foreignKey;
            if ($relation->orderBy !== null) {
                $names[] = $relation->orderBy;
            }
        }
        foreach ($names as $name) {
            if (preg_match(self::IDENTIFIER, $name) !== 1) {
                throw MappingException::malformedIdentifier($name);
            }
        }
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
        // Read through the whole hierarchy: written on an abstract base or a
        // trait, the attribute holds the model as surely as on the model.
        $tenant = TenantScoped::on($reflection);

        $columns = [];
        $relations = [];
        $idProperty = null;
        $createdAt = null;
        $updatedAt = null;
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
            } elseif ($name === 'updatedAt' && self::isTimestamp($property)) {
                $updatedAt = $name;
            }
        }

        if ($idProperty === null) {
            throw MappingException::noId($class);
        }
        $tenantColumn = $tenant?->column;
        self::plainNames($table->newInstance()->name, $columns, $tenantColumn, $relations);
        $audited = Audited::on($reflection);
        $deletedAt = self::deletedAtOf($reflection, $columns, $idProperty);

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
            beforeSave: self::compileHooks($reflection, BeforeSave::class),
            uniqueKeys: self::compileUniqueKeys($reflection, $columns, $relations, $columns[$idProperty], $tenantColumn),
            createdAtProperty: $createdAt,
            updatedAtProperty: $updatedAt,
            afterSave: self::compileHooks($reflection, AfterSave::class),
            beforeDelete: self::compileHooks($reflection, BeforeDelete::class),
            afterDelete: self::compileHooks($reflection, AfterDelete::class),
            carbonStamps: array_values(array_filter(
                [$createdAt, $updatedAt, $deletedAt],
                static fn (?string $stamped): bool => $stamped !== null
                    && ltrim((string) $reflection->getProperty($stamped)->getType(), '?') === CarbonImmutable::class,
            )),
            audited: $audited === null ? null : AuditedColumns::compile(
                $class,
                $audited,
                $columns,
                $relations,
                $columns[$idProperty],
                $tenantColumn,
                array_values(array_filter([$createdAt, $updatedAt, $deletedAt], static fn (?string $stamp): bool => $stamp !== null)),
            ),
            deletedAtProperty: $deletedAt,
            deletedAtColumn: $deletedAt === null ? null : $columns[$deletedAt],
        );
    }

    /**
     * The property a #[SoftDeletes] model maps its trashed moment to, by the
     * column the attribute names; null for a model that does not soft
     * delete. The property must hold a moment or none -- a nullable
     * CarbonImmutable or DateTimeImmutable -- since a live row holds none,
     * and it is refused when the model compiles rather than at the first
     * delete.
     *
     * @param \ReflectionClass<Model> $reflection
     * @param array<string, string> $columns property => column
     */
    private static function deletedAtOf(\ReflectionClass $reflection, array $columns, string $idProperty): ?string
    {
        $declared = SoftDeletes::on($reflection);
        if ($declared === null) {
            return null;
        }
        $class = $reflection->getName();
        $property = array_search($declared->column, $columns, true);
        if (!\is_string($property) || $property === $idProperty) {
            throw new MappingException(
                "{$class} is #[SoftDeletes], and maps no property to its '{$declared->column}' column: declare "
                . '#[Column] public ?CarbonImmutable $deletedAt = null'
                . ($declared->column === 'deleted_at' ? '.' : " with #[Column('{$declared->column}')]."),
            );
        }
        $reflected = $reflection->getProperty($property);
        $type = $reflected->getType();
        if (!self::isTimestamp($reflected) || $type === null || !$type->allowsNull()) {
            throw new MappingException(
                "{$class}::\${$property} is the #[SoftDeletes] column, which holds no moment while the row is live: type it a nullable "
                . 'CarbonImmutable (or DateTimeImmutable), ?CarbonImmutable.',
            );
        }

        return $property;
    }

    /**
     * Whether a column property holds a moment the ORM can stamp: typed
     * DateTimeImmutable or CarbonImmutable, and nothing else -- a string or
     * an int column named createdAt or updatedAt is the model's own to fill.
     */
    private static function isTimestamp(\ReflectionProperty $property): bool
    {
        $type = $property->getType();

        return $type instanceof \ReflectionNamedType
            && \in_array($type->getName(), [\DateTimeImmutable::class, CarbonImmutable::class], true);
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
     * A moment is written in UTC, to the microsecond, in the form the
     * dialect keeps one ($storage, TimestampStorage). 'Y-m-d H:i:s' wrote
     * the wall-clock time in whatever zone the value carried and dropped the
     * fraction, so a New York time came back hours off and truncated.
     *
     * @param class-string<Model> $class
     * @param list<array{string, string, (\Closure(mixed): mixed)|null, (\Closure(object): mixed)|null}> $plan
     * @return \Closure(Model, TimestampStorage): array<string, mixed>
     */
    private static function compileColumnReader(string $class, array $plan): \Closure
    {
        /** @var \Closure(Model, TimestampStorage): array<string, mixed> $bound */
        $bound = \Closure::bind(
            static function (Model $model, TimestampStorage $storage) use ($plan): array {
                $values = [];
                foreach ($plan as [$property, $column, $_cast, $store]) {
                    $reflection = new \ReflectionProperty($model, $property);
                    if (!$reflection->isInitialized($model)) {
                        continue;
                    }

                    $value = $model->{$property};
                    $values[$column] = match (true) {
                        $store !== null && $value !== null => $store($value),
                        $value instanceof \DateTimeInterface => $storage->format($value),
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

        if ($belongsToMany = ($property->getAttributes(BelongsToMany::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null)) {
            $attribute = $belongsToMany->newInstance();
            $target = self::modelTarget($class, $property, $attribute->target);
            $pivot = self::pivotFor($class, $property, $target, $attribute);

            return new RelationMeta(
                kind: RelationKind::BelongsToMany,
                owner: $class,
                property: $property->getName(),
                target: $target,
                foreignKey: $pivot->ownerColumn,
                orderBy: $attribute->orderBy,
                pivot: $pivot,
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
                onDelete: self::deleteAction($class, $property, $attribute->onDelete),
            );
        }

        return null;
    }

    /**
     * The pivot a #[BelongsToMany] goes through, checked when the model is
     * compiled (ADR 0192): a model, with a #[BelongsTo] to each side -- the
     * one of each type, or the ones `from:` and `to:` name -- and held to
     * the tenant whenever either side is. A pivot that is not would let
     * one tenant's row be linked to another's, and be read across the line:
     * refused, as failing closed.
     *
     * The pivot's own metadata is compiled here, which reads only its own
     * attributes: a #[BelongsTo] names its target without compiling it.
     *
     * @param class-string<Model> $class
     * @param class-string<Model> $target
     */
    private static function pivotFor(string $class, \ReflectionProperty $property, string $target, BelongsToMany $attribute): PivotMeta
    {
        $where = "{$class}::\${$property->getName()}";
        if (!is_subclass_of($attribute->through, Model::class)) {
            throw new MappingException("{$where}: #[BelongsToMany] goes through {$attribute->through}, which is not a model. Name the pivot's model in through:.");
        }
        $pivot = self::for($attribute->through);

        $side = static function (?string $named, string $model, string $which) use ($pivot, $where, $attribute): RelationMeta {
            if ($named !== null) {
                $relation = $pivot->relations[$named] ?? null;
                if ($relation === null || $relation->kind !== RelationKind::BelongsTo || $relation->target !== $model) {
                    throw new MappingException("{$where}: {$which}: '{$named}' is no #[BelongsTo] of {$attribute->through} to {$model}.");
                }

                return $relation;
            }
            $found = array_values(array_filter(
                $pivot->relations,
                static fn (RelationMeta $r): bool => $r->kind === RelationKind::BelongsTo && $r->target === $model,
            ));
            if ($found === []) {
                throw new MappingException("{$where}: the pivot {$attribute->through} has no #[BelongsTo] to {$model}.");
            }
            if (\count($found) > 1) {
                throw new MappingException(
                    "{$where}: the pivot {$attribute->through} has " . \count($found) . " #[BelongsTo]s to {$model}; name them with from: and to:.",
                );
            }

            return $found[0];
        };
        $owner = $side($attribute->from, $class, 'from');
        $other = $side($attribute->to, $target, 'to');
        if ($owner->property === $other->property) {
            throw new MappingException("{$where}: from: and to: name the same #[BelongsTo] of {$attribute->through}; name one for each side.");
        }

        if ($pivot->tenantColumn === null && (TenantScoped::on($class) !== null || TenantScoped::on($target) !== null)) {
            throw new MappingException(
                "{$where}: {$attribute->through} is not #[TenantScoped], and links rows that are: its rows would be "
                . "read and written across tenants. Make the pivot #[TenantScoped].",
            );
        }

        return new PivotMeta($attribute->through, $owner->property, $owner->foreignKey, $other->property, $other->foreignKey);
    }

    /**
     * Every compiled #[BelongsToMany] through the pivot $class: the relations
     * a write to one of its rows changes (ADR 0192).
     *
     * @param class-string<Model> $class
     * @return list<RelationMeta>
     */
    public static function relationsThrough(string $class): array
    {
        $found = [];
        foreach (self::$compiled as $meta) {
            foreach ($meta->relations as $relation) {
                if ($relation->pivot?->class === $class) {
                    $found[] = $relation;
                }
            }
        }

        return $found;
    }

    /**
     * A #[BelongsTo]'s delete action, as SQL spells it -- any case, any
     * spacing -- or null for none. SET NULL on a property that is never
     * null would leave a row the model cannot read: refused.
     *
     * @param class-string<Model> $class
     * @return value-of<RelationMeta::DELETE_ACTIONS>|null
     */
    private static function deleteAction(string $class, \ReflectionProperty $property, OnDelete|string|null $onDelete): ?string
    {
        if ($onDelete === null) {
            return null;
        }
        $action = $onDelete instanceof OnDelete ? $onDelete->value : strtoupper(trim((string) preg_replace('/\s+/', ' ', $onDelete)));
        foreach (RelationMeta::DELETE_ACTIONS as $known) {
            if ($action !== $known) {
                continue;
            }
            if ($known === 'SET NULL' && !($property->getType()?->allowsNull() ?? false)) {
                throw new MappingException("{$class}::\${$property->getName()} is never null, so its #[BelongsTo] cannot be set to null on delete.");
            }

            return $known;
        }

        throw new MappingException(
            "{$class}::\${$property->getName()}: '" . (\is_string($onDelete) ? $onDelete : $action) . "' is no ON DELETE action; name one of " . implode(', ', array_map(strtolower(...), RelationMeta::DELETE_ACTIONS)) . '.',
        );
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

        // A moment that can change in place would change the column through
        // whatever else holds it, as any mutable value would (ADR 0122).
        foreach (self::MUTABLE_MOMENTS as $mutable) {
            if (strcasecmp($type->getName(), $mutable) === 0 || is_subclass_of($type->getName(), \DateTime::class)) {
                throw MappingException::mutableMoment($class, $property->getName(), $type->getName());
            }
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
            self::assertImmutableValue($class, $property, $value);

            return $value;
        };

        // Given what the property holds, never null: of the property's type.
        $store = static function (object $value) use ($class, $property, $cast, $json): mixed {
            // A subclass of the declared type is held to it as well, and so
            // is what a property holds -- an array, an interface, a class
            // that may be extended -- and whether each is initialized:
            // checked here, since the types alone cannot say.
            self::assertImmutableValue($class, $property, $value);
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
     * All the way down, not one level as PHP's readonly is: a readonly
     * property holding a mutable object -- a DateTime, a class with a
     * property that is not readonly -- is a value that changes in place as
     * surely as one of its own (ADR 0122). What the types settle is settled
     * once per class; what they cannot is checked on each value
     * (assertImmutableValue()).
     *
     * @param class-string<Model> $class
     * @param class-string $valueClass
     */
    private static function assertImmutable(string $class, string $property, string $valueClass): void
    {
        $mutable = self::immutability($valueClass)[0];
        if ($mutable !== false) {
            throw MappingException::mutableValue($class, $property, $valueClass, $mutable);
        }
    }

    /**
     * Refuses a value that can change after it is made: its class, by
     * assertImmutable()'s rule, and then what its loose properties hold --
     * each object held to its own class, each array walked, and an array
     * element held by reference refused, since writing the variable it
     * shares changes the array.
     *
     * @param class-string<Model> $class
     */
    private static function assertImmutableValue(string $class, string $property, object $value): void
    {
        $why = self::mutableIn($value, 0);
        if ($why !== null) {
            throw MappingException::mutableValue($class, $property, $value::class, $why);
        }
    }

    /** Why $value can change, or null where it cannot. */
    private static function mutableIn(mixed $value, int $depth): ?string
    {
        // Deeper than any value a column holds: a structure built to make
        // this walk forever is refused, not followed.
        if ($depth > self::IMMUTABLE_DEPTH) {
            return 'it nests deeper than ' . self::IMMUTABLE_DEPTH . ' levels, past what is checked';
        }

        if (\is_array($value)) {
            foreach ($value as $key => $item) {
                if (\ReflectionReference::fromArrayElement($value, $key) !== null) {
                    return 'an array in it holds an element by reference, which changes with the variable it shares';
                }
                $why = self::mutableIn($item, $depth + 1);
                if ($why !== null) {
                    return $why;
                }
            }

            return null;
        }
        if (!\is_object($value)) {
            return null;
        }

        [$mutable, $loose, $properties] = self::immutability($value::class);
        if ($mutable !== false) {
            return $depth === 0 ? $mutable : 'it holds a ' . $value::class . ", and {$mutable}";
        }
        // A readonly property left uninitialized may still be set, once,
        // from inside its class: the value would change after it was made.
        // Every property it declares is held, so a count of those it holds
        // says so -- and one more says it holds one its class does not.
        if ($properties !== null && \count(get_mangled_object_vars($value)) !== $properties) {
            $what = \count(get_mangled_object_vars($value)) < $properties ? 'a property of it is not initialized' : 'it holds a property its class does not declare';

            return $depth === 0 ? $what : 'it holds a ' . $value::class . ", and {$what}";
        }
        foreach ($loose as [$declaring, $name]) {
            $held = new \ReflectionProperty($declaring, $name);
            $why = self::mutableIn($held->getValue($value), $depth + 1);
            if ($why !== null) {
                return $why;
            }
        }

        return null;
    }

    /**
     * What a class's types say of its immutability, worked out once: why it
     * is mutable -- a property that is not readonly, or one whose type is a
     * mutable class, at any depth -- or false; and its loose properties,
     * whose types cannot vouch for what they hold: an array, an object or
     * mixed, an interface or a class that may be extended, or a class with
     * loose properties of its own. Those are checked on each value.
     *
     * A class PHP declares is immutable only by name (IMMUTABLE_CLASSES):
     * its state is no property reflection can see. A class that refers to
     * itself, at any depth, is judged where the walk began.
     *
     * @param class-string $valueClass
     * @param array<string, true> $visiting the classes the walk is inside
     * @return array{string|false, list<array{class-string, string}>, int|null}
     */
    private static function immutability(string $valueClass, array $visiting = []): array
    {
        if (isset(self::$immutability[$valueClass])) {
            return self::$immutability[$valueClass];
        }
        if (isset($visiting[$valueClass])) {
            return [false, [], null];
        }
        $visiting[$valueClass] = true;

        $reflection = new \ReflectionClass($valueClass);
        $judged = [false, [], null];
        if (!$reflection->isEnum() && !\in_array($valueClass, self::IMMUTABLE_CLASSES, true)) {
            $judged = self::judge($reflection, $visiting);
        }

        // Only a walk that began here is kept: one inside a cycle may have
        // passed over a class the walk had not finished with.
        if (\count($visiting) === 1) {
            self::$immutability[$valueClass] = $judged;
        }

        return $judged;
    }

    /**
     * @param \ReflectionClass<object> $reflection
     * @param array<string, true> $visiting
     * @return array{string|false, list<array{class-string, string}>, int|null}
     */
    private static function judge(\ReflectionClass $reflection, array $visiting): array
    {
        $loose = [];
        // Every instance property it declares, counted, for the check that
        // each is initialized; none to count past a class of PHP's own,
        // whose state is no property.
        $properties = 0;
        for ($level = $reflection; $level !== false; $level = $level->getParentClass()) {
            $name = $level->getName();
            if (\in_array($name, self::IMMUTABLE_CLASSES, true)) {
                $properties = null;
                break;
            }
            if ($level->isInternal()) {
                return ["{$name}, a class of PHP's own whose state is out of reflection's sight, may change in place", [], null];
            }
            foreach ($level->getProperties() as $declared) {
                if ($declared->getDeclaringClass()->getName() !== $name || $declared->isStatic() || $declared->isVirtual()) {
                    continue;
                }
                $at = "{$name}::\${$declared->getName()}";
                $properties++;
                if (!$declared->isReadOnly()) {
                    return ["{$at} is not readonly", [], null];
                }
                foreach (self::typesOf($declared->getType()) as $type) {
                    if ($type->isBuiltin()) {
                        if (\in_array($type->getName(), ['array', 'iterable', 'object', 'mixed'], true)) {
                            $loose[] = [$name, $declared->getName()];
                        }
                        continue;
                    }
                    $held = match ($type->getName()) {
                        'self', 'static' => $reflection->getName(),
                        'parent' => $level->getParentClass() === false ? $name : $level->getParentClass()->getName(),
                        default => $type->getName(),
                    };
                    if (!class_exists($held) && !interface_exists($held) && !enum_exists($held)) {
                        return ["{$at} is typed {$held}, which is no class", [], null];
                    }
                    [$mutable] = self::immutability($held, $visiting);
                    if ($mutable !== false) {
                        return ["{$at} holds {$held}, and {$mutable}", [], null];
                    }
                    // Walked on each value, whatever the class: its own
                    // properties have to be initialized too.
                    if (!enum_exists($held)) {
                        $loose[] = [$name, $declared->getName()];
                    }
                }
            }
        }

        return [false, array_values(array_unique($loose, SORT_REGULAR)), $properties];
    }

    /** @return list<\ReflectionNamedType> every type a property's declaration names */
    private static function typesOf(?\ReflectionType $type): array
    {
        if ($type instanceof \ReflectionNamedType) {
            return [$type];
        }
        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            $named = [];
            foreach ($type->getTypes() as $member) {
                array_push($named, ...self::typesOf($member));
            }

            return $named;
        }

        return [];
    }

    /**
     * The methods marked with one hook attribute -- #[BeforeSave],
     * #[AfterSave], #[BeforeDelete] or #[AfterDelete] -- in the order save()
     * or delete() calls them: the class's own top to bottom, then its
     * traits', in the order it uses them, then a parent class's by the same
     * rule (see BeforeSave for why). A method may carry more than one.
     * Within one class that is the order reflection lists its methods in,
     * and under the names the class gave them, so a trait's method imported
     * under an alias is called by its alias.
     *
     * Each is a closure bound to the class that declares it: a private
     * method can be called from there alone, and a parent's private one not
     * from the model at all.
     *
     * @param \ReflectionClass<Model> $reflection
     * @param class-string $attribute
     * @return list<\Closure(Model): void>
     */
    private static function compileHooks(\ReflectionClass $reflection, string $attribute): array
    {
        $hooks = [];
        $registered = [];

        for ($level = $reflection; $level !== false && $level->getName() !== Model::class; $level = $level->getParentClass()) {
            $scope = $level->getName();
            foreach ($level->getMethods() as $method) {
                $name = $method->getName();
                if ($method->getDeclaringClass()->getName() !== $scope || $method->getAttributes($attribute, \ReflectionAttribute::IS_INSTANCEOF) === []) {
                    continue;
                }
                // Overridden, it is still one method: called once, from the
                // class lowest down, and what runs is the override.
                if (!$method->isPrivate() && isset($registered[$name])) {
                    continue;
                }
                if ($method->isStatic() || $method->getNumberOfRequiredParameters() > 0) {
                    throw MappingException::unusableHook($reflection->getName(), "{$scope}::{$name}()", Str::shortName($attribute));
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
            // Carbon's, likewise, and in UTC whatever it was read as: read
            // as the DateTimeImmutable above reads it and then made a
            // Carbon, since Carbon's constructor, like parse(), reads a
            // relative time -- 'tomorrow' -- against a clock a test moved.
            CarbonImmutable::class => static fn (mixed $v): CarbonImmutable => CarbonImmutable::instance(
                $v instanceof \DateTimeInterface ? $v : new \DateTimeImmutable((string) $v, new \DateTimeZone('UTC')),
            )->setTimezone('UTC'),
            'mixed' => null,
            default => throw MappingException::unsupportedColumnType($class, $property->getName(), $type->getName()),
        };
    }
}
