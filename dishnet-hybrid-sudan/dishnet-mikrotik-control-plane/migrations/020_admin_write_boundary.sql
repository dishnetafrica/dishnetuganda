-- 020 — the Admin WRITE security foundation (docs/84 → W-1 / W-2 / W-3)
--
-- Three fixes, in the order they have to hold:
--
--   W-1  every state-changing provisioning function writes its own audit row,
--        in the same transaction, from an actor it is handed rather than one
--        the caller can manufacture.
--   W-2  a device may not be paired with another customer's site — enforced by
--        a constraint, so it binds every writer and not only the callers who
--        choose to go through the function.
--   W-3  a dedicated Admin write identity holding no table DML at all.
--
-- This migration binds no Admin write route. It builds the floor those routes
-- will later stand on. Development and test schema only; production is
-- untouched and the census (docs/79) still gates any production change.

-- ===========================================================================
-- W-1 (a). The audit writer
-- ===========================================================================
--
-- mt_audit_log has RLS + FORCE and a tenant policy, so a function owned by
-- dnb_def_prov cannot insert into it at all. The tempting alternative — let
-- each caller write the row afterwards — is precisely the defect docs/84 F-1
-- records: audit that lives in one route file is audit that a second caller
-- silently skips.
--
-- So: one narrow writer, owned by its own NOLOGIN role, callable ONLY by the
-- definer owner roles. dnb_app, dnb_admin and dnb_adminwrite cannot call it,
-- so no HTTP caller can post an audit row for an act that never happened.
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'dnb_def_audit') THEN
    CREATE ROLE dnb_def_audit NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE
                              NOINHERIT NOBYPASSRLS;
  END IF;
  -- Ownership transfer needs membership; INHERIT FALSE keeps the migration
  -- owner from picking up this role's INSERT policy by inheritance (017 §1).
  EXECUTE format('GRANT dnb_def_audit TO %I WITH INHERIT FALSE, SET TRUE', current_user);
END $$;

GRANT USAGE ON SCHEMA public TO dnb_def_audit;
-- CREATE transiently, revoked at the end of this file, exactly as 017 does.
GRANT CREATE ON SCHEMA public TO dnb_def_audit;
GRANT INSERT ON mt_audit_log TO dnb_def_audit;

-- INSERT only. No SELECT: a bug in the writer must not become a way to read
-- the whole estate's history, and the table's own triggers already forbid
-- UPDATE, DELETE and TRUNCATE.
DROP POLICY IF EXISTS dnb_def_audit_mt_audit_log_insert ON mt_audit_log;
CREATE POLICY dnb_def_audit_mt_audit_log_insert ON mt_audit_log
  FOR INSERT TO dnb_def_audit WITH CHECK (true);

SET LOCAL ROLE dnb_def_audit;

CREATE OR REPLACE FUNCTION mt_audit_write(
  p_customer uuid, p_actor text, p_actor_kind text, p_action text,
  p_target_type text, p_target_id text, p_source text, p_detail jsonb
) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
  -- An unattributable act is not auditable, so it is refused. This is the line
  -- that stops a later caller passing NULL "for now".
  IF p_actor IS NULL OR btrim(p_actor) = '' THEN
    RAISE EXCEPTION 'an audited action requires the identity of whoever performed it'
      USING ERRCODE = 'check_violation';
  END IF;
  IF p_actor_kind IS NULL OR p_actor_kind NOT IN ('principal','staff','system') THEN
    RAISE EXCEPTION 'unknown actor kind: %', coalesce(p_actor_kind, '(null)')
      USING ERRCODE = 'check_violation';
  END IF;
  INSERT INTO mt_audit_log (customer_id, actor, actor_kind, action,
                            target_type, target_id, source, detail)
  VALUES (p_customer, btrim(p_actor), p_actor_kind, p_action,
          p_target_type, p_target_id, p_source, coalesce(p_detail, '{}'::jsonb));
END $$;

