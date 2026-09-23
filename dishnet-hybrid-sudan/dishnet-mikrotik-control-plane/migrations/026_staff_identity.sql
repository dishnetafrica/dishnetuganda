-- 026 — DishNet Staff identity: WHO is DishNet staff (docs/114 G-B)
--
-- This migration establishes exactly one thing: the identity of DishNet's own
-- people, behind the AdminIdentityPort seam. It does not touch mt_principals,
-- operator-plane capabilities (migration 027, docs/116), B-3 or O-1.
--
-- Decisions frozen in docs/114 §M.3 and resolved in §N, each measured first:
--
--   D-AUTH-1  a dedicated LOGIN role, dnb_staffauth, for login / resolve /
--             logout and nothing else. dnb_adminwrite can already EXECUTE
--             seven write functions (M1), so a pre-authentication code path
--             may not connect as it.
--   D-AUTH-2  bcrypt (pgcrypto, cost 12) is verified HERE. No function that a
--             login role can EXECUTE returns a hash or a TOTP secret; the one
--             exception is enrolment, which returns the NEW secret once to the
--             session that asked for it.
--   D-AUTH-4  session 8 h absolute; lockout 5 failures → 15 min, doubling per
--             lock, 24 h ceiling, decaying, never permanent. Every number lives
--             in mt_staff_policy() and nowhere else.
--   D-AUTH-5  TOTP is verified HERE. A password-only session is recorded with
--             factor 'password'; whether that is enough is the application's
--             decision (DN_STAFF_REQUIRE_TOTP), and it is enough only to enrol.
--   D-AUTH-6  the audit actor is the immutable username; the uuid travels in
--             detail. display_name appears in no audit row.
--   D-AUTH-7  lifecycle functions are EXECUTE-able by dnb_adminwrite only; the
--             route guard requires staff.manage, which only Admin holds.
--
-- THE TABLES ARE CREATED AS dnb_def_staff, NOT AS THE OWNER. Measured (docs/114
-- §M.1, M3): pg_default_acl records, for the owner as granting role,
-- dnb_app=r, dnb_worker=rwd and dnb_admin=rwd on every table the owner
-- creates. A credential table created by the owner would hand dnb_admin
-- SELECT on password hashes before this file ended. dnb_def_staff has no
-- default ACL, so nothing is granted by default, and a test asserts that
-- every login role holds zero privileges on both tables.
--
-- Failed logins are counters on the row, not audit rows: they are security
-- telemetry (docs/89's distinction). A lock engaging IS audited.
-- ---------------------------------------------------------------------------

-- The schema's first extension. pgcrypto is a TRUSTED extension (PostgreSQL
-- 13+), so the non-superuser owner may create it in a database it owns —
-- proved by execution on this cluster (docs/114 §M.1, M5) and re-proved by
-- the installer on every suite run.
CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- ---------------------------------------------------------------------------
-- 1. Roles
-- ---------------------------------------------------------------------------
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'dnb_def_staff') THEN
    CREATE ROLE dnb_def_staff NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE
                              NOINHERIT NOBYPASSRLS;
  END IF;
  -- Ownership needs membership; INHERIT FALSE keeps the migration owner from
  -- picking up this role's privileges by inheritance (017 §1).
  EXECUTE format('GRANT dnb_def_staff TO %I WITH INHERIT FALSE, SET TRUE', current_user);

  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'dnb_staffauth') THEN
    -- No PASSWORD clause, deliberately (docs/97, B-1): the installer
    -- provisions one. Until then this role cannot authenticate at all.
    CREATE ROLE dnb_staffauth LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE
                              NOINHERIT NOBYPASSRLS;
  END IF;
END $$;

GRANT USAGE ON SCHEMA public TO dnb_def_staff, dnb_staffauth;
-- CREATE transiently, revoked at the end of this file, exactly as 017 does.
GRANT CREATE ON SCHEMA public TO dnb_def_staff;

