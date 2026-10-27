<?php

declare(strict_types=1);

namespace Tinvar\Tests\Orm\Fixtures;

use Tinvar\Orm\Attributes\BeforeSave;
use Tinvar\Orm\Attributes\BelongsTo;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\HasMany;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\TenantScoped;
use Tinvar\Orm\Attributes\Unique;
use Tinvar\Orm\Collection;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\RowLevelSecurity;
use Tinvar\Orm\TenantSubtrees;
use Tinvar\Support\Uuid;

require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/databases.php';

/*
 * What the query builder's tests read: two tenants' aisles, shelves and
 * items, with a hidden column, a nullable one, and rows that point across
 * the tenant line -- a shelf on another tenant's aisle, an item on another
 * tenant's shelf -- so a join or a subquery that forgot its tenant
 * predicate would show it. Plain SQL types, so the same tables are made on
 * SQLite, Postgres and MySQL.
 */

#[Table('aisles'), TenantScoped, Unique('code')]
final class Aisle extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $code;
}

#[Table('shelves'), TenantScoped]
final class Shelf extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;
    #[Column] public int $position;
    #[Column] public ?string $note = null;

    /** On no Data object: what no sort or filter from a request may reach. */
    #[Column] public string $secret;

    #[BelongsTo] public Aisle $aisle;

    /** @var Collection<Item> */
    #[HasMany(Item::class, orderBy: 'name')]
    public Collection $items;
}

#[Table('items'), TenantScoped]
final class Item extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;
    #[Column] public int $priceCents;

    #[BelongsTo] public Shelf $shelf;
}

/** Not tenant-scoped: what an application with no tenancy reads. */
#[Table('brands'), Unique('name')]
final class Brand extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;
    #[Column] public int $rank;
}

/**
 * The brands table read as a model whose saves run a hook: what a write
 * through a statement, which makes no model, must not write past.
 */
#[Table('brands')]
final class TrimmedBrand extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;
    #[Column] public int $rank;

    #[BeforeSave]
    private function trim(): void
    {
        $this->name = trim($this->name);
    }
}

/**
 * The shelves table read as a model that forgot #[TenantScoped]: the bug
 * Postgres's row-level security is the second line against.
 */
#[Table('shelves')]
final class UnscopedShelf extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;
}

/** The rows, made on any of the three databases. */
final class Shelves
{
    /** @var array<string, string> by name, every row's id */
    public array $ids = [];

    public function __construct(public readonly \PDO $pdo)
    {
        $pdo->exec('CREATE TABLE aisles (id VARCHAR(64) PRIMARY KEY, code VARCHAR(64) NOT NULL, tenant_id VARCHAR(64) NOT NULL)');
        $pdo->exec('CREATE TABLE shelves (id VARCHAR(64) PRIMARY KEY, name VARCHAR(64) NOT NULL, position INTEGER NOT NULL, '
            . 'note VARCHAR(64), secret VARCHAR(64) NOT NULL, aisle_id VARCHAR(64) NOT NULL, tenant_id VARCHAR(64) NOT NULL)');
        $pdo->exec('CREATE TABLE items (id VARCHAR(64) PRIMARY KEY, name VARCHAR(64) NOT NULL, price_cents INTEGER NOT NULL, '
            . 'shelf_id VARCHAR(64) NOT NULL, tenant_id VARCHAR(64) NOT NULL)');
        // `rank` is a word MySQL keeps, so quoted -- as standard SQL, and
        // MySQL with ANSI_QUOTES, quote it.
        $pdo->exec('CREATE TABLE brands (id VARCHAR(64) PRIMARY KEY, name VARCHAR(64) NOT NULL, "rank" INTEGER NOT NULL)');
        // The #[Unique] keys, the tenant column leading a tenant's: what an
        // upsert conflicts on.
        $pdo->exec('CREATE UNIQUE INDEX aisles_tenant_id_code_unique ON aisles (tenant_id, code)');
        $pdo->exec('CREATE UNIQUE INDEX brands_name_unique ON brands (name)');

        foreach ([['A1', 'acme'], ['A2', 'acme'], ['G1', 'globex']] as [$code, $tenant]) {
            $this->insert('aisles', $code, [$code, $tenant]);
        }

        // name, position, note, secret, aisle, tenant
        foreach ([
            ['Alpha', 1, 'top', 's-m', 'A1', 'acme'],
            ['Bravo', 2, null, 's-a', 'A2', 'acme'],
            ['Charlie', 3, 'low', 's-z', 'A1', 'acme'],
            ['Foxtrot', 4, null, 's-q', 'G1', 'acme'],     // on globex's aisle
            ['Delta', 1, 'g', 's-b', 'G1', 'globex'],
            ['Echo', 2, null, 's-c', 'A1', 'globex'],      // on acme's aisle
        ] as [$name, $position, $note, $secret, $aisle, $tenant]) {
            $this->insert('shelves', $name, [$name, $position, $note, $secret, $this->ids[$aisle], $tenant]);
        }

        // name, price, shelf, tenant
        foreach ([
            ['apple', 100, 'Alpha', 'acme'],
            ['apricot', 250, 'Alpha', 'acme'],
            ['banana', 50, 'Bravo', 'acme'],
            ['fig', 300, 'Foxtrot', 'acme'],
            ['date', 75, 'Delta', 'globex'],
            ['eggplant', 125, 'Echo', 'globex'],
            ['ghost', 999, 'Alpha', 'globex'],             // globex's, on acme's shelf
        ] as [$name, $price, $shelf, $tenant]) {
            $this->insert('items', $name, [$name, $price, $this->ids[$shelf], $tenant]);
        }

        foreach ([['Zeta', 1], ['Ypsilon', 2], ['Xi', 3]] as [$name, $rank]) {
            $this->insert('brands', $name, [$name, $rank]);
        }
    }

