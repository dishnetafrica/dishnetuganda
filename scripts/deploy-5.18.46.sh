#!/usr/bin/env bash
#
# deploy-5.18.46.sh — deploy plugin 5.18.46: a quotation summary a customer can read, and a price check the model
# cannot walk past by leaving out the commas (docs/41).
#
#   5.18.46  The quotation summary on WhatsApp (Uganda). The operator, 27 Sep, on order 000114: "here Monthly give
#            wrong in message"; then, asked to decide as a customer would read it: "i will go with your
#            recommandation", and "Use the clearer message". A quote now says what is paid once, that the first month
#            is inside the total, and what the plan costs after that:
#               💰 One-time: UGX 2,399,000
#               💰 First month: UGX 249,000 (then UGX 249,000 per month)
#               🏷️ Total: UGX 2,648,000
#            The monthly line is the one spelled like one of uCRM's service plans — the rule the price feed and the
#            assistant already use — so an access point, cable, connectors or consultancy on a quote are one-time, not
#            "Monthly" as before. South Sudan's summary is byte-identical, and its uCRM is asked nothing more.
#
#            The price check, where the hardware module is on (Uganda). On 27 Sep this script's own stage AI saw the
#            assistant write two wrong setup totals — "1993500 UGX" and "1999500 UGX" for 1,897,500 — and "TOTAL FOR
#            SETUP: [Sum of setup costs]"; the check refused none of them, because it read only amounts written with
#            commas and the prompt prints every price without them. It now reads both, and refuses an unfilled slot.
#            A refused reply becomes the safe fallback and the conversation is handed to a person, as before.
#
#            And the check this script runs (stage AI) judges with the same checks, and says so.
#
# It carries everything 5.18.45 deployed. Stage V re-checks the public pages exactly as deploy-5.18.45.sh did (all ok
# live on 27 Sep 07:54). No knowledge row changes, so there is no stage K.
#
# Same machinery as deploy-5.18.45.sh — before-evidence, the backup as fixed on 27 Sep (the two live databases copied
# with SQLite's VACUUM INTO and checked; tar's own words shown), the documented deploy (scripts/deploy-hybrid.sh),
# stage V over the PUBLIC address and over :8443 — which ROLLS BACK BY ITSELF if the public address were ever
# redirected — and stage AI.
#
# Run as root on the server, then send back THE LOG FILE (never a copy of the terminal):
#
#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg \
#     && mkdir -p /root/dnb-5.18.46 \
#     && bash scripts/deploy-5.18.46.sh 2>&1 | tee /root/dnb-5.18.46/deploy-$(date -u +%Y%m%dT%H%M%SZ).log
#
# Options
#   --after-only         the deploy already happened: run the checks
#   --plugin-base <url>  the public URL of public.php, if the derived one is wrong
#
# What it never does: touch a configuration value, a customer, a quotation, a knowledge row, the webhook key,
# Traefik, UISP or the website. The writes are the documented deploy, a backup under /root/dnb-5.18.46/ (its database
# copies pass through the container's /tmp and are removed there at once) — and, only if stage V finds the public
# address redirecting, the documented rollback. Stage AI spends eleven model calls on the configured provider (a few
# cents) and sends nothing.
#
# Stages:  A before-evidence + backup → GO/NO-GO   B the documented deploy
#          V the public pages, the :8443 door and the loop check   AI the assistant, asked   F summary
set -uo pipefail
umask 077

PLUGIN="dishnet-hybrid-sudan"
CONTAINER="${UCRM_CONTAINER:-ucrm}"
EXPECTED_PLUGIN_COMMIT="131712a"   # 5.18.46 — the plugin-scoped commit deploy-hybrid.sh records
EXPECTED_VERSION="5.18.46"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$REPO/$PLUGIN"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="${DNB_OUT:-/root/dnb-5.18.46}"; mkdir -p "$OUT"; chmod 700 "$OUT"
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