-- ---------------------------------------------------------------------------
-- 2. The audit writer, obtained from its owner (the 024 pattern)
-- ---------------------------------------------------------------------------
SET LOCAL ROLE dnb_def_audit;
GRANT EXECUTE ON FUNCTION mt_audit_write(uuid,text,text,text,text,text,text,jsonb)
  TO dnb_def_staff;
RESET ROLE;

-- ---------------------------------------------------------------------------
-- 3. Everything below is created AS dnb_def_staff
-- ---------------------------------------------------------------------------
SET LOCAL ROLE dnb_def_staff;

CREATE TABLE mt_staff (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  -- Immutable, case-folded, and the audit actor (D-AUTH-6).
  username          text NOT NULL UNIQUE
                    CHECK (username = lower(username) AND username ~ '^[a-z0-9][a-z0-9._-]{1,62}$'),
  display_name      text NOT NULL,
  role              text NOT NULL CHECK (role IN ('admin','noc','sales','support')),
  status            text NOT NULL DEFAULT 'active' CHECK (status IN ('active','disabled')),
  password_hash     text NOT NULL,            -- bcrypt, never leaves this table
  password_set_at   timestamptz NOT NULL DEFAULT now(),
  totp_secret       bytea,                    -- raw bytes, never leaves this table after enrolment
  totp_confirmed_at timestamptz,
  totp_last_step    bigint,                   -- replay guard: a step is accepted once
  failed_attempts   int NOT NULL DEFAULT 0,
  lock_count        int NOT NULL DEFAULT 0,   -- doubles the next lock; reset by a success
  locked_until      timestamptz,
  last_failed_at    timestamptz,
  last_login_at     timestamptz,
  created_at        timestamptz NOT NULL DEFAULT now(),
  created_by        text NOT NULL,
  disabled_at       timestamptz,
  disabled_by       text
);

CREATE TABLE mt_staff_sessions (
  id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  staff_id       uuid NOT NULL REFERENCES mt_staff(id) ON DELETE RESTRICT,
  token_hash     text NOT NULL UNIQUE,        -- HMAC of the cookie token; the token itself is never stored
  factor         text NOT NULL CHECK (factor IN ('password','password+totp')),
  created_at     timestamptz NOT NULL DEFAULT now(),
  expires_at     timestamptz NOT NULL,        -- absolute; there is no sliding expiry
  revoked_at     timestamptz,
  revoked_reason text,
  source         text
);
CREATE INDEX mt_staff_sessions_live_ix ON mt_staff_sessions (staff_id) WHERE revoked_at IS NULL;

-- NOT tenant tables: no customer_id, and deliberately NO row-level security.
-- A later reader should not "fix" this. Isolation here is by privilege, not
-- by policy: no login role may touch either table, and every access goes
-- through the functions below.
REVOKE ALL ON mt_staff, mt_staff_sessions FROM PUBLIC;

-- ---------------------------------------------------------------------------
-- 4. Policy — the ONE place every number lives (D-AUTH-4)
-- ---------------------------------------------------------------------------
CREATE FUNCTION mt_staff_policy()
RETURNS TABLE (session_ttl interval, lockout_threshold int, lockout_base interval,
               lockout_ceiling interval, failure_decay interval,
               min_password_length int, totp_step_seconds int, totp_window_steps int,
               bcrypt_cost int)
LANGUAGE sql IMMUTABLE AS $$
  SELECT interval '8 hours',        -- session lifetime, absolute
         5,                         -- failures before a lock
         interval '15 minutes',     -- first lock
         interval '24 hours',       -- longest lock
         interval '1 hour',         -- a failure older than this restarts the count
         12,                        -- shortest password
         30, 1,                     -- TOTP step and ±window
         12                         -- bcrypt cost
$$;

-- ---------------------------------------------------------------------------
-- 5. Internal helpers — owner-only, no grants
-- ---------------------------------------------------------------------------

-- RFC 6238 TOTP, ±window, with a replay guard: returns the accepted step, or
-- NULL. A step at or below p_last_step is never accepted twice.
CREATE FUNCTION mt_staff_totp_step(p_secret bytea, p_code text, p_at timestamptz, p_last_step bigint)
RETURNS bigint LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE
  pol   record;
  base  bigint;
  step  bigint;
  d     int;
  msg   bytea;
  h     bytea;
  off   int;
  bin   bigint;
