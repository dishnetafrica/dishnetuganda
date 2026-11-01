// TEST ONLY — section G of tests/test_catalogue_direct.php (5.18.103, root docs/72 §G.5): drives the Uganda KYC form in
// direct mode (the uCRM catalogue switched on) in headless Chromium. Section G renders the pages from the judged tree into
// the run's own temporary folder and runs this file as
//   QFU_H_PAGES=<that folder> QFU_H_PORT=<a free port> NODE_PATH=<where playwright is> node g_kyc_form_browser.js
// It serves them through 5.18.102's own router, tests/quote_from_ucrm/h_router.php (php -S; a POST is echoed back as
// JSON), one page per mode:
//   cd_on     uCRM read: plans 6:12 "Residential Standard (TEST)" 100 and 7:17 "Business Plus (TEST)" 300; hardware, kits
//             first — 90 "Cable <b>"&' (TEST)" 75, 56 "Mini Kit (TEST)" 900, 55 "Standard Kit (TEST)" 1500 — then the
//             accessory 57 "Pipe Adapter (TEST)" 50; the installation 70 "Professional Installation" 200. Standard
//             quotations: 10 "Mini + Residential (TEST)" (6:12, 56 × 1, installation), 11 "Kit x2 + Business (TEST)"
//             (7:17, 55 × 2, 57 × 3, no installation), 12 disabled (an archived product in it). Wallet 10,000,000.
//   cd_stale  uCRM down, the last good list drawn (the same rows); Cash unavailable.
// The items' tokens are PHP's (cd_token()), handed over in expect.json beside the pages: this file only compares them, it
// never computes one. The posts of the two submitted sales are written beside the pages (posted_credit.json,
// posted_cash.json) for section G to submit through KycService::process. Prints PASS/FAIL per check and "N passed, M
// failed"; exits 0, 1 (a check failed) or 2 (it could not run). Every name, number and price is fictitious.
'use strict';
const { chromium } = require('playwright');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const PAGES = process.env.QFU_H_PAGES || '';
const PORT = parseInt(process.env.QFU_H_PORT || '0', 10);
if (!PAGES || !PORT) { console.error('QFU_H_PAGES and QFU_H_PORT are required'); process.exit(2); }
const ROUTER = path.join(__dirname, '..', 'quote_from_ucrm', 'h_router.php');
const BASE = `http://127.0.0.1:${PORT}/`;
const X = JSON.parse(fs.readFileSync(path.join(PAGES, 'expect.json'), 'utf8'));
const T = X.tokens;                                    // PHP's tokens, by item: p6_12, p7_17, k90, k56, k55, k57, inst
const joined = list => list.slice().sort().join(',');  // the posted form: once each, sorted, comma-joined
let pass = 0, fail = 0;
function ok(cond, name, extra) {
  if (cond) { pass++; console.log('PASS ' + name); }
  else { fail++; console.log('FAIL ' + name + (extra !== undefined ? '  -- got: ' + JSON.stringify(extra) : '')); }
}

async function open(browser, mode, confirmAnswer) {
  const page = await browser.newPage();
  const errors = [], dialogs = [];
  page.on('pageerror', e => errors.push(String(e && e.message || e)));
  page.on('dialog', async d => {
    dialogs.push({ type: d.type(), message: d.message() });
    if (d.type() === 'confirm') { if (confirmAnswer) await d.accept(); else await d.dismiss(); } else await d.accept();
  });
  let navs = 0;
  page.on('framenavigated', f => { if (f === page.mainFrame()) navs++; });
  await page.goto(BASE + '?mode=' + mode);
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(150);
  return { page, errors, dialogs, navs: () => navs };
}
const next = page => page.locator('.wiz-panel.active .wiz-btn-next').click();
const active = page => page.evaluate(() => +document.querySelector('.wiz-panel.active').dataset.panel);
async function fillStep2(page) {
  await page.fill('input[name="firstname"]', 'TEST');
  await page.fill('input[name="lastname"]', 'Direct');
  await page.fill('input[name="contacts[0][phone]"]', '+256700007101');
  await page.fill('input[name="address_1"]', 'Plot 1, Test Road');
}
async function toStep3(page) { await next(page); await fillStep2(page); await next(page); }
const badge = page => page.textContent('#osTotalBadge');
const seen = page => page.inputValue('#q102TotalSeen');
const items = page => page.inputValue('#q103ItemsSeen');
const cartOut = page => page.inputValue('#q103CartOut');
const cashDisabled = page => page.evaluate(() => document.querySelector('#wSalesType option[value="Cash"]').disabled);
const planChecked = page => page.evaluate(() => [...document.querySelectorAll('input[name="ucrm_plan"]:checked')].map(i => i.value));
const installChecked = page => page.evaluate(() => [...document.querySelectorAll('input[name="install_by"]:checked')].map(i => i.value));
const cart = page => page.evaluate(() => hwCart.map(i => [String(i.id), i.qty]));
async function pickInstall(page, v) { await page.locator(`#q102Install label:has(input[value="${v}"])`).click(); await page.waitForTimeout(50); }
async function pressStd(page, id) { await page.locator(`button.q103-std[data-std="${id}"]`).click(); await page.waitForTimeout(80); }
async function submit(page) {
  await page.check('#wTerms');
  const nav = page.waitForNavigation({ timeout: 2500 }).then(() => true, () => false);
  await page.click('#btnSubmit');
  const went = await nav;
  let posted = null;
  if (went) { try { posted = JSON.parse(await page.textContent('body')).posted; } catch (e) { posted = 'unparsable'; } }
  return { went, posted };
}
const LEGACY = ['package_choice', 'device_id', 'hw_cart_json', 'ucrm_links_seen'];

