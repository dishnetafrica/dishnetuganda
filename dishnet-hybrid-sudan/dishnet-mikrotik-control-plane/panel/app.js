/* DishNet Admin Web — views.
 *
 * The V2 prototype is the UX source of truth; its information architecture is
 * preserved, not redesigned. Reseller navigation and tenant management are
 * absent by decision (docs/81 §9), not by omission.
 *
 * Estate READS go through api.js, which stays read-only and is tested to. The
 * estate WRITES a view renders are the four router writes of routers.js —
 * register, assign, lifecycle state, push configuration (migration 028,
 * docs/121) — and nothing else; identity writes go through staff.js. Nothing
 * in this file contacts a router: every write is a row on the server.
 */
import { Session, renderGate, L } from './login.js';
import { AdminApi, S, cohort, COHORT_LABEL, contactAge, evidenceLevel } from './api.js';
import { StaffApi, AccountApi } from './staff.js';
import { RouterWriteApi, NEXT_STATES, STEP_MEANING, freshKey } from './routers.js';

const api = new AdminApi();
/* The identity plane has its own client (staff.js) so that api.js stays
 * estate read-only and a test can keep saying so. */
const staffApi = new StaffApi();
const accountApi = new AccountApi();
/* The router-write client (docs/121 D-12): four operations, every one a row
 * on the server. Kept apart from api.js so its read-only guard keeps holding. */
const routersApi = new RouterWriteApi();
const esc = s => String(s ?? '').replace(/[&<>"]/g, c =>
  ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[c]));
const short = id => id ? String(id).slice(0, 8) : '—';
const ugx = n => n == null ? '—' : 'UGX ' + Number(n).toLocaleString('en-UG');

/* V2's navigation, minus reseller and tenant management. */
export const NAV = [
  { sec: 'Network plane' },
  { id: 'dashboard',   label: 'Overview' },
  { id: 'routers',     label: 'Routers' },
  { id: 'hotspot',     label: 'HotSpot' },
  { id: 'network',     label: 'Network health' },
  { id: 'intents',     label: 'Provisioning jobs' },
  { id: 'sessions',    label: 'Active sessions' },
  { id: 'diagnostics', label: 'Diagnostics' },
  { sec: 'Commercial plane' },
  { id: 'customers',   label: 'Operators & sites' },
  { id: 'plans',       label: 'Plans' },
  { id: 'vouchers',    label: 'Vouchers' },
  { id: 'batches',     label: 'Batches' },
  { sec: 'Administration' },
  { id: 'audit',       label: 'Audit log' },
  { id: 'staff',       label: 'DishNet staff' },
  { id: 'account',     label: 'My account' },
];

const state = { view: 'routers', arg: null, health: null, q: '', cohortFilter: null };

/* -------------------------------------------------------------------------
 * The one place a non-OK result becomes screen text.
 *
 * Each state says a DIFFERENT thing. Collapsing them is how "you are not
 * signed in" and "there are no routers" become the same blank panel.
 * ---------------------------------------------------------------------- */
function stateBlock(res, noun) {
  const m = {
    [S.EMPTY]:        [`No ${noun} yet`, `The estate contains no ${noun}. This is a real count, not a failure.`],
    [S.UNAUTHORIZED]: ['Not signed in',  'No staff identity was accepted for this request. Where no identity provider is bound the default binding admits nobody; otherwise the session has ended.'],
    [S.FORBIDDEN]:    ['Not permitted',  `Your role does not include the capability this screen needs${res.data && res.data.capability ? ' (' + esc(res.data.capability) + ')' : ''}.`],
    [S.UNAVAILABLE]:  ['Not available',  'The backend cannot answer this yet. This is not an empty estate.'],
    [S.OFFLINE]:      ['No response',    'The request did not reach the server. Nothing is known about the estate right now.'],
    [S.FAILED]:       ['Request failed', `The server answered ${res.status}. Nothing is being shown rather than showing something wrong.`],
    [S.LOADING]:      ['Loading…',       ''],
  }[res.state];
  if (!m) return '';
  return `<div class="stateblock ${esc(res.state)}"><h3>${esc(m[0])}</h3><p>${m[1]}</p></div>`;
}
const isOk = r => r.state === S.OK;

function table(cols, rows, rowFn) {
  return `<div class="tw"><table><thead><tr>${
    cols.map(c => `<th>${esc(c)}</th>`).join('')}</tr></thead><tbody>${
    rows.map(rowFn).join('')}</tbody></table></div>`;
}

/* ---- Routers: the fleet-centric operational view (V2's landing screen) --- */
/* Resolve an id to the name its own projection carries. A truncated uuid in a
 * Operator column tells DishNet staff nothing and reads like a stray identifier. */
function nameResolver(res) {
  const by = new Map(isOk(res) ? res.rows.map(x => [x.id, x.name]) : []);
  return id => by.get(id) ?? (id ? short(id) : null);
}

async function vRouters() {
  const [res, custs, sites] = await Promise.all([api.routers(), api.customers(), api.sites()]);
  if (!isOk(res)) return head('Routers') + stateBlock(res, 'routers');
  const custName = nameResolver(custs), siteName = nameResolver(sites);

  const all = res.rows;
  const counts = { fresh: 0, warm: 0, cold: 0, never: 0 };
  all.forEach(r => counts[cohort(r.last_seen_at)]++);

  const q = state.q.toLowerCase();
  let rows = all.filter(r => !state.cohortFilter || cohort(r.last_seen_at) === state.cohortFilter);
  if (q) rows = rows.filter(r => [r.serial, r.name, r.model, r.state, r.tunnel_ip]
    .some(v => String(v ?? '').toLowerCase().includes(q)));

  /* Fleet health without opening routers one by one — V2's requirement.
     never-connected is its own cohort and is listed first when non-zero. */
  const order = ['never', 'cold', 'warm', 'fresh'];
  const cohorts = `<div class="cohorts">${order.map(k => `
    <button class="fl ${k} ${counts[k] ? '' : 'zero'} ${state.cohortFilter === k ? 'on' : ''}"
            data-cohort="${k}" title="${esc(COHORT_LABEL[k])}">
      <i></i><span class="fl-n">${counts[k]}</span>
      <span class="fl-l">${k === 'never' ? 'Never seen' : k}</span>
    </button>`).join('')}
    <button class="fl all ${state.cohortFilter ? '' : 'on'}" data-cohort="">
      <span class="fl-n">${all.length}</span><span class="fl-l">all</span></button></div>`;

  return head('Routers', `${all.length} in the estate`) + takeMsg() + cohorts + search() + (
    rows.length === 0
      ? `<div class="stateblock empty"><h3>Nothing matches</h3><p>${
          esc(all.length)} routers exist; none match this filter.</p></div>`
      : table(['Serial', 'Name', 'Model', 'State', 'Last contact', 'Operator', 'Site'], rows,
          r => `<tr data-router="${esc(r.id)}">
            <td class="mono">${esc(r.serial)}</td>
            <td>${esc(r.name) || '—'}</td>
            <td>${esc(r.model)}</td>
            <td><span class="pill">${esc(r.state)}</span></td>
            <td><span class="hs ${cohort(r.last_seen_at)}"><i></i>${esc(contactAge(r.last_seen_at))}</span></td>
            <td>${esc(custName(r.customer_id)) || '—'}</td>
            <td>${esc(siteName(r.site_id)) || '—'}</td></tr>`)) +
    registerForm();
}


