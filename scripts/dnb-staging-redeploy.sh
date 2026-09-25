#!/bin/sh
# DishNet Domain-B staging — REDEPLOY to the reviewed build and apply migration
# 030, operator onboarding (docs/125). One command, run as root on the server:
#
#   curl -fsSL -o /root/dnb-redeploy.sh https://raw.githubusercontent.com/dishnetafrica/dishnetuganda/claude/study-this-jhe2eg/scripts/dnb-staging-redeploy.sh \
#     && sh /root/dnb-redeploy.sh 2>&1 | tee /root/dnb-staging-evidence/redeploy-$(date -u +%Y%m%dT%H%M%SZ).log
#
# The previous version of this file applied migration 029 and ran on staging at
# 2026-09-24 04:14 UTC (docs/124 §H). It is kept at commit 938c002.
#
#   0  read-only checks: stage 1 healthy; which staff identity the API runs;
#      and migration 029 ALREADY applied, with its key in place. This command
#      applies 030 on top of 029 and nothing else: 029 needs the census
#      re-taken first, which only its own command does (docs/124 §E).
#   1  build the artifact ON THIS SERVER from the reviewed commit, fetched by
#      its hash, so a later push to the branch cannot change what is built;
#      refuse unless its content digest is the reviewed one. Nothing live changes.
#   2  read-only: the new build's doctor must report no blocker; its warnings
#      are printed.
#   3  swap /opt/dnb-staging/app (the previous tree is kept) and apply the
#      pending migration with the installer and the installation's own
#      secrets. 030 checks its own catalogue and refuses to commit if any of
#      it is wrong; the previous tree is then put back.
#   4  verify INDEPENDENTLY, afterwards:
#        a. the catalogue: the store belongs to dnb_def_prov; the three writers
#           are SECURITY DEFINER, owned by dnb_def_prov, and EXECUTE-able by
#           dnb_adminwrite and nobody else; the helpers are callable by their
#           owner only; the location writer takes no operator; 029's key holds.
#        b. an execution test as dnb_adminwrite, in a transaction that is
#           ALWAYS rolled back: create an operator, replay it (same operator,
#           nothing new), reuse its key for another name (refused), start its
#           service, add a location whose operator is DERIVED from the service.
#        c. every other login role, enumerated from pg_roles: each of the three
#           writers refused. Every login role: the store refused. Each call is
#           inside its own rolled-back transaction.
#        d. residue: nothing any of it wrote survives.
#   5  restart ONLY the two application containers; the three new routes
#      answer an anonymous POST with 401 (routed and guarded: a missing route
#      answers 404, an unbound one 501); the new panel file is served, and the
#      sign-in gate fix is live; loopback and hostname; no other container
#      changed.
#   6  result.
#
# It touches no production container, no Traefik file, no DNS record, no
# firewall rule and no PostgreSQL instance but dnb-staging-postgres. Nothing
# real is contacted: DN_ALLOW_REAL_BINDINGS stays absent and delivery stays
# simulated. The API keeps the staff identity it runs with (docs/122). It
# prints no secret.
#
# The version name is still 0.1.0-rc1: compare the CONTENT DIGEST, never the
# name (docs/96). Deployed since 2026-09-24 04:14 UTC: 780023ff…; this build:
# 4a629184….
set -eu

