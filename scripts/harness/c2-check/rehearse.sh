#!/usr/bin/env bash
# Rehearse scripts/dnb-c2-check.sh (the READ-ONLY guard around change C-2) against stubs: docker, ss, nsenter,
# curl and the UISP health script are replaced on PATH, so every verdict path runs here — including the one that
# tells the operator to put the uCRM values back. The ucrm.json in the fake plugin carries an app key; no run may
# print it. Refuses to run where the real server could be (a live ucrm container on this machine).
set -u
R="$(cd "$(dirname "$0")/../../.." && pwd)"; SCRIPT="${SCRIPT:-$R/scripts/dnb-c2-check.sh}"
if command -v docker >/dev/null 2>&1 && docker ps --format '{{.Names}}' 2>/dev/null | grep -qx ucrm; then echo "refusing: a real ucrm container runs here"; exit 2; fi
SB="$(mktemp -d)"; trap 'rm -rf "$SB"' EXIT
F="$SB/fake"; mkdir -p "$F/plugin" "$SB/bin" "$SB/state"
PASS=0; FAILN=0
check() { if [ "$1" = "$2" ]; then PASS=$((PASS+1)); echo "  ok   $3"; else FAILN=$((FAILN+1)); echo "  FAIL $3 (got '$1', want '$2')"; fi; }
has()   { if printf '%s' "$1" | grep -qF -- "$2"; then PASS=$((PASS+1)); echo "  ok   $3"; else FAILN=$((FAILN+1)); echo "  FAIL $3 (missing '$2')"; fi; }
hasnt() { if printf '%s' "$1" | grep -qF -- "$2"; then FAILN=$((FAILN+1)); echo "  FAIL $3 (found '$2')"; else PASS=$((PASS+1)); echo "  ok   $3"; fi; }

# ── stubs ──
cat > "$SB/bin/docker" <<'STUB'
#!/usr/bin/env bash
F="$FAKE"
case "$1" in
  exec)
    shift; [ "$1" = "-w" ] && { shift 2; W=1; } || W=0
    shift   # the container
    if [ "$1" = "true" ]; then exit 0; fi
    if [ "$W" = "1" ]; then cat "$F/pdf_line"; exit 0; fi       # the PDF scan needs uCRM: its answer is canned
    # the URL reader: the REAL php code, against the fake plugin directory
    shift; code=""; args=()
    while [ $# -gt 0 ]; do case "$1" in -r) code="$2"; shift 2;; -d) shift 2;; *) args+=("${1/\/data\/ucrm\/data\/plugins\/dishnet-hybrid-sudan/$F/plugin}"); shift;; esac; done
    php -r "$code" "${args[@]}" ;;
  ps) cat "$F/ps" 2>/dev/null ;;
  inspect) echo 4242 ;;
esac
STUB
cat > "$SB/bin/ss" <<'STUB'
#!/usr/bin/env bash
cat "$FAKE/ss_host" 2>/dev/null
STUB
cat > "$SB/bin/nsenter" <<'STUB'
#!/usr/bin/env bash
cat "$FAKE/ss_ns" 2>/dev/null
STUB
cat > "$SB/bin/curl" <<'STUB'
#!/usr/bin/env bash
u="${@: -1}"
case "$u" in */crm) echo "302 https://crm.dishnetuganda.com/crm/";; *) echo "200 ";; esac
STUB
cat > "$SB/health.sh" <<'STUB'
#!/usr/bin/env bash
if [ -f "$FAKE/health_bad" ]; then echo "  [FAIL]  8443/tcp is NOT bound"; exit 1; fi
echo "  [ OK ]  all good"; exit 0
STUB
chmod +x "$SB/bin/"* "$SB/health.sh"

setfake() {  # $1 ucrm address  $2 pdf line
  printf '{"ucrmPublicUrl":"%s","pluginPublicUrl":"%s_plugins/dishnet-hybrid-sudan/public.php","ucrmLocalUrl":"http://localhost/crm/","pluginAppKey":"APPKEY-SECRET-123"}' "$1" "$1" > "$F/plugin/ucrm.json"
  printf '%s\n' "$2" > "$F/pdf_line"
}
routers() {  # $1 = on|off : 6 public on the host (docker proxy), 3 public in the namespace (NAT), private ones never counted
  if [ "$1" = "on" ]; then
    printf '0 0 172.18.0.5:8443 %s\n' 41.210.1.1:50001 41.210.1.2:50002 41.210.1.3:50003 '[::ffff:102.80.4.4]:50004' 102.80.5.5:50005 102.80.5.5:50006 172.17.0.1:40000 10.0.0.9:40001 '[::ffff:172.18.0.1]:40002' 127.0.0.1:40003 > "$F/ss_host"
    printf '0 0 172.20.0.3:443 %s\n' 197.1.1.1:60001 197.1.1.2:60002 197.1.1.3:60003 172.17.0.1:60004 > "$F/ss_ns"
  else : > "$F/ss_host"; : > "$F/ss_ns"; fi
}
echo 'unms-nginx|0.0.0.0:8080->80/tcp, 0.0.0.0:8443->443/tcp' > "$F/ps"
run() { PATH="$SB/bin:$PATH" FAKE="$F" STATE_DIR="$SB/state" HEALTH_SCRIPT="$SB/health.sh" POLL_SECONDS=0 POLLS=3 bash "$SCRIPT" "$@" 2>&1; }

