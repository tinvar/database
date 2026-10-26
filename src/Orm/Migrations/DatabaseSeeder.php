<?php

declare(strict_types=1);

namespace Tinvar\Orm\Migrations;

use Tinvar\Orm\Connection;

/**
 * What `tinvar db:seed` fills a database with: the application's demo or
 * development data, made once the migrations have run.
 *
 * Never data the application needs in production. db:seed runs only
 * where TINVAR_ENV or --non-production says this is not production, and
 * never where anything says it is (NonProduction), since what a seeder makes is
 * accounts with passwords printed in the repository and rows nobody
 * entered; data production needs -- a lookup table, a first role -- is a
 * migration's, written by hand beside the generated ones.
 */
interface DatabaseSeeder
{
    /**
     * The options db:seed passes on to seed(), by name, each with what it
     * is for; db:seed refuses any other.
     *
     * @return array<string, string>
     */
    public function options(): array;

    /**
     * Fills the database, which is migrated already.
     *
     * @param array<string, string> $options those of options() given, by name
     * @param \Closure(string): void $out a line for whoever ran the command
     *
     * @throws \InvalidArgumentException for an option it cannot use, which db:seed reports as a bad invocation
     * @throws MigrationRefused for a database it will not seed, one seeded already say
     */
    public function seed(Connection $connection, array $options, \Closure $out): void;
}
