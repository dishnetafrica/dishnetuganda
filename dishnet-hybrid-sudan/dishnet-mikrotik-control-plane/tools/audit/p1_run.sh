#!/usr/bin/env bash
# Builds a disposable control-plane database and runs the P-1 measurement.
# Its own database, so tests/run.sh's dnb_test is untouched. Never Phase 0.
set -uo pipefail
cd "$(dirname "$0")/../.."
export PGHOST="${DNB_PGHOST:-/var/tmp}" PGPORT="${DNB_PGPORT:-55432}"
DB="${DNB_P1_DB:-dnb_p1}"
psql -U "${DNB_OWNER_USER:-dnb}" -d postgres -q -c "DROP DATABASE IF EXISTS ${DB};" 2>/dev/null
psql -U "${DNB_OWNER_USER:-dnb}" -d postgres -q -c "CREATE DATABASE ${DB};" || exit 1
export DNB_DSN="pgsql:host=${PGHOST};port=${PGPORT};dbname=${DB}"
php bin/migrate.php >/dev/null || { echo "migration failed"; exit 1; }
php tools/audit/p1_device_site_ownership.php
