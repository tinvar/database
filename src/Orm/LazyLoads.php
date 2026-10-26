<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * The lazy relation loads an Orm ran while a test recorded them
 * (Orm::recordLazyLoads()), in order.
 *
 * Only a test turns this on: the testing layer, for the application under
 * test (Testing::use()), or an ORM test for an Orm of its own. Nothing in a
 * request does, so a worker never holds one.
 */
final class LazyLoads
{
    /** @var list<LazyLoad> */
    private array $loads = [];

    public function record(LazyLoad $load): void
    {
        $this->loads[] = $load;
    }

    /** @return list<LazyLoad> every load recorded, from the $from'th on */
    public function since(int $from = 0): array
    {
        return \array_slice($this->loads, $from);
    }

    /** How many loads have been recorded: a mark for since(). */
    public function count(): int
    {
        return \count($this->loads);
    }

    /**
     * The loads from the $from'th on that ran for one parent alone.
     *
     * @return list<LazyLoad>
     */
    public function outsideBatch(int $from = 0): array
    {
        return array_values(array_filter($this->since($from), static fn (LazyLoad $load): bool => !$load->inBatch()));
    }
}
