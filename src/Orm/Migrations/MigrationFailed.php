<?php

declare(strict_types=1);

namespace Tinvar\Orm\Migrations;

/**
 * A migration that threw. What it leaves depends on where it ran: in a
 * transaction, nothing -- its changes and its record rolled back together,
 * and the migrations before it stay run and recorded; outside one (MySQL,
 * or a migration that asked not to be in one), the statements it had run,
 * listed here, and no record, so it runs again once it is fixed and what
 * it left is repaired.
 */
final class MigrationFailed extends \RuntimeException
{
    /**
     * @param list<string> $leftBehind statements that ran and were not rolled back
     */
    public function __construct(
        public readonly string $migration,
        public readonly string $direction,
        public readonly array $leftBehind,
        \Throwable $previous,
    ) {
        $message = \sprintf('%s failed %s: %s', $migration, $direction === 'up' ? 'to migrate' : 'to roll back', $previous->getMessage());
        $message .= $leftBehind === []
            ? ' Nothing it did was kept.'
            : \sprintf(
                ' It ran outside a transaction, and these %d statement(s) were kept; repair them before migrating again: %s',
                \count($leftBehind),
                implode('; ', $leftBehind),
            );

        parent::__construct($message, 0, $previous);
    }
}
