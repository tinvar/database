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
 * Or an aggregate over the rows' collections (ADR 0073): `[Aggregate::Sum,
 * 'items.priceCents']` in a report of shelves is the sum over every item
 * of every shelf in the group. $over is then the per-row aggregate -- a
 * correlated subquery, under the related model's tenant predicate -- that
 * the group's own aggregate combines: a sum or a count of the rows' sums
 * or counts, the least of their least, the most of their most, and an
 * average as the sum of their sums over the sum of their counts, never an
 * average of averages.
 *
 * @internal for the query builder, inside this package
 */
final readonly class Measure
{
    public function __construct(
        public Aggregate $function,
        public ?ColumnRef $column,
        public ?RelationMeasure $over = null,
    ) {
        if ($function->needsColumn() && $column === null && $over === null) {
            throw new \InvalidArgumentException("{$function->name} reads a column; name one.");
        }
    }
}
