#!/bin/bash
# scenarios.sh — every path of scripts/dnb-staging-census.sh, asserted.
#   HSIM_SANDBOX=yes-this-is-not-the-server scripts/harness/census/scenarios.sh
# HSIM_CSCRIPT=<path> runs the same scenarios against another copy of the
# script (controls on the controls, docs/123 §D).
. "$(dirname "$0")/../staff-login/guard.sh"; . "$REPO/scripts/harness/staff-login/lib.sh"
C=${HSIM:?}/cstate; SCRIPT=${HSIM_CSCRIPT:-$REPO/scripts/dnb-staging-census.sh}
CENSUS=$REPO/dishnet-hybrid-sudan/dishnet-mikrotik-control-plane/tools/audit/production_census.sql
PGH=${HSIM_PGHOST:-/var/tmp}; PGP=${HSIM_PGPORT:-55432}
SU() { psql -h "$PGH" -p "$PGP" -X -q -U postgres "$@"; }
: > "$HSIM/state/results"

echo "== setup: the staging-like Domain-B database, and three other systems' databases =="
"$REPO/scripts/harness/staff-login/reset.sh" 127.0.0.1
SU -d postgres -c "DROP DATABASE IF EXISTS hsim_no_such_db" 2>/dev/null   # must NOT exist: S5b and S8 rely on it
for db in hsim_unms_ucrm hsim_unms_unms hsim_phase0_radius hsim_legacy_dnb; do
  SU -d postgres -c "DROP DATABASE IF EXISTS $db" -c "CREATE DATABASE $db" 2>/dev/null
done
SU -d hsim_phase0_radius -c "CREATE TABLE radcheck (id serial, username text)"
SU -d hsim_legacy_dnb -c "CREATE TABLE mt_migrations (filename text PRIMARY KEY)"
rnd() { openssl rand -hex 12; }

server() {   # the baseline server: staging + the systems a DishNet host runs
  rm -rf "${C:?}"; mkdir -p "$C/env" "$C/senv"; : > "$C/calls.log"
  cat > "$C/containers.txt" <<X
dnb-staging-postgres|postgres:16-alpine|running
dnb-staging-api|dnb-staging-php:8.3|running
dnb-staging-worker|dnb-staging-php:8.3|running
dn-phase0-postgres|postgres:16-alpine|running
unms-postgres|ubnt/unms-postgres:3.0.159|running
unms|ubnt/unms:3.0.159|running
easypanel-traefik.1.abcdef|traefik:3.6.7|running
evolution-api|atendai/evolution-api:v2|running
old-thing|alpine:3|exited
X
  S1=$(rnd); S2=$(rnd); S3=$(rnd); S4=$(rnd); S5=$(rnd); S6=$(rnd); S7=$(rnd)
  printf '%s\n' "$S1" "$S2" "$S3" "$S4" "$S5" "$S6" "$S7" > "$C/secrets.txt"
  printf 'POSTGRES_PASSWORD=%s\nPOSTGRES_DB=dnb\n' "$S1" > "$C/env/dnb-staging-postgres.env"
  for a in api worker; do
    printf 'DNB_DSN=pgsql:host=dnb-staging-postgres;port=5432;dbname=dnb\nDNB_ADMINAPI_PASS=%s\nDNB_TOKEN_PEPPER=%s\nDN_STAFF_IDENTITY=dishnet\n' "$S2" "$S3" > "$C/env/dnb-staging-$a.env"
  done
  printf 'POSTGRES_USER=radius\nPOSTGRES_PASSWORD=%s\n' "$S4" > "$C/env/dn-phase0-postgres.env"
  printf 'POSTGRES_USER=unms\nPOSTGRES_PASSWORD=%s\n' "$S5" > "$C/env/unms-postgres.env"
  printf 'UNMS_PG_PASSWORD=%s\nUNMS_PG_HOST=unms-postgres\n' "$S6" > "$C/env/unms.env"
  printf 'DATABASE_CONNECTION_URI=postgresql://evo:%s@evo-db:5432/evolution\n' "$S7" > "$C/env/evolution-api.env"
  printf 's1aaa|easypanel_traefik\ns2bbb|dishnet_ai\n' > "$C/services.txt"
  printf 'OPENAI_API_KEY=%s\n' "$S7" > "$C/senv/s2bbb.env"
  cat > "$C/pgmap.txt" <<X
dnb-staging-postgres|dnb|dnb_hsim|ok
dnb-staging-postgres|postgres|postgres|ok
dn-phase0-postgres|radius|hsim_phase0_radius|ok
unms-postgres|ucrm|hsim_unms_ucrm|ok
unms-postgres|unms|hsim_unms_unms|ok
X
}
run() {   # run <name> [env assignments…]
  local n=$1; shift
  rm -rf /root/dnb-staging-evidence
  env "$@" CENSUS_SQL="${CENSUS_OVERRIDE:-$CENSUS}" sh "$SCRIPT" > "$HSIM/$n.log" 2>&1 < /dev/null; echo "EXIT=$?" >> "$HSIM/$n.log"
}
no_secret() { local f; while IFS= read -r f; do grep -qF -- "$f" "$HSIM/$1.log" && return 1; done < "$C/secrets.txt"; return 0; }
audit_rows() { SU -d dnb_hsim -Atc "select count(*) from mt_audit_log"; }

