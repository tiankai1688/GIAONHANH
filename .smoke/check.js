// Extract inline <script> blocks (those WITHOUT a src attribute) and run node --check on the combined source.
const fs = require('fs');
const { execSync } = require('child_process');
const path = require('path');

const htmlPath = path.resolve(process.argv[2]);
const html = fs.readFileSync(htmlPath, 'utf8');

// Match <script ...>...</script> that have NO src attribute
const re = /<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g;
let m, parts = [];
while ((m = re.exec(html)) !== null) { parts.push(m[1]); }

const combined = parts.join('\n;\n');
const outPath = path.join(__dirname, 'inline.js');
fs.writeFileSync(outPath, combined);

try {
  execSync('node --check "' + outPath + '"', { stdio: 'inherit' });
  console.log('NODE_CHECK_OK: extracted ' + parts.length + ' inline script block(s), syntax valid.');
} catch (e) {
  console.log('NODE_CHECK_FAIL');
  process.exit(1);
}
