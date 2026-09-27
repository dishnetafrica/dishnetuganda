#!/usr/bin/env bash
#
# deploy-5.18.50.sh — deploy plugin 5.18.50, Release A of docs/44 (staff and jobs, Uganda only), or roll it back.
#
#   5.18.50  Release A, as approved on 27 Sep (docs/44 §15–§16): the staff app and its jobs follow Uganda's own rules.
#            J1  the South Sudan staff lists stop rewriting Uganda accounts
#            J2  My Jobs, job detail and every job action on Uganda use ONLY a uCRM user link saved through the new,
#                checked picker (M7) — never ftth_crm_client_id, never an id stored the old way
#            J3  a staff phone number is read in the tenant's international form, never rewritten
#            J5  job times in Kampala time
#            J6  who may act on a job: the verified assignee, a support leader or an admin; an inactive or demoted
#                account is refused at once (D7)
#            M6  NO job-assignment WhatsApp message on Uganda: New Job, Bulk Dispatch, Reschedule and jobs created in
#                uCRM send none, and say so. Accept, task-progress and completion messages are unchanged
#            J7  WA Events and the Message Log say "sent", never "delivered", and count what was suppressed
#            J8  a WhatsApp message from a staff number is not treated as a customer's (M1)
#   South Sudan: unchanged, byte for byte (tests/test_staff_jobs_south_sudan.php).
#   Excluded, as approved: billing, invoices, payments, customer records and their workflows; the retailer wallet
#   top-up / uCRM client creation. Release B (job-assignment messages with approved wording) is NOT in this build.
#
# Run as root on the server, then send back THE LOG FILE (never a copy of the terminal):
#
#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg \
#     && mkdir -p /root/dnb-5.18.50 \
#     && bash scripts/deploy-5.18.50.sh 2>&1 | tee /root/dnb-5.18.50/deploy-$(date -u +%Y%m%dT%H%M%SZ).log
#
# Roll back to 5.18.49 (e076632) — the same backup first, then the documented deploy of that commit:
#
#   cd /opt/dishnet && mkdir -p /root/dnb-5.18.50 \
#     && bash scripts/deploy-5.18.50.sh --rollback 2>&1 | tee /root/dnb-5.18.50/rollback-$(date -u +%Y%m%dT%H%M%SZ).log
#
#   By hand, if this script cannot run:  cd /opt/dishnet && git checkout e076632 && bash scripts/deploy-hybrid.sh
#   (then `git checkout -` to return the checkout to the branch before the next pull).
#
# Options
#   --after-only         the deploy already happened: run the checks again (later runs measure stage R since the deploy)
#   --rollback           put 5.18.49 back (typed ROLLBACK), then check it
#   --plugin-base <url>  the public URL of public.php, if the derived one is wrong
#
# What it never does: touch a configuration value, a customer, a staff account, a job, a message, the webhook key,
# Traefik, UISP or the website; send anything; sign anyone in. Every read of the plugin's data opens the database
# READ-ONLY as the database's owner. The writes are the documented deploy (or rollback), a backup under
# /root/dnb-5.18.50/ (its database copies pass through the container's /tmp and are removed there at once), a record
# of where the deploy started (state-5.18.50.env beside the logs) — and, only if stage V finds the public address
# redirecting after a deploy, the rollback.
#
# Stages:  A before-evidence, the Uganda check, the syntax check under the server's own PHP, the staff snapshot,
#            the Message Log mark, the backup → GO/NO-GO
#          B the documented deploy (or, with --rollback, the documented deploy of 5.18.49)
#          V the public pages, the :8443 door and the loop check; no fatal since the deploy
#          R Release A installed: the files, the Uganda switch, staff records untouched, no job-assignment message,
#            the verified links    (RB after a rollback: 5.18.49's files back, staff records untouched)
#          F summary
set -uo pipefail
umask 077

