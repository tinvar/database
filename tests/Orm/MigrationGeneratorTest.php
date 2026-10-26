<?php

declare(strict_types=1);

/**
 * make:migration's half that thinks: "diffs compiled metadata against the
 * live database and writes a migration file to review and commit"
 * (tinvar-spec.md, "Migrations"). On SQLite here; the tenant policy it
 * writes for Postgres is in the Postgres migration tests.
 */

namespace Tinvar\Tests\Orm\MigrationGenerator;

use Tinvar\Orm\Attributes\BelongsTo;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\HasMany;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\TenantScoped;
use Tinvar\Orm\Collection;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Migrations\MigrationFailed;
use Tinvar\Orm\Migrations\MigrationGenerator;
use Tinvar\Orm\Migrations\Migrator;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\SqliteDialect;
use Tinvar\Tests\Orm\Fixtures\MigrationFiles;

require_once __DIR__ . '/Fixtures/migrations.php';

#[Table('gen_sites')]
final class Site extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $code;
    #[Column] public int $floors = 1;
    #[Column] public ?string $note;

    /** @var Collection<Rack> */
    #[HasMany(Rack::class, orderBy: 'name')]
    public Collection $racks;
}

#[Table('gen_racks'), TenantScoped]
final class Rack extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;
    #[Column] public bool $active = true;
    #[Column] public float $load;
    /** @var list<string> */
    #[Column] public array $tags;
    /** @var array<string, mixed> */
    #[Column(type: 'jsonb')] public array $layout;
    #[Column] public \DateTimeImmutable $installedAt;

    #[BelongsTo] public Site $site;
    #[BelongsTo(Site::class, foreignKey: 'backup_site_id')] public ?Site $backupSite;
}

/**
 * A model whose column name, from its own attribute, would end a comment
 * and close PHP if it were written into one as it is.
 */
#[Table('gen_hostile')]
final class Hostile extends Model
{
    #[Id] public private(set) string $id;
    #[Column(name: "x?>\nsystem('id');//")] public string $x;
}

/**
 * What in $source would run something a name smuggled in: a call to
 * system(), or PHP closed -- and anything after it.
 *
 * @return list<string>
 */
function smuggled(string $source): array
{
    $found = [];
    foreach (\PhpToken::tokenize($source) as $i => $token) {
        if ($token->is([T_CLOSE_TAG, T_INLINE_HTML, T_OPEN_TAG_WITH_ECHO])
            || ($token->is(T_OPEN_TAG) && $i > 0)
            || ($token->is(T_STRING) && strtolower($token->text) === 'system')) {
            $found[] = "{$token->getTokenName()} {$token->text}";
        }
    }

    return $found;
}

/** @return array{int, string} php -l's exit status and what it said about $path */
function lint(string $path): array
{
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $status);

    return [$status, implode("\n", $output)];
}

/** @return array{MigrationFiles, PdoConnection, MigrationGenerator} */
function setUp(): array
{
    $files = new MigrationFiles();
    $connection = PdoConnection::sqlite($files->database(), create: true);

    return [$files, $connection, new MigrationGenerator($connection, new SqliteDialect())];
}

const MODELS = [Rack::class, Site::class];

it('writes the tables the models describe, a referenced table before the tables referencing it', function () {
    [, , $generator] = setUp();

    $source = (string) $generator->generate(MODELS, '2026-10-24');

    expect($source)->toContain(<<<'PHP'
                $schema->create('gen_sites', function (Table $table): void {
                    $table->uuid('id')->primary();
                    $table->string('code');
                    $table->integer('floors')->default(1);
                    $table->string('note')->nullable();
                });

                $schema->create('gen_racks', function (Table $table): void {
                    $table->uuid('id')->primary();
                    $table->string('name');
                    $table->boolean('active')->default(true);
                    $table->float('load');
                    $table->json('tags');
                    $table->json('layout');
                    $table->timestamp('installed_at');
                    $table->uuid('site_id')->references('gen_sites');
                    $table->uuid('backup_site_id')->nullable()->references('gen_sites');
                    $table->tenant('tenant_id');
                    $table->index(['backup_site_id']);
                    $table->index(['site_id', 'name']);
                });
        PHP)
        ->toContain(<<<'PHP'
            public function down(Schema $schema): void
            {
                $schema->drop('gen_racks');
                $schema->drop('gen_sites');
            }
        PHP)
        ->toContain("and the sqlite database on 2026-10-24.");
});

