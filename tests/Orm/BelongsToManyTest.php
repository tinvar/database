<?php

declare(strict_types=1);

/**
 * #[BelongsToMany] (ADR 0192): a collection of models linked through a
 * pivot model -- read lazily and eagerly in one statement for a whole list,
 * filtered by whereHas() and counted by withCount(), written by attach(),
 * detach() and sync() in one transaction, each pivot row audited, every
 * side held to the tenant in force; and the pivot table made by
 * Table::pivot(). On SQLite, on MySQL, and on Postgres with the pivot held
 * by row-level security as a role that is not a superuser.
 */

namespace Tinvar\Tests\Orm\BelongsToMany;

use Tinvar\Orm\Attributes\Audited;
use Tinvar\Orm\Attributes\BelongsTo;
use Tinvar\Orm\Attributes\BelongsToMany;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\TenantScoped;
use Tinvar\Orm\Attributes\Unique;
use Tinvar\Orm\Collection;
use Tinvar\Orm\Connection;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\LazyLoads;
use Tinvar\Orm\MappingException;
use Tinvar\Orm\Migrations\MigrationGenerator;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\MissingRow;
use Tinvar\Orm\Model;
use Tinvar\Orm\ModelChange;
use Tinvar\Orm\ModelChanges;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\PivotChange;
use Tinvar\Orm\RelationKind;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\MySqlDialect;
use Tinvar\Orm\Schema\PostgresDialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\SqliteDialect;
use Tinvar\Orm\Schema\Table as SchemaTable;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Orm\Fixtures\Tenancy;

require_once __DIR__ . '/Fixtures/tenancy.php';
require_once __DIR__ . '/Fixtures/databases.php';

#[Table('m2m_products'), TenantScoped]
final class Product extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;

    /** @var Collection<Tag> */
    #[BelongsToMany(Tag::class, through: ProductTag::class, orderBy: 'name')]
    public Collection $tags;

    /** @var Collection<Tag> in the order they were attached */
    #[BelongsToMany(Tag::class, through: ProductTag::class)]
    public Collection $tagsAsAttached;
}

#[Table('m2m_tags'), TenantScoped]
final class Tag extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;

    /** @var Collection<Product> the other side, through the same pivot */
    #[BelongsToMany(Product::class, through: ProductTag::class, orderBy: 'name')]
    public Collection $products;
}

/** The pivot: a model of its own, with a column of its own, audited and held to the tenant. */
#[Table('m2m_product_tags'), TenantScoped, Audited, Unique('product', 'tag')]
final class ProductTag extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public int $weight = 0;
    #[BelongsTo] public Product $product;
    #[BelongsTo] public Tag $tag;
}

/** Keeps each change it is handed: the audit log. */
final class Log implements ModelChanges
{
    /** @var list<ModelChange> */
    public array $changes = [];

    public bool $fail = false;

    public function record(ModelChange $change): void
    {
        if ($this->fail) {
            throw new \RuntimeException('the log is full');
        }
        $this->changes[] = $change;
    }

    /** @return list<string> each change as kind:model */
    public function kinds(): array
    {
        return array_map(static fn (ModelChange $c): string => $c->kind->value . ':' . (new \ReflectionClass($c->model))->getShortName(), $this->changes);
    }
}

/** The tables, made through the schema builder: the pivot by Table::pivot(). */
function tables(Connection $connection, Dialect $dialect): void
{
    $schema = new Schema($connection, $dialect);
    $schema->create('m2m_products', static function (SchemaTable $table): void {
        $table->uuid('id')->primary();
        $table->tenant();
        $table->string('name');
    });
    $schema->create('m2m_tags', static function (SchemaTable $table): void {
        $table->uuid('id')->primary();
        $table->tenant();
        $table->string('name');
    });
    $schema->create('m2m_product_tags', static function (SchemaTable $table): void {
        $table->uuid('id')->primary();
        $table->tenant();
        $table->pivot(Product::class, Tag::class);
        $table->integer('weight')->default(0);
    });
}

function orm(Connection $connection, Log $log): Orm
{
    $orm = new Orm($connection, new IdentityMap('m2m'), Tenancy::flat());
    $orm->recordChangesWith($log);

    return $orm;
}

/** @return list<string> */
function names(iterable $models): array
{
    $out = [];
    foreach ($models as $model) {
        $out[] = $model->name;
    }

    return $out;
}

function product(Orm $orm, string $name): Product
{
    $product = new Product();
    $product->name = $name;

    return $orm->save($product);
}

