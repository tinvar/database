<?php

declare(strict_types=1);

namespace Tinvar\Orm\Async;

/**
 * A fiber's hold on a session: what AsyncConnection keeps per fiber, and
 * the one thing that may send a statement to the session.
 *
 * A session goes back to the pool when its lease ends, and the pool may
 * then hand it to someone else, so everything that would send it a
 * statement asks the lease first (session()), and a lease that has ended
 * refuses. Without that, a transaction() still unwinding after the request
 * that began it had ended could COMMIT on a session another fiber now held.
 *
 * @internal AsyncConnection's, inside this package
 */
final class Lease
{
    private bool $ended = false;

    /** Whether a transaction was open on the session when the lease was ended from outside. */
    private bool $endedInTransaction = false;

    public function __construct(private readonly Session $session, private readonly Pool $pool) {}

    /** The session, while the lease holds it. */
    public function session(): Session
    {
        if ($this->ended) {
            throw new \LogicException($this->endedInTransaction
                ? 'The request this fiber\'s transaction ran in has ended, and the transaction was rolled back with it.'
                : 'This fiber\'s database session has been handed back.');
        }

        return $this->session;
    }

    public function ended(): bool
    {
        return $this->ended;
    }

    public function endedInTransaction(): bool
    {
        return $this->endedInTransaction;
    }

    /**
     * Hands the session back: cleared and kept by the pool, or closed if a
     * statement is in flight on it. Whatever transaction is open on it is
     * rolled back by the clearing. Nothing once ended.
     */
    public function end(): void
    {
        if ($this->ended) {
            return;
        }

        $this->ended = true;
        $this->endedInTransaction = $this->session->inTransaction;
        $this->pool->checkin($this->session);
    }

    /**
     * A lease whose fiber is gone -- finished and collected without handing
     * its session back -- hands it back now, so the pool does not shrink by
     * one for every such fiber.
     */
    public function __destruct()
    {
        // A destructor that throws, run by the collector or at shutdown,
        // fails whatever happened to be running.
        try {
            $this->end();
        } catch (\Throwable) {
        }
    }
}
