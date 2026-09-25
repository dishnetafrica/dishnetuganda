#!/bin/bash
# scenarios.sh — every path of scripts/dnb-staging-staff-login.sh, asserted.
# Exit 0 only when every assertion passes. Needs: root, python3 + PyYAML,
# `script` (util-linux), openssl, curl, psql, a local PostgreSQL 16 on
# /var/tmp:55432 that also listens on 127.0.0.1 with scram-sha-256, and port 443.
#   HSIM_SANDBOX=yes-this-is-not-the-server scripts/harness/staff-login/scenarios.sh
. "$(dirname "$0")/guard.sh"; . "$HSIM_HOME/lib.sh"
: > "$HSIM/state/results"
H=$HSIM_HOME; L() { echo "$HSIM/$1.log"; }; RX='[a-z2-9]{6}(-[a-z2-9]{6}){3}'
before() { awk -v a="$2" -v b="$3" '$0 ~ a && !x {x=NR} $0 ~ b && !y {y=NR} END {exit !(x && y && x < y)}' "$1"; }
rolled_back() {
  local n=$1
  check "$n: route file byte-identical to the stage-2 file" cmp -s "$HSIM/state/route.stage2" "$ROUTE"
  check "$n: API back on the development identity, nothing else" eq "$(api_env)" "DN_DEV_STAFF_IDENTITY=yes-development-only "
  check "$n: no staff row was created" eq "$(sql 'select count(*) from mt_staff')" 0
  check "$n: the hostname answers basic auth's 401 again" eq "$(via -o /dev/null -w '%{http_code}' https://portal-staging.dishnetuganda.com/)" 401
  check "$n: no temporary file left in the route directory" eq "$(ls -A /etc/easypanel/traefik/config | grep -vx dnb-staging.yml | wc -l)" 0
}
switched() {
  local n=$1 a=$2
  check "$n: exit 0" has "$(L "$n")" '^EXIT=0$'
  check "$n: PROVED from $a" has "$(L "$n")" "^PROVED: TLS is recognised from ${a//./\\.} "
  check "$n: API env is exactly the real provider, DN_TRUSTED_PROXY=$a" eq "$(api_env)" "DN_PORTAL_ORIGIN=https://portal-staging.dishnetuganda.com DN_STAFF_IDENTITY=dishnet DN_TRUSTED_PROXY=$a "
  check "$n: route has no basic auth; rate limit, headers and certificate kept" sh -c "! grep -q dnb-staging-auth $ROUTE && grep -q dnb-staging-ratelimit $ROUTE && grep -q dnb-staging-headers $ROUTE && grep -q 'certResolver: letsencrypt' $ROUTE"
  check "$n: only dnb-staging-api changed" has "$(L "$n")" '^containers whose status/StartedAt/RestartCount changed: dnb-staging-api \(expected'
}

echo "== guard: controls on the refusal =="
check "guard: refuses without HSIM_SANDBOX" eq "$(HSIM_SANDBOX= "$H/reset.sh" >/dev/null 2>&1; echo $?)" 2
mkdir -p /etc/easypanel/traefik/config; : > /etc/easypanel/traefik/config/traefik-mail.yml
check "guard: refuses where the server's mail route file exists" eq "$("$H/reset.sh" >/dev/null 2>&1; echo $?)" 2
rm -f /etc/easypanel/traefik/config/traefik-mail.yml
mv /opt/dnb-staging/.hsim-sandbox /opt/dnb-staging/.hsim-sandbox.off
check "guard: refuses an /opt/dnb-staging it did not create" eq "$("$H/reset.sh" >/dev/null 2>&1; echo $?)" 2
mv /opt/dnb-staging/.hsim-sandbox.off /opt/dnb-staging/.hsim-sandbox

echo "== A: Traefik's connections carry the gateway address (the expected case), under a terminal =="
"$H/reset.sh" 127.0.0.1; "$H/run.sh" A tty
switched A 127.0.0.1
check "A: proved on the first attempt, no correction" hasnt "$(L A)" 'correcting once'
check "A: doctor in production posture reports 0 blockers" has "$(L A)" 'checks: .*, 0 blocker'
check "A: over plain HTTP the real provider answers 403 insecure_transport" has "$(L A)" 'over plain HTTP → 403 .*insecure_transport'
check "A: the administrator is created only AFTER the proof" before "$(L A)" '^PROVED' 'created the first DishNet administrator'
check "A: the sign-in box went to the terminal, no password file" sh -c "grep -q 'the sign-in box is on your terminal' $(L A) && ! ls /root/dnb-staging-evidence/*.password.txt >/dev/null 2>&1"
check "A: widening measured: loopback carries the trusted address (401)" has "$(L A)" '^MEASURED: a host-local client .*\(401\)'
check "A: exactly one staff row" eq "$(sql 'select count(*) from mt_staff')" 1
"$H/journey.sh" A

