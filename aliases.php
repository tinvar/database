<?php

declare(strict_types=1);

/**
 * The names the ORM's attributes and the tenant tree had before the
 * framework was split into packages, kept for one release (ADR 0055).
 *
 * @deprecated since 0.4: use Tinvar\Orm\Attributes\*. Removed in 0.5.
 *
 * An old name is aliased when something first asks for it, not when this
 * file loads, and is reported once per process as a deprecation. The alias
 * is the class itself, and the ORM reads each attribute as an instance of
 * its class (ReflectionAttribute::IS_INSTANCEOF), so a model still written
 * `#[\Tinvar\Attributes\TenantScoped]` is scoped exactly as one written with
 * the new name. Read by name alone, the old spelling would match nothing,
 * and a tenant-scoped model would quietly read every tenant's rows.
 *
 * It lives beside src/ rather than in it, because it names the old
 * namespaces, which are the framework's, on purpose: the layer rule reads
 * src/ alone.
 */
$renamed = [
    'tinvar\\attributes\\table' => Tinvar\Orm\Attributes\Table::class,
    'tinvar\\attributes\\column' => Tinvar\Orm\Attributes\Column::class,
    'tinvar\\attributes\\id' => Tinvar\Orm\Attributes\Id::class,
    'tinvar\\attributes\\belongsto' => Tinvar\Orm\Attributes\BelongsTo::class,
    'tinvar\\attributes\\hasmany' => Tinvar\Orm\Attributes\HasMany::class,
    'tinvar\\attributes\\beforesave' => Tinvar\Orm\Attributes\BeforeSave::class,
    'tinvar\\attributes\\tenantscoped' => Tinvar\Orm\Attributes\TenantScoped::class,
];

// Recorded whether or not an alias is ever made, so a refusal of an old
// name can say what it became (Tinvar\Support\KnownAttributes).
Tinvar\Support\RenamedClasses::record($renamed);

spl_autoload_register(static function (string $class) use ($renamed): void {
    $current = $renamed[strtolower(ltrim($class, '\\'))] ?? null;

    if ($current !== null) {
        @trigger_error("{$class} is deprecated since tinvar/database 0.4 and is removed in 0.5; use {$current}.", \E_USER_DEPRECATED);
        class_alias($current, $class);
    }
});
