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
     * at once -- withinSubtree() -- is refused (ADR 0196). Every statement
     * inside one carries the whole list, as one bound value past
     * MAX_LISTED, and Postgres is told it for its policy: the bound is what
     * a request can afford to send with each statement, not what SQL can
     * hold. A larger subtree is read a page at a time (pages(), and the
     * Orm's withinSubtreePages() and forEachTenant()), with no bound at all.
     */
    public const int MAX_SUBTREE = 10_000;

    /**
     * Tenants a read's predicate names one placeholder each, `IN (?, ...)`;
     * past this many the Orm binds them as one value -- a Postgres array, a
     * JSON array MySQL and SQLite read as a table -- so no statement grows
     * with the subtree (ADR 0196).
     */
    public const int MAX_LISTED = 1_000;

    /** Tenants to a page of a subtree, unless the walk asks for another size. */
    public const int PAGE = 500;

    /**
     * @param int $maxSubtree the largest subtree subtree() reads, from 1 to
     *        MAX_SUBTREE: lower only, for a test of the refusal that need not
     *        build 10,000 tenants to see it
     */
    public function __construct(private TenantSubtrees $port, private int $maxSubtree = self::MAX_SUBTREE)
    {
        if ($maxSubtree < 1 || $maxSubtree > self::MAX_SUBTREE) {
            throw new \InvalidArgumentException('A subtree read at once is held to between 1 and ' . self::MAX_SUBTREE . ' tenants.');
        }
    }

    /**
     * Whether $tenant is $root or below it: $root is the tenant itself, or
     * one of the first MAX_DEPTH tenants above it before any repeats.
     *
     * The port's ancestors read up to $root and no further, so a port that
     * walks as it is read is asked the tenant's depth below $root, never
     * the rest of the chain.
     */
    public function contains(string $root, string $tenant): bool
    {
        if ($root === '' || $tenant === '') {
            return false;
        }

        return $root === $tenant || \in_array($root, $this->walk($tenant, $root), true);
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
        return $this->walk($tenant, null);
    }

    /**
     * ancestors(), read no further than $stopAt once it is met.
     *
     * @return list<string>
     */
    private function walk(string $tenant, ?string $stopAt): array
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
            if ($parent === $stopAt) {
                break;
            }
        }

        return $above;
    }

    /**
     * $root and every tenant below it, $root first, each once: what a read
     * across the subtree at once is held to.
     *
     * The root is put first whatever the port answered, and the empty name
     * dropped; anything else that is not a tenant's name refuses the whole
     * answer, as a subtree larger than the limit does, rather than reading
     * across part of one. A paged port (PagedTenantSubtrees) is asked for
     * no more of the subtree than the limit, so a subtree of a million is
     * refused for the price of reading 10,000 names; the rest in byte order.
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

        if ($this->port instanceof PagedTenantSubtrees) {
            // Asked for as many below the root as the limit: a full answer
            // is the root and that many more, which is one too many.
            $below = $this->port->descendantsAfter($root, null, $this->maxSubtree);
            $tenants = [$root, ...$this->checkedPage($root, $below, null, $this->maxSubtree)];
            if (\count($below) >= $this->maxSubtree) {
                throw $this->tooLarge($root, null);
            }

            return $tenants;
        }

        $tenants = [$root => true];
        foreach ($this->port->subtree($root) as $tenant) {
            if ($tenant === '') {
                continue;
            }
            self::assertNamed($tenant, $root);
            $tenants[$tenant] = true;
        }

        if (\count($tenants) > $this->maxSubtree) {
            throw $this->tooLarge($root, \count($tenants));
        }

        return array_map('strval', array_keys($tenants));
    }

    /**
     * $root and every tenant below it, a page of $size at a time, in byte
     * order of their names (strcmp()), each once; from the first name
     * after $after, for a walk that goes on from a checkpoint (ADR 0196).
     *
     * No bound on the subtree: only a page is held at a time. A paged port
     * is asked for each page; any other is read whole once and paged here.
     * Each page the port answers is held to the walk -- names, in order,
     * after the last, no more than asked -- and one that breaks it refuses
     * the walk where it stands rather than skip, repeat or loop.
     *
     * Each page is read when the walk reaches it, so a tenant added to the
     * subtree behind the walk, or moved out of it ahead, is missed; inside a
     * transaction the walk reads the tree the transaction does.
     *
     * @return \Generator<int, SubtreePage>
     * @throws \InvalidArgumentException for no root, a cursor that is no name, or a size outside 1 to MAX_SUBTREE
     * @throws \UnexpectedValueException when the port answers a page that breaks the walk
     */
    public function pages(string $root, int $size = self::PAGE, ?string $after = null): \Generator
    {
        if (!self::isName($root)) {
            throw new \InvalidArgumentException('A subtree has a root; the empty tenant is no tenant.');
        }
        if ($size < 1 || $size > self::MAX_SUBTREE) {
            throw new \InvalidArgumentException('A page of a subtree holds between 1 and ' . self::MAX_SUBTREE . ' tenants.');
        }
        if ($after !== null && !self::isName($after)) {
            throw new \InvalidArgumentException('A walk goes on after a tenant\'s name, and this is none.');
        }

        if (!$this->port instanceof PagedTenantSubtrees) {
            yield from $this->pagesOfWhole($root, $size, $after);

            return;
        }

        // The root is no descendant: it goes into the page whose names it
        // sorts among, the first one that reaches past it.
        $rootPending = $after === null || strcmp($root, $after) > 0;
        $cursor = $after;
        while (true) {
            $raw = $this->port->descendantsAfter($root, $cursor, $size);
            $page = $this->checkedPage($root, $raw, $cursor, $size);
            $more = \count($raw) >= $size;

            if ($rootPending && (!$more || ($page !== [] && strcmp($root, $page[\count($page) - 1]) < 0))) {
                $rootPending = false;
                $page[] = $root;
                sort($page, \SORT_STRING);
                if (\count($page) > $size) {
                    // Its last goes on to the next page, which starts after
                    // what this one ends with.
                    array_pop($page);
                    $more = true;
                }
            }

            if ($page !== []) {
                yield new SubtreePage($root, $page);
                $cursor = $page[\count($page) - 1];
            } elseif ($more) {
                // A page of the root alone, which a loop named below itself.
                $cursor = $raw[\count($raw) - 1];
            }
            if (!$more) {
                return;
            }
        }
    }

    /**
     * pages() of a port that answers the whole subtree alone: read once,
     * held as subtree() holds it, and paged here.
     *
     * @param int<1, max> $size
     * @return \Generator<int, SubtreePage>
     */
    private function pagesOfWhole(string $root, int $size, ?string $after): \Generator
    {
        $tenants = [$root => true];
        foreach ($this->port->subtree($root) as $tenant) {
            if ($tenant !== '') {
                self::assertNamed($tenant, $root);
                $tenants[$tenant] = true;
            }
        }
        $names = array_map('strval', array_keys($tenants));
        sort($names, \SORT_STRING);
        if ($after !== null) {
            $names = array_values(array_filter($names, static fn (string $name): bool => strcmp($name, $after) > 0));
        }

        foreach (array_chunk($names, $size) as $page) {
            yield new SubtreePage($root, $page);
        }
    }

    /**
     * A page a paged port answered, held to the walk, with the root -- which
     * a loop may name below itself -- taken out.
     *
     * @param array<mixed> $page
     * @return list<string>
     */
    private function checkedPage(string $root, array $page, ?string $after, int $limit): array
    {
        if (\count($page) > $limit || !array_is_list($page)) {
            throw new \UnexpectedValueException("The tenant tree answered a page of the subtree of '{$root}' with more than the {$limit} tenants asked for, or not as a list.");
        }

        $checked = [];
        $previous = $after;
        foreach ($page as $tenant) {
            self::assertNamed($tenant, $root);
            \assert(\is_string($tenant));
            if ($previous !== null && strcmp($tenant, $previous) <= 0) {
                throw new \UnexpectedValueException(
                    "The tenant tree answered a page of the subtree of '{$root}' out of byte order, or not after the tenant it was asked to start after.",
                );
            }
            $previous = $tenant;
            if ($tenant !== $root) {
                $checked[] = $tenant;
            }
        }

        return $checked;
    }

    /** @throws \UnexpectedValueException for anything that is no tenant's name */
    private static function assertNamed(mixed $tenant, string $root): void
    {
        if (!self::isName($tenant)) {
            throw new \UnexpectedValueException(\sprintf(
                "The tenant tree named %s in the subtree of '%s', which is no tenant.",
                get_debug_type($tenant),
                $root,
            ));
        }
    }

    private function tooLarge(string $root, ?int $count): \LengthException
    {
        return new \LengthException(\sprintf(
            "Tenant '%s' has %s tenants in its subtree, more than the %d a read across one at once carries: "
            . 'read it a page at a time, with withinSubtreePages() or forEachTenant().',
            $root,
            $count === null ? 'more' : (string) $count,
            $this->maxSubtree,
        ));
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
