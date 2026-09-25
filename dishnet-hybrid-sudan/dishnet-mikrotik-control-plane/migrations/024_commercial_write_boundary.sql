-- 024 — A-1 / T3 and B-2: the customer-plane commercial write boundary.
--
-- Migration 022 closed dnb_admin, 023 closed dnb_worker. dnb_app was the third
-- and last role able to INSERT into mt_audit_log directly, and it is the
-- customer-facing HTTP role: it wrote its own audit rows at six call sites in
-- src/Api/Routes.php. Those six were "caller-written and skippable" (docs/86
-- F-8) -- the mutation happened in a repository class and the audit row was a
-- separate statement beside it.
--
-- Measured before this file was written: dnb_app could EXECUTE exactly six
-- SECURITY DEFINER functions and NOT ONE of them performed any of the six
-- audited mutations. Five are mt_auth_* (whose own routes audit nothing at
-- all) and the sixth is mt_voucher_redeem, which has no caller and is to be
-- deleted (docs/87). So there was no boundary to move the audit into. This
-- file builds one per mutation.
--
-- WHY NOT simply grant dnb_app EXECUTE on mt_audit_write(). Of mt_audit_log's
-- ten columns only `id` and `at` are not parameters of that function, so
-- EXECUTE on it is exactly as forgeable as the INSERT it would replace: the
-- caller still chooses the customer, the actor, the actor kind, the action and
-- the target. It would relocate the forgery and look like a remediation. The
-- mutation itself has to be the thing that is authorised, so that the audit
-- row is a consequence of the act rather than a claim about it.

-- ---------------------------------------------------------------------------
-- 1. dnb_def_comm -- a NEW definer role, and the reason is measured
-- ---------------------------------------------------------------------------
-- Every existing definer role already carries a widening `USING (true)` policy
-- on exactly the table these functions must write:
--
--   dnb_def_net    USING(true) on mt_vouchers and mt_hotspot_users
--   dnb_def_work   USING(true) on mt_intents
--   dnb_def_admin  USING(true) SELECT on all of them
--
-- Owning the commercial writers with any of those would REMOVE the tenant
-- isolation that makes them safe. dnb_def_comm is created with NO policy of
-- its own, deliberately, so it inherits the existing
--
--   <table>_isolation  FOR ALL TO public  USING/WITH CHECK
--                      (customer_id = mt_current_customer())
--
-- policies. `TO public` includes every role, so a function running as
-- dnb_def_comm is bound by exactly the same tenant predicate as dnb_app is.
-- Ownership is therefore enforced BELOW the function, not by it: a row for
-- another customer is refused by RLS WITH CHECK even if the function body
-- asked for one, and a row belonging to another customer is invisible to the
-- EXISTS checks the function makes. That is the strongest layer available.
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'dnb_def_comm') THEN
    CREATE ROLE dnb_def_comm NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE
                             NOINHERIT NOBYPASSRLS;
  END IF;
  -- Ownership needs membership; INHERIT FALSE keeps the migration owner from
  -- picking up this role's privileges by inheritance (017 section 1).
  EXECUTE format('GRANT dnb_def_comm TO %I WITH INHERIT FALSE, SET TRUE', current_user);
END $$;

GRANT USAGE ON SCHEMA public TO dnb_def_comm;
-- CREATE transiently, revoked at the end of this file, exactly as 017 does.
GRANT CREATE ON SCHEMA public TO dnb_def_comm;

-- Table privileges. Narrow on purpose: no DELETE anywhere (both tables carry a
-- no-delete trigger), and read-only on everything the functions only consult.
GRANT SELECT, INSERT, UPDATE ON mt_plans            TO dnb_def_comm;
GRANT SELECT, INSERT, UPDATE ON mt_vouchers         TO dnb_def_comm;
GRANT SELECT, INSERT, UPDATE ON mt_voucher_batches  TO dnb_def_comm;
GRANT SELECT, INSERT         ON mt_hotspot_users    TO dnb_def_comm;
GRANT SELECT, INSERT         ON mt_intents          TO dnb_def_comm;
GRANT SELECT, INSERT         ON mt_profiles         TO dnb_def_comm;
GRANT SELECT                 ON mt_customers        TO dnb_def_comm;
GRANT SELECT                 ON mt_sites            TO dnb_def_comm;
GRANT SELECT                 ON mt_principals       TO dnb_def_comm;
GRANT SELECT                 ON mt_sessions         TO dnb_def_comm;