BEGIN
  IF p_secret IS NULL OR p_code IS NULL OR p_code !~ '^[0-9]{6}$' THEN
    RETURN NULL;
  END IF;
  SELECT * INTO pol FROM mt_staff_policy();
  base := floor(extract(epoch FROM p_at) / pol.totp_step_seconds)::bigint;
  FOR d IN -pol.totp_window_steps .. pol.totp_window_steps LOOP
    step := base + d;
    CONTINUE WHEN p_last_step IS NOT NULL AND step <= p_last_step;
    msg := decode(lpad(to_hex(step), 16, '0'), 'hex');
    h   := hmac(msg, p_secret, 'sha1');
    off := get_byte(h, 19) & 15;
    bin := ((get_byte(h, off) & 127)::bigint << 24)
         | (get_byte(h, off + 1)::bigint << 16)
         | (get_byte(h, off + 2)::bigint << 8)
         |  get_byte(h, off + 3)::bigint;
    IF lpad((bin % 1000000)::text, 6, '0') = p_code THEN
      RETURN step;
    END IF;
  END LOOP;
  RETURN NULL;
END $$;

CREATE FUNCTION mt_staff_revoke_sessions(p_staff uuid, p_reason text, p_except_hash text DEFAULT NULL)
RETURNS int LANGUAGE plpgsql AS $$
DECLARE n int;
BEGIN
  UPDATE mt_staff_sessions
     SET revoked_at = now(), revoked_reason = p_reason
   WHERE staff_id = p_staff AND revoked_at IS NULL
     AND (p_except_hash IS NULL OR token_hash <> p_except_hash);
  GET DIAGNOSTICS n = ROW_COUNT;
  RETURN n;
END $$;

-- The one INSERT path. Bootstrap and create both come here.
CREATE FUNCTION mt_staff_insert(p_username text, p_display text, p_role text,
                                p_password text, p_actor text, p_detail jsonb)
RETURNS uuid LANGUAGE plpgsql AS $$
DECLARE
  pol record;
  v_id uuid;
BEGIN
  SELECT * INTO pol FROM mt_staff_policy();
  IF p_password IS NULL OR length(p_password) < pol.min_password_length THEN
    RAISE EXCEPTION 'password shorter than % characters', pol.min_password_length
      USING ERRCODE = 'check_violation';
  END IF;
  IF p_display IS NULL OR btrim(p_display) = '' THEN
    RAISE EXCEPTION 'display name is required' USING ERRCODE = 'check_violation';
  END IF;
  INSERT INTO mt_staff (username, display_name, role, password_hash, created_by)
  VALUES (lower(btrim(p_username)), btrim(p_display), p_role,
          crypt(p_password, gen_salt('bf', pol.bcrypt_cost)), p_actor)
  RETURNING id INTO v_id;
  PERFORM mt_audit_write(NULL, p_actor, 'staff', 'staff.created', 'staff', v_id::text, NULL,
    jsonb_build_object('staff_id', v_id, 'username', lower(btrim(p_username)), 'role', p_role) || coalesce(p_detail, '{}'::jsonb));
  RETURN v_id;
END $$;

-- ---------------------------------------------------------------------------
-- 6. Authentication — EXECUTE: dnb_staffauth only
-- ---------------------------------------------------------------------------

-- One transaction: lockout check, bcrypt, TOTP, counters, session row, audit.
-- Every failing path returns the EMPTY SET and evaluates a bcrypt, so an
-- unknown username, a wrong password, a wrong code, a locked account and a
-- disabled account are indistinguishable from outside.
CREATE FUNCTION mt_staff_login(p_username text, p_password text, p_totp text,
                               p_token_hash text, p_source text)
RETURNS TABLE (staff_id uuid, staff_username text, staff_role text, factor text,
               totp_enrolled boolean, expires_at timestamptz)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
  pol       record;
  s         record;
  v_now     timestamptz := now();
  v_ok      boolean;
  v_step    bigint;
  v_att     int;
  v_lock    interval;
  v_factor  text;
  v_sess    uuid;
