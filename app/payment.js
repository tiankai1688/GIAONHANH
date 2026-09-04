/* GIAONHANH payment service — real backend flow.
 *
 * pay({ method, amount, orderNo }) now delegates gateway creation to the
 * Laravel backend (PaymentGatewayService) so secrets never touch the client:
 *   - COD        -> backend marks paid synchronously (paid:true)
 *   - MoMo/ZaloPay -> backend returns pay_url; we open it (native deep link /
 *                     in-app browser) and the caller polls payment-status until
 *                     the gateway IPN confirms (paid:true).
 * When apiBase/useApi is off we fall back to an OFFLINE simulation so the
 * prototype's local demo keeps working.
 */
(function () {
  const GN = (window.GN = window.GN || {});

  // HMAC-SHA256 used only for the offline-fallback signature preview.
  async function hmacSHA256(key, msg) {
    const enc = new TextEncoder();
    const cryptoKey = await crypto.subtle.importKey(
      'raw', enc.encode(key),
      { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']
    );
    const sig = await crypto.subtle.sign('HMAC', cryptoKey, enc.encode(msg));
    return [...new Uint8Array(sig)]
      .map((b) => b.toString(16).padStart(2, '0')).join('');
  }
  GN.hmacSHA256 = hmacSHA256;

  /* Returns { ok, method, paid?, pending?, payUrl?, transId?, error? } */
  GN.pay = async function ({ method, amount, orderNo }) {
    if (method === 'cod') {
      return { ok: true, method: 'cod', paid: true };
    }

    const CFG = window.GN_CONFIG || {};
    if (CFG.useApi && CFG.apiBase) {
      const r = await GN.API.pay(orderNo, method);
      if (r && r.offline) return { ok: false, error: 'offline' };
      if (!r) return { ok: false, error: 'no_response' };
      if (r.pay_url) {
        await GN.native.openURL(r.pay_url);
        return { ok: true, method, pending: true, payUrl: r.pay_url, transId: r.trans_id };
      }
      if (r.status === 'paid') return { ok: true, method, paid: true };
      return { ok: false, error: 'no_pay_url' };
    }

    // ---- OFFLINE fallback (local demo) ----
    const fake = {
      momo:    { partnerCode: 'MOMO_PARTNER', orderId: orderNo, requestId: 'momo_' + Date.now() },
      zalopay: { appId: 2553, apptransid: orderNo, appuser: 'user_demo' },
    }[method] || {};
    if (method === 'momo') {
      await hmacSHA256('MOMO_SECRET', fake.partnerCode + fake.orderId + fake.requestId);
    } else if (method === 'zalopay') {
      await hmacSHA256('ZALOPAY_KEY1', fake.appId + '|' + fake.apptransid + '|' + fake.appuser);
    }
    await new Promise((r) => setTimeout(r, 1200));
    return { ok: true, method, pending: false, paid: true, offline: true };
  };

  /* Poll payment-status until paid or timeout. Used after opening a wallet. */
  GN.waitForPayment = async function (orderNo, { timeout = 120000, interval = 2000 } = {}) {
    const CFG = window.GN_CONFIG || {};
    if (!(CFG.useApi && CFG.apiBase)) {
      return { paid: true, simulated: true };
    }
    const start = Date.now();
    while (Date.now() - start < timeout) {
      const r = await GN.API.paymentStatus(orderNo);
      if (r && !r.offline && r.paid) return { paid: true, status: r.status };
      await new Promise((res) => setTimeout(res, interval));
    }
    return { paid: false, timeout: true };
  };
})();
