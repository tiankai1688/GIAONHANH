<?php

use App\Console\Commands\ReconcileOrders;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\PaymentGatewayInterface;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Fake gateway that returns a scripted status from queryStatus. All other
 * interface methods are no-ops — only queryStatus is exercised by reconcile.
 */
class FakeReconcileGateway implements PaymentGatewayInterface
{
    public ?string $status = null;

    public function queryStatus(string $orderNo, string $gateway): ?string { return $this->status; }
    public function createMoMo(\App\Models\Order $o, string $i, string $r): array { return []; }
    public function createZaloPay(\App\Models\Order $o, string $i, string $r): array { return []; }
    public function verifyMoMoIpn(array $d): bool { return false; }
    public function createPayment(\App\Models\Order $o, string $m, string $i, string $r): array { return []; }
    public function createViaAggregator(\App\Models\Order $o, string $m, string $a, string $i, string $r): array { return []; }
    public function verifyZaloPayCallback(string $d, string $mac): ?array { return null; }
    public function verifyAggregatorIpn(string $n, string $d, string $mac): ?array { return null; }
    public function refund(\App\Models\Order $o, \App\Models\Payment $p): array { return ['status' => 'skipped']; }
}

function rgUser(): User
{
    return User::create([
        'name' => 'RG User',
        'phone' => '090' . random_int(1000000, 9999999),
        'password' => bcrypt('secret123'),
        'role' => 'customer',
    ]);
}

function rgMerchant(): Merchant
{
    return Merchant::create([
        'name' => 'Cua Hang RG',
        'contact_name' => 'Chu RG',
        'phone' => '090' . random_int(1000000, 9999999),
        'address' => '123 Pho Hue',
        'status' => 'approved',
        'is_open' => true,
        'commission_rate' => 0.0,
        'delivery_fee' => 15000.0,
        'delivery_subsidy' => true,
    ]);
}

/*
 * Reconcile against a REAL PSP that reports the order as PAID (IPN was lost):
 * the stuck pending_payment order must be fulfilled (and merged sub-orders
 * cascaded to paid), and its payment row flipped to success.
 */
it('recovers a genuinely-paid order via PSP queryStatus', function () {
    $fake = new FakeReconcileGateway();
    $fake->status = 'paid';
    app()->instance(PaymentGatewayInterface::class, $fake);

    $parent = Order::create([
        'order_no' => 'GN' . uniqid(),
        'user_id' => rgUser()->id,
        'merchant_id' => null,
        'type' => 'merged',
        'status' => 'pending_payment',
        'amount' => 120000.0,
        'product_amount' => 120000.0,
    ]);
    DB::table('orders')->where('id', $parent->id)->update(['created_at' => now()->subHours(2)]); // make it stale past TTL
    Order::create([
        'order_no' => $parent->order_no . '-1',
        'user_id' => $parent->user_id,
        'merchant_id' => rgMerchant()->id,
        'type' => 'sub',
        'parent_order_no' => $parent->order_no,
        'status' => 'pending_payment',
        'amount' => 50000.0,
        'product_amount' => 50000.0,
    ]);
    Order::create([
        'order_no' => $parent->order_no . '-2',
        'user_id' => $parent->user_id,
        'merchant_id' => rgMerchant()->id,
        'type' => 'sub',
        'parent_order_no' => $parent->order_no,
        'status' => 'pending_payment',
        'amount' => 70000.0,
        'product_amount' => 70000.0,
    ]);
    Payment::create([
        'order_id' => $parent->id,
        'method' => 'momo',
        'gateway' => 'momo',
        'amount' => 120000.0,
        'status' => 'pending',
    ]);

    app(ReconcileOrders::class)->handle();

    expect($parent->fresh()->status)->toBe('paid');
    expect(Order::where('parent_order_no', $parent->order_no)->where('status', 'paid')->count())->toBe(2);
    expect(Payment::where('order_id', $parent->id)->first()->status)->toBe('success');
});

