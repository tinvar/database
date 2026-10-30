<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * A connection that tells an observer of each statement it sends, once it
 * has been answered: for the trace spans of the request or job sending it.
 *
 * The observer is handed the statement's text and when it ran, and whether
 * it threw -- never its bindings, so nothing it does can carry a bound
 * value anywhere. One observer at a time; null takes it away.
 */
interface ObservesStatements
{
    /** @param (\Closure(string, int, int, ?\Throwable): void)|null $observer the SQL, hrtime(true) at its start and end, and what it threw */
    public function observeStatements(?\Closure $observer): void;
}
