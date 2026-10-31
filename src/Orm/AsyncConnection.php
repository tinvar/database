<?php

declare(strict_types=1);

namespace Tinvar\Orm;

use Amp\CancelledException;
use Amp\Mysql\MysqlConfig;
use Amp\Postgres\PostgresConfig;
use Amp\Mysql\MysqlConnection;
use Amp\Postgres\PostgresConnection;
use Amp\Sql\SqlResult;
use Amp\TimeoutCancellation;
use Revolt\EventLoop;
use Tinvar\Orm\Async\Driver;
use Tinvar\Orm\Async\Lease;
use Tinvar\Orm\Async\MySqlDriver;
use Tinvar\Orm\Async\Pool;
use Tinvar\Orm\Async\PostgresDriver;
use Tinvar\Orm\Async\QueryError;
use Tinvar\Orm\Async\Session;

use function Amp\async;

/**
 * Fiber mode's driver: Postgres through amphp/postgres, MySQL through
 * amphp/mysql, behind the Connection the ORM already speaks
 * (ConcurrentConnection says what changes; ADR 0087).
 *
 * Opt-in. PdoConnection stays the default, and SQLite has no async driver:
 * a SQLite statement is a call into a library in this process, with no
 * socket to wait on, so there is nothing for another fiber to do meanwhile.
 *
 * Each fiber that sends a statement is lent a session from a bounded pool
 * (a Lease), which it keeps until it gives it back -- release(), the batch
 * scheduler on its behalf, or the end of the request. Before each
 * statement the session is brought into line with the sending fiber's
 * tenant and with whether a read-only scope is open, as PdoConnection
 * brings its one session; on its way back it is cleared, and closed if it
 * cannot be. A statement still in flight when its fiber is destroyed, or
 * past the statement timeout, takes its session with it: closed, never
 * handed back mid-answer.
 *
 * The end of the request is rollBackOpenTransaction() and
 * clearTenantSetting(), as for PdoConnection (RequestScope, Orm::flush()):
 * every session lent in the request is handed back -- rolled back,
 * cleared -- and the request waits for the clearing. The pool and its
 * sessions outlive the request; nothing the request told them does.
 */
final class AsyncConnection implements ConcurrentConnection, TenantSetting, ObservesStatements, GuardsStatements
{
    /** The most statements the log keeps between resets, as PdoConnection's. */
    public const int MAX_LOGGED = PdoConnection::MAX_LOGGED;

    /** Sessions a pool opens at most, unless told otherwise. */
    public const int POOL_SIZE = 8;

    /** How long a statement waits for a session to come free, in seconds. */
    public const float CHECKOUT_TIMEOUT = 10.0;

    /**
     * How long a session may wait idle before it is closed rather than lent,
     * in seconds: under the five minutes and more that servers, poolers and
     * load balancers between commonly drop an idle connection at.
     */
    public const float IDLE_TIMEOUT = 240.0;

    /** How long a session lives from its connect before it is closed rather than lent or kept, in seconds. */
    public const float MAX_LIFETIME = 1800.0;

    private int $statements = 0;

    /** @var list<string> */
    private array $log = [];

    private bool $logSql = true;

    /** A tell another backend answered (sameBackend()): every tenant is refused from then on. */
    private ?SessionMoved $sessionMoved = null;

    /** @var (\Closure(string, int, int, ?\Throwable): void)|null told of each statement once it is answered (ObservesStatements) */
    private ?\Closure $observer = null;

    /** @var (\Closure(string, bool): void)|null shown each statement before it is sent (GuardsStatements) */
    private ?\Closure $guard = null;

    private readonly Pool $pool;

    /**
     * The session each fiber holds. A lease ended from outside -- the
     * request over under it -- stays until the fiber is gone, so a fiber
     * whose transaction was rolled back under it finds so at its next
     * statement rather than carry on outside the transaction.
     *
     * @var \WeakMap<object, Lease>
     */
    private \WeakMap $leases;

    /** The session the main context holds, outside every fiber. */
    private ?Lease $mainLease = null;

    /** Moves on at every end of a request; a checkout that outlived its request gives its session back. */
    private int $generation = 0;

    /** Read-only scopes open now, across every fiber; see readOnly(). */
    private int $readOnlyScopes = 0;

