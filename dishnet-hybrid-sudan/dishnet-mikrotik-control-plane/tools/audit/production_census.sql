-- ---------------------------------------------------------------------------
-- Control-plane integrity census — RUN BY THE DISHNET ENGINEER ON THE PRODUCTION HOST, READ ONLY
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
  has_svc bool := to_regclass('public.mt_services') IS NOT NULL;
  has_cus bool := to_regclass('public.mt_customers') IS NOT NULL;
  has_mig bool := to_regclass('public.mt_migrations') IS NOT NULL;
  -- Read path per entity: the Admin PROJECTION where it exists and this role
  -- may execute it, the base table otherwise. See the note before SECTION 1b.
  src_sit text; src_svc text; src_dev text; src_vou text; src_bat text; src_cus text;
  src_pri text;   -- SECTION 1c (migration 027)
  blind bool := false;   -- set whenever a DATA measurement was refused or hidden
  ledger_read bool := false;  -- SCHEMA evidence; reported, never part of the verdict
  is_priv bool := false;      -- superuser or BYPASSRLS
  hidden text[] := '{}';      -- base tables read under RLS by a role that does not bypass it
  t text;
  seen  bigint := 0;     -- POSITIVE CONTROL: rows this session could actually see
  block bigint := 0;     -- rows that would refuse the O-1 constraint
  r record;
BEGIN
RAISE NOTICE '============================================================';
RAISE NOTICE 'SECTION 0 — who is running this, and can they see everything';
RAISE NOTICE '============================================================';
RAISE NOTICE 'server            : %', version();
RAISE NOTICE 'database          : %', current_database();
RAISE NOTICE 'user              : %', current_user;
RAISE NOTICE 'server address    : %   port : %',
  coalesce(host(inet_server_addr()), '<unix socket>'),
  coalesce(inet_server_port()::text, current_setting('port', true));
RAISE NOTICE '(identity only — no password or connection string is read or printed)';
SELECT rolsuper, rolbypassrls INTO r FROM pg_roles WHERE rolname = current_user;
RAISE NOTICE 'superuser         : %   bypassrls : %', r.rolsuper, r.rolbypassrls;
is_priv := r.rolsuper OR r.rolbypassrls;
IF NOT is_priv THEN
  RAISE NOTICE '';
  RAISE NOTICE 'This role does not bypass row-level security, and these tables use';
  RAISE NOTICE 'FORCE ROW LEVEL SECURITY, which binds even their owner. Every data';
  RAISE NOTICE 'read below therefore goes through an Admin projection where this role';
  RAISE NOTICE 'may execute one (dnb_def_admin sees every row behind them). A read that';
  RAISE NOTICE 'falls back to a base table is marked HIDDEN, and one that is refused is';
  RAISE NOTICE 'marked UNREADABLE; either withholds the verdict in SECTION 6.';
  RAISE NOTICE '';
END IF;

RAISE NOTICE '';
RAISE NOTICE '============================================================';
RAISE NOTICE 'SECTION 1 — DEPLOYMENT EVIDENCE (from the database, not docs)';
RAISE NOTICE '============================================================';
RAISE NOTICE 'mt_sites exists          : %', has_sit;
RAISE NOTICE 'mt_services exists       : %', has_svc;
RAISE NOTICE 'mt_customers exists      : %', has_cus;
RAISE NOTICE 'mt_devices exists        : %', has_dev;
RAISE NOTICE 'mt_vouchers exists       : %', has_vou;
RAISE NOTICE 'mt_voucher_batches exists: %', has_bat;
RAISE NOTICE 'mt_migrations exists     : %', has_mig;
IF NOT has_mig THEN
  RAISE NOTICE '=> no migration ledger: this database has never been migrated by bin/migrate.php';
