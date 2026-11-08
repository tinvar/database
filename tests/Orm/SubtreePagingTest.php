<?php

declare(strict_types=1);

/**
 * A subtree of any size, read and written in pages (ADR 0196).
 *
 * withinSubtree() reads one subtree up to TenantBounds::MAX_SUBTREE
 * tenants, and past MAX_LISTED binds them as one value rather than one
 * placeholder each; withinSubtreePages() reads a subtree of any size a page
 * of tenants at a time, under the same grant; forEachTenant() runs a job's
 * or a command's work as each tenant of one in turn, from where it last
 * stopped. The pages are the tree's own walk, in byte order of the
 * tenants' names, checked by the Orm as every answer of the tree is.
 *
 * The proof at the end builds 5,000 tenants three levels below one root,
 * and a tree beside it with a tenant named to sort among them, on SQLite,
 * Postgres (as a role row-level security holds) and MySQL -- through PDO
 * and, on the servers, the async driver -- and checks that each read is
 * complete and that no row outside the subtree is read or written.
 */

namespace Tinvar\Tests\Orm\SubtreePaging;

use Tinvar\Orm\AsyncConnection;
use Tinvar\Orm\Connection;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PagedTenantSubtrees;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\RowLevelSecurity;
use Tinvar\Orm\SubtreePage;
use Tinvar\Orm\TenantBounds;
use Tinvar\Orm\TenantSubtrees;
use Tinvar\Support\Uuid;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Tests\Orm\Fixtures\ScriptRun;

require_once __DIR__ . '/Fixtures/tenant.php';
require_once __DIR__ . '/Fixtures/databases.php';

/**
 * A port over a list of parent links, paged as a table's would be: in byte
 * order, after a name, so many at a time -- or, given $page, answering
 * pages however that says.
 */
final readonly class Links implements PagedTenantSubtrees
{
    /** @param array<string, string> $parents child => parent */
    public function __construct(private array $parents, private ?\Closure $page = null) {}

    public function hierarchical(): bool
    {
        return true;
    }

    public function ancestors(string $tenant): array
    {
        $above = [];
        while (isset($this->parents[$tenant]) && !\in_array($this->parents[$tenant], $above, true)) {
            $above[] = $tenant = $this->parents[$tenant];
        }

        return $above;
    }

    public function subtree(string $root): array
    {
        return [$root, ...$this->below($root)];
    }

    public function descendantsAfter(string $root, ?string $after, int $limit): array
    {
        if ($this->page !== null) {
            return ($this->page)($root, $after, $limit);
        }
        $below = array_values(array_filter($this->below($root), static fn (string $t): bool => $after === null || strcmp($t, $after) > 0));
        sort($below, SORT_STRING);

        return \array_slice($below, 0, $limit);
    }

    /** @return list<string> */
    private function below(string $root): array
    {
        $children = [];
        foreach ($this->parents as $child => $parent) {
            $children[$parent][] = (string) $child;
        }
        $found = [];
        for ($queue = [$root]; $queue !== [];) {
            foreach ($children[array_shift($queue)] ?? [] as $child) {
                if ($child !== $root && !isset($found[$child])) {
                    $found[$child] = true;
                    $queue[] = $child;
                }
            }
        }

        return array_map('strval', array_keys($found));
    }
}

/** The same links, answering subtree() alone. */
final readonly class Unpaged implements TenantSubtrees
{
    public function __construct(private Links $links) {}

    public function hierarchical(): bool
    {
        return true;
    }

    public function ancestors(string $tenant): array
    {
        return $this->links->ancestors($tenant);
    }

    public function subtree(string $root): array
    {
        return $this->links->subtree($root);
    }
}

/**
 * A wide tree: 'root' over $n children named c0001... and 'other' beside.
 *
 * @return array<string, string>
 */
function wide(int $n): array
{
    $parents = ['other-1' => 'other'];
    for ($i = 1; $i <= $n; $i++) {
        $parents[\sprintf('c%05d', $i)] = 'root';
    }

    return $parents;
}

