<?php

declare(strict_types=1);

/**
 * #[Audited] (ADR 0156): every change to a model's rows handed to what
 * records changes, inside the change's own transaction -- through save(),
 * delete() and the query builder's statements alike -- with the fields an
 * entry may name, secrets redacted and `except:` left out; and no write at
 * all with nowhere to record it.
 */

namespace Tinvar\Tests\Orm\Audited;

use Carbon\CarbonImmutable;
use Tinvar\Orm\Attributes\Audited;
use Tinvar\Orm\Attributes\BelongsTo;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Sensitive;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\TenantScoped;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\MappingException;
use Tinvar\Orm\Model;
use Tinvar\Orm\OnDelete;
use Tinvar\Orm\ModelChange;
use Tinvar\Orm\ModelChangeKind;
use Tinvar\Orm\ModelChanges;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Tests\Orm\Fixtures\Tenancy;

require_once __DIR__ . '/Fixtures/tenancy.php';

#[Table('audited_owners')]
final class Owner extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;
}

#[Table('audited_accounts'), Audited(except: ['notes'])]
final class Account extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $email;
    #[Column] public string $passwordHash;
    #[Column, Sensitive] public ?string $taxNumber = null;
    #[Column] public ?string $notes = null;
    #[Column] public int $credits = 0;
    #[Column] public CarbonImmutable $createdAt;
    #[Column] public CarbonImmutable $updatedAt;
    #[BelongsTo] public Owner $owner;
}

/** Deleted, or let go, by the database when its owner is: a change no write of the ORM's makes. */
#[Table('audited_cascaded'), Audited]
final class Cascaded extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $label;
    #[BelongsTo(onDelete: OnDelete::Cascade)] public Owner $owner;
}

#[Table('audited_released'), Audited]
final class Released extends Model
{
    #[Id] public private(set) string $id;
    #[BelongsTo(onDelete: 'set null')] public ?Owner $owner = null;
}

/** Held by its owner: a delete of the owner is refused while it is there, so nothing changes unrecorded. */
#[Table('audited_held'), Audited]
final class Held extends Model
{
    #[Id] public private(set) string $id;
    #[BelongsTo(onDelete: OnDelete::Restrict)] public Owner $owner;
}

#[Table('audited_accounts'), Audited(except: ['nothing'])]
final class Misnamed extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $email;
}

/** A tenant's row, its tenant column stamped by the ORM and mapped by no property. */
#[Table('audited_memos'), TenantScoped, Audited]
final class Memo extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $body;
}

/** Records into a table on the same connection, as an audit log does, and keeps each change. */
final class Recorder implements ModelChanges
{
    /** @var list<ModelChange> */
    public array $changes = [];

    public bool $fail = false;

    public function __construct(private readonly PdoConnection $connection) {}

    public function record(ModelChange $change): void
    {
        if ($this->fail) {
            throw new \RuntimeException('the log is full');
        }
        $this->connection->execute('INSERT INTO entries (kind, id) VALUES (?, ?)', [$change->kind->value, $change->id]);
        $this->changes[] = $change;
    }

    public function entries(): int
    {
        return (int) $this->connection->select('SELECT COUNT(*) AS n FROM entries')[0]['n'];
    }
}

/** @return array{Orm, Recorder, Owner} */
function audited(bool $record = true): array
{
    $connection = new PdoConnection(new \PDO('sqlite::memory:'));
    $connection->execute('CREATE TABLE audited_owners (id TEXT PRIMARY KEY, name TEXT NOT NULL)');
    $connection->execute(
        'CREATE TABLE audited_accounts (id TEXT PRIMARY KEY, email TEXT NOT NULL, password_hash TEXT NOT NULL, tax_number TEXT, '
        . 'notes TEXT, credits INTEGER NOT NULL DEFAULT 0, created_at TEXT, updated_at TEXT, owner_id TEXT)',
    );
    $connection->execute('CREATE TABLE entries (kind TEXT, id TEXT)');
    $orm = new Orm($connection, new IdentityMap('audited'));
    $recorder = new Recorder($connection);
    if ($record) {
        $orm->recordChangesWith($recorder);
    }
    $owner = new Owner();
    $owner->name = 'Ada';
    $orm->save($owner);

    return [$orm, $recorder, $owner];
}

