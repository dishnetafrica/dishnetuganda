#!/usr/bin/env bash
#
# deploy-5.18.85.sh — the installation authorisation's request form filled from the customer's uCRM quotation, and the
#            technician's name in the booking messages (plugin 5.18.85) over 5.18.84, or roll it back to 5.18.84.
#
#   5.18.85  On 06 Oct the first real authorisation went out with its charges typed by hand while the customer's quotation
#            already held them, and the booking WhatsApp (5.18.84) went out without its Technician line. With this release
#            the request form starts from the customer's latest uCRM quotation: the kit, the plan, the installation charge,
#            a priced transport charge, other one-time charges; a transport line the quotation lists at 0 is asked for,
#            never filled with 0. The form names the quotation; the sender checks and may change every value. And the
#            booking WhatsApp and the "installation booked" e-mail name the technician by the first name on the verified
#            staff account, else uCRM's users/admins/{id}: job.add's own lookup, users/{id}, answers 404 on this uCRM for
#            every user (docs/44 §13.1). Uganda only. Record: docs/07, 06 Oct. NO SWITCH: nothing is sent by the form; it
#            fills itself the next time a person opens it.
#
#   Customer Installation Authorisation (5.18.83) and the booking WhatsApp (5.18.84) are LIVE on this server, both switched
#            on by the operator on 06 Oct. This deploy keeps them exactly as they are: both switches are read before and
#            after and must be unchanged, each's two copies agreeing (V3b, V3c, R2); migration 086 must be complete before
#            anything changes and still complete after (A, R3); their pieces are checked present (R6). The rollback puts
#            5.18.84 back, both features and both switches as they are.
#
#   THE COMMIT IT INSTALLS IS A RELEASE COMMIT, NOT THE BRANCH TIP — as 5.18.66 through 5.18.84 were. The pin below, on
#            release/5.18.85, is this release alone applied on 79607d4 (the 5.18.84 release commit production runs). The
#            branch tip also carries undeployed work: the distributor partner portal (migrations 081–083), the PD-8 CSRF
#            guard, and the AI communication layer batches (migration 085, the media worker, voice, image and document
#            paths). Stage A refuses a pin whose parent is not 5.18.84, or whose delta is not exactly this release's files.
#
#   Scope:   code only — NO migration, no new configuration key. Two new files (the quotation reader and its test),
#            fourteen changed (the authorisation's staff API, the job page's request form, the booking WhatsApp's class,
#            webhook.php, 5.18.84's test, the job-notifications test, two test fixtures, the manifest and five version
#            pins). No uCRM write, no message, no configuration value changed, no table created or altered, nothing written.
#
#   Regression: because the whole plugin tree is copied, stage R doubles as a full check that Release A (5.18.49) through
#            5.18.84 is installed byte-for-byte as the pin has it (R5), migrations 084 and 086 are still in place (R3), the
#            photo surface, the staff-cash chain, the 5.18.69–5.18.74 fixes, 5.18.83's authorisation and 5.18.84's booking
#            WhatsApp are present (R6), the pilot still binds the Null channel (R4) and its switch is untouched (V3, R2). R7
#            runs 5.18.71's read-only cash-in-hand tool on the live book; R8 reads a quotation shaped like DishNet Uganda's
#            with the installed reader — a pure function: nothing read from uCRM, nothing written.
#
# 5.18.84 must be the live plugin (it is, since 6 Oct 15:23 UTC): this script refuses any other live commit.
#
# Run as root on the server, then send back THE LOG FILE (never a copy of the terminal):
#
#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.85 \
#     && mkdir -p /root/dnb-5.18.85 \
#     && bash scripts/deploy-5.18.85.sh 2>&1 | tee /root/dnb-5.18.85/deploy-$(date -u +%Y%m%dT%H%M%SZ).log
#
# The rollback is a separate command, printed at the end of the deploy's log. It is never pasted together with the one
# above: pasted together, the shell runs both (root docs/44 §16.9).
#
# Options
#   --after-only         the deploy already happened: run the checks again
#   --rollback           put 5.18.84 back (typed ROLLBACK), then check it
#   --plugin-base <url>  the public URL of public.php, if the derived one is wrong
#
# What it never does: switch customer_wa_install_scheduled, install_auth_enabled or any other configuration value on or
# off, touch a customer, a staff account, a job, a quotation, a message, a photo, a cash record, the webhook key, Traefik,
# UISP or the website; send anything; sign anyone in; run a cron; call uCRM. The databases are read as their owner, and the
# reads of 086's tables and the configuration row open the database READ-ONLY; the cash-in-hand tool only reads. The
# writes are the documented deploy (or rollback), a backup under /root/dnb-5.18.85/, a record of where the deploy started,
# and the time of the copy on the files this release changes.
#
# Stages:  A before-evidence: the release delta (no migration), both switches (install_auth_enabled and
#            customer_wa_install_scheduled as the operator left them, each's two copies agreeing), migration 086 complete,
#            uCRM's job.add reaching the plugin, the syntax check under the server's own PHP, the backup → GO/NO-GO
#          B the documented deploy (or, with --rollback, the documented deploy of 5.18.84); the copy's time on each file
#          V the public pages (no loop, the portal still refuses, the Uganda contacts, zoom allowed); the authorisation page
#            and its two staff actions answering as before; the pilot switch, install_auth_enabled and
#            customer_wa_install_scheduled UNCHANGED by the run; the photo surfaces still refusing; no fatal
#          R 5.18.85 installed: the changed files byte-for-byte and the version (R1); the switches as before, read through
#            the installed code (R2); 086 still complete, 084 still in place (R3); the pilot still bound to the Null channel,
#            no undeployed file installed (R4); Release A→5.18.84 still installed (R5); every earlier marker, 5.18.83's
#            authorisation, 5.18.84's booking WhatsApp and 5.18.85's pieces present (R6); cash in hand on the live book,
#            read-only (R7); the installed quotation reader on a quotation shaped like 000181 (R8)
#          F summary
set -uo pipefail
umask 077

