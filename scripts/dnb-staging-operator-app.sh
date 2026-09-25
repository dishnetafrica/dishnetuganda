#!/bin/sh
# DishNet Domain-B staging — the OPERATOR APP on its own host name, with sign-in
# codes by SMS: migrations 031 and 032 and docs/127 phase 3 (docs/127 §J).
# One command, run as root on the server:
#
#   curl -fsSL -o /root/dnb-operator-app.sh https://raw.githubusercontent.com/dishnetafrica/dishnetuganda/claude/study-this-jhe2eg/scripts/dnb-staging-operator-app.sh \
#     && sh /root/dnb-operator-app.sh 2>&1 | tee /root/dnb-staging-evidence/operator-app-$(date -u +%Y%m%dT%H%M%SZ).log
#
# Create the DNS record first: app-staging.dishnetuganda.com -> 209.97.137.203.
#
#   0  read-only checks. Nothing is changed by any refusal here.
#   1  SMS for sign-in codes, OPTIONAL: the Africa's Talking username, the API
#      key (typed with echo off) and a sender name. They are held in memory,
#      never printed and never logged. SMS=skip skips the question; a key from
#      an earlier run is kept unless SMS=replace.
#   2  build the artifact ON THIS SERVER from the reviewed commit, fetched by
#      its hash; refuse unless its content digest is the reviewed one.
#   3  read-only: the new build's doctor, in production posture and with the
#      SMS settings, must report no blocker.
#   4  swap /opt/dnb-staging/app (the previous tree is kept) and apply exactly
#      031 and 032 with the installer. A refusal puts the previous tree back.
#   5  verify INDEPENDENTLY, afterwards — the catalogue, then execution tests
#      in transactions that are ALWAYS rolled back, so nothing is kept and
#      nothing is ever sent: an unknown number gets no message; an active
#      person of an active operator gets one queued; the same person with the
#      operator suspended gets none (031); every login role is refused the
#      outbox, the worker's claim and code issue, except each one's own role.
#   6  restart the API on the new build; recreate the worker with the SMS
#      settings (DN_DELIVERY stays simulated) and read its SMS binding off its log.
#   7  add dnb-staging-app: the operator app, its own container, holding four
#      variables and no other credential, on 127.0.0.1:8098 and 172.17.0.1:8098.
#   8  add the Traefik route app-staging.dishnetuganda.com (a NEW file; the
#      Admin route is not touched): the app's allow-list in the router rules, a
#      stricter rate limit on sign-in, https only. Then verify through Traefik:
#      the certificate, the page, the refusals, and the sign-in rate limit.
#   9  result.
#
# It never signs anyone in: that needs a real phone and spends SMS credit, and
# is the operator's own first proof. It touches no production container, no
# DNS record, no firewall rule, no PostgreSQL instance but dnb-staging-postgres,
# and no Traefik file but the new one. DN_ALLOW_REAL_BINDINGS stays absent and
# router delivery stays simulated. It prints no secret.
#
# The version name is still 0.1.0-rc1: compare the CONTENT DIGEST, never the
# name (docs/96). Deployed since 2026-09-24 04:47 UTC: 4a629184…; this build:
# 2874d643….
set -eu

EV=/root/dnb-staging-evidence
APP=/opt/dnb-staging
IMG=dnb-staging-php:8.3
# The source may be overridden only for the local rehearsal (scripts/harness/
# operator-app). It cannot change what is deployed: the digest below decides.
REPO=${DNB_REDEPLOY_REPO:-https://github.com/dishnetafrica/dishnetuganda}
BRANCH=claude/study-this-jhe2eg
# The reviewed commit on that branch (docs/127 §I), fetched by its hash.
COMMIT=818d711e342a8d7627d7491be759e09a2bbbcab7
CP=dishnet-hybrid-sudan/dishnet-mikrotik-control-plane
# Content digest of the reviewed build = sha256 of the archive's SHA256SUMS (docs/127 §I.7).
DIGEST=2874d64346b927e6ec2da77ceecb19714a623030ea7e89b74590d7bc6640a0c4
PORTAL=portal-staging.dishnetuganda.com
HOST=app-staging.dishnetuganda.com
PUBLIC_IP=209.97.137.203
TCFG=/etc/easypanel/traefik/config
ADMIN_ROUTE=$TCFG/dnb-staging.yml
ROUTE=$TCFG/dnb-staging-app.yml
GW=172.17.0.1
PORT=8098
MIG1=031_sign_in_requires_active_operator.sql
MIG2=032_sign_in_codes_by_sms.sql
PREV_MIG=030_admin_operator_onboarding.sql
FK=mt_sites_service_customer_fkey
UQ=mt_services_id_customer_key
CSP="default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; manifest-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'"
PROBE_PHONE=+256799000000
SMS_MODE=${SMS:-ask}
TS=$(date -u +%Y%m%dT%H%M%SZ)
RO="-c default_transaction_read_only=on"

fail() { echo; echo "STOP: $*" >&2; echo "Send back the log file, not the terminal: cat \"\$(ls -t $EV/operator-app-*.log | head -1)\"" >&2; exit 1; }
step() { echo; echo "== $* =="; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@" 2>/dev/null || true; }
# sq <sql>: one read-only answer from the staging instance's catalogue.
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

# Whatever happens, the terminal gets its echo back, and SMS settings that were
# never promoted to sms.env do not stay on disk.
PROMOTED=0; KF=""
cleanup() {
  { stty echo < /dev/tty; } 2>/dev/null || true
  [ "$PROMOTED" = 1 ] || rm -f "$APP/env/sms.env.new"
  [ -z "$KF" ] || rm -f "$KF"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

[ "$(id -u)" = 0 ] || fail "run as root"
for t in docker curl tar sha256sum sed grep awk comm ss getent openssl; do
  command -v "$t" >/dev/null 2>&1 || fail "missing tool: $t"
done
case $SMS_MODE in ask|skip|replace) ;; *) fail "SMS must be ask, skip or replace (got '$SMS_MODE')";; esac
install -d -m 0700 "$EV"
date -u '+%Y-%m-%d %H:%M:%S UTC'; hostname

step "0/9 read-only checks (nothing is changed in this step)"
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
[ -e "$APP/env/sms.env.new" ] && { rm -f "$APP/env/sms.env.new"; echo "removed: $APP/env/sms.env.new, left by an earlier run that stopped"; }
cur=$(sha256sum "$APP/app/SHA256SUMS" | cut -d' ' -f1)
echo "deployed content digest: $cur"
[ "$cur" = "$DIGEST" ] && echo "NOTE: the reviewed build is already deployed; the run continues and re-verifies"
APP_EXISTS=0
docker inspect -f '{{.State.Status}}' dnb-staging-app >/dev/null 2>&1 && APP_EXISTS=1
for c in dnb-staging-api dnb-staging-worker $([ "$APP_EXISTS" = 1 ] && echo dnb-staging-app); do
  n=$(envnames "$c") || fail "cannot read the environment of $c"
  echo "$n" | grep -qx DN_ALLOW_REAL_BINDINGS && fail "DN_ALLOW_REAL_BINDINGS is set on $c — F6-B is NOT authorized; refusing"
  echo "$n" | grep -qx DNB_EXPOSE_OTP && fail "DNB_EXPOSE_OTP is set on $c — a sign-in code must never be shown; refusing"
done
api_env=$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' dnb-staging-api) || fail "cannot read the API's environment"
api_get() { echo "$api_env" | grep "^$1=" | head -1 | cut -d= -f2-; }
[ "$(api_get DN_STAFF_IDENTITY)" = dishnet ] && [ -z "$(api_get DN_DEV_STAFF_IDENTITY)" ] \
  || fail "the API does not run the real DishNet staff login alone (docs/122) — this command expects that state; nothing was changed"
TRUSTED=$(api_get DN_TRUSTED_PROXY); ORIGIN=$(api_get DN_PORTAL_ORIGIN)
echo "$TRUSTED" | grep -qE '^[0-9]{1,3}(\.[0-9]{1,3}){3}$' || fail "DN_TRUSTED_PROXY on the API is not one IPv4 address: '$TRUSTED'"
[ "$ORIGIN" = "https://$PORTAL" ] || fail "DN_PORTAL_ORIGIN on the API is '$ORIGIN', not https://$PORTAL"
echo "API identity: the real DishNet staff login (docs/122) — trusted proxy $TRUSTED, origin $ORIGIN"
wn=$(envnames dnb-staging-worker)
[ "$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' dnb-staging-worker | grep -c '^DN_DELIVERY=simulated$' || true)" = 1 ] \
  || fail "the worker does not run DN_DELIVERY=simulated — not a state this script knows"
echo "worker: DN_DELIVERY=simulated; SMS variables now: $(echo "$wn" | grep -E '^(DN_SMS|DNB_SMS_)' | tr '\n' ' ' || true)"
before_n=$(sq "SELECT count(*) FROM mt_migrations")
before_last=$(sq "SELECT max(filename) FROM mt_migrations")
echo "migrations before: $before_n (last $before_last)"
case "$before_n|$before_last" in
  "30|$PREV_MIG") echo "ok: migration 030 is applied (docs/125 §F); 031 and 032 are the ones pending" ;;
  "31|$MIG1")     echo "NOTE: 031 is applied and 032 is pending — an earlier run stopped at 032; the run continues" ;;
  "32|$MIG2")     echo "NOTE: 031 and 032 are already applied; the run continues and re-verifies" ;;
  *) fail "this command applies 031 and 032 on top of 030 and nothing else, and the ledger reads $before_n migration(s), last $before_last. Nothing was changed" ;;
