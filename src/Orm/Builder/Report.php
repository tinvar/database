<?php

declare(strict_types=1);

namespace Tinvar\Orm\Builder;

use Tinvar\Orm\Aggregate;
use Tinvar\Orm\Model;

/**
 * The class a report's rows are read into (Query::report()), checked
 * before any SQL is built: each column the report reads fills a parameter
 * of its constructor, every parameter it does not read has a default, and
 * every one it reads is typed as something a column or an aggregate can
 * be read as -- int, float, string, bool, a backed enum, or mixed.
 *
 * Then each row is made into one, its values read as their parameters'
 * types. The database's drivers each spell a number their own way --
 * SQLite an int, Postgres an int or a numeric string, MySQL a decimal
 * string -- so a value is converted where it means the same, and refused,
 * with an UnexpectedValueException, where it would not: '2.5' is no int, a
 * NULL no string, a value that is no case of the enum no enum.
 *
 * Never a model: a model is a whole row, the identity map's, and what
 * save() writes back. A group is no row.
 *
 * @internal for the query builder, inside this package
 * @template R of object
 */
final readonly class Report
{
    /**
     * @param class-string<R> $class
     * @param array<string, array{string, bool}> $types by parameter: its type's name ('mixed' for none) and whether it takes null
     */
    private function __construct(
        private string $class,
        private array $types,
    ) {}

    /**
     * @template C of object
     * @param class-string<C> $class
     * @param list<string> $names the parameters the report fills
     * @return self<C>
     */
    public static function plan(string $class, array $names): self
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException("A report is read into a class, and {$class} is none.");
        }
        if (is_a($class, Model::class, true)) {
            throw new \LogicException(
                "A report is read into a class of its own, not {$class}: a model is a whole row, and a report reads groups or some columns.",
            );
        }
        $reflection = new \ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new \InvalidArgumentException("{$class} cannot be made, so a report cannot be read into it.");
        }

        $parameters = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->isVariadic()) {
                throw new \InvalidArgumentException("{$class} takes its values variadically; a report fills named parameters.");
            }
            $parameters[$parameter->getName()] = $parameter;
        }

        foreach ($names as $name) {
            if (!isset($parameters[$name])) {
                throw new \InvalidArgumentException(
                    "{$class}'s constructor has no parameter '{$name}' for the report to fill. It takes: "
                    . (implode(', ', array_keys($parameters)) ?: 'nothing') . '.',
                );
            }
        }

        $types = [];
        foreach ($parameters as $name => $parameter) {
            if (!\in_array($name, $names, true)) {
                if (!$parameter->isOptional()) {
                    throw new \InvalidArgumentException("{$class} needs '{$name}', which the report does not read.");
                }
                continue;
            }
            $types[$name] = self::type($class, $parameter);
        }

        return new self($class, $types);
    }

    /**
     * One row, made into the class.
     *
     * @param array<string, mixed> $values by parameter
     * @return R
     */
    public function make(array $values): object
    {
        $arguments = [];
        foreach ($this->types as $name => [$type, $nullable]) {
            $arguments[$name] = $this->read($name, $type, $nullable, $values[$name] ?? null);
        }

        return new ($this->class)(...$arguments);
    }

    /** @return array{string, bool} */
    private static function type(string $class, \ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();
        if ($type === null) {
            return ['mixed', true];
        }
        if (!$type instanceof \ReflectionNamedType) {
            throw new \InvalidArgumentException(
                "{$class}::\${$parameter->getName()} is typed {$type}; a report reads one type into each parameter.",
            );
        }

        $name = $type->getName();
        if (\in_array($name, ['int', 'float', 'string', 'bool', 'mixed'], true) || is_subclass_of($name, \BackedEnum::class)) {
            return [$name, $type->allowsNull()];
        }

        throw new \InvalidArgumentException(
            "{$class}::\${$parameter->getName()} is typed {$name}; a report reads an int, a float, a string, a bool, a backed enum or mixed.",
        );
    }

    private function read(string $name, string $type, bool $nullable, mixed $value): mixed
    {
        if ($value === null || $type === 'mixed') {
            return $value === null && !$nullable
                ? throw new \UnexpectedValueException("{$this->class}::\${$name} is never null, and the report read NULL.")
                : $value;
        }

        $read = match (true) {
            $type === 'int' => self::int($value),
            $type === 'float' => \is_int($value) || \is_float($value) || (\is_string($value) && is_numeric($value)) ? (float) $value : null,
            $type === 'string' => \is_string($value) ? $value : (\is_int($value) ? (string) $value : null),
            $type === 'bool' => self::bool($value),
            default => self::enum($type, $value),
        };

        return $read ?? throw new \UnexpectedValueException(
            "{$this->class}::\${$name} is {$type}, and the report read " . get_debug_type($value) . (\is_scalar($value) ? " '{$value}'" : '') . '.',
        );
    }

    /**
     * As Query::sum() and an aggregate read one (Aggregate::integer()):
     * every integer PHP holds, so the same sum read into a report is not
     * refused at nineteen digits.
     */
    private static function int(mixed $value): ?int
    {
        return Aggregate::integer($value);
    }

    /** As each database hands one back: a bool, 0 or 1, or Postgres's 't' and 'f'. */
    private static function bool(mixed $value): ?bool
    {
        return match (true) {
            \is_bool($value) => $value,
            $value === 1, $value === '1', $value === 't', $value === 'true' => true,
            $value === 0, $value === '0', $value === 'f', $value === 'false' => false,
            default => null,
        };
    }

    private static function enum(string $type, mixed $value): ?\BackedEnum
    {
        /** @var class-string<\BackedEnum> $type */
        $cases = $type::cases();
        $backing = $cases === [] ? null : $cases[0]->value;
        $key = \is_int($backing) ? self::int($value) : (\is_string($value) ? $value : null);

        return $key === null ? null : $type::tryFrom($key);
    }
}
