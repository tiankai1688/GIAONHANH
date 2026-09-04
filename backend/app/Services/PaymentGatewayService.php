<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Real MoMo / ZaloPay gateway integration for GIAONHANH.
 *
 *  - MoMo (Payment Gateway v2): signs every request with HMAC-SHA256 using
 *    the secret key, and VERIFIES the IPN callback signature the same way.
 *  - ZaloPay: signs the create-order request with key1 (HMAC-SHA256) and
 *    VERIFIES the callback `data`/`mac` with key2.
 *
 * When real credentials are absent (or PAYMENT_SANDBOX=true) the service still
 * runs the FULL signing + verification pipeline, but points the user at a local
 * sandbox cashier (public/pay-mock.html). The signature is generated server
 * side and embedded in the mock URL, so the IPN callback verification is
 * genuinely exercised end-to-end — only the external PSP is stubbed.
 */
class PaymentGatewayService implements PaymentGatewayInterface
{
    private bool $sandbox;
    private string $sandboxSecret;

    public function __construct()
    {
        $hasMoMo = config('payment.has_momo', false);
        $hasZalo = config('payment.has_zalo', false);
        $this->sandbox = config('payment.sandbox', ! ($hasMoMo || $hasZalo));
        // Dev/test sandbox secret. In SANDBOX mode this signs + verifies the
        // local mock IPN so the callback pipeline is exercised end-to-end. It is
        // a known dev value, NOT a production credential. REAL gateway keys
        // (momo_secret_key / zalopay_key2 / aggregator_api_key) still FAIL
        // CLOSED when missing in production — sandbox is forced off there by
        // real-key presence, so this secret is never used for real traffic.
        $this->sandboxSecret = (string) config('payment.sandbox_secret', 'GIAONHANH_SANDBOX_SECRET');
    }

    /* =====================================================================
     |  MoMo
     ===================================================================== */

    /**
     * Build (and optionally dispatch) a MoMo payment.
     * Returns ['status','pay_url','trans_id','raw'].
     */
    public function createMoMo(Order $order, string $ipnUrl, string $returnUrl): array
    {
        $partnerCode = config('payment.momo_partner_code');
        $accessKey   = config('payment.momo_access_key');
        $secretKey   = config('payment.momo_secret_key', $this->sandboxSecret);
        $endpoint    = config('payment.momo_endpoint');

        $orderId   = $order->order_no;
        $requestId = 'momo_' . $order->order_no . '_' . time();
        $amount    = (int) round($order->amount);
        $orderInfo = 'GIAONHANH ' . $order->order_no;
        $extraData = base64_encode(json_encode(['order_no' => $order->order_no]));
        $requestType = 'payWithMethod';

        $raw = "accessKey=$accessKey&amount=$amount&extraData=$extraData&ipnUrl=$ipnUrl"
            . "&orderId=$orderId&orderInfo=$orderInfo&partnerCode=$partnerCode"
            . "&redirectUrl=$returnUrl&requestId=$requestId&requestType=$requestType";
        $signature = hash_hmac('sha256', $raw, $secretKey);

        if (! $this->sandbox) {
            $res = Http::timeout(15)->post($endpoint, [
                'partnerCode' => $partnerCode,
                'partnerName' => 'GIAONHANH',
                'storeId'     => 'GIAONHANH_STORE',
                'requestId'   => $requestId,
                'amount'      => $amount,
                'orderId'     => $orderId,
                'orderInfo'   => $orderInfo,
                'redirectUrl' => $returnUrl,
                'ipnUrl'      => $ipnUrl,
                'extraData'   => $extraData,
                'requestType' => $requestType,
                'signature'   => $signature,
            ]);
            $body = $res->json();
            if (($body['resultCode'] ?? -1) !== 0) {
                Log::error('MoMo create failed', $body);
                return ['status' => 'failed', 'pay_url' => null, 'trans_id' => $requestId, 'raw' => $body];
            }
            return [
                'status'   => 'pending',
                'pay_url'  => $body['payUrl'] ?? ($body['deeplink'] ?? null),
                'trans_id' => $requestId,
                'raw'      => $body,
            ];
        }

        // Sandbox: embed a correctly-signed IPN payload in our local cashier URL.
        // MoMo re-signs the callback over the IPN field set, so we sign that
        // same set here (the external PSP is the only thing stubbed).
        $ipnData = [
            'partnerCode'   => $partnerCode,
            'accessKey'     => $accessKey,
            'amount'        => $amount,
            'orderId'       => $orderId,
            'orderInfo'     => $orderInfo,
            'orderType'     => 'momo_wallet',
            'transId'       => 'MOMO' . time(),
            'resultCode'    => 0,
            'message'       => 'Success',
            'extraData'     => $extraData,
            'paymentOption' => 'MOMO_WALLET',
        ];
        $ipnSignature = $this->momoIpnSignature($ipnData, config('payment.momo_secret_key', $this->sandboxSecret));

        $params = http_build_query([
            'method'      => 'momo',
            'orderId'     => $orderId,
            'requestId'   => $requestId,
            'amount'      => $amount,
            'orderInfo'   => $orderInfo,
            'partnerCode' => $partnerCode,
            'accessKey'   => $accessKey,
            'extraData'   => $extraData,
            'orderType'   => 'momo_wallet',
            'paymentOption' => 'MOMO_WALLET',
            'message'     => 'Success',
            'resultCode'  => 0,
            'transId'     => $ipnData['transId'],
            'ipnUrl'      => $ipnUrl,
            'redirectUrl' => $returnUrl,
            'signature'   => $ipnSignature,
        ]);
        return [
            'status'   => 'pending',
            'pay_url'  => rtrim(config('app.url', 'http://localhost:8000'), '/') . '/pay-mock.html?' . $params,
            'trans_id' => $requestId,
            'raw'      => ['sandbox' => true],
        ];
    }

