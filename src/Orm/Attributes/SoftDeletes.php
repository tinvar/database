<?php

declare(strict_types=1);

namespace Tinvar\Orm\Attributes;

use Tinvar\Support\KnownAttributes;

/**
 * Deletes the model's rows softly (ADR 0191): Orm::delete() stamps the
 * row's `deleted_at` with the moment, rather than removing it, and every
 * read passes a trashed row by -- find(), all(), a query, a #[HasMany] --
 * unless it asks for it (Query::withTrashed(), Query::onlyTrashed(),
 * Orm::find(..., Trashed::With)). Orm::restore() brings a row back, and
 * Orm::forceDelete() removes it for good.
 *
 *     #[Table('orders'), TenantScoped, SoftDeletes]
 *     final class Order extends Model
 *     {
 *         #[Id] public private(set) string $id;
 *         #[Column] public ?CarbonImmutable $deletedAt = null;
 *     }
 *
 * The model maps the column with a nullable moment -- CarbonImmutable or
 * DateTimeImmutable -- which the ORM alone writes: a save() or a query's
 * update() that sets it is refused, so a row is trashed and restored only
 * where the hooks run and the audit log hears of it. A migration makes
 * the column with `$table->softDeletes()`.
 *
 * A #[BelongsTo] still reads a trashed row: the key names it, and a
 * child's page can say its parent was deleted. Its tenant is its own, as
 * before, under the predicate and Postgres's policy alike.
 *
 * Inherited, as #[TenantScoped] is: written on an abstract base or a
 * trait, it holds every model below it.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class SoftDeletes
{
    public function __construct(public string $column = 'deleted_at') {}

    /**
     * The #[SoftDeletes] that holds $class, the nearest wherever in its
     * hierarchy it is written; null for a class with none.
     *
     * @template T of object
     * @param class-string<T>|\ReflectionClass<T> $class
     */
    public static function on(string|\ReflectionClass $class): ?self
    {
        $reflection = $class instanceof \ReflectionClass ? $class : new \ReflectionClass($class);
        foreach (TenantScoped::hierarchy($reflection) as $level) {
            KnownAttributes::checkOn($level, $level->getName());
            foreach ($level->getAttributes(self::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                return $attribute->newInstance();
            }
        }

        return null;
    }
}
