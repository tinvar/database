<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * Seals and opens the cursors Query::cursorPaginate() hands a client.
 *
 * A cursor names the row a page starts after (or ends before) by its key,
 * and the query it was made for, as the builder's fingerprint of it. What
 * the cursor is bound to beyond that -- the session, the user and their
 * auth epoch, the tenant, the build -- is the implementation's: the ORM
 * knows none of them. SignedCursors signs with a binding string the caller
 * gives it; the framework's page cursors bind it as its route tokens are.
 *
 * A cursor that does not open -- forged, expired, minted for another
 * binding or another query -- opens to null, and the builder refuses it.
 */
interface Cursors
{
    /**
     * @param string $query  the query's fingerprint: its SQL and bindings, tenant predicate included
     * @param string $anchor the key of the row the page is read from
     * @param bool   $after  rows after the anchor in the query's order, or before it
     */
    public function seal(string $query, string $anchor, bool $after): string;

    /**
     * @return array{string, bool}|null the anchor and the direction, or null for a
     *         cursor that is not one this sealed for $query and its binding
     */
    public function open(string $query, string $cursor): ?array;
}
