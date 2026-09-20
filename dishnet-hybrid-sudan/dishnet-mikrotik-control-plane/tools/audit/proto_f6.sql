-- Disposable F6 voucher-redemption actor prototype. pf_* objects, proto_* roles.
-- NOT production. Creates nothing in dnb_test and touches no mt_* object.
--
-- What it models, and why each piece exists:
--
--   the portal role   -- redemption is performed by someone who has no DishNet
--                        account (docs/45 2.1: a HotSpot user cannot sign into
--                        the PWA). That actor therefore cannot be the
--                        customer-authenticated request role.
--   the return shape  -- the guest is unauthenticated. What comes back to an
--                        unauthenticated caller is a product decision, not a
--                        consequence of what the current function happens to
--                        SELECT.
--   the log           -- a failed redemption is the interesting one. A code is
--                        a bearer credential; attempts against codes that do
--                        not exist are the only visible sign of guessing.
--   the mode switch   -- site binding is modelled as A, B and C at once so the
--                        three can be measured side by side rather than one
--                        being chosen here.
CREATE EXTENSION IF NOT EXISTS pgcrypto;
GRANT USAGE ON SCHEMA public TO proto_portal, proto_cust, proto_rad6, proto_adm6, proto_def6;

CREATE TABLE pf_customers (id uuid PRIMARY KEY, name text UNIQUE, radius_ref text UNIQUE);
CREATE TABLE pf_sites (id uuid PRIMARY KEY, customer_id uuid NOT NULL REFERENCES pf_customers(id),
                       name text, nas_identifier text UNIQUE);

CREATE TABLE pf_vouchers (
  id          uuid PRIMARY KEY,
  customer_id uuid NOT NULL REFERENCES pf_customers(id),
  site_id     uuid REFERENCES pf_sites(id),
  code        text NOT NULL UNIQUE,
  state       text NOT NULL DEFAULT 'unused' CHECK (state IN ('unused','active','expired','revoked')),
  duration_s  integer NOT NULL,
  activated_at timestamptz,
  expires_at   timestamptz);

-- Mirrors production: the AAA row is written at ISSUE, not at redemption.
CREATE TABLE pf_hotspot_users (voucher_id uuid PRIMARY KEY REFERENCES pf_vouchers(id),
                               customer_id uuid NOT NULL, radius_username text NOT NULL UNIQUE);

CREATE TABLE pf_sessions (id bigserial PRIMARY KEY, customer_id uuid NOT NULL,
                          voucher_id uuid, radius_username text, octets bigint NOT NULL DEFAULT 0);

-- The audit record. customer_id is NULLABLE on purpose: an attempt against a
-- code that does not exist belongs to no tenant, and refusing to record it is
-- how code-guessing stays invisible.
CREATE TABLE pf_redemption_log (
  id           bigserial PRIMARY KEY,
  at           timestamptz NOT NULL DEFAULT now(),
  actor_kind   text NOT NULL CHECK (actor_kind IN ('guest','principal','staff','system')),
  actor        text NOT NULL,              -- portal/NAS identity, or admin name
  nas          text,                       -- where it was presented
  -- NOT the code. A code is a live bearer credential until it expires, and a
  -- guest who mistypes one digit can write SOMEONE ELSE'S valid code into a
  -- log that support staff can read. The prefix is enough to spot a guessing
  -- run; the hash is enough to correlate repeat attempts on the same code.
  code_prefix  text,
  code_hash    text,
  voucher_id   uuid,                       -- NULL when the code matched nothing
  customer_id  uuid,                       -- NULL when the code matched nothing
  site_id      uuid,                       -- the site resolved from the NAS
  outcome      text NOT NULL,
  detail       jsonb NOT NULL DEFAULT '{}');

CREATE TABLE pf_settings (k text PRIMARY KEY, v text NOT NULL);
INSERT INTO pf_settings VALUES ('site_binding_mode','A');

