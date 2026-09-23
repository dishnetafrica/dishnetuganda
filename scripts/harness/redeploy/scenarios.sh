#!/bin/bash
# scenarios.sh — rehearse scripts/dnb-staging-redeploy.sh (migration 029, docs/124)
# against a sandbox rebuilt, from nothing, into the state the staging server is
# in now: the 028 build at digest 1bc95524…, the simulated estate at ledger 28,
# the stage-2 route, and the API switched to the real DishNet staff login by
# the REAL scripts/dnb-staging-staff-login.sh (docs/122). The redeploy script
# under test is the real one; only `docker` is faked (bin/docker), and every
# database it touches is a real PostgreSQL database.
#
#   HSIM_SANDBOX=yes-this-is-not-the-server bash scripts/harness/redeploy/scenarios.sh
#
# The build the redeploy fetches comes from a bare clone of THIS repository's
# committed branch: commit the code before running, or the digest will not match.
set -uo pipefail
. "$(dirname "$0")/../staff-login/guard.sh"
. "$(dirname "$0")/../staff-login/lib.sh"
S=$HSIM/state; APPD=/opt/dnb-staging; EVD=/root/dnb-staging-evidence
D028=1bc95524cd36f38413b5325fe26cdf76a20cb9d67e4253051c5b0bae7a7af74b
D029=$(grep -m1 '^DIGEST=' "$REPO/scripts/dnb-staging-redeploy.sh" | cut -d= -f2)
SCRIPT=$REPO/scripts/dnb-staging-redeploy.sh
rm -f "$S/results"
# The sandbox's API and fake Traefik hold 127.0.0.1:8099 and :443; release them at exit
# so nothing else on this machine (the install test uses 8099) collides with them.
cleanup() { docker rm -f dnb-staging-api >/dev/null 2>&1 || true; pkill -f '[s]taff-login/traefik[.]py' 2>/dev/null || true; }
trap cleanup EXIT

digest() { sha256sum "$1/SHA256SUMS" 2>/dev/null | cut -d' ' -f1; }
ledger() { sql "SELECT count(*) FROM mt_migrations"; }
o1()     { sql "SELECT count(*) FROM pg_constraint WHERE conname IN ('mt_sites_service_customer_fkey','mt_services_id_customer_key')"; }
forced() { sql "SELECT bool_and(relrowsecurity AND relforcerowsecurity) FROM pg_class WHERE relname IN ('mt_sites','mt_services')"; }
nodir()  { ! ls -d "$APPD"/$1 >/dev/null 2>&1; }
called() { grep -qE -- "$1" "$S/calls.log"; }

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
# fresh <real|dev>: the staging state of 2026-09-23 22:08 UTC (real), or the stage-2 posture (dev).
fresh() {
  rm -rf "${APPD:?}"/app "${APPD:?}"/app.prev-* "${APPD:?}"/app.new-* "${APPD:?}"/app.refused-* "${APPD:?}"/app.failed-*
  mkdir -p "$APPD/app"; tar -xzf "$HSIM/build028.tar.gz" -C "$APPD/app" --strip-components=1
  bash "$REPO/scripts/harness/staff-login/reset.sh" 127.0.0.1 > "$HSIM/reset.out" 2>&1 || { tail -20 "$HSIM/reset.out"; exit 1; }
  echo "DN_DELIVERY=simulated" > "$S/worker.env"; : > "$S/worker.log"
  if [ "$1" = real ]; then
    bash "$REPO/scripts/harness/staff-login/run.sh" switch notty
    grep -q '^EXIT=0$' "$HSIM/switch.log" || { echo "the staff-login switch did not complete:"; tail -25 "$HSIM/switch.log"; exit 1; }
  fi
  : > "$S/calls.log"; rm -f "$S/race.log"
}
# redeploy <name> [script]: the real script, the harness source, stdin closed.
redeploy() {
  local n=$1 sc=${2:-$SCRIPT}
  : > "$S/calls.log"
  { DNB_REDEPLOY_REPO="file://$HSIM/remote/dishnetuganda" sh "$sc" 2>&1; echo "EXIT=$?"; } > "$HSIM/$n.log" < /dev/null
}
violate() {   # one site of one operator pointed at another operator's service — legal at 028
  sql "UPDATE mt_sites SET service_id = (SELECT v.id FROM mt_services v WHERE v.customer_id <> mt_sites.customer_id ORDER BY v.id LIMIT 1)
        WHERE id = (SELECT id FROM mt_sites ORDER BY id LIMIT 1)" > /dev/null
}

PIN=$(grep -m1 '^COMMIT=' "$SCRIPT" | cut -d= -f2)
echo "== setup: the 028 build, a bare clone of this repository, and a branch that has MOVED ON since review"
build028
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
check "the branch tip no longer builds the reviewed digest" bash -c "[ \"\$(sha256sum $HSIM/remote/tipx/SHA256SUMS | cut -d' ' -f1)\" != $D029 ]"

