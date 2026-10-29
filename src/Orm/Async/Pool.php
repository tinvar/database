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

    public function __construct(
        private readonly Driver $driver,
        private readonly int $size,
        private readonly float $checkoutTimeout,
    ) {
        if ($size < 1) {
            throw new \InvalidArgumentException('A pool holds at least one session.');
        }
        if ($checkoutTimeout <= 0) {
            throw new \InvalidArgumentException('A checkout waits some time for a session to come free.');
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
            while (($session = array_pop($this->idle)) !== null) {
                if (!$session->link->isClosed()) {
                    return $session;
                }
                $this->forget($session);
            }

            if ($this->open < $this->size) {
                $this->open++;
                $link = null;
                try {
                    $link = $this->driver->connect();

                    return new Session($link, $this->driver);
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
                $this->idle[] = $session;
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