ALTER TABLE pf_customers      OWNER TO proto_def6;
ALTER TABLE pf_sites          OWNER TO proto_def6;
ALTER TABLE pf_vouchers       OWNER TO proto_def6;
ALTER TABLE pf_hotspot_users  OWNER TO proto_def6;
ALTER TABLE pf_sessions       OWNER TO proto_def6;
ALTER TABLE pf_redemption_log OWNER TO proto_def6;
ALTER TABLE pf_settings       OWNER TO proto_def6;
ALTER SEQUENCE pf_sessions_id_seq        OWNER TO proto_def6;
ALTER SEQUENCE pf_redemption_log_id_seq  OWNER TO proto_def6;

-- ---------------------------------------------------------------------------
-- GUEST / CAPTIVE PORTAL
--
-- Signature is (code, nas). There is no customer argument and no site
-- argument: the only thing the caller may assert is the code they hold and
-- the router they are standing in front of. Both are then RESOLVED, never
-- trusted as authority.
--
-- Returns no voucher_id and no customer_id. An unauthenticated caller learns
-- only what their own session needs.
-- ---------------------------------------------------------------------------
CREATE FUNCTION pf_portal_redeem(p_code text, p_nas text)
RETURNS TABLE (ok boolean, reason text, radius_username text, duration_s integer, expires_at timestamptz)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $fn$
DECLARE v record; s record; mode text; new_site uuid; bind_ok boolean; why text;
BEGIN
  SELECT v_setting.v INTO mode FROM pf_settings v_setting WHERE k = 'site_binding_mode';

  SELECT * INTO s FROM pf_sites WHERE nas_identifier = p_nas;

  SELECT * INTO v FROM pf_vouchers WHERE code = upper(btrim(p_code));

  IF NOT FOUND THEN
    INSERT INTO pf_redemption_log (actor_kind, actor, nas, code_prefix, code_hash, site_id, outcome)
    VALUES ('guest','portal',p_nas,left(upper(btrim(p_code)),5),encode(digest(upper(btrim(p_code)),'sha256'),'hex'),s.id,'unknown_code');
    RETURN QUERY SELECT false, 'invalid'::text, NULL::text, NULL::integer, NULL::timestamptz;
    RETURN;
  END IF;

  IF s.id IS NULL THEN
    INSERT INTO pf_redemption_log (actor_kind,actor,nas,code_prefix, code_hash,voucher_id,customer_id,outcome)
    VALUES ('guest','portal',p_nas,left(v.code,5),encode(digest(v.code,'sha256'),'hex'),v.id,v.customer_id,'unknown_nas');
    RETURN QUERY SELECT false, 'invalid'::text, NULL::text, NULL::integer, NULL::timestamptz;
    RETURN;
  END IF;

  IF v.state <> 'unused' THEN
    INSERT INTO pf_redemption_log (actor_kind,actor,nas,code_prefix, code_hash,voucher_id,customer_id,site_id,outcome,detail)
    VALUES ('guest','portal',p_nas,left(v.code,5),encode(digest(v.code,'sha256'),'hex'),v.id,v.customer_id,s.id,'already_'||v.state,
            jsonb_build_object('activated_at',v.activated_at));
    -- Same reason string as unknown_code. The portal must not become an oracle
    -- that tells a guesser which codes exist.
    RETURN QUERY SELECT false, 'invalid'::text, NULL::text, NULL::integer, NULL::timestamptz;
    RETURN;
  END IF;

  -- Tenant rule, common to all three binding modes: a voucher is only ever
  -- valid at a site belonging to the customer that issued it. Q's router must
  -- never serve P's guest on Q's uplink.
  IF s.customer_id <> v.customer_id THEN
    INSERT INTO pf_redemption_log (actor_kind,actor,nas,code_prefix, code_hash,voucher_id,customer_id,site_id,outcome)
    VALUES ('guest','portal',p_nas,left(v.code,5),encode(digest(v.code,'sha256'),'hex'),v.id,v.customer_id,s.id,'foreign_tenant');
    RETURN QUERY SELECT false, 'invalid'::text, NULL::text, NULL::integer, NULL::timestamptz;
    RETURN;
  END IF;

  bind_ok := true; why := NULL; new_site := v.site_id;
  IF mode = 'A' THEN                    -- bound at ISSUE
    IF v.site_id IS NULL THEN bind_ok := false; why := 'unbound_voucher';
    ELSIF v.site_id <> s.id THEN bind_ok := false; why := 'wrong_site'; END IF;
  ELSIF mode = 'B' THEN                 -- bound at REDEMPTION
    IF v.site_id IS NULL THEN new_site := s.id;
    ELSIF v.site_id <> s.id THEN bind_ok := false; why := 'already_bound_elsewhere'; END IF;
  ELSIF mode = 'C' THEN                 -- not bound; any site of the owner
    new_site := COALESCE(v.site_id, s.id);
  END IF;

  IF NOT bind_ok THEN
    INSERT INTO pf_redemption_log (actor_kind,actor,nas,code_prefix, code_hash,voucher_id,customer_id,site_id,outcome,detail)
    VALUES ('guest','portal',p_nas,left(v.code,5),encode(digest(v.code,'sha256'),'hex'),v.id,v.customer_id,s.id,why,
            jsonb_build_object('mode',mode,'voucher_site',v.site_id));
    RETURN QUERY SELECT false, 'invalid'::text, NULL::text, NULL::integer, NULL::timestamptz;
    RETURN;
  END IF;

  -- duration_s is also an OUT parameter of this function, so the source column
  -- must be qualified or PL/pgSQL cannot tell them apart.
  UPDATE pf_vouchers AS tgt SET state='active', activated_at=now(), site_id=new_site,
         expires_at = now() + (tgt.duration_s || ' seconds')::interval
   WHERE tgt.id = v.id AND tgt.state = 'unused';

  IF NOT FOUND THEN   -- lost a concurrent race for the same code
    INSERT INTO pf_redemption_log (actor_kind,actor,nas,code_prefix, code_hash,voucher_id,customer_id,site_id,outcome)
    VALUES ('guest','portal',p_nas,left(v.code,5),encode(digest(v.code,'sha256'),'hex'),v.id,v.customer_id,s.id,'race_lost');
    RETURN QUERY SELECT false, 'invalid'::text, NULL::text, NULL::integer, NULL::timestamptz;
    RETURN;
  END IF;

  SELECT * INTO v FROM pf_vouchers WHERE id = v.id;

  INSERT INTO pf_redemption_log (actor_kind,actor,nas,code_prefix, code_hash,voucher_id,customer_id,site_id,outcome,detail)
  VALUES ('guest','portal',p_nas,left(v.code,5),encode(digest(v.code,'sha256'),'hex'),v.id,v.customer_id,s.id,'redeemed',
          jsonb_build_object('mode',mode,'expires_at',v.expires_at));

  RETURN QUERY
    SELECT true, 'ok'::text, h.radius_username, v.duration_s, v.expires_at
      FROM pf_hotspot_users AS h WHERE h.voucher_id = v.id;
