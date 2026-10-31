<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * Reads raw SQL for what tinvar-spec.md, "Tenancy", flags: "Raw SQL ... on
 * tenant tables are flagged by a static check and in the dev toolbar." SQL
 * sent through Connection::select() or execute() passes no tenant
 * predicate of the ORM's, so a statement naming a #[TenantScoped] model's
 * table is one a reviewer should see -- PHPStan's RawTenantSqlRule when the
 * text is known, the dev toolbar when it runs (ADR 0057).
 *
 * A flag, not a parser: every name in the text outside a quoted string and
 * a comment is compared with the tenant tables, quoted or not, schema or
 * not, in any case. A column named like a tenant table is flagged too,
 * which costs a look; a table missed would cost a tenant's rows.
 */
final class RawSql
{
    /** The longest a statement is kept to be shown, after its literals are taken out. */
    public const int MAX_SHOWN = 2000;

    /** The words a statement that writes holds: writes() reads any of them, anywhere, as one. */
    private const array WRITE_WORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'MERGE', 'REPLACE', 'UPSERT', 'TRUNCATE', 'DROP', 'ALTER', 'CREATE', 'RENAME',
        'GRANT', 'REVOKE', 'COPY', 'CALL', 'EXEC', 'EXECUTE', 'DO', 'LOAD', 'HANDLER', 'IMPORT', 'INTO',
    ];

    /** A string literal, a quoted name, a comment, or a bare name: what tokens() reads. */
    private const string TOKENS = <<<'REGEX'
        ~
          (?<comment> --[^\n]* | /\*.*?(?:\*/|\z) )
        | (?<literal> [EeNnXxBb]?'(?:[^']|'')*(?:'|\z) | \$(?<tag>[A-Za-z_]*)\$.*?(?:\$\k<tag>\$|\z) )
        | (?<quoted> "(?:[^"]|"")*"? | `(?:[^`]|``)*`? | \[[^\]]*\]? )
        | (?<bare> [A-Za-z_][A-Za-z0-9_$]* )
        ~xs
        REGEX;

    /**
     * The tenant tables $sql names, each once, as $tables spells it.
     *
     * @param list<string> $tables
     * @return list<string>
     */
    public static function tenantTablesIn(string $sql, array $tables): array
    {
        if ($tables === []) {
            return [];
        }
        $wanted = [];
        foreach ($tables as $table) {
            $wanted[strtolower($table)] = $table;
            // A schema-qualified table is named by its last part as often.
            $bare = strrchr($table, '.');
            if ($bare !== false) {
                $wanted[strtolower(substr($bare, 1))] ??= $table;
            }
        }

        // Text the pattern gives up on is text nobody read: it may name
        // every one of them.
        $names = self::names($sql);
        if ($names === null) {
            return $tables;
        }

        $found = [];
        foreach ($names as $name) {
            $table = $wanted[strtolower($name)] ?? null;
            if ($table !== null) {
                $found[$table] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * Whether $sql may write: a word outside its strings and comments that
     * a statement writing rows, or the schema, starts with or holds -- a
     * DELETE inside a WITH included -- or text the pattern cannot read.
     * For what select() is sent: execute() is taken to write whatever it
     * says. A function a SELECT calls that writes rows is not seen.
     */
    public static function writes(string $sql): bool
    {
        $names = self::names($sql);
        if ($names === null) {
            return true;
        }
        foreach ($names as $name) {
            if (\in_array(strtoupper($name), self::WRITE_WORDS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * $sql with every string literal replaced by `'?'` and cut to
     * MAX_SHOWN bytes: to show where it was sent, not what it held. A
     * literal is where code that builds SQL from values puts them, and
     * those may be anyone's.
     */
    public static function redacted(string $sql): string
    {
        $shown = (string) preg_replace_callback(
            self::TOKENS,
            static fn (array $m): string => ($m['literal'] ?? '') !== '' ? "'?'" : $m[0],
            $sql,
        );

        return \strlen($shown) > self::MAX_SHOWN ? substr($shown, 0, self::MAX_SHOWN) . '...' : $shown;
    }

    /**
     * Every name in $sql, unquoted, with its schema and its parts apart:
     * `"public"."orders"` is public, orders and public.orders.
     *
     * @return list<string>|null null where the pattern could not read it
     */
    private static function names(string $sql): ?array
    {
        if (preg_match_all(self::TOKENS, $sql, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL) === false) {
            return null;
        }

        $names = [];
        $dotted = [];   // the parts of the dotted name being read, one list per name
        $end = 0;
        foreach ($matches as $match) {
            [$text, $at] = $match[0];
            $text = (string) $text;
            // What one database reads otherwise than this pattern: a
            // backslash escaping a quote (MySQL, Postgres's E''), a comment
            // MySQL runs (`/*! ... */`) or does not take for one (`--x`),
            // and a dollar quote, which is a name on MySQL. Unread, so it
            // may name every table.
            $literal = $match['literal'][0] ?? null;
            $comment = $match['comment'][0] ?? null;
            $unread = match (true) {
                $literal !== null => str_contains($literal, '\\') || ($match['tag'][0] ?? null) !== null,
                $comment !== null => str_starts_with($comment, '/*!')
                    || (str_starts_with($comment, '--') && preg_match('/^--(\s|$)/', $comment) !== 1),
                default => false,
            };
            if ($unread) {
                return null;
            }
            $name = match (true) {
                ($match['bare'][0] ?? null) !== null => $text,
                ($match['quoted'][0] ?? null) !== null => self::unquote($text),
                default => null,
            };

            // Parts of one dotted name touch: a dot between them and nothing else.
            $continues = $name !== null && $dotted !== [] && trim(substr($sql, $end, $at - $end)) === '.';
            $end = $at + \strlen($text);
            if ($name === null) {
                $dotted[] = [];
                continue;
            }
            if ($continues) {
                $dotted[\count($dotted) - 1][] = $name;
            } else {
                $dotted[] = [$name];
            }
            $names[] = $name;
        }

        foreach ($dotted as $parts) {
            if (\count($parts) > 1) {
                $names[] = implode('.', $parts);
            }
        }

        return $names;
    }

    private static function unquote(string $quoted): string
    {
        $open = $quoted[0];
        $close = $open === '[' ? ']' : $open;
        $inner = substr($quoted, 1, str_ends_with($quoted, $close) && \strlen($quoted) > 1 ? -1 : null);

        return $open === '[' ? $inner : str_replace($close . $close, $close, $inner);
    }
}
