/* DishNet Customer PWA — the API client.
 *
 * This is the ONLY place the PWA talks to the server, and it exists to make
 * three rules impossible to break by accident.
 *
 * 1. THE CLIENT HOLDS A TOKEN AND NOTHING ELSE. There is no customer id in
 *    this file, in storage, or in any request this file builds. The server
 *    derives the customer from the session. A site id may appear in a PATH —
 *    but only as a FILTER over what the token already authorizes: a foreign id
 *    matches nothing and returns 404 (docs/55 §D.4). It is never an
 *    authorization input.
 *
 * 2. A RESPONSE IS CLASSIFIED, NEVER ASSUMED. Every call returns one of the
 *    seven states below. In particular 202 is `queued`, not `ok`: rendering a
 *    queued intent as success is the specific lie this project forbids.
 *
 * 3. NO SCREEN MAY INVENT DELIVERY BEHAVIOUR. There is no wording here about
 *    check-ins, polling or when a router will next be reached, because none of
 *    that is hardware verified (B1 is open). The neutral vocabulary is
 *    `queued` and `unavailable`.
 */
export const State = Object.freeze({
  LOADING:     'loading',      // in flight
  OK:          'ok',           // 200 with content
  EMPTY:       'empty',        // 200, but the collection is genuinely empty
  QUEUED:      'queued',       // 202 — accepted, not done. NOT success.
  UNAVAILABLE: 'unavailable',  // 404/501 — this capability does not exist yet
  OFFLINE:     'offline',      // the request never reached the server
  FAILED:      'failed',       // 4xx/5xx the user must be told about
});

export class Api {
  constructor(base = '') {
    this.base = base;
    this.token = null;            // opaque. Carries no ids.
  }

  /* The token is the whole client-side identity. sessionStorage, not
     localStorage: a closed tab should not leave a session behind on a shared
     device, which a hotel front desk very much is. */
  restore() {
    try { this.token = sessionStorage.getItem('dn.token'); } catch { this.token = null; }
    return this.token;
  }
  remember(t) {
    this.token = t;
    try { t ? sessionStorage.setItem('dn.token', t) : sessionStorage.removeItem('dn.token'); } catch {}
  }
  forget() { this.remember(null); }

  /* `listKey` is the field the server puts a collection under. The API does
     NOT use a generic `items` envelope: every list endpoint names its own key
     — {"sites": [...]}, {"plans": [...]}. That was discovered by a test that
     asserted the wrong shape, and the server is authoritative, so the client
     follows it rather than the other way round. */
  async call(method, path, body, listKey) {
    const headers = { 'Accept': 'application/json' };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (this.token) headers['Authorization'] = 'Bearer ' + this.token;

    let res;
    try {
      res = await fetch(this.base + path, {
        method, headers,
        body: body === undefined ? undefined : JSON.stringify(body),
      });
    } catch {
      // The request never left, or never came back. Distinct from a refusal:
      // the user can retry this one and it may simply work.
      return { state: State.OFFLINE, data: null, status: 0 };
    }

    let data = null;
    try { data = await res.json(); } catch { data = null; }

    if (res.status === 401) { this.forget(); return { state: State.FAILED, data, status: 401 }; }
    if (res.status === 202) return { state: State.QUEUED, data, status: 202 };
    // 501 is the honest "not built yet" the admin surface also uses; 404 on a
    // collection route means the same thing to a screen.
    if (res.status === 501 || res.status === 404) {
      return { state: State.UNAVAILABLE, data, status: res.status };
    }
    if (!res.ok) return { state: State.FAILED, data, status: res.status };

    const list = Array.isArray(data) ? data
               : (listKey && data && Array.isArray(data[listKey]) ? data[listKey] : null);
    if (list !== null && list.length === 0) {
      return { state: State.EMPTY, data, status: res.status, items: list };
    }
    return { state: State.OK, data, status: res.status, items: list ?? undefined };
  }

  get(path, listKey) { return this.call('GET', path, undefined, listKey); }
  post(path, body) { return this.call('POST', path, body); }

  /* ---- auth ---------------------------------------------------------- */
  requestCode(phone) { return this.post('/api/v1/auth/request-code', { phone }); }

  async verify(phone, code) {
    const r = await this.post('/api/v1/auth/verify', { phone, code });
    if (r.state === State.OK && r.data && r.data.token) this.remember(r.data.token);
    return r;
  }

  async logout() {
    const r = await this.post('/api/v1/auth/logout', {});
    // Forget locally whatever the server said. A logout that leaves a live
    // token in the tab because the network hiccuped is worse than one that
    // drops a token the server still knows about.
    this.forget();
    return r;
  }

  /* ---- reads. No method here takes a customer id. -------------------- */
  me()           { return this.get('/api/v1/me'); }
  services()     { return this.get('/api/v1/me/services', 'services'); }
  sites()        { return this.get('/api/v1/me/sites', 'sites'); }
  site(id)       { return this.get('/api/v1/me/sites/' + encodeURIComponent(id)); }
  plans()        { return this.get('/api/v1/me/plans', 'plans'); }
  vouchers()     { return this.get('/api/v1/me/vouchers', 'vouchers'); }
  sessions()     { return this.get('/api/v1/me/sessions', 'sessions'); }
  usage()        { return this.get('/api/v1/me/usage'); }
  uplink()       { return this.get('/api/v1/me/uplink'); }
  intents()      { return this.get('/api/v1/me/intents', 'intents'); }
  entitlements() { return this.get('/api/v1/me/entitlements', 'entitlements'); }

  /* ---- writes -------------------------------------------------------- */
  /* Voucher creation is a REQUEST, not a router configuration. The server
     answers 202 with the batch; the screen must render that as queued. The
     caller never waits for a router. */
  createVouchers(planId, count, siteId) {
    const body = { plan_id: planId, count };
    if (siteId) body.site_id = siteId;   // a filter over authorized sites
    return this.post('/api/v1/me/vouchers', body);
  }
  revokeVoucher(id) { return this.post('/api/v1/me/vouchers/' + encodeURIComponent(id) + '/revoke', {}); }
  disconnect(id)    { return this.post('/api/v1/me/sessions/' + encodeURIComponent(id) + '/disconnect', {}); }
}

/* Capabilities the backend does not expose yet. Named here so a screen renders
   `unavailable` deliberately instead of rendering `empty`, which would read as
   "you have no access points" when the truth is "no endpoint returns them".
   See docs/82 for the gap report. */
export const MISSING_CONTRACTS = Object.freeze({
  accessPoints: 'no endpoint returns access points (Projection::accessPoint exists; no route emits it)',
  billing:      'no billing or invoice endpoint exists',
  support:      'no support endpoint exists',
});
