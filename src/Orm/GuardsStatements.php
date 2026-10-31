<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * A connection that shows each statement to a guard before it sends it:
 * what the Orm over it installs, so raw SQL that may write inside a
 * widened read names the tenants it may reach, or is refused, however the
 * connection was held -- from connection(), statements(), or a variable
 * from before the widening (Orm::guardStatement(), ADR 0122).
 *
 * The guard may throw, and then the statement is not sent.
 */
interface GuardsStatements
{
    /** @param (\Closure(string, bool): void)|null $guard given the SQL and whether select() was asked; null stops it */
    public function guardStatements(?\Closure $guard): void;
}
