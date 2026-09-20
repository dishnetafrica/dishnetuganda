-- 017 — remediation of audit finding F2 (docs/58 §13)
--
-- THE CONTROL PLANE SILENTLY DID NOTHING WITHOUT A SUPERUSER.
--
-- Every SECURITY DEFINER function was owned by the database owner, and every
-- tenant table has FORCE ROW LEVEL SECURITY, which binds the owner too. So the
-- functions worked only because the owner happened to be a superuser and
-- superusers ignore RLS. Measured with a non-superuser owner, three functions
-- failed loudly and ELEVEN SILENTLY DID NOTHING while returning a
-- success-shaped answer: logins that never succeed, RADIUS packets discarded,
-- a worker reporting an empty queue forever, devices that report provisioned
-- and are not. Nothing in an error log.
--
-- The functions are right to bypass RLS; each one runs before a tenant exists,
-- across tenants, or above them. The defect is bypassing it by BEING A
-- SUPERUSER, which is an undeclared, unbounded, unauditable grant that also
-- meant a flaw in any one of the twenty functions executed with superuser
-- rights.
--
-- So the bypass becomes four owner roles, one per trust context, each holding
-- explicit policies for exactly the tables and commands its functions touch.
-- They own no tables, so ordinary RLS applies to them and every privilege they
-- have is a visible row in pg_policy. dnb_def_auth cannot read a device
-- secret; dnb_def_prov cannot read a session. Previously all twenty functions
-- could do anything at all.
--
-- The database owner keeps DDL and nothing else, and stays subject to FORCE
-- RLS on every tenant table — which is what docs/57 §11.4 claimed and could
-- not deliver.

-- ---------------------------------------------------------------------------
-- 1. The four function-owner roles. NOLOGIN: they are never connected as, only
--    executed as. Member of nothing, so none can reach another's privileges.
DO $$
DECLARE r text;
BEGIN
  FOREACH r IN ARRAY ARRAY['dnb_def_auth','dnb_def_net','dnb_def_work','dnb_def_prov'] LOOP
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = r) THEN
      EXECUTE format(
        'CREATE ROLE %I NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS', r);
    END IF;
    EXECUTE format('GRANT USAGE ON SCHEMA public TO %I', r);
    -- CREATE is required transiently: PostgreSQL will not make a role the owner
    -- of an object in a schema it cannot create in. It is revoked at the end of
    -- this migration, and a test asserts none of these roles can create
    -- anything afterwards.
    EXECUTE format('GRANT CREATE ON SCHEMA public TO %I', r);
    -- PostgreSQL requires membership in a role to hand it ownership of an
    -- object. The owner therefore becomes a member of each definer role. That
    -- is not an escalation: the owner already holds DDL on these functions and
    -- could rewrite them outright. It is recorded as a residual in docs/58.
    -- PostgreSQL 16 gives the creator ADMIN but withholds SET, and reassigning
    -- ownership requires SET. Granted unconditionally so the statement also
    -- repairs a role left behind by an earlier partial run.
    --
    -- INHERIT FALSE is load-bearing. With the default, the owner would INHERIT
    -- every policy granted to these roles and could read and write tenant data
    -- through them — a quieter version of the superuser dependency this
    -- migration exists to remove. The owner may become a definer role
    -- deliberately (SET ROLE), which it needs in order to hand over ownership,
    -- but it carries none of their privileges while acting as itself.
    -- tests/test_definer_roles.php proves the owner still reads nothing.
    EXECUTE format('GRANT %I TO %I WITH INHERIT FALSE, SET TRUE', r, current_user);
  END LOOP;
END $$;

