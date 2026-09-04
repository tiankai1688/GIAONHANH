/* GIAONHANH API client
 * Talks to the Laravel backend (backend/). When window.GN_CONFIG.apiBase is
 * empty (default), every call returns { offline:true } and the prototype keeps
 * its localStorage demo behavior. Set apiBase + useApi:true to go live.
 */
(function () {
  const GN = (window.GN = window.GN || {});
  const CFG = window.GN_CONFIG || {};
  const BASE = CFG.apiBase || '';
  GN.token = GN.token || null;
  // SECURITY (senior-review-2 fix 3.4): the refresh token is issued by the
  // backend as an HttpOnly cookie, so the SPA never holds it in JS memory or
  // storage. Only the short-lived access token is kept client-side.

  // HTML-escape untrusted strings before injecting into innerHTML (XSS defense).
  // Backend-supplied text (store/product names, customer name/address/note) is
  // attacker-influenceable and MUST be escaped wherever it lands in markup.
  GN.esc = function (s) {
    if (s == null) return '';
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };
  window.esc = GN.esc; // short alias usable inside all app templates

  // SECURITY (XSS strictification, 2026-08-01): inline event-handler
  // attributes (onclick="...") were removed from markup during the CSP
  // hardening and replaced with data-action="fnName" (+ optional
  // data-args='[...]'). This single delegated listener dispatches them so the
  // SPA can run under a strict CSP (script-src WITHOUT 'unsafe-inline').
  // It is defense-in-depth on top of GN.esc() (which blocks data-reflection
  // XSS into innerHTML). The listener only reacts to [data-action] elements,
  // so it never interferes with the existing .onclick = handlers or other
  // document-level click listeners (e.g. native-bridge.js).
  document.addEventListener('click', function (e) {
    const el = e.target.closest('[data-action]');
    if (!el) return;
    const name = el.getAttribute('data-action');
    const fn = window[name];
    if (typeof fn !== 'function') {
      if (window.console) console.warn('[GN] data-action 未定义:', name);
      return;
    }
    let args = [];
    const raw = el.getAttribute('data-args');
    if (raw) {
      try { args = JSON.parse(raw); } catch (_) { args = []; }
    }
    fn.apply(el, args);
  });

  // Clipboard copy helper — replaces the inline onclick clipboard call.
  GN.copyOrderNo = window.copyOrderNo = function (text) {
    if (navigator.clipboard) navigator.clipboard.writeText(text);
  };

  // Restore persisted session (access token) across reloads.
  // SECURITY: use sessionStorage (NOT localStorage) for the access token.
  // sessionStorage is cleared when the tab closes, shrinking the XSS read
  // window, whereas localStorage persists indefinitely and is readable by any
  // injected script. The refresh token is an HttpOnly cookie managed by the
  // backend and is intentionally NOT persisted here (XSS cannot read it).
  if (window.sessionStorage) {
    GN.token = sessionStorage.getItem('gn_token') || GN.token;
  }

  // Persist ONLY the access token. The refresh token is an HttpOnly cookie set
  // by the backend (on login/refresh) and expired on logout/delete — it is
  // intentionally NOT stored here.
  GN.setAuth = function (data) {
    if (!data) return;
    if (data.token) GN.token = data.token;
    if (window.sessionStorage && GN.token) sessionStorage.setItem('gn_token', GN.token);
    if (GN.onAuth) GN.onAuth(data);
  };

  // Clear the local access token AND ask the backend to expire the HttpOnly
  // refresh cookie (JS cannot clear an HttpOnly cookie directly).
  GN.clearAuth = function () {
    const tok = GN.token;
    GN.token = null;
    if (window.sessionStorage) sessionStorage.removeItem('gn_token');
    if (BASE && tok) {
      // best-effort logout; ignore failures (e.g. offline / already expired)
      fetch(BASE + '/api/v1/auth/logout', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + tok },
        credentials: 'include',
      }).catch(function () {});
    }
  };

  // Refresh-token rotation. The refresh token is sent as an HttpOnly cookie
  // automatically (credentials: 'include'); it is NOT put in the request body,
  // so an XSS payload cannot read or replay it from JS. On success the backend
  // sets a fresh HttpOnly cookie and returns only the new access token.
  let _refreshing = null;
  async function doRefresh() {
    try {
      const res = await fetch(BASE + '/api/v1/auth/refresh', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
      });
      if (!res.ok) return null;
      const data = await res.json();
      if (!data || !data.token) return null;
      GN.setAuth(data); // stores only the access token
      return data.token;
    } catch (_) {
      return null;
    }
  }

  async function req(path, opts = {}) {
    if (!BASE) return { offline: true };

    async function attempt() {
      const headers = { 'Content-Type': 'application/json' };
      if (GN.token) headers['Authorization'] = 'Bearer ' + GN.token;
      return fetch(BASE + path, { headers, ...opts });
    }

    let res = await attempt();
    // On 401, attempt a single refresh-token rotation then retry once. The
    // refresh token rides along as an HttpOnly cookie, so this works without
    // any JS-held secret.
    if (res.status === 401) {
      if (!_refreshing) _refreshing = doRefresh().finally(() => { _refreshing = null; });
      const newTok = await _refreshing;
      if (newTok) res = await attempt();
    }
    if (!res.ok) throw new Error('HTTP ' + res.status);
    return res.json();
  }

  GN.API = {
    /* ---- health (for LIVE-mode reachability probes / uptime monitoring) ---- */
    health: () => req('/api/v1/health'),

    /* ---- auth ---- */
    login: async (phone, password) => {
      const r = await req('/api/v1/auth/login', { method: 'POST', body: JSON.stringify({ phone, password }) });
      if (r && r.token) GN.setAuth(r);
      return r;
    },
    register: async (payload) => {
      const r = await req('/api/v1/auth/register', { method: 'POST', body: JSON.stringify(payload) });
      if (r && r.token) GN.setAuth(r);
      return r;
    },

    /* ---- catalogue ---- */
    categories: () => req('/api/v1/categories'),
    merchants: (q = '') => req('/api/v1/merchants' + q),
    merchantProducts: (id) => req('/api/v1/merchants/' + id + '/products'),
    flashSales: () => req('/api/v1/flash-sales'),

    /* ---- orders ---- */
    createOrder: (payload) =>
      req('/api/v1/orders', { method: 'POST', body: JSON.stringify(payload) }),
    // P0: cross-store merged order (parent + one sub-order per merchant).
    createMergedOrder: (payload) =>
      req('/api/v1/orders/merged', { method: 'POST', body: JSON.stringify(payload) }),
    // Customer coupon preview (does not consume). Returns {valid,discount,...} or 422.
    verifyCoupon: (code, merchantId, subtotal) =>
      req('/api/v1/coupons/verify', {
        method: 'POST',
        body: JSON.stringify({ code, merchant_id: merchantId, subtotal }),
      }),
    /* ---- settlement (T+1) ---- */
    merchantSettlements: () => req('/api/v1/merchant/settlements'),
    confirmSettlement: (date) =>
      req('/api/v1/merchant/settlements/confirm', {
        method: 'POST',
        body: JSON.stringify({ settle_date: date }),
      }),
    adminSettlements: () => req('/api/v1/admin/settlements/merchants'),
    myOrders: () => req('/api/v1/orders'),
    order: (orderNo) => req('/api/v1/orders/' + orderNo),
    cancelOrder: (orderNo) =>
      req('/api/v1/orders/' + orderNo + '/cancel', { method: 'POST' }),

    /* ---- payment ---- */
    pay: (orderNo, method) =>
      req('/api/v1/orders/' + orderNo + '/pay', {
        method: 'POST',
        body: JSON.stringify({ method }),
      }),
    paymentStatus: (orderNo) => req('/api/v1/orders/' + orderNo + '/payment-status'),

    /* ---- merchant (own storefront, requires merchant ability) ---- */
    merchantProfile: () => req('/api/v1/merchant/me'),
    merchantOrders: () => req('/api/v1/merchant/orders'),
    myProducts: () => req('/api/v1/merchant/products'),
    createProduct: (payload) =>
      req('/api/v1/merchant/products', { method: 'POST', body: JSON.stringify(payload) }),
    updateProduct: (id, payload) =>
      req('/api/v1/merchant/products/' + id, { method: 'PUT', body: JSON.stringify(payload) }),
    updateMerchantProfile: (payload) =>
      req('/api/v1/merchant/me', { method: 'PUT', body: JSON.stringify(payload) }),
    merchantAccept: (orderNo) =>
      req('/api/v1/merchant/orders/' + orderNo + '/accept', { method: 'POST' }),
    merchantReady: (orderNo) =>
      req('/api/v1/merchant/orders/' + orderNo + '/ready', { method: 'POST' }),

    /* ---- merchant coupons (own storefront) ---- */
    merchantCoupons: () => req('/api/v1/merchant/coupons'),
    createCoupon: (payload) =>
      req('/api/v1/merchant/coupons', { method: 'POST', body: JSON.stringify(payload) }),
    updateCoupon: (id, payload) =>
      req('/api/v1/merchant/coupons/' + id, { method: 'PUT', body: JSON.stringify(payload) }),
    deleteCoupon: (id) =>
      req('/api/v1/merchant/coupons/' + id, { method: 'DELETE' }),

    /* ---- rider ---- */
    riderOrders: (lat, lng) =>
      req('/api/v1/rider/orders' + (lat != null ? `?lat=${lat}&lng=${lng}` : '')),
    riderCurrent: () => req('/api/v1/rider/current'),
    riderProfile: () => req('/api/v1/rider/me'),
    setRiderOnline: (online) =>
      req('/api/v1/rider/me', { method: 'PUT', body: JSON.stringify({ status: online ? 'online' : 'offline' }) }),
    riderLocation: (lat, lng) =>
      req('/api/v1/rider/location', { method: 'POST', body: JSON.stringify({ lat, lng }) }),
    riderAccept: (orderNo) =>
      req('/api/v1/rider/orders/' + orderNo + '/accept', { method: 'POST' }),
    // lat/lng = rider's live GPS at the moment of hand-off. When supplied the
    // backend enforces a proximity gate (must be near the delivery address).
    riderDeliver: (orderNo, lat, lng) => {
      const body = {};
      if (lat != null && lng != null) {
        body.lat = lat;
        body.lng = lng;
      }
      return req('/api/v1/rider/orders/' + orderNo + '/deliver', {
        method: 'POST',
        body: Object.keys(body).length ? JSON.stringify(body) : undefined,
      });
    },

    /* ---- push device token (saved server-side for FCM/APNs targeting) ---- */
    deviceToken: (token, platform) => {
      const devName = (navigator.userAgent || 'unknown').slice(0, 60);
      return req('/api/v1/device-token', {
        method: 'POST',
        body: JSON.stringify({
          token,
          platform,
          device_name: devName,
          locale: (navigator.language || 'vi'),
        }),
      });
    },

    /* ---- admin (L console) — platform self-operated back office ----
       Endpoints that exist in backend/routes/api.php are wired live; those
       still pending on the backend (dashboard, merchant list) will 404 and the
       prototype falls back to its offline demo data. */
    adminLogin: (phone, password) => GN.API.login(phone, password),
    // GET /api/admin/dashboard — summary KPIs (pending on backend)
    adminDashboard: () => req('/api/v1/admin/dashboard'),
    // GET /api/admin/orders?status=  (exists)
    adminOrders: (q = '') => req('/api/v1/admin/orders' + q),
    // GET /api/admin/settlement — finance summary (exists)
    adminSettlement: () => req('/api/v1/admin/settlement'),
    // GET /api/admin/settlements/merchants?date=  (exists)
    adminMerchantSettlements: (q = '') => req('/api/v1/admin/settlements/merchants' + q),
    // GET /api/admin/settlements/payouts?merchant_id=&settle_date=&status=
    adminPayouts: (q = '') => req('/api/v1/admin/settlements/payouts' + q),
    // POST /api/admin/settlements/{merchant}/pay  {settle_date,amount?,method?,reference?,note?}
    adminSettlementPay: (id, payload) =>
      req('/api/v1/admin/settlements/' + id + '/pay', { method: 'POST', body: JSON.stringify(payload) }),
    // GET /api/admin/merchants?status=  (pending on backend — falls back to demo)
    adminMerchants: (q = '') => req('/api/v1/admin/merchants' + q),
    adminApproveMerchant: (id) =>
      req('/api/v1/admin/merchants/' + id + '/approve', { method: 'POST' }),
    adminRejectMerchant: (id, reason) =>
      req('/api/v1/admin/merchants/' + id + '/reject', { method: 'POST', body: JSON.stringify({ reason: reason || '' }) }),
    adminApproveKyc: (id) =>
      req('/api/v1/admin/merchants/' + id + '/kyc/approve', { method: 'POST' }),
    adminRejectKyc: (id, reason) =>
      req('/api/v1/admin/merchants/' + id + '/kyc/reject', { method: 'POST', body: JSON.stringify({ reason: reason || '' }) }),
    // GET /api/admin/agents — delivery agents
    adminAgents: () => req('/api/v1/admin/agents'),
    adminAgent: (id) => req('/api/v1/admin/agents/' + id),
    adminAgentApprove: (id) =>
      req('/api/v1/admin/agents/' + id + '/approve', { method: 'POST' }),
    adminAgentReject: (id) =>
      req('/api/v1/admin/agents/' + id + '/reject', { method: 'POST' }),
    adminAgentUpdate: (id, payload) =>
      req('/api/v1/admin/agents/' + id, { method: 'PUT', body: JSON.stringify(payload) }),
    adminAgentDelete: (id) =>
      req('/api/v1/admin/agents/' + id, { method: 'DELETE' }),
    // GET /api/admin/payouts — merchant payout batch (exists)
    adminPayouts: () => req('/api/v1/admin/payouts'),
  };

  /* If useApi is on, the prototype calls this instead of faking the order. */
  GN.createOrderLive = async function (payload) {
    const r = await GN.API.createOrder(payload);
    if (r.offline) return { offline: true };
    if (r.order_no) GN.lastOrderNo = r.order_no;
    return r;
  };

  /* Demo login helper: register (or login) a user of the given role. */
  GN.demoLogin = async function (phone = '0900000002', role = 'customer') {
    return GN.demoLoginAs(phone, role);
  };

  GN.demoLoginAs = async function (phone, role = 'customer', password = 'demo123') {
    let r = await GN.API.login(phone, password);
    if (r && r.offline) return { offline: true };
    if (r && r.token) {
      if (GN.native && GN.native.flushPushToken) GN.native.flushPushToken();
      return r;
    }
    // not registered yet → register with the requested role
    r = await GN.API.register({ name: 'Demo ' + role, phone, role });
    if (r && r.offline) return { offline: true };
    if (GN.native && GN.native.flushPushToken) GN.native.flushPushToken();
    return r;
  };
})();