EV=/root/dnb-staging-evidence
APP=/opt/dnb-staging
IMG=dnb-staging-php:8.3
# The source may be overridden only for the local rehearsal (scripts/harness/
# redeploy). It cannot change what is deployed: the digest below decides.
REPO=${DNB_REDEPLOY_REPO:-https://github.com/dishnetafrica/dishnetuganda}
BRANCH=claude/study-this-jhe2eg
# The reviewed commit on that branch (docs/125 §E). Fetched by its hash, never as
# "the branch tip": the branch moves on, this build does not.
COMMIT=46c778e6efd559b9faf968811760d914ceb8d3cc
CP=dishnet-hybrid-sudan/dishnet-mikrotik-control-plane
HOST=portal-staging.dishnetuganda.com
# Content digest of the reviewed build = sha256 of the archive's SHA256SUMS (docs/125 §D.2).
DIGEST=4a6291849f0b687d2472909dd1e416af837227dcf648fd41fb99fc5c7a5fd571
MIG=030_admin_operator_onboarding.sql
PREV_MIG=029_o1_site_service_same_operator.sql
FK=mt_sites_service_customer_fkey
UQ=mt_services_id_customer_key
WRITERS="mt_admin_operator_create mt_admin_service_create mt_admin_site_create"
TS=$(date -u +%Y%m%dT%H%M%SZ)
RO="-c default_transaction_read_only=on"
NIL=00000000-0000-0000-0000-000000000000

fail() { echo; echo "STOP: $*" >&2; exit 1; }
step() { echo; echo "== $* =="; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@" || true; }
# sq <sql>: one read-only answer from the staging instance's catalogue.
sq() { docker exec -e PGOPTIONS="$RO" dnb-staging-postgres psql -U postgres -d dnb -Atc "$1"; }
# as <role>: psql on the staging database as that role, reading SQL from stdin.
as() { docker exec -i dnb-staging-postgres psql -X -A -t -w -U "$1" -d dnb 2>&1 || true; }
# o1: 029's key validated | its UNIQUE | the two single-column keys | FORCE on both.
o1() {
  sq "SELECT (SELECT count(*) FROM pg_constraint WHERE conname = '$FK' AND conrelid = 'public.mt_sites'::regclass AND contype = 'f' AND convalidated)
          || '|' || (SELECT count(*) FROM pg_constraint WHERE conname = '$UQ' AND conrelid = 'public.mt_services'::regclass AND contype = 'u')
          || '|' || (SELECT count(*) FROM pg_constraint WHERE conrelid = 'public.mt_sites'::regclass AND conname IN ('mt_sites_customer_id_fkey','mt_sites_service_id_fkey'))
          || '|' || (SELECT bool_and(relrowsecurity AND relforcerowsecurity) FROM pg_class WHERE oid IN ('public.mt_sites'::regclass, 'public.mt_services'::regclass))"
}

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
case "$before_n|$before_last" in
  "29|$PREV_MIG") echo "ok: migration 029 is applied (docs/124 §H); 030 is the one pending" ;;
  "30|$MIG")      echo "NOTE: migration 030 is already applied; the run continues and re-verifies" ;;
  *) fail "this command applies 030 on top of 029 and nothing else, and the ledger reads $before_n migration(s), last $before_last. If 029 is not applied, its own command comes first, because 029 needs the census re-taken (docs/124 §E). Nothing was changed" ;;
esac
k0=$(o1)
echo "O-1 before (validated key | supporting UNIQUE | single-column keys kept | FORCE on both): $k0"
[ "$k0" = "1|1|2|true" ] || fail "migration 029's key is not in place ($k0; expected 1|1|2|true) — nothing was changed; paste this output back"
[ "$(code http://127.0.0.1:8099/)" = 200 ] || fail "the panel does not answer 200 on 127.0.0.1:8099"
echo "ok: stage 1 is healthy"

step "1/6 build the artifact on this server from commit ${COMMIT%${COMMIT#????????????}} of $BRANCH and check its content digest"
SRC="$EV/src-$TS"
install -d "$SRC"
if command -v git >/dev/null 2>&1; then
  git init -q "$SRC"
  git -C "$SRC" fetch -q --depth 1 "$REPO.git" "$COMMIT"
  git -C "$SRC" checkout -q FETCH_HEAD
  head=$(git -C "$SRC" rev-parse HEAD)
  [ "$head" = "$COMMIT" ] || fail "fetched $head, not the reviewed commit $COMMIT — nothing was changed"
  echo "$head" | tee "$EV/redeploy-$TS.commit"
