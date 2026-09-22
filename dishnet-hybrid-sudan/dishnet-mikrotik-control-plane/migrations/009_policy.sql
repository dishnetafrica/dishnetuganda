-- 009 — the policy plane
--
-- Two tables, and the split between them is the whole point (docs/42 §8.2):
--
--   mt_plans     WHAT THE CUSTOMER SELLS.  Name, duration, devices, speed,
--                price. Theirs. DishNet neither sets nor approves any of it.
--   mt_profiles  HOW IT IS ENFORCED.       The RADIUS realisation. DishNet's.
--                DERIVED from a plan's values, never chosen by a customer,
--                never named to one.
--
-- Keeping them apart means a price change cannot touch enforcement, and the
-- customer never has to know RouterOS exists.
--
-- *** F9. THERE IS NO COMMERCIAL CEILING IN THIS FILE. ***
--
-- A plan is checked for one thing only: whether the values can be EXPRESSED.
-- "That rate cannot be written into the RADIUS attribute" is a fact about the
-- protocol and stays. "You did not buy that much" is a ceiling and is wrong —
-- the customer's uplink is their own and DishNet does not ration it (F8).
-- No column here references an entitlement, and a guard asserts no code does.

CREATE TABLE mt_profiles (
  id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  rate_down_bps      bigint  NOT NULL,
  rate_up_bps        bigint  NOT NULL,
  session_timeout_s  integer NOT NULL,
  shared_users       integer NOT NULL,
  data_cap_bytes     bigint,
  created_at         timestamptz NOT NULL DEFAULT now(),
  -- Deduplicated on the technical tuple: two customers selling the same shape
  -- of access share one enforcement profile, and neither can discover the
  -- other by observing it.
  UNIQUE (rate_down_bps, rate_up_bps, session_timeout_s, shared_users, data_cap_bytes)
);

COMMENT ON TABLE mt_profiles IS
  'DishNet-owned enforcement. Derived from a plan, never chosen by a customer. '
  'No customer-facing route exposes this table or its id.';

CREATE TABLE mt_plans (
  id                   uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id          uuid NOT NULL REFERENCES mt_customers(id) ON DELETE RESTRICT,
  site_id              uuid REFERENCES mt_sites(id) ON DELETE SET NULL,
  profile_id           uuid NOT NULL REFERENCES mt_profiles(id) ON DELETE RESTRICT,

  name                 text    NOT NULL,
  duration_s           integer NOT NULL CHECK (duration_s > 0),
  rate_down_bps        bigint  NOT NULL CHECK (rate_down_bps > 0),
  rate_up_bps          bigint  NOT NULL CHECK (rate_up_bps   > 0),
  data_cap_bytes       bigint           CHECK (data_cap_bytes IS NULL OR data_cap_bytes > 0),
  devices_per_voucher  integer NOT NULL CHECK (devices_per_voucher > 0),
  mode                 text    NOT NULL CHECK (mode IN ('elapsed','paused')),

  -- Integer minor units, always. The plugin learned this once already: a
  -- float UGX amount is a rounding bug waiting for month-end.
  -- Zero is PERMITTED: a hotel handing a loyalty guest free access is a
  -- normal thing to want, and refusing it would be a commercial opinion.
  price_minor          bigint  NOT NULL CHECK (price_minor >= 0),
  currency             char(3) NOT NULL,

  active               boolean NOT NULL DEFAULT true,
  created_by           uuid REFERENCES mt_principals(id) ON DELETE SET NULL,
  created_at           timestamptz NOT NULL DEFAULT now(),
  updated_at           timestamptz NOT NULL DEFAULT now(),
  UNIQUE (customer_id, name)
);
CREATE INDEX mt_plans_customer_ix ON mt_plans (customer_id, active);

COMMENT ON COLUMN mt_plans.price_minor IS
  'The customer sets this. DishNet does not set it, cap it or approve it (F9).';

ALTER TABLE mt_plans ENABLE ROW LEVEL SECURITY;
ALTER TABLE mt_plans FORCE  ROW LEVEL SECURITY;
CREATE POLICY mt_plans_isolation ON mt_plans
  USING (customer_id = mt_current_customer())
  WITH CHECK (customer_id = mt_current_customer());

-- mt_profiles carries no customer_id and is never exposed by a customer
-- route, so it has no isolation policy to write. What it must NOT do is let
-- a customer read it, and the grant below is the whole of that: SELECT is
-- needed to resolve a plan's profile, nothing more.
REVOKE ALL ON mt_profiles FROM dnb_app;
GRANT SELECT, INSERT ON mt_profiles TO dnb_app;

-- A plan is never deleted. It is retired (active = false), because a voucher
-- sold against it is a revenue record and a deleted plan orphans it.
-- docs/43 §C: expire, never delete.
CREATE OR REPLACE FUNCTION mt_plans_no_delete() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN
  RAISE EXCEPTION 'plans are retired, not deleted: set active = false'
    USING ERRCODE = 'DN409';
END $$;
CREATE TRIGGER mt_plans_no_delete BEFORE DELETE ON mt_plans
  FOR EACH ROW EXECUTE FUNCTION mt_plans_no_delete();

CREATE OR REPLACE FUNCTION mt_plans_touch() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN NEW.updated_at := now(); RETURN NEW; END $$;
CREATE TRIGGER mt_plans_touch BEFORE UPDATE ON mt_plans
  FOR EACH ROW EXECUTE FUNCTION mt_plans_touch();
