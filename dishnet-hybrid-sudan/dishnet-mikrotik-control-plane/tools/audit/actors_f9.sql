-- Disposable four-actor F9 prototype. pc_* tables, proto_* roles. NOT production.
--
-- Design rule: one policy PER ROLE, each naming its own resolver. There is no
-- universal customer context and no role branch inside any resolver — which is
-- what removes the current_user/session_user hazard that made the previous
-- prototype silently do nothing.
CREATE EXTENSION IF NOT EXISTS pgcrypto;
GRANT USAGE ON SCHEMA public TO proto_app, proto_admin, proto_worker, proto_radius, proto_def;

CREATE TABLE pc_customers (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), name text UNIQUE);
CREATE TABLE pc_data (id bigserial PRIMARY KEY, customer_id uuid NOT NULL, secret_text text);

-- CUSTOMER authority: a live session credential.
CREATE TABLE pc_sessions (token_hash text PRIMARY KEY, customer_id uuid NOT NULL,
                          expires_at timestamptz NOT NULL, revoked_at timestamptz);

-- ADMIN authority: an admin principal, an admin session, and a per-operation
-- capability that the SERVER mints. An admin never holds a customer credential.
CREATE TABLE pc_admins (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), name text UNIQUE, active bool NOT NULL DEFAULT true);
CREATE TABLE pc_admin_sessions (token_hash text PRIMARY KEY, admin_id uuid NOT NULL REFERENCES pc_admins(id),
                                expires_at timestamptz NOT NULL, revoked_at timestamptz);
CREATE TABLE pc_admin_perms (admin_id uuid NOT NULL REFERENCES pc_admins(id), operation text NOT NULL,
                             customer_id uuid, PRIMARY KEY (admin_id, operation, customer_id));
CREATE TABLE pc_admin_caps (cap_hash text PRIMARY KEY, admin_id uuid NOT NULL, customer_id uuid NOT NULL,
                            operation text NOT NULL, expires_at timestamptz NOT NULL,
                            consumed_at timestamptz, issued_at timestamptz NOT NULL DEFAULT now());

-- WORKER authority: the intent it actually claimed. Never a supplied uuid.
CREATE TABLE pc_intents (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), customer_id uuid NOT NULL,
                         kind text NOT NULL, state text NOT NULL DEFAULT 'queued',
                         lease_hash text, lease_expires_at timestamptz, claimed_by text);

-- RADIUS authority: a hotspot identity. No table access at all.
CREATE TABLE pc_hotspot (username text PRIMARY KEY, customer_id uuid NOT NULL);
CREATE TABLE pc_usage (id bigserial PRIMARY KEY, customer_id uuid NOT NULL, username text, bytes bigint);

-- ── resolvers, one per actor ────────────────────────────────────────────────
CREATE FUNCTION pc_customer_ctx() RETURNS uuid
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT s.customer_id FROM pc_sessions s
   WHERE s.token_hash = NULLIF(current_setting('app.session_key', true), '')
     AND s.revoked_at IS NULL AND s.expires_at > now();
$$;

CREATE FUNCTION pc_admin_scope() RETURNS uuid
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT c.customer_id FROM pc_admin_caps c
   WHERE c.cap_hash = encode(digest(NULLIF(current_setting('app.admin_cap', true), ''), 'sha256'), 'hex')
     AND c.consumed_at IS NULL AND c.expires_at > now();
$$;

CREATE FUNCTION pc_worker_scope() RETURNS uuid
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT i.customer_id FROM pc_intents i
   WHERE i.lease_hash = encode(digest(NULLIF(current_setting('app.intent_lease', true), ''), 'sha256'), 'hex')
     AND i.state = 'claimed' AND i.lease_expires_at > now();
$$;

-- ── the ONLY way an admin capability comes into existence ────────────────────
-- Minted server-side, against a live admin session and an explicit permission.
-- proto_admin has no INSERT on pc_admin_caps, so an attacker holding SQL as
-- that role cannot mint one for a customer it is not authorised for.
CREATE FUNCTION pc_admin_open(p_admin_token text, p_customer uuid, p_operation text)
RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_admin uuid; v_cap text;
BEGIN
  SELECT a.admin_id INTO v_admin FROM pc_admin_sessions a
    JOIN pc_admins ad ON ad.id = a.admin_id AND ad.active
   WHERE a.token_hash = p_admin_token AND a.revoked_at IS NULL AND a.expires_at > now();
  IF v_admin IS NULL THEN RAISE EXCEPTION 'no live admin session'; END IF;

  IF NOT EXISTS (SELECT 1 FROM pc_admin_perms p
                  WHERE p.admin_id = v_admin AND p.operation = p_operation
                    AND (p.customer_id IS NULL OR p.customer_id = p_customer)) THEN
    RAISE EXCEPTION 'admin % not authorised for % on %', v_admin, p_operation, p_customer;
  END IF;

  v_cap := encode(gen_random_bytes(32), 'hex');
  INSERT INTO pc_admin_caps (cap_hash, admin_id, customer_id, operation, expires_at)
  VALUES (encode(digest(v_cap,'sha256'),'hex'), v_admin, p_customer, p_operation, now() + interval '2 minutes');
  RETURN v_cap;   -- returned ONCE; only its hash is stored
END $$;