hdr "$EXPECTED_VERSION — $TS — $(hostname)"

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
IN_CONTAINER="${IN_CONTAINER:-/data/ucrm/data/plugins/$PLUGIN}"
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
[ -d "$DATA_HOST" ] || DATA_HOST="$PDD_IN"   # a data directory outside /data is the same path on both sides (the rehearsal's sandbox)
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
http GET "$PLUGIN_BASE?page=terms"
echo "  before: the Terms page → $HTTP_CODE; 'South Sudan' ×$(count 'South Sudan') · Juba ×$(count 'Juba') · 'registered in Uganda' ×$(count 'registered in Uganda')  (0 · 0 · 1 since A2 went live)"
http GET "$PLUGIN_BASE?page=api&action=app_legal_version"
echo "  before: app_legal_version → $HTTP_CODE $(printf '%s' "$HTTP_BODY" | grep -o '"tos_version":"[^"]*"' | head -1)  (1.1 since A2 went live; this deploy keeps it — nobody is asked again)"

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
  # The live databases first, each copied as of one moment. tar cannot copy a file that is being written: this
  # script's first run (27 Sep 2026, 07:00:32 UTC) stopped here with "backup of …-data failed" and nothing more,
  # because tar's own words were thrown away. GNU tar exits 1, "file changed as we read it", when a file grows
  # while it reads it — the rehearsal reproduces exactly that line — and at the top of the hour the plugin's jobs
  # are writing. So SQLite makes the copy itself (VACUUM INTO, one read transaction) inside the container, as the
  # database's owner (the journey audit's rule: no -wal or -shm file changes hands); the copy is checked with
  # integrity_check and its sha256 compared on both sides, and the temporary file is removed. tar then takes the
  # rest of the directory without the live database files, and says why whenever it exits non-zero.
  SNAP_DBS="plugin.sqlite3 dishnet.sqlite"       # the plugin's two live databases (main.php, post_sync.php)
  SNAP_TMP="${DNB_SNAPSHOT_TMP:-/tmp}"           # inside the container, as the plugin's own backups use it
  mask() { sed -E 's/[0-9]{4,}/####/g; s/[^[:space:]/@]+@[^[:space:]/]+/…@…/g'; }
  SNAPSHOT_PHP="$(cat <<'PHP'
$src = $argv[1]; $tmp = $argv[2];
if (file_exists($tmp)) { fwrite(STDERR, "a file is already at the temporary path\n"); exit(4); }
register_shutdown_function(function () use ($tmp) { @unlink($tmp); });
try {
    $o = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 30];
    $db = new PDO("sqlite:" . $src, null, null, $o);
    $v = (string)$db->query("SELECT sqlite_version()")->fetchColumn();
    $db->exec("VACUUM INTO " . $db->quote($tmp));
    $db = null;
    $c = new PDO("sqlite:" . $tmp, null, null, $o);
    $ic = $c->query("PRAGMA integrity_check")->fetchAll(PDO::FETCH_COLUMN);
    $t = (int)$c->query("SELECT count(*) FROM sqlite_master WHERE type = 'table'")->fetchColumn();
    $c = null;
    if ($ic !== ["ok"]) { fwrite(STDERR, "integrity_check of the copy: " . implode("; ", array_slice($ic, 0, 3)) . "\n"); exit(3); }
    clearstatcache();
    $n = filesize($tmp); $h = hash_file("sha256", $tmp);
    $f = fopen($tmp, "rb"); $w = fopen("php://stdout", "wb");
    $sent = stream_copy_to_stream($f, $w); fclose($f); fclose($w);
    if ($sent !== $n) { fwrite(STDERR, "sent $sent of $n bytes\n"); exit(5); }
    fwrite(STDERR, "SNAPSHOT $n $h $t $v\n");
} catch (Throwable $e) { fwrite(STDERR, get_class($e) . ": " . $e->getMessage() . "\n"); exit(2); }
PHP
)"
  for db in $SNAP_DBS; do
    src="$PDD_IN/$db"
    if ! docker exec "$CONTAINER" test -f "$src" 2>/dev/null; then echo "  …     $db is not in the data directory — nothing to copy"; continue; fi
    owner="$(docker exec "$CONTAINER" stat -c '%u:%g' "$src" 2>/dev/null || true)"
    if [ -z "$owner" ]; then bad "backup of $db failed: could not read who owns it"; continue; fi
    docker exec -u "$owner" "$CONTAINER" php -d display_errors=stderr -r "$SNAPSHOT_PHP" "$src" "$SNAP_TMP/dnb-backup-$TS-$db" \
      > "$BK/$db" 2> "$BK/$db.err"; rc=$?
    docker exec -u "$owner" "$CONTAINER" rm -f "$SNAP_TMP/dnb-backup-$TS-$db" 2>/dev/null || true
    set -- $(grep '^SNAPSHOT ' "$BK/$db.err" | tail -1)   # SNAPSHOT <bytes> <sha256> <tables> <sqlite version>
    got_n="$(stat -c %s "$BK/$db" 2>/dev/null || echo 0)"; got_h="$(sha256sum "$BK/$db" 2>/dev/null | cut -c1-64)"
    if [ "$rc" = "0" ] && [ "${1:-}" = "SNAPSHOT" ] && [ "$got_n" = "${2:-}" ] && [ "$got_h" = "${3:-}" ]; then
      ok "backed up $db → $BK/$db ($(du -h "$BK/$db" | cut -f1)) — one consistent copy (VACUUM INTO as $owner, SQLite ${5:-?}), integrity ok, ${4:-?} tables, the same sha256 on both sides"
    elif [ "$rc" = "0" ] && [ "${1:-}" = "SNAPSHOT" ]; then
      bad "backup of $db failed: the copy that arrived is not the copy checked ($got_n of ${2:-?} bytes; sha256 differs)"
    else
      bad "backup of $db failed (exit $rc): $(grep -v '^SNAPSHOT ' "$BK/$db.err" | grep -v '^$' | head -2 | tr '\n' ' ' | sed 's/ *$//' | mask | cut -c1-240)"
    fi
    set --
  done
  DONE_DIRS=""
  for d in "$DATA_HOST" "$DEST/data" "$MOUNT/ucrm/data/plugins/.$PLUGIN-data"; do
    [ -d "$d" ] || continue
    case " $DONE_DIRS " in *" $d "*) continue;; esac; DONE_DIRS="$DONE_DIRS $d"
    n="$(basename "$d" | tr -c 'A-Za-z0-9._\n-' '_')"; [ -n "$n" ] || n="data-$(date -u +%s)"
    [ -e "$BK/$n.tar.gz" ] && n="$n-$(date -u +%H%M%S)"
    ex=(); why=""
    if [ "$d" -ef "$DATA_HOST" ]; then   # the data directory: its live databases were copied above
      for db in $SNAP_DBS; do for s in "" -wal -shm -journal; do ex+=("--exclude=$(basename "$d")/$db$s"); done; done
      why=" — without the live databases, copied above"
    fi
    tar -C "$(dirname "$d")" ${ex[@]+"${ex[@]}"} -czf "$BK/$n.tar.gz" "$(basename "$d")" 2> "$BK/$n.tar.err"; rc=$?
    if [ "$rc" -le 1 ] && tar -tzf "$BK/$n.tar.gz" >/dev/null 2>&1; then
      ok "backed up $d → $BK/$n.tar.gz ($(du -h "$BK/$n.tar.gz" | cut -f1))$why"
      # Exit 1 is GNU tar's "some files differ": a file changed, shrank or went away while it was read. The archive
      # is complete and readable (checked just above); those files are in it as tar found them.
      [ "$rc" = "1" ] && { note "while tar read $d, $(grep -c . "$BK/$n.tar.err") file(s) changed or went away (tar exit 1: logs and the like, archived as tar found them). It said:"
        grep . "$BK/$n.tar.err" | head -5 | mask | cut -c1-200 | sed 's/^/          /'; }
    else
      bad "backup of $d failed (tar exit $rc$([ "$rc" -le 1 ] && printf '; the archive it wrote cannot be read back')). It said:"
      grep . "$BK/$n.tar.err" | head -5 | mask | cut -c1-200 | sed 's/^/          /'
    fi
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
# A2 — the wording itself (docs/38 §7.3, approved): the Uganda sentences present, no South Sudan sentence, no unconfirmed fee.
if [ "$(count 'South Sudan')" = "0" ] && [ "$(count 'Juba')" = "0" ]; then ok "V2 the Terms page names South Sudan ×0 and Juba ×0 (A2)"; else bad "V2 the Terms page still names South Sudan ×$(count 'South Sudan') · Juba ×$(count 'Juba')"; fi
if [ "$(count 'registered in Uganda (Reg. No. 80046255496181)')" != "0" ] && [ "$(count 'laws of the Republic of Uganda')" != "0" ] && [ "$(count 'the courts of Uganda')" != "0" ]; then ok "V2 the Terms page carries the approved Uganda identity, governing law and forum (A2 points 1–3)"
else bad "V2 the Terms page: identity ×$(count 'registered in Uganda (Reg. No. 80046255496181)') · law ×$(count 'laws of the Republic of Uganda') · forum ×$(count 'the courts of Uganda')"; fi
if [ "$(count 'USD 25')" = "0" ] && [ "$(count 'USD 150')" = "0" ] && [ "$(count 'fibre')" = "0" ] && [ "$(count 'LTE')" = "0" ]; then ok "V2 the Terms page states no South Sudan fee and no fibre/LTE product (A2 points 7–8)"; else bad "V2 the Terms page: 'USD 25' ×$(count 'USD 25') · 'USD 150' ×$(count 'USD 150') · fibre ×$(count 'fibre') · LTE ×$(count 'LTE')"; fi
[ "$(count 'v1.1')" != "0" ] && ok "V2 the Terms page shows version 1.1" || bad "V2 the Terms page shows no v1.1"
http GET "$PLUGIN_BASE?page=privacy"
[ "$HTTP_CODE" = "200" ] && [ "$(count '+211')" = "0" ] && [ "$(count 'dishnetafrica.com')" = "0" ] && ok "V2 the Privacy page answers 200 with no South Sudan contact" || bad "V2 Privacy page → $HTTP_CODE; '+211' ×$(count '+211') · dishnetafrica.com ×$(count 'dishnetafrica.com')"
if [ "$(count 'The Uganda Communications Commission and other Ugandan authorities')" != "0" ] && [ "$(count 'your WhatsApp number or your e-mail address')" != "0" ] && [ "$(count 'South Sudan')" = "0" ] && [ "$(count 'Splynx')" = "0" ]; then ok "V2 the Privacy page carries the Uganda regulator sentence and both sign-in channels, and no South Sudan or fibre/LTE clause (A2 points 4, 9, 10)"
else bad "V2 the Privacy page: regulator ×$(count 'The Uganda Communications Commission and other Ugandan authorities') · channels ×$(count 'your WhatsApp number or your e-mail address') · 'South Sudan' ×$(count 'South Sudan') · Splynx ×$(count 'Splynx')"; fi
http GET "$PLUGIN_BASE?page=api&action=app_legal_version"
if [ "$HTTP_CODE" = "200" ] && printf '%s' "$HTTP_BODY" | grep -q '"tos_version":"1.1"' && printf '%s' "$HTTP_BODY" | grep -q '"privacy_version":"1.1"'; then ok "V2 app_legal_version answers 1.1 / 1.1 — every Uganda customer accepts the new wording once on the next sign-in (A2 point 6)"
else bad "V2 app_legal_version → $HTTP_CODE $(printf '%s' "$HTTP_BODY" | cut -c1-160)"; fi

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

