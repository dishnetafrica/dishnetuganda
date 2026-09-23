/* DishNet Admin — the ROUTER-write client (migration 028, docs/121).
 *
 * The third client, and the only one that changes ESTATE state. api.js stays
 * estate read-only and staff.js identity-only; both are asserted by tests, and
 * so is this file: it reaches exactly four operations, every one under
 * /routers, every one a ROW on the server — a registry row, or an intent row
 * for the worker. Nothing here contacts a router. The push action queues a job
 * that only the worker delivers, through whatever binding the deployment chose
 * (nothing by default; an in-memory simulator under DN_DELIVERY=simulated; a
 * real router only behind the F6-B gate, which is not authorized).
 *
 * The server takes the actor from the session cookie. This file sends no
 * actor, no staged_by, no state it did not ask for, and no operator except the
 * explicit TARGET of an assignment.
 */
export const BASE = '/api/v1/admin';

/** One shape for every answer, so a screen can branch on state, never on luck. */
export async function send(method, path, body) {
  let res;
  try {
    res = await fetch(BASE + path, {
      method,
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(body ?? {}),
    });
  } catch {
    return { state: 'offline', status: 0, data: null };
  }
  let data = null;
  try { data = await res.json(); } catch {}
  const state = res.status === 401 ? 'unauthorized'
              : res.status === 403 ? 'forbidden'
              : res.status === 501 ? 'unavailable'
              : res.ok            ? 'ok'
              :                     'failed';
  return { state, status: res.status, data };
}

/* The lifecycle steps a person may RECORD from each state: migration 012's
 * transition table with `diverged` left out (divergence is COMPUTED from
 * desired and actual, so nobody records it by hand) and `registered` never a
 * destination (it is where a row starts). The server is the authority — a step
 * the trigger refuses comes back 409 with the trigger's own reason — and a
 * test proves by execution that every pair below is one the trigger accepts.
 * Kept as strict JSON on one line so that test can parse it. */
export const NEXT_STATES = {"registered":["staged","decommissioned"],"staged":["shipped","connected","decommissioned"],"shipped":["connected","orphaned","decommissioned"],"connected":["provisioned","orphaned","decommissioned"],"provisioned":["active","orphaned","decommissioned"],"active":["orphaned","decommissioned"],"diverged":["provisioned","active","orphaned","decommissioned"],"orphaned":["connected","decommissioned"],"decommissioned":[]};

/* What each recordable step means, for the person clicking it. A state is what
 * a person OBSERVED and is recording; nothing is measured by recording it. */
export const STEP_MEANING = Object.freeze({
  staged:         'identity and keys recorded at the bench',
  shipped:        'the unit has left DishNet for the site',
  connected:      'you have seen the tunnel come up from this unit',
  provisioned:    'its configuration has been applied and checked',
  active:         'serving guests',
  orphaned:       'removed from its site or returned to stock',
  decommissioned: 'retired for good — no further change is possible',
});

/** A fresh idempotency key, minted once per rendered page (docs/121 D-12). */
export function freshKey() {
  if (globalThis.crypto && typeof crypto.randomUUID === 'function') return 'panel-' + crypto.randomUUID();
  const b = new Uint8Array(16); crypto.getRandomValues(b);
  return 'panel-' + Array.from(b, x => x.toString(16).padStart(2, '0')).join('');
}

/** The four router writes. Paths under /routers only; nothing else exists here. */
export class RouterWriteApi {
  register(fields)         { return send('POST', '/routers', fields); }
  assign(id, fields)       { return send('POST', '/routers/' + encodeURIComponent(id) + '/assign', fields); }
  setState(id, state)      { return send('POST', '/routers/' + encodeURIComponent(id) + '/state', { state }); }
  pushConfig(id, key)      { return send('POST', '/routers/' + encodeURIComponent(id) + '/actions',
                                          { action: 'push_config', idempotency_key: key }); }
}
