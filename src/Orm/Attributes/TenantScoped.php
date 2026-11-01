<?php

declare(strict_types=1);

namespace Tinvar\Orm\Attributes;

use Tinvar\Orm\MappingException;
use Tinvar\Support\KnownAttributes;

/**
 * Filters every query by the active tenant and stamps it on insert.
 *
 * Inherited: written on an ancestor class, or on a trait the class or an
 * ancestor uses (a trait's own traits included), it scopes the class too.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class TenantScoped
{
    public function __construct(public string $column = 'tenant_id') {}

    /**
     * The #[TenantScoped] that holds $class, wherever in its hierarchy it is
     * written; null for a class with none.
     *
     * PHP does not inherit class attributes: ReflectionClass::getAttributes()
     * sees only what the class itself carries, so a model extending an
     * abstract #[TenantScoped] base, or using a #[TenantScoped] trait, read
     * and wrote every tenant's rows. Every reader -- the ORM's metadata, the
     * build rules -- asks here, so they agree on what is scoped.
     *
     * The declarations must agree: two naming different columns are refused
     * rather than one picked, since either pick scopes by the wrong column
     * somewhere. And a Tinvar attribute naming no class, anywhere up the
     * hierarchy, is refused the way one on the class itself is
     * (KnownAttributes): an old name for this one would be no match.
     *
     * @template T of object
     * @param class-string<T>|\ReflectionClass<T> $class
     * @throws MappingException when two declarations name different columns
     * @throws \LogicException   naming a Tinvar attribute up the hierarchy that is no class
     */
    public static function on(string|\ReflectionClass $class): ?self
    {
        $reflection = $class instanceof \ReflectionClass ? $class : new \ReflectionClass($class);

        $found = null;
        $where = null;
        foreach (self::hierarchy($reflection) as $level) {
            KnownAttributes::checkOn($level, $level->getName());
            foreach ($level->getAttributes(self::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $declared = $attribute->newInstance();
                if ($found === null) {
                    [$found, $where] = [$declared, $level->getName()];
                } elseif ($declared->column !== $found->column) {
                    throw MappingException::conflictingTenantScope(
                        $reflection->getName(),
                        $where ?? '',
                        $found->column,
                        $level->getName(),
                        $declared->column,
                    );
                }
            }
        }

        return $found;
    }

    /**
     * The class, its ancestors, and every trait any of them uses, a trait's
     * own traits included, each once.
     *
     * @template T of object
     * @param \ReflectionClass<T> $class
     * @return list<\ReflectionClass<object>>
     *
     * @internal read by Audited too, so the two attributes agree on what a model's hierarchy is
     */
    public static function hierarchy(\ReflectionClass $class): array
    {
        $out = [];
        $seen = [];
        $pending = [];
        for ($current = $class; $current !== false; $current = $current->getParentClass()) {
            $pending[] = $current;
        }
        while ($pending !== []) {
            $level = array_shift($pending);
            if (isset($seen[$level->getName()])) {
                continue;
            }
            $seen[$level->getName()] = true;
            $out[] = $level;
            foreach ($level->getTraits() as $trait) {
                $pending[] = $trait;
            }
        }

        return $out;
    }
}