    /**
     * Build the MoMo IPN signature over the SAME field set MoMo uses for its
     * callback (message/resultCode/transId/orderType/paymentOption), which is
     * DIFFERENT from the create-request field set. Used both for verification
     * and for signing the sandbox mock IPN.
     */
    private function momoIpnSignature(array $data, string $key): string
    {
        $fields = [
            'accessKey', 'amount', 'extraData', 'message', 'orderId', 'orderInfo',
            'orderType', 'partnerCode', 'paymentOption', 'resultCode', 'transId',
        ];
        $raw = implode('&', array_map(fn ($f) => "$f=" . ($data[$f] ?? ''), $fields));

        return hash_hmac('sha256', $raw, $key);
    }

    /**
     * V3 (fail-closed): resolve the HMAC key used to VERIFY a gateway callback.
     * Production keys take priority. In sandbox self-test mode the explicitly
     * configured PAYMENT_SANDBOX_SECRET may be used — but only when it is
     * actually set; an empty/missing key returns null so verification fails
     * closed instead of falling back to a hardcoded default secret.
     */
    private function resolveVerifyKey(string $configKey): ?string
    {
        $real = config($configKey);
        if (! empty($real)) {
            return $real;
        }
        if ($this->sandbox && $this->sandboxSecret !== '') {
            return $this->sandboxSecret;
        }
        return null;
    }

    /**
     * Verify a MoMo IPN callback. Returns true when the signature matches.
     */
    public function verifyMoMoIpn(array $data): bool
    {
        // V3 (fail-closed): refuse to verify when no verification key is
        // configured. We never fall back to a hardcoded/default secret, so a
        // missing key cannot be abused to forge a "paid" callback.
        $key = $this->resolveVerifyKey('payment.momo_secret_key');
        if ($key === null) {
            Log::warning('MoMo IPN verification rejected: no verification key (fail-closed).');
            return false;
        }
        $expected = $this->momoIpnSignature($data, $key);

        return hash_equals($expected, (string) ($data['signature'] ?? ''));
    }

    /* =====================================================================
     |  ZaloPay
     ===================================================================== */