esac
k0=$(o1)
echo "O-1 (validated key | supporting UNIQUE | single-column keys kept | FORCE on both): $k0"
[ "$k0" = "1|1|2|true" ] || fail "migration 029's key is not in place ($k0) — nothing was changed; paste this output back"
# The app's four variables, from the stage-1 files: present once each, unquoted, not empty.
for pair in "runtime.env DNB_DSN" "runtime.env DNB_TOKEN_PEPPER" "runtime.env DNB_SECRET_KEY" "secrets.docker.env DNB_APP_PASS"; do
  f=${pair% *}; k=${pair#* }
  [ "$(grep -c "^$k=" "$APP/env/$f" || true)" = 1 ] || fail "$APP/env/$f does not hold $k exactly once"
  grep "^$k=" "$APP/env/$f" | grep -qE "^$k=[^\"' ]+\$" || fail "$k in $APP/env/$f is empty, quoted or holds a space"
done
echo "the app's four variables are in the stage-1 files (values not shown)"
[ "$(code http://127.0.0.1:8099/)" = 200 ] || fail "the panel does not answer 200 on 127.0.0.1:8099"
[ -f "$ADMIN_ROUTE" ] || fail "$ADMIN_ROUTE is missing — stage 2 (docs/120 §15.8.6) is not in place"
grep -q 'entryPoints: \["https"\]' "$ADMIN_ROUTE" && grep -q 'certResolver: letsencrypt' "$ADMIN_ROUTE" && grep -q "http://$GW:8099" "$ADMIN_ROUTE" \
  || fail "$ADMIN_ROUTE does not show the pattern this route copies (https entrypoint, letsencrypt, $GW backend) — stop and look"
[ "$(code -k --resolve "$PORTAL:443:127.0.0.1" "https://$PORTAL/")" = 200 ] || fail "https://$PORTAL/ does not answer through Traefik — the precedent is not live"
T=$(docker ps --format '{{.Names}}' | grep -i traefik | head -1 || true); [ -n "$T" ] || fail "no Traefik container is running"
SVC=$(docker service ls --format '{{.Name}}' 2>/dev/null | grep -i traefik | head -1 || true); [ -n "$SVC" ] || fail "no Traefik swarm service found"
docker service inspect "$SVC" --format '{{json .Endpoint.Spec.Ports}}' | grep -q '"PublishMode":"host"' \
  || fail "Traefik publishes 80/443 in ingress mode; the client address would be hidden and a per-address limit meaningless — stop and report"
T_STARTED=$(docker inspect -f '{{.State.StartedAt}}' "$T")
BGW=$(docker network inspect bridge -f '{{range .IPAM.Config}}{{.Gateway}}{{end}}' 2>/dev/null || true)
[ "$BGW" = "$GW" ] || fail "the docker bridge gateway is '$BGW', not $GW — the route's backend address would be wrong"
DNSA=$(getent ahostsv4 "$HOST" 2>/dev/null | awk '{print $1}' | sort -u | tr '\n' ' ' || true)
echo "$DNSA" | grep -qw "$PUBLIC_IP" || fail "$HOST does not resolve to $PUBLIC_IP (got: ${DNSA:-nothing}). Create that DNS A record first, wait a few minutes and run again. Nothing was changed"
echo "DNS: $HOST → $DNSA"
if [ "$APP_EXISTS" = 1 ]; then
  echo "NOTE: dnb-staging-app exists from an earlier run; it will be recreated"
else
  ss -tln | grep -qE "[:.]$PORT[[:space:]]" && fail "port $PORT is already in use on this host, by something that is not dnb-staging-app — nothing was changed"
  echo "port $PORT: free"
fi
if [ -e "$ROUTE" ]; then
  grep -q 'docs/127 §J' "$ROUTE" || fail "$ROUTE exists and was not written by this command — nothing was changed; look at it first"
  cp "$ROUTE" "$EV/operator-app-$TS.route.before"; echo "NOTE: $ROUTE exists from an earlier run; it will be rewritten (previous copy kept)"
fi
docker inspect -f '{{.Name}}|{{.State.Status}}|{{.State.StartedAt}}|{{.RestartCount}}' $(docker ps -aq) | sort > "$EV/operator-app-$TS.before"
echo "ok: stage 1, stage 2 and the real staff login are in place; DNS resolves; nothing was changed"

step "1/9 SMS for sign-in codes (optional; the key is never shown or logged)"
SMS_STATE=none
if [ -f "$APP/env/sms.env" ] && [ "$SMS_MODE" != replace ]; then
  SMS_STATE=kept
  echo "kept: the SMS settings from an earlier run ($APP/env/sms.env). To replace them, run with SMS=replace."
elif [ "$SMS_MODE" = skip ]; then
  echo "skipped (SMS=skip): no code will reach any phone until a key is added — run again without SMS=skip"
elif ! ( : > /dev/tty ) 2>/dev/null; then
  echo "skipped: no terminal to type the key into. Run the command again from an SSH session to add it"
else
  {
    echo
    echo "  To send sign-in codes to phones, this needs your Africa's Talking username and"
    echo "  API key (in your Africa's Talking account: Settings → API Key)."
    echo "  Press Enter to skip: the app still goes live, but nobody can sign in until a key is added."
    echo
    printf "  Africa's Talking username: "
  } > /dev/tty
  IFS= read -r SMS_USER < /dev/tty || SMS_USER=""
  if [ -z "$SMS_USER" ]; then
    echo "skipped: no username typed — no code will reach any phone until a key is added"
  else
    echo "$SMS_USER" | grep -qE '^[A-Za-z0-9_.-]{1,64}$' || fail "the username may hold only letters, digits, dots, dashes and underscores. Nothing was changed"
    # Echo goes off BEFORE the prompt appears, so nothing typed after it is ever echoed.
    stty -echo < /dev/tty
    printf "  API key (typing is hidden): " > /dev/tty
    IFS= read -r SMS_KEY < /dev/tty || SMS_KEY=""
    stty echo < /dev/tty; printf '\n' > /dev/tty
    echo "$SMS_KEY" | grep -qE '^[A-Za-z0-9_-]{16,256}$' || { unset SMS_KEY; fail "that does not look like an API key (16-256 letters, digits, dashes or underscores). Nothing was changed"; }
    printf "  Sender name (optional, up to 15 characters; press Enter for none): " > /dev/tty
    IFS= read -r SMS_SENDER < /dev/tty || SMS_SENDER=""
    [ -z "$SMS_SENDER" ] || echo "$SMS_SENDER" | grep -qE '^[A-Za-z0-9 ._-]{1,15}$' \
      || { unset SMS_KEY; fail "the sender name must be 1-15 letters, digits, spaces, dots, dashes or underscores. Nothing was changed"; }
    # Written by the shell itself (printf is a builtin): the key is on no command line.
    ( umask 077
      printf 'DN_SMS=africastalking\nDNB_SMS_USERNAME=%s\nDNB_SMS_API_KEY=%s\n' "$SMS_USER" "$SMS_KEY"
      [ -z "$SMS_SENDER" ] || printf 'DNB_SMS_SENDER=%s\n' "$SMS_SENDER"
    ) > "$APP/env/sms.env.new"
    chmod 0600 "$APP/env/sms.env.new"
    SMS_STATE=new
    echo "SMS: username $SMS_USER, key typed (not shown), sender ${SMS_SENDER:-(none)}$([ "$SMS_USER" = sandbox ] && echo ' — SANDBOX: messages go to the provider'"'"'s simulator, not to phones')"
  fi
fi
case $SMS_STATE in
  new)  SMSFILE="$APP/env/sms.env.new" ;;
  kept) SMSFILE="$APP/env/sms.env" ;;
  *)    SMSFILE="" ;;
