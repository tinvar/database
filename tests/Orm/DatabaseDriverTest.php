<?php

declare(strict_types=1);

/**
 * Which database an application keeps its rows in (ADR 0157), and the
 * connection PdoConnection::server() opens to a server's: one the ORM's SQL
 * runs on as it is written (ADR 0169). The MySQL half runs in a scratch
 * database of its own, and is skipped, saying why, with no server.
 */

namespace Tinvar\Tests\Orm\DatabaseDriver;

use Tinvar\Orm\DatabaseDriver;
use Tinvar\Orm\PdoConnection;
use Tinvar\Tests\Orm\Fixtures\Databases;

require_once __DIR__ . '/Fixtures/databases.php';

it('writes each server\'s DSN with no credentials, and its extension and port', function () {
    expect(DatabaseDriver::Postgres->dsn('db', null, 'shop'))->toBe('pgsql:host=db;port=5432;dbname=shop')
        ->and(DatabaseDriver::Mysql->dsn('db', 3307, 'shop'))->toBe('mysql:host=db;port=3307;dbname=shop;charset=utf8mb4')
        ->and(DatabaseDriver::Postgres->extension())->toBe('pdo_pgsql')
        ->and(DatabaseDriver::Sqlite->isServer())->toBeFalse()
        ->and(DatabaseDriver::from('mysql')->title())->toBe('MySQL');
});

it('refuses a host or name that would add to the DSN', function () {
    DatabaseDriver::Postgres->dsn('db;user=root', null, 'shop');
})->throws(\InvalidArgumentException::class);

it('opens SQLite by its path, never a DSN', function () {
    DatabaseDriver::Sqlite->dsn('db', null, 'shop');
})->throws(\LogicException::class);

/**
 * The host, port, user and password of a test server's DSN, as an
 * application's config holds them apart.
 *
 * @return array{string, ?int, ?string, ?string}
 */
function serverOf(string $dsn): array
{
    $pairs = [];
    foreach (explode(';', substr($dsn, (int) strpos($dsn, ':') + 1)) as $pair) {
        [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
        $pairs[trim($key)] = trim($value);
    }

    return [$pairs['host'] ?? 'localhost', isset($pairs['port']) ? (int) $pairs['port'] : null, $pairs['user'] ?? null, $pairs['password'] ?? null];
}

it('opens a MySQL server\'s database reading double quotes as names, as the ORM writes them, the server\'s own modes kept', function () {
    [$host, $port, $user, $password] = serverOf(Databases::dsn('mysql'));

    Databases::inScratchSchema('mysql', static function (\PDO $pdo, string $database) use ($host, $port, $user, $password): void {
        $connection = PdoConnection::server(DatabaseDriver::Mysql, $host, $port, $database, $user, $password, 'app');
        // Every statement the ORM and its query builder send names its
        // tables and columns so, and MySQL reads "shelves" as a string
        // unless ANSI_QUOTES is on: an app made with --database=mysql had
        // its first one refused.
        $connection->execute('CREATE TABLE "shelves" ("id" INT PRIMARY KEY, "name" VARCHAR(20))');
        $connection->execute('INSERT INTO "shelves" ("id", "name") VALUES (?, ?)', [1, 'oak']);
        $modes = explode(',', (string) $connection->select('SELECT @@SESSION.sql_mode AS "modes"')[0]['modes']);
        // What every new session starts with -- not the shared test
        // connection's own, which a test before this one may have set.
        $servers = array_filter(explode(',', (string) $connection->select('SELECT @@GLOBAL.sql_mode AS "modes"')[0]['modes']));

        expect($connection->select('SELECT "shelves"."name" FROM "shelves" WHERE "shelves"."id" = ?', [1]))->toBe([['name' => 'oak']])
            ->and($modes)->toContain('ANSI_QUOTES')
            // Added to the server's own modes, never in their place: a strict
            // server stays strict.
            ->and(array_values(array_diff($servers, $modes)))->toBe([]);
    });
})->group('mysql')->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');

// pdo_mysql runs a string of statements sent by exec() or query() unless
// told otherwise, so one injected `;` there ran a statement of its own. A
// server() connection opens with multiple statements off (ADR 0208).
it('opens a MySQL server\'s database refusing more than one statement at a time', function () {
    [$host, $port, $user, $password] = serverOf(Databases::dsn('mysql'));

    Databases::inScratchSchema('mysql', static function (\PDO $pdo, string $database) use ($host, $port, $user, $password): void {
        $connection = PdoConnection::server(DatabaseDriver::Mysql, $host, $port, $database, $user, $password, 'app');
        $inner = new \ReflectionProperty(PdoConnection::class, 'pdo')->getValue($connection);
        \assert($inner instanceof \PDO);

        expect(static fn () => $inner->exec('CREATE TABLE "a" ("id" INT); CREATE TABLE "b" ("id" INT)'))->toThrow(\PDOException::class)
            ->and($connection->select('SELECT COUNT(*) AS "n" FROM information_schema.tables WHERE table_schema = ?', [$database])[0]['n'])
            ->toEqual(0);
    });
})->group('mysql')->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');