ELSE
  BEGIN
    EXECUTE 'SELECT count(*) FROM mt_migrations' INTO n;
    ledger_read := true;
    RAISE NOTICE 'migrations applied       : %', n;
    FOR r IN EXECUTE 'SELECT filename FROM mt_migrations ORDER BY filename' LOOP
      RAISE NOTICE '    %', r.filename;
    END LOOP;
  EXCEPTION WHEN insufficient_privilege THEN
    -- SCHEMA evidence, not data. Until docs/123 this refusal also set
    -- `blind`, so the documented dnb_adminapi run could never reach CLEAR or
    -- BLOCKED -- and the owner's run cannot see the data at all -- so no run
    -- of the documented two could produce a verdict. It is reported in
    -- SECTION 6 instead, and the owner's run supplies it.
    RAISE NOTICE 'migrations applied       : UNREADABLE by % — no SELECT on mt_migrations', current_user;
    RAISE NOTICE '  (the Admin projections do not cover the migration ledger; the';
    RAISE NOTICE '   schema level therefore needs a role with SELECT on that table)';
  END;
END IF;
IF NOT (has_dev OR has_vou OR has_bat) THEN
  RAISE NOTICE '';
  RAISE NOTICE '=> CONTROL-PLANE SCHEMA ABSENT from this database.';
  RAISE NOTICE '   That is authoritative for THIS database only. It does not';
  RAISE NOTICE '   establish that no control plane is deployed elsewhere —';
  RAISE NOTICE '   see SECTION 5 for what else must be checked.';
  RETURN;
END IF;

-- ---------------------------------------------------------------------------
-- READ PATH. Every measurement below prefers the Admin PROJECTION over the
-- base table. dnb_def_admin holds SELECT-only USING(true) policies behind
-- them, so an ordinary non-superuser, non-BYPASSRLS dnb_adminapi login can see
-- the whole estate -- and a census role created to bypass RLS would outlive
-- the census (docs/105). Measured: the projections carry every column this
-- file needs, including site_id, customer_id, state, tunnel_ip and sold_at.
-- Base tables are the fallback only where a projection is absent, which means
-- an older migration level. The path actually taken is printed either way.
-- ---------------------------------------------------------------------------
src_sit := CASE WHEN to_regproc('public.mt_admin_sites') IS NULL THEN 'mt_sites'
                WHEN NOT has_function_privilege(current_user,'public.mt_admin_sites()','EXECUTE') THEN 'mt_sites'
                ELSE 'mt_admin_sites()' END;
src_svc := CASE WHEN to_regproc('public.mt_admin_services') IS NULL THEN 'mt_services'
                WHEN NOT has_function_privilege(current_user,'public.mt_admin_services()','EXECUTE') THEN 'mt_services'
                ELSE 'mt_admin_services()' END;
src_dev := CASE WHEN to_regproc('public.mt_admin_routers') IS NULL THEN 'mt_devices'
                WHEN NOT has_function_privilege(current_user,'public.mt_admin_routers()','EXECUTE') THEN 'mt_devices'
                ELSE 'mt_admin_routers()' END;
src_vou := CASE WHEN to_regproc('public.mt_admin_vouchers') IS NULL THEN 'mt_vouchers'
                WHEN NOT has_function_privilege(current_user,'public.mt_admin_vouchers()','EXECUTE') THEN 'mt_vouchers'
                ELSE 'mt_admin_vouchers()' END;
src_bat := CASE WHEN to_regproc('public.mt_admin_voucher_batches') IS NULL THEN 'mt_voucher_batches'
                WHEN NOT has_function_privilege(current_user,'public.mt_admin_voucher_batches()','EXECUTE') THEN 'mt_voucher_batches'
                ELSE 'mt_admin_voucher_batches()' END;
src_cus := CASE WHEN to_regproc('public.mt_admin_customers') IS NULL THEN 'mt_customers'
                WHEN NOT has_function_privilege(current_user,'public.mt_admin_customers()','EXECUTE') THEN 'mt_customers'
                ELSE 'mt_admin_customers()' END;
