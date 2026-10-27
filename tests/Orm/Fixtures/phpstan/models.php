<?php

declare(strict_types=1);

namespace Tinvar\Tests\Orm\PHPStanFixture;

use Tinvar\Orm\Attributes\BelongsTo;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\HasMany;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\TenantScoped;
use Tinvar\Orm\Attributes\Unique;
use Tinvar\Orm\Collection;
use Tinvar\Orm\Model;

/*
 * The models the PHPStan rule's fixture calls are made over: loaded by
 * PHPStan's bootstrap, since the rule reads their compiled metadata.
 */

#[Table('pallets')]
final class Pallet extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $code;

    /** @var Collection<Crate> */
    #[HasMany(Crate::class)]
    public Collection $crates;
}

#[Table('crates'), TenantScoped, Unique('label')]
final class Crate extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $label;
    #[Column] public int $weightKg;

    #[BelongsTo] public Pallet $pallet;
}

enum CrateSort: string
{
    case Label = 'label';
    case Weight = 'weight_kg';
    case Misspelt = 'lable';
}
