<?php

declare(strict_types=1);

namespace Tinvar\Orm\Schema;

use Tinvar\Orm\Metadata;
use Tinvar\Orm\Model;
use Tinvar\Orm\OnDelete;
use Tinvar\Support\Str;

/**
 * A table as a migration describes it: the columns and indexes of a new
 * one (Schema::create()), or the changes to an existing one
 * (Schema::table()). Nothing runs until the closure returns; the dialect
 * then compiles the whole description into its statements.
 *
 *     $schema->create('orders', function (Table $table): void {
 *         $table->uuid('id')->primary();
 *         $table->foreign(Customer::class);
 *         $table->enum('status', OrderStatus::class);
 *         $table->timestamp('placed_at');
 *         $table->index(['customer_id', 'placed_at DESC']);
 *     });
 */
final class Table
{
    /** @var list<Column> */
    public private(set) array $columns = [];

    /** @var list<Index> */
    public private(set) array $indexes = [];

    /** @var list<string>|null a primary key over several columns */
    public private(set) ?array $primaryKey = null;

    /** @var list<string> */
    public private(set) array $droppedColumns = [];

    /** @var array<string, string> old name => new name */
    public private(set) array $renamedColumns = [];

    /** @var list<string> */
    public private(set) array $droppedIndexes = [];

    /** The column tenant() added, which Postgres holds to row-level security. */
    public private(set) ?string $tenantColumn = null;

    /** Whether pivot() has made this table's unique key, which tenant() would have led. */
    private bool $pivoted = false;

    public function __construct(
        public readonly string $name,
        public readonly bool $creating,
    ) {
        Identifier::assert($name, 'table');
    }

    /** A UUID: the ORM's primary keys, minted as UUIDv7 on insert, and the keys that reference them. */
    public function uuid(string $name): Column
    {
        return $this->add(new Column($name, 'uuid'));
    }

    /**
     * Text up to $length characters: what can be indexed and made unique on
     * every dialect, where MySQL cannot index a TEXT column whole.
     */
    public function string(string $name, int $length = 255): Column
    {
        return $this->add(new Column($name, 'string', $length));
    }

    /** Text of any length, which MySQL will not index; string() for what is looked up by. */
    public function text(string $name): Column
    {
        return $this->add(new Column($name, 'text'));
    }

    public function integer(string $name): Column
    {
        return $this->add(new Column($name, 'integer'));
    }

    public function bigInteger(string $name): Column
    {
        return $this->add(new Column($name, 'bigInteger'));
    }

    public function boolean(string $name): Column
    {
        return $this->add(new Column($name, 'boolean'));
    }

    public function float(string $name): Column
    {
        return $this->add(new Column($name, 'float'));
    }

    public function decimal(string $name, int $precision = 10, int $scale = 2): Column
    {
        return $this->add(new Column($name, 'decimal', $precision, $scale));
    }

    /** JSON: jsonb on Postgres, JSON on MySQL, text on SQLite -- what an array or value-object property is stored as. */
    public function json(string $name): Column
    {
        return $this->add(new Column($name, 'json'));
    }

    /**
     * An instant, which a DateTimeImmutable or CarbonImmutable property
     * holds, stored in UTC as each dialect keeps one best (ADR 0145):
     * timestamptz on Postgres, which keeps the instant the ORM's offset
     * names; DATETIME(6) on MySQL, written as UTC wall-clock time, so no
     * session zone converts it; ISO 8601 text on SQLite, at a fixed width
     * in UTC, which sorts as it reads.
     */
    public function timestamp(string $name): Column
    {
        return $this->add(new Column($name, 'timestamp'));
    }

    /**
     * created_at and updated_at, the instants the ORM stamps a model's
     * createdAt and updatedAt with: required, since every insert stamps
     * both. Added to a table that has rows, they are nullable until a
     * backfill fills them.
     */
    public function timestamps(bool $nullable = false): void
    {
        $this->timestamp('created_at')->nullable($nullable);
        $this->timestamp('updated_at')->nullable($nullable);
    }

