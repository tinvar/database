<?php

declare(strict_types=1);

use Tinvar\Orm\DanglingRelation;
use Tinvar\Support\Uuid;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\NoTenantContext;
use Tinvar\Orm\Orm;
use Tinvar\Orm\ReadOnlyViolation;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Tests\Orm\Fixtures\TenantFixture;

require_once __DIR__ . '/Fixtures/models.php';
require_once __DIR__ . '/Fixtures/schema.php';
require_once __DIR__ . '/Fixtures/tenant.php';
require_once __DIR__ . '/Fixtures/tenancy.php';

function tenantOrm(TenantFixture $fixture): Orm
{
    return new Orm($fixture->connection, new IdentityMap('tenant-test'), tenants: Tenancy::flat());
}

it('fails closed when a tenant-scoped model is read with no active tenant', function () {
    $orm = tenantOrm(new TenantFixture());

    expect(fn () => $orm->all(Script::class))->toThrow(NoTenantContext::class)
        ->and(fn () => $orm->find(Script::class, 'anything'))->toThrow(NoTenantContext::class);
});

it('returns only the active tenant\'s rows', function () {
    $fixture = new TenantFixture();
    $orm = tenantOrm($fixture);

    $acme = $orm->actAs('acme', fn (): array => array_map(
        static fn (Script $s): string => $s->name,
        $orm->all(Script::class),
    ));

    expect($acme)->toHaveCount(2)
        ->and($acme)->each->toStartWith('acme-');
});

// The important one: a valid id from another tenant must not resolve.
it('does not resolve an id belonging to another tenant', function () {
    $fixture = new TenantFixture();
    $orm = tenantOrm($fixture);
    $globexId = $fixture->scripts['globex'][0];

    $found = $orm->actAs('acme', fn (): ?object => $orm->find(Script::class, $globexId));

    expect($found)->toBeNull('a foreign id is simply not found');
});

it('scopes relation loads too', function () {
    $fixture = new TenantFixture();
    $orm = tenantOrm($fixture);

    $count = $orm->actAs('acme', function () use ($orm, $fixture): int {
        $script = $orm->find(Script::class, $fixture->scripts['acme'][0]);

        return $script->runs->count();
    });

    expect($count)->toBe(1);
});

