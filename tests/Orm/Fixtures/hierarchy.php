<?php

declare(strict_types=1);

namespace Tinvar\Tests\Orm\Fixtures\Hierarchy;

use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Support\Uuid;
use Tinvar\Orm\TenantHierarchy;
use Tinvar\Orm\TenantTree;

require_once __DIR__ . '/tenant.php';

/**
 * A reseller with two customers, one of which has a site of its own, and a
 * tenant beside them all:
 *
 *     reseller            other
 *     ├── acme
 *     │   └── acme-west
 *     └── globex
 */
final class Tree implements TenantTree
{
    /** @var array<string, string> child => parent */
    public array $parents = [
        'acme' => 'reseller',
        'globex' => 'reseller',
        'acme-west' => 'acme',
    ];

    /** How many times each question was asked, for tests of what a check costs. */
    public int $asked = 0;

    public function parentOf(string $tenant): ?string
    {
        $this->asked++;

        return $this->parents[$tenant] ?? null;
    }

    public function descendantsOf(string $tenant): array
    {
        $this->asked++;
        $found = [];
        $queue = [$tenant];
        while ($queue !== []) {
            $node = array_shift($queue);
            foreach ($this->parents as $child => $parent) {
                if ($parent === $node && !\in_array($child, $found, true)) {
                    $found[] = $child;
                    $queue[] = $child;
                }
            }
        }

        return $found;
    }
}

/** Each tenant's one script, and a run under it, in a database of their own. */
final class Tenants
{
    public const array ALL = ['reseller', 'acme', 'acme-west', 'globex', 'other'];

    /** @var array<string, string> tenant => its script's id */
    public array $scripts = [];

    public readonly PdoConnection $connection;

    public function __construct()
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE scripts (id TEXT PRIMARY KEY, name TEXT NOT NULL, tenant_id TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE script_runs (id TEXT PRIMARY KEY, status TEXT NOT NULL, script_id TEXT NOT NULL, tenant_id TEXT NOT NULL)');

        foreach (self::ALL as $tenant) {
            $id = Uuid::v7();
            $this->scripts[$tenant] = $id;
            $pdo->prepare('INSERT INTO scripts VALUES (?, ?, ?)')->execute([$id, "{$tenant}-script", $tenant]);
            $pdo->prepare('INSERT INTO script_runs VALUES (?, ?, ?, ?)')->execute([Uuid::v7(), "{$tenant}-run", $id, $tenant]);
        }

        $this->connection = new PdoConnection($pdo, 'hierarchy-test');
    }

    public function orm(?Tree $tree = new Tree()): Orm
    {
        return new Orm($this->connection, new IdentityMap('hierarchy-test'), $tree === null ? null : new TenantHierarchy($tree));
    }
}
