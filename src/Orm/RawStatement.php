<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * One statement sent through Orm::connection() that names a tenant table:
 * raw SQL, which no tenant predicate of the ORM's is added to, flagged for
 * the dev toolbar (tinvar-spec.md, "Tenancy"; ADR 0057).
 *
 * Its literals are taken out (RawSql::redacted()): it says where the
 * statement was sent and what it named, not what it carried.
 */
final readonly class RawStatement
{
    /**
     * @param string $sql the statement, its literals taken out
     * @param list<string> $tables the tenant tables it names
     * @param string $site where it was sent from, as file:line; '' where that is not known
     * @param string $tenant the tenant in force, '' for none
     * @param bool $acrossTenants whether acrossTenants() lifted scoping
     */
    public function __construct(
        public string $sql,
        public array $tables,
        public string $site,
        public string $tenant,
        public bool $acrossTenants,
    ) {}
}