RAISE NOTICE '';
RAISE NOTICE 'read path — sites:%  services:%  routers:%', src_sit, src_svc, src_dev;
RAISE NOTICE '            vouchers:%  batches:%  customers:%', src_vou, src_bat, src_cus;
-- A base-table read by a role that does not bypass RLS is HIDDEN, not a count.
-- Under FORCE ROW LEVEL SECURITY, with no tenant context, it returns zero rows
-- while rows exist -- silently, with no error to catch. Measured (docs/123 §A.2)
-- with one constructed grant: sites read through the base table and services
-- through the projection reported CLEAR over a real O-1 violation; the reverse
-- reported BLOCKED(6) where one row blocks. A refused read already withholds
-- the verdict; a hidden one now does the same.
IF NOT is_priv THEN
  FOREACH t IN ARRAY ARRAY[src_sit, src_svc, src_dev, src_vou, src_bat, src_cus] LOOP
    IF right(t, 2) <> '()'
       AND coalesce((SELECT c.relrowsecurity FROM pg_class c WHERE c.oid = to_regclass('public.' || t)), false)
       AND has_table_privilege(current_user, 'public.' || t, 'SELECT') THEN
      hidden := hidden || t;
    END IF;
  END LOOP;
  IF cardinality(hidden) > 0 THEN
    blind := true;
    RAISE NOTICE '*** HIDDEN — read from base table(s) under row-level security: %', array_to_string(hidden, ', ');
    RAISE NOTICE '    Their counts below can read zero while rows exist. NOT MEASURED, not clean.';
  END IF;
END IF;

RAISE NOTICE '';
RAISE NOTICE '============================================================';
RAISE NOTICE 'SECTION 1b — mt_sites and mt_services  (O-1)';
RAISE NOTICE '============================================================';
-- Added after docs/104-107. The rest of this file was written for the DEVICE
-- and VOUCHER constraints (docs/76 B.2) and predates O-1 entirely, so none of
-- it measured the one relationship the composite FK is about.
--
-- O-1: mt_sites carries two INDEPENDENT single-column foreign keys,
-- customer_id and service_id, and nothing requires them to agree. RLS checks
-- the written row's own customer_id -- correctly the writer's -- while
-- service_id may point at a row the writer cannot even read. Remediation is
-- UNIQUE (id, customer_id) on mt_services plus a composite FK from mt_sites.
-- Only the FK can refuse existing data.
IF NOT (has_sit AND has_svc) THEN
  RAISE NOTICE 'mt_sites and/or mt_services ABSENT — O-1 is not measurable here.';