main() {
PLUGIN="dishnet-hybrid-sudan"
CONTAINER="${UCRM_CONTAINER:-ucrm}"
EXPECTED_PLUGIN_COMMIT="125fa0c"   # 5.18.50 — the plugin-scoped commit deploy-hybrid.sh records
EXPECTED_VERSION="5.18.50"
BASELINE_COMMIT="e076632"          # 5.18.49 — what the server runs, what Release A was built and tested against, the rollback
BASELINE_VERSION="5.18.49"
BRANCH="claude/study-this-jhe2eg"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$REPO/$PLUGIN"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="${DNB_OUT:-/root/dnb-5.18.50}"; mkdir -p "$OUT"; chmod 700 "$OUT"
STATE="$OUT/state-$EXPECTED_VERSION.env"
GUARD_SECONDS="${GUARD_SECONDS:-60}"
# The events of the four job-assignment paths (docs/44 M6): ＋ New Job and Bulk Dispatch, Reschedule, a job created in
# uCRM. Any suffix counts (…_suppressed_optout, an admin copy).
ASSIGN_RE='^(ops_scheduling_job_assigned|ops_scheduling_rescheduled|job_assigned)'

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

hdr "$EXPECTED_VERSION ($MODE) — $TS — $(hostname)"

# ════════════════════════════════════════════════════════
hdr "A. Before-evidence (read-only)"
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
git -C "$REPO" cat-file -e "$BASELINE_COMMIT^{commit}" 2>/dev/null || stop "the checkout does not hold $BASELINE_COMMIT ($BASELINE_VERSION) — the rollback commit must be present before anything changes"

# What Release A changes, from Git — never from a list typed into this script.
CHANGED="$(git -C "$REPO" diff --name-only --diff-filter=AMR "$BASELINE_COMMIT" "$EXPECTED_PLUGIN_COMMIT" -- "$PLUGIN")"
ADDED="$(git -C "$REPO" diff --name-only --diff-filter=A "$BASELINE_COMMIT" "$EXPECTED_PLUGIN_COMMIT" -- "$PLUGIN")"
DELETED="$(git -C "$REPO" diff --name-only --diff-filter=D "$BASELINE_COMMIT" "$EXPECTED_PLUGIN_COMMIT" -- "$PLUGIN")"
N_CHANGED="$(printf '%s\n' "$CHANGED" | grep -c . || true)"; N_ADDED="$(printf '%s\n' "$ADDED" | grep -c . || true)"
echo "  Release A       $N_CHANGED files differ from $BASELINE_COMMIT: $((N_CHANGED - N_ADDED)) changed, $N_ADDED added, $(printf '%s\n' "$DELETED" | grep -c . || true) removed"
[ "$N_CHANGED" -gt 0 ] || stop "Git reports no file between $BASELINE_COMMIT and $EXPECTED_PLUGIN_COMMIT"
[ -z "$DELETED" ] || note "files removed in $EXPECTED_VERSION stay on the server (deploy-hybrid.sh never deletes): $(printf '%s ' $DELETED)"

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

case "$MODE" in
  deploy)
    if [ "$LIVE_BEFORE" = "$EXPECTED_PLUGIN_COMMIT" ]; then note "the container already serves $EXPECTED_PLUGIN_COMMIT — skipping the deploy, running the checks"; MODE="after"
    elif [ "$LIVE_BEFORE" != "$BASELINE_COMMIT" ]; then
      stop "NO-GO: the container serves ${LIVE_BEFORE:-an unknown commit}; Release A was built and tested against $BASELINE_COMMIT ($BASELINE_VERSION) — send this log before anything is deployed"
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
case "$PHPV" in 5.*|7.*) stop "the container's PHP is $PHPV; 5.18.50 needs 8.0 or later (as 5.18.49 does)";; esac

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
# The staff accounts: one line per account — its id and a digest of the fields Release A reads (role, admin, active,
# e-mail, phone, uCRM user id, FTTH id, the verified link) — and the counts. No name, e-mail or number leaves the
# container; the digests are salted and cut.
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

