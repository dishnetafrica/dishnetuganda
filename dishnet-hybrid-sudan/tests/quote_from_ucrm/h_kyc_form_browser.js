// TEST ONLY — section H of tests/test_quote_from_ucrm.php (5.18.102, docs/71 §L.1 "the form, in a browser"): drives the
// Uganda KYC form (gate on) in headless Chromium. Section H renders the pages from the judged tree into the run's own
// temporary folder and runs this file as
//   QFU_H_PAGES=<that folder> QFU_H_PORT=<a free port> NODE_PATH=<where playwright is> node h_kyc_form_browser.js
// It serves them through h_router.php (php -S, beside this file), one page per mode:
//   new_ug_on        catalogue read; plans 1 (250000), 2 (180000.50) priced, 3 Business Priority unpriced;
//                    kits 101 Mini (1100000), 102 Standard (2499000) priced, 103 Router Mesh unpriced, 106 HTML title;
//                    the installation 150000. Wallet 10,000,000.
//                    5.18.102 D14: every item through its link; the plugin rows are named "… (plugin)" and drawn with
//                    uCRM's names ("Residential", "Starlink Mini Kit", …); the installation's linked product is
//                    "Installation by DishNet (TEST)" (links: plan 1 = 1, kit 101 = 5, kit 102 = 6, installation = 9)
//   new_ug_lowwallet the same, wallet 100
//   new_ug_unread    products answer 503 -> the banner
//   new_ug_noinst    the installation not linked (a product named Professional Installation listed all the same)
//   new_ug_neutral   5.18.102 D14 review (D14-UI-5): plan 1 and kit 101 linked to products named "Standard Plan (TEST)"
//                    and "Dish Kit (TEST)" (no "residential", no "mini"); plan 2 and kit 102 unlinked
// Checks: the unanswered question stops step 3 with its message; the hidden question never blocks Submit; Cash is
// disabled (and refused at submit) while unpriced; one total across step 3, step 5, ucrm_total_seen and the wallet
// check; the answer cleared on a service / connection change; Fiber unpriced; names escaped. Prints PASS/FAIL per check
// and "N passed, M failed"; exits 0, 1 (a check failed) or 2 (it could not run). Every name, number and address is
// fictitious.
'use strict';
const { chromium } = require('playwright');
const { spawn } = require('child_process');
const path = require('path');

const DIR = __dirname;
const PAGES = process.env.QFU_H_PAGES || '';
const PORT = parseInt(process.env.QFU_H_PORT || '0', 10);
if (!PAGES || !PORT) { console.error('QFU_H_PAGES and QFU_H_PORT are required'); process.exit(2); }
const BASE = `http://127.0.0.1:${PORT}/`;
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
  await page.goto(BASE + '?mode=' + mode);
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(150);
  return { page, errors, dialogs };
}
const next = page => page.locator('.wiz-panel.active .wiz-btn-next').click();
const active = page => page.evaluate(() => +document.querySelector('.wiz-panel.active').dataset.panel);
async function fillStep2(page) {
  await page.fill('input[name="firstname"]', 'TEST');
  await page.fill('input[name="lastname"]', 'Customer');
  await page.fill('input[name="contacts[0][phone]"]', '+256700000000');
  await page.fill('input[name="address_1"]', 'Plot 1, Test Road');
}
const badge = page => page.textContent('#osTotalBadge');
const seen = page => page.inputValue('#q102TotalSeen');
const cashDisabled = page => page.evaluate(() => document.querySelector('#wSalesType option[value="Cash"]').disabled);
async function pickInstall(page, v) { await page.locator(`#q102Install label:has(input[value="${v}"])`).click(); await page.waitForTimeout(50); }
async function toStep3(page) { await next(page); await fillStep2(page); await next(page); }
async function submit(page) {
  await page.check('#wTerms');
  const nav = page.waitForNavigation({ timeout: 2500 }).then(() => true, () => false);
  await page.click('#btnSubmit');
  const went = await nav;
  let posted = null;
  if (went) { try { posted = JSON.parse(await page.textContent('body')).posted; } catch (e) { posted = 'unparsable'; } }
  return { went, posted };
}

