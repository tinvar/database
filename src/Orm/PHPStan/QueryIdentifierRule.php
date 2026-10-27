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
use Tinvar\Orm\Aggregate;
use Tinvar\Orm\Builder\Expression;
use Tinvar\Orm\Builder\Identifiers;
use Tinvar\Orm\MappingException;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\Model;
use Tinvar\Orm\ModelMeta;
use Tinvar\Orm\Query;
use Tinvar\Orm\RelationKind;

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
 * What it reads (ADR 0064 added the groups, aggregates and writes):
 *
 *   - a column: where() and its kin -- a #[HasMany]'s column too, which
 *     they read as an EXISTS -- orderBy(), pluck(), sum(), min(), max(),
 *     every column groupBy() names, and the column of having() and
 *     orderByAggregate();
 *   - a column of the model's own table: increment(), decrement(), and
 *     each key of the fields update() is given as a constant array;
 *   - a relation: with(), whereHas() and its kin, and a #[HasMany] for
 *     withCount(), withSum() and the rest and orderByRelation(), whose
 *     column is the related model's;
 *   - each column a report() reads, as a constant array of them;
 *   - a raw condition, whereRaw().
 *
 * Registered by extension.neon beside this package's composer.json.
 *
 * @implements Rule<MethodCall>
 */
final class QueryIdentifierRule implements Rule
{
    /** Methods whose first argument is a column, a #[HasMany]'s included: a filter. */
    private const array FILTERS = [
        'where', 'orwhere', 'wherein', 'orwherein', 'wherenotin', 'wherenull', 'orwherenull', 'wherenotnull',
    ];

    /** Methods whose first argument is a column of the model or a #[BelongsTo] it reaches. */
    private const array COLUMNS = ['orderby', 'pluck', 'sum', 'min', 'max'];

    /** Methods whose first argument is a column of the model's own table. */
    private const array OWN = ['increment', 'decrement'];

    /** Methods whose first argument is a relation path. */
    private const array RELATIONS = ['wherehas', 'orwherehas', 'wheredoesnthave'];

    /** Methods whose second argument is a column an aggregate reads, or null. */
    private const array MEASURES = ['having', 'orderbyaggregate'];

    /**
     * Methods whose first argument is a #[HasMany], and where the related
     * model's column is: its position and its name.
     */
    private const array RELATION_MEASURES = [
        'withcount' => null,
        'withsum' => [1, 'column'],
        'withmin' => [1, 'column'],
        'withmax' => [1, 'column'],
        'withavg' => [1, 'column'],
        'withaggregate' => [2, 'column'],
        'orderbyrelation' => [2, 'column'],
    ];

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
        $checked = \in_array($method, [...self::FILTERS, ...self::COLUMNS, ...self::OWN, ...self::RELATIONS, ...self::MEASURES], true)
            || \array_key_exists($method, self::RELATION_MEASURES)
            || \in_array($method, ['with', 'groupby', 'whereraw', 'orwhereraw', 'update', 'report'], true);
        if (!$checked) {
            return [];
        }

        $meta = $this->model($scope->getType($node->var));
        if ($meta === null) {
            return [];
        }

        $args = array_values($node->getArgs());
        $errors = [];
        $report = function (MappingException $e) use (&$errors, $meta, $node): void {
            $errors[] = RuleErrorBuilder::message("Query<{$meta->class}>::{$node->name}(): {$e->getMessage()}")
                ->identifier('tinvar.queryIdentifier')
                ->build();
        };

        foreach ($this->checks($method, $meta, $args, $scope) as $check) {
            try {
                $check();
            } catch (MappingException $e) {
                $report($e);
            }
        }

