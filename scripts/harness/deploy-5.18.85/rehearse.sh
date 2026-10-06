#!/usr/bin/env bash
# Rehearse scripts/deploy-5.18.85.sh — the deploy, its checks, and the ROLLBACK — before the operator runs any of it.
#
# 5.18.85 over 5.18.84: the installation authorisation's request form filled from the customer's latest uCRM quotation, and
# the technician's name in the booking WhatsApp and the "installation booked" e-mail. Code only: two new files (the
# quotation reader and its test), fourteen changed (the staff API, the request form, the booking WhatsApp's class,
# webhook.php, two tests, two fixtures, the manifest, five version pins). Deployed from a RELEASE COMMIT on release/5.18.85,
# cut on the live 5.18.84 release commit (79607d4), not from the branch tip, which also carries the undeployed partner
# portal, the PD-8 CSRF guard and the AI communication layer. The base is installed AS PRODUCTION RUNS IT ON 06 OCT: 5.18.84,
# migration 086 applied by the plugin's own runner, Customer Installation Authorisation switched ON (both copies,
# activation #1 with one job exempt, its event) and the booking WhatsApp switched ON (both copies). This rehearsal proves:
#   · the pin is exactly the release: cut on 5.18.84, 16 files (2 added, 14 changed), no migration, the quotation reader,
#     the prefill's read, the form's note and transport guard and the technician's name present, and none of the
#     undeployed work;
#   · the script runs end to end against that base and PASSES — and keeps both live features exactly as they were: V3b,
#     V3c and R2 read both switches ON in both copies before and after, R3 finds 086 complete with the operator's records,
#     V7 reads the live authorisation page through the INSTALLED code, V9 the form's data behind the staff guard, R8 runs
#     the installed reader on a quotation shaped like 000181;
#   · the deploy switches nothing and writes no record: every table — 086's five included — the vault and the configuration
#     files are byte-identical;
#   · stage A refuses, before anything changes: either switch's two copies disagreeing; 086 incomplete; a pin carrying a
#     file that is not this release's; a pin lacking its quotation reader; the branch tip; a placeholder pin; a server
#     still on 5.18.83;
#   · R1/R3/R5/R6 have TEETH: the quotation read taken out of the staff API → R1 and R6 name it; the technician's name taken
#     out of webhook.php → R1 and R6; a 5.18.84 file changed → R5 names it; a trigger dropped from 086 → R3 names it;
#   · after the operator turns the booking WhatsApp off (emulated as set_config.php writes it: both copies), --after-only
#     still PASSES and says so; with its two copies made to disagree, V3c and R2 fail;
#   · the rollback returns the plugin to 5.18.84 — manifest 5.18.84, nothing reaching the quotation reader or the new name
#     lookup, the booking WhatsApp and the authorisation whole and still ON, 086 complete, every earlier release's code
#     intact — and changes no data;
#   · weakened copies of the script (R1 blinded; R6's quotation-read check blinded) are caught — the control on the control;
#   · the clone is left as found.
#
# Everything runs in a sandbox: a fake `docker` that maps /data/ucrm to a directory here, a clone of this checkout, the plugin
# installed as 5.18.84 exactly (git archive of the release commit), and a stand-in web server for stage V's pages. DEPLOY and
# ROLLBACK are typed through a pseudo-terminal, as the operator types them. It never touches this checkout, the server or the
# network, and refuses to run where the server could be.
#
#   bash scripts/harness/deploy-5.18.85/rehearse.sh            REHEARSE_KEEP=<dir> keeps every run's full output
set -u
R="$(cd "$(dirname "$0")/../../.." && pwd)"
for f in /data/ucrm /opt/dishnet /var/run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
BASE=79607d4; RA_BASE=e076632; OLDER=2de810c; BRANCH_FILE=scripts/deploy-5.18.85.sh; P=dishnet-hybrid-sudan; RELEASE_BRANCH=release/5.18.85
MIG=migrations/086_install_authorisation.sql
QP_LIB=lib/QuotationPrefill.php                                                                         # 5.18.85's quotation reader
QP_PICK='public static function pick($quotes, int $clientId): ?array'                                  # the latest, this client's
QP_READ="\$iaQList = \$iaQCrm->get('billing/quotes?' . http_build_query(['clientId' => \$cid]));"      # the prefill's one read
FORM_NOTE='Filled from quotation <b>'                                                                    # the form names it
TGUARD="if(window._iaTransportAsk && v('iaTransport')===''){"                                           # transport asked for
TECHFN='public static function technicianName(int $ucrmUserId, $store, $crm): string'                    # the name lookup
TECHCALL="\$_whTech = \$_whJobNotifier ? whInstallTechName(\$assignedUserId, \$store, \$crm) : '';"      # job.add uses it
WA_LIB=lib/InstallScheduledWhatsApp.php                                                                  # 5.18.84's — live, must survive
WA_FLAG="public const FLAG  = 'customer_wa_install_scheduled';"
WA_CLAIM='        if (!CustomerEmailDispatcher::claimOnce($pdo, self::claimKey($jobId))) {'
WA_CALL='            whInstallScheduledWhatsApp($jobId, is_array($job) ? $job : [], is_array($client ?? null) ? $client : [],'
WA_KEYLINE="'customer_wa_install_scheduled' => ['bool',"
RULE='public static function decision(\PDO $pdo, array $config, array $job): array'   # 5.18.83's one rule — live, must survive
ROUTE="if (\$page === 'install_auth') {"                                            # 5.18.83's customer page route
G_OUT="\$_iaJob, 2, 'checkout', (int)(\$me2['id'] ?? 0));"                           # 5.18.83's check-out guard
NEVERQ="public const NEVER_QUEUED = ['app_otp', 'ops_install_auth_request'];"        # 5.18.83's never-queued link
HANDOFF='public function dataReportHandoff(): bool'   # 5.18.74's tenant setting — must survive this release and its rollback
PAY='>How to pay</div>'                            # 5.18.74's "How to pay" — must survive too
WORD72="'Still to account for'"                   # 5.18.72's Staff Cashbooks wording — must survive too
HERO71='Cash in hand — per currency'          # 5.18.71's landing hero — must survive too
CIHTOOL='== cash in hand (5.18.71)'            # 5.18.71's read-only tool, run in R7 — must survive too
PREVMARK='var _fr3Base = <?= json_encode(dn_book_base($config)) ?>;'   # 5.18.70's Field Register token — must survive too
PREV69="'currency'        => \$manCur,"             # 5.18.69's Manual Entry stamp — must survive too
CHAINMARK='CASH IN HAND</div>'                 # 5.18.68's Cashbook card — must survive too
EXPECT_N=16; EXPECT_AD=2                # files in the release delta (set when the release commit was cut)
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
check "$(git -C "$REPO" rev-parse --short "$PIN^" 2>/dev/null)" "$BASE" "control: the release commit is cut on $BASE (5.18.84), the live version"
ver() { git -C "$REPO" show "$1:$P/manifest.json" | python3 -c 'import json,sys; print(json.load(sys.stdin)["information"]["version"])'; }
check "$(ver "$PIN")" "5.18.85" "the plugin at the pin is 5.18.85"
check "$(ver "$BASE")" "5.18.84" "control: the base is 5.18.84"
dl() { git -C "$REPO" diff --no-renames --name-only --diff-filter="$1" "$2" "$3" -- $P; }
CHANGED="$(dl AM "$BASE" "$PIN")"
N_CH="$(grep -c . <<<"$CHANGED")"; N_AD="$(dl A "$BASE" "$PIN" | grep -c .)"; N_MO=$((N_CH - N_AD)); N_DEL="$(dl D "$BASE" "$PIN" | grep -c .)"
echo "  5.18.85   $N_CH files: $N_MO changed, $N_AD added · $N_DEL removed or renamed"
check "$N_DEL" "0" "control: 5.18.84 → 5.18.85 removes no file"
check "$N_AD" "$EXPECT_AD" "control: 5.18.85 adds exactly $EXPECT_AD files"
check "$N_CH" "$EXPECT_N" "control: $EXPECT_N files in the delta"
for want in "$QP_LIB" "tests/test_install_quote_prefill.php" "includes/api/api_install_auth.php" "tabs/support/scheduling.php" "$WA_LIB" "webhook.php" \
            "tests/test_install_scheduled_whatsapp.php" "tests/test_job_notifications_day.php" "tests/fixtures/fake_ucrm_staff_jobs.php" \
            "tests/fixtures/staff_jobs_sandbox.php" "manifest.json" "tests/test_distributor_registry.php" "tests/test_distributor_apply.php" \
            "tests/test_distributor_territory.php" "tests/test_distributor_notify.php" "tests/test_distributor_link_ucrm.php"; do
  check "$(grep -c "^$P/$want$" <<<"$CHANGED")" "1" "control: the delta includes $want"
