<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * What happened to an #[Audited] model's row (ADR 0156). The value is the
 * event an audit log files the change under.
 */
enum ModelChangeKind: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';

    /** A #[SoftDeletes] model's row trashed by delete(): still there, read by nothing that does not ask (ADR 0191). */
    case Trashed = 'trashed';

    /** A trashed row brought back by restore(). */
    case Restored = 'restored';
}
