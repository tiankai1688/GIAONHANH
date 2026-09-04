<?php

use App\Console\Commands\ReconcileOrders;
use App\Http\Controllers\Api\PaymentController;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\MerchantSettlementService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()->bind(\App\Services\PaymentGatewayInterface::class, \App\Services\PaymentGatewayService::class);
});

function srUser(array $attrs = []): User
{
    return User::create(array_merge([
        'name'     => 'Sr User',
        'phone'    => '090' . random_int(1000000, 9999999),
        'password' => bcrypt('secret123'),
        'role'     => 'customer',
    ], $attrs));
}

function srMerchant(array $attrs = []): Merchant
{
    return Merchant::create(array_merge([
        'name'             => 'Cua Hang SR',
        'contact_name'     => 'Chu SR',
        'phone'            => '090' . random_int(1000000, 9999999),
        'address'          => '123 Pho Hue',
        'status'           => 'approved',
        'is_open'          => true,
        'commission_rate'  => 0.0,
        'delivery_fee'     => 15000.0,
        'delivery_subsidy' => true,
    ], $attrs));
}

/*
 * Regression for red-team FATAL A: a cross-store merged order must flip ALL its
 * sub-orders to `paid` when the gateway IPN pays the parent, otherwise
 * MerchantController::acceptOrder's `status==='paid'` guard rejects every
 * sub-order and the feature is 100% broken.
 */
it('cascades paid state to merged sub-orders (red-team fatal A)', function () {
    $parent = Order::create([
        'order_no'    => 'GN' . uniqid(),
        'user_id'     => srUser()->id,
        'merchant_id' => null,
        'type'        => 'merged',
        'status'      => 'pending_payment',
        'amount'      => 120000.0,
        'product_amount' => 120000.0,
    ]);
    $m1 = srMerchant();
    $m2 = srMerchant();
    Order::create([
        'order_no'        => $parent->order_no . '-1',
        'user_id'         => $parent->user_id,
        'merchant_id'     => $m1->id,
        'type'            => 'sub',
        'parent_order_no' => $parent->order_no,
        'status'          => 'pending_payment',
        'amount'          => 50000.0,
        'product_amount'  => 50000.0,
    ]);
    Order::create([
        'order_no'        => $parent->order_no . '-2',
        'user_id'         => $parent->user_id,
        'merchant_id'     => $m2->id,
        'type'            => 'sub',
        'parent_order_no' => $parent->order_no,
        'status'          => 'pending_payment',
        'amount'          => 70000.0,
        'product_amount'  => 70000.0,
    ]);

    // Invoke the actual private cascade used by every IPN handler.
    $ref = new ReflectionMethod(PaymentController::class, 'markOrderPaid');
    $ref->setAccessible(true);
    $ref->invoke(new PaymentController(), $parent);

    expect($parent->fresh()->status)->toBe('paid');
    expect(Order::where('parent_order_no', $parent->order_no)->where('status', 'pending_payment')->count())->toBe(0);
    expect(Order::where('parent_order_no', $parent->order_no)->where('status', 'paid')->count())->toBe(2);
});

/*
 * Regression for red-team FATAL B: single-store orders (type=single, the
 * platform's most common order type) MUST enter T+1 settlement. The old filter
 * `where('type','sub')` silently excluded them so merchants never got paid.
 */
it('includes single-store orders in T+1 settlement (red-team fatal B)', function () {
    $merchant = srMerchant();
    Order::create([
        'order_no'         => 'GN' . uniqid(),
        'user_id'          => srUser()->id,
        'merchant_id'      => $merchant->id,
        'type'             => 'single',
        'status'           => 'delivered',
        'amount'           => 80000.0,
        'product_amount'   => 80000.0,
        'merchant_settlement' => 80000.0,
        'delivered_at'     => Carbon::yesterday(),
    ]);

    $report = MerchantSettlementService::forMerchant($merchant, Carbon::yesterday()->toDateString());
    expect($report['order_count'])->toBe(1);
    expect((float) $report['payable'])->toBe(80000.0);
});

/*
 * Regression for red-team FATAL C: orders.psp_fee / psp_fee_bearer are now
 * fillable, so PaymentController::pay()'s update() actually persists them
 * (previously silently dropped by Eloquent → unit economics stayed blind).
 */
