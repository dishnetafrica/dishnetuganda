#!/usr/bin/env bash
#
# dnb-crm-root-redirect.sh — change set C-1 (docs/38 §C.2): the bare /crm on the
# public address no longer leads to :8443.
#
# Today (measured, docs/37 §I.2 U4) https://crm.dishnetuganda.com/crm is answered
# by UISP with 301 → https://crm.dishnetuganda.com:8443/crm/, whose certificate is
# self-signed for "localhost". This places ONE file for Traefik's file provider
# that answers exactly that path with 302 → https://crm.dishnetuganda.com/crm/ on
# 443 (UISP then serves /crm/ as today). Nothing else changes: not main.yaml, not
# uisp.yaml, not UISP, not the plugin.
#
# READ FIRST, THEN ACT: it reads uisp.yaml for the certificate resolver and the
# host router's priority and refuses if uisp.yaml is not where docs/120 §1.3 saw
# it. It records what the bare /crm answers BEFORE, writes the file, waits for
# Traefik to take it (no restart), and verifies: /crm → 302 with the exact
# Location; /crm/ still answers as before and never names :8443; the portal
# sign-in still 200; uCRM's own login unchanged; Traefik not restarted.
#
# Run as root on the server and send back THE LOG FILE:
#
#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg \
#     && bash scripts/dnb-crm-root-redirect.sh 2>&1 | tee /root/dnb-crm-root-$(date -u +%Y%m%dT%H%M%SZ).log
#
# Rollback at any time (one command, no restart):
#   rm /etc/easypanel/traefik/config/dnb-crm-root.yml
#
# Re-running rewrites the same file. HOST/SCHEME/CONF_DIR are overridable for the
# rehearsal (scripts/harness/crm-root/) only.
set -uo pipefail
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEMPLATE="$REPO/scripts/traefik/dnb-crm-root.yml.template"
CONF_DIR="${CONF_DIR:-/etc/easypanel/traefik/config}"
TARGET="$CONF_DIR/dnb-crm-root.yml"
HOST="${HOST:-crm.dishnetuganda.com}"
SCHEME="${SCHEME:-https}"
PORTAL="$SCHEME://$HOST/crm/_plugins/dishnet-hybrid-sudan/public.php?page=customer_login"
PASS=0; FAIL=0; NOTE=0
ok()   { PASS=$((PASS+1)); printf '  ok    %s\n' "$*"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL  %s\n' "$*"; }
note() { NOTE=$((NOTE+1)); printf '  note  %s\n' "$*"; }
stop() { printf '\n  STOP: %s\n  Nothing further was done. Send the log file.\n' "$*"; exit 1; }
code() { curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "$1" 2>/dev/null || echo 000; }
loc()  { curl -sS -o /dev/null -D - --max-time 20 "$1" 2>/dev/null | awk 'tolower($1)=="location:"{print $2}' | tr -d '\r' | head -1; }

echo "== C-1: the bare /crm on the public address — $(date -u +%Y-%m-%dT%H:%M:%SZ) — $(hostname) =="
[ -f "$TEMPLATE" ] || stop "template missing: $TEMPLATE"
[ -d "$CONF_DIR" ] || stop "no Traefik file-provider directory at $CONF_DIR"
UISP="$CONF_DIR/uisp.yaml"; [ -f "$UISP" ] || stop "uisp.yaml not found at $UISP — the host router this must sit above is not where docs/120 §1.3 saw it"
TRAEFIK_ID="$(docker ps --filter 'name=traefik' --format '{{.ID}}' | head -1)"; [ -n "$TRAEFIK_ID" ] || stop "no running traefik container"
STARTED_BEFORE="$(docker inspect "$TRAEFIK_ID" --format '{{.State.StartedAt}}')"

echo "  uisp.yaml (read-only; the host router this file sits above):"
grep -nE 'rule:|entryPoints:|priority:|certResolver:|service:' "$UISP" | head -12 | sed 's/^/     /'
RESOLVER="$(grep -E '^\s*certResolver:' "$UISP" | head -1 | sed -E 's/.*certResolver:\s*//; s/["'"'"' ]//g')"
[ -n "$RESOLVER" ] || { RESOLVER="letsencrypt"; note "uisp.yaml names no certResolver; using letsencrypt (the resolver the other routes on this host use)"; }
UISP_PRIO="$(grep -E '^\s*priority:' "$UISP" | head -1 | sed -E 's/.*priority:\s*//; s/[^0-9]//g')"
if [ -n "$UISP_PRIO" ]; then PRIO_LINE="priority: $((UISP_PRIO + 10))"; echo "  uisp.yaml priority $UISP_PRIO → this router $((UISP_PRIO + 10))";
else PRIO_LINE=""; echo "  uisp.yaml sets no priority → Traefik ranks by rule length, and Host && Path outranks Host alone"; fi
echo "  certResolver    $RESOLVER"

B_CODE="$(code "$SCHEME://$HOST/crm")"; B_LOC="$(loc "$SCHEME://$HOST/crm")"
S_CODE="$(code "$SCHEME://$HOST/crm/")"; S_LOC="$(loc "$SCHEME://$HOST/crm/")"
echo "  before:  /crm → $B_CODE ${B_LOC:+→ $B_LOC}"
echo "           /crm/ → $S_CODE ${S_LOC:+→ $S_LOC}"
echo "           portal sign-in → $(code "$PORTAL")   uCRM login → $(code "$SCHEME://$HOST/crm/login")"
case "$B_LOC" in *:8443*) note "the bare /crm currently leads to :8443 — the door this file closes";; esac
[ -f "$TARGET" ] && note "$TARGET exists — it will be rewritten (a re-run)"
HOST_RE="$(printf '%s' "$HOST" | sed -E 's/[.]/\\\\./g')"
sed -e "s|__RESOLVER__|$RESOLVER|" -e "s|__PRIORITY_LINE__|$PRIO_LINE|" -e "s|__HOST_RE__|$HOST_RE|g" -e "s|__HOST__|$HOST|g" "$TEMPLATE" | sed '/^\s*$/d' > "$TARGET.tmp" && mv "$TARGET.tmp" "$TARGET" || stop "could not write $TARGET"
chmod 644 "$TARGET"; ok "wrote $TARGET"

