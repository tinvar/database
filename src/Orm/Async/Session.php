<?php

declare(strict_types=1);

namespace Tinvar\Orm\Async;

use Amp\Mysql\MysqlConnection;
use Amp\Postgres\PostgresConnection;

/**
 * One database session of the pool's, and what this side knows of its
 * state: what it was last told, and the transaction open on it.
 *
 * @internal AsyncConnection's and Pool's, inside this package
 */
final class Session
{
    /**
     * The tenant the session was last told, as Driver::tenantKey() spells
     * it; null when that is not known. A rollback makes it unknown, as it
     * does PdoConnection's: Postgres takes back a set_config() made in the
     * transaction or savepoint rolled back.
     */
    public ?string $tenant;

    /** Whether the session refuses writes outside a transaction; null when not known. */
    public ?bool $readOnly;

    public bool $inTransaction = false;

    /** Whether the transaction open began READ ONLY, so cannot be made to write. */
    public bool $transactionReadOnly = false;

    /** Savepoints of transaction() open inside the transaction; names the next one. */
    public int $savepoints = 0;

    /**
     * Postgres: how many savepoints of transaction() were open when a
     * read-only scope opened after the transaction began, and its own
     * savepoint was made; null while there is none. See
     * AsyncConnection::bringIntoLine().
     */
    public ?int $readOnlySavepoint = null;

    /**
     * The statement that failed inside the Postgres transaction open now,
     * and how many savepoints were open when it did, as PdoConnection keeps
     * it: the transaction is aborted, and its COMMIT would roll back.
     *
     * @var array{\PDOException, int}|null
     */
    public ?array $aborted = null;

    /**
     * Whether a statement is in flight, its answer not yet read whole. A
     * session left so -- its fiber destroyed or timed out mid-statement --
     * is closed rather than handed back: the next statement on it would
     * read the rest of this one's answer.
     */
    public bool $busy = false;

    public bool $closed = false;

    /** The server's id for this session's backend (Driver::backend()). */
    public readonly int $backend;

    /** When it was last handed back cleared, on the pool's clock: how long it has waited idle. */
    public float $idleSince;

    /**
     * @param float $openedAt when it was opened, on the pool's clock: its lifetime runs from here
     */
    public function __construct(public readonly PostgresConnection|MysqlConnection $link, Driver $driver, public readonly float $openedAt = 0.0)
    {
        $this->idleSince = $openedAt;
        $this->backend = $driver->backend($link);
        $this->tenant = $driver->tenantKey('', false, []);
        $this->readOnly = false;
    }

    /** Forgets the transaction, as it stands once the session has rolled back or committed. */
    public function endTransaction(): void
    {
        $this->inTransaction = false;
        $this->transactionReadOnly = false;
        $this->savepoints = 0;
        $this->readOnlySavepoint = null;
        $this->aborted = null;
    }
}
