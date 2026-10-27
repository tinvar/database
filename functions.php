<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * The query builder's terminal step as a plain function, for the end of a
 * pipe (tinvar-spec.md, "Querying"):
 *
 *     $racks = Rack::query($orm) |> scope\atSite($site) |> fetch(...);
 *
 * Beside src/ rather than in it, as aliases.php is: Composer loads a file of
 * functions by its path, and a PSR-4 directory holds classes.
 *
 * @template T of Model
 * @param Query<T> $query
 * @return list<T>
 */
function fetch(Query $query): array
{
    return $query->fetch();
}
