<?php

declare(strict_types=1);

/**
 * Row-level security behind a pooler in transaction or statement mode.
 *
 * The tenant the policies read is a session setting, told only when it
 * changes. Behind PgBouncer in transaction mode a later statement can run
 * on a server session another worker told its own tenant. Each tell asks
 * which backend answered, in the same round trip, and one that is not the
 * session's first is refused, and every tell after it.
 *
 * The pooler is played by a pg_backend_pid() of the test's own, ahead of
 * pg_catalog's on the search path, that answers another backend each call:
 * what a transaction pooler under load answers.
 */

namespace Tinvar\Tests\Orm\SessionMoved;

use Tinvar\Orm\Async\PostgresDriver;
use Tinvar\Orm\AsyncConnection;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\SessionMoved;
use Tinvar\Tests\Orm\Fixtures\Databases;

require_once __DIR__ . '/Fixtures/databases.php';

/**
 * The three settings the policies read, as the session holds them.
 *
 * @return array{string, string, string}
 */
function settings(\PDO $pdo): array
{
    $row = $pdo->query("SELECT current_setting('app.tenant', true) AS tenant, current_setting('app.across_tenants', true) AS across, current_setting('app.subtree', true) AS subtree")->fetch(\PDO::FETCH_ASSOC);

    return [(string) $row['tenant'], (string) $row['across'], (string) $row['subtree']];
}

/** Shadows pg_backend_pid() in $schema with one answering 1, 2, 3, ... */
function movingBackends(\PDO $pdo, string $schema): void
{
    $pdo->exec("CREATE SEQUENCE {$schema}.moving_backend");
    $pdo->exec("CREATE FUNCTION {$schema}.pg_backend_pid() RETURNS integer LANGUAGE sql AS 'SELECT nextval(''{$schema}.moving_backend'')::integer'");
}