# A1 — the tenant. Release A is Uganda's: its rules switch on only where the tenant reads Uganda.
TEN="$(probe "$TENANT_PHP" "$PDD_IN" "$IN_CONTAINER" tenant 2>&1)"
T_STORE="$(printf '%s\n' "$TEN" | sed -n 's/^store=//p' | head -1)"; T_FILES="$(printf '%s\n' "$TEN" | sed -n 's/^files=//p' | head -1)"
echo "  tenant          the configuration's store copy reads ${T_STORE:-?}; its files read ${T_FILES:-?}"
if [ "$T_STORE" = "uganda" ] && [ "$T_FILES" = "uganda" ]; then ok "A1 the installed plugin reads Uganda from both configuration sources — Release A's rules will be on"
elif [ "$MODE" = "deploy" ]; then stop "NO-GO: the installed plugin does not read Uganda from both configuration sources (store ${T_STORE:-?}, files ${T_FILES:-?}) — Release A would stay off, or apply to one path and not another"
else bad "A1 the tenant reads store ${T_STORE:-?} / files ${T_FILES:-?}, not Uganda from both"; fi

# A2 — every changed PHP file, checked by the server's own PHP before a byte is copied (php -l reads it from stdin).
if [ "$MODE" = "deploy" ]; then
  L_OK=0; L_TOK=0; L_BAD=""; L_TBAD=""
  for f in $(printf '%s\n' "$CHANGED" | grep '\.php$'); do
    if docker exec -i "$CONTAINER" php -l < "$REPO/$f" 2>&1 | grep -q '^No syntax errors detected'; then
      case "$f" in "$PLUGIN"/tests/*) L_TOK=$((L_TOK+1));; *) L_OK=$((L_OK+1));; esac
    else case "$f" in "$PLUGIN"/tests/*) L_TBAD="$L_TBAD ${f#"$PLUGIN"/}";; *) L_BAD="$L_BAD ${f#"$PLUGIN"/}";; esac; fi
  done
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
  # 5.18.50: the installed plugin itself, as it is now — a restore that needs no Git — and the configuration vault
  # beside it (read, never changed; it holds keys, so it stays in this root-only directory).
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
  if [ "$MODE" = "deploy" ]; then
    echo "  Rollback at any time:  cd $REPO && bash scripts/deploy-5.18.50.sh --rollback"
    echo "        or by hand:      cd $REPO && git checkout $BASELINE_COMMIT && bash scripts/deploy-hybrid.sh && git checkout -"
  else
    echo "  Deploy again at any time:  cd $REPO && bash scripts/deploy-5.18.50.sh"
  fi
fi

# The documented rollback: check out 5.18.49, run the documented deploy of it, verify, return the checkout.
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
  bash "$REPO/scripts/deploy-hybrid.sh" 2>&1 | sed 's/^/     /'; RC=${PIPESTATUS[0]}
  if [ "$RC" = "2" ]; then
    note "the container was still restarting; waiting for it"
    for i in 1 2 3 4 5 6 7 8 9 10 11 12; do
      sleep 10
      if bash "$REPO/scripts/deploy-hybrid.sh" --check 2>&1 | grep -q 'Up to date'; then RC=0; break; fi
    done
  fi
  LIVE_AFTER="$(live_commit)"
  if [ "$RC" = "0" ] && [ "$LIVE_AFTER" = "$EXPECTED_PLUGIN_COMMIT" ]; then ok "container serves $LIVE_AFTER"
  else stop "deploy did not verify (rc=$RC, live=${LIVE_AFTER:-?}). Roll back: cd $REPO && bash scripts/deploy-5.18.50.sh --rollback"; fi
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

# V4 — no fatal of the plugin in the container log since the deploy (a short guard window).
if [ "$MODE" != "after" ] && [ -n "$BK" ]; then
  printf '  …     waiting %ss for the first requests on the code now installed\n' "$GUARD_SECONDS"; sleep "$GUARD_SECONDS"
fi
N_FATAL="$(docker logs "$CONTAINER" --since "$DEPLOY_STARTED" 2>&1 | grep -E 'UNCAUGHT|FATAL|PHP Fatal|PHP Parse error' | grep -c "$PLUGIN" || true)"
if [ "${N_FATAL:-0}" = "0" ]; then ok "V4 no fatal or parse error of $PLUGIN in the container log since $DEPLOY_STARTED"
else bad "V4 ${N_FATAL} fatal line(s) of $PLUGIN since $DEPLOY_STARTED:"; docker logs "$CONTAINER" --timestamps --since "$DEPLOY_STARTED" 2>&1 | grep -E 'UNCAUGHT|FATAL|PHP Fatal|PHP Parse error' | grep "$PLUGIN" | cut -c1-200 | head -5 | sed 's/^/     /'; fi

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
hdr "R. Release A, installed (read-only)"
# ════════════════════════════════════════════════════════
# R1 — every file Release A changes is installed byte for byte as the pinned commit has it.
R_OK=0; R_BAD=""
for f in $CHANGED; do
  rel="${f#"$PLUGIN"/}"
  a="$(git -C "$REPO" show "$EXPECTED_PLUGIN_COMMIT:$f" 2>/dev/null | sha256sum | cut -c1-64)"
  b="$(sha256sum "$DEST/$rel" 2>/dev/null | cut -c1-64)"
  if [ -n "$b" ] && [ "$a" = "$b" ]; then R_OK=$((R_OK+1)); else R_BAD="$R_BAD $rel"; fi
done
[ -z "$R_BAD" ] && ok "R1 all $R_OK files Release A changes are installed exactly as $EXPECTED_PLUGIN_COMMIT has them ($N_ADDED of them new)" \
  || bad "R1 installed files that differ from $EXPECTED_PLUGIN_COMMIT:$R_BAD"
IV="$(grep -o '"version": *"5[^"]*"' "$DEST/manifest.json" | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/')"
[ "$IV" = "$EXPECTED_VERSION" ] && ok "R1 the installed manifest says $IV" || bad "R1 the installed manifest says ${IV:-?}"

# R2 — the switch, as the installed plugin reads it from both configuration sources.
GATE="$(probe "$TENANT_PHP" "$PDD_IN" "$IN_CONTAINER" gate 2>&1)"
G_STORE="$(printf '%s\n' "$GATE" | sed -n 's/^store=//p' | head -1)"; G_FILES="$(printf '%s\n' "$GATE" | sed -n 's/^files=//p' | head -1)"
if [ "$G_STORE" = "on" ] && [ "$G_FILES" = "on" ]; then ok "R2 the installed StaffJobsGate reads Uganda from both configuration sources: Release A's rules are on (store $G_STORE, files $G_FILES)"
else bad "R2 the installed StaffJobsGate reads store ${G_STORE:-?} / files ${G_FILES:-?} — Release A's rules are not on everywhere"; fi

# R3 — no staff account was changed by the deploy (Release A writes none: J1 stops the South Sudan lists rewriting them).
staff_compare "R3"

# R4 — no job-assignment WhatsApp message since the deploy (M6); every other event counted, as information.
MARK="$NAL_MARK"
[ "$MODE" = "after" ] && [ -f "$STATE" ] && MARK="$(sed -n 's/^NAL_MARK=//p' "$STATE" | head -1)"
if [ -n "$MARK" ]; then
  EV="$(probe "$NAL_PHP" "$PDD_IN" since "$MARK" 2>&1)"
  if printf '%s\n' "$EV" | grep -q '^MAX '; then
    N_ASSIGN="$(printf '%s\n' "$EV" | awk -v re="$ASSIGN_RE" '$1=="EVENT" && $2 ~ re {n+=$3} END {print n+0}')"
    N_ALL="$(printf '%s\n' "$EV" | awk '$1=="EVENT" {n+=$3} END {print n+0}')"
    if [ "$N_ASSIGN" = "0" ]; then ok "R4 no job-assignment WhatsApp message in the Message Log since row #$MARK (New Job, Bulk Dispatch, Reschedule, a job from uCRM: 0)"
    else bad "R4 $N_ASSIGN job-assignment message(s) in the Message Log since row #$MARK:"; printf '%s\n' "$EV" | awk -v re="$ASSIGN_RE" '$1=="EVENT" && $2 ~ re {print "       " $2 " ×" $3}'; fi
    echo "  …     since row #$MARK the Message Log has $N_ALL new row(s)$( [ "$N_ALL" != "0" ] && printf ', by event:')"
    printf '%s\n' "$EV" | awk '$1=="EVENT" {print "          " $2 " ×" $3}'
  elif printf '%s' "$EV" | grep -q '^NOTABLE'; then note "R4 the Message Log table does not exist — nothing was sent at all"
  else bad "R4 the Message Log could not be read: $(printf '%s' "$EV" | head -c 200 | tr '\n' ' ')"; fi
else note "R4 there is no record of where the deploy started (state-$EXPECTED_VERSION.env) — the Message Log is not counted"; fi

# R5 — the other job-assignment path stays off: the master cron's job_assign entry is still commented out.
if [ "$(grep -cE "^[[:space:]]*'job_assign'[[:space:]]*=>" "$DEST/cron/master.php" 2>/dev/null)" = "0" ] \
   && [ "$(grep -cE "^[[:space:]]*//[[:space:]]*'job_assign'[[:space:]]*=>" "$DEST/cron/master.php" 2>/dev/null)" = "1" ]; then
  ok "R5 the master cron's job_assign entry is still commented out (as since before 5.18.49)"
