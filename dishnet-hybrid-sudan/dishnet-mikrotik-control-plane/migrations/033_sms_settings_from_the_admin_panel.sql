-- 033 — SMS settings from the Admin panel (docs/128 SS-1…SS-14).
--
-- Before this migration the SMS sender for sign-in codes was chosen only by the
-- worker's environment (DN_SMS, DNB_SMS_*; migration 032, docs/127 §G), and the
-- API key reached it by being typed into the staging command on the server. The
-- operator asked for it to be settable from the Admin panel instead. After this
-- migration a DishNet Admin can set it there, and the worker picks it up within a
-- second without a restart. Where DN_SMS is set, the environment still wins.
--
--   SS-1  ONE row, in a table created as dnb_def_auth — the role that already
--         owns the outbox and the worker's SMS functions — so migration 015's
--         default privileges hand nobody anything. NO login role holds any
--         privilege on it. Not a tenant table: the SMS account is DishNet's.
--   SS-2  THE KEY NEVER RESTS IN CLEAR. PHP seals it before this schema sees it
--         (Dn\Notify\SmsSettings: AES-256-GCM under a key derived from
--         DNB_SECRET_KEY with its own label; 'africastalking|<username>' as
--         associated data). This table holds the envelope and a keyed
--         fingerprint whose only use is telling an identical re-save from a
--         real change. The key itself is never in the database.
--   SS-3  Only the worker reads the envelope: mt_sms_settings_for_worker(),
--         EXECUTE dnb_worker ONLY.
--   SS-4  The Admin read, mt_admin_sms_settings() (dnb_adminapi ONLY), returns
--         whether a key is set and nothing of it — not the envelope, not the
--         fingerprint.
--   SS-6  The write, mt_sms_settings_set(), EXECUTE dnb_adminwrite ONLY, writes
--         its own audit row (W-1): actor_kind 'staff', the actor a parameter
--         from the identity boundary, customer_id NULL, and a detail that never
--         carries the key, the envelope or the fingerprint.
--   SS-7  'none' clears everything. 'africastalking' needs a username and a new
--         key, or a stored key for THE SAME username. A username change without
--         a new key is refused. Identical settings are a no-op with NO audit
--         row (RULE I-1): a double click must not leave a false record that the
--         append-only trigger then keeps forever.
--   SS-10 The worker reports what it is doing — off, in use, unusable, or the
--         environment governs — through mt_sms_worker_report() (dnb_worker
--         ONLY), so the panel shows what the worker did, never what a form hoped.

-- 1. The audit writer, obtained from its owner (the 024 / 026 pattern): with
--    this migration dnb_def_auth owns an audited mutation.
SET LOCAL ROLE dnb_def_audit;
GRANT EXECUTE ON FUNCTION mt_audit_write(uuid,text,text,text,text,text,text,jsonb)
  TO dnb_def_auth;
RESET ROLE;

-- 2. The settings row, created as its owner.
GRANT CREATE ON SCHEMA public TO dnb_def_auth;
SET LOCAL ROLE dnb_def_auth;

CREATE TABLE mt_sms_settings (
  id              smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
  provider        text NOT NULL DEFAULT 'none' CHECK (provider IN ('none','africastalking')),
  -- The same rules as Dn\Notify\SmsSettings and the staging command's prompts:
  -- the function checks them first, for a clear answer; these are the floor.
  username        text CHECK (username ~ '^[A-Za-z0-9_.-]{1,64}$'),
  sender          text CHECK (sender ~ '^[A-Za-z0-9 ._-]{1,15}$'),
  key_sealed      text CHECK (length(key_sealed) <= 1000 AND key_sealed ~ '^v1\.[A-Za-z0-9+/]+={0,2}$'),
  key_fp          text CHECK (key_fp ~ '^[0-9a-f]{64}$'),
  version         bigint NOT NULL DEFAULT 0 CHECK (version >= 0),
  updated_at      timestamptz,
  updated_by      text,
  -- What the worker last said it is doing (SS-10). Written by the worker only.
  worker_state    text CHECK (worker_state IN ('off','in_use','unusable','environment')),
  worker_version  bigint,
  worker_detail   text CHECK (length(worker_detail) <= 200),
  worker_seen_at  timestamptz,
  -- 'none' holds nothing at all; 'africastalking' holds a username and a sealed
  -- key with its fingerprint. Nothing in between can be stored.
  CONSTRAINT mt_sms_settings_shape CHECK (
    (provider = 'none'
       AND username IS NULL AND sender IS NULL AND key_sealed IS NULL AND key_fp IS NULL)
    OR (provider = 'africastalking'
       AND username IS NOT NULL AND key_sealed IS NOT NULL AND key_fp IS NOT NULL))
);
INSERT INTO mt_sms_settings (id) VALUES (1);

