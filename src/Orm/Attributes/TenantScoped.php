<?php

declare(strict_types=1);

namespace Tinvar\Orm\Attributes;

/** Filters every query by the active tenant and stamps it on insert. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class TenantScoped
{
    public function __construct(public string $column = 'tenant_id') {}
}
