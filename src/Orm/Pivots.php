<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * What Orm::attach(), detach() and sync() do (ADR 0192): a #[BelongsToMany]'s
 * links written as pivot models, saved and deleted through the Orm -- so the
 * pivot's tenant is stamped, its hooks run and, #[Audited], each link made or
 * undone is recorded -- in one transaction.
 *
 * Held to the tenant in force before anything is written: the owner and
 * every row named are read in it, and one that is not there -- deleted, or
 * another tenant's, which are worded alike -- refuses the whole call. Not
 * across tenants or a subtree, where a pivot row's tenant would be no one's
 * to say.
 *
 * @internal for the Orm, inside this package
 */
final readonly class Pivots
{
    public function __construct(private Orm $orm) {}

    /**
     * @param iterable<Model|string|int> $related
     * @param array<string, mixed> $values
     */
    public function write(string $what, Model $owner, string $property, iterable $related, array $values, bool $attach, bool $detach): PivotChange
    {
        $ownerClass = $owner::class;
        $ownerMeta = Metadata::for($ownerClass);
        $relation = $ownerMeta->relations[$property] ?? null;
        $pivot = $relation?->pivot;
        if ($relation === null || $pivot === null) {
            throw new \LogicException("{$what}: {$ownerClass} has no #[BelongsToMany] '{$property}'.");
        }
        if ($this->orm->isWidened()) {
            throw new \LogicException(
                "{$what} links rows in one tenant: call it inside actAs() for the tenant whose rows they are, not across tenants or a subtree.",
            );
        }
        if (!$owner->isPersisted()) {
            throw new \LogicException("{$what}: save the {$ownerClass} before linking it.");
        }
        $ids = $this->keys($what, $relation, $related);
        $pivotMeta = Metadata::for($pivot->class);
        $this->checkValues($what, $pivot, $pivotMeta, $values);

        $change = $this->orm->transaction(function () use ($owner, $ownerMeta, $relation, $pivot, $pivotMeta, $ids, $values, $attach, $detach): PivotChange {
            $ownerId = $owner->primaryKey();
            if ($this->orm->query($owner::class)->whereIn($ownerMeta->idColumn, [$ownerId])->pluck($ownerMeta->idColumn) === []) {
                throw MissingRow::toLink($owner::class, $ownerId);
            }

            $targets = [];
            if ($ids !== []) {
                $targetMeta = Metadata::for($relation->target);
                foreach ($this->orm->query($relation->target)->whereIn($targetMeta->idColumn, $ids)->fetch() as $target) {
                    $targets[$target->primaryKey()] = $target;
                }
                foreach ($ids as $id) {
                    if (!isset($targets[$id])) {
                        throw MissingRow::toLink($relation->target, $id);
                    }
                }
            }

            // The links there now, by the row each links to: every one of
            // the owner's for a sync(), else those to the rows named. A
            // pivot with no unique key over the pair may hold one twice.
            // A #[SoftDeletes] pivot's trashed links are none, and one is
            // restored rather than made again beside it (ADR 0191).
            $linked = [];
            $trashed = [];
            if ($ids !== [] || ($attach && $detach)) {
                $links = $this->orm->query($pivot->class)->withTrashed()->where($pivot->ownerProperty, $ownerId);
                if (!($attach && $detach)) {
                    $links = $links->whereIn($pivot->targetProperty, $ids);
                }
                foreach ($links->orderBy($pivotMeta->idProperty)->fetch() as $link) {
                    if ($link->isTrashed()) {
                        $trashed[(string) $link->foreignKey($pivot->targetProperty)] ??= $link;
                    } else {
                        $linked[(string) $link->foreignKey($pivot->targetProperty)][] = $link;
                    }
                }
            }

            $detached = [];
            if ($detach) {
                $named = array_flip($ids);
                foreach ($linked as $id => $twins) {
                    $id = (string) $id;
                    // detach() unlinks what it names; sync() what it does not.
                    if (isset($named[$id]) !== $attach) {
                        foreach ($twins as $link) {
                            $this->orm->delete($link);
                        }
                        $detached[] = $id;
                    }
                }
                if (!$attach) {
                    // In the order they were named.
                    $detached = array_values(array_intersect($ids, $detached));
                }
            }

            $attached = [];
            if ($attach) {
                foreach ($ids as $id) {
                    if (isset($linked[$id])) {
                        continue;
                    }
                    if (isset($trashed[$id])) {
                        foreach ($values as $name => $value) {
                            $pivotMeta->set($trashed[$id], $name, $value);
                        }
                        $this->orm->save($trashed[$id]);
                        $this->orm->restore($trashed[$id]);
                        $attached[] = $id;
                    } elseif ($this->link($owner, $targets[$id], $pivot, $pivotMeta, $values)) {
                        $attached[] = $id;
                    }
                }
            }

            return new PivotChange($attached, $detached);
        });

        // The owner handed in may be no model the identity map holds -- read
        // in another scope, or before a flush -- so its collections are
        // unloaded by name, as every one through the pivot the map holds was
        // by the writes themselves.
        foreach (Metadata::relationsThrough($pivot->class) as $through) {
            $this->orm->forgetLinks($through, $owner);
        }

        return $change;
    }

    /**
     * Saves one pivot model; false when a link raced in first. In a
     * savepoint, so the unique key refusing it -- the link made by another
     * request since it was read -- takes back this insert alone.
     *
     * @param array<string, mixed> $values
     */
    private function link(Model $owner, Model $target, PivotMeta $pivot, ModelMeta $pivotMeta, array $values): bool
    {
        /** @var Model $link */
        $link = new ($pivot->class)();
        $pivotMeta->set($link, $pivot->ownerProperty, $owner);
        $pivotMeta->set($link, $pivot->targetProperty, $target);
        foreach ($values as $name => $value) {
            $pivotMeta->set($link, $name, $value);
        }

        try {
            $this->orm->transaction(fn (): Model => $this->orm->save($link));
        } catch (\PDOException $e) {
            if (!UniqueViolation::is($e)) {
                throw $e;
            }

            return false;
        }

        return true;
    }

    /**
     * The keys $related names, each once, in the order named.
     *
     * @param iterable<Model|string|int> $related
     * @return list<string>
     */
    private function keys(string $what, RelationMeta $relation, iterable $related): array
    {
        $keys = [];
        foreach ($related as $item) {
            if ($item instanceof Model) {
                if (!$item instanceof $relation->target) {
                    throw new \InvalidArgumentException("{$what}: {$relation->key()} links {$relation->target} rows, and was handed a " . $item::class . '.');
                }
                if (!$item->isPersisted()) {
                    throw new \LogicException("{$what}: save the {$relation->target} before linking it.");
                }
                $key = $item->primaryKey();
            } else {
                $key = (string) $item;
            }
            if ($key === '') {
                throw new \InvalidArgumentException("{$what}: an empty key names no row.");
            }
            $keys[$key] = true;
        }

        return array_map(strval(...), array_keys($keys));
    }

    /**
     * $values names the pivot's own properties: never its key, nor either
     * side, which the call sets.
     *
     * @param array<string, mixed> $values
     */
    private function checkValues(string $what, PivotMeta $pivot, ModelMeta $pivotMeta, array $values): void
    {
        foreach (array_keys($values) as $name) {
            if (!isset($pivotMeta->columns[$name]) || $name === $pivotMeta->idProperty) {
                throw new \InvalidArgumentException(
                    "{$what}: '{$name}' is no property of {$pivot->class} a link may be made with. Name its own #[Column]s; "
                    . "the key, '{$pivot->ownerProperty}' and '{$pivot->targetProperty}' are set by the call.",
                );
            }
        }
    }
}
