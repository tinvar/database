<?php

declare(strict_types=1);

namespace Tinvar\Tests\Orm\Fixtures;

use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\RowLevelSecurity;
use Tinvar\Support\Uuid;

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/databases.php';

/**
 * Runs $test on Postgres against two tenants' scripts with the policy on,
 * as a role the policy holds to.
 *
 * @param \Closure(PdoConnection, \PDO): void $test
 */
function withPolicies(\Closure $test): void
{
    Databases::inScratchSchema('pgsql', static function (\PDO $pdo, string $schema) use ($test): void {
        $pdo->exec('CREATE TABLE scripts (id TEXT PRIMARY KEY, name TEXT NOT NULL, tenant_id TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE script_runs (id TEXT PRIMARY KEY, status TEXT NOT NULL, script_id TEXT NOT NULL, tenant_id TEXT NOT NULL)');
        // Checked at the commit, so a tag for no script is a commit refused.
        $pdo->exec('CREATE TABLE script_tags (script_id TEXT NOT NULL REFERENCES scripts (id) DEFERRABLE INITIALLY DEFERRED)');
        foreach (['acme', 'globex'] as $tenant) {
            foreach ([1, 2] as $i) {
                $id = Uuid::v7();
                $pdo->prepare('INSERT INTO scripts VALUES (?, ?, ?)')->execute([$id, "{$tenant}-script-{$i}", $tenant]);
                $pdo->prepare('INSERT INTO script_runs VALUES (?, ?, ?, ?)')->execute([Uuid::v7(), 'ok', $id, $tenant]);
            }
        }
        foreach ([...RowLevelSecurity::forModel(Script::class), ...RowLevelSecurity::forModel(ScriptRun::class)] as $sql) {
            $pdo->exec($sql);
        }

        // The connecting role is a superuser here, and a superuser bypasses
        // every policy. The application must not connect as one; the test
        // becomes a role that is not, as the application would be.
        $role = 'tinvar_rls_' . bin2hex(random_bytes(4));
        $pdo->exec("CREATE ROLE {$role} NOLOGIN");
        $pdo->exec("GRANT USAGE ON SCHEMA {$schema} TO {$role}");
        $pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA {$schema} TO {$role}");
        $pdo->exec("SET ROLE {$role}");

        try {
            $connection = new PdoConnection($pdo, 'pgsql');
            $connection->resetStatementCount();
            $test($connection, $pdo);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // The connection is shared by every Postgres test in the run.
            $pdo->exec('RESET ROLE');
            $pdo->exec("SELECT set_config('app.tenant', '', false), set_config('app.across_tenants', 'off', false), set_config('app.subtree', '', false)");
            $pdo->exec("DROP OWNED BY {$role}");
            $pdo->exec("DROP ROLE {$role}");
        }
    });
}
