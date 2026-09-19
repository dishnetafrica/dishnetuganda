-- 013 — admin operations on devices
--
-- A device belongs to nobody until it is assigned, and mt_devices' policy is
-- WITH CHECK (customer_id = mt_current_customer()). NULL never satisfies that,
-- so unassigned stock cannot be inserted at all under a customer context —
-- and there is no customer context to give it, because it has no customer.
--
-- That is the policy doing its job, not a mistake in it. Relaxing it to
-- "... OR customer_id IS NULL" would let every customer read all unassigned
-- stock, which is worse than the problem.
--
-- So admin device operations take the same narrow route as authentication and
-- accounting: SECURITY DEFINER functions with a surface small enough to read
-- in one sitting. Each is a hole deliberately cut, not a policy weakened.
--
-- These are ADMIN entry points. When the /admin surface is built they will sit
-- behind a staff principal; today nothing customer-facing can reach them,
-- because no route calls them.

CREATE OR REPLACE FUNCTION mt_device_register(
  p_serial text, p_model text, p_ros text,
  p_wg_pubkey text, p_tunnel_ip text, p_staged_by text
) RETURNS mt_devices
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE r mt_devices; staged boolean := p_staged_by IS NOT NULL AND p_staged_by <> '';
BEGIN
  INSERT INTO mt_devices (serial, model, ros_version, wg_pubkey, tunnel_ip,
                          staged_by, staged_at, state)
  VALUES (p_serial, p_model, p_ros, p_wg_pubkey, p_tunnel_ip, p_staged_by,
          CASE WHEN staged THEN now() END,
          CASE WHEN staged THEN 'staged' ELSE 'registered' END)
  RETURNING * INTO r;
  RETURN r;
END $$;

CREATE OR REPLACE FUNCTION mt_device_assign(
  p_device uuid, p_customer uuid, p_site uuid, p_name text
) RETURNS mt_devices
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE r mt_devices;
BEGIN
  UPDATE mt_devices SET customer_id = p_customer, site_id = p_site,
                        name = p_name, claimed_at = now()
   WHERE id = p_device RETURNING * INTO r;
  RETURN r;
END $$;

CREATE OR REPLACE FUNCTION mt_device_set_state(p_device uuid, p_state text)
RETURNS mt_devices
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE r mt_devices;
BEGIN
  UPDATE mt_devices SET state = p_state WHERE id = p_device RETURNING * INTO r;
  RETURN r;
END $$;

GRANT EXECUTE ON FUNCTION mt_device_register(text,text,text,text,text,text) TO dnb_app;
GRANT EXECUTE ON FUNCTION mt_device_assign(uuid,uuid,uuid,text)             TO dnb_app;
GRANT EXECUTE ON FUNCTION mt_device_set_state(uuid,text)                    TO dnb_app;