    public static function sqlite(): self
    {
        return new self(new \PDO('sqlite::memory:'));
    }

    public function connection(string $name = 'test'): PdoConnection
    {
        $connection = new PdoConnection($this->pdo, $name);
        $connection->resetStatementCount();

        return $connection;
    }

    public function orm(?TenantSubtrees $tenants = null, ?PdoConnection $connection = null): Orm
    {
        return new Orm($connection ?? $this->connection(), new IdentityMap('test'), $tenants ?? Tenancy::flat());
    }

    /** @param list<mixed> $values */
    private function insert(string $table, string $name, array $values): void
    {
        $id = Uuid::v7();
        $this->ids[$name] = $id;
        $placeholders = implode(', ', array_fill(0, \count($values) + 1, '?'));
        $this->pdo->prepare("INSERT INTO {$table} VALUES ({$placeholders})")->execute([$id, ...$values]);
    }

    /**
     * Runs $test on Postgres against these rows with every tenant table
     * held by its row-level security policy, as a role that is not a
     * superuser -- a superuser bypasses every policy there is.
     *
     * @param \Closure(self, PdoConnection): void $test
     */
    public static function onPostgres(\Closure $test): void
    {
        Databases::inScratchSchema('pgsql', static function (\PDO $pdo, string $schema) use ($test): void {
            $shelves = new self($pdo);
            foreach ([Aisle::class, Shelf::class, Item::class] as $model) {
                foreach (RowLevelSecurity::forModel($model) as $sql) {
                    $pdo->exec($sql);
                }
            }

            $role = 'tinvar_rls_' . bin2hex(random_bytes(4));
            $pdo->exec("CREATE ROLE {$role} NOLOGIN");
            $pdo->exec("GRANT USAGE ON SCHEMA {$schema} TO {$role}");
            $pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA {$schema} TO {$role}");
            $pdo->exec("SET ROLE {$role}");

            try {
                $test($shelves, $shelves->connection('pgsql'));
            } finally {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $pdo->exec('RESET ROLE');
                $pdo->exec("SELECT set_config('app.tenant', '', false), set_config('app.across_tenants', 'off', false), set_config('app.subtree', '', false)");
                $pdo->exec("DROP OWNED BY {$role}");
                $pdo->exec("DROP ROLE {$role}");
            }
        });
    }

    /**
     * Runs $test on MySQL against these rows, with ANSI_QUOTES on: the ORM
     * quotes identifiers as standard SQL does.
     *
     * @param \Closure(self, PdoConnection): void $test
     */
    public static function onMySql(\Closure $test): void
    {
        Databases::inScratchSchema('mysql', static function (\PDO $pdo) use ($test): void {
            $pdo->exec("SET SESSION sql_mode = CONCAT(@@sql_mode, ',ANSI_QUOTES')");
            $shelves = new self($pdo);
            $test($shelves, $shelves->connection('mysql'));
        });
    }
}