main() {
PLUGIN="dishnet-hybrid-sudan"
CONTAINER="${UCRM_CONTAINER:-ucrm}"
EXPECTED_PLUGIN_COMMIT="4790019"   # 5.18.85 (release) — the request form from the quotation, the technician's name; cut on 5.18.84; branch release/5.18.85
EXPECTED_VERSION="5.18.85"
BASELINE_COMMIT="79607d4"          # 5.18.84 (release) — what the server runs first, and the rollback
BASELINE_VERSION="5.18.84"
RELEASE_A_BASE="e076632"           # 5.18.49 — the regression base: R5 checks every file from Release A through 5.18.84 is installed as pinned
RELEASE_BRANCH="release/5.18.85"   # where the release commit lives; fetched when the checkout does not hold it
BRANCH="claude/study-this-jhe2eg"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$REPO/$PLUGIN"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="${DNB_OUT:-/root/dnb-5.18.85}"; mkdir -p "$OUT"; chmod 700 "$OUT"
STATE="$OUT/state-$EXPECTED_VERSION.env"
GUARD_SECONDS="${GUARD_SECONDS:-60}"
TOOL="tools/set_distributors.php"   # reads the live pilot switch (read-only with --show); a regression check here
LAYOUT_MARK='flex-shrink:0;">📷 '    # the one-line button of 5.18.67's card (R6 regression)
BACKFILL_TOOL="tools/backfill_staff_cash_ins.php"   # 5.18.68 (R6 regression: installed)
RECORDS_TOOL="tools/staff_records_currency.php"      # 5.18.70 (R6 regression: installed, and still 5.18.70's)
CIH_TOOL="tools/cash_in_hand.php"                     # 5.18.71 (R6 regression: installed); run in R7 — READ-ONLY, nothing written
MIG="migrations/086_install_authorisation.sql"       # 5.18.83's migration — live, with the operator's records: a regression check here
IA_TABLES="install_auth install_auth_events install_auth_activations install_auth_exempt install_auth_rate"
IA_TRIGGERS="install_auth_accepted_is_final install_auth_never_deleted install_auth_status_moves install_auth_request_is_fixed install_auth_events_no_update install_auth_events_no_delete install_auth_activations_no_update install_auth_activations_no_delete install_auth_exempt_no_update install_auth_exempt_no_delete"
IA_INDEXES="idx_install_auth_client idx_install_auth_status idx_install_auth_events_job idx_install_auth_rate"
WA_LIB="lib/InstallScheduledWhatsApp.php"          # 5.18.84's class
WA_KEY="customer_wa_install_scheduled"             # 5.18.84's switch — LIVE: switched on by the operator on 06 Oct
QP_LIB="lib/QuotationPrefill.php"                  # 5.18.85's quotation reader
# The release's own files, and nothing else: 2 added, 14 changed. Anything outside this list in the pin is another build.
EXPECTED_ADDED="$QP_LIB tests/test_install_quote_prefill.php"
EXPECTED_CHANGED="includes/api/api_install_auth.php $WA_LIB manifest.json tabs/support/scheduling.php tests/fixtures/fake_ucrm_staff_jobs.php tests/fixtures/staff_jobs_sandbox.php tests/test_distributor_apply.php tests/test_distributor_link_ucrm.php tests/test_distributor_notify.php tests/test_distributor_registry.php tests/test_distributor_territory.php tests/test_install_scheduled_whatsapp.php tests/test_job_notifications_day.php webhook.php"

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
in_list() { case " $2 " in *" $1 "*) return 0;; esac; return 1; }   # in_list WORD "LIST"

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
echo "  installs        $EXPECTED_PLUGIN_COMMIT by its hash: $EXPECTED_VERSION's own changes on the live $BASELINE_VERSION, without the undeployed work the branch tip ($HEAD_PLUGIN) also carries"
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
OTHER="$(git -C "$REPO" diff --no-renames --name-only --diff-filter=CRTUXB "$BASELINE_COMMIT" "$EXPECTED_PLUGIN_COMMIT" -- "$PLUGIN")"
N_CHANGED="$(printf '%s\n' "$CHANGED" | grep -c . || true)"; N_ADDED="$(printf '%s\n' "$ADDED" | grep -c . || true)"
echo "  $EXPECTED_VERSION         $N_CHANGED files differ from $BASELINE_COMMIT: $((N_CHANGED - N_ADDED)) changed, $N_ADDED added, $(printf '%s\n' "$DELETED" | grep -c . || true) removed"
[ "$N_CHANGED" -gt 0 ] || stop "Git reports no file between $BASELINE_COMMIT and $EXPECTED_PLUGIN_COMMIT"
[ -z "$DELETED$OTHER" ] || stop "the pin removes or retypes files ($(printf '%s ' $DELETED $OTHER | sed "s#$PLUGIN/##g")); $EXPECTED_VERSION removes none — this script is for another build"
# The delta must be exactly this release's files: every added file expected as added, every changed file expected as
# changed, and every expected file present. This is what keeps the undeployed portal, CSRF and AI-layer work out.
STRAY=""; MISSING=""
for f in $CHANGED; do
  rel="${f#"$PLUGIN"/}"
  if printf '%s\n' "$ADDED" | grep -qxF -- "$f"; then in_list "$rel" "$EXPECTED_ADDED" || STRAY="$STRAY $rel(added)"
  else in_list "$rel" "$EXPECTED_CHANGED" || STRAY="$STRAY $rel"; fi
done
for rel in $EXPECTED_ADDED; do printf '%s\n' "$ADDED" | grep -qxF -- "$PLUGIN/$rel" || MISSING="$MISSING $rel(added)"; done
for rel in $EXPECTED_CHANGED; do
  printf '%s\n' "$CHANGED" | grep -qxF -- "$PLUGIN/$rel" && ! printf '%s\n' "$ADDED" | grep -qxF -- "$PLUGIN/$rel" || MISSING="$MISSING $rel"
done
[ -z "$STRAY" ] || stop "the pin carries files that are not $EXPECTED_VERSION's, which this release must not ship:$STRAY"
[ -z "$MISSING" ] || stop "the pin lacks files $EXPECTED_VERSION is made of:$MISSING — this script is for another build"
N_MIG="$(printf '%s\n' "$CHANGED" | grep -c "^$PLUGIN/migrations/" || true)"
echo "  migrations      $N_MIG added since $BASELINE_VERSION ($EXPECTED_VERSION adds none)"
[ "$N_MIG" = "0" ] || stop "the pin carries $N_MIG migration(s); $EXPECTED_VERSION adds none — this script is for another build"
ok "A0 the release delta is exactly $EXPECTED_VERSION's $N_CHANGED files — $N_ADDED added, $((N_CHANGED - N_ADDED)) changed, no migration; no partner-portal, CSRF or AI-layer file"

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
# Who owns the plugin's database: the reads of the new tables run as that user, so SQLite can never leave a file owned by root beside it.
DB_OWNER="$(docker exec "$CONTAINER" stat -c '%u:%g' "$PDD_IN/plugin.sqlite3" 2>/dev/null || true)"

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

# A switch, in BOTH places the plugin keeps it, judged as the plugin's own flagOn() judges it:
#   files — the configuration files, through the installed plugin's own read-only path (PluginConfig::read reads them in
#           the plugin's order and changes nothing on disk); what the plugin's tools read, and webhook.php beneath the store;
#   store — the kyc_config row in plugin.sqlite3, read-only; what public.php reads, and webhook.php first.
# set_config.php writes both. The vault never holds these keys. Read as the database's owner.
cfg_switch() {  # cfg_switch KEY PREFIX — prints PREFIX=<files>/<store>, each absent|off|on|?, the store also nodb; or PREFIX=?
  local v=""
  [ -n "$DB_OWNER" ] && v="$(docker exec -u "$DB_OWNER" "$CONTAINER" php -r '
    $r = $argv[1]; $pdd = $argv[2]; $key = $argv[3];
    $judge = function ($c) use ($key) {
      if (!is_array($c)) return "?";
      $v = $c[$key] ?? null;
      if ($v === null || $v === "") return "absent";
      if (is_bool($v)) return $v ? "on" : "off";
      return in_array(strtolower(trim((string)$v)), ["1", "true", "on", "yes"], true) ? "on" : "off";
    };
    $files = "?";
    try {
      if (is_file($r . "/lib/PluginConfig.php")) { require_once $r . "/lib/PluginConfig.php"; if (method_exists("PluginConfig", "read")) $files = $judge(PluginConfig::read($r, $pdd)); }
    } catch (Throwable $e) { $files = "?"; }
    $store = "?"; $db = rtrim($pdd, "/") . "/plugin.sqlite3";
    if (!is_file($db)) $store = "nodb";
    else try {
      $p = new PDO("sqlite:" . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
      if ($p->query("SELECT 1 FROM sqlite_master WHERE type = \x27table\x27 AND name = \x27kyc_config\x27")->fetchColumn() === false) $store = "absent";
      else { $row = $p->query("SELECT data FROM kyc_config WHERE id = 0 LIMIT 1")->fetchColumn(); $store = $row === false ? "absent" : $judge(json_decode((string)$row, true)); }
    } catch (Throwable $e) { $store = "?"; }
    echo "$files/$store";
  ' "$IN_CONTAINER" "$PDD_IN" "$1" 2>/dev/null)"
  case "$v" in */*) echo "$2=$v" ;; *) echo "$2=?" ;; esac
}
sw_off()      { case "${1#*=}" in absent/absent|absent/off|off/absent|off/off) return 0;; esac; return 1; }   # both read, both off
sw_on()       { case "${1#*=}" in on/*|*/on) return 0;; esac; return 1; }                                      # either on
sw_both_on()  { [ "${1#*=}" = "on/on" ]; }
ia_store_on() { case "$1" in ia=*/on) return 0;; esac; return 1; }                                             # what the authorisation page reads
ia_switch_state() { cfg_switch install_auth_enabled ia; }   # 5.18.83's — LIVE: switched on by the operator on 06 Oct
wa_switch_state() { cfg_switch "$WA_KEY" wa; }              # 5.18.84's — LIVE: switched on by the operator on 06 Oct
IA_BEFORE="$(ia_switch_state)"
echo "  authorisation   ${IA_BEFORE}   (install_auth_enabled in the configuration files / the store row; the operator's setting — this run never changes it)"
WA_BEFORE="$(wa_switch_state)"
echo "  booking WA      ${WA_BEFORE}   ($WA_KEY in the configuration files / the store row; the operator's setting — this run never changes it)"

