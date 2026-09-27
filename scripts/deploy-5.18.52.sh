#!/usr/bin/env bash
#
# deploy-5.18.52.sh — deploy plugin 5.18.52 over 5.18.51, or roll it back to 5.18.51.
#
#   5.18.52  Release B of docs/44, designed the South Sudan way and approved ("approved", §16.12; build §16.14). On
#            Uganda one component, lib/JobNotifier.php, sends every job WhatsApp message, once per change, to the
#            engineer uCRM has on the job — only to an account linked through the verified picker (M7):
#              · message 1, "New Job Has Been Assigned to You", with an ACCEPT JOB link to the job's page;
#              · message 2, after Accept, with the completion link, in place of the old "✅ Job Accepted";
#              · a new time; "no longer assigned" (reassigned or removed); "cancelled" (deleted in uCRM).
#            The link survives the staff sign-in. Jobs made in My Jobs and Bulk Dispatch are created Open (status 0),
#            so the Accept button shows. Migration 075 adds two tables the notifier keeps its record in.
#   South Sudan: nothing changes but two empty tables; this script checks only the Uganda server.
#   It installs the pinned commit by its hash, also once later releases have been pushed to the branch.
#
# 5.18.51 must be installed first (scripts/deploy-5.18.51.sh): this script refuses any other live commit.
#
# Run as root on the server, then send back THE LOG FILE (never a copy of the terminal):
#
#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg \
#     && mkdir -p /root/dnb-5.18.52 \
#     && bash scripts/deploy-5.18.52.sh 2>&1 | tee /root/dnb-5.18.52/deploy-$(date -u +%Y%m%dT%H%M%SZ).log
#
# The rollback is a separate command, printed at the end of the deploy's log. It is never pasted together with the one
# above: pasted together, the shell runs both (docs/44 §16.9).
#
# Options
#   --after-only         the deploy already happened: run the checks again, counting from the deploy's own record
#   --rollback           put 5.18.51 back (typed ROLLBACK), then check it
#   --plugin-base <url>  the public URL of public.php, if the derived one is wrong
#
# What it never does: touch a configuration value, a customer, a staff account, a job, a message, the webhook key,
# Traefik, UISP or the website; send anything; sign anyone in; run a cron; call uCRM. Every read of the plugin's data
# opens the database READ-ONLY as the database's owner. The writes are the documented deploy (or rollback), a backup
# under /root/dnb-5.18.52/ (its database copies pass through the container's /tmp and are removed there at once), a
# record of where the deploy started (state-5.18.52.env beside the logs) — and, only if stage V finds the public
# address redirecting after a deploy, the rollback. Migration 075 is applied by the plugin itself, at the first request
# the new code serves; stage V's own page loads are such requests.
#
# Stages:  A before-evidence, the Uganda check, the syntax check under the server's own PHP, the staff snapshot,
#            the Message Log mark, the notifier's tables, the backup → GO/NO-GO
#          B the documented deploy (or, with --rollback, the documented deploy of 5.18.51)
#          V the public pages, the :8443 door and the loop check; no fatal since the deploy
#          R 5.18.52 installed: the files, the Uganda switch, staff records untouched, the old job paths silent,
#            migration 075, the job link, the master's lock guard kept        (RB after a rollback)
#          F summary
set -uo pipefail
umask 077

