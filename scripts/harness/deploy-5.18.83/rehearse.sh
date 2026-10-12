#!/usr/bin/env bash
# Rehearse scripts/deploy-5.18.83.sh — the deploy, its checks, migration 086 applied by the plugin's own runner, and the
# ROLLBACK — before the operator runs any of it.
#
# 5.18.83 over 5.18.74: Customer Installation Authorisation for Starlink installation jobs (docs/63, docs/64), delivered
# SWITCHED OFF. One additive migration (086: five tables, four indexes, ten triggers), eight new files, twenty-three changed.
# Deployed from a RELEASE COMMIT on release/5.18.83, cut on the live 5.18.74 release commit (db18ad9), not from the branch
# tip, which also carries the undeployed partner portal, the PD-8 CSRF guard and the AI communication layer batches.
# This rehearsal proves:
#   · the pin is exactly the release: cut on 5.18.74, 31 files (8 added, 23 changed), one migration (086), every guard,
#     the page, the link redaction and the activation step present, and none of the undeployed work;
#   · the script runs end to end against a 5.18.74 base (the pilot ON, a UGX book holding a receipt and a Staff Advance,
#     so cash in hand is 850,000) and PASSES; the stand-in web server boots the INSTALLED plugin's store on every request,
#     as public.php does, so migration 086 is applied by the plugin's own runner — R3 then finds it complete and EMPTY;
#   · V7 reads the INSTALLED customer page through the stand-in: 404 "This page is not available." with the switch off;
#   · the deploy switches nothing on and writes no record: install_auth_enabled reads absent before and after (V3b, R2),
#     every pre-existing table is byte-identical, the five new tables hold no row;
#   · stage A refuses, before anything changes: the switch already ON; a migration 086 already recorded; a pin carrying a
#     file that is not this release's (an AI-layer file planted in a commit cut on 5.18.74); a pin lacking one of its
#     files; the branch tip; a placeholder pin; a server on 5.18.72;
#   · R1/R3/R6 have TEETH: a gate-less customer page installed → V7, R1 and R6 each name it; a trigger dropped from the
#     database (what a PARTIAL run leaves) → R3 names it; a PARTIAL line in migration.log → R3 says so; the installed 086
#     edited → R1 and R3's ledger check both fail; a 5.18.74 file changed → R5 names it;
#   · the lazy branch: when no request has reached the plugin yet, R3 says 086 is not applied yet (a note, not a failure),
#     and --after-only finds it applied once a request has;
#   · after the operator's own activation (emulated: the switch ON in configuration and an activation row), --after-only
#     still PASSES — V7 expects the live page's answer, R3 reports the rows — and the ROLLBACK REFUSES until the switch is
#     off, changing nothing;
#   · the rollback returns the plugin to 5.18.74 — manifest 5.18.74, no route, guard, hook or redaction of 5.18.83 reached,
#     every earlier release's code intact — and changes no data; the files 5.18.83 added stay on disk, reached by nothing;
#   · weakened copies of the script (R1 blinded; R3's completeness check blinded) are caught — the control on the control;
#   · the clone is left as found.
#
# Everything runs in a sandbox: a fake `docker` that maps /data/ucrm to a directory here, a clone of this checkout, the plugin
# installed as 5.18.74 exactly (git archive of the release commit), and a stand-in web server for stage V's pages. DEPLOY and
# ROLLBACK are typed through a pseudo-terminal, as the operator types them. It never touches this checkout, the server or the
# network, and refuses to run where the server could be.
#
#   bash scripts/harness/deploy-5.18.83/rehearse.sh            REHEARSE_KEEP=<dir> keeps every run's full output
set -u
R="$(cd "$(dirname "$0")/../../.." && pwd)"
for f in /data/ucrm /opt/dishnet /var/run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
BASE=db18ad9; RA_BASE=e076632; OLDER=88d8442; BRANCH_FILE=scripts/deploy-5.18.83.sh; P=dishnet-hybrid-sudan; RELEASE_BRANCH=release/5.18.83
MIG=migrations/086_install_authorisation.sql
RULE='public static function decision(\PDO $pdo, array $config, array $job): array'   # the one rule (lib/InstallAuth.php)
ROUTE="if (\$page === 'install_auth') {"                                            # the customer page's route (public.php)
GATE='if (!InstallAuth::enabled($iaConfig, $iaDataDir)) {'                           # the page's first decision
HANDLERS="require __DIR__ . '/api/api_install_auth.php';"                             # the staff actions (includes/api_handlers.php)
G_ACC="\$job, \$statusInt, 'accept', (int)(\$_sjMe['id'] ?? 0));"                    # the guard on Accept Job / status updates
G_CMP="\$job, 2, 'complete', \$rid);"                                                # the guard on Complete Job
G_IN="\$_iaJob, 1, 'checkin', (int)(\$me2['id'] ?? 0));"                             # the guard on GPS check-in
G_OUT="\$_iaJob, 2, 'checkout', (int)(\$me2['id'] ?? 0));"                           # the guard on GPS check-out (5.18.83)
NEVERQ="public const NEVER_QUEUED = ['app_otp', 'ops_install_auth_request'];"        # the link never queued (lib/NotificationService.php)
OBSERVE='private function installAuthObserve(int $jobId, ?array $was, array $job): ?array'   # the uCRM-side check (lib/JobNotifier.php)
ACTIVATE='$iaActivation = InstallAuth::activate($iaPdo, CrmApiClient::fromUcrm($root, $iaCur),'   # the activation step (tools/set_config.php)
HANDOFF='public function dataReportHandoff(): bool'   # 5.18.74's tenant setting — must survive this release and its rollback
PAY='>How to pay</div>'                            # 5.18.74's "How to pay" — must survive too
WORD72="'Still to account for'"                   # 5.18.72's Staff Cashbooks wording — must survive too
HERO71='Cash in hand — per currency'          # 5.18.71's landing hero — must survive too
CIHTOOL='== cash in hand (5.18.71)'            # 5.18.71's read-only tool, run in R7 — must survive too
PREVMARK='var _fr3Base = <?= json_encode(dn_book_base($config)) ?>;'   # 5.18.70's Field Register token — must survive too
PREV69="'currency'        => \$manCur,"             # 5.18.69's Manual Entry stamp — must survive too
CHAINMARK='CASH IN HAND</div>'                 # 5.18.68's Cashbook card — must survive too
EXPECT_N=31; EXPECT_AD=8                # files in the release delta (set when the release commit was cut)
IA_TRIGGERS="install_auth_accepted_is_final install_auth_never_deleted install_auth_status_moves install_auth_request_is_fixed install_auth_events_no_update install_auth_events_no_delete install_auth_activations_no_update install_auth_activations_no_delete install_auth_exempt_no_update install_auth_exempt_no_delete"
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
# The release branch lives in this checkout locally until it is pushed; the clone sees it as origin/<branch> either way.
git -C "$REPO" fetch -q origin "refs/heads/$RELEASE_BRANCH:refs/remotes/origin/$RELEASE_BRANCH" 2>/dev/null || true
find "$REPO/dishnet-hybrid-sudan" -type f -exec touch -d "@$(git -C "$REPO" log -1 --format=%ct)" {} +
PIN="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT="\([^"]*\)".*/\1/p' "$DEPLOY")"
TIP="$(git -C "$REPO" log -1 --format=%h -- $P)"
echo "== 0. what is rehearsed =="
echo "  script    $WHICH, sha256 $(sha256sum "$DEPLOY" | cut -c1-16)"
echo "  clone     $BRANCH at $(git -C "$REPO" rev-parse --short HEAD); branch-tip plugin commit $TIP"
case "$PIN" in __*) echo "  FAIL the script still carries the placeholder pin ($PIN) — fill EXPECTED_PLUGIN_COMMIT with the release commit, then rehearse"; exit 2;; esac
REL="$(git -C "$REPO" rev-parse --short "origin/$RELEASE_BRANCH" 2>/dev/null || echo none)"
check "$PIN" "$REL" "the script is pinned to the release commit on $RELEASE_BRANCH ($PIN)"
check "$(git -C "$REPO" rev-parse --short "$PIN^" 2>/dev/null)" "$BASE" "control: the release commit is cut on $BASE (5.18.74), the live version"
ver() { git -C "$REPO" show "$1:$P/manifest.json" | python3 -c 'import json,sys; print(json.load(sys.stdin)["information"]["version"])'; }
check "$(ver "$PIN")" "5.18.83" "the plugin at the pin is 5.18.83"
check "$(ver "$BASE")" "5.18.74" "control: the base is 5.18.74"
dl() { git -C "$REPO" diff --no-renames --name-only --diff-filter="$1" "$2" "$3" -- $P; }
CHANGED="$(dl AM "$BASE" "$PIN")"
N_CH="$(grep -c . <<<"$CHANGED")"; N_AD="$(dl A "$BASE" "$PIN" | grep -c .)"; N_MO=$((N_CH - N_AD)); N_DEL="$(dl D "$BASE" "$PIN" | grep -c .)"
echo "  5.18.83   $N_CH files: $N_MO changed, $N_AD added · $N_DEL removed or renamed"
check "$N_DEL" "0" "control: 5.18.74 → 5.18.83 removes no file"
check "$N_AD" "$EXPECT_AD" "control: 5.18.83 adds exactly $EXPECT_AD files"
check "$N_CH" "$EXPECT_N" "control: $EXPECT_N files in the delta"
for want in "includes/api/api_install_auth.php" "lib/InstallAuth.php" "lib/InstallAuthEmails.php" "lib/InstallAuthNotifier.php" "lib/InstallationTerms.php" \
            "$MIG" "tabs/customer_app/install_auth_page.php" "tests/test_install_authorisation.php" \
            "includes/api/api_crm_misc.php" "includes/api/api_field_ops.php" "includes/api/api_scheduling.php" "includes/api_handlers.php" \
            "lib/CustomerEmailDispatcher.php" "lib/JobNotifier.php" "lib/NotificationService.php" "manifest.json" "public.php" \
            "tabs/support/scheduling.php" "tools/set_config.php" "webhook.php" \
            "tests/test_customer_otp_transport.php" "tests/test_job_access.php" "tests/test_notify_customer_fixes.php" "tests/test_set_config_tool.php" \
            "tests/test_notify_evo_retry.php" "tests/test_sales_support_tenant.php" \
            "tests/test_distributor_registry.php" "tests/test_distributor_apply.php" "tests/test_distributor_territory.php" \
            "tests/test_distributor_notify.php" "tests/test_distributor_link_ucrm.php"; do
  check "$(grep -c "^$P/$want$" <<<"$CHANGED")" "1" "control: the delta includes $want"
done
check "$(grep -cE "^$P/(partner_api\.php|lib/Partner|lib/StaffApiCsrf\.php|lib/Totp\.php|lib/DistributorPortalData\.php|lib/Media|lib/InboundMedia|lib/Voice|lib/Image|lib/Document|lib/Pdf|migrations/08[1235]_)" <<<"$CHANGED")" "0" \
  "control: no partner-portal, CSRF or AI-layer file in the delta"
check "$(grep -c '^'"$P"'/migrations/' <<<"$CHANGED")$(grep -c "^$P/$MIG$" <<<"$CHANGED")" "11" "control: 5.18.83 adds exactly one migration, 086"
at() { git -C "$REPO" show "$1:$P/$2" 2>/dev/null | grep -cF -- "$3"; }
check "$(at "$PIN" lib/InstallAuth.php "$RULE")$(at "$PIN" public.php "$ROUTE")$(at "$PIN" tabs/customer_app/install_auth_page.php "$GATE")$(at "$PIN" includes/api_handlers.php "$HANDLERS")$(at "$PIN" includes/api/api_scheduling.php "$G_ACC")$(at "$PIN" includes/api/api_scheduling.php "$G_CMP")$(at "$PIN" includes/api/api_field_ops.php "$G_IN")$(at "$PIN" includes/api/api_field_ops.php "$G_OUT")$(at "$PIN" lib/NotificationService.php "$NEVERQ")$(at "$PIN" lib/JobNotifier.php "$OBSERVE")$(at "$PIN" tools/set_config.php "$ACTIVATE")" "11111111111" \
  "control: at the pin the one rule, the route, the page's gate, the staff actions, the four guards, the never-queued list, the uCRM-side check and the activation step are there"
check "$(at "$BASE" public.php "$ROUTE")$(at "$BASE" includes/api_handlers.php "$HANDLERS")$(at "$BASE" includes/api/api_scheduling.php "$G_ACC")$(at "$BASE" includes/api/api_field_ops.php "$G_OUT")$(at "$BASE" lib/NotificationService.php "$NEVERQ")$(at "$BASE" lib/JobNotifier.php "$OBSERVE")$(at "$BASE" tools/set_config.php "$ACTIVATE")" "0000000" "control: 5.18.74 has none of them (changed by this release)"
check "$(at "$PIN" lib/TenantProfile.php "$HANDOFF")$(at "$PIN" tabs/customer_app/portal.php "$PAY")$(at "$PIN" tabs/accounts/staff_cashbooks.php "$WORD72")$(at "$PIN" tabs/accounts/accounts_dashboard.php "$HERO71")$(at "$PIN" tools/cash_in_hand.php "$CIHTOOL")" "11111" "control: 5.18.74's hand-off setting and \"How to pay\", 5.18.72's wording, 5.18.71's hero and tool are still there at the pin (regression)"
check "$(at "$PIN" tabs/sales/wallet.php "$PREVMARK")$(at "$PIN" tabs/accounts/staff_cashbooks.php "$PREV69")$(at "$PIN" tabs/accounts/cashbook.php "$CHAINMARK")$(at "$PIN" lib/StaffCashPositionService.php 'dn_book_base(null)')" "1111" "control: 5.18.70's token, 5.18.69's stamp, 5.18.68's card and base-reading position service are still there at the pin (regression)"
check "$(at "$PIN" tabs/support/scheduling.php 'flex-shrink:0;">📷 ')$(git -C "$REPO" show "$PIN:$P/tabs/support/scheduling.php" | grep -c 'window.schOpenCompleteFormImpl=function')" "11" "control: the 5.18.67 card layout and the 5.18.66 completion form are still there at the pin (regression)"
check "$(at "$PIN" webhook.php 'DistributorEvents::maybeNotify')$(at "$PIN" lib/DistributorNotifier.php 'new NullWhatsAppChannel')" "21" "control: webhook.php still draws the two draft hooks and the notifier still binds the Null channel at the pin (regression)"
HEADER_CMD="$(sed -n '/^# Run as root/,/^# The rollback is a separate command/p' "$DEPLOY")"
check "$(grep -c 'deploy-5.18.83.sh 2>&1 | tee' <<<"$HEADER_CMD")$(grep -cE -- '--rollback|--void|--relabel|git checkout [0-9a-f]{7}' <<<"$HEADER_CMD")" "10" \
  "the header's deploy command stands alone: no rollback, no --void/--relabel and no checkout of another commit in its block (docs/44 §16.9)"
check "$(grep -c "git fetch origin $RELEASE_BRANCH" <<<"$HEADER_CMD")" "1" "the header's deploy command fetches the release branch"
check "$(grep -cE -- '--key +install_auth_enabled|install_auth_enabled +--value' "$DEPLOY")" "0" "the script carries no command that switches the feature on — neither to run nor to print"

# ── The container: 5.18.74 installed, its data beside it ─────────────────────
MOUNT="$SB/mount"; PLUGINS="$MOUNT/ucrm/data/plugins"; PD="$PLUGINS/$P"; DATA="$PLUGINS/.$P-data"
VAULT="$PLUGINS/.dishnet-sudan.vault.json"
mkdir -p "$PD" "$DATA" "$SB/web" "$SB/bin" "$SB/out"
IN_CONTAINER="/data/ucrm/data/plugins/$P"
free_port() { python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()'; }
WPORT="$(free_port)"; PLUGIN_BASE="http://127.0.0.1:$WPORT/public.php"
printf '%s\n%s\n' "$PD" "$DATA" > "$SB/web/.installed_plugin"   # the stand-in reads the INSTALLED plugin, so V6 and V7 read real code
# The stand-in public.php. On every request it boots the INSTALLED plugin's store, as the real public.php does — so the
# plugin's own migration runner applies a pending migration on the first request after a copy (unless .no_boot is there:
# the "nothing has reached the plugin yet" case). ?page=install_auth runs the INSTALLED page itself when the installed
# public.php routes it, with public.php's own $config — the store row; the sign-in page carries the installed viewport line.
cat > "$SB/web/public.php" <<'PHP'
<?php
[$root, $dataDir] = array_map('trim', array_slice(explode("\n", (string)@file_get_contents(__DIR__ . '/.installed_plugin')), 0, 2));
$store = null;
if (!is_file(__DIR__ . '/.no_boot')) {
    foreach (['bootstrap_data', 'StoreInterface', 'JsonStore', 'SqliteStore'] as $l) require_once $root . "/lib/$l.php";
    $store = SqliteStore::create($dataDir);
}
$page = $_GET['page'] ?? '';
if ($page === 'install_auth' && strpos((string)@file_get_contents($root . '/public.php'), "if (\$page === 'install_auth') {") !== false) {
    // public.php's own source of $config: the store row. With nothing booted, the files.
    if ($store !== null) { $config = $store->load('kyc_config.json'); }
    else { require_once $root . '/lib/PluginConfig.php'; $config = PluginConfig::read($root, $dataDir); }
    require $root . '/tabs/customer_app/install_auth_page.php';
    exit;
}
if ($page === 'customer_portal' || $page === 'install_auth') { header('Location: ?page=customer_login', true, 302); exit; }
if ($page === 'job_photo') { header('Location: ?page=login', true, 302); exit; }
if ($page === 'api') { http_response_code(401); header('Content-Type: application/json'); echo '{"status":"error","message":"Unauthorized."}'; exit; }
$vp = '';
$lw = (string)@file_get_contents($root . '/tabs/customer_app/login_web.php');
if ($lw !== '' && preg_match('/<meta name="viewport"[^>]*>/', $lw, $m)) $vp = $m[0];
echo '<html><head>' . $vp . '</head><body>Sign in</body></html>';
PHP
php -S "127.0.0.1:$WPORT" -t "$SB/web" >"$SB/web.log" 2>&1 & PIDS+=($!)
standin_page() { curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "$PLUGIN_BASE?page=$1"; }

install_base() {   # the installed plugin exactly as 5.18.74
  find "$PD" -mindepth 1 -maxdepth 1 ! -name ucrm.json -exec rm -rf {} +
  git -C "$REPO" archive "$BASE:$P" | tar -x -C "$PD"
  printf '%s\n' "$BASE" > "$PD/.deployed-commit"
  printf '%s\n' '[2026-10-06 08:20:01] [master] RUN staff_jobs' > "$PD/data/plugin.log" 2>/dev/null || true
}
printf '{"pluginDataDir":"/data/ucrm/data/plugins/.%s-data","ucrmPublicUrl":"http://127.0.0.1:1/crm"}' "$P" > "$PD/ucrm.json"
vault() { printf '{"config":{"currency_code":"%s"}}' "$1" > "$VAULT"; }
seed_data() {      # the plugin's databases, written by 5.18.74's own store (so 084's tables exist, empty); the pilot is enabled through the real tool
  rm -rf "$DATA"/plugin.sqlite3* "$DATA"/dishnet.sqlite* "$DATA"/config.json "$DATA"/kyc_config.json "$DATA"/migration.log "$DATA"/uploads
  php -r '
    foreach (["bootstrap_data", "StoreInterface", "JsonStore", "SqliteStore"] as $l) require_once $argv[1] . "/lib/$l.php";
    require_once $argv[1] . "/lib/CashbookService.php";
    $s = SqliteStore::create($argv[2]);
    $s->save("kyc_config.json", ["crm_base_url" => "http://127.0.0.1:1", "company_name" => "DishNet Sandbox", "currency_code" => "UGX",
        "tenant_profile" => "uganda", "cashbook_base_currency" => "UGX", "cashbook_currencies" => "UGX,USD", "currency_symbol" => "UGX"]);
    file_put_contents($argv[2] . "/kyc_config.json", json_encode($s->load("kyc_config.json")));
    // one staff member; a UGX book holding a receipt and a Staff Advance, so cash in hand is 850,000
    $s->appendWithId("retailers.json", ["name" => "Rehearsal Tech", "email" => "tech@example.test", "role" => "support", "is_active" => true]);
    $cb = new CashbookService($s, $argv[2]);
    $cb->addEntryRaw(["project" => "dishnet", "date" => date("Y-m-d"), "direction" => "in", "amount" => 1000000, "currency" => "UGX",
        "category" => "Receipt", "category_raw" => "Receipt", "person" => "", "description" => "opening float", "status" => "approved", "source" => "manual"]);
    $cb->addEntryRaw(["project" => "dishnet", "date" => date("Y-m-d"), "direction" => "out", "amount" => 150000, "currency" => "UGX",
        "category" => "Staff Advance", "category_raw" => "Staff Advance", "person" => "Rehearsal Tech", "description" => "rehearsal", "status" => "approved", "source" => "manual"]);
  ' "$PD" "$DATA" >/dev/null
  php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec("PRAGMA journal_mode=WAL"); $p->exec("CREATE TABLE wa_messages(id INTEGER PRIMARY KEY, body TEXT)");
    for ($i = 0; $i < 10; $i++) $p->exec("INSERT INTO wa_messages(body) VALUES (\x27hello $i\x27)");' "$DATA/dishnet.sqlite"
}
inst_ver() { grep -o '"version": *"5[^"]*"' "$PD/manifest.json" | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/'; }
live() { tail -n1 "$PD/.deployed-commit" 2>/dev/null | tr -cd '0-9a-f'; }
backups() { ls -d "$SB/out"/backup-* 2>/dev/null | wc -l | tr -d ' '; }
sq() { php -r '$p = new PDO("sqlite:" . $argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $r = $p->query($argv[2]); echo $r ? implode(",", $r->fetchAll(PDO::FETCH_COLUMN)) : "";' "$DATA/plugin.sqlite3" "$1" 2>/dev/null || echo err; }
sqx() { php -r '$p = new PDO("sqlite:" . $argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $p->exec($argv[2]); echo "done";' "$DATA/plugin.sqlite3" "$1" 2>&1; }
dist_tables() { sq "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('dist_partners','dist_appointment','dist_regions','dist_territory_map','dist_customer_links','dist_contacts','dist_notify_consent','dist_notify_log')"; }
ledger_rows() { sq "SELECT COUNT(*) FROM cb_ledger WHERE currency='UGX' AND status='approved'"; }
photo_tables() { sq "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('job_photos','job_completion_gps')"; }
ia_objects() { sq "SELECT (SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('install_auth','install_auth_events','install_auth_activations','install_auth_exempt','install_auth_rate')) || ':' || (SELECT COUNT(*) FROM sqlite_master WHERE type='trigger' AND name LIKE 'install_auth%') || ':' || (SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name IN ('idx_install_auth_client','idx_install_auth_status','idx_install_auth_events_job','idx_install_auth_rate'))"; }
ia_rows() { sq "SELECT (SELECT COUNT(*) FROM install_auth) || ':' || (SELECT COUNT(*) FROM install_auth_events) || ':' || (SELECT COUNT(*) FROM install_auth_activations) || ':' || (SELECT COUNT(*) FROM install_auth_exempt) || ':' || (SELECT COUNT(*) FROM install_auth_rate)"; }
mig_row() { sq "SELECT COUNT(*) FROM _migrations WHERE filename = '086_install_authorisation.sql'"; }
_canon() { php -r 'function c($x){ if (is_array($x)) { ksort($x); foreach ($x as &$v) $v = c($v); } return $x; }
  echo json_encode(c(json_decode((string)@file_get_contents($argv[1]), true)));' "$1" 2>/dev/null; }
data_dump() {   # every table's rows but the ledger of migrations and 086's own five; the vault; the configuration files
  php -r '$p = new PDO("sqlite:" . $argv[1]); $skip = ["_migrations", "sqlite_sequence"];
      foreach ($p->query("SELECT name FROM sqlite_master WHERE type = \x27table\x27 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if (in_array($t, $skip, true) || strpos($t, "install_auth") === 0) continue;
        echo $t, ":", json_encode($p->query("SELECT * FROM [$t]")->fetchAll(PDO::FETCH_NUM)), "\n"; }' "$DATA/plugin.sqlite3"
  echo "vault:$(_canon "$VAULT")"
  [ -f "$DATA/kyc_config.json" ] && echo "kyc:$(_canon "$DATA/kyc_config.json")"
  [ -f "$DATA/config.json" ] && echo "config:$(_canon "$DATA/config.json")"
  true
}
data_digest() { data_dump | sha256sum | cut -c1-16; }
installed_digest() {   # $1 a commit: the number of files 5.18.83 touches that are NOT installed as that commit has them
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
ia_set() {   # on|off [files]: the switch as set_config.php writes it — the override file AND the store row public.php reads
             # ("files": the file only — the two copies disagree); off puts both back byte for byte
  php -r '
    $mode = $argv[1]; $data = $argv[2]; $keep = $argv[3]; $only = $argv[4] ?? "";
    $file = $data . "/kyc_config.json";
    $p = new PDO("sqlite:" . $data . "/plugin.sqlite3"); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if ($mode === "on") {
      $row = $p->query("SELECT data FROM kyc_config WHERE id = 0")->fetchColumn();
      if (!is_file($keep)) file_put_contents($keep, json_encode(["file" => file_get_contents($file), "row" => $row]));
      $f = json_decode((string)file_get_contents($file), true); $f["install_auth_enabled"] = "1"; file_put_contents($file, json_encode($f));
      if ($only !== "files") { $r = json_decode((string)$row, true); $r["install_auth_enabled"] = "1"; $p->prepare("UPDATE kyc_config SET data = ? WHERE id = 0")->execute([json_encode($r)]); }
    } else {
      $o = json_decode((string)file_get_contents($keep), true);
      file_put_contents($file, $o["file"]); $p->prepare("UPDATE kyc_config SET data = ? WHERE id = 0")->execute([$o["row"]]); unlink($keep);
    }
    echo "done";
  ' "$1" "$DATA" "$SB/ia_keep.json" "${2:-}" >/dev/null
}

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
pinned_copy() {   # pinned_copy NAME COMMIT — a copy of the script pinned to another commit
  sed "s/^EXPECTED_PLUGIN_COMMIT=\"[^\"]*\"/EXPECTED_PLUGIN_COMMIT=\"$2\"/" "$DEPLOY" > "$REPO/scripts/.$1.sh"; echo "$REPO/scripts/.$1.sh"
}
release_variant() {   # release_variant add|drop PATH — a commit cut on 5.18.74 holding the release's tree with one file planted or removed
  local idx="$SB/variant.idx" tree c
  GIT_INDEX_FILE="$idx" git -C "$REPO" read-tree "$PIN"
  if [ "$1" = "add" ]; then
    GIT_INDEX_FILE="$idx" git -C "$REPO" update-index --add --cacheinfo "100644,$(printf '<?php // planted\n' | git -C "$REPO" hash-object -w --stdin),$P/$2"
  else GIT_INDEX_FILE="$idx" git -C "$REPO" update-index --force-remove "$P/$2"; fi
  tree="$(GIT_INDEX_FILE="$idx" git -C "$REPO" write-tree)"; rm -f "$idx"
  c="$(git -C "$REPO" -c user.name=rehearsal -c user.email=rehearsal@example.test commit-tree "$tree" -p "$(git -C "$REPO" rev-parse "$BASE")" -m "rehearsal variant: $1 $2")"
  git -C "$REPO" rev-parse --short "$c"
}

install_base; seed_data; vault UGX; fresh
flip --on   # enable the pilot through the real tool, exactly as the live server has it
for i in $(seq 1 50); do [ "$(standin_page customer_login)" = "200" ] && break; sleep 0.1; done
D0="$(data_digest)"
check "$(inst_ver)" "5.18.74" "control: the installed base is 5.18.74"
check "$(grep -cF -- "$ROUTE" "$PD/public.php")$(grep -cF -- "$HANDLERS" "$PD/includes/api_handlers.php")$(test -f "$PD/lib/InstallAuth.php" && echo yes || echo no)$(test -f "$PD/$MIG" && echo yes || echo no)" "00nono" "control: the base has no route, no staff actions, no InstallAuth and no migration 086"
check "$(grep -cF -- "$HANDOFF" "$PD/lib/TenantProfile.php")$(grep -cF -- "$PAY" "$PD/tabs/customer_app/portal.php")$(grep -cF -- "$WORD72" "$PD/tabs/accounts/staff_cashbooks.php")$(grep -cF -- "$HERO71" "$PD/tabs/accounts/accounts_dashboard.php")$(grep -cF -- "$CIHTOOL" "$PD/tools/cash_in_hand.php" 2>/dev/null)" "11111" "control: the base has 5.18.74's hand-off setting and How-to-pay, 5.18.72's wording, 5.18.71's hero and tool"
check "$(grep -cF -- "$PREVMARK" "$PD/tabs/sales/wallet.php")$(grep -cF -- "$PREV69" "$PD/tabs/accounts/staff_cashbooks.php")$(grep -cF -- "$CHAINMARK" "$PD/tabs/accounts/cashbook.php")$(grep -c 'var UG_PHOTOS' "$PD/tabs/support/scheduling.php")" "1111" "control: …and 5.18.70's token, 5.18.69's stamp, 5.18.68's card and the 5.18.66 photo surface are there"
check "$(ledger_rows)" "2" "control: the seeded UGX book holds its two approved rows (a receipt of 1,000,000 and a Staff Advance of 150,000)"
check "$(dist_tables)$(photo_tables)" "82" "control: the base plugin.sqlite3 has the 8 distributor tables and 084's two photo tables"
check "$(ia_objects)$(mig_row)" "0:0:00" "control: the base has none of 086's objects and no ledger row for it"
check "$(env PATH="$SB/bin:$PATH" DN_DATA_DIR="$DATA" bash "$SB/bin/docker" exec ucrm php "$IN_CONTAINER/tools/set_distributors.php" --show 2>/dev/null | awk '/distributors_enabled/ {print toupper($2); exit}')" "ON" "control: the pilot is ON on the base"
check "$(standin_page customer_login)$(data_digest)" "200$D0" "control: the stand-in boots the installed store on a request and that changes no data (the digest stands)"

echo; echo "== 1. NO-GO before anything changes =="
printf '%s\n' "$OLDER" > "$PD/.deployed-commit"; fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: the container serves $OLDER; 5.18.83 was built and tested against $BASE (5.18.74) — deploy 5.18.74 first (scripts/deploy-5.18.74.sh) and send its log")" "yes" \
  "1a the server still runs 5.18.72: NO-GO — 5.18.74 goes first"
check "$(live)$(backups)" "${OLDER}0" "…nothing deployed, no backup taken"
printf '%s\n' "$BASE" > "$PD/.deployed-commit"
S="$(pinned_copy unpinned __PLUGIN_COMMIT__)"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
check "$(has "$OUT" 'STOP: this copy of the script is not pinned to a reviewed commit')$(live)$(backups)" "yes${BASE}0" \
  "1b a copy still carrying the placeholder pin: stops before anything is read"
S="$(pinned_copy tip "$TIP")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
check "$(has "$OUT" "STOP: $TIP is not cut on $BASE (5.18.74): its parent is")$(has "$OUT" 'this script is for another build')" "yesyes" \
  "1c a copy pinned to the branch tip ($TIP, parent not 5.18.74): refused — the undeployed work cannot ride along"
check "$(live)$(backups)$(cnt "$OUT" 'serves ')" "${BASE}00" "…before the container was even looked at: nothing deployed, no backup, no live read"
V_ADD="$(release_variant add lib/MediaPolicy.php)"; S="$(pinned_copy stray "$V_ADD")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
check "$(has "$OUT" "STOP: the pin carries files that are not 5.18.83's, which this release must not ship: lib/MediaPolicy.php(added)")$(live)$(backups)" "yes${BASE}0" \
  "1d a commit cut on 5.18.74 carrying the release AND an AI-layer file: refused by the delta's allow-list, naming the file"
V_DROP="$(release_variant drop "$MIG")"; S="$(pinned_copy short "$V_DROP")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
check "$(has "$OUT" "STOP: the pin lacks files 5.18.83 is made of: $MIG(added)")$(live)$(backups)" "yes${BASE}0" \
  "1e a commit cut on 5.18.74 carrying the release WITHOUT migration 086: refused, naming it"
ia_set on; fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: install_auth_enabled reads 'ia=on/on' (files/store) on the live 5.18.74")$(live)$(backups)" "yes${BASE}0" \
  "1f the switch already ON in the live configuration: NO-GO — 5.18.83 must arrive off; nothing deployed, no backup"
ia_set off; ia_set on files; fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: install_auth_enabled reads 'ia=on/absent' (files/store) on the live 5.18.74")$(live)$(backups)" "yes${BASE}0" \
  "1f2 ON in the configuration files only (the store row says nothing): still NO-GO — either copy on is on"
ia_set off
sqx "INSERT INTO _migrations(filename, checksum, duration_ms) VALUES ('086_install_authorisation.sql', 'f00d', 1)" >/dev/null
fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: a migration 086 is already recorded on this server (mig=applied:f00d")$(live)$(backups)" "yes${BASE}0" \
  "1g a migration 086 already in the plugin's ledger: NO-GO — the runner would skip this release's 086; nothing deployed"
sqx "DELETE FROM _migrations WHERE filename = '086_install_authorisation.sql'" >/dev/null
check "$(mig_row)$(data_digest)" "0$D0" "control: the planted ledger row is gone and the data is as seeded"

echo; echo "== 2. the deploy, as the operator runs it =="
fresh; OUT="$(run --answer DEPLOY)"
check "$(fails "$OUT")" "0" "no FAIL line"
check "$(has "$OUT" '5.18.83 (deploy): PASSED')" "yes" "PASSED"
for l in "ok    A0 the release delta is exactly 5.18.83's $N_CH files — $N_AD added, $N_MO changed, one migration (086); no partner-portal, CSRF or AI-layer file" \
         "ok    A2 PHP" \
         "authorisation   ia=absent/absent" \
         "086 state       mig=absent tables=0/5 triggers=0/10 indexes=0/4 checks=0/2 rows=-:-:-:-:- missing=" \
         "— one consistent copy, integrity ok" "ok    backed up the installed plugin (5.18.74, $BASE)" "ok    backed up the configuration vault" \
         "GO — evidence recorded" \
         "checking out $PIN (5.18.83, the release commit) for the documented deploy" \
         "ok    container serves $PIN" \
         "ok    V1 the sign-in page on the public address answers 200 with zero redirects (no loop)" \
         "ok    V1 the portal without a session still refuses" \
         "ok    V2 the sign-in page answers 200 and carries no South Sudan contact" \
         "ok    V6 the sign-in page allows pinch-zoom (no user-scalable=no, no maximum-scale) — as on 5.18.74" \
         "ok    V5 the photo viewer without a session refuses (302) — a photo is never served to nobody" \
         "ok    V5 job_photo_upload without a login answers 401 — the staff guard, before any handler" \
         "ok    V7 the customer authorisation page answers 404 \"This page is not available.\" — the switch is off, so it behaves as a page that does not exist" \
         "ok    V8 install_auth_request without a login answers 401 — the staff guard, before any handler" \
         "ok    V3 the pilot switch is unchanged by the deploy (before=pilot=on, after=pilot=on) — 5.18.83 changes no configuration value" \
         "ok    V3b install_auth_enabled is unchanged by this run (before=ia=absent/absent, after=ia=absent/absent; files/store) — OFF: nothing changes for staff or customers" \
         "ok    V4 no fatal or parse error of $P in the container log since" \
         "ok    R1 all $N_CH files 5.18.83 changes are installed exactly as $PIN has them ($N_AD new)" \
         "ok    R1 the installed manifest says 5.18.83" \
         "ok    R2 the installed set_distributors --show reports pilot=on" \
         "ok    R2 the installed InstallAuth reads install_auth_enabled OFF in the configuration files and in the store row the pages read, and enabled() is false in both — no guard, no page, no request, no alert runs" \
         "ok    R3 migration 086 is applied and complete — 5 tables, 10 triggers, 4 indexes, both CHECKs, the ledger row matching the installed file — and its tables are empty (install_auth:events:activations:exempt:rate = 0:0:0:0:0)" \
         "migration.log  [" \
         "OK: 086_install_authorisation.sql (" \
         "ok    R3 084 is still installed and its two tables exist on the live plugin.sqlite3 — job_photos 0:0 rows" \
         "ok    R4 the pilot libs + the Distributors tab are installed as before" \
         "no partner-portal, CSRF or AI-layer file is installed" \
         "ok    R5 all" \
         "ok    R6 every earlier surface is installed as before" \
         "and 5.18.83's pieces are in place" \
         "ok    R7 cash in hand on the live book, read-only — what the landing hero shows: UGX CASH IN HAND 850,000.00 · USD CASH IN HAND 0.00" \
         "ok    R7 the photo tables and files read exactly as before the deploy" \
         "what changed      Uganda, once switched on: a Starlink installation job needs the customer's acceptance" \
         "TODAY THE SWITCH IS OFF (V3b, R2)" \
         "migrations        one, 086 — five empty tables, their indexes and ten triggers, created additively by the plugin on its next request (R3)"; do
  check "$(has "$OUT" "$l")" "yes" "2: ${l:0:96}"
done
check "$(cnt "$OUT" 'ok    backed up ')" "5" "five backups: two databases, the data directory, the installed plugin, the vault"
check "$(live)$(installed_digest "$PIN")" "${PIN}0" "the container serves $PIN: every changed file exactly as the commit has it"
check "$(grep -cE 'Fiber & Starlink +UGX +850,000\.00' <<<"$OUT")$(grep -cE 'DishNet 4G +UGX +0\.00' <<<"$OUT")$(grep -cE 'BlueCARD +UGX +0\.00' <<<"$OUT")" "111" "R7 prints the base per project: Fiber & Starlink 850,000.00, the others 0.00"
check "$(inst_ver)" "5.18.83" "the installed manifest is 5.18.83"
check "$(grep -cF -- "$RULE" "$PD/lib/InstallAuth.php")$(grep -cF -- "$ROUTE" "$PD/public.php")$(grep -cF -- "$GATE" "$PD/tabs/customer_app/install_auth_page.php")$(grep -cF -- "$G_OUT" "$PD/includes/api/api_field_ops.php")$(grep -cF -- "$NEVERQ" "$PD/lib/NotificationService.php")$(grep -cF -- "$ACTIVATE" "$PD/tools/set_config.php")" "111111" "the installed plugin carries the one rule, the route, the page's gate, the check-out guard, the never-queued list and the activation step"
check "$(grep -cF -- "$HANDOFF" "$PD/lib/TenantProfile.php")$(grep -cF -- "$WORD72" "$PD/tabs/accounts/staff_cashbooks.php")$(grep -cF -- "$HERO71" "$PD/tabs/accounts/accounts_dashboard.php")$(grep -cF -- "$PREVMARK" "$PD/tabs/sales/wallet.php")$(grep -cF -- "$CHAINMARK" "$PD/tabs/accounts/cashbook.php")$(grep -c 'var UG_PHOTOS' "$PD/tabs/support/scheduling.php")" "111111" "…with 5.18.74's setting, 5.18.72's wording, 5.18.71's hero, 5.18.70's token, 5.18.68's card and the photo surface intact"
check "$(for f in partner_api.php lib/StaffApiCsrf.php lib/MediaPolicy.php lib/InboundMedia.php migrations/085_wa_media.sql; do test -f "$PD/$f" && echo "$f"; done | wc -l | tr -d ' ')" "0" "no partner-portal, CSRF or AI-layer file is installed — the undeployed work stayed on the branch"
check "$(ia_objects)$(mig_row)" "5:10:41" "086 was applied by the plugin's own runner on the first request after the copy: 5 tables, 10 triggers, 4 indexes, one ledger row"
check "$(ia_rows)" "0:0:0:0:0" "…and its five tables are empty: with the switch off nothing wrote to them"
check "$(data_digest)" "$D0" "the deploy wrote no record and no configuration value — every pre-existing table, the vault and the configuration files as before"
check "$(ledger_rows)$(photo_tables)$(dist_tables)" "228" "…the seeded ledger still holds its two rows; the two photo tables and the eight pilot tables are as before"
check "$(grep -c "cd $REPO && bash scripts/deploy-5.18.83.sh --rollback" <<<"$OUT")" "1" "the rollback command is printed once, on its own line, never beside the deploy"
check "$(awk '/PASSED\. Send this LOG FILE back/ {p=1} p && /deploy-5\.18\.83\.sh --rollback/ {print "after"; exit}' <<<"$OUT")" "after" \
  "…the rollback block comes after the verdict, at the end of the log, never in the block the operator pasted"
check "$(grep -cE -- '--void|--relabel|SEPARATELY|--key +install_auth_enabled|install_auth_enabled +--value' <<<"$OUT")" "0" "no repair command and no command that switches the feature on is printed"
BK="$(ls -d "$SB/out"/backup-* | tail -1)"
check "$(tar -xzOf "$BK"/plugin-installed-5.18.74.tar.gz $P/manifest.json | grep -c '"version": "5.18.74"')" "1" "the code backup is 5.18.74"
check "$(tar -tzf "$BK"/plugin-installed-5.18.74.tar.gz | grep -c "$P/lib/InstallAuth.php")" "0" "…and holds no InstallAuth.php: a rollback restores 5.18.74 exactly"

echo; echo "== 3. V7, R1, R3, R5 and R6 have teeth =="
cp "$PD/tabs/customer_app/install_auth_page.php" "$SB/page.aside"
python3 - "$PD/tabs/customer_app/install_auth_page.php" <<'PY'
import sys
p = sys.argv[1]; s = open(p).read()
old = "if (!InstallAuth::enabled($iaConfig, $iaDataDir)) {"
assert s.count(old) == 1, "gate anchor not unique"
open(p, 'w').write(s.replace(old, "if (false) {"))
PY
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  V7 the customer authorisation page → 404; 'not available' ×0 · 'link is not valid' ×1 (with the switch off")" "yes" "3a the page's gate removed on the server: V7 fails — the page answers as a live page while the switch is off"
check "$(has "$OUT" 'FAIL  R1 installed files that differ: tabs/customer_app/install_auth_page.php')$(has "$OUT" 'page:no-gate')" "yesyes" "3b …and R1 names the file and R6 the missing gate"
check "$(ia_rows)" "0:0:0:0:0" "control: even the gate-less page wrote nothing for a request without a link"
cp "$SB/page.aside" "$PD/tabs/customer_app/install_auth_page.php"
check "$(sqx 'DROP TRIGGER install_auth_status_moves')" "done" "control: one trigger dropped from the database — what a PARTIAL run of 086 would leave"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R3 migration 086 is recorded but not complete: incomplete:install_auth_status_moves')" "yes" "3c R3 fails, naming the missing trigger — the ledger row alone is not trusted"
php -r '$p = new PDO("sqlite:" . $argv[1]); $sql = (string)file_get_contents($argv[2]);
  if (!preg_match("/CREATE TRIGGER IF NOT EXISTS install_auth_status_moves.*?\nEND;/s", $sql, $m)) { fwrite(STDERR, "trigger not found\n"); exit(1); }
  $p->exec($m[0]); echo "restored";' "$DATA/plugin.sqlite3" "$PD/$MIG"; echo
check "$(ia_objects)" "5:10:4" "control: the trigger restored from the installed 086"
printf '[%s] PARTIAL: 086_install_authorisation.sql — 30 ok, 1 skipped: planted\n' "$(date '+%Y-%m-%d %H:%M:%S')" >> "$DATA/migration.log"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R3 migration 086 is recorded but not complete: migration.log:PARTIAL')" "yes" "3d a PARTIAL line for 086 in migration.log: R3 fails"
sed -i '/PARTIAL: 086_install_authorisation.sql/d' "$DATA/migration.log"
printf -- '-- edited on the server\n' >> "$PD/$MIG"
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R1 installed files that differ: $MIG")$(has "$OUT" 'ledger-checksum≠installed-file')" "yesyes" "3e the installed 086 edited: R1 names it and R3's ledger check fails"
git -C "$REPO" show "$PIN:$P/$MIG" > "$PD/$MIG"
printf '\n// changed on the server\n' >> "$PD/includes/routes.php"; OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R5 files from Release A→5.18.74 differ on the server: includes/routes.php")" "yes" "3f a Release-A/regression file changed on the server: R5 fails, naming it"
git -C "$REPO" show "$PIN:$P/includes/routes.php" > "$PD/includes/routes.php"
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" '5.18.83 (after): PASSED')" "0yes" "3g each fault removed: --after-only PASSES again"

echo; echo "== 4. V3/R2 have teeth: the pilot switch is read live =="
flip --off; OUT="$(run --after-only)"
check "$(has "$OUT" 'the pilot reads off')$(has "$OUT" "ok    R2 the installed set_distributors --show reports pilot=off")$(fails "$OUT")" "yesyes0" "4a pilot turned off: V3/R2 read off, still PASSES"
flip --on; OUT="$(run --after-only)"
check "$(has "$OUT" 'the pilot reads on')$(has "$OUT" 'ok    R2 the installed set_distributors --show reports pilot=on')$(fails "$OUT")" "yesyes0" "4b pilot turned back on: V3/R2 read on again, PASSES"

echo; echo "== 5. after the operator's own activation (emulated), --after-only still PASSES and the rollback refuses =="
ia_set on
check "$(sqx "INSERT INTO install_auth_activations(activated_at, activated_by, jobs_read, exempted) VALUES (datetime('now'), 'rehearsal', 0, 0)")" "done" "control: an activation row written, as the activation step writes one"
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" '5.18.83 (after): PASSED')" "0yes" "5a --after-only PASSES with the feature live"
check "$(has "$OUT" 'ok    V3b install_auth_enabled is unchanged by this run (before=ia=on/on, after=ia=on/on; files/store) — ON in both: the operator switched the feature on')$(has "$OUT" 'note  R2 the installed InstallAuth reads the switch ON in both places (files=on/yes store=on/yes)')" "yesyes" "5b V3b and R2 report the switch ON in both copies, the operator's step"
check "$(has "$OUT" 'ok    V7 the customer authorisation page answers 404 "This link is not valid" — the switch is on, and a request without a link is refused')$(has "$OUT" 'ok    R3 migration 086 is applied and complete; its tables hold install_auth:events:activations:exempt:rate = 0:0:1:0:0')" "yesyes" "5c V7 expects the live page's answer; R3 reports the activation row"
D1="$(data_digest)"; fresh; OUT="$(run --answer ROLLBACK --rollback)"
check "$(has "$OUT" "STOP: install_auth_enabled reads 'ia=on/on' (files/store). Switch it off first")$(live)$(backups)$(data_digest)" "yes${PIN}0$D1" "5d the rollback REFUSES while the switch is on — before the typed ROLLBACK, before any backup; nothing changed"
ia_set off; ia_set on files
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  V3b the configuration files and the store row disagree about install_auth_enabled (ia=on/absent, files/store)')$(has "$OUT" 'FAIL  R2 the installed InstallAuth could not read its switch, or its two copies disagree (files=on/yes store=off/no)')" "yesyes" \
  "5e the two copies disagree (the file says on, the store row the pages read says nothing): V3b and R2 both FAIL — the tools and the pages would act differently"
check "$(has "$OUT" 'ok    V7 the customer authorisation page answers 404 "This page is not available."')" "yes" "5f …and V7 follows the store row, as public.php does: the page stays 'not available'"
ia_set off
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" 'before=ia=absent/absent, after=ia=absent/absent')$(has "$OUT" 'ok    V7 the customer authorisation page answers 404 "This page is not available."')" "0yesyes" "5g the switch cleared in both: --after-only PASSES, V7 back to 'not available'; the activation row stays as history"

echo; echo "== 6. the rollback: --rollback, typed ROLLBACK =="
D2="$(data_digest)"; fresh; OUT="$(run --answer ROLLBACK --rollback)"
check "$(fails "$OUT")$(has "$OUT" '5.18.83 (rollback): PASSED')" "0yes" "PASSED"
for l in "GO — evidence recorded" "checking out $BASE (5.18.74) for the documented deploy" "ok    container serves $BASE (5.18.74)" \
         "ok    V6 the sign-in page allows pinch-zoom" \
         "ok    RB the installed manifest says 5.18.74" \
         "ok    RB the installed plugin is 5.18.74's again: no file of 5.18.74 reaches the authorisation code — no route, no guard, no hook" \
         "ok    RB the 5.18.74 hand-off setting and \"How to pay\" are still there" \
         "ok    RB the 5.18.72 Staff Cashbooks wording is still there" \
         "ok    RB the 5.18.71 landing hero and cash-in-hand tool are still there" \
         "ok    RB the 5.18.70 Field Register fix and records tool and the 5.18.69 Manual Entry stamp are still there" \
         "ok    RB the 5.18.68 staff-cash chain and card are still there" \
         "ok    RB the 5.18.66 photo surface and the 5.18.67 card are still there" \
         "note  RB the code is 5.18.74 again" \
         "Files 5.18.83 added stay on disk, reached by nothing" \
         "rows=0:0:1:0:0"; do
  check "$(has "$OUT" "$l")" "yes" "6: ${l:0:90}"
done
check "$(has "$OUT" "ok    backed up the installed plugin (5.18.83, $PIN)")" "yes" "the backup first — of 5.18.83's code"
check "$(live)$(installed_digest "$BASE")" "${BASE}0" "the container serves $BASE: every changed file as 5.18.74 has it"
check "$(inst_ver)" "5.18.74" "the installed manifest is 5.18.74 again"
check "$(grep -cF -- "$ROUTE" "$PD/public.php")$(grep -cF -- "$HANDLERS" "$PD/includes/api_handlers.php")$(grep -cF -- "$NEVERQ" "$PD/lib/NotificationService.php")$(grep -cF -- "$HANDOFF" "$PD/lib/TenantProfile.php")$(grep -cF -- "$WORD72" "$PD/tabs/accounts/staff_cashbooks.php")$(grep -c 'var UG_PHOTOS' "$PD/tabs/support/scheduling.php")" "000111" "the installed public.php, handlers and send layer are 5.18.74's again; 5.18.74's setting, 5.18.72's wording and the photo surface intact"
check "$(test -f "$PD/lib/InstallAuth.php" && echo yes || echo no)$(test -f "$PD/$MIG" && echo yes || echo no)" "yesyes" "the files 5.18.83 added stay on disk (deploy-hybrid.sh never deletes) — reached by nothing, as RB proved"
check "$(standin_page customer_login)$(ia_objects)$(mig_row)" "2005:10:41" "after the rollback 5.18.74's store boots over the lingering 086: applied once, nothing re-run, its tables kept"
check "$(data_digest)$(ledger_rows)" "${D2}2" "the rollback changed no data — the seeded ledger still holds its two rows"

echo; echo "== 7. the lazy case: nothing has reached the plugin since the copy =="
install_base; seed_data; vault UGX; fresh; flip --on
touch "$SB/web/.no_boot"
OUT="$(run --answer DEPLOY)"
check "$(fails "$OUT")$(has "$OUT" '5.18.83 (deploy): PASSED')" "0yes" "7a the deploy PASSES"
check "$(has "$OUT" "…     R3 $MIG is installed; no request has reached the plugin since the copy, so its runner has not applied it yet — looked at again after R7")" "yes" "7b at R3 nothing has reached the plugin since the copy: 086 is not applied yet, and R3 says it will look again"
check "$(has "$OUT" "ok    R3 (after R7) migration 086 is applied and complete")$(has "$OUT" "= 0:0:0:0:0)")$(ia_objects)$(mig_row)" "yesyes5:10:41" "7c …R7's tool opened the plugin's store, the plugin's own runner applied 086, and R3's second look finds it complete and empty"
rm -f "$SB/web/.no_boot"
check "$(standin_page customer_login)" "200" "control: one request reaches the plugin"
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" 'ok    R3 migration 086 is applied and complete')$(has "$OUT" '= 0:0:0:0:0)')$(cnt "$OUT" 'R3 (after R7)')" "0yesyes0" "7d --after-only finds 086 applied at R3's first look, with no second look"

echo; echo "== 8. weakened copies of the script must be caught (control on the control) =="
mutant() {   # mutant NAME OLD NEW — a copy of the script with one check blinded
  python3 - "$DEPLOY" "$REPO/scripts/.mutant-$1.sh" "$2" "$3" <<'PY'
import sys
src, dst, old, new = sys.argv[1:5]
s = open(src).read()
assert s.count(old) == 1, "mutant anchor not unique: " + old
open(dst, 'w').write(s.replace(old, new))
PY
  echo "$REPO/scripts/.mutant-$1.sh"
}
MUT="$(mutant r1 'if [ -n "$b" ] && [ "$a" = "$b" ]; then R_OK=$((R_OK+1)); else R_BAD="$R_BAD $rel"; fi' 'R_OK=$((R_OK+1))')"
git -C "$REPO" show "$BASE:$P/lib/NotificationService.php" > "$PD/lib/NotificationService.php"
OUT="$(run --script "$MUT" --after-only)"
check "$(has "$OUT" "ok    R1 all $N_CH files 5.18.83 changes are installed exactly as $PIN has them")" "yes" "8a the R1-blinded copy calls a reverted NotificationService installed — the mutation is detected"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R1 installed files that differ: lib/NotificationService.php')$(has "$OUT" 'notify:link-may-be-queued')" "yesyes" "8b …and the real script FAILS R1 and R6 on the same fault, naming the file and the missing never-queued list"
git -C "$REPO" show "$PIN:$P/lib/NotificationService.php" > "$PD/lib/NotificationService.php"; rm -f "$MUT"
MUT="$(mutant r3 "printf '%s' \"\$tb\" | grep -q ' tables=5/5 triggers=10/10 indexes=4/4 checks=2/2 ' || r3bad=" "true || r3bad=")"
sqx 'DROP TRIGGER install_auth_never_deleted' >/dev/null
OUT="$(run --script "$MUT" --after-only)"
check "$(has "$OUT" 'ok    R3 migration 086 is applied and complete')" "yes" "8c the R3-blinded copy calls 086 complete with a trigger missing — the mutation is detected"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R3 migration 086 is recorded but not complete: incomplete:install_auth_never_deleted')" "yes" "8d …and the real script FAILS R3 on the same fault, naming the trigger"
rm -f "$MUT"

echo; echo "== 9. what the rehearsal left behind =="
check "$(checkout_state)" "$CHECKOUT0" "this checkout is as the rehearsal found it: same commit, same tracked files"
check "$(ls "$REPO/scripts"/.mutant-* "$REPO/scripts"/.unpinned.sh "$REPO/scripts"/.tip.sh "$REPO/scripts"/.stray.sh "$REPO/scripts"/.short.sh 2>/dev/null | wc -l | tr -d ' ')" "0" "no weakened copy is left in the clone"
check "$(grep -c 'Fatal\|Parse error' "$SB/web.log")" "0" "the stand-in served every request without a PHP fatal"

echo; echo "rehearsal: $PASS passed, $FAILN failed ($(cat "$SB/runs") runs of the script)"
[ "$FAILN" = "0" ]
