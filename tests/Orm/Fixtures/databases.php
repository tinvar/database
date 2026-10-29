<?php

declare(strict_types=1);

namespace Tinvar\Tests\Orm\Fixtures;

/**
 * The servers a database test can reach, beside the SQLite every test has:
 * Postgres and MySQL.
 *
 * The database package's copy of the framework suite's finder
 * (packages/framework/tests/Support/Fixtures/databases.php), so its tests
 * run with nothing of the framework's installed (ADR 0055); it leaves out
 * Redis, which only the framework's tests use.
 *
 * Tests that need one run when it is there and are skipped, saying why, when
 * it is not: the suite has to pass on a machine with PHP alone, and a test
 * that quietly passed without its server would be worse than a skipped one.
 */
final class Databases
{
    /** @var array<string, \PDO|string> by driver: a connection, or why there is none */
    private static array $found = [];

    /** @var array<string, string> by driver: where the server was found */
    private static array $dsns = [];

    /**
     * A Postgres connection, or why there is none.
     *
     * TINVAR_TEST_PGSQL_DSN names the server, as a PDO DSN with the user and
     * password in it (`pgsql:host=…;dbname=…;user=…;password=…`). Without it,
     * a server pg_isready finds on this machine is tried as PGUSER, or the
     * postgres role, which is what a local install trusts.
     */
    public static function postgres(): \PDO|string
    {
        return self::$found['pgsql'] ??= self::connect('pgsql', static function (): ?string {
            $dsn = getenv('TINVAR_TEST_PGSQL_DSN');
            if (\is_string($dsn) && $dsn !== '') {
                return $dsn;
            }

            exec('pg_isready -q 2>/dev/null', $output, $status);
            if ($status !== 0) {
                return null;
            }

            return \sprintf(
                'pgsql:host=%s;port=%s;dbname=%s;user=%s;connect_timeout=2',
                self::env('PGHOST', 'localhost'),
                self::env('PGPORT', '5432'),
                self::env('PGDATABASE', 'postgres'),
                self::env('PGUSER', 'postgres'),
            );
        }, 'Postgres: set TINVAR_TEST_PGSQL_DSN, or run a server pg_isready can find.');
    }

    /**
     * A MySQL connection, or why there is none. Only TINVAR_TEST_MYSQL_DSN
     * names one (`mysql:host=…;dbname=…;user=…;password=…`): nothing like
     * pg_isready says one is running, and guessing at a root password is
     * not something a test suite should do.
     */
    public static function mysql(): \PDO|string
    {
        return self::$found['mysql'] ??= self::connect('mysql', static function (): ?string {
            $dsn = getenv('TINVAR_TEST_MYSQL_DSN');

            return \is_string($dsn) && $dsn !== '' ? $dsn : null;
        }, 'MySQL: set TINVAR_TEST_MYSQL_DSN.');
    }

    /** Why the Postgres tests are skipped, or null when they run. */
    public static function postgresMissing(): ?string
    {
        $found = self::postgres();

        return \is_string($found) ? $found : null;
    }

    /**
     * Why the async Postgres driver's tests are skipped, or null when they
     * run: the server, and ext-pgsql, which amphp/postgres runs on.
     */
    public static function asyncPostgresMissing(): ?string
    {
        return self::postgresMissing() ?? (\extension_loaded('pgsql') ? null : 'ext-pgsql is not loaded: the async Postgres driver runs on it.');
    }

    /** Why the MySQL tests are skipped, or null when they run. */
    public static function mysqlMissing(): ?string
    {
        $found = self::mysql();

        return \is_string($found) ? $found : null;
    }

    /**
     * A fresh connection to the server found, for a test that needs a
     * second one -- another process's view, a second worker.
     */
    public static function another(string $driver): \PDO
    {
        $found = $driver === 'pgsql' ? self::postgres() : self::mysql();
        if (\is_string($found)) {
            throw new \LogicException($found);
        }

        return self::open(self::$dsns[$driver]);
    }

    /** The DSN the server was found at, to hand to a child process. */
    public static function dsn(string $driver): string
    {
        $driver === 'pgsql' ? self::postgres() : self::mysql();

        return self::$dsns[$driver] ?? throw new \LogicException("No {$driver} server was found.");
    }

    /**
     * A schema (Postgres) or database (MySQL) of the test's own, made now
     * and dropped when $work returns, with the connection pointed at it --
     * so a test never sees another's tables, nor leaves its own behind.
     *
     * @template T
     * @param \Closure(\PDO, string): T $work given the connection and the schema's name
     * @return T
     */
    public static function inScratchSchema(string $driver, \Closure $work): mixed
    {
        $pdo = $driver === 'pgsql' ? self::postgres() : self::mysql();
        if (\is_string($pdo)) {
            throw new \LogicException($pdo);
        }

        $schema = 'tinvar_test_' . bin2hex(random_bytes(6));
        if ($driver === 'pgsql') {
            $pdo->exec("CREATE SCHEMA {$schema}");
            $pdo->exec("SET search_path TO {$schema}");
        } else {
            $pdo->exec("CREATE DATABASE {$schema}");
            $pdo->exec("USE {$schema}");
        }

        try {
            return $work($pdo, $schema);
        } finally {
            // Whatever the test left open goes first, or the drop waits on it.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->exec($driver === 'pgsql' ? "DROP SCHEMA {$schema} CASCADE" : "DROP DATABASE {$schema}");
            if ($driver === 'pgsql') {
                $pdo->exec('SET search_path TO DEFAULT');
            }
        }
    }

    /**
     * The Postgres server found, as the async driver's config: the same
     * host, port, database, user and password, with $options -- a search
     * path, a role -- for every session to start with.
     */
    public static function asyncPostgres(string $options): \Amp\Postgres\PostgresConfig
    {
        return \Amp\Postgres\PostgresConfig::fromString(self::connectionString('pgsql'))->withOptions($options);
    }

    /** The MySQL server found, as the async driver's config, in $database with $sqlMode. */
    public static function asyncMySql(string $database, string $sqlMode): \Amp\Mysql\MysqlConfig
    {
        return \Amp\Mysql\MysqlConfig::fromString(self::connectionString('mysql'))->withDatabase($database)->withSqlMode($sqlMode);
    }

    /** A PDO DSN's pairs as the amphp clients read them: `key=value`, space apart. */
    private static function connectionString(string $driver): string
    {
        $pairs = [];
        foreach (explode(';', substr(self::dsn($driver), \strlen($driver) + 1)) as $pair) {
            if (str_contains($pair, '=') && !str_starts_with($pair, 'connect_timeout=')) {
                $pairs[] = str_replace(' ', '\\ ', trim($pair));
            }
        }

        return implode(' ', $pairs);
    }

    /** @param \Closure(): ?string $dsn */
    private static function connect(string $driver, \Closure $dsn, string $missing): \PDO|string
    {
        if (!\extension_loaded("pdo_{$driver}")) {
            return "pdo_{$driver} is not loaded.";
        }

        $found = $dsn();
        if ($found === null) {
            return $missing;
        }

        try {
            $pdo = self::open($found);
        } catch (\PDOException $e) {
            return "{$missing} The server found refused a connection: {$e->getMessage()}";
        }

        self::$dsns[$driver] = $found;

        return $pdo;
    }

    private static function open(string $dsn): \PDO
    {
        return new \PDO($dsn, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 2,
        ]);
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return \is_string($value) && $value !== '' ? $value : $default;
    }
}
