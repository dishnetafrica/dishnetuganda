#!/usr/bin/env bash
# run.sh PLUGIN_ROOT [block|allow] [SCRIPT] — test scripts/console/sales-number-on.console.js (or SCRIPT, a weakened copy)
# in headless Chromium against a sandbox of the plugin release checked out at PLUGIN_ROOT (…/dishnet-hybrid-sudan, 5.18.89+).
# "block" / "allow": without / with the plugin's own service worker. Needs php, node and Playwright's Chromium.
# Nothing leaves 127.0.0.1; every person, number and instance is fictitious. Exit 0 only when every check passed.
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"; REPO="$(cd "$HERE/../../.." && pwd)"
ROOT="${1:?the plugin release checkout (…/dishnet-hybrid-sudan)}"; SW="${2:-block}"; SCRIPT="${3:-$REPO/scripts/console/sales-number-on.console.js}"
[ -f "$ROOT/tests/fixtures/staff_jobs_sandbox.php" ] || { echo "run.sh: $ROOT is not a plugin checkout with its tests"; exit 2; }
for f in /data/ucrm /opt/dishnet /var/run/docker.sock; do [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server"; exit 2; }; done
CTL="$(mktemp -d)"
DN_VAULT_FILE="$CTL/v.json" php "$HERE/driver.php" "$ROOT" "$CTL" > "$CTL/driver.out" 2>&1 &
DPID=$!
( cd "$REPO" && SW="$SW" timeout 300 node "$HERE/test.js" "$CTL" "$SCRIPT" ); RC=$?
touch "$CTL/stop"; for _ in $(seq 1 100); do kill -0 "$DPID" 2>/dev/null || break; sleep 0.1; done; kill "$DPID" 2>/dev/null
[ "$RC" = 0 ] || { echo "run.sh: FAILED (exit $RC); the sandbox driver's output:"; tail -n 5 "$CTL/driver.out"; }
exit "$RC"
