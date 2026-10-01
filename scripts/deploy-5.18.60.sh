#!/usr/bin/env bash
#
# deploy-5.18.60.sh — deploy plugin 5.18.60 over 5.18.59, or roll it back to 5.18.59.
#
#   5.18.60  Customer emails can copy the client's other contacts, and payment reminders can go by e-mail.
#            Two operator-facing additions, BOTH shipped OFF — deploying this changes nothing a customer receives
#            until an operator turns a switch on with tools/set_customer_emails.php:
#              • email_cc_contacts      when on, every customer e-mail (welcome, invoice, receipt, quotation, reminder)
#                                       goes TO the client's main/billing contact and CCs every OTHER contact e-mail on
#                                       that uCRM client. The To is unchanged; CC is purely additive. OTP / login-code
#                                       e-mail is NEVER copied (it goes to one person, by design).
#              • reminder_email_enabled when on (with the master switch on), the Uganda payment-reminder cron also sends
#                                       an e-mail alongside each WhatsApp, for the BEFORE-due tiers only (7/3/1 days).
#                                       Overdue tiers stay suppressed on prepaid — no "suspension" wording, as before.
#            CC is delivered for real (a Cc header AND one RCPT TO per copied address); an invalid/refused CC never sinks
#            the send. The reminder e-mail is prepaid-safe (never threatens suspension) and is NOT a catalogue template,
#            so the Email Preview screen and the South Sudan install are byte-for-byte unchanged.
#
#   Scope:   code-only. No migration, no new table, no new admin tab, no website change, no cron schedule change. It adds
#            lib/EmailRecipients.php and tests/test_reminder_email_cc.php, adds tools/mail_log_doctor.php (a read-only
#            diagnostic), and edits lib/MailService.php, lib/CustomerEmailDispatcher.php, lib/CustomerEmails.php,
#            lib/InvoiceReminders.php, lib/QuotationService.php, cron/customer_reminders.php,
#            tools/set_customer_emails.php and manifest.json. It changes no table and no row.
#
#   Regression: because the whole plugin tree is copied, stage R doubles as a full check that Release A (5.18.49) through
#            5.18.59 are installed byte-for-byte as the pin has them — this release must lose none of it.
#
# 5.18.59 must be installed first (scripts/deploy-5.18.59.sh): this script refuses any other live commit.
#
# Run as root on the server, then send back THE LOG FILE (never a copy of the terminal):
#
#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg \
#     && mkdir -p /root/dnb-5.18.60 \
#     && bash scripts/deploy-5.18.60.sh 2>&1 | tee /root/dnb-5.18.60/deploy-$(date -u +%Y%m%dT%H%M%SZ).log
#
# The rollback is a separate command, printed at the end of the deploy's log. It is never pasted together with the one
# above: pasted together, the shell runs both (root docs/44 §16.9).
#
# Options
#   --after-only         the deploy already happened: run the checks again
#   --rollback           put 5.18.59 back (typed ROLLBACK), then check it
#   --plugin-base <url>  the public URL of public.php, if the derived one is wrong
#
# What it never does: touch a configuration value (it never turns a switch on — that stays the operator's own act), a
# customer, a staff account, a job, a message, the webhook key, Traefik, UISP or the website; send anything; sign anyone
# in; run a cron; call uCRM. Every read of the plugin's data opens the database READ-ONLY as its owner. The writes are the
# documented deploy (or rollback), a backup under /root/dnb-5.18.60/, a record of where the deploy started, and the time
# of the copy on the files this release changes.
#
# Stages:  A before-evidence, the syntax check under the server's own PHP, the switch state, the backup → GO/NO-GO
#          B the documented deploy (or, with --rollback, the documented deploy of 5.18.59); the copy's time on each file
#          V the public pages (no loop, the portal still refuses, the Uganda contacts), the two new switches STILL OFF on
#            the live install (the deploy turned nothing on), and no fatal since the deploy
#          R 5.18.60 installed: the changed files byte-for-byte and the version (R1); the two switches read off (R2);
#            OTP e-mail carries no Cc in the installed OtpEmail.php (R3); the CC + reminder code is present in the
#            installed libs (R4); reminder_due is NOT a catalogue template (R5); Release A→5.18.59 still installed (R6)
#          F summary
set -uo pipefail
umask 077