REVOKE ALL ON FUNCTION mt_audit_write(uuid,text,text,text,text,text,text,jsonb) FROM PUBLIC;
-- Deliberately NOT dnb_app, dnb_admin or dnb_adminwrite. A caller may cause an
-- audit row by performing an audited act; it may not write one directly.
GRANT EXECUTE ON FUNCTION mt_audit_write(uuid,text,text,text,text,text,text,jsonb)
  TO dnb_def_prov;

RESET ROLE;

-- ===========================================================================
-- W-1 (b). The seven functions, each auditing itself
-- ===========================================================================
--
-- Four gain an explicit p_actor; three already carried one (p_staged_by, p_by,
-- p_created_by) and now use it for the audit row as well.
--
-- The actor is a PARAMETER supplied by the Admin identity boundary. It is
-- never read from a session setting: a GUC is something an HTTP caller can
-- set, which is the forgery this exists to prevent.
--
-- Recreated under SET LOCAL ROLE because they are already owned by
-- dnb_def_prov, and CREATE OR REPLACE requires ownership.
GRANT CREATE ON SCHEMA public TO dnb_def_prov;
SET LOCAL ROLE dnb_def_prov;

CREATE OR REPLACE FUNCTION mt_device_register(
  p_serial text, p_model text, p_ros text,
  p_wg_pubkey text, p_tunnel_ip text, p_staged_by text
) RETURNS mt_devices
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE r mt_devices; staged boolean := p_staged_by IS NOT NULL AND p_staged_by <> '';
BEGIN
  INSERT INTO mt_devices (serial, model, ros_version, wg_pubkey, tunnel_ip,
                          staged_by, staged_at, state)
  VALUES (p_serial, p_model, p_ros, p_wg_pubkey, p_tunnel_ip, p_staged_by,
          CASE WHEN staged THEN now() END,
          CASE WHEN staged THEN 'staged' ELSE 'registered' END)
  RETURNING * INTO r;
  -- A device enters the estate owned by nobody, so customer_id is NULL here.
  -- wg_pubkey and tunnel_ip are never placed in the detail.
  PERFORM mt_audit_write(NULL, coalesce(nullif(btrim(p_staged_by), ''), 'unattributed'),
    'staff', 'device.registered', 'device', r.id::text, 'admin',
    jsonb_build_object('serial', p_serial, 'model', p_model, 'state', r.state));
  RETURN r;
END $$;

CREATE OR REPLACE FUNCTION mt_device_assign(
  p_device uuid, p_customer uuid, p_site uuid, p_name text, p_actor text
) RETURNS mt_devices
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE r mt_devices; was_customer uuid; was_site uuid;
BEGIN
  SELECT d.customer_id, d.site_id INTO was_customer, was_site
    FROM mt_devices d WHERE d.id = p_device;
  -- The customer/site invariant is NOT checked here. It is a constraint (W-2),
  -- so it binds the direct-UPDATE path too. A violation therefore raises from
  -- the constraint and rolls this function's audit row back with the mutation,
  -- which is the behaviour W-1 requires.
  UPDATE mt_devices SET customer_id = p_customer, site_id = p_site,
                        name = p_name, claimed_at = now()
   WHERE id = p_device RETURNING * INTO r;
  IF NOT FOUND THEN RETURN NULL; END IF;
  UPDATE mt_device_secrets SET customer_id = p_customer WHERE device_id = p_device;
  UPDATE mt_device_config  SET customer_id = p_customer WHERE device_id = p_device;
  PERFORM mt_audit_write(p_customer, p_actor, 'staff', 'device.assigned',
    'device', p_device::text, 'admin',
    jsonb_build_object('from_customer', was_customer, 'to_customer', p_customer,
                       'from_site', was_site, 'to_site', p_site));
  RETURN r;
END $$;

CREATE OR REPLACE FUNCTION mt_device_set_state(p_device uuid, p_state text, p_actor text)
RETURNS mt_devices
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE r mt_devices; was_state text;
BEGIN
  SELECT d.state INTO was_state FROM mt_devices d WHERE d.id = p_device;
  UPDATE mt_devices SET state = p_state WHERE id = p_device RETURNING * INTO r;
  IF NOT FOUND THEN RETURN NULL; END IF;
  PERFORM mt_audit_write(r.customer_id, p_actor, 'staff', 'device.state_changed',
    'device', p_device::text, 'admin',
    jsonb_build_object('from', was_state, 'to', p_state));
  RETURN r;
