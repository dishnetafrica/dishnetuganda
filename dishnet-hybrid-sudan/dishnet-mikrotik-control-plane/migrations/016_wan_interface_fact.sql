-- 016 — remediation of audit finding R4 (docs/57 §1.1)
--
-- `ether1` WAS A GUESS THAT PRODUCED PLAUSIBLE NUMBERS.
--
-- UplinkSampler::wan() picked the interface named `ether1` off whatever the
-- router returned. On a unit where ether1 is not the WAN, that measures a LAN
-- bridge or the tunnel and stores throughput that looks entirely reasonable.
-- Those samples are the evidence in a support-boundary conversation, and they
-- would have been wrong in the customer's favour or DishNet's at random.
--
-- Unlike the security findings, this one fails QUIETLY. Nothing errors, no test
-- goes red, and the graph is beautiful. The test that "proved" it worked fed a
-- fake an interface named ether1 and then asserted the code found ether1.
--
-- Which interface carries the uplink is not derivable from anything the control
-- plane holds. It is not a function of model, and it cannot be inferred from a
-- name. It is a FACT ESTABLISHED WITH THE DEVICE IN HAND, at staging
-- (docs/31 §3.1), by someone who can see which port the uplink is plugged into.
-- So it is stored like every other staging fact, with who established it and
-- when, and the sampler reads it rather than guessing.
--
-- The rule that follows is the same one the sampler already applies to an
-- unreachable router: WHEN THE FACT IS ABSENT, RECORD NOTHING. Not a zero, not
-- a fallback interface, not a best guess. No measurement is a truthful state;
-- an invented measurement is not.

ALTER TABLE mt_devices
  ADD COLUMN wan_interface        text,
  ADD COLUMN wan_interface_set_by text,
  ADD COLUMN wan_interface_set_at timestamptz;

COMMENT ON COLUMN mt_devices.wan_interface IS
  'RouterOS interface carrying the uplink, established at staging with the '
  'device in hand. NULL means NOT ESTABLISHED, and the sampler records no WAN '
  'measurement for the device. Never defaulted, never inferred from the model.';

-- An empty string is not an established fact, and neither is a name whose
-- origin nobody recorded. The provenance is half the point: a WAN interface
-- that appeared without an author is the guess this migration exists to remove.
ALTER TABLE mt_devices
  ADD CONSTRAINT mt_devices_wan_shape CHECK (
    wan_interface IS NULL OR btrim(wan_interface) <> ''),
  ADD CONSTRAINT mt_devices_wan_provenance CHECK (
    (wan_interface IS NULL     AND wan_interface_set_by IS NULL
                               AND wan_interface_set_at IS NULL)
 OR (wan_interface IS NOT NULL AND wan_interface_set_by IS NOT NULL
                               AND wan_interface_set_at IS NOT NULL));

-- ---------------------------------------------------------------------------
-- Establishing the fact is an ADMIN operation, like every other staging write
-- (migration 015). A customer cannot set it: they are not holding the device,
-- and a customer-settable WAN interface would be a customer-settable telemetry
-- source.
--
-- No value is rejected for looking like a guess — `ether1` may genuinely BE the
-- WAN on some unit, and refusing it would just be a different assumption. What
-- is required is that a person establish it and be recorded as having done so.
CREATE FUNCTION mt_device_set_wan(p_device uuid, p_interface text, p_by text)
RETURNS mt_devices
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE r mt_devices;
BEGIN
  IF p_interface IS NULL OR btrim(p_interface) = '' THEN
    RAISE EXCEPTION 'wan interface must be a non-empty name established at staging'
      USING ERRCODE = 'check_violation';
  END IF;
  IF p_by IS NULL OR btrim(p_by) = '' THEN
    RAISE EXCEPTION 'wan interface requires the identity of whoever established it'
      USING ERRCODE = 'check_violation';
  END IF;

  UPDATE mt_devices
     SET wan_interface        = btrim(p_interface),
         wan_interface_set_by = btrim(p_by),
         wan_interface_set_at = now()
   WHERE id = p_device
  RETURNING * INTO r;
  RETURN r;
END $$;

-- ---------------------------------------------------------------------------
-- The sampler needs the established fact alongside the address. It still gets
-- nothing else: the function exists so a cross-customer worker can find its
-- work without BYPASSRLS, not so it can browse the device table.
DROP FUNCTION IF EXISTS mt_devices_samplable();
CREATE FUNCTION mt_devices_samplable()
RETURNS TABLE (id uuid, tunnel_ip text, customer_id uuid, wan_interface text)
LANGUAGE sql SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT d.id, d.tunnel_ip, d.customer_id, d.wan_interface
    FROM mt_devices d
   WHERE d.customer_id IS NOT NULL
     AND d.tunnel_ip IS NOT NULL
     AND d.state IN ('provisioned','active','diverged');
$$;

-- Deliberately NOT filtered on wan_interface IS NOT NULL. A device whose WAN
-- nobody established still needs to appear, counted as unmeasurable, so the gap
-- is visible as a provisioning omission. Filtering it out would hide the very
-- thing this migration is about.

GRANT EXECUTE ON FUNCTION mt_device_set_wan(uuid,text,text) TO dnb_admin;
GRANT EXECUTE ON FUNCTION mt_devices_samplable()            TO dnb_worker;

-- ---------------------------------------------------------------------------
-- Keeping migration 015's privilege sweep true for functions added later.
--
-- CREATE FUNCTION grants EXECUTE to PUBLIC. Migration 015 swept that away for
-- everything existing at the time, and reached for ALTER DEFAULT PRIVILEGES to
-- cover what came next. That does not work: on PostgreSQL 16.13 a default-
-- privileges REVOKE of the built-in PUBLIC EXECUTE stores no catalogue row at
-- all, and even a hand-materialised row excluding PUBLIC is not applied to
-- functions created afterwards (docs/57 §12.2). The two functions above proved
-- it — both came out PUBLIC-executable under a migration that claimed
-- otherwise, and the S1/S2 suite caught them.
--
-- So the sweep becomes something a migration can CALL. It touches PUBLIC only,
-- never the application roles, so the explicit grants each migration makes
-- survive it and calling it twice is harmless. Every migration that adds a
-- function ends with SELECT mt_revoke_public_execute(); the S1/S2 suite fails
-- if one does not.
CREATE OR REPLACE FUNCTION mt_revoke_public_execute() RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE f regprocedure; n integer := 0;
BEGIN
  FOR f IN SELECT p.oid::regprocedure
             FROM pg_proc p JOIN pg_namespace nsp ON nsp.oid = p.pronamespace
            WHERE nsp.nspname = 'public'
              AND p.proname LIKE 'mt\_%'
              AND EXISTS (SELECT 1 FROM aclexplode(p.proacl) a WHERE a.grantee = 0)
  LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC', f);
    n := n + 1;
  END LOOP;

  -- A function whose proacl is still NULL has never been touched, so it
  -- carries the built-in default — PUBLIC included. The loop above cannot see
  -- those, because NULL means "no explicit ACL", not "no PUBLIC grant".
  FOR f IN SELECT p.oid::regprocedure
             FROM pg_proc p JOIN pg_namespace nsp ON nsp.oid = p.pronamespace
            WHERE nsp.nspname = 'public'
              AND p.proname LIKE 'mt\_%'
              AND p.proacl IS NULL
  LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC', f);
    n := n + 1;
  END LOOP;
  RETURN n;
END $$;

REVOKE ALL ON FUNCTION mt_revoke_public_execute() FROM PUBLIC;

SELECT mt_revoke_public_execute();
