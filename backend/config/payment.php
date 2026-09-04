<?php

/**
 * Payment gateway configuration for GIAONHANH.
 *
 * All PAYMENT_*/MOMO_*/ZALOPAY_*/AGGREGATOR_* env values are read HERE — the ONLY
 * place env() is permitted. Runtime code MUST read config('payment.*') instead
 * of env() so that `php artisan config:cache` does not silently null out the
 * gateway credentials / sandbox secret and break signature verification
 * (fail-closed) in production. See docs/code-architecture-review-2026-08-01.md.
 */

return [
    // Sandbox cashier: when true, the local pay-mock.html cashier is used and no
    // real PSP traffic is sent (still full signing + verification end-to-end).
    //
    // SECURITY (2026-08-01): DEFAULT IS NOW FALSE. A bare `config:cache` or any
    // deploy that ships config without PAYMENT_SANDBOX explicitly set is SAFE —
    // verification falls back to the REAL gateway keys and fails closed when they
    // are missing, so a "forgot to configure" production can NEVER be forged via
    // the public IPN routes (see resolveVerifyKey / fail-closed). Sandbox is now
    // strictly OPT-IN (set PAYMENT_SANDBOX=true in a dev/.env only). The dev
    // sandbox_secret below is consulted ONLY when sandbox is explicitly true and
    // is never the production verification path.
    'sandbox' => env('PAYMENT_SANDBOX', false),

    // DEV/TEST sandbox secret. Used ONLY when `sandbox` is true to sign + verify
    // the local mock IPN, so the full callback pipeline is exercised without a
    // real PSP. It is intentionally a known value, NOT a production secret:
    //   - Production sets real MOMO_*/ZALOPAY_* keys → has_momo/has_zalo true →
    //     sandbox forced false → this secret is never used for verification.
    //   - The REAL gateway keys (momo_secret_key / zalopay_key2 /
    //     aggregator_api_key) still fail closed when missing in production.
    // Production MUST either configure real gateway keys or set PAYMENT_SANDBOX=false.
    'sandbox_secret' => env('PAYMENT_SANDBOX_SECRET', 'GIAONHANH_SANDBOX_SECRET'),

    // Whether real PSP credentials are configured (derived; not an env var).
    'has_momo' => ! empty(env('MOMO_PARTNER_CODE')) && ! empty(env('MOMO_SECRET_KEY')),
    'has_zalo' => ! empty(env('ZALOPAY_APP_ID')) && ! empty(env('ZALOPAY_KEY1')),

    // --- MoMo (Payment Gateway v2) ---
    'momo_partner_code'      => env('MOMO_PARTNER_CODE', 'MOMO_PARTNER'),
    'momo_access_key'        => env('MOMO_ACCESS_KEY', 'MOMO_ACCESS'),
    'momo_secret_key'        => env('MOMO_SECRET_KEY', ''),
    'momo_endpoint'          => env('MOMO_ENDPOINT', 'https://test-payment.momo.vn/v2/gateway/pay'),
    'momo_refund_endpoint'   => env('MOMO_REFUND_ENDPOINT', 'https://test-payment.momo.vn/v2/gateway/api/refund'),
    'momo_query_endpoint'    => env('MOMO_QUERY_ENDPOINT', 'https://test-payment.momo.vn/v2/gateway/api/query'),

    // --- ZaloPay ---
    'zalopay_app_id'         => env('ZALOPAY_APP_ID', 2553),
    'zalopay_key1'           => env('ZALOPAY_KEY1', ''),
    'zalopay_key2'           => env('ZALOPAY_KEY2', ''),
    'zalopay_endpoint'       => env('ZALOPAY_ENDPOINT', 'https://sandbox.zalopay.com.vn/v001/tpe/createorder'),
    'zalopay_refund_endpoint' => env('ZALOPAY_REFUND_ENDPOINT', 'https://sandbox.zalopay.com.vn/v001/tpe/refund'),
    'zalopay_query_endpoint'  => env('ZALOPAY_QUERY_ENDPOINT', 'https://sandbox.zalopay.com.vn/v001/tpe/query'),

    // --- Licensed aggregator (Sepay / Payoo) ---
    'aggregator'                 => env('PAYMENT_AGGREGATOR', 'none'),
    'aggregator_api_key'         => env('AGGREGATOR_API_KEY', ''),
    'aggregator_endpoint'        => env('AGGREGATOR_ENDPOINT', 'https://my.sepay.vn/api/v1/orders'),
    'aggregator_merchant_account' => env('AGGREGATOR_MERCHANT_ACCOUNT', 'MERCHANT_VND_SETTLEMENT'),

    // P0#6 — PSP (MoMo / ZaloPay) acquirer-fee bearer. Closes the
    // unit-economics blind spot in the 0-commission model: payment channels
    // still charge ~1.5%–3.5% per transaction. This MUST be decided with
    // legal/finance and written into the merchant agreement. The actual fee
    // charged per order is recorded on orders.psp_fee; this config decides who
    // absorbs it. Default 'platform' (platform absorbs) — set 'merchant' ONLY
    // if the signed merchant agreement explicitly shifts it.
    'psp_fee_bearer' => env('PSP_FEE_BEARER', 'platform'),

    // PSP acquirer fee RATE applied to wallet (MoMo/ZaloPay/aggregator) orders.
    // COD carries no gateway fee. 0.025 = 2.5%, a conservative midpoint of the
    // 1.5%–3.5% MoMo/ZaloPay band. Recorded per order (orders.psp_fee /
    // payments.psp_fee) so unit economics are NEVER blind again.
    'psp_fee_rate' => env('PSP_FEE_RATE', 0.025),

    // How long an order/payment may sit in pending_payment / pending before the
    // reconciliation command (orders:reconcile) expires/fails it. Prevents
    // permanently stuck orders when an IPN is lost (red-team hacker #5).
    'pending_ttl_minutes' => env('PAYMENT_PENDING_TTL_MINUTES', 30),
];
