<?php

declare(strict_types=1);

namespace Tinvar\Orm\Attributes;

/**
 * Marks a model method delete() calls before the row is deleted, after the
 * read-only check: a delete refused inside a loader or query body runs
 * none.
 *
 * The hooks and the DELETE are one transaction, which delete() opens for a
 * model that has any: a hook that throws stops the delete, and what the
 * hooks wrote before it is taken back with it. A hook that deletes the rows
 * below its own -- a basket's lines -- runs their hooks, as any delete()
 * does; a foreign key's ON DELETE CASCADE in the database runs none.
 *
 * Ordered as #[BeforeSave] is. A hook that deletes its own model is refused
 * (ADR 0122). Query::delete() refuses a model that has these: it deletes
 * rows it never read, so it has no model to run them on.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class BeforeDelete {}
