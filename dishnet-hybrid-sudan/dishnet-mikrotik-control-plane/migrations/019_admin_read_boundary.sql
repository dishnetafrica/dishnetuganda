-- 019 — the Admin cross-customer READ boundary (docs/83, D-1 accepted)
--
-- DishNet staff read the estate across customers. The customer RLS boundary
-- must not move an inch to let them.
--
-- What this does NOT do, deliberately:
--   * no BYPASSRLS, on any role
--   * no superuser
--   * no ALTER TABLE ... DISABLE / NO FORCE ROW LEVEL SECURITY
--   * no policy on dnb_admin, which is a LOGIN role and would then be able to
--     write its own queries against any column of any row
--   * no function reads mt_current_customer(), so a caller-supplied tenant
--     context cannot influence an estate read
--
-- The mechanism is the one migrations 015-017 already established and that
-- mt_devices_samplable() already uses for the worker: a SECURITY DEFINER
-- function with a pinned search_path, owned by a NOLOGIN role, returning a
-- FIXED column list, with EXECUTE granted to a login role that holds no table
-- privilege at all. Narrowing is by COLUMN (the RETURNS TABLE contract) and by
-- REACHABILITY (EXECUTE only) — row narrowing is impossible for an estate read
-- by definition, since the requirement is every row.
--
-- READ ONLY. No admin write is bound here. mt_device_assign still writes no
-- audit row (docs/72 §A.4) and must not be reachable over HTTP until that is
-- fixed.

-- ---------------------------------------------------------------------------
-- 0. The Admin API's OWN login role.
--
-- Not dnb_admin. Migration 015 line 60 grants dnb_admin SELECT/INSERT/UPDATE/
-- DELETE on ALL TABLES, with ALTER DEFAULT PRIVILEGES so new tables inherit it.
-- That is bounded by RLS today — measured: dnb_admin reading mt_customers with
-- no tenant context returns 0 rows — so it is not an estate bypass. But the
-- approved requirement is that the Admin API's role holds NO base-table SELECT
-- at all, and for dnb_admin that is simply not true.
--
-- Revoking migration 015's grant would change an existing, reviewed privilege
-- model that provisioning depends on. Giving the read surface its own identity
-- changes nothing that exists and satisfies the requirement exactly:
-- dnb_adminapi holds EXECUTE on eleven functions and nothing else, forever.
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'dnb_adminapi') THEN
    -- No PASSWORD clause, deliberately (docs/97). A migration is
    -- source-controlled and replayed identically everywhere; a credential
    -- must be neither. rolpassword stays NULL, which under scram-sha-256
    -- means this role cannot authenticate at all until the installer sets
    -- a credential. Privileges are declared here; credentials are not.
    CREATE ROLE dnb_adminapi LOGIN
                             NOSUPERUSER NOCREATEDB NOCREATEROLE
                             NOINHERIT NOBYPASSRLS;
  END IF;
END $$;
GRANT USAGE ON SCHEMA public TO dnb_adminapi;
-- Deliberately NOT granted: any privilege on any table, now or by default.

-- ---------------------------------------------------------------------------
-- 1. The owner role. NOLOGIN: never connected as, only executed as.
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'dnb_def_admin') THEN
    CREATE ROLE dnb_def_admin NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE
                              NOINHERIT NOBYPASSRLS;
  END IF;
END $$;

GRANT USAGE ON SCHEMA public TO dnb_def_admin;
-- CREATE is required transiently so this role can own functions in the schema.
-- Revoked at the end, and a test asserts it cannot create anything afterwards.
GRANT CREATE ON SCHEMA public TO dnb_def_admin;

-- PostgreSQL requires membership in a role to hand it ownership of an object,
-- and PG16 gives the creator ADMIN but withholds SET, which reassignment
-- needs. Exactly what migration 017 does for the other four definer roles,
-- and for the same reason: WITH INHERIT FALSE means the migration owner can
-- ACT AS this role but carries none of its privileges while acting as itself.
DO $$
BEGIN
  EXECUTE format('GRANT dnb_def_admin TO %I WITH INHERIT FALSE, SET TRUE',
                 current_user);
END $$;

-- ---------------------------------------------------------------------------
-- 2. SELECT for the owner role only, on exactly the nine tables the reads use.
--
-- mt_profiles is absent on purpose: it carries no RLS and no tenant column, so
-- it needs no grant (docs/83 F-A). mt_device_secrets, mt_device_config,
-- mt_auth_codes, mt_auth_sessions and mt_principals are absent because no
-- admin read may reach them at all.
DO $$
DECLARE t text; pol text;
BEGIN
  FOREACH t IN ARRAY ARRAY['mt_customers','mt_sites','mt_devices','mt_plans',
                           'mt_vouchers','mt_voucher_batches','mt_sessions',
                           'mt_intents','mt_audit_log'] LOOP
    EXECUTE format('GRANT SELECT ON %I TO dnb_def_admin', t);
    pol := 'dnb_def_admin_' || t || '_select';
    EXECUTE format('DROP POLICY IF EXISTS %I ON %I', pol, t);
    -- Estate scope. Reachable ONLY through the functions below, because
    -- dnb_def_admin cannot log in and dnb_admin gets no table privilege.
    EXECUTE format('CREATE POLICY %I ON %I FOR SELECT TO dnb_def_admin USING (true)',
                   pol, t);
  END LOOP;
