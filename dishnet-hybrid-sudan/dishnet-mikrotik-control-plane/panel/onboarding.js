/* DishNet Admin — the ONBOARDING-write client (migration 030, docs/125).
 *
 * The fourth client, and the second that changes ESTATE state. api.js stays
 * estate read-only, staff.js identity-only and routers.js router-only; each is
 * asserted by tests, and so is this file: it reaches exactly four operations,
 * every one a ROW on the server written by one audited function — create an
 * operator, start its HotSpot service, add a location, and (docs/126, J-1) add
 * an owner who can sign in for the operator.
 *
 * The server takes the actor from the session cookie. This file sends no actor
 * and no status. A location is sent with its SERVICE and nothing else that
 * names an operator: the server reads the operator from the service row
 * (derive, never accept). Every call carries an idempotency key minted once
 * per rendered form, so a double click is one act. Nothing here contacts a
 * router.
 */
import { send } from './routers.js';

/** The three onboarding writes. Nothing else exists here. */
export class OnboardingWriteApi {
  createOperator(name, key) {
    return send('POST', '/customers', { name, idempotency_key: key });
  }
  startService(operatorId, key) {
    return send('POST', '/customers/' + encodeURIComponent(operatorId) + '/services', { idempotency_key: key });
  }
  addLocation(serviceId, name, location, key) {
    const body = { service_id: serviceId, name, idempotency_key: key };
    if (location) body.location = location;
    return send('POST', '/sites', body);
  }
  /* The operator is the PATH — the one explicit target the Admin plane allows.
   * No key: the phone's global uniqueness is the replay guard (docs/108), so a
   * second submission is refused, never duplicated. The number is sent once
   * and never comes back: the server's answer withholds it. */
  addOwner(operatorId, name, phone) {
    return send('POST', '/customers/' + encodeURIComponent(operatorId) + '/principals',
      { kind: 'owner', display_name: name, phone });
  }
}