function account(Orm $orm, Owner $owner): Account
{
    $account = new Account();
    $account->email = 'ada@example.test';
    $account->passwordHash = 'hash-one';
    $account->taxNumber = 'GB-1';
    $account->notes = 'likes tea';
    $account->owner = $owner;
    $orm->save($account);

    return $account;
}

it('records a created row with its fields, secrets redacted and except left out', function () {
    [$orm, $recorder, $owner] = audited();
    $account = account($orm, $owner);

    expect($recorder->changes)->toHaveCount(1);
    $change = $recorder->changes[0];
    expect($change->kind)->toBe(ModelChangeKind::Created)
        ->and($change->model)->toBe(Account::class)
        ->and($change->id)->toBe($account->id)
        ->and($change->tenant)->toBeNull()
        ->and($change->changes)->toBe([
            'email' => [null, 'ada@example.test'],
            'passwordHash' => [null, ModelChange::REDACTED],
            'taxNumber' => [null, ModelChange::REDACTED],
            'credits' => [null, 0],
            'owner' => [null, $owner->id],
        ]);
});

it('records an update as each changed field from what to what, and nothing for a save that changed nothing', function () {
    [$orm, $recorder, $owner] = audited();
    $account = account($orm, $owner);

    $account->email = 'ada@example.org';
    $account->notes = 'likes coffee';
    $account->passwordHash = 'hash-two';
    $orm->save($account);

    expect($recorder->changes)->toHaveCount(2)
        ->and($recorder->changes[1]->kind)->toBe(ModelChangeKind::Updated)
        ->and($recorder->changes[1]->changes)->toBe([
            'email' => ['ada@example.test', 'ada@example.org'],
            'passwordHash' => [ModelChange::REDACTED, ModelChange::REDACTED],
        ]);

    // Only a field never recorded changed: the row is written, and nothing said.
    $account->notes = 'likes both';
    $orm->save($account);
    $orm->save($account);
    expect($recorder->changes)->toHaveCount(2);
});

it('records a delete with what the row held', function () {
    [$orm, $recorder, $owner] = audited();
    $account = account($orm, $owner);
    $id = $account->id;

    $orm->delete($account);

    expect($recorder->changes[1]->kind)->toBe(ModelChangeKind::Deleted)
        ->and($recorder->changes[1]->id)->toBe($id)
        ->and($recorder->changes[1]->changes['email'])->toBe(['ada@example.test', null]);
});

it('leaves no entry for a change that rolls back, and no change for an entry that cannot be written', function () {
    [$orm, $recorder, $owner] = audited();

    expect(fn () => $orm->transaction(function () use ($orm, $owner): void {
        account($orm, $owner);
        throw new \RuntimeException('changed my mind');
    }))->toThrow(\RuntimeException::class, 'changed my mind');
    expect($recorder->entries())->toBe(0)
        ->and($orm->query(Account::class)->count())->toBe(0);

    $recorder->fail = true;
    expect(fn () => account($orm, $owner))->toThrow(\RuntimeException::class, 'the log is full');
    expect($orm->query(Account::class)->count())->toBe(0);
});

it('refuses to write an audited model with nowhere to record it, and writes any other', function () {
    [$orm, , $owner] = audited(record: false);

    expect($orm->recordsChanges())->toBeFalse()
        ->and(fn () => account($orm, $owner))->toThrow(\LogicException::class, 'is #[Audited], and this Orm records changes nowhere')
        ->and(fn () => Account::query($orm)->insert([['email' => 'x@example.test', 'passwordHash' => 'h', 'owner' => $owner]]))
        ->toThrow(\LogicException::class, 'records changes nowhere')
        ->and(fn () => Account::query($orm)->where('credits', 0)->update(['credits' => 1]))
        ->toThrow(\LogicException::class, 'records changes nowhere')
        ->and($orm->query(Account::class)->count())->toBe(0)
        ->and($owner->id)->not->toBe('');
});

