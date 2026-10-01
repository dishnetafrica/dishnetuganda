#!/usr/bin/env bash
#
# deploy-5.18.59.sh — deploy plugin 5.18.59 over 5.18.58, or roll it back to 5.18.58.
#
#   5.18.59  "Become a DishNet Distributor" — the public website recruitment page's intake, captured in the plugin.
#            The website page dishnet-web-uganda/site/become-a-distributor.html POSTs an expression of interest to a new
#            PUBLIC endpoint, public.php?page=distributor_apply (reached BEFORE the login gate, modelled on web_chat.php /
#            the price feed): CORS from the shared site-origin allow-list (dishnetuganda.com + www, never '*'), an OPTIONS
#            preflight, POST-only, a honeypot, a minimum fill-time, a per-IP rate limit and a 20 KB payload cap. The body
#            is validated and sanitised SERVER-SIDE by lib/DistributorApplicationService::normalise() — the partner model
#            is whitelisted, labels are derived server-side (never trusted from the browser), service/activity ids are
#            whitelisted, control characters are stripped and lengths capped — then stored in ONE new table,
#            dist_partner_applications (migration 077, additive, idempotent). DishNet staff review the applications in a
#            new admin-only tab (tabs/admin/partner_applications.php, read-only list + detail). A row is an application,
#            NOT an approval: it creates NO uCRM client, partner, service or account, and grants NO portal access.
#            Appointing a partner stays a separate DishNet staff action (root docs/47, docs/48).
#
#   Scope:   additive. 5.18.59 adds five files (distributor_apply.php, lib/DistributorApplicationService.php,
#            migrations/077_distributor_applications.sql, tabs/admin/partner_applications.php,
#            tests/test_distributor_apply.php) and edits two (public.php — the route + the tab registration — and
#            manifest.json — the version). It changes no existing table, no existing row, and no customer-facing screen.
#            The website half of this release ships SEPARATELY: the HTML reaches branch `main` and the operator rebuilds
#            `web-uganda` on EasyPanel (dishnet-web-uganda/README-DEPLOY.md). The site's WhatsApp fallback means the page
#            is safe to publish even before this endpoint is live.
#
#   Migration: UNLIKE 5.18.58, this release carries a migration (077). SqliteStore::create() applies it at the FIRST
#            request the new code serves; stage V makes that request (the endpoint probes bootstrap the store), and stage
#            R then confirms 077 is recorded and dist_partner_applications exists. The migration is a single
#            CREATE TABLE IF NOT EXISTS plus two CREATE INDEX IF NOT EXISTS — it adds an empty table and nothing else.
#
#   Regression: because the whole plugin tree is copied, stage R doubles as a full check that Release A (5.18.49) through
#            5.18.58 are installed byte-for-byte as the pin has them — this release must lose none of it.
#
# 5.18.58 must be installed first (scripts/deploy-5.18.58.sh): this script refuses any other live commit.
#
# Run as root on the server, then send back THE LOG FILE (never a copy of the terminal):
#
#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg \
#     && mkdir -p /root/dnb-5.18.59 \
#     && bash scripts/deploy-5.18.59.sh 2>&1 | tee /root/dnb-5.18.59/deploy-$(date -u +%Y%m%dT%H%M%SZ).log
#
# The rollback is a separate command, printed at the end of the deploy's log. It is never pasted together with the one
# above: pasted together, the shell runs both (root docs/44 §16.9).
#
# Options
#   --after-only         the deploy already happened: run the checks again, counting from the deploy's own record
#   --rollback           put 5.18.58 back (typed ROLLBACK), then check it
#   --plugin-base <url>  the public URL of public.php, if the derived one is wrong
#
# What it never does: touch a configuration value, a customer, a staff account, a job, a message, a distributor
# application, the webhook key, Traefik, UISP or the website; switch a notification on or off; send anything; sign anyone
# in; run a cron; call uCRM. Every read of the plugin's data opens the database READ-ONLY as the database's owner. The
# writes are the documented deploy (or rollback), a backup under /root/dnb-5.18.59/, a record of where the deploy started
# (state-5.18.59.env beside the logs), and the time of the copy on the files this release changes. The endpoint probes in
# stage V are all refused BEFORE the store (a wrong method, a filled honeypot, an impossibly fast submit, an empty
# payload), so not one of them can create an application row — stage R proves the table gained nothing during this run.
#
# Stages:  A before-evidence, the syntax check under the server's own PHP, the data snapshot, the backup → GO/NO-GO
#          B the documented deploy (or, with --rollback, the documented deploy of 5.18.58); the copy's time on each file
#          V the public pages (no loop, the portal still refuses, the Uganda contacts), the distributor_apply endpoint's
#            CORS and anti-abuse guards (no row created), and no fatal since the deploy
#          R 5.18.59 installed: the changed files byte-for-byte and the version (R1); migration 077 applied and
#            dist_partner_applications present (R2); the admin tab registered (R3); no application row created by the
#            probes (R4); the endpoint and the service carry no uCRM call (R5); and Release A→5.18.58 still installed as
#            pinned (R6)   (RB after a rollback)
#          F summary
set -uo pipefail
umask 077

