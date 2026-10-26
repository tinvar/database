<?php

declare(strict_types=1);

namespace Tinvar\Tests\Orm\GhostScope;

use Tinvar\Orm\Attributes\BelongsTo;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\HasMany;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\TenantScoped;
use Tinvar\Orm\Collection;
use Tinvar\Orm\DanglingRelation;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;

#[Table('ghost_projects'), TenantScoped]
final class Project extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;

    /** @var Collection<Task> */
    #[HasMany(Task::class)]
    public Collection $tasks;
}

#[Table('ghost_tasks'), TenantScoped]
final class Task extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $title;
    #[BelongsTo] public Project $project;
}

const ACME_PROJECT = '0190d1d8-3d2a-7000-8000-00000000a001';
const GLOBEX_PROJECT = '0190d1d8-3d2a-7000-8000-00000000b001';
const STRAY_TASK = '0190d1d8-3d2a-7000-8000-00000000a101';
const GOOD_TASK = '0190d1d8-3d2a-7000-8000-00000000a102';
const PLANTED_TASK = '0190d1d8-3d2a-7000-8000-00000000b101';

/**
 * Two tenants. One of acme's tasks points at globex's project, and one of
 * globex's at acme's -- the shape a bad import or a bug elsewhere leaves
 * behind, and the one a scoped read must keep refusing.
 */
function ghostOrm(): Orm
{
    $pdo = new \PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE ghost_projects (id TEXT PRIMARY KEY, name TEXT, tenant_id TEXT)');
    $pdo->exec('CREATE TABLE ghost_tasks (id TEXT PRIMARY KEY, title TEXT, project_id TEXT, tenant_id TEXT)');
    $pdo->exec("INSERT INTO ghost_projects VALUES ('" . ACME_PROJECT . "', 'acme project', 'acme'), ('" . GLOBEX_PROJECT . "', 'globex secrets', 'globex')");
    $pdo->exec("INSERT INTO ghost_tasks VALUES ('" . STRAY_TASK . "', 'stray', '" . GLOBEX_PROJECT . "', 'acme'), ('" . GOOD_TASK . "', 'good', '" . ACME_PROJECT . "', 'acme')");
    $pdo->exec("INSERT INTO ghost_tasks VALUES ('" . PLANTED_TASK . "', 'globex secrets', '" . ACME_PROJECT . "', 'globex')");

    return new Orm(new PdoConnection($pdo), new IdentityMap('test'));
}

// Touched first inside acrossTenants(), the ghost read its row unscoped --
// globex's -- and kept it, so scoped code afterwards saw another tenant's data.
it('loads a ghost made under a scope under that scope, even when first touched inside acrossTenants', function () {
    $orm = ghostOrm();

    $orm->actAs('acme', function () use ($orm): void {
        $task = $orm->find(Task::class, STRAY_TASK);

        $inside = $orm->acrossTenants(static function () use ($task): string {
            try {
                return $task->project->name;
            } catch (DanglingRelation) {
                return 'refused';
            }
        });

        expect($inside)->toBe('refused');
        expect(fn () => $task->project->name)->toThrow(DanglingRelation::class);
    });
});

it('still loads a ghost whose row is in its own scope', function () {
    $orm = ghostOrm();

    $orm->actAs('acme', function () use ($orm): void {
        $task = $orm->find(Task::class, GOOD_TASK);

        expect($orm->acrossTenants(static fn (): string => $task->project->name))->toBe('acme project')
            ->and($task->project->name)->toBe('acme project');
    });
});

// A ghost made while unscoped carries no scope: acrossTenants() is the
// explicit choice to read every tenant's rows.
it('reads across tenants for a ghost made inside acrossTenants', function () {
    $orm = ghostOrm();

    $name = $orm->acrossTenants(fn (): string => $orm->find(Task::class, STRAY_TASK)?->project->name ?? '');

    expect($name)->toBe('globex secrets');
});

/** @return list<string> */
function titles(Project $project): array
{
    $titles = array_map(static fn (Task $task): string => $task->title, $project->tasks->all());
    sort($titles);

    return $titles;
}

// A collection is a relation as a ghost is, and belongs to the scope its
// parent was read in. Its loader read under whatever scope was in force when
// it was first iterated, so acme's project, first read through inside
// acrossTenants(), kept globex's task in it after the block.
it('loads a collection read under a scope under that scope, even when first read inside acrossTenants', function () {
    $orm = ghostOrm();

    $orm->actAs('acme', function () use ($orm): void {
        $project = $orm->find(Project::class, ACME_PROJECT);
        \assert($project instanceof Project);

        expect($orm->acrossTenants(static fn (): array => titles($project)))->toBe(['good'])
            ->and(titles($project))->toBe(['good']);
    });
});

// First read as another tenant, it held that tenant's tasks, and none of the
// project's own.
it('loads a collection read under a scope under that scope, even when first read as another tenant', function () {
    $orm = ghostOrm();

    $orm->actAs('acme', function () use ($orm): void {
        $project = $orm->find(Project::class, ACME_PROJECT);
        \assert($project instanceof Project);

        expect($orm->actAs('globex', static fn (): array => titles($project)))->toBe(['good'])
            ->and(titles($project))->toBe(['good']);
    });
});

// A write inside acrossTenants() cannot refill a loaded collection from rows
// it read unscoped, so it unloads it; the loader it leaves reads under the
// parent's scope too, not under whichever reads it next.
it('loads a collection unloaded by a write inside acrossTenants under its parent\'s scope', function () {
    $orm = ghostOrm();

    $orm->actAs('acme', function () use ($orm): void {
        $project = $orm->find(Project::class, ACME_PROJECT);
        $task = $orm->find(Task::class, GOOD_TASK);
        \assert($project instanceof Project && $task instanceof Task);
        expect(titles($project))->toBe(['good']);

        $task->title = 'better';
        $orm->acrossTenants(static fn (): Task => $orm->save($task));

        expect($orm->actAs('globex', static fn (): array => titles($project)))->toBe(['better']);
    });
});

// As with a ghost: a parent read inside acrossTenants() carries no scope.
it('reads across tenants for a collection whose parent was read inside acrossTenants', function () {
    $orm = ghostOrm();

    $titles = $orm->acrossTenants(static function () use ($orm): array {
        $project = $orm->find(Project::class, ACME_PROJECT);
        \assert($project instanceof Project);

        return titles($project);
    });

    expect($titles)->toBe(['globex secrets', 'good']);
});
