<?php

declare(strict_types=1);

namespace Tinvar\Orm;

enum RelationKind
{
    case HasMany;
    case BelongsTo;
}
