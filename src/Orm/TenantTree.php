<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * The application's tenant hierarchy: which tenant is under which -- a
 * reseller's customers under the reseller, a customer's sites under the
 * customer, whatever the application keeps.
 *
 * tinvar-spec.md, "Panels and scope": "The tenant resolved from a host only
 * constrains: the session's tenant must be that tenant or a descendant",
 * and "Widening (a reseller's customers ...) comes from the session's
 * membership". The framework asks; TenantHierarchy holds the answers to
 * limits that keep a mistake in them from widening anything.
 *
 * Both answers come from one record -- a parent column, a path -- so they
 * cannot disagree: parentOf() decides whether a tenant is under another,
 * and descendantsOf() is what a read across a subtree is filtered by. An
 * implementation answering the two from different places could let a
 * parent read a tenant that is not its child.
 */
interface TenantTree
{
    /** The tenant directly above $tenant, or null for a root and for a tenant there is no record of. */
    public function parentOf(string $tenant): ?string;

    /**
     * Every tenant below $tenant, at any depth, and not $tenant itself: one
     * recursive query, or a path prefix, rather than a call per level.
     *
     * @return list<string>
     */
    public function descendantsOf(string $tenant): array;
}
