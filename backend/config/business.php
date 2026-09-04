<?php

/**
 * Core business policy for the 0-commission + delivery-subsidy model.
 *
 * Read via config('business.*') — never env() in runtime code, so that
 * `php artisan config:cache` keeps these values available in production.
 */

return [
    // Platform commission rate (0 = the GIAONHANH 0-commission promise).
    'commission_rate' => (float) env('PLATFORM_COMMISSION_RATE', 0),

    // When true the platform pays the delivery fee (customer pays 0 delivery).
    'delivery_subsidy_enabled' => env('DELIVERY_SUBSIDY_ENABLED', true),

    // Fixed amount (VND) of the platform-funded NEW_USER welcome coupon.
    'new_user_coupon_amount' => (float) env('NEW_USER_COUPON_AMOUNT', 0),

    // Flat single delivery fee charged ONCE for a cross-store merged order.
    'merged_delivery_fee' => (float) env('MERGED_DELIVERY_FEE', 15000),
];
