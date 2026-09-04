<?php

namespace App\Actions;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\CouponService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Encapsulates the order-creation domain logic shared between the single-store
 * `OrderController::store` and the cross-store `OrderController::storeMerged`
 * paths. Extracted to remove the ~70 lines of duplicated product validation /
 * line building / item persistence / coupon resolution that previously lived in
 * both controllers (see docs/code-architecture-review-2026-08-01.md, P0-2).
 */
class CreateOrderAction
{
    public function __construct(private CouponService $couponService)
    {
    }

    /**
     * Validate that every product belongs to the (approved) merchant and build
     * priced line items. Shared by single-store and merged orders.
     *
     * @return array{ lines: array, productAmount: float }
     */
    public function buildLines(Merchant $merchant, array $items): array
    {
        $lines = [];
        $productAmount = 0.0;

        foreach ($items as $item) {
            $product = Product::findOrFail($item['product_id']);
            if ($product->merchant_id !== $merchant->id) {
                abort(422, 'Sản phẩm không thuộc merchant này.');
            }
            $qty = (int) $item['qty'];
            $price = $product->effectivePrice();
            $subtotal = round($price * $qty, 2);
            $productAmount += $subtotal;
            $lines[] = [
                'product'  => $product,
                'qty'      => $qty,
                'price'    => $price,
                'subtotal' => $subtotal,
            ];
        }

        return ['lines' => $lines, 'productAmount' => $productAmount];
    }

    /**
     * Persist order items, atomically reserve stock, and bump the sales counter.
     * Stock is decremented inside a WHERE stock >= qty UPDATE so concurrent
     * orders cannot oversell the same product (no negative inventory). If the
     * available stock is short, the whole order transaction is rolled back.
     */
    public function persistItems(Order $order, array $lines): void
    {
        foreach ($lines as $line) {
            $this->reserveStock($line['product'], $line['qty']);
            $order->items()->create([
                'product_id' => $line['product']->id,
                'name'       => $line['product']->name_vi,
                'name_zh'    => $line['product']->name_zh,
                'price'      => $line['price'],
                'qty'        => $line['qty'],
                'subtotal'   => $line['subtotal'],
            ]);
            $line['product']->increment('sales', $line['qty']);
        }
    }

    /**
     * Atomically subtract `qty` from a product's stock (and flash_stock when
     * applicable). The UPDATE carries a WHERE stock >= qty predicate, so the
     * decrement only succeeds when enough stock exists — this is the real guard
     * against overselling under concurrency. Aborts 422 (rolling back the parent
     * order transaction) when stock is insufficient.
     */
    private function reserveStock(Product $product, int $qty): void
    {
        $affected = DB::table('products')
            ->where('id', $product->id)
            ->where('stock', '>=', $qty)
            ->decrement('stock', $qty);
        if ($affected === 0) {
            abort(422, 'Sản phẩm "' . $product->name_vi . '" tạm hết hàng.');
        }

        if ($product->is_flash) {
            $f = DB::table('products')
                ->where('id', $product->id)
                ->where('flash_stock', '>=', $qty)
                ->decrement('flash_stock', $qty);
            if ($f === 0) {
                abort(422, 'Flash sale "' . $product->name_vi . '" đã hết quà.');
            }
        }
    }

    /**
     * V2: Server-side coupon resolution. Combines the auto platform NEW_USER
     * coupon (platform-funded) with an optional merchant coupon (from the
     * entered code, merchant-funded). Never trusts client input.
     *
     * @return array{ platform_discount: float, merchant_coupon: ?Coupon, merchant_discount: float }
     */
    public function resolveServerCoupon(User $user, float $productAmount, ?string $couponCode, int $merchantId): array
    {
        $platformDiscount = $this->resolveNewUserCoupon($user, $productAmount);

        $merchantCoupon = null;
        $merchantDiscount = 0.0;
        if ($couponCode) {
            try {
                $r = $this->couponService->resolve($couponCode, $merchantId, $productAmount);
                $merchantCoupon = $r['coupon'];
                $merchantDiscount = $r['discount'];
            } catch (ValidationException $e) {
                abort(422, $e->errors()['coupon_code'][0] ?? 'Mã không hợp lệ.');
            }
        }

        return [
            'platform_discount' => $platformDiscount,
            'merchant_coupon'   => $merchantCoupon,
            'merchant_discount' => $merchantDiscount,
        ];
    }

