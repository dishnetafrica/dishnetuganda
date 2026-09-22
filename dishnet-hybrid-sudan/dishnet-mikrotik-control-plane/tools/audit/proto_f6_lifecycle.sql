-- F6 lifecycle extension. Loads ON TOP of proto_f6.sql; changes none of it.
--
-- The question the decision gate asks is "is the AAA identity created at issue
-- or at redemption". Modelling it needs both paths side by side, and needs the
-- three lifecycle events that actually distinguish them: an unsold batch, a
-- revocation, and an expiry.
CREATE TABLE pf_life (k text PRIMARY KEY, v text NOT NULL);
INSERT INTO pf_life VALUES ('aaa_created_at','issue');   -- 'issue' | 'redeem'
ALTER TABLE pf_life OWNER TO proto_def6;

-- Issue a batch. Under Model A the AAA row is written here for every voucher,
-- which is what VoucherService::issue() does today.
CREATE FUNCTION pf_issue_batch(p_customer uuid, p_site uuid, p_n integer, p_prefix text)
RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $fn$
DECLARE i integer; vid uuid; c record; m text;
BEGIN
  SELECT v INTO m FROM pf_life WHERE k='aaa_created_at';
  SELECT * INTO c FROM pf_customers WHERE id = p_customer;
  FOR i IN 1..p_n LOOP
    vid := gen_random_uuid();
    INSERT INTO pf_vouchers (id,customer_id,site_id,code,duration_s)
    VALUES (vid, p_customer, p_site, p_prefix||'-'||lpad(i::text,5,'0'), 60);
    IF m = 'issue' THEN
      INSERT INTO pf_hotspot_users (voucher_id, customer_id, radius_username)
      VALUES (vid, p_customer, c.radius_ref||'-'||replace(p_prefix||lpad(i::text,5,'0'),'-',''));
    END IF;
  END LOOP;
  RETURN p_n;
END $fn$;
ALTER FUNCTION pf_issue_batch(uuid,uuid,integer,text) OWNER TO proto_def6;

-- Redemption under Model B also creates the AAA identity.
CREATE FUNCTION pf_redeem_life(p_code text, p_nas text)
RETURNS TABLE (ok boolean, reason text) LANGUAGE plpgsql SECURITY DEFINER
SET search_path = public, pg_temp AS $fn$
DECLARE v record; c record; m text;
BEGIN
  SELECT pf_life.v INTO m FROM pf_life WHERE k='aaa_created_at';
  SELECT * INTO v FROM pf_vouchers WHERE code = upper(btrim(p_code)) AND state='unused';
  IF NOT FOUND THEN RETURN QUERY SELECT false,'invalid'::text; RETURN; END IF;
  UPDATE pf_vouchers AS tgt SET state='active', activated_at=now(),
         expires_at = now() + (tgt.duration_s || ' seconds')::interval
   WHERE tgt.id = v.id AND tgt.state='unused';
  IF m = 'redeem' THEN
    SELECT * INTO c FROM pf_customers WHERE id = v.customer_id;
    INSERT INTO pf_hotspot_users (voucher_id, customer_id, radius_username)
    VALUES (v.id, v.customer_id, c.radius_ref||'-'||replace(v.code,'-',''))
    ON CONFLICT (voucher_id) DO NOTHING;
  END IF;
  RETURN QUERY SELECT true,'ok'::text;
END $fn$;
ALTER FUNCTION pf_redeem_life(text,text) OWNER TO proto_def6;

-- Revocation exactly as VoucherService::revoke() performs it: a state flip on
-- the voucher, and nothing else. The AAA row is deliberately left alone,
-- because that is what the production code does.
CREATE FUNCTION pf_revoke_asis(p_voucher uuid) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $fn$
BEGIN
  UPDATE pf_vouchers SET state='revoked', revoked_at=now()
   WHERE id = p_voucher AND state IN ('unused','active');
  RETURN FOUND;
END $fn$;
ALTER FUNCTION pf_revoke_asis(uuid) OWNER TO proto_def6;

-- Counting helper, so the runner can observe without table grants.
CREATE FUNCTION pf_counts() RETURNS text LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = public, pg_temp AS $fn$
  SELECT 'vouchers='||(SELECT count(*) FROM pf_vouchers)
       ||' aaa_identities='||(SELECT count(*) FROM pf_hotspot_users)
       ||' redeemed='||(SELECT count(*) FROM pf_vouchers WHERE state='active')
       ||' revoked='||(SELECT count(*) FROM pf_vouchers WHERE state='revoked');
$fn$;
ALTER FUNCTION pf_counts() OWNER TO proto_def6;

REVOKE EXECUTE ON FUNCTION pf_issue_batch(uuid,uuid,integer,text) FROM PUBLIC;
REVOKE EXECUTE ON FUNCTION pf_redeem_life(text,text)              FROM PUBLIC;
REVOKE EXECUTE ON FUNCTION pf_revoke_asis(uuid)                   FROM PUBLIC;
REVOKE EXECUTE ON FUNCTION pf_counts()                            FROM PUBLIC;
GRANT  EXECUTE ON FUNCTION pf_redeem_life(text,text) TO proto_portal;
GRANT  EXECUTE ON FUNCTION pf_counts()               TO proto_portal, proto_rad6, proto_adm6;
