<?php

declare(strict_types=1);

namespace Tinvar\Tests\Orm\Fixtures;

use Tinvar\Orm\AsyncConnection;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Connection;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Support\Uuid;

require_once __DIR__ . '/databases.php';

/*
 * A table in each database's own types: a uuid key and a boolean on
 * Postgres, which compares neither with text -- what the shelves, kept to
 * plain types so one schema serves all three, never exercise.
 */

#[Table('flags')]
final class Flag extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;
    #[Column] public bool $done;
}

final class Flags
{
    /** @var array<string, string> by name, every row's id, in the order they were made */
    public array $ids = [];

    public function __construct(public readonly \PDO $pdo, string $driver)
    {
        $pdo->exec(match ($driver) {
            'pgsql' => 'CREATE TABLE flags (id UUID PRIMARY KEY, name VARCHAR(64) NOT NULL, done BOOLEAN NOT NULL)',
            'mysql' => 'CREATE TABLE flags (id CHAR(36) PRIMARY KEY, name VARCHAR(64) NOT NULL, done BOOLEAN NOT NULL)',
            default => 'CREATE TABLE flags (id TEXT PRIMARY KEY, name TEXT NOT NULL, done INTEGER NOT NULL)',
        });

        foreach (['a' => false, 'b' => false, 'c' => true, 'd' => true] as $name => $done) {
            $id = Uuid::v7();
            $this->ids[$name] = $id;
            $insert = $pdo->prepare('INSERT INTO flags VALUES (?, ?, ?)');
            $insert->bindValue(1, $id);
            $insert->bindValue(2, $name);
            $insert->bindValue(3, $done, \PDO::PARAM_BOOL);
            $insert->execute();
        }
    }

    public function orm(Connection $connection): Orm
    {
        return new Orm($connection, new IdentityMap('test'));
    }

    public static function sqlite(): Orm
    {
        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        return new self($pdo, 'sqlite')->orm(new PdoConnection($pdo, 'test'));
    }

    /** @param \Closure(Orm): void $test */
    public static function onPostgres(\Closure $test): void
    {
        Databases::inScratchSchema('pgsql', static function (\PDO $pdo) use ($test): void {
            $test(new self($pdo, 'pgsql')->orm(new PdoConnection($pdo, 'pgsql')));
        });
    }

    /** @param \Closure(Orm): void $test */
    public static function onPostgresAsync(\Closure $test): void
    {
        Databases::inScratchSchema('pgsql', static function (\PDO $pdo, string $schema) use ($test): void {
            $flags = new self($pdo, 'pgsql');
            $connection = AsyncConnection::postgres(Databases::asyncPostgres("-c search_path={$schema}"), 'pgsql-async');
            try {
                $test($flags->orm($connection));
            } finally {
                $connection->close();
            }
        });
    }

    /** @param \Closure(Orm): void $test */
    public static function onMySql(\Closure $test): void
    {
        Databases::inScratchSchema('mysql', static function (\PDO $pdo) use ($test): void {
            $pdo->exec("SET SESSION sql_mode = CONCAT(@@sql_mode, ',ANSI_QUOTES')");
            $test(new self($pdo, 'mysql')->orm(new PdoConnection($pdo, 'mysql')));
        });
    }

    /** @param \Closure(Orm): void $test */
    public static function onMySqlAsync(\Closure $test): void
    {
        Databases::inScratchSchema('mysql', static function (\PDO $pdo, string $database) use ($test): void {
            $pdo->exec("SET SESSION sql_mode = CONCAT(@@sql_mode, ',ANSI_QUOTES')");
            $flags = new self($pdo, 'mysql');
            $mode = $pdo->query('SELECT @@SESSION.sql_mode')?->fetchColumn();
            $connection = AsyncConnection::mysql(Databases::asyncMySql($database, \is_string($mode) ? $mode : 'ANSI_QUOTES'), 'mysql-async');
            try {
                $test($flags->orm($connection));
            } finally {
                $connection->close();
            }
        });
    }
}
