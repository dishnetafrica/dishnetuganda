#!/bin/bash
# scenarios.sh — rehearse scripts/dnb-staging-sms-settings.sh (docs/128 §G)
# against a sandbox rebuilt, from nothing, into the state the staging server is
# in now:
#   the 028 build at digest 1bc95524…, the simulated estate, the stage-2 route,
#   the worker as stage 1 created it, the API switched to the real DishNet staff
#   login by the REAL scripts/dnb-staging-staff-login.sh (docs/122), the REAL
#   029 command (938c002) and 030 command (b9aa7da), and the REAL operator-app
#   command (7170edc, docs/127 §J) as run 2026-09-25 05:31 UTC with the SMS
#   question skipped — every one byte for byte.
# The script under test is the real one, read from the working tree. Only
# `docker` and `getent` are faked (bin/), and Traefik (operator-app/traefik.py);
# every database is real PostgreSQL, every server a real `php -S`, and the
# worker the real bin/worker.php.
#
#   HSIM_SANDBOX=yes-this-is-not-the-server bash scripts/harness/sms-settings/scenarios.sh
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
D032=2874d64346b927e6ec2da77ceecb19714a623030ea7e89b74590d7bc6640a0c4
S029=c558e26a1d467d9e1d3bd210f9ffb0e3719aca552ed66bfbbaf54e7936dc9e3c   # the 029 command as the operator ran it
S030=0000f7588e0e8aa0c45397b66d44a71b0f014756e43a934f9598b47d0f32e8b4   # the 030 command as the operator ran it
S032=1c27d06d1dcf85b7e4a13a8cbed2613a0285068b0024ddd3dc84abc529675bdb   # the operator-app command as the operator ran it
SCRIPT=$REPO/scripts/dnb-staging-sms-settings.sh
D033=$(grep -m1 '^DIGEST=' "$SCRIPT" | cut -d= -f2)
PIN=$(grep -m1 '^COMMIT=' "$SCRIPT" | cut -d= -f2)
OA=$REPO/scripts/harness/operator-app     # its fake Traefik and its terminal driver
TOTP=$REPO/scripts/harness/staff-login/totp.py
PORTAL=portal-staging.dishnetuganda.com; HOST=app-staging.dishnetuganda.com
U=https://$PORTAL/api/v1/admin
RX='[a-z2-9]{6}(-[a-z2-9]{6}){3}'
PROBE=staging-probe-033
KEY=hsimKEY$(openssl rand -hex 20)        # typed into the operator-app command's prompt: must appear nowhere
PANELKEY=hsimPANEL$(openssl rand -hex 16) # typed into the Admin panel: must appear nowhere either
SMSUSER=dishnet-hsim
rm -f "$S/results"
ADDED_GW=0
pg() { psql -h "$PGH" -p "$PGP" -U postgres -d postgres -Atc "$1"; }

kill_servers() {
  [ -f "$S/api.pid" ] && { kill "$(cat "$S/api.pid")" 2>/dev/null; sleep 0.3; }
  [ -f "$S/app.pids" ] && while read -r p; do kill "$p" 2>/dev/null; done < "$S/app.pids"
  pkill -f 'php -S 127.0.0.1:8099' 2>/dev/null || true; pkill -f 'serve-app[.]php' 2>/dev/null || true
}
cleanup() {
  kill_servers
  pkill -f '[o]perator-app/traefik[.]py' 2>/dev/null || true
  pkill -f '[s]taff-login/traefik[.]py' 2>/dev/null || true
  # Role memberships are CLUSTER-WIDE: none planted here may outlive the run.
  sql "REVOKE dnb_adminwrite FROM dnb_app" >/dev/null 2>&1 || true
  for n in 030 p4 p4sms; do pg "DROP DATABASE IF EXISTS dnb_hsim_snap$n" >/dev/null 2>&1 || true; done
  [ "$ADDED_GW" = 1 ] && ip addr del 172.17.0.1/32 dev lo 2>/dev/null || true
}
trap cleanup EXIT

digest() { sha256sum "$1/SHA256SUMS" 2>/dev/null | cut -d' ' -f1; }
ledger() { sql "SELECT count(*) || '|' || max(filename) FROM mt_migrations"; }
settings() { sql "SELECT provider || '|' || version || '|' || (key_sealed IS NOT NULL)::text FROM mt_sms_settings"; }
probe_rows() { sql "SELECT count(*) FROM mt_audit_log WHERE actor = '$PROBE'"; }
calls() { grep -cE "docker $1" "$S/calls.log" || true; }
ino() { stat -c %i "$APPD/app"; }
api_ino() { stat -L -c %i "/proc/$(cat "$S/api.pid")/cwd" 2>/dev/null; }
# Every process of the app serves the tree now at the path.
app_on_tree() { local p; [ -s "$S/app.pids" ] || return 1; while read -r p; do [ "$(stat -L -c %i "/proc/$p/cwd" 2>/dev/null)" = "$(ino)" ] || return 1; done < "$S/app.pids"; }

start_traefik() {
  pkill -f '[o]perator-app/traefik[.]py' 2>/dev/null || true
  pkill -f '[s]taff-login/traefik[.]py' 2>/dev/null || true
  for _ in $(seq 50); do (exec 3<>/dev/tcp/127.0.0.1/443) 2>/dev/null || break; sleep 0.1; done
  setsid nohup python3 "$OA/traefik.py" >> "$S/traefik.stdout" 2>&1 < /dev/null &
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
# — with NO terminal (setsid, stdin closed).
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
  env "$@" DNB_REDEPLOY_REPO="file://$HSIM/remote/dishnetuganda" python3 "$OA/drive.py" "$HSIM/$n.screen" "$ans" -- \
    bash -c "{ sh '$sc' 2>&1; echo EXIT=\$?; } | tee '$HSIM/$n.log'"
}
TYPE_SMS="[[\"username: \", \"$SMSUSER\"], [\"typing is hidden): \", \"$KEY\"], [\"press Enter for none): \", \"DishNet\"]]"
# Neither key may appear anywhere a copy of the terminal, a log, the evidence or a server log could carry it.
nokey() { ! grep -rqF -e "$KEY" -e "$PANELKEY" "$HSIM/$1.log" "$HSIM/$1.screen" /root/dnb-staging-evidence "$S/api.log" "$S/app.log" "$S/worker.log" 2>/dev/null; }

