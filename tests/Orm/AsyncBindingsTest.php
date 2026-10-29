<?php

declare(strict_types=1);

/**
 * The async driver's statements mean what the sync driver's do (ADR
 * 0087): placeholders numbered as PDO numbers them for Postgres, and
 * values bound as PDO binds them, as text.
 */

namespace Tinvar\Tests\Orm\AsyncBindings;

use Tinvar\Orm\Async\Bindings;

it('numbers each placeholder as PDO does for Postgres', function () {
    expect(Bindings::numbered('SELECT * FROM "t" WHERE "a" = ? AND "b" IN (?, ?) LIMIT ?'))
        ->toBe('SELECT * FROM "t" WHERE "a" = $1 AND "b" IN ($2, $3) LIMIT $4')
        // Beside an operator's characters, where the client would read an operator.
        ->and(Bindings::numbered('SELECT ?<?, ?||?, -?'))->toBe('SELECT $1<$2, $3||$4, -$5')
        ->and(Bindings::numbered('SELECT 1'))->toBe('SELECT 1');
});

it('leaves a question mark alone inside a string, a quoted name, a dollar-quoted body or a comment', function () {
    $sql = "SELECT '?', 'it''s ?', E'\\' ?', \"col?\", \$\$ ? \$\$, \$tag\$ ? \$tag\$, ? -- a comment?\n, ? /* a /* nested? */ one? */ , ?";

    expect(Bindings::numbered($sql))
        ->toBe("SELECT '?', 'it''s ?', E'\\' ?', \"col?\", \$\$ ? \$\$, \$tag\$ ? \$tag\$, \$1 -- a comment?\n, \$2 /* a /* nested? */ one? */ , \$3");
});

it('refuses PDO\'s escape for a literal question mark', function () {
    expect(static fn (): string => Bindings::numbered('SELECT data ?? \'key\' FROM t WHERE id = ?'))
        ->toThrow(\InvalidArgumentException::class, '??');
});

it('binds every value as text, as PDO binds it', function () {
    expect(Bindings::asText([null, 'abc', 42, -7, true, false, 1.5, 0.1 + 0.2, 1e20]))
        ->toBe([null, 'abc', '42', '-7', '1', '', '1.5', '0.3', '1.0E+20']);
});

it('refuses a value PDO could not bind', function () {
    expect(static fn (): array => Bindings::asText([['an', 'array']]))->toThrow(\InvalidArgumentException::class)
        ->and(static fn (): array => Bindings::asText([new \stdClass()]))->toThrow(\InvalidArgumentException::class);
});
