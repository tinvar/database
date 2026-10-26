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
use Tinvar\Tests\Orm\Fixtures\TenantFixture;

require_once __DIR__ . '/Fixtures/tenant.php';

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
    $orm = new Orm($fixture->connection, new IdentityMap('renamed'));

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
    $orm = new Orm($fixture->connection, new IdentityMap('renamed'));

    $saved = $orm->actAs('acme', function () use ($orm, $fixture): string {
        $script = $orm->find(OldScript::class, $fixture->scripts['acme'][0]) ?? throw new \LogicException('no script');
        $script->name = '  padded  ';
        $orm->save($script);

        return (string) $orm->connection()->select('SELECT name FROM scripts WHERE id = ?', [$script->id])[0]['name'];
    });

    expect($saved)->toBe('padded');
});
