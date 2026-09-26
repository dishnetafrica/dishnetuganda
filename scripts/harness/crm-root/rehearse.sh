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
case "$1" in ps) echo abc123;; inspect) echo 2026-09-26T00:00:00Z;; *) exit 0;; esac
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

echo; echo "REHEARSAL: $OKS ok, $BADS failed"; [ "$BADS" = "0" ]
