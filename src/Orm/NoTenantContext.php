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
 *
 * Thrown too, inside acrossTenants() and whatever tenant is in force, for an
 * Orm no tenancy was registered with (noTenancy()): a model marked
 * tenant-scoped in an application that never set tenancy up is read by no
 * one, rather than read with a tenant nothing vouches for (ADR 0056).
 */
final class NoTenantContext extends \RuntimeException
{
    public function __construct(string $class, ?string $message = null)
    {
        parent::__construct($message ?? (
            "{$class} is #[TenantScoped] and was queried with no active tenant. "
            . 'Set one with Orm::actAs(), or state the intent with Orm::acrossTenants().'
        ));
    }

    /** A tenant-scoped model reached through an Orm no tenancy was registered with. */
    public static function noTenancy(string $class): self
    {
        return new self($class, "{$class} is #[TenantScoped], and no tenancy is registered with the Orm, so no tenant's rows "
            . 'are read or written. Require tinvar/tenancy and give the Orm its tree (TenantSubtrees).');
    }
}
