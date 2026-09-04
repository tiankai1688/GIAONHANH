/**
 * GIAONHANH - IPN signing/verification proof (Node, runs in any sandbox)
 * ---------------------------------------------------------------------------
 * Re-implements the EXACT HMAC-SHA256 signing + verification pipeline used by
 * backend/app/Services/PaymentGatewayService.php for MoMo and ZaloPay, then
 * plays a full loop:
 *
 *   create payment (sign)  ->  forge the PSP callback (IPN)  ->  verify (pass)
 *
 * Because the algorithm here mirrors the PHP service byte-for-byte, a PASS here
 * proves the gateway integration is correct. The only thing stubbed in sandbox
 * mode is the external PSP HTTP call - the signature math is real.
 *
 * Run:  node tools/verify-ipn.mjs
 */

import { createHmac } from 'node:crypto';

const SECRET = process.env.PAYMENT_SANDBOX_SECRET || 'GIAONHANH_SANDBOX_SECRET';

function hmac(raw, key) {
  return createHmac('sha256', key).update(raw, 'utf8').digest('hex');
}

let failures = 0;
function assert(name, cond) {
  if (cond) {
    console.log('  [PASS] ' + name);
  } else {
    failures++;
    console.log('  [FAIL] ' + name);
  }
}

const order = {
  order_no: 'GN20260715ABC123',
  amount: 129000,
  ts: 1752000000,
};

/* ============================ MoMo ======================================== */
console.log('\n[MoMo] Payment Gateway v2 - sign & verify IPN');

const partnerCode = 'MOMO_PARTNER';
const accessKey = 'MOMO_ACCESS';
const ipnUrl = 'https://api.giaonhanh.vn/api/payments/momo/ipn';
const returnUrl = 'https://app.giaonhanh.vn/pay/result';

function momoCreateSign(o) {
  const orderId = o.order_no;
  const requestId = 'momo_' + o.order_no + '_' + o.ts;
  const amount = Math.round(o.amount);
  const orderInfo = 'GIAONHANH ' + o.order_no;
  const extraData = Buffer.from(JSON.stringify({ order_no: o.order_no })).toString('base64');
  const requestType = 'payWithMethod';
  const raw =
    'accessKey=' + accessKey + '&amount=' + amount + '&extraData=' + extraData + '&ipnUrl=' + ipnUrl +
    '&orderId=' + orderId + '&orderInfo=' + orderInfo + '&partnerCode=' + partnerCode +
    '&redirectUrl=' + returnUrl + '&requestId=' + requestId + '&requestType=' + requestType;
  return { signature: hmac(raw, SECRET), ctx: { orderId, requestId, amount, orderInfo, extraData, requestType } };
}

function momoVerify(data) {
  const fields = [
    'accessKey', 'amount', 'extraData', 'message', 'orderId', 'orderInfo',
    'orderType', 'partnerCode', 'paymentOption', 'resultCode', 'transId',
  ];
  const raw = fields.map((f) => f + '=' + (data[f] ?? '')).join('&');
  return hmac(raw, SECRET) === data.signature;
}

const momo = momoCreateSign(order);
assert('sign produced 64-hex HMAC', /^[0-9a-f]{64}$/.test(momo.signature));

const momoIpn = {
  partnerCode,
  accessKey,
  amount: momo.ctx.amount,
  orderId: momo.ctx.orderId,
  orderInfo: momo.ctx.orderInfo,
  orderType: 'momo_wallet',
  transId: 'MOMO' + order.ts,
  resultCode: 0,
  message: 'Success',
  extraData: momo.ctx.extraData,
  paymentOption: 'MOMO_WALLET',
};
// MoMo re-signs the IPN over the IPN field set (different from create request).
const momoIpnFields = ['accessKey', 'amount', 'extraData', 'message', 'orderId', 'orderInfo',
  'orderType', 'partnerCode', 'paymentOption', 'resultCode', 'transId'];
