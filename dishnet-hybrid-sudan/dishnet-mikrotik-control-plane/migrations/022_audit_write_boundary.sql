-- 022 — A-1 / T1: the audit write boundary.
--
-- docs/84 F-3 recorded that migration 015's blanket grant let a role write any
-- table directly, and stated it as a hypothetical. docs/112 A-1 proved it by
-- execution for the one table where it matters most: dnb_admin could INSERT
-- into mt_audit_log, so the audit trail was FORGEABLE. mt_audit_log is
-- append-only by trigger, so a forged row cannot afterwards be removed or
-- corrected — which is worse than a forgeable business write, because a
-- business write can be reversed.
--
-- Why RLS was not already enough. Migration 015's own comment reasoned that
-- "each is subject to RLS on every table; the difference between them is only
-- which SECURITY DEFINER functions they may call." That holds for business
-- tables, where RLS plus constraints bound what a row can say. It FAILS for
-- mt_audit_log, because RLS constrains which tenant a row belongs to and not
-- whether the row is true: a forged row naming another actor satisfies
-- customer_id = mt_current_customer() perfectly. A-1 is therefore not an error
-- in 015's logic; it is a table to which that logic does not apply.
--
-- Scope, deliberately narrow (docs/113 T1). This revokes dnb_admin only.
--   dnb_app    still writes six customer-API audit sites directly (F-8) and
--   dnb_worker still writes intent.confirmed / intent.failed directly.
-- Revoking either today would break a live path, because neither has a
-- controlled replacement yet. Those are T2 and T3 and are NOT done here.
--
-- Measured before writing this: revoking dnb_admin breaks nothing at all. Its
-- only caller anywhere in the repository is Plugin/Simulator.php, which the
-- release package excludes — and the simulator only SELECTs mt_audit_log, to
-- count rows its acts produced. Its own comment says so: the rows are
-- "written by those acts, not inserted".
REVOKE INSERT ON mt_audit_log FROM dnb_admin;

-- The same grant would come back on the next table created, because 015 also
-- set ALTER DEFAULT PRIVILEGES. A revoke that leaves the default in place is a
-- fix that expires: the attempt store (docs/89) and the non-tenant idempotency
-- store (docs/108) are both still to be created, and either would hand
-- dnb_admin INSERT the moment it existed.
--
-- Only dnb_admin is narrowed. dnb_app and dnb_worker keep their defaults until
-- T2/T3 give them controlled paths; that residue is recorded as A-2.
ALTER DEFAULT PRIVILEGES IN SCHEMA public
  REVOKE INSERT ON TABLES FROM dnb_admin;

COMMENT ON TABLE mt_audit_log IS
  'Append-only audit trail. Written only through mt_audit_write(), owned by '
  'dnb_def_audit. No role holding a direct INSERT grant here is a control '
  'boundary: see docs/113. dnb_admin was revoked by migration 022 (A-1/T1); '
  'dnb_app and dnb_worker still hold one pending T2/T3.';
