<?php

declare(strict_types=1);

namespace Tinvar\Orm\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use Tinvar\Orm\Builder\Identifiers;
use Tinvar\Orm\Connection;
use Tinvar\Orm\RawSql;

/**
 * Raw SQL on a tenant table, flagged by PHPStan: tinvar-spec.md, "Tenancy"
 * -- "Raw SQL and whereRaw subqueries on tenant tables are flagged by a
 * static check and in the dev toolbar."
 *
 * A call to select() or execute() on a Tinvar\Orm\Connection whose SQL
 * names the table of a #[TenantScoped] model, or a table the application
 * lists as a tenant's, is reported: nothing adds the tenant predicate to
 * it, so it reads and writes every tenant's rows unless the code wrote the
 * predicate itself -- and on Postgres, row-level security is all that
 * holds it. The query builder writes the predicate on every table; a
 * statement that has to be raw says why, in an ignore of the identifier
 * this rule reports (tinvar.rawTenantSql) beside it, where a reviewer
 * reads it. `whereRaw()` is no raw SQL: it cannot name a table (ADR 0057).
 *
 * SQL PHPStan cannot read as constant strings is reported as unread when
 * there are tenant tables at all: what it names is unknown, and a check
 * that let it pass would pass the statement most worth reading.
 *
 * Which tables are a tenant's comes from the application's configuration,
 * since PHPStan cannot list the classes there are:
 *
 *     parameters:
 *         tinvar:
 *             models: [App\Models\Rack, App\Models\Unit]
 *             tenantTables: [rack_events]
 *
 * Each model's table counts when the model is #[TenantScoped], read
 * through its compiled metadata as the builder reads it.
 *
 * @implements Rule<MethodCall>
 */
final class RawTenantSqlRule implements Rule
{
    /** @var list<string>|null resolved on first use, once PHPStan's autoloading is up */
    private ?array $tables = null;

    /**
     * @param list<string> $models model classes, whose tables count when they are #[TenantScoped]
     * @param list<string> $tenantTables further tables that are a tenant's, with no model
     */
    public function __construct(private readonly array $models = [], private readonly array $tenantTables = []) {}

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Node\Identifier || !\in_array($node->name->toLowerString(), ['select', 'execute'], true)) {
            return [];
        }
        if (!(new ObjectType(Connection::class))->isSuperTypeOf($scope->getType($node->var))->yes()) {
            return [];
        }
        $tables = $this->tables();
        $args = array_values($node->getArgs());
        if ($tables === [] || $args === []) {
            return [];
        }

        $method = $node->name->toString();
        $strings = $scope->getType($args[0]->value)->getConstantStrings();
        if ($strings === []) {
            return [
                RuleErrorBuilder::message(
                    "Connection::{$method}() is sent SQL PHPStan cannot read, which may name a tenant table: nothing adds the tenant "
                    . 'predicate to raw SQL. Use the query builder, or say why this has to be raw.',
                )->identifier('tinvar.rawSqlUnread')->build(),
            ];
        }

        $named = [];
        foreach ($strings as $string) {
            array_push($named, ...RawSql::tenantTablesIn($string->getValue(), $tables));
        }
        $named = array_values(array_unique($named));
        if ($named === []) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                "Connection::{$method}() sends raw SQL on the tenant table" . (\count($named) > 1 ? 's ' : ' ')
                . implode(', ', $named) . ': nothing adds the tenant predicate to it. Use the query builder, or say why this has to be raw.',
            )->identifier('tinvar.rawTenantSql')->build(),
        ];
    }

    /** @return list<string> */
    private function tables(): array
    {
        if ($this->tables !== null) {
            return $this->tables;
        }

        $tables = $this->tenantTables;
        foreach ($this->models as $class) {
            // One that does not compile could be a tenant's: refused, not
            // passed over, so the check cannot quietly cover less.
            $meta = Identifiers::model($class) ?? throw new \LogicException(
                "tinvar.models lists {$class}, which does not load and compile as a model in PHPStan's process.",
            );
            if ($meta->tenantColumn !== null) {
                $tables[] = $meta->table;
            }
        }

        return $this->tables = array_values(array_unique($tables));
    }
}
