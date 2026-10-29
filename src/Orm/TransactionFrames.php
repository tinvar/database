<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * What the Orm keeps per open transaction(): the journal a rollback
 * undoes, and what waits for the commit. One set for the connection's one
 * transaction on PdoConnection; one per fiber on a ConcurrentConnection,
 * where each fiber's transaction is its own, and one fiber's commit must
 * neither run another's afterCommit() callbacks nor fold in its undo.
 *
 * @internal the Orm's, inside this package
 */
final class TransactionFrames
{
    /**
     * What a rollback has to put back: one frame per open transaction(),
     * each a list of undo steps recorded before a write replaced what they
     * restore -- a model's snapshot, a loaded relation's items. A nested
     * frame that returns is folded into the one around it, since the outer
     * rollback undoes its writes too.
     *
     * @var list<list<\Closure(): void>>
     */
    public array $journal = [];

    /**
     * What waits for the outermost transaction to commit: one frame per open
     * transaction(), alongside the journal's. A nested frame that returns is
     * folded into the one around it, since only the outermost commit makes
     * its writes visible; one that rolls back is dropped with its writes.
     *
     * @var list<list<\Closure(): void>>
     */
    public array $afterCommit = [];
}
