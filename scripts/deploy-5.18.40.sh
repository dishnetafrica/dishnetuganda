#!/usr/bin/env bash
#
# deploy-5.18.40.sh — deploy plugin 5.18.40: the customer portal can be installed on a
# phone (its own web-app manifest, an Install row, no service worker by decision), and
# 5.18.39's fix rides along (the five-minute tick no longer dies on its own log line).
# Same machinery as phase1/phase2-deploy.sh: before-evidence, a backup, the documented
# deploy, the manifest checked over the public address, then it WAITS for the next tick
# and proves the tick ran to its last line.
#
# The website change (Customer Login → the DishNet portal) is a separate act: redeploy
# the site in EasyPanel (project web, app web-uganda). Stage W only reports whether the
# live site already links the portal.
#
# Run as root on the server, in one sitting (it waits up to ~13 minutes for the tick),
# and send back THE LOG FILE (never a copy of the terminal):
#
#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg \
#     && mkdir -p /root/dnb-5.18.40 \
#     && bash scripts/deploy-5.18.40.sh 2>&1 | tee /root/dnb-5.18.40/deploy-$(date -u +%Y%m%dT%H%M%SZ).log
#
# Options
#   --after-only         the deploy already happened: only run the checks
#   --plugin-base <url>  the public URL of public.php, if the derived one is wrong
#
# What it never does: touch the data directory, a configuration value, a customer, the
# webhook key, or the website. The only writes are the documented deploy
# (scripts/deploy-hybrid.sh) and a backup under /root/dnb-5.18.40/.
#
# Stages:  A before-evidence + backup → GO/NO-GO   B the documented deploy
#          M the customer manifest over the public address   T the tick after the deploy
#          W the website (report only)   F summary
set -uo pipefail
umask 077

PLUGIN="dishnet-hybrid-sudan"
CONTAINER="${UCRM_CONTAINER:-ucrm}"
EXPECTED_PLUGIN_COMMIT="4a2f41c"   # 5.18.40 — the plugin-scoped commit deploy-hybrid.sh records
EXPECTED_VERSION="5.18.40"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$REPO/$PLUGIN"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="/root/dnb-5.18.40"; mkdir -p "$OUT"; chmod 700 "$OUT"
WEBSITE="${WEBSITE:-https://dishnetuganda.com/}"
# Rehearsal knobs — never needed on the server: how often to look, how long to wait, the grace window
TICK_POLL_SECONDS="${TICK_POLL_SECONDS:-15}"
TICK_MAX_SECONDS="${TICK_MAX_SECONDS:-780}"
TICK_GUARD_SECONDS="${TICK_GUARD_SECONDS:-90}"
TICK_PERIOD_SECONDS="${TICK_PERIOD_SECONDS:-330}"

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

# ── HTTP helper: status code, body and headers; the request's headers are never echoed ─
HTTP_CODE=""; HTTP_BODY=""; HTTP_HEADERS=""
http() {
  local m="$1" u="$2" b="$3"; shift 3
  local hdrs=(); local h; for h in "$@"; do hdrs+=(-H "$h"); done
  local args=(-sS -o "/tmp/dnb_body.$$" -D "/tmp/dnb_hdr.$$" -w '%{http_code}' --max-time 30 -X "$m")
  [ -n "$b" ] && args+=(-H 'Content-Type: application/json' --data "$b")
  local r
  r="$(curl "${args[@]}" ${hdrs[@]+"${hdrs[@]}"} "$u" 2>/dev/null)" || r="000"
  HTTP_CODE="$r"; HTTP_BODY="$(head -c 200000 "/tmp/dnb_body.$$" 2>/dev/null | tr -d '\000' || true)"   # binary bodies (icons) carry NULs bash cannot hold
  HTTP_HEADERS="$(head -c 8000 "/tmp/dnb_hdr.$$" 2>/dev/null || true)"
  rm -f "/tmp/dnb_body.$$" "/tmp/dnb_hdr.$$"
}

hdr "5.18.40 — $TS — $(hostname)"

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