    /** Which run of read-only scopes is open, as PdoConnection numbers them. */
    private int $readOnlyRun = 0;

    /** @var (\Closure(): array{string, bool, list<string>})|null */
    private ?\Closure $tenantScope = null;

    /** @param array{idleTimeout: float, maxLifetime: float, checkAfter: float, clock: (\Closure(): float)|null} $clock */
    private function __construct(
        private readonly Driver $driver,
        private readonly string $name,
        int $poolSize,
        float $checkoutTimeout,
        private readonly ?float $statementTimeout,
        array $clock,
    ) {
        if ($statementTimeout !== null && $statementTimeout <= 0) {
            throw new \InvalidArgumentException('A statement timeout is some time; leave it null for none.');
        }

        $this->pool = new Pool($driver, $poolSize, $checkoutTimeout, $clock['idleTimeout'], $clock['maxLifetime'], $clock['checkAfter'], $clock['clock']);
        $this->leases = new \WeakMap();
    }

    /**
     * Postgres, over ext-pgsql. The session's settings that are not the
     * request's -- a search path, the role -- go in the config's options
     * (`-c search_path=app`): every session starts with them, and DISCARD
     * ALL clears back to them.
     *
     * Connect as a role that is neither a superuser nor BYPASSRLS, as for
     * PdoConnection: row-level security holds no other.
     *
     * @param float|null $statementTimeout seconds a statement may run before its
     *        session is closed under it and it fails; null for none
     * @param float $idleTimeout seconds a pooled session may wait idle before it is closed rather than lent
     * @param float $maxLifetime seconds from its connect after which a session is closed rather than lent or kept
     * @param float $checkAfter seconds idle after which a session is asked SELECT 1 before it is lent; 0, every time
     * @param (\Closure(): float)|null $clock the pool's clock, in seconds; a monotonic one unless given (for tests)
     */
    public static function postgres(
        PostgresConfig $config,
        string $name = 'default',
        int $poolSize = self::POOL_SIZE,
        float $checkoutTimeout = self::CHECKOUT_TIMEOUT,
        ?float $statementTimeout = null,
        float $idleTimeout = self::IDLE_TIMEOUT,
        float $maxLifetime = self::MAX_LIFETIME,
        float $checkAfter = 0.0,
        ?\Closure $clock = null,
    ): self {
        return new self(new PostgresDriver($config), $name, $poolSize, $checkoutTimeout, $statementTimeout, [
            'idleTimeout' => $idleTimeout, 'maxLifetime' => $maxLifetime, 'checkAfter' => $checkAfter, 'clock' => $clock,
        ]);
    }

    /**
     * MySQL. The SQL mode a session starts with is the config's
     * (MysqlConfig::withSqlMode()); the ORM quotes identifiers as standard
     * SQL does, so it wants ANSI_QUOTES in it, as on PdoConnection. The
     * pool's clock is as postgres() takes it.
     *
     * @param (\Closure(): float)|null $clock
     */
    public static function mysql(
        MysqlConfig $config,
        string $name = 'default',
        int $poolSize = self::POOL_SIZE,
        float $checkoutTimeout = self::CHECKOUT_TIMEOUT,
        ?float $statementTimeout = null,
        float $idleTimeout = self::IDLE_TIMEOUT,
        float $maxLifetime = self::MAX_LIFETIME,
        float $checkAfter = 0.0,
        ?\Closure $clock = null,
    ): self {
        return new self(new MySqlDriver($config), $name, $poolSize, $checkoutTimeout, $statementTimeout, [
            'idleTimeout' => $idleTimeout, 'maxLifetime' => $maxLifetime, 'checkAfter' => $checkAfter, 'clock' => $clock,
        ]);
    }

    public function select(string $sql, array $bindings = []): array
    {
        if ($this->guard !== null) {
            ($this->guard)($sql, true);
        }
        $this->record($sql);

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->observed($sql, fn (): mixed => $this->run(fn (PostgresConnection|MysqlConnection $link): array => self::rows($this->driver->execute($link, $sql, $bindings))));

        return $rows;
    }

