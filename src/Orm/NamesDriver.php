<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * A connection that says which database it talks to, for SQL each one
 * spells its own way: a MySQL upsert, a row lock, a float's binding, a
 * migration's dialect.
 *
 * Asked of the connection rather than of PDO, since the async driver
 * (AsyncConnection) has no PDO handle to ask and speaks the same SQL.
 */
interface NamesDriver
{
    /** PDO's name for the driver: 'sqlite', 'pgsql' or 'mysql'. */
    public function driver(): string;
}