/*
 * Fail-closed: when the gateway is unreachable / sandbox / unconfigured the
 * queryStatus contract returns null. Reconcile MUST still expire the order and
 * restore its stock (conservative), and MUST NOT assume the order is paid.
 */
it('expires (and restores stock) when PSP queryStatus returns null (fail-closed)', function () {
    $fake = new FakeReconcileGateway();
    $fake->status = null; // gateway unknown → conservative expire
    app()->instance(PaymentGatewayInterface::class, $fake);

    $merchant = rgMerchant();
    $product = Product::create([
        'merchant_id' => $merchant->id,
        'name_vi' => 'Banh Mi',
        'name_zh' => '面包',
        'price' => 30000.0,
        'stock' => 50,
        'status' => 'on',
    ]);
    $order = Order::create([
        'order_no' => 'GN' . uniqid(),
        'user_id' => rgUser()->id,
        'merchant_id' => $merchant->id,
        'status' => 'pending_payment',
        'amount' => 30000.0,
        'product_amount' => 30000.0,
    ]);
    DB::table('orders')->where('id', $order->id)->update(['created_at' => now()->subHours(2)]); // make it stale past TTL
    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'name' => 'Banh Mi',
        'price' => 30000.0,
        'qty' => 2,
        'subtotal' => 60000.0,
    ]);
    DB::table('orders')->where('id', $order->id)->update(['created_at' => now()->subHours(2)]);
    Payment::create([
        'order_id' => $order->id,
        'method' => 'momo',
        'gateway' => 'momo',
        'amount' => 30000.0,
        'status' => 'pending',
    ]);
    DB::table('payments')->where('order_id', $order->id)->update(['created_at' => now()->subHours(2)]);

    app(ReconcileOrders::class)->handle();

    // Order expired, never marked paid; stock freed; payment failed.
    expect($order->fresh()->status)->toBe('cancelled');
    expect(Product::find($product->id)->stock)->toBe(52); // 50 + 2 restored
    expect(Payment::where('order_id', $order->id)->first()->status)->toBe('failed');
});

/*
 * Gateway confirms the transaction FAILED → cancel + restore stock, just like
 * the conservative expire path, but driven by the authoritative PSP answer.
 */
it('cancels and restores stock when PSP reports failed', function () {
    $fake = new FakeReconcileGateway();
    $fake->status = 'failed';
    app()->instance(PaymentGatewayInterface::class, $fake);

    $merchant = rgMerchant();
    $product = Product::create([
        'merchant_id' => $merchant->id,
        'name_vi' => 'Tra Sua',
        'name_zh' => '奶茶',
        'price' => 40000.0,
        'stock' => 20,
        'status' => 'on',
    ]);
    $order = Order::create([
        'order_no' => 'GN' . uniqid(),
        'user_id' => rgUser()->id,
        'merchant_id' => $merchant->id,
        'status' => 'pending_payment',
        'amount' => 40000.0,
        'product_amount' => 40000.0,
    ]);
    DB::table('orders')->where('id', $order->id)->update(['created_at' => now()->subHours(2)]); // make it stale past TTL
    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'name' => 'Tra Sua',
        'price' => 40000.0,
        'qty' => 1,
        'subtotal' => 40000.0,
    ]);
    DB::table('orders')->where('id', $order->id)->update(['created_at' => now()->subHours(2)]);
    Payment::create([
        'order_id' => $order->id,
        'method' => 'zalopay',
        'gateway' => 'zalopay',
        'amount' => 40000.0,
        'status' => 'pending',
    ]);

    app(ReconcileOrders::class)->handle();

    expect($order->fresh()->status)->toBe('cancelled');
    expect(Product::find($product->id)->stock)->toBe(21);
    expect(Payment::where('order_id', $order->id)->first()->status)->toBe('failed');
});
