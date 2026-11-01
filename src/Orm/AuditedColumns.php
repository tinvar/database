<?php

declare(strict_types=1);

namespace Tinvar\Orm;

use Tinvar\Orm\Attributes\Audited;
use Tinvar\Orm\Attributes\Sensitive;

/**
 * An #[Audited] model's columns as its audit entries name them, compiled
 * once with its metadata (ADR 0150): each column under the field a
 * developer knows it by -- its property, or the #[BelongsTo] whose key it
 * holds -- the ones never recorded, and the secrets recorded as changed and
 * never with their values.
 */
final readonly class AuditedColumns
{
    /** The longest a recorded string is kept, in bytes; past it, it is cut and marked. */
    public const int MAX_VALUE_BYTES = 4096;

    /**
     * @param array<string, string> $fields column => field, every column an entry may name
     * @param array<string, true> $redacted the columns recorded as `[redacted]`
     */
    public function __construct(
        public array $fields,
        public array $redacted,
    ) {}

    /**
     * Compiles what $declared asks of a model's columns.
     *
     * @param class-string<Model> $class
     * @param array<string, string> $columns property => column
     * @param array<string, RelationMeta> $relations
     * @param list<string> $stamps the createdAt and updatedAt properties the ORM stamps
     * @throws MappingException for an `except:` naming nothing the model maps
     */
    public static function compile(
        string $class,
        Audited $declared,
        array $columns,
        array $relations,
        string $idColumn,
        ?string $tenantColumn,
        array $stamps,
    ): self {
        $fields = [];
        $byName = [];
        foreach ($columns as $property => $column) {
            $fields[$column] = $property;
            $byName[$property] = $column;
            $byName[$column] = $column;
        }
        foreach ($relations as $property => $relation) {
            if ($relation->kind === RelationKind::BelongsTo) {
                $fields[$relation->foreignKey] = $property;
                $byName[$property] = $relation->foreignKey;
                $byName[$relation->foreignKey] = $relation->foreignKey;
            }
        }

        // What an entry names otherwise: the row by its key, the tenant by
        // its own column, and its moment by its own.
        unset($fields[$idColumn]);
        if ($tenantColumn !== null) {
            unset($fields[$tenantColumn]);
        }
        foreach ($stamps as $stamp) {
            unset($fields[$columns[$stamp]]);
        }

        foreach ($declared->except as $name) {
            $column = $byName[$name] ?? null;
            if ($column === null) {
                throw new MappingException(
                    "{$class}: #[Audited(except: ...)] names '{$name}', which is no column, property or #[BelongsTo] of the model. "
                    . 'Name the field as the model declares it.',
                );
            }
            unset($fields[$column]);
        }

        $redacted = [];
        $reflection = new \ReflectionClass($class);
        foreach ($fields as $column => $field) {
            $marked = $reflection->hasProperty($field)
                && $reflection->getProperty($field)->getAttributes(Sensitive::class, \ReflectionAttribute::IS_INSTANCEOF) !== [];
            if ($marked || Audited::namesSecret($field) || Audited::namesSecret($column)) {
                $redacted[$column] = true;
            }
        }

        return new self($fields, $redacted);
    }

    /**
     * What changed between two forms of a row, field by field: every field
     * a row created was made with, every one a row deleted held, and for
     * one updated each of $columns whose value differs. Both rows are
     * column => value as the database stores them.
     *
     * @param array<string, mixed>|null $before null for a row created
     * @param array<string, mixed>|null $after null for a row deleted
     * @param list<string>|null $columns the columns a write set; null for all
     * @return array<string, array{0: scalar|null, 1: scalar|null}> field => [before, after]
     */
    public function diff(?array $before, ?array $after, ?array $columns = null): array
    {
        $changes = [];
        foreach ($this->fields as $column => $field) {
            if ($columns !== null && !\in_array($column, $columns, true)) {
                continue;
            }
            $was = $before === null ? null : self::recorded($before[$column] ?? null);
            $now = $after === null ? null : self::recorded($after[$column] ?? null);
            if ($before !== null && $after !== null && $was === $now) {
                continue;
            }
            if (($before === null && $now === null) || ($after === null && $was === null)) {
                // Made empty, or held nothing: nothing to say of it.
                continue;
            }
            if (isset($this->redacted[$column])) {
                $was = $was === null ? null : ModelChange::REDACTED;
                $now = $now === null ? null : ModelChange::REDACTED;
            }
            $changes[$field] = [$was, $now];
        }

        return $changes;
    }

    /** A stored value as an entry holds it: a scalar or null, a long string cut. */
    private static function recorded(mixed $value): string|int|float|bool|null
    {
        if ($value === null || \is_int($value) || \is_float($value) || \is_bool($value)) {
            return $value;
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        $text = \is_string($value) ? $value : (\is_scalar($value) || $value instanceof \Stringable ? (string) $value : get_debug_type($value));
        if (\strlen($text) > self::MAX_VALUE_BYTES) {
            $text = mb_strcut($text, 0, self::MAX_VALUE_BYTES, 'UTF-8') . '…';
        }

        return $text;
    }
}
