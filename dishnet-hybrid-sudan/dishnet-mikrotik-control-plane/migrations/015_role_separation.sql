-- 015 — remediation of audit findings S1 and S2 (docs/57 §10)
--
-- ONE DATABASE IDENTITY WAS SERVING THREE EXECUTION CONTEXTS.
--
-- Requests, background jobs and provisioning all connected as dnb_app, so every
-- privilege any of them needed, all of them had. The only thing separating a
-- customer request from a worker was which PHP function the process happened to
-- call, and that is not an authorization boundary.
--
-- Proven before this migration, from inside customer P's tenant context:
--   * P read Q's sealed credential row and decrypted it        (S1)
--   * P claimed Q's intent, payload included                   (S2)
--   * P assigned Q's DEVICE to itself, then read the credential
--     entirely legitimately                                    (S1, two-step)
--
-- The third is why the device admin functions are in this migration: securing
-- mt_device_secrets alone would have converted a direct read into a two-step
-- read.
--
-- ENCRYPTION IS NOT TENANT ISOLATION. The AEAD scheme is unchanged and still
-- does its job — integrity, and binding an envelope to one device so it cannot
-- be moved between rows. What it never did was decide WHO MAY ASK. That is
-- authorization, and authorization is what follows.

-- ---------------------------------------------------------------------------
-- 1. Roles. Three, because three execution contexts genuinely exist.
--
-- DEPLOYMENT REQUIREMENT, not a suggestion. The passwords below are local
-- development literals, following the convention migration 001 set for dnb_app.
-- They are in a public repository, so on any installation the database listens
-- for beyond a local socket they are equivalent to no password at all — and a
-- role separation whose credentials are published is not a boundary. Before the
-- database accepts a non-local connection, every one of these roles must be
-- given a real password:
--
--   ALTER ROLE dnb_app    PASSWORD '<generated>';
--   ALTER ROLE dnb_worker PASSWORD '<generated>';
--   ALTER ROLE dnb_admin  PASSWORD '<generated>';
--
-- and the application handed them through DNB_APP_PASS / DNB_WORKER_PASS /
-- DNB_ADMIN_PASS. This is tracked as a deployment gate in docs/57 §11.4; it is
-- deliberately not automated here, because a migration that generates and
-- stores credentials would put them somewhere this file can reach.
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'dnb_worker') THEN
    CREATE ROLE dnb_worker LOGIN PASSWORD 'worker-local-dev'
      NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'dnb_admin') THEN
    CREATE ROLE dnb_admin LOGIN PASSWORD 'admin-local-dev'
      NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
  END IF;
END $$;

-- No role is a member of another, so none can SET ROLE into another. Each is
-- subject to RLS on every table; the difference between them is only which
-- SECURITY DEFINER functions they may call.
GRANT USAGE ON SCHEMA public TO dnb_worker, dnb_admin;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO dnb_worker, dnb_admin;
GRANT EXECUTE ON FUNCTION mt_current_customer() TO dnb_worker, dnb_admin;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO dnb_worker, dnb_admin;

-- Finding S5, taken while the grants are being rewritten: no application role
-- has any business deleting migration history.
REVOKE DELETE ON mt_migrations FROM dnb_app, dnb_worker, dnb_admin;
REVOKE TRUNCATE ON ALL TABLES IN SCHEMA public FROM dnb_worker, dnb_admin;

-- ---------------------------------------------------------------------------
-- 2. S1 — mt_device_secrets gets the isolation every other customer table has.
ALTER TABLE mt_device_secrets ADD COLUMN customer_id uuid REFERENCES mt_customers(id);
UPDATE mt_device_secrets s SET customer_id = d.customer_id
  FROM mt_devices d WHERE d.id = s.device_id;
-- Nullable on purpose: a device is staged, and may hold credentials, BEFORE it
-- is assigned to anyone. Such a row matches no customer under the policy below,
-- which is correct — unassigned stock belongs to nobody.
CREATE INDEX mt_device_secrets_customer_ix ON mt_device_secrets (customer_id);

ALTER TABLE mt_device_secrets ENABLE ROW LEVEL SECURITY;
ALTER TABLE mt_device_secrets FORCE  ROW LEVEL SECURITY;
CREATE POLICY mt_device_secrets_isolation ON mt_device_secrets
  USING (customer_id = mt_current_customer())
  WITH CHECK (customer_id = mt_current_customer());

-- S3, same cause, same shape, and required for the same reason: desired and
-- actual router configuration was cross-readable.
ALTER TABLE mt_device_config ADD COLUMN customer_id uuid REFERENCES mt_customers(id);
UPDATE mt_device_config c SET customer_id = d.customer_id
  FROM mt_devices d WHERE d.id = c.device_id;