    public function execute(string $sql, array $bindings = []): int
    {
        if ($this->guard !== null) {
            ($this->guard)($sql, false);
        }
        $this->record($sql);

        /** @var int $affected */
        $affected = $this->observed($sql, fn (): mixed => $this->run(fn (PostgresConnection|MysqlConnection $link): int => self::affected($this->driver->execute($link, $sql, $bindings))));

        return $affected;
    }

    /**
     * Sends one statement of the application's on the calling fiber's
     * session, brought into line first; a write the database refuses in a
     * read-only scope becomes the ReadOnlyViolation the sync driver throws.
     *
     * @param \Closure(PostgresConnection|MysqlConnection): mixed $statement
     */
    private function run(\Closure $statement): mixed
    {
        $lease = $this->lease();
        $this->bringIntoLine($lease);

        // As the session was told, not as the count stands when the answer
        // comes: another fiber may close the last scope meanwhile.
        $readOnly = $this->readOnlyScopes > 0;

        try {
            return $this->send($lease, $statement);
        } catch (\PDOException $e) {
            throw $readOnly && $this->driver->refusedWrite($e) ? ReadOnlyViolation::refusedByDatabase($e) : $e;
        }
    }

    /**
     * Sends a statement on the lease's session and reads its answer whole.
     *
     * The session is marked busy for as long as the statement is in
     * flight. Left busy -- the fiber destroyed while it waited, which runs
     * the finally and no catch; the timeout; the session failing -- it is
     * closed, so it is never handed back with the rest of an answer
     * waiting on it, or in the middle of a transaction no one will end.
     *
     * @template T
     * @param \Closure(PostgresConnection|MysqlConnection): T $statement
     * @return T
     */
    private function send(Lease $lease, \Closure $statement): mixed
    {
        $session = $lease->session();
        if ($session->closed) {
            throw new QueryError('08003', null, 'The database session this was sent on has been closed.', sessionFailed: true);
        }
        $session->busy = true;

        try {
            $result = $this->statementTimeout === null
                ? $statement($session->link)
                : $this->withinTimeout($session->link, $statement, $this->statementTimeout);
            $session->busy = false;

            return $result;
        } catch (CancelledException) {
            throw new QueryError(
                $this->driver->name() === 'pgsql' ? '57014' : 'HY000',
                null,
                \sprintf('The statement ran past its %s s timeout; its session was closed under it.', $this->statementTimeout),
                sessionFailed: true,
            );
        } catch (\Throwable $e) {
            $error = $this->driver->error($e);
            if ($error instanceof QueryError && !$error->sessionFailed) {
                // The statement failed and the session answered: it is fine.
                $session->busy = false;

                // On Postgres the transaction it ran in is aborted now, as
                // PdoConnection notes it.
                if ($session->inTransaction && $this->driver->name() === 'pgsql') {
                    $session->aborted ??= [$error, $session->savepoints];
                }
            }

            throw $error;
        } finally {
            if ($session->busy) {
                $this->pool->discard($session);
            }
        }
    }

    /**
     * @template T
     * @param \Closure(PostgresConnection|MysqlConnection): T $statement
     * @return T
     */
    private function withinTimeout(PostgresConnection|MysqlConnection $link, \Closure $statement, float $timeout): mixed
    {
        $future = async($statement, $link);

        try {
            return $future->await(new TimeoutCancellation($timeout));
        } catch (CancelledException $e) {
            // Its session is closed by send(), which fails it in turn.
            $future->ignore();

            throw $e;
        }
    }

    /**
     * The calling fiber's lease, lent now if it has none.
     *
     * A lease whose session was closed under it is replaced -- unless a
     * transaction was open on it, which is gone with the session, and the
     * statements after it must not run outside it as though it were not.
     */
    private function lease(): Lease
    {
        $fiber = \Fiber::getCurrent();
        $lease = $fiber === null ? $this->mainLease : ($this->leases[$fiber] ?? null);

        if ($lease !== null) {
            if ($lease->ended()) {
                if ($lease->endedInTransaction()) {
                    $lease->session();
                }
            } elseif (!$lease->session()->closed) {
                return $lease;
            } elseif ($lease->session()->inTransaction) {
                throw new QueryError('08003', null, 'The database session this fiber\'s transaction ran on was closed; the transaction is gone with it.', sessionFailed: true);
            } else {
                $lease->end();
            }
        }

        $generation = $this->generation;
        $session = $this->pool->checkout();
        $lease = new Lease($session, $this->pool);

        // The request ended while this waited for a session: its fiber
        // belongs to a request that is over.
        if ($generation !== $this->generation) {
            $lease->end();

            throw new \LogicException('The request this statement was sent in has ended.');
        }

        if ($fiber === null) {
            $this->mainLease = $lease;
        } else {
            $this->leases[$fiber] = $lease;
        }

        return $lease;
    }

