<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * A connection that tells its database which tenant it is serving, so the
 * database's own row-level security can stand behind the ORM's filter.
 *
 * tinvar-spec.md, "Tenancy": "Row-level security on by default in the
 * shared tier. PHP sets the tenant as a session variable on each
 * connection; with none set, policies match no rows."
 *
 * The connection ASKS which tenant is in force, just before each statement,
 * rather than being told on every switch. The tenant is fiber-local and a
 * render's fibers share one connection: a value pushed at the moment of a
 * switch would be whichever fiber switched last, not the one about to run
 * a statement. See RowLevelSecurity for the policies that read it.
 */
interface TenantSetting
{
    /**
     * @param \Closure(): array{string, bool, list<string>} $scope the tenant in force
     *        for whoever is about to run a statement ('' for none), whether
     *        acrossTenants() has lifted scoping for them, and the tenants
     *        withinSubtree() has widened their reads to (empty for none)
     */
    public function followTenant(\Closure $scope): void;

    /**
     * Sets the session back to no tenant now, rather than at the next
     * statement: for the end of a request, so that nothing reaching the
     * connection around this class before then sees the last request's.
     */
    public function clearTenantSetting(): void;
}