-- Without this the role cannot EVALUATE the isolation policy, so every read
-- and write would fail rather than be scoped. docs/113 measured exactly this
-- for dnb_adminwrite, which cannot even SELECT mt_audit_log for want of it.
GRANT EXECUTE ON FUNCTION mt_current_customer() TO dnb_def_comm;

-- The audit writer belongs to dnb_def_audit and only it may hand out EXECUTE.
SET LOCAL ROLE dnb_def_audit;
GRANT EXECUTE ON FUNCTION mt_audit_write(uuid,text,text,text,text,text,text,jsonb)
  TO dnb_def_comm;
RESET ROLE;

-- ---------------------------------------------------------------------------
-- 2. The six mutation boundaries, plus one internal helper
-- ---------------------------------------------------------------------------
SET LOCAL ROLE dnb_def_comm;

-- Profile resolution, ported verbatim from src/Policy/ProfileResolver.php,
-- including its race behaviour: the loser of a concurrent insert re-reads
-- rather than failing, because this is a lookup and not a write the caller
-- asked for. mt_profiles has no RLS -- it is the shared technical layer, and
-- no customer-facing response carries a profile id (docs/42 section 8.2).
CREATE OR REPLACE FUNCTION mt_profile_resolve(
  p_rate_down bigint, p_rate_up bigint, p_timeout integer,
  p_shared integer, p_cap bigint
) RETURNS uuid
LANGUAGE plpgsql AS $$
DECLARE v_id uuid;
BEGIN
  SELECT id INTO v_id FROM mt_profiles
   WHERE rate_down_bps = p_rate_down AND rate_up_bps = p_rate_up
     AND session_timeout_s = p_timeout AND shared_users = p_shared
     AND data_cap_bytes IS NOT DISTINCT FROM p_cap;
  IF FOUND THEN RETURN v_id; END IF;

  BEGIN
    INSERT INTO mt_profiles
      (rate_down_bps, rate_up_bps, session_timeout_s, shared_users, data_cap_bytes)
    VALUES (p_rate_down, p_rate_up, p_timeout, p_shared, p_cap)
    RETURNING id INTO v_id;
    RETURN v_id;
  EXCEPTION WHEN unique_violation THEN
    SELECT id INTO v_id FROM mt_profiles
     WHERE rate_down_bps = p_rate_down AND rate_up_bps = p_rate_up
       AND session_timeout_s = p_timeout AND shared_users = p_shared
       AND data_cap_bytes IS NOT DISTINCT FROM p_cap;
    RETURN v_id;
  END;
END $$;

-- The two guards every one of the six repeats. Written out rather than
-- factored into a helper so that each function states its own preconditions:
-- a reader of one function should not have to go and find another to learn
-- what it refuses.
--
--   * there is no p_customer parameter anywhere in this file. The customer is
--     mt_current_customer(), set by TenantContext from the authenticated
--     principal. Forging one is UNREPRESENTABLE rather than rejected
--     (docs/105, the mt_site_create contract).
--   * the actor is a parameter, as W-1 requires, and is verified to be a
--     principal of THIS customer by a SELECT that RLS has already scoped.

-- 1/6 --------------------------------------------------------- plan.created
CREATE OR REPLACE FUNCTION mt_plan_create(
  p_name text, p_duration_s integer, p_rate_down_bps bigint, p_rate_up_bps bigint,
  p_data_cap_bytes bigint, p_devices_per_voucher integer, p_mode text,
  p_price_minor bigint, p_currency text, p_site uuid,
  p_actor_principal uuid, p_source text
) RETURNS mt_plans
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
  v_customer uuid := mt_current_customer();
  v_profile  uuid;
  v_plan     mt_plans;