main() {
PLUGIN="dishnet-hybrid-sudan"
CONTAINER="${UCRM_CONTAINER:-ucrm}"
EXPECTED_PLUGIN_COMMIT="fc5c3b7"   # 5.18.52 — the plugin-scoped commit deploy-hybrid.sh records
EXPECTED_VERSION="5.18.52"
BASELINE_COMMIT="240f2f9"          # 5.18.51 — what the server runs first, what 5.18.52 was built and tested against, the rollback
BASELINE_VERSION="5.18.51"
RELEASE_A_BASE="e076632"           # 5.18.49 — Release A's own baseline: R1 checks Release A's files are installed as pinned
BRANCH="claude/study-this-jhe2eg"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$REPO/$PLUGIN"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="${DNB_OUT:-/root/dnb-5.18.52}"; mkdir -p "$OUT"; chmod 700 "$OUT"
STATE="$OUT/state-$EXPECTED_VERSION.env"
GUARD_SECONDS="${GUARD_SECONDS:-60}"
# The events of the old job-assignment paths (5.18.49): ＋ New Job and Bulk Dispatch, Reschedule. On Uganda they stay
# silent — release B's messages are the notifier's, under the events below. Any suffix counts (…_suppressed_optout).
OLD_RE='^(ops_scheduling_job_assigned|ops_scheduling_rescheduled)'
# Release B's messages in the Message Log: message 1, a new time, the two notices, message 2.
B_RE='^(job_assigned|job_rescheduled|job_unassigned|job_cancelled|ops_job_accepted_self)'

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
live_commit() { docker exec "$CONTAINER" cat "$IN_CONTAINER/.deployed-commit" 2>/dev/null | tail -n1 | tr -cd '0-9a-f'; }
# Container log lines at or after $1 (UTC, compared to the second). Docker's --since only fetches: the window is decided
# here, so it is the same whatever the docker in front of it does with --since.
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
# What is installed is always $EXPECTED_PLUGIN_COMMIT, by its hash. Later releases are pushed to the same branch, so
# after a pull the checkout may be ahead of it: the deploy then checks the pinned commit out for the documented deploy
# and returns the checkout to the branch afterwards, as the rollback does with $BASELINE_COMMIT. What this script reads
# for the release — the version, the syntax check, stage R — comes from that commit, never from the working tree.
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

# What 5.18.52 changes, from Git — never from a list typed into this script. Without rename detection: a renamed file
# is a removed one and an added one, which is what deploy-hybrid.sh makes of it on the server (the old name stays).
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
[ -d "$DATA_HOST" ] || DATA_HOST="$PDD_IN"   # a data directory outside /data is the same path on both sides
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
HOSTNAME_ONLY="$(printf '%s' "$ORIGIN" | sed -E 's#^https?://##; s#:[0-9]+$##')"
ALT_BASE="https://$HOSTNAME_ONLY:8443${PLUGIN_BASE#"$ORIGIN"}"
echo "  the :8443 door  $ALT_BASE"
echo "  job links       $PLUGIN_BASE?page=dashboard&tab=scheduling&job=<id>   (what message 1 and message 2 carry)"
http GET "$PLUGIN_BASE?page=customer_login"
echo "  before: the sign-in page on the public address → $HTTP_CODE"

# ── Read-only probes, run inside the container as the database's owner ───────
RO_PDO='new PDO("sqlite:" . $argv[1] . "/plugin.sqlite3", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 30, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY])'
# The tenant, and (after the deploy) the switch, as the plugin decides them: the configuration's store copy (how
# public.php and the master cron build it) and its files (how the webhooks and the follow-up crons build it), each
# gap-filled from the vault by TenantProfile itself. The vault's location depends on who asks, so the owner asks.
TENANT_PHP='error_reporting(E_ALL); ini_set("display_errors", "stderr");
$pdd = $argv[1]; $root = $argv[2]; $what = $argv[3];
foreach (["ConfigVault", "PluginConfig", "TenantProfile"] as $c) require_once $root . "/lib/" . $c . ".php";
if ($what === "gate") require_once $root . "/lib/StaffJobsGate.php";
$store = [];
try { $db = '"$RO_PDO"'; $row = $db->query("SELECT data FROM kyc_config WHERE id = 0 LIMIT 1")->fetchColumn(); $db = null;
      $d = is_string($row) ? json_decode($row, true) : null; $store = is_array($d) ? $d : [];
} catch (Throwable $e) { echo "store=unreadable:", get_class($e), "\n"; }
$files = PluginConfig::read($root, $pdd);
foreach (["store" => $store, "files" => $files] as $k => $cfg) {
  try { echo $k, "=", $what === "gate" ? (StaffJobsGate::applies($cfg, $pdd) ? "on" : "off") : TenantProfile::current($cfg, $pdd)->id(), "\n"; }
  catch (Throwable $e) { echo $k, "=error:", get_class($e), "\n"; }
}'
# The staff accounts: one line per account — its id and a digest of the fields the job rules read (role, admin,
# active, e-mail, phone, uCRM user id, FTTH id, the verified link) — and the counts. No name, e-mail or number leaves
# the container; the digests are salted and cut.
STAFF_PHP='error_reporting(E_ALL); ini_set("display_errors", "stderr");
$db = '"$RO_PDO"';
$cols = array_column($db->query("PRAGMA table_info(retailers)")->fetchAll(PDO::FETCH_ASSOC), "name");
if (!$cols) { fwrite(STDERR, "no retailers table\n"); exit(3); }
$rows = [];
if (in_array("data", $cols, true)) { foreach ($db->query("SELECT id, data FROM retailers ORDER BY id") as $r) { $d = json_decode((string)$r["data"], true); if (is_array($d)) { $d["id"] = $d["id"] ?? $r["id"]; $rows[] = $d; } } }
else { foreach ($db->query("SELECT * FROM retailers ORDER BY id") as $r) $rows[] = $r; }
$db = null;
$h = function ($v) { $v = strtolower(trim((string)$v)); return $v === "" ? "-" : substr(hash("sha256", "dnb-5.18.50|" . $v), 0, 20); };
$jobRoles = ["support", "support_leader", "support_engineer", "admin"];
$n = ["accounts" => 0, "active" => 0, "take_jobs" => 0, "with_id" => 0, "verified" => 0, "unverified" => 0, "ftth_only" => 0];
$lines = [];
foreach ($rows as $d) {
  $role = strtolower(trim((string)($d["role"] ?? ""))); $admin = !empty($d["is_admin"]); $active = !empty($d["is_active"]);
  $uid = (int)($d["ucrm_user_id"] ?? 0); $email = strtolower(trim((string)($d["email"] ?? "")));
  $link = is_array($d["ucrm_link"] ?? null) ? $d["ucrm_link"] : null;
  $verified = $link !== null && $uid > 0 && (int)($link["user_id"] ?? 0) === $uid && $email !== "" && (string)($link["email"] ?? "") === $email;
  $n["accounts"]++; if ($active) $n["active"]++;
  if ($active && ($admin || in_array($role, $jobRoles, true))) {
    $n["take_jobs"]++; if ($uid > 0) $n["with_id"]++;
    if ($verified) $n["verified"]++; elseif ($uid > 0) $n["unverified"]++;
    if ($uid <= 0 && (int)($d["ftth_crm_client_id"] ?? 0) > 0) $n["ftth_only"]++;
  }
  $f = [$role, $admin, $active, $h($email), $h($d["phone"] ?? ""), $uid, (int)($d["ftth_crm_client_id"] ?? 0),
        $link === null ? null : [(int)($link["user_id"] ?? 0), $h($link["email"] ?? ""), (string)($link["verified_at"] ?? ""), (int)($link["verified_by"] ?? 0)]];
  $lines[] = sprintf("ROW %d %s", (int)($d["id"] ?? 0), substr(hash("sha256", json_encode($f)), 0, 20));
}
sort($lines, SORT_NATURAL);
echo "COUNTS ", json_encode($n), "\n", implode("\n", $lines), "\n";'
# The Message Log (notification_audit_log): its last id, or the events written after a given id — event names and
# counts only; no number, no text.
NAL_PHP='error_reporting(E_ALL); ini_set("display_errors", "stderr");
$db = '"$RO_PDO"';
if (!(int)$db->query("SELECT count(*) FROM sqlite_master WHERE type = \"table\" AND name = \"notification_audit_log\"")->fetchColumn()) { echo "NOTABLE\n"; exit(0); }
if ($argv[2] === "mark") { $r = $db->query("SELECT coalesce(max(id), 0), count(*) FROM notification_audit_log")->fetch(PDO::FETCH_NUM); echo "MARK ", (int)$r[0], " ", (int)$r[1], "\n"; exit(0); }
$st = $db->prepare("SELECT event, count(*) FROM notification_audit_log WHERE id > ? GROUP BY event ORDER BY event"); $st->execute([(int)$argv[3]]);
foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) echo "EVENT ", preg_replace("/[^A-Za-z0-9_.:-]/", "_", (string)$r[0]), " ", (int)$r[1], "\n";
echo "MAX ", (int)$db->query("SELECT coalesce(max(id), 0) FROM notification_audit_log")->fetchColumn(), "\n";'
# The job notifier's record (migration 075): whether it is applied, the two tables, and what the history holds — event,
# message and outcome with counts only; never a job's title, a person, a number or a text (the tables hold none).
JN_PHP='error_reporting(E_ALL); ini_set("display_errors", "stderr");
$db = '"$RO_PDO"';
$has = function ($t) use ($db) { $s = $db->prepare("SELECT count(*) FROM sqlite_master WHERE type = ? AND name = ?"); $s->execute(["table", $t]); return (int)$s->fetchColumn() > 0; };
$applied = 0;
if ($has("_migrations")) { $s = $db->prepare("SELECT count(*) FROM _migrations WHERE filename = ?"); $s->execute(["075_job_notifications.sql"]); $applied = (int)$s->fetchColumn(); }
echo "MIGRATION ", $applied, "\n";
foreach (["job_notify_state", "job_notify_events"] as $t) echo "TABLE ", $t, " ", $has($t) ? (int)$db->query("SELECT count(*) FROM " . $t)->fetchColumn() : -1, "\n";
if ($has("job_notify_events")) {
  foreach ($db->query("SELECT event, message, outcome, count(*) FROM job_notify_events GROUP BY 1, 2, 3 ORDER BY 1, 2, 3")->fetchAll(PDO::FETCH_NUM) as $r)
    echo "ROW ", preg_replace("/[^a-z_-]/", "_", (string)$r[0]), " ", $r[1] === null ? "-" : preg_replace("/[^a-z_-]/", "_", (string)$r[1]), " ", preg_replace("/[^a-z_]/", "_", (string)$r[2]), " ", (int)$r[3], "\n";
}'
probe() { docker exec -u "$DB_OWNER" "$CONTAINER" php -d display_errors=stderr -r "$@"; }
staff_snapshot() {   # $1 the file to write (mode 600); prints nothing
  probe "$STAFF_PHP" "$PDD_IN" > "$1" 2> "$1.err"; local rc=$?
  [ "$rc" = "0" ] && grep -q '^COUNTS ' "$1"
}
count_field() { sed -n 's/^COUNTS //p' "$1" | head -1 | grep -oE "\"$2\":[0-9]+" | grep -oE '[0-9]+$'; }
staff_counts() {     # the COUNTS line of a snapshot, in words
  printf '%s accounts, %s active; of the %s active accounts that take jobs, %s hold a uCRM user id (%s through a verified link, %s stored the old way) and %s only an FTTH id' \
    "$(count_field "$1" accounts)" "$(count_field "$1" active)" "$(count_field "$1" take_jobs)" "$(count_field "$1" with_id)" \
    "$(count_field "$1" verified)" "$(count_field "$1" unverified)" "$(count_field "$1" ftth_only)"
}
staff_digest() { grep '^ROW ' "$1" | sha256sum | cut -c1-16; }
staff_changed_ids() {   # the ids whose line differs between two snapshots — ids only
  diff <(grep '^ROW ' "$1") <(grep '^ROW ' "$2") | sed -nE 's/^[<>] ROW ([0-9]+) .*/\1/p' | sort -un | tr '\n' ' ' | sed 's/ $//'
}

