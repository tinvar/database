<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * RawStatements over a connection that names its database, naming it too:
 * raw SQL that asks which database it is on -- for MySQL's count of rows
 * changed, a migration's dialect -- gets the same answer with the dev
 * toolbar's flag on as without it (ADR 0172).
 *
 * @internal made by Orm::connection(), inside this package
 */
final readonly class NamedRawStatements extends RawStatements implements NamesDriver
{
    /** @param \Closure(string, \Closure(): string): void $flag */
    public function __construct(Connection&NamesDriver $connection, \Closure $flag)
    {
        parent::__construct($connection, $flag);
    }

    public function driver(): string
    {
        \assert($this->connection instanceof NamesDriver);

        return $this->connection->driver();
    }
}
