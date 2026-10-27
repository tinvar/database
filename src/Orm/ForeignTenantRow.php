<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * A write through the query builder named a tenant other than the one in
 * force: tinvar-spec.md, "Tenancy" -- writes "refuse rows for another
 * tenant". A row is stamped with the tenant in force, as save() stamps one,
 * so a row naming another is a mistake at best and an attempt to write into
 * someone else's data at worst; either way nothing is written.
 */
final class ForeignTenantRow extends \LogicException
{
    public static function named(string $class): self
    {
        // The tenant it named is left out: it came with the row, and may
        // have come from a request.
        return new self(
            "A row of {$class} names a tenant other than the one in force, and a write is stamped with the tenant in "
            . "force. Write another tenant's rows inside actAs() for that tenant -- if this code may.",
        );
    }

    public static function moved(string $class): self
    {
        return new self("A write cannot move rows of {$class} to another tenant: the tenant column is stamped on insert and never updated.");
    }
}
