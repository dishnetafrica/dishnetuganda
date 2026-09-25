-- 023 — A-1 / T2: the worker audit boundary.
--
-- Migration 022 closed dnb_admin. dnb_worker still held direct INSERT on
-- mt_audit_log because IntentWorker wrote its two audit rows itself:
--
--   intent.confirmed   after delivery is CONFIRMED by a separate read
--   intent.failed      after a permanent (non-retryable) delivery failure
--
-- Those are the only two audit writes the worker performs anywhere. Moving
-- them behind a definer lets the direct grant go.
--
-- Why this is not a new boundary. dnb_def_work already owns the intent
-- lifecycle -- mt_intent_claim, mt_intent_expire_overdue -- and already holds
-- its own mt_intents SELECT and UPDATE policies. The audit write belongs in
-- the same place the lifecycle already lives, not in a boundary invented for
-- it.
--
-- What the caller can no longer choose. Under the direct INSERT the worker
-- supplied customer_id, actor_kind and the action string itself, so a
-- compromised or buggy worker could write an audit row naming any customer,
-- any actor kind and any action. Through this function:
--
--   customer_id  is DERIVED from the intent row, never supplied
--   actor_kind   is fixed to 'system'
--   action       is constrained to exactly two values by p_outcome
--   target       is the intent, by construction
--
-- The worker still supplies its own identity as p_worker, which is the W-1
-- shape: the actor is a parameter from the identity boundary, not a GUC and
-- not a request field.
--
-- Audit semantics are preserved exactly. The previous calls passed source =
-- NULL and, for the failure case only, detail = {"reason": ...}. Both are kept
-- so existing rows and new rows are indistinguishable in shape.

-- Ownership transfer needs membership; INHERIT FALSE keeps the migration owner
-- from picking up these roles' policies by inheritance (017 section 1, and the
-- same block migration 020 uses).
DO $membership$
BEGIN
  EXECUTE format('GRANT dnb_def_audit TO %I WITH INHERIT FALSE, SET TRUE', current_user);
  EXECUTE format('GRANT dnb_def_work  TO %I WITH INHERIT FALSE, SET TRUE', current_user);
END $membership$;

SET LOCAL ROLE dnb_def_audit;
-- dnb_def_work joins dnb_def_prov as a definer role permitted to write audit
-- rows. Still deliberately NOT dnb_app, dnb_admin, dnb_adminwrite or
-- dnb_worker: a caller may CAUSE an audit row by performing an audited act; it
-- may not write one directly.
GRANT EXECUTE ON FUNCTION mt_audit_write(uuid,text,text,text,text,text,text,jsonb)
  TO dnb_def_work;
RESET ROLE;

GRANT USAGE ON SCHEMA public TO dnb_def_work;
-- CREATE transiently, revoked at the end of this file, exactly as 017, 019 and
-- 020 do. No definer role holds CREATE on the schema at rest.
GRANT CREATE ON SCHEMA public TO dnb_def_work;

SET LOCAL ROLE dnb_def_work;

CREATE OR REPLACE FUNCTION mt_intent_audit(
  p_intent  uuid,
  p_worker  text,
  p_outcome text,
  p_reason  text DEFAULT NULL
) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp
AS $$
DECLARE v_customer uuid;
BEGIN
  -- The action vocabulary is closed. A caller cannot name an arbitrary action,
  -- which was the whole of what made a direct INSERT forgeable.
  IF p_outcome IS NULL OR p_outcome NOT IN ('confirmed','failed') THEN
    RAISE EXCEPTION 'intent audit outcome must be confirmed or failed, got %',
      coalesce(p_outcome, '(null)') USING ERRCODE = 'check_violation';
  END IF;

  -- Derived, never supplied. An audit row cannot be attached to a customer the
  -- intent does not belong to, nor to an intent that does not exist.
  SELECT customer_id INTO v_customer FROM mt_intents WHERE id = p_intent;
  IF v_customer IS NULL THEN
    RAISE EXCEPTION 'no such intent: %', p_intent USING ERRCODE = 'check_violation';
  END IF;

  PERFORM mt_audit_write(
    v_customer, p_worker, 'system', 'intent.' || p_outcome,
    'intent', p_intent::text, NULL,
    -- Keyed on the OUTCOME, not on whether the reason happens to be null.
    -- The previous code wrote {"reason": null} for a failure with no message,
    -- and {} for a confirmation; keying on p_reason would silently collapse
    -- the first case into the second and change the row's shape.
    CASE WHEN p_outcome = 'failed'
         THEN jsonb_build_object('reason', p_reason)
         ELSE '{}'::jsonb END);
END $$;

REVOKE ALL ON FUNCTION mt_intent_audit(uuid,text,text,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mt_intent_audit(uuid,text,text,text) TO dnb_worker;

COMMENT ON FUNCTION mt_intent_audit(uuid,text,text,text) IS
  'A-1/T2. The worker''s only audit path. customer_id is derived from the '
  'intent, actor_kind is fixed to system, and the action is one of exactly '
  'two values -- so the worker can no longer name an arbitrary customer, '
  'kind or action. dnb_worker holds EXECUTE here and no INSERT on '
  'mt_audit_log.';

RESET ROLE;

-- Back to no CREATE at rest, as 017 line 292 leaves the other definer roles.
REVOKE CREATE ON SCHEMA public FROM dnb_def_work;

-- The direct grant can now go. IntentWorker calls mt_intent_audit() instead,
-- and nothing else in the worker path writes mt_audit_log.
REVOKE INSERT ON mt_audit_log FROM dnb_worker;

-- And the default, or the fix expires on the next table created (the attempt
-- store and the non-tenant idempotency store are both still to come).
ALTER DEFAULT PRIVILEGES IN SCHEMA public
  REVOKE INSERT ON TABLES FROM dnb_worker;

