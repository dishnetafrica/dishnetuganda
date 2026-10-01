#!/usr/bin/env bash
# Rehearse scripts/deploy-5.18.60.sh — the deploy, its checks, and the ROLLBACK — before the operator runs any of it.
#
# 5.18.60 lets customer e-mails CC the client's other uCRM contacts, and lets the Uganda payment-reminder cron send a
# before-due reminder by e-mail alongside WhatsApp. BOTH ship OFF. It sits on 5.18.59 and is code-only: it adds three
# files (lib/EmailRecipients.php, tools/mail_log_doctor.php, tests/test_reminder_email_cc.php) and edits nine
# (lib/MailService.php, lib/CustomerEmailDispatcher.php, lib/CustomerEmails.php, lib/InvoiceReminders.php,
# lib/QuotationService.php, cron/customer_reminders.php, tools/set_customer_emails.php, manifest.json, and the version
# pin in tests/test_distributor_apply.php). There is NO migration, NO new table, NO website change. This rehearsal proves:
#   · the script runs end to end against a 5.18.59 base and PASSES;
#   · the deploy turns NOTHING on — V3 and R2 read both new switches off on the live install through the plugin's own
#     tool (set_customer_emails.php --show), and the summary says so;
#   · those switch checks have TEETH — flip CC on with the installed tool and --after-only now NOTES "a switch reads on"
#     (and still PASSES, because the deploy itself never flips a switch); flip it back and it reads off again;
#   · R3 confirms the installed OtpEmail.php carries no Cc header and resolves no contacts — a login code is never copied;
#   · R4 confirms the CC + reminder code is installed; R5 confirms reminder_due is NOT a catalogue template (so Email
#     Preview and the South Sudan install are byte-for-byte unchanged); R1 installs the 12-file delta byte-for-byte and
#     the manifest reads 5.18.60; R6 confirms Release A (5.18.49) through 5.18.59 are installed as pinned;
#   · the data this release does not touch is unchanged, the backup is of 5.18.59, and the rollback command is printed
#     alone, after the verdict (root docs/44 §16.9);
#   · the rollback returns the plugin to 5.18.59, manifest 5.18.59, the config untouched (this release wrote none);
#   · the base gate holds: a server on an older commit is a NO-GO ("deploy 5.18.59 first"), and a copy still carrying the
#     placeholder pin stops before anything is read;
#   · R1 and R6 have teeth (a reverted changed file / a changed Release-A file is caught, by name);
#   · a weakened copy whose R3 grep is blinded is caught (control on the control): with a Cc header planted in the
#     installed OtpEmail.php the blinded copy still says R3 ok, while the real script FAILS R3, naming it.
#
# Everything is real except the container and the public address:
#   · the repository is a CLONE; the deploy and rollback run the real git checkout and the real scripts/deploy-hybrid.sh;
#   · the "container" is a directory a fake docker maps to; this machine's PHP stands in for the server's;
#   · the installed plugin starts as 5.18.59 exactly (git archive d6d0a2e), its data built by 5.18.59's own store;
#   · the public address answers stage V's customer pages (sign-in 200 with no loop, the portal refuses, no SS contact).
# DEPLOY and ROLLBACK are typed through a pseudo-terminal, as the operator types them.
#
# ONE sandbox shim, and only one: the deploy's switch_state() runs the installed set_customer_emails.php through
# docker exec, and that tool derives its own data directory with cliDataDir(). In the real container /data is real and
# cliDataDir() resolves natively; here the tool runs via the mapped PHP on the host, so we set DN_DATA_DIR to the
# sandbox data dir (cliDataDir() honours it first) — the tool then reads the real seeded config exactly as it would on
# the server. Nothing else is shimmed, and the deploy script itself never reads DN_DATA_DIR.
#
#   bash scripts/harness/deploy-5.18.60/rehearse.sh            REHEARSE_KEEP=<dir> keeps every run's full output
#
# It needs the script pinned to this checkout's plugin commit, and python3; it refuses to run where the server could be.
set -u
R="$(cd "$(dirname "$0")/../../.." && pwd)"
for f in /data/ucrm /opt/dishnet /var/run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
BASE=d6d0a2e; RA_BASE=e076632; OLDER=fcab6bd; BRANCH_FILE=scripts/deploy-5.18.60.sh; P=dishnet-hybrid-sudan
checkout_state() { { git -C "$R" rev-parse HEAD; git -C "$R" status --porcelain --untracked-files=no; git -C "$R" diff HEAD; } | sha256sum | cut -c1-16; }
CHECKOUT0="$(checkout_state)"
SB="$(mktemp -d)"; PIDS=()
cleanup() { for p in "${PIDS[@]:-}"; do kill "$p" 2>/dev/null; done; rm -rf "$SB"; }
trap cleanup EXIT
unset DN_VAULT_FILE DN_DATA_DIR
export NO_PROXY=127.0.0.1,localhost no_proxy=127.0.0.1,localhost
PASS=0; FAILN=0
check() { if [ "$1" = "$2" ]; then PASS=$((PASS+1)); echo "  ok   $3"; else FAILN=$((FAILN+1)); echo "  FAIL $3 (got '$1', want '$2')"; fi; }
has()   { grep -qF -- "$2" <<<"$1" && echo yes || echo no; }
cnt()   { grep -cF -- "$2" <<<"$1"; }
fails() { grep -cE '^  FAIL  ' <<<"$1"; }

