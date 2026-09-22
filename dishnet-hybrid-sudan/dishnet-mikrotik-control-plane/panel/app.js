/* DishNet Admin Web — views.
 *
 * The V2 prototype is the UX source of truth; its information architecture is
 * preserved, not redesigned. Reseller navigation and tenant management are
 * absent by decision (docs/81 §9), not by omission.
 *
 * READ ONLY. No view renders a control that changes state.
 */
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
async function vRouters() {
  const res = await api.routers();
  if (!isOk(res)) return head('MT Routers') + stateBlock(res, 'routers');

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

  return head('MT Routers', `${all.length} in the estate`) + cohorts + search() + (
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
            <td class="mono">${short(r.customer_id)}</td>
            <td class="mono">${short(r.site_id)}</td></tr>`));
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
function signalPanel(sig) {
  if (!sig || !sig.signals) {
    return `<div class="note">The signal inventory could not be read, so no signal is being shown.</div>`;
  }
  return `<div class="signals">` + sig.signals.map(x => `
    <div class="signal ${esc(x.status)}">
      <span class="dot"></span>
      <div>
        <b>${esc(x.label)}</b>
        <span class="verdict">${x.status === 'measured' ? 'measured' : 'no signal'}</span>
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
  const f = [['Serial', r.serial], ['Name', r.name], ['Model', r.model],
             ['RouterOS', r.ros_version], ['State', r.state],
             ['Tunnel address', r.tunnel_ip], ['WAN interface', r.wan_interface],
             ['WAN established by', r.wan_interface_set_by],
             ['Last contact', contactAge(r.last_seen_at)],
             ['Staged by', r.staged_by], ['Claimed', r.claimed_at],
             ['Customer', short(r.customer_id)], ['Site', short(r.site_id)]];
  const sres = await api.networkSignals();
  const sig = sres.status === 200 ? sres.data : null;
  return head('Router', esc(r.serial)) +
    `<div class="kv">${f.map(([k, v]) =>
      `<div><dt>${esc(k)}</dt><dd>${v == null || v === '' ? '—' : esc(v)}</dd></div>`).join('')}</div>` +
    (r.wan_interface ? '' : `<div class="note">No WAN interface has been established for this
      router, so its uplink is not measurable. That is a provisioning gap, not a fault.</div>`) +
    `<h2 class="sub">Provisioning</h2>` + ladder(r) +
    `<h2 class="sub">Signals</h2>` + signalPanel(sig) +
    actionPanel(sig);
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

const vVouchers = list('MT Vouchers', () => api.vouchers(), 'voucher',
  ['Reference', 'State', 'Price', 'Issued', 'Activated', 'Expires'], v => `<tr>
    <td class="mono">${short(v.id)}</td><td><span class="pill">${esc(v.state)}</span></td>
    <td>${ugx(v.price_minor)}</td><td>${esc((v.created_at||'').slice(0,16).replace('T',' '))}</td>
    <td>${esc((v.activated_at||'').slice(0,16).replace('T',' ')) || '—'}</td>
    <td>${esc((v.expires_at||'').slice(0,16).replace('T',' ')) || '—'}</td></tr>`, 'vouchers');

const vBatches = list('Batches', () => api.batches(), 'batch',
  ['Reference', 'Requested', 'Issued', 'State', 'Created'], b => `<tr>
    <td class="mono">${short(b.id)}</td><td>${esc(b.requested_count)}</td>
    <td>${esc(b.issued_count)}</td><td><span class="pill">${esc(b.state)}</span></td>
    <td>${esc((b.created_at||'').slice(0,16).replace('T',' '))}</td></tr>`, 'batches');

const vSessions = list('MT Sessions', () => api.sessions(), 'session',
  ['Reference', 'NAS', 'Address', 'In', 'Out', 'State', 'Started'], s => `<tr>
    <td class="mono">${short(s.id)}</td><td>${esc(s.nas_identifier) || '—'}</td>
    <td class="mono">${esc(s.ip) || '—'}</td>
    <td>${esc(s.bytes_in)}</td><td>${esc(s.bytes_out)}</td>
    <td><span class="pill">${esc(s.state)}</span></td>
    <td>${esc((s.started_at||'').slice(0,16).replace('T',' '))}</td></tr>`, 'sessions');

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
                network: vNetwork, diagnostics: vDiagnostics };

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
  const q = document.getElementById('q');
  if (q) {
    q.oninput = () => { state.q = q.value; };
    q.onkeyup = e => { if (e.key === 'Enter' || state.q === '') render(); };
    q.onsearch = () => render();
  }
}

export async function boot() {
  const h = await api.health();
  state.health = h.status === 200 ? h.data : null;
  await render();
}