# The data directory, resolved the way the plugin resolves it (ucrm.json first).
PDD_IN="$(docker exec "$CONTAINER" php -r '$u=@json_decode((string)@file_get_contents($argv[1]),true); echo rtrim((string)($u["pluginDataDir"]??""),"/");' "$IN_CONTAINER/ucrm.json" 2>/dev/null || true)"
if [ -z "$PDD_IN" ]; then
  if docker exec "$CONTAINER" test -f "/data/ucrm/data/plugins/.$PLUGIN-data/plugin.sqlite3"; then PDD_IN="/data/ucrm/data/plugins/.$PLUGIN-data";
  else PDD_IN="$IN_CONTAINER/data"; fi
fi
DATA_HOST="$MOUNT${PDD_IN#/data}"
HB_IN="$PDD_IN/heartbeat.log"
docker exec "$CONTAINER" test -f "$HB_IN" || stop "no heartbeat log at $HB_IN (container path) — the data directory was not resolved"
echo "  data dir        $PDD_IN (container)  =  $DATA_HOST (host)"

# The public address, by the plugin's own rule: crm_public_url (config.json) → pluginPublicUrl → ucrmPublicUrl.
if [ -z "$PLUGIN_BASE" ]; then
  PLUGIN_BASE="$(docker exec "$CONTAINER" php -r '
    $r=$argv[1]; $pdd=$argv[2]; $u=@json_decode((string)@file_get_contents($r."/ucrm.json"),true)?:[];
    $over="";
    foreach ([$r."/data/config.json", $pdd."/config.json", $pdd."/kyc_config.json"] as $f) { $c=@json_decode((string)@file_get_contents($f),true)?:[]; $v=rtrim(trim((string)($c["crm_public_url"]??"")),"/"); if($v!==""){ $over=preg_replace("#/crm$#","",$v); break; } }
    if($over!==""){ echo $over."/crm/_plugins/".basename($r)."/public.php"; exit; }
    if(!empty($u["pluginPublicUrl"])){ echo rtrim($u["pluginPublicUrl"],"/"); exit; }
    $b=rtrim((string)($u["ucrmPublicUrl"]??""),"/"); $b=preg_replace("#/crm$#","",$b); echo $b?$b."/crm/_plugins/".basename($r)."/public.php":"";' "$IN_CONTAINER" "$PDD_IN" 2>/dev/null || true)"
fi
[ -n "$PLUGIN_BASE" ] || stop "could not derive the plugin's public URL — re-run with --plugin-base https://<host>/crm/_plugins/$PLUGIN/public.php"
echo "  plugin URL      $PLUGIN_BASE"

# The defect 5.18.39 fixes, measured before anything changes.
N456_1H="$(docker logs "$CONTAINER" --since 1h 2>&1 | grep -c "$PLUGIN/main.php:456" || true)"
N456_24H="$(docker logs "$CONTAINER" --since 24h 2>&1 | grep -c "$PLUGIN/main.php:456" || true)"
echo "  main.php:456    ${N456_1H:-0} crash lines in the last hour, ${N456_24H:-0} in 24 h — the tick defect this deploy carries the fix for"
HB_TAIL_BEFORE="$(docker exec "$CONTAINER" tail -n 200 "$HB_IN" 2>/dev/null || true)"
HB_LAST_BEFORE="$(printf '%s\n' "$HB_TAIL_BEFORE" | tail -n 1 | cut -c2-20)"
# The heartbeat's clock is NOT one clock: a master-cron job sets the default timezone mid-tick, so
# the early lines are stamped UTC and the closing ones Kampala time (+03:00). Stage T therefore
# never compares timestamps; it looks for closing lines that were not in this snapshot.
echo "  heartbeat.log   last line at ${HB_LAST_BEFORE:-?} (the tick's own clock, which changes mid-tick); its last three lines:"
docker exec "$CONTAINER" tail -n 3 "$HB_IN" 2>/dev/null | cut -c1-140 | sed 's/^/     /'
N_DONE_TAIL="$(docker exec "$CONTAINER" tail -n 200 "$HB_IN" 2>/dev/null | grep -c 'main.php total execution' || true)"
echo "  completed ticks ${N_DONE_TAIL:-0} 'total execution' lines among the last 200 heartbeat lines (0 is the defect)"
http GET "$PLUGIN_BASE?page=customer_manifest" ''
echo "  manifest before $HTTP_CODE for ?page=customer_manifest (the answer before this deploy, whatever it is)"