-- 3. The Admin write (SS-6, SS-7). Returns {changed, version, key}, where key is
--    set | replaced | kept | removed | none — never the key itself.
CREATE FUNCTION mt_sms_settings_set(p_provider text, p_username text, p_sender text,
                                    p_key_sealed text, p_key_fp text, p_actor text)
RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp
AS $$
DECLARE s mt_sms_settings%ROWTYPE; v_sender text; v_key text; v_version bigint;
BEGIN
  IF p_actor IS NULL OR btrim(p_actor) = '' THEN
    RAISE EXCEPTION 'an SMS settings change requires the identity of whoever made it' USING ERRCODE = '22023';
  END IF;
  IF p_provider IS NULL OR p_provider NOT IN ('none','africastalking') THEN
    RAISE EXCEPTION 'provider must be none or africastalking' USING ERRCODE = '22023';
  END IF;
  IF (p_key_sealed IS NULL) <> (p_key_fp IS NULL) THEN
    RAISE EXCEPTION 'a new key comes sealed and with its fingerprint, or not at all' USING ERRCODE = '22023';
  END IF;

  -- The row always exists (this migration inserts it; nothing deletes it). The
  -- lock serialises two Admins saving at once.
  SELECT * INTO s FROM mt_sms_settings WHERE id = 1 FOR UPDATE;

  IF p_provider = 'none' THEN
    IF p_username IS NOT NULL OR nullif(p_sender, '') IS NOT NULL OR p_key_sealed IS NOT NULL THEN
      RAISE EXCEPTION 'turning SMS off takes no username, sender or key' USING ERRCODE = '22023';
    END IF;
    IF s.provider = 'none' THEN
      RETURN jsonb_build_object('changed', false, 'version', s.version, 'key', 'none');
    END IF;
    v_version := s.version + 1;
    UPDATE mt_sms_settings
       SET provider = 'none', username = NULL, sender = NULL, key_sealed = NULL, key_fp = NULL,
           version = v_version, updated_at = now(), updated_by = btrim(p_actor)
     WHERE id = 1;
    PERFORM mt_audit_write(NULL, btrim(p_actor), 'staff', 'sms.settings_changed', 'sms_settings', NULL, 'admin',
      jsonb_build_object('provider', 'none', 'key', 'removed', 'version', v_version));
    RETURN jsonb_build_object('changed', true, 'version', v_version, 'key', 'removed');
  END IF;

  IF p_username IS NULL OR p_username !~ '^[A-Za-z0-9_.-]{1,64}$' THEN
    RAISE EXCEPTION 'username must be 1-64 letters, digits, dots, dashes or underscores' USING ERRCODE = '22023';
  END IF;
  v_sender := nullif(p_sender, '');
  IF v_sender IS NOT NULL AND v_sender !~ '^[A-Za-z0-9 ._-]{1,15}$' THEN
    RAISE EXCEPTION 'sender must be 1-15 letters, digits, spaces, dots, dashes or underscores' USING ERRCODE = '22023';
  END IF;

  IF p_key_sealed IS NULL THEN
    -- Keep the stored key: only for the same provider AND the same username.
    -- The key belongs to an account, and its envelope names that account.
    IF s.provider <> 'africastalking' THEN
      RAISE EXCEPTION 'an API key is required' USING ERRCODE = '22023';
    END IF;
    IF s.username IS DISTINCT FROM p_username THEN
      RAISE EXCEPTION 'a new username needs its API key typed again' USING ERRCODE = '22023';
    END IF;
    v_key := 'kept';
  ELSIF s.provider = 'africastalking' AND s.username = p_username AND s.key_fp = p_key_fp THEN
    v_key := 'kept';                       -- the same key, typed again
  ELSIF s.provider = 'africastalking' THEN
    v_key := 'replaced';
  ELSE
    v_key := 'set';
  END IF;

  -- RULE I-1: identical settings write nothing — no row change, no audit row.
  IF s.provider = 'africastalking' AND s.username = p_username
     AND s.sender IS NOT DISTINCT FROM v_sender AND v_key = 'kept' THEN
    RETURN jsonb_build_object('changed', false, 'version', s.version, 'key', 'kept');
  END IF;

  v_version := s.version + 1;
  UPDATE mt_sms_settings
     SET provider   = 'africastalking',
         username   = p_username,
         sender     = v_sender,
         key_sealed = CASE WHEN v_key = 'kept' THEN s.key_sealed ELSE p_key_sealed END,
         key_fp     = CASE WHEN v_key = 'kept' THEN s.key_fp     ELSE p_key_fp     END,
         version    = v_version, updated_at = now(), updated_by = btrim(p_actor)
   WHERE id = 1;
  PERFORM mt_audit_write(NULL, btrim(p_actor), 'staff', 'sms.settings_changed', 'sms_settings', NULL, 'admin',
    jsonb_build_object('provider', 'africastalking', 'username', p_username, 'sender', v_sender,
                       'key', v_key, 'version', v_version));
  RETURN jsonb_build_object('changed', true, 'version', v_version, 'key', v_key);
