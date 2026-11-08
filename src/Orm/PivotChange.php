<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * What Orm::attach(), detach() or sync() changed (ADR 0192): the keys of
 * the related rows linked and unlinked, in the order they were handed in.
 * A link already there is not attached again, nor one not there detached,
 * so each list holds only what the call wrote.
 */
final readonly class PivotChange
{
    /**
     * @param list<string> $attached
     * @param list<string> $detached
     */
    public function __construct(
        public array $attached = [],
        public array $detached = [],
    ) {}

    public function changed(): bool
    {
        return $this->attached !== [] || $this->detached !== [];
    }
}