main() {
PLUGIN="dishnet-hybrid-sudan"
CONTAINER="${UCRM_CONTAINER:-ucrm}"
EXPECTED_PLUGIN_COMMIT="d6d0a2e"   # 5.18.59 — the distributor-application capture (migration 077 + endpoint + admin tab), on 5.18.58
EXPECTED_VERSION="5.18.59"
BASELINE_COMMIT="fcab6bd"          # 5.18.58 — what the server runs first (the sales/support tenant-currency fix), and the rollback
BASELINE_VERSION="5.18.58"
RELEASE_A_BASE="e076632"           # 5.18.49 — the regression base: R6 checks every file from Release A through 5.18.58 is installed as pinned
BRANCH="claude/study-this-jhe2eg"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$REPO/$PLUGIN"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="${DNB_OUT:-/root/dnb-5.18.59}"; mkdir -p "$OUT"; chmod 700 "$OUT"
STATE="$OUT/state-$EXPECTED_VERSION.env"
GUARD_SECONDS="${GUARD_SECONDS:-60}"
MIGRATION="077_distributor_applications.sql"   # the one migration this release adds
TABLE="dist_partner_applications"              # the one table it creates
TAB_FILE="tabs/admin/partner_applications.php" # the admin review tab

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

# ── HTTP helper: status code, body, headers, the number of redirects followed ─
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
hdr_has() { printf '%s' "$HTTP_HEADERS" | tr -d '\r' | grep -iq "$1"; }
mask() { sed -E 's/[0-9]{4,}/####/g; s/[^[:space:]/@]+@[^[:space:]/]+/…@…/g'; }
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
echo "  plugin commit   $HEAD_PLUGIN   (expected $EXPECTED_PLUGIN_COMMIT)"
PIN_CHECKOUT=""
if [ "$HEAD_PLUGIN" = "$EXPECTED_PLUGIN_COMMIT" ]; then
  echo "  installs        the checkout as it is"
elif git -C "$REPO" merge-base --is-ancestor "$EXPECTED_PLUGIN_COMMIT" HEAD 2>/dev/null; then
  PIN_CHECKOUT=1
  echo "  installs        $EXPECTED_PLUGIN_COMMIT by its hash — the branch has moved on to $HEAD_PLUGIN since"
else
  stop "the checkout's plugin commit is $HEAD_PLUGIN, and $EXPECTED_PLUGIN_COMMIT is not in its history — pull the branch, or this script is for another build"
fi
VERSION_SRC="$(git -C "$REPO" show "$EXPECTED_PLUGIN_COMMIT:$PLUGIN/manifest.json" 2>/dev/null | grep -o '"version": *"5[^"]*"' | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/')"
echo "  plugin version  ${VERSION_SRC:-?}   (expected $EXPECTED_VERSION)"
echo "  tracked edits   $DIRTY"
[ "$VERSION_SRC" = "$EXPECTED_VERSION" ]        || stop "manifest.json in $EXPECTED_PLUGIN_COMMIT says ${VERSION_SRC:-?}, expected $EXPECTED_VERSION"
[ "$DIRTY" = "0" ]                              || stop "the checkout has $DIRTY locally edited tracked files — deploying would ship edits nobody reviewed"
git -C "$REPO" cat-file -e "$BASELINE_COMMIT^{commit}" 2>/dev/null || stop "the checkout does not hold $BASELINE_COMMIT ($BASELINE_VERSION) — the rollback commit must be present before anything changes"
git -C "$REPO" cat-file -e "$RELEASE_A_BASE^{commit}" 2>/dev/null || stop "the checkout does not hold $RELEASE_A_BASE (Release A's baseline) — pull the branch again"

# What this release changes, from Git — never from a list typed into this script.
CHANGED="$(git -C "$REPO" diff --no-renames --name-only --diff-filter=AM "$BASELINE_COMMIT" "$EXPECTED_PLUGIN_COMMIT" -- "$PLUGIN")"
ADDED="$(git -C "$REPO" diff --no-renames --name-only --diff-filter=A "$BASELINE_COMMIT" "$EXPECTED_PLUGIN_COMMIT" -- "$PLUGIN")"
DELETED="$(git -C "$REPO" diff --no-renames --name-only --diff-filter=D "$BASELINE_COMMIT" "$EXPECTED_PLUGIN_COMMIT" -- "$PLUGIN")"
N_CHANGED="$(printf '%s\n' "$CHANGED" | grep -c . || true)"; N_ADDED="$(printf '%s\n' "$ADDED" | grep -c . || true)"
echo "  $EXPECTED_VERSION         $N_CHANGED files differ from $BASELINE_COMMIT: $((N_CHANGED - N_ADDED)) changed, $N_ADDED added, $(printf '%s\n' "$DELETED" | grep -c . || true) removed"
[ "$N_CHANGED" -gt 0 ] || stop "Git reports no file between $BASELINE_COMMIT and $EXPECTED_PLUGIN_COMMIT"
[ -z "$DELETED" ] || note "files removed or renamed in $EXPECTED_VERSION stay on the server under their old name (deploy-hybrid.sh never deletes): $(printf '%s ' $DELETED | sed "s#$PLUGIN/##g")"

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
[ -z "$PIN_CHECKOUT" ] || echo "     (--check compares the container with the branch tip, $HEAD_PLUGIN; this script installs $EXPECTED_PLUGIN_COMMIT)"

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
[ -n "$PHPV" ] || stop "no php inside the container — the data directory and the checks are read with it"
echo "  container PHP   $PHPV"
case "$PHPV" in 5.*|7.*) stop "the container's PHP is $PHPV; $EXPECTED_VERSION needs 8.0 or later (as $BASELINE_VERSION does)";; esac

PDD_IN="$(docker exec "$CONTAINER" php -r '$u=@json_decode((string)@file_get_contents($argv[1]),true); echo rtrim((string)($u["pluginDataDir"]??""),"/");' "$IN_CONTAINER/ucrm.json" 2>/dev/null || true)"
if [ -z "$PDD_IN" ]; then
  if docker exec "$CONTAINER" test -f "/data/ucrm/data/plugins/.$PLUGIN-data/plugin.sqlite3"; then PDD_IN="/data/ucrm/data/plugins/.$PLUGIN-data";
  else PDD_IN="$IN_CONTAINER/data"; fi
