<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\Order;

/**
 * Core business rule for GIAONHANH:
 *  - Platform commission = config('business.commission_rate') (DEFAULT 0% — the
 *    GIAONHANH 0-commission promise). A non-null per-merchant override wins.
 *  - Delivery fee is PLATFORM-SUBSIDIZED when merchant.delivery_subsidy = true
 *    (customer pays 0 delivery; platform pays the rider out of marketing budget)
 *  - New-user coupon is PLATFORM-FUNDED (does not reduce merchant settlement)
 *
 * This service is the single source of truth for order money splits.
 */
class PaymentSplitService
{
    /**
     * @param float|null $commissionRate        Platform commission rate. When
     *        null, falls back to the GLOBAL policy config('business.commission_rate')
     *        (env PLATFORM_COMMISSION_RATE) — the single auditable source of truth
     *        for the 0-commission promise. A caller may pass a per-merchant
     *        override (e.g. merchants.commission_rate) which wins when non-null.
     * @param bool|null   $deliverySubsidyEnabled Falls back to config when null.
     */
    public function __construct(
        private ?float $commissionRate = null,
        private ?bool $deliverySubsidyEnabled = null,
    ) {
        // Resolve from the global platform policy so that flipping the
        // PLATFORM_COMMISSION_RATE env var changes the REAL money math (previously
        // the config existed but was never read — merged orders hardcoded 0.0).
        $this->commissionRate = $commissionRate ?? (float) config('business.commission_rate', 0);
        $this->deliverySubsidyEnabled = $deliverySubsidyEnabled ?? (bool) config('business.delivery_subsidy_enabled', true);
    }

    /**
     * @param float $productAmount  sum of item prices
     * @param float $deliveryFee    merchant's standard delivery fee
     * @param float $platformCouponDiscount  platform-funded coupon amount
     * @param bool  $deliverySubsidized merchant opted into free-delivery subsidy
     * @param float $merchantCouponDiscount  merchant-funded coupon amount
     *        (reduces the merchant's own settlement, not the platform)
     * @return array resolved money fields for the order
     */
    public function compute(
        float $productAmount,
        float $deliveryFee,
        float $platformCouponDiscount = 0.0,
        bool $deliverySubsidized = true,
        float $merchantCouponDiscount = 0.0
    ): array {
        $commissionRate = (float) $this->commissionRate; // 0
        $commission = round($productAmount * $commissionRate, 2);

        $subsidizedDelivery = $deliverySubsidized && $this->deliverySubsidyEnabled;

        // Customer only pays delivery when it is NOT subsidized.
        $customerDelivery = $subsidizedDelivery ? 0.0 : $deliveryFee;

        // Platform eats: subsidized delivery + platform-funded coupon only.
        $platformSubsidy = ($subsidizedDelivery ? $deliveryFee : 0.0) + $platformCouponDiscount;

        // Merchant keeps full product value (0 commission) + un-subsidized
        // delivery, MINUS any merchant-funded coupon it issued.
        $merchantSettlement = $productAmount
            + ($subsidizedDelivery ? 0.0 : $deliveryFee)
            - $merchantCouponDiscount;

        // Customer payable = products + (delivery if any) - ALL discounts.
        $totalDiscount = $platformCouponDiscount + $merchantCouponDiscount;
        $amount = max(0.0, $productAmount + $customerDelivery - $totalDiscount);

        return [
            'product_amount'      => round($productAmount, 2),
            'delivery_fee'        => round($deliveryFee, 2),
            'coupon_discount'     => round($totalDiscount, 2),
            'commission'          => round($commission, 2),
            'platform_subsidy'    => round($platformSubsidy, 2),
            'merchant_settlement' => round($merchantSettlement, 2),
            'amount'              => round($amount, 2),
        ];
    }

    /**
     * Apply the split onto an Order instance (does not save).
     */
    public function apply(
        Order $order,
        Merchant $merchant,
        array $cart,
        float $merchantCouponDiscount = 0.0
    ): Order {
        $productAmount = 0.0;
        foreach ($cart as $item) {
            $productAmount += (float) $item['price'] * (int) $item['qty'];
        }

        $split = $this->compute(
            $productAmount,
            (float) $merchant->delivery_fee,
            (float) ($cart['_coupon_discount'] ?? 0),
            (bool) $merchant->delivery_subsidy,
            $merchantCouponDiscount
        );

        $order->forceFill($split);

        return $order;
    }

