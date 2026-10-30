<?php

declare(strict_types=1);

namespace Tinvar\Orm\Attributes;

/**
 * Adds only what the PHP type cannot express: a different column name, a
 * storage type, a cast, a string's width, or a check. What make:migration
 * writes for the column, and compares the database's with (ADR 0110).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class Column
{
    /**
     * @param class-string|null $cast a Tinvar\Orm\Cast to map the column through, for a value
     *        class the application cannot make implement Cast itself; a class that does
     *        implement it is cast by its type alone
     * @param int|null $length a string column's most characters (255 where unsaid): varchar(n)
     * @param string|null $check a CHECK the column is held to, as SQL over its own name:
     *        `price_cents >= 0`. The application's own text, written into its migration
     */
    public function __construct(
        public ?string $name = null,
        public ?string $type = null,
        public ?string $cast = null,
        public ?int $length = null,
        public ?string $check = null,
    ) {
        if ($length !== null && ($length < 1 || $length > 65_535)) {
            throw new \InvalidArgumentException('A column\'s length is from 1 to 65535 characters.');
        }
    }
}
