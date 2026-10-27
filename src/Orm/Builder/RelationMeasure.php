<?php

declare(strict_types=1);

namespace Tinvar\Orm\Builder;

use Tinvar\Orm\Aggregate;
use Tinvar\Orm\RelationMeta;

/**
 * An aggregate over each row's related rows -- withCount(), withSum(),
 * orderByRelation() -- resolved when it was added: a #[HasMany] of the
 * model the query reads, the function, the related model's column (which
 * may itself be reached through the related model's #[BelongsTo]
 * relations), and the conditions the related rows are narrowed by.
 *
 * Compiled as a correlated subquery, with the related model's tenant
 * predicate inside it (Compiler::relationMeasure()).
 *
 * @internal for the query builder, inside this package
 */
final readonly class RelationMeasure
{
    /**
     * @param string $name what the value is answered under (Aggregated); never written to SQL
     * @param list<Condition> $conditions the related rows'
     */
    public function __construct(
        public string $name,
        public RelationMeta $relation,
        public Aggregate $function,
        public ?ColumnRef $column,
        public array $conditions,
    ) {
        if ($function->needsColumn() && $column === null) {
            throw new \InvalidArgumentException("{$function->name} reads a column; name one.");
        }
    }
}
