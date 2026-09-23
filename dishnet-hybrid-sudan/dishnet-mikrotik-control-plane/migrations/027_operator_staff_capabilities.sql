-- 027 — T-2: the Operator Staff capability model (docs/116; C6 / C16)
--
-- WHAT an Operator Owner or Operator Staff member may do, and the floor
-- beneath it. Migration 026 answered "who is DishNet staff"; this file answers
-- the operator plane's own question, inside one operator, under RLS.
--
-- Decided in docs/116 and reviewed in its §J before this file was written:
--
--   D.1  mt_principals.kind becomes owner | staff. The stored value 'operator'
--        is renamed to 'staff' — after T-1 the word Operator means the tenant.
--   D.2  capabilities text[] on the row; inherits the tenant's RLS with it.
--   D.3  mt_op_capabilities() — the ONE canonical list. PHP's OpCapability::ALL
--        is asserted equal by the suite.
--   D.4  CHECK: every stored capability is a known one.
--   D.5  CHECK: an owner's list is EMPTY (kind implies everything); a staff
--        member can NEVER hold op.staff.manage.
--   D.6  REVOKE INSERT, UPDATE, DELETE ON mt_principals FROM dnb_app — the
--        measured hole (docs/116 §0): the HTTP role could rewrite a principal's
--        kind and forge an owner inside its own tenant. After this file the
--        only writers are the definer functions below.
--   D.7  mt_principal_can(): RLS-bound because its owner has no widening policy.
--   D.8  the operator-plane writers, owner dnb_def_comm, EXECUTE dnb_app:
--        the actor must be an ACTIVE OWNER of the same tenant, checked inside;
--        the last active owner cannot be disabled or demoted; disable revokes
--        every live session of that principal in the same transaction.
--   D.9  mt_admin_principal_create(target operator, ...) — the Admin plane,
--        owner dnb_def_prov (measured, §J J-3), EXECUTE dnb_adminwrite. The
--        first owner of a new operator can be created by nobody else.
--   D.10 mt_auth_resolve_token() returns kind and capabilities, re-read LIVE
--        on every request: a demotion or a removed capability takes effect on
--        the very next request, and a disabled principal's session is gone.
--   D.11 the six commercial functions gain the capability floor: a refusal is
--        SQLSTATE 42501 raised BEFORE the mutation, so no audit row exists.
--   D.12 mt_admin_principals() — phone, email and credential_hash withheld.
--
-- THE DATA REWRITE RUNS AS dnb_def_auth, NOT AS THE OWNER (§J J-4). Since 017
-- the owner is not a superuser and mt_principals has FORCE RLS: with no tenant
-- context the owner sees ZERO rows, so an UPDATE by the owner would rewrite
-- nothing and report success. dnb_def_auth holds UPDATE USING (true) and sees
-- every row. The count rewritten is reported, and the absence of any remaining
-- 'operator' value is asserted before the CHECK is tightened. Measured
-- separately: CHECK validation itself is NOT blind under FORCE RLS — it scans
-- the heap — so the tightened CHECK would refuse a leftover row anyway. The
-- production count is a census line (docs/79); this file is not authorised for
-- production any more than 020–026 are.
--
-- THE NAMING RULE (docs/116 §G): mt_audit_log.actor_kind = 'staff' means
-- DishNet staff and nothing else. Every operator person — owner or staff — is
-- actor_kind = 'principal', with detail.principal_kind saying which. Every
-- mt_audit_write() call below passes a LITERAL actor kind; a test forbids any
-- call that derives it from a principal's kind column.
-- ---------------------------------------------------------------------------

-- ---------------------------------------------------------------------------
-- 1. kind: widen the CHECK, rewrite as a role that sees every row, tighten
-- ---------------------------------------------------------------------------
ALTER TABLE mt_principals DROP CONSTRAINT mt_principals_kind_check;
ALTER TABLE mt_principals ADD CONSTRAINT mt_principals_kind_check
  CHECK (kind IN ('owner','operator','staff'));

SET LOCAL ROLE dnb_def_auth;
DO $$
DECLARE r record; n integer;
BEGIN
  -- The census this migration performs on the database it runs against. The
  -- production figure belongs to docs/79, obtained BEFORE this file is applied.
  FOR r IN SELECT kind, status, count(*) AS c FROM mt_principals GROUP BY 1, 2 ORDER BY 1, 2 LOOP
    RAISE NOTICE '027 census — principals kind=% status=%: %', r.kind, r.status, r.c;
  END LOOP;
  UPDATE mt_principals SET kind = 'staff' WHERE kind = 'operator';
  GET DIAGNOSTICS n = ROW_COUNT;
  RAISE NOTICE '027 — rewrote % principal row(s) from operator to staff', n;
  IF EXISTS (SELECT 1 FROM mt_principals WHERE kind = 'operator') THEN
    RAISE EXCEPTION '027 — a principal with kind = operator remains after the rewrite; refusing';
  END IF;
END $$;
RESET ROLE;

ALTER TABLE mt_principals DROP CONSTRAINT mt_principals_kind_check;
ALTER TABLE mt_principals ADD CONSTRAINT mt_principals_kind_check
  CHECK (kind IN ('owner','staff'));

