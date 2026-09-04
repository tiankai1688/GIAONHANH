// GIAONHANH backend load test — k6 (industry-standard, for serious scale runs).
//
//   BASE_URL=http://localhost:8080 MERCHANT_ID=1 k6 run tools/load-test.k6.js
//
// What it proves (the honest "scale readiness" evidence for fundraising DD):
//   - read hot path: /merchants + /merchants/{id}/products + /categories
//     (browsing is the real bottleneck at 300M scale; single-DB bbox query)
//   - write path kept light because auth routes are throttled (60/min)
//
// Thresholds fail the run if p95 > 800ms or error rate > 1%.

import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE = (__ENV.BASE_URL || 'http://localhost:8080').replace(/\/+$/, '');
const API = `${BASE}/api/v1`;
const MERCHANT_ID = __ENV.MERCHANT_ID || '1';

export const options = {
  scenarios: {
    browse: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '30s', target: 50 },
        { duration: '1m', target: 200 },
        { duration: '30s', target: 0 },
      ],
      exec: 'browse',
    },
    write: {
      executor: 'constant-vus',
      vus: 2,
      duration: '2m',
      exec: 'write',
    },
  },
  thresholds: {
    http_req_duration: ['p(95)<800'],
    http_req_failed: ['rate<0.01'],
  },
};

export function browse() {
  const m = http.get(`${API}/merchants`);
  check(m, { 'merchants 200': (r) => r.status === 200 });
  const p = http.get(`${API}/merchants/${MERCHANT_ID}/products`);
  check(p, { 'products 200': (r) => r.status === 200 });
  http.get(`${API}/categories`);
  http.get(`${API}/health`);
  sleep(1);
}

export function write() {
  const phone = '09' + Math.floor(100000000 + Math.random() * 899999999);
  const r = http.post(
    `${API}/auth/register`,
    JSON.stringify({ name: 'LT', phone, password: 'Load123' }),
    { headers: { 'Content-Type': 'application/json' } },
  );
  check(r, { 'register 201/200': (x) => x.status === 201 || x.status === 200 });
  sleep(2);
}
