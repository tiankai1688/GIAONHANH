#!/usr/bin/env node
/**
 * verify-contract.mjs
 * --------------------
 * Frontend <-> Backend endpoint contract checker for GIAONHANH.
 *
 *  1. Parses the frontend API client (app/api.js) and extracts every
 *     req('/api/...') call together with its HTTP method.
 *  2. Parses the Laravel route file (backend/routes/api.php) and extracts
 *     every Route::{verb}('/...') (prefixing /api, which Laravel adds
 *     automatically for routes defined in api.php).
 *  3. Normalizes path params ({order}, + id +, ...) to a single {*}
 *     token and strips query strings, then checks that every frontend call has a
 *     matching backend route (same verb + normalized path).
 *  4. Bonus: scans app/api.js for duplicate keys inside the GN.API = { }
 *     object literal (a duplicate key silently shadows an earlier definition).
 *
 * Exit code: 0 = all frontend endpoints resolved, 1 = at least one missing.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const __dir = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(__dir, '..');
const API_JS = resolve(ROOT, 'app/api.js');
const ROUTES_PHP = resolve(ROOT, 'backend/routes/api.php');

/* ---------- helpers ---------- */
function norm(p) {
  return p
    .replace(/\?.*$/, '')                 // strip query string
    .replace(/\/+$/, '')                    // trailing slash
    .replace(/\/\{[^}]+\}/g, '/{*}')     // backend {param}
    .replace(/{%}|{%}/g, '{*}')
    .replace(/\/{2,}/g, '/');             // collapse double slashes
}

