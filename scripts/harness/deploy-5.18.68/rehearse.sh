#!/usr/bin/env bash
# Rehearse scripts/deploy-5.18.68.sh — the deploy, its checks, the backfill's DRY RUN, and the ROLLBACK — before the
# operator runs any of it.
#
# 5.18.68 over 5.18.67: staff money in the book's own currency (UGX on Uganda), the Cashbook page's CASH IN HAND hero,
# the evening summary, the Opening Balances link off the Uganda strip, and tools/backfill_staff_cash_ins.php. Code only —
# no migration, no new table. Deployed from a RELEASE COMMIT on release/5.18.68, cut on the live 5.18.67 release commit
# (96857d8), not from the branch tip, which still carries the undeployed partner-portal stack and the PD-8 CSRF guard.
# This rehearsal proves:
#   · the script runs end to end against a 5.18.67 base (the pilot ON, a UGX book with one unlinked Staff Advance seeded)
#     and PASSES;
#   · it installs exactly the right files — the release's own, two new (the test and the tool), NO migration, no
#     partner-portal or CSRF file; the pin's Cashbook page carries CASH IN HAND, the base's does not;
#   · a copy pinned to the BRANCH TIP (whose parent is not 5.18.67) is refused before anything is read;
#   · the deploy touches no configuration value and no data — V3/R2 read the LIVE pilot switch before and after; every
#     table, the seeded advance included, is byte-identical right after it (so the backfill's DRY RUN in R7 wrote nothing);
#   · R7 reads the dry run's plan on the seeded data: 1 to create (the advance to a staff member the live link never saw);
#   · R6 proves the headline: the auto-link's amount rule, the ledger writer, the position service, the Cashbook card, the
#     evening summary, the strip gate and the tool are installed, with the 5.18.66/5.18.67 photo surface intact;
#   · R1/R6 have TEETH — revert the installed position service to 5.18.67 and R1 names it AND R6 names literal-usd; revert
#     the Cashbook page and R6 names the missing card; R5 has TEETH — a changed Release-A file is caught by name;
#   · the switch checks have TEETH — the pilot turned off with the installed tool reads off; on again reads on; PASS both;
#   · R4 still proves nothing can be sent, and catches a partner-portal file planted on the install;
#   · the rollback returns the plugin to 5.18.67 — manifest 5.18.67, the position cards back, the position service back,
#     the photo surface and the 5.18.67 card intact, the data untouched; the rollback command is printed alone, after the
#     verdict, and the backfill's --apply command after that, in its own block (root docs/44 §16.9);
#   · the base gate holds: a server on 5.18.66 is a NO-GO ("deploy 5.18.67 first"); a placeholder pin stops before any read;
#   · a weakened copy whose R1 byte-for-byte check is blinded is caught (control on the control).
#
# Everything is real except the container and the public address (as the earlier rehearsals): a CLONE of this repo, the
# real git checkout and scripts/deploy-hybrid.sh, this machine's PHP for the server's, the installed plugin starting as
# 5.18.67 exactly (git archive of the release commit), and a stand-in web server for stage V's pages. DEPLOY and ROLLBACK
# are typed through a pseudo-terminal. The one shim is DN_DATA_DIR for the installed tools' cliDataDir().
#
#   bash scripts/harness/deploy-5.18.68/rehearse.sh            REHEARSE_KEEP=<dir> keeps every run's full output
set -u
R="$(cd "$(dirname "$0")/../../.." && pwd)"
for f in /data/ucrm /opt/dishnet /var/run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
BASE=96857d8; RA_BASE=e076632; OLDER=8137912; BRANCH_FILE=scripts/deploy-5.18.68.sh; P=dishnet-hybrid-sudan; RELEASE_BRANCH=release/5.18.68
MARK='CASH IN HAND</div>'                       # the Cashbook hero of 5.18.68
POSMARK='dn_book_base(null)'                   # the position service reading the book's base
EXPECT_N=23                            # files in the release delta (set when the release commit was cut)
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
TIP="$(git -C "$REPO" log -1 --format=%h -- $P)"
echo "== 0. what is rehearsed =="
echo "  script    $WHICH, sha256 $(sha256sum "$DEPLOY" | cut -c1-16)"
echo "  clone     $BRANCH at $(git -C "$REPO" rev-parse --short HEAD); branch-tip plugin commit $TIP"
case "$PIN" in __*) echo "  FAIL the script still carries the placeholder pin ($PIN) — fill EXPECTED_PLUGIN_COMMIT with the release commit, then rehearse"; exit 2;; esac
REL="$(git -C "$REPO" rev-parse --short "origin/$RELEASE_BRANCH" 2>/dev/null || echo none)"
check "$PIN" "$REL" "the script is pinned to the release commit on $RELEASE_BRANCH ($PIN)"
check "$(git -C "$REPO" rev-parse --short "$PIN^" 2>/dev/null)" "$BASE" "control: the release commit is cut on $BASE (5.18.67), the live version"
check "$(git -C "$REPO" show "$PIN:$P/manifest.json" | python3 -c 'import json,sys; print(json.load(sys.stdin)["information"]["version"])')" "5.18.68" "the plugin at the pin is 5.18.68"
check "$(git -C "$REPO" show "$BASE:$P/manifest.json" | python3 -c 'import json,sys; print(json.load(sys.stdin)["information"]["version"])')" "5.18.67" "control: the base is 5.18.67"
dl() { git -C "$REPO" diff --no-renames --name-only --diff-filter="$1" "$2" "$3" -- $P; }
CHANGED="$(dl AM "$BASE" "$PIN")"
N_CH="$(grep -c . <<<"$CHANGED")"; N_AD="$(dl A "$BASE" "$PIN" | grep -c .)"; N_MO=$((N_CH - N_AD)); N_DEL="$(dl D "$BASE" "$PIN" | grep -c .)"
echo "  5.18.68   $N_CH files: $N_MO changed, $N_AD added · $N_DEL removed or renamed"
check "$N_DEL" "0" "control: 5.18.67 → 5.18.68 removes no file"
check "$N_AD" "2" "control: 5.18.68 adds exactly two files — the chain test and the backfill tool"
check "$N_CH" "$EXPECT_N" "control: $EXPECT_N files in the delta"
for want in "manifest.json" "SAFETY.md" "cron/cashbook_summary.php" "includes/navigation.php" "includes/post/post_cashbook.php" "includes/post/post_field.php" \
            "lib/CashbookService.php" "lib/DualReadCashPosition.php" "lib/NotificationService.php" "lib/StaffCashPositionService.php" "lib/StaffLedgerWriter.php" \
            "tabs/accounts/cashbook.php" "tabs/accounts/staff_cashbooks.php" "tabs/sales/my_account.php" "tabs/sales/wallet.php" \
            "tests/test_cashbook_currency.php" "tests/test_staff_cash_chain.php" "tools/backfill_staff_cash_ins.php" \
            "tests/test_distributor_registry.php" "tests/test_distributor_apply.php" "tests/test_distributor_territory.php" \
            "tests/test_distributor_notify.php" "tests/test_distributor_link_ucrm.php"; do
  check "$(grep -c "^$P/$want$" <<<"$CHANGED")" "1" "control: the delta includes $want"
