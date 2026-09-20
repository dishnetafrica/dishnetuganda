CREATE EXTENSION IF NOT EXISTS pgcrypto;
GRANT USAGE ON SCHEMA public TO cap_admin, cap_def;
CREATE TABLE cp_data (id bigserial PRIMARY KEY, customer_id uuid NOT NULL, secret_text text);
CREATE INDEX ON cp_data (customer_id);
CREATE TABLE cp_caps (cap_hash text PRIMARY KEY, customer_id uuid NOT NULL, operation text NOT NULL,
                      expires_at timestamptz NOT NULL, consumed_at timestamptz, admin_id text NOT NULL);
CREATE TABLE cp_audit (id bigserial PRIMARY KEY, at timestamptz DEFAULT now(), what text);

-- A: reusable until expiry
CREATE FUNCTION cp_scope_reusable() RETURNS uuid
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE v uuid;
BEGIN
  SELECT c.customer_id INTO v FROM cp_caps c
   WHERE c.cap_hash = encode(digest(NULLIF(current_setting('app.admin_cap',true),''),'sha256'),'hex')
     AND c.expires_at > now();
  RETURN v;
END $$;

-- B: single use. Consumption is an atomic UPDATE ... WHERE consumed_at IS NULL
-- so two concurrent claimants cannot both win. It cannot live in the STABLE
-- resolver (a STABLE function may not write), so consumption is a separate
-- explicit step the caller performs once per operation.
CREATE FUNCTION cp_consume(p_cap text) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE v uuid;
BEGIN
  UPDATE cp_caps SET consumed_at = now()
   WHERE cap_hash = encode(digest(p_cap,'sha256'),'hex')
     AND consumed_at IS NULL AND expires_at > now()
  RETURNING customer_id INTO v;
  RETURN v;   -- NULL means already consumed, expired, or unknown
END $$;

CREATE FUNCTION cp_scope_single() RETURNS uuid
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE v uuid;
BEGIN
  SELECT c.customer_id INTO v FROM cp_caps c
   WHERE c.cap_hash = encode(digest(NULLIF(current_setting('app.admin_cap',true),''),'sha256'),'hex')
     AND c.expires_at > now() AND c.consumed_at IS NOT NULL
     AND c.consumed_at > now() - interval '30 seconds';   -- the operation window
  RETURN v;
END $$;

ALTER FUNCTION cp_scope_reusable() OWNER TO cap_def;
ALTER FUNCTION cp_consume(text)    OWNER TO cap_def;
ALTER FUNCTION cp_scope_single()   OWNER TO cap_def;
GRANT SELECT, INSERT, UPDATE ON cp_caps TO cap_def;
GRANT SELECT, INSERT, UPDATE, DELETE ON cp_data TO cap_admin;
GRANT USAGE, SELECT ON SEQUENCE cp_data_id_seq TO cap_admin;
DO $$ DECLARE f regprocedure; BEGIN
  FOR f IN SELECT oid::regprocedure FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
            WHERE n.nspname='public' AND p.proname LIKE 'cp\_%'
  LOOP EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC',f); END LOOP; END $$;
GRANT EXECUTE ON FUNCTION cp_scope_reusable(), cp_consume(text), cp_scope_single() TO cap_admin;

ALTER TABLE cp_data ENABLE ROW LEVEL SECURITY; ALTER TABLE cp_data FORCE ROW LEVEL SECURITY;
ALTER TABLE cp_caps ENABLE ROW LEVEL SECURITY; ALTER TABLE cp_caps FORCE ROW LEVEL SECURITY;
CREATE POLICY caps_def ON cp_caps FOR ALL TO cap_def USING (true) WITH CHECK (true);

INSERT INTO cp_data (customer_id, secret_text) VALUES
 ('11111111-1111-4111-8111-111111111111','P-PRIVATE'),
 ('22222222-2222-4222-8222-222222222222','Q-PRIVATE');
