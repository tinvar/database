<?php

declare(strict_types=1);

/**
 * tinvar-spec.md, "Observability (dev toolbar)": "Every lazy relation load
 * with its trigger site" and "`acrossTenants()` calls flagged". The Orm
 * tells the toolbar of each, with where the application made it, when the
 * toolbar asked -- and takes no backtrace when it did not.
 */

namespace Tinvar\Tests\Orm\ToolbarFlags;

use Tinvar\Orm\LazyLoad;
use Tinvar\Orm\LazyLoads;
use Tinvar\Tests\Orm\Fixtures\Shelf;
use Tinvar\Tests\Orm\Fixtures\Shelves;

require_once __DIR__ . '/Fixtures/shelves.php';

it('tells of each lazy load with the line that touched the relation', function () {
    $orm = Shelves::sqlite()->orm();
    $flagged = [];
    $orm->flagLazyLoads(static function (LazyLoad $load) use (&$flagged): void {
        $flagged[] = $load;
    });

    $line = __LINE__ + 1;
    $count = $orm->actAs('acme', static fn (): int => $orm->query(Shelf::class)->where('position', 1)->first()?->items->count() ?? -1);

    expect($count)->toBe(2)
        ->and($flagged)->toHaveCount(1)
        ->and($flagged[0]->relation)->toBe(Shelf::class . '::items')
        ->and($flagged[0]->site)->toBe(__FILE__ . ":{$line}");
});

it('tells the test recording lazy loads too, and takes no site for it alone', function () {
    $orm = Shelves::sqlite()->orm();
    $loads = new LazyLoads();
    $orm->recordLazyLoads($loads);

    $orm->actAs('acme', static fn (): int => $orm->query(Shelf::class)->where('position', 1)->first()?->items->count() ?? -1);

    expect($loads->since())->toHaveCount(1)
        ->and($loads->since()[0]->site)->toBe('');
});

it('tells of each acrossTenants() call, where it was made', function () {
    $orm = Shelves::sqlite()->orm();
    $flagged = [];
    $orm->flagAcrossTenants(static function (string $site) use (&$flagged): void {
        $flagged[] = $site;
    });

    $line = __LINE__ + 1;
    $count = $orm->acrossTenants(static fn (): int => $orm->query(Shelf::class)->count());

    expect($count)->toBeGreaterThan(0)
        ->and($flagged)->toBe([__FILE__ . ":{$line}"]);

    $orm->flagAcrossTenants(null);
    $orm->acrossTenants(static fn (): int => 1);
    expect($flagged)->toHaveCount(1);
});
