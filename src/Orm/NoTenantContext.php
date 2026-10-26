<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * A tenant-scoped model was read with no active tenant.
 *
 * tinvar-spec.md, "Tenancy": "Fail closed. A query on a #[TenantScoped] model
 * or tenant table with no active tenant throws NoTenantContext, unless it is
 * inside acrossTenants()."
 *
 * Failing closed is the whole point: the alternative is a query that quietly
 * returns every tenant's rows.
 */
final class NoTenantContext extends \RuntimeException
{
    public function __construct(string $class)
    {
        parent::__construct(
            "{$class} is #[TenantScoped] and was queried with no active tenant. "
            . 'Set one with Orm::actAs(), or state the intent with Orm::acrossTenants().',
        );
    }
}