END $fn$;
ALTER FUNCTION pf_portal_redeem(text,text) OWNER TO proto_def6;

-- ---------------------------------------------------------------------------
-- ADMIN support / recovery. A DIFFERENT function, not a flag on the portal one:
-- it returns identifiers, it names a human, and it demands a stated reason.
-- ---------------------------------------------------------------------------
CREATE FUNCTION pf_admin_redeem(p_code text, p_admin text, p_reason text)
RETURNS TABLE (ok boolean, voucher_id uuid, customer_id uuid, expires_at timestamptz)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $fn$
DECLARE v record;
BEGIN
  IF coalesce(btrim(p_reason),'') = '' THEN
    RAISE EXCEPTION 'support redemption requires a stated reason';
  END IF;
  SELECT * INTO v FROM pf_vouchers WHERE code = upper(btrim(p_code));
  IF NOT FOUND THEN
    INSERT INTO pf_redemption_log (actor_kind,actor,code_prefix, code_hash,outcome,detail)
    VALUES ('staff',p_admin,left(upper(btrim(p_code)),5),encode(digest(upper(btrim(p_code)),'sha256'),'hex'),'unknown_code',jsonb_build_object('reason',p_reason));
    RETURN QUERY SELECT false, NULL::uuid, NULL::uuid, NULL::timestamptz; RETURN;
  END IF;
  UPDATE pf_vouchers SET state='active', activated_at=now(),
         expires_at = now() + (duration_s || ' seconds')::interval
   WHERE id = v.id AND state='unused';
  IF NOT FOUND THEN
    INSERT INTO pf_redemption_log (actor_kind,actor,code_prefix, code_hash,voucher_id,customer_id,outcome,detail)
    VALUES ('staff',p_admin,left(v.code,5),encode(digest(v.code,'sha256'),'hex'),v.id,v.customer_id,'already_'||v.state,jsonb_build_object('reason',p_reason));
    RETURN QUERY SELECT false, v.id, v.customer_id, NULL::timestamptz; RETURN;
  END IF;
  SELECT * INTO v FROM pf_vouchers WHERE id = v.id;
  INSERT INTO pf_redemption_log (actor_kind,actor,code_prefix, code_hash,voucher_id,customer_id,site_id,outcome,detail)
  VALUES ('staff',p_admin,left(v.code,5),encode(digest(v.code,'sha256'),'hex'),v.id,v.customer_id,v.site_id,'redeemed',jsonb_build_object('reason',p_reason));
  RETURN QUERY SELECT true, v.id, v.customer_id, v.expires_at;