BEGIN
  IF v_customer IS NULL THEN
    RAISE EXCEPTION 'no tenant context' USING ERRCODE = 'check_violation';
  END IF;
  IF p_actor_principal IS NULL
     OR NOT EXISTS (SELECT 1 FROM mt_principals WHERE id = p_actor_principal) THEN
    RAISE EXCEPTION 'actor is not a principal of this customer'
      USING ERRCODE = 'check_violation';
  END IF;
  IF p_site IS NOT NULL
     AND NOT EXISTS (SELECT 1 FROM mt_sites WHERE id = p_site) THEN
    RAISE EXCEPTION 'site is not this customer''s' USING ERRCODE = 'check_violation';
  END IF;

  v_profile := mt_profile_resolve(p_rate_down_bps, p_rate_up_bps, p_duration_s,
                                  p_devices_per_voucher, p_data_cap_bytes);

  INSERT INTO mt_plans
    (customer_id, site_id, profile_id, name, duration_s,
     rate_down_bps, rate_up_bps, data_cap_bytes,
     devices_per_voucher, mode, price_minor, currency, created_by)
  VALUES
    (v_customer, p_site, v_profile, btrim(p_name), p_duration_s,
     p_rate_down_bps, p_rate_up_bps, p_data_cap_bytes,
     p_devices_per_voucher, p_mode, p_price_minor, upper(p_currency),
     p_actor_principal)
  RETURNING * INTO v_plan;

  PERFORM mt_audit_write(v_customer, p_actor_principal::text, 'principal',
                         'plan.created', 'plan', v_plan.id::text, p_source,
                         jsonb_build_object('name', v_plan.name));
  RETURN v_plan;
END $$;

-- 2/6 --------------------------------------------------------- plan.updated
-- A NULL parameter means "unchanged", reproducing
-- array_merge($cur, array_filter($body, fn($v) => $v !== null)) exactly --
-- including the consequence that there is today NO way to clear a data cap.
CREATE OR REPLACE FUNCTION mt_plan_update(
  p_plan uuid, p_name text, p_duration_s integer,
  p_rate_down_bps bigint, p_rate_up_bps bigint, p_data_cap_bytes bigint,
  p_devices_per_voucher integer, p_mode text, p_price_minor bigint,
  p_currency text, p_actor_principal uuid, p_source text
) RETURNS mt_plans
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
  v_customer uuid := mt_current_customer();
  v_cur      mt_plans;
  v_profile  uuid;
  v_plan     mt_plans;
BEGIN
  IF v_customer IS NULL THEN
    RAISE EXCEPTION 'no tenant context' USING ERRCODE = 'check_violation';
  END IF;
  IF p_actor_principal IS NULL
     OR NOT EXISTS (SELECT 1 FROM mt_principals WHERE id = p_actor_principal) THEN
    RAISE EXCEPTION 'actor is not a principal of this customer'
      USING ERRCODE = 'check_violation';
  END IF;

  -- RLS has already scoped this: another customer's plan is simply not there.
  SELECT * INTO v_cur FROM mt_plans WHERE id = p_plan;
  IF NOT FOUND THEN RETURN NULL; END IF;

  v_profile := mt_profile_resolve(
    coalesce(p_rate_down_bps, v_cur.rate_down_bps),
    coalesce(p_rate_up_bps,   v_cur.rate_up_bps),
    coalesce(p_duration_s,    v_cur.duration_s),
    coalesce(p_devices_per_voucher, v_cur.devices_per_voucher),
    coalesce(p_data_cap_bytes, v_cur.data_cap_bytes));

  UPDATE mt_plans SET
      name                = btrim(coalesce(p_name, v_cur.name)),
      duration_s          = coalesce(p_duration_s, v_cur.duration_s),
      rate_down_bps       = coalesce(p_rate_down_bps, v_cur.rate_down_bps),
      rate_up_bps         = coalesce(p_rate_up_bps, v_cur.rate_up_bps),
      data_cap_bytes      = coalesce(p_data_cap_bytes, v_cur.data_cap_bytes),
      devices_per_voucher = coalesce(p_devices_per_voucher, v_cur.devices_per_voucher),
      mode                = coalesce(p_mode, v_cur.mode),
      price_minor         = coalesce(p_price_minor, v_cur.price_minor),
      currency            = upper(coalesce(p_currency, v_cur.currency)),
      profile_id          = v_profile
    WHERE id = p_plan
    RETURNING * INTO v_plan;

  PERFORM mt_audit_write(v_customer, p_actor_principal::text, 'principal',
                         'plan.updated', 'plan', p_plan::text, p_source, '{}'::jsonb);
  RETURN v_plan;