# A1 — the tenant. Release B is Uganda's: the notifier runs only where the tenant reads Uganda.
TEN="$(probe "$TENANT_PHP" "$PDD_IN" "$IN_CONTAINER" tenant 2>&1)"
T_STORE="$(printf '%s\n' "$TEN" | sed -n 's/^store=//p' | head -1)"; T_FILES="$(printf '%s\n' "$TEN" | sed -n 's/^files=//p' | head -1)"
echo "  tenant          the configuration's store copy reads ${T_STORE:-?}; its files read ${T_FILES:-?}"
if [ "$T_STORE" = "uganda" ] && [ "$T_FILES" = "uganda" ]; then ok "A1 the installed plugin reads Uganda from both configuration sources — the job notifier will be on"
elif [ "$MODE" = "deploy" ]; then stop "NO-GO: the installed plugin does not read Uganda from both configuration sources (store ${T_STORE:-?}, files ${T_FILES:-?}) — the job rules would stay off, or apply to one path and not another"
else bad "A1 the tenant reads store ${T_STORE:-?} / files ${T_FILES:-?}, not Uganda from both"; fi

# A2 — every changed PHP file, as $EXPECTED_PLUGIN_COMMIT has it, checked by the server's own PHP before a byte is
# copied (php -l reads it from stdin). A file Git cannot produce counts as rejected, never as checked.
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

# A3 — the staff accounts as they are now (counts only), kept for stage R.
SNAP_BEFORE="$OUT/staff-$TS-before.txt"
if staff_snapshot "$SNAP_BEFORE"; then
  ok "A3 staff accounts read: $(staff_counts "$SNAP_BEFORE") — digest $(staff_digest "$SNAP_BEFORE")"
else
  bad "A3 the staff accounts could not be read: $(head -c 200 "$SNAP_BEFORE.err" | tr '\n' ' ')"
  [ "$MODE" = "deploy" ] && stop "NO-GO: without the staff snapshot stage R cannot prove the deploy left every staff account as it was"
