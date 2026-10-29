<?php

declare(strict_types=1);

namespace Tinvar\Orm\Async;

/**
 * A statement's failure on the async driver, thrown as the sync driver
 * throws one: a PDOException whose code is the SQLSTATE and whose errorInfo
 * is [SQLSTATE, the driver's code, its message]. Code that catches a unique
 * violation by its SQLSTATE catches it on either driver.
 *
 * The client's own exception is not chained to it: it quotes the statement,
 * and a statement can carry what it would have written, as
 * ReadOnlyViolation::refusedByDatabase() says of its own.
 *
 * @internal thrown by AsyncConnection; caught as \PDOException
 */
final class QueryError extends \PDOException
{
    /** Whether the session itself failed -- gone, refused, timed out -- not just the statement. */
    public readonly bool $sessionFailed;

    public function __construct(string $sqlState, int|string|null $driverCode, string $message, bool $sessionFailed = false)
    {
        parent::__construct("SQLSTATE[{$sqlState}]: {$message}");
        $this->code = $sqlState;
        $this->errorInfo = [$sqlState, $driverCode, $message];
        $this->sessionFailed = $sessionFailed;
    }

    /**
     * The session itself failed: gone, closed, refused, or answering what
     * the client could not read. Its message says why at each step the
     * client wrapped it in -- "could not connect after 3 tries", and what
     * the server said to the last -- since only the message is kept.
     */
    public static function sessionFailed(string $sqlState, int|string|null $driverCode, \Throwable $failure): self
    {
        $reasons = [];
        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
            $reasons[] = trim($cause->getMessage());
        }

        return new self($sqlState, $driverCode, 'The database session failed: ' . implode(': ', array_filter($reasons)), true);
    }
}
