<?php

declare(strict_types=1);

namespace Tinvar\Orm\Attributes;

/** A single relation, backed by a lazy ghost. */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class BelongsTo
{
    /** @param class-string|null $target inferred from the property type when null */
    public function __construct(
        public ?string $target = null,
        public ?string $foreignKey = null,
    ) {}
}