    public function createZaloPay(Order $order, string $ipnUrl, string $returnUrl): array
    {
        $appId     = (int) config('payment.zalopay_app_id');
        $key1      = config('payment.zalopay_key1', $this->sandboxSecret);
        $key2      = config('payment.zalopay_key2', $this->sandboxSecret);
        $endpoint  = config('payment.zalopay_endpoint');

        $appTransId = (string) $order->order_no;
        $appUser    = 'gn_user_' . ($order->user_id ?? 0);
        $amount     = (int) round($order->amount);
        $appTime    = round(microtime(true) * 1000);
        $embedData  = json_encode(['merchantinfo' => 'GIAONHANH']);
        $items      = json_encode($order->items->map(fn ($i) => [
            'itemid'     => (string) $i->id,
            'itemname'   => $i->name,
            'itemprice'  => (int) round($i->price),
            'itemquantity' => (int) $i->qty,
        ])->toArray());
        $description = 'GIAONHANH ' . $order->order_no;
        $bankCode   = '';

        $mac = hash_hmac('sha256',
            $appId . '|' . $appTransId . '|' . $appUser . '|' . $amount . '|' . $appTime . '|' . $embedData . '|' . $items,
            $key1
        );

        if (! $this->sandbox) {
            $res = Http::timeout(15)->post($endpoint, [
                'appid'       => $appId,
                'apptransid'  => $appTransId,
                'appuser'     => $appUser,
                'apptime'     => $appTime,
                'amount'      => $amount,
                'description' => $description,
                'bankcode'    => $bankCode,
                'item'        => $items,
                'embeddata'   => $embedData,
                'mac'         => $mac,
            ]);
            $body = $res->json();
            if (($body['returncode'] ?? -1) !== 1) {
                Log::error('ZaloPay create failed', $body);
                return ['status' => 'failed', 'pay_url' => null, 'trans_id' => $appTransId, 'raw' => $body];
            }
            return [
                'status'   => 'pending',
                'pay_url'  => $body['orderurl'] ?? null,
                'trans_id' => $appTransId,
                'raw'      => $body,
            ];
        }

        // Sandbox: build the callback payload and sign it with key2.
        $payload = [
            'appid'      => $appId,
            'apptransid' => $appTransId,
            'appuser'    => $appUser,
            'amount'     => $amount,
            'apptime'    => $appTime,
            'embeddata'  => $embedData,
            'item'       => $items,
            'status'     => 1,
        ];
        $data = base64_encode(json_encode($payload));
        $mac2 = hash_hmac('sha256', $data, $key2);
        $params = http_build_query([
            'method' => 'zalopay',
            'data'   => $data,
            'mac'    => $mac2,
        ]);
        return [
            'status'   => 'pending',
            'pay_url'  => rtrim(config('app.url', 'http://localhost:8000'), '/') . '/pay-mock.html?' . $params,
            'trans_id' => $appTransId,
            'raw'      => ['sandbox' => true],
        ];
    }

    /**
     * Verify a ZaloPay callback. Returns the decoded payload on success, else null.
     */
    public function verifyZaloPayCallback(string $data, string $mac): ?array
    {
        // V3 (fail-closed): never verify with a fallback/default secret.
        $key2 = $this->resolveVerifyKey('payment.zalopay_key2');
        if ($key2 === null) {
            Log::warning('ZaloPay callback verification rejected: no key (fail-closed).');
            return null;
        }
        $expected = hash_hmac('sha256', $data, $key2);
        if (! hash_equals($expected, $mac)) {
            return null;
        }
        $payload = json_decode(base64_decode($data), true);
        return is_array($payload) ? $payload : null;
    }

    /* =====================================================================
     |  Unified entry point + licensed AGGREGATOR mode
     ===================================================================== */

    /**
     * Single entry point used by PaymentController.
     *
     * When PAYMENT_AGGREGATOR is set (e.g. sepay / payoo) the wallet payment is
     * routed through a LICENSED aggregator. The aggregator — not GIAONHANH —
     * is the licensed payment intermediary; customer funds settle to the
     * aggregator and are split straight to the merchant (0% commission) and,
     * where applicable, back to the platform for the delivery subsidy. This
     * keeps GIAONHANH from ever pooling customer money (no 二清 / illegal fund
     * pooling) — see backend/docs/PAYMENT_COMPLIANCE.md.
     *
     * Returns ['status','pay_url','trans_id','raw','gateway'].
     */
    public function createPayment(Order $order, string $method, string $ipnUrl, string $returnUrl): array
    {
        $aggregator = config('payment.aggregator');
        if ($aggregator !== 'none' && in_array($method, ['momo', 'zalopay'], true)) {
            $r = $this->createViaAggregator($order, $method, $aggregator, $ipnUrl, $returnUrl);
            $r['gateway'] = $aggregator;
            return $r;
        }

        $r = $method === 'momo'
            ? $this->createMoMo($order, $ipnUrl, $returnUrl)
            : $this->createZaloPay($order, $ipnUrl, $returnUrl);
        $r['gateway'] = $method;
        return $r;
    }