END $$;

-- 4. The worker's read (SS-3) — the only function that returns the envelope.
CREATE FUNCTION mt_sms_settings_for_worker()
RETURNS TABLE (provider text, username text, sender text, key_sealed text, version bigint)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp
AS $$
  SELECT s.provider, s.username, s.sender, s.key_sealed, s.version FROM mt_sms_settings s WHERE s.id = 1
$$;

-- 5. The worker's report (SS-10): what it is doing, and which version it applied.
CREATE FUNCTION mt_sms_worker_report(p_version bigint, p_state text, p_detail text)
RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp
AS $$
BEGIN
  IF p_state IS NULL OR p_state NOT IN ('off','in_use','unusable','environment') THEN
    RAISE EXCEPTION 'state must be off, in_use, unusable or environment' USING ERRCODE = '22023';
  END IF;
  UPDATE mt_sms_settings
     SET worker_state = p_state, worker_version = p_version,
         worker_detail = left(p_detail, 200), worker_seen_at = now()
   WHERE id = 1;
END $$;

-- 6. The Admin read (SS-4, SS-10, SS-11): the settings without the key, the
--    worker's own report, and the outbox's record as counts and times only —
--    no phone number, no code, no envelope.
CREATE FUNCTION mt_admin_sms_settings()
RETURNS TABLE (provider text, username text, sender text, key_set boolean, version bigint,
               updated_at timestamptz, updated_by text,
               worker_state text, worker_version bigint, worker_detail text,
               worker_seen_at timestamptz, worker_recent boolean,
               sent_24h integer, failed_24h integer, expired_24h integer,
               no_recipient_24h integer, pending_now integer,
               last_sent_at timestamptz, last_failure_at timestamptz, last_failure text)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp
AS $$
  SELECT s.provider, s.username, s.sender, s.key_sealed IS NOT NULL, s.version,
         s.updated_at, s.updated_by,
         s.worker_state, s.worker_version, s.worker_detail,
         s.worker_seen_at, coalesce(s.worker_seen_at > now() - interval '3 minutes', false),
         (SELECT count(*)::int FROM mt_auth_sms_outbox o
           WHERE o.state = 'sent'         AND o.settled_at > now() - interval '24 hours'),
         (SELECT count(*)::int FROM mt_auth_sms_outbox o
           WHERE o.state = 'failed'       AND o.settled_at > now() - interval '24 hours'),
         (SELECT count(*)::int FROM mt_auth_sms_outbox o
           WHERE o.state = 'expired'      AND o.settled_at > now() - interval '24 hours'),
         (SELECT count(*)::int FROM mt_auth_sms_outbox o
           WHERE o.state = 'no_recipient' AND o.created_at > now() - interval '24 hours'),
         (SELECT count(*)::int FROM mt_auth_sms_outbox o WHERE o.state IN ('queued','sending')),
         (SELECT max(o.settled_at) FROM mt_auth_sms_outbox o WHERE o.state = 'sent'),
         f.at, f.why
    FROM mt_sms_settings s
    LEFT JOIN LATERAL (
      -- The last attempt the provider refused or that failed on the way: only a
      -- row the worker actually tried (attempts > 0), never one that merely
      -- expired unsent.
      SELECT coalesce(o.settled_at, o.created_at) AS at, o.last_error AS why
        FROM mt_auth_sms_outbox o
       WHERE o.last_error IS NOT NULL AND o.attempts > 0
       ORDER BY coalesce(o.settled_at, o.created_at) DESC
       LIMIT 1) f ON true
   WHERE s.id = 1
$$;