fi
DATA_HOST="$MOUNT${PDD_IN#/data}"
[ -d "$DATA_HOST" ] || DATA_HOST="$PDD_IN"
echo "  data dir        $PDD_IN (container)  =  $DATA_HOST (host)"
DB_OWNER="$(docker exec "$CONTAINER" stat -c '%u:%g' "$PDD_IN/plugin.sqlite3" 2>/dev/null || true)"
[ -n "$DB_OWNER" ] || stop "cannot read who owns $PDD_IN/plugin.sqlite3 — every read below runs as that owner, so that no file changes hands"
echo "  database owner  $DB_OWNER   (every read below runs as this user, and opens the database read-only)"

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
case "$PLUGIN_BASE" in *:8443*) stop "the plugin's public URL carries :8443 — crm_public_url is not set to the public address";; esac
ORIGIN="$(printf '%s' "$PLUGIN_BASE" | sed -E 's#^(https?://[^/]+).*#\1#')"
APPLY_URL="$PLUGIN_BASE?page=distributor_apply"
echo "  intake endpoint $APPLY_URL"
echo "  site origin     the website posts from https://dishnetuganda.com (allow-listed; never '*')"
http GET "$PLUGIN_BASE?page=customer_login"
echo "  before: the sign-in page on the public address → $HTTP_CODE"

# ── Read-only probe of the plugin database, run inside the container as the owner ──
RO_PDO='new PDO("sqlite:" . $argv[1] . "/plugin.sqlite3", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 30, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY])'
# The distributor-intake facts: whether migration 077 is recorded, whether the table exists, the total row count, and
# the count created at or after a given UTC instant (how stage R proves the probes created nothing). No application
# field ever leaves the container — counts only.
DPA_PHP='error_reporting(E_ALL); ini_set("display_errors", "stderr");
$since = $argv[2] ?? "";
try { $db = '"$RO_PDO"'; } catch (Throwable $e) { echo "DB unreadable:", get_class($e), "\n"; exit(0); }
$mig = 0;
try { $s = $db->prepare("SELECT count(*) FROM _migrations WHERE filename = ?"); $s->execute(["'"$MIGRATION"'"]); $mig = (int)$s->fetchColumn(); } catch (Throwable $e) { echo "MIG err\n"; }
echo "MIGRATION ", $mig, "\n";
$exists = 0;
try { $exists = (int)$db->query("SELECT count(*) FROM sqlite_master WHERE type=\"table\" AND name=\"'"$TABLE"'\"")->fetchColumn(); } catch (Throwable $e) {}
echo "TABLE ", $exists, "\n";
if ($exists) {
  try { echo "COUNT ", (int)$db->query("SELECT count(*) FROM '"$TABLE"'")->fetchColumn(), "\n"; } catch (Throwable $e) { echo "COUNT err\n"; }
  $idx = 0; try { $idx = (int)$db->query("SELECT count(*) FROM sqlite_master WHERE type=\"index\" AND tbl_name=\"'"$TABLE"'\" AND name IN (\"idx_dpa_created\",\"idx_dpa_status\")")->fetchColumn(); } catch (Throwable $e) {}
  echo "INDEX ", $idx, "\n";
  if ($since !== "") {
    try { $s = $db->prepare("SELECT count(*) FROM '"$TABLE"' WHERE datetime(created_at) >= datetime(?)"); $s->execute([$since]); echo "SINCE ", (int)$s->fetchColumn(), "\n"; }
    catch (Throwable $e) { echo "SINCE err\n"; }
  }
}
$db = null;'
# A core existing table (retailers), to show the deploy disturbed no existing data.
CORE_PHP='error_reporting(E_ALL); ini_set("display_errors", "stderr");
try { $db = '"$RO_PDO"'; echo "RETAILERS ", (int)$db->query("SELECT count(*) FROM retailers")->fetchColumn(), "\n"; $db = null; }
catch (Throwable $e) { echo "RETAILERS err:", get_class($e), "\n"; }'
probe() { docker exec -u "$DB_OWNER" "$CONTAINER" php -d display_errors=stderr -r "$@"; }

# A1 — the distributor-intake table before the deploy. On 5.18.58 migration 077 has never run, so the table is absent;
# that is the expected state and is information, not a failure.
DPA="$(probe "$DPA_PHP" "$PDD_IN" "" 2>&1)"
DPA_MIG="$(printf '%s\n' "$DPA" | sed -n 's/^MIGRATION //p' | head -1)"
DPA_TAB="$(printf '%s\n' "$DPA" | sed -n 's/^TABLE //p' | head -1)"
DPA_CNT="$(printf '%s\n' "$DPA" | sed -n 's/^COUNT //p' | head -1)"
if [ "${DPA_TAB:-0}" = "0" ]; then echo "  intake table    absent (migration 077 has not run yet) — expected on $BASELINE_VERSION"
else echo "  intake table    present, $DPA_CNT row(s), migration 077 recorded ${DPA_MIG:-?}× (an earlier $EXPECTED_VERSION left it)"; fi
RET_BEFORE="$(probe "$CORE_PHP" "$PDD_IN" 2>&1 | sed -n 's/^RETAILERS //p' | head -1)"
echo "  staff accounts  ${RET_BEFORE:-?} row(s) in retailers (this release writes none; stage R shows it unchanged)"

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

