// Inline <script> syntax smoke test for prototype HTML files.
// Extracts inline (non-src) script blocks and compiles each with vm.Script
// (compile-only; does NOT execute, so browser globals are irrelevant).
const fs = require('fs');
const vm = require('vm');

const files = process.argv.slice(2);
let totalErrors = 0;

for (const file of files) {
  const html = fs.readFileSync(file, 'utf8');
  // Match <script ...>...</script> blocks that have no src attribute.
  const re = /<script\b([^>]*)>([\s\S]*?)<\/script>/gi;
  let m, blocks = 0, errors = 0;
  while ((m = re.exec(html)) !== null) {
    const attrs = m[1] || '';
    if (/\bsrc\s*=/.test(attrs)) continue; // external script, skip
    if (/\btype\s*=\s*["']?(?:module|importmap)["']?/i.test(attrs)) continue;
    const code = m[2];
    if (!code.trim()) continue;
    blocks++;
    try {
      new vm.Script(code, { filename: file });
    } catch (e) {
      errors++;
      totalErrors++;
      console.log(`  ✗ ${file} block#${blocks}: ${e.message.split('\n')[0]}`);
    }
  }
  if (errors === 0) {
    console.log(`  ✓ ${file} — ${blocks} inline block(s), 0 syntax errors`);
  }
}

if (totalErrors > 0) {
  console.log(`\nRESULT: FAIL (${totalErrors} syntax error(s))`);
  process.exit(1);
} else {
  console.log(`\nRESULT: PASS`);
}
