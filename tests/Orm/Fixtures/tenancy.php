<?php

declare(strict_types=1);

namespace Tinvar\Tests\Orm\Fixtures;

use Tinvar\Orm\TenantSubtrees;

/**
 * The tenancy a test registers with an Orm, so it may read #[TenantScoped]
 * models at all (ADR 0056): tenants side by side, or a tree of parent
 * links.
 *
 * A stand-in for tinvar/tenancy, which the database's suite runs without,
 * and the framework's must pass without too. It walks its parent links
 * honestly -- once, stopping at a loop -- so a test of the Orm's own limits
 * hands the Orm a port of its own that does not.
 */
final readonly class Tenancy implements TenantSubtrees
{
    /** @param array<string, string>|null $parents child => parent; null for tenants side by side */
    public function __construct(private ?array $parents = null) {}

    /** Tenants side by side, with no hierarchy: what most tests register. */
    public static function flat(): self
    {
        return new self();
    }

    /** @param array<string, string> $parents child => parent */
    public static function tree(array $parents): self
    {
        return new self($parents);
    }

    public function hierarchical(): bool
    {
        return $this->parents !== null;
    }

    public function ancestors(string $tenant): array
    {
        $above = [];
        $seen = [$tenant => true];
        while (isset($this->parents[$tenant]) && !isset($seen[$this->parents[$tenant]])) {
            $tenant = $this->parents[$tenant];
            $seen[$tenant] = true;
            $above[] = $tenant;
        }

        return $above;
    }

    public function subtree(string $root): array
    {
        $found = [$root];
        for ($i = 0; $i < \count($found); $i++) {
            foreach ($this->parents ?? [] as $child => $parent) {
                if ($parent === $found[$i] && !\in_array((string) $child, $found, true)) {
                    $found[] = (string) $child;
                }
            }
        }

        return $found;
    }
}
