<?php

use App\Models\Merchant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\PaymentGatewayInterface;
use Mockery;

/*
 * Failure-path coverage.
 *
 * The existing suite (OrderControllerTest / PaymentSplitServiceTest /
 * PaymentGatewaySignatureTest) is mostly REAL — it asserts money-split math,
 * stock decrements, idempotent pay(), and signature verification. But it only
 * exercises the HAPPY PATH plus a few obvious guards. These tests target the
 * failure branches and cross-role boundaries that were completely untested and
 * would let a regression slip through green CI:
 *
 *   1. Gateway throws during pay()  -> must 502 + order stays pending (no free order)
 *   2. Cancel of a DELIVERED order  -> must 422 (status guard, hit via HTTP)
 *   3. Cancel by a DIFFERENT customer -> must 403 (cancel authorization)
 *   4. Stock exhaustion at exactly 0 -> 2nd order 422, stock never negative
 *   5. Merged-parent cancel cascades to every child sub-order
 *
 * Every test below would FAIL (exposing a bug) if the corresponding guard or
 * failure-handler were removed or weakened. They run against RefreshDatabase +
 * in-memory SQLite and a Mockery gateway double, so no real PSP is touched.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    app()->bind(PaymentGatewayInterface::class, \App\Services\PaymentGatewayService::class);

    $this->customer = User::create([
        'name'     => 'Nguyen Van A',
        'phone'    => '0901112223',
        'password' => bcrypt('secret123'),
        'role'     => 'customer',
    ]);

    $this->merchant = Merchant::create([
        'name'             => 'Cua Hang Test',
        'contact_name'     => 'Chu Shop',
        'phone'            => '0901112224',
        'address'          => '123 Pho Hue, Hanoi',
        'status'           => 'approved',
        'is_open'          => true,
        'commission_rate'  => 0.0,
        'delivery_fee'     => 15000.0,
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

function custToken(User $user): string
{
    return $user->createToken('ci', ['customer'])->plainTextToken;
}

describe('payment failure path', function () {
    it('does not mark the order paid when the gateway throws (502, order stays pending)', function () {
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

        // Simulate a PSP timeout / transport error.
        $gateway = Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('createPayment')->andThrow(new \RuntimeException('PSP timeout'));
        app()->instance(PaymentGatewayInterface::class, $gateway);

        $res = $this->withToken(custToken($this->customer))
            ->postJson('/api/v1/orders/' . $order->order_no . '/pay', ['method' => 'momo']);
        $res->assertStatus(502);

        // CRITICAL: a gateway error must never flip the order to 'paid'
        // (that would hand the customer a free order) and must not double-charge.
        expect($order->fresh()->status)->toBe('pending_payment');

        $payment = Payment::where('order_id', $order->id)->first();
        expect($payment)->not->toBeNull();
        expect($payment->status)->toBe('failed');          // not success
        expect(Payment::where('order_id', $order->id)->count())->toBe(1); // no 2nd row
    });
});

describe('cancellation guards (real HTTP)', function () {
    it('refuses to cancel a delivered order (422 status guard)', function () {
        $order = Order::create([
            'order_no'        => 'GN' . uniqid(),
            'user_id'         => $this->customer->id,
            'merchant_id'     => $this->merchant->id,
            'status'          => 'delivered',
            'amount'          => 50000.0,
            'product_amount'  => 50000.0,
            'address'         => 'a',
            'contact_name'    => 'c',
            'contact_phone'   => '0901234567',
        ]);

        $res = $this->withToken(custToken($this->customer))
            ->postJson('/api/v1/orders/' . $order->order_no . '/cancel');
        $res->assertStatus(422);

        // A completed, settled delivery must not be silently reverted
        // (which would also trigger a refund on an already-settled order).
        expect($order->fresh()->status)->toBe('delivered');
    });

    it('forbids a different customer from cancelling the order (403)', function () {
        $order = Order::create([
            'order_no'        => 'GN' . uniqid(),
            'user_id'         => $this->customer->id,
            'merchant_id'     => $this->merchant->id,
            'status'          => 'pending_payment',
            'amount'          => 50000.0,
            'product_amount'  => 50000.0,
            'address'         => 'a',
            'contact_name'    => 'c',
            'contact_phone'   => '0901234567',
        ]);

        $intruder = User::create([
            'name'     => 'Intruder',
            'phone'    => '0901112299',
            'password' => bcrypt('secret123'),
            'role'     => 'customer',
        ]);

        $res = $this->withToken(custToken($intruder))
            ->postJson('/api/v1/orders/' . $order->order_no . '/cancel');
        $res->assertStatus(403);

        // Order untouched.
        expect($order->fresh()->status)->toBe('pending_payment');
    });
});

describe('stock atomicity boundary', function () {
    it('refuses a second order that would oversell the last unit (stock never negative)', function () {
        $this->product->update(['stock' => 3]);

        $first = [
            'merchant_id'   => $this->merchant->id,
            'address'       => '456 Hang Bong, Hanoi',
            'contact_name'  => 'Nguyen Van A',
            'contact_phone' => '0901234567',
            'pay_method'    => 'cod',
            'items'         => [['product_id' => $this->product->id, 'qty' => 3]],
        ];
        $r1 = $this->withToken(custToken($this->customer))->postJson('/api/v1/orders', $first);
        $r1->assertStatus(201);
        expect((int) Product::find($this->product->id)->stock)->toBe(0);

        $second = [
            'merchant_id'   => $this->merchant->id,
            'address'       => '789 Tran Quang Khai, Hanoi',
            'contact_name'  => 'Nguyen Van A',
            'contact_phone' => '0901234567',
            'pay_method'    => 'cod',
            'items'         => [['product_id' => $this->product->id, 'qty' => 1]],
        ];
        $r2 = $this->withToken(custToken($this->customer))->postJson('/api/v1/orders', $second);
        $r2->assertStatus(422);

        // CRITICAL invariant: stock never goes negative, even at the boundary.
        expect((int) Product::find($this->product->id)->stock)->toBe(0);
        // No orphan order leaked from the rejected request.
        expect(Order::where('contact_phone', '0901234567')->count())->toBe(1);
    });
});

describe('merged cancel cascade', function () {
    it('cancels every child sub-order when a merged parent is cancelled', function () {
        $parentNo = 'GN' . uniqid();

        $parent = Order::create([
            'order_no'        => $parentNo,
            'user_id'         => $this->customer->id,
            'merchant_id'     => null,
            'type'            => 'merged',
            'status'          => 'pending_payment',
            'amount'          => 80000.0,
            'product_amount'  => 80000.0,
            'address'         => 'a',
            'contact_name'    => 'c',
            'contact_phone'   => '0901234567',
        ]);

        $merchant2 = Merchant::create([
            'name' => 'Cua Hang 2', 'contact_name' => 'C2', 'phone' => '0901112230',
            'address' => 'y', 'status' => 'approved', 'is_open' => true,
            'commission_rate' => 0.0, 'delivery_fee' => 15000.0, 'delivery_subsidy' => true,
        ]);

        $sub1 = Order::create([
            'order_no'        => $parentNo . '-1',
            'user_id'         => $this->customer->id,
            'merchant_id'     => $this->merchant->id,
            'type'            => 'sub',
            'parent_order_no' => $parentNo,
            'status'          => 'pending_payment',
            'amount'          => 50000.0,
            'product_amount'  => 50000.0,
            'address'         => 'a',
            'contact_name'    => 'c',
            'contact_phone'   => '0901234567',
        ]);
        $sub2 = Order::create([
            'order_no'        => $parentNo . '-2',
            'user_id'         => $this->customer->id,
            'merchant_id'     => $merchant2->id,
            'type'            => 'sub',
            'parent_order_no' => $parentNo,
            'status'          => 'pending_payment',
            'amount'          => 30000.0,
            'product_amount'  => 30000.0,
            'address'         => 'a',
            'contact_name'    => 'c',
            'contact_phone'   => '0901234567',
        ]);

        $res = $this->withToken(custToken($this->customer))
            ->postJson('/api/v1/orders/' . $parent->order_no . '/cancel');
        $res->assertStatus(200);

        // The whole merge must be cancelled atomically — leaving a sub-order
        // pending would let a rider pick up an already-cancelled shipment.
        expect($parent->fresh()->status)->toBe('cancelled');
        expect($sub1->fresh()->status)->toBe('cancelled');
        expect($sub2->fresh()->status)->toBe('cancelled');
    });
});
