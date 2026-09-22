-- 025 — B-2, second half: dnb_app loses direct write on the commercial tables.
--
-- 024 gave all six customer-plane mutations a SECURITY DEFINER boundary that
-- writes the row AND its audit row in one transaction, and took dnb_app's
-- INSERT on mt_audit_log away. That closed audit FORGERY. It did not close the
-- other half: migration 006 line 47 granted dnb_app SELECT, INSERT, UPDATE and
-- DELETE on ALL TABLES, so the application could still bypass the six
-- functions and mutate a business table with NO audit row at all.
--
-- Every dnb_app write path to these tables was inventoried before this file
-- was written, from the code rather than from the grants:
--
--   mt_plans            PlanRepository::create/update/retire  -> the three
--                       mt_plan_* functions. Zero raw DML remains in the class.
--   mt_vouchers         VoucherService::issueBatch/revoke     -> mt_voucher_*
--   mt_voucher_batches  VoucherService::issueBatch            -> mt_voucher_batch_issue
--   mt_hotspot_users    VoucherService::issueBatch            -> mt_voucher_batch_issue
--   mt_intents          three enqueue call sites              -> all three
--                       functions. The only dnb_app reference left in
--                       src/Api/Routes.php is IntentQueue::forCustomer(),
--                       which is a SELECT.
--   mt_profiles         ProfileResolver                       -> mt_profile_resolve,
--                       owned by dnb_def_comm. The PHP class had no caller
--                       left and was deleted, so the INSERT is dead too.
--
-- The worker keeps its own writes: mt_intents UPDATE is the claim/lease and
-- the mark* transitions, and those belong to dnb_worker, not to dnb_app.
--
-- SELECT IS DELIBERATELY KEPT on all of them. PlanRepository::all/find,
-- VoucherService::list/find, IntentQueue::forCustomer/find and the read-back
-- inside mt_voucher_batch_issue's caller are ordinary RLS-scoped reads and are
-- not what B-2 is about.

REVOKE INSERT, UPDATE, DELETE ON mt_plans           FROM dnb_app;
REVOKE INSERT, UPDATE, DELETE ON mt_vouchers        FROM dnb_app;
REVOKE INSERT, UPDATE, DELETE ON mt_voucher_batches FROM dnb_app;
REVOKE INSERT, UPDATE, DELETE ON mt_hotspot_users   FROM dnb_app;
REVOKE INSERT, UPDATE, DELETE ON mt_intents         FROM dnb_app;

-- mt_profiles has no RLS -- it is the shared technical layer -- so it never
-- carried a tenant risk. It is revoked because the privilege is dead, not
-- because it was dangerous.
REVOKE INSERT ON mt_profiles FROM dnb_app;

-- The half that would otherwise expire, again. Migration 006 line 50 set
-- ALTER DEFAULT PRIVILEGES for dnb_app, so the next table created would hand
-- back UPDATE and DELETE -- and the attempt store (docs/89) and the non-tenant
-- idempotency store (docs/108) are both still to be created. 024 took INSERT
-- out of that default; this takes the other two.
--
-- SELECT stays in the default. A future table that genuinely needs a dnb_app
-- write must say so explicitly in its own migration, which is the point.
ALTER DEFAULT PRIVILEGES IN SCHEMA public
  REVOKE UPDATE, DELETE ON TABLES FROM dnb_app;

COMMENT ON TABLE mt_plans IS
  'Retail plans. dnb_app may READ only: every mutation goes through mt_plan_create / mt_plan_update / mt_plan_retire, which audit inside the same transaction (B-2, migration 024/025).';
COMMENT ON TABLE mt_vouchers IS
  'Vouchers. dnb_app may READ only: issue and revoke go through mt_voucher_batch_issue / mt_voucher_revoke (B-2, migration 024/025).';
COMMENT ON TABLE mt_intents IS
  'The only path from a request to a router. dnb_app may READ only; enqueue happens inside the commercial boundary functions. dnb_worker keeps UPDATE for the claim/lease and the mark* transitions.';