/** @param list<SubtreePage> $pages @return list<list<string>> */
function lists(array $pages): array
{
    return array_map(static fn (SubtreePage $page): array => $page->tenants, $pages);
}

/** A database of one script and one run per tenant named, on SQLite. */
function sqliteScripts(array $tenants): PdoConnection
{
    $pdo = new \PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE scripts (id TEXT PRIMARY KEY, name TEXT NOT NULL, tenant_id TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE script_runs (id TEXT PRIMARY KEY, status TEXT NOT NULL, script_id TEXT NOT NULL, tenant_id TEXT NOT NULL)');
    seed($pdo, $tenants);

    return new PdoConnection($pdo, 'paging-test');
}

/** One script, "{tenant}-script", and one run of it per tenant, in batches. */
function seed(\PDO $pdo, array $tenants): void
{
    foreach (array_chunk($tenants, 400) as $chunk) {
        $scripts = [];
        $runs = [];
        foreach ($chunk as $tenant) {
            $id = Uuid::v7();
            array_push($scripts, $id, "{$tenant}-script", $tenant);
            array_push($runs, Uuid::v7(), 'new', $id, $tenant);
        }
        $pdo->prepare('INSERT INTO scripts (id, name, tenant_id) VALUES ' . implode(', ', array_fill(0, \count($chunk), '(?, ?, ?)')))->execute($scripts);
        $pdo->prepare('INSERT INTO script_runs (id, status, script_id, tenant_id) VALUES ' . implode(', ', array_fill(0, \count($chunk), '(?, ?, ?, ?)')))->execute($runs);
    }
}

/** @return list<string> the tenants whose scripts the read in force reaches, sorted */
function tenantsRead(Orm $orm): array
{
    $tenants = array_map(static fn (Script $s): string => substr($s->name, 0, -\strlen('-script')), $orm->all(Script::class));
    sort($tenants, SORT_STRING);

    return $tenants;
}

/**
 * @template T
 * @param \Closure(): T $work
 * @return T
 */
function granted(Orm $orm, string $tenant, \Closure $work): mixed
{
    return $orm->actAs($tenant, static fn (): mixed => $orm->grantSubtree($work));
}

describe('the pages of a subtree', function () {
    it('are the root and every tenant below it, in byte order, each once, so many at a time', function () {
        $bounds = new TenantBounds(new Links(['b' => 'm', 'z' => 'm', 'B' => 'b', 'n' => 'z', 'a' => 'other']));

        expect(lists(iterator_to_array($bounds->pages('m', 2), false)))->toBe([['B', 'b'], ['m', 'n'], ['z']])
            ->and(lists(iterator_to_array($bounds->pages('m', 10), false)))->toBe([['B', 'b', 'm', 'n', 'z']])
            ->and(lists(iterator_to_array($bounds->pages('a', 3), false)))->toBe([['a']])
            ->and(lists(iterator_to_array($bounds->pages('nobody', 3), false)))->toBe([['nobody']]);
    });

    it('go on from a page\'s last tenant, which is all a checkpoint has to keep', function () {
        $bounds = new TenantBounds(new Links(['b' => 'm', 'z' => 'm', 'B' => 'b', 'n' => 'z']));
        $first = iterator_to_array($bounds->pages('m', 2), false)[0];

        expect($first->last())->toBe('b')
            ->and($first->root)->toBe('m')
            ->and(lists(iterator_to_array($bounds->pages('m', 2, after: $first->last()), false)))->toBe([['m', 'n'], ['z']])
            ->and(lists(iterator_to_array($bounds->pages('m', 2, after: 'm'), false)))->toBe([['n', 'z']])
            ->and(lists(iterator_to_array($bounds->pages('m', 2, after: 'zz'), false)))->toBe([]);
    });

    it('are the same from a port that answers the whole subtree alone', function () {
        $links = new Links(wide(25));
        $paged = iterator_to_array(new TenantBounds($links)->pages('root', 7), false);
        $whole = iterator_to_array(new TenantBounds(new Unpaged($links))->pages('root', 7), false);

        expect(lists($whole))->toBe(lists($paged))
            ->and(array_merge(...lists($paged)))->toHaveCount(26)
            ->and(array_merge(...lists($paged)))->not->toContain('other', 'other-1');
    });

    // A port that pages wrong would walk forever, or skip, or repeat: each
    // page is held to its own order and to coming after the last.
    it('are refused whole from a port that does not page as asked', function () {
        $answers = [
            'ignores the cursor' => static fn (string $root, ?string $after, int $limit): array => ['a', 'b'],
            'out of order' => static fn (string $root, ?string $after, int $limit): array => $after === null ? ['b', 'a'] : [],
            'more than asked' => static fn (string $root, ?string $after, int $limit): array => $after === null ? ['a', 'b', 'c'] : [],
            'no tenant' => static fn (string $root, ?string $after, int $limit): array => $after === null ? ['a', "b\0"] : [],
        ];
        foreach ($answers as $why => $page) {
            $bounds = new TenantBounds(new Links([], \Closure::fromCallable($page)));

            expect(fn () => iterator_to_array($bounds->pages('root', 2), false))->toThrow(\UnexpectedValueException::class, null, $why);
        }
    });

    it('are from 1 to MAX_SUBTREE tenants, below a root, after a tenant\'s name', function () {
        $bounds = new TenantBounds(new Links([]));

        expect(fn () => iterator_to_array($bounds->pages('root', 0)))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => iterator_to_array($bounds->pages('root', TenantBounds::MAX_SUBTREE + 1)))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => iterator_to_array($bounds->pages('', 10)))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => iterator_to_array($bounds->pages('root', 10, after: "a\0")))->toThrow(\InvalidArgumentException::class);
    });
});