// The point of the review: run, the migration leaves the database as the
// models describe it, and the next diff finds nothing.
it('writes a migration that, run, leaves nothing for the next diff to find', function () {
    [$files, $connection, $generator] = setUp();
    file_put_contents("{$files->directory}/2026_10_24_000001_create_gen.php", (string) $generator->generate(MODELS, '2026-10-24'));

    $migrator = new Migrator($connection, $files->directory);
    $migrator->migrate();

    expect($generator->generate(MODELS, '2026-10-24'))->toBeNull()
        ->and(array_keys($migrator->dialect()->columns($connection, 'gen_racks')))->toBe([
            'id', 'name', 'active', 'load', 'tags', 'layout', 'installed_at', 'site_id', 'backup_site_id', 'tenant_id',
        ])
        ->and($migrator->rollback())->toBe(['2026_10_24_000001_create_gen'])
        ->and($migrator->dialect()->tables($connection))->toBe(['tinvar_migrations']);
});

it('writes a migration the ORM can then read and write through', function () {
    [$files, $connection, $generator] = setUp();
    file_put_contents("{$files->directory}/2026_10_24_000001_create_gen.php", (string) $generator->generate(MODELS, '2026-10-24'));
    new Migrator($connection, $files->directory)->migrate();
    $orm = new Orm($connection, new IdentityMap('gen'));

    $site = new Site();
    $site->code = 'LAX';
    $site->note = null;
    $orm->save($site);

    $rack = $orm->actAs('acme', static function () use ($orm, $site): Rack {
        $rack = new Rack();
        $rack->name = 'R-01';
        $rack->load = 1.5;
        $rack->tags = ['edge'];
        $rack->layout = ['u' => 42];
        $rack->installedAt = new \DateTimeImmutable('2026-10-24 12:00:00');
        $rack->site = $site;
        $rack->backupSite = null;

        return $orm->save($rack);
    });

    expect($connection->select('SELECT name, active, tenant_id, site_id FROM gen_racks'))->toBe([
        ['name' => 'R-01', 'active' => 1, 'tenant_id' => 'acme', 'site_id' => $site->id],
    ])->and($rack->id)->not->toBe('');
});

it('adds what an existing table lacks, names what it has that no model maps, and drops nothing', function () {
    [, $connection, $generator] = setUp();
    $connection->execute('CREATE TABLE gen_sites (id TEXT PRIMARY KEY, code TEXT NOT NULL, legacy INTEGER)');

    $source = (string) $generator->generate([Site::class], '2026-10-24');

    expect($source)->toContain(<<<'PHP'
                // gen_sites.legacy is in the database and on no model. Dropping it is yours to decide:
                // $table->dropColumn('legacy').
                $schema->table('gen_sites', function (Table $table): void {
                    $table->integer('floors')->default(1);
                    $table->string('note')->nullable();
                });
        PHP)
        ->toContain(<<<'PHP'
                $schema->table('gen_sites', function (Table $table): void {
                    $table->dropColumn('floors', 'note');
                });
        PHP)
        ->not->toContain('drop(');
});

it('says when a column it adds needs a default the rows already there do not have', function () {
    [, $connection, $generator] = setUp();
    $connection->execute('CREATE TABLE gen_sites (id TEXT PRIMARY KEY, floors INTEGER NOT NULL, note TEXT)');

    expect((string) $generator->generate([Site::class], '2026-10-24'))
        ->toContain('// gen_sites.code is NOT NULL with no default: the rows already there need one')
        ->toContain("\$table->string('code');");
});

