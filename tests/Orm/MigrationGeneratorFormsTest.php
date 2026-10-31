<?php

declare(strict_types=1);

/**
 * make:migration writing the builder's short forms: a moment as
 * timestamp(), the two the ORM stamps as timestamps(), a key as
 * foreign(Model::class) with its delete action as a word of the builder's,
 * and a backed enum as enum() -- and reading each back from the database
 * as the same thing, so a migration it wrote leaves nothing to find.
 */

namespace Tinvar\Tests\Orm\MigrationGeneratorForms;

use Carbon\CarbonImmutable;
use Tinvar\Orm\Attributes\BelongsTo;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\HasMany;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Collection;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Migrations\MigrationGenerator;
use Tinvar\Orm\Migrations\Migrator;
use Tinvar\Orm\Model;
use Tinvar\Orm\OnDelete;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\MySqlDialect;
use Tinvar\Orm\Schema\PostgresDialect;
use Tinvar\Orm\Schema\SqliteDialect;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Orm\Fixtures\MigrationFiles;

require_once __DIR__ . '/Fixtures/migrations.php';
require_once __DIR__ . '/Fixtures/databases.php';

enum Stage: string
{
    case Placed = 'placed';
    case Shipped = 'shipped';
}

enum Urgency: int
{
    case Low = 1;
    case High = 2;
}

#[Table('form_buyers')]
final class Buyer extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;

    /** @var Collection<Note> */
    #[HasMany(Note::class)]
    public Collection $notes;
}

#[Table('form_parcels')]
final class Parcel extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public Stage $stage = Stage::Placed;
    #[Column] public ?Urgency $urgency = null;
    #[Column] public CarbonImmutable $placedAt;
    #[Column] public ?CarbonImmutable $shippedAt = null;
    #[Column] public private(set) CarbonImmutable $createdAt;
    #[Column] public private(set) CarbonImmutable $updatedAt;

    #[BelongsTo(onDelete: OnDelete::Cascade)] public Buyer $buyer;
    #[BelongsTo(foreignKey: 'gift_from_id', onDelete: 'set null')] public ?Buyer $giftFrom = null;
    #[BelongsTo(foreignKey: 'checked_by_id', onDelete: OnDelete::NoAction)] public ?Buyer $checkedBy = null;
}

/** Notes have no relation back: the key comes from the buyer's #[HasMany]. */
#[Table('form_notes')]
final class Note extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $body;
}

/** The parcels table, a stage further on than its check. */
enum LaterStage: string
{
    case Placed = 'placed';
    case Shipped = 'shipped';
    case Returned = 'returned';
}

#[Table('form_parcels')]
final class LaterParcel extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public LaterStage $stage = LaterStage::Placed;
    #[Column] public ?Urgency $urgency = null;
    #[Column] public CarbonImmutable $placedAt;
    #[Column] public ?CarbonImmutable $shippedAt = null;
    #[Column] public private(set) CarbonImmutable $createdAt;
    #[Column] public private(set) CarbonImmutable $updatedAt;

    #[BelongsTo(Buyer::class, onDelete: OnDelete::Cascade)] public Buyer $buyer;
    #[BelongsTo(Buyer::class, foreignKey: 'gift_from_id', onDelete: 'set null')] public ?Buyer $giftFrom = null;
    #[BelongsTo(Buyer::class, foreignKey: 'checked_by_id', onDelete: OnDelete::NoAction)] public ?Buyer $checkedBy = null;
}

const MODELS = [Buyer::class, Parcel::class, Note::class];

/** Runs $source as the next migration. */
function migrate(MigrationFiles $files, PdoConnection $connection, string $source): void
{
    file_put_contents("{$files->directory}/2026_10_31_000001_forms.php", $source);
    new Migrator($connection, $files->directory)->migrate();
}

