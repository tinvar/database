<?php

declare(strict_types=1);

namespace Tinvar\Orm;

final class MappingException extends \RuntimeException
{
    public static function noTable(string $class): self
    {
        return new self("{$class} has no #[Table] attribute.");
    }

    public static function conflictingTenantScope(string $class, string $first, string $firstColumn, string $second, string $secondColumn): self
    {
        return new self(
            "{$class} is #[TenantScoped] by two columns: '{$firstColumn}' on {$first} and '{$secondColumn}' on {$second}. "
            . 'A tenant-scoped model has one tenant column; declare the same one everywhere, or only once.',
        );
    }

    public static function noId(string $class): self
    {
        return new self("{$class} has no #[Id] property.");
    }

    public static function untypedRelation(string $class, string $property): self
    {
        return new self(
            "{$class}::\${$property} has #[BelongsTo] but no class type to infer its target from; "
            . 'name the target explicitly.',
        );
    }

    /** @param list<string> $known */
    public static function unknownIdentifier(string $class, string $column, array $known): self
    {
        return new self(
            "{$class} has no column '{$column}'. The query builder only accepts identifiers from compiled "
            . 'metadata, so a sort or filter field cannot name a hidden column. Known: ' . implode(', ', $known) . '.',
        );
    }

    public static function malformedIdentifier(string $column): self
    {
        return new self(
            "'{$column}' is not a well-formed column name. Identifiers are never interpolated from input.",
        );
    }

    public static function relationTargetNotModel(string $class, string $property, string $target): self
    {
        return new self("{$class}::\${$property} relates to {$target}, which is not a Model.");
    }

    public static function unsupportedColumnType(string $class, string $property, string $type): self
    {
        return new self("{$class}::\${$property} has unsupported column type {$type}.");
    }

    /**
     * A stored value that is not one of the property's type. Named by the
     * type of what was found, never the value: a column may hold what an
     * error page or a log should not show.
     */
    public static function undecodable(string $class, string $property, string $column, string $type, string $found, ?\Throwable $previous = null): self
    {
        return new self(
            "{$class}::\${$property}: column '{$column}' holds {$found}, which does not decode into {$type}"
            . ($previous === null ? '.' : ': ' . $previous::class . '.'),
            previous: $previous,
        );
    }

    public static function notACast(string $class, string $property, string $named): self
    {
        return new self("{$class}::\${$property} names {$named} as its cast, which does not implement " . Cast::class . '.');
    }

    public static function castNeedsClass(string $class, string $property): self
    {
        return new self("{$class}::\${$property} has a cast, so it must be typed as one class: the class the cast makes.");
    }

    public static function castNotScalar(string $class, string $property, string $cast): self
    {
        return new self(
            "{$class}::\${$property}: {$cast}::toColumn() returned an array for a column that is not JSON; "
            . "return a scalar, or make columnType() 'json'.",
        );
    }

    public static function mutableValue(string $class, string $property, string $valueClass, string $mutable): self
    {
        return new self(
            "{$class}::\${$property} holds {$valueClass}, which is mutable: {$mutable}. "
            . 'A value-object column holds an immutable object, so a column changes only by assignment.',
        );
    }

    public static function unbackedEnum(string $class, string $property, string $enum): self
    {
        return new self("{$class}::\${$property} is the pure enum {$enum}, which has no value to store; give it a string or int backing.");
    }

    public static function unusableHook(string $class, string $method, string $attribute = 'BeforeSave'): self
    {
        return new self("{$class}: #[{$attribute}] {$method} must be an instance method that takes no arguments.");
    }
}
