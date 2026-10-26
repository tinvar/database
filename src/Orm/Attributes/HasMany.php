<?php

declare(strict_types=1);

namespace Tinvar\Orm\Attributes;

/** A collection relation. Loads with one whereIn across the whole result set. */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class HasMany
{
    /** @param class-string $target */
    public function __construct(
        public string $target,
        public ?string $foreignKey = null,
        public ?string $orderBy = null,
    ) {}
}