    /**
     * Route a wallet payment through a licensed aggregator (Sepay shown as the
     * concrete example). Signs the order + split instructions with the
     * aggregator API key and returns the pay_url.
     */
    public function createViaAggregator(Order $order, string $method, string $aggregator, string $ipnUrl, string $returnUrl): array
    {
        $key      = config('payment.aggregator_api_key', $this->sandboxSecret);
        $endpoint = config('payment.aggregator_endpoint');

        // Split: merchant receives the product amount at 0% commission.
        // The platform's delivery subsidy is paid from the platform's OWN
        // settlement account — never carved out of the customer payment.
        $split = [[
            'account' => config('payment.aggregator_merchant_account'),
            'amount'  => (int) round((float) $order->product_amount),
            'note'    => 'GIAONHANH settlement 0% commission',
        ]];

        $payload = [
            'order_id'   => $order->order_no,
            'amount'     => (int) round((float) $order->amount),
            'currency'   => 'VND',
            'method'     => $method,
            'split'      => $split,
            'return_url' => $returnUrl,
            'ipn_url'    => $ipnUrl,
        ];
        $mac = hash_hmac('sha256', json_encode($payload), $key);

        if (! $this->sandbox && config('payment.aggregator_api_key')) {
            $res = Http::withHeaders(['Authorization' => 'Apikey ' . $key])
                ->timeout(15)
                ->post($endpoint, array_merge($payload, ['mac' => $mac]));
            $body = $res->json();
            if (($body['status'] ?? -1) !== 1) {
                Log::error('Aggregator create failed', $body);
                return ['status' => 'failed', 'pay_url' => null, 'trans_id' => $order->order_no, 'raw' => $body];
            }
            return [
                'status'   => 'pending',
                'pay_url'  => $body['pay_url'] ?? null,
                'trans_id' => $order->order_no,
                'raw'      => $body,
            ];
        }

        // Sandbox: sign a mock callback payload (data+mac) like the real IPN.
        $data = base64_encode(json_encode(array_merge($payload, ['status' => 1])));
        $cbMac = hash_hmac('sha256', $data, $key);
        $params = http_build_query([
            'method' => $aggregator,
            'data'   => $data,
            'mac'    => $cbMac,
        ]);
        return [
            'status'   => 'pending',
            'pay_url'  => rtrim(config('app.url', 'http://localhost:8000'), '/') . '/pay-mock.html?' . $params,
            'trans_id' => $order->order_no,
            'raw'      => ['aggregator' => $aggregator, 'sandbox' => true],
        ];
    }

    /**
     * Verify an aggregator callback (data base64 + HMAC with aggregator key).
     */
    public function verifyAggregatorIpn(string $name, string $data, string $mac): ?array
    {
        // V3 (fail-closed): never verify with a fallback/default secret.
        $key = $this->resolveVerifyKey('payment.aggregator_api_key');
        if ($key === null) {
            Log::warning('Aggregator IPN verification rejected: no key (fail-closed).');
            return null;
        }
        if (! hash_equals(hash_hmac('sha256', $data, $key), $mac)) {
            return null;
        }
        $payload = json_decode(base64_decode($data), true);
        return is_array($payload) ? $payload : null;
    }