ELSE
 BEGIN
  EXECUTE format('SELECT count(*) FROM %s', src_sit) INTO n;
  RAISE NOTICE 'mt_sites total                          : %', n;
  seen := seen + n;
  EXECUTE format('SELECT count(*) FROM %s', src_svc) INTO n;
  RAISE NOTICE 'mt_services total                       : %', n;
  seen := seen + n;
  EXECUTE format('SELECT count(DISTINCT (customer_id, service_id)) FROM %s', src_sit) INTO n;
  RAISE NOTICE 'distinct (customer_id, service_id) pairs : %', n;
  EXECUTE format('SELECT count(DISTINCT service_id) FROM %s', src_sit) INTO n;
  RAISE NOTICE 'distinct services referenced by sites    : %', n;
  EXECUTE format('SELECT count(DISTINCT customer_id) FROM %s', src_sit) INTO n;
  RAISE NOTICE 'distinct customers owning sites          : %', n;

  RAISE NOTICE '  --- nullability (production may be at a different migration level) ---';
  EXECUTE format('SELECT count(*) FROM %s WHERE service_id IS NULL', src_sit) INTO n;
  RAISE NOTICE 'mt_sites with service_id NULL           : %', n;
  EXECUTE format('SELECT count(*) FROM %s WHERE customer_id IS NULL', src_sit) INTO n;
  RAISE NOTICE 'mt_sites with customer_id NULL          : %', n;

  RAISE NOTICE '  --- THE O-1 DEFECT ITSELF ---';
  EXECUTE format('SELECT count(*) FROM %s s JOIN %s v ON v.id = s.service_id
             WHERE s.customer_id IS DISTINCT FROM v.customer_id', src_sit, src_svc) INTO n;
  RAISE NOTICE 'sites whose service belongs to ANOTHER customer : %   <= BLOCKS the FK', n;
  IF n > 0 THEN
    FOR r IN EXECUTE format('SELECT left(s.id::text,8) AS site, left(s.customer_id::text,8) AS site_cust,
                             left(v.id::text,8) AS svc, left(v.customer_id::text,8) AS svc_cust
                        FROM %s s JOIN %s v ON v.id = s.service_id
                       WHERE s.customer_id IS DISTINCT FROM v.customer_id
                       ORDER BY 1', src_sit, src_svc) LOOP
      RAISE NOTICE '    site % (cust %) -> service % (cust %)',
                   r.site, r.site_cust, r.svc, r.svc_cust;
    END LOOP;
    RAISE NOTICE '    ^ every pair above must be resolved BEFORE the migration.';
    RAISE NOTICE '      The dependency counts printed in SECTION 2 and 3 say whether';
    RAISE NOTICE '      the running application relies on any of these sites.';
  END IF;

  RAISE NOTICE '  --- orphaned references ---';
  EXECUTE format('SELECT count(*) FROM %s s WHERE s.service_id IS NOT NULL
             AND NOT EXISTS (SELECT 1 FROM %s v WHERE v.id = s.service_id)', src_sit, src_svc) INTO n;
  RAISE NOTICE 'mt_sites ORPHANED service_id            : %', n;
  IF has_cus THEN
    EXECUTE format('SELECT count(*) FROM %s s WHERE s.customer_id IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM %s c WHERE c.id = s.customer_id)', src_sit, src_cus) INTO n;
    RAISE NOTICE 'mt_sites ORPHANED customer_id           : %', n;
    EXECUTE format('SELECT count(*) FROM %s v WHERE v.customer_id IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM %s c WHERE c.id = v.customer_id)', src_svc, src_cus) INTO n;
    RAISE NOTICE 'mt_services ORPHANED customer_id        : %', n;
  ELSE
    RAISE NOTICE 'mt_customers ABSENT — orphaned-customer checks skipped';
  END IF;

  RAISE NOTICE '  --- shared / duplicate service relationships ---';
  -- A service with several sites is LEGITIMATE: service 1:N sites is the
  -- measured cardinality (docs/104). A service reached from the sites of MORE
  -- THAN ONE customer is not, and is the same defect counted a second way.
  EXECUTE format('SELECT count(*) FROM (SELECT service_id FROM %s
             GROUP BY service_id HAVING count(*) > 1) x', src_sit) INTO n;
  RAISE NOTICE 'services with >1 site                   : %   (legitimate 1:N — informational)', n;
  EXECUTE format('SELECT count(*) FROM (SELECT service_id FROM %s
             GROUP BY service_id HAVING count(DISTINCT customer_id) > 1) x', src_sit) INTO n;
  RAISE NOTICE 'services reached by sites of >1 CUSTOMER: %   <= BLOCKS the FK', n;
 EXCEPTION WHEN insufficient_privilege THEN
  blind := true;
  RAISE NOTICE '*** SECTION 1b UNREADABLE by % — insufficient privilege.', current_user;
  RAISE NOTICE '    SKIPPED, not clean. A section that stopped early must never';
  RAISE NOTICE '    be read as one that found nothing.';
 END;
END IF;

RAISE NOTICE '';
RAISE NOTICE '';
RAISE NOTICE '============================================================';
RAISE NOTICE 'SECTION 1c — mt_principals by kind and status  (027 rewrite)';
RAISE NOTICE '============================================================';
-- Added with migration 027 (docs/116 D.1, §J J-4). That migration rewrites
-- every mt_principals.kind = 'operator' to 'staff' before tightening the CHECK
-- to (owner | staff). How many production rows that touches is NOT known from
-- the development schema — this section is what establishes it. Read through
-- mt_admin_principals() where 027 is already applied (it did not exist
-- before), the base table otherwise. The base table is FORCE RLS: a role that
-- does not bypass RLS reads 0 with no tenant context (SECTION 0), and a role
-- without SELECT on it is refused — both are reported, neither is a count.
IF to_regclass('public.mt_principals') IS NULL THEN
  RAISE NOTICE 'mt_principals ABSENT — nothing for 027 to rewrite here.';
ELSE
 BEGIN
  src_pri := CASE WHEN to_regproc('public.mt_admin_principals') IS NULL THEN 'mt_principals'
                  WHEN NOT has_function_privilege(current_user,'public.mt_admin_principals()','EXECUTE') THEN 'mt_principals'
                  ELSE 'mt_admin_principals()' END;
  RAISE NOTICE 'read path — principals:%   (mt_admin_principals exists: %)',
    src_pri, to_regproc('public.mt_admin_principals') IS NOT NULL;
  IF NOT is_priv AND src_pri = 'mt_principals'
     AND coalesce((SELECT c.relrowsecurity FROM pg_class c WHERE c.oid = to_regclass('public.mt_principals')), false)
     AND has_table_privilege(current_user, 'public.mt_principals', 'SELECT') THEN
    blind := true;
    RAISE NOTICE '*** HIDDEN — mt_principals read from the base table under row-level security;';
    RAISE NOTICE '    the counts below can read zero while rows exist. NOT MEASURED, not clean.';
  END IF;
  EXECUTE format('SELECT count(*) FROM %s', src_pri) INTO n;
  RAISE NOTICE 'mt_principals total                     : %', n;
  seen := seen + n;
  FOR r IN EXECUTE format('SELECT kind, status, count(*) AS c FROM %s GROUP BY 1, 2 ORDER BY 1, 2', src_pri) LOOP
    RAISE NOTICE '    kind = %  status = %  : %', rpad(r.kind, 8), rpad(r.status, 8), r.c;
  END LOOP;
  EXECUTE format('SELECT count(*) FROM %s WHERE kind = %L', src_pri, 'operator') INTO n;
  RAISE NOTICE 'kind = operator (rows 027 rewrites)     : %', n;
  IF to_regproc('public.mt_admin_principals') IS NOT NULL AND n > 0 THEN
    RAISE NOTICE '*** INCONSISTENT: mt_admin_principals() exists (027 applied) yet operator rows remain.';
  END IF;
 EXCEPTION WHEN insufficient_privilege THEN
  blind := true;
  RAISE NOTICE '*** SECTION 1c UNREADABLE by % — insufficient privilege.', current_user;
  RAISE NOTICE '    Before 027 there is no Admin projection of mt_principals, so this';
  RAISE NOTICE '    needs a role with SELECT on the table that also sees every row.';
 END;
END IF;

RAISE NOTICE '';
RAISE NOTICE '============================================================';
RAISE NOTICE 'SECTION 2 — mt_devices';
RAISE NOTICE '============================================================';
 BEGIN
EXECUTE format('SELECT count(*) FROM %s', src_dev) INTO n;
RAISE NOTICE 'total                                   : %', n;
EXECUTE format('SELECT count(*) FROM %s WHERE site_id IS NULL', src_dev) INTO n;
RAISE NOTICE 'unsited (site_id NULL)                  : %', n;
EXECUTE format('SELECT count(*) FROM %s WHERE site_id IS NOT NULL AND customer_id IS NULL', src_dev) INTO n;
RAISE NOTICE 'PARTIAL NULL (site set, customer NULL)  : %   <= blocks the CHECK', n;
EXECUTE format('SELECT count(*) FROM %s d JOIN %s s ON s.id=d.site_id
           WHERE d.customer_id IS DISTINCT FROM s.customer_id', src_dev, src_sit) INTO n;
RAISE NOTICE 'CROSS-CUSTOMER (site owned by another)  : %   <= blocks the FK', n;
EXECUTE format('SELECT count(*) FROM %s d WHERE d.site_id IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM %s s WHERE s.id=d.site_id)', src_dev, src_sit) INTO n;
RAISE NOTICE 'ORPHANED (site_id points at no site)    : %', n;
EXECUTE format('SELECT count(*) FROM %s WHERE state=''decommissioned'' AND site_id IS NOT NULL', src_dev) INTO n;
RAISE NOTICE 'DECOMMISSIONED but still sited          : %', n;
EXECUTE format('SELECT count(*) FROM %s WHERE site_id IS NOT NULL AND tunnel_ip IS NULL', src_dev) INTO n;
RAISE NOTICE 'sited but no tunnel_ip (unprojectable)  : %', n;
EXECUTE format('SELECT count(*) FROM %s WHERE tunnel_ip IS NOT NULL', src_dev) INTO n;
EXECUTE format('SELECT count(DISTINCT tunnel_ip) FROM %s WHERE tunnel_ip IS NOT NULL', src_dev) INTO m;
RAISE NOTICE 'devices with a tunnel_ip                : %   distinct : %', n, m;
RAISE NOTICE 'SHARED/DUPLICATE tunnel_ip              : %', n - m;
SELECT count(*) INTO k FROM pg_constraint
  WHERE conrelid = 'mt_devices'::regclass AND contype = 'u'
    AND pg_get_constraintdef(oid) ILIKE '%tunnel_ip%';
IF k > 0 THEN
  RAISE NOTICE '  (UNIQUE on tunnel_ip present, so duplicates should be structurally impossible;';
  RAISE NOTICE '   a non-zero above would mean the constraint is missing or was dropped)';
ELSE
  RAISE NOTICE '  *** UNIQUE on tunnel_ip is ABSENT — duplicates are possible here ***';
END IF;
RAISE NOTICE '  note: reuse of a RETIRED tunnel_ip is not detectable from current rows';
RAISE NOTICE '        alone (no history is kept) — that is T10, docs/71 §5';
 EXCEPTION WHEN insufficient_privilege THEN
  blind := true;
  RAISE NOTICE '*** SECTION 2 UNREADABLE by % — insufficient privilege.', current_user;
  RAISE NOTICE '    SKIPPED, not clean: a section that stopped early is NOT MEASURED.';
 END;

RAISE NOTICE '';
RAISE NOTICE '============================================================';
RAISE NOTICE 'SECTION 3 — mt_vouchers and mt_voucher_batches';
RAISE NOTICE '============================================================';
 BEGIN
EXECUTE format('SELECT count(*) FROM %s', src_vou) INTO n;
EXECUTE format('SELECT count(*) FROM %s WHERE site_id IS NULL', src_vou) INTO m;
RAISE NOTICE 'mt_vouchers total                       : %', n;
RAISE NOTICE 'mt_vouchers with site_id NULL           : %   <= blocks NOT NULL', m;
EXECUTE format('SELECT count(*) FROM %s v JOIN %s s ON s.id=v.site_id
           WHERE v.customer_id IS DISTINCT FROM s.customer_id', src_vou, src_sit) INTO n;
RAISE NOTICE 'mt_vouchers CROSS-CUSTOMER site         : %   <= blocks the FK', n;
EXECUTE format('SELECT count(*) FROM %s v WHERE v.site_id IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM %s s WHERE s.id=v.site_id)', src_vou, src_sit) INTO n;
RAISE NOTICE 'mt_vouchers ORPHANED site_id            : %', n;
RAISE NOTICE '  --- site-less vouchers BY STATE (voiding is not free) ---';
FOR r IN EXECUTE format('SELECT state, count(*) AS c FROM %s
                   WHERE site_id IS NULL GROUP BY state ORDER BY state', src_vou) LOOP
  RAISE NOTICE '    site-less + state=% : %', rpad(r.state, 10), r.c;
END LOOP;
EXECUTE format('SELECT count(*) FROM %s WHERE site_id IS NULL AND state = ''unused''', src_vou) INTO n;
RAISE NOTICE '  site-less and UNUSED (voidable)       : %', n;
EXECUTE format('SELECT count(*) FROM %s WHERE site_id IS NULL AND state <> ''unused''', src_vou) INTO n;
RAISE NOTICE '  site-less and NOT unused              : %   <= sold/active/expired/revoked', n;
EXECUTE format('SELECT count(*) FROM %s WHERE site_id IS NULL AND sold_at IS NOT NULL', src_vou) INTO n;
RAISE NOTICE '  site-less with a sold_at timestamp    : %', n;

EXECUTE format('SELECT count(*) FROM %s', src_bat) INTO n;
EXECUTE format('SELECT count(*) FROM %s WHERE site_id IS NULL', src_bat) INTO m;
RAISE NOTICE 'mt_voucher_batches total                : %', n;
RAISE NOTICE 'mt_voucher_batches with site_id NULL    : %   (informational — see docs/77)', m;
EXECUTE format('SELECT count(*) FROM %s b JOIN %s s ON s.id=b.site_id
           WHERE b.customer_id IS DISTINCT FROM s.customer_id', src_bat, src_sit) INTO n;
RAISE NOTICE 'mt_voucher_batches CROSS-CUSTOMER site  : %   <= blocks the FK', n;
EXECUTE format('SELECT count(*) FROM %s b WHERE b.site_id IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM %s s WHERE s.id=b.site_id)', src_bat, src_sit) INTO n;
RAISE NOTICE 'mt_voucher_batches ORPHANED site_id     : %', n;
EXECUTE format('SELECT count(*) FROM %s b WHERE b.site_id IS NULL
           AND EXISTS (SELECT 1 FROM %s v WHERE v.batch_id = b.id)', src_bat, src_vou) INTO n;
RAISE NOTICE '  NULL-site batches that HAVE vouchers  : %   <= every such voucher is site-less', n;
 EXCEPTION WHEN insufficient_privilege THEN
  blind := true;
  RAISE NOTICE '*** SECTION 3 UNREADABLE by % — insufficient privilege.', current_user;
  RAISE NOTICE '    SKIPPED, not clean: a section that stopped early is NOT MEASURED.';
 END;

RAISE NOTICE '';
RAISE NOTICE '============================================================';
RAISE NOTICE 'SECTION 4 — would each proposed constraint VALIDATE right now?';
RAISE NOTICE '============================================================';
 BEGIN
EXECUTE format('SELECT count(*) FROM (SELECT id, customer_id FROM %s
           GROUP BY id, customer_id HAVING count(*)>1) x', src_sit) INTO n;
RAISE NOTICE 'mt_sites UNIQUE (id, customer_id)       : % blocking row(s)', n;
IF has_svc THEN
  -- O-1, the two statements docs/106 demonstrated against the real schema.
  -- The UNIQUE cannot fail: PRIMARY KEY (id) is strictly stronger, so
  -- (id, customer_id) can never reject a row the PK accepts. Only the FK can.
  EXECUTE format('SELECT count(*) FROM (SELECT id, customer_id FROM %s
             GROUP BY id, customer_id HAVING count(*)>1) x', src_svc) INTO n;
  RAISE NOTICE 'mt_services UNIQUE (id, customer_id)    : % blocking row(s)  (cannot fail — PK is stronger)', n;
  EXECUTE format('SELECT count(*) FROM %s s WHERE s.service_id IS NOT NULL
             AND NOT EXISTS (SELECT 1 FROM %s v
                              WHERE v.id = s.service_id AND v.customer_id = s.customer_id)', src_sit, src_svc) INTO n;
  RAISE NOTICE 'mt_sites composite FK  (O-1)            : % blocking row(s)  <= THE O-1 GATE', n;
  block := block + n;
END IF;
EXECUTE format('SELECT count(*) FROM %s d WHERE d.site_id IS NOT NULL AND d.customer_id IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM %s s WHERE s.id=d.site_id AND s.customer_id=d.customer_id)', src_dev, src_sit) INTO n;
RAISE NOTICE 'mt_devices composite FK                 : % blocking row(s)', n;
EXECUTE format('SELECT count(*) FROM %s WHERE NOT (site_id IS NULL OR customer_id IS NOT NULL)', src_dev) INTO n;
RAISE NOTICE 'mt_devices CHECK                        : % blocking row(s)', n;
EXECUTE format('SELECT count(*) FROM %s WHERE site_id IS NULL', src_vou) INTO n;
RAISE NOTICE 'mt_vouchers site_id NOT NULL            : % blocking row(s)', n;
EXECUTE format('SELECT count(*) FROM %s v WHERE v.site_id IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM %s s WHERE s.id=v.site_id AND s.customer_id=v.customer_id)', src_vou, src_sit) INTO n;
RAISE NOTICE 'mt_vouchers composite FK                : % blocking row(s)', n;
EXECUTE format('SELECT count(*) FROM %s b WHERE b.site_id IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM %s s WHERE s.id=b.site_id AND s.customer_id=b.customer_id)', src_bat, src_sit) INTO n;
RAISE NOTICE 'mt_voucher_batches composite FK         : % blocking row(s)', n;
 EXCEPTION WHEN insufficient_privilege THEN
  blind := true;
  RAISE NOTICE '*** SECTION 4 UNREADABLE by % — insufficient privilege.', current_user;
  RAISE NOTICE '    SKIPPED, not clean: a section that stopped early is NOT MEASURED.';
 END;

RAISE NOTICE '';
RAISE NOTICE '============================================================';
RAISE NOTICE 'SECTION 5 — what this census CANNOT establish';
RAISE NOTICE '============================================================';
IF blind THEN
  RAISE NOTICE '*** SOME COUNTS ABOVE ARE NOT MEASURED ***';
  RAISE NOTICE 'At least one read was refused (UNREADABLE) or hidden by row-level';
  RAISE NOTICE 'security (HIDDEN). Those sections are NOT MEASURED, never zero.';
  RAISE NOTICE '';
ELSIF NOT is_priv THEN
  RAISE NOTICE 'Every data read above went through an Admin projection, so the counts';
  RAISE NOTICE 'are complete for this database although this role (%) does not', current_user;
  RAISE NOTICE 'bypass row-level security.';
  RAISE NOTICE '';
END IF;
RAISE NOTICE 'It speaks for THIS database only. To establish that no control';
RAISE NOTICE 'plane is deployed anywhere, the operator must also confirm, and';
RAISE NOTICE 'state how it was confirmed:';
RAISE NOTICE '  (a) no other database on any host holds these mt_* tables;';
RAISE NOTICE '  (b) no application/container is running against such a database;';
RAISE NOTICE '  (c) the DSN used for this run is the one any deployment would use.';
RAISE NOTICE 'Absent (a)-(c), production state stays NOT ESTABLISHED.';

RAISE NOTICE '';
RAISE NOTICE '============================================================';
RAISE NOTICE 'SECTION 6 — VERDICT  (GATE 1 of docs/107)';
RAISE NOTICE '============================================================';
-- A zero-row read is INDETERMINATE, never clean: it has seven possible causes
-- and only one of them is a finding (docs/103). So the verdict is withheld
-- unless this session PROVED it could see something it is entitled to see.
RAISE NOTICE 'rows this session could actually see    : %   (positive control)', seen;
RAISE NOTICE 'measurements refused or hidden          : %', blind;
RAISE NOTICE 'migration ledger read in this run       : %   (schema evidence — not part of the verdict)', ledger_read;
RAISE NOTICE 'rows that would refuse the O-1 FK       : %   (distinct rows, not', block;
RAISE NOTICE '                                              a sum of detectors)';
RAISE NOTICE '';
IF blind THEN
  RAISE NOTICE '>> INDETERMINATE — at least one measurement was refused or hidden.';
  RAISE NOTICE '   Re-run with a role that can read what was skipped and repeat.';
  RAISE NOTICE '   Do NOT read the zeros above as a clean result.';
ELSIF seen = 0 THEN
  RAISE NOTICE '>> INDETERMINATE — every table read empty and nothing proved this';
  RAISE NOTICE '   session can see anything at all. Either the control plane holds';
  RAISE NOTICE '   no data, or this role cannot see it. Those are different facts';
  RAISE NOTICE '   and this run cannot tell them apart.';
ELSIF block = 0 THEN
  RAISE NOTICE '>> CLEAR — % row(s) seen, 0 would refuse the O-1 composite FK.', seen;
  RAISE NOTICE '   This authorises nothing by itself: GATE 2, the migration, is a';
  RAISE NOTICE '   separate operator decision on separate evidence.';
ELSE
  RAISE NOTICE '>> BLOCKED(%) — that many rows would refuse the O-1 composite FK.', block;
  RAISE NOTICE '   The migration would fail closed by itself, but its error names';
  RAISE NOTICE '   only ONE offending pair. The list above is what enumerates them.';
END IF;
END $$;

ROLLBACK;
\echo
\echo 'census complete — nothing was written (READ ONLY transaction, rolled back)'