echo; echo "== R1 the staging state now (real staff login, ledger 28): redeploy applies 029 and verifies it"
fresh real
check "precondition: 028 build deployed"            eq "$(digest "$APPD/app")" "$D028"
check "precondition: ledger 28, no O-1 key"          eq "$(ledger)|$(o1)" "28|0"
check "precondition: the API runs the real provider" has "$S/api.env" '^DN_STAFF_IDENTITY=dishnet$'
redeploy r1; L=$HSIM/r1.log
check "exit 0"                                       has "$L" '^EXIT=0$'
check "API identity detected as the real login"      has "$L" '^API identity: the real DishNet staff login'
check "the pinned commit was built, not the tip"     has "$L" "^$PIN\$"
check "digest refused nothing: artifact-ok"          has "$L" "^artifact-ok: .*content digest $D029"
check "census before: CLEAR"                         has "$L" '^census before the migration: CLEAR, [1-9][0-9]* row'
check "doctor in production posture, no blocker"     has "$L" '^doctor \(production posture\): no blocker'
check "the installer applied exactly 029"            has "$L" 'applied 1 migration\(s\): 029_o1_site_service_same_operator.sql'
check "ledger 29, latest 029"                        has "$L" '^migrations after: 29 \(last 029_o1_site_service_same_operator.sql\)'
check "catalogue 1|1|2|true"                         has "$L" '1\|1\|2\|true$'
check "census after: CLEAR"                          has "$L" '^census after the migration: CLEAR, [1-9][0-9]* row'
check "projection check: sites seen, none crossing"  has "$L" 'another operator.s\): [1-9][0-9]*\|0$'
check "execution test: refused by name"              has "$L" 'violates foreign key constraint "mt_sites_service_customer_fkey"'
check "execution test: control accepted, residue 0"  has "$L" '^execution test \(rolled back\): dnb_app, .*own site accepted.*residue 0$'
check "loopback 200/200/401 naming dishnet"          has "$L" '^loopback: panel 200, routers.js 200, GET /api/v1/admin/session 401 .*"provider":"dishnet"'
check "through Traefik 200 and 401 naming dishnet"   has "$L" '^through Traefik: / 200, GET /api/v1/admin/session 401 .*"provider":"dishnet"'
check "the script raised no warning of its own"     hasnt "$L" '^WARN:'
check "exactly the two containers changed"           has "$L" '^containers whose status/StartedAt/RestartCount changed: dnb-staging-api dnb-staging-worker $'
check "result line"                                  has "$L" '^=== REDEPLOYED .*migrations 29 .*census CLEAR before and CLEAR after, cross-operator site refused by name ==='
check "database: ledger 29, both constraints, FORCE" eq "$(ledger)|$(o1)|$(forced)" "29|2|t"
check "database: no probe residue"                   eq "$(sql "SELECT count(*) FROM mt_sites WHERE name LIKE 'O1-PROBE-%'")" 0
check "tree: the new build is live"                  eq "$(digest "$APPD/app")" "$D029"
check "tree: the previous build is kept"             eq "$(digest "$(ls -d "$APPD"/app.prev-* | head -1)")" "$D028"
check "the API still runs the real provider"         has "$S/api.env" '^DN_STAFF_IDENTITY=dishnet$'
check "and no development identity"                  hasnt "$S/api.env" '^DN_DEV_STAFF_IDENTITY='
check "no container was removed or created"          bash -c "! grep -qE 'docker (rm|run -d)' '$S/calls.log'"
check "the restarted API answers as the real provider" eq "$(curl -s http://127.0.0.1:8099/api/v1/admin/session | grep -c '"provider":"dishnet"')" 1

echo; echo "== R2 run again: already deployed, nothing to apply, everything re-verified"
redeploy r2; L=$HSIM/r2.log
check "exit 0"                                       has "$L" '^EXIT=0$'
check "notes the build is already deployed"          has "$L" '^NOTE: the reviewed build is already deployed'
check "the installer applied nothing"                has "$L" 'schema already current, no migration applied'
check "verification still passes"                    has "$L" '^=== REDEPLOYED .*migrations 29 '
check "ledger still 29"                              eq "$(ledger)" 29

echo; echo "== R5 wrong digest pinned (a copy of the script): refused before anything changes"
fresh real
sed "s/^DIGEST=.*/DIGEST=$(printf '0%.0s' $(seq 64))/" "$SCRIPT" > "$HSIM/redeploy-wrongdigest.sh"
redeploy r5 "$HSIM/redeploy-wrongdigest.sh"; L=$HSIM/r5.log
check "exit non-zero"                                hasnt "$L" '^EXIT=0$'
check "says the digest is not the reviewed build"    has "$L" 'is not the reviewed build 0{64} — nothing was changed'
check "tree unchanged, no new tree left"             bash -c "[ \"\$(sha256sum $APPD/app/SHA256SUMS | cut -d' ' -f1)\" = $D028 ] && ! ls -d $APPD/app.new-* >/dev/null 2>&1"
check "ledger 28, no restart"                        eq "$(ledger)|$(grep -c restart "$S/calls.log")" "28|0"