BEGIN
  SELECT * INTO pol FROM mt_staff_policy();
  IF p_token_hash IS NULL OR length(p_token_hash) < 32 THEN
    RAISE EXCEPTION 'a session token hash is required' USING ERRCODE = 'check_violation';
  END IF;

  SELECT * INTO s FROM mt_staff WHERE username = lower(btrim(coalesce(p_username, ''))) FOR UPDATE;

  -- Unknown, disabled or locked: spend a bcrypt anyway, then say nothing.
  IF NOT FOUND OR s.status <> 'active'
     OR (s.locked_until IS NOT NULL AND s.locked_until > v_now) THEN
    PERFORM crypt(coalesce(p_password, ''), gen_salt('bf', pol.bcrypt_cost));
    RETURN;
  END IF;

  v_ok := (crypt(coalesce(p_password, ''), s.password_hash) = s.password_hash);
  IF v_ok AND s.totp_confirmed_at IS NOT NULL THEN
    v_step := mt_staff_totp_step(s.totp_secret, p_totp, v_now, s.totp_last_step);
    v_ok   := v_step IS NOT NULL;
  END IF;

  IF NOT v_ok THEN
    -- Decay: a failure older than the window restarts the count.
    v_att := CASE WHEN s.last_failed_at IS NULL OR s.last_failed_at < v_now - pol.failure_decay
                  THEN 0 ELSE s.failed_attempts END + 1;
    IF v_att >= pol.lockout_threshold THEN
      v_lock := LEAST(pol.lockout_base * power(2, s.lock_count), pol.lockout_ceiling);
      UPDATE mt_staff
         SET failed_attempts = 0, lock_count = lock_count + 1,
             locked_until = v_now + v_lock, last_failed_at = v_now
       WHERE id = s.id;
      PERFORM mt_audit_write(NULL, s.username, 'staff', 'staff.locked', 'staff', s.id::text, p_source,
        jsonb_build_object('staff_id', s.id, 'locked_for_seconds', extract(epoch FROM v_lock)::int,
                           'lock_count', s.lock_count + 1));
    ELSE
      UPDATE mt_staff SET failed_attempts = v_att, last_failed_at = v_now WHERE id = s.id;
    END IF;
    RETURN;
  END IF;

  v_factor := CASE WHEN s.totp_confirmed_at IS NOT NULL THEN 'password+totp' ELSE 'password' END;
  INSERT INTO mt_staff_sessions (staff_id, token_hash, factor, expires_at, source)
  VALUES (s.id, p_token_hash, v_factor, v_now + pol.session_ttl, p_source)
  RETURNING id INTO v_sess;
  UPDATE mt_staff
     SET last_login_at = v_now, failed_attempts = 0, lock_count = 0, locked_until = NULL,
         totp_last_step = coalesce(v_step, totp_last_step)
   WHERE id = s.id;
  PERFORM mt_audit_write(NULL, s.username, 'staff', 'staff.login', 'staff', s.id::text, p_source,
    jsonb_build_object('staff_id', s.id, 'session_id', v_sess, 'factor', v_factor));

  RETURN QUERY SELECT s.id, s.username, s.role, v_factor,
                      s.totp_confirmed_at IS NOT NULL, v_now + pol.session_ttl;
END $$;

-- Pure read. Status and role are re-read LIVE, so disabling a person or
-- changing a role is enforced on the very next request even for a session
-- row a later bug forgot to revoke.
CREATE FUNCTION mt_staff_session_resolve(p_token_hash text)
RETURNS TABLE (staff_id uuid, staff_username text, staff_role text, factor text,
               totp_enrolled boolean, expires_at timestamptz)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT s.id, s.username, s.role, ss.factor, s.totp_confirmed_at IS NOT NULL, ss.expires_at
    FROM mt_staff_sessions ss
    JOIN mt_staff s ON s.id = ss.staff_id
   WHERE ss.token_hash = p_token_hash
     AND ss.revoked_at IS NULL
     AND ss.expires_at > now()
     AND s.status = 'active';