/* The provisioning ladder. Rungs come from the lifecycle states migration 012
 * allows, in the order a router passes through them. A device sitting in a
 * state that is NOT on the ladder (orphaned, diverged, decommissioned) is not
 * drawn as a half-finished ladder, because it is not part-way along one. */
const LADDER = ['registered', 'staged', 'shipped', 'connected', 'provisioned', 'active'];

function ladder(r) {
  const at = LADDER.indexOf(r.state);
  if (at < 0) {
    return `<div class="note">This router is <b>${esc(r.state)}</b>, which is not a step on the
      provisioning ladder. Its position is not being guessed.</div>`;
  }
  const stamp = { staged: r.staged_at, connected: r.claimed_at };
  return `<ol class="ladder">` + LADDER.map((step, i) => {
    const cls = i < at ? 'done' : i === at ? 'here' : 'todo';
    const when = stamp[step] ? `<span class="when">${esc(String(stamp[step]).slice(0, 16))}</span>` : '';
    return `<li class="${cls}"><span class="dot"></span><span class="step">${esc(step)}</span>${when}</li>`;
  }).join('') + `</ol>`;
}

/* Signals, measured and unmeasured, exactly as the server reported them.
 *
 * An unmeasured signal is drawn grey and says what would have to exist. It is
 * never drawn green and never drawn red: red would claim a fault was observed,
 * and nothing observed anything. */
function verdictOf(x) {
  if (x.verdict) return x.verdict;
  if (x.status !== 'measured') return 'not measured';
  return x.admin_readable ? 'measured' : 'measured, not exposed';
}

/* Two audiences, one source.
 *
 * `concise` is the NOC view: the signal and a short verdict, nothing
 * else. An administrator on a normal day should not have to read why a
 * handshake timestamp is missing in order to use the screen.
 *
 * The full form — source, limitation, and what would be required — lives in
 * Diagnostics, where that is exactly what someone came for. Both read the same
 * server inventory, so the two can never disagree. */
function signalPanel(sig, concise = false) {
  if (!sig || !sig.signals) {
    return `<div class="note">The signal inventory could not be read, so no signal is being shown.</div>`;
  }
  if (concise) {
    return `<div class="signals tight">` + sig.signals.map(x => `
      <div class="signal ${esc(x.status)}${x.status === 'measured' && !x.admin_readable ? ' unexposed' : ''}">
        <span class="dot"></span>
        <div><b>${esc(x.label)}</b><span class="verdict">${esc(verdictOf(x))}</span></div>
      </div>`).join('') + `</div>
      <div class="hint">Why a signal is unavailable, and what it would take, is in
        <a class="lnk" data-view="diagnostics">Diagnostics</a>.</div>`;
  }
  return `<div class="signals">` + sig.signals.map(x => `
    <div class="signal ${esc(x.status)}${x.status === 'measured' && !x.admin_readable ? ' unexposed' : ''}">
      <span class="dot"></span>
      <div>
        <b>${esc(x.label)}</b>
        <span class="verdict">${esc(verdictOf(x))}</span>
        ${x.reason ? `<p>${esc(x.reason)}</p>` : ''}
        ${x.source ? `<p class="src">${esc(x.source)}</p>` : ''}
        ${x.needs ? `<p class="needs">Needs: ${esc(x.needs)}</p>` : ''}
      </div>
    </div>`).join('') + `</div>`;
}

/* Actions, from the server inventory and nowhere else.
 *
 * An action the SERVER marks available is live on a router's own page — and
 * only there: the button queues a job for the worker (an intent, F2) under a
 * key minted once per rendered page, so a double click is one job and a fresh
 * page a fresh request. Everything else is rendered inert with the server's
 * reason: shown rather than hidden, so DishNet staff can see what the product
 * will eventually do and why it cannot yet. The Diagnostics page has no router
 * in view, so every button there is inert. */
function actionPanel(sig, routerId = null) {
  const acts = (sig && sig.actions) || [];
  if (!acts.length) return '';
  const key = freshKey();
  return `<h2 class="sub">Actions</h2><div class="actions">` + acts.map(a => a.available && routerId ? `
    <div class="action live">
      <button class="btn live" data-raction="${esc(a.key)}" data-id="${esc(routerId)}" data-key="${esc(key)}">${esc(a.label)}</button>
      <p>${esc(a.reason)}</p>
    </div>` : `
    <div class="action">
      <button class="btn" disabled aria-disabled="true" title="${esc(a.reason)}">${esc(a.label)}</button>
      <p>${esc(a.reason)}</p>
    </div>`).join('') + `</div>`;
}

/* ---- Router writes (migration 028, docs/121) --------------------------- */
/* Add a router: the bench act (docs/31 §3.1 step 11). What is typed here is the
 * unit's IDENTITY as staged by the signed-in person — serial, model, RouterOS
 * version, WireGuard public key, tunnel address inside 10.66.0.0/16. The server
 * records who staged it from the session, never from this form. */
