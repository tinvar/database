<?php

declare(strict_types=1);

/**
 * A write that reaches its row says so on every database (ADR 0172).
 *
 * SQLite and Postgres count the rows an UPDATE matched; MySQL, through PDO
 * and amphp/mysql alike, counts the rows it changed. save() reads a count
 * of none as its row gone (MissingRow), and the query builder's update()
 * answers the count: on MySQL a save whose values the row already held --
 * the same change saved by two requests, a field set back to what another
 * request stored -- threw MissingRow, and update() undercounted.
 *
 * Each case runs on SQLite, on Postgres under its row-level security, on
 * MySQL, and through the async drivers; the servers' are skipped, saying
 * why, without one.
 */

namespace Tinvar\Tests\Orm\UpdateMatchedRows;

use Tinvar\Orm\Connection;
use Tinvar\Orm\MissingRow;
use Tinvar\Orm\PdoConnection;
use Tinvar\Tests\Orm\Fixtures\Brand;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Orm\Fixtures\Shelf;
use Tinvar\Tests\Orm\Fixtures\Shelves;
use Tinvar\Tests\Orm\Fixtures\UnscopedShelf;

require_once __DIR__ . '/Fixtures/shelves.php';

/**
 * Runs $test over the shelves fixture on $driver, skipped where its server
 * is not there.
 *
 * @param \Closure(Shelves, Connection): void $test
 */
function onDatabase(string $driver, \Closure $test): void
{
    $missing = match ($driver) {
        'sqlite' => null,
        'pgsql' => Databases::postgresMissing(),
        'pgsql-async' => Databases::asyncPostgresMissing(),
        default => Databases::mysqlMissing(),
    };
    if ($missing !== null) {
        test()->markTestSkipped($missing);
    }
    match ($driver) {
        'sqlite' => (static function () use ($test): void {
            $fixture = Shelves::sqlite();
            $test($fixture, $fixture->connection('sqlite'));
        })(),
        'pgsql' => Shelves::onPostgres($test),
        'pgsql-async' => Shelves::onPostgresAsync($test),
        'mysql' => Shelves::onMySql($test),
        'mysql-async' => Shelves::onMySqlAsync($test),
    };
}

dataset('databases', ['sqlite', 'pgsql', 'pgsql-async', 'mysql', 'mysql-async']);

/** A brand's name as stored, read past the ORM. */
function storedName(Connection $connection, string $id): ?string
{
    $name = $connection->select('SELECT name FROM brands WHERE id = ?', [$id])[0]['name'] ?? null;

    return \is_string($name) ? $name : null;
}

it('saves a change the row already holds, as two requests saving the same change do', function (string $driver) {
    onDatabase($driver, static function (Shelves $fixture, Connection $connection): void {
        $first = $fixture->orm(connection: $connection);
        $second = $fixture->orm(connection: $connection);
        $mine = $first->find(Brand::class, $fixture->ids['Zeta']) ?? throw new \LogicException('no brand');
        $theirs = $second->find(Brand::class, $fixture->ids['Zeta']) ?? throw new \LogicException('no brand');

        $mine->name = 'Omega';
        $first->save($mine);
        $theirs->name = 'Omega';
        $second->save($theirs);

        expect($theirs->isDirty())->toBeFalse()
            ->and(storedName($connection, $fixture->ids['Zeta']))->toBe('Omega');

        // And a tenant's row, the predicate on the write: its position
        // stored meanwhile through the same table read as another model,
        // so the shelf this Orm holds is not read again.
        $orm = $fixture->orm(connection: $connection);
        $orm->actAs('acme', static function () use ($orm): void {
            $shelf = $orm->query(Shelf::class)->where('name', 'Alpha')->first() ?? throw new \LogicException('no shelf');
            expect($orm->query(UnscopedShelf::class)->where('name', 'Alpha')->update(['position' => 5]))->toBe(1)
                ->and($shelf->position)->toBe(1);

            $shelf->position = 5;
            $orm->save($shelf);
            expect($shelf->isDirty())->toBeFalse();
        });
    });
})->with('databases');

it('refuses to save another tenant\'s row that holds the change already', function (string $driver) {
    onDatabase($driver, static function (Shelves $fixture, Connection $connection): void {
        $orm = $fixture->orm(connection: $connection);
        $orm->switchTenant('acme');
        // globex's shelf, read across tenants as it was.
        $delta = $orm->acrossTenants(static fn (): ?Shelf => $orm->query(Shelf::class)->where('name', 'Delta')->first()) ?? throw new \LogicException('no shelf');
        // globex moves it since.
        expect($orm->actAs('globex', static fn (): int => $orm->query(UnscopedShelf::class)->where('name', 'Delta')->update(['position' => 5])))->toBe(1);

        // acme's save of the same position is held to acme, and reaches no
        // row: refused, as for a row that is not there, never read as written.
        $delta->position = 5;
        expect(static fn () => $orm->save($delta))->toThrow(MissingRow::class)
            ->and($delta->isDirty())->toBeTrue();
    });
})->with('databases');

it('refuses to save over a row that is gone', function (string $driver) {
    onDatabase($driver, static function (Shelves $fixture, Connection $connection): void {
        $orm = $fixture->orm(connection: $connection);
        $brand = $orm->find(Brand::class, $fixture->ids['Xi']) ?? throw new \LogicException('no brand');
        $connection->execute('DELETE FROM brands WHERE id = ?', [$fixture->ids['Xi']]);
        $brand->name = 'Omicron';

        expect(static fn () => $orm->save($brand))->toThrow(MissingRow::class)
            ->and($brand->isDirty())->toBeTrue();
    });
})->with('databases');

