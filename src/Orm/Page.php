<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * One page of a query's rows, by number, with how many rows there are in
 * all: what Query::paginate() returns.
 *
 * A page number is a position in the rows the reader may already see, in
 * the order the code chose -- it carries nothing the server has to trust,
 * so it travels in a URL as it is (ADR 0057). The framework's Scroll is
 * built from one.
 *
 * @template T
 */
final readonly class Page
{
    /** The most rows a page may hold: a page, not an export. */
    public const int MAX_PER_PAGE = 1000;

    /** @param list<T> $items this page's, in the query's order */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
    ) {
        if ($page < 1 || $perPage < 1 || $total < 0) {
            throw new \InvalidArgumentException('A page counts from 1, holds at least one row, and a total is never negative.');
        }
    }

    /** The last page there is: 1 for no rows at all. */
    public function lastPage(): int
    {
        return max(1, intdiv($this->total + $this->perPage - 1, $this->perPage));
    }

    /** Whether a page follows this one. */
    public function hasMore(): bool
    {
        return $this->page * $this->perPage < $this->total;
    }

    /**
     * The same page with each item mapped -- a model to the Data a page
     * sends, since a model never crosses to the renderer.
     *
     * @template U
     * @param \Closure(T): U $map
     * @return self<U>
     */
    public function map(\Closure $map): self
    {
        return new self(array_map($map, $this->items), $this->page, $this->perPage, $this->total);
    }
}
