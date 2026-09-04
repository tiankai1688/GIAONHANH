<?php

namespace Database\Seeders;

use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Rider;
use App\Models\User;
use App\Services\PaymentSplitService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds a realistic order book so the three-terminal prototype (consumer /
 * rider / merchant) and the real-time voice + Echo chains can be exercised
 * end-to-end after `php artisan migrate --seed`.
 *
 * Money is resolved through the SAME PaymentSplitService the runtime uses, so
 * the seeded numbers always match what a real checkout would produce:
 *   - commission            = 0            (platform takes 0%)
 *   - platform_subsidy      = delivery_fee + coupon   (free delivery + new-user coupon)
 *   - merchant_settlement   = product_amount         (keeps 100%, 0 commission)
 *   - amount (customer pays)= product_amount - coupon
 *
 * Lifecycle coverage: paid → accepted → picked → delivering → delivered, plus
 * the consumer-driven edge states — pending_payment (checkout not yet completed,
 * including one carrying a failed prior payment attempt that can be retried) and
 * cancelled (paid then refunded before the merchant accepted).
 */
class OrderSeeder extends Seeder
{
    public function run(): void
    {
        if (Order::query()->exists()) {
            return; // idempotent: never duplicate on re-seed
        }

        $customer  = User::where('phone', '0900000002')->firstOrFail();
        $shipperA  = Rider::where('phone', '0900000004')->firstOrFail();
        $shipperB  = Rider::where('phone', '0900000005')->firstOrFail();

        $greenMart = Merchant::where('name', 'GREEN MART')->firstOrFail();
        $pho24     = Merchant::where('name', 'PHỞ 24H')->firstOrFail();
        $beautyHub = Merchant::where('name', 'BEAUTY HUB')->firstOrFail();

        // ---- GREEN MART (demo merchant account 0900000003) ----
        $this->makeOrder($greenMart, $customer, [
            [$this->p($greenMart, 'Táo nhập khẩu'), 2],
            [$this->p($greenMart, 'Sữa tươi'), 1],
        ], 'paid', ['age_min' => 8]);

        $o2 = $this->makeOrder($greenMart, $customer, [
            [$this->p($greenMart, 'Nước khoáng'), 3],
        ], 'accepted', ['rider' => $shipperA, 'age_min' => 30]);

        $this->makeOrder($greenMart, $customer, [
            [$this->p($greenMart, 'Sữa tươi'), 2],
            [$this->p($greenMart, 'Táo nhập khẩu'), 1],
        ], 'picked', ['age_min' => 45]); // rider grab-pool (picked + unassigned)

        $o4 = $this->makeOrder($greenMart, $customer, [
            [$this->p($greenMart, 'Táo nhập khẩu'), 1],
            [$this->p($greenMart, 'Sữa tươi'), 1],
        ], 'delivering', ['rider' => $shipperB, 'age_min' => 60]);

        $this->makeOrder($greenMart, $customer, [
            [$this->p($greenMart, 'Nước khoáng'), 2],
        ], 'delivered', ['rider' => $shipperA, 'age_min' => 180]);

        // ---- PHỞ 24H (no demo login — feeds the rider grab-pool) ----
        $this->makeOrder($pho24, $customer, [
            [$this->p($pho24, 'Phở bò'), 1],
            [$this->p($pho24, 'Trà sữa'), 2],
        ], 'picked', ['age_min' => 50]); // rider grab-pool

        $this->makeOrder($pho24, $customer, [
            [$this->p($pho24, 'Gà rán'), 1],
            [$this->p($pho24, 'Phở bò'), 1],
        ], 'delivered', ['rider' => $shipperB, 'age_min' => 240]);

        // ---- BEAUTY HUB ----
        // New-user coupon: demonstrates platform-funded subsidy (platform_subsidy = delivery + 15000).
        $this->makeOrder($beautyHub, $customer, [
            [$this->p($beautyHub, 'Mặt nạ'), 2],
        ], 'paid', ['age_min' => 10, 'coupon' => 15000]);

        $this->makeOrder($beautyHub, $customer, [
            [$this->p($beautyHub, 'Dầu gội'), 1],
        ], 'delivered', ['rider' => $shipperA, 'age_min' => 300]);

        // ---- Edge states: exercise the consumer pay / cancel-refund paths ----
        // pending_payment: no Payment yet — the consumer can complete checkout
        // through the demo pay-mock flow (POST /orders/{no}/pay).
        $this->makeOrder($greenMart, $customer, [
            [$this->p($greenMart, 'Sữa tươi'), 1],
        ], 'pending_payment', ['age_min' => 5]);

        // cancelled: was paid (momo) then cancelled before the merchant accepted
        // — represents the refund path (Payment exists, status rolled back).
        $this->makeOrder($beautyHub, $customer, [
            [$this->p($beautyHub, 'Dầu gội'), 1],
        ], 'cancelled', ['age_min' => 20]);

        // failed payment: order is still pending_payment but carries a failed momo
        // attempt — the consumer can retry checkout (POST /orders/{no}/pay), covering
        // the failure/retry path. payment_status opt forces the Payment row status.
        $this->makeOrder($pho24, $customer, [
            [$this->p($pho24, 'Phở bò'), 1],
        ], 'pending_payment', ['age_min' => 3, 'payment_status' => 'failed']);

        // ---- Bulk demo orders (configurable volume) ----
        // Set SEED_ORDERS_PER_MERCHANT=n in .env to generate n extra delivered
        // history orders per merchant — handy for load / demo screens without
        // touching the curated lifecycle orders above. Defaults to 0 (curated
        // set only), so existing behaviour is preserved out of the box.
        $bulk = (int) env('SEED_ORDERS_PER_MERCHANT', 0);
        if ($bulk > 0) {
            $riders = [$shipperA, $shipperB];
            foreach ([$greenMart, $pho24, $beautyHub] as $m) {
                for ($i = 0; $i < $bulk; $i++) {
                    $prod = $m->products()->inRandomOrder()->first();
                    if (!$prod) continue;
                    $rider = $riders[array_rand($riders)];
                    $this->makeOrder($m, $customer, [[$prod, rand(1, 3)]], 'delivered', [
                        'age_min' => rand(400, 60 * 24 * 10),
                        'rider'   => $rider,
                    ]);
                }
            }
        }

        // Rider B is mid-delivery on O4 — mirror runtime state.
        $shipperB->update(['status' => 'busy', 'current_order_id' => $o4->id]);
    }

