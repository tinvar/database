<?php

declare(strict_types=1);

namespace Tinvar\Tests\Orm\Fixtures;

use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\HasMany;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\TenantScoped;
use Tinvar\Orm\Collection;
use Tinvar\Orm\Model;
use Tinvar\Orm\PdoConnection;
use Tinvar\Support\Uuid;

require_once __DIR__ . '/tenancy.php';

#[Table('scripts'), TenantScoped]
final class Script extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;

    /** @var Collection<ScriptRun> */
    #[HasMany(ScriptRun::class)]
    public Collection $runs;
}

#[Table('script_runs'), TenantScoped]
final class ScriptRun extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $status;
}

/** Two tenants, each with its own scripts and runs. */
final class TenantFixture
{
    /** @var array<string, list<string>> tenant => script ids */
    public array $scripts = [];

    public PdoConnection $connection;

    public function __construct()
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE scripts (id TEXT PRIMARY KEY, name TEXT NOT NULL, tenant_id TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE script_runs (id TEXT PRIMARY KEY, status TEXT NOT NULL, script_id TEXT NOT NULL, tenant_id TEXT NOT NULL)');

        foreach (['acme', 'globex'] as $tenant) {
            for ($i = 1; $i <= 2; $i++) {
                $id = Uuid::v7();
                $this->scripts[$tenant][] = $id;
                $pdo->prepare('INSERT INTO scripts VALUES (?, ?, ?)')
                    ->execute([$id, "{$tenant}-script-{$i}", $tenant]);
                $pdo->prepare('INSERT INTO script_runs VALUES (?, ?, ?, ?)')
                    ->execute([Uuid::v7(), 'ok', $id, $tenant]);
            }
        }

        $this->connection = new PdoConnection($pdo, 'tenant-test');
        $this->connection->resetStatementCount();
    }
}
