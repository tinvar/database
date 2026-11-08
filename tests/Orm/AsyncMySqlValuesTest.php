<?php

declare(strict_types=1);

/**
 * The async MySQL driver binds fewer values to a statement than the others
 * (ADR 0196, "What a review found"): amphp/mysql sends no statement of more
 * than about 3,600 ("Malformed communication packet"). So on it the Orm
 * lists at most Orm::maxValues() keys to a statement, and binds at most
 * maxBindings() values to an insert, and a read, a relation load, an
 * insert, an update and a delete of 4,500 rows each run in parts -- as on
 * every other driver past 10,000.
 */

namespace Tinvar\Tests\Orm\AsyncMySqlValues;

use Tinvar\Orm\AsyncConnection;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Query;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Tests\Orm\Fixtures\ScriptRun;
use Tinvar\Tests\Orm\Fixtures\Tenancy;

require_once __DIR__ . '/Fixtures/tenant.php';
require_once __DIR__ . '/Fixtures/databases.php';

it('lists keys a part at a time on the async MySQL driver alone', function () {
    Databases::inScratchSchema('mysql', static function (\PDO $pdo, string $database): void {
        $async = AsyncConnection::mysql(Databases::asyncMySql($database, 'ANSI_QUOTES'), 'mysql-async');
        try {
            expect(new Orm($async, tenants: Tenancy::flat())->maxValues())->toBe(2_000)
                ->and(new Orm($async, tenants: Tenancy::flat())->maxBindings())->toBe(3_000)
                ->and(new Orm(new PdoConnection($pdo, 'mysql'), tenants: Tenancy::flat())->maxValues())->toBe(Query::MAX_VALUES)
                ->and(new Orm(PdoConnection::sqlite(':memory:', 'x'), tenants: Tenancy::flat())->maxBindings())->toBe(Query::MAX_BINDINGS);
        } finally {
            $async->close();
        }
    });
})->group('mysql')->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');

it('reads, loads, inserts, updates and deletes 4,500 rows on the async MySQL driver', function () {
    Databases::inScratchSchema('mysql', static function (\PDO $pdo, string $database): void {
        $pdo->exec('CREATE TABLE scripts (id VARCHAR(36) PRIMARY KEY, name VARCHAR(191) NOT NULL, tenant_id VARCHAR(191) NOT NULL)');
        $pdo->exec('CREATE TABLE script_runs (id VARCHAR(36) PRIMARY KEY, status VARCHAR(191) NOT NULL, script_id VARCHAR(36) NOT NULL, tenant_id VARCHAR(191) NOT NULL)');
        $mode = $pdo->query("SELECT CONCAT(@@sql_mode, ',ANSI_QUOTES')")?->fetchColumn();
        $async = AsyncConnection::mysql(Databases::asyncMySql($database, \is_string($mode) ? $mode : 'ANSI_QUOTES'), 'mysql-async');
        $orm = new Orm($async, new IdentityMap('async-values'), Tenancy::flat());

        try {
            $orm->actAs('acme', static function () use ($orm, $pdo): void {
                $names = array_map(static fn (int $i): array => ['name' => "script-{$i}"], range(1, 4_500));

                expect($orm->query(Script::class)->insert($names))->toBe(4_500);

                $ids = array_map(strval(...), $orm->query(Script::class)->pluck('id'));
                foreach (array_chunk($ids, 500) as $chunk) {
                    $pdo->prepare('INSERT INTO script_runs (id, status, script_id, tenant_id) VALUES ' . implode(', ', array_fill(0, \count($chunk), "(UUID(), 'new', ?, 'acme')")))
                        ->execute($chunk);
                }

                // Read by key; every parent's relation loaded at once; then
                // written and deleted by key.
                $scripts = $orm->whereIn(Script::class, 'id', $ids);
                $loaded = $orm->query(Script::class)->with('runs')->fetch();

                expect($scripts)->toHaveCount(4_500)
                    ->and(array_sum(array_map(static fn (Script $s): int => \count($s->runs->all()), $loaded)))->toBe(4_500)
                    ->and($orm->query(ScriptRun::class)->update(['status' => 'seen']))->toBe(4_500)
                    ->and($orm->query(ScriptRun::class)->where('status', 'seen')->count())->toBe(4_500)
                    ->and($orm->query(ScriptRun::class)->delete())->toBe(4_500)
                    ->and($orm->query(ScriptRun::class)->count())->toBe(0);
            });
        } finally {
            $async->close();
        }
    });
})->group('mysql')->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');
