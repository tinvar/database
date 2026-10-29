<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * Level 2 lazy loading: render-wide relation batching over fibers.
 *
 * Each call in a query batch runs in its own fiber. When one reaches a relation
 * it has not got, it registers the load and suspends. Once every live fiber is
 * suspended, the scheduler runs one statement per relation and resumes them all.
 *
 * Registration happens in a relation accessor rather than a lazy-ghost
 * initializer; ADR 0002 records why the spec's wording is unsafe on PHP 8.5.
 *
 * Over a ConcurrentConnection -- fiber mode, ADR 0087 -- a fiber also
 * suspends while its statement is in flight, and the event loop, not this
 * class, resumes it. Such a fiber is neither finished nor waiting on a
 * relation, so the scheduler waits in the loop until every fiber is one or
 * the other, and only then flushes: the tick's statements overlap, and a
 * relation is still loaded once for every fiber that wants it.
 */
final class BatchScheduler
{
    /** @var array<string, array{relation: RelationMeta, tenant: string, parents: array<string, Model>}> */
    private array $pending = [];

    private int $rounds = 0;

    /**
     * Fiber mode's bookkeeping: the fibers this scheduler started, those of
     * them waiting on a relation, and how many are neither waiting on one
     * nor finished -- running, or waiting on the database.
     *
     * @var \WeakMap<object, true>
     */
    private \WeakMap $tasks;

    /** @var \WeakMap<object, true> */
    private \WeakMap $parked;

    private int $running = 0;

    /** What wakes the main context's wait in the event loop; null while it is not waiting. */
    private ?\Closure $wake = null;

    public function __construct(private readonly Orm $orm)
    {
        $this->tasks = new \WeakMap();
        $this->parked = new \WeakMap();
    }

    /**
     * Registers a relation load for this tick and suspends until it has run.
     *
     * Two fibers awaiting the same relation on the same parent join one group,
     * so a shared row costs one statement, not two.
     *
     * @param array<string, Model> $parents group key => parent: the one being
     *        read, and the rest of its result set that has not loaded it
     */
    public function await(string $key, RelationMeta $relation, array $parents): void
    {
        // The key comes from the caller because it carries the tenant: two
        // fibers awaiting the same relation in DIFFERENT tenants must not join
        // one group.
        //
        // Grouping alone is not enough. flush() runs in the MAIN context, not
        // in the fiber that awaited, so the tenant it would read there is the
        // main one -- the load would run under the wrong scope however neatly
        // it was grouped. The awaiting fiber's tenant is recorded here and
        // re-entered at flush time.
        $this->pending[$key] ??= ['relation' => $relation, 'tenant' => $this->orm->tenant(), 'parents' => []];
        foreach ($parents as $groupKey => $parent) {
            $this->pending[$key]['parents'][$groupKey] = $parent;
        }

        $fiber = \Fiber::getCurrent();
        $concurrent = $this->concurrent();
        if ($concurrent === null || $fiber === null || !isset($this->tasks[$fiber])) {
            \Fiber::suspend();

            return;
        }

        // A fiber waiting on a relation holds no database session: the flush
        // that answers it needs sessions, and a pool held by fibers that are
        // only waiting would starve it.
        $concurrent->release();
        $this->parked[$fiber] = true;
        $this->running--;
        $this->nudge();

        \Fiber::suspend();
    }

    public function hasPending(): bool
    {
        return $this->pending !== [];
    }

    /** Rounds of batched loading run so far; one round may cover many relations. */
    public function rounds(): int
    {
        return $this->rounds;
    }