else
  curl -fsSL "https://codeload.github.com/dishnetafrica/dishnetuganda/tar.gz/$COMMIT" \
    | tar -xz -C "$SRC" --strip-components=1
  echo "tarball of commit $COMMIT (no git on this host)" | tee "$EV/redeploy-$TS.commit"
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
[ -f "$NEW/migrations/$MIG" ] && [ -f "$NEW/panel/onboarding.js" ] || { rm -rf "$NEW"; fail "the build carries no migration 030 or no onboarding client — wrong build"; }

step "2/6 read-only: the new build's doctor must report no blocker"
RUN="docker run --rm --network dnb-staging -v $APP/env:/run/dnb -w /app --env-file $APP/env/runtime.env --env-file $APP/env/install.env --env-file $APP/env/secrets.docker.env"
if [ "$MODE" = real ]; then
  DOCTOR="-e DN_STAFF_IDENTITY=dishnet -e DN_TRUSTED_PROXY=$TRUSTED -e DN_PORTAL_ORIGIN=$ORIGIN"; POSTURE="production posture"
else
  DOCTOR=""; POSTURE="--disposable (the development identity is a WARN there, and the truth of that posture)"
fi
# shellcheck disable=SC2086
doc0=$($RUN -v "$NEW:/app:ro" $DOCTOR "$IMG" php plugin/bin/plugin.php doctor $([ "$MODE" = dev ] && echo --disposable) 2>&1) || true
echo "$doc0" | grep -E 'checks:|^  warn |BLOCK' | sed 's/^/  /'
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

