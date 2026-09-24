#!/bin/bash
# scenarios.sh — rehearse scripts/dnb-staging-redeploy.sh (migration 030, docs/125
# §E) against a sandbox rebuilt, from nothing, into the state the staging server
# is in now: the 028 build at digest 1bc95524…, the simulated estate, the stage-2
# route, the API switched to the real DishNet staff login by the REAL
# scripts/dnb-staging-staff-login.sh (docs/122), and then migration 029 applied by
# the REAL 029 command, exactly as the operator ran it at 2026-09-24 04:14 UTC
# (docs/124 §H; the script as it stood at commit 938c002). The redeploy script
# under test is the real one; only `docker` is faked (bin/docker), and every
# database it touches is a real PostgreSQL database.
#
#   HSIM_SANDBOX=yes-this-is-not-the-server bash scripts/harness/redeploy/scenarios.sh
#
# The build the redeploy fetches comes from a bare clone of THIS repository's
# committed history: the pinned commit must be committed before running.
set -uo pipefail
. "$(dirname "$0")/../staff-login/guard.sh"
. "$(dirname "$0")/../staff-login/lib.sh"
S=$HSIM/state; APPD=/opt/dnb-staging
D028=1bc95524cd36f38413b5325fe26cdf76a20cb9d67e4253051c5b0bae7a7af74b
D029=780023ff04545bb8b5eb921c63b9e7bb7d885b3079ecc6069c1797d89b1c46d0
S029=c558e26a1d467d9e1d3bd210f9ffb0e3719aca552ed66bfbbaf54e7936dc9e3c   # the 029 command as the operator ran it
SCRIPT=$REPO/scripts/dnb-staging-redeploy.sh
D030=$(grep -m1 '^DIGEST=' "$SCRIPT" | cut -d= -f2)
PIN=$(grep -m1 '^COMMIT=' "$SCRIPT" | cut -d= -f2)
rm -f "$S/results"
# The sandbox's API and fake Traefik hold 127.0.0.1:8099 and :443; release them at
# exit. Role memberships are CLUSTER-WIDE: a scenario that grants one must never
# leave it behind in the development cluster.
cleanup() {
  docker rm -f dnb-staging-api >/dev/null 2>&1 || true
  pkill -f '[s]taff-login/traefik[.]py' 2>/dev/null || true
  sql "REVOKE dnb_adminwrite FROM dnb_admin" >/dev/null 2>&1 || true
}
trap cleanup EXIT

