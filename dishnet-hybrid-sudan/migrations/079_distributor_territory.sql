-- 079_distributor_territory.sql — territory + customer attribution (WS-A P2, docs/49).
--
-- Adds the attribution spine on top of the distributor registry (078):
--   dist_regions         a distributor's named coverage region (docs/47 §9.3)
--   dist_territory_map    area/district -> region -> distributor (the resolver's map)
--   dist_customer_links   the structured owner link: a customer/lead -> distributor
--
-- Rules baked in (docs/49 §6):
--   * NEVER by phone — there is no phone column anywhere here; a link is keyed by
--     the uCRM client id or the lead id, never a phone number.
--   * ONE OWNER AT A TIME — one ACTIVE link per (scope, entity_id); relinking
--     supersedes the old row (active=0, superseded_at set) and keeps it as
--     history, never a silent overwrite.
--   * ONE DISTRIBUTOR PER AREA — area_key is globally unique in the territory
--     map, so an area resolves to at most one distributor (ambiguity fails safe).
--
-- Additive and self-contained: older code ignores these tables; every statement
-- is idempotent; nothing in 001-078 is touched. The whole feature stays behind
-- the distributors_enabled flag and is Uganda-gated in public.php, so Uganda
-- (flag off) and South Sudan are unchanged until it is deliberately enabled.

CREATE TABLE IF NOT EXISTS dist_regions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    partner_id  INTEGER NOT NULL,                 -- dist_partners.id
    code        TEXT    NOT NULL DEFAULT '',
    name        TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
    created_by  TEXT    NOT NULL DEFAULT ''
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_dist_region_partner_code ON dist_regions(partner_id, code);
CREATE INDEX        IF NOT EXISTS idx_dist_region_partner      ON dist_regions(partner_id);

CREATE TABLE IF NOT EXISTS dist_territory_map (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    region_id   INTEGER NOT NULL,                 -- dist_regions.id
    partner_id  INTEGER NOT NULL,                 -- denormalised from the region, for a fast resolve
    area_key    TEXT    NOT NULL,                 -- normalised district/area token (the match key)
    area_label  TEXT    NOT NULL DEFAULT '',      -- as typed, for display
    created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
    created_by  TEXT    NOT NULL DEFAULT ''
);
-- One distributor per area: an area maps to at most one partner (the floor under
-- the "one owner" rule and the reason a territory resolve can never be ambiguous).
CREATE UNIQUE INDEX IF NOT EXISTS idx_dist_territory_area    ON dist_territory_map(area_key);
CREATE INDEX        IF NOT EXISTS idx_dist_territory_partner ON dist_territory_map(partner_id);
CREATE INDEX        IF NOT EXISTS idx_dist_territory_region  ON dist_territory_map(region_id);

CREATE TABLE IF NOT EXISTS dist_customer_links (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    scope         TEXT    NOT NULL,               -- ucrm_client | lead
    entity_id     TEXT    NOT NULL,               -- the uCRM client id or the lead id (string) — NEVER a phone
    partner_id    INTEGER NOT NULL,               -- dist_partners.id
    assigned_via  TEXT    NOT NULL DEFAULT 'manual',  -- territory | manual | application
    source        TEXT    NOT NULL DEFAULT '',
    note          TEXT    NOT NULL DEFAULT '',
    active         INTEGER NOT NULL DEFAULT 1,    -- 1 = the current owner; 0 = superseded history
    assigned_by   TEXT    NOT NULL DEFAULT '',    -- the acting admin (identity boundary, not a request field)
    assigned_at   TEXT    NOT NULL DEFAULT (datetime('now')),
    superseded_at TEXT    NOT NULL DEFAULT ''
);
-- One ACTIVE owner per customer/lead. Relinking supersedes (active=0) and inserts
-- a new active row, so history is kept and there is never more than one owner.
CREATE UNIQUE INDEX IF NOT EXISTS idx_dist_link_active  ON dist_customer_links(scope, entity_id) WHERE active = 1;
CREATE INDEX        IF NOT EXISTS idx_dist_link_partner ON dist_customer_links(partner_id);
CREATE INDEX        IF NOT EXISTS idx_dist_link_entity  ON dist_customer_links(scope, entity_id);
