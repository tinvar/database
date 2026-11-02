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
}
