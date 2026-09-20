-- Disposable F9 prototype. NOT the production schema; tables are pc_* so
-- nothing here can be mistaken for mt_*.
CREATE EXTENSION IF NOT EXISTS pgcrypto;
GRANT USAGE ON SCHEMA public TO proto_app, proto_worker, proto_admin, proto_def;

CREATE TABLE pc_customers (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), name text);
CREATE TABLE pc_sessions (
  token_hash text PRIMARY KEY,
  customer_id uuid NOT NULL REFERENCES pc_customers(id),
  expires_at timestamptz NOT NULL,
  revoked_at timestamptz);
CREATE TABLE pc_data (id bigserial PRIMARY KEY, customer_id uuid NOT NULL, secret_text text);
CREATE INDEX ON pc_data (customer_id);

-- A definer-only secret, for candidate F. proto_app has NO privilege on it.
CREATE TABLE pc_keys (k text PRIMARY KEY, v bytea);
INSERT INTO pc_keys VALUES ('ctx', gen_random_bytes(32));

GRANT SELECT, INSERT, UPDATE, DELETE ON pc_data, pc_customers TO proto_app, proto_worker, proto_admin;
GRANT SELECT ON pc_sessions TO proto_def;
GRANT SELECT ON pc_keys     TO proto_def;

-- ── candidate A: today's model — RLS trusts a GUC holding an IDENTIFIER ──
CREATE FUNCTION pc_current_a() RETURNS uuid LANGUAGE sql STABLE AS
$$ SELECT NULLIF(current_setting('app.customer_id', true), '')::uuid $$;

-- ── candidate B: RLS resolves a SECRET the caller must already hold ──
-- The GUC holds a session key, not an id. Establishing Q's context requires
-- a value that hashes to a live session row of Q's — which an attacker with
-- SQL as proto_app cannot read (RLS) and cannot compute (it is Q's bearer
-- token, and only its hash is stored).
--
-- Role-aware: the non-request actors have no session and legitimately work
-- across customers, so they keep the identifier-GUC path. proto_app cannot
-- become them (separate login, no membership), so this does not widen it.
CREATE FUNCTION pc_current_b() RETURNS uuid
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v uuid;
BEGIN
  IF current_user <> 'proto_app' THEN
    RETURN NULLIF(current_setting('app.customer_id', true), '')::uuid;
  END IF;
  SELECT s.customer_id INTO v FROM pc_sessions s
   WHERE s.token_hash = NULLIF(current_setting('app.session_key', true), '')
     AND s.revoked_at IS NULL AND s.expires_at > now();
  RETURN v;
END $$;
ALTER FUNCTION pc_current_b() OWNER TO proto_def;

-- ── candidate F: RLS verifies an HMAC the app computes over the id ──
-- The GUC holds customer_id + a MAC. Forging needs the key, which lives in a
-- table proto_app cannot read. No per-statement table lookup on the hot path
-- beyond fetching the key.
CREATE FUNCTION pc_current_f() RETURNS uuid
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE raw text; parts text[]; key bytea;
BEGIN
  IF current_user <> 'proto_app' THEN
    RETURN NULLIF(current_setting('app.customer_id', true), '')::uuid;
  END IF;
  raw := NULLIF(current_setting('app.customer_mac', true), '');
  IF raw IS NULL THEN RETURN NULL; END IF;
  parts := string_to_array(raw, '.');
  IF array_length(parts,1) <> 2 THEN RETURN NULL; END IF;
  SELECT v INTO key FROM pc_keys WHERE k = 'ctx';
  IF encode(hmac(parts[1], key, 'sha256'), 'hex') <> parts[2] THEN RETURN NULL; END IF;
  RETURN parts[1]::uuid;
END $$;
ALTER FUNCTION pc_current_f() OWNER TO proto_def;

GRANT EXECUTE ON FUNCTION pc_current_a(), pc_current_b(), pc_current_f()
  TO proto_app, proto_worker, proto_admin;

-- A counter, to measure how often a policy actually calls the function.
CREATE TABLE pc_calls (n bigint NOT NULL DEFAULT 0);
INSERT INTO pc_calls VALUES (0);
CREATE FUNCTION pc_current_b_counted() RETURNS uuid
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
  UPDATE pc_calls SET n = n + 1;
  RETURN pc_current_b();
END $$;
ALTER FUNCTION pc_current_b_counted() OWNER TO proto_def;
GRANT EXECUTE ON FUNCTION pc_current_b_counted() TO proto_app;
GRANT ALL ON pc_calls TO proto_def;
