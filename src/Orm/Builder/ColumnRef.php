<?php

declare(strict_types=1);

namespace Tinvar\Orm\Builder;

/**
 * A column a query names, resolved against compiled metadata: the
 * #[BelongsTo] relations walked from the model the query reads, and the
 * column at the end of them.
 *
 * Only Identifiers makes one, and only from names the metadata knows, so a
 * ColumnRef in a query is already a column of a model -- what the compiler
 * quotes is never a string a caller handed in.
 *
 * @internal for the query builder, inside this package
 */
final readonly class ColumnRef
{
    /**
     * @param list<string> $path   the #[BelongsTo] properties walked, outermost first
     * @param string       $column the column of the model at the end of the path
     */
    public function __construct(
        public array $path,
        public string $column,
    ) {}

    /** The joins it needs, by path: '' for the model the query reads. */
    public function pathKey(): string
    {
        return implode('.', $this->path);
    }
}