-- ---------------------------------------------------------------------------
-- 2. Table privileges and matching RLS policies, from the access matrix in
--    docs/58 §13.2. Declared once, here, as data: the loop below is mechanical
--    so that the PRIVILEGE SURFACE IS THIS LIST and can be read as one.
--
--    S/I/U/D map to SELECT/INSERT/UPDATE/DELETE. A policy is created per role,
--    per table, per command — USING for read-side commands, WITH CHECK for
--    write-side — rather than one FOR ALL policy, so that a role permitted to
--    insert is not thereby permitted to read.
DO $$
DECLARE
  spec text[][] := ARRAY[
    -- pre-authentication: no tenant exists yet
    ['dnb_def_auth','mt_auth_codes',    'SIU'],
    ['dnb_def_auth','mt_principals',    'SU' ],
    ['dnb_def_auth','mt_auth_sessions', 'SIU'],
    -- network-side entry points: the portal guest and the NAS have no tenant
    ['dnb_def_net', 'mt_vouchers',      'SU' ],
    ['dnb_def_net', 'mt_hotspot_users', 'S'  ],
    ['dnb_def_net', 'mt_sessions',      'SIU'],
    -- cross-customer background work: one worker, whole fleet
    ['dnb_def_work','mt_intents',       'SU' ],
    ['dnb_def_work','mt_sessions',      'SU' ],
    ['dnb_def_work','mt_uplink_samples','SID'],
    ['dnb_def_work','mt_devices',       'S'  ],
    -- provisioning and onboarding: stock belongs to nobody until assigned
    ['dnb_def_prov','mt_devices',       'SIU'],
    ['dnb_def_prov','mt_device_secrets','SIU'],
    ['dnb_def_prov','mt_device_config', 'SIU'],
    ['dnb_def_prov','mt_customers',     'I'  ]
  ];
  i int; role_ text; tbl text; cmds text; c text; cmd text; pol text;
BEGIN
  FOR i IN 1 .. array_length(spec, 1) LOOP
    role_ := spec[i][1]; tbl := spec[i][2]; cmds := spec[i][3];
    FOR c IN SELECT regexp_split_to_table(cmds, '') LOOP
      cmd := CASE c WHEN 'S' THEN 'SELECT' WHEN 'I' THEN 'INSERT'
                    WHEN 'U' THEN 'UPDATE' WHEN 'D' THEN 'DELETE' END;
      EXECUTE format('GRANT %s ON %I TO %I', cmd, tbl, role_);

      pol := format('%s_%s_%s', role_, tbl, lower(cmd));
      EXECUTE format('DROP POLICY IF EXISTS %I ON %I', pol, tbl);
      -- USING governs which existing rows a command may see; WITH CHECK
      -- governs which rows it may leave behind. INSERT has no USING, DELETE
      -- and SELECT have no WITH CHECK, and UPDATE needs both.
      EXECUTE format('CREATE POLICY %I ON %I FOR %s TO %I %s',
        pol, tbl, cmd, role_,
        CASE cmd
          WHEN 'INSERT' THEN 'WITH CHECK (true)'
          WHEN 'UPDATE' THEN 'USING (true) WITH CHECK (true)'
          ELSE               'USING (true)'
        END);
    END LOOP;
  END LOOP;
END $$;

-- ---------------------------------------------------------------------------
-- 3. Hand each function to the role for its trust context.
--
-- Ownership is what the function executes as. The EXECUTE grants made in
-- earlier migrations decide who may CALL it and are deliberately unchanged:
-- this migration moves the identity a function runs AS, not the set of callers.
DO $$
DECLARE
  owners text[][] := ARRAY[
    ['dnb_def_auth','mt_auth_issue_code(text,text,interval)'],
    ['dnb_def_auth','mt_auth_verify_code(text,text)'],
    ['dnb_def_auth','mt_auth_create_session(uuid,uuid,text,interval)'],
    ['dnb_def_auth','mt_auth_resolve_token(text)'],
    ['dnb_def_auth','mt_auth_revoke_token(text)'],
    ['dnb_def_net', 'mt_voucher_redeem(text)'],
    ['dnb_def_net', 'mt_session_account(text,text,text,text,bigint,bigint,text,text,text)'],
    ['dnb_def_work','mt_intent_claim(text,interval,integer)'],
    ['dnb_def_work','mt_intent_expire_overdue()'],
    ['dnb_def_work','mt_sessions_reap(interval)'],
    ['dnb_def_work','mt_uplink_prune(interval)'],
    ['dnb_def_work','mt_uplink_record(uuid,bigint,bigint,integer)'],
    ['dnb_def_work','mt_devices_samplable()'],
    ['dnb_def_prov','mt_device_register(text,text,text,text,text,text)'],
    ['dnb_def_prov','mt_device_assign(uuid,uuid,uuid,text)'],
    ['dnb_def_prov','mt_device_set_state(uuid,text)'],
    ['dnb_def_prov','mt_device_set_secret(uuid,text,text)'],
    ['dnb_def_prov','mt_device_set_desired(uuid,jsonb)'],
    ['dnb_def_prov','mt_device_set_wan(uuid,text,text)']
  ];
  i int;
