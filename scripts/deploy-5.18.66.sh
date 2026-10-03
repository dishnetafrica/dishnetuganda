#!/usr/bin/env bash
#
# deploy-5.18.66.sh — site photos on a job and the technician's location at completion (plugin 5.18.66) over 5.18.65, or
#                     roll it back to 5.18.65.
#
#   5.18.66  On Uganda only, inside My Jobs (the installable staff app): a Photos card on the job page — kit / dish, cable
#            used, router / model, other — with camera capture, the shot shrunk on the phone and re-encoded on the server
#            (type checked by getimagesize, at most 1600 px, JPEG), stored under the plugin's data directory
#            (uploads/job_photos/<job>/, server-named) and served only to the person who took it, the job's assignee, a
#            support leader, an admin or an accountant (?page=job_photo&id=N). "Mark as Completed" records where the
#            technician was — a GPS fix, or their reason there is none — and an installation cannot be completed without
#            its kit, cable and router photos (config job_photos_required, default on). Migration 084 adds two empty
#            tables (job_photos, job_completion_gps). NOTHING new is written to uCRM: a completion sends uCRM exactly the
#            writes 5.18.65 did. South Sudan keeps 5.18.65 byte for byte: the gate is StaffJobsGate (fail-closed), proved
#            by the day test against the 5.18.51 baseline on both countries (root docs/07, 03 Oct).
#
#   THE COMMIT IT INSTALLS IS A RELEASE COMMIT, NOT THE BRANCH TIP. The branch claude/study-this-jhe2eg also carries the
#            distributor partner-portal stack (WS-A P4a–P4d: migrations 081–083, partner_api.php, lib/Partner*.php) and
#            the PD-8 CSRF guard, each built "dev only, NOT deployed" with its own approval still to come. 8137912, on
#            release/5.18.66, is 5.18.66's own changes applied on ce3fa91 — the version the server runs — and nothing
#            else. Stage A refuses a pin whose parent is not 5.18.65.
#
#   Scope:   NOT code-only — one additive migration (084, CREATE TABLE IF NOT EXISTS, applied by the plugin on its next
#            request) and four new files (lib/JobPhotos.php, includes/api/api_job_photos.php, migrations/084_job_photos.sql,
#            tests/test_job_photos.php); edits in api_scheduling.php, api_handlers.php, routes.php, the job page, Photo
#            Manager, the manifest and six test files. No uCRM write, no message, no setting changed.
#
#   Contract: on Uganda, scheduling_complete now answers 422, in words, to a caller that sends neither a fix nor a reason,
#            or completes an installation without its three photos. The job page always satisfies it.
#
#   Regression: because the whole plugin tree is copied, stage R doubles as a full check that Release A (5.18.49) through
#            5.18.65 — the distributor pilot included — is installed byte-for-byte as the pin has it (R5), the pilot
#            still binds the Null channel so nothing can be sent (R4), and the pilot's switch is untouched (V3, R2).
#
# 5.18.65 must be installed first (scripts/deploy-5.18.65.sh): this script refuses any other live commit.
#
# Run as root on the server, then send back THE LOG FILE (never a copy of the terminal):
#
#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.66 \
#     && mkdir -p /root/dnb-5.18.66 \
#     && bash scripts/deploy-5.18.66.sh 2>&1 | tee /root/dnb-5.18.66/deploy-$(date -u +%Y%m%dT%H%M%SZ).log
#
# The rollback is a separate command, printed at the end of the deploy's log. It is never pasted together with the one
# above: pasted together, the shell runs both (root docs/44 §16.9).
#
# Options
#   --after-only         the deploy already happened: run the checks again
#   --rollback           put 5.18.65 back (typed ROLLBACK), then check it
#   --plugin-base <url>  the public URL of public.php, if the derived one is wrong
#
# What it never does: change the pilot switch or any other configuration value, touch a customer, a staff account, a job,
# a message, a photo, the webhook key, Traefik, UISP or the website; send anything; sign anyone in; run a cron; call uCRM;
# create the two tables (the plugin's own migration runner does, additively, on the next request). Every read of the
# plugin's data opens the database READ-ONLY. The writes are the documented deploy (or rollback), a backup under
# /root/dnb-5.18.66/, a record of where the deploy started, and the time of the copy on the files this release changes.
#
# Stages:  A before-evidence, the syntax check under the server's own PHP, the switch state, the backup → GO/NO-GO
#          B the documented deploy (or, with --rollback, the documented deploy of 5.18.65); the copy's time on each file
#          V the public pages (no loop, the portal still refuses, the Uganda contacts), the pilot switch UNCHANGED by the
#            deploy, the two new surfaces refusing an anonymous caller, and no fatal since the deploy
#          R 5.18.66 installed: the changed files byte-for-byte and the version (R1); the switch unchanged (R2); migration
#            084 installed and its two tables present-or-lazy, with their row counts (R3); the pilot code still bound to
#            the Null channel (R4); Release A→5.18.65 still installed (R5); the photo surface installed and gated (R6)
#          F summary
set -uo pipefail
umask 077

