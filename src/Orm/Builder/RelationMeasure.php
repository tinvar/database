<?php

declare(strict_types=1);

namespace Tinvar\Orm\Builder;

use Tinvar\Orm\Aggregate;
use Tinvar\Orm\RelationMeta;

/**
 * An aggregate over each row's related rows -- withCount(), withSum(),
 * orderByRelation() -- resolved when it was added: a relation of the model
 * the query reads, or a path of them ('shelves.items', 'lines.product'),
 * each a #[HasMany] or a #[BelongsTo]; the function; the column of the
 * model the path ends at (which may itself be reached through that
 * model's #[BelongsTo] relations); and the conditions those rows are
 * narrowed by.
 *
 * Compiled as a correlated subquery over the path's tables joined, each
 * with its tenant predicate (Compiler::relationMeasure()). The rows
 * aggregated are the ends of the path, one for each way along it: a count
 * over 'lines.product' counts each line's product, and so a product two
 * lines name twice (ADR 0073).
 *
 * @internal for the query builder, inside this package
 */
final readonly class RelationMeasure
{
    /**
     * @param string $name what the value is answered under (Aggregated); never written to SQL
     * @param RelationMeta $relation the path's last relation, whose target's rows are aggregated
     * @param list<Condition> $conditions the related rows'
     * @param list<RelationMeta> $through the relations before $relation, from the model the query reads
     */
    public function __construct(
        public string $name,
        public RelationMeta $relation,
        public Aggregate $function,
        public ?ColumnRef $column,
        public array $conditions,
        public array $through = [],
    ) {
        if ($function->needsColumn() && $column === null) {
            throw new \InvalidArgumentException("{$function->name} reads a column; name one.");
        }
    }

    /** The same rows, under another function and column. */
    public function as(Aggregate $function, ?ColumnRef $column): self
    {
        return new self($this->name, $this->relation, $function, $column, $this->conditions, $this->through);
    }
}
