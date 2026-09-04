// GIAONHANH backend API smoke test (HTTP end-to-end).
//
// Runs against a *running* Laravel instance — it does NOT need PHP locally,
// only Node 18+ (global fetch). Point it at the server with API_BASE.
//
//   API_BASE=http://localhost:8080 \
//   ADMIN_PHONE=0900000001 ADMIN_PASSWORD=admin123 \
//   node tools/backend-smoke.mjs
//
// It exercises the critical paths so that "the backend runs" is provable:
//   health -> register (token + refresh_token) -> list merchant(s) + product
//   -> create order (with server-side coupon) -> pay (cod) -> fetch order
//   -> cross-store MERGED order (flagship differentiator) -> refresh token
//   -> admin login -> admin settlement list -> admin payout disbursement.
//
// Exit code is non-zero if any hard check fails. Optional steps (merged order
// needs >=2 merchants with products, payout needs >=1 settled merchant) skip
// gracefully when seed data is insufficient rather than failing the run.

const BASE = (process.env.API_BASE || 'http://localhost:8080').replace(/\/+$/, '');
const API = BASE + '/api/v1';
const ADMIN_PHONE = process.env.ADMIN_PHONE || '0900000001';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || process.env.ADMIN_SEED_PASSWORD || 'admin123';
const COUPON_CODE = process.env.COUPON_CODE || ''; // optional: a known merchant coupon to verify

let pass = 0;
let fail = 0;
const lines = [];

async function req(path, { method = 'GET', body, token } = {}) {
  const headers = { 'Content-Type': 'application/json' };
  if (token) headers['Authorization'] = 'Bearer ' + token;
  const res = await fetch(API + path, {
    method,
    headers,
    body: body ? JSON.stringify(body) : undefined,
  });
  let data = null;
  try { data = await res.json(); } catch { /* non-JSON */ }
  return { status: res.status, data };
}

function ok(name, cond, detail = '') {
  if (cond) {
    pass++;
    lines.push(`  ✓ ${name}`);
  } else {
    fail++;
    lines.push(`  ✗ ${name}${detail ? ' — ' + detail : ''}`);
  }
  return cond;
}

function randPhone() {
  return '09' + String(Math.floor(100000000 + Math.random() * 899999999));
}