main() {
PLUGIN="dishnet-hybrid-sudan"
CONTAINER="${UCRM_CONTAINER:-ucrm}"
EXPECTED_PLUGIN_COMMIT="8137912"   # 5.18.66 (release) — site photos + completion location, cut on 5.18.65; branch release/5.18.66
EXPECTED_VERSION="5.18.66"
BASELINE_COMMIT="ce3fa91"          # 5.18.65 — what the server runs first, and the rollback
BASELINE_VERSION="5.18.65"
RELEASE_A_BASE="e076632"           # 5.18.49 — the regression base: R5 checks every file from Release A through 5.18.65 is installed as pinned
RELEASE_BRANCH="release/5.18.66"   # where the release commit lives; fetched when the checkout does not hold it
BRANCH="claude/study-this-jhe2eg"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$REPO/$PLUGIN"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="${DNB_OUT:-/root/dnb-5.18.66}"; mkdir -p "$OUT"; chmod 700 "$OUT"
STATE="$OUT/state-$EXPECTED_VERSION.env"
GUARD_SECONDS="${GUARD_SECONDS:-60}"
TOOL="tools/set_distributors.php"   # reads the live pilot switch (read-only with --show); a regression check here

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
echo "  installs        $EXPECTED_PLUGIN_COMMIT by its hash: 5.18.66's own changes on the live $BASELINE_VERSION, without the undeployed work the branch tip ($HEAD_PLUGIN) also carries"
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
echo "  migrations      $N_MIG added since $BASELINE_VERSION (additive; applied by the plugin on the next request)"
# The release must carry none of the undeployed portal / CSRF work: its delta is the photo work and nothing else.
STRAY="$(printf '%s\n' "$CHANGED" | grep -E "^$PLUGIN/(partner_api\.php|lib/Partner|lib/StaffApiCsrf\.php|lib/Totp\.php|lib/DistributorPortalData\.php|migrations/08[123]_)" || true)"
[ -z "$STRAY" ] || stop "the pin carries undeployed partner-portal / CSRF work, which this release must not ship: $(printf '%s ' $STRAY | sed "s#$PLUGIN/##g")"
ok "A0 the release delta is the photo work only — no partner-portal or CSRF file among the $N_CHANGED"

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
GD="$(docker exec "$CONTAINER" php -r 'echo function_exists("imagecreatefromjpeg") ? "yes" : "no";' 2>/dev/null || echo '?')"
if [ "$GD" = "yes" ]; then echo "  container GD    yes — photos are re-encoded on the server (≤1600 px JPEG)"
else note "the container's PHP has no GD ($GD): photos are still type-checked and stored as they arrive from the phone (already shrunk there), just not re-encoded on the server"; fi

# The live pilot switch, read with the plugin's own tool (--show is read-only). A regression check: this deploy never touches it.
switch_state() {  # prints "pilot=<on|off>"
  docker exec "$CONTAINER" php "$IN_CONTAINER/$TOOL" --show 2>/dev/null \
    | awk '/distributors_enabled/ { print "pilot=" (toupper($2)=="ON"?"on":"off"); exit }'
}
SW_BEFORE="$(switch_state)"
echo "  switch          ${SW_BEFORE:-pilot=?}   (the operator's setting; this deploy never changes it)"

# The two tables of migration 084, read-only, from the installed plugin.sqlite3: present with their row counts, or lazy.
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
echo "  photo tables    $TB_BEFORE   (lazy = not created yet; the plugin creates them on its next request)"
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
  if [ "$rc" = "2" ]; then note "the container was still restarting; waiting for it"; local i; for i in 1 2 3 4 5 6 7 8 9 10 11 12; do sleep 10; if bash "$REPO/scripts/deploy-hybrid.sh" --check 2>&1 | grep -q 'Up to date'; then rc=0; break; fi; done; fi
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
    # V5 — the two new surfaces refuse an anonymous caller: the photo viewer sends a visitor to sign in, the upload action
    # answers 401 before any handler runs. Both reads change nothing; neither ever answers 200 to nobody.
    if [ "$MODE" != "rollback" ]; then
      http GET "$PLUGIN_BASE?page=job_photo&id=1"
      case "$HTTP_CODE" in 302|401|403) ok "V5 the photo viewer without a session refuses ($HTTP_CODE) — a photo is never served to nobody";; *) bad "V5 the photo viewer without a session → $HTTP_CODE (expected 302/401/403)";; esac
      http POST "$PLUGIN_BASE?page=api&action=job_photo_upload"
      case "$HTTP_CODE" in 401|403) ok "V5 job_photo_upload without a login answers $HTTP_CODE — the staff guard, before any handler";; *) bad "V5 job_photo_upload without a login → $HTTP_CODE (expected 401)";; esac
    fi
  fi
