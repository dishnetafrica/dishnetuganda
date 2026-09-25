#!/usr/bin/env bash
# Dependency-free tests for the AI platform layer. No PHPUnit, matching the
# plugin's zero-dependency convention. Run from anywhere.
set -uo pipefail
cd "$(dirname "$0")"

# Give the whole run its own ConfigVault.
#
# The vault deliberately lives OUTSIDE the data directory so it survives a
# re-install, which means DN_DATA_DIR does not move it. So a test that runs a
# tool with a temporary data directory still wrote into the REAL vault — and
# because the vault gap-fills missing keys, that junk was then restored into
# later tests. Results depended on run order and on what a previous run had
# left behind. Fourteen tests had this exposure; fixing it here fixes all of
# them, and no test can reach the real vault at all.
#
# And one per TEST, not per run. A setting one test writes into its own
# config is copied into the vault by the next config load, and the vault then
# gap-fills it into every test after: crm_public_url did exactly that the day
# it became a vault key (5.18.34), and failed four later tests.
VAULTS="$(mktemp -d -t dn-test-vaults.XXXXXX)"
trap 'rm -rf "$VAULTS"' EXIT INT TERM

fail=0
for t in test_*.php; do
  printf '\n=== %s ===\n' "$t"
  export DN_VAULT_FILE="$VAULTS/${t%.php}.json"
  php "$t" || fail=1
done
exit "$fail"
