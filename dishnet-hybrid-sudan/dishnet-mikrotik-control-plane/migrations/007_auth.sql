-- 007 — authentication
--
-- THE CHICKEN-AND-EGG PROBLEM, AND WHY THESE FUNCTIONS EXIST.
--
-- Every customer-scoped table is under RLS keyed on app.customer_id. But at
-- the moment someone signs in there IS no customer id yet — deriving it is the
-- whole point of signing in. So the auth lookup cannot run under the policy it
-- is trying to satisfy.
--
-- Three ways out, and only one is safe:
--
--   1. Give the app role BYPASSRLS.               Catastrophic: one bug then
--                                                 reads every customer.
--   2. Remove RLS from mt_principals.             Then a request-scoped bug
--                                                 reads every principal.
--   3. SECURITY DEFINER functions.                A narrow, auditable hole
--                                                 that returns ONLY the ids
--                                                 needed to establish context.
--
-- These functions take the place of a SELECT the app is not allowed to make.
-- They return ids and nothing else — never a row, never a hash, never a name —
-- so the hole cannot be widened by the caller.
--
-- search_path is pinned on every one of them. A SECURITY DEFINER function with
-- a mutable search_path can be hijacked by a caller who creates a same-named
-- object in a schema earlier on the path, which would run attacker SQL as the
-- owner. That is the classic mistake with this pattern.

CREATE TABLE mt_auth_codes (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  phone         text NOT NULL,
  code_hash     text NOT NULL,
  principal_id  uuid REFERENCES mt_principals(id) ON DELETE CASCADE,
  customer_id   uuid REFERENCES mt_customers(id) ON DELETE CASCADE,
  attempts      integer NOT NULL DEFAULT 0,
  created_at    timestamptz NOT NULL DEFAULT now(),
  expires_at    timestamptz NOT NULL,
  consumed_at   timestamptz
);
CREATE INDEX mt_auth_codes_phone_ix ON mt_auth_codes (phone, created_at DESC);

-- Not customer-scoped by RLS: a code row exists before the caller has a
-- customer context. It is reachable only through the functions below, and the
-- app role is granted NOTHING on the table itself.
ALTER TABLE mt_auth_codes ENABLE ROW LEVEL SECURITY;
ALTER TABLE mt_auth_codes FORCE  ROW LEVEL SECURITY;
-- No policy at all => no rows for anyone but the definer. Deliberate.

-- ---------------------------------------------------------------------------
-- Issue a code. Returns the code row id ALWAYS, whether or not the phone is
-- registered, so the caller cannot tell a real number from an unknown one.
-- An unknown phone gets a row with NULL principal_id: it will never verify,
-- but it costs the same work and returns the same shape.
CREATE OR REPLACE FUNCTION mt_auth_issue_code(p_phone text, p_code_hash text, p_ttl interval)
RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp
AS $$
DECLARE v_principal uuid; v_customer uuid; v_id uuid; v_recent int;
BEGIN
  SELECT count(*) INTO v_recent FROM mt_auth_codes
   WHERE phone = p_phone AND created_at > now() - interval '15 minutes';
  IF v_recent >= 5 THEN
    -- A dedicated SQLSTATE so the caller can map this to 429.
    -- It previously raised 'too_many_connections' (53300), which surfaced as a
    -- 500 and wrote "Too many connections" into the log — sending whoever read
    -- it hunting a connection-pool fault that did not exist. A rate limit must
    -- name itself.
    RAISE EXCEPTION 'rate limited' USING ERRCODE = 'DN429';
  END IF;

  SELECT id, customer_id INTO v_principal, v_customer
    FROM mt_principals WHERE phone = p_phone AND status = 'active';

  INSERT INTO mt_auth_codes (phone, code_hash, principal_id, customer_id, expires_at)
  VALUES (p_phone, p_code_hash, v_principal, v_customer, now() + p_ttl)
  RETURNING id INTO v_id;
  RETURN v_id;
END $$;

-- Verify a code. Returns zero rows on any failure — wrong code, expired,
-- consumed, too many attempts, or a phone that was never registered. The
-- caller cannot distinguish these, which is the point.
CREATE OR REPLACE FUNCTION mt_auth_verify_code(p_phone text, p_code_hash text)
RETURNS TABLE (principal_id uuid, customer_id uuid)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp
AS $$
DECLARE r record;
BEGIN
  SELECT * INTO r FROM mt_auth_codes
   WHERE phone = p_phone AND consumed_at IS NULL AND expires_at > now()
   ORDER BY created_at DESC LIMIT 1;

  IF NOT FOUND THEN RETURN; END IF;

  UPDATE mt_auth_codes SET attempts = attempts + 1 WHERE id = r.id;
  IF r.attempts >= 4 THEN RETURN; END IF;

  -- constant-time-ish: the comparison happens regardless of whether the
  -- principal exists, so an unregistered phone takes the same path
  IF r.code_hash IS DISTINCT FROM p_code_hash THEN RETURN; END IF;
  IF r.principal_id IS NULL THEN RETURN; END IF;

  UPDATE mt_auth_codes SET consumed_at = now() WHERE id = r.id;
  principal_id := r.principal_id;
  customer_id  := r.customer_id;
  RETURN NEXT;
END $$;

-- Resolve a bearer token to the ids it authorises, and nothing else.
CREATE OR REPLACE FUNCTION mt_auth_resolve_token(p_token_hash text)
RETURNS TABLE (principal_id uuid, customer_id uuid)
LANGUAGE sql SECURITY DEFINER SET search_path = public, pg_temp
AS $$
  SELECT s.principal_id, s.customer_id
    FROM mt_auth_sessions s
    JOIN mt_principals p ON p.id = s.principal_id
   WHERE s.token_hash = p_token_hash
     AND s.revoked_at IS NULL
     AND s.expires_at > now()
     AND p.status = 'active';
$$;

CREATE OR REPLACE FUNCTION mt_auth_create_session(
  p_principal uuid, p_customer uuid, p_token_hash text, p_ttl interval)
RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp
AS $$
DECLARE v_id uuid;
BEGIN
  INSERT INTO mt_auth_sessions (principal_id, customer_id, token_hash, expires_at)
  VALUES (p_principal, p_customer, p_token_hash, now() + p_ttl) RETURNING id INTO v_id;
  UPDATE mt_principals SET last_login_at = now() WHERE id = p_principal;
  RETURN v_id;
END $$;

CREATE OR REPLACE FUNCTION mt_auth_revoke_token(p_token_hash text)
RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp
AS $$
DECLARE n integer;
BEGIN
  UPDATE mt_auth_sessions SET revoked_at = now()
   WHERE token_hash = p_token_hash AND revoked_at IS NULL;
  GET DIAGNOSTICS n = ROW_COUNT;
  RETURN n;
END $$;

-- The app role may EXECUTE these and may not touch mt_auth_codes directly.
REVOKE ALL ON mt_auth_codes FROM dnb_app;
GRANT EXECUTE ON FUNCTION mt_auth_issue_code(text,text,interval)   TO dnb_app;
GRANT EXECUTE ON FUNCTION mt_auth_verify_code(text,text)           TO dnb_app;
GRANT EXECUTE ON FUNCTION mt_auth_resolve_token(text)              TO dnb_app;
GRANT EXECUTE ON FUNCTION mt_auth_create_session(uuid,uuid,text,interval) TO dnb_app;
GRANT EXECUTE ON FUNCTION mt_auth_revoke_token(text)               TO dnb_app;