# ── The clone ────────────────────────────────────────────────────────────────
git clone -q --no-hardlinks "$R" "$SB/repo" || { echo "cannot clone $R"; exit 1; }
REPO="$SB/repo"; DEPLOY="$REPO/$BRANCH_FILE"; BRANCH="$(git -C "$REPO" symbolic-ref --short HEAD)"
if git -C "$R" ls-files --error-unmatch "$BRANCH_FILE" >/dev/null 2>&1; then
  cmp -s "$R/$BRANCH_FILE" "$DEPLOY" || { echo "refusing: $BRANCH_FILE differs from the committed one — commit it, then rehearse"; exit 2; }
  WHICH="the committed script"
else cp "$R/$BRANCH_FILE" "$DEPLOY"; WHICH="the working copy (not yet committed; untracked in the clone)"; fi
find "$REPO/dishnet-hybrid-sudan" -type f -exec touch -d "@$(git -C "$REPO" log -1 --format=%ct)" {} +
PIN="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT="\([^"]*\)".*/\1/p' "$DEPLOY")"
echo "== 0. what is rehearsed =="
echo "  script    $WHICH, sha256 $(sha256sum "$DEPLOY" | cut -c1-16)"
echo "  clone     $BRANCH at $(git -C "$REPO" rev-parse --short HEAD)"
case "$PIN" in __*) echo "  FAIL the script still carries the placeholder pin ($PIN) — fill EXPECTED_PLUGIN_COMMIT with the plugin commit, then rehearse"; exit 2;; esac
check "$PIN" "$(git -C "$REPO" log -1 --format=%h -- $P)" "the script is pinned to the clone's plugin commit ($PIN)"
check "$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["information"]["version"])' "$REPO/$P/manifest.json")" "5.18.60" "the plugin is 5.18.60"
dl() { git -C "$REPO" diff --no-renames --name-only --diff-filter="$1" "$2" "$3" -- $P; }
CHANGED="$(dl AM "$BASE" "$PIN")"
N_CH="$(grep -c . <<<"$CHANGED")"; N_AD="$(dl A "$BASE" "$PIN" | grep -c .)"; N_MO=$((N_CH - N_AD)); N_DEL="$(dl D "$BASE" "$PIN" | grep -c .)"
echo "  5.18.60   $N_CH files: $N_MO changed, $N_AD added · $N_DEL removed or renamed"
check "$N_CH" "12" "control: 5.18.60 changes exactly twelve files (3 added + 9 edited)"
check "$N_AD" "3" "control: three of them are new"
check "$N_DEL" "0" "control: 5.18.59 → 5.18.60 removes no file"
for want in "lib/EmailRecipients.php" "lib/MailService.php" "lib/CustomerEmailDispatcher.php" "lib/CustomerEmails.php" \
            "lib/InvoiceReminders.php" "lib/QuotationService.php" "cron/customer_reminders.php" \
            "tools/set_customer_emails.php" "tools/mail_log_doctor.php" "tests/test_reminder_email_cc.php" "manifest.json"; do
  check "$(grep -c "^$P/$want$" <<<"$CHANGED")" "1" "control: the delta includes $want"
done
# 5.18.60 carries NO migration — a decisive difference from 5.18.59.
check "$(dl AM "$BASE" "$PIN" | grep -c '^'"$P"'/migrations/')" "0" "control: 5.18.60 adds or changes no migration (code-only)"
# EmailRecipients.php is new in 5.18.60 and absent at the base; the base's MailService has no CC RCPT loop.
check "$(git -C "$REPO" cat-file -e "$PIN:$P/lib/EmailRecipients.php" 2>/dev/null && echo yes || echo no)" "yes" "control: lib/EmailRecipients.php exists at the pin"
check "$(git -C "$REPO" cat-file -e "$BASE:$P/lib/EmailRecipients.php" 2>/dev/null && echo yes || echo no)" "no" "control: lib/EmailRecipients.php does not exist at the base (the file this release adds)"
check "$(git -C "$REPO" show "$BASE:$P/lib/MailService.php" | grep -c 'rcpt_cc')" "0" "control: 5.18.59's MailService has no CC RCPT loop (added by this release)"
check "$(git -C "$REPO" show "$PIN:$P/lib/MailService.php" | grep -c 'rcpt_cc')" "1" "control: 5.18.60's MailService RCPTs each Cc address"
HEADER_CMD="$(sed -n '/^# Run as root/,/^# The rollback is a separate command/p' "$DEPLOY")"
check "$(grep -c 'deploy-5.18.60.sh 2>&1 | tee' <<<"$HEADER_CMD")$(grep -cE -- '--rollback|git checkout [0-9a-f]{7}' <<<"$HEADER_CMD")" "10" \
  "the header's deploy command stands alone: no rollback and no checkout of another commit in its block (docs/44 §16.9)"

# ── The container: 5.18.59 installed, its data beside it ─────────────────────
MOUNT="$SB/mount"; PLUGINS="$MOUNT/ucrm/data/plugins"; PD="$PLUGINS/$P"; DATA="$PLUGINS/.$P-data"
VAULT="$PLUGINS/.dishnet-sudan.vault.json"
mkdir -p "$PD" "$DATA" "$SB/web" "$SB/bin" "$SB/out"
IN_CONTAINER="/data/ucrm/data/plugins/$P"
php_conf() {
  rm -rf "$SB/etc"; mkdir -p "$SB/etc/php/conf.d" "$SB/etc/php-fpm.d"
  { printf '[PHP]\nmemory_limit = 512M\n'; for i in $(seq 1 18); do echo; done; printf '%s\nopcache.revalidate_freq=2\n' "$1"; } > "$SB/etc/php/php.ini"
  printf 'zend_extension=opcache\nopcache.enable_cli=0\n' > "$SB/etc/php/conf.d/docker-php-ext-opcache.ini"
  printf '[www]\nuser = www-data\n%s\n' "$2" > "$SB/etc/php-fpm.d/www.conf"
}
php_conf 'opcache.validate_timestamps=1' ''
plugin_log() { printf '%s\n' '[2026-10-01 06:00:01] [master] RUN staff_jobs' > "$PD/data/plugin.log"; }

# ── The stand-in public address (customer pages only — 5.18.60 has no public endpoint of its own) ──
free_port() { python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()'; }
WPORT="$(free_port)"; PLUGIN_BASE="http://127.0.0.1:$WPORT/public.php"
cat > "$SB/web/public.php" <<'PHP'
<?php
$page = $_GET['page'] ?? '';
if ($page === 'customer_portal') { header('Location: ?page=customer_login', true, 302); exit; }
echo '<html><body>Sign in</body></html>';
PHP
php -S "127.0.0.1:$WPORT" -t "$SB/web" >/dev/null 2>&1 & PIDS+=($!)
for i in $(seq 1 50); do curl -s --noproxy '*' "$PLUGIN_BASE?page=customer_login" | grep -q 'Sign in' && break; sleep 0.1; done

install_base() {   # the installed plugin exactly as 5.18.59
  find "$PD" -mindepth 1 -maxdepth 1 ! -name ucrm.json -exec rm -rf {} +
  git -C "$REPO" archive "$BASE:$P" | tar -x -C "$PD"
  printf '%s\n' "$BASE" > "$PD/.deployed-commit"
  plugin_log
}
printf '{"pluginDataDir":"/data/ucrm/data/plugins/.%s-data","ucrmPublicUrl":"http://127.0.0.1:1/crm"}' "$P" > "$PD/ucrm.json"
vault() { printf '{"config":{"currency_code":"%s"}}' "$1" > "$VAULT"; }
seed_data() {      # the plugin's databases, written by 5.18.59's own store; NO e-mail switch set anywhere → all off
  rm -f "$DATA"/plugin.sqlite3* "$DATA"/dishnet.sqlite* "$DATA"/config.json "$DATA"/kyc_config.json "$DATA"/migration.log
  php -r '
    foreach (["bootstrap_data", "StoreInterface", "JsonStore", "SqliteStore"] as $l) require_once $argv[1] . "/lib/$l.php";
    $s = SqliteStore::create($argv[2]);
    $s->save("kyc_config.json", ["crm_base_url" => "http://127.0.0.1:1", "company_name" => "DishNet Sandbox"]);
    $s->save("retailers.json", [
      ["id" => 1, "name" => "Sandbox Admin", "email" => "admin@example.test", "phone" => "+256700000110", "role" => "admin", "is_admin" => true, "is_active" => true, "ucrm_user_id" => 1000],
      ["id" => 4, "name" => "Sandbox Accountant", "email" => "acct@example.test", "phone" => "0700000113", "role" => "accountant", "is_active" => true],
    ]);
  ' "$PD" "$DATA" >/dev/null
  php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec("PRAGMA journal_mode=WAL"); $p->exec("CREATE TABLE wa_messages(id INTEGER PRIMARY KEY, body TEXT)");
    for ($i = 0; $i < 10; $i++) $p->exec("INSERT INTO wa_messages(body) VALUES (\x27hello $i\x27)");' "$DATA/dishnet.sqlite"
}
inst_ver() { grep -o '"version": *"5[^"]*"' "$PD/manifest.json" | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/'; }
live() { tail -n1 "$PD/.deployed-commit" 2>/dev/null | tr -cd '0-9a-f'; }
backups() { ls -d "$SB/out"/backup-* 2>/dev/null | wc -l | tr -d ' '; }
# The vault and any kyc_config FILE are compared by PARSED, key-sorted content, not raw bytes: the plugin's own config
# loader (PluginConfig::load, reached by the switch tool's --show) re-serialises the vault when it reads it — pretty-
# printing it, same values — so a byte compare would flag that benign re-formatting as a change. A value change still
# registers. plugin.sqlite3 business rows are compared row-for-row.
_canon() { php -r 'function c($x){ if (is_array($x)) { ksort($x); foreach ($x as &$v) $v = c($v); } return $x; }
  echo json_encode(c(json_decode((string)@file_get_contents($argv[1]), true)));' "$1" 2>/dev/null; }
data_dump() {
  php -r '$p = new PDO("sqlite:" . $argv[1]); $skip = ["_migrations", "sqlite_sequence"];
      foreach ($p->query("SELECT name FROM sqlite_master WHERE type = \x27table\x27 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if (in_array($t, $skip, true)) continue;
        echo $t, ":", json_encode($p->query("SELECT * FROM [$t]")->fetchAll(PDO::FETCH_NUM)), "\n"; }' "$DATA/plugin.sqlite3"
  echo "vault:$(_canon "$VAULT")"
  [ -f "$DATA/kyc_config.json" ] && echo "kyc:$(_canon "$DATA/kyc_config.json")"
  true
}
data_digest() { data_dump | sha256sum | cut -c1-16; }
installed_digest() {   # $1 a commit: the number of files 5.18.60 touches that are NOT installed as that commit has them
  local c="$1" f rel bad=0
  for f in $CHANGED; do
    rel="${f#$P/}"
    if git -C "$REPO" cat-file -e "$c:$f" 2>/dev/null; then
      [ "$(git -C "$REPO" show "$c:$f" | sha256sum | cut -c1-64)" = "$(sha256sum "$PD/$rel" 2>/dev/null | cut -c1-64)" ] || bad=$((bad+1))
    fi
  done
  echo "$bad"
}
logline() { printf '%s.123456789Z %s\n' "$(date -u -d "@$1" +%Y-%m-%dT%H:%M:%S)" "$2" >> "$SB/container.log"; }
base_log() { local a="${1:-$(date -u +%s)}"; : > "$SB/container.log"; logline $((a - 60)) 'NOTICE: fpm is running, pid 1'; }
fresh() { rm -rf "$SB/out"; mkdir -p "$SB/out"; base_log; php_conf 'opcache.validate_timestamps=1' ''; }
# Flip a switch through the installed tool, the way the operator would (fake docker + the DN_DATA_DIR shim).
flip() { env PATH="$SB/bin:$PATH" DN_DATA_DIR="$DATA" bash "$SB/bin/docker" exec ucrm php "$IN_CONTAINER/tools/set_customer_emails.php" "$@" >/dev/null 2>&1; }

# ── The fake docker (verbatim from the 5.18.59 rehearsal) ──
cat > "$SB/bin/docker" <<SH
#!/usr/bin/env bash
printf '%s\n' "\$*" >> "$SB/docker.log"
case "\$1" in
  inspect) echo "$MOUNT"; exit 0 ;;
  logs) cat "$SB/container.log" 2>/dev/null; exit 0 ;;
  exec) shift ;;
  *) echo "fake docker: \$1 is not supported" >&2; exit 1 ;;
esac
while [ \$# -gt 0 ]; do case "\$1" in -i) shift ;; -u) shift 2 ;; *) break ;; esac; done
shift
args=(); for a in "\$@"; do case "\$a" in /data/*) args+=("$MOUNT/\${a#/data/}") ;; /usr/local/etc|/usr/local/etc/*) args+=("$SB/etc\${a#/usr/local/etc}") ;; *) args+=("\$a") ;; esac; done
exec "\${args[@]}"
SH
chmod +x "$SB/bin/docker"

run() {   # [--answer WORD] [--script FILE], then the script's own options — through a pseudo-terminal
  local answer="" script="$DEPLOY"
  while :; do case "${1:-}" in --answer) answer="$2"; shift 2 ;; --script) script="$2"; shift 2 ;; *) break ;; esac; done
  : > "$SB/docker.log"
  local n; n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"
  local cmd; cmd="$(printf '%q ' env PATH="$SB/bin:$PATH" DNB_OUT="$SB/out" GUARD_SECONDS=0 DN_DATA_DIR="$DATA" bash "$script" --plugin-base "$PLUGIN_BASE" "$@")"
  local o; o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r')"
  if [ -n "${REHEARSE_KEEP:-}" ]; then
    local label; label="$(printf '%s-%s%s' "${answer:-none}" "$(basename "$script" .sh)" "$(printf '%s' "$*" | tr -c 'A-Za-z0-9-' '_')")"
    printf '%s\n' "$o" > "$REHEARSE_KEEP/run-$(printf '%02d' "$n")-$label.txt"
  fi
  printf '%s' "$o"
}

install_base; seed_data; vault UGX; fresh
D0="$(data_digest)"
check "$(inst_ver)" "5.18.59" "control: the installed base is 5.18.59"
check "$(git -C "$REPO" cat-file -e "$BASE:$P/lib/EmailRecipients.php" 2>/dev/null && echo yes || echo no)" "no" "control: the base install carries no EmailRecipients.php"

echo; echo "== 1. NO-GO before anything changes =="
printf '%s\n' "$OLDER" > "$PD/.deployed-commit"; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: the container serves $OLDER; 5.18.60 was built and tested against $BASE (5.18.59) — deploy 5.18.59 first (scripts/deploy-5.18.59.sh) and send its log")" "yes" \
  "1a the server still runs an older commit: NO-GO — 5.18.59 goes first"
check "$(live)$(backups)" "${OLDER}0" "…nothing deployed, no backup taken"
printf '%s\n' "$BASE" > "$PD/.deployed-commit"
sed 's/^EXPECTED_PLUGIN_COMMIT="[^"]*"/EXPECTED_PLUGIN_COMMIT="__PLUGIN_COMMIT__"/' "$DEPLOY" > "$REPO/scripts/.unpinned.sh"
fresh; OUT="$(run --answer DEPLOY --script "$REPO/scripts/.unpinned.sh")"; rm -f "$REPO/scripts/.unpinned.sh"
check "$(has "$OUT" 'STOP: this copy of the script is not pinned to a reviewed commit')$(live)$(backups)" "yes${BASE}0" \
  "1b a copy still carrying the placeholder pin: stops before anything is read"

echo; echo "== 2. the deploy, as the operator runs it =="
fresh; OUT="$(run --answer DEPLOY)"
check "$(fails "$OUT")" "0" "no FAIL line"
check "$(has "$OUT" '5.18.60 (deploy): PASSED')" "yes" "PASSED"
for l in "$N_CH files differ from $BASE: $N_MO changed, $N_AD added, 0 removed" \
         "ok    A2 PHP" \
         "— one consistent copy, integrity ok" "ok    backed up the installed plugin (5.18.59, $BASE)" "ok    backed up the configuration vault" \
         "GO — evidence recorded" \
         "gave the $N_CH installed file(s) this release changes the time of this copy" "ok    container serves $PIN" \
         "ok    V1 the sign-in page on the public address answers 200 with zero redirects (no loop)" \
         "ok    V1 the portal without a session still refuses" \
         "ok    V2 the sign-in page answers 200 and carries no South Sudan contact" \
         "ok    V3 both new switches read off on the live install (cc=off reminder=off)" \
         "ok    V4 no fatal or parse error of $P in the container log since" \
         "ok    R1 all $N_CH files 5.18.60 changes are installed exactly as $PIN has them ($N_AD new)" \
         "ok    R1 the installed manifest says 5.18.60" \
         "ok    R2 the installed set_customer_emails --show reports both new switches off" \
         "ok    R3 the installed OtpEmail.php sets no Cc and resolves no contacts — login codes are never copied" \
         "ok    R4 the installed libs carry the CC + reminder code" \
         "ok    R5 reminder_due is not a catalogue template on the install" \
         "ok    R6 all" \
         "what changed      the plugin CAN now CC a client's other contacts and send payment reminders by e-mail" \
         "nothing sent      V3/R2 confirm both switches read off" \
         "OTP never copied  R3 confirms login-code e-mail carries no Cc"; do
  check "$(has "$OUT" "$l")" "yes" "2: ${l:0:96}"
done
check "$(cnt "$OUT" 'ok    backed up ')" "5" "five backups: two databases, the data directory, the installed plugin, the vault"
check "$(live)$(installed_digest "$PIN")" "${PIN}0" "the container serves $PIN: every changed file exactly as the commit has it"
check "$(inst_ver)" "5.18.60" "the installed manifest is 5.18.60"
check "$(data_digest)" "$D0" "no table and no configuration value changed (code-only release)"
check "$(grep -c "cd $REPO && bash scripts/deploy-5.18.60.sh --rollback" <<<"$OUT")" "1" "the rollback command is printed once, on its own line, never beside the deploy"
check "$(awk '/PASSED\. Send this LOG FILE back/ {p=1} p && /deploy-5\.18\.60\.sh --rollback/ {print "after"; exit}' <<<"$OUT")" "after" \
  "…the rollback block comes after the verdict, at the end of the log, never in the block the operator pasted"
BK="$(ls -d "$SB/out"/backup-* | tail -1)"
check "$(tar -xzOf "$BK"/plugin-installed-5.18.59.tar.gz $P/manifest.json | grep -c '"version": "5.18.59"')" "1" "the code backup is 5.18.59"
check "$(tar -tzf "$BK"/plugin-installed-5.18.59.tar.gz | grep -c "$P/lib/EmailRecipients.php")" "0" \
  "…and the backup has no EmailRecipients.php: a rollback restores the pre-5.18.60 code exactly"

echo; echo "== 3. R1 and R6 have teeth: a changed file reverted on the server is caught =="
ST="$SB/out/state-5.18.60.env"
check "$(ls "$ST" >/dev/null 2>&1 && echo yes || echo no)" "yes" "control: the deploy recorded where it started (state-5.18.60.env)"
git -C "$REPO" show "$BASE:$P/lib/MailService.php" > "$PD/lib/MailService.php"   # the server reverts a changed file to 5.18.59
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R1 installed files that differ: lib/MailService.php")" "yes" "3a a changed file reverted on the server: R1 fails, naming it"
git -C "$REPO" show "$PIN:$P/lib/MailService.php" > "$PD/lib/MailService.php"   # put it back
printf '\n// changed on the server\n' >> "$PD/includes/routes.php"; OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R6 files from Release A→5.18.59 differ on the server: includes/routes.php")" "yes" "3b a Release-A/regression file changed on the server: R6 fails, naming it"
git -C "$REPO" show "$PIN:$P/includes/routes.php" > "$PD/includes/routes.php"
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" '5.18.60 (after): PASSED')" "0yes" "3c each fault removed: --after-only PASSES again"

echo; echo "== 4. V3/R2 have teeth: the switch checks read the LIVE state =="
# The deploy never flips a switch. Prove the check is not hard-wired to "off": flip CC on with the installed tool and
# --after-only now NOTES that a switch reads on (and still PASSES — nothing FAILED; the deploy itself turned nothing on).
flip --cc on
OUT="$(run --after-only)"
check "$(has "$OUT" 'note  V3 a switch reads on (cc=on reminder=off)')" "yes" "4a CC turned on by the operator: V3 NOTES it (cc=on reminder=off)"
check "$(has "$OUT" "note  R2 the installed switches read 'cc=on reminder=off'")" "yes" "4b …and R2 NOTES the same — the check reads the live state, not a constant"
check "$(fails "$OUT")$(has "$OUT" '5.18.60 (after): PASSED')" "0yes" "4c …and it still PASSES: a switch on is the operator's act, never a FAIL"
flip --cc off
OUT="$(run --after-only)"
check "$(has "$OUT" 'ok    V3 both new switches read off on the live install (cc=off reminder=off)')$(fails "$OUT")" "yes0" "4d CC turned back off: V3 reads off again, PASSES"

echo; echo "== 5. the rollback: --rollback, typed ROLLBACK =="
D1="$(data_digest)"; fresh; OUT="$(run --answer ROLLBACK --rollback)"
check "$(fails "$OUT")$(has "$OUT" '5.18.60 (rollback): PASSED')" "0yes" "PASSED"
for l in "GO — evidence recorded" "checking out $BASE (5.18.59) for the documented deploy" "ok    container serves $BASE (5.18.59)" \
         "ok    RB the installed manifest says 5.18.59" \
         "note  RB the switches and the config are unchanged"; do
  check "$(has "$OUT" "$l")" "yes" "5: ${l:0:96}"
done
check "$(has "$OUT" "ok    backed up the installed plugin (5.18.60, $PIN)")" "yes" "the backup first — of 5.18.60's code"
check "$(live)$(installed_digest "$BASE")" "${BASE}0" "the container serves $BASE: every changed file as 5.18.59 has it"
check "$(inst_ver)" "5.18.59" "the installed manifest is 5.18.59 again"
check "$(data_digest)" "$D1" "the rollback changed no data (it restores code only)"
OUT="$(run --answer ROLLBACK --rollback)"
check "$(has "$OUT" 'nothing to roll back; running the rollback checks')$(fails "$OUT")$(backups)" "yes01" "5b rolling back again: says so, checks, takes no second backup"

echo; echo "== 6. a weakened copy of the script must be caught (control on the control) =="
# R3 blinded: its grep for a Cc header / EmailRecipients in the installed OtpEmail.php is changed so it can never match.
# With a Cc header planted in the installed OtpEmail.php, the weakened script still says R3 ok — the mutation is
# detected; the real script FAILS on the same fault. R3 is the login-code-never-copied guarantee.
fresh; OUT="$(run --answer DEPLOY)"; check "$(fails "$OUT")" "0" "control: re-deployed 5.18.60 for the mutant test"
MUT="$REPO/scripts/.mutant-r3.sh"
python3 - "$DEPLOY" "$MUT" <<'PY'
import sys
src, dst = sys.argv[1], sys.argv[2]
s = open(src).read()
pairs = [
    ("grep -q \"'Cc'\" \"$DEST/lib/OtpEmail.php\"", "grep -q \"'Cc_NEVER'\" \"$DEST/lib/OtpEmail.php\""),
    ("grep -q '\"Cc\"' \"$DEST/lib/OtpEmail.php\"", "grep -q '\"Cc_NEVER\"' \"$DEST/lib/OtpEmail.php\""),
    ("grep -q 'EmailRecipients' \"$DEST/lib/OtpEmail.php\"", "grep -q 'EmailRecipients_NEVER' \"$DEST/lib/OtpEmail.php\""),
]
for old, new in pairs:
    assert s.count(old) == 1, "R3 anchor not unique: " + old
    s = s.replace(old, new)
open(dst, 'w').write(s)
PY
printf "\n\$headers['Cc'] = 'leak@example.test'; // planted\n" >> "$PD/lib/OtpEmail.php"   # plant a Cc header in the installed OTP e-mail
OUT="$(run --script "$MUT" --after-only)"
check "$(has "$OUT" 'ok    R3 the installed OtpEmail.php sets no Cc and resolves no contacts')" "yes" "6a the R3-blinded copy calls the Cc-carrying OTP e-mail clean — the mutation is detected"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R3 the installed OtpEmail.php references a Cc header or EmailRecipients')" "yes" "6b …and the real script FAILS R3 on the same fault, naming it"
rm -f "$MUT"; git -C "$REPO" show "$PIN:$P/lib/OtpEmail.php" > "$PD/lib/OtpEmail.php"   # put the real OTP e-mail back

echo; echo "== 7. what the rehearsal left behind =="
check "$(checkout_state)" "$CHECKOUT0" "this checkout is as the rehearsal found it: same commit, same tracked files"
check "$(ls "$REPO/scripts"/.mutant-* "$REPO/scripts"/.unpinned.sh 2>/dev/null | wc -l | tr -d ' ')" "0" "no weakened copy is left in the clone"

echo; echo "rehearsal: $PASS passed, $FAILN failed ($(cat "$SB/runs") runs of the script)"
[ "$FAILN" = "0" ]
