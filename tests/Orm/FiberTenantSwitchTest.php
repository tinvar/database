<?php

declare(strict_types=1);

/**
 * A tenant switch made for one fiber does not reset the rows other fibers hold.
 *
 * flush() re-entered each awaiting fiber's tenant with actAs() from the main
 * context, and the switch back reset the main tenant: its identity-map
 * buckets and relation rows were dropped mid-render, and a fiber that looked
 * up a row it already held was handed a second object for it. An actAs()
 * exiting inside a fiber did the same. "The same row is the same object,
 * which lazy batching depends on."
 */

namespace Tinvar\Tests\Orm\FiberTenantSwitch;

use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Tests\Orm\Fixtures\TenantFixture;

require_once __DIR__ . '/Fixtures/tenant.php';

it('keeps the main tenant\'s rows through a flush that loads for another tenant', function () {
    $fixture = new TenantFixture();
    $orm = new Orm($fixture->connection, new IdentityMap('tenant-test'));
    $orm->switchTenant('acme');
    $acme = $fixture->scripts['acme'][0];
    $globex = $fixture->scripts['globex'][0];

    $results = $orm->beginRender()->run([
        'acme' => static function () use ($orm, $acme): bool {
            $before = $orm->find(Script::class, $acme) ?? throw new \LogicException('no script');
            $before->runs->count();   // awaits, so the flush runs in between

            return $orm->find(Script::class, $acme) === $before;
        },
        'globex' => static fn (): int => $orm->actAs(
            'globex',
            static fn (): int => $orm->find(Script::class, $globex)?->runs->count() ?? -1,
        ),
    ]);

    expect($results)->toBe(['acme' => true, 'globex' => 1])
        ->and($fixture->connection->statementCount())
        ->toBe(4, 'two scripts and one load of runs per tenant, nothing read twice');
});

it('keeps the main tenant\'s rows when an actAs() inside a fiber exits', function () {
    $fixture = new TenantFixture();
    $orm = new Orm($fixture->connection, new IdentityMap('tenant-test'));
    $orm->switchTenant('acme');
    $acme = $fixture->scripts['acme'][0];
    $globex = $fixture->scripts['globex'][0];

    $results = $orm->beginRender()->run([
        'acme' => static function () use ($orm, $acme): bool {
            $before = $orm->find(Script::class, $acme) ?? throw new \LogicException('no script');
            $before->runs->count();   // suspends while the other fiber runs

            return $orm->find(Script::class, $acme) === $before;
        },
        // Enters globex and leaves it again before the first fiber resumes.
        'globex' => static fn (): string => $orm->actAs(
            'globex',
            static fn (): string => $orm->find(Script::class, $globex)?->name ?? 'none',
        ),
    ]);

    expect($results['acme'])->toBeTrue()
        ->and($results['globex'])->toBe('globex-script-1');
});

it('still resets the entered tenant on a switch the main context makes', function () {
    $fixture = new TenantFixture();
    $orm = new Orm($fixture->connection, new IdentityMap('tenant-test'));
    $orm->switchTenant('acme');
    $id = $fixture->scripts['acme'][0];
    $before = $orm->find(Script::class, $id);

    $orm->actAs('globex', static fn (): mixed => null);
    $fixture->connection->resetStatementCount();

    expect($orm->find(Script::class, $id))->not->toBe($before)
        ->and($fixture->connection->statementCount())->toBe(1, 'read again, not served from before the switch');
});
