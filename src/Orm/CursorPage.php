<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * One page of a query's rows after or before a row, with the cursors for
 * the pages either side: what Query::cursorPaginate() returns.
 *
 * The cursors are sealed by the Cursors they were made with -- signed and
 * bound -- and name a row by its key, never its values (ADR 0057). Null
 * where there is no page that way.
 *
 * `cursor` is the one the page was read at: a cursor that opened, as the
 * caller passed it, or null for the first page -- what a list loading its
 * pages one at a time tells this page apart by (ADR 0120). Never one that
 * did not open: that is InvalidCursor, and no page.
 *
 * @template T
 */
final readonly class CursorPage
{
    /** @param list<T> $items this page's, in the query's order */
    public function __construct(
        public array $items,
        public int $perPage,
        public ?string $next,
        public ?string $previous,
        public ?string $cursor = null,
    ) {}

    /**
     * The same page with each item mapped.
     *
     * @template U
     * @param \Closure(T): U $map
     * @return self<U>
     */
    public function map(\Closure $map): self
    {
        return new self(array_map($map, $this->items), $this->perPage, $this->next, $this->previous, $this->cursor);
    }
}