END $$;

CREATE OR REPLACE FUNCTION mt_device_set_secret(
  p_device uuid, p_username text, p_sealed text, p_actor text
) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_customer uuid;
BEGIN
  SELECT d.customer_id INTO v_customer FROM mt_devices d WHERE d.id = p_device;
  IF NOT FOUND THEN RETURN false; END IF;
  INSERT INTO mt_device_secrets (device_id, customer_id, username, secret_sealed)
  VALUES (p_device, v_customer, p_username, p_sealed)
  ON CONFLICT (device_id) DO UPDATE
    SET username = EXCLUDED.username, secret_sealed = EXCLUDED.secret_sealed,
        customer_id = EXCLUDED.customer_id, rotated_at = now();
  -- The sealed value never reaches the audit detail. That the credential was
  -- rotated is the auditable fact; what it became is not.
  PERFORM mt_audit_write(v_customer, p_actor, 'staff', 'device.secret_rotated',
    'device', p_device::text, 'admin', jsonb_build_object('username', p_username));
  RETURN true;
END $$;

CREATE OR REPLACE FUNCTION mt_device_set_desired(p_device uuid, p_desired jsonb, p_actor text)
RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_customer uuid;
BEGIN
  SELECT d.customer_id INTO v_customer FROM mt_devices d WHERE d.id = p_device;
  IF NOT FOUND THEN RETURN false; END IF;
  INSERT INTO mt_device_config (device_id, customer_id, desired, desired_at)
  VALUES (p_device, v_customer, p_desired, now())
  ON CONFLICT (device_id) DO UPDATE
    SET desired = EXCLUDED.desired, desired_at = now(), customer_id = EXCLUDED.customer_id;
  -- The desired document itself is not copied into the detail: it is free-form,
  -- and D-2 forbids putting into audit what no Admin screen may read back.
  PERFORM mt_audit_write(v_customer, p_actor, 'staff', 'device.desired_set',
    'device', p_device::text, 'admin', '{}'::jsonb);
  RETURN true;
END $$;

CREATE OR REPLACE FUNCTION mt_device_set_wan(p_device uuid, p_interface text, p_by text)
RETURNS mt_devices
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE r mt_devices;
BEGIN
  IF p_interface IS NULL OR btrim(p_interface) = '' THEN
    RAISE EXCEPTION 'wan interface must be a non-empty name established at staging'
      USING ERRCODE = 'check_violation';
  END IF;
  IF p_by IS NULL OR btrim(p_by) = '' THEN
    RAISE EXCEPTION 'wan interface requires the identity of whoever established it'
      USING ERRCODE = 'check_violation';
  END IF;

  UPDATE mt_devices
     SET wan_interface        = btrim(p_interface),
         wan_interface_set_by = btrim(p_by),
         wan_interface_set_at = now()
   WHERE id = p_device
  RETURNING * INTO r;
  IF NOT FOUND THEN RETURN NULL; END IF;
  PERFORM mt_audit_write(r.customer_id, p_by, 'staff', 'device.wan_established',
    'device', p_device::text, 'admin',
    jsonb_build_object('interface', btrim(p_interface)));
  RETURN r;
END $$;

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
  PERFORM mt_audit_write(v_id, p_created_by, 'staff', 'customer.created',
    'customer', v_id::text, 'admin', jsonb_build_object('name', btrim(p_name)));
  RETURN v_id;
END $$;

-- The old signatures must not survive. Leaving them callable would leave an
-- unaudited path to exactly the same mutation, which is worse than no fix:
-- it would look fixed.
DROP FUNCTION IF EXISTS mt_device_assign(uuid,uuid,uuid,text);
DROP FUNCTION IF EXISTS mt_device_set_state(uuid,text);
DROP FUNCTION IF EXISTS mt_device_set_secret(uuid,text,text);
DROP FUNCTION IF EXISTS mt_device_set_desired(uuid,jsonb);

RESET ROLE;

