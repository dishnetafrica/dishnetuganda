/* Responsive verification for the Admin UI — actually rendered, not asserted.
 * Loads the page in Chromium at each width with the admin API stubbed, and
 * reports horizontal overflow, off-screen nav, and unreadable text. */
import { chromium } from 'playwright';
import { fileURLToPath } from 'url';
import path from 'path';
import fs from 'fs';

const root = path.dirname(fileURLToPath(import.meta.url));
const page_url = (process.env.ADMIN_UI_URL || 'http://127.0.0.1:8899') + '/admin/index.html';

const routers = Array.from({ length: 12 }, (_, i) => ({
  id: 'r' + i, customer_id: 'c' + (i % 3), site_id: 's' + (i % 4),
  serial: 'HGX88420' + (10 + i), model: 'hAP ax2', ros_version: '7.14.3',
  tunnel_ip: '10.66.0.' + (11 + i), name: 'Lobby AP ' + i, state: 'active',
  wan_interface: i % 3 ? 'ether1' : null, wan_interface_set_by: 'tech',
  staged_by: 'tech', staged_at: null, claimed_at: null,
  last_seen_at: i % 4 === 0 ? null : new Date(Date.now() - i * 40 * 60000).toISOString(),
  created_at: '2026-01-01T00:00:00Z',
}));
const stub = {
  '/api/v1/admin/health': { phase: 'F6-A', bindings: { publisher_simulated: true,
      publisher_binding: 'null', real_bindings_allowed: false }, identity: { provider: 'test', role: 'admin' } },
  '/api/v1/admin/routers': { router: routers },
  '/api/v1/admin/sites': { site: [{ id: 's0', customer_id: 'c0', name: 'Lobby', location: 'Ground floor' }] },
  '/api/v1/admin/vouchers': { voucher: [] },
  '/api/v1/admin/intents': { intent: [] },
  '/api/v1/admin/customers': { customer: [{ id: 'c0', name: 'Riverside Hotel', ucrm_client_id: 1001, status: 'active', created_at: '2024-03-01' }] },
};

const widths = [360, 390, 768, 1024, 1440];
const views  = ['routers', 'dashboard', 'customers'];
let bad = 0;

/* The image ships Chromium 1194; the npm package may expect another build.
   Launching the provided binary is the documented remedy and keeps this a
   REAL render rather than a claim. */
const provided = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const b = await chromium.launch(
  fs.existsSync(provided) ? { executablePath: provided } : {});
for (const w of widths) {
  const ctx = await b.newContext({ viewport: { width: w, height: 800 }, deviceScaleFactor: 1 });
  const p = await ctx.newPage();
  await p.route('**/api/v1/admin/**', route => {
    const u = new URL(route.request().url());
    const body = stub[u.pathname] ?? {};
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });
  });
  await p.goto(page_url);
  await p.waitForSelector('#nav a', { timeout: 5000 });

  for (const v of views) {
    await p.click(`[data-view="${v}"]`).catch(() => {});
    await p.waitForTimeout(120);
    const m = await p.evaluate(() => ({
      docW: document.documentElement.scrollWidth,
      winW: window.innerWidth,
      navVisible: !!document.querySelector('#nav a')?.getBoundingClientRect().width,
      smallest: Math.min(...[...document.querySelectorAll('td,th,.fl-l,.tile span')]
        .map(e => parseFloat(getComputedStyle(e).fontSize)).filter(Boolean).concat([99])),
      /* Measure the SCROLL CONTAINER, not the table.
         A wide table whose element extends past the viewport inside a
         scrolling wrapper is the intended behaviour; the first version of
         this check measured the table itself and called that a failure. What
         actually matters is (a) the container stays on screen and (b) the
         overflowing content is reachable rather than clipped. */
      containerEscapes: [...document.querySelectorAll('.tw')]
        .some(c => c.getBoundingClientRect().right > window.innerWidth + 1),
      unreachable: [...document.querySelectorAll('.tw')]
        .some(c => c.scrollWidth > c.clientWidth + 1 && getComputedStyle(c).overflowX === 'visible'),
    }));
    const overflow = m.docW > m.winW + 1;
    const ok = !overflow && m.navVisible && m.smallest >= 11 && !m.containerEscapes && !m.unreachable;
    if (!ok) bad++;
    console.log(`  ${String(w).padStart(4)}px  ${v.padEnd(10)} ` +
      `page-overflow=${overflow ? 'YES' : 'no '} ` +
      `container-escapes=${m.containerEscapes ? 'YES' : 'no '} ` +
      `clipped=${m.unreachable ? 'YES' : 'no '} ` +
      `nav=${m.navVisible ? 'ok' : 'MISSING'} min-font=${m.smallest}px  ${ok ? 'PASS' : 'FAIL'}`);
  }
  await ctx.close();
}
await b.close();
console.log(bad === 0 ? '\n  RESPONSIVE: all widths PASS' : `\n  RESPONSIVE: ${bad} FAILURES`);
process.exit(bad === 0 ? 0 : 1);
