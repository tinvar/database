<?php

declare(strict_types=1);

namespace Tinvar\Orm\Migrations;

use Tinvar\Orm\Attributes\Column as ColumnAttribute;
use Tinvar\Orm\Connection;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\Model;
use Tinvar\Orm\OnDelete;
use Tinvar\Orm\RelationKind;
use Tinvar\Orm\RelationMeta;
use Tinvar\Orm\Schema\Column;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Index;
use Tinvar\Support\Str;

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
 * changed, or whose width or integer size #[Column] declares otherwise
 * (ADR 0110), and renames a column or table where the difference reads
 * as one rename and nothing else could, or where `--rename` says one. It
 * never drops: a column the database has and no model maps, an index no
 * relation loads by any more, and a foreign key, check or unique index
 * the models do not have as the database does, are named in a comment
 * and a warning, for whoever reviews the file to decide.
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
     * @param list<array{table: string|null, from: string, to: string}> $renames the renames
     *        whoever runs make:migration says outright (`--rename`): a table's where `table`
     *        is null, else a column's of the models' table `table`. Each is held to being one:
     *        `from` in the database and on no model, `to` on a model and not in the database --
     *        or the migration is refused, naming it.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly Dialect $dialect,
        ?\Closure $confirmRename = null,
        private readonly array $renames = [],
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
        $renamedFrom = $this->saidTableRenames($wanted);
        $lookAlikes = [];
        foreach ($this->tableRenames($wanted) as $new => $old) {
            if (isset($renamedFrom[$new]) || \in_array($old, $renamedFrom, true)) {
                continue;
            }
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

        $unmapped = $this->unmappedTables($wanted, [...array_values($renamedFrom), ...array_values($lookAlikes)]);
        if ($unmapped !== null) {
            $this->warnings[] = $unmapped;
            array_unshift($up, self::comment($unmapped));
        }

        // Comments alone -- a column or a table no model maps -- are not a
        // migration.
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

    /**
     * The tables the database has that no model maps, named and never
     * dropped (ADR 0122): a table a migration made for something no model
     * reads -- sessions, a counter -- or one a removed model left behind is
     * the same to the database, and only whoever reviews the file can tell
     * them apart. Not the framework's own (`tinvar_`), not one this
     * migration renames or names as a look-alike, and by name only where
     * the name is a plain identifier; the rest are counted.
     *
     * @param array<string, mixed> $wanted the models' tables
     * @param list<string> $accounted tables the migration names already
     */
    private function unmappedTables(array $wanted, array $accounted): ?string
    {
        $named = [];
        $unnamed = 0;
        foreach ($this->dialect->tables($this->connection) as $table) {
            if (isset($wanted[$table]) || \in_array($table, $accounted, true) || str_starts_with($table, 'tinvar_')) {
                continue;
            }
            if (preg_match(self::IDENTIFIER, $table) === 1) {
                $named[] = $table;
            } else {
                $unnamed++;
            }
        }
        if ($named === [] && $unnamed === 0) {
            return null;
        }

        $list = implode(', ', $named) . ($unnamed === 0 ? '' : ($named === [] ? '' : ', and ') . "{$unnamed} more whose names are not plain identifiers");

        return "The database has tables no model maps: {$list}. A migration of the models drops none; dropping one is yours to decide.";
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
                    'type' => self::keyType($relation->target),
                    'nullable' => new \ReflectionProperty($model, $property)->getType()?->allowsNull() ?? false,
                    'references' => [$target->table, $target->idColumn],
                    'onDelete' => $relation->onDelete,
                    'model' => $relation->target,
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
                    'type' => self::keyType($model),
                    'nullable' => false,
                    'references' => [$meta->table, $meta->idColumn],
                    'model' => $model,
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
        $declared = ($property->getAttributes(ColumnAttribute::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null)?->newInstance();
        $cast = Metadata::castOf($property->getDeclaringClass()->getName(), $property);
        $storage = $declared->type ?? ($cast === null ? null : $cast::columnType());
        $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : 'mixed';

        $builderType = $storage !== null ? self::storageType($storage) : match (true) {
            $typeName === 'int' => 'integer',
            $typeName === 'float' => 'float',
            $typeName === 'bool' => 'boolean',
            $typeName === 'array' => 'json',
            is_a($typeName, \DateTimeImmutable::class, true) => 'timestamp',
            $typeName === 'string' => 'string',
            is_subclass_of($typeName, \BackedEnum::class) => (new \ReflectionEnum($typeName))->getBackingType()?->getName() === 'int' ? 'integer' : 'string',
            default => 'text',
        };

        $column = ['type' => $builderType, 'nullable' => $type?->allowsNull() ?? true];
        // A backed enum stored as itself, held to its cases' values: written
        // as enum(), and its check compared case by case (enumChecks()).
        if ($storage === null && is_subclass_of($typeName, \BackedEnum::class)) {
            $column['enum'] = $typeName;
        }
        // A width and a check, where the model says one (ADR 0110). A size
        // is compared only where #[Column] says it: a plain `int` or
        // `string` is as happy in a bigint or a varchar(100).
        if ($declared?->length !== null) {
            $column['length'] = $declared->length;
        }
        if ($declared?->length !== null || $declared?->type !== null) {
            $column['sized'] = true;
        }
        if ($declared?->check !== null) {
            $column['check'] = $declared->check;
        }
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

    /**
     * The builder's type of a key referencing $model, as foreign() types
     * one: a bigInteger for an int key, a uuid for any other.
     *
     * @param class-string<Model> $model
     */
    private static function keyType(string $model): string
    {
        $key = new \ReflectionProperty($model, Metadata::for($model)->idProperty)->getType();

        return $key instanceof \ReflectionNamedType && $key->getName() === 'int' ? 'bigInteger' : 'uuid';
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
     * The table renames said with --rename, each held to being one.
     *
     * @param array<string, array{columns: array<string, array<string, mixed>>, indexes: list<list<string>>, tenant: string|null, keys: list<string>, uniques: list<list<string>>}> $wanted
     * @return array<string, string> the wanted name => the database's name
     * @throws MigrationRefused for a rename that is not one
     */
    private function saidTableRenames(array $wanted): array
    {
        $renamed = [];
        foreach ($this->renames as ['table' => $table, 'from' => $from, 'to' => $to]) {
            if ($table !== null) {
                continue;
            }
            $why = match (true) {
                !isset($wanted[$to]) => "no model's table is {$to}",
                $this->dialect->hasTable($this->connection, $to) => "{$to} is in the database already",
                isset($wanted[$from]) => "{$from} is a model's table",
                !$this->dialect->hasTable($this->connection, $from) => "the database has no {$from}",
                isset($renamed[$to]) || \in_array($from, $renamed, true) => 'it names a table another --rename names',
                default => null,
            };
            if ($why !== null) {
                throw new MigrationRefused("--rename={$from}:{$to} is not a rename here: {$why}.");
            }
            $renamed[$to] = $from;
        }

        return $renamed;
    }

    /**
     * The column renames said with --rename for the models' table $table,
     * each held to being one.
     *
     * @param list<string> $missing
     * @param list<string> $unmapped
     * @return array<string, string> the wanted name => the database's name
     * @throws MigrationRefused for a rename that is not one
     */
    private function saidColumnRenames(string $table, array $missing, array $unmapped): array
    {
        $renamed = [];
        foreach ($this->renames as ['table' => $of, 'from' => $from, 'to' => $to]) {
            if ($of !== $table) {
                continue;
            }
            $why = match (true) {
                !\in_array($to, $missing, true) => "{$table}.{$to} is not on a model, or is in the database already",
                !\in_array($from, $unmapped, true) => "{$table}.{$from} is not in the database, or is on a model",
                isset($renamed[$to]) || \in_array($from, $renamed, true) => 'it names a column another --rename names',
                default => null,
            };
            if ($why !== null) {
                throw new MigrationRefused("--rename={$table}.{$from}:{$to} is not a rename here: {$why}.");
            }
            $renamed[$to] = $from;
        }

        return $renamed;
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
        $renamedColumns = [];
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

        // Every table's renames said outright -- a table renamed, too, by
        // the models' name -- then those that read as one, asked about.
        $said = $this->saidColumnRenames($name, $missing, $unmapped);
        $guessed = $this->columnRenames(
            array_values(array_diff($missing, array_keys($said))),
            array_values(array_diff($unmapped, $said)),
            $table['columns'],
            $columns,
        );
        foreach ([...$said, ...$guessed] as $new => $old) {
            $new = (string) $new;
            if (!isset($said[$new]) && !($this->confirmRename)("Is the column {$name}.{$old}, on no model, the model's {$new} renamed? Renamed, it keeps its values.")) {
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
            $renamedColumns[$old] = $new;
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
        $comments = [...$comments, ...$this->keysChecksAndUniques($name, $live, $table, $columns, $liveIndexes, $renamedColumns)];
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
        // Within a family: int to bigint, varchar(100) to varchar(40), as
        // this dialect stores each -- SQLite's TEXT has no width to change.
        $widthChanged = !$typeChanged && $this->widthChanged($spec, $live['type']);
        $nullChanged = $nullable !== $live['nullable'];
        $declaresDefault = \array_key_exists('default', $spec);
        $defaultChanged = $declaresDefault && !self::sameDefault($wantedType, $spec['default'], $live);
        if (!$typeChanged && !$widthChanged && !$nullChanged && !$defaultChanged) {
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
        if ($widthChanged) {
            $comments[] = "{$table}.{$column} is " . self::describe($liveType) . ' in the database and ' . self::describe(self::typeOfSpec($spec)) . ' on the model. '
                . 'Narrowed, a value that no longer fits fails the change -- MySQL outside strict mode cuts it short instead -- so find those first; review it.';
        }
        if ($nullChanged && !$nullable) {
            $comments[] = "{$table}.{$column} takes NULL in the database and not on the model: a row holding NULL fails this, so backfill those rows first.";
        }

        $upType = $typeChanged || $widthChanged ? self::typeOfSpec($spec) : $liveType;
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
     * What the models say of an existing table's foreign keys, checks and
     * unique keys that the database does not have, or has otherwise --
     * named for whoever reviews the migration, and warned of, and never
     * written as a change: the builder adds a key or a check only with
     * its column, and dropping one is a drop, which stays explicit (ADR
     * 0110). The database's own text reaches the file only as a plain
     * identifier, or as comment text that cannot end its comment.
     *
     * A table the models declare no check on, or no unique key, is not
     * compared for those: a check or unique index an application made in a
     * migration of its own, before the models could say one, is not news.
     *
     * @param array{columns: array<string, array<string, mixed>>, indexes: list<list<string>>, tenant: string|null, keys: list<string>, uniques: list<list<string>>} $table
     * @param array<string, mixed> $columns the database's, by the names this migration leaves them
     * @param list<array{name: string, columns: list<string>, unique: bool}> $liveIndexes
     * @param array<string, string> $renamed the database's name of a column this migration renames => the model's
     * @return list<string>
     */
    private function keysChecksAndUniques(string $name, string $live, array $table, array $columns, array $liveIndexes, array $renamed): array
    {
        $found = [];

        $keys = $this->dialect->foreignKeys($this->connection, $live);
        if ($keys !== null) {
            $byColumn = [];
            foreach ($keys as $key) {
                $byColumn[$renamed[$key['column']] ?? $key['column']] = $key;
            }
            foreach ($table['columns'] as $column => $spec) {
                $column = (string) $column;
                if (!isset($columns[$column])) {
                    continue; // added by this migration, with its key
                }
                $references = $spec['references'] ?? null;
                $key = $byColumn[$column] ?? null;
                if (\is_array($references) && \is_string($references[0] ?? null) && \is_string($references[1] ?? null)) {
                    [$target, $targetColumn] = $references;
                    if ($key === null) {
                        $found[] = "{$name}.{$column} references {$target} on the model, and has no foreign key in the database. The builder adds a key only "
                            . 'with its column: add it by hand, after finding any row that references nothing.';
                    } elseif ($key['table'] !== $target || ($key['references'] !== '' && $key['references'] !== $targetColumn)) {
                        $was = preg_match(self::IDENTIFIER, $key['table']) === 1 ? $key['table'] : 'another table';
                        $found[] = "{$name}.{$column} references {$target} on the model, and {$was} in the database. Changing a key is "
                            . 'dropping it and adding another: do both by hand, after finding any row that would reference nothing.';
                    } elseif (\is_string($spec['onDelete'] ?? null) && !self::sameDeleteAction($spec['onDelete'], $key['onDelete'])) {
                        // Only where the model names one: an action written
                        // in a migration before the models could say one is
                        // not news. The database's, written only if it is one
                        // SQL spells (ADR 0122).
                        $was = \in_array($key['onDelete'], [...RelationMeta::DELETE_ACTIONS, 'SET DEFAULT'], true) ? $key['onDelete'] : 'another action';
                        $found[] = "{$name}.{$column} is deleted with ON DELETE {$spec['onDelete']} on the model, and {$was} in the database. "
                            . 'Changing a key\'s action is dropping the key and adding it again: do both by hand.';
                    }
                } elseif ($key !== null && ($spec['primary'] ?? false) !== true) {
                    $was = preg_match(self::IDENTIFIER, $key['table']) === 1 ? $key['table'] : 'another table';
                    $found[] = "{$name}.{$column} has a foreign key to {$was} in the database that no relation of the models reads by. Dropping it is yours to decide.";
                }
            }
        }

        $wantedChecks = [];
        foreach ($table['columns'] as $column => $spec) {
            if (\is_string($spec['check'] ?? null) && isset($columns[(string) $column])) {
                $wantedChecks[(string) $column] = $spec['check'];
            }
        }
        $declaresChecks = array_filter(array_column($table['columns'], 'check'), \is_string(...)) !== [];
        $declaresEnums = array_filter(array_column($table['columns'], 'enum'), \is_string(...)) !== [];
        $checks = $declaresChecks || $declaresEnums ? $this->dialect->checks($this->connection, $live) : null;
        $enumChecks = [];
        if ($checks !== null && $declaresEnums) {
            [$enumFound, $enumChecks] = self::enumChecks($name, $table['columns'], $columns, $checks);
            $found = [...$found, ...$enumFound];
        }
        if ($checks !== null && $declaresChecks) {
            // An enum's own check is the enum's, compared above.
            $checks = array_values(array_diff_key($checks, $enumChecks));
            $liveChecks = array_map(self::checkText(...), $checks);
            foreach ($wantedChecks as $column => $check) {
                if (!\in_array(self::checkText($check), $liveChecks, true)) {
                    $found[] = "{$name}.{$column} is held to CHECK ({$check}) on the model, and the database has no such check. The builder adds a "
                        . 'check only with its column -- on SQLite a check is added by rebuilding the table -- so add it by hand, after finding any row it refuses.';
                }
            }
            $modelChecks = array_map(self::checkText(...), array_values(array_filter(array_column($table['columns'], 'check'), \is_string(...))));
            foreach ($checks as $i => $check) {
                if (!\in_array($liveChecks[$i], $modelChecks, true)) {
                    // The database's text, quoted: nothing in it reads as the
                    // finding's own, in the file or on a terminal.
                    $found[] = "{$name} has a check in the database that no model declares, " . self::quoted(mb_substr($check, 0, 200)) . '. Dropping it is yours to decide.';
                }
            }
        }

        if ($table['uniques'] !== []) {
            $primary = array_keys(array_filter($table['columns'], static fn (array $spec): bool => ($spec['primary'] ?? false) === true));
            $wantedUniques = array_map(static function (array $columns): array {
                sort($columns);

                return $columns;
            }, $table['uniques']);
            foreach ($liveIndexes as $index) {
                $sorted = $index['columns'];
                sort($sorted);
                if (!$index['unique'] || $sorted === [] || $sorted === $primary || \in_array($sorted, $wantedUniques, true)) {
                    continue;
                }
                if (preg_match(self::IDENTIFIER, $index['name']) !== 1 || preg_grep(self::IDENTIFIER, $index['columns'], PREG_GREP_INVERT) !== []) {
                    continue;
                }
                $found[] = "{$name}.{$index['name']} is a unique index over (" . implode(', ', $index['columns']) . ') that no #[Unique] of the models declares. '
                    . "If it is not wanted any more: \$table->dropIndex({$this->php($index['name'])}).";
            }
        }

        foreach ($found as $finding) {
            $this->warnings[] = $finding;
        }

        return $found;
    }

    /**
     * Each enum column's check, found among the database's and read case
     * by case: one naming the column and a value of the enum is its check,
     * however the database spells it -- SQLite keeps `stage IN ('placed',
     * ...)` as written, Postgres turns it into `= ANY (ARRAY[...])` -- and
     * a case whose value it does not name is one the database refuses.
     * Named for whoever reviews the migration and never written: a check
     * is widened by a migration of its own (Table::enum()).
     *
     * @param array<array-key, array<string, mixed>> $wanted the models' columns
     * @param array<string, mixed> $columns the database's
     * @param list<string> $checks the database's checks
     * @return array{list<string>, array<int, true>} the findings, and which of $checks are enums' checks
     */
    private static function enumChecks(string $table, array $wanted, array $columns, array $checks): array
    {
        $found = [];
        $claimed = [];
        // Words kept apart, unlike checkText(): a column is found as a word.
        // Postgres casts each value (`'placed'::character varying`); MySQL
        // escapes its quotes (`_utf8mb4\'placed\'`).
        $texts = array_map(
            static fn (string $check): string => (string) preg_replace(
                ['/::\s*[a-z_ ]+(\(\d+\))?/', '/\\\\\'/', '/["`\[\]()]/'],
                ['', "'", ' '],
                strtolower($check),
            ),
            $checks,
        );
        foreach ($wanted as $column => $spec) {
            $column = (string) $column;
            $enum = $spec['enum'] ?? null;
            if (!\is_string($enum) || !is_subclass_of($enum, \BackedEnum::class) || !isset($columns[$column])) {
                continue;
            }
            $values = array_map(static fn (\BackedEnum $case): int|string => $case->value, $enum::cases());
            $named = static fn (string $text, int|string $value): bool => \is_int($value)
                ? preg_match('/(?<![0-9a-z_.])' . $value . '(?![0-9a-z_.])/', $text) === 1
                : str_contains($text, "'" . str_replace("'", "''", strtolower($value)) . "'");

            $at = null;
            foreach ($texts as $i => $text) {
                if (preg_match('/(?<![a-z0-9_])' . preg_quote(strtolower($column), '/') . '(?![a-z0-9_])/', $text) !== 1) {
                    continue;
                }
                foreach ($values as $value) {
                    if ($named($text, $value)) {
                        $at = $i;
                        break 2;
                    }
                }
            }

            $short = Str::shortName($enum);
            if ($at === null) {
                $found[] = "{$table}.{$column} holds one of {$short}'s values on the model, and the database has no check holding it to them. "
                    . 'The builder adds a check only with its column -- on SQLite a check is added by rebuilding the table -- so add it by hand, after finding any row it refuses.';
                continue;
            }
            $claimed[$at] = true;
            $missing = array_values(array_filter($values, static fn (int|string $value): bool => !$named($texts[$at], $value)));
            if ($missing !== []) {
                $list = implode(', ', array_map(static fn (int|string $value): string => \is_int($value) ? (string) $value : var_export($value, true), $missing));
                $found[] = "{$table}.{$column} holds one of {$short}'s values on the model, and the database's check does not take {$list}. "
                    . 'Widening a check is dropping it and adding it again -- on SQLite, rebuilding the table -- so do it by hand, in a migration of its own.';
            }
        }

        return [$found, $claimed];
    }

    /**
     * Whether two delete actions are one: RESTRICT and NO ACTION both
     * refuse the delete, and differ only in whether a deferred check could
     * let it through, which no key the builder makes is.
     */
    private static function sameDeleteAction(string $model, string $live): bool
    {
        $refuses = ['RESTRICT', 'NO ACTION'];

        return $model === $live || (\in_array($model, $refuses, true) && \in_array($live, $refuses, true));
    }

    /** A check's expression as it is compared: no case, spaces, brackets, quotes or casts. */
    private static function checkText(string $check): string
    {
        $text = strtolower($check);
        $text = (string) preg_replace('/::\s*[a-z_ ]+(\(\d+\))?/', '', $text);

        return (string) preg_replace('/[\s()"`\[\]]+/', '', $text);
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
     * The builder's type of a column the model describes: its type, and a
     * string's width where the model says one.
     *
     * @param array<string, mixed> $spec
     * @return array{type: string, length: int|null, scale: int|null}
     */
    private static function typeOfSpec(array $spec): array
    {
        return [
            'type' => \is_string($spec['type'] ?? null) ? $spec['type'] : 'text',
            'length' => \is_int($spec['length'] ?? null) ? $spec['length'] : null,
            'scale' => null,
        ];
    }

    /**
     * Whether the model's column and the database's, of one family, differ
     * in their size, where #[Column] says one: read through the dialect, so
     * what it stores alike -- SQLite's INTEGER for int and bigint -- is no
     * change. Only the sizes a migration writes are compared: integers and
     * strings.
     *
     * @param array<string, mixed> $spec
     */
    private function widthChanged(array $spec, string $liveType): bool
    {
        if (($spec['sized'] ?? false) !== true) {
            return false;
        }
        $wanted = self::typeOfSpec($spec);
        try {
            $stored = $this->dialect->typeOf(new Column('c', $wanted['type'], $wanted['length']));
        } catch (\InvalidArgumentException) {
            return false;
        }
        // Stored as the database has it already: SQLite's INTEGER for a
        // bigint, its TEXT for a varchar of any width.
        if (self::spelled($stored) === self::spelled($liveType)) {
            return false;
        }
        $live = $this->dialect->builderType($liveType);
        $sized = ['integer', 'bigInteger', 'string'];
        if ($live === null || !\in_array($wanted['type'], $sized, true) || !\in_array($live['type'], $sized, true)) {
            return false;
        }

        return $wanted['type'] !== $live['type'] || ($wanted['type'] === 'string' && ($wanted['length'] ?? 255) !== ($live['length'] ?? 255));
    }

    /** A type as compared: no case, no spaces. */
    private static function spelled(string $type): string
    {
        return (string) preg_replace('/\s+/', '', strtolower($type));
    }

    /** @param array{type: string, length: int|null, scale: int|null}|null $type */
    private static function describe(?array $type): string
    {
        return match (true) {
            $type === null => 'of a type of its own',
            $type['type'] === 'string' => 'string(' . ($type['length'] ?? 255) . ')',
            default => $type['type'],
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
        $stamped = self::stampedPair($table['columns']);
        foreach ($table['columns'] as $column => $spec) {
            $column = (string) $column;
            if ($stamped && \in_array($column, ['created_at', 'updated_at'], true)) {
                // The pair the ORM stamps, written once, where the first was.
                if (!\in_array('            $table->timestamps();', $lines, true)) {
                    $lines[] = '            $table->timestamps();';
                }
                continue;
            }
            $lines[] = '            ' . $this->renderColumn($column, $spec) . ';';
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

    /**
     * Whether a table has created_at and updated_at as timestamps() makes
     * them: both moments, both required, with nothing else said of either.
     *
     * @param array<array-key, array<string, mixed>> $columns
     */
    private static function stampedPair(array $columns): bool
    {
        foreach (['created_at', 'updated_at'] as $name) {
            $spec = $columns[$name] ?? null;
            if ($spec === null || ($spec['type'] ?? null) !== 'timestamp' || ($spec['nullable'] ?? false) !== false
                || array_diff(array_keys($spec), ['type', 'nullable']) !== []) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $spec */
    private function renderColumn(string $column, array $spec): string
    {
        $model = \is_string($spec['model'] ?? null) && is_subclass_of($spec['model'], Model::class) ? $spec['model'] : null;
        $enum = \is_string($spec['enum'] ?? null) ? $spec['enum'] : null;
        $call = match (true) {
            // A key from its model: the name, type, table and key are the
            // model's, and the column is named only where it is not the
            // one foreign() would name it.
            $model !== null => '$table->foreign(' . self::className($model)
                . ($column === Str::snake(Str::shortName($model)) . '_id' ? '' : ", {$this->php($column)}") . ')',
            $enum !== null => "\$table->enum({$this->php($column)}, " . self::className($enum) . ')',
            default => $this->renderType($column, self::typeOfSpec($spec)),
        };

        if (($spec['primary'] ?? false) === true) {
            $call .= '->primary()';
        }
        if (($spec['nullable'] ?? false) === true) {
            $call .= '->nullable()';
        }
        if (\array_key_exists('default', $spec)) {
            $call .= '->default(' . var_export($spec['default'], true) . ')';
        }
        if (\is_string($spec['check'] ?? null)) {
            $call .= '->check(' . var_export($spec['check'], true) . ')';
        }
        $references = $spec['references'] ?? null;
        if ($model === null && \is_array($references) && \is_string($references[0] ?? null) && \is_string($references[1] ?? null)) {
            $call .= $references[1] === 'id'
                ? "->references({$this->php($references[0])})"
                : "->references({$this->php($references[0])}, {$this->php($references[1])})";
        }
        if (\is_string($spec['onDelete'] ?? null)) {
            $call .= match ($spec['onDelete']) {
                OnDelete::Cascade->value => '->cascadeOnDelete()',
                OnDelete::SetNull->value => '->nullOnDelete()',
                OnDelete::Restrict->value => '->restrictOnDelete()',
                default => '->onDelete(' . self::className(OnDelete::class, '') . '::' . OnDelete::parse($spec['onDelete'])->name . ')',
            };
        }

        return $call;
    }

    /**
     * A class's name as the file writes it: fully qualified, so the file
     * needs no import of it, and with ::class after it unless told not to.
     * A model's or an enum's, from the code: never the database's.
     */
    private static function className(string $class, string $suffix = '::class'): string
    {
        return '\\' . ltrim($class, '\\') . $suffix;
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
