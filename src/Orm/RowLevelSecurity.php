<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * Postgres row-level security for #[TenantScoped] tables: the policy that
 * reads the session variable PdoConnection keeps (see TenantSetting).
 *
 * The ORM already filters every query by the tenant and fails closed with
 * no tenant. This is the second layer, for whatever goes around the ORM --
 * raw SQL through connection(), a `whereRaw` subquery, a report someone
 * wrote by hand -- that forgot the predicate: the database still returns
 * only the tenant's rows, and none at all when no tenant is set.
 *
 * A migration applies it (ADR 0029): Table::tenant() on a table it makes,
 * Schema::tenantPolicy() on one that has its tenant column already, and
 * `make:migration` writes whichever a #[TenantScoped] table lacks.
 *
 * The policy is FORCEd, so the table's owner -- often the
 * application's own role -- is held to it too; a superuser or a role with
 * BYPASSRLS is not, whatever this does, so the application must not
 * connect as one.
 *
 * acrossTenants() lifts the policy as it lifts the ORM's filter, by a
 * second variable; withinSubtree() widens it to the tenants of a subtree
 * by a third, a Postgres array of them, empty -- and so matching nothing
 * -- outside one. A policy made before that third variable matches the
 * tenant alone: a read across a subtree there sees the parent's rows and
 * none of its children's until a migration makes the policy again. It is a guard against a forgotten predicate, not against
 * SQL injection: injected SQL can call set_config() as well as this class
 * can.
 */
final class RowLevelSecurity
{
    /** The session variable holding the tenant in force; '' for none. */
    public const string TENANT_SETTING = 'app.tenant';

    /** 'on' while acrossTenants() has lifted scoping; 'off' otherwise. */
    public const string ACROSS_SETTING = 'app.across_tenants';

    /** The tenants withinSubtree() widened reads to, as an array literal; '' for none. */
    public const string SUBTREE_SETTING = 'app.subtree';

    /** The policy's name on every table, so it can be dropped and made again. */
    public const string POLICY = 'tinvar_tenant';

    /**
     * The statements that turn the policy on for one table.
     *
     * @param string $type the tenant column's type, which the setting -- text -- is cast
     *        to, so the comparison uses the column's index rather than casting every row
     * @return list<string>
     */
    public static function policy(string $table, string $column = 'tenant_id', string $type = 'text'): array
    {
        $table = self::identifier($table, 'table');
        $column = self::identifier($column, 'column');
        if (preg_match('/^[a-z][a-z0-9_ ]{0,62}\z/', $type) !== 1) {
            throw new \InvalidArgumentException("'{$type}' is not a column type this can cast to.");
        }

        // NULLIF: no tenant is '', which cast to a uuid is an error rather
        // than a value matching nothing. NULL compares as unknown, and a
        // policy reading unknown matches no row.
        //
        // The subtree the same way: '' outside withinSubtree() is NULL, and
        // `= ANY (NULL)` is unknown, so it adds nothing to the tenant alone.
        $predicate = \sprintf(
            "current_setting('%s', true) = 'on' OR %s = NULLIF(current_setting('%s', true), '')::%s"
            . " OR %s = ANY (NULLIF(current_setting('%s', true), '')::%s[])",
            self::ACROSS_SETTING,
            $column,
            self::TENANT_SETTING,
            $type,
            $column,
            self::SUBTREE_SETTING,
            $type,
        );

        return [
            "ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY",
            "ALTER TABLE {$table} FORCE ROW LEVEL SECURITY",
            'DROP POLICY IF EXISTS ' . self::POLICY . " ON {$table}",
            'CREATE POLICY ' . self::POLICY . " ON {$table} USING ({$predicate}) WITH CHECK ({$predicate})",
        ];
    }

    /**
     * The policy for a model's table and tenant column.
     *
     * @param class-string<Model> $model
     * @return list<string>
     */
    public static function forModel(string $model, string $type = 'text'): array
    {
        $meta = $model::meta();
        if ($meta->tenantColumn === null) {
            throw new \InvalidArgumentException("{$model} is not #[TenantScoped]; there is no tenant column to hold it to.");
        }

        return self::policy($meta->table, $meta->tenantColumn, $type);
    }

    /**
     * Tenants as the Postgres array literal SUBTREE_SETTING holds, every
     * element quoted so a tenant's id is read as one value whatever it
     * holds; '' for none.
     *
     * @param list<string> $tenants
     */
    public static function arrayLiteral(array $tenants): string
    {
        if ($tenants === []) {
            return '';
        }

        return '{' . implode(',', array_map(
            static fn (string $tenant): string => '"' . addcslashes($tenant, '"\\') . '"',
            $tenants,
        )) . '}';
    }

    /**
     * The statement a connection tells its session's tenant with: the three
     * settings, set session-wide only when the backend answering is the one
     * expected, and cleared on any other -- in the same statement, so on
     * that backend whatever a pooler does next -- answering which backend it
     * was as `pid`. The parameters are the placeholders, in the driver's
     * own spelling, for the tenant, 'on' or 'off', the subtree's array
     * literal and the backend expected, an integer or null for any (the
     * first tell, before one is known); $readOnly, when given, is one for
     * 'on' or 'off' to set default_transaction_read_only to as well, left
     * alone on a backend not expected.
     *
     * A tripwire's second half (ADR 0140): the tell a transaction pooler
     * hands to another server session neither leaves ours there for
     * whichever client gets it next, nor leaves the tenant it held.
     *
     * @internal PdoConnection's and the async drivers', inside this package
     */
    public static function tellOnBackend(string $tenant, string $across, string $subtree, string $expected, ?string $readOnly = null): string
    {
        $setting = static fn (string $name, string $value, string $cleared): string => "CASE WHEN here.expected IS NULL OR here.pid = here.expected THEN set_config('{$name}', {$value}, false) "
            . "ELSE set_config('{$name}', '{$cleared}', false) END";

        return 'SELECT ' . $setting(self::TENANT_SETTING, $tenant, '') . ' AS tenant, '
            . $setting(self::ACROSS_SETTING, $across, 'off') . ' AS across, '
            . $setting(self::SUBTREE_SETTING, $subtree, '') . ' AS subtree, '
            . ($readOnly === null ? '' : "CASE WHEN here.expected IS NULL OR here.pid = here.expected THEN set_config('default_transaction_read_only', {$readOnly}, false) END AS read_only, ")
            . 'here.pid AS pid '
            // One pg_backend_pid() for the whole statement: a subquery with
            // a volatile call in its list is not folded into the outer one.
            . "FROM (SELECT pg_backend_pid() AS pid, CAST({$expected} AS integer) AS expected) AS here";
    }

    /** A plain or schema-qualified identifier, quoted. */
    private static function identifier(string $name, string $what): string
    {
        $parts = explode('.', $name);
        foreach ($parts as $part) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}\z/', $part) !== 1) {
                throw new \InvalidArgumentException("'{$name}' is not a {$what} name.");
            }
        }

        return implode('.', array_map(static fn (string $part): string => '"' . $part . '"', $parts));
    }
}