# Q — the quotation summary is the installed one (it is proved by tests/test_quote_summary.php; a quotation is never
# created from here: the next one the team makes shows it).
if grep -q 'function whQuotePlans(' "$DEST/webhook.php" 2>/dev/null && grep -q '"💰 One-time: "' "$DEST/webhook.php" 2>/dev/null; then
  ok "Q the installed webhook carries the 5.18.46 quotation summary (One-time / First month, then … per month / Total)"
else bad "Q the installed webhook does not carry the 5.18.46 quotation summary"; fi

# ════════════════════════════════════════════════════════
hdr "AI. The assistant, asked — scripts/dnb-ai-check.sh --ask (eleven questions on the sales number; nothing is sent to anyone)"
# ════════════════════════════════════════════════════════
# The replies are printed for reading; they are measurements of the model, not pass/fail of the deploy. What is
# judged is what the assistant is GIVEN: the version, the network equipment, the knowledge limit, the fact, the row.
AIOUT="$(IN_CONTAINER="$IN_CONTAINER" UCRM_CONTAINER="$CONTAINER" bash "$REPO/scripts/dnb-ai-check.sh" --ask 2>&1)"
printf '%s\n' "$AIOUT" | sed 's/^/     /'
# A here-string, never `printf | grep -q`: under pipefail, grep -q leaving at its first match can kill printf with
# SIGPIPE, and the pipeline then reads as "no match". It did on 27 Sep: deploy-5.18.44.sh reported no access point
# while its own listing showed one (docs/40 §13).
aihas() { grep -qE -- "$1" <<<"$AIOUT"; }
aihas "\\(report\\) — plugin $EXPECTED_VERSION " && ok "AI the check reads plugin $EXPECTED_VERSION" || bad "AI the check does not read plugin $EXPECTED_VERSION"
if aihas 'NETWORK EQUIPMENT \(one-time\) +[1-9]'; then ok "AI network equipment is listed to the assistant: $(printf '%s' "$AIOUT" | grep -E 'NETWORK EQUIPMENT \(one-time\)' | head -1 | sed -E 's/ +/ /g; s/^ //')"
else bad "AI no product is listed as network equipment — the assistant has nothing to design with"; fi
aihas '← router$' && ok "AI a router (the MikroTik) is among it" || note "AI no router is recognised among the network equipment"
aihas '← access point$' && ok "AI an access point is among it" || note "AI no access point is recognised among the network equipment"
aihas 'each answer reaches it up to +1000 characters' && ok "AI approved knowledge reaches the assistant up to 1,000 characters" || bad "AI the knowledge limit is not 1,000"
if aihas 'STARLINK ROUTERS \(indoor\) +[1-9]'; then ok "AI Starlink routers are listed to the assistant: $(printf '%s' "$AIOUT" | grep -E 'STARLINK ROUTERS \(indoor\)' | head -1 | sed -E 's/ +/ /g; s/^ //')"
else bad "AI no accessory is a Starlink router — the rule for more floors cannot apply"; fi
aihas '"unlimited" fact +(the default wording|your own wording)' && ok "AI the data-allowance fact is stated to the assistant" || note "AI the data-allowance fact is off (ai_fact_unlimited=omit) or not stated"
if aihas "BUSINESS_PLANS +fact · approved · as seeded · 981 chars"; then ok "AI BUSINESS_PLANS still reads as 5.18.45 left it (981 characters, whole)"
elif aihas "BUSINESS_PLANS +fact · approved · edited"; then note "AI BUSINESS_PLANS is a person's wording"
else bad "AI BUSINESS_PLANS does not read as 5.18.45 left it"; fi
aihas 'after the priority block it says: unlimited standard data continues' && bad "AI a knowledge row still says standard data continues after the priority block (see section 3 above)" \
  || ok "AI no knowledge row says standard data continues after the priority block"
