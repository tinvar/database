<?php

declare(strict_types=1);

/**
 * The limits of a walk of the tenant tree, held by the Orm itself on what
 * the port it was given returns.
 *
 * The Orm takes the tree through an interface (TenantSubtrees), which any
 * application can implement (ADR 0056). Each port here drops a limit a real
 * walk keeps -- it loops, runs deeper than 32 levels, answers with more than
 * 1,000 tenants or with things that are no tenant's name -- and the Orm
 * still answers as the bounded walk would: "not under" past the cut, and a
 * refusal rather than part of a subtree. Through Orm::tenants(), which the
 * code above the Orm asks, and through withinSubtree(), which reads.
 */

namespace Tinvar\Tests\Orm\TreeBounds;

use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\TenantBounds;
use Tinvar\Orm\TenantSubtrees;
use Tinvar\Tests\Orm\Fixtures\Hierarchy\Tenants;
use Tinvar\Tests\Orm\Fixtures\Script;

require_once __DIR__ . '/Fixtures/hierarchy.php';

/** A port that answers whatever it is told to, limits or no limits. */
final readonly class Answers implements TenantSubtrees
{
    /**
     * @param \Closure(string): list<mixed> $ancestors
     * @param \Closure(string): list<mixed> $subtree
     */
    public function __construct(private \Closure $ancestors, private \Closure $subtree) {}

    public function hierarchical(): bool
    {
        return true;
    }

    /** @return list<string> */
    public function ancestors(string $tenant): array
    {
        return ($this->ancestors)($tenant);
    }

    /** @return list<string> */
    public function subtree(string $root): array
    {
        return ($this->subtree)($root);
    }
}

/** A chain of $levels tenants above 'leaf': level-1 its parent, level-$levels the top. */
function chain(int $levels): Answers
{
    return new Answers(
        static fn (string $t): array => $t === 'leaf' ? array_map(static fn (int $i): string => "level-{$i}", range(1, $levels)) : [],
        static fn (string $root): array => [$root],
    );
}

function bounds(TenantSubtrees $port): TenantBounds
{
    return new Orm(new Tenants()->connection, tenants: $port)->tenants() ?? throw new \LogicException('No tree.');
}

describe('a walk up', function () {
    it('places a tenant under no more than 32 levels above it', function () {
        $deep = bounds(chain(40));

        expect($deep->contains('level-' . TenantBounds::MAX_DEPTH, 'leaf'))->toBeTrue()
            ->and($deep->contains('level-' . (TenantBounds::MAX_DEPTH + 1), 'leaf'))->toBeFalse()
            ->and($deep->contains('level-40', 'leaf'))->toBeFalse()
            ->and($deep->ancestors('leaf'))->toHaveCount(TenantBounds::MAX_DEPTH);
    });

    it('stops at a loop, and at the tenant itself, keeping what came before', function () {
        $loop = bounds(new Answers(
            static fn (string $t): array => $t === 'a' ? ['b', 'c', 'b', 'root'] : ($t === 'x' ? ['y', 'x', 'root'] : []),
            static fn (string $root): array => [$root],
        ));

        expect($loop->ancestors('a'))->toBe(['b', 'c'])
            ->and($loop->contains('c', 'a'))->toBeTrue()
            ->and($loop->contains('root', 'a'))->toBeFalse()
            ->and($loop->ancestors('x'))->toBe(['y'])
            ->and($loop->contains('root', 'x'))->toBeFalse();
    });

    it('stops at anything that is no tenant\'s name', function () {
        $odd = bounds(new Answers(
            static fn (string $t): array => match ($t) {
                'a' => ['b', '', 'root'],
                'n' => ['b', "root\0", 'root'],
                'i' => ['b', 7, 'root'],
                default => ['b', null, 'root'],
            },
            static fn (string $root): array => [$root],
        ));

        foreach (['a', 'n', 'i', 'z'] as $tenant) {
            expect($odd->ancestors($tenant))->toBe(['b'])
                ->and($odd->contains('root', $tenant))->toBeFalse();
        }
    });

    it('places no one under the empty tenant, and the empty tenant under no one', function () {
        $any = bounds(new Answers(static fn (): array => [''], static fn (string $root): array => [$root]));

        expect($any->contains('', 'a'))->toBeFalse()
            ->and($any->contains('a', ''))->toBeFalse()
            ->and($any->ancestors(''))->toBe([]);
    });
});

