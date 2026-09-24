-- 032 — sign-in codes by SMS: a sealed outbox that the worker sends from
--        (docs/127 §C, S-1…S-9; phase 2).
--
-- Before this migration a sign-in code was delivered nowhere: issueCode()
-- returned it and nothing sent it. After it, the request writes an OUTBOX row
-- in the same transaction as the code, and the worker sends it.
--
--   S-1  a code is queued only for an ACTIVE person of an ACTIVE operator —
--        the same lookup 031 made the code row use. An unknown number, a
--        disabled person or a suspended operator's person gets a row in state
--        'no_recipient' with NO payload, so nothing is ever sent to them and
--        nobody can spend DishNet's SMS credit on an arbitrary number.
--   S-2  one outbox row for EVERY request, registered or not: the same writes
--        either way, so the answer's timing does not tell them apart. The
--        request never waits on the provider — the worker sends.
--   S-3  the code never rests in clear. PHP seals it before the call
--        (Dn\Notify\CodeEnvelope: AES-256-GCM under a key derived from
--        DNB_SECRET_KEY with its own label, the phone as associated data);
--        this schema stores only the envelope, and erases it the moment the
--        row settles, fails or expires. The key is never in the database.
--   S-4  the outbox belongs to dnb_def_auth, is created as that role (so
--        migration 015's default privileges hand nobody anything), and NO
--        login role holds any privilege on it. The worker reaches it through
--        three functions — claim (a 60-second lease), settle, expire —
--        executable by dnb_worker ONLY. At most 3 attempts.
--
-- mt_auth_issue_code gains the sealed payload as a fourth parameter. The old
-- three-argument signature is DROPPED, not left beside it: a caller that could
-- still issue a code without a payload would issue one nobody can deliver.

-- 1. The outbox, created as its owner. REFERENCES on mt_auth_codes is granted
--    only for the foreign key's creation and revoked straight after.
GRANT CREATE ON SCHEMA public TO dnb_def_auth;
GRANT REFERENCES ON mt_auth_codes TO dnb_def_auth;
SET LOCAL ROLE dnb_def_auth;

CREATE TABLE mt_auth_sms_outbox (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  -- One row per code; the code row carries the phone, so the outbox does not
  -- keep a second copy of it.
  code_id       uuid NOT NULL UNIQUE REFERENCES mt_auth_codes (id) ON DELETE CASCADE,
  state         text NOT NULL
                CHECK (state IN ('queued','sending','sent','failed','expired','no_recipient')),
  sealed        text,
  attempts      integer NOT NULL DEFAULT 0 CHECK (attempts BETWEEN 0 AND 3),
  lease_until   timestamptz,
  provider_ref  text,
  last_error    text,
  created_at    timestamptz NOT NULL DEFAULT now(),
  expires_at    timestamptz NOT NULL,
  settled_at    timestamptz,
  -- The envelope exists while, and only while, the message may still be sent.
  CONSTRAINT mt_auth_sms_outbox_sealed_only_while_pending
    CHECK ((state IN ('queued','sending')) = (sealed IS NOT NULL)),
  CONSTRAINT mt_auth_sms_outbox_lease_only_while_sending
    CHECK ((state = 'sending') = (lease_until IS NOT NULL))
);
CREATE INDEX mt_auth_sms_outbox_pending_ix ON mt_auth_sms_outbox (created_at)
  WHERE state IN ('queued','sending');

-- 2. Issue a code — 031's function, plus the outbox row and the sealed payload.
DROP FUNCTION mt_auth_issue_code(text, text, interval);
CREATE FUNCTION mt_auth_issue_code(p_phone text, p_code_hash text, p_ttl interval, p_sealed text)
RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp
AS $$
DECLARE v_principal uuid; v_customer uuid; v_id uuid; v_recent int;
BEGIN
  -- Checked for EVERY caller before anything else, so a missing payload can
  -- never make a registered number answer differently from an unknown one.
  IF p_sealed IS NULL OR length(p_sealed) > 200 OR p_sealed !~ '^v1\.[A-Za-z0-9+/]+={0,2}$' THEN
    RAISE EXCEPTION 'a sealed code is required' USING ERRCODE = '22023';
  END IF;

  SELECT count(*) INTO v_recent FROM mt_auth_codes
   WHERE phone = p_phone AND created_at > now() - interval '15 minutes';
  IF v_recent >= 5 THEN
    -- A dedicated SQLSTATE so the caller can map this to 429 (see 007).
    RAISE EXCEPTION 'rate limited' USING ERRCODE = 'DN429';
  END IF;

  -- The person AND the operator must be active (031, docs/127 F-1).
  SELECT p.id, p.customer_id INTO v_principal, v_customer
    FROM mt_principals p
    JOIN mt_customers  c ON c.id = p.customer_id
   WHERE p.phone = p_phone AND p.status = 'active' AND c.status = 'active';

  INSERT INTO mt_auth_codes (phone, code_hash, principal_id, customer_id, expires_at)
  VALUES (p_phone, p_code_hash, v_principal, v_customer, now() + p_ttl)
  RETURNING id INTO v_id;

  -- S-1 / S-2: one outbox row for every request. Only a person who could sign
  -- in gets the sealed code; everyone else gets 'no_recipient' and no payload.
  INSERT INTO mt_auth_sms_outbox (code_id, state, sealed, expires_at)
  VALUES (v_id,
          CASE WHEN v_principal IS NULL THEN 'no_recipient' ELSE 'queued' END,
          CASE WHEN v_principal IS NULL THEN NULL ELSE p_sealed END,
          now() + p_ttl);
  RETURN v_id;
END $$;

-- 3. The worker's three functions.
--
-- Claim up to p_limit messages that may still be sent, leased for 60 seconds.
-- A message whose lease lapsed (its worker died) is claimable again while it
-- has attempts left. SKIP LOCKED: two workers never claim the same message.
CREATE FUNCTION mt_auth_sms_claim(p_limit integer)
RETURNS TABLE (id uuid, phone text, sealed text, attempt integer)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp
AS $$
BEGIN
  IF p_limit IS NULL OR p_limit < 1 OR p_limit > 50 THEN
    RAISE EXCEPTION 'claim limit must be between 1 and 50' USING ERRCODE = '22023';
  END IF;
  RETURN QUERY
  WITH picked AS (
    SELECT o.id FROM mt_auth_sms_outbox o
     WHERE o.expires_at > now()
       AND o.attempts < 3
       AND (o.state = 'queued' OR (o.state = 'sending' AND o.lease_until <= now()))
     ORDER BY o.created_at
     LIMIT p_limit
     FOR UPDATE SKIP LOCKED)
  UPDATE mt_auth_sms_outbox o
     SET state = 'sending', lease_until = now() + interval '60 seconds', attempts = o.attempts + 1
    FROM picked, mt_auth_codes c
   WHERE o.id = picked.id AND c.id = o.code_id
  RETURNING o.id, c.phone, o.sealed, o.attempts;
END $$;

-- Settle one claimed message. p_attempt is the claim's own attempt number: a
-- worker whose lease lapsed and whose message was claimed again cannot settle
-- the newer claim. 'retry' re-queues while attempts remain; otherwise the row
-- becomes failed (or expired, if the code has). Every final state erases the
-- envelope. Returns the new state, or NULL when there was nothing to settle.
CREATE FUNCTION mt_auth_sms_settle(p_id uuid, p_attempt integer, p_outcome text,
                                   p_provider_ref text, p_error text)
RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp
AS $$
DECLARE r mt_auth_sms_outbox%ROWTYPE; v_state text;
BEGIN
  IF p_outcome IS NULL OR p_outcome NOT IN ('sent','retry','failed') THEN
    RAISE EXCEPTION 'outcome must be sent, retry or failed' USING ERRCODE = '22023';
  END IF;
  SELECT * INTO r FROM mt_auth_sms_outbox WHERE mt_auth_sms_outbox.id = p_id FOR UPDATE;
  IF NOT FOUND OR r.state <> 'sending' OR r.attempts IS DISTINCT FROM p_attempt THEN
    RETURN NULL;
  END IF;

  v_state := CASE
    WHEN p_outcome = 'sent'       THEN 'sent'
    WHEN r.expires_at <= now()    THEN 'expired'
    WHEN p_outcome = 'failed'     THEN 'failed'
    WHEN r.attempts >= 3          THEN 'failed'
    ELSE 'queued' END;

  UPDATE mt_auth_sms_outbox SET
    state        = v_state,
    sealed       = CASE WHEN v_state = 'queued' THEN r.sealed ELSE NULL END,
    lease_until  = NULL,
    provider_ref = CASE WHEN p_outcome = 'sent' THEN left(p_provider_ref, 100) ELSE provider_ref END,
    last_error   = CASE WHEN p_outcome = 'sent' THEN NULL ELSE left(p_error, 200) END,
    settled_at   = CASE WHEN v_state = 'queued' THEN NULL ELSE now() END
   WHERE mt_auth_sms_outbox.id = p_id;
  RETURN v_state;
END $$;

-- Close what can no longer be sent: every pending row whose code has expired,
-- and every row whose third attempt never reported back. Erases the envelope.
-- Runs on every worker tick whether or not an SMS sender is configured, so
-- with none configured every queued message ends as 'expired' — the truth.
CREATE FUNCTION mt_auth_sms_expire()
RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp
AS $$
DECLARE n integer;
BEGIN
  UPDATE mt_auth_sms_outbox SET
    state       = CASE WHEN expires_at <= now() THEN 'expired' ELSE 'failed' END,
    sealed      = NULL,
    lease_until = NULL,
    settled_at  = now(),
    last_error  = CASE WHEN expires_at <= now()
                       THEN coalesce(last_error, 'not sent before the code expired')
                       ELSE 'the last attempt did not report back' END
   WHERE state IN ('queued','sending')
     AND (expires_at <= now()
          OR (state = 'sending' AND lease_until <= now() AND attempts >= 3));
  GET DIAGNOSTICS n = ROW_COUNT;
  RETURN n;
END $$;

-- The grants are issued HERE, as the functions' owner, and not after RESET
-- ROLE. Measured while building this file: the installing owner is a member
-- of dnb_def_auth WITHOUT inherit, so a REVOKE it issues on dnb_def_auth's
-- functions is a WARNING that changes nothing — PUBLIC kept EXECUTE on the
-- new mt_auth_issue_code, and the verification block below refused the
-- migration. 027 and 030 grant inside their role blocks for the same reason.
REVOKE ALL ON FUNCTION mt_auth_issue_code(text, text, interval, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mt_auth_issue_code(text, text, interval, text) TO dnb_app;
REVOKE ALL ON FUNCTION mt_auth_sms_claim(integer) FROM PUBLIC;
REVOKE ALL ON FUNCTION mt_auth_sms_settle(uuid, integer, text, text, text) FROM PUBLIC;
REVOKE ALL ON FUNCTION mt_auth_sms_expire() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mt_auth_sms_claim(integer) TO dnb_worker;
GRANT EXECUTE ON FUNCTION mt_auth_sms_settle(uuid, integer, text, text, text) TO dnb_worker;
GRANT EXECUTE ON FUNCTION mt_auth_sms_expire() TO dnb_worker;

RESET ROLE;

REVOKE REFERENCES ON mt_auth_codes FROM dnb_def_auth;
REVOKE CREATE ON SCHEMA public FROM dnb_def_auth;

-- 4. Verify before committing: what exists is exactly what this file says.
DO $$
DECLARE f text; who text; n int;
BEGIN
  IF to_regprocedure('mt_auth_issue_code(text,text,interval)') IS NOT NULL THEN
    RAISE EXCEPTION '032: the three-argument mt_auth_issue_code still exists';
  END IF;

  FOREACH f IN ARRAY ARRAY['mt_auth_issue_code(text,text,interval,text)', 'mt_auth_sms_claim(integer)',
                           'mt_auth_sms_settle(uuid,integer,text,text,text)', 'mt_auth_sms_expire()'] LOOP
    IF (SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE oid = f::regprocedure) <> 'dnb_def_auth'
       OR NOT (SELECT prosecdef FROM pg_proc WHERE oid = f::regprocedure) THEN
      RAISE EXCEPTION '032: % is not a SECURITY DEFINER owned by dnb_def_auth', f;
    END IF;
    who := CASE WHEN f LIKE 'mt_auth_issue_code%' THEN 'dnb_app' ELSE 'dnb_worker' END;
    SELECT count(*) INTO n FROM pg_proc q, aclexplode(coalesce(q.proacl, acldefault('f', q.proowner))) a
     WHERE q.oid = f::regprocedure AND a.privilege_type = 'EXECUTE'
       AND a.grantee NOT IN (who::regrole::oid, q.proowner);
    IF n <> 0 THEN RAISE EXCEPTION '032: % may be executed by someone other than %', f, who; END IF;
    IF NOT has_function_privilege(who, f, 'EXECUTE') THEN
      RAISE EXCEPTION '032: % cannot execute %', who, f;
    END IF;
  END LOOP;

  IF (SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid = 'mt_auth_sms_outbox'::regclass) <> 'dnb_def_auth' THEN
    RAISE EXCEPTION '032: mt_auth_sms_outbox is not owned by dnb_def_auth';
  END IF;
  -- No other role holds ANY privilege on the outbox — by grant, not by guess.
  SELECT count(*) INTO n FROM pg_class t, aclexplode(coalesce(t.relacl, acldefault('r', t.relowner))) a
   WHERE t.oid = 'mt_auth_sms_outbox'::regclass AND a.grantee <> t.relowner;
  IF n <> 0 THEN RAISE EXCEPTION '032: some role other than the owner holds a privilege on mt_auth_sms_outbox'; END IF;

  IF has_table_privilege('dnb_def_auth', 'mt_auth_codes', 'REFERENCES') THEN
    RAISE EXCEPTION '032: dnb_def_auth kept REFERENCES on mt_auth_codes';
  END IF;
  IF has_schema_privilege('dnb_def_auth', 'public', 'CREATE') THEN
    RAISE EXCEPTION '032: dnb_def_auth kept CREATE on the schema';
  END IF;
END $$;
