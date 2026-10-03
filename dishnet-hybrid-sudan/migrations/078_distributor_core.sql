-- 078_distributor_core.sql — the distributor registry (WS-A Phase 1a, docs/49).
--
-- The FIRST appointed-distributor entity. docs/47 §9.2 designs it; this is that
-- table, trimmed to what Phase 1a needs, with the uCRM columns present (nullable)
-- so the later uCRM-link step (P1b) needs no ALTER.
--
-- Scope boundary (docs/49 §15.1, and the whole approval): a row here is a LOCAL
-- prospect record only. Creating it does NOT create a uCRM company client, does
-- NOT grant any account, login, wallet or portal access, and writes NOTHING to
-- uCRM — those are separate, later, explicitly-approved steps. Appointment is a
-- deliberate DishNet-admin action, recorded in dist_appointment for provenance.
--
-- Additive and self-contained: older code ignores these tables; every statement
-- is idempotent. Nothing in migrations 001-077 is touched. Uganda and South
-- Sudan behaviour is unchanged until the distributors_enabled flag is set, and
-- the whole feature is Uganda-gated in public.php besides.
--
-- Dedupe rule (docs/47 §9.1, carried here): a partner is deduped by its
-- normalised TIN (unique where present) and its uCRM client id (unique where
-- linked) — NEVER by phone. A partner's contact phone says nothing about the
-- legal entity, and phone matching has disclosed the wrong customer before.
-- There is deliberately no phone column on this table.

CREATE TABLE IF NOT EXISTS dist_partners (
    id                       INTEGER PRIMARY KEY AUTOINCREMENT,
    partner_code             TEXT    NOT NULL UNIQUE,            -- 'DP-00001'; assigned once, never reused
    partner_type             TEXT    NOT NULL DEFAULT '',        -- corporate_retail | authorised_reseller | regional_distributor | wholesale_customer
    category                 TEXT    NOT NULL DEFAULT '',        -- fuel_station | supermarket | electronics | distributor | other
    status                   TEXT    NOT NULL DEFAULT 'prospect',-- prospect | onboarding | active | suspended | terminated
    legal_name               TEXT    NOT NULL DEFAULT '',
    trading_name             TEXT    NOT NULL DEFAULT '',
    tin                      TEXT    NOT NULL DEFAULT '',        -- cached until the uCRM client is linked (then uCRM is master)
    tin_norm                 TEXT    NOT NULL DEFAULT '',        -- normalised TIN; unique where not empty
    registration_no          TEXT    NOT NULL DEFAULT '',
    ucrm_client_id           INTEGER,                            -- NULL until active; unique where set (P1b links it)
    ucrm_linked_by           TEXT    NOT NULL DEFAULT '',
    ucrm_linked_at           TEXT    NOT NULL DEFAULT '',
    account_manager_staff_id INTEGER,
    trading_currency         TEXT    NOT NULL DEFAULT 'UGX',
    created_at               TEXT    NOT NULL DEFAULT (datetime('now')),
    created_by               TEXT    NOT NULL DEFAULT '',
    updated_at               TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- partner_code is already UNIQUE above. TIN and uCRM-id uniqueness are PARTIAL:
-- many prospects legitimately have no TIN and no uCRM link yet, and empty/NULL
-- must not collide.
CREATE UNIQUE INDEX IF NOT EXISTS idx_dist_partner_tinnorm
    ON dist_partners(tin_norm) WHERE tin_norm <> '';
CREATE UNIQUE INDEX IF NOT EXISTS idx_dist_partner_ucrm
    ON dist_partners(ucrm_client_id) WHERE ucrm_client_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_dist_partner_status ON dist_partners(status);

-- The application -> partner provenance bridge. One appointment per application
-- (a DNP-… row is appointed at most once). Records who appointed and when.
CREATE TABLE IF NOT EXISTS dist_appointment (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    application_id  INTEGER NOT NULL,                 -- dist_partner_applications.id (the DNP- ref)
    partner_id      INTEGER NOT NULL,                 -- dist_partners.id
    appointed_by    TEXT    NOT NULL DEFAULT '',      -- the acting admin (identity from the boundary, not a request field)
    appointed_at    TEXT    NOT NULL DEFAULT (datetime('now')),
    note            TEXT    NOT NULL DEFAULT ''
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_dist_appointment_app     ON dist_appointment(application_id);
CREATE INDEX        IF NOT EXISTS idx_dist_appointment_partner ON dist_appointment(partner_id);