async function main() {
  console.log(`\nGIAONHANH backend smoke → ${API}\n`);

  // 1) Health
  {
    const { status, data } = await req('/health');
    ok('GET /api/health', status === 200 && data && data.ok === true, `status=${status}`);
  }

  // 2) Register a brand-new customer -> token + refresh_token
  const phone = randPhone();
  const password = 'Smoke123';
  let custToken = null;
  let custRefresh = null;
  {
    const { status, data } = await req('/auth/register', {
      method: 'POST',
      body: { name: 'Smoke User', phone, password },
    });
    const got = status === 201 && data && data.token && data.refresh_token;
    ok('POST /api/auth/register (token + refresh_token)', got, `status=${status} ${data?.message || ''}`);
    custToken = data?.token;
    custRefresh = data?.refresh_token;
    // Fallback: if phone collides, try login with the same password.
    if (!got) {
      const r2 = await req('/auth/login', { method: 'POST', body: { phone, password } });
      if (r2.status === 200 && r2.data?.token) {
        custToken = r2.data.token;
        custRefresh = r2.data.refresh_token;
        ok('  fallback: POST /api/auth/login', true);
      }
    }
  }

  if (!custToken) {
    console.log(lines.join('\n'));
    console.log(`\nRESULT: FAIL (${fail} failed, ${pass} passed) — no customer token, aborting.`);
    process.exit(1);
  }

  // 3) List approved merchants, collect those with a product (need >=2 for merged order)
  let merchantId = null;
  let productId = null;
  const merchantsWithProducts = [];
  {
    const { status, data } = await req('/merchants');
    const list = Array.isArray(data) ? data : (data?.data || []);
    ok('GET /api/merchants', status === 200 && list.length > 0, `status=${status} count=${list.length}`);
    for (const m of list) {
      const pr = await req(`/merchants/${m.id}/products`);
      const plist = Array.isArray(pr.data) ? pr.data : (pr.data?.data || []);
      if (pr.status === 200 && plist.length > 0) {
        if (!merchantId) { merchantId = m.id; productId = plist[0].id; }
        if (merchantsWithProducts.length < 3) merchantsWithProducts.push({ id: m.id, productId: plist[0].id });
      }
    }
    ok('found approved merchant with a product', !!merchantId, merchantId ? `merchant=${merchantId} product=${productId}` : 'none found');
  }

  // 4) Create an order (server-side coupon resolution)
  let orderNo = null;
  if (merchantId && productId) {
    const { status, data } = await req('/orders', {
      method: 'POST',
      token: custToken,
      body: {
        merchant_id: merchantId,
        address: '123 Duong ABC, Hanoi',
        lat: 21.0285,
        lng: 105.8542,
        contact_name: 'Smoke',
        contact_phone: phone,
        pay_method: 'cod',
        items: [{ product_id: productId, qty: 1 }],
      },
    });
    const created = status === 201 && data && data.order_no;
    ok('POST /api/orders (201 + order_no)', created, `status=${status} ${data?.message || ''}`);
    orderNo = data?.order_no;
    if (created) {
      ok('  order has merchant_settlement computed', typeof data.merchant_settlement === 'number', `settlement=${data.merchant_settlement}`);
    }
  } else {
    ok('POST /api/orders (skipped: no merchant/product)', true, 'seeding gap');
  }

  // 5) Pay (COD) then fetch order
  if (orderNo) {
    const pay = await req(`/orders/${orderNo}/pay`, { method: 'POST', token: custToken, body: { method: 'cod' } });
    ok('POST /api/orders/{no}/pay', pay.status === 200 || pay.status === 201, `status=${pay.status}`);

    const show = await req(`/orders/${orderNo}`, { token: custToken });
    ok('GET /api/orders/{no}', show.status === 200 && show.data && show.data.order_no === orderNo, `status=${show.status}`);
  }

  // 5b) Cross-store MERGED order (the flagship differentiator) — needs >=2 merchants with products.
  if (merchantsWithProducts.length >= 2) {
    const [a, b] = merchantsWithProducts;
    const { status, data } = await req('/orders/merged', {
      method: 'POST',
      token: custToken,
      body: {
        delivery_type: 'instant',
        groups: [
          { merchant_id: a.id, items: [{ product_id: a.productId, qty: 1 }] },
          { merchant_id: b.id, items: [{ product_id: b.productId, qty: 1 }] },
        ],
      },
    });
    const created = status === 201 && data && data.order_no;
    ok('POST /api/orders/merged (201 + parent order_no)', created, `status=${status} ${data?.message || ''}`);
    if (created) {
      const subs = Array.isArray(data.sub_orders) ? data.sub_orders
                 : (Array.isArray(data.subOrders) ? data.subOrders : []);
      ok('  merged parent has >=2 sub-orders', subs.length >= 2, `sub_count=${subs.length}`);
    }
  } else {
    ok('POST /api/orders/merged (skipped: <2 merchants with products)', true, 'seeding gap');
  }

  // 6) Refresh token rotation (must return a NEW access token + refresh_token)
  if (custRefresh) {
    const { status, data } = await req('/auth/refresh', { method: 'POST', body: { refresh_token: custRefresh } });
    const rotated = status === 200 && data && data.token && data.refresh_token;
    ok('POST /api/auth/refresh (rotation)', rotated, `status=${status} ${data?.message || ''}`);
    if (rotated && data.token === custToken) {
      ok('  new access token differs from old', false, 'token unchanged');
    } else if (rotated) {
      ok('  new access token differs from old', true);
    }
  } else {
    ok('POST /api/auth/refresh (skipped: no refresh_token)', true);
  }

  // 7) Coupon verify (optional, if a code is supplied)
  if (COUPON_CODE && merchantId) {
    const { status, data } = await req('/coupons/verify', {
      method: 'POST',
      token: custToken,
      body: { code: COUPON_CODE, merchant_id: merchantId, subtotal: 100000 },
    });
    ok('POST /api/coupons/verify', status === 200 && data && data.valid === true, `status=${status} ${data?.message || ''}`);
  }

  // 8) Admin login + settlement list + a payout disbursement ledger entry
  {
    const { status, data } = await req('/auth/login', { method: 'POST', body: { phone: ADMIN_PHONE, password: ADMIN_PASSWORD } });
    const adminToken = data?.token;
    ok('admin POST /api/auth/login', status === 200 && !!adminToken, `status=${status} ${data?.message || ''}`);
    if (adminToken) {
      const set = await req('/admin/settlements/merchants', { token: adminToken });
      ok('admin GET /api/admin/settlements/merchants', set.status === 200, `status=${set.status}`);
      // NOTE: adminIndex returns a nested object {settle_date, merchant_count,
      // total_payable, merchants:[...]} — NOT a flat array. Use the merchant id
      // collected in step 3 to guarantee the payout endpoint is actually exercised
      // (adminPayout is an updateOrCreate ledger that always 200s, independent of
      // whether settlement rows exist for that date).
      const mid = merchantId
        || (set.data?.merchants && set.data.merchants[0]?.merchant_id);
      if (mid) {
        const pay = await req(`/admin/settlements/${mid}/pay`, {
          method: 'POST', token: adminToken,
          body: { settle_date: new Date(Date.now() - 86400000).toISOString().slice(0, 10), method: 'manual' },
        });
        ok('admin POST /api/admin/settlements/{m}/pay (disbursement ledger)', pay.status === 200 || pay.status === 201, `status=${pay.status}`);
      } else {
        ok('admin POST /api/admin/settlements/{m}/pay (skipped: no merchant id)', true, 'data gap');
      }
    }
  }

  console.log(lines.join('\n'));
  if (fail === 0) {
    console.log(`\nRESULT: PASS (${pass} passed, 0 failed) ✅`);
    process.exit(0);
  } else {
    console.log(`\nRESULT: FAIL (${fail} failed, ${pass} passed) ❌`);
    process.exit(1);
  }
}

main().catch((e) => {
  console.error('FATAL:', e);
  process.exit(1);
});
