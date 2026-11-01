<?php

declare(strict_types=1);

namespace Tinvar\Tests\Orm\SqliteConnection;

use Tinvar\Orm\PdoConnection;

/**
 * PdoConnection::sqlite(): a database file opened the way several workers
 * sharing it need -- WAL, a busy timeout, enforced foreign keys -- and never
 * a fresh empty database made by a mistyped path.
 */
function scratchDatabase(): string
{
    $directory = sys_get_temp_dir() . '/tinvar-sqlite-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700);

    return "{$directory}/test.sqlite";
}

function removeDatabase(string $path): void
{
    foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
        if (is_file($path . $suffix)) {
            unlink($path . $suffix);
        }
    }
    if (is_dir(\dirname($path))) {
        rmdir(\dirname($path));
    }
}

it('opens a file in WAL mode, with a busy timeout and foreign keys on', function () {
    $path = scratchDatabase();

    try {
        $connection = PdoConnection::sqlite($path, 'shop', create: true);

        expect($connection->select('PRAGMA journal_mode')[0]['journal_mode'])->toBe('wal')
            ->and($connection->select('PRAGMA busy_timeout')[0]['timeout'])->toBe(PdoConnection::SQLITE_BUSY_TIMEOUT_MS)
            ->and($connection->select('PRAGMA foreign_keys')[0]['foreign_keys'])->toBe(1)
            ->and($connection->name())->toBe('shop');
    } finally {
        removeDatabase($path);
    }
});

// SQLite's default in WAL mode syncs the log on every commit, inside the
// write lock, so every queued writer waited for the disk too (ADR 0050).
it('syncs at checkpoints rather than at every commit, unless told otherwise', function () {
    $path = scratchDatabase();

    try {
        // 1 is NORMAL, 2 FULL, 3 EXTRA.
        expect(PdoConnection::sqlite($path, create: true)->select('PRAGMA synchronous')[0]['synchronous'])->toBe(1)
            ->and(PdoConnection::sqlite($path, synchronous: 'full')->select('PRAGMA synchronous')[0]['synchronous'])->toBe(2)
            ->and(PdoConnection::sqlite($path, synchronous: 'EXTRA')->select('PRAGMA synchronous')[0]['synchronous'])->toBe(3);
    } finally {
        removeDatabase($path);
    }
});

// A commit a security decision rests on -- a session ended, an epoch moved --
// is synced in full however the rest are, so a power cut cannot take it back.
it('syncs a durable transaction\'s commit in full, and goes back to NORMAL after', function () {
    $connection = PdoConnection::sqlite(':memory:');
    $connection->execute('CREATE TABLE hits (n INTEGER)');

    $during = $connection->durableTransaction(static function () use ($connection): int {
        $connection->execute('INSERT INTO hits (n) VALUES (1)');

        return $connection->select('PRAGMA synchronous')[0]['synchronous'];
    });

    expect($during)->toBe(2)
        ->and($connection->select('PRAGMA synchronous')[0]['synchronous'])->toBe(1)
        ->and($connection->select('SELECT n FROM hits'))->toBe([['n' => 1]]);
});

it('goes back to NORMAL after a durable transaction that fails, having written nothing', function () {
    $connection = PdoConnection::sqlite(':memory:');
    $connection->execute('CREATE TABLE hits (n INTEGER)');

    expect(fn () => $connection->durableTransaction(static function () use ($connection): never {
        $connection->execute('INSERT INTO hits (n) VALUES (1)');

        throw new \RuntimeException('refused');
    }))->toThrow(\RuntimeException::class, 'refused')
        ->and($connection->select('PRAGMA synchronous')[0]['synchronous'])->toBe(1)
        ->and($connection->select('SELECT COUNT(*) AS n FROM hits')[0]['n'])->toBe(0);
});

it('leaves a connection already syncing in full as it is', function () {
    $connection = PdoConnection::sqlite(':memory:', synchronous: 'EXTRA');

    $during = $connection->durableTransaction(static fn (): int => $connection->select('PRAGMA synchronous')[0]['synchronous']);

    expect($during)->toBe(3)
        ->and($connection->select('PRAGMA synchronous')[0]['synchronous'])->toBe(3);
});

