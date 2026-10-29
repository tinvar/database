<?php

declare(strict_types=1);

namespace Tinvar\Orm\Async;

use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Revolt\EventLoop;

use function Amp\async;

/**
 * A bounded pool of database sessions.
 *
 * Built here rather than taken from the clients' own pools, which hand a
 * connection back as it stands: nothing in them clears what a session was
 * told before another caller is given it. Here a session is cleared on its
 * way back (Driver::clear()), and one that cannot be -- the clearing fails,
 * a transaction on it will not roll back, a statement is still in flight --
 * is closed and never handed out again. Failing closed: the cost of a
 * session thrown away is one connect, and the cost of one handed on in
 * another's tenant is a leak.
 *
 * The pool lives as long as the worker; sessions outlive requests, and
 * nothing a request told one outlives its clearing.
 *
 * And it keeps a clock. A session lives at most `maxLifetime` seconds from
 * its connect, and waits idle at most `idleTimeout`: past either it is
 * closed rather than lent, and one past its lifetime on its way back is
 * closed rather than kept -- so a worker that runs for days does not hold
 * sessions a server or a proxy between has long since timed out, nor one
 * session for ever against a server whose settings or failover moved on.
 * Nothing runs between requests to close them -- FrankenPHP's worker runs
 * no event loop while it waits -- so the clock is read when a session is
 * asked for or handed back, every idle one past its time closed then.
 *
 * An idle session is asked `SELECT 1` before it is lent, within the
 * checkout timeout: one that does not answer -- the server restarted, a
 * proxy dropped it -- is closed, never lent, and the checkout goes on to
 * the next or a new one. Without it, the first statement of whichever
 * fiber was lent a dead session failed, and with it the fiber's work. A
 * session idle for less than `checkAfter` is lent unasked: 0, the
 * default, asks every time.
 *
 * @internal AsyncConnection's, inside this package
 */
final class Pool
{
    /** @var list<Session> cleared, waiting to be checked out */
    private array $idle = [];

    /** Sessions counted against the bound: idle, checked out, being opened and being cleared. */
    private int $open = 0;

    /** @var list<DeferredFuture<null>> checkouts waiting for a session to come free, first first */
    private array $waiting = [];

    /** Clearings queued and not yet done. */
    private int $clearing = 0;

    /** @var DeferredFuture<null>|null completed when the last clearing queued is done */
    private ?DeferredFuture $cleared = null;

    /**
     * @param float $idleTimeout seconds a session may wait idle before it is closed rather than lent
     * @param float $maxLifetime seconds from its connect after which a session is closed rather than lent or kept
     * @param float $checkAfter seconds idle after which a session is asked SELECT 1 before it is lent; 0 for always
     * @param (\Closure(): float)|null $clock seconds on a monotonic clock; hrtime() unless given
     */
    public function __construct(
        private readonly Driver $driver,
        private readonly int $size,
        private readonly float $checkoutTimeout,
        private readonly float $idleTimeout = 240.0,
        private readonly float $maxLifetime = 1800.0,
        private readonly float $checkAfter = 0.0,
        private readonly ?\Closure $clock = null,
    ) {
        if ($size < 1) {
            throw new \InvalidArgumentException('A pool holds at least one session.');
        }
        if ($checkoutTimeout <= 0) {
            throw new \InvalidArgumentException('A checkout waits some time for a session to come free.');
        }
        if ($idleTimeout <= 0 || $maxLifetime <= 0) {
            throw new \InvalidArgumentException('A session\'s idle timeout and lifetime are some time: a pool that keeps sessions for ever is the one this replaces.');
        }
        if ($checkAfter < 0) {
            throw new \InvalidArgumentException('A session is asked whether it answers after 0 seconds idle or more.');
        }
    }

    /**
     * A session, cleared: an idle one, a new one while the pool is below its
     * bound, or the next to come free, waited for up to the checkout
     * timeout. Suspends the calling fiber, or runs the event loop from
     * outside every fiber, while it waits.
     */
    public function checkout(): Session
    {
        $deadline = hrtime(true) + (int) ($this->checkoutTimeout * 1e9);

        while (true) {
            $this->expire();
            while (($session = array_pop($this->idle)) !== null) {
                if ($session->link->isClosed()) {
                    $this->forget($session);
                    continue;
                }
                if ($this->answers($session)) {
                    return $session;
                }
            }

            if ($this->open < $this->size) {
                $this->open++;
                $link = null;
                try {
                    $link = $this->driver->connect();

                    return new Session($link, $this->driver, $this->now());
                } catch (\Throwable $e) {
                    $link?->close();
                    $this->open--;
                    $this->wakeOne();

                    throw $this->driver->error($e);
                }
            }

            $left = ($deadline - hrtime(true)) / 1e9;
            $free = new DeferredFuture();
            $this->waiting[] = $free;

            // A timer of its own, referenced, rather than a
            // TimeoutCancellation, whose timer is not: with nothing else
            // keeping the loop alive -- a fiber holding the only session
            // while it waits for this one -- the loop would end, and the
            // wait with it, as a deadlock with no word of the pool.
            $timer = EventLoop::delay(max(0.0, $left), static function () use ($free): void {
                if (!$free->isComplete()) {
                    $free->error(new CancelledException());
                }
            });
            try {
                $free->getFuture()->await();
            } catch (CancelledException) {
                $this->waiting = array_values(array_filter($this->waiting, static fn (DeferredFuture $w): bool => $w !== $free));

                throw new QueryError('08004', null, \sprintf(
                    'No database session came free within %s s: all %d of the pool\'s are held. '
                    . 'A fiber holding one while it waits on another fiber that needs one waits for ever; raise the pool\'s size, or release() first.',
                    $this->checkoutTimeout,
                    $this->size,
                ));
            } finally {
                EventLoop::cancel($timer);
            }
        }
    }