-- ---------------------------------------------------------------------------
-- 2. The canonical capability list, the column, and the two invariants
-- ---------------------------------------------------------------------------
-- Owned by the migration owner. A CHECK expression runs as whoever writes the
-- row, so EXECUTE goes to exactly the three definer roles that ever write or
-- update mt_principals — dnb_def_comm (the operator-plane writers and the
-- floor), dnb_def_prov (mt_admin_principal_create) and dnb_def_auth (007's
-- last_login_at UPDATE re-evaluates every CHECK) — and to nobody else. The
-- owner keeps it implicitly, which is what validates the constraint below.
-- No mt_ function is PUBLIC-executable (test_isolation_s1_s2), this one
-- included: it names no secret, but the rule has no exceptions.
CREATE FUNCTION mt_op_capabilities()
RETURNS text[] LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$
  SELECT ARRAY[
    'op.profile.read',  'op.profile.write',
    'op.locations.read', 'op.routers.read', 'op.intents.read',
    'op.plans.read',    'op.plans.write',
    'op.vouchers.read', 'op.vouchers.issue', 'op.vouchers.revoke',
    'op.sessions.read', 'op.sessions.disconnect',
    'op.reports.read',  'op.sales.read', 'op.billing.read', 'op.audit.read',
    'op.staff.manage'
  ]::text[]
$$;
REVOKE ALL ON FUNCTION mt_op_capabilities() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mt_op_capabilities() TO dnb_def_comm, dnb_def_prov, dnb_def_auth;

ALTER TABLE mt_principals ADD COLUMN capabilities text[] NOT NULL DEFAULT '{}';

-- An unknown capability is a constraint violation, not a silent no-op.
ALTER TABLE mt_principals ADD CONSTRAINT mt_principals_capabilities_known
  CHECK (capabilities <@ mt_op_capabilities());

-- Owner implies all, so its list is empty; staff can never hold staff management.
ALTER TABLE mt_principals ADD CONSTRAINT mt_principals_kind_capabilities
  CHECK ((kind = 'owner' AND capabilities = '{}')
      OR (kind = 'staff' AND NOT ('op.staff.manage' = ANY (capabilities))));

COMMENT ON COLUMN mt_principals.kind IS
  'owner | staff (docs/116, C6/C16 closed). owner implies every op.* capability; staff holds exactly capabilities[]. This is the OPERATOR plane: it is never mapped onto mt_audit_log.actor_kind, where every principal is ''principal''.';
COMMENT ON COLUMN mt_principals.capabilities IS
  'docs/116 §A. A subset of mt_op_capabilities(); empty for an owner by constraint; never contains op.staff.manage for a staff member. Written only by the mt_principal_* and mt_admin_principal_* functions.';

-- ---------------------------------------------------------------------------
-- 3. The floor: the HTTP role loses every write on the identity table
-- ---------------------------------------------------------------------------
-- SELECT stays: /me and /me/staff are ordinary RLS-scoped reads. The default
-- privileges were already closed for dnb_app by 024 (INSERT) and 025
-- (UPDATE, DELETE), so nothing here will re-open on the next table.
REVOKE INSERT, UPDATE, DELETE ON mt_principals FROM dnb_app;

-- ---------------------------------------------------------------------------
-- 4. What the definer roles may reach, and the two new policies
-- ---------------------------------------------------------------------------
-- dnb_def_comm gets the writes and NO policy of its own: it stays bound by
-- mt_principals_isolation exactly as dnb_app is, so the writers below are
-- tenant-scoped below the function (the 024 property, unchanged).
GRANT INSERT, UPDATE ON mt_principals TO dnb_def_comm;

-- dnb_def_prov: INSERT across tenants — the pattern of mt_customer_create —
-- and deliberately NO SELECT: the Admin-plane creator pre-generates the id and
-- reads no principal of any tenant.
GRANT INSERT ON mt_principals TO dnb_def_prov;
CREATE POLICY dnb_def_prov_mt_principals_insert ON mt_principals
  FOR INSERT TO dnb_def_prov WITH CHECK (true);

-- dnb_def_admin: the estate read for the projection in §8, like 019's tables.
GRANT SELECT ON mt_principals TO dnb_def_admin;
CREATE POLICY dnb_def_admin_mt_principals_select ON mt_principals
  FOR SELECT TO dnb_def_admin USING (true);

-- ---------------------------------------------------------------------------
-- 5. dnb_def_auth: resolution carries kind and capabilities; sessions can be
--    revoked per principal by the operator plane
-- ---------------------------------------------------------------------------
GRANT CREATE ON SCHEMA public TO dnb_def_auth;
SET LOCAL ROLE dnb_def_auth;

-- A return type cannot be changed in place. The EXECUTE surface is re-asserted
-- below exactly as it was: dnb_app, and nobody else.
DROP FUNCTION mt_auth_resolve_token(text);
CREATE FUNCTION mt_auth_resolve_token(p_token_hash text)
RETURNS TABLE (principal_id uuid, customer_id uuid, kind text, capabilities text[])
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  -- kind and capabilities are read from the principal row on EVERY request,
  -- never from the session: a demotion or a removed capability is enforced on
  -- the next request, exactly as status already is (P-C).
  SELECT s.principal_id, s.customer_id, p.kind, p.capabilities
    FROM mt_auth_sessions s
    JOIN mt_principals p ON p.id = s.principal_id
   WHERE s.token_hash = p_token_hash
     AND s.revoked_at IS NULL
     AND s.expires_at > now()
     AND p.status = 'active';