function registerForm() {
  return `<form class="sform" data-rform="register">
    <h3>Add a router</h3>
    <label>Serial <input name="serial" required pattern="[A-Za-z0-9][A-Za-z0-9._-]{3,63}" autocapitalize="characters" spellcheck="false"></label>
    <label>Model <input name="model" required maxlength="64" placeholder="hAP ax2"></label>
    <label>RouterOS version <input name="ros_version" maxlength="32" placeholder="7.14.3"></label>
    <label>WireGuard public key <input name="wg_pubkey" pattern="[A-Za-z0-9+/]{43}=" autocapitalize="none" spellcheck="false"></label>
    <label>Tunnel address <input name="tunnel_ip" pattern="10\\.66\\.[0-9]{1,3}\\.[0-9]{1,3}" placeholder="10.66.0.21" spellcheck="false"></label>
    <button class="btn" type="submit">Register</button>
    <small>Recorded as staged by you and owned by nobody until it is assigned. Nothing here contacts the router.</small></form>`;
}

/* Assign to an operator: the explicit TARGET (docs/114 D-AUTH-3), chosen here by
 * name and sent as its id. A site must belong to that operator — the server
 * refuses any other pairing below the function (W-2). Reassignment is a
 * legitimate act and is audited as one. */
function assignForm(r, custs, sites) {
  if (!isOk(custs)) return `<div class="note">Operators could not be read, so no assignment is offered.</div>`;
  const ops = custs.rows.map(c => `<option value="${esc(c.id)}"${c.id === r.customer_id ? ' selected' : ''}>${esc(c.name)}</option>`).join('');
  const sts = (isOk(sites) ? sites.rows : []).map(s =>
    `<option value="${esc(s.id)}" data-op="${esc(s.customer_id)}"${s.id === r.site_id ? ' selected' : ''}>${esc(s.name)}</option>`).join('');
  const verb = r.customer_id ? 'Reassign' : 'Assign';
  return `<form class="sform" data-rform="assign" data-id="${esc(r.id)}">
    <h3>${verb} to an operator</h3>
    <label>Operator <select name="customer_id" required><option value="">— choose —</option>${ops}</select></label>
    <label>Site <select name="site_id"><option value="">— none —</option>${sts}</select></label>
    <label>Name shown to the operator <input name="name" maxlength="64" value="${esc(r.name ?? '')}"></label>
    <button class="btn" type="submit">${verb}</button>
    <small>A site must belong to the chosen operator; the server refuses any other pairing.</small></form>`;
}

/* Record the next lifecycle step a person OBSERVED (docs/121 D-3, D-10). The
 * steps offered come from NEXT_STATES; migration 012's trigger is the authority
 * and a refusal shows the trigger's own reason. */
function recordPanel(r) {
  const next = NEXT_STATES[r.state] || [];
  if (!next.length) {
    return `<div class="note">This router is <b>${esc(r.state)}</b>; no further step can be recorded for it.</div>`;
  }
  return `<div class="steps">
    <div class="steps-head">Record the next step you observed — this router is currently <b>${esc(r.state)}</b>.
      Nothing here contacts the router; a recorded state is what a person saw.</div>
    ${next.map(s => `<button class="btn small${s === 'decommissioned' ? ' ghost' : ''}" data-rstate="${esc(s)}" data-id="${esc(r.id)}"
        title="${esc(STEP_MEANING[s] || '')}">→ ${esc(s)}</button>`).join('')}
  </div>`;
}

async function vRouter() {
  const res = await api.router(state.arg);
  if (res.state !== S.OK && !(res.data && res.data.router)) {
    return head('Router') + stateBlock(res, 'router');
  }
  const r = res.data.router;

  /* Everything the OPERATIONS section shows is filtered from the estate reads
   * the Admin API already exposes. Nothing here asks a router anything. */
  const [sres, sess, vous, jobs, custs, sites] = await Promise.all([
    api.networkSignals(), api.sessions(), api.vouchers(), api.intents(),
    api.customers(), api.sites()]);
  const custName = nameResolver(custs), siteName = nameResolver(sites);
  const sig = sres.status === 200 ? sres.data : null;
  const mine = (res2, pred) => isOk(res2) ? res2.rows.filter(pred) : null;
  const rJobs = mine(jobs, x => x.target_id === r.id);
  const rVous = r.site_id ? mine(vous, x => x.site_id === r.site_id) : [];

  const kv = (pairs) => `<div class="kv">${pairs.map(([k, v]) =>
    `<div><dt>${esc(k)}</dt><dd>${v == null || v === '' ? '—' : esc(v)}</dd></div>`).join('')}</div>`;

  const identity = kv([
    ['Name', r.name], ['Serial', r.serial], ['Model', r.model],
    ['RouterOS', r.ros_version], ['Lifecycle state', r.state],
    ['Operator', custName(r.customer_id)], ['Site', siteName(r.site_id)],
  ]);

  /* CONNECTIVITY carries only what is recorded. The observed half of it — link
   * state, tunnel handshake, RADIUS and HotSpot — lives in SIGNALS, where each
   * one says it has no source. */
  const connectivity = kv([
    ['Tunnel address', r.tunnel_ip],
    ['WAN interface', r.wan_interface],
    ['WAN established by', r.wan_interface_set_by],
    ['Last contact', contactAge(r.last_seen_at)],
  ]);

  const count = (rows, noun) => rows === null
    ? `<span class="muted">unavailable</span>`
    : `<b>${rows.length}</b> ${esc(noun)}${rows.length === 1 ? '' : 's'}`;
  const operations = `<div class="opgrid">
    <div class="op"><h3>Active sessions</h3><p><span class="muted">not attributable</span></p>
      <small>RADIUS accounting carries a NAS identifier and mt_session_account never sets
      device_id, so a session cannot be tied to one router. The estate-wide count is on
      Active sessions.</small></div>
    <div class="op"><h3>Vouchers at this site</h3><p>${count(rVous, 'voucher')}</p></div>
    <div class="op"><h3>Provisioning jobs</h3><p>${count(rJobs, 'job')}</p></div>
    <div class="op"><h3>Uplink</h3><p><span class="muted">not exposed to Admin</span></p>
      <small>Recorded in Domain B. Exposing telemetry to Admin is decision D-4, which is open.</small></div>
  </div>` + (rJobs && rJobs.length ? table(
    ['Kind', 'State', 'Attempts', 'Created'], rJobs,
    j => `<tr><td>${esc(j.kind)}</td><td><span class="pill ${esc(j.state)}">${esc(j.state)}</span></td>
          <td>${esc(j.attempts)}/${esc(j.max_attempts)}</td>
          <td class="mono">${esc(String(j.created_at ?? '').slice(0, 16))}</td></tr>`) : '');

  return head('Router', esc(r.serial)) + takeMsg() +
    `<h2 class="sub">Identity</h2>` + identity +
    `<h2 class="sub">Assignment</h2>` + assignForm(r, custs, sites) +
    `<h2 class="sub">Connectivity</h2>` + connectivity +
    (r.wan_interface ? '' : `<div class="note">No WAN interface has been established for this
      router, so its uplink is not measurable. That is a provisioning gap, not a fault.</div>`) +
    `<h2 class="sub">Provisioning</h2>` + ladder(r) + recordPanel(r) +
    `<h2 class="sub">Operations</h2>` + operations +
    `<h2 class="sub">Signals</h2>` + signalPanel(sig, true) +
    actionPanel(sig, r.id);
}

