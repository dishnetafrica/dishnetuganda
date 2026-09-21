-- ---------------------------------------------------------------------------
-- Control-plane integrity census — OPERATOR-SIDE, READ ONLY
--
--   psql "<production control-plane DSN>" -f tools/audit/production_census.sql
--
-- Establishes the production data state for the proposed customer/site
-- ownership constraints (docs/76 §B.2). It REPAIRS NOTHING and CHANGES
-- NOTHING: the whole run is inside a READ ONLY transaction, so the database
-- itself refuses any write this file could contain. That is a property of the
-- transaction, not a promise of the author.
--
-- It also establishes DEPLOYMENT state from the database itself rather than
-- from documentation — whether the control-plane schema exists at all, which
-- migrations were applied, and whether anything was ever written.
--
-- Paste the whole transcript back. Nothing here prints customer names, phone
-- numbers, voucher codes, credentials or any other customer-identifying value:
-- the output is counts, plus UUID prefixes only where a row must be traceable.
-- ---------------------------------------------------------------------------
\pset pager off
\set ON_ERROR_STOP on
\timing off

BEGIN;
SET TRANSACTION READ ONLY;

DO $$
DECLARE
  n bigint; m bigint; k bigint;
  has_dev bool := to_regclass('public.mt_devices')  IS NOT NULL;
  has_vou bool := to_regclass('public.mt_vouchers') IS NOT NULL;
  has_bat bool := to_regclass('public.mt_voucher_batches') IS NOT NULL;
  has_sit bool := to_regclass('public.mt_sites')    IS NOT NULL;
  has_mig bool := to_regclass('public.mt_migrations') IS NOT NULL;
  r record;
BEGIN
RAISE NOTICE '============================================================';
RAISE NOTICE 'SECTION 0 — who is running this, and can they see everything';
RAISE NOTICE '============================================================';
RAISE NOTICE 'server            : %', version();
RAISE NOTICE 'database          : %', current_database();
RAISE NOTICE 'user              : %', current_user;
SELECT rolsuper, rolbypassrls INTO r FROM pg_roles WHERE rolname = current_user;
RAISE NOTICE 'superuser         : %   bypassrls : %', r.rolsuper, r.rolbypassrls;
IF NOT (r.rolsuper OR r.rolbypassrls) THEN
  RAISE NOTICE '';
  RAISE NOTICE '*** WARNING — THIS CENSUS MAY BE BLINDED ***';
  RAISE NOTICE 'These tables use FORCE ROW LEVEL SECURITY, which binds even the';
  RAISE NOTICE 'table owner. Without superuser or BYPASSRLS the counts below can';
  RAISE NOTICE 'read 0 while rows exist. A zero from this run is NOT evidence of';
  RAISE NOTICE 'an empty table. Re-run as a role that bypasses RLS.';
  RAISE NOTICE '';
END IF;

RAISE NOTICE '';
RAISE NOTICE '============================================================';
RAISE NOTICE 'SECTION 1 — DEPLOYMENT EVIDENCE (from the database, not docs)';
RAISE NOTICE '============================================================';
RAISE NOTICE 'mt_sites exists          : %', has_sit;
RAISE NOTICE 'mt_devices exists        : %', has_dev;
RAISE NOTICE 'mt_vouchers exists       : %', has_vou;
RAISE NOTICE 'mt_voucher_batches exists: %', has_bat;
RAISE NOTICE 'mt_migrations exists     : %', has_mig;
IF NOT has_mig THEN
  RAISE NOTICE '=> no migration ledger: this database has never been migrated by bin/migrate.php';
ELSE
  EXECUTE 'SELECT count(*) FROM mt_migrations' INTO n;
  RAISE NOTICE 'migrations applied       : %', n;
  FOR r IN EXECUTE 'SELECT filename FROM mt_migrations ORDER BY filename' LOOP
    RAISE NOTICE '    %', r.filename;
  END LOOP;
END IF;
IF NOT (has_dev OR has_vou OR has_bat) THEN
  RAISE NOTICE '';
  RAISE NOTICE '=> CONTROL-PLANE SCHEMA ABSENT from this database.';
  RAISE NOTICE '   That is authoritative for THIS database only. It does not';
  RAISE NOTICE '   establish that no control plane is deployed elsewhere —';
  RAISE NOTICE '   see SECTION 5 for what else must be checked.';
  RETURN;
END IF;

RAISE NOTICE '';
RAISE NOTICE '============================================================';
RAISE NOTICE 'SECTION 2 — mt_devices';
RAISE NOTICE '============================================================';
EXECUTE 'SELECT count(*) FROM mt_devices' INTO n;
RAISE NOTICE 'total                                   : %', n;
EXECUTE 'SELECT count(*) FROM mt_devices WHERE site_id IS NULL' INTO n;
RAISE NOTICE 'unsited (site_id NULL)                  : %', n;
EXECUTE 'SELECT count(*) FROM mt_devices WHERE site_id IS NOT NULL AND customer_id IS NULL' INTO n;
RAISE NOTICE 'PARTIAL NULL (site set, customer NULL)  : %   <= blocks the CHECK', n;
EXECUTE 'SELECT count(*) FROM mt_devices d JOIN mt_sites s ON s.id=d.site_id
           WHERE d.customer_id IS DISTINCT FROM s.customer_id' INTO n;