$$;

CREATE FUNCTION mt_staff_logout(p_token_hash text)
RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_staff uuid; v_user text; v_sess uuid;
BEGIN
  UPDATE mt_staff_sessions ss
     SET revoked_at = now(), revoked_reason = 'logout'
    FROM mt_staff s
   WHERE ss.token_hash = p_token_hash AND ss.revoked_at IS NULL AND s.id = ss.staff_id
  RETURNING s.id, s.username, ss.id INTO v_staff, v_user, v_sess;
  IF NOT FOUND THEN RETURN false; END IF;   -- already revoked or unknown: nothing to audit
  PERFORM mt_audit_write(NULL, v_user, 'staff', 'staff.logout', 'staff', v_staff::text, NULL,
    jsonb_build_object('staff_id', v_staff, 'session_id', v_sess));
  RETURN true;
END $$;

-- ---------------------------------------------------------------------------
-- 7. Lifecycle — EXECUTE: dnb_adminwrite only; the route guard is staff.manage
-- ---------------------------------------------------------------------------

-- The first administrator. Refuses once anyone exists; forces the admin role.
CREATE FUNCTION mt_staff_bootstrap(p_username text, p_display text, p_password text, p_actor text)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
  IF EXISTS (SELECT 1 FROM mt_staff) THEN
    RAISE EXCEPTION 'staff already exist; bootstrap creates only the first administrator'
      USING ERRCODE = 'check_violation';
  END IF;
  RETURN mt_staff_insert(p_username, p_display, 'admin', p_password, p_actor,
                         jsonb_build_object('bootstrap', true));
END $$;

CREATE FUNCTION mt_staff_create(p_username text, p_display text, p_role text, p_password text, p_actor text)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
  RETURN mt_staff_insert(p_username, p_display, p_role, p_password, p_actor, NULL);
END $$;

CREATE FUNCTION mt_staff_disable(p_staff uuid, p_actor text)
RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE s record; n int;
BEGIN
  SELECT * INTO s FROM mt_staff WHERE id = p_staff FOR UPDATE;
  IF NOT FOUND OR s.status <> 'active' THEN RETURN false; END IF;
  IF s.username = p_actor THEN
    RAISE EXCEPTION 'a staff member cannot disable itself' USING ERRCODE = 'check_violation';
  END IF;
  IF s.role = 'admin' AND (SELECT count(*) FROM mt_staff WHERE role = 'admin' AND status = 'active') <= 1 THEN
    RAISE EXCEPTION 'the last active admin cannot be disabled' USING ERRCODE = 'check_violation';
  END IF;
  UPDATE mt_staff SET status = 'disabled', disabled_at = now(), disabled_by = p_actor WHERE id = s.id;
  n := mt_staff_revoke_sessions(s.id, 'disabled');
  PERFORM mt_audit_write(NULL, p_actor, 'staff', 'staff.disabled', 'staff', s.id::text, NULL,
    jsonb_build_object('staff_id', s.id, 'username', s.username, 'sessions_revoked', n));
  RETURN true;
END $$;

CREATE FUNCTION mt_staff_enable(p_staff uuid, p_actor text)
RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE s record;
BEGIN
  SELECT * INTO s FROM mt_staff WHERE id = p_staff FOR UPDATE;
  IF NOT FOUND OR s.status <> 'disabled' THEN RETURN false; END IF;
  UPDATE mt_staff SET status = 'active', disabled_at = NULL, disabled_by = NULL,
                      failed_attempts = 0, lock_count = 0, locked_until = NULL
   WHERE id = s.id;
  PERFORM mt_audit_write(NULL, p_actor, 'staff', 'staff.enabled', 'staff', s.id::text, NULL,
    jsonb_build_object('staff_id', s.id, 'username', s.username));
  RETURN true;
END $$;