echo "== B: Traefik's connections carry ANOTHER address; the script corrects once; no terminal =="
"$H/reset.sh" 127.0.0.2; "$H/run.sh" B notty
switched B 127.0.0.2
check "B: corrected once, from the gateway to the measured address" has "$(L B)" 'correcting once: recreating the API with DN_TRUSTED_PROXY=127\.0\.0\.2'
check "B: no terminal: the password went to a 0600 file" eq "$(stat -c %a /root/dnb-staging-evidence/*.password.txt 2>/dev/null)" 600
check "B: widening measured: loopback does not carry the trusted address (403)" has "$(L B)" '^MEASURED: a host-local client .* is refused \(403\)'
"$H/journey.sh" B

echo "== C: Traefik sends no X-Forwarded-Proto; the attempt comes from the trusted address =="
"$H/reset.sh" 127.0.0.1; touch "$HSIM/state/traefik.dropxfp"; "$H/run.sh" C
check "C: exit 1" has "$(L C)" '^EXIT=1$'
check "C: stops on the missing X-Forwarded-Proto, without claiming a wrong address" sh -c "grep -q 'Traefik did not send X-Forwarded-Proto' $(L C) && ! grep -q 'is not the address' $(L C)"
rolled_back C; rm -f "${HSIM:?}/state/traefik.dropxfp"

echo "== C2: no X-Forwarded-Proto and another address: corrects once, still refused, rolls back =="
"$H/reset.sh" 127.0.0.2; touch "$HSIM/state/traefik.dropxfp"; "$H/run.sh" C2
check "C2: exit 1 after one correction" sh -c "grep -q '^EXIT=1$' $(L C2) && grep -q 'correcting once' $(L C2) && grep -q 'STOP: expected 401 invalid_credentials' $(L C2)"
rolled_back C2; rm -f "${HSIM:?}/state/traefik.dropxfp"

echo "== E: the doctor reports a blocker in production posture =="
"$H/reset.sh" 127.0.0.1; echo 'DN_STAFF_REQUIRE_TOTP=no' >> /opt/dnb-staging/env/install.env; "$H/run.sh" E
check "E: exit 1 on '1 blocker'" sh -c "grep -q '^EXIT=1$' $(L E) && grep -q ', 1 blocker' $(L E) && grep -q 'STOP: the doctor reports a blocker' $(L E)"
rolled_back E

echo "== F: the real-provider container fails to start =="
"$H/reset.sh" 127.0.0.1; HSIM_FAIL_RUN=1 "$H/run.sh" F
check "F: exit 1, could not start" sh -c "grep -q '^EXIT=1$' $(L F) && grep -q 'STOP: could not start the API with the real provider' $(L F)"
rolled_back F

echo "== D: a second run after success is idempotent =="
"$H/reset.sh" 127.0.0.1; "$H/run.sh" D1; "$H/run.sh" D2
switched D2 127.0.0.1
check "D2: recognised the real provider, route and staff already in place" sh -c "grep -q 'already runs the real provider' $(L D2) && grep -q 'route unchanged' $(L D2) && grep -q 'skipped: 1 staff row' $(L D2)"

echo "== D3: a re-run whose proof fails restores the REAL provider, not the development identity =="
touch "$HSIM/state/traefik.dropxfp"; "$H/run.sh" D3
check "D3: exit 1" has "$(L D3)" '^EXIT=1$'
check "D3: API back on the real provider it had" eq "$(api_env)" "DN_PORTAL_ORIGIN=https://portal-staging.dishnetuganda.com DN_STAFF_IDENTITY=dishnet DN_TRUSTED_PROXY=127.0.0.1 "
check "D3: staff row kept; route still without basic auth" sh -c "[ \"\$(psql -h ${HSIM_PGHOST:-/var/tmp} -p ${HSIM_PGPORT:-55432} -U postgres -d ${HSIM_DB:-dnb_hsim} -Atc 'select count(*) from mt_staff')\" = 1 ] && ! grep -q dnb-staging-auth $ROUTE"
rm -f "${HSIM:?}/state/traefik.dropxfp"
check "D3: with Traefik healthy again the restored provider answers 401 invalid_credentials" eq "$(via -H Origin:https://portal-staging.dishnetuganda.com -H Content-Type:application/json -o /dev/null -w '%{http_code}' -d '{"username":"nobody","password":"x"}' https://portal-staging.dishnetuganda.com/api/v1/admin/session)" 401

echo "== G: the bootstrap output has a password line no parser expects: the password is still delivered =="
"$H/reset.sh" 127.0.0.1; HSIM_MANGLE_BOOTSTRAP=1 "$H/run.sh" G notty
check "G: exit 0; the whole output was sent to the operator instead" sh -c "grep -q '^EXIT=0$' $(L G) && grep -q 'could not be picked out' $(L G)"
check "G: the 0600 file carries the password; the log does not" sh -c "grep -qE '$RX' /root/dnb-staging-evidence/*.password.txt && ! grep -qE '$RX' $(L G)"
"$H/journey.sh" G

p=$(grep -c P "$HSIM/state/results" || true); f=$(grep -c F "$HSIM/state/results" || true)
echo; echo "=== scenarios: $p passed, $f failed ==="
[ "$f" = 0 ]