fi

# A4 — the Message Log's last row now: stage R counts what is written after it.
NAL="$(probe "$NAL_PHP" "$PDD_IN" mark 2>&1)"
NAL_MARK="$(printf '%s\n' "$NAL" | sed -nE 's/^MARK ([0-9]+) ([0-9]+)$/\1/p')"; NAL_ROWS="$(printf '%s\n' "$NAL" | sed -nE 's/^MARK ([0-9]+) ([0-9]+)$/\2/p')"
if [ -n "$NAL_MARK" ]; then ok "A4 the Message Log holds $NAL_ROWS rows; its last is #$NAL_MARK"
elif printf '%s' "$NAL" | grep -q '^NOTABLE'; then NAL_MARK=0; note "the Message Log table does not exist yet — stage R counts from its first row"
else bad "A4 the Message Log could not be read: $(printf '%s' "$NAL" | head -c 200 | tr '\n' ' ')"; fi

# A5 — the notifier's record before the deploy: on 5.18.51 migration 075 has never run (information).
JN="$(probe "$JN_PHP" "$PDD_IN" 2>&1)"
JN_MIG="$(printf '%s\n' "$JN" | sed -n 's/^MIGRATION //p' | head -1)"
JN_S="$(printf '%s\n' "$JN" | sed -nE 's/^TABLE job_notify_state (-?[0-9]+)$/\1/p')"; JN_E="$(printf '%s\n' "$JN" | sed -nE 's/^TABLE job_notify_events (-?[0-9]+)$/\1/p')"
if [ "$JN_MIG" = "0" ] && [ "$JN_S" = "-1" ] && [ "$JN_E" = "-1" ]; then echo "  …     migration 075 has not run here: no job_notify_state, no job_notify_events (as expected on $BASELINE_VERSION)"
elif [ -n "$JN_MIG" ]; then echo "  …     migration 075 recorded ${JN_MIG}×; job_notify_state ${JN_S:-?} rows, job_notify_events ${JN_E:-?} rows ($([ "$LIVE_BEFORE" = "$EXPECTED_PLUGIN_COMMIT" ] && echo "$EXPECTED_VERSION is installed" || echo "left by an earlier $EXPECTED_VERSION"))"
else note "the notifier's record could not be read: $(printf '%s' "$JN" | head -c 200 | tr '\n' ' ')"; fi

# ── The backup (deploy and rollback) ─────────────────────────────────────────
BK=""
if [ "$MODE" = "deploy" ] || { [ "$MODE" = "rollback" ] && [ "$LIVE_BEFORE" = "$EXPECTED_PLUGIN_COMMIT" ]; }; then
  hdr "A. Backup"
  BK="$OUT/backup-$TS"; mkdir -p "$BK"; chmod 700 "$BK"
  # As deploy-5.18.49.sh: each live database copied as of one moment by SQLite itself (VACUUM INTO, as its owner),
  # checked and compared on both sides; tar takes the rest of each directory and says why whenever it exits non-zero.
  SNAP_DBS="plugin.sqlite3 dishnet.sqlite"
  SNAP_TMP="${DNB_SNAPSHOT_TMP:-/tmp}"
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
    if [ "$d" -ef "$DATA_HOST" ]; then
      for db in $SNAP_DBS; do for s in "" -wal -shm -journal; do ex+=("--exclude=$(basename "$d")/$db$s"); done; done
      why=" — without the live databases, copied above"
    fi
    tar -C "$(dirname "$d")" ${ex[@]+"${ex[@]}"} -czf "$BK/$n.tar.gz" "$(basename "$d")" 2> "$BK/$n.tar.err"; rc=$?
    if [ "$rc" -le 1 ] && tar -tzf "$BK/$n.tar.gz" >/dev/null 2>&1; then
      ok "backed up $d → $BK/$n.tar.gz ($(du -h "$BK/$n.tar.gz" | cut -f1))$why"
      [ "$rc" = "1" ] && { note "while tar read $d, $(grep -c . "$BK/$n.tar.err") file(s) changed or went away (tar exit 1: logs and the like, archived as tar found them). It said:"
        grep . "$BK/$n.tar.err" | head -5 | mask | cut -c1-200 | sed 's/^/          /'; }
    else
      bad "backup of $d failed (tar exit $rc$([ "$rc" -le 1 ] && printf '; the archive it wrote cannot be read back')). It said:"
      grep . "$BK/$n.tar.err" | head -5 | mask | cut -c1-200 | sed 's/^/          /'
    fi
  done
  # The installed plugin itself, as it is now — a restore that needs no Git — and the configuration vault beside it
  # (read, never changed; it holds keys, so it stays in this root-only directory).
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
  cp "$SNAP_BEFORE" "$BK/staff-before.txt" 2>/dev/null || true
  chmod -R go-rwx "$BK"
  if [ -x "$REPO/scripts/verify-uisp-health.sh" ]; then
    if timeout 120 bash "$REPO/scripts/verify-uisp-health.sh" > "$BK/health-before.txt" 2>&1; then ok "UISP health recorded → $BK/health-before.txt"; else note "verify-uisp-health.sh did not pass — recorded in $BK/health-before.txt"; fi
  fi
  [ "$FAIL" = "0" ] || stop "NO-GO: the backup or a before-check did not complete"
  echo; echo "  GO — evidence recorded, backup in $BK"
  if [ "$MODE" = "deploy" ]; then echo "  The rollback command is printed at the end of this log, on its own."
  else echo "  Deploy again at any time:  cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh"; fi
fi

# The documented rollback: check out 5.18.51, run the documented deploy of it, verify, return the checkout.
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
  local live; live="$(live_commit)"
  echo "  the container now serves ${live:-?}; the checkout is back on $ref"
  [ "$rc" = "0" ] && [ "$live" = "$BASELINE_COMMIT" ]
}

