#!/bin/sh
# DishNet Domain-B staging — SMS settings from the Admin panel: migration 033
# (docs/128). One command, run as root on the server:
#
#   curl -fsSL -o /root/dnb-sms-settings.sh https://raw.githubusercontent.com/dishnetafrica/dishnetuganda/claude/study-this-jhe2eg/scripts/dnb-staging-sms-settings.sh \
#     && sh /root/dnb-sms-settings.sh 2>&1 | tee /root/dnb-staging-evidence/sms-settings-$(date -u +%Y%m%dT%H%M%SZ).log
#
#   0  read-only checks. Nothing is changed by any refusal here.
#   1  build the artifact ON THIS SERVER from the reviewed commit, fetched by
#      its hash; refuse unless its content digest is the reviewed one.
#   2  read-only: the new build's doctor, in production posture, must report
#      no blocker.
#   3  swap /opt/dnb-staging/app (the previous tree is kept) and apply exactly
#      033 with the installer. 033 is one transaction: a refusal keeps nothing
#      of it, and the previous tree is put back.
#   4  verify INDEPENDENTLY, afterwards — the catalogue; then, in ONE
#      transaction that is ALWAYS rolled back, a setting saved as
#      dnb_adminwrite with one audit row, the same save again changing nothing
#      and writing no second row, a new username without its key refused, the
#      worker's read and the Admin read; then every login role refused the
#      table and every function but its own.
#   5  restart the API, the worker and the operator app on the new build. They
#      are RESTARTED, not recreated, so no environment changes. Read the
#      worker's SMS mode off its log and off its report in the database.
#   6  result.
#
# It asks for no SMS setting and handles no secret: the Africa's Talking
# account is entered later, in the Admin panel (Administration → SMS for
# sign-in). It touches no production container, no DNS record, no firewall rule,
# no Traefik file, and no PostgreSQL instance but dnb-staging-postgres.
# DN_ALLOW_REAL_BINDINGS stays absent and router delivery stays simulated.
#
# The version name is still 0.1.0-rc1: compare the CONTENT DIGEST, never the
# name (docs/96). Deployed since 2026-09-25 05:31 UTC: 2874d643…; this build:
# 2af800b7….
set -eu