/* Voucher lifecycle, as the DOMAIN currently supports it — not as the
 * commercial model describes it. Two states are reachable; three are not, and
 * each says what is missing. Simulating the others would make the prototype
 * less trustworthy, not more complete. */
function lifecyclePanel(sig) {
  const ls = sig && sig.voucher_lifecycle;
  if (!ls) return '';
  const sum = sig.voucher_lifecycle_summary || {};
  return `<div class="note">${esc(sum.note || '')}</div>
    <div class="lifecycle">${ls.map(x => `
      <div class="lc ${esc(x.status)}">
        <span class="dot"></span>
        <div><b>${esc(x.state)}</b>
          <span class="verdict">${x.status === 'reachable' ? 'available now' : 'not reachable'}</span>
          <p>${esc(x.via || x.blocked_by || '')}</p></div>
      </div>`).join('')}</div>`;
}

const bytes = n => n == null ? '\u2014' :
  n >= 1e9 ? (n / 1e9).toFixed(1) + ' GB' : n >= 1e6 ? (n / 1e6).toFixed(0) + ' MB' : n + ' B';

async function vHotspot() {
  const [svcs, plans, vous, custs, sites, sres] = await Promise.all([
    api.services(), api.plans(), api.vouchers(), api.customers(), api.sites(), api.networkSignals()]);
  if (!isOk(svcs)) return head('HotSpot') + stateBlock(svcs, 'service');
  const custName = nameResolver(custs);
  const sig = sres.status === 200 ? sres.data : null;
  const hot = svcs.rows.filter(x => x.kind === 'mikrotik_hotspot');
  const byState = rows => rows.reduce((a, v) => (a[v.state] = (a[v.state] || 0) + 1, a), {});

  const cards = hot.map(sv => {
    const vp = isOk(plans) ? plans.rows.filter(p => p.customer_id === sv.customer_id) : [];
    const vv = isOk(vous) ? vous.rows.filter(v => v.customer_id === sv.customer_id) : [];
    const c = byState(vv);
    return `<div class="hscard">
      <h3>${esc(custName(sv.customer_id))}</h3>
      <div class="hsrow">
        <div><dt>Service</dt><dd><span class="pill">${esc(sv.status)}</span>
          <small>recorded, not observed</small></dd></div>
        <div><dt>RADIUS</dt><dd><span class="muted">not measured</span></dd></div>
        <div><dt>Active users</dt><dd><span class="muted">not attributable per router</span></dd></div>
      </div>
      <h4>Plans</h4>
      ${vp.length ? table(['Plan', 'Duration', 'Down', 'Price', 'Active'], vp,
        p => `<tr><td>${esc(p.name)}</td><td>${esc(Math.round(p.duration_s / 60))} min</td>
              <td>${esc(Math.round(p.rate_down_bps / 1e6))} Mbps</td>
              <td>${ugx(p.price_minor)}</td>
              <td>${p.active ? 'yes' : 'retired'}</td></tr>`)
        : `<p class="muted">No plans.</p>`}
      <h4>Vouchers</h4>
      <div class="vcount">${['unused', 'active', 'expired', 'revoked'].map(st =>
        `<span class="vc"><b>${c[st] || 0}</b> ${st}</span>`).join('')}
        <span class="vc total"><b>${vv.length}</b> total</span></div>
    </div>`;
  }).join('');

  return head('HotSpot', `${hot.length} service${hot.length === 1 ? '' : 's'}`) +
    `<div class="note">Service state is what the control plane <b>recorded</b>. Whether a
      HotSpot server is running on a router is not observed by anything here, and stays
      unavailable until the hardware gate opens.</div>` +
    cards +
    `<h2 class="sub">Voucher lifecycle</h2>` + lifecyclePanel(sig);
}

async function vVoucher() {
  const [res, plans, sites, custs] = await Promise.all([
    api.voucher(state.arg), api.plans(), api.sites(), api.customers()]);
  if (res.state !== S.OK && !(res.data && res.data.voucher)) {
    return head('Voucher') + stateBlock(res, 'voucher');
  }
  const v = res.data.voucher;
  const planName = nameResolver(plans), siteName = nameResolver(sites),
        custName = nameResolver(custs);
  const f = [
    ['Reference', short(v.id)], ['State', v.state],
    ['Operator', custName(v.customer_id)], ['Site', siteName(v.site_id)],
    ['Plan', planName(v.plan_id)], ['Price', ugx(v.price_minor)],
    ['Duration', Math.round(v.duration_s / 60) + ' min'],
    ['Issued', v.created_at], ['Activated', v.activated_at],
    ['Expires', v.expires_at], ['Revoked', v.revoked_at],
  ];
  return head('Voucher', esc(short(v.id))) +
    `<div class="kv">${f.map(([k, val]) =>
      `<div><dt>${esc(k)}</dt><dd>${val == null || val === '' ? '\u2014' : esc(val)}</dd></div>`).join('')}</div>` +
    `<div class="note">The voucher <b>code</b> is not shown, here or anywhere in Admin. A
      code is a bearer credential: whoever holds it holds the access, so it stays out of
      every path but redemption.</div>`;
}

