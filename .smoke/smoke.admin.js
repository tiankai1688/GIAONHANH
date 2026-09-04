// End-to-end smoke test for app/admin.html (L console) using jsdom.
// Exercises: login -> all 13 screens switch -> per-screen render sanity ->
// rider detail drawer + disable/enable toggle -> coupon create -> agent approve ->
// settings save -> permission save -> notifications mark-all-read -> VI/ZH + dark toggle.
// Asserts 0 JS runtime errors.
const fs = require('fs');
const path = require('path');
const { JSDOM, VirtualConsole } = require('jsdom');

const APP_DIR = path.resolve(__dirname, '..', 'app');
const htmlPath = path.join(APP_DIR, 'admin.html');
const apiJsPath = path.join(APP_DIR, 'api.js');

let html = fs.readFileSync(htmlPath, 'utf8');
const apiJs = fs.readFileSync(apiJsPath, 'utf8');
// Inline api.js so jsdom doesn't need to fetch the external file
html = html.replace('<script src="api.js"></script>', '<script>\n' + apiJs + '\n</script>');

const errors = [];
const vc = new VirtualConsole();
vc.on('jsdomError', e => { errors.push('jsdomError: ' + (e && e.message ? e.message : e)); });
vc.on('error', (...a) => { errors.push('console.error: ' + a.join(' ')); });

const dom = new JSDOM(html, {
  runScripts: 'dangerously',
  resources: undefined,
  url: 'http://localhost/admin.html',
  pretendToBeVisual: true,
  virtualConsole: vc,
});
const { window } = dom;
const { document } = window;
window.addEventListener('error', e => errors.push('window.error: ' + (e.error ? e.error.stack : e.message)));
window.addEventListener('unhandledrejection', e => errors.push('unhandledrejection: ' + (e.reason && e.reason.message ? e.reason.message : e.reason)));

const wait = ms => new Promise(r => setTimeout(r, ms));
const $ = s => document.querySelector(s);
const $$ = s => Array.from(document.querySelectorAll(s));
const step = (name, ok, detail) => console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? ' — ' + detail : ''));
const goto = async p => { const n = $$('.nav-item').find(x => x.dataset.page === p); if (n) { n.click(); await wait(12); } return n; };