describe('on Postgres', function () {
    it('tells a session one tenant after another while the same backend answers', function () {
        Databases::inScratchSchema('pgsql', static function (\PDO $pdo): void {
            $connection = new PdoConnection($pdo, 'pgsql');
            $tenant = 'acme';
            $connection->followTenant(static function () use (&$tenant): array {
                return [$tenant, false, []];
            });

            $seen = [];
            foreach (['acme', 'globex', 'acme', 'initech'] as $tenant) {
                $seen[] = $connection->select("SELECT current_setting('app.tenant', true) AS tenant")[0]['tenant'];
            }

            expect($seen)->toBe(['acme', 'globex', 'acme', 'initech']);
            $connection->clearTenantSetting();
        });
    });

    it('refuses a tenant another backend answered, and every tenant after', function () {
        Databases::inScratchSchema('pgsql', static function (\PDO $pdo, string $schema): void {
            movingBackends($pdo, $schema);
            $pdo->exec("SET search_path TO {$schema}, pg_catalog");

            $connection = new PdoConnection($pdo, 'pgsql');
            $tenant = 'acme';
            $connection->followTenant(static function () use (&$tenant): array {
                return [$tenant, false, []];
            });

            expect($connection->select('SELECT 1 AS one')[0]['one'])->toBe(1);

            $tenant = 'globex';
            expect(fn () => $connection->select('SELECT 1 AS one'))->toThrow(SessionMoved::class, 'pooler in session mode');

            // Even back to a tenant told before, and with nothing to tell.
            $tenant = 'acme';
            expect(fn () => $connection->select('SELECT 1 AS one'))->toThrow(SessionMoved::class)
                ->and(fn () => $connection->select('SELECT 1 AS one'))->toThrow(SessionMoved::class);

            // The reset a worker ends a request with still runs.
            $connection->clearTenantSetting();
            $pdo->exec("SELECT set_config('app.tenant', '', false), set_config('app.across_tenants', 'off', false), set_config('app.subtree', '', false)");
        });
    });

    it('leaves the session that answered a refused tell holding no tenant, across_tenants or subtree', function () {
        Databases::inScratchSchema('pgsql', static function (\PDO $pdo, string $schema): void {
            movingBackends($pdo, $schema);
            $pdo->exec("SET search_path TO {$schema}, pg_catalog");

            $connection = new PdoConnection($pdo, 'pgsql');
            $told = ['acme', true, ['acme', 'acme-eu']];
            $connection->followTenant(static function () use (&$told): array {
                return $told;
            });

            $connection->select('SELECT 1 AS one');
            expect(settings($pdo))->toBe(['acme', 'on', '{"acme","acme-eu"}']);

            // The session the pooler handed over is told nothing of globex,
            // and what it held is cleared before the refusal: the next
            // client it is handed to runs under no tenant.
            $told = ['globex', false, ['globex']];
            expect(fn () => $connection->select('SELECT 1 AS one'))->toThrow(SessionMoved::class)
                ->and(settings($pdo))->toBe(['', 'off', '']);
        });
    });

    it('still clears the session at the end of a request once refused', function () {
        Databases::inScratchSchema('pgsql', static function (\PDO $pdo, string $schema): void {
            movingBackends($pdo, $schema);
            $pdo->exec("SET search_path TO {$schema}, pg_catalog");

            $connection = new PdoConnection($pdo, 'pgsql');
            $tenant = 'acme';
            $connection->followTenant(static function () use (&$tenant): array {
                return [$tenant, false, []];
            });

            $connection->select('SELECT 1 AS one');
            $tenant = 'globex';
            expect(fn () => $connection->select('SELECT 1 AS one'))->toThrow(SessionMoved::class);

            // Whichever session the pooler hands the reset to, told by
            // anyone, is left holding nothing.
            $pdo->exec("SELECT set_config('app.tenant', 'initech', false), set_config('app.across_tenants', 'on', false), set_config('app.subtree', '{initech}', false)");
            $connection->clearTenantSetting();

            expect(settings($pdo))->toBe(['', 'off', ''])
                ->and(fn () => $connection->select('SELECT 1 AS one'))->toThrow(SessionMoved::class);
        });
    });

    it('sets nothing on a backend the async driver did not expect, and clears what it held', function () {
        $driver = new PostgresDriver(Databases::asyncPostgres(''));
        $link = $driver->connect();
        try {
            $backend = $driver->backend($link);
            $setting = static fn (): array => array_values($link->query("SELECT current_setting('app.tenant', true) AS tenant, current_setting('app.across_tenants', true) AS across, current_setting('app.subtree', true) AS subtree, current_setting('default_transaction_read_only') AS read_only")->fetchRow() ?? []);

            expect($driver->tell($link, $backend, 'acme', true, ['acme'], false))->toBe($backend)
                ->and($setting())->toBe(['acme', 'on', '{"acme"}', 'off']);

            expect($driver->tell($link, $backend + 1, 'globex', false, ['globex'], true))->toBe($backend)
                ->and($setting())->toBe(['', 'off', '', 'off']);

            $driver->tell($link, $backend, 'acme', true, ['acme'], false);
            $link->query('BEGIN');
            expect($driver->tellTenant($link, $backend + 1, 'globex', false, ['globex']))->toBe($backend)
                ->and($setting())->toBe(['', 'off', '', 'off']);
            $link->query('COMMIT');
        } finally {
            $link->close();
        }
    })->skip(Databases::asyncPostgresMissing() !== null, Databases::asyncPostgresMissing() ?? '');

    it('refuses it on the async driver, whose sessions are told at each statement', function () {
        Databases::inScratchSchema('pgsql', static function (\PDO $pdo, string $schema): void {
            movingBackends($pdo, $schema);

            $connection = AsyncConnection::postgres(Databases::asyncPostgres("-c search_path={$schema},pg_catalog"), 'pgsql-moving');
            try {
                $connection->followTenant(static fn (): array => ['acme', false, []]);

                expect(fn () => $connection->select('SELECT 1 AS one'))->toThrow(SessionMoved::class)
                    ->and(fn () => $connection->select('SELECT 1 AS one'))->toThrow(SessionMoved::class);
            } finally {
                $connection->close();
            }
        });
    })->skip(Databases::asyncPostgresMissing() !== null, Databases::asyncPostgresMissing() ?? '');
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');