// SQLite will not change how it syncs inside a transaction, and only the
// outermost commits: run inside another, the durable one would not be.
it('refuses a durable transaction inside another, rather than run it undurably', function () {
    $connection = PdoConnection::sqlite(':memory:');

    expect(fn () => $connection->transaction(static fn (): mixed => $connection->durableTransaction(static fn (): int => 1)))
        ->toThrow(\LogicException::class, 'cannot run inside another')
        ->and($connection->inTransaction())->toBeFalse()
        ->and($connection->select('PRAGMA synchronous')[0]['synchronous'])->toBe(1);
});

// OFF hands the file's consistency to the operating system, which can leave
// it corrupt after a power cut; and an unknown word would be a setting that
// looks configured and is not.
it('refuses to sync OFF, or by a word SQLite does not know', function (string $level) {
    expect(fn () => PdoConnection::sqlite(':memory:', synchronous: $level))
        ->toThrow(\InvalidArgumentException::class, 'NORMAL, FULL or EXTRA');
})->with(['OFF', '0', 'normal; PRAGMA foreign_keys = OFF', '']);

// Off by default in SQLite: a REFERENCES clause was decoration, and an order
// line could name a product that never existed.
it('enforces foreign keys', function () {
    $connection = PdoConnection::sqlite(':memory:');
    $connection->execute('CREATE TABLE parents (id TEXT PRIMARY KEY)');
    $connection->execute('CREATE TABLE children (id TEXT PRIMARY KEY, parent_id TEXT NOT NULL REFERENCES parents (id))');

    expect(fn () => $connection->execute("INSERT INTO children (id, parent_id) VALUES ('c', 'missing')"))
        ->toThrow(\PDOException::class, 'FOREIGN KEY');
});

// A mistyped path used to create an empty database there, and the failure
// surfaced as "no such table" somewhere else entirely.
it('refuses a file that does not exist unless asked to create it', function () {
    $path = scratchDatabase();

    try {
        expect(fn () => PdoConnection::sqlite($path))->toThrow(\PDOException::class)
            ->and(is_file($path))->toBeFalse();

        PdoConnection::sqlite($path, create: true);

        expect(is_file($path))->toBeTrue();
    } finally {
        removeDatabase($path);
    }
});

it('refuses an empty path', function () {
    expect(fn () => PdoConnection::sqlite(''))->toThrow(\InvalidArgumentException::class, 'needs a database path');
});

// The timeout is what lets ten workers share one file: a second writer waits
// for the first instead of failing the request on the spot.
it('waits for a held write lock rather than failing at once', function () {
    $path = scratchDatabase();

    try {
        $holder = PdoConnection::sqlite($path, create: true);
        $holder->execute('CREATE TABLE hits (n INTEGER)');
        $holder->pdo()->exec('BEGIN IMMEDIATE');

        $waiter = PdoConnection::sqlite($path, busyTimeoutMs: 300);
        $started = microtime(true);
        $failed = false;

        try {
            $waiter->execute('INSERT INTO hits (n) VALUES (1)');
        } catch (\PDOException) {
            $failed = true;
        }
        $waited = microtime(true) - $started;
        $holder->pdo()->exec('ROLLBACK');

        expect($failed)->toBeTrue('the lock was never released, so the write had to give up')
            ->and($waited)->toBeGreaterThan(0.25);
    } finally {
        removeDatabase($path);
    }
});

/**
 * Another process holding $path's write lock for $milliseconds, once it has
 * it: the process and its output pipe.
 *
 * @return array{resource, resource}
 */
function holdWriteLock(string $path, int $milliseconds): array
{
    $holder = proc_open([
        PHP_BINARY, '-r',
        '$pdo = new PDO("sqlite:" . $argv[1]); $pdo->exec("BEGIN IMMEDIATE"); echo "held\n"; '
        . 'usleep((int) $argv[2] * 1000); $pdo->exec("COMMIT");',
        '--', $path, (string) $milliseconds,
    ], [1 => ['pipe', 'w']], $pipes);
    if ($holder === false || trim((string) fgets($pipes[1])) !== 'held') {
        throw new \RuntimeException('The process holding the write lock did not start.');
    }

    return [$holder, $pipes[1]];
}