function tag(Orm $orm, string $name): Tag
{
    $tag = new Tag();
    $tag->name = $name;

    return $orm->save($tag);
}

/**
 * What every database must do alike.
 *
 * @param \Closure(): void|null $raw a check of the pivot table read around the ORM, inside acme
 */
function behavesAlike(Connection $connection, Dialect $dialect, ?\Closure $raw = null): void
{
    tables($connection, $dialect);
    $log = new Log();
    $orm = orm($connection, $log);

    // acme's rows, and globex's.
    [$kettle, $toaster, $sale, $steel, $red] = $orm->actAs('acme', static fn (): array => [
        product($orm, 'kettle'), product($orm, 'toaster'), tag($orm, 'sale'), tag($orm, 'steel'), tag($orm, 'red'),
    ]);
    [$lamp, $theirs] = $orm->actAs('globex', static fn (): array => [product($orm, 'lamp'), tag($orm, 'aaa-theirs')]);

    // attach(): the links made, each once, each pivot row audited. A
    // millisecond apart, so the pivot's UUIDv7 keys sort in link order.
    $log->changes = [];
    expect($orm->actAs('acme', static fn (): PivotChange => $orm->attach($kettle, 'tags', [$steel]))->attached)->toBe([$steel->id]);
    usleep(2000);
    $change = $orm->actAs('acme', static fn (): PivotChange => $orm->attach($kettle, 'tags', [$steel, $sale->id]));
    expect($change->attached)->toBe([$sale->id])
        ->and($change->detached)->toBe([])
        ->and($change->changed())->toBeTrue()
        ->and($log->kinds())->toBe(['created:ProductTag', 'created:ProductTag']);

    // Again: idempotent, nothing written, nothing recorded.
    $again = $orm->actAs('acme', static fn (): PivotChange => $orm->attach($kettle, 'tags', [$sale, $steel, $sale]));
    expect($again->attached)->toBe([])->and($again->changed())->toBeFalse()->and($log->changes)->toHaveCount(2);

    $orm->actAs('acme', static fn (): PivotChange => $orm->attach($toaster, 'tags', [$red], ['weight' => 7]));

    // Across the tenant line, by hand -- what attach() refuses: a globex
    // pivot row on acme's kettle, and an acme one naming globex's tag.
    $orm->actAs('globex', static function () use ($orm, $kettle, $theirs): void {
        $link = new ProductTag();
        $link->product = $kettle;
        $link->tag = $theirs;
        $orm->save($link);
    });
    $orm->actAs('acme', static function () use ($orm, $toaster, $theirs): void {
        $link = new ProductTag();
        $link->product = $toaster;
        $link->tag = $theirs;
        $orm->save($link);
    });

    $orm->flush();
    $orm->actAs('acme', static function () use ($orm, $connection, $red): void {
        $loads = new LazyLoads();
        $orm->recordLazyLoads($loads);

        // Lazily: one statement for the whole list, either tenant's rows across the line left out.
        $products = $orm->query(Product::class)->orderBy('name')->fetch();
        $count = $connection instanceof PdoConnection ? $connection->statementCount() : 0;
        expect(names($products[0]->tags))->toBe(['sale', 'steel'])
            ->and(names($products[0]->tagsAsAttached))->toBe(['steel', 'sale'])
            ->and(names($products[1]->tags))->toBe(['red'])
            ->and($loads->outsideBatch())->toBe([]);
        if ($connection instanceof PdoConnection) {
            expect($connection->statementCount() - $count)->toBe(2);   // tags, tagsAsAttached: once each for both products
        }

        // The other side, through the same pivot.
        expect(names($orm->find(Tag::class, $red->id)?->products ?? []))->toBe(['toaster']);
    });

    $orm->flush();
    $orm->actAs('acme', static function () use ($orm): void {
        // Eagerly, nested: no lazy load at all.
        $loads = new LazyLoads();
        $orm->recordLazyLoads($loads);
        $eager = $orm->query(Product::class)->with('tags.products')->orderBy('name')->fetch();
        expect(names($eager[0]->tags))->toBe(['sale', 'steel'])
            ->and(names($eager[0]->tags[0]->products))->toBe(['kettle'])
            ->and($loads->count())->toBe(0);

        // Across the relation.
        expect(names($orm->query(Product::class)->whereHas('tags', static fn ($q) => $q->where('name', 'red'))->fetch()))->toBe(['toaster'])
            ->and(names($orm->query(Product::class)->where('tags.name', 'steel')->fetch()))->toBe(['kettle'])
            ->and(names($orm->query(Product::class)->whereHas('tags', static fn ($q) => $q->where('name', 'aaa-theirs'))->fetch()))->toBe([])
            ->and(names($orm->query(Product::class)->whereDoesntHave('tags', static fn ($q) => $q->where('name', 'sale'))->fetch()))->toBe(['toaster']);
        $counted = $orm->query(Product::class)->withCount('tags')->orderBy('name')->fetchAggregated();
        expect(array_map(static fn ($row): int => (int) $row->values['tagsCount'], $counted))->toBe([2, 1]);
    });

    // detach() and sync(): what changed, audited, and the loaded collection follows.
    $orm->flush();
    $log->changes = [];
    $kettle = $orm->actAs('acme', static fn (): Product => $orm->find(Product::class, $kettle->id));
    expect($orm->actAs('acme', static fn (): array => names($kettle->tags)))->toBe(['sale', 'steel']);
    $detached = $orm->actAs('acme', static fn (): PivotChange => $orm->detach($kettle, 'tags', [$sale, $red]));
    expect($detached->detached)->toBe([$sale->id])->and($detached->attached)->toBe([])
        ->and($log->kinds())->toBe(['deleted:ProductTag'])
        ->and($orm->actAs('acme', static fn (): array => names($kettle->tags)))->toBe(['steel']);

    $log->changes = [];
    $synced = $orm->actAs('acme', static fn (): PivotChange => $orm->sync($kettle, 'tags', [$red, $sale]));
    expect($synced->attached)->toBe([$red->id, $sale->id])->and($synced->detached)->toBe([$steel->id])
        ->and($log->kinds())->toBe(['deleted:ProductTag', 'created:ProductTag', 'created:ProductTag'])
        ->and($orm->actAs('acme', static fn (): array => names($kettle->tags)))->toBe(['red', 'sale']);

    // One transaction: a pivot row the log cannot record takes the whole sync back.
    $log->fail = true;
    expect(static fn () => $orm->actAs('acme', static fn (): PivotChange => $orm->sync($kettle, 'tags', [$steel])))
        ->toThrow(\RuntimeException::class, 'the log is full');
    $log->fail = false;
    expect($orm->actAs('acme', static fn (): array => names($kettle->tags)))->toBe(['red', 'sale']);
    $orm->flush();
    expect($orm->actAs('acme', static fn (): array => names($orm->find(Product::class, $kettle->id)?->tags ?? [])))->toBe(['red', 'sale']);

    // Across the tenant line: refused, worded as a missing row, nothing written.
    $log->changes = [];
    expect(static fn () => $orm->actAs('acme', static fn (): PivotChange => $orm->attach($kettle, 'tags', [$steel, $theirs])))
        ->toThrow(MissingRow::class, 'belongs to another tenant')
        ->and(static fn () => $orm->actAs('acme', static fn (): PivotChange => $orm->attach($kettle, 'tags', [$theirs->id])))
        ->toThrow(MissingRow::class)
        ->and(static fn () => $orm->actAs('acme', static fn (): PivotChange => $orm->attach($lamp, 'tags', [$sale])))
        ->toThrow(MissingRow::class)
        ->and(static fn () => $orm->actAs('globex', static fn (): PivotChange => $orm->sync($kettle, 'tags', [])))
        ->toThrow(MissingRow::class)
        ->and(static fn () => $orm->acrossTenants(static fn (): PivotChange => $orm->attach($kettle, 'tags', [$steel])))
        ->toThrow(\LogicException::class, 'actAs()')
        ->and($log->changes)->toBe([]);

    if ($raw !== null) {
        $orm->actAs('acme', $raw(...));
    }
}

