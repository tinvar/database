<?php

declare(strict_types=1);

/**
 * The builder's helpers for what a migration says most: a moment
 * (timestamp(), timestamps()), a key to another model's table, read from
 * that model (foreign()), and a column held to a backed enum's values
 * (enum()). Each is only a shorter way to write a column the builder
 * already made, so each is checked against the SQL the long way writes.
 */

namespace Tinvar\Tests\Orm\SchemaHelpers;

use Tinvar\Orm\Attributes\BelongsTo;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table as TableAttribute;
use Tinvar\Orm\Model;
use Tinvar\Orm\OnDelete;
use Tinvar\Orm\Schema\MySqlDialect;
use Tinvar\Orm\Schema\PostgresDialect;
use Tinvar\Orm\Schema\SqliteDialect;
use Tinvar\Orm\Schema\Table;

#[TableAttribute('shop_orders')]
final class PurchaseOrder extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $number;
}

#[TableAttribute('ledger')]
final class LedgerEntry extends Model
{
    #[Id, Column('entry_key')] public private(set) string $key;
}

#[TableAttribute('shop_order_notes')]
final class OrderNote extends Model
{
    #[Id] public private(set) string $id;

    #[BelongsTo(onDelete: OnDelete::Cascade)] public PurchaseOrder $purchaseOrder;

    #[BelongsTo(foreignKey: 'reply_to_id', onDelete: OnDelete::SetNull)] public ?PurchaseOrder $replyTo = null;
}

final class NotAModel {}

enum Status: string
{
    case Placed = 'placed';
    case Shipped = 'shipped';
    case Cancelled = 'cancelled';
}

enum Priority: int
{
    case Low = 1;
    case High = 2;
}

enum Shape
{
    case Round;
}

enum Quoted: string
{
    case Plain = 'plain';
    case Odd = "it's";
}

function created(\Closure $columns): Table
{
    $table = new Table('lines', creating: true);
    $columns($table);

    return $table;
}

describe('timestamp() and timestamps()', function () {
    it('stores a moment as each dialect keeps one: ISO text, timestamptz, DATETIME(6)', function () {
        $table = created(function (Table $table): void {
            $table->timestamp('placed_at');
            $table->timestamp('shipped_at')->nullable();
        });

        expect(new SqliteDialect()->createTable($table)[0])->toContain('"placed_at" TEXT NOT NULL,')
            ->toContain('"shipped_at" TEXT' . "\n")
            ->and(new PostgresDialect()->createTable($table)[0])->toContain('"placed_at" timestamptz NOT NULL,')
            ->toContain('"shipped_at" timestamptz' . "\n")
            ->and(new MySqlDialect()->createTable($table)[0])->toContain('`placed_at` DATETIME(6) NOT NULL,')
            ->toContain('`shipped_at` DATETIME(6)' . "\n");
    });

    it('adds created_at and updated_at, both required, as the ORM stamps both on every insert', function () {
        $table = created(fn (Table $table) => $table->timestamps());

        expect(array_map(static fn ($c): array => [$c->name, $c->type, $c->nullable], $table->columns))->toBe([
            ['created_at', 'timestamp', false],
            ['updated_at', 'timestamp', false],
        ]);
    });

    it('adds them nullable when asked, for a table that already has rows to backfill', function () {
        $table = new Table('lines', creating: false);
        $table->timestamps(nullable: true);

        expect(new SqliteDialect()->alterTable($table))->toBe([
            'ALTER TABLE "lines" ADD COLUMN "created_at" TEXT',
            'ALTER TABLE "lines" ADD COLUMN "updated_at" TEXT',
        ]);
    });
});

