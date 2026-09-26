#!/usr/bin/env bash
#
# deploy-5.18.41.sh — deploy plugin 5.18.41: change set A1 of docs/38 (built on the operator's
# "go with your recommendation", 26 September 2026).
#   A1.1  the signed-in portal, the invoice screen and the legal pages read the TENANT profile
#         (Help/Support contacts, WhatsApp buttons, locality, the location default, the bank
#         block, the currency code) — South Sudan renders what it rendered; Uganda stops showing
#         Juba, +211, dishnetafrica.com, a South Sudan bank and " USD" after a shilling amount.
#   A1.2  consent given on one verified sign-in route (phone) counts on the other (e-mail).
#   A1.3  a customer page reached on the same host with an explicit other port (UISP's :8443,
#         self-signed certificate) is answered 302 to the public address, same path and query.
#         Never page=api, never a POST, never the native wrapper; loop-proof by construction.
#
# Same machinery as deploy-5.18.40.sh: before-evidence, a backup, the documented deploy
# (scripts/deploy-hybrid.sh), then stage V verifies over the PUBLIC address and over :8443,
# and ROLLS BACK BY ITSELF if the public address were ever redirected (a loop would lock
# customers out; the rule makes it impossible, and the script still checks).
#
# Run as root on the server, then send back THE LOG FILE (never a copy of the terminal):
#
#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg \
#     && mkdir -p /root/dnb-5.18.41 \
#     && bash scripts/deploy-5.18.41.sh 2>&1 | tee /root/dnb-5.18.41/deploy-$(date -u +%Y%m%dT%H%M%SZ).log
#
# Options
#   --after-only         the deploy already happened: only run the checks
#   --plugin-base <url>  the public URL of public.php, if the derived one is wrong
#
# What it never does: touch the data directory, a configuration value, a customer, the
# webhook key, Traefik, UISP or the website. The only writes are the documented deploy and a
# backup under /root/dnb-5.18.41/ — and, only if stage V finds the public address redirecting,
# the documented rollback.
#
# Stages:  A before-evidence + backup → GO/NO-GO   B the documented deploy
#          V the tenant on the public pages, the :8443 door, the API and the loop check   F summary
#
# Afterwards, the read-only audit proves the signed-in screens (they need your code):
#   bash scripts/journey-audit.sh --login-phone '<the customer phone, international form>'
# expected: L5 and L9 pass; L10 (the Terms wording) still fails until change set A2.
set -uo pipefail
umask 077

PLUGIN="dishnet-hybrid-sudan"
CONTAINER="${UCRM_CONTAINER:-ucrm}"
EXPECTED_PLUGIN_COMMIT="a2ea19f"   # 5.18.41 — the plugin-scoped commit deploy-hybrid.sh records
EXPECTED_VERSION="5.18.41"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$REPO/$PLUGIN"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="/root/dnb-5.18.41"; mkdir -p "$OUT"; chmod 700 "$OUT"
GUARD_SECONDS="${GUARD_SECONDS:-60}"

AFTER_ONLY=0; PLUGIN_BASE="${PLUGIN_BASE:-}"
while [ $# -gt 0 ]; do
  case "$1" in
    --after-only) AFTER_ONLY=1 ;;
    --plugin-base) PLUGIN_BASE="${2:-}"; shift ;;
    *) echo "unknown option: $1" >&2; exit 64 ;;
  esac; shift
done

PASS=0; FAIL=0; NOTE=0
ok()   { PASS=$((PASS+1)); printf '  ok    %s\n' "$*"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL  %s\n' "$*"; }
note() { NOTE=$((NOTE+1)); printf '  note  %s\n' "$*"; }
hdr()  { printf '\n== %s ==\n' "$*"; }
stop() { printf '\n  STOP: %s\n  Nothing further was done. Send the log file.\n' "$*"; exit 1; }
case "$EXPECTED_PLUGIN_COMMIT" in __*) stop "this copy of the script is not pinned to a reviewed commit — pull the branch again";; esac

