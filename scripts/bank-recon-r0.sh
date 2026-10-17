#!/usr/bin/env bash
#
# bank-recon-r0.sh — R0 of docs/69 §2.3.8: a census, READ ONLY, of the Cashbook's voided entries — their sources, paths
# and months, their void time and evidence, FinAudit's delete records, twin candidates (never proof), the I1 families,
# the Receipt rows' collection methods, the reversals, the rows of 12 Sep, the candidate rows for the 12 Sep adjustment,
# the largest unassigned rows, the deliberate voids, the three 25 Sep cash-ins and the live kits with no Starlink account.
# Nothing is decided, built, posted, voided or adjusted by it.
#
# Run as root on the server, then send back THE LOG FILE (the command is in the hand-over, not repeated here).
#
# What it reads: the container's mount and the installed plugin's commit; ucrm.json (the data folder's name); the live
# plugin.sqlite3 — cb_ledger, cb_accounts, fin_audit, staff_ledger, equipment_assignments and the stores
# payment_collections, cash_ins, cash_expenses and crm_payment_retry (each one optional but cb_ledger). What it never
# opens: a database in <plugin>/data (the stray store's old place), any configuration file, the vault, uCRM, the network.
#
# Why it cannot write:
#   - scripts/bank-recon-r0.php is piped into the container's PHP on standard input: nothing is copied into the container,
#     and no plugin code is loaded (the Cashbook's own classes alter tables and refresh the configuration vault);
#   - SQLite opens the book immutable and read-only, with query_only=ON and temp_store=MEMORY: it takes no lock and makes
#     no file. It reads only while the plugin has the book closed (no -wal or -shm beside it), waiting up to two minutes for
#     that, and it reads the book twice: the counts count only if both readings agree, the file did not move and no side
#     file appeared; if the plugin rewrites the book mid-reading, R0 says "run again" (exit 3);
#   - PHP runs with log_errors=0 and opcache.enable_cli=0, so nothing is appended to a php.ini error log or a cache file;
#     every message goes to this log instead;
#   - every query must be a single SELECT, and each reading fails unless SQLite says its connection changed 0 rows;
#   - it runs as the database's owner (never root);
#   - this script refuses a PHP file whose sha256 is not the reviewed one.
#
# What it prints: counts, row ids, checked dates and months, account kinds, status codes and currency codes. No name (of
# an account or anyone), description, person, reference, customer, category text or amount: every printed value is a
# number, a checked date or a code from a fixed list. The 12 Sep adjustment's candidates are found by a hash of the figure
# docs/07 records, never by the figure.
#
# Options: none.

set -u
CONTAINER="${UCRM_CONTAINER:-ucrm}"
PLUGIN="dishnet-hybrid-sudan"
IN_CONTAINER="/data/ucrm/data/plugins/$PLUGIN"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TOOL="$REPO/scripts/bank-recon-r0.php"
TOOL_SHA="9cb7e4fecb592f1abcffd41c29ad23c2804d56b680616509ded6d19d14b085b4"
[ $# -eq 0 ] || { echo "unknown option: $1 (R0 takes none)"; exit 2; }
stop() { printf '\n  STOP: %s\n\n  R0: NOT RUN — nothing was read from the database and nothing was written. Send the log file.\n' "$*"; exit 1; }
dx()   { docker exec "$@" </dev/null; }

printf '\n== bank-recon R0 (read only) — %s ==\n\n' "$(date -u +%Y%m%dT%H%M%SZ)"
[ "$(id -u)" = "0" ] || stop "run this as root (it reads through docker)"
command -v docker >/dev/null 2>&1 || stop "no docker on this host"
[ -f "$TOOL" ] || stop "no $TOOL — git pull first"
[ "$(sha256sum "$TOOL" | cut -c1-64)" = "$TOOL_SHA" ] || stop "$TOOL is not the reviewed version (sha256 differs) — git pull, and do not edit it"
[ "$(docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null)" = "true" ] || stop "the $CONTAINER container is not running"
MOUNT="$(docker inspect "$CONTAINER" --format '{{range .Mounts}}{{if eq .Destination "/data"}}{{.Source}}{{end}}{{end}}' 2>/dev/null || true)"
[ -n "$MOUNT" ] || stop "the $CONTAINER container has no /data mount"
DEST="$MOUNT/ucrm/data/plugins/$PLUGIN"
[ -f "$DEST/manifest.json" ] || stop "no installed plugin at $DEST"
LIVE="$(dx "$CONTAINER" cat "$IN_CONTAINER/.deployed-commit" 2>/dev/null | tail -n1 | tr -cd '0-9a-f')"
PDD_IN="$(dx "$CONTAINER" php -r '$u=@json_decode((string)@file_get_contents($argv[1]),true); echo rtrim((string)($u["pluginDataDir"]??""),"/");' "$IN_CONTAINER/ucrm.json" 2>/dev/null || true)"
if [ -z "$PDD_IN" ]; then
  if [ -f "$MOUNT/ucrm/data/plugins/.$PLUGIN-data/plugin.sqlite3" ]; then PDD_IN="/data/ucrm/data/plugins/.$PLUGIN-data"
  else stop "ucrm.json names no data folder, and there is no .$PLUGIN-data/plugin.sqlite3 beside the plugin"; fi
fi
[ "${PDD_IN%/}" != "$IN_CONTAINER/data" ] || stop "the data folder would be $IN_CONTAINER/data — the stray store's old place, which is never opened"
dx "$CONTAINER" test -f "$PDD_IN/plugin.sqlite3" 2>/dev/null || stop "no plugin.sqlite3 in $PDD_IN (as the container sees it)"
U="$(dx "$CONTAINER" stat -c '%u:%g' "$PDD_IN/plugin.sqlite3" 2>/dev/null || true)"
case "$U" in ''|0:*|*:*:*) stop "the live database's owner is ${U:-unreadable} — expected a non-root owner (1000:1000 on 13 Oct)" ;; esac
# --no-optional-locks: a plain `git status` may refresh and rewrite the checkout's .git/index (scripts/bank-recon-p0-run.sh:75)
if HEAD="$(git --no-optional-locks -C "$REPO" rev-parse --short HEAD 2>/dev/null)" && EDITS="$(git --no-optional-locks -C "$REPO" status --porcelain --untracked-files=no -- scripts/bank-recon-r0.sh scripts/bank-recon-r0.php 2>/dev/null)"; then
  CK="$HEAD, $([ -z "$EDITS" ] && echo 'both R0 files as committed' || echo "$(printf '%s\n' "$EDITS" | wc -l) R0 file(s) EDITED")"
else CK="not readable as a git checkout"; fi
echo "  installed       ${LIVE:-unknown} in $CONTAINER; data $PDD_IN; database owner $U"
echo "  this checkout   $CK; wrapper sha256 $(sha256sum "${BASH_SOURCE[0]}" | cut -c1-16)…; tool sha256 ${TOOL_SHA:0:16}…"
echo

docker exec -i -u "$U" "$CONTAINER" php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 -- "$PDD_IN" "$IN_CONTAINER" < "$TOOL" 2>&1
rc=$?
echo
case "$rc" in
  0) echo "  R0 finished (exit 0). Send this LOG FILE back. It holds counts, ids, dates and codes — no name, description, person,"
     echo "  reference, customer or amount." ;;
  2) echo "  R0 refused (exit 2) — the reason is above. Nothing was written. Send the log file." ;;
  *) echo "  R0 did not finish (exit $rc) — the reason is above. Nothing was written. Send the log file." ;;
esac
exit "$rc"