EV=/root/dnb-staging-evidence
APP=/opt/dnb-staging
IMG=dnb-staging-php:8.3
# The source may be overridden only for the local rehearsal (scripts/harness/
# sms-settings). It cannot change what is deployed: the digest below decides.
REPO=${DNB_REDEPLOY_REPO:-https://github.com/dishnetafrica/dishnetuganda}
BRANCH=claude/study-this-jhe2eg
# The reviewed commit on that branch (docs/128 §F), fetched by its hash.
COMMIT=8d40936005d44f18cd6307267ff08e808048635c
CP=dishnet-hybrid-sudan/dishnet-mikrotik-control-plane
# Content digest of the reviewed build = sha256 of the archive's SHA256SUMS.
DIGEST=2af800b7748fea0e7f11f17267c25e7283da964ca2997d39d696ae8d9c5a5c2d
# The build the operator-app command deployed (docs/127 §L.2).
PREV_DIGEST=2874d64346b927e6ec2da77ceecb19714a623030ea7e89b74590d7bc6640a0c4
PORTAL=portal-staging.dishnetuganda.com
APPHOST=app-staging.dishnetuganda.com
TCFG=/etc/easypanel/traefik/config
ADMIN_ROUTE=$TCFG/dnb-staging.yml
APP_ROUTE=$TCFG/dnb-staging-app.yml
GW=172.17.0.1
PORT=8098
MIG=033_sms_settings_from_the_admin_panel.sql
PREV_MIG=032_sign_in_codes_by_sms.sql
FK=mt_sites_service_customer_fkey
UQ=mt_services_id_customer_key
CSP="default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; manifest-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'"
# The execution test's values: an actor no person has, an account no one owns,
# and an envelope that opens under no key. Nothing of them is ever committed.
PROBE_ACTOR=staging-probe-033
PROBE_USER=staging-probe
PROBE_SEALED=v1.cHJvYmU=
PROBE_FP=$(printf '%s' 'dnb-staging-probe-033' | sha256sum | cut -d' ' -f1)
TS=$(date -u +%Y%m%dT%H%M%SZ)
RO="-c default_transaction_read_only=on"
EMPTY_SHA=$(printf '' | sha256sum | cut -d' ' -f1)

fail() { echo; echo "STOP: $*" >&2; echo "Send back the log file, not the terminal: cat \"\$(ls -t $EV/sms-settings-*.log | head -1)\"" >&2; exit 1; }
step() { echo; echo "== $* =="; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@" 2>/dev/null || true; }
# sq <sql>: one read-only answer from the staging instance, as its superuser.
sq() { docker exec -e PGOPTIONS="$RO" dnb-staging-postgres psql -U postgres -d dnb -Atc "$1"; }
# as <role>: psql on the staging database as that role, reading SQL from stdin.
as() { docker exec -i dnb-staging-postgres psql -X -A -t -w -U "$1" -d dnb 2>&1 || true; }
o1() {
  sq "SELECT (SELECT count(*) FROM pg_constraint WHERE conname = '$FK' AND conrelid = 'public.mt_sites'::regclass AND contype = 'f' AND convalidated)
          || '|' || (SELECT count(*) FROM pg_constraint WHERE conname = '$UQ' AND conrelid = 'public.mt_services'::regclass AND contype = 'u')
          || '|' || (SELECT count(*) FROM pg_constraint WHERE conrelid = 'public.mt_sites'::regclass AND conname IN ('mt_sites_customer_id_fkey','mt_sites_service_id_fkey'))
          || '|' || (SELECT bool_and(relrowsecurity AND relforcerowsecurity) FROM pg_class WHERE oid IN ('public.mt_sites'::regclass, 'public.mt_services'::regclass))"
}
# hdr <header> <curl args…>: one response header's value, from a GET.
hdr() { h=$1; shift; curl -s -D - -o /dev/null "$@" 2>/dev/null | tr -d '\r' | grep -i "^$h:" | head -1 | cut -d: -f2- | sed 's/^ //' || true; }
envnames() { docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$1" 2>/dev/null | sed -n 's/^\([A-Za-z_][A-Za-z0-9_]*\)=.*/\1/p' | sort; }
# envhash <container> <name>: the sha256 of one variable's value. The value goes
# through a pipe and is never printed or put on a command line.
envhash() { docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$1" 2>/dev/null | sed -n "s/^$2=//p" | head -1 | tr -d '\n' | sha256sum | cut -d' ' -f1; }
settings_row() { sq "SELECT provider || '|' || coalesce(username, '-') || '|' || coalesce(sender, '-') || '|' || (key_sealed IS NOT NULL)::text || '|' || version || '|' || coalesce(updated_at::text, '-') || '|' || coalesce(updated_by, '-') FROM mt_sms_settings"; }

[ "$(id -u)" = 0 ] || fail "run as root"
for t in docker curl tar sha256sum sed grep awk comm ss; do
  command -v "$t" >/dev/null 2>&1 || fail "missing tool: $t"
done
install -d -m 0700 "$EV"
date -u '+%Y-%m-%d %H:%M:%S UTC'; hostname

step "0/6 read-only checks (nothing is changed in this step)"
for c in dnb-staging-postgres dnb-staging-api dnb-staging-worker dnb-staging-app; do
  [ "$(docker inspect -f '{{.State.Running}}' "$c" 2>/dev/null || true)" = true ] \
    || fail "$c is not running — the operator app (docs/127 §L.2) is not in place"
done
[ -d "$APP/app" ] || fail "$APP/app is missing"
for f in runtime.env install.env secrets.docker.env app.env; do
  [ -f "$APP/env/$f" ] || fail "$APP/env/$f is missing — not the layout the operator-app command left"
done
docker image inspect "$IMG" >/dev/null 2>&1 || fail "image $IMG is missing"
for d in "$APP"/app.new-*; do [ -e "$d" ] && fail "$d exists — an earlier run stopped mid-way; paste its log back first"; done
cur=$(sha256sum "$APP/app/SHA256SUMS" | cut -d' ' -f1)
echo "deployed content digest: $cur"
case "$cur" in
  "$PREV_DIGEST") echo "ok: the operator app's build (docs/127 §L.2) is deployed" ;;
  "$DIGEST")      echo "NOTE: the reviewed build is already deployed; the run continues and re-verifies" ;;
  *) fail "the deployed build is neither the operator app's ($PREV_DIGEST) nor this one — not a state this command knows. Nothing was changed" ;;
esac
for c in dnb-staging-api dnb-staging-worker dnb-staging-app; do
  n=$(envnames "$c") || fail "cannot read the environment of $c"
  echo "$n" | grep -qx DN_ALLOW_REAL_BINDINGS && fail "DN_ALLOW_REAL_BINDINGS is set on $c — F6-B is NOT authorized; refusing"
  echo "$n" | grep -qx DNB_EXPOSE_OTP && fail "DNB_EXPOSE_OTP is set on $c — a sign-in code must never be shown; refusing"
done
api_env=$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' dnb-staging-api) || fail "cannot read the API's environment"
api_get() { echo "$api_env" | grep "^$1=" | head -1 | cut -d= -f2-; }
[ "$(api_get DN_STAFF_IDENTITY)" = dishnet ] && [ -z "$(api_get DN_DEV_STAFF_IDENTITY)" ] \
  || fail "the API does not run the real DishNet staff login alone (docs/122) — the SMS page is bound only under it; nothing was changed"
TRUSTED=$(api_get DN_TRUSTED_PROXY); ORIGIN=$(api_get DN_PORTAL_ORIGIN)
echo "$TRUSTED" | grep -qE '^[0-9]{1,3}(\.[0-9]{1,3}){3}$' || fail "DN_TRUSTED_PROXY on the API is not one IPv4 address: '$TRUSTED'"
[ "$ORIGIN" = "https://$PORTAL" ] || fail "DN_PORTAL_ORIGIN on the API is '$ORIGIN', not https://$PORTAL"
echo "API identity: the real DishNet staff login (docs/122) — trusted proxy $TRUSTED, origin $ORIGIN"
wenv=$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' dnb-staging-worker) || fail "cannot read the worker's environment"
[ "$(echo "$wenv" | grep -c '^DN_DELIVERY=simulated$' || true)" = 1 ] \
  || fail "the worker does not run DN_DELIVERY=simulated — not a state this script knows"
WSMS=$(echo "$wenv" | sed -n 's/^DN_SMS=//p' | head -1)
if [ -z "$WSMS" ]; then
  SMS_MODE=panel
  echo "worker: DN_DELIVERY=simulated; DN_SMS is not set, so on the new build it follows the Admin panel's SMS settings"
else
  SMS_MODE=env
  [ -f "$APP/env/sms.env" ] || fail "the worker has DN_SMS=$WSMS but $APP/env/sms.env is missing — not a state this command knows"
  echo "worker: DN_DELIVERY=simulated; DN_SMS=$WSMS is set in its environment and wins — the Admin panel's SMS page will say so (docs/128 SS-9)"
fi
for c in dnb-staging-api dnb-staging-worker dnb-staging-app; do
  [ "$(envhash "$c" DNB_SECRET_KEY)" != "$EMPTY_SHA" ] || fail "$c holds no DNB_SECRET_KEY"
done
[ "$(envhash dnb-staging-api DNB_SECRET_KEY)" = "$(envhash dnb-staging-worker DNB_SECRET_KEY)" ] \
  && [ "$(envhash dnb-staging-app DNB_SECRET_KEY)" = "$(envhash dnb-staging-worker DNB_SECRET_KEY)" ] \
  || fail "the API, the worker and the app do not hold the same DNB_SECRET_KEY: a key saved in the panel would not open in the worker. Nothing was changed"
echo "DNB_SECRET_KEY: set, and the same in the API, the worker and the app (values not shown) — the API seals, the worker opens"
before_n=$(sq "SELECT count(*) FROM mt_migrations")
before_last=$(sq "SELECT max(filename) FROM mt_migrations")
echo "migrations before: $before_n (last $before_last)"
case "$before_n|$before_last" in
  "32|$PREV_MIG") echo "ok: 031 and 032 are applied (docs/127 §L.2); 033 is the one pending" ;;
  "33|$MIG")      echo "NOTE: 033 is already applied; the run continues and re-verifies" ;;
  *) fail "this command applies 033 on top of 032 and nothing else, and the ledger reads $before_n migration(s), last $before_last. Nothing was changed" ;;
