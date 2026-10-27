<?php

declare(strict_types=1);

/**
 * paginate() and cursorPaginate() (ADR 0057): a page number carries nothing
 * the server trusts; a cursor names a row by key, is signed and bound to
 * its reader and to its query, and never carries a row's values.
 */

namespace Tinvar\Tests\Orm\QueryPaging;

use Tinvar\Orm\CursorPage;
use Tinvar\Orm\Cursors;
use Tinvar\Orm\InvalidCursor;
use Tinvar\Orm\Orm;
use Tinvar\Orm\Page;
use Tinvar\Orm\Query;
use Tinvar\Orm\SignedCursors;
use Tinvar\Support\Signer;
use Tinvar\Tests\Orm\Fixtures\Shelf;
use Tinvar\Tests\Orm\Fixtures\Shelves;

require_once __DIR__ . '/Fixtures/shelves.php';

function cursors(string $binding = 'session-a', ?\Closure $clock = null, string $key = 'test-key'): SignedCursors
{
    return new SignedCursors(new Signer($key, SignedCursors::PURPOSE), $binding, clock: $clock);
}

/** @param Page<Shelf>|CursorPage<Shelf> $page @return list<string> */
function names(Page|CursorPage $page): array
{
    return array_map(static fn (Shelf $shelf): string => $shelf->name, $page->items);
}

/** Every shelf as acme, by position: Alpha, Bravo, Charlie, Foxtrot. */
function acme(Orm $orm, \Closure $read): mixed
{
    return $orm->actAs('acme', $read);
}

describe('paginate', function () {
    it('reads a page by number, with the total', function () {
        $orm = Shelves::sqlite()->orm();
        $query = $orm->query(Shelf::class)->orderBy('position');

        $first = acme($orm, static fn (): Page => $query->paginate(1, 3));
        $second = acme($orm, static fn (): Page => $query->paginate(2, 3));
        $past = acme($orm, static fn (): Page => $query->paginate(9, 3));
        $huge = acme($orm, static fn (): Page => $query->paginate(\PHP_INT_MAX, 3));

        expect(names($first))->toBe(['Alpha', 'Bravo', 'Charlie'])
            ->and([$first->total, $first->lastPage(), $first->hasMore()])->toBe([4, 2, true])
            ->and(names($second))->toBe(['Foxtrot'])
            ->and($second->hasMore())->toBeFalse()
            ->and(names($past))->toBe([])
            ->and(names($huge))->toBe([]);
    });

    it('maps a page\'s items and keeps its numbers', function () {
        $orm = Shelves::sqlite()->orm();
        $page = acme($orm, static fn (): Page => $orm->query(Shelf::class)->orderBy('position')->paginate(2, 2))
            ->map(static fn (Shelf $shelf): string => strtoupper($shelf->name));

        expect($page->items)->toBe(['CHARLIE', 'FOXTROT'])
            ->and([$page->page, $page->perPage, $page->total])->toBe([2, 2, 4]);
    });

    it('refuses a page that is not one, and a query that set its own limit', function () {
        $orm = Shelves::sqlite()->orm();

        expect(fn () => $orm->query(Shelf::class)->paginate(0, 10))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $orm->query(Shelf::class)->paginate(1, 0))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $orm->query(Shelf::class)->paginate(1, Page::MAX_PER_PAGE + 1))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $orm->query(Shelf::class)->limit(2)->paginate(1, 10))->toThrow(\LogicException::class);
    });
});

