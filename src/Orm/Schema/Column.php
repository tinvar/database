<?php

declare(strict_types=1);

namespace Tinvar\Orm\Schema;

/**
 * One column as a migration declares it, in the builder's own types: each
 * dialect says what a type is called on its database (see Dialect).
 *
 * Fluent, and only for the length of the closure that declares it: once the
 * table is compiled its statements have run, and changing a column after
 * that changes nothing.
 */
final class Column
{
    /** The builder's types; anything else is a database type, passed through as written. */
    public const array TYPES = [
        'uuid', 'string', 'text', 'integer', 'bigInteger', 'boolean', 'float', 'decimal',
        'json', 'timestamp', 'date', 'binary',
    ];

    /**
     * What a database type the builder has no name for may look like, since
     * it is written into the statement as it is: `tsvector`,
     * `numeric(10, 2)`, `double precision`.
     */
    public const string DATABASE_TYPE = '/^[A-Za-z][A-Za-z0-9_ ]{0,62}(\(\d+(\s*,\s*\d+)?\))?\z/';

    public private(set) bool $nullable = false;

    public private(set) bool $hasDefault = false;

    public private(set) int|float|string|bool|Expression|null $default = null;

    public private(set) bool $primary = false;

    public private(set) bool $unique = false;

    public private(set) bool $caseInsensitive = false;

    public private(set) ?string $check = null;

    /** @var array{string, string}|null the table and column this one references */
    public private(set) ?array $references = null;

    public private(set) ?string $onDelete = null;

    /** Whether this declares an existing column anew rather than adding one (change()). */
    public private(set) bool $changing = false;

    /**
     * @param string $type one of TYPES, or a database type of the application's own
     * @param int|null $length a string's most characters; a decimal's precision
     * @param int|null $scale a decimal's digits after the point
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly ?int $length = null,
        public readonly ?int $scale = null,
    ) {
        Identifier::assert($name, 'column');

        // A database type the builder has no name for is written into the
        // statement as it is, so it is held to what a type can look like.
        if (!$this->isPortable() && preg_match(self::DATABASE_TYPE, $type) !== 1) {
            throw new \InvalidArgumentException("'{$type}' is not a column type.");
        }
    }

    /** Whether the type is one of the builder's, which each dialect names, rather than a database's own. */
    public function isPortable(): bool
    {
        return \in_array($this->type, self::TYPES, true);
    }

    public function nullable(bool $nullable = true): self
    {
        $this->nullable = $nullable;

        return $this;
    }

    /**
     * A literal the database fills in when an insert leaves the column out,
     * or an Expression it evaluates (`new Expression('CURRENT_TIMESTAMP')`).
     */
    public function default(int|float|string|bool|Expression|null $value): self
    {
        $this->hasDefault = true;
        $this->default = $value;

        return $this;
    }

    public function primary(): self
    {
        $this->primary = true;

        return $this;
    }

    public function unique(): self
    {
        $this->unique = true;

        return $this;
    }

    /**
     * Compared without regard to case, so `Ada@shop.test` and `ada@shop.test`
     * are one value -- to a UNIQUE constraint and to `WHERE email = ?` alike.
     * SQLite's NOCASE, Postgres's citext, a case-insensitive MySQL collation.
     */
    public function caseInsensitive(): self
    {
        $this->caseInsensitive = true;

        return $this;
    }

    /**
     * A CHECK constraint on the column, as SQL: `price_cents > 0`. Written
     * into the statement as it is, so it is the migration author's SQL, not
     * anyone's input.
     */
    public function check(string $expression): self
    {
        $this->check = $expression;

        return $this;
    }

    /** A foreign key to $table's $column. */
    public function references(string $table, string $column = 'id'): self
    {
        Identifier::assert($table, 'table');
        Identifier::assert($column, 'column');
        $this->references = [$table, $column];

        return $this;
    }

    /** What a delete of the referenced row does to this one: cascade, set null, restrict or no action. */
    public function onDelete(string $action): self
    {
        $action = strtoupper(trim($action));
        if (!\in_array($action, ['CASCADE', 'SET NULL', 'RESTRICT', 'NO ACTION'], true)) {
            throw new \InvalidArgumentException("'{$action}' is not an ON DELETE action.");
        }
        if ($this->references === null) {
            throw new \LogicException("Column '{$this->name}' has no references() for onDelete() to act on.");
        }
        $this->onDelete = $action;

        return $this;
    }

    /**
     * Declares an existing column anew, in Schema::table(): its type,
     * nullability, default and case-insensitivity become what this says,
     * whole -- a default or nullable() it leaves out, the column loses --
     * and its keys, checks and indexes stay as they are. A key, a unique
     * index or a check is added or dropped on its own, not by change().
     *
     *     $table->string('note', 500)->nullable()->change();
     *
     * Postgres alters the column in place, converting each value with a
     * cast; MySQL redefines it; SQLite, which can do neither, rebuilds the
     * table around it. A value that does not convert, or a NULL where the
     * column no longer allows one, fails the migration.
     */
    public function change(): self
    {
        $this->changing = true;

        return $this;
    }
}