# ── HTTP helper: status code, body, headers, the number of redirects followed ─
HTTP_CODE=""; HTTP_BODY=""; HTTP_HEADERS=""; HTTP_REDIRECTS=""; HTTP_LOCATION=""
http() {   # http METHOD URL [-k] [-L]
  local m="$1" u="$2"; shift 2
  local args=(-sS -o "/tmp/dnb_body.$$" -D "/tmp/dnb_hdr.$$" -w '%{http_code} %{num_redirects} %{redirect_url}' --max-time 30 -X "$m" --max-redirs 5)
  local a; for a in "$@"; do args+=("$a"); done
  local r; r="$(curl "${args[@]}" "$u" 2>/dev/null)" || r="000 0 "
  HTTP_CODE="${r%% *}"; r="${r#* }"; HTTP_REDIRECTS="${r%% *}"; HTTP_LOCATION="${r#* }"
  HTTP_BODY="$(head -c 400000 "/tmp/dnb_body.$$" 2>/dev/null | tr -d '\000' || true)"
  HTTP_HEADERS="$(head -c 8000 "/tmp/dnb_hdr.$$" 2>/dev/null || true)"
  rm -f "/tmp/dnb_body.$$" "/tmp/dnb_hdr.$$"
}
count() { printf '%s' "$HTTP_BODY" | grep -o -F -- "$1" | wc -l | tr -d ' '; }

hdr "5.18.41 — $TS — $(hostname)"

# ════════════════════════════════════════════════════════
hdr "A. Before-evidence (read-only) and backup"
# ════════════════════════════════════════════════════════
HEAD_REPO="$(git -C "$REPO" rev-parse --short HEAD 2>/dev/null || echo unknown)"
HEAD_PLUGIN="$(git -C "$REPO" log -1 --format=%h -- "$PLUGIN" 2>/dev/null || echo unknown)"
DIRTY="$(git -C "$REPO" status --porcelain --untracked-files=no 2>/dev/null | wc -l | tr -d ' ')"
VERSION_SRC="$(grep -o '"version": *"5[^"]*"' "$SRC/manifest.json" | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/')"
echo "  checkout        $REPO"
echo "  repo HEAD       $HEAD_REPO"
echo "  plugin commit   $HEAD_PLUGIN   (expected $EXPECTED_PLUGIN_COMMIT)"
echo "  plugin version  ${VERSION_SRC:-?}   (expected $EXPECTED_VERSION)"
echo "  tracked edits   $DIRTY"
[ "$HEAD_PLUGIN" = "$EXPECTED_PLUGIN_COMMIT" ] || stop "the checkout's plugin commit is $HEAD_PLUGIN, not $EXPECTED_PLUGIN_COMMIT — pull the branch, or this script is for another build"
[ "$VERSION_SRC" = "$EXPECTED_VERSION" ]        || stop "manifest.json says ${VERSION_SRC:-?}, expected $EXPECTED_VERSION"
[ "$DIRTY" = "0" ]                              || stop "the checkout has $DIRTY locally edited tracked files — deploying would ship edits nobody reviewed"

MOUNT="$(docker inspect "$CONTAINER" --format '{{range .Mounts}}{{if eq .Destination "/data"}}{{.Source}}{{end}}{{end}}' 2>/dev/null || true)"
[ -n "$MOUNT" ] || stop "container '$CONTAINER' has no /data mount"
DEST="$MOUNT/ucrm/data/plugins/$PLUGIN"
IN_CONTAINER="/data/ucrm/data/plugins/$PLUGIN"
[ -f "$DEST/manifest.json" ] || stop "no installed plugin at $DEST"
LIVE_BEFORE="$(docker exec "$CONTAINER" cat "$IN_CONTAINER/.deployed-commit" 2>/dev/null | tail -n1 | tr -cd '0-9a-f')"
LIVE_VERSION="$(grep -o '"version": *"5[^"]*"' "$DEST/manifest.json" | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/')"
echo "  serves          $DEST"
echo "  live commit     ${LIVE_BEFORE:-unknown}   (this is the rollback commit)"
echo "  live version    ${LIVE_VERSION:-?}"
echo "  --check says:"; bash "$REPO/scripts/deploy-hybrid.sh" --check 2>&1 | sed 's/^/     /'
docker exec "$CONTAINER" php -v >/dev/null 2>&1 || stop "no php inside the container — the data directory is resolved with it"