DEPLOY_STARTED=""
# ════════════════════════════════════════════════════════
if [ "$MODE" = "deploy" ]; then
  hdr "B. The documented deploy (scripts/deploy-hybrid.sh)"
  # ══════════════════════════════════════════════════════
  printf '  Type DEPLOY to deploy %s (%s) over live %s, anything else to stop: ' "$EXPECTED_VERSION" "$EXPECTED_PLUGIN_COMMIT" "${LIVE_BEFORE:-?}"
  read -r ANSWER </dev/tty || ANSWER=""; echo
  [ "$ANSWER" = "DEPLOY" ] || stop "not confirmed"
  DEPLOY_STARTED="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  # The Message Log's last row and the staff accounts again, at the last moment: the backup took a while and the old
  # code kept working meanwhile. Stage R counts from here, and a later --after-only run from the same point.
  NAL="$(probe "$NAL_PHP" "$PDD_IN" mark 2>&1)"; M="$(printf '%s\n' "$NAL" | sed -nE 's/^MARK ([0-9]+) [0-9]+$/\1/p')"
  [ -n "$M" ] && NAL_MARK="$M"
  S="$OUT/staff-$TS-deploy.txt"; staff_snapshot "$S" && SNAP_BEFORE="$S"
  printf 'DEPLOYED_AT=%s\nNAL_MARK=%s\nSNAP_BEFORE=%s\nFROM_COMMIT=%s\n' "$DEPLOY_STARTED" "${NAL_MARK:-}" "$SNAP_BEFORE" "$LIVE_BEFORE" > "$STATE"
  echo "  marked: the Message Log's last row is #${NAL_MARK:-?}; staff digest $(staff_digest "$SNAP_BEFORE") — recorded in $STATE"
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
    S="$OUT/staff-$TS-rollback.txt"; staff_snapshot "$S" && SNAP_BEFORE="$S"   # at the last moment, as a deploy does
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
  [ -n "$DEPLOY_STARTED" ] || DEPLOY_STARTED="$(date -u -d '-10 minutes' +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || date -u +%Y-%m-%dT%H:%M:%SZ)"
fi

# ════════════════════════════════════════════════════════
hdr "V. Verification over the public address and the :8443 door (nothing signed in; no customer touched)"
# ════════════════════════════════════════════════════════
# V1 — the public address is never redirected: the loop check comes FIRST, and a redirect after a deploy rolls back.
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
LOC="$(printf '%s' "$HTTP_HEADERS" | tr -d '\r' | grep -i '^location:' | head -1 | sed -E 's/^[^:]*: *//')"
case "$LOC" in *:8443*) bad "V1 its Location carries :8443 ($LOC)";; *) ok "V1 its Location carries no :8443";; esac

# V2 — the tenant on the public pages, as every deploy since 5.18.42 has checked them.
http GET "$PLUGIN_BASE?page=customer_login"
[ "$HTTP_CODE" = "200" ] && ok "V2 the sign-in page answers 200" || bad "V2 sign-in page → $HTTP_CODE"
[ "$(count '+211')" = "0" ] && [ "$(count 'dishnetafrica.com')" = "0" ] && ok "V2 the sign-in page carries no South Sudan contact" || bad "V2 the sign-in page: '+211' ×$(count '+211') · dishnetafrica.com ×$(count 'dishnetafrica.com')"
http GET "$PLUGIN_BASE?page=terms"
[ "$HTTP_CODE" = "200" ] && ok "V2 the Terms page answers 200" || bad "V2 Terms page → $HTTP_CODE"
if [ "$(count '+211')" = "0" ] && [ "$(count 'dishnetafrica.com')" = "0" ] && [ "$(count 'wa.me/211')" = "0" ]; then ok "V2 the Terms page's contacts and footer carry no South Sudan literal (A1.1)"; else bad "V2 the Terms page: '+211' ×$(count '+211') · dishnetafrica.com ×$(count 'dishnetafrica.com') · wa.me/211 ×$(count 'wa.me/211')"; fi
[ "$(count 'wa.me/256705993348')" != "0" ] && [ "$(count 'Kampala, Uganda')" != "0" ] && ok "V2 the Terms page carries the Uganda WhatsApp link and locality" || bad "V2 the Terms page: wa.me/256705993348 ×$(count 'wa.me/256705993348') · 'Kampala, Uganda' ×$(count 'Kampala, Uganda')"
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
if [ "$HTTP_CODE" = "200" ] && printf '%s' "$HTTP_BODY" | grep -q '"tos_version":"1.1"' && printf '%s' "$HTTP_BODY" | grep -q '"privacy_version":"1.1"'; then ok "V2 app_legal_version answers 1.1 / 1.1 — nobody is asked to accept the terms again"
else bad "V2 app_legal_version → $HTTP_CODE $(printf '%s' "$HTTP_BODY" | cut -c1-160)"; fi

# V3 — the :8443 door (self-signed certificate: -k is for THIS check only).
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

# V4 — no fatal of the plugin in the container log since the deploy (a short guard window). 5.18.51 removed the
# master's lock fatal, so that line counts here too.
if [ "$MODE" != "after" ] && [ -n "$BK" ]; then
  printf '  …     waiting %ss for the first requests on the code now installed\n' "$GUARD_SECONDS"; sleep "$GUARD_SECONDS"
fi
V4_LINES="$(log_since "$DEPLOY_STARTED" | grep -E 'UNCAUGHT|FATAL|PHP Fatal|PHP Parse error' | grep -F -- "$PLUGIN" || true)"
N_FATAL="$(printf '%s\n' "$V4_LINES" | grep -c . || true)"
if [ "${N_FATAL:-0}" = "0" ]; then ok "V4 no fatal or parse error of $PLUGIN in the container log since $DEPLOY_STARTED"
else bad "V4 ${N_FATAL} fatal line(s) of $PLUGIN since $DEPLOY_STARTED:"; printf '%s\n' "$V4_LINES" | cut -c1-200 | head -5 | sed 's/^/     /'; fi