        return $errors;
    }

    /**
     * Each name the call holds that PHPStan can read, as a check that
     * throws the builder's own MappingException for one it would refuse.
     *
     * @param list<Node\Arg> $args
     * @return list<\Closure(): mixed>
     */
    private function checks(string $method, ModelMeta $meta, array $args, Scope $scope): array
    {
        $first = $this->argument($args, 0, $method === 'report' ? 'class' : 'column');
        $names = fn (?Node\Arg $arg): array => $arg === null ? [] : $this->names($scope->getType($arg->value));
        $checks = [];

        if ($method === 'with' || $method === 'groupby') {
            foreach ($args as $arg) {
                if ($arg->name !== null) {
                    continue;
                }
                foreach ($names($arg) as $name) {
                    $checks[] = $method === 'with'
                        ? static fn (): mixed => Identifiers::relationPath($meta, $name)
                        : static fn (): mixed => Identifiers::column($meta, $name);
                }
            }

            return $checks;
        }

        if (\in_array($method, self::FILTERS, true)) {
            foreach ($names($first) as $name) {
                $checks[] = static fn (): mixed => Identifiers::collectionColumn($meta, $name) ?? Identifiers::column($meta, $name);
            }
        } elseif (\in_array($method, self::COLUMNS, true)) {
            foreach ($names($first) as $name) {
                $checks[] = static fn (): mixed => Identifiers::column($meta, $name);
            }
        } elseif (\in_array($method, self::OWN, true)) {
            foreach ($names($first) as $name) {
                $checks[] = static fn (): mixed => self::own($meta, $name);
            }
        } elseif (\in_array($method, self::RELATIONS, true)) {
            foreach ($names($this->argument($args, 0, 'relation')) as $name) {
                $checks[] = static fn (): mixed => Identifiers::relationPath($meta, $name);
            }
        } elseif (\in_array($method, self::MEASURES, true)) {
            foreach ($names($this->argument($args, 1, 'column')) as $name) {
                $checks[] = static fn (): mixed => Identifiers::column($meta, $name);
            }
        } elseif (\array_key_exists($method, self::RELATION_MEASURES)) {
            $at = self::RELATION_MEASURES[$method];
            $columns = $at === null ? [] : $names($this->argument($args, $at[0], $at[1]));
            foreach ($names($this->argument($args, 0, 'relation')) as $relation) {
                $checks[] = static function () use ($meta, $relation, $columns): mixed {
                    $found = Identifiers::relation($meta, $relation);
                    if ($found->kind !== RelationKind::HasMany) {
                        throw new MappingException("{$meta->class}::\${$relation} is a #[BelongsTo]: an aggregate reads a #[HasMany].");
                    }
                    foreach ($columns as $column) {
                        Identifiers::column(Metadata::for($found->target), $column);
                    }

                    return null;
                };
            }
        } elseif ($method === 'whereraw' || $method === 'orwhereraw') {
            foreach ($names($this->argument($args, 0, 'sql')) as $sql) {
                $bindings = $this->bindings($scope, $this->argument($args, 1, 'bindings'));
                $checks[] = static fn (): mixed => Expression::parse($meta, $sql, $bindings);
            }
        } elseif ($method === 'update') {
            foreach ($this->constantArrays($scope, $this->argument($args, 0, 'values')) as [$keys]) {
                foreach ($keys as $key) {
                    foreach ($this->names($key) as $name) {
                        $checks[] = static fn (): mixed => self::own($meta, $name);
                    }
                }
            }
        } elseif ($method === 'report') {
            foreach ($this->constantArrays($scope, $this->argument($args, 1, 'columns')) as [, $values]) {
                foreach ($values as $value) {
                    // Aggregate::Count reads no column; its case's value is no name.
                    if ((new ObjectType(Aggregate::class))->isSuperTypeOf($value)->yes()) {
                        continue;
                    }
                    // A column, or [Aggregate::Sum, 'column']: the column is the second.
                    $column = $value->getConstantArrays() !== [] ? ($value->getConstantArrays()[0]->getValueTypes()[1] ?? null) : $value;
                    foreach ($column === null ? [] : $this->names($column) as $name) {
                        $checks[] = static fn (): mixed => Identifiers::column($meta, $name);
                    }
                }
            }
        }

        return $checks;
    }

    /** A column of the model's own table: what a write sets. */
    private static function own(ModelMeta $meta, string $name): string
    {
        $ref = Identifiers::column($meta, $name);
        if ($ref->path !== []) {
            throw new MappingException("'{$name}' is a column of another table; a write sets {$meta->class}'s own.");
        }

        return $ref->column;
    }

    /**
     * The argument at $position, or the one named $name; null for neither.
     *
     * @param list<Node\Arg> $args
     */
    private function argument(array $args, int $position, string $name): ?Node\Arg
    {
        foreach ($args as $arg) {
            if ($arg->name !== null && $arg->name->toString() === $name) {
                return $arg;
            }
        }
        $arg = $args[$position] ?? null;

        return $arg !== null && $arg->name === null && !$arg->unpack ? $arg : null;
    }

    /**
     * The key and value types of each constant array an argument can be.
     *
     * @return list<array{list<Type>, list<Type>}>
     */
    private function constantArrays(Scope $scope, ?Node\Arg $arg): array
    {
        if ($arg === null) {
            return [];
        }

        return array_map(
            static fn ($array): array => [$array->getKeyTypes(), array_values($array->getValueTypes())],
            $scope->getType($arg->value)->getConstantArrays(),
        );
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

    /** How many values a raw condition binds, where PHPStan can count them. */
    private function bindings(Scope $scope, ?Node\Arg $arg): ?int
    {
        if ($arg === null) {
            return 0;
        }

        $arrays = $scope->getType($arg->value)->getConstantArrays();

        return \count($arrays) === 1 ? \count($arrays[0]->getValueTypes()) : null;
    }
}
