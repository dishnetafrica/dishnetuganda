#!/usr/bin/env bash
#
# deploy-5.18.72.sh — the Staff Cashbooks tiles on Uganda read "received · Advances & collections" and "Still to account
#            for", not "collected" and "Needs handover" (plugin 5.18.72) over 5.18.71, or roll it back to 5.18.71.
#
#   5.18.72  The operator, on the technician's Staff Cashbooks page (UGX 339,672.00 held: 650,000 received, 310,328 out):
#            "fix the collected and handover wording for uganda". The tiles read "UGX collected" and "Needs handover" —
#            South Sudan's collections wording, where field staff collect payments and hand them over to accounts. On
#            Uganda the staff member's inflow is advances (and any collections) to be spent on approved expenses and
#            accounted for. Now, on a book without SSP (tabs/accounts/staff_cashbooks.php, the selected-staff view), the
#            first tile reads "<base> received" with "Advances & collections" beneath it, and the "Cash with staff" line
#            reads "Still to account for" while a balance is held ("All settled ✓" otherwise). Every figure, the hero, its
#            pills and "Handed over · Given to accounts" are unchanged. South Sudan's page is the same bytes as before,
#            proved on a South Sudan sandbox from the hero to the currency tabs, on both tabs.
#            Wording only. No migration, no new table, no uCRM write, no setting changed, nothing written to any record.
#
#   THE COMMIT IT INSTALLS IS A RELEASE COMMIT, NOT THE BRANCH TIP — as 5.18.66 through 5.18.71 were. The pin below, on
#            release/5.18.72, is 5.18.72's own changes applied on b350192 (the 5.18.71 release commit production runs). The
#            branch tip still carries the undeployed distributor partner-portal stack (WS-A P4a–P4d) and the PD-8 CSRF guard;
#            stage A refuses a pin whose parent is not 5.18.71 or whose delta carries any of that work.
#
#   Scope:   wording only, one page. No migration, no new table, no uCRM write, no message. Nothing here writes to a cash
#            record; there is no repair command this time.
#
#   Regression: because the whole plugin tree is copied, stage R doubles as a full check that Release A (5.18.49) through
#            5.18.71 — the photo surface, the staff-cash chain, the 5.18.69/5.18.70 fixes and the 5.18.71 landing hero
#            included — is installed byte-for-byte as the pin has it (R5), migration 084 is still in place with its two
#            tables (R3), the photo surface is still present and Uganda-gated (R6), the pilot still binds the Null channel
#            (R4) and its switch is untouched (V3, R2). R7 runs 5.18.71's read-only cash-in-hand tool on the live book.
#
# 5.18.71 must be installed first (scripts/deploy-5.18.71.sh): this script refuses any other live commit.
#
# Run as root on the server, then send back THE LOG FILE (never a copy of the terminal):
#
#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.72 \
#     && mkdir -p /root/dnb-5.18.72 \
#     && bash scripts/deploy-5.18.72.sh 2>&1 | tee /root/dnb-5.18.72/deploy-$(date -u +%Y%m%dT%H%M%SZ).log
#
# The rollback is a separate command, printed at the end of the deploy's log. It is never pasted together with the one
# above: pasted together, the shell runs both (root docs/44 §16.9).
#
# Options
#   --after-only         the deploy already happened: run the checks again
#   --rollback           put 5.18.71 back (typed ROLLBACK), then check it
#   --plugin-base <url>  the public URL of public.php, if the derived one is wrong
#
# What it never does: change the pilot switch or any other configuration value, touch a customer, a staff account, a job,
# a message, a photo, a cash record, the webhook key, Traefik, UISP or the website; send anything; sign anyone in; run a
# cron; call uCRM. Every read of the plugin's data opens the database READ-ONLY; the cash-in-hand tool only reads. The
# writes are the documented deploy (or rollback), a backup under /root/dnb-5.18.72/, a record of where the deploy started,
# and the time of the copy on the files this release changes.
#
# Stages:  A before-evidence, the syntax check under the server's own PHP, the switch state, the backup → GO/NO-GO
#          B the documented deploy (or, with --rollback, the documented deploy of 5.18.71); the copy's time on each file
#          V the public pages (no loop, the portal still refuses, the Uganda contacts), the pilot switch UNCHANGED by the
#            deploy, the photo viewer and upload action still refusing an anonymous caller, and no fatal since the deploy
#          R 5.18.72 installed: the changed files byte-for-byte and the version (R1); the switch unchanged (R2); 084 still
#            installed with its two tables and their row counts (R3); the pilot code still bound to the Null channel (R4);
#            Release A→5.18.71 still installed (R5); the photo surface, the staff-cash chain, the 5.18.69/5.18.70 fixes, the
#            5.18.71 hero and tool, and the 5.18.72 wording present (R6); cash in hand on the live book, read-only (R7)
#          F summary
set -uo pipefail
umask 077

main() {
PLUGIN="dishnet-hybrid-sudan"
CONTAINER="${UCRM_CONTAINER:-ucrm}"
EXPECTED_PLUGIN_COMMIT="88d8442"   # 5.18.72 (release) — the Staff Cashbooks tiles on Uganda read received / Still to account for; cut on 5.18.71; branch release/5.18.72
EXPECTED_VERSION="5.18.72"
BASELINE_COMMIT="b350192"          # 5.18.71 (release) — what the server runs first, and the rollback
BASELINE_VERSION="5.18.71"
RELEASE_A_BASE="e076632"           # 5.18.49 — the regression base: R5 checks every file from Release A through 5.18.71 is installed as pinned
RELEASE_BRANCH="release/5.18.72"   # where the release commit lives; fetched when the checkout does not hold it
BRANCH="claude/study-this-jhe2eg"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$REPO/$PLUGIN"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="${DNB_OUT:-/root/dnb-5.18.72}"; mkdir -p "$OUT"; chmod 700 "$OUT"
STATE="$OUT/state-$EXPECTED_VERSION.env"
GUARD_SECONDS="${GUARD_SECONDS:-60}"
TOOL="tools/set_distributors.php"   # reads the live pilot switch (read-only with --show); a regression check here
LAYOUT_MARK='flex-shrink:0;">📷 '    # the one-line button of 5.18.67's card (R6 regression)
BACKFILL_TOOL="tools/backfill_staff_cash_ins.php"   # 5.18.68 (R6 regression: installed)
RECORDS_TOOL="tools/staff_records_currency.php"      # 5.18.70 (R6 regression: installed, and still 5.18.70's)
CIH_TOOL="tools/cash_in_hand.php"                     # 5.18.71 (R6 regression: installed); run in R7 — READ-ONLY, nothing written

MODE="deploy"; PLUGIN_BASE="${PLUGIN_BASE:-}"
while [ $# -gt 0 ]; do
  case "$1" in
    --after-only) MODE="after" ;;
    --rollback) MODE="rollback" ;;
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