esac

step "2/9 build the artifact on this server from commit ${COMMIT%${COMMIT#????????????}} of $BRANCH and check its content digest"
SRC="$EV/src-$TS"
install -d "$SRC"
if command -v git >/dev/null 2>&1; then
  git init -q "$SRC"
  git -C "$SRC" fetch -q --depth 1 "$REPO.git" "$COMMIT"
  git -C "$SRC" checkout -q FETCH_HEAD
  head=$(git -C "$SRC" rev-parse HEAD)
  [ "$head" = "$COMMIT" ] || fail "fetched $head, not the reviewed commit $COMMIT — nothing was changed"
  echo "$head" | tee "$EV/operator-app-$TS.commit"
else
  curl -fsSL "https://codeload.github.com/dishnetafrica/dishnetuganda/tar.gz/$COMMIT" | tar -xz -C "$SRC" --strip-components=1
  echo "tarball of commit $COMMIT (no git on this host)" | tee "$EV/operator-app-$TS.commit"
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
for f in "migrations/$MIG1" "migrations/$MIG2" plugin/bin/serve-app.php public/index.php public/app/index.html; do
  [ -f "$NEW/$f" ] || { rm -rf "$NEW"; fail "the build carries no $f — wrong build"; }
done

step "3/9 read-only: the new build's doctor, in production posture and with the SMS settings, must report no blocker"
RUN="docker run --rm --network dnb-staging -v $APP/env:/run/dnb -w /app --env-file $APP/env/runtime.env --env-file $APP/env/install.env --env-file $APP/env/secrets.docker.env"
DOCTOR="-e DN_STAFF_IDENTITY=dishnet -e DN_TRUSTED_PROXY=$TRUSTED -e DN_PORTAL_ORIGIN=$ORIGIN"
[ -n "$SMSFILE" ] && DOCTOR="$DOCTOR --env-file $SMSFILE"
unset SMS_KEY 2>/dev/null || true
# The key, as a grep PATTERN FILE (mode 0600, removed at exit), so every check
# for a leak reads it from a file and never from a command line.
if [ -n "$SMSFILE" ]; then
  KF=$(mktemp "$EV/.smskey.XXXXXX"); chmod 0600 "$KF"
  sed -n 's/^DNB_SMS_API_KEY=//p' "$SMSFILE" > "$KF"
  [ -s "$KF" ] || fail "the SMS settings hold no key"
fi
# shellcheck disable=SC2086
doc0=$($RUN -v "$NEW:/app:ro" $DOCTOR "$IMG" php plugin/bin/plugin.php doctor 2>&1) || true
if [ -n "$KF" ] && printf '%s' "$doc0" | grep -qF -f "$KF"; then
  rm -rf "$NEW"; fail "the doctor's output carries the SMS key — refusing; nothing was changed, and nothing of it was printed"