    /**
     * P0 — Cross-store merged order split.
     *
     * Aggregates several per-merchant carts into ONE customer order with a
     * SINGLE delivery fee (the killer feature: "一次配送、只收一次配送费").
     *
     * Business rules (unchanged core):
     *  - Platform commission = 0  (every merchant keeps 100% of product value)
     *  - Delivery is PLATFORM-SUBSIDIZED -> customer pays 0 delivery; platform
     *    pays the rider the single flat MERGED_DELIVERY_FEE out of budget.
     *  - New-user coupon is PLATFORM-FUNDED (applied once to the whole order).
     *  - Per-merchant merchant coupons reduce that merchant's sub-settlement.
     *
     * @param array $groups  [ ['merchant'=>Merchant, 'productAmount'=>float], ... ]
     * @param float $couponDiscount  platform-funded coupon for the whole order
     * @param array $merchantCouponDiscounts  merchant_id => merchant-funded discount
     * @return array ['parent'=>[...], 'subs'=>[ [...per merchant], ... ]]
     */
    public function computeMerged(
        array $groups,
        float $couponDiscount = 0.0,
        array $merchantCouponDiscounts = []
    ): array {
        $totalProduct = 0.0;
        foreach ($groups as $g) {
            $totalProduct += (float) ($g['productAmount'] ?? 0);
        }

        // ONE flat delivery fee for the entire merged order (rider gets 100%).
        $singleDelivery = (float) config('business.merged_delivery_fee', 15000);
        $subsidized = $this->deliverySubsidyEnabled;

        // Customer pays delivery only when NOT subsidized.
        $customerDelivery = $subsidized ? 0.0 : $singleDelivery;

        $merchantCouponTotal = 0.0;
        foreach ($merchantCouponDiscounts as $mc) {
            $merchantCouponTotal += (float) $mc;
        }

        // Platform eats: subsidized delivery + platform-funded coupon.
        $platformSubsidy = ($subsidized ? $singleDelivery : 0.0) + $couponDiscount;

        // Customer payable = sum(products) + (delivery if any) - all discounts.
        $amount = max(0.0, $totalProduct + $customerDelivery - $couponDiscount - $merchantCouponTotal);

        // Per-merchant settlement: each keeps its own product value (0 commission);
        // delivery is covered by the parent's single flat fee, so sub delivery = 0.
        // A merchant-funded coupon on that merchant reduces its settlement.
        $subs = [];
        foreach ($groups as $g) {
            $pa = round((float) ($g['productAmount'] ?? 0), 2);
            $mc = round((float) ($merchantCouponDiscounts[$g['merchant']->id] ?? 0), 2);
            $subs[] = [
                'merchant_id'         => $g['merchant']->id,
                'product_amount'      => $pa,
                'delivery_fee'        => 0.0,
                'coupon_discount'     => $mc,
                'platform_subsidy'    => 0.0,
                'commission'          => 0.0,
                'merchant_settlement' => max(0.0, $pa - $mc),
                'amount'              => max(0.0, $pa - $mc),
            ];
        }

        $parent = [
            'type'               => 'merged',
            'product_amount'     => round($totalProduct, 2),
            'delivery_fee'       => round($singleDelivery, 2),
            'group_delivery_fee' => round($singleDelivery, 2),
            'coupon_discount'    => round($couponDiscount + $merchantCouponTotal, 2),
            'platform_subsidy'   => round($platformSubsidy, 2),
            'commission'         => 0.0,
            'merchant_settlement'=> 0.0, // settlement lives on each sub-order
            'amount'             => round($amount, 2),
        ];

        return ['parent' => $parent, 'subs' => $subs];
    }

    /**
     * Haversine distance (km) between two lat/lng points.
     */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $earth * 2 * asin(sqrt($a));
    }
}
