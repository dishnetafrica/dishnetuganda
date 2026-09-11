-- ═══════════════════════════════════════════════════════════════════
-- 065: Financial audit trail — who changed a money record, and to what.
--
-- cb_ledger rows could be edited and hard-deleted leaving nothing at all
-- behind: no actor, no before value, no record that the row had ever
-- existed. voidEntry already did it properly — it stamps the reason, the
-- actor and the time into the row and keeps it. This gives edit and delete
-- the same standard, and gives every other money record somewhere to put
-- the same evidence.
--
-- Append-only by contract: nothing in the codebase updates or deletes from
-- this table, and the test suite asserts that. A row here outlives the row
-- it describes, which is the entire point — a deleted ledger entry can be
-- read back out of before_json.
-- ═══════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS fin_audit (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    record_type   TEXT    NOT NULL,          -- cb_ledger | stock_purchase | ...
    record_id     INTEGER NOT NULL,
    action        TEXT    NOT NULL,          -- create | update | void | delete
    actor_id      INTEGER,
    actor_name    TEXT    NOT NULL DEFAULT '',
    reason        TEXT    NOT NULL DEFAULT '',
    before_json   TEXT,                      -- the whole row as it was
    after_json    TEXT,                      -- as it became — NULL for a delete
    changed_keys  TEXT    NOT NULL DEFAULT '', -- comma list, for reading at a glance
    created_at    TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_fa_record ON fin_audit(record_type, record_id, id);
CREATE INDEX IF NOT EXISTS idx_fa_date   ON fin_audit(created_at);
CREATE INDEX IF NOT EXISTS idx_fa_actor  ON fin_audit(actor_id, created_at);
