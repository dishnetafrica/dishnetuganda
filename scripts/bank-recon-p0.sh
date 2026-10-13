#!/usr/bin/env bash
#
# bank-recon-p0.sh — P0 of docs/68: measure, READ ONLY, what the Cashbook's "cash in hand" would read under each proposed
# treatment of bank accounts. Nothing is decided, built, posted, voided or adjusted by it.
#
# Run as root on the server, then send back THE LOG FILE (the command is in the hand-over, not repeated here).
#
# What it reads: the container's mount and the installed plugin's commit; ucrm.json (the data folder's name); the live
# plugin.sqlite3 — cb_ledger, cb_accounts and the settings row (kyc_config) only; the three configuration files
# (<plugin>/data/config.json among them, as the plugin's own loader reads it) and the vault, for the book's two currency
# settings. What it never opens: a database in <plugin>/data (the stray store's old place), uCRM, the network.
#
# Why it cannot write:
#   - scripts/bank-recon-p0.php is piped into the container's PHP on standard input: nothing is copied into the container,
#     and no plugin code is loaded (the Cashbook's own classes alter tables and refresh the configuration vault);
#   - SQLite opens the book immutable and read-only, with query_only=ON and temp_store=MEMORY: it takes no lock and makes
#     no file. It reads only while the plugin has the book closed (no -wal or -shm beside it), waiting up to two minutes for
#     that, and it reads the book twice: the figures count only if both readings agree, the file did not move and no side
#     file appeared; if the plugin rewrites the book mid-reading, P0 says "run again" (exit 3);
#   - PHP runs with log_errors=0 and opcache.enable_cli=0, so nothing is appended to a php.ini error log or a cache file;
#     every message goes to this log instead;
#   - every query must be a single SELECT, and each reading fails unless SQLite says its connection changed 0 rows;
#   - it runs as the database's owner (never root);
#   - this script refuses a PHP file whose sha256 is not the reviewed one.
#
# What it prints: counts and net totals per currency and per kind of account; cash in hand today and under four candidate
# definitions; account ids with their kind and currency; the 12 Sep adjustment row's id, date, direction, status and
# whether its category is a capital one. No name (of an account or anyone), description, person, reference, customer or
# category: every other printed value is a number, a checked date or a code from a fixed list.
#
# Options: --adjustment-id=N names the 12 Sep adjustment row when it is not found on its own.

set -u
CONTAINER="${UCRM_CONTAINER:-ucrm}"
PLUGIN="dishnet-hybrid-sudan"
IN_CONTAINER="/data/ucrm/data/plugins/$PLUGIN"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TOOL="$REPO/scripts/bank-recon-p0.php"
TOOL_SHA="0143dae93cf34ba414a27f3c15af4946c7b224d24ffffd250eb6e8e27f08b422"
ADJ=""
for a in "$@"; do
  case "$a" in
    --adjustment-id=[0-9]*) ADJ="${a#--adjustment-id=}"; case "$ADJ" in *[!0-9]*) echo "bad --adjustment-id"; exit 2 ;; esac
                            [ "${#ADJ}" -le 10 ] || { echo "bad --adjustment-id (at most 10 digits)"; exit 2; } ;;
    *) echo "unknown option: $a (only --adjustment-id=N)"; exit 2 ;;
  esac
done
stop() { printf '\n  STOP: %s\n\n  P0: NOT RUN — nothing was read from the database and nothing was written. Send the log file.\n' "$*"; exit 1; }
dx()   { docker exec "$@" </dev/null; }

printf '\n== bank-recon P0 (read only) — %s ==\n\n' "$(date -u +%Y%m%dT%H%M%SZ)"
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
if HEAD="$(git -C "$REPO" rev-parse --short HEAD 2>/dev/null)" && EDITS="$(git -C "$REPO" status --porcelain --untracked-files=no -- scripts/bank-recon-p0.sh scripts/bank-recon-p0.php 2>/dev/null)"; then
  CK="$HEAD, $([ -z "$EDITS" ] && echo 'both P0 files as committed' || echo "$(printf '%s\n' "$EDITS" | wc -l) P0 file(s) EDITED")"
else CK="not readable as a git checkout"; fi
echo "  installed       ${LIVE:-unknown} in $CONTAINER; data $PDD_IN; database owner $U"
echo "  this checkout   $CK; wrapper sha256 $(sha256sum "${BASH_SOURCE[0]}" | cut -c1-16)…; tool sha256 ${TOOL_SHA:0:16}…"
echo

docker exec -i -u "$U" "$CONTAINER" php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 -- "$PDD_IN" "$IN_CONTAINER" ${ADJ:+"--adjustment-id=$ADJ"} < "$TOOL" 2>&1
rc=$?
echo
case "$rc" in
  0) echo "  P0 finished (exit 0). Send this LOG FILE back. It holds totals and counts, account ids with kind and currency, and"
     echo "  the 12 Sep adjustment row's id, date and status — no name, description, person, reference or customer." ;;
  2) echo "  P0 refused (exit 2) — the reason is above. Nothing was written. Send the log file." ;;
  *) echo "  P0 did not finish (exit $rc) — the reason is above. Nothing was written. Send the log file." ;;
esac
exit "$rc"