# ── The backup (deploy and rollback) ─────────────────────────────────────────
BK=""
if [ "$MODE" = "deploy" ] || { [ "$MODE" = "rollback" ] && [ "$LIVE_BEFORE" = "$EXPECTED_PLUGIN_COMMIT" ]; }; then
  hdr "A. Backup"
  BK="$OUT/backup-$TS"; mkdir -p "$BK"; chmod 700 "$BK"
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
    docker exec -u "$owner" "$CONTAINER" php -d display_errors=stderr -r "$SNAPSHOT_PHP" "$src" "$SNAP_TMP/dnb-backup-$TS-$db" \
      > "$BK/$db" 2> "$BK/$db.err"; rc=$?
    docker exec -u "$owner" "$CONTAINER" rm -f "$SNAP_TMP/dnb-backup-$TS-$db" 2>/dev/null || true
    set -- $(grep '^SNAPSHOT ' "$BK/$db.err" | tail -1)
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
    if [ "$d" -ef "$DATA_HOST" ]; then
      for db in $SNAP_DBS; do for s in "" -wal -shm -journal; do ex+=("--exclude=$(basename "$d")/$db$s"); done; done
      why=" — without the live databases, copied above"
    fi
    tar -C "$(dirname "$d")" ${ex[@]+"${ex[@]}"} -czf "$BK/$n.tar.gz" "$(basename "$d")" 2> "$BK/$n.tar.err"; rc=$?
    if [ "$rc" -le 1 ] && tar -tzf "$BK/$n.tar.gz" >/dev/null 2>&1; then
      ok "backed up $d → $BK/$n.tar.gz ($(du -h "$BK/$n.tar.gz" | cut -f1))$why"
      [ "$rc" = "1" ] && { note "while tar read $d, $(grep -c . "$BK/$n.tar.err") file(s) changed or went away (tar exit 1: logs and the like)"; }
    else
      bad "backup of $d failed (tar exit $rc$([ "$rc" -le 1 ] && printf '; the archive it wrote cannot be read back')). It said:"
      grep . "$BK/$n.tar.err" | head -5 | mask | cut -c1-200 | sed 's/^/          /'
    fi
  done
  CODE_TAR="$BK/plugin-installed-${LIVE_VERSION:-unknown}.tar.gz"
  tar -C "$(dirname "$DEST")" --exclude="$PLUGIN/data" -czf "$CODE_TAR" "$PLUGIN" 2> "$BK/plugin-installed.tar.err"; rc=$?
  if [ "$rc" = "0" ] && tar -tzf "$CODE_TAR" >/dev/null 2>&1; then ok "backed up the installed plugin (${LIVE_VERSION:-?}, ${LIVE_BEFORE:-?}) → $CODE_TAR ($(du -h "$CODE_TAR" | cut -f1)) — its code, without data/"
  else bad "backup of the installed plugin failed (tar exit $rc): $(head -c 200 "$BK/plugin-installed.tar.err" | mask | tr '\n' ' ')"; fi
  VAULT="$MOUNT/ucrm/data/plugins/.dishnet-sudan.vault.json"
  if [ -f "$VAULT" ]; then
    if cp -p "$VAULT" "$BK/config-vault.json" && cmp -s "$VAULT" "$BK/config-vault.json"; then ok "backed up the configuration vault → $BK/config-vault.json ($(stat -c %s "$VAULT") bytes, identical)"
    else bad "backup of the configuration vault failed"; fi
  else note "no configuration vault at the plugins directory — nothing to copy"; fi
  cp "$DEST/.deployed-commit" "$BK/deployed-commit.before" 2>/dev/null || true
  chmod -R go-rwx "$BK"
  [ "$FAIL" = "0" ] || stop "NO-GO: the backup or a before-check did not complete"
  echo; echo "  GO — evidence recorded, backup in $BK"
  if [ "$MODE" = "deploy" ]; then echo "  The rollback command is printed at the end of this log, on its own."
  else echo "  Deploy again at any time:  cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh"; fi
fi

# §16.23: OPcache tells a changed file only by its modification second; each file this release changes gets the time of
# this copy, so PHP-FPM compiles it again at its next use. Only the time changes; R1 checks each file's content.
stamp_copy() {
  local f rel n=0
  for f in $CHANGED; do rel="${f#"$PLUGIN"/}"; [ -f "$DEST/$rel" ] && touch -c "$DEST/$rel" && n=$((n+1)); done
  echo "  gave the $n installed file(s) this release changes the time of this copy, $(date -u +%H:%M:%S) UTC: PHP-FPM compiles each again at its next use"
}

# The documented rollback: check out 5.18.58, run the documented deploy of it, verify, return the checkout.
do_rollback() {
  local ref; ref="$(git -C "$REPO" symbolic-ref -q --short HEAD 2>/dev/null || git -C "$REPO" rev-parse HEAD)"
  echo "  the checkout is on $ref; checking out $BASELINE_COMMIT ($BASELINE_VERSION) for the documented deploy"
  git -C "$REPO" checkout -q "$BASELINE_COMMIT" || { bad "git checkout $BASELINE_COMMIT failed — nothing was deployed"; return 1; }
  bash "$REPO/scripts/deploy-hybrid.sh" 2>&1 | sed 's/^/     /'; local rc=${PIPESTATUS[0]}
  if [ "$rc" = "2" ]; then
    note "the container was still restarting; waiting for it"
    local i; for i in 1 2 3 4 5 6 7 8 9 10 11 12; do
      sleep 10
      if bash "$REPO/scripts/deploy-hybrid.sh" --check 2>&1 | grep -q 'Up to date'; then rc=0; break; fi
    done
  fi
  git -C "$REPO" checkout -q "$ref" || bad "could not return the checkout to $ref — run: cd $REPO && git checkout $ref"
  stamp_copy
  local live; live="$(live_commit)"
  echo "  the container now serves ${live:-?}; the checkout is back on $ref"
  [ "$rc" = "0" ] && [ "$live" = "$BASELINE_COMMIT" ]
}