fi
echo "$doc0" | grep -E 'checks:|^  warn |BLOCK|by SMS' | sed 's/^/  /'
echo "$doc0" | grep -qE '[0-9]+ checks: .*, 0 blocker' || { rm -rf "$NEW"; fail "the new build's doctor (production posture) reports a blocker — nothing was changed; paste this output back"; }
if [ -n "$SMSFILE" ]; then
  echo "$doc0" | grep -q 'value withheld' || { rm -rf "$NEW"; fail "the doctor does not report the SMS key as set (value withheld) — nothing was changed"; }
fi
echo "doctor (production posture): no blocker"

step "4/9 swap the application tree and apply the pending migrations"
mv "$APP/app" "$APP/app.prev-$TS"
mv "$NEW" "$APP/app"
echo "swapped: $APP/app is the new build; previous tree at $APP/app.prev-$TS"
if ! $RUN -v "$APP/app:/app:ro" "$IMG" php plugin/bin/plugin.php install > "$EV/operator-app-$TS.install.txt" 2>&1; then
  sed 's/^/  /' "$EV/operator-app-$TS.install.txt"
  mv "$APP/app" "$APP/app.refused-$TS" && mv "$APP/app.prev-$TS" "$APP/app"
  ref_n=$(sq "SELECT count(*) FROM mt_migrations"); ref_last=$(sq "SELECT max(filename) FROM mt_migrations")
  echo "migrations now: $ref_n (last $ref_last). The installer applies each file in its own transaction, so a file"
  echo "that committed before the refusal stays applied. 031 replaces three sign-in functions under the signatures"
  echo "the previous build calls, so that build runs on unchanged. A re-run of this command continues from there."
  fail "the installer refused (its lines are above). The previous tree is back at $APP/app, the refused build is at $APP/app.refused-$TS, no container was restarted, and no SMS setting was kept. Paste this output back"
