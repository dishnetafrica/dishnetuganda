-- 082_distributor_portal_auth.sql — distributor portal sign-in state
-- (WS-A P4c, docs/50, docs/47 §10.3): the one-time login code and the TOTP
-- second factor for dist_partner_users (migration 081).
--
-- Additive, idempotent, inert until the portal is built AND distributors_enabled
-- AND the tenant is Uganda. Nothing in 001-081 is dropped. SS and Uganda (flag
-- off) are unchanged.
--
-- The login CODE is stored HASHED only (HMAC under a label-derived key); the raw
-- code lives only in the message to the already-verified number, never in the
-- database. Rate and attempt limits live here, written in the same transaction as
-- the attempt, so a second caller cannot bypass them (the anti-enumeration rule,
-- docs/89 / docs/49 §8). The TOTP secret already lives on dist_partner_users;
-- this adds the "confirmed" flag so an un-confirmed secret can never satisfy a
-- login.

-- One pending login code per account (overwritten on re-request).
CREATE TABLE IF NOT EXISTS dist_partner_otp (
    user_id     INTEGER PRIMARY KEY,            -- dist_partner_users.id
    code_hash   TEXT    NOT NULL,               -- HMAC(code, K); the raw code is NEVER stored
    purpose     TEXT    NOT NULL DEFAULT 'login',
    expires_at  INTEGER NOT NULL,
    attempts    INTEGER NOT NULL DEFAULT 0,     -- wrong tries against THIS code
    created_at  INTEGER NOT NULL
);

-- A generic, hashed rate ledger. rkey is an opaque bucket key such as
-- 'send:user:<id>', 'send:ip:<sha>', or 'fail:user:<id>'. No raw phone or IP is
-- stored. Counts within a window drive the send caps and the decaying fail lock.
CREATE TABLE IF NOT EXISTS dist_partner_rate (
    rkey  TEXT    NOT NULL,
    at    INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_dist_prate ON dist_partner_rate(rkey, at);

-- The TOTP secret is set at enrolment but is usable only once a code has
-- confirmed it. dist_partner_users is inert (no production reader yet), so adding
-- a column is safe and additive.
ALTER TABLE dist_partner_users ADD COLUMN totp_confirmed INTEGER NOT NULL DEFAULT 0;