step "4/6 verify independently, afterwards"
# a. the catalogue
store=$(sq "SELECT pg_get_userbyid(relowner) || ', row security ' || CASE WHEN relrowsecurity THEN 'on' ELSE 'off' END FROM pg_class WHERE oid = 'public.mt_admin_idempotency'::regclass")
echo "the store mt_admin_idempotency: owner $store"
[ "$store" = "dnb_def_prov, row security off" ] || fail "the store is not what migration 030 promises ('$store')"
wr=$(sq "SELECT count(*) FILTER (WHERE p.prosecdef AND pg_get_userbyid(p.proowner) = 'dnb_def_prov')
          || '|' || (SELECT count(*) FROM pg_proc q, aclexplode(coalesce(q.proacl, acldefault('f', q.proowner))) a WHERE q.pronamespace = 'public'::regnamespace
                       AND q.proname IN ('mt_admin_operator_create','mt_admin_service_create','mt_admin_site_create')
                       AND a.privilege_type = 'EXECUTE' AND a.grantee = 'dnb_adminwrite'::regrole::oid)
          || '|' || (SELECT count(*) FROM pg_proc q, aclexplode(coalesce(q.proacl, acldefault('f', q.proowner))) a WHERE q.pronamespace = 'public'::regnamespace
                       AND q.proname IN ('mt_admin_operator_create','mt_admin_service_create','mt_admin_site_create')
                       AND a.privilege_type = 'EXECUTE' AND a.grantee NOT IN ('dnb_adminwrite'::regrole::oid, q.proowner))
        FROM pg_proc p WHERE p.pronamespace = 'public'::regnamespace
         AND p.proname IN ('mt_admin_operator_create','mt_admin_service_create','mt_admin_site_create')")
echo "writers (SECURITY DEFINER owned by dnb_def_prov | EXECUTE granted to dnb_adminwrite | granted to anyone else): $wr"
[ "$wr" = "3|3|0" ] || fail "the three writers are not what migration 030 promises ($wr; expected 3|3|0)"
hp=$(sq "SELECT count(*) FILTER (WHERE NOT p.prosecdef AND pg_get_userbyid(p.proowner) = 'dnb_def_prov')
          || '|' || (SELECT count(*) FROM pg_proc q, aclexplode(coalesce(q.proacl, acldefault('f', q.proowner))) a WHERE q.pronamespace = 'public'::regnamespace
                       AND q.proname IN ('mt_admin_idem_seen','mt_admin_idem_claim') AND a.grantee <> q.proowner)
        FROM pg_proc p WHERE p.pronamespace = 'public'::regnamespace AND p.proname IN ('mt_admin_idem_seen','mt_admin_idem_claim')")
echo "helpers (running with their caller's privileges, owned by dnb_def_prov | granted to anyone but their owner): $hp"
[ "$hp" = "2|0" ] || fail "the two helpers are not what migration 030 promises ($hp; expected 2|0)"
args=$(sq "SELECT pg_get_function_identity_arguments(p.oid) FROM pg_proc p WHERE p.pronamespace = 'public'::regnamespace AND p.proname = 'mt_admin_site_create'")
echo "the location writer takes: $args"
echo "$args" | grep -qiE 'operator|customer' && fail "the location writer takes an operator — it must derive it (docs/105)"
k1=$(o1)
echo "O-1 after (validated key | supporting UNIQUE | single-column keys kept | FORCE on both): $k1"
[ "$k1" = "1|1|2|true" ] || fail "migration 029's key does not hold after 030 ($k1)"
proj=$(docker exec -e PGOPTIONS="$RO" dnb-staging-postgres psql -X -A -t -w -U dnb_adminapi -d dnb -c \
  "SELECT count(*) || '|' || count(*) FILTER (WHERE s.customer_id <> v.customer_id) FROM mt_admin_sites() s LEFT JOIN mt_admin_services() v ON v.id = s.service_id")
echo "through the Admin projections as dnb_adminapi (sites seen | sites whose service is another operator's): $proj"
echo "$proj" | grep -qE '^[1-9][0-9]*[|]0$' || fail "the projection check did not see sites with none crossing (got '$proj')"

# b. the writers, by execution, as the only role that may call them — rolled back.
probe=$(as dnb_adminwrite <<SQL
BEGIN;
SELECT 'who|' || current_user;
SELECT r ->> 'replayed' AS op_rep, r -> 'customer' ->> 'id' AS op_id
  FROM (SELECT mt_admin_operator_create('O30-PROBE operator (rolled back)', 'o30-probe-op-$TS', 'redeploy-probe') r) x \gset
SELECT 'op|' || :'op_rep' || '|' || :'op_id';
SELECT 'op-replay|' || (r ->> 'replayed') || '|' || (r -> 'customer' ->> 'id')
  FROM (SELECT mt_admin_operator_create('O30-PROBE operator (rolled back)', 'o30-probe-op-$TS', 'redeploy-probe') r) x;
SAVEPOINT reuse;
SELECT 'op-reuse|' || (mt_admin_operator_create('O30-PROBE another name (rolled back)', 'o30-probe-op-$TS', 'redeploy-probe') ->> 'replayed');
ROLLBACK TO SAVEPOINT reuse;
SELECT r -> 'service' ->> 'id' AS svc_id, 'svc|' || (r ->> 'replayed') || '|' || (r -> 'service' ->> 'customer_id') || '|' || (r -> 'service' ->> 'id') AS svc_line
  FROM (SELECT mt_admin_service_create(:'op_id', 'o30-probe-svc-$TS', 'redeploy-probe') r) x \gset
SELECT :'svc_line';
SELECT 'site|' || (r ->> 'replayed') || '|' || (r -> 'site' ->> 'customer_id') || '|' || (r -> 'site' ->> 'service_id')
  FROM (SELECT mt_admin_site_create(:'svc_id', 'O30-PROBE location (rolled back)', NULL, 'o30-probe-site-$TS', 'redeploy-probe') r) x;
ROLLBACK; -- the execution test keeps nothing
SQL
)
echo "$probe" | sed 's/^/  /'
echo "$probe" | grep -q '^who|dnb_adminwrite$' || fail "the execution test did not run as dnb_adminwrite"
OP=$(echo "$probe" | sed -n 's/^op|false|\([0-9a-f-]\{36\}\)$/\1/p')
[ -n "$OP" ] || fail "CONTROL failed: dnb_adminwrite could not create an operator through mt_admin_operator_create"
SVC=$(echo "$probe" | sed -n "s/^svc|false|$OP|\([0-9a-f-]\{36\}\)\$/\1/p")
echo "$probe" | grep -q "^op-replay|true|$OP\$" || fail "a replay of the same request was not answered with the same operator"
echo "$probe" | grep -q 'this idempotency key was already used for a different request' || fail "the key reused for another name was not refused"
echo "$probe" | grep -q '^op-reuse|' && fail "the key reused for another name returned a result"
[ -n "$SVC" ] || fail "the service was not started for the operator named"
echo "$probe" | grep -q "^site|false|$OP|$SVC\$" || fail "the location's operator was not derived from its service"
echo "execution test (rolled back): dnb_adminwrite created an operator, a replay returned the same one, the key reused for another name was refused, the service started for it, and the location's operator was derived from the service"

# c. every other login role is refused each writer; every login role is refused the store.
ROLES=$(sq "SELECT string_agg(rolname, ' ' ORDER BY rolname) FROM pg_roles WHERE rolcanlogin AND NOT rolsuper")
nroles=0
for r in $ROLES; do
  if [ "$r" = dnb_adminwrite ]; then
    out=$(printf '%s\n' "BEGIN;" "SELECT 'store|' || count(*) FROM mt_admin_idempotency;" "ROLLBACK;" | as "$r")
    want_w=0
  else
    out=$(as "$r" <<SQL
BEGIN;
SELECT 'store|' || count(*) FROM mt_admin_idempotency;
ROLLBACK;
BEGIN;
SELECT 'w|' || (mt_admin_operator_create('O30-DENY (rolled back)', 'o30-deny-$TS', 'redeploy-probe') ->> 'replayed');
ROLLBACK;
BEGIN;
SELECT 'w|' || (mt_admin_service_create('$NIL', 'o30-deny-$TS', 'redeploy-probe') ->> 'replayed');
ROLLBACK;
BEGIN;
SELECT 'w|' || (mt_admin_site_create('$NIL', 'O30-DENY (rolled back)', NULL, 'o30-deny-$TS', 'redeploy-probe') ->> 'replayed');
ROLLBACK;
SQL
)
    want_w=3
  fi
  echo "$out" | grep -qE 'FATAL|could not connect|connection to server' && fail "cannot connect as $r, so the refusal test cannot be taken for it — paste this output back"
  st=$(echo "$out" | grep -c 'permission denied for table mt_admin_idempotency' || true)
  wd=$(echo "$out" | grep -cE 'permission denied for function mt_admin_(operator|service|site)_create' || true)
  # Refused means: the refusal was seen AND no answer came back.
  s_ok=no; [ "$st" = 1 ] && ! echo "$out" | grep -q '^store|' && s_ok=yes
  w_ok=no; [ "$wd" = "$want_w" ] && ! echo "$out" | grep -q '^w|' && w_ok=yes
  echo "  $r: store refused $st of 1; writers refused $wd of $want_w"
  [ "$s_ok" = yes ] || fail "$r was NOT refused the store mt_admin_idempotency"
  [ "$w_ok" = yes ] || fail "$r was NOT refused every onboarding writer ($wd of $want_w refused)"
  nroles=$((nroles + 1))
done
[ "$nroles" -ge 2 ] || fail "too few login roles to test ($nroles)"
echo "refusals, by execution: $nroles login role(s) — every one refused the store, and every one but dnb_adminwrite refused all three writers"
echo "store rows, read as the instance superuser (the positive control): $(sq "SELECT count(*) FROM mt_admin_idempotency")"

# d. residue: every writer act writes exactly one audit row naming its actor.
left=$(sq "SELECT (SELECT count(*) FROM mt_audit_log WHERE actor = 'redeploy-probe')
           + (SELECT count(*) FROM mt_customers WHERE name LIKE 'O30-%')
           + (SELECT count(*) FROM mt_sites WHERE name LIKE 'O30-%')
           + (SELECT count(*) FROM mt_admin_idempotency WHERE key LIKE 'o30-%-$TS')")
[ "$left" = 0 ] || fail "the execution tests left $left row(s) behind"
echo "residue: 0 (no audit row by the probe actor, no probe operator, location or key)"

step "5/6 restart the two application containers — and nothing else"
docker restart dnb-staging-api dnb-staging-worker >/dev/null
i=0; until [ "$(code http://127.0.0.1:8099/)" = 200 ]; do i=$((i+1)); [ $i -le 30 ] || break; sleep 1; done
B=$(mktemp "$EV/body.XXXXXX")
c_panel=$(code http://127.0.0.1:8099/)
c_js=$(code http://127.0.0.1:8099/routers.js)
c_on=$(code http://127.0.0.1:8099/onboarding.js)
gate=$(curl -s http://127.0.0.1:8099/ | grep -c '#gate\[hidden\]' || true)
c_sess=$(curl -s -o "$B" -w '%{http_code}' http://127.0.0.1:8099/api/v1/admin/session || true)
echo "loopback: panel $c_panel, routers.js $c_js, onboarding.js $c_on, sign-in gate fix in the page: $gate, GET /api/v1/admin/session $c_sess $(cat "$B")"
[ "$c_panel" = 200 ] && [ "$c_js" = 200 ] && [ "$c_on" = 200 ] && [ "$c_sess" = 401 ] || fail "the API did not come back as expected"
[ "$gate" -ge 1 ] || fail "the page served is not the new build's (no sign-in gate fix)"
if [ "$MODE" = real ]; then
  grep -q '"provider":"dishnet"' "$B" || fail "after the restart the API no longer names the dishnet provider"
fi
anon() { curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' --data '{}' "http://127.0.0.1:8099/api/v1/admin/$1" || true; }
p1=$(anon customers); p2=$(anon "customers/$NIL/services"); p3=$(anon sites)
echo "anonymous POST (401 = routed and guarded): /customers $p1, /customers/{id}/services $p2, /sites $p3"
[ "$p1|$p2|$p3" = "401|401|401" ] || fail "the three onboarding routes do not all answer 401 to an anonymous POST"
if [ "$MODE" = real ]; then
  p_root=$(code -k --resolve "$HOST:443:127.0.0.1" "https://$HOST/")
  p_on=$(code -k --resolve "$HOST:443:127.0.0.1" "https://$HOST/onboarding.js")
  p_sess=$(curl -sk -o "$B" -w '%{http_code}' --resolve "$HOST:443:127.0.0.1" "https://$HOST/api/v1/admin/session" || true)
  echo "through Traefik: / $p_root, onboarding.js $p_on, GET /api/v1/admin/session $p_sess $(cat "$B")"
  [ "$p_root" = 200 ] && [ "$p_on" = 200 ] && [ "$p_sess" = 401 ] && grep -q '"provider":"dishnet"' "$B" \
    || echo "WARN: through Traefik expected 200, 200 and a 401 naming the dishnet provider — check the route (docs/122)"
else
  p_js=$(code -k --resolve "$HOST:443:127.0.0.1" "https://$HOST/onboarding.js")
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
echo "=== REDEPLOYED $(date -u +%FT%TZ): $(cat "$APP/app/VERSION"), content digest $DIGEST, migrations $after_n (last $after_last); onboarding: writers on dnb_adminwrite only and the store closed, refused by execution for $nroles login roles; replay and derived operator proved in a rolled-back test, residue 0; three routes bound and guarded; O-1 holds ==="
echo "Previous tree kept at $APP/app.prev-$TS. Rollback of the CODE, if ever needed:"
echo "  mv $APP/app $APP/app.failed-$TS && mv $APP/app.prev-$TS $APP/app && docker restart dnb-staging-api dnb-staging-worker"
echo "  (migration 030 adds a table, five functions and row policies that the previous build never calls, so it needs no undoing)"
echo "Paste this whole output back; leave nothing out."
