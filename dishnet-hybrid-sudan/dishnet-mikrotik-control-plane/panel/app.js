/* DishNet Admin Web — views.
 *
 * The V2 prototype is the UX source of truth; its information architecture is
 * preserved, not redesigned. Reseller navigation and tenant management are
 * absent by decision (docs/81 §9), not by omission.
 *
 * READ ONLY. No view renders a control that changes state.
 */
import { Session, renderGate, L } from './login.js';
import { AdminApi, S, cohort, COHORT_LABEL, contactAge, evidenceLevel } from './api.js';

const api = new AdminApi();
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
  { id: 'customers',   label: 'Customers & sites' },
  { id: 'plans',       label: 'Plans' },
  { id: 'vouchers',    label: 'Vouchers' },
  { id: 'batches',     label: 'Batches' },
  { sec: 'Administration' },
  { id: 'audit',       label: 'Audit log' },
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
    [S.UNAUTHORIZED]: ['Not signed in',  'No staff identity was accepted. A staff identity provider has not been selected yet, so the default binding admits nobody.'],
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
 * Customer column tells an operator nothing and reads like a stray identifier. */
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

  return head('Routers', `${all.length} in the estate`) + cohorts + search() + (
    rows.length === 0
      ? `<div class="stateblock empty"><h3>Nothing matches</h3><p>${
          esc(all.length)} routers exist; none match this filter.</p></div>`
      : table(['Serial', 'Name', 'Model', 'State', 'Last contact', 'Customer', 'Site'], rows,
          r => `<tr data-router="${esc(r.id)}">
            <td class="mono">${esc(r.serial)}</td>
            <td>${esc(r.name) || '—'}</td>
            <td>${esc(r.model)}</td>
            <td><span class="pill">${esc(r.state)}</span></td>
            <td><span class="hs ${cohort(r.last_seen_at)}"><i></i>${esc(contactAge(r.last_seen_at))}</span></td>
            <td>${esc(custName(r.customer_id)) || '—'}</td>
            <td>${esc(siteName(r.site_id)) || '—'}</td></tr>`));
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
 * `concise` is the operator's view: the signal and a short verdict, nothing
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

/* Every action is rendered inert, with the server's reason attached.
 *
 * They are shown rather than hidden on purpose: an operator should be able to
 * see what this product will eventually do and why it cannot do it yet. The
 * buttons carry the disabled attribute and no handler is bound to them. */
function actionPanel(sig) {
  const acts = (sig && sig.actions) || [];
  if (!acts.length) return '';
  return `<h2 class="sub">Actions</h2><div class="actions">` + acts.map(a => `
    <div class="action">
      <button class="btn" disabled aria-disabled="true" title="${esc(a.reason)}">${esc(a.label)}</button>
      <p>${esc(a.reason)}</p>
    </div>`).join('') + `</div>`;
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
    ['Customer', custName(r.customer_id)], ['Site', siteName(r.site_id)],
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

  return head('Router', esc(r.serial)) +
    `<h2 class="sub">Identity</h2>` + identity +
    `<h2 class="sub">Connectivity</h2>` + connectivity +
    (r.wan_interface ? '' : `<div class="note">No WAN interface has been established for this
      router, so its uplink is not measurable. That is a provisioning gap, not a fault.</div>`) +
    `<h2 class="sub">Provisioning</h2>` + ladder(r) +
    `<h2 class="sub">Operations</h2>` + operations +
    `<h2 class="sub">Signals</h2>` + signalPanel(sig, true) +
    actionPanel(sig);
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
    ['Customer', custName(v.customer_id)], ['Site', siteName(v.site_id)],
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

const vCustomers = list('Customers & Sites', () => api.customers(), 'customer',
  ['Name', 'Account', 'Status', 'Since'], c => `<tr>
    <td>${esc(c.name)}</td><td class="mono">${esc(c.ucrm_client_id) || '—'}</td>
    <td><span class="pill">${esc(c.status)}</span></td>
    <td>${esc((c.created_at || '').slice(0, 10))}</td></tr>`, 'customers');

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

const VIEWS = { routers: vRouters, router: vRouter, customers: vCustomers, plans: vPlans,
                vouchers: vVouchers, batches: vBatches, sessions: vSessions,
                intents: vIntents, audit: vAudit, dashboard: vDashboard,
                network: vNetwork, diagnostics: vDiagnostics,
                hotspot: vHotspot, voucher: vVoucher };

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
      await session.login(b.dataset.role);
      if (!paintGate()) { await boot(); }
    };
  });
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