main() {
PLUGIN="dishnet-hybrid-sudan"
CONTAINER="${UCRM_CONTAINER:-ucrm}"
EXPECTED_PLUGIN_COMMIT="3b5e61f"   # 5.18.60 — CC customer emails + payment reminders by email (both OFF by default), on 5.18.59
EXPECTED_VERSION="5.18.60"
BASELINE_COMMIT="d6d0a2e"          # 5.18.59 — what the server runs first (the distributor-application capture), and the rollback
BASELINE_VERSION="5.18.59"
RELEASE_A_BASE="e076632"           # 5.18.49 — the regression base: R6 checks every file from Release A through 5.18.59 is installed as pinned
BRANCH="claude/study-this-jhe2eg"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$REPO/$PLUGIN"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="${DNB_OUT:-/root/dnb-5.18.60}"; mkdir -p "$OUT"; chmod 700 "$OUT"
STATE="$OUT/state-$EXPECTED_VERSION.env"
GUARD_SECONDS="${GUARD_SECONDS:-60}"
TOOL="tools/set_customer_emails.php"   # reads the live switch state (read-only with --show)

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

CHANGED="$(git -C "$REPO" diff --no-renames --name-only --diff-filter=AM "$BASELINE_COMMIT" "$EXPECTED_PLUGIN_COMMIT" -- "$PLUGIN")"
ADDED="$(git -C "$REPO" diff --no-renames --name-only --diff-filter=A "$BASELINE_COMMIT" "$EXPECTED_PLUGIN_COMMIT" -- "$PLUGIN")"
DELETED="$(git -C "$REPO" diff --no-renames --name-only --diff-filter=D "$BASELINE_COMMIT" "$EXPECTED_PLUGIN_COMMIT" -- "$PLUGIN")"
N_CHANGED="$(printf '%s\n' "$CHANGED" | grep -c . || true)"; N_ADDED="$(printf '%s\n' "$ADDED" | grep -c . || true)"
echo "  $EXPECTED_VERSION         $N_CHANGED files differ from $BASELINE_COMMIT: $((N_CHANGED - N_ADDED)) changed, $N_ADDED added, $(printf '%s\n' "$DELETED" | grep -c . || true) removed"
[ "$N_CHANGED" -gt 0 ] || stop "Git reports no file between $BASELINE_COMMIT and $EXPECTED_PLUGIN_COMMIT"
[ -z "$DELETED" ] || note "files removed/renamed in $EXPECTED_VERSION stay on the server under their old name (deploy-hybrid.sh never deletes): $(printf '%s ' $DELETED | sed "s#$PLUGIN/##g")"

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
[ -n "$PHPV" ] || stop "no php inside the container"
echo "  container PHP   $PHPV"
case "$PHPV" in 5.*|7.*) stop "the container's PHP is $PHPV; $EXPECTED_VERSION needs 8.0 or later (as $BASELINE_VERSION does)";; esac

# The live switch state, read with the plugin's own tool (--show is read-only). Proves the deploy turns nothing on.
switch_state() {  # prints "cc=<on|off> reminder=<on|off>"
  docker exec "$CONTAINER" php "$IN_CONTAINER/$TOOL" --show 2>/dev/null \
    | awk '
      /CC the client.?s other contacts/ { cc = ($NF=="ON"?"on":"off") }
      /Payment reminders by email/      { rm = ($NF=="ON"?"on":"off") }
      END { printf "cc=%s reminder=%s\n", (cc?cc:"?"), (rm?rm:"?") }'
}
SW_BEFORE="$(switch_state)"
echo "  switches        $SW_BEFORE   (both start off; this deploy never changes them)"