DEPLOY_STARTED=""; DEPLOY_STARTED_SQL=""
# ════════════════════════════════════════════════════════
if [ "$MODE" = "deploy" ]; then
  hdr "B. The documented deploy (scripts/deploy-hybrid.sh)"
  # ══════════════════════════════════════════════════════
  printf '  Type DEPLOY to deploy %s (%s) over live %s, anything else to stop: ' "$EXPECTED_VERSION" "$EXPECTED_PLUGIN_COMMIT" "${LIVE_BEFORE:-?}"
  read -r ANSWER </dev/tty || ANSWER=""; echo
  [ "$ANSWER" = "DEPLOY" ] || stop "not confirmed"
  DEPLOY_STARTED="$(date -u +%Y-%m-%dT%H:%M:%SZ)"; DEPLOY_STARTED_SQL="$(date -u +'%Y-%m-%d %H:%M:%S')"
  printf 'DEPLOYED_AT=%s\nDEPLOYED_AT_SQL=%s\nFROM_COMMIT=%s\nRET_BEFORE=%s\n' "$DEPLOY_STARTED" "$DEPLOY_STARTED_SQL" "$LIVE_BEFORE" "${RET_BEFORE:-}" > "$STATE"
  DEPLOY_REF=""
  if [ -n "$PIN_CHECKOUT" ]; then
    DEPLOY_REF="$(git -C "$REPO" symbolic-ref -q --short HEAD 2>/dev/null || git -C "$REPO" rev-parse HEAD)"
    echo "  the checkout is on $DEPLOY_REF ($HEAD_PLUGIN); checking out $EXPECTED_PLUGIN_COMMIT ($EXPECTED_VERSION) for the documented deploy"
    git -C "$REPO" checkout -q "$EXPECTED_PLUGIN_COMMIT" || stop "git checkout $EXPECTED_PLUGIN_COMMIT failed — nothing was deployed"
  fi
  bash "$REPO/scripts/deploy-hybrid.sh" 2>&1 | sed 's/^/     /'; RC=${PIPESTATUS[0]}
  if [ "$RC" = "2" ]; then
    note "the container was still restarting; waiting for it"
    for i in 1 2 3 4 5 6 7 8 9 10 11 12; do
      sleep 10
      if bash "$REPO/scripts/deploy-hybrid.sh" --check 2>&1 | grep -q 'Up to date'; then RC=0; break; fi
    done
  fi
  if [ -n "$DEPLOY_REF" ]; then
    if git -C "$REPO" checkout -q "$DEPLOY_REF"; then echo "  the checkout is back on $DEPLOY_REF"
    else bad "could not return the checkout to $DEPLOY_REF — run: cd $REPO && git checkout $DEPLOY_REF"; fi
  fi
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
    if do_rollback; then ok "container serves $BASELINE_COMMIT ($BASELINE_VERSION)"
    else stop "the rollback did not verify (live=$(live_commit)) — by hand: cd $REPO && git checkout $BASELINE_COMMIT && bash scripts/deploy-hybrid.sh && git checkout -"; fi
  else
    DEPLOY_STARTED="$(date -u -d '-10 minutes' +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || date -u +%Y-%m-%dT%H:%M:%SZ)"
  fi
  LIVE_AFTER="$(live_commit)"
else
  LIVE_AFTER="$LIVE_BEFORE"
  ok "live commit is $LIVE_AFTER"
  DEPLOY_STARTED="$(sed -n 's/^DEPLOYED_AT=//p' "$STATE" 2>/dev/null | head -1)"
  DEPLOY_STARTED_SQL="$(sed -n 's/^DEPLOYED_AT_SQL=//p' "$STATE" 2>/dev/null | head -1)"
  [ -n "$DEPLOY_STARTED" ] || DEPLOY_STARTED="$(date -u -d '-10 minutes' +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || date -u +%Y-%m-%dT%H:%M:%SZ)"
fi

# ════════════════════════════════════════════════════════
hdr "V. Verification over the public address (nothing signed in; no customer, no application touched)"
# ════════════════════════════════════════════════════════
# V1 — the public address is never redirected (a loop after a deploy rolls back), and the portal still refuses.
http GET "$PLUGIN_BASE?page=customer_login" -L
if [ "$HTTP_CODE" = "200" ] && [ "$HTTP_REDIRECTS" = "0" ]; then ok "V1 the sign-in page on the public address answers 200 with zero redirects (no loop)"
else
  bad "V1 the sign-in page on the public address → $HTTP_CODE after $HTTP_REDIRECTS redirect(s) ${HTTP_LOCATION:+(last Location $HTTP_LOCATION)}"
  if [ "$MODE" = "deploy" ] && [ "${HTTP_REDIRECTS:-0}" != "0" ]; then
    echo; echo "  ROLLING BACK to $BASELINE_COMMIT — the public address must never redirect"
    do_rollback || true; stop "rolled back (the container serves $(live_commit)); send the log file"
  fi
fi
http GET "$PLUGIN_BASE?page=customer_portal&view=home"
case "$HTTP_CODE" in 302|401) ok "V1 the portal without a session still refuses ($HTTP_CODE)";; *) bad "V1 the portal without a session → $HTTP_CODE";; esac