-- The grants are issued HERE, as the functions' owner, and not after RESET ROLE
-- (032's measured lesson: the installing owner is a member of dnb_def_auth
-- WITHOUT inherit, so a REVOKE it issues afterwards changes nothing).
REVOKE ALL ON FUNCTION mt_sms_settings_set(text,text,text,text,text,text) FROM PUBLIC;
REVOKE ALL ON FUNCTION mt_sms_settings_for_worker() FROM PUBLIC;
REVOKE ALL ON FUNCTION mt_sms_worker_report(bigint,text,text) FROM PUBLIC;
REVOKE ALL ON FUNCTION mt_admin_sms_settings() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mt_sms_settings_set(text,text,text,text,text,text) TO dnb_adminwrite;
GRANT EXECUTE ON FUNCTION mt_sms_settings_for_worker() TO dnb_worker;
GRANT EXECUTE ON FUNCTION mt_sms_worker_report(bigint,text,text) TO dnb_worker;
GRANT EXECUTE ON FUNCTION mt_admin_sms_settings() TO dnb_adminapi;

-- The row is checked HERE, as its owner: after RESET ROLE the installing owner
-- cannot read this table at all — which is the point of SS-1. (Measured while
-- building: a trial run as SET ROLE dnb from a superuser passed this check
-- only because RESET ROLE returned that session to the superuser.)
DO $$
BEGIN
  IF (SELECT count(*) FROM mt_sms_settings WHERE id = 1 AND provider = 'none' AND version = 0) <> 1 THEN
    RAISE EXCEPTION '033: the settings row did not start as none, version 0';
  END IF;
END $$;

RESET ROLE;

REVOKE CREATE ON SCHEMA public FROM dnb_def_auth;

-- 7. Verify before committing: what exists is exactly what this file says.
DO $$
DECLARE f text; who text; n int;
BEGIN
  FOREACH f IN ARRAY ARRAY['mt_sms_settings_set(text,text,text,text,text,text)',
                           'mt_sms_settings_for_worker()',
                           'mt_sms_worker_report(bigint,text,text)',
                           'mt_admin_sms_settings()'] LOOP
    IF (SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE oid = f::regprocedure) <> 'dnb_def_auth'
       OR NOT (SELECT prosecdef FROM pg_proc WHERE oid = f::regprocedure) THEN
      RAISE EXCEPTION '033: % is not a SECURITY DEFINER owned by dnb_def_auth', f;
    END IF;
    who := CASE WHEN f LIKE 'mt_sms_settings_set%' THEN 'dnb_adminwrite'
                WHEN f LIKE 'mt_admin_%'           THEN 'dnb_adminapi'
                ELSE 'dnb_worker' END;
    SELECT count(*) INTO n FROM pg_proc q, aclexplode(coalesce(q.proacl, acldefault('f', q.proowner))) a
     WHERE q.oid = f::regprocedure AND a.privilege_type = 'EXECUTE'
       AND a.grantee NOT IN (who::regrole::oid, q.proowner);
    IF n <> 0 THEN RAISE EXCEPTION '033: % may be executed by someone other than %', f, who; END IF;
    IF NOT has_function_privilege(who, f, 'EXECUTE') THEN
      RAISE EXCEPTION '033: % cannot execute %', who, f;
    END IF;
  END LOOP;

  IF (SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid = 'mt_sms_settings'::regclass) <> 'dnb_def_auth' THEN
    RAISE EXCEPTION '033: mt_sms_settings is not owned by dnb_def_auth';
  END IF;
  -- No other role holds ANY privilege on the settings — by grant, not by guess.
  SELECT count(*) INTO n FROM pg_class t, aclexplode(coalesce(t.relacl, acldefault('r', t.relowner))) a
   WHERE t.oid = 'mt_sms_settings'::regclass AND a.grantee <> t.relowner;
  IF n <> 0 THEN RAISE EXCEPTION '033: some role other than the owner holds a privilege on mt_sms_settings'; END IF;

  IF NOT has_function_privilege('dnb_def_auth', 'mt_audit_write(uuid,text,text,text,text,text,text,jsonb)', 'EXECUTE') THEN
    RAISE EXCEPTION '033: dnb_def_auth cannot write its audit row';
  END IF;
  IF has_schema_privilege('dnb_def_auth', 'public', 'CREATE') THEN
    RAISE EXCEPTION '033: dnb_def_auth kept CREATE on the schema';
  END IF;
  -- The installing owner itself holds nothing on the table: by grant, here.
  IF has_table_privilege(current_user, 'mt_sms_settings', 'SELECT') THEN
    RAISE EXCEPTION '033: the installing role (%) can read mt_sms_settings', current_user;
  END IF;
END $$;