PDD_IN="$(docker exec "$CONTAINER" php -r '$u=@json_decode((string)@file_get_contents($argv[1]),true); echo rtrim((string)($u["pluginDataDir"]??""),"/");' "$IN_CONTAINER/ucrm.json" 2>/dev/null || true)"
if [ -z "$PDD_IN" ]; then
  if docker exec "$CONTAINER" test -f "/data/ucrm/data/plugins/.$PLUGIN-data/plugin.sqlite3"; then PDD_IN="/data/ucrm/data/plugins/.$PLUGIN-data";
  else PDD_IN="$IN_CONTAINER/data"; fi
fi
DATA_HOST="$MOUNT${PDD_IN#/data}"
echo "  data dir        $PDD_IN (container)  =  $DATA_HOST (host)"

# The public address, by the plugin's own rule: crm_public_url (config.json) → pluginPublicUrl → ucrmPublicUrl.
if [ -z "$PLUGIN_BASE" ]; then
  PLUGIN_BASE="$(docker exec "$CONTAINER" php -r '
    $r=$argv[1]; $pdd=$argv[2]; $u=@json_decode((string)@file_get_contents($r."/ucrm.json"),true)?:[];
    $over="";
    foreach ([$r."/data/config.json", $pdd."/config.json", $pdd."/kyc_config.json"] as $f) { $c=@json_decode((string)@file_get_contents($f),true)?:[]; $v=rtrim(trim((string)($c["crm_public_url"]??"")),"/"); if($v!==""){$over=$v; break;} }
    if($over!==""){ echo $over."/crm/_plugins/".basename($r)."/public.php"; exit; }
    if(!empty($u["pluginPublicUrl"])){ echo rtrim($u["pluginPublicUrl"],"/"); exit; }
    $b=rtrim((string)($u["ucrmPublicUrl"]??""),"/"); $b=preg_replace("#/crm$#","",$b); echo $b?$b."/crm/_plugins/".basename($r)."/public.php":"";' "$IN_CONTAINER" "$PDD_IN" 2>/dev/null || true)"
fi
[ -n "$PLUGIN_BASE" ] || stop "could not derive the plugin's public URL — re-run with --plugin-base https://<host>/crm/_plugins/$PLUGIN/public.php"
echo "  plugin URL      $PLUGIN_BASE"
case "$PLUGIN_BASE" in *:8443*) stop "the plugin's public URL carries :8443 — crm_public_url is not set to the public address; A1.3 must not be deployed against an override that names :8443";; esac
ORIGIN="$(printf '%s' "$PLUGIN_BASE" | sed -E 's#^(https?://[^/]+).*#\1#')"
HOSTNAME_ONLY="$(printf '%s' "$ORIGIN" | sed -E 's#^https?://##; s#:[0-9]+$##')"
ALT_BASE="https://$HOSTNAME_ONLY:8443${PLUGIN_BASE#"$ORIGIN"}"
echo "  the :8443 door  $ALT_BASE"

# The doors, before anything changes (read-only).
http GET "$PLUGIN_BASE?page=customer_login"
echo "  before: sign-in on the public address → $HTTP_CODE; '+211' ×$(count '+211') · dishnetafrica.com ×$(count 'dishnetafrica.com')"
http GET "$ALT_BASE?page=customer_login" -k
echo "  before: sign-in on :8443 → $HTTP_CODE (a 200 here is the door A1.3 closes)"

if [ "$AFTER_ONLY" = "0" ] && [ "$LIVE_BEFORE" = "$EXPECTED_PLUGIN_COMMIT" ]; then
  note "the container already serves $EXPECTED_PLUGIN_COMMIT — skipping the deploy, running the checks"
  AFTER_ONLY=1
fi

