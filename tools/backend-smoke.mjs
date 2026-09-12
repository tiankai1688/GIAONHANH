// GIAONHANH backend smoke test
//
// Proves the REAL Laravel stack (MySQL via `php artisan serve`) boots and serves
// the core auth flow end-to-end. Registration is intentionally a 2-step OTP flow
// (red-team security requirement — an account is NOT created and NO token is
// issued until the OTP is verified), so this script exercises both steps:
//
//   POST /api/v1/auth/register        -> OTP requested (dev OTP echoed when
//                                         APP_DEBUG=true, which the CI smoke
//                                         job enables; production never returns it)
//   POST /api/v1/auth/register/verify -> account created + access token issued
//   GET  /api/v1/me                   -> authed route works with that token
//
// A fresh random phone is used each run so the test is idempotent and never
// trips the per-phone OTP cooldown or the unique-phone constraint.

const BASE = process.env.API_BASE || 'http://127.0.0.1:8080';
const API = BASE + '/api/v1';

let failed = 0;

function log(ok, name, extra = '') {
  console.log(`${ok ? '✓' : '✗'} ${name}${extra ? ' — ' + extra : ''}`);
  if (!ok) failed++;
  return ok;
}

async function call(method, path, body, token) {
  const headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
  if (token) headers['Authorization'] = 'Bearer ' + token;
  const res = await fetch(API + path, {
    method,
    headers,
    body: body ? JSON.stringify(body) : undefined,
  });
  let json = null;
  try { json = await res.json(); } catch (_) { json = null; }
  return { res, json };
}

(async () => {
  // 1) Health — proves the server is up and routing works.
  const h = await call('GET', '/health');
  log(
    h.res.ok && h.json && h.json.ok === true,
    'GET /api/health',
    `status=${h.res.status}`,
  );

  // 2) Register — step 1: request the OTP. No account, no token yet.
  const phone = '09' + Math.floor(10000000 + Math.random() * 89999999); // 10-digit VN mobile
  const reg = await call('POST', '/auth/register', {
    name: 'Smoke Customer',
    phone: String(phone),
    password: 'Sm0keTest#2026',
  });
  const otp = reg.json && reg.json.otp;
  const regOk =
    reg.res.status === 200 &&
    reg.json &&
    reg.json.message &&
    typeof otp === 'string' &&
    otp.length === 6;
  log(
    regOk,
    'POST /api/auth/register (request OTP)',
    regOk ? `otp=${otp}` : `status=${reg.res.status} body=${JSON.stringify(reg.json)}`,
  );

  // 3) Verify — step 2: create the account + issue the access token.
  let token = null;
  if (regOk) {
    const v = await call('POST', '/auth/register/verify', { phone: String(phone), otp });
    token = v.json && v.json.token;
    log(
      v.res.status === 201 && typeof token === 'string' && token.length > 0,
      'POST /api/auth/register/verify (create account + token)',
      `status=${v.res.status}`,
    );
  } else {
    log(false, 'POST /api/auth/register/verify (create account + token)', 'skipped: register failed');
  }

  // 4) Authed route — proves token issuance + sanctum ability routing.
  if (token) {
    const me = await call('GET', '/me', null, token);
    log(
      me.res.ok && me.json && me.json.role === 'customer',
      'GET /api/me (customer token)',
      `status=${me.res.status}`,
    );
  } else {
    log(false, 'GET /api/me (customer token)', 'skipped: no token');
  }

  console.log(`\nRESULT: ${failed === 0 ? 'PASS' : 'FAIL'} (${failed} failed)`);
  process.exit(failed === 0 ? 0 : 1);
})();
