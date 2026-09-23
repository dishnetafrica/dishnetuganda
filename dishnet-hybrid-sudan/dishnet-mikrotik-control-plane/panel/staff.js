/* DishNet Admin — the IDENTITY-plane client: sessions and the DishNet staff
 * roster (migration 026, docs/114 G-B).
 *
 * This file is deliberately separate from api.js, which stays ESTATE READ-ONLY
 * and is tested to contain no mutating call. Everything here mutates identity
 * state and nothing else: a test asserts that every path this file reaches
 * begins with /session or /staff, so it can never grow an estate write.
 *
 * It sends no role and no operator to establish who the caller is — the server
 * decides that from the HttpOnly cookie this script cannot read. Passwords
 * pass through here exactly once, from a form field to a request body, and
 * are never stored, logged or echoed.
 */
export const BASE = '/api/v1/admin';

/** One shape for every answer, so a screen can branch on state, never on luck. */
export async function send(method, path, body) {
  let res;
  try {
    res = await fetch(BASE + path, {
      method,
      headers: body === undefined
        ? { Accept: 'application/json' }
        : { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
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

/** The DishNet staff roster. Admin only; the server answers 403 to anyone else. */
export class StaffApi {
  async list() {
    const r = await send('GET', '/staff');
    const rows = r.data && Array.isArray(r.data.staff) ? r.data.staff : [];
    return { ...r, rows, state: r.state === 'ok' && !rows.length ? 'empty' : r.state };
  }
  create(fields)        { return send('POST', '/staff', fields); }
  disable(id)           { return send('POST', '/staff/' + encodeURIComponent(id) + '/disable', {}); }
  enable(id)            { return send('POST', '/staff/' + encodeURIComponent(id) + '/enable', {}); }
  setRole(id, role)     { return send('POST', '/staff/' + encodeURIComponent(id) + '/role', { role }); }
  newPassword(id)       { return send('POST', '/staff/' + encodeURIComponent(id) + '/password', {}); }
  clearTotp(id)         { return send('POST', '/staff/' + encodeURIComponent(id) + '/totp-reset', {}); }
}

/** The signed-in person's OWN credential. Identified by the cookie, never by an id. */
export class AccountApi {
  changePassword(fields) { return send('POST', '/session/password', fields); }
  enrol()                { return send('POST', '/session/totp', {}); }
  confirm(code)          { return send('POST', '/session/totp/confirm', { code }); }
}
