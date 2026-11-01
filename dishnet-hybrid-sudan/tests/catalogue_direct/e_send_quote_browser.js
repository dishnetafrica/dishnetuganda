// TEST ONLY — section E of tests/test_catalogue_direct.php (5.18.103, root docs/72 §G.9): drives Send Quotation pages,
// drawn by the section from the judged tree, in headless Chromium. The section writes a job file and runs this as
//   CDE_JOB=<job.json> NODE_PATH=<where playwright is> node e_send_quote_browser.js
// The job: {pages: [{file, steps: [<JavaScript expression>, …]}]}. Each page is opened from its file (file://; every
// other request is aborted, so nothing leaves the machine), its own script runs, then each step is evaluated in it in
// turn and its value kept. Dialogs are dismissed and recorded, page errors recorded. Prints one line,
// "@@CDE@@" + {pages: [{results, errors, dialogs}], error}, and exits 0; the assertions are the section's.
'use strict';
const { chromium } = require('playwright');
const fs = require('fs');

(async () => {
  const out = { pages: [], error: null };
  let browser = null;
  try {
    const job = JSON.parse(fs.readFileSync(process.env.CDE_JOB || '', 'utf8'));
    browser = await chromium.launch();
    for (const pg of job.pages) {
      const page = await browser.newPage();
      const errors = [], dialogs = [];
      page.on('pageerror', e => errors.push(String(e && e.message || e)));
      page.on('dialog', async d => { dialogs.push(d.message()); await d.dismiss(); });
      await page.route('**/*', r => (r.request().url().startsWith('file:') ? r.continue() : r.abort()));
      await page.goto('file://' + pg.file);
      await page.waitForLoadState('domcontentloaded');
      const results = [];
      for (const js of pg.steps) {
        try { results.push(await page.evaluate(js)); } catch (e) { results.push({ __error: String(e && e.message || e) }); }
      }
      out.pages.push({ results, errors, dialogs });
      await page.close();
    }
  } catch (e) {
    out.error = String(e && e.stack || e);
  }
  if (browser) await browser.close();
  process.stdout.write('@@CDE@@' + JSON.stringify(out) + '\n');
})();