    /**
     * Brings the session into line with the calling fiber's tenant and with
     * whether a read-only scope is open: what PdoConnection does before each
     * statement, on each fiber's own session.
     *
     * Asked of the calling fiber before anything here suspends, so the
     * answer is that fiber's (TenantSetting).
     *
     * Outside a transaction, one round trip tells both, and only when either
     * differs from what the session was last told. Inside one, the tenant is
     * told as before, and a read-only scope opened since the transaction
     * began is held on Postgres by a savepoint set read-only, rolled back to
     * when the scope ends, as PdoConnection holds one; MySQL cannot change a
     * transaction's access mode once begun, and the ORM's check holds it.
     */
    /**
     * Refuses a tell another backend than the session's answered: a pooler
     * in transaction or statement mode between this pool and Postgres, which
     * may run the next statement on a session told another worker's tenant
     * (SessionMoved). Every tell after is refused too, on any session. The
     * driver set nothing on that other backend and cleared what it held, in
     * the tell itself (Driver::tell()).
     */
    private function sameBackend(Session $session, ?int $backend): void
    {
        if ($backend === null || $backend === $session->backend) {
            return;
        }

        $this->sessionMoved = SessionMoved::between($session->backend, $backend);

        throw $this->sessionMoved;
    }

    private function bringIntoLine(Lease $lease): void
    {
        [$tenant, $across, $subtree] = $this->tenantScope !== null ? ($this->tenantScope)() : ['', false, []];
        $readOnly = $this->readOnlyScopes > 0;
        $key = $this->driver->tenantKey($tenant, $across, $subtree);
        $session = $lease->session();
        if ($this->sessionMoved !== null) {
            throw $this->sessionMoved;
        }

        if (!$session->inTransaction) {
            if ($session->tenant !== $key || $session->readOnly !== $readOnly) {
                $session->tenant = null;
                $this->sameBackend($session, $this->send($lease, fn (PostgresConnection|MysqlConnection $link): ?int => $this->driver->tell($link, $session->backend, $tenant, $across, $subtree, $readOnly)));
                $session->tenant = $key;
                $session->readOnly = $readOnly;
            }

            return;
        }

        if ($session->tenant !== $key) {
            $session->tenant = null;
            $this->sameBackend($session, $this->send($lease, fn (PostgresConnection|MysqlConnection $link): ?int => $this->driver->tellTenant($link, $session->backend, $tenant, $across, $subtree)));
            $session->tenant = $key;
        }

        $savepoint = $this->driver->readOnlySavepoint();
        if ($savepoint === null || $session->transactionReadOnly) {
            return;
        }

        if ($readOnly && $session->readOnlySavepoint === null) {
            $this->send($lease, static fn (PostgresConnection|MysqlConnection $link): SqlResult => $link->query($savepoint[0]));
            $session->readOnlySavepoint = $session->savepoints;
        } elseif (!$readOnly) {
            $this->endReadOnlySavepoint($lease);
        }
    }

    /**
     * Rolls back to the read-only savepoint of a scope that has ended, as
     * PdoConnection does at the scope's end: the setting goes, and with it
     * a tenant told since and a failure inside it -- a write the scope
     * refused aborted the transaction, and this brings it back.
     */
    private function endReadOnlySavepoint(Lease $lease): void
    {
        $session = $lease->session();
        $depth = $session->readOnlySavepoint;
        $savepoint = $this->driver->readOnlySavepoint();
        if ($depth === null || $savepoint === null) {
            return;
        }

        $this->send($lease, static fn (PostgresConnection|MysqlConnection $link): SqlResult => $link->query($savepoint[1]));
        $session->readOnlySavepoint = null;
        $session->tenant = null;
        if ($session->aborted !== null && $session->aborted[1] >= $depth) {
            $session->aborted = null;
        }
    }