describe('a subtree read at once', function () {
    it('is read from a paged port no further than the limit, and refused past it', function () {
        $asked = [];
        $links = new Links(wide(30));
        $bounds = new TenantBounds(new Links([], static function (string $root, ?string $after, int $limit) use ($links, &$asked): array {
            $asked[] = $limit;

            return $links->descendantsAfter($root, $after, $limit);
        }), maxSubtree: 20);

        expect(fn () => $bounds->subtree('root'))->toThrow(\LengthException::class, 'withinSubtreePages()')
            ->and($asked)->toBe([20])
            ->and(new TenantBounds($links, maxSubtree: 31)->subtree('root'))->toHaveCount(31)
            ->and(new TenantBounds($links, maxSubtree: 31)->subtree('root')[0])->toBe('root');
    });

    it('may only be held to less than MAX_SUBTREE', function () {
        expect(fn () => new TenantBounds(new Links([]), maxSubtree: TenantBounds::MAX_SUBTREE + 1))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => new TenantBounds(new Links([]), maxSubtree: 0))->toThrow(\InvalidArgumentException::class)
            ->and(TenantBounds::MAX_SUBTREE)->toBe(10_000)
            ->and(TenantBounds::MAX_LISTED)->toBe(1_000);
    });

    // Past MAX_LISTED the tenants are one bound value, not one each.
    it('binds a subtree past MAX_LISTED as one value, and reads all of it', function () {
        $parents = wide(1_500);
        $tenants = ['root', ...array_keys(array_filter($parents, static fn (string $p): bool => $p === 'root'))];
        $connection = sqliteScripts([...$tenants, 'other', 'other-1']);
        $orm = new Orm($connection, new IdentityMap('paging'), new Links($parents));

        $connection->resetStatementCount();
        $read = granted($orm, 'root', static fn (): array => $orm->withinSubtree(static fn (): array => tenantsRead($orm)));
        $statement = array_values(array_filter($connection->statementLog(), static fn (string $sql): bool => str_contains($sql, 'FROM "scripts"')));
        sort($tenants, SORT_STRING);

        expect($read)->toBe($tenants)
            ->and($statement)->toHaveCount(1)
            ->and($statement[0])->toContain('json_each(?)')
            ->and(substr_count($statement[0], '?'))->toBe(1);
    });
});