# The staff accounts now, against stage A (a deploy) or against the deploy's own record (a later --after-only run).
SNAP_AFTER="$OUT/staff-$TS-after.txt"
REF_SNAP="$SNAP_BEFORE"; REF_WHAT="stage A of this run"
if [ "$MODE" = "after" ] && [ -f "$STATE" ]; then
  s="$(sed -n 's/^SNAP_BEFORE=//p' "$STATE" | head -1)"; [ -f "$s" ] && { REF_SNAP="$s"; REF_WHAT="the deploy's stage A ($(sed -n 's/^DEPLOYED_AT=//p' "$STATE" | head -1))"; }
fi
staff_compare() {   # $1 label. Across a deploy or a rollback a change is a failure; later, links saved since are expected.
  if ! staff_snapshot "$SNAP_AFTER"; then bad "$1 the staff accounts could not be read again: $(head -c 200 "$SNAP_AFTER.err" | tr '\n' ' ')"; return; fi
  if [ ! -s "$REF_SNAP" ]; then note "$1 no earlier staff snapshot to compare with"; return; fi
  if [ "$(staff_digest "$REF_SNAP")" = "$(staff_digest "$SNAP_AFTER")" ]; then
    ok "$1 every staff account is as it was at $REF_WHAT: role, status, e-mail, phone, uCRM user id and link (digest $(staff_digest "$SNAP_AFTER"), $(count_field "$SNAP_AFTER" accounts) accounts)"
  elif [ "$MODE" = "after" ]; then
    note "$1 staff accounts changed since $REF_WHAT — account id(s): $(staff_changed_ids "$REF_SNAP" "$SNAP_AFTER") (a link saved through the picker, or an edit, counts here)"
  else
    bad "$1 staff accounts changed during the $MODE — account id(s): $(staff_changed_ids "$REF_SNAP" "$SNAP_AFTER"). Nothing in this release writes one: if a person edited or linked an account meanwhile, say so when you send the log"
  fi
}

if [ "$MODE" != "rollback" ]; then
# ════════════════════════════════════════════════════════
hdr "R. $EXPECTED_VERSION installed (read-only)"
# ════════════════════════════════════════════════════════
# R1 — every file 5.18.52 changes is installed byte for byte as the pinned commit has it.
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
# …and every other file Release A and 5.18.51 changed is installed as the pinned commit has it. A file the pinned
# commit no longer has (removed or renamed since) is not compared: stage A lists it, and it stays on the server.
RA_OK=0; RA_BAD=""
for f in $(git -C "$REPO" diff --no-renames --name-only --diff-filter=AM "$RELEASE_A_BASE" "$BASELINE_COMMIT" -- "$PLUGIN"); do
  rel="${f#"$PLUGIN"/}"; [ "$rel" = "manifest.json" ] && continue
  printf '%s\n' "$CHANGED" | grep -qxF "$f" && continue
  git -C "$REPO" cat-file -e "$EXPECTED_PLUGIN_COMMIT:$f" 2>/dev/null || continue
  a="$(git -C "$REPO" show "$EXPECTED_PLUGIN_COMMIT:$f" 2>/dev/null | sha256sum | cut -c1-64)"
  b="$(sha256sum "$DEST/$rel" 2>/dev/null | cut -c1-64)"
  if [ -n "$b" ] && [ "$a" = "$b" ]; then RA_OK=$((RA_OK+1)); else RA_BAD="$RA_BAD $rel"; fi
done
[ -z "$RA_BAD" ] && ok "R1 the $RA_OK other files of Release A and 5.18.51 are installed exactly as $EXPECTED_PLUGIN_COMMIT has them" \
  || bad "R1 Release A or 5.18.51 files that differ from $EXPECTED_PLUGIN_COMMIT:$RA_BAD"

# R2 — the switch, as the installed plugin reads it from both configuration sources.
GATE="$(probe "$TENANT_PHP" "$PDD_IN" "$IN_CONTAINER" gate 2>&1)"
G_STORE="$(printf '%s\n' "$GATE" | sed -n 's/^store=//p' | head -1)"; G_FILES="$(printf '%s\n' "$GATE" | sed -n 's/^files=//p' | head -1)"
if [ "$G_STORE" = "on" ] && [ "$G_FILES" = "on" ]; then ok "R2 the installed StaffJobsGate reads Uganda from both configuration sources: the job notifier is on (store $G_STORE, files $G_FILES)"
else bad "R2 the installed StaffJobsGate reads store ${G_STORE:-?} / files ${G_FILES:-?} — the job rules are not on everywhere"; fi

# R3 — no staff account was changed by the deploy (release B writes none).
staff_compare "R3"

