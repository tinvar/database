<?php

declare(strict_types=1);

namespace Tinvar\Tests\Orm\Fixtures;

use Tinvar\Support\PrivateDirectory;

/**
 * Migration files written by a test, into a directory of its own: each
 * test's migrations say what they do beside the assertion about them,
 * rather than in a fixture directory read from elsewhere.
 */
final class MigrationFiles
{
    public readonly string $directory;

    public function __construct()
    {
        $this->directory = PrivateDirectory::create('tinvar-migrate');
        $owner = getmypid();
        $directory = $this->directory;
        register_shutdown_function(static function () use ($directory, $owner): void {
            if (getmypid() === $owner) {
                PrivateDirectory::remove($directory);
            }
        });
    }

    /**
     * Writes one migration. $up and $down are method bodies, with $schema in
     * scope; a null $down leaves the migration without one.
     */
    public function add(string $name, string $up, ?string $down = null, bool $withinTransaction = true): string
    {
        $path = "{$this->directory}/{$name}.php";
        $property = $withinTransaction ? '' : "    public bool \$withinTransaction = false;\n\n";
        $downMethod = $down === null ? '' : "\n    public function down(Schema \$schema): void\n    {\n        {$down}\n    }\n";

        file_put_contents($path, <<<PHP
            <?php

            declare(strict_types=1);

            use Tinvar\\Orm\\Migrations\\Migration;
            use Tinvar\\Orm\\Schema\\Schema;
            use Tinvar\\Orm\\Schema\\Table;

            return new class extends Migration {
            {$property}    public function up(Schema \$schema): void
                {
                    {$up}
                }
            {$downMethod}};

            PHP);

        return $path;
    }

    /** A temporary SQLite file, removed with the directory. */
    public function database(string $name = 'test'): string
    {
        return "{$this->directory}/{$name}.sqlite";
    }
}