it('adds the indexes a relation loads by to a table that lacks them, and the tenant column with its own', function () {
    [, $connection, $generator] = setUp();
    $connection->execute('CREATE TABLE gen_sites (id TEXT PRIMARY KEY, code TEXT NOT NULL, floors INTEGER NOT NULL DEFAULT 1, note TEXT)');
    $connection->execute(
        'CREATE TABLE gen_racks (id TEXT PRIMARY KEY, name TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1, load REAL NOT NULL, tags TEXT NOT NULL, '
        . 'layout TEXT NOT NULL, installed_at TEXT NOT NULL, site_id TEXT NOT NULL, backup_site_id TEXT)',
    );
    // A longer index starting with the column does the shorter one's work.
    $connection->execute('CREATE INDEX by_backup ON gen_racks (backup_site_id, name)');

    $source = (string) $generator->generate(MODELS, '2026-10-24');

    expect($source)->toContain(<<<'PHP'
                $schema->table('gen_racks', function (Table $table): void {
                    $table->tenant('tenant_id');
                    $table->index(['site_id', 'name']);
                });
        PHP)
        ->toContain(<<<'PHP'
                    $table->dropIndex('gen_racks_tenant_id');
                    $table->dropIndex('gen_racks_site_id_name');
                    $table->dropColumn('tenant_id');
        PHP);
});

it('finds nothing to write for a database that has everything', function () {
    [, $connection, $generator] = setUp();
    $connection->execute('CREATE TABLE gen_sites (id TEXT PRIMARY KEY, code VARCHAR(40) NOT NULL, floors INTEGER NOT NULL DEFAULT (1), note TEXT, legacy TEXT)');

    // A column no model maps is said beside a change, and is not one; a
    // type spelled another way, of the same family, is no change either.
    expect($generator->generate([Site::class], '2026-10-24'))->toBeNull();
});

/** The `//` comments in $source, as one line of text: what they say, however they wrap. */
function comments(string $source): string
{
    preg_match_all('~^ *// (.*)$~m', $source, $lines);

    return implode(' ', $lines[1]);
}

/** Writes $source as a migration, runs it, and hands back the migrator that ran it. */
function migrated(MigrationFiles $files, PdoConnection $connection, string $source, string $name = '2026_10_24_000001_change'): Migrator
{
    file_put_contents("{$files->directory}/{$name}.php", $source);
    $migrator = new Migrator($connection, $files->directory);
    expect($migrator->migrate())->toBe([$name]);

    return $migrator;
}

