<?php

declare(strict_types=1);

/**
 * The migration commands: what they print, and what they answer with, for
 * a deploy script to act on. `bin/tinvar` hands them the shop's database
 * and models; the shop's own tests run them that way.
 */

namespace Tinvar\Tests\Orm\MigrateCommand;

use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Connection;
use Tinvar\Orm\Migrations\DatabaseSeeder;
use Tinvar\Orm\Migrations\MigrateCommand;
use Tinvar\Orm\Migrations\MigrationRefused;
use Tinvar\Orm\Migrations\Migrator;
use Tinvar\Orm\Migrations\ProductionMarker;
use Tinvar\Orm\Model;
use Tinvar\Orm\PdoConnection;
use Tinvar\Support\DevMode;
use Tinvar\Support\Uuid;
use Tinvar\Tests\Orm\Fixtures\MigrationFiles;

require_once __DIR__ . '/Fixtures/migrations.php';

#[Table('notes')]
final class Note extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $body;
}

final class Run
{
    /** @var list<string> */
    public array $out = [];

    /** @var list<string> */
    public array $err = [];

    public ?DatabaseSeeder $seeder = null;

    /** @var list<string> what make:migration asked */
    public array $asked = [];

    public bool $answer = false;

    /** @var list<array{string, int, int, string}> what each migration's progress said */
    public array $progress = [];

    /**
     * Laid over this process's environment with its production markers
     * taken out, so a TINVAR_ENV or APP_ENV in the shell running the suite
     * decides nothing; null takes a variable out. TINVAR_ENV=testing by
     * default, which is what lets migrate:fresh and db:seed run.
     *
     * @var array<string, string|null>
     */
    public array $environment = ['TINVAR_ENV' => 'testing'];

    public function __construct(public readonly MigrationFiles $files, public string $directory = '') {}

    /** @param list<string> $arguments */
    public function __invoke(string $command, array $arguments = [], int $now = 1_793_016_000): int
    {
        $this->out = [];
        $this->err = [];
        $database = $this->files->database();

        return new MigrateCommand(
            static fn (bool $create): Connection => PdoConnection::sqlite($database, create: $create),
            $this->directory === '' ? $this->files->directory : $this->directory,
            [Note::class],
            function (string $line): void {
                $this->out[] = $line;
            },
            function (string $line): void {
                $this->err[] = $line;
            },
            static fn (): int => $now,
            $this->seeder,
            function (string $question): bool {
                $this->asked[] = $question;

                return $this->answer;
            },
            array_filter(
                [...array_diff_key(getenv(), array_flip(DevMode::PRODUCTION_MARKERS)), ...$this->environment],
                \is_string(...),
            ),
            function (string $direction, int $done, int $total, string $name): void {
                $this->progress[] = [$direction, $done, $total, $name];
            },
        )->run($command, $arguments);
    }
}

/** Notes to seed, as many as --notes says. */
final class Notes implements DatabaseSeeder
{
    /** @var list<array<string, string>> the options each seed() was given */
    public array $given = [];

    public function options(): array
    {
        return ['notes' => 'how many notes'];
    }

    public function seed(Connection $connection, array $options, \Closure $out): void
    {
        $this->given[] = $options;
        $notes = (int) ($options['notes'] ?? 1);
        for ($i = 0; $i < $notes; $i++) {
            $connection->execute('INSERT INTO notes (id, body) VALUES (?, ?)', [Uuid::v7(), "note {$i}"]);
        }
        $out("Seeded {$notes} note(s).");
    }
}

function run(): Run
{
    $files = new MigrationFiles();
    // An empty database, as an application has before its first migration.
    PdoConnection::sqlite($files->database(), create: true);

    return new Run($files);
}

const CREATE_NOTES = "\$schema->create('notes', function (Table \$t): void { \$t->uuid('id')->primary(); \$t->string('body'); });";

