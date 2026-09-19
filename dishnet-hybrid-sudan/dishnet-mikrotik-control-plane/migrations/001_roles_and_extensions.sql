-- 001 — roles and extensions
--
-- THE MOST IMPORTANT FILE IN THE ISOLATION MODEL.
--
-- PostgreSQL row-level security is BYPASSED by superusers and, unless
-- FORCE ROW LEVEL SECURITY is set, by the table owner. An application that
-- connects as the owner therefore gets RLS that silently does nothing: every
-- policy is present, every test that only checks policy existence passes, and
-- every customer can read every other customer.
--
-- So the app connects as a role that owns nothing and is not superuser, and
-- every table additionally sets FORCE ROW LEVEL SECURITY so the owner is
-- subject to its own policies too. Both, deliberately: either alone leaves a
-- path open.

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'dnb_app') THEN
    -- NOBYPASSRLS is the default but is stated so the intent survives a reader
    CREATE ROLE dnb_app LOGIN PASSWORD 'app-local-dev' NOSUPERUSER NOCREATEDB
                        NOCREATEROLE NOINHERIT NOBYPASSRLS;
  END IF;
END $$;

-- gen_random_uuid() is built in from PG13. Ids are UUIDs, not sequences:
-- a sequential id lets one customer infer how many rows another has created,
-- which the acceptance condition names as "infer".