it('records the query builder\'s writes row by row, and refuses an upsert', function () {
    [$orm, $recorder, $owner] = audited();

    Account::query($orm)->insert([
        ['email' => 'one@example.test', 'passwordHash' => 'h1', 'owner' => $owner],
        ['email' => 'two@example.test', 'passwordHash' => 'h2', 'owner' => $owner],
    ]);
    expect(array_map(static fn (ModelChange $c): string => $c->kind->value, $recorder->changes))->toBe(['created', 'created']);

    // Both rows move, and each is recorded; setting one to what it holds
    // already changes nothing, and nothing is recorded.
    Account::query($orm)->increment('credits', 5);
    expect($recorder->changes)->toHaveCount(4)
        ->and($recorder->changes[2]->changes)->toBe(['credits' => [0, 5]]);
    Account::query($orm)->where('email', 'one@example.test')->update(['credits' => 5]);
    expect($recorder->changes)->toHaveCount(4);

    Account::query($orm)->where('email', 'two@example.test')->delete();
    expect($recorder->changes[4]->kind)->toBe(ModelChangeKind::Deleted)
        ->and($recorder->changes[4]->changes['email'])->toBe(['two@example.test', null]);

    expect(fn () => Account::query($orm)->upsert([['email' => 'one@example.test', 'passwordHash' => 'h']], 'email'))
        ->toThrow(\LogicException::class, 'is #[Audited]');
});

it('refuses an except naming nothing the model maps, when the model compiles', function () {
    expect(fn () => Misnamed::meta())->toThrow(MappingException::class, "names 'nothing', which is no column");
});

it('refuses an audited row the database deletes or lets go with its owner, which no statement of the ORM\'s records', function () {
    expect(fn () => Cascaded::meta())->toThrow(MappingException::class, 'ON DELETE CASCADE')
        ->and(fn () => Released::meta())->toThrow(MappingException::class, 'ON DELETE SET NULL')
        ->and(Held::meta()->audited)->not->toBeNull();
});

// ADR 0156 files each change under the tenant whose row it is, and ADR
// 0106 has a widened write name the tenants of the rows it reaches. A
// parent editing a child's row inside withinSubtree(), or a job inside
// acrossTenants(), changes the child's row: the child's log is where the
// change belongs, whatever tenant was in force.
it('records a widened write to another tenant\'s row under that row\'s tenant', function () {
    $connection = new PdoConnection(new \PDO('sqlite::memory:'));
    $connection->execute('CREATE TABLE audited_memos (id TEXT PRIMARY KEY, body TEXT NOT NULL, tenant_id TEXT NOT NULL)');
    $connection->execute('CREATE TABLE entries (kind TEXT, id TEXT)');
    $orm = new Orm($connection, new IdentityMap('audited'), Tenancy::tree(['child' => 'parent']));
    $recorder = new Recorder($connection);
    $orm->recordChangesWith($recorder);
    $widened = static fn (\Closure $work): mixed => $orm->actAs('parent', static fn (): mixed => $orm->grantSubtree(
        static fn (): mixed => $orm->withinSubtree($work),
    ));

    $id = $orm->actAs('child', static function () use ($orm): string {
        $memo = new Memo();
        $memo->body = 'one';
        $orm->save($memo);

        return $memo->id;
    });
    $widened(static function () use ($orm, $id): void {
        $memo = $orm->find(Memo::class, $id);
        \assert($memo instanceof Memo);
        $memo->body = 'two';
        $orm->save($memo);
    });
    $widened(static fn (): int => Memo::query($orm)->where('body', 'two')->update(['body' => 'three']));
    $orm->actAs('other', static fn (): mixed => $orm->acrossTenants(
        static fn (): int => Memo::query($orm)->where('body', 'three')->update(['body' => 'four-ish']),
    ));
    $widened(static fn (): int => Memo::query($orm)->where('body', 'four-ish')->delete());
    $orm->actAs('child', static function () use ($orm): void {
        $memo = new Memo();
        $memo->body = 'four';
        $orm->save($memo);
    });
    $widened(static function () use ($orm): void {
        $memo = Memo::query($orm)->where('body', 'four')->first();
        \assert($memo instanceof Memo);
        $orm->delete($memo);
    });

    expect(array_map(static fn (ModelChange $c): array => [$c->kind->value, $c->tenant], $recorder->changes))->toBe([
        ['created', 'child'],
        ['updated', 'child'],
        ['updated', 'child'],
        ['updated', 'child'],
        ['deleted', 'child'],
        ['created', 'child'],
        ['deleted', 'child'],
    ]);
});
