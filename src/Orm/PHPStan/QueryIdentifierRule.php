<?php

declare(strict_types=1);

namespace Tinvar\Orm\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use Tinvar\Orm\Builder\Expression;
use Tinvar\Orm\Builder\Identifiers;
use Tinvar\Orm\MappingException;
use Tinvar\Orm\Model;
use Tinvar\Orm\ModelMeta;
use Tinvar\Orm\Query;

/**
 * Column and relation names in query builder calls, checked by PHPStan
 * against the model's compiled metadata: "codegen emits a PHPStan extension
 * from the metadata, so a misspelled column fails static analysis".
 *
 * Not emitted: the rule reads the metadata itself, through Metadata::for()
 * and the builder's own Identifiers, when PHPStan analyses a call. A
 * generated file would be a second copy of the metadata that a model
 * change could leave behind, and a second copy of the resolution rules
 * that could disagree with the builder's (ADR 0057). So the names it
 * refuses are exactly the names the builder refuses at runtime.
 *
 * A call is checked where PHPStan knows both halves: the query is a
 * Query<T> for one concrete model, and the name is a constant string --
 * or a case of a string-backed enum, whose value is the name. A name built
 * at runtime is the builder's to check when it runs, and a model PHPStan
 * cannot load, the same.
 *
 * Registered by extension.neon beside this package's composer.json.
 *
 * @implements Rule<MethodCall>
 */
final class QueryIdentifierRule implements Rule
{
    /** Methods whose first argument is a column. */
    private const array COLUMNS = [
        'where', 'orwhere', 'wherein', 'orwherein', 'wherenotin', 'wherenull', 'orwherenull', 'wherenotnull',
        'orderby', 'pluck', 'sum', 'min', 'max',
    ];

    /** Methods whose first argument is a relation path. */
    private const array RELATIONS = ['wherehas', 'orwherehas', 'wheredoesnthave'];

    public function __construct(private readonly ReflectionProvider $reflection) {}

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Node\Identifier) {
            return [];
        }
        $method = $node->name->toLowerString();
        $checked = \in_array($method, self::COLUMNS, true) || \in_array($method, self::RELATIONS, true)
            || $method === 'with' || $method === 'whereraw' || $method === 'orwhereraw';
        if (!$checked) {
            return [];
        }

        $meta = $this->model($scope->getType($node->var));
        if ($meta === null) {
            return [];
        }

        $args = array_values($node->getArgs());
        if ($args === [] || $args[0]->name !== null) {
            return [];
        }

        $errors = [];
        $names = $method === 'with'
            ? array_merge([], ...array_map(fn (Node\Arg $arg): array => $this->names($scope->getType($arg->value)), $args))
            : $this->names($scope->getType($args[0]->value));

        foreach ($names as $name) {
            try {
                match (true) {
                    \in_array($method, self::COLUMNS, true) => Identifiers::column($meta, $name),
                    $method === 'whereraw' || $method === 'orwhereraw' => Expression::parse($meta, $name, $this->bindings($scope, $args)),
                    default => Identifiers::relationPath($meta, $name),
                };
            } catch (MappingException $e) {
                $errors[] = RuleErrorBuilder::message("Query<{$meta->class}>::{$node->name->toString()}(): {$e->getMessage()}")
                    ->identifier('tinvar.queryIdentifier')
                    ->build();
            }
        }

        return $errors;
    }

    /** The compiled metadata of the one model a Query<T> is over, or null where that is not known. */
    private function model(Type $type): ?ModelMeta
    {
        if (!(new ObjectType(Query::class))->isSuperTypeOf($type)->yes()) {
            return null;
        }

        $classes = $type->getTemplateType(Query::class, 'T')->getObjectClassNames();
        if (\count($classes) !== 1 || !$this->reflection->hasClass($classes[0])) {
            return null;
        }
        $class = $this->reflection->getClass($classes[0]);
        if (!$class->isSubclassOfClass($this->reflection->getClass(Model::class)) || $class->isAbstract()) {
            return null;
        }

        // Compiled from the class itself, as the builder compiles it when it
        // runs -- so the class has to load in PHPStan's process too, through
        // the project's autoloader or a bootstrap file.
        return Identifiers::model($class->getName());
    }

    /**
     * The names a constant argument can be: each constant string, and each
     * string-backed enum case's value.
     *
     * @return list<string>
     */
    private function names(Type $type): array
    {
        $names = array_map(static fn ($string): string => $string->getValue(), $type->getConstantStrings());

        foreach ($type->getEnumCases() as $case) {
            $backing = $case->getBackingValueType();
            if ($backing !== null) {
                foreach ($backing->getConstantStrings() as $string) {
                    $names[] = $string->getValue();
                }
            }
        }

        return $names;
    }

    /** @param list<Node\Arg> $args how many values a raw condition binds, where PHPStan can count them */
    private function bindings(Scope $scope, array $args): ?int
    {
        if (!isset($args[1])) {
            return 0;
        }

        $arrays = $scope->getType($args[1]->value)->getConstantArrays();

        return \count($arrays) === 1 ? \count($arrays[0]->getValueTypes()) : null;
    }
}
