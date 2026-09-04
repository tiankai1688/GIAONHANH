<?php

namespace App\Http\Controllers\Api;

use App\Actions\CreateOrderAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateMergedOrderRequest;
use App\Http\Requests\CreateOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Merchant;
use App\Models\Order;
use App\Services\PaymentGatewayInterface;
use App\Services\PaymentSplitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function __construct(private CreateOrderAction $orders)
    {
    }

    /**
     * Create an order from the cart. Money split resolved by PaymentSplitService.
     */
    public function store(CreateOrderRequest $request)
    {
        $merchant = Merchant::approved()->findOrFail($request->input('merchant_id'));

        return DB::transaction(function () use ($request, $merchant) {
            $built = $this->orders->buildLines($merchant, $request->input('items'));
            $lines = $built['lines'];
            $productAmount = $built['productAmount'];

            // V2 (anti-subsidy-fraud): coupons are computed SERVER-SIDE. The
            // client `coupon_discount` field is ignored entirely (see
            // CreateOrderRequest). A merchant coupon (from the entered code) and
            // the platform NEW_USER coupon (auto, if eligible) can both apply.
            $coupon = $this->orders->resolveServerCoupon(
                $request->user(),
                $productAmount,
                $request->input('coupon_code'),
                $merchant->id
            );

            $order = new Order([
                'order_no'      => 'GN' . date('Ymd') . strtoupper(Str::random(6)),
                'user_id'       => $request->user()->id,
                'merchant_id'   => $merchant->id,
                'status'        => 'pending_payment',
                'delivery_type' => $request->input('delivery_type', 'instant'),
                'expect_time'   => $request->input('expect_time'),
                'pay_method'    => $request->input('pay_method', 'cod'),
                'address'       => $request->input('address'),
                'lat'           => $request->input('lat'),
                'lng'           => $request->input('lng'),
                'contact_name'  => $request->input('contact_name'),
                'contact_phone' => $request->input('contact_phone'),
                'note'          => $request->input('note'),
            ]);

            // Resolve the 0-commission / delivery-subsidy split. Merchant-funded
            // coupon reduces the merchant's settlement; platform coupon does not.
            $splitter = new PaymentSplitService(
                commissionRate: (float) $merchant->commission_rate,
                deliverySubsidyEnabled: config('business.delivery_subsidy_enabled', true)
            );
            $split = $splitter->compute(
                $productAmount,
                (float) $merchant->delivery_fee,
                $coupon['platform_discount'],
                (bool) $merchant->delivery_subsidy,
                $coupon['merchant_discount']
            );
            $this->orders->applySplit($order, $split);
            $order->coupon_id = $coupon['merchant_coupon']?->id;
            $order->save();

            // Record merchant-coupon redemption + bump usage counter.
            $this->orders->recordMerchantCoupon($request->user(), $coupon['merchant_coupon'], $coupon['merchant_discount']);
            // NEW_USER platform coupon redemption MUST be written inside this
            // transaction, so a rolled-back order also rolls back the grant
            // (the customer keeps their one-time coupon for a retry).
            $this->orders->grantNewUserCoupon($request->user(), $coupon['platform_discount']);
            $this->orders->persistItems($order, $lines);

            return (new OrderResource($order->load('items', 'merchant')))
                ->response()->setStatusCode(201);
        });
    }

    /**
     * P0 — Create a cross-store MERGED order: one parent order + one sub-order
     * per merchant. A single delivery fee is charged once (platform-subsidized,
     * so the customer pays 0), and the customer pays one combined total.
     */
    public function storeMerged(CreateMergedOrderRequest $request)
    {
        $user = $request->user();
        $groupsInput = $request->input('groups');

        // Validate each group: merchant approved, products belong to it, build lines.
        $groups = [];
        foreach ($groupsInput as $gi) {
            $merchant = Merchant::approved()->findOrFail($gi['merchant_id']);
            $built = $this->orders->buildLines($merchant, $gi['items']);
            $groups[] = [
                'merchant'      => $merchant,
                'productAmount' => $built['productAmount'],
                'lines'         => $built['lines'],
                'coupon_code'   => $gi['coupon_code'] ?? null,
            ];
        }

        $totalProduct = array_sum(array_column($groups, 'productAmount'));

        // Per-merchant merchant coupons (optional code per group). Keys the
        // discount by merchant_id so computeMerged reduces the right sub-order.
        $merchantCouponDiscounts = [];
        $merchantCoupons = [];
        foreach ($groups as $g) {
            $code = $g['coupon_code'] ?? null;
            if ($code) {
                $r = $this->orders->resolveMerchantCoupon($code, $g['merchant']->id, (float) $g['productAmount']);
                $merchantCouponDiscounts[$g['merchant']->id] = $r['discount'];
                $merchantCoupons[$g['merchant']->id] = $r['coupon'];
            }
        }

        // Platform NEW_USER coupon (auto, merchant-agnostic, platform-funded).
        $platform = $this->orders->resolveServerCoupon($user, $totalProduct, null, 0);
        $platformDiscount = $platform['platform_discount'];

        // Commission is driven by the GLOBAL platform policy
        // (config/business.php → PLATFORM_COMMISSION_RATE). Omitting commissionRate
        // lets PaymentSplitService fall back to that config, so the 0-commission
        // promise is now an auditable config decision rather than a hardcoded
        // literal — and a future monetization lever (flip the env var) affects the
        // flagship merged order too. Single-store orders keep their per-merchant
        // override semantics (merchants.commission_rate) applied in store().
        $splitter = new PaymentSplitService(
            deliverySubsidyEnabled: config('business.delivery_subsidy_enabled', true)
        );
        $split = $splitter->computeMerged($groups, $platformDiscount, $merchantCouponDiscounts);

        return DB::transaction(function () use ($request, $user, $groups, $split, $merchantCoupons, $merchantCouponDiscounts) {
            $parentNo = 'GN' . date('Ymd') . strtoupper(Str::random(6));

            $parent = new Order([
                'order_no'      => $parentNo,
                'user_id'       => $user->id,
                'merchant_id'   => null,
                'type'          => 'merged',
                'status'        => 'pending_payment',
                'delivery_type' => $request->input('delivery_type', 'instant'),
                'expect_time'   => $request->input('expect_time'),
                'pay_method'    => $request->input('pay_method', 'cod'),
                'address'       => $request->input('address'),
                'lat'           => $request->input('lat'),
                'lng'           => $request->input('lng'),
                'contact_name'  => $request->input('contact_name'),
                'contact_phone' => $request->input('contact_phone'),
                'note'          => $request->input('note'),
            ]);
            $this->orders->applySplit($parent, $split['parent']);
            $parent->save();

            foreach ($groups as $idx => $g) {
                $sub = new Order([
                    'order_no'         => $parentNo . '-' . ($idx + 1),
                    'user_id'          => $user->id,
                    'merchant_id'      => $g['merchant']->id,
                    'type'             => 'sub',
                    'parent_order_no'  => $parentNo,
                    'status'           => 'pending_payment',
                    'delivery_type'    => $parent->delivery_type,
                    'pay_method'       => $parent->pay_method,
                    'address'          => $parent->address,
                    'lat'              => $parent->lat,
                    'lng'              => $parent->lng,
                    'contact_name'     => $parent->contact_name,
                    'contact_phone'    => $parent->contact_phone,
                    'note'             => $parent->note,
                ]);
                $this->orders->applySplit($sub, $split['subs'][$idx]);
                $sub->save();

                $this->orders->persistItems($sub, $g['lines']);
            }

            // Record merchant-coupon redemptions + bump usage counters.
            foreach ($merchantCoupons as $merchantId => $c) {
                $this->orders->recordMerchantCoupon($user, $c, $merchantCouponDiscounts[$merchantId]);
            }
            // NEW_USER platform coupon redemption: written INSIDE this transaction
            // so a rolled-back merged order also rolls back the one-time grant.
            $this->orders->grantNewUserCoupon($user, $platformDiscount);

            return (new OrderResource($parent->load('items', 'subOrders')))
                ->response()->setStatusCode(201);
        });
    }

    public function mine(Request $request)
    {
        $orders = $request->user()->orders()
            ->whereNull('parent_order_no') // hide child sub-orders; show merged parent + standalone
            ->with('items', 'merchant', 'rider')
            ->latest()
            ->paginate(20);
        return OrderResource::collection($orders);
    }

    public function show(Request $request, Order $order)
    {
        $this->authorizeOrder($request, $order);
        $with = ['items', 'merchant', 'rider', 'payment'];
        if ($order->type === 'merged') {
            $with[] = 'subOrders';
        }
        return new OrderResource($order->load($with));
    }

    public function cancel(Request $request, Order $order)
    {
        $this->authorizeOrder($request, $order, 'cancel');
        if (! in_array($order->status, ['pending_payment', 'paid', 'accepted'])) {
            return response()->json(['message' => 'Không thể hủy đơn ở trạng thái này.'], 422);
        }

        // A merged parent cancellation cascades to every child sub-order.
        if ($order->type === 'merged') {
            foreach ($order->subOrders as $sub) {
                $sub->update(['status' => 'cancelled']);
            }
        }

        // Refund a successful wallet payment before cancelling. If the gateway
        // refund fails we still cancel but flag it for manual reconciliation
        // (never block the user over a refund hiccup).
        $payment = $order->payment;
        if ($payment && $payment->status === 'success' && $payment->method !== 'cod') {
            try {
                $gateway = app(PaymentGatewayInterface::class);
                $result = $gateway->refund($order, $payment);
                if (($result['status'] ?? 'failed') === 'refunded') {
                    $payment->update(['status' => 'refunded', 'refunded_at' => now()]);
                    $order->update(['refunded_at' => now()]);
                } else {
                    $order->update(['status' => 'cancelled', 'refund_error' => json_encode($result)]);
                    if ($order->rider_id) {
                        $order->rider?->update(['status' => 'online', 'current_order_id' => null]);
                    }
                    return new OrderResource($order->load('items', 'merchant'));
                }
            } catch (\Throwable $e) {
                $order->update(['status' => 'cancelled', 'refund_error' => $e->getMessage()]);
                if ($order->rider_id) {
                    $order->rider?->update(['status' => 'online', 'current_order_id' => null]);
                }
                return new OrderResource($order->load('items', 'merchant'));
            }
        }

        $order->update(['status' => 'cancelled']);
        if ($order->rider_id) {
            $rider = $order->rider;
            $rider?->update(['status' => 'online', 'current_order_id' => null]);
        }
        return new OrderResource($order->load('items', 'merchant'));
    }
}