fi
sed 's/^/  /' "$EV/operator-app-$TS.install.txt"
after_n=$(sq "SELECT count(*) FROM mt_migrations")
after_last=$(sq "SELECT max(filename) FROM mt_migrations")
want=$(ls "$APP/app/migrations"/*.sql | wc -l | tr -d ' ')
echo "migrations after: $after_n (last $after_last); migration files in the build: $want"
[ "$after_n" = "$want" ] || fail "the ledger ($after_n) does not match the build's migration files ($want)"
[ "$after_last" = "$MIG2" ] || fail "the latest applied migration is $after_last, not $MIG2"
if [ "$SMS_STATE" = new ]; then
  mv "$APP/env/sms.env.new" "$APP/env/sms.env"; chmod 0600 "$APP/env/sms.env"; PROMOTED=1
  SMSFILE="$APP/env/sms.env"
  echo "SMS settings kept at $APP/env/sms.env (mode $(stat -c '%a' "$APP/env/sms.env"))"
fi

step "5/9 verify independently, afterwards"
# a. the catalogue
ob=$(sq "SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid = 'public.mt_auth_sms_outbox'::regclass")
echo "the outbox mt_auth_sms_outbox: owner $ob"
[ "$ob" = dnb_def_auth ] || fail "the outbox is not dnb_def_auth's ('$ob')"
ia=$(sq "SELECT string_agg(pg_get_function_identity_arguments(oid), ' / ') FROM pg_proc WHERE pronamespace = 'public'::regnamespace AND proname = 'mt_auth_issue_code'")
echo "code issue takes: $ia"
[ "$ia" = "p_phone text, p_code_hash text, p_ttl interval, p_sealed text" ] || fail "mt_auth_issue_code is not 032's one four-argument form ('$ia')"
FNS="'mt_auth_issue_code','mt_auth_verify_code','mt_auth_resolve_token','mt_auth_sms_claim','mt_auth_sms_settle','mt_auth_sms_expire'"
own=$(sq "SELECT count(*) FROM pg_proc WHERE pronamespace = 'public'::regnamespace AND proname IN ($FNS) AND prosecdef AND pg_get_userbyid(proowner) = 'dnb_def_auth'")
echo "sign-in and SMS functions that are SECURITY DEFINER and dnb_def_auth's: $own of 6"
[ "$own" = 6 ] || fail "not all six sign-in and SMS functions are SECURITY DEFINER owned by dnb_def_auth ($own)"
gr=$(sq "SELECT string_agg(g, ' ' ORDER BY g) FROM (
           SELECT p.proname || ':' || string_agg(CASE WHEN a.grantee = 0 THEN 'PUBLIC' ELSE pg_get_userbyid(a.grantee) END, ',' ORDER BY a.grantee) AS g
             FROM pg_proc p, aclexplode(coalesce(p.proacl, acldefault('f', p.proowner))) a
            WHERE p.pronamespace = 'public'::regnamespace AND p.proname IN ($FNS)
              AND a.privilege_type = 'EXECUTE' AND a.grantee <> p.proowner
            GROUP BY p.proname) x")
echo "EXECUTE, beyond the owner: $gr"
[ "$gr" = "mt_auth_issue_code:dnb_app mt_auth_resolve_token:dnb_app mt_auth_sms_claim:dnb_worker mt_auth_sms_expire:dnb_worker mt_auth_sms_settle:dnb_worker mt_auth_verify_code:dnb_app" ] \
  || fail "the sign-in and SMS functions are not granted as 031 and 032 promise"
pol=$(sq "SELECT count(*) FROM pg_policies WHERE schemaname = 'public' AND tablename = 'mt_customers' AND policyname = 'dnb_def_auth_mt_customers_select'")
[ "$pol" = 1 ] || fail "031's read policy for dnb_def_auth on mt_customers is missing"
echo "031's read policy on mt_customers: present"
k1=$(o1); [ "$k1" = "1|1|2|true" ] || fail "migration 029's key does not hold after 031 and 032 ($k1)"
echo "O-1 after: $k1"

# b. by execution, in ONE transaction that is always rolled back: the probe's code
# rows are never committed, so no worker ever sees them and nothing is sent.
# Each outbox row is looked up by the code id the issue function returns: inside
# one transaction now() is constant, so two rows cannot be told apart by time.
reg=$(sq "SELECT count(*) FROM mt_principals WHERE phone = '$PROBE_PHONE'")
[ "$reg" = 0 ] || fail "the probe number is registered to somebody — refusing to use it"
pp=$(sq "SELECT count(*) FROM mt_principals p JOIN mt_customers c ON c.id = p.customer_id
          WHERE p.status = 'active' AND c.status = 'active' AND p.phone IS NOT NULL
            AND NOT EXISTS (SELECT 1 FROM mt_auth_codes k WHERE k.phone = p.phone AND k.created_at > now() - interval '15 minutes')")
PERSON=""
if [ "$pp" -ge 1 ]; then
  PERSON="SELECT p.phone AS pphone, p.customer_id AS pop FROM mt_principals p JOIN mt_customers c ON c.id = p.customer_id
     WHERE p.status = 'active' AND c.status = 'active' AND p.phone IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM mt_auth_codes k WHERE k.phone = p.phone AND k.created_at > now() - interval '15 minutes')
     ORDER BY p.created_at LIMIT 1 \\gset
SET ROLE dnb_app;
SELECT mt_auth_issue_code(:'pphone', 'probe', interval '10 minutes', 'v1.cHJvYmU=') AS pcode \\gset
SELECT 'person-issue|' || (:'pcode'::uuid IS NOT NULL);
RESET ROLE;
SELECT 'person|' || o.state || '|' || (o.sealed IS NULL) FROM mt_auth_sms_outbox o WHERE o.code_id = :'pcode';
UPDATE mt_customers SET status = 'suspended' WHERE id = :'pop';
SET ROLE dnb_app;
SELECT mt_auth_issue_code(:'pphone', 'probe', interval '10 minutes', 'v1.cHJvYmU=') AS scode \\gset
SELECT 'suspended-issue|' || (:'scode'::uuid IS NOT NULL);
RESET ROLE;
SELECT 'suspended|' || o.state || '|' || (o.sealed IS NULL) FROM mt_auth_sms_outbox o WHERE o.code_id = :'scode';"
fi
probe=$(as postgres <<SQL
BEGIN;
SET ROLE dnb_app;
SELECT 'who|' || current_user;
SELECT mt_auth_issue_code('$PROBE_PHONE', 'probe', interval '10 minutes', 'v1.cHJvYmU=') AS ucode \gset
SELECT 'unknown-issue|' || (:'ucode'::uuid IS NOT NULL);
RESET ROLE;
SELECT 'unknown|' || o.state || '|' || (o.sealed IS NULL) FROM mt_auth_sms_outbox o WHERE o.code_id = :'ucode';
$PERSON
ROLLBACK; -- the execution test keeps nothing
SQL
)
echo "$probe" | grep -vE '^(BEGIN|ROLLBACK|SET|RESET|UPDATE [0-9]+)$' | sed 's/^/  /'
echo "$probe" | grep -q '^who|dnb_app$' || fail "the execution test did not run as dnb_app"
echo "$probe" | grep -q '^unknown-issue|true$' || fail "CONTROL failed: dnb_app could not issue a code"
echo "$probe" | grep -q '^unknown|no_recipient|true$' || fail "an unknown number was not answered no_recipient with no payload"
if [ -n "$PERSON" ]; then
  echo "$probe" | grep -q '^person|queued|false$' || fail "an active person of an active operator was not queued a code"
  echo "$probe" | grep -q '^suspended|no_recipient|true$' || fail "with the operator suspended, the person was still queued a code (031)"
  echo "execution test (rolled back): unknown number → no message; an active person → queued; the same person, operator suspended → no message"
else
  echo "NOTE: no active person of an active operator is free to probe; the queued and suspended checks were skipped (the unknown-number check ran)"
fi

# c. every login role, enumerated: the outbox, the claim and code issue, by execution.
ROLES=$(sq "SELECT string_agg(rolname, ' ' ORDER BY rolname) FROM pg_roles WHERE rolcanlogin AND NOT rolsuper")
nroles=0; ctl_w=0; ctl_a=0
for r in $ROLES; do
  out=$(as "$r" <<SQL
BEGIN;
SELECT 'outbox|' || count(*) FROM mt_auth_sms_outbox;
ROLLBACK;
BEGIN;
SELECT 'claim|' || count(*) FROM mt_auth_sms_claim(1);
ROLLBACK;
BEGIN;
SELECT 'issue|' || (mt_auth_issue_code('$PROBE_PHONE', 'probe', interval '1 minute', 'v1.cHJvYmU=') IS NOT NULL);
ROLLBACK;
SQL
)
  echo "$out" | grep -qE 'FATAL|could not connect|connection to server' && fail "cannot connect as $r, so the refusal test cannot be taken for it — paste this output back"
  ob_r=$(echo "$out" | grep -c 'permission denied for table mt_auth_sms_outbox' || true)
  cl_r=$(echo "$out" | grep -c 'permission denied for function mt_auth_sms_claim' || true)
  is_r=$(echo "$out" | grep -c 'permission denied for function mt_auth_issue_code' || true)
  echo "$out" | grep -q '^outbox|' && fail "$r READ the outbox"
  [ "$ob_r" = 1 ] || fail "$r was not refused the outbox"
  if [ "$r" = dnb_worker ]; then
    echo "$out" | grep -q '^claim|' || fail "CONTROL failed: dnb_worker could not call the claim"; ctl_w=1
  else
    [ "$cl_r" = 1 ] && ! echo "$out" | grep -q '^claim|' || fail "$r was NOT refused the worker's claim"
  fi
  if [ "$r" = dnb_app ]; then
    echo "$out" | grep -q '^issue|true$' || fail "CONTROL failed: dnb_app could not issue a code"; ctl_a=1
  else
    [ "$is_r" = 1 ] && ! echo "$out" | grep -q '^issue|' || fail "$r was NOT refused code issue"
  fi
  echo "  $r: outbox refused; claim $([ "$r" = dnb_worker ] && echo 'ALLOWED (its own)' || echo refused); issue $([ "$r" = dnb_app ] && echo 'ALLOWED (its own)' || echo refused)"
  nroles=$((nroles + 1))
done
[ "$ctl_w|$ctl_a" = "1|1" ] || fail "the positive controls did not run (dnb_worker $ctl_w, dnb_app $ctl_a)"
echo "refusals, by execution: $nroles login role(s); every one refused the outbox; only dnb_worker may claim; only dnb_app may issue"

# d. residue, and the operator's status untouched.
left=$(sq "SELECT count(*) FROM mt_auth_codes WHERE phone = '$PROBE_PHONE' OR code_hash = 'probe'")
[ "$left" = 0 ] || fail "the execution tests left $left code row(s) behind"
nsusp=$(sq "SELECT count(*) FROM mt_customers WHERE status <> 'active'")
echo "residue: 0 code rows; operators not active now: $nsusp (the rolled-back suspension left nothing)"

step "6/9 restart the API on the new build, and recreate the worker with the SMS settings"
docker restart dnb-staging-api >/dev/null
i=0; until [ "$(code http://127.0.0.1:8099/)" = 200 ]; do i=$((i+1)); [ $i -le 30 ] || break; sleep 1; done
B=$(mktemp "$EV/body.XXXXXX")
c_sess=$(curl -s -o "$B" -w '%{http_code}' http://127.0.0.1:8099/api/v1/admin/session || true)
echo "API: panel $(code http://127.0.0.1:8099/), GET /api/v1/admin/session $c_sess $(cat "$B")"
[ "$c_sess" = 401 ] && grep -q '"provider":"dishnet"' "$B" || fail "the API did not come back with the dishnet provider"
run_worker() {  # run_worker [sms env file]
  docker rm -f dnb-staging-worker >/dev/null 2>&1 || true
  # shellcheck disable=SC2046
  docker run -d --name dnb-staging-worker --network dnb-staging --restart unless-stopped \
    -v "$APP/app:/app:ro" -w /app \
    --env-file "$APP/env/runtime.env" --env-file "$APP/env/secrets.docker.env" $([ -n "${1:-}" ] && echo "--env-file $1") \
    -e DN_DELIVERY=simulated "$IMG" php bin/worker.php >/dev/null
}
run_worker "$SMSFILE" || fail "could not start the worker"
WANT_SMS=null; [ -n "$SMSFILE" ] && WANT_SMS=africastalking
i=0; line=""
while [ $i -le 20 ]; do
  line=$(docker logs dnb-staging-worker 2>&1 | grep -m1 -o '"sms":"[^"]*"' || true)
  [ -n "$line" ] && break
  [ "$(docker inspect -f '{{.State.Running}}' dnb-staging-worker 2>/dev/null || true)" = true ] || break
  i=$((i+1)); sleep 1
done
wlog=$(docker logs dnb-staging-worker 2>&1 | head -3 || true)
if [ "$line" != "\"sms\":\"$WANT_SMS\"" ] || [ "$(docker inspect -f '{{.State.Running}}' dnb-staging-worker 2>/dev/null || true)" != true ]; then
  echo "$wlog" | sed 's/^/  /'
  run_worker "" && echo "  restored: the worker runs again without SMS settings"
  fail "the worker did not start with the SMS binding $WANT_SMS (its first lines are above; they name variables, never values)"
fi
echo "$wlog" | grep -q '"delivery_binding":"simulated-routeros"' || fail "the worker does not report the simulated delivery binding"
printf '%s\n' "$wn" > "$EV/wn.before.$TS"; envnames dnb-staging-worker > "$EV/wn.after.$TS"
added=$(comm -13 "$EV/wn.before.$TS" "$EV/wn.after.$TS" | tr '\n' ' ')
removed_v=$(comm -23 "$EV/wn.before.$TS" "$EV/wn.after.$TS" | grep -vE '^(DN_SMS|DNB_SMS_[A-Z_]+)$' | tr '\n' ' ' || true)
rm -f "$EV/wn.before.$TS" "$EV/wn.after.$TS"
echo "worker: $line, delivery simulated-routeros; variables added: ${added:-none}"
[ -z "$removed_v" ] || fail "the recreated worker lost variables: $removed_v"
echo "$added" | tr ' ' '\n' | grep -vE '^(|DN_SMS|DNB_SMS_USERNAME|DNB_SMS_API_KEY|DNB_SMS_SENDER)$' | grep -q . && fail "the recreated worker gained variables other than the SMS settings: $added"

step "7/9 the operator app: dnb-staging-app, holding four variables and no other credential"
( umask 077
  grep '^DNB_DSN=' "$APP/env/runtime.env"; grep '^DNB_TOKEN_PEPPER=' "$APP/env/runtime.env"
  grep '^DNB_SECRET_KEY=' "$APP/env/runtime.env"; grep '^DNB_APP_PASS=' "$APP/env/secrets.docker.env"
) > "$APP/env/app.env.new"
chmod 0600 "$APP/env/app.env.new"; mv "$APP/env/app.env.new" "$APP/env/app.env"
[ "$(sed -n 's/=.*//p' "$APP/env/app.env" | sort | tr '\n' ' ')" = "DNB_APP_PASS DNB_DSN DNB_SECRET_KEY DNB_TOKEN_PEPPER " ] \
  || fail "$APP/env/app.env does not hold exactly the four variables"
docker rm -f dnb-staging-app >/dev/null 2>&1 || true
docker run -d --name dnb-staging-app --network dnb-staging --restart unless-stopped \
  -p "127.0.0.1:$PORT:$PORT" -p "$GW:$PORT:$PORT" -v "$APP/app:/app:ro" -w /app \
  --env-file "$APP/env/app.env" "$IMG" php -S "0.0.0.0:$PORT" plugin/bin/serve-app.php >/dev/null \
  || fail "could not start dnb-staging-app"
i=0; until [ "$(code "http://127.0.0.1:$PORT/")" = 200 ] && [ "$(code "http://$GW:$PORT/")" = 200 ]; do
  i=$((i+1)); [ $i -le 30 ] || { docker logs dnb-staging-app --tail 10 2>&1 || true; fail "the app does not answer on 127.0.0.1:$PORT and $GW:$PORT"; }; sleep 1
done
echo "app up after ${i}s on 127.0.0.1:$PORT and $GW:$PORT"
an=$(envnames dnb-staging-app | grep -E '^(DNB?_)' | tr '\n' ' ')
echo "its DN_/DNB_ variables: $an"
[ "$an" = "DNB_APP_PASS DNB_DSN DNB_SECRET_KEY DNB_TOKEN_PEPPER " ] || fail "dnb-staging-app holds variables beyond the four: $an"
pb=$(docker inspect -f '{{json .HostConfig.PortBindings}}' dnb-staging-app)
echo "published: $pb"
[ "$(echo "$pb" | grep -o '"HostIp":"[^"]*","HostPort":"[^"]*"' | sort | tr '\n' ' ')" = "\"HostIp\":\"127.0.0.1\",\"HostPort\":\"$PORT\" \"HostIp\":\"$GW\",\"HostPort\":\"$PORT\" " ] \
  || fail "dnb-staging-app is published somewhere other than 127.0.0.1:$PORT and $GW:$PORT"
ss -tln | grep -E "[:.]$PORT[[:space:]]" | grep -qE "(0\.0\.0\.0|\*|\[::\]):$PORT" && fail "$PORT is bound on a wildcard address — not allowed"
L="http://127.0.0.1:$PORT"
csp=$(hdr content-security-policy "$L/")
[ "$csp" = "$CSP" ] || fail "the page's Content-Security-Policy is not the reviewed one: '$csp'"
nf() { b=$(curl -s -X "$1" -H 'Content-Type: application/json' --data '{}' "$L$2" 2>/dev/null || true); c=$(code -X "$1" -H 'Content-Type: application/json' --data '{}' "$L$2"); [ "$c|$b" = "404|not found" ]; }
for p in "GET /api/v1/admin/session" "POST /internal/radius/accounting" "GET /index.php" "GET /plugin/plugin.json"; do
  nf ${p% *} ${p#* } || fail "the app answered ${p} other than its own 404"
done
c_me=$(code "$L/api/v1/me"); c_rc=$(code -X POST -H 'Content-Type: application/json' --data '{}' "$L/api/v1/auth/request-code")
c_js=$(code "$L/app/app.js")
echo "loopback: / 200 with the reviewed CSP; /app/app.js $c_js; /api/v1/me $c_me; an empty sign-in request $c_rc; the Admin API, RADIUS accounting, index.php and the manifest: the app's own 404"
[ "$c_js|$c_me|$c_rc" = "200|401|400" ] || fail "the app's answers are not 200, 401 and 400"

step "8/9 the Traefik route app-staging.dishnetuganda.com (a new file; the Admin route is not touched)"
RNEW="$EV/dnb-staging-app.yml.$TS"
cat > "$RNEW" <<'YAML'
# Domain-B STAGING — the operator app (docs/127 §J). Written by
# scripts/dnb-staging-operator-app.sh; a drop-in beside dnb-staging.yml. Backend:
# dnb-staging-app on the docker bridge gateway only (172.17.0.1:8098). The router
# rules carry the app's allow-list, so any other path is Traefik's own 404 and
# never reaches PHP. Sign-in has its own, stricter per-address rate limit. No
# basic auth: the app has its own sign-in, by a code sent by SMS.
# Delete this file to withdraw the hostname; Traefik drops the route in seconds.
http:
  routers:
    dnb-staging-app:
      rule: "Host(`app-staging.dishnetuganda.com`) && (Path(`/`) || PathPrefix(`/app/`) || PathPrefix(`/pwa/`) || Path(`/api/v1/me`) || PathPrefix(`/api/v1/me/`))"
      entryPoints: ["https"]
      priority: 20
      service: dnb-staging-app
      middlewares: ["dnb-staging-app-ratelimit", "dnb-staging-app-headers"]
      tls:
        certResolver: letsencrypt
    dnb-staging-app-signin:
      rule: "Host(`app-staging.dishnetuganda.com`) && PathPrefix(`/api/v1/auth/`)"
      entryPoints: ["https"]
      priority: 30
      service: dnb-staging-app
      middlewares: ["dnb-staging-app-signin-ratelimit", "dnb-staging-app-headers"]
      tls:
        certResolver: letsencrypt
    dnb-staging-app-http:
      rule: "Host(`app-staging.dishnetuganda.com`)"
      entryPoints: ["http"]
      priority: 10
      service: dnb-staging-app
      middlewares: ["dnb-staging-app-https"]
  middlewares:
    dnb-staging-app-https:
      redirectScheme:
        scheme: https
        permanent: true
    dnb-staging-app-ratelimit:
      rateLimit:
        average: 20
        burst: 50
    dnb-staging-app-signin-ratelimit:
      rateLimit:
        average: 10
        period: 1m
        burst: 10
    dnb-staging-app-headers:
      headers:
        frameDeny: true
        contentTypeNosniff: true
        referrerPolicy: "no-referrer"
        stsSeconds: 15552000
        customResponseHeaders:
          X-Robots-Tag: "noindex, nofollow, noarchive"
  services:
    dnb-staging-app:
      loadBalancer:
        servers:
          - url: "http://172.17.0.1:8098"
YAML
if command -v python3 >/dev/null 2>&1 && python3 -c 'import yaml' 2>/dev/null; then
  python3 - "$RNEW" <<'PY' || fail "the route file is not valid YAML"
import sys, yaml
d = yaml.safe_load(open(sys.argv[1]))
r = d['http']['routers']; m = d['http']['middlewares']
for name, router in r.items():
    for mw in router['middlewares']:
        assert mw in m, mw
assert d['http']['services']['dnb-staging-app']['loadBalancer']['servers'][0]['url'] == 'http://172.17.0.1:8098'
print('  yaml: ok —', ', '.join(sorted(r)))
PY
else
  echo "  (python3-yaml not available on this host; relying on Traefik's own reload below)"
fi
chmod 0644 "$RNEW"; cp "$RNEW" "$TCFG/.dnb-staging-app.yml.tmp" && mv "$TCFG/.dnb-staging-app.yml.tmp" "$ROUTE"; rm -f "$RNEW"
echo "written: $ROUTE (no other file in $TCFG was touched)"
V="--resolve $HOST:443:127.0.0.1"
i=0; until [ "$(hdr content-security-policy -k $V "https://$HOST/")" = "$CSP" ]; do
  i=$((i+1)); [ $i -le 60 ] || { docker logs "$T" --since 3m 2>&1 | grep -iE 'dnb-staging-app|app-staging|error' | tail -10 || true; fail "Traefik did not route $HOST to the app within 60 s; the route file stays in place — paste this output back"; }; sleep 1
done
echo "route active after ${i}s"
i=0; until openssl s_client -connect 127.0.0.1:443 -servername "$HOST" </dev/null 2>/dev/null | openssl x509 -noout -issuer 2>/dev/null | grep -qi "let's encrypt"; do
  i=$((i+1)); [ $i -le 150 ] || { openssl s_client -connect 127.0.0.1:443 -servername "$HOST" </dev/null 2>/dev/null | openssl x509 -noout -issuer -subject -enddate 2>/dev/null || true; fail "the certificate did not arrive within 150 s; the route is in place — paste this output back"; }; sleep 1
done
openssl s_client -connect 127.0.0.1:443 -servername "$HOST" </dev/null 2>/dev/null | openssl x509 -noout -checkhost "$HOST" 2>/dev/null | grep -q 'does match' \
  || fail "the certificate served for $HOST does not name $HOST"
echo "certificate: $(openssl s_client -connect 127.0.0.1:443 -servername "$HOST" </dev/null 2>/dev/null | openssl x509 -noout -issuer | sed 's/^issuer=//'), naming $HOST"
t_root=$(code -k $V "https://$HOST/"); t_js=$(code -k $V "https://$HOST/app/app.js"); t_me=$(code -k $V "https://$HOST/api/v1/me")
sts=$(hdr strict-transport-security -k $V "https://$HOST/")
echo "through Traefik: / $t_root, /app/app.js $t_js, /api/v1/me $t_me; HSTS: ${sts:-none}"
[ "$t_root|$t_js|$t_me" = "200|200|401" ] || fail "through Traefik the app does not answer 200, 200 and 401"
[ -n "$sts" ] || fail "Traefik's headers middleware is not applied (no Strict-Transport-Security)"
for p in "GET /api/v1/admin/session" "POST /internal/radius/accounting" "GET /index.php" "GET /src/autoload.php" "GET /plugin/plugin.json"; do
  m=${p% *}; u=${p#* }
  c=$(code -k $V -X "$m" -H 'Content-Type: application/json' --data '{}' "https://$HOST$u")
  a=$(curl -sk $V -D - -o /dev/null -X "$m" -H 'Content-Type: application/json' --data '{}' "https://$HOST$u" 2>/dev/null | tr -d '\r' | grep -ci '^content-security-policy:' || true)
  [ "$a" = 0 ] || fail "$m $u reached the app through Traefik — the route's allow-list does not hold"
  if [ "$c" = 404 ]; then echo "  $m $u → 404 from Traefik (never reached the app)"; else echo "  WARN: $m $u → $c from Traefik, not 404 — it did not reach the app, but record it"; fi
done
h_http=$(code --resolve "$HOST:80:127.0.0.1" "http://$HOST/")
h_loc=$(hdr location --resolve "$HOST:80:127.0.0.1" "http://$HOST/")
echo "http://$HOST/ → $h_http $h_loc"
case $h_http in 301|302|307|308) ;; *) fail "http://$HOST/ is not redirected";; esac
echo "$h_loc" | grep -q "^https://$HOST" || fail "http://$HOST/ is not redirected to https"
# The sign-in rate limit, measured: empty requests, which the app answers 400
# and which create nothing and send nothing. The limit is per address, so this
# host's own address is the one it spends, for about a minute.
n400=0; n429=0; nother=0; k=0
while [ $k -lt 15 ]; do
  c=$(code -k $V -X POST -H 'Content-Type: application/json' --data '{}' "https://$HOST/api/v1/auth/request-code")
  case $c in 400) n400=$((n400+1));; 429) n429=$((n429+1));; *) nother=$((nother+1));; esac
  k=$((k+1))