# C <curl args…>: the panel's own requests, through the fake Traefik, with the staff cookie.
C() { via -H "Origin: https://$PORTAL" -H 'Content-Type: application/json' -c "$S/jar" -b "$S/jar" -o "$S/body" -w '%{http_code}' "$@"; }
J() { python3 -c "import json,sys; d=json.load(open(sys.argv[1])); print(eval(sys.argv[2], {}, {'d': d}))" "$S/body" "$1" 2>/dev/null; }
# signin: dishnet-admin with the harness's password and the current authenticator code
# (the next step's code if this step's was already spent: a code is never accepted twice).
signin() {
  local pw k c
  pw=$(sed -n 's/^pw=//p' "$S/admin.creds"); k=$(sed -n 's/^totp=//p' "$S/admin.creds"); rm -f "$S/jar"
  for off in 0 1; do
    c=$(C -d "{\"username\":\"dishnet-admin\",\"password\":\"$pw\",\"code\":\"$(python3 "$TOTP" "$k" "$off")\"}" "$U/session")
    [ "$c" = 200 ] && grep -q '"pending":false' "$S/body" && return 0
  done
  return 1
}
# worker_once <name>: the DEPLOYED worker — the tree now at the path, the worker's
# own recorded environment — for one pass, as the running loop's next tick would.
worker_once() {
  local list; mapfile -t list < "$S/worker.env"
  ( cd "$APPD/app" && exec env -i "${list[@]}" php bin/worker.php --once ) > "$HSIM/$1.worker.log" 2>&1
}