# R4 — the Message Log since the deploy: the old job paths stay silent on Uganda; release B's messages are counted
# (they go only when someone creates or changes a job — the deploy itself sends nothing).
MARK="$NAL_MARK"
[ "$MODE" = "after" ] && [ -f "$STATE" ] && MARK="$(sed -n 's/^NAL_MARK=//p' "$STATE" | head -1)"
if [ -n "$MARK" ]; then
  EV="$(probe "$NAL_PHP" "$PDD_IN" since "$MARK" 2>&1)"
  if printf '%s\n' "$EV" | grep -q '^MAX '; then
    N_OLD="$(printf '%s\n' "$EV" | awk -v re="$OLD_RE" '$1=="EVENT" && $2 ~ re {n+=$3} END {print n+0}')"
    N_B="$(printf '%s\n' "$EV" | awk -v re="$B_RE" '$1=="EVENT" && $2 ~ re {n+=$3} END {print n+0}')"
    N_ALL="$(printf '%s\n' "$EV" | awk '$1=="EVENT" {n+=$3} END {print n+0}')"
    if [ "$N_OLD" = "0" ]; then ok "R4 the old ＋ New Job, Bulk Dispatch and Reschedule messages stay off since row #$MARK (0)"
    else bad "R4 $N_OLD message(s) from the old job paths since row #$MARK — only the job notifier may send on Uganda:"; printf '%s\n' "$EV" | awk -v re="$OLD_RE" '$1=="EVENT" && $2 ~ re {print "       " $2 " ×" $3}'; fi
    if [ "$N_B" = "0" ]; then echo "  …     release B's job messages since row #$MARK: none (nobody created or changed a job)"
    else note "R4 release B's job messages since row #$MARK: $N_B — someone created, changed or accepted a job:"; printf '%s\n' "$EV" | awk -v re="$B_RE" '$1=="EVENT" && $2 ~ re {print "          " $2 " ×" $3}'; fi
    echo "  …     since row #$MARK the Message Log has $N_ALL new row(s)$( [ "$N_ALL" != "0" ] && printf ', by event:')"
    printf '%s\n' "$EV" | awk '$1=="EVENT" {print "          " $2 " ×" $3}'
  elif printf '%s' "$EV" | grep -q '^NOTABLE'; then note "R4 the Message Log table does not exist — nothing was sent at all"
  else bad "R4 the Message Log could not be read: $(printf '%s' "$EV" | head -c 200 | tr '\n' ' ')"; fi
else note "R4 there is no record of where the deploy started (state-$EXPECTED_VERSION.env) — the Message Log is not counted"; fi

