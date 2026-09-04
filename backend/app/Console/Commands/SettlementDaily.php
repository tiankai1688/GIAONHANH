<?php

namespace App\Console\Commands;

use App\Services\MerchantSettlementService;
use Illuminate\Console\Command;

/**
 * T+1 daily settlement report.
 *
 *   php artisan settlement:daily            # yesterday (default T+1 cut)
 *   php artisan settlement:daily 2026-07-29 # specific business day
 */
class SettlementDaily extends Command
{
    protected $signature = 'settlement:daily {date? : business day (Y-m-d), default yesterday}';
    protected $description = 'Compute T+1 merchant payouts for sub-orders delivered on a given day';

    public function handle(): int
    {
        $date = $this->argument('date');
        $report = MerchantSettlementService::perMerchant($date);

        $this->info("T+1 Settlement for {$report['settle_date']}");
        $this->info("Merchants: {$report['merchant_count']} | Total payable: {$report['total_payable']} VND");

        foreach ($report['merchants'] as $m) {
            $this->line(
                " - {$m['merchant_name']}: {$m['order_count']} orders, payable {$m['payable']} VND"
            );
        }

        return self::SUCCESS;
    }
}
