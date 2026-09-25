-- ---------------------------------------------------------------------------
-- 029 — O-1: a site's service must belong to the site's own operator (docs/124)
--
-- GATE 2 of docs/107, authorised by the operator on 2026-09-23 after GATE 1,
-- the deployment census (docs/123 §F), found no Domain B outside staging and
-- staging's estate CLEAR under dnb_adminapi at migration 028.
--
-- THE DEFECT (docs/104-106). mt_sites carries two independent single-column
-- foreign keys, customer_id and service_id, and nothing required them to
-- agree: a site of operator A could name operator B's service. Referential
-- integrity is enforced BELOW row-level security, so neither operator can see
-- the other's row, yet B can then never end that service and cannot see why.
-- The remediation is W-2's shape one level up: UNIQUE (id, customer_id) on
-- mt_services plus a composite foreign key from mt_sites. Both single-column
-- foreign keys stay; nothing is dropped.
--
-- WHY THIS FILE DIFFERS FROM tools/audit/o1_composite_fk.sql, MEASURED.
-- The Migrator applies each file as the schema OWNER, which is not a superuser
-- (017, F2), and both tables are FORCE ROW LEVEL SECURITY. The candidate's
-- guard, row_security = off, therefore made the owner refuse EVERY time --
-- "query would be affected by row-level security policy for table mt_sites"
-- -- on an empty install as well as on a clean estate (docs/124 §A.1). Without
-- the guard the owner validated against zero visible rows and marked a
-- violated constraint VALIDATED (docs/123, the O-1 acceptance record).
--
-- So the owner lifts FORCE on exactly these two tables, INSIDE this migration's
-- single transaction, validates against every row, and restores FORCE before
-- the transaction ends. The ALTERs take ACCESS EXCLUSIVE locks, so no other
-- session can read either table while FORCE is lifted, and a failure anywhere
-- rolls all of it back, FORCE included. The guard stays: if any read here were
-- still subject to row-level security -- this file run by a role that does
-- not own the tables -- the migration errors instead of validating a partial
-- view. It fails closed; it never validates what it cannot see.
--
-- APPLY IT ONLY AS ONE TRANSACTION: through the installer (the Migrator runs
-- each file as one), or `psql --single-transaction -v ON_ERROR_STOP=1`. A plain
-- `psql -f` commits statement by statement, SET LOCAL then does nothing, and an
-- interruption between lifting and restoring FORCE would leave it lifted.
-- ---------------------------------------------------------------------------

SET LOCAL row_security = off;

ALTER TABLE mt_services NO FORCE ROW LEVEL SECURITY;
ALTER TABLE mt_sites    NO FORCE ROW LEVEL SECURITY;

-- Enumerate before anything can refuse: the foreign key's own error would name
-- only ONE offending pair. Any violation stops the migration with the count and
-- up to five pairs, and nothing is changed (docs/107: resolve per row first).
DO $$
DECLARE n bigint; r record; pairs text := '';
BEGIN
  SELECT count(*) INTO n
    FROM mt_sites s JOIN mt_services v ON v.id = s.service_id
   WHERE s.customer_id <> v.customer_id;
  IF n > 0 THEN
    FOR r IN SELECT left(s.id::text, 8) AS site, left(v.id::text, 8) AS svc
               FROM mt_sites s JOIN mt_services v ON v.id = s.service_id
              WHERE s.customer_id <> v.customer_id
              ORDER BY 1 LIMIT 5 LOOP
      pairs := pairs || format(' site %s -> service %s;', r.site, r.svc);
    END LOOP;
    RAISE EXCEPTION 'O-1 (029): % site(s) point at another operator''s service:% resolve each first (docs/107, docs/123); nothing was changed', n, pairs;
  END IF;
END $$;

-- Cannot fail on existing data: PRIMARY KEY (id) is strictly stronger, so
-- (id, customer_id) can never reject a row the key accepts. An index, not a
-- restriction (docs/106).
ALTER TABLE mt_services
  ADD CONSTRAINT mt_services_id_customer_key UNIQUE (id, customer_id);

-- The only statement that can refuse. Both columns are NOT NULL, so MATCH
-- SIMPLE is complete and no CHECK is needed (docs/106). ON DELETE and ON UPDATE
-- are left at NO ACTION, as W-2 did: a service's operator cannot change while a
-- site references it, so service migration stays impossible by constraint (S-A).
ALTER TABLE mt_sites
  ADD CONSTRAINT mt_sites_service_customer_fkey
  FOREIGN KEY (customer_id, service_id) REFERENCES mt_services (customer_id, id);

ALTER TABLE mt_sites    FORCE ROW LEVEL SECURITY;
ALTER TABLE mt_services FORCE ROW LEVEL SECURITY;

-- Self-verification, inside the same transaction: any failure undoes all of it.
DO $$
BEGIN
  IF NOT (SELECT relrowsecurity AND relforcerowsecurity FROM pg_class WHERE oid = 'public.mt_sites'::regclass)
  OR NOT (SELECT relrowsecurity AND relforcerowsecurity FROM pg_class WHERE oid = 'public.mt_services'::regclass) THEN
    RAISE EXCEPTION '029: FORCE ROW LEVEL SECURITY was not restored on mt_sites and mt_services';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint
                  WHERE conname = 'mt_sites_service_customer_fkey' AND conrelid = 'public.mt_sites'::regclass
                    AND contype = 'f' AND convalidated) THEN
    RAISE EXCEPTION '029: the O-1 foreign key is missing or not validated';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint
                  WHERE conname = 'mt_services_id_customer_key' AND conrelid = 'public.mt_services'::regclass
                    AND contype = 'u') THEN
    RAISE EXCEPTION '029: the supporting UNIQUE (id, customer_id) is missing';
  END IF;
  IF (SELECT count(*) FROM pg_constraint
       WHERE conrelid = 'public.mt_sites'::regclass
         AND conname IN ('mt_sites_customer_id_fkey', 'mt_sites_service_id_fkey')) <> 2 THEN
    RAISE EXCEPTION '029: a single-column foreign key of mt_sites is missing — the composite one is additive';
  END IF;
END $$;