CREATE FUNCTION mt_staff_set_role(p_staff uuid, p_role text, p_actor text)
RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE s record; n int;
BEGIN
  SELECT * INTO s FROM mt_staff WHERE id = p_staff FOR UPDATE;
  IF NOT FOUND THEN RETURN false; END IF;
  IF s.role = p_role THEN RETURN false; END IF;          -- no change, no audit
  IF s.role = 'admin' AND s.status = 'active'
     AND (SELECT count(*) FROM mt_staff WHERE role = 'admin' AND status = 'active') <= 1 THEN
    RAISE EXCEPTION 'the last active admin cannot be demoted' USING ERRCODE = 'check_violation';
  END IF;
  UPDATE mt_staff SET role = p_role WHERE id = s.id;     -- the CHECK refuses an unknown role
  n := mt_staff_revoke_sessions(s.id, 'role_changed');
  PERFORM mt_audit_write(NULL, p_actor, 'staff', 'staff.role_changed', 'staff', s.id::text, NULL,
    jsonb_build_object('staff_id', s.id, 'username', s.username, 'previous', s.role, 'new', p_role,
                       'sessions_revoked', n));
  RETURN true;
END $$;

-- An admin never chooses a colleague's password: the application generates
-- one, passes it here, and shows it once. It is never logged or audited.
CREATE FUNCTION mt_staff_reset_password(p_staff uuid, p_password text, p_actor text)
RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE pol record; s record; n int;
BEGIN
  SELECT * INTO pol FROM mt_staff_policy();
  SELECT * INTO s FROM mt_staff WHERE id = p_staff FOR UPDATE;
  IF NOT FOUND THEN RETURN false; END IF;
  IF p_password IS NULL OR length(p_password) < pol.min_password_length THEN
    RAISE EXCEPTION 'password shorter than % characters', pol.min_password_length
      USING ERRCODE = 'check_violation';
  END IF;
  UPDATE mt_staff SET password_hash = crypt(p_password, gen_salt('bf', pol.bcrypt_cost)),
                      password_set_at = now(), failed_attempts = 0, lock_count = 0, locked_until = NULL
   WHERE id = s.id;
  n := mt_staff_revoke_sessions(s.id, 'password_reset');
  PERFORM mt_audit_write(NULL, p_actor, 'staff', 'staff.password_reset', 'staff', s.id::text, NULL,
    jsonb_build_object('staff_id', s.id, 'username', s.username, 'sessions_revoked', n));
  RETURN true;
END $$;

CREATE FUNCTION mt_staff_totp_reset(p_staff uuid, p_actor text)
RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE s record; n int;
BEGIN
  SELECT * INTO s FROM mt_staff WHERE id = p_staff FOR UPDATE;
  IF NOT FOUND OR s.totp_secret IS NULL THEN RETURN false; END IF;
  UPDATE mt_staff SET totp_secret = NULL, totp_confirmed_at = NULL, totp_last_step = NULL WHERE id = s.id;
  n := mt_staff_revoke_sessions(s.id, 'totp_reset');
  PERFORM mt_audit_write(NULL, p_actor, 'staff', 'staff.totp_reset', 'staff', s.id::text, NULL,
    jsonb_build_object('staff_id', s.id, 'username', s.username, 'sessions_revoked', n));
  RETURN true;
END $$;

-- ---------------------------------------------------------------------------
-- 8. Self-service — EXECUTE: dnb_adminwrite; the caller is identified by ITS
--    OWN SESSION TOKEN, never by an id in the request.
-- ---------------------------------------------------------------------------
CREATE FUNCTION mt_staff_change_password(p_token_hash text, p_current text, p_new text)
RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE pol record; s record; n int;
BEGIN
  SELECT * INTO pol FROM mt_staff_policy();
  SELECT st.* INTO s
    FROM mt_staff_sessions ss JOIN mt_staff st ON st.id = ss.staff_id
   WHERE ss.token_hash = p_token_hash AND ss.revoked_at IS NULL AND ss.expires_at > now()
     AND st.status = 'active'
   FOR UPDATE OF st;
  IF NOT FOUND THEN RETURN false; END IF;
  IF crypt(coalesce(p_current, ''), s.password_hash) <> s.password_hash THEN RETURN false; END IF;
  IF p_new IS NULL OR length(p_new) < pol.min_password_length THEN
    RAISE EXCEPTION 'password shorter than % characters', pol.min_password_length
      USING ERRCODE = 'check_violation';
  END IF;
  UPDATE mt_staff SET password_hash = crypt(p_new, gen_salt('bf', pol.bcrypt_cost)), password_set_at = now()
   WHERE id = s.id;
  n := mt_staff_revoke_sessions(s.id, 'password_changed', p_token_hash);   -- every OTHER session
  PERFORM mt_audit_write(NULL, s.username, 'staff', 'staff.password_changed', 'staff', s.id::text, NULL,
    jsonb_build_object('staff_id', s.id, 'other_sessions_revoked', n));
  RETURN true;
