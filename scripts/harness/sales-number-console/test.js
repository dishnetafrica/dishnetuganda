// test.js CTL SCRIPT — TEST ONLY (scripts/harness/sales-number-console): drives scripts/console/sales-number-on.console.js
// in headless Chromium (Playwright) against driver.php's sandbox: every way the script can stop, the switch-on itself read
// back from the sandbox's book and its fake Evolution, and what the script prints. Everything is fictitious.
const { chromium } = require('playwright');
const fs = require('fs');
const [ctl, scriptPath] = process.argv.slice(2);
const SW = process.env.SW === 'allow' ? 'allow' : 'block';   // the plugin registers a service worker: run once with it, once without
const sleep = ms => new Promise(r => setTimeout(r, ms));
let pass = 0, fail = 0, cmdN = 0;
const check = (name, cond, detail) => { if (cond) { pass++; console.log('  ok   ' + name); } else { fail++; console.log('  FAIL ' + name + (detail !== undefined ? '  — ' + JSON.stringify(detail) : '')); } };
const cmd = async o => { cmdN++; fs.writeFileSync(`${ctl}/cmd-${cmdN}.json`, JSON.stringify(o)); const f = `${ctl}/res-${cmdN}.json`; for (let i = 0; i < 1200 && !fs.existsSync(f); i++) await sleep(50); return JSON.parse(fs.readFileSync(f, 'utf8')); };
const src = fs.readFileSync(scriptPath, 'utf8');
const scriptFor = (channel, assistant) => {
  const a = /^  const CHANNEL   = '';/m, b = /^  const ASSISTANT = 'leave';/m;
  if (src.split(a).length !== 2 || src.split(b).length !== 2) throw new Error('the option lines are not found once each');
  return src.replace(a, `  const CHANNEL   = '${channel}';`).replace(b, `  const ASSISTANT = '${assistant}';`);
};
(async () => {
  for (let i = 0; i < 1200 && !fs.existsSync(`${ctl}/ready.json`); i++) await sleep(100);
  const ready = JSON.parse(fs.readFileSync(`${ctl}/ready.json`, 'utf8'));
  const base = ready.base, origin = new URL(base).origin, cardUrl = `${base}?page=dashboard&tab=wa_ai_setup`;
  check('setup: three numbers added switched off, departments verified', ready.setup.length === 6 && ready.setup.every(x => x.startsWith('ok: ')), ready.setup);
  const st0 = ready.state.channels;
  check('setup: sales-001..003 disabled and unverified', ['sales-001', 'sales-002', 'sales-003'].every(k => st0[k] && st0[k].status === 'disabled' && !st0[k].verified_at), st0);
  const browser = await chromium.launch();
  // signed in through the page itself: the session cookie is Secure, and only the browser sends it to 127.0.0.1 over http
  const login = async (p, email) => { await p.goto(`${base}?page=login`); await p.fill('input[name="identifier"]', email); await p.fill('input[name="password"]', 'sj-password-1');
    await Promise.all([p.waitForNavigation(), p.locator('form button[type="submit"], form input[type="submit"]').first().click()]); };
  const ctx = await browser.newContext({ serviceWorkers: SW });
  const page = await ctx.newPage();
  await login(page, 'admin@example.test');
  let posts = [], logs = [], dialogs = [], answers = [];
  page.on('request', rq => { if (rq.method() === 'POST') posts.push(rq.postData() || ''); });
  page.on('console', m => logs.push(m.text()));
  page.on('dialog', async d => { dialogs.push(d.message()); const yes = answers.length ? answers.shift() : true; if (yes) await d.accept(); else await d.dismiss(); });
  // With the service worker blocked, the page's requests can be answered here: nothing off 127.0.0.1 (the page's CDN styles),
  // and one planted answer at a time — `plant` = { when: (request) => bool, answer: async (route) => {…} }.
  let plant = null;
  if (SW === 'block') await page.route('**/*', async route => {
    const rq = route.request(), u = new URL(rq.url());
    if (u.hostname !== '127.0.0.1') return route.abort();
    if (plant && rq.url() === cardUrl && plant.when(rq)) return plant.answer(route);
    return route.continue();
  });
  const openFramed = async () => { await page.goto(origin + '/crm-outer.html'); await page.frameLocator('#f').locator('#sales-numbers').waitFor({ state: 'attached' }); };
  const openTop = async () => { await page.goto(cardUrl); await page.locator('#sales-numbers').waitFor({ state: 'attached' }); };
  const run = async (channel, assistant, ans) => { posts = []; logs = []; dialogs = []; answers = (ans || []).slice(); await page.evaluate(scriptFor(channel, assistant || 'leave')); await sleep(150); return logs.join('\n'); };
  const fields = b => Object.fromEntries(new URLSearchParams(b));
  const acts = () => posts.map(fields).map(x => x.wa_action + (x.status ? ':' + x.status : ''));
  const noLeak = out => !/Alpha|Bravo|bravo-off|Seller|sn-new|kla-|sn-three|Sales —/.test(out) && !/\d{5,}/.test(out);
  const chan = async id => (await cmd({ do: 'state' })).channels[id];
  let out, st;

  console.log('C0 not on the card\'s page');
  await page.goto(`${base}?page=dashboard&tab=leads`);
  out = await run('', 'leave');
  check('C0 STOP, the card is not on this page; nothing sent, no question asked', /STOP — the card "Salesperson numbers" is not on this page/.test(out) && posts.length === 0 && dialogs.length === 0, out);

  console.log('C1 several numbers off, none named');
  await openFramed();
  out = await run('', 'leave');
  check('C1 STOP, lists the three by id and state only; nothing sent, no question', /more than one number is not switched on/.test(out) && ['sales-001: disabled', 'sales-002: disabled', 'sales-003: disabled'].every(x => out.includes(x)) && posts.length === 0 && dialogs.length === 0 && noLeak(out), out);

  console.log('C2 CHANNEL is not an id on the card: an unknown id, a name, a phone number');
  out = await run('sales-009', 'leave');
  check('C2 an unknown id: STOP, the ids listed, what was typed not shown; nothing sent', /is not the id of a number on the card/.test(out) && /sales-001, sales-002, sales-003/.test(out) && !out.includes('sales-009') && posts.length === 0 && dialogs.length === 0, out);
  out = await run('Sales — Alpha Two', 'leave');
  check('C2 a name typed: STOP, and the name is nowhere in the output', /is not the id of a number on the card/.test(out) && noLeak(out) && posts.length === 0 && dialogs.length === 0, out);
  out = await run('+256700000701', 'leave');
  check('C2 a phone number typed: STOP, and no run of its digits is in the output', /is not the id of a number on the card/.test(out) && noLeak(out) && posts.length === 0 && dialogs.length === 0, out);

  console.log('C3 Cancel at the first question');
  out = await run('sales-001', 'leave', [false]);
  check('C3 the question names the number on the person\'s own screen, and that no number is on record yet', dialogs.length === 1 && dialogs[0].includes('Sales — Alpha Two') && dialogs[0].includes('(sales-001)') && /Switch ON/.test(dialogs[0]) && /Number on record: none yet/.test(dialogs[0]), dialogs);
  check('C3 Cancel: nothing sent, and the console line names nobody', /you pressed Cancel\. Nothing was sent/.test(out) && posts.length === 0 && noLeak(out), out);
  st = await chan('sales-001');
  check('C3 the book is as before', st.status === 'disabled' && !st.verified_at, st);

  console.log('C4 already running on this page (the flag is the plugin page\'s, whichever console context pastes)');
  await page.frames()[1].evaluate(() => { window.__dnSalesNumberBusy = true; });
  out = await run('sales-001', 'leave');
  check('C4 STOP, already running; nothing sent, no question, and the other run\'s flag left standing', /already running on this page/.test(out) && posts.length === 0 && dialogs.length === 0 && await page.frames()[1].evaluate(() => window.__dnSalesNumberBusy === true), out);
  await page.frames()[1].evaluate(() => { window.__dnSalesNumberBusy = false; });

  console.log('C5 a number whose phone is not paired; then one Evolution no longer lists');
  out = await run('sales-002', 'leave');
  check('C5 one request (Verify number), the plugin\'s refusal printed, then STOP', acts().join() === 'sn_verify' && fields(posts[0]).channel_id === 'sales-002' && /1 Verify number: NOT OK — Not connected yet/.test(out) && /STOP after 1 request\(s\)\. The number is NOT switched on/.test(out) && noLeak(out), out);
  st = await chan('sales-002');
  check('C5 the number is still off and unverified', st.status === 'disabled' && !st.verified_at, st);
  await cmd({ do: 'instances', drop: ['kla-bravo-off'] });
  out = await run('sales-002', 'leave');
  check('C5 its instance not listed: the plugin\'s answer names the instance, the output does not — not even a part of it (its name is a part of its instance\'s)', acts().join() === 'sn_verify' && /1 Verify number: NOT OK — Evolution does not report the instance \[its instance\]/.test(out) && noLeak(out), out);
  await cmd({ do: 'instances' });

  console.log('C6 the session lost while the page stays open; the connection lost');
  await ctx.clearCookies();
  out = await run('sales-003', 'leave');
  check('C6 session lost: STOP before anything is sent — the card is not shown when asked again', /the plugin did not show the card: sign in again/.test(out) && posts.length === 0 && dialogs.length === 0, out);
  const pl = await ctx.newPage(); await login(pl, 'admin@example.test'); await pl.close();
  await ctx.setOffline(true);
  out = await run('sales-003', 'leave');
  check('C6 connection lost: STOP, nothing sent, and it does not say "sign in again"', /STOP/.test(out) && /Nothing was sent/.test(out) && !/sign in again/.test(out) && (SW === 'allow' ? /could not be read \(HTTP 503\)/.test(out) : true) && posts.length === 0 && dialogs.length === 0, out);
  await ctx.setOffline(false);

  console.log('C7 the card\'s own refusals, read before anything is sent');
  await cmd({ do: 'flag', on: false });
  out = await run('sales-001', 'leave');
  check('C7 registry off: STOP, nothing sent', /the channel registry is off/.test(out) && posts.length === 0 && dialogs.length === 0, out);
  await cmd({ do: 'flag', on: true });
  await cmd({ do: 'staff', who: 'bravo', active: false });
  const b7 = await cmd({ do: 'state' });
  out = await run('sales-003', 'leave');
  const a7 = await cmd({ do: 'state' });
  check('C7 its owner not an active staff member: STOP, nothing sent, no question, the book and Evolution as before', /owner is not an active staff member/.test(out) && posts.length === 0 && dialogs.length === 0 && noLeak(out) && JSON.stringify(a7) === JSON.stringify(b7), out);
  await cmd({ do: 'staff', who: 'bravo', active: true });

  console.log('C8 the real thing, framed as uCRM frames it');
  const before = await cmd({ do: 'state' });
  out = await run('sales-001', 'leave');
  console.log(out.split('\n').map(l => '      | ' + l).join('\n'));
  const f = posts.map(fields);
  check('C8 two questions: the number, then the phone found — its last two digits', dialogs.length === 2 && /Number on record: none yet/.test(dialogs[0]) && /the number ending ••••01/.test(dialogs[1]) && !/\d{3,}/.test(dialogs[1]), dialogs);
  check('C8 exactly three requests, in the card\'s order, each this number\'s with the page\'s token', acts().join() === 'sn_verify,sn_webhook,sn_status:active' && f.every(x => x.channel_id === 'sales-001' && /^\d{8}:[0-9a-f]{40}$/.test(x._csrf)), acts());
  check('C8 no other field rides along (no retire, no owner, no pause)', f.length === 3 && Object.keys(f[0]).sort().join() === '_csrf,channel_id,wa_action' && Object.keys(f[1]).sort().join() === '_csrf,channel_id,wa_action' && Object.keys(f[2]).sort().join() === '_csrf,channel_id,reason,status,wa_action' && f[2].reason === '', f.map(x => Object.keys(x)));
  check('C8 the three answers OK and DONE', /1 Verify number: OK/.test(out) && /2 Register webhook: OK/.test(out) && /3 Switch on: OK/.test(out) && /DONE, 3 request\(s\) sent\. Now: sales-001: active · assistant on · connected · number verified, ending ••••01/.test(out), out);
  check('C8 the output names nobody: no name, no instance, no more than two digits of a number', noLeak(out) && /ending ••••01/.test(out), out);
  const after = await cmd({ do: 'state' });
  st = after.channels;
  check('C8 the book: sales-001 active, verified, its number Evolution\'s', st['sales-001'].status === 'active' && !!st['sales-001'].verified_at && /700000701$/.test(st['sales-001'].business_number || ''), st['sales-001']);
  check('C8 Evolution holds the webhook for its instance, and only its', Object.keys(after.webhooks).join() === 'sn-new' && after.webhooks['sn-new'].startsWith('https://plugin.example.test/public.php?page=evo_webhook&token='), Object.keys(after.webhooks));
  check('C8 the other numbers untouched; no message sent', ['sales-002', 'sales-003', 'sales', 'support', 'account'].every(k => JSON.stringify(st[k]) === JSON.stringify(before.channels[k])) && after.text_calls === before.text_calls);

  console.log('C9 Cancel at the second question: the phone found is not the person\'s');
  out = await run('sales-003', 'leave', [true, false]);
  check('C9 one request (Verify number), then STOP at the number\'s last two digits', dialogs.length === 2 && /the number ending ••••02/.test(dialogs[1]) && acts().join() === 'sn_verify' && /you pressed Cancel at the number ending ••••02\. It is verified but NOT switched on/.test(out) && noLeak(out), out);
  const s9 = await cmd({ do: 'state' });
  check('C9 the book: verified, still switched off; no webhook registered for it', s9.channels['sales-003'].status === 'disabled' && !!s9.channels['sales-003'].verified_at && Object.keys(s9.webhooks).join() === 'sn-new', [s9.channels['sales-003'].status, Object.keys(s9.webhooks)]);

  await cmd({ do: 'instances', jid: { 'sn-three': '123456789012345@lid' } });
  out = await run('sales-003', 'leave');
  check('C9 then Evolution reports its owner with no phone number: STOP before any question — the card\'s own words, not "another phone"; nothing sent', /Evolution now reports no phone number for it \(a WhatsApp @lid\)/.test(out) && /pair the phone again, then verify/.test(out) && !/ANOTHER/.test(out) && posts.length === 0 && dialogs.length === 0 && noLeak(out.replace('ending ••••02', '')), out);
  await cmd({ do: 'instances' });

  console.log('C10 pasted again on the same, stale page');
  out = await run('sales-001', 'leave');
  check('C10 nothing to do, nothing sent, no question', /Nothing to do: it is already switched on and its assistant is on/.test(out) && posts.length === 0 && dialogs.length === 0, out);

  console.log('C11 a phone paired again: Evolution reports another number for a number that is on, then for one that is off');
  await cmd({ do: 'instances', owner: { 'sn-new': '256700000711' } });
  out = await run('sales-001', 'leave');
  check('C11 on, with the card\'s warning: STOP, nothing sent, and it is not called verified', /it is switched on, but its row on the card says Evolution now reports another number/.test(out) && /NO LONGER CONFIRMED/.test(out) && !/number verified/.test(out) && !/nothing automated is sent/.test(out) && /may still be answering/.test(out) && posts.length === 0 && dialogs.length === 0, out);
  await cmd({ do: 'form', fields: { wa_action: 'sn_status', channel_id: 'sales-001', status: 'disabled' } });
  out = await run('sales-001', 'leave', [true, false]);
  check('C11 off, with the warning: the first question says so and names the number on record; Cancel at the new phone\'s digits', dialogs.length === 2 && /Number on record: ••••01 — the card says Evolution now reports ANOTHER phone/.test(dialogs[0]) && /the number ending ••••11/.test(dialogs[1]) && acts().join() === 'sn_verify' && /NOT switched on/.test(out), [dialogs, out]);
  st = await chan('sales-001');
  check('C11 the book: still switched off', st.status === 'disabled', st);
  out = await run('sales-001', 'leave');
  check('C11 pasted again and both questions... the number on record is now the phone\'s, so one question; three requests; on', dialogs.length === 1 && /Number on record: ••••11\./.test(dialogs[0]) && acts().join() === 'sn_verify,sn_webhook,sn_status:active' && /DONE, 3 request/.test(out) && noLeak(out), [dialogs, out]);
  st = await chan('sales-001');
  check('C11 the book: active, its number the phone Evolution reports now', st.status === 'active' && /700000711$/.test(st.business_number || ''), st);

  console.log('C12 the only number left off, picked without being named; the page not framed');
  await cmd({ do: 'form', fields: { wa_action: 'sn_status', channel_id: 'sales-002', status: 'retired', confirm: 'yes' } });
  await openTop();
  out = await run('', 'leave');
  check('C12 it asks about sales-003 once (its number already on record), then three requests and DONE', dialogs.length === 1 && dialogs[0].includes('(sales-003)') && /Number on record: ••••02\./.test(dialogs[0]) && acts().join() === 'sn_verify,sn_webhook,sn_status:active' && posts.map(fields).every(x => x.channel_id === 'sales-003') && /DONE, 3 request\(s\) sent\. Now: sales-003: active/.test(out) && noLeak(out), out);
  st = (await cmd({ do: 'state' })).channels;
  check('C12 the book: sales-003 active and verified; the retired number still retired', st['sales-003'].status === 'active' && !!st['sales-003'].verified_at && st['sales-002'].status === 'retired', [st['sales-003'].status, st['sales-002'].status]);

  console.log('C13 the assistant');
  const off = await cmd({ do: 'form', fields: { wa_action: 'sn_ai', channel_id: 'sales-003', value: '0' } });
  out = await run('sales-003', 'leave');
  check('C13 assistant off, not asked for: nothing to do, nothing sent', off.answer.startsWith('ok: ') && /to turn its assistant on, set ASSISTANT/.test(out) && posts.length === 0 && dialogs.length === 0, out);
  out = await run('sales-003', 'on');
  check('C13 asked for: one question, one request (the assistant\'s own form), DONE', dialogs.length === 1 && /Let the assistant answer customers/.test(dialogs[0]) && posts.length === 1 && fields(posts[0]).wa_action === 'sn_ai' && fields(posts[0]).value === '1' && fields(posts[0]).channel_id === 'sales-003' && /4 Let the assistant answer: OK/.test(out) && /DONE, 1 request\(s\) sent\. Now: sales-003: active · assistant on/.test(out) && noLeak(out), out);
  st = await chan('sales-003');
  check('C13 the book: the assistant is on for sales-003', String(st.ai_enabled) === '1', st);

  console.log('C14 every number on');
  out = await run('', 'leave');
  check('C14 says so, nothing sent', /Every salesperson number on the card is already switched on/.test(out) && posts.length === 0 && dialogs.length === 0, out);

  const offAgain = () => cmd({ do: 'form', fields: { wa_action: 'sn_status', channel_id: 'sales-003', status: 'disabled' } });
  if (SW === 'block') {
    const isAct = a => rq => rq.method() === 'POST' && fields(rq.postData() || '').wa_action === a;
    console.log('C15 a department gap written on the card (planted in the page the script reads)');
    await offAgain();
    plant = { when: rq => rq.method() === 'GET', answer: async route => { const r = await route.fetch(); route.fulfill({ response: r, body: (await r.text()).replace('A number is added switched off;', '<b>Verify the department numbers first</b> (support): A number is added switched off;') }); } };
    out = await run('sales-003', 'leave');
    check('C15 STOP, nothing sent', /a department number is not verified yet/.test(out) && posts.length === 0 && dialogs.length === 0, out);

    console.log('C16 an answer lost: the plugin made the change, the page that came back says nothing');
    plant = { when: isAct('sn_status'), answer: async route => { await route.fetch(); route.fulfill({ status: 504, contentType: 'text/html', body: '<html><body>gateway time-out</body></html>' }); } };
    out = await run('sales-003', 'leave');
    st = await chan('sales-003');
    check('C16 Switch on with no answer: it says NOT KNOWN, never "NOT switched on" — and the number is in fact on', acts().join() === 'sn_verify,sn_webhook,sn_status:active' && /3 Switch on: NOT OK — the plugin gave no answer \(HTTP 504\)/.test(out) && /It is NOT KNOWN whether the number was switched on/.test(out) && !/The number is NOT switched on/.test(out) && st.status === 'active', [out, st.status]);
    await offAgain();
    plant = { when: isAct('sn_status'), answer: async route => { await route.fetch(); route.fulfill({ contentType: 'text/html', body: '<html><body><div class="wa-msg wa-good">Saved.</div></body></html>' }); } };
    out = await run('sales-003', 'leave');
    check('C16 OK but the card not in the answer: STOP, NOT KNOWN', /the plugin said OK, but the number is not on the card it answered with/.test(out) && /It is NOT KNOWN whether the number was switched on/.test(out) && !/DONE/.test(out), out);

    await offAgain();
    plant = { when: isAct('sn_webhook'), answer: async route => { await route.fetch(); route.fulfill({ status: 504, contentType: 'text/html', body: '<html><body>gateway time-out</body></html>' }); } };
    out = await run('sales-003', 'leave');
    st = await chan('sales-003');
    check('C16 Register webhook with no answer: NOT KNOWN, "paste again" (the card cannot show it), nothing more sent; still off', acts().join() === 'sn_verify,sn_webhook' && /NOT KNOWN whether its webhook was registered, and the card does not show that/.test(out) && /paste again/.test(out) && !/To look/.test(out) && st.status === 'disabled', [out, st.status]);
    plant = null;
    out = await run('sales-003', 'leave');
    check('C16 pasted again: three requests and DONE', acts().join() === 'sn_verify,sn_webhook,sn_status:active' && /DONE, 3 request/.test(out), out);

    console.log('C17 an answer Evolution wrote: only the plugin\'s fixed words and the HTTP status are printed');
    await offAgain();
    const refuse = text => ({ when: isAct('sn_webhook'), answer: route => route.fulfill({ contentType: 'text/html', body: `<html><body><div class="wa-msg wa-bad">${text}</div></body></html>` }) });
    plant = refuse('Evolution refused: Not sent — no connection to Evolution: Could not resolve host: evo.internal.example');
    out = await run('sales-003', 'leave');
    check('C17 a connection error: no host, no instance, no path in the output; verified, not switched on', acts().join() === 'sn_verify,sn_webhook' && /2 Register webhook: NOT OK — Evolution refused: Not sent — no connection to Evolution$/m.test(out) && !/evo\.internal|resolve|webhook\/set/.test(out) && noLeak(out) && /verified but NOT switched on/.test(out), out);
    plant = refuse('Evolution refused: May have been sent — no answer from Evolution: Operation timed out after 20001 milliseconds');
    out = await run('sales-003', 'leave');
    check('C17 no answer from Evolution: the plugin\'s fixed words alone, not curl\'s', /2 Register webhook: NOT OK — Evolution refused: May have been sent — no answer from Evolution$/m.test(out) && !/timed out|milliseconds/.test(out) && noLeak(out), out);
    plant = refuse('Evolution refused: Instance sn-three not found on evo.internal.example [HTTP 404 on POST /webhook/set/sn-three]');
    out = await run('sales-003', 'leave');
    st = await chan('sales-003');
    check('C17 a 404 with Evolution\'s own words: only "[HTTP 404]"; the number still off', /2 Register webhook: NOT OK — Evolution refused \[HTTP 404\]$/m.test(out) && !/evo\.internal|not found on|webhook\/set/.test(out) && noLeak(out) && st.status === 'disabled', [out, st.status]);
    plant = null;
  } else {
    console.log('C15–C17 (planted answers) are not run with the service worker on: an answer cannot be planted behind it');
    await offAgain();
  }

  console.log('C18 a salesperson, not an administrator');
  const ctx2 = await browser.newContext({ serviceWorkers: SW }); const p2 = await ctx2.newPage(); await login(p2, 'alpha@example.test'); const posts2 = [], logs2 = [];
  p2.on('request', rq => { if (rq.method() === 'POST') posts2.push(rq.postData() || ''); }); p2.on('console', m => logs2.push(m.text())); p2.on('dialog', d => d.accept());
  await p2.goto(cardUrl); await p2.evaluate(scriptFor('sales-003', 'leave')); await sleep(150);
  st = await chan('sales-003');
  check('C18 STOP, nothing sent, the number still off', /STOP/.test(logs2.join('\n')) && posts2.length === 0 && st.status === 'disabled', logs2);

  console.log('C19 why the script exists: the card\'s own Verify number, pressed by hand, reaches the plugin with no action');
  await openTop();
  const b19 = await cmd({ do: 'state' }); posts = [];
  const row19 = page.locator('#sales-numbers .wa-row', { has: page.locator('input[name="channel_id"][value="sales-003"]') });
  await Promise.all([page.waitForNavigation(), row19.locator('button[name="wa_action"][value="sn_verify"]').evaluate(b => b.click())]);   // the button's own click (the sandbox page has no styles, so it is not laid out as shown)
  const a19 = await cmd({ do: 'state' });
  check('C19 the hand press sends the form without its action; the plugin shows no answer and changes nothing', posts.length === 1 && fields(posts[0]).channel_id === 'sales-003' && !('wa_action' in fields(posts[0])) && await page.locator('.wa-msg').count() === 0 && JSON.stringify(a19) === JSON.stringify(b19), posts.map(fields));

  await cmd({ do: 'quit' });
  await browser.close();
  console.log(`\nservice worker: ${SW} — ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.log('TEST ERROR ' + (e && e.stack || e)); process.exit(2); });
