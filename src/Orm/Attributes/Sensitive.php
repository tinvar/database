<?php

declare(strict_types=1);

namespace Tinvar\Orm\Attributes;

/**
 * Marks a column whose value the audit log never records (ADR 0153): an
 * #[Audited] model's entry says the field changed, and shows `[redacted]`
 * on both sides of it.
 *
 *     #[Column, Sensitive] public string $taxNumber;
 *
 * A property whose name already says it is a secret -- a password, a
 * token, a hash (Audited::SECRET_NAMES) -- is redacted without it; this is
 * for the ones whose names do not. A field left out of the log altogether,
 * even that it changed, belongs in #[Audited(except: [...])] instead.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class Sensitive {}