digest() { sha256sum "$1/SHA256SUMS" 2>/dev/null | cut -d' ' -f1; }
ledger() { sql "SELECT count(*) FROM mt_migrations"; }
o1()     { sql "SELECT count(*) FROM pg_constraint WHERE conname IN ('mt_sites_service_customer_fkey','mt_services_id_customer_key')"; }
store()  { sql "SELECT coalesce(to_regclass('public.mt_admin_idempotency')::text, 'absent')"; }
estate() { sql "SELECT (SELECT count(*) FROM mt_customers) || '|' || (SELECT count(*) FROM mt_services) || '|' || (SELECT count(*) FROM mt_sites) || '|' || (SELECT count(*) FROM mt_audit_log)"; }
residue(){ sql "SELECT (SELECT count(*) FROM mt_audit_log WHERE actor = 'redeploy-probe') + (SELECT count(*) FROM mt_customers WHERE name LIKE 'O30-%') + (SELECT count(*) FROM mt_sites WHERE name LIKE 'O30-%')"; }
prevd()  { for d in "$APPD"/app.prev-*; do [ -d "$d" ] && digest "$d"; done; }   # one digest per line
anon()   { curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' --data '{}' "http://127.0.0.1:8099/api/v1/admin/$1"; }

# The 028 build, reproduced from the commit that was deployed, and checked.
build028() {
  [ -f "$HSIM/build028.tar.gz" ] && return 0
  local W=$HSIM/b028; rm -rf "$W"; mkdir -p "$W/src" "$W/x"
  git -C "$REPO" archive 9f95353 dishnet-hybrid-sudan/dishnet-mikrotik-control-plane | tar -x -C "$W/src"
  sh "$W/src/dishnet-hybrid-sudan/dishnet-mikrotik-control-plane/plugin/bin/package.sh" "$W/dist" > "$W/pkg.out" 2>&1
  local t; t=$(ls "$W"/dist/*.tar.gz); tar -xzf "$t" -C "$W/x" --strip-components=1
  [ "$(digest "$W/x")" = "$D028" ] || { echo "the 028 build does not reproduce its digest"; exit 1; }
  cp "$t" "$HSIM/build028.tar.gz"
}
# fresh <real|dev>: the staging state of 2026-09-23 22:08 UTC (ledger 28).
fresh() {
  rm -rf "${APPD:?}"/app "${APPD:?}"/app.prev-* "${APPD:?}"/app.new-* "${APPD:?}"/app.refused-* "${APPD:?}"/app.failed-*
  mkdir -p "$APPD/app"; tar -xzf "$HSIM/build028.tar.gz" -C "$APPD/app" --strip-components=1
  bash "$REPO/scripts/harness/staff-login/reset.sh" 127.0.0.1 > "$HSIM/reset.out" 2>&1 || { tail -20 "$HSIM/reset.out"; exit 1; }
  echo "DN_DELIVERY=simulated" > "$S/worker.env"; : > "$S/worker.log"
  if [ "$1" = real ]; then
    bash "$REPO/scripts/harness/staff-login/run.sh" switch notty
    grep -q '^EXIT=0$' "$HSIM/switch.log" || { echo "the staff-login switch did not complete:"; tail -25 "$HSIM/switch.log"; exit 1; }
  fi
  : > "$S/calls.log"; rm -f "$S/race.log" "$S/inject.log"
}
# redeploy <name> [script]: a real script, the harness source, stdin closed.
redeploy() {
  local n=$1 sc=${2:-$SCRIPT}
  : > "$S/calls.log"
  { DNB_REDEPLOY_REPO="file://$HSIM/remote/dishnetuganda" sh "$sc" 2>&1; echo "EXIT=$?"; } > "$HSIM/$n.log" < /dev/null
}
# fresh029 <real|dev>: the staging state NOW — fresh, then the REAL 029 command, as run on 2026-09-24.
fresh029() {
  fresh "$1"
  redeploy "setup029-$1" "$HSIM/redeploy-029.sh"
  grep -q '^EXIT=0$' "$HSIM/setup029-$1.log" && grep -q '^=== REDEPLOYED .*migrations 29 ' "$HSIM/setup029-$1.log" \
    || { echo "the 029 command did not complete in the sandbox:"; tail -25 "$HSIM/setup029-$1.log"; exit 1; }
  [ "$(ledger)|$(digest "$APPD/app")" = "29|$D029" ] || { echo "not the post-029 state"; exit 1; }
  : > "$S/calls.log"; rm -f "$S/inject.log"
}

echo "== setup: the 028 build, the 029 command as run, a bare clone, and a branch that has MOVED ON since review"
build028
git -C "$REPO" show 938c002:scripts/dnb-staging-redeploy.sh > "$HSIM/redeploy-029.sh"
check "the 029 command is byte-for-byte the one the operator ran" eq "$(sha256sum "$HSIM/redeploy-029.sh" | cut -d' ' -f1)" "$S029"
rm -rf "$HSIM/remote"; mkdir -p "$HSIM/remote"
git clone -q --bare "$REPO" "$HSIM/remote/dishnetuganda.git"
git -C "$HSIM/remote/dishnetuganda.git" cat-file -e "$PIN^{commit}" || { echo "the pinned commit $PIN is not in this repository"; exit 1; }
# A later commit on the branch that changes a packaged file: the command must still build the pinned commit.
W=$HSIM/remote/work; git clone -q -b claude/study-this-jhe2eg "$HSIM/remote/dishnetuganda.git" "$W"
echo "// a later change on the branch (redeploy harness)" >> "$W/dishnet-hybrid-sudan/dishnet-mikrotik-control-plane/src/autoload.php"
git -C "$W" -c user.email=harness@localhost -c user.name=harness commit -qam "a later change on the branch"
git -C "$W" push -q origin claude/study-this-jhe2eg
sh "$W/dishnet-hybrid-sudan/dishnet-mikrotik-control-plane/plugin/bin/package.sh" "$HSIM/remote/tipdist" > /dev/null 2>&1
mkdir -p "$HSIM/remote/tipx"; tar -xzf "$HSIM"/remote/tipdist/*.tar.gz -C "$HSIM/remote/tipx" --strip-components=1
echo "   pinned $PIN; branch tip now $(git -C "$HSIM/remote/dishnetuganda.git" rev-parse --short claude/study-this-jhe2eg)"
check "the branch tip no longer builds the reviewed digest" bash -c "[ \"\$(sha256sum $HSIM/remote/tipx/SHA256SUMS | cut -d' ' -f1)\" != $D030 ]"

echo; echo "== R1 the staging state now (real staff login, 029 applied by its own command): redeploy applies 030 and verifies it"
fresh029 real
check "precondition: 029 build deployed, ledger 29, O-1 key, no store" eq "$(digest "$APPD/app")|$(ledger)|$(o1)|$(store)" "$D029|29|2|absent"
check "precondition: the API runs the real provider"  has "$S/api.env" '^DN_STAFF_IDENTITY=dishnet$'
E0=$(estate)
redeploy r1; L=$HSIM/r1.log
check "exit 0"                                        has "$L" '^EXIT=0$'
check "API identity detected as the real login"       has "$L" '^API identity: the real DishNet staff login'
check "029 found applied; 030 pending"                has "$L" '^ok: migration 029 is applied'
check "O-1 before: 1|1|2|true"                        has "$L" '^O-1 before .*: 1\|1\|2\|true$'
check "the pinned commit was built, not the tip"      has "$L" "^$PIN\$"
check "artifact-ok with the reviewed digest"          has "$L" "^artifact-ok: .*content digest $D030"
check "doctor's warning is printed: 29 of 30 applied" has "$L" 'warn +plugin schema +29 of 30 migration\(s\) applied'
check "doctor in production posture, no blocker"      has "$L" '^doctor \(production posture\): no blocker'
check "the installer applied exactly 030"             has "$L" 'applied 1 migration\(s\): 030_admin_operator_onboarding.sql'
check "ledger 30, latest 030"                         has "$L" '^migrations after: 30 \(last 030_admin_operator_onboarding.sql\)'
check "store: dnb_def_prov, no row security"          has "$L" '^the store mt_admin_idempotency: owner dnb_def_prov, row security off$'
check "writers 3|3|0"                                 has "$L" '^writers .*: 3\|3\|0$'
check "helpers 2|0"                                   has "$L" '^helpers .*: 2\|0$'
check "the location writer takes no operator"         has "$L" '^the location writer takes: p_service uuid, p_name text, p_location text, p_idempotency_key text, p_actor text$'
check "O-1 after: 1|1|2|true"                         has "$L" '^O-1 after .*: 1\|1\|2\|true$'
check "projection check: sites seen, none crossing"   has "$L" 'another operator.s\): [1-9][0-9]*\|0$'
check "probe ran as dnb_adminwrite"                   has "$L" '^  who\|dnb_adminwrite$'
check "probe: a replay returned the same operator"    bash -c "op=\$(sed -n 's/^  op|false|//p' '$L'); [ -n \"\$op\" ] && grep -q \"^  op-replay|true|\$op\$\" '$L'"
check "probe: the reused key refused"                 has "$L" 'ERROR: +this idempotency key was already used for a different request'
check "probe: the location's operator derived"        has "$L" '^execution test \(rolled back\): dnb_adminwrite created an operator'
check "dnb_adminwrite refused the store"              has "$L" '^  dnb_adminwrite: store refused 1 of 1; writers refused 0 of 0$'
check "dnb_app refused the store and all writers"     has "$L" '^  dnb_app: store refused 1 of 1; writers refused 3 of 3$'
check "dnb_admin refused the store and all writers"   has "$L" '^  dnb_admin: store refused 1 of 1; writers refused 3 of 3$'
check "the owner refused too"                         has "$L" '^  dnb: store refused 1 of 1; writers refused 3 of 3$'
check "every login role in the cluster was tested"    bash -c "[ \"\$(grep -cE '^  [a-z0-9_]+: store refused 1 of 1' '$L')\" = \"\$(psql -h /var/tmp -p 55432 -U postgres -d postgres -Atc \"SELECT count(*) FROM pg_roles WHERE rolcanlogin AND NOT rolsuper\")\" ]"
check "residue 0, by the script"                      has "$L" '^residue: 0 '
check "loopback: 200/200/200, gate fix, 401 dishnet"  has "$L" '^loopback: panel 200, routers.js 200, onboarding.js 200, sign-in gate fix in the page: 1, GET /api/v1/admin/session 401 .*"provider":"dishnet"'
check "anonymous POSTs 401 on all three routes"       has "$L" '^anonymous POST .*: /customers 401, /customers/\{id\}/services 401, /sites 401$'
check "through Traefik 200, 200 and 401 dishnet"      has "$L" '^through Traefik: / 200, onboarding.js 200, GET /api/v1/admin/session 401 .*"provider":"dishnet"'
check "the worker came back simulated"                has "$L" '^worker binding: "delivery_binding":"simulated-routeros"$'
check "the script raised no warning of its own"      hasnt "$L" '^WARN:'
check "exactly the two containers changed"            has "$L" '^containers whose status/StartedAt/RestartCount changed: dnb-staging-api dnb-staging-worker $'
check "result line"                                   has "$L" '^=== REDEPLOYED .*migrations 30 \(last 030_admin_operator_onboarding.sql\); onboarding: .*O-1 holds ===$'
check "database: ledger 30, store present, O-1 kept"  eq "$(ledger)|$(store)|$(o1)" "30|mt_admin_idempotency|2"
check "database: estate unchanged by the command"     eq "$(estate)" "$E0"
check "database: no probe residue anywhere"           eq "$(residue)|$(sql "SELECT count(*) FROM mt_admin_idempotency")" "0|0"
check "tree: the new build is live"                   eq "$(digest "$APPD/app")" "$D030"
check "tree: the 029 build is kept as a previous tree" eq "$(prevd | grep -cx "$D029")" 1
check "the API still runs the real provider, no development identity" bash -c "grep -q '^DN_STAFF_IDENTITY=dishnet$' '$S/api.env' && ! grep -q '^DN_DEV_STAFF_IDENTITY=' '$S/api.env'"
check "no container was removed or created"           bash -c "! grep -qE 'docker (rm|run -d)' '$S/calls.log'"
check "the restarted API routes and guards the three" eq "$(anon customers)|$(anon sites)" "401|401"

echo; echo "== R2 run again: already deployed, nothing to apply, everything re-verified"
redeploy r2; L=$HSIM/r2.log
check "exit 0"                                        has "$L" '^EXIT=0$'
check "notes the build is already deployed"           has "$L" '^NOTE: the reviewed build is already deployed'
check "notes 030 is already applied"                  has "$L" '^NOTE: migration 030 is already applied'
check "the installer applied nothing"                 has "$L" 'schema already current, no migration applied'
check "verification still passes"                     has "$L" '^=== REDEPLOYED .*migrations 30 '
check "ledger still 30, no residue"                   eq "$(ledger)|$(residue)" "30|0"

echo; echo "== R3 029 is NOT applied (ledger 28): refused in step 0, nothing changed"
fresh real
redeploy r3; L=$HSIM/r3.log
check "exit non-zero"                                 hasnt "$L" '^EXIT=0$'
check "says 030 goes on top of 029 and nothing else"  has "$L" 'this command applies 030 on top of 029 and nothing else, and the ledger reads 28'
check "tree unchanged; no new, prev or refused tree"  bash -c "[ \"\$(sha256sum $APPD/app/SHA256SUMS | cut -d' ' -f1)\" = $D028 ] && ! ls -d $APPD/app.new-* $APPD/app.prev-* $APPD/app.refused-* >/dev/null 2>&1"
check "ledger 28, no O-1 key, no store"               eq "$(ledger)|$(o1)|$(store)" "28|0|absent"
check "no build, no installer, no restart"            bash -c "! grep -qE 'docker (run|restart)' '$S/calls.log'"

echo; echo "== R4 wrong digest pinned (a copy of the script): refused before anything changes"
fresh029 real
sed "s/^DIGEST=.*/DIGEST=$(printf '0%.0s' $(seq 64))/" "$SCRIPT" > "$HSIM/redeploy-wrongdigest.sh"
redeploy r4 "$HSIM/redeploy-wrongdigest.sh"; L=$HSIM/r4.log
check "exit non-zero"                                 hasnt "$L" '^EXIT=0$'
check "says the digest is not the reviewed build"     has "$L" 'is not the reviewed build 0{64} — nothing was changed'
check "tree unchanged, no new tree left"              bash -c "[ \"\$(sha256sum $APPD/app/SHA256SUMS | cut -d' ' -f1)\" = $D029 ] && ! ls -d $APPD/app.new-* >/dev/null 2>&1"
check "ledger 29, no store, no restart"               eq "$(ledger)|$(store)|$(grep -c restart "$S/calls.log")" "29|absent|0"

echo; echo "== R5 a default privilege that would hand dnb_admin the store: 030 refuses by itself; the previous tree is put back"
fresh029 real
HSIM_PRE_INSTALL_SQL="ALTER DEFAULT PRIVILEGES FOR ROLE dnb_def_prov IN SCHEMA public GRANT SELECT ON TABLES TO dnb_admin" redeploy r5; L=$HSIM/r5.log
check "the fault was planted"                         has "$S/inject.log" '^ALTER DEFAULT PRIVILEGES$'
check "exit non-zero"                                 hasnt "$L" '^EXIT=0$'
check "030's own check refused, naming the role"      has "$L" '030: login role dnb_admin holds a privilege on mt_admin_idempotency'
check "the script says the previous tree is back"     has "$L" 'the installer refused .* The previous tree is back at'
check "tree: the 029 build is live again"             eq "$(digest "$APPD/app")" "$D029"
check "tree: the refused build is kept aside"         eq "$(digest "$(ls -d "$APPD"/app.refused-* | head -1)")" "$D030"
check "ledger 29, no store, O-1 kept"                 eq "$(ledger)|$(store)|$(o1)" "29|absent|2"
check "no restart"                                    bash -c "! grep -q restart '$S/calls.log'"

echo; echo "== R7 a role membership granted AFTER the migration: the catalogue cannot see it; the execution test must"
fresh029 real
HSIM_POST_INSTALL_SQL="GRANT dnb_adminwrite TO dnb_admin WITH INHERIT TRUE" redeploy r7; L=$HSIM/r7.log
check "the fault was planted"                         has "$S/inject.log" '^GRANT ROLE$'
check "the catalogue still reads clean (3|3|0)"       has "$L" '^writers .*: 3\|3\|0$'
check "exit non-zero"                                 hasnt "$L" '^EXIT=0$'
check "the execution test names dnb_admin"            has "$L" 'dnb_admin was NOT refused every onboarding writer \(0 of 3 refused\)'
check "no restart: the old code still serves"         bash -c "! grep -q restart '$S/calls.log'"
check "the writes it made were rolled back"           eq "$(residue)|$(sql "SELECT count(*) FROM mt_admin_idempotency")" "0|0"
sql "REVOKE dnb_adminwrite FROM dnb_admin" > /dev/null
check "the membership is gone again (cluster-wide)"   eq "$(sql "SELECT count(*) FROM pg_auth_members WHERE roleid = 'dnb_adminwrite'::regrole AND member = 'dnb_admin'::regrole")" 0

echo; echo "== R8 a direct grant on the store AFTER the migration: the execution test must find it"
fresh029 real
HSIM_POST_INSTALL_SQL="GRANT SELECT ON mt_admin_idempotency TO dnb_app" redeploy r8; L=$HSIM/r8.log
check "the fault was planted"                         has "$S/inject.log" '^GRANT$'
check "exit non-zero"                                 hasnt "$L" '^EXIT=0$'
check "the execution test names dnb_app"              has "$L" 'dnb_app was NOT refused the store mt_admin_idempotency'
check "no restart"                                    bash -c "! grep -q restart '$S/calls.log'"

echo; echo "== R6 the stage-2 posture (development identity, basic auth): redeploy works there too"
fresh029 dev
redeploy r6; L=$HSIM/r6.log
check "exit 0"                                        has "$L" '^EXIT=0$'
check "API identity detected as development"          has "$L" '^API identity: the development identity'
check "doctor --disposable, no blocker"               has "$L" '^doctor \(--disposable'
check "anonymous POSTs 401 on all three routes"       has "$L" '^anonymous POST .*: /customers 401, /customers/\{id\}/services 401, /sites 401$'
check "through Traefik 401 (basic auth)"              has "$L" '^through Traefik: 401'
check "result line"                                   has "$L" '^=== REDEPLOYED .*migrations 30 '
check "database: ledger 30, store present, no residue" eq "$(ledger)|$(store)|$(residue)" "30|mt_admin_idempotency|0"

echo; echo "== controls on the controls: five broken copies of the script must each be caught"
# M1: both step-0 gates removed — R3's state must no longer be refused, and 029 would ride in without its census.
sed -e 's/^  \*) fail "this command applies 030 on top of 029.*;;$/  *) : ;;/' \
    -e 's/^\[ "\$k0" = "1|1|2|true" \] || fail .*$/:/' "$SCRIPT" > "$HSIM/m1.sh"
check "M1 mutation applied (two lines)" eq "$(diff "$SCRIPT" "$HSIM/m1.sh" | grep -c '^>')" 2
fresh real; redeploy m1 "$HSIM/m1.sh"
check "M1 (no 029-first gate) is caught: the refusal is missing" hasnt "$HSIM/m1.log" 'on top of 029 and nothing else'
check "M1 is caught: R3's 'ledger stays 28' would fail"          bash -c "[ \"\$(psql -h /var/tmp -p 55432 -U postgres -d dnb_hsim -Atc 'SELECT count(*) FROM mt_migrations')\" != 28 ]"
# M2: no swap-back when the installer refuses — R5's tree assertion must fail.
sed 's/^  mv "\$APP\/app" "\$APP\/app.refused-\$TS" && mv "\$APP\/app.prev-\$TS" "\$APP\/app"$/  :/' "$SCRIPT" > "$HSIM/m2.sh"
check "M2 mutation applied" eq "$(diff "$SCRIPT" "$HSIM/m2.sh" | grep -c '^>')" 1
fresh029 real
HSIM_PRE_INSTALL_SQL="ALTER DEFAULT PRIVILEGES FOR ROLE dnb_def_prov IN SCHEMA public GRANT SELECT ON TABLES TO dnb_admin" redeploy m2 "$HSIM/m2.sh"
check "M2 (no swap-back) is caught: the refused build is left live" eq "$(digest "$APPD/app")" "$D030"
# M3: the writer refusal no longer fails the run — R7's leak must then pass unnoticed.
sed 's/^  \[ "\$w_ok" = yes \] || fail .*$/  :/' "$SCRIPT" > "$HSIM/m3.sh"
check "M3 mutation applied" eq "$(diff "$SCRIPT" "$HSIM/m3.sh" | grep -c '^>')" 1
fresh029 real
HSIM_POST_INSTALL_SQL="GRANT dnb_adminwrite TO dnb_admin WITH INHERIT TRUE" redeploy m3 "$HSIM/m3.sh"
sql "REVOKE dnb_adminwrite FROM dnb_admin" > /dev/null
check "M3 (writer refusal not enforced) is caught: R7's exit-non-zero fails" has "$HSIM/m3.log" '^EXIT=0$'
# M4: the store refusal no longer fails the run — R8's grant must then pass unnoticed.
sed 's/^  \[ "\$s_ok" = yes \] || fail .*$/  :/' "$SCRIPT" > "$HSIM/m4.sh"
check "M4 mutation applied" eq "$(diff "$SCRIPT" "$HSIM/m4.sh" | grep -c '^>')" 1
fresh029 real
HSIM_POST_INSTALL_SQL="GRANT SELECT ON mt_admin_idempotency TO dnb_app" redeploy m4 "$HSIM/m4.sh"
check "M4 (store refusal not enforced) is caught: R8's exit-non-zero fails" has "$HSIM/m4.log" '^EXIT=0$'
# M5: the execution test COMMITS — the script's own residue check must fail it.
sed 's/^ROLLBACK; -- the execution test keeps nothing$/COMMIT;/' "$SCRIPT" > "$HSIM/m5.sh"
check "M5 mutation applied" eq "$(diff "$SCRIPT" "$HSIM/m5.sh" | grep -c '^>')" 1
fresh029 real; redeploy m5 "$HSIM/m5.sh"
check "M5 (probe committed) is caught by the script's residue check" has "$HSIM/m5.log" 'the execution tests left [1-9][0-9]* row\(s\) behind'
check "M5 exits non-zero"                             hasnt "$HSIM/m5.log" '^EXIT=0$'

p=$(grep -c P "$S/results"); f=$(grep -c F "$S/results")
echo; echo "redeploy harness: $p passed, $f failed"
[ "$f" = 0 ]