rollback() {   # the documented rollback, executed only when stage V finds the public address redirecting
  echo; echo "  ROLLING BACK to ${LIVE_BEFORE:-<unknown>} — $*"
  [ -n "$LIVE_BEFORE" ] || { echo "  no live commit recorded; roll back by hand: cd $REPO && git checkout <commit> && bash scripts/deploy-hybrid.sh"; return 1; }
  git -C "$REPO" checkout -q "$LIVE_BEFORE" && bash "$REPO/scripts/deploy-hybrid.sh" 2>&1 | sed 's/^/     /'
  local live; live="$(docker exec "$CONTAINER" cat "$IN_CONTAINER/.deployed-commit" 2>/dev/null | tail -n1 | tr -cd '0-9a-f')"
  echo "  the container now serves ${live:-?}; the checkout is at $LIVE_BEFORE (run 'git checkout claude/study-this-jhe2eg' before the next pull)"
}

BK=""
if [ "$AFTER_ONLY" = "0" ]; then
  BK="$OUT/backup-$TS"; mkdir -p "$BK"; chmod 700 "$BK"
  DONE_DIRS=""
  for d in "$DATA_HOST" "$DEST/data" "$MOUNT/ucrm/data/plugins/.$PLUGIN-data"; do
    [ -d "$d" ] || continue
    case " $DONE_DIRS " in *" $d "*) continue;; esac; DONE_DIRS="$DONE_DIRS $d"
    n="$(basename "$d" | tr -c 'A-Za-z0-9._\n-' '_')"; [ -n "$n" ] || n="data-$(date -u +%s)"
    [ -e "$BK/$n.tar.gz" ] && n="$n-$(date -u +%H%M%S)"
    if tar -C "$(dirname "$d")" -czf "$BK/$n.tar.gz" "$(basename "$d")" 2>/dev/null; then
      ok "backed up $d → $BK/$n.tar.gz ($(du -h "$BK/$n.tar.gz" | cut -f1))"
    else bad "backup of $d failed"; fi
  done
  cp "$DEST/.deployed-commit" "$BK/deployed-commit.before" 2>/dev/null || true
  chmod -R go-rwx "$BK"
  if [ -x "$REPO/scripts/verify-uisp-health.sh" ]; then
    if timeout 120 bash "$REPO/scripts/verify-uisp-health.sh" > "$BK/health-before.txt" 2>&1; then ok "UISP health recorded → $BK/health-before.txt"; else note "verify-uisp-health.sh did not pass — recorded in $BK/health-before.txt"; fi
  fi
  [ "$FAIL" = "0" ] || stop "NO-GO: the backup did not complete"
  echo; echo "  GO — evidence recorded, backup in $BK"
  echo "  Rollback at any time:  cd $REPO && git checkout ${LIVE_BEFORE:-<live commit>} && bash scripts/deploy-hybrid.sh"

  # ════════════════════════════════════════════════════════
  hdr "B. The documented deploy (scripts/deploy-hybrid.sh)"
  # ════════════════════════════════════════════════════════
  printf '  Type DEPLOY to deploy %s (%s) over live %s, anything else to stop: ' "$EXPECTED_VERSION" "$EXPECTED_PLUGIN_COMMIT" "${LIVE_BEFORE:-?}"
  read -r ANSWER </dev/tty || ANSWER=""
  [ "$ANSWER" = "DEPLOY" ] || stop "not confirmed"
  DEPLOY_STARTED="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  bash "$REPO/scripts/deploy-hybrid.sh" 2>&1 | sed 's/^/     /'; RC=${PIPESTATUS[0]}
  if [ "$RC" = "2" ]; then
    note "the container was still restarting; waiting for it"
    for i in 1 2 3 4 5 6 7 8 9 10 11 12; do
      sleep 10
      if bash "$REPO/scripts/deploy-hybrid.sh" --check 2>&1 | grep -q 'Up to date'; then RC=0; break; fi
    done
  fi
  LIVE_AFTER="$(docker exec "$CONTAINER" cat "$IN_CONTAINER/.deployed-commit" 2>/dev/null | tail -n1 | tr -cd '0-9a-f')"
  if [ "$RC" = "0" ] && [ "$LIVE_AFTER" = "$EXPECTED_PLUGIN_COMMIT" ]; then ok "container serves $LIVE_AFTER"
  else stop "deploy did not verify (rc=$RC, live=${LIVE_AFTER:-?}). Roll back: cd $REPO && git checkout ${LIVE_BEFORE:-<commit>} && bash scripts/deploy-hybrid.sh"; fi