BEGIN
  FOR i IN 1 .. array_length(owners, 1) LOOP
    EXECUTE format('ALTER FUNCTION %s OWNER TO %I', owners[i][2], owners[i][1]);
  END LOOP;
END $$;

-- ---------------------------------------------------------------------------
-- 4. The onboarding path that did not exist.
--
-- A customer cannot be its own tenant context before it exists, so
-- mt_customers could not be inserted into under any context and the only way
-- to create one was to be a superuser. p_created_by is required for the same
-- reason mt_device_set_wan requires it: an onboarding with no recorded author
-- is not an administrative act, it is an anonymous write.
--
-- mt_customers gains an INSERT-ONLY policy for this role. The existing FOR ALL
-- isolation policy still governs SELECT, UPDATE and DELETE, so dnb_def_prov
-- can create a customer and still cannot read one.
-- Returns the id ALONE, and generates it rather than using RETURNING.
--
-- Both choices are privilege decisions, not style. `INSERT ... RETURNING`
-- requires SELECT on the returned columns, and under RLS it also requires a
-- SELECT policy — so a function that returned the row would need this role to
-- be able to READ mt_customers, i.e. to read every customer in the fleet, in
-- order to create one. Generating the key first means the INSERT privilege is
-- the only privilege involved. It also matches the rule the auth functions
-- already follow: a SECURITY DEFINER function hands back an id, never a row.
CREATE OR REPLACE FUNCTION mt_customer_create(p_name text, p_created_by text)
RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_id uuid := gen_random_uuid();
BEGIN
  IF p_name IS NULL OR btrim(p_name) = '' THEN
    RAISE EXCEPTION 'customer name is required' USING ERRCODE = 'check_violation';
  END IF;
  IF p_created_by IS NULL OR btrim(p_created_by) = '' THEN
    RAISE EXCEPTION 'customer creation requires the identity of whoever performed it'
      USING ERRCODE = 'check_violation';
  END IF;
  INSERT INTO mt_customers (id, name) VALUES (v_id, btrim(p_name));
  RETURN v_id;
END $$;

ALTER FUNCTION mt_customer_create(text,text) OWNER TO dnb_def_prov;
GRANT EXECUTE ON FUNCTION mt_customer_create(text,text) TO dnb_admin;

-- ---------------------------------------------------------------------------
-- 5. Re-assert the EXECUTE surface, as each function's new owner.
--
-- Two consequences of INHERIT FALSE, both discovered by the migration failing
-- rather than by reasoning about it:
--
--   * GRANT on a function requires ownership, and the owner has just given
--     these functions away. It must therefore SET ROLE to the new owner to
--     restate the grants — which it can, and which is exactly the boundary
--     working: acting as a definer role is now a deliberate act.
--   * the same applies to REVOKE, so mt_revoke_public_execute() is rebuilt
--     below to assume each function's owner before revoking PUBLIC off it.
--
-- The grants themselves are unchanged from 013/015/016; this restates them so
-- the file is readable as the whole EXECUTE surface rather than a delta.
DO $$
DECLARE
  g text[][] := ARRAY[
    ['dnb_def_auth','mt_auth_issue_code(text,text,interval)','dnb_app'],
    ['dnb_def_auth','mt_auth_verify_code(text,text)','dnb_app'],
    ['dnb_def_auth','mt_auth_create_session(uuid,uuid,text,interval)','dnb_app'],
    ['dnb_def_auth','mt_auth_resolve_token(text)','dnb_app'],
    ['dnb_def_auth','mt_auth_revoke_token(text)','dnb_app'],
    ['dnb_def_net', 'mt_voucher_redeem(text)','dnb_app'],
    ['dnb_def_net', 'mt_session_account(text,text,text,text,bigint,bigint,text,text,text)','dnb_app'],
    ['dnb_def_work','mt_intent_claim(text,interval,integer)','dnb_worker'],
    ['dnb_def_work','mt_intent_expire_overdue()','dnb_worker'],
    ['dnb_def_work','mt_sessions_reap(interval)','dnb_worker'],
    ['dnb_def_work','mt_uplink_prune(interval)','dnb_worker'],
    ['dnb_def_work','mt_uplink_record(uuid,bigint,bigint,integer)','dnb_worker'],
    ['dnb_def_work','mt_devices_samplable()','dnb_worker'],
    ['dnb_def_prov','mt_device_register(text,text,text,text,text,text)','dnb_admin'],
    ['dnb_def_prov','mt_device_assign(uuid,uuid,uuid,text)','dnb_admin'],
    ['dnb_def_prov','mt_device_set_state(uuid,text)','dnb_admin'],
    ['dnb_def_prov','mt_device_set_secret(uuid,text,text)','dnb_admin'],
    ['dnb_def_prov','mt_device_set_desired(uuid,jsonb)','dnb_admin'],
    ['dnb_def_prov','mt_device_set_wan(uuid,text,text)','dnb_admin'],
    ['dnb_def_prov','mt_customer_create(text,text)','dnb_admin']
  ];
  i int;
