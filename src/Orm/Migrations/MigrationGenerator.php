<?php

declare(strict_types=1);

namespace Tinvar\Orm\Migrations;

use Tinvar\Orm\Attributes\Column as ColumnAttribute;
use Tinvar\Orm\Connection;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\Model;
use Tinvar\Orm\RelationKind;
use Tinvar\Orm\Schema\Column;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Index;

/**
 * What `make:migration` writes: "diffs compiled metadata against the live
 * database and writes a migration file to review and commit"
 * (tinvar-spec.md, "Migrations").
 *
 * The models say what should be there -- typed properties are the schema,
 * so a property's type and nullability are the column's -- and the
 * database what is. The migration adds what is missing: tables, columns,
 * the foreign keys relations read by and the indexes they load by, and on
 * Postgres the tenant policy of a #[TenantScoped] table. It changes a
 * column whose type, nullability or declared default the model has
 * changed, and renames a column or table where the difference reads as
 * one rename and nothing else could. It never drops: a column the
 * database has and no model maps, or an index no relation loads by any
 * more, is named in a comment, for whoever reviews the file to decide.
 *
 * Builder calls, not SQL, so one migration runs on every dialect: written
 * against the SQLite of a laptop, it runs against production's Postgres.
 *
 * What it writes is code a developer will run, and some of it comes from
 * the database -- whoever can make a column there, through a schema a
 * tenant controls or a database someone else got into, chooses its name,
 * its type and its default. A name the database gives is written only if
 * it is a plain identifier, a type only if it could be nothing but a type,
 * a default only if it is a literal, read into its PHP value; anything
 * else is left out and reported in warnings. Every name, the models' as
 * well, reaches the file as a quoted literal or as comment text that
 * cannot end its comment.
 */
final class MigrationGenerator
{
    /** A name the database gives that may be written into the file, even as a comment. */
    private const string IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*\z/';

    /** The families Dialect::family() names; any other is a database's own type. */
    private const array FAMILIES = ['text', 'integer', 'float', 'decimal', 'numeric', 'boolean', 'json', 'timestamp', 'date', 'binary'];

    /** @var list<string> what the last generate() left out of the file, and why, for whoever runs it */
    public private(set) array $warnings = [];

    /** @var \Closure(string): bool */
    private readonly \Closure $confirmRename;

    /**
     * @param (\Closure(string): bool)|null $confirmRename asked, of each column or table that
     *        reads as another renamed, whether it is: a question for whoever runs make:migration.
     *        Without it nothing is renamed, and each such pair is named in a comment instead --
     *        the database cannot tell a rename from a drop and an add, and read wrongly, a rename
     *        would leave the old column's values under the new one's name.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly Dialect $dialect,
        ?\Closure $confirmRename = null,
    ) {
        $this->confirmRename = $confirmRename ?? static fn (string $question): bool => false;
    }

    /**
     * The migration that brings the database to the models, or null when
     * it is there already.
     *
     * @param list<class-string<Model>> $models
     */
    public function generate(array $models, string $date): ?string
    {
        $this->warnings = [];
        $wanted = $this->wanted($models);
        $renamedFrom = [];
        $lookAlikes = [];
        foreach ($this->tableRenames($wanted) as $new => $old) {
            if (($this->confirmRename)("Is the table {$old}, on no model, the models' {$new} renamed? Renamed, it keeps its rows.")) {
                $renamedFrom[$new] = $old;
            } else {
                $lookAlikes[$new] = $old;
            }
        }
        $up = [];
        $down = [];
        $changed = false;

        foreach ($this->inDependencyOrder($wanted) as $name => $table) {
            $from = $renamedFrom[$name] ?? null;
            if ($from === null && !$this->dialect->hasTable($this->connection, $name)) {
                $create = $this->renderCreate($name, $table);
                if (isset($lookAlikes[$name])) {
                    $old = $lookAlikes[$name];
                    $create = self::comment(
                        "{$old} is in the database and on no model, with the columns {$name} has. If {$name} is {$old} renamed, "
                        . "\$schema->rename({$this->php($old)}, {$this->php($name)}) in place of this keeps its rows.",
                    ) . "\n" . $create;
                }
                $up[] = $create;
                array_unshift($down, "        \$schema->drop({$this->php($name)});");
                $changed = true;
                continue;
            }

            $rename = [];
            $unrename = [];
            if ($from !== null) {
                $rename[] = "        \$schema->rename({$this->php($from)}, {$this->php($name)});";
                $unrename[] = "        \$schema->rename({$this->php($name)}, {$this->php($from)});";
                $changed = true;
            }

            [$changes, $undo, $changesTable] = $this->changes($name, $table, $from ?? $name);
            $up = [...$up, ...$rename, ...$changes];
            $down = [...$undo, ...$unrename, ...$down];
            $changed = $changed || $changesTable;
        }

        // Comments alone -- a column no model maps -- are not a migration.
        if (!$changed) {
            return null;
        }

        return self::source(
            "Written by `tinvar make:migration` from the difference between the models and the {$this->dialect->name()} "
            . "database on {$date}. Review it before committing it: it adds and changes what the models have and the database "
            . 'lacks, and drops nothing.',
            implode("\n\n", $up),
            implode("\n", $down),
        );
    }

