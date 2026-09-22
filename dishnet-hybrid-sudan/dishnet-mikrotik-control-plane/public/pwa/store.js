/* The data the screens read, loaded from the real API.
 *
 * This replaces the prototype's mock constants. It deliberately keeps the
 * prototype's shape — {cust, services, sites, routers, vouchers, sessions,
 * mik} — so no view function changes: the audited UX stays exactly as audited,
 * and only what feeds it is different.
 *
 * derive() remains THE SINGLE GATE. In the prototype it walked
 * token -> customer -> services -> sites -> routers, standing in for the
 * server. Now the server does that walk and the client never sees a customer
 * id at all, so the gate here is simply "render only what the server returned
 * for this token".
 */
import { Api, State, MISSING_CONTRACTS } from './api.js';

export class Store {
  constructor(api) {
    this.api = api || new Api();
    this.reset();
  }

  reset() {
    this.cust = null;
    this.services = []; this.sites = []; this.vouchers = [];
    this.sessions = []; this.plans = []; this.intents = [];
    this.usage = null;
    /* Per-resource state, so a screen can tell these three apart:
         empty       — you have none
         unavailable — the platform cannot answer this yet
         failed      — something went wrong just now
       Collapsing them is how "no access points" gets shown to a customer who
       has four. */
    this.state = {
      me: State.LOADING, services: State.LOADING, sites: State.LOADING,
      plans: State.LOADING, vouchers: State.LOADING, sessions: State.LOADING,
      usage: State.LOADING, intents: State.LOADING,
      // No route emits these. Not LOADING — they are never coming.
      accessPoints: State.UNAVAILABLE,
      billing:      State.UNAVAILABLE,
      support:      State.UNAVAILABLE,
    };
    this.missing = MISSING_CONTRACTS;
  }

  /** Load everything the shell needs. One pass, in parallel. */
  async load() {
    const [me, services, sites, plans, vouchers, sessions, usage, intents] = await Promise.all([
      this.api.me(), this.api.services(), this.api.sites(), this.api.plans(),
      this.api.vouchers(), this.api.sessions(), this.api.usage(), this.api.intents(),
    ]);

    this.state.me = me.state;
    // A 401 anywhere means the session is gone. Say so rather than rendering
    // a half-empty app that looks like an account with nothing in it.
    if (me.status === 401) { this.reset(); this.state.me = State.FAILED; return false; }
    this.cust = me.data ?? null;

    const put = (key, res) => {
      this.state[key] = res.state;
      return res.items ?? [];   // api.js already unwrapped the server's named key
    };
    this.services = put('services', services);
    this.sites    = put('sites', sites);
    this.plans    = put('plans', plans);
    this.vouchers = put('vouchers', vouchers);
    this.sessions = put('sessions', sessions);
    this.intents  = put('intents', intents);
    this.state.usage = usage.state;
    this.usage = usage.data ?? null;
    return true;
  }

  /** The prototype's derive(), fed by the server instead of by mocks. */
  derive() {
    if (!this.cust) return null;
    return {
      cust: this.cust,
      services: this.services,
      sites: this.sites,
      /* Empty, and NOT because the customer has none: no endpoint returns
         access points. The Wi-Fi screens must read state.accessPoints and
         render `unavailable`. */
      routers: [],
      vouchers: this.vouchers,
      sessions: this.sessions,
      plans: this.plans,
      mik: this.services.find(s => (s.kind || '') === 'mikrotik_hotspot'
                                || (s.kind || '') === 'mikrotik') || null,
    };
  }

  /** Queue a voucher batch. Returns the classified result — 202 stays queued. */
  async requestVouchers(planId, count, siteId) {
    const r = await this.api.createVouchers(planId, count, siteId);
    if (r.state === State.OK || r.state === State.QUEUED) {
      const v = await this.api.vouchers();
      this.state.vouchers = v.state;
      this.vouchers = v.items ?? [];
    }
    return r;
  }
}
