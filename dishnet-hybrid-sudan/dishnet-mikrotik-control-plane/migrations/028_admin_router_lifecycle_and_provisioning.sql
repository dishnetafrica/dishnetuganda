-- 028 — Router lifecycle and provisioning from the Admin plane (docs/121)
--
-- Decided in docs/121 §B BEFORE this file was written:
--
--   D-4   mt_device_set_state() gains RULE I-1 (docs/108): a state the row
--         already holds is a no-op — no UPDATE, no audit row, the row returned.
--         Same signature, same owner, grants preserved by CREATE OR REPLACE. A
--         blank actor is refused on every path, the no-op included.
--   D-5   mt_device_provision_request(device, idempotency_key, actor) — the
--         Admin-plane enqueue function docs/118 D-2 named and did not authorise
--         (that instruction fixed the migration state at 027; this one lifts
--         it). Owner dnb_def_prov; EXECUTE dnb_adminwrite ONLY — never
--         dnb_admin, dnb_worker or dnb_app (docs/112 A-1). There is no
--         p_customer: the operator is DERIVED from the device row. The payload
--         names the device and nothing else.
--   D-6   The function refuses early what the worker would refuse ~8 minutes
--         later with no audit row (docs/120 §15.8.6): an unassigned router (an
--         intent needs a tenant to run under), a router not recorded as
--         connected or later, a decommissioned router, a router with no
--         management address. The 10.66/16 rule is NOT copied here — it lives
--         once, in Dn\Devices\TunnelAddress, and binds at registration.
--   D-7   The accepted states are exactly DeliveryTarget::DELIVERABLE_STATES;
--         the suite proves the equality by execution over all nine states.
--   D-8   The replay check runs BEFORE the insert and BEFORE the audit
--         (RULE I-1). The same (operator, key) for the same device and kind
--         returns the existing intent and writes nothing; the same key for a
--         different request is a conflict. The race two identical requests can
--         win against the pre-check is closed inside the function with
--         ON CONFLICT DO NOTHING on the existing unique index.
--   D-16  dnb_def_prov gains SELECT and INSERT on mt_intents, with the two
--         policies in migration 017's naming. SELECT is not optional: with an
--         INSERT-only policy the replay check would silently read ZERO rows
--         (the O-1 lesson) and every replay would surface as a unique
--         violation instead of the stored result.
--
-- Nothing else changes: no table, no column, no enum value, no actor kind.

-- ---------------------------------------------------------------------------
-- 1. dnb_def_prov may read and insert intents (D-16)
-- ---------------------------------------------------------------------------
GRANT SELECT, INSERT ON mt_intents TO dnb_def_prov;

DROP POLICY IF EXISTS dnb_def_prov_mt_intents_select ON mt_intents;
CREATE POLICY dnb_def_prov_mt_intents_select ON mt_intents
  FOR SELECT TO dnb_def_prov USING (true);
DROP POLICY IF EXISTS dnb_def_prov_mt_intents_insert ON mt_intents;
CREATE POLICY dnb_def_prov_mt_intents_insert ON mt_intents
  FOR INSERT TO dnb_def_prov WITH CHECK (true);

-- ---------------------------------------------------------------------------
-- 2. The functions, owned by dnb_def_prov (as 020 §2 and 027 §7)
-- ---------------------------------------------------------------------------
GRANT CREATE ON SCHEMA public TO dnb_def_prov;
SET LOCAL ROLE dnb_def_prov;

-- D-4. Behaviour identical to 020's for a real change: one UPDATE (the 012
-- trigger judges legality), one audit row naming from and to. New: a state the
-- row already holds returns the row untouched and unaudited.
CREATE OR REPLACE FUNCTION mt_device_set_state(p_device uuid, p_state text, p_actor text)
RETURNS mt_devices
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE r mt_devices; was_state text;
BEGIN
  IF p_actor IS NULL OR btrim(p_actor) = '' THEN
    RAISE EXCEPTION 'a state change requires the identity of whoever recorded it'
      USING ERRCODE = 'check_violation';
  END IF;
  SELECT d.* INTO r FROM mt_devices d WHERE d.id = p_device;
  IF NOT FOUND THEN RETURN NULL; END IF;
  -- RULE I-1 (docs/108; docs/121 D-4). The row already holds this state, so
  -- there is no act to record: a replayed request must not write a second
  -- audit row that the append-only trigger then makes permanent.
  IF r.state = p_state THEN RETURN r; END IF;
  was_state := r.state;
  UPDATE mt_devices SET state = p_state WHERE id = p_device RETURNING * INTO r;
  PERFORM mt_audit_write(r.customer_id, p_actor, 'staff', 'device.state_changed',
    'device', p_device::text, 'admin',
    jsonb_build_object('from', was_state, 'to', p_state));
  RETURN r;
