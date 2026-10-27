<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/*
 * The query builder's terminal step as a plain function, for the end of a
 * pipe (tinvar-spec.md, "Querying"):
 *
 *     $racks = Rack::query($orm) |> scope\atSite($site) |> fetch(...);
 *
 * In the PSR-4 directory, so the layer rule reads it as the database's, and
 * loaded by Composer by its path (`files`). Declared only once, should
 * anything ask the class loader for a class this path would name.
 */

if (!\function_exists(__NAMESPACE__ . '\\fetch')) {
    /**
     * @template T of Model
     * @param Query<T> $query
     * @return list<T>
     */
    function fetch(Query $query): array
    {
        return $query->fetch();
    }
}
