<?php

declare(strict_types=1);

namespace Tinvar\Orm;

use Carbon\CarbonImmutable;

/**
 * Compiled metadata for one model: table, columns, relations, a hydrator and
 * the hooks a save runs.
 *
 * Reflection happens once, here. Production never reflects
 * (tinvar-spec.md, "Engine layers").
 */
final class ModelMeta
{
    /** @var \WeakMap<Model, array<string, true>> the models whose hooks are running, with the kinds of hook */
    private \WeakMap $running;

    /**
     * @param class-string<Model>          $class
     * @param array<string, string>        $columns  property => column
     * @param array<string, RelationMeta>  $relations property => relation
     * @param \Closure(array<string, mixed>): array<string, mixed> $decoder row => property values
     * @param \Closure(Model, array<string, mixed>): void $filler
     * @param \Closure(Model): string      $readKey
     * @param \Closure(Model, string, mixed): void $write
     * @param \Closure(Model, string): mixed      $read
     * @param list<\Closure(Model): void>  $beforeSave the #[BeforeSave] methods, in the order they run
     * @param list<\Closure(Model): void>  $afterSave the #[AfterSave] methods, likewise
     * @param list<\Closure(Model): void>  $beforeDelete the #[BeforeDelete] methods, likewise
     * @param list<\Closure(Model): void>  $afterDelete the #[AfterDelete] methods, likewise
     * @param list<list<string>>           $uniqueKeys each #[Unique] key, as its columns, without the tenant column
     * @param string|null                  $createdAtProperty a `createdAt` column typed as a moment, which
     *        the ORM stamps on insert when nothing has set it (Orm::now())
     * @param string|null                  $updatedAtProperty an `updatedAt` column typed as a moment, which
     *        the ORM stamps on insert and on every update unless that write sets it
     * @param list<string>                 $carbonStamps of those two, the ones typed CarbonImmutable
     * @param AuditedColumns|null          $audited what the audit log records of the model's rows, for an
     *        #[Audited] one; null for a model whose changes are recorded nowhere (ADR 0156)
     */
    public function __construct(
        public readonly string $class,
        public readonly string $table,
        public readonly string $idProperty,
        public readonly string $idColumn,
        public readonly array $columns,
        public readonly array $relations,
        public readonly ?string $tenantColumn,
        private readonly \Closure $decoder,
        private readonly \Closure $filler,
        private readonly \Closure $readKey,
        private readonly \Closure $write,
        private readonly \Closure $read,
        private readonly \Closure $columnReader,
        private readonly array $beforeSave = [],
        public readonly array $uniqueKeys = [],
        public readonly ?string $createdAtProperty = null,
        public readonly ?string $updatedAtProperty = null,
        private readonly array $afterSave = [],
        private readonly array $beforeDelete = [],
        private readonly array $afterDelete = [],
        private readonly array $carbonStamps = [],
        public readonly ?AuditedColumns $audited = null,
    ) {
        $this->running = new \WeakMap();
    }

    /**
     * Sets a moment the ORM stamps -- createdAt, updatedAt -- to $now, as
     * the class the property is typed: a CarbonImmutable for one typed so,
     * a DateTimeImmutable for any other, in UTC either way.
     */
    public function stamp(Model $model, string $property, \DateTimeImmutable $now): void
    {
        $now = $now->setTimezone(new \DateTimeZone('UTC'));
        $this->set($model, $property, match (true) {
            \in_array($property, $this->carbonStamps, true) => CarbonImmutable::instance($now),
            $now::class === \DateTimeImmutable::class => $now,
            default => \DateTimeImmutable::createFromInterface($now),
        });
    }

    /**
     * Whether a save runs #[BeforeSave] methods: what a write through a
     * query, which makes no model to run them on, refuses to write past.
     */
    public function hasBeforeSave(): bool
    {
        return $this->beforeSave !== [];
    }

    /** Whether a save runs #[AfterSave] methods once its row is written. */
    public function hasAfterSave(): bool
    {
        return $this->afterSave !== [];
    }