    /**
     * A foreign key to a model's table, all of it read from the model: the
     * column is named for the model (`order_id` for Order, `order_line_id`
     * for OrderLine), as a #[BelongsTo] names the key it reads by, unless
     * $column says otherwise; typed as the model's key is; and references
     * its #[Table] and key column.
     *
     *     $table->foreign(Order::class)->cascadeOnDelete();
     *     $table->foreign(Customer::class, 'buyer_id', onDelete: OnDelete::SetNull, nullable: true);
     *
     * The model is read when the migration runs, as it is then: a model
     * whose table is later renamed gives the new name to a database
     * migrated afresh, which is the name the rename's own migration leaves.
     *
     * @param class-string<Model> $model
     */
    public function foreign(string $model, ?string $column = null, ?OnDelete $onDelete = null, bool $nullable = false): Column
    {
        if (!is_subclass_of($model, Model::class)) {
            throw new \InvalidArgumentException("{$model} is not a Model, so it has no table for a foreign key to reference.");
        }
        $meta = Metadata::for($model);
        $key = new \ReflectionProperty($model, $meta->idProperty)->getType();
        $type = match ($key instanceof \ReflectionNamedType ? $key->getName() : null) {
            'string' => 'uuid',
            'int' => 'bigInteger',
            default => throw new \InvalidArgumentException("{$model}'s key is neither a string nor an int, so foreign() cannot type a column for it."),
        };

        $added = $this->add(new Column($column ?? Str::snake(Str::shortName($model)) . '_id', $type))
            ->nullable($nullable)
            ->references($meta->table, $meta->idColumn);

        return $onDelete === null ? $added : $added->onDelete($onDelete);
    }

    /**
     * A pivot table's two sides (ADR 0192): a foreign() key to each model,
     * and the unique key no two links share -- led by the tenant column
     * when tenant() came first, as a #[TenantScoped] model's #[Unique] is,
     * and so also the index a #[BelongsToMany]'s load reads by. Each key is
     * indexed alone too, as make:migration indexes every #[BelongsTo]'s, so
     * the table is the one it would write for the pivot model.
     *
     *     $schema->create('product_tags', function (Table $table): void {
     *         $table->uuid('id')->primary();
     *         $table->tenant();
     *         $table->pivot(Product::class, Tag::class);
     *     });
     *
     * $onDelete is each key's ON DELETE. Left null -- the database's
     * default, which refuses to delete a row still linked -- for a pivot
     * that is #[Audited]: a link the database deletes is one the audit log
     * never hears of. The columns are named as foreign() names them
     * unless given; a model linked to itself needs both.
     *
     * @param class-string<Model> $from
     * @param class-string<Model> $to
     * @return array{Column, Column}
     */
    public function pivot(string $from, string $to, ?string $fromColumn = null, ?string $toColumn = null, ?OnDelete $onDelete = null): array
    {
        $first = $this->foreign($from, $fromColumn, $onDelete);
        $second = $this->foreign($to, $toColumn, $onDelete);
        if ($first->name === $second->name) {
            throw new \LogicException("Table '{$this->name}': both sides of the pivot are '{$first->name}'; name the columns.");
        }

        $this->unique([...($this->tenantColumn === null ? [] : [$this->tenantColumn]), $first->name, $second->name]);
        $this->index([$first->name]);
        $this->index([$second->name]);
        $this->pivoted = true;

        return [$first, $second];
    }