// A model deleted and saved again inserts its row under the same key. Put
// back between a save's UPDATE, which found no row, and whatever MySQL
// then asks: the save is refused, as where the database counts the rows
// matched, or it writes its change to the row put back -- never reports a
// change written that the row does not hold.
it('never reports a save written to a row put back under its key while it was saved', function (string $driver) {
    onDatabase($driver, static function (Shelves $fixture, Connection $connection): void {
        \assert($connection instanceof PdoConnection);
        $id = $fixture->ids['Xi'];
        $mine = $fixture->orm(connection: $connection);
        $theirs = $fixture->orm(connection: $connection);
        $brand = $mine->find(Brand::class, $id) ?? throw new \LogicException('no brand');
        $gone = $theirs->find(Brand::class, $id) ?? throw new \LogicException('no brand');
        $theirs->delete($gone);

        $putBack = false;
        $connection->observeStatements(static function (string $sql) use ($connection, $theirs, $gone, &$putBack): void {
            if (str_starts_with($sql, 'UPDATE "brands"')) {
                $connection->observeStatements(null);
                $theirs->save($gone);
                $putBack = true;
            }
        });
        $brand->name = 'Omicron';
        try {
            $mine->save($brand);
            $saved = true;
        } catch (MissingRow) {
            $saved = false;
        } finally {
            $connection->observeStatements(null);
        }

        expect($putBack)->toBeTrue()
            ->and(storedName($connection, $id))->toBe($saved ? 'Omicron' : 'Xi')
            ->and($brand->isDirty())->toBe(!$saved);
    });
})->with(['sqlite', 'pgsql', 'mysql']);

it('answers the rows update() reached, those that held its values already among them', function (string $driver) {
    onDatabase($driver, static function (Shelves $fixture, Connection $connection): void {
        $orm = $fixture->orm(connection: $connection);

        // Zeta's rank is 1 already; Ypsilon's and Xi's are not.
        expect($orm->query(Brand::class)->update(['rank' => 1]))->toBe(3)
            ->and($orm->query(Brand::class)->update(['rank' => 1]))->toBe(3, 'every row holding it already')
            ->and($orm->query(Brand::class)->where('name', 'Zeta')->update(['rank' => 1]))->toBe(1)
            ->and($orm->query(Brand::class)->where('name', 'Nobody')->update(['rank' => 1]))->toBe(0);

        // A tenant's rows: acme's four shelves, Alpha's note "top" already.
        $orm->actAs('acme', static function () use ($orm): void {
            expect($orm->query(Shelf::class)->where('name', 'Alpha')->update(['note' => 'top']))->toBe(1)
                ->and($orm->query(Shelf::class)->update(['note' => 'top']))->toBe(4)
                ->and($orm->query(Shelf::class)->update(['note' => 'top']))->toBe(4);
        });
    });
})->with('databases');

// Counted as the rows stand, not as an open transaction saw them: at
// MySQL's REPEATABLE READ a plain read inside one sees the rows as they
// were at its first read, and would miss a row committed since that the
// UPDATE, which reads the rows as they stand, reached.
it('counts a row committed since a transaction of its own began among those update() reached, on MySQL', function () {
    Shelves::onMySql(static function (Shelves $fixture, PdoConnection $connection): void {
        $other = Databases::another('mysql');
        $other->exec('USE ' . $fixture->pdo->query('SELECT DATABASE()')?->fetchColumn());
        $orm = $fixture->orm(connection: $connection);

        $orm->transaction(static function () use ($orm, $other): void {
            // The transaction's first read, which every plain read after it sees as of.
            expect($orm->query(Brand::class)->count())->toBe(3);
            $other->prepare('INSERT INTO brands (id, name, `rank`) VALUES (?, ?, ?)')->execute(['psi', 'Psi', 1]);

            // Zeta's rank and Psi's are 1 already.
            expect($orm->query(Brand::class)->update(['rank' => 1]))->toBe(4);
        });
    });
})->group('mysql')->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');

// And save() asks as the row stands: a plain read inside a transaction
// already open would find a row deleted since its first read, and report a
// change written to a row that is gone.
it('refuses to save over a row deleted since a transaction of its own began, on MySQL', function () {
    Shelves::onMySql(static function (Shelves $fixture, PdoConnection $connection): void {
        $other = Databases::another('mysql');
        $other->exec('USE ' . $fixture->pdo->query('SELECT DATABASE()')?->fetchColumn());
        $orm = $fixture->orm(connection: $connection);
        $id = $fixture->ids['Xi'];

        $orm->transaction(static function () use ($orm, $other, $id): void {
            // The transaction's first read, which every plain read after it sees as of.
            $brand = $orm->find(Brand::class, $id) ?? throw new \LogicException('no brand');
            $other->prepare('DELETE FROM brands WHERE id = ?')->execute([$id]);

            $brand->name = 'Omicron';
            expect(static fn () => $orm->save($brand))->toThrow(MissingRow::class)
                ->and($brand->isDirty())->toBeTrue();
        });
    });
})->group('mysql')->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');
