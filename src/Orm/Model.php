<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * Base model. Columns are declared on the class, relations and behaviour in
 * traits (tinvar-spec.md, "Models").
 *
 * Phase 0 implements hydration, identity and lazy relations. Writes, change
 * tracking, scopes and the query builder are later phases; the brief asks for
 * the ORM to stay as small as the prototype needs.
 */
abstract class Model
{
    private ?ResultSet $resultSet = null;
    private ?Orm $orm = null;

    /** @var array<string, mixed> foreign-key values read from the row, keyed by relation property */
    private array $foreignKeys = [];

    /**
     * The ghost hydration put in each single relation, keyed by property. It
     * stands for the key in $foreignKeys; kept so a ghost assigned since can
     * be told from it without loading either.
     *
     * @var array<string, Model>
     */
    private array $installedRelations = [];

    /**
     * Column values as they were when the row was read.
     *
     * "Original column values are snapshotted on hydrate; save() writes only
     * the diff." Null means this model has never been in the database.
     *
     * @var array<string, mixed>|null
     */
    private ?array $original = null;

    public static function meta(): ModelMeta
    {
        return Metadata::for(static::class);
    }

    /**
     * A query over this model's rows, read through $orm.
     *
     * The spec writes `Rack::query()`. The Orm is passed rather than found:
     * a model reaching for an Orm held statically would hold the one a
     * worker built, across every request it serves, and a query made in a
     * fiber would read whichever request last set it (ADR 0057).
     *
     * @return Query<static>
     */
    #[\NoDiscard]
    public static function query(Orm $orm): Query
    {
        return Query::of($orm, static::class);
    }

    public function primaryKey(): string
    {
        return static::meta()->keyOf($this);
    }

    /**
     * The models hydrated alongside this one. Relation loads widen to cover
     * them, which is what keeps a result set at 1+1 queries.
     */
    public function resultSet(): ?ResultSet
    {
        return $this->resultSet;
    }

    public function orm(): ?Orm
    {
        return $this->orm;
    }

    public function foreignKey(string $relationProperty): mixed
    {
        return $this->foreignKeys[$relationProperty] ?? null;
    }

    /** True once the row exists in the database. */
    public function isPersisted(): bool
    {
        return $this->original !== null;
    }

    /**
     * Columns whose value differs from the snapshot, foreign keys included.
     *
     * Diffed over what a write sends rather than the declared columns alone:
     * the snapshot holds the foreign keys too, and leaving them out meant a
     * row moved to another parent read as clean and was never written.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        if ($this->original === null) {
            return static::meta()->columnValues($this);
        }

        $changes = [];
        foreach (static::meta()->writeValues($this) as $column => $value) {
            if (!\array_key_exists($column, $this->original) || $this->original[$column] !== $value) {
                $changes[$column] = $value;
            }
        }

        return $changes;
    }

    /**
     * Whether the row is trashed: a #[SoftDeletes] model's row that delete()
     * stamped and restore() has not brought back, as it was last read or
     * written (ADR 0191). Never, for a model that deletes its rows.
     */
    public function isTrashed(): bool
    {
        return static::meta()->isTrashed($this);
    }

    public function isDirty(): bool
    {
        return $this->changes() !== [];
    }

    /**
     * @internal called by Orm after a read or a write
     * @param array<string, mixed> $values
     */
    public function markClean(array $values): void
    {
        $this->original = $values;
    }

    /**
     * @internal called by Orm after a delete
     *
     * The row is gone, so the model no longer stands for one, and a later
     * save() inserts it as it would a new model.
     */
    public function markDeleted(): void
    {
        $this->original = null;
    }

    /**
     * @internal read by Orm before a write inside a transaction
     * @return array<string, mixed>|null
     */
    public function snapshot(): ?array
    {
        return $this->original;
    }

    /**
     * @internal called by Orm when a transaction rolls back
     *
     * The write that replaced the snapshot was undone, so the model has to
     * describe the row as it is again: unpersisted if its insert was undone,
     * dirty if its update was, persisted if its delete was.
     *
     * @param array<string, mixed>|null $snapshot
     */
    public function restoreSnapshot(?array $snapshot): void
    {
        $this->original = $snapshot;
    }

    /** @internal called by Orm during hydration */
    public function attach(Orm $orm, ResultSet $resultSet): void
    {
        $this->orm = $orm;
        $this->resultSet = $resultSet;
    }

    /**
     * @internal called by Orm during hydration
     * @param Model|null $installed the ghost put in the relation for this key
     */
    public function rememberForeignKey(string $relationProperty, mixed $value, ?Model $installed = null): void
    {
        $this->foreignKeys[$relationProperty] = $value;

        if ($installed === null) {
            unset($this->installedRelations[$relationProperty]);
        } else {
            $this->installedRelations[$relationProperty] = $installed;
        }
    }

    /** @internal the ghost hydration put in a single relation, if any */
    public function installedRelation(string $relationProperty): ?Model
    {
        return $this->installedRelations[$relationProperty] ?? null;
    }
}