# V2 — the Uganda public pages still render their own contacts (a cheap regression: the public.php edit broke nothing).
http GET "$PLUGIN_BASE?page=customer_login"
if [ "$HTTP_CODE" = "200" ] && [ "$(count '+211')" = "0" ] && [ "$(count 'dishnetafrica.com')" = "0" ]; then ok "V2 the sign-in page answers 200 and carries no South Sudan contact"
else bad "V2 sign-in page → $HTTP_CODE; '+211' ×$(count '+211') · dishnetafrica.com ×$(count 'dishnetafrica.com')"; fi

# V3 — the distributor_apply endpoint: its CORS and anti-abuse guards, each refused BEFORE the store, so none creates a
# row. The site posts from https://dishnetuganda.com; a wrong origin, a wrong method, a filled honeypot and an
# impossibly fast submit are all turned away. This also makes the first request the new code serves, which applies 077.
SITE_ORIGIN="https://dishnetuganda.com"
http OPTIONS "$APPLY_URL" -H "Origin: $SITE_ORIGIN" -H 'Access-Control-Request-Method: POST'
if [ "$HTTP_CODE" = "204" ] && hdr_has "^access-control-allow-origin: *$SITE_ORIGIN"; then ok "V3 OPTIONS preflight from the site origin → 204 and reflects that origin (CORS open to the site, never '*')"
else bad "V3 OPTIONS from the site origin → $HTTP_CODE; ACAO $(printf '%s' "$HTTP_HEADERS" | tr -d '\r' | grep -i '^access-control-allow-origin:' | head -1 | sed -E 's/^[^:]*: *//')"; fi
http OPTIONS "$APPLY_URL" -H 'Origin: https://evil.example.com' -H 'Access-Control-Request-Method: POST'
if [ "$HTTP_CODE" = "403" ] && ! hdr_has '^access-control-allow-origin:'; then ok "V3 OPTIONS from a disallowed origin → 403 with no Access-Control-Allow-Origin (the allow-list holds)"
else bad "V3 OPTIONS from a disallowed origin → $HTTP_CODE $(hdr_has '^access-control-allow-origin:' && echo '(but it set ACAO!)')"; fi
http GET "$APPLY_URL"
if [ "$HTTP_CODE" = "405" ] && printf '%s' "$HTTP_BODY" | grep -q '"reason":"method"'; then ok "V3 GET → 405 {reason:method} — POST-only, and the route is reached BEFORE the login gate (a gated page would serve HTML or redirect)"
else bad "V3 GET → $HTTP_CODE $(printf '%s' "$HTTP_BODY" | cut -c1-120)"; fi
http POST "$APPLY_URL" -H "Origin: $SITE_ORIGIN" --data 'hp=iamabot&t=9000&payload=%7B%7D'
if [ "$HTTP_CODE" = "200" ] && printf '%s' "$HTTP_BODY" | grep -q '"reason":"rejected"'; then ok "V3 POST with the honeypot filled → 200 {ok:false,reason:rejected} — turned away before any store"
else bad "V3 POST (honeypot) → $HTTP_CODE $(printf '%s' "$HTTP_BODY" | cut -c1-120)"; fi
http POST "$APPLY_URL" -H "Origin: $SITE_ORIGIN" --data 'hp=&t=1&payload=%7B%7D'
if [ "$HTTP_CODE" = "200" ] && printf '%s' "$HTTP_BODY" | grep -q '"reason":"too_fast"'; then ok "V3 POST submitted impossibly fast → 200 {ok:false,reason:too_fast} — turned away before any store"
else bad "V3 POST (too fast) → $HTTP_CODE $(printf '%s' "$HTTP_BODY" | cut -c1-120)"; fi
http POST "$APPLY_URL" -H "Origin: $SITE_ORIGIN" --data 'hp=&t=9000&payload='
if [ "$HTTP_CODE" = "400" ] && printf '%s' "$HTTP_BODY" | grep -q '"reason":"bad_payload"'; then ok "V3 POST with an empty payload → 400 {ok:false,reason:bad_payload} — nothing to store"
else bad "V3 POST (empty payload) → $HTTP_CODE $(printf '%s' "$HTTP_BODY" | cut -c1-120)"; fi

# V4 — no fatal of the plugin in the container log since the deploy.
if [ "$MODE" != "after" ] && [ -n "$BK" ]; then
  printf '  …     waiting %ss for the first requests on the code now installed\n' "$GUARD_SECONDS"; sleep "$GUARD_SECONDS"
fi
V4_LINES="$(log_since "$DEPLOY_STARTED" | grep -E 'UNCAUGHT|FATAL|PHP Fatal|PHP Parse error' | grep -F -- "$PLUGIN" || true)"
N_FATAL="$(printf '%s\n' "$V4_LINES" | grep -c . || true)"
if [ "${N_FATAL:-0}" = "0" ]; then ok "V4 no fatal or parse error of $PLUGIN in the container log since $DEPLOY_STARTED"
else bad "V4 ${N_FATAL} fatal line(s) of $PLUGIN since $DEPLOY_STARTED:"; printf '%s\n' "$V4_LINES" | cut -c1-200 | head -5 | sed 's/^/     /'; fi

if [ "$MODE" != "rollback" ]; then
# ════════════════════════════════════════════════════════
hdr "R. $EXPECTED_VERSION installed (read-only)"
# ════════════════════════════════════════════════════════
# R1 — every file this release changes is installed byte for byte as the pinned commit has it, and the version.
R_OK=0; R_BAD=""
for f in $CHANGED; do
  rel="${f#"$PLUGIN"/}"
  a="$(git -C "$REPO" show "$EXPECTED_PLUGIN_COMMIT:$f" 2>/dev/null | sha256sum | cut -c1-64)"
  b="$(sha256sum "$DEST/$rel" 2>/dev/null | cut -c1-64)"
  if [ -n "$b" ] && [ "$a" = "$b" ]; then R_OK=$((R_OK+1)); else R_BAD="$R_BAD $rel"; fi
