<?php

use App\Models\Coupon;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\PaymentGatewayInterface;
use Mockery;

/*
 * Phase-2 blind-spot coverage — the failure paths the first testing audit
 * flagged as UNTESTED. Every test below would FAIL (exposing a bug) if the
 * corresponding guard or contract were weakened:
 *
 *   1. Refund FAILURE contract (fail-open): when the gateway refund returns a
 *      non-"refunded" status (or throws), the order is still cancelled but the
 *      payment must NOT be marked 'refunded' and the failure must be recorded
 *      in refund_error. A regression that silently swallows the error (e.g.
 *      marking the payment refunded on failure) would wrongly tell ops the
 *      money was returned.
 *   2. Merchant coupon usage cap: a code with usage_limit=1 can be redeemed
 *      exactly once; the second attempt must 422 and used_count must not climb.
 *   3. Input validation on create-order: empty items, qty>9999, >50 line items
 *      must all 422 (the FormRequest rules must actually be wired).
 *   4. pay() on an ALREADY-PAID order must not double-charge: it returns 422
 *      and creates no second Payment row.
 *
 * NOTE on concurrency (the 5th blind spot from the audit): true oversell / one
 * order-two-riders races require parallel requests across processes, which a
 * single-process Pest run cannot reproduce without faking. Writing a
 * sequential "concurrent" test would give false confidence, so it is
 * deliberately NOT faked here — it belongs in a dedicated load/race harness
 * (e.g. Artillery firing N parallel POSTs, or a DB-level FOR UPDATE probe).
 *
 * Runs against RefreshDatabase + in-memory SQLite with a Mockery gateway.
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

function custTok(User $user): string
{
    return $user->createToken('ci', ['customer'])->plainTextToken;
}

describe('cancel refund-failure contract (fail-open)', function () {
    it('cancels but flags refund_error and never marks payment refunded when the gateway returns failed', function () {
        $order = Order::create([
            'order_no'        => 'GN' . uniqid(),
            'user_id'         => $this->customer->id,
            'merchant_id'     => $this->merchant->id,
            'status'          => 'paid',
            'pay_method'      => 'momo',
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

        // Gateway says the refund did NOT go through.
        $gateway = Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('refund')->once()->andReturn(['status' => 'failed', 'message' => 'gateway declined']);
        app()->instance(PaymentGatewayInterface::class, $gateway);

        $res = $this->withToken(custTok($this->customer))
            ->postJson('/api/v1/orders/' . $order->order_no . '/cancel');
        $res->assertStatus(200);

        // CONTRACT: order cancelled for the user, but the money was NOT returned
        // so the payment stays 'success' (not 'refunded') and the failure is
        // recorded for manual reconciliation. A regression marking it 'refunded'
        // on failure would falsely report the money was returned.
        expect($order->fresh()->status)->toBe('cancelled');
        expect($order->fresh()->refund_error)->not->toBeNull();
        expect($order->fresh()->refunded_at)->toBeNull();
        expect($payment->fresh()->status)->toBe('success'); // NOT 'refunded'
    });

    it('cancels and flags refund_error when the gateway refund throws', function () {
        $order = Order::create([
            'order_no'        => 'GN' . uniqid(),
            'user_id'         => $this->customer->id,
            'merchant_id'     => $this->merchant->id,
            'status'          => 'paid',
            'pay_method'      => 'momo',
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

        $gateway = Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('refund')->once()->andThrow(new \RuntimeException('PSP unreachable'));
        app()->instance(PaymentGatewayInterface::class, $gateway);

        $res = $this->withToken(custTok($this->customer))
            ->postJson('/api/v1/orders/' . $order->order_no . '/cancel');
        $res->assertStatus(200);

        expect($order->fresh()->status)->toBe('cancelled');
        expect($order->fresh()->refund_error)->not->toBeNull();
        expect($payment->fresh()->status)->toBe('success');
    });
});

describe('merchant coupon usage cap', function () {
    it('redeems a single-use coupon once, then rejects the second attempt (422, used_count frozen)', function () {
        $coupon = Coupon::create([
            'merchant_id' => $this->merchant->id,
            'code'        => 'LIMIT1',
            'name'        => 'Single use',
            'type'        => 'cash',
            'value'       => 10000.0,
            'funded_by'   => 'merchant',
            'min_order'   => 0.0,
            'status'      => 'active',
            'used_count'  => 0,
            'usage_limit' => 1,
        ]);

        $base = [
            'merchant_id'   => $this->merchant->id,
            'address'       => '456 Hang Bong, Hanoi',
            'contact_name'  => 'Nguyen Van A',
            'contact_phone' => '0901234567',
            'pay_method'    => 'cod',
            'coupon_code'   => 'LIMIT1',
            'items'         => [['product_id' => $this->product->id, 'qty' => 1]],
        ];

        $r1 = $this->withToken(custTok($this->customer))->postJson('/api/v1/orders', $base);
        $r1->assertStatus(201);
        expect(Coupon::find($coupon->id)->used_count)->toBe(1);

        // Second redemption of the same single-use code must be rejected and
        // must NOT bump used_count past the limit (anti 套券 / 超发).
        $r2 = $this->withToken(custTok($this->customer))->postJson('/api/v1/orders', $base);
        $r2->assertStatus(422);
        expect(Coupon::find($coupon->id)->used_count)->toBe(1);

        // No orphan order from the rejected second attempt.
        expect(Order::where('user_id', $this->customer->id)->count())->toBe(1);
    });
});

describe('create-order input validation (422 on bad payloads)', function () {
    it('rejects an empty items array', function () {
        $payload = [
            'merchant_id'   => $this->merchant->id,
            'address'       => '456 Hang Bong, Hanoi',
            'contact_name'  => 'Nguyen Van A',
            'contact_phone' => '0901234567',
            'items'         => [],
        ];
        $res = $this->withToken(custTok($this->customer))->postJson('/api/v1/orders', $payload);
        $res->assertStatus(422);
    });

    it('rejects a line quantity above the 9999 cap', function () {
        $payload = [
            'merchant_id'   => $this->merchant->id,
            'address'       => '456 Hang Bong, Hanoi',
            'contact_name'  => 'Nguyen Van A',
            'contact_phone' => '0901234567',
            'items'         => [['product_id' => $this->product->id, 'qty' => 10000]],
        ];
        $res = $this->withToken(custTok($this->customer))->postJson('/api/v1/orders', $payload);
        $res->assertStatus(422);
    });

    it('rejects more than 50 line items', function () {
        $items = [];
        for ($i = 0; $i < 51; $i++) {
            $items[] = ['product_id' => $this->product->id, 'qty' => 1];
        }
        $payload = [
            'merchant_id'   => $this->merchant->id,
            'address'       => '456 Hang Bong, Hanoi',
            'contact_name'  => 'Nguyen Van A',
            'contact_phone' => '0901234567',
            'items'         => $items,
        ];
        $res = $this->withToken(custTok($this->customer))->postJson('/api/v1/orders', $payload);
        $res->assertStatus(422);
    });
});

describe('pay() on an already-paid order does not double-charge', function () {
    it('returns 422 and creates no second Payment when the order is already paid', function () {
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

        // First COD pay → order paid, one Payment row marked success.
        $r1 = $this->withToken(custTok($this->customer))
            ->postJson('/api/v1/orders/' . $order->order_no . '/pay', ['method' => 'cod']);
        $r1->assertStatus(200);
        expect($order->fresh()->status)->toBe('paid');
        expect(Payment::where('order_id', $order->id)->count())->toBe(1);

        // Second pay on the now-paid order must be rejected (status guard) and
        // must NOT create a second Payment row (the double-charge bug).
        $r2 = $this->withToken(custTok($this->customer))
            ->postJson('/api/v1/orders/' . $order->order_no . '/pay', ['method' => 'cod']);
        $r2->assertStatus(422);
        expect(Payment::where('order_id', $order->id)->count())->toBe(1);
    });
});