# The public address, by the plugin's own rule.
if [ -z "$PLUGIN_BASE" ]; then
  PLUGIN_BASE="$(docker exec "$CONTAINER" php -r '
    $r=$argv[1]; $pdd=$argv[2]; $u=@json_decode((string)@file_get_contents($r."/ucrm.json"),true)?:[];
    $over="";
    foreach ([$r."/data/config.json", $pdd."/config.json", $pdd."/kyc_config.json"] as $f) { $c=@json_decode((string)@file_get_contents($f),true)?:[]; $v=rtrim(trim((string)($c["crm_public_url"]??"")),"/"); if($v!==""){$over=$v; break;} }
    if($over!==""){ echo $over."/crm/_plugins/".basename($r)."/public.php"; exit; }
    if(!empty($u["pluginPublicUrl"])){ echo rtrim($u["pluginPublicUrl"],"/"); exit; }
    $b=rtrim((string)($u["ucrmPublicUrl"]??""),"/"); $b=preg_replace("#/crm$#","",$b); echo $b?$b."/crm/_plugins/".basename($r)."/public.php":"";' "$IN_CONTAINER" "${PDD_IN:-$IN_CONTAINER/data}" 2>/dev/null || true)"
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
  PDD_IN="$(docker exec "$CONTAINER" php -r '$u=@json_decode((string)@file_get_contents($argv[1]),true); echo rtrim((string)($u["pluginDataDir"]??""),"/");' "$IN_CONTAINER/ucrm.json" 2>/dev/null || true)"
  if [ -z "$PDD_IN" ]; then
    if docker exec "$CONTAINER" test -f "/data/ucrm/data/plugins/.$PLUGIN-data/plugin.sqlite3"; then PDD_IN="/data/ucrm/data/plugins/.$PLUGIN-data"; else PDD_IN="$IN_CONTAINER/data"; fi
  fi
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
  printf 'DEPLOYED_AT=%s\nFROM_COMMIT=%s\nSW_BEFORE=%s\n' "$TS" "$LIVE_BEFORE" "$SW_BEFORE" > "$STATE"
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
    echo "  the checkout is on $DEPLOY_REF ($HEAD_PLUGIN); checking out $EXPECTED_PLUGIN_COMMIT ($EXPECTED_VERSION) for the documented deploy"
    git -C "$REPO" checkout -q "$EXPECTED_PLUGIN_COMMIT" || stop "git checkout $EXPECTED_PLUGIN_COMMIT failed — nothing was deployed"
  fi
  bash "$REPO/scripts/deploy-hybrid.sh" 2>&1 | sed 's/^/     /'; RC=${PIPESTATUS[0]}
  if [ "$RC" = "2" ]; then note "the container was still restarting; waiting for it"; for i in 1 2 3 4 5 6 7 8 9 10 11 12; do sleep 10; if bash "$REPO/scripts/deploy-hybrid.sh" --check 2>&1 | grep -q 'Up to date'; then RC=0; break; fi; done; fi
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
hdr "V. Verification over the public address (nothing signed in; no switch changed)"
# ════════════════════════════════════════════════════════
if [ -n "$PLUGIN_BASE" ]; then
  http GET "$PLUGIN_BASE?page=customer_login" -L
  if [ "$HTTP_CODE" = "000" ]; then
    # The server's own shell could not open a connection to the public URL. That is the ABSENCE of a reading, not a
    # failing page: a code-only release changes no routing (R6 proves the sign-in code is byte-intact) and V4 proves no
    # fatal, so a NOTE — never a FAIL that would read as "the page is broken". The commonest cause here is a :8443
    # public port the host cannot reach from inside (hairpin); the plugin's own :8443 rule is "not the public address".
    case "$PLUGIN_BASE" in
      *:8443*) note "V1/V2 could not probe the public page: the derived address is $PLUGIN_BASE and the server cannot reach its own :8443 public port from inside the host (curl 000 — hairpin). This says nothing about whether the page works; the code is verified by R1–R6 below and by V4 (no fatal). To confirm the page itself, open $PLUGIN_BASE?page=customer_login in a browser, or re-run: bash scripts/deploy-$EXPECTED_VERSION.sh --after-only --plugin-base <an address the server can reach>" ;;
      *) note "V1/V2 could not probe the public page: $PLUGIN_BASE is not reachable from the server (curl 000). This says nothing about the page; R1–R6 and V4 verify the code. Open the sign-in page in a browser, or re-run with --plugin-base <a reachable address>" ;;
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
  fi