    /** A migration with nothing in it yet: for a backfill, or what the models cannot say. */
    public static function blank(): string
    {
        return self::source(
            'Written by hand: a data backfill, or a change the models cannot express.',
            '        //',
            '        //',
        );
    }

    private static function source(string $about, string $up, string $down): string
    {
        // Inside the doc comment: nothing in it may end the comment early.
        $about = str_replace(["\r", "\n", '*/'], ['', '', '*\/'], $about);
        $wrapped = wordwrap($about, 74, "\n * ", true);

        return <<<PHP
            <?php

            declare(strict_types=1);

            use Tinvar\\Orm\\Migrations\\Migration;
            use Tinvar\\Orm\\Schema\\Schema;
            use Tinvar\\Orm\\Schema\\Table;

            /**
             * {$wrapped}
             */
            return new class extends Migration {
                public function up(Schema \$schema): void
                {
            {$up}
                }

                public function down(Schema \$schema): void
                {
            {$down}
                }
            };

            PHP;
    }

    /**
     * What the models describe, by table.
     *
     * @param list<class-string<Model>> $models
     * @return array<string, array{columns: array<string, array<string, mixed>>, indexes: list<list<string>>, tenant: string|null, keys: list<string>, uniques: list<list<string>>}>
     */
    private function wanted(array $models): array
    {
        $tables = [];
        foreach ($models as $model) {
            $meta = Metadata::for($model);
            $table = $tables[$meta->table] ?? ['columns' => [], 'indexes' => [], 'tenant' => null, 'keys' => [], 'uniques' => []];

            foreach ($meta->columns as $property => $column) {
                $table['columns'][$column] = $property === $meta->idProperty
                    ? ['type' => 'uuid', 'nullable' => false, 'primary' => true]
                    : self::columnOf(new \ReflectionProperty($model, $property));
            }

            foreach ($meta->relations as $property => $relation) {
                if ($relation->kind !== RelationKind::BelongsTo) {
                    continue;
                }
                $target = Metadata::for($relation->target);
                $table['columns'][$relation->foreignKey] ??= [
                    'type' => 'uuid',
                    'nullable' => new \ReflectionProperty($model, $property)->getType()?->allowsNull() ?? false,
                    'references' => [$target->table, $target->idColumn],
                ];
                $table['indexes'][] = [$relation->foreignKey];
                $table['keys'][] = $relation->foreignKey;
            }

            if ($meta->tenantColumn !== null) {
                $table['tenant'] = $meta->tenantColumn;
                unset($table['columns'][$meta->tenantColumn]);
            }

            // Each #[Unique] key, the tenant column first on a tenant's
            // table: the index an upsert's conflict target has to match.
            foreach ($meta->uniqueKeys as $key) {
                $unique = $meta->tenantColumn === null ? $key : [$meta->tenantColumn, ...$key];
                if (!\in_array($unique, $table['uniques'], true)) {
                    $table['uniques'][] = $unique;
                }
            }
            $tables[$meta->table] = $table;
        }

        // A has-many's key is on the other model's table, which may have no
        // relation back to say so; the key is made there, with the index its
        // loads use -- `WHERE key IN (...) ORDER BY orderBy`.
        foreach ($models as $model) {
            $meta = Metadata::for($model);
            foreach ($meta->relations as $relation) {
                $target = Metadata::for($relation->target);
                if ($relation->kind !== RelationKind::HasMany || !isset($tables[$target->table])) {
                    continue;
                }
                $tables[$target->table]['columns'][$relation->foreignKey] ??= [
                    'type' => 'uuid',
                    'nullable' => false,
                    'references' => [$meta->table, $meta->idColumn],
                ];
                $tables[$target->table]['indexes'][] = array_values(array_filter([$relation->foreignKey, $relation->orderBy]));
                $tables[$target->table]['keys'][] = $relation->foreignKey;
            }
        }

        foreach ($tables as $name => $table) {
            $tables[$name]['indexes'] = self::withoutCoveredIndexes($table['indexes']);
        }

        return $tables;
    }

