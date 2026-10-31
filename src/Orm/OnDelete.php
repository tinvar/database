<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * What the database does to a row when the row its foreign key references
 * is deleted, as SQL spells each action: the value is written into the
 * key's ON DELETE clause as it is.
 *
 *     $table->foreign(Order::class, onDelete: OnDelete::Cascade);
 *     #[BelongsTo(onDelete: OnDelete::Cascade)] public Order $order;
 *
 * RESTRICT and NO ACTION both refuse the delete; they differ only in
 * whether a deferred check could let it through, which no key the builder
 * makes is.
 */
enum OnDelete: string
{
    /** The row goes with the row it references. */
    case Cascade = 'CASCADE';

    /** The key becomes NULL, for a column that takes NULL. */
    case SetNull = 'SET NULL';

    /** The delete is refused while a row references it. */
    case Restrict = 'RESTRICT';

    /** The database's own default: refused, checked at the end of the statement. */
    case NoAction = 'NO ACTION';

    /**
     * The action an enum case or a word names, in any case and spacing:
     * `cascade`, `set null`, `SET  NULL`.
     *
     * @throws \InvalidArgumentException for a word that names none
     */
    public static function parse(self|string $action): self
    {
        if ($action instanceof self) {
            return $action;
        }
        $spelled = strtoupper(trim((string) preg_replace('/\s+/', ' ', $action)));

        return self::tryFrom($spelled) ?? throw new \InvalidArgumentException("'{$spelled}' is not an ON DELETE action.");
    }
}
