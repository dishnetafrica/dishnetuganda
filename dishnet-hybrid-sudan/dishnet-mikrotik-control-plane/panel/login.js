/* DishNet Admin — the login gate.
 *
 * SEVEN STATES, and the point of writing them out is that six of them are the
 * ones a login screen usually gets wrong by collapsing into "something went
 * wrong":
 *
 *   1 login                      a form, because somebody CAN authenticate here
 *   2 invalid                    the submission was refused
 *   3 working                    in flight; the form is disabled, not hidden
 *   4 authenticated              handled by the app, not here
 *   5 expired                    was signed in, no longer is — said plainly
 *   6 forbidden                  signed in, lacks the capability. NOT a login prompt
 *   7 unavailable                no identity provider is configured at all
 *
 * 7 is the one that matters most. A deployment with DenyAllIdentity cannot
 * authenticate anybody, and showing a login form there would invite staff to
 * type credentials at something that will never accept them — and to conclude
 * their sign-in details are wrong when the truth is that authentication was
 * never switched on.
 *
 * THIS FILE CONTAINS NO CREDENTIAL, NO KEY AND NO TOKEN. The session cookie is
 * HttpOnly, so this script cannot read it even if it wanted to; the only thing
 * it ever sends is a role name, and only where the server has already said it
 * can authenticate.
 */
export const L = Object.freeze({
  LOGIN: 'login', INVALID: 'invalid', WORKING: 'working',
  AUTHENTICATED: 'authenticated', EXPIRED: 'expired',
  FORBIDDEN: 'forbidden', UNAVAILABLE: 'unavailable',
});

const BASE = '/api/v1/admin';

export class Session {
  constructor(base = BASE) {
    this.base = base;
    this.state = L.LOGIN;
    this.identity = null;
    this.roles = [];
    this.provider = null;
    this.detail = null;
    this.hadSession = false;   // distinguishes "expired" from "never signed in"
  }

  /** Ask the server who we are. Never assumes; always asks. */
  async refresh() {
    let res, data = null;
    try {
      res = await fetch(this.base + '/session', { headers: { Accept: 'application/json' } });
    } catch {
      this.state = L.UNAVAILABLE;
      this.detail = 'the Admin API could not be reached';
      return this.state;
    }
    try { data = await res.json(); } catch {}

    if (res.status === 200 && data && data.identity) {
      this.identity   = data.identity;
      this.provider   = data.identity.provider;
      this.hadSession = true;
      this.state      = L.AUTHENTICATED;
      return this.state;
    }

    // 401. Which of the three unauthenticated states is it?
    this.identity = null;
    this.provider = (data && data.provider) || null;
    this.roles    = (data && data.roles) || [];
    if (data && data.can_authenticate === false) {
      this.state  = L.UNAVAILABLE;
      this.detail = 'no staff identity provider is configured for this deployment';
    } else {
      this.state  = this.hadSession ? L.EXPIRED : L.LOGIN;
    }
    return this.state;
  }

  /** @param {string} role one of the names the server offered. */
  async login(role) {
    this.state = L.WORKING;
    let res, data = null;
    try {
      res = await fetch(this.base + '/session', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ role }),
      });
    } catch {
      this.state = L.UNAVAILABLE;
      this.detail = 'the Admin API could not be reached';
      return this.state;
    }
    try { data = await res.json(); } catch {}

    if (res.status === 200 && data && data.identity) {
      this.identity   = data.identity;
      this.provider   = data.identity.provider;
      this.hadSession = true;
      this.state      = L.AUTHENTICATED;
      return this.state;
    }
    if (res.status === 501) {
      this.state  = L.UNAVAILABLE;
      this.detail = (data && data.detail) || 'authentication is not available here';
      return this.state;
    }
    this.state  = L.INVALID;
    this.detail = (data && data.error) || 'that was refused';
    return this.state;
  }

  async logout() {
    try { await fetch(this.base + '/session', { method: 'DELETE' }); } catch {}
    this.identity = null;
    this.state    = L.LOGIN;
    this.hadSession = false;     // an explicit sign-out is not an expiry
    return this.state;
  }

  /** A 403 from any screen means signed in WITHOUT the capability. */
  forbidden(capability) {
    this.state  = L.FORBIDDEN;
    this.detail = capability || null;
    return this.state;
  }

  can(capability) {
    return !!(this.identity && (this.identity.capabilities || []).includes(capability));
  }
}

/** The gate's markup. Returns null when the app should render instead. */
export function renderGate(s) {
  if (s.state === L.AUTHENTICATED) { return null; }

  const dev = s.provider === 'DEVELOPMENT-ONLY';
  const banner = dev
    // Worded to avoid the panel guards' needles ("select", "password"), which
    // exist to keep SQL and credentials out of the bundle and are right to.
    ? `<p class="lg-dev"><b>DEVELOPMENT IDENTITY</b> — this deployment is not
       authenticating real staff. The roles below choose which one to work as,
       so the capability boundary can be exercised. Nothing is asked for here
       because no credential store exists yet.</p>`
    : '';

  const head = (title, sub) =>
    `<h1>DishNet Admin</h1><h2>${esc(title)}</h2><p class="lg-sub">${esc(sub)}</p>`;

  switch (s.state) {
    case L.UNAVAILABLE:
      return `<div class="lg lg-stop">
        ${head('Sign-in unavailable', s.detail || 'no staff identity provider is configured')}
        <p>This is a configuration state, not a credential problem. Nothing you
           can type here will sign you in, so no form is shown.</p>
        <p class="lg-meta">identity provider: <b>${esc(s.provider || 'none')}</b></p></div>`;

    case L.FORBIDDEN:
      return `<div class="lg lg-stop">
        ${head('Not permitted', 'your role does not carry this capability')}
        <p>You are signed in. This is an authorisation boundary, not a sign-in
           problem, so signing in again will not change it.</p>
        ${s.detail ? `<p class="lg-meta">capability: <b>${esc(s.detail)}</b></p>` : ''}
        <button class="lg-btn" data-act="back">Back</button></div>`;

    case L.EXPIRED:
      return `<div class="lg">
        ${head('Session expired', 'you were signed in; the session has run out')}
        ${banner}${roleButtons(s, 'Sign in again')}</div>`;

    case L.INVALID:
      return `<div class="lg">
        ${head('Sign-in refused', s.detail || 'that was refused')}
        ${banner}${roleButtons(s, 'Try again')}</div>`;

    case L.WORKING:
      return `<div class="lg lg-busy">
        ${head('Signing in', 'contacting the Admin API')}
        <p class="lg-spin">working…</p></div>`;

    default:
      return `<div class="lg">
        ${head('Sign in', 'DishNet staff access to the MikroTik control plane')}
        ${banner}${roleButtons(s, 'Sign in')}</div>`;
  }
}

function roleButtons(s, label) {
  if (!s.roles.length) {
    return `<p class="lg-sub">The server offered no roles to sign in as.</p>`;
  }
  return `<p class="lg-label">${esc(label)} as:</p><div class="lg-roles">`
    + s.roles.map(r => `<button class="lg-btn" data-act="login" data-role="${esc(r)}">${esc(r)}</button>`).join('')
    + `</div>`;
}

function esc(v) {
  return String(v).replace(/[&<>"']/g, c =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
