<?php
/**
 * GIAONHANH — IPN verification (standalone, NO framework dependency)
 * ---------------------------------------------------------------------------
 * Run on a machine with PHP (production smoke test before going live):
 *
 *     php backend/scripts/verify-ipn.php
 *
 * It re-implements the exact HMAC-SHA256 signing + verification used by
 * backend/app/Services/PaymentGatewayService.php and plays the full loop
 * (sign -> forge PSP callback -> verify). This proves the gateway math is
 * correct independently of Laravel so you can trust the integration before
 * pointing real MoMo / ZaloPay credentials at it.
 */

declare(strict_types=1);

$SECRET = getenv('PAYMENT_SANDBOX_SECRET') ?: 'GIAONHANH_SANDBOX_SECRET';

$failures = 0;
function assert_check(string $name, bool $cond): void
{
    global $failures;
    if ($cond) {
        echo "  \033[32m✓ PASS\033[0m  $name\n";
    } else {
        $failures++;
        echo "  \033[31m✗ FAIL\033[0m  $name\n";
    }
}

function hmac(string $raw, string $key): string
{
    return hash_hmac('sha256', $raw, $key);
}

$order = ['order_no' => 'GN20260715ABC123', 'amount' => 129000, 'ts' => 1752000000];

/* ============================ MoMo ======================================= */
echo "\n\033[1m[MoMo] Payment Gateway v2 — sign & verify IPN\033[0m\n";

$partnerCode = 'MOMO_PARTNER';
$accessKey   = 'MOMO_ACCESS';
$ipnUrl      = 'https://api.giaonhanh.vn/api/payments/momo/ipn';
$returnUrl   = 'https://app.giaonhanh.vn/pay/result';

$orderId   = $order['order_no'];
$requestId = 'momo_' . $order['order_no'] . '_' . $order['ts'];
$amount    = (int) round($order['amount']);
$orderInfo = 'GIAONHANH ' . $order['order_no'];
$extraData = base64_encode(json_encode(['order_no' => $order['order_no']]));
$requestType = 'payWithMethod';

$momoRaw = "accessKey=$accessKey&amount=$amount&extraData=$extraData&ipnUrl=$ipnUrl"
    . "&orderId=$orderId&orderInfo=$orderInfo&partnerCode=$partnerCode"
    . "&redirectUrl=$returnUrl&requestId=$requestId&requestType=$requestType";
$momoSignature = hmac($momoRaw, $SECRET);

assert_check('sign produced 64-hex HMAC', (bool) preg_match('/^[0-9a-f]{64}$/', $momoSignature));

$momoIpn = [
    'partnerCode'   => $partnerCode,
    'accessKey'     => $accessKey,
    'amount'        => $amount,
    'orderId'       => $orderId,
    'orderInfo'     => $orderInfo,
    'orderType'     => 'momo_wallet',
    'transId'       => 'MOMO' . $order['ts'],
    'resultCode'    => 0,
    'message'       => 'Success',
    'extraData'     => $extraData,
    'paymentOption' => 'MOMO_WALLET',
    'signature'     => $momoSignature,
];

$momoFields = ['accessKey', 'amount', 'extraData', 'message', 'orderId', 'orderInfo',
    'orderType', 'partnerCode', 'paymentOption', 'resultCode', 'transId'];
$momoVerifyRaw = implode('&', array_map(fn ($f) => "$f=" . ($momoIpn[$f] ?? ''), $momoFields));
assert_check('IPN signature verifies (resultCode=0)', hmac($momoVerifyRaw, $SECRET) === $momoIpn['signature']);

$tampered = $momoIpn;
$tampered['amount'] = $amount + 1;
$tamperedRaw = implode('&', array_map(fn ($f) => "$f=" . ($tampered[$f] ?? ''), $momoFields));
assert_check('tampered amount rejected', hmac($tamperedRaw, $SECRET) !== $tampered['signature']);

/* ========================== ZaloPay ====================================== */
echo "\n\033[1m[ZaloPay] create-order + callback verify\033[0m\n";

$key1 = $SECRET;
$key2 = $SECRET;
$appId = 2553;

$appTransId = (string) $order['order_no'];
$appUser    = 'gn_user_2';
$amount     = (int) round($order['amount']);
$appTime    = $order['ts'] * 1000;
$embedData  = json_encode(['merchantinfo' => 'GIAONHANH']);
$items      = json_encode([['itemid' => '1', 'itemname' => 'demo', 'itemprice' => $amount, 'itemquantity' => 1]]);

$zaloMac = hmac("$appId|$appTransId|$appUser|$amount|$appTime|$embedData|$items", $key1);
assert_check('create-order mac produced 64-hex HMAC', (bool) preg_match('/^[0-9a-f]{64}$/', $zaloMac));

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
$dataB64 = base64_encode(json_encode($payload));
$cbMac = hmac($dataB64, $key2);
assert_check('callback (data+mac) verifies', hmac($dataB64, $key2) === $cbMac);
assert_check('tampered callback mac rejected', hmac($dataB64, $key2) !== substr($cbMac, 0, -2) . 'ff');

/* =========================== Verdict ===================================== */
echo "\n\033[1m" . ($failures === 0
    ? "\033[32mALL CHECKS PASSED — IPN signing/verification is correct.\033[0m"
    : "\033[31m$failures CHECK(S) FAILED.\033[0m") . "\033[0m\n";

exit($failures === 0 ? 0 : 1);