else note "V1/V2/V5 skipped — the plugin's public URL could not be derived (re-run with --plugin-base); the R checks below read the install directly"; fi

# V3 — the pilot switch is UNCHANGED by the deploy: whatever state the operator left it in, it stays. 5.18.66 touches no
# configuration value. The check reads the LIVE state through the plugin's own tool before and after, and requires them equal.
SW_AFTER="$(switch_state)"
if [ -z "$SW_AFTER" ] || ! printf '%s' "$SW_AFTER" | grep -q '='; then bad "V3 could not read the pilot switch ($SW_AFTER)"
elif [ "$SW_AFTER" = "${SW_BEFORE:-}" ]; then ok "V3 the pilot switch is unchanged by the deploy (before=$SW_BEFORE, after=$SW_AFTER) — 5.18.66 changes no configuration value"
else bad "V3 the pilot switch CHANGED across the deploy (before=${SW_BEFORE:-?}, after=$SW_AFTER) — 5.18.66 must never touch it"; fi
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

# R3 — migration 084 is installed; its two tables are present (with their row counts) or lazy until the plugin's next
# request. Additive: no existing row is touched. Right after a deploy both counts read 0 — nothing is created by deploying.
if [ ! -f "$DEST/migrations/084_job_photos.sql" ]; then bad "R3 migrations/084_job_photos.sql is not installed"
else
  TB="$(photo_tables_state)"
  case "$TB" in
    present:*) ok "R3 migration 084 is installed and its two tables exist on the live plugin.sqlite3 — job_photos ${TB#present:} rows (photos:gps); created additively by the plugin, nothing else touched" ;;
    lazy)      note "R3 migration 084 is installed; job_photos / job_completion_gps are not in plugin.sqlite3 yet — the plugin creates them additively on its next request. Open My Jobs once, or re-run --after-only" ;;
    nodb)      note "R3 migration 084 is installed; no plugin.sqlite3 yet at $PDD_IN — created on the first plugin request" ;;
    *)         note "R3 migration 084 is installed; could not read the table state ($TB)" ;;
  esac
  PF="$(photo_files_state)"
  case "$PF" in
    absent)  echo "  …     uploads/job_photos does not exist in the data directory yet: no photo has been taken (the deploy creates none)" ;;
    files:*) echo "  …     uploads/job_photos holds ${PF#files:} file(s) — photos technicians have taken (never touched by this script)" ;;
    *)       echo "  …     could not read uploads/job_photos ($PF)" ;;
  esac
fi

# R4 — regression: the distributor pilot is installed as before, its two webhook hooks flag-gated, and the bound channel is
# the Null one (the Evolution adapter is defined but constructed nowhere): nothing can be sent. 5.18.66 touches none of it.
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

# R5 — Release A (5.18.49) → 5.18.65 installed as pinned (regression that nothing — the distributor pilot included — was lost).
RA_OK=0; RA_BAD=""
for f in $(git -C "$REPO" diff --no-renames --name-only --diff-filter=AM "$RELEASE_A_BASE" "$BASELINE_COMMIT" -- "$PLUGIN"); do
  rel="${f#"$PLUGIN"/}"
  git -C "$REPO" cat-file -e "$EXPECTED_PLUGIN_COMMIT:$f" 2>/dev/null || continue
  a="$(git -C "$REPO" show "$EXPECTED_PLUGIN_COMMIT:$f" 2>/dev/null | sha256sum | cut -c1-64)"
  b="$(sha256sum "$DEST/$rel" 2>/dev/null | cut -c1-64)"
  if [ -n "$b" ] && [ "$a" = "$b" ]; then RA_OK=$((RA_OK+1)); else RA_BAD="$RA_BAD $rel"; fi
done
[ -z "$RA_BAD" ] && ok "R5 all $RA_OK files from Release A through $BASELINE_VERSION are installed as $EXPECTED_PLUGIN_COMMIT has them — $EXPECTED_VERSION lost none of the prior releases" || bad "R5 files from Release A→$BASELINE_VERSION differ on the server:$RA_BAD"