END $$;

-- Returns the NEW secret exactly once, to the session that asked. Nothing is
-- audited until it is confirmed; an unconfirmed secret changes no state.
CREATE FUNCTION mt_staff_totp_enrol(p_token_hash text)
RETURNS bytea LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE s record; v_secret bytea;
BEGIN
  SELECT st.* INTO s
    FROM mt_staff_sessions ss JOIN mt_staff st ON st.id = ss.staff_id
   WHERE ss.token_hash = p_token_hash AND ss.revoked_at IS NULL AND ss.expires_at > now()
     AND st.status = 'active'
   FOR UPDATE OF st;
  IF NOT FOUND THEN RETURN NULL; END IF;
  IF s.totp_confirmed_at IS NOT NULL THEN
    RAISE EXCEPTION 'a second factor is already enrolled; an administrator must reset it'
      USING ERRCODE = 'check_violation';
  END IF;
  v_secret := gen_random_bytes(20);
  UPDATE mt_staff SET totp_secret = v_secret, totp_last_step = NULL WHERE id = s.id;
  RETURN v_secret;
END $$;

CREATE FUNCTION mt_staff_totp_confirm(p_token_hash text, p_code text)
RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE s record; v_step bigint;
BEGIN
  SELECT st.* INTO s
    FROM mt_staff_sessions ss JOIN mt_staff st ON st.id = ss.staff_id
   WHERE ss.token_hash = p_token_hash AND ss.revoked_at IS NULL AND ss.expires_at > now()
     AND st.status = 'active'
   FOR UPDATE OF st;
  IF NOT FOUND OR s.totp_secret IS NULL OR s.totp_confirmed_at IS NOT NULL THEN RETURN false; END IF;
  v_step := mt_staff_totp_step(s.totp_secret, p_code, now(), NULL);
  IF v_step IS NULL THEN RETURN false; END IF;
  UPDATE mt_staff SET totp_confirmed_at = now(), totp_last_step = v_step WHERE id = s.id;
  -- The session that enrolled is now a two-factor session.
  UPDATE mt_staff_sessions SET factor = 'password+totp' WHERE token_hash = p_token_hash;
  PERFORM mt_audit_write(NULL, s.username, 'staff', 'staff.totp_enrolled', 'staff', s.id::text, NULL,
    jsonb_build_object('staff_id', s.id));
  RETURN true;
END $$;

-- ---------------------------------------------------------------------------
-- 9. The Admin read projection — EXECUTE: dnb_adminapi. No hash, no secret.
-- ---------------------------------------------------------------------------
CREATE FUNCTION mt_admin_staff()
RETURNS TABLE (id uuid, username text, display_name text, role text, status text,
               totp_enrolled boolean, created_at timestamptz, last_login_at timestamptz,
               disabled_at timestamptz)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT s.id, s.username, s.display_name, s.role, s.status, s.totp_confirmed_at IS NOT NULL,
         s.created_at, s.last_login_at, s.disabled_at
    FROM mt_staff s ORDER BY s.username;
$$;