# Migration 086, read-only, from the installed plugin.sqlite3, as the database's owner: the ledger row, every object 086
# creates and the rows in its five tables. Prints one line:
#   mig=<absent|applied:<md5>> tables=<n>/5 triggers=<n>/10 indexes=<n>/4 checks=<n>/2 rows=<t1>:<t2>:<t3>:<t4>:<t5> missing=<names|->
# or nodb | ?
ia_tables_state() {
  if [ -z "$DB_OWNER" ]; then   # never open the database as root: SQLite could leave a root-owned -wal/-shm beside it
    if docker exec "$CONTAINER" test -f "$PDD_IN/plugin.sqlite3" 2>/dev/null; then echo "?"; else echo "nodb"; fi
    return
  fi
  docker exec -u "$DB_OWNER" "$CONTAINER" php -r '
    $db = rtrim((string)$argv[1], "/") . "/plugin.sqlite3";
    if (!is_file($db)) { echo "nodb"; exit; }
    $tables = explode(" ", $argv[2]); $triggers = explode(" ", $argv[3]); $indexes = explode(" ", $argv[4]);
    try {
      $p = new PDO("sqlite:" . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
      $have = []; foreach ($p->query("SELECT type, name, sql FROM sqlite_master")->fetchAll(PDO::FETCH_ASSOC) as $o) $have[$o["type"] . ":" . $o["name"]] = (string)$o["sql"];
      $mig = "absent";
      if (isset($have["table:_migrations"])) {
        $q = $p->prepare("SELECT checksum FROM _migrations WHERE filename = ?"); $q->execute(["086_install_authorisation.sql"]);
        $c = $q->fetchColumn(); if ($c !== false) $mig = "applied:" . (string)$c;
      }
      $miss = []; $nt = 0; $ng = 0; $ni = 0; $rows = [];
      foreach ($tables as $t)   { if (isset($have["table:$t"])) { $nt++; $rows[] = (int)$p->query("SELECT COUNT(*) FROM [$t]")->fetchColumn(); } else { $miss[] = $t; $rows[] = "-"; } }
      foreach ($triggers as $t) { if (isset($have["trigger:$t"])) $ng++; else $miss[] = $t; }
      foreach ($indexes as $t)  { if (isset($have["index:$t"]))   $ni++; else $miss[] = $t; }
      $nc = 0;
      if (strpos($have["table:install_auth"] ?? "", "accepted_at IS NOT NULL AND accepted_method IS NOT NULL") !== false) $nc++; else $miss[] = "install_auth:accepted-check";
      if (strpos($have["table:install_auth_events"] ?? "", "INSTALLATION_EXEMPTED") !== false) $nc++; else $miss[] = "install_auth_events:event-check";
      echo "mig=$mig tables=$nt/" . count($tables) . " triggers=$ng/" . count($triggers) . " indexes=$ni/" . count($indexes) . " checks=$nc/2 rows=" . implode(":", $rows) . " missing=" . ($miss ? implode(",", $miss) : "-");
    } catch (Throwable $e) { echo "?"; }
  ' "$PDD_IN" "$IA_TABLES" "$IA_TRIGGERS" "$IA_INDEXES" 2>/dev/null || echo "?"
}
IA_TB_BEFORE="$(ia_tables_state)"
echo "  086 state       $IA_TB_BEFORE"
if [ "$MODE" = "deploy" ]; then
  if ! sw_off "$IA_BEFORE" && ! sw_both_on "$IA_BEFORE"; then
    stop "NO-GO: install_auth_enabled reads '$IA_BEFORE' (files/store) — this script must find it readable, its two copies agreeing (on in both, as the operator left it, or off in both), before it deploys. Send the log file"
  fi
  if ! sw_off "$WA_BEFORE" && ! sw_both_on "$WA_BEFORE"; then
    stop "NO-GO: $WA_KEY reads '$WA_BEFORE' (files/store) — this script must find it readable, its two copies agreeing (on in both, as the operator left it, or off in both), before it deploys: the tools and the webhook would act differently. Send the log file"
  fi
  case "$IA_TB_BEFORE" in
    "mig=applied:"*" tables=5/5 triggers=10/10 indexes=4/4 checks=2/2 "*) ;;
    "mig=applied:"*) stop "NO-GO: migration 086 (5.18.83's) is recorded but not complete on this server ($IA_TB_BEFORE) — Customer Installation Authorisation depends on it. Send the log file" ;;
    "mig=absent "*) stop "NO-GO: migration 086 is not applied on this server ($IA_TB_BEFORE), although $BASELINE_VERSION is live. Send the log file" ;;
    nodb) stop "NO-GO: no plugin.sqlite3 at $PDD_IN — the live plugin has a database; the derived data directory is wrong" ;;
    *) stop "NO-GO: the plugin database could not be read read-only as its owner (${DB_OWNER:-unknown}): '$IA_TB_BEFORE' — without that read, R3 could not verify migration 086 either. Send the log file" ;;
  esac
fi
if [ "$MODE" = "rollback" ] && [ "$LIVE_BEFORE" = "$EXPECTED_PLUGIN_COMMIT" ] && sw_on "$WA_BEFORE"; then
  note "$WA_KEY reads '$WA_BEFORE' (files/store). $BASELINE_VERSION reads it too: after the rollback the booking WhatsApp still goes, without the technician's name (its lookup at users/{id} answers 404 here), and the request form opens empty again"
fi

# uCRM's job.add as the plugin's own webhook log records it (read-only; the log keeps its last 300 entries): the event the
# booking WhatsApp hangs on. Evidence only, never a NO-GO — a quiet week has no new jobs.
JOBADD="$(docker exec "$CONTAINER" php -r '
  $l = @json_decode((string)@file_get_contents(rtrim($argv[1], "/") . "/webhook_log.json"), true);
  if (!is_array($l)) { echo "nolog"; exit; }
  $n = 0; $last = "";
  foreach ($l as $e) { if (($e["message"] ?? "") === "Received UCRM webhook: job.add") { $n++; $t = (string)($e["received_at"] ?? ""); if ($t > $last) $last = $t; } }
  echo $n, "|", $last;' "$PDD_IN" 2>/dev/null || true)"
case "$JOBADD" in
  ""|nolog)   echo "  job.add         the plugin's webhook log could not be read — evidence only" ;;
  "0|"*)      echo "  job.add         none among the webhook log's last 300 entries — evidence only (docs/07, 28 Sep: uCRM delivers job.add to the plugin)" ;;
  *)          echo "  job.add         ${JOBADD%%|*} received among the webhook log's last 300 entries, the last at ${JOBADD#*|} (the plugin's clock) — the event the booking WhatsApp hangs on" ;;
esac

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
      ok "backed up $db → $BK/$db ($(du -h "$BK/$db" | cut -f1)) — one consistent copy, integrity ok, ${4:-?} tables, SQLite ${5:-?}"
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
  printf 'DEPLOYED_AT=%s\nFROM_COMMIT=%s\nSW_BEFORE=%s\nIA_BEFORE=%s\nWA_BEFORE=%s\nTB_BEFORE=%s\nPF_BEFORE=%s\n' "$TS" "$LIVE_BEFORE" "$SW_BEFORE" "$IA_BEFORE" "$WA_BEFORE" "$TB_BEFORE" "$PF_BEFORE" > "$STATE"
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
      *:8443*) note "V1/V2/V5/V7 could not probe the public address: the derived address is $PLUGIN_BASE and the server cannot reach its own :8443 public port from inside the host (curl 000 — hairpin). This says nothing about whether the pages work; the code is verified by R1–R6 below and by V4 (no fatal). To confirm the pages themselves, open $PLUGIN_BASE?page=customer_login in a browser, or re-run: bash scripts/deploy-$EXPECTED_VERSION.sh --after-only --plugin-base <an address the server can reach>" ;;
      *) note "V1/V2/V5/V7 could not probe the public address: $PLUGIN_BASE is not reachable from the server (curl 000). This says nothing about the pages; R1–R6 and V4 verify the code. Open the sign-in page in a browser, or re-run with --plugin-base <a reachable address>" ;;
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
    # V6 — 5.18.73/5.18.74: the sign-in page allows pinch-zoom; 5.18.85 keeps it (and so does the rollback target, 5.18.84).
    if [ "$HTTP_CODE" = "200" ] && [ "$(count 'user-scalable=no')" = "0" ] && [ "$(count 'maximum-scale=')" = "0" ]; then ok "V6 the sign-in page allows pinch-zoom (no user-scalable=no, no maximum-scale) — as on 5.18.74"
    else bad "V6 the sign-in page blocks zoom: user-scalable=no ×$(count 'user-scalable=no') · maximum-scale= ×$(count 'maximum-scale=') (HTTP $HTTP_CODE)"; fi
    # V5 — the photo surfaces of 5.18.66 still refuse an anonymous caller. Both reads change nothing; neither ever answers 200 to nobody.
    if [ "$MODE" != "rollback" ]; then
      http GET "$PLUGIN_BASE?page=job_photo&id=1"
      case "$HTTP_CODE" in 302|401|403) ok "V5 the photo viewer without a session refuses ($HTTP_CODE) — a photo is never served to nobody";; *) bad "V5 the photo viewer without a session → $HTTP_CODE (expected 302/401/403)";; esac
      http POST "$PLUGIN_BASE?page=api&action=job_photo_upload"
      case "$HTTP_CODE" in 401|403) ok "V5 job_photo_upload without a login answers $HTTP_CODE — the staff guard, before any handler";; *) bad "V5 job_photo_upload without a login → $HTTP_CODE (expected 401)";; esac
    fi
    # V7 — 5.18.83's authorisation page answers as its switch says, unchanged by this release: with install_auth_enabled on
    # in the store row (the operator's setting since 06 Oct), 404 "This link is not valid" to a request without a link —
    # malformed links are refused before the database is read; with it off, 404 "This page is not available.".
    # V8 — the staff side refuses an anonymous caller before any handler, as every staff action does.
    if [ "$MODE" != "rollback" ]; then
      http GET "$PLUGIN_BASE?page=install_auth"
      NA="$(count 'This page is not available.')"; NV="$(count 'This link is not valid or has expired.')"
      if ia_store_on "$IA_BEFORE"; then
        if [ "$HTTP_CODE" = "404" ] && [ "$NV" = "1" ] && [ "$NA" = "0" ]; then ok "V7 the customer authorisation page answers 404 \"This link is not valid\" — the switch is on, and a request without a link is refused"
        else bad "V7 the customer authorisation page → $HTTP_CODE; 'not available' ×$NA · 'link is not valid' ×$NV (with the switch on it must answer 404 'This link is not valid')"; fi
      elif [ "$HTTP_CODE" = "404" ] && [ "$NA" = "1" ] && [ "$NV" = "0" ]; then
        ok "V7 the customer authorisation page answers 404 \"This page is not available.\" — the switch is off, so it behaves as a page that does not exist"
      else bad "V7 the customer authorisation page → $HTTP_CODE; 'not available' ×$NA · 'link is not valid' ×$NV (with the switch off it must answer 404 'This page is not available.')"; fi
      http POST "$PLUGIN_BASE?page=api&action=install_auth_request"
      case "$HTTP_CODE" in 401|403) ok "V8 install_auth_request without a login answers $HTTP_CODE — the staff guard, before any handler";; *) bad "V8 install_auth_request without a login → $HTTP_CODE (expected 401)";; esac
      # V9 — 5.18.85: the form's data, which now reads the customer's quotations, refuses an anonymous caller before any of it.
      http GET "$PLUGIN_BASE?page=api&action=install_auth_prefill&job_id=1"
      case "$HTTP_CODE" in 401|403) ok "V9 install_auth_prefill without a login answers $HTTP_CODE — the staff guard, before a quotation is read";; *) bad "V9 install_auth_prefill without a login → $HTTP_CODE (expected 401)";; esac
    fi
  fi
