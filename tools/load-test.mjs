// GIAONHANH load sniff — zero-dependency Node runner (no install needed).
// Use this for a quick scale-readiness check right now; use load-test.k6.js
// for a serious, threshold-gated run in CI / prod-like env.
//
//   API_BASE=http://localhost:8080 DURATION=30 VUS=20 MERCHANT_ID=1 \
//   node tools/load-test.mjs
//
// Node 18+ (global fetch). Reports RPS, error rate, p50/p95/p99 latency.

import { performance } from 'node:perf_hooks';

const BASE = (process.env.API_BASE || 'http://localhost:8080').replace(/\/+$/, '');
const API = BASE + '/api/v1';
const DURATION = Number(process.env.DURATION || 30);
const VUS = Number(process.env.VUS || 20);
const MERCHANT_ID = process.env.MERCHANT_ID || '1';

const endpoints = [
  { method: 'GET', path: '/health' },
  { method: 'GET', path: '/merchants' },
  { method: 'GET', path: `/merchants/${MERCHANT_ID}/products` },
  { method: 'GET', path: '/categories' },
  { method: 'GET', path: '/flash-sales' },
];

const latencies = [];
let ok = 0;
let fail = 0;
const deadline = Date.now() + DURATION * 1000;

async function worker() {
  while (Date.now() < deadline) {
    const ep = endpoints[Math.floor(Math.random() * endpoints.length)];
    const t0 = performance.now();
    try {
      const res = await fetch(API + ep.path, { method: ep.method });
      latencies.push(performance.now() - t0);
      if (res.ok) ok++;
      else fail++;
    } catch {
      latencies.push(performance.now() - t0);
      fail++;
    }
  }
}

const start = Date.now();
await Promise.all(Array.from({ length: VUS }, () => worker()));
const elapsed = (Date.now() - start) / 1000;
latencies.sort((a, b) => a - b);
const pct = (p) => (latencies.length ? latencies[Math.floor((p / 100) * (latencies.length - 1))] : 0);
const total = ok + fail;

console.log('\nGIAONHANH load sniff →', API);
console.log(`duration=${elapsed.toFixed(1)}s  vus=${VUS}`);
console.log(`requests=${total}  rps=${(total / elapsed).toFixed(1)}  ok=${ok}  fail=${fail}  errRate=${(total ? (fail / total) * 100 : 0).toFixed(2)}%`);
console.log(`latency ms: p50=${pct(50).toFixed(1)}  p95=${pct(95).toFixed(1)}  p99=${pct(99).toFixed(1)}  max=${latencies[latencies.length - 1]?.toFixed(1)}`);
process.exit(total && fail / total > 0.05 ? 1 : 0);
