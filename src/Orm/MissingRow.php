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

    /**
     * An attach(), detach() or sync() naming a row no read in the tenant in
     * force returns (ADR 0192): worded as onUpdate() is, for the same reason.
     */
    public static function toLink(string $class, string $id): self
    {
        return new self(sprintf(
            'Linking %s "%s" matched no row. The row has been deleted, or belongs to another tenant. Nothing was linked or unlinked.',
            $class,
            $id,
        ));
    }

    /**
     * A delete() of a model with delete hooks whose DELETE matched no row:
     * worded as onUpdate() is, for the same reason.
     */
    public static function onDelete(string $class, string $id): self
    {
        return new self(sprintf(
            'Deleting %s "%s" matched no row to delete. '
            . 'The row has been deleted, or belongs to another tenant. Nothing was deleted, and no #[AfterDelete] ran.',
            $class,
            $id,
        ));
    }

    /** A delete() of a model with delete hooks that was never saved, or is deleted already. */
    public static function notPersisted(string $class): self
    {
        return new self("Deleting a {$class} that has no row: it was never saved, or is deleted already. No hook ran.");
    }
}
