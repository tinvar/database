<?php

declare(strict_types=1);

/**
 * The read-only transaction on Postgres: BEGIN READ ONLY around loader and
 * query bodies, or a read-only savepoint inside a transaction the caller
 * already holds.
 *
 * Postgres is where the shared transaction can go wrong in a way SQLite's
 * query_only cannot: one failed statement aborts the whole transaction, and
 * every fiber in the render shares it. Skipped, saying why, without a server
 * (tests/Orm/Fixtures/databases.php says how one is found).
 */

namespace Tinvar\Tests\Orm\ReadOnlyTransactionPostgres;

use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\ReadOnlyViolation;
use Tinvar\Support\Uuid;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Databases;

require_once __DIR__ . '/Fixtures/models.php';
require_once __DIR__ . '/Fixtures/databases.php';

/**
 * Runs $test against a connection to a schema of its own, holding the
 * fixture's three tables: two racks of two units at one site.
 *
 * @param \Closure(PdoConnection, \PDO): void $test
 */
function onPostgres(\Closure $test): void
{
    Databases::inScratchSchema('pgsql', static function (\PDO $pdo) use ($test): void {
        $pdo->exec('CREATE TABLE sites (id TEXT PRIMARY KEY, code TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE racks (id TEXT PRIMARY KEY, name TEXT NOT NULL, height_u INTEGER NOT NULL, site_id TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE units (id TEXT PRIMARY KEY, position INTEGER NOT NULL, name TEXT NOT NULL, rack_id TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE things (id UUID PRIMARY KEY)');
        $pdo->exec('CREATE SEQUENCE tickets');

        $site = Uuid::v7();
        $pdo->prepare('INSERT INTO sites VALUES (?, ?)')->execute([$site, 'LAX']);
        foreach (['R-01', 'R-02'] as $name) {
            $rack = Uuid::v7();
            $pdo->prepare('INSERT INTO racks VALUES (?, ?, 42, ?)')->execute([$rack, $name, $site]);
            foreach ([1, 2] as $position) {
                $pdo->prepare('INSERT INTO units VALUES (?, ?, ?, ?)')->execute([Uuid::v7(), $position, "{$name}-{$position}", $rack]);
            }
        }

        $connection = new PdoConnection($pdo, 'pgsql');
        $connection->resetStatementCount();

        $test($connection, $pdo);
    });
}

/** @return list<string> */
function rackNames(\PDO $pdo): array
{
    return array_column($pdo->query('SELECT name FROM racks ORDER BY name COLLATE "C"')->fetchAll(\PDO::FETCH_ASSOC), 'name');
}

