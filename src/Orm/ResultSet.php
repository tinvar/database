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
     * Relation rows a read across tenants or a subtree loaded for these
     * models, by the widened scope's name and the relation: with the Orm's
     * write generation they were read at, and grouped as
     * Orm::loadRelationRows() groups them.
     *
     * @var array<string, array{int, array<string, list<array<string, mixed>>>}>
     */
    private array $widened = [];

    /**
     * @param bool $alone a set made for one model hydrated by itself -- a
     *        #[BelongsTo] ghost filled from its row -- rather than the rows a
     *        statement returned together. A relation read on its model loads
     *        for that model alone, which the record a test reads says
     *        (LazyLoad::ALONE).
     */
    public function __construct(public readonly bool $alone = false) {}

    /**
     * The rows kept under $key, or null when none were, or they were read
     * before the write generation the Orm is at now.
     *
     * @return array<string, list<array<string, mixed>>>|null
     */
    public function widenedRows(string $key, int $generation): ?array
    {
        $kept = $this->widened[$key] ?? null;

        return $kept !== null && $kept[0] === $generation ? $kept[1] : null;
    }

    /** @param array<string, list<array<string, mixed>>> $rows */
    public function keepWidenedRows(string $key, int $generation, array $rows): void
    {
        $this->widened[$key] = [$generation, $rows];
    }

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
