#!/bin/sh
# DishNet Domain-B staging — REDEPLOY to the reviewed build and apply migration
# 029, O-1 (docs/124). One command, run as root on the server:
#
#   curl -fsSL -o /root/dnb-redeploy.sh https://raw.githubusercontent.com/dishnetafrica/dishnetuganda/claude/study-this-jhe2eg/scripts/dnb-staging-redeploy.sh \
#     && sh /root/dnb-redeploy.sh 2>&1 | tee /root/dnb-staging-evidence/redeploy-$(date -u +%Y%m%dT%H%M%SZ).log
#
# GATE 2 of docs/107, in the order docs/79 §7b sets, each step its own evidence:
#   0  read-only checks: stage 1 healthy; which staff identity the API runs.
#   1  build the artifact ON THIS SERVER from the public branch; refuse unless
#      its content digest is the reviewed one. Nothing live changes.
#   2  read-only again: the census as dnb_adminapi must read CLEAR (GATE 1,
#      re-taken minutes before the migration), and the new build's doctor
#      must report no blocker. Anything else stops here with nothing changed.
#   3  swap /opt/dnb-staging/app (the previous tree is kept) and apply the
#      pending migration with the installer and the installation's own
#      secrets. Migration 029 refuses by itself, and changes nothing, if a
#      violating row appeared since step 2; the previous tree is then put back.
#   4  verify INDEPENDENTLY, afterwards (docs/79 §7b step 6): the key exists
#      and is validated, FORCE row security is on for both tables, the census
#      reads CLEAR again, and an execution test inside a transaction that is
#      always rolled back: dnb_app is refused a cross-operator site BY NAME
#      while the same operator's site is accepted. Residue is checked.
#   5  restart ONLY the two application containers; verify over loopback and
#      through the hostname, and that no other container changed.
#   6  result.
#
# It touches no production container, no Traefik file, no DNS record, no
# firewall rule and no PostgreSQL instance but dnb-staging-postgres. Nothing
# real is contacted: DN_ALLOW_REAL_BINDINGS stays absent and delivery stays
# simulated. The API keeps the staff identity it runs with (docs/122).
#
# The version name is still 0.1.0-rc1: compare the CONTENT DIGEST, never the
# name (docs/96). Deployed since 2026-09-23 17:34 UTC: 1bc95524…; this build:
# 780023ff….
set -eu