it('persists psp_fee on the order after the fillable fix (red-team fatal C)', function () {
    $order = Order::create([
        'order_no'        => 'GN' . uniqid(),
        'user_id'         => srUser()->id,
        'merchant_id'     => srMerchant()->id,
        'status'          => 'pending_payment',
        'amount'          => 100000.0,
        'product_amount'  => 100000.0,
        'psp_fee'         => 2500.0,
        'psp_fee_bearer'  => 'platform',
    ]);

    expect($order->fresh()->psp_fee)->toBe(2500.0);
    expect($order->fresh()->psp_fee_bearer)->toBe('platform');
});

/*
 * Regression for red-team S5 / PDPD: account deletion must anonymize the
 * user's historical order PII (name / phone / address / GPS / note), not just
 * the account row — otherwise the customer's home address survives forever.
 */
it('anonymizes historical order PII on account deletion (red-team S5)', function () {
    $user = srUser();
    $order = Order::create([
        'order_no'       => 'GN' . uniqid(),
        'user_id'        => $user->id,
        'merchant_id'    => srMerchant()->id,
        'status'         => 'delivered',
        'amount'         => 60000.0,
        'product_amount' => 60000.0,
        'contact_name'   => 'Nguyen Thi B',
        'contact_phone'  => '0911223344',
        'address'        => '456 Nguyen Trai, HCMC',
        'lat'            => 10.77,
        'lng'            => 106.68,
        'note'           => 'goi truoc 5p',
    ]);

    $this->withToken($user->createToken('ci', ['customer'])->plainTextToken)
        ->deleteJson('/api/v1/account')
        ->assertStatus(200);

    $fresh = Order::find($order->id);
    expect($fresh->contact_name)->toBeNull();
    expect($fresh->contact_phone)->toBeNull();
    expect($fresh->address)->toBeNull();
    expect($fresh->lat)->toBeNull();
    expect($fresh->lng)->toBeNull();
    expect($fresh->note)->toBeNull();
    // Commercial record retained for audit.
    expect((float) $fresh->amount)->toBe(60000.0);
});

/*
 * Regression for red-team S3: the reconciliation command must cancel stale
 * pending_payment orders AND restore their stock atomically, and (N3) must NOT
 * flip a pending payment to failed when its order is already paid (race guard).
 */
it('reconciles stale orders and restores stock without harming paid orders (red-team S3 / N3)', function () {
    $merchant = srMerchant();
    $product  = Product::create([
        'merchant_id' => $merchant->id,
        'name_vi'     => 'Banh Mi',
        'name_zh'     => '面包',
        'price'       => 30000.0,
        'stock'       => 50,
        'status'      => 'on',
    ]);

    // Stale pending_payment order past TTL (stock must be restored).
    $stale = Order::create([
        'order_no'        => 'GN' . uniqid(),
        'user_id'         => srUser()->id,
        'merchant_id'     => $merchant->id,
        'status'          => 'pending_payment',
        'amount'          => 30000.0,
        'product_amount'  => 30000.0,
    ]);
    OrderItem::create([
        'order_id'   => $stale->id,
        'product_id' => $product->id,
        'name'       => 'Banh Mi',
        'price'      => 30000.0,
        'qty'        => 2,
        'subtotal'   => 60000.0,
    ]);
    DB::table('orders')->where('id', $stale->id)->update(['created_at' => now()->subHours(2)]);

    // Paid order whose payment row is still 'pending' (in-flight race) — must
    // NOT be marked failed by reconciliation.
    $paid = Order::create([
        'order_no'        => 'GN' . uniqid(),
        'user_id'         => srUser()->id,
        'merchant_id'     => $merchant->id,
        'status'          => 'paid',
        'amount'          => 30000.0,
        'product_amount'  => 30000.0,
    ]);
    DB::table('orders')->where('id', $paid->id)->update(['created_at' => now()->subHours(2)]);
    \App\Models\Payment::create([
        'order_id' => $paid->id,
        'method'   => 'momo',
        'amount'   => 30000.0,
        'status'   => 'pending',
    ]);
    DB::table('payments')->where('order_id', $paid->id)->update(['created_at' => now()->subHours(2)]);

    // ReconcileOrders now requires the PaymentGatewayInterface (resolved from
    // the container; the beforeEach binds the real service, whose sandbox mode
    // returns null from queryStatus → conservative expire, matching this test).
    app(ReconcileOrders::class)->handle();

    expect($stale->fresh()->status)->toBe('cancelled');
    expect(Product::find($product->id)->stock)->toBe(52); // 50 + 2 restored
    expect(\App\Models\Payment::where('order_id', $paid->id)->first()->status)->toBe('pending'); // N3 guard
});
