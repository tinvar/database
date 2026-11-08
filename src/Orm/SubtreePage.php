<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * One page of a tenant's subtree (ADR 0196): its tenants, in byte order
 * of their names, from the walk TenantBounds::pages() makes.
 *
 * The page's last tenant is where the next one starts after, so a long job
 * keeps last() as its checkpoint and hands it back as `after` to go on: the
 * walk is keyset paging over tenants' names, and a name is all it needs.
 */
final readonly class SubtreePage
{
    /** @var non-empty-list<string> in byte order */
    public array $tenants;

    /**
     * @param string $root the tenant whose subtree this is a page of
     * @param list<string> $tenants in byte order, one at least
     */
    public function __construct(public string $root, array $tenants)
    {
        if ($tenants === []) {
            throw new \InvalidArgumentException('A page of a subtree holds a tenant at least.');
        }
        $this->tenants = $tenants;
    }

    /** The page's last tenant: the `after` that goes on from it. */
    public function last(): string
    {
        return $this->tenants[\count($this->tenants) - 1];
    }
}