(async () => {
  const srv = spawn('php', ['-S', `127.0.0.1:${PORT}`, path.join(DIR, 'h_router.php')],
                    { cwd: PAGES, stdio: 'ignore', env: Object.assign({}, process.env, { QFU_H_PAGES: PAGES }) });
  for (let i = 0; i < 50; i++) {                                 // wait until the router answers (at most 5 s)
    await new Promise(r => setTimeout(r, 100));
    try { const r = await fetch(BASE + '?mode=new_ug_on'); if (r.status === 200) break; } catch (e) { /* not up yet */ }
  }
  const browser = await chromium.launch();
  try {
    // ── 1. Starlink + New Connection: the question, the one total, Cash ───────────────────────────────────────
    {
      const { page, errors, dialogs } = await open(browser, 'new_ug_on', true);
      ok(errors.length === 0, '1.0 no script error on load', errors);
      await toStep3(page);
      ok(await active(page) === 3, '1.1 reached step 3');
      ok(await page.isVisible('#q102Install'), '1.2 "Who installs?" shown for Starlink + New Connection');
      const st = await page.evaluate(() => [...document.querySelectorAll('input[name="install_by"]')].map(i => ({ v: i.value, c: i.checked, d: i.disabled, r: i.required || i.hasAttribute('required') })));
      ok(JSON.stringify(st) === JSON.stringify([{ v: 'dishnet', c: false, d: false, r: false }, { v: 'customer', c: false, d: false, r: false }]),
        '1.3 two options, nothing preselected, enabled, never required', st);
      ok((await page.textContent('#q102Install')).includes('DishNet installs') && (await page.textContent('#q102Install')).includes('Customer installs (kit only)'), '1.4 labels');
      // 5.18.102 D14 (docs/71 §R.5): step 3's rows are drawn with uCRM's names, never the plugin's "(plugin)" ones
      const rowsText = [await page.textContent('.pkg-opt[data-id="1"]'), await page.textContent('.hw-row[data-id="101"]')];
      ok(rowsText[0].includes('Residential') && rowsText[1].includes('Starlink Mini Kit') && !rowsText.join(' ').includes('(plugin)'),
        '1.4b step 3: the plan and the kit are shown by uCRM\'s names ("Residential", "Starlink Mini Kit"), not the plugin\'s', rowsText);
      ok(await badge(page) === 'not priced' && await seen(page) === '' && await cashDisabled(page), '1.5 unanswered: no total, ucrm_total_seen empty, Cash disabled',
        [await badge(page), await seen(page), await cashDisabled(page)]);
      ok((await page.textContent('#osLines')).includes('Choose who installs above to see the total.'), '1.5b … the step-3 summary says why');
      await next(page);
      ok(await active(page) === 3, '1.6 Continue with no answer: step 3 does not advance');
      ok(await page.isVisible('#q102InstallErr') && (await page.textContent('#q102InstallErr')).trim() === 'Choose who installs', '1.7 … "Choose who installs" shown');
      const border = await page.evaluate(() => getComputedStyle(document.getElementById('q102InstallBox')).borderTopColor);
      ok(border === 'rgb(220, 53, 69)', '1.8 … red border', border);

      await pickInstall(page, 'dishnet');
      // Mini Kit 1,100,000 + Starlink Residential 250,000 + Professional Installation 150,000
      ok(await badge(page) === 'UGX 1500000.00' && await seen(page) === '1500000.00' && !(await cashDisabled(page)),
        '1.9 DishNet installs: total 1,500,000 (installation line added), ucrm_total_seen 1500000.00, Cash enabled', [await badge(page), await seen(page)]);
      ok(!(await page.isVisible('#q102InstallErr')), '1.10 the message clears once answered');
      // 5.18.102 D14 (rewritten on purpose, §R.2.4): its label comes from the installation's link
      ok((await page.textContent('#osLines')).includes('Installation by DishNet (TEST)') && !(await page.textContent('#osLines')).includes('Professional Installation'),
        '1.11 the installation line is in the step-3 summary, by its linked product\'s name', await page.textContent('#osLines'));
      await pickInstall(page, 'customer');
      ok(await badge(page) === 'UGX 1350000.00' && await seen(page) === '1350000.00', '1.12 Customer installs: no installation line, total 1,350,000', [await badge(page), await seen(page)]);
      const os = await page.textContent('#osLines');
      ok(os.includes('Starlink Mini Kit') && os.includes('Residential') && !os.includes('(plugin)'), '1.12b the order summary names uCRM\'s products, not the plugin\'s rows', os);

      // an unpriced row makes the total unpriced; it adds nothing
      await page.locator('.hw-row[data-id="103"]').click();
      ok(await badge(page) === 'not priced' && await seen(page) === '' && await cashDisabled(page), '1.13 Router Mesh (not a uCRM product) added: no total, Cash disabled');
      ok((await page.textContent('#hwCartRows')).includes('not priced in uCRM'), '1.14 … its cart line reads "not priced in uCRM"');
      const note = await page.textContent('#q102CashNote');
      // 5.18.102 D14 (rewritten on purpose, §R.4.1): the link's reason, and the note's short tail
      ok(note.includes('Cash is not possible') && note.includes('Router Mesh is not linked to a uCRM product yet') && note.endsWith('. Register as Credit, or ask an admin.')
        && !note.includes('fix the product'), '1.15 … the Cash note gives the reason, and ends "Register as Credit, or ask an admin."', note);
      await page.locator('#hwCartRows button:has-text("✕")').nth(1).click();
      ok(await badge(page) === 'UGX 1350000.00' && !(await cashDisabled(page)), '1.16 removed again: priced, Cash enabled');

      // a priced second kit, qty 2 (Standard 2,499,000 × 2) — the one total across step 3, ucrm_total_seen, step 5
      await page.locator('.hw-row[data-id="102"]').click();
      await page.locator('.hw-row[data-id="102"]').click();
      const exp = 1100000 + 2 * 2499000 + 250000;
      ok(await badge(page) === 'UGX ' + exp.toFixed(2) && await seen(page) === exp.toFixed(2), '1.17 Standard Kit ×2 added: step 3 total and ucrm_total_seen ' + exp, [await badge(page), await seen(page)]);
      const planCellPrices = await page.evaluate(() => [...document.querySelectorAll('.pkg-opt')].filter(p => p.dataset.type === 'starlink').map(p => p.dataset.price));
      ok(JSON.stringify(planCellPrices) === JSON.stringify(['250000', '180000.5', '0']), '1.18 plan rows carry uCRM\'s price (not customer_price 99/88/77), 0 for the unpriced one', planCellPrices);
      ok(!(await page.textContent('#wPackageList')).includes('UGX 99.00'), '1.19 the plugin\'s customer_price is not drawn');

      await next(page);                                            // → step 4
      ok(await active(page) === 4, '1.20 answered: step 3 advances');
      await page.selectOption('#wSalesType', 'Cash');
      await next(page);                                            // → step 5
      ok(await active(page) === 5, '1.21 step 5');
      const review = await page.textContent('#wReviewContent');
      ok(review.includes('Today Total') && review.includes('UGX ' + exp.toFixed(2)), '1.22 step 5 review: the same total', review.slice(-400));
      ok(review.includes('Customer installs (kit only)'), '1.23 step 5 shows the installation answer');
      ok(review.includes('Starlink Mini Kit') && review.includes('Starlink Standard Kit') && review.includes('Residential') && !review.includes('(plugin)'),
        '1.23b step 5 names uCRM\'s products, not the plugin\'s rows', review.slice(0, 600));
      const r = await submit(page);
      const conf = dialogs.filter(d => d.type === 'confirm');
      ok(r.went && conf.length === 1, '1.24 Cash, priced, wallet 10,000,000: submitted after the confirm', [r.went, dialogs]);
      ok(r.posted && r.posted.ucrm_total_seen === exp.toFixed(2) && r.posted.install_by === 'customer' && r.posted.sales_type === 'Cash',
        '1.25 POST carries ucrm_total_seen ' + exp.toFixed(2) + ', install_by=customer, sales_type=Cash', r.posted && { t: r.posted.ucrm_total_seen, i: r.posted.install_by, s: r.posted.sales_type });
      ok(r.posted && JSON.parse(r.posted.hw_cart_json || '[]').length === 2, '1.26 … and the cart (the posting hw_cart_json is the in-panel #hwCartJson)', r.posted && r.posted.hw_cart_json);
      // 5.18.102 D14 (§R.5, §R.4.7, §R.4.2): what is posted — uCRM's names, the selection's link records, the form's marker
      const titles = r.posted ? JSON.parse(r.posted.hw_cart_json || '[]').map(i => i.title) : null;
      ok(r.posted && JSON.stringify(titles) === JSON.stringify(['Starlink Mini Kit', 'Starlink Standard Kit']) && r.posted.selected_plan_name === 'Residential'
        && r.posted.ucrm_links_seen === '1,5,6' && r.posted.ucrm_quote_form === '5.18.102',
        '1.26b … hw_cart_json\'s titles and selected_plan_name are uCRM\'s names; ucrm_links_seen 1,5,6 (plan, two kits); ucrm_quote_form 5.18.102',
        r.posted && { titles, plan: r.posted.selected_plan_name, links: r.posted.ucrm_links_seen, marker: r.posted.ucrm_quote_form });
      ok(errors.length === 0, '1.27 no script error in the whole journey', errors);
      await page.close();
    }

    // ── 2. Shifting Connection: the hidden question never blocks Submit; nothing posted for it ─────────────────
    {
      const { page, errors, dialogs } = await open(browser, 'new_ug_on', true);
      await page.locator('label:has(input[name="connectivity_type"][value="Shifting Connection"])').click();
      await toStep3(page);
      ok(!(await page.isVisible('#q102Install')), '2.1 Shifting Connection: no question');
      const dis = await page.evaluate(() => [...document.querySelectorAll('input[name="install_by"]')].every(i => i.disabled && !i.checked && !i.required));
      ok(dis, '2.2 … its inputs disabled, unchecked, not required');
      ok(await badge(page) === 'UGX 1350000.00', '2.3 … total without installation (kit + plan)', await badge(page));
      await next(page); await next(page);
      ok(await active(page) === 5, '2.4 … steps 3 and 4 advance');
      const r = await submit(page);
      ok(r.went && dialogs.filter(d => d.type === 'confirm').length === 1 && dialogs.filter(d => d.type === 'alert').length === 0,
        '2.5 … Submit is not blocked (native validation and kycFormSubmit pass)', [r.went, dialogs]);
      ok(r.posted && !('install_by' in r.posted) && r.posted.ucrm_total_seen === '1350000.00' && r.posted.ucrm_links_seen === '1,5',
        '2.6 … no install_by posted; ucrm_total_seen 1350000.00; ucrm_links_seen 1,5 (no installation link)',
        r.posted && { i: r.posted.install_by, t: r.posted.ucrm_total_seen, l: r.posted.ucrm_links_seen });
      ok(errors.length === 0, '2.7 no script error', errors);
      await page.close();
    }

    // ── 3. The answer is cleared on a service or connection change ──────────────────────────────────────────────
    {
      const { page, errors } = await open(browser, 'new_ug_on', false);
      await toStep3(page);
      await pickInstall(page, 'dishnet');
      ok(await seen(page) === '1500000.00', '3.1 answered DishNet');
      await page.evaluate(() => wizGo(1));
      await page.locator('label:has(input[name="customer_type"][value="Fiber"])').click();
      ok(await page.evaluate(() => document.querySelector('input[name="install_by"][value="dishnet"]').checked) === false, '3.2 Fiber chosen: the answer is cleared');
      await page.locator('label:has(input[name="customer_type"][value="StarLink"])').click();
      ok(await page.evaluate(() => [...document.querySelectorAll('input[name="install_by"]')].some(i => i.checked)) === false, '3.3 back to Starlink: unanswered again');
      await page.evaluate(() => wizGo(3));
      await pickInstall(page, 'customer');
      await page.evaluate(() => wizGo(1));
      await page.locator('label:has(input[name="connectivity_type"][value="Ownership Change"])').click();
      await page.locator('label:has(input[name="connectivity_type"][value="New Connection"])').click();
      ok(await page.evaluate(() => [...document.querySelectorAll('input[name="install_by"]')].some(i => i.checked)) === false, '3.4 connection type changed and back: unanswered again');
      ok(await seen(page) === '' && await cashDisabled(page), '3.5 … no total, Cash disabled until answered');
      // submit-time check: jump to step 5 with the question unanswered
      await page.evaluate(() => wizGo(5));
      await page.check('#wTerms');
      const al = [];
      page.removeAllListeners('dialog');
      page.on('dialog', async d => { al.push(d.message()); await d.dismiss(); });
      await page.click('#btnSubmit');
      await page.waitForTimeout(300);
      ok(al.length === 1 && /Choose who installs/.test(al[0]), '3.6 kycFormSubmit checks the question again (alert, no confirm)', al);
      ok(errors.length === 0, '3.7 no script error', errors);
      await page.close();
    }

    // ── 4. Fiber on Uganda: unpriced, no fiber_install_fee ─────────────────────────────────────────────────────
    {
      const { page, errors } = await open(browser, 'new_ug_on', false);
      await page.locator('label:has(input[name="customer_type"][value="Fiber"])').click();
      ok(await page.evaluate(() => typeof _kycFiberInstallFee) === 'undefined', '4.1 _kycFiberInstallFee is not on the page');
      ok(await badge(page) === 'not priced' && await cashDisabled(page), '4.2 Fiber: no total, Cash disabled', await badge(page));
      ok((await page.textContent('#osLines')).includes('Fiber is not priced from uCRM on this installation'), '4.3 … with the Fiber reason');
      ok(!(await page.textContent('#hwCartRows')).includes('Installation Fee'), '4.4 … no Fiber installation-fee line');
      ok(errors.length === 0, '4.5 no script error', errors);
      await page.close();
    }

    // ── 5. A selected unpriced plan; Cash refused at submit ────────────────────────────────────────────────────
    {
      const { page, errors, dialogs } = await open(browser, 'new_ug_on', true);
      await toStep3(page);
      await pickInstall(page, 'customer');                         // priced: Cash enabled
      await page.evaluate(() => wizGo(4));
      await page.selectOption('#wSalesType', 'Cash');              // Cash chosen while priced
      await page.evaluate(() => wizGo(3));
      await page.locator('.pkg-opt[data-id="3"]').click();         // Business Priority: product/service plan prices differ
      ok(await page.inputValue('#wSalesType') === 'Cash', '5.0 Cash stays selected (never switched silently)');
      ok(await badge(page) === 'not priced' && await cashDisabled(page), '5.1 unpriced plan selected: no total, Cash disabled');
      ok((await page.textContent('#wPackageList')).includes('not priced in uCRM'), '5.2 the plan row reads "not priced in uCRM"');
      await page.evaluate(() => wizGo(5));
      const r = await submit(page);
      ok(!r.went && dialogs.some(d => d.type === 'alert' && /Cash is not possible/.test(d.message) && d.message.endsWith('Register as Credit (step 4), or ask an admin.'))
        && !dialogs.some(d => d.type === 'confirm'),
        '5.3 Cash still selected: kycFormSubmit refuses with an alert ending "Register as Credit (step 4), or ask an admin.", nothing posted', dialogs);
      ok((await page.textContent('#wReviewContent')).includes('No total from uCRM'), '5.4 the review says there is no total');
      ok(errors.length === 0, '5.5 no script error', errors);
      await page.close();
    }

    // ── 6. The wallet check uses the one total ────────────────────────────────────────────────────────────────
    {
      const { page, dialogs } = await open(browser, 'new_ug_lowwallet', true);
      await toStep3(page);
      await pickInstall(page, 'dishnet');
      const total = await seen(page);
      await page.evaluate(() => wizGo(4));
      await page.selectOption('#wSalesType', 'Cash');
      await page.evaluate(() => wizGo(5));
      const r = await submit(page);
      const a = dialogs.find(d => d.type === 'alert');
      ok(!r.went && a && a.message.includes('Required: UGX ' + total) && a.message.includes('Insufficient wallet balance'),
        '6.1 wallet 100: refused, "Required" is the one total (' + total + ', installation included)', dialogs);
      await page.close();
    }

    // ── 7. uCRM unreadable: banner, Cash disabled, Credit still goes in ─────────────────────────────────────────
    {
      const { page, errors, dialogs } = await open(browser, 'new_ug_unread', true);
      ok(await page.isVisible('#q102Banner') && (await page.textContent('#q102Banner')).includes("uCRM's prices could not be read — Cash sales are not possible until it can; Credit registrations still go in"),
        '7.1 the banner');
      ok(await cashDisabled(page), '7.2 Cash disabled');
      await toStep3(page);
      await pickInstall(page, 'customer');
      ok(await badge(page) === 'not priced', '7.3 no total');
      await page.evaluate(() => wizGo(4));
      ok(await page.inputValue('#wSalesType') === 'Credit', '7.4 Credit selected (the default)');
      await page.evaluate(() => wizGo(5));
      const r = await submit(page);
      ok(r.went && r.posted && r.posted.sales_type === 'Credit' && r.posted.ucrm_total_seen === '' && r.posted.install_by === 'customer',
        '7.5 a Credit registration still submits (ucrm_total_seen empty)', r.posted && { s: r.posted.sales_type, t: r.posted.ucrm_total_seen });
      ok(errors.length === 0, '7.6 no script error', errors);
      await page.close();
    }

    // ── 8. No installation product: DishNet option says so; choosing it makes the total unpriced ────────────────
    {
      const { page } = await open(browser, 'new_ug_noinst', false);
      await toStep3(page);
      ok((await page.textContent('#q102Install')).includes('Installation not priced in uCRM — no quotation, Cash not possible'), '8.1 the DishNet option reads the unpriced text');
      await pickInstall(page, 'dishnet');
      ok(await badge(page) === 'not priced' && await cashDisabled(page), '8.2 choosing it: no total, Cash disabled');
      await pickInstall(page, 'customer');
      ok(await badge(page) === 'UGX 1350000.00' && !(await cashDisabled(page)), '8.3 Customer installs: priced again');
      await page.close();
    }

    // ── 9. Names from rows are escaped ────────────────────────────────────────────────────────────────────────
    {
      const { page } = await open(browser, 'new_ug_on', false);
      await toStep3(page);
      await page.locator('.hw-row[data-id="106"]').click();
      const injected = await page.evaluate(() => document.querySelectorAll('#osLines b, #hwCartRows b').length);
      ok(injected === 0 && (await page.textContent('#hwCartRows')).includes('Cable <b>Pro</b>'), '9.1 a title with <b> renders as text in the cart and summary', injected);
      await page.close();
    }

    // ── 10. 5.18.102 D14 review (D14-UI-5): the default plan and kit by the row's own name, never uCRM's ────────────
    {
      const { page, errors } = await open(browser, 'new_ug_neutral', false);
      await toStep3(page);
      const d = await page.evaluate(() => ({ plan: (document.querySelector('input[name=package_choice]:checked') || {}).value || null,
                                             cart: hwCart.map(c => String(c.id)) }));
      ok(d.plan === '1' && JSON.stringify(d.cart) === '["101"]',
        '10.1 the linked plan 1 and kit 101, named in uCRM without "residential" / "mini", are still the defaults (their own names carry the keywords), never the unlinked plan 2 / kit 102', d);
      await pickInstall(page, 'customer');
      const r = [await seen(page), await cashDisabled(page), errors];
      ok(r[0] === '1350000.00' && r[1] === false && errors.length === 0, '10.2 … so the form opens priced: 1,350,000, Cash enabled, no script error', r);
      await page.close();
    }
  } finally {
    await browser.close();
    srv.kill();
  }
  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
