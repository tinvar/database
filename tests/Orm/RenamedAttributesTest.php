<?php

declare(strict_types=1);

/**
 * The ORM's attributes by the names they had before the split (ADR 0055):
 * `Tinvar\Attributes\Table` and the rest, now `Tinvar\Orm\Attributes\*`,
 * kept as aliases for one release.
 *
 * A model still written with the old names has to compile to exactly what
 * the new names give it. The one that matters is #[TenantScoped]: read by
 * the name it was written with, an aliased attribute matches nothing, and a
 * tenant-scoped model would read every tenant's rows instead of failing
 * closed.
 */

namespace Tinvar\Tests\Orm\Renamed;

use Tinvar\Orm\Collection;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\Model;
use Tinvar\Orm\NoTenantContext;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Tests\Orm\Fixtures\TenantFixture;

require_once __DIR__ . '/Fixtures/tenant.php';
require_once __DIR__ . '/Fixtures/tenancy.php';

#[\Tinvar\Attributes\Table('scripts'), \Tinvar\Attributes\TenantScoped]
final class OldScript extends Model
{
    #[\Tinvar\Attributes\Id] public private(set) string $id;
    #[\Tinvar\Attributes\Column] public string $name;

    /** @var Collection<OldScriptRun> */
    #[\Tinvar\Attributes\HasMany(OldScriptRun::class, foreignKey: 'script_id')]
    public Collection $runs;

    #[\Tinvar\Attributes\BeforeSave]
    private function normalize(): void
    {
        $this->name = trim($this->name);
    }
}

#[\Tinvar\Attributes\Table('script_runs'), \Tinvar\Attributes\TenantScoped]
final class OldScriptRun extends Model
{
    #[\Tinvar\Attributes\Id] public private(set) string $id;
    #[\Tinvar\Attributes\Column] public string $status;

    #[\Tinvar\Attributes\BelongsTo(OldScript::class, foreignKey: 'script_id')]
    public OldScript $script;
}

it('reports an old name as deprecated, naming the new one', function () {
    $reported = [];
    set_error_handler(static function (int $level, string $message) use (&$reported): bool {
        $reported[] = [$level, $message];

        return true;
    });

    try {
        // In another case than it was declared in: a class name is not
        // case-sensitive, and neither is the alias.
        class_exists('tinvar\attributes\HASMANY');
    } finally {
        restore_error_handler();
    }

    expect($reported)->toHaveCount(1)
        ->and($reported[0][0])->toBe(\E_USER_DEPRECATED)
        ->and($reported[0][1])->toContain('removed in 0.5')
        ->and($reported[0][1])->toContain('Tinvar\Orm\Attributes\HasMany');
})->skip(fn (): bool => class_exists('Tinvar\Attributes\HasMany', false), 'the alias was made earlier in this process');

it('aliases each old name to the class that replaced it', function () {
    set_error_handler(static fn (int $level): bool => $level === \E_USER_DEPRECATED);

    try {
        foreach (['Table', 'Column', 'Id', 'BelongsTo', 'HasMany', 'BeforeSave', 'TenantScoped'] as $name) {
            $old = new \ReflectionClass("Tinvar\\Attributes\\{$name}");

            expect($old->getName())->toBe("Tinvar\\Orm\\Attributes\\{$name}");
        }
    } finally {
        restore_error_handler();
    }
});

it('compiles a model written with the old names to what the new names give it', function () {
    $old = Metadata::for(OldScript::class);
    $new = Metadata::for(Script::class);

    expect($old->table)->toBe($new->table)
        ->and($old->tenantColumn)->toBe('tenant_id')
        ->and($old->tenantColumn)->toBe($new->tenantColumn)
        ->and($old->idColumn)->toBe($new->idColumn)
        ->and($old->columns)->toBe($new->columns)
        ->and(array_keys($old->relations))->toBe(array_keys($new->relations));
});

it('holds a model written with the old #[TenantScoped] to the tenant in force, and to nothing without one', function () {
    $fixture = new TenantFixture();
    $orm = new Orm($fixture->connection, new IdentityMap('renamed'), tenants: Tenancy::flat());

    expect(fn () => $orm->all(OldScript::class))->toThrow(NoTenantContext::class);

    $acme = $orm->actAs('acme', fn (): array => array_map(
        static fn (OldScript $s): string => $s->name,
        $orm->all(OldScript::class),
    ));
    $foreign = $orm->actAs('acme', fn (): ?OldScript => $orm->find(OldScript::class, $fixture->scripts['globex'][0]));

    expect($acme)->toBe(['acme-script-1', 'acme-script-2'])
        ->and($foreign)->toBeNull();
});

it('runs an old-named #[BeforeSave] hook', function () {
    $fixture = new TenantFixture();
    $orm = new Orm($fixture->connection, new IdentityMap('renamed'), tenants: Tenancy::flat());

    $saved = $orm->actAs('acme', function () use ($orm, $fixture): string {
        $script = $orm->find(OldScript::class, $fixture->scripts['acme'][0]) ?? throw new \LogicException('no script');
        $script->name = '  padded  ';
        $orm->save($script);

        return (string) $orm->connection()->select('SELECT name FROM scripts WHERE id = ?', [$script->id])[0]['name'];
    });

    expect($saved)->toBe('padded');
});

/**
 * Runs $code in a PHP of its own, autoloaded as this suite is but with no
 * alias ever made -- every package's alias autoloader taken off -- as an
 * application will be once the aliases are gone in 0.5. Answers what it
 * printed.
 */
function withoutAliases(string $code): string
{
    $autoload = var_export(\dirname((string) new \ReflectionClass(\Composer\Autoload\ClassLoader::class)->getFileName(), 2) . '/autoload.php', true);
    $script = <<<PHP
        <?php
        require {$autoload};
        foreach (spl_autoload_functions() as \$loader) {
            if (\$loader instanceof \\Closure && str_ends_with((string) new \\ReflectionFunction(\$loader)->getFileName(), '/aliases.php')) {
                spl_autoload_unregister(\$loader);
            }
        }
        {$code}
        PHP;

    $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    \assert(\is_resource($process));
    fwrite($pipes[0], $script);
    fclose($pipes[0]);
    $printed = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    proc_close($process);

    return trim($printed);
}

// Once an old name is no alias of anything, PHP matches nothing by it, and
// a model written #[\Tinvar\Attributes\TenantScoped] compiled as not
// tenant-scoped: it read every tenant's rows. It is refused instead, saying
// what the name became.
it('refuses a model written with an old name that is no alias of anything', function () {
    $printed = withoutAliases(<<<'PHP'
        #[\Tinvar\Orm\Attributes\Table('scripts'), \Tinvar\Attributes\TenantScoped]
        final class Unaliased extends \Tinvar\Orm\Model
        {
            #[\Tinvar\Orm\Attributes\Id] public private(set) string $id;
        }

        try {
            $column = \Tinvar\Orm\Metadata::for(Unaliased::class)->tenantColumn;
            echo 'compiled, tenant column: ', var_export($column, true);
        } catch (\Throwable $e) {
            echo get_class($e), ': ', $e->getMessage();
        }
        PHP);

    expect($printed)->toBe(
        'LogicException: Unaliased carries #[\Tinvar\Attributes\TenantScoped], which is no class now: it was renamed '
        . 'Tinvar\Orm\Attributes\TenantScoped. Write the new name; under the old one the attribute would be ignored (ADR 0055).',
    );
});