else bad "R5 the installed cron/master.php no longer has its job_assign entry commented out"; fi

# R6 — what the screens now say, read from the installed files (the pinned build is proved by the suite).
grep -qF 'No WhatsApp message is sent for jobs yet' "$DEST/tabs/support/scheduling.php" 2>/dev/null \
  && ok "R6 ＋ New Job says: \"No WhatsApp message is sent for jobs yet\" — its Notify via WhatsApp box is hidden on Uganda" \
  || bad "R6 the installed New Job screen does not say that no WhatsApp message is sent"
grep -qF 'res.whatsapp_note' "$DEST/tabs/support/bulk_dispatch.php" 2>/dev/null && grep -qF 'data.whatsapp_note' "$DEST/tabs/support/scheduling.php" 2>/dev/null \
  && ok "R6 Bulk Dispatch and Reschedule show the server's note that no message was sent" \
  || bad "R6 the installed Bulk Dispatch or Reschedule screen does not show the no-message note"
grep -qF 'WhatsApp skipped: job notifications are not switched on yet' "$DEST/webhook.php" 2>/dev/null \
  && ok "R6 a job created in uCRM is logged \"WhatsApp skipped: job notifications are not switched on yet\"" \
  || bad "R6 the installed webhook does not log the skipped job message"

# R7 — who will see their jobs (M7): only an account whose uCRM user was saved through the new picker.
NV="$(count_field "$SNAP_AFTER" verified)"; NT="$(count_field "$SNAP_AFTER" take_jobs)"; NU="$(count_field "$SNAP_AFTER" unverified)"; NF="$(count_field "$SNAP_AFTER" ftth_only)"
if [ -n "$NT" ]; then
  note "R7 $NV of $NT active accounts that take jobs have a verified uCRM link. The other $((NT - NV)) see no job in My Jobs until an admin saves their uCRM user through the picker (Staff → edit → uCRM user); ${NU:-0} of them hold an id stored the old way and ${NF:-0} only an FTTH id — neither counts on Uganda (M7)"