// Reassemble a possibly string-concatenated req() path argument.
//  e.g.  '/api/merchants/' + id + '/products'  ->  /api/merchants/{*}/products
function reassemble(argExpr) {
  const parts = argExpr.split('+').map((s) => s.trim()).filter(Boolean);
  let out = '';
  for (const part of parts) {
    const m = part.match(/^['"`]([^'"`]*)['"`]$/);
    if (m) {
      out += m[1];
    } else if (part.includes('?')) {
      out += '';                              // a `?query` ternary fragment, not a path segment
    } else {
      // A bare variable expression. If it is appended directly after a '/'
      // it is a PATH parameter (e.g. '/orders/' + id -> /orders/{*}).
      // Otherwise it is a QUERY-STRING append (e.g. '/admin/orders' + q
      // where q = '?status=...') and must NOT add a path segment, or the
      // tool will falsely report a missing '/admin/orders/{*}' route.
      out += out.endsWith('/') ? '/{*}' : '';
    }
  }
  return norm(out);
}

// Walk from a '(' and return the balanced substring inside it.
function balancedArg(src, openIdx) {
  let i = openIdx, depth = 0, instr = null;
  for (; i < src.length; i++) {
    const ch = src[i];
    if (instr) {
      if (ch === '\\') { i++; continue; }
      if (ch === instr) instr = null;
      continue;
    }
    if (ch === '"' || ch === "'" || ch === '`') { instr = ch; continue; }
    if (ch === '(') depth++;
    else if (ch === ')') {
      depth--;
      if (depth === 0) return src.slice(openIdx + 1, i);
    }
  }
  return '';
}

// Find the first top-level comma (depth 0, not in a string).
function splitFirstComma(arg) {
  let depth = 0, instr = null;
  for (let i = 0; i < arg.length; i++) {
    const ch = arg[i];
    if (instr) {
      if (ch === '\\') { i++; continue; }
      if (ch === instr) instr = null;
      continue;
    }
    if (ch === '"' || ch === "'" || ch === '`') { instr = ch; continue; }
    if (ch === '(' || ch === '{' || ch === '[') depth++;
    else if (ch === ')' || ch === '}' || ch === ']') depth--;
    else if (ch === ',' && depth === 0) return [arg.slice(0, i), arg.slice(i + 1)];
  }
  return [arg, ''];
}

/* ---------- 1. frontend endpoints ---------- */
function parseFrontend(src) {
  const calls = [];
  let from = 0;
  while (true) {
    const idx = src.indexOf('req(', from);
    if (idx === -1) break;
    const prev = idx > 0 ? src[idx - 1] : '';
    if (/[A-Za-z0-9_$]/.test(prev)) { from = idx + 4; continue; }
    const arg = balancedArg(src, idx + 3); // idx+3 points at '(' after 'req'
    const [pathExpr, optsExpr] = splitFirstComma(arg);
    const mm = optsExpr.match(/method\s*:\s*['"`](GET|POST|PUT|DELETE|PATCH)['"`]/i);
    const method = (mm ? mm[1] : 'GET').toUpperCase();
    const path = reassemble(pathExpr);
    if (path.startsWith('/api/')) calls.push({ method, path });
    from = idx + 4;
  }
  return calls;
}

/* ---------- 2. backend routes ---------- */
function parseRoutes(src) {
  const routes = [];
  // Routes in api.php are mounted under /api by Laravel. When they are
  // additionally wrapped in Route::prefix('v1'), the effective prefix is
  // /api/v1 — detect it so the contract check stays in sync with versioning.
  const apiPrefix = /Route::prefix\(\s*['"`]v1['"`]/i.test(src) ? '/api/v1' : '/api';
  const re = /Route::(get|post|put|delete|patch)\(\s*['"`]([^'"`]+)['"`]/gi;
  let m;
  while ((m = re.exec(src))) {
    const verb = m[1].toUpperCase();
    const path = norm(apiPrefix + m[2]);
    routes.push({ method: verb, path });
  }
  return routes;
}

/* ---------- 3. duplicate-key scan ---------- */
function duplicateKeys(src) {
  const block = src.match(/GN\.API\s*=\s*\{([\s\S]*?)\n\};\s*$/m);
  if (!block) return [];
  const keys = [];
  const re = /^\s*([A-Za-z_$][\w$]*)\s*:\s*(?:async\s*)?function|^\s*([A-Za-z_$][\w$]*)\s*:/gm;
  let k;
  while ((k = re.exec(block[1]))) {
    const key = k[1] || k[2];
    if (key) keys.push(key);
  }
  const seen = new Set();
  const dup = new Set();
  for (const key of keys) {
    if (seen.has(key)) dup.add(key);
    seen.add(key);
  }
  return [...dup];
}

/* ---------- main ---------- */
const apiSrc = readFileSync(API_JS, 'utf8');
const routeSrc = readFileSync(ROUTES_PHP, 'utf8');

const frontend = parseFrontend(apiSrc);
const backend = parseRoutes(routeSrc);
const dupes = duplicateKeys(apiSrc);

const backKey = (r) => r.method + ' ' + r.path;
const backSet = new Set(backend.map(backKey));

const missing = [];
const matched = [];
for (const c of frontend) {
  const key = c.method + ' ' + c.path;
  if (backSet.has(key)) matched.push(key);
  else missing.push(key);
}

const frontSet = new Set(frontend.map((c) => c.method + ' ' + c.path));
const orphans = backend.filter((r) => !frontSet.has(backKey(r))).map(backKey);

const ok = missing.length === 0 && dupes.length === 0;

console.log('\n=== GIAONHANH Frontend <-> Backend Contract ===\n');
console.log('Frontend endpoints parsed : ' + frontend.length);
console.log('Backend routes parsed     : ' + backend.length);
console.log('Matched                 : ' + matched.length);

if (matched.length) {
  console.log('\n[OK] Resolved endpoints:');
  matched.forEach((k) => console.log('   ' + k));
}
if (missing.length) {
  console.log('\n[MISSING] frontend calls with no backend route:');
  missing.forEach((k) => console.log('   ' + k));
}
if (dupes.length) {
  console.log('\n[WARN] duplicate keys in app/api.js (later shadows earlier):');
  dupes.forEach((k) => console.log('   ' + k));
}
if (orphans.length) {
  console.log('\n[INFO] backend routes with no frontend caller:');
  orphans.forEach((k) => console.log('   ' + k));
}

console.log('\n' + (ok ? 'RESULT: PASS' : 'RESULT: FAIL'));
process.exit(ok ? 0 : 1);