it('compiles a many-to-many relation through its pivot model', function () {
    $relation = Metadata::for(Product::class)->relations['tags'];

    expect($relation->kind)->toBe(RelationKind::BelongsToMany)
        ->and($relation->kind->collects())->toBeTrue()
        ->and($relation->target)->toBe(Tag::class)
        ->and($relation->pivot?->class)->toBe(ProductTag::class)
        ->and($relation->pivot?->ownerProperty)->toBe('product')
        ->and($relation->pivot?->ownerColumn)->toBe('product_id')
        ->and($relation->pivot?->targetProperty)->toBe('tag')
        ->and($relation->pivot?->targetColumn)->toBe('tag_id')
        ->and(Metadata::for(Product::class)->knownIdentifiers())->not->toContain('product_id');
});

#[Table('m2m_open_products')]
final class OpenProduct extends Model
{
    #[Id] public private(set) string $id;

    /** @var Collection<Tag> */
    #[BelongsToMany(Tag::class, through: OpenLink::class)]
    public Collection $tags;
}

/** A pivot that forgot #[TenantScoped], between tenant-scoped rows. */
#[Table('m2m_open_links')]
final class OpenLink extends Model
{
    #[Id] public private(set) string $id;
    #[BelongsTo] public OpenProduct $product;
    #[BelongsTo] public Tag $tag;
}

