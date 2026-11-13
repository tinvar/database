<?php

declare(strict_types=1);

use Tinvar\Orm\Attributes\BelongsTo;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\HasMany;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\TenantScoped;
use Tinvar\Orm\Collection;
use Tinvar\Orm\MappingException;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\Model;

#[Table('misrelated')]
final class MisrelatedModel extends Model
{
    #[Id] public private(set) string $id;

    /** @var Collection<stdClass> */
    #[HasMany(stdClass::class)]
    public Collection $things;
}

// The attribute takes any class name; a relation to something that is not
// a model failed only when first read. It is refused when the model is
// compiled.
it('refuses a relation whose target is not a model', function () {
    expect(fn () => Metadata::for(MisrelatedModel::class))
        ->toThrow(MappingException::class, 'MisrelatedModel::$things relates to stdClass, which is not a Model.');
});

#[Table('misnamed" OR 1=1 --')]
final class MisnamedTableModel extends Model
{
    #[Id] public private(set) string $id;
}

#[Table('misnamed')]
final class MisnamedColumnModel extends Model
{
    #[Id] public private(set) string $id;
    #[Column('name"')] public string $name;
}

#[Table('misnamed'), TenantScoped('tenant id')]
final class MisnamedTenantModel extends Model
{
    #[Id] public private(set) string $id;
}

#[Table('misnamed')]
final class MisnamedKeyModel extends Model
{
    #[Id] public private(set) string $id;
    #[BelongsTo(foreignKey: 'owner_id; DROP TABLE x')] public MisnamedTableModel $owner;
}

// Every name the ORM writes into SQL from an attribute is a plain
// identifier, checked when the model compiles rather than trusted at each
// place one is quoted (ADR 0208).
it('refuses a table, a column, a tenant column or a foreign key that is no plain identifier', function () {
    expect(fn () => Metadata::for(MisnamedTableModel::class))->toThrow(MappingException::class, 'not a well-formed')
        ->and(fn () => Metadata::for(MisnamedColumnModel::class))->toThrow(MappingException::class, 'not a well-formed')
        ->and(fn () => Metadata::for(MisnamedTenantModel::class))->toThrow(MappingException::class, 'not a well-formed')
        ->and(fn () => Metadata::for(MisnamedKeyModel::class))->toThrow(MappingException::class, 'not a well-formed');
});
