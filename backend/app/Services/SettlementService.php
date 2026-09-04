<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\Order;

/**
 * Finance / reconciliation for the 0-commission + delivery-subsidy model.
 *
 * Even at 0% commission the platform must still TRACK what each merchant is
 * owed (merchant_settlement) and how much delivery subsidy the platform itself
 * spent (platform_subsidy). This is the data an admin exports for payout.
 *
 * SCOPE: lifetime CUMULATIVE payable per merchant (`merchantPayouts`) and
 * platform-wide totals (`summary`). For the T+1 DAILY per-merchant breakdown
 * (a specific business day, sub-orders only), see MerchantSettlementService.
 *
 * @see \App\Services\MerchantSettlementService
 */
class SettlementService
{
    public function summary(): array
    {
        return [
            'order_counts' => [
                'total'     => Order::count(),
                'active'    => Order::whereIn('status', ['paid', 'accepted', 'picked', 'delivering'])->count(),
                'delivered' => Order::where('status', 'delivered')->count(),
            ],
            'gmv'                        => (float) Order::sum('amount'),
            'merchant_settlement_total' => (float) Order::sum('merchant_settlement'),
            'platform_subsidy_total'    => (float) Order::sum('platform_subsidy'),
            'commission_total'          => (float) Order::sum('commission'),
            'by_merchant' => Merchant::withSum('orders', 'merchant_settlement')
                ->get()
                ->map(fn (Merchant $m) => [
                    'id'         => $m->id,
                    'name'       => $m->name,
                    'settlement' => (float) ($m->orders_sum_merchant_settlement ?? 0),
                ])
                ->sortByDesc('settlement')
                ->values(),
        ];
    }

    public function merchantPayouts(): array
    {
        return Merchant::withSum('orders', 'merchant_settlement')
            ->get()
            ->map(fn (Merchant $m) => [
                'merchant_id' => $m->id,
                'name'        => $m->name,
                'payable'     => (float) ($m->orders_sum_merchant_settlement ?? 0),
                'bank_account' => $m->bank_account,
                'kyc_status'  => $m->kyc_status,
            ])
            ->values()
            ->all();
    }
}
