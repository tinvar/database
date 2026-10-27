<?php

declare(strict_types=1);

/**
 * #[Unique]: the keys a model says no two rows share, compiled with the
 * rest of its metadata -- what an upsert may conflict on, and the unique
 * indexes make:migration writes, the tenant column first on a tenant's
 * table (ADR 0057).
 */

namespace Tinvar\Tests\Orm\UniqueKeys;

use Tinvar\Orm\Attributes\BelongsTo;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\TenantScoped;
use Tinvar\Orm\Attributes\Unique;
use Tinvar\Orm\MappingException;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\Migrations\MigrationGenerator;
use Tinvar\Orm\Migrations\Migrator;
use Tinvar\Orm\Model;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\SqliteDialect;
use Tinvar\Tests\Orm\Fixtures\MigrationFiles;

require_once __DIR__ . '/Fixtures/migrations.php';

#[Table('uq_warehouses'), Unique('code')]
final class Warehouse extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $code;
}

#[Table('uq_bins'), TenantScoped, Unique('warehouse', 'label'), Unique('serial_no')]
final class Bin extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $label;
    #[Column(name: 'serial_no')] public string $serial;

    #[BelongsTo] public Warehouse $warehouse;
}

#[Table('uq_bad'), Unique('nothing')]
final class UnknownKey extends Model
{
    #[Id] public private(set) string $id;
}

#[Table('uq_bad'), TenantScoped, Unique('tenant_id')]
final class TenantKey extends Model
{
    #[Id] public private(set) string $id;
}

#[Table('uq_bad'), Unique('id')]
final class IdKey extends Model
{
    #[Id] public private(set) string $id;
}

it('compiles each key as its columns, by property, relation or column name', function () {
    expect(Metadata::for(Warehouse::class)->uniqueKeys)->toBe([['code']])
        ->and(Metadata::for(Bin::class)->uniqueKeys)->toBe([['warehouse_id', 'label'], ['serial_no']]);
});

it('answers a conflict target only for a declared key, in any order, with the tenant column first', function () {
    $bin = Metadata::for(Bin::class);

    expect($bin->conflictTarget(['label', 'warehouse_id']))->toBe(['tenant_id', 'warehouse_id', 'label'])
        ->and($bin->conflictTarget(['serial_no']))->toBe(['tenant_id', 'serial_no'])
        ->and($bin->conflictTarget(['label']))->toBeNull()
        ->and($bin->conflictTarget(['label', 'warehouse_id', 'serial_no']))->toBeNull()
        ->and(Metadata::for(Warehouse::class)->conflictTarget(['code']))->toBe(['code']);
});

it('refuses a key that names no column, the key, or the tenant column', function (string $class, string $message) {
    expect(fn () => Metadata::for($class))->toThrow(MappingException::class, $message);
})->with([
    'unknown' => [UnknownKey::class, "names 'nothing', which is no column"],
    'tenant' => [TenantKey::class, 'leads every key'],
    'id' => [IdKey::class, 'unique already'],
]);

it('has make:migration write each key as a unique index, the tenant column first, and find nothing once it has run', function () {
    $files = new MigrationFiles();
    $connection = PdoConnection::sqlite($files->database(), create: true);
    $generator = new MigrationGenerator($connection, new SqliteDialect());

    $source = (string) $generator->generate([Warehouse::class, Bin::class], '2026-10-26');
    file_put_contents("{$files->directory}/2026_10_26_000001_create_uq.php", $source);
    new Migrator($connection, $files->directory)->migrate();

    expect($source)->toContain("\$table->unique(['code']);")
        ->and($source)->toContain("\$table->unique(['tenant_id', 'warehouse_id', 'label']);")
        ->and($source)->toContain("\$table->unique(['tenant_id', 'serial_no']);")
        ->and($generator->generate([Warehouse::class, Bin::class], '2026-10-26'))->toBeNull();
});

it('adds a key a table lacks, saying rows that share it refuse the index', function () {
    $files = new MigrationFiles();
    $connection = PdoConnection::sqlite($files->database(), create: true);
    $connection->execute('CREATE TABLE uq_warehouses (id VARCHAR(36) PRIMARY KEY, code VARCHAR(255) NOT NULL)');

    $source = (string) new MigrationGenerator($connection, new SqliteDialect())->generate([Warehouse::class], '2026-10-26');
    // The comment, as one line of prose however it was wrapped.
    $prose = (string) preg_replace('~\s*(//\s*)+~', ' ', $source);

    expect($source)->toContain("\$table->unique(['code']);")
        ->and($prose)->toContain('rows already sharing those values refuse')
        ->and($source)->toContain("\$table->dropIndex('uq_warehouses_code_unique');");
});