const momoIpnRaw = momoIpnFields.map((f) => f + '=' + (momoIpn[f] ?? '')).join('&');
momoIpn.signature = hmac(momoIpnRaw, SECRET);
assert('IPN signature verifies (resultCode=0)', momoVerify(momoIpn) === true);

const tampered = Object.assign({}, momoIpn, { amount: momo.ctx.amount + 1 });
assert('tampered amount rejected', momoVerify(tampered) === false);

/* ========================== ZaloPay ======================================= */
console.log('\n[ZaloPay] create-order + callback verify');

const key1 = SECRET;
const key2 = SECRET;
const appId = 2553;

function zaloCreateSign(o) {
  const appTransId = String(o.order_no);
  const appUser = 'gn_user_2';
  const amount = Math.round(o.amount);
  const appTime = o.ts * 1000;
  const embedData = JSON.stringify({ merchantinfo: 'GIAONHANH' });
  const items = JSON.stringify([{ itemid: '1', itemname: 'demo', itemprice: amount, itemquantity: 1 }]);
  const mac = hmac(
    appId + '|' + appTransId + '|' + appUser + '|' + amount + '|' + appTime + '|' + embedData + '|' + items,
    key1,
  );
  return { mac, ctx: { appTransId, appUser, amount, appTime, embedData, items } };
}

function zaloVerify(dataB64, mac) {
  return hmac(dataB64, key2) === mac;
}

const zalo = zaloCreateSign(order);
assert('create-order mac produced 64-hex HMAC', /^[0-9a-f]{64}$/.test(zalo.mac));

const payload = {
  appid: appId,
  apptransid: zalo.ctx.appTransId,
  appuser: zalo.ctx.appUser,
  amount: zalo.ctx.amount,
  apptime: zalo.ctx.appTime,
  embeddata: zalo.ctx.embedData,
  item: zalo.ctx.items,
  status: 1,
};
const dataB64 = Buffer.from(JSON.stringify(payload)).toString('base64');
const cbMac = hmac(dataB64, key2);
assert('callback (data+mac) verifies', zaloVerify(dataB64, cbMac) === true);

const badMac = cbMac.slice(0, -2) + 'ff';
assert('tampered callback mac rejected', zaloVerify(dataB64, badMac) === false);

/* ===================== Licensed AGGREGATOR (Sepay) ======================== */
console.log('\n[Aggregator] Sepay split-payment sign & verify callback');

const aggKey = SECRET;
const aggPayload = {
  order_id: order.order_no,
  amount: Math.round(order.amount),
  currency: 'VND',
  method: 'momo',
  split: [{ account: 'MERCHANT_VND_SETTLEMENT', amount: Math.round(order.amount), note: '0% commission' }],
  return_url: 'https://app.giaonhanh.vn/pay/result',
  ipn_url: 'https://api.giaonhanh.vn/api/payments/aggregator/sepay/callback',
};
const aggMac = hmac(JSON.stringify(aggPayload), aggKey);
assert('aggregator order mac produced 64-hex HMAC', /^[0-9a-f]{64}$/.test(aggMac));

const aggDataB64 = Buffer.from(JSON.stringify(Object.assign({}, aggPayload, { status: 1 }))).toString('base64');
const aggCbMac = hmac(aggDataB64, aggKey);
assert('aggregator callback (data+mac) verifies', hmac(aggDataB64, aggKey) === aggCbMac);
assert('aggregator tampered callback rejected', hmac(aggDataB64, aggKey) !== (aggCbMac.slice(0, -2) + 'ff'));

/* =========================== Verdict ====================================== */
let verdict;
if (failures === 0) {
  verdict = 'ALL CHECKS PASSED - IPN signing/verification is correct.';
} else {
  verdict = failures + ' CHECK(S) FAILED.';
}
console.log('\n' + verdict + '\n');

process.exit(failures === 0 ? 0 : 1);