END $fn$;
ALTER FUNCTION pf_admin_redeem(text,text,text) OWNER TO proto_def6;

-- RADIUS: accounting only. It resolves the customer from the AAA identity and
-- never touches voucher state -- which is exactly what production does today.
CREATE FUNCTION pf_radius_account(p_username text, p_octets bigint)
RETURNS bigint LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $fn$
DECLARE h record; sid bigint;
BEGIN
  SELECT * INTO h FROM pf_hotspot_users WHERE radius_username = p_username;
  IF NOT FOUND THEN RETURN NULL; END IF;
  INSERT INTO pf_sessions (customer_id, voucher_id, radius_username, octets)
  VALUES (h.customer_id, h.voucher_id, p_username, p_octets) RETURNING id INTO sid;
  RETURN sid;
END $fn$;
ALTER FUNCTION pf_radius_account(text,bigint) OWNER TO proto_def6;

-- OPTIONAL, for question 5 only: a customer-operator activation. Deliberately a
-- THIRD function, so that granting it is a separate decision from granting the
-- portal one. It takes a voucher id, never a code -- an operator works from
-- their own list, they do not type a code they were handed.
CREATE FUNCTION pf_operator_activate(p_voucher uuid, p_customer uuid)
RETURNS TABLE (ok boolean, expires_at timestamptz)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $fn$
DECLARE v record;
BEGIN
  SELECT * INTO v FROM pf_vouchers WHERE id = p_voucher AND customer_id = p_customer;
  IF NOT FOUND THEN
    INSERT INTO pf_redemption_log (actor_kind,actor,voucher_id,customer_id,outcome)
    VALUES ('principal','operator',p_voucher,p_customer,'not_yours');
    RETURN QUERY SELECT false, NULL::timestamptz; RETURN;
  END IF;
  UPDATE pf_vouchers SET state='active', activated_at=now(),
         expires_at = now() + (duration_s || ' seconds')::interval
   WHERE id = v.id AND state='unused';
  IF NOT FOUND THEN
    INSERT INTO pf_redemption_log (actor_kind,actor,voucher_id,customer_id,outcome)
    VALUES ('principal','operator',v.id,v.customer_id,'already_'||v.state);
    RETURN QUERY SELECT false, NULL::timestamptz; RETURN;
  END IF;
  SELECT * INTO v FROM pf_vouchers WHERE id = v.id;
  INSERT INTO pf_redemption_log (actor_kind,actor,voucher_id,customer_id,site_id,outcome)
  VALUES ('principal','operator',v.id,v.customer_id,v.site_id,'redeemed');
  RETURN QUERY SELECT true, v.expires_at;
END $fn$;
ALTER FUNCTION pf_operator_activate(uuid,uuid) OWNER TO proto_def6;