CREATE INDEX mt_device_config_customer_ix ON mt_device_config (customer_id);
ALTER TABLE mt_device_config ENABLE ROW LEVEL SECURITY;
ALTER TABLE mt_device_config FORCE  ROW LEVEL SECURITY;
CREATE POLICY mt_device_config_isolation ON mt_device_config
  USING (customer_id = mt_current_customer())
  WITH CHECK (customer_id = mt_current_customer());

-- Assignment moves the dependent rows with the device, so ownership cannot
-- drift between a device and the secret that opens it.
CREATE OR REPLACE FUNCTION mt_device_assign(
  p_device uuid, p_customer uuid, p_site uuid, p_name text
) RETURNS mt_devices
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE r mt_devices;
BEGIN
  UPDATE mt_devices SET customer_id = p_customer, site_id = p_site,
                        name = p_name, claimed_at = now()
   WHERE id = p_device RETURNING * INTO r;
  UPDATE mt_device_secrets SET customer_id = p_customer WHERE device_id = p_device;
  UPDATE mt_device_config  SET customer_id = p_customer WHERE device_id = p_device;
  RETURN r;
END $$;

-- Writing a secret for a device that may still be unassigned: the policy above
-- would refuse it, and there is no customer context to give it.
CREATE OR REPLACE FUNCTION mt_device_set_secret(
  p_device uuid, p_username text, p_sealed text
) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_customer uuid;
BEGIN
  SELECT customer_id INTO v_customer FROM mt_devices WHERE id = p_device;
  IF NOT FOUND THEN RETURN false; END IF;
  INSERT INTO mt_device_secrets (device_id, customer_id, username, secret_sealed)
  VALUES (p_device, v_customer, p_username, p_sealed)
  ON CONFLICT (device_id) DO UPDATE
    SET username = EXCLUDED.username, secret_sealed = EXCLUDED.secret_sealed,
        customer_id = EXCLUDED.customer_id, rotated_at = now();
  RETURN true;
END $$;

-- Same, for desired configuration on a device that may be unassigned.
CREATE OR REPLACE FUNCTION mt_device_set_desired(p_device uuid, p_desired jsonb)
RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_customer uuid;
BEGIN
  SELECT customer_id INTO v_customer FROM mt_devices WHERE id = p_device;
  IF NOT FOUND THEN RETURN false; END IF;
  INSERT INTO mt_device_config (device_id, customer_id, desired, desired_at)
  VALUES (p_device, v_customer, p_desired, now())
  ON CONFLICT (device_id) DO UPDATE
    SET desired = EXCLUDED.desired, desired_at = now(), customer_id = EXCLUDED.customer_id;
  RETURN true;
END $$;

-- ---------------------------------------------------------------------------
-- 3. The sampler stops needing cross-customer access at all.
--
-- It now learns each device's owner with the work item and enters that tenant
-- context before reading anything — exactly what the intent worker already did.
-- Dropped rather than replaced: PostgreSQL will not let CREATE OR REPLACE
-- change a function's return type, and this one gains a column.
DROP FUNCTION IF EXISTS mt_devices_samplable();
CREATE FUNCTION mt_devices_samplable()
RETURNS TABLE (id uuid, tunnel_ip text, customer_id uuid)
LANGUAGE sql SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT d.id, d.tunnel_ip, d.customer_id
    FROM mt_devices d
   WHERE d.customer_id IS NOT NULL
     AND d.tunnel_ip IS NOT NULL
     AND d.state IN ('provisioned','active','diverged');
$$;

