-- O-1 COMPOSITE INTEGRITY — CANDIDATE DDL, NOT A MIGRATION.
--
-- This file is deliberately NOT in migrations/. Putting it there would apply it
-- on every install, which is exactly the authorisation that has not been given:
-- O-1 is designed (docs/105), demonstrated inside a rolled-back transaction
-- (docs/106) and gated on the production census (docs/107 GATE 1), which is
-- NOT OBTAINED. tools/ is also excluded from the release package.
--
-- It exists so the acceptance harness can prove HOW it behaves -- that it
-- applies to a clean estate, refuses a violating one, and leaves no partial
-- schema change when it refuses.
--
-- One transaction on purpose. src/Db/Migrator.php runs each migration file as
-- one implicit transaction (PDO::exec), so this mirrors how it would run.
-- Explicit BEGIN/COMMIT is needed under `psql -f`, which otherwise autocommits
-- each statement and could leave the UNIQUE applied with the FK refused.
BEGIN;

-- ---------------------------------------------------------------------------
-- THIS LINE IS LOAD-BEARING. Measured, not assumed.
--
-- docs/106 recorded that "the migration fails closed by itself -- PostgreSQL
-- validates against every existing row... No guard clause is needed and none
-- should be added." THAT IS FALSE FOR A ROLE SUBJECT TO RLS, and the schema
-- owner is exactly such a role: since migration 017 (finding F2) dnb is not a
-- superuser and mt_sites has FORCE ROW LEVEL SECURITY, so with no tenant
-- context it sees ZERO rows.
--
-- Proved by execution against a violating estate:
--
--   as dnb, without this line   ALTER TABLE / ALTER TABLE / COMMIT
--                               convalidated = true, violating row still there
--   as a role that sees rows    ERROR: violates foreign key constraint
--                               ...names the offending pair, ROLLBACK
--   as dnb, WITH this line      ERROR: query would be affected by row-level
--                               security policy for table "mt_sites", ROLLBACK
--
-- The first outcome is the dangerous one: the invariant is ASSERTED but not
-- TRUE, and nothing would ever re-check it.
--
-- WHAT THIS LINE DOES, STATED PRECISELY. It is NOT a way of bypassing RLS and
-- must not be described as one. The property it buys is narrower, and it is
-- the one that matters:
--
--   the migration REFUSES TO PROCEED when its own validation query would be
--   affected by row-level security, instead of validating against whatever
--   subset of rows happened to be visible.
--
-- Under FORCE RLS, row_security = off makes such a query ERROR rather than
-- silently return fewer rows. The migration fails closed; it does not gain
-- sight of anything it could not already see.
-- ---------------------------------------------------------------------------
SET LOCAL row_security = off;

-- Cannot fail on existing data: PRIMARY KEY (id) is strictly stronger, so
-- (id, customer_id) can never reject a row the PK accepts. It adds an index,
-- not a restriction (docs/106).
ALTER TABLE mt_services
  ADD CONSTRAINT mt_services_id_customer_key UNIQUE (id, customer_id);

-- The only statement that can refuse. Referenced column ORDER is cosmetic --
-- PostgreSQL matches the column set, not the sequence (measured, docs/106).
-- No MATCH FULL and no CHECK: both mt_sites columns are already NOT NULL, so
-- MATCH SIMPLE is equivalent and either would be inert while implying to a
-- future reader that a NULL case exists.
ALTER TABLE mt_sites
  ADD CONSTRAINT mt_sites_service_customer_fkey
  FOREIGN KEY (customer_id, service_id) REFERENCES mt_services (customer_id, id);

COMMIT;
