<?php

declare(strict_types=1);

namespace Tinvar\Orm\Schema;

/**
 * A table as a migration describes it: the columns and indexes of a new
 * one (Schema::create()), or the changes to an existing one
 * (Schema::table()). Nothing runs until the closure returns; the dialect
 * then compiles the whole description into its statements.
 *
 *     $schema->create('orders', function (Table $table): void {
 *         $table->uuid('id')->primary();
 *         $table->uuid('customer_id')->references('customers');
 *         $table->string('placed_at');
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

    /** An instant: timestamptz on Postgres, so the offset the ORM writes is kept as the instant it names. */
    public function timestamp(string $name): Column
    {
        return $this->add(new Column($name, 'timestamp'));
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
