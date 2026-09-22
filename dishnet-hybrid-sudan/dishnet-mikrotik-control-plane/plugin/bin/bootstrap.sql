-- DishNet MikroTik control plane — the privileged bootstrap step.
--
-- The plugin's own installer deliberately cannot do this. Creating a database
-- and a role is a privileged act on someone else's cluster, so it is a separate
-- file the operator can read in full before running, rather than something a
-- PHP process does on their behalf with credentials it was handed.
--
-- Run as a cluster superuser, supplying the owner password on the command line
-- so it never lands in a file:
--
--   psql -U postgres -d postgres \
--        -v db=dnb -v owner=dnb -v owner_pass="$(php -r 'echo bin2hex(random_bytes(24));')" \
--        -f plugin/bin/bootstrap.sql
--
-- It creates exactly two things:
--
--   1. the login role that OWNS the plugin's schema, and
--   2. an empty database owned by it.
--
-- It creates no table, grants nothing on any other database, and touches no
-- existing object. Everything else — the six application roles, the six
-- definer roles, every table, policy and function — is created by
-- `php plugin/bin/plugin.php install`, connecting as this owner.
--
-- The owner needs CREATEROLE (the migrations create the application and
-- definer roles) and CREATEDB (the test harness builds throwaway databases).
-- It is NOT a superuser and does NOT have BYPASSRLS: since migration 017 the
-- owner is subject to FORCE row-level security like every other role, which is
-- the property audit finding F2 exists to protect.
--
-- NOTE ON SCOPE: PostgreSQL roles are cluster-wide, not per-database. Running
-- this against a cluster that also serves UCRM adds roles visible to that
-- cluster. It grants them nothing there, but "nothing was touched" would be
-- an overstatement — see the installation readiness report.

\set ON_ERROR_STOP on

\if :{?db}
\else
  \echo 'ERROR: pass -v db=<database name>'
  \quit 1
\endif
\if :{?owner}
\else
  \echo 'ERROR: pass -v owner=<owner role name>'
  \quit 1
\endif
\if :{?owner_pass}
\else
  \echo 'ERROR: pass -v owner_pass=<password>. Do not reuse a password from another system.'
  \quit 1
\endif

-- The owner role. Idempotent: an existing role keeps its password, so re-running
-- this file cannot silently rotate a credential something else is using.
SELECT format(
  'CREATE ROLE %I LOGIN CREATEROLE CREATEDB PASSWORD %L', :'owner', :'owner_pass')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'owner')
\gexec

-- The database. CREATE DATABASE cannot run inside a transaction block, which is
-- why this file is a script rather than one statement.
SELECT format('CREATE DATABASE %I OWNER %I', :'db', :'owner')
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'db')
\gexec

-- Report what is now true, rather than what was attempted.
SELECT
  (SELECT count(*) FROM pg_roles    WHERE rolname = :'owner') AS owner_role_present,
  (SELECT count(*) FROM pg_database WHERE datname = :'db')    AS database_present,
  (SELECT rolcreaterole FROM pg_roles WHERE rolname = :'owner') AS owner_can_create_roles,
  (SELECT rolsuper      FROM pg_roles WHERE rolname = :'owner') AS owner_is_superuser;
