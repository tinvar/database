<?php

declare(strict_types=1);

namespace Tinvar\Tests\Orm\PHPStanFixture;

use Tinvar\Orm\Aggregate;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
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

    // Groups, aggregates, collections and writes (ADR 0064).
    $pallets = Pallet::query($orm);
    $_ = $pallets->where('crates.label', 'x');
    $_ = $pallets->whereIn('crates.lable', ['x']); // refused
    $_ = $pallets->orderBy('crates.label'); // refused
    $_ = $pallets->withCount('crates');
    $_ = $pallets->withCount('crate'); // refused
    $_ = $pallets->withSum('crates', 'weightKg');
    $_ = $pallets->withSum('crates', 'weight'); // refused
    $_ = $pallets->withMax(relation: 'crates', column: 'wieghtKg'); // refused
    $_ = $pallets->orderByRelation('crates', Aggregate::Max, 'weightKg', 'desc');
    $_ = $pallets->orderByRelation('crates', Aggregate::Max, 'weight_kgs'); // refused
    $_ = $query->withCount('pallet'); // refused
    $_ = $query->groupBy('label', 'pallet.code');
    $_ = $query->groupBy('label', 'pallet.cod'); // refused
    $_ = $query->having(Aggregate::Count, null, '>', 1);
    $_ = $query->having(Aggregate::Sum, 'weightKg', '>', 1);
    $_ = $query->having(Aggregate::Sum, 'wieghtKg', '>', 1); // refused
    $_ = $query->orderByAggregate(Aggregate::Sum, 'weight_kg');
    $_ = $query->orderByAggregate(Aggregate::Sum, 'weight'); // refused
    $_ = $query->groupBy('label')->report(\stdClass::class, ['label' => 'label', 'n' => Aggregate::Count, 'kg' => [Aggregate::Sum, 'weightKg']]);
    $_ = $query->report(\stdClass::class, ['kg' => [Aggregate::Sum, 'wieghtKg']]); // refused
    $_ = $query->report(\stdClass::class, ['label' => 'lable']); // refused
    $query->increment('weightKg');
    $query->decrement('weightKg', 2, ['label' => 'x']);
    $query->increment('wieghtKg'); // refused
    $query->increment('pallet.code'); // refused
    $query->update(['label' => 'x', 'weightKg' => 3]);
    $query->update(['lable' => 'x']); // refused
    $query->insert([['label' => 'x', 'weightKg' => 3], ['label' => 'y', 'weight_kg' => 4]]);
    $query->insert([['label' => 'x', 'wieghtKg' => 3]]); // refused
    $query->insert([['pallet.code' => 'x']]); // refused
    $query->upsert([['label' => 'x', 'weightKg' => 3]], 'label', update: ['weightKg']);
    $query->upsert([['label' => 'x', 'weightKg' => 3]], ['label']);
    $query->upsert([['lable' => 'x', 'weightKg' => 3]], 'label'); // refused
    $query->upsert([['label' => 'x', 'weightKg' => 3]], 'weightKg'); // refused
    $query->upsert([['label' => 'x', 'weightKg' => 3]], uniqueBy: ['lable']); // refused
    $query->upsert([['label' => 'x', 'weightKg' => 3]], 'label', ['wieghtKg']); // refused
    $query->upsert([['label' => 'x']], $fromRuntime);

    // Known only at runtime: the builder's to check when it runs.
    $_ = $query->where($fromRuntime, 'x');

    // A query over no one model PHPStan can name is not read.
    $any = static fn (Query $q): Query => $q->where('anything', 1);
}

/*
 * Raw SQL through a Connection: flagged where it names a tenant table --
 * Crate's, which is #[TenantScoped], or crate_events, listed as one.
 */
function raw(Orm $orm, PdoConnection $pdo, string $fromRuntime): void
{
    $_ = $orm->connection()->select('SELECT id FROM crates WHERE label = ?', ['x']); // refused
    $_ = $orm->connection()->select('SELECT id FROM pallets');
    $_ = $orm->connection()->execute('UPDATE "public"."CRATES" SET label = ?', ['x']); // refused
    $_ = $pdo->execute('DELETE FROM crate_events'); // refused
    $_ = $orm->statements()->select('SELECT c.id FROM pallets p JOIN crates c ON c.pallet_id = p.id'); // refused
    $_ = $orm->connection()->select("SELECT 'crates' AS word -- crates");
    $_ = $orm->connection()->select('SELECT id FROM ' . ($fromRuntime === 'a' ? 'pallets' : 'crates')); // refused
    $_ = $orm->connection()->select($fromRuntime); // refused
}