END $$;

-- 3/6 --------------------------------------------------------- plan.retired
-- Retire, never delete: a voucher sold against a plan is a revenue record.
-- There is deliberately NO state guard here, because there is none today --
-- retiring an already-retired plan currently succeeds and audits, and adding
-- a guard would be a behaviour change, not a boundary.
CREATE OR REPLACE FUNCTION mt_plan_retire(
  p_plan uuid, p_actor_principal uuid, p_source text
) RETURNS mt_plans
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
  v_customer uuid := mt_current_customer();
  v_plan     mt_plans;
BEGIN
  IF v_customer IS NULL THEN
    RAISE EXCEPTION 'no tenant context' USING ERRCODE = 'check_violation';
  END IF;
  IF p_actor_principal IS NULL
     OR NOT EXISTS (SELECT 1 FROM mt_principals WHERE id = p_actor_principal) THEN
    RAISE EXCEPTION 'actor is not a principal of this customer'
      USING ERRCODE = 'check_violation';
  END IF;

  UPDATE mt_plans SET active = false WHERE id = p_plan RETURNING * INTO v_plan;
  IF NOT FOUND THEN RETURN NULL; END IF;

  PERFORM mt_audit_write(v_customer, p_actor_principal::text, 'principal',
                         'plan.retired', 'plan', p_plan::text, p_source, '{}'::jsonb);
  RETURN v_plan;
END $$;

-- 4/6 -------------------------------------------------------- voucher.issued
-- Reproduces VoucherService::issueBatch, including the parts that are known
-- defects and are NOT this migration's to fix:
--
--   * the mt_hotspot_users row is written HERE, at issue time. It holds no
--     secret and no site. It is a registry row, not an AAA credential -- AAA
--     publication is a write to the separate RADIUS database and nothing in
--     production calls the publisher. Decision 1 (Model B: publish at
--     redemption/activation) is unchanged by this file and NOT reintroduced
--     here; docs/87's remediation is still the pending work.
--   * radius_username stays a reversible transform of the code. That is the
--     Decision 3 contradiction recorded in docs/86 and is deliberately carried
--     over verbatim rather than silently redesigned inside a security fix.
--   * an idempotency key still only deduplicates the INTENT, not the batch.
--     A replay issues a second batch and returns the first intent. That is
--     today's behaviour (I-A is open) and is preserved exactly.
--
-- Code collisions: p_codes is drawn by the caller from the CSPRNG generator
-- and MUST contain spares. The loop consumes codes in order and skips any
-- that collide, so uniqueness is still decided by the index and a collision is
-- still retried rather than fatal. The one change from the PHP loop is that
-- codes are now drawn eagerly rather than lazily; the observable outcome --
-- p_count distinct vouchers, earlier codes preferred -- is identical.
CREATE OR REPLACE FUNCTION mt_voucher_batch_issue(
  p_plan uuid, p_count integer, p_site uuid, p_actor_principal uuid,
  p_codes text[], p_idempotency_key text, p_source text
) RETURNS TABLE (out_batch uuid, out_intent uuid, out_vouchers uuid[])
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
  v_customer uuid := mt_current_customer();
  v_plan     mt_plans;
  v_batch    uuid;
  v_intent   uuid;
  v_ids      uuid[] := '{}';
  v_vid      uuid;
  v_issued   integer := 0;
  v_ref      text;
  i          integer;