if [ "$AFTER_ONLY" = "0" ] && [ "$LIVE_BEFORE" = "$EXPECTED_PLUGIN_COMMIT" ]; then
  note "the container already serves $EXPECTED_PLUGIN_COMMIT — skipping the deploy, running the checks"
  AFTER_ONLY=1
fi

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
hdr "M. The customer web-app manifest over the public address (what a phone sees)"
# ════════════════════════════════════════════════════════
http GET "$PLUGIN_BASE?page=customer_manifest" ''
[ "$HTTP_CODE" = "200" ] && ok "M1 ?page=customer_manifest answers 200 without a login" || bad "M1 customer_manifest → $HTTP_CODE"
printf '%s' "$HTTP_HEADERS" | grep -qi '^content-type: application/manifest+json' && ok "M1 …as application/manifest+json" || bad "M1 content type: $(printf '%s' "$HTTP_HEADERS" | grep -i '^content-type' | tr -d '\r')"
MJ="$(printf '%s' "$HTTP_BODY" | php -r '$m=json_decode(stream_get_contents(STDIN),true); if(!is_array($m)){echo "INVALID"; exit;} $ic=array_map(fn($i)=>$i["sizes"]??"", $m["icons"]??[]); echo ($m["display"]??"?"),"|",($m["start_url"]??"?"),"|",($m["scope"]??"?"),"|",implode(",",$ic);' 2>/dev/null || echo INVALID)"
case "$MJ" in
  INVALID) bad "M2 the manifest body is not JSON" ;;
  *) IFS='|' read -r M_DISPLAY M_START M_SCOPE M_ICONS <<<"$MJ"
     [ "$M_DISPLAY" = "standalone" ] && ok "M2 display standalone" || bad "M2 display '$M_DISPLAY'"
     case "$M_START" in */public.php?page=customer_login) ok "M2 start_url is the customer sign-in page: $M_START";; *) bad "M2 start_url '$M_START'";; esac
     case "$M_START" in "$M_SCOPE"*) ok "M2 start_url lies inside scope $M_SCOPE";; *) bad "M2 start_url outside scope '$M_SCOPE'";; esac
     case "$M_ICONS" in *192x192*512x512*|*512x512*192x192*) ok "M2 icons 192 and 512 declared ($M_ICONS)";; *) bad "M2 icons: '$M_ICONS'";; esac ;;
esac
for sz in 192 512; do
  http GET "$PLUGIN_BASE?page=app_icon&size=$sz" ''
  if [ "$HTTP_CODE" = "200" ] && printf '%s' "$HTTP_HEADERS" | grep -qi '^content-type: image/png'; then ok "M3 the $sz px icon answers 200 image/png"; else bad "M3 icon $sz → $HTTP_CODE $(printf '%s' "$HTTP_HEADERS" | grep -i '^content-type' | tr -d '\r')"; fi
done
http GET "$PLUGIN_BASE?page=customer_login" ''
[ "$HTTP_CODE" = "200" ] && printf '%s' "$HTTP_BODY" | grep -q '<link rel="manifest" href="?page=customer_manifest">' && ok "M4 the sign-in page links the customer manifest" || bad "M4 sign-in page → $HTTP_CODE, manifest link $(printf '%s' "$HTTP_BODY" | grep -c 'customer_manifest')"
printf '%s' "$HTTP_BODY" | grep -q 'serviceWorker' && bad "M4 the sign-in page registers a service worker — not by decision" || ok "M4 no service worker on the customer pages (by decision: nothing signed-in is cached on the phone)"
http GET "$PLUGIN_BASE?page=customer_portal&view=home" ''
case "$HTTP_CODE" in 302|401) ok "M5 the portal without a session still refuses ($HTTP_CODE)";; *) bad "M5 the portal without a session → $HTTP_CODE";; esac

