<?php

declare(strict_types=1);

namespace Tinvar\Orm\Attributes;

/** Adds only what the PHP type cannot express: a different column name, a storage type, or a cast. */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class Column
{
    /**
     * @param class-string|null $cast a Tinvar\Orm\Cast to map the column through, for a value
     *        class the application cannot make implement Cast itself; a class that does
     *        implement it is cast by its type alone
     */
    public function __construct(
        public ?string $name = null,
        public ?string $type = null,
        public ?string $cast = null,
    ) {}
}