else
  DEPLOY_STARTED="$(date -u -d '-10 minutes' +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || date -u +%Y-%m-%dT%H:%M:%SZ)"
  LIVE_AFTER="$LIVE_BEFORE"
  [ "$LIVE_AFTER" = "$EXPECTED_PLUGIN_COMMIT" ] && ok "live commit is $LIVE_AFTER" || stop "the container serves ${LIVE_AFTER:-?}, not $EXPECTED_PLUGIN_COMMIT"
fi

# ════════════════════════════════════════════════════════
hdr "V. Verification over the public address and the :8443 door (nothing signed in; no customer touched)"
# ════════════════════════════════════════════════════════
# V1 — the public address is never redirected: the loop check comes FIRST, and a redirect here rolls back.
http GET "$PLUGIN_BASE?page=customer_login" -L
if [ "$HTTP_CODE" = "200" ] && [ "$HTTP_REDIRECTS" = "0" ]; then ok "V1 the sign-in page on the public address answers 200 with zero redirects (no loop)"
else
  bad "V1 the sign-in page on the public address → $HTTP_CODE after $HTTP_REDIRECTS redirect(s) ${HTTP_LOCATION:+(last Location $HTTP_LOCATION)}"
  if [ "$AFTER_ONLY" = "0" ] && [ "${HTTP_REDIRECTS:-0}" != "0" ]; then rollback "the public address must never redirect"; stop "rolled back; send the log file"; fi
fi
http GET "$PLUGIN_BASE?page=customer_portal&view=home"
case "$HTTP_CODE" in 302|401) ok "V1 the portal without a session still refuses ($HTTP_CODE)";; *) bad "V1 the portal without a session → $HTTP_CODE";; esac
LOC="$(printf '%s' "$HTTP_HEADERS" | tr -d '\r' | grep -i '^location:' | head -1 | sed -E 's/^[^:]*: *//')"
case "$LOC" in *:8443*) bad "V1 its Location carries :8443 ($LOC)";; *) ok "V1 its Location carries no :8443";; esac

# V2 — the tenant on the public pages (the signed-in screens need the operator's code: journey-audit.sh).
http GET "$PLUGIN_BASE?page=customer_login"
[ "$HTTP_CODE" = "200" ] && ok "V2 the sign-in page answers 200" || bad "V2 sign-in page → $HTTP_CODE"
[ "$(count '+211')" = "0" ] && [ "$(count 'dishnetafrica.com')" = "0" ] && ok "V2 the sign-in page carries no South Sudan contact" || bad "V2 the sign-in page: '+211' ×$(count '+211') · dishnetafrica.com ×$(count 'dishnetafrica.com')"
http GET "$PLUGIN_BASE?page=terms"
[ "$HTTP_CODE" = "200" ] && ok "V2 the Terms page answers 200" || bad "V2 Terms page → $HTTP_CODE"
if [ "$(count '+211')" = "0" ] && [ "$(count 'dishnetafrica.com')" = "0" ] && [ "$(count 'wa.me/211')" = "0" ]; then ok "V2 the Terms page's contacts and footer carry no South Sudan literal (A1.1)"; else bad "V2 the Terms page: '+211' ×$(count '+211') · dishnetafrica.com ×$(count 'dishnetafrica.com') · wa.me/211 ×$(count 'wa.me/211')"; fi
[ "$(count 'wa.me/256705993348')" != "0" ] && [ "$(count 'Kampala, Uganda')" != "0" ] && ok "V2 the Terms page carries the Uganda WhatsApp link and locality" || bad "V2 the Terms page: wa.me/256705993348 ×$(count 'wa.me/256705993348') · 'Kampala, Uganda' ×$(count 'Kampala, Uganda')"
note "V2 the Terms page still names South Sudan ×$(count 'South Sudan') and Juba ×$(count 'Juba') in its wording — change set A2, gated on approved text (expected until then)"
http GET "$PLUGIN_BASE?page=privacy"
[ "$HTTP_CODE" = "200" ] && [ "$(count '+211')" = "0" ] && [ "$(count 'dishnetafrica.com')" = "0" ] && ok "V2 the Privacy page answers 200 with no South Sudan contact" || bad "V2 Privacy page → $HTTP_CODE; '+211' ×$(count '+211') · dishnetafrica.com ×$(count 'dishnetafrica.com')"