-- ── the ONLY way a worker gains a customer ──────────────────────────────────
CREATE FUNCTION pc_intent_claim(p_worker text)
RETURNS TABLE (intent_id uuid, lease text)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_id uuid; v_lease text := encode(gen_random_bytes(32),'hex');
BEGIN
  SELECT i.id INTO v_id FROM pc_intents i WHERE i.state = 'queued'
   ORDER BY i.id FOR UPDATE SKIP LOCKED LIMIT 1;
  IF v_id IS NULL THEN RETURN; END IF;
  UPDATE pc_intents SET state='claimed', claimed_by=p_worker,
         lease_hash = encode(digest(v_lease,'sha256'),'hex'),
         lease_expires_at = now() + interval '2 minutes'
   WHERE id = v_id;
  RETURN QUERY SELECT v_id, v_lease;
END $$;

-- ── RADIUS ingestion: derives the customer, holds nothing else ──────────────
CREATE FUNCTION pc_radius_ingest(p_username text, p_bytes bigint)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_c uuid; v_id bigint;
BEGIN
  SELECT h.customer_id INTO v_c FROM pc_hotspot h WHERE h.username = p_username;
  IF v_c IS NULL THEN RETURN NULL; END IF;
  INSERT INTO pc_usage (customer_id, username, bytes) VALUES (v_c, p_username, p_bytes) RETURNING id INTO v_id;
  RETURN v_c;
END $$;

ALTER FUNCTION pc_customer_ctx()  OWNER TO proto_def;
ALTER FUNCTION pc_admin_scope()   OWNER TO proto_def;
ALTER FUNCTION pc_worker_scope()  OWNER TO proto_def;
ALTER FUNCTION pc_admin_open(text,uuid,text) OWNER TO proto_def;
ALTER FUNCTION pc_intent_claim(text)         OWNER TO proto_def;
ALTER FUNCTION pc_radius_ingest(text,bigint) OWNER TO proto_def;

-- ── privileges: each actor gets only what its authority needs ───────────────
GRANT SELECT, INSERT, UPDATE, DELETE ON pc_data TO proto_app, proto_admin, proto_worker;
GRANT USAGE, SELECT ON SEQUENCE pc_data_id_seq TO proto_app, proto_admin, proto_worker;
GRANT SELECT ON pc_customers TO proto_app, proto_admin, proto_worker;
REVOKE ALL ON pc_data, pc_customers, pc_sessions, pc_admin_caps, pc_intents, pc_usage FROM proto_radius;

GRANT EXECUTE ON FUNCTION pc_customer_ctx() TO proto_app;
GRANT EXECUTE ON FUNCTION pc_admin_scope()  TO proto_admin;
GRANT EXECUTE ON FUNCTION pc_worker_scope() TO proto_worker;
GRANT EXECUTE ON FUNCTION pc_admin_open(text,uuid,text) TO proto_admin;
GRANT EXECUTE ON FUNCTION pc_intent_claim(text)         TO proto_worker;
GRANT EXECUTE ON FUNCTION pc_radius_ingest(text,bigint) TO proto_radius;

-- the resolvers read these; nobody else may
GRANT SELECT ON pc_sessions, pc_admin_sessions, pc_admins, pc_admin_perms, pc_admin_caps, pc_intents, pc_hotspot TO proto_def;
GRANT INSERT, UPDATE ON pc_admin_caps TO proto_def;
GRANT UPDATE ON pc_intents TO proto_def;
GRANT INSERT ON pc_usage TO proto_def;
GRANT USAGE, SELECT ON SEQUENCE pc_usage_id_seq TO proto_def;

-- ── RLS: one policy per actor role, each with its OWN authority ─────────────
ALTER TABLE pc_data ENABLE ROW LEVEL SECURITY;  ALTER TABLE pc_data FORCE ROW LEVEL SECURITY;
CREATE POLICY d_customer ON pc_data FOR ALL TO proto_app
  USING (customer_id = pc_customer_ctx()) WITH CHECK (customer_id = pc_customer_ctx());
CREATE POLICY d_admin ON pc_data FOR ALL TO proto_admin
  USING (customer_id = pc_admin_scope())  WITH CHECK (customer_id = pc_admin_scope());
CREATE POLICY d_worker ON pc_data FOR ALL TO proto_worker
  USING (customer_id = pc_worker_scope()) WITH CHECK (customer_id = pc_worker_scope());

-- Recursion break: the tables the resolvers read are exempt for the DEFINER
-- role only, and their tenant policies name the other roles explicitly. The
-- definer policy calls no resolver, so nothing can recurse.
ALTER TABLE pc_sessions ENABLE ROW LEVEL SECURITY; ALTER TABLE pc_sessions FORCE ROW LEVEL SECURITY;
CREATE POLICY s_def ON pc_sessions FOR SELECT TO proto_def USING (true);
CREATE POLICY s_tenant ON pc_sessions FOR ALL TO proto_app, proto_admin, proto_worker
  USING (customer_id = pc_customer_ctx());

ALTER TABLE pc_admin_caps ENABLE ROW LEVEL SECURITY; ALTER TABLE pc_admin_caps FORCE ROW LEVEL SECURITY;
CREATE POLICY c_def ON pc_admin_caps FOR ALL TO proto_def USING (true) WITH CHECK (true);

ALTER TABLE pc_intents ENABLE ROW LEVEL SECURITY; ALTER TABLE pc_intents FORCE ROW LEVEL SECURITY;
CREATE POLICY i_def ON pc_intents FOR ALL TO proto_def USING (true) WITH CHECK (true);