# ── HTTP helper ──────────────────────────────────────────────────────────────
HTTP_CODE=""; HTTP_BODY=""; HTTP_HEADERS=""; HTTP_REDIRECTS=""; HTTP_LOCATION=""
http() {   # http METHOD URL [curl args...]
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
live_commit() { docker exec "$CONTAINER" cat "$IN_CONTAINER/.deployed-commit" 2>/dev/null | tail -n1 | tr -cd '0-9a-f'; }
log_since() { docker logs "$CONTAINER" --timestamps --since "$1" 2>&1 | awk -v t="${1:0:19}" 'substr($1, 1, 19) >= t'; }

hdr "$EXPECTED_VERSION ($MODE) — $TS — $(hostname)"

# ════════════════════════════════════════════════════════
hdr "A. Before-evidence (read-only)"
# ════════════════════════════════════════════════════════
HEAD_REPO="$(git -C "$REPO" rev-parse --short HEAD 2>/dev/null || echo unknown)"
HEAD_PLUGIN="$(git -C "$REPO" log -1 --format=%h -- "$PLUGIN" 2>/dev/null || echo unknown)"
DIRTY="$(git -C "$REPO" status --porcelain --untracked-files=no 2>/dev/null | wc -l | tr -d ' ')"
echo "  checkout        $REPO"
echo "  repo HEAD       $HEAD_REPO"
echo "  branch tip      $HEAD_PLUGIN   (the plugin commit on $BRANCH — NOT what this script installs)"
echo "  release commit  $EXPECTED_PLUGIN_COMMIT   (on $RELEASE_BRANCH, cut on $BASELINE_COMMIT)"
# The release commit is not on the branch: fetch it when the checkout does not hold it yet, then prove it is cut on the
# live version. A pin whose parent is anything else is another build, and stops here.
if ! git -C "$REPO" cat-file -e "$EXPECTED_PLUGIN_COMMIT^{commit}" 2>/dev/null; then
  echo "  fetching        origin $RELEASE_BRANCH — the release commit is not in this checkout yet"
  git -C "$REPO" fetch -q origin "$RELEASE_BRANCH" 2>&1 | sed 's/^/     /' || true
fi
git -C "$REPO" cat-file -e "$EXPECTED_PLUGIN_COMMIT^{commit}" 2>/dev/null \
  || stop "the checkout does not hold $EXPECTED_PLUGIN_COMMIT — run: cd $REPO && git fetch origin $RELEASE_BRANCH, then this script again"
PIN_PARENT="$(git -C "$REPO" rev-parse --short "$EXPECTED_PLUGIN_COMMIT^" 2>/dev/null || echo '?')"
[ "$PIN_PARENT" = "$BASELINE_COMMIT" ] || stop "$EXPECTED_PLUGIN_COMMIT is not cut on $BASELINE_COMMIT ($BASELINE_VERSION): its parent is $PIN_PARENT — this script is for another build"
PIN_CHECKOUT=1
echo "  installs        $EXPECTED_PLUGIN_COMMIT by its hash: 5.18.72's own changes on the live $BASELINE_VERSION, without the undeployed work the branch tip ($HEAD_PLUGIN) also carries"
VERSION_SRC="$(git -C "$REPO" show "$EXPECTED_PLUGIN_COMMIT:$PLUGIN/manifest.json" 2>/dev/null | grep -o '"version": *"5[^"]*"' | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/')"
echo "  plugin version  ${VERSION_SRC:-?}   (expected $EXPECTED_VERSION)"
echo "  tracked edits   $DIRTY"
[ "$VERSION_SRC" = "$EXPECTED_VERSION" ]        || stop "manifest.json in $EXPECTED_PLUGIN_COMMIT says ${VERSION_SRC:-?}, expected $EXPECTED_VERSION"
[ "$DIRTY" = "0" ]                              || stop "the checkout has $DIRTY locally edited tracked files — deploying would ship edits nobody reviewed"
git -C "$REPO" cat-file -e "$BASELINE_COMMIT^{commit}" 2>/dev/null || stop "the checkout does not hold $BASELINE_COMMIT ($BASELINE_VERSION) — the rollback commit must be present before anything changes"
git -C "$REPO" cat-file -e "$RELEASE_A_BASE^{commit}" 2>/dev/null || stop "the checkout does not hold $RELEASE_A_BASE (Release A's baseline) — pull the branch again"

CHANGED="$(git -C "$REPO" diff --no-renames --name-only --diff-filter=AM "$BASELINE_COMMIT" "$EXPECTED_PLUGIN_COMMIT" -- "$PLUGIN")"
ADDED="$(git -C "$REPO" diff --no-renames --name-only --diff-filter=A "$BASELINE_COMMIT" "$EXPECTED_PLUGIN_COMMIT" -- "$PLUGIN")"
DELETED="$(git -C "$REPO" diff --no-renames --name-only --diff-filter=D "$BASELINE_COMMIT" "$EXPECTED_PLUGIN_COMMIT" -- "$PLUGIN")"
N_CHANGED="$(printf '%s\n' "$CHANGED" | grep -c . || true)"; N_ADDED="$(printf '%s\n' "$ADDED" | grep -c . || true)"
echo "  $EXPECTED_VERSION         $N_CHANGED files differ from $BASELINE_COMMIT: $((N_CHANGED - N_ADDED)) changed, $N_ADDED added, $(printf '%s\n' "$DELETED" | grep -c . || true) removed"
[ "$N_CHANGED" -gt 0 ] || stop "Git reports no file between $BASELINE_COMMIT and $EXPECTED_PLUGIN_COMMIT"
[ -z "$DELETED" ] || note "files removed/renamed in $EXPECTED_VERSION stay on the server under their old name (deploy-hybrid.sh never deletes): $(printf '%s ' $DELETED | sed "s#$PLUGIN/##g")"
N_MIG="$(printf '%s\n' "$CHANGED" | grep -c "^$PLUGIN/migrations/" || true)"
echo "  migrations      $N_MIG added since $BASELINE_VERSION (5.18.72 adds none)"
[ "$N_MIG" = "0" ] || stop "the pin carries $N_MIG migration(s); 5.18.72 is code only — this script is for another build"
# The release must carry none of the undeployed portal / CSRF work.
STRAY="$(printf '%s\n' "$CHANGED" | grep -E "^$PLUGIN/(partner_api\.php|lib/Partner|lib/StaffApiCsrf\.php|lib/Totp\.php|lib/DistributorPortalData\.php|migrations/08[123]_)" || true)"
[ -z "$STRAY" ] || stop "the pin carries undeployed partner-portal / CSRF work, which this release must not ship: $(printf '%s ' $STRAY | sed "s#$PLUGIN/##g")"
ok "A0 the release delta carries no partner-portal or CSRF file and no migration — $N_CHANGED files, this release's own"

MOUNT="$(docker inspect "$CONTAINER" --format '{{range .Mounts}}{{if eq .Destination "/data"}}{{.Source}}{{end}}{{end}}' 2>/dev/null || true)"
[ -n "$MOUNT" ] || stop "container '$CONTAINER' has no /data mount"
DEST="$MOUNT/ucrm/data/plugins/$PLUGIN"
IN_CONTAINER="${IN_CONTAINER:-/data/ucrm/data/plugins/$PLUGIN}"
[ -f "$DEST/manifest.json" ] || stop "no installed plugin at $DEST"
LIVE_BEFORE="$(live_commit)"
LIVE_VERSION="$(grep -o '"version": *"5[^"]*"' "$DEST/manifest.json" | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/')"
echo "  serves          $DEST"
echo "  live commit     ${LIVE_BEFORE:-unknown}"
echo "  live version    ${LIVE_VERSION:-?}"
echo "  --check says:"; bash "$REPO/scripts/deploy-hybrid.sh" --check 2>&1 | sed 's/^/     /'
echo "     (--check compares the container with the branch tip, $HEAD_PLUGIN; this script installs $EXPECTED_PLUGIN_COMMIT, so it reads \"NOT up to date\" before and after — expected)"

# The in-container plugin data directory (holds plugin.sqlite3), derived read-only — for the switch tool and the R3 table check.
PDD_IN="$(docker exec "$CONTAINER" php -r '$u=@json_decode((string)@file_get_contents($argv[1]),true); echo rtrim((string)($u["pluginDataDir"]??""),"/");' "$IN_CONTAINER/ucrm.json" 2>/dev/null || true)"
if [ -z "$PDD_IN" ]; then
  if docker exec "$CONTAINER" test -f "/data/ucrm/data/plugins/.$PLUGIN-data/plugin.sqlite3"; then PDD_IN="/data/ucrm/data/plugins/.$PLUGIN-data"; else PDD_IN="$IN_CONTAINER/data"; fi
fi

case "$MODE" in
  deploy)
    if [ "$LIVE_BEFORE" = "$EXPECTED_PLUGIN_COMMIT" ]; then note "the container already serves $EXPECTED_PLUGIN_COMMIT — skipping the deploy, running the checks"; MODE="after"
    elif [ "$LIVE_BEFORE" != "$BASELINE_COMMIT" ]; then
      stop "NO-GO: the container serves ${LIVE_BEFORE:-an unknown commit}; $EXPECTED_VERSION was built and tested against $BASELINE_COMMIT ($BASELINE_VERSION) — deploy $BASELINE_VERSION first (scripts/deploy-$BASELINE_VERSION.sh) and send its log"
    fi ;;
  after)
    [ "$LIVE_BEFORE" = "$EXPECTED_PLUGIN_COMMIT" ] || stop "the container serves ${LIVE_BEFORE:-?}, not $EXPECTED_PLUGIN_COMMIT" ;;
  rollback)
    if [ "$LIVE_BEFORE" = "$BASELINE_COMMIT" ]; then note "the container already serves $BASELINE_COMMIT ($BASELINE_VERSION) — nothing to roll back; running the rollback checks"
    elif [ "$LIVE_BEFORE" != "$EXPECTED_PLUGIN_COMMIT" ]; then
      stop "the container serves ${LIVE_BEFORE:-an unknown commit}, neither $EXPECTED_PLUGIN_COMMIT nor $BASELINE_COMMIT — roll back by hand, from the log of the deploy that put it there"
    fi ;;