echo "S1 --before: the address with :8443, a PDF with :8443, routers connected"
setfake "https://crm.dishnetuganda.com:8443/crm/" "invoice 000005 · crm.dishnetuganda.com:8443 x2"; routers on
out="$(run --before)"; rc=$?
check "$rc" "0" "S1 exits 0"
has "$out" "routers connected on :8443            9 connection(s) from 8 address(es)" "S1 counts 9 public connections from 8 addresses (host + namespace; private and loopback peers excluded)"
has "$out" "14 connection(s) seen on the port in all" "S1 reports everything it saw on the port (the control's basis: 9 public + 5 private/loopback)"
has "$out" "uCRM's address, as it tells plugins   https://crm.dishnetuganda.com:8443/crm/" "S1 prints uCRM's own address"
has "$out" "the latest invoice's PDF              invoice 000005 · crm.dishnetuganda.com:8443 x2" "S1 prints the PDF's link hosts"
has "$out" "THIS SCRIPT CHANGES NOTHING. Between --before and --after, YOU make the change, by hand" "S1 prints the manual steps, and says the script makes no change"
has "$out" "If those fields are greyed out" "S1 tells the operator when to stop"
hasnt "$out" "APPKEY-SECRET-123" "S1 never prints the app key"
check "$(grep -c 'APPKEY' "$SB/state/state.env")" "0" "S1 the state file holds no app key"
check "$(grep -c '^BEFORE_DEV_N=9$' "$SB/state/state.env")" "1" "S1 the state file records the count"

echo "S2 --after: the address and the PDF changed, the routers stayed"
setfake "https://crm.dishnetuganda.com/crm/" "invoice 000006 · crm.dishnetuganda.com x2"
out="$(run --after)"; rc=$?
check "$rc" "0" "S2 exits 0"
has "$out" "ok    uCRM now tells plugins its address without :8443" "S2 sees the address change"
has "$out" "ok    the routers are still connected on :8443 (9 connection(s), floor 8)" "S2 the routers stayed (floor 80 % of 9 = 8)"
has "$out" "RESULT   C-2 is done" "S2 verdict: done"
hasnt "$out" "APPKEY-SECRET-123" "S2 never prints the app key"

echo "S3 --after: the routers dropped off :8443 — the rollback instruction"
routers off
out="$(run --after)"; rc=$?
check "$rc" "1" "S3 exits 1"
has "$out" "the routers did NOT come back on :8443" "S3 reports the routers gone"
has "$out" "PUT THE TWO VALUES BACK NOW" "S3 tells the operator to roll back"
has "$out" "RESULT   something the change must not break is broken" "S3 verdict: broken"
check "$(printf '%s\n' "$out" | grep -c '^  …  ')" "3" "S3 polled the configured number of times before judging"

echo "S4 --after: routers fine, uCRM has not rewritten its address yet — a note, never a failure"
routers on; setfake "https://crm.dishnetuganda.com:8443/crm/" "invoice 000005 · crm.dishnetuganda.com:8443 x2"
sed -i "s/^BEFORE_TS=.*/BEFORE_TS=$(date -u -d '-10 min' +%Y-%m-%dT%H:%M:%SZ)/" "$SB/state/state.env"
out="$(run --after)"; rc=$?
check "$rc" "0" "S4 exits 0"
has "$out" "note  uCRM still tells plugins an address with :8443" "S4 notes the address not yet rewritten"
has "$out" "RESULT   nothing broke, but uCRM has not taken the new address yet" "S4 verdict: wait and re-run"

echo "S5 --after with no before-state refuses"
rm -f "$SB/state/state.env"
out="$(run --after)"; rc=$?
check "$rc" "1" "S5 exits 1"
has "$out" "no before-state" "S5 says to run --before first"

echo "S6 --before with UISP already unhealthy: do not change the setting"
touch "$F/health_bad"; out="$(run --before)"; rc=$?; rm -f "$F/health_bad"
check "$rc" "1" "S6 exits 1"
has "$out" "Something is already wrong BEFORE the change. Do not change the setting" "S6 says not to proceed"

