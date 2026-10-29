<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * A connection whose statements from different fibers run at once: fiber
 * mode's driver (tinvar-spec.md, "Engine layers": "PDO for sync contexts,
 * amphp Postgres/MySQL for fiber mode"; ADR 0087).
 *
 * Behind it is a bounded pool of database sessions, not one. A fiber is
 * given a session of its own at its first statement and keeps it for its
 * turn -- until it hands control to the batch scheduler, its task ends, it
 * calls release(), or the request does -- so two fibers' statements never
 * share a session, and a fiber waiting on the database waits alone while the
 * rest run.
 *
 * What changes from the sync driver, PdoConnection, which serves every fiber
 * from one session:
 *
 * - A transaction belongs to the fiber that began it. Its statements, and
 *   every statement that fiber sends until it ends, go to its session; no
 *   other fiber's statement runs inside it, nor sees what it has not
 *   committed. inTransaction() answers for the calling fiber.
 * - The session's tenant -- what row-level security reads -- is set on each
 *   session as it is checked out, brought into line before each statement
 *   as PdoConnection does, and cleared when the session is handed back; a
 *   session that cannot be cleared is closed, never handed out again.
 * - Waiting is done in the event loop (Revolt). A statement sent from
 *   outside every fiber runs the loop until it is answered, so the
 *   connection works anywhere a PdoConnection does; one sent from a fiber
 *   suspends that fiber, and something has to run the loop -- the batch
 *   scheduler does, while it waits for a render's fibers.
 */
interface ConcurrentConnection extends Connection, NamesDriver
{
    /**
     * Hands the calling fiber's session back to the pool, cleared, unless a
     * transaction is open on it. Nothing when it holds none.
     *
     * Never suspends: the session is cleared in the event loop, and is not
     * handed to anyone else until it has been. For a fiber about to wait on
     * something other than the database -- the batch scheduler calls it
     * before suspending one -- so the pool is not held by fibers doing
     * nothing with it.
     */
    public function release(): void;

    /**
     * Runs the event loop until $settled answers true, from outside every
     * fiber; what the batch scheduler waits in while a render's fibers
     * wait on the database. $wake is what a fiber calls when it may have
     * settled things, for $settled to be asked again.
     *
     * @param \Closure(): bool $settled
     * @param \Closure(\Closure(): void): void $wake given the closure to call
     */
    public function waitUntil(\Closure $settled, \Closure $wake): void;
}
