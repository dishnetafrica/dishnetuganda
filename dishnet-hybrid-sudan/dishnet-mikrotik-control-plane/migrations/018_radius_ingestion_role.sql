-- 018 — remediation of audit finding F1 (docs/58 §4)
--
-- THE ROLE THAT SERVED CUSTOMER REQUESTS COULD FORGE ANOTHER CUSTOMER'S USAGE.
--
-- mt_session_account resolves the owning customer from mt_hotspot_users rather
-- than from a caller-supplied id, which is the right design and is exactly why
-- a caller who knows ANOTHER customer's RADIUS username writes into THAT
-- customer's rows. Proven in docs/58 §4 F1: customer P fabricated a session in
-- Q's data with 9999999 bytes in, and could not read it back — a blind
-- cross-customer write.
--
-- It is not merely an isolation problem. Byte counters use GREATEST so that a
-- reordered RADIUS retransmit cannot shrink them, which means AN INFLATED
-- COUNTER CAN NEVER BE CORRECTED DOWNWARD by the ingest path. Sessions are
-- what a customer is shown as "who used my hotspot and how much", and byte
-- totals are the evidence in the support-boundary conversation C17 exists for.
--
-- The defect is not in the function. It is that one database identity served
-- two trust contexts: a customer's HTTP request, and a NAS reporting usage for
-- the whole fleet. The same shape as S2, one layer up.
--
-- So RADIUS ingestion gets its own identity, holding EXACTLY ONE privilege.

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'dnb_radius') THEN
    -- The deployment requirement in migration 015 applies to this role too:
    -- the literal below is a development password and must be replaced before
    -- the database accepts a non-local connection.
    CREATE ROLE dnb_radius LOGIN PASSWORD 'radius-local-dev'
      NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
  END IF;
END $$;

GRANT USAGE ON SCHEMA public TO dnb_radius;

-- No table privileges at all, deliberately. mt_session_account is SECURITY
-- DEFINER owned by dnb_def_net, so ingestion needs EXECUTE and nothing else.
-- A compromised RADIUS endpoint therefore cannot read a session, a voucher, a
-- customer or a device — it can only submit accounting for a username, which
-- is what a NAS does.
--
-- mt_current_customer is granted because RLS policies evaluate it; without it
-- any statement against a tenant table errors rather than returning nothing,
-- and an error is a worse answer than an empty one.
GRANT EXECUTE ON FUNCTION mt_current_customer() TO dnb_radius;

DO $$
BEGIN
  EXECUTE 'SET LOCAL ROLE dnb_def_net';
  EXECUTE 'GRANT EXECUTE ON FUNCTION
             mt_session_account(text,text,text,text,bigint,bigint,text,text,text)
           TO dnb_radius';
  -- The request role loses it. This is the whole finding: after this line an
  -- arbitrary customer request, and anything injected into one, cannot reach
  -- the accounting primitive at all.
  EXECUTE 'REVOKE EXECUTE ON FUNCTION
             mt_session_account(text,text,text,text,bigint,bigint,text,text,text)
           FROM dnb_app';
  RESET ROLE;
END $$;

-- Unchanged on purpose: mt_voucher_redeem stays with dnb_app. It is the other
-- network-side entry point, but a voucher code is a bearer credential typed by
-- a guest at a portal, and redeeming one is not a write into another
-- customer's records. It remains recorded as finding F6.