END $$;

COMMENT ON FUNCTION mt_device_set_state(uuid,text,text) IS
  'W-1 (020). Records a lifecycle state a DishNet staff member observed; migration 012''s trigger decides legality. 028 (docs/121 D-4): a state the row already holds is a no-op with no audit row — RULE I-1.';

-- D-5 … D-8.
CREATE FUNCTION mt_device_provision_request(p_device uuid, p_idempotency_key text, p_actor text)
RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
  d     mt_devices;
  v     mt_intents;
  v_key text := btrim(coalesce(p_idempotency_key, ''));
BEGIN
  IF p_actor IS NULL OR btrim(p_actor) = '' THEN
    RAISE EXCEPTION 'a provisioning request requires the identity of whoever made it'
      USING ERRCODE = 'check_violation';
  END IF;
  IF v_key = '' THEN
    RAISE EXCEPTION 'a provisioning request requires an idempotency key'
      USING ERRCODE = 'check_violation';
  END IF;

  SELECT x.* INTO d FROM mt_devices x WHERE x.id = p_device;
  IF NOT FOUND THEN RETURN NULL; END IF;

  -- D-6: refuse now what the worker would only fail later, and say why.
  IF d.customer_id IS NULL THEN
    RAISE EXCEPTION 'router % is not assigned to an operator; assign it before queuing its configuration', d.serial
      USING ERRCODE = 'DN409';
  END IF;
  IF d.state = 'decommissioned' THEN
    RAISE EXCEPTION 'router % is decommissioned', d.serial USING ERRCODE = 'DN409';
  END IF;
  -- D-7: exactly DeliveryTarget::DELIVERABLE_STATES (asserted by execution).
  IF d.state NOT IN ('connected', 'provisioned', 'active', 'diverged') THEN
    RAISE EXCEPTION 'router % is recorded as %; its configuration can be queued only once it is recorded as connected', d.serial, d.state
      USING ERRCODE = 'DN409';
  END IF;
  IF d.tunnel_ip IS NULL THEN
    RAISE EXCEPTION 'router % has no management address recorded', d.serial
      USING ERRCODE = 'DN409';
  END IF;

  -- D-8 / RULE I-1: the replay check comes BEFORE the insert and the audit.
  SELECT i.* INTO v FROM mt_intents i
   WHERE i.customer_id = d.customer_id AND i.idempotency_key = v_key;
  IF FOUND THEN
    IF v.kind <> 'device.provision' OR v.target_id IS DISTINCT FROM p_device::text THEN
      RAISE EXCEPTION 'idempotency key already used for a different request'
        USING ERRCODE = 'DN409';
    END IF;
    RETURN jsonb_build_object('replayed', true, 'intent', to_jsonb(v));
  END IF;

  INSERT INTO mt_intents
    (customer_id, actor_principal_id, actor_kind, kind, target_type, target_id, payload, idempotency_key)
  VALUES
    (d.customer_id, NULL, 'staff', 'device.provision', 'device', p_device::text,
     jsonb_build_object('device_id', p_device), v_key)
  ON CONFLICT (customer_id, idempotency_key) WHERE idempotency_key IS NOT NULL DO NOTHING
  RETURNING * INTO v;
  IF NOT FOUND THEN
    -- Lost the race to an identical request that committed between the check
    -- and the insert: return what it recorded, or refuse if it was different.
    SELECT i.* INTO v FROM mt_intents i
     WHERE i.customer_id = d.customer_id AND i.idempotency_key = v_key;
    IF NOT FOUND OR v.kind <> 'device.provision' OR v.target_id IS DISTINCT FROM p_device::text THEN
      RAISE EXCEPTION 'idempotency key already used for a different request'
        USING ERRCODE = 'DN409';
    END IF;
    RETURN jsonb_build_object('replayed', true, 'intent', to_jsonb(v));
  END IF;

  PERFORM mt_audit_write(d.customer_id, p_actor, 'staff', 'device.provision_requested',
    'device', p_device::text, 'admin',
    jsonb_build_object('intent', v.id, 'state', d.state, 'idempotency_key', v_key));
  RETURN jsonb_build_object('replayed', false, 'intent', to_jsonb(v));