echo; echo "== R3 a cross-operator site exists: GATE 1 stops the run; nothing is changed"
violate
check "precondition: one violating row"              eq "$(sql "SELECT count(*) FROM mt_sites s JOIN mt_services v ON v.id = s.service_id WHERE s.customer_id <> v.customer_id")" 1
redeploy r3; L=$HSIM/r3.log
check "exit non-zero"                                hasnt "$L" '^EXIT=0$'
check "census before: BLOCKED(1)"                    has "$L" '^census before the migration: BLOCKED\(1\)'
check "stops with nothing changed"                   has "$L" 'the census does not read CLEAR \(BLOCKED\(1\)\) — nothing was changed'
check "tree unchanged; no new, prev or refused tree" bash -c "[ \"\$(sha256sum $APPD/app/SHA256SUMS | cut -d' ' -f1)\" = $D028 ] && ! ls -d $APPD/app.new-* $APPD/app.prev-* $APPD/app.refused-* >/dev/null 2>&1"
check "ledger 28, no O-1 key, FORCE on"              eq "$(ledger)|$(o1)|$(forced)" "28|0|t"
check "no installer run, no restart"                 bash -c "! grep -qE 'plugin.php install|restart' '$S/calls.log'"

echo; echo "== R4 a violating row appears AFTER the census: 029 refuses by itself; the previous tree is put back"
fresh real
HSIM_RACE=1 redeploy r4; L=$HSIM/r4.log
check "the race was staged"                          has "$S/race.log" '^UPDATE 1$'
check "and the violating row is still there, untouched" eq "$(sql "SELECT count(*) FROM mt_sites s JOIN mt_services v ON v.id = s.service_id WHERE s.customer_id <> v.customer_id")" 1
check "census before still read CLEAR"               has "$L" '^census before the migration: CLEAR'
check "exit non-zero"                                hasnt "$L" '^EXIT=0$'
check "029 refused with its count"                   has "$L" 'O-1 \(029\): 1 site\(s\) point at another operator'
check "the script says the previous tree is back"    has "$L" 'the installer refused .* The previous tree is back at'
check "tree: the previous build is live again"       eq "$(digest "$APPD/app")" "$D028"
check "tree: the refused build is kept aside"        eq "$(digest "$(ls -d "$APPD"/app.refused-* | head -1)")" "$D029"
check "ledger 28, no O-1 key, FORCE on"              eq "$(ledger)|$(o1)|$(forced)" "28|0|t"
check "no restart"                                   bash -c "! grep -q restart '$S/calls.log'"

echo; echo "== R6 the stage-2 posture (development identity, basic auth): redeploy works there too"
fresh dev
redeploy r6; L=$HSIM/r6.log
check "exit 0"                                       has "$L" '^EXIT=0$'
check "API identity detected as development"         has "$L" '^API identity: the development identity'
check "doctor --disposable, no blocker"              has "$L" '^doctor \(--disposable'
check "through Traefik 401 (basic auth)"             has "$L" '^through Traefik: 401'
check "result line"                                  has "$L" '^=== REDEPLOYED .*migrations 29 '
check "database: ledger 29, both constraints, FORCE" eq "$(ledger)|$(o1)|$(forced)" "29|2|t"

echo; echo "== controls on the controls: three broken copies of the script must each be caught"
# M1: the census gate removed — R3's state must no longer stop at GATE 1.
sed 's/^if \[ "\$V0" != CLEAR \]; then$/if false; then/' "$SCRIPT" > "$HSIM/m1.sh"
check "M1 mutation applied" bash -c "! cmp -s '$SCRIPT' '$HSIM/m1.sh'"
fresh real; violate; redeploy m1 "$HSIM/m1.sh"
check "M1 (no census gate) is caught: GATE-1 stop missing" hasnt "$HSIM/m1.log" 'the census does not read CLEAR'
# M2: no swap-back when the installer refuses — R4's tree assertion must fail.
sed 's/^  mv "\$APP\/app" "\$APP\/app.refused-\$TS" && mv "\$APP\/app.prev-\$TS" "\$APP\/app"$/  :/' "$SCRIPT" > "$HSIM/m2.sh"
check "M2 mutation applied" bash -c "! cmp -s '$SCRIPT' '$HSIM/m2.sh'"
fresh real; HSIM_RACE=1 redeploy m2 "$HSIM/m2.sh"
check "M2 (no swap-back) is caught: the refused build is left live" eq "$(digest "$APPD/app")" "$D029"
# M3: the execution test aims at the SAME operator — the script's own check must fail it.
sed 's/^  A=\$1; SA=\$2; SB=\$4$/  A=$1; SA=$2; SB=$2/' "$SCRIPT" > "$HSIM/m3.sh"
check "M3 mutation applied" bash -c "! cmp -s '$SCRIPT' '$HSIM/m3.sh'"
fresh real; redeploy m3 "$HSIM/m3.sh"
check "M3 (probe without a cross-operator target) is caught by the script" has "$HSIM/m3.log" 'the cross-operator site was NOT refused'
check "M3 exits non-zero"                            hasnt "$HSIM/m3.log" '^EXIT=0$'

p=$(grep -c P "$S/results"); f=$(grep -c F "$S/results")
echo; echo "redeploy harness: $p passed, $f failed"
[ "$f" = 0 ]