done
check "$(grep -cE "^$P/(partner_api\.php|lib/Partner|lib/StaffApiCsrf\.php|lib/Totp\.php|lib/DistributorPortalData\.php|lib/Media|lib/InboundMedia|lib/Voice|lib/Image|lib/Document|lib/Pdf|migrations/|tools/)" <<<"$CHANGED")" "0" \
  "control: no migration, no tool and no partner-portal, CSRF or AI-layer file in the delta"
at() { git -C "$REPO" show "$1:$P/$2" 2>/dev/null | grep -cF -- "$3"; }
check "$(at "$PIN" "$QP_LIB" "$QP_PICK")$(at "$PIN" includes/api/api_install_auth.php "$QP_READ")$(at "$PIN" tabs/support/scheduling.php "$FORM_NOTE")$(at "$PIN" tabs/support/scheduling.php "$TGUARD")$(at "$PIN" "$WA_LIB" "$TECHFN")$(at "$PIN" webhook.php "$TECHCALL")" "111111" \
  "control: at the pin the quotation reader, the prefill's read, the form's note and transport guard, the name lookup and its use in job.add are there"
check "$(at "$BASE" includes/api/api_install_auth.php "$QP_READ")$(at "$BASE" tabs/support/scheduling.php "$FORM_NOTE")$(at "$BASE" webhook.php "$TECHCALL")$(git -C "$REPO" cat-file -e "$BASE:$P/$QP_LIB" 2>/dev/null && echo yes || echo no)" "000no" "control: 5.18.84 has none of them"
check "$(at "$PIN" "$WA_LIB" "$WA_FLAG")$(at "$PIN" "$WA_LIB" "$WA_CLAIM")$(at "$PIN" webhook.php "$WA_CALL")$(at "$PIN" tools/set_config.php "$WA_KEYLINE")" "1111" "control: 5.18.84's booking WhatsApp is whole at the pin: its switch, claim, call and setting"
check "$(at "$PIN" lib/InstallAuth.php "$RULE")$(at "$PIN" public.php "$ROUTE")$(at "$PIN" includes/api/api_field_ops.php "$G_OUT")$(at "$PIN" lib/NotificationService.php "$NEVERQ")" "1111" "control: 5.18.83's authorisation is whole at the pin"
check "$(at "$PIN" lib/TenantProfile.php "$HANDOFF")$(at "$PIN" tabs/customer_app/portal.php "$PAY")$(at "$PIN" tabs/accounts/staff_cashbooks.php "$WORD72")$(at "$PIN" tabs/accounts/accounts_dashboard.php "$HERO71")$(at "$PIN" tools/cash_in_hand.php "$CIHTOOL")" "11111" "control: 5.18.74's hand-off and How-to-pay, 5.18.72's wording, 5.18.71's hero and tool at the pin"
check "$(at "$PIN" tabs/sales/wallet.php "$PREVMARK")$(at "$PIN" tabs/accounts/staff_cashbooks.php "$PREV69")$(at "$PIN" tabs/accounts/cashbook.php "$CHAINMARK")$(at "$PIN" lib/StaffCashPositionService.php 'dn_book_base(null)')" "1111" "control: 5.18.70's, 5.18.69's and 5.18.68's markers at the pin"
check "$(at "$PIN" webhook.php 'DistributorEvents::maybeNotify')$(at "$PIN" lib/DistributorNotifier.php 'new NullWhatsAppChannel')" "21" "control: webhook.php still draws the two draft hooks and the notifier still binds the Null channel"
HEADER_CMD="$(sed -n '/^# Run as root/,/^# The rollback is a separate command/p' "$DEPLOY")"
check "$(grep -c 'deploy-5.18.85.sh 2>&1 | tee' <<<"$HEADER_CMD")$(grep -cE -- '--rollback|--void|--relabel|git checkout [0-9a-f]{7}' <<<"$HEADER_CMD")" "10" \
  "the header's deploy command stands alone: no rollback, no --void/--relabel and no checkout of another commit in its block (docs/44 §16.9)"
