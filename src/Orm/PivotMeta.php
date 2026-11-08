<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * The pivot a #[BelongsToMany] goes through (ADR 0192): its model, and its
 * #[BelongsTo] to each side -- the property, and the column it keeps the
 * key in.
 */
final readonly class PivotMeta
{
    /** @param class-string<Model> $class */
    public function __construct(
        public string $class,
        public string $ownerProperty,
        public string $ownerColumn,
        public string $targetProperty,
        public string $targetColumn,
    ) {}
}