/**
 * How many times $connection's PDO began a transaction while $work ran:
 * each try at BEGIN IMMEDIATE is a statement SQLite prepares, and its
 * authorizer hears every one.
 */
function beginsDuring(PdoConnection $connection, \Closure $work): int
{
    $pdo = $connection->pdo();
    \assert($pdo instanceof \Pdo\Sqlite);
    $begins = 0;
    $pdo->setAuthorizer(static function (int $action, ?string $what) use (&$begins): int {
        if ($action === 22 && $what === 'BEGIN') {
            $begins++;
        }

        return \Pdo\Sqlite::OK;
    });

    try {
        $work();
    } finally {
        $pdo->setAuthorizer(null);
    }

    return $begins;
}

// SQLite's busy handler sleeps up to 100 ms between tries and never wakes
// when the lock comes free, so queued writers left it idle (ADR 0050). A
// transaction waits in steps of its own, a few milliseconds at most.
it('waits for the write lock in short steps of its own, and begins once it is let go', function () {
    $path = scratchDatabase();

    try {
        $connection = PdoConnection::sqlite($path, create: true);
        $connection->execute('CREATE TABLE hits (n INTEGER)');
        [$holder] = holdWriteLock($path, 300);

        $started = microtime(true);
        $begins = beginsDuring($connection, static function () use ($connection): void {
            $connection->transaction(static fn (): int => $connection->execute('INSERT INTO hits (n) VALUES (1)'));
        });
        $waited = microtime(true) - $started;
        proc_close($holder);

        // Each try a statement; SQLite's handler would have prepared it once
        // and slept in it. Fewer than 20 tries in 300 ms would be waits far
        // past 4 ms; more than 400, a loop that does not back off.
        expect($connection->select('SELECT n FROM hits'))->toBe([['n' => 1]])
            ->and($waited)->toBeGreaterThan(0.2)
            ->and($begins)->toBeGreaterThan(20)
            ->and($begins)->toBeLessThan(400)
            ->and($connection->select('PRAGMA busy_timeout')[0]['timeout'])->toBe(PdoConnection::SQLITE_BUSY_TIMEOUT_MS);
    } finally {
        removeDatabase($path);
    }
});

it('waits the same way for a write made outside a transaction', function () {
    $path = scratchDatabase();

    try {
        $connection = PdoConnection::sqlite($path, create: true);
        $connection->execute('CREATE TABLE hits (n INTEGER)');
        $connection->resetStatementCount();
        [$holder] = holdWriteLock($path, 200);

        $started = microtime(true);
        $written = $connection->execute('INSERT INTO hits (n) VALUES (1)');
        $waited = microtime(true) - $started;
        proc_close($holder);

        // However many tries it took, it is one statement to a query budget.
        expect($written)->toBe(1)
            ->and($waited)->toBeGreaterThan(0.1)
            ->and($connection->statementCount())->toBe(1)
            ->and($connection->select('PRAGMA busy_timeout')[0]['timeout'])->toBe(PdoConnection::SQLITE_BUSY_TIMEOUT_MS);
    } finally {
        removeDatabase($path);
    }
});

it('gives up on the write lock once the busy timeout has passed, and keeps the timeout for everything else', function () {
    $path = scratchDatabase();

    try {
        $holder = PdoConnection::sqlite($path, create: true);
        $holder->execute('CREATE TABLE hits (n INTEGER)');
        $holder->pdo()->exec('BEGIN IMMEDIATE');

        $waiter = PdoConnection::sqlite($path, busyTimeoutMs: 300);
        $started = microtime(true);
        expect(fn () => $waiter->transaction(static fn (): int => $waiter->execute('INSERT INTO hits (n) VALUES (1)')))
            ->toThrow(\PDOException::class, 'database is locked');
        $waited = microtime(true) - $started;
        $holder->pdo()->exec('ROLLBACK');

        expect($waited)->toBeGreaterThan(0.25)
            ->and($waited)->toBeLessThan(2.0)
            ->and($waiter->inTransaction())->toBeFalse()
            ->and($waiter->select('PRAGMA busy_timeout')[0]['timeout'])->toBe(300);
    } finally {
        removeDatabase($path);
    }
});