describe('on Postgres', function () {
    it('refuses writes sent around the ORM, in the database', function () {
        onPostgres(function (PdoConnection $connection, \PDO $pdo): void {
            $orm = new Orm($connection, new IdentityMap('test'));

            try {
                $orm->readOnly(static fn (): int => $connection->execute("UPDATE racks SET name = 'renamed'"));
                $this->fail('The write went through.');
            } catch (ReadOnlyViolation $e) {
                expect($e->getPrevious())->toBeInstanceOf(\PDOException::class)
                    ->and($e->getPrevious()?->getMessage())->toContain('25006');
            }

            expect(fn () => $orm->readOnly(static fn (): int|false => $pdo->exec('DELETE FROM units')))
                ->toThrow(\PDOException::class, 'read-only transaction')
                // A sequence is a write too, and the one a loader is likeliest to
                // reach for without noticing.
                ->and(fn () => $orm->readOnly(static fn (): array => $connection->select("SELECT nextval('tickets')")))
                ->toThrow(ReadOnlyViolation::class)
                ->and(rackNames($pdo))->toBe(['R-01', 'R-02'])
                ->and((int) $pdo->query('SELECT COUNT(*) FROM units')->fetchColumn())->toBe(4)
                ->and($pdo->inTransaction())->toBeFalse('the transaction ended with its scope');
        });
    });

    // One connection, one transaction: the fibers of a render share it, from
    // the first in to the last out, rather than each waiting its turn.
    it('runs a render\'s fibers in one transaction, which ends when the last leaves', function () {
        onPostgres(function (PdoConnection $connection, \PDO $pdo): void {
            $orm = new Orm($connection, new IdentityMap('test'));
            $started = static fn (): string => (string) $connection->select('SELECT transaction_timestamp()::text AS t')[0]['t'];
            $seen = [];

            $first = new \Fiber(static function () use ($orm, $started, $connection, &$seen): void {
                $orm->readOnly(static function () use ($started, $connection, &$seen): void {
                    $seen[] = $started();
                    $seen[] = $connection->select("SELECT current_setting('transaction_read_only') AS s")[0]['s'];
                    \Fiber::suspend();
                    $seen[] = $started();
                });
            });
            $second = new \Fiber(static function () use ($orm, $started, &$seen): void {
                $orm->readOnly(static function () use ($started, &$seen): void {
                    usleep(2_000);
                    $seen[] = $started();
                });
            });

            $first->start();
            $second->start();
            expect($pdo->inTransaction())->toBeTrue('the first fiber is still inside');
            $first->resume();

            expect($pdo->inTransaction())->toBeFalse()
                ->and($seen[1])->toBe('on')
                ->and(array_unique([$seen[0], $seen[2], $seen[3]]))->toHaveCount(1, 'one transaction for both fibers');

            usleep(2_000);
            expect($orm->readOnly($started))->not->toBe($seen[0], 'the next scope is a transaction of its own');
        });
    });

    // A failed statement aborts a Postgres transaction, and this one is the
    // whole render's: without recovering it, one query refused by the
    // database failed every query after it in the tick.
    it('fails only the statement that failed; the fibers after it read on', function () {
        onPostgres(function (PdoConnection $connection, \PDO $pdo): void {
            $orm = new Orm($connection, new IdentityMap('test'));
            $scheduler = $orm->beginRender();

            // Inside one scope for the tick, as the query runner runs one.
            $results = $orm->readOnly(static fn (): array => $scheduler->run([
                'write' => static fn (): mixed => $orm->readOnly(static fn (): int => $connection->execute("UPDATE racks SET name = 'renamed'")),
                'malformed' => static fn (): mixed => $orm->readOnly(static fn (): array => $connection->select('SELECT id FROM things WHERE id = ?', ['not-a-uuid'])),
                'read' => static fn (): mixed => $orm->readOnly(static fn (): int => \count($orm->all(Rack::class))),
            ]));
            $orm->endRender();

            expect($results['write'])->toBeInstanceOf(ReadOnlyViolation::class)
                ->and($results['malformed'])->toBeInstanceOf(\PDOException::class)
                ->and($results['read'])->toBe(2)
                ->and(rackNames($pdo))->toBe(['R-01', 'R-02']);
        });
    });

    // A raw statement through pdo() aborts the transaction with nothing there
    // to recover it; the next statement through the connection finds it
    // aborted, begins it again, and runs.
    it('recovers the transaction a raw write aborted, for the statement after it', function () {
        onPostgres(function (PdoConnection $connection, \PDO $pdo): void {
            $orm = new Orm($connection, new IdentityMap('test'));

            $count = $orm->readOnly(static function () use ($connection, $pdo): int {
                try {
                    $pdo->exec("UPDATE racks SET name = 'renamed'");
                } catch (\PDOException) {
                }

                return (int) $connection->select('SELECT COUNT(*) AS c FROM racks')[0]['c'];
            });

            expect($count)->toBe(2)
                ->and($connection->statementCount())->toBe(1, 'counted once, though it ran twice');
        });
    });

    // A render inside an action's transaction, or a test's: the scope is a
    // savepoint set read-only, and rolling back to it at the end drops the
    // setting and nothing the caller wrote.
    it('opens inside a transaction the caller holds, and leaves it writable', function () {
        onPostgres(function (PdoConnection $connection, \PDO $pdo): void {
            $orm = new Orm($connection, new IdentityMap('test'));

            $connection->transaction(static function () use ($orm, $connection): void {
                $connection->execute("UPDATE racks SET name = 'first' WHERE name = 'R-01'");

                $seen = $orm->readOnly(static function () use ($connection): array {
                    expect(fn () => $connection->execute("UPDATE racks SET name = 'refused'"))
                        ->toThrow(ReadOnlyViolation::class);

                    return array_column($connection->select('SELECT name FROM racks ORDER BY name COLLATE "C"'), 'name');
                });

                expect($seen)->toBe(['R-02', 'first'], 'the scope reads what the transaction wrote')
                    ->and($connection->select("SELECT current_setting('transaction_read_only') AS s")[0]['s'])->toBe('off');
                $connection->execute("UPDATE racks SET name = 'second' WHERE name = 'R-02'");
            });

            expect(rackNames($pdo))->toBe(['first', 'second']);
        });
    });

    // Recovery waits for a savepoint of transaction() opened inside the
    // scope, which recovers on its own way out. One the scope was opened
    // inside is no such savepoint: waiting for it, one malformed uuid failed
    // every statement after it until the scope ended.
    it('recovers a scope opened inside a nested transaction, for the statement after a failed one', function () {
        onPostgres(function (PdoConnection $connection): void {
            $orm = new Orm($connection, new IdentityMap('test'));

            $count = $connection->transaction(static fn (): int => $connection->transaction(
                static fn (): int => $orm->readOnly(static function () use ($connection): int {
                    try {
                        $connection->select('SELECT id FROM things WHERE id = ?', ['not-a-uuid']);
                    } catch (\PDOException) {
                    }

                    return (int) $connection->select('SELECT COUNT(*) AS c FROM racks')[0]['c'];
                }),
            ));

            expect($count)->toBe(2);
        });
    });

    it('ends a transaction the reset finds a fiber left open', function () {
        onPostgres(function (PdoConnection $connection, \PDO $pdo): void {
            $orm = new Orm($connection, new IdentityMap('test'));
            $abandoned = new \Fiber(static fn (): mixed => $orm->readOnly(static fn (): mixed => \Fiber::suspend()));
            $abandoned->start();

            expect($connection->rollBackOpenTransaction())->toBeTrue()
                ->and($pdo->inTransaction())->toBeFalse()
                ->and($connection->execute("UPDATE racks SET name = 'renamed'"))->toBe(2);

            $abandoned = null;
            expect($pdo->inTransaction())->toBeFalse('the unwound fiber did not reopen or close anything');
        });
    });

    // Level 2 batching on Postgres: the batched load runs from the main
    // context, between fibers, inside the transaction they share.
    it('batches a render\'s relation loads inside the transaction', function () {
        onPostgres(function (PdoConnection $connection, \PDO $pdo): void {
            $orm = new Orm($connection, new IdentityMap('test'));
            $racks = $orm->all(Rack::class, 'name');
            $connection->resetStatementCount();

            $scheduler = $orm->beginRender();
            $counts = $scheduler->run(array_map(
                static fn (Rack $rack): \Closure => static fn (): int => $orm->readOnly(static fn (): int => $rack->units->count()),
                $racks,
            ));
            $orm->endRender();

            expect($counts)->toBe([2, 2])
                ->and($scheduler->rounds())->toBe(1)
                ->and($connection->statementCount())->toBe(1);
        });
    });
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');
