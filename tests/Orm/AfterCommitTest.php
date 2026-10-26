<?php

declare(strict_types=1);

/**
 * Orm::afterCommit(): what tells someone else about a write runs once the
 * write can be read by them -- after the outermost transaction commits --
 * and never for a write that was rolled back.
 */

namespace Tinvar\Tests\Orm\AfterCommit;

use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;

function orm(): Orm
{
    return new Orm(PdoConnection::sqlite(':memory:', 'test'), new IdentityMap('test'));
}

it('runs at once with no transaction open', function () {
    $ran = [];
    orm()->afterCommit(function () use (&$ran): void {
        $ran[] = 'now';
    });

    expect($ran)->toBe(['now']);
});

it('runs after the outermost commit, in the order asked, with nothing open by then', function () {
    $orm = orm();
    $ran = [];

    $orm->transaction(function () use ($orm, &$ran): void {
        $orm->afterCommit(function () use ($orm, &$ran): void {
            $ran[] = ['outer', $orm->connection()->inTransaction()];
        });
        $orm->transaction(function () use ($orm, &$ran): void {
            $orm->afterCommit(function () use ($orm, &$ran): void {
                $ran[] = ['inner', $orm->connection()->inTransaction()];
            });
        });
        expect($ran)->toBe([]);
    });

    expect($ran)->toBe([['outer', false], ['inner', false]]);
});

it('never runs for a transaction that rolls back, nor for a nested one that did inside a commit', function () {
    $orm = orm();
    $ran = [];

    try {
        $orm->transaction(function () use ($orm, &$ran): void {
            $orm->afterCommit(function () use (&$ran): void {
                $ran[] = 'rolled back';
            });

            throw new \RuntimeException('failed');
        });
    } catch (\RuntimeException) {
    }

    $orm->transaction(function () use ($orm, &$ran): void {
        try {
            $orm->transaction(function () use ($orm, &$ran): void {
                $orm->afterCommit(function () use (&$ran): void {
                    $ran[] = 'savepoint rolled back';
                });

                throw new \RuntimeException('inner failed');
            });
        } catch (\RuntimeException) {
        }
        $orm->afterCommit(function () use (&$ran): void {
            $ran[] = 'committed';
        });
    });

    expect($ran)->toBe(['committed']);
});

it('runs every callback even when one throws, and rethrows the first once they have', function () {
    $orm = orm();
    $ran = [];

    $thrown = null;
    try {
        $orm->transaction(function () use ($orm, &$ran): void {
            $orm->afterCommit(static function (): void {
                throw new \LogicException('first');
            });
            $orm->afterCommit(function () use (&$ran): void {
                $ran[] = 'second';
            });
        });
    } catch (\LogicException $e) {
        $thrown = $e->getMessage();
    }

    expect($thrown)->toBe('first')
        ->and($ran)->toBe(['second']);
});

// A transaction left open in a fiber nobody resumes -- abandoned when the
// request ended -- held its frame past the reset, so every afterCommit() of
// the next request on that worker waited on a commit that never came: the
// job never dispatched, the invalidation never sent.
it('starts the next request with no frame a request before left open', function () {
    $orm = orm();
    $ran = [];

    $fiber = new \Fiber(function () use ($orm): void {
        $orm->transaction(static function (): void {
            \Fiber::suspend();
        });
    });
    $fiber->start();
    $orm->flush();

    $orm->afterCommit(function () use (&$ran): void {
        $ran[] = 'next request';
    });

    expect($ran)->toBe(['next request']);
});