    /**
     * A column from a property: its type and nullability are the column's,
     * and a default it declares the column's default. #[Column(type:)]
     * names a storage type where PHP's says too little -- jsonb for a
     * value object. Without one, a value object's column is what its Cast
     * says it stores, and a backed enum's is its backing type.
     *
     * @return array<string, mixed>
     */
    private static function columnOf(\ReflectionProperty $property): array
    {
        $type = $property->getType();
        $declared = $property->getAttributes(ColumnAttribute::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
        $cast = Metadata::castOf($property->getDeclaringClass()->getName(), $property);
        $storage = $declared?->newInstance()->type ?? ($cast === null ? null : $cast::columnType());
        $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : 'mixed';

        $builderType = $storage !== null ? self::storageType($storage) : match (true) {
            $typeName === 'int' => 'integer',
            $typeName === 'float' => 'float',
            $typeName === 'bool' => 'boolean',
            $typeName === 'array' => 'json',
            $typeName === \DateTimeImmutable::class => 'timestamp',
            $typeName === 'string' => 'string',
            is_subclass_of($typeName, \BackedEnum::class) => (new \ReflectionEnum($typeName))->getBackingType()?->getName() === 'int' ? 'integer' : 'string',
            default => 'text',
        };

        $column = ['type' => $builderType, 'nullable' => $type?->allowsNull() ?? true];
        if ($property->hasDefaultValue()) {
            $default = $property->getDefaultValue();
            if ($default instanceof \BackedEnum) {
                $default = $default->value;
            }
            if (\is_int($default) || \is_float($default) || \is_string($default) || \is_bool($default)) {
                $column['default'] = $default;
            }
        }

        return $column;
    }

    /** The builder's type for a #[Column(type:)], or the type itself, written as the database's own. */
    private static function storageType(string $type): string
    {
        return match (strtolower($type)) {
            'json', 'jsonb' => 'json',
            'uuid' => 'uuid',
            'text' => 'text',
            'string', 'varchar' => 'string',
            'int', 'integer' => 'integer',
            'bigint', 'biginteger' => 'bigInteger',
            'bool', 'boolean' => 'boolean',
            'float', 'double', 'real' => 'float',
            'decimal', 'numeric' => 'decimal',
            'timestamp', 'timestamptz', 'datetime' => 'timestamp',
            'date' => 'date',
            'binary', 'blob', 'bytea' => 'binary',
            default => $type,
        };
    }

    /**
     * The tables to rename: a table the models want and the database lacks,
     * matched to the one table no model maps whose columns are the wanted
     * table's exactly -- and only where neither could be matched to another.
     *
     * @param array<string, array{columns: array<string, array<string, mixed>>, indexes: list<list<string>>, tenant: string|null, keys: list<string>, uniques: list<list<string>>}> $wanted
     * @return array<string, string> the wanted name => the database's name
     */
    private function tableRenames(array $wanted): array
    {
        $missing = array_values(array_filter(
            array_keys($wanted),
            fn (string $name): bool => !$this->dialect->hasTable($this->connection, $name),
        ));
        if ($missing === []) {
            return [];
        }

        $unmapped = [];
        foreach ($this->dialect->tables($this->connection) as $table) {
            // The database's name, written only if it is a plain identifier.
            if (!isset($wanted[$table]) && $table !== Migrator::TABLE && preg_match(self::IDENTIFIER, $table) === 1) {
                $columns = array_map('strval', array_keys($this->dialect->columns($this->connection, $table)));
                sort($columns);
                $unmapped[$table] = $columns;
            }
        }

        $candidates = [];
        foreach ($missing as $name) {
            $columns = array_map('strval', array_keys($wanted[$name]['columns']));
            if ($wanted[$name]['tenant'] !== null) {
                $columns[] = $wanted[$name]['tenant'];
            }
            sort($columns);
            $candidates[$name] = array_keys(array_filter($unmapped, static fn (array $live): bool => $live === $columns));
        }

        return self::oneToOne($candidates);
    }

    /**
     * The statements adding and changing what an existing table lacks, and
     * their undoing. $live is the name the database has the table under: a
     * table renamed by this migration is read under its old name.
     *
     * @param array{columns: array<string, array<string, mixed>>, indexes: list<list<string>>, tenant: string|null, keys: list<string>, uniques: list<list<string>>} $table
     * @return array{list<string>, list<string>, bool} up, down, and whether there is anything to do
     */
    private function changes(string $name, array $table, string $live): array
    {
        $columns = $this->dialect->columns($this->connection, $live);
        $liveIndexes = $this->dialect->indexes($this->connection, $live);
        $tenant = $table['tenant'];

        $comments = [];
        $renames = [];
        $unrenames = [];
        $changes = [];
        $unchanges = [];
        $additions = [];
        $undoColumns = [];
        $undoIndexes = [];

        // What the database has that no model maps. The database's names,
        // so anyone who could make the column chose them; only a plain
        // identifier is written, even into a comment.
        $unmapped = [];
        foreach (array_keys($columns) as $column) {
            // A name made of digits comes back from PHP as an int key.
            $column = (string) $column;
            if (isset($table['columns'][$column]) || $column === $tenant) {
                continue;
            }
            if (preg_match(self::IDENTIFIER, $column) !== 1) {
                $this->warnings[] = \sprintf(
                    '%s has a column named %s, which is not a plain identifier and is on no model; it is left out of the migration. Look at it in the database.',
                    $name,
                    self::quoted($column),
                );
                continue;
            }
            $unmapped[] = $column;
        }
        $missing = array_values(array_filter(
            array_map('strval', array_keys($table['columns'])),
            static fn (string $column): bool => !isset($columns[$column]),
        ));

        foreach ($this->columnRenames($missing, $unmapped, $table['columns'], $columns) as $new => $old) {
            if (!($this->confirmRename)("Is the column {$name}.{$old}, on no model, the model's {$new} renamed? Renamed, it keeps its values.")) {
                $comments[] = "{$name}.{$old} is in the database and on no model, and {$name}.{$new}, alike in type and nullability, is "
                    . "on a model and not in the database. If {$new} is {$old} renamed, \$table->renameColumn({$this->php($old)}, "
                    . "{$this->php($new)}) in place of adding it keeps its values; if not, dropping {$old} is yours to decide: "
                    . "\$table->dropColumn({$this->php($old)}).";
                $unmapped = array_values(array_diff($unmapped, [$old]));
                continue;
            }
            $renames[] = "            \$table->renameColumn({$this->php($old)}, {$this->php($new)});";
            $unrenames[] = "            \$table->renameColumn({$this->php($new)}, {$this->php($old)});";
            $columns[$new] = $columns[$old];
            unset($columns[$old]);
            $unmapped = array_values(array_diff($unmapped, [$old]));
            foreach ($liveIndexes as $i => $index) {
                $liveIndexes[$i]['columns'] = array_map(static fn (string $c): string => $c === $old ? $new : $c, $index['columns']);
            }
        }

        foreach ($table['columns'] as $column => $spec) {
            $column = (string) $column;
            if (!isset($columns[$column])) {
                if (($spec['nullable'] ?? false) !== true && !\array_key_exists('default', $spec)) {
                    $comments[] = "{$name}.{$column} is NOT NULL with no default: the rows already there need one (SQLite insists even when there are none), so give it a default() or add it nullable and backfill.";
                }
                $additions[] = '            ' . $this->renderColumn($column, $spec) . ';';
                $undoColumns[] = $column;
                continue;
            }
            if (($spec['primary'] ?? false) === true) {
                continue;
            }

            $change = $this->change($name, $column, $spec, $columns[$column]);
            if ($change !== null) {
                $comments = [...$comments, ...$change['comments']];
                $changes[] = $change['up'];
                if ($change['down'] !== null) {
                    $unchanges[] = $change['down'];
                }
            }
        }

        $liveIndexColumns = array_column($liveIndexes, 'columns');
        $policy = null;
        if ($tenant !== null && !isset($columns[$tenant])) {
            $comments[] = "{$name}.{$tenant} is NOT NULL with no default: the rows already there need their tenant, so give it a default() or backfill it before this runs.";
            $additions[] = "            \$table->tenant({$this->php($tenant)});";
            $undoIndexes[] = Index::of($name, [$tenant], false, null)->name;
            $undoColumns[] = $tenant;
            $liveIndexColumns[] = [$tenant];
        } elseif ($tenant !== null && !$this->dialect->hasTenantPolicy($this->connection, $live)) {
            $policy = $tenant;
        }

        foreach ($table['indexes'] as $columnsOfIndex) {
            if (self::covered($columnsOfIndex, $liveIndexColumns)) {
                continue;
            }
            $additions[] = "            \$table->index({$this->phpList($columnsOfIndex)});";
            $undoIndexes[] = Index::of($name, $columnsOfIndex, false, null)->name;
        }

        // A unique key is there when a unique index has its columns, in any
        // order: the set is what a conflict target matches.
        $liveUniques = [];
        foreach ($liveIndexes as $index) {
            if ($index['unique']) {
                $columnsOfIndex = $index['columns'];
                sort($columnsOfIndex);
                $liveUniques[] = $columnsOfIndex;
            }
        }
        foreach ($table['uniques'] as $columnsOfIndex) {
            $sorted = $columnsOfIndex;
            sort($sorted);
            if (\in_array($sorted, $liveUniques, true)) {
                continue;
            }
            $comments[] = "{$name} gets a unique index over (" . implode(', ', $columnsOfIndex) . '), which rows already sharing those values refuse: find them first.';
            $additions[] = "            \$table->unique({$this->phpList($columnsOfIndex)});";
            $undoIndexes[] = Index::of($name, $columnsOfIndex, true, null)->name;
        }

        $comments = [...$comments, ...$this->staleIndexes($name, $table, $liveIndexes)];
        foreach ($unmapped as $column) {
            $comments[] = "{$name}.{$column} is in the database and on no model. Dropping it is yours to decide: \$table->dropColumn({$this->php($column)}).";
        }

        // Each comment above the change it is about, or alone where there
        // is none.
        $up = array_map(self::comment(...), $comments);
        $lines = [...$renames, ...$changes, ...$additions];
        if ($lines !== []) {
            $up[] = "        \$schema->table({$this->php($name)}, function (Table \$table): void {\n" . implode("\n", $lines) . "\n        });";
        }
        if ($policy !== null) {
            $up[] = "        \$schema->tenantPolicy({$this->php($name)}, {$this->php($policy)});";
        }
        $up = $up === [] ? [] : [implode("\n", $up)];

        $down = [];
        if ($policy !== null) {
            $down[] = "        \$schema->dropTenantPolicy({$this->php($name)});";
        }
        $undo = [];
        foreach ($undoIndexes as $index) {
            $undo[] = "            \$table->dropIndex({$this->php($index)});";
        }
        if ($undoColumns !== []) {
            $undo[] = '            $table->dropColumn(' . implode(', ', array_map($this->php(...), $undoColumns)) . ');';
        }
        $undo = [...$undo, ...$unchanges];
        if ($undo !== []) {
            $down[] = "        \$schema->table({$this->php($name)}, function (Table \$table): void {\n" . implode("\n", $undo) . "\n        });";
        }
        // Renamed back after the rest is undone, which names the columns as
        // this migration left them: a table's renames run before its other
        // changes.
        if ($unrenames !== []) {
            $down[] = "        \$schema->table({$this->php($name)}, function (Table \$table): void {\n" . implode("\n", $unrenames) . "\n        });";
        }

        // A comment alone is no change; a rename, change or addition is.
        return [$up, $down, $lines !== [] || $policy !== null];
    }

    /**
     * The columns to rename: a column a model wants and the table lacks,
     * matched to the one column no model maps of its family and
     * nullability, where neither could be matched to another.
     *
     * @param list<string> $missing
     * @param list<string> $unmapped
     * @param array<string, array<string, mixed>> $wanted
     * @param array<string, array{type: string, nullable: bool, default: int|float|string|bool|null, defaultKind: string, caseInsensitive: bool}> $live
     * @return array<string, string> the wanted name => the database's name
     */
    private function columnRenames(array $missing, array $unmapped, array $wanted, array $live): array
    {
        $candidates = [];
        foreach ($missing as $column) {
            $spec = $wanted[$column];
            $family = $this->dialect->family($this->sqlType($column, $spec));
            $candidates[$column] = array_values(array_filter(
                $unmapped,
                fn (string $old): bool => $this->dialect->family($live[$old]['type']) === $family
                    && $live[$old]['nullable'] === (($spec['nullable'] ?? false) === true),
            ));
        }

        return self::oneToOne($candidates);
    }

    /**
     * What changes an existing column to what the model says, and back: a
     * type of another family, another nullability, or a default the model
     * declares that the column does not have. Its definition is written
     * whole, as change() takes it -- keeping, of what the model does not
     * say, the column's own length, case-insensitivity and literal default.
     *
     * @param array<string, mixed> $spec
     * @param array{type: string, nullable: bool, default: int|float|string|bool|null, defaultKind: string, caseInsensitive: bool} $live
     * @return array{up: string, down: string|null, comments: list<string>}|null
     */
    private function change(string $table, string $column, array $spec, array $live): ?array
    {
        $wantedType = \is_string($spec['type'] ?? null) ? $spec['type'] : 'text';
        $nullable = ($spec['nullable'] ?? false) === true;
        $typeChanged = $this->dialect->family($this->sqlType($column, $spec)) !== $this->dialect->family($live['type']);
        $nullChanged = $nullable !== $live['nullable'];
        $declaresDefault = \array_key_exists('default', $spec);
        $defaultChanged = $declaresDefault && !self::sameDefault($wantedType, $spec['default'], $live);
        if (!$typeChanged && !$nullChanged && !$defaultChanged) {
            return null;
        }

        // The database's type, as the builder would write it; null where it
        // is not one a migration may carry.
        $liveType = $this->dialect->builderType($live['type']);
        $comments = [];
        if ($typeChanged) {
            // The family's name, if it is one of the builder's; the
            // database's own spelling of a type is not written, even here.
            $family = $this->dialect->family($live['type']);
            $was = \in_array($family, self::FAMILIES, true) ? $family : 'of a type of its own';
            $comments[] = "{$table}.{$column} is {$was} in the database and {$wantedType} on the model. "
                . 'A change of type converts every value -- on SQLite by rebuilding the table -- and fails on one that does not convert; review it.';
        }
        if ($nullChanged && !$nullable) {
            $comments[] = "{$table}.{$column} takes NULL in the database and not on the model: a row holding NULL fails this, so backfill those rows first.";
        }

        $upType = $typeChanged ? ['type' => $wantedType, 'length' => null, 'scale' => null] : $liveType;
        if ($upType === null) {
            $this->warnings[] = \sprintf(
                '%s.%s differs from its model in nullability or default, and its type, %s as the database spells it, is not one make:migration writes; change it by hand.',
                $table,
                $column,
                self::quoted($live['type']),
            );

            return null;
        }

        if ($declaresDefault) {
            $upDefault = ['literal', $spec['default']];
        } else {
            $upDefault = [$live['defaultKind'], $live['default']];
            if ($live['defaultKind'] === 'expression') {
                $comments[] = "{$table}.{$column} has a default the database computes, which this does not copy, so the change leaves the column without one: put it back by hand.";
            }
        }

        $up = '            ' . $this->renderChange($column, $upType, $nullable, $upDefault, $live['caseInsensitive']) . ';';

        $down = null;
        if ($liveType === null) {
            $this->warnings[] = \sprintf(
                '%s.%s is %s in the database, which is not a type make:migration writes, so down() does not change it back; write that by hand.',
                $table,
                $column,
                self::quoted($live['type']),
            );
        } else {
            if ($live['defaultKind'] === 'expression') {
                $comments[] = "{$table}.{$column}'s default in the database is one it computes, which down() does not put back.";
            }
            $down = '            ' . $this->renderChange($column, $liveType, $live['nullable'], [$live['defaultKind'], $live['default']], $live['caseInsensitive']) . ';';
        }

        return ['up' => $up, 'down' => $down, 'comments' => $comments];
    }

    /**
     * An index no relation loads by any more, named for whoever reviews the
     * migration: one named as the builder names an index over its columns,
     * not unique, leading with a relation's key, that the models' indexes
     * neither are nor begin with -- what a has-many's index becomes when
     * its orderBy changes. An index is no data, but it may serve a query no
     * model knows of, so it is said rather than dropped.
     *
     * @param array{columns: array<string, array<string, mixed>>, indexes: list<list<string>>, tenant: string|null, keys: list<string>, uniques: list<list<string>>} $table
     * @param list<array{name: string, columns: list<string>, unique: bool}> $live
     * @return list<string>
     */
    private function staleIndexes(string $name, array $table, array $live): array
    {
        $comments = [];
        foreach ($live as $index) {
            $columns = $index['columns'];
            if ($index['unique'] || $columns === [] || !\in_array($columns[0], $table['keys'], true)) {
                continue;
            }
            // The database's names: only plain identifiers are written.
            if (preg_match(self::IDENTIFIER, $index['name']) !== 1 || preg_grep(self::IDENTIFIER, $columns, PREG_GREP_INVERT) !== []) {
                continue;
            }
            if ($index['name'] !== Index::of($name, $columns, false, null)->name) {
                continue;
            }
            foreach ($table['indexes'] as $wanted) {
                if (\array_slice($wanted, 0, \count($columns)) === $columns) {
                    continue 2;
                }
            }
            $comments[] = "{$name}.{$index['name']} is an index over (" . implode(', ', $columns) . '), which no relation of the models loads by now. '
                . "If nothing else uses it: \$table->dropIndex({$this->php($index['name'])}).";
        }

        return $comments;
    }

    /**
     * Whether the column's literal default is the one the model declares,
     * read as the column's type: SQLite's 1 is a boolean's true, MySQL's
     * '0' an integer's 0.
     *
     * @param array{type: string, nullable: bool, default: int|float|string|bool|null, defaultKind: string, caseInsensitive: bool} $live
     */
    private static function sameDefault(string $type, mixed $wanted, array $live): bool
    {
        if ($live['defaultKind'] !== 'literal') {
            return false;
        }

        $typed = self::typedDefault($type, $live['default']);
        $declared = self::typedDefault($type, \is_scalar($wanted) ? $wanted : null);

        return \is_float($typed) || \is_float($declared) ? (float) $typed === (float) $declared : $typed === $declared;
    }

    /** A literal default as a value of the builder type it is the default of. */
    private static function typedDefault(string $type, int|float|string|bool|null $value): int|float|string|bool|null
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'integer', 'bigInteger' => is_numeric($value) ? (int) $value : $value,
            'float', 'decimal' => is_numeric($value) ? (float) $value : $value,
            'boolean' => match (strtolower((string) $value)) {
                '1', 't', 'true', 'y', 'yes' => true,
                '0', 'f', 'false', 'n', 'no', '' => false,
                default => $value,
            },
            default => \is_bool($value) ? ($value ? '1' : '0') : (string) $value,
        };
    }

    /**
     * What the dialect calls a column's type, written the builder's way; a
     * type of the model's own the builder would refuse is its own family.
     *
     * @param array<string, mixed> $spec
     */
    private function sqlType(string $column, array $spec): string
    {
        $type = \is_string($spec['type'] ?? null) ? $spec['type'] : 'text';
        try {
            return $this->dialect->typeOf(new Column('c', $type));
        } catch (\InvalidArgumentException) {
            return $type;
        }
    }

    /** @param array{columns: array<string, array<string, mixed>>, indexes: list<list<string>>, tenant: string|null, keys: list<string>, uniques: list<list<string>>} $table */
    private function renderCreate(string $name, array $table): string
    {
        $lines = [];
        foreach ($table['columns'] as $column => $spec) {
            $lines[] = '            ' . $this->renderColumn((string) $column, $spec) . ';';
        }
        if ($table['tenant'] !== null) {
            $lines[] = "            \$table->tenant({$this->php($table['tenant'])});";
        }
        foreach ($table['indexes'] as $columns) {
            $lines[] = "            \$table->index({$this->phpList($columns)});";
        }
        foreach ($table['uniques'] as $columns) {
            $lines[] = "            \$table->unique({$this->phpList($columns)});";
        }

        return "        \$schema->create({$this->php($name)}, function (Table \$table): void {\n" . implode("\n", $lines) . "\n        });";
    }

    /** @param array<string, mixed> $spec */
    private function renderColumn(string $column, array $spec): string
    {
        $call = $this->renderType($column, ['type' => \is_string($spec['type'] ?? null) ? $spec['type'] : 'text', 'length' => null, 'scale' => null]);

        if (($spec['primary'] ?? false) === true) {
            $call .= '->primary()';
        }
        if (($spec['nullable'] ?? false) === true) {
            $call .= '->nullable()';
        }
        if (\array_key_exists('default', $spec)) {
            $call .= '->default(' . var_export($spec['default'], true) . ')';
        }
        $references = $spec['references'] ?? null;
        if (\is_array($references) && \is_string($references[0] ?? null) && \is_string($references[1] ?? null)) {
            $call .= $references[1] === 'id'
                ? "->references({$this->php($references[0])})"
                : "->references({$this->php($references[0])}, {$this->php($references[1])})";
        }

        return $call;
    }

    /**
     * A column's whole definition with change() after it.
     *
     * @param array{type: string, length: int|null, scale: int|null} $type
     * @param array{string, int|float|string|bool|null} $default its kind and, for a literal, its value
     */
    private function renderChange(string $column, array $type, bool $nullable, array $default, bool $caseInsensitive): string
    {
        $call = $this->renderType($column, $type);
        if ($nullable) {
            $call .= '->nullable()';
        }
        if ($default[0] === 'literal') {
            $call .= '->default(' . var_export(self::typedDefault($type['type'], $default[1]), true) . ')';
        }
        if ($caseInsensitive) {
            $call .= '->caseInsensitive()';
        }

        return $call . '->change()';
    }

    /** @param array{type: string, length: int|null, scale: int|null} $type */
    private function renderType(string $column, array $type): string
    {
        $name = $this->php($column);

        return match (true) {
            $type['type'] === 'string' && $type['length'] !== null && $type['length'] !== 255 => "\$table->string({$name}, {$type['length']})",
            $type['type'] === 'decimal' && $type['length'] !== null => "\$table->decimal({$name}, {$type['length']}, " . ($type['scale'] ?? 2) . ')',
            \in_array($type['type'], Column::TYPES, true) => "\$table->{$type['type']}({$name})",
            default => "\$table->column({$name}, {$this->php($type['type'])})",
        };
    }

    /**
     * Tables made before the tables that reference them, since Postgres and
     * MySQL check a foreign key's table exists when the key is made. A cycle
     * keeps the models' order, and fails there for whoever reviews it.
     *
     * @template T of array{columns: array<string, array<string, mixed>>, indexes: list<list<string>>, tenant: string|null, keys: list<string>, uniques: list<list<string>>}
     * @param array<string, T> $tables
     * @return array<string, T>
     */
    private function inDependencyOrder(array $tables): array
    {
        $ordered = [];
        $visiting = [];
        $visit = static function (string $name) use (&$visit, &$ordered, &$visiting, $tables): void {
            if (isset($ordered[$name]) || isset($visiting[$name])) {
                return;
            }
            $visiting[$name] = true;
            foreach ($tables[$name]['columns'] as $spec) {
                $references = $spec['references'] ?? null;
                if (\is_array($references) && \is_string($references[0] ?? null) && isset($tables[$references[0]]) && $references[0] !== $name) {
                    $visit($references[0]);
                }
            }
            $ordered[$name] = $tables[$name];
        };
        foreach (array_keys($tables) as $name) {
            $visit($name);
        }

        return $ordered;
    }

    /**
     * Pairs each wanted name with its one candidate, where that candidate is
     * no other's: a rename read only where nothing else could be meant.
     *
     * @param array<string, list<string>> $candidates
     * @return array<string, string>
     */
    private static function oneToOne(array $candidates): array
    {
        $pairs = [];
        foreach ($candidates as $wanted => $found) {
            if (\count($found) !== 1) {
                continue;
            }
            $claimants = array_filter($candidates, static fn (array $others): bool => \in_array($found[0], $others, true));
            if (\count($claimants) === 1) {
                $pairs[(string) $wanted] = $found[0];
            }
        }

        return $pairs;
    }

    /**
     * An index over the leading columns of another does the other's work
     * too, so only the longer is kept: a relation's (order_id) beside its
     * loads' (order_id, position).
     *
     * @param list<list<string>> $indexes
     * @return list<list<string>>
     */
    private static function withoutCoveredIndexes(array $indexes): array
    {
        $kept = [];
        foreach ($indexes as $i => $columns) {
            $others = $indexes;
            unset($others[$i]);
            $longer = array_filter($others, static fn (array $other): bool => \count($other) > \count($columns));
            if (!self::covered($columns, array_values($longer)) && !\in_array($columns, $kept, true)) {
                $kept[] = $columns;
            }
        }

        return $kept;
    }

    /**
     * @param list<string> $columns
     * @param list<list<string>> $indexes
     */
    private static function covered(array $columns, array $indexes): bool
    {
        foreach ($indexes as $index) {
            if (\array_slice($index, 0, \count($columns)) === $columns) {
                return true;
            }
        }

        return false;
    }

    /**
     * Text as `//` comment lines. A line break would end the comment, and
     * `?>` ends PHP itself even inside one, so neither reaches the file
     * whatever the text came from.
     */
    private static function comment(string $text): string
    {
        $text = str_replace(["\r", "\n", '?>'], ['', '', '?\\>'], $text);

        return '        // ' . wordwrap($text, 90, "\n        // ", true);
    }

    /** Text from the database, for a warning: quoted, so nothing in it reads as the warning's own. */
    private static function quoted(string $text): string
    {
        return (string) json_encode($text, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function php(string $value): string
    {
        return var_export($value, true);
    }

    /** @param list<string> $values */
    private function phpList(array $values): string
    {
        return '[' . implode(', ', array_map($this->php(...), $values)) . ']';
    }
}