-- ---------------------------------------------------------------------------
-- 10. Grants — exactly the table in docs/114 §C.2 as resolved in §N R-1
-- ---------------------------------------------------------------------------
DO $$
DECLARE f text;
BEGIN
  FOREACH f IN ARRAY ARRAY[
    'mt_staff_policy()',
    'mt_staff_totp_step(bytea,text,timestamptz,bigint)',
    'mt_staff_revoke_sessions(uuid,text,text)',
    'mt_staff_insert(text,text,text,text,text,jsonb)',
    'mt_staff_login(text,text,text,text,text)',
    'mt_staff_session_resolve(text)',
    'mt_staff_logout(text)',
    'mt_staff_bootstrap(text,text,text,text)',
    'mt_staff_create(text,text,text,text,text)',
    'mt_staff_disable(uuid,text)',
    'mt_staff_enable(uuid,text)',
    'mt_staff_set_role(uuid,text,text)',
    'mt_staff_reset_password(uuid,text,text)',
    'mt_staff_totp_reset(uuid,text)',
    'mt_staff_change_password(text,text,text)',
    'mt_staff_totp_enrol(text)',
    'mt_staff_totp_confirm(text,text)',
    'mt_admin_staff()'] LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC', f);
  END LOOP;
END $$;

-- D-AUTH-1: the authentication role, and nothing else.
GRANT EXECUTE ON FUNCTION mt_staff_login(text,text,text,text,text)   TO dnb_staffauth;
GRANT EXECUTE ON FUNCTION mt_staff_session_resolve(text)             TO dnb_staffauth;
GRANT EXECUTE ON FUNCTION mt_staff_logout(text)                      TO dnb_staffauth;
-- Lifecycle and self-service: the Admin write connection (§N R-1).
GRANT EXECUTE ON FUNCTION mt_staff_bootstrap(text,text,text,text)     TO dnb_adminwrite;
GRANT EXECUTE ON FUNCTION mt_staff_create(text,text,text,text,text)   TO dnb_adminwrite;
GRANT EXECUTE ON FUNCTION mt_staff_disable(uuid,text)                 TO dnb_adminwrite;
GRANT EXECUTE ON FUNCTION mt_staff_enable(uuid,text)                  TO dnb_adminwrite;
GRANT EXECUTE ON FUNCTION mt_staff_set_role(uuid,text,text)           TO dnb_adminwrite;
GRANT EXECUTE ON FUNCTION mt_staff_reset_password(uuid,text,text)     TO dnb_adminwrite;
GRANT EXECUTE ON FUNCTION mt_staff_totp_reset(uuid,text)              TO dnb_adminwrite;
GRANT EXECUTE ON FUNCTION mt_staff_change_password(text,text,text)    TO dnb_adminwrite;
GRANT EXECUTE ON FUNCTION mt_staff_totp_enrol(text)                   TO dnb_adminwrite;
GRANT EXECUTE ON FUNCTION mt_staff_totp_confirm(text,text)            TO dnb_adminwrite;
-- The read projection: the Admin read connection.
GRANT EXECUTE ON FUNCTION mt_admin_staff()                            TO dnb_adminapi;
-- Policy is not a secret; the roles that act on it may read it.
GRANT EXECUTE ON FUNCTION mt_staff_policy() TO dnb_staffauth, dnb_adminwrite, dnb_adminapi;

COMMENT ON TABLE mt_staff IS
  'DishNet Staff identity (docs/114 G-B). Not a tenant table: no customer_id, no RLS. No login role may read or write it; every access is a definer function owned by dnb_def_staff. password_hash and totp_secret never leave this table except the new TOTP secret, once, at enrolment.';
COMMENT ON TABLE mt_staff_sessions IS
  'Revocable server-side staff sessions. token_hash is an HMAC of the cookie token under a label-derived key; the token itself is never stored. expires_at is absolute.';
COMMENT ON FUNCTION mt_staff_login(text,text,text,text,text) IS
  'docs/114 G-B. One transaction: decaying lockout, bcrypt, TOTP, counters, session row, audit. Every failing path returns the empty set after a bcrypt, so failures are indistinguishable.';

RESET ROLE;

-- No definer role holds CREATE at rest.
REVOKE CREATE ON SCHEMA public FROM dnb_def_staff;
