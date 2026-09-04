<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;

/**
 * Contract for all payment-gateway integrations (MoMo / ZaloPay / aggregator).
 *
 * Extracted from the concrete PaymentGatewayService so that controllers depend
 * on THIS interface (not the class) and tests can bind a mock — see
 * docs/code-architecture-review-2026-08-01.md, P1-d. The Laravel container
 * binds the concrete implementation in bootstrap/app.php via withBindings,
 * and the public IPN/callback controllers resolve the interface by class.
 */
interface PaymentGatewayInterface
{
    /**
     * Build (and optionally dispatch) a MoMo payment.
     * Returns ['status','pay_url','trans_id','raw'].
     */
    public function createMoMo(Order $order, string $ipnUrl, string $returnUrl): array;

    /**
     * Build (and optionally dispatch) a ZaloPay payment.
     * Returns ['status','pay_url','trans_id','raw'].
     */
    public function createZaloPay(Order $order, string $ipnUrl, string $returnUrl): array;

    /**
     * Verify a MoMo IPN callback signature. Returns true when it matches.
     */
    public function verifyMoMoIpn(array $data): bool;

    /**
     * Single unified entry point used by PaymentController::pay.
     * Returns ['status','pay_url','trans_id','raw','gateway'].
     */
    public function createPayment(Order $order, string $method, string $ipnUrl, string $returnUrl): array;

    /**
     * Route a wallet payment through a licensed aggregator (Sepay / Payoo).
     */
    public function createViaAggregator(Order $order, string $method, string $aggregator, string $ipnUrl, string $returnUrl): array;

    /**
     * Verify a ZaloPay callback (base64 data + HMAC with key2).
     * Returns the decoded payload on success, else null.
     */
    public function verifyZaloPayCallback(string $data, string $mac): ?array;

    /**
     * Verify an aggregator callback (base64 data + HMAC with aggregator key).
     * Returns the decoded payload on success, else null.
     */
    public function verifyAggregatorIpn(string $name, string $data, string $mac): ?array;

    /**
     * Refund a successful wallet payment when the customer cancels.
     * COD is a no-op. Returns ['status' => 'refunded'|'skipped'|'failed'].
     */
    public function refund(Order $order, Payment $payment): array;

    /**
     * Query a PSP transaction status for reconciliation (orders:reconcile).
     *
     * Returns one of: 'paid' | 'failed' | 'expired' | 'pending' — or null when
     * the gateway is NOT configured / unreachable / in sandbox, in which case the
     * caller MUST apply its conservative fallback (expire the order) and MUST
     * NEVER assume 'paid' on a null. This is the fail-closed contract that lets
     * the self-heal reconcile against the real source of truth without ever
     * falsely marking an unpaid order as paid.
     */
    public function queryStatus(string $orderNo, string $gateway): ?string;
}
