<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * Maps a column's stored value to a PHP object and back.
 *
 * "Value-object columns map through a cast interface, so JSON comes back
 * typed" (tinvar-spec.md, "Models"). Two spellings reach it:
 *
 *     #[Column(type: 'jsonb')] public RackLayout $layout;          // RackLayout implements Cast
 *     #[Column(cast: MoneyCast::class)] public Money $price;       // MoneyCast implements Cast<Money>
 *
 * The first is the spec's, for a class the application owns: the type says
 * all there is to say. The second is for a class it does not own, a library's
 * money or an address type, which cannot be made to implement anything. Where
 * a column has both, `cast:` wins. One interface serves both because its
 * methods are static: nothing is constructed to cast, so a cast is stateless
 * by construction -- what the metadata compiler caches lives as long as the
 * worker, across every request it serves -- and a value object's fromColumn()
 * is just a named constructor.
 *
 * A column whose columnType() is 'json' (or 'jsonb') is JSON: the ORM decodes what is
 * stored, with json_decode() into arrays and never unserialize(), before
 * fromColumn() sees it, and encodes what toColumn() returns. Any other type
 * is handed over as the driver read it, and toColumn() returns a scalar.
 *
 * A value that does not decode, or that fromColumn() refuses by throwing,
 * fails the read with a MappingException naming the model and the column;
 * the model is not half built. Change tracking compares the stored form, so
 * an unchanged object writes nothing, and the object must be immutable --
 * every instance property it or a parent declares readonly -- which the model
 * is checked for when it compiles. A column then changes only by assignment,
 * never through an object something else still holds.
 *
 * @template T of object
 */
interface Cast
{
    /**
     * The column's type in the migration builder's terms (Schema\Column::TYPES):
     * 'json' for a structure, or 'string', 'integer', 'decimal' and the rest
     * for a value that is one scalar. make:migration writes it, unless
     * #[Column(type:)] names a storage type of its own.
     */
    public static function columnType(): string;

    /**
     * The PHP value for what the column holds: for a JSON column, what the
     * JSON decodes to (arrays, never objects); for any other, the value as
     * read. Never null -- a NULL column is null without asking. Throws on a
     * value it cannot make an object of.
     *
     * @return T
     */
    public static function fromColumn(mixed $stored): object;

    /**
     * What to store for $value: anything json_encode() takes, for a JSON
     * column; a scalar for any other. Equal values must give equal results,
     * since that is what a save compares.
     *
     * @param T $value
     * @return array<array-key, mixed>|string|int|float|bool
     */
    public static function toColumn(object $value): array|string|int|float|bool;
}
