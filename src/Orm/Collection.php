<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * A relation collection that is lazy about its contents.
 *
 * Reading `items` resolves the relation. Inside a render that resolution
 * suspends the current fiber so the scheduler can batch it with every other
 * relation wanted in the same tick; outside one it falls back to Level 1.
 *
 * The await lives in the property hook rather than a lazy-ghost initializer:
 * PHP clears an object's lazy flag on initializer entry, so a second reader of a
 * shared ghost silently sees defaults. See ADR 0002.
 *
 * @template T of Model
 * @implements \IteratorAggregate<int, T>
 * @implements \ArrayAccess<int, T>
 */
final class Collection implements \IteratorAggregate, \Countable, \ArrayAccess
{
    /** @var list<T>|null */
    private ?array $loaded = null;

    /** @var (\Closure(): list<T>)|null */
    private ?\Closure $loader;

    /**
     * @param list<T>|null $items
     * @param (\Closure(): list<T>)|null $loader
     */
    private function __construct(?array $items, ?\Closure $loader)
    {
        $this->loaded = $items;
        $this->loader = $loader;
    }

    /**
     * @param list<T> $items
     * @return self<T>
     */
    public static function of(array $items): self
    {
        return new self($items, null);
    }

    /**
     * @param \Closure(): list<T> $loader
     * @return self<T>
     */
    public static function lazy(\Closure $loader): self
    {
        return new self(null, $loader);
    }

    /** @var list<T> */
    public array $items {
        get => $this->loaded ??= $this->resolve();
    }

    /** True when the contents are in hand, so tests can assert nothing loaded yet. */
    public function isLoaded(): bool
    {
        return $this->loaded !== null;
    }

    /**
     * @internal the ORM refills a loaded relation after a write, rather than
     * reloading it: "The identity map keeps loaded relations consistent after
     * a write, with no reload." In place, because a caller may be holding
     * this collection rather than reading the property again.
     *
     * @param list<T> $items
     */
    public function replaceItems(array $items): void
    {
        $this->loaded = $items;
    }

    /**
     * @internal for a loaded relation the ORM cannot refill, so the next read
     * loads it again. The loader is set before the items are dropped, so the
     * collection never has neither.
     *
     * @param \Closure(): list<T> $loader
     */
    public function unload(\Closure $loader): void
    {
        $this->loader = $loader;
        $this->loaded = null;
    }

    /**
     * @return list<T>
     *
     * The loader is released only after it returns. Clearing it first looks
     * tidier but reintroduces the ADR 0002 fault in this class: the loader
     * suspends, a second fiber enters with `loaded` still null, finds no loader
     * and silently resolves to an empty collection.
     */
    private function resolve(): array
    {
        // One of the two is always set: `loaded` is assigned the moment this
        // returns, and the loader is cleared only after it has.
        $loader = $this->loader ?? throw new \LogicException('A collection has neither items nor a loader.');
        $items = $loader();
        $this->loader = null;

        return $items;
    }

    public function count(): int
    {
        return \count($this->items);
    }

    /** @return \Traversable<int, T> */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->items);
    }

    /** @return T|null */
    public function first(): ?Model
    {
        return $this->items[0] ?? null;
    }

    /** @return list<T> */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * @param \Closure(T): mixed $fn
     * @return list<mixed>
     */
    public function map(\Closure $fn): array
    {
        return array_map($fn, $this->items);
    }

    /**
     * @param \Closure(T): bool $fn
     * @return list<T>
     */
    public function filter(\Closure $fn): array
    {
        return array_values(array_filter($this->items, $fn));
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    /** @return T|null */
    public function offsetGet(mixed $offset): ?Model
    {
        return $this->items[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Relation collections are read-only; write through the model instead.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('Relation collections are read-only; write through the model instead.');
    }
}
