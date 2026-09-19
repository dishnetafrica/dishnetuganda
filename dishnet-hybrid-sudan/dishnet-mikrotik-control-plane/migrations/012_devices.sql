-- 012 — the device plane
--
-- A router DishNet staged, shipped and manages.
--
-- Nothing here is built for BYO (docs/53 §4): every column assumes DishNet
-- held the device at staging (docs/31 §3.1), and C20 remains open.

CREATE TABLE mt_devices (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id     uuid REFERENCES mt_customers(id) ON DELETE RESTRICT,   -- NULL until assigned
  site_id         uuid REFERENCES mt_sites(id) ON DELETE SET NULL,

  -- Identity established at staging, with possession (docs/31 §3.1 step 11).
  serial          text NOT NULL UNIQUE,
  model           text NOT NULL,
  ros_version     text,
  wg_pubkey       text UNIQUE,
  tunnel_ip       text UNIQUE,

  name            text,          -- friendly, customer-facing
  state           text NOT NULL DEFAULT 'registered' CHECK (state IN (
                    'registered','staged','shipped','connected',
                    'provisioned','active','orphaned','diverged','decommissioned')),

  last_seen_at    timestamptz,
  staged_at       timestamptz,
  staged_by       text,          -- WHO staged it: the audit half of the trust anchor
  claimed_at      timestamptz,

  created_at      timestamptz NOT NULL DEFAULT now(),
  updated_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX mt_devices_customer_ix ON mt_devices (customer_id, state);
CREATE INDEX mt_devices_site_ix     ON mt_devices (site_id);

-- Desired and actual, both stored; divergence is COMPUTED.
-- A third stored copy is a third thing to be wrong.
CREATE TABLE mt_device_config (
  device_id      uuid PRIMARY KEY REFERENCES mt_devices(id) ON DELETE RESTRICT,
  desired        jsonb NOT NULL DEFAULT '{}'::jsonb,
  actual         jsonb NOT NULL DEFAULT '{}'::jsonb,
  desired_at     timestamptz,
  actual_read_at timestamptz
);

-- ---------------------------------------------------------------------------
-- Management credentials, encrypted at rest, per device.
--
-- docs/30 Artifact 7 principle 3. The column holds an AEAD envelope, so a
-- database dump, a replica, a backup tape and an errant SELECT all yield
-- ciphertext. There is no column anywhere that holds a router password in
-- the clear, and a guard asserts it.
CREATE TABLE mt_device_secrets (
  device_id     uuid PRIMARY KEY REFERENCES mt_devices(id) ON DELETE RESTRICT,
  username      text NOT NULL,
  secret_sealed text NOT NULL,     -- AEAD envelope; never plaintext
  rotated_at    timestamptz NOT NULL DEFAULT now()
);

COMMENT ON COLUMN mt_device_secrets.secret_sealed IS
  'AEAD envelope. Never a password. If this column is ever readable as a '
  'password, the encryption has been removed rather than misconfigured.';

-- The app role may read secrets to use them and may not delete them.
REVOKE ALL ON mt_device_secrets FROM dnb_app;
GRANT SELECT, INSERT, UPDATE ON mt_device_secrets TO dnb_app;

-- ---------------------------------------------------------------------------
DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY['mt_devices'] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE  ROW LEVEL SECURITY', t);
    -- An unassigned device (customer_id NULL) belongs to no customer and is
    -- visible to none: it is DishNet stock until assigned.
    EXECUTE format(
      'CREATE POLICY %I ON %I USING (customer_id = mt_current_customer()) '
      || 'WITH CHECK (customer_id = mt_current_customer())', t || '_isolation', t);
  END LOOP;
END $$;

-- mt_device_config and mt_device_secrets carry no customer column and are
-- reached only through a device the caller could already see. Neither is
-- exposed by any customer route, and the device projection omits both.
REVOKE ALL ON mt_device_config FROM dnb_app;
GRANT SELECT, INSERT, UPDATE ON mt_device_config TO dnb_app;

-- Lifecycle, enforced by the database rather than by remembering.
CREATE OR REPLACE FUNCTION mt_device_transition() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN
  IF NEW.state = OLD.state THEN RETURN NEW; END IF;
  IF OLD.state = 'decommissioned' THEN
    RAISE EXCEPTION 'device % is decommissioned', OLD.id USING ERRCODE = 'DN409';
  END IF;
  IF NOT (
       (OLD.state = 'registered'   AND NEW.state IN ('staged','decommissioned'))
    OR (OLD.state = 'staged'       AND NEW.state IN ('shipped','connected','decommissioned'))
    OR (OLD.state = 'shipped'      AND NEW.state IN ('connected','orphaned','decommissioned'))
    OR (OLD.state = 'connected'    AND NEW.state IN ('provisioned','orphaned','decommissioned'))
    OR (OLD.state = 'provisioned'  AND NEW.state IN ('active','diverged','orphaned','decommissioned'))
    OR (OLD.state = 'active'       AND NEW.state IN ('diverged','orphaned','decommissioned'))
    OR (OLD.state = 'diverged'     AND NEW.state IN ('provisioned','active','orphaned','decommissioned'))
    OR (OLD.state = 'orphaned'     AND NEW.state IN ('connected','decommissioned'))
  ) THEN
    RAISE EXCEPTION 'illegal device transition % -> %', OLD.state, NEW.state
      USING ERRCODE = 'DN409';
  END IF;
  NEW.updated_at := now();
  RETURN NEW;
END $$;
CREATE TRIGGER mt_devices_transition BEFORE UPDATE ON mt_devices
  FOR EACH ROW EXECUTE FUNCTION mt_device_transition();

CREATE OR REPLACE FUNCTION mt_devices_no_delete() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN
  RAISE EXCEPTION 'devices are decommissioned, not deleted' USING ERRCODE = 'DN409';
END $$;
CREATE TRIGGER mt_devices_no_delete BEFORE DELETE ON mt_devices
  FOR EACH ROW EXECUTE FUNCTION mt_devices_no_delete();

ALTER TABLE mt_sessions ADD COLUMN device_id uuid REFERENCES mt_devices(id) ON DELETE SET NULL;
CREATE INDEX mt_sessions_device_ix ON mt_sessions (device_id);