async function vSessions() {
  const [res, vous, sites, plans] = await Promise.all([
    api.sessions(), api.vouchers(), api.sites(), api.plans()]);
  if (!isOk(res)) return head('Active sessions') + stateBlock(res, 'session');
  const vby = new Map(isOk(vous) ? vous.rows.map(v => [v.id, v]) : []);
  const siteName = nameResolver(sites), planName = nameResolver(plans);
  return head('Active sessions', `${res.rows.length}`) +
    `<div class="note"><b>Router attribution unavailable.</b> RADIUS accounting carries a NAS
      identifier, and nothing maps a NAS identifier to a device, so no session below can be
      tied to a particular router. Site and plan are derived through the voucher.
      Accounting source: RADIUS.</div>` +
    table(['Session', 'Site', 'Plan', 'NAS', 'In', 'Out', 'State', 'Started'], res.rows,
      x => {
        const v = vby.get(x.voucher_id);
        return `<tr>
          <td class="mono">${esc(short(x.id))}</td>
          <td>${esc(v ? siteName(v.site_id) : '\u2014')}</td>
          <td>${esc(v ? planName(v.plan_id) : '\u2014')}</td>
          <td class="mono">${esc(x.nas_identifier) || '\u2014'}</td>
          <td>${esc(bytes(x.bytes_in))}</td><td>${esc(bytes(x.bytes_out))}</td>
          <td><span class="pill">${esc(x.state)}</span></td>
          <td class="mono">${esc(String(x.started_at ?? '').slice(0, 16))}</td></tr>`;
      });
}

async function vNetwork() {
  const res = await api.networkSignals();
  if (res.status !== 200) return head('Network health') + stateBlock({ state: S.UNAVAILABLE, status: res.status }, 'signal');
  const d = res.data, m = d.summary;
  return head('Network health', `${m.measured} of ${m.total} signals measured`) +
    `<div class="note">This page is the inventory of what this system can observe. It is
      deliberately not a wall of green: ${m.unmeasured} of ${m.total} signals have no source at
      all, and showing them as healthy would be the screen asserting something nothing
      checked.</div>` +
    signalPanel(d);
}

async function vDiagnostics() {
  const res = await api.networkSignals();
  const h = state.health;
  const rows = [
    ['Delivery binding', h ? h.bindings.delivery_binding : '—'],
    ['Publisher binding', h ? h.bindings.publisher_binding : '—'],
    ['Publisher simulated', h ? String(h.bindings.publisher_simulated) : '—'],
    ['Real bindings allowed', h ? String(h.bindings.real_bindings_allowed) : '—'],
    ['Identity provider', h ? h.identity.provider : '—'],
  ];
  const acts = res.status === 200 ? res.data : null;
  return head('Diagnostics') +
    `<div class="note">Nothing here contacts a router. Every reading below is about
      <b>this process</b> — which adapters it loaded and which identity it accepted.</div>` +
    `<div class="kv">${rows.map(([k, v]) =>
      `<div><dt>${esc(k)}</dt><dd>${esc(v)}</dd></div>`).join('')}</div>` +
    `<h2 class="sub">Why each signal is or is not available</h2>` + signalPanel(acts) +
    `<h2 class="sub">Voucher lifecycle</h2>` + lifecyclePanel(acts) +
    actionPanel(acts);
}

const list = (title, fetch, key, cols, rowFn, noun) => async () => {
  const res = await fetch();
  if (!isOk(res)) return head(title) + stateBlock(res, noun);
  return head(title, `${res.rows.length}`) + table(cols, res.rows, rowFn);
};

const vCustomers = list('Operators & Sites', () => api.customers(), 'customer',
  ['Name', 'Account', 'Status', 'Since'], c => `<tr>
    <td>${esc(c.name)}</td><td class="mono">${esc(c.ucrm_client_id) || '—'}</td>
    <td><span class="pill">${esc(c.status)}</span></td>
    <td>${esc((c.created_at || '').slice(0, 10))}</td></tr>`, 'operators');

const vPlans = list('Plans', () => api.plans(), 'plan',
  ['Name', 'Price', 'Duration', 'Down/Up', 'Devices', 'Active'], p => `<tr>
    <td>${esc(p.name)}</td><td>${ugx(p.price_minor)}</td>
    <td>${esc(Math.round((p.duration_s || 0) / 60))} min</td>
    <td>${esc(Math.round((p.rate_down_bps||0)/1e6))}/${esc(Math.round((p.rate_up_bps||0)/1e6))} Mbps</td>
    <td>${esc(p.devices_per_voucher)}</td>
    <td>${p.active ? 'yes' : 'no'}</td></tr>`, 'plans');

const vVouchers = list('Vouchers', () => api.vouchers(), 'voucher',
  ['Reference', 'State', 'Price', 'Issued', 'Activated', 'Expires'], v => `<tr data-voucher="${esc(v.id)}">
    <td class="mono">${short(v.id)}</td><td><span class="pill">${esc(v.state)}</span></td>
    <td>${ugx(v.price_minor)}</td><td>${esc((v.created_at||'').slice(0,16).replace('T',' '))}</td>
    <td>${esc((v.activated_at||'').slice(0,16).replace('T',' ')) || '—'}</td>
    <td>${esc((v.expires_at||'').slice(0,16).replace('T',' ')) || '—'}</td></tr>`, 'vouchers');

const vBatches = list('Batches', () => api.batches(), 'batch',
  ['Reference', 'Requested', 'Issued', 'State', 'Created'], b => `<tr>
    <td class="mono">${short(b.id)}</td><td>${esc(b.requested_count)}</td>
    <td>${esc(b.issued_count)}</td><td><span class="pill">${esc(b.state)}</span></td>
    <td>${esc((b.created_at||'').slice(0,16).replace('T',' '))}</td></tr>`, 'batches');


/* Intents: state, attempts and target — never payload or last_error (D-2). */
const vIntents = list('MT Intents', () => api.intents(), 'intent',
  ['Reference', 'Kind', 'State', 'Attempts', 'Target', 'Created'], i => `<tr>
    <td class="mono">${short(i.id)}</td><td>${esc(i.kind)}</td>
    <td><span class="pill ${esc(i.state)}">${esc(i.state)}</span></td>
    <td>${esc(i.attempts)}/${esc(i.max_attempts)}</td>
    <td>${esc(i.target_type)} ${short(i.target_id)}</td>
    <td>${esc((i.created_at||'').slice(0,16).replace('T',' '))}</td></tr>`, 'intents');

const vAudit = list('Audit Log', () => api.audit(), 'audit',
  ['When', 'Actor', 'Kind', 'Action', 'Target'], a => `<tr>
    <td>${esc((a.at||'').slice(0,16).replace('T',' '))}</td>
    <td class="mono">${esc(a.actor)}</td><td>${esc(a.actor_kind)}</td>
    <td>${esc(a.action)}</td>
    <td class="mono">${esc(a.target_type)} ${short(a.target_id)}</td></tr>`, 'audit entries');

