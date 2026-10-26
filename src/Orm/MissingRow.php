<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * A save() whose UPDATE matched no row.
 *
 * The row is gone -- deleted since the model was read -- or the tenant
 * predicate excluded it. The two are deliberately worded the same, for the
 * reason DanglingRelation gives: "a row you may not see" and "a row that does
 * not exist" must look alike, or the difference is a cross-tenant existence
 * oracle.
 *
 * This used to be silent. The UPDATE wrote nothing, save() reported success
 * and the model was marked clean, so the change was lost and a retry sent
 * nothing at all.
 */
final class MissingRow extends \RuntimeException
{
    public static function onUpdate(string $class, string $id): self
    {
        return new self(sprintf(
            'Saving %s "%s" matched no row to update. '
            . 'The row has been deleted, or belongs to another tenant. Nothing was written.',
            $class,
            $id,
        ));
    }
}