it('writes a moment, the stamped pair, a key from its model and an enum in the builder\'s short forms', function () {
    $files = new MigrationFiles();
    $generator = new MigrationGenerator(PdoConnection::sqlite($files->database(), create: true), new SqliteDialect());

    $source = (string) $generator->generate(MODELS, '2026-10-31');

    expect($source)->toContain(<<<'PHP'
                $schema->create('form_parcels', function (Table $table): void {
                    $table->uuid('id')->primary();
                    $table->enum('stage', \Tinvar\Tests\Orm\MigrationGeneratorForms\Stage::class)->default('placed');
                    $table->enum('urgency', \Tinvar\Tests\Orm\MigrationGeneratorForms\Urgency::class)->nullable();
                    $table->timestamp('placed_at');
                    $table->timestamp('shipped_at')->nullable();
                    $table->timestamps();
                    $table->foreign(\Tinvar\Tests\Orm\MigrationGeneratorForms\Buyer::class)->cascadeOnDelete();
                    $table->foreign(\Tinvar\Tests\Orm\MigrationGeneratorForms\Buyer::class, 'gift_from_id')->nullable()->nullOnDelete();
                    $table->foreign(\Tinvar\Tests\Orm\MigrationGeneratorForms\Buyer::class, 'checked_by_id')->nullable()->onDelete(\Tinvar\Orm\OnDelete::NoAction);
        PHP)
        ->toContain("\$table->foreign(\\Tinvar\\Tests\\Orm\\MigrationGeneratorForms\\Buyer::class);\n")
        ->not->toContain('->references(');
});

it('writes a migration that, run, makes what the models say and leaves nothing to find', function () {
    $files = new MigrationFiles();
    $connection = PdoConnection::sqlite($files->database(), create: true);
    $generator = new MigrationGenerator($connection, new SqliteDialect());

    migrate($files, $connection, (string) $generator->generate(MODELS, '2026-10-31'));

    expect($generator->generate(MODELS, '2026-10-31'))->toBeNull()
        ->and($generator->warnings)->toBe([])
        ->and(fn () => $connection->execute("INSERT INTO form_buyers (id, name) VALUES ('b', 'Ada')"))->not->toThrow(\PDOException::class)
        ->and(fn () => $connection->execute(
            "INSERT INTO form_parcels (id, stage, placed_at, created_at, updated_at, buyer_id) VALUES ('p', 'lost', 'x', 'x', 'x', 'b')",
        ))->toThrow(\PDOException::class, 'CHECK constraint failed');

    // And the ORM reads and writes through what it made.
    $orm = new Orm($connection, new IdentityMap('test'));
    $buyer = new Buyer();
    $buyer->name = 'Grace';
    $parcel = new Parcel();
    $parcel->placedAt = new CarbonImmutable('2026-10-31 12:00', 'UTC');
    $parcel->buyer = $orm->save($buyer);
    $orm->save($parcel);
    $orm->flush();

    $back = $orm->find(Parcel::class, $parcel->id) ?? throw new \LogicException('no parcel');
    expect($back->stage)->toBe(Stage::Placed)
        ->and($back->placedAt->toIso8601ZuluString())->toBe('2026-10-31T12:00:00Z');
});

it('says when the database\'s check leaves out a case the enum has, and writes no change for it', function () {
    $files = new MigrationFiles();
    $connection = PdoConnection::sqlite($files->database(), create: true);
    $generator = new MigrationGenerator($connection, new SqliteDialect());
    migrate($files, $connection, (string) $generator->generate(MODELS, '2026-10-31'));

    expect($generator->generate([Buyer::class, LaterParcel::class, Note::class], '2026-10-31'))->toBeNull()
        ->and(implode("\n", $generator->warnings))->toContain(
            "form_parcels.stage holds one of LaterStage's values on the model, and the database's check does not take 'returned'.",
        );
});

it('reads Postgres\'s own spelling of an enum\'s check as the enum\'s', function () {
    Databases::inScratchSchema('pgsql', static function (\PDO $pdo, string $schema): void {
        $pdo->exec("SET search_path TO {$schema}, public");
        $connection = new PdoConnection($pdo, 'pgsql');
        $files = new MigrationFiles();
        $generator = new MigrationGenerator($connection, new PostgresDialect());

        migrate($files, $connection, (string) $generator->generate(MODELS, '2026-10-31'));

        expect($generator->generate(MODELS, '2026-10-31'))->toBeNull()
            ->and($generator->warnings)->toBe([]);
    });
})->skip(fn () => Databases::postgresMissing() ?? false);

it('reads MySQL\'s own spelling of an enum\'s check as the enum\'s', function () {
    Databases::inScratchSchema('mysql', static function (\PDO $pdo): void {
        $pdo->exec("SET SESSION sql_mode = CONCAT(@@sql_mode, ',ANSI_QUOTES')");
        $connection = new PdoConnection($pdo, 'mysql');
        $files = new MigrationFiles();
        $generator = new MigrationGenerator($connection, new MySqlDialect());

        migrate($files, $connection, (string) $generator->generate(MODELS, '2026-10-31'));

        expect($generator->generate(MODELS, '2026-10-31'))->toBeNull()
            ->and($generator->warnings)->toBe([]);
    });
})->skip(fn () => Databases::mysqlMissing() ?? false);
