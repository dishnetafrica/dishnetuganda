#!/bin/bash
# scenarios.sh — rehearse scripts/dnb-staging-operator-app.sh (docs/127 §J)
# against a sandbox rebuilt, from nothing, into the state the staging server is
# in now:
#   the 028 build at digest 1bc95524…, the simulated estate, the stage-2 route,
#   the worker as stage 1 created it, the API switched to the real DishNet staff
#   login by the REAL scripts/dnb-staging-staff-login.sh (docs/122), then the
#   REAL 029 command (commit 938c002, as run 2026-09-24 04:14 UTC) and the REAL
#   030 command (commit b9aa7da, as run 04:47 UTC), byte for byte.
# The script under test is the real one, read from the working tree. Only
# `docker` and `getent` are faked (bin/), and Traefik (traefik.py); every
# database is real PostgreSQL, every server a real `php -S`, and the worker the
# real bin/worker.php.
#
#   HSIM_SANDBOX=yes-this-is-not-the-server bash scripts/harness/operator-app/scenarios.sh
#
# The build the command fetches comes from a bare clone of THIS repository's
# committed history, so the pinned commit must be committed first.
set -uo pipefail
. "$(dirname "$0")/../staff-login/guard.sh"
. "$(dirname "$0")/../staff-login/lib.sh"
S=$HSIM/state; APPD=/opt/dnb-staging; T=/etc/easypanel/traefik/config
PGH=${HSIM_PGHOST:-/var/tmp}; PGP=${HSIM_PGPORT:-55432}
D028=1bc95524cd36f38413b5325fe26cdf76a20cb9d67e4253051c5b0bae7a7af74b
D029=780023ff04545bb8b5eb921c63b9e7bb7d885b3079ecc6069c1797d89b1c46d0
D030=4a6291849f0b687d2472909dd1e416af837227dcf648fd41fb99fc5c7a5fd571
S029=c558e26a1d467d9e1d3bd210f9ffb0e3719aca552ed66bfbbaf54e7936dc9e3c   # the 029 command as the operator ran it
S030=0000f7588e0e8aa0c45397b66d44a71b0f014756e43a934f9598b47d0f32e8b4   # the 030 command as the operator ran it
SCRIPT=$REPO/scripts/dnb-staging-operator-app.sh
D032=$(grep -m1 '^DIGEST=' "$SCRIPT" | cut -d= -f2)
PIN=$(grep -m1 '^COMMIT=' "$SCRIPT" | cut -d= -f2)
HOST=app-staging.dishnetuganda.com
KEY=hsimKEY$(openssl rand -hex 20)          # an invented key: it must appear in no log, screen or file
SMSUSER=dishnet-hsim
rm -f "$S/results"
ADDED_GW=0
pg() { psql -h "$PGH" -p "$PGP" -U postgres -d postgres -Atc "$1"; }

cleanup() {
  docker rm -f dnb-staging-api >/dev/null 2>&1 || true
  docker rm -f dnb-staging-app >/dev/null 2>&1 || true
  pkill -f '[o]perator-app/traefik[.]py' 2>/dev/null || true
  pkill -f '[s]taff-login/traefik[.]py' 2>/dev/null || true
  pkill -f '[h]sim-port-squatter' 2>/dev/null || true
  # Role memberships are CLUSTER-WIDE: none planted here may outlive the run.
  sql "REVOKE dnb_worker FROM dnb_app" >/dev/null 2>&1 || true
  for n in 029 030; do pg "DROP DATABASE IF EXISTS dnb_hsim_snap$n" >/dev/null 2>&1 || true; done
  [ "$ADDED_GW" = 1 ] && ip addr del 172.17.0.1/32 dev lo 2>/dev/null || true
}
trap cleanup EXIT

digest() { sha256sum "$1/SHA256SUMS" 2>/dev/null | cut -d' ' -f1; }
ledger() { sql "SELECT count(*) || '|' || max(filename) FROM mt_migrations"; }
outbox() { sql "SELECT coalesce(to_regclass('public.mt_auth_sms_outbox')::text, 'absent')"; }
probe_rows() { sql "SELECT count(*) FROM mt_auth_codes WHERE phone = '+256799000000' OR code_hash = 'probe'"; }
calls() { grep -cE "docker $1" "$S/calls.log" || true; }
app_names() { grep -oE '^(DN|DNB)_[A-Z_]+' "$S/app.env" 2>/dev/null | sort | tr '\n' ' '; }
via() { curl -sk --resolve "$HOST:443:127.0.0.1" "$@"; }

