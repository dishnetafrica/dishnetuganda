-- 003 — commercial plane
--
-- What the customer buys from DishNet: platform access and scope.
-- *** NEVER BANDWIDTH. F8 is binding. ***
-- The customer's uplink is their own; DishNet does not sell, ration or gate it.

CREATE TABLE mt_services (
  id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id  uuid NOT NULL REFERENCES mt_customers(id) ON DELETE RESTRICT,
  kind         text NOT NULL CHECK (kind IN ('mikrotik_hotspot')),
  status       text NOT NULL DEFAULT 'active'
               CHECK (status IN ('active','suspended','ended')),
  started_at   timestamptz NOT NULL DEFAULT now(),
  ended_at     timestamptz
);
CREATE INDEX mt_services_customer_ix ON mt_services (customer_id);

-- Platform scope, key/value so the C11 pricing UNIT can be chosen later
-- without a schema change.
--
-- A CHECK constraint enumerates the permitted keys. This is not tidiness: it
-- is the mechanism that stops a bandwidth ceiling being added quietly later.
-- Adding one would require a migration that visibly edits this list, which is
-- reviewable. F8/F9/F13.
CREATE TABLE mt_entitlements (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  service_id  uuid NOT NULL REFERENCES mt_services(id) ON DELETE CASCADE,
  customer_id uuid NOT NULL REFERENCES mt_customers(id) ON DELETE RESTRICT,
  key         text NOT NULL CHECK (key IN (
                'max_routers','max_sites','max_operators',
                'feature_portal_branding','feature_uplink_alerts'
              )),
  int_value   integer,
  text_value  text,
  created_at  timestamptz NOT NULL DEFAULT now(),
  UNIQUE (service_id, key)
);
CREATE INDEX mt_entitlements_customer_ix ON mt_entitlements (customer_id);

COMMENT ON TABLE mt_entitlements IS
  'Commercial Plane ONLY: platform access and scope. Never bandwidth, never '
  'uplink rationing, never a network-policy ceiling. Enforced at admin time, '
  'never experienced by a guest (F10).';

CREATE TABLE mt_sites (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id uuid NOT NULL REFERENCES mt_customers(id) ON DELETE RESTRICT,
  service_id  uuid NOT NULL REFERENCES mt_services(id) ON DELETE RESTRICT,
  name        text NOT NULL,
  location    text,
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX mt_sites_customer_ix ON mt_sites (customer_id);