    /** Runs one statement per pending relation. */
    public function flush(): void
    {
        if ($this->pending === []) {
            return;
        }

        $groups = $this->pending;
        $this->pending = [];
        $this->rounds++;

        if ($this->concurrent() !== null) {
            $this->flushConcurrently($groups);

            return;
        }

        foreach ($groups as $key => $group) {
            // A load that fails fails only the fibers that awaited it. They
            // resume to find their group missing, and resolveHasMany()'s
            // fallback load runs again inside each one's own call, so the
            // error is raised there and run() reports it against that call's
            // id. Escaping here, it failed every call in the tick -- finished
            // ones included -- and skipped the groups after it.
            try {
                // Back into the tenant that awaited, so the statement carries
                // that fiber's scope and the rows are cached under its
                // namespace. The key is passed in rather than recomputed, for
                // the same reason. A load in a batch, Level 2's, whoever its
                // parents are -- for the record a test reads (LazyLoad).
                $this->loadAs($group['tenant'], function () use ($group, $key): void {
                    $this->orm->loadRelationRows(
                        $group['relation'],
                        array_values($group['parents']),
                        $key,
                        LazyLoad::RENDER,
                    );
                });
            } catch (\Throwable) {
                continue;
            }
        }
    }

    /**
     * flush() over a ConcurrentConnection: each group's load in a fiber of
     * its own, as loadAs() runs one, all at once, waited for in the event
     * loop. A load that fails fails only the fibers that awaited it, as
     * above: the fiber's wrapper keeps the failure (task()).
     *
     * @param array<string, array{relation: RelationMeta, tenant: string, parents: array<string, Model>}> $groups
     */
    private function flushConcurrently(array $groups): void
    {
        $loads = [];
        foreach ($groups as $key => $group) {
            $loads[] = $this->task(function () use ($group, $key): void {
                $this->orm->actAs($group['tenant'], function () use ($group, $key): void {
                    $this->orm->loadRelationRows(
                        $group['relation'],
                        array_values($group['parents']),
                        $key,
                        LazyLoad::RENDER,
                    );
                });
            });
        }

        foreach ($loads as $load) {
            $this->running++;
            $load->start();
        }

        $this->waitForTheDatabase();

        // Nothing in a load waits on a relation. If one did, no flush would
        // ever resume it, and the rows it was loading would never arrive.
        foreach ($loads as $load) {
            if (!$load->isTerminated()) {
                throw new \LogicException('A batched relation load suspended; it has to run to completion.');
            }
        }
    }

    /**
     * Runs a batched load as $tenant, in a fiber of its own.
     *
     * flush() runs in the main context, where an actAs() is a main-context
     * switch, and those are resets (ADR 0008): entering the awaiting fiber's
     * tenant dropped that tenant's rows, and switching back dropped the main
     * tenant's -- mid-render, under fibers still holding them, so a row they
     * looked up again came back as a second object. A tenant entered inside
     * a fiber is that fiber's alone, and the switch drops nothing.
     *
     * @param \Closure(): void $load
     */
    private function loadAs(string $tenant, \Closure $load): void
    {
        $fiber = new \Fiber(function () use ($tenant, $load): void {
            $this->orm->actAs($tenant, $load);
        });
        $fiber->start();

        // Nothing in a load suspends. If something did, no flush would ever
        // resume it, and the rows it was loading would silently never arrive.
        if (!$fiber->isTerminated()) {
            throw new \LogicException('A batched relation load suspended; it has to run to completion.');
        }
    }

    /**
     * Runs every task as a fiber, flushing between rounds until all have
     * finished. A task that throws yields its Throwable in place, so one failed
     * call is reported against its own id instead of failing the batch.
     *
     * @param array<array-key, \Closure(): mixed> $tasks
     * @return array<array-key, mixed>
     */
    public function run(array $tasks): array
    {
        if ($this->concurrent() !== null) {
            return $this->runConcurrently($tasks);
        }

        $results = [];
        $live = [];

        foreach ($tasks as $id => $task) {
            $live[$id] = new \Fiber($task);
        }

        foreach ($live as $id => $fiber) {
            $this->settle($id, $fiber, $results, $live, start: true);
        }

        while ($live !== []) {
            if (!$this->hasPending()) {
                throw new \LogicException(
                    'Render stalled: ' . \count($live) . ' fiber(s) suspended with no pending relation load. '
                    . 'A suspend outside the scheduler cannot be resumed.',
                );
            }

            $this->flush();

            foreach ($live as $id => $fiber) {
                $this->settle($id, $fiber, $results, $live, start: false);
            }
        }

        return $results;
    }

