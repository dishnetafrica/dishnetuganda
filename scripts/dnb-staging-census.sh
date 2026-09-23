#!/bin/sh
# DishNet Domain B — the DEPLOYMENT CENSUS (docs/123). READ ONLY: it changes nothing.
# One command, run as root on the server:
#
#   curl -fsSL -o /root/dnb-census.sh https://raw.githubusercontent.com/dishnetafrica/dishnetuganda/claude/study-this-jhe2eg/scripts/dnb-staging-census.sh \
#     && sh /root/dnb-census.sh 2>&1 | tee /root/dnb-staging-evidence/census-$(date -u +%Y%m%dT%H%M%SZ).log
#
# It answers, from the server itself, the questions docs/79 asks before any
# O-1 decision:
#   1  which containers and swarm services run Domain B: every container's and
#      every service's configuration is searched for the key DNB_DSN. Only that
#      key's host, port and database are printed, never another key and never
#      a credential;
#   2  which PostgreSQL containers hold the Domain-B schema: for each, the list
#      of its databases and whether each has the table public.mt_migrations.
#      Catalog only, in read-only sessions; no row of any other system is read;
#   3  the O-1 census on the staging database, run twice as docs/79 §3 says:
#      as dnb_adminapi for the DATA verdict, as the owner for the SCHEMA level.
#      The census is a READ ONLY transaction that rolls back.
# The census SQL is fetched from the reviewed branch and refused unless its
# sha256 equals the digest pinned below. This script prints no secret, so the
# whole output may be pasted back.
set -eu

EV=/root/dnb-staging-evidence
TS=$(date -u +%Y%m%dT%H%M%SZ)
OUT="$EV/census-$TS"
RAW=https://raw.githubusercontent.com/dishnetafrica/dishnetuganda/claude/study-this-jhe2eg
CENSUS_PATH=dishnet-hybrid-sudan/dishnet-mikrotik-control-plane/tools/audit/production_census.sql
CENSUS_SHA256=513218356a1bb93346ad581a425435532cf2b1a451c148efe59032a261033983
STAGING_PG=dnb-staging-postgres
STAGING_DB=dnb
RO="-c default_transaction_read_only=on"

fail() { echo; echo "STOP: $*" >&2; exit 1; }
step() { echo; echo "== $* =="; }
# Only host, port and dbname of a PDO DSN; user=, password= and anything else never leave.
dsn_parts() { printf '%s' "$1" | sed -E 's/^[a-z]+://' | tr ';' '\n' | grep -E '^(host|port|dbname)=' | tr '\n' ' ' || true; }
clean() { sed -E 's/^psql:[^ ]*: //; s/^NOTICE:  //' "$1"; }

[ "$(id -u)" = 0 ] || fail "run as root"
for t in docker curl sha256sum sed grep awk sort head tr wc; do command -v "$t" >/dev/null 2>&1 || fail "missing tool: $t"; done
install -d -m 0700 "$EV" "$OUT"
date -u '+%Y-%m-%d %H:%M:%S UTC'; hostname

step "0/4 the census instrument, checked against the reviewed digest"
if [ -n "${CENSUS_SQL:-}" ]; then
  cp "$CENSUS_SQL" "$OUT/production_census.sql"        # the test harness only; the digest check still applies
else
  curl -fsSL -o "$OUT/production_census.sql" "$RAW/$CENSUS_PATH" || fail "could not fetch the census from the branch"
fi
got=$(sha256sum "$OUT/production_census.sql" | cut -d' ' -f1)
[ "$got" = "$CENSUS_SHA256" ] || fail "the census file's sha256 is $got, not the reviewed $CENSUS_SHA256 — nothing was run"
echo "census file verified: sha256 $got"

step "1/4 which containers and swarm services run Domain B (configuration only)"
: > "$OUT/domainb.txt"
n_c=0
for c in $(docker ps -a --format '{{.Names}}'); do
  n_c=$((n_c + 1))
  dsn=$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$c" 2>/dev/null | sed -n 's/^DNB_DSN=//p' | head -1)
  [ -n "$dsn" ] || continue
  st=$(docker inspect -f '{{.State.Status}}' "$c" 2>/dev/null || echo '?')
  echo "container $c ($st): $(dsn_parts "$dsn")" >> "$OUT/domainb.txt"
done
n_s=0
for s in $(docker service ls -q 2>/dev/null || true); do
  n_s=$((n_s + 1))
  name=$(docker service inspect -f '{{.Spec.Name}}' "$s" 2>/dev/null || echo "$s")
  dsn=$(docker service inspect -f '{{range .Spec.TaskTemplate.ContainerSpec.Env}}{{println .}}{{end}}' "$s" 2>/dev/null | sed -n 's/^DNB_DSN=//p' | head -1)
  [ -n "$dsn" ] || continue
  echo "swarm service $name: $(dsn_parts "$dsn")" >> "$OUT/domainb.txt"
done
echo "searched $n_c container(s) and $n_s swarm service(s) for DNB_DSN:"
if [ -s "$OUT/domainb.txt" ]; then sed 's/^/  /' "$OUT/domainb.txt"; else echo "  none carries it"; fi
OUTSIDE=$(grep -vE '^container dnb-staging-(api|worker) ' "$OUT/domainb.txt" || true)
if command -v php >/dev/null 2>&1; then
  if php -m 2>/dev/null | grep -qix 'pdo_pgsql'; then HOSTPHP="host PHP HAS pdo_pgsql — a host-level process could run Domain B; crontab and systemd are not searched by this script"
  else HOSTPHP="host PHP lacks pdo_pgsql — no host-level PHP process can reach PostgreSQL"; fi
