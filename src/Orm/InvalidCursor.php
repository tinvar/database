<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * A cursor Query::cursorPaginate() was handed and will not read from: one
 * that does not open -- forged, expired, sealed for another binding or
 * another query -- or whose row the reader can no longer see.
 *
 * Says which, never what the cursor held. A caller with a cursor from a
 * request answers it as a request that named no page it may read: the
 * first page, or a 400.
 */
final class InvalidCursor extends \RuntimeException
{
    public static function unopened(): self
    {
        return new self('The cursor is not one sealed for this query and this reader, or it has expired.');
    }

    public static function anchorGone(): self
    {
        return new self('The row the cursor reads from is no longer there to read.');
    }
}