async function vDashboard() {
  const [routers, sites, vouchers, intents] = await Promise.all(
    [api.routers(), api.sites(), api.vouchers(), api.intents()]);
  if (!isOk(routers)) return head('Overview') + stateBlock(routers, 'estate data');
  const c = { fresh: 0, warm: 0, cold: 0, never: 0 };
  routers.rows.forEach(r => c[cohort(r.last_seen_at)]++);
  const failed = isOk(intents) ? intents.rows.filter(i => i.state === 'failed').length : 0;
  const tile = (n, l, cls = '') => `<div class="tile ${cls}"><b>${n}</b><span>${esc(l)}</span></div>`;
  return head('Overview') + `<div class="tiles">
    ${tile(routers.rows.length, 'routers')}
    ${tile(c.never, 'never seen', c.never ? 'bad' : '')}
    ${tile(c.cold, 'not seen in 3h', c.cold ? 'warn' : '')}
    ${tile(failed, 'failed intents', failed ? 'bad' : '')}
    ${tile(isOk(sites) ? sites.rows.length : '—', 'sites')}
    ${tile(isOk(vouchers) ? vouchers.rows.length : '—', 'vouchers')}</div>` +
    `<div class="note">Counts describe what the control plane has recorded. They do not
     assert that any router was contacted just now.</div>`;
}

/* -------------------------------------------------------------------------
 * IDENTITY PLANE — DishNet staff (Admin only) and the signed-in person's own
 * account. Everything here goes through staff.js; nothing here reads or writes
 * the estate. A generated password is rendered ONCE from the response and is
 * kept by nothing on this page.
 * ---------------------------------------------------------------------- */
const ROLES = ['admin', 'noc', 'sales', 'support'];
const once = { text: null };   // the last one-time value to show, cleared on the next render

function onceBox() {
  if (!once.text) return '';
  const t = once.text; once.text = null;
  return `<div class="once"><b>Shown once — it is stored nowhere and cannot be retrieved.</b>
    <p>${esc(t.what)}</p><code>${esc(t.value)}</code></div>`;
}

function notice(res, fallback) {
  const d = res && res.data;
  if (res && res.status === 409 && d) return `<div class="msg err">${esc(d.detail || 'refused')}</div>`;
  if (res && res.status === 403 && d && d.error === 'second_factor_required')
    return `<div class="msg err">Set up your authenticator first (My account).</div>`;
  if (res && res.status === 403) return `<div class="msg err">Your role does not carry ${esc(d && d.capability || 'this capability')}.</div>`;
  if (res && res.status === 501) return `<div class="msg err">${esc(d && d.detail || 'not available under this identity provider')}</div>`;
  if (res && res.status >= 400) return `<div class="msg err">${esc(fallback || ('the server answered ' + res.status))}</div>`;
  return '';
}
const pending = { msg: '' };
/** The one message for the next render, shown once. */
const takeMsg = () => { const m = pending.msg; pending.msg = ''; return m; };

function radios(name, current) {
  return `<span class="radios">${ROLES.map(r => `<label><input type="radio" name="${esc(name)}"
    value="${esc(r)}" ${r === current ? 'checked' : ''}> ${esc(r)}</label>`).join('')}</span>`;
}

async function vStaff() {
  const res = await staffApi.list();
  if (res.state !== 'ok' && res.state !== 'empty') {
    const map = { unauthorized: S.UNAUTHORIZED, forbidden: S.FORBIDDEN, unavailable: S.UNAVAILABLE,
                  offline: S.OFFLINE, failed: S.FAILED };
    return head('DishNet staff') + stateBlock({ state: map[res.state] || S.FAILED, status: res.status, data: res.data }, 'staff');
  }
  const me = session.identity ? session.identity.subject : '';
  const msg = pending.msg; pending.msg = '';
  const form = `<form class="sform" data-sform="create">
    <h3>Add a DishNet staff member</h3>
    <label>Username <input name="username" autocapitalize="none" pattern="[a-z0-9][a-z0-9._-]{1,62}" required></label>
    <label>Display name <input name="display_name" required></label>
    <label>Role ${radios('role', 'support')}</label>
    <button class="btn" type="submit">Create</button>
    <small>The password is generated and shown once. The new person should change it and set up an
      authenticator on first sign-in.</small></form>`;
  const rows = res.rows.map(x => `<tr>
    <td class="mono">${esc(x.username)}${x.username === me ? ' <span class="pill">you</span>' : ''}</td>
    <td>${esc(x.display_name)}</td>
    <td><span class="pill">${esc(x.role)}</span></td>
    <td><span class="pill ${x.status === 'disabled' ? 'failed' : ''}">${esc(x.status)}</span></td>
    <td>${x.totp_enrolled ? 'enrolled' : '<span class="muted">not yet</span>'}</td>
    <td class="mono">${esc(String(x.last_login_at || '').slice(0, 16).replace('T', ' ')) || '—'}</td>
    <td class="acts">
      ${x.status === 'active'
        ? `<button class="btn small" data-sact="disable" data-id="${esc(x.id)}" ${x.username === me ? 'disabled aria-disabled="true" title="you cannot disable yourself"' : ''}>Disable</button>`
        : `<button class="btn small" data-sact="enable" data-id="${esc(x.id)}">Enable</button>`}
      <button class="btn small" data-sact="pw" data-id="${esc(x.id)}">New password</button>
      <button class="btn small" data-sact="totp" data-id="${esc(x.id)}" ${x.totp_enrolled ? '' : 'disabled aria-disabled="true" title="no authenticator to clear"'}>Clear authenticator</button>
      <span class="rolechange">${ROLES.filter(r => r !== x.role).map(r =>
        `<button class="btn small ghost" data-sact="role" data-role="${esc(r)}" data-id="${esc(x.id)}">→ ${esc(r)}</button>`).join('')}</span>
    </td></tr>`).join('');
  return head('DishNet staff', `${res.rows.length}`) +
    `<div class="note">DishNet's own people, who sign in here. Operators and their staff are a
      different plane (Operators &amp; sites) and never appear in this list. Disabling a person
      ends their live sessions at once; so does changing their role or their password.</div>` +
    msg + onceBox() + form +
    (res.rows.length
      ? `<div class="tw"><table><thead><tr><th>Username</th><th>Name</th><th>Role</th><th>Status</th>
          <th>Authenticator</th><th>Last sign-in</th><th>Actions</th></tr></thead><tbody>${rows}</tbody></table></div>`
      : `<div class="stateblock empty"><h3>No staff yet</h3><p>The first administrator is created on the server with
          <code>plugin.php staff:bootstrap</code>.</p></div>`);
}