it('restores the previous tenant after actAs, even when the body throws', function () {
    $orm = tenantOrm(new TenantFixture());

    $orm->actAs('acme', function () use ($orm): void {
        expect($orm->tenant())->toBe('acme');

        try {
            $orm->actAs('globex', static fn (): never => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
            // expected
        }

        expect($orm->tenant())->toBe('acme', 'the inner switch was undone');
    });

    expect($orm->tenant())->toBe('');
});

describe('acrossTenants', function () {
    it('lifts scoping only for its closure', function () {
        $fixture = new TenantFixture();
        $orm = tenantOrm($fixture);

        $all = $orm->acrossTenants(fn (): array => $orm->all(Script::class));
        expect($all)->toHaveCount(4);

        expect(fn () => $orm->all(Script::class))->toThrow(NoTenantContext::class);
    });

    it('restores scoping when the closure throws', function () {
        $orm = tenantOrm(new TenantFixture());

        try {
            $orm->acrossTenants(static fn (): never => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
            // expected
        }

        expect($orm->isUnscoped())->toBeFalse()
            ->and(fn () => $orm->all(Script::class))->toThrow(NoTenantContext::class);
    });

    it('clears the identity map on exit, so unscoped rows are not served later', function () {
        $fixture = new TenantFixture();
        $orm = tenantOrm($fixture);
        $globexId = $fixture->scripts['globex'][0];

        $orm->acrossTenants(fn (): ?object => $orm->find(Script::class, $globexId));

        $leaked = $orm->actAs('acme', fn (): ?object => $orm->find(Script::class, $globexId));

        expect($leaked)->toBeNull('the unscoped read did not survive into a scoped one');
    });

    // An explicit switch must win over an enclosing acrossTenants(), or
    // actAs() would name a tenant and still read every tenant's rows.
    it('re-applies scoping for an actAs nested inside it', function () {
        $fixture = new TenantFixture();
        $orm = tenantOrm($fixture);

        $names = $orm->acrossTenants(fn (): array => $orm->actAs('acme', fn (): array => array_map(
            static fn (Script $s): string => $s->name,
            $orm->all(Script::class),
        )));

        expect($names)->toHaveCount(2)->each->toStartWith('acme-');
    });

    it('restores the unscoped state after that nested actAs', function () {
        $orm = tenantOrm(new TenantFixture());

        $count = $orm->acrossTenants(function () use ($orm): int {
            $orm->actAs('acme', fn (): array => $orm->all(Script::class));

            // Back under acrossTenants: every tenant again.
            return \count($orm->all(Script::class));
        });

        expect($count)->toBe(4);
    });

    // "Context is fiber-local, so a suspended fiber can't leak a switch to
    // another." The render scheduler interleaves fibers in one tick.
    it('does not leak unscoped state from one fiber to another', function () {
        $orm = tenantOrm(new TenantFixture());

        $unscoped = new Fiber(function () use ($orm): bool {
            return $orm->acrossTenants(function () use ($orm): bool {
                Fiber::suspend();          // another fiber runs here

                return $orm->isUnscoped();
            });
        });

        $unscoped->start();

        // While that fiber sits inside acrossTenants, this context is scoped.
        expect($orm->isUnscoped())->toBeFalse();

        $scoped = new Fiber(static fn (): bool => $orm->isUnscoped());
        $scoped->start();
        expect($scoped->getReturn())->toBeFalse('a sibling fiber is unaffected');

        $unscoped->resume();
        expect($unscoped->getReturn())->toBeTrue('its own state survived the suspend');
    });
});

it('leaves a model with no TenantScoped attribute alone', function () {
    $fixture = new Tinvar\Tests\Orm\Fixtures\Fixture(racks: 2, unitsPerRack: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'), tenants: Tenancy::flat());

    expect($orm->all(Tinvar\Tests\Orm\Fixtures\Rack::class))->toHaveCount(2);
});

/**
 * "Context is fiber-local, so a suspended fiber can't leak a switch to
 * another." That held for the auth context and for acrossTenants() depth, but
 * NOT for the tenant itself: it was one shared value, mutated by actAs() while
 * the render scheduler interleaves fibers.
 *
 * Nothing could exploit it only because Orm::switchTenant() nulled the
 * scheduler, so no suspend point remained inside an actAs() that actually
 * changed tenant -- safety by accident, and it silently dropped the rest of
 * the render from Level 2 batching to Level 1.
 */
describe('the tenant is fiber-local', function () {
    it('does not move another fiber tenant when one suspends inside actAs', function () {
        $fixture = new TenantFixture();
        $orm = tenantOrm($fixture);

        $victimSaw = null;

        $victim = new Fiber(function () use ($orm, &$victimSaw): void {
            $orm->actAs('acme', function () use ($orm, &$victimSaw): void {
                \Fiber::suspend();
                $victimSaw = array_map(
                    static fn (Script $s): string => $s->name,
                    $orm->all(Script::class),
                );
            });
        });

        $attacker = new Fiber(function () use ($orm): void {
            $orm->actAs('globex', function (): void {
                \Fiber::suspend();
            });
        });

        // The victim must read while the attacker is still INSIDE its
        // actAs(). Letting the attacker finish first would hide the bug: its
        // own finally restores whatever tenant was live when it started.
        $victim->start();
        $attacker->start();
        $victim->resume();
        $attacker->resume();

        sort($victimSaw);

        expect($victimSaw)->toBe(['acme-script-1', 'acme-script-2']);
    });

    it('leaves the main context tenant alone while a fiber acts as another', function () {
        $fixture = new TenantFixture();
        $orm = tenantOrm($fixture);

        $orm->actAs('acme', function () use ($orm): void {
            $fiber = new Fiber(function () use ($orm): void {
                $orm->actAs('globex', function (): void {
                    \Fiber::suspend();
                });
            });

            $fiber->start();

            expect($orm->tenant())->toBe('acme', 'the fiber switch did not reach the caller');

            $fiber->resume();
        });
    });
});

/**
 * "Loaders and queries run in a read-only transaction. save(), job dispatch
 * and event dispatch throw inside them, because hovering a link runs them."
 *
 * Nothing implemented this: a loader or query body could write, and Phase 2's
 * prefetch-on-hover turns that into a mutation nobody chose.
 */
describe('read-only contexts', function () {
    it('refuses a write inside one', function () {
        $fixture = new TenantFixture();
        $orm = tenantOrm($fixture);

        $orm->actAs('acme', function () use ($orm): void {
            $script = $orm->all(Script::class)[0];

            expect(fn () => $orm->readOnly(function () use ($orm, $script): void {
                $script->name = 'renamed';
                $orm->save($script);
            }))->toThrow(ReadOnlyViolation::class, 'not allowed inside');
        });
    });

    it('refuses a delete inside one', function () {
        $fixture = new TenantFixture();
        $orm = tenantOrm($fixture);

        $orm->actAs('acme', function () use ($orm): void {
            $script = $orm->all(Script::class)[0];

            expect(fn () => $orm->readOnly(static fn (): mixed => $orm->delete($script)))
                ->toThrow(ReadOnlyViolation::class, 'delete()');
        });
    });

    // A transaction opened in a loader is a write by intent, even when
    // nothing inside it goes through save().
    it('refuses a transaction inside one', function () {
        $fixture = new TenantFixture();
        $orm = tenantOrm($fixture);

        expect(fn () => $orm->readOnly(static fn (): mixed => $orm->transaction(static fn (): bool => true)))
            ->toThrow(ReadOnlyViolation::class, 'transaction()');
    });

    it('allows writes again once it ends', function () {
        $fixture = new TenantFixture();
        $orm = tenantOrm($fixture);

        $orm->actAs('acme', function () use ($orm): void {
            $orm->readOnly(static fn (): bool => true);

            $script = $orm->all(Script::class)[0];
            $script->name = 'renamed';
            $orm->save($script);

            expect($orm->isReadOnly())->toBeFalse();
        });
    });

    // Same reasoning as the tenant and the unscoped depth: the scheduler
    // interleaves query bodies, so one fiber's read-only context must not
    // become everyone's -- nor leak out of the fiber that opened it.
    it('does not make another fiber read-only', function () {
        $fixture = new TenantFixture();
        $orm = tenantOrm($fixture);

        $other = null;

        $reader = new Fiber(function () use ($orm, &$other): void {
            $orm->readOnly(function () use ($orm, &$other): void {
                \Fiber::suspend();
                $other = $orm->isReadOnly();
            });
        });

        $reader->start();

        expect($orm->isReadOnly())->toBeFalse('the main context is untouched');

        $reader->resume();

        expect($other)->toBeTrue('and the fiber kept its own');
    });
});

/**
 * Making the tenant fiber-local let batching survive an actAs() inside a
 * render, which it never had before -- and that exposed the other half:
 * BatchScheduler::flush() runs in the MAIN context, not in the fiber that
 * awaited. Grouping the pending loads by a tenant-namespaced key was not
 * enough; the statement itself ran under the main tenant.
 */
describe('batched relation loads run in the awaiting fiber tenant', function () {
    it('does not run the batch under the main context tenant', function () {
        $fixture = new TenantFixture();
        $orm = tenantOrm($fixture);
        $orm->beginRender();

        try {
            $out = $orm->scheduler()->run([
                'a' => static function () use ($orm): mixed {
                    return $orm->actAs('acme', static function () use ($orm): int {
                        return \count($orm->all(Script::class)[0]->runs->items);
                    });
                },
            ]);
        } finally {
            $orm->endRender();
        }

        // The main tenant is '', so a batch flushed there threw
        // NoTenantContext -- out of the scheduler, killing the whole tick
        // rather than failing one call.
        expect($out['a'])->toBe(1);
    });

    it('gives two fibers in different tenants their own rows', function () {
        $fixture = new TenantFixture();
        $orm = tenantOrm($fixture);
        $orm->switchTenant('acme');
        $orm->beginRender();

        try {
            $out = $orm->scheduler()->run([
                'acme' => static fn (): mixed => $orm->actAs('acme', static fn (): array => array_map(
                    static fn (object $r): string => $r->status,
                    $orm->all(Script::class)[0]->runs->items,
                )),
                'globex' => static fn (): mixed => $orm->actAs('globex', static fn (): array => array_map(
                    static fn (object $r): string => $r->status,
                    $orm->all(Script::class)[0]->runs->items,
                )),
            ]);
        } finally {
            $orm->endRender();
        }

        // globex used to come back empty: its group was never loaded, and
        // resolveHasMany read straight through a cache miss to [].
        expect($out['acme'])->toBe(['ok'])
            ->and($out['globex'])->toBe(['ok']);
    });
});

/**
 * acrossTenants() is the escape hatch the spec requires to be explicit. It
 * answered [] for every relation: loadRelationRows() refuses to CACHE an
 * unscoped read -- correctly, since those rows carry no tenant predicate and
 * must never be served to a later scoped read -- but both resolvers read
 * their result back out of that same cache, so refusing to cache meant
 * refusing to answer.
 */
describe('relations inside acrossTenants', function () {
    it('answers a hasMany across every tenant', function () {
        $fixture = new TenantFixture();
        $orm = tenantOrm($fixture);

        $total = $orm->acrossTenants(static function () use ($orm): int {
            $sum = 0;
            foreach ($orm->all(Script::class) as $script) {
                $sum += $script->runs->count();
            }

            return $sum;
        });

        expect($total)->toBe(4, 'two tenants, two scripts each, one run each');
    });

    // The rows an unscoped read returned must not survive into a scoped one.
    it('does not leave unscoped relation rows behind for a scoped read', function () {
        $fixture = new TenantFixture();
        $orm = tenantOrm($fixture);

        $orm->acrossTenants(static function () use ($orm): void {
            foreach ($orm->all(Script::class) as $script) {
                $script->runs->count();
            }
        });

        $scoped = $orm->actAs('acme', static function () use ($orm): int {
            $sum = 0;
            foreach ($orm->all(Script::class) as $script) {
                $sum += $script->runs->count();
            }

            return $sum;
        });

        expect($scoped)->toBe(2, 'acme only, not the four the unscoped read saw');
    });
});

/**
 * A #[BelongsTo] whose key names no readable row used to leave the object
 * marked initialized, holding nothing, and never retrying -- so reading a
 * field raised PHP's own "must not be accessed before initialization" Error
 * from a getter, with no mention of the relation, the key or the scope.
 */
it('names a dangling relation instead of leaving a hollow object', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'), tenants: Tenancy::flat());

    $fixture->connection->execute(
        'INSERT INTO racks (id, name, height_u, site_id) VALUES (?, ?, ?, ?)',
        [Uuid::v7(), 'R-DANGLING', 42, Uuid::v7()],
    );

    $dangling = null;
    foreach ($orm->all(Rack::class) as $rack) {
        if ($rack->name === 'R-DANGLING') {
            $dangling = $rack;
        }
    }

    expect(static fn (): string => $dangling->site->code)
        ->toThrow(DanglingRelation::class, 'which no scoped read returns');
});

// What an action wrote as another tenant it wrote there, and that tenant's
// pages are the ones its tags must reach (ActionRunner).
it('names every tenant a piece of work ran as, the one in force first', function () {
    $orm = tenantOrm(new TenantFixture());

    [$result, $tenants] = $orm->actAs('acme', fn (): array => $orm->tenantsEnteredBy(function () use ($orm): string {
        $orm->actAs('42', fn (): null => null);
        $orm->actAs('globex', fn (): string => $orm->actAs('42', fn (): string => 'done'));

        return 'returned';
    }));
    [, $none] = $orm->actAs('acme', fn (): array => $orm->tenantsEnteredBy(fn (): null => null));

    expect($result)->toBe('returned')
        ->and($tenants)->toBe(['acme', '42', 'globex'], 'a tenant id of digits stays a string')
        ->and($none)->toBe(['acme']);
});

it('keeps an outer piece of work\'s tenants through an inner one, and forgets them at the boundary', function () {
    $orm = tenantOrm(new TenantFixture());

    [[, $inner], $outer] = $orm->actAs('acme', fn (): array => $orm->tenantsEnteredBy(
        fn (): array => $orm->tenantsEnteredBy(fn (): null => $orm->actAs('globex', fn (): null => null)),
    ));
    [, $flushed] = $orm->actAs('acme', fn (): array => $orm->tenantsEnteredBy(function () use ($orm): void {
        $orm->actAs('globex', fn (): null => null);
        $orm->flush();
    }));

    expect($inner)->toBe(['acme', 'globex'])
        ->and($outer)->toBe(['acme', 'globex'])
        ->and($flushed)->toBe([''], 'the request ended under it, and no tenant is in force');
});
