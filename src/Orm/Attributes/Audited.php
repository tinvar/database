<?php

declare(strict_types=1);

namespace Tinvar\Orm\Attributes;

use Tinvar\Support\KnownAttributes;

/**
 * Records every change to the model's rows in the application's audit log
 * (ADR 0153): a row created, with what it was made with; updated, with each
 * field it changed from what to what; deleted, with what it held.
 *
 *     #[Table('orders'), Audited(except: ['notes'])]
 *     final class Order extends Model { ... }
 *
 * The record is written on the connection the change is, inside its
 * transaction -- the ORM opens one around the save or delete of an audited
 * model, a savepoint inside one open already -- so a change that rolls back
 * leaves no entry, and an entry that cannot be written takes the change
 * back with it. An Orm with nothing to record to (Orm::recordChangesWith())
 * refuses to write the model at all, rather than write it unrecorded.
 *
 * `except:` names fields never recorded at all, by property, #[BelongsTo]
 * or column: not their values, nor that they changed. A secret is recorded
 * as having changed and never what to: a property marked #[Sensitive], and
 * one whose name says it is a secret (SECRET_NAMES -- a password, a token,
 * a hash, an API key) without being marked. The key, the tenant column and
 * the createdAt and updatedAt the ORM stamps are no field of an entry: the
 * entry names its row and carries its own moment.
 *
 * A write through a query is recorded too, row by row: insert() each row it
 * wrote; update(), increment() and decrement() each row they changed, read
 * before and after under the lock the write holds; delete() each row it
 * deleted, read before. upsert() is refused on an audited model, since it
 * cannot say which rows it inserted and which it changed without reading
 * them as save() would. Raw SQL through connection() is never recorded.
 *
 * Inherited, as #[TenantScoped] is: written on an abstract base or a trait,
 * it audits every model below it, and the nearest declaration's `except:`
 * is the one that holds.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Audited
{
    /**
     * The names that mark a secret when nothing else does: a property or a
     * column whose name holds one of these, in any case, is recorded as
     * changed and never with its value.
     */
    public const string SECRET_NAMES = '/(password|passwd|passphrase|secret|token|api_?key|private_?key|recovery|otp|hash|salt|credential|card_?number|cvv|ssn)/i';

    /** @param list<string> $except fields never recorded: properties, #[BelongsTo]s or columns */
    public function __construct(public array $except = []) {}

    /**
     * The #[Audited] that holds $class, the nearest wherever in its
     * hierarchy it is written; null for a class with none. A Tinvar
     * attribute up the hierarchy naming no class is refused, as on the
     * class itself (KnownAttributes).
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

    /** Whether a property or column name reads as a secret's (SECRET_NAMES). */
    public static function namesSecret(string $name): bool
    {
        return preg_match(self::SECRET_NAMES, $name) === 1;
    }
}