END $$;

-- ---------------------------------------------------------------------------
-- 3. The projections. Each RETURNS TABLE list is exactly the matching
--    AdminProjection allowlist; tests/test_admin_read_boundary.php compares the
--    two programmatically, so the contract cannot drift in one place only.
--
--    Withheld everywhere: mt_customers.radius_ref, mt_devices.wg_pubkey,
--    mt_vouchers.code, mt_intents.payload, mt_intents.last_error,
--    mt_audit_log.detail (D-2).

CREATE OR REPLACE FUNCTION mt_admin_customers()
RETURNS TABLE (id uuid, name text, ucrm_client_id integer, status text,
               created_at timestamptz)
LANGUAGE sql SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT c.id, c.name, c.ucrm_client_id, c.status, c.created_at
    FROM mt_customers c ORDER BY c.name;
$$;

CREATE OR REPLACE FUNCTION mt_admin_customer(p_id uuid)
RETURNS TABLE (id uuid, name text, ucrm_client_id integer, status text,
               created_at timestamptz)
LANGUAGE sql SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT c.id, c.name, c.ucrm_client_id, c.status, c.created_at
    FROM mt_customers c WHERE c.id = p_id;
$$;

CREATE OR REPLACE FUNCTION mt_admin_sites()
RETURNS TABLE (id uuid, customer_id uuid, service_id uuid, name text,
               location text, created_at timestamptz)
LANGUAGE sql SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT s.id, s.customer_id, s.service_id, s.name, s.location, s.created_at
    FROM mt_sites s ORDER BY s.name;
$$;

-- No wg_pubkey. No sealed credential — mt_device_secrets is not named here or
-- anywhere in this file.
CREATE OR REPLACE FUNCTION mt_admin_routers()
RETURNS TABLE (id uuid, customer_id uuid, site_id uuid, serial text, model text,
               ros_version text, tunnel_ip text, name text, state text,
               wan_interface text, wan_interface_set_by text, staged_by text,
               staged_at timestamptz, claimed_at timestamptz,
               last_seen_at timestamptz, created_at timestamptz)
LANGUAGE sql SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT d.id, d.customer_id, d.site_id, d.serial, d.model, d.ros_version,
         d.tunnel_ip, d.name, d.state, d.wan_interface, d.wan_interface_set_by,
         d.staged_by, d.staged_at, d.claimed_at, d.last_seen_at, d.created_at
    FROM mt_devices d ORDER BY d.serial;
$$;

CREATE OR REPLACE FUNCTION mt_admin_router(p_id uuid)
RETURNS TABLE (id uuid, customer_id uuid, site_id uuid, serial text, model text,
               ros_version text, tunnel_ip text, name text, state text,
               wan_interface text, wan_interface_set_by text, staged_by text,
               staged_at timestamptz, claimed_at timestamptz,
               last_seen_at timestamptz, created_at timestamptz)
LANGUAGE sql SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT d.id, d.customer_id, d.site_id, d.serial, d.model, d.ros_version,
         d.tunnel_ip, d.name, d.state, d.wan_interface, d.wan_interface_set_by,
         d.staged_by, d.staged_at, d.claimed_at, d.last_seen_at, d.created_at
    FROM mt_devices d WHERE d.id = p_id;
$$;

CREATE OR REPLACE FUNCTION mt_admin_plans()
RETURNS TABLE (id uuid, customer_id uuid, site_id uuid, profile_id uuid,
               name text, duration_s integer, rate_down_bps bigint,
               rate_up_bps bigint, data_cap_bytes bigint,
               devices_per_voucher integer, mode text, price_minor bigint,
               currency character(3), active boolean, created_at timestamptz)
LANGUAGE sql SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT p.id, p.customer_id, p.site_id, p.profile_id, p.name, p.duration_s,
         p.rate_down_bps, p.rate_up_bps, p.data_cap_bytes,
         p.devices_per_voucher, p.mode, p.price_minor, p.currency, p.active,
         p.created_at
    FROM mt_plans p ORDER BY p.created_at DESC;
$$;

-- No `code`. A batch print is a deliberate, audited action; it is not a side
-- effect of opening a list.
CREATE OR REPLACE FUNCTION mt_admin_vouchers()
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
    FROM mt_vouchers v ORDER BY v.created_at DESC;
$$;

CREATE OR REPLACE FUNCTION mt_admin_voucher_batches()
RETURNS TABLE (id uuid, customer_id uuid, site_id uuid, plan_id uuid,
               requested_count integer, issued_count integer, state text,
               created_at timestamptz, completed_at timestamptz)
LANGUAGE sql SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT b.id, b.customer_id, b.site_id, b.plan_id, b.requested_count,
         b.issued_count, b.state, b.created_at, b.completed_at
    FROM mt_voucher_batches b ORDER BY b.created_at DESC;
