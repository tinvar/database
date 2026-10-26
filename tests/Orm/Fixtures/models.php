<?php

declare(strict_types=1);

namespace Tinvar\Tests\Orm\Fixtures;

use Tinvar\Orm\Attributes\BelongsTo;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\HasMany;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Collection;
use Tinvar\Orm\Model;

trait HasUnits
{
    /** @var Collection<Unit> */
    #[HasMany(Unit::class, orderBy: 'position')]
    public Collection $units;
}

#[Table('sites')]
final class Site extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $code;

    /**
     * A site owns racks. Here so a model that is reachable as a #[BelongsTo]
     * ghost also owns a #[HasMany] -- the shape that exposed resolveHasMany()
     * answering an empty collection.
     *
     * @var Collection<Rack>
     */
    #[HasMany(Rack::class, orderBy: 'name')]
    public Collection $racks;
}

#[Table('racks')]
final class Rack extends Model
{
    use HasUnits;

    #[Id] public private(set) string $id;
    #[Column] public string $name;
    #[Column] public int $heightU;

    #[BelongsTo] public Site $site;

    public string $label {
        get => "{$this->site->code}-{$this->name}";
    }
}

#[Table('units')]
final class Unit extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public int $position;
    #[Column] public string $name;
}