describe('a subtree', function () {
    it('is refused past 1,000 tenants, root included, rather than read in part', function () {
        $wide = static fn (int $n): TenantBounds => bounds(new Answers(
            static fn (): array => [],
            static fn (string $root): array => [$root, ...array_map(static fn (int $i): string => "t{$i}", range(1, $n))],
        ));

        expect($wide(TenantBounds::MAX_SUBTREE - 1)->subtree('root'))->toHaveCount(TenantBounds::MAX_SUBTREE)
            ->and(fn () => $wide(TenantBounds::MAX_SUBTREE)->subtree('root'))->toThrow(\LengthException::class, 'more than the 1000');
    });

    it('puts its root first whatever the port said, each tenant once', function () {
        $shuffled = bounds(new Answers(static fn (): array => [], static fn (): array => ['b', 'root', '', 'b', 'a']));
        $rootless = bounds(new Answers(static fn (): array => [], static fn (): array => []));

        expect($shuffled->subtree('root'))->toBe(['root', 'b', 'a'])
            ->and($rootless->subtree('root'))->toBe(['root']);
    });

    it('is refused whole when the port names something that is no tenant', function () {
        foreach ([[7], [null], ["b\0"], ["b\x7f"], [['nested']]] as $odd) {
            $port = bounds(new Answers(static fn (): array => [], static fn (string $root): array => [$root, 'a', ...$odd]));

            expect(fn () => $port->subtree('root'))->toThrow(\UnexpectedValueException::class, 'no tenant');
        }
    });

    it('has a root', function () {
        expect(fn () => bounds(chain(1))->subtree(''))->toThrow(\InvalidArgumentException::class);
    });
});

describe('the Orm', function () {
    // What the code above it asks is what its reads use: one checked tree.
    it('hands the code above it the checked tree, never the port', function () {
        $port = chain(40);
        $orm = new Orm(new Tenants()->connection, tenants: $port);

        expect($orm->tenants())->toBeInstanceOf(TenantBounds::class)
            ->and($orm->tenants())->not->toBe($port)
            ->and($orm->tenants()?->contains('level-40', 'leaf'))->toBeFalse();
    });

    it('reads across a subtree only as far as the checked tree reaches', function () {
        $tenants = new Tenants();
        // Every tenant claimed to be in reseller's subtree, and 'other' to be
        // above acme -- but past a loop, which the Orm stops at.
        $port = new Answers(
            static fn (string $t): array => $t === 'acme' ? ['acme-west', 'acme', 'reseller'] : [],
            static fn (string $root): array => $root === 'reseller' ? ['reseller', 'acme', 'globex', 'acme-west'] : [$root],
        );
        $orm = new Orm($tenants->connection, new IdentityMap('bounds'), $port);
        $names = static fn (): array => array_map(static fn (Script $s): string => $s->name, $orm->all(Script::class, 'name'));

        // Granted in reseller, read from acme: acme is under reseller only
        // past the loop, so the grant does not reach it.
        expect(fn () => $orm->actAs('reseller', static fn () => $orm->grantSubtree(
            static fn () => $orm->actAs('acme', static fn () => $orm->withinSubtree($names)),
        )))->toThrow(\LogicException::class, 'not in its subtree');

        // From reseller itself, the subtree it answered, which is the port's
        // to answer truthfully (its contract), within the 1,000.
        expect($orm->actAs('reseller', static fn (): array => $orm->grantSubtree(static fn (): array => $orm->withinSubtree($names))))
            ->toBe(['acme-script', 'acme-west-script', 'globex-script', 'reseller-script']);
    });

    it('refuses a read across a subtree larger than a read can carry', function () {
        $tenants = new Tenants();
        $port = new Answers(
            static fn (): array => [],
            static fn (string $root): array => [$root, ...array_map(static fn (int $i): string => "t{$i}", range(1, TenantBounds::MAX_SUBTREE))],
        );
        $orm = new Orm($tenants->connection, new IdentityMap('bounds'), $port);

        expect(fn () => $orm->actAs('reseller', static fn () => $orm->grantSubtree(static fn () => $orm->withinSubtree(
            static fn (): array => $orm->all(Script::class),
        ))))->toThrow(\LengthException::class);
    });

    it('takes tenants side by side as no tree at all', function () {
        $flat = new readonly class implements TenantSubtrees {
            public function hierarchical(): bool
            {
                return false;
            }

            public function ancestors(string $tenant): array
            {
                return ['everyone'];
            }

            public function subtree(string $root): array
            {
                return [$root, 'everyone-else'];
            }
        };
        $orm = new Orm(new Tenants()->connection, tenants: $flat);

        expect($orm->hasTenancy())->toBeTrue()
            ->and($orm->tenants())->toBeNull()
            ->and(fn () => $orm->actAs('reseller', static fn () => $orm->grantSubtree(static fn () => $orm->withinSubtree(static fn () => null))))
            ->toThrow(\LogicException::class, 'no tenant tree');
    });
});