it('migrates, says what it ran, and says when there is nothing to run', function () {
    $run = run();
    $run->files->add('2026_10_24_000001_create_notes', CREATE_NOTES, "\$schema->drop('notes');");

    expect($run('migrate'))->toBe(0)
        ->and($run->out[0])->toStartWith('migrated  2026_10_24_000001_create_notes (')
        ->and($run->out[1])->toBe('Ran 1 migration(s).')
        ->and($run('migrate'))->toBe(0)
        ->and($run->out)->toBe(['Nothing to migrate.']);
});

it('lists each migration and whether it has run', function () {
    $run = run();
    $run->files->add('2026_10_24_000001_create_notes', CREATE_NOTES, "\$schema->drop('notes');");
    $run('migrate');
    $run->files->add('2026_10_24_000002_backfill', '//');

    expect($run('migrate:status'))->toBe(0)
        ->and($run->out)->toBe([
            'Ran  Batch  Migration',
            'yes  1      2026_10_24_000001_create_notes',
            'no          2026_10_24_000002_backfill',
        ])
        ->and($run->err)->toBe([]);
});

it('rolls back the last batch, or the last N', function () {
    $run = run();
    $run->files->add('2026_10_24_000001_create_notes', CREATE_NOTES, "\$schema->drop('notes');");
    $run->files->add('2026_10_24_000002_backfill', '//', '//');
    $run('migrate');

    expect($run('migrate:rollback', ['--step=1']))->toBe(0)
        ->and($run->out[0])->toStartWith('rolled back 2026_10_24_000002_backfill')
        ->and($run('migrate:rollback'))->toBe(0)
        ->and($run->out[1])->toBe('Rolled back 1 migration(s).')
        ->and($run('migrate:rollback'))->toBe(0)
        ->and($run->out)->toBe(['Nothing to roll back.']);
});

it('answers a failed or refused run with 1, and says why', function () {
    $run = run();
    $run->files->add('2026_10_24_000001_create_notes', CREATE_NOTES);
    $run('migrate');

    expect($run('migrate:rollback'))->toBe(MigrateCommand::FAILED)
        ->and($run->err)->toBe(['2026_10_24_000001_create_notes has no down(), so it cannot be rolled back. Nothing was rolled back.']);

    $run->files->add('2026_10_24_000002_fail', "throw new \\RuntimeException('disk full');");

    expect($run('migrate'))->toBe(MigrateCommand::FAILED)
        ->and($run->err[0])->toContain('2026_10_24_000002_fail failed to migrate: disk full Nothing it did was kept.');
});

it('answers a bad invocation with 64', function () {
    $run = run();

    expect($run('migrate', ['--force']))->toBe(MigrateCommand::USAGE)
        ->and($run->err[0])->toBe("Unknown argument '--force'. Usage: tinvar migrate [--lock-timeout=…]")
        ->and($run('migrate:rollback', ['--step=none']))->toBe(MigrateCommand::USAGE)
        ->and($run('migrate', ['--lock-timeout=soon']))->toBe(MigrateCommand::USAGE)
        ->and($run('make:migration'))->toBe(MigrateCommand::USAGE)
        ->and($run('make:migration', ['Add Notes']))->toBe(MigrateCommand::USAGE)
        ->and($run('migrate:nothing'))->toBe(MigrateCommand::USAGE);
});

it('drops everything and migrates afresh', function () {
    $run = run();
    $run->files->add('2026_10_24_000001_create_notes', CREATE_NOTES);
    $run('migrate');

    expect($run('migrate:fresh', ['--force']))->toBe(0)
        ->and($run->out)->toContain('dropped   notes')
        ->and(end($run->out))->toBe('Dropped every table and ran 1 migration(s).');
});

// Every row goes with every table: a stray command must not get that far.
it('refuses to drop everything without --force', function () {
    $run = run();
    $run->files->add('2026_10_24_000001_create_notes', CREATE_NOTES);
    $run('migrate');

    expect($run('migrate:fresh'))->not->toBe(0)
        ->and(implode("\n", $run->err))->toContain('--force')
        ->and($run->out)->not->toContain('dropped   notes');
});