BEGIN
  IF v_customer IS NULL THEN
    RAISE EXCEPTION 'no tenant context' USING ERRCODE = 'check_violation';
  END IF;
  IF p_actor_principal IS NULL
     OR NOT EXISTS (SELECT 1 FROM mt_principals WHERE id = p_actor_principal) THEN
    RAISE EXCEPTION 'actor is not a principal of this customer'
      USING ERRCODE = 'check_violation';
  END IF;
  IF p_site IS NOT NULL
     AND NOT EXISTS (SELECT 1 FROM mt_sites WHERE id = p_site) THEN
    RAISE EXCEPTION 'site is not this customer''s' USING ERRCODE = 'check_violation';
  END IF;
  IF p_count < 1 THEN
    RAISE EXCEPTION 'count must be at least 1' USING ERRCODE = 'check_violation';
  END IF;

  -- RLS scopes this. Another customer's plan is not found, exactly as a
  -- retired one is not: both answer 404 rather than telling the caller which.
  SELECT * INTO v_plan FROM mt_plans WHERE id = p_plan AND active;
  IF NOT FOUND THEN
    -- P0002 is what the caller maps back to its existing 404.
    RAISE EXCEPTION 'plan not found or retired' USING ERRCODE = 'P0002';
  END IF;

  INSERT INTO mt_voucher_batches
    (customer_id, site_id, plan_id, requested_count, created_by)
  VALUES (v_customer, p_site, p_plan, p_count, p_actor_principal)
  RETURNING id INTO v_batch;

  FOR i IN 1 .. coalesce(array_length(p_codes, 1), 0) LOOP
    EXIT WHEN v_issued >= p_count;
    BEGIN
      INSERT INTO mt_vouchers
        (customer_id, batch_id, plan_id, site_id, code,
         price_minor, currency, duration_s)
      VALUES
        (v_customer, v_batch, v_plan.id, p_site, p_codes[i],
         -- Snapshot: what this voucher sold for, fixed at issue.
         v_plan.price_minor, v_plan.currency, v_plan.duration_s)
      RETURNING id INTO v_vid;
      v_ids    := v_ids || v_vid;
      v_issued := v_issued + 1;
    EXCEPTION WHEN unique_violation THEN
      NULL;   -- code collision: take the next draw
    END;
  END LOOP;

  IF v_issued < p_count THEN
    RAISE EXCEPTION 'could not allocate % unique voucher codes', p_count
      USING ERRCODE = 'check_violation';
  END IF;

  SELECT radius_ref INTO v_ref FROM mt_customers WHERE id = v_customer;
  INSERT INTO mt_hotspot_users (voucher_id, customer_id, radius_username)
  SELECT v.id, v.customer_id, v_ref || '-' || replace(v.code, '-', '')
    FROM mt_vouchers v WHERE v.id = ANY (v_ids);

  UPDATE mt_voucher_batches
     SET issued_count = v_issued, state = 'issued', completed_at = now()
   WHERE id = v_batch;

  IF p_idempotency_key IS NOT NULL THEN
    SELECT id INTO v_intent FROM mt_intents
     WHERE idempotency_key = p_idempotency_key;
  END IF;
  IF v_intent IS NULL THEN
    INSERT INTO mt_intents
      (customer_id, actor_principal_id, actor_kind, kind,
       target_type, target_id, payload, idempotency_key)
    VALUES
      (v_customer, p_actor_principal, 'principal', 'voucher.publish',
       'voucher_batch', v_batch::text,
       jsonb_build_object('batch_id', v_batch, 'count', v_issued),
       p_idempotency_key)
    RETURNING id INTO v_intent;
  END IF;

  PERFORM mt_audit_write(v_customer, p_actor_principal::text, 'principal',
                         'voucher.issued', 'voucher_batch', v_batch::text, p_source,
                         jsonb_build_object('count', v_issued));

  RETURN QUERY SELECT v_batch, v_intent, v_ids;
END $$;

