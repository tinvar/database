<?php

declare(strict_types=1);

/**
 * detach() and sync() beside another request unlinking the same rows: a
 * link read and then deleted by someone else before this call's DELETE
 * reaches it is one this call did not unlink. It is left out of what the
 * call says it detached -- as restore() leaves out a trashed link another
 * request brought back -- rather than throwing MissingRow (ADR 0208), so
 * a double submit of the same unlink answers as having changed nothing.
 *
 * The other request is a pivot hook: after the first link's DELETE, it
 * removes the second link's row around the ORM, committed with the first.
 */

namespace Tinvar\Tests\Orm\PivotRace;

use Tinvar\Orm\Attributes\AfterDelete;
use Tinvar\Orm\Attributes\BelongsTo;
use Tinvar\Orm\Attributes\BelongsToMany;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\Unique;
use Tinvar\Orm\Collection;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\PivotChange;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\SqliteDialect;
use Tinvar\Orm\Schema\Table as SchemaTable;

#[Table('race_posts')]
final class Post extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;

    /** @var Collection<Label> */
    #[BelongsToMany(Label::class, through: PostLabel::class, orderBy: 'name')]
    public Collection $labels;
}

#[Table('race_labels')]
final class Label extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;
}

#[Table('race_post_labels'), Unique('post', 'label')]
final class PostLabel extends Model
{
    #[Id] public private(set) string $id;
    #[BelongsTo] public Post $post;
    #[BelongsTo] public Label $label;

    /** @var (\Closure(self): void)|null what the other request does once the first link is gone */
    public static ?\Closure $meanwhile = null;

    #[AfterDelete]
    public function gone(): void
    {
        $meanwhile = self::$meanwhile;
        self::$meanwhile = null;
        $meanwhile?->__invoke($this);
    }
}

/** @return array{Orm, PdoConnection, Post, Label, Label, Label} */
function linked(): array
{
    $connection = new PdoConnection(new \PDO('sqlite::memory:'));
    $schema = new Schema($connection, new SqliteDialect());
    $schema->create('race_posts', static function (SchemaTable $table): void {
        $table->uuid('id')->primary();
        $table->string('name');
    });
    $schema->create('race_labels', static function (SchemaTable $table): void {
        $table->uuid('id')->primary();
        $table->string('name');
    });
    $schema->create('race_post_labels', static function (SchemaTable $table): void {
        $table->uuid('id')->primary();
        $table->pivot(Post::class, Label::class);
    });

    $orm = new Orm($connection, new IdentityMap('race'));
    $post = new Post();
    $post->name = 'post';
    $orm->save($post);
    $labels = [];
    foreach (['a', 'b', 'c'] as $name) {
        $label = new Label();
        $label->name = $name;
        $labels[] = $orm->save($label);
    }
    $orm->attach($post, 'labels', $labels);

    return [$orm, $connection, $post, ...$labels];
}

/**
 * Once the call has deleted its first link -- a's or b's, whichever its
 * key sorts first -- the other request deletes the other of the two.
 */
function unlinkMeanwhile(PdoConnection $connection, Label $a, Label $b): void
{
    PostLabel::$meanwhile = static function (PostLabel $first) use ($connection, $a, $b): void {
        $connection->execute('DELETE FROM race_post_labels WHERE id <> ? AND label_id IN (?, ?)', [$first->id, $a->id, $b->id]);
    };
}

afterEach(function () {
    PostLabel::$meanwhile = null;
});

it('leaves a link another request unlinked first out of what detach() detached', function () {
    [$orm, $connection, $post, $a, $b, $c] = linked();
    unlinkMeanwhile($connection, $a, $b);

    $change = $orm->detach($post, 'labels', [$a, $b]);

    expect($change)->toBeInstanceOf(PivotChange::class)
        ->and($change->detached)->toHaveCount(1)
        ->and([$a->id, $b->id])->toContain($change->detached[0])
        ->and(array_column($connection->select('SELECT label_id FROM race_post_labels'), 'label_id'))->toBe([$c->id]);
});

it('leaves a link another request unlinked first out of what sync() detached', function () {
    [$orm, $connection, $post, $a, $b, $c] = linked();
    unlinkMeanwhile($connection, $a, $b);

    $change = $orm->sync($post, 'labels', [$c]);

    expect($change->detached)->toHaveCount(1)
        ->and([$a->id, $b->id])->toContain($change->detached[0])
        ->and($change->attached)->toBe([])
        ->and(array_column($connection->select('SELECT label_id FROM race_post_labels'), 'label_id'))->toBe([$c->id]);
});