    /**
     * run() over a ConcurrentConnection.
     *
     * The main context gives its session back first: it waits here while
     * the tasks run, and a session it held would be one fewer for them. Then
     * each round runs every task that can run, waits in the event loop
     * while any is waiting on the database, and flushes once every task left
     * is waiting on a relation.
     *
     * Each task is wrapped: one resumed by the event loop finishes inside
     * the loop, where a throw would end the loop rather than the task, so
     * the wrapper keeps the failure as the task's result, as settle() does.
     *
     * @param array<array-key, \Closure(): mixed> $tasks
     * @return array<array-key, mixed>
     */
    private function runConcurrently(array $tasks): array
    {
        $this->concurrent()?->release();

        $results = [];
        $live = [];
        foreach ($tasks as $id => $task) {
            $live[$id] = $this->task($task);
        }

        foreach ($live as $fiber) {
            $this->running++;
            $fiber->start();
        }

        while (true) {
            $this->waitForTheDatabase();

            foreach ($live as $id => $fiber) {
                if ($fiber->isTerminated()) {
                    $results[$id] = $fiber->getReturn()[1];
                    unset($live[$id]);
                }
            }

            if ($live === []) {
                return $results;
            }

            if (!$this->hasPending()) {
                throw new \LogicException(
                    'Render stalled: ' . \count($live) . ' fiber(s) suspended with no pending relation load. '
                    . 'A suspend outside the scheduler cannot be resumed.',
                );
            }

            $this->flush();

            foreach ($live as $fiber) {
                if (isset($this->parked[$fiber]) && $fiber->isSuspended()) {
                    unset($this->parked[$fiber]);
                    $this->running++;
                    $fiber->resume();
                }
            }
        }
    }

    /**
     * A fiber of this scheduler's for $work, over a ConcurrentConnection: it
     * answers [true, what $work returned] or [false, what it threw], and on
     * its way out gives back its session and says it has finished.
     *
     * @param \Closure(): mixed $work
     * @return \Fiber<mixed, mixed, array{bool, mixed}, mixed>
     */
    private function task(\Closure $work): \Fiber
    {
        $concurrent = $this->concurrent();
        $fiber = new \Fiber(function () use ($work, $concurrent): array {
            try {
                return [true, $work()];
            } catch (\Throwable $e) {
                return [false, $e];
            } finally {
                $concurrent?->release();
                $this->running--;
                $this->nudge();
            }
        });
        $this->tasks[$fiber] = true;

        return $fiber;
    }

    /**
     * Waits in the event loop until no fiber of this scheduler's is running
     * or waiting on the database: each has finished or waits on a relation.
     */
    private function waitForTheDatabase(): void
    {
        $concurrent = $this->concurrent();
        if ($concurrent === null) {
            return;
        }

        try {
            $concurrent->waitUntil($this->settled(...), function (\Closure $wake): void {
                $this->wake = $wake;
            });
        } finally {
            $this->wake = null;
        }
    }

    /** Whether every fiber of this scheduler's has finished or waits on a relation. */
    private function settled(): bool
    {
        return $this->running === 0;
    }

    private function nudge(): void
    {
        if ($this->wake !== null) {
            ($this->wake)();
        }
    }

    /** The connection, when it runs fibers' statements at once; null for a sync one. */
    private function concurrent(): ?ConcurrentConnection
    {
        $connection = $this->orm->statements();

        return $connection instanceof ConcurrentConnection ? $connection : null;
    }

    /**
     * @param array<array-key, mixed>  $results
     * @param array<array-key, \Fiber<mixed, mixed, mixed, mixed>> $live
     * @param \Fiber<mixed, mixed, mixed, mixed> $fiber
     * @param-out array<array-key, mixed>  $results
     * @param-out array<array-key, \Fiber<mixed, mixed, mixed, mixed>> $live
     */
    private function settle(int|string $id, \Fiber $fiber, array &$results, array &$live, bool $start): void
    {
        try {
            $start ? $fiber->start() : ($fiber->isSuspended() ? $fiber->resume() : null);
        } catch (\Throwable $e) {
            $results[$id] = $e;
            unset($live[$id]);

            return;
        }

        if ($fiber->isTerminated()) {
            $results[$id] = $fiber->getReturn();
            unset($live[$id]);
        }
    }
}