if aihas "MANY_USERS_HOTSPOT +fact · approved · as seeded · 994 chars"; then ok "AI MANY_USERS_HOTSPOT still reads as 5.18.44 left it (994 characters)"
else note "AI MANY_USERS_HOTSPOT is no longer the 5.18.44 row (a person's wording?) — see section 3 above"; fi
# 5.18.46: the price check judges with the added checks, and says so — and a refusal is a measurement, not a failure.
if aihas 'the price check also +reads amounts written without commas'; then ok "AI the price check reads amounts written without commas and refuses an unfilled slot (5.18.46)"
else bad "AI the price check does not read amounts written without commas — 5.18.46 does wherever the hardware module (ai_hardware_expert) is on"; fi
aihas 'plan copies dropped +[1-9]' && ok "AI products spelled like a plan are read as the plan, as the quotation summary now reads its lines: $(printf '%s' "$AIOUT" | grep -E 'plan copies dropped' | head -1 | sed -E 's/ +/ /g; s/^ //')" \
  || note "AI no product in uCRM is spelled like a plan (plan copies dropped 0) — a quotation line is read as the plan only when it carries a service plan's name"
if aihas 'Asked: 11 model call'; then
  ok "AI the eleven questions were asked — read the replies above"
  NREF="$(printf '%s' "$AIOUT" | grep -oE 'Asked: 11 model call\(s\); [0-9]+ refused by the price check' | grep -oE '[0-9]+ refused' | grep -oE '^[0-9]+')"
  [ "${NREF:-0}" = "0" ] || note "AI ${NREF} of the replies were refused by the price check — each shows above what it could not match or the slot it left unfilled; the customer would have had the fallback and a person"
