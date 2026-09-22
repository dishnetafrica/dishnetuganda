#!/usr/bin/env bash
# N10 candidate evidence. Each pass runs in its OWN fresh disposable database,
# built from the real migrations, and every database is dropped afterwards.
# Nothing here proposes a change to keep: it measures how each candidate behaves
# under this schema's real roles and real FORCE ROW LEVEL SECURITY.
#
#   ./tools/audit/n10_run.sh            # the passes that stand: 2, 3, 4
#   ./tools/audit/n10_run.sh 1          # the superseded pass, for the record
set -uo pipefail
cd "$(dirname "$0")/../.."
export PGHOST="${DNB_PGHOST:-/var/tmp}" PGPORT="${DNB_PGPORT:-55432}"
OWN="${DNB_OWNER_USER:-dnb}"
PASSES="${*:-2 3 4}"
for n in $PASSES; do
  case "$n" in
    1) f=tools/audit/n10_candidates.php;  label="pass 1 — SUPERSEDED, see docs/73 §1.2" ;;
    2) f=tools/audit/n10_candidates2.php; label="pass 2 — FK validation, MATCH modes, trigger visibility" ;;
    3) f=tools/audit/n10_candidates3.php; label="pass 3 — site deletion, and the request-role path" ;;
    4) f=tools/audit/n10_candidates4.php; label="pass 4 — the FK+CHECK combination, migration safety" ;;
    *) echo "unknown pass: $n"; exit 1 ;;
  esac
  DB="dnb_n10_$n"
  printf '\n============================================================\n%s\n============================================================\n' "$label"
  psql -U "$OWN" -d postgres -q -c "DROP DATABASE IF EXISTS ${DB};" 2>/dev/null
  psql -U "$OWN" -d postgres -q -c "CREATE DATABASE ${DB};" || exit 1
  DNB_DSN="pgsql:host=${PGHOST};port=${PGPORT};dbname=${DB}" \
    sh -c 'php bin/migrate.php >/dev/null' || { echo "migration failed"; exit 1; }
  DNB_DSN="pgsql:host=${PGHOST};port=${PGPORT};dbname=${DB}" php "$f"
  psql -U "$OWN" -d postgres -q -c "DROP DATABASE ${DB};"
done
echo "(all disposable databases dropped)"