-- ===========================================================================
-- W-2. The customer/site invariant, as a constraint
-- ===========================================================================
--
--   device.site_id IS NULL  OR  device.customer_id = that site's customer_id
--
-- A constraint rather than a check inside mt_device_assign, because the
-- measured defect was never that the function was wrong: dnb_app bypassed the
-- function entirely with a direct UPDATE (docs/73 §1.1). A constraint binds
-- every writer, including ones nobody has written yet.
--
-- NO BACKFILL. Existing rows are not touched and no ownership is inferred;
-- the production census (docs/79) must decide how real violations are treated.
-- These are added VALID because the development and test schema has zero
-- violations (docs/74 §2). On a database that has any, this migration must be
-- preceded by that decision.
ALTER TABLE mt_sites DROP CONSTRAINT IF EXISTS mt_sites_id_customer_key;
ALTER TABLE mt_sites ADD CONSTRAINT mt_sites_id_customer_key UNIQUE (id, customer_id);

ALTER TABLE mt_devices DROP CONSTRAINT IF EXISTS mt_devices_site_customer_fkey;
ALTER TABLE mt_devices ADD CONSTRAINT mt_devices_site_customer_fkey
  FOREIGN KEY (site_id, customer_id) REFERENCES mt_sites (id, customer_id);

-- MATCH SIMPLE skips the check when any column is NULL, so (site set, customer
-- NULL) would slip past it — and would also escape the existing single-column
-- FK's meaning. The CHECK closes that. MATCH FULL would close it too, but it
-- breaks site deletion, which docs/73 M6 measured.
ALTER TABLE mt_devices DROP CONSTRAINT IF EXISTS mt_devices_site_needs_customer;
ALTER TABLE mt_devices ADD CONSTRAINT mt_devices_site_needs_customer
  CHECK (site_id IS NULL OR customer_id IS NOT NULL);

-- ===========================================================================
-- W-3. The dedicated Admin write identity
-- ===========================================================================
--
-- dnb_admin already holds INSERT/UPDATE/DELETE on every table (migration 015
-- line 60). docs/84 F-3 records why those grants are not revoked here: that
-- needs its own dependency audit. The Admin API therefore gets a new identity
-- rather than a trimmed old one.
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'dnb_adminwrite') THEN
    -- No PASSWORD clause, deliberately (docs/97). A migration is
    -- source-controlled and replayed identically everywhere; a credential
    -- must be neither. rolpassword stays NULL, which under scram-sha-256
    -- means this role cannot authenticate at all until the installer sets
    -- a credential. Privileges are declared here; credentials are not.
    CREATE ROLE dnb_adminwrite LOGIN
                               NOSUPERUSER NOCREATEDB NOCREATEROLE
                               NOINHERIT NOBYPASSRLS;
  END IF;
END $$;
GRANT USAGE ON SCHEMA public TO dnb_adminwrite;
-- Deliberately NOT granted: any privilege on any table, now or by default.