else note "V1/V2/V5/V7 skipped — the plugin's public URL could not be derived (re-run with --plugin-base); the R checks below read the install directly"; fi

# V3 — the pilot switch is UNCHANGED by the deploy: whatever state the operator left it in, it stays.
SW_AFTER="$(switch_state)"
if [ -z "$SW_AFTER" ] || ! printf '%s' "$SW_AFTER" | grep -q '='; then bad "V3 could not read the pilot switch ($SW_AFTER)"
elif [ "$SW_AFTER" = "${SW_BEFORE:-}" ]; then ok "V3 the pilot switch is unchanged by the deploy (before=$SW_BEFORE, after=$SW_AFTER) — $EXPECTED_VERSION changes no configuration value"
else bad "V3 the pilot switch CHANGED across the deploy (before=${SW_BEFORE:-?}, after=$SW_AFTER) — $EXPECTED_VERSION must never touch it"; fi
printf '  …     the pilot reads %s (set by the operator; manage it with set_distributors.php --show/--on/--off)\n' "${SW_AFTER#pilot=}"
# V3b — install_auth_enabled (5.18.83's, live) is UNCHANGED in both places, its two copies agreeing: this run never touches it.
IA_AFTER="$(ia_switch_state)"
if ! sw_off "$IA_AFTER" && ! sw_on "$IA_AFTER"; then bad "V3b could not read install_auth_enabled in both places ($IA_AFTER, files/store)"
elif [ "$IA_AFTER" != "$IA_BEFORE" ]; then bad "V3b install_auth_enabled CHANGED across this run (before=$IA_BEFORE, after=$IA_AFTER, files/store) — this run must never touch it"
elif sw_on "$IA_AFTER" && ! sw_both_on "$IA_AFTER"; then bad "V3b the configuration files and the store row disagree about install_auth_enabled ($IA_AFTER, files/store): the tools and the pages would act differently. Send the log file"
else ok "V3b install_auth_enabled is unchanged by this run (before=$IA_BEFORE, after=$IA_AFTER; files/store)$(sw_both_on "$IA_AFTER" && echo ' — ON in both: Customer Installation Authorisation stays live, as the operator left it' || echo ' — OFF in both')"; fi
# V3c — customer_wa_install_scheduled (5.18.84's, live) is UNCHANGED in both places, its two copies agreeing: this run never touches it.
WA_AFTER="$(wa_switch_state)"
if sw_both_on "$WA_AFTER"; then WA_SAYS=" — ON in both: the booking WhatsApp stays live, as the operator left it$([ "$MODE" = "rollback" ] && echo "; $BASELINE_VERSION sends it too, without the technician's name")"
else WA_SAYS=" — OFF in both: no customer is sent the booking WhatsApp, as the operator left it"; fi
if ! sw_off "$WA_AFTER" && ! sw_on "$WA_AFTER"; then bad "V3c could not read $WA_KEY in both places ($WA_AFTER, files/store)"
elif [ "$WA_AFTER" != "$WA_BEFORE" ]; then bad "V3c $WA_KEY CHANGED across this run (before=$WA_BEFORE, after=$WA_AFTER, files/store) — this run must never touch it"
elif sw_on "$WA_AFTER" && ! sw_both_on "$WA_AFTER"; then bad "V3c the configuration files and the store row disagree about $WA_KEY ($WA_AFTER, files/store): the tools and the webhook would act differently. Send the log file"
else ok "V3c $WA_KEY is unchanged by this run (before=$WA_BEFORE, after=$WA_AFTER; files/store)$WA_SAYS"; fi

# V4 — no fatal of the plugin in the container log since the deploy.
if [ "$MODE" != "after" ] && [ -n "$BK" ]; then printf '  …     waiting %ss for the first requests on the code now installed\n' "$GUARD_SECONDS"; sleep "$GUARD_SECONDS"; fi
V4_LINES="$(log_since "$DEPLOY_STARTED" | grep -E 'UNCAUGHT|FATAL|PHP Fatal|PHP Parse error' | grep -F -- "$PLUGIN" || true)"
N_FATAL="$(printf '%s\n' "$V4_LINES" | grep -c . || true)"
if [ "${N_FATAL:-0}" = "0" ]; then ok "V4 no fatal or parse error of $PLUGIN in the container log since $DEPLOY_STARTED"
else bad "V4 ${N_FATAL} fatal line(s) of $PLUGIN since $DEPLOY_STARTED:"; printf '%s\n' "$V4_LINES" | cut -c1-200 | head -5 | sed 's/^/     /'; fi

# Migration 086 judged as installed now — R3 after a deploy, RB after a rollback (defined here, before both).
check_086() {   # $1 the label
  local tb md5 mlog rows r3bad=""
  md5="$(md5sum "$DEST/$MIG" 2>/dev/null | cut -c1-32)"
  tb="$(ia_tables_state)"
  mlog="$(docker exec "$CONTAINER" sh -c 'grep -F "086_install_authorisation.sql" "$1/migration.log" 2>/dev/null | tail -3' _ "$PDD_IN" 2>/dev/null || true)"
  case "$tb" in
    "mig=applied:"*)
      [ "$(printf '%s' "$tb" | sed -E 's/^mig=applied:([0-9a-f]*) .*/\1/')" = "$md5" ] || r3bad="$r3bad ledger-checksum≠installed-file"
      printf '%s' "$tb" | grep -q ' tables=5/5 triggers=10/10 indexes=4/4 checks=2/2 ' || r3bad="$r3bad incomplete:$(printf '%s' "$tb" | sed -E 's/.* missing=//')"
      printf '%s\n' "$mlog" | grep -q 'PARTIAL' && r3bad="$r3bad migration.log:PARTIAL"
      rows="$(printf '%s' "$tb" | sed -E 's/.* rows=([^ ]*) .*/\1/')"
      if [ -n "$r3bad" ]; then bad "$1 migration 086 is recorded but not complete:$r3bad ($tb)"
      else ok "$1 migration 086 (5.18.83's) is still applied and complete — 5 tables, 10 triggers, 4 indexes, both CHECKs, the ledger row matching the installed file; its tables hold install_auth:events:activations:exempt:rate = $rows (before this run: $(printf '%s' "$IA_TB_BEFORE" | sed -E 's/.* rows=([^ ]*) .*/\1/'))"; fi ;;
    "mig=absent "*) bad "$1 migration 086 is not applied on this server ($tb)" ;;
    nodb) bad "$1 no plugin.sqlite3 at $PDD_IN" ;;
    *)    bad "$1 could not read migration 086's state ($tb)" ;;
  esac
}

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