$$;
REVOKE ALL ON FUNCTION mt_auth_resolve_token(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mt_auth_resolve_token(text) TO dnb_app;

-- Every live session of one principal, revoked. Reachable by dnb_def_comm only
-- (mt_principal_disable), never by a login role: the caller has already found
-- the principal inside its own tenant, and this owner's widening policy is
-- what lets the sessions be reached at all.
CREATE FUNCTION mt_auth_revoke_principal_sessions(p_principal uuid)
RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE n integer;
BEGIN
  UPDATE mt_auth_sessions SET revoked_at = now()
   WHERE principal_id = p_principal AND revoked_at IS NULL;
  GET DIAGNOSTICS n = ROW_COUNT;
  RETURN n;
END $$;
REVOKE ALL ON FUNCTION mt_auth_revoke_principal_sessions(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mt_auth_revoke_principal_sessions(uuid) TO dnb_def_comm;

RESET ROLE;
REVOKE CREATE ON SCHEMA public FROM dnb_def_auth;

-- ---------------------------------------------------------------------------
-- 6. dnb_def_comm: the capability check, the four writers, the six with the floor
-- ---------------------------------------------------------------------------
GRANT CREATE ON SCHEMA public TO dnb_def_comm;
SET LOCAL ROLE dnb_def_comm;

-- The internal floor. Not SECURITY DEFINER: it runs as whichever definer
-- function called it (always dnb_def_comm), so the SELECT is RLS-scoped to the
-- tenant. Raises 42501 for an active principal without the capability, or a
-- disabled one; returns the actor's kind so the audit row can record it.
-- Granted to nobody.
CREATE FUNCTION mt_principal_require(p_actor uuid, p_capability text)
RETURNS text LANGUAGE plpgsql STABLE AS $$
DECLARE r record;
BEGIN
  IF p_capability IS NULL OR NOT (p_capability = ANY (mt_op_capabilities())) THEN
    RAISE EXCEPTION 'unknown capability: %', p_capability USING ERRCODE = 'check_violation';
  END IF;
  SELECT kind, status, capabilities INTO r FROM mt_principals WHERE id = p_actor;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'actor is not a principal of this customer' USING ERRCODE = 'check_violation';
  END IF;
  IF r.status <> 'active'
     OR NOT (r.kind = 'owner' OR p_capability = ANY (r.capabilities)) THEN
    RAISE EXCEPTION 'capability required: %', p_capability USING ERRCODE = '42501';
  END IF;
  RETURN r.kind;
END $$;
REVOKE ALL ON FUNCTION mt_principal_require(uuid,text) FROM PUBLIC;

-- The boolean of docs/116 D.7. RLS-bound by construction: a principal outside
-- the caller's tenant is not found and answers false, indistinguishably from
-- one that lacks the capability. Owner => true for every op.*; staff =>
-- membership; disabled => false; unknown capability => false.
CREATE FUNCTION mt_principal_can(p_principal uuid, p_capability text)
RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT coalesce((
    SELECT p.status = 'active'
           AND p_capability = ANY (mt_op_capabilities())
           AND (p.kind = 'owner' OR p_capability = ANY (p.capabilities))
      FROM mt_principals p WHERE p.id = p_principal), false);
$$;
REVOKE ALL ON FUNCTION mt_principal_can(uuid,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mt_principal_can(uuid,text) TO dnb_app;

-- Every one of the four states its own preconditions rather than sharing a
-- helper, as the six in 024 do: there is no p_customer parameter (the tenant
-- is mt_current_customer(), so a forged one is unrepresentable), the actor is
-- a parameter from the identity boundary (W-1), and the actor must be an
-- ACTIVE OWNER of this tenant — op.staff.manage is exactly the capability a
-- staff member can never hold (D.5), so mt_principal_require() with it is the
-- owner check.

-- principal.created ---------------------------------------------------------
CREATE FUNCTION mt_principal_create(
  p_kind text, p_display_name text, p_phone text, p_capabilities text[],
  p_actor uuid, p_source text DEFAULT NULL
) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
  v_customer   uuid := mt_current_customer();
  v_actor_kind text;
  v_caps       text[] := coalesce(p_capabilities, '{}');
  v_id         uuid;
BEGIN
  IF v_customer IS NULL THEN
    RAISE EXCEPTION 'no tenant context' USING ERRCODE = 'check_violation';
  END IF;
  IF p_actor IS NULL OR NOT EXISTS (SELECT 1 FROM mt_principals WHERE id = p_actor) THEN
    RAISE EXCEPTION 'actor is not a principal of this customer' USING ERRCODE = 'check_violation';
  END IF;
  v_actor_kind := mt_principal_require(p_actor, 'op.staff.manage');
  IF p_display_name IS NULL OR btrim(p_display_name) = '' THEN
    RAISE EXCEPTION 'display name is required' USING ERRCODE = 'check_violation';
  END IF;
  IF p_kind IS NULL OR p_kind NOT IN ('owner', 'staff') THEN
    RAISE EXCEPTION 'kind must be owner or staff' USING ERRCODE = 'check_violation';
  END IF;
  IF p_kind = 'owner' AND v_caps <> '{}' THEN
    RAISE EXCEPTION 'an owner holds every capability; capabilities must be empty'
      USING ERRCODE = 'check_violation';
  END IF;

  -- The constraints are the floor beneath this INSERT: a duplicate phone is a
  -- unique_violation (P-B: a refusal, never an upsert), an unknown capability
  -- or op.staff.manage on a staff row a check_violation.
  INSERT INTO mt_principals (customer_id, kind, display_name, phone, capabilities)
  VALUES (v_customer, p_kind, btrim(p_display_name), nullif(btrim(p_phone), ''), v_caps)
  RETURNING id INTO v_id;

  PERFORM mt_audit_write(v_customer, p_actor::text, 'principal',
                         'principal.created', 'principal', v_id::text, p_source,
                         jsonb_build_object('principal_kind', v_actor_kind,
                                            'capability', 'op.staff.manage',
                                            'target_kind', p_kind,
                                            'capabilities', to_jsonb(v_caps)));
  RETURN v_id;
END $$;

-- principal.capabilities_changed --------------------------------------------
-- NULL: no such principal in this tenant (a foreign id is simply not there).
-- false: nothing changed, and nothing is audited. true: changed and audited.
CREATE FUNCTION mt_principal_set_capabilities(
  p_principal uuid, p_capabilities text[], p_actor uuid, p_source text DEFAULT NULL
) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
  v_customer   uuid := mt_current_customer();
  v_actor_kind text;
  v_caps       text[] := coalesce(p_capabilities, '{}');
  t            mt_principals;
BEGIN
  IF v_customer IS NULL THEN
    RAISE EXCEPTION 'no tenant context' USING ERRCODE = 'check_violation';
  END IF;
  IF p_actor IS NULL OR NOT EXISTS (SELECT 1 FROM mt_principals WHERE id = p_actor) THEN
    RAISE EXCEPTION 'actor is not a principal of this customer' USING ERRCODE = 'check_violation';
  END IF;
  v_actor_kind := mt_principal_require(p_actor, 'op.staff.manage');

  SELECT * INTO t FROM mt_principals WHERE id = p_principal FOR UPDATE;
  IF NOT FOUND THEN RETURN NULL; END IF;
  IF t.kind <> 'staff' THEN
    RAISE EXCEPTION 'an owner holds every capability; capabilities cannot be set'
      USING ERRCODE = 'check_violation';
  END IF;
  IF t.capabilities = v_caps THEN RETURN false; END IF;

  UPDATE mt_principals SET capabilities = v_caps WHERE id = t.id;   -- the CHECKs refuse the rest

  PERFORM mt_audit_write(v_customer, p_actor::text, 'principal',
                         'principal.capabilities_changed', 'principal', t.id::text, p_source,
                         jsonb_build_object('principal_kind', v_actor_kind,
                                            'capability', 'op.staff.manage',
                                            'previous', to_jsonb(t.capabilities),
                                            'new', to_jsonb(v_caps)));
  RETURN true;
END $$;

-- principal.kind_changed ----------------------------------------------------
-- staff -> owner by an owner; owner -> staff only while another active owner
-- remains, and never on oneself. Either direction clears the capability list:
-- an owner's is empty by constraint, and a freshly demoted staff member holds
-- nothing until an owner grants it. No session is revoked — resolution re-reads
-- kind and capabilities live (D.10), which the suite proves.
CREATE FUNCTION mt_principal_set_kind(
  p_principal uuid, p_kind text, p_actor uuid, p_source text DEFAULT NULL
) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
  v_customer   uuid := mt_current_customer();
  v_actor_kind text;
  t            mt_principals;
BEGIN
  IF v_customer IS NULL THEN
    RAISE EXCEPTION 'no tenant context' USING ERRCODE = 'check_violation';
  END IF;
  IF p_actor IS NULL OR NOT EXISTS (SELECT 1 FROM mt_principals WHERE id = p_actor) THEN
    RAISE EXCEPTION 'actor is not a principal of this customer' USING ERRCODE = 'check_violation';
  END IF;
  v_actor_kind := mt_principal_require(p_actor, 'op.staff.manage');
  IF p_kind IS NULL OR p_kind NOT IN ('owner', 'staff') THEN
    RAISE EXCEPTION 'kind must be owner or staff' USING ERRCODE = 'check_violation';
  END IF;

  SELECT * INTO t FROM mt_principals WHERE id = p_principal FOR UPDATE;
  IF NOT FOUND THEN RETURN NULL; END IF;
  IF t.kind = p_kind THEN RETURN false; END IF;
  -- The invariant is checked BEFORE the self-guard on purpose. Only an active
  -- owner can be the actor here, so with one owner left the actor IS that
  -- owner: an invariant checked second could never be reached, and a guard
  -- that cannot fire cannot be proved. Checked first, a lone owner demoting
  -- itself is told the true reason, and the self-guard covers the rest.
  IF t.kind = 'owner' AND t.status = 'active'
     AND (SELECT count(*) FROM mt_principals WHERE kind = 'owner' AND status = 'active') <= 1 THEN
    RAISE EXCEPTION 'the last active owner cannot be demoted' USING ERRCODE = 'check_violation';
  END IF;
  IF t.id = p_actor THEN
    RAISE EXCEPTION 'a principal cannot change its own kind' USING ERRCODE = 'check_violation';
  END IF;

  UPDATE mt_principals SET kind = p_kind, capabilities = '{}' WHERE id = t.id;

  PERFORM mt_audit_write(v_customer, p_actor::text, 'principal',
                         'principal.kind_changed', 'principal', t.id::text, p_source,
                         jsonb_build_object('principal_kind', v_actor_kind,
                                            'capability', 'op.staff.manage',
                                            'previous', t.kind, 'new', p_kind,
                                            'capabilities_cleared', to_jsonb(t.capabilities)));
  RETURN true;
END $$;

-- principal.disabled --------------------------------------------------------
-- Disable, never reassign or delete (P-C). Every live session of the principal
-- is revoked in this same transaction, so the next request it makes is 401.
CREATE FUNCTION mt_principal_disable(
  p_principal uuid, p_actor uuid, p_source text DEFAULT NULL
) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
  v_customer   uuid := mt_current_customer();
  v_actor_kind text;
  t            mt_principals;
  n            integer;
BEGIN
  IF v_customer IS NULL THEN
    RAISE EXCEPTION 'no tenant context' USING ERRCODE = 'check_violation';
  END IF;
  IF p_actor IS NULL OR NOT EXISTS (SELECT 1 FROM mt_principals WHERE id = p_actor) THEN
    RAISE EXCEPTION 'actor is not a principal of this customer' USING ERRCODE = 'check_violation';
  END IF;
  v_actor_kind := mt_principal_require(p_actor, 'op.staff.manage');

  SELECT * INTO t FROM mt_principals WHERE id = p_principal FOR UPDATE;
  IF NOT FOUND THEN RETURN NULL; END IF;
  IF t.status <> 'active' THEN RETURN false; END IF;
  -- Invariant before self-guard, for the reason given in mt_principal_set_kind.
  IF t.kind = 'owner'
     AND (SELECT count(*) FROM mt_principals WHERE kind = 'owner' AND status = 'active') <= 1 THEN
    RAISE EXCEPTION 'the last active owner cannot be disabled' USING ERRCODE = 'check_violation';
  END IF;
  IF t.id = p_actor THEN
    RAISE EXCEPTION 'a principal cannot disable itself' USING ERRCODE = 'check_violation';
  END IF;

  UPDATE mt_principals SET status = 'disabled' WHERE id = t.id;
  n := mt_auth_revoke_principal_sessions(t.id);

  PERFORM mt_audit_write(v_customer, p_actor::text, 'principal',
                         'principal.disabled', 'principal', t.id::text, p_source,
                         jsonb_build_object('principal_kind', v_actor_kind,
                                            'capability', 'op.staff.manage',
                                            'target_kind', t.kind,
                                            'sessions_revoked', n));
  RETURN true;
END $$;

-- The six of 024, each with its floor (D.11). Bodies are 024's verbatim apart
-- from two additions: the capability requirement right after the actor check,
-- and the actor's kind plus the capability in the audit detail (docs/116 §G:
-- recorded AT ACT TIME, so a later demotion does not rewrite history). A
-- refusal is raised before the mutation, so there is no audit row to undo.

CREATE OR REPLACE FUNCTION mt_plan_create(
  p_name text, p_duration_s integer, p_rate_down_bps bigint, p_rate_up_bps bigint,
  p_data_cap_bytes bigint, p_devices_per_voucher integer, p_mode text,
  p_price_minor bigint, p_currency text, p_site uuid,
  p_actor_principal uuid, p_source text
) RETURNS mt_plans
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
  v_customer uuid := mt_current_customer();
  v_kind     text;
  v_profile  uuid;
  v_plan     mt_plans;
BEGIN
  IF v_customer IS NULL THEN
    RAISE EXCEPTION 'no tenant context' USING ERRCODE = 'check_violation';
  END IF;
  IF p_actor_principal IS NULL
     OR NOT EXISTS (SELECT 1 FROM mt_principals WHERE id = p_actor_principal) THEN
    RAISE EXCEPTION 'actor is not a principal of this customer'
      USING ERRCODE = 'check_violation';
  END IF;
  v_kind := mt_principal_require(p_actor_principal, 'op.plans.write');
  IF p_site IS NOT NULL
     AND NOT EXISTS (SELECT 1 FROM mt_sites WHERE id = p_site) THEN
    RAISE EXCEPTION 'site is not this customer''s' USING ERRCODE = 'check_violation';
  END IF;

  v_profile := mt_profile_resolve(p_rate_down_bps, p_rate_up_bps, p_duration_s,
                                  p_devices_per_voucher, p_data_cap_bytes);

  INSERT INTO mt_plans
    (customer_id, site_id, profile_id, name, duration_s,
     rate_down_bps, rate_up_bps, data_cap_bytes,
     devices_per_voucher, mode, price_minor, currency, created_by)
  VALUES
    (v_customer, p_site, v_profile, btrim(p_name), p_duration_s,
     p_rate_down_bps, p_rate_up_bps, p_data_cap_bytes,
     p_devices_per_voucher, p_mode, p_price_minor, upper(p_currency),
     p_actor_principal)
  RETURNING * INTO v_plan;

  PERFORM mt_audit_write(v_customer, p_actor_principal::text, 'principal',
                         'plan.created', 'plan', v_plan.id::text, p_source,
                         jsonb_build_object('name', v_plan.name,
                                            'principal_kind', v_kind, 'capability', 'op.plans.write'));
  RETURN v_plan;
END $$;

CREATE OR REPLACE FUNCTION mt_plan_update(
  p_plan uuid, p_name text, p_duration_s integer,
  p_rate_down_bps bigint, p_rate_up_bps bigint, p_data_cap_bytes bigint,
  p_devices_per_voucher integer, p_mode text, p_price_minor bigint,
  p_currency text, p_actor_principal uuid, p_source text
) RETURNS mt_plans
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
  v_customer uuid := mt_current_customer();
  v_kind     text;
  v_cur      mt_plans;
  v_profile  uuid;
  v_plan     mt_plans;
BEGIN
  IF v_customer IS NULL THEN
    RAISE EXCEPTION 'no tenant context' USING ERRCODE = 'check_violation';
  END IF;
  IF p_actor_principal IS NULL
     OR NOT EXISTS (SELECT 1 FROM mt_principals WHERE id = p_actor_principal) THEN
    RAISE EXCEPTION 'actor is not a principal of this customer'
      USING ERRCODE = 'check_violation';
  END IF;
  v_kind := mt_principal_require(p_actor_principal, 'op.plans.write');

  SELECT * INTO v_cur FROM mt_plans WHERE id = p_plan;
  IF NOT FOUND THEN RETURN NULL; END IF;

  v_profile := mt_profile_resolve(
    coalesce(p_rate_down_bps, v_cur.rate_down_bps),
    coalesce(p_rate_up_bps,   v_cur.rate_up_bps),
    coalesce(p_duration_s,    v_cur.duration_s),
    coalesce(p_devices_per_voucher, v_cur.devices_per_voucher),
    coalesce(p_data_cap_bytes, v_cur.data_cap_bytes));

  UPDATE mt_plans SET
      name                = btrim(coalesce(p_name, v_cur.name)),
      duration_s          = coalesce(p_duration_s, v_cur.duration_s),
      rate_down_bps       = coalesce(p_rate_down_bps, v_cur.rate_down_bps),
      rate_up_bps         = coalesce(p_rate_up_bps, v_cur.rate_up_bps),
      data_cap_bytes      = coalesce(p_data_cap_bytes, v_cur.data_cap_bytes),
      devices_per_voucher = coalesce(p_devices_per_voucher, v_cur.devices_per_voucher),
      mode                = coalesce(p_mode, v_cur.mode),
      price_minor         = coalesce(p_price_minor, v_cur.price_minor),
      currency            = upper(coalesce(p_currency, v_cur.currency)),
      profile_id          = v_profile
    WHERE id = p_plan
    RETURNING * INTO v_plan;

  PERFORM mt_audit_write(v_customer, p_actor_principal::text, 'principal',
                         'plan.updated', 'plan', p_plan::text, p_source,
                         jsonb_build_object('principal_kind', v_kind, 'capability', 'op.plans.write'));
  RETURN v_plan;
END $$;

CREATE OR REPLACE FUNCTION mt_plan_retire(
  p_plan uuid, p_actor_principal uuid, p_source text
) RETURNS mt_plans
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
  v_customer uuid := mt_current_customer();
  v_kind     text;
  v_plan     mt_plans;
BEGIN
  IF v_customer IS NULL THEN
    RAISE EXCEPTION 'no tenant context' USING ERRCODE = 'check_violation';
  END IF;
  IF p_actor_principal IS NULL
     OR NOT EXISTS (SELECT 1 FROM mt_principals WHERE id = p_actor_principal) THEN
    RAISE EXCEPTION 'actor is not a principal of this customer'
      USING ERRCODE = 'check_violation';
  END IF;
  v_kind := mt_principal_require(p_actor_principal, 'op.plans.write');

  UPDATE mt_plans SET active = false WHERE id = p_plan RETURNING * INTO v_plan;
  IF NOT FOUND THEN RETURN NULL; END IF;

  PERFORM mt_audit_write(v_customer, p_actor_principal::text, 'principal',
                         'plan.retired', 'plan', p_plan::text, p_source,
                         jsonb_build_object('principal_kind', v_kind, 'capability', 'op.plans.write'));
  RETURN v_plan;
END $$;

CREATE OR REPLACE FUNCTION mt_voucher_batch_issue(
  p_plan uuid, p_count integer, p_site uuid, p_actor_principal uuid,
  p_codes text[], p_idempotency_key text, p_source text
) RETURNS TABLE (out_batch uuid, out_intent uuid, out_vouchers uuid[])
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
  v_customer uuid := mt_current_customer();
  v_kind     text;
  v_plan     mt_plans;
  v_batch    uuid;
  v_intent   uuid;
  v_ids      uuid[] := '{}';
  v_vid      uuid;
  v_issued   integer := 0;
  v_ref      text;
  i          integer;
BEGIN
  IF v_customer IS NULL THEN
    RAISE EXCEPTION 'no tenant context' USING ERRCODE = 'check_violation';
  END IF;
  IF p_actor_principal IS NULL
     OR NOT EXISTS (SELECT 1 FROM mt_principals WHERE id = p_actor_principal) THEN
    RAISE EXCEPTION 'actor is not a principal of this customer'
      USING ERRCODE = 'check_violation';
  END IF;
  v_kind := mt_principal_require(p_actor_principal, 'op.vouchers.issue');
  IF p_site IS NOT NULL
     AND NOT EXISTS (SELECT 1 FROM mt_sites WHERE id = p_site) THEN
    RAISE EXCEPTION 'site is not this customer''s' USING ERRCODE = 'check_violation';
  END IF;
  IF p_count < 1 THEN
    RAISE EXCEPTION 'count must be at least 1' USING ERRCODE = 'check_violation';
  END IF;

  SELECT * INTO v_plan FROM mt_plans WHERE id = p_plan AND active;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'plan not found or retired' USING ERRCODE = 'P0002';
  END IF;

  INSERT INTO mt_voucher_batches
    (customer_id, site_id, plan_id, requested_count, created_by)
  VALUES (v_customer, p_site, p_plan, p_count, p_actor_principal)
  RETURNING id INTO v_batch;

  FOR i IN 1 .. coalesce(array_length(p_codes, 1), 0) LOOP
    EXIT WHEN v_issued >= p_count;
    BEGIN
      INSERT INTO mt_vouchers
        (customer_id, batch_id, plan_id, site_id, code,
         price_minor, currency, duration_s)
      VALUES
        (v_customer, v_batch, v_plan.id, p_site, p_codes[i],
         v_plan.price_minor, v_plan.currency, v_plan.duration_s)
      RETURNING id INTO v_vid;
      v_ids    := v_ids || v_vid;
      v_issued := v_issued + 1;
    EXCEPTION WHEN unique_violation THEN
      NULL;   -- code collision: take the next draw
    END;
  END LOOP;

  IF v_issued < p_count THEN
    RAISE EXCEPTION 'could not allocate % unique voucher codes', p_count
      USING ERRCODE = 'check_violation';
  END IF;

  SELECT radius_ref INTO v_ref FROM mt_customers WHERE id = v_customer;
  INSERT INTO mt_hotspot_users (voucher_id, customer_id, radius_username)
  SELECT v.id, v.customer_id, v_ref || '-' || replace(v.code, '-', '')
    FROM mt_vouchers v WHERE v.id = ANY (v_ids);

  UPDATE mt_voucher_batches
     SET issued_count = v_issued, state = 'issued', completed_at = now()
   WHERE id = v_batch;

  IF p_idempotency_key IS NOT NULL THEN
    SELECT id INTO v_intent FROM mt_intents
     WHERE idempotency_key = p_idempotency_key;
  END IF;
  IF v_intent IS NULL THEN
    INSERT INTO mt_intents
      (customer_id, actor_principal_id, actor_kind, kind,
       target_type, target_id, payload, idempotency_key)
    VALUES
      (v_customer, p_actor_principal, 'principal', 'voucher.publish',
       'voucher_batch', v_batch::text,
       jsonb_build_object('batch_id', v_batch, 'count', v_issued),
       p_idempotency_key)
    RETURNING id INTO v_intent;
  END IF;

  PERFORM mt_audit_write(v_customer, p_actor_principal::text, 'principal',
                         'voucher.issued', 'voucher_batch', v_batch::text, p_source,
                         jsonb_build_object('count', v_issued,
                                            'principal_kind', v_kind, 'capability', 'op.vouchers.issue'));

  RETURN QUERY SELECT v_batch, v_intent, v_ids;
END $$;

CREATE OR REPLACE FUNCTION mt_voucher_revoke(
  p_voucher uuid, p_actor_principal uuid, p_source text
) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
  v_customer uuid := mt_current_customer();
  v_kind     text;
  v_id       uuid;
  v_intent   uuid;
BEGIN
  IF v_customer IS NULL THEN
    RAISE EXCEPTION 'no tenant context' USING ERRCODE = 'check_violation';
  END IF;
  IF p_actor_principal IS NULL
     OR NOT EXISTS (SELECT 1 FROM mt_principals WHERE id = p_actor_principal) THEN
    RAISE EXCEPTION 'actor is not a principal of this customer'
      USING ERRCODE = 'check_violation';
  END IF;
  v_kind := mt_principal_require(p_actor_principal, 'op.vouchers.revoke');

  UPDATE mt_vouchers SET state = 'revoked', revoked_at = now()
   WHERE id = p_voucher AND state IN ('unused', 'active')
   RETURNING id INTO v_id;
  IF NOT FOUND THEN RETURN NULL; END IF;

  INSERT INTO mt_intents
    (customer_id, actor_principal_id, actor_kind, kind, target_type, target_id, payload)
  VALUES
    (v_customer, p_actor_principal, 'principal', 'voucher.revoke',
     'voucher', p_voucher::text, jsonb_build_object('voucher_id', p_voucher))
  RETURNING id INTO v_intent;

  PERFORM mt_audit_write(v_customer, p_actor_principal::text, 'principal',
                         'voucher.revoked', 'voucher', p_voucher::text, p_source,
                         jsonb_build_object('principal_kind', v_kind, 'capability', 'op.vouchers.revoke'));
  RETURN v_intent;
END $$;

CREATE OR REPLACE FUNCTION mt_session_disconnect_request(
  p_session uuid, p_actor_principal uuid, p_source text
) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
  v_customer uuid := mt_current_customer();
  v_kind     text;
  v_intent   uuid;
BEGIN
  IF v_customer IS NULL THEN
    RAISE EXCEPTION 'no tenant context' USING ERRCODE = 'check_violation';
  END IF;
  IF p_actor_principal IS NULL
     OR NOT EXISTS (SELECT 1 FROM mt_principals WHERE id = p_actor_principal) THEN
    RAISE EXCEPTION 'actor is not a principal of this customer'
      USING ERRCODE = 'check_violation';
  END IF;
  v_kind := mt_principal_require(p_actor_principal, 'op.sessions.disconnect');

  IF NOT EXISTS (SELECT 1 FROM mt_sessions WHERE id = p_session) THEN
    RETURN NULL;
  END IF;

  INSERT INTO mt_intents
    (customer_id, actor_principal_id, actor_kind, kind, target_type, target_id, payload)
  VALUES
    (v_customer, p_actor_principal, 'principal', 'session.disconnect',
     'session', p_session::text, jsonb_build_object('session_id', p_session))
  RETURNING id INTO v_intent;

  PERFORM mt_audit_write(v_customer, p_actor_principal::text, 'principal',
                         'session.disconnect_requested', 'session', p_session::text,
                         p_source,
                         jsonb_build_object('principal_kind', v_kind, 'capability', 'op.sessions.disconnect'));
  RETURN v_intent;
END $$;

-- Who may call the four: dnb_app only — the operator plane, reached from the
-- authenticated customer API and nowhere else (the 024 rule).
REVOKE ALL ON FUNCTION mt_principal_create(text,text,text,text[],uuid,text)          FROM PUBLIC;
REVOKE ALL ON FUNCTION mt_principal_set_capabilities(uuid,text[],uuid,text)          FROM PUBLIC;
REVOKE ALL ON FUNCTION mt_principal_set_kind(uuid,text,uuid,text)                    FROM PUBLIC;
REVOKE ALL ON FUNCTION mt_principal_disable(uuid,uuid,text)                          FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mt_principal_create(text,text,text,text[],uuid,text)       TO dnb_app;
GRANT EXECUTE ON FUNCTION mt_principal_set_capabilities(uuid,text[],uuid,text)       TO dnb_app;
GRANT EXECUTE ON FUNCTION mt_principal_set_kind(uuid,text,uuid,text)                 TO dnb_app;
GRANT EXECUTE ON FUNCTION mt_principal_disable(uuid,uuid,text)                       TO dnb_app;

COMMENT ON FUNCTION mt_principal_create(text,text,text,text[],uuid,text) IS
  'docs/116 D.8. Creates an owner or staff principal of the CURRENT tenant; the actor must be an active owner of it (op.staff.manage, which staff can never hold). Duplicate phone is a refusal (P-B). One audit row, actor_kind principal.';
COMMENT ON FUNCTION mt_principal_disable(uuid,uuid,text) IS
  'docs/116 D.8. Disable, never reassign (P-C). The last active owner cannot be disabled; a principal cannot disable itself; every live session of the target is revoked in the same transaction.';

RESET ROLE;
REVOKE CREATE ON SCHEMA public FROM dnb_def_comm;

-- ---------------------------------------------------------------------------
-- 7. dnb_def_prov: the Admin-plane creator — target operator EXPLICIT
-- ---------------------------------------------------------------------------
GRANT CREATE ON SCHEMA public TO dnb_def_prov;
SET LOCAL ROLE dnb_def_prov;

-- D-AUTH-3 as reviewed in docs/116 §J J-3: the target operator is a validated
-- parameter, never mt_current_customer(), which the Admin plane never sets.
-- The id is generated first and the INSERT carries no RETURNING, so this owner
-- needs INSERT and nothing else on mt_principals — it reads no principal of
-- any tenant. An unknown operator is refused by the foreign key, a duplicate
-- phone by the unique index, a bad kind or capability list by the CHECKs.
-- The audit row names the DishNet staff username (D-AUTH-6), actor_kind
-- 'staff', with the target operator in detail (docs/116 §G).
CREATE FUNCTION mt_admin_principal_create(
  p_operator uuid, p_kind text, p_display_name text, p_phone text,
  p_capabilities text[], p_actor text
) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
  v_id   uuid   := gen_random_uuid();
  v_caps text[] := coalesce(p_capabilities, '{}');
BEGIN
  IF p_operator IS NULL THEN
    RAISE EXCEPTION 'target operator is required' USING ERRCODE = 'check_violation';
  END IF;
  IF p_actor IS NULL OR btrim(p_actor) = '' THEN
    RAISE EXCEPTION 'principal creation requires the identity of whoever performed it'
      USING ERRCODE = 'check_violation';
  END IF;
  IF p_display_name IS NULL OR btrim(p_display_name) = '' THEN
    RAISE EXCEPTION 'display name is required' USING ERRCODE = 'check_violation';
  END IF;
  IF p_kind IS NULL OR p_kind NOT IN ('owner', 'staff') THEN
    RAISE EXCEPTION 'kind must be owner or staff' USING ERRCODE = 'check_violation';
  END IF;
  IF p_kind = 'owner' AND v_caps <> '{}' THEN
    RAISE EXCEPTION 'an owner holds every capability; capabilities must be empty'
      USING ERRCODE = 'check_violation';
  END IF;

  INSERT INTO mt_principals (id, customer_id, kind, display_name, phone, capabilities)
  VALUES (v_id, p_operator, p_kind, btrim(p_display_name), nullif(btrim(p_phone), ''), v_caps);

  PERFORM mt_audit_write(p_operator, btrim(p_actor), 'staff',
                         'principal.created', 'principal', v_id::text, 'admin',
                         jsonb_build_object('operator', p_operator,
                                            'target_kind', p_kind,
                                            'capabilities', to_jsonb(v_caps)));
  RETURN v_id;
END $$;
REVOKE ALL ON FUNCTION mt_admin_principal_create(uuid,text,text,text,text[],text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mt_admin_principal_create(uuid,text,text,text,text[],text) TO dnb_adminwrite;
COMMENT ON FUNCTION mt_admin_principal_create(uuid,text,text,text,text[],text) IS
  'docs/116 D.9, docs/112 spine step 1. Creates a principal of an EXPLICIT target operator on the Admin plane; the first owner of a new operator can be created by nobody else. Actor is the DishNet staff username; actor_kind staff.';

RESET ROLE;
REVOKE CREATE ON SCHEMA public FROM dnb_def_prov;

-- ---------------------------------------------------------------------------
-- 8. dnb_def_admin: the projection — phone, email, credential_hash withheld
-- ---------------------------------------------------------------------------
GRANT CREATE ON SCHEMA public TO dnb_def_admin;
SET LOCAL ROLE dnb_def_admin;

CREATE FUNCTION mt_admin_principals()
RETURNS TABLE (id uuid, customer_id uuid, kind text, display_name text, status text,
               capabilities text[], created_at timestamptz, last_login_at timestamptz)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT p.id, p.customer_id, p.kind, p.display_name, p.status, p.capabilities,
         p.created_at, p.last_login_at
    FROM mt_principals p ORDER BY p.customer_id, p.display_name;
$$;
REVOKE ALL ON FUNCTION mt_admin_principals() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mt_admin_principals() TO dnb_adminapi;
COMMENT ON FUNCTION mt_admin_principals() IS
  'docs/116 D.12. Estate view of every operator''s people. phone (the authentication key, docs/100), email and credential_hash are withheld.';

RESET ROLE;
REVOKE CREATE ON SCHEMA public FROM dnb_def_admin;
