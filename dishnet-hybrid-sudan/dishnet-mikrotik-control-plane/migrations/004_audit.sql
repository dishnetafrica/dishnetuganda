-- 004 — audit foundation
-- Append-only. The delete-protection pattern follows the plugin's migration 070
-- (ledger rows), carried into Domain B because the same reasoning applies: a
-- record that can be edited after the fact is not evidence.

CREATE TABLE mt_audit_log (
  id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id  uuid REFERENCES mt_customers(id) ON DELETE RESTRICT,  -- NULL = system event
  actor        text NOT NULL,          -- principal id, staff id, or 'system'
  actor_kind   text NOT NULL CHECK (actor_kind IN ('principal','staff','system')),
  action       text NOT NULL,
  target_type  text,
  target_id    text,
  source       text,                   -- request ip / job name
  detail       jsonb NOT NULL DEFAULT '{}'::jsonb,
  at           timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX mt_audit_log_customer_ix ON mt_audit_log (customer_id, at DESC);
CREATE INDEX mt_audit_log_action_ix   ON mt_audit_log (action, at DESC);

CREATE OR REPLACE FUNCTION mt_audit_append_only() RETURNS trigger AS $$
BEGIN
  RAISE EXCEPTION 'mt_audit_log is append-only: % is not permitted', TG_OP
    USING ERRCODE = 'insufficient_privilege';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER mt_audit_no_update BEFORE UPDATE ON mt_audit_log
  FOR EACH ROW EXECUTE FUNCTION mt_audit_append_only();
CREATE TRIGGER mt_audit_no_delete BEFORE DELETE ON mt_audit_log
  FOR EACH ROW EXECUTE FUNCTION mt_audit_append_only();

-- TRUNCATE bypasses row triggers, so it needs its own statement-level trigger.
-- Without this the append-only guarantee has a one-word hole in it.
CREATE TRIGGER mt_audit_no_truncate BEFORE TRUNCATE ON mt_audit_log
  FOR EACH STATEMENT EXECUTE FUNCTION mt_audit_append_only();