else HOSTPHP="no PHP on the host"; fi
echo "$HOSTPHP"

step "2/4 which PostgreSQL containers hold the Domain-B schema (catalog only, read-only sessions)"
docker ps --format '{{.Names}} {{.Image}}' | awk 'tolower($2) ~ /postgres/ {print $1}' > "$OUT/pg-containers.txt"
: > "$OUT/ledger.txt"
for c in $(cat "$OUT/pg-containers.txt"); do
  u=$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$c" 2>/dev/null | sed -n 's/^POSTGRES_USER=//p' | head -1)
  u=${u:-postgres}
  if ! docker exec -e PGOPTIONS="$RO" "$c" psql -X -A -t -w -U "$u" -d postgres \
       -c "SELECT datname FROM pg_database WHERE NOT datistemplate AND datallowconn ORDER BY 1" > "$OUT/dbs.txt" 2> "$OUT/err.txt"; then
    echo "$c: NOT CHECKED — $(head -1 "$OUT/err.txt")" >> "$OUT/ledger.txt"; continue
  fi
  while IFS= read -r d; do
    [ -n "$d" ] || continue
    has=$(docker exec -e PGOPTIONS="$RO" "$c" psql -X -A -t -w -U "$u" -d "$d" \
          -c "SELECT to_regclass('public.mt_migrations') IS NOT NULL" 2>/dev/null || true)
    case $has in
      t) echo "$c / $d: HOLDS the Domain-B ledger (public.mt_migrations)" ;;
      f) echo "$c / $d: no Domain-B ledger" ;;
      *) echo "$c / $d: NOT CHECKED — could not open this database" ;;
    esac >> "$OUT/ledger.txt"
  done < "$OUT/dbs.txt"
done
rm -f "$OUT/dbs.txt" "$OUT/err.txt"
echo "$(wc -l < "$OUT/pg-containers.txt" | tr -d ' ') PostgreSQL container(s):"
if [ -s "$OUT/ledger.txt" ]; then sed 's/^/  /' "$OUT/ledger.txt"; else echo "  none running"; fi
HOLDERS=$(grep 'HOLDS the Domain-B ledger' "$OUT/ledger.txt" | sed 's/: HOLDS.*//' | tr '\n' ';' | sed 's/;$//' || true)
OTHER_HOLDERS=$(grep 'HOLDS the Domain-B ledger' "$OUT/ledger.txt" | grep -v "^$STAGING_PG / $STAGING_DB:" || true)
UNCHECKED=$(grep -c 'NOT CHECKED' "$OUT/ledger.txt" || true)

step "3/4 the O-1 census on the staging database — run 1 as dnb_adminapi: the DATA verdict"
docker exec -i "$STAGING_PG" psql -X -q -w -U dnb_adminapi -d "$STAGING_DB" \
  < "$OUT/production_census.sql" > "$OUT/census-run1-dnb_adminapi.txt" 2>&1 || true
clean "$OUT/census-run1-dnb_adminapi.txt"
step "3/4 run 2 as the owner dnb: the SCHEMA level (its own verdict is withheld by design — it cannot see the data)"
docker exec -i "$STAGING_PG" psql -X -q -w -U dnb -d "$STAGING_DB" \
  < "$OUT/production_census.sql" > "$OUT/census-run2-owner.txt" 2>&1 || true
clean "$OUT/census-run2-owner.txt"
V1=$(grep -oE '>> (CLEAR|BLOCKED\([0-9]+\)|INDETERMINATE)' "$OUT/census-run1-dnb_adminapi.txt" | head -1 | sed 's/^>> //' || true)
SEEN=$(grep -oE 'rows this session could actually see +: [0-9]+' "$OUT/census-run1-dnb_adminapi.txt" | grep -oE '[0-9]+$' | head -1 || true)
MIG=$(grep -oE 'migrations applied +: [0-9]+' "$OUT/census-run2-owner.txt" | grep -oE '[0-9]+$' | head -1 || true)
LAST=$(grep -oE '[0-9]{3}_[a-z0-9_]+\.sql' "$OUT/census-run2-owner.txt" | tail -1 || true)

step "4/4 result"
if [ -z "$OUTSIDE" ]; then Q1="NONE outside staging"; else Q1="FOUND OUTSIDE STAGING — $(echo "$OUTSIDE" | wc -l | tr -d ' ') entr(y/ies), listed in step 1"; fi
if [ -z "$OTHER_HOLDERS" ]; then Q2="only $STAGING_PG / $STAGING_DB"; else Q2="ALSO OUTSIDE STAGING: $(echo "$OTHER_HOLDERS" | sed 's/: HOLDS.*//' | tr '\n' ' ')"; fi
[ -n "$HOLDERS" ] || Q2="NONE — not even staging (read step 2)"
[ "${UNCHECKED:-0}" = 0 ] || Q2="$Q2; $UNCHECKED database(s) or container(s) NOT CHECKED (step 2)"
echo "Domain B services (DNB_DSN):      $Q1"
echo "  $HOSTPHP"
echo "Databases holding the ledger:     $Q2"
echo "Staging DATA verdict (run 1):     ${V1:-NO VERDICT — run 1 did not finish; read its output above}${SEEN:+, $SEEN row(s) seen}"
echo "Staging SCHEMA level (run 2):     ${MIG:-NOT READ} migration(s)${LAST:+, last $LAST}"
echo "Evidence kept in $OUT (mode 0700)."
echo
echo "=== CENSUS $(date -u +%FT%TZ): Domain B services: $Q1; ledger: $Q2; staging verdict: ${V1:-NO VERDICT}; staging schema: ${MIG:-?} migrations ==="
echo "Nothing was changed. This output contains no password or key: paste all of it back."
