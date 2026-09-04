// Strictify CSP for the static SPA: remove inline event-handler attributes
// (the dangerous XSS sink) and inject a hash-based <meta> CSP so the SPA can
// run without 'unsafe-inline' in script-src.
//
// Static SPAs cannot use nonce CSP (nonce requires an HTTP response header;
// meta CSP forbids nonce). Hash-based CSP works in meta and is deterministic
// for first-party inline scripts whose source is fixed — so we compute the
// SHA-256 of every attribute-less <script> block and allowlist it.
//
// Usage: node tools/strictify-csp.mjs [--check]
//   --check  : report only, do not write files.

import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
import { execSync } from 'node:child_process';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const CHECK = process.argv.includes('--check');

// backend/public is served by the API nginx (governed by the HTTP-header CSP),
// so it is excluded from meta-CSP injection to avoid header/meta policy
// intersection breaking its inline scripts. Its inline handler was fixed by hand.
const roots = ['app', 'mobile/www', 'mobile/android/app/src/main/assets/public'];
let files = [];
for (const r of roots) {
  const abs = join(ROOT, r);
  if (!existsSync(abs)) continue;
  const out = execSync(`find "${abs}" -name "*.html" -not -path "*/node_modules/*" -not -path "*/vendor/*"`, { encoding: 'utf8' });
  files.push(...out.split('\n').filter(Boolean));
}
files = [...new Set(files)];

// Inline event-handler conversions: attribute form -> data-action.
// admin/merchant-web use escaped quotes (\") inside JS template literals;
// merged-demo uses literal double quotes.
const CONVERSIONS = [
  // closeDrawer (escaped + unescaped)
  [/onclick=\\"closeDrawer\\(\\)\\"/g, 'data-action=\\"closeDrawer\\"'],
  [/onclick="closeDrawer\(\)"/g, 'data-action="closeDrawer"'],
  // switchPage('orders') (escaped + unescaped)
  [/onclick=\\"switchPage\('orders'\)\\"/g, 'data-action=\\"switchPage\\" data-args=\'["orders"]\''],
  [/onclick="switchPage\('orders'\)"/g, 'data-action="switchPage" data-args=\'["orders"]\''],
  // merged-demo clipboard expression (literal double quotes, ${orderNo})
  [/onclick="navigator\.clipboard&&navigator\.clipboard\.writeText\('\$\{orderNo\}'\)"/g,
    'data-action="copyOrderNo" data-args=\'["${orderNo}"]\''],
];

// Event-handler attribute names we must NEVER leave inline.
const HANDLER_RE = /\s+on(?:click|dblclick|mousedown|mouseup|mouseover|mouseout|mousemove|mouseenter|mouseleave|keydown|keyup|keypress|focus|blur|change|input|submit|reset|load|unload|error|scroll|contextmenu|touchstart|touchend|toggle)\s*=/gi;

const report = [];
let totalOnclick = 0;
let totalHashes = 0;

for (const f of files) {
  let html = readFileSync(f, 'utf8');
  const before = html;

  let converted = 0;
  for (const [re, rep] of CONVERSIONS) {
    const m = html.match(re);
    if (m) { html = html.replace(re, rep); converted += m.length; }
  }
  totalOnclick += converted;

  // hash attribute-less inline <script> blocks
  const scripts = [];
  const re = /<script>([\s\S]*?)<\/script>/g;
  let mm;
  while ((mm = re.exec(html)) !== null) {
    const content = mm[1];
    const hash = createHash('sha256').update(content, 'utf8').digest('base64');
    scripts.push("'sha256-" + hash + "'");
  }
  totalHashes += scripts.length;

  const csp =
    "default-src 'self'; " +
    // 'self' scripts + first-party inline scripts (hashed) + Leaflet CDN
    // (index.html / rider.html load maps from unpkg). Self-hosting Leaflet
    // would let us drop the CDN origin — tracked as a hardening follow-up.
    "script-src 'self' " + scripts.join(' ') + " https://unpkg.com; " +
    "style-src 'self' 'unsafe-inline' https://unpkg.com; " +
    "img-src 'self' data: https:; " +
    "font-src 'self' data:; " +
    "connect-src 'self' https: wss:; " +
    "frame-ancestors 'none'; " +
    "object-src 'none'; " +
    "base-uri 'self'";
  const metaTag = `<meta http-equiv="Content-Security-Policy" content="${csp}" />`;

  if (/http-equiv="Content-Security-Policy"/.test(html)) {
    html = html.replace(/<meta[^>]*http-equiv="Content-Security-Policy"[^>]*>/i, metaTag);
  } else if (/<\/head>/.test(html)) {
    html = html.replace(/<\/head>/, `  ${metaTag}\n</head>`);
  } else if (/<meta\s+charset/i.test(html)) {
    html = html.replace(/(<meta\s+charset[^>]*>)/i, `$1\n  ${metaTag}`);
  } else {
    html = metaTag + '\n' + html;
  }

  const leftovers = (html.match(HANDLER_RE) || []).length;
  report.push({
    file: f.replace(ROOT + '/', ''),
    converted,
    inlineScripts: scripts.length,
    leftoverHandlers: leftovers,
    changed: html !== before,
  });

  if (!CHECK && html !== before) writeFileSync(f, html, 'utf8');
}

console.log('=== CSP strictification ' + (CHECK ? '(CHECK-ONLY)' : '(APPLIED)') + ' ===');
console.log('files scanned      :', files.length);
console.log('onclick converted  :', totalOnclick);
console.log('inline <script> hashed :', totalHashes);
console.log('--- per file ---');
for (const r of report) {
  console.log(
    `${r.file.padEnd(60)} conv=${String(r.converted).padEnd(2)} ` +
    `scripts=${String(r.inlineScripts).padEnd(2)} leftover=${r.leftoverHandlers} ` +
    `${r.changed ? 'written' : 'nochange'}`
  );
}
const bad = report.filter((r) => r.leftoverHandlers > 0);
if (bad.length) {
  console.log('\n⚠️  LEFTOVER inline handlers in:');
  for (const r of bad) console.log('   ', r.file, '(', r.leftoverHandlers, ')');
  process.exitCode = 2;
} else {
  console.log('\n✅ no leftover inline event-handler attributes.');
}