fi
else
# ════════════════════════════════════════════════════════
hdr "RB. 5.18.49, back in place (read-only)"
# ════════════════════════════════════════════════════════
RB_OK=0; RB_BAD=""
for f in $CHANGED; do
  printf '%s\n' "$ADDED" | grep -qxF "$f" && continue
  rel="${f#"$PLUGIN"/}"
  a="$(git -C "$REPO" show "$BASELINE_COMMIT:$f" 2>/dev/null | sha256sum | cut -c1-64)"
  b="$(sha256sum "$DEST/$rel" 2>/dev/null | cut -c1-64)"
  if [ -n "$b" ] && [ "$a" = "$b" ]; then RB_OK=$((RB_OK+1)); else RB_BAD="$RB_BAD $rel"; fi
done
[ -z "$RB_BAD" ] && ok "RB1 all $RB_OK files Release A had changed are back exactly as $BASELINE_COMMIT ($BASELINE_VERSION) has them" \
  || bad "RB1 installed files that differ from $BASELINE_COMMIT:$RB_BAD"
IV="$(grep -o '"version": *"5[^"]*"' "$DEST/manifest.json" | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/')"
[ "$IV" = "$BASELINE_VERSION" ] && ok "RB1 the installed manifest says $IV" || bad "RB1 the installed manifest says ${IV:-?}"
# The files 5.18.50 added stay (deploy-hybrid.sh never deletes); nothing in 5.18.49 loads them.
N_LEFT=0; USED=""
for f in $ADDED; do
  rel="${f#"$PLUGIN"/}"; [ -f "$DEST/$rel" ] && N_LEFT=$((N_LEFT+1))
  case "$rel" in lib/*.php)
    cls="$(basename "$rel" .php)"
    if grep -rlE "\\b$cls\\b" "$DEST" --include='*.php' 2>/dev/null | grep -v "^$DEST/tests/" | grep -vxF "$DEST/$rel" | grep -q .; then
      # a file 5.18.50 added may name it; a file 5.18.49 has may not
      for u in $(grep -rlE "\\b$cls\\b" "$DEST" --include='*.php' 2>/dev/null | grep -v "^$DEST/tests/" | grep -vxF "$DEST/$rel"); do
        printf '%s\n' "$ADDED" | grep -qxF "$PLUGIN/${u#"$DEST"/}" || USED="$USED ${u#"$DEST"/}→$cls"
      done
    fi ;;
  esac
done
[ -z "$USED" ] && ok "RB2 the $N_LEFT files 5.18.50 added are still on disk and inert: no 5.18.49 file loads them" \
  || bad "RB2 5.18.49 files name a class 5.18.50 added:$USED"
staff_compare "RB3"
note "on 5.18.49 again: the job-assignment WhatsApp messages are sent again (M6 is undone); on the next Staff page load the South Sudan lists rewrite the uCRM user ids they name, which breaks any link saved through the picker for those accounts — after a new deploy, save those links again; My Jobs falls back to the old id rules"
fi

# ════════════════════════════════════════════════════════
hdr "F. Summary"
# ════════════════════════════════════════════════════════
LIVE_NOW="$(live_commit)"
case "$MODE" in
  rollback) echo "  serving           ${LIVE_NOW:-?}  (plugin $BASELINE_VERSION)"
            echo "  deploy again      cd $REPO && bash scripts/deploy-5.18.50.sh" ;;
  *)        echo "  deployed commit   ${LIVE_NOW:-?}  (plugin $EXPECTED_VERSION)"
            echo "  rollback          cd $REPO && bash scripts/deploy-5.18.50.sh --rollback     (→ $BASELINE_COMMIT, $BASELINE_VERSION)"
            echo "      or by hand    cd $REPO && git checkout $BASELINE_COMMIT && bash scripts/deploy-hybrid.sh && git checkout -" ;;
esac
[ -n "$BK" ] && echo "  backup            $BK"
[ -n "$BK" ] && echo "  the data          this release changes none: a rollback needs no restore. The database copies are there for a restore on instruction only"
if [ "$MODE" != "rollback" ]; then
  echo "  job messages      Uganda sends NO job-assignment WhatsApp message (New Job, Bulk Dispatch, Reschedule, a job from uCRM); accept, task-progress and completion messages are unchanged — Release B, with approved wording, is separate"
  echo "  My Jobs           shows a technician's jobs only once an admin has saved their uCRM user through the picker (R7)"
  echo "  later             cd $REPO && bash scripts/deploy-5.18.50.sh --after-only   re-measures R3 and R4 since this deploy; send its log file"
fi
echo "  checks            $PASS ok, $FAIL failed, $NOTE notes"
if [ "$FAIL" = "0" ]; then echo; echo "  $EXPECTED_VERSION ($MODE): PASSED. Send this LOG FILE back (not a copy of the terminal)."
else echo; echo "  $EXPECTED_VERSION ($MODE): $FAIL FAILED — send the log file; do not roll back on your own unless staff or customers are affected."; fi
[ "$FAIL" = "0" ]
}

# The whole script is read before it runs: a rollback checks out a commit in which this file does not exist.
main "$@"; exit $?
