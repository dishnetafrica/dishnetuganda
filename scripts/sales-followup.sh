#!/usr/bin/env bash
#
# sales-followup.sh — the operator's one way to run 5.18.104's switch tool, tools/sales_followup.php, on the server
# (Uganda; root docs/73 §H.2.3, §H.2.12.1, §H.2.16). Run as root on the host; it runs the INSTALLED tool inside the
# plugin's container as the user that owns the book (docker exec -u <owner>), never as root, so the book and
# kyc_config.json never become root's — the tool refuses any other uid anyway. Every word after the script's name is
# passed to the tool unchanged:
#
#   bash scripts/sales-followup.sh show
#   bash scripts/sales-followup.sh --check [<step>]
#   bash scripts/sales-followup.sh doctor
#   bash scripts/sales-followup.sh on <step> [--opt value | --opt=value] [--accept-not-yet "<reason>"]
#   bash scripts/sales-followup.sh skip <step> --reason "<text>"
#   bash scripts/sales-followup.sh off <step>|sending|all [--rollback]
#   bash scripts/sales-followup.sh set <key|alias> <value> [<value>]
#   bash scripts/sales-followup.sh erase                    (the tool reads ONE number from the terminal, echo off)
#
# The steps, in their one order (C6): report (1), agent-cc (1b, optional), ack (2), tracking (3), list (4),
# voice-measure (5), chase (6), voice-dry-run (7), voice-staff (8), voice-reply (9), cold (10, optional), offers-test (11),
# offers-live (12). Each `on` refuses, saving nothing, unless the steps before it are on (or skipped, or optional) and
# their checks allow it; `off all --rollback` is the one rollback sequence (the deploy's --rollback runs exactly that,
# QF-RB1, before it puts 5.18.103 back).
#
# The terminal: `erase` reads its number from the terminal with echo off — never from this command line, a file or the
# environment — so it is run with a terminal (docker exec -it); so are `on chase`, which asks you to type yes, and
# `on cold`, which asks for one line about support's assistant (no number). Every other command runs without one.
#
# The log: everything the tool prints is also written, under umask 077, to /root/dnb-sales/followup-<UTC>.log (a folder
# of mode 700; DNB_SALES_LOG_DIR moves it, for a rehearsal). The tool prints counts, fixed codes, dates and key names —
# never a phone, a name, a staff id or a key's secret value; the OpenAI key is "set" or "not set". As a backstop any run
# of 9 or more digits in its output is masked before it reaches the terminal or the log. Send back the LOG FILE, never a
# copy of the terminal.
#
# Exit: the tool's — 0 done, 2 refused (nothing saved), 3 a check, a hook or an assertion failed; 1 when this wrapper
# itself refuses (no container, no installed tool, a root-owned book), having changed nothing.
set -uo pipefail
umask 077

PLUGIN="dishnet-hybrid-sudan"
CONTAINER="${UCRM_CONTAINER:-ucrm}"
IN_CONTAINER="${IN_CONTAINER:-/data/ucrm/data/plugins/$PLUGIN}"
PLUGINS_IN="$(dirname "$IN_CONTAINER")"
TOOL="tools/sales_followup.php"
LOG_DIR="${DNB_SALES_LOG_DIR:-/root/dnb-sales}"

refuse() { printf 'sales-followup: REFUSED — %s. Nothing was changed.\n' "$*" >&2; exit 1; }
mask() { sed -u -E 's/[0-9]{9,}/<digits>/g' 2>/dev/null || sed -E 's/[0-9]{9,}/<digits>/g'; }

[ "$#" -ge 1 ] || refuse "give the tool's command, e.g.: bash scripts/sales-followup.sh show"
command -v docker >/dev/null 2>&1 || refuse "docker is not available here"
docker exec "$CONTAINER" test -f "$IN_CONTAINER/$TOOL" </dev/null 2>/dev/null \
  || refuse "the installed plugin has no $TOOL — 5.18.104 is not installed in the '$CONTAINER' container"

# The plugin's persistent data directory, as the deploy scripts find it: what uCRM told the plugin (ucrm.json), else
# the folder beside the plugin. Never <plugin>/data — the stray store's old place, where no tool may open a store.
PDD="$(docker exec "$CONTAINER" php -r '$u=@json_decode((string)@file_get_contents($argv[1]),true); echo rtrim((string)($u["pluginDataDir"]??""),"/");' "$IN_CONTAINER/ucrm.json" 2>/dev/null </dev/null || true)"
if [ -z "$PDD" ]; then
  if docker exec "$CONTAINER" test -f "$PLUGINS_IN/.$PLUGIN-data/plugin.sqlite3" </dev/null 2>/dev/null; then PDD="$PLUGINS_IN/.$PLUGIN-data"; else PDD=""; fi
fi
[ -n "$PDD" ] && [ "${PDD%/}" != "$IN_CONTAINER/data" ] || refuse "the plugin's data directory could not be found beside the plugin (ucrm.json names none, and $PLUGINS_IN/.$PLUGIN-data holds no book)"
docker exec "$CONTAINER" test -f "$PDD/plugin.sqlite3" </dev/null 2>/dev/null || refuse "no book at the data directory"
OWNER="$(docker exec "$CONTAINER" stat -c '%u:%g' "$PDD/plugin.sqlite3" 2>/dev/null </dev/null || true)"
case "$OWNER" in ''|*[!0-9:]*) refuse "the book's owner could not be read" ;; 0:*) refuse "the book is root's — the plugin's own user must own it; this wrapper never runs the tool as root" ;; esac

# A terminal only where the tool reads one: erase (echo off), on chase (yes), on cold (one line).
TTY=""
case "$1 ${2:-}" in "erase "*|"on chase"|"on cold") TTY=1 ;; esac
if [ -n "$TTY" ]; then
  [ -t 0 ] || refuse "'$1${2:+ $2}' reads from the terminal: run it in a terminal, never with its input piped"
  EXEC=(docker exec -it -u "$OWNER" -e "DN_DATA_DIR=$PDD" "$CONTAINER")
else
  EXEC=(docker exec -u "$OWNER" -e "DN_DATA_DIR=$PDD" "$CONTAINER")
fi

mkdir -p "$LOG_DIR" && chmod 700 "$LOG_DIR" || refuse "the log folder $LOG_DIR could not be made"
LOG="$LOG_DIR/followup-$(date -u +%Y%m%dT%H%M%SZ).log"
( umask 077; : > "$LOG" ) && chmod 600 "$LOG" || refuse "the log file $LOG could not be made"
{
  printf '== sales-followup — %s — %s — the tool: %s %s ==\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$(hostname)" "$TOOL" "$(printf '%s ' "$@" | mask)"
  printf '  installed commit %s · the book owned by %s\n' "$(docker exec "$CONTAINER" cat "$IN_CONTAINER/.deployed-commit" 2>/dev/null </dev/null | tail -n1 | tr -cd '0-9a-f')" "$OWNER"
} | tee -a "$LOG"
if [ -n "$TTY" ]; then
  "${EXEC[@]}" php -d log_errors=0 "$IN_CONTAINER/$TOOL" "$@" 2>&1 | mask | tee -a "$LOG"
else
  "${EXEC[@]}" php -d log_errors=0 "$IN_CONTAINER/$TOOL" "$@" 2>&1 </dev/null | mask | tee -a "$LOG"
fi
RC=${PIPESTATUS[0]}
printf '  exit %s · this log: %s (mode 0600) — send back this LOG FILE, never a copy of the terminal\n' "$RC" "$LOG" | tee -a "$LOG"
exit "$RC"
