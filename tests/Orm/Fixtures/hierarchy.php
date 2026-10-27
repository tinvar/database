<?php

declare(strict_types=1);

namespace Tinvar\Tests\Orm\Fixtures\Hierarchy;

use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Support\Uuid;
use Tinvar\Tests\Orm\Fixtures\Tenancy;

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
final class Tree
{
    /** child => parent */
    public const array PARENTS = [
        'acme' => 'reseller',
        'globex' => 'reseller',
        'acme-west' => 'acme',
    ];
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

    /**
     * An Orm over the tree, as tinvar/tenancy would register one -- through
     * this suite's own port, which the database's suite runs without -- or
     * over tenants side by side, for null.
     *
     * @param array<string, string>|null $parents child => parent
     */
    public function orm(?array $parents = Tree::PARENTS): Orm
    {
        return new Orm($this->connection, new IdentityMap('hierarchy-test'), $parents === null ? Tenancy::flat() : Tenancy::tree($parents));
    }
}
