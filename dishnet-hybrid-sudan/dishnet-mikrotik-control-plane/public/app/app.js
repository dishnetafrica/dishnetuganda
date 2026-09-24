/* DishNet operator app — the screens (docs/127 §H).
 *
 * The audited prototype's screens (prototype/dishnet-customer-pwa-prototype.html)
 * on the real data layer (public/pwa/api.js and store.js). What changed on the
 * way, and why:
 *
 *  - No mock data and no reviewer chrome: no persona switcher, no identity
 *    chain, no tamper demo, no guest-portal preview.
 *  - Every request goes through api.js. This file opens no connection of its
 *    own, holds no operator id, and never reads a code out of a response.
 *  - No inline handler, inline style or third-party origin: the server's
 *    Content-Security-Policy refuses them (H-3). Clicks and form submissions
 *    are delegated from data-act and data-form attributes.
 *  - What the platform cannot answer is UNAVAILABLE, never empty (G1–G3).
 *    What the signed-in person's role does not include is FORBIDDEN. A 202 is
 *    QUEUED, and nothing here says how or when a router is reached (B1).
 *  - The words are the review's (H-7). A code "is on its way" only IF the
 *    number can sign in; vouchers are recorded, but the Wi-Fi login that
 *    accepts them is not switched on; a device list says what was reported.
 *  - A write is never retried, and one write runs at a time (H-8).
 */
import { Api, State } from '/pwa/api.js';
import { Store } from '/pwa/store.js';

/* The server's own numbers, stated where the screen states them. The suite
   asserts each against its source: Authenticator::CODE_TTL_MINUTES, and the
   per-number limit in mt_auth_issue_code (5 codes in 15 minutes). */
const CODE_MINUTES = 10;
const LIMIT_MINUTES = 15;

const api = new Api('');
const store = new Store(api);

const ui = {
  view: 'home', arg: null,
  signin: { stage: 'phone', phone: '', error: '' },
  voucherFilter: 'unused',
  busy: false,
  uplink: null,
};