else note "V1/V2 skipped — the plugin's public URL could not be derived (re-run with --plugin-base); the R checks below read the install directly"; fi

# V3 — the two new switches are STILL OFF on the live install: the deploy turned nothing on. (The operator turns them on
# later, deliberately, with tools/set_customer_emails.php.)
SW_AFTER="$(switch_state)"
if [ "$SW_AFTER" = "cc=off reminder=off" ]; then ok "V3 both new switches read off on the live install ($SW_AFTER) — the deploy sent nothing and copied no one"
elif printf '%s' "$SW_AFTER" | grep -q '='; then note "V3 a switch reads on ($SW_AFTER) — an operator turned it on; the deploy itself never does. Expected right after a deploy: cc=off reminder=off"
else bad "V3 could not read the switch state ($SW_AFTER)"; fi

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

# R2 — the two switches read off on the installed tool (re-reads the live config through the installed code).
[ "$SW_AFTER" = "cc=off reminder=off" ] && ok "R2 the installed set_customer_emails --show reports both new switches off (cc + reminder): deploying changed nothing a customer receives" \
  || note "R2 the installed switches read '$SW_AFTER' (expected cc=off reminder=off right after a deploy; non-off means an operator turned one on)"

# R3 — OTP / login-code e-mail carries NO Cc in the installed OtpEmail.php (a login code must never be copied to anyone).
if [ -f "$DEST/lib/OtpEmail.php" ]; then
  if grep -q "'Cc'" "$DEST/lib/OtpEmail.php" 2>/dev/null || grep -q '"Cc"' "$DEST/lib/OtpEmail.php" 2>/dev/null || grep -q 'EmailRecipients' "$DEST/lib/OtpEmail.php" 2>/dev/null; then
    bad "R3 the installed OtpEmail.php references a Cc header or EmailRecipients — login codes must go to one person only"
  else ok "R3 the installed OtpEmail.php sets no Cc and resolves no contacts — login codes are never copied"; fi
else bad "R3 lib/OtpEmail.php is missing on the server"; fi

# R4 — the CC + reminder code is present in the installed libs.
R4_BAD=""
grep -q 'EmailRecipients' "$DEST/lib/CustomerEmailDispatcher.php" 2>/dev/null || R4_BAD="$R4_BAD dispatcher:EmailRecipients"
grep -q 'function sendReminderDue' "$DEST/lib/CustomerEmailDispatcher.php" 2>/dev/null || R4_BAD="$R4_BAD dispatcher:sendReminderDue"
grep -q 'function ccEnabled' "$DEST/lib/CustomerEmailDispatcher.php" 2>/dev/null || R4_BAD="$R4_BAD dispatcher:ccEnabled"
grep -q 'rcpt_cc' "$DEST/lib/MailService.php" 2>/dev/null || R4_BAD="$R4_BAD mailservice:rcpt_cc"
[ -f "$DEST/lib/EmailRecipients.php" ] || R4_BAD="$R4_BAD lib/EmailRecipients.php:missing"
grep -q 'function reminderDue' "$DEST/lib/CustomerEmails.php" 2>/dev/null || R4_BAD="$R4_BAD customeremails:reminderDue"
[ -z "$R4_BAD" ] && ok "R4 the installed libs carry the CC + reminder code (EmailRecipients, MailService CC-RCPT, dispatcher ccEnabled + sendReminderDue, CustomerEmails::reminderDue)" || bad "R4 the installed libs are missing:$R4_BAD"