RAISE NOTICE 'CROSS-CUSTOMER (site owned by another)  : %   <= blocks the FK', n;
EXECUTE 'SELECT count(*) FROM mt_devices d WHERE d.site_id IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM mt_sites s WHERE s.id=d.site_id)' INTO n;
RAISE NOTICE 'ORPHANED (site_id points at no site)    : %', n;
EXECUTE 'SELECT count(*) FROM mt_devices WHERE state=''decommissioned'' AND site_id IS NOT NULL' INTO n;
RAISE NOTICE 'DECOMMISSIONED but still sited          : %', n;
EXECUTE 'SELECT count(*) FROM mt_devices WHERE site_id IS NOT NULL AND tunnel_ip IS NULL' INTO n;
RAISE NOTICE 'sited but no tunnel_ip (unprojectable)  : %', n;

RAISE NOTICE '';
RAISE NOTICE '============================================================';
RAISE NOTICE 'SECTION 3 — mt_vouchers and mt_voucher_batches';
RAISE NOTICE '============================================================';
EXECUTE 'SELECT count(*) FROM mt_vouchers' INTO n;
EXECUTE 'SELECT count(*) FROM mt_vouchers WHERE site_id IS NULL' INTO m;
RAISE NOTICE 'mt_vouchers total                       : %', n;
RAISE NOTICE 'mt_vouchers with site_id NULL           : %   <= blocks NOT NULL', m;
EXECUTE 'SELECT count(*) FROM mt_vouchers v JOIN mt_sites s ON s.id=v.site_id
           WHERE v.customer_id IS DISTINCT FROM s.customer_id' INTO n;
RAISE NOTICE 'mt_vouchers CROSS-CUSTOMER site         : %   <= blocks the FK', n;
EXECUTE 'SELECT count(*) FROM mt_vouchers v WHERE v.site_id IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM mt_sites s WHERE s.id=v.site_id)' INTO n;
RAISE NOTICE 'mt_vouchers ORPHANED site_id            : %', n;
EXECUTE 'SELECT count(*) FROM mt_vouchers WHERE site_id IS NULL AND state <> ''unused''' INTO n;
RAISE NOTICE '  of the site-less, already sold/used   : %   (these cannot simply be voided)', n;

EXECUTE 'SELECT count(*) FROM mt_voucher_batches' INTO n;
EXECUTE 'SELECT count(*) FROM mt_voucher_batches WHERE site_id IS NULL' INTO m;
RAISE NOTICE 'mt_voucher_batches total                : %', n;
RAISE NOTICE 'mt_voucher_batches with site_id NULL    : %   (informational — see docs/77)', m;
EXECUTE 'SELECT count(*) FROM mt_voucher_batches b JOIN mt_sites s ON s.id=b.site_id
           WHERE b.customer_id IS DISTINCT FROM s.customer_id' INTO n;
RAISE NOTICE 'mt_voucher_batches CROSS-CUSTOMER site  : %   <= blocks the FK', n;

RAISE NOTICE '';
RAISE NOTICE '============================================================';
RAISE NOTICE 'SECTION 4 — would each proposed constraint VALIDATE right now?';
RAISE NOTICE '============================================================';
EXECUTE 'SELECT count(*) FROM (SELECT id, customer_id FROM mt_sites
           GROUP BY id, customer_id HAVING count(*)>1) x' INTO n;
RAISE NOTICE 'mt_sites UNIQUE (id, customer_id)       : % blocking row(s)', n;
EXECUTE 'SELECT count(*) FROM mt_devices d WHERE d.site_id IS NOT NULL AND d.customer_id IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM mt_sites s WHERE s.id=d.site_id AND s.customer_id=d.customer_id)' INTO n;
RAISE NOTICE 'mt_devices composite FK                 : % blocking row(s)', n;
EXECUTE 'SELECT count(*) FROM mt_devices WHERE NOT (site_id IS NULL OR customer_id IS NOT NULL)' INTO n;
RAISE NOTICE 'mt_devices CHECK                        : % blocking row(s)', n;
EXECUTE 'SELECT count(*) FROM mt_vouchers WHERE site_id IS NULL' INTO n;
RAISE NOTICE 'mt_vouchers site_id NOT NULL            : % blocking row(s)', n;
EXECUTE 'SELECT count(*) FROM mt_vouchers v WHERE v.site_id IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM mt_sites s WHERE s.id=v.site_id AND s.customer_id=v.customer_id)' INTO n;
RAISE NOTICE 'mt_vouchers composite FK                : % blocking row(s)', n;
EXECUTE 'SELECT count(*) FROM mt_voucher_batches b WHERE b.site_id IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM mt_sites s WHERE s.id=b.site_id AND s.customer_id=b.customer_id)' INTO n;
RAISE NOTICE 'mt_voucher_batches composite FK         : % blocking row(s)', n;

RAISE NOTICE '';
RAISE NOTICE '============================================================';
RAISE NOTICE 'SECTION 5 — what this census CANNOT establish';
RAISE NOTICE '============================================================';
RAISE NOTICE 'It speaks for THIS database only. To establish that no control';
RAISE NOTICE 'plane is deployed anywhere, the operator must also confirm, and';
RAISE NOTICE 'state how it was confirmed:';
RAISE NOTICE '  (a) no other database on any host holds these mt_* tables;';
RAISE NOTICE '  (b) no application/container is running against such a database;';
RAISE NOTICE '  (c) the DSN used for this run is the one any deployment would use.';
RAISE NOTICE 'Absent (a)-(c), production state stays NOT ESTABLISHED.';
END $$;

ROLLBACK;
\echo
\echo 'census complete — nothing was written (READ ONLY transaction, rolled back)'
