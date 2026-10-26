<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * The models hydrated from one query, held so a relation touched on any of them
 * loads for all of them at once.
 *
 * This is Level 1: "Loads units for every model in the same result set with one
 * whereIn. 1+1 queries, never 1+N."
 */
final class ResultSet
{
    /** @var list<Model> */
    public private(set) array $models = [];

    /**
     * @param bool $alone a set made for one model hydrated by itself -- a
     *        #[BelongsTo] ghost filled from its row -- rather than the rows a
     *        statement returned together. A relation read on its model loads
     *        for that model alone, which the record a test reads says
     *        (LazyLoad::ALONE).
     */
    public function __construct(public readonly bool $alone = false) {}

    public function add(Model $model): void
    {
        $this->models[] = $model;
    }

    /** @return list<Model> */
    public function all(): array
    {
        return $this->models;
    }
}
