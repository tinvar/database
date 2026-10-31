<?php

declare(strict_types=1);

/**
 * #[TenantScoped] written once, on a base class or a trait, and inherited.
 *
 * PHP does not inherit class attributes: ReflectionClass::getAttributes()
 * sees only what the class itself carries. A model extending an abstract
 * #[TenantScoped] base, or using a #[TenantScoped] trait, compiled as not
 * tenant-scoped and read, updated and inserted across every tenant's rows.
 */

namespace Tinvar\Tests\Orm\InheritedTenantScope;

use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\TenantScoped;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\MappingException;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\Model;
use Tinvar\Orm\NoTenantContext;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\RowLevelSecurity;
use Tinvar\Support\Uuid;
use Tinvar\Tests\Orm\Fixtures\Tenancy;

require_once __DIR__ . '/Fixtures/tenancy.php';

#[TenantScoped]
abstract class TenantModel extends Model {}

abstract class MiddleModel extends TenantModel {}

#[Table('notes')]
final class InheritedNote extends MiddleModel
{
    #[Id] public private(set) string $id;
    #[Column] public string $body;
}

#[TenantScoped]
trait BelongsToTenant {}

trait Tenanted
{
    use BelongsToTenant;
}

#[Table('notes')]
final class TraitNote extends Model
{
    use BelongsToTenant;

    #[Id] public private(set) string $id;
    #[Column] public string $body;
}

// A trait used by a trait, used by a parent.
abstract class TraitedBase extends Model
{
    use Tenanted;
}

#[Table('notes')]
final class DeepTraitNote extends TraitedBase
{
    #[Id] public private(set) string $id;
    #[Column] public string $body;
}

#[TenantScoped('org_id')]
trait BelongsToOrg {}

#[Table('notes'), TenantScoped('org_id')]
final class ConflictingNote extends TenantModel
{
    #[Id] public private(set) string $id;
    #[Column] public string $body;
}

#[Table('notes')]
final class TwoTraitNote extends Model
{
    use BelongsToTenant;
    use BelongsToOrg;

    #[Id] public private(set) string $id;
    #[Column] public string $body;
}

#[Table('notes'), TenantScoped]
final class RestatedNote extends TenantModel
{
    use BelongsToTenant;

    #[Id] public private(set) string $id;
    #[Column] public string $body;
}

/** @return array{\PDO, Orm} */
function notes(): array
{
    $pdo = new \PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE notes (id TEXT PRIMARY KEY, body TEXT, tenant_id TEXT)');
    foreach (['acme', 'globex'] as $tenant) {
        $pdo->prepare('INSERT INTO notes VALUES (?, ?, ?)')->execute([Uuid::v7(), "{$tenant} secret", $tenant]);
    }

    return [$pdo, new Orm(new PdoConnection($pdo), new IdentityMap('inherited-tenant'), tenants: Tenancy::flat())];
}

dataset('inheriting models', [
    'an abstract parent' => [InheritedNote::class],
    'a trait' => [TraitNote::class],
    'a trait of a trait of a parent' => [DeepTraitNote::class],
]);

it('compiles the tenant column', function (string $model) {
    expect(Metadata::for($model)->tenantColumn)->toBe('tenant_id');
})->with('inheriting models');

it('reads only the active tenant\'s rows', function (string $model) {
    [, $orm] = notes();

    $seen = $orm->actAs('acme', fn (): array => [
        ...array_map(static fn (object $n): string => $n->body, $orm->all($model)),
        ...array_map(static fn (object $n): string => $n->body, $orm->query($model)->fetch()),
    ]);

    expect($seen)->toBe(['acme secret', 'acme secret']);
})->with('inheriting models');

it('fails closed with no tenant', function (string $model) {
    [, $orm] = notes();

    expect(fn () => $orm->all($model))->toThrow(NoTenantContext::class)
        ->and(fn () => $orm->query($model)->where('body', '!=', '')->update(['body' => 'x']))->toThrow(NoTenantContext::class);
})->with('inheriting models');

it('updates only the active tenant\'s rows', function (string $model) {
    [$pdo, $orm] = notes();

    $updated = $orm->actAs('acme', fn (): int => $orm->query($model)->where('body', '!=', 'zzz')->update(['body' => 'changed']));

    expect($updated)->toBe(1)
        ->and($pdo->query("SELECT body FROM notes WHERE tenant_id = 'globex'")->fetchColumn())->toBe('globex secret');
})->with('inheriting models');

it('stamps the tenant on insert', function (string $model) {
    [$pdo, $orm] = notes();

    $orm->actAs('acme', function () use ($orm, $model): void {
        $note = new $model();
        $note->body = 'new';
        $orm->save($note);
    });

    expect($pdo->query("SELECT tenant_id FROM notes WHERE body = 'new'")->fetchColumn())->toBe('acme');
})->with('inheriting models');

it('writes a row-level security policy for it', function (string $model) {
    expect(RowLevelSecurity::forModel($model))->toBe(RowLevelSecurity::policy('notes', 'tenant_id', 'text'));
})->with('inheriting models');

it('refuses a subclass naming another tenant column than its parent', function () {
    expect(fn () => Metadata::for(ConflictingNote::class))
        ->toThrow(MappingException::class, 'ConflictingNote');
});

it('refuses two traits naming different tenant columns', function () {
    expect(fn () => Metadata::for(TwoTraitNote::class))
        ->toThrow(MappingException::class, 'TwoTraitNote');
});

it('accepts the same tenant column stated again', function () {
    expect(Metadata::for(RestatedNote::class)->tenantColumn)->toBe('tenant_id');
});

#[\Tinvar\Orm\Attributes\TenantScopd]
abstract class MisspeltBase extends Model {}

#[Table('notes')]
final class MisspeltNote extends MisspeltBase
{
    #[Id] public private(set) string $id;
    #[Column] public string $body;
}

// Misspelt on the base, the attribute would be no match and the model read
// every tenant's rows: refused as it is on the model itself.
it('refuses a Tinvar attribute naming no class on an ancestor', function () {
    expect(fn () => Metadata::for(MisspeltNote::class))
        ->toThrow(\LogicException::class, 'MisspeltBase carries #[\Tinvar\Orm\Attributes\TenantScopd]');
});
