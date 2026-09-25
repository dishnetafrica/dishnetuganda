-- 031 — sign-in requires an ACTIVE OPERATOR as well as an active person
--        (docs/127 §B F-1…F-4; finding F-J1-1 of docs/126).
--
-- Before this migration the three authentication functions checked the
-- PRINCIPAL's status and never the OPERATOR's: the people of a suspended
-- operator could request a code, verify it and keep using a session. Measured
-- and asserted as a gap in tests/test_operator_owner_login.php before this file
-- existed.
--
-- After it:
--   mt_auth_issue_code     finds a principal only if the person AND the operator
--                          are active; otherwise the code row is written with no
--                          principal, exactly as for an unknown number, so the
--                          answer is the same (docs/127 F-3);
--   mt_auth_verify_code    re-checks both at verification — the operator the
--                          session will act for, which is the code row's own —
--                          so a code issued before a suspension cannot be used
--                          after it;
--   mt_auth_resolve_token  re-checks both on EVERY request, against the
--                          session's own operator (P-C), so a suspended
--                          operator's live sessions stop on the next request.
--
-- Suspension does not revoke sessions (docs/127 F-4): nothing suspends an
-- operator yet, and the live check is the enforcement.
--
-- The only privilege added: dnb_def_auth, which owns the three functions, may
-- READ mt_customers — one SELECT policy, named as 017 names them. Return shapes,
-- signatures and EXECUTE grants (dnb_app only) are unchanged: CREATE OR REPLACE
-- keeps the ACL, and the verification block below asserts it.

-- 1. dnb_def_auth may read an operator's status. Nothing else changes.
GRANT SELECT ON mt_customers TO dnb_def_auth;
CREATE POLICY dnb_def_auth_mt_customers_select ON mt_customers
  FOR SELECT TO dnb_def_auth USING (true);

-- 2. The three functions, replaced by their owner.
GRANT CREATE ON SCHEMA public TO dnb_def_auth;
SET LOCAL ROLE dnb_def_auth;

CREATE OR REPLACE FUNCTION mt_auth_issue_code(p_phone text, p_code_hash text, p_ttl interval)
RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp
AS $$
DECLARE v_principal uuid; v_customer uuid; v_id uuid; v_recent int;
BEGIN
  SELECT count(*) INTO v_recent FROM mt_auth_codes
   WHERE phone = p_phone AND created_at > now() - interval '15 minutes';
  IF v_recent >= 5 THEN
    -- A dedicated SQLSTATE so the caller can map this to 429 (see 007).
    RAISE EXCEPTION 'rate limited' USING ERRCODE = 'DN429';
  END IF;

  -- The person AND the operator must be active (docs/127 F-1). A suspended
  -- operator's person is treated exactly as an unknown number: the code row is
  -- still written, with no principal, so nothing about the answer differs.
  SELECT p.id, p.customer_id INTO v_principal, v_customer
    FROM mt_principals p
    JOIN mt_customers  c ON c.id = p.customer_id
   WHERE p.phone = p_phone AND p.status = 'active' AND c.status = 'active';

  INSERT INTO mt_auth_codes (phone, code_hash, principal_id, customer_id, expires_at)
  VALUES (p_phone, p_code_hash, v_principal, v_customer, now() + p_ttl)
  RETURNING id INTO v_id;
  RETURN v_id;
END $$;

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

  -- Re-checked NOW (docs/127 F-1): a code issued before the person was disabled
  -- or the operator suspended does not open a session after it. The operator
  -- checked is the one the session will act for — the code row's own, which is
  -- what is returned below — exactly as the resolver checks the session's own.
  IF NOT EXISTS (SELECT 1 FROM mt_principals p, mt_customers c
                  WHERE p.id = r.principal_id AND p.status = 'active'
                    AND c.id = r.customer_id  AND c.status = 'active') THEN
    RETURN;
  END IF;

  UPDATE mt_auth_codes SET consumed_at = now() WHERE id = r.id;
  principal_id := r.principal_id;
  customer_id  := r.customer_id;
  RETURN NEXT;
END $$;

CREATE OR REPLACE FUNCTION mt_auth_resolve_token(p_token_hash text)
RETURNS TABLE (principal_id uuid, customer_id uuid, kind text, capabilities text[])
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  -- kind and capabilities are read from the principal row on EVERY request,
  -- never from the session (027). Since 031 the OPERATOR's status is read on
  -- every request too — the session's own operator, never the principal's
  -- current one (P-C) — so a suspension binds the next request.
  SELECT s.principal_id, s.customer_id, p.kind, p.capabilities
    FROM mt_auth_sessions s
    JOIN mt_principals p ON p.id = s.principal_id
    JOIN mt_customers  c ON c.id = s.customer_id
   WHERE s.token_hash = p_token_hash
     AND s.revoked_at IS NULL
     AND s.expires_at > now()
     AND p.status = 'active'
     AND c.status = 'active';
$$;

RESET ROLE;
REVOKE CREATE ON SCHEMA public FROM dnb_def_auth;

-- 3. Verify before committing: what changed is exactly what this file says.
DO $$
DECLARE f text; n int; r record;
BEGIN
  FOREACH f IN ARRAY ARRAY['mt_auth_issue_code(text,text,interval)', 'mt_auth_verify_code(text,text)',
                           'mt_auth_resolve_token(text)'] LOOP
    IF (SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE oid = f::regprocedure) <> 'dnb_def_auth'
       OR NOT (SELECT prosecdef FROM pg_proc WHERE oid = f::regprocedure) THEN
      RAISE EXCEPTION '031: % is not a SECURITY DEFINER owned by dnb_def_auth', f;
    END IF;
    IF position('mt_customers' IN (SELECT prosrc FROM pg_proc WHERE oid = f::regprocedure)) = 0 THEN
      RAISE EXCEPTION '031: % does not read the operator''s status', f;
    END IF;
    -- EXECUTE exactly as before: dnb_app and the owner, and nobody else.
    SELECT count(*) INTO n FROM pg_proc q, aclexplode(coalesce(q.proacl, acldefault('f', q.proowner))) a
     WHERE q.oid = f::regprocedure AND a.privilege_type = 'EXECUTE'
       AND a.grantee NOT IN ('dnb_app'::regrole::oid, q.proowner);
    IF n <> 0 THEN RAISE EXCEPTION '031: % may be executed by someone other than dnb_app', f; END IF;
    IF NOT has_function_privilege('dnb_app', f, 'EXECUTE') THEN
      RAISE EXCEPTION '031: dnb_app lost EXECUTE on %', f;
    END IF;
  END LOOP;
  IF NOT has_table_privilege('dnb_def_auth', 'mt_customers', 'SELECT')
     OR has_table_privilege('dnb_def_auth', 'mt_customers', 'INSERT')
     OR has_table_privilege('dnb_def_auth', 'mt_customers', 'UPDATE')
     OR has_table_privilege('dnb_def_auth', 'mt_customers', 'DELETE') THEN
    RAISE EXCEPTION '031: dnb_def_auth must READ mt_customers and nothing more';
  END IF;
END $$;
