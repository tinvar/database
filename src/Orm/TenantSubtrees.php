<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * The application's tenant tree, as the ORM is given it: whether one tenant
 * is in another's subtree, every tenant in a subtree, and the tenants above
 * one.
 *
 * The ORM's port onto tenancy, which sits above it (ADR 0054). The ORM
 * reads contains() and subtree() itself, in withinSubtree(); it carries the
 * tree, through tenants(), for the code above it that bounds a session's
 * tenant by a host's and tells the pages reading across a subtree of a
 * write below them, which reads ancestors() too.
 *
 * The framework's tenant hierarchy implements it over the tree the
 * application keeps, and is what an application gives the ORM: its answers
 * fail closed, and an implementation of this has to as well. A tree that
 * loops, or runs deeper than any real hierarchy, puts a tenant under
 * nothing -- contains() is false and ancestors() stops there -- and a
 * subtree larger than a statement can carry throws rather than answering
 * with part of itself, which would be a silently wrong IN list.
 */
interface TenantSubtrees
{
    /** Whether $tenant is $root or below it; false for the empty tenant on either side. */
    public function contains(string $root, string $tenant): bool;

    /**
     * $root and every tenant below it, $root first.
     *
     * @return non-empty-list<string>
     * @throws \LengthException when the subtree is larger than a read across one carries
     * @throws \InvalidArgumentException for the empty tenant, which roots nothing
     */
    public function subtree(string $root): array;

    /**
     * Every tenant above $tenant, its parent first: those whose reads across
     * their subtree a write made as $tenant reaches.
     *
     * @return list<string>
     */
    public function ancestors(string $tenant): array;
}