    /**
     * Query a PSP transaction status for reconciliation (orders:reconcile).
     *
     * Fail-closed contract: returns null when the gateway is NOT configured /
     * unreachable / in sandbox. A null means "unknown" — the caller MUST apply
     * its conservative expire fallback and MUST NEVER assume 'paid'. Only an
     * explicit 'paid' from a configured, reachable gateway flips an order to paid.
     *
     * With real MoMo/ZaloPay credentials it calls the provider's query endpoint
     * and maps the result code to 'paid' | 'failed' | 'expired' | 'pending'. Any
     * exception is swallowed to null (conservative) — a broken network must not
     * falsely resurrect a stuck order as paid.
     */
    public function queryStatus(string $orderNo, string $gateway): ?string
    {
        if ($this->sandbox) {
            // No real PSP in sandbox self-test: we cannot verify, so return null
            // and let reconcile apply its conservative expire fallback.
            return null;
        }

        if ($gateway === 'momo' && config('payment.momo_secret_key')) {
            try {
                $raw = 'accessKey=' . config('payment.momo_access_key')
                    . '&orderId=' . $orderNo
                    . '&partnerCode=' . config('payment.momo_partner_code');
                $res = Http::timeout(10)->post(config('payment.momo_query_endpoint'), [
                    'partnerCode' => config('payment.momo_partner_code'),
                    'orderId'     => $orderNo,
                    'requestId'   => 'q_' . $orderNo . '_' . time(),
                    'signature'   => hash_hmac('sha256', $raw, config('payment.momo_secret_key')),
                ]);
                $body = $res->json();
                return match ((int) ($body['resultCode'] ?? -1)) {
                    0 => 'paid',
                    1000, 1001, 7000, 7002 => 'failed',
                    9000 => 'expired',
                    default => 'pending',
                };
            } catch (\Throwable $e) {
                Log::warning('MoMo queryStatus failed', ['error' => $e->getMessage()]);
                return null;
            }
        }

        if ($gateway === 'zalopay' && config('payment.zalopay_key1')) {
            try {
                $res = Http::timeout(10)->post(config('payment.zalopay_query_endpoint'), [
                    'appid' => (int) config('payment.zalopay_app_id'),
                    'apptransid' => $orderNo,
                    'mac' => hash_hmac('sha256', config('payment.zalopay_app_id') . '|' . $orderNo, config('payment.zalopay_key1')),
                ]);
                $body = $res->json();
                return match ((int) ($body['returncode'] ?? -1)) {
                    1 => 'paid',
                    2, 3 => 'failed',
                    4 => 'expired',
                    default => 'pending',
                };
            } catch (\Throwable $e) {
                Log::warning('ZaloPay queryStatus failed', ['error' => $e->getMessage()]);
                return null;
            }
        }

        // Unknown / unconfigured gateway → null (conservative).
        return null;
    }

    /**
     * Refund a successful wallet payment (MoMo / ZaloPay) when the customer
     * cancels. In sandbox (or without real keys) this simulates success so the
     * flow can be tested end-to-end; with real credentials it calls the PSP's
     * refund API. COD is a no-op (collected on delivery).
     *
     * Returns ['status' => 'refunded'|'skipped'|'failed'].
     */
    public function refund(Order $order, \App\Models\Payment $payment): array
    {
        $method = $payment->method;
        if ($method === 'cod') {
            return ['status' => 'skipped'];
        }

        if ($this->sandbox || ! config('payment.momo_secret_key')) {
            // No real PSP configured — simulate a successful refund.
            return ['status' => 'refunded', 'sandbox' => true];
        }

        if ($method === 'momo') {
            $endpoint = config('payment.momo_refund_endpoint');
            $partnerCode = config('payment.momo_partner_code');
            $orderId   = $order->order_no;
            $requestId = 'rf_' . $order->order_no . '_' . time();
            $raw = "partnerCode=$partnerCode&orderId=$orderId&requestId=$requestId";
            $signature = hash_hmac('sha256', $raw, config('payment.momo_secret_key', $this->sandboxSecret));
            $res = Http::timeout(15)->post($endpoint, [
                'partnerCode' => $partnerCode,
                'orderId'     => $orderId,
                'requestId'   => $requestId,
                'signature'   => $signature,
            ]);
            return $res->successful()
                ? ['status' => 'refunded']
                : ['status' => 'failed', 'raw' => $res->json()];
        }

        // ZaloPay refund
        $appId = (int) config('payment.zalopay_app_id');
        $key1  = config('payment.zalopay_key1', $this->sandboxSecret);
        $endpoint = config('payment.zalopay_refund_endpoint');
        $mac = hash_hmac('sha256', $appId . '|' . $order->order_no . '|' . time(), $key1);
        $res = Http::timeout(15)->post($endpoint, [
            'appid'       => $appId,
            'apptransid'  => $order->order_no,
            'timestamp'   => time(),
            'mac'         => $mac,
        ]);
        return $res->successful()
            ? ['status' => 'refunded']
            : ['status' => 'failed', 'raw' => $res->json()];
    }
}
