<?php

declare(strict_types=1);

namespace Tinvar\Orm\Schema;

/**
 * The names a schema statement may carry: a table, column or index name,
 * plain or schema-qualified.
 *
 * Names are quoted as well as checked, but quoting alone would let a name
 * with a quote in it out of its quotes. Checked here, a name is one that
 * means the same unquoted, quoted and on every dialect, so a table made on
 * SQLite in development is the table Postgres has in production.
 */
final class Identifier
{
    public static function assert(string $name, string $what): void
    {
        foreach (explode('.', $name) as $part) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}\z/', $part) !== 1) {
                throw new \InvalidArgumentException("'{$name}' is not a {$what} name.");
            }
        }
    }
}
