<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * A #[BelongsTo] whose foreign key names a row that is not there.
 *
 * Not there means: deleted, soft-deleted, or outside the active tenant. The
 * ORM cannot tell those apart from here, and deliberately does not say which
 * -- "a row you may not see" and "a row that does not exist" must look the
 * same, or the difference is a cross-tenant existence oracle.
 *
 * This used to be silent. The ghost's initializer returned without hydrating,
 * leaving an object that was marked initialized, held no data, and would
 * never retry. Reading any field of it raised PHP's own
 * "must not be accessed before initialization" Error -- from a getter, with
 * no mention of the relation, the key, or the scope that hid the row. That is
 * the worst possible diagnostic for the most likely cause.
 */
final class DanglingRelation extends \RuntimeException
{
    public static function for(string $owner, string $property, string $target, string $foreignKey): self
    {
        return new self(sprintf(
            '%s::$%s points at %s "%s", which no scoped read returns. '
            . 'The row is missing, soft-deleted, or belongs to another tenant. '
            . 'Make the relation nullable if its absence is expected.',
            $owner,
            $property,
            $target,
            $foreignKey,
        ));
    }
}
