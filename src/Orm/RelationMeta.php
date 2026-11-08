<?php

declare(strict_types=1);

namespace Tinvar\Orm;

final readonly class RelationMeta
{
    /** The delete actions a #[BelongsTo] may name, as SQL spells them. */
    public const array DELETE_ACTIONS = ['CASCADE', 'SET NULL', 'RESTRICT', 'NO ACTION'];

    /**
     * @param class-string<Model> $owner
     * @param class-string<Model> $target
     * @param string $foreignKey the column holding the key that links the two: the owner's for a #[BelongsTo],
     *        the target's for a #[HasMany], the pivot's column naming the owner for a #[BelongsToMany]
     * @param value-of<self::DELETE_ACTIONS>|null $onDelete a #[BelongsTo]'s foreign key's ON DELETE, where it names one
     * @param PivotMeta|null $pivot the pivot a #[BelongsToMany] goes through (ADR 0192)
     */
    public function __construct(
        public RelationKind $kind,
        public string $owner,
        public string $property,
        public string $target,
        public string $foreignKey,
        public ?string $orderBy = null,
        public ?string $onDelete = null,
        public ?PivotMeta $pivot = null,
    ) {}

    /** Groups pending loads in the scheduler: one query per owner-relation pair. */
    public function key(): string
    {
        return "{$this->owner}::{$this->property}";
    }

    /** The column on the child side that is matched with whereIn. */
    public function childColumn(): string
    {
        return $this->kind === RelationKind::BelongsTo
            ? Metadata::for($this->target)->idColumn
            : $this->foreignKey;
    }
}