    /** Whether a delete runs #[BeforeDelete] or #[AfterDelete] methods. */
    public function hasDeleteHooks(): bool
    {
        return $this->beforeDelete !== [] || $this->afterDelete !== [];
    }

    /**
     * The #[Unique] key whose columns are $columns, in any order, with the
     * tenant column leading it on a #[TenantScoped] model: an upsert's
     * conflict target. Null where the model declares no such key.
     *
     * @param list<string> $columns
     * @return non-empty-list<string>|null
     */
    public function conflictTarget(array $columns): ?array
    {
        $wanted = $columns;
        sort($wanted);
        foreach ($this->uniqueKeys as $key) {
            $sorted = $key;
            sort($sorted);
            if ($sorted === $wanted && $key !== []) {
                return $this->tenantColumn === null ? $key : [$this->tenantColumn, ...$key];
            }
        }

        return null;
    }

    /**
     * The model's declared column values, as the database should see them.
     *
     * The inverse of the hydrator. Foreign keys are not declared columns;
     * writeValues() adds them.
     *
     * A moment is written in $storage's form; unsaid, in the form of the
     * connection the model was read or saved through, or with its offset
     * spelled out for a model that has been through none.
     *
     * @return array<string, mixed> column => value
     */
    public function columnValues(Model $model, ?TimestampStorage $storage = null): array
    {
        return ($this->columnReader)($model, $storage ?? $model->orm()?->timestampStorage() ?? TimestampStorage::WithOffset);
    }

    /**
     * Column values plus the foreign keys single relations own: everything a
     * write sends, and so the basis of change tracking. The snapshot taken on
     * hydrate is this, and save() writes what differs from it.
     *
     * A #[BelongsTo] property is not a declared column, but the key it stands
     * for is a real column and has to be written -- otherwise an insert leaves
     * it null and the row is unlinked, and a row moved to another parent
     * stays where it was.
     *
     * @return array<string, mixed> column => value
     */
    public function writeValues(Model $model, ?TimestampStorage $storage = null): array
    {
        $values = $this->columnValues($model, $storage);

        foreach ($this->relations as $property => $relation) {
            if ($relation->kind !== RelationKind::BelongsTo) {
                continue;
            }

            $values[$relation->foreignKey] = $this->foreignKeyOf($model, $property, $relation);
        }

        return $values;
    }

    /**
     * The key a single relation stands for, without forcing it to load.
     *
     * The property holds a lazy ghost until something reads it. Reading it
     * here to ask for its id would make every hydrate issue a query for every
     * belongsTo -- the exact 1+N the lazy ghost exists to avoid -- so the
     * ghost hydration installed yields the key captured from the row instead.
     */
    private function foreignKeyOf(Model $model, string $property, RelationMeta $relation): mixed
    {
        if (!(new \ReflectionProperty($this->class, $property))->isInitialized($model)) {
            return $model->foreignKey($property);
        }

        $related = $this->get($model, $property);

        // Set to null: a nullable relation cleared, whatever the row said.
        if (!$related instanceof Model) {
            return null;
        }

        if (!(new \ReflectionClass($relation->target))->isUninitializedLazyObject($related)) {
            return $related->primaryKey();
        }

        // Still a ghost. The one hydration installed stands for the key the
        // row named. Any other -- another row's relation, assigned here before
        // anything read it -- stands for a key only loading it can tell, and
        // taking the row's key for it would silently undo the move.
        return $related === $model->installedRelation($property)
            ? $model->foreignKey($property)
            : $related->primaryKey();
    }

    /** @param array<string, mixed> $row */
    public function hydrate(Model $model, array $row): void
    {
        ($this->filler)($model, ($this->decoder)($row));
    }

    /**
     * A row's columns as the values of the properties they map to, cast but
     * not yet assigned: a row that does not decode throws here and leaves
     * every model as it was.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed> property => value
     */
    public function decode(array $row): array
    {
        return ($this->decoder)($row);
    }

    /**
     * Assigns what decode() returned.
     *
     * @param array<string, mixed> $values property => value
     */
    public function fill(Model $model, array $values): void
    {
        ($this->filler)($model, $values);
    }