esac

PHPV="$(docker exec "$CONTAINER" php -r 'echo PHP_VERSION;' 2>/dev/null | tr -cd '0-9.')"
[ -n "$PHPV" ] || stop "no php inside the container"
echo "  container PHP   $PHPV"
case "$PHPV" in 5.*|7.*) stop "the container's PHP is $PHPV; $EXPECTED_VERSION needs 8.0 or later (as $BASELINE_VERSION does)";; esac

# The live pilot switch, read with the plugin's own tool (--show is read-only). A regression check: this deploy never touches it.
switch_state() {  # prints "pilot=<on|off>"
  docker exec "$CONTAINER" php "$IN_CONTAINER/$TOOL" --show 2>/dev/null \
    | awk '/distributors_enabled/ { print "pilot=" (toupper($2)=="ON"?"on":"off"); exit }'
}
SW_BEFORE="$(switch_state)"
echo "  switch          ${SW_BEFORE:-pilot=?}   (the operator's setting; this deploy never changes it)"

# The two tables of migration 084 (from 5.18.66), read-only: present with their row counts, or lazy.
photo_tables_state() {  # prints present:<photos>:<gps> | lazy | nodb | ?
  docker exec "$CONTAINER" php -r '
    $db = rtrim((string)$argv[1], "/") . "/plugin.sqlite3";
    if (!is_file($db)) { echo "nodb"; exit; }
    try { $p = new PDO("sqlite:" . $db); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
    catch (Throwable $e) { echo "nodb"; exit; }
    try { $have = $p->query("SELECT name FROM sqlite_master WHERE type=\x27table\x27")->fetchAll(PDO::FETCH_COLUMN); }
    catch (Throwable $e) { echo "?"; exit; }
    if (!in_array("job_photos", $have, true) || !in_array("job_completion_gps", $have, true)) { echo "lazy"; exit; }
    try { echo "present:", (int)$p->query("SELECT COUNT(*) FROM job_photos")->fetchColumn(), ":", (int)$p->query("SELECT COUNT(*) FROM job_completion_gps")->fetchColumn(); }
    catch (Throwable $e) { echo "?"; }
  ' "$PDD_IN" 2>/dev/null || echo "?"
}
# The pilot's tables, as 5.18.65's own check read them — a regression (R4).
dist_tables_state() {  # prints present | lazy | nodb | ?
  docker exec "$CONTAINER" php -r '
    $db = rtrim((string)$argv[1], "/") . "/plugin.sqlite3";
    if (!is_file($db)) { echo "nodb"; exit; }
    try { $p = new PDO("sqlite:" . $db); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
    catch (Throwable $e) { echo "nodb"; exit; }
    $want = ["dist_partners","dist_appointment","dist_regions","dist_territory_map","dist_customer_links","dist_contacts","dist_notify_consent","dist_notify_log"];
    try { $have = $p->query("SELECT name FROM sqlite_master WHERE type=\x27table\x27")->fetchAll(PDO::FETCH_COLUMN); }
    catch (Throwable $e) { echo "?"; exit; }
    foreach ($want as $t) if (!in_array($t, $have, true)) { echo "lazy"; exit; }
    echo "present";
  ' "$PDD_IN" 2>/dev/null || echo "?"
}
# The photo folder in the data directory, read-only: absent, or how many files it holds.
photo_files_state() {  # prints absent | files:<n>
  docker exec "$CONTAINER" sh -c 'd="$1/uploads/job_photos"; if [ -d "$d" ]; then echo "files:$(find "$d" -type f | wc -l | tr -d " ")"; else echo absent; fi' _ "$PDD_IN" 2>/dev/null || echo "?"
}
TB_BEFORE="$(photo_tables_state)"; PF_BEFORE="$(photo_files_state)"
echo "  photo tables    $TB_BEFORE   (from 5.18.66; this deploy touches neither)"
echo "  photo files     $PF_BEFORE"

# The public address, by the plugin's own rule.
if [ -z "$PLUGIN_BASE" ]; then
  PLUGIN_BASE="$(docker exec "$CONTAINER" php -r '
    $r=$argv[1]; $pdd=$argv[2]; $u=@json_decode((string)@file_get_contents($r."/ucrm.json"),true)?:[];
    $over="";
    foreach ([$r."/data/config.json", $pdd."/config.json", $pdd."/kyc_config.json"] as $f) { $c=@json_decode((string)@file_get_contents($f),true)?:[]; $v=rtrim(trim((string)($c["crm_public_url"]??"")),"/"); if($v!==""){$over=$v; break;} }
    if($over!==""){ echo $over."/crm/_plugins/".basename($r)."/public.php"; exit; }
    if(!empty($u["pluginPublicUrl"])){ echo rtrim($u["pluginPublicUrl"],"/"); exit; }
    $b=rtrim((string)($u["ucrmPublicUrl"]??""),"/"); $b=preg_replace("#/crm$#","",$b); echo $b?$b."/crm/_plugins/".basename($r)."/public.php":"";' "$IN_CONTAINER" "$PDD_IN" 2>/dev/null || true)"
fi
echo "  plugin URL      ${PLUGIN_BASE:-<unknown>}"

# A2 — every changed PHP file, as the pinned commit has it, checked by the server's own PHP before a byte is copied.
if [ "$MODE" = "deploy" ]; then
  L_OK=0; L_TOK=0; L_BAD=""; L_TBAD=""
  for f in $(printf '%s\n' "$CHANGED" | grep '\.php$'); do
    if git -C "$REPO" show "$EXPECTED_PLUGIN_COMMIT:$f" > "/tmp/dnb_lint.$$" 2>/dev/null \
       && docker exec -i "$CONTAINER" php -l < "/tmp/dnb_lint.$$" 2>&1 | grep -q '^No syntax errors detected'; then
      case "$f" in "$PLUGIN"/tests/*) L_TOK=$((L_TOK+1));; *) L_OK=$((L_OK+1));; esac
    else case "$f" in "$PLUGIN"/tests/*) L_TBAD="$L_TBAD ${f#"$PLUGIN"/}";; *) L_BAD="$L_BAD ${f#"$PLUGIN"/}";; esac; fi
  done
  rm -f "/tmp/dnb_lint.$$"
  [ -z "$L_BAD" ] || stop "NO-GO: PHP $PHPV in the container rejects:$L_BAD"
  ok "A2 PHP $PHPV in the container accepts all $L_OK changed PHP files that run on the server (and $L_TOK test files)"
  [ -z "$L_TBAD" ] || note "PHP $PHPV rejects test files (they are copied, never run on the server):$L_TBAD"
fi

# ── Backup (code + data), on deploy or on a rollback from the new version ────
BK=""
if [ "$MODE" = "deploy" ] || { [ "$MODE" = "rollback" ] && [ "$LIVE_BEFORE" = "$EXPECTED_PLUGIN_COMMIT" ]; }; then
  hdr "A. Backup"
  BK="$OUT/backup-$TS"; mkdir -p "$BK"; chmod 700 "$BK"
  DATA_HOST="$MOUNT${PDD_IN#/data}"; [ -d "$DATA_HOST" ] || DATA_HOST="$PDD_IN"
  SNAP_DBS="plugin.sqlite3 dishnet.sqlite"
  SNAP_TMP="${DNB_SNAPSHOT_TMP:-/tmp}"
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
    docker exec -u "$owner" "$CONTAINER" php -d display_errors=stderr -r "$SNAPSHOT_PHP" "$src" "$SNAP_TMP/dnb-backup-$TS-$db" > "$BK/$db" 2> "$BK/$db.err"; rc=$?
    docker exec -u "$owner" "$CONTAINER" rm -f "$SNAP_TMP/dnb-backup-$TS-$db" 2>/dev/null || true
    set -- $(grep '^SNAPSHOT ' "$BK/$db.err" | tail -1)
    got_n="$(stat -c %s "$BK/$db" 2>/dev/null || echo 0)"; got_h="$(sha256sum "$BK/$db" 2>/dev/null | cut -c1-64)"
    if [ "$rc" = "0" ] && [ "${1:-}" = "SNAPSHOT" ] && [ "$got_n" = "${2:-}" ] && [ "$got_h" = "${3:-}" ]; then
      ok "backed up $db → $BK/$db ($(du -h "$BK/$db" | cut -f1)) — one consistent copy, integrity ok, ${4:-?} tables"
    elif [ "$rc" = "0" ] && [ "${1:-}" = "SNAPSHOT" ]; then bad "backup of $db failed: the copy that arrived is not the copy checked"
    else bad "backup of $db failed (exit $rc): $(grep -v '^SNAPSHOT ' "$BK/$db.err" | grep -v '^$' | head -2 | tr '\n' ' ' | cut -c1-200)"; fi
    set --
  done
  if [ -d "$DATA_HOST" ]; then
    ex=(); for db in $SNAP_DBS; do for s in "" -wal -shm -journal; do ex+=("--exclude=$(basename "$DATA_HOST")/$db$s"); done; done
    tar -C "$(dirname "$DATA_HOST")" ${ex[@]+"${ex[@]}"} -czf "$BK/data.tar.gz" "$(basename "$DATA_HOST")" 2> "$BK/data.tar.err"; rc=$?
    if [ "$rc" -le 1 ] && tar -tzf "$BK/data.tar.gz" >/dev/null 2>&1; then ok "backed up the data dir → $BK/data.tar.gz ($(du -h "$BK/data.tar.gz" | cut -f1)) — without the live databases, copied above"
    else bad "backup of the data dir failed (tar exit $rc)"; fi
  fi
  CODE_TAR="$BK/plugin-installed-${LIVE_VERSION:-unknown}.tar.gz"
  tar -C "$(dirname "$DEST")" --exclude="$PLUGIN/data" -czf "$CODE_TAR" "$PLUGIN" 2> "$BK/plugin-installed.tar.err"; rc=$?
  if [ "$rc" = "0" ] && tar -tzf "$CODE_TAR" >/dev/null 2>&1; then ok "backed up the installed plugin (${LIVE_VERSION:-?}, ${LIVE_BEFORE:-?}) → $CODE_TAR ($(du -h "$CODE_TAR" | cut -f1))"
  else bad "backup of the installed plugin failed (tar exit $rc)"; fi
  VAULT="$MOUNT/ucrm/data/plugins/.dishnet-sudan.vault.json"
  if [ -f "$VAULT" ]; then cp -p "$VAULT" "$BK/config-vault.json" && cmp -s "$VAULT" "$BK/config-vault.json" && ok "backed up the configuration vault → $BK/config-vault.json" || bad "backup of the configuration vault failed"
  else note "no configuration vault at the plugins directory — nothing to copy"; fi
  cp "$DEST/.deployed-commit" "$BK/deployed-commit.before" 2>/dev/null || true
  printf 'DEPLOYED_AT=%s\nFROM_COMMIT=%s\nSW_BEFORE=%s\nTB_BEFORE=%s\nPF_BEFORE=%s\n' "$TS" "$LIVE_BEFORE" "$SW_BEFORE" "$TB_BEFORE" "$PF_BEFORE" > "$STATE"
  chmod -R go-rwx "$BK"
  [ "$FAIL" = "0" ] || stop "NO-GO: the backup or a before-check did not complete"
  echo; echo "  GO — evidence recorded, backup in $BK"
  [ "$MODE" = "deploy" ] && echo "  The rollback command is printed at the end of this log, on its own."
fi

# §16.23: each changed file gets the time of this copy so PHP-FPM recompiles it.
stamp_copy() { local f rel n=0; for f in $CHANGED; do rel="${f#"$PLUGIN"/}"; [ -f "$DEST/$rel" ] && touch -c "$DEST/$rel" && n=$((n+1)); done; echo "  gave the $n installed file(s) this release changes the time of this copy, $(date -u +%H:%M:%S) UTC"; }

do_rollback() {
  local ref; ref="$(git -C "$REPO" symbolic-ref -q --short HEAD 2>/dev/null || git -C "$REPO" rev-parse HEAD)"
  echo "  the checkout is on $ref; checking out $BASELINE_COMMIT ($BASELINE_VERSION) for the documented deploy"
  git -C "$REPO" checkout -q "$BASELINE_COMMIT" || { bad "git checkout $BASELINE_COMMIT failed — nothing was deployed"; return 1; }
  bash "$REPO/scripts/deploy-hybrid.sh" 2>&1 | sed 's/^/     /'; local rc=${PIPESTATUS[0]}
  if [ "$rc" = "2" ]; then note "the container was still restarting; waiting for it"; local i; for i in 1 2 3 4 5 6 7 8 9 10 11 12; do sleep 10; if [ "$(live_commit)" = "$BASELINE_COMMIT" ]; then rc=0; break; fi; done; fi
  git -C "$REPO" checkout -q "$ref" || bad "could not return the checkout to $ref — run: cd $REPO && git checkout $ref"
  stamp_copy
  local live; live="$(live_commit)"; echo "  the container now serves ${live:-?}; the checkout is back on $ref"
  [ "$rc" = "0" ] && [ "$live" = "$BASELINE_COMMIT" ]
}

DEPLOY_STARTED=""
# ════════════════════════════════════════════════════════
if [ "$MODE" = "deploy" ]; then
  hdr "B. The documented deploy (scripts/deploy-hybrid.sh)"
  printf '  Type DEPLOY to deploy %s (%s) over live %s, anything else to stop: ' "$EXPECTED_VERSION" "$EXPECTED_PLUGIN_COMMIT" "${LIVE_BEFORE:-?}"
  read -r ANSWER </dev/tty || ANSWER=""; echo
  [ "$ANSWER" = "DEPLOY" ] || stop "not confirmed"
  DEPLOY_STARTED="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  DEPLOY_REF=""
  if [ -n "$PIN_CHECKOUT" ]; then
    DEPLOY_REF="$(git -C "$REPO" symbolic-ref -q --short HEAD 2>/dev/null || git -C "$REPO" rev-parse HEAD)"
    echo "  the checkout is on $DEPLOY_REF ($HEAD_PLUGIN); checking out $EXPECTED_PLUGIN_COMMIT ($EXPECTED_VERSION, the release commit) for the documented deploy"
    git -C "$REPO" checkout -q "$EXPECTED_PLUGIN_COMMIT" || stop "git checkout $EXPECTED_PLUGIN_COMMIT failed — nothing was deployed"
  fi
  bash "$REPO/scripts/deploy-hybrid.sh" 2>&1 | sed 's/^/     /'; RC=${PIPESTATUS[0]}
  if [ "$RC" = "2" ]; then note "the container was still restarting; waiting for it"; for i in 1 2 3 4 5 6 7 8 9 10 11 12; do sleep 10; if [ "$(live_commit)" = "$EXPECTED_PLUGIN_COMMIT" ]; then RC=0; break; fi; done; fi
  if [ -n "$DEPLOY_REF" ]; then git -C "$REPO" checkout -q "$DEPLOY_REF" && echo "  the checkout is back on $DEPLOY_REF" || bad "could not return the checkout to $DEPLOY_REF — run: cd $REPO && git checkout $DEPLOY_REF"; fi
  stamp_copy
  LIVE_AFTER="$(live_commit)"
  if [ "$RC" = "0" ] && [ "$LIVE_AFTER" = "$EXPECTED_PLUGIN_COMMIT" ]; then ok "container serves $LIVE_AFTER"
  else stop "deploy did not verify (rc=$RC, live=${LIVE_AFTER:-?}). Roll back with the separate rollback command: cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --rollback"; fi
elif [ "$MODE" = "rollback" ]; then
  hdr "B. The rollback: the documented deploy of $BASELINE_COMMIT ($BASELINE_VERSION)"
  if [ "$LIVE_BEFORE" = "$EXPECTED_PLUGIN_COMMIT" ]; then
    printf '  Type ROLLBACK to put %s (%s) back over live %s, anything else to stop: ' "$BASELINE_VERSION" "$BASELINE_COMMIT" "$LIVE_BEFORE"
    read -r ANSWER </dev/tty || ANSWER=""; echo
    [ "$ANSWER" = "ROLLBACK" ] || stop "not confirmed"
    DEPLOY_STARTED="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    if do_rollback; then ok "container serves $BASELINE_COMMIT ($BASELINE_VERSION)"; else stop "the rollback did not verify (live=$(live_commit)) — by hand: cd $REPO && git checkout $BASELINE_COMMIT && bash scripts/deploy-hybrid.sh && git checkout -"; fi
  else DEPLOY_STARTED="$(date -u -d '-10 minutes' +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || date -u +%Y-%m-%dT%H:%M:%SZ)"; fi
  LIVE_AFTER="$(live_commit)"
else
  LIVE_AFTER="$LIVE_BEFORE"; ok "live commit is $LIVE_AFTER"
  DEPLOY_STARTED="$(sed -n 's/^DEPLOYED_AT=//p' "$STATE" 2>/dev/null | head -1)"; [ -n "$DEPLOY_STARTED" ] || DEPLOY_STARTED="$(date -u -d '-10 minutes' +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || date -u +%Y-%m-%dT%H:%M:%SZ)"
fi

# ════════════════════════════════════════════════════════
hdr "V. Verification over the public address (nothing signed in; nothing written)"
# ════════════════════════════════════════════════════════
if [ -n "$PLUGIN_BASE" ]; then
  http GET "$PLUGIN_BASE?page=customer_login" -L
  if [ "$HTTP_CODE" = "000" ]; then
    case "$PLUGIN_BASE" in
      *:8443*) note "V1/V2/V5 could not probe the public address: the derived address is $PLUGIN_BASE and the server cannot reach its own :8443 public port from inside the host (curl 000 — hairpin). This says nothing about whether the pages work; the code is verified by R1–R6 below and by V4 (no fatal). To confirm the pages themselves, open $PLUGIN_BASE?page=customer_login in a browser, or re-run: bash scripts/deploy-$EXPECTED_VERSION.sh --after-only --plugin-base <an address the server can reach>" ;;
      *) note "V1/V2/V5 could not probe the public address: $PLUGIN_BASE is not reachable from the server (curl 000). This says nothing about the pages; R1–R6 and V4 verify the code. Open the sign-in page in a browser, or re-run with --plugin-base <a reachable address>" ;;
    esac
  else
    if [ "$HTTP_CODE" = "200" ] && [ "$HTTP_REDIRECTS" = "0" ]; then ok "V1 the sign-in page on the public address answers 200 with zero redirects (no loop)"
    else
      bad "V1 the sign-in page on the public address → $HTTP_CODE after $HTTP_REDIRECTS redirect(s) ${HTTP_LOCATION:+(last $HTTP_LOCATION)}"
      if [ "$MODE" = "deploy" ] && [ "${HTTP_REDIRECTS:-0}" != "0" ]; then echo; echo "  ROLLING BACK — the public address must never redirect"; do_rollback || true; stop "rolled back (serves $(live_commit)); send the log file"; fi
    fi
    http GET "$PLUGIN_BASE?page=customer_portal&view=home"
    case "$HTTP_CODE" in 302|401) ok "V1 the portal without a session still refuses ($HTTP_CODE)";; *) bad "V1 the portal without a session → $HTTP_CODE";; esac
    http GET "$PLUGIN_BASE?page=customer_login"
    if [ "$HTTP_CODE" = "200" ] && [ "$(count '+211')" = "0" ] && [ "$(count 'dishnetafrica.com')" = "0" ]; then ok "V2 the sign-in page answers 200 and carries no South Sudan contact"
    else bad "V2 sign-in page → $HTTP_CODE; '+211' ×$(count '+211') · dishnetafrica.com ×$(count 'dishnetafrica.com')"; fi
    # V5 — the photo surfaces of 5.18.66 still refuse an anonymous caller. Both reads change nothing; neither ever answers 200 to nobody.
    if [ "$MODE" != "rollback" ]; then
      http GET "$PLUGIN_BASE?page=job_photo&id=1"
      case "$HTTP_CODE" in 302|401|403) ok "V5 the photo viewer without a session refuses ($HTTP_CODE) — a photo is never served to nobody";; *) bad "V5 the photo viewer without a session → $HTTP_CODE (expected 302/401/403)";; esac
      http POST "$PLUGIN_BASE?page=api&action=job_photo_upload"
      case "$HTTP_CODE" in 401|403) ok "V5 job_photo_upload without a login answers $HTTP_CODE — the staff guard, before any handler";; *) bad "V5 job_photo_upload without a login → $HTTP_CODE (expected 401)";; esac
    fi
  fi
else note "V1/V2/V5 skipped — the plugin's public URL could not be derived (re-run with --plugin-base); the R checks below read the install directly"; fi

# V3 — the pilot switch is UNCHANGED by the deploy: whatever state the operator left it in, it stays.
SW_AFTER="$(switch_state)"
if [ -z "$SW_AFTER" ] || ! printf '%s' "$SW_AFTER" | grep -q '='; then bad "V3 could not read the pilot switch ($SW_AFTER)"
elif [ "$SW_AFTER" = "${SW_BEFORE:-}" ]; then ok "V3 the pilot switch is unchanged by the deploy (before=$SW_BEFORE, after=$SW_AFTER) — 5.18.72 changes no configuration value"
else bad "V3 the pilot switch CHANGED across the deploy (before=${SW_BEFORE:-?}, after=$SW_AFTER) — 5.18.72 must never touch it"; fi
printf '  …     the pilot reads %s (set by the operator; manage it with set_distributors.php --show/--on/--off)\n' "${SW_AFTER#pilot=}"

# V4 — no fatal of the plugin in the container log since the deploy.
if [ "$MODE" != "after" ] && [ -n "$BK" ]; then printf '  …     waiting %ss for the first requests on the code now installed\n' "$GUARD_SECONDS"; sleep "$GUARD_SECONDS"; fi
V4_LINES="$(log_since "$DEPLOY_STARTED" | grep -E 'UNCAUGHT|FATAL|PHP Fatal|PHP Parse error' | grep -F -- "$PLUGIN" || true)"
N_FATAL="$(printf '%s\n' "$V4_LINES" | grep -c . || true)"
if [ "${N_FATAL:-0}" = "0" ]; then ok "V4 no fatal or parse error of $PLUGIN in the container log since $DEPLOY_STARTED"
else bad "V4 ${N_FATAL} fatal line(s) of $PLUGIN since $DEPLOY_STARTED:"; printf '%s\n' "$V4_LINES" | cut -c1-200 | head -5 | sed 's/^/     /'; fi

if [ "$MODE" != "rollback" ]; then
# ════════════════════════════════════════════════════════
hdr "R. $EXPECTED_VERSION installed (read-only)"
# ════════════════════════════════════════════════════════
# R1 — every changed file is installed byte for byte, and the version.
R_OK=0; R_BAD=""
for f in $CHANGED; do
  rel="${f#"$PLUGIN"/}"
  a="$(git -C "$REPO" show "$EXPECTED_PLUGIN_COMMIT:$f" 2>/dev/null | sha256sum | cut -c1-64)"
  b="$(sha256sum "$DEST/$rel" 2>/dev/null | cut -c1-64)"
  if [ -n "$b" ] && [ "$a" = "$b" ]; then R_OK=$((R_OK+1)); else R_BAD="$R_BAD $rel"; fi
done
[ -z "$R_BAD" ] && ok "R1 all $R_OK files $EXPECTED_VERSION changes are installed exactly as $EXPECTED_PLUGIN_COMMIT has them ($N_ADDED new)" || bad "R1 installed files that differ:$R_BAD"
IV="$(grep -o '"version": *"5[^"]*"' "$DEST/manifest.json" | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/')"
[ "$IV" = "$EXPECTED_VERSION" ] && ok "R1 the installed manifest says $IV" || bad "R1 the installed manifest says ${IV:-?}"

# R2 — the installed tool re-reads the live switch through the installed code; the deploy left it exactly as it was.
if printf '%s' "$SW_AFTER" | grep -q '='; then ok "R2 the installed set_distributors --show reports $SW_AFTER (the operator's setting, unchanged by this deploy)"
else bad "R2 could not read the installed switch ($SW_AFTER)"; fi

# R3 — 5.18.72 adds NO migration. Regression: 084 (from 5.18.66) is still installed and its two tables exist, with their
# row counts — whatever technicians have recorded; a count is never a failure.
if [ ! -f "$DEST/migrations/084_job_photos.sql" ]; then bad "R3 migrations/084_job_photos.sql from 5.18.66 is missing on the server"
else
  TB="$(photo_tables_state)"
  case "$TB" in
    present:*) ok "R3 5.18.72 adds no migration; 084 is still installed and its two tables exist on the live plugin.sqlite3 — job_photos ${TB#present:} rows (photos:gps), untouched by this deploy" ;;
    lazy)      note "R3 5.18.72 adds no migration; 084 is installed; the two tables are not in plugin.sqlite3 yet — created on the plugin's next request" ;;
    nodb)      note "R3 5.18.72 adds no migration; 084 is installed; no plugin.sqlite3 yet at $PDD_IN" ;;
    *)         note "R3 5.18.72 adds no migration; 084 is installed; could not read the table state ($TB)" ;;
  esac
  PF="$(photo_files_state)"
  case "$PF" in
    absent)  echo "  …     uploads/job_photos does not exist in the data directory yet: no photo has been taken" ;;
    files:*) echo "  …     uploads/job_photos holds ${PF#files:} file(s) — photos technicians have taken (never touched by this script)" ;;
    *)       echo "  …     could not read uploads/job_photos ($PF)" ;;
  esac
fi

# R4 — regression: the distributor pilot is installed as before, its two webhook hooks flag-gated, and the bound channel is
# the Null one (the Evolution adapter is defined but constructed nowhere): nothing can be sent. 5.18.67 touches none of it.
R4_BAD=""
for lib in lib/DistributorRegistry.php lib/DistributorAttribution.php lib/DistributorNotifier.php lib/DistributorEvents.php lib/WhatsAppChannel.php; do
  [ -f "$DEST/$lib" ] || R4_BAD="$R4_BAD $lib:missing"
done
[ -f "$DEST/tabs/admin/distributors.php" ] || R4_BAD="$R4_BAD tabs/admin/distributors.php:missing"
grep -q 'class NullWhatsAppChannel' "$DEST/lib/WhatsAppChannel.php" 2>/dev/null || R4_BAD="$R4_BAD WhatsAppChannel:NullWhatsAppChannel"
grep -q 'new NullWhatsAppChannel' "$DEST/lib/DistributorNotifier.php" 2>/dev/null || R4_BAD="$R4_BAD notifier:Null-bound"
HOOKS="$(grep -c 'DistributorEvents::maybeNotify' "$DEST/webhook.php" 2>/dev/null || echo 0)"
[ "$HOOKS" = "2" ] || R4_BAD="$R4_BAD webhook:maybeNotify×$HOOKS(want 2)"
if grep -q 'new EvolutionWhatsAppChannel' "$DEST/webhook.php" 2>/dev/null \
   || grep -q 'new EvolutionWhatsAppChannel' "$DEST/includes/post/post_distributors.php" 2>/dev/null; then
  R4_BAD="$R4_BAD live-channel-bound"
fi
[ -f "$DEST/partner_api.php" ] && R4_BAD="$R4_BAD partner_api.php:present(not-in-this-release)"
[ -f "$DEST/lib/StaffApiCsrf.php" ] && R4_BAD="$R4_BAD StaffApiCsrf.php:present(not-in-this-release)"
DT="$(dist_tables_state)"
[ -z "$R4_BAD" ] && ok "R4 the pilot libs + the Distributors tab are installed as before; webhook.php draws the two flag-gated hooks; the bound channel is NullWhatsAppChannel and the Evolution adapter is constructed nowhere — nothing can be sent; the pilot tables read $DT; no partner-portal or CSRF file is installed" || bad "R4 the installed pilot is incomplete, binds a live channel, or carries files not in this release:$R4_BAD"

# R5 — Release A (5.18.49) → 5.18.66 installed as pinned (regression that nothing — the photo surface included — was lost).
RA_OK=0; RA_BAD=""
for f in $(git -C "$REPO" diff --no-renames --name-only --diff-filter=AM "$RELEASE_A_BASE" "$BASELINE_COMMIT" -- "$PLUGIN"); do
  rel="${f#"$PLUGIN"/}"
  git -C "$REPO" cat-file -e "$EXPECTED_PLUGIN_COMMIT:$f" 2>/dev/null || continue
  a="$(git -C "$REPO" show "$EXPECTED_PLUGIN_COMMIT:$f" 2>/dev/null | sha256sum | cut -c1-64)"
  b="$(sha256sum "$DEST/$rel" 2>/dev/null | cut -c1-64)"
  if [ -n "$b" ] && [ "$a" = "$b" ]; then RA_OK=$((RA_OK+1)); else RA_BAD="$RA_BAD $rel"; fi
done
[ -z "$RA_BAD" ] && ok "R5 all $RA_OK files from Release A through $BASELINE_VERSION are installed as $EXPECTED_PLUGIN_COMMIT has them — $EXPECTED_VERSION lost none of the prior releases" || bad "R5 files from Release A→$BASELINE_VERSION differ on the server:$RA_BAD"

# R6 — the photo surface of 5.18.66 and the card layout of 5.18.67 are still installed (regression), and 5.18.68's
# staff-cash chain is in place: the auto-link carries every non-SSP amount, the position service reads the book's base,
# the Cashbook page shows CASH IN HAND, the Uganda strip gate exists, the backfill tool is installed. R1 proves each file
# byte-for-byte; this is the readable confirmation.
R6_BAD=""
for f in lib/JobPhotos.php includes/api/api_job_photos.php migrations/084_job_photos.sql "$BACKFILL_TOOL" "$RECORDS_TOOL" "$CIH_TOOL"; do [ -f "$DEST/$f" ] || R6_BAD="$R6_BAD $f:missing"; done
grep -qF "require __DIR__ . '/api/api_job_photos.php'" "$DEST/includes/api_handlers.php" 2>/dev/null || R6_BAD="$R6_BAD api_handlers:no-require"
grep -qF 'var UG_PHOTOS = <?= $_sjUganda' "$DEST/tabs/support/scheduling.php" 2>/dev/null || R6_BAD="$R6_BAD job-page:no-uganda-gate"
grep -qF -- "$LAYOUT_MARK" "$DEST/tabs/support/scheduling.php" 2>/dev/null || R6_BAD="$R6_BAD job-page:old-photo-layout"
N_AMT="$(grep -cF "!== 'SSP' ? \$cbAmount : 0" "$DEST/includes/post/post_cashbook.php" 2>/dev/null || echo 0)"
[ "$N_AMT" = "2" ] || R6_BAD="$R6_BAD auto-link:amount-rule×$N_AMT(want 2)"
grep -qF "dn_book_base(null)" "$DEST/lib/StaffCashPositionService.php" 2>/dev/null || R6_BAD="$R6_BAD position-service:literal-usd"
grep -qF "if (\$currency === '' || \$currency === 'SSP') \$currency = 'USD';" "$DEST/lib/StaffLedgerWriter.php" 2>/dev/null || R6_BAD="$R6_BAD ledger-writer:literal-usd"
grep -qF "public function cashInHand(string \$currency, string \$project = ''): float" "$DEST/lib/CashbookService.php" 2>/dev/null || R6_BAD="$R6_BAD service:no-cashInHand"
grep -qF 'CASH IN HAND</div>' "$DEST/tabs/accounts/cashbook.php" 2>/dev/null || R6_BAD="$R6_BAD cashbook-page:no-cash-in-hand-card"
grep -qF '$cb->currencyPositions()' "$DEST/tabs/accounts/cashbook.php" 2>/dev/null && R6_BAD="$R6_BAD cashbook-page:position-cards-still-drawn"
grep -qF '_navTenantUganda' "$DEST/includes/navigation.php" 2>/dev/null || R6_BAD="$R6_BAD strip:no-uganda-gate"
grep -qF "\$uL=array_values(array_filter(\$sc_ledger,fn(\$r)=>\$r['cur']===\$scBaseCode));" "$DEST/tabs/accounts/staff_cashbooks.php" 2>/dev/null || R6_BAD="$R6_BAD staff-cashbooks:literal-usd"
grep -qF 'CASH IN HAND: ' "$DEST/cron/cashbook_summary.php" 2>/dev/null || R6_BAD="$R6_BAD evening-summary:position-model"
# 5.18.69: the Manual Entry stamp, the base-bag label, the export's tab→currency rule
grep -qF "'currency'        => \$manCur," "$DEST/tabs/accounts/staff_cashbooks.php" 2>/dev/null || R6_BAD="$R6_BAD manual-entry:stamps-literal-usd"
grep -qF "\$scBaseCode.' Received'" "$DEST/tabs/accounts/staff_cashbooks.php" 2>/dev/null || R6_BAD="$R6_BAD staff-cashbooks:usd-received-label"
grep -qF "=== 'ssp' ? 'SSP' : dn_book_base(\$config ?? null);" "$DEST/includes/routes.php" 2>/dev/null || R6_BAD="$R6_BAD export:literal-usd-tab"
grep -qF "'cat'=>\$_mcBase.' Received'," "$DEST/tabs/sales/my_account.php" 2>/dev/null || R6_BAD="$R6_BAD my-cash:usd-received-label"
# 5.18.70: the Field Register tells its forms the base and submits it; its export reads the base; the records tool is 5.18.70's
grep -qF 'var _fr3Base = <?= json_encode(dn_book_base($config)) ?>;' "$DEST/tabs/sales/wallet.php" 2>/dev/null || R6_BAD="$R6_BAD field-register:no-base-token"
[ "$(grep -cF "fr3fInCurrency').value  = _fr3Base;" "$DEST/tabs/sales/wallet.php" 2>/dev/null)" = "2" ] || R6_BAD="$R6_BAD field-register:literal-usd-submit"
grep -qF "fr3fInCurrency').value  = 'USD';" "$DEST/tabs/sales/wallet.php" 2>/dev/null && R6_BAD="$R6_BAD field-register:literal-usd-submit-present"
grep -qF '$frBase3 = dn_book_base($config ?? null);' "$DEST/includes/routes.php" 2>/dev/null || R6_BAD="$R6_BAD export:literal-usd-collection"
grep -qF '== staff records by currency (5.18.70)' "$DEST/$RECORDS_TOOL" 2>/dev/null || R6_BAD="$R6_BAD records-tool:not-5.18.70"
# 5.18.71: the dashboard's hero is cash in hand per currency, the account position is not drawn, money held by staff is listed, the tool is installed
grep -qF 'Cash in hand — per currency' "$DEST/tabs/accounts/accounts_dashboard.php" 2>/dev/null || R6_BAD="$R6_BAD dashboard:no-cash-in-hand-hero"
grep -qF '$cbDash->currencyPositions()' "$DEST/tabs/accounts/accounts_dashboard.php" 2>/dev/null && R6_BAD="$R6_BAD dashboard:account-position-still-drawn"
grep -qF '$_held = $_adJsonSvc->getUSDBalance($_hid);' "$DEST/tabs/accounts/accounts_dashboard.php" 2>/dev/null || R6_BAD="$R6_BAD dashboard:no-held-by-staff"
grep -qF '== cash in hand (5.18.71)' "$DEST/$CIH_TOOL" 2>/dev/null || R6_BAD="$R6_BAD cash-in-hand-tool:missing-or-wrong"
# 5.18.72: on a book without SSP the Staff Cashbooks tiles read "received · Advances & collections" and "Still to account for"; South Sudan's "collected" tile is still in the file
grep -qF '<div class="cb3-stat-lbl"><?=$scBaseCode?> received</div>' "$DEST/tabs/accounts/staff_cashbooks.php" 2>/dev/null || R6_BAD="$R6_BAD staff-cashbooks:no-received-tile"
grep -qF "(\$scSSP?'Needs handover':'Still to account for')" "$DEST/tabs/accounts/staff_cashbooks.php" 2>/dev/null || R6_BAD="$R6_BAD staff-cashbooks:no-account-for-line"
grep -qF '<div class="cb3-stat-sub">Advances &amp; collections</div>' "$DEST/tabs/accounts/staff_cashbooks.php" 2>/dev/null || R6_BAD="$R6_BAD staff-cashbooks:no-advances-subline"
grep -qF "<div class=\"cb3-stat-lbl\"><?=\$curTab==='ssp'?'SSP':\$scBaseCode?> collected</div>" "$DEST/tabs/accounts/staff_cashbooks.php" 2>/dev/null || R6_BAD="$R6_BAD staff-cashbooks:south-sudan-tile-lost"
[ -z "$R6_BAD" ] && ok "R6 the photo surface, the 5.18.67 card, the 5.18.68 staff-cash chain and the 5.18.69, 5.18.70 and 5.18.71 fixes are installed as before, and 5.18.72's wording is in place: on a book without SSP the Staff Cashbooks tiles read received · Advances & collections and Still to account for, and South Sudan's collected / Needs handover branch is still in the file" || bad "R6 incomplete:$R6_BAD"

# R7 — cash in hand on the live book, READ-ONLY: tools/cash_in_hand.php (5.18.71's, unchanged by this release) prints the
# ledger's running balance per currency and the base per project — the figures the landing hero shows. Nothing is written
# (asserted in the rehearsal).
R7_OUT="$(docker exec "$CONTAINER" php "$IN_CONTAINER/$CIH_TOOL" 2>&1)"; R7_RC=$?
if [ "$R7_RC" = "0" ] && printf '%s' "$R7_OUT" | grep -qF 'READ-ONLY — nothing was changed'; then
  ok "R7 cash in hand on the live book, read-only — what the landing hero shows: $(printf '%s' "$R7_OUT" | grep -E 'CASH IN HAND' | tr -s ' ' | sed 's/^ //' | paste -sd '|' - | sed 's/|/ · /g')"
  printf '%s\n' "$R7_OUT" | grep -E 'CASH IN HAND|Fiber & Starlink|DishNet 4G|BlueCARD' | sed 's/^/     /'
else
  bad "R7 the cash-in-hand tool failed (exit $R7_RC): $(printf '%s' "$R7_OUT" | tail -3 | tr '\n' ' ' | cut -c1-300)"
fi
TB_AFTER="$(photo_tables_state)"; PF_AFTER="$(photo_files_state)"
[ "$TB_AFTER" = "$TB_BEFORE" ] && [ "$PF_AFTER" = "$PF_BEFORE" ] && ok "R7 the photo tables and files read exactly as before the deploy ($TB_AFTER, $PF_AFTER) — the tool touched no data" || bad "R7 the photo tables/files changed across the deploy ($TB_BEFORE → $TB_AFTER, $PF_BEFORE → $PF_AFTER)"

elif [ "$MODE" = "rollback" ]; then
hdr "RB. $BASELINE_VERSION, back in place (read-only)"
IV="$(grep -o '"version": *"5[^"]*"' "$DEST/manifest.json" | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/')"
[ "$IV" = "$BASELINE_VERSION" ] && ok "RB the installed manifest says $IV" || bad "RB the installed manifest says ${IV:-?}, expected $BASELINE_VERSION"
grep -qF "'Still to account for'" "$DEST/tabs/accounts/staff_cashbooks.php" 2>/dev/null && bad "RB the installed Staff Cashbooks page still carries 5.18.72's wording" || ok "RB the installed Staff Cashbooks page is $BASELINE_VERSION's again (collected / Needs handover on every book, as before this release)"
grep -qF 'Cash in hand — per currency' "$DEST/tabs/accounts/accounts_dashboard.php" 2>/dev/null && grep -qF '== cash in hand (5.18.71)' "$DEST/$CIH_TOOL" 2>/dev/null && ok "RB the 5.18.71 landing hero and cash-in-hand tool are still there" || bad "RB the installed plugin lost 5.18.71 code"
grep -qF 'var _fr3Base' "$DEST/tabs/sales/wallet.php" 2>/dev/null && grep -qF '(5.18.70)' "$DEST/$RECORDS_TOOL" 2>/dev/null && grep -qF "'currency'        => \$manCur," "$DEST/tabs/accounts/staff_cashbooks.php" 2>/dev/null && ok "RB the 5.18.70 Field Register fix and records tool and the 5.18.69 Manual Entry stamp are still there" || bad "RB the installed plugin lost 5.18.69/5.18.70 code"
grep -qF 'CASH IN HAND</div>' "$DEST/tabs/accounts/cashbook.php" 2>/dev/null && grep -qF "dn_book_base(null)" "$DEST/lib/StaffCashPositionService.php" 2>/dev/null && ok "RB the 5.18.68 staff-cash chain and card are still there" || bad "RB the installed plugin lost 5.18.68 code"
grep -qF 'var UG_PHOTOS' "$DEST/tabs/support/scheduling.php" 2>/dev/null && grep -qF -- "$LAYOUT_MARK" "$DEST/tabs/support/scheduling.php" 2>/dev/null && ok "RB the 5.18.66 photo surface and the 5.18.67 card are still there" || bad "RB the installed job page lost 5.18.66/5.18.67 code"
note "RB the code is $BASELINE_VERSION again. The rollback restores code only; this release wrote no record"
fi

# ════════════════════════════════════════════════════════
hdr "F. Summary"
# ════════════════════════════════════════════════════════
LIVE_NOW="$(live_commit)"
case "$MODE" in
  rollback) echo "  serving           ${LIVE_NOW:-?}  (plugin $BASELINE_VERSION)"; echo "  deploy again      cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh" ;;
  *)        echo "  deployed commit   ${LIVE_NOW:-?}  (plugin $EXPECTED_VERSION, the release commit on $RELEASE_BRANCH)" ;;
esac
[ -n "$BK" ] && echo "  backup            $BK"
if [ "$MODE" != "rollback" ]; then
  echo "  what changed      Uganda: the Staff Cashbooks tiles (the accountant's view of one staff member) say what the money is — UGX RECEIVED with 'Advances & collections' beneath it, and 'Still to account for' under Cash with staff — instead of 'UGX collected' and 'Needs handover'. Wording only: every figure is unchanged. Nothing else changed"
  echo "  what did not      South Sudan (its Staff Cashbooks page is the same bytes as before — collected / Needs handover — proved on a South Sudan sandbox); every configuration value (V3/R2); uCRM; every cash record (nothing here writes one; R7 only reads); the photo tables and files (R3); the undeployed partner-portal and CSRF work on the branch (not in this release, R4)"
  echo "  migrations        none — 5.18.72 adds no migration; 084's two tables are unchanged (R3)"
  echo "  try it            Staff Cashbooks, pick the technician: the first tile reads UGX RECEIVED with 'Advances & collections' beneath it, and Cash with staff reads 'Still to account for'. The figures are exactly as before (UGX 339,672.00 held)"
  echo "  later             cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --after-only"
fi
echo "  checks            $PASS ok, $FAIL failed, $NOTE notes"
if [ "$FAIL" = "0" ]; then echo; echo "  $EXPECTED_VERSION ($MODE): PASSED. Send this LOG FILE back (not a copy of the terminal)."
else echo; echo "  $EXPECTED_VERSION ($MODE): $FAIL FAILED — send the log file; do not roll back on your own unless staff or customers are affected."; fi
if [ "$MODE" != "rollback" ]; then
  echo
  echo "  Only if it is ever needed — its own command, never pasted together with the deploy (root docs/44 §16.9) —"
  echo "  the rollback to $BASELINE_VERSION ($BASELINE_COMMIT) asks you to type ROLLBACK before it changes anything:"
  echo "      cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --rollback"
  echo "  or by hand, if this script cannot run:"
  echo "      cd $REPO && git checkout $BASELINE_COMMIT && bash scripts/deploy-hybrid.sh && git checkout -"
fi
[ "$FAIL" = "0" ]
}

main "$@"; exit $?
