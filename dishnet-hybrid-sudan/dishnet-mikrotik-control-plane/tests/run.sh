#!/usr/bin/env bash
# Dependency-free test runner. Creates a throwaway database, installs into it,
# runs every suite. No PHPUnit, matching the plugin's convention.
#
#   ./tests/run.sh
#
# Requires a reachable PostgreSQL. Override with DNB_PGHOST / DNB_PGPORT /
# DNB_OWNER_USER, or point DNB_DSN at an existing database.
#
# CREDENTIALS ARE GENERATED PER RUN. Since docs/97 the migrations create every
# login role with no password, so there is nothing to fall back to — which is
# the point. Each run mints fresh random passwords, exports them, and lets the
# real installer apply them, so the suite exercises the engineer's own install
# path rather than a shortcut around it. Nothing is written to disk.
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

rnd() { php -r 'echo bin2hex(random_bytes(24));'; }
export DNB_APP_PASS="$(rnd)"        DNB_WORKER_PASS="$(rnd)"
export DNB_ADMIN_PASS="$(rnd)"      DNB_ADMINAPI_PASS="$(rnd)"
export DNB_ADMINWRITE_PASS="$(rnd)" DNB_RADIUS_PASS="$(rnd)"
export DNB_TOKEN_PEPPER="$(rnd)"    DNB_SECRET_KEY="$(rnd)"

php plugin/bin/plugin.php install >/dev/null || { echo "install failed"; exit 1; }

fail=0
for t in tests/test_*.php; do
  printf '\n=== %s ===\n' "$(basename "$t")"
  php "$t" || fail=1
done

printf '\n%s\n' "============================================================"
[ "$fail" = 0 ] && echo "ALL SUITES PASSED" || echo "SUITE FAILURES"
exit "$fail"