    /**
     * Runs the model's #[BeforeSave] methods, in their compiled order.
     *
     * A hook that saves its own model would run the hooks again, and again,
     * until the stack ran out; it is refused at once instead. Saving another
     * model from a hook is fine.
     */
    public function beforeSave(Model $model): void
    {
        $this->run('BeforeSave', $this->beforeSave, $model, 'saved');
    }

    /** Runs the model's #[AfterSave] methods, refusing one that saves its own model. */
    public function afterSave(Model $model): void
    {
        $this->run('AfterSave', $this->afterSave, $model, 'saved');
    }

    /** Runs the model's #[BeforeDelete] methods, refusing one that deletes its own model. */
    public function beforeDelete(Model $model): void
    {
        $this->run('BeforeDelete', $this->beforeDelete, $model, 'deleted');
    }

    /** Runs the model's #[AfterDelete] methods, refusing one that deletes its own model. */
    public function afterDelete(Model $model): void
    {
        $this->run('AfterDelete', $this->afterDelete, $model, 'deleted');
    }

    /**
     * Runs one kind of hook on $model in its compiled order, refusing the
     * model's own save or delete from inside one: it would run the same
     * hooks again, without end.
     *
     * @param list<\Closure(Model): void> $hooks
     */
    private function run(string $kind, array $hooks, Model $model, string $did): void
    {
        if ($hooks === []) {
            return;
        }
        $kinds = $this->running[$model] ?? [];
        if (isset($kinds[$kind])) {
            throw new \LogicException("A #[{$kind}] hook of {$this->class} {$did} its own model, which would run the hooks without end.");
        }

        $this->running[$model] = [...$kinds, $kind => true];
        try {
            foreach ($hooks as $hook) {
                $hook($model);
            }
        } finally {
            $left = $this->running[$model] ?? [];
            unset($left[$kind]);
            if ($left === []) {
                unset($this->running[$model]);
            } else {
                $this->running[$model] = $left;
            }
        }
    }

    public function keyOf(Model $model): string
    {
        return ($this->readKey)($model);
    }

    /**
     * Declared columns plus the foreign keys single relations need.
     *
     * A #[BelongsTo] property is not itself a column, but the key it reads has
     * to come back in the row or the relation has nothing to resolve from.
     *
     * @return list<string>
     */
    public function selectColumns(): array
    {
        $columns = array_values($this->columns);

        foreach ($this->relations as $relation) {
            if ($relation->kind === RelationKind::BelongsTo && !\in_array($relation->foreignKey, $columns, true)) {
                $columns[] = $relation->foreignKey;
            }
        }

        return $columns;
    }

    /**
     * Writes a property from the model's own scope, so relation properties and
     * ORM-owned fields stay closed to everyone else.
     */
    public function set(Model $model, string $property, mixed $value): void
    {
        ($this->write)($model, $property, $value);
    }

    public function get(Model $model, string $property): mixed
    {
        return ($this->read)($model, $property);
    }

    /**
     * Every column name this model may be queried by.
     *
     * The query builder refuses anything outside this set, so an identifier
     * cannot inject SQL or name a column the model does not map. It is not an
     * allowlist for request input: every mapped column is in it, including
     * ones no Data class exposes -- a password hash, an API token -- and
     * sorting by one of those reveals its order, which is enough to recover
     * it. A sort or filter field from a request has to be narrowed first, to
     * a backed enum or an #[In] list of the columns the page shows
     * (tinvar-spec.md, "Identifiers from users").
     *
     * @return list<string>
     */
    public function knownIdentifiers(): array
    {
        $known = $this->selectColumns();

        if ($this->tenantColumn !== null && !\in_array($this->tenantColumn, $known, true)) {
            $known[] = $this->tenantColumn;
        }

        foreach ($this->relations as $relation) {
            if (!\in_array($relation->foreignKey, $known, true)) {
                $known[] = $relation->foreignKey;
            }
        }

        return $known;
    }
}