esac
k0=$(o1)
echo "O-1 (validated key | supporting UNIQUE | single-column keys kept | FORCE on both): $k0"
[ "$k0" = "1|1|2|true" ] || fail "migration 029's key is not in place ($k0) — nothing was changed; paste this output back"
[ "$(code http://127.0.0.1:8099/)" = 200 ] || fail "the panel does not answer 200 on 127.0.0.1:8099"
[ "$(code "http://127.0.0.1:$PORT/")" = 200 ] || fail "the operator app does not answer 200 on 127.0.0.1:$PORT"
for f in "$ADMIN_ROUTE" "$APP_ROUTE"; do [ -f "$f" ] || fail "$f is missing — the Traefik routes are not as the operator-app command left them"; done
R_ADMIN=$(sha256sum "$ADMIN_ROUTE" | cut -d' ' -f1); R_APP=$(sha256sum "$APP_ROUTE" | cut -d' ' -f1)
T=$(docker ps --format '{{.Names}}' | grep -i traefik | head -1 || true); [ -n "$T" ] || fail "no Traefik container is running"
T_STARTED=$(docker inspect -f '{{.State.StartedAt}}' "$T")
[ "$(code -k --resolve "$PORTAL:443:127.0.0.1" "https://$PORTAL/")" = 200 ] || fail "https://$PORTAL/ does not answer through Traefik"
[ "$(code -k --resolve "$APPHOST:443:127.0.0.1" "https://$APPHOST/")" = 200 ] || fail "https://$APPHOST/ does not answer through Traefik"
WN_BEFORE=$(envnames dnb-staging-worker); AN_BEFORE=$(envnames dnb-staging-api); PN_BEFORE=$(envnames dnb-staging-app)
docker inspect -f '{{.Name}}|{{.State.Status}}|{{.State.StartedAt}}|{{.RestartCount}}' $(docker ps -aq) | sort > "$EV/sms-settings-$TS.before"
echo "ok: the operator app's state is in place; nothing was changed"

step "1/6 build the artifact on this server from commit ${COMMIT%${COMMIT#????????????}} of $BRANCH and check its content digest"
SRC="$EV/src-$TS"
install -d "$SRC"
if command -v git >/dev/null 2>&1; then
  git init -q "$SRC"
  git -C "$SRC" fetch -q --depth 1 "$REPO.git" "$COMMIT"
  git -C "$SRC" checkout -q FETCH_HEAD
  head=$(git -C "$SRC" rev-parse HEAD)
  [ "$head" = "$COMMIT" ] || fail "fetched $head, not the reviewed commit $COMMIT — nothing was changed"
  echo "$head" | tee "$EV/sms-settings-$TS.commit"
else
  curl -fsSL "https://codeload.github.com/dishnetafrica/dishnetuganda/tar.gz/$COMMIT" | tar -xz -C "$SRC" --strip-components=1
  echo "tarball of commit $COMMIT (no git on this host)" | tee "$EV/sms-settings-$TS.commit"
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
for f in "migrations/$MIG" panel/settings.js src/Notify/PanelSms.php src/Admin/SettingsAdmin.php; do
  [ -f "$NEW/$f" ] || { rm -rf "$NEW"; fail "the build carries no $f — wrong build"; }
done

step "2/6 read-only: the new build's doctor, in production posture, must report no blocker"
RUN="docker run --rm --network dnb-staging -v $APP/env:/run/dnb -w /app --env-file $APP/env/runtime.env --env-file $APP/env/install.env --env-file $APP/env/secrets.docker.env"
DOCTOR="-e DN_STAFF_IDENTITY=dishnet -e DN_TRUSTED_PROXY=$TRUSTED -e DN_PORTAL_ORIGIN=$ORIGIN"
[ "$SMS_MODE" = env ] && DOCTOR="$DOCTOR --env-file $APP/env/sms.env"
# shellcheck disable=SC2086
doc0=$($RUN -v "$NEW:/app:ro" $DOCTOR "$IMG" php plugin/bin/plugin.php doctor 2>&1) || true
echo "$doc0" | grep -E 'checks:|^  warn |^  skip |BLOCK|by SMS' | sed 's/^/  /'
echo "$doc0" | grep -qE '[0-9]+ checks: .*, 0 blocker' || { rm -rf "$NEW"; fail "the new build's doctor (production posture) reports a blocker — nothing was changed; paste this output back"; }
echo "doctor (production posture): no blocker"

step "3/6 swap the application tree and apply migration 033"
mv "$APP/app" "$APP/app.prev-$TS"
mv "$NEW" "$APP/app"
echo "swapped: $APP/app is the new build; previous tree at $APP/app.prev-$TS"
if ! $RUN -v "$APP/app:/app:ro" "$IMG" php plugin/bin/plugin.php install > "$EV/sms-settings-$TS.install.txt" 2>&1; then
  sed 's/^/  /' "$EV/sms-settings-$TS.install.txt"
  mv "$APP/app" "$APP/app.refused-$TS" && mv "$APP/app.prev-$TS" "$APP/app"
  echo "migrations now: $(sq "SELECT count(*) FROM mt_migrations") (last $(sq "SELECT max(filename) FROM mt_migrations")) — 033 is one transaction, so a refusal keeps nothing of it"
  fail "the installer refused (its lines are above). The previous tree is back at $APP/app, the refused build is at $APP/app.refused-$TS, and no container was restarted. Paste this output back"
fi
sed 's/^/  /' "$EV/sms-settings-$TS.install.txt"
after_n=$(sq "SELECT count(*) FROM mt_migrations")
after_last=$(sq "SELECT max(filename) FROM mt_migrations")
want=$(ls "$APP/app/migrations"/*.sql | wc -l | tr -d ' ')
echo "migrations after: $after_n (last $after_last); migration files in the build: $want"
[ "$after_n" = "$want" ] || fail "the ledger ($after_n) does not match the build's migration files ($want)"
[ "$after_last" = "$MIG" ] || fail "the latest applied migration is $after_last, not $MIG"

step "4/6 verify independently, afterwards"
# a. the catalogue
tb=$(sq "SELECT pg_get_userbyid(relowner) || '|' || relrowsecurity::text FROM pg_class WHERE oid = 'public.mt_sms_settings'::regclass")
acl=$(sq "SELECT coalesce(string_agg(DISTINCT CASE WHEN a.grantee = 0 THEN 'PUBLIC' ELSE pg_get_userbyid(a.grantee) END, ','), 'none')
            FROM pg_class c, aclexplode(coalesce(c.relacl, acldefault('r', c.relowner))) a
           WHERE c.oid = 'public.mt_sms_settings'::regclass AND a.grantee <> c.relowner")
nrow=$(sq "SELECT count(*) FROM mt_sms_settings")
echo "the settings store mt_sms_settings: owner|row security $tb; privileges held by anyone but its owner: $acl; rows: $nrow"
[ "$tb" = "dnb_def_auth|false" ] || fail "mt_sms_settings is not dnb_def_auth's, as 033 promises ('$tb')"
[ "$acl" = none ] || fail "a role other than its owner holds a privilege on mt_sms_settings: $acl"
[ "$nrow" = 1 ] || fail "mt_sms_settings holds $nrow rows, not the one 033 inserts"
FNS="'mt_sms_settings_set','mt_sms_settings_for_worker','mt_sms_worker_report','mt_admin_sms_settings'"
own=$(sq "SELECT count(*) FROM pg_proc WHERE pronamespace = 'public'::regnamespace AND proname IN ($FNS) AND prosecdef AND pg_get_userbyid(proowner) = 'dnb_def_auth'")
echo "SMS settings functions that are SECURITY DEFINER and dnb_def_auth's: $own of 4"
[ "$own" = 4 ] || fail "not all four SMS settings functions are SECURITY DEFINER owned by dnb_def_auth ($own)"
gr=$(sq "SELECT string_agg(g, ' ' ORDER BY g) FROM (
           SELECT p.proname || ':' || string_agg(CASE WHEN a.grantee = 0 THEN 'PUBLIC' ELSE pg_get_userbyid(a.grantee) END, ',' ORDER BY a.grantee) AS g
             FROM pg_proc p, aclexplode(coalesce(p.proacl, acldefault('f', p.proowner))) a
            WHERE p.pronamespace = 'public'::regnamespace AND p.proname IN ($FNS)
              AND a.privilege_type = 'EXECUTE' AND a.grantee <> p.proowner
            GROUP BY p.proname) x")
echo "EXECUTE, beyond the owner: $gr"
[ "$gr" = "mt_admin_sms_settings:dnb_adminapi mt_sms_settings_for_worker:dnb_worker mt_sms_settings_set:dnb_adminwrite mt_sms_worker_report:dnb_worker" ] \
  || fail "the SMS settings functions are not granted as 033 promises"
aw=$(sq "SELECT has_function_privilege('dnb_def_auth', 'mt_audit_write(uuid,text,text,text,text,text,text,jsonb)', 'EXECUTE')::text
             || '|' || has_schema_privilege('dnb_def_auth', 'public', 'CREATE')::text")
echo "dnb_def_auth may write the audit row of the act it owns | kept CREATE on the schema: $aw"
[ "$aw" = "true|false" ] || fail "dnb_def_auth's privileges are not as 033 leaves them ($aw)"
res=$(sq "SELECT pg_get_function_result('public.mt_admin_sms_settings()'::regprocedure)")
echo "$res" | grep -q 'key_set boolean' || fail "the Admin read does not report key_set"
echo "$res" | grep -qE 'key_sealed|key_fp' && fail "the Admin read returns the key's envelope or its fingerprint"
echo "the Admin read returns key_set, and neither the envelope nor the fingerprint"
gr32=$(sq "SELECT string_agg(g, ' ' ORDER BY g) FROM (
           SELECT p.proname || ':' || string_agg(CASE WHEN a.grantee = 0 THEN 'PUBLIC' ELSE pg_get_userbyid(a.grantee) END, ',' ORDER BY a.grantee) AS g
             FROM pg_proc p, aclexplode(coalesce(p.proacl, acldefault('f', p.proowner))) a
            WHERE p.pronamespace = 'public'::regnamespace
              AND p.proname IN ('mt_auth_issue_code','mt_auth_verify_code','mt_auth_resolve_token','mt_auth_sms_claim','mt_auth_sms_settle','mt_auth_sms_expire')
              AND a.privilege_type = 'EXECUTE' AND a.grantee <> p.proowner
            GROUP BY p.proname) x")
[ "$gr32" = "mt_auth_issue_code:dnb_app mt_auth_resolve_token:dnb_app mt_auth_sms_claim:dnb_worker mt_auth_sms_expire:dnb_worker mt_auth_sms_settle:dnb_worker mt_auth_verify_code:dnb_app" ] \
  || fail "the sign-in and SMS outbox functions of 031 and 032 are no longer granted as they were"
echo "031 and 032's sign-in and outbox grants: unchanged"
k1=$(o1); [ "$k1" = "1|1|2|true" ] || fail "migration 029's key does not hold after 033 ($k1)"
echo "O-1 after: $k1"

# b. by execution, in ONE transaction that is always rolled back. The row is
# read by the superuser first (a positive control that it is there to change).
S0=$(settings_row)
P0=$(sq "SELECT provider FROM mt_sms_settings")
echo "the settings now: provider $P0, version $(sq "SELECT version FROM mt_sms_settings"), key set: $(sq "SELECT (key_sealed IS NOT NULL)::text FROM mt_sms_settings")"
WANT_FIRST=set; [ "$P0" = africastalking ] && WANT_FIRST=replaced
probe=$(as postgres <<SQL
BEGIN;
SELECT version AS v0 FROM mt_sms_settings \gset
SET ROLE dnb_adminwrite;
SELECT 'who|' || current_user;
SELECT mt_sms_settings_set('africastalking', '$PROBE_USER', 'DishNet', '$PROBE_SEALED', '$PROBE_FP', '$PROBE_ACTOR') AS r1 \gset
SELECT 'first|' || (:'r1'::jsonb ->> 'changed') || '|' || (:'r1'::jsonb ->> 'key');
SELECT mt_sms_settings_set('africastalking', '$PROBE_USER', 'DishNet', '$PROBE_SEALED', '$PROBE_FP', '$PROBE_ACTOR') AS r2 \gset
SELECT 'again|' || (:'r2'::jsonb ->> 'changed') || '|' || (:'r2'::jsonb ->> 'key');
SAVEPOINT refusal;
SELECT 'refusal|NOT REFUSED|' || mt_sms_settings_set('africastalking', 'another-account', NULL, NULL, NULL, '$PROBE_ACTOR')::text;
ROLLBACK TO SAVEPOINT refusal;
RESET ROLE;
SELECT 'audit|' || count(*) || '|' || coalesce(min(actor_kind), '-') || '|' || coalesce(min(action), '-') || '|'
       || coalesce(bool_and(customer_id IS NULL)::text, '-') || '|'
       || coalesce(bool_and(position('$PROBE_SEALED' in detail::text) = 0 AND position('$PROBE_FP' in detail::text) = 0)::text, '-')
  FROM mt_audit_log WHERE actor = '$PROBE_ACTOR';
SET ROLE dnb_worker;
SELECT 'worker|' || provider || '|' || username || '|' || coalesce(sender, '-') || '|' || (key_sealed = '$PROBE_SEALED')::text || '|' || (version = :v0 + 1)::text
  FROM mt_sms_settings_for_worker();
SELECT 'report|ok' FROM (SELECT mt_sms_worker_report(:v0 + 1, 'unusable', 'staging probe, rolled back')) x;
RESET ROLE;
SET ROLE dnb_adminapi;
SELECT 'panel|' || provider || '|' || username || '|' || key_set::text || '|' || worker_state || '|' || (worker_version = :v0 + 1)::text
  FROM mt_admin_sms_settings();
RESET ROLE;
ROLLBACK; -- the execution test keeps nothing
SQL
)
echo "$probe" | grep -vE '^(BEGIN|ROLLBACK|SET|RESET|SAVEPOINT)$' | sed 's/^/  /'
echo "$probe" | grep -q '^who|dnb_adminwrite$' || fail "the execution test did not run as dnb_adminwrite"
echo "$probe" | grep -q "^first|true|$WANT_FIRST\$" || fail "CONTROL failed: dnb_adminwrite could not save a setting (expected changed, key $WANT_FIRST)"
echo "$probe" | grep -q '^again|false|kept$' || fail "the same save again was not a no-op (RULE I-1)"
echo "$probe" | grep -q 'a new username needs its API key typed again' || fail "a new username without its key was not refused in the function's words"
echo "$probe" | grep -q '^refusal|NOT REFUSED' && fail "a new username without its key was accepted"
echo "$probe" | grep -q '^audit|1|staff|sms.settings_changed|true|true$' \
  || fail "the save did not write exactly ONE audit row (staff, sms.settings_changed, no operator, no envelope or fingerprint in it)"
echo "$probe" | grep -q "^worker|africastalking|$PROBE_USER|DishNet|true|true\$" || fail "the worker's read did not return the saved setting at the next version"
echo "$probe" | grep -q '^report|ok$' || fail "CONTROL failed: dnb_worker could not report"
echo "$probe" | grep -q "^panel|africastalking|$PROBE_USER|true|unusable|true\$" || fail "the Admin read did not show the saved setting and the worker's report"
echo "execution test (rolled back): saved with ONE audit row; the same save again changed nothing and wrote none; a new username without its key refused; the worker read it and reported; the Admin read showed key_set and the report"

# c. every login role, enumerated: the table and each function, by execution.
ROLES=$(sq "SELECT string_agg(rolname, ' ' ORDER BY rolname) FROM pg_roles WHERE rolcanlogin AND NOT rolsuper")
nroles=0; ctl_aw=0; ctl_w=0; ctl_api=0
for r in $ROLES; do
  out=$(as "$r" <<SQL
BEGIN;
SELECT 'table|' || count(*) FROM mt_sms_settings;
ROLLBACK;
BEGIN;
SELECT 'set|' || (mt_sms_settings_set('none', NULL, NULL, NULL, NULL, '$PROBE_ACTOR') ->> 'changed');
ROLLBACK;
BEGIN;
SELECT 'forworker|' || count(*) FROM mt_sms_settings_for_worker();
ROLLBACK;
BEGIN;
SELECT 'report|ok' FROM (SELECT mt_sms_worker_report(NULL, 'off', 'staging probe, rolled back')) x;
ROLLBACK;
BEGIN;
SELECT 'read|' || count(*) FROM mt_admin_sms_settings();
ROLLBACK;
SQL
)
  echo "$out" | grep -qE 'FATAL|could not connect|connection to server' && fail "cannot connect as $r, so the refusal test cannot be taken for it — paste this output back"
  echo "$out" | grep -q '^table|' && fail "$r READ mt_sms_settings"
  [ "$(echo "$out" | grep -c 'permission denied for table mt_sms_settings' || true)" = 1 ] || fail "$r was not refused mt_sms_settings"
  allowed=""
  for pair in "set:mt_sms_settings_set:dnb_adminwrite" "forworker:mt_sms_settings_for_worker:dnb_worker" \
              "report:mt_sms_worker_report:dnb_worker" "read:mt_admin_sms_settings:dnb_adminapi"; do
    tag=${pair%%:*}; rest=${pair#*:}; fn=${rest%%:*}; ownr=${rest#*:}
    if [ "$r" = "$ownr" ]; then
      echo "$out" | grep -q "^$tag|" || fail "CONTROL failed: $r could not call $fn, its own function"
      allowed="${allowed:+$allowed }$fn"
      case $tag in set) ctl_aw=1;; forworker|report) ctl_w=$((ctl_w + 1));; read) ctl_api=1;; esac
    else
      echo "$out" | grep -q "^$tag|" && fail "$r was NOT refused $fn"
      [ "$(echo "$out" | grep -c "permission denied for function $fn" || true)" = 1 ] || fail "$r was not refused $fn as a privilege"
    fi
  done
  echo "  $r: the table refused; allowed: ${allowed:-none}; every other function refused"
  nroles=$((nroles + 1))
done
[ "$ctl_aw|$ctl_w|$ctl_api" = "1|2|1" ] || fail "the positive controls did not all run (dnb_adminwrite $ctl_aw, dnb_worker $ctl_w of 2, dnb_adminapi $ctl_api)"
echo "refusals, by execution: $nroles login role(s); every one refused the table; each function ran only for its own role"

# d. residue: nothing of the probes was kept.
left=$(sq "SELECT count(*) FROM mt_audit_log WHERE actor = '$PROBE_ACTOR'")
[ "$left" = 0 ] || fail "the execution tests left $left audit row(s) behind"
[ "$(settings_row)" = "$S0" ] || fail "the execution tests changed the settings row"
echo "residue: 0 audit rows by the probe actor; the settings row is as it was"

step "5/6 restart the API, the worker and the operator app on the new build (restarted, not recreated)"
T_R=$(sq "SELECT now()")
SINCE=$(date -u +%Y-%m-%dT%H:%M:%SZ)
docker restart dnb-staging-api dnb-staging-worker dnb-staging-app >/dev/null || fail "docker restart failed — paste this output back"
i=0; until [ "$(code http://127.0.0.1:8099/)" = 200 ] && [ "$(code "http://127.0.0.1:$PORT/")" = 200 ]; do i=$((i+1)); [ $i -le 30 ] || break; sleep 1; done
B=$(mktemp "$EV/body.XXXXXX")
c_panel=$(code http://127.0.0.1:8099/)
c_js=$(code http://127.0.0.1:8099/settings.js)
c_nav=$(curl -s http://127.0.0.1:8099/app.js | grep -c 'SMS for sign-in' || true)
c_sess=$(curl -s -o "$B" -w '%{http_code}' http://127.0.0.1:8099/api/v1/admin/session || true)
c_get=$(code http://127.0.0.1:8099/api/v1/admin/settings/sms)
c_post=$(code -X POST -H 'Content-Type: application/json' --data '{}' http://127.0.0.1:8099/api/v1/admin/settings/sms)
echo "API: panel $c_panel, settings.js $c_js, the SMS page in the panel: $c_nav, GET /api/v1/admin/session $c_sess $(cat "$B")"
echo "the SMS settings routes, anonymously (401 = bound and guarded): GET $c_get, POST $c_post"
[ "$c_panel|$c_js|$c_sess|$c_get|$c_post" = "200|200|401|401|401" ] && [ "$c_nav" -ge 1 ] && grep -q '"provider":"dishnet"' "$B" \
  || fail "the API did not come back on the new build with the dishnet provider and the SMS routes guarded"
WANT_SMS=panel; [ "$SMS_MODE" = env ] && WANT_SMS=$WSMS
i=0; line=""; rep=""
while [ $i -le 30 ]; do
  line=$(docker logs --since "$SINCE" dnb-staging-worker 2>&1 | grep -m1 -o '"sms":"[^"]*"' || true)
  rep=$(echo "SELECT 'rep|' || coalesce(worker_state, '-') || '|' || coalesce(worker_version::text, '-') || '|' || coalesce((worker_seen_at >= '$T_R'::timestamptz)::text, 'false') || '|' || version || '|' || provider FROM mt_admin_sms_settings();" \
        | as dnb_adminapi | grep '^rep|' || true)
  [ -n "$line" ] && echo "$rep" | grep -q '^rep|[a-z_]*|[-0-9]*|true|' && break
  [ "$(docker inspect -f '{{.State.Running}}' dnb-staging-worker 2>/dev/null || true)" = true ] || break
  i=$((i+1)); sleep 1
done
wlog=$(docker logs --since "$SINCE" dnb-staging-worker 2>&1 | head -4 || true)
echo "$wlog" | sed 's/^/  /'
[ "$(docker inspect -f '{{.State.Running}}' dnb-staging-worker 2>/dev/null || true)" = true ] || fail "the worker is not running after the restart (its first lines are above; they name variables, never values)"
[ "$line" = "\"sms\":\"$WANT_SMS\"" ] || fail "the worker did not start with the SMS mode $WANT_SMS (got '${line:-nothing}')"
echo "$wlog" | grep -q '"delivery_binding":"simulated-routeros"' || fail "the worker does not report the simulated delivery binding"
rstate=$(echo "$rep" | cut -d'|' -f2); rver=$(echo "$rep" | cut -d'|' -f3); rnow=$(echo "$rep" | cut -d'|' -f4); sver=$(echo "$rep" | cut -d'|' -f5); sprov=$(echo "$rep" | cut -d'|' -f6)
echo "the worker's report in the database: state $rstate, at version $rver, after this restart: $rnow (settings version $sver, provider $sprov)"
[ "$rnow" = true ] || fail "the worker has not reported since the restart"
if [ "$SMS_MODE" = env ]; then
  [ "$rstate" = environment ] || fail "the worker reports '$rstate', not 'environment', although DN_SMS is set"
else
  [ "$rver" = "$sver" ] || fail "the worker reports version $rver, not the settings' $sver"
  if [ "$sprov" = none ]; then [ "$rstate" = off ] || fail "no sender is set, yet the worker reports '$rstate'"
  else case $rstate in in_use|unusable) ;; *) fail "a sender is set, yet the worker reports '$rstate'";; esac; fi
fi
[ "$(envnames dnb-staging-worker)" = "$WN_BEFORE" ] && [ "$(envnames dnb-staging-api)" = "$AN_BEFORE" ] && [ "$(envnames dnb-staging-app)" = "$PN_BEFORE" ] \
  || fail "a container's environment changed across the restart"
echo "environments: unchanged in all three (names compared; restarted, not recreated)"
csp=$(hdr content-security-policy "http://127.0.0.1:$PORT/")
a_gw=$(code "http://$GW:$PORT/")
an=$(envnames dnb-staging-app | grep -E '^(DNB?_)' | tr '\n' ' ')
echo "operator app: loopback 200, gateway $a_gw, the reviewed CSP: $([ "$csp" = "$CSP" ] && echo yes || echo NO), its DN_/DNB_ variables: $an"
[ "$a_gw" = 200 ] && [ "$csp" = "$CSP" ] && [ "$an" = "DNB_APP_PASS DNB_DSN DNB_SECRET_KEY DNB_TOKEN_PEPPER " ] || fail "the operator app did not come back as it was"
p_js=$(code -k --resolve "$PORTAL:443:127.0.0.1" "https://$PORTAL/settings.js")
p_get=$(code -k --resolve "$PORTAL:443:127.0.0.1" "https://$PORTAL/api/v1/admin/settings/sms")
a_root=$(code -k --resolve "$APPHOST:443:127.0.0.1" "https://$APPHOST/")
echo "through Traefik: $PORTAL/settings.js $p_js, its SMS settings route anonymously $p_get; $APPHOST/ $a_root"
[ "$p_js|$p_get|$a_root" = "200|401|200" ] || fail "through Traefik the new build does not answer 200, 401 and 200"
[ "$(docker inspect -f '{{.State.StartedAt}}' "$T")" = "$T_STARTED" ] || fail "Traefik restarted during this run"
[ "$(sha256sum "$ADMIN_ROUTE" | cut -d' ' -f1)|$(sha256sum "$APP_ROUTE" | cut -d' ' -f1)" = "$R_ADMIN|$R_APP" ] || fail "a Traefik route file changed during this run"
echo "Traefik: not restarted (started $T_STARTED); both route files unchanged"

step "6/6 result"
docker inspect -f '{{.Name}}|{{.State.Status}}|{{.State.StartedAt}}|{{.RestartCount}}' $(docker ps -aq) | sort > "$EV/sms-settings-$TS.after"
changed=$(comm -13 "$EV/sms-settings-$TS.before" "$EV/sms-settings-$TS.after" | cut -d'|' -f1 | tr -d '/' | sort -u | tr '\n' ' ')
echo "containers new or changed: ${changed:-none}"
[ "$changed" = "dnb-staging-api dnb-staging-app dnb-staging-worker " ] || fail "unexpected container change: ${changed:-none}"
cut -d'|' -f1 "$EV/sms-settings-$TS.before" | sort > "$EV/sms-settings-$TS.names.before"
cut -d'|' -f1 "$EV/sms-settings-$TS.after"  | sort > "$EV/sms-settings-$TS.names.after"
removed=$(comm -23 "$EV/sms-settings-$TS.names.before" "$EV/sms-settings-$TS.names.after" | tr '\n' ' ')
echo "containers removed (must be none): ${removed:-none}"
[ -z "$removed" ] || fail "a container disappeared during this run: $removed"
rm -f "$B"
case $SMS_MODE in
  env)   MODE_LINE="the worker's SMS comes from DN_SMS=$WSMS in its environment, which wins over the panel" ;;
  *)     if [ "$sprov" = none ]; then MODE_LINE="the worker follows the Admin panel: no SMS sender is set yet, so no code is sent"
         else MODE_LINE="the worker follows the Admin panel: $sprov, reported $rstate"; fi ;;
esac
echo
echo "=== SMS SETTINGS ON STAGING $(date -u +%FT%TZ): $(cat "$APP/app/VERSION"), content digest $DIGEST, migrations $after_n (last $after_last); the settings store and its four functions refused by execution to every login role but each function's own ($nroles roles); save, replay and refusal proved in a rolled-back test, residue 0; $MODE_LINE ==="
echo
echo "Next, by you (nothing below was done by this command):"
echo "  1. Open https://$PORTAL/ and sign in as dishnet-admin. The first time, the panel asks you"
echo "     to set up an authenticator app, then to choose a new password."
echo "  2. Open Administration → SMS for sign-in."
if [ "$SMS_MODE" = env ]; then
  echo "     It says the SMS sender is set on the server (DN_SMS). The panel's settings are not used while it is."
else
  echo "     It says no SMS sender is set yet. When your Africa's Talking account exists, enter the username,"
  echo "     the API key and, if you like, a sender name, then Save. The key is never shown again."
  echo "     Within a few seconds the page should say the worker has it in use."
fi
echo "  3. Then use 'Add an owner' on an operator with your own number, and sign in on your phone at"
echo "     https://$APPHOST/. Tell me only what the screen says: never the code, and never the key."
echo "  Do not run the operator-app command (dnb-operator-app.sh) again: it now stops at its first check"
echo "  and changes nothing. From now on the SMS account is set in the panel."
echo
echo "Rollback, if ever needed (in this order: a container mounts the tree it is started on, so the"
echo "previous tree goes back first):"
echo "  mv $APP/app $APP/app.failed-$TS && mv $APP/app.prev-$TS $APP/app"
echo "  docker restart dnb-staging-api dnb-staging-worker dnb-staging-app"
echo "  (033 stays: the previous build never reads its table. A key saved in the panel stays sealed in the"
echo "  database; use 'Turn SMS off' in the panel first if you want it removed.)"
echo
echo "Send back the LOG FILE, not the terminal. This prints it:"
echo "  cat \"\$(ls -t $EV/sms-settings-*.log | head -1)\""
