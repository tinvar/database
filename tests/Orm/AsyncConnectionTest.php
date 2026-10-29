<?php

declare(strict_types=1);

/**
 * The async driver (ADR 0087) against a real Postgres, under row-level
 * security as a role that is not a superuser, and a real MySQL: that a
 * tick's statements overlap, that each fiber's session carries its own
 * tenant and nothing of anyone else's, that a transaction is its fiber's
 * alone, and that a session is never handed on with another request's
 * state or the rest of an answer on it.
 *
 * QueryDatabasesTest runs the query builder's whole suite through it as
 * well. Each group is skipped, saying why, without its server.
 */

namespace Tinvar\Tests\Orm\AsyncConnection;

use Tinvar\Orm\AsyncConnection;
use Tinvar\Orm\Orm;
use Tinvar\Orm\ReadOnlyViolation;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Orm\Fixtures\Shelf;
use Tinvar\Tests\Orm\Fixtures\Shelves;
use Tinvar\Tests\Orm\Fixtures\UnscopedShelf;

use function Amp\async;
use function Amp\delay;
use function Amp\Future\await;

require_once __DIR__ . '/Fixtures/shelves.php';

/** @return list<string> */
function brands(AsyncConnection $connection): array
{
    return array_map(
        static fn (array $row): string => (string) $row['name'],
        $connection->select('SELECT name FROM brands ORDER BY name'),
    );
}

/** @return list<string> */
function unscopedShelves(Orm $orm): array
{
    return array_map(static fn (UnscopedShelf $shelf): string => $shelf->name, $orm->query(UnscopedShelf::class)->orderBy('name')->fetch());
}

/** The backend serving the calling fiber's session. */
function backend(AsyncConnection $connection): int
{
    $pid = $connection->select('SELECT pg_backend_pid() AS pid')[0]['pid'];

    return \is_int($pid) ? $pid : throw new \LogicException('No backend pid.');
}