# R2 — the installed tool re-reads the live switch through the installed code; the deploy left it exactly as it was. And
# the installed authorisation code reads its own switch as the plugin will: InstallAuth::enabled() over the configuration.
if printf '%s' "$SW_AFTER" | grep -q '='; then ok "R2 the installed set_distributors --show reports $SW_AFTER (the operator's setting, unchanged by this deploy)"
else bad "R2 could not read the installed switch ($SW_AFTER)"; fi
IA_CODE="?"
[ -n "$DB_OWNER" ] && IA_CODE="$(docker exec -u "$DB_OWNER" "$CONTAINER" php -r '
  $r = $argv[1]; $pdd = $argv[2];
  try {
    require_once $r . "/lib/PluginConfig.php"; require_once $r . "/lib/InstallAuth.php";
    $f = PluginConfig::read($r, $pdd);
    $p = new PDO("sqlite:" . rtrim($pdd, "/") . "/plugin.sqlite3", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
    $row = $p->query("SELECT data FROM kyc_config WHERE id = 0 LIMIT 1")->fetchColumn();
    $s = is_string($row) ? (json_decode($row, true) ?: []) : [];
    $one = function (array $c) use ($pdd) { return (InstallAuth::flagOn($c) ? "on" : "off") . "/" . (InstallAuth::enabled($c, $pdd) ? "yes" : "no"); };
    echo "files=", $one($f), " store=", $one($s);
  } catch (Throwable $e) { echo "?"; }
' "$IN_CONTAINER" "$PDD_IN" 2>/dev/null || echo "?")"
case "$IA_CODE" in
  "files=off/no store=off/no") ok "R2 the installed InstallAuth reads install_auth_enabled OFF in the configuration files and in the store row the pages read — as before this run" ;;
  "files=on/yes store=on/yes") ok "R2 the installed InstallAuth reads install_auth_enabled ON in both places and enabled() is true — Customer Installation Authorisation stays live, as the operator left it" ;;
  *) bad "R2 the installed InstallAuth could not read its switch, or its two copies disagree ($IA_CODE)" ;;
esac
# …and the installed booking WhatsApp reads its own switch as the webhook will: InstallScheduledWhatsApp::enabled().
WA_CODE="?"
[ -n "$DB_OWNER" ] && [ -f "$DEST/$WA_LIB" ] && WA_CODE="$(docker exec -u "$DB_OWNER" "$CONTAINER" php -r '
  $r = $argv[1]; $pdd = $argv[2];
  try {
    require_once $r . "/lib/PluginConfig.php"; require_once $r . "/lib/InstallScheduledWhatsApp.php";
    $f = PluginConfig::read($r, $pdd);
    $p = new PDO("sqlite:" . rtrim($pdd, "/") . "/plugin.sqlite3", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
    $row = $p->query("SELECT data FROM kyc_config WHERE id = 0 LIMIT 1")->fetchColumn();
    $s = is_string($row) ? (json_decode($row, true) ?: []) : [];
    $one = function (array $c) use ($pdd) { return (InstallScheduledWhatsApp::flagOn($c) ? "on" : "off") . "/" . (InstallScheduledWhatsApp::enabled($c, $pdd) ? "yes" : "no"); };
    echo "files=", $one($f), " store=", $one($s);
  } catch (Throwable $e) { echo "?"; }
' "$IN_CONTAINER" "$PDD_IN" 2>/dev/null || echo "?")"
case "$WA_CODE" in
  "files=off/no store=off/no") if sw_off "${WA_AFTER:-}"; then ok "R2 the installed InstallScheduledWhatsApp reads $WA_KEY OFF in the configuration files and in the store row, and enabled() is false in both — as the operator left it"; else bad "R2 the installed InstallScheduledWhatsApp reads $WA_KEY OFF, but the configuration says ${WA_AFTER:-?} (files/store)"; fi ;;
  "files=on/yes store=on/yes") if sw_both_on "${WA_AFTER:-}"; then ok "R2 the installed InstallScheduledWhatsApp reads $WA_KEY ON in both places and enabled() is true — the booking WhatsApp stays live, as the operator left it"; else bad "R2 the installed InstallScheduledWhatsApp reads $WA_KEY ON, but the configuration says ${WA_AFTER:-?} (files/store)"; fi ;;
  *) bad "R2 the installed InstallScheduledWhatsApp could not read its switch, or its two copies disagree ($WA_CODE)" ;;
esac

# R3 — migration 086 (5.18.83's) is still installed and complete: the runner applies a file statement by statement and
# records it even when one fails ("PARTIAL"), so the ledger row alone proves nothing. Its tables hold the operator's
# records since the switch-on (activations, exemptions, requests, events); this run only reads them. Regression: 084 (from
# 5.18.66) still installed, its two tables untouched.
if [ ! -f "$DEST/$MIG" ]; then bad "R3 $MIG is not installed"; else check_086 "R3"; fi
if [ ! -f "$DEST/migrations/084_job_photos.sql" ]; then bad "R3 migrations/084_job_photos.sql from 5.18.66 is missing on the server"
else
  TB="$(photo_tables_state)"
  case "$TB" in
    present:*) ok "R3 084 is still installed and its two tables exist on the live plugin.sqlite3 — job_photos ${TB#present:} rows (photos:gps), untouched by this deploy" ;;
    lazy)      note "R3 084 is installed; the two tables are not in plugin.sqlite3 yet — created on the plugin's next request" ;;
    nodb)      note "R3 084 is installed; no plugin.sqlite3 yet at $PDD_IN" ;;
    *)         note "R3 084 is installed; could not read the table state ($TB)" ;;
  esac
  PF="$(photo_files_state)"
  case "$PF" in
    absent)  echo "  …     uploads/job_photos does not exist in the data directory yet: no photo has been taken" ;;
    files:*) echo "  …     uploads/job_photos holds ${PF#files:} file(s) — photos technicians have taken (never touched by this script)" ;;
    *)       echo "  …     could not read uploads/job_photos ($PF)" ;;
  esac
fi

# R4 — regression: the distributor pilot is installed as before, its two webhook hooks flag-gated, and the bound channel is
# the Null one (the Evolution adapter is defined but constructed nowhere): nothing can be sent. And none of the undeployed
# work on the branch — the partner portal, the CSRF guard, the AI communication layer — is installed.
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
for f in partner_api.php lib/StaffApiCsrf.php lib/Totp.php lib/DistributorPortalData.php migrations/081_distributor_portal_accounts.sql migrations/085_wa_media.sql lib/MediaPolicy.php lib/InboundMedia.php lib/MediaWorker.php; do
  [ -f "$DEST/$f" ] && R4_BAD="$R4_BAD $f:present(not-in-this-release)"
done
DT="$(dist_tables_state)"
[ -z "$R4_BAD" ] && ok "R4 the pilot libs + the Distributors tab are installed as before; webhook.php draws the two flag-gated hooks; the bound channel is NullWhatsAppChannel and the Evolution adapter is constructed nowhere — nothing can be sent; the pilot tables read $DT; no partner-portal, CSRF or AI-layer file is installed" || bad "R4 the installed pilot is incomplete, binds a live channel, or carries files not in this release:$R4_BAD"

# R5 — Release A (5.18.49) → 5.18.83 installed as pinned (regression that nothing — the photo surface and 5.18.83's authorisation included — was lost).
RA_OK=0; RA_BAD=""
for f in $(git -C "$REPO" diff --no-renames --name-only --diff-filter=AM "$RELEASE_A_BASE" "$BASELINE_COMMIT" -- "$PLUGIN"); do
  rel="${f#"$PLUGIN"/}"
  git -C "$REPO" cat-file -e "$EXPECTED_PLUGIN_COMMIT:$f" 2>/dev/null || continue
  a="$(git -C "$REPO" show "$EXPECTED_PLUGIN_COMMIT:$f" 2>/dev/null | sha256sum | cut -c1-64)"
  b="$(sha256sum "$DEST/$rel" 2>/dev/null | cut -c1-64)"
  if [ -n "$b" ] && [ "$a" = "$b" ]; then RA_OK=$((RA_OK+1)); else RA_BAD="$RA_BAD $rel"; fi