echo "== S1: the expected server — Domain B only in staging =="
server; a0=$(audit_rows); run S1
check "S1: exit 0 with the reviewed census" sh -c "grep -q '^EXIT=0$' $HSIM/S1.log && grep -q 'census file verified' $HSIM/S1.log"
check "S1: searched every container and swarm service" has "$HSIM/S1.log" '^searched 9 container\(s\) and 2 swarm service\(s\) for DNB_DSN:$'
check "S1: exactly the two staging containers carry DNB_DSN, printed as host/port/dbname" eq "$(grep -c '^  container dnb-staging-\(api\|worker\) (running): host=dnb-staging-postgres port=5432 dbname=dnb $' "$HSIM/S1.log")" 2
check "S1: Domain B outside staging: NONE" has "$HSIM/S1.log" '^Domain B services \(DNB_DSN\): +NONE outside staging$'
check "S1: every database of every PostgreSQL container was looked at" eq "$(grep -cE '^  (dnb-staging-postgres|dn-phase0-postgres|unms-postgres) / ' "$HSIM/S1.log")" 5
check "S1: the ledger is found in staging only" has "$HSIM/S1.log" '^Databases holding the ledger: +only dnb-staging-postgres / dnb$'
check "S1: the staging DATA verdict is CLEAR, from dnb_adminapi" has "$HSIM/S1.log" '^Staging DATA verdict \(run 1\): +CLEAR, [1-9][0-9]* row\(s\) seen$'
check "S1: the staging SCHEMA level is 28 migrations, last 028" has "$HSIM/S1.log" '^Staging SCHEMA level \(run 2\): +28 migration\(s\), last 028_admin_router_lifecycle_and_provisioning\.sql$'
check "S1: no planted secret appears anywhere in the output" no_secret S1
check "S1: nothing was written — the audit trail is unchanged" eq "$(audit_rows)" "$a0"
check "S1: every catalog query ran in a read-only session (the stub refuses otherwise)" hasnt "$HSIM/S1.log" 'STUB:'

echo "== S2: a Domain-B container OUTSIDE staging, with a password inside its DSN =="
server; P=$(rnd); echo "$P" >> "$C/secrets.txt"
echo 'dnb-prod-api|dnb-php:8.3|running' >> "$C/containers.txt"
printf 'DNB_DSN=pgsql:host=10.0.0.5;port=5432;dbname=dnb_prod;user=dnb_app;password=%s\n' "$P" > "$C/env/dnb-prod-api.env"
run S2
check "S2: found outside staging" has "$HSIM/S2.log" '^Domain B services \(DNB_DSN\): +FOUND OUTSIDE STAGING'
check "S2: its DSN printed as host/port/dbname only" has "$HSIM/S2.log" '^  container dnb-prod-api \(running\): host=10\.0\.0\.5 port=5432 dbname=dnb_prod $'
check "S2: neither the DSN's password nor its user key is printed" sh -c "! grep -qF '$P' $HSIM/S2.log && ! grep -q 'user=' $HSIM/S2.log"
check "S2: no planted secret appears anywhere" no_secret S2

