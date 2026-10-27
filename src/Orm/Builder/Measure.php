<?php

declare(strict_types=1);

namespace Tinvar\Orm\Builder;

use Tinvar\Orm\Aggregate;

/**
 * An aggregate over the rows a query reads -- a report's column, a
 * HAVING, a sort by one -- resolved when it was added: the function one of
 * Aggregate's cases, the column a ColumnRef the metadata produced, or none
 * for a count of rows.
 *
 * @internal for the query builder, inside this package
 */
final readonly class Measure
{
    public function __construct(
        public Aggregate $function,
        public ?ColumnRef $column,
    ) {
        if ($function->needsColumn() && $column === null) {
            throw new \InvalidArgumentException("{$function->name} reads a column; name one.");
        }
    }
}