done
[ -z "$RA_BAD" ] && ok "R5 all $RA_OK files from Release A through $BASELINE_VERSION are installed as $EXPECTED_PLUGIN_COMMIT has them — $EXPECTED_VERSION lost none of the prior releases" || bad "R5 files from Release A→$BASELINE_VERSION differ on the server:$RA_BAD"

# R6 — every earlier release's surface is still installed (regression), and 5.18.85's pieces are in place. R1 proves each
# file byte-for-byte; this is the readable confirmation.
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
# 5.18.74: the Data Report hand-off is a tenant setting, ON in both profiles, the portal gates on it (twice), no tokenless fallback, the mint refuses empty inputs, the secret generator, "How to pay", zoom, no invented uptime, no hard-coded version
grep -qF '"data_report_handoff": true' "$DEST/profiles/uganda.json" 2>/dev/null || R6_BAD="$R6_BAD profile-uganda:no-handoff-on"
grep -qF '"data_report_handoff": false' "$DEST/profiles/uganda.json" 2>/dev/null && R6_BAD="$R6_BAD profile-uganda:5.18.73-off-value-present"
grep -qF "'webhook_secret' => ['secret'," "$DEST/tools/set_config.php" 2>/dev/null || R6_BAD="$R6_BAD set-config:no-secret-generator"
grep -qF '" generated and stored (" . strlen($new) . " characters). It is not shown here;' "$DEST/tools/set_config.php" 2>/dev/null || R6_BAD="$R6_BAD set-config:generator-message-changed"
grep -qF '$portalPayText = trim((string)((is_array($config ?? null) ? $config : [])[' "$DEST/tabs/customer_app/portal_data.php" 2>/dev/null || R6_BAD="$R6_BAD portal-data:no-pay-text"
grep -qF '>How to pay</div>' "$DEST/tabs/customer_app/portal.php" 2>/dev/null || R6_BAD="$R6_BAD portal:no-how-to-pay"
grep -qF '"data_report_handoff": true' "$DEST/profiles/south-sudan.json" 2>/dev/null || R6_BAD="$R6_BAD profile-south-sudan:no-handoff-on"
grep -qF 'public function dataReportHandoff(): bool' "$DEST/lib/TenantProfile.php" 2>/dev/null || R6_BAD="$R6_BAD tenant-profile:no-handoff-method"
grep -qF '$portalDataReportHandoff = $portalTenant->dataReportHandoff();' "$DEST/tabs/customer_app/portal_data.php" 2>/dev/null || R6_BAD="$R6_BAD portal-data:no-handoff-flag"
N_GATE="$(grep -cF '<?php if ($portalDataReportHandoff):' "$DEST/tabs/customer_app/portal.php" 2>/dev/null || echo 0)"
[ "$N_GATE" = "2" ] || R6_BAD="$R6_BAD portal:handoff-gates×$N_GATE(want 2)"
grep -qF ".catch(function () { location.href = url; });" "$DEST/tabs/customer_app/portal.php" 2>/dev/null && R6_BAD="$R6_BAD portal:tokenless-fallback-present"
grep -qF "'data_report_handoff_unconfigured'" "$DEST/includes/api/api_customer_app.php" 2>/dev/null || R6_BAD="$R6_BAD api:mint-does-not-refuse-empty-inputs"
grep -qF 'user-scalable=no' "$DEST/tabs/customer_app/login_web.php" 2>/dev/null && R6_BAD="$R6_BAD login:zoom-still-blocked"
grep -qF '24h uptime' "$DEST/tabs/customer_app/portal.php" 2>/dev/null && R6_BAD="$R6_BAD portal:invented-uptime-still-drawn"
grep -qF 'v4.12.20<br>' "$DEST/tabs/customer_app/portal.php" 2>/dev/null && R6_BAD="$R6_BAD portal:hard-coded-version-still-drawn"
# 5.18.83: the record and its one rule, the activation step, the uCRM-side check, the page behind its gate, the staff
# actions, the four guards (accept, check-in, check-out, complete), the link kept out of every store, the channels off by default
for f in lib/InstallAuth.php lib/InstallAuthNotifier.php lib/InstallAuthEmails.php lib/InstallationTerms.php includes/api/api_install_auth.php tabs/customer_app/install_auth_page.php "$MIG"; do [ -f "$DEST/$f" ] || R6_BAD="$R6_BAD $f:missing"; done
grep -qF 'public static function decision(\PDO $pdo, array $config, array $job): array' "$DEST/lib/InstallAuth.php" 2>/dev/null || R6_BAD="$R6_BAD install-auth:no-one-rule"
grep -qF 'public static function activate(\PDO $pdo, $crm, array $config, string $by, ?int $now = null): array' "$DEST/lib/InstallAuth.php" 2>/dev/null || R6_BAD="$R6_BAD install-auth:no-activation"
grep -qF 'public static function observeUcrm(' "$DEST/lib/InstallAuth.php" 2>/dev/null || R6_BAD="$R6_BAD install-auth:no-ucrm-check"
grep -qF "public const VERSION = 'INSTALLATION-TERMS-v1.0';" "$DEST/lib/InstallationTerms.php" 2>/dev/null || R6_BAD="$R6_BAD terms:not-v1.0"
grep -qF "require __DIR__ . '/api/api_install_auth.php';" "$DEST/includes/api_handlers.php" 2>/dev/null || R6_BAD="$R6_BAD api_handlers:no-install-auth-require"
grep -qF "if (\$page === 'install_auth') {" "$DEST/public.php" 2>/dev/null || R6_BAD="$R6_BAD public:no-install-auth-route"
grep -qF 'if (!InstallAuth::enabled($iaConfig, $iaDataDir)) {' "$DEST/tabs/customer_app/install_auth_page.php" 2>/dev/null || R6_BAD="$R6_BAD page:no-gate"
grep -qF "\$job, \$statusInt, 'accept', (int)(\$_sjMe['id'] ?? 0));" "$DEST/includes/api/api_scheduling.php" 2>/dev/null || R6_BAD="$R6_BAD guard:accept"
grep -qF "\$job, 2, 'complete', \$rid);" "$DEST/includes/api/api_scheduling.php" 2>/dev/null || R6_BAD="$R6_BAD guard:complete"
grep -qF "\$_iaJob, 1, 'checkin', (int)(\$me2['id'] ?? 0));" "$DEST/includes/api/api_field_ops.php" 2>/dev/null || R6_BAD="$R6_BAD guard:checkin"
grep -qF "\$_iaJob, 2, 'checkout', (int)(\$me2['id'] ?? 0));" "$DEST/includes/api/api_field_ops.php" 2>/dev/null || R6_BAD="$R6_BAD guard:checkout"
grep -qF "public const NEVER_QUEUED = ['app_otp', 'ops_install_auth_request'];" "$DEST/lib/NotificationService.php" 2>/dev/null || R6_BAD="$R6_BAD notify:link-may-be-queued"
grep -qF "'body'       => self::storable(\$message)," "$DEST/lib/NotificationService.php" 2>/dev/null || R6_BAD="$R6_BAD notify:link-kept-in-inbox"
grep -qF 'private function installAuthObserve(int $jobId, ?array $was, array $job): ?array' "$DEST/lib/JobNotifier.php" 2>/dev/null || R6_BAD="$R6_BAD job-notifier:no-ucrm-check"
grep -qF "\$_iaGone  = !is_array(\$_iaStill) && (int)(\$crm->getLastError()['http_code'] ?? 0) === 404;" "$DEST/webhook.php" 2>/dev/null || R6_BAD="$R6_BAD webhook:delete-not-confirmed"
grep -qF "'install_auth_enabled' => ['bool'," "$DEST/tools/set_config.php" 2>/dev/null || R6_BAD="$R6_BAD set-config:no-switch"
grep -qF '$iaActivation = InstallAuth::activate($iaPdo, CrmApiClient::fromUcrm($root, $iaCur),' "$DEST/tools/set_config.php" 2>/dev/null || R6_BAD="$R6_BAD set-config:no-activation-step"
grep -qF "return in_array(strtolower(trim((string)\$v)), ['1', 'on', 'true', 'yes'], true);" "$DEST/lib/CustomerEmailDispatcher.php" 2>/dev/null || R6_BAD="$R6_BAD emails:not-off-by-default"
grep -qF '🔴 CUSTOMER ACCEPTANCE REQUIRED' "$DEST/tabs/support/scheduling.php" 2>/dev/null || R6_BAD="$R6_BAD job-page:no-panel"
# 5.18.84: the booking WhatsApp — the class, its switch and Uganda gate, the claim before the send, the call in job.add
# beside the e-mail, the helper that never throws, the setting
[ -f "$DEST/$WA_LIB" ] || R6_BAD="$R6_BAD $WA_LIB:missing"
grep -qF "public const FLAG  = 'customer_wa_install_scheduled';" "$DEST/$WA_LIB" 2>/dev/null || R6_BAD="$R6_BAD booking-wa:no-switch"
grep -qF '        return StaffJobsGate::applies($config, $dataDir);' "$DEST/$WA_LIB" 2>/dev/null || R6_BAD="$R6_BAD booking-wa:no-uganda-gate"
grep -qF '        if (!CustomerEmailDispatcher::claimOnce($pdo, self::claimKey($jobId))) {' "$DEST/$WA_LIB" 2>/dev/null || R6_BAD="$R6_BAD booking-wa:no-claim"
grep -qF '            whInstallScheduledWhatsApp($jobId, is_array($job) ? $job : [], is_array($client ?? null) ? $client : [],' "$DEST/webhook.php" 2>/dev/null || R6_BAD="$R6_BAD webhook:no-booking-call"
grep -qF 'function whInstallScheduledWhatsApp(int $jobId, array $job, array $client, string $technician, array $config, string $dataDir,' "$DEST/webhook.php" 2>/dev/null || R6_BAD="$R6_BAD webhook:no-booking-helper"
grep -qF "'customer_wa_install_scheduled' => ['bool'," "$DEST/tools/set_config.php" 2>/dev/null || R6_BAD="$R6_BAD set-config:no-booking-switch"
# 5.18.85: the quotation reader (the latest, this client's only), the prefill's one read of uCRM's quotations, the form's
# note and its transport guard, the technician's name from the staff account or uCRM's users/admins in job.add
[ -f "$DEST/$QP_LIB" ] || R6_BAD="$R6_BAD $QP_LIB:missing"
grep -qF 'public static function pick($quotes, int $clientId): ?array' "$DEST/$QP_LIB" 2>/dev/null || R6_BAD="$R6_BAD quotation:no-pick"
grep -qF "            if ((int)(\$q['clientId'] ?? 0) !== \$clientId) continue;" "$DEST/$QP_LIB" 2>/dev/null || R6_BAD="$R6_BAD quotation:other-clients"
grep -qF "\$iaQList = \$iaQCrm->get('billing/quotes?' . http_build_query(['clientId' => \$cid]));" "$DEST/includes/api/api_install_auth.php" 2>/dev/null || R6_BAD="$R6_BAD prefill:no-quotation-read"
grep -qF 'Filled from quotation <b>' "$DEST/tabs/support/scheduling.php" 2>/dev/null || R6_BAD="$R6_BAD form:no-quotation-note"
grep -qF "if(window._iaTransportAsk && v('iaTransport')===''){" "$DEST/tabs/support/scheduling.php" 2>/dev/null || R6_BAD="$R6_BAD form:no-transport-guard"
grep -qF 'public static function technicianName(int $ucrmUserId, $store, $crm): string' "$DEST/$WA_LIB" 2>/dev/null || R6_BAD="$R6_BAD booking-wa:no-name-lookup"
grep -qF "\$_whTech = \$_whJobNotifier ? whInstallTechName(\$assignedUserId, \$store, \$crm) : '';" "$DEST/webhook.php" 2>/dev/null || R6_BAD="$R6_BAD webhook:no-technician-name"
[ -z "$R6_BAD" ] && ok "R6 every earlier surface is installed as before (the photo surface, the 5.18.67 card, the 5.18.68 chain, the 5.18.69–5.18.72 fixes, 5.18.74's hand-off setting and \"How to pay\", 5.18.83's authorisation — the record and its one rule, the activation step, the uCRM-side check, the page behind its gate, the staff actions, the four guards, the link kept out of every store, the e-mails off by default, the job-page panel), 5.18.84's booking WhatsApp (the class with its switch, Uganda gate and once-per-job claim, its call in job.add beside the e-mail, the setting), and 5.18.85's pieces are in place: the quotation reader, the prefill's read of uCRM's quotations, the form's note and transport guard, the technician's name" || bad "R6 incomplete:$R6_BAD"

