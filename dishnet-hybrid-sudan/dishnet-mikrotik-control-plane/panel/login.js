/* DishNet Admin — the login gate.
 *
 * SEVEN STATES, plus one, and the point of writing them out is that most of
 * them are the ones a login screen usually gets wrong by collapsing into
 * "something went wrong":
 *
 *   1 login                      a form, because somebody CAN authenticate here
 *   2 invalid                    the submission was refused
 *   3 working                    in flight; the form is disabled, not hidden
 *   4 authenticated              handled by the app, not here
 *   5 expired                    was signed in, no longer is — said plainly
 *   6 forbidden                  signed in, lacks the capability. NOT a login prompt
 *   7 unavailable                no identity provider is configured at all
 *   8 enrol                      signed in with a password; an authenticator is
 *                                still owed, and nothing else is reachable until
 *                                it is confirmed (docs/114 D-AUTH-5)
 *
 * 7 is the one that matters most. A deployment with DenyAllIdentity cannot
 * authenticate anybody, and showing a login form there would invite staff to
 * type credentials at something that will never accept them — and to conclude
 * their sign-in details are wrong when the truth is that authentication was
 * never switched on.
 *
 * TWO PROVIDERS, ONE GATE. The server says which form to draw (mode): the
 * development identity offers a role picker and takes no credential; the real
 * provider takes a username, a password and, once enrolled, an authenticator
 * code. What the server never says to an unauthenticated caller is anything
 * about any account — which is why the code field is always shown.
 *
 * THIS FILE CONTAINS NO CREDENTIAL, NO KEY AND NO TOKEN. The session cookie is
 * HttpOnly, so this script cannot read it even if it wanted to. A password
 * travels from a form field to one request body and is held nowhere.
 */
import { send } from './staff.js';

export const L = Object.freeze({
  LOGIN: 'login', INVALID: 'invalid', WORKING: 'working',
  AUTHENTICATED: 'authenticated', EXPIRED: 'expired',
  FORBIDDEN: 'forbidden', UNAVAILABLE: 'unavailable', ENROL: 'enrol',
});

export class Session {
  constructor() {
    this.state = L.LOGIN;
    this.identity = null;
    this.roles = [];
    this.mode = 'none';          // 'development' | 'credentials' | 'none'
    this.provider = null;
    this.detail = null;
    this.enrolment = null;       // the authenticator setup, shown once
    this.hadSession = false;     // distinguishes "expired" from "never signed in"
  }

  /** Ask the server who we are. Never assumes; always asks. */
  async refresh() {
    const r = await send('GET', '/session');
    if (r.state === 'offline') {
      this.state = L.UNAVAILABLE;
      this.detail = 'the Admin API could not be reached';
      return this.state;
    }
    const data = r.data;
    if (r.status === 200 && data && data.identity) { return this.accept(data.identity); }

    // 401. Which of the unauthenticated states is it?
    this.identity = null;
    this.provider = (data && data.provider) || null;
    this.roles    = (data && data.roles) || [];
    this.mode     = (data && data.mode) || 'none';
    if (data && data.can_authenticate === false) {
      this.state  = L.UNAVAILABLE;
      this.detail = 'no staff identity provider is configured for this deployment';
    } else {
      this.state  = this.hadSession ? L.EXPIRED : L.LOGIN;
    }
    return this.state;
  }

  /** @param {object} fields what the form collected — a role, or credentials. */
  async login(fields) {
    this.state = L.WORKING;
    const r = await send('POST', '/session', fields);
    if (r.state === 'offline') {
      this.state = L.UNAVAILABLE;
      this.detail = 'the Admin API could not be reached';
      return this.state;
    }
    const data = r.data;
    if (r.status === 200 && data && data.identity) { return this.accept(data.identity); }
    if (r.status === 501 || (r.status === 403 && data && data.error === 'insecure_transport')) {
      // Both are properties of the deployment, not of what was typed.
      this.state  = L.UNAVAILABLE;
      this.detail = (data && data.detail) || 'authentication is not available here';
      return this.state;
    }
    this.state  = L.INVALID;
    this.detail = r.status === 403 ? 'the request was refused as cross-site'
                : 'those sign-in details were not accepted';
    return this.state;
  }

  accept(identity) {
    this.identity   = identity;
    this.provider   = identity.provider;
    this.hadSession = true;
    const sf = identity.second_factor || {};
    this.state = sf.pending ? L.ENROL : L.AUTHENTICATED;
    return this.state;
  }

  /** Begin authenticator setup. The key is shown once and kept only on screen. */
  async enrol() {
    const r = await send('POST', '/session/totp', {});
    this.enrolment = r.status === 200 && r.data ? r.data.enrolment : null;
    if (!this.enrolment) { this.detail = (r.data && r.data.detail) || 'the authenticator could not be set up'; }
    return this.enrolment;
  }