describe('a table that has changed on the model', function () {
    it('changes a column whose type, nullability or default the model has changed, and changes it back', function () {
        [$files, $connection, $generator] = setUp();
        $connection->execute("CREATE TABLE gen_sites (id TEXT PRIMARY KEY, code TEXT, floors TEXT NOT NULL DEFAULT '2', note TEXT NOT NULL)");
        $connection->execute("INSERT INTO gen_sites VALUES ('s1', 'LAX', '3', 'n')");

        $source = (string) $generator->generate([Site::class], '2026-10-24');

        expect($source)->toContain(<<<'PHP'
                    $schema->table('gen_sites', function (Table $table): void {
                        $table->text('code')->change();
                        $table->integer('floors')->default(1)->change();
                        $table->text('note')->nullable()->change();
                    });
            PHP)
            ->toContain(<<<'PHP'
                    $schema->table('gen_sites', function (Table $table): void {
                        $table->text('code')->nullable()->change();
                        $table->text('floors')->default('2')->change();
                        $table->text('note')->change();
                    });
            PHP)
            ->and(comments($source))->toContain('gen_sites.code takes NULL in the database and not on the model: a row holding NULL fails this')
            ->toContain('gen_sites.floors is text in the database and integer on the model.');

        $migrator = migrated($files, $connection, $source);
        $columns = $migrator->dialect()->columns($connection, 'gen_sites');

        expect($columns['floors'])->toMatchArray(['type' => 'INTEGER', 'nullable' => false, 'default' => 1])
            ->and($columns['note']['nullable'])->toBeTrue()
            ->and($connection->select('SELECT floors FROM gen_sites'))->toBe([['floors' => 3]])
            ->and($generator->generate([Site::class], '2026-10-24'))->toBeNull()
            ->and($migrator->rollback())->toBe(['2026_10_24_000001_change'])
            ->and($migrator->dialect()->columns($connection, 'gen_sites')['floors'])->toMatchArray(['type' => 'TEXT', 'default' => '2']);
    });

    it('fails the change, and leaves the table as it was, where a row has a NULL the column no longer takes', function () {
        [$files, $connection, $generator] = setUp();
        $connection->execute('CREATE TABLE gen_sites (id TEXT PRIMARY KEY, code TEXT, floors INTEGER NOT NULL DEFAULT 1, note TEXT)');
        $connection->execute("INSERT INTO gen_sites VALUES ('s1', NULL, 1, NULL)");
        file_put_contents("{$files->directory}/2026_10_24_000001_change.php", (string) $generator->generate([Site::class], '2026-10-24'));

        expect(fn () => new Migrator($connection, $files->directory)->migrate())->toThrow(MigrationFailed::class, 'NOT NULL')
            ->and($connection->select('SELECT sql FROM sqlite_master WHERE name = ?', ['gen_sites'])[0]['sql'])
            ->toBe('CREATE TABLE gen_sites (id TEXT PRIMARY KEY, code TEXT, floors INTEGER NOT NULL DEFAULT 1, note TEXT)')
            ->and($connection->select('SELECT * FROM gen_sites'))->toHaveCount(1);
    });

    it('asks before it renames a column, and renames it, keeping its values, when told it is one', function () {
        [$files, $connection] = setUp();
        $connection->execute('CREATE TABLE gen_sites (id TEXT PRIMARY KEY, code TEXT NOT NULL, floors INTEGER NOT NULL DEFAULT 1, remark TEXT)');
        $connection->execute("INSERT INTO gen_sites VALUES ('s1', 'LAX', 1, 'kept')");
        $asked = [];
        $generator = new MigrationGenerator($connection, new SqliteDialect(), static function (string $question) use (&$asked): bool {
            $asked[] = $question;

            return true;
        });

        $source = (string) $generator->generate([Site::class], '2026-10-24');

        expect($asked)->toBe(["Is the column gen_sites.remark, on no model, the model's note renamed? Renamed, it keeps its values."])
            ->and($source)->toContain("\$table->renameColumn('remark', 'note');")
            ->and($source)->toContain("\$table->renameColumn('note', 'remark');")
            ->and($source)->not->toContain("\$table->string('note')");

        $migrator = migrated($files, $connection, $source);

        expect($connection->select('SELECT note FROM gen_sites'))->toBe([['note' => 'kept']])
            ->and($generator->generate([Site::class], '2026-10-24'))->toBeNull()
            ->and($migrator->rollback())->toBe(['2026_10_24_000001_change'])
            ->and($connection->select('SELECT remark FROM gen_sites'))->toBe([['remark' => 'kept']]);
    });

    it('adds the column, and names the one it may be a rename of, when nobody says it is one', function () {
        [, $connection, $generator] = setUp();
        $connection->execute('CREATE TABLE gen_sites (id TEXT PRIMARY KEY, code TEXT NOT NULL, floors INTEGER NOT NULL DEFAULT 1, remark TEXT)');

        $source = (string) $generator->generate([Site::class], '2026-10-24');

        expect($source)->toContain("\$table->string('note')->nullable();")
            ->and($source)->not->toContain("        \$table->renameColumn(")
            ->and(comments($source))->toContain(
                "gen_sites.remark is in the database and on no model, and gen_sites.note, alike in type and nullability, is on a model "
                . "and not in the database. If note is remark renamed, \$table->renameColumn('remark', 'note') in place of adding it keeps its values",
            );
    });

    it('renames a table the models name differently, when told it is one', function () {
        [$files, $connection] = setUp();
        $connection->execute('CREATE TABLE old_sites (id TEXT PRIMARY KEY, code TEXT NOT NULL, floors INTEGER NOT NULL DEFAULT 1, note TEXT)');
        $connection->execute("INSERT INTO old_sites VALUES ('s1', 'LAX', 2, NULL)");
        $generator = new MigrationGenerator($connection, new SqliteDialect(), static fn (string $question): bool => true);

        $source = (string) $generator->generate([Site::class], '2026-10-24');

        expect($source)->toContain("        \$schema->rename('old_sites', 'gen_sites');")
            ->and($source)->toContain("        \$schema->rename('gen_sites', 'old_sites');")
            ->and($source)->not->toContain('$schema->create(');

        $migrator = migrated($files, $connection, $source);

        expect($connection->select('SELECT code, floors FROM gen_sites'))->toBe([['code' => 'LAX', 'floors' => 2]])
            ->and($migrator->rollback())->toBe(['2026_10_24_000001_change'])
            ->and($migrator->dialect()->tables($connection))->toBe(['old_sites', 'tinvar_migrations']);
    });

    it('names an index no relation loads by any more, and drops none', function () {
        [, $connection, $generator] = setUp();
        $connection->execute('CREATE TABLE gen_sites (id TEXT PRIMARY KEY, code TEXT NOT NULL, floors INTEGER NOT NULL DEFAULT 1, note TEXT)');
        $connection->execute(
            'CREATE TABLE gen_racks (id TEXT PRIMARY KEY, name TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1, load REAL NOT NULL, tags TEXT NOT NULL, '
            . 'layout TEXT NOT NULL, installed_at TEXT NOT NULL, site_id TEXT NOT NULL, backup_site_id TEXT, tenant_id TEXT NOT NULL)',
        );
        foreach (['gen_racks_tenant_id (tenant_id)', 'gen_racks_backup_site_id (backup_site_id)', 'gen_racks_site_id_installed_at (site_id, installed_at)', 'by_install (site_id, installed_at)'] as $index) {
            $connection->execute(str_replace(' (', ' ON gen_racks (', "CREATE INDEX {$index}"));
        }

        $source = (string) $generator->generate(MODELS, '2026-10-24');

        expect(comments($source))->toContain(
            "gen_racks.gen_racks_site_id_installed_at is an index over (site_id, installed_at), which no relation of the models "
            . "loads by now. If nothing else uses it: \$table->dropIndex('gen_racks_site_id_installed_at').",
        )
            ->and($source)->toContain("\$table->index(['site_id', 'name']);")
            ->and($source)->not->toContain('by_install')
            ->and($source)->not->toContain("        \$table->dropIndex('gen_racks_site_id_installed_at')");
    });

    it('carries a literal default into the column it changes, and never one the database computes', function () {
        [$files, $connection, $generator] = setUp();
        $connection->execute("CREATE TABLE gen_sites (id TEXT PRIMARY KEY, code TEXT DEFAULT (datetime('now')), floors INTEGER NOT NULL DEFAULT 1, note TEXT NOT NULL DEFAULT 'it''s ?> here')");

        $source = (string) $generator->generate([Site::class], '2026-10-24');
        $path = "{$files->directory}/2026_10_24_000001_change.php";
        file_put_contents($path, $source);
        [$status, $said] = lint($path);

        expect($status)->toBe(0, $said)
            ->and(smuggled($source))->toBe([])
            ->and($source)->toContain("\$table->text('code')->change();")
            ->and($source)->toContain("\$table->text('code')->nullable()->change();")
            ->and($source)->toContain("\$table->text('note')->nullable()->default('it\\'s ?> here')->change();")
            ->and(comments($source))->toContain('gen_sites.code has a default the database computes, which this does not copy')
            ->and($source)->not->toContain('datetime');
    });
});

