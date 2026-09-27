#!/usr/bin/env bash
#
# dnb-ucrm-users-facts.sh — READ-ONLY: Uganda's uCRM staff users, before J1–J8 are built (docs/44).
#
# It answers, from the live install:
#   1. the tenant profile and timezone the plugin runs on
#   2. every uCRM staff user: id, active, and whether its record — the list AND the detail — carries any phone-like
#      field, and whether that field holds a number (the country code only, never the number); plus what the job.add
#      handler's own lookup (users/{id}) answers for a real id
#   3. each staff account against those users: the e-mail match a verified picker would propose, whether the stored
#      uCRM id is a real user, and the two other settings that hold a uCRM user id
#   4. the offset uCRM writes into its own timestamps (which clock uCRM keeps)
#   5. whether the follow-up engine is on
#   6. which events each WhatsApp number's webhook subscribes to (delivery receipts)
#
# What it never does: create a user, change a mapping, create a job, send a message, write to the plugin's store or
# configuration, deploy, sync or restart anything. The store is read from a COPY made inside the container as its
# owner and removed afterwards; uCRM and Evolution are asked with GET only, through the plugin's own clients. Nothing
# it prints is a name, username, e-mail address, phone number, date, amount, address, URL, token or key, and the output
# passes through a mask before it reaches the terminal.
#
# Run as root on the server, then send back the LOG FILE (never a copy of the terminal):
#
#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && mkdir -p /root/dnb-jobs \
#     && bash scripts/dnb-ucrm-users-facts.sh 2>&1 | tee /root/dnb-jobs/ucrm-users-$(date -u +%Y%m%dT%H%M%SZ).log
set -uo pipefail
umask 077

PLUGIN="dishnet-hybrid-sudan"
CONTAINER="${UCRM_CONTAINER:-ucrm}"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPORT="$REPO/scripts/lib/ucrm_users_facts.php"
IN_CONTAINER="/data/ucrm/data/plugins/$PLUGIN"
PLUGINS_IN="/data/ucrm/data/plugins"
RO="/tmp/dnu-ro-$$"

PASS=0; FAIL=0; NOTE=0
ok()   { PASS=$((PASS+1)); printf '  ok    %s\n' "$*"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL  %s\n' "$*"; }
note() { NOTE=$((NOTE+1)); printf '  note  %s\n' "$*"; }
hdr()  { printf '\n== %s ==\n' "$*"; }
stop() { printf '\n  STOP: %s\n  Nothing was changed. Send the log file.\n' "$*"; exit 1; }
# The backstop. The report prints no personal value; anything shaped like an e-mail address, a phone number or a
# token is masked anyway. Hyphens and underscores break the patterns on purpose: event names survive.
mask() { sed -E 's/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/<email>/g; s/\+?[0-9][0-9 ()]{8,}[0-9]/<number>/g; s/[A-Za-z0-9+\/=-]{32,}/<redacted>/g; s#https?://[^ ]+#<url>#g'; }

hdr "uCRM staff users — read-only facts — $(date -u +%Y-%m-%dT%H:%M:%SZ) — $(hostname)"
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
PRL="$(printf '%s\n' "$OUT" | sed -n 's/^@@PROFILE //p' | head -1)"
case "$PRL" in
  uganda) ok "tenant profile uganda: the plugin runs on Uganda's rules" ;;
  "") note "tenant profile not reported" ;;
  *) note "tenant profile ${PRL}: the plugin runs on South Sudan's rules here" ;;
esac
read -r _ U_TOT U_ACT U_FIELD U_VALUE <<<"$(printf '%s\n' "$OUT" | grep '^@@USERS' | head -1)"
if [ -n "${U_TOT:-}" ]; then
  note "uCRM has ${U_TOT} staff user(s), ${U_ACT} active"
  if [ "${U_VALUE:-0}" = "0" ]; then
    note "no uCRM user record holds a phone number (${U_FIELD} carry a phone-like field): a job message's number must come from the staff account"
  else
    note "${U_VALUE} uCRM user record(s) hold a phone number — still not used by the specification; the staff account's number is the one that counts"
  fi
fi
read -r _ M_ROWS M_TOT <<<"$(printf '%s\n' "$OUT" | grep '^@@MATCH' | head -1)"
[ -n "${M_ROWS:-}" ] && note "${M_ROWS} of ${M_TOT} staff account(s) share an e-mail with a uCRM user — the others need a uCRM user created before they can be given a job"
read -r _ S_REAL S_TOT <<<"$(printf '%s\n' "$OUT" | grep '^@@STORED' | head -1)"
[ -n "${S_REAL:-}" ] && note "${S_REAL} of ${S_TOT} stored uCRM user id(s) belong to a real uCRM user"
OFS="$(printf '%s\n' "$OUT" | sed -n 's/^@@OFFSET //p' | head -1)"
case "$OFS" in
  +0300|+03:00) ok "uCRM writes its times in UTC+03:00, Kampala's offset" ;;
  -|"") note "uCRM's offset could not be read" ;;
  *) note "uCRM writes its times with offset(s) ${OFS} — not only Kampala's; see section 4" ;;
esac
read -r _ R_SUB R_TOT <<<"$(printf '%s\n' "$OUT" | grep '^@@RECEIPTS' | head -1)"
[ -n "${R_SUB:-}" ] && note "delivery receipts (MESSAGES_UPDATE) are subscribed on ${R_SUB} of ${R_TOT} WhatsApp number(s)"
printf '\n  %d ok, %d failed, %d notes. Nothing was created, sent or changed. Send the log file.\n' "$PASS" "$FAIL" "$NOTE"
[ "$FAIL" -eq 0 ]
