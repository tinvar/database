<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * The tenant tree as the Orm reads it: what a registered TenantSubtrees
 * answers, held to the limits a walk of a tree has, by the Orm itself.
 *
 * Both fail closed. An answer that loops, or goes deeper than any real
 * hierarchy would, places the tenant under nothing past that point -- a
 * request refused, never one let in on a tree that does not hold together.
 * A subtree larger than a read can carry is an error rather than a part of
 * one, since a part would be a silently wrong answer.
 *
 * The limits are checked here, on whatever the port returns, rather than
 * left to the port: the port is an interface, and an implementation that
 * walked without them -- by mistake, or by a test's shortcut left in --
 * would otherwise widen every read across a subtree and every host's bound
 * on a session (ADR 0056). A final class, built by the Orm, and what
 * Orm::tenants() returns, so the code above the Orm that bounds a session's
 * tenant by a host's, admits a handoff into a subtree, or reaches the pages
 * reading across one, asks the same checked tree the Orm's reads do.
 *
 * What it cannot check -- that the answers are the application's real
 * tree, from one record, and that the port returns at all -- is the port's
 * contract (TenantSubtrees).
 */
final readonly class TenantBounds
{
    /** Levels above a tenant read before it is taken to be under nothing further. */
    public const int MAX_DEPTH = 32;

    /**
     * Tenants in one subtree, root included, beyond which a read across it
     * is refused. Each is a bound value in the read's IN list and in the
     * setting Postgres's policy reads, so the bound is what a statement can
     * carry with room to spare -- and a parent with more children than this
     * reads across them with a report or a job, not a page's loader.
     */
    public const int MAX_SUBTREE = 1000;

    public function __construct(private TenantSubtrees $port) {}

    /**
     * Whether $tenant is $root or below it: $root is the tenant itself, or
     * one of the first MAX_DEPTH tenants above it before any repeats.
     */
    public function contains(string $root, string $tenant): bool
    {
        if ($root === '' || $tenant === '') {
            return false;
        }

        return $root === $tenant || \in_array($root, $this->ancestors($tenant), true);
    }

    /**
     * Every tenant above $tenant, its parent first: the tenants whose reads
     * across their subtree a write made as $tenant has to reach.
     *
     * The port's answer, cut where a walk would stop: at MAX_DEPTH, at a
     * tenant met twice -- $tenant itself included, which is a loop -- and at
     * anything that is not a tenant's name. What came before is kept: an
     * answer that reaches too few tenants leaves a page stale, and never
     * shows anyone a row, and contains() reads the same cut answer, so a
     * root past the cut is not above the tenant.
     *
     * @return list<string>
     */
    public function ancestors(string $tenant): array
    {
        if ($tenant === '') {
            return [];
        }

        $above = [];
        $seen = [$tenant => true];
        foreach ($this->port->ancestors($tenant) as $parent) {
            if (\count($above) >= self::MAX_DEPTH || !self::isName($parent) || isset($seen[$parent])) {
                break;
            }
            $seen[$parent] = true;
            $above[] = $parent;
        }

        return $above;
    }

    /**
     * $root and every tenant below it, $root first, each once.
     *
     * The root is put first whatever the port answered, and the empty name
     * dropped; anything else that is not a tenant's name refuses the whole
     * answer, as a subtree larger than MAX_SUBTREE does, rather than reading
     * across part of one.
     *
     * @return non-empty-list<string>
     * @throws \LengthException when the subtree is larger than MAX_SUBTREE
     * @throws \UnexpectedValueException when the port names something that is no tenant
     */
    public function subtree(string $root): array
    {
        if (!self::isName($root)) {
            throw new \InvalidArgumentException('A subtree has a root; the empty tenant is no tenant.');
        }

        $tenants = [$root => true];
        foreach ($this->port->subtree($root) as $tenant) {
            if ($tenant === '') {
                continue;
            }
            if (!self::isName($tenant)) {
                throw new \UnexpectedValueException(\sprintf(
                    "The tenant tree named %s in the subtree of '%s', which is no tenant.",
                    get_debug_type($tenant),
                    $root,
                ));
            }
            $tenants[$tenant] = true;
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

    /**
     * Whether $name is a tenant's: a non-empty string with no control
     * character in it, which is all the Orm lets a tenant be
     * (Orm::switchTenant()). A Postgres setting cannot carry NUL, and the
     * claim a token carries with no tenancy registered is spelled with one
     * of the others, so no tenant can be taken for it. Asked of mixed, since
     * an implementation's docblock is a promise and this is where the Orm
     * stops taking it on trust.
     */
    public static function isName(mixed $name): bool
    {
        return \is_string($name) && $name !== '' && preg_match('/[\x00-\x1f\x7f]/', $name) !== 1;
    }
}