describe('foreign()', function () {
    it('names the column, types it and references the table and key, all from the model', function () {
        $long = created(fn (Table $table) => $table->uuid('purchase_order_id')->references('shop_orders'));
        $short = created(fn (Table $table) => $table->foreign(PurchaseOrder::class));

        foreach ([new SqliteDialect(), new PostgresDialect(), new MySqlDialect()] as $dialect) {
            expect($dialect->createTable($short))->toBe($dialect->createTable($long));
        }
        expect($short->columns[0]->name)->toBe('purchase_order_id')
            ->and($short->columns[0]->references)->toBe(['shop_orders', 'id']);
    });

    it('references the key column the model names, under a column of the migration\'s choosing', function () {
        $table = created(fn (Table $table) => $table->foreign(LedgerEntry::class, 'entry'));

        expect($table->columns[0]->name)->toBe('entry')
            ->and($table->columns[0]->type)->toBe('uuid')
            ->and($table->columns[0]->references)->toBe(['ledger', 'entry_key']);
    });

    it('says what a delete does in a word, or by the enum', function (\Closure $declare, string $sql) {
        $table = created($declare);

        expect(new PostgresDialect()->createTable($table)[0])->toContain("REFERENCES \"shop_orders\" (\"id\") ON DELETE {$sql}\n");
    })->with([
        'cascade' => [fn (Table $t) => $t->foreign(PurchaseOrder::class)->cascadeOnDelete(), 'CASCADE'],
        'restrict' => [fn (Table $t) => $t->foreign(PurchaseOrder::class)->restrictOnDelete(), 'RESTRICT'],
        'set null' => [fn (Table $t) => $t->foreign(PurchaseOrder::class)->nullable()->nullOnDelete(), 'SET NULL'],
        'named' => [fn (Table $t) => $t->foreign(PurchaseOrder::class, onDelete: OnDelete::Cascade), 'CASCADE'],
        'named, nullable' => [fn (Table $t) => $t->foreign(PurchaseOrder::class, onDelete: OnDelete::SetNull, nullable: true), 'SET NULL'],
        'the enum to onDelete()' => [fn (Table $t) => $t->uuid('order_id')->references('shop_orders')->onDelete(OnDelete::NoAction), 'NO ACTION'],
        'a word to onDelete(), as before' => [fn (Table $t) => $t->uuid('order_id')->references('shop_orders')->onDelete('cascade'), 'CASCADE'],
    ]);

    it('is required unless asked otherwise', function () {
        $required = created(fn (Table $t) => $t->foreign(PurchaseOrder::class));
        $optional = created(fn (Table $t) => $t->foreign(PurchaseOrder::class, nullable: true));

        expect($required->columns[0]->nullable)->toBeFalse()
            ->and($optional->columns[0]->nullable)->toBeTrue();
    });

    // A key that sets NULL where the column takes none fails the delete it
    // was meant to allow, on every database, at the moment of the delete.
    it('refuses ON DELETE SET NULL on a column that takes no NULL, when the table is compiled', function () {
        $table = created(fn (Table $t) => $t->foreign(PurchaseOrder::class)->nullOnDelete());

        expect(fn () => new SqliteDialect()->createTable($table))
            ->toThrow(\LogicException::class, "Column 'purchase_order_id': ON DELETE SET NULL needs a column that takes NULL; declare it nullable().");
    });

    it('refuses a class that is not a model', function () {
        expect(fn () => created(fn (Table $t) => $t->foreign(NotAModel::class)))
            ->toThrow(\InvalidArgumentException::class, NotAModel::class . ' is not a Model');
    });

    it('takes an action spelled as SQL spells it, and refuses any other', function () {
        expect(OnDelete::from('SET NULL'))->toBe(OnDelete::SetNull)
            ->and(OnDelete::parse('set null'))->toBe(OnDelete::SetNull)
            ->and(OnDelete::parse(' Cascade '))->toBe(OnDelete::Cascade)
            ->and(fn () => OnDelete::parse('explode'))->toThrow(\InvalidArgumentException::class, "'EXPLODE' is not an ON DELETE action.");
    });
});

describe('enum()', function () {
    it('is the string column the long way writes, held to the cases\' values', function () {
        $long = created(fn (Table $t) => $t->string('status')->check("status IN ('placed', 'shipped', 'cancelled')"));
        $short = created(fn (Table $t) => $t->enum('status', Status::class));

        foreach ([new SqliteDialect(), new PostgresDialect(), new MySqlDialect()] as $dialect) {
            expect($dialect->createTable($short))->toBe($dialect->createTable($long));
        }
        expect(new MySqlDialect()->createTable($short)[0])->toContain("`status` VARCHAR(255) NOT NULL CHECK (status IN ('placed', 'shipped', 'cancelled'))");
    });

    it('is an integer column for an int-backed enum', function () {
        $table = created(fn (Table $t) => $t->enum('priority', Priority::class)->default(1));

        expect(new PostgresDialect()->createTable($table)[0])->toContain('"priority" integer NOT NULL DEFAULT 1 CHECK (priority IN (1, 2))');
    });

    it('keeps a check of its own beside the values\' one', function () {
        $table = created(fn (Table $t) => $t->enum('status', Status::class)->nullable()->check("status <> 'cancelled'"));

        expect(new SqliteDialect()->createTable($table)[0])
            ->toContain("\"status\" TEXT CHECK (status IN ('placed', 'shipped', 'cancelled')) CHECK (status <> 'cancelled')");
    });

    it('quotes a value as each dialect quotes a literal, and a column named by a keyword', function () {
        $table = created(fn (Table $t) => $t->enum('order', Quoted::class));

        expect(new SqliteDialect()->createTable($table)[0])->toContain("CHECK (\"order\" IN ('plain', 'it''s'))")
            ->and(new MySqlDialect()->createTable($table)[0])->toContain("CHECK (`order` IN ('plain', 'it''s'))");
    });

    it('adds the column with its check to a table that exists', function () {
        $table = new Table('lines', creating: false);
        $table->enum('status', Status::class)->default('placed');

        expect(new PostgresDialect()->alterTable($table))->toBe([
            "ALTER TABLE \"lines\" ADD COLUMN \"status\" varchar(255) NOT NULL DEFAULT 'placed' CHECK (status IN ('placed', 'shipped', 'cancelled'))",
        ]);
    });

    it('refuses a pure enum, and a class that is no enum', function () {
        expect(fn () => created(fn (Table $t) => $t->enum('shape', Shape::class)))
            ->toThrow(\InvalidArgumentException::class, Shape::class . ' is not a backed enum')
            ->and(fn () => created(fn (Table $t) => $t->enum('shape', NotAModel::class)))
            ->toThrow(\InvalidArgumentException::class, NotAModel::class . ' is not a backed enum');
    });

    it('takes a #[BelongsTo]\'s delete action as the enum, as it does a word', function () {
        $relations = \Tinvar\Orm\Metadata::for(OrderNote::class)->relations;

        expect($relations['purchaseOrder']->onDelete)->toBe('CASCADE')
            ->and($relations['purchaseOrder']->foreignKey)->toBe('purchase_order_id')
            ->and($relations['replyTo']->onDelete)->toBe('SET NULL');
    });

    it('is changed with a migration of its own, not by change()', function () {
        $table = new Table('lines', creating: false);
        $table->enum('status', Status::class)->change();

        expect(fn () => new PostgresDialect()->alterTable($table))->toThrow(\LogicException::class, 'a primary key, unique index, check or foreign key is added or dropped on its own');
    });
});
