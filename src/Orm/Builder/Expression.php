<?php

declare(strict_types=1);

namespace Tinvar\Orm\Builder;

use Tinvar\Orm\MappingException;
use Tinvar\Orm\ModelMeta;

/**
 * The condition whereRaw() takes, read as tokens and written out again
 * from them -- never passed through.
 *
 * "Raw" is the spec's name for it, and what it allows is a condition the
 * other methods cannot spell: arithmetic, a function, a CASE. What it does
 * not allow is anything that could reach past the model or the tenant:
 *
 *   - **no literal text**: no quote of any kind, so no string, no quoted
 *     identifier, and a value is a `?` bound like every other;
 *   - **no identifier but the model's**: a bare word is a column the
 *     metadata knows (Identifiers), a keyword from a short list, or a
 *     function from a shorter one, followed by its parenthesis -- so no
 *     table, no SELECT, no subquery, and no way to read a row the tenant
 *     predicate does not hold;
 *   - **no comment, no statement end**, and parentheses that balance, so
 *     the condition cannot close the group it is put in and OR its way
 *     past the tenant predicate after it.
 *
 * What is written to the SQL is the tokens, joined by spaces: a column as
 * the compiler quotes it, a keyword or function in capitals, a number, an
 * operator from the list below. The string a caller wrote never is.
 *
 * @internal for the query builder, inside this package
 */
final class Expression
{
    /** Words a condition may use, besides columns and functions. */
    public const array KEYWORDS = ['AND', 'OR', 'NOT', 'IS', 'NULL', 'TRUE', 'FALSE', 'IN', 'BETWEEN', 'LIKE', 'CASE', 'WHEN', 'THEN', 'ELSE', 'END'];

    /**
     * Functions a condition may call: what SQLite, Postgres and MySQL all
     * spell and mean alike. `||` is not here for the same reason: MySQL
     * reads it as OR.
     */
    public const array FUNCTIONS = ['LOWER', 'UPPER', 'COALESCE', 'NULLIF', 'ABS', 'LENGTH', 'ROUND', 'TRIM'];

    /** Operators, longest first, so `<=` is not read as `<` then `=`. */
    private const array OPERATORS = ['<=', '>=', '<>', '!=', '=', '<', '>', '+', '-', '*', '/', '%'];

    /** The longest condition taken: a condition, not a program. */
    public const int MAX_LENGTH = 2048;

    /**
     * @param int|null $bindings how many values were given for its `?`s; null where
     *        that is not known yet, as to the PHPStan rule reading a call
     * @return list<array{string, string|ColumnRef}> kind => text, or the column
     * @throws MappingException for anything outside the grammar above
     */
    public static function parse(ModelMeta $meta, string $sql, ?int $bindings): array
    {
        if (\strlen($sql) > self::MAX_LENGTH) {
            throw new MappingException('A raw condition is at most ' . self::MAX_LENGTH . ' bytes.');
        }

        $tokens = [];
        $depth = 0;
        $params = 0;
        $length = \strlen($sql);
        $at = 0;

        while ($at < $length) {
            if (preg_match('/\G\s+/', $sql, $m, 0, $at) === 1) {
                $at += \strlen($m[0]);
                continue;
            }

            $rest = substr($sql, $at, 2);
            if ($rest === '--' || $rest === '/*' || $rest === '*/') {
                throw self::refused($sql, $at, 'a comment');
            }

            $char = $sql[$at];
            if ($char === '?') {
                $tokens[] = ['param', '?'];
                $params++;
                $at++;
                continue;
            }
            if ($char === '(') {
                $tokens[] = ['open', '('];
                $depth++;
                $at++;
                continue;
            }
            if ($char === ')') {
                if (--$depth < 0) {
                    throw self::refused($sql, $at, 'a parenthesis it did not open');
                }
                $tokens[] = ['close', ')'];
                $at++;
                continue;
            }
            if ($char === ',') {
                $tokens[] = ['comma', ','];
                $at++;
                continue;
            }

            if (preg_match('/\G[0-9]+(?:\.[0-9]+)?(?![A-Za-z0-9_.])/', $sql, $m, 0, $at) === 1) {
                $tokens[] = ['number', $m[0]];
                $at += \strlen($m[0]);
                continue;
            }

            foreach (self::OPERATORS as $operator) {
                if (substr($sql, $at, \strlen($operator)) === $operator) {
                    $tokens[] = ['operator', $operator];
                    $at += \strlen($operator);
                    continue 2;
                }
            }

            if (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*/', $sql, $m, 0, $at) === 1) {
                $word = $m[0];
                $at += \strlen($word);
                $tokens[] = self::word($meta, $sql, $word, $at);
                continue;
            }

            // A quote of any kind, a backtick, a bracket, a semicolon, a
            // backslash, a `$` or a `:` placeholder, `||`: none is in the
            // grammar, and each is how a string, an identifier or a second
            // statement would be smuggled in.
            throw self::refused($sql, $at, "'{$char}'");
        }

        if ($tokens === []) {
            throw new MappingException('A raw condition cannot be empty.');
        }
        if ($depth !== 0) {
            throw new MappingException('A raw condition has to close every parenthesis it opens.');
        }
        if ($bindings !== null && $params !== $bindings) {
            throw new MappingException("A raw condition has {$params} placeholder(s) and was given {$bindings} value(s).");
        }

        return $tokens;
    }

    /**
     * A bare word: a column first -- a model may have a column a keyword
     * shares a name with, and it is quoted when written -- then a keyword,
     * which a parenthesis may follow (`AND (`, `IN (`), then a function,
     * which one must. Anything else is refused.
     *
     * @return array{string, string|ColumnRef}
     */
    private static function word(ModelMeta $meta, string $sql, string $word, int $after): array
    {
        $call = preg_match('/\G\s*\(/', $sql, $m, 0, $after) === 1;
        $upper = strtoupper($word);

        if (!$call && (str_contains($word, '.') || \in_array($word, Identifiers::names($meta), true))) {
            return ['column', Identifiers::column($meta, $word)];
        }

        if (\in_array($upper, self::KEYWORDS, true)) {
            return ['keyword', $upper];
        }

        if ($call) {
            if (!\in_array($upper, self::FUNCTIONS, true)) {
                throw new MappingException("'{$word}' is not a function a raw condition may call: " . implode(', ', self::FUNCTIONS) . '.');
            }

            return ['function', $upper];
        }

        throw MappingException::unknownIdentifier($meta->class, $word, Identifiers::names($meta));
    }

    private static function refused(string $sql, int $at, string $what): MappingException
    {
        return new MappingException(
            "A raw condition may not hold {$what} (at byte {$at} of '" . substr($sql, 0, 80) . "'). "
            . 'Values are bound with ?, and columns are the model\'s.',
        );
    }
}