# --- T BEGIN ---
# ════════════════════════════════════════════════════════
hdr "T. The tick after the deploy (uCRM runs main.php about every five minutes — this waits for it)"
# ════════════════════════════════════════════════════════
# Heartbeat lines matching $1 that were NOT in the pre-deploy snapshot. Never by timestamp: the
# tick's clock changes mid-run (UTC first, Kampala time after a master-cron job sets the zone), so a
# closing line from BEFORE the deploy reads as 'later' than the opening line written after it.
hb_new() { docker exec "$CONTAINER" tail -n 200 "$HB_IN" 2>/dev/null | grep -F "$1" | grep -vxF -f <(printf '%s\n' "$HB_TAIL_BEFORE" | grep -F "$1" || true) || true; }
T0="$(date -u +%s)"; FIRST_DONE=""; SCHED_LINE=""
while :; do
  FIRST_DONE="$(hb_new 'main.php total execution' | head -n 1)"
  if [ -n "$FIRST_DONE" ]; then SCHED_LINE="$(hb_new 'UCRM auto-pull' | head -n 1)"; break; fi
  ELAPSED=$(( $(date -u +%s) - T0 ))
  [ "$ELAPSED" -lt "$TICK_MAX_SECONDS" ] || break
  printf '  …     %ss — no completed tick yet; last heartbeat line: %s\n' "$ELAPSED" "$(docker exec "$CONTAINER" tail -n 1 "$HB_IN" 2>/dev/null | cut -c1-90)"
  sleep "$TICK_POLL_SECONDS"
done
if [ -n "$FIRST_DONE" ]; then
  ok "T1 a tick completed after the deploy: $(printf '%s' "$FIRST_DONE" | cut -c1-120)"
  if printf '%s' "$SCHED_LINE" | grep -qE 'auto-pull: scheduled for [0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:00\.'; then
    ok "T2 the line that used to kill the tick is written, with a two-digit hour: ${SCHED_LINE#*] }"
  elif [ -n "$SCHED_LINE" ]; then
    note "T2 this tick's auto-pull line: ${SCHED_LINE#*] } — the pull hour itself or auto-pull disabled; the padded branch was not the one taken this tick"
  else bad "T2 the completed tick wrote no 'UCRM auto-pull' line"; fi
else
  bad "T1 no tick completed within ${TICK_MAX_SECONDS}s — the heartbeat's last lines:"
  docker exec "$CONTAINER" tail -n 8 "$HB_IN" 2>/dev/null | cut -c1-140 | sed 's/^/     /'
fi
# Crashes are counted from a little after the deploy: a tick already running the old code
# when the deploy landed may still die in the first seconds, and that is not this build's.
DEPLOY_EPOCH="$(date -u -d "$DEPLOY_STARTED" +%s)"
GUARD_FROM="$(date -u -d "@$(( DEPLOY_EPOCH + TICK_GUARD_SECONDS ))" +%Y-%m-%dT%H:%M:%SZ)"
WAIT_UNTIL=$(( DEPLOY_EPOCH + TICK_GUARD_SECONDS + TICK_PERIOD_SECONDS ))
if [ "$(date -u +%s)" -lt "$WAIT_UNTIL" ]; then
  printf '  …     waiting %ss more, so that a whole tick period falls inside the counting window\n' "$(( WAIT_UNTIL - $(date -u +%s) ))"
  sleep "$(( WAIT_UNTIL - $(date -u +%s) ))"
fi
N_AFTER="$(docker logs "$CONTAINER" --since "$GUARD_FROM" 2>&1 | grep -E 'UNCAUGHT|FATAL|Fatal' | grep -c "$PLUGIN/main.php" || true)"
if [ "${N_AFTER:-0}" = "0" ]; then ok "T3 no crash of $PLUGIN/main.php in the container log since $GUARD_FROM (deploy + ${TICK_GUARD_SECONDS}s)"
else
  bad "T3 ${N_AFTER} crash line(s) of $PLUGIN/main.php since $GUARD_FROM:"
  docker logs "$CONTAINER" --timestamps --since "$GUARD_FROM" 2>&1 | grep -E 'UNCAUGHT|FATAL|Fatal' | grep "$PLUGIN/main.php" | cut -c1-200 | head -5 | sed 's/^/     /'