it('writes an empty migration for a backfill, which loads and runs', function () {
    [$files, $connection] = setUp();
    file_put_contents("{$files->directory}/2026_10_24_000001_backfill.php", MigrationGenerator::blank());

    $migrator = new Migrator($connection, $files->directory);

    expect($migrator->migrate())->toBe(['2026_10_24_000001_backfill'])
        ->and($migrator->rollback())->toBe(['2026_10_24_000001_backfill']);
});

// The file is code a developer runs, and a column's name is chosen by
// whoever could make the column: a tenant's own schema, or a database
// someone else got into.
describe('names the database gives', function () {
    it('leaves out a column whose name is not a plain identifier, and warns of it, rather than write it', function (string $column) {
        [$files, $connection, $generator] = setUp();
        $connection->execute('CREATE TABLE gen_sites (id TEXT PRIMARY KEY, code TEXT NOT NULL, "' . str_replace('"', '""', $column) . '" TEXT)');

        $source = (string) $generator->generate([Site::class], '2026-10-24');
        $path = "{$files->directory}/2026_10_24_000001_add_to_sites.php";
        file_put_contents($path, $source);
        [$status, $said] = lint($path);

        expect($status)->toBe(0, $said)
            ->and(smuggled($source))->toBe([])
            ->and($source)->not->toContain('system(')
            ->and($source)->toContain("\$table->integer('floors')->default(1);")
            ->and($generator->warnings)->toBe([
                'gen_sites has a column named ' . json_encode($column, JSON_UNESCAPED_SLASHES)
                . ', which is not a plain identifier and is on no model; it is left out of the migration. Look at it in the database.',
            ])
            // And it runs as the migration it says it is, and nothing else.
            ->and(new Migrator($connection, $files->directory)->migrate())->toBe(['2026_10_24_000001_add_to_sites']);
    })->with([
        'a line break, then a call' => "x\nsystem('id');//",
        'a closing tag' => "x?><?php system('id'); ?>",
        'a carriage return' => "x\rsystem('id');",
    ]);

    // SQLite keeps a declared type as written, whatever it is; the
    // migration changing the column back would carry it into the file.
    it('writes no type the database gives that could be anything but a type, and warns of it', function () {
        [$files, $connection, $generator] = setUp();
        $type = "evil?>\nsystem('id')";
        $connection->execute('CREATE TABLE gen_sites (id TEXT PRIMARY KEY, code TEXT NOT NULL, floors INTEGER NOT NULL DEFAULT 1, note "' . str_replace('"', '""', $type) . '" NOT NULL)');

        $source = (string) $generator->generate([Site::class], '2026-10-24');
        $path = "{$files->directory}/2026_10_24_000001_change.php";
        file_put_contents($path, $source);
        [$status, $said] = lint($path);

        expect($status)->toBe(0, $said)
            ->and(smuggled($source))->toBe([])
            ->and($source)->not->toContain('evil')
            ->and($source)->toContain("\$table->string('note')->nullable()->change();")
            ->and($generator->warnings)->toBe([
                'gen_sites.note is ' . json_encode($type, JSON_UNESCAPED_SLASHES)
                . ' in the database, which is not a type make:migration writes, so down() does not change it back; write that by hand.',
            ]);
    });

    // A type Column would take from a migration's author -- words, then a
    // length -- can still say more than a type: a key, a default.
    it('writes no type the database gives that holds a clause', function (string $type) {
        [, $connection, $generator] = setUp();
        $connection->execute('CREATE TABLE gen_sites (id TEXT PRIMARY KEY, code TEXT NOT NULL, floors INTEGER NOT NULL DEFAULT 1, note "' . $type . '" NOT NULL)');

        $source = (string) $generator->generate([Site::class], '2026-10-24');

        expect($source)->not->toContain($type)
            ->and($generator->warnings)->toHaveCount(1)
            ->and($generator->warnings[0])->toContain('is not one make:migration writes');
    })->with([
        'a foreign key' => ['text references gen_sites'],
        'a default' => ['text default 5'],
        'a check' => ['text check (1)'],
    ]);

    it('writes a plain identifier the database gives only as a quoted literal in a comment', function () {
        [$files, $connection, $generator] = setUp();
        $connection->execute('CREATE TABLE gen_sites (id TEXT PRIMARY KEY, code TEXT NOT NULL, legacy_flag INTEGER)');

        $source = (string) $generator->generate([Site::class], '2026-10-24');

        expect($source)->toContain("// \$table->dropColumn('legacy_flag').")
            ->and($generator->warnings)->toBe([]);
    });

    // A model's names are the application's own, but they reach the file
    // the same ways: as a literal, which nothing in it can end, and as
    // comment text, which can neither break its line nor close PHP.
    it('writes a model\'s name as a literal, and as comment text that cannot end the comment or PHP', function () {
        [$files, $connection, $generator] = setUp();
        $connection->execute('CREATE TABLE gen_hostile (id TEXT PRIMARY KEY)');

        $source = (string) $generator->generate([Hostile::class], '2026-10-24');
        $path = "{$files->directory}/2026_10_24_000001_add_to_hostile.php";
        file_put_contents($path, $source);
        [$status, $said] = lint($path);

        expect($status)->toBe(0, $said)
            ->and(smuggled($source))->toBe([])
            ->and($source)->toContain("// gen_hostile.x?\\>system('id');// is NOT NULL with no default")
            ->and($source)->toContain("\$table->string('x?>\nsystem(\\'id\\');//');");
    });

    it('cannot end the header\'s doc comment from the date it is given', function () {
        [, , $generator] = setUp();

        $source = (string) $generator->generate([Site::class], "today */ system('id'); /*");

        expect(smuggled($source))->toBe([])
            ->and($source)->toContain("today *\\/ system('id'); /*");
    });
});
