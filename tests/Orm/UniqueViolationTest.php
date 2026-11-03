<?php

declare(strict_types=1);

/**
 * A unique index's refusal, told apart from every other failure on each
 * database: what a store that writes first and says "taken" after reads.
 */

namespace Tinvar\Tests\Orm\UniqueViolation;

use Tinvar\Orm\UniqueViolation;
use Tinvar\Tests\Orm\Fixtures\Databases;

require_once __DIR__ . '/Fixtures/databases.php';

/** @return array{?\PDOException, ?\PDOException} the duplicate's failure, and a NOT NULL's */
function failures(\PDO $pdo): array
{
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE slugs (slug VARCHAR(63) NOT NULL UNIQUE, note VARCHAR(10) NOT NULL)');
    $pdo->exec("INSERT INTO slugs (slug, note) VALUES ('acme', 'x')");
    $caught = [];
    foreach (["INSERT INTO slugs (slug, note) VALUES ('acme', 'y')", "INSERT INTO slugs (slug, note) VALUES ('globex', NULL)"] as $sql) {
        try {
            $pdo->exec($sql);
            $caught[] = null;
        } catch (\PDOException $e) {
            $caught[] = $e;
        }
    }

    return [$caught[0], $caught[1]];
}

it('knows a duplicate from any other refusal on SQLite', function () {
    [$duplicate, $notNull] = failures(new \PDO('sqlite::memory:'));

    expect(UniqueViolation::is($duplicate ?? new \LogicException()))->toBeTrue()
        ->and(UniqueViolation::is($notNull ?? new \LogicException()))->toBeFalse()
        ->and(UniqueViolation::is(new \RuntimeException('UNIQUE constraint failed')))->toBeFalse();
});

it('knows a duplicate from any other refusal on Postgres', function () {
    Databases::inScratchSchema('pgsql', static function (\PDO $pdo): void {
        [$duplicate, $notNull] = failures($pdo);

        expect(UniqueViolation::is($duplicate ?? new \LogicException()))->toBeTrue()
            ->and(UniqueViolation::is($notNull ?? new \LogicException()))->toBeFalse();
    });
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

it('knows a duplicate from any other refusal on MySQL', function () {
    Databases::inScratchSchema('mysql', static function (\PDO $pdo): void {
        [$duplicate, $notNull] = failures($pdo);

        expect(UniqueViolation::is($duplicate ?? new \LogicException()))->toBeTrue()
            ->and(UniqueViolation::is($notNull ?? new \LogicException()))->toBeFalse();
    });
})->group('mysql')->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');