    public function transaction(\Closure $work): mixed
    {
        $lease = $this->lease();
        if ($lease->session()->inTransaction) {
            return $this->savepoint($lease, $work);
        }

        // Before the BEGIN: the tenant and the session's read-only default
        // as the fiber has them, told outside the transaction.
        $this->bringIntoLine($lease);
        $readOnly = $this->readOnlyScopes > 0;
        $this->send($lease, fn (PostgresConnection|MysqlConnection $link): SqlResult => $link->query($this->driver->begin($readOnly)));
        $session = $lease->session();
        $session->inTransaction = true;
        $session->transactionReadOnly = $readOnly;
        $session->aborted = null;

        try {
            $result = $work();
        } catch (\Throwable $e) {
            $this->rollBack($lease);
            $this->forgetEnded($lease);

            throw $e;
        }

        // Ended under it: the pool rolled it back, and its session may be
        // another fiber's now. Nothing here may COMMIT on it.
        if ($lease->ended()) {
            $this->forgetEnded($lease);

            throw new \LogicException('The request this fiber\'s transaction ran in has ended, and the transaction was rolled back with it.');
        }

        $session = $lease->session();

        // A read-only scope opened in here and ended without a statement
        // after it: its savepoint is rolled back to now, before the commit.
        if ($session->readOnlySavepoint !== null && $this->readOnlyScopes === 0) {
            try {
                $this->endReadOnlySavepoint($lease);
            } catch (\Throwable $e) {
                $this->rollBack($lease);

                throw $e;
            }
        }

        // As PdoConnection: a statement that failed in here aborted the
        // whole Postgres transaction, though $work caught it and returned,
        // and its COMMIT would roll back what came before it and be
        // reported as a commit. Rolled back and said so instead.
        if ($session->aborted !== null) {
            $failed = $session->aborted[0];
            $this->rollBack($lease);

            throw new \PDOException(
                'The transaction was rolled back, not committed: a statement in it failed, and Postgres aborts the transaction it fails in. '
                . 'Run a statement that may fail in a transaction() of its own, whose savepoint takes the failure back alone.',
                0,
                $failed,
            );
        }

        try {
            $this->send($lease, static fn (PostgresConnection|MysqlConnection $link): SqlResult => $link->query('COMMIT'));
        } catch (\Throwable $e) {
            // Refused commits roll back on Postgres, and a tenant told in
            // the transaction with them; MySQL's are rolled back here.
            $this->rollBack($lease);

            throw $e;
        }

        $session->endTransaction();

        return $result;
    }