SET LOCAL ROLE dnb_def_prov;
DO $$
DECLARE f text;
BEGIN
  FOREACH f IN ARRAY ARRAY[
    'mt_device_register(text,text,text,text,text,text)',
    'mt_device_assign(uuid,uuid,uuid,text,text)',
    'mt_device_set_state(uuid,text,text)',
    'mt_device_set_secret(uuid,text,text,text)',
    'mt_device_set_desired(uuid,jsonb,text)',
    'mt_device_set_wan(uuid,text,text)',
    'mt_customer_create(text,text)'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC', f);
    -- dnb_admin keeps the EXECUTE it already held; dnb_adminwrite is added.
    EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO dnb_admin, dnb_adminwrite', f);
  END LOOP;
END $$;
RESET ROLE;

-- ===========================================================================
-- Prove this migration's own effects
-- ===========================================================================
--
-- A non-owner GRANT is answered with a warning, not an error, so an inert
-- grant is this project's most repeated defect. Every claim below is checked
-- by execution before the migration is allowed to succeed.
DO $$
DECLARE f text; missing text[] := '{}'; leaked text[] := '{}';
BEGIN
  FOREACH f IN ARRAY ARRAY[
    'mt_device_register(text,text,text,text,text,text)',
    'mt_device_assign(uuid,uuid,uuid,text,text)',
    'mt_device_set_state(uuid,text,text)',
    'mt_device_set_secret(uuid,text,text,text)',
    'mt_device_set_desired(uuid,jsonb,text)',
    'mt_device_set_wan(uuid,text,text)',
    'mt_customer_create(text,text)'] LOOP
    IF NOT has_function_privilege('dnb_adminwrite', f, 'EXECUTE') THEN
      missing := missing || f;
    END IF;
    IF has_function_privilege('dnb_app', f, 'EXECUTE') THEN
      leaked := leaked || f;
    END IF;
  END LOOP;
  IF array_length(missing, 1) IS NOT NULL THEN
    RAISE EXCEPTION 'dnb_adminwrite did not receive EXECUTE on: %',
      array_to_string(missing, ', ');
  END IF;
  IF array_length(leaked, 1) IS NOT NULL THEN
    RAISE EXCEPTION 'dnb_app can still execute provisioning functions: %',
      array_to_string(leaked, ', ');
  END IF;

  IF NOT has_function_privilege('dnb_def_prov',
        'mt_audit_write(uuid,text,text,text,text,text,text,jsonb)', 'EXECUTE') THEN
    RAISE EXCEPTION 'dnb_def_prov cannot write audit rows — W-1 would be inert';
  END IF;
  FOREACH f IN ARRAY ARRAY['dnb_app','dnb_admin','dnb_adminwrite','dnb_adminapi','dnb_worker'] LOOP
    IF has_function_privilege(f,
         'mt_audit_write(uuid,text,text,text,text,text,text,jsonb)', 'EXECUTE') THEN
      RAISE EXCEPTION '% can forge audit rows directly', f;
    END IF;
  END LOOP;

  -- W-3's defining property: no table DML anywhere, now or by default.
  IF EXISTS (
    SELECT 1 FROM information_schema.role_table_grants
     WHERE grantee = 'dnb_adminwrite'
       AND privilege_type IN ('INSERT','UPDATE','DELETE','SELECT','TRUNCATE')
  ) THEN
    RAISE EXCEPTION 'dnb_adminwrite holds a table privilege; it must hold none';
  END IF;
  IF EXISTS (SELECT 1 FROM pg_roles
              WHERE rolname IN ('dnb_adminwrite','dnb_def_audit')
                AND (rolsuper OR rolbypassrls OR rolcreaterole OR rolcreatedb)) THEN
    RAISE EXCEPTION 'a new role carries superuser, BYPASSRLS, CREATEROLE or CREATEDB';
  END IF;

  -- W-2 must be VALID, not merely present. An ADD FOREIGN KEY can report
  -- convalidated under FORCE RLS while having validated nothing (019's lesson).
  IF NOT EXISTS (SELECT 1 FROM pg_constraint
                  WHERE conname = 'mt_devices_site_customer_fkey' AND convalidated) THEN
    RAISE EXCEPTION 'the customer/site foreign key is missing or not validated';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint
                  WHERE conname = 'mt_devices_site_needs_customer' AND convalidated) THEN
    RAISE EXCEPTION 'the site-needs-customer check is missing or not validated';
  END IF;

  -- The unaudited signatures must be gone, not merely superseded.
  IF EXISTS (SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
              WHERE n.nspname = 'public'
                AND p.oid::regprocedure::text IN (
                  'mt_device_assign(uuid,uuid,uuid,text)',
                  'mt_device_set_state(uuid,text)',
                  'mt_device_set_secret(uuid,text,text)',
                  'mt_device_set_desired(uuid,jsonb)')) THEN
    RAISE EXCEPTION 'an unaudited provisioning signature survived';
  END IF;
END $$;

-- Take back the CREATE the ownership work needed. A definer role that can
-- CREATE FUNCTION could mint itself a new entry point running as itself.
REVOKE CREATE ON SCHEMA public FROM dnb_def_prov, dnb_def_audit;
SELECT mt_revoke_public_execute();
