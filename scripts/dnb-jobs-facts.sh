#!/usr/bin/env bash
#
# dnb-jobs-facts.sh — READ-ONLY facts for docs/43, the staff / job-assignment / WhatsApp audit.
#
# It answers, from the live install, the questions the repository cannot:
#   1. what is installed, and the plugin's timezone (a job's time in the technician's WhatsApp depends on it)
#   2. each staff account as S1…Sn: role, active, admin, the FORM of its phone (country code only), its uCRM user id,
#      whether a hard-coded South Sudan map forces that id, and whether that id is a real Uganda uCRM user with the
#      same e-mail and a phone on record (yes/no — never the e-mail or the number)
#   3. uCRM's own staff users: how many, which have a phone field, which match a staff row
#   4. uCRM scheduling jobs: counts by status, by assigned user and by kind of title (never a title or an address)
#   5. what was sent for jobs: the Message Log, the failure queue and the webhook log, as counts
#   6. the plugin's uCRM webhook endpoint: active, and whether job.add and job.edit reach it (no address printed)
#   7. the WhatsApp transport: which channels are configured and connected (no key, address or instance name)
#   8. the background jobs: whether the dispatch cron is scheduled, the daily summary's last run and its error
#   9. staff numbers that the WhatsApp inbox files as customer conversations, and AI replies queued for them
#  10. the plugin's local job records (completions, invoice queue), as counts
#
# What it never does: send a message, create or change a job, touch a staff account, write to the plugin's store or
# configuration, deploy, sync or restart anything. The store is read from a COPY made inside the container as its
# owner and removed afterwards; uCRM is asked with GET only, through the plugin's own client; Evolution is asked for
# its connection state (GET). Nothing it prints is a name, e-mail address, phone number, job title, token or key, and
# the output passes through a mask before it reaches the terminal.
#
# Run as root on the server, then send back the LOG FILE (never a copy of the terminal):
#
#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && mkdir -p /root/dnb-jobs \
#     && bash scripts/dnb-jobs-facts.sh 2>&1 | tee /root/dnb-jobs/facts-$(date -u +%Y%m%dT%H%M%SZ).log
set -uo pipefail
umask 077

PLUGIN="dishnet-hybrid-sudan"
CONTAINER="${UCRM_CONTAINER:-ucrm}"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPORT="$REPO/scripts/lib/jobs_facts.php"
IN_CONTAINER="/data/ucrm/data/plugins/$PLUGIN"
PLUGINS_IN="/data/ucrm/data/plugins"
RO="/tmp/dnj-ro-$$"