/* ---------------------------------------------------------------- helpers */
const $ = (sel) => document.querySelector(sel);
const esc = (v) => String(v ?? '').replace(/[&<>"']/g,
  (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

function money(minor, currency) {
  const cur = String(currency || 'UGX').toUpperCase();
  // UGX has no minor unit (ISO 4217 exponent 0), so minor units are shillings.
  const value = cur === 'UGX' ? Number(minor) : Number(minor) / 100;
  return cur + ' ' + value.toLocaleString('en-UG', { maximumFractionDigits: cur === 'UGX' ? 0 : 2 });
}
function duration(s) {
  const n = Number(s) || 0;
  const unit = (v, one, many) => v + ' ' + (v === 1 ? one : many);
  if (n > 0 && n % 86400 === 0) return unit(n / 86400, 'day', 'days');
  if (n > 0 && n % 3600 === 0)  return unit(n / 3600, 'hour', 'hours');
  if (n > 0 && n % 60 === 0)    return unit(n / 60, 'minute', 'minutes');
  return unit(n, 'second', 'seconds');
}
function speed(bps) {
  const n = Number(bps) || 0;
  if (n >= 1e6) return (Math.round(n / 1e5) / 10) + ' Mbps';
  return Math.round(n / 1e3) + ' kbps';
}
function bytes(v) {
  const n = Number(v) || 0;
  if (n >= 1e9) return (Math.round(n / 1e8) / 10) + ' GB';
  if (n >= 1e6) return (Math.round(n / 1e5) / 10) + ' MB';
  if (n >= 1e3) return Math.round(n / 1e3) + ' kB';
  return n + ' B';
}
function when(iso) {
  if (!iso) return '—';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '—';
  return d.toLocaleString('en-UG', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}
/* A form control by name. Never form.<name>: a form's own properties (name,
   length, action…) shadow its controls, and form.name is the form's name. */
const val = (form, n) => {
  const el = form.elements.namedItem(n);
  return el && 'value' in el ? String(el.value) : '';
};
function macTail(mac) {
  const parts = String(mac || '').split(/[:-]/);
  return parts.length >= 3 ? '…' + parts.slice(-3).join(':') : 'unknown';
}

/* What the signed-in person may do. A convenience for the screen only — the
   server decides, on every request, and a refusal still reads as forbidden. */
function can(cap) {
  const p = store.cust && store.cust.principal;
  if (!p) return false;
  if (p.kind === 'owner') return true;
  return Array.isArray(p.capabilities) && p.capabilities.includes(cap);
}

function siteName(id) {
  const s = store.sites.find((x) => x.id === id);
  return s ? s.name : 'All locations';
}

/* ---------------------------------------------------------------- chrome */
function bar(title, sub, back) {
  return `<div class="appbar">${back ? `<button class="bk" data-act="go" data-view="${esc(back)}" aria-label="Back">&#8249;</button>` : ''}
    <h1>${esc(title)}${sub ? `<span class="sub">${esc(sub)}</span>` : ''}</h1></div>`;
}
function tabs() {
  const T = [['home', '&#127968;', 'Home'], ['wifi', '&#128246;', 'Wi-Fi'], ['vouchers', '&#127915;', 'Vouchers'],
             ['plans', '&#128203;', 'Plans'], ['account', '&#128100;', 'Account']];
  return `<nav class="tabbar">${T.map(([k, i, l]) =>
    `<button class="tab ${ui.view === k ? 'on' : ''}" data-act="go" data-view="${k}"><span class="ic">${i}</span><span class="tl">${l}</span></button>`).join('')}</nav>`;
}
const shell = (head, body) => head + `<div class="body">${body}</div>` + tabs();

/* One card per non-OK state, so no screen collapses them (docs/82). */
function stateCard(state, what, reload) {
  if (state === State.FORBIDDEN) {
    return `<div class="card flat"><b class="sm">Not part of your role</b>
      <p class="tiny muted lh mt8">Your role doesn't include ${esc(what)}. Ask the owner of this account if you need it.</p></div>`;
  }
  if (state === State.UNAVAILABLE) {
    return `<div class="card flat"><b class="sm">Not available yet</b>
      <p class="tiny muted lh mt8">This app can't show ${esc(what)} yet.</p></div>`;
  }
  if (state === State.OFFLINE || state === State.FAILED) {
    return `<div class="card warn"><b class="sm">Couldn't load ${esc(what)}</b>
      <p class="tiny lh mt8">${state === State.OFFLINE ? 'No connection.' : 'Something went wrong.'}</p>
      ${reload ? `<button class="btn sm mt8" data-act="reload" data-key="${esc(reload)}">Try again</button>` : ''}</div>`;
  }
  if (state === State.LOADING) return `<p class="muted sm">Loading…</p>`;
  return '';
}
const ready = (state) => state === State.OK || state === State.EMPTY;

/* ---------------------------------------------------------------- sign-in */
function vSignIn() {
  const s = ui.signin;
  const brand = `<div class="brand"><div class="word"><span class="dish">DISH</span><span class="net">NET</span></div>
    <div class="co">AFRICA</div></div>`;
  const err = s.error ? `<p class="err" role="alert">${esc(s.error)}</p>` : '';
  const dis = ui.busy ? 'disabled' : '';
  if (s.stage === 'code') {
    return `<div class="signin">${brand}
      <h2 class="mb8">Enter your code</h2>
      <p class="sm muted lh mb14">If ${esc(s.phone)} can sign in, a code is on its way by SMS.
        It is valid for ${CODE_MINUTES} minutes.</p>
      ${err}
      <form data-form="verify" autocomplete="off">
        <div class="field"><label for="code">6-digit code</label>
          <input id="code" name="code" class="otp" inputmode="numeric" autocomplete="one-time-code"
                 maxlength="6" pattern="[0-9]{6}" required></div>
        <button class="btn primary" type="submit" ${dis}>Sign in</button>
      </form>
      <button class="btn link mt8" data-act="resend" ${dis}>Send a new code</button>
      <button class="btn link" data-act="other-number" ${dis}>Use a different number</button>
    </div>`;
  }
  return `<div class="signin">${brand}
    <h2 class="mb8">Sign in</h2>
    <p class="sm muted lh mb14">Enter the mobile number DishNet registered for you, in international form.
      We'll send a sign-in code by SMS.</p>
    ${err}
    <form data-form="request-code">
      <div class="field"><label for="phone">Mobile number</label>
        <input id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel"
               placeholder="+256 7xx xxx xxx" value="${esc(s.phone)}" required></div>
      <button class="btn primary" type="submit" ${dis}>Send code</button>
    </form>
    <p class="tiny muted lh mt20 center">This app is for the people who run a DishNet Wi-Fi location.
      Guests don't need an account.</p>
  </div>`;
}

function signinError(r, step) {
  if (r.state === State.OFFLINE) return 'No connection. Check your internet and try again.';
  if (r.status === 429) return `Too many codes were asked for this number. Wait ${LIMIT_MINUTES} minutes, then try again.`;
  if (r.status === 400) return step === 'request' ? 'Enter your mobile number.' : 'Enter the 6-digit code.';
  if (r.status === 401) return "That code didn't work. Check it, or send a new one.";
  return 'Something went wrong. Please try again.';
}

async function requestCode(phone) {
  if (ui.busy) return;
  ui.busy = true; ui.signin.error = ''; ui.signin.phone = phone; render();
  const r = await api.requestCode(phone);
  ui.busy = false;
  if (r.state === State.QUEUED) ui.signin = { stage: 'code', phone, error: '' };
  else ui.signin.error = signinError(r, 'request');
  render();
  const c = $('#code'); if (c) c.focus();
}

async function verify(code) {
  if (ui.busy) return;
  ui.busy = true; ui.signin.error = ''; render();
  const r = await api.verify(ui.signin.phone, code);
  ui.busy = false;
  if (r.state === State.OK && api.token) { await enter(); return; }
  ui.signin.error = signinError(r, 'verify');
  render();
}

async function enter() {
  ui.view = 'home'; ui.arg = null; ui.uplink = null;
  $('#app').innerHTML = '<p class="boot">Loading your account…</p>';
  const ok = await store.load();
  if (!ok) { signedOut('Your session ended. Please sign in again.'); return; }
  render();
}

function signedOut(message) {
  api.forget(); store.reset(); closeSheet();
  ui.signin = { stage: 'phone', phone: ui.signin.phone, error: message || '' };
  render();
}

/* A 401 on any request means the session is gone — ended, revoked, or its
   operator suspended (migration 031). Say so, rather than render half a page. */
function sessionGone(r) {
  if (r && r.status === 401) { signedOut('Your session ended. Please sign in again.'); return true; }
  return false;
}

/* ------------------------------------------------------------------- home */
const SERVICE_KIND = { mikrotik_hotspot: { name: 'Guest Wi-Fi', blurb: 'HotSpot with vouchers', ic: '&#128246;' } };
const SERVICE_STATUS = { active: ['On your account', 'p-ok'], suspended: ['Suspended', 'p-warn'], ended: ['Ended', 'p-mute'] };
const INTENT_KIND = { 'voucher.publish': 'Publish new vouchers', 'voucher.revoke': 'Remove a revoked voucher',
                      'session.disconnect': 'Disconnect a device', 'device.provision': 'Configure a router' };
const INTENT_STATE = { queued: ['Queued', 'p-info'], sent: ['Sent · not confirmed', 'p-info'],
                       failed: ['Did not complete', 'p-bad'], expired: ['Expired', 'p-mute'] };

function vHome() {
  const me = store.cust || {};
  const person = me.principal || {};
  let s = `<div class="mb14"><h2>${esc((me.customer && me.customer.name) || 'Your account')}</h2>
    <div class="tiny muted">${esc(person.display_name || '')}${person.kind ? ' · ' + (person.kind === 'owner' ? 'Owner' : 'Staff') : ''}</div></div>`;

  s += `<h3>My services</h3>`;
  if (!ready(store.state.services)) s += stateCard(store.state.services, 'your services', 'services');
  else if (!store.services.length) s += `<div class="card flat"><p class="sm lh">No service is on this account yet. DishNet sets it up.</p></div>`;
  else s += store.services.map((sv) => {
    const k = SERVICE_KIND[sv.kind] || { name: sv.kind, blurb: '', ic: '&#9679;' };
    const st = SERVICE_STATUS[sv.status] || [sv.status, 'p-mute'];
    return `<div class="svc card tap" data-act="go" data-view="wifi"><div class="ico">${k.ic}</div>
      <div class="grow"><div class="nm">${esc(k.name)}</div>
        <div class="tiny muted">${esc(k.blurb)}${sv.started_at ? ' · since ' + esc(when(sv.started_at)) : ''}</div>
        <div class="mt8"><span class="pill ${st[1]}"><i class="dot"></i>${esc(st[0])}</span></div></div></div>`;
  }).join('');

  s += queuedCard();
  s += `<div class="btns mt14"><button class="btn" data-act="go" data-view="wifi">Wi-Fi</button>
        <button class="btn" data-act="go" data-view="vouchers">Vouchers</button></div>`;
  return shell(bar('DishNet', 'Signed in'), s);
}

function queuedCard() {
  if (store.state.intents !== State.OK) return '';
  const open = store.intents.filter((i) => i.state !== 'confirmed').slice(0, 6);
  if (!open.length) return '';
  return `<div class="card info mt14"><div class="spread mb8"><b class="sm">Requests</b>
      <span class="pill p-info"><i class="dot"></i>${open.length}</span></div>
    ${open.map((i) => {
      const st = INTENT_STATE[i.state] || [i.state, 'p-mute'];
      return `<div class="kv"><span class="k">${esc(INTENT_KIND[i.kind] || i.kind)}</span>
        <span class="v"><span class="pill ${st[1]}"><i class="dot"></i>${esc(st[0])}</span></span></div>`;
    }).join('')}
    <p class="tiny muted lh mt8">A request stays here until it is confirmed.</p></div>`;
}

/* ------------------------------------------------------------------ Wi-Fi */
const hotspot = () => store.services.find((s) => s.kind === 'mikrotik_hotspot') || null;
const byState = (states) => store.vouchers.filter((v) => states.includes(v.state));
const sessionsAt = (siteId) => store.sessions.filter((x) => {
  const v = store.vouchers.find((w) => w.id === x.voucher_id);
  return v && v.site_id === siteId;
});

function vWifi() {
  if (ready(store.state.services) && !hotspot()) {
    return shell(bar('My Wi-Fi'), `<div class="empty"><div class="ic">&#128246;</div><h3>No Wi-Fi service yet</h3>
      <p>Guest Wi-Fi with vouchers isn't on this account. DishNet sets it up.</p></div>`);
  }
  const devices = ready(store.state.sessions) ? String(store.sessions.length) : '—';
  const codes = ready(store.state.vouchers) ? String(byState(['unused']).length) : '—';
  let s = `<div class="card dark"><div class="tiny muted">Reported now</div>
    <div class="stats mt8">
      <div><div class="big">${esc(devices)}</div><div class="lab">devices reported</div></div>
      <div><div class="big">${esc(codes)}</div><div class="lab">codes ready</div></div>
    </div></div>`;
  s += `<div class="card flat"><b class="sm">Access points</b>
    <p class="tiny muted lh mt8">Access-point status isn't available in this app yet.</p></div>`;
  s += `<div class="btns">${can('op.vouchers.issue') ? `<button class="btn primary" data-act="new-voucher">+ New voucher</button>` : ''}
    <button class="btn" data-act="go" data-view="devices">Devices</button></div>`;

  s += `<h3 class="mt20">My locations</h3>`;
  if (!ready(store.state.sites)) s += stateCard(store.state.sites, 'your locations', 'sites');
  else if (!store.sites.length) s += `<div class="card flat"><p class="sm lh">No location yet. DishNet adds your locations.</p></div>`;
  else s += store.sites.map((st) => `<div class="card tap" data-act="go" data-view="site" data-arg="${esc(st.id)}">
      <b>${esc(st.name)}</b><div class="tiny muted">${esc(st.location || '')}</div>
      <div class="kv mt8"><span class="k">Codes ready here</span><span class="v">${byState(['unused']).filter((v) => v.site_id === st.id).length}</span></div>
      <div class="kv"><span class="k">Devices reported here</span><span class="v">${sessionsAt(st.id).length}</span></div></div>`).join('');

  s += `<button class="btn ghost mt8" data-act="go" data-view="usage">&#128200; Usage</button>`;
  return shell(bar('My Wi-Fi', 'Guest HotSpot'), s);
}

function vSite() {
  const st = store.sites.find((x) => x.id === ui.arg);
  if (!st) return shell(bar('Location', '', 'wifi'), `<div class="empty"><p>Not available on this account.</p></div>`);
  let s = `<div class="card">
      <div class="kv"><span class="k">Codes ready here</span><span class="v">${byState(['unused']).filter((v) => v.site_id === st.id).length}</span></div>
      <div class="kv"><span class="k">Devices reported here</span><span class="v">${sessionsAt(st.id).length}</span></div>
      <div class="kv"><span class="k">Access points</span><span class="v muted">Not available yet</span></div></div>`;
  if (can('op.vouchers.issue')) s += `<button class="btn primary mt8" data-act="new-voucher" data-arg="${esc(st.id)}">+ New voucher for ${esc(st.name)}</button>`;
  s += `<button class="btn ghost mt8" data-act="go" data-view="devices">Devices</button>`;
  return shell(bar(st.name, st.location || '', 'wifi'), s);
}

/* --------------------------------------------------------------- vouchers */
const FILTERS = [['unused', 'Ready', ['unused']], ['active', 'In use', ['activating', 'active']],
                 ['done', 'Finished', ['expired', 'revoked']]];

function vVouchers() {
  let s = `<div class="card warn"><p class="tiny lh">Codes are recorded here. The Wi-Fi login that accepts them
    is not switched on yet, so guests can't use them yet.</p></div>`;
  if (!ready(store.state.vouchers)) return shell(bar('Vouchers', 'Guest access codes'), s + stateCard(store.state.vouchers, 'vouchers', 'vouchers'));

  const f = FILTERS.find((x) => x[0] === ui.voucherFilter) || FILTERS[0];
  s += `<div class="chips">${FILTERS.map(([k, l, st]) => {
    const n = byState(st).length;
    return `<button class="btn sm ${k === f[0] ? 'on' : ''}" data-act="voucher-filter" data-arg="${k}">${l}${n ? ' (' + n + ')' : ''}</button>`;
  }).join('')}</div>`;
  const list = byState(f[2]);
  if (!list.length) s += `<div class="empty"><div class="ic">&#127915;</div><p>Nothing here.</p></div>`;
  else s += `<div class="card">` + list.map((v) => `<div class="item"><div class="grow">
      <div class="code">${esc(v.code)}</div>
      <div class="tiny muted">${esc(duration(v.duration_s))} · ${esc(money(v.price_minor, v.currency))} · ${esc(siteName(v.site_id))}</div>
      <div class="tiny muted">${v.state === 'revoked' ? 'Revoked' : v.state === 'expired' ? 'Expired' : 'Created ' + esc(when(v.created_at))}</div></div>
      <div class="right"><button class="btn sm" data-act="copy" data-copy="${esc(v.code)}">Copy</button>
      ${v.state === 'unused' && can('op.vouchers.revoke') ? `<button class="btn sm danger mt8" data-act="revoke" data-arg="${esc(v.id)}" data-code="${esc(v.code)}">Revoke</button>` : ''}</div></div>`).join('') + `</div>`;
  if (can('op.vouchers.issue')) s += `<button class="btn primary mt8" data-act="new-voucher">+ New voucher</button>`;
  s += `<p class="tiny muted lh mt14">A voucher gives someone internet access. It does not create a DishNet account
    for them, and it does not let them see this app.</p>`;
  return shell(bar('Vouchers', 'Guest access codes'), s);
}

function openNewVoucher(siteId) {
  const plans = store.plans.filter((p) => p.active);
  if (!ready(store.state.sites) || !store.sites.length) {
    return openSheet(`<div class="grab"></div><h2 class="mb8">New voucher</h2>
      <p class="sm muted lh">No location yet. DishNet adds your locations.</p>
      <button class="btn mt14" data-act="close-sheet">Close</button>`);
  }
  if (!ready(store.state.plans) || !plans.length) {
    return openSheet(`<div class="grab"></div><h2 class="mb8">New voucher</h2>
      <p class="sm muted lh">Create a plan first. A plan sets how long a code lasts and what it costs.</p>
      ${can('op.plans.write') ? '<button class="btn primary mt14" data-act="go-plans">Go to plans</button>' : ''}
      <button class="btn mt8" data-act="close-sheet">Close</button>`);
  }
  const sel = siteId || store.sites[0].id;
  openSheet(`<div class="grab"></div><h2 class="mb14">New voucher</h2>
    <form data-form="new-voucher">
      <div class="field"><label for="nv-site">Location</label>
        <select id="nv-site" name="site">${store.sites.map((st) =>
          `<option value="${esc(st.id)}" ${st.id === sel ? 'selected' : ''}>${esc(st.name)}</option>`).join('')}</select></div>
      <div class="field"><span class="label">Plan</span>
        ${plans.map((p, i) => `<label class="opt"><input type="radio" name="plan" value="${esc(p.id)}" ${i === 0 ? 'checked' : ''}>
          <span class="grow"><b>${esc(p.name)}</b><span class="tiny muted"> · ${esc(duration(p.duration_s))} · up to ${esc(p.devices_per_voucher)} device${Number(p.devices_per_voucher) === 1 ? '' : 's'}</span></span>
          <b class="sm nowrap">${esc(money(p.price_minor, p.currency))}</b></label>`).join('')}</div>
      <div class="field"><label for="nv-count">How many codes</label>
        <input id="nv-count" name="count" type="number" min="1" max="50" value="1" required></div>
      <p class="err" id="nv-error" hidden></p>
      <button class="btn primary" type="submit" ${ui.busy ? 'disabled' : ''}>Create</button>
    </form>
    <button class="btn link mt8" data-act="close-sheet">Cancel</button>`);
}

async function createVouchers(form) {
  if (ui.busy) return;
  const planId = (form.querySelector('input[name=plan]:checked') || {}).value;
  const siteId = val(form, 'site');
  const count = Math.max(1, Math.min(50, parseInt(val(form, 'count'), 10) || 1));
  if (!planId) return showFormError('#nv-error', 'Choose a plan.');
  busyForm(form, true);
  const r = await store.requestVouchers(planId, count, siteId);
  busyForm(form, false);
  if (sessionGone(r)) return;
  if (r.state === State.QUEUED || r.state === State.OK) {
    const codes = ((r.data && r.data.vouchers) || []).map((v) => v.code);
    await store.reload('intents');
    render();
    return openSheet(`<div class="grab"></div><div class="center">
      <div class="ic">&#127915;</div><h2 class="mt8">${codes.length === 1 ? 'Voucher created' : codes.length + ' vouchers created'}</h2>
      <div class="codes">${codes.map(esc).join('<br>')}</div>
      <p class="sm muted lh">${esc(siteName(siteId))}</p></div>
      <div class="card info mt14"><p class="tiny lh"><b>Queued.</b> Publishing these codes to your Wi-Fi is waiting.
        You'll see it under Requests until it is confirmed.</p></div>
      <button class="btn primary mt14" data-act="copy" data-copy="${esc(codes.join('\n'))}">Copy ${codes.length === 1 ? 'code' : 'codes'}</button>
      <button class="btn ghost mt8" data-act="done-vouchers">Done</button>`);
  }
  showFormError('#nv-error', writeError(r, 'create vouchers', {
    404: 'That plan or location is no longer available.',
  }));
}

function openRevoke(id, code) {
  openSheet(`<div class="grab"></div><h2 class="mb8">Revoke ${esc(code)}?</h2>
    <p class="sm muted lh">The code will no longer be valid. This can't be undone.</p>
    <p class="err" id="rv-error" hidden></p>
    <button class="btn primary mt14" data-act="revoke-confirm" data-arg="${esc(id)}" ${ui.busy ? 'disabled' : ''}>Revoke</button>
    <button class="btn link mt8" data-act="close-sheet">Keep it</button>`);
}

async function revoke(id, btn) {
  if (ui.busy) return;
  ui.busy = true; if (btn) btn.disabled = true;
  const r = await api.revokeVoucher(id);
  ui.busy = false; if (btn) btn.disabled = false;
  if (sessionGone(r)) return;
  if (r.state === State.QUEUED || r.state === State.OK) {
    closeSheet();
    await Promise.all([store.reload('vouchers'), store.reload('intents')]);
    render();
    return toast('Revoked. Removing it from your Wi-Fi is queued.');
  }
  showFormError('#rv-error', writeError(r, 'revoke vouchers', { 404: 'That voucher can no longer be revoked.' }));
}

/* ------------------------------------------------------------------ plans */
function vPlans() {
  let s = '';
  if (!ready(store.state.plans)) return shell(bar('Plans', 'What your codes cost'), stateCard(store.state.plans, 'plans', 'plans'));
  if (!store.plans.length) s += `<div class="empty"><div class="ic">&#128203;</div><h3>No plans yet</h3>
    <p>A plan sets how long a code lasts, how fast it is, and what it costs.</p></div>`;
  else s += store.plans.map((p) => `<div class="card"><div class="spread"><div class="grow"><b>${esc(p.name)}</b>
      <div class="tiny muted">${esc(duration(p.duration_s))} · ${esc(speed(p.rate_down_bps))} down / ${esc(speed(p.rate_up_bps))} up</div>
      <div class="tiny muted">Up to ${esc(p.devices_per_voucher)} device${Number(p.devices_per_voucher) === 1 ? '' : 's'}${p.data_cap_bytes ? ' · ' + esc(bytes(p.data_cap_bytes)) + ' of data' : ''}${p.mode === 'paused' ? ' · time counts only while connected' : ''}</div></div>
      <div class="right"><b class="nowrap">${esc(money(p.price_minor, p.currency))}</b>
        <div class="mt8">${p.active ? '<span class="pill p-ok"><i class="dot"></i>On sale</span>' : '<span class="pill p-mute"><i class="dot"></i>Retired</span>'}</div></div></div>
      ${p.active && can('op.plans.write') ? `<button class="btn sm ghost mt8" data-act="retire" data-arg="${esc(p.id)}" data-name="${esc(p.name)}">Retire</button>` : ''}</div>`).join('');
  if (can('op.plans.write')) s += `<button class="btn primary mt8" data-act="new-plan">+ New plan</button>`;
  return shell(bar('Plans', 'What your codes cost'), s);
}

function openNewPlan() {
  openSheet(`<div class="grab"></div><h2 class="mb14">New plan</h2>
    <form data-form="new-plan">
      <div class="field"><label for="np-name">Name</label><input id="np-name" name="name" maxlength="80" placeholder="1 Day" required></div>
      <div class="pair"><div class="field"><label for="np-len">Lasts</label><input id="np-len" name="len" type="number" min="1" value="1" required></div>
        <div class="field"><label for="np-unit">&nbsp;</label><select id="np-unit" name="unit">
          <option value="60">minutes</option><option value="3600">hours</option><option value="86400" selected>days</option></select></div></div>
      <div class="pair"><div class="field"><label for="np-down">Download (Mbps)</label><input id="np-down" name="down" type="number" min="0.1" step="0.1" value="10" required></div>
        <div class="field"><label for="np-up">Upload (Mbps)</label><input id="np-up" name="up" type="number" min="0.1" step="0.1" value="3" required></div></div>
      <div class="pair"><div class="field"><label for="np-dev">Devices per code</label><input id="np-dev" name="devices" type="number" min="1" value="1" required></div>
        <div class="field"><label for="np-data">Data (GB, optional)</label><input id="np-data" name="data" type="number" min="0.1" step="0.1"></div></div>
      <div class="field"><label for="np-price">Price (UGX)</label><input id="np-price" name="price" type="number" min="0" step="1" value="0" required>
        <div class="hint">Zero is fine — you can hand codes out free.</div></div>
      <div class="field"><label for="np-mode">Time counts</label><select id="np-mode" name="mode">
        <option value="elapsed" selected>From first use, continuously</option><option value="paused">Only while connected</option></select></div>
      <div class="err" id="np-error" hidden></div>
      <button class="btn primary" type="submit" ${ui.busy ? 'disabled' : ''}>Create plan</button>
    </form>
    <button class="btn link mt8" data-act="close-sheet">Cancel</button>`);
}

async function createPlan(form) {
  if (ui.busy) return;
  const num = (n) => Number(val(form, n).trim());
  const data = val(form, 'data').trim();
  const plan = {
    name: val(form, 'name').trim(),
    duration_s: Math.round(num('len') * num('unit')),
    rate_down_bps: Math.round(num('down') * 1e6),
    rate_up_bps: Math.round(num('up') * 1e6),
    devices_per_voucher: Math.round(num('devices')),
    data_cap_bytes: data === '' ? null : Math.round(Number(data) * 1e9),
    mode: val(form, 'mode'),
    price_minor: Math.round(num('price')),   // UGX: whole shillings (exponent 0)
    currency: 'UGX',
  };
  busyForm(form, true);
  const r = await api.createPlan(plan);
  busyForm(form, false);
  if (sessionGone(r)) return;
  if (r.state === State.OK) {
    closeSheet();
    await store.reload('plans');
    render();
    return toast('Plan created.');
  }
  if (r.status === 422 && r.data && Array.isArray(r.data.reasons)) {
    return showFormError('#np-error', r.data.reasons.join(' · '));
  }
  showFormError('#np-error', writeError(r, 'create plans', { 409: 'A plan with that name already exists.' }));
}

function openRetire(id, name) {
  openSheet(`<div class="grab"></div><h2 class="mb8">Retire ${esc(name)}?</h2>
    <p class="sm muted lh">It stays on record, and no new codes can be made on it.</p>
    <p class="err" id="rt-error" hidden></p>
    <button class="btn primary mt14" data-act="retire-confirm" data-arg="${esc(id)}">Retire</button>
    <button class="btn link mt8" data-act="close-sheet">Keep it</button>`);
}

async function retire(id, btn) {
  if (ui.busy) return;
  ui.busy = true; if (btn) btn.disabled = true;
  const r = await api.retirePlan(id);
  ui.busy = false; if (btn) btn.disabled = false;
  if (sessionGone(r)) return;
  if (r.state === State.OK) {
    closeSheet();
    await store.reload('plans');
    render();
    return toast('Plan retired.');
  }
  showFormError('#rt-error', writeError(r, 'retire plans', { 404: 'That plan is no longer available.' }));
}

/* ---------------------------------------------------------------- devices */
function vDevices() {
  let s = `<p class="sm muted lh mb14">Guest devices your Wi-Fi has reported as connected.</p>`;
  if (!ready(store.state.sessions)) return shell(bar('Devices', '', 'wifi'), s + stateCard(store.state.sessions, 'devices', 'sessions'));
  if (!store.sessions.length) {
    s += `<div class="empty"><div class="ic">&#128241;</div><h3>No device has been reported</h3>
      <p>Devices appear here when your Wi-Fi reports them.</p></div>`;
  } else {
    s += `<div class="card">` + store.sessions.map((x) => {
      const v = store.vouchers.find((w) => w.id === x.voucher_id);
      return `<div class="item"><div class="grow"><b class="sm">Device ${esc(macTail(x.mac))}</b>
        <div class="tiny muted">${v ? esc(siteName(v.site_id)) + ' · ' : ''}since ${esc(when(x.started_at))}${v ? ' · <span class="mono">' + esc(v.code) + '</span>' : ''}</div></div>
        <div class="right"><div class="sm">${esc(bytes(x.bytes_out))}</div><div class="tiny muted">received</div>
        ${can('op.sessions.disconnect') ? `<button class="btn sm danger mt8" data-act="disconnect" data-arg="${esc(x.id)}">Disconnect</button>` : ''}</div></div>`;
    }).join('') + `</div>`;
  }
  s += `<p class="tiny muted lh mt14">Device owners are guests, not DishNet account holders.</p>`;
  return shell(bar('Devices', ready(store.state.sessions) ? store.sessions.length + ' reported' : '', 'wifi'), s);
}

async function disconnect(id, btn) {
  if (ui.busy) return;
  ui.busy = true; if (btn) btn.disabled = true;
  const r = await api.disconnect(id);
  ui.busy = false; if (btn) btn.disabled = false;
  if (sessionGone(r)) return;
  if (r.state === State.QUEUED || r.state === State.OK) {
    await store.reload('intents');
    render();
    return toast('Disconnect queued.');
  }
  toast(writeError(r, 'disconnect devices', { 404: 'That device is no longer connected.' }));
}

/* ------------------------------------------------------------------ usage */
function vUsage() {
  const u = store.usage && store.usage.usage;
  let s = '';
  if (!ready(store.state.usage) || !u) s += stateCard(store.state.usage, 'usage', null);
  else {
    // RFC 2866: Acct-Input-Octets are what the NAS received FROM the guest's
    // device, Acct-Output-Octets what it sent TO it. bytes_in / bytes_out.
    s += `<div class="card">
      <div class="kv"><span class="k">Devices reported now</span><span class="v">${esc(u.open_now)}</span></div>
      <div class="kv"><span class="k">Sessions recorded</span><span class="v">${esc(u.sessions_total)}</span></div>
      <div class="kv"><span class="k">Received by guests</span><span class="v">${esc(bytes(u.bytes_out))}</span></div>
      <div class="kv"><span class="k">Sent by guests</span><span class="v">${esc(bytes(u.bytes_in))}</span></div></div>
      <p class="tiny muted lh">Counts cover what your Wi-Fi has reported for your own locations.</p>`;
  }
  s += `<h3 class="mt20">Your internet connection</h3>`;
  const up = ui.uplink;
  if (!up) s += `<p class="muted sm">Loading…</p>`;
  else if (up.state !== State.OK) s += stateCard(up.state, 'connection measurements', null);
  else if (!up.data.uplink || !up.data.uplink.samples) s += `<div class="card flat"><p class="sm lh">No measurements yet.</p></div>`;
  else {
    const m = up.data.uplink;
    s += `<div class="card"><div class="kv"><span class="k">Highest download</span><span class="v">${esc(speed(m.peak_rx_bps))}</span></div>
      <div class="kv"><span class="k">Average download</span><span class="v">${esc(speed(m.mean_rx_bps))}</span></div>
      <div class="kv"><span class="k">Highest upload</span><span class="v">${esc(speed(m.peak_tx_bps))}</span></div>
      <div class="kv"><span class="k">Last measured</span><span class="v">${esc(when(m.last_sample_at))}</span></div></div>
      <p class="tiny muted lh">Observed throughput on your own connection over the last day.</p>`;
  }
  return shell(bar('Usage', '', 'wifi'), s);
}

async function loadUplink() {
  const r = await api.uplink();
  if (sessionGone(r)) return;
  ui.uplink = r;
  if (ui.view === 'usage') render();
}

/* ---------------------------------------------------------------- account */
function vAccount() {
  const me = store.cust || {};
  const p = me.principal || {};
  const role = p.kind === 'owner' ? 'Owner — everything on this account'
    : (Array.isArray(p.capabilities) ? `Staff — ${p.capabilities.length} permission${p.capabilities.length === 1 ? '' : 's'}` : 'Staff');
  const s = `<div class="card">
      <div class="kv"><span class="k">Account</span><span class="v">${esc((me.customer && me.customer.name) || '—')}</span></div>
      <div class="kv"><span class="k">Your name</span><span class="v">${esc(p.display_name || '—')}</span></div>
      <div class="kv"><span class="k">Your role</span><span class="v">${esc(role)}</span></div></div>
    <div class="card flat"><b class="sm">Billing</b><p class="tiny muted lh mt8">Billing isn't available in this app yet.</p></div>
    <div class="card flat"><b class="sm">Support</b><p class="tiny muted lh mt8">Support isn't available in this app yet.</p></div>
    <button class="btn ghost danger mt8" data-act="signout">Sign out</button>`;
  return shell(bar('Account'), s);
}

/* ------------------------------------------------------ sheet, toast, forms */
function openSheet(html) {
  $('#sheetbox').innerHTML = html;
  $('#sheet').hidden = false;
}
function closeSheet() { const sh = $('#sheet'); if (sh) { sh.hidden = true; $('#sheetbox').innerHTML = ''; } }
function toast(message) {
  const t = document.createElement('div');
  t.className = 'toast'; t.textContent = message;
  $('#toasts').appendChild(t);
  setTimeout(() => t.remove(), 3400);
}
function busyForm(form, on) {
  ui.busy = on;
  form.querySelectorAll('button, input, select').forEach((el) => { el.disabled = on; });
}
function showFormError(sel, message) {
  const el = $(sel);
  if (el) { el.textContent = message; el.hidden = false; } else toast(message);
}
function writeError(r, what, byStatus) {
  if (r.state === State.OFFLINE) return "No connection. Nothing was changed — check your internet and try again.";
  if (r.state === State.FORBIDDEN) return `Your role doesn't include permission to ${what}.`;
  if (byStatus && byStatus[r.status]) return byStatus[r.status];
  if (r.status === 400 && r.data && r.data.error) return String(r.data.error);
  return 'Something went wrong. Nothing was changed.';
}

/* ----------------------------------------------------------------- render */
const VIEWS = { home: vHome, wifi: vWifi, site: vSite, vouchers: vVouchers, plans: vPlans,
                devices: vDevices, usage: vUsage, account: vAccount };

function render() {
  if (!api.token) { $('#app').innerHTML = vSignIn(); return; }
  $('#app').innerHTML = (VIEWS[ui.view] || vHome)();
  const b = document.querySelector('.body'); if (b) b.scrollTop = 0;
}

function go(view, arg) {
  ui.view = VIEWS[view] ? view : 'home'; ui.arg = arg || null;
  closeSheet();
  render();
  if (ui.view === 'usage' && !ui.uplink) loadUplink();
}

async function copy(text) {
  try { await navigator.clipboard.writeText(text); toast('Copied.'); }
  catch { toast("Couldn't copy on this device. Select the code and copy it by hand."); }
}

document.addEventListener('click', (e) => {
  const sh = $('#sheet');
  if (e.target === sh) { closeSheet(); return; }
  const t = e.target.closest('[data-act]');
  if (!t || t.disabled) return;
  const a = t.dataset;
  switch (a.act) {
    case 'go':             go(a.view, a.arg); break;
    case 'reload':         store.reload(a.key).then((r) => { if (!sessionGone(r)) render(); }); break;
    case 'resend':         requestCode(ui.signin.phone); break;
    case 'other-number':   ui.signin = { stage: 'phone', phone: '', error: '' }; render(); break;
    case 'signout':        api.logout().then(() => signedOut('')); break;
    case 'new-voucher':    openNewVoucher(a.arg); break;
    case 'done-vouchers':  closeSheet(); ui.voucherFilter = 'unused'; go('vouchers'); break;
    case 'voucher-filter': ui.voucherFilter = a.arg; render(); break;
    case 'copy':           copy(a.copy || ''); break;
    case 'revoke':         openRevoke(a.arg, a.code); break;
    case 'revoke-confirm': revoke(a.arg, t); break;
    case 'new-plan':       openNewPlan(); break;
    case 'go-plans':       go('plans'); break;
    case 'retire':         openRetire(a.arg, a.name); break;
    case 'retire-confirm': retire(a.arg, t); break;
    case 'disconnect':     disconnect(a.arg, t); break;
    case 'close-sheet':    closeSheet(); break;
    default: break;
  }
});

document.addEventListener('submit', (e) => {
  const f = e.target.closest('form[data-form]');
  if (!f) return;
  e.preventDefault();
  switch (f.dataset.form) {
    case 'request-code': requestCode(val(f, 'phone').trim()); break;
    case 'verify':       verify(val(f, 'code').trim()); break;
    case 'new-voucher':  createVouchers(f); break;
    case 'new-plan':     createPlan(f); break;
    default: break;
  }
});

/* ------------------------------------------------------------------- boot */
if (api.restore()) enter(); else render();