done
[ -z "$R_BAD" ] && ok "R1 all $R_OK files $EXPECTED_VERSION changes are installed exactly as $EXPECTED_PLUGIN_COMMIT has them ($N_ADDED of them new)" \
  || bad "R1 installed files that differ from $EXPECTED_PLUGIN_COMMIT:$R_BAD"
IV="$(grep -o '"version": *"5[^"]*"' "$DEST/manifest.json" | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/')"
[ "$IV" = "$EXPECTED_VERSION" ] && ok "R1 the installed manifest says $IV" || bad "R1 the installed manifest says ${IV:-?}"

# R2 — migration 077 applied by the plugin itself at the first request the new code served (stage V made it): recorded
# in _migrations, the table and both its indexes present.
DPA2="$(probe "$DPA_PHP" "$PDD_IN" "${DEPLOY_STARTED_SQL:-}" 2>&1)"
R2_MIG="$(printf '%s\n' "$DPA2" | sed -n 's/^MIGRATION //p' | head -1)"
R2_TAB="$(printf '%s\n' "$DPA2" | sed -n 's/^TABLE //p' | head -1)"
R2_IDX="$(printf '%s\n' "$DPA2" | sed -n 's/^INDEX //p' | head -1)"
R2_CNT="$(printf '%s\n' "$DPA2" | sed -n 's/^COUNT //p' | head -1)"
R2_SINCE="$(printf '%s\n' "$DPA2" | sed -n 's/^SINCE //p' | head -1)"
if [ "$R2_MIG" = "1" ] && [ "$R2_TAB" = "1" ] && [ "$R2_IDX" = "2" ]; then
  ok "R2 migration 077 is applied: $TABLE exists with both indexes, holding $R2_CNT application(s)"
else
  bad "R2 migration 077 is not fully in place (recorded ${R2_MIG:-?}×, table ${R2_TAB:-?}, indexes ${R2_IDX:-?}/2) — the intake has nowhere to write"
  docker exec -u "$DB_OWNER" "$CONTAINER" sh -c "grep -E '077_distributor' '$PDD_IN/migration.log' 2>/dev/null | tail -3" 2>/dev/null | cut -c1-200 | sed 's/^/     /'
fi

# R3 — the admin review tab is installed and registered in the installed public.php (file + route + perm).
if [ -f "$DEST/$TAB_FILE" ]; then ok "R3 the review tab file is installed ($TAB_FILE)"; else bad "R3 the review tab file is missing ($TAB_FILE)"; fi
PUB="$DEST/public.php"
if grep -q "'partner_applications'=> *'tabs/admin/partner_applications.php'" "$PUB" 2>/dev/null \
   && grep -q "'partner_applications' *=> *'\*admin'" "$PUB" 2>/dev/null; then
  ok "R3 the review tab is registered in the installed public.php (\$_tabFiles) and admin-gated (\$_tabPerms '*admin')"
else bad "R3 the review tab is not registered as expected in the installed public.php"; fi
if grep -q "page === 'distributor_apply'" "$PUB" 2>/dev/null; then
  RP="$(grep -n "page === 'distributor_apply'" "$PUB" | head -1 | cut -d: -f1)"; GP="$(grep -n 'requireLogin()' "$PUB" | head -1 | cut -d: -f1)"
  if [ -n "$RP" ] && [ -n "$GP" ] && [ "$RP" -lt "$GP" ]; then ok "R3 the public route is wired in the installed public.php before the login gate (line $RP < $GP)"
  else bad "R3 the public route's position relative to the login gate is wrong (route line ${RP:-?}, gate line ${GP:-?})"; fi
else bad "R3 the installed public.php does not route page=distributor_apply"; fi

# R4 — the deploy's own endpoint probes created NO application row: nothing in the table carries a created_at at or after
# the deploy began. (On an --after-only re-run the window is the recorded deploy time.)
if [ "$R2_TAB" = "1" ]; then
  if [ -n "${DEPLOY_STARTED_SQL:-}" ] && [ -n "$R2_SINCE" ] && [ "$R2_SINCE" != "err" ]; then
    [ "$R2_SINCE" = "0" ] && ok "R4 no application row was created during this deploy (0 rows at/after ${DEPLOY_STARTED_SQL}): the endpoint probes all stopped before the store" \
      || bad "R4 $R2_SINCE application row(s) appeared at/after the deploy began — the probes should create none; if a real applicant submitted meanwhile, say so when you send the log"
  else note "R4 the no-row window could not be measured (no deploy timestamp on this run); the table holds $R2_CNT row(s)"
  fi
fi

# R5 — the installed endpoint and service carry NO uCRM call: no CrmApiClient, no uCRM API path, no client creation.
R5_BAD=""
for rel in "distributor_apply.php" "lib/DistributorApplicationService.php"; do
  if [ -f "$DEST/$rel" ]; then
    grep -q 'CrmApiClient' "$DEST/$rel" 2>/dev/null && R5_BAD="$R5_BAD $rel:CrmApiClient"
    grep -Eq 'api/v[0-9]|X-Auth-App-Key' "$DEST/$rel" 2>/dev/null && R5_BAD="$R5_BAD $rel:ucrm-api"
    grep -qi 'createClient' "$DEST/$rel" 2>/dev/null && R5_BAD="$R5_BAD $rel:createClient"
  else R5_BAD="$R5_BAD $rel:missing"; fi