done
echo "sign-in rate limit: 15 empty requests from this host → $n400 answered 400 by the app, $n429 refused 429 by Traefik, $nother other"
[ "$n400" -ge 1 ] && [ "$n429" -ge 1 ] && [ "$nother" = 0 ] || fail "the sign-in rate limit did not behave as written (the route file is in place — paste this output back)"
[ "$(docker inspect -f '{{.State.StartedAt}}' "$T")" = "$T_STARTED" ] || fail "Traefik restarted during this run"
echo "Traefik: not restarted (started $T_STARTED)"

step "9/9 result"
docker inspect -f '{{.Name}}|{{.State.Status}}|{{.State.StartedAt}}|{{.RestartCount}}' $(docker ps -aq) | sort > "$EV/operator-app-$TS.after"
changed=$(comm -13 "$EV/operator-app-$TS.before" "$EV/operator-app-$TS.after" | cut -d'|' -f1 | tr -d '/' | sort -u | tr '\n' ' ')
echo "containers new or changed: ${changed:-none}"
[ "$changed" = "dnb-staging-api dnb-staging-app dnb-staging-worker " ] || fail "unexpected container change: ${changed:-none}"
cut -d'|' -f1 "$EV/operator-app-$TS.before" | sort > "$EV/operator-app-$TS.names.before"
cut -d'|' -f1 "$EV/operator-app-$TS.after"  | sort > "$EV/operator-app-$TS.names.after"
removed=$(comm -23 "$EV/operator-app-$TS.names.before" "$EV/operator-app-$TS.names.after" | tr '\n' ' ')
echo "containers removed (must be none): ${removed:-none}"
[ -z "$removed" ] || fail "a container disappeared during this run: $removed"
rm -f "$B"
if [ -n "$KF" ]; then
  leak=$(grep -rlF -f "$KF" "$EV" 2>/dev/null | grep -vxF "$KF" || true)
  [ -z "$leak" ] && echo "the SMS key appears in no file under $EV (this run's log included, as far as it is written)" \
                 || echo "WARNING: the SMS key appears in: $leak — delete those files and replace the key in your Africa's Talking account"
