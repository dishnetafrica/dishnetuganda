-- 006 — row-level security
--
-- Layer 3 of the authorization model. The application also filters, but this
-- is the layer that makes a FORGOTTEN filter return nothing instead of
-- another customer's rows (docs/30 Artifact 7, principle 2).
--
-- Two properties every policy below relies on:
--
--   1. FORCE ROW LEVEL SECURITY — without it the table OWNER bypasses its own
--      policies, so a migration-owner connection would see everything.
--   2. current_setting('app.customer_id', true) returns NULL when unset, and
--      `customer_id = NULL` is NULL, not TRUE. So an unset context matches no
--      rows. It fails CLOSED. Tested explicitly rather than assumed.

CREATE OR REPLACE FUNCTION mt_current_customer() RETURNS uuid AS $$
  SELECT NULLIF(current_setting('app.customer_id', true), '')::uuid;
$$ LANGUAGE sql STABLE;

-- mt_customers: a customer may see ONLY its own row.
ALTER TABLE mt_customers ENABLE ROW LEVEL SECURITY;
ALTER TABLE mt_customers FORCE  ROW LEVEL SECURITY;
CREATE POLICY mt_customers_isolation ON mt_customers
  USING (id = mt_current_customer())
  WITH CHECK (id = mt_current_customer());

-- Every other customer-scoped table keys on customer_id.
DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY[
    'mt_principals','mt_auth_sessions','mt_services',
    'mt_entitlements','mt_sites','mt_audit_log','mt_idempotency'
  ] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE  ROW LEVEL SECURITY', t);
    EXECUTE format(
      'CREATE POLICY %I ON %I USING (customer_id = mt_current_customer()) '
      || 'WITH CHECK (customer_id = mt_current_customer())',
      t || '_isolation', t);
  END LOOP;
END $$;

-- The app role gets DML and nothing else. No DDL, so it cannot ALTER TABLE
-- ... DISABLE ROW LEVEL SECURITY, and no ownership, so FORCE cannot be
-- sidestepped. Tested.
GRANT USAGE ON SCHEMA public TO dnb_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO dnb_app;
GRANT EXECUTE ON FUNCTION mt_current_customer() TO dnb_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO dnb_app;

-- Explicitly NOT granted: CREATE on schema, ownership of any table,
-- BYPASSRLS, and TRUNCATE (which would skip the audit row triggers).
REVOKE TRUNCATE ON ALL TABLES IN SCHEMA public FROM dnb_app;