// Asked, where a person is there, rather than refused for want of a flag;
// the answer no where nobody is, which is the refusal above.
it('asks before dropping everything without --force, and drops it at yes', function () {
    $run = run();
    $run->files->add('2026_10_24_000001_create_notes', CREATE_NOTES);
    $run('migrate');
    $run->answer = true;

    expect($run('migrate:fresh'))->toBe(0, implode("\n", $run->err))
        ->and($run->asked)->toBe(['migrate:fresh: drop every table, and every row in them?'])
        ->and($run->out)->toContain('dropped   notes');

    $run->seeder = new Notes();
    expect($run('db:seed', ['--fresh']))->toBe(0)
        ->and($run->asked[1])->toBe('db:seed --fresh: drop every table, and every row in them?');
});

it('asks before rolling back where TINVAR_ENV does not say development, and takes back nothing at no', function (?string $environment) {
    $run = run();
    $run->environment = ['TINVAR_ENV' => $environment];
    $run->files->add('2026_10_24_000001_create_notes', CREATE_NOTES, "\$schema->drop('notes');");
    $run('migrate');

    expect($run('migrate:rollback'))->toBe(MigrateCommand::FAILED)
        ->and($run->asked)->toBe(['Roll back 1 migration(s) (2026_10_24_000001_create_notes), and what their down() drops with them?'])
        ->and($run->err[0])->toContain('Nothing was rolled back; run it again with --force to do that.')
        ->and(PdoConnection::sqlite($run->files->database())->select("SELECT name FROM sqlite_master WHERE name = 'notes'"))->toHaveCount(1);

    $run->answer = true;
    expect($run('migrate:rollback'))->toBe(0)
        ->and($run->out)->toContain('Rolled back 1 migration(s).');
})->with(['nothing set' => [null], 'staging' => ['staging']]);

it('rolls back without asking given --force, or where TINVAR_ENV says development, or with nothing to take back', function () {
    $run = run();
    $run->environment = ['TINVAR_ENV' => null];
    $run->files->add('2026_10_24_000001_create_notes', CREATE_NOTES, "\$schema->drop('notes');");

    expect($run('migrate:rollback'))->toBe(0)
        ->and($run->out)->toBe(['Nothing to roll back.']);
    $run('migrate');
    expect($run('migrate:rollback', ['--force']))->toBe(0);

    $run->environment = ['TINVAR_ENV' => 'local'];
    $run('migrate');
    expect($run('migrate:rollback'))->toBe(0)
        ->and($run->asked)->toBe([]);
});

it('tells its progress before the first migration and after each, up and down', function () {
    $run = run();
    $run->files->add('2026_10_24_000001_create_notes', CREATE_NOTES, "\$schema->drop('notes');");
    $run->files->add('2026_10_24_000002_backfill', '//', '//');

    $run('migrate');
    $run('migrate');
    $run('migrate:rollback');

    expect($run->progress)->toBe([
        ['up', 0, 2, ''],
        ['up', 1, 2, '2026_10_24_000001_create_notes'],
        ['up', 2, 2, '2026_10_24_000002_backfill'],
        ['down', 0, 2, ''],
        ['down', 1, 2, '2026_10_24_000002_backfill'],
        ['down', 2, 2, '2026_10_24_000001_create_notes'],
    ]);
});

it('refuses to drop everything in production, even with --force and --non-production', function (string $variable) {
    $run = run();
    $run->files->add('2026_10_24_000001_create_notes', CREATE_NOTES);
    $run('migrate');
    $run->environment = [$variable => 'Production', ...($variable === 'TINVAR_ENV' ? [] : ['TINVAR_ENV' => 'local'])];

    expect($run('migrate:fresh', ['--force', '--non-production']))->toBe(MigrateCommand::FAILED)
        ->and($run->err)->toBe([
            "migrate:fresh drops every table, and {$variable} says this is production. It is refused here, --non-production or not. "
            . "If this is not production, set {$variable} to something else.",
        ])
        ->and($run->out)->not->toContain('dropped   notes')
        ->and(PdoConnection::sqlite($run->files->database())->select('SELECT COUNT(*) AS n FROM tinvar_migrations'))->toBe([['n' => 1]]);
})->with(['TINVAR_ENV', 'APP_ENV', 'NODE_ENV']);