// Only SQLITE_BUSY is a turn to wait for: a write refused for what it is
// fails once, at once. Retried, it would go on for the busy timeout's five
// seconds; the bound is far under that and far over a loaded run's pause.
it('does not try a failed write again for anything but a held lock', function () {
    $connection = PdoConnection::sqlite(':memory:');
    $connection->execute('CREATE TABLE hits (n INTEGER PRIMARY KEY)');
    $connection->execute('INSERT INTO hits (n) VALUES (1)');

    $started = microtime(true);
    expect(fn () => $connection->execute('INSERT INTO hits (n) VALUES (1)'))->toThrow(\PDOException::class, 'UNIQUE')
        ->and(microtime(true) - $started)->toBeLessThan(1.0);
});

// A connection the caller opened itself is left to whatever busy handler it
// set; only sqlite() knows the timeout it was opened with.
it('leaves a connection it did not open to SQLite\'s own busy handler', function () {
    $path = scratchDatabase();

    try {
        PdoConnection::sqlite($path, create: true)->execute('CREATE TABLE hits (n INTEGER)');
        $pdo = new \Pdo\Sqlite("sqlite:{$path}");
        $pdo->exec('PRAGMA busy_timeout = 2000');
        $connection = new PdoConnection($pdo);
        [$holder] = holdWriteLock($path, 200);

        $begins = beginsDuring($connection, static function () use ($connection): void {
            $connection->transaction(static fn (): int => $connection->execute('INSERT INTO hits (n) VALUES (1)'));
        });
        proc_close($holder);

        expect($begins)->toBe(1)
            ->and($connection->select('SELECT COUNT(*) AS n FROM hits')[0]['n'])->toBe(1);
    } finally {
        removeDatabase($path);
    }
});

it('reads while another connection holds an open write', function () {
    $path = scratchDatabase();

    try {
        $writer = PdoConnection::sqlite($path, create: true);
        $writer->execute('CREATE TABLE hits (n INTEGER)');
        $writer->execute('INSERT INTO hits (n) VALUES (1)');

        $writer->pdo()->exec('BEGIN IMMEDIATE');
        $writer->execute('INSERT INTO hits (n) VALUES (2)');

        // The reader sees the last commit, not the write in progress, and
        // does not wait for it.
        $reader = PdoConnection::sqlite($path, busyTimeoutMs: 0);

        expect($reader->select('SELECT COUNT(*) AS n FROM hits')[0]['n'])->toBe(1);

        $writer->pdo()->exec('COMMIT');

        expect($reader->select('SELECT COUNT(*) AS n FROM hits')[0]['n'])->toBe(2);
    } finally {
        removeDatabase($path);
    }
});

/**
 * Overtakes $connection's read-then-write transaction with a commit from
 * $other between the read and the write.
 *
 * @return array{bool, \Throwable|null} whether the other writer was refused,
 *         and what the transaction threw
 */
function overtake(PdoConnection $connection, PdoConnection $other): array
{
    $otherRefused = false;

    try {
        $connection->transaction(function () use ($connection, $other, &$otherRefused): void {
            $next = (int) $connection->select('SELECT COUNT(*) AS n FROM hits')[0]['n'] + 1;

            try {
                $other->execute('INSERT INTO hits (n) VALUES (100)');
            } catch (\PDOException) {
                $otherRefused = true;
            }

            $connection->execute('INSERT INTO hits (n) VALUES (?)', [$next]);
        });
    } catch (\Throwable $e) {
        return [$otherRefused, $e];
    }

    return [$otherRefused, null];
}

// Begun deferred, a transaction that reads and then writes took its snapshot
// at the read; a commit from another worker in between made its write fail
// with SQLITE_BUSY_SNAPSHOT, which no busy timeout waits out -- a 500 from
// placing an order under load. Begun IMMEDIATE, it holds the write lock from
// the start, and the other writer queues behind it instead.
it('begins a transaction holding the write lock, so a read-then-write is never overtaken', function () {
    $path = scratchDatabase();

    try {
        $connection = PdoConnection::sqlite($path, create: true);
        $connection->execute('CREATE TABLE hits (n INTEGER)');

        [$otherRefused, $thrown] = overtake($connection, PdoConnection::sqlite($path, busyTimeoutMs: 0));

        expect($thrown)->toBeNull()
            ->and($otherRefused)->toBeTrue('the other writer had to wait its turn')
            ->and($connection->select('SELECT n FROM hits'))->toBe([['n' => 1]]);
    } finally {
        removeDatabase($path);
    }
});

