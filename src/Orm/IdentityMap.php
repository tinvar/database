<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * "Keyed by connection, tenant, class and ID, and reset per request, per job and
 * on every tenant switch. The same row is the same object, which lazy batching
 * depends on." (tinvar-spec.md, "Engine layers")
 *
 * The invariant matters beyond object identity: a relation loaded for one parent
 * has to be visible to every other holder of that same row, or a batched load
 * would be wasted.
 */
final class IdentityMap
{
    /** @var array<string, array<string, object>> bucket => id => model */
    private array $rows = [];

    /**
     * The tenant, per fiber.
     *
     * "Context is fiber-local, so a suspended fiber can't leak a switch to
     * another." The tenant was a single shared value while the render
     * scheduler interleaves fibers, so an actAs() that suspended would have
     * moved every other fiber's tenant with it. Nothing could suspend inside
     * one only because Orm::switchTenant() nulled the scheduler, which was an
     * accident standing in for a guarantee -- and it silently dropped every
     * later relation load in the render from Level 2 batching to Level 1.
     */
    private string $tenantMain = '';

    /** @var \WeakMap<object, string> */
    private \WeakMap $tenantByFiber;

    public function __construct(private readonly string $connection = 'default')
    {
        $this->tenantByFiber = new \WeakMap();
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T|null
     */
    public function get(string $class, string $id): ?object
    {
        /** @var T|null */
        return $this->rows[$this->bucket($class)][$id] ?? null;
    }

    /**
     * Stores the model unless the row is already mapped, and returns whichever
     * instance is canonical. Callers must use the return value.
     *
     * @template T of object
     * @param T $model
     * @return T
     */
    public function remember(string $class, string $id, object $model): object
    {
        $bucket = $this->bucket($class);
        /** @var T|null $existing */
        $existing = $this->rows[$bucket][$id] ?? null;
        if ($existing !== null) {
            return $existing;
        }
        $this->rows[$bucket][$id] = $model;

        return $model;
    }

    public function has(string $class, string $id): bool
    {
        return isset($this->rows[$this->bucket($class)][$id]);
    }

    /**
     * Switches tenant, which is also a reset.
     *
     * tinvar-spec.md, "Tenancy": "The map is cleared at every job boundary,
     * every actAs and forEachTenant iteration, and every acrossTenants() exit."
     *
     * Bucket keys already carry the tenant, so rows cannot be read across one.
     * Clearing matters for switching *back*: without it, a later switch to a
     * tenant seen earlier in the request would serve rows cached before any
     * intervening write. Prefer Orm::switchTenant(), which also drops the
     * relation-row cache.
     *
     * Only a switch the main context makes is a reset; see below.
     */
    public function switchTenant(string $tenant): void
    {
        if ($tenant === $this->tenant()) {
            return;
        }

        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            $this->tenantMain = $tenant;
        } else {
            $this->tenantByFiber[$fiber] = $tenant;
        }

        // Only the tenant being ENTERED is dropped, not the whole map. Bucket
        // keys carry the tenant, so this is never about reading across one: it
        // is so a switch back to a tenant seen earlier in the request does not
        // serve rows cached before an intervening write. Clearing everything
        // would also throw away rows other fibers are still using.
        //
        // For the same reason, a fiber's switch drops nothing. The buckets
        // are shared by every fiber in the render, and the tenant a fiber
        // enters -- or returns to when its actAs() exits -- is usually one
        // other fibers are reading in: dropping it handed them a second
        // object for a row they already held, mid-render.
        if ($fiber === null) {
            $this->forgetTenant($tenant);
        }
    }

    public function tenant(): string
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            return $this->tenantMain;
        }

        return $this->tenantByFiber[$fiber] ?? $this->tenantMain;
    }

    /**
     * Clears the tenant itself, for the request and job teardown.
     *
     * flush() drops the rows; this drops the context they were read under.
     * Leaving it meant a tenant set by switchTenant() survived into the next
     * request on a long-lived worker.
     */
    public function resetTenant(): void
    {
        $this->tenantMain = '';
        $this->tenantByFiber = new \WeakMap();
    }

    /** Drops every bucket belonging to one tenant. */
    private function forgetTenant(string $tenant): void
    {
        $prefix = "{$this->connection}|{$tenant}|";

        foreach (array_keys($this->rows) as $bucket) {
            if (str_starts_with($bucket, $prefix)) {
                unset($this->rows[$bucket]);
            }
        }
    }

    /** Drops one row, after it has been deleted. */
    public function forget(string $class, string $id): void
    {
        unset($this->rows[$this->bucket($class)][$id]);
    }

    /** Called at every request, job, actAs and acrossTenants boundary. */
    public function flush(): void
    {
        $this->rows = [];
    }

    public function count(): int
    {
        return array_sum(array_map(\count(...), $this->rows));
    }

    /**
     * Every row held, in every tenant: what a request read, for the testing
     * layer's model-leak check, which looks for their columns in what the
     * render sent. Ghosts a relation registered and nothing loaded are among
     * them; the caller tells them apart.
     *
     * @return list<object>
     *
     * @internal for the testing layer (tinvar/testing), released with this
     *     package at one version; not for applications
     */
    public function all(): array
    {
        $all = [];
        foreach ($this->rows as $bucket) {
            foreach ($bucket as $model) {
                $all[] = $model;
            }
        }

        return $all;
    }

    private function bucket(string $class): string
    {
        return "{$this->connection}|{$this->tenant()}|{$class}";
    }
}
