<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * Which database an application keeps its rows in: what `tinvar new` asks
 * (`--database=sqlite|postgres|mysql`) and an application's config reads,
 * as the skeleton's APP_DATABASE_DRIVER (ADR 0157).
 *
 * SQLite is a file beside the application and needs nothing else, so it is
 * the default; Postgres and MySQL are servers, reached by host, port and
 * database name, with the user and the password given apart -- never in a
 * DSN, where a password is one printed line away from a log.
 */
enum DatabaseDriver: string
{
    case Sqlite = 'sqlite';
    case Postgres = 'postgres';
    case Mysql = 'mysql';

    /** What a person reads: `SQLite`, `PostgreSQL`, `MySQL`. */
    public function title(): string
    {
        return match ($this) {
            self::Sqlite => 'SQLite',
            self::Postgres => 'PostgreSQL',
            self::Mysql => 'MySQL',
        };
    }

    /** PDO's name for the driver, and the start of its DSN. */
    public function pdoDriver(): string
    {
        return match ($this) {
            self::Sqlite => 'sqlite',
            self::Postgres => 'pgsql',
            self::Mysql => 'mysql',
        };
    }

    /** The PHP extension PDO needs for it: `pdo_pgsql`. */
    public function extension(): string
    {
        return 'pdo_' . $this->pdoDriver();
    }

    /** Whether it is a server reached by host and port, not a file. */
    public function isServer(): bool
    {
        return $this !== self::Sqlite;
    }

    /** The server's usual port; null for SQLite. */
    public function defaultPort(): ?int
    {
        return match ($this) {
            self::Sqlite => null,
            self::Postgres => 5432,
            self::Mysql => 3306,
        };
    }

    /**
     * The DSN for a server's database, with no credentials in it. MySQL's
     * names utf8mb4, so a row holds any character a person types.
     *
     * @throws \LogicException for SQLite, which is opened by its path (PdoConnection::sqlite())
     */
    public function dsn(string $host, ?int $port, string $database): string
    {
        if (!$this->isServer()) {
            throw new \LogicException('A SQLite database is opened by its path, with PdoConnection::sqlite().');
        }
        if ($host === '' || $database === '' || preg_match('/[;=\s]/', $host . $database) === 1) {
            throw new \InvalidArgumentException("A {$this->title()} DSN needs a host and a database name, neither with a semicolon, an equals sign or a space.");
        }
        $port ??= $this->defaultPort();

        return $this === self::Mysql
            ? "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4"
            : "pgsql:host={$host};port={$port};dbname={$database}";
    }
}