-- 5/6 ------------------------------------------------------- voucher.revoked
-- The state guard is the one that already exists and it is load-bearing:
-- docs/108 measured that voucher.revoke is retry-safe ONLY because this
-- UPDATE matches nothing the second time. Moving it into the function keeps
-- that guard ahead of both the intent and the audit row, which is what RULE
-- I-1 requires -- a replay now returns NULL and writes nothing at all,
-- instead of relying on where the statements happened to sit in a handler.
CREATE OR REPLACE FUNCTION mt_voucher_revoke(
  p_voucher uuid, p_actor_principal uuid, p_source text
) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
  v_customer uuid := mt_current_customer();
  v_id       uuid;
  v_intent   uuid;
BEGIN
  IF v_customer IS NULL THEN
    RAISE EXCEPTION 'no tenant context' USING ERRCODE = 'check_violation';
  END IF;
  IF p_actor_principal IS NULL
     OR NOT EXISTS (SELECT 1 FROM mt_principals WHERE id = p_actor_principal) THEN
    RAISE EXCEPTION 'actor is not a principal of this customer'
      USING ERRCODE = 'check_violation';
  END IF;

  UPDATE mt_vouchers SET state = 'revoked', revoked_at = now()
   WHERE id = p_voucher AND state IN ('unused', 'active')
   RETURNING id INTO v_id;
  IF NOT FOUND THEN RETURN NULL; END IF;

  INSERT INTO mt_intents
    (customer_id, actor_principal_id, actor_kind, kind, target_type, target_id, payload)
  VALUES
    (v_customer, p_actor_principal, 'principal', 'voucher.revoke',
     'voucher', p_voucher::text, jsonb_build_object('voucher_id', p_voucher))
  RETURNING id INTO v_intent;

  PERFORM mt_audit_write(v_customer, p_actor_principal::text, 'principal',
                         'voucher.revoked', 'voucher', p_voucher::text,
                         p_source, '{}'::jsonb);
  RETURN v_intent;
END $$;

-- 6/6 --------------------------------------- session.disconnect_requested
-- BOUNDARY ONLY. This deliberately does NOT make session.disconnect replay
-- safe: it has no idempotency key and no state guard today, so a retry still
-- enqueues a second intent. docs/108 records that as a blocker to be fixed
-- before F6-B, and fixing it here would be a silent behaviour change hidden
-- inside a privilege remediation. What DOES change is that the duplicate can
-- no longer be an unaudited or independently-forged one.
CREATE OR REPLACE FUNCTION mt_session_disconnect_request(
  p_session uuid, p_actor_principal uuid, p_source text
) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
  v_customer uuid := mt_current_customer();
  v_intent   uuid;
BEGIN
  IF v_customer IS NULL THEN
    RAISE EXCEPTION 'no tenant context' USING ERRCODE = 'check_violation';
  END IF;
  IF p_actor_principal IS NULL
     OR NOT EXISTS (SELECT 1 FROM mt_principals WHERE id = p_actor_principal) THEN
    RAISE EXCEPTION 'actor is not a principal of this customer'
      USING ERRCODE = 'check_violation';
  END IF;

  -- RLS scopes it: another customer's session is simply not there.
  IF NOT EXISTS (SELECT 1 FROM mt_sessions WHERE id = p_session) THEN
    RETURN NULL;
  END IF;

  INSERT INTO mt_intents
    (customer_id, actor_principal_id, actor_kind, kind, target_type, target_id, payload)
  VALUES
    (v_customer, p_actor_principal, 'principal', 'session.disconnect',
     'session', p_session::text, jsonb_build_object('session_id', p_session))
  RETURNING id INTO v_intent;

  PERFORM mt_audit_write(v_customer, p_actor_principal::text, 'principal',
                         'session.disconnect_requested', 'session', p_session::text,
                         p_source, '{}'::jsonb);
  RETURN v_intent;
END $$;