describe('withinSubtreePages()', function () {
    it('reads each page of the subtree alone, and goes on from where it stopped', function () {
        $parents = wide(12);
        $connection = sqliteScripts(['root', 'other', 'other-1', ...array_keys($parents)]);
        $orm = new Orm($connection, new IdentityMap('paging'), new Links($parents));
        $seen = [];
        $read = static function (SubtreePage $page) use ($orm, &$seen): void {
            $seen[] = [$page->tenants, tenantsRead($orm), $orm->tenant()];
        };

        $stopped = granted($orm, 'root', static fn (): ?string => $orm->withinSubtreePages($read, 5, pages: 2));
        $done = granted($orm, 'root', static fn (): ?string => $orm->withinSubtreePages($read, 5, after: $stopped));

        expect($stopped)->toBe('c00010')
            ->and($done)->toBeNull()
            ->and(array_column($seen, 0))->toBe([
                ['c00001', 'c00002', 'c00003', 'c00004', 'c00005'],
                ['c00006', 'c00007', 'c00008', 'c00009', 'c00010'],
                ['c00011', 'c00012', 'root'],
            ])
            ->and(array_column($seen, 1))->toBe(array_column($seen, 0))
            ->and(array_unique(array_column($seen, 2)))->toBe(['root'])
            ->and(granted($orm, 'root', static fn (): array => tenantsRead($orm)))->toBe(['root']);
    });

    it('runs only where a membership granted the subtree, from inside it', function () {
        $orm = new Orm(sqliteScripts(['root', 'other']), new IdentityMap('paging'), new Links(wide(3)));
        $page = static fn (): null => null;

        expect(fn () => $orm->actAs('root', static fn () => $orm->withinSubtreePages($page)))->toThrow(\LogicException::class, 'membership')
            ->and(fn () => granted($orm, 'c00001', static fn () => $orm->actAs('root', static fn () => $orm->withinSubtreePages($page))))
            ->toThrow(\LogicException::class, 'not in its subtree')
            ->and(fn () => granted($orm, 'root', static fn () => $orm->withinSubtreePages($page, 0)))->toThrow(\InvalidArgumentException::class);
    });
});

describe('forEachTenant()', function () {
    it('runs the work as each tenant of the subtree in turn, and says where to go on from', function () {
        $parents = wide(7);
        $orm = new Orm(sqliteScripts(['root', 'other', 'other-1', ...array_keys($parents)]), new IdentityMap('paging'), new Links($parents));
        $seen = [];
        $each = static function (string $tenant) use ($orm, &$seen): void {
            $seen[$tenant] = [$orm->tenant(), tenantsRead($orm)];
        };

        $first = $orm->forEachTenant('root', $each, limit: 3, pageSize: 2);
        $second = $orm->forEachTenant('root', $each, after: $first, limit: 3, pageSize: 2);
        $last = $orm->forEachTenant('root', $each, after: $second, pageSize: 2);

        expect([$first, $second, $last])->toBe(['c00003', 'c00006', null])
            ->and(array_keys($seen))->toBe(['c00001', 'c00002', 'c00003', 'c00004', 'c00005', 'c00006', 'c00007', 'root'])
            ->and(array_filter($seen, static fn (array $s, string $tenant): bool => $s !== [$tenant, [$tenant]], ARRAY_FILTER_USE_BOTH))->toBe([]);
    });

    // A job holds no grant (ADR 0047): each tenant is run as itself, which
    // actAs() lets any code do -- and nothing is widened.
    it('needs no grant, and widens nothing inside a widened read', function () {
        $orm = new Orm(sqliteScripts(['root', 'c00001', 'other']), new IdentityMap('paging'), new Links(wide(1)));
        $seen = [];
        $each = static function (string $tenant) use ($orm, &$seen): void {
            $seen[$tenant] = [$orm->subtreeInForce(), tenantsRead($orm)];
        };

        granted($orm, 'root', static fn (): mixed => $orm->withinSubtree(static fn (): ?string => $orm->forEachTenant('root', $each)));

        expect($seen)->toBe(['c00001' => [null, ['c00001']], 'root' => [null, ['root']]]);
    });

    it('is refused with no tenant tree', function () {
        $orm = new Orm(sqliteScripts(['root']), new IdentityMap('paging'), \Tinvar\Tests\Orm\Fixtures\Tenancy::flat());

        expect(fn () => $orm->forEachTenant('root', static fn (): null => null))->toThrow(\LogicException::class, 'no tenant tree');
    });
});

