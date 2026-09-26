#!/usr/bin/env bash
# Rehearsal of scripts/dnb-crm-root-redirect.sh against a fake edge and a fake Traefik directory.
# docker is a stub; the "https" scheme is plain http to a php -S that flips its answer for /crm once
# the file exists — exactly the observable Traefik behaviour the script waits for.
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"; REPO="$(cd "$HERE/../../.." && pwd)"
SB="$(mktemp -d -t dn-crmroot.XXXXXX)"; trap 'rm -rf "$SB"; [ -n "${SRV:-}" ] && kill "$SRV" 2>/dev/null' EXIT
OKS=0; BADS=0; check() { if [ "$1" = "$2" ]; then OKS=$((OKS+1)); echo "  ok   $3"; else BADS=$((BADS+1)); echo "  FAIL $3 (got '$1', want '$2')"; fi; }
mkdir -p "$SB/conf" "$SB/bin"
cat > "$SB/conf/uisp.yaml" <<'Y'
http:
  routers:
    uisp:
      rule: "Host(`crm.dishnetuganda.com`)"
      entryPoints: ["https"]
      priority: 20
      service: uisp
      tls:
        certResolver: letsencrypt
Y
cat > "$SB/bin/docker" <<'D'
#!/usr/bin/env bash
case "$1" in ps) echo abc123;; inspect) echo 2026-09-26T00:00:00Z;; logs) cat "${FAKE_TRAEFIK_LOG:-/dev/null}" 2>/dev/null;; *) exit 0;; esac
D
chmod +x "$SB/bin/docker"
PORT=$((31000 + RANDOM % 2000))
FAKE_CONF_DIR="$SB/conf" php -S 127.0.0.1:$PORT "$HERE/fake_edge.php" >/dev/null 2>&1 & SRV=$!
for i in $(seq 1 50); do curl -s -o /dev/null "http://127.0.0.1:$PORT/crm/login" && break; sleep 0.1; done

echo "-- S1 the script places the file and verifies the new answer"
out="$(PATH="$SB/bin:$PATH" CONF_DIR="$SB/conf" HOST="127.0.0.1:$PORT" SCHEME=http bash "$REPO/scripts/dnb-crm-root-redirect.sh" 2>&1)"; rc=$?
printf '%s\n' "$out" | sed 's/^/     /' | cut -c1-150
check "$rc" "0" "S1 exit 0"
check "$([ -f "$SB/conf/dnb-crm-root.yml" ] && echo yes)" "yes" "S1 the file was written"
check "$(grep -c 'Host(`127.0.0.1:'"$PORT"'`) && Path(`/crm`)' "$SB/conf/dnb-crm-root.yml")" "2" "S1 two routers for the exact path on the host"
check "$(grep -c 'priority: 30' "$SB/conf/dnb-crm-root.yml")" "2" "S1 priority above uisp.yaml's (20 → 30)"
check "$(grep -c 'certResolver: letsencrypt' "$SB/conf/dnb-crm-root.yml")" "1" "S1 the resolver read from uisp.yaml"
check "$(grep -c '^ *permanent: false' "$SB/conf/dnb-crm-root.yml")" "1" "S1 a 302, never a cached 301"
check "$(grep -c '__' "$SB/conf/dnb-crm-root.yml")" "0" "S1 no placeholder left"
check "$(grep -cE '(^|[^\\])\\([^\\]|$)' "$SB/conf/dnb-crm-root.yml")" "0" "S1 no lone backslash anywhere in the file (a YAML double-quoted string would reject it)"
check "$(python3 -c 'import sys,yaml; d=yaml.safe_load(open(sys.argv[1])); print(sorted(d["http"]["routers"]), d["http"]["middlewares"]["dnb-crm-root-redirect"]["redirectRegex"]["regex"].count("[.]"))' "$SB/conf/dnb-crm-root.yml" 2>&1)" "['dnb-crm-root', 'dnb-crm-root-http'] 3" "S1 the file parses as YAML: two routers, and the host's three dots are [.] in the regex"
check "$(printf '%s\n' "$out" | grep -c 'parses as YAML with the two routers')" "1" "S1 the script checked the YAML itself before placing the file"
check "$(printf '%s\n' "$out" | grep -c "Traefik's log has no error or warning naming the file")" "1" "S1 Traefik's log was read and is clean"
check "$(printf '%s\n' "$out" | grep -c 'the bare /crm currently leads to :8443')" "1" "S1 the before-state names the :8443 door"
check "$(printf '%s\n' "$out" | grep -c 'GET /crm → 302 → https://127.0.0.1:'"$PORT"'/crm/')" "1" "S1 the new answer is verified with the exact Location"
check "$(printf '%s\n' "$out" | grep -c '/crm/ answers exactly as before')" "1" "S1 /crm/ unchanged"
check "$(printf '%s\n' "$out" | grep -c 'Traefik was not restarted')" "1" "S1 no restart"