-- ---------------------------------------------------------------------------
-- 3. Who may call them
-- ---------------------------------------------------------------------------
-- dnb_app only. Not dnb_admin, not dnb_worker, not dnb_adminwrite: these are
-- the CUSTOMER plane, reached from the authenticated customer API and nowhere
-- else. mt_profile_resolve is granted to nobody at all -- it is reachable only
-- from inside the two plan functions, which run as its owner.
REVOKE ALL ON FUNCTION mt_profile_resolve(bigint,bigint,integer,integer,bigint) FROM PUBLIC;

REVOKE ALL ON FUNCTION mt_plan_create(text,integer,bigint,bigint,bigint,integer,text,bigint,text,uuid,uuid,text) FROM PUBLIC;
REVOKE ALL ON FUNCTION mt_plan_update(uuid,text,integer,bigint,bigint,bigint,integer,text,bigint,text,uuid,text) FROM PUBLIC;
REVOKE ALL ON FUNCTION mt_plan_retire(uuid,uuid,text) FROM PUBLIC;
REVOKE ALL ON FUNCTION mt_voucher_batch_issue(uuid,integer,uuid,uuid,text[],text,text) FROM PUBLIC;
REVOKE ALL ON FUNCTION mt_voucher_revoke(uuid,uuid,text) FROM PUBLIC;
REVOKE ALL ON FUNCTION mt_session_disconnect_request(uuid,uuid,text) FROM PUBLIC;

GRANT EXECUTE ON FUNCTION mt_plan_create(text,integer,bigint,bigint,bigint,integer,text,bigint,text,uuid,uuid,text) TO dnb_app;
GRANT EXECUTE ON FUNCTION mt_plan_update(uuid,text,integer,bigint,bigint,bigint,integer,text,bigint,text,uuid,text) TO dnb_app;
GRANT EXECUTE ON FUNCTION mt_plan_retire(uuid,uuid,text) TO dnb_app;
GRANT EXECUTE ON FUNCTION mt_voucher_batch_issue(uuid,integer,uuid,uuid,text[],text,text) TO dnb_app;
GRANT EXECUTE ON FUNCTION mt_voucher_revoke(uuid,uuid,text) TO dnb_app;
GRANT EXECUTE ON FUNCTION mt_session_disconnect_request(uuid,uuid,text) TO dnb_app;

COMMENT ON FUNCTION mt_plan_create(text,integer,bigint,bigint,bigint,integer,text,bigint,text,uuid,uuid,text) IS
  'A-1/T3, B-2. Creates a plan and its audit row in one transaction. Customer is mt_current_customer(); there is no customer parameter.';
COMMENT ON FUNCTION mt_voucher_batch_issue(uuid,integer,uuid,uuid,text[],text,text) IS
  'A-1/T3, B-2. Issues a voucher batch and its audit row in one transaction. Reproduces VoucherService::issueBatch verbatim, including the docs/86 defects it is not this migration''s job to fix.';
COMMENT ON FUNCTION mt_session_disconnect_request(uuid,uuid,text) IS
  'A-1/T3, B-2. Boundary only -- session.disconnect replay safety remains an open blocker (docs/108).';

RESET ROLE;

-- No definer role holds CREATE at rest.
REVOKE CREATE ON SCHEMA public FROM dnb_def_comm;

-- ---------------------------------------------------------------------------
-- 4. T3 -- the last direct audit writer is closed
-- ---------------------------------------------------------------------------
-- With all six mutations behind boundaries that write their own audit row,
-- dnb_app has no remaining reason to hold INSERT on mt_audit_log.
REVOKE INSERT ON mt_audit_log FROM dnb_app;

-- The half that would otherwise expire. Migration 015 set ALTER DEFAULT
-- PRIVILEGES, so the NEXT table created would hand the grant straight back --
-- and the attempt store (docs/89) and the non-tenant idempotency store
-- (docs/108) are both still to be created.
ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE INSERT ON TABLES FROM dnb_app;

COMMENT ON TABLE mt_audit_log IS
  'Append-only audit trail. A-1: no login role may INSERT directly -- dnb_admin revoked in 022, dnb_worker in 023, dnb_app in 024. The only writer is mt_audit_write(), owned by dnb_def_audit and callable only by the definer roles that own audited mutations.';
