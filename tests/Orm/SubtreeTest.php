<?php

declare(strict_types=1);

/**
 * A tenant hierarchy, and reads across a subtree of it.
 *
 * "Widening (a reseller's customers, all tenants for staff) comes from the
 * session's membership." withinSubtree() is the spec's withinReseller():
 * the rows of the tenant in force and every tenant below it, where a
 * membership granted it, and the tenant in force -- what every token and
 * every write is bound to -- unchanged throughout.
 */

namespace Tinvar\Tests\Orm\Subtree;

use Tinvar\Orm\MissingRow;
use Tinvar\Orm\Orm;
use Tinvar\Orm\TenantBounds;
use Tinvar\Orm\TenantSubtrees;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Tests\Orm\Fixtures\ScriptRun;
use Tinvar\Tests\Orm\Fixtures\Hierarchy\Tenants;

require_once __DIR__ . '/Fixtures/hierarchy.php';

/** @return list<string> every script the read in force reaches, by name */
function names(Orm $orm): array
{
    return array_map(static fn (Script $script): string => $script->name, $orm->all(Script::class, 'name'));
}

/**
 * $work as $tenant, granted reads across its subtree -- as a panel's
 * membership check grants them -- and inside them.
 *
 * @template T
 * @param \Closure(): T $work
 * @return T
 */
function across(Orm $orm, string $tenant, \Closure $work): mixed
{
    return $orm->actAs($tenant, static fn (): mixed => $orm->grantSubtree(static fn (): mixed => $orm->withinSubtree($work)));
}

describe('the tree', function () {
    // The Orm takes the tree through a port, and reads every answer through
    // its own final TenantBounds, so the limits hold whatever implementation
    // it is given (ADR 0056; TreeBoundsTest gives it ones that drop them,
    // and tinvar/tenancy's TenantHierarchy is tested with tenancy).
    it('is read by the Orm through its own checks, whatever implements the port', function () {
        $parameter = new \ReflectionMethod(Orm::class, '__construct')->getParameters()[2];
        $type = $parameter->getType();
        $orm = new Tenants()->orm();

        expect($parameter->getName())->toBe('tenants')
            ->and($type instanceof \ReflectionNamedType ? $type->getName() : null)->toBe(TenantSubtrees::class)
            ->and(new \ReflectionClass(TenantBounds::class)->isFinal())->toBeTrue()
            ->and(new \ReflectionMethod(Orm::class, 'tenants')->getReturnType()?->__toString())->toBe('?' . TenantBounds::class)
            ->and($orm->tenants())->toBeInstanceOf(TenantBounds::class)
            ->and($orm->tenants()?->contains('reseller', 'acme-west'))->toBeTrue()
            ->and($orm->tenants()?->contains('acme', 'globex'))->toBeFalse()
            ->and($orm->tenants()?->subtree('reseller'))->toBe(['reseller', 'acme', 'globex', 'acme-west']);
    });
});