done
check "$(grep -cE "^$P/(partner_api\.php|lib/Partner|lib/StaffApiCsrf\.php|lib/Totp\.php|lib/DistributorPortalData\.php|migrations/08[123]_)" <<<"$CHANGED")" "0" \
  "control: no partner-portal or CSRF file in the delta"
check "$(grep -c '^'"$P"'/migrations/' <<<"$CHANGED")" "0" "control: 5.18.68 adds no migration"
check "$(git -C "$REPO" show "$PIN:$P/tabs/accounts/cashbook.php" | grep -cF -- "$MARK")" "1" "control: the Cashbook page carries the CASH IN HAND card at the pin"
check "$(git -C "$REPO" show "$BASE:$P/tabs/accounts/cashbook.php" | grep -cF -- "$MARK")" "0" "control: 5.18.67's Cashbook page does not (added by this release)"
check "$(git -C "$REPO" show "$PIN:$P/lib/StaffCashPositionService.php" | grep -cF -- "$POSMARK")" "1" "control: the position service reads the book's base at the pin"
check "$(git -C "$REPO" show "$BASE:$P/lib/StaffCashPositionService.php" | grep -cF -- "$POSMARK")" "0" "control: 5.18.67's does not"
check "$(git -C "$REPO" show "$PIN:$P/tabs/support/scheduling.php" | grep -cF -- 'flex-shrink:0;">📷 ')" "1" "control: the 5.18.67 card layout is still there at the pin (regression)"
check "$(git -C "$REPO" show "$PIN:$P/tabs/support/scheduling.php" | grep -c 'window.schOpenCompleteFormImpl=function')" "1" "control: the 5.18.66 completion form is still there at the pin (regression)"
check "$(git -C "$REPO" show "$PIN:$P/webhook.php" | grep -c 'DistributorEvents::maybeNotify')" "2" "control: webhook.php still draws the two draft hooks at the pin (regression)"
check "$(git -C "$REPO" show "$PIN:$P/lib/DistributorNotifier.php" | grep -c 'new NullWhatsAppChannel')" "1" "control: the notifier still binds the Null channel at the pin (regression)"
HEADER_CMD="$(sed -n '/^# Run as root/,/^# The rollback is a separate command/p' "$DEPLOY")"
check "$(grep -c 'deploy-5.18.68.sh 2>&1 | tee' <<<"$HEADER_CMD")$(grep -cE -- '--rollback|--apply|git checkout [0-9a-f]{7}' <<<"$HEADER_CMD")" "10" \
  "the header's deploy command stands alone: no rollback, no --apply and no checkout of another commit in its block (docs/44 §16.9)"