# R5 — reminder_due is NOT a catalogue template (so the preview screen and the South Sudan install are unchanged).
# It exists only as a render method; it must never be a CATALOGUE key. Ask the installed class itself.
R5="$(docker exec "$CONTAINER" php -r 'require $argv[1]; echo array_key_exists("reminder_due", CustomerEmails::CATALOGUE) ? "KEY" : "NOKEY";' "$IN_CONTAINER/lib/CustomerEmails.php" 2>/dev/null || echo "?")"
[ "$R5" = "NOKEY" ] && ok "R5 reminder_due is not a catalogue template on the install — Email Preview and South Sudan are unchanged" \
  || { [ "$R5" = "KEY" ] && bad "R5 reminder_due appears in CustomerEmails::CATALOGUE on the install — it must not" || note "R5 could not evaluate the catalogue ($R5)"; }

# R6 — Release A (5.18.49) → 5.18.59 installed as pinned (regression that nothing was lost).
RA_OK=0; RA_BAD=""
for f in $(git -C "$REPO" diff --no-renames --name-only --diff-filter=AM "$RELEASE_A_BASE" "$BASELINE_COMMIT" -- "$PLUGIN"); do
  rel="${f#"$PLUGIN"/}"
  git -C "$REPO" cat-file -e "$EXPECTED_PLUGIN_COMMIT:$f" 2>/dev/null || continue
  a="$(git -C "$REPO" show "$EXPECTED_PLUGIN_COMMIT:$f" 2>/dev/null | sha256sum | cut -c1-64)"
  b="$(sha256sum "$DEST/$rel" 2>/dev/null | cut -c1-64)"
  if [ -n "$b" ] && [ "$a" = "$b" ]; then RA_OK=$((RA_OK+1)); else RA_BAD="$RA_BAD $rel"; fi
done
[ -z "$RA_BAD" ] && ok "R6 all $RA_OK files from Release A through $BASELINE_VERSION are installed as $EXPECTED_PLUGIN_COMMIT has them — $EXPECTED_VERSION lost none of the prior releases" || bad "R6 files from Release A→$BASELINE_VERSION differ on the server:$RA_BAD"

elif [ "$MODE" = "rollback" ]; then
hdr "RB. $BASELINE_VERSION, back in place (read-only)"
IV="$(grep -o '"version": *"5[^"]*"' "$DEST/manifest.json" | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/')"
[ "$IV" = "$BASELINE_VERSION" ] && ok "RB the installed manifest says $IV" || bad "RB the installed manifest says ${IV:-?}, expected $BASELINE_VERSION"
note "RB the switches and the config are unchanged — this release wrote none, and the rollback restores code only"
fi

# ════════════════════════════════════════════════════════
hdr "F. Summary"
# ════════════════════════════════════════════════════════
LIVE_NOW="$(live_commit)"
case "$MODE" in
  rollback) echo "  serving           ${LIVE_NOW:-?}  (plugin $BASELINE_VERSION)"; echo "  deploy again      cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh" ;;
  *)        echo "  deployed commit   ${LIVE_NOW:-?}  (plugin $EXPECTED_VERSION)" ;;
esac
[ -n "$BK" ] && echo "  backup            $BK"
if [ "$MODE" != "rollback" ]; then
  echo "  what changed      the plugin CAN now CC a client's other contacts and send payment reminders by e-mail — both OFF until you turn them on"
  echo "  nothing sent      V3/R2 confirm both switches read off: this deploy copied no one and sent no reminder"
  echo "  to turn CC on     docker exec $CONTAINER php $IN_CONTAINER/$TOOL --cc on            (copies the client's other contacts on every customer e-mail)"
  echo "  reminders by mail docker exec $CONTAINER php $IN_CONTAINER/$TOOL --master on --reminder-email on   (before-due only; alongside WhatsApp)"
  echo "  see the state     docker exec $CONTAINER php $IN_CONTAINER/$TOOL --show"
  echo "  OTP never copied  R3 confirms login-code e-mail carries no Cc"
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
