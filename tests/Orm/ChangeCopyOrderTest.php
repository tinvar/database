<?php

declare(strict_types=1);

/**
 * A Change::copy() reads the row as it is written: on every database, the
 * value the source column held before the UPDATE -- never what the same
 * UPDATE sets it to. MySQL evaluates a single-table UPDATE's assignments
 * left to right, each seeing those before it, where Postgres and SQLite read
 * every one from the old row; so a copy from a column the same update()
 * sets would store one thing on MySQL and another elsewhere. It is refused.
 */

namespace Tinvar\Tests\Orm\ChangeCopyOrder;

use Tinvar\Orm\Change;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Orm\Fixtures\Shelf;
use Tinvar\Tests\Orm\Fixtures\Shelves;

require_once __DIR__ . '/Fixtures/shelves.php';

function copiesWhatTheRowHeld(Orm $orm): void
{
    $acme = static fn (\Closure $work): mixed => $orm->actAs('acme', $work);
    $notes = static fn (): array => $acme(static fn (): array => $orm->query(Shelf::class)->where('name', 'like', 'Bravo%')->pluck('note'));

    // Either order: the copy would read the new name on MySQL alone.
    expect(fn () => $acme(static fn (): int => $orm->query(Shelf::class)->where('name', 'Bravo')
        ->update(['name' => 'Bravo 2', 'note' => Change::copy('name')])))
        ->toThrow(\InvalidArgumentException::class, 'sets as well')
        ->and(fn () => $acme(static fn (): int => $orm->query(Shelf::class)->where('name', 'Bravo')
            ->update(['note' => Change::copy('name'), 'name' => 'Bravo 2'])))
        ->toThrow(\InvalidArgumentException::class, 'sets as well')
        // Two copies: MySQL would copy the secret into the note.
        ->and(fn () => $acme(static fn (): int => $orm->query(Shelf::class)->where('name', 'Bravo')
            ->update(['name' => Change::copy('secret'), 'note' => Change::copy('name')])))
        ->toThrow(\InvalidArgumentException::class, 'sets as well')
        ->and($notes())->toBe([null])
        // A copy from a column nothing else sets is as it was.
        ->and($acme(static fn (): int => $orm->query(Shelf::class)->where('name', 'Bravo')
            ->update(['position' => Change::add(1), 'note' => Change::copy('name')])))->toBe(1)
        ->and($notes())->toBe(['Bravo']);
}

it('refuses a copy from a column the same update() sets, on SQLite', function () {
    copiesWhatTheRowHeld(Shelves::sqlite()->orm());
});

it('refuses it on Postgres', function () {
    Shelves::onPostgres(static function (Shelves $fixture, PdoConnection $connection): void {
        copiesWhatTheRowHeld($fixture->orm(connection: $connection));
    });
})->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

it('refuses it on MySQL, which would read the new value', function () {
    Shelves::onMySql(static function (Shelves $fixture, PdoConnection $connection): void {
        copiesWhatTheRowHeld($fixture->orm(connection: $connection));
    });
})->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');
