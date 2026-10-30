<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * One lazy relation load the ORM ran -- one statement -- and whether it ran
 * in a batch, for the testing layer's assertNoLazyLoadsOutsideBatch() and
 * the dev toolbar.
 *
 * Decided in Orm::loadRelationRows(), from the parents it was handed:
 *
 *   - RENDER: Level 2. The BatchScheduler ran it at the end of a tick, for
 *     every parent the render's fibers awaited the relation on.
 *   - RESULT_SET: Level 1. It was for several parents -- the result set the
 *     one being read was hydrated in, or what of it had not loaded the
 *     relation yet.
 *   - ONE_ROW: Level 1, for a parent that is the whole of its result set,
 *     the one row a statement returned: batched as far as it can be by
 *     itself, and a 1+N when the statement runs in a loop -- a find() for
 *     each of N keys -- so a relation loaded this way more than once in
 *     what a test reads is outside a batch (LazyLoads::outsideBatch(), ADR
 *     0122).
 *   - ALONE: for one parent by itself, because it was read in no result set
 *     of rows -- a #[BelongsTo] ghost, filled from its row alone, or a model
 *     attached to no set -- or because its siblings were not loaded with
 *     it: resolveHasMany()'s retry, after a batch that did not produce its
 *     rows. Read on each of N such parents, that is N statements: the 1+N
 *     the batching exists to prevent.
 */
final readonly class LazyLoad
{
    public const string RENDER = 'render';
    public const string RESULT_SET = 'result set';
    public const string ONE_ROW = 'one row';
    public const string ALONE = 'alone';

    /**
     * @param string $relation the relation, as Owner::property
     * @param int $parents how many parents the statement was for
     * @param self::RENDER|self::RESULT_SET|self::ONE_ROW|self::ALONE $batch
     * @param string $site where the relation was touched, as file:line -- the
     *        first frame outside the ORM -- when the dev toolbar asked
     *        (Orm::flagLazyLoads()); '' otherwise
     */
    public function __construct(
        public string $relation,
        public int $parents,
        public string $batch,
        public string $site = '',
    ) {}

    /** Whether it ran in a batch, by itself: a ONE_ROW load is, until its relation loads that way again. */
    public function inBatch(): bool
    {
        return $this->batch !== self::ALONE;
    }

    /**
     * The loads of $loads that ran outside a batch: each for one parent
     * alone, and each ONE_ROW load of a relation that loaded that way more
     * than once among them -- the one row a statement returned, N times
     * over, is the loop over find() the batching exists to prevent, where
     * one statement for the N keys and one for their relation would do
     * (ADR 0122).
     *
     * @param list<self> $loads
     * @return list<self>
     */
    public static function outsideBatch(array $loads): array
    {
        $oneRow = [];
        foreach ($loads as $load) {
            if ($load->batch === self::ONE_ROW) {
                $oneRow[$load->relation] = ($oneRow[$load->relation] ?? 0) + 1;
            }
        }

        return array_values(array_filter(
            $loads,
            static fn (self $load): bool => !$load->inBatch() || ($load->batch === self::ONE_ROW && $oneRow[$load->relation] > 1),
        ));
    }
}
