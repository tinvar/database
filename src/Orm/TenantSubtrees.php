<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * The tenant tree, as the ORM is told it: the port tenancy registers with
 * the Orm (its third constructor argument).
 *
 * Registering one is what lets the Orm read a #[TenantScoped] model at all.
 * With none, every read and write of one throws NoTenantContext, whatever
 * tenant is in force: an application that never set tenancy up has no
 * business reading a tenant's rows (ADR 0056).
 *
 * The Orm does not take these answers on trust. It reads them through
 * TenantBounds, which holds them to the limits a walk of the tree has --
 * 32 levels, a stop at a loop, 1,000 tenants to a subtree -- whatever an
 * implementation returns, so one that drops them drops nothing. What it
 * cannot check is held by this contract:
 *
 * - **One record.** ancestors() and subtree() are answered from the same
 *   place -- a parent column, a path -- so they agree: a tenant subtree()
 *   names has its root among its ancestors(), and no other tenant does. An
 *   implementation answering the two from different places could let a
 *   parent read a tenant that is not its child.
 * - **It ends.** Each call returns, on a tree that loops too: the Orm can
 *   bound what comes back, never how long it takes to come.
 * - **It is the truth.** No tenant is named that the record does not put
 *   there; an unknown tenant has no ancestors and a subtree of itself.
 * - **It fails loudly.** A tree it cannot read throws, and the read that
 *   asked fails with it, rather than answering with part of a tree.
 *
 * tinvar/tenancy implements it over the tree an application writes, and
 * tinvar/testing ships a conformance kit that holds any implementation to
 * this contract.
 */
interface TenantSubtrees
{
    /**
     * Whether the application's tenants are in a hierarchy at all. False
     * for tenants side by side: each is its own subtree, ancestors() and
     * subtree() are never asked, and a read across a subtree is refused, as
     * it is with no tree.
     */
    public function hierarchical(): bool;

    /**
     * Every tenant above $tenant, its parent first and its root last; none
     * for a root, and for a tenant there is no record of.
     *
     * A list, or a generator that walks up as it is read: the Orm reads it
     * only as far as a question needs -- contains() stops at the root it
     * asks about -- and never past its own limits.
     *
     * @return iterable<string>
     */
    public function ancestors(string $tenant): iterable;

    /**
     * $root and every tenant below it, at any depth: one recursive query,
     * or a path prefix, rather than a call per level.
     *
     * @return list<string>
     */
    public function subtree(string $root): array;
}