else bad "AI the questions were not asked (see the check's own STOP line above)"; fi

# ════════════════════════════════════════════════════════
hdr "F. Summary"
# ════════════════════════════════════════════════════════
echo "  deployed commit   $LIVE_AFTER  (plugin $EXPECTED_VERSION)"
if [ "$AFTER_ONLY" = "0" ]; then echo "  rollback commit   ${LIVE_BEFORE:-unknown}   →  cd $REPO && git checkout ${LIVE_BEFORE:-<commit>} && bash scripts/deploy-hybrid.sh"
else echo "  rollback commit   (this run deployed nothing — see the deployment run's log)"; fi
[ -n "$BK" ] && echo "  backup            $BK"
echo "  the assistant     its replies are in stage AI above; compare them with stage AI of /root/dnb-5.18.45/deploy-*.log (27 Sep, 5.18.45)"
echo "  quotations        the next quotation made in uCRM shows One-time / First month (then … per month) / Total"
echo "  switch off        the \"unlimited\" fact: docs/40 §12 item 3 (set_config.php --key ai_fact_unlimited --value omit, as the plugin's owner)"
echo "  checks            $PASS ok, $FAIL failed, $NOTE notes"
if [ "$FAIL" = "0" ]; then echo; echo "  $EXPECTED_VERSION: PASSED. Send this LOG FILE back (not a copy of the terminal)."
else echo; echo "  $EXPECTED_VERSION: $FAIL FAILED — send the log file; do not roll back on your own unless customers are affected."; fi
[ "$FAIL" = "0" ]
