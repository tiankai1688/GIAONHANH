<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Payment;
use App\Services\PaymentGatewayInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    /**
     * Initiate payment for an order.
     *  - COD: marked paid synchronously (rider assigned via the grab model, see RiderController).
     *  - MoMo / ZaloPay: a gateway payment is created (pending) and the
     *    wallet pay_url is returned. The order flips to `paid` only when the
     *    gateway IPN/callback confirms it.
     */
    public function pay(Request $request, Order $order)
    {
        $this->authorizeOrder($request, $order);
        if ($order->status !== 'pending_payment') {
            return response()->json(['message' => 'Đơn không ở trạng thái chờ thanh toán.'], 422);
        }
        $method = $request->input('method', 'cod');
        if (! in_array($method, ['cod', 'momo', 'zalopay'])) {
            return response()->json(['message' => 'Phương thức không hợp lệ.'], 422);
        }

        // PSP acquirer fee — closes the unit-economics blind spot (red-team boss
        // #1). COD carries no gateway fee; wallet methods are charged
        // amount * psp_fee_rate. Recorded on the order + payment so the cost is
        // ALWAYS visible (never silently absorbed into an unknown loss).
        $pspFee = $method === 'cod'
            ? 0.0
            : round((float) $order->amount * (float) config('payment.psp_fee_rate', 0.025), 2);
        $pspBearer = config('payment.psp_fee_bearer', 'platform');

        $gateway = app(PaymentGatewayInterface::class);

        // Idempotency + serialisation: reserve a single Payment row for this
        // order under a row lock so concurrent re-clicks cannot create two
        // charges (double-charge bug). Reuses an existing pending/failed row so
        // a failed attempt can be retried without duplicating the charge.
        $reservation = DB::transaction(function () use ($order, $method, $pspFee, $pspBearer) {
            $locked  = Order::where('order_no', $order->order_no)->lockForUpdate()->first();
            $payment = Payment::where('order_id', $locked->id)->first();
            if (! $payment) {
                $payment = Payment::create([
                    'order_id' => $locked->id,
                    'method'   => $method,
                    'amount'   => $locked->amount,
                    'psp_fee'  => $pspFee,
                    'status'   => 'pending',
                ]);
            }
            // Record the PSP fee on the order exactly once (idempotent).
            if ($locked->psp_fee === null || (float) $locked->psp_fee == 0.0) {
                $locked->update(['psp_fee' => $pspFee, 'psp_fee_bearer' => $pspBearer]);
            }
            return ['payment' => $payment, 'order' => $locked];
        });

        $payment     = $reservation['payment'];
        $lockedOrder = $reservation['order'];

        // Already paid — fully idempotent success, never re-charges.
        if ($payment->status === 'success') {
            return response()->json([
                'order_no' => $lockedOrder->order_no,
                'method'   => $payment->method,
                'pay_url'  => $payment->pay_url,
                'trans_id' => $payment->trans_id,
                'status'   => 'paid',
            ], 200);
        }

        // ---- COD: collected on delivery, confirm synchronously (local only) ----
        if ($method === 'cod') {
            DB::transaction(function () use ($payment, $lockedOrder) {
                $payment->update(['status' => 'success', 'paid_at' => now()]);
                $lockedOrder->update(['status' => 'paid', 'paid_at' => now()]);
                event(new \App\Events\OrderPaid($lockedOrder));
            });
            return response()->json([
                'order_no' => $lockedOrder->order_no,
                'method'   => 'cod',
                'pay_url'  => null,
                'trans_id' => null,
                'status'   => 'paid',
            ], 200);
        }

        // ---- Wallet (MoMo / ZaloPay / aggregator): call the gateway OUTSIDE the
        // DB transaction so a slow/hanging PSP cannot hold DB connections. Any
        // timeout or transport error is caught and surfaced as a clean 502. ----
        $aggregator  = config('payment.aggregator');
        $viaAggregator = $aggregator !== 'none' && in_array($method, ['momo', 'zalopay'], true);
        $callbackPath = $viaAggregator
            ? 'aggregator/' . $aggregator . '/callback'
            : ($method === 'momo' ? 'momo/ipn' : 'zalopay/callback');
        $ipnUrl    = rtrim(config('app.url', 'http://localhost:8000'), '/') . '/api/payments/' . $callbackPath;
        $returnUrl = rtrim(config('app.url', 'http://localhost:8000'), '/') . '/pay-mock.html';

        try {
            $result = $gateway->createPayment($lockedOrder, $method, $ipnUrl, $returnUrl);
        } catch (\Throwable $e) {
            Log::error('Payment gateway call failed', [
                'order' => $lockedOrder->order_no,
                'error' => $e->getMessage(),
            ]);
            $payment->update(['status' => 'failed']);
            return response()->json(['message' => 'Cổng thanh toán tạm lỗi, vui lòng thử lại.'], 502);
        }

        $payment->update([
            'status'           => $result['status'],
            'gateway'          => $result['gateway'] ?? $method,
            'gateway_order_id' => $result['trans_id'],
            'pay_url'          => $result['pay_url'],
            'raw'              => $result['raw'] ?? null,
        ]);

        return response()->json([
            'order_no' => $lockedOrder->order_no,
            'method'   => $method,
            'pay_url'  => $result['pay_url'],
            'trans_id' => $result['trans_id'],
            'status'   => $result['status'],
        ], 200);
    }

    /**
     * MoMo IPN (public). Verifies the signature, then marks the payment/order
     * paid. Rider assignment uses the grab model (see RiderController) — the
     * order is broadcast on orders.grab for nearby riders. Responds with
     * resultCode 0 so MoMo stops retrying.
     */
    public function momoIpn(Request $request)
    {
        $gateway = app(PaymentGatewayInterface::class);
        if (! $gateway->verifyMoMoIpn($request->all())) {
            return response()->json([
                'partnerCode' => $request->input('partnerCode'),
                'orderId'     => $request->input('orderId'),
                'requestId'   => $request->input('requestId'),
                'resultCode'  => 99,
                'message'     => 'Signature invalid',
            ]);
        }

        $orderNo = (string) $request->input('orderId');
        $order   = Order::where('order_no', $orderNo)->first();
        $payment = $order?->payment;
        if (! $order || ! $payment) {
            return response()->json([
                'partnerCode' => $request->input('partnerCode'),
                'orderId'     => $orderNo,
                'requestId'   => $request->input('requestId'),
                'resultCode'  => 99,
                'message'     => 'Order not found',
            ]);
        }

        // V3-④ Bind extraData.order_no to orderId to prevent order-swapping
        // (replaying a valid signature against a different order).
        $extra = $request->input('extraData');
        if (! empty($extra)) {
            $decoded = json_decode(base64_decode((string) $extra), true);
            if (! is_array($decoded) || ($decoded['order_no'] ?? null) !== $orderNo) {
                Log::warning('MoMo IPN extraData/orderId mismatch', ['order_no' => $orderNo]);
                return response()->json([
                    'partnerCode' => $request->input('partnerCode'),
                    'orderId'     => $orderNo,
                    'requestId'   => $request->input('requestId'),
                    'resultCode'  => 99,
                    'message'     => 'Invalid extraData',
                ]);
            }
        }

        // V3-② Amount must equal the order total (defense in depth against a
        // tampered/partial callback amount).
        if ((int) $request->input('amount') !== (int) round((float) $order->amount)) {
            Log::warning('MoMo IPN amount mismatch', [
                'order'        => $orderNo,
                'ipn_amount'   => $request->input('amount'),
                'order_amount' => $order->amount,
            ]);
            return response()->json([
                'partnerCode' => $request->input('partnerCode'),
                'orderId'     => $orderNo,
                'requestId'   => $request->input('requestId'),
                'resultCode'  => 99,
                'message'     => 'Amount mismatch',
            ]);
        }

        // V3-③ Replay protection: ignore duplicate callbacks for the same gateway
        // requestId (idempotent success, no double side-effects / double events).
        if ($this->isDuplicateIpn('momo', (string) $request->input('requestId'))) {
            return response()->json([
                'partnerCode' => $request->input('partnerCode'),
                'orderId'     => $orderNo,
                'requestId'   => $request->input('requestId'),
                'resultCode'  => 0,
                'message'     => 'Confirm Success',
            ]);
        }

        if ((int) $request->input('resultCode') === 0) {
            if ($payment->status !== 'success') {
                $payment->update(['status' => 'success', 'paid_at' => now(), 'raw' => $request->all()]);
                $this->markOrderPaid($order);
                event(new \App\Events\OrderPaid($order));
            }
            return response()->json([
                'partnerCode' => $request->input('partnerCode'),
                'orderId'     => $orderNo,
                'requestId'   => $request->input('requestId'),
                'resultCode'  => 0,
                'message'     => 'Confirm Success',
            ]);
        }

        $payment->update(['status' => 'failed', 'raw' => $request->all()]);
        return response()->json([
            'partnerCode' => $request->input('partnerCode'),
            'orderId'     => $orderNo,
            'requestId'   => $request->input('requestId'),
            'resultCode'  => (int) $request->input('resultCode'),
            'message'     => $request->input('message'),
        ]);
    }

    /**
     * ZaloPay callback (public). Data is base64 + HMAC(key2) signed.
     */
    public function zaloPayCallback(Request $request)
    {
        $gateway = app(PaymentGatewayInterface::class);
        $payload = $gateway->verifyZaloPayCallback(
            (string) $request->input('data'),
            (string) $request->input('mac')
        );
        if ($payload === null) {
            return response()->json(['return_code' => 0, 'return_message' => 'mac not match']);
        }

        $order   = Order::where('order_no', $payload['apptransid'])->first();
        $payment = $order?->payment;
        if (! $order || ! $payment) {
            return response()->json(['return_code' => 0, 'return_message' => 'order not found']);
        }

        // V3-④ Bind: the resolved order must equal the callback's apptransid.
        if (($payload['apptransid'] ?? null) !== $order->order_no) {
            Log::warning('ZaloPay callback apptransid mismatch', ['order' => $order->order_no]);
            return response()->json(['return_code' => 0, 'return_message' => 'invalid order']);
        }
        // V3-② Amount must equal the order total.
        if ((int) ($payload['amount'] ?? 0) !== (int) round((float) $order->amount)) {
            Log::warning('ZaloPay callback amount mismatch', ['order' => $order->order_no]);
            return response()->json(['return_code' => 0, 'return_message' => 'amount mismatch']);
        }
        // V3-③ Replay protection by apptransid.
        if ($this->isDuplicateIpn('zalopay', (string) ($payload['apptransid'] ?? ''))) {
            return response()->json(['return_code' => 1, 'return_message' => 'success']);
        }

        if ((int) ($payload['status'] ?? 1) === 1) {
            if ($payment->status !== 'success') {
                $payment->update(['status' => 'success', 'paid_at' => now(), 'raw' => $payload]);
                $this->markOrderPaid($order);
                event(new \App\Events\OrderPaid($order));
            }
            return response()->json(['return_code' => 1, 'return_message' => 'success']);
        }

        $payment->update(['status' => 'failed', 'raw' => $payload]);
        return response()->json(['return_code' => 0, 'return_message' => 'failed']);
    }

    /**
     * Licensed-aggregator callback (public, signature-verified). Used when
     * PAYMENT_AGGREGATOR is set (sepay / payoo). The aggregator is the licensed
     * intermediary; we only verify the mac and settle.
     */
    public function aggregatorCallback(Request $request, string $name)
    {
        $gateway = app(PaymentGatewayInterface::class);
        $payload = $gateway->verifyAggregatorIpn(
            $name,
            (string) $request->input('data'),
            (string) $request->input('mac')
        );
        if ($payload === null) {
            return response()->json(['return_code' => 0, 'return_message' => 'mac not match']);
        }

        $order   = Order::where('order_no', $payload['order_id'] ?? null)->first();
        $payment = $order?->payment;
        if (! $order || ! $payment) {
            return response()->json(['return_code' => 0, 'return_message' => 'order not found']);
        }

        // V3-④ Bind: the resolved order must equal the callback's order_id.
        if (($payload['order_id'] ?? null) !== $order->order_no) {
            Log::warning('Aggregator callback order_id mismatch', ['order' => $order->order_no]);
            return response()->json(['return_code' => 0, 'return_message' => 'invalid order']);
        }
        // V3-② Amount must equal the order total.
        if ((int) ($payload['amount'] ?? 0) !== (int) round((float) $order->amount)) {
            Log::warning('Aggregator callback amount mismatch', ['order' => $order->order_no]);
            return response()->json(['return_code' => 0, 'return_message' => 'amount mismatch']);
        }
        // V3-③ Replay protection by order_id (+ gateway name channel).
        if ($this->isDuplicateIpn('aggregator_' . $name, (string) ($payload['order_id'] ?? ''))) {
            return response()->json(['return_code' => 1, 'return_message' => 'success']);
        }

        if ((int) ($payload['status'] ?? 1) === 1) {
            if ($payment->status !== 'success') {
                $payment->update(['status' => 'success', 'paid_at' => now(), 'raw' => $payload]);
                $this->markOrderPaid($order);
                event(new \App\Events\OrderPaid($order));
            }
            return response()->json(['return_code' => 1, 'return_message' => 'success']);
        }

        $payment->update(['status' => 'failed', 'raw' => $payload]);
        return response()->json(['return_code' => 0, 'return_message' => 'failed']);
    }

    /**
     * Polling endpoint for the client to learn when the gateway IPN has
     * confirmed payment (used after opening the wallet).
     */
    public function status(Request $request, Order $order)
    {
        $this->authorizeOrder($request, $order);
        return response()->json([
            'order_no' => $order->order_no,
            'status'   => $order->status,
            'paid'     => in_array($order->status, ['paid', 'accepted', 'picked', 'delivering', 'delivered']),
            'payment'  => [
                'method' => $order->payment?->method,
                'status' => $order->payment?->status,
            ],
        ]);
    }

    /**
     * Mark an order paid AND cascade the paid state to its merged sub-orders.
     *
     * Red-team FATAL A: a cross-store merged order has a `type=merged` PARENT
     * (no merchant_id, never accepted by a merchant) plus one `type=sub` CHILD
     * per merchant (each with its own merchant_id). The gateway IPN arrives for
     * the parent's order_no only. If we stop at the parent, every child stays
     * `pending_payment` forever and MerchantController::acceptOrder's
     * `status==='paid'` guard rejects it — the merged-order feature is 100%
     * broken. So we also flip all `pending_payment` children to `paid`.
     */
    private function markOrderPaid(Order $order): void
    {
        $order->update(['status' => 'paid', 'paid_at' => now()]);

        if ($order->type === 'merged') {
            $order->subOrders()
                ->where('status', 'pending_payment')
                ->update(['status' => 'paid', 'paid_at' => now()]);
        }
    }

    /**
     * V3-③ Replay protection. Returns true if this gateway callback (identified
     * by its transaction id) has already been seen. Uses a cache key with a 24h
     * TTL so the same callback cannot be processed twice. The gateway transaction
     * id is the only reliable unique marker across retries.
     */
    private function isDuplicateIpn(string $channel, string $txnId): bool
    {
        if ($txnId === '') {
            return false; // cannot dedup without an id; rely on status guard
        }
        $key = 'ipn:' . $channel . ':' . $txnId;
        // Cache::add returns true only when the key was newly written.
        return ! Cache::add($key, true, now()->addHours(24));
    }
}