// Unset is not "not production": a server with no environment set is
// common, and the one place a stray migrate:fresh does the most harm.
it('refuses to drop everything where nothing says this is not production, and says what to set', function (?string $environment) {
    $run = run();
    $run->files->add('2026_10_24_000001_create_notes', CREATE_NOTES);
    $run('migrate');
    $run->environment = ['TINVAR_ENV' => $environment, 'APP_ENV' => 'local', 'NODE_ENV' => 'development'];

    expect($run('migrate:fresh', ['--force']))->toBe(MigrateCommand::FAILED)
        ->and($run->err)->toBe([
            'migrate:fresh drops every table, and nothing says this is not production. '
            . 'Set TINVAR_ENV=local (or development, or testing), or pass --non-production to say so for this run.',
        ])
        ->and($run->out)->not->toContain('dropped   notes');
})->with(['unset' => [null], 'empty' => [''], 'staging' => ['staging'], 'prod' => ['prod']]);

it('drops everything where TINVAR_ENV says this is not production', function (string $environment) {
    $run = run();
    $run->files->add('2026_10_24_000001_create_notes', CREATE_NOTES);
    $run('migrate');
    $run->environment = ['TINVAR_ENV' => $environment];

    expect($run('migrate:fresh', ['--force']))->toBe(0, implode("\n", $run->err))
        ->and($run->out)->toContain('dropped   notes');
})->with(['local', 'development', 'testing', ' Local ']);

it('drops everything where --non-production says so, and TINVAR_ENV says nothing', function () {
    $run = run();
    $run->files->add('2026_10_24_000001_create_notes', CREATE_NOTES);
    $run('migrate');
    $run->environment = ['TINVAR_ENV' => null];

    expect($run('migrate:fresh', ['--non-production', '--force']))->toBe(0, implode("\n", $run->err))
        ->and($run->out)->toContain('dropped   notes')
        // The flag says this is not production; it does not stand for --force.
        ->and($run('migrate:fresh', ['--non-production']))->toBe(MigrateCommand::FAILED)
        ->and(implode("\n", $run->err))->toContain('--force');
});