#[Table('m2m_people')]
final class Person extends Model
{
    #[Id] public private(set) string $id;

    /** @var Collection<Person> */
    #[BelongsToMany(Person::class, through: Follow::class)]
    public Collection $follows;

    /** @var Collection<Person> */
    #[BelongsToMany(Person::class, through: Follow::class, from: 'follower', to: 'followed')]
    public Collection $following;
}

#[Table('m2m_follows')]
final class Follow extends Model
{
    #[Id] public private(set) string $id;
    #[BelongsTo] public Person $follower;
    #[BelongsTo] public Person $followed;
}

#[Table('m2m_no_pivot')]
final class Unlinked extends Model
{
    #[Id] public private(set) string $id;

    /** @var Collection<Tag> */
    #[BelongsToMany(Tag::class, through: Follow::class)]
    public Collection $tags;
}

it('refuses a pivot that would let a tenant-scoped side be linked across tenants', function () {
    expect(static fn () => Metadata::for(OpenProduct::class))
        ->toThrow(MappingException::class, 'OpenLink is not #[TenantScoped]');
});

it('asks which pivot relation is which when the pivot cannot say', function () {
    expect(static fn () => Metadata::for(Person::class))->toThrow(MappingException::class, "name them with from: and to:");
});

it('refuses a pivot with no #[BelongsTo] to either side', function () {
    expect(static fn () => Metadata::for(Unlinked::class))->toThrow(MappingException::class, 'no #[BelongsTo]');
});

it('makes a pivot table whose tenant column leads its unique key, and refuses tenant() after it', function () {
    $connection = new PdoConnection(new \PDO('sqlite::memory:'));
    tables($connection, new SqliteDialect());
    $indexes = $connection->select("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'm2m_product_tags' AND sql LIKE '%UNIQUE%'");
    $table = $connection->select("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'm2m_product_tags'")[0]['sql'];

    expect($indexes)->toHaveCount(1)
        ->and((string) $indexes[0]['sql'])->toContain('"tenant_id", "product_id", "tag_id"')
        ->and((string) $table)->toContain('REFERENCES "m2m_products" ("id")')
        ->and((string) $table)->toContain('REFERENCES "m2m_tags" ("id")');

    expect(static fn () => new Schema($connection, new SqliteDialect())->create('late', static function (SchemaTable $table): void {
        $table->uuid('id')->primary();
        $table->pivot(Product::class, Tag::class);
        $table->tenant();
    }))->toThrow(\LogicException::class, 'before pivot()');
});

it('reads, filters, counts and writes a many-to-many relation alike on SQLite', function () {
    behavesAlike(new PdoConnection(new \PDO('sqlite::memory:')), new SqliteDialect());
});

it('reads, filters, counts and writes a many-to-many relation alike on MySQL', function () {
    Databases::inScratchSchema('mysql', static function (\PDO $pdo): void {
        $pdo->exec("SET SESSION sql_mode = CONCAT(@@sql_mode, ',ANSI_QUOTES')");
        behavesAlike(new PdoConnection($pdo, 'mysql'), new MySqlDialect());
    });
})->group('mysql')->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');

