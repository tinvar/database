<?php

declare(strict_types=1);

namespace Tinvar\Tests\Orm\PHPStanFixture;

use Tinvar\Orm\Orm;
use Tinvar\Orm\Query;

/*
 * Calls the rule reads. A line ending `// refused` is one it must report,
 * and no other line may be reported.
 */

function calls(Orm $orm, string $fromRuntime): void
{
    $query = Crate::query($orm);

    $_ = $query->where('label', 'x');
    $_ = $query->where('lable', 'x'); // refused
    $_ = $query->where('weightKg', '>', 3);
    $_ = $query->where('weight_kg', '>', 3);
    $_ = $query->where('pallet', 'x');
    $_ = $query->orderBy('pallet.code');
    $_ = $query->orderBy('pallet.cod'); // refused
    $_ = $query->orderBy('label DESC'); // refused
    $_ = $query->orderBy(CrateSort::Weight);
    $_ = $query->orderBy(CrateSort::Misspelt); // refused
    $_ = $query->with('pallet');
    $_ = $query->with('pallet', 'palette'); // refused
    $_ = $query->whereHas('pallet');
    $_ = $query->whereHas('pallets'); // refused
    $_ = $query->whereRaw('weight_kg * ? > ?', [2, 10]);
    $_ = $query->whereRaw('wieght_kg > ?', [1]); // refused
    $_ = $query->whereRaw("label = 'x'"); // refused
    $_ = $query->whereRaw('weight_kg > ?', [1, 2]); // refused
    $_ = $orm->query(Crate::class)->whereIn('weight_kg', [1]);
    $_ = $orm->query(Crate::class)->whereNotIn('weigth_kg', [1]); // refused
    $_ = $query->pluck('label');
    $_ = $query->sum('secret'); // refused

    // Known only at runtime: the builder's to check when it runs.
    $_ = $query->where($fromRuntime, 'x');

    // A query over no one model PHPStan can name is not read.
    $any = static fn (Query $q): Query => $q->where('anything', 1);
}
