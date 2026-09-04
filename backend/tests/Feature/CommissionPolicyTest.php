<?php

use App\Services\PaymentSplitService;

uses(RefreshDatabase::class);

/*
 * The 0-commission promise must be an AUDITABLE config decision, not a literal.
 * Flipping PLATFORM_COMMISSION_RATE (config/business.php) must change the real
 * money math for the flagship merged order (which previously hardcoded 0.0 and
 * ignored the config entirely).
 */
it('uses the global commission_rate config as the default', function () {
    config(['business.commission_rate' => 0.05]); // 5% monetization lever
    $split = (new PaymentSplitService())->compute(100000, 15000);

    expect($split['commission'])->toBe(5000.0);
});

it('defaults commission to 0 when the config is 0', function () {
    config(['business.commission_rate' => 0]);
    $split = (new PaymentSplitService())->compute(100000, 15000);

    expect($split['commission'])->toBe(0.0);
});

/*
 * A caller-supplied per-merchant override still wins over the global config
 * (single-store orders pass merchants.commission_rate). The global config is
 * only the DEFAULT, so existing per-merchant deals are preserved.
 */
it('honors an explicit per-merchant commission override', function () {
    config(['business.commission_rate' => 0.05]); // platform-wide 5%
    $split = (new PaymentSplitService(commissionRate: 0.10))->compute(100000, 15000);

    expect($split['commission'])->toBe(10000.0); // merchant-specific 10% wins
});