async function vAccount() {
  const id = session.identity || {};
  const sf = id.second_factor || {};
  const real = id.provider === 'dishnet';
  const msg = pending.msg; pending.msg = '';
  const kv = [['Signed in as', id.subject], ['Role', id.role], ['Identity provider', id.provider],
              ['Authenticator', real ? (sf.enrolled ? 'enrolled' : 'not set up') : 'not applicable']];
  let body = `<div class="kv">${kv.map(([k, v]) => `<div><dt>${esc(k)}</dt><dd>${esc(v ?? '—')}</dd></div>`).join('')}</div>`;
  if (!real) {
    body += `<div class="note">This is the development identity: it has no password to change and
      no authenticator to set up. Under the real provider this screen manages both.</div>`;
  } else {
    if (!sf.enrolled) {
      body += session.enrolment
        ? `<div class="sform"><h3>Authenticator setup</h3>${enrolMarkup(session.enrolment)}
            <form data-sform="confirm"><label>Code from the app <input name="code" inputmode="numeric"
              pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></label>
            <button class="btn" type="submit">Confirm</button></form></div>`
        : `<div class="sform"><h3>Authenticator</h3><p>Not set up. Any time-based authenticator app works.</p>
            <button class="btn" data-sact="enrol">Begin setup</button></div>`;
    }
    body += `<form class="sform" data-sform="pw"><h3>Change password</h3>
      <label>Current <input name="current" type="password" autocomplete="current-password" required></label>
      <label>New (12 characters or more) <input name="replacement" type="password" autocomplete="new-password" minlength="12" required></label>
      <button class="btn" type="submit">Change</button>
      <small>Every other session of yours is ended when it changes; this one continues.</small></form>`;
  }
  return head('My account') + msg + body +
    `<div class="note"><a class="lnk" data-act="signout">Sign out</a> — ends this session on the server, not just in this tab.</div>`;
}

function enrolMarkup(e) {
  return `<div class="lg-key"><span>Account</span><b>${esc(e.account)}</b>
    <span>Setup key</span><b class="mono">${esc(String(e.key).replace(/(.{4})/g, '$1 ').trim())}</b>
    <span>Or paste</span><b class="mono small">${esc(e.uri)}</b></div>
    <p class="muted">Shown once. An administrator can clear it later if the device is lost.</p>`;
}

const VIEWS = { routers: vRouters, router: vRouter, customers: vCustomers, plans: vPlans,
                vouchers: vVouchers, batches: vBatches, sessions: vSessions,
                intents: vIntents, audit: vAudit, dashboard: vDashboard,
                network: vNetwork, diagnostics: vDiagnostics,
                hotspot: vHotspot, voucher: vVoucher,
                staff: vStaff, account: vAccount };

function head(title, sub) {
  return `<div class="appbar"><h1>${esc(title)}${sub ? `<span class="sub">${esc(sub)}</span>` : ''}</h1></div>`;
}
function search() {
  return `<div class="searchbar"><input id="q" type="search" placeholder="Search serial, name, model, state…"
    value="${esc(state.q)}" autocomplete="off"></div>`;
}

function banner() {
  const e = evidenceLevel(state.health);
  return `<div class="evidence ${esc(e.level)}"><b>${esc(e.level.toUpperCase())}</b>
    <span>${esc(e.label)}</span></div>`;
}

export async function render() {
  document.getElementById('nav').innerHTML = NAV.map(n => n.sec
    ? `<div class="navsec">${esc(n.sec)}</div>`
    : `<a class="navitem ${state.view === n.id ? 'on' : ''}" data-view="${n.id}">${esc(n.label)}</a>`
  ).join('');
  document.getElementById('evidence').innerHTML = banner();
  const who = document.getElementById('who');
  if (who) {
    const id = session.identity;
    who.innerHTML = id ? `<b>${esc(id.subject)}</b> · ${esc(id.role)}<a class="lnk" data-act="signout">Sign out</a>` : '';
  }
  const view = VIEWS[state.view] || vRouters;
  document.getElementById('main').innerHTML = '<div class="stateblock loading"><h3>Loading…</h3></div>';
  document.getElementById('main').innerHTML = await view();
  wire();
}

function wire() {
  document.querySelectorAll('[data-view]').forEach(a => a.onclick = () => {
    state.view = a.dataset.view; state.arg = null; state.q = ''; state.cohortFilter = null; render();
  });
  document.querySelectorAll('[data-cohort]').forEach(b => b.onclick = () => {
    state.cohortFilter = b.dataset.cohort || null; render();
  });
  document.querySelectorAll('[data-router]').forEach(tr => tr.onclick = () => {
    state.view = 'router'; state.arg = tr.dataset.router; render();
  });
  document.querySelectorAll('[data-voucher]').forEach(tr => tr.onclick = () => {
    state.view = 'voucher'; state.arg = tr.dataset.voucher; render();
  });
  const q = document.getElementById('q');
  if (q) {
    q.oninput = () => { state.q = q.value; };
    q.onkeyup = e => { if (e.key === 'Enter' || state.q === '') render(); };
    q.onsearch = () => render();
  }
  document.querySelectorAll('[data-act="signout"]').forEach(a => a.onclick = async () => {
    await session.logout(); paintGate();
  });
  wireIdentity();
  wireRouters();
}

/* The identity-plane controls. Each answer is re-rendered from the server's
 * reply; nothing here assumes an act succeeded. */