describe('make:migration', function () {
    it('writes the migration the models call for, named for now, and creates the directory', function () {
        $run = run();
        $run->directory = "{$run->files->directory}/migrations";

        expect($run('make:migration', ['create_notes'], now: 1_793_016_000))->toBe(0);

        $path = "{$run->directory}/2026_10_26_120000_create_notes.php";
        expect($run->out)->toBe(["wrote {$path}"])
            ->and((string) file_get_contents($path))->toContain("\$schema->create('notes', function (Table \$table): void {")
            ->and($run('migrate'))->toBe(0)
            ->and($run('make:migration', ['create_notes_again']))->toBe(0)
            ->and($run->out[0])->toStartWith('The database already has everything the models describe; nothing written.');
    });

    it('writes an empty one with --blank, for a backfill', function () {
        $run = run();

        expect($run('make:migration', ['backfill_bodies', '--blank']))->toBe(0)
            ->and((string) file_get_contents("{$run->files->directory}/2026_10_26_120000_backfill_bodies.php"))->toContain('Written by hand');
    });

    // Diffed against a database behind its migrations, the new one would
    // repeat what they do.
    it('refuses to diff a database with migrations still to run', function () {
        $run = run();
        $run->files->add('2026_10_24_000001_create_notes', CREATE_NOTES);
        $run('migrate:status');

        expect($run('make:migration', ['add_title']))->toBe(MigrateCommand::FAILED)
            ->and($run->err[0])->toBe('1 migration(s) have not run here (2026_10_24_000001_create_notes); migrate first, or this one would repeat them.');
    });

    it('warns of a column the database names with more than an identifier, and writes nothing of it', function () {
        $run = run();
        $connection = PdoConnection::sqlite($run->files->database());
        $connection->execute("CREATE TABLE notes (id TEXT PRIMARY KEY, body TEXT NOT NULL, \"x\nsystem('id');//\" TEXT)");

        expect($run('make:migration', ['add_title']))->toBe(0)
            ->and($run->err)->toBe([
                'warning: notes has a column named "x\\nsystem(\'id\');//", which is not a plain identifier and is on no model; '
                . 'it is left out of the migration. Look at it in the database.',
            ])
            ->and($run->out[0])->toStartWith('The database already has everything the models describe; nothing written.');
    });

    it('will not write over a migration of the same name and time', function () {
        $run = run();
        $run('make:migration', ['backfill', '--blank']);

        expect($run('make:migration', ['backfill', '--blank']))->toBe(MigrateCommand::FAILED)
            ->and($run->err[0])->toEndWith('2026_10_26_120000_backfill.php already exists.');
    });

    it('asks whoever runs it before it reads a column as another renamed', function (bool $answer, string $written) {
        $run = run();
        PdoConnection::sqlite($run->files->database())->execute('CREATE TABLE notes (id TEXT PRIMARY KEY, text TEXT NOT NULL)');
        $run->answer = $answer;

        expect($run('make:migration', ['rename_text']))->toBe(0)
            ->and($run->asked)->toBe(["Is the column notes.text, on no model, the model's body renamed? Renamed, it keeps its values."])
            ->and((string) file_get_contents("{$run->files->directory}/2026_10_26_120000_rename_text.php"))->toContain($written);
    })->with([
        'told it is' => [true, "\$table->renameColumn('text', 'body');"],
        'told it is not' => [false, "\$table->string('body');"],
    ]);
});

