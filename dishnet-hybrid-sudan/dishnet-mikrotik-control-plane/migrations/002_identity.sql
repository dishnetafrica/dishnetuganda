-- 002 — identity plane
-- Customer is the canonical entity. "Tenant" is retired as an entity name and
-- survives only as the word for the isolation key (app.customer_id).

CREATE TABLE mt_customers (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  ucrm_client_id  integer UNIQUE,          -- nullable: C16 is open
  name            text NOT NULL,
  status          text NOT NULL DEFAULT 'active'
                  CHECK (status IN ('active','suspended','closed')),
  created_at      timestamptz NOT NULL DEFAULT now()
);

-- A person who may act for a customer. kind is owner|operator; the full role
-- model is C6/C16 and is deliberately not decided here.
CREATE TABLE mt_principals (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id      uuid NOT NULL REFERENCES mt_customers(id) ON DELETE RESTRICT,
  kind             text NOT NULL CHECK (kind IN ('owner','operator')),
  display_name     text NOT NULL,
  phone            text,
  email            text,
  credential_hash  text,
  status           text NOT NULL DEFAULT 'active'
                   CHECK (status IN ('active','disabled')),
  created_at       timestamptz NOT NULL DEFAULT now(),
  last_login_at    timestamptz
);
CREATE UNIQUE INDEX mt_principals_phone_uq ON mt_principals (phone) WHERE phone IS NOT NULL;
CREATE INDEX mt_principals_customer_ix ON mt_principals (customer_id);

-- The ONLY thing a client ever holds. It carries no customer id; the customer
-- is derived from it server-side (F4).
CREATE TABLE mt_auth_sessions (
  id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  principal_id uuid NOT NULL REFERENCES mt_principals(id) ON DELETE CASCADE,
  customer_id  uuid NOT NULL REFERENCES mt_customers(id) ON DELETE RESTRICT,
  token_hash   text NOT NULL UNIQUE,       -- hash only; the token is never stored
  issued_at    timestamptz NOT NULL DEFAULT now(),
  expires_at   timestamptz NOT NULL,
  revoked_at   timestamptz
);
CREATE INDEX mt_auth_sessions_principal_ix ON mt_auth_sessions (principal_id);
