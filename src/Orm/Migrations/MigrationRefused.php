<?php

declare(strict_types=1);

namespace Tinvar\Orm\Migrations;

/**
 * The migrator declining to start: before it has changed anything, for a
 * reason the operator can act on.
 */
final class MigrationRefused extends \RuntimeException
{
    public static function locked(float $timeout, string $lock): self
    {
        return new self(\sprintf(
            'Another migration has held %s for %ss; this one did not start. If no deploy is migrating, the holder is still '
            . 'connected somewhere, and stopping it releases the lock.',
            $lock,
            rtrim(rtrim(number_format($timeout, 1, '.', ''), '0'), '.'),
        ));
    }

    public static function irreversible(string $name): self
    {
        return new self("{$name} has no down(), so it cannot be rolled back. Nothing was rolled back.");
    }

    public static function missingFile(string $name): self
    {
        return new self("{$name} is recorded as run, but its file is gone; it cannot be rolled back without it. Nothing was rolled back.");
    }

    public static function inTransaction(): self
    {
        return new self('Migrations commit one by one, so they cannot run inside a transaction the caller holds.');
    }

    public static function badFile(string $path, string $why): self
    {
        return new self("{$path}: {$why}");
    }
}