describe('withinSubtree()', function () {
    it('reads the tenant in force and every tenant below it, and the tenant alone after', function () {
        $orm = new Tenants()->orm();

        expect(across($orm, 'reseller', static fn (): array => names($orm)))
            ->toBe(['acme-script', 'acme-west-script', 'globex-script', 'reseller-script'])
            ->and(across($orm, 'acme', static fn (): array => names($orm)))->toBe(['acme-script', 'acme-west-script'])
            ->and(across($orm, 'other', static fn (): array => names($orm)))->toBe(['other-script'])
            ->and($orm->actAs('reseller', static fn (): array => names($orm)))->toBe(['reseller-script']);
    });

    // What every token, job and write is bound to.
    it('leaves the tenant in force exactly as it was', function () {
        $orm = new Tenants()->orm();

        expect(across($orm, 'reseller', static fn (): array => [$orm->tenant(), $orm->subtreeInForce()]))
            ->toBe(['reseller', ['reseller', 'acme', 'globex', 'acme-west']]);
    });

    it('runs only where a membership granted it', function () {
        $orm = new Tenants()->orm();

        expect(fn () => $orm->actAs('reseller', static fn (): array => $orm->withinSubtree(static fn (): array => names($orm))))
            ->toThrow(\LogicException::class, 'only a membership grants')
            // Scoped: once the grant returns, it is gone.
            ->and(fn () => $orm->actAs('reseller', static function () use ($orm): array {
                $orm->grantSubtree(static fn (): bool => true);

                return $orm->withinSubtree(static fn (): array => names($orm));
            }))->toThrow(\LogicException::class, 'only a membership grants');
    });

    // The grant is a membership's, held in one tenant: from a tenant below
    // it, it reaches that tenant's subtree; from a tenant elsewhere, which
    // code may actAs() and read, it widens nothing.
    it('reaches only the subtree of the tenant the grant was made in', function () {
        $orm = new Tenants()->orm();
        $granted = static fn (string $tenant): array => $orm->actAs('reseller', static fn (): array => $orm->grantSubtree(
            static fn (): array => $orm->actAs($tenant, static fn (): array => $orm->withinSubtree(static fn (): array => names($orm))),
        ));

        expect($granted('acme'))->toBe(['acme-script', 'acme-west-script'])
            ->and(fn () => $granted('other'))->toThrow(\LogicException::class, "granted below 'reseller'")
            ->and($orm->grantSubtree(static fn (): bool => $orm->isSubtreeGranted()))->toBeFalse();
    });

    it('needs a tenant tree, and a tenant to read below', function () {
        $flat = new Tenants()->orm(null);
        $orm = new Tenants()->orm();

        expect(fn () => across($flat, 'reseller', static fn (): array => names($flat)))->toThrow(\LogicException::class, 'no tenant tree')
            // Granted with no tenant in force is granted nowhere.
            ->and(fn () => $orm->grantSubtree(static fn (): mixed => $orm->withinSubtree(static fn (): array => names($orm))))
            ->toThrow(\LogicException::class, 'only a membership grants');
    });

    it('narrows to the tenant actAs() names, and lifts entirely inside acrossTenants()', function () {
        $orm = new Tenants()->orm();

        expect(across($orm, 'reseller', static fn (): array => $orm->actAs('globex', static fn (): array => names($orm))))
            ->toBe(['globex-script'])
            ->and(across($orm, 'reseller', static fn (): array => $orm->acrossTenants(static fn (): array => names($orm))))
            ->toHaveCount(5)
            // Named inside acrossTenants(), it narrows it, as actAs() does.
            ->and($orm->actAs('acme', static fn (): array => $orm->acrossTenants(
                static fn (): array => $orm->grantSubtree(static fn (): array => $orm->withinSubtree(static fn (): array => names($orm))),
            )))->toBe(['acme-script', 'acme-west-script'])
            ->and($orm->isWidened())->toBeFalse();
    });

    // A child's row cached under the parent would answer a later read made
    // as the parent alone: the map is bypassed, in both directions.
    it('never serves a row read across the subtree to a read of the tenant alone', function () {
        $fixture = new Tenants();
        $orm = $fixture->orm();
        $acme = $fixture->scripts['acme'];

        $orm->actAs('reseller', static function () use ($orm, $acme): void {
            expect($orm->find(Script::class, $acme))->toBeNull();

            $read = $orm->grantSubtree(static fn (): ?Script => $orm->withinSubtree(static fn (): ?Script => $orm->find(Script::class, $acme)));

            expect($read?->name)->toBe('acme-script')
                ->and($orm->identityMap()->get(Script::class, $acme))->toBeNull()
                ->and($orm->find(Script::class, $acme))->toBeNull();
        });
    });

    it('reads a child\'s relations across the subtree, and the tenant\'s own alone after', function () {
        $fixture = new Tenants();
        $orm = $fixture->orm();

        $runs = across($orm, 'reseller', static fn (): array => array_map(
            static fn (ScriptRun $run): string => $run->status,
            $orm->find(Script::class, $fixture->scripts['acme-west'])?->runs->all() ?? [],
        ));
        $own = $orm->actAs('reseller', static fn (): array => array_map(
            static fn (ScriptRun $run): string => $run->status,
            $orm->find(Script::class, $fixture->scripts['reseller'])?->runs->all() ?? [],
        ));

        expect($runs)->toBe(['acme-west-run'])->and($own)->toBe(['reseller-run']);
    });

    // The tenant in force is what a write is stamped with: to write as the
    // child, actAs() it.
    it('updates and deletes a child\'s rows, and stamps a new row with the tenant in force', function () {
        $fixture = new Tenants();
        $orm = $fixture->orm();

        across($orm, 'reseller', static function () use ($orm, $fixture): void {
            $acme = $orm->find(Script::class, $fixture->scripts['acme']);
            \assert($acme instanceof Script);
            $acme->name = 'renamed-by-reseller';
            $orm->save($acme);

            $west = $orm->find(Script::class, $fixture->scripts['acme-west']);
            \assert($west instanceof Script);
            $orm->delete($west);

            $made = new Script();
            $made->name = 'made-across';
            $orm->save($made);
        });

        $rows = $fixture->connection->select('SELECT name, tenant_id FROM scripts ORDER BY name');

        expect(array_column($rows, 'tenant_id', 'name'))->toBe([
            'globex-script' => 'globex',
            'made-across' => 'reseller',
            'other-script' => 'other',
            'renamed-by-reseller' => 'acme',
            'reseller-script' => 'reseller',
        ]);
    });

    // Held after the block, a child's row is the child's: written as the
    // parent alone, the predicate refuses it.
    it('refuses a write to a child\'s row once the block is over', function () {
        $fixture = new Tenants();
        $orm = $fixture->orm();

        expect(fn () => $orm->actAs('reseller', static function () use ($orm, $fixture): void {
            $acme = $orm->grantSubtree(static fn (): ?Script => $orm->withinSubtree(
                static fn (): ?Script => $orm->find(Script::class, $fixture->scripts['acme']),
            ));
            \assert($acme instanceof Script);
            $acme->name = 'renamed-after';
            $orm->save($acme);
        }))->toThrow(MissingRow::class);
    });

    // Work set up inside the block -- a Lazy field, a ghost -- reads the
    // subtree it was set up in, wherever it is resolved.
    it('carries the subtree into work deferred from inside it', function () {
        $orm = new Tenants()->orm();

        $deferred = across($orm, 'acme', static fn (): \Closure => $orm->captureScope());

        expect($deferred(static fn (): array => names($orm)))->toBe(['acme-script', 'acme-west-script'])
            ->and($orm->tenant())->toBe('')
            ->and($orm->isWidened())->toBeFalse();
    });

    // A render's fibers each start at their tenant exactly; the grant, made
    // for the request, reaches them.
    it('widens only the fiber that asked, and lets every fiber of the request ask', function () {
        $orm = new Tenants()->orm();

        $seen = $orm->actAs('reseller', static fn (): array => $orm->grantSubtree(static function () use ($orm): array {
            $seen = [];
            $wide = new \Fiber(static function () use ($orm, &$seen): void {
                $orm->withinSubtree(static function () use ($orm, &$seen): void {
                    \Fiber::suspend();
                    $seen['wide'] = names($orm);
                });
            });
            $narrow = new \Fiber(static function () use ($orm, &$seen): void {
                $seen['narrow'] = names($orm);
            });

            $wide->start();
            $narrow->start();
            $seen['main'] = names($orm);
            $wide->resume();

            return $seen;
        }));

        expect($seen)->toBe([
            'narrow' => ['reseller-script'],
            'main' => ['reseller-script'],
            'wide' => ['acme-script', 'acme-west-script', 'globex-script', 'reseller-script'],
        ]);
    });

    it('is reset with the rest of the request', function () {
        $orm = new Tenants()->orm();

        $orm->grantSubtree(static function () use ($orm): void {
            $orm->flush();

            expect($orm->isSubtreeGranted())->toBeFalse();
        });

        expect($orm->isSubtreeGranted())->toBeFalse();
    });
});