EV=/root/dnb-staging-evidence
APP=/opt/dnb-staging
IMG=dnb-staging-php:8.3
# The source may be overridden only for the local rehearsal (scripts/harness/
# redeploy). It cannot change what is deployed: the digest below decides.
REPO=${DNB_REDEPLOY_REPO:-https://github.com/dishnetafrica/dishnetuganda}
BRANCH=claude/study-this-jhe2eg
CP=dishnet-hybrid-sudan/dishnet-mikrotik-control-plane
HOST=portal-staging.dishnetuganda.com
# Content digest of the reviewed build = sha256 of the archive's SHA256SUMS (docs/124 §D).
DIGEST=780023ff04545bb8b5eb921c63b9e7bb7d885b3079ecc6069c1797d89b1c46d0
# The census the gate reads, unchanged since docs/123 (tools/ is not in the package).
CENSUS_SHA=513218356a1bb93346ad581a425435532cf2b1a451c148efe59032a261033983
MIG=029_o1_site_service_same_operator.sql
FK=mt_sites_service_customer_fkey
UQ=mt_services_id_customer_key
TS=$(date -u +%Y%m%dT%H%M%SZ)
RO="-c default_transaction_read_only=on"

fail() { echo; echo "STOP: $*" >&2; exit 1; }
step() { echo; echo "== $* =="; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@" || true; }
# sq <sql>: one read-only answer from the staging instance's catalogue.
sq() { docker exec -e PGOPTIONS="$RO" dnb-staging-postgres psql -U postgres -d dnb -Atc "$1"; }
# census <label>: the O-1 census as dnb_adminapi; prints its verdict.
census() {
  out="$EV/redeploy-$TS.census-$1.txt"
  docker exec -i dnb-staging-postgres psql -X -q -w -U dnb_adminapi -d dnb < "$CENSUS" > "$out" 2>&1 || true
  v=$(grep -oE '>> (CLEAR|BLOCKED\([0-9]+\)|INDETERMINATE)' "$out" | head -1 | sed 's/^>> //' || true)
  echo "${v:-NO VERDICT}"
}
seen() { grep -oE 'rows this session could actually see +: [0-9]+' "$EV/redeploy-$TS.census-$1.txt" | grep -oE '[0-9]+$' | head -1 || true; }

[ "$(id -u)" = 0 ] || fail "run as root"
for t in docker curl tar sha256sum sed grep awk comm; do
  command -v "$t" >/dev/null 2>&1 || fail "missing tool: $t"
done
install -d -m 0700 "$EV"

step "0/6 read-only checks (nothing is changed in this step)"
for c in dnb-staging-postgres dnb-staging-api dnb-staging-worker; do
  [ "$(docker inspect -f '{{.State.Running}}' "$c" 2>/dev/null || true)" = true ] \
    || fail "$c is not running — stage 1 (docs/120 §15) is not in place"
done
[ -d "$APP/app" ] || fail "$APP/app is missing"
for f in runtime.env install.env secrets.docker.env; do
  [ -f "$APP/env/$f" ] || fail "$APP/env/$f is missing — not the stage-1 layout"
done
docker image inspect "$IMG" >/dev/null 2>&1 || fail "image $IMG is missing"
for d in "$APP"/app.new-*; do [ -e "$d" ] && fail "$d exists — an earlier run stopped mid-way; paste its log back first"; done
cur=$(sha256sum "$APP/app/SHA256SUMS" | cut -d' ' -f1)
echo "deployed content digest: $cur"
[ "$cur" = "$DIGEST" ] && echo "NOTE: the reviewed build is already deployed; the run continues and re-verifies"
api_env=$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' dnb-staging-api) || fail "cannot read the API's environment"
wrk_env=$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' dnb-staging-worker) || fail "cannot read the worker's environment"
for e in "$api_env" "$wrk_env"; do
  echo "$e" | grep -q '^DN_ALLOW_REAL_BINDINGS=' && fail "DN_ALLOW_REAL_BINDINGS is set on a staging container — F6-B is NOT authorized; refusing"
done
api_get() { echo "$api_env" | grep "^$1=" | head -1 | cut -d= -f2-; }
if [ "$(api_get DN_STAFF_IDENTITY)" = dishnet ]; then
  MODE=real; TRUSTED=$(api_get DN_TRUSTED_PROXY); ORIGIN=$(api_get DN_PORTAL_ORIGIN)
  [ -z "$(api_get DN_DEV_STAFF_IDENTITY)" ] || fail "the API carries both the real provider and the development identity — not a state this script knows"
  echo "$TRUSTED" | grep -qE '^[0-9]{1,3}(\.[0-9]{1,3}){3}$' || fail "DN_TRUSTED_PROXY on the API is not one IPv4 address: '$TRUSTED'"
  [ "$ORIGIN" = "https://$HOST" ] || fail "DN_PORTAL_ORIGIN on the API is '$ORIGIN', not https://$HOST"
  echo "API identity: the real DishNet staff login (docs/122) — trusted proxy $TRUSTED, origin $ORIGIN"
elif [ -n "$(api_get DN_DEV_STAFF_IDENTITY)" ]; then
  MODE=dev; echo "API identity: the development identity (the stage-2 demonstration posture)"
else
  fail "the API runs neither the real DishNet staff login nor the development identity — not a state this script knows"
fi
docker inspect -f '{{.Name}}|{{.State.Status}}|{{.State.StartedAt}}|{{.RestartCount}}' $(docker ps -aq) \
  | sort > "$EV/redeploy-$TS.before"
before_n=$(sq "SELECT count(*) FROM mt_migrations")
before_last=$(sq "SELECT max(filename) FROM mt_migrations")
echo "migrations before: $before_n (last $before_last)"
[ "$(code http://127.0.0.1:8099/)" = 200 ] || fail "the panel does not answer 200 on 127.0.0.1:8099"
echo "ok: stage 1 is healthy"

step "1/6 build the artifact on this server from $BRANCH and check its content digest"
SRC="$EV/src-$TS"
install -d "$SRC"
if command -v git >/dev/null 2>&1; then
  git clone -q --depth 1 --branch "$BRANCH" "$REPO.git" "$SRC"
  ( cd "$SRC" && git rev-parse HEAD ) | tee "$EV/redeploy-$TS.commit"
else
  curl -fsSL "https://codeload.github.com/dishnetafrica/dishnetuganda/tar.gz/refs/heads/$BRANCH" \
    | tar -xz -C "$SRC" --strip-components=1
  echo "branch tarball of $BRANCH (no git on this host)" | tee "$EV/redeploy-$TS.commit"
fi
sh "$SRC/$CP/plugin/bin/package.sh" "$EV/dist-$TS" | sed -n '1,4p'
ART=$(ls "$EV/dist-$TS"/*.tar.gz | head -1)
[ -f "$ART" ] || fail "no artifact was built"
NEW="$APP/app.new-$TS"
install -d -m 0750 "$NEW"
tar -xzf "$ART" -C "$NEW" --strip-components=1
( cd "$NEW" && sha256sum -c SHA256SUMS --quiet ) || { rm -rf "$NEW"; fail "the extracted files do not match SHA256SUMS"; }
got=$(sha256sum "$NEW/SHA256SUMS" | cut -d' ' -f1)
[ "$got" = "$DIGEST" ] || { rm -rf "$NEW"; fail "content digest $got is not the reviewed build $DIGEST — nothing was changed"; }
echo "artifact-ok: $(cat "$NEW/VERSION"), $(find "$NEW" -type f | wc -l | tr -d ' ') files, content digest $got"
[ -f "$NEW/migrations/$MIG" ] || { rm -rf "$NEW"; fail "the build carries no migration 029 — wrong build"; }
CENSUS="$SRC/$CP/tools/audit/production_census.sql"
[ "$(sha256sum "$CENSUS" | cut -d' ' -f1)" = "$CENSUS_SHA" ] || { rm -rf "$NEW"; fail "the census file is not the reviewed one — nothing was changed"; }
echo "census file: the reviewed one (docs/123)"

step "2/6 read-only: GATE 1 re-taken — the census as dnb_adminapi must read CLEAR; the new build's doctor must report no blocker"
V0=$(census before)
S0=$(seen before)
echo "census before the migration: $V0, ${S0:-?} row(s) seen — full output kept in $EV/redeploy-$TS.census-before.txt"
if [ "$V0" != CLEAR ]; then
  grep -E 'SECTION 4|BLOCK|would refuse|UNREADABLE|HIDDEN|>>' "$EV/redeploy-$TS.census-before.txt" | sed 's/^/  /' | head -40
  rm -rf "$NEW"
  fail "the census does not read CLEAR ($V0) — nothing was changed. Paste this output back; each reported row needs its own decision (docs/107)"
fi
RUN="docker run --rm --network dnb-staging -v $APP/env:/run/dnb -w /app --env-file $APP/env/runtime.env --env-file $APP/env/install.env --env-file $APP/env/secrets.docker.env"
if [ "$MODE" = real ]; then
  DOCTOR="-e DN_STAFF_IDENTITY=dishnet -e DN_TRUSTED_PROXY=$TRUSTED -e DN_PORTAL_ORIGIN=$ORIGIN"; POSTURE="production posture"
else
  DOCTOR=""; POSTURE="--disposable (the development identity is a WARN there, and the truth of that posture)"
fi
# shellcheck disable=SC2086
doc0=$($RUN -v "$NEW:/app:ro" $DOCTOR "$IMG" php plugin/bin/plugin.php doctor $([ "$MODE" = dev ] && echo --disposable) 2>&1) || true
echo "$doc0" | grep -E 'checks:|blocker|BLOCK|WARN' | sed 's/^/  /'
echo "$doc0" | grep -qE '[0-9]+ checks: .*, 0 blocker' || { rm -rf "$NEW"; fail "the new build's doctor ($POSTURE) reports a blocker — nothing was changed; paste this output back"; }
echo "doctor ($POSTURE): no blocker"

step "3/6 swap the application tree and apply the pending migration"
mv "$APP/app" "$APP/app.prev-$TS"
mv "$NEW" "$APP/app"
echo "swapped: $APP/app is the new build; previous tree at $APP/app.prev-$TS"
if ! $RUN -v "$APP/app:/app:ro" "$IMG" php plugin/bin/plugin.php install > "$EV/redeploy-$TS.install.txt" 2>&1; then
  sed 's/^/  /' "$EV/redeploy-$TS.install.txt"
  mv "$APP/app" "$APP/app.refused-$TS" && mv "$APP/app.prev-$TS" "$APP/app"
  fail "the installer refused (its lines are above). The previous tree is back at $APP/app, the refused build is at $APP/app.refused-$TS, and no container was restarted. Paste this output back"
fi
sed 's/^/  /' "$EV/redeploy-$TS.install.txt"
after_n=$(sq "SELECT count(*) FROM mt_migrations")
after_last=$(sq "SELECT max(filename) FROM mt_migrations")
want=$(ls "$APP/app/migrations"/*.sql | wc -l | tr -d ' ')
echo "migrations after: $after_n (last $after_last); migration files in the build: $want"
[ "$after_n" = "$want" ] || fail "the ledger ($after_n) does not match the build's migration files ($want)"
[ "$after_last" = "$MIG" ] || fail "the latest applied migration is $after_last, not $MIG"

step "4/6 verify independently, afterwards (docs/79 §7b step 6)"
cat=$(sq "SELECT (SELECT count(*) FROM pg_constraint WHERE conname = '$FK' AND conrelid = 'public.mt_sites'::regclass AND contype = 'f' AND convalidated)
          || '|' || (SELECT count(*) FROM pg_constraint WHERE conname = '$UQ' AND conrelid = 'public.mt_services'::regclass AND contype = 'u')
          || '|' || (SELECT count(*) FROM pg_constraint WHERE conrelid = 'public.mt_sites'::regclass AND conname IN ('mt_sites_customer_id_fkey','mt_sites_service_id_fkey'))
          || '|' || (SELECT bool_and(relrowsecurity AND relforcerowsecurity) FROM pg_class WHERE oid IN ('public.mt_sites'::regclass, 'public.mt_services'::regclass))")
echo "catalogue (validated key | supporting UNIQUE | single-column keys kept | FORCE on both): $cat"
[ "$cat" = "1|1|2|true" ] || fail "the schema is not what migration 029 promises ($cat; expected 1|1|2|true) — paste this output back"
V1=$(census after)
S1=$(seen after)
echo "census after the migration: $V1, ${S1:-?} row(s) seen — full output kept in $EV/redeploy-$TS.census-after.txt"
[ "$V1" = CLEAR ] && [ "${S1:-0}" -gt 0 ] || fail "the census after the migration does not read CLEAR over a visible estate ($V1, ${S1:-?} rows)"
proj=$(docker exec -e PGOPTIONS="$RO" dnb-staging-postgres psql -X -A -t -w -U dnb_adminapi -d dnb -c \
  "SELECT count(*) || '|' || count(*) FILTER (WHERE s.customer_id <> v.customer_id) FROM mt_admin_sites() s LEFT JOIN mt_admin_services() v ON v.id = s.service_id")
echo "through the Admin projections as dnb_adminapi (sites seen | sites whose service is another operator's): $proj"
echo "$proj" | grep -qE '^[1-9][0-9]*[|]0$' || fail "the projection check did not see sites with none crossing (got '$proj')"
pair=$(docker exec -e PGOPTIONS="$RO" dnb-staging-postgres psql -X -A -t -w -U dnb_adminapi -d dnb -c \
  "SELECT string_agg(customer_id::text || ' ' || id::text, ' ') FROM (SELECT DISTINCT ON (customer_id) customer_id, id FROM mt_admin_services() ORDER BY customer_id, started_at, id LIMIT 2) x")
# shellcheck disable=SC2086
set -- $pair
if [ $# -eq 4 ]; then
  A=$1; SA=$2; SB=$4
  probe=$(docker exec -i dnb-staging-postgres psql -X -A -t -w -U dnb_app -d dnb 2>&1 <<SQL || true
BEGIN;
SELECT set_config('app.customer_id', '$A', true) IS NOT NULL;
SELECT 'who|' || current_user || '|' || coalesce(mt_current_customer()::text, 'none');
INSERT INTO mt_sites (customer_id, service_id, name) VALUES ('$A', '$SA', 'O1-PROBE-OWN (rolled back)');
SELECT 'own|' || count(*) FROM mt_sites WHERE name = 'O1-PROBE-OWN (rolled back)';
SAVEPOINT cross_operator;
INSERT INTO mt_sites (customer_id, service_id, name) VALUES ('$A', '$SB', 'O1-PROBE-CROSS (rolled back)');
ROLLBACK TO SAVEPOINT cross_operator;
SELECT 'cross|' || count(*) FROM mt_sites WHERE name = 'O1-PROBE-CROSS (rolled back)';
ROLLBACK;
SQL
)
  echo "$probe" | sed 's/^/  /'
  echo "$probe" | grep -q "^who|dnb_app|$A\$" || fail "the execution test did not run as dnb_app in the chosen operator's context"
  echo "$probe" | grep -q '^own|1$' || fail "CONTROL failed: the same-operator site was not accepted"
  echo "$probe" | grep -q "violates foreign key constraint \"$FK\"" || fail "the cross-operator site was NOT refused by $FK"
  echo "$probe" | grep -q '^cross|0$' || fail "the cross-operator site is visible after its refusal"
  left=$(sq "SELECT count(*) FROM mt_sites WHERE name LIKE 'O1-PROBE-%'")
  [ "$left" = 0 ] || fail "the execution test left $left row(s) behind"
  echo "execution test (rolled back): dnb_app, operator ${A%%-*}…: own site accepted; a site on another operator's service refused by $FK; residue 0"
  PROBE="cross-operator site refused by name"
else
  echo "execution test NOT RUN: fewer than two operators hold a service here (the catalogue and census results above stand)"
  PROBE="execution test not run (fewer than two operators with a service)"
fi

step "5/6 restart the two application containers — and nothing else"
docker restart dnb-staging-api dnb-staging-worker >/dev/null
i=0; until [ "$(code http://127.0.0.1:8099/)" = 200 ]; do i=$((i+1)); [ $i -le 30 ] || break; sleep 1; done
B=$(mktemp "$EV/body.XXXXXX")
c_panel=$(code http://127.0.0.1:8099/)
c_js=$(code http://127.0.0.1:8099/routers.js)
c_sess=$(curl -s -o "$B" -w '%{http_code}' http://127.0.0.1:8099/api/v1/admin/session || true)
echo "loopback: panel $c_panel, routers.js $c_js, GET /api/v1/admin/session $c_sess $(cat "$B")"
[ "$c_panel" = 200 ] && [ "$c_js" = 200 ] && [ "$c_sess" = 401 ] || fail "the API did not come back as expected"
if [ "$MODE" = real ]; then
  grep -q '"provider":"dishnet"' "$B" || fail "after the restart the API no longer names the dishnet provider"
  p_root=$(code -k --resolve "$HOST:443:127.0.0.1" "https://$HOST/")
  p_sess=$(curl -sk -o "$B" -w '%{http_code}' --resolve "$HOST:443:127.0.0.1" "https://$HOST/api/v1/admin/session" || true)
  echo "through Traefik: / $p_root, GET /api/v1/admin/session $p_sess $(cat "$B")"
  [ "$p_root" = 200 ] && [ "$p_sess" = 401 ] && grep -q '"provider":"dishnet"' "$B" \
    || echo "WARN: through Traefik expected 200 and a 401 naming the dishnet provider — check the route (docs/122)"
else
  p_js=$(code -k --resolve "$HOST:443:127.0.0.1" "https://$HOST/routers.js")
  echo "through Traefik: $p_js (401 expected: basic auth in front)"
  [ "$p_js" = 401 ] || echo "WARN: expected 401 from Traefik, got $p_js — check the stage-2 route file (docs/120 §15.8.6)"
fi
rm -f "$B"
echo "worker binding: $(docker logs --since 3m dnb-staging-worker 2>&1 | grep -m1 -o '"delivery_binding":"[^"]*"' || echo '(not yet logged)')"
docker inspect -f '{{.Name}}|{{.State.Status}}|{{.State.StartedAt}}|{{.RestartCount}}' $(docker ps -aq) \
  | sort > "$EV/redeploy-$TS.after"
changed=$(comm -13 "$EV/redeploy-$TS.before" "$EV/redeploy-$TS.after" | cut -d'|' -f1 | tr -d '/' | sort | tr '\n' ' ')
echo "containers whose status/StartedAt/RestartCount changed: ${changed:-none}"
[ "$changed" = "dnb-staging-api dnb-staging-worker " ] || fail "unexpected container change: ${changed:-none}"
cut -d'|' -f1 "$EV/redeploy-$TS.before" | sort > "$EV/redeploy-$TS.names.before"
cut -d'|' -f1 "$EV/redeploy-$TS.after"  | sort > "$EV/redeploy-$TS.names.after"
removed=$(comm -23 "$EV/redeploy-$TS.names.before" "$EV/redeploy-$TS.names.after" | tr '\n' ' ')
echo "containers removed (must be none): ${removed:-none}"
[ -z "$removed" ] || fail "a container disappeared during the redeploy: $removed"

step "6/6 result"
echo "=== REDEPLOYED $(date -u +%FT%TZ): $(cat "$APP/app/VERSION"), content digest $DIGEST, migrations $after_n (last $after_last); O-1: key validated, FORCE on, census $V0 before and $V1 after, $PROBE ==="
echo "Previous tree kept at $APP/app.prev-$TS. Rollback of the CODE, if ever needed:"
echo "  mv $APP/app $APP/app.failed-$TS && mv $APP/app.prev-$TS $APP/app && docker restart dnb-staging-api dnb-staging-worker"
echo "  (migration 029 adds one UNIQUE and one foreign key; the previous build neither needs nor conflicts with them, so it needs no undoing)"
echo "Paste this whole output back; leave nothing out."