it('reads, filters, counts and writes a many-to-many relation alike on Postgres, the pivot under row-level security', function () {
    Databases::inScratchSchema('pgsql', static function (\PDO $pdo, string $schema): void {
        $role = 'tinvar_rls_' . bin2hex(random_bytes(4));
        $pdo->exec("CREATE ROLE {$role} NOLOGIN");
        $pdo->exec("GRANT USAGE, CREATE ON SCHEMA {$schema} TO {$role}");
        $pdo->exec("SET ROLE {$role}");
        try {
            $connection = new PdoConnection($pdo, 'pgsql');
            behavesAlike($connection, new PostgresDialect(), static function () use ($connection): void {
                // Around the ORM, the policy still holds the pivot to acme's rows:
                // kettle's two and toaster's two, never globex's on kettle.
                expect((int) $connection->select('SELECT COUNT(*) AS n FROM m2m_product_tags')[0]['n'])->toBe(4);
            });
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
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

it('makes links with the pivot\'s own values, refuses what it cannot link, and reads every link across tenants', function () {
    $connection = new PdoConnection(new \PDO('sqlite::memory:'));
    tables($connection, new SqliteDialect());
    $orm = orm($connection, new Log());
    [$kettle, $sale] = $orm->actAs('acme', static fn (): array => [product($orm, 'kettle'), tag($orm, 'sale')]);
    $theirs = $orm->actAs('globex', static fn (): Tag => tag($orm, 'theirs'));
    $orm->actAs('globex', static function () use ($orm, $kettle, $theirs): void {
        $link = new ProductTag();
        $link->product = $kettle;
        $link->tag = $theirs;
        $orm->save($link);
    });

    $orm->actAs('acme', static fn (): PivotChange => $orm->attach($kettle, 'tags', [$sale], ['weight' => 7]));
    expect($connection->select('SELECT weight FROM m2m_product_tags WHERE tag_id = ?', [$sale->id])[0]['weight'])->toEqual(7);

    expect(static fn () => $orm->actAs('acme', static fn (): PivotChange => $orm->attach($kettle, 'tags', [$sale], ['id' => 'x'])))
        ->toThrow(\InvalidArgumentException::class, "'id' is no property")
        ->and(static fn () => $orm->actAs('acme', static fn (): PivotChange => $orm->attach($kettle, 'tags', [$sale], ['tag' => $sale])))
        ->toThrow(\InvalidArgumentException::class, "'tag' is no property")
        ->and(static fn () => $orm->actAs('acme', static fn (): PivotChange => $orm->attach($kettle, 'tags', [$kettle])))
        ->toThrow(\InvalidArgumentException::class, 'was handed a')
        ->and(static fn () => $orm->actAs('acme', static fn (): PivotChange => $orm->attach($kettle, 'name', [$sale])))
        ->toThrow(\LogicException::class, "no #[BelongsToMany] 'name'")
        ->and(static fn () => $orm->actAs('acme', static fn (): PivotChange => $orm->attach(new Product(), 'tags', [$sale])))
        ->toThrow(\LogicException::class, 'save the');

    // Read across tenants, the relation is every link's: the pivot and the
    // target unscoped alike, and nothing of it kept for a scoped read after.
    $orm->flush();
    expect($orm->acrossTenants(static fn (): array => names($orm->query(Product::class)->where('name', 'kettle')->fetch()[0]->tags)))
        ->toBe(['sale', 'theirs']);
    $orm->flush();
    expect($orm->actAs('acme', static fn (): array => names($orm->find(Product::class, $kettle->id)?->tags ?? [])))->toBe(['sale']);
});

it('batches a render\'s many-to-many loads across its fibers into one statement', function () {
    $connection = new PdoConnection(new \PDO('sqlite::memory:'));
    tables($connection, new SqliteDialect());
    $orm = orm($connection, new Log());
    $orm->actAs('acme', static function () use ($orm): void {
        [$kettle, $toaster, $sale, $red] = [product($orm, 'kettle'), product($orm, 'toaster'), tag($orm, 'sale'), tag($orm, 'red')];
        $orm->attach($kettle, 'tags', [$sale]);
        $orm->attach($toaster, 'tags', [$red, $sale]);
    });
    $orm->flush();

    $orm->actAs('acme', static function () use ($orm, $connection): void {
        $kettle = $orm->query(Product::class)->where('name', 'kettle')->fetch()[0];
        $toaster = $orm->query(Product::class)->where('name', 'toaster')->fetch()[0];
        $before = $connection->statementCount();
        $scheduler = $orm->beginRender();
        $results = $scheduler->run([
            'kettle' => static fn (): array => names($kettle->tags),
            'toaster' => static fn (): array => names($toaster->tags),
        ]);
        $orm->endRender();

        expect($results)->toBe(['kettle' => ['sale'], 'toaster' => ['red', 'sale']])
            ->and($scheduler->rounds())->toBe(1)
            ->and($connection->statementCount() - $before)->toBe(1);
    });
});

it('makes the pivot table make:migration would make for the pivot model', function () {
    $connection = new PdoConnection(new \PDO('sqlite::memory:'));
    tables($connection, new SqliteDialect());

    expect(new MigrationGenerator($connection, new SqliteDialect())->generate([Product::class, Tag::class, ProductTag::class], '2026-11-08'))->toBeNull();
});
