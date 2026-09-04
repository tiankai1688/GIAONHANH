// End-to-end smoke test for app/merchant-web.html using jsdom.
// Exercises: login -> 5 screen switches -> change price -> accept -> ready -> settlement/export -> VI/ZH toggle -> dark toggle -> add product fallback.
// Asserts 0 JS runtime errors.
const fs = require('fs');
const path = require('path');
const { JSDOM, VirtualConsole } = require('jsdom');

const APP_DIR = path.resolve(__dirname, '..', 'app');
const htmlPath = path.join(APP_DIR, 'merchant-web.html');
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
  url: 'http://localhost/merchant-web.html',
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

(async () => {
  await wait(60); // let init scripts settle

  // ---- 1. LOGIN ----
  const loginVisibleBefore = !$('#login').classList.contains('hide');
  $('#loginBtn').click();
  await wait(40);
  const appShown = $('#app').style.display === 'flex' && $('#login').classList.contains('hide');
  step('login -> console visible', appShown);

  // ---- 2. SWITCH THROUGH 5 SCREENS ----
  const pages = ['dashboard', 'products', 'orders', 'settlement', 'settings'];
  let allPagesOk = true;
  for (const p of pages) {
    const nav = $$('.nav-item').find(n => n.dataset.page === p);
    nav.click();
    await wait(15);
    const active = $('#page-' + p).classList.contains('active');
    if (!active) allPagesOk = false;
  }
  step('5 screens switch', allPagesOk);

  // ---- 3. CHANGE PRICE (products) ----
  $$('.nav-item').find(n => n.dataset.page === 'products').click();
  await wait(15);
  const priceInput = $('[data-price]');
  const beforeVal = priceInput.value;
  priceInput.value = '55000';
  priceInput.dispatchEvent(new window.Event('change', { bubbles: true }));
  await wait(20);
  const toastAfterPrice = $$('#toastWrap .toast').length > 0;
  step('change price -> handler ran + toast', toastAfterPrice, 'input="' + beforeVal + '"->"' + priceInput.value + '"');

  // ---- 4. ACCEPT ORDER (orders) ----
  $$('.nav-item').find(n => n.dataset.page === 'orders').click();
  await wait(15);
  const acceptBtn = $('[data-accept]');
  let acceptOk = false;
  if (acceptBtn) {
    const no = acceptBtn.dataset.accept;
    acceptBtn.click();
    await wait(25);
    const row = $$('#orderTableBody tr').find(tr => tr.dataset.order === no);
    const pillTxt = row ? row.querySelector('.pill').textContent : '';
    acceptOk = /Đã nhận|已接单|accepted/i.test(pillTxt);
    step('accept order -> status updated', acceptOk, no + ' -> ' + pillTxt);
  } else {
    step('accept order', false, 'no accept button found');
  }

  // ---- 5. READY ORDER (the one just accepted shows a ready button) ----
  let readyOk = false;
  const readyBtn = $('[data-ready]');
  if (readyBtn) {
    const no = readyBtn.dataset.ready;
    readyBtn.click();
    await wait(25);
    const row = $$('#orderTableBody tr').find(tr => tr.dataset.order === no);
    const pillTxt = row ? row.querySelector('.pill').textContent : '';
    readyOk = /Đã lấy hàng|已取货|picked/i.test(pillTxt);
    step('ready order -> status updated', readyOk, no + ' -> ' + pillTxt);
  } else {
    step('ready order', false, 'no ready button found (expected one accepted order)');
  }

  // ---- 6. SETTLEMENT + EXPORT ----
  $$('.nav-item').find(n => n.dataset.page === 'settlement').click();
  await wait(15);
  const settleRendered = $('#settleTableBody').children.length > 0 && $('#settlePayable').textContent.includes('₫');
  step('settlement page rendered', settleRendered);
  const toastsBefore = $$('#toastWrap .toast').length;
  $('#btnExport').click();
  await wait(25);
  const exportOk = $$('#toastWrap .toast').length > toastsBefore;
  step('export statement -> toast', exportOk);

  // ---- 7. VI / ZH TOGGLE ----
  $$('#langPill button').find(b => b.dataset.lang === 'zh').click();
  await wait(20);
  const zhOk = document.documentElement.getAttribute('lang') === 'zh'
    && $('#loginBtn') && /登录/.test($('#loginBtn').textContent);
  step('language -> 中文', zhOk, 'lang=' + document.documentElement.getAttribute('lang'));
  // back to VI
  $$('#langPill button').find(b => b.dataset.lang === 'vi').click();
  await wait(15);

  // ---- 8. DARK TOGGLE ----
  const themeBefore = document.documentElement.getAttribute('data-theme');
  $('#themeBtn').click();
  await wait(15);
  const darkOk = document.documentElement.getAttribute('data-theme') === (themeBefore === 'light' ? 'dark' : 'light');
  step('dark theme toggle', darkOk, themeBefore + ' -> ' + document.documentElement.getAttribute('data-theme'));

  // ---- 9. ADD PRODUCT (demo fallback, P2 gap) ----
  $$('.nav-item').find(n => n.dataset.page === 'products').click();
  await wait(15);
  const rowsBefore = $$('#prodTableBody tr[data-id]').length;
  $('#btnAddProduct').click();
  await wait(20);
  const drawerOpen = $('#drawer').classList.contains('show');
  $('#npName').value = 'Món mới test';
  $('#npPrice').value = '25000';
  $('#npSave').click();
  await wait(25);
  const rowsAfter = $$('#prodTableBody tr[data-id]').length;
  const hasP2 = $$('#prodTableBody .add-badge').length > 0;
  step('add product -> row appended (demo)', rowsAfter === rowsBefore + 1 && hasP2 && drawerOpen === false || (rowsAfter === rowsBefore + 1 && hasP2),
    'rows ' + rowsBefore + '->' + rowsAfter + ', P2 badge=' + hasP2);

  // ---- 10. ORDER DETAIL (B.10) — open A1 (paid) and chain accept->ready->ready->deliver ----
  $$('.nav-item').find(n => n.dataset.page === 'orders').click();
  await wait(15);
  const detRow = $$('#orderTableBody tr[data-order]').find(tr => tr.dataset.order === 'GN20260731A1');
  if (detRow) detRow.click();
  await wait(20);
  const odRendered = $('#orderDetailBody').children.length > 0 && !!$('#orderDetailBody .timeline');
  step('order-detail renders (A1, paid)', odRendered);
  if ($('#mAccept')) { $('#mAccept').click(); await wait(25); }
  const afterAccept = !!$('#mReady');
  step('order accept -> ready btn appears', afterAccept);
  if ($('#mReady')) { $('#mReady').click(); await wait(25); }
  if ($('#mReady')) { $('#mReady').click(); await wait(25); }
  const afterDeliver = !!$('#mDeliver');
  step('order ready x2 -> deliver btn appears', afterDeliver);
  if ($('#mDeliver')) { $('#mDeliver').click(); await wait(25); }
  const delivered = !$('#mDeliver') && /Đã giao|已送达|delivered/i.test($('#orderDetailBody').textContent);
  step('order deliver -> delivered', delivered);

  // ---- 11. PRODUCT EDIT SAVE (B.11) ----
  $$('.nav-item').find(n => n.dataset.page === 'product-edit').click();
  await wait(15);
  const pBefore = $$('#prodTableBody tr[data-id]').length;
  $('#mpNameVi').value = 'Món test edit';
  $('#mpPrice').value = '30000';
  $('#btnSaveMProduct').click();
  await wait(25);
  const pAfter = $$('#prodTableBody tr[data-id]').length;
  step('product-edit save -> row appended (demo)', pAfter === pBefore + 1, pBefore + ' -> ' + pAfter);

  // ---- 12. MERCHANT COUPON CREATE (B.12) ----
  $$('.nav-item').find(n => n.dataset.page === 'm-coupons').click();
  await wait(15);
  const mcBefore = $('#mCouponTableBody').children.length;
  $('#btnNewMCoupon').click();
  await wait(20);
  const mcDrawer = $('#drawer').classList.contains('show');
  $('#mcName').value = 'Test M Coupon';
  $('#mcValue').value = '10000';
  $('#mcSave').click();
  await wait(25);
  const mcAfter = $('#mCouponTableBody').children.length;
  step('merchant coupon create -> row appended', mcDrawer && mcAfter === mcBefore + 1, mcBefore + ' -> ' + mcAfter);

  // ---- 13. MERCHANT DATA DASHBOARD (B.13) ----
  $$('.nav-item').find(n => n.dataset.page === 'm-data').click();
  await wait(15);
  const mdataOk = $('#mDataKpi').children.length > 0 && $('#mDataChart').children.length > 0 && $('#mDataTop').children.length > 0;
  step('m-data dashboard rendered', mdataOk);

  // ---- SUMMARY ----
  console.log('\n==== SMOKE RESULT ====');
  console.log('JS runtime errors captured: ' + errors.length);
  if (errors.length) { errors.forEach(e => console.log('  - ' + e)); }
  console.log(errors.length === 0 ? 'SMOKE_PASS: 0 JS runtime errors' : 'SMOKE_FAIL: ' + errors.length + ' error(s)');
  process.exit(errors.length === 0 ? 0 : 2);
})().catch(e => {
  console.log('TEST_HARNESS_ERROR: ' + (e && e.stack ? e.stack : e));
  process.exit(3);
});