it('begins deferred when asked to', function () {
    $path = scratchDatabase();

    try {
        $connection = PdoConnection::sqlite($path, create: true, immediateTransactions: false);
        $connection->execute('CREATE TABLE hits (n INTEGER)');

        [$otherRefused, $thrown] = overtake($connection, PdoConnection::sqlite($path, busyTimeoutMs: 0));

        expect($otherRefused)->toBeFalse()
            ->and($thrown)->toBeInstanceOf(\PDOException::class);
    } finally {
        removeDatabase($path);
    }
});

// A long render, or a caller that never resets, must not grow the log
// without bound; the count, which budgets read, goes on.
it('keeps at most a bounded log of statements, and counts them all', function () {
    $connection = PdoConnection::sqlite(':memory:');

    for ($i = 0; $i < PdoConnection::MAX_LOGGED + 10; $i++) {
        $connection->select('SELECT ' . $i);
    }

    expect($connection->statementLog())->toHaveCount(PdoConnection::MAX_LOGGED)
        ->and($connection->statementLog()[0])->toBe('SELECT 0')
        ->and($connection->statementCount())->toBe(PdoConnection::MAX_LOGGED + 10);
});

// Switching to WAL takes the exclusive lock, and SQLite refuses a switch
// that finds the write lock held at once, without the busy handler: two
// processes creating one new database together had one fail before either
// reached the migration lock. Another process holds the write lock of a
// file not yet in WAL mode here, as the first of two would.
it('waits its turn to put a file in WAL mode while another process holds it', function () {
    $path = scratchDatabase();

    try {
        $holder = proc_open([
            PHP_BINARY, '-r',
            '$pdo = new PDO("sqlite:" . $argv[1]); $pdo->exec("CREATE TABLE hits (n INTEGER)"); '
            . '$pdo->exec("BEGIN IMMEDIATE"); echo "held\n"; usleep(300000); $pdo->exec("COMMIT");',
            '--', $path,
        ], [1 => ['pipe', 'w']], $pipes);
        expect($holder)->not->toBeFalse()
            ->and(trim((string) fgets($pipes[1])))->toBe('held');

        $started = microtime(true);
        $connection = PdoConnection::sqlite($path);
        $waited = microtime(true) - $started;
        proc_close($holder);

        expect($connection->select('PRAGMA journal_mode')[0]['journal_mode'])->toBe('wal')
            ->and($waited)->toBeGreaterThan(0.1);
    } finally {
        removeDatabase($path);
    }
});

it('gives up on the switch once the busy timeout has passed', function () {
    $path = scratchDatabase();

    try {
        $holder = new \PDO("sqlite:{$path}");
        $holder->exec('CREATE TABLE hits (n INTEGER)');
        $holder->exec('BEGIN IMMEDIATE');

        $started = microtime(true);
        expect(fn () => PdoConnection::sqlite($path, busyTimeoutMs: 200))->toThrow(\PDOException::class, 'database is locked')
            ->and(microtime(true) - $started)->toBeGreaterThan(0.15);
        $holder->exec('ROLLBACK');
    } finally {
        removeDatabase($path);
    }
});

// Bound as text, an integer compared with an expression -- which has no
// column affinity to convert it -- sorted after every number, and the
// condition held for no row.
it('binds an integer as an integer', function () {
    $connection = new PdoConnection(new \PDO('sqlite::memory:'));
    $connection->execute('CREATE TABLE shelves (position INTEGER NOT NULL)');
    foreach ([1, 2, 3] as $position) {
        $connection->execute('INSERT INTO shelves VALUES (?)', [$position]);
    }

    expect($connection->select('SELECT position FROM shelves WHERE position * ? > ? ORDER BY position LIMIT ?', [10, 15, 5]))
        ->toBe([['position' => 2], ['position' => 3]])
        ->and($connection->select('SELECT ? AS a, ? AS b, ? AS c', [null, 'x', 1.5]))->toBe([['a' => null, 'b' => 'x', 'c' => '1.5']]);
});
