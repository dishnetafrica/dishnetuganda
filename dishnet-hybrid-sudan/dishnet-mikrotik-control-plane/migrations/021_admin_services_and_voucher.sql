-- 021 — two approved additions to the Admin read boundary (docs/92 §6.2)
--
-- The boundary D-1/D-2 fixed at eleven projections becomes thirteen. Both were
-- approved explicitly; neither exposes anything new in kind.
--
--   mt_admin_services()      the HotSpot screen needs the service record, and
--                            no Admin path reached mt_services at all.
--   mt_admin_voucher(uuid)   voucher detail, the single-row shape routers and
--                            customers already have.
--
-- WHAT THESE DO NOT DO. mt_admin_services reports the RECORDED service state —
-- what the control plane was told to set up. It is NOT a liveness signal and no
-- screen may read it as one: whether a HotSpot server is actually running on a
-- router remains unmeasured until F6-B, and SignalReport continues to say so.
--
-- mt_admin_voucher withholds `code`, exactly as the list projection does. A
-- voucher code is a bearer credential and docs/67 §5 keeps it out of every path
-- but redemption. No AAA username, no password, no RADIUS credential and no raw
-- redemption data appears here either — none of it is on mt_vouchers to begin
-- with, and this function reads nothing else.

GRANT CREATE ON SCHEMA public TO dnb_def_admin;

-- ---------------------------------------------------------------------------
-- 1. mt_services joins the tables the owner role may read.
--
-- Same shape as migration 019 §2: SELECT to the NOLOGIN owner, one estate-scope
-- policy, reachable only through the function below because dnb_def_admin
-- cannot log in and dnb_adminapi holds no table privilege.
GRANT SELECT ON mt_services TO dnb_def_admin;
DROP POLICY IF EXISTS dnb_def_admin_mt_services_select ON mt_services;
CREATE POLICY dnb_def_admin_mt_services_select ON mt_services
  FOR SELECT TO dnb_def_admin USING (true);

-- ---------------------------------------------------------------------------
-- 2. The projections. Each RETURNS TABLE list is exactly the matching
--    AdminProjection allowlist; the boundary test compares the two
--    programmatically, so the contract cannot drift in one place only.

CREATE OR REPLACE FUNCTION mt_admin_services()
RETURNS TABLE (id uuid, customer_id uuid, kind text, status text,
               started_at timestamptz, ended_at timestamptz)
LANGUAGE sql SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT s.id, s.customer_id, s.kind, s.status, s.started_at, s.ended_at
    FROM mt_services s ORDER BY s.started_at DESC NULLS LAST, s.id;
$$;

-- Deliberately the same column list as mt_admin_vouchers(): a detail view that
-- returned MORE than the list would be a way to reach a withheld field by
-- asking for one row at a time.
CREATE OR REPLACE FUNCTION mt_admin_voucher(p_voucher uuid)
RETURNS TABLE (id uuid, customer_id uuid, batch_id uuid, plan_id uuid,
               site_id uuid, state text, price_minor bigint,
               currency character(3), duration_s integer,
               created_at timestamptz, activated_at timestamptz,
               expires_at timestamptz, revoked_at timestamptz,
               sold_at timestamptz)
LANGUAGE sql SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT v.id, v.customer_id, v.batch_id, v.plan_id, v.site_id, v.state,
         v.price_minor, v.currency, v.duration_s, v.created_at, v.activated_at,
         v.expires_at, v.revoked_at, v.sold_at
    FROM mt_vouchers v WHERE v.id = p_voucher;
$$;

-- ---------------------------------------------------------------------------
-- 3. Ownership and grants. EXECUTE to dnb_adminapi, and no table privilege.
DO $$
DECLARE f text;
BEGIN
  FOREACH f IN ARRAY ARRAY['mt_admin_services()', 'mt_admin_voucher(uuid)'] LOOP
    EXECUTE format('ALTER FUNCTION %s OWNER TO dnb_def_admin', f);
    -- SET LOCAL ROLE is load-bearing, not ceremony: after the ALTER the
    -- migration owner holds no grant option, and PostgreSQL answers a non-owner
    -- GRANT with a WARNING rather than an error, so the statement is accepted
    -- and does nothing. Migration 019 shipped exactly that defect on its first
    -- run.
    EXECUTE 'SET LOCAL ROLE dnb_def_admin';
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO dnb_adminapi', f);
    EXECUTE 'RESET ROLE';
  END LOOP;
END $$;

-- ---------------------------------------------------------------------------
-- 4. Prove the effect rather than announcing it.
DO $$
DECLARE f text; missing text[] := '{}'; leaked text[] := '{}';
BEGIN
  FOREACH f IN ARRAY ARRAY['mt_admin_services()', 'mt_admin_voucher(uuid)'] LOOP
    IF NOT has_function_privilege('dnb_adminapi', f, 'EXECUTE') THEN
      missing := missing || f;
    END IF;
    -- The request role must not reach an estate-wide projection.
    IF has_function_privilege('dnb_app', f, 'EXECUTE') THEN
      leaked := leaked || f;
    END IF;
  END LOOP;
  IF array_length(missing, 1) IS NOT NULL THEN
    RAISE EXCEPTION 'dnb_adminapi did not receive EXECUTE on: %',
      array_to_string(missing, ', ');
  END IF;
  IF array_length(leaked, 1) IS NOT NULL THEN
    RAISE EXCEPTION 'dnb_app can reach an estate projection: %',
      array_to_string(leaked, ', ');
  END IF;

  -- dnb_adminapi must still hold NO table privilege anywhere, including the
  -- table this migration just opened to the owner role.
  IF EXISTS (SELECT 1 FROM information_schema.role_table_grants
              WHERE grantee = 'dnb_adminapi') THEN
    RAISE EXCEPTION 'dnb_adminapi acquired a table privilege';
  END IF;

  -- The voucher code must not have leaked into either new projection.
  IF EXISTS (
    SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
     WHERE n.nspname = 'public'
       AND p.proname IN ('mt_admin_services', 'mt_admin_voucher')
       AND pg_get_function_result(p.oid) ~* '\mcode\M'
  ) THEN
    RAISE EXCEPTION 'a new projection returns a column named code';
  END IF;
END $$;

REVOKE CREATE ON SCHEMA public FROM dnb_def_admin;
SELECT mt_revoke_public_execute();