-- ---------------------------------------------------------------------------
-- The privilege boundary. CREATE FUNCTION grants EXECUTE to PUBLIC, so every
-- function is revoked from PUBLIC first and then granted by name.
-- ---------------------------------------------------------------------------
REVOKE EXECUTE ON FUNCTION pf_portal_redeem(text,text)      FROM PUBLIC;
REVOKE EXECUTE ON FUNCTION pf_admin_redeem(text,text,text)  FROM PUBLIC;
REVOKE EXECUTE ON FUNCTION pf_radius_account(text,bigint)   FROM PUBLIC;
REVOKE EXECUTE ON FUNCTION pf_operator_activate(uuid,uuid)  FROM PUBLIC;

GRANT EXECUTE ON FUNCTION pf_portal_redeem(text,text)     TO proto_portal;
GRANT EXECUTE ON FUNCTION pf_admin_redeem(text,text,text) TO proto_adm6;
GRANT EXECUTE ON FUNCTION pf_radius_account(text,bigint)  TO proto_rad6;
-- pf_operator_activate is granted to NOBODY by default. Question 5 is a
-- product decision; the prototype shows the grant is separable, not that it
-- should be made.

-- ---------------------------------------------------------------------------
-- The same operator operation written correctly.
--
-- pf_operator_activate above takes the customer as an ARGUMENT, and the case
-- run shows what that costs: the customer role activated another tenant's
-- voucher simply by naming that tenant. It is kept in the prototype as the
-- negative result. The authority here is a session credential the caller
-- holds, resolved server-side, exactly as docs/61 settled for the customer
-- actor -- never a uuid the caller supplies.
-- ---------------------------------------------------------------------------
CREATE TABLE pf_auth_sessions (token_hash text PRIMARY KEY, customer_id uuid NOT NULL,
                               expires_at timestamptz NOT NULL, revoked_at timestamptz);
ALTER TABLE pf_auth_sessions OWNER TO proto_def6;

CREATE FUNCTION pf_current_customer() RETURNS uuid
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $fn$
  SELECT s.customer_id FROM pf_auth_sessions s
   WHERE s.token_hash = encode(digest(coalesce(nullif(current_setting('app.session_key', true),''),'-'),'sha256'),'hex')
     AND s.expires_at > now() AND s.revoked_at IS NULL;
$fn$;
ALTER FUNCTION pf_current_customer() OWNER TO proto_def6;

CREATE FUNCTION pf_operator_activate2(p_voucher uuid)
RETURNS TABLE (ok boolean, expires_at timestamptz)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $fn$
DECLARE v record; me uuid;
BEGIN
  me := pf_current_customer();
  IF me IS NULL THEN RAISE EXCEPTION 'no customer authority'; END IF;
  SELECT * INTO v FROM pf_vouchers vv WHERE vv.id = p_voucher AND vv.customer_id = me;
  IF NOT FOUND THEN
    INSERT INTO pf_redemption_log (actor_kind,actor,voucher_id,customer_id,outcome)
    VALUES ('principal','operator',p_voucher,me,'not_yours');
    RETURN QUERY SELECT false, NULL::timestamptz; RETURN;
  END IF;
  UPDATE pf_vouchers AS tgt SET state='active', activated_at=now(),
         expires_at = now() + (tgt.duration_s || ' seconds')::interval
   WHERE tgt.id = v.id AND tgt.state='unused';
  IF NOT FOUND THEN
    INSERT INTO pf_redemption_log (actor_kind,actor,voucher_id,customer_id,outcome)
    VALUES ('principal','operator',v.id,v.customer_id,'already_'||v.state);
    RETURN QUERY SELECT false, NULL::timestamptz; RETURN;
  END IF;
  SELECT * INTO v FROM pf_vouchers vv WHERE vv.id = v.id;
  INSERT INTO pf_redemption_log (actor_kind,actor,voucher_id,customer_id,site_id,outcome)
  VALUES ('principal','operator',v.id,v.customer_id,v.site_id,'redeemed');
  RETURN QUERY SELECT true, v.expires_at;
END $fn$;
ALTER FUNCTION pf_operator_activate2(uuid) OWNER TO proto_def6;
REVOKE EXECUTE ON FUNCTION pf_operator_activate2(uuid) FROM PUBLIC;
REVOKE EXECUTE ON FUNCTION pf_current_customer()       FROM PUBLIC;
GRANT  EXECUTE ON FUNCTION pf_operator_activate2(uuid) TO proto_cust;
