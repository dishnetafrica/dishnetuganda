-- 073_customer_sessions.sql — Phase 2 of the customer-login audit (plan §E.5).
--
-- One row per customer-portal token issued. A token is accepted only while its
-- row exists, is not revoked and has not expired, so logout, "sign out
-- everywhere" and any later key concern all act here — the token itself no
-- longer decides. Additive: older code ignores the table.
CREATE TABLE IF NOT EXISTS customer_sessions (
    jti         TEXT PRIMARY KEY,
    client_id   INTEGER NOT NULL,
    identifier  TEXT NOT NULL DEFAULT '',
    login_mode  TEXT NOT NULL DEFAULT '',
    kid         TEXT NOT NULL DEFAULT '',
    issued_at   INTEGER NOT NULL,
    expires_at  INTEGER NOT NULL,
    revoked_at  INTEGER,
    revoked_by  TEXT,
    ip          TEXT NOT NULL DEFAULT '',
    ua          TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_customer_sessions_client ON customer_sessions(client_id, revoked_at);
CREATE INDEX IF NOT EXISTS idx_customer_sessions_expires ON customer_sessions(expires_at);
