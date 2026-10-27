<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * Where a sort puts the rows whose column is NULL: before every other row,
 * or after them, whichever way the column sorts.
 *
 * Each database has its own answer when asked nothing -- Postgres sorts a
 * NULL as larger than every value, SQLite and MySQL as smaller -- so one
 * query listed its rows in another order on each. The query builder asks
 * for one, the same way on all three: an `(x IS NULL)` term ahead of the
 * column, which sorts false before true everywhere, rather than `NULLS
 * FIRST`, which MySQL has not got (ADR 0057).
 */
enum Nulls: string
{
    case First = 'first';
    case Last = 'last';
}
