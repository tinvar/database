<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * A typed factory.
 *
 * "Factories are typed classes returning a model, so IDEs autocomplete
 * fields" (tinvar-spec.md, "Factories"). definition() returns a real model
 * built through its own constructor, so a renamed field is a type error
 * rather than a wrong string key.
 *
 * @template T of Model
 */
abstract class Factory
{
    private int $count = 1;

    /** @var list<\Closure(T): void> */
    private array $states = [];

    /** @var array<string, Model> relation property => parent */
    private array $parents = [];

    /** Made-up values for definition(): `$this->faker->bothify('R-##')`. */
    protected Fake $faker;

    /** @return T */
    abstract public function definition(): Model;

    /** Every factory is built with no arguments, which is what new() relies on. */
    final public function __construct()
    {
        $this->faker = new Fake();
    }

    /**
     * The same factory drawing its made-up values from $seed, so a run can
     * be made again exactly.
     */
    public function seeded(int $seed): static
    {
        $clone = clone $this;
        $clone->faker = new Fake($seed);

        return $clone;
    }

    public static function new(): static
    {
        // Through static::class rather than `new static()`: the same object,
        // but typed as the concrete factory -- RackFactory, a Factory<Rack> --
        // where `new static()` in a static method cannot carry the class's T.
        $class = static::class;

        return new $class();
    }

    public function count(int $count): static
    {
        $clone = clone $this;
        $clone->count = max(0, $count);

        return $clone;
    }

    /**
     * Applies an adjustment to each model after definition().
     *
     * @param \Closure(T): void $state
     */
    public function state(\Closure $state): static
    {
        $clone = clone $this;
        $clone->states[] = $state;

        return $clone;
    }

    /**
     * Attaches a parent for a single relation.
     *
     * The relation is found by type, so `->for($site)` needs no property name
     * unless the model has two relations to the same class.
     */
    public function for(Model $parent, ?string $relation = null): static
    {
        $clone = clone $this;
        $clone->parents[$relation ?? $this->relationFor($parent)] = $parent;

        return $clone;
    }

    /**
     * Builds without writing.
     *
     * @return T|list<T>
     */
    public function make(): Model|array
    {
        $models = [];

        for ($i = 0; $i < $this->count; $i++) {
            $model = $this->definition();
            $meta = Metadata::for($model::class);

            foreach ($this->parents as $property => $parent) {
                $meta->set($model, $property, $parent);
            }
            foreach ($this->states as $state) {
                $state($model);
            }

            $models[] = $model;
        }

        return $this->count === 1 ? $models[0] : $models;
    }

    /**
     * Builds and writes. A batch is written in one transaction, so a failure
     * part way through leaves nothing behind.
     *
     * @return T|list<T>
     */
    public function create(Orm $orm): Model|array
    {
        $made = $this->make();
        $models = \is_array($made) ? $made : [$made];

        $orm->transaction(static function () use ($orm, $models): void {
            foreach ($models as $model) {
                $orm->save($model);
            }
        });

        return $made;
    }

    private function relationFor(Model $parent): string
    {
        $meta = Metadata::for(static::model());
        $matches = [];

        foreach ($meta->relations as $property => $relation) {
            if ($relation->kind === RelationKind::BelongsTo && $parent instanceof $relation->target) {
                $matches[] = $property;
            }
        }

        if (\count($matches) === 1) {
            return $matches[0];
        }

        throw new \LogicException(
            \count($matches) === 0
                ? static::model() . ' has no single relation to ' . $parent::class . '.'
                : static::model() . ' has ' . \count($matches) . ' relations to ' . $parent::class
                    . '; name the one you mean: ->for($parent, \'' . $matches[0] . '\').',
        );
    }

    /**
     * The model this factory builds, from definition()'s return type.
     *
     * @return class-string<T>
     */
    public static function model(): string
    {
        $type = (new \ReflectionMethod(static::class, 'definition'))->getReturnType();

        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            throw new \LogicException(
                static::class . '::definition() must declare the model it returns, so the factory is typed.',
            );
        }

        /** @var class-string<T> */
        return $type->getName();
    }
}
