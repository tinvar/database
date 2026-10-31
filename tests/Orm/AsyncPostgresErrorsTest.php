<?php

declare(strict_types=1);

/**
 * What the async Postgres driver makes of an error (ADR 0087): a statement
 * that failed on a session still answering, or a session the server ended,
 * which answers as a statement's error would -- a restart's admin_shutdown,
 * read as the reply to the next statement -- and has its socket closed by
 * libpq by then. A session so ended is failed, so the pool closes it rather
 * than lend it again.
 */

namespace Tinvar\Tests\Orm\AsyncPostgresErrors;

use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresQueryError;
use Tinvar\Orm\Async\PostgresDriver;
use Tinvar\Orm\Async\QueryError;

function errorFor(string $sqlState): QueryError
{
    $error = new PostgresDriver(new PostgresConfig('localhost'))
        ->error(new PostgresQueryError('ERROR', ['sqlstate' => $sqlState], 'SELECT 1'));

    return $error instanceof QueryError ? $error : throw new \LogicException('Not a QueryError.');
}

it('fails the session the server ended, and keeps one a statement failed on', function (string $sqlState, bool $failed) {
    $error = errorFor($sqlState);

    expect($error->sessionFailed)->toBe($failed)
        ->and($error->errorInfo[0] ?? null)->toBe($sqlState);
})->with([
    'admin_shutdown' => ['57P01', true],
    'crash_shutdown' => ['57P02', true],
    'cannot_connect_now' => ['57P03', true],
    'connection_failure' => ['08006', true],
    'query_canceled' => ['57014', false],
    'unique_violation' => ['23505', false],
    'read_only_sql_transaction' => ['25006', false],
])->skip(!\extension_loaded('pgsql'), 'The async Postgres driver runs on ext-pgsql.');