-- ---------------------------------------------------------------------------
-- 4. S2 — the request role loses every cross-customer primitive.
--
-- After this, arbitrary SQL executed as dnb_app cannot call the claim function,
-- cannot SET ROLE to a role that can (no membership), and cannot grant itself
-- the right (no CREATEROLE, not superuser). The boundary is a privilege the
-- request path does not hold, not a route nobody wrote.
-- PostgreSQL grants EXECUTE on a newly created function to PUBLIC. A revoke
-- naming only dnb_app therefore removes a grant the role never separately held,
-- and the privilege survives through PUBLIC. That is not a hypothetical: the
-- first draft of this migration did exactly that, and every "denied" assertion
-- in tests/test_isolation_s1_s2.php failed open until the revoke named PUBLIC.
--
-- Revoking from PUBLIC alone is also not enough, for the mirror-image reason:
-- earlier migrations granted several of these to dnb_app by name, and a revoke
-- aimed at PUBLIC leaves those explicit grants standing. Both grantees have to
-- go, so the sweep below strips EXECUTE from PUBLIC *and* from all three
-- application roles, and every privilege any of them holds afterwards appears
-- as a GRANT in this file. Read the grants below and you have read the whole
-- privilege surface; nothing is inherited from a migration written earlier.
--
-- The privilege is taken away across the schema and handed back by name.
--
-- This sweep covers the functions that exist WHEN IT RUNS. It cannot cover one
-- a later migration creates, and ALTER DEFAULT PRIVILEGES does not close that
-- gap: on PostgreSQL 16.13 a default-privileges REVOKE of the built-in PUBLIC
-- EXECUTE stores no catalogue row and changes nothing, and even after a row
-- that excludes PUBLIC is materialised by hand, a function created afterwards
-- still comes out with the built-in default. Measured in this cluster, not
-- assumed — docs/57 §12.2. An earlier draft of this file carried such a line
-- and it did nothing, which is the same failure mode as the revoke it was
-- meant to reinforce.
--
-- So migration 016 defines mt_revoke_public_execute(), which every migration
-- that adds a function calls at its end, and tests/test_isolation_s1_s2.php
-- goes red if one forgets.
DO $$
DECLARE f regprocedure;
BEGIN
    FOR f IN SELECT p.oid::regprocedure
               FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
              WHERE n.nspname = 'public' AND p.proname LIKE 'mt\_%'
    LOOP
        EXECUTE format(
            'REVOKE ALL ON FUNCTION %s FROM PUBLIC, dnb_app, dnb_worker, dnb_admin', f);
    END LOOP;
END $$;

-- The request path keeps exactly what serving a request needs: login (these
-- return ids only), voucher redemption and RADIUS accounting (network-side
-- entry points that resolve identity from what they are handed), and the RLS
-- predicate itself, which every role must be able to evaluate.
GRANT EXECUTE ON FUNCTION mt_current_customer()                           TO dnb_app, dnb_worker, dnb_admin;
GRANT EXECUTE ON FUNCTION mt_auth_issue_code(text,text,interval)          TO dnb_app;
GRANT EXECUTE ON FUNCTION mt_auth_verify_code(text,text)                  TO dnb_app;
GRANT EXECUTE ON FUNCTION mt_auth_create_session(uuid,uuid,text,interval) TO dnb_app;
GRANT EXECUTE ON FUNCTION mt_auth_resolve_token(text)                     TO dnb_app;
GRANT EXECUTE ON FUNCTION mt_auth_revoke_token(text)                      TO dnb_app;
GRANT EXECUTE ON FUNCTION mt_voucher_redeem(text)                         TO dnb_app;
GRANT EXECUTE ON FUNCTION
      mt_session_account(text,text,text,text,bigint,bigint,text,text,text) TO dnb_app;

-- The cross-customer primitives. dnb_app is not named on any line below, and
-- after the sweep above that is sufficient: there is no PUBLIC path to them.
GRANT EXECUTE ON FUNCTION mt_intent_claim(text,interval,integer)          TO dnb_worker;
GRANT EXECUTE ON FUNCTION mt_intent_expire_overdue()                      TO dnb_worker;
GRANT EXECUTE ON FUNCTION mt_sessions_reap(interval)                      TO dnb_worker;
GRANT EXECUTE ON FUNCTION mt_uplink_prune(interval)                       TO dnb_worker;
GRANT EXECUTE ON FUNCTION mt_uplink_record(uuid,bigint,bigint,integer)    TO dnb_worker;
GRANT EXECUTE ON FUNCTION mt_devices_samplable()                          TO dnb_worker;

-- A4 — device theft. Without these, S1's fix only lengthens the attack by one
-- step: reassign the device to yourself, then read its secret legitimately.
GRANT EXECUTE ON FUNCTION mt_device_register(text,text,text,text,text,text) TO dnb_admin;
GRANT EXECUTE ON FUNCTION mt_device_assign(uuid,uuid,uuid,text)             TO dnb_admin;
GRANT EXECUTE ON FUNCTION mt_device_set_state(uuid,text)                    TO dnb_admin;
GRANT EXECUTE ON FUNCTION mt_device_set_secret(uuid,text,text)              TO dnb_admin;
GRANT EXECUTE ON FUNCTION mt_device_set_desired(uuid,jsonb)                 TO dnb_admin;

-- Unchanged and deliberately still on dnb_app: every mt_auth_* function (login
-- is a request-path operation and they return ids only), mt_voucher_redeem and
-- mt_session_account (network-side entry points that resolve identity from what
-- is presented). docs/57 §10.6 records the residual on the latter.
