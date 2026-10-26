<?php

declare(strict_types=1);

namespace Tinvar\Orm\Attributes;

/**
 * The table a model maps to. Typed properties are the schema; attributes add
 * only what PHP cannot express (tinvar-spec.md, "Models").
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Table
{
    public function __construct(public string $name) {}
}
