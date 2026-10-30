<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * The lazy relation loads an Orm ran while a test recorded them
 * (Orm::recordLazyLoads()), in order.
 *
 * Only a test turns this on: the testing layer, for the application under
 * test (through its use()), or an ORM test for an Orm of its own. Nothing in a
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
     * The loads from the $from'th on that ran outside a batch: for one
     * parent alone, or for the one row of a statement run more than once
     * (LazyLoad::outsideBatch()).
     *
     * @return list<LazyLoad>
     */
    public function outsideBatch(int $from = 0): array
    {
        return LazyLoad::outsideBatch($this->since($from));
    }
}
