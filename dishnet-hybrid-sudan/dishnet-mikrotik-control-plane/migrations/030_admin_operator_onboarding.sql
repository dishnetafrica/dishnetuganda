-- ---------------------------------------------------------------------------
-- 030 — Operators, their HotSpot service and their locations, created from the
--       Admin plane (docs/125). Roadmap step 3.
--
-- Built on O-1 (029): a location's service and the location itself now belong
-- to one operator by constraint, which docs/105 required before any site
-- writer could exist.
--
--   D-2  mt_admin_idempotency: the NON-TENANT idempotency store (docs/108 0b).
--        Keyed (endpoint, key) with a request digest. Created AS dnb_def_prov
--        so that no login role inherits a default privilege on it (the 026
--        lesson: migration 015's defaults are per granting role). No row
--        security: it has no tenant; its isolation is privilege, as mt_staff's.
--   D-3  Inside every writer, in this order: validate; compute the request
--        digest HERE, from the parameters, so no caller can supply a false one;
--        answer a replay (RULE I-1) before anything else; read-only existence
--        checks, returning NULL with nothing claimed; CLAIM the key (ON CONFLICT
--        DO NOTHING closes the race); mutate; audit; store the result.
--   D-4  mt_admin_operator_create wraps mt_customer_create, which stays the one
--        implementation and writes the one audit row.
--   D-5  mt_admin_service_create: the one legal kind; an active operator.
--   D-6  mt_admin_site_create: NO operator parameter. The operator is read
--        from the service row (derive, never accept); 029's key is the floor.
--   D-7  dnb_def_prov gains exactly what the three need: SELECT on
--        mt_customers, mt_services, mt_sites; INSERT on mt_services, mt_sites.
--
-- EXECUTE on the three writers: dnb_adminwrite only (docs/112 A-1). Nothing
-- here contacts a router, and no table, column or enum value outside the store
-- is added.
-- ---------------------------------------------------------------------------

-- ---------------------------------------------------------------------------
-- 1. What dnb_def_prov may read and write (D-7), granted by the tables' owner
-- ---------------------------------------------------------------------------
GRANT SELECT ON mt_customers TO dnb_def_prov;
DROP POLICY IF EXISTS dnb_def_prov_mt_customers_select ON mt_customers;
CREATE POLICY dnb_def_prov_mt_customers_select ON mt_customers
  FOR SELECT TO dnb_def_prov USING (true);

GRANT SELECT, INSERT ON mt_services TO dnb_def_prov;
DROP POLICY IF EXISTS dnb_def_prov_mt_services_select ON mt_services;
CREATE POLICY dnb_def_prov_mt_services_select ON mt_services
  FOR SELECT TO dnb_def_prov USING (true);
DROP POLICY IF EXISTS dnb_def_prov_mt_services_insert ON mt_services;
CREATE POLICY dnb_def_prov_mt_services_insert ON mt_services
  FOR INSERT TO dnb_def_prov WITH CHECK (true);

GRANT SELECT, INSERT ON mt_sites TO dnb_def_prov;
DROP POLICY IF EXISTS dnb_def_prov_mt_sites_select ON mt_sites;
CREATE POLICY dnb_def_prov_mt_sites_select ON mt_sites
  FOR SELECT TO dnb_def_prov USING (true);
DROP POLICY IF EXISTS dnb_def_prov_mt_sites_insert ON mt_sites;
CREATE POLICY dnb_def_prov_mt_sites_insert ON mt_sites
  FOR INSERT TO dnb_def_prov WITH CHECK (true);

-- ---------------------------------------------------------------------------
-- 2. Everything below is created AS dnb_def_prov (CREATE is transient, as 017)
-- ---------------------------------------------------------------------------
GRANT CREATE ON SCHEMA public TO dnb_def_prov;
SET LOCAL ROLE dnb_def_prov;

CREATE TABLE mt_admin_idempotency (
  endpoint        text NOT NULL CHECK (endpoint IN ('operator.create', 'service.create', 'site.create')),
  key             text NOT NULL CHECK (key ~ '^[A-Za-z0-9._:-]{8,128}$'),
  request_digest  text NOT NULL CHECK (request_digest ~ '^[0-9a-f]{64}$'),
  actor           text NOT NULL CHECK (btrim(actor) <> ''),
  -- The row as the writer returned it. Set in the same transaction as the claim,
  -- so a committed row always carries it.
  result          jsonb,
  created_at      timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (endpoint, key)
);
COMMENT ON TABLE mt_admin_idempotency IS
  'docs/125 D-2; docs/108 0b. The non-tenant idempotency store for Admin-plane creation: operators, services, locations. No tenant, so no row security: no login role holds any privilege on it, and only the dnb_def_prov writers touch it.';

-- The stored result of an earlier identical request, NULL if the key is unused,
-- or a refusal if the key was used for a different request. SECURITY INVOKER:
-- it runs with the privileges of the writer that calls it and grants nothing.
CREATE FUNCTION mt_admin_idem_seen(p_endpoint text, p_key text, p_digest text)
RETURNS jsonb
LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
DECLARE v_digest text; v_result jsonb;
BEGIN
  SELECT i.request_digest, i.result INTO v_digest, v_result
    FROM mt_admin_idempotency i WHERE i.endpoint = p_endpoint AND i.key = p_key;
  IF NOT FOUND THEN RETURN NULL; END IF;
  IF v_digest <> p_digest THEN
    RAISE EXCEPTION 'this idempotency key was already used for a different request'
      USING ERRCODE = 'DN409';
  END IF;
  RETURN v_result;
END $$;

-- Claim the key for this request: NULL when claimed now; the stored result when
-- an identical request committed first (the race the pre-check cannot close).
CREATE FUNCTION mt_admin_idem_claim(p_endpoint text, p_key text, p_digest text, p_actor text)
RETURNS jsonb
LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN
  INSERT INTO mt_admin_idempotency (endpoint, key, request_digest, actor)
  VALUES (p_endpoint, p_key, p_digest, p_actor)
  ON CONFLICT (endpoint, key) DO NOTHING;
  IF FOUND THEN RETURN NULL; END IF;
  RETURN mt_admin_idem_seen(p_endpoint, p_key, p_digest);
END $$;

REVOKE ALL ON FUNCTION mt_admin_idem_seen(text,text,text) FROM PUBLIC;
REVOKE ALL ON FUNCTION mt_admin_idem_claim(text,text,text,text) FROM PUBLIC;

-- D-4. An operator: the tenant root. mt_customer_create writes customer.created.
CREATE FUNCTION mt_admin_operator_create(p_name text, p_idempotency_key text, p_actor text)
RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
  v_key    text := btrim(coalesce(p_idempotency_key, ''));
  v_name   text := btrim(coalesce(p_name, ''));
  v_actor  text := btrim(coalesce(p_actor, ''));
  v_digest text;
  v_row    jsonb;
  v_id     uuid;
BEGIN
  IF v_actor = '' THEN
    RAISE EXCEPTION 'creating an operator requires the identity of whoever did it'
      USING ERRCODE = 'check_violation';
  END IF;
  IF v_key !~ '^[A-Za-z0-9._:-]{8,128}$' THEN
    RAISE EXCEPTION 'an idempotency key of 8 to 128 letters, digits, dots, underscores, colons or dashes is required'
      USING ERRCODE = 'check_violation';
  END IF;
  IF v_name = '' OR length(v_name) > 120 THEN
    RAISE EXCEPTION 'an operator name of 1 to 120 characters is required' USING ERRCODE = 'check_violation';
  END IF;
  v_digest := encode(sha256(convert_to(jsonb_build_object('name', v_name)::text, 'UTF8')), 'hex');

  -- RULE I-1: a replay is answered before anything is written.
  v_row := mt_admin_idem_seen('operator.create', v_key, v_digest);
  IF v_row IS NOT NULL THEN RETURN jsonb_build_object('replayed', true, 'customer', v_row); END IF;
  v_row := mt_admin_idem_claim('operator.create', v_key, v_digest, v_actor);
  IF v_row IS NOT NULL THEN RETURN jsonb_build_object('replayed', true, 'customer', v_row); END IF;

  v_id := mt_customer_create(v_name, v_actor);
  SELECT to_jsonb(c) INTO v_row FROM mt_customers c WHERE c.id = v_id;
  UPDATE mt_admin_idempotency SET result = v_row WHERE endpoint = 'operator.create' AND key = v_key;
  RETURN jsonb_build_object('replayed', false, 'customer', v_row);
END $$;

-- D-5. The operator's HotSpot service. The operator is the explicit TARGET, never
-- a tenant context (docs/114 D-AUTH-3); it must exist and be active.
CREATE FUNCTION mt_admin_service_create(p_operator uuid, p_idempotency_key text, p_actor text)
RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
  v_key    text := btrim(coalesce(p_idempotency_key, ''));
  v_actor  text := btrim(coalesce(p_actor, ''));
  v_digest text;
  v_row    jsonb;
  v_status text;
  v_svc    mt_services;
BEGIN
  IF v_actor = '' THEN
    RAISE EXCEPTION 'starting a service requires the identity of whoever did it'
      USING ERRCODE = 'check_violation';
  END IF;
  IF v_key !~ '^[A-Za-z0-9._:-]{8,128}$' THEN
    RAISE EXCEPTION 'an idempotency key of 8 to 128 letters, digits, dots, underscores, colons or dashes is required'
      USING ERRCODE = 'check_violation';
  END IF;
  IF p_operator IS NULL THEN
    RAISE EXCEPTION 'the target operator is required' USING ERRCODE = 'check_violation';
  END IF;
  v_digest := encode(sha256(convert_to(
    jsonb_build_object('operator', p_operator, 'kind', 'mikrotik_hotspot')::text, 'UTF8')), 'hex');

  v_row := mt_admin_idem_seen('service.create', v_key, v_digest);
  IF v_row IS NOT NULL THEN RETURN jsonb_build_object('replayed', true, 'service', v_row); END IF;

  SELECT c.status INTO v_status FROM mt_customers c WHERE c.id = p_operator;
  IF NOT FOUND THEN RETURN NULL; END IF;
  IF v_status <> 'active' THEN
    RAISE EXCEPTION 'the operator is %; a service can be started only for an active operator', v_status
      USING ERRCODE = 'DN409';
  END IF;

  v_row := mt_admin_idem_claim('service.create', v_key, v_digest, v_actor);
  IF v_row IS NOT NULL THEN RETURN jsonb_build_object('replayed', true, 'service', v_row); END IF;

  INSERT INTO mt_services (customer_id, kind) VALUES (p_operator, 'mikrotik_hotspot')
  RETURNING * INTO v_svc;
  PERFORM mt_audit_write(p_operator, v_actor, 'staff', 'service.created',
    'service', v_svc.id::text, 'admin',
    jsonb_build_object('operator', p_operator, 'kind', v_svc.kind));
  UPDATE mt_admin_idempotency SET result = to_jsonb(v_svc) WHERE endpoint = 'service.create' AND key = v_key;
  RETURN jsonb_build_object('replayed', false, 'service', to_jsonb(v_svc));
END $$;

-- D-6. A location. There is NO operator parameter: the operator is read from the
-- service row, so a location on another operator's service is unrepresentable
-- here, and refused below this by 029's key if anything else tried.
CREATE FUNCTION mt_admin_site_create(p_service uuid, p_name text, p_location text,
                                     p_idempotency_key text, p_actor text)
RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE
  v_key    text := btrim(coalesce(p_idempotency_key, ''));
  v_actor  text := btrim(coalesce(p_actor, ''));
  v_name   text := btrim(coalesce(p_name, ''));
  v_loc    text := nullif(btrim(coalesce(p_location, '')), '');
  v_digest text;
  v_row    jsonb;
  v_status text;
  v_svc    mt_services;
  v_site   mt_sites;
BEGIN
  IF v_actor = '' THEN
    RAISE EXCEPTION 'adding a location requires the identity of whoever did it'
      USING ERRCODE = 'check_violation';
  END IF;
  IF v_key !~ '^[A-Za-z0-9._:-]{8,128}$' THEN
    RAISE EXCEPTION 'an idempotency key of 8 to 128 letters, digits, dots, underscores, colons or dashes is required'
      USING ERRCODE = 'check_violation';
  END IF;
  IF p_service IS NULL THEN
    RAISE EXCEPTION 'the service is required' USING ERRCODE = 'check_violation';
  END IF;
  IF v_name = '' OR length(v_name) > 120 THEN
    RAISE EXCEPTION 'a location name of 1 to 120 characters is required' USING ERRCODE = 'check_violation';
  END IF;
  IF v_loc IS NOT NULL AND length(v_loc) > 200 THEN
    RAISE EXCEPTION 'a location description is at most 200 characters' USING ERRCODE = 'check_violation';
  END IF;
  v_digest := encode(sha256(convert_to(
    jsonb_build_object('service', p_service, 'name', v_name, 'location', v_loc)::text, 'UTF8')), 'hex');

  v_row := mt_admin_idem_seen('site.create', v_key, v_digest);
  IF v_row IS NOT NULL THEN RETURN jsonb_build_object('replayed', true, 'site', v_row); END IF;

  SELECT s.* INTO v_svc FROM mt_services s WHERE s.id = p_service;
  IF NOT FOUND THEN RETURN NULL; END IF;
  IF v_svc.status <> 'active' THEN
    RAISE EXCEPTION 'the service is %; a location can be added only to an active service', v_svc.status
      USING ERRCODE = 'DN409';
  END IF;
  SELECT c.status INTO v_status FROM mt_customers c WHERE c.id = v_svc.customer_id;
  IF v_status <> 'active' THEN
    RAISE EXCEPTION 'the operator is %; a location can be added only for an active operator', v_status
      USING ERRCODE = 'DN409';
  END IF;

  v_row := mt_admin_idem_claim('site.create', v_key, v_digest, v_actor);
  IF v_row IS NOT NULL THEN RETURN jsonb_build_object('replayed', true, 'site', v_row); END IF;

  INSERT INTO mt_sites (customer_id, service_id, name, location)
  VALUES (v_svc.customer_id, p_service, v_name, v_loc)
  RETURNING * INTO v_site;
  PERFORM mt_audit_write(v_svc.customer_id, v_actor, 'staff', 'site.created',
    'site', v_site.id::text, 'admin',
    jsonb_build_object('operator', v_svc.customer_id, 'service', p_service, 'name', v_name));
  UPDATE mt_admin_idempotency SET result = to_jsonb(v_site) WHERE endpoint = 'site.create' AND key = v_key;
  RETURN jsonb_build_object('replayed', false, 'site', to_jsonb(v_site));
END $$;

REVOKE ALL ON FUNCTION mt_admin_operator_create(text,text,text) FROM PUBLIC;
REVOKE ALL ON FUNCTION mt_admin_service_create(uuid,text,text) FROM PUBLIC;
REVOKE ALL ON FUNCTION mt_admin_site_create(uuid,text,text,text,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mt_admin_operator_create(text,text,text) TO dnb_adminwrite;
GRANT EXECUTE ON FUNCTION mt_admin_service_create(uuid,text,text) TO dnb_adminwrite;
GRANT EXECUTE ON FUNCTION mt_admin_site_create(uuid,text,text,text,text) TO dnb_adminwrite;

COMMENT ON FUNCTION mt_admin_operator_create(text,text,text) IS
  'docs/125 D-4. Creates a Domain-B operator through mt_customer_create, idempotently: the same key for the same name returns the first result and writes nothing (RULE I-1); the same key for another name is refused.';
COMMENT ON FUNCTION mt_admin_service_create(uuid,text,text) IS
  'docs/125 D-5. Starts an active operator''s HotSpot service (the one legal kind), idempotently, audited as service.created with the staff actor.';
COMMENT ON FUNCTION mt_admin_site_create(uuid,text,text,text,text) IS
  'docs/125 D-6. Adds a location to an active service. No operator parameter: the operator is derived from the service row (derive, never accept); 029''s composite key is the floor. Idempotent, audited as site.created.';

RESET ROLE;
REVOKE CREATE ON SCHEMA public FROM dnb_def_prov;

-- ---------------------------------------------------------------------------
-- 3. Verify, or refuse to be applied
-- ---------------------------------------------------------------------------
DO $$
DECLARE
  w  text[] := ARRAY['mt_admin_operator_create(text,text,text)',
                     'mt_admin_service_create(uuid,text,text)',
                     'mt_admin_site_create(uuid,text,text,text,text)'];
  h  text[] := ARRAY['mt_admin_idem_seen(text,text,text)',
                     'mt_admin_idem_claim(text,text,text,text)'];
  f  text;
  r  record;
  n  int;
BEGIN
  FOREACH f IN ARRAY w || h LOOP
    IF (SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE oid = f::regprocedure) <> 'dnb_def_prov' THEN
      RAISE EXCEPTION '030: % is not owned by dnb_def_prov', f;
    END IF;
    SELECT count(*) INTO n FROM pg_proc p, aclexplode(p.proacl) a
     WHERE p.oid = f::regprocedure AND a.grantee = 0;
    IF n <> 0 THEN RAISE EXCEPTION '030: PUBLIC holds EXECUTE on %', f; END IF;
  END LOOP;
  FOREACH f IN ARRAY w LOOP
    IF NOT (SELECT prosecdef FROM pg_proc WHERE oid = f::regprocedure) THEN
      RAISE EXCEPTION '030: % is not SECURITY DEFINER', f;
    END IF;
    IF NOT has_function_privilege('dnb_adminwrite', f, 'EXECUTE') THEN
      RAISE EXCEPTION '030: dnb_adminwrite cannot execute %', f;
    END IF;
    FOR r IN SELECT rolname FROM pg_roles
              WHERE rolname LIKE 'dnb%' AND rolname NOT IN ('dnb_adminwrite', 'dnb_def_prov')
                AND has_function_privilege(rolname, f, 'EXECUTE') LOOP
      RAISE EXCEPTION '030: % may execute % — only dnb_adminwrite may (docs/112 A-1)', r.rolname, f;
    END LOOP;
  END LOOP;
  FOREACH f IN ARRAY h LOOP
    IF (SELECT prosecdef FROM pg_proc WHERE oid = f::regprocedure) THEN
      RAISE EXCEPTION '030: % must run with its caller''s privileges', f;
    END IF;
    FOR r IN SELECT rolname FROM pg_roles
              WHERE rolname LIKE 'dnb%' AND rolname <> 'dnb_def_prov'
                AND has_function_privilege(rolname, f, 'EXECUTE') LOOP
      RAISE EXCEPTION '030: % may execute the internal helper %', r.rolname, f;
    END LOOP;
  END LOOP;
  -- D-6: the location writer takes no operator, by construction.
  IF EXISTS (SELECT 1 FROM pg_proc p, unnest(p.proargnames) a(n)
              WHERE p.oid = 'mt_admin_site_create(uuid,text,text,text,text)'::regprocedure
                AND (a.n ILIKE '%customer%' OR a.n ILIKE '%operator%')) THEN
    RAISE EXCEPTION '030: mt_admin_site_create takes an operator parameter — it must derive it';
  END IF;
  -- D-2: the store belongs to dnb_def_prov and no login role may touch it.
  IF (SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid = 'public.mt_admin_idempotency'::regclass) <> 'dnb_def_prov' THEN
    RAISE EXCEPTION '030: mt_admin_idempotency is not owned by dnb_def_prov';
  END IF;
  FOR r IN SELECT rolname FROM pg_roles WHERE rolcanlogin
              AND (has_table_privilege(oid, 'public.mt_admin_idempotency', 'SELECT')
                OR has_table_privilege(oid, 'public.mt_admin_idempotency', 'INSERT')
                OR has_table_privilege(oid, 'public.mt_admin_idempotency', 'UPDATE')
                OR has_table_privilege(oid, 'public.mt_admin_idempotency', 'DELETE')
                OR has_table_privilege(oid, 'public.mt_admin_idempotency', 'TRUNCATE'))
              AND NOT rolsuper LOOP
    RAISE EXCEPTION '030: login role % holds a privilege on mt_admin_idempotency', r.rolname;
  END LOOP;
  -- D-7: exactly SELECT + INSERT on services and sites, SELECT added on operators.
  IF NOT (has_table_privilege('dnb_def_prov', 'mt_services', 'SELECT') AND has_table_privilege('dnb_def_prov', 'mt_services', 'INSERT')
      AND has_table_privilege('dnb_def_prov', 'mt_sites', 'SELECT') AND has_table_privilege('dnb_def_prov', 'mt_sites', 'INSERT')
      AND has_table_privilege('dnb_def_prov', 'mt_customers', 'SELECT'))
     OR has_table_privilege('dnb_def_prov', 'mt_services', 'UPDATE') OR has_table_privilege('dnb_def_prov', 'mt_services', 'DELETE')
     OR has_table_privilege('dnb_def_prov', 'mt_sites', 'UPDATE') OR has_table_privilege('dnb_def_prov', 'mt_sites', 'DELETE') THEN
    RAISE EXCEPTION '030: dnb_def_prov does not hold exactly the privileges D-7 names';
  END IF;
  SELECT count(*) INTO n FROM information_schema.role_table_grants WHERE grantee = 'dnb_adminwrite';
  IF n <> 0 THEN RAISE EXCEPTION '030: dnb_adminwrite holds a table privilege; it must hold none (W-3)'; END IF;
END $$;
