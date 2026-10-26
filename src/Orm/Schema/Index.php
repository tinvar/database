<?php

declare(strict_types=1);

namespace Tinvar\Orm\Schema;

/** An index a migration declares: its columns in order, each ascending or descending. */
final readonly class Index
{
    /**
     * @param list<array{string, string}> $columns each column with 'ASC', 'DESC' or '' for the default
     */
    public function __construct(
        public string $name,
        public string $table,
        public array $columns,
        public bool $unique = false,
    ) {
        Identifier::assert($name, 'index');
        if ($columns === []) {
            throw new \InvalidArgumentException("Index '{$name}' has no columns.");
        }
    }

    /**
     * From the builder's spelling, a column name with an optional direction:
     * `['customer_id', 'placed_at DESC']`.
     *
     * @param list<string> $columns
     */
    public static function of(string $table, array $columns, bool $unique, ?string $name): self
    {
        $parsed = [];
        foreach ($columns as $column) {
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]{0,62})(?:\s+(ASC|DESC))?\z/i', trim($column), $m) !== 1) {
                throw new \InvalidArgumentException("'{$column}' is not an index column: a column name, then ASC or DESC if it matters.");
            }
            $parsed[] = [$m[1], strtoupper($m[2] ?? '')];
        }

        // The default name is the table and its columns, as a hand-written
        // schema would name it, so the index a migration makes is the one
        // `EXPLAIN` and a later dropIndex() can name without looking it up.
        // An index lives in its table's schema, so a qualified table's
        // default name leaves the schema out.
        $bare = str_contains($table, '.') ? substr($table, (int) strrpos($table, '.') + 1) : $table;
        $name ??= $bare . '_' . implode('_', array_column($parsed, 0)) . ($unique ? '_unique' : '');

        return new self($name, $table, $parsed, $unique);
    }

    /** @return list<string> the column names, without their directions */
    public function columnNames(): array
    {
        return array_column($this->columns, 0);
    }
}