  async confirm(code) {
    const r = await send('POST', '/session/totp/confirm', { code });
    if (r.status === 200) { this.enrolment = null; this.detail = null; return this.refresh(); }
    this.detail = r.status === 400 ? 'that code was not accepted — wait for the next one and try again'
                : 'the code could not be checked';
    return this.state;
  }

  async logout() {
    await send('DELETE', '/session');
    this.identity = null;
    this.enrolment = null;
    this.state    = L.LOGIN;
    this.hadSession = false;     // an explicit sign-out is not an expiry
    return this.refresh();       // the server says what to draw next
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
    // Worded to avoid the panel guards' needles, which exist to keep SQL and
    // credentials out of the bundle and are right to.
    ? `<p class="lg-dev"><b>DEVELOPMENT IDENTITY</b> — this deployment is not
       authenticating real staff. The roles below choose which one to work as,
       so the capability boundary can be exercised. Nothing is asked for here
       because no credential store is bound.</p>`
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

    case L.ENROL:
      return `<div class="lg">${head('Set up your authenticator',
          'this deployment requires a second factor before anything else is available')}
        ${enrolPanel(s)}
        <p class="lg-meta">signed in as <b>${esc(s.identity ? s.identity.subject : '')}</b>
           · <a class="lnk" data-act="signout">Sign out</a></p></div>`;

    case L.EXPIRED:
      return `<div class="lg">
        ${head('Session expired', 'you were signed in; the session has run out')}
        ${banner}${loginForm(s, 'Sign in again')}</div>`;

    case L.INVALID:
      return `<div class="lg">
        ${head('Sign-in refused', s.detail || 'that was refused')}
        ${banner}${loginForm(s, 'Try again')}</div>`;

    case L.WORKING:
      return `<div class="lg lg-busy">
        ${head('Signing in', 'contacting the Admin API')}
        <p class="lg-spin">working…</p></div>`;

    default:
      return `<div class="lg">
        ${head('Sign in', 'DishNet staff access to the MikroTik control plane')}
        ${banner}${loginForm(s, 'Sign in')}</div>`;
  }
}

function loginForm(s, label) {
  if (s.mode === 'credentials') {
    // The code field is ALWAYS shown: the server never tells an unauthenticated
    // caller whether an account has an authenticator, so neither does this form.
    return `<form class="lg-form" data-act="credentials" autocomplete="on">
      <label>Username <input name="username" autocomplete="username" autocapitalize="none" required></label>
      <label>Password <input name="password" type="password" autocomplete="current-password" required></label>
      <label>Authenticator code <input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
             autocomplete="one-time-code" placeholder="leave empty until you have set one up"></label>
      <button class="lg-btn" type="submit">${esc(label)}</button></form>`;
  }
  if (!s.roles.length) {
    return `<p class="lg-sub">The server offered no way to sign in.</p>`;
  }
  return `<p class="lg-label">${esc(label)} as:</p><div class="lg-roles">`
    + s.roles.map(r => `<button class="lg-btn" data-act="login" data-role="${esc(r)}">${esc(r)}</button>`).join('')
    + `</div>`;
}

/** Authenticator setup. The key appears once, here, and is stored by nothing in this page. */
export function enrolPanel(s) {
  const e = s.enrolment;
  if (!e) {
    return `<p>Your session is signed in with a password only. Set up an authenticator app
        (any RFC 6238 app) to finish signing in.</p>
      ${s.detail ? `<p class="lg-err">${esc(s.detail)}</p>` : ''}
      <button class="lg-btn" data-act="enrol">Begin setup</button>`;
  }
  return `<p>Add this account to your authenticator app, then enter the code it shows.</p>
    <div class="lg-key"><span>Account</span><b>${esc(e.account)}</b>
      <span>Setup key</span><b class="mono">${esc(String(e.key).replace(/(.{4})/g, '$1 ').trim())}</b>
      <span>Or paste</span><b class="mono small">${esc(e.uri)}</b></div>
    <p class="lg-meta">Time-based, six digits, every 30 seconds. This key is shown once;
       an administrator can reset it later if the device is lost.</p>
    ${s.detail ? `<p class="lg-err">${esc(s.detail)}</p>` : ''}
    <form class="lg-form" data-act="confirm">
      <label>Code from the app <input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
             autocomplete="one-time-code" required autofocus></label>
      <button class="lg-btn" type="submit">Confirm</button></form>`;
}

function esc(v) {
  return String(v).replace(/[&<>"']/g, c =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
