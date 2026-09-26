#!/usr/bin/env bash
#
# customer-login-clean-url.sh — OPTIONAL: give the DishNet customer sign-in a short
# address, https://crm.dishnetuganda.com/customer-login, as one new Traefik file.
# The website does not need it (it links the canonical portal URL directly).
#
# Run as root on the server, then send back THE LOG FILE:
#
#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg \
#     && bash scripts/customer-login-clean-url.sh 2>&1 | tee /root/dnb-clean-url-$(date -u +%Y%m%dT%H%M%SZ).log
#
# It reads uisp.yaml for the certificate resolver and the host router's priority,
# writes /etc/easypanel/traefik/config/dnb-customer-login.yml from the template,
# and verifies: the route answers 302 with the exact Location, the old and the
# canonical URLs still answer as before, Traefik was not restarted.
# Rollback at any time:  rm /etc/easypanel/traefik/config/dnb-customer-login.yml
# It never edits main.yaml or uisp.yaml. Re-running rewrites the same file.
set -uo pipefail
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEMPLATE="$REPO/scripts/traefik/dnb-customer-login.yml.template"
CONF_DIR="/etc/easypanel/traefik/config"
TARGET="$CONF_DIR/dnb-customer-login.yml"
HOST="crm.dishnetuganda.com"
CANON="https://$HOST/crm/_plugins/dishnet-hybrid-sudan/public.php?page=customer_login"
PASS=0; FAIL=0; NOTE=0
ok()   { PASS=$((PASS+1)); printf '  ok    %s\n' "$*"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL  %s\n' "$*"; }
note() { NOTE=$((NOTE+1)); printf '  note  %s\n' "$*"; }
stop() { printf '\n  STOP: %s\n  Nothing further was done. Send the log file.\n' "$*"; exit 1; }
code() { curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "$1" 2>/dev/null || echo 000; }
loc()  { curl -sS -o /dev/null -D - --max-time 20 "$1" 2>/dev/null | awk 'tolower($1)=="location:"{print $2}' | tr -d '\r' | head -1; }

echo "== customer-login clean URL — $(date -u +%Y-%m-%dT%H:%M:%SZ) — $(hostname) =="
[ -f "$TEMPLATE" ] || stop "template missing: $TEMPLATE"
[ -d "$CONF_DIR" ] || stop "no Traefik file-provider directory at $CONF_DIR"
UISP="$CONF_DIR/uisp.yaml"; [ -f "$UISP" ] || stop "uisp.yaml not found at $UISP — the host router this must sit above is not where docs/120 §1.3 saw it"
TRAEFIK_ID="$(docker ps --filter 'name=traefik' --format '{{.ID}}' | head -1)"; [ -n "$TRAEFIK_ID" ] || stop "no running traefik container"
STARTED_BEFORE="$(docker inspect "$TRAEFIK_ID" --format '{{.State.StartedAt}}')"

RESOLVER="$(grep -E '^\s*certResolver:' "$UISP" | head -1 | sed -E 's/.*certResolver:\s*//; s/["'"'"' ]//g')"
[ -n "$RESOLVER" ] || { RESOLVER="letsencrypt"; note "uisp.yaml names no certResolver; using letsencrypt (the resolver the staging routes use)"; }
UISP_PRIO="$(grep -E '^\s*priority:' "$UISP" | head -1 | sed -E 's/.*priority:\s*//; s/[^0-9]//g')"
if [ -n "$UISP_PRIO" ]; then PRIO_LINE="priority: $((UISP_PRIO + 10))"; echo "  uisp.yaml priority $UISP_PRIO → this router $((UISP_PRIO + 10))";
else PRIO_LINE=""; echo "  uisp.yaml sets no priority → Traefik ranks by rule length, and Host && Path outranks Host alone"; fi
echo "  certResolver    $RESOLVER"

echo "  before:  /customer-login → $(code "https://$HOST/customer-login")   canonical → $(code "$CANON")   uCRM login → $(code "https://$HOST/crm/login")"
[ -f "$TARGET" ] && note "$TARGET exists — it will be rewritten (a re-run)"
sed -e "s|__RESOLVER__|$RESOLVER|" -e "s|__PRIORITY_LINE__|$PRIO_LINE|" "$TEMPLATE" | sed '/^\s*$/d' > "$TARGET.tmp" && mv "$TARGET.tmp" "$TARGET" || stop "could not write $TARGET"
chmod 644 "$TARGET"; ok "wrote $TARGET"

for i in 1 2 3 4 5 6 7 8 9 10; do sleep 2; c="$(code "https://$HOST/customer-login")"; [ "$c" = "302" ] && break; done
[ "$c" = "302" ] && ok "GET /customer-login → 302 (after ${i}×2 s)" || bad "GET /customer-login → $c, not 302 — Traefik did not take the route (file kept; rollback: rm $TARGET)"
L="$(loc "https://$HOST/customer-login")"; [ "$L" = "$CANON" ] && ok "Location is exactly the canonical portal sign-in" || bad "Location is '$L'"
[ "$(code "$CANON")" = "200" ] && ok "the canonical sign-in page still answers 200" || bad "canonical page → $(code "$CANON")"
[ "$(code "https://$HOST/crm/login")" = "200" ] && ok "uCRM's own login still answers 200 (unchanged)" || note "uCRM login → $(code "https://$HOST/crm/login")"
[ "$(docker inspect "$TRAEFIK_ID" --format '{{.State.StartedAt}}')" = "$STARTED_BEFORE" ] && ok "Traefik was not restarted" || bad "Traefik restarted"
echo; echo "  checks  $PASS ok, $FAIL failed, $NOTE notes"
[ "$FAIL" = "0" ] && echo "  DONE — the short address works. Rollback: rm $TARGET" || echo "  $FAIL FAILED — send the log file; rollback: rm $TARGET"
[ "$FAIL" = "0" ]
