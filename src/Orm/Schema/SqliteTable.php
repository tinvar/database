<?php

declare(strict_types=1);

namespace Tinvar\Orm\Schema;

/**
 * A SQLite table's CREATE TABLE statement, read back from sqlite_master
 * into its column definitions and table constraints.
 *
 * SQLite keeps a table's schema as the statement that made it, and tells
 * no more about a column than its declared type, NOT NULL, default and
 * key: a COLLATE, a CHECK, a GENERATED expression are in the statement
 * alone. Rebuilding a table to change or drop a column -- what SQLite's
 * ALTER TABLE cannot do -- has to keep them, so the statement is taken
 * apart into its parts and put back together with only the columns in
 * question touched: every other definition is written back as it was,
 * token for token.
 */
final class SqliteTable
{
    /** The words that begin a constraint on a column, after its name and type. */
    private const array COLUMN_CONSTRAINTS = [
        'CONSTRAINT', 'PRIMARY', 'NOT', 'NULL', 'UNIQUE', 'CHECK', 'DEFAULT', 'COLLATE', 'REFERENCES', 'GENERATED', 'AS',
    ];

    /** The words that begin a table constraint rather than a column definition. */
    private const array TABLE_CONSTRAINTS = ['CONSTRAINT', 'PRIMARY', 'UNIQUE', 'CHECK', 'FOREIGN'];

    /**
     * @param list<array{column: string|null, tokens: list<array{string, string}>}> $items each
     *        column definition (with its name) and table constraint (with none), in order
     * @param string $tail what follows the closing parenthesis: WITHOUT ROWID, STRICT
     */
    private function __construct(
        public readonly array $items,
        public readonly string $tail,
    ) {}

    public static function parse(string $sql): self
    {
        $tokens = self::tokenize($sql);
        $start = null;
        $depth = 0;
        $items = [];
        $current = [];
        foreach ($tokens as $i => $token) {
            if ($start === null) {
                if ($token === ['punct', '(']) {
                    $start = $i;
                    $depth = 1;
                }
                continue;
            }

            if ($token === ['punct', '(']) {
                $depth++;
            } elseif ($token === ['punct', ')']) {
                $depth--;
                if ($depth === 0) {
                    $items[] = self::item($current);

                    return new self(
                        $items,
                        implode('', array_column(\array_slice($tokens, $i + 1), 1)),
                    );
                }
            } elseif ($token === ['punct', ','] && $depth === 1) {
                $items[] = self::item($current);
                $current = [];
                continue;
            }
            $current[] = $token;
        }

        throw new \InvalidArgumentException('Not a CREATE TABLE statement SQLite would have kept: its column list does not close.');
    }

    /**
     * SQL in tokens, each its kind and its text as written: ws, comment,
     * string, word (a bare name or keyword), quoted (a quoted name), number,
     * punct. Joined back, the texts are the SQL.
     *
     * @return list<array{string, string}>
     */
    public static function tokenize(string $sql): array
    {
        preg_match_all(
            '/\s+|--[^\n]*|\/\*.*?(?:\*\/|\z)|\'(?:[^\']|\'\')*\'|"(?:[^"]|"")*"|`(?:[^`]|``)*`|\[[^\]]*\]'
            . '|[A-Za-z_\x80-\xff][A-Za-z0-9_$\x80-\xff]*|\d+(?:\.\d*)?(?:[eE][+-]?\d+)?|\.\d+|./s',
            $sql,
            $matches,
        );

        $tokens = [];
        foreach ($matches[0] as $text) {
            $first = $text[0];
            $kind = match (true) {
                ctype_space($first) => 'ws',
                str_starts_with($text, '--'), str_starts_with($text, '/*') => 'comment',
                $first === "'" && \strlen($text) > 1 => 'string',
                \in_array($first, ['"', '`', '['], true) && \strlen($text) > 1 => 'quoted',
                ctype_digit($first), $first === '.' && \strlen($text) > 1 => 'number',
                ctype_alpha($first), $first === '_', \ord($first) >= 0x80 => 'word',
                default => 'punct',
            };
            $tokens[] = [$kind, $text];
        }

        return $tokens;
    }

    /**
     * A name as SQLite reads it: unquoted, a doubled quote made one.
     *
     * @param array{string, string} $token
     */
    public static function name(array $token): ?string
    {
        [$kind, $text] = $token;

        return match (true) {
            $kind === 'word' => $text,
            $kind === 'quoted' && $text[0] === '[' => substr($text, 1, -1),
            $kind === 'quoted' => str_replace($text[0] . $text[0], $text[0], substr($text, 1, -1)),
            $kind === 'string' => str_replace("''", "'", substr($text, 1, -1)),
            default => null,
        };
    }

    /** @return list<string> the columns, in order */
    public function columns(): array
    {
        return array_values(array_filter(array_column($this->items, 'column'), static fn (?string $name): bool => $name !== null));
    }