$$;

-- No radius_username (the AAA identity) and no mac (a guest's device address).
CREATE OR REPLACE FUNCTION mt_admin_sessions()
RETURNS TABLE (id uuid, customer_id uuid, voucher_id uuid, device_id uuid,
               nas_identifier text, ip text, bytes_in bigint, bytes_out bigint,
               state text, terminate_cause text, started_at timestamptz,
               last_seen_at timestamptz, ended_at timestamptz)
LANGUAGE sql SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT s.id, s.customer_id, s.voucher_id, s.device_id, s.nas_identifier,
         s.ip, s.bytes_in, s.bytes_out, s.state, s.terminate_cause,
         s.started_at, s.last_seen_at, s.ended_at
    FROM mt_sessions s ORDER BY s.started_at DESC;
$$;

-- D-2: no payload, no last_error. Both are free-form, so their names say
-- nothing about what a future code path may put inside them.
CREATE OR REPLACE FUNCTION mt_admin_intents()
RETURNS TABLE (id uuid, customer_id uuid, kind text, state text,
               attempts integer, max_attempts integer, target_type text,
               target_id text, created_at timestamptz, sent_at timestamptz,
               confirmed_at timestamptz, failed_at timestamptz)
LANGUAGE sql SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT i.id, i.customer_id, i.kind, i.state, i.attempts, i.max_attempts,
         i.target_type, i.target_id, i.created_at, i.sent_at, i.confirmed_at,
         i.failed_at
    FROM mt_intents i ORDER BY i.created_at DESC;
$$;

-- D-2: no raw `detail` (jsonb, free-form).
CREATE OR REPLACE FUNCTION mt_admin_audit()
RETURNS TABLE (id uuid, customer_id uuid, actor text, actor_kind text,
               action text, target_type text, target_id text, source text,
               at timestamptz)
LANGUAGE sql SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT a.id, a.customer_id, a.actor, a.actor_kind, a.action, a.target_type,
         a.target_id, a.source, a.at
    FROM mt_audit_log a ORDER BY a.at DESC;
$$;

-- ---------------------------------------------------------------------------
-- 4. Ownership and grants. EXECUTE to dnb_adminapi, and NO table privilege.
DO $$
DECLARE f text;
BEGIN
  FOREACH f IN ARRAY ARRAY[
    'mt_admin_customers()', 'mt_admin_customer(uuid)', 'mt_admin_sites()',
    'mt_admin_routers()', 'mt_admin_router(uuid)', 'mt_admin_plans()',
    'mt_admin_vouchers()', 'mt_admin_voucher_batches()', 'mt_admin_sessions()',
    'mt_admin_intents()', 'mt_admin_audit()'] LOOP
    EXECUTE format('ALTER FUNCTION %s OWNER TO dnb_def_admin', f);
    -- SET LOCAL ROLE is NOT ceremony. After the ALTER above, the migration
    -- owner is no longer the function's owner and holds no grant option, and
    -- PostgreSQL answers a non-owner GRANT with a WARNING, not an error: the
    -- statement is accepted and does nothing. The first version of this
    -- migration omitted it and shipped an inert grant, which the boundary test
    -- caught as "permission denied for function mt_admin_customers". Migration
    -- 017 already does it this way for the same reason.
    EXECUTE 'SET LOCAL ROLE dnb_def_admin';
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC', f);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO dnb_adminapi', f);
    EXECUTE 'RESET ROLE';
  END LOOP;
END $$;

-- ---------------------------------------------------------------------------
-- 4a. Prove the grants actually took.
--
-- An accepted-but-inert privilege statement is this project's most frequent
-- defect; three were found in earlier audits and this migration produced a
-- fourth on its first run. A migration that cannot demonstrate its own effect
-- is not a migration, so this refuses to complete rather than reporting
-- success for work that did nothing.
DO $$
DECLARE f text; missing text[] := '{}';
BEGIN
  FOREACH f IN ARRAY ARRAY[
    'mt_admin_customers()', 'mt_admin_customer(uuid)', 'mt_admin_sites()',
    'mt_admin_routers()', 'mt_admin_router(uuid)', 'mt_admin_plans()',
    'mt_admin_vouchers()', 'mt_admin_voucher_batches()', 'mt_admin_sessions()',
    'mt_admin_intents()', 'mt_admin_audit()'] LOOP
    IF NOT has_function_privilege('dnb_adminapi', f, 'EXECUTE') THEN
      missing := missing || f;
    END IF;
  END LOOP;
  IF array_length(missing, 1) IS NOT NULL THEN
    RAISE EXCEPTION 'dnb_adminapi did not receive EXECUTE on: %',
      array_to_string(missing, ', ');
  END IF;
END $$;

-- The transient CREATE goes back.
REVOKE CREATE ON SCHEMA public FROM dnb_def_admin;

-- A proacl of NULL means "never touched", which carries the built-in PUBLIC
-- default and is invisible to an ACL sweep. Re-run the sweep so functions
-- added above cannot keep it (docs/73 §1.1).
SELECT mt_revoke_public_execute();
