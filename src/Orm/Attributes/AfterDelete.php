<?php

declare(strict_types=1);

namespace Tinvar\Orm\Attributes;

/**
 * Marks a model method delete() calls once the row is gone. The model reads
 * as unsaved by then, as after any delete; its columns still hold what they
 * held, for a hook to read.
 *
 * In one transaction with the DELETE and the #[BeforeDelete] hooks: a hook
 * that throws puts the row back, and the model reads as persisted again.
 * Ordered as #[BeforeSave] is. Query::delete() refuses a model that has
 * these, as it refuses #[BeforeDelete] (ADR 0122).
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class AfterDelete {}