describe('db:seed', function () {
    it('migrates, then seeds with the options the seeder takes', function () {
        $run = run();
        $run->files->add('2026_10_24_000001_create_notes', CREATE_NOTES);
        $run->seeder = $seeder = new Notes();

        expect($run('db:seed', ['--notes=3']))->toBe(0)
            ->and($run->out)->toContain('Ran 1 migration(s).')
            ->and(end($run->out))->toBe('Seeded 3 note(s).')
            ->and($seeder->given)->toBe([['notes' => '3']])
            ->and(PdoConnection::sqlite($run->files->database())->select('SELECT COUNT(*) AS n FROM notes'))->toBe([['n' => 3]]);
    });

    it('drops every table first with --fresh, which takes --force', function () {
        $run = run();
        $run->files->add('2026_10_24_000001_create_notes', CREATE_NOTES);
        $run->seeder = new Notes();
        $run('db:seed', ['--notes=2']);

        expect($run('db:seed', ['--fresh']))->toBe(MigrateCommand::FAILED)
            ->and(implode("\n", $run->err))->toContain('--force')
            ->and($run('db:seed', ['--force']))->toBe(MigrateCommand::USAGE)
            ->and($run('db:seed', ['--fresh', '--force', '--notes=1']))->toBe(0)
            ->and($run->out)->toContain('Dropped every table and ran 1 migration(s).')
            ->and(PdoConnection::sqlite($run->files->database())->select('SELECT COUNT(*) AS n FROM notes'))->toBe([['n' => 1]]);
    });

    it('refuses an option the seeder does not take', function () {
        $run = run();
        $run->seeder = new Notes();

        expect($run('db:seed', ['--customers=3']))->toBe(MigrateCommand::USAGE)
            ->and($run->err[0])->toContain("Unknown argument '--customers=3'");
    });

    it('refuses to seed in production, and with no seeder to seed with', function (string $variable) {
        $run = run();
        $run->files->add('2026_10_24_000001_create_notes', CREATE_NOTES);
        $run->seeder = $seeder = new Notes();
        $run->environment = [$variable => 'production'];

        expect($run('db:seed'))->toBe(MigrateCommand::FAILED)
            ->and($run->err[0])->toContain("{$variable} says this is production")
            ->and($seeder->given)->toBe([]);

        $run->environment = ['TINVAR_ENV' => 'testing'];
        $run->seeder = null;
        expect($run('db:seed'))->toBe(MigrateCommand::FAILED)
            ->and($run->err[0])->toBe('This application has no seeder for db:seed to run.');
    })->with(['TINVAR_ENV', 'APP_ENV', 'NODE_ENV']);

    it('refuses to seed in production with --non-production too', function (string $variable) {
        $run = run();
        $run->files->add('2026_10_24_000001_create_notes', CREATE_NOTES);
        $run->seeder = $seeder = new Notes();
        $run->environment = [$variable => 'production', ...($variable === 'TINVAR_ENV' ? [] : ['TINVAR_ENV' => 'testing'])];

        expect($run('db:seed', ['--non-production']))->toBe(MigrateCommand::FAILED)
            ->and($run('db:seed', ['--fresh', '--force', '--non-production']))->toBe(MigrateCommand::FAILED)
            ->and($run->err)->toBe([
                "db:seed fills a database with demo data, and {$variable} says this is production. It is refused here, "
                . "--non-production or not. If this is not production, set {$variable} to something else.",
            ])
            ->and($seeder->given)->toBe([])
            ->and(PdoConnection::sqlite($run->files->database())->select("SELECT name FROM sqlite_master WHERE type = 'table'"))->toBe([]);
    })->with(['TINVAR_ENV', 'APP_ENV', 'NODE_ENV']);

    // Before it migrates: refused, the database is as it was.
    it('refuses to seed where nothing says this is not production, and says what to set', function (?string $environment) {
        $run = run();
        $run->files->add('2026_10_24_000001_create_notes', CREATE_NOTES);
        $run->seeder = $seeder = new Notes();
        $run->environment = ['TINVAR_ENV' => $environment];

        expect($run('db:seed', ['--notes=1']))->toBe(MigrateCommand::FAILED)
            ->and($run('db:seed', ['--fresh', '--force']))->toBe(MigrateCommand::FAILED)
            ->and($run->err)->toBe([
                'db:seed fills a database with demo data, and nothing says this is not production. '
                . 'Set TINVAR_ENV=local (or development, or testing), or pass --non-production to say so for this run.',
            ])
            ->and($seeder->given)->toBe([])
            ->and(PdoConnection::sqlite($run->files->database())->select("SELECT name FROM sqlite_master WHERE type = 'table'"))->toBe([]);
    })->with(['unset' => [null], 'staging' => ['staging']]);

    it('seeds where TINVAR_ENV says this is not production', function (string $environment) {
        $run = run();
        $run->files->add('2026_10_24_000001_create_notes', CREATE_NOTES);
        $run->seeder = $seeder = new Notes();
        $run->environment = ['TINVAR_ENV' => $environment];

        expect($run('db:seed', ['--notes=1']))->toBe(0, implode("\n", $run->err))
            ->and($seeder->given)->toBe([['notes' => '1']]);
    })->with(['local', 'development', 'testing']);

    it('seeds, and seeds afresh, where --non-production says so and TINVAR_ENV says nothing', function () {
        $run = run();
        $run->files->add('2026_10_24_000001_create_notes', CREATE_NOTES);
        $run->seeder = $seeder = new Notes();
        $run->environment = ['TINVAR_ENV' => null];

        expect($run('db:seed', ['--notes=2', '--non-production']))->toBe(0, implode("\n", $run->err))
            ->and($run('db:seed', ['--non-production', '--fresh', '--force', '--notes=1']))->toBe(0, implode("\n", $run->err))
            ->and($run->out)->toContain('Dropped every table and ran 1 migration(s).')
            // The flag is the command's, not an option handed to the seeder.
            ->and($seeder->given)->toBe([['notes' => '2'], ['notes' => '1']]);
    });
});

