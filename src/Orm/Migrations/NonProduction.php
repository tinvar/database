<?php

declare(strict_types=1);

namespace Tinvar\Orm\Migrations;

use Tinvar\Support\DevMode;

/**
 * What a command that destroys or invents data -- `migrate:fresh`,
 * `db:seed` -- needs before it runs: a statement that this is not
 * production, and no sign anywhere that it is.
 *
 * Failing closed is the point. Refusing only where TINVAR_ENV, APP_ENV or
 * NODE_ENV says production let through every server that says nothing,
 * and a production box with no environment set is common: a fresh VM, a
 * container whose env file did not load, a shell someone sshed into. So
 * the environment has to say what it is -- TINVAR_ENV local, development
 * or testing -- or whoever runs the command says it, with --non-production,
 * for the one run. A production marker still wins over both: a TINVAR_ENV
 * of `local` copied beside an APP_ENV of `production`, or the flag typed
 * from habit into the wrong shell, is the mistake this is here to stop.
 */
final class NonProduction
{
    /** The flag that says, for one run, that this database is not production's. */
    public const string FLAG = '--non-production';

    /** The TINVAR_ENV values that say so for every run. */
    public const array ENVIRONMENTS = ['local', 'development', 'testing'];

    /**
     * @param string $command the command, as it is typed, for the message
     * @param string $does what it does to the data, as "drops every table"
     * @param array<string, string> $environment
     * @param bool $declared whether --non-production was given
     * @throws MigrationRefused unless this is said not to be production, and nothing says it is
     */
    public static function require(string $command, string $does, array $environment, bool $declared): void
    {
        $production = DevMode::productionMarker($environment);
        if ($production !== null) {
            throw new MigrationRefused(
                "{$command} {$does}, and {$production} says this is production. It is refused here, "
                . self::FLAG . " or not. If this is not production, set {$production} to something else.",
            );
        }

        if ($declared || \in_array(strtolower(trim($environment['TINVAR_ENV'] ?? '')), self::ENVIRONMENTS, true)) {
            return;
        }

        throw new MigrationRefused(
            "{$command} {$does}, and nothing says this is not production. Set TINVAR_ENV=local (or development, "
            . 'or testing), or pass ' . self::FLAG . ' to say so for this run.',
        );
    }
}
