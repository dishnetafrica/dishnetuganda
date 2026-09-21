#!/usr/bin/env bash
# Would the proposed N10 constraints break the existing suite or its fixtures?
# Builds a disposable database, applies the constraints, then runs every suite
# against it. Disposable; dropped at the end. Touches no migration.
set -uo pipefail
cd "$(dirname "$0")/../.."
export PGHOST="${DNB_PGHOST:-/var/tmp}" PGPORT="${DNB_PGPORT:-55432}"
DB=dnb_n10_impact; OWN="${DNB_OWNER_USER:-dnb}"
psql -U "$OWN" -d postgres -q -c "DROP DATABASE IF EXISTS ${DB};" 2>/dev/null
psql -U "$OWN" -d postgres -q -c "CREATE DATABASE ${DB};" || exit 1
export DNB_DSN="pgsql:host=${PGHOST};port=${PGPORT};dbname=${DB}"
php bin/migrate.php >/dev/null || { echo "migration failed"; exit 1; }
psql -U "$OWN" -d "$DB" -q <<'SQL'
ALTER TABLE mt_sites   ADD CONSTRAINT n10_sites_id_customer UNIQUE (id, customer_id);
ALTER TABLE mt_devices ADD CONSTRAINT n10_device_site_customer
  FOREIGN KEY (site_id, customer_id) REFERENCES mt_sites (id, customer_id);
ALTER TABLE mt_devices ADD CONSTRAINT n10_device_site_needs_customer
  CHECK (site_id IS NULL OR customer_id IS NOT NULL);
SQL
echo "constraints applied; running the full suite against them"
echo
fail=0; total=0
for t in tests/test_*.php; do
  out="$(php "$t" 2>&1)"; rc=$?
  n=$(printf '%s' "$out" | grep -oE 'PASSED  all [0-9]+ assertions' | grep -oE '[0-9]+' || echo 0)
  total=$((total + ${n:-0}))
  if [ $rc -ne 0 ]; then fail=1; printf '  FAIL  %-34s\n' "$(basename "$t")"
    printf '%s\n' "$out" | grep -E 'FAIL|Fatal|Uncaught' | head -4 | sed 's/^/          /'
  else printf '  ok    %-34s %s assertions\n' "$(basename "$t")" "${n:-0}"; fi
done
echo; echo "  TOTAL assertions under the N10 constraints: $total"
[ $fail -eq 0 ] && echo "  SUITE RESULT: ALL PASSED with the constraints in place" \
                || echo "  SUITE RESULT: FAILURES (above)"
psql -U "$OWN" -d postgres -q -c "DROP DATABASE ${DB};" && echo "  (disposable ${DB} dropped)"