(async () => {
  const srv = spawn('php', ['-S', `127.0.0.1:${PORT}`, ROUTER],
                    { cwd: PAGES, stdio: 'ignore', env: Object.assign({}, process.env, { QFU_H_PAGES: PAGES }) });
  for (let i = 0; i < 50; i++) {                                 // wait until the router answers (at most 5 s)
    await new Promise(r => setTimeout(r, 100));
    try { const r = await fetch(BASE + '?mode=cd_on'); if (r.status === 200) break; } catch (e) { /* not up yet */ }
  }
  const browser = await chromium.launch();
  try {
    // ── 1. The direct page as it opens: nothing preselected, uCRM's rows, no Fiber, the standard quotations ────────
    {
      const { page, errors, dialogs, navs } = await open(browser, 'cd_on', false);
      ok(errors.length === 0, '1.0 no script error on load', errors);
      const types = await page.evaluate(() => [...document.querySelectorAll('input[type="radio"][name="customer_type"]')].map(i => i.value));
      ok(JSON.stringify(types) === JSON.stringify(['StarLink', 'LTE']), '1.1 step 1 offers Starlink and DishNet 4G — no Fiber card', types);
      await toStep3(page);
      ok(await active(page) === 3, '1.2 reached step 3');
      ok((await planChecked(page)).length === 0 && (await cart(page)).length === 0 && await page.evaluate(() => selPlan === null),
        '1.3 nothing preselected: no ucrm_plan checked, the cart empty (no keyword defaults in direct mode)', [await planChecked(page), await cart(page)]);
      ok(await cartOut(page) === '[]' && await seen(page) === '' && await items(page) === '',
        '1.4 ucrm_cart_json "[]", ucrm_total_seen and ucrm_items_seen empty', [await cartOut(page), await seen(page), await items(page)]);
      const vis = await page.evaluate(() => ({
        hw: [...document.querySelectorAll('.hw-row')].filter(r => getComputedStyle(r).display !== 'none').map(r => r.dataset.id),
        plans: [...document.querySelectorAll('.pkg-opt')].filter(r => getComputedStyle(r).display !== 'none').map(r => r.dataset.id) }));
      ok(JSON.stringify(vis) === JSON.stringify({ hw: ['90', '56', '55', '57'], plans: ['6:12', '7:17'] }),
        '1.5 shown: the hardware, kits (by price) before the accessory; the two offered plans', vis);
      const stds = await page.evaluate(() => [...document.querySelectorAll('button.q103-std')].map(b => [b.dataset.std, b.disabled]));
      ok(JSON.stringify(stds) === JSON.stringify([['10', false], ['11', false], ['12', true]]), '1.6 three standard quotation buttons; 12 disabled', stds);
      const nav0 = navs();
      await page.locator('button.q103-std[data-std="12"]').click({ force: true });
      await page.waitForTimeout(80);
      ok((await planChecked(page)).length === 0 && (await cart(page)).length === 0, '1.7 pressing the disabled one fills nothing');
      const cableText = await page.textContent('.hw-row[data-id="90"]');
      ok(cableText.includes('Cable <b>"&\' (TEST)') && await page.evaluate(() => document.querySelectorAll('#wHardwareList b').length) === 0,
        '1.8 uCRM\'s name with <b>"&\' is shown as text, never markup', cableText);
      ok(dialogs.length === 0 && navs() === nav0, '1.9 no dialog; pressing the disabled one navigates nowhere', [dialogs, navs() - nav0]);
      await page.close();
    }

    // ── 2. A standard quotation with the installation: plan, cart, "Who installs?" answered; never submitted ───────
    {
      const { page, errors, dialogs, navs } = await open(browser, 'cd_on', false);
      await toStep3(page);
      const nav0 = navs();
      await pressStd(page, 10);
      ok(JSON.stringify(await planChecked(page)) === '["6:12"]' && await page.evaluate(() => selPlan && selPlan.id) === '6:12',
        '2.1 "Mini + Residential (TEST)": its plan selected (ucrm_plan 6:12)', await planChecked(page));
      ok(JSON.stringify(await cart(page)) === JSON.stringify([['56', 1]]) && await cartOut(page) === '[{"p":56,"q":1}]',
        '2.2 … the cart set to its hardware: Mini Kit × 1; ucrm_cart_json [{"p":56,"q":1}]', [await cart(page), await cartOut(page)]);
      ok(JSON.stringify(await installChecked(page)) === '["dishnet"]', '2.3 … "Who installs?" answered DishNet (it includes the installation)', await installChecked(page));
      // 100 + 900 + 200
      ok(await badge(page) === 'UGX 1200.00' && await seen(page) === '1200.00' && !(await cashDisabled(page)),
        '2.4 … total 1,200 = plan 100 + Mini 900 + installation 200; ucrm_total_seen 1200.00; Cash enabled', [await badge(page), await seen(page)]);
      ok(await items(page) === joined([T.p6_12, T.k56, T.inst]), '2.5 … ucrm_items_seen = the plan\'s, the Mini\'s and the installation\'s tokens, sorted, comma-joined',
        [await items(page), joined([T.p6_12, T.k56, T.inst])]);
      ok(await active(page) === 3 && navs() === nav0 && dialogs.length === 0, '2.6 … never submits: still on step 3, no navigation, no confirm', [await active(page), navs() - nav0, dialogs]);
      const note = await page.textContent('#q103StdNote');
      ok(await page.isVisible('#q103StdNote') && note.includes('Filled from "Mini + Residential (TEST)"') && !note.includes('choose who installs'),
        '2.7 … the note says it was filled, and that nothing is submitted until the agent submits', note);
      ok(errors.length === 0, '2.8 no script error', errors);
      await page.close();
    }

    // ── 3. A standard quotation without the installation: the question left open; then the totals ────────────────
    {
      const { page, errors, dialogs, navs } = await open(browser, 'cd_on', false);
      await toStep3(page);
      const nav0 = navs();
      await pickInstall(page, 'dishnet');                          // an answer given before is cleared, not kept
      await pressStd(page, 11);
      ok(JSON.stringify(await planChecked(page)) === '["7:17"]' && JSON.stringify(await cart(page)) === JSON.stringify([['55', 2], ['57', 3]]),
        '3.1 "Kit x2 + Business (TEST)": plan 7:17, Standard Kit × 2, Pipe Adapter × 3', [await planChecked(page), await cart(page)]);
      ok(await cartOut(page) === '[{"p":55,"q":2},{"p":57,"q":3}]', '3.2 … ucrm_cart_json carries the ids and quantities only', await cartOut(page));
      ok((await installChecked(page)).length === 0, '3.3 … "Who installs?" left open (it does not include the installation)', await installChecked(page));
      ok(await badge(page) === 'not priced' && await seen(page) === '' && await items(page) === '' && await cashDisabled(page),
        '3.4 … unanswered: no total, ucrm_total_seen and ucrm_items_seen empty, Cash disabled', [await badge(page), await seen(page), await items(page)]);
      const cn = await page.textContent('#q102CashNote');
      ok(cn.includes('Cash is not possible') && cn.includes('choose who installs in step 3'), '3.5 … the Cash note says why', cn);
      ok((await page.textContent('#q103StdNote')).includes('choose who installs'), '3.6 … and the standard quotation\'s note asks for it');
      await pickInstall(page, 'customer');
      // 300 + 1500 × 2 + 50 × 3
      ok(await badge(page) === 'UGX 3450.00' && await seen(page) === '3450.00' && !(await cashDisabled(page)),
        '3.7 Customer installs: total 3,450 = plan 300 + 1,500 × 2 + 50 × 3', [await badge(page), await seen(page)]);
      ok(await items(page) === joined([T.p7_17, T.k55, T.k57]), '3.8 … ucrm_items_seen: the plan\'s and the two items\' tokens, sorted (not in the order chosen)',
        [await items(page), joined([T.p7_17, T.k55, T.k57])]);
      await pickInstall(page, 'dishnet');
      ok(await seen(page) === '3650.00' && await items(page) === joined([T.p7_17, T.k55, T.k57, T.inst]),
        '3.9 DishNet installs: 3,650 (the installation 200 added), its token added', [await seen(page), await items(page)]);
      await page.locator('.hw-row[data-id="90"]').click();
      ok(await seen(page) === '3725.00' && await items(page) === joined([T.p7_17, T.k55, T.k57, T.inst, T.k90]),
        '3.10 the Cable (75) added by hand: 3,725, its token added', [await seen(page), await items(page)]);
      const cartTitle = await page.evaluate(() => document.querySelectorAll('#hwCartRows b, #osLines b').length);
      ok(cartTitle === 0 && (await page.textContent('#hwCartRows')).includes('Cable <b>"&\' (TEST)'), '3.11 … its name is text in the cart, never markup', cartTitle);
      ok(navs() === nav0 && dialogs.length === 0 && errors.length === 0, '3.12 never submitted; no dialog; no script error', [navs() - nav0, dialogs, errors]);
      await page.close();
    }

    // ── 4. The quantity stops at 99, the server's limit ──────────────────────────────────────────────────────────
    {
      const { page, errors, dialogs } = await open(browser, 'cd_on', false);
      await toStep3(page);
      await page.evaluate(() => { const r = document.querySelector('.hw-row[data-id="56"]'); for (let i = 0; i < 99; i++) hwAdd(r); });
      ok(JSON.stringify(await cart(page)) === JSON.stringify([['56', 99]]) && dialogs.length === 0, '4.1 Mini Kit added 99 times: quantity 99, no alert', await cart(page));
      await page.locator('.hw-row[data-id="56"]').click();
      ok(JSON.stringify(await cart(page)) === JSON.stringify([['56', 99]]) && dialogs.some(d => d.type === 'alert' && d.message.includes('At most 99 of one item')),
        '4.2 the hundredth press: still 99, with "At most 99 of one item on a sale."', [await cart(page), dialogs]);
      await page.locator('#hwCartRows button.hw-qty-btn:has-text("+")').click();
      ok(JSON.stringify(await cart(page)) === JSON.stringify([['56', 99]]) && await cartOut(page) === '[{"p":56,"q":99}]',
        '4.3 the quantity box\'s + at 99: still 99; ucrm_cart_json q 99', [await cart(page), await cartOut(page)]);
      await page.locator('#hwCartRows button.hw-qty-btn:has-text("−")').click();
      ok(JSON.stringify(await cart(page)) === JSON.stringify([['56', 98]]), '4.4 control: − takes it to 98', await cart(page));
      ok(errors.length === 0, '4.5 no script error', errors);
      await page.close();
    }

    // ── 5. Submit is blocked with no plan ─────────────────────────────────────────────────────────────────────────
    {
      const { page, errors, dialogs } = await open(browser, 'cd_on', true);
      await toStep3(page);
      await page.locator('.hw-row[data-id="56"]').click();
      await pickInstall(page, 'customer');
      await next(page); await next(page);
      ok(await active(page) === 5, '5.1 no plan chosen: steps 3 and 4 still advance', await active(page));
      const r = await submit(page);
      ok(!r.went && dialogs.some(d => d.type === 'alert' && d.message === 'Please select a service plan before submitting.') && !dialogs.some(d => d.type === 'confirm'),
        '5.2 Submit refused with "Please select a service plan before submitting." — nothing posted, no confirm', [r.went, dialogs]);
      ok(errors.length === 0, '5.3 no script error', errors);
      await page.close();
    }

    // ── 6. A Credit sale from a standard quotation, submitted: what the browser posts ────────────────────────────
    {
      const { page, errors, dialogs } = await open(browser, 'cd_on', true);
      await toStep3(page);
      await pressStd(page, 10);
      await next(page);
      ok(await active(page) === 4 && await page.inputValue('#wSalesType') === 'Credit', '6.1 step 4, Credit (the default)');
      await next(page);
      const r = await submit(page);
      ok(r.went && dialogs.filter(d => d.type === 'confirm').length === 1, '6.2 submitted after the confirm', [r.went, dialogs]);
      const p = r.posted || {};
      ok(p.ucrm_quote_form === '5.18.103-direct' && p.ucrm_plan === '6:12' && p.ucrm_cart_json === '[{"p":56,"q":1}]' && p.install_by === 'dishnet',
        '6.3 posted: the marker 5.18.103-direct, ucrm_plan 6:12, ucrm_cart_json [{"p":56,"q":1}], install_by dishnet',
        { m: p.ucrm_quote_form, plan: p.ucrm_plan, cart: p.ucrm_cart_json, i: p.install_by });
      ok(p.ucrm_total_seen === '1200.00' && p.ucrm_items_seen === joined([T.p6_12, T.k56, T.inst]) && p.sales_type === 'Credit',
        '6.4 … ucrm_total_seen 1200.00 and ucrm_items_seen the drawn tokens of the selection', { t: p.ucrm_total_seen, s: p.ucrm_items_seen });
      ok(LEGACY.every(k => !(k in p)), '6.5 … and no package_choice, device_id, hw_cart_json or ucrm_links_seen at all', LEGACY.filter(k => k in p));
      fs.writeFileSync(path.join(PAGES, 'posted_credit.json'), JSON.stringify(p));
      ok(errors.length === 0, '6.6 no script error', errors);
      await page.close();
    }

    // ── 7. A Cash sale: a standard quotation, DishNet installs, submitted ─────────────────────────────────────────
    {
      const { page, errors, dialogs } = await open(browser, 'cd_on', true);
      await toStep3(page);
      await pressStd(page, 11);
      await pickInstall(page, 'dishnet');
      await next(page);
      await page.selectOption('#wSalesType', 'Cash');
      await next(page);
      ok((await page.textContent('#wReviewContent')).includes('UGX 3650.00'), '7.1 step 5 shows the one total, 3,650');
      const r = await submit(page);
      const p = r.posted || {};
      ok(r.went && p.sales_type === 'Cash' && p.ucrm_total_seen === '3650.00' && p.ucrm_items_seen === joined([T.p7_17, T.k55, T.k57, T.inst])
        && p.ucrm_cart_json === '[{"p":55,"q":2},{"p":57,"q":3}]' && p.ucrm_plan === '7:17' && LEGACY.every(k => !(k in p)),
        '7.2 Cash, priced: posted with ucrm_total_seen 3650.00, the four tokens, the cart by id, no legacy field', { went: r.went, p });
      fs.writeFileSync(path.join(PAGES, 'posted_cash.json'), JSON.stringify(p));
      ok(errors.length === 0 && dialogs.filter(d => d.type === 'alert').length === 0, '7.3 no script error, no alert', [errors, dialogs]);
      await page.close();
    }

    // ── 8. The last good list (uCRM down): the rows drawn, Cash unavailable, Credit still goes in ─────────────────
    {
      const { page, errors, dialogs } = await open(browser, 'cd_stale', true);
      ok(await page.isVisible('#q103Banner') && (await page.textContent('#q103Banner')).includes('uCRM could not be read — the list may be out of date; Cash is unavailable; Credit registrations still go in.'),
        '8.1 the banner');
      ok(await cashDisabled(page), '8.2 Cash disabled from the start');
      await toStep3(page);
      const vis = await page.evaluate(() => [...document.querySelectorAll('.hw-row')].filter(r => getComputedStyle(r).display !== 'none').map(r => r.dataset.id));
      ok(JSON.stringify(vis) === JSON.stringify(['90', '56', '55', '57']), '8.3 the last good list\'s hardware is drawn', vis);
      await pressStd(page, 10);
      ok(JSON.stringify(await planChecked(page)) === '["6:12"]' && JSON.stringify(await installChecked(page)) === '["dishnet"]',
        '8.4 a standard quotation still fills the order', [await planChecked(page), await installChecked(page)]);
      ok(await cashDisabled(page) && await seen(page) === '' && await items(page) === '' && await badge(page) === 'not priced',
        '8.5 … but no total, nothing seen, Cash disabled — the list is not uCRM\'s now', [await badge(page), await seen(page), await items(page)]);
      await next(page);
      await page.evaluate(() => { const s = document.getElementById('wSalesType'); s.value = 'Cash'; });   // forced past the disabled option
      await next(page);
      let r = await submit(page);
      ok(!r.went && dialogs.some(d => d.type === 'alert' && /Cash is not possible/.test(d.message)), '8.6 Cash forced: refused at submit', dialogs);
      await page.evaluate(() => { const s = document.getElementById('wSalesType'); s.value = 'Credit'; });
      dialogs.length = 0;
      r = await submit(page);
      ok(r.went && r.posted && r.posted.sales_type === 'Credit' && r.posted.ucrm_total_seen === '' && r.posted.ucrm_plan === '6:12',
        '8.7 a Credit registration still submits (ucrm_total_seen empty)', r.posted && { s: r.posted.sales_type, t: r.posted.ucrm_total_seen });
      ok(errors.length === 0, '8.8 no script error', errors);
      await page.close();
    }
  } finally {
    await browser.close();
    srv.kill();
  }
  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
