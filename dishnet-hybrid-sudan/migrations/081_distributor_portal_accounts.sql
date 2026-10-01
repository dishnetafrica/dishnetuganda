-- 081_distributor_portal_accounts.sql — distributor portal accounts + sessions
-- (WS-A P4b, docs/50 §C, docs/47 §10.3).
--
-- The portal account (dist_partner_users) and the signed-in session
-- (dist_partner_sessions) the distributor portal will run on. Nothing routes to
-- them yet (P4d adds the dispatcher); they are inert until the portal is built
-- AND distributors_enabled is set AND the tenant is Uganda. Additive and
-- self-contained: older code ignores these tables, every statement is
-- idempotent, and nothing in 001-080 is touched — Uganda (flag off) and South
-- Sudan are unchanged.
--
-- Isolation model (docs/47 §10.3, docs/50 §D): a portal user and a session each
-- carry partner_id, the authoritative scope. The scoped reader derives scope
-- from the SESSION row and the live user row, never from a request. Sign-in
-- resolves a user by its canonical phone, so that phone is globally UNIQUE where
-- present — the Domain-B P-B discipline: a duplicate would let a one-time code
-- bind to an arbitrary account. A duplicate phone is therefore a refusal in
-- PartnerAccounts, never an upsert, with this partial index as the floor.
--
-- The session token is NEVER stored. dist_partner_sessions holds only
-- token_hash = HMAC(token, K); the raw opaque token lives only in the HttpOnly
-- cookie. Logout, disable and a role/outlet change all act on the row, so the
-- token itself never decides (PartnerSession).

CREATE TABLE IF NOT EXISTS dist_partner_users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    partner_id    INTEGER NOT NULL,                        -- dist_partners.id (the one operator this account acts for)
    role          TEXT    NOT NULL DEFAULT 'head_office',  -- head_office | branch_manager | branch_clerk | read_delegate
    status        TEXT    NOT NULL DEFAULT 'active',       -- active | disabled
    display_name  TEXT    NOT NULL DEFAULT '',
    phone         TEXT    NOT NULL DEFAULT '',             -- canonical international sign-in number; the OTP destination
    contact_id    INTEGER,                                 -- dist_contacts.id the number came from (provenance); nullable
    outlet_scope  TEXT    NOT NULL DEFAULT '',             -- CSV of outlet ids for an outlet role; reserved (pilot: empty)
    totp_secret   TEXT    NOT NULL DEFAULT '',             -- base32 TOTP secret; empty until enrolled (P4c)
    created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
    created_by    TEXT    NOT NULL DEFAULT '',
    updated_at    TEXT    NOT NULL DEFAULT (datetime('now')),
    disabled_at   TEXT    NOT NULL DEFAULT '',
    disabled_by   TEXT    NOT NULL DEFAULT ''
);
-- One account per canonical phone (the sign-in key). Partial: many rows may have
-- no phone yet, and empty/duplicate-empty must not collide.
CREATE UNIQUE INDEX IF NOT EXISTS idx_dist_puser_phone   ON dist_partner_users(phone) WHERE phone <> '';
CREATE INDEX        IF NOT EXISTS idx_dist_puser_partner ON dist_partner_users(partner_id, status);

CREATE TABLE IF NOT EXISTS dist_partner_sessions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    token_hash   TEXT    NOT NULL UNIQUE,                  -- HMAC(token, K); the raw token is NEVER stored
    user_id      INTEGER NOT NULL,                         -- dist_partner_users.id
    partner_id   INTEGER NOT NULL,                         -- dist_partners.id — scope, derived from the user at issue
    role         TEXT    NOT NULL DEFAULT '',              -- role at issue (authenticate re-reads the live user besides)
    outlet_scope TEXT    NOT NULL DEFAULT '',
    issued_at    INTEGER NOT NULL,
    expires_at   INTEGER NOT NULL,
    revoked_at   INTEGER,
    revoked_by   TEXT,
    ip           TEXT    NOT NULL DEFAULT '',
    ua           TEXT    NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_dist_psess_user    ON dist_partner_sessions(user_id, revoked_at);
CREATE INDEX IF NOT EXISTS idx_dist_psess_expires ON dist_partner_sessions(expires_at);
