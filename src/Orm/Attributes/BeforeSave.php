<?php

declare(strict_types=1);

namespace Tinvar\Orm\Attributes;

/**
 * Marks a model method save() calls before it works out what to write.
 *
 * tinvar-spec.md, "Models":
 *
 *     #[BeforeSave]
 *     private function normalize(): void { $this->name = trim($this->name); }
 *
 * Private methods work, and so do methods a trait declares: "relations and
 * behavior in traits". Every save() runs them, an insert or an update, and
 * before the diff, so what a hook changes is written with the rest; a hook
 * that needs to tell the two apart asks isPersisted().
 *
 * The order is fixed when the model compiles, most specific first: the
 * class's own hooks top to bottom, then each trait's, in the order the class
 * uses them, then a parent class's by the same rule. A trait's hook is shared
 * behaviour -- a slug derived from the name, a time stamped -- so it reads
 * the columns after the class has normalized them.
 *
 * A hook that throws stops the save: nothing is written, the exception
 * reaches the caller, and inside transaction() rolls it back as any other
 * would. What the hooks had changed on the model stays on it, unsaved, so it
 * reads as dirty. Hooks run inside whatever transaction the save is in, and
 * after the read-only check (ADR 0022): a save refused there runs none.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class BeforeSave {}
