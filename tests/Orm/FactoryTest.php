<?php

declare(strict_types=1);

use Shop\Database\Factories\CustomerFactory;
use Shop\Database\Migrations;
use Shop\Database\Factories\OrderFactory;
use Shop\Database\Factories\ProductFactory;
use Shop\Models\Customer;
use Shop\Models\Order;
use Shop\Models\Product;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Support\Uuid;

/** The shop's own schema, as its migrations make it, in an in-memory SQLite database, foreign keys enforced. */
function shopSqlite(): PdoConnection
{
    $connection = PdoConnection::sqlite(':memory:', 'shop-test');
    Migrations::migrate($connection);

    return $connection;
}

function shopOrm(PdoConnection $connection): Orm
{
    return new Orm($connection, new IdentityMap('shop-test'));
}

it('builds a model with its typed fields set', function () {
    $product = ProductFactory::new()->make();

    expect($product)->toBeInstanceOf(Product::class)
        ->and($product->name)->toBe('Test product 1')
        ->and($product->priceCents)->toBe(1001)
        ->and($product->isPersisted())->toBeFalse('make() does not write');
});

it('derives the model it builds from definition()', function () {
    expect(ProductFactory::model())->toBe(Product::class)
        ->and(OrderFactory::model())->toBe(Order::class);
});

it('builds a list when asked for several', function () {
    $products = ProductFactory::new()->count(3)->make();

    expect($products)->toHaveCount(3)
        ->and(array_map(static fn (Product $p): string => $p->sku, $products))
        ->toBe(['PW-9001', 'PW-9002', 'PW-9003']);
});

it('attaches a parent by type, with no property name needed', function () {
    $connection = shopSqlite();
    $orm = shopOrm($connection);
    $customer = CustomerFactory::new()->create($orm);

    $order = OrderFactory::new()->for($customer)->create($orm);

    expect($order->isPersisted())->toBeTrue()
        ->and(Uuid::isValid($order->primaryKey()))->toBeTrue();

    $reread = shopOrm($connection)->find(Order::class, $order->primaryKey());
    expect($reread->customer->primaryKey())->toBe($customer->primaryKey());
});

it('applies a state after the definition', function () {
    $product = ProductFactory::new()
        ->state(static function (Product $product): void {
            $product->stock = 0;
        })
        ->make();

    expect($product->stock)->toBe(0)->and($product->name)->toBe('Test product 1');
});

it('writes a batch in one transaction', function () {
    $connection = shopSqlite();
    $orm = shopOrm($connection);
    $customer = CustomerFactory::new()->create($orm);

    $orders = OrderFactory::new()->count(20)->for($customer)->create($orm);

    expect($orders)->toHaveCount(20)
        ->and(shopOrm($connection)->all(Order::class))->toHaveCount(20);
});

it('leaves nothing behind when a write in the batch fails', function () {
    $connection = shopSqlite();
    $orm = shopOrm($connection);

    // Every product in the batch takes the same SKU, which the schema holds
    // unique, so the second insert collides and the whole batch must roll
    // back.
    $colliding = ProductFactory::new()
        ->count(5)
        ->state(static function (Product $product): void {
            $product->sku = 'PW-SAME';
        });

    expect(fn () => $colliding->create($orm))->toThrow(PDOException::class);
    expect(shopOrm($connection)->all(Product::class))->toHaveCount(0);
});

it('refuses an ambiguous or absent parent relation', function () {
    $connection = shopSqlite();
    $orm = shopOrm($connection);
    $customer = CustomerFactory::new()->create($orm);

    // A product belongs to no customer.
    expect(fn () => ProductFactory::new()->for($customer))
        ->toThrow(LogicException::class, 'no single relation');
});

it('is immutable, so a configured factory can be reused', function () {
    $base = ProductFactory::new();
    $three = $base->count(3);

    expect($base->make())->toBeInstanceOf(Product::class)
        ->and($three->make())->toHaveCount(3);
});

it('builds a customer the policy and the session can use', function () {
    $orm = shopOrm(shopSqlite());

    $customer = CustomerFactory::new()->create($orm);

    expect($customer)->toBeInstanceOf(Customer::class)
        ->and($customer->authIdentifier())->toBe($customer->primaryKey())
        ->and(password_verify('factory-1', $customer->passwordHash))->toBeTrue();
});
