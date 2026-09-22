#!/usr/bin/env bash
# Dependency-free test runner. Creates a throwaway database, migrates it,
# runs every suite. No PHPUnit, matching the plugin's convention.
#
#   ./tests/run.sh
#
# Requires a reachable PostgreSQL. Override with DNB_PGHOST / DNB_PGPORT /
# DNB_OWNER_USER, or point DNB_DSN at an existing database.
set -uo pipefail
cd "$(dirname "$0")/.."

PGHOST_="${DNB_PGHOST:-/var/tmp}"
PGPORT_="${DNB_PGPORT:-55432}"
OWNER="${DNB_OWNER_USER:-dnb}"
DB="${DNB_TEST_DB:-dnb_test}"

export PGHOST="$PGHOST_" PGPORT="$PGPORT_"
psql -U "$OWNER" -d postgres -q -c "DROP DATABASE IF EXISTS ${DB};" || exit 1
psql -U "$OWNER" -d postgres -q -c "CREATE DATABASE ${DB};"         || exit 1
export DNB_DSN="pgsql:host=${PGHOST_};port=${PGPORT_};dbname=${DB}"

php bin/migrate.php >/dev/null || { echo "migration failed"; exit 1; }

fail=0
for t in tests/test_*.php; do
  printf '\n=== %s ===\n' "$(basename "$t")"
  php "$t" || fail=1
done

printf '\n%s\n' "============================================================"
[ "$fail" = 0 ] && echo "ALL SUITES PASSED" || echo "SUITE FAILURES"
exit "$fail"