describe('on Postgres, with row-level security', function () {
    it('runs statements from different fibers at once, each on a session of its own', function () {
        Shelves::onPostgresAsync(static function (Shelves $fixture, AsyncConnection $connection): void {
            $started = hrtime(true);
            $pids = await(array_map(
                static fn (int $i) => async(static function () use ($connection): int {
                    $connection->select('SELECT pg_sleep(0.3)');

                    return backend($connection);
                }),
                [1, 2, 3],
            ));
            $seconds = (hrtime(true) - $started) / 1e9;

            // Three 0.3 s sleeps one after another take 0.9 s.
            expect($seconds)->toBeLessThan(0.75)
                ->and(array_unique($pids))->toHaveCount(3)
                ->and($connection->sessionsOpen())->toBe(3);
        });
    });

    it('overlaps a render tick\'s query bodies and still loads a relation once for every fiber that wants it', function () {
        Shelves::onPostgresAsync(static function (Shelves $fixture, AsyncConnection $connection): void {
            $orm = $fixture->orm(connection: $connection);
            $scheduler = $orm->beginRender();
            $connection->resetStatementCount();

            $body = static fn (string $name): \Closure => static fn (): array => $orm->actAs('acme', static function () use ($orm, $connection, $name): array {
                $connection->select('SELECT pg_sleep(0.3)');
                $shelf = $orm->query(Shelf::class)->where('name', $name)->first();

                return array_map(static fn ($item): string => $item->name, iterator_to_array($shelf?->items ?? []));
            });

            $started = hrtime(true);
            $results = $orm->readOnly(static fn (): array => $scheduler->run([
                'alpha' => $body('Alpha'),
                'bravo' => $body('Bravo'),
                'foxtrot' => $body('Foxtrot'),
            ]));
            $seconds = (hrtime(true) - $started) / 1e9;

            $itemLoads = array_filter($connection->statementLog(), static fn (string $sql): bool => str_contains($sql, 'FROM "items"'));

            expect($results)->toBe(['alpha' => ['apple', 'apricot'], 'bravo' => ['banana'], 'foxtrot' => ['fig']])
                ->and($seconds)->toBeLessThan(0.75)
                ->and($itemLoads)->toHaveCount(1)
                ->and($scheduler->rounds())->toBe(1);
        });
    });

    it('never lets one fiber\'s tenant reach another fiber\'s statement', function () {
        // Two sessions for six fibers: each is handed from tenant to tenant
        // over and over while the others are mid-statement.
        Shelves::onPostgresAsync(static function (Shelves $fixture, AsyncConnection $connection): void {
            $orm = $fixture->orm(connection: $connection);
            $read = static fn (string $tenant): \Closure => static function () use ($orm, $connection, $tenant): array {
                $seen = [];
                for ($round = 0; $round < 3; $round++) {
                    $seen[] = $orm->actAs($tenant, static function () use ($orm, $connection): array {
                        $setting = $connection->select("SELECT current_setting('app.tenant', true) AS tenant, pg_sleep(0.02)")[0]['tenant'];

                        return [$setting, unscopedShelves($orm)];
                    });
                    $connection->release();
                }

                return $seen;
            };

            $seen = await([
                'acme-1' => async($read('acme')),
                'globex-1' => async($read('globex')),
                'acme-2' => async($read('acme')),
                'globex-2' => async($read('globex')),
                'acme-3' => async($read('acme')),
                'globex-3' => async($read('globex')),
            ]);

            foreach ($seen as $fiber => $rounds) {
                $tenant = explode('-', $fiber)[0];
                foreach ($rounds as [$setting, $shelves]) {
                    expect($setting)->toBe($tenant)
                        ->and($shelves)->toBe($tenant === 'acme' ? ['Alpha', 'Bravo', 'Charlie', 'Foxtrot'] : ['Delta', 'Echo']);
                }
            }
        }, poolSize: 2);
    });

    it('hands a session on cleared: the next fiber on it has no tenant and reads no row', function () {
        Shelves::onPostgresAsync(static function (Shelves $fixture, AsyncConnection $connection): void {
            $orm = $fixture->orm(connection: $connection);

            [$acmePid, $acmeRows] = async(static fn (): array => $orm->actAs('acme', static fn (): array => [backend($connection), unscopedShelves($orm)]))->await();
            [$nextPid, $setting, $nextRows] = async(static fn (): array => [
                backend($connection),
                $connection->select("SELECT current_setting('app.tenant', true) AS tenant")[0]['tenant'],
                unscopedShelves($orm),
            ])->await();

            expect($nextPid)->toBe($acmePid)
                ->and($acmeRows)->toHaveCount(4)
                ->and($setting)->toBe('')
                ->and($nextRows)->toBe([]);
        }, poolSize: 1);
    });

    it('hands the next fiber none of a session\'s temporary tables, advisory locks or settings', function () {
        Shelves::onPostgresAsync(static function (Shelves $fixture, AsyncConnection $connection): void {
            $left = async(static function () use ($connection): int {
                $connection->execute('CREATE TEMPORARY TABLE scratch (a INTEGER)');
                $connection->select('SELECT pg_advisory_lock(87)');
                $connection->execute("SET statement_timeout = '7s'");
                $connection->select("SELECT set_config('app.other', 'x', false)");

                return backend($connection);
            })->await();

            [$pid, $state] = async(static fn (): array => [backend($connection), $connection->select(
                "SELECT to_regclass('pg_temp.scratch') AS scratch, current_setting('statement_timeout') AS timeout, "
                . "current_setting('app.other', true) AS other, "
                . "(SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()) AS locks",
            )[0]])->await();

            expect($pid)->toBe($left)
                ->and($state['scratch'])->toBeNull()
                ->and($state['timeout'])->toBe('0')
                ->and($state['other'] ?? '')->toBe('')
                ->and($state['locks'])->toBe(0);
        }, poolSize: 1);
    });

    it('clears what a raw statement set on a session, and closes one it cannot clear', function () {
        Shelves::onPostgresAsync(static function (Shelves $fixture, AsyncConnection $connection): void {
            // A raw SET ROLE: the session starts as the policy's role, and
            // so does the next fiber on it.
            $role = $connection->select('SELECT current_user AS role')[0]['role'];
            // The pool's one session, which the fibers below need.
            $connection->release();
            async(static fn (): int => $connection->execute('SET ROLE postgres'))->await();
            $after = async(static fn (): mixed => $connection->select('SELECT current_user AS role')[0]['role'])->await();

            // A transaction begun around transaction(), which DISCARD ALL
            // refuses to run inside: the session cannot be cleared, so it
            // is closed, tenant and all.
            $before = $connection->sessionsOpen();
            $stuck = async(static function () use ($connection): int {
                $connection->execute('BEGIN');
                $connection->select("SELECT set_config('app.tenant', 'globex', false)");

                return backend($connection);
            })->await();
            $connection->rollBackOpenTransaction();
            [$pid, $tenant] = async(static fn (): array => [
                backend($connection),
                $connection->select("SELECT current_setting('app.tenant', true) AS tenant")[0]['tenant'],
            ])->await();

            expect($after)->toBe($role)
                ->and($connection->sessionsOpen())->toBe($before)
                ->and($pid)->not->toBe($stuck)
                ->and($tenant ?? '')->toBe('');
        }, poolSize: 1);
    });

    it('keeps a transaction to the fiber that began it', function () {
        Shelves::onPostgresAsync(static function (Shelves $fixture, AsyncConnection $connection): void {
            $writer = async(static function () use ($connection): bool {
                try {
                    $connection->transaction(static function () use ($connection): never {
                        $connection->execute("INSERT INTO brands VALUES ('b-omega', 'Omega', 9)");
                        delay(0.3);

                        throw new \RuntimeException('the writer gives up');
                    });
                } catch (\RuntimeException) {
                }

                return $connection->inTransaction();
            });

            $reader = async(static function () use ($connection): array {
                delay(0.1);
                $inTransaction = $connection->inTransaction();
                $seen = brands($connection);
                $connection->execute("INSERT INTO brands VALUES ('b-psi', 'Psi', 8)");

                return [$inTransaction, $seen];
            });

            [$readerInTransaction, $readerSaw] = $reader->await();

            expect($writer->await())->toBeFalse()
                ->and($readerInTransaction)->toBeFalse()
                ->and($readerSaw)->toBe(['Xi', 'Ypsilon', 'Zeta'])
                // The writer's rollback took its own write and not the
                // reader's, which ran on a session of its own.
                ->and(brands($connection))->toBe(['Psi', 'Xi', 'Ypsilon', 'Zeta']);
        });
    });

    it('keeps nested transactions in savepoints, and refuses to commit one a failed statement aborted', function () {
        Shelves::onPostgresAsync(static function (Shelves $fixture, AsyncConnection $connection): void {
            $connection->transaction(static function () use ($connection): void {
                $connection->execute("INSERT INTO brands VALUES ('b-1', 'Outer', 1)");
                try {
                    $connection->transaction(static function () use ($connection): never {
                        $connection->execute("INSERT INTO brands VALUES ('b-2', 'Inner', 2)");

                        throw new \RuntimeException('inner fails');
                    });
                } catch (\RuntimeException) {
                }
                $connection->execute("INSERT INTO brands VALUES ('b-3', 'After', 3)");
            });

            $aborted = static fn (): mixed => $connection->transaction(static function () use ($connection): void {
                $connection->execute("INSERT INTO brands VALUES ('b-4', 'Lost', 4)");
                try {
                    $connection->execute('SELECT no_such_column FROM brands');
                } catch (\PDOException) {
                }
            });

            expect($aborted)->toThrow(\PDOException::class, 'rolled back, not committed')
                ->and(brands($connection))->toBe(['After', 'Outer', 'Xi', 'Ypsilon', 'Zeta'])
                ->and($connection->inTransaction())->toBeFalse();
        });
    });

    it('runs a fiber\'s afterCommit() callbacks on its own commit, not another fiber\'s', function () {
        Shelves::onPostgresAsync(static function (Shelves $fixture, AsyncConnection $connection): void {
            $orm = $fixture->orm(connection: $connection);
            /** @var \ArrayObject<int, string> $told */
            $told = new \ArrayObject();

            $slow = async(static fn () => $orm->transaction(static function () use ($orm, $connection, $told): void {
                $connection->execute("INSERT INTO brands VALUES ('b-s', 'Slow', 1)");
                $orm->afterCommit(static fn () => $told->append('slow committed'));
                delay(0.3);
                $told->append('slow commits');
            }));
            $quick = async(static fn () => $orm->transaction(static function () use ($orm, $connection, $told): void {
                delay(0.1);
                $connection->execute("INSERT INTO brands VALUES ('b-q', 'Quick', 2)");
                $orm->afterCommit(static fn () => $told->append('quick committed'));
            }));

            await([$slow, $quick]);

            expect($told->getArrayCopy())->toBe(['quick committed', 'slow commits', 'slow committed']);
        });
    });

    it('closes a session whose statement runs past the timeout, and hands out a fresh one', function () {
        Shelves::onPostgresAsync(static function (Shelves $fixture, AsyncConnection $connection): void {
            $slow = backend($connection);
            $timedOut = static fn (): array => $connection->select('SELECT pg_sleep(5)');

            expect($timedOut)->toThrow(\PDOException::class, 'timeout')
                ->and($connection->sessionsOpen())->toBe(0)
                ->and(backend($connection))->not->toBe($slow);
        }, statementTimeout: 0.3);
    });

    it('closes a session a request ends under mid-statement, rather than hand it back with the answer pending', function () {
        Shelves::onPostgresAsync(static function (Shelves $fixture, AsyncConnection $connection): void {
            $inFlight = async(static function () use ($connection): array {
                backend($connection);

                return $connection->select('SELECT pg_sleep(5)');
            });
            delay(0.2);

            $started = hrtime(true);
            $connection->rollBackOpenTransaction();
            $connection->clearTenantSetting();

            expect(static fn () => $inFlight->await())->toThrow(\PDOException::class)
                ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(2.0)
                ->and($connection->sessionsOpen())->toBe(0)
                ->and($connection->idleSessions())->toBe([]);
        });
    });

    it('rolls back a fiber\'s transaction the request ends under, and refuses its statements after', function () {
        Shelves::onPostgresAsync(static function (Shelves $fixture, AsyncConnection $connection): void {
            $writer = async(static fn () => $connection->transaction(static function () use ($connection): void {
                $connection->execute("INSERT INTO brands VALUES ('b-early', 'Early', 1)");
                delay(0.3);
                $connection->execute("INSERT INTO brands VALUES ('b-late', 'Late', 2)");
            }));
            delay(0.1);

            expect($connection->rollBackOpenTransaction())->toBeTrue();

            expect(static fn () => $writer->await())->toThrow(\LogicException::class, 'has ended')
                ->and(brands($connection))->toBe(['Xi', 'Ypsilon', 'Zeta']);
        });
    });

    it('keeps the pool across a request\'s end and none of the request\'s state', function () {
        Shelves::onPostgresAsync(static function (Shelves $fixture, AsyncConnection $connection): void {
            $orm = $fixture->orm(connection: $connection);

            // A request: fibers in two tenants, one widened across them, and
            // two never resumed -- one inside a read-only scope, one inside a
            // transaction with a write in it.
            await([
                async(static fn () => $orm->actAs('acme', static fn (): array => unscopedShelves($orm))),
                async(static fn () => $orm->acrossTenants(static fn (): array => unscopedShelves($orm))),
            ]);
            $readOnly = new \Fiber(static fn () => $orm->readOnly(static function () use ($orm): void {
                $orm->actAs('globex', static fn (): array => unscopedShelves($orm));
                \Fiber::suspend();
            }));
            $writing = new \Fiber(static fn () => $connection->transaction(static function () use ($connection): void {
                $connection->execute("INSERT INTO brands VALUES ('b-left', 'Left', 1)");
                \Fiber::suspend();
            }));
            // The write first: once the scope is open, every fiber's
            // statements refuse writes (Connection::readOnly()).
            $writing->start();
            delay(0.1);
            $readOnly->start();
            // Their statements answered, each is left at its own suspend.
            delay(0.1);
            $open = $connection->sessionsOpen();

            // The worker's reset contract: RequestScope, then Orm::flush().
            $connection->rollBackOpenTransaction();
            $orm->flush();

            $states = array_map(
                static fn ($session): ?array => $session->link->query(
                    "SELECT current_setting('app.tenant', true) AS tenant, current_setting('app.across_tenants', true) AS across, "
                    . "current_setting('default_transaction_read_only') AS read_only, current_user AS role, "
                    . 'pg_current_xact_id_if_assigned() AS xact',
                )->fetchRow(),
                $connection->idleSessions(),
            );

            expect($open)->toBeGreaterThan(1)
                ->and($connection->sessionsOpen())->toBe($open)
                ->and($states)->toHaveCount($open)
                ->and($connection->isReadOnly())->toBeFalse()
                ->and($connection->inTransaction())->toBeFalse()
                ->and(brands($connection))->toBe(['Xi', 'Ypsilon', 'Zeta']);
            foreach ($states as $state) {
                expect($state)->not->toBeNull()
                    ->and($state['tenant'] ?? '')->toBe('')
                    ->and($state['across'] ?? '')->not->toBe('on')
                    ->and($state['read_only'] ?? null)->toBe('off')
                    ->and($state['role'] ?? '')->toStartWith('tinvar_rls_')
                    ->and($state['xact'] ?? null)->toBeNull();
            }
        });
    });

    it('refuses writes in a read-only scope, from any fiber, in the database', function () {
        Shelves::onPostgresAsync(static function (Shelves $fixture, AsyncConnection $connection): void {
            $write = static fn (string $id): int => $connection->execute("INSERT INTO brands VALUES ('{$id}', '{$id}', 1)");

            $refusal = static function (\Closure $write): ?string {
                try {
                    $write();

                    return null;
                } catch (\Throwable $e) {
                    return $e::class;
                }
            };
            $refused = $connection->readOnly(static fn (): array => [
                async(static fn () => $refusal(static fn () => $write('Wa')))->await(),
                $refusal(static fn () => $connection->transaction(static fn (): int => $write('Wb'))),
            ]);

            // A scope opened inside a transaction already open: a savepoint
            // set read-only, rolled back to at the scope's end.
            $inside = $connection->transaction(static function () use ($connection, $write): bool {
                $refusedInside = false;
                try {
                    $connection->readOnly(static fn (): int => $write('Wc'));
                } catch (ReadOnlyViolation) {
                    $refusedInside = true;
                }
                $write('Wd');

                return $refusedInside;
            });

            expect($refused)->toBe([ReadOnlyViolation::class, ReadOnlyViolation::class])
                ->and($inside)->toBeTrue()
                ->and(brands($connection))->toBe(['Wd', 'Xi', 'Ypsilon', 'Zeta']);
        });
    });
})->group('pgsql')->skip(Databases::asyncPostgresMissing() !== null, Databases::asyncPostgresMissing() ?? '');