WANT="https://$HOST/crm/"
for i in 1 2 3 4 5 6 7 8 9 10; do sleep 2; c="$(code "$SCHEME://$HOST/crm")"; L="$(loc "$SCHEME://$HOST/crm")"; [ "$c" = "302" ] && [ "$L" = "$WANT" ] && break; done
if [ "$c" = "302" ] && [ "$L" = "$WANT" ]; then ok "GET /crm → 302 → $WANT (after ${i}×2 s)"
else bad "GET /crm → $c ${L:+→ $L} — expected 302 → $WANT. Traefik did not take the route, or another router outranks it. Rollback: rm $TARGET"; fi
case "$L" in *:8443*) bad "the Location still carries :8443";; *) ok "the Location carries no :8443";; esac
c2="$(code "http://$HOST/crm")"; L2="$(loc "http://$HOST/crm")"
if [ "$c2" = "301" ] || [ "$c2" = "302" ]; then case "$L2" in https://$HOST/*) ok "the http:// form answers $c2 → $L2 (https, no :8443)";; *) note "the http:// form answers $c2 → ${L2:-<none>}";; esac
else note "the http:// form answers $c2"; fi
A_CODE="$(code "$SCHEME://$HOST/crm/")"; A_LOC="$(loc "$SCHEME://$HOST/crm/")"
[ "$A_CODE" = "$S_CODE" ] && [ "$A_LOC" = "$S_LOC" ] && ok "/crm/ answers exactly as before ($A_CODE ${A_LOC:+→ $A_LOC})" || note "/crm/ now answers $A_CODE ${A_LOC:+→ $A_LOC} (before: $S_CODE ${S_LOC:+→ $S_LOC})"
case "$A_LOC" in *:8443*) bad "/crm/ leads to :8443";; *) ok "/crm/ leads nowhere near :8443";; esac
[ "$(code "$PORTAL")" = "200" ] && ok "the portal sign-in page still answers 200" || bad "portal sign-in → $(code "$PORTAL")"
[ "$(code "$SCHEME://$HOST/crm/login")" = "200" ] && ok "uCRM's own login still answers 200 (unchanged)" || note "uCRM login → $(code "$SCHEME://$HOST/crm/login")"
[ "$(docker inspect "$TRAEFIK_ID" --format '{{.State.StartedAt}}')" = "$STARTED_BEFORE" ] && ok "Traefik was not restarted" || bad "Traefik restarted"
echo; echo "  checks  $PASS ok, $FAIL failed, $NOTE notes"
if [ "$FAIL" = "0" ]; then echo "  DONE — the bare /crm stays on 443. Rollback: rm $TARGET"; else echo "  $FAIL FAILED — send the log file; rollback: rm $TARGET"; fi
[ "$FAIL" = "0" ]
