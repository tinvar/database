<?php

declare(strict_types=1);

namespace Tinvar\Orm\Attributes;

/** UUIDv7 primary key, minted on insert. */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class Id {}
