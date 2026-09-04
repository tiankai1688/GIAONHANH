const fs = require('fs');
const vm = require('vm');
const path = process.argv[2];
const html = fs.readFileSync(path, 'utf8');
let ok = true, i = 0;
let idx = 0;
while ((idx = html.indexOf('<script', idx)) !== -1) {
  const endTag = html.indexOf('>', idx);
  if (endTag === -1) break;
  const open = html.slice(idx, endTag + 1);
  const close = html.indexOf('</script>', endTag);
  if (close === -1) break;
  const code = html.slice(endTag + 1, close);
  idx = close + 9;
  // skip external scripts
  if (/\bsrc\s*=/.test(open)) continue;
  i++;
  try {
    new vm.Script(code, { filename: path + ' #inline' + i });
    console.log('OK  inline script #' + i + '  (' + code.length + ' chars)');
  } catch (e) {
    ok = false;
    console.error('FAIL inline script #' + i + ': ' + e.message);
  }
}
console.log(i + ' inline script(s) checked. ' + (ok ? 'ALL OK' : 'HAS ERRORS'));
process.exit(ok ? 0 : 1);