    /**
     * Takes a session back. Never suspends: it is cleared in the event loop,
     * and only then idle; a waiting checkout is woken once it is. A session
     * with a statement in flight is closed at once instead.
     */
    public function checkin(Session $session): void
    {
        if ($session->busy || $session->closed) {
            $this->discard($session);

            return;
        }

        $this->clearing++;
        $this->cleared ??= new DeferredFuture();
        EventLoop::queue(function () use ($session): void {
            // Within the checkout timeout, or the session is closed: a server
            // that does not answer the clearing must not hold the request's
            // end, which waits for it (settle()).
            $clearing = async(fn () => $this->clear($session));
            try {
                $clearing->await(new TimeoutCancellation($this->checkoutTimeout));
                // Cleared, and past its lifetime: closed now rather than
                // kept for a checkout that would close it anyway.
                if ($this->now() - $session->openedAt >= $this->maxLifetime) {
                    $this->discard($session);
                } else {
                    $session->idleSince = $this->now();
                    $this->idle[] = $session;
                }
            } catch (\Throwable) {
                $clearing->ignore();
                $this->discard($session);
            } finally {
                if (--$this->clearing === 0) {
                    $cleared = $this->cleared;
                    $this->cleared = null;
                    $cleared?->complete();
                }
                $this->wakeOne();
            }
        });
    }

    /**
     * Closes a session for good; it is never handed out again.
     *
     * One with a statement in flight has the statement cancelled first
     * (Driver::cancel()), in the event loop, and is closed once that is
     * done: it no longer counts against the bound, nor will anyone be given
     * it, from now.
     */
    public function discard(Session $session): void
    {
        if ($session->closed) {
            return;
        }

        $this->forget($session);

        if (!$session->busy) {
            self::shut($session);

            return;
        }

        EventLoop::queue(function () use ($session): void {
            try {
                async(fn () => $this->driver->cancel($session->backend))->await(new TimeoutCancellation($this->checkoutTimeout));
            } catch (\Throwable) {
                // Closed below whatever came of it.
            } finally {
                self::shut($session);
            }
        });
    }

    private static function shut(Session $session): void
    {
        try {
            $session->link->close();
        } catch (\Throwable) {
            // Closed is what was wanted.
        }
    }

    /** Waits until every session handed back so far has been cleared or closed. */
    public function settle(): void
    {
        $this->cleared?->getFuture()->await();
    }

    /** Sessions open now, idle or not; for tests and the dev toolbar. */
    public function open(): int
    {
        return $this->open;
    }

    /** @return list<Session> the sessions idle now, cleared; for tests */
    public function idle(): array
    {
        return $this->idle;
    }

    /** Closes the idle sessions: for a worker stopping. */
    public function close(): void
    {
        foreach ($this->idle as $session) {
            $this->discard($session);
        }
        $this->idle = [];
    }

    /**
     * Closes every idle session past its idle timeout or its lifetime. The
     * idle list is oldest first, as sessions come back, but a lifetime runs
     * from the connect, so every one is looked at.
     */
    private function expire(): void
    {
        $now = $this->now();
        $kept = [];
        foreach ($this->idle as $session) {
            if ($now - $session->idleSince >= $this->idleTimeout || $now - $session->openedAt >= $this->maxLifetime) {
                $this->discard($session);
            } else {
                $kept[] = $session;
            }
        }
        $this->idle = $kept;
    }

    /**
     * Whether an idle session still answers, asked within the checkout
     * timeout. One that does not is closed, and is never lent: its backend
     * is gone, or no answer can be trusted to come.
     */
    private function answers(Session $session): bool
    {
        if ($this->now() - $session->idleSince < $this->checkAfter) {
            return true;
        }

        $session->busy = true;
        $asking = async(static fn () => $session->link->query('SELECT 1'));
        try {
            $asking->await(new TimeoutCancellation($this->checkoutTimeout));
            $session->busy = false;

            return true;
        } catch (\Throwable) {
            $asking->ignore();

            return false;
        } finally {
            // Unanswered, or answered with a failure: closed. One still
            // busy has its statement cancelled first, as any is.
            if ($session->busy) {
                $this->discard($session);
            }
        }
    }

    private function now(): float
    {
        return $this->clock === null ? hrtime(true) / 1e9 : ($this->clock)();
    }

    /**
     * Rolls back what a session holds open, and clears it. A failure leaves
     * it to the caller to close.
     */
    private function clear(Session $session): void
    {
        if ($session->inTransaction) {
            $session->link->query('ROLLBACK');
        }
        $session->endTransaction();

        $this->driver->clear($session->link);
        $session->tenant = $this->driver->tenantKey('', false, []);
        $session->readOnly = false;
    }

    private function forget(Session $session): void
    {
        $session->closed = true;
        $this->open--;
        $this->wakeOne();
    }

    private function wakeOne(): void
    {
        while (($next = array_shift($this->waiting)) !== null) {
            if (!$next->isComplete()) {
                $next->complete();

                return;
            }
        }
    }
}