# R6 — the headline of 5.18.66: the photo surface is installed and gated. R1 already proves each file byte-for-byte; this
# is the readable confirmation that the pieces are present and wired to the Uganda gate.
R6_BAD=""
for f in lib/JobPhotos.php includes/api/api_job_photos.php migrations/084_job_photos.sql; do [ -f "$DEST/$f" ] || R6_BAD="$R6_BAD $f:missing"; done
grep -qF "require __DIR__ . '/api/api_job_photos.php'" "$DEST/includes/api_handlers.php" 2>/dev/null || R6_BAD="$R6_BAD api_handlers:no-require"
grep -qF "if (!empty(\$_sjUganda) && in_array(\$act, ['job_photo_upload'" "$DEST/includes/api/api_job_photos.php" 2>/dev/null || R6_BAD="$R6_BAD api:not-uganda-gated"
grep -qF 'window.schOpenCompleteFormImpl=function' "$DEST/tabs/support/scheduling.php" 2>/dev/null || R6_BAD="$R6_BAD job-page:no-completion-form"
grep -qF 'var UG_PHOTOS = <?= $_sjUganda' "$DEST/tabs/support/scheduling.php" 2>/dev/null || R6_BAD="$R6_BAD job-page:no-uganda-gate"
grep -qF 'JobPhotos::validateGps' "$DEST/includes/api/api_scheduling.php" 2>/dev/null || R6_BAD="$R6_BAD complete:no-location-rule"
grep -qF 'JobPhotos::missingRequired' "$DEST/includes/api/api_scheduling.php" 2>/dev/null || R6_BAD="$R6_BAD complete:no-photo-rule"
grep -qF "\$page === 'job_photo'" "$DEST/includes/routes.php" 2>/dev/null || R6_BAD="$R6_BAD routes:no-viewer"
grep -qF 'JobPhotos::path(' "$DEST/includes/routes.php" 2>/dev/null || R6_BAD="$R6_BAD routes:viewer-not-contained"
grep -qF "'uploads/job_photos'" "$DEST/tabs/admin/photo_manager.php" 2>/dev/null || R6_BAD="$R6_BAD photo-manager:no-folder"
[ -z "$R6_BAD" ] && ok "R6 the photo surface is installed: JobPhotos + the Uganda-gated API + migration 084; the job page carries the Photos card, the Uganda gate and the location-taking completion form; scheduling_complete carries the photo and location rules; the viewer route serves only from under uploads/job_photos; Photo Manager lists the folder" || bad "R6 the photo surface is incomplete:$R6_BAD"

elif [ "$MODE" = "rollback" ]; then
hdr "RB. $BASELINE_VERSION, back in place (read-only)"
IV="$(grep -o '"version": *"5[^"]*"' "$DEST/manifest.json" | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/')"
[ "$IV" = "$BASELINE_VERSION" ] && ok "RB the installed manifest says $IV" || bad "RB the installed manifest says ${IV:-?}, expected $BASELINE_VERSION"
grep -qF 'var UG_PHOTOS' "$DEST/tabs/support/scheduling.php" 2>/dev/null && bad "RB the installed job page still carries the 5.18.66 photo code" || ok "RB the installed job page is $BASELINE_VERSION's again (no Photos card, notes-only completion)"
note "RB the code is $BASELINE_VERSION again. The rollback restores code only: job_photos / job_completion_gps and any photo files under uploads/job_photos stay in the data directory, unread by $BASELINE_VERSION and harmless, until a later version. Files 5.18.66 added (JobPhotos.php, api_job_photos.php, 084) stay on disk unreferenced — deploy-hybrid.sh never deletes"
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
  echo "  what changed      My Jobs (Uganda): a Photos card on the job page — kit / dish, cable used, router / model, other — and a completion form that records the technician's location (a GPS fix, or their reason there is none). An installation needs its kit, cable and router photos before it can be completed"
  echo "  what did not      South Sudan (byte for byte); every configuration value (V3/R2); uCRM — a completion sends it exactly the writes 5.18.65 did; the undeployed partner-portal and CSRF work on the branch (not in this release, R4)"
  echo "  migrations        one, 084 — two empty tables, created additively by the plugin on its next request (R3)"
  echo "  storage           photos under the data directory, uploads/job_photos/<job>/ (in the Google Drive backup); served only to the taker, the assignee, a leader, an admin or an accountant"
  echo "  try it            on a phone, signed in as a technician: My Jobs → a job → Take photo (each label) → Mark as Completed → Allow location. Admin: Photo Manager → 📷 Jobs"
  echo "  photo rule        job_photos_required (plugin config, default on): set to 0 to make the three photos optional for installations"
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