END $$;

REVOKE ALL ON FUNCTION mt_device_provision_request(uuid,text,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mt_device_provision_request(uuid,text,text) TO dnb_adminwrite;
COMMENT ON FUNCTION mt_device_provision_request(uuid,text,text) IS
  'docs/121 D-5..D-8; docs/114 §K; docs/118 D-2 lifted. Queues a device.provision intent for a router from the Admin plane. The operator is DERIVED from the device row; the payload names the device only; the replay check precedes the insert and the audit (RULE I-1); nothing here contacts a router (F2).';

RESET ROLE;
REVOKE CREATE ON SCHEMA public FROM dnb_def_prov;

-- ---------------------------------------------------------------------------
-- 3. Verify, or refuse to be applied
-- ---------------------------------------------------------------------------
DO $$
DECLARE
  f  text := 'mt_device_provision_request(uuid,text,text)';
  s  text := 'mt_device_set_state(uuid,text,text)';
  r  record;
  n  int;
BEGIN
  IF NOT has_function_privilege('dnb_adminwrite', f, 'EXECUTE') THEN
    RAISE EXCEPTION '028: dnb_adminwrite cannot execute %', f;
  END IF;
  IF NOT has_function_privilege('dnb_adminwrite', s, 'EXECUTE') THEN
    RAISE EXCEPTION '028: CREATE OR REPLACE lost dnb_adminwrite''s EXECUTE on %', s;
  END IF;
  FOR r IN SELECT rolname FROM pg_roles
            WHERE rolname LIKE 'dnb%' AND rolname NOT IN ('dnb_adminwrite', 'dnb_def_prov')
              AND has_function_privilege(rolname, f, 'EXECUTE') LOOP
    RAISE EXCEPTION '028: % may execute % — only dnb_adminwrite may (docs/112 A-1)', r.rolname, f;
  END LOOP;
  SELECT count(*) INTO n FROM pg_proc p, aclexplode(p.proacl) a
   WHERE p.oid = f::regprocedure AND a.grantee = 0;
  IF n <> 0 THEN RAISE EXCEPTION '028: PUBLIC holds EXECUTE on %', f; END IF;
  IF (SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE oid = f::regprocedure) <> 'dnb_def_prov'
     OR (SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE oid = s::regprocedure) <> 'dnb_def_prov' THEN
    RAISE EXCEPTION '028: a function is not owned by dnb_def_prov';
  END IF;
  SELECT count(*) INTO n FROM information_schema.role_table_grants WHERE grantee = 'dnb_adminwrite';
  IF n <> 0 THEN RAISE EXCEPTION '028: dnb_adminwrite holds a table privilege; it must hold none (W-3)'; END IF;
  IF NOT has_table_privilege('dnb_def_prov', 'mt_intents', 'SELECT')
     OR NOT has_table_privilege('dnb_def_prov', 'mt_intents', 'INSERT')
     OR has_table_privilege('dnb_def_prov', 'mt_intents', 'UPDATE')
     OR has_table_privilege('dnb_def_prov', 'mt_intents', 'DELETE') THEN
    RAISE EXCEPTION '028: dnb_def_prov must hold exactly SELECT and INSERT on mt_intents';
  END IF;
  SELECT count(*) INTO n FROM pg_policy
   WHERE polrelid = 'mt_intents'::regclass
     AND polname IN ('dnb_def_prov_mt_intents_select', 'dnb_def_prov_mt_intents_insert');
  IF n <> 2 THEN RAISE EXCEPTION '028: the two dnb_def_prov policies on mt_intents are missing'; END IF;
  RAISE NOTICE '028: ok — % executable by dnb_adminwrite only; dnb_def_prov reads and inserts intents; W-3 intact', f;
END $$;
