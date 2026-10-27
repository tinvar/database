<?php

declare(strict_types=1);

namespace Tinvar\Orm\Attributes;

/**
 * A set of columns no two of the model's rows share: `#[Unique('sku')]`,
 * or `#[Unique('aisle', 'code')]` for a pair. Each is a property mapping a
 * column, a #[BelongsTo] property (its foreign key) or a column name.
 *
 * What an upsert may name as its conflict target, and the only thing it
 * may: an upsert that conflicts on a set of columns the model does not say
 * is unique has no index to conflict on, and the database refuses it, or
 * worse, matches rows the code did not mean. On a #[TenantScoped] model the
 * key is unique within a tenant, so the tenant column leads it -- in the
 * index make:migration writes and in every conflict target (ADR 0057).
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class Unique
{
    /** @var list<string> */
    public array $columns;

    public function __construct(string ...$columns)
    {
        $this->columns = array_values($columns);
    }
}
