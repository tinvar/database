<?php

declare(strict_types=1);

namespace Tinvar\Orm\Migrations;

use Tinvar\Orm\Connection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;

/**
 * The database's own word that it is production's (ADR 0103).
 *
 * NonProduction reads the environment of the machine a command runs on,
 * which says nothing of the database it is pointed at: a laptop with
 * TINVAR_ENV=local and production's DSN pasted in from a runbook passes
 * it. So production's database carries a mark of its own -- one row in
 * one table -- written by `tinvar migrate` wherever the environment says
 * production, or by `tinvar db:mark-production`, and `migrate:fresh`,
 * `db:seed` and Migrator::fresh() refuse a database that has it, whatever
 * the environment or a flag says.
 *
 * Nothing in the framework takes the mark away: a database that should not
 * have it is unmarked by hand, in the database's own client, by dropping
 * the table -- a step nobody takes from habit. A copy of production's
 * database -- a restored backup -- keeps it, and is refused as production
 * is until someone says otherwise that way; `tinvar staging:import` never
 * copies it.
 */
final class ProductionMarker
{
    public const string TABLE = 'tinvar_production';

    /** Whether the database $connection reaches is marked production's. */
    public static function isMarked(Connection $connection, ?Dialect $dialect = null): bool
    {
        $dialect ??= Dialect::for($connection);
        if (!$dialect->hasTable($connection, self::TABLE)) {
            return false;
        }

        $count = $connection->select('SELECT COUNT(*) AS n FROM ' . $dialect->quote(self::TABLE))[0]['n'] ?? 0;

        return (\is_int($count) || \is_string($count)) && (int) $count > 0;
    }

    /**
     * Marks the database production's; nothing if it is already.
     *
     * @return bool whether this call marked it
     */
    public static function mark(Connection $connection, ?Dialect $dialect = null, ?int $now = null): bool
    {
        $dialect ??= Dialect::for($connection);
        if (self::isMarked($connection, $dialect)) {
            return false;
        }

        new Schema($connection, $dialect)->create(self::TABLE, static function (Table $table): void {
            $table->integer('id')->primary();
            $table->string('marked_at', 32);
        }, ifNotExists: true);

        // `migrate` marks once it has migrated, outside the migration lock,
        // so two deploys together can both find the database unmarked: the
        // second's write finds the row taken and leaves it, rather than
        // failing a deploy whose migrations ran.
        return $connection->execute(
            $dialect->insertUnlessTaken(self::TABLE, ['id', 'marked_at']),
            [1, gmdate('Y-m-d\TH:i:s\Z', $now ?? time())],
        ) === 1;
    }

    /**
     * @param string $command the command, as it is typed, for the message
     * @param string $does what it does to the data, as "drops every table"
     * @throws MigrationRefused where the database is marked production's
     */
    public static function refuse(Connection $connection, string $command, string $does, ?Dialect $dialect = null): void
    {
        if (self::isMarked($connection, $dialect)) {
            throw new MigrationRefused(
                "{$command} {$does}, and this database is marked production's (its " . self::TABLE . ' table). It is '
                . 'refused here whatever the environment or ' . NonProduction::FLAG . ' says. If this database is not '
                . 'production\'s, drop that table by hand first.',
            );
        }
    }
}
