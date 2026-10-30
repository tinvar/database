<?php

declare(strict_types=1);

namespace Tinvar\Orm\Attributes;

/**
 * Marks a model method save() calls once the row is written: an insert, or
 * an update that wrote something. A save with nothing to write writes no
 * row, and runs none.
 *
 * The model is as the database now holds it: persisted, clean, its key
 * minted and its stamps filled, and in the identity map. A hook that needs
 * to tell an insert from an update notes isPersisted() in a #[BeforeSave].
 *
 * The write and the hooks are one transaction: save() opens one around
 * them for a model that has any -- a savepoint, inside one already open. A
 * hook that throws takes the row back with whatever the hooks wrote, the
 * exception reaches the caller, and the model reads as it did before the
 * save, as after any rolled-back write. A durableTransaction() cannot be
 * opened inside a hook, since it must be the outermost.
 *
 * Ordered as #[BeforeSave] is: the class's own top to bottom, then its
 * traits', then a parent's. A hook that saves its own model is refused,
 * since it would run the hooks without end; saving another is fine
 * (ADR 0122).
 *
 * A write through a query: insert() runs these on a model of each row it
 * wrote; update(), increment(), decrement() and upsert() refuse a model
 * that has them, since they write rows they never read whole.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class AfterSave {}