// ------------------------------------------------------------- the proof

/**
 * 5,000 tenants three levels below 'r' -- 10 regions, 200 customers, the
 * rest sites -- and a tree beside it under 'x', one of whose tenants is
 * named to sort among r's.
 *
 * @return array{array<string, string>, list<string>, list<string>} the parent links, the subtree, the tenants outside it
 */
function forest(): array
{
    $parents = [];
    $subtree = ['r'];
    for ($a = 1; $a <= 10; $a++) {
        $parents[$subtree[] = "r-a{$a}"] = 'r';
    }
    for ($b = 1; $b <= 200; $b++) {
        $parents[$subtree[] = 'r-a' . (($b % 10) + 1) . "-b{$b}"] = 'r-a' . (($b % 10) + 1);
    }
    $customers = \array_slice($subtree, 11);
    for ($c = 1; \count($subtree) < 5_000; $c++) {
        $customer = $customers[$c % 200];
        $parents[$subtree[] = "{$customer}-c{$c}"] = $customer;
    }
    $outside = ['x', 'r-a1-impostor', 'r0'];
    $parents['r-a1-impostor'] = 'x';
    $parents['r0'] = 'x';
    for ($i = 1; $i <= 50; $i++) {
        $parents[$outside[] = "x-{$i}"] = 'x';
    }
    sort($subtree, SORT_STRING);

    return [$parents, $subtree, $outside];
}

/** The tree as a table, walked by one recursive query per page, in the database's byte order. */
final readonly class TableTree implements PagedTenantSubtrees
{
    public function __construct(private Connection $connection, private string $driver) {}

    public function hierarchical(): bool
    {
        return true;
    }

    public function ancestors(string $tenant): \Generator
    {
        for ($seen = [$tenant => true]; ;) {
            $parent = $this->connection->select('SELECT parent_id FROM tenants WHERE id = ?', [$tenant])[0]['parent_id'] ?? null;
            if (!\is_string($parent) || isset($seen[$parent])) {
                return;
            }
            $seen[$parent] = true;
            yield $tenant = $parent;
        }
    }

    public function subtree(string $root): array
    {
        return [$root, ...$this->descendantsAfter($root, null, \PHP_INT_MAX)];
    }

    public function descendantsAfter(string $root, ?string $after, int $limit): array
    {
        $bytes = match ($this->driver) {
            'pgsql' => 'id COLLATE "C"',
            'mysql' => 'CAST(id AS BINARY)',
            default => 'id',
        };
        $rows = $this->connection->select(
            'WITH RECURSIVE below(id) AS (SELECT id FROM tenants WHERE parent_id = ? UNION SELECT t.id FROM tenants t JOIN below b ON t.parent_id = b.id) '
            . "SELECT id FROM below WHERE id <> ?" . ($after === null ? '' : " AND {$bytes} > " . ($this->driver === 'mysql' ? 'CAST(? AS BINARY)' : '?'))
            . " ORDER BY {$bytes}" . ($limit === \PHP_INT_MAX ? '' : ' LIMIT ' . $limit),
            $after === null ? [$root, $root] : [$root, $root, $after],
        );

        return array_map(static fn (array $row): string => (string) $row['id'], $rows);
    }
}

