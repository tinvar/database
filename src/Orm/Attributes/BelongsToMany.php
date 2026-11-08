<?php

declare(strict_types=1);

namespace Tinvar\Orm\Attributes;

/**
 * A collection of another model's rows, linked to this one through a pivot
 * model: a model of its own, with a #[BelongsTo] to each side (ADR 0192).
 *
 *     #[BelongsToMany(Tag::class, through: ProductTag::class, orderBy: 'name')]
 *     public Collection $tags;
 *
 *     #[Table('product_tags'), TenantScoped, Unique('product', 'tag')]
 *     final class ProductTag extends Model
 *     {
 *         #[Id] public private(set) string $id;
 *         #[BelongsTo] public Product $product;
 *         #[BelongsTo] public Tag $tag;
 *     }
 *
 * Loaded as a #[HasMany] is -- one statement for the whole result set, the
 * pivot joined to the target, each held to its tenant -- and written with
 * Orm::attach(), detach() and sync(), which save and delete pivot models,
 * so a pivot that is #[Audited] records each link made or undone.
 *
 * `from:` and `to:` name the pivot's #[BelongsTo] properties for this model
 * and for the target. Each is found by its type when the pivot has one
 * #[BelongsTo] to that model, and must be named when it has two -- a model
 * linked to itself. `orderBy:` is a column of the target's; without one,
 * the rows come in the pivot's key order -- with UUIDv7 keys, the order
 * they were linked in to the millisecond, links made in the same one in
 * no order of theirs. A relation shown in a fixed order names `orderBy:`.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class BelongsToMany
{
    /**
     * @param class-string $target
     * @param class-string $through the pivot model
     */
    public function __construct(
        public string $target,
        public string $through,
        public ?string $from = null,
        public ?string $to = null,
        public ?string $orderBy = null,
    ) {}
}