# R7 — cash in hand on the live book, READ-ONLY: tools/cash_in_hand.php (5.18.71's, unchanged by this release) prints the
# ledger's running balance per currency and the base per project — the figures the landing hero shows. Nothing is written.
R7_OUT="$(docker exec "$CONTAINER" php "$IN_CONTAINER/$CIH_TOOL" 2>&1)"; R7_RC=$?
if [ "$R7_RC" = "0" ] && printf '%s' "$R7_OUT" | grep -qF 'READ-ONLY — nothing was changed'; then
  ok "R7 cash in hand on the live book, read-only — what the landing hero shows: $(printf '%s' "$R7_OUT" | grep -E 'CASH IN HAND' | tr -s ' ' | sed 's/^ //' | paste -sd '|' - | sed 's/|/ · /g')"
  printf '%s\n' "$R7_OUT" | grep -E 'CASH IN HAND|Fiber & Starlink|DishNet 4G|BlueCARD' | sed 's/^/     /'
else
  bad "R7 the cash-in-hand tool failed (exit $R7_RC): $(printf '%s' "$R7_OUT" | tail -3 | tr '\n' ' ' | cut -c1-300)"
fi
TB_AFTER="$(photo_tables_state)"; PF_AFTER="$(photo_files_state)"
[ "$TB_AFTER" = "$TB_BEFORE" ] && [ "$PF_AFTER" = "$PF_BEFORE" ] && ok "R7 the photo tables and files read exactly as before the deploy ($TB_AFTER, $PF_AFTER) — the tool touched no data" || bad "R7 the photo tables/files changed across the deploy ($TB_BEFORE → $TB_AFTER, $PF_BEFORE → $PF_AFTER)"


# R8 — the installed quotation reader, under the server's own PHP, on a quotation shaped like DishNet Uganda's 000181: a
# kit, a monthly plan, "Professional Installation", and transport "borne by the customer" at 0. A pure function: nothing
# is read from uCRM and nothing is written.
R8_OUT="$(docker exec "$CONTAINER" php -r '
  require_once $argv[1] . "/lib/QuotationPrefill.php";
  $q = ["id" => 1, "clientId" => 1, "number" => "R8", "status" => 1, "createdDate" => "2026-10-06T10:15:00+0300", "items" => [
    ["label" => "Starlink Mini Kit + Mini Router", "price" => 2249000, "quantity" => 1, "total" => 2249000, "unit" => "Pc"],
    ["label" => "Residential Lite (up to 100 Mbps)", "price" => 249000, "quantity" => 1, "total" => 249000, "unit" => "Monthly"],
    ["label" => "Professional Installation", "price" => 150000, "quantity" => 1, "total" => 150000, "unit" => "Time"],
    ["label" => "Transportation charges to and from the site shall be borne by the customer.", "price" => 0, "quantity" => 1, "total" => 0, "unit" => "Time"]]];
  $p = QuotationPrefill::pick([$q], 1); $x = $p ? QuotationPrefill::suggest($p) : [];
  echo ($x["equipment"] ?? "?"), "|", ($x["service"] ?? "?"), "|", var_export($x["installation"] ?? null, true), "|", var_export($x["transport"] ?? null, true), "|", empty($x["transport_unpriced"]) ? "fill" : "ask";
' "$IN_CONTAINER" 2>&1)"
if [ "$R8_OUT" = "Starlink Mini Kit + Mini Router|Residential Lite (up to 100 Mbps)|'150000'|NULL|ask" ]; then
  ok "R8 the installed quotation reader reads a quotation shaped like 000181 as the form will: the kit, the plan, installation 150000, transport asked for — nothing read from uCRM, nothing written"
