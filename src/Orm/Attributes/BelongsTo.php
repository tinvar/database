<?php

declare(strict_types=1);

namespace Tinvar\Orm\Attributes;

/**
 * A single relation, backed by a lazy ghost.
 *
 * `onDelete` says what the database does to this row when the row it
 * belongs to is deleted -- 'cascade', 'set null' (for a property that may
 * be null), 'restrict' or 'no action' -- and is what make:migration writes
 * on the foreign key it makes, and compares with the key the database has
 * (ADR 0122). Null says nothing: the key is made with the database's own
 * default, and not compared. A delete the database cascades runs no
 * model's #[BeforeDelete] or #[AfterDelete].
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class BelongsTo
{
    /** @param class-string|null $target inferred from the property type when null */
    public function __construct(
        public ?string $target = null,
        public ?string $foreignKey = null,
        public ?string $onDelete = null,
    ) {}
}