snapshot() {
  pg "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = 'dnb_hsim' AND pid <> pg_backend_pid()" > /dev/null
  pg "DROP DATABASE IF EXISTS dnb_hsim_snap$1" > /dev/null && pg "CREATE DATABASE dnb_hsim_snap$1 TEMPLATE dnb_hsim" > /dev/null
  tar -czf "$HSIM/sms-snap$1.tgz" -C / "${APPD#/}" "${T#/}" \
      $(cd / && for f in api.env worker.env worker.log worker.running worker.appdir containers traefik.src app.env app.ports; do [ -e "${S#/}/$f" ] && echo "${S#/}/$f"; done)
}
restore() {
  kill_servers
  sql "REVOKE dnb_adminwrite FROM dnb_app" >/dev/null 2>&1 || true
  pg "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = 'dnb_hsim' AND pid <> pg_backend_pid()" > /dev/null
  # OWNER dnb, as reset.sh creates it (since PostgreSQL 15 schema public belongs to
  # the database owner; a copy owned by postgres would deny the installer CREATE).
  pg "DROP DATABASE dnb_hsim" > /dev/null && pg "CREATE DATABASE dnb_hsim TEMPLATE dnb_hsim_snap$1 OWNER dnb" > /dev/null
  rm -rf "${APPD:?}"/app* "${APPD:?}"/env "${T:?}"/*
  rm -f "$S"/api.* "$S"/app.* "$S"/worker.* "$S/containers" "$S/inject.log" "$S/calls.log" "$S/jar"
  tar -xzf "$HSIM/sms-snap$1.tgz" -C /
  local list; mapfile -t list < "$S/api.env"
  ( cd "$APPD/app" && exec setsid nohup env -i "${list[@]}" php -S 127.0.0.1:8099 plugin/bin/serve.php ) >> "$S/api.log" 2>&1 < /dev/null &
  echo $! > "$S/api.pid"
  # The app runs iff the snapshot's container list has it: the setup's earlier
  # states have none, whatever files an earlier run left behind.
  if grep -q '|dnb-staging-app|' "$S/containers" && [ -f "$S/app.env" ] && [ -f "$S/app.ports" ]; then
    mapfile -t list < "$S/app.env"; : > "$S/app.pids"
    for hp in $(grep -oE '"HostIp":"[^"]*","HostPort":"[0-9]+"' "$S/app.ports" | sed -E 's/"HostIp":"([^"]*)","HostPort":"([0-9]+)"/\1:\2/'); do
      ( cd "$APPD/app" && exec setsid nohup env -i "${list[@]}" php -S "$hp" plugin/bin/serve-app.php ) >> "$S/app.log" 2>&1 < /dev/null &
      echo $! >> "$S/app.pids"
    done
    for _ in $(seq 50); do [ "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8098/)" = 200 ] && break; sleep 0.2; done
  fi
  for _ in $(seq 50); do [ "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8099/)" = 200 ] && break; sleep 0.2; done
  start_traefik
  echo "$HOST 209.97.137.203" > "$S/dns"
  unset HSIM_PRE_INSTALL_SQL HSIM_POST_INSTALL_SQL HSIM_PUBLISH_MODE
  : > "$S/calls.log"; rm -rf /root/dnb-staging-evidence
}

echo "== setup: the sandbox host, the 028 build, the staff login, the 029, 030 and operator-app commands as run"
if ! ip -4 addr show lo | grep -q ' 172.17.0.1/'; then ip addr add 172.17.0.1/32 dev lo && ADDED_GW=1; fi
check "the docker bridge gateway address exists here (172.17.0.1, on loopback)" bash -c "ip -4 addr show lo | grep -q ' 172.17.0.1/'"
[ -f "$HSIM/tls-le.crt" ] || openssl req -x509 -newkey rsa:2048 -nodes -keyout "$HSIM/tls-le.key" -out "$HSIM/tls-le.crt" -days 30 \
  -subj "/O=Let's Encrypt/CN=R11" -addext "subjectAltName=DNS:$PORTAL,DNS:$HOST" >/dev/null 2>&1
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
git -C "$REPO" show 7170edc:scripts/dnb-staging-operator-app.sh > "$HSIM/operator-app-as-run.sh"
check "the 029 command is byte-for-byte the one the operator ran" eq "$(sha256sum "$HSIM/redeploy-029.sh" | cut -d' ' -f1)" "$S029"
check "the 030 command is byte-for-byte the one the operator ran" eq "$(sha256sum "$HSIM/redeploy-030.sh" | cut -d' ' -f1)" "$S030"
check "the operator-app command is byte-for-byte the one the operator ran" eq "$(sha256sum "$HSIM/operator-app-as-run.sh" | cut -d' ' -f1)" "$S032"
rm -rf "$HSIM/remote"; mkdir -p "$HSIM/remote"
git clone -q --bare "$REPO" "$HSIM/remote/dishnetuganda.git"
git -C "$HSIM/remote/dishnetuganda.git" cat-file -e "$PIN^{commit}" || { echo "the pinned commit $PIN is not in this repository"; exit 1; }
W=$HSIM/remote/work; git clone -q -b claude/study-this-jhe2eg "$HSIM/remote/dishnetuganda.git" "$W"
echo "// a later change on the branch (sms-settings harness)" >> "$W/dishnet-hybrid-sudan/dishnet-mikrotik-control-plane/src/autoload.php"
git -C "$W" -c user.email=harness@localhost -c user.name=harness commit -qam "a later change on the branch"
git -C "$W" push -q origin claude/study-this-jhe2eg
echo "   pinned $PIN; branch tip now $(git -C "$HSIM/remote/dishnetuganda.git" rev-parse --short claude/study-this-jhe2eg)"

echo "   building the staging state: 028 → estate → stage-1 worker → real staff login → first sign-in → 029 → 030 → operator app"
# State an EARLIER run left behind must not leak into this one's snapshots:
# measured — a previous run's app.env/app.ports travelled into snapshot 030 and
# a restore of it started an app that did not exist at that point, holding 8098.
kill_servers
rm -f "$S"/app.* "$S"/worker.* "$S/jar" "$S/admin.creds" "$S/inject.log"
rm -rf "${APPD:?}"/app "${APPD:?}"/app.*; mkdir -p "$APPD/app"; tar -xzf "$HSIM/build028.tar.gz" -C "$APPD/app" --strip-components=1
pg "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = 'dnb_hsim' AND pid <> pg_backend_pid()" > /dev/null
pg "DROP DATABASE IF EXISTS dnb_hsim" > /dev/null
bash "$REPO/scripts/harness/staff-login/reset.sh" 127.0.0.1 > "$HSIM/reset.out" 2>&1 || { tail -20 "$HSIM/reset.out"; exit 1; }
stage1_worker
bash "$REPO/scripts/harness/staff-login/run.sh" switch notty
grep -q '^EXIT=0$' "$HSIM/switch.log" || { echo "the staff-login switch did not complete:"; tail -25 "$HSIM/switch.log"; exit 1; }
start_traefik
# The operator's first sign-in (docs/122 J): the one-time password from its 0600
# file, an authenticator enrolled, a password of the harness's own. Invented
# credentials, for this sandbox only; kept in the state directory, mode 0600.
PW1=$(grep -aoE "$RX" /root/dnb-staging-evidence/*.password.txt 2>/dev/null | head -1)
[ -n "$PW1" ] || { echo "no one-time password from the switch"; exit 1; }
rm -f "$S/jar"
[ "$(C -d "{\"username\":\"dishnet-admin\",\"password\":\"$PW1\"}" "$U/session")" = 200 ] || { echo "first sign-in failed"; cat "$S/body"; exit 1; }
C -X POST -d '{}' "$U/session/totp" > /dev/null; TK=$(J "d['enrolment']['key']")
[ "$(C -d "{\"code\":\"$(python3 "$TOTP" "$TK")\"}" "$U/session/totp/confirm")" = 200 ] || { echo "authenticator enrolment failed"; exit 1; }
PW2="$(openssl rand -hex 12)-Harness"
[ "$(C -d "{\"current\":\"$PW1\",\"replacement\":\"$PW2\"}" "$U/session/password")" = 200 ] || { echo "password change failed"; exit 1; }
C -X DELETE "$U/session" > /dev/null
( umask 077; printf 'pw=%s\ntotp=%s\n' "$PW2" "$TK" > "$S/admin.creds" )
: > "$S/calls.log"
{ DNB_REDEPLOY_REPO="file://$HSIM/remote/dishnetuganda" sh "$HSIM/redeploy-029.sh" 2>&1; echo "EXIT=$?"; } > "$HSIM/setup029.log" < /dev/null
grep -q '^EXIT=0$' "$HSIM/setup029.log" || { echo "the 029 command did not complete:"; tail -25 "$HSIM/setup029.log"; exit 1; }
check "setup: the 029 command left ledger 29 and the 029 build" eq "$(ledger)|$(digest "$APPD/app")" "29|029_o1_site_service_same_operator.sql|$D029"
{ DNB_REDEPLOY_REPO="file://$HSIM/remote/dishnetuganda" sh "$HSIM/redeploy-030.sh" 2>&1; echo "EXIT=$?"; } > "$HSIM/setup030.log" < /dev/null
grep -q '^EXIT=0$' "$HSIM/setup030.log" || { echo "the 030 command did not complete:"; tail -25 "$HSIM/setup030.log"; exit 1; }
check "setup: the 030 command left ledger 30 and the 030 build" eq "$(ledger)|$(digest "$APPD/app")" "30|030_admin_operator_onboarding.sql|$D030"
echo "$HOST 209.97.137.203" > "$S/dns"
snapshot 030
run setup-p4 "$HSIM/operator-app-as-run.sh"
grep -q '^EXIT=0$' "$HSIM/setup-p4.log" || { echo "the operator-app command did not complete:"; tail -25 "$HSIM/setup-p4.log"; exit 1; }
check "setup: the operator-app command left ledger 32 and its build" eq "$(ledger)|$(digest "$APPD/app")" "32|032_sign_in_codes_by_sms.sql|$D032"
check "setup: as on the server — SMS skipped, the worker says sms null" bash -c "grep -q 'SMS NOT configured' '$HSIM/setup-p4.log' && grep -q '\"sms\":\"null\"' '$S/worker.log'"
check "setup: the app runs, holding its four variables" eq "$(grep -oE '^(DN|DNB)_[A-Z_]+' "$S/app.env" | sort | tr '\n' ' ')" "DNB_APP_PASS DNB_DSN DNB_SECRET_KEY DNB_TOKEN_PEPPER "
snapshot p4
restore 030
runtty setup-p4sms "$TYPE_SMS" "$HSIM/operator-app-as-run.sh"
grep -q '^EXIT=0$' "$HSIM/setup-p4sms.log" || { echo "the operator-app command with SMS did not complete:"; tail -25 "$HSIM/setup-p4sms.log"; exit 1; }
check "setup: a variant with the SMS key typed into the operator-app command (the worker's DN_SMS)" has "$S/worker.log" '"sms":"africastalking"'
snapshot p4sms

echo; echo "== S1 the staging state now, no terminal: 033 applied, verified, the three containers restarted on the new build"
restore p4
run s1; L=$HSIM/s1.log
check "exit 0"                                          has "$L" '^EXIT=0$'
check "the operator app's build found deployed"         has "$L" '^ok: the operator app.s build \(docs/127 §L.2\) is deployed$'
check "the real staff login found"                      has "$L" '^API identity: the real DishNet staff login'
check "the worker found without DN_SMS: it will follow the panel" has "$L" '^worker: DN_DELIVERY=simulated; DN_SMS is not set, so on the new build it follows the Admin panel'
check "one DNB_SECRET_KEY in all three, not shown"      has "$L" '^DNB_SECRET_KEY: set, and the same in the API, the worker and the app \(values not shown\)'
check "032 found applied; 033 pending"                  has "$L" '^ok: 031 and 032 are applied'
check "the pinned commit was built, not the tip"        has "$L" "^$PIN\$"
check "artifact-ok with the reviewed digest"            has "$L" "^artifact-ok: .*content digest $D033"
check "doctor's warning: 32 of 33 applied, printed"     has "$L" 'warn +plugin schema +32 of 33 migration\(s\) applied'
check "doctor: SMS not measurable before 033, printed"  has "$L" 'skip +sign-in codes by SMS +NOT MEASURED — DN_SMS is unset, so the Admin panel decides'
check "doctor in production posture, no blocker"        has "$L" '^doctor \(production posture\): no blocker$'
check "the installer applied exactly 033"               has "$L" 'applied 1 migration\(s\): 033_sms_settings_from_the_admin_panel.sql'
check "ledger 33, latest 033"                           has "$L" '^migrations after: 33 \(last 033_sms_settings_from_the_admin_panel.sql\)'
check "the store: dnb_def_auth's, no RLS, nobody else's privilege, one row" has "$L" '^the settings store mt_sms_settings: owner\|row security dnb_def_auth\|false; privileges held by anyone but its owner: none; rows: 1$'
check "four functions, SECURITY DEFINER, dnb_def_auth's" has "$L" ': 4 of 4$'
check "each function granted to exactly its role"       has "$L" '^EXECUTE, beyond the owner: mt_admin_sms_settings:dnb_adminapi mt_sms_settings_for_worker:dnb_worker mt_sms_settings_set:dnb_adminwrite mt_sms_worker_report:dnb_worker$'
check "dnb_def_auth may audit; kept no CREATE"          has "$L" 'kept CREATE on the schema: true\|false$'
check "the Admin read withholds envelope and fingerprint" has "$L" '^the Admin read returns key_set, and neither the envelope nor the fingerprint$'
check "031/032 grants unchanged"                        has "$L" "^031 and 032's sign-in and outbox grants: unchanged$"
check "probe: saved, key set"                           has "$L" '^  first\|true\|set$'
check "probe: the same save again, no change (RULE I-1)" has "$L" '^  again\|false\|kept$'
check "probe: a new username without its key, refused in the function's words" has "$L" 'a new username needs its API key typed again'
check "probe: exactly one audit row, staff, no operator, no envelope or fingerprint" has "$L" '^  audit\|1\|staff\|sms.settings_changed\|true\|true$'
check "probe: the worker's read and the Admin read"     bash -c "grep -q '^  worker|africastalking|staging-probe|DishNet|true|true$' '$L' && grep -q '^  panel|africastalking|staging-probe|true|unusable|true$' '$L'"
check "dnb_adminwrite: only the setter"                 has "$L" '^  dnb_adminwrite: the table refused; allowed: mt_sms_settings_set; every other function refused$'
check "dnb_worker: only its read and report"            has "$L" '^  dnb_worker: the table refused; allowed: mt_sms_settings_for_worker mt_sms_worker_report; every other function refused$'
check "dnb_adminapi: only the Admin read"               has "$L" '^  dnb_adminapi: the table refused; allowed: mt_admin_sms_settings; every other function refused$'
check "the owner refused everything"                    has "$L" '^  dnb: the table refused; allowed: none; every other function refused$'
NLOGIN=$(pg "SELECT count(*) FROM pg_roles WHERE rolcanlogin AND NOT rolsuper")
check "control: the cluster has login roles to test ($NLOGIN)" bash -c "[ '$NLOGIN' -ge 8 ]"
check "every login role in the cluster was tested"      eq "$(grep -cE '^  [a-z0-9_]+: the table refused; allowed:' "$L")" "$NLOGIN"
check "residue 0, by the script"                        has "$L" '^residue: 0 audit rows by the probe actor; the settings row is as it was$'
check "API back on the new build: panel, settings.js, the SMS page, dishnet" has "$L" '^API: panel 200, settings.js 200, the SMS page in the panel: [1-9][0-9]*, GET /api/v1/admin/session 401 .*"provider":"dishnet"'
check "the SMS routes answer 401 anonymously"           has "$L" '^the SMS settings routes, anonymously \(401 = bound and guarded\): GET 401, POST 401$'
check "the worker: sms panel"                           has "$L" '"sms":"panel"'
check "the worker's report: off, version 0, after the restart" has "$L" '^the worker.s report in the database: state off, at version 0, after this restart: true \(settings version 0, provider none\)$'
check "environments unchanged across the restart"       has "$L" '^environments: unchanged in all three'
check "the app back as it was"                          has "$L" '^operator app: loopback 200, gateway 200, the reviewed CSP: yes, its DN_/DNB_ variables: DNB_APP_PASS DNB_DSN DNB_SECRET_KEY DNB_TOKEN_PEPPER $'
check "through Traefik: 200, 401, 200"                  has "$L" "^through Traefik: $PORTAL/settings.js 200, its SMS settings route anonymously 401; $HOST/ 200\$"
check "Traefik not restarted; route files unchanged"    has "$L" '^Traefik: not restarted .*both route files unchanged$'
check "exactly the three containers changed"            has "$L" '^containers new or changed: dnb-staging-api dnb-staging-app dnb-staging-worker $'
check "none removed"                                    has "$L" '^containers removed \(must be none\): none$'
check "result: the worker follows the panel, none set"  has "$L" '^=== SMS SETTINGS ON STAGING .*the worker follows the Admin panel: no SMS sender is set yet'
check "next steps: the SMS page, enter the account later" has "$L" '^     It says no SMS sender is set yet\. When your Africa.s Talking account exists'
check "says not to run the operator-app command again"  has "$L" '^  Do not run the operator-app command'
check "the rollback puts the tree back first"           has "$L" '^Rollback, if ever needed \(in this order'
check "asks for the log file, not the terminal"         has "$L" '^Send back the LOG FILE, not the terminal'
check "no warning raised by the script"                 hasnt "$L" '^  WARN:|^WARNING:'
check "database: ledger 33; settings none, version 0, no key" eq "$(ledger)|$(settings)" "33|033_sms_settings_from_the_admin_panel.sql|none|0|false"
check "database: no probe audit row survived"           eq "$(probe_rows)" 0
check "sandbox: the API serves the new tree (by inode)" eq "$(api_ino)" "$(ino)"
check "sandbox: the worker runs the new tree (by inode)" eq "$(cat "$S/worker.inode")" "$(ino)"
check "sandbox: every app process serves the new tree (by inode)" app_on_tree
# The newest previous tree (by the UTC stamp in its name) is this run's: the setup's own
# commands left older ones.
check "sandbox: the tree is the reviewed build; the previous one kept" bash -c "[ \"\$(sha256sum $APPD/app/SHA256SUMS | cut -d' ' -f1)\" = $D033 ] && [ \"\$(sha256sum \$(ls -d $APPD/app.prev-* | sort | tail -1)/SHA256SUMS | cut -d' ' -f1)\" = $D032 ]"
check "sandbox: no Traefik file touched"                eq "$(ls "$T" | tr '\n' ' ')" "dnb-staging-app.yml dnb-staging.yml "
check "sandbox: the worker's environment has no SMS variable" hasnt "$S/worker.env" '^(DN_SMS|DNB_SMS_)'

echo; echo "== S1b the Admin's journey through Traefik on the deployed build: sign in, save the account, the worker uses it"
check "signed in: password and authenticator code"      signin
check "GET the SMS page: nothing set, the worker off at version 0" eq "$(C "$U/settings/sms")|$(J "[d['sms']['provider'], d['sms']['key_set'], d['sms']['version'], d['worker']['state'], d['worker']['version']]")" "200|['none', False, 0, 'off', 0]"
check "a body naming the actor is refused, not ignored (400)" eq "$(C -d "{\"provider\":\"africastalking\",\"username\":\"hsim-account\",\"api_key\":\"$PANELKEY\",\"actor\":\"someone-else\"}" "$U/settings/sms")" 400
A0=$(sql "SELECT count(*) FROM mt_audit_log WHERE action = 'sms.settings_changed'")
check "saved: 200, changed, version 1, key set"         eq "$(C -d "{\"provider\":\"africastalking\",\"username\":\"hsim-account\",\"api_key\":\"$PANELKEY\",\"sender\":\"DishNet\"}" "$U/settings/sms")|$(J "[d['changed'], d['version'], d['key']]")" "200|[True, 1, 'set']"
check "the answer carries no key"                       hasnt_f "$S/body" "$PANELKEY"
check "stored sealed: an envelope, not the key"         eq "$(sql "SELECT (key_sealed LIKE 'v1.%')::text || '|' || (position('$PANELKEY' in key_sealed) = 0)::text || '|' || (position('$PANELKEY' in key_fp) = 0)::text FROM mt_sms_settings")" "true|true|true"
check "one audit row: dishnet-admin, staff, the key 'set' and not the key" eq "$(sql "SELECT count(*) || '|' || min(actor) || '|' || min(actor_kind) || '|' || min(detail->>'key') || '|' || bool_and(position('$PANELKEY' in detail::text) = 0)::text FROM mt_audit_log WHERE action = 'sms.settings_changed' AND actor = 'dishnet-admin'")" "1|dishnet-admin|staff|set|true"
check "the page says the worker has not caught up yet (version 1 saved, 0 reported)" eq "$(C "$U/settings/sms")|$(J "[d['sms']['version'], d['worker']['version']]")" "200|[1, 0]"
check "no sign-in code is waiting, so the worker's next tick sends nothing" eq "$(sql "SELECT count(*) FROM mt_auth_sms_outbox WHERE state = 'queued'")" 0
worker_once s1b
check "the deployed worker's next tick: in use, the panel's version, Africa's Talking" has "$HSIM/s1b.worker.log" '"sms_settings":\{"version":1,"state":"in_use","binding":"africastalking"'
check "the page shows what the worker reported: in use at version 1" eq "$(C "$U/settings/sms")|$(J "[d['worker']['state'], d['worker']['version'], d['worker']['recent']]")" "200|['in_use', 1, True]"
check "the same save again: 200, nothing changed, no audit row (RULE I-1)" eq "$(C -d "{\"provider\":\"africastalking\",\"username\":\"hsim-account\",\"api_key\":\"$PANELKEY\",\"sender\":\"DishNet\"}" "$U/settings/sms")|$(J "[d['changed'], d['key']]")|$(sql "SELECT count(*) FROM mt_audit_log WHERE action = 'sms.settings_changed'")" "200|[False, 'kept']|$((A0 + 1))"
check "PANEL KEY: nowhere in the logs, the evidence or the worker's output" bash -c "! grep -rqF -- '$PANELKEY' '$HSIM/s1.log' '$HSIM/s1b.worker.log' '$S/api.log' '$S/worker.log' /root/dnb-staging-evidence 2>/dev/null"

echo; echo "== S3 the command again, now that an account IS set: re-verified, the saved setting untouched, the worker back in use"
run s3; L=$HSIM/s3.log
check "exit 0"                                          has "$L" '^EXIT=0$'
check "notes the build is already deployed"             has "$L" '^NOTE: the reviewed build is already deployed'
check "notes 033 is already applied"                    has "$L" '^NOTE: 033 is already applied'
check "doctor: set in the Admin panel, value withheld"  has "$L" 'ok +sign-in codes by SMS +africastalking, set in the Admin panel — live; API key set \(value withheld\)'
check "the installer applied nothing"                   has "$L" 'schema already current, no migration applied'
check "probe over a set account: replaced, then kept"   bash -c "grep -q '^  first|true|replaced$' '$L' && grep -q '^  again|false|kept$' '$L'"
check "the worker's report after the restart: in use at version 1" has "$L" '^the worker.s report in the database: state in_use, at version 1, after this restart: true \(settings version 1, provider africastalking\)$'
check "result: follows the panel, in use"               has "$L" '^=== SMS SETTINGS ON STAGING .*the worker follows the Admin panel: africastalking, reported in_use'
check "the saved setting survived the run untouched"    eq "$(settings)|$(sql "SELECT username FROM mt_sms_settings")" "africastalking|1|true|hsim-account"
check "PANEL KEY: nowhere"                              nokey s3

echo; echo "== S1c turn SMS off from the panel: the worker stops using it at its next tick"
check "signed in again"                                 signin
check "off: 200, changed, the key removed"              eq "$(C -d '{"provider":"none"}' "$U/settings/sms")|$(J "[d['changed'], d['key']]")" "200|[True, 'removed']"
check "the row holds no account and no key"             eq "$(sql "SELECT provider || '|' || coalesce(username, '-') || '|' || (key_sealed IS NULL)::text || '|' || (key_fp IS NULL)::text FROM mt_sms_settings")" "none|-|true|true"
worker_once s1c
check "the worker's next tick: off"                     has "$HSIM/s1c.worker.log" '"sms_settings":\{"version":2,"state":"off","binding":"null"'
check "signed out"                                      eq "$(C -X DELETE "$U/session")" 204

echo; echo "== S2 the variant with DN_SMS in the worker's environment: the environment wins, and the command says so"
restore p4sms
run s2; L=$HSIM/s2.log
check "exit 0"                                          has "$L" '^EXIT=0$'
check "found: DN_SMS set, and it wins"                  has "$L" '^worker: DN_DELIVERY=simulated; DN_SMS=africastalking is set in its environment and wins'
check "doctor: the environment's sender, value withheld" has "$L" 'ok +sign-in codes by SMS +africastalking — live; API key set \(value withheld\)'
check "the worker: sms africastalking"                  has "$L" '"sms":"africastalking"'
check "the worker's report: environment"                has "$L" '^the worker.s report in the database: state environment, at version -, after this restart: true'
check "next steps: set on the server"                   has "$L" 'It says the SMS sender is set on the server \(DN_SMS\)'
check "the worker kept its SMS variables"               bash -c "grep -qxF 'DNB_SMS_API_KEY=$KEY' '$S/worker.env' && grep -q '^DN_SMS=africastalking$' '$S/worker.env'"
check "THE KEY: nowhere in the log or the evidence"     nokey s2

echo; echo "== S4 refusals in step 0 — nothing changed by any of them"
restore 030
run s4a; L=$HSIM/s4a.log
check "a: before the operator app (ledger 30): refused"  has "$L" 'dnb-staging-app is not running — the operator app \(docs/127 §L.2\) is not in place'
check "a: exit non-zero, no build, no run, no restart"   bash -c "! grep -q '^EXIT=0$' '$L' && [ \"$(calls 'run')|$(calls 'restart')\" = '0|0' ]"
restore p4
mapfile -t list < "$S/api.env"
docker rm -f dnb-staging-api > /dev/null
docker run -d --name dnb-staging-api --network dnb-staging --restart unless-stopped -p 127.0.0.1:8099:8099 -v "$APPD/app:/app:ro" -w /app \
  --env-file "$APPD/env/runtime.env" --env-file "$APPD/env/secrets.docker.env" -e DN_DEV_STAFF_IDENTITY=yes-development-only \
  dnb-staging-php:8.3 php -S 0.0.0.0:8099 plugin/bin/serve.php > /dev/null
for _ in $(seq 50); do [ "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8099/)" = 200 ] && break; sleep 0.2; done
run s4b; L=$HSIM/s4b.log
check "b: the API in the development posture: refused"  has "$L" 'the API does not run the real DishNet staff login alone'
check "b: no build, no run"                             bash -c "! grep -q '^EXIT=0$' '$L' && [ \"$(calls 'run')\" = 0 ]"
restore p4
sed -i 's/^DNB_SECRET_KEY=.*/DNB_SECRET_KEY=hsim-a-different-master-key/' "$S/worker.env"
run s4c; L=$HSIM/s4c.log
check "c: the worker holds another DNB_SECRET_KEY: refused, and says why" has "$L" 'do not hold the same DNB_SECRET_KEY: a key saved in the panel would not open in the worker'
check "c: the value is not printed"                     hasnt "$L" 'hsim-a-different-master-key'
check "c: no build, no run; ledger 32"                  bash -c "! grep -q '^EXIT=0$' '$L' && [ \"$(calls 'run')|$(ledger)\" = '0|32|032_sign_in_codes_by_sms.sql' ]"
restore p4
echo 'DN_ALLOW_REAL_BINDINGS=yes-f6b-authorized' >> "$S/worker.env"
run s4d; L=$HSIM/s4d.log
check "d: the F6-B gate open on the worker: refused"    has "$L" 'DN_ALLOW_REAL_BINDINGS is set on dnb-staging-worker — F6-B is NOT authorized; refusing'
restore p4
echo "# not the build that was deployed" >> "$APPD/app/SHA256SUMS"
run s4e; L=$HSIM/s4e.log
check "e: an unknown deployed build: refused"           has "$L" 'the deployed build is neither the operator app.s .* nor this one'
check "e: no build, no run"                             bash -c "! grep -q '^EXIT=0$' '$L' && [ \"$(calls 'run')\" = 0 ]"

echo; echo "== S5 a wrong digest pinned (a copy of the script): refused before anything changes"
restore p4
sed "s/^DIGEST=.*/DIGEST=$(printf '0%.0s' $(seq 64))/" "$SCRIPT" > "$HSIM/wrongdigest.sh"
run s5 "$HSIM/wrongdigest.sh"; L=$HSIM/s5.log
check "exit non-zero"                                   hasnt "$L" '^EXIT=0$'
check "says the digest is not the reviewed build"       has "$L" 'is not the reviewed build 0{64} — nothing was changed'
check "tree, ledger, containers unchanged; no half-built tree left" bash -c "[ \"\$(sha256sum $APPD/app/SHA256SUMS | cut -d' ' -f1)\" = $D032 ] && ! ls -d $APPD/app.new-* >/dev/null 2>&1 && [ '$(ledger)' = '32|032_sign_in_codes_by_sms.sql' ] && [ \"$(calls 'restart')\" = 0 ]"

echo; echo "== S6 a default privilege that would hand dnb_app the settings: 033 refuses by itself; nothing of it is kept; the tree goes back"
restore p4
run s6 "$SCRIPT" HSIM_PRE_INSTALL_SQL="ALTER DEFAULT PRIVILEGES FOR ROLE dnb_def_auth IN SCHEMA public GRANT SELECT ON TABLES TO dnb_app"; L=$HSIM/s6.log
check "the fault was planted"                           has "$S/inject.log" '^ALTER DEFAULT PRIVILEGES$'
check "exit non-zero"                                   hasnt "$L" '^EXIT=0$'
check "033's own check refused, naming the store"      has "$L" '033: some role other than the owner holds a privilege on mt_sms_settings'
check "the installer refused; the tree is back"         has "$L" 'the installer refused .* The previous tree is back at'
check "033 kept nothing: ledger 32, no settings table"  eq "$(ledger)|$(sql "SELECT coalesce(to_regclass('public.mt_sms_settings')::text, 'absent')")" "32|032_sign_in_codes_by_sms.sql|absent"
check "the script says so"                              has "$L" '^migrations now: 32 \(last 032_sign_in_codes_by_sms.sql\) — 033 is one transaction'
check "tree: the previous build is live again"          eq "$(digest "$APPD/app")" "$D032"
check "no restart: the panel still serves the previous build (no settings.js)" bash -c "[ \"$(calls 'restart')\" = 0 ] && [ \"\$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8099/settings.js)\" = 404 ]"
sql "ALTER DEFAULT PRIVILEGES FOR ROLE dnb_def_auth IN SCHEMA public REVOKE SELECT ON TABLES FROM dnb_app" > /dev/null
run s6b; L=$HSIM/s6b.log
check "the fault removed, the same command again: completes, applying exactly 033" bash -c "grep -q '^EXIT=0$' '$L' && grep -q 'applied 1 migration(s): 033_sms_settings_from_the_admin_panel.sql' '$L'"

echo; echo "== S7 a role membership after the installer (dnb_app becomes dnb_adminwrite): the catalogue cannot see it; the execution test must"
restore p4
run s7 "$SCRIPT" HSIM_POST_INSTALL_SQL="GRANT dnb_adminwrite TO dnb_app WITH INHERIT TRUE"; L=$HSIM/s7.log
check "the fault was planted"                           has "$S/inject.log" '^GRANT ROLE$'
check "the catalogue still reads clean"                 has "$L" '^EXECUTE, beyond the owner: mt_admin_sms_settings:dnb_adminapi mt_sms_settings_for_worker:dnb_worker mt_sms_settings_set:dnb_adminwrite mt_sms_worker_report:dnb_worker$'
check "exit non-zero"                                   hasnt "$L" '^EXIT=0$'
check "the execution test names dnb_app"                has "$L" 'dnb_app was NOT refused mt_sms_settings_set'
check "no restart: the old surface stays"               eq "$(calls 'restart')" 0
check "no probe row survived"                           eq "$(probe_rows)" 0
sql "REVOKE dnb_adminwrite FROM dnb_app" > /dev/null
check "the membership is gone again (cluster-wide)"     eq "$(sql "SELECT count(*) FROM pg_auth_members WHERE roleid = 'dnb_adminwrite'::regrole AND member = 'dnb_app'::regrole")" 0

echo; echo "== S8 the printed rollback, run exactly as printed: the previous build back under all three containers"
# Docker mounts the directory a container is STARTED on and keeps it when that
# directory is renamed; a restart mounts whatever the path is then. The fake
# records every process's tree by inode, so a rollback in the wrong order shows.
restore p4
run s8; L=$HSIM/s8.log
check "the command completed first"                     has "$L" '^EXIT=0$'
sed -n '/^Rollback, if ever needed/,/^  (033 stays/p' "$L" | sed '1,2d;$d' | sed 's/^  //' > "$HSIM/s8.rollback.sh"
check "the printed rollback is two commands"            eq "$(grep -c . "$HSIM/s8.rollback.sh")" 2
bash -e "$HSIM/s8.rollback.sh" > "$HSIM/s8.rollback.out" 2>&1; echo "RC=$?" >> "$HSIM/s8.rollback.out"
check "it ran without an error"                         has "$HSIM/s8.rollback.out" '^RC=0$'
check "the tree is the previous build; the new one kept aside" bash -c "[ \"\$(sha256sum $APPD/app/SHA256SUMS | cut -d' ' -f1)\" = $D032 ] && [ \"\$(sha256sum $APPD/app.failed-*/SHA256SUMS | cut -d' ' -f1)\" = $D033 ]"
check "the API serves the tree at the path (by inode), without the SMS page" bash -c "[ \"$(api_ino)\" = \"$(ino)\" ] && [ \"\$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8099/settings.js)\" = 404 ]"
check "the worker runs the tree at the path (by inode): sms null again" bash -c "[ \"\$(cat $S/worker.inode)\" = \"\$(stat -c %i $APPD/app)\" ] && grep -q '\"sms\":\"null\"' $S/worker.log"
check "every app process serves the tree at the path (by inode)" app_on_tree
check "033 stays, as printed"                           eq "$(ledger)" "33|033_sms_settings_from_the_admin_panel.sql"
# The control on that control: the containers restarted BEFORE the tree goes back
# stay on the new build, and the inode checks must see it.
restore p4
run s8c; L=$HSIM/s8c.log
sed -n '/^Rollback, if ever needed/,/^  (033 stays/p' "$L" | sed '1,2d;$d' | sed 's/^  //' > "$HSIM/s8c.new.sh"
{ sed -n 2p "$HSIM/s8c.new.sh"; sed -n 1p "$HSIM/s8c.new.sh"; } > "$HSIM/s8c.rollback.sh"
bash -e "$HSIM/s8c.rollback.sh" > "$HSIM/s8c.rollback.out" 2>&1; echo "RC=$?" >> "$HSIM/s8c.rollback.out"
check "CONTROL: in the wrong order the three stay on the new build, and the inode checks see it" \
  bash -c "grep -q '^RC=0$' $HSIM/s8c.rollback.out && [ \"\$(cat $S/worker.inode)\" != \"\$(stat -c %i $APPD/app)\" ] && [ \"$(api_ino)\" != \"$(ino)\" ]"
check "CONTROL: and app_on_tree fails for the app"      bash -c "! ( $(declare -f ino app_on_tree); S=$S; APPD=$APPD; app_on_tree )"

echo; echo "== S9 the operator-app command, run again after 033: it stops at its first check and changes nothing"
restore p4
run s9pre; check "the 033 command completed first"     has "$HSIM/s9pre.log" '^EXIT=0$'
run s9 "$HSIM/operator-app-as-run.sh"; L=$HSIM/s9.log
check "exit non-zero"                                   hasnt "$L" '^EXIT=0$'
check "refused on the ledger: 33"                       has "$L" 'applies 031 and 032 on top of 030 and nothing else, and the ledger reads 33 migration\(s\)'
check "nothing changed: no build, no run, no restart; tree and ledger as they were" bash -c "[ \"$(calls 'run')|$(calls 'restart')\" = '0|0' ] && [ \"\$(sha256sum $APPD/app/SHA256SUMS | cut -d' ' -f1)\" = $D033 ] && [ '$(ledger)' = '33|033_sms_settings_from_the_admin_panel.sql' ]"

echo; echo "== controls on the controls: broken copies of the script, each caught by an assertion above"
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
# X1: the execution test COMMITS: the script's own residue check must stop it.
mut x1 '[["ROLLBACK; -- the execution test keeps nothing", "COMMIT;"]]'
restore p4; run x1 "$HSIM/x1.sh"
check "X1 (probe committed) is caught by the script's residue check" has "$HSIM/x1.log" 'the execution tests left [1-9][0-9]* audit row\(s\) behind'
check "X1 exits non-zero, before any restart"           bash -c "! grep -q '^EXIT=0$' '$HSIM/x1.log' && [ \"$(calls 'restart')\" = 0 ]"
# X2: the per-role refusal checks removed: with S7's membership planted, it passes.
mut x2 '[["      echo \"$out\" | grep -q \"^$tag|\" && fail \"$r was NOT refused $fn\"\n      [ \"$(echo \"$out\" | grep -c \"permission denied for function $fn\" || true)\" = 1 ] || fail \"$r was not refused $fn as a privilege\"", "      :"]]'
restore p4; run x2 "$HSIM/x2.sh" HSIM_POST_INSTALL_SQL="GRANT dnb_adminwrite TO dnb_app WITH INHERIT TRUE"
sql "REVOKE dnb_adminwrite FROM dnb_app" > /dev/null
check "X2 (refusal checks removed) is caught: it completes where S7 stopped, so S7's 'exit non-zero' fails" has "$HSIM/x2.log" '^EXIT=0$'
# X3: the operator app not restarted: the script's own container check must stop it.
mut x3 '[["docker restart dnb-staging-api dnb-staging-worker dnb-staging-app >/dev/null", "docker restart dnb-staging-api dnb-staging-worker >/dev/null"]]'
restore p4; run x3 "$HSIM/x3.sh"
check "X3 (app not restarted) is caught by the script's container check" has "$HSIM/x3.log" 'unexpected container change: dnb-staging-api dnb-staging-worker $'
check "X3: and the app is left serving the renamed previous tree (by inode)" bash -c "! ( $(declare -f ino app_on_tree); S=$S; APPD=$APPD; app_on_tree )"
# X4: the DNB_SECRET_KEY check removed: with S4c's mismatch it proceeds — and the
# consequence is exactly what the check exists to prevent.
mut x4 '[["  || fail \"the API, the worker and the app do not hold the same DNB_SECRET_KEY: a key saved in the panel would not open in the worker. Nothing was changed\"", "  || :"]]'
restore p4; sed -i 's/^DNB_SECRET_KEY=.*/DNB_SECRET_KEY=hsim-a-different-master-key/' "$S/worker.env"
run x4 "$HSIM/x4.sh"
check "X4 (key check removed) is caught: it completes where S4c refused" has "$HSIM/x4.log" '^EXIT=0$'
signin; C -d "{\"provider\":\"africastalking\",\"username\":\"hsim-account\",\"api_key\":\"$PANELKEY\"}" "$U/settings/sms" > /dev/null
worker_once x4
check "X4: the consequence — a key saved in the panel does not open in the worker" has "$HSIM/x4.worker.log" '"state":"unusable".*does not open under this DNB_SECRET_KEY'
# X5: the digest comparison removed: a wrong pin proceeds.
mut x5 '[["[ \"$got\" = \"$DIGEST\" ] || { rm -rf \"$NEW\"; fail \"content digest $got is not the reviewed build $DIGEST — nothing was changed\"; }", ":"]]'
sed -i "s/^DIGEST=.*/DIGEST=$(printf '0%.0s' $(seq 64))/" "$HSIM/x5.sh"
restore p4; run x5 "$HSIM/x5.sh"
check "X5 (digest check removed) is caught: it proceeds where S5 refused" bash -c "! grep -q 'is not the reviewed build' '$HSIM/x5.log' && grep -q '^artifact-ok' '$HSIM/x5.log'"

p=$(grep -c P "$S/results"); f=$(grep -c F "$S/results")
echo; echo "sms-settings harness: $p passed, $f failed"
[ "$f" = 0 ]
