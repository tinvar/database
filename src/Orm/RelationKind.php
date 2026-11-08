<?php

declare(strict_types=1);

namespace Tinvar\Orm;

enum RelationKind
{
    case HasMany;
    case BelongsTo;

    /** Through a pivot model: a collection of the target's rows (ADR 0192). */
    case BelongsToMany;

    /** Whether the relation holds a Collection, loaded for each parent by the parent's key. */
    public function collects(): bool
    {
        return $this !== self::BelongsTo;
    }
}