describe('cursorPaginate', function () {
    it('reads forward and back through every row, a page at a time', function () {
        $orm = Shelves::sqlite()->orm();
        $cursors = cursors();
        $query = $orm->query(Shelf::class)->orderBy('position', 'desc');
        $page = static fn (?string $cursor): CursorPage => acme($orm, static fn (): CursorPage => $query->cursorPaginate(2, $cursor, $cursors));

        $first = $page(null);
        $second = $page($first->next);
        $back = $page($second->previous);

        expect(names($first))->toBe(['Foxtrot', 'Charlie'])
            ->and($first->previous)->toBeNull()
            ->and(names($second))->toBe(['Bravo', 'Alpha'])
            ->and($second->next)->toBeNull()
            ->and(names($back))->toBe(['Foxtrot', 'Charlie'])
            ->and($back->previous)->toBeNull()
            ->and(names($page($back->next)))->toBe(['Bravo', 'Alpha']);
    });

    it('breaks ties by the key, so no row is read twice or skipped', function () {
        $fixture = Shelves::sqlite();
        $fixture->pdo->exec("UPDATE shelves SET position = 1 WHERE tenant_id = 'acme'");
        $orm = $fixture->orm();
        $cursors = cursors();
        $query = $orm->query(Shelf::class)->orderBy('position');

        $seen = [];
        $cursor = null;
        do {
            $page = acme($orm, static fn (): CursorPage => $query->cursorPaginate(1, $cursor, $cursors));
            array_push($seen, ...names($page));
            $cursor = $page->next;
        } while ($cursor !== null);

        expect($seen)->toHaveCount(4)->and(array_unique($seen))->toHaveCount(4);
    });

    it('reads from where the reader is, whatever was added before it', function () {
        $fixture = Shelves::sqlite();
        $orm = $fixture->orm();
        $cursors = cursors();
        $query = $orm->query(Shelf::class)->orderBy('position');

        $first = acme($orm, static fn (): CursorPage => $query->cursorPaginate(2, null, $cursors));
        $fixture->pdo->exec("INSERT INTO shelves VALUES ('" . \Tinvar\Support\Uuid::v7() . "', 'Zero', 0, NULL, 's', '{$fixture->ids['A1']}', 'acme')");

        expect(names(acme($orm, static fn (): CursorPage => $query->cursorPaginate(2, $first->next, $cursors))))->toBe(['Charlie', 'Foxtrot']);
    });

    it('never carries a row\'s values, only its key', function () {
        $fixture = Shelves::sqlite();
        $orm = $fixture->orm();
        $page = acme($orm, static fn (): CursorPage => $orm->query(Shelf::class)->orderBy('secret')->cursorPaginate(1, null, cursors()));

        $payload = (string) Signer::decode(explode('.', (string) $page->next)[0]);

        expect($payload)->toContain($fixture->ids['Bravo'])
            ->and($payload)->not->toContain('s-a')
            ->and($payload)->not->toContain('Bravo')
            ->and($payload)->not->toContain('session-a')
            // Nor the binding or the query as a hash a log could match a
            // session's cursors by: the MAC covers them, the cursor does not
            // carry them.
            ->and(array_keys((array) json_decode($payload, true)))->toBe(['a', 'f', 'e']);
    });

    it('refuses a cursor sealed for another reader, another query or another tenant', function () {
        $orm = Shelves::sqlite()->orm();
        $query = $orm->query(Shelf::class)->orderBy('position');
        $next = acme($orm, static fn (): ?string => $query->cursorPaginate(1, null, cursors())->next);
        \assert($next !== null);

        expect(fn () => acme($orm, static fn (): CursorPage => $query->cursorPaginate(1, $next, cursors('session-b'))))
            ->toThrow(InvalidCursor::class)
            ->and(fn () => acme($orm, static fn (): CursorPage => $query->cursorPaginate(1, $next, cursors(key: 'another-key'))))
            ->toThrow(InvalidCursor::class)
            ->and(fn () => acme($orm, static fn (): CursorPage => $query->where('position', '>', 0)->cursorPaginate(1, $next, cursors())))
            ->toThrow(InvalidCursor::class)
            ->and(fn () => acme($orm, static fn (): CursorPage => $orm->query(Shelf::class)->orderBy('name')->cursorPaginate(1, $next, cursors())))
            ->toThrow(InvalidCursor::class)
            // The query's fingerprint holds its tenant predicate's binding.
            ->and(fn () => $orm->actAs('globex', static fn (): CursorPage => $query->cursorPaginate(1, $next, cursors())))
            ->toThrow(InvalidCursor::class)
            ->and(fn () => $orm->acrossTenants(static fn (): CursorPage => $query->cursorPaginate(1, $next, cursors())))
            ->toThrow(InvalidCursor::class);
    });

    it('refuses a cursor changed, cut, expired or out of all proportion', function (\Closure $spoil) {
        $now = 1_000_000;
        $clock = static function () use (&$now): int {
            return $now;
        };
        $orm = Shelves::sqlite()->orm();
        $query = $orm->query(Shelf::class)->orderBy('position');
        $next = (string) acme($orm, static fn (): ?string => $query->cursorPaginate(1, null, cursors(clock: $clock))->next);

        $spoilt = $spoil($next, $now);

        expect(fn () => acme($orm, static fn (): CursorPage => $query->cursorPaginate(1, $spoilt, cursors(clock: $clock))))
            ->toThrow(InvalidCursor::class);
    })->with([
        'a payload byte changed' => [static fn (string $c): string => substr_replace($c, $c[3] === 'A' ? 'B' : 'A', 3, 1)],
        'the signature cut' => [static fn (string $c): string => explode('.', $c)[0]],
        'a third part' => [static fn (string $c): string => $c . '.x'],
        'expired' => [static function (string $c, int &$now): string {
            $now += SignedCursors::DEFAULT_TTL + 1;

            return $c;
        }],
        'too long' => [static fn (string $c): string => str_repeat('A', SignedCursors::MAX_LENGTH + 1)],
        'not base64' => [static fn (string $c): string => '!!.!!'],
        'another anchor, signed by nobody' => [static function (string $c): string {
            $payload = (string) Signer::decode(explode('.', $c)[0]);
            $forged = preg_replace('/"a":"[^"]+"/', '"a":"' . \Tinvar\Support\Uuid::v7() . '"', $payload);

            return Signer::encode((string) $forged) . '.' . explode('.', $c)[1];
        }],
    ]);

    it('refuses a cursor whose row the reader can no longer see', function () {
        $fixture = Shelves::sqlite();
        $orm = $fixture->orm();
        $cursors = cursors();
        $query = $orm->query(Shelf::class)->orderBy('position');
        $first = acme($orm, static fn (): CursorPage => $query->cursorPaginate(2, null, $cursors));

        $fixture->pdo->exec("UPDATE shelves SET tenant_id = 'globex' WHERE name = 'Bravo'");

        expect(fn () => acme($orm, static fn (): CursorPage => $query->cursorPaginate(2, $first->next, $cursors)))
            ->toThrow(InvalidCursor::class);
    });

    it('refuses to sort by a column that may be NULL, or one a join reaches', function () {
        $orm = Shelves::sqlite()->orm();

        expect(fn () => acme($orm, static fn (): CursorPage => $orm->query(Shelf::class)->orderBy('note')->cursorPaginate(2, null, cursors())))
            ->toThrow(\LogicException::class)
            ->and(fn () => acme($orm, static fn (): CursorPage => $orm->query(Shelf::class)->orderBy('aisle.code')->cursorPaginate(2, null, cursors())))
            ->toThrow(\LogicException::class);
    });

    it('fails closed with no tenant, before a cursor is opened', function () {
        $orm = Shelves::sqlite()->orm();
        $opened = false;
        $spy = new class ($opened) implements Cursors {
            public function __construct(private bool &$opened) {}

            public function seal(string $query, string $anchor, bool $after): string
            {
                return 'x';
            }

            public function open(string $query, string $cursor): ?array
            {
                $this->opened = true;

                return null;
            }
        };

        expect(fn () => $orm->query(Shelf::class)->cursorPaginate(2, 'anything', $spy))
            ->toThrow(\Tinvar\Orm\NoTenantContext::class)
            ->and($opened)->toBeFalse();
    });
});

describe('a signed cursor', function () {
    it('opens for the query and binding it was sealed for, and nothing else', function () {
        $cursors = cursors();
        $sealed = $cursors->seal('q', '019a0000-0000-7000-8000-000000000000', false);

        expect($cursors->open('q', $sealed))->toBe(['019a0000-0000-7000-8000-000000000000', false])
            ->and($cursors->open('q2', $sealed))->toBeNull()
            ->and(cursors('other')->open('q', $sealed))->toBeNull()
            ->and($cursors->open('q', 'not-a-cursor'))->toBeNull();
    });

    it('opens only for a key that is a key', function () {
        $cursors = cursors();

        expect($cursors->open('q', $cursors->seal('q', "x' OR '1'='1", true)))->toBeNull();
    });

    it('takes a lifetime of a second to a day', function () {
        expect(fn () => new SignedCursors(new Signer('k', SignedCursors::PURPOSE), 'b', 0))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => new SignedCursors(new Signer('k', SignedCursors::PURPOSE), 'b', 86_401))->toThrow(\InvalidArgumentException::class);
    });
});