echo "S7 an argument that is not a mode is refused"
out="$(run --now)"; rc=$?
check "$rc" "64" "S7 exits 64 with the usage line"

echo "S8 the control: a copy that counts private peers too would read 14, not 9 — the assertion S1 makes discriminates"
cp "$SCRIPT" "$SB/broken.sh"
python3 - "$SB/broken.sh" <<'PY'
import sys; p=sys.argv[1]; t=open(p).read()
old="""    | grep -v -E '^$|^127\\.|^10\\.|^172\\.(1[6-9]|2[0-9]|3[01])\\.|^192\\.168\\.|^169\\.254\\.|^::1$|^f[cd][0-9a-f]*:|^fe80:' \\\n"""
assert t.count(old)==1, "the filter line is gone"
open(p,'w').write(t.replace(old, "    | grep -v -E '^$' \\\n"))
PY
out="$(PATH="$SB/bin:$PATH" FAKE="$F" STATE_DIR="$SB/state" HEALTH_SCRIPT="$SB/health.sh" POLL_SECONDS=0 POLLS=3 bash "$SB/broken.sh" --before 2>&1)"
hasnt "$out" "9 connection(s) from 8 address(es)" "S8 the broken copy does not produce the count S1 asserts"
has "$out" "14 connection(s)" "S8 the broken copy counts the private and loopback peers (14)"

echo "S9 no router connected, but the port is visible (only this server's own connections): a measured zero"
printf '0 0 172.18.0.5:8443 %s\n' 172.17.0.1:40000 127.0.0.1:40003 > "$F/ss_host"; : > "$F/ss_ns"
setfake "https://crm.dishnetuganda.com:8443/crm/" "invoice 000005 · crm.dishnetuganda.com:8443 x2"
out="$(run --before)"; rc=$?
check "$rc" "0" "S9 --before exits 0"
has "$out" "ok    no router is connected to UISP on :8443 right now — measured" "S9 --before calls the zero measured, because the count saw the port"
check "$(grep -c '^BEFORE_DEV_T=2$' "$SB/state/state.env")" "1" "S9 the state file records what was seen in all"
setfake "https://crm.dishnetuganda.com/crm/" "invoice 000006 · crm.dishnetuganda.com x2"
out="$(run --after)"; rc=$?
check "$rc" "0" "S9 --after exits 0"
has "$out" "ok    no router was connected on :8443 before the change either" "S9 --after: nothing to lose, said as a measurement"
has "$out" "RESULT   C-2 is done" "S9 verdict: done"

echo "S10 nothing at all visible on the port: the zero is INDETERMINATE, never 'no routers'"
: > "$F/ss_host"; : > "$F/ss_ns"
setfake "https://crm.dishnetuganda.com:8443/crm/" "invoice 000005 · crm.dishnetuganda.com:8443 x2"
out="$(run --before)"; rc=$?
check "$rc" "0" "S10 --before exits 0"
has "$out" "note  nothing at all was seen on :8443, not even this script's own test connection — the count is blind on this server" "S10 --before says the count is blind"
hasnt "$out" "no router is connected to UISP on :8443 right now" "S10 --before never claims a measured zero"
out="$(run --after)"; rc=$?
has "$out" "note  the router count was blind on this server before the change" "S10 --after sends the operator to UISP's device list"

echo "S11 --after run straight after --before, with nothing changed: the guard says the change is the operator's, by hand"
printf '0 0 172.18.0.5:8443 %s\n' 172.17.0.1:40000 > "$F/ss_host"; : > "$F/ss_ns"
setfake "https://crm.dishnetuganda.com:8443/crm/" "invoice 000005 · crm.dishnetuganda.com:8443 x2"
out="$(run --before)"; out2="$(run --after)"; rc=$?
has "$out" "THIS SCRIPT CHANGES NOTHING. Between --before and --after, YOU make the change" "S11 --before says the change is manual, in uCRM's web page"
check "$rc" "0" "S11 --after exits 0 (nothing is broken)"
has "$out2" "RESULT   nothing broke. uCRM's address is still exactly what --before recorded" "S11 --after recognises the address is unchanged, moments later"
has "$out2" "This script only CHECKS — it changes nothing." "S11 --after says it only checks"
has "$out2" "Not changed in uCRM yet? Make the change by hand" "S11 --after names the manual step"
has "$out2" "Already saved it there? Wait a few minutes" "S11 --after keeps the honest second case"
hasnt "$out2" "RESULT   nothing broke, but uCRM has not taken the new address yet" "S11 --after does not give the generic verdict"
hasnt "$out2" "uCRM rewrites that file itself" "S11 --after does not give the generic note"

echo; echo "REHEARSAL: $PASS ok, $FAILN failed"
[ "$FAILN" = "0" ]