/** Makes the tenants table and the scripts, through $pdo. */
function plant(\PDO $pdo, string $driver): array
{
    [$parents, $subtree, $outside] = forest();
    $text = $driver === 'mysql' ? 'VARCHAR(191)' : 'TEXT';
    $pdo->exec("CREATE TABLE tenants (id {$text} PRIMARY KEY, parent_id {$text})");
    $pdo->exec("CREATE TABLE scripts (id {$text} PRIMARY KEY, name {$text} NOT NULL, tenant_id {$text} NOT NULL)");
    $pdo->exec("CREATE TABLE script_runs (id {$text} PRIMARY KEY, status {$text} NOT NULL, script_id {$text} NOT NULL, tenant_id {$text} NOT NULL)");
    $pdo->exec('CREATE INDEX tenants_parent ON tenants (parent_id)');
    $pdo->exec('CREATE INDEX scripts_tenant ON scripts (tenant_id)');
    $pdo->exec('CREATE INDEX script_runs_tenant ON script_runs (tenant_id)');
    $all = [...$subtree, ...$outside];
    foreach (array_chunk($all, 500) as $chunk) {
        $values = [];
        foreach ($chunk as $tenant) {
            array_push($values, $tenant, $parents[$tenant] ?? null);
        }
        $pdo->prepare('INSERT INTO tenants (id, parent_id) VALUES ' . implode(', ', array_fill(0, \count($chunk), '(?, ?)')))->execute($values);
    }
    seed($pdo, $all);

    return [$subtree, $outside];
}

/**
 * Every read and write the ADR promises, across the 5,000: complete, and
 * nothing outside the subtree.
 *
 * @param list<string> $subtree
 * @param list<string> $outside
 * @param (\Closure(): int)|null $raw a count of scripts by raw SQL, which only row-level security holds
 * @param bool $writeAtOnce false writes a page at a time: amphp/mysql sends no
 *        statement of more than about 3,600 bound values, and update() names
 *        the 5,000 rows it writes by key
 */
function prove(Orm $orm, array $subtree, array $outside, ?\Closure $raw = null, bool $writeAtOnce = true): void
{
    $within = static fn (\Closure $work): mixed => granted($orm, 'r', static fn (): mixed => $orm->withinSubtree($work));

    // A read at once: every tenant's row, and no other.
    expect($within(static fn (): array => tenantsRead($orm)))->toBe($subtree)
        ->and($within(static fn (): int => $orm->query(Script::class)->count()))->toBe(5_000)
        // r-a1-impostor's script is named among them, and is x's.
        ->and($within(static fn (): int => $orm->query(Script::class)->where('name', 'like', 'r-a1-%')->count()))
        ->toBe(\count(array_filter($subtree, static fn (string $t): bool => str_starts_with("{$t}-script", 'r-a1-'))))
        ->and($within(static fn (): int => $orm->query(Script::class)->where('name', 'r-a1-impostor-script')->count()))->toBe(0);
    if ($raw !== null) {
        expect($within($raw))->toBe(5_000)
            ->and($orm->actAs('r', $raw))->toBe(1);
    }

    // A write, held to the subtree: x's runs are left alone.
    $written = 0;
    $write = static function () use ($orm, &$written): void {
        $written += $orm->query(ScriptRun::class)->update(['status' => 'seen']);
    };
    $writeAtOnce ? $within($write) : granted($orm, 'r', static fn (): ?string => $orm->withinSubtreePages($write, 1_000));

    expect($written)->toBe(5_000)
        ->and($orm->acrossTenants(static fn (): int => $orm->query(ScriptRun::class)->where('status', 'seen')->count()))->toBe(5_000)
        ->and($orm->actAs('r-a1-impostor', static fn (): array => $orm->query(ScriptRun::class)->pluck('status')))->toBe(['new']);

    // In pages: together the whole subtree, each page reading itself alone.
    $pages = [];
    $read = static function (SubtreePage $page) use ($orm, &$pages): void {
        $pages[] = [$page->tenants, tenantsRead($orm)];
    };
    $stopped = granted($orm, 'r', static fn (): ?string => $orm->withinSubtreePages($read, 700, pages: 4));
    $done = granted($orm, 'r', static fn (): ?string => $orm->withinSubtreePages($read, 700, after: $stopped));

    expect($stopped)->toBe($subtree[2_799])
        ->and($done)->toBeNull()
        ->and(\count($pages))->toBe(8)
        ->and(array_merge(...array_column($pages, 0)))->toBe($subtree)
        ->and(array_column($pages, 1))->toBe(array_column($pages, 0));

    // As each tenant in turn, stopped and gone on with: each once, as itself.
    $seen = [];
    $each = static function (string $tenant) use ($orm, &$seen): void {
        $seen[] = $tenant;
        expect(tenantsRead($orm))->toBe([$tenant]);
    };
    $cursor = null;
    for ($runs = 0; $runs === 0 || $cursor !== null; $runs++) {
        $cursor = $orm->forEachTenant('r', $each, after: $cursor, limit: 2_000);
    }

    expect($runs)->toBe(3)
        ->and($seen)->toBe($subtree)
        ->and(array_intersect($seen, $outside))->toBe([]);
}