else bad "R8 the installed quotation reader read a 000181-shaped quotation as: $(printf '%s' "$R8_OUT" | head -c 300)"; fi
elif [ "$MODE" = "rollback" ]; then
hdr "RB. $BASELINE_VERSION, back in place (read-only)"
IV="$(grep -o '"version": *"5[^"]*"' "$DEST/manifest.json" | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/')"
[ "$IV" = "$BASELINE_VERSION" ] && ok "RB the installed manifest says $IV" || bad "RB the installed manifest says ${IV:-?}, expected $BASELINE_VERSION"
RB_REF=""
grep -qF 'QuotationPrefill' "$DEST/includes/api/api_install_auth.php" 2>/dev/null && RB_REF="$RB_REF api_install_auth.php"
grep -qF 'Filled from quotation' "$DEST/tabs/support/scheduling.php" 2>/dev/null && RB_REF="$RB_REF scheduling.php"
grep -qF 'whInstallTechName' "$DEST/webhook.php" 2>/dev/null && RB_REF="$RB_REF webhook.php"
[ -z "$RB_REF" ] && ok "RB the installed plugin is $BASELINE_VERSION's again: no file of $BASELINE_VERSION reaches the quotation reader or the new name lookup — the request form opens empty, as before" || bad "RB the installed plugin still reaches 5.18.85's pieces:$RB_REF"
# 5.18.84's booking WhatsApp is live on this server: the rollback must leave it whole.
RBW=""
[ -f "$DEST/$WA_LIB" ] || RBW="$RBW class"
grep -qF "public const FLAG  = 'customer_wa_install_scheduled';" "$DEST/$WA_LIB" 2>/dev/null || RBW="$RBW switch"
grep -qF '        if (!CustomerEmailDispatcher::claimOnce($pdo, self::claimKey($jobId))) {' "$DEST/$WA_LIB" 2>/dev/null || RBW="$RBW claim"
grep -qF '            whInstallScheduledWhatsApp($jobId, is_array($job) ? $job : [], is_array($client ?? null) ? $client : [],' "$DEST/webhook.php" 2>/dev/null || RBW="$RBW call"
grep -qF "'customer_wa_install_scheduled' => ['bool'," "$DEST/tools/set_config.php" 2>/dev/null || RBW="$RBW setting"
[ -z "$RBW" ] && ok "RB 5.18.84's booking WhatsApp is whole: the class with its switch and once-per-job claim, its call in job.add, its setting" || bad "RB 5.18.84's booking WhatsApp is incomplete after the rollback:$RBW"
# 5.18.83's authorisation is live on this server: the rollback must leave it whole.
RBA=""
grep -qF 'public static function decision(\PDO $pdo, array $config, array $job): array' "$DEST/lib/InstallAuth.php" 2>/dev/null || RBA="$RBA one-rule"
grep -qF "if (\$page === 'install_auth') {" "$DEST/public.php" 2>/dev/null || RBA="$RBA route"
grep -qF 'if (!InstallAuth::enabled($iaConfig, $iaDataDir)) {' "$DEST/tabs/customer_app/install_auth_page.php" 2>/dev/null || RBA="$RBA page-gate"
grep -qF "\$job, \$statusInt, 'accept', (int)(\$_sjMe['id'] ?? 0));" "$DEST/includes/api/api_scheduling.php" 2>/dev/null || RBA="$RBA guard:accept"
grep -qF "\$job, 2, 'complete', \$rid);" "$DEST/includes/api/api_scheduling.php" 2>/dev/null || RBA="$RBA guard:complete"
grep -qF "\$_iaJob, 1, 'checkin', (int)(\$me2['id'] ?? 0));" "$DEST/includes/api/api_field_ops.php" 2>/dev/null || RBA="$RBA guard:checkin"
grep -qF "\$_iaJob, 2, 'checkout', (int)(\$me2['id'] ?? 0));" "$DEST/includes/api/api_field_ops.php" 2>/dev/null || RBA="$RBA guard:checkout"
grep -qF "public const NEVER_QUEUED = ['app_otp', 'ops_install_auth_request'];" "$DEST/lib/NotificationService.php" 2>/dev/null || RBA="$RBA never-queued"
grep -qF "'install_auth_enabled' => ['bool'," "$DEST/tools/set_config.php" 2>/dev/null || RBA="$RBA set-config:switch"
[ -z "$RBA" ] && ok "RB 5.18.83's Customer Installation Authorisation is whole: the one rule, the page behind its gate, the four guards, the link never queued, its switch in the tool" || bad "RB 5.18.83's authorisation is incomplete after the rollback:$RBA"
check_086 "RB"
grep -qF 'public function dataReportHandoff(): bool' "$DEST/lib/TenantProfile.php" 2>/dev/null && grep -qF '>How to pay</div>' "$DEST/tabs/customer_app/portal.php" 2>/dev/null && ok "RB the 5.18.74 hand-off setting and \"How to pay\" are still there" || bad "RB the installed plugin lost 5.18.74 code"
grep -qF "'Still to account for'" "$DEST/tabs/accounts/staff_cashbooks.php" 2>/dev/null && ok "RB the 5.18.72 Staff Cashbooks wording is still there" || bad "RB the installed plugin lost 5.18.72 code"
grep -qF 'Cash in hand — per currency' "$DEST/tabs/accounts/accounts_dashboard.php" 2>/dev/null && grep -qF '== cash in hand (5.18.71)' "$DEST/$CIH_TOOL" 2>/dev/null && ok "RB the 5.18.71 landing hero and cash-in-hand tool are still there" || bad "RB the installed plugin lost 5.18.71 code"
grep -qF 'var _fr3Base' "$DEST/tabs/sales/wallet.php" 2>/dev/null && grep -qF '(5.18.70)' "$DEST/$RECORDS_TOOL" 2>/dev/null && grep -qF "'currency'        => \$manCur," "$DEST/tabs/accounts/staff_cashbooks.php" 2>/dev/null && ok "RB the 5.18.70 Field Register fix and records tool and the 5.18.69 Manual Entry stamp are still there" || bad "RB the installed plugin lost 5.18.69/5.18.70 code"
grep -qF 'CASH IN HAND</div>' "$DEST/tabs/accounts/cashbook.php" 2>/dev/null && grep -qF "dn_book_base(null)" "$DEST/lib/StaffCashPositionService.php" 2>/dev/null && ok "RB the 5.18.68 staff-cash chain and card are still there" || bad "RB the installed plugin lost 5.18.68 code"
grep -qF 'var UG_PHOTOS' "$DEST/tabs/support/scheduling.php" 2>/dev/null && grep -qF -- "$LAYOUT_MARK" "$DEST/tabs/support/scheduling.php" 2>/dev/null && ok "RB the 5.18.66 photo surface and the 5.18.67 card are still there" || bad "RB the installed job page lost 5.18.66/5.18.67 code"
note "RB the code is $BASELINE_VERSION again. The rollback restores code only. Files $EXPECTED_VERSION added stay on disk, reached by nothing (deploy-hybrid.sh never deletes): $(printf '%s ' $EXPECTED_ADDED). A request already sent keeps the charges it was sent with"
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
  echo "  what changed      Uganda: the authorisation's request form starts from the customer's latest uCRM quotation — the kit, the plan, the installation charge, a priced transport charge, other one-time charges; transport the quotation lists at 0 is asked for — and names it; the booking WhatsApp and the installation e-mail name the technician (the staff account's first name, else uCRM's)"
  echo "  the switches      install_auth_enabled ${IA_AFTER#ia=}, $WA_KEY ${WA_AFTER#wa=} (files/store) — as the operator left them (V3b, V3c, R2); $EXPECTED_VERSION adds none"
  echo "  try it            open a Starlink installation job whose customer has a quotation in uCRM, then Request customer authorisation: the form names the quotation and is filled from it (enter transport if it asks). A new installation job's booking WhatsApp names its technician"
  echo "  what did not      South Sudan (Uganda-only changes; its e-mail as before); every configuration value (V3/V3b/V3c — this run switches nothing); the authorisation's rule, page and guards, and every request already sent (R6, R3); uCRM (the form only reads quotations, when a person opens it); every cash record (R7 only reads); the photo tables and files (R3); the undeployed partner-portal, CSRF and AI-layer work on the branch (not in this release, A0/R4)"
  echo "  migrations        none (086, 5.18.83's, still complete: R3)"
  echo "  later             cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --after-only"
fi
echo "  checks            $PASS ok, $FAIL failed, $NOTE notes"
if [ "$FAIL" = "0" ]; then echo; echo "  $EXPECTED_VERSION ($MODE): PASSED. Send this LOG FILE back (not a copy of the terminal)."
else echo; echo "  $EXPECTED_VERSION ($MODE): $FAIL FAILED — send the log file; do not roll back on your own unless staff or customers are affected."; fi
if [ "$MODE" != "rollback" ]; then
  echo
  echo "  Only if it is ever needed — its own command, never pasted together with the deploy (root docs/44 §16.9) —"
  echo "  the rollback to $BASELINE_VERSION ($BASELINE_COMMIT) asks you to type ROLLBACK before it changes anything. It puts back the"
  echo "  empty request form and the booking WhatsApp without the technician's name; both switches stay as the operator left them:"
  echo "      cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --rollback"
  echo "  or by hand, if this script cannot run:"
  echo "      cd $REPO && git checkout $BASELINE_COMMIT && bash scripts/deploy-hybrid.sh && git checkout -"
fi
[ "$FAIL" = "0" ]
}

main "$@"; exit $?