start_traefik() {
  pkill -f '[o]perator-app/traefik[.]py' 2>/dev/null || true
  pkill -f '[s]taff-login/traefik[.]py' 2>/dev/null || true
  for _ in $(seq 50); do (exec 3<>/dev/tcp/127.0.0.1/443) 2>/dev/null || break; sleep 0.1; done
  setsid nohup python3 "$HSIM_HOME/traefik.py" >> "$S/traefik.stdout" 2>&1 < /dev/null &
  for _ in $(seq 50); do (exec 3<>/dev/tcp/127.0.0.1/443) 2>/dev/null && (exec 3<>/dev/tcp/127.0.0.1/80) 2>/dev/null && return 0; sleep 0.2; done
  echo "the fake Traefik did not start"; cat "$S/traefik.stdout"; exit 1
}
# The worker exactly as stage 1 created it (docs/120 §15 block B).
stage1_worker() {
  docker rm -f dnb-staging-worker >/dev/null 2>&1
  docker run -d --name dnb-staging-worker --network dnb-staging --restart unless-stopped \
    -v "$APPD/app:/app:ro" -w /app --env-file "$APPD/env/runtime.env" --env-file "$APPD/env/secrets.docker.env" \
    -e DN_DELIVERY=simulated dnb-staging-php:8.3 php bin/worker.php > /dev/null
}
# run <name> [script] [env…]: the command, as the operator runs it — `sh … 2>&1 | tee log`
# — with NO terminal (setsid, stdin closed): the SMS question is skipped.
run() {
  local n=$1 sc=${2:-$SCRIPT}; shift; [ $# -gt 0 ] && shift
  : > "$S/calls.log"; rm -rf /root/dnb-staging-evidence
  env "$@" DNB_REDEPLOY_REPO="file://$HSIM/remote/dishnetuganda" setsid -w bash -c \
    "{ sh '$sc' 2>&1; echo EXIT=\$?; } | tee '$HSIM/$n.log' > /dev/null" < /dev/null
}
# runtty <name> <answers-json> [script] [env…]: the same, on a real terminal, typing the answers.
runtty() {
  local n=$1 ans=$2 sc=${3:-$SCRIPT}; shift 2; [ $# -gt 0 ] && shift
  : > "$S/calls.log"; rm -rf /root/dnb-staging-evidence
  env "$@" DNB_REDEPLOY_REPO="file://$HSIM/remote/dishnetuganda" python3 "$HSIM_HOME/drive.py" "$HSIM/$n.screen" "$ans" -- \
    bash -c "{ sh '$sc' 2>&1; echo EXIT=\$?; } | tee '$HSIM/$n.log'"
  echo "DRIVE=$?" >> "$HSIM/$n.drive"
}
TYPE_SMS="[[\"username: \", \"$SMSUSER\"], [\"typing is hidden): \", \"$KEY\"], [\"press Enter for none): \", \"DishNet\"]]"
TYPE_SANDBOX="[[\"username: \", \"sandbox\"], [\"typing is hidden): \", \"$KEY\"], [\"press Enter for none): \", \"\"]]"
# The key must appear nowhere a copy of the terminal, the log or the evidence could carry it.
nokey() { ! grep -rqF -- "$KEY" "$HSIM/$1.log" "$HSIM/$1.screen" /root/dnb-staging-evidence 2>/dev/null; }

snapshot() {
  pg "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = 'dnb_hsim' AND pid <> pg_backend_pid()" > /dev/null
  pg "DROP DATABASE IF EXISTS dnb_hsim_snap$1" > /dev/null && pg "CREATE DATABASE dnb_hsim_snap$1 TEMPLATE dnb_hsim" > /dev/null
  tar -czf "$HSIM/snap$1.tgz" -C / "${APPD#/}" "${T#/}" \
      $(cd / && for f in api.env worker.env worker.log worker.running worker.appdir containers traefik.src; do [ -e "${S#/}/$f" ] && echo "${S#/}/$f"; done)
}
restore() {
  docker rm -f dnb-staging-app >/dev/null 2>&1 || true
  [ -f "$S/api.pid" ] && { kill "$(cat "$S/api.pid")" 2>/dev/null; sleep 0.3; }
  pkill -f 'php -S 127.0.0.1:8099' 2>/dev/null || true; pkill -f 'serve-app[.]php' 2>/dev/null || true
  pkill -f '[h]sim-port-squatter' 2>/dev/null || true
  sql "REVOKE dnb_worker FROM dnb_app" >/dev/null 2>&1 || true
  pg "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = 'dnb_hsim' AND pid <> pg_backend_pid()" > /dev/null
  # OWNER dnb, as reset.sh creates it: since PostgreSQL 15 schema public belongs to the
  # DATABASE OWNER (pg_database_owner), so a copy owned by postgres would deny the
  # installing role CREATE there — measured: the installer then refused.
  pg "DROP DATABASE dnb_hsim" > /dev/null && pg "CREATE DATABASE dnb_hsim TEMPLATE dnb_hsim_snap$1 OWNER dnb" > /dev/null
  rm -rf "${APPD:?}"/app* "${APPD:?}"/env "${T:?}"/*
  rm -f "$S"/api.* "$S"/app.* "$S"/worker.* "$S/containers" "$S/inject.log" "$S/calls.log"
  tar -xzf "$HSIM/snap$1.tgz" -C /
  mapfile -t list < "$S/api.env"
  ( cd "$APPD/app" && exec setsid nohup env -i "${list[@]}" php -S 127.0.0.1:8099 plugin/bin/serve.php ) >> "$S/api.log" 2>&1 < /dev/null &
  echo $! > "$S/api.pid"
  for _ in $(seq 50); do [ "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8099/)" = 200 ] && break; sleep 0.2; done
  start_traefik
  echo "$HOST 209.97.137.203" > "$S/dns"
  unset HSIM_PRE_INSTALL_SQL HSIM_POST_INSTALL_SQL HSIM_PUBLISH_MODE
  : > "$S/calls.log"; rm -rf /root/dnb-staging-evidence
}

echo "== setup: the sandbox host, the 028 build, the 029 and 030 commands as run, a bare clone with the branch moved on"
if ! ip -4 addr show lo | grep -q ' 172.17.0.1/'; then ip addr add 172.17.0.1/32 dev lo && ADDED_GW=1; fi
check "the docker bridge gateway address exists here (172.17.0.1, on loopback)" bash -c "ip -4 addr show lo | grep -q ' 172.17.0.1/'"
[ -f "$HSIM/tls-le.crt" ] || openssl req -x509 -newkey rsa:2048 -nodes -keyout "$HSIM/tls-le.key" -out "$HSIM/tls-le.crt" -days 30 \
  -subj "/O=Let's Encrypt/CN=R11" -addext "subjectAltName=DNS:portal-staging.dishnetuganda.com,DNS:$HOST" >/dev/null 2>&1
start_traefik
if [ ! -f "$HSIM/build028.tar.gz" ]; then
  W=$HSIM/b028; rm -rf "$W"; mkdir -p "$W/src" "$W/x"
  git -C "$REPO" archive 9f95353 dishnet-hybrid-sudan/dishnet-mikrotik-control-plane | tar -x -C "$W/src"
  sh "$W/src/dishnet-hybrid-sudan/dishnet-mikrotik-control-plane/plugin/bin/package.sh" "$W/dist" > "$W/pkg.out" 2>&1
  t=$(ls "$W"/dist/*.tar.gz); tar -xzf "$t" -C "$W/x" --strip-components=1
  [ "$(digest "$W/x")" = "$D028" ] || { echo "the 028 build does not reproduce its digest"; exit 1; }
  cp "$t" "$HSIM/build028.tar.gz"
fi
git -C "$REPO" show 938c002:scripts/dnb-staging-redeploy.sh > "$HSIM/redeploy-029.sh"
git -C "$REPO" show b9aa7da:scripts/dnb-staging-redeploy.sh > "$HSIM/redeploy-030.sh"
check "the 029 command is byte-for-byte the one the operator ran" eq "$(sha256sum "$HSIM/redeploy-029.sh" | cut -d' ' -f1)" "$S029"
check "the 030 command is byte-for-byte the one the operator ran" eq "$(sha256sum "$HSIM/redeploy-030.sh" | cut -d' ' -f1)" "$S030"
rm -rf "$HSIM/remote"; mkdir -p "$HSIM/remote"
git clone -q --bare "$REPO" "$HSIM/remote/dishnetuganda.git"
git -C "$HSIM/remote/dishnetuganda.git" cat-file -e "$PIN^{commit}" || { echo "the pinned commit $PIN is not in this repository"; exit 1; }
W=$HSIM/remote/work; git clone -q -b claude/study-this-jhe2eg "$HSIM/remote/dishnetuganda.git" "$W"
echo "// a later change on the branch (operator-app harness)" >> "$W/dishnet-hybrid-sudan/dishnet-mikrotik-control-plane/src/autoload.php"
git -C "$W" -c user.email=harness@localhost -c user.name=harness commit -qam "a later change on the branch"
git -C "$W" push -q origin claude/study-this-jhe2eg
echo "   pinned $PIN; branch tip now $(git -C "$HSIM/remote/dishnetuganda.git" rev-parse --short claude/study-this-jhe2eg)"

echo "   building the staging state: 028 → estate → stage-1 worker → real staff login → 029 → 030"
rm -rf "${APPD:?}"/app "${APPD:?}"/app.*; mkdir -p "$APPD/app"; tar -xzf "$HSIM/build028.tar.gz" -C "$APPD/app" --strip-components=1
# reset.sh drops the database AS dnb, silently (2>/dev/null under set -e). A database
# left by an interrupted run, owned by another role, would stop it without a word —
# measured — so the superuser removes whatever is there first.
pg "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = 'dnb_hsim' AND pid <> pg_backend_pid()" > /dev/null
pg "DROP DATABASE IF EXISTS dnb_hsim" > /dev/null
bash "$REPO/scripts/harness/staff-login/reset.sh" 127.0.0.1 > "$HSIM/reset.out" 2>&1 || { tail -20 "$HSIM/reset.out"; exit 1; }
stage1_worker
bash "$REPO/scripts/harness/staff-login/run.sh" switch notty
grep -q '^EXIT=0$' "$HSIM/switch.log" || { echo "the staff-login switch did not complete:"; tail -25 "$HSIM/switch.log"; exit 1; }
: > "$S/calls.log"
{ DNB_REDEPLOY_REPO="file://$HSIM/remote/dishnetuganda" sh "$HSIM/redeploy-029.sh" 2>&1; echo "EXIT=$?"; } > "$HSIM/setup029.log" < /dev/null
grep -q '^EXIT=0$' "$HSIM/setup029.log" || { echo "the 029 command did not complete:"; tail -25 "$HSIM/setup029.log"; exit 1; }
check "setup: the 029 command left ledger 29 and the 029 build" eq "$(ledger)|$(digest "$APPD/app")" "29|029_o1_site_service_same_operator.sql|$D029"
snapshot 029
{ DNB_REDEPLOY_REPO="file://$HSIM/remote/dishnetuganda" sh "$HSIM/redeploy-030.sh" 2>&1; echo "EXIT=$?"; } > "$HSIM/setup030.log" < /dev/null
grep -q '^EXIT=0$' "$HSIM/setup030.log" || { echo "the 030 command did not complete:"; tail -25 "$HSIM/setup030.log"; exit 1; }
check "setup: the 030 command left ledger 30 and the 030 build" eq "$(ledger)|$(digest "$APPD/app")" "30|030_admin_operator_onboarding.sql|$D030"
check "setup: the worker runs as stage 1 made it, delivery simulated" has "$S/worker.log" '"delivery_binding":"simulated-routeros"'
check "setup: the 030 build's worker knows nothing of SMS"          hasnt "$S/worker.log" '"sms":'
snapshot 030

echo; echo "== O1 the staging state now, no terminal: 031 and 032 applied, verified; the app and its route in place; SMS skipped"
restore 030
run o1; L=$HSIM/o1.log
check "exit 0"                                         has "$L" '^EXIT=0$'
check "the real staff login found"                     has "$L" '^API identity: the real DishNet staff login'
check "030 found applied; 031 and 032 pending"         has "$L" '^ok: migration 030 is applied'
check "DNS found"                                      has "$L" "^DNS: $HOST → 209.97.137.203"
check "port 8098 free"                                 has "$L" '^port 8098: free$'
check "no terminal: SMS skipped, and said so"          has "$L" '^skipped: no terminal to type the key into'
check "the pinned commit was built, not the tip"       has "$L" "^$PIN\$"
check "artifact-ok with the reviewed digest"           has "$L" "^artifact-ok: .*content digest $D032"
check "doctor's warning: 30 of 32 applied, printed"    has "$L" 'warn +plugin schema +30 of 32 migration\(s\) applied'
check "doctor's warning: no SMS sender, printed"       has "$L" 'warn +sign-in codes by SMS +DN_SMS unset .*no operator can sign in'
check "doctor in production posture, no blocker"       has "$L" '^doctor \(production posture\): no blocker$'
check "the installer applied exactly 031 and 032"      has "$L" 'applied 2 migration\(s\): 031_sign_in_requires_active_operator.sql, 032_sign_in_codes_by_sms.sql'
check "ledger 32, latest 032"                          has "$L" '^migrations after: 32 \(last 032_sign_in_codes_by_sms.sql\)'
check "outbox owned by dnb_def_auth"                   has "$L" '^the outbox mt_auth_sms_outbox: owner dnb_def_auth$'
check "code issue: 032's four-argument form only"      has "$L" '^code issue takes: p_phone text, p_code_hash text, p_ttl interval, p_sealed text$'
check "six functions, SECURITY DEFINER, dnb_def_auth's" has "$L" '6 of 6$'
check "grants: sign-in to dnb_app, SMS to dnb_worker"  has "$L" '^EXECUTE, beyond the owner: mt_auth_issue_code:dnb_app mt_auth_resolve_token:dnb_app mt_auth_sms_claim:dnb_worker mt_auth_sms_expire:dnb_worker mt_auth_sms_settle:dnb_worker mt_auth_verify_code:dnb_app$'
check "probe: an unknown number, no message"           has "$L" '^  unknown\|no_recipient\|true$'
check "probe: an active person, queued"                has "$L" '^  person\|queued\|false$'
check "probe: the operator suspended, no message (031)" has "$L" '^  suspended\|no_recipient\|true$'
check "no phone number printed anywhere"               hasnt "$L" '\+256[0-9a-f]{6,}'
check "dnb_app: only issue allowed"                    has "$L" '^  dnb_app: outbox refused; claim refused; issue ALLOWED \(its own\)$'
check "dnb_worker: only claim allowed"                 has "$L" '^  dnb_worker: outbox refused; claim ALLOWED \(its own\); issue refused$'
check "the owner refused everything"                   has "$L" '^  dnb: outbox refused; claim refused; issue refused$'
# pg is a function of THIS shell, so it is called here, never inside `bash -c`: the
# first version did, read an empty string there, and failed on a correct run.
NLOGIN=$(pg "SELECT count(*) FROM pg_roles WHERE rolcanlogin AND NOT rolsuper")
check "control: the cluster has login roles to test ($NLOGIN)" bash -c "[ '$NLOGIN' -ge 9 ]"
check "every login role in the cluster was tested"     eq "$(grep -cE '^  [a-z0-9_]+: outbox refused' "$L")" "$NLOGIN"
check "residue 0, by the script"                       has "$L" '^residue: 0 code rows'
check "worker recreated, sms null, delivery simulated" has "$L" '^worker: "sms":"null", delivery simulated-routeros; variables added: none$'
check "the app: exactly four variables"                has "$L" '^its DN_/DNB_ variables: DNB_APP_PASS DNB_DSN DNB_SECRET_KEY DNB_TOKEN_PEPPER $'
check "the app: published on the two addresses only"  has "$L" '^published: \{"8098/tcp":\[\{"HostIp":"127.0.0.1","HostPort":"8098"\},\{"HostIp":"172.17.0.1","HostPort":"8098"\}\]\}$'
check "the app on loopback: 200/401/400 and the 404s"  has "$L" '^loopback: / 200 with the reviewed CSP; /app/app.js 200; /api/v1/me 401; an empty sign-in request 400;'
check "the route written; nothing else in the dir"     has "$L" "^written: $T/dnb-staging-app.yml"
check "certificate names the host"                     has "$L" "^certificate: .*Let's Encrypt.*, naming $HOST\$"
check "through Traefik 200/200/401 with HSTS"          has "$L" '^through Traefik: / 200, /app/app.js 200, /api/v1/me 401; HSTS: max-age=15552000$'
check "refusals are Traefik's 404, never the app"      bash -c "[ \"\$(grep -c '→ 404 from Traefik (never reached the app)' '$L')\" = 5 ]"
check "http → https"                                   has "$L" "^http://$HOST/ → 301 https://$HOST/\$"
check "sign-in rate limit: 10 by the app, 5 refused"   has "$L" '^sign-in rate limit: 15 empty requests from this host → 10 answered 400 by the app, 5 refused 429 by Traefik, 0 other$'
check "Traefik not restarted"                          has "$L" '^Traefik: not restarted'
check "exactly the three containers new or changed"    has "$L" '^containers new or changed: dnb-staging-api dnb-staging-app dnb-staging-worker $'
check "none removed"                                   has "$L" '^containers removed \(must be none\): none$'
check "result: SMS NOT configured, said plainly"       has "$L" '^=== OPERATOR APP ON STAGING .*SMS NOT configured'
check "next steps: nobody can sign in until a key is added" has "$L" '^  2\. Nobody can sign in yet: no SMS key is set'
check "the rollback puts the tree back before any container starts" has "$L" '^Rollback, if ever needed \(in this order'
check "asks for the log file, not the terminal"        has "$L" '^Send back the LOG FILE, not the terminal'
check "no note about a key when none was set"          hasnt "$L" 'The SMS key stays in'
check "no warning raised by the script"                hasnt "$L" '^  WARN:|^WARNING:'
check "database: ledger 32, outbox present"            eq "$(ledger)|$(outbox)" "32|032_sign_in_codes_by_sms.sql|mt_auth_sms_outbox"
check "database: no probe row survived"                eq "$(probe_rows)" 0
check "database: every operator still active"          eq "$(sql "SELECT count(*) FROM mt_customers WHERE status <> 'active'")" 0
check "sandbox: the app holds the four and no other DN/DNB variable" eq "$(app_names)" "DNB_APP_PASS DNB_DSN DNB_SECRET_KEY DNB_TOKEN_PEPPER "
check "sandbox: env/app.env is mode 0600"              eq "$(stat -c %a "$APPD/env/app.env")" 600
check "sandbox: no sms.env, no sms.env.new"            bash -c "[ ! -e $APPD/env/sms.env ] && [ ! -e $APPD/env/sms.env.new ]"
check "sandbox: the worker has no SMS variable"        hasnt "$S/worker.env" '^(DN_SMS|DNB_SMS_)'
check "sandbox: the Admin route file untouched"        eq "$(sha256sum "$T/dnb-staging.yml" | cut -d' ' -f1)" "$(tar -xzOf "$HSIM/snap030.tgz" "${T#/}/dnb-staging.yml" | sha256sum | cut -d' ' -f1)"
check "sandbox: exactly one new route file"            eq "$(ls "$T" | tr '\n' ' ')" "dnb-staging-app.yml dnb-staging.yml "
check "outside: /internal through Traefik is Traefik's own 404" eq "$(via -X POST https://$HOST/internal/radius/accounting)" "404 page not found"
check "outside: the portal still answers through Traefik" eq "$(curl -sk -o /dev/null -w '%{http_code}' --resolve portal-staging.dishnetuganda.com:443:127.0.0.1 https://portal-staging.dishnetuganda.com/)" 200
check "outside: the tree is the reviewed build"        eq "$(digest "$APPD/app")" "$D032"

echo; echo "== O2 on a terminal, the SMS key typed: kept at mode 0600, handed to the worker, shown nowhere"
restore 030
runtty o2 "$TYPE_SMS"; L=$HSIM/o2.log
check "exit 0"                                         has "$L" '^EXIT=0$'
check "the username is echoed in the log"              has "$L" "^SMS: username $SMSUSER, key typed \\(not shown\\), sender DishNet\$"
check "doctor: SMS set, value withheld"                has "$L" 'value withheld'
check "worker: sms africastalking"                     has "$L" '^worker: "sms":"africastalking", delivery simulated-routeros; variables added: DNB_SMS_API_KEY DNB_SMS_SENDER DNB_SMS_USERNAME DN_SMS $'
check "the script's own leak check: none"              has "$L" '^the SMS key appears in no file under /root/dnb-staging-evidence'
check "result: SMS configured"                         has "$L" "^=== OPERATOR APP ON STAGING .*SMS configured \\($SMSUSER\\)"
check "next steps: the code arrives by SMS"            has "$L" '^  2\. On that phone, open https://app-staging.dishnetuganda.com/ and enter the same number'
check "the rollback says where the key stays"          has "$L" 'The SMS key stays in /opt/dnb-staging/env/sms.env, mode 0600'
check "THE KEY: not in the log, the screen or the evidence" nokey o2
check "the screen shows the hidden prompt and the username" bash -c "grep -q 'typing is hidden' '$HSIM/o2.screen' && grep -q '$SMSUSER' '$HSIM/o2.screen'"
check "sms.env: mode 0600, the four lines"             eq "$(stat -c %a "$APPD/env/sms.env")|$(sed 's/=.*//' "$APPD/env/sms.env" | tr '\n' ' ')" "600|DN_SMS DNB_SMS_USERNAME DNB_SMS_API_KEY DNB_SMS_SENDER "
check "sms.env holds the key typed"                    grep -qxF "DNB_SMS_API_KEY=$KEY" "$APPD/env/sms.env"
check "no sms.env.new left"                            bash -c "[ ! -e $APPD/env/sms.env.new ]"
check "the worker holds the key, the app does not"     bash -c "grep -qxF 'DNB_SMS_API_KEY=$KEY' '$S/worker.env' && ! grep -q '^DNB_SMS_' '$S/app.env'"
check "the worker log names the binding, not the key"  bash -c "grep -q '\"sms\":\"africastalking\"' '$S/worker.log' && ! grep -qF -- '$KEY' '$S/worker.log'"

echo; echo "== O3 run again: everything already in place, the SMS settings kept, re-verified"
run o3; L=$HSIM/o3.log
check "exit 0"                                         has "$L" '^EXIT=0$'
check "notes the build is already deployed"            has "$L" '^NOTE: the reviewed build is already deployed'
check "notes 031 and 032 are already applied"          has "$L" '^NOTE: 031 and 032 are already applied'
check "notes the app and the route exist"              bash -c "grep -q '^NOTE: dnb-staging-app exists from an earlier run' '$L' && grep -q 'exists from an earlier run; it will be rewritten' '$L'"
check "the SMS settings kept"                          has "$L" '^kept: the SMS settings from an earlier run'
check "the installer applied nothing"                  has "$L" 'schema already current, no migration applied'
check "worker still africastalking, nothing added"     has "$L" '^worker: "sms":"africastalking", delivery simulated-routeros; variables added: none$'
check "result line"                                    has "$L" '^=== OPERATOR APP ON STAGING .*SMS configured'
check "THE KEY: nowhere"                               nokey o3

echo; echo "== O4 SMS=replace on a terminal with the sandbox username: replaced, and SANDBOX said plainly"
runtty o4 "$TYPE_SANDBOX" "$SCRIPT" SMS=replace; L=$HSIM/o4.log
check "exit 0"                                         has "$L" '^EXIT=0$'
check "the sandbox is named at the prompt's answer"    has "$L" "SANDBOX: messages go to the provider's simulator, not to phones"
check "result: SANDBOX, not to phones"                 has "$L" "SANDBOX: codes go to the provider's simulator, NOT to phones"
check "next steps: sandbox, not a phone"               has "$L" '^  2\. SANDBOX: the code does not reach a phone'
check "sms.env replaced: sandbox, no sender"           eq "$(sed -n 's/^DNB_SMS_USERNAME=//p' "$APPD/env/sms.env")|$(grep -c '^DNB_SMS_SENDER=' "$APPD/env/sms.env")" "sandbox|0"
check "THE KEY: nowhere"                               nokey o4

echo; echo "== O5 no DNS record yet: refused in step 0, nothing changed"
restore 030; rm -f "$S/dns"
run o5; L=$HSIM/o5.log
check "exit non-zero"                                  hasnt "$L" '^EXIT=0$'
check "says: create the DNS record first"              has "$L" "$HOST does not resolve to 209.97.137.203 \\(got: nothing\\). Create that DNS A record first"
check "the refusal asks for the log file"              has "$L" '^Send back the log file, not the terminal'
check "no build, no run, no restart"                   eq "$(calls 'run')|$(calls 'restart')" "0|0"
check "ledger 30, no route, no app, tree unchanged"    bash -c "[ '$(ledger)' = '30|030_admin_operator_onboarding.sql' ] && [ ! -e $T/dnb-staging-app.yml ] && ! grep -q dnb-staging-app $S/containers && [ \"\$(sha256sum $APPD/app/SHA256SUMS | cut -d' ' -f1)\" = $D030 ]"

echo; echo "== O6 030 NOT applied (the post-029 state): refused in step 0, nothing changed"
restore 029
run o6; L=$HSIM/o6.log
check "exit non-zero"                                  hasnt "$L" '^EXIT=0$'
check "says it applies 031 and 032 on top of 030 only" has "$L" 'applies 031 and 032 on top of 030 and nothing else, and the ledger reads 29'
check "no build, no run, no restart; ledger 29"        eq "$(calls 'run')|$(calls 'restart')|$(ledger)" "0|0|29|029_o1_site_service_same_operator.sql"

echo; echo "== O7 a wrong digest pinned (a copy of the script): refused before anything changes"
restore 030
sed "s/^DIGEST=.*/DIGEST=$(printf '0%.0s' $(seq 64))/" "$SCRIPT" > "$HSIM/wrongdigest.sh"
run o7 "$HSIM/wrongdigest.sh"; L=$HSIM/o7.log
check "exit non-zero"                                  hasnt "$L" '^EXIT=0$'
check "says the digest is not the reviewed build"      has "$L" 'is not the reviewed build 0{64} — nothing was changed'
check "tree, ledger, containers unchanged"             bash -c "[ \"\$(sha256sum $APPD/app/SHA256SUMS | cut -d' ' -f1)\" = $D030 ] && ! ls -d $APPD/app.new-* >/dev/null 2>&1 && [ '$(ledger)' = '30|030_admin_operator_onboarding.sql' ] && ! grep -q dnb-staging-app $S/containers"

echo; echo "== O8 a default privilege that would hand dnb_app the outbox: 032 refuses by itself; the tree goes back; the typed key is not kept"
restore 030
runtty o8 "$TYPE_SMS" "$SCRIPT" HSIM_PRE_INSTALL_SQL="ALTER DEFAULT PRIVILEGES FOR ROLE dnb_def_auth IN SCHEMA public GRANT SELECT ON TABLES TO dnb_app"; L=$HSIM/o8.log
check "the fault was planted"                          has "$S/inject.log" '^ALTER DEFAULT PRIVILEGES$'
check "exit non-zero"                                  hasnt "$L" '^EXIT=0$'
check "032's own check refused, naming the outbox"     has "$L" '032: some role other than the owner holds a privilege on mt_auth_sms_outbox'
check "the installer refused; the tree is back"        has "$L" 'the installer refused .* The previous tree is back at'
check "tree: the 030 build is live again"              eq "$(digest "$APPD/app")" "$D030"
# The Migrator runs each file in its own transaction: 031 committed before 032
# refused. Measured on the first run of this harness, and reported by the script.
check "ledger 31: 031 stayed, 032 did not; no outbox"  eq "$(ledger)|$(outbox)" "31|031_sign_in_requires_active_operator.sql|absent"
check "the script reports the ledger as it now stands" has "$L" '^migrations now: 31 \(last 031_sign_in_requires_active_operator.sql\)\. The installer applies each file in its own transaction'
check "the previous build's call still exists: 031 kept the three-argument issue function" eq "$(sql "SELECT count(*) FROM pg_proc WHERE proname = 'mt_auth_issue_code' AND pronargs = 3")" 1
check "the Admin panel still answers on the previous build" eq "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8099/)" 200
check "no sms.env and no sms.env.new"                  bash -c "[ ! -e $APPD/env/sms.env ] && [ ! -e $APPD/env/sms.env.new ]"
check "no restart, no app, no route"                   bash -c "[ \"\$(grep -c 'docker restart' $S/calls.log)\" = 0 ] && ! grep -q dnb-staging-app $S/containers && [ ! -e $T/dnb-staging-app.yml ]"
check "THE KEY: nowhere"                               nokey o8
sql "ALTER DEFAULT PRIVILEGES FOR ROLE dnb_def_auth IN SCHEMA public REVOKE SELECT ON TABLES FROM dnb_app" > /dev/null

echo; echo "== O8b the fault removed, the same command again: it continues from ledger 31, applies only 032, and completes"
runtty o8b "$TYPE_SMS"; L=$HSIM/o8b.log
check "exit 0"                                         has "$L" '^EXIT=0$'
check "notes 031 applied and 032 pending"              has "$L" '^NOTE: 031 is applied and 032 is pending'
check "doctor's warning: 31 of 32 applied, printed"    has "$L" 'warn +plugin schema +31 of 32 migration\(s\) applied'
check "the installer applied exactly 032"              has "$L" 'applied 1 migration\(s\): 032_sign_in_codes_by_sms.sql'
check "ledger 32, latest 032"                          has "$L" '^migrations after: 32 \(last 032_sign_in_codes_by_sms.sql\)'
check "probe: the operator suspended, no message (031)" has "$L" '^  suspended\|no_recipient\|true$'
check "worker: sms africastalking"                     has "$L" '^worker: "sms":"africastalking", delivery simulated-routeros; variables added: DNB_SMS_API_KEY DNB_SMS_SENDER DNB_SMS_USERNAME DN_SMS $'
check "result: SMS configured"                         has "$L" "^=== OPERATOR APP ON STAGING .*SMS configured \\($SMSUSER\\)"
check "database: ledger 32, outbox present"            eq "$(ledger)|$(outbox)" "32|032_sign_in_codes_by_sms.sql|mt_auth_sms_outbox"
check "tree: the reviewed build"                       eq "$(digest "$APPD/app")" "$D032"
check "THE KEY: nowhere"                               nokey o8b

echo; echo "== O9 a role membership after the installer (dnb_app becomes dnb_worker): the catalogue cannot see it; the execution test must"
restore 030
run o9 "$SCRIPT" HSIM_POST_INSTALL_SQL="GRANT dnb_worker TO dnb_app WITH INHERIT TRUE"; L=$HSIM/o9.log
check "the fault was planted"                          has "$S/inject.log" '^GRANT ROLE$'
check "the catalogue still reads clean"                has "$L" '^EXECUTE, beyond the owner: mt_auth_issue_code:dnb_app .*mt_auth_verify_code:dnb_app$'
check "exit non-zero"                                  hasnt "$L" '^EXIT=0$'
check "the execution test names dnb_app"               has "$L" 'dnb_app was NOT refused the worker.s claim'
check "no restart, no app, no route: the old surface stays" bash -c "[ \"\$(grep -c 'docker restart' $S/calls.log)\" = 0 ] && ! grep -q dnb-staging-app $S/containers && [ ! -e $T/dnb-staging-app.yml ]"
check "no probe row survived"                          eq "$(probe_rows)" 0
sql "REVOKE dnb_worker FROM dnb_app" > /dev/null
check "the membership is gone again (cluster-wide)"    eq "$(sql "SELECT count(*) FROM pg_auth_members WHERE roleid = 'dnb_worker'::regrole AND member = 'dnb_app'::regrole")" 0

echo; echo "== O10 port 8098 already taken by something else: refused in step 0"
restore 030
setsid python3 -c "import http.server,sys; sys.argv=['hsim-port-squatter']; http.server.HTTPServer(('127.0.0.1',8098), http.server.SimpleHTTPRequestHandler).serve_forever()" hsim-port-squatter > /dev/null 2>&1 < /dev/null &
for _ in $(seq 30); do (exec 3<>/dev/tcp/127.0.0.1/8098) 2>/dev/null && break; sleep 0.1; done
run o10; L=$HSIM/o10.log
pkill -f '[h]sim-port-squatter' 2>/dev/null || kill %1 2>/dev/null || true
check "exit non-zero"                                  hasnt "$L" '^EXIT=0$'
check "says the port is in use"                        has "$L" 'port 8098 is already in use on this host, by something that is not dnb-staging-app'
check "no build, no run"                               eq "$(calls 'run')" 0

echo; echo "== O11 the API in the development posture: refused in step 0"
restore 030
mapfile -t list < "$S/api.env"
docker rm -f dnb-staging-api > /dev/null
docker run -d --name dnb-staging-api --network dnb-staging --restart unless-stopped -p 127.0.0.1:8099:8099 -v "$APPD/app:/app:ro" -w /app \
  --env-file "$APPD/env/runtime.env" --env-file "$APPD/env/secrets.docker.env" -e DN_DEV_STAFF_IDENTITY=yes-development-only \
  dnb-staging-php:8.3 php -S 0.0.0.0:8099 plugin/bin/serve.php > /dev/null
for _ in $(seq 50); do [ "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8099/)" = 200 ] && break; sleep 0.2; done
run o11; L=$HSIM/o11.log
check "exit non-zero"                                  hasnt "$L" '^EXIT=0$'
check "says it expects the real staff login"           has "$L" 'the API does not run the real DishNet staff login alone'
check "no build, no run"                               eq "$(calls 'run')" 0

echo; echo "== O12 Traefik publishing in ingress mode: refused in step 0 (a per-address limit would be meaningless)"
restore 030
run o12 "$SCRIPT" HSIM_PUBLISH_MODE=ingress; L=$HSIM/o12.log
check "exit non-zero"                                  hasnt "$L" '^EXIT=0$'
check "says ingress mode hides the client address"     has "$L" 'Traefik publishes 80/443 in ingress mode'
check "no build, no run"                               eq "$(calls 'run')" 0

echo; echo "== O13 the printed rollback, run exactly as printed: the hostname withdrawn, the app gone, the previous build back under the API and the worker"
# Docker mounts the directory a container is STARTED on and keeps it when that
# directory is renamed; a restart or a new container mounts whatever the path is
# then. The fake records the worker's tree by inode, and the API's is its process's
# working directory, so a rollback in the wrong order is visible here.
restore 030
run o13; L=$HSIM/o13.log
check "the command completed first"                    has "$L" '^EXIT=0$'
sed -n '/^Rollback, if ever needed/,/^  (031 and 032 stay/p' "$L" | sed '1,2d;$d' | sed 's/^  //' > "$HSIM/o13.rollback.sh"
check "the printed rollback is five commands"          eq "$(grep -c . "$HSIM/o13.rollback.sh")" 5
bash -e "$HSIM/o13.rollback.sh" > "$HSIM/o13.rollback.out" 2>&1; echo "RC=$?" >> "$HSIM/o13.rollback.out"
check "it ran without an error"                        has "$HSIM/o13.rollback.out" '^RC=0$'
check "the route file is gone"                         bash -c "[ ! -e $T/dnb-staging-app.yml ]"
check "the host is Traefik's own 404 again"            eq "$(via https://$HOST/)" "404 page not found"
check "no app container, nothing on either 8098"       bash -c "! grep -q '|dnb-staging-app|' $S/containers && ! (exec 3<>/dev/tcp/127.0.0.1/8098) 2>/dev/null && ! (exec 3<>/dev/tcp/172.17.0.1/8098) 2>/dev/null"
check "the tree is the previous build; the new one kept aside" bash -c "[ \"\$(sha256sum $APPD/app/SHA256SUMS | cut -d' ' -f1)\" = $D030 ] && [ \"\$(sha256sum $APPD/app.failed-*/SHA256SUMS | cut -d' ' -f1)\" = $D032 ]"
check "the worker runs the tree now at the path (by inode), with no SMS setting" bash -c "[ \"\$(cat $S/worker.inode)\" = \"\$(stat -c %i $APPD/app)\" ] && [ -f $S/worker.running ] && ! grep -q '^DNB\\?_SMS' $S/worker.env"
check "the API serves the tree now at the path (by inode)" bash -c "[ \"\$(stat -L -c %i /proc/\$(cat $S/api.pid)/cwd)\" = \"\$(stat -c %i $APPD/app)\" ]"
check "the panel answers; the real staff login still does" bash -c "[ \"\$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8099/)\" = 200 ] && curl -s http://127.0.0.1:8099/api/v1/admin/session | grep -q '\"provider\":\"dishnet\"'"
check "031 and 032 stay, as printed"                   eq "$(ledger)" "32|032_sign_in_codes_by_sms.sql"
# The control on that control: the order the first draft printed (the worker
# recreated BEFORE the tree went back) leaves the worker on the new build, and the
# inode check must see it.
restore 030
run o13c; L=$HSIM/o13c.log
sed -n '/^Rollback, if ever needed/,/^  (031 and 032 stay/p' "$L" | sed '1,2d;$d' | sed 's/^  //' > "$HSIM/o13c.new.sh"
{ sed -n '1,2p' "$HSIM/o13c.new.sh"; sed -n 4p "$HSIM/o13c.new.sh"; sed -n 3p "$HSIM/o13c.new.sh"; sed -n 5p "$HSIM/o13c.new.sh"; } > "$HSIM/o13c.rollback.sh"
check "CONTROL: the old order is the same five lines, the worker's before the tree's" bash -c "[ \"\$(sort $HSIM/o13c.new.sh)\" = \"\$(sort $HSIM/o13c.rollback.sh)\" ] && sed -n 3p $HSIM/o13c.rollback.sh | grep -q '^docker rm -f dnb-staging-worker'"
bash -e "$HSIM/o13c.rollback.sh" > "$HSIM/o13c.rollback.out" 2>&1; echo "RC=$?" >> "$HSIM/o13c.rollback.out"
check "CONTROL: in the old order the worker is left on the new build, and the inode check sees it" bash -c "grep -q '^RC=0$' $HSIM/o13c.rollback.out && [ \"\$(cat $S/worker.inode)\" != \"\$(stat -c %i $APPD/app)\" ]"

echo; echo "== controls on the controls: seven broken copies of the script, each caught by an assertion above"
mut() {  # mut <name> <python replacement pairs as JSON>: a copy of the script with exact edits, each anchor asserted once
  python3 - "$SCRIPT" "$HSIM/$1.sh" "$2" <<'PY'
import json, sys
s = open(sys.argv[1]).read()
for old, new in json.loads(sys.argv[3]):
    assert s.count(old) == 1, (sys.argv[2], old[:60], s.count(old))
    s = s.replace(old, new)
open(sys.argv[2], 'w').write(s)
PY
}
# X1: every role password handed to the app, and the script's own two checks of that removed.
mut x1 '[["  grep '"'"'^DNB_DSN='"'"' \"$APP/env/runtime.env\"; grep '"'"'^DNB_TOKEN_PEPPER='"'"' \"$APP/env/runtime.env\"\n  grep '"'"'^DNB_SECRET_KEY='"'"' \"$APP/env/runtime.env\"; grep '"'"'^DNB_APP_PASS='"'"' \"$APP/env/secrets.docker.env\"", "  cat \"$APP/env/runtime.env\" \"$APP/env/secrets.docker.env\""],
         ["[ \"$(sed -n '"'"'s/=.*//p'"'"' \"$APP/env/app.env\" | sort | tr '"'"'\\n'"'"' '"'"' '"'"')\" = \"DNB_APP_PASS DNB_DSN DNB_SECRET_KEY DNB_TOKEN_PEPPER \" ] \\\n  || fail", ": || fail"],
         ["[ \"$an\" = \"DNB_APP_PASS DNB_DSN DNB_SECRET_KEY DNB_TOKEN_PEPPER \" ] || fail", ": || fail"]]'
restore 030; run x1 "$HSIM/x1.sh"
check "X1 (every role password handed to the app) is caught: O1's 'the app holds the four' fails" bash -c "[ \"\$(grep -oE '^(DN|DNB)_[A-Z_]+' $S/app.env | sort | tr '\n' ' ')\" != 'DNB_APP_PASS DNB_DSN DNB_SECRET_KEY DNB_TOKEN_PEPPER ' ]"
# X2: the key read with echo ON.
mut x2 '[["    stty -echo < /dev/tty\n    printf \"  API key", "    printf \"  API key"]]'
restore 030; runtty x2 "$TYPE_SMS" "$HSIM/x2.sh"
check "X2 (the key echoed) is caught: O2's 'THE KEY: nowhere' fails" bash -c "! ( ! grep -rqF -- '$KEY' '$HSIM/x2.log' '$HSIM/x2.screen' /root/dnb-staging-evidence 2>/dev/null )"
# X3: the route without its allow-list, and the script's own through-Traefik refusal check removed.
mut x3 '[["      rule: \"Host(`app-staging.dishnetuganda.com`) && (Path(`/`) || PathPrefix(`/app/`) || PathPrefix(`/pwa/`) || Path(`/api/v1/me`) || PathPrefix(`/api/v1/me/`))\"", "      rule: \"Host(`app-staging.dishnetuganda.com`)\""],
         ["  [ \"$a\" = 0 ] || fail \"$m $u reached the app through Traefik", "  : || fail \"$m $u reached the app through Traefik"]]'
restore 030; run x3 "$HSIM/x3.sh"
check "X3 (no allow-list in the route) is caught: O1's '/internal is Traefik's own 404' fails" bash -c "[ \"\$(curl -sk --resolve $HOST:443:127.0.0.1 -X POST https://$HOST/internal/radius/accounting)\" != '404 page not found' ]"
# X4: the execution test COMMITS: the script's own residue check must stop it.
mut x4 '[["ROLLBACK; -- the execution test keeps nothing", "COMMIT;"]]'
restore 030; run x4 "$HSIM/x4.sh"
check "X4 (probe committed) is caught by the script's residue check" has "$HSIM/x4.log" 'the execution tests left [1-9][0-9]* code row\(s\) behind'
check "X4 exits non-zero"                              hasnt "$HSIM/x4.log" '^EXIT=0$'
# X5: published on every address.
mut x5 '[["  -p \"127.0.0.1:$PORT:$PORT\" -p \"$GW:$PORT:$PORT\"", "  -p \"0.0.0.0:$PORT:$PORT\""]]'
restore 030; run x5 "$HSIM/x5.sh"
check "X5 (published on 0.0.0.0) is caught"           bash -c "grep -q 'is published somewhere other than' '$HSIM/x5.log' && ! grep -q '^EXIT=0$' '$HSIM/x5.log'"
# X6: DNB_EXPOSE_OTP given to the app, and the script's own variable check removed.
mut x6 '[["  --env-file \"$APP/env/app.env\" \"$IMG\"", "  --env-file \"$APP/env/app.env\" -e DNB_EXPOSE_OTP=1 \"$IMG\""],
         ["[ \"$an\" = \"DNB_APP_PASS DNB_DSN DNB_SECRET_KEY DNB_TOKEN_PEPPER \" ] || fail", ": || fail"]]'
restore 030; run x6 "$HSIM/x6.sh"
check "X6 (codes exposed by the app) is caught: O1's 'the app holds the four' fails" bash -c "grep -q '^DNB_EXPOSE_OTP=1$' '$S/app.env'"
# X7: the typed SMS settings not removed when the installer refuses.
mut x7 '[["  [ \"$PROMOTED\" = 1 ] || rm -f \"$APP/env/sms.env.new\"", "  :"]]'
restore 030; runtty x7 "$TYPE_SMS" "$HSIM/x7.sh" HSIM_PRE_INSTALL_SQL="ALTER DEFAULT PRIVILEGES FOR ROLE dnb_def_auth IN SCHEMA public GRANT SELECT ON TABLES TO dnb_app"
sql "ALTER DEFAULT PRIVILEGES FOR ROLE dnb_def_auth IN SCHEMA public REVOKE SELECT ON TABLES FROM dnb_app" > /dev/null
check "X7 (the key left on disk after a refusal) is caught: O8's 'no sms.env.new' fails" bash -c "[ -e $APPD/env/sms.env.new ]"

p=$(grep -c P "$S/results"); f=$(grep -c F "$S/results")
echo; echo "operator-app harness: $p passed, $f failed"
[ "$f" = 0 ]
