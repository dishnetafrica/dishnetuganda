#!/usr/bin/env bash
set -uo pipefail
cd "$(dirname "$0")/../.."
export PGHOST="${DNB_PGHOST:-/var/tmp}" PGPORT="${DNB_PGPORT:-55432}"
DB=dnb_scope; OWN="${DNB_OWNER_USER:-dnb}"
psql -U "$OWN" -d postgres -q -c "DROP DATABASE IF EXISTS ${DB};" 2>/dev/null
psql -U "$OWN" -d postgres -q -c "CREATE DATABASE ${DB};" || exit 1
export DNB_DSN="pgsql:host=${PGHOST};port=${PGPORT};dbname=${DB}"
php bin/migrate.php >/dev/null || { echo "migration failed"; exit 1; }
php tools/audit/site_ownership_scope.php
psql -U "$OWN" -d postgres -q -c "DROP DATABASE ${DB};" && echo "(disposable ${DB} dropped)"
