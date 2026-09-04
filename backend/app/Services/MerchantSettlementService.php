<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\Order;
use Illuminate\Support\Carbon;

/**
 * T+1 merchant settlement.
 *
 * Merchant-billable orders are BOTH:
 *  - single-store orders (type=single, the platform's most common order type,
 *    carrying its own merchant_id + merchant_settlement), and
 *  - sub-orders of a merged parent (type=sub, one per merchant).
 * A merged PARENT (type=merged) carries no merchant_id and is never settled
 * directly. Each billable order already has its own merchant_settlement
 * precomputed by PaymentSplitService (0 platform commission — the GIAONHANH
 * business model).
 *
 * SCOPE: the per-BUSINESS-DAY (default yesterday) breakdown of what each
 * merchant is owed, used for the daily payout run. This is DISTINCT from
 * SettlementService, which reports LIFETIME CUMULATIVE payable totals.
 *
 * @see \App\Services\SettlementService
 */
class MerchantSettlementService
{
    /**
     * Single merchant's T+1 settlement for a business day.
     * Defaults to yesterday (the standard T+1 cut).
     */
    public static function forMerchant(Merchant $merchant, ?string $date = null): array
    {
        $settleDate = $date ? Carbon::parse($date) : Carbon::yesterday();

        $orders = Order::where('merchant_id', $merchant->id)
            ->whereIn('type', ['single', 'sub'])
            ->whereDate('delivered_at', $settleDate)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->with('items')
            ->get();

        return [
            'merchant_id'  => $merchant->id,
            'merchant_name'=> $merchant->name,
            'settle_date'  => $settleDate->toDateString(),
            'order_count'  => $orders->count(),
            'commission'   => 0.0, // platform commission is always 0
            'payable'      => (float) $orders->sum('merchant_settlement'),
            'orders'       => $orders->map(fn ($o) => [
                'order_no'         => $o->order_no,
                'parent_order_no' => $o->parent_order_no,
                'product_amount'   => (float) $o->product_amount,
                'settlement'       => (float) $o->merchant_settlement,
                'delivered_at'     => $o->delivered_at,
            ])->values(),
        ];
    }

    /**
     * Platform-wide T+1 payout breakdown per merchant (admin view).
     */
    public static function perMerchant(?string $date = null): array
    {
        $settleDate = $date ? Carbon::parse($date) : Carbon::yesterday();

        $rows = Order::whereIn('type', ['single', 'sub'])
            ->whereDate('delivered_at', $settleDate)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->with('merchant')
            ->get()
            ->groupBy('merchant_id')
            ->map(function ($group) {
                $m = $group->first()->merchant;

                return [
                    'merchant_id'   => $group->first()->merchant_id,
                    'merchant_name' => $m ? $m->name : null,
                    'order_count'   => $group->count(),
                    'commission'    => 0.0,
                    'payable'       => (float) $group->sum('merchant_settlement'),
                ];
            })->values();

        return [
            'settle_date'    => $settleDate->toDateString(),
            'merchant_count' => $rows->count(),
            'total_payable'  => (float) $rows->sum('payable'),
            'merchants'      => $rows,
        ];
    }
}
