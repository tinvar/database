<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * The application's TenantTree, read with limits: whether one tenant is in
 * another's subtree, the whole of a subtree, and the tenants above one.
 *
 * Both fail closed. A walk up from a tenant that loops, or goes deeper than
 * any real hierarchy would, says the tenant is not under the root -- a
 * request refused, never one let in on a tree that does not hold together.
 * A subtree larger than a read can carry is an error rather than a part of
 * one, since a part would be a silently wrong answer.
 *
 * It ships with the ORM and is what the ORM takes, as a final class, so the
 * limits hold for everyone using this package, with the framework or
 * without it: an application writes the tree, never the walk (ADR 0055).
 * Above the ORM, the code that bounds a session's tenant by a host's and
 * tells the pages reading across a subtree of a write below them asks the
 * same object, through Orm::tenants().
 */
final readonly class TenantHierarchy
{
    /** Levels walked up from a tenant before it is taken to be under nothing. */
    public const int MAX_DEPTH = 32;

    /**
     * Tenants in one subtree, root included, beyond which a read across it
     * is refused. Each is a bound value in the read's IN list and in the
     * setting Postgres's policy reads, so the bound is what a statement can
     * carry with room to spare -- and a parent with more children than this
     * reads across them with a report or a job, not a page's loader.
     */
    public const int MAX_SUBTREE = 1000;

    public function __construct(private TenantTree $tree) {}

    /**
     * Whether $tenant is $root or below it.
     *
     * Walked up from $tenant, one parent at a time, so the answer costs the
     * tenant's depth and never the size of $root's subtree.
     */
    public function contains(string $root, string $tenant): bool
    {
        if ($root === '' || $tenant === '') {
            return false;
        }

        $seen = [];
        $current = $tenant;
        for ($depth = 0; $depth <= self::MAX_DEPTH; $depth++) {
            if ($current === $root) {
                return true;
            }

            $seen[$current] = true;
            $parent = $this->tree->parentOf($current);
            if ($parent === null || $parent === '' || isset($seen[$parent])) {
                return false;
            }
            $current = $parent;
        }

        return false;
    }

    /**
     * Every tenant above $tenant, its parent first.
     *
     * The tenants whose reads across their subtree a write made as $tenant
     * has to reach: the query cache keeps its tags by them. Walked up as
     * contains() walks, and stopped where contains() would stop -- at a
     * loop, or past MAX_DEPTH -- keeping what it found before: an answer
     * that reaches too few tenants leaves a page stale, and never shows
     * anyone a row.
     *
     * @return list<string>
     */
    public function ancestors(string $tenant): array
    {
        $above = [];
        $seen = [$tenant => true];
        $current = $tenant;
        for ($depth = 0; $tenant !== '' && $depth < self::MAX_DEPTH; $depth++) {
            $parent = $this->tree->parentOf($current);
            if ($parent === null || $parent === '' || isset($seen[$parent])) {
                break;
            }
            $seen[$parent] = true;
            $above[] = $parent;
            $current = $parent;
        }

        return $above;
    }

    /**
     * $root and every tenant below it, $root first.
     *
     * @return non-empty-list<string>
     * @throws \LengthException when the subtree is larger than MAX_SUBTREE
     */
    public function subtree(string $root): array
    {
        if ($root === '') {
            throw new \InvalidArgumentException('A subtree has a root; the empty tenant is no tenant.');
        }

        $tenants = [$root => true];
        foreach ($this->tree->descendantsOf($root) as $descendant) {
            if ($descendant !== '') {
                $tenants[$descendant] = true;
            }
        }

        if (\count($tenants) > self::MAX_SUBTREE) {
            throw new \LengthException(\sprintf(
                "Tenant '%s' has %d tenants in its subtree, more than the %d a read across one carries.",
                $root,
                \count($tenants),
                self::MAX_SUBTREE,
            ));
        }

        return array_map('strval', array_keys($tenants));
    }
}
