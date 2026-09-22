-- 010 — vouchers, batches, and the projection into AAA
--
-- A voucher is a credential the customer sells. A HotSpot user is what the
-- network authenticates. They are not the same thing, and neither is a
-- DishNet account (docs/45 §2.1): a guest who redeems a code does not become
-- a customer.

-- ---------------------------------------------------------------------------
-- A short, unique, per-customer token for namespacing RADIUS usernames.
--
-- docs/30 §370 proposes t{tenant_id}-{code} so one customer's code cannot
-- authenticate on another's NAS. Deriving that prefix from a slice of the
-- customer UUID would make collisions merely unlikely; a unique column makes
-- them impossible. The difference matters because the failure mode is one
-- customer's voucher working on another customer's router.
--
-- The DEFAULT matters as much as the constraint. A NOT NULL column with no
-- default makes every future INSERT fail — which is a production bug, not
-- just a fixture one, and it surfaced here only because the test seeder
-- creates customers. Derived from a fresh random uuid rather than the row's
-- own id, because a DEFAULT cannot see the row being inserted.
ALTER TABLE mt_customers ADD COLUMN radius_ref text
  DEFAULT ('c' || substr(replace(gen_random_uuid()::text, '-', ''), 1, 10));
UPDATE mt_customers SET radius_ref = 'c' || substr(replace(id::text, '-', ''), 1, 10)
 WHERE radius_ref IS NULL;
ALTER TABLE mt_customers ALTER COLUMN radius_ref SET NOT NULL;
ALTER TABLE mt_customers ADD CONSTRAINT mt_customers_radius_ref_uq UNIQUE (radius_ref);

-- ---------------------------------------------------------------------------
CREATE TABLE mt_voucher_batches (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id      uuid NOT NULL REFERENCES mt_customers(id) ON DELETE RESTRICT,
  site_id          uuid REFERENCES mt_sites(id) ON DELETE SET NULL,
  plan_id          uuid NOT NULL REFERENCES mt_plans(id) ON DELETE RESTRICT,
  requested_count  integer NOT NULL CHECK (requested_count > 0),
  issued_count     integer NOT NULL DEFAULT 0,
  state            text NOT NULL DEFAULT 'issuing'
                   CHECK (state IN ('issuing','issued','failed')),
  created_by       uuid REFERENCES mt_principals(id) ON DELETE SET NULL,
  created_at       timestamptz NOT NULL DEFAULT now(),
  completed_at     timestamptz
);
CREATE INDEX mt_voucher_batches_customer_ix ON mt_voucher_batches (customer_id, created_at DESC);

CREATE TABLE mt_vouchers (
  id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id    uuid NOT NULL REFERENCES mt_customers(id) ON DELETE RESTRICT,
  batch_id       uuid REFERENCES mt_voucher_batches(id) ON DELETE RESTRICT,
  plan_id        uuid NOT NULL REFERENCES mt_plans(id) ON DELETE RESTRICT,
  site_id        uuid REFERENCES mt_sites(id) ON DELETE SET NULL,

  -- Unique GLOBALLY, not per customer: the code is what a guest types, and
  -- two identical codes on two customers' routers would be one namespacing
  -- mistake away from authenticating on the wrong one.
  code           text NOT NULL UNIQUE,

  state          text NOT NULL DEFAULT 'unused'
                 CHECK (state IN ('unused','active','expired','revoked')),

  -- Snapshot, not a reference. If the customer changes the plan's price next
  -- month, what this voucher SOLD for must not change with it — that would
  -- rewrite revenue that has already happened.
  price_minor    bigint  NOT NULL,
  currency       char(3) NOT NULL,
  duration_s     integer NOT NULL,

  created_at     timestamptz NOT NULL DEFAULT now(),
  activated_at   timestamptz,
  expires_at     timestamptz,
  revoked_at     timestamptz,
  sold_at        timestamptz,
  sold_by        uuid REFERENCES mt_principals(id) ON DELETE SET NULL
);
CREATE INDEX mt_vouchers_customer_ix ON mt_vouchers (customer_id, state, created_at DESC);
CREATE INDEX mt_vouchers_batch_ix    ON mt_vouchers (batch_id);

-- The projection of a voucher into AAA. One row per voucher, created when
-- the intent to publish it is confirmed.
CREATE TABLE mt_hotspot_users (
  voucher_id       uuid PRIMARY KEY REFERENCES mt_vouchers(id) ON DELETE RESTRICT,
  customer_id      uuid NOT NULL REFERENCES mt_customers(id) ON DELETE RESTRICT,
  radius_username  text NOT NULL UNIQUE,
  created_at       timestamptz NOT NULL DEFAULT now()
);

COMMENT ON TABLE mt_hotspot_users IS
  'A HotSpot user is a network identity, not a DishNet account. Redeeming a '
  'voucher creates one of these and nothing else (docs/45 §2.1).';

-- ---------------------------------------------------------------------------
DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY['mt_voucher_batches','mt_vouchers','mt_hotspot_users'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE  ROW LEVEL SECURITY', t);
    EXECUTE format(
      'CREATE POLICY %I ON %I USING (customer_id = mt_current_customer()) '
      || 'WITH CHECK (customer_id = mt_current_customer())', t || '_isolation', t);
  END LOOP;
END $$;

-- Expire, never delete (docs/43 §C). A voucher is a revenue record: deleting
-- a used one destroys the ability to reconcile what was sold.
CREATE OR REPLACE FUNCTION mt_vouchers_no_delete() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN
  RAISE EXCEPTION 'vouchers are expired or revoked, not deleted'
    USING ERRCODE = 'DN409';
END $$;
CREATE TRIGGER mt_vouchers_no_delete BEFORE DELETE ON mt_vouchers
  FOR EACH ROW EXECUTE FUNCTION mt_vouchers_no_delete();

-- ---------------------------------------------------------------------------
-- Redemption. THE ONE OPERATION THAT MUST NOT HAPPEN TWICE.
--
-- Two guests typing the same code at the same moment is not hypothetical —
-- it is what happens when a code is shared. The guard is the WHERE clause:
-- only a row still in 'unused' can move, so the second transaction blocks on
-- the row lock, re-evaluates, matches nothing, and returns empty.
--
-- SECURITY DEFINER because redemption arrives from the network side, which
-- has no customer context: the code is the only thing presented, and which
-- customer it belongs to is the answer, not the input.
CREATE OR REPLACE FUNCTION mt_voucher_redeem(p_code text)
RETURNS TABLE (voucher_id uuid, customer_id uuid, duration_s integer, expires_at timestamptz)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
  RETURN QUERY
  UPDATE mt_vouchers v
     SET state = 'active',
         activated_at = now(),
         expires_at = now() + (v.duration_s || ' seconds')::interval
   WHERE v.code = p_code
     AND v.state = 'unused'
  RETURNING v.id, v.customer_id, v.duration_s, v.expires_at;
END $$;

GRANT EXECUTE ON FUNCTION mt_voucher_redeem(text) TO dnb_app;