    /**
     * Find a product by Vietnamese name within a merchant.
     */
    private function p(Merchant $merchant, string $nameVi): Product
    {
        return $merchant->products()->where('name_vi', $nameVi)->firstOrFail();
    }

    /**
     * Create one order across its full lifecycle with correct money splits.
     *
     * @param  array<int, array{0: Product, 1: int}>  $items  [product, qty]
     * @param  array{rider?: Rider, age_min?: int, coupon?: float, pay_method?: string, address?: string, contact?: string, contact_phone?: string, note?: string}  $opts
     */
    private function makeOrder(Merchant $merchant, User $customer, array $items, string $status, array $opts = []): Order
    {
        $splitter = new PaymentSplitService(
            commissionRate: (float) $merchant->commission_rate,
            deliverySubsidyEnabled: true,
        );

        $productAmount = 0.0;
        foreach ($items as [$product, $qty]) {
            $productAmount += $product->effectivePrice() * $qty;
        }

        $coupon = (float) ($opts['coupon'] ?? 0);
        $split  = $splitter->compute(
            $productAmount,
            (float) $merchant->delivery_fee,
            $coupon,
            (bool) $merchant->delivery_subsidy,
        );

        $order = Order::create(array_merge([
            'order_no'      => $this->orderNo(),
            'user_id'       => $customer->id,
            'merchant_id'   => $merchant->id,
            'rider_id'      => $opts['rider']?->id,
            'status'        => $status,
            'delivery_type' => 'instant',
            'pay_method'    => $opts['pay_method'] ?? 'momo',
            'address'       => $opts['address'] ?? '12 Đường Xuân Thủy, Cầu Giấy, Hà Nội',
            'lat'           => (float) $merchant->lat + 0.0012,
            'lng'           => (float) $merchant->lng - 0.0009,
            'contact_name'  => $opts['contact'] ?? 'Nguyễn Văn An',
            'contact_phone' => $opts['contact_phone'] ?? '0912345678',
            'note'          => $opts['note'] ?? null,
        ], $split));

        // Cascade timestamps by status so the front-end "X 分钟前" reads naturally.
        $createdAt   = now()->subMinutes($opts['age_min'] ?? 30);
        $paidAt      = in_array($status, ['paid', 'accepted', 'picked', 'delivering', 'delivered', 'cancelled']) ? $createdAt->copy()->addMinutes(2) : null;
        $acceptedAt  = in_array($status, ['accepted', 'picked', 'delivering', 'delivered']) ? $paidAt->copy()->addMinutes(8) : null;
        $pickedAt    = in_array($status, ['picked', 'delivering', 'delivered']) ? $acceptedAt->copy()->addMinutes(5) : null;
        $deliveringAt = in_array($status, ['delivering', 'delivered']) ? $pickedAt->copy()->addMinutes(3) : null;
        $deliveredAt = $status === 'delivered' ? $deliveringAt->copy()->addMinutes(15) : null;

        $order->created_at   = $createdAt;
        $order->paid_at      = $paidAt;
        $order->accepted_at  = $acceptedAt;
        $order->picked_at    = $pickedAt;
        $order->delivering_at = $deliveringAt;
        $order->delivered_at = $deliveredAt;
        $order->save();

        foreach ($items as [$product, $qty]) {
            $price = $product->effectivePrice();
            $order->items()->create([
                'product_id' => $product->id,
                'name'       => $product->name_vi,
                'name_zh'    => $product->name_zh,
                'price'      => $price,
                'qty'       => $qty,
                'subtotal'   => round($price * $qty, 2),
            ]);
            $product->increment('sales', $qty);
        }

        $payStatus = $opts['payment_status'] ?? 'success';
        if ($status !== 'pending_payment' || $payStatus !== 'success') {
            $method = $opts['pay_method'] ?? 'momo';
            Payment::create([
                'order_id'   => $order->id,
                'method'     => $method,
                'amount'     => $order->amount,
                'status'     => $payStatus,
                'gateway'    => $method === 'cod' ? null : $method,
                'paid_at'    => $payStatus === 'success' ? $paidAt : null,
            ]);
        }

        return $order;
    }

    /**
     * Order number mirrors the runtime format: GN + YYYYMMDD + 6 uppercase chars.
     */
    private function orderNo(): string
    {
        do {
            $no = 'GN' . date('Ymd') . strtoupper(Str::random(6));
        } while (Order::where('order_no', $no)->exists());

        return $no;
    }
}