fi
case $SMS_STATE in
  new|kept) SMS_LINE="SMS configured ($(sed -n 's/^DNB_SMS_USERNAME=//p' "$APP/env/sms.env"))"
            grep -q '^DNB_SMS_USERNAME=sandbox$' "$APP/env/sms.env" && SMS_LINE="$SMS_LINE — SANDBOX: codes go to the provider's simulator, NOT to phones" ;;
  *)        SMS_LINE="SMS NOT configured — no code reaches any phone; run this command again to add the key" ;;
esac
echo
echo "=== OPERATOR APP ON STAGING $(date -u +%FT%TZ): https://$HOST/ — $(cat "$APP/app/VERSION"), content digest $DIGEST, migrations $after_n (last $after_last); $SMS_LINE; sign-in refusals proved for $nroles login roles in rolled-back tests, residue 0; the app holds four variables and answers only its own surface; the route carries its allow-list and a sign-in rate limit ==="
echo
echo "Next, by you (nothing below was done by this command):"
echo "  1. In the Admin panel (https://$PORTAL/), open an operator and use 'Add an owner' with the"
echo "     mobile number that should sign in, in international form (+256…)."
case $SMS_STATE in
  new|kept)
    if grep -q '^DNB_SMS_USERNAME=sandbox$' "$APP/env/sms.env"; then
      echo "  2. SANDBOX: the code does not reach a phone; it goes to Africa's Talking's test environment."
      echo "     To reach phones, run this command again with SMS=replace and your live username and key."
    else
      echo "  2. On that phone, open https://$HOST/ and enter the same number. The code arrives by SMS."
    fi ;;
  *)
    echo "  2. Nobody can sign in yet: no SMS key is set, so no code is sent. When you have your"
    echo "     Africa's Talking username and API key, run this same command again from an SSH session"
    echo "     and type them when it asks." ;;
esac
echo "  3. Tell me only what the screen says. Never send the code itself."
echo
echo "Rollback, if ever needed (in this order: a container mounts the tree it is started on, so the"
echo "previous tree goes back first):"
echo "  rm $ROUTE                     # withdraws the hostname within seconds"
echo "  docker rm -f dnb-staging-app"
echo "  mv $APP/app $APP/app.failed-$TS && mv $APP/app.prev-$TS $APP/app"
echo "  docker rm -f dnb-staging-worker && docker run -d --name dnb-staging-worker --network dnb-staging --restart unless-stopped -v $APP/app:/app:ro -w /app --env-file $APP/env/runtime.env --env-file $APP/env/secrets.docker.env -e DN_DELIVERY=simulated $IMG php bin/worker.php"
echo "  docker restart dnb-staging-api"
echo "  (031 and 032 stay: the Admin plane never calls the sign-in functions.)"
case $SMS_STATE in new|kept) echo "  (The SMS key stays in $APP/env/sms.env, mode 0600. Delete that file to take it off this server.)" ;; esac
echo
echo "Send back the LOG FILE, not the terminal. This prints it:"
echo "  cat \"\$(ls -t $EV/operator-app-*.log | head -1)\""
echo "Your SMS key is in neither."