    /**
     * Resolve ONLY a merchant-issued coupon (no platform NEW_USER coupon). Used
     * by the merged path, which computes the platform coupon once globally.
     *
     * @return array{ coupon: ?Coupon, discount: float }
     */
    public function resolveMerchantCoupon(?string $code, int $merchantId, float $productAmount): array
    {
        if (! $code) {
            return ['coupon' => null, 'discount' => 0.0];
        }
        try {
            $r = $this->couponService->resolve($code, $merchantId, $productAmount);
            return ['coupon' => $r['coupon'], 'discount' => $r['discount']];
        } catch (ValidationException $e) {
            abort(422, $e->errors()['coupon_code'][0] ?? 'Mã không hợp lệ.');
        }
    }

    /**
     * Record a merchant-coupon redemption and bump its usage counter.
     * The coupon row is locked (lockForUpdate) and its usage_limit re-checked
     * under the lock so concurrent redemptions cannot push used_count past the
     * limit. This is ALWAYS called from inside the order-creating transaction
     * (store / storeMerged), so we deliberately do NOT open a nested transaction:
     * a nested DB::transaction would hold the coupon lock across the entire outer
     * transaction via a savepoint and raise lock-ordering deadlock risk when
     * another path acquires Order vs Coupon locks in the opposite order.
     *
     * LOCK-ORDERING CONVENTION (deadlock avoidance): within any single
     * transaction, acquire Order locks BEFORE Coupon locks. Both callers insert
     * the Order rows first, then call this method to lock Coupon — keeping a
     * single, consistent acquisition order across the codebase.
     */
    public function recordMerchantCoupon(User $user, ?Coupon $coupon, float $discount): void
    {
        if (! $coupon) {
            return;
        }
        $locked = Coupon::where('id', $coupon->id)->lockForUpdate()->first();
        if (! $locked) {
            return;
        }
        if ($locked->used_count >= $locked->usage_limit) {
            throw ValidationException::withMessages(['coupon_code' => 'Mã đã hết lượt sử dụng.']);
        }
        $locked->increment('used_count');
        CouponRedemption::create([
            'user_id'     => $user->id,
            'coupon_code' => $locked->code,
            'coupon_id'   => $locked->id,
            'amount'      => $discount,
        ]);
    }

    /**
     * Centralise the forceFill($split) key mapping so a change to the keys
     * returned by PaymentSplitService only needs to be reconciled in ONE place
     * (previously duplicated in store + storeMerged).
     */
    public function applySplit(Order $order, array $split): void
    {
        $order->forceFill($split);
    }

    /**
     * Auto-grants the platform "NEW_USER" coupon at most once per user, only to
     * genuinely new users, capped to the product amount. Recorded so the same
     * user can never reuse it (anti 套补贴).
     */
    private function resolveNewUserCoupon(User $user, float $productAmount): float
    {
        $code = 'NEW_USER';
        $max  = (float) config('business.new_user_coupon_amount', 0);
        if ($max <= 0) {
            return 0.0;
        }
        if (CouponRedemption::where('user_id', $user->id)
            ->where('coupon_code', $code)->exists()) {
            return 0.0;
        }
        $hasPriorOrder = Order::where('user_id', $user->id)
            ->whereIn('status', ['paid', 'accepted', 'picked', 'delivering', 'delivered'])
            ->exists();
        if ($hasPriorOrder) {
            return 0.0;
        }
        $discount = min($max, $productAmount);
        // NOTE: eligibility is computed here, but the redemption ROW is NOT
        // written yet. It is persisted later by grantNewUserCoupon() from INSIDE
        // the order-creating DB transaction, so a rolled-back order also rolls
        // back the coupon grant (otherwise a user permanently loses their
        // one-time coupon with no order — the "coupon consumed outside the
        // transaction" bug). The (user_id, coupon_code) unique index still backs
        // the concurrent first-order race (handled in grantNewUserCoupon).
        return $discount;
    }

    /**
     * Persist the platform NEW_USER coupon redemption. MUST be invoked from within
     * the order-creating transaction (store / storeMerged) so a failed/rolled-back
     * order transaction also rolls back this grant — the user keeps their
     * one-time coupon for a retry. The (user_id, coupon_code) unique index is the
     * hard guard against a concurrent first-order race: we swallow the 23000
     * duplicate and simply grant nothing instead of 500-ing.
     */
    public function grantNewUserCoupon(User $user, float $discount): void
    {
        if ($discount <= 0) {
            return;
        }
        try {
            CouponRedemption::create([
                'user_id'     => $user->id,
                'coupon_code' => 'NEW_USER',
                'amount'      => $discount,
            ]);
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                return;
            }
            throw $e;
        }
    }
}