check "$(grep -c "git fetch origin $RELEASE_BRANCH" <<<"$HEADER_CMD")" "1" "the header's deploy command fetches the release branch"

# ── The container: 5.18.67 installed, its data beside it ─────────────────────
MOUNT="$SB/mount"; PLUGINS="$MOUNT/ucrm/data/plugins"; PD="$PLUGINS/$P"; DATA="$PLUGINS/.$P-data"
VAULT="$PLUGINS/.dishnet-sudan.vault.json"
mkdir -p "$PD" "$DATA" "$SB/web" "$SB/bin" "$SB/out"
IN_CONTAINER="/data/ucrm/data/plugins/$P"
free_port() { python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()'; }
WPORT="$(free_port)"; PLUGIN_BASE="http://127.0.0.1:$WPORT/public.php"
cat > "$SB/web/public.php" <<'PHP'
<?php
$page = $_GET['page'] ?? '';
if ($page === 'customer_portal') { header('Location: ?page=customer_login', true, 302); exit; }
if ($page === 'job_photo') { header('Location: ?page=login', true, 302); exit; }
if ($page === 'api') { http_response_code(401); header('Content-Type: application/json'); echo '{"status":"error","message":"Unauthorized."}'; exit; }
echo '<html><body>Sign in</body></html>';
PHP
php -S "127.0.0.1:$WPORT" -t "$SB/web" >/dev/null 2>&1 & PIDS+=($!)
for i in $(seq 1 50); do curl -s --noproxy '*' "$PLUGIN_BASE?page=customer_login" | grep -q 'Sign in' && break; sleep 0.1; done

install_base() {   # the installed plugin exactly as 5.18.67
  find "$PD" -mindepth 1 -maxdepth 1 ! -name ucrm.json -exec rm -rf {} +
  git -C "$REPO" archive "$BASE:$P" | tar -x -C "$PD"
  printf '%s\n' "$BASE" > "$PD/.deployed-commit"
  printf '%s\n' '[2026-10-03 20:00:01] [master] RUN staff_jobs' > "$PD/data/plugin.log" 2>/dev/null || true
}
printf '{"pluginDataDir":"/data/ucrm/data/plugins/.%s-data","ucrmPublicUrl":"http://127.0.0.1:1/crm"}' "$P" > "$PD/ucrm.json"
vault() { printf '{"config":{"currency_code":"%s"}}' "$1" > "$VAULT"; }
seed_data() {      # the plugin's databases, written by 5.18.67's own store (so 084's tables exist, empty); the pilot is enabled through the real tool
  rm -rf "$DATA"/plugin.sqlite3* "$DATA"/dishnet.sqlite* "$DATA"/config.json "$DATA"/kyc_config.json "$DATA"/migration.log "$DATA"/uploads
  php -r '
    foreach (["bootstrap_data", "StoreInterface", "JsonStore", "SqliteStore"] as $l) require_once $argv[1] . "/lib/$l.php";
    require_once $argv[1] . "/lib/CashbookService.php";
    $s = SqliteStore::create($argv[2]);
    $s->save("kyc_config.json", ["crm_base_url" => "http://127.0.0.1:1", "company_name" => "DishNet Sandbox", "currency_code" => "UGX",
        "tenant_profile" => "uganda", "cashbook_base_currency" => "UGX", "cashbook_currencies" => "UGX,USD", "currency_symbol" => "UGX"]);
    file_put_contents($argv[2] . "/kyc_config.json", json_encode($s->load("kyc_config.json")));
    // one staff member and one Staff Advance to them that the live link never saw (as on production before 5.18.68)
    $s->appendWithId("retailers.json", ["name" => "Rehearsal Tech", "email" => "tech@example.test", "role" => "support", "is_active" => true]);
    $cb = new CashbookService($s, $argv[2]);
    $cb->addEntryRaw(["project" => "dishnet", "date" => date("Y-m-d"), "direction" => "out", "amount" => 10000, "currency" => "UGX",
        "category" => "Staff Advance", "category_raw" => "Staff Advance", "person" => "Rehearsal Tech", "description" => "rehearsal",
        "status" => "approved", "source" => "manual"]);
  ' "$PD" "$DATA" >/dev/null
  php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec("PRAGMA journal_mode=WAL"); $p->exec("CREATE TABLE wa_messages(id INTEGER PRIMARY KEY, body TEXT)");
    for ($i = 0; $i < 10; $i++) $p->exec("INSERT INTO wa_messages(body) VALUES (\x27hello $i\x27)");' "$DATA/dishnet.sqlite"
}
inst_ver() { grep -o '"version": *"5[^"]*"' "$PD/manifest.json" | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/'; }
live() { tail -n1 "$PD/.deployed-commit" 2>/dev/null | tr -cd '0-9a-f'; }
backups() { ls -d "$SB/out"/backup-* 2>/dev/null | wc -l | tr -d ' '; }
dist_tables() { php -r '$p=new PDO("sqlite:".$argv[1]); $n=0; foreach(["dist_partners","dist_appointment","dist_regions","dist_territory_map","dist_customer_links","dist_contacts","dist_notify_consent","dist_notify_log"] as $t){ $q=$p->query("SELECT 1 FROM sqlite_master WHERE type=\x27table\x27 AND name=\x27$t\x27"); if($q->fetchColumn()) $n++; } echo $n;' "$DATA/plugin.sqlite3" 2>/dev/null || echo 0; }
photo_tables() { php -r '$p=new PDO("sqlite:".$argv[1]); $n=0; foreach(["job_photos","job_completion_gps"] as $t){ $q=$p->query("SELECT 1 FROM sqlite_master WHERE type=\x27table\x27 AND name=\x27$t\x27"); if($q->fetchColumn()) $n++; } echo $n;' "$DATA/plugin.sqlite3" 2>/dev/null || echo 0; }
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
installed_digest() {   # $1 a commit: the number of files 5.18.67 touches that are NOT installed as that commit has them
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
fresh() { rm -rf "$SB/out"; mkdir -p "$SB/out"; base_log; }
flip() { env PATH="$SB/bin:$PATH" DN_DATA_DIR="$DATA" bash "$SB/bin/docker" exec ucrm php "$IN_CONTAINER/tools/set_distributors.php" "$@" >/dev/null 2>&1; }

# ── The fake docker (verbatim shape from the 5.18.66 rehearsal) ──
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
flip --on   # enable the pilot through the real tool, exactly as the live server has it
D0="$(data_digest)"
check "$(inst_ver)" "5.18.67" "control: the installed base is 5.18.67"
check "$(grep -cF -- "$MARK" "$PD/tabs/accounts/cashbook.php")" "0" "control: the base Cashbook page has the position cards, not CASH IN HAND"
check "$(grep -cF -- "$POSMARK" "$PD/lib/StaffCashPositionService.php")" "0" "control: the base position service reads literal USD"
check "$(grep -c 'var UG_PHOTOS' "$PD/tabs/support/scheduling.php")" "1" "control: …and the 5.18.66 photo surface is there"
check "$(php -r '$p=new PDO("sqlite:".$argv[1]); echo (int)$p->query("SELECT COUNT(*) FROM cb_ledger WHERE category=\x27Staff Advance\x27 AND currency=\x27UGX\x27")->fetchColumn();' "$DATA/plugin.sqlite3")" "1" "control: the seeded UGX Staff Advance is in the base ledger"
check "$(test -f "$PD/lib/JobPhotos.php" && echo yes || echo no)$(test -f "$PD/partner_api.php" && echo yes || echo no)" "yesno" "control: the base has JobPhotos.php and no partner_api.php"
check "$(dist_tables)" "8" "control: the base plugin.sqlite3 has the distributor tables (8)"
check "$(photo_tables)" "2" "control: …and 084's two photo tables, from 5.18.66's own migration run"
check "$(env PATH="$SB/bin:$PATH" DN_DATA_DIR="$DATA" bash "$SB/bin/docker" exec ucrm php "$IN_CONTAINER/tools/set_distributors.php" --show 2>/dev/null | awk '/distributors_enabled/ {print toupper($2); exit}')" "ON" "control: the pilot is ON on the base"

echo; echo "== 1. NO-GO before anything changes =="
printf '%s\n' "$OLDER" > "$PD/.deployed-commit"; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: the container serves $OLDER; 5.18.68 was built and tested against $BASE (5.18.67) — deploy 5.18.67 first (scripts/deploy-5.18.67.sh) and send its log")" "yes" \
  "1a the server still runs 5.18.66: NO-GO — 5.18.67 goes first"
check "$(live)$(backups)" "${OLDER}0" "…nothing deployed, no backup taken"
printf '%s\n' "$BASE" > "$PD/.deployed-commit"
sed 's/^EXPECTED_PLUGIN_COMMIT="[^"]*"/EXPECTED_PLUGIN_COMMIT="__PLUGIN_COMMIT__"/' "$DEPLOY" > "$REPO/scripts/.unpinned.sh"
fresh; OUT="$(run --answer DEPLOY --script "$REPO/scripts/.unpinned.sh")"; rm -f "$REPO/scripts/.unpinned.sh"
check "$(has "$OUT" 'STOP: this copy of the script is not pinned to a reviewed commit')$(live)$(backups)" "yes${BASE}0" \
  "1b a copy still carrying the placeholder pin: stops before anything is read"
sed "s/^EXPECTED_PLUGIN_COMMIT=\"[^\"]*\"/EXPECTED_PLUGIN_COMMIT=\"$TIP\"/" "$DEPLOY" > "$REPO/scripts/.tip.sh"
fresh; OUT="$(run --answer DEPLOY --script "$REPO/scripts/.tip.sh")"; rm -f "$REPO/scripts/.tip.sh"
check "$(has "$OUT" "STOP: $TIP is not cut on $BASE (5.18.67): its parent is")$(has "$OUT" 'this script is for another build')" "yesyes" \
  "1c a copy pinned to the branch tip ($TIP, parent not 5.18.67): refused — the undeployed work cannot ride along"
check "$(live)$(backups)$(cnt "$OUT" 'serves ')" "${BASE}00" "…before the container was even looked at: nothing deployed, no backup, no live read"

echo; echo "== 2. the deploy, as the operator runs it =="
fresh; OUT="$(run --answer DEPLOY)"
check "$(fails "$OUT")" "0" "no FAIL line"
check "$(has "$OUT" '5.18.68 (deploy): PASSED')" "yes" "PASSED"
for l in "ok    A0 the release delta carries no partner-portal or CSRF file and no migration — $N_CH files, this release's own" \
         "ok    A2 PHP" \
         "— one consistent copy, integrity ok" "ok    backed up the installed plugin (5.18.66, $BASE)" "ok    backed up the configuration vault" \
         "GO — evidence recorded" \
         "checking out $PIN (5.18.68, the release commit) for the documented deploy" \
         "ok    container serves $PIN" \
         "ok    V1 the sign-in page on the public address answers 200 with zero redirects (no loop)" \
         "ok    V1 the portal without a session still refuses" \
         "ok    V2 the sign-in page answers 200 and carries no South Sudan contact" \
         "ok    V5 the photo viewer without a session refuses (302) — a photo is never served to nobody" \
         "ok    V5 job_photo_upload without a login answers 401 — the staff guard, before any handler" \
         "ok    V3 the pilot switch is unchanged by the deploy (before=pilot=on, after=pilot=on) — 5.18.68 changes no configuration value" \
         "the pilot reads on" \
         "ok    V4 no fatal or parse error of $P in the container log since" \
         "ok    R1 all $N_CH files 5.18.68 changes are installed exactly as $PIN has them (2 new)" \
         "ok    R1 the installed manifest says 5.18.68" \
         "ok    R2 the installed set_distributors --show reports pilot=on" \
         "ok    R3 5.18.68 adds no migration; 084 is still installed and its two tables exist on the live plugin.sqlite3 — job_photos 0:0 rows" \
         "ok    R4 the pilot libs + the Distributors tab are installed as before" \
         "no partner-portal or CSRF file is installed" \
         "ok    R5 all" \
         "ok    R6 the photo surface and the 5.18.67 card are installed as before, and 5.18.68's staff-cash chain is in place" \
         "ok    R7 the backfill tool's dry run completed on the live data and wrote nothing: plan: 1 to create, 0 to fix, 0 skipped" \
         "Rehearsal Tech" \
         "ok    R7 the photo tables and files read exactly as before the deploy" \
         "what changed      Uganda: a Staff Advance (and a Commission) given through Add Entry now reaches that staff member's own ledger in UGX" \
         "what did not      South Sudan (byte for byte, proved against a 5.18.67 golden)" \
         "migrations        none — 5.18.68 adds no migration"; do
  check "$(has "$OUT" "$l")" "yes" "2: ${l:0:96}"
done
check "$(cnt "$OUT" 'ok    backed up ')" "5" "five backups: two databases, the data directory, the installed plugin, the vault"
check "$(live)$(installed_digest "$PIN")" "${PIN}0" "the container serves $PIN: every changed file exactly as the commit has it"
check "$(inst_ver)" "5.18.68" "the installed manifest is 5.18.68"
check "$(grep -cF -- "$MARK" "$PD/tabs/accounts/cashbook.php")" "1" "the installed Cashbook page now carries the CASH IN HAND card"
check "$(grep -cF -- "$POSMARK" "$PD/lib/StaffCashPositionService.php")" "1" "…the installed position service reads the book's base"
check "$(test -f "$PD/tools/backfill_staff_cash_ins.php" && echo yes || echo no)" "yes" "…and the backfill tool is installed"
check "$(grep -c 'var UG_PHOTOS' "$PD/tabs/support/scheduling.php")$(grep -cF -- 'flex-shrink:0;">📷 ' "$PD/tabs/support/scheduling.php")" "11" "…with the 5.18.66 photo surface and the 5.18.67 card intact"
check "$(test -f "$PD/partner_api.php" && echo yes || echo no)$(test -f "$PD/lib/StaffApiCsrf.php" && echo yes || echo no)" "nono" "partner_api.php and StaffApiCsrf.php are NOT installed — the undeployed work stayed on the branch"
check "$(data_digest)" "$D0" "the deploy wrote no table and no configuration value — the backfill's DRY RUN in R7 included (the seeded advance still has no cash-in)"
check "$(photo_tables)$(dist_tables)" "28" "…the two photo tables and the eight pilot tables are as before"
check "$(grep -c "cd $REPO && bash scripts/deploy-5.18.68.sh --rollback" <<<"$OUT")" "1" "the rollback command is printed once, on its own line, never beside the deploy"
check "$(awk '/PASSED\. Send this LOG FILE back/ {p=1} p && /deploy-5\.18\.68\.sh --rollback/ {print "after"; exit}' <<<"$OUT")" "after" \
  "…the rollback block comes after the verdict, at the end of the log, never in the block the operator pasted"
check "$(grep -c "backfill_staff_cash_ins.php --apply" <<<"$OUT")" "1" "the backfill's --apply command is printed once"
check "$(awk '/deploy-5\.18\.68\.sh --rollback/ {p=1} p && /backfill_staff_cash_ins\.php --apply/ {print "after"; exit}' <<<"$OUT")" "after" \
  "…after the rollback block, as its own third block"
check "$(grep -cE '^\s+docker exec -it ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/backfill_staff_cash_ins.php --apply' <<<"$OUT")" "1" "…addressed to the installed tool inside the container"
BK="$(ls -d "$SB/out"/backup-* | tail -1)"
check "$(tar -xzOf "$BK"/plugin-installed-5.18.67.tar.gz $P/manifest.json | grep -c '"version": "5.18.67"')" "1" "the code backup is 5.18.67"
check "$(tar -xzOf "$BK"/plugin-installed-5.18.67.tar.gz $P/tabs/accounts/cashbook.php | grep -cF -- "$MARK")" "0" \
  "…and the backup's Cashbook page has the position cards: a rollback restores 5.18.67 exactly"

echo; echo "== 3. R1/R6 and R5 have teeth: a changed file reverted on the server is caught =="
git -C "$REPO" show "$BASE:$P/lib/StaffCashPositionService.php" > "$PD/lib/StaffCashPositionService.php"   # revert the position service to 5.18.67
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R1 installed files that differ: lib/StaffCashPositionService.php")" "yes" "3a the position service reverted on the server: R1 fails, naming it"
check "$(has "$OUT" 'FAIL  R6 incomplete:')$(has "$OUT" 'position-service:literal-usd')" "yesyes" "3b …and R6 fails too, naming literal-usd"
git -C "$REPO" show "$PIN:$P/lib/StaffCashPositionService.php" > "$PD/lib/StaffCashPositionService.php"   # put it back
git -C "$REPO" show "$BASE:$P/tabs/accounts/cashbook.php" > "$PD/tabs/accounts/cashbook.php"   # revert the Cashbook page to 5.18.67
OUT="$(run --after-only)"
check "$(has "$OUT" 'cashbook-page:no-cash-in-hand-card')$(has "$OUT" 'cashbook-page:position-cards-still-drawn')" "yesyes" "3b2 the Cashbook page reverted: R6 names the missing card and the position cards"
git -C "$REPO" show "$PIN:$P/tabs/accounts/cashbook.php" > "$PD/tabs/accounts/cashbook.php"
printf '\n// changed on the server\n' >> "$PD/includes/api_handlers.php"; OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R5 files from Release A→5.18.67 differ on the server: includes/api_handlers.php")" "yes" "3c a Release-A/regression file changed on the server: R5 fails, naming it"
git -C "$REPO" show "$PIN:$P/includes/api_handlers.php" > "$PD/includes/api_handlers.php"
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" '5.18.68 (after): PASSED')" "0yes" "3d each fault removed: --after-only PASSES again"

echo; echo "== 4. V3/R2 have teeth: the switch checks read the LIVE pilot state =="
flip --off
OUT="$(run --after-only)"
check "$(has "$OUT" 'the pilot reads off')$(has "$OUT" "ok    R2 the installed set_distributors --show reports pilot=off")$(fails "$OUT")" "yesyes0" "4a pilot turned off: V3/R2 read off, still PASSES"
flip --on
OUT="$(run --after-only)"
check "$(has "$OUT" 'the pilot reads on')$(has "$OUT" 'ok    R2 the installed set_distributors --show reports pilot=on')$(fails "$OUT")" "yesyes0" "4b pilot turned back on: V3/R2 read on again, PASSES"

echo; echo "== 4e. R4 has teeth: a live channel, or a partner-portal file, on the install is caught =="
printf "\n\$x = new EvolutionWhatsAppChannel(\$evo, 'sales'); // planted\n" >> "$PD/webhook.php"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R4 the installed pilot is incomplete, binds a live channel, or carries files not in this release:')$(has "$OUT" 'live-channel-bound')" "yesyes" "4e a live channel planted in webhook.php: R4 FAILS, naming it"
git -C "$REPO" show "$PIN:$P/webhook.php" > "$PD/webhook.php"
printf '<?php // planted: a portal file that is not in this release\n' > "$PD/partner_api.php"
OUT="$(run --after-only)"
check "$(has "$OUT" 'partner_api.php:present(not-in-this-release)')" "yes" "4f a partner-portal file planted on the install: R4 FAILS, naming it"
rm -f "$PD/partner_api.php"
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" 'ok    R4 the pilot libs')" "0yes" "4g both removed: R4 passes again"

echo; echo "== 5. the rollback: --rollback, typed ROLLBACK =="
D1="$(data_digest)"; fresh; OUT="$(run --answer ROLLBACK --rollback)"
check "$(fails "$OUT")$(has "$OUT" '5.18.68 (rollback): PASSED')" "0yes" "PASSED"
for l in "GO — evidence recorded" "checking out $BASE (5.18.67) for the documented deploy" "ok    container serves $BASE (5.18.67)" \
         "ok    RB the installed manifest says 5.18.67" \
         "ok    RB the installed Cashbook page is 5.18.67's again (the position cards as before this release)" \
         "ok    RB the installed position service is 5.18.67's again" \
         "ok    RB the 5.18.66 photo surface and the 5.18.67 card are still there" \
         "note  RB the code is 5.18.67 again"; do
  check "$(has "$OUT" "$l")" "yes" "5: ${l:0:90}"
done
check "$(has "$OUT" "ok    backed up the installed plugin (5.18.68, $PIN)")" "yes" "the backup first — of 5.18.68's code"
check "$(live)$(installed_digest "$BASE")" "${BASE}0" "the container serves $BASE: every changed file as 5.18.67 has it"
check "$(inst_ver)" "5.18.67" "the installed manifest is 5.18.67 again"
check "$(grep -cF -- "$MARK" "$PD/tabs/accounts/cashbook.php")$(grep -cF -- "$POSMARK" "$PD/lib/StaffCashPositionService.php")$(grep -c 'var UG_PHOTOS' "$PD/tabs/support/scheduling.php")" "001" "the installed Cashbook page and position service are 5.18.67's again, the photo surface intact"
check "$(data_digest)" "$D1" "the rollback changed no data"

echo; echo "== 6. a weakened copy of the script must be caught (control on the control) =="
install_base; seed_data; vault UGX; fresh; flip --on; OUT="$(run --answer DEPLOY)"; check "$(fails "$OUT")" "0" "control: re-deployed 5.18.68 for the mutant test"
MUT="$REPO/scripts/.mutant-r1.sh"
python3 - "$DEPLOY" "$MUT" <<'PY'
import sys
src, dst = sys.argv[1], sys.argv[2]
s = open(src).read()
old = 'if [ -n "$b" ] && [ "$a" = "$b" ]; then R_OK=$((R_OK+1)); else R_BAD="$R_BAD $rel"; fi'
new = 'R_OK=$((R_OK+1))'
assert s.count(old) == 1, "R1 anchor not unique"
open(dst, 'w').write(s.replace(old, new))
PY
git -C "$REPO" show "$BASE:$P/tabs/sales/wallet.php" > "$PD/tabs/sales/wallet.php"
OUT="$(run --script "$MUT" --after-only)"
check "$(has "$OUT" "ok    R1 all $N_CH files 5.18.68 changes are installed exactly as $PIN has them")" "yes" "6a the R1-blinded copy calls a reverted wallet page installed — the mutation is detected"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R1 installed files that differ: tabs/sales/wallet.php')" "yes" "6b …and the real script FAILS R1 on the same fault, naming the file"
rm -f "$MUT"; git -C "$REPO" show "$PIN:$P/tabs/sales/wallet.php" > "$PD/tabs/sales/wallet.php"

echo; echo "== 7. what the rehearsal left behind =="
check "$(checkout_state)" "$CHECKOUT0" "this checkout is as the rehearsal found it: same commit, same tracked files"
check "$(ls "$REPO/scripts"/.mutant-* "$REPO/scripts"/.unpinned.sh "$REPO/scripts"/.tip.sh 2>/dev/null | wc -l | tr -d ' ')" "0" "no weakened copy is left in the clone"

echo; echo "rehearsal: $PASS passed, $FAILN failed ($(cat "$SB/runs") runs of the script)"
[ "$FAILN" = "0" ]