    /** Whether a column compares without regard to case: COLLATE NOCASE in its definition. */
    public function caseInsensitive(string $column): bool
    {
        foreach ($this->segments($column) as [$kind, $tokens]) {
            if ($kind === 'COLLATE' && strtoupper(self::name(self::significant($tokens)[1] ?? ['punct', '']) ?? '') === 'NOCASE') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether anything in the table besides the column's own definition
     * names it: a table constraint, another column's check or generated
     * expression. What SQLite's DROP COLUMN refuses to drop a column for.
     */
    public function namedElsewhere(string $column): bool
    {
        foreach ($this->items as $item) {
            if ($item['column'] !== null && strcasecmp($item['column'], $column) === 0) {
                continue;
            }
            if (self::mentions($item['column'] === null ? $item['tokens'] : \array_slice($item['tokens'], 1), $column)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The table's column list as it would be with $dropped gone and the
     * columns in $changed defined anew, between its parentheses.
     *
     * A dropped column takes with it every table constraint that names it
     * -- a key over it, a check reading it -- and every check on another
     * column that reads it, as Postgres drops what depends on a dropped
     * column. A changed column is given its new type, nullability, default
     * and collation, and keeps its keys, checks and generated expression.
     *
     * @param list<string> $dropped
     * @param array<string, string> $changed by column name: its new definition, name and type first,
     *        without keys or checks
     */
    public function rebuilt(array $dropped, array $changed): string
    {
        $isDropped = static function (string $name) use ($dropped): bool {
            foreach ($dropped as $column) {
                if (strcasecmp($column, $name) === 0) {
                    return true;
                }
            }

            return false;
        };

        $lines = [];
        foreach ($this->items as $item) {
            $column = $item['column'];
            if ($column === null) {
                if (!$this->tableConstraintNamesAny($item['tokens'], $dropped)) {
                    $lines[] = self::text($item['tokens']);
                }
                continue;
            }
            if ($isDropped($column)) {
                continue;
            }

            $definition = null;
            foreach ($changed as $name => $new) {
                if (strcasecmp((string) $name, $column) === 0) {
                    $definition = $new;
                }
            }
            if ($definition === null && !self::mentionsAny(\array_slice($item['tokens'], 1), $dropped)) {
                // Untouched, so written back as it was.
                $lines[] = self::text($item['tokens']);
                continue;
            }
            $kept = $this->keptSegments($column, $dropped, $definition !== null);
            $lines[] = $definition === null
                ? self::text([$item['tokens'][0]]) . $kept['type'] . $kept['constraints']
                : $definition . $kept['constraints'];
        }

        return "\n    " . implode(",\n    ", array_map('trim', $lines)) . "\n";
    }

    /**
     * A column's definition after its name, split where each constraint
     * begins: its type first (kind TYPE), then each constraint, kinds named
     * by their first word -- NOT for NOT NULL.
     *
     * @return list<array{string, list<array{string, string}>}>
     */
    private function segments(string $column): array
    {
        foreach ($this->items as $item) {
            if ($item['column'] !== null && strcasecmp($item['column'], $column) === 0) {
                return self::split(\array_slice($item['tokens'], 1));
            }
        }

        return [];
    }

    /**
     * @param list<array{string, string}> $tokens
     * @return list<array{string, list<array{string, string}>}>
     */
    private static function split(array $tokens): array
    {
        $segments = [];
        $kind = 'TYPE';
        $current = [];
        $depth = 0;
        $previous = '';
        foreach ($tokens as $i => $token) {
            if ($token === ['punct', '(']) {
                $depth++;
            } elseif ($token === ['punct', ')']) {
                $depth--;
            }

            $word = $token[0] === 'word' ? strtoupper($token[1]) : '';
            if ($depth === 0 && \in_array($word, self::COLUMN_CONSTRAINTS, true) && !self::continues($word, $previous, $tokens, $i)) {
                $segments[] = [$kind, $current];
                $kind = $word;
                $current = [];
            }
            $current[] = $token;

            if ($token[0] !== 'ws' && $token[0] !== 'comment') {
                $previous = $word;
            }
        }
        $segments[] = [$kind, $current];

        return $segments;
    }

    /**
     * Whether a constraint's word belongs to the clause before it rather
     * than beginning one: NULL after NOT, DEFAULT or SET (ON DELETE SET
     * NULL), DEFAULT after SET, NOT before DEFERRABLE, AS after ALWAYS.
     *
     * @param list<array{string, string}> $tokens
     */
    private static function continues(string $word, string $previous, array $tokens, int $at): bool
    {
        return match ($word) {
            'NULL' => \in_array($previous, ['NOT', 'DEFAULT', 'SET'], true),
            'DEFAULT' => $previous === 'SET',
            'AS' => $previous === 'ALWAYS',
            'NOT' => strtoupper(self::significant(\array_slice($tokens, $at + 1))[0][1] ?? '') === 'DEFERRABLE',
            default => false,
        };
    }

    /**
     * A column's type as written, and the constraints a rebuild keeps on it:
     * all of them, for a column not changed, but a check reading a dropped
     * column; for a changed one only its keys, checks and generated
     * expression, since its new definition says the rest. A CONSTRAINT name
     * goes with the constraint after it.
     *
     * @param list<string> $dropped
     * @return array{type: string, constraints: string}
     */
    private function keptSegments(string $column, array $dropped, bool $changing): array
    {
        $type = '';
        $kept = '';
        $name = '';
        foreach ($this->segments($column) as [$kind, $tokens]) {
            if ($kind === 'TYPE') {
                $type = self::text($tokens);
                continue;
            }
            if ($kind === 'CONSTRAINT') {
                $name = self::text($tokens);
                continue;
            }

            $keep = match ($kind) {
                'CHECK' => !self::mentionsAny($tokens, $dropped),
                'GENERATED', 'AS' => self::mentionsAny($tokens, $dropped)
                    ? throw new \LogicException("Column '{$column}' is generated from a column being dropped; drop or redefine it first.")
                    : true,
                'NOT', 'NULL', 'DEFAULT', 'COLLATE' => !$changing,
                default => true,
            };
            if ($keep) {
                $kept .= ' ' . trim($name) . ($name === '' ? '' : ' ') . trim(self::text($tokens));
            }
            $name = '';
        }

        return ['type' => rtrim($type) === '' ? '' : ' ' . trim($type), 'constraints' => $kept];
    }

    /**
     * Whether a table constraint names one of $columns among its own: the
     * columns a PRIMARY KEY, UNIQUE or FOREIGN KEY is over -- not the
     * columns a foreign key references, in another table -- or any name a
     * CHECK reads.
     *
     * @param list<array{string, string}> $tokens
     * @param list<string> $columns
     */
    private function tableConstraintNamesAny(array $tokens, array $columns): bool
    {
        $significant = self::significant($tokens);
        // A CONSTRAINT name is a name of the constraint's, not a column.
        if (strtoupper($significant[0][1] ?? '') === 'CONSTRAINT') {
            $significant = \array_slice($significant, 2);
        }
        $kind = strtoupper($significant[0][1] ?? '');
        if ($kind === 'CHECK') {
            return self::mentionsAny($significant, $columns);
        }

        // The first parenthesized list: the constraint's own columns.
        $own = [];
        $depth = 0;
        foreach ($significant as $token) {
            if ($token === ['punct', '(']) {
                $depth++;
                continue;
            }
            if ($token === ['punct', ')']) {
                if (--$depth === 0) {
                    break;
                }
                continue;
            }
            if ($depth > 0) {
                $own[] = $token;
            }
        }

        return self::mentionsAny($own, $columns);
    }

    /**
     * Whether a constraint's tokens begin a table constraint.
     *
     * @param list<array{string, string}> $tokens
     */
    private static function isTableConstraint(array $tokens): bool
    {
        $first = self::significant($tokens)[0] ?? null;

        return $first !== null && $first[0] === 'word' && \in_array(strtoupper($first[1]), self::TABLE_CONSTRAINTS, true);
    }

    /**
     * @param list<array{string, string}> $tokens
     * @return array{column: string|null, tokens: list<array{string, string}>}
     */
    private static function item(array $tokens): array
    {
        // Without the whitespace and comments around it.
        while ($tokens !== [] && \in_array($tokens[0][0], ['ws', 'comment'], true)) {
            array_shift($tokens);
        }
        while ($tokens !== [] && \in_array($tokens[\count($tokens) - 1][0], ['ws', 'comment'], true)) {
            array_pop($tokens);
        }
        if ($tokens === []) {
            throw new \InvalidArgumentException('Not a CREATE TABLE statement SQLite would have kept: it has an empty definition.');
        }

        return [
            'column' => self::isTableConstraint($tokens) ? null : self::name($tokens[0]),
            'tokens' => $tokens,
        ];
    }

    /**
     * @param list<array{string, string}> $tokens
     * @param list<string> $columns
     */
    private static function mentionsAny(array $tokens, array $columns): bool
    {
        foreach ($columns as $column) {
            if (self::mentions($tokens, $column)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{string, string}> $tokens */
    private static function mentions(array $tokens, string $column): bool
    {
        foreach ($tokens as $token) {
            if (\in_array($token[0], ['word', 'quoted'], true) && strcasecmp((string) self::name($token), $column) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{string, string}> $tokens
     * @return list<array{string, string}>
     */
    private static function significant(array $tokens): array
    {
        return array_values(array_filter($tokens, static fn (array $token): bool => $token[0] !== 'ws' && $token[0] !== 'comment'));
    }

    /** @param list<array{string, string}> $tokens */
    private static function text(array $tokens): string
    {
        return implode('', array_column($tokens, 1));
    }
}