PASS=0; FAIL=0; NOTE=0
ok()   { PASS=$((PASS+1)); printf '  ok    %s\n' "$*"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL  %s\n' "$*"; }
note() { NOTE=$((NOTE+1)); printf '  note  %s\n' "$*"; }
hdr()  { printf '\n== %s ==\n' "$*"; }
stop() { printf '\n  STOP: %s\n  Nothing was changed. Send the log file.\n' "$*"; exit 1; }
# The backstop. The report prints no personal value; anything shaped like an e-mail address, a phone number or a
# token is masked anyway. Hyphens and underscores break the patterns on purpose: dates and event names survive.
mask() { sed -E 's/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/<email>/g; s/\+?[0-9][0-9 ()]{8,}[0-9]/<number>/g; s/[A-Za-z0-9+\/=-]{32,}/<redacted>/g'; }

hdr "Staff, jobs and WhatsApp — read-only facts — $(date -u +%Y-%m-%dT%H:%M:%SZ) — $(hostname)"
[ -f "$REPORT" ] || stop "the report file is missing from this checkout: $REPORT"
command -v docker >/dev/null 2>&1 || stop "docker is not available here"
docker exec "$CONTAINER" php -v >/dev/null 2>&1 || stop "no php inside the '$CONTAINER' container"
docker exec "$CONTAINER" test -f "$IN_CONTAINER/public.php" || stop "the plugin is not installed at $IN_CONTAINER"
LIVE="$(docker exec "$CONTAINER" cat "$IN_CONTAINER/.deployed-commit" 2>/dev/null | tail -n1 | tr -cd '0-9a-f')"
echo "  installed plugin commit ${LIVE:-unknown} · this checkout $(git -C "$REPO" rev-parse --short HEAD 2>/dev/null || echo ?)"

# The plugin's persistent data directory: what uCRM told the plugin, else the two places it has lived.
PDD="$(docker exec "$CONTAINER" php -r '$u=@json_decode((string)@file_get_contents($argv[1]),true); echo rtrim((string)($u["pluginDataDir"]??""),"/");' "$IN_CONTAINER/ucrm.json" 2>/dev/null || true)"
if [ -z "$PDD" ]; then
  if docker exec "$CONTAINER" test -f "$PLUGINS_IN/.$PLUGIN-data/plugin.sqlite3"; then PDD="$PLUGINS_IN/.$PLUGIN-data"; else PDD="$IN_CONTAINER/data"; fi
fi
DB="$PDD/plugin.sqlite3"
docker exec "$CONTAINER" test -f "$DB" || stop "no store at $DB"
OWNER="$(docker exec "$CONTAINER" stat -c '%u:%g' "$DB" 2>/dev/null)"
[ -n "$OWNER" ] || stop "cannot read the store's owner"
echo "  data directory $PDD (inside the container) · store owner $OWNER"

# A copy of the store, as its owner, inside the container. The live file is only ever read by cp.
cleanup() { docker exec -u "$OWNER" "$CONTAINER" rm -rf "$RO" >/dev/null 2>&1 || true; }
trap cleanup EXIT
docker exec -u "$OWNER" "$CONTAINER" sh -c "mkdir -p '$RO' && cp '$DB' '$RO/plugin.sqlite3' && ( [ -f '$DB-wal' ] && cp '$DB-wal' '$RO/plugin.sqlite3-wal' || true )" >/dev/null 2>&1 \
  || stop "could not copy the store"

OUT="$(docker exec -i -u "$OWNER" -w "$IN_CONTAINER" -e "RO_DIR=$RO" -e "PDD=$PDD" "$CONTAINER" php -d display_errors=0 < "$REPORT" 2>&1 | mask)"
printf '%s\n' "$OUT" | grep -v '^@@'

hdr "Summary"
if printf '%s\n' "$OUT" | grep -q '^@@DONE'; then ok "the report ran to its end"; else bad "the report stopped early — the lines above show where"; fi
TZL="$(printf '%s\n' "$OUT" | sed -n 's/^@@TZ //p' | head -1)"
case "$TZL" in
  Africa/Kampala*) ok "timezone Africa/Kampala: a job's day and hour in the technician's WhatsApp are Kampala's" ;;
  "") note "timezone not reported" ;;
  *) note "timezone ${TZL%% *} — the technician's job message prints the day and hour in this zone, not Kampala's" ;;
esac
PRL="$(printf '%s\n' "$OUT" | sed -n 's/^@@PROFILE //p' | head -1)"
case "$PRL" in
  uganda) ok "tenant profile uganda: the plugin runs on Uganda's rules" ;;
  "") note "tenant profile not reported" ;;
  *) note "tenant profile ${PRL}: the plugin runs on South Sudan's rules here" ;;
esac
read -r _ ST_TOTAL ST_FTTH ST_UCRM ST_ASSIGN <<<"$(printf '%s\n' "$OUT" | grep '^@@STAFF' | head -1)"
[ -n "${ST_TOTAL:-}" ] && note "staff rows ${ST_TOTAL}: ${ST_UCRM} carry a uCRM user id, ${ST_ASSIGN} can be picked in My Jobs → New Job, ${ST_FTTH} have the FTTH CRM link that the 'CRM LINKED' tile counts"
read -r _ J_TOTAL J_CLIENT <<<"$(printf '%s\n' "$OUT" | grep '^@@JOBS' | head -1)"
[ -n "${J_TOTAL:-}" ] && note "uCRM returned ${J_TOTAL} scheduling job(s), ${J_CLIENT} with a client"
printf '\n  %d ok, %d failed, %d notes. Nothing was sent and nothing was changed. Send the log file.\n' "$PASS" "$FAIL" "$NOTE"
[ "$FAIL" -eq 0 ]