check "$(grep -c "git fetch origin $RELEASE_BRANCH" <<<"$HEADER_CMD")" "1" "the header's deploy command fetches the release branch"
check "$(grep -cE -- '--key +(customer_wa_install_scheduled|install_auth_enabled)|(customer_wa_install_scheduled|install_auth_enabled) +--value' "$DEPLOY")" "0" "the script carries no command that switches either feature — neither on nor off"

# ── The container: 5.18.84 installed AS PRODUCTION RUNS IT, its data beside it ─
MOUNT="$SB/mount"; PLUGINS="$MOUNT/ucrm/data/plugins"; PD="$PLUGINS/$P"; DATA="$PLUGINS/.$P-data"
VAULT="$PLUGINS/.dishnet-sudan.vault.json"
mkdir -p "$PD" "$DATA" "$SB/web" "$SB/bin" "$SB/out"
IN_CONTAINER="/data/ucrm/data/plugins/$P"
free_port() { python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()'; }
WPORT="$(free_port)"; PLUGIN_BASE="http://127.0.0.1:$WPORT/public.php"
printf '%s\n%s\n' "$PD" "$DATA" > "$SB/web/.installed_plugin"   # the stand-in reads the INSTALLED plugin, so V6 and V7 read real code
# The stand-in public.php. On every request it boots the INSTALLED plugin's store, as the real public.php does.
# ?page=install_auth runs the INSTALLED page itself, with public.php's own $config — the store row; the sign-in page carries
# the installed viewport line.
cat > "$SB/web/public.php" <<'PHP'
<?php
[$root, $dataDir] = array_map('trim', array_slice(explode("\n", (string)@file_get_contents(__DIR__ . '/.installed_plugin')), 0, 2));
foreach (['bootstrap_data', 'StoreInterface', 'JsonStore', 'SqliteStore'] as $l) require_once $root . "/lib/$l.php";
$store = SqliteStore::create($dataDir);
$page = $_GET['page'] ?? '';
if ($page === 'install_auth' && strpos((string)@file_get_contents($root . '/public.php'), "if (\$page === 'install_auth') {") !== false) {
    $config = $store->load('kyc_config.json');   // public.php's own source of $config: the store row
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

install_base() {   # the installed plugin exactly as 5.18.84
  find "$PD" -mindepth 1 -maxdepth 1 ! -name ucrm.json -exec rm -rf {} +
  git -C "$REPO" archive "$BASE:$P" | tar -x -C "$PD"
  printf '%s\n' "$BASE" > "$PD/.deployed-commit"
}
printf '{"pluginDataDir":"/data/ucrm/data/plugins/.%s-data","ucrmPublicUrl":"http://127.0.0.1:1/crm"}' "$P" > "$PD/ucrm.json"
vault() { printf '{"config":{"currency_code":"%s"}}' "$1" > "$VAULT"; }
sq() { php -r '$p = new PDO("sqlite:" . $argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $r = $p->query($argv[2]); echo $r ? implode(",", $r->fetchAll(PDO::FETCH_COLUMN)) : "";' "$DATA/plugin.sqlite3" "$1" 2>/dev/null || echo err; }
sqx() { php -r '$p = new PDO("sqlite:" . $argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $p->exec($argv[2]); echo "done";' "$DATA/plugin.sqlite3" "$1" 2>&1; }
cfg_set() {   # cfg_set KEY on|off [files]: a switch as set_config.php writes it — the override file AND the store row public.php reads
              # ("files": the file only — the two copies disagree); off removes the key from both
  php -r '
    $key = $argv[1]; $mode = $argv[2]; $data = $argv[3]; $only = $argv[4] ?? "";
    $file = $data . "/kyc_config.json";
    $p = new PDO("sqlite:" . $data . "/plugin.sqlite3"); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $f = json_decode((string)file_get_contents($file), true); $r = json_decode((string)$p->query("SELECT data FROM kyc_config WHERE id = 0")->fetchColumn(), true);
    if ($mode === "on") { $f[$key] = "1"; if ($only !== "files") $r[$key] = "1"; }
    else { unset($f[$key]); unset($r[$key]); }
    file_put_contents($file, json_encode($f)); $p->prepare("UPDATE kyc_config SET data = ? WHERE id = 0")->execute([json_encode($r)]);
    echo "done";
  ' "$1" "$2" "$DATA" "${3:-}" >/dev/null
}
seed_data() {      # the plugin's databases, written by 5.18.84's own store (084 and 086 applied by its runner); then production's
                   # 06 Oct state: authorisation switched on in both copies, activation #1 with job 20 exempt and its event, and the
                   # booking WhatsApp switched on in both copies
  rm -rf "$DATA"/plugin.sqlite3* "$DATA"/dishnet.sqlite* "$DATA"/config.json "$DATA"/kyc_config.json "$DATA"/migration.log "$DATA"/uploads "$DATA"/webhook_log.json
  php -r '
    foreach (["bootstrap_data", "StoreInterface", "JsonStore", "SqliteStore"] as $l) require_once $argv[1] . "/lib/$l.php";
    require_once $argv[1] . "/lib/CashbookService.php";
    $s = SqliteStore::create($argv[2]);
    $s->save("kyc_config.json", ["crm_base_url" => "http://127.0.0.1:1", "company_name" => "DishNet Sandbox", "currency_code" => "UGX",
        "tenant_profile" => "uganda", "cashbook_base_currency" => "UGX", "cashbook_currencies" => "UGX,USD", "currency_symbol" => "UGX",
        "install_auth_job_titles" => "Starlink Installation", "install_auth_whatsapp" => "1"]);
    file_put_contents($argv[2] . "/kyc_config.json", json_encode($s->load("kyc_config.json")));
    $s->appendWithId("retailers.json", ["name" => "Rehearsal Tech", "email" => "tech@example.test", "role" => "support", "is_active" => true]);
    $cb = new CashbookService($s, $argv[2]);
    $cb->addEntryRaw(["project" => "dishnet", "date" => date("Y-m-d"), "direction" => "in", "amount" => 1000000, "currency" => "UGX",
        "category" => "Receipt", "category_raw" => "Receipt", "person" => "", "description" => "opening float", "status" => "approved", "source" => "manual"]);
    $cb->addEntryRaw(["project" => "dishnet", "date" => date("Y-m-d"), "direction" => "out", "amount" => 150000, "currency" => "UGX",
        "category" => "Staff Advance", "category_raw" => "Staff Advance", "person" => "Rehearsal Tech", "description" => "rehearsal", "status" => "approved", "source" => "manual"]);
  ' "$PD" "$DATA" >/dev/null
  cfg_set install_auth_enabled on
  cfg_set customer_wa_install_scheduled on
  sqx "INSERT INTO install_auth_activations(activated_at, activated_by, jobs_read, exempted) VALUES ('2026-10-06 11:50:00', 'tools/set_config.php as root', 1, 1)" >/dev/null
  sqx "INSERT INTO install_auth_exempt(job_id, activation_id, crm_client_id, title, recorded_at) VALUES (20, 1, 0, 'Starlink Installation — Rehearsal Customer', '2026-10-06 11:50:00')" >/dev/null
  sqx "INSERT INTO install_auth_events(job_id, event, actor_kind, actor_id, detail, created_at) VALUES (20, 'INSTALLATION_EXEMPTED', 'system', NULL, 'activation:1;status:1', '2026-10-06 11:50:00')" >/dev/null
  php -r 'file_put_contents($argv[1], json_encode([
      ["id" => 2, "event" => "job.add", "message" => "Received UCRM webhook: job.add", "data" => [], "received_at" => "2026-10-06 15:02:11", "ip" => ""],
      ["id" => 1, "event" => "job.add", "message" => "Received UCRM webhook: job.add", "data" => [], "received_at" => "2026-10-06 14:40:03", "ip" => ""]]));' "$DATA/webhook_log.json"
  php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec("PRAGMA journal_mode=WAL"); $p->exec("CREATE TABLE wa_messages(id INTEGER PRIMARY KEY, body TEXT)");
    for ($i = 0; $i < 10; $i++) $p->exec("INSERT INTO wa_messages(body) VALUES (\x27hello $i\x27)");' "$DATA/dishnet.sqlite"
}
inst_ver() { grep -o '"version": *"5[^"]*"' "$PD/manifest.json" | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/'; }
live() { tail -n1 "$PD/.deployed-commit" 2>/dev/null | tr -cd '0-9a-f'; }
backups() { ls -d "$SB/out"/backup-* 2>/dev/null | wc -l | tr -d ' '; }
dist_tables() { sq "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('dist_partners','dist_appointment','dist_regions','dist_territory_map','dist_customer_links','dist_contacts','dist_notify_consent','dist_notify_log')"; }
ledger_rows() { sq "SELECT COUNT(*) FROM cb_ledger WHERE currency='UGX' AND status='approved'"; }
photo_tables() { sq "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('job_photos','job_completion_gps')"; }
ia_objects() { sq "SELECT (SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('install_auth','install_auth_events','install_auth_activations','install_auth_exempt','install_auth_rate')) || ':' || (SELECT COUNT(*) FROM sqlite_master WHERE type='trigger' AND name LIKE 'install_auth%') || ':' || (SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name IN ('idx_install_auth_client','idx_install_auth_status','idx_install_auth_events_job','idx_install_auth_rate'))"; }
ia_rows() { sq "SELECT (SELECT COUNT(*) FROM install_auth) || ':' || (SELECT COUNT(*) FROM install_auth_events) || ':' || (SELECT COUNT(*) FROM install_auth_activations) || ':' || (SELECT COUNT(*) FROM install_auth_exempt) || ':' || (SELECT COUNT(*) FROM install_auth_rate)"; }
mig_row() { sq "SELECT COUNT(*) FROM _migrations WHERE filename = '086_install_authorisation.sql'"; }
_canon() { php -r 'function c($x){ if (is_array($x)) { ksort($x); foreach ($x as &$v) $v = c($v); } return $x; }
  echo json_encode(c(json_decode((string)@file_get_contents($argv[1]), true)));' "$1" 2>/dev/null; }
data_dump() {   # every table's rows but the ledger of migrations — 086's five included; the vault; the configuration files
  php -r '$p = new PDO("sqlite:" . $argv[1]); $skip = ["_migrations", "sqlite_sequence"];
      foreach ($p->query("SELECT name FROM sqlite_master WHERE type = \x27table\x27 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if (in_array($t, $skip, true)) continue;
        echo $t, ":", json_encode($p->query("SELECT * FROM [$t]")->fetchAll(PDO::FETCH_NUM)), "\n"; }' "$DATA/plugin.sqlite3"
  echo "vault:$(_canon "$VAULT")"
  [ -f "$DATA/kyc_config.json" ] && echo "kyc:$(_canon "$DATA/kyc_config.json")"
  [ -f "$DATA/config.json" ] && echo "config:$(_canon "$DATA/config.json")"
  true
}
data_digest() { data_dump | sha256sum | cut -c1-16; }
installed_digest() {   # $1 a commit: the number of files 5.18.85 touches that are NOT installed as that commit has them
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
pinned_copy() {   # pinned_copy NAME COMMIT — a copy of the script pinned to another commit
  sed "s/^EXPECTED_PLUGIN_COMMIT=\"[^\"]*\"/EXPECTED_PLUGIN_COMMIT=\"$2\"/" "$DEPLOY" > "$REPO/scripts/.$1.sh"; echo "$REPO/scripts/.$1.sh"
}
release_variant() {   # release_variant add|drop PATH — a commit cut on 5.18.84 holding the release's tree with one file planted or removed
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
check "$(inst_ver)" "5.18.84" "control: the installed base is 5.18.84"
check "$(grep -cF -- "$QP_READ" "$PD/includes/api/api_install_auth.php")$(grep -cF -- "$TECHCALL" "$PD/webhook.php")$(test -f "$PD/$QP_LIB" && echo yes || echo no)" "00no" "control: the base has no quotation read, no name lookup in job.add and no quotation reader"
check "$(grep -cF -- "$WA_FLAG" "$PD/$WA_LIB")$(grep -cF -- "$WA_CLAIM" "$PD/$WA_LIB")$(grep -cF -- "$WA_CALL" "$PD/webhook.php")$(grep -cF -- "$WA_KEYLINE" "$PD/tools/set_config.php")" "1111" "control: the base has 5.18.84's booking WhatsApp: the switch, the claim, the call, the setting"
check "$(grep -cF -- "$RULE" "$PD/lib/InstallAuth.php")$(grep -cF -- "$ROUTE" "$PD/public.php")$(grep -cF -- "$G_OUT" "$PD/includes/api/api_field_ops.php")$(grep -cF -- "$NEVERQ" "$PD/lib/NotificationService.php")" "1111" "control: the base has 5.18.83's authorisation: the rule, the route, the check-out guard, the never-queued link"
check "$(grep -cF -- "$HANDOFF" "$PD/lib/TenantProfile.php")$(grep -cF -- "$PAY" "$PD/tabs/customer_app/portal.php")$(grep -cF -- "$WORD72" "$PD/tabs/accounts/staff_cashbooks.php")$(grep -cF -- "$HERO71" "$PD/tabs/accounts/accounts_dashboard.php")$(grep -cF -- "$CIHTOOL" "$PD/tools/cash_in_hand.php" 2>/dev/null)" "11111" "control: the base has 5.18.74's hand-off setting and How-to-pay, 5.18.72's wording, 5.18.71's hero and tool"
check "$(ledger_rows)" "2" "control: the seeded UGX book holds its two approved rows (a receipt of 1,000,000 and a Staff Advance of 150,000)"
check "$(dist_tables)$(photo_tables)" "82" "control: the base plugin.sqlite3 has the 8 distributor tables and 084's two photo tables"
check "$(ia_objects)$(mig_row)$(ia_rows)" "5:10:410:1:1:1:0" "control: 086 applied by the plugin's own runner, complete, holding production's 06 Oct records: activation #1, job 20 exempt, its event"
both_copies() { echo "$(sq "SELECT json_extract(data, '$.$1') FROM kyc_config WHERE id = 0")$(php -r 'echo json_decode((string)file_get_contents($argv[1]), true)[$argv[2]] ?? "-";' "$DATA/kyc_config.json" "$1")"; }
check "$(both_copies install_auth_enabled)$(both_copies customer_wa_install_scheduled)" "1111" "control: install_auth_enabled and customer_wa_install_scheduled are ON in both copies, as the operator switched them on"
check "$(env PATH="$SB/bin:$PATH" DN_DATA_DIR="$DATA" bash "$SB/bin/docker" exec ucrm php "$IN_CONTAINER/tools/set_distributors.php" --show 2>/dev/null | awk '/distributors_enabled/ {print toupper($2); exit}')" "ON" "control: the pilot is ON on the base"
check "$(standin_page customer_login)$(standin_page install_auth)$(data_digest)" "200404$D0" "control: the stand-in boots the installed store, the live authorisation page refuses a request without a link, and neither changes any data"

echo; echo "== 1. NO-GO before anything changes =="
printf '%s\n' "$OLDER" > "$PD/.deployed-commit"; fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: the container serves $OLDER; 5.18.85 was built and tested against $BASE (5.18.84) — deploy 5.18.84 first (scripts/deploy-5.18.84.sh) and send its log")" "yes" \
  "1a the server still runs 5.18.83: NO-GO — 5.18.84 goes first"
check "$(live)$(backups)" "${OLDER}0" "…nothing deployed, no backup taken"
printf '%s\n' "$BASE" > "$PD/.deployed-commit"
S="$(pinned_copy unpinned __PLUGIN_COMMIT__)"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
check "$(has "$OUT" 'STOP: this copy of the script is not pinned to a reviewed commit')$(live)$(backups)" "yes${BASE}0" \
  "1b a copy still carrying the placeholder pin: stops before anything is read"
S="$(pinned_copy tip "$TIP")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
check "$(has "$OUT" "STOP: $TIP is not cut on $BASE (5.18.84): its parent is")$(has "$OUT" 'this script is for another build')" "yesyes" \
  "1c a copy pinned to the branch tip ($TIP, parent not 5.18.84): refused — the undeployed work cannot ride along"
check "$(live)$(backups)$(cnt "$OUT" 'serves ')" "${BASE}00" "…before the container was even looked at: nothing deployed, no backup, no live read"
V_ADD="$(release_variant add lib/MediaPolicy.php)"; S="$(pinned_copy stray "$V_ADD")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
check "$(has "$OUT" "STOP: the pin carries files that are not 5.18.85's, which this release must not ship: lib/MediaPolicy.php(added)")$(live)$(backups)" "yes${BASE}0" \
  "1d a commit cut on 5.18.84 carrying the release AND an AI-layer file: refused by the delta's allow-list, naming the file"
V_DROP="$(release_variant drop "$QP_LIB")"; S="$(pinned_copy short "$V_DROP")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
check "$(has "$OUT" "STOP: the pin lacks files 5.18.85 is made of: $QP_LIB(added)")$(live)$(backups)" "yes${BASE}0" \
  "1e a commit cut on 5.18.84 carrying the release WITHOUT the quotation reader: refused, naming it"
cfg_set customer_wa_install_scheduled off; cfg_set customer_wa_install_scheduled on files; fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: customer_wa_install_scheduled reads 'wa=on/absent' (files/store) — this script must find it readable, its two copies agreeing")$(live)$(backups)" "yes${BASE}0" \
  "1f the booking WhatsApp's two copies disagree (the files on, the store row the webhook reads first silent): NO-GO — nothing deployed, no backup"
cfg_set customer_wa_install_scheduled on
php -r '$f = json_decode((string)file_get_contents($argv[1]), true); unset($f["install_auth_enabled"]); file_put_contents($argv[1], json_encode($f));' "$DATA/kyc_config.json"
fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: install_auth_enabled reads 'ia=absent/on' (files/store) — this script must find it readable, its two copies agreeing")$(live)$(backups)" "yes${BASE}0" \
  "1g the authorisation's two copies disagree (the store row on, the files silent): NO-GO — a deploy would leave the tools and the pages acting differently"
cfg_set install_auth_enabled on
check "$(sqx 'DROP TRIGGER install_auth_status_moves')" "done" "control: one trigger dropped from 086 — what a PARTIAL run would leave"
fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: migration 086 (5.18.83's) is recorded but not complete on this server")$(live)$(backups)" "yes${BASE}0" \
  "1h 086 incomplete on the live server: NO-GO — the authorisation depends on it; nothing deployed"
php -r '$p = new PDO("sqlite:" . $argv[1]); $sql = (string)file_get_contents($argv[2]);
  if (!preg_match("/CREATE TRIGGER IF NOT EXISTS install_auth_status_moves.*?\nEND;/s", $sql, $m)) { fwrite(STDERR, "trigger not found\n"); exit(1); }
  $p->exec($m[0]); echo "restored";' "$DATA/plugin.sqlite3" "$PD/$MIG" >/dev/null
check "$(ia_objects)$(data_digest)" "5:10:4$D0" "control: the trigger restored, both switches back as production has them — the data is as seeded"

echo; echo "== 2. the deploy, as the operator runs it =="
fresh; OUT="$(run --answer DEPLOY)"
check "$(fails "$OUT")" "0" "no FAIL line"
check "$(has "$OUT" '5.18.85 (deploy): PASSED')" "yes" "PASSED"
for l in "ok    A0 the release delta is exactly 5.18.85's $N_CH files — $N_AD added, $N_MO changed, no migration; no partner-portal, CSRF or AI-layer file" \
         "ok    A2 PHP" \
         "authorisation   ia=on/on" \
         "booking WA      wa=on/on   (customer_wa_install_scheduled in the configuration files / the store row; the operator's setting — this run never changes it)" \
         "086 state       mig=applied:" \
         "rows=0:1:1:1:0 missing=-" \
         "job.add         2 received among the webhook log's last 300 entries, the last at 2026-10-06 15:02:11" \
         "— one consistent copy, integrity ok" "ok    backed up the installed plugin (5.18.84, $BASE)" "ok    backed up the configuration vault" \
         "GO — evidence recorded" \
         "checking out $PIN (5.18.85, the release commit) for the documented deploy" \
         "ok    container serves $PIN" \
         "ok    V1 the sign-in page on the public address answers 200 with zero redirects (no loop)" \
         "ok    V1 the portal without a session still refuses" \
         "ok    V2 the sign-in page answers 200 and carries no South Sudan contact" \
         "ok    V6 the sign-in page allows pinch-zoom" \
         "ok    V5 the photo viewer without a session refuses (302) — a photo is never served to nobody" \
         "ok    V5 job_photo_upload without a login answers 401 — the staff guard, before any handler" \
         "ok    V7 the customer authorisation page answers 404 \"This link is not valid\" — the switch is on, and a request without a link is refused" \
         "ok    V8 install_auth_request without a login answers 401 — the staff guard, before any handler" \
         "ok    V9 install_auth_prefill without a login answers 401 — the staff guard, before a quotation is read" \
         "ok    V3 the pilot switch is unchanged by the deploy (before=pilot=on, after=pilot=on)" \
         "ok    V3b install_auth_enabled is unchanged by this run (before=ia=on/on, after=ia=on/on; files/store) — ON in both: Customer Installation Authorisation stays live, as the operator left it" \
         "ok    V3c customer_wa_install_scheduled is unchanged by this run (before=wa=on/on, after=wa=on/on; files/store) — ON in both: the booking WhatsApp stays live, as the operator left it" \
         "ok    V4 no fatal or parse error of $P in the container log since" \
         "ok    R1 all $N_CH files 5.18.85 changes are installed exactly as $PIN has them ($N_AD new)" \
         "ok    R1 the installed manifest says 5.18.85" \
         "ok    R2 the installed set_distributors --show reports pilot=on" \
         "ok    R2 the installed InstallAuth reads install_auth_enabled ON in both places and enabled() is true — Customer Installation Authorisation stays live, as the operator left it" \
         "ok    R2 the installed InstallScheduledWhatsApp reads customer_wa_install_scheduled ON in both places and enabled() is true — the booking WhatsApp stays live, as the operator left it" \
         "ok    R3 migration 086 (5.18.83's) is still applied and complete — 5 tables, 10 triggers, 4 indexes, both CHECKs, the ledger row matching the installed file; its tables hold install_auth:events:activations:exempt:rate = 0:1:1:1:0" \
         "ok    R3 084 is still installed and its two tables exist on the live plugin.sqlite3" \
         "ok    R4 the pilot libs + the Distributors tab are installed as before" \
         "no partner-portal, CSRF or AI-layer file is installed" \
         "ok    R5 all" \
         "ok    R6 every earlier surface is installed as before" \
         "and 5.18.85's pieces are in place: the quotation reader, the prefill's read of uCRM's quotations, the form's note and transport guard, the technician's name" \
         "ok    R7 cash in hand on the live book, read-only — what the landing hero shows: UGX CASH IN HAND 850,000.00 · USD CASH IN HAND 0.00" \
         "ok    R7 the photo tables and files read exactly as before the deploy" \
         "ok    R8 the installed quotation reader reads a quotation shaped like 000181 as the form will: the kit, the plan, installation 150000, transport asked for — nothing read from uCRM, nothing written" \
         "the switches      install_auth_enabled on/on, customer_wa_install_scheduled on/on (files/store) — as the operator left them (V3b, V3c, R2); 5.18.85 adds none" \
         "migrations        none (086, 5.18.83's, still complete: R3)"; do
  check "$(has "$OUT" "$l")" "yes" "2: ${l:0:96}"
done
check "$(cnt "$OUT" 'ok    backed up ')" "5" "five backups: two databases, the data directory, the installed plugin, the vault"
check "$(cnt "$OUT" '  note  ')" "0" "no note: both switches read as expected, nothing to say"
check "$(live)$(installed_digest "$PIN")" "${PIN}0" "the container serves $PIN: every changed file exactly as the commit has it"
check "$(inst_ver)" "5.18.85" "the installed manifest is 5.18.85"
check "$(grep -cF -- "$QP_PICK" "$PD/$QP_LIB")$(grep -cF -- "$QP_READ" "$PD/includes/api/api_install_auth.php")$(grep -cF -- "$FORM_NOTE" "$PD/tabs/support/scheduling.php")$(grep -cF -- "$TGUARD" "$PD/tabs/support/scheduling.php")$(grep -cF -- "$TECHFN" "$PD/$WA_LIB")$(grep -cF -- "$TECHCALL" "$PD/webhook.php")" "111111" \
  "the installed plugin carries the quotation reader, the prefill's read, the form's note and transport guard, the name lookup and its use in job.add"
check "$(grep -cF -- "$WA_CALL" "$PD/webhook.php")$(grep -cF -- "$WA_CLAIM" "$PD/$WA_LIB")$(grep -cF -- "$WA_KEYLINE" "$PD/tools/set_config.php")$(grep -cF -- "$RULE" "$PD/lib/InstallAuth.php")$(grep -cF -- "$ROUTE" "$PD/public.php")$(grep -cF -- "$G_OUT" "$PD/includes/api/api_field_ops.php")$(grep -cF -- "$NEVERQ" "$PD/lib/NotificationService.php")" "1111111" \
  "…and still 5.18.84's booking WhatsApp and 5.18.83's authorisation, whole"
check "$(for f in partner_api.php lib/StaffApiCsrf.php lib/MediaPolicy.php lib/InboundMedia.php migrations/085_wa_media.sql; do test -f "$PD/$f" && echo "$f"; done | wc -l | tr -d ' ')" "0" "no partner-portal, CSRF or AI-layer file installed"
check "$(ia_objects)$(mig_row)$(ia_rows)" "5:10:410:1:1:1:0" "086 is as it was: complete, one ledger row, production's records"
check "$(data_digest)" "$D0" "the deploy wrote no record and no configuration value — every table (086's included), the vault and the configuration files as before"
check "$(grep -c "cd $REPO && bash scripts/deploy-5.18.85.sh --rollback" <<<"$OUT")" "1" "the rollback command is printed once, on its own line, never beside the deploy"
check "$(awk '/PASSED\. Send this LOG FILE back/ {p=1} p && /deploy-5\.18\.85\.sh --rollback/ {print "after"; exit}' <<<"$OUT")" "after" \
  "…the rollback block comes after the verdict, at the end of the log, never in the block the operator pasted"
check "$(grep -cE -- '--void|--relabel|SEPARATELY|--key +(customer_wa_install_scheduled|install_auth_enabled)|(customer_wa_install_scheduled|install_auth_enabled) +--value' <<<"$OUT")" "0" "no repair command and no switch command anywhere in the log"
BK="$(ls -d "$SB/out"/backup-* | tail -1)"
check "$(tar -xzOf "$BK"/plugin-installed-5.18.84.tar.gz $P/manifest.json | grep -c '"version": "5.18.84"')" "1" "the code backup is 5.18.84"
check "$(tar -tzf "$BK"/plugin-installed-5.18.84.tar.gz | grep -c "$P/$QP_LIB")" "0" "…and holds no quotation reader: a rollback restores 5.18.84 exactly"

echo; echo "== 3. R1, R3, R5 and R6 have teeth =="
cp "$PD/includes/api/api_install_auth.php" "$SB/api.aside"
python3 - "$PD/includes/api/api_install_auth.php" "$QP_READ" <<'PY'
import sys
p, old = sys.argv[1], sys.argv[2]; s = open(p).read()
assert s.count(old) == 1, "quotation-read anchor not unique"
open(p, 'w').write(s.replace(old, "$iaQList = null;"))
PY
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R1 installed files that differ: includes/api/api_install_auth.php')$(has "$OUT" 'prefill:no-quotation-read')" "yesyes" "3a the quotation read taken out of the staff API on the server: R1 names the file and R6 the missing read"
cp "$SB/api.aside" "$PD/includes/api/api_install_auth.php"
cp "$PD/webhook.php" "$SB/webhook.aside"
python3 - "$PD/webhook.php" "$TECHCALL" <<'PY'
import sys
p, old = sys.argv[1], sys.argv[2]; s = open(p).read()
assert s.count(old) == 1, "technician anchor not unique"
open(p, 'w').write(s.replace(old, "$_whTech = '';"))
PY
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R1 installed files that differ: webhook.php')$(has "$OUT" 'webhook:no-technician-name')" "yesyes" "3b the technician's name taken out of job.add: R1 names the file and R6 the missing call"
cp "$SB/webhook.aside" "$PD/webhook.php"
printf '\n// changed on the server\n' >> "$PD/tools/set_config.php"; OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R5 files from Release A→5.18.84 differ on the server: tools/set_config.php")" "yes" "3c a 5.18.84 file changed on the server: R5 fails, naming it — the live booking WhatsApp's setting is regression-checked"
git -C "$REPO" show "$PIN:$P/tools/set_config.php" > "$PD/tools/set_config.php"
check "$(sqx 'DROP TRIGGER install_auth_never_deleted')" "done" "control: one trigger dropped from 086"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R3 migration 086 is recorded but not complete: incomplete:install_auth_never_deleted')" "yes" "3d R3 fails, naming the missing trigger — the ledger row alone is not trusted"
php -r '$p = new PDO("sqlite:" . $argv[1]); $sql = (string)file_get_contents($argv[2]);
  if (!preg_match("/CREATE TRIGGER IF NOT EXISTS install_auth_never_deleted.*?\nEND;/s", $sql, $m)) { fwrite(STDERR, "trigger not found\n"); exit(1); }
  $p->exec($m[0]); echo "restored";' "$DATA/plugin.sqlite3" "$PD/$MIG" >/dev/null
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" '5.18.85 (after): PASSED')$(ia_objects)" "0yes5:10:4" "3e each fault removed: --after-only PASSES again"

echo; echo "== 4. V3/R2 have teeth: the pilot switch is read live =="
flip --off; OUT="$(run --after-only)"
check "$(has "$OUT" 'the pilot reads off')$(has "$OUT" "ok    R2 the installed set_distributors --show reports pilot=off")$(fails "$OUT")" "yesyes0" "4a pilot turned off: V3/R2 read off, still PASSES"
flip --on; OUT="$(run --after-only)"
check "$(has "$OUT" 'the pilot reads on')$(has "$OUT" 'ok    R2 the installed set_distributors --show reports pilot=on')$(fails "$OUT")" "yesyes0" "4b pilot turned back on: V3/R2 read on again, PASSES"

echo; echo "== 5. after the operator turns the booking WhatsApp off (emulated as set_config.php --clear writes it), --after-only still PASSES =="
cfg_set customer_wa_install_scheduled off
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" '5.18.85 (after): PASSED')" "0yes" "5a --after-only PASSES with the booking WhatsApp switched off"
check "$(has "$OUT" 'ok    V3c customer_wa_install_scheduled is unchanged by this run (before=wa=absent/absent, after=wa=absent/absent; files/store) — OFF in both: no customer is sent the booking WhatsApp, as the operator left it')$(has "$OUT" 'ok    R2 the installed InstallScheduledWhatsApp reads customer_wa_install_scheduled OFF in the configuration files and in the store row, and enabled() is false in both — as the operator left it')" "yesyes" \
  "5b V3c and R2 say it is off, as the operator left it"
check "$(has "$OUT" 'the switches      install_auth_enabled on/on, customer_wa_install_scheduled absent/absent (files/store)')" "yes" "5c the summary says what the switches read"
cfg_set customer_wa_install_scheduled on files
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  V3c the configuration files and the store row disagree about customer_wa_install_scheduled (wa=on/absent, files/store)')$(has "$OUT" 'FAIL  R2 the installed InstallScheduledWhatsApp could not read its switch, or its two copies disagree')" "yesyes" \
  "5d the two copies disagree (the file says on, the store row the webhook reads first says nothing): V3c and R2 both FAIL"
cfg_set customer_wa_install_scheduled on
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" 'before=wa=on/on, after=wa=on/on')" "0yes" "5e the switch back on in both: --after-only PASSES"

echo; echo "== 6. the rollback: --rollback, typed ROLLBACK (with both switches on) =="
D2="$(data_digest)"; fresh; OUT="$(run --answer ROLLBACK --rollback)"
check "$(fails "$OUT")$(has "$OUT" '5.18.85 (rollback): PASSED')" "0yes" "PASSED"
for l in "note  customer_wa_install_scheduled reads 'wa=on/on' (files/store). 5.18.84 reads it too: after the rollback the booking WhatsApp still goes, without the technician's name" \
         "GO — evidence recorded" "checking out $BASE (5.18.84) for the documented deploy" "ok    container serves $BASE (5.18.84)" \
         "ok    V6 the sign-in page allows pinch-zoom" \
         "ok    V3b install_auth_enabled is unchanged by this run (before=ia=on/on, after=ia=on/on; files/store) — ON in both" \
         "ok    V3c customer_wa_install_scheduled is unchanged by this run (before=wa=on/on, after=wa=on/on; files/store) — ON in both: the booking WhatsApp stays live, as the operator left it; 5.18.84 sends it too, without the technician's name" \
         "ok    RB the installed manifest says 5.18.84" \
         "ok    RB the installed plugin is 5.18.84's again: no file of 5.18.84 reaches the quotation reader or the new name lookup — the request form opens empty, as before" \
         "ok    RB 5.18.84's booking WhatsApp is whole: the class with its switch and once-per-job claim, its call in job.add, its setting" \
         "ok    RB 5.18.83's Customer Installation Authorisation is whole: the one rule, the page behind its gate, the four guards, the link never queued, its switch in the tool" \
         "ok    RB migration 086 (5.18.83's) is still applied and complete" \
         "ok    RB the 5.18.74 hand-off setting and \"How to pay\" are still there" \
         "ok    RB the 5.18.72 Staff Cashbooks wording is still there" \
         "ok    RB the 5.18.71 landing hero and cash-in-hand tool are still there" \
         "ok    RB the 5.18.70 Field Register fix and records tool and the 5.18.69 Manual Entry stamp are still there" \
         "ok    RB the 5.18.68 staff-cash chain and card are still there" \
         "ok    RB the 5.18.66 photo surface and the 5.18.67 card are still there" \
         "note  RB the code is 5.18.84 again" \
         "Files 5.18.85 added stay on disk, reached by nothing" \
         "A request already sent keeps the charges it was sent with"; do
  check "$(has "$OUT" "$l")" "yes" "6: ${l:0:90}"
done
check "$(has "$OUT" "ok    backed up the installed plugin (5.18.85, $PIN)")" "yes" "the backup first — of 5.18.85's code"
check "$(live)$(installed_digest "$BASE")" "${BASE}0" "the container serves $BASE: every changed file as 5.18.84 has it"
check "$(inst_ver)" "5.18.84" "the installed manifest is 5.18.84 again"
check "$(grep -cF -- "$QP_READ" "$PD/includes/api/api_install_auth.php")$(grep -cF -- "$TECHCALL" "$PD/webhook.php")$(grep -cF -- "$FORM_NOTE" "$PD/tabs/support/scheduling.php")$(grep -cF -- "$WA_CALL" "$PD/webhook.php")$(grep -cF -- "$WA_KEYLINE" "$PD/tools/set_config.php")$(grep -cF -- "$RULE" "$PD/lib/InstallAuth.php")$(grep -cF -- "$ROUTE" "$PD/public.php")" "0001111" \
  "after the rollback: no quotation read, no name lookup, no note — and the booking WhatsApp and the authorisation whole"
check "$(test -f "$PD/$QP_LIB" && echo yes || echo no)" "yes" "the quotation reader 5.18.85 added stays on disk (deploy-hybrid.sh never deletes) — reached by nothing, as RB proved"
check "$(standin_page install_auth)$(ia_objects)$(ia_rows)" "4045:10:40:1:1:1:0" "after the rollback the live authorisation still refuses a request without a link; 086 and its records as they were"
check "$(data_digest)$(ledger_rows)" "${D2}2" "the rollback changed no data — the seeded ledger still holds its two rows, both switches still on"

echo; echo "== 7. weakened copies of the script must be caught (control on the control) =="
install_base; seed_data; vault UGX; fresh; flip --on
OUT="$(run --answer DEPLOY)"
check "$(fails "$OUT")$(has "$OUT" '5.18.85 (deploy): PASSED')" "0yes" "control: a fresh base deployed again — PASSED"
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
git -C "$REPO" show "$BASE:$P/webhook.php" > "$PD/webhook.php"
OUT="$(run --script "$MUT" --after-only)"
check "$(has "$OUT" "ok    R1 all $N_CH files 5.18.85 changes are installed exactly as $PIN has them")" "yes" "7a the R1-blinded copy calls a 5.18.84 webhook.php installed — the mutation is detected"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R1 installed files that differ: webhook.php')$(has "$OUT" 'webhook:no-technician-name')" "yesyes" "7b …and the real script FAILS R1 and R6 on the same fault, naming the file and the missing call"
git -C "$REPO" show "$PIN:$P/webhook.php" > "$PD/webhook.php"; rm -f "$MUT"
MUT="$(mutant r6 '|| R6_BAD="$R6_BAD prefill:no-quotation-read"' '|| true')"
git -C "$REPO" show "$BASE:$P/includes/api/api_install_auth.php" > "$PD/includes/api/api_install_auth.php"
OUT="$(run --script "$MUT" --after-only)"
check "$(has "$OUT" 'prefill:no-quotation-read')$(has "$OUT" 'FAIL  R1 installed files that differ: includes/api/api_install_auth.php')" "noyes" "7c the R6-blinded copy no longer names the missing read (only R1 still sees the file) — the mutation is detected"
git -C "$REPO" show "$PIN:$P/includes/api/api_install_auth.php" > "$PD/includes/api/api_install_auth.php"; rm -f "$MUT"
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" '5.18.85 (after): PASSED')" "0yes" "7d the staff API restored: --after-only PASSES"

echo; echo "== 8. what the rehearsal left behind =="
check "$(checkout_state)" "$CHECKOUT0" "this checkout is as the rehearsal found it: same commit, same tracked files"
check "$(ls "$REPO/scripts"/.mutant-* "$REPO/scripts"/.unpinned.sh "$REPO/scripts"/.tip.sh "$REPO/scripts"/.stray.sh "$REPO/scripts"/.short.sh 2>/dev/null | wc -l | tr -d ' ')" "0" "no weakened copy is left in the clone's scripts/"
check "$(grep -c 'Fatal\|Parse error' "$SB/web.log")" "0" "the stand-in served every request without a PHP fatal"

echo; echo "rehearsal: $PASS passed, $FAILN failed ($(cat "$SB/runs") runs of the script)"
[ "$FAILN" = "0" ]