(async () => {
  await wait(60); // let init scripts settle

  // ---- 1. LOGIN (demo fallback, creds ignored) ----
  $('#loginBtn').click();
  await wait(40);
  const appShown = $('#app').style.display === 'flex' && $('#login').classList.contains('hide');
  step('L login -> console visible', appShown);

  // ---- 2. SWITCH ALL 13 SCREENS ----
  const pages = ['dashboard', 'merchants', 'orders', 'settlement', 'overview', 'riders', 'coupons', 'agents', 'settings', 'permissions', 'notifications', 'cover', 'emptystates'];
  let allOk = true;
  for (const p of pages) {
    const n = await goto(p);
    if (!n) { allOk = false; console.log('  no nav-item for ' + p); continue; }
    if (!$('#page-' + p).classList.contains('active')) allOk = false;
  }
  step('13 screens switch', allOk);

  // ---- 3. PER-SCREEN RENDER SANITY (new L screens) ----
  const checks = {
    overview: () => $('#overviewKpi').children.length > 0 && $('#overviewFunnel').children.length > 0,
    riders: () => $('#riderTableBody').children.length > 0,
    coupons: () => $('#couponTableBody').children.length > 0,
    agents: () => $('#agentTableBody').children.length > 0,
    settings: () => !!$('#lSetSubsidySw') && !!$('#lSetApiKey'),
    permissions: () => $('#permGrid').querySelectorAll('input[type=checkbox]').length > 0,
    notifications: () => $('#notifList').children.length > 0,
    cover: () => $('#coverStats').children.length > 0,
    emptystates: () => $('#page-emptystates').innerHTML.includes('es-card') || $('#page-emptystates').innerHTML.length > 80,
  };
  for (const p of Object.keys(checks)) {
    await goto(p);
    step('render ' + p, checks[p]());
  }

  // ---- 4. RIDER DETAIL DRAWER + DISABLE/ENABLE TOGGLE ----
  await goto('riders');
  const rrow = $('#riderTableBody').querySelector('tr[data-rider]');
  rrow.click();
  await wait(20);
  const drawerOpen = $('#drawer').classList.contains('show') && !!$('#riderChart');
  step('rider drawer opens w/ earnings chart', drawerOpen);

  const tbtn = $('#riderTableBody').querySelector('[data-rider-toggle]');
  const tId = tbtn ? tbtn.dataset.riderToggle : null;
  const before = tbtn ? tbtn.textContent.trim() : '';
  if (tbtn) { tbtn.click(); await wait(25); }
  const afterBtn = tId ? $('#riderTableBody').querySelector('[data-rider-toggle="' + tId + '"]') : null;
  const after = afterBtn ? afterBtn.textContent.trim() : '';
  step('rider disable/enable toggles label', !!tId && before !== after, before + ' -> ' + after);

  // ---- 5. PLATFORM COUPON CREATE ----
  await goto('coupons');
  const cBefore = $('#couponTableBody').children.length;
  $('#btnNewCoupon').click();
  await wait(20);
  const cDrawer = $('#drawer').classList.contains('show');
  $('#cpName').value = 'Mã test 20K';
  $('#cpValue').value = '20000';
  $('#cpSave').click();
  await wait(25);
  const cAfter = $('#couponTableBody').children.length;
  step('coupon create -> row appended', cDrawer && cAfter === cBefore + 1, cBefore + ' -> ' + cAfter);

  // ---- 6. AGENT APPROVE (backend attempted only when LIVE) ----
  await goto('agents');
  const aok = $('#agentTableBody').querySelector('[data-agent-ok]');
  let agentOk = false;
  if (aok) {
    const id = aok.dataset.agentOk;
    aok.click();
    await wait(45);
    const stillPending = !!$('#agentTableBody').querySelector('tr[data-agent="' + id + '"] [data-agent-ok]');
    agentOk = !stillPending;
    step('agent approve -> pending button gone', agentOk, 'agent ' + id);
  } else {
    step('agent approve', false, 'no pending agent button found');
  }

  // ---- 7. PLATFORM SETTINGS SAVE ----
  await goto('settings');
  const tBefore = $$('#toastWrap .toast').length;
  $('#btnSaveLSettings').click();
  await wait(20);
  step('L settings save -> toast', $$('#toastWrap .toast').length > tBefore);

  // ---- 8. PERMISSIONS SAVE ----
  await goto('permissions');
  const pBefore = $$('#toastWrap .toast').length;
  $('#btnSavePerm').click();
  await wait(20);
  step('permission save -> toast', $$('#toastWrap .toast').length > pBefore);

  // ---- 9. NOTIFICATIONS MARK-ALL-READ ----
  await goto('notifications');
  $('#btnMarkAllRead').click();
  await wait(20);
  const dotHidden = $('#navNotifDot').style.display === 'none' || $('#navNotifDot').textContent === '0';
  step('notifications mark-all-read -> dot cleared', dotHidden);

  // ---- 10. VI / ZH TOGGLE ----
  $$('#langPill button').find(b => b.dataset.lang === 'zh').click();
  await wait(20);
  const zhOk = document.documentElement.getAttribute('lang') === 'zh';
  step('L language -> 中文', zhOk);
  $$('#langPill button').find(b => b.dataset.lang === 'vi').click();
  await wait(12);

  // ---- 11. DARK TOGGLE ----
  const thB = document.documentElement.getAttribute('data-theme');
  $('#themeBtn').click();
  await wait(12);
  const darkOk = document.documentElement.getAttribute('data-theme') !== thB;
  step('L dark theme toggle', darkOk);

  // ---- 12. COVER OPEN LINK (anchor; not clicked to avoid jsdom navigation error) ----
  step('cover open link exists', !!$('#btnOpenCover'));

  // ---- SUMMARY ----
  console.log('\n==== ADMIN SMOKE RESULT ====');
  console.log('JS runtime errors captured: ' + errors.length);
  if (errors.length) errors.forEach(e => console.log('  - ' + e));
  console.log(errors.length === 0 ? 'SMOKE_PASS: 0 JS runtime errors' : 'SMOKE_FAIL: ' + errors.length + ' error(s)');
  process.exit(errors.length === 0 ? 0 : 2);
})().catch(e => {
  console.log('TEST_HARNESS_ERROR: ' + (e && e.stack ? e.stack : e));
  process.exit(3);
});