# R5 — the other job-assignment path stays off: the master cron's job_assign entry is still commented out.
if [ "$(grep -cE "^[[:space:]]*'job_assign'[[:space:]]*=>" "$DEST/cron/master.php" 2>/dev/null)" = "0" ] \
   && [ "$(grep -cE "^[[:space:]]*//[[:space:]]*'job_assign'[[:space:]]*=>" "$DEST/cron/master.php" 2>/dev/null)" = "1" ]; then
  ok "R5 the master cron's job_assign entry is still commented out (path A stays off)"
else bad "R5 the installed cron/master.php no longer has its job_assign entry commented out"; fi

# R6 — the job notifier in the installed files: every caller, the sign-in return, and what the screens say.
R6_MISS=""
for pair in "webhook.php|function whJobNotify(" "webhook.php|case 'job.edit':" "includes/api/api_scheduling.php|\$sjNotifier()->observe(\$newJobId, 'my_jobs'" \
            "includes/api/api_scheduling.php|\$sjNotifier()->observe(\$newJobId, 'bulk'" "includes/api/api_scheduling.php|\$sjNotifier()->observe(\$jobId, 'reschedule'" \
            "includes/api/api_scheduling.php|\$sjNotifier()->accepted(" "public.php|JobReturn::remember(" "includes/post/post_auth.php|JobReturn::take(" \
            "tabs/support/scheduling.php|The engineer gets a WhatsApp message"; do
  grep -qF -- "${pair#*|}" "$DEST/${pair%%|*}" 2>/dev/null || R6_MISS="$R6_MISS ${pair%%|*}:${pair#*|}"
done
[ -z "$R6_MISS" ] && ok "R6 the job notifier is wired in: New Job, Bulk Dispatch, Reschedule, Accept, uCRM's job.add/job.edit/job.delete, the sign-in return; ＋ New Job says the engineer gets a WhatsApp message" \
  || bad "R6 missing from the installed files:$R6_MISS"
grep -qF 'No WhatsApp message is sent for jobs yet' "$DEST/tabs/support/scheduling.php" 2>/dev/null \
  && bad "R6 the installed New Job screen still says no WhatsApp message is sent" || ok "R6 the old \"No WhatsApp message is sent for jobs yet\" is gone from the screen"

# R7 — who gets the messages (M7): only an account whose uCRM user was saved through the verified picker.
NV="$(count_field "$SNAP_AFTER" verified)"; NT="$(count_field "$SNAP_AFTER" take_jobs)"; NU="$(count_field "$SNAP_AFTER" unverified)"; NF="$(count_field "$SNAP_AFTER" ftth_only)"
if [ -n "$NT" ]; then
  note "R7 $NV of $NT active accounts that take jobs have a verified uCRM link: only they receive job messages. The other $((NT - NV)) get none (logged \"WhatsApp skipped: no staff account is linked\") until an admin saves their uCRM user through the picker (Staff → edit → uCRM user); ${NU:-0} of them hold an id stored the old way and ${NF:-0} only an FTTH id — neither counts on Uganda (M7)"
fi

# R8 — 5.18.51's fix is kept: the master releases its lock only while the handle is still open.
if grep -qF 'if (is_resource($lockFp)) {' "$DEST/cron/master.php" 2>/dev/null; then ok "R8 the installed master.php keeps 5.18.51's lock guard (is_resource)"
else bad "R8 the installed master.php has lost 5.18.51's is_resource() guard on its lock release"; fi

# R9 — migration 075, applied by the plugin itself at the first request the new code served (stage V made some).
JN="$(probe "$JN_PHP" "$PDD_IN" 2>&1)"
JN_MIG="$(printf '%s\n' "$JN" | sed -n 's/^MIGRATION //p' | head -1)"
JN_S="$(printf '%s\n' "$JN" | sed -nE 's/^TABLE job_notify_state (-?[0-9]+)$/\1/p')"; JN_E="$(printf '%s\n' "$JN" | sed -nE 's/^TABLE job_notify_events (-?[0-9]+)$/\1/p')"
if [ "$JN_MIG" = "1" ] && [ -n "$JN_S" ] && [ "$JN_S" != "-1" ] && [ -n "$JN_E" ] && [ "$JN_E" != "-1" ]; then
  ok "R9 migration 075 is applied: job_notify_state holds $JN_S job(s), job_notify_events $JN_E row(s)"
  printf '%s\n' "$JN" | awk '$1=="ROW" {print "          " $2 " / " $3 " / " $4 " ×" $5}'
else
  bad "R9 migration 075 is not in place (recorded ${JN_MIG:-?}×; job_notify_state ${JN_S:-?}, job_notify_events ${JN_E:-?}) — the notifier sends nothing without its record"
  docker exec -u "$DB_OWNER" "$CONTAINER" sh -c "grep -F 075_job_notifications '$PDD_IN/migration.log' 2>/dev/null | tail -3" | cut -c1-200 | sed 's/^/     /'
fi

# R10 — the link the messages carry: signed out, it asks for the sign-in and shows no job (the page keeps only the
# job's number, for after the sign-in).
http GET "$PLUGIN_BASE?page=dashboard&tab=scheduling&job=1"
if [ "$HTTP_CODE" = "302" ] && [ "$HTTP_LOCATION" = "$PLUGIN_BASE?page=login" ]; then ok "R10 a job link opened signed out → 302 → the staff sign-in page, and no job shown"
else bad "R10 a job link opened signed out → $HTTP_CODE ${HTTP_LOCATION:+→ $HTTP_LOCATION} (expected 302 → $PLUGIN_BASE?page=login)"; fi
else
# ════════════════════════════════════════════════════════
hdr "RB. $BASELINE_VERSION, back in place (read-only)"
# ════════════════════════════════════════════════════════
RB_OK=0; RB_BAD=""
for f in $CHANGED; do
  printf '%s\n' "$ADDED" | grep -qxF "$f" && continue
  rel="${f#"$PLUGIN"/}"
  a="$(git -C "$REPO" show "$BASELINE_COMMIT:$f" 2>/dev/null | sha256sum | cut -c1-64)"
  b="$(sha256sum "$DEST/$rel" 2>/dev/null | cut -c1-64)"
  if [ -n "$b" ] && [ "$a" = "$b" ]; then RB_OK=$((RB_OK+1)); else RB_BAD="$RB_BAD $rel"; fi
done
[ -z "$RB_BAD" ] && ok "RB1 all $RB_OK files $EXPECTED_VERSION had changed are back exactly as $BASELINE_COMMIT ($BASELINE_VERSION) has them" \
  || bad "RB1 installed files that differ from $BASELINE_COMMIT:$RB_BAD"
IV="$(grep -o '"version": *"5[^"]*"' "$DEST/manifest.json" | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/')"
[ "$IV" = "$BASELINE_VERSION" ] && ok "RB1 the installed manifest says $IV" || bad "RB1 the installed manifest says ${IV:-?}"
# The files 5.18.52 added stay (deploy-hybrid.sh never deletes); nothing in 5.18.51 loads them.
N_LEFT=0; USED=""
for f in $ADDED; do
  rel="${f#"$PLUGIN"/}"; [ -f "$DEST/$rel" ] && N_LEFT=$((N_LEFT+1))
  case "$rel" in lib/*.php)
    cls="$(basename "$rel" .php)"
    for u in $(grep -rlE "\\b$cls\\b" "$DEST" --include='*.php' 2>/dev/null | grep -v "^$DEST/tests/" | grep -vxF "$DEST/$rel"); do
      printf '%s\n' "$ADDED" | grep -qxF "$PLUGIN/${u#"$DEST"/}" || USED="$USED ${u#"$DEST"/}→$cls"
    done ;;
  esac
done
[ -z "$USED" ] && ok "RB2 the $N_LEFT file(s) $EXPECTED_VERSION added are still on disk and inert: no $BASELINE_VERSION file loads them" \
  || bad "RB2 $BASELINE_VERSION files name a class $EXPECTED_VERSION added:$USED"
staff_compare "RB3"
note "on $BASELINE_VERSION again: no job WhatsApp message on Uganda (M6). Migration 075's two tables and their rows stay; $BASELINE_VERSION never reads them. Jobs created Open meanwhile stay Open in uCRM"
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
[ -n "$BK" ] && echo "  the data          this release adds two tables and changes no row: a rollback needs no restore. The database copies are there for a restore on instruction only"
if [ "$MODE" != "rollback" ]; then
  echo "  job messages      on Uganda each job change sends one WhatsApp message, to the engineer uCRM has on the job: message 1 with the ACCEPT JOB link, a new time, \"no longer assigned\", \"cancelled\"; after Accept, message 2 with the completion link"
  echo "  who gets them     only accounts with a verified uCRM link (R7); everyone else's job is logged \"WhatsApp skipped\" in WA Events"
  echo "  later             cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --after-only   re-measures R3, R4 and R9 since this deploy; send its log file"
fi
echo "  checks            $PASS ok, $FAIL failed, $NOTE notes"
if [ "$FAIL" = "0" ]; then echo; echo "  $EXPECTED_VERSION ($MODE): PASSED. Send this LOG FILE back (not a copy of the terminal)."
else echo; echo "  $EXPECTED_VERSION ($MODE): $FAIL FAILED — send the log file; do not roll back on your own unless staff or customers are affected."; fi
if [ "$MODE" != "rollback" ]; then
  echo
  echo "  Only if it is ever needed — its own command, never pasted together with the deploy (docs/44 §16.9) —"
  echo "  the rollback to $BASELINE_VERSION ($BASELINE_COMMIT) asks you to type ROLLBACK before it changes anything:"
  echo "      cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --rollback"
  echo "  or by hand, if this script cannot run:"
  echo "      cd $REPO && git checkout $BASELINE_COMMIT && bash scripts/deploy-hybrid.sh && git checkout -"
fi
[ "$FAIL" = "0" ]
}

# The whole script is read before it runs: a rollback checks out a commit in which this file does not exist.
main "$@"; exit $?