// The environment says what the machine is, not what the database is: a
// laptop at TINVAR_ENV=local pointed at production's database passed the
// check. Production's database carries a mark of its own (ADR 0103).
describe('the production mark', function () {
    it('is written by migrate where the environment says production, once', function () {
        $run = run();
        $run->files->add('2026_10_24_000001_create_notes', CREATE_NOTES);
        $run->environment = ['TINVAR_ENV' => 'production'];

        expect($run('migrate'))->toBe(0)
            ->and(end($run->out))->toBe("Marked this database production's, as TINVAR_ENV says.")
            ->and(ProductionMarker::isMarked(PdoConnection::sqlite($run->files->database())))->toBeTrue()
            ->and($run('migrate'))->toBe(0)
            ->and($run->out)->toBe(['Nothing to migrate.']);
    });

    it('is not written where the environment says nothing, or not production', function (?string $environment) {
        $run = run();
        $run->files->add('2026_10_24_000001_create_notes', CREATE_NOTES);
        $run->environment = ['TINVAR_ENV' => $environment];

        expect($run('migrate'))->toBe(0)
            ->and(ProductionMarker::isMarked(PdoConnection::sqlite($run->files->database())))->toBeFalse();
    })->with([null, 'local', 'staging']);

    it('refuses migrate:fresh and db:seed on a marked database, whatever the environment and the flag say', function () {
        $run = run();
        $run->files->add('2026_10_24_000001_create_notes', CREATE_NOTES);
        $run->seeder = $seeder = new Notes();
        expect($run('migrate'))->toBe(0)
            ->and($run('db:mark-production'))->toBe(0)
            ->and($run->out)->toBe(["Marked this database production's: migrate:fresh and db:seed are refused on it from now on."])
            ->and($run('db:mark-production'))->toBe(0)
            ->and($run->out)->toBe(["This database is marked production's already."]);

        $run->environment = ['TINVAR_ENV' => 'local'];
        expect($run('migrate:fresh', ['--force', '--non-production']))->toBe(MigrateCommand::FAILED)
            ->and($run->err[0])->toContain("this database is marked production's")
            ->and($run('db:seed', ['--non-production']))->toBe(MigrateCommand::FAILED)
            ->and($run->err[0])->toContain("this database is marked production's")
            ->and($run('db:seed', ['--fresh', '--force']))->toBe(MigrateCommand::FAILED)
            ->and($seeder->given)->toBe([])
            ->and(PdoConnection::sqlite($run->files->database())->select('SELECT COUNT(*) AS n FROM notes'))->toBe([['n' => 0]]);
    });

    it('is held by Migrator::fresh() itself, for code that drops tables without the command', function () {
        $run = run();
        $run->files->add('2026_10_24_000001_create_notes', CREATE_NOTES);
        $connection = PdoConnection::sqlite($run->files->database());
        ProductionMarker::mark($connection);

        expect(fn () => new Migrator($connection, $run->files->directory)->fresh())
            ->toThrow(MigrationRefused::class, "marked production's")
            ->and(ProductionMarker::isMarked($connection))->toBeTrue();
    });

    it('is taken away only by hand: an emptied mark table marks nothing', function () {
        $run = run();
        $run->files->add('2026_10_24_000001_create_notes', CREATE_NOTES);
        $connection = PdoConnection::sqlite($run->files->database());
        ProductionMarker::mark($connection);
        $connection->execute('DELETE FROM ' . ProductionMarker::TABLE);

        expect(ProductionMarker::isMarked($connection))->toBeFalse()
            ->and($run('migrate:fresh', ['--force']))->toBe(0, implode("\n", $run->err));
    });
});
