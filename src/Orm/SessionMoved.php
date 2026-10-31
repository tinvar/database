<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * Postgres answered a connection's tenant from another server session than
 * the one it was first told on.
 *
 * The tenant row-level security reads is a session setting, told only when
 * it changes (ADR 0026). Behind a pooler that hands each transaction, or
 * each statement, to whichever server session is free -- PgBouncer in
 * transaction or statement mode, and poolers like it -- a later statement
 * can run on a session another worker told its own tenant, and the policy
 * then holds it to that tenant's rows, or lifts it. Every tenant told also
 * asks which backend answered, in the same round trip; one that is not the
 * session's first is refused, and the connection refuses every tenant
 * after, rather than run anything more on a session it cannot vouch for.
 *
 * A tripwire, not a guarantee: a pooler that happened to hand back the same
 * session each time is not seen. Row-level security needs a direct
 * connection or a pooler in session mode (docs/deployment.md).
 */
final class SessionMoved extends \RuntimeException
{
    public static function between(int $first, int $now): self
    {
        return new self(
            "Postgres answered from backend {$now} on a connection first told its tenant on backend {$first}. Something between "
            . 'PHP and Postgres -- PgBouncer or another pooler in transaction or statement mode -- hands this connection\'s '
            . 'statements to different server sessions, so the tenant row-level security reads could be another worker\'s. '
            . 'Connect directly, or through a pooler in session mode.',
        );
    }
}