# V3 — the :8443 door (self-signed certificate: -k is for THIS check only; nothing else on this host uses -k).
http GET "$ALT_BASE?page=customer_login" -k
if [ "$HTTP_CODE" = "302" ] && [ "$HTTP_LOCATION" = "$PLUGIN_BASE?page=customer_login" ]; then ok "V3 the sign-in page on :8443 → 302 → the public address, same path and query (A1.3)"
else bad "V3 the sign-in page on :8443 → $HTTP_CODE ${HTTP_LOCATION:+→ $HTTP_LOCATION} (expected 302 → $PLUGIN_BASE?page=customer_login)"; fi
http GET "$ALT_BASE?page=customer_manifest" -k
[ "$HTTP_CODE" = "302" ] && ok "V3 the customer manifest on :8443 → 302" || bad "V3 customer_manifest on :8443 → $HTTP_CODE"
http GET "$ALT_BASE?page=api&action=app_legal_version" -k
[ "$HTTP_CODE" = "200" ] && printf '%s' "$HTTP_BODY" | grep -q 'tos_version' && ok "V3 page=api on :8443 is answered (200), never redirected — the wrapper's calls keep working" || bad "V3 page=api on :8443 → $HTTP_CODE"
http GET "$ALT_BASE?page=customer_login" -k -H 'X-DishNet-Client: android'
[ "$HTTP_CODE" = "200" ] && ok "V3 the native wrapper's request on :8443 is served (200), not redirected" || bad "V3 the wrapper on :8443 → $HTTP_CODE"
http POST "$ALT_BASE?page=api&action=app_legal_version" -k
[ "$HTTP_CODE" != "302" ] && ok "V3 a POST on :8443 is never redirected ($HTTP_CODE)" || bad "V3 a POST on :8443 was redirected"

# V4 — no fatal in the container log since the deploy (a short guard window).
if [ "$AFTER_ONLY" = "0" ]; then
  printf '  …     waiting %ss for the first requests on the new code\n' "$GUARD_SECONDS"; sleep "$GUARD_SECONDS"
fi
N_FATAL="$(docker logs "$CONTAINER" --since "$DEPLOY_STARTED" 2>&1 | grep -E 'UNCAUGHT|FATAL|PHP Fatal|PHP Parse error' | grep -c "$PLUGIN" || true)"
if [ "${N_FATAL:-0}" = "0" ]; then ok "V4 no fatal or parse error of $PLUGIN in the container log since $DEPLOY_STARTED"
else bad "V4 ${N_FATAL} fatal line(s) of $PLUGIN since $DEPLOY_STARTED:"; docker logs "$CONTAINER" --timestamps --since "$DEPLOY_STARTED" 2>&1 | grep -E 'UNCAUGHT|FATAL|PHP Fatal|PHP Parse error' | grep "$PLUGIN" | cut -c1-200 | head -5 | sed 's/^/     /'; fi

# ════════════════════════════════════════════════════════
hdr "F. Summary"
# ════════════════════════════════════════════════════════
echo "  deployed commit   $LIVE_AFTER  (plugin $EXPECTED_VERSION)"
if [ "$AFTER_ONLY" = "0" ]; then echo "  rollback commit   ${LIVE_BEFORE:-unknown}   →  cd $REPO && git checkout ${LIVE_BEFORE:-<commit>} && bash scripts/deploy-hybrid.sh"
else echo "  rollback commit   (this run deployed nothing — see the deployment run's log)"; fi
[ -n "$BK" ] && echo "  backup            $BK"
echo "  next (your code)  bash scripts/journey-audit.sh --login-phone '<the customer phone, international form>'   → L5 and L9 pass; L10 fails until A2"
echo "  checks            $PASS ok, $FAIL failed, $NOTE notes"
if [ "$FAIL" = "0" ]; then echo; echo "  5.18.41: PASSED. Send this LOG FILE back (not a copy of the terminal)."
else echo; echo "  5.18.41: $FAIL FAILED — send the log file; do not roll back on your own unless customers are affected."; fi
[ "$FAIL" = "0" ]
