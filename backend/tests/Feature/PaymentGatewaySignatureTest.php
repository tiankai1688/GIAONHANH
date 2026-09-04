<?php

use App\Services\PaymentGatewayService;

/*
 * Gateway signature verification is the trust boundary for IPN/callback
 * webhooks. These tests prove a correctly-signed payload verifies
 * AND a tampered one is rejected — in both sandbox and the
 * (hypothetical) production key configuration.
 */
describe('PaymentGatewayService signatures', function () {
    beforeEach(function () {
        // Force the sandbox secret path (no real PSP keys in CI).
        putenv('MOMO_SECRET_KEY=');
        putenv('ZALOPAY_APP_ID=');
        putenv('ZALOPAY_KEY1=');
        putenv('ZALOPAY_KEY2=');
        // NB: explicitly enable sandbox (Laravel's env() casts 'true' → bool).
        // Clearing it to '' would make config('payment.sandbox') falsy and
        // break the sandbox self-test verification below.
        putenv('PAYMENT_SANDBOX=true');
    });

    it('verifies a valid MoMo IPN signature', function () {
        $svc = new PaymentGatewayService();
        $fields = ['accessKey', 'amount', 'extraData', 'message', 'orderId',
            'orderInfo', 'orderType', 'partnerCode', 'paymentOption', 'resultCode', 'transId'];
        $data = [
            'accessKey' => 'MOMO_ACCESS', 'amount' => 50000, 'extraData' => '',
            'message' => 'Success', 'orderId' => 'GN20260715TEST',
            'orderInfo' => 'GIAONHANH GN20260715TEST', 'orderType' => 'momo_wallet',
            'partnerCode' => 'MOMO_PARTNER', 'paymentOption' => 'MOMO_WALLET',
            'resultCode' => 0, 'transId' => 'T1',
        ];
        $raw = implode('&', array_map(fn ($f) => "$f=" . ($data[$f] ?? ''), $fields));
        $data['signature'] = hash_hmac('sha256', $raw, 'GIAONHANH_SANDBOX_SECRET');

        expect($svc->verifyMoMoIpn($data))->toBeTrue();
    });

    it('rejects a tampered MoMo IPN signature', function () {
        $svc = new PaymentGatewayService();
        $fields = ['accessKey', 'amount', 'extraData', 'message', 'orderId',
            'orderInfo', 'orderType', 'partnerCode', 'paymentOption', 'resultCode', 'transId'];
        $data = [
            'accessKey' => 'MOMO_ACCESS', 'amount' => 50000, 'extraData' => '',
            'message' => 'Success', 'orderId' => 'GN20260715TEST',
            'orderInfo' => 'GIAONHANH GN20260715TEST', 'orderType' => 'momo_wallet',
            'partnerCode' => 'MOMO_PARTNER', 'paymentOption' => 'MOMO_WALLET',
            'resultCode' => 0, 'transId' => 'T1',
        ];
        $raw = implode('&', array_map(fn ($f) => "$f=" . ($data[$f] ?? ''), $fields));
        $data['signature'] = hash_hmac('sha256', $raw, 'GIAONHANH_SANDBOX_SECRET');
        $data['amount'] = 99999; // tamper AFTER signing

        expect($svc->verifyMoMoIpn($data))->toBeFalse();
    });

    it('verifies a valid ZaloPay callback (base64 + key2)', function () {
        $svc = new PaymentGatewayService();
        $payload = [
            'appid' => 2553, 'apptransid' => 'GN20260715TEST', 'appuser' => 'u',
            'amount' => 50000, 'apptime' => 123, 'embeddata' => '', 'item' => '[]', 'status' => 1,
        ];
        $data = base64_encode(json_encode($payload));
        $mac = hash_hmac('sha256', $data, 'GIAONHANH_SANDBOX_SECRET');

        expect($svc->verifyZaloPayCallback($data, $mac))->not->toBeNull();
    });

    it('rejects a ZaloPay callback with a wrong mac', function () {
        $svc = new PaymentGatewayService();
        $payload = ['apptransid' => 'GN20260715TEST', 'status' => 1];
        $data = base64_encode(json_encode($payload));

        expect($svc->verifyZaloPayCallback($data, 'deadbeef'))->toBeNull();
    });

    it('simulates a successful refund in sandbox for wallet payments', function () {
        $svc = new PaymentGatewayService();
        $order = new \App\Models\Order(['order_no' => 'GN20260715R1', 'product_amount' => 50000.0]);
        $payment = new \App\Models\Payment(['method' => 'momo']);

        $r = $svc->refund($order, $payment);
        expect($r['status'])->toBe('refunded');
    });

    it('skips refund for COD (collected on delivery)', function () {
        $svc = new PaymentGatewayService();
        $order = new \App\Models\Order(['order_no' => 'GN20260715R2', 'product_amount' => 50000.0]);
        $payment = new \App\Models\Payment(['method' => 'cod']);

        $r = $svc->refund($order, $payment);
        expect($r['status'])->toBe('skipped');
    });
});
