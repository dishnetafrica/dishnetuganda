-- 068_equipment_assignments.sql
--
-- ONE authoritative answer to "who owns this kit".
--
-- Until now the question had three answers depending on which code path asked.
-- stock_units.crm_client_id was the strong one. sl_kits.json was a file from a
-- plugin that is not installed. And the blocking path — the one that decides
-- whether a paying customer keeps their internet — fell through to a regular
-- expression over a service name somebody typed into uCRM.
--
-- A service rename could stop a customer being blockable. A mistyped serial in
-- a service name could block a different customer's dish. Neither would leave
-- a trace, because a guess that finds nothing and a guess that finds the wrong
-- thing look identical from the outside.
--
-- This table replaces all three. Ownership is an integer, uCRM's client id, on
-- a row that also carries the exact Starlink identifiers needed to act on the
-- hardware. Both directions resolve by exact match or not at all.
--
-- ── WHY THE CONSTRAINTS ARE IN THE DATABASE ────────────────────────────────
--
-- Application code can be bypassed by the next tool somebody writes at 2am.
-- These are enforced by SQLite itself:
--
--   · a unit has at most ONE live assignment       (idx_ea_live_unit)
--   · a uCRM service has at most ONE live kit      (idx_ea_live_service)
--   · a Starlink identifier resolves to at most    (idx_ea_live_kit / _terminal
--     one live assignment                           / _router / _sl)
--   · a released assignment can never be edited    (trigger ea_released_is_history)
--   · an assignment can never be deleted           (trigger ea_never_deleted)
--
-- The live indexes are PARTIAL — they apply only while released_at IS NULL, so
-- a kit can be assigned, released and reassigned any number of times, and the
-- whole chain stays queryable.

CREATE TABLE IF NOT EXISTS equipment_assignments (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,

    -- Our side
    unit_id              INTEGER NOT NULL,          -- stock_units.id
    crm_client_id        INTEGER NOT NULL,          -- uCRM client id — THE identity
    crm_service_id       INTEGER,                   -- uCRM service id, when known

    -- Starlink's side, exactly as their API spells it. Never invented: an
    -- identifier we do not have is empty, and an empty one never matches.
    starlink_account     TEXT NOT NULL DEFAULT '',  -- ACC-DF-…
    starlink_service_line TEXT NOT NULL DEFAULT '', -- SL-…
    terminal_id          TEXT NOT NULL DEFAULT '',  -- userTerminalId
    router_id            TEXT NOT NULL DEFAULT '',  -- routerId (no Router- prefix)
    kit_serial           TEXT NOT NULL DEFAULT '',  -- KIT… — copied from the unit

    -- Life
    assigned_at          TEXT NOT NULL,
    assigned_by          INTEGER,
    assigned_by_name     TEXT NOT NULL DEFAULT '',
    released_at          TEXT,
    released_reason      TEXT NOT NULL DEFAULT '',
    released_by_name     TEXT NOT NULL DEFAULT '',
    replaced_by_unit_id  INTEGER,                   -- the kit that took over
    note                 TEXT NOT NULL DEFAULT '',
    created_at           TEXT NOT NULL
);

-- One kit, one live owner. This is the constraint that makes "Kit A can never
-- appear under Customer B" a property of the database rather than a habit.
CREATE UNIQUE INDEX IF NOT EXISTS idx_ea_live_unit
    ON equipment_assignments(unit_id) WHERE released_at IS NULL;

-- One uCRM service, one live kit. Without this, a customer with two services
-- and two kits has no way to say which kit a suspension should touch.
CREATE UNIQUE INDEX IF NOT EXISTS idx_ea_live_service
    ON equipment_assignments(crm_service_id)
    WHERE released_at IS NULL AND crm_service_id IS NOT NULL;

-- A Starlink identifier resolves to at most one live assignment. These are the
-- reverse-direction keys: the data plugin hands us one of them and must get
-- back exactly one customer, or none.
CREATE UNIQUE INDEX IF NOT EXISTS idx_ea_live_kit
    ON equipment_assignments(kit_serial) WHERE released_at IS NULL AND kit_serial != '';
CREATE UNIQUE INDEX IF NOT EXISTS idx_ea_live_terminal
    ON equipment_assignments(terminal_id) WHERE released_at IS NULL AND terminal_id != '';
CREATE UNIQUE INDEX IF NOT EXISTS idx_ea_live_router
    ON equipment_assignments(router_id) WHERE released_at IS NULL AND router_id != '';
CREATE UNIQUE INDEX IF NOT EXISTS idx_ea_live_sl
    ON equipment_assignments(starlink_service_line)
    WHERE released_at IS NULL AND starlink_service_line != '';

-- Reading indexes.
CREATE INDEX IF NOT EXISTS idx_ea_client  ON equipment_assignments(crm_client_id);
CREATE INDEX IF NOT EXISTS idx_ea_service ON equipment_assignments(crm_service_id);
CREATE INDEX IF NOT EXISTS idx_ea_unit    ON equipment_assignments(unit_id);

-- A released assignment is history. Editing one rewrites what was true at the
-- time, which is the whole thing an assignment trail exists to prevent.
CREATE TRIGGER IF NOT EXISTS ea_released_is_history
BEFORE UPDATE ON equipment_assignments
FOR EACH ROW WHEN OLD.released_at IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'equipment_assignments: a released assignment is history; create a new assignment instead');
END;

-- And no assignment is ever deleted. Release it.
CREATE TRIGGER IF NOT EXISTS ea_never_deleted
BEFORE DELETE ON equipment_assignments
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'equipment_assignments: assignments are never deleted; release them instead');
END;
