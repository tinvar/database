<?php

declare(strict_types=1);

namespace Tinvar\Orm\Async;

/**
 * Statements and their bindings made ready for the database clients as
 * the sync driver makes them ready for PDO, so a statement means the same
 * on either driver.
 *
 * @internal the async drivers', inside this package
 */
final class Bindings
{
    /**
     * Each binding as PdoConnection binds it on Postgres and MySQL: as text,
     * the way PDO turns a value into text, or NULL.
     *
     * The clients would send an integer as an integer. On MySQL that brings
     * back what PdoConnection::bind() keeps out: a text column compared with
     * an integer numerically, so `'abc' = 0` holds and a key compared with a
     * number matches rows it does not name. A boolean is '1' or '0', as
     * PdoConnection::bind() binds one -- not PDO's '' for false, which
     * Postgres refuses for a boolean -- and a float is written to
     * `precision` significant digits, as PDO writes them.
     *
     * @param list<mixed> $bindings
     * @return list<?string>
     */
    public static function asText(array $bindings): array
    {
        $precision = (int) \ini_get('precision');
        $text = [];
        foreach ($bindings as $value) {
            $text[] = match (true) {
                $value === null => null,
                \is_string($value) => $value,
                \is_bool($value) => $value ? '1' : '0',
                \is_int($value) => (string) $value,
                \is_float($value) => $precision > 0 ? \sprintf("%.{$precision}G", $value) : (string) $value,
                $value instanceof \Stringable => (string) $value,
                default => throw new \InvalidArgumentException(\sprintf(
                    'A statement binds strings, numbers, booleans and NULL; it was given %s.',
                    get_debug_type($value),
                )),
            };
        }

        return $text;
    }

    /**
     * $sql with each `?` placeholder written as Postgres numbers them, `$1`
     * on: what PDO does for pdo_pgsql. A `?` inside a string, a quoted
     * identifier, a dollar-quoted body or a comment is left alone.
     *
     * Done here rather than by the client, which reads a `?` beside an
     * operator's character -- `a<?`, `?|` -- as an operator, and would bind
     * the values after it to the wrong placeholders. PDO's escape for a
     * literal question mark, `??`, is refused: the client would read what is
     * left of it as a placeholder of its own.
     */
    public static function numbered(string $sql): string
    {
        if (!str_contains($sql, '?')) {
            return $sql;
        }

        $out = '';
        $number = 0;
        $length = \strlen($sql);
        for ($i = 0; $i < $length;) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            $end = match (true) {
                $char === "'" => self::stringEnd($sql, $i, self::escapesBackslashes($sql, $i)),
                $char === '"' => self::quotedEnd($sql, $i, '"'),
                $char === '-' && $next === '-' => self::lineEnd($sql, $i),
                $char === '/' && $next === '*' => self::commentEnd($sql, $i),
                $char === '$' => self::dollarQuotedEnd($sql, $i),
                default => null,
            };
            if ($end !== null) {
                $out .= substr($sql, $i, $end - $i);
                $i = $end;

                continue;
            }

            if ($char === '?') {
                if ($next === '?') {
                    throw new \InvalidArgumentException(
                        'The async Postgres driver does not take PDO\'s ?? for a literal question mark; write the operator another way.',
                    );
                }
                $out .= '$' . ++$number;
                $i++;

                continue;
            }

            $out .= $char;
            $i++;
        }

        return $out;
    }

    /** Whether the string opening at $at is an escape string, E'...', where a backslash escapes. */
    private static function escapesBackslashes(string $sql, int $at): bool
    {
        $before = $sql[$at - 1] ?? '';
        if ($before !== 'E' && $before !== 'e') {
            return false;
        }
        $further = $sql[$at - 2] ?? '';

        return $further === '' || (!ctype_alnum($further) && $further !== '_');
    }

    /** Where the string opening at $at ends, one past its closing quote. */
    private static function stringEnd(string $sql, int $at, bool $backslashes): int
    {
        $length = \strlen($sql);
        for ($i = $at + 1; $i < $length; $i++) {
            if ($backslashes && $sql[$i] === '\\') {
                $i++;

                continue;
            }
            if ($sql[$i] === "'") {
                if (($sql[$i + 1] ?? '') === "'") {
                    $i++;

                    continue;
                }

                return $i + 1;
            }
        }

        return $length;
    }

    private static function quotedEnd(string $sql, int $at, string $quote): int
    {
        $length = \strlen($sql);
        for ($i = $at + 1; $i < $length; $i++) {
            if ($sql[$i] === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) {
                    $i++;

                    continue;
                }

                return $i + 1;
            }
        }

        return $length;
    }

    private static function lineEnd(string $sql, int $at): int
    {
        $newline = strpos($sql, "\n", $at);

        return $newline === false ? \strlen($sql) : $newline + 1;
    }

    /** Where the comment opening at $at ends: Postgres nests them. */
    private static function commentEnd(string $sql, int $at): int
    {
        $length = \strlen($sql);
        $depth = 0;
        for ($i = $at; $i < $length - 1; $i++) {
            $pair = $sql[$i] . $sql[$i + 1];
            if ($pair === '/*') {
                $depth++;
                $i++;
            } elseif ($pair === '*/') {
                $i++;
                if (--$depth === 0) {
                    return $i + 1;
                }
            }
        }

        return $length;
    }

    /**
     * Where the dollar-quoted body opening at $at ends, or null when the
     * `$` opens none -- `$1`, a numbered placeholder, or part of a name.
     */
    private static function dollarQuotedEnd(string $sql, int $at): ?int
    {
        $before = $sql[$at - 1] ?? '';
        if ($before !== '' && (ctype_alnum($before) || $before === '_' || $before === '$')) {
            return null;
        }
        if (preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $sql, $tag, 0, $at) !== 1) {
            return null;
        }

        $close = strpos($sql, $tag[0], $at + \strlen($tag[0]));

        return $close === false ? \strlen($sql) : $close + \strlen($tag[0]);
    }
}
