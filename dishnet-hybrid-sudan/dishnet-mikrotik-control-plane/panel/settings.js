/* DishNet Admin — the SMS-SETTINGS client (migration 033, docs/128).
 *
 * The fifth client. api.js stays estate read-only, staff.js identity-only,
 * routers.js router-only and onboarding.js onboarding-only; each is asserted by
 * tests, and so is this file: it reaches exactly one path, /settings/sms, with
 * one read and one write. Admin only (sms.manage): the server answers 403 to
 * anyone else and 501 where the real staff login is not bound.
 *
 * The API key passes through here exactly once, from a password field to a
 * request body. It is never stored, logged or echoed, and the server never
 * sends it back: the read says whether a key is set and nothing of it. The
 * server takes the actor from the session cookie; this file sends none.
 */
import { send } from './staff.js';

export class SmsSettingsApi {
  /** The settings without the key, what the worker reports, and the outbox's counts. */
  read() { return send('GET', '/settings/sms'); }
  /** {provider, username, api_key, sender}. An empty api_key keeps the stored one. */
  save(fields) { return send('POST', '/settings/sms', fields); }
}