echo "== S3: a swarm SERVICE carries DNB_DSN =="
server; echo 's3ccc|dishnet_dnb' >> "$C/services.txt"; printf 'DNB_DSN=pgsql:host=db;port=5432;dbname=dnb\n' > "$C/senv/s3ccc.env"
run S3
check "S3: the service is listed and counted outside staging" sh -c "grep -q '^  swarm service dishnet_dnb: host=db port=5432 dbname=dnb $' $HSIM/S3.log && grep -q 'FOUND OUTSIDE STAGING' $HSIM/S3.log"

echo "== S4: the Domain-B ledger in a database OUTSIDE staging =="
server; echo 'unms-postgres|dnb_legacy|hsim_legacy_dnb|ok' >> "$C/pgmap.txt"
run S4
check "S4: the other holder is named" has "$HSIM/S4.log" '^Databases holding the ledger: +ALSO OUTSIDE STAGING: unms-postgres / dnb_legacy'

echo "== S5: a PostgreSQL container that cannot be opened =="
server; echo 'dn-phase0-postgres|*|*|deny' >> "$C/pgmap.txt"
run S5
check "S5: it is reported NOT CHECKED, with the reason" has "$HSIM/S5.log" '^  dn-phase0-postgres: NOT CHECKED — psql: error:'
check "S5: and the summary does not call the scan complete" has "$HSIM/S5.log" '1 database\(s\) or container\(s\) NOT CHECKED'

echo "== S5b: a database that is listed but cannot be opened =="
server; echo 'unms-postgres|ucrm_archive|hsim_no_such_db|ok' >> "$C/pgmap.txt"
run S5b
check "S5b: that database is NOT CHECKED, never counted as holding no ledger" has "$HSIM/S5b.log" '^  unms-postgres / ucrm_archive: NOT CHECKED — could not open this database$'
check "S5b: and the summary counts it as not checked" has "$HSIM/S5b.log" '1 database\(s\) or container\(s\) NOT CHECKED'

echo "== S6: an O-1 violation in staging =="
server
SU -d dnb_hsim -c "INSERT INTO mt_sites (id, customer_id, service_id, name) SELECT gen_random_uuid(), a.customer_id, b.id, 'CENSUS-HARNESS-CROSS' FROM (SELECT customer_id FROM mt_services ORDER BY id LIMIT 1) a, (SELECT id, customer_id FROM mt_services ORDER BY id DESC LIMIT 1) b WHERE a.customer_id <> b.customer_id"
run S6
check "S6: the documented DATA run reads BLOCKED(1)" has "$HSIM/S6.log" '^Staging DATA verdict \(run 1\): +BLOCKED\(1\)'
check "S6: and enumerates the offending pair" has "$HSIM/S6.log" '    site [0-9a-f]{8} \(cust [0-9a-f]{8}\) -> service [0-9a-f]{8} \(cust [0-9a-f]{8}\)'
SU -d dnb_hsim -c "DELETE FROM mt_sites WHERE name = 'CENSUS-HARNESS-CROSS'"

echo "== S7: a census file that is not the reviewed one =="
server; cp "$CENSUS" "$HSIM/tampered.sql"; printf '\n-- one extra line\n' >> "$HSIM/tampered.sql"
CENSUS_OVERRIDE="$HSIM/tampered.sql" run S7
check "S7: refused before anything ran" sh -c "grep -q '^EXIT=1$' $HSIM/S7.log && grep -q 'nothing was run' $HSIM/S7.log"
check "S7: no container was even inspected" eq "$(grep -c . "$C/calls.log")" 0

echo "== S8: the staging database cannot be reached =="
server; sed -i 's/^dnb-staging-postgres|dnb|dnb_hsim|ok$/dnb-staging-postgres|dnb|no_such_db|ok/' "$C/pgmap.txt"
run S8
check "S8: the summary says NO VERDICT rather than a verdict" has "$HSIM/S8.log" '^Staging DATA verdict \(run 1\): +NO VERDICT'
check "S8: and the run still completes, exit 0" has "$HSIM/S8.log" '^EXIT=0$'

p=$(grep -c P "$HSIM/state/results" || true); f=$(grep -c F "$HSIM/state/results" || true)
echo; echo "=== census scenarios: $p passed, $f failed ==="
[ "$f" = 0 ]
