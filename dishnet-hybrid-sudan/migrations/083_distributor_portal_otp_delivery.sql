-- 083_distributor_portal_otp_delivery.sql — the distributor-portal sign-in
-- AUTHENTICATION-PLANE log (WS-A P4, docs/53): the non-secret record of an OTP
-- send's outcome and of an admin TOTP reset.
--
-- Why a log at all: docs/53 §3 requires that Evolution's ACCEPTANCE of a send is
-- never recorded as confirmed DELIVERY, and that the admin TOTP reset is AUDITED.
-- Both need a persisted, non-secret trail. This table is it. It is separate from
-- dist_notify_log (customer/business notifications, migration 080): authentication
-- traffic is kept apart from marketing/sales/customer notifications, by its own
-- store as well as its own send path and message class (docs/53 §1).
--
-- What is NOT stored here, ever: the login code, the TOTP secret, the Evolution
-- apikey, or any session token (docs/53 §3). The columns hold ids and an outcome
-- only; `detail` is a short non-secret note (an error class, a revoked-session
-- count), never a credential. The destination number is referenced by contact_id
-- (provenance into dist_contacts, migration 080), not copied in clear.
--
-- Accepted != delivered is baked into the VALUE SET: the otp_send outcomes are
-- exactly {accepted, failed, unknown, no_recipient}. There is deliberately NO
-- 'delivered' value — the current code cannot confirm WhatsApp delivery (the
-- webhook does not consume MESSAGES_UPDATE), so nothing may record it. A CHECK
-- constraint makes an attempt to write 'delivered' a database error.
--
-- Additive, idempotent, and inert until the portal is built AND
-- distributors_enabled AND the tenant is Uganda. Nothing in 001-082 is touched;
-- Uganda (flag off) and South Sudan are unchanged.

CREATE TABLE IF NOT EXISTS dist_partner_auth_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,                 -- dist_partner_users.id
    partner_id  INTEGER NOT NULL DEFAULT 0,       -- dist_partners.id (provenance; 0 if unresolved)
    contact_id  INTEGER,                          -- dist_contacts.id the code was sent to (provenance; NULL if none)
    event       TEXT    NOT NULL,                 -- 'otp_send' | 'totp_reset'
    outcome     TEXT    NOT NULL,                 -- otp_send: accepted|failed|unknown|no_recipient ; totp_reset: ok
    detail      TEXT    NOT NULL DEFAULT '',      -- a NON-SECRET note only (error class, revoked count). NEVER a code/secret/key/token.
    actor       TEXT    NOT NULL DEFAULT '',      -- the acting admin for a staff action (totp_reset); '' for a system send
    at          INTEGER NOT NULL,
    -- accepted != delivered: there is no 'delivered' outcome, by construction.
    CHECK (event IN ('otp_send', 'totp_reset')),
    CHECK (outcome IN ('accepted', 'failed', 'unknown', 'no_recipient', 'ok'))
);
CREATE INDEX IF NOT EXISTS idx_dist_pauth_user  ON dist_partner_auth_log(user_id, at);
CREATE INDEX IF NOT EXISTS idx_dist_pauth_event ON dist_partner_auth_log(event, at);