done
[ -z "$R5_BAD" ] && ok "R5 the installed intake creates no uCRM record: distributor_apply.php and DistributorApplicationService.php name no CrmApiClient, no uCRM API path and no client creation" \
  || bad "R5 the installed intake references uCRM where it must not:$R5_BAD"

# R6 — the data this release does not touch is unchanged: the retailers count matches stage A (or the deploy's record).
RET_REF="${RET_BEFORE:-}"; RET_REF_WHAT="stage A of this run"
if [ "$MODE" = "after" ] && [ -f "$STATE" ]; then r="$(sed -n 's/^RET_BEFORE=//p' "$STATE" | head -1)"; [ -n "$r" ] && { RET_REF="$r"; RET_REF_WHAT="the deploy's record"; }; fi
RET_AFTER="$(probe "$CORE_PHP" "$PDD_IN" 2>&1 | sed -n 's/^RETAILERS //p' | head -1)"
if [ -n "$RET_REF" ] && [ "$RET_REF" = "$RET_AFTER" ]; then ok "R6 the retailers table is unchanged ($RET_AFTER rows, as at $RET_REF_WHAT): this release wrote no existing table"
elif [ -n "$RET_AFTER" ]; then note "R6 retailers reads $RET_AFTER now vs ${RET_REF:-?} at $RET_REF_WHAT (a person adding or editing an account meanwhile counts here)"
else bad "R6 the retailers table could not be read back"; fi

# R7 — Release A (5.18.49) → 5.18.58 installed as the pinned commit has it (a full regression that nothing was lost).
RA_OK=0; RA_BAD=""
for f in $(git -C "$REPO" diff --no-renames --name-only --diff-filter=AM "$RELEASE_A_BASE" "$BASELINE_COMMIT" -- "$PLUGIN"); do
  rel="${f#"$PLUGIN"/}"
  git -C "$REPO" cat-file -e "$EXPECTED_PLUGIN_COMMIT:$f" 2>/dev/null || continue   # a file 5.18.59 removed/renamed is not compared
  a="$(git -C "$REPO" show "$EXPECTED_PLUGIN_COMMIT:$f" 2>/dev/null | sha256sum | cut -c1-64)"
  b="$(sha256sum "$DEST/$rel" 2>/dev/null | cut -c1-64)"
  if [ -n "$b" ] && [ "$a" = "$b" ]; then RA_OK=$((RA_OK+1)); else RA_BAD="$RA_BAD $rel"; fi
done
[ -z "$RA_BAD" ] && ok "R7 all $RA_OK files from Release A through $BASELINE_VERSION are installed as $EXPECTED_PLUGIN_COMMIT has them — 5.18.59 lost none of the prior releases" \
  || bad "R7 files from Release A→$BASELINE_VERSION differ on the server:$RA_BAD"

elif [ "$MODE" = "rollback" ]; then
# ════════════════════════════════════════════════════════
hdr "RB. $BASELINE_VERSION, back in place (read-only)"
# ════════════════════════════════════════════════════════
IV="$(grep -o '"version": *"5[^"]*"' "$DEST/manifest.json" | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/')"
[ "$IV" = "$BASELINE_VERSION" ] && ok "RB the installed manifest says $IV" || bad "RB the installed manifest says ${IV:-?}, expected $BASELINE_VERSION"
# The intake table and any rows stay (SQLite keeps the table; 5.18.58 simply never reads it). The rollback restores code,
# not data, and this release changed no existing table, so no restore is needed.
DPAR="$(probe "$DPA_PHP" "$PDD_IN" "" 2>&1)"; RB_TAB="$(printf '%s\n' "$DPAR" | sed -n 's/^TABLE //p' | head -1)"; RB_CNT="$(printf '%s\n' "$DPAR" | sed -n 's/^COUNT //p' | head -1)"
if [ "${RB_TAB:-0}" = "1" ]; then note "RB $TABLE and its $RB_CNT row(s) remain in the database — $BASELINE_VERSION never reads them; nothing to restore"
else echo "  …     $TABLE is not present (it was never created here)"; fi
fi

# ════════════════════════════════════════════════════════
hdr "F. Summary"
# ════════════════════════════════════════════════════════
LIVE_NOW="$(live_commit)"
case "$MODE" in
  rollback) echo "  serving           ${LIVE_NOW:-?}  (plugin $BASELINE_VERSION)"
            echo "  deploy again      cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh" ;;
  *)        echo "  deployed commit   ${LIVE_NOW:-?}  (plugin $EXPECTED_VERSION)" ;;
esac
[ -n "$BK" ] && echo "  backup            $BK"
[ -n "$BK" ] && echo "  the data          this release changes no existing table and no existing row: a rollback needs no restore. The new table (077) and any rows simply stay, unread by $BASELINE_VERSION"
if [ "$MODE" != "rollback" ]; then
  echo "  what changed      the public website's 'Become a DishNet Distributor' page can now lodge an application through page=distributor_apply; staff review it in a new admin-only tab. No uCRM record, no partner, no account is created (R5)"
  echo "  the website half  ships separately: the HTML must reach branch main and the operator rebuilds web-uganda on EasyPanel (dishnet-web-uganda/README-DEPLOY.md). The page's WhatsApp fallback keeps it safe before this endpoint is live"
  echo "  regression        R7 confirms Release A through $BASELINE_VERSION are installed as pinned; V1/V2 confirm the public pages still render"
  echo "  later             cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --after-only   re-measures V3, R2 and R4 since this deploy; send its log file"
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

# The whole script is read before it runs: a rollback checks out a commit in which this file does not exist.
main "$@"; exit $?