describe('5,000 tenants three levels deep', function () {
    it('are read and written whole, and alone, on SQLite', function () {
        $pdo = new \PDO('sqlite::memory:');
        [$subtree, $outside] = plant($pdo, 'sqlite');
        $connection = new PdoConnection($pdo, 'paging-sqlite');

        prove(new Orm($connection, new IdentityMap('paging'), new TableTree($connection, 'sqlite')), $subtree, $outside);
    });

    it('are read and written whole, and alone, on Postgres under row-level security', function () {
        Databases::inScratchSchema('pgsql', static function (\PDO $pdo, string $schema): void {
            [$subtree, $outside] = plant($pdo, 'pgsql');
            foreach ([...RowLevelSecurity::forModel(Script::class), ...RowLevelSecurity::forModel(ScriptRun::class)] as $sql) {
                $pdo->exec($sql);
            }
            $role = 'tinvar_rls_' . bin2hex(random_bytes(4));
            $pdo->exec("CREATE ROLE {$role} NOLOGIN");
            $pdo->exec("GRANT USAGE ON SCHEMA {$schema} TO {$role}");
            $pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA {$schema} TO {$role}");
            $pdo->exec("SET ROLE {$role}");

            try {
                $connection = new PdoConnection($pdo, 'pgsql');
                $raw = static fn (): int => (int) $connection->select('SELECT count(*) AS n FROM scripts')[0]['n'];
                prove(new Orm($connection, new IdentityMap('paging'), new TableTree($connection, 'pgsql')), $subtree, $outside, $raw);

                // And through the async driver, the same tree and policy.
                $async = AsyncConnection::postgres(Databases::asyncPostgres("-c search_path={$schema} -c role={$role}"), 'pgsql-async');
                try {
                    $pdo->exec("UPDATE script_runs SET status = 'new'");
                    $raw = static fn (): int => (int) $async->select('SELECT count(*) AS n FROM scripts')[0]['n'];
                    prove(new Orm($async, new IdentityMap('paging-async'), new TableTree($async, 'pgsql')), $subtree, $outside, $raw);
                } finally {
                    $async->close();
                }
            } finally {
                $pdo->exec('RESET ROLE');
                $pdo->exec("SELECT set_config('app.tenant', '', false), set_config('app.across_tenants', 'off', false), set_config('app.subtree', '', false)");
                $pdo->exec("DROP OWNED BY {$role}");
                $pdo->exec("DROP ROLE {$role}");
            }
        });
    })->skip(Databases::asyncPostgresMissing() !== null, Databases::asyncPostgresMissing() ?? '');

    it('are read and written whole, and alone, on MySQL', function () {
        Databases::inScratchSchema('mysql', static function (\PDO $pdo, string $database): void {
            $pdo->exec("SET SESSION sql_mode = CONCAT(@@sql_mode, ',ANSI_QUOTES')");
            [$subtree, $outside] = plant($pdo, 'mysql');
            $connection = new PdoConnection($pdo, 'mysql');
            prove(new Orm($connection, new IdentityMap('paging'), new TableTree($connection, 'mysql')), $subtree, $outside);

            $pdo->exec("UPDATE script_runs SET status = 'new'");
            $mode = $pdo->query('SELECT @@SESSION.sql_mode')?->fetchColumn();
            $async = AsyncConnection::mysql(Databases::asyncMySql($database, \is_string($mode) ? $mode : 'ANSI_QUOTES'), 'mysql-async');
            try {
                prove(new Orm($async, new IdentityMap('paging-async'), new TableTree($async, 'mysql')), $subtree, $outside, writeAtOnce: false);
            } finally {
                $async->close();
            }
        });
    })->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');
});
