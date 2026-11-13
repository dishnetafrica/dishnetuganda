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
  const a = "  const CHANNEL   = '';", b = "  const ASSISTANT = 'leave';";
  if (src.split(a).length !== 2 || src.split(b).length !== 2) throw new Error('the option lines are not found once each');
  return src.replace(a, `  const CHANNEL   = '${channel}';`).replace(b, `  const ASSISTANT = '${assistant}';`);
};
(async () => {
  for (let i = 0; i < 1200 && !fs.existsSync(`${ctl}/ready.json`); i++) await sleep(100);
  const ready = JSON.parse(fs.readFileSync(`${ctl}/ready.json`, 'utf8'));
  const base = ready.base, origin = new URL(base).origin, cardUrl = `${base}?page=dashboard&tab=wa_ai_setup`;
  console.log('setup: ' + JSON.stringify(ready.setup));
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
  let posts = [], logs = [], dialogs = [], accept = true;
  page.on('request', rq => { if (rq.method() === 'POST') posts.push(rq.postData() || ''); });
  page.on('console', m => logs.push(m.text()));
  page.on('dialog', async d => { dialogs.push(d.message()); if (accept) await d.accept(); else await d.dismiss(); });
  let doctor = false;
  if (SW === 'block') await page.route('**/*', async route => {   // nothing off 127.0.0.1 (the page's CDN styles); C13's doctored note
    const rq = route.request(), u = new URL(rq.url());
    if (u.hostname !== '127.0.0.1') return route.abort();
    if (doctor && rq.method() === 'GET' && rq.url() === cardUrl) {
      const r = await route.fetch(); const body = (await r.text()).replace('A number is added switched off;', '<b>Verify the department numbers first</b> (support): A number is added switched off;');
      return route.fulfill({ response: r, body });
    }
    return route.continue();
  });
  const openFramed = async () => { await page.goto(origin + '/crm-outer.html');
    try { await page.frameLocator('#f').locator('#sales-numbers').waitFor({ state: 'attached', timeout: 8000 }); }
    catch (e) { for (const f of page.frames()) console.log('   frame', f.url().replace(/\d{5}/, 'PORT').slice(0, 110), 'title', await f.title().catch(() => '?'), 'h1', await f.locator('h1,h2,h3').first().textContent().catch(() => '?')); console.log('   outer html', (await page.content()).slice(0, 300)); throw e; } };
  const openTop = async () => { await page.goto(cardUrl); await page.locator('#sales-numbers').waitFor({ state: 'attached' }); };
  const run = async (channel, assistant, acc) => { posts = []; logs = []; dialogs = []; accept = acc !== false; await page.evaluate(scriptFor(channel, assistant || 'leave')); await sleep(150); return logs.join('\n'); };
  const fields = b => Object.fromEntries(new URLSearchParams(b));
  const noLeak = out => !/Alpha|Bravo|Seller|sn-new|sn-off|sn-three|Sales —/.test(out) && !/\d{5,}/.test(out);
  let out, st;

  console.log('C0 not on the card\'s page');
  await page.goto(`${base}?page=dashboard&tab=leads`);
  out = await run('', 'leave');
  check('C0 STOP, the card is not on this page; nothing sent, no question asked', /STOP — the card "Salesperson numbers" is not on this page/.test(out) && posts.length === 0 && dialogs.length === 0, out);

  console.log('C1 several numbers off, none named');
  await openFramed();
  out = await run('', 'leave');
  check('C1 STOP, lists the three by id and state only; nothing sent, no question', /more than one number is not switched on/.test(out) && ['sales-001: disabled', 'sales-002: disabled', 'sales-003: disabled'].every(x => out.includes(x)) && posts.length === 0 && dialogs.length === 0 && noLeak(out), out);

  console.log('C2 a number that is not on the card');
  out = await run('sales-009', 'leave');
  check('C2 STOP, no such number; nothing sent', /there is no number "sales-009"/.test(out) && posts.length === 0 && dialogs.length === 0, out);

  console.log('C3 Cancel');
  out = await run('sales-001', 'leave', false);
  check('C3 the question names the number on the person\'s own screen', dialogs.length === 1 && dialogs[0].includes('Sales — Alpha Two') && dialogs[0].includes('(sales-001)') && /Switch ON/.test(dialogs[0]), dialogs);
  check('C3 Cancel: nothing sent, and the console line names nobody', /you pressed Cancel\. Nothing was sent/.test(out) && posts.length === 0 && noLeak(out), out);
  st = (await cmd({ do: 'state' })).channels;
  check('C3 the book is as before', st['sales-001'].status === 'disabled' && !st['sales-001'].verified_at, st['sales-001']);

  console.log('C4 already running');
  await page.evaluate(() => { window.__dnSalesNumberBusy = true; });
  out = await run('sales-001', 'leave');
  check('C4 STOP, already running; nothing sent', /already running here/.test(out) && posts.length === 0 && dialogs.length === 0, out);
  await page.evaluate(() => { window.__dnSalesNumberBusy = false; });

  console.log('C5 a number whose phone is not paired');
  out = await run('sales-002', 'leave');
  check('C5 one request (Verify number), the plugin\'s refusal printed, then STOP', posts.length === 1 && fields(posts[0]).wa_action === 'sn_verify' && fields(posts[0]).channel_id === 'sales-002' && /1 Verify number: NOT OK — Not connected yet/.test(out) && /STOP after 1 request/.test(out) && noLeak(out), out);
  st = (await cmd({ do: 'state' })).channels;
  check('C5 the number is still off and unverified', st['sales-002'].status === 'disabled' && !st['sales-002'].verified_at, st['sales-002']);

  console.log('C6 the session lost while the page stays open');
  await ctx.clearCookies();
  out = await run('sales-003', 'leave');
  check('C6 STOP before anything is sent: the card is not shown when asked again', /the plugin did not show the card/.test(out) && posts.length === 0 && dialogs.length === 0, out);
  const pl = await ctx.newPage(); await login(pl, 'admin@example.test'); await pl.close();

  console.log('C7 the card\'s own refusals, read before anything is sent');
  await cmd({ do: 'flag', on: false });
  out = await run('sales-001', 'leave');
  check('C7 registry off: STOP, nothing sent', /the channel registry is off/.test(out) && posts.length === 0 && dialogs.length === 0, out);
  await cmd({ do: 'flag', on: true });

  console.log('C8 the real thing, framed as uCRM frames it');
  const before = await cmd({ do: 'state' });
  out = await run('sales-001', 'leave');
  console.log(out.split('\n').map(l => '      | ' + l).join('\n'));
  const f = posts.map(fields);
  check('C8 exactly three requests, in the card\'s order, each this number\'s with the page\'s token', f.length === 3 && f[0].wa_action === 'sn_verify' && f[1].wa_action === 'sn_webhook' && f[2].wa_action === 'sn_status' && f[2].status === 'active' && f.every(x => x.channel_id === 'sales-001' && /^\d{8}:[0-9a-f]{40}$/.test(x._csrf)), f.map(x => [x.wa_action, x.status, x.channel_id]));
  check('C8 no other field rides along (no retire, no owner, no pause)', f.length === 3 && Object.keys(f[0]).sort().join() === '_csrf,channel_id,wa_action' && Object.keys(f[1]).sort().join() === '_csrf,channel_id,wa_action' && Object.keys(f[2]).sort().join() === '_csrf,channel_id,reason,status,wa_action' && f[2].reason === '', f.map(x => Object.keys(x)));
  check('C8 the three answers OK and DONE', /1 Verify number: OK/.test(out) && /2 Register webhook: OK/.test(out) && /3 Switch on: OK/.test(out) && /DONE, 3 request\(s\) sent\. Now: sales-001: active · assistant on · connected · number verified/.test(out), out);
  check('C8 the output names nobody: no name, no instance, no more than two digits of a number', noLeak(out) && /ending ••••01/.test(out), out);
  const after = await cmd({ do: 'state' });
  st = after.channels;
  check('C8 the book: sales-001 active, verified, its number Evolution\'s', st['sales-001'].status === 'active' && !!st['sales-001'].verified_at && /700000701$/.test(st['sales-001'].business_number || ''), st['sales-001']);
  check('C8 Evolution holds the webhook for its instance, and only its', Object.keys(after.webhooks).join() === 'sn-new' && after.webhooks['sn-new'].startsWith('https://plugin.example.test/public.php?page=evo_webhook&token='), Object.keys(after.webhooks));
  check('C8 the other numbers untouched; no message sent', ['sales-002', 'sales-003', 'sales', 'support', 'account'].every(k => JSON.stringify(st[k]) === JSON.stringify(before.channels[k])) && after.text_calls === before.text_calls);

  console.log('C9 pasted again on the same, stale page');
  out = await run('sales-001', 'leave');
  check('C9 nothing to do, nothing sent, no question', /Nothing to do: it is already switched on and its assistant is on/.test(out) && posts.length === 0 && dialogs.length === 0, out);

  console.log('C10 the only number left off, picked without being named; the page not framed');
  await cmd({ do: 'form', fields: { wa_action: 'sn_status', channel_id: 'sales-002', status: 'retired', confirm: 'yes' } });
  await openTop();
  out = await run('', 'leave');
  check('C10 it asks about sales-003, then three requests and DONE', dialogs.length === 1 && dialogs[0].includes('(sales-003)') && posts.length === 3 && posts.map(fields).every(x => x.channel_id === 'sales-003') && /DONE, 3 request\(s\) sent\. Now: sales-003: active/.test(out) && noLeak(out), out);
  st = (await cmd({ do: 'state' })).channels;
  check('C10 the book: sales-003 active and verified; the retired number still retired', st['sales-003'].status === 'active' && !!st['sales-003'].verified_at && st['sales-002'].status === 'retired', [st['sales-003'].status, st['sales-002'].status]);

  console.log('C11 the assistant');
  const off = await cmd({ do: 'form', fields: { wa_action: 'sn_ai', channel_id: 'sales-003', value: '0' } });
  out = await run('sales-003', 'leave');
  check('C11 assistant off, not asked for: nothing to do, nothing sent', off.answer.startsWith('ok: ') && /to turn its assistant on, set ASSISTANT/.test(out) && posts.length === 0 && dialogs.length === 0, out);
  out = await run('sales-003', 'on');
  check('C11 asked for: one question, one request (the assistant\'s own form), DONE', dialogs.length === 1 && /Let the assistant answer customers/.test(dialogs[0]) && posts.length === 1 && fields(posts[0]).wa_action === 'sn_ai' && fields(posts[0]).value === '1' && fields(posts[0]).channel_id === 'sales-003' && /4 Let the assistant answer: OK/.test(out) && /DONE, 1 request\(s\) sent\. Now: sales-003: active · assistant on/.test(out) && noLeak(out), out);
  st = (await cmd({ do: 'state' })).channels;
  check('C11 the book: the assistant is on for sales-003', String(st['sales-003'].ai_enabled) === '1', st['sales-003']);

  console.log('C12 every number on');
  out = await run('', 'leave');
  check('C12 says so, nothing sent', /Every salesperson number on the card is already switched on/.test(out) && posts.length === 0 && dialogs.length === 0, out);

  console.log('C13 a department gap written on the card (the page\'s note doctored in the answer the script reads)');
  doctor = true;
  await cmd({ do: 'form', fields: { wa_action: 'sn_status', channel_id: 'sales-003', status: 'disabled' } });
  if (SW === 'block') {
  out = await run('sales-003', 'leave');
  check('C13 STOP, nothing sent', /a department number is not verified yet/.test(out) && posts.length === 0 && dialogs.length === 0, out);
  } else console.log('  (not run with the service worker on: a doctored answer cannot be planted behind it)');
  doctor = false;

  console.log('C14 a salesperson, not an administrator');
  const ctx2 = await browser.newContext({ serviceWorkers: SW }); const p2 = await ctx2.newPage(); await login(p2, 'alpha@example.test'); const posts2 = [], logs2 = [];
  p2.on('request', rq => { if (rq.method() === 'POST') posts2.push(rq.postData() || ''); }); p2.on('console', m => logs2.push(m.text())); p2.on('dialog', d => d.accept());
  await p2.goto(cardUrl); await p2.evaluate(scriptFor('sales-003', 'leave')); await sleep(150);
  st = (await cmd({ do: 'state' })).channels;
  check('C14 STOP, nothing sent, the number still off', /STOP/.test(logs2.join('\n')) && posts2.length === 0 && st['sales-003'].status === 'disabled', logs2);

  await cmd({ do: 'quit' });
  await browser.close();
  console.log(`\nservice worker: ${SW} — ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.log('TEST ERROR ' + (e && e.stack || e)); process.exit(2); });