fi
N_IN_GUARD="$(docker logs "$CONTAINER" --since "$DEPLOY_STARTED" --until "$GUARD_FROM" 2>&1 | grep -E 'UNCAUGHT|FATAL|Fatal' | grep -c "$PLUGIN/main.php" || true)"
[ "${N_IN_GUARD:-0}" = "0" ] || note "T3 ${N_IN_GUARD} crash line(s) in the first ${TICK_GUARD_SECONDS}s after the deploy started — a tick that was already running the old code"
N_OTHERS="$(docker logs "$CONTAINER" --since "$DEPLOY_STARTED" 2>&1 | grep -E 'UNCAUGHT|FATAL|Fatal|PHP Fatal|PHP Parse error' | grep -vc "$PLUGIN/main.php" || true)"
if [ "${N_OTHERS:-0}" = "0" ]; then ok "T4 no other PHP fatal/uncaught line since the deploy"
else
  note "T4 ${N_OTHERS} fatal/uncaught line(s) since the deploy that are not the tick's — other plugins, recorded, not this build's:"
  docker logs "$CONTAINER" --timestamps --since "$DEPLOY_STARTED" 2>&1 | grep -E 'UNCAUGHT|FATAL|Fatal|PHP Fatal|PHP Parse error' | grep -v "$PLUGIN/main.php" | cut -c1-160 | head -3 | sed 's/^/     /'
fi
# --- T END ---

# ════════════════════════════════════════════════════════
hdr "W. The website (report only — the site is redeployed in EasyPanel, not here)"
# ════════════════════════════════════════════════════════
http GET "$WEBSITE" ''
N_PORTAL="$(printf '%s' "$HTTP_BODY" | grep -o 'href="https://crm.dishnetuganda.com/crm/_plugins/dishnet-hybrid-sudan/public.php?page=customer_login"' | wc -l | tr -d ' ')"
N_UCRM="$(printf '%s' "$HTTP_BODY" | grep -o 'href="https://crm.dishnetuganda.com/crm/login"' | wc -l | tr -d ' ')"
if [ "$HTTP_CODE" != "200" ]; then note "W the website answered $HTTP_CODE — nothing to read"
elif [ "${N_PORTAL:-0}" != "0" ]; then ok "W the live home page links the DishNet portal sign-in ($N_PORTAL Customer Login links); uCRM login links: ${N_UCRM:-0}"
elif [ "${N_UCRM:-0}" != "0" ]; then note "W the live home page still links uCRM's login ($N_UCRM links) — the website has not been redeployed in EasyPanel yet"
else note "W the live home page carries neither login link — read it yourself"; fi

# ════════════════════════════════════════════════════════
hdr "F. Summary"
# ════════════════════════════════════════════════════════
echo "  deployed commit   $LIVE_AFTER  (plugin $EXPECTED_VERSION)"
if [ "$AFTER_ONLY" = "0" ]; then echo "  rollback commit   ${LIVE_BEFORE:-unknown}   →  cd $REPO && git checkout ${LIVE_BEFORE:-<commit>} && bash scripts/deploy-hybrid.sh"
else echo "  rollback commit   (this run deployed nothing — see the deployment run's log)"; fi
[ -n "$BK" ] && echo "  backup            $BK"
echo "  main.php:456      before: ${N456_1H:-0} crash lines in the hour before the run   after: ${N_AFTER:-?} since $GUARD_FROM"
echo "  install address   $PLUGIN_BASE?page=customer_login  (open it on a phone: the browser offers Install / Add to Home Screen)"
echo "  checks            $PASS ok, $FAIL failed, $NOTE notes"
if [ "$FAIL" = "0" ]; then echo; echo "  5.18.40: PASSED. Send this LOG FILE back (not a copy of the terminal)."
else echo; echo "  5.18.40: $FAIL FAILED — send the log file; do not roll back on your own unless customers are affected."; fi
[ "$FAIL" = "0" ]