describe('on MySQL', function () {
    it('runs statements from different fibers at once', function () {
        Shelves::onMySqlAsync(static function (Shelves $fixture, AsyncConnection $connection): void {
            $started = hrtime(true);
            await(array_map(static fn (int $i) => async(static fn (): array => $connection->select('SELECT SLEEP(0.3)')), [1, 2, 3]));

            expect((hrtime(true) - $started) / 1e9)->toBeLessThan(0.75)
                ->and($connection->sessionsOpen())->toBe(3);
        });
    });

    it('keeps a transaction to the fiber that began it', function () {
        Shelves::onMySqlAsync(static function (Shelves $fixture, AsyncConnection $connection): void {
            $writer = async(static function () use ($connection): void {
                try {
                    $connection->transaction(static function () use ($connection): never {
                        $connection->execute("INSERT INTO brands VALUES ('b-omega', 'Omega', 9)");
                        delay(0.3);

                        throw new \RuntimeException('the writer gives up');
                    });
                } catch (\RuntimeException) {
                }
            });
            $reader = async(static function () use ($connection): array {
                delay(0.1);
                $seen = brands($connection);
                $connection->execute("INSERT INTO brands VALUES ('b-psi', 'Psi', 8)");

                return $seen;
            });

            expect($reader->await())->toBe(['Xi', 'Ypsilon', 'Zeta']);
            $writer->await();
            expect(brands($connection))->toBe(['Psi', 'Xi', 'Ypsilon', 'Zeta']);
        });
    });

    it('refuses writes in a read-only scope in the database, and clears the refusal with the session', function () {
        Shelves::onMySqlAsync(static function (Shelves $fixture, AsyncConnection $connection): void {
            $refused = static fn () => $connection->readOnly(
                static fn (): int => $connection->execute("INSERT INTO brands VALUES ('b-a', 'A', 1)"),
            );

            expect($refused)->toThrow(ReadOnlyViolation::class);

            $connection->rollBackOpenTransaction();
            $states = array_map(
                static fn ($session): mixed => $session->link->query('SELECT @@SESSION.transaction_read_only AS ro')->fetchRow()['ro'] ?? null,
                $connection->idleSessions(),
            );

            expect($states)->each->toBe(0)
                ->and($connection->execute("INSERT INTO brands VALUES ('b-b', 'B', 1)"))->toBe(1);
        });
    });

    it('hands the next fiber none of a session\'s transaction, variables, temporary tables, locks or modes', function () {
        Shelves::onMySqlAsync(static function (Shelves $fixture, AsyncConnection $connection): void {
            $mode = $connection->select('SELECT @@SESSION.sql_mode AS mode')[0]['mode'];
            $connection->release();

            $left = async(static function () use ($connection): int {
                $connection->execute('START TRANSACTION');
                $connection->execute("INSERT INTO brands VALUES ('b-open', 'Open', 1)");
                $connection->execute('SET @tenant = 42');
                $connection->execute('CREATE TEMPORARY TABLE scratch (a INTEGER)');
                $connection->select("SELECT GET_LOCK('tinvar_87', 0)");
                $connection->execute("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");

                return (int) $connection->select('SELECT CONNECTION_ID() AS id')[0]['id'];
            })->await();

            [$id, $state, $scratch] = async(static function () use ($connection): array {
                $state = $connection->select(
                    "SELECT CONNECTION_ID() AS id, @tenant AS tenant, IS_USED_LOCK('tinvar_87') AS holder, @@SESSION.sql_mode AS mode",
                )[0];
                try {
                    $connection->select('SELECT * FROM scratch');
                    $scratch = true;
                } catch (\PDOException) {
                    $scratch = false;
                }

                return [(int) $state['id'], $state, $scratch];
            })->await();

            expect($id)->toBe($left)
                ->and($state['tenant'])->toBeNull()
                ->and($state['holder'])->toBeNull()
                ->and($state['mode'])->toBe($mode)
                ->and($scratch)->toBeFalse()
                ->and(brands($connection))->toBe(['Xi', 'Ypsilon', 'Zeta']);
        }, poolSize: 1);
    });

    it('closes a session whose statement runs past the timeout', function () {
        Shelves::onMySqlAsync(static function (Shelves $fixture, AsyncConnection $connection): void {
            $connection->select('SELECT 1');
            $timedOut = static fn (): array => $connection->select('SELECT SLEEP(5)');

            expect($timedOut)->toThrow(\PDOException::class, 'timeout')
                ->and($connection->sessionsOpen())->toBe(0)
                ->and($connection->select('SELECT 1 AS one'))->toBe([['one' => 1]]);
        }, statementTimeout: 0.3);
    });
})->group('mysql')->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');
