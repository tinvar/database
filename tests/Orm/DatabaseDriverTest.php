<?php

declare(strict_types=1);

/** Which database an application keeps its rows in (ADR 0157). */

use Tinvar\Orm\DatabaseDriver;

it('writes each server\'s DSN with no credentials, and its extension and port', function () {
    expect(DatabaseDriver::Postgres->dsn('db', null, 'shop'))->toBe('pgsql:host=db;port=5432;dbname=shop')
        ->and(DatabaseDriver::Mysql->dsn('db', 3307, 'shop'))->toBe('mysql:host=db;port=3307;dbname=shop;charset=utf8mb4')
        ->and(DatabaseDriver::Postgres->extension())->toBe('pdo_pgsql')
        ->and(DatabaseDriver::Sqlite->isServer())->toBeFalse()
        ->and(DatabaseDriver::from('mysql')->title())->toBe('MySQL');
});

it('refuses a host or name that would add to the DSN', function () {
    DatabaseDriver::Postgres->dsn('db;user=root', null, 'shop');
})->throws(InvalidArgumentException::class);

it('opens SQLite by its path, never a DSN', function () {
    DatabaseDriver::Sqlite->dsn('db', null, 'shop');
})->throws(LogicException::class);
