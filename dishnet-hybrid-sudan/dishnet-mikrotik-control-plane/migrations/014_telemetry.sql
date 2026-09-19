-- 014 — uplink telemetry
--
-- MEASURE AND INFORM. NEVER GATE. (F13, docs/53.)
--
-- The customer's uplink is theirs. DishNet does not sell it, ration it or
-- price it (F8), and nothing measured here may become a limit. That is not a
-- policy this file states and hopes for: there is no column, no function and
-- no code path that turns a sample into a refusal, and the suite proves it
-- behaviourally by saturating the readings and checking that every customer
-- operation still succeeds unchanged.
--
-- What this exists for is the support boundary (C17). "The internet is slow"
-- has a cause on either side of the line, and without measurement the two are
-- indistinguishable, so every complaint escalates to DishNet by default.

CREATE TABLE mt_uplink_samples (
  device_id      uuid NOT NULL REFERENCES mt_devices(id) ON DELETE RESTRICT,
  customer_id    uuid NOT NULL REFERENCES mt_customers(id) ON DELETE RESTRICT,
  at             timestamptz NOT NULL DEFAULT now(),

  -- Observed throughput. NOT a capacity, NOT an allowance, NOT a percentage:
  -- a percentage would need a denominator DishNet does not own and, with
  -- Starlink, one that varies by the minute. See §UTILISATION in the README.
  rx_bps         bigint NOT NULL CHECK (rx_bps >= 0),
  tx_bps         bigint NOT NULL CHECK (tx_bps >= 0),
  session_count  integer NOT NULL DEFAULT 0 CHECK (session_count >= 0),

  PRIMARY KEY (device_id, at)
);
CREATE INDEX mt_uplink_samples_customer_ix ON mt_uplink_samples (customer_id, at DESC);

COMMENT ON TABLE mt_uplink_samples IS
  'Observation only. No code reads this table to decide anything. F13.';

ALTER TABLE mt_uplink_samples ENABLE ROW LEVEL SECURITY;
ALTER TABLE mt_uplink_samples FORCE  ROW LEVEL SECURITY;
CREATE POLICY mt_uplink_samples_isolation ON mt_uplink_samples
  USING (customer_id = mt_current_customer())
  WITH CHECK (customer_id = mt_current_customer());

-- Samples arrive from a worker, which has no customer context until it knows
-- which device it read. Same narrow route as every other system-side write.
CREATE OR REPLACE FUNCTION mt_uplink_record(
  p_device uuid, p_rx bigint, p_tx bigint, p_sessions integer
) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_customer uuid;
BEGIN
  SELECT customer_id INTO v_customer FROM mt_devices WHERE id = p_device;
  -- A device belonging to nobody produces no sample: telemetry for unassigned
  -- stock would have no one to show it to.
  IF v_customer IS NULL THEN RETURN false; END IF;

  INSERT INTO mt_uplink_samples (device_id, customer_id, rx_bps, tx_bps, session_count)
  VALUES (p_device, v_customer, GREATEST(p_rx, 0), GREATEST(p_tx, 0), GREATEST(p_sessions, 0))
  ON CONFLICT (device_id, at) DO NOTHING;
  RETURN true;
END $$;

-- Samples accumulate quickly. Unlike a voucher or a session, a sample is not
-- evidence of anything sold, so it is the one thing here that CAN be deleted.
CREATE OR REPLACE FUNCTION mt_uplink_prune(p_keep interval)
RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE n integer;
BEGIN
  DELETE FROM mt_uplink_samples WHERE at < now() - p_keep;
  GET DIAGNOSTICS n = ROW_COUNT;
  RETURN n;
END $$;

GRANT EXECUTE ON FUNCTION mt_uplink_record(uuid,bigint,bigint,integer) TO dnb_app;
GRANT EXECUTE ON FUNCTION mt_uplink_prune(interval)                    TO dnb_app;

-- ---------------------------------------------------------------------------
-- Which devices the sampler should read.
--
-- The sampler serves every customer and cannot set app.customer_id before it
-- knows whose device it is about to read, so under RLS an ordinary SELECT
-- returns nothing. This is the third system-side worker to need such a
-- function — the intent claim and the device admin path were the others —
-- which makes it a structural property of the design rather than an
-- oversight: a worker that legitimately spans customers gets a narrow,
-- auditable function, never a relaxed policy.
--
-- It returns the two fields the sampler needs and no others. Credentials are
-- fetched separately, so this function cannot be used to enumerate them.
CREATE OR REPLACE FUNCTION mt_devices_samplable()
RETURNS TABLE (id uuid, tunnel_ip text)
LANGUAGE sql SECURITY DEFINER SET search_path = public, pg_temp AS $$
  SELECT d.id, d.tunnel_ip
    FROM mt_devices d
   WHERE d.customer_id IS NOT NULL
     AND d.tunnel_ip IS NOT NULL
     AND d.state IN ('provisioned','active','diverged');
$$;

GRANT EXECUTE ON FUNCTION mt_devices_samplable() TO dnb_app;
