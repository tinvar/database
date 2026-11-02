<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * Where the ORM records each change to an #[Audited] model (ADR 0151): the
 * application's audit log, registered with Orm::recordChangesWith().
 *
 * record() is called inside the change's own transaction, after the row is
 * written and before the transaction commits, so what it writes on the
 * Orm's connection commits or rolls back with the change. One that throws
 * takes the change back: a change the log could not record is not made.
 */
interface ModelChanges
{
    public function record(ModelChange $change): void;
}