function wireIdentity() {
  const after = async (res, ok) => {
    if (res.status === 401) { return onUnauthorized(); }
    pending.msg = res.status < 300 ? (ok ? `<div class="msg ok">${esc(ok)}</div>` : '') : notice(res);
    render();
  };
  document.querySelectorAll('[data-sact]').forEach(b => b.onclick = async () => {
    const id = b.dataset.id;
    switch (b.dataset.sact) {
      case 'disable': return after(await staffApi.disable(id), 'Disabled; their sessions are ended.');
      case 'enable':  return after(await staffApi.enable(id), 'Enabled.');
      case 'role':    return after(await staffApi.setRole(id, b.dataset.role), 'Role changed; their sessions are ended.');
      case 'totp':    return after(await staffApi.clearTotp(id), 'Authenticator cleared; they will set up a new one at next sign-in.');
      case 'pw': {
        const r = await staffApi.newPassword(id);
        if (r.status === 200 && r.data) { once.text = { what: 'New password for this person:', value: r.data.password }; }
        return after(r, '');
      }
      case 'enrol': {
        await session.enrol();
        pending.msg = session.enrolment ? '' : `<div class="msg err">${esc(session.detail || 'could not begin')}</div>`;
        return render();
      }
    }
  });
  document.querySelectorAll('form[data-sform]').forEach(f => f.onsubmit = async ev => {
    ev.preventDefault();
    const fields = Object.fromEntries(new FormData(f));
    switch (f.dataset.sform) {
      case 'create': {
        const r = await staffApi.create(fields);
        if (r.status === 201 && r.data) { once.text = { what: `Password for ${r.data.staff.username}:`, value: r.data.password }; }
        return after(r, '');
      }
      case 'pw':      return after(await accountApi.changePassword(fields), 'Password changed. Your other sessions are ended.');
      case 'confirm': {
        await session.confirm(fields.code);
        pending.msg = session.detail ? `<div class="msg err">${esc(session.detail)}</div>` : `<div class="msg ok">Authenticator confirmed.</div>`;
        return render();
      }
    }
  });
}

/* The router-write controls (docs/121 D-12). Each answer is re-rendered from
 * the server's reply; nothing here assumes an act succeeded. A 401 sends the
 * whole panel back to the gate. */
function wireRouters() {
  const after = async (res, ok) => {
    if (res.status === 401) { return onUnauthorized(); }
    pending.msg = res.status === 0 ? `<div class="msg err">No response from the server; nothing was recorded.</div>`
                : res.status < 300 ? (ok ? `<div class="msg ok">${esc(ok)}</div>` : '')
                : notice(res, res.data && res.data.error);
    render();
  };
  /* Empty optional fields are left out, so the server sees absence, not ''. */
  const clean = f => Object.fromEntries([...new FormData(f)].filter(([, v]) => String(v).trim() !== ''));
  document.querySelectorAll('form[data-rform]').forEach(f => {
    const op = f.querySelector('select[name="customer_id"]'), site = f.querySelector('select[name="site_id"]');
    if (op && site) {
      const filter = () => { [...site.options].forEach(o => {
        const mine = !o.value || o.dataset.op === op.value;
        o.hidden = !mine; o.disabled = !mine; if (!mine && o.selected) site.value = '';
      }); };
      op.onchange = filter; filter();
    }
    f.onsubmit = async ev => {
      ev.preventDefault();
      const fields = clean(f);
      switch (f.dataset.rform) {
        case 'register': {
          const r = await routersApi.register(fields);
          if (r.status === 201 && r.data && r.data.router) { state.view = 'router'; state.arg = r.data.router.id; }
          return after(r, `Registered ${fields.serial}: staged by you, owned by nobody yet.`);
        }
        case 'assign': return after(await routersApi.assign(f.dataset.id, fields), 'Assigned.');
      }
    };
  });
  document.querySelectorAll('[data-rstate]').forEach(b => b.onclick = async () => {
    const s = b.dataset.rstate;
    if (s === 'decommissioned' && !confirm('Decommission this router? No further state can be recorded for it afterwards.')) return;
    return after(await routersApi.setState(b.dataset.id, s), `Recorded as ${s}.`);
  });
  document.querySelectorAll('[data-raction]').forEach(b => b.onclick = async () => {
    b.disabled = true;
    const r = await routersApi.pushConfig(b.dataset.id, b.dataset.key);
    return after(r, r.status === 202 ? 'Configuration job queued for the worker. It is delivered through the worker\'s binding, not from here.'
                  : r.status === 200 ? 'That job was already queued; nothing new was added.' : '');
  });
}

/**
 * THE GATE RUNS FIRST, AND IT RUNS ON THE SERVER'S ANSWER.
 *
 * Nothing is fetched and nothing is drawn until GET /session has said who —
 * if anyone — this browser is. The panel does not decide it is authenticated;
 * it asks, and re-asks whenever a screen comes back 401.
 */
export const session = new Session();

function paintGate() {
  const html = renderGate(session);
  const gate = document.getElementById('gate');
  const shell = document.querySelector('.shell');
  if (html === null) {
    gate.innerHTML = ''; gate.hidden = true;
    if (shell) { shell.hidden = false; }
    return false;
  }
  if (shell) { shell.hidden = true; }
  gate.hidden = false;
  gate.innerHTML = html;
  gate.querySelectorAll('[data-act="login"]').forEach(b => {
    b.onclick = async () => {
      paintGateBusy();
      await session.login({ role: b.dataset.role });
      if (!paintGate()) { await boot(); }
    };
  });
  const creds = gate.querySelector('form[data-act="credentials"]');
  if (creds) {
    creds.onsubmit = async ev => {
      ev.preventDefault();
      const fields = Object.fromEntries(new FormData(creds));
      paintGateBusy();
      await session.login(fields);
      if (!paintGate()) { await boot(); }
    };
  }
  const enrol = gate.querySelector('[data-act="enrol"]');
  if (enrol) { enrol.onclick = async () => { await session.enrol(); paintGate(); }; }
  const confirm = gate.querySelector('form[data-act="confirm"]');
  if (confirm) {
    confirm.onsubmit = async ev => {
      ev.preventDefault();
      await session.confirm(Object.fromEntries(new FormData(confirm)).code);
      if (!paintGate()) { await boot(); }
    };
  }
  const signout = gate.querySelector('[data-act="signout"]');
  if (signout) { signout.onclick = async () => { await session.logout(); paintGate(); }; }
  const back = gate.querySelector('[data-act="back"]');
  if (back) { back.onclick = async () => { await session.refresh(); if (!paintGate()) { await boot(); } }; }
  return true;
}

function paintGateBusy() {
  const gate = document.getElementById('gate');
  gate.hidden = false;
  gate.innerHTML = renderGate({ ...session, state: L.WORKING });
}

/** Any screen answering 401 sends us back to the gate rather than drawing nothing. */
export async function onUnauthorized() {
  await session.refresh();
  paintGate();
}

export async function boot() {
  await session.refresh();
  if (paintGate()) { return; }          // not signed in: the gate is the whole UI

  const h = await api.health();
  if (h.status === 401) { return onUnauthorized(); }
  state.health = h.status === 200 ? h.data : null;
  await render();
}