    /**
     * Lets a fiber whose transaction was ended under it go on, now that
     * the transaction() has unwound and said so: its next statement is
     * lent a session of its own, outside any transaction, as it now is.
     */
    private function forgetEnded(Lease $lease): void
    {
        if (!$lease->ended()) {
            return;
        }

        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            if ($this->mainLease === $lease) {
                $this->mainLease = null;
            }
        } elseif (($this->leases[$fiber] ?? null) === $lease) {
            unset($this->leases[$fiber]);
        }
    }

    /**
     * Rolls back the lease's transaction. A rollback that fails takes the
     * session with it: closed, the database rolls the transaction back
     * itself. Nothing once the lease has ended: the session is the pool's,
     * which rolled it back, and may be another fiber's now.
     */
    private function rollBack(Lease $lease): void
    {
        if ($lease->ended()) {
            return;
        }

        $session = $lease->session();
        try {
            if (!$session->closed) {
                $this->send($lease, static fn (PostgresConnection|MysqlConnection $link): SqlResult => $link->query('ROLLBACK'));
            }
        } catch (\Throwable) {
            $this->pool->discard($session);
        } finally {
            $session->endTransaction();
            $session->tenant = null;
        }
    }

    /**
     * A nested transaction(), in a savepoint of the outer one, as on
     * PdoConnection.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function savepoint(Lease $lease, \Closure $work): mixed
    {
        $session = $lease->session();
        $number = $session->savepoints + 1;
        $name = "tinvar_{$number}";
        $this->send($lease, static fn (PostgresConnection|MysqlConnection $link): SqlResult => $link->query("SAVEPOINT {$name}"));
        $session->savepoints = $number;

        try {
            $result = $work();
        } catch (\Throwable $e) {
            if (!$lease->ended() && !$session->closed) {
                // ROLLBACK TO leaves the savepoint open, so it is released as
                // well; on Postgres it also brings the transaction back from
                // a statement that failed inside it.
                $session->tenant = null;
                try {
                    $this->send($lease, static fn (PostgresConnection|MysqlConnection $link): SqlResult => $link->query("ROLLBACK TO SAVEPOINT {$name}"));
                    $this->send($lease, static fn (PostgresConnection|MysqlConnection $link): SqlResult => $link->query("RELEASE SAVEPOINT {$name}"));
                } catch (\Throwable) {
                    // Left to the outer transaction's rollback.
                }
                if ($session->aborted !== null && $session->aborted[1] >= $number) {
                    $session->aborted = null;
                }
                // A read-only savepoint made inside this one is gone with it.
                if ($session->readOnlySavepoint !== null && $session->readOnlySavepoint >= $number) {
                    $session->readOnlySavepoint = null;
                }
                $session->savepoints = $number - 1;
            }

            throw $e;
        }

        $this->send($lease, static fn (PostgresConnection|MysqlConnection $link): SqlResult => $link->query("RELEASE SAVEPOINT {$name}"));
        $session->savepoints = $number - 1;

        // Released with a read-only savepoint made inside it: that
        // savepoint is gone, and its SET LOCAL stays for the rest of the
        // transaction, which refuses writes from here to its end.
        if ($session->readOnlySavepoint !== null && $session->readOnlySavepoint >= $number) {
            $session->readOnlySavepoint = null;
            $session->transactionReadOnly = true;
        }

        return $result;
    }

    /**
     * transaction(): Postgres and MySQL sync every commit by default, so
     * there is nothing more to do -- but, as on PdoConnection, it must be
     * the outermost.
     */
    public function durableTransaction(\Closure $work): mixed
    {
        if ($this->inTransaction()) {
            throw new \LogicException(
                'A durable transaction cannot run inside another: only the outermost commits, and it was begun without the sync.',
            );
        }

        return $this->transaction($work);
    }

    /** Whether the calling fiber has a transaction open. Another fiber's is not this one's. */
    public function inTransaction(): bool
    {
        $lease = $this->currentLease();

        return $lease !== null && !$lease->ended() && $lease->session()->inTransaction;
    }

    /**
     * A read-only scope, counted across fibers as Connection::readOnly()
     * says: while any is open, every fiber's statements run refusing
     * writes.
     *
     * The database is told at each statement, on the session sending it,
     * rather than when the scope opens: no session is shared, and there is
     * no handle around this class to send a write through before then.
     */
    public function readOnly(\Closure $work): mixed
    {
        $run = $this->readOnlyRun;
        $this->readOnlyScopes++;

        // A finally, not a catch: a fiber destroyed while suspended in here
        // is unwound, which runs finally blocks and no catch.
        try {
            return $work();
        } finally {
            // Ended already, by a reset; the run open now, if any, is another's.
            if ($run === $this->readOnlyRun && $this->readOnlyScopes > 0) {
                $this->readOnlyScopes--;
            }
        }
    }

    public function isReadOnly(): bool
    {
        return $this->readOnlyScopes > 0;
    }

    /**
     * The end of a request: every session lent in it handed back -- its
     * transaction, if any, rolled back by the clearing -- and read-only
     * scopes still open ended. Waits until the sessions are cleared, so
     * the next request finds the pool as the last one left it.
     */
    public function rollBackOpenTransaction(): bool
    {
        $this->readOnlyScopes = 0;
        $this->readOnlyRun++;

        return $this->endLeases();
    }

    public function release(): void
    {
        $lease = $this->currentLease();
        if ($lease === null || $lease->ended() || $lease->session()->inTransaction) {
            return;
        }

        $lease->end();
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            $this->mainLease = null;
        } else {
            unset($this->leases[$fiber]);
        }
    }

    public function waitUntil(\Closure $settled, \Closure $wake): void
    {
        $already = $settled();
        if ($already) {
            return;
        }

        $suspension = EventLoop::getSuspension();
        $wait = new \ArrayObject([true]);
        $wake(static function () use ($wait, $suspension, $settled): void {
            if ($wait[0] === true && $settled()) {
                $wait[0] = false;
                $suspension->resume();
            }
        });

        try {
            $suspension->suspend();
        } catch (\Error $e) {
            // The loop ran dry with a fiber still suspended: nothing will
            // ever resume it.
            if (str_starts_with($e->getMessage(), 'Event loop terminated without resuming')) {
                throw new \LogicException(
                    'Render stalled: a fiber suspended on something other than the database or the scheduler, and nothing will resume it.',
                    0,
                    $e,
                );
            }

            throw $e;
        } finally {
            $wait[0] = false;
        }
    }

    public function followTenant(\Closure $scope): void
    {
        $this->tenantScope = $scope;
    }

    public function guardStatements(?\Closure $guard): void
    {
        $this->guard = $guard;
    }

    /**
     * Ends every lease, as rollBackOpenTransaction() does: the clearing
     * sets each session back to no tenant.
     */
    public function clearTenantSetting(): void
    {
        $this->endLeases();
    }

    /** Hands back every session lent, and waits for the clearing. */
    private function endLeases(): bool
    {
        $leases = $this->mainLease !== null ? [$this->mainLease] : [];
        foreach ($this->leases as $lease) {
            $leases[] = $lease;
        }

        $open = false;
        foreach ($leases as $lease) {
            if (!$lease->ended()) {
                $open = $open || $lease->session()->inTransaction;
                $lease->end();
            }
        }

        $this->mainLease = null;
        $this->generation++;
        $this->pool->settle();

        return $open;
    }

    private function currentLease(): ?Lease
    {
        $fiber = \Fiber::getCurrent();

        return $fiber === null ? $this->mainLease : ($this->leases[$fiber] ?? null);
    }

    /** Sessions the pool holds open now, idle or lent: for tests and the dev toolbar. */
    public function sessionsOpen(): int
    {
        return $this->pool->open();
    }

    /** Closes the pool's idle sessions: for a worker stopping. */
    public function close(): void
    {
        $this->endLeases();
        $this->pool->close();
    }

    /**
     * @internal for tests: the pool's idle sessions, as cleared
     *
     * @return list<Session>
     */
    public function idleSessions(): array
    {
        return $this->pool->idle();
    }

    public function statementCount(): int
    {
        return $this->statements;
    }

    public function resetStatementCount(): void
    {
        $this->statements = 0;
        $this->log = [];
    }

    public function statementLog(): array
    {
        return $this->log;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function driver(): string
    {
        return $this->driver->name();
    }

    /** Turned off in benchmarks so log-keeping does not colour the numbers. */
    public function logSql(bool $on): void
    {
        $this->logSql = $on;
    }

    /**
     * @param SqlResult<mixed> $result
     * @return list<array<string, mixed>>
     */
    private static function rows(SqlResult $result): array
    {
        $rows = [];
        foreach ($result as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Rows a statement changed; one that answered rows is read to its end
     * first, as its session needs.
     *
     * @param SqlResult<mixed> $result
     */
    private static function affected(SqlResult $result): int
    {
        $count = $result->getRowCount();
        if ($count !== null) {
            return $count;
        }

        $count = 0;
        foreach ($result as $ignored) {
            $count++;
        }

        return $count;
    }

    /** @param (\Closure(string, int, int, ?\Throwable): void)|null $observer */
    public function observeStatements(?\Closure $observer): void
    {
        $this->observer = $observer;
    }

    /**
     * $statement, timed and told to the observer, when there is one: the
     * text alone, as the log keeps it, never what was bound to it.
     *
     * @template T
     * @param \Closure(): T $statement
     * @return T
     */
    private function observed(string $sql, \Closure $statement): mixed
    {
        $observer = $this->observer;
        if ($observer === null) {
            return $statement();
        }

        $started = hrtime(true);
        $failure = null;
        try {
            return $statement();
        } catch (\Throwable $e) {
            $failure = $e;

            throw $e;
        } finally {
            $observer($sql, $started, hrtime(true), $failure);
        }
    }

    private function record(string $sql): void
    {
        $this->statements++;
        if ($this->logSql && \count($this->log) < self::MAX_LOGGED) {
            $this->log[] = $sql;
        }
    }
}