echo "-- S2 without uisp.yaml the script refuses before writing anything"
rm -f "$SB/conf/dnb-crm-root.yml"; mv "$SB/conf/uisp.yaml" "$SB/conf/uisp.yaml.away"
out="$(PATH="$SB/bin:$PATH" CONF_DIR="$SB/conf" HOST="127.0.0.1:$PORT" SCHEME=http bash "$REPO/scripts/dnb-crm-root-redirect.sh" 2>&1)"; rc=$?
check "$rc" "1" "S2 exit 1"
check "$(printf '%s\n' "$out" | grep -c 'STOP: uisp.yaml not found')" "1" "S2 the refusal names uisp.yaml"
check "$([ -f "$SB/conf/dnb-crm-root.yml" ] && echo yes || echo no)" "no" "S2 nothing written"
mv "$SB/conf/uisp.yaml.away" "$SB/conf/uisp.yaml"

echo "-- S3 when the edge does not take the route, the script fails and says so (the file stays for inspection)"
FAKE_CONF_DIR="$SB/elsewhere" php -S 127.0.0.1:$((PORT+1)) "$HERE/fake_edge.php" >/dev/null 2>&1 & SRV2=$!
for i in $(seq 1 50); do curl -s -o /dev/null "http://127.0.0.1:$((PORT+1))/crm/login" && break; sleep 0.1; done
out="$(PATH="$SB/bin:$PATH" CONF_DIR="$SB/conf" HOST="127.0.0.1:$((PORT+1))" SCHEME=http bash "$REPO/scripts/dnb-crm-root-redirect.sh" 2>&1)"; rc=$?
kill $SRV2 2>/dev/null
check "$rc" "1" "S3 exit 1"
check "$(printf '%s\n' "$out" | grep -c 'Traefik did not take the route')" "1" "S3 the failure is named"
check "$(printf '%s\n' "$out" | grep -c 'the Location still carries :8443')" "1" "S3 the :8443 Location is flagged"

echo "-- S4 the control on the control: a copy that escapes the dots with a backslash (the 26 September defect) is refused before the file is placed"
rm -f "$SB/conf/dnb-crm-root.yml"
python3 - "$REPO/scripts/dnb-crm-root-redirect.sh" "$SB/broken.sh" <<'PY'
import sys
t = open(sys.argv[1]).read()
old = "HOST_RE=\"$(printf '%s' \"$HOST\" | sed -E 's/[.]/[.]/g')\""
assert t.count(old) == 1, "anchor"
open(sys.argv[2], 'w').write(t.replace(old, "HOST_RE=\"$(printf '%s' \"$HOST\" | sed -E 's/[.]/\\\\\\\\./g')\""))
PY
out="$(PATH="$SB/bin:$PATH" CONF_DIR="$SB/conf" HOST="127.0.0.1:$PORT" SCHEME=http TEMPLATE="$REPO/scripts/traefik/dnb-crm-root.yml.template" bash "$SB/broken.sh" 2>&1)"; rc=$?
check "$rc" "1" "S4 exit 1"
check "$(printf '%s\n' "$out" | grep -c 'template missing')" "0" "S4 the copy found the template (the refusal is the escape check, not a missing file)"
check "$(printf '%s\n' "$out" | grep -c 'lone backslash')" "1" "S4 the refusal names the lone backslash"
check "$([ -f "$SB/conf/dnb-crm-root.yml" ] && echo yes || echo no)" "no" "S4 nothing was placed"
check "$(printf '%s\n' "$out" | grep -c 'wrote ')" "0" "S4 the file was never written"

echo "-- S5 when Traefik rejects the file anyway, its log lines are shown"
FAKE_TRAEFIK_LOG="$SB/traefik.log"; export FAKE_TRAEFIK_LOG
printf '%s\n' 'time="2026-09-26T15:05:10Z" level=error msg="Error while parsing file" file=/etc/easypanel/traefik/config/dnb-crm-root.yml' > "$FAKE_TRAEFIK_LOG"
FAKE_CONF_DIR="$SB/elsewhere2" php -S 127.0.0.1:$((PORT+2)) "$HERE/fake_edge.php" >/dev/null 2>&1 & SRV3=$!
for i in $(seq 1 50); do curl -s -o /dev/null "http://127.0.0.1:$((PORT+2))/crm/login" && break; sleep 0.1; done
out="$(PATH="$SB/bin:$PATH" CONF_DIR="$SB/conf" HOST="127.0.0.1:$((PORT+2))" SCHEME=http bash "$REPO/scripts/dnb-crm-root-redirect.sh" 2>&1)"; rc=$?
kill $SRV3 2>/dev/null; unset FAKE_TRAEFIK_LOG
check "$rc" "1" "S5 exit 1"
check "$(printf '%s\n' "$out" | grep -c 'Error while parsing file')" "1" "S5 Traefik's own error line is shown"
check "$(printf '%s\n' "$out" | grep -c "error/warning line(s) naming the file")" "1" "S5 the error naming the file is counted as a failure"

echo; echo "REHEARSAL: $OKS ok, $BADS failed"; [ "$BADS" = "0" ]
