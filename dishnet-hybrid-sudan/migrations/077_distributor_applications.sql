-- 077_distributor_applications.sql — public distributor-recruitment applications (intake only).
--
-- Captures an "expression of interest" submitted from the public website
-- (become-a-distributor.html) through the public `distributor_apply` endpoint.
-- This is PRE-APPROVAL intake ONLY: a row here is NOT a partner, NOT a uCRM
-- client, and grants NO account or portal access. Appointing a partner, and
-- creating any uCRM company client, is a separate DishNet staff action
-- (root docs/47, docs/48). Additive and self-contained: older code ignores
-- the table; every statement is idempotent.

CREATE TABLE IF NOT EXISTS dist_partner_applications (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
    status          TEXT    NOT NULL DEFAULT 'received',   -- received | reviewing | contacted | closed (staff-set, later)
    source          TEXT    NOT NULL DEFAULT '',
    partner_model   TEXT    NOT NULL DEFAULT '',           -- corporate | regional | retail | referral
    model_label     TEXT    NOT NULL DEFAULT '',
    business_name   TEXT    NOT NULL DEFAULT '',
    trading_name    TEXT    NOT NULL DEFAULT '',
    business_type   TEXT    NOT NULL DEFAULT '',
    contact_name    TEXT    NOT NULL DEFAULT '',
    contact_role    TEXT    NOT NULL DEFAULT '',
    email           TEXT    NOT NULL DEFAULT '',
    phone           TEXT    NOT NULL DEFAULT '',
    city            TEXT    NOT NULL DEFAULT '',
    website         TEXT    NOT NULL DEFAULT '',
    description     TEXT    NOT NULL DEFAULT '',
    operating       TEXT    NOT NULL DEFAULT '',
    services        TEXT    NOT NULL DEFAULT '',            -- JSON array of service labels
    activities      TEXT    NOT NULL DEFAULT '',            -- JSON array of activity labels
    coverage        TEXT    NOT NULL DEFAULT '',            -- JSON object
    readiness       TEXT    NOT NULL DEFAULT '',            -- JSON object
    training        TEXT    NOT NULL DEFAULT '',            -- JSON array of proposed module groups
    consent         INTEGER NOT NULL DEFAULT 0,
    ip              TEXT    NOT NULL DEFAULT '',
    user_agent      TEXT    NOT NULL DEFAULT '',
    raw_json        TEXT    NOT NULL DEFAULT ''
);

CREATE INDEX IF NOT EXISTS idx_dpa_created ON dist_partner_applications(created_at);
CREATE INDEX IF NOT EXISTS idx_dpa_status  ON dist_partner_applications(status);