    /**
     * A column holding one of a backed enum's values, and only those: a
     * string for a string-backed enum, an integer for an int-backed one,
     * with a CHECK listing the cases' values -- a CHECK rather than
     * MySQL's ENUM type, so the column is the same on every dialect and a
     * new case is a change to a constraint, not to the column (ADR 0145).
     *
     *     $table->enum('status', OrderStatus::class)->default(OrderStatus::Placed->value);
     *
     * The cases are read when the migration runs. One added to the enum
     * later needs a migration of its own to widen the check: the database
     * refuses its value until then, and make:migration says so.
     *
     * @param class-string<\BackedEnum> $enum
     */
    public function enum(string $name, string $enum): Column
    {
        if (!is_subclass_of($enum, \BackedEnum::class)) {
            throw new \InvalidArgumentException("{$enum} is not a backed enum, so it has no values for '{$name}' to hold.");
        }
        $values = array_map(static fn (\BackedEnum $case): int|string => $case->value, $enum::cases());
        if ($values === []) {
            throw new \InvalidArgumentException("{$enum} has no cases, so '{$name}' could hold nothing.");
        }

        if ((string) new \ReflectionEnum($enum)->getBackingType() === 'int') {
            return $this->integer($name)->oneOf($values);
        }
        $longest = max(array_map(static fn (int|string $value): int => mb_strlen((string) $value), $values));

        return $this->string($name, max(255, $longest))->oneOf($values);
    }

    public function date(string $name): Column
    {
        return $this->add(new Column($name, 'date'));
    }

    public function binary(string $name): Column
    {
        return $this->add(new Column($name, 'binary'));
    }

    /** A column of a type this database has and the builder does not name, written as it is: `tsvector`. */
    public function column(string $name, string $type): Column
    {
        return $this->add(new Column($name, $type));
    }

    /**
     * The tenant column of a #[TenantScoped] model: required, indexed --
     * every query the ORM makes on the table filters by it -- and, on
     * Postgres, held to the row-level security policy RowLevelSecurity
     * writes, so a query that goes around the ORM and forgets the tenant
     * still reads only the tenant's rows, and none with no tenant set.
     *
     * Added to a table that has rows, it needs a default() or a backfill:
     * the column is NOT NULL.
     */
    public function tenant(string $column = 'tenant_id'): Column
    {
        if ($this->tenantColumn !== null) {
            throw new \LogicException("Table '{$this->name}' already has a tenant column, '{$this->tenantColumn}'.");
        }
        if ($this->pivoted) {
            throw new \LogicException("Table '{$this->name}': call tenant() before pivot(), so the tenant column leads the pivot's unique key.");
        }

        $this->tenantColumn = $column;
        $added = $this->string($column);
        $this->index([$column]);

        return $added;
    }

    /** @param list<string> $columns */
    public function primary(array $columns): void
    {
        if (!$this->creating) {
            throw new \LogicException('A primary key is declared when the table is created.');
        }
        foreach ($columns as $column) {
            Identifier::assert($column, 'column');
        }
        $this->primaryKey = $columns;
    }

    /** @param list<string> $columns names, each with ASC or DESC after it where the order matters */
    public function index(array $columns, ?string $name = null): void
    {
        $this->indexes[] = Index::of($this->name, $columns, false, $name);
    }

    /** @param list<string> $columns */
    public function unique(array $columns, ?string $name = null): void
    {
        $this->indexes[] = Index::of($this->name, $columns, true, $name);
    }

    public function dropColumn(string ...$names): void
    {
        $this->altering('dropColumn()');
        foreach ($names as $name) {
            Identifier::assert($name, 'column');
            $this->droppedColumns[] = $name;
        }
    }

    public function renameColumn(string $from, string $to): void
    {
        $this->altering('renameColumn()');
        Identifier::assert($from, 'column');
        Identifier::assert($to, 'column');
        $this->renamedColumns[$from] = $to;
    }

    public function dropIndex(string $name): void
    {
        $this->altering('dropIndex()');
        Identifier::assert($name, 'index');
        $this->droppedIndexes[] = $name;
    }

    private function add(Column $column): Column
    {
        foreach ($this->columns as $existing) {
            if ($existing->name === $column->name) {
                throw new \LogicException("Table '{$this->name}' declares column '{$column->name}' twice.");
            }
        }
        $this->columns[] = $column;

        return $column;
    }

    private function altering(string $what): void
    {
        if ($this->creating) {
            throw new \LogicException("{$what} changes an existing table; it has no place in create().");
        }
    }
}
