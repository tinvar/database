<?php

declare(strict_types=1);

namespace Tinvar\Orm;

final readonly class RelationMeta
{
    /**
     * @param class-string<Model> $owner
     * @param class-string<Model> $target
     */
    public function __construct(
        public RelationKind $kind,
        public string $owner,
        public string $property,
        public string $target,
        public string $foreignKey,
        public ?string $orderBy = null,
    ) {}

    /** Groups pending loads in the scheduler: one query per owner-relation pair. */
    public function key(): string
    {
        return "{$this->owner}::{$this->property}";
    }

    /** The column on the child side that is matched with whereIn. */
    public function childColumn(): string
    {
        return $this->kind === RelationKind::HasMany
            ? $this->foreignKey
            : Metadata::for($this->target)->idColumn;
    }
}
