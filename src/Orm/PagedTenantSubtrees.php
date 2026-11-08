<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * A tenant tree that answers a subtree a page at a time (ADR 0196): what
 * lets the Orm walk a subtree of any size without holding all of it, and
 * read one at once without asking for more of it than it will carry.
 *
 * A port that implements TenantSubtrees alone still pages: the Orm reads
 * its whole subtree() and pages that. The contract of TenantSubtrees holds
 * here too -- one record, it ends, it is the truth, it fails loudly -- and
 * descendantsAfter() answers from the same record as subtree().
 */
interface PagedTenantSubtrees extends TenantSubtrees
{
    /**
     * The first $limit tenants below $root, at any depth and not $root
     * itself, whose names sort after $after -- all of them for null -- in
     * byte order of their names (strcmp(); a database's binary or "C"
     * collation): one recursive query, or a path prefix, per page.
     *
     * The Orm holds every page to that order and that cursor, and refuses
     * a page that breaks them, so a walk always ends.
     *
     * @param int $limit 1 or more
     * @return list<string>
     */
    public function descendantsAfter(string $root, ?string $after, int $limit): array;
}
