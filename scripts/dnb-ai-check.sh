#!/usr/bin/env bash
#
# dnb-ai-check.sh — READ-ONLY. How the WhatsApp assistant answers two kinds of question (docs/40):
#   · a customer running a BUSINESS who needs UNLIMITED data — a Residential plan, not a Business plan with a GB block;
#   · covering ANOTHER AREA — the outdoor access point and the MikroTik priced in uCRM.
#
# Run as root on the server, and send back the LOG FILE (never a copy of the terminal):
#
#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && mkdir -p /root/dnb-ai \
#     && bash scripts/dnb-ai-check.sh --ask 2>&1 | tee /root/dnb-ai/check-$(date -u +%Y%m%dT%H%M%SZ).log
#
# Without --ask it spends nothing and shows: which assistant answers and with which switches; the price list exactly
# as the assistant sees it on each number; the approved-knowledge rows on these topics and what of each the assistant
# never sees; and recent real conversations on these topics (DAYS, default 60) — each customer message with the reply
# it got. Phone numbers, e-mail addresses and kit numbers are masked; staff are shown as "staff", never by name.
#
# --ask also puts nine questions, in seven short conversations, to the INSTALLED assistant on the sales number — the
# same path a customer's message takes (knowledge base, context contract, price check, Business-plan note) — and prints
# each reply as the customer would receive it. That is nine model calls on the configured provider, a few cents.
#
# Nothing is changed and nothing is sent. The plugin database is COPIED, as its owner, to a temporary folder inside the
# container and the copy is read (the journey audit's rule: even a read-only open of the live file can leave SQLite's
# -wal/-shm files owned by root); the folder is removed at the end. The settings are read without the vault refresh,
# the price list is fetched with its cache off, and no message, lead, event or log line is written. The PHP runs inside
# the uCRM container (scripts/lib/ai_check.php), as the database's owner, against the plugin installed there.
set -uo pipefail
umask 077

MODE=report
for a in "$@"; do
  case "$a" in
    --ask) MODE=ask ;;
    *) echo "usage: $0 [--ask]      (DAYS=<n> for a different window of past conversations)" >&2; exit 64 ;;
  esac
done
CONTAINER="${UCRM_CONTAINER:-ucrm}"
PLUGIN="dishnet-hybrid-sudan"
IN_CONTAINER="${IN_CONTAINER:-/data/ucrm/data/plugins/$PLUGIN}"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DAYS="${DAYS:-60}"
case "$DAYS" in ''|*[!0-9]*) echo "DAYS must be a number" >&2; exit 64 ;; esac

PLUGINS_IN="$(dirname "$IN_CONTAINER")"
RO="/tmp/dnb-ai-ro-$$"; DB_OWNER=""

stop() { printf '\n  STOP: %s\n  Nothing was changed and nothing was sent. Send the log file.\n' "$*"; exit 1; }
cleanup() { [ -n "$DB_OWNER" ] && docker exec -u "$DB_OWNER" "$CONTAINER" rm -rf "$RO" 2>/dev/null; true; }
trap cleanup EXIT

# The PHP report, piped into the container as the database's owner, with the plugin's directory as the working
# directory and the copy of the database named in AI_CHECK_DB. error_log() goes to stderr, which is discarded, so
# no PHP log file is written either. Its "@@" lines are for this script, never printed.
run() {
  docker exec -i -u "$DB_OWNER" -w "$IN_CONTAINER" -e "AI_CHECK_DB=$RO/plugin.sqlite3" "$CONTAINER" \
    php -d display_errors=0 -d log_errors=0 -d error_log= -- "$1" "$DAYS" < "$REPO/scripts/lib/ai_check.php" 2>/dev/null
}

printf '\n== DishNet AI check — %s — %s ==\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$(hostname)"
printf '  repository commit %s\n' "$(git -C "$REPO" rev-parse --short HEAD 2>/dev/null || echo unknown)"
[ -r "$REPO/scripts/lib/ai_check.php" ] || stop "scripts/lib/ai_check.php is missing from $REPO"
docker exec "$CONTAINER" true 2>/dev/null || stop "container '$CONTAINER' is not reachable with docker exec"

# The plugin's data directory and its database (the deploy scripts' rule), and a copy of the database made as its owner.
PDD_IN="$(docker exec "$CONTAINER" php -r '$u=@json_decode((string)@file_get_contents($argv[1]),true); echo rtrim((string)($u["pluginDataDir"]??""),"/");' "$IN_CONTAINER/ucrm.json" 2>/dev/null || true)"
if [ -z "$PDD_IN" ]; then
  if docker exec "$CONTAINER" test -f "$PLUGINS_IN/.$PLUGIN-data/plugin.sqlite3"; then PDD_IN="$PLUGINS_IN/.$PLUGIN-data"; else PDD_IN="$IN_CONTAINER/data"; fi
fi
DB_IN="$PDD_IN/plugin.sqlite3"
docker exec "$CONTAINER" test -f "$DB_IN" || stop "no plugin database at $DB_IN"
DB_OWNER="$(docker exec "$CONTAINER" stat -c '%u:%g' "$DB_IN" 2>/dev/null)"
[ -n "$DB_OWNER" ] || stop "could not read who owns $DB_IN"
docker exec -u "$DB_OWNER" "$CONTAINER" sh -c "mkdir -p '$RO' && cp '$DB_IN' '$RO/plugin.sqlite3' && ( [ -f '$DB_IN-wal' ] && cp '$DB_IN-wal' '$RO/plugin.sqlite3-wal' || true )" 2>/dev/null \
  || stop "could not copy the plugin database to $RO inside the container"

RC=0
for part in report $([ "$MODE" = ask ] && echo ask); do
  OUT="$(run "$part")"
  [ -n "$OUT" ] || stop "the $part part produced nothing (docker exec of scripts/lib/ai_check.php)"
  printf '%s\n' "$OUT" | grep -v '^@@'
  LAST="$(printf '%s\n' "$OUT" | grep '^@@ ' | tail -1)"
  read -r _ P STATE A B C <<<"$LAST"
  if [ "${STATE:-}" != "ok" ]; then
    printf '\n  The %s part stopped (see above).\n' "$part"; RC=1; continue
  fi
  if [ "$part" = report ]; then
    IFS=: read -r bn ba bi <<<"${A:-0:0:0}"; IFS=: read -r cn ca ci <<<"${B:-0:0:0}"
    printf '\n  Past conversations: business/unlimited %s shown (%s answered, %s replies by the AI) · covering another area %s shown (%s answered, %s by the AI)\n' \
      "$bn" "$ba" "$bi" "$cn" "$ca" "$ci"
  else
    printf '\n  Asked: %s model call(s); %s refused by the price check; %s with the Business-plan note added\n' "${A:-0}" "${B:-0}" "${C:-0}"
  fi
done
printf '\n  Nothing was changed and nothing was sent. Send the log file.\n'
exit "$RC"
