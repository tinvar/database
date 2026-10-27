<?php

declare(strict_types=1);

namespace Tinvar\Orm\Builder;

use Tinvar\Orm\MappingException;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\Model;
use Tinvar\Orm\ModelMeta;
use Tinvar\Orm\RelationKind;
use Tinvar\Orm\RelationMeta;

/**
 * Every identifier the query builder puts in SQL, resolved here and only
 * here, against compiled metadata.
 *
 * tinvar-spec.md, "Identifiers from users": "The query builder rejects
 * identifiers that don't come from compiled metadata, so no one can sort by
 * a hidden column." A name is looked up, never passed through: a column, a
 * property that maps one, a #[BelongsTo] that stands for its foreign key,
 * or a relation. What comes back is the metadata's own spelling, so a name
 * that merely looks like one -- quoted, padded, cased differently, carrying
 * a dot or a comment -- matches nothing and is refused before any SQL is
 * built. A name the model does not map is refused however plausible it is.
 *
 * Membership is the guarantee; the shape check in front of it keeps what
 * reaches the lookup to plain names, and quote() checks again, so even a
 * column compiled from a #[Column(name: ...)] that is not a plain name is
 * refused rather than quoted.
 *
 * What this cannot know is which of a model's columns a page shows: every
 * mapped one resolves, a password hash's included. A sort or filter field
 * taken from a request is narrowed first, to a backed enum or an #[In]
 * list of the fields a Data class shows (ADR 0057).
 *
 * @internal for the query builder and its PHPStan rule, inside this package
 */
final class Identifiers
{
    /** How many #[BelongsTo] relations a column may be reached through: 'site.region.name' is two. */
    public const int MAX_DEPTH = 3;

    /** A plain SQL name: what every column, table and relation the metadata compiles has to be. */
    private const string NAME = '/^[A-Za-z_][A-Za-z0-9_]{0,62}\z/';

    /**
     * A column of $meta, or of a model its #[BelongsTo] relations reach
     * ('site.code'): a column name, a property that maps one, or a
     * #[BelongsTo] property, which names its foreign key.
     *
     * A backed enum names the column its value does, so a sort field typed
     * as one passes its case straight in. Its value is looked up like any
     * other name: an enum is a closed set of strings, not a pass.
     */
    public static function column(ModelMeta $meta, string|\BackedEnum $name): ColumnRef
    {
        $name = self::text($name);
        $parts = explode('.', $name);
        $column = array_pop($parts);

        if (\count($parts) > self::MAX_DEPTH) {
            throw new MappingException(
                "'{$name}' walks more than " . self::MAX_DEPTH . ' relations; join no deeper than that.',
            );
        }

        $target = $meta;
        $path = [];
        foreach ($parts as $property) {
            $relation = self::relation($target, $property);
            if ($relation->kind !== RelationKind::BelongsTo) {
                throw new MappingException(
                    "{$target->class}::\${$property} is a #[HasMany]: a column is reached only through #[BelongsTo] "
                    . 'relations, each one row, so a join never repeats the rows it is on. Filter by a collection '
                    . 'with whereHas().',
                );
            }
            $path[] = $property;
            $target = Metadata::for($relation->target);
        }

        return new ColumnRef($path, self::own($target, $column));
    }

    /**
     * A relation of $meta, by its property: what with() and whereHas() name.
     */
    public static function relation(ModelMeta $meta, string $property): RelationMeta
    {
        if (preg_match(self::NAME, $property) !== 1) {
            throw MappingException::malformedIdentifier($property);
        }

        return $meta->relations[$property] ?? throw new MappingException(
            "{$meta->class} has no relation '{$property}'. The query builder only accepts relations from compiled "
            . 'metadata. Known: ' . (implode(', ', array_keys($meta->relations)) ?: 'none') . '.',
        );
    }

    /**
     * A path of relations from $meta ('lines.product'), each one checked.
     *
     * @return non-empty-list<RelationMeta>
     */
    public static function relationPath(ModelMeta $meta, string $path): array
    {
        $parts = explode('.', $path);
        if (\count($parts) > self::MAX_DEPTH + 1) {
            throw new MappingException("'{$path}' walks more than " . (self::MAX_DEPTH + 1) . ' relations.');
        }

        $relations = [];
        $target = $meta;
        foreach ($parts as $property) {
            $relation = self::relation($target, $property);
            $relations[] = $relation;
            $target = Metadata::for($relation->target);
        }

        return $relations;
    }

    /**
     * One identifier, double-quoted -- or a dotted table name, each part
     * quoted -- once it is a plain name. Quoting is not what makes a name
     * safe; being the metadata's is. This is the last check before SQL, so
     * a name that got past everything else still cannot close the quote.
     */
    public static function quote(string $identifier): string
    {
        $parts = explode('.', $identifier);
        foreach ($parts as $part) {
            if (preg_match(self::NAME, $part) !== 1) {
                throw MappingException::malformedIdentifier($identifier);
            }
        }

        return '"' . implode('"."', $parts) . '"';
    }

    /**
     * Every name column() takes for $meta itself: its columns, the
     * properties mapping them, and its #[BelongsTo] properties.
     *
     * @return list<string>
     */
    public static function names(ModelMeta $meta): array
    {
        $names = [...$meta->knownIdentifiers(), ...array_keys($meta->columns)];
        foreach ($meta->relations as $property => $relation) {
            if ($relation->kind === RelationKind::BelongsTo) {
                $names[] = $property;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * The compiled metadata of $class, or null where it is no model that
     * compiles: for the PHPStan rule, which knows a class only by its name,
     * and must leave a model that does not compile to fail where it is read.
     */
    public static function model(string $class): ?ModelMeta
    {
        try {
            return is_a($class, Model::class, true) ? Metadata::for($class) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** A column of $meta itself, by any name it is known by. */
    private static function own(ModelMeta $meta, string $name): string
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw MappingException::malformedIdentifier($name);
        }

        $found = [];
        if (isset($meta->columns[$name])) {
            $found[] = $meta->columns[$name];
        }
        $relation = $meta->relations[$name] ?? null;
        if ($relation !== null && $relation->kind === RelationKind::BelongsTo) {
            $found[] = $relation->foreignKey;
        }
        if (\in_array($name, $meta->knownIdentifiers(), true)) {
            $found[] = $name;
        }

        $found = array_values(array_unique($found));
        if ($found === []) {
            throw MappingException::unknownIdentifier($meta->class, $name, self::names($meta));
        }

        // A property named for another property's column -- `$title` mapping
        // `name` beside `$name` mapping `title` -- could mean either. Guessed,
        // a sort field would read the other one.
        if (\count($found) > 1) {
            throw new MappingException(
                "'{$name}' names more than one column of {$meta->class} (" . implode(', ', $found) . '); '
                . 'name the column itself.',
            );
        }

        return $found[0];
    }

    private static function text(string|\BackedEnum $name): string
    {
        if ($name instanceof \BackedEnum) {
            if (!\is_string($name->value)) {
                throw new MappingException($name::class . ' is backed by integers, and a column is named by a string.');
            }

            return $name->value;
        }

        return $name;
    }
}
