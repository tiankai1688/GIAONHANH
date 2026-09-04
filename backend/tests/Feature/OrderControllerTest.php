<?php

use App\Models\Merchant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\PaymentGatewayInterface;
use App\Services\PaymentGatewayService;
use Mockery;

/*
 * Order core-chain Feature tests (store / storeMerged / cancel).
 *
 * These exercise the real HTTP layer + DB (RefreshDatabase, in-memory SQLite)
 * and prove three things the architecture review called out:
 *   - P0-2  : single-store and merged orders both flow through CreateOrderAction
 *             and the 0%-commission / delivery-subsidy split is applied correctly.
 *   - P2-b  : order authorization is enforced by OrderPolicy (a different
 *             customer is rejected with 403 on show/cancel).
 *   - P1-d  : PaymentGatewayInterface is mockable — the wallet-refund cancel
 *             path binds a Mockery double to the interface instead of the real
 *             gateway, so no real PSP call is made.
 *
 * Run with: php artisan test  (Pest; see phpunit.xml + tests/Pest.php)
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    // Reset the gateway interface to the real implementation before each test
    // so a mock bound in one test cannot leak into another.
    app()->bind(PaymentGatewayInterface::class, PaymentGatewayService::class);

    $this->customer = User::create([
        'name'     => 'Nguyen Van A',
        'phone'    => '0901112223',
        'password' => bcrypt('secret123'),
        'role'     => 'customer',
    ]);

    $this->merchant = Merchant::create([
        'name'           => 'Cua Hang Test',
        'contact_name'   => 'Chu Shop',
        'phone'          => '0901112224',
        'address'        => '123 Pho Hue, Hanoi',
        'status'         => 'approved',   // required by Merchant::approved() scope
        'is_open'        => true,
        'commission_rate' => 0.0,
        'delivery_fee'   => 15000.0,
        'delivery_subsidy' => true,
    ]);

    $this->product = Product::create([
        'merchant_id' => $this->merchant->id,
        'name_vi'     => 'Tra Sua',
        'name_zh'     => '奶茶',
        'price'       => 50000.0,
        'stock'       => 50,
        'status'      => 'on',
    ]);
});

afterEach(function () {
    Mockery::close();
});

function customerToken(User $user): string
{
    return $user->createToken('ci', ['customer'])->plainTextToken;
}

describe('single-store order creation', function () {
    it('creates an order with the 0%-commission / subsidized-delivery split', function () {
        $payload = [
            'merchant_id'   => $this->merchant->id,
            'address'       => '456 Hang Bong, Hanoi',
            'contact_name'  => 'Nguyen Van A',
            'contact_phone' => '0901234567',
            'pay_method'    => 'cod',
            'items'         => [
                ['product_id' => $this->product->id, 'qty' => 2],
            ],
        ];

        $res = $this->withToken(customerToken($this->customer))
            ->postJson('/api/v1/orders', $payload);

        $res->assertStatus(201);

        $order = Order::where('user_id', $this->customer->id)->latest()->first();
        expect($order)->not->toBeNull();
        // 2 x 50,000 = 100,000 product value
        expect((float) $order->product_amount)->toBe(100000.0);
        // Delivery is platform-subsidized -> customer pays 0 delivery
        expect((float) $order->amount)->toBe(100000.0);
        // Merchant keeps full product value (0 commission)
        expect((float) $order->merchant_settlement)->toBe(100000.0);
        // Platform eats the 15,000 delivery fee
        expect((float) $order->platform_subsidy)->toBe(15000.0);
        expect((float) $order->commission)->toBe(0.0);
        expect((float) $order->delivery_fee)->toBe(15000.0);
        // Line item persisted
        expect($order->items()->count())->toBe(1);
    });

    it('rejects an order for a product that does not belong to the merchant (422)', function () {
        $other = Merchant::create([
            'name' => 'Other', 'contact_name' => 'O', 'phone' => '0901112225',
            'address' => 'x', 'status' => 'approved', 'is_open' => true,
        ]);
        $foreignProduct = Product::create([
            'merchant_id' => $other->id, 'name_vi' => 'X', 'name_zh' => 'X',
            'price' => 10000.0, 'stock' => 5, 'status' => 'on',
        ]);

        $payload = [
            'merchant_id'   => $this->merchant->id,
            'address'       => '456 Hang Bong, Hanoi',
            'contact_name'  => 'Nguyen Van A',
            'contact_phone' => '0901234567',
            'items'         => [
                ['product_id' => $foreignProduct->id, 'qty' => 1],
            ],
        ];

        $res = $this->withToken(customerToken($this->customer))
            ->postJson('/api/v1/orders', $payload);

        $res->assertStatus(422);
    });
});

describe('cross-store merged order creation', function () {
    it('creates a parent + one sub-order per merchant with a single delivery fee', function () {
        $merchant2 = Merchant::create([
            'name' => 'Cua Hang 2', 'contact_name' => 'C2', 'phone' => '0901112226',
            'address' => 'y', 'status' => 'approved', 'is_open' => true,
            'commission_rate' => 0.0, 'delivery_fee' => 15000.0, 'delivery_subsidy' => true,
        ]);
        $product2 = Product::create([
            'merchant_id' => $merchant2->id, 'name_vi' => 'Banh Mi', 'name_zh' => '面包',
            'price' => 30000.0, 'stock' => 20, 'status' => 'on',
        ]);

        $payload = [
            'address'       => '789 Tran Quang Khai, Hanoi',
            'contact_name'  => 'Nguyen Van A',
            'contact_phone' => '0901234567',
            'pay_method'    => 'cod',
            'groups'        => [
                [
                    'merchant_id' => $this->merchant->id,
                    'items' => [['product_id' => $this->product->id, 'qty' => 1]],
                ],
                [
                    'merchant_id' => $merchant2->id,
                    'items' => [['product_id' => $product2->id, 'qty' => 1]],
                ],
            ],
        ];

        $res = $this->withToken(customerToken($this->customer))
            ->postJson('/api/v1/orders/merged', $payload);

        $res->assertStatus(201);

        $parent = Order::where('type', 'merged')->latest()->first();
        expect($parent)->not->toBeNull();
        // 50,000 + 30,000 = 80,000 product value
        expect((float) $parent->product_amount)->toBe(80000.0);
        // One flat delivery fee, platform-subsidized -> customer pays 0 delivery
        expect((float) $parent->amount)->toBe(80000.0);
        expect((float) $parent->group_delivery_fee)->toBe(15000.0);
        expect((float) $parent->platform_subsidy)->toBe(15000.0);
        expect((float) $parent->merchant_settlement)->toBe(0.0); // settlement lives on subs

        // Two per-merchant sub-orders, each keeping its own product value
        expect($parent->subOrders()->count())->toBe(2);
        $subMerchant1 = $parent->subOrders()->where('merchant_id', $this->merchant->id)->first();
        $subMerchant2 = $parent->subOrders()->where('merchant_id', $merchant2->id)->first();
        expect((float) $subMerchant1->merchant_settlement)->toBe(50000.0);
        expect((float) $subMerchant2->merchant_settlement)->toBe(30000.0);
    });
});

describe('order authorization (OrderPolicy)', function () {
    it('forbids a different customer from viewing the order (403)', function () {
        $order = Order::create([
            'order_no'      => 'GN' . uniqid(),
            'user_id'       => $this->customer->id,
            'merchant_id'   => $this->merchant->id,
            'status'        => 'pending_payment',
            'address'       => 'a',
            'contact_name'  => 'c',
            'contact_phone' => '0901234567',
        ]);

        $intruder = User::create([
            'name' => 'Intruder', 'phone' => '0901112227',
            'password' => bcrypt('secret123'), 'role' => 'customer',
        ]);

        $res = $this->withToken(customerToken($intruder))
            ->getJson('/api/v1/orders/' . $order->order_no);

        $res->assertStatus(403);
    });

    it('allows the owner to cancel a pending order (200)', function () {
        $order = Order::create([
            'order_no'      => 'GN' . uniqid(),
            'user_id'       => $this->customer->id,
            'merchant_id'   => $this->merchant->id,
            'status'        => 'pending_payment',
            'address'       => 'a',
            'contact_name'  => 'c',
            'contact_phone' => '0901234567',
        ]);

        $res = $this->withToken(customerToken($this->customer))
            ->postJson('/api/v1/orders/' . $order->order_no . '/cancel');

        $res->assertStatus(200);
        expect($order->fresh()->status)->toBe('cancelled');
    });
});

describe('cancel with refund', function () {
    it('refunds a paid wallet order through the payment gateway interface (mockable)', function () {
        $order = Order::create([
            'order_no'        => 'GN' . uniqid(),
            'user_id'         => $this->customer->id,
            'merchant_id'     => $this->merchant->id,
            'status'          => 'paid',
            'amount'          => 100000.0,
            'product_amount'  => 100000.0,
            'address'         => 'a',
            'contact_name'    => 'c',
            'contact_phone'   => '0901234567',
        ]);
        $payment = Payment::create([
            'order_id' => $order->id,
            'method'   => 'momo',
            'amount'   => 100000.0,
            'status'   => 'success',
        ]);

        // P1-d: bind a Mockery double to the INTERFACE (not the concrete class),
        // proving the controllers depend on the contract and can be tested
        // without a real PSP. The double asserts refund() is invoked once.
        $gateway = Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('refund')->once()->andReturn(['status' => 'refunded']);
        app()->instance(PaymentGatewayInterface::class, $gateway);

        $res = $this->withToken(customerToken($this->customer))
            ->postJson('/api/v1/orders/' . $order->order_no . '/cancel');

        $res->assertStatus(200);
        expect($payment->fresh()->status)->toBe('refunded');
        expect($order->fresh()->status)->toBe('cancelled');
        expect($order->fresh()->refunded_at)->not->toBeNull();
    });

    it('skips refund for a COD order (no gateway call needed)', function () {
        $order = Order::create([
            'order_no'        => 'GN' . uniqid(),
            'user_id'         => $this->customer->id,
            'merchant_id'     => $this->merchant->id,
            'status'          => 'pending_payment',
            'pay_method'      => 'cod',
            'amount'          => 50000.0,
            'product_amount'  => 50000.0,
            'address'         => 'a',
            'contact_name'    => 'c',
            'contact_phone'   => '0901234567',
        ]);

        $res = $this->withToken(customerToken($this->customer))
            ->postJson('/api/v1/orders/' . $order->order_no . '/cancel');

        $res->assertStatus(200);
        expect($order->fresh()->status)->toBe('cancelled');
        expect($order->fresh()->refunded_at)->toBeNull();
    });
});

describe('payment idempotency (no double charge)', function () {
    it('reuses a single Payment row across repeated pay() calls', function () {
        $order = Order::create([
            'order_no'        => 'GN' . uniqid(),
            'user_id'         => $this->customer->id,
            'merchant_id'     => $this->merchant->id,
            'status'          => 'pending_payment',
            'pay_method'      => 'momo',
            'amount'          => 100000.0,
            'product_amount'  => 100000.0,
            'address'         => 'a',
            'contact_name'    => 'c',
            'contact_phone'   => '0901234567',
        ]);

        // Mock the gateway so no real PSP call is made; return a pending session.
        $gateway = Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('createPayment')->andReturn([
            'status' => 'pending', 'pay_url' => 'https://pay.example', 'trans_id' => 'T1', 'raw' => [],
        ]);
        app()->instance(PaymentGatewayInterface::class, $gateway);

        $token = customerToken($this->customer);
        $r1 = $this->withToken($token)->postJson('/api/v1/orders/' . $order->order_no . '/pay', ['method' => 'momo']);
        $r1->assertStatus(200);
        $r2 = $this->withToken($token)->postJson('/api/v1/orders/' . $order->order_no . '/pay', ['method' => 'momo']);
        $r2->assertStatus(200);

        // The double-charge bug created two Payment rows (two real charges).
        // After the fix a single Payment row is reserved and reused.
        expect(Payment::where('order_id', $order->id)->count())->toBe(1);
    });
});

describe('stock reservation (no oversell)', function () {
    it('decrements product stock on order creation', function () {
        $payload = [
            'merchant_id'   => $this->merchant->id,
            'address'       => '456 Hang Bong, Hanoi',
            'contact_name'  => 'Nguyen Van A',
            'contact_phone' => '0901234567',
            'pay_method'    => 'cod',
            'items'         => [
                ['product_id' => $this->product->id, 'qty' => 3],
            ],
        ];

        $res = $this->withToken(customerToken($this->customer))
            ->postJson('/api/v1/orders', $payload);
        $res->assertStatus(201);

        expect((int) Product::find($this->product->id)->stock)->toBe(47); // 50 - 3
    });

    it('rejects the order when stock is insufficient (422, stock unchanged)', function () {
        $this->product->update(['stock' => 1]);

        $payload = [
            'merchant_id'   => $this->merchant->id,
            'address'       => '456 Hang Bong, Hanoi',
            'contact_name'  => 'Nguyen Van A',
            'contact_phone' => '0901234567',
            'items'         => [
                ['product_id' => $this->product->id, 'qty' => 5],
            ],
        ];

        $res = $this->withToken(customerToken($this->customer))
            ->postJson('/api/v1/orders', $payload);
        $res->assertStatus(422);

        // No oversell: stock stays at 1 and no order row leaks.
        expect((int) Product::find($this->product->id)->stock)->toBe(1);
        expect(Order::where('user_id', $this->customer->id)->count())->toBe(0);
    });
});

describe('rider claim serialisation', function () {
    it('rejects a second rider claiming an already-claimed order (409)', function () {
        $order = Order::create([
            'order_no'        => 'GN' . uniqid(),
            'user_id'         => $this->customer->id,
            'merchant_id'     => $this->merchant->id,
            'status'          => 'paid',
            'amount'          => 50000.0,
            'product_amount'  => 50000.0,
            'address'         => 'a',
            'contact_name'    => 'c',
            'contact_phone'   => '0901234567',
        ]);

        $riderUser1 = User::create([
            'name' => 'Shipper 1', 'phone' => '0901112231',
            'password' => bcrypt('secret123'), 'role' => 'rider',
        ]);
        $rider1 = $riderUser1->rider()->create([
            'name' => 'S1', 'phone' => '0901112231', 'status' => 'online', 'vehicle' => 'bike',
        ]);
        $riderUser2 = User::create([
            'name' => 'Shipper 2', 'phone' => '0901112232',
            'password' => bcrypt('secret123'), 'role' => 'rider',
        ]);
        $riderUser2->rider()->create([
            'name' => 'S2', 'phone' => '0901112232', 'status' => 'online', 'vehicle' => 'bike',
        ]);

        $t1 = $riderUser1->createToken('ci', ['rider'])->plainTextToken;
        $t2 = $riderUser2->createToken('ci', ['rider'])->plainTextToken;

        $first = $this->withToken($t1)->postJson('/api/v1/rider/orders/' . $order->order_no . '/accept');
        $first->assertStatus(200);

        $second = $this->withToken($t2)->postJson('/api/v1/rider/orders/' . $order->order_no . '/accept');
        $second->assertStatus(409);

        // The order belongs to rider 1, never rider 2.
        expect(Order::find($order->id)->rider_id)->toBe($rider1->id);
    });
});
