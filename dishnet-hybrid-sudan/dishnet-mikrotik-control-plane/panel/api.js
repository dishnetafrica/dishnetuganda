/* DishNet Admin Web — the API client.
 *
 * READ ONLY. This increment exposes no state-changing call: there is no
 * assign, provision, reboot, reset, revoke, disconnect, create or edit method
 * anywhere in this file, and a test asserts none appears. Admin writes are a
 * separate authorization gate.
 *
 * Every read goes to one of the eleven approved projections (migration 019).
 * The client cannot ask for anything else, because the server will not answer
 * anything else: dnb_adminapi holds EXECUTE on those functions and no
 * privilege on any table.
 */
export const S = Object.freeze({
  LOADING:      'loading',
  OK:           'ok',
  EMPTY:        'empty',        // the estate genuinely has none
  UNAVAILABLE:  'unavailable',  // the backend cannot answer this yet
  UNAUTHORIZED: 'unauthorized', // 401 — no staff identity
  FORBIDDEN:    'forbidden',    // 403 — identity without the capability
  OFFLINE:      'offline',
  FAILED:       'failed',
});

export class AdminApi {
  constructor(base = '') { this.base = base; }

  async get(path, key) {
    let res;
    try {
      res = await fetch(this.base + path, { headers: { 'Accept': 'application/json' } });
    } catch { return { state: S.OFFLINE, rows: [], status: 0 }; }

    let data = null;
    try { data = await res.json(); } catch {}

    if (res.status === 401) return { state: S.UNAUTHORIZED, rows: [], status: 401, data };
    if (res.status === 403) return { state: S.FORBIDDEN, rows: [], status: 403, data };
    if (res.status === 501) return { state: S.UNAVAILABLE, rows: [], status: 501, data };
    if (!res.ok)            return { state: S.FAILED, rows: [], status: res.status, data };

    const rows = key && data && Array.isArray(data[key]) ? data[key] : [];
    return { state: rows.length ? S.OK : S.EMPTY, rows, status: res.status, data };
  }

  health()        { return this.get('/api/v1/admin/health'); }
  customers()     { return this.get('/api/v1/admin/customers', 'customer'); }
  customer(id)    { return this.get('/api/v1/admin/customers/' + encodeURIComponent(id)); }
  sites()         { return this.get('/api/v1/admin/sites', 'site'); }
  services()      { return this.get('/api/v1/admin/services', 'service'); }
  routers()       { return this.get('/api/v1/admin/routers', 'router'); }
  router(id)      { return this.get('/api/v1/admin/routers/' + encodeURIComponent(id)); }
  plans()         { return this.get('/api/v1/admin/plans', 'plan'); }
  vouchers()      { return this.get('/api/v1/admin/vouchers', 'voucher'); }
  batches()       { return this.get('/api/v1/admin/voucher-batches', 'batch'); }
  voucher(id)     { return this.get('/api/v1/admin/vouchers/' + encodeURIComponent(id)); }
  sessions()      { return this.get('/api/v1/admin/sessions', 'session'); }
  intents()       { return this.get('/api/v1/admin/intents', 'intent'); }
  audit()         { return this.get('/api/v1/admin/audit', 'audit'); }
  /* Every operator's people (migration 027). The projection withholds phone,
   * email and every credential, so none can reach this client. */
  principals()    { return this.get('/api/v1/admin/principals', 'principal'); }

  /* Which router signals the plugin can actually produce, and which it cannot.
   * The inventory is the SERVER'S answer, never this file's: a panel that
   * decided for itself which dots are green could show a green dot for a
   * signal nothing measures. */
  networkSignals() { return this.get('/api/v1/admin/network-signals'); }
}

/* ---------------------------------------------------------------------------
 * Fleet cohorts — the V2 vocabulary, preserved.
 *
 * A router's cohort is derived from last_seen_at ALONE. It says how long ago
 * the platform last had contact, and NOTHING about how contact happens or when
 * the next one is due: whether the estate is push or poll is unresolved (B1),
 * so any wording implying a schedule would be an invention.
 *
 * "never" is a first-class cohort, not a null. A router that has never been
 * seen is the single most operationally important thing on the screen, and
 * folding it into "offline" is how it stops being noticed.
 * ------------------------------------------------------------------------ */
export function cohort(lastSeenAt) {
  if (!lastSeenAt) return 'never';
  const mins = (Date.now() - new Date(lastSeenAt).getTime()) / 60000;
  if (!isFinite(mins) || mins < 0) return 'never';
  if (mins <= 5)   return 'fresh';
  if (mins <= 180) return 'warm';
  return 'cold';
}

export const COHORT_LABEL = Object.freeze({
  fresh: 'Seen in the last 5 minutes',
  warm:  'Seen in the last 3 hours',
  cold:  'Not seen for over 3 hours',
  never: 'Never seen',
});

export function contactAge(lastSeenAt) {
  if (!lastSeenAt) return 'Never';
  const m = (Date.now() - new Date(lastSeenAt).getTime()) / 60000;
  if (!isFinite(m) || m < 0) return 'Never';
  if (m < 1)    return 'just now';
  if (m < 60)   return Math.round(m) + ' min ago';
  if (m < 1440) return (m / 60).toFixed(1) + ' h ago';
  return Math.floor(m / 1440) + ' d ago';
}

/* What the health endpoint's binding facts mean on screen.
 * SIMULATED is never dressed up as anything else. */
export function evidenceLevel(health) {
  if (!health) return { level: 'unknown', label: 'Evidence level unknown' };
  if (health.bindings && health.bindings.publisher_simulated === true) {
    return { level: 'simulated',
             label: 'SIMULATED — no router or AAA system has been contacted' };
  }
  if (health.phase === 'F6-B') {
    return { level: 'production', label: 'PRODUCTION bindings active' };
  }
  return { level: 'documented',
           label: 'DOCUMENTED — behaviour follows published contracts, not verified hardware' };
}