BEGIN
  FOR i IN 1 .. array_length(g, 1) LOOP
    EXECUTE format('SET LOCAL ROLE %I', g[i][1]);
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC', g[i][2]);
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO %I', g[i][2], g[i][3]);
  END LOOP;
  RESET ROLE;
END $$;

-- ---------------------------------------------------------------------------
-- 5b. The PUBLIC sweep has to be able to act as each function's owner.
--
-- REVOKE requires ownership, and nineteen of these functions are no longer the
-- database owner's. The sweep therefore assumes each function's owner in turn,
-- which means it can no longer be SECURITY DEFINER: PostgreSQL forbids SET
-- ROLE inside one.
--
-- SECURITY INVOKER is the more honest shape anyway. This is a maintenance
-- routine that migrations run as the database owner, not a privileged entry
-- point offered to the application. A caller without SET on the definer roles
-- simply cannot run it, which is the correct outcome rather than a hole.
CREATE OR REPLACE FUNCTION mt_revoke_public_execute() RETURNS integer
LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
DECLARE r record; n integer := 0;
BEGIN
  FOR r IN SELECT p.oid::regprocedure AS sig, p.proowner::regrole::text AS own
             FROM pg_proc p JOIN pg_namespace nsp ON nsp.oid = p.pronamespace
            WHERE nsp.nspname = 'public'
              AND p.proname LIKE 'mt\_%'
              AND (p.proacl IS NULL
                   OR EXISTS (SELECT 1 FROM aclexplode(p.proacl) a WHERE a.grantee = 0))
  LOOP
    -- proacl IS NULL means no explicit ACL, which IS the built-in default and
    -- therefore PUBLIC EXECUTE. aclexplode(NULL) returns no rows, so a check
    -- that only inspects proacl passes on a brand-new function (docs/57 §12.2).
    EXECUTE format('SET LOCAL ROLE %I', r.own);
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC', r.sig);
    n := n + 1;
  END LOOP;
  RESET ROLE;
  RETURN n;
END $$;

REVOKE ALL ON FUNCTION mt_revoke_public_execute() FROM PUBLIC;

-- ---------------------------------------------------------------------------
-- 6. Take back the CREATE privilege the ownership transfers needed.
--
-- PostgreSQL will not make a role the owner of an object in a schema it cannot
-- create in, so §1 granted CREATE transiently. These roles must not keep it: a
-- definer role that can CREATE FUNCTION could mint itself a new entry point
-- running with its own privileges. tests/test_definer_roles.php asserts by
-- execution that none of them can.
REVOKE CREATE ON SCHEMA public FROM dnb_def_auth, dnb_def_net, dnb_def_work, dnb_def_prov;

-- Migration 016's sweep: these functions changed owner and one is new, so
-- PUBLIC must be taken off them again. Calling it is how every migration that
-- touches a function ends (docs/57 §12.2).
SELECT mt_revoke_public_execute();
