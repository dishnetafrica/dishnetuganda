#!/usr/bin/env bash
# Rehearse scripts/deploy-5.18.87.sh — the deploy, its checks, and the ROLLBACK — before the operator runs any of it.
#
# 5.18.87 over 5.18.86: the AI's WhatsApp leads recorded at last — Batch 0 of the AI communication layer (b0674bd, docs/55
# §3), never deployed until now. Three production files, taken from Batch 0 byte for byte: AiReplyWorker (the capture's pin
# read from the context that exists; the sales context's pin; the guard's audit ids), UcrmLeadWorker (it reads the payload
# WorkerBase decodes) and BrainContext (the pin's key). Code only: two new files (Batch 0's test and the switches' test), ten
# changed (those three, BrainContext's test, the manifest, five version pins). Deployed from a RELEASE COMMIT on
# release/5.18.87, cut on the live 5.18.86 release commit (c2c96e1), not from the branch tip, which also carries the partner
# portal, the PD-8 CSRF guard, the AI media layer and the rest of multi-number Batch 1. The base is installed AS PRODUCTION
# RUNS IT ON 07 OCT: 5.18.86, migration 086 applied by the plugin's own runner, Customer Installation Authorisation and the
# booking WhatsApp switched ON (both copies), the Inbox's own settings row naming Evolution and the three numbers, and the
# four lead switches ON in both copies — the uCRM lead write too, until the operator's step 0. This rehearsal proves:
#   · the pin is exactly the release: cut on 5.18.86, 12 files (2 added, 10 changed), no migration, the three production
#     files Batch 0's byte for byte, and none of the undeployed work — no migration 087, no AI media-layer file;
#   · stage A refuses, before anything changes, a server whose uCRM lead write is still ON (A4: the operator decided on 07 Oct,
#     "Leads on, uCRM later") or whose lead capture's two copies disagree; either live switch's two copies disagreeing; 086
#     incomplete; a pin carrying an AI media-layer file or migration 087; a pin lacking the uCRM worker's fix; the branch tip;
#     a placeholder pin; a server on 5.18.85 — and A3 is evidence now: the Inbox's row missing a number is said, never a stop;
#   · step 0 of the handover — the INSTALLED tools/set_config.php, through docker exec, as the operator runs it — switches the
#     uCRM write off in both copies and leaves lead capture ON;
#   · the script then runs end to end and PASSES, keeping every live feature exactly as it was (V3b, V3c, V3d, R2, R3, R6,
#     R8, R9), the four lead switches unchanged (V3e), and R10 saying what the installed lead path will do with them: leads
#     recorded on every selling number, nothing reaching uCRM; the deploy switches nothing and writes no record — every table,
#     the vault and the configuration files are byte-identical; the log carries no Evolution address, key or instance name
#     and no switch command;
#   · R1/R3/R5/R6/R10 have TEETH: 5.18.86's AI worker back → R1 and R6; 5.18.86's uCRM worker back → R1 and R6; a 5.18.86
#     file changed → R5; a trigger dropped from 086 → R3; a uCRM worker that would retry a switched-off sync → R1 and R10;
#   · after the deploy, the uCRM write switched on as its own step (the same tool) → R10 says each lead reaches uCRM, and off
#     again the data is byte for byte as before; lead capture's copies apart → V3e fails; ai_qualification off → R10 says no
#     lead is asked for anywhere; the booking WhatsApp off still PASSES; the Inbox's account number taken away → R9 fails;
#   · the rollback returns the plugin to 5.18.86 — manifest 5.18.86, Batch 0 gone, 5.18.86's Inbox route, the quotation form,
#     the booking WhatsApp and the authorisation whole and still ON, 086 complete, every switch as it was — and changes no
#     data;
#   · weakened copies of the script (A4 blinded; R6's Batch 0 check blinded; V3e's copies check blinded) are caught — the
#     control on the control;
#   · the clone is left as found.
#
# Everything runs in a sandbox: a fake `docker` that maps /data/ucrm to a directory here, a clone of this checkout, the plugin
# installed as 5.18.86 exactly (git archive of the release commit), and a stand-in web server for stage V's pages. DEPLOY and
# ROLLBACK are typed through a pseudo-terminal, as the operator types them. It never touches this checkout, the server or the
# network, and refuses to run where the server could be. The Evolution settings seeded are fictitious and never contacted.
#
#   bash scripts/harness/deploy-5.18.87/rehearse.sh            REHEARSE_KEEP=<dir> keeps every run's full output
set -u
R="$(cd "$(dirname "$0")/../../.." && pwd)"
for f in /data/ucrm /opt/dishnet /var/run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
BASE=c2c96e1; RA_BASE=e076632; OLDER=4790019; BRANCH_FILE=scripts/deploy-5.18.87.sh; P=dishnet-hybrid-sudan; RELEASE_BRANCH=release/5.18.87
B0=b0674bd                                                                                             # Batch 0, on the branch: the source of the three files
MIG=migrations/086_install_authorisation.sql
IR_LIB=lib/InboxReplyRoute.php                                                                          # 5.18.86's Inbox route — live, must survive
IR_DECIDE='public static function decide(string $conversationChannel, array $config, ?string $dataDir, ?\PDO $pdo = null): array'
IR_CALL="InboxReplyRoute::decide((string)(\$conv['channel'] ?? ''), (array)\$config, \$dataDir, \$store->getPdo());"   # ×4 in the Inbox API
OLD_LINE="=== 'accounts' ? 'accounts' : 'support';"                                                      # 5.18.85's sender line — gone since 5.18.86
CHSEND='public function sendOnChannel(string $channel, string $toPhone, string $text, string $event,'   # the channel send
FORSTORE='public static function forStore(array $config, ?\PDO $pdo, ?string $dataDir, int $timeout = 20): self'
B0_PIN='$this->latestPin($convId, $context));'                         # 5.18.87: the capture's pin, from the context that exists
B0_OLDPIN='$this->latestPin($convId, $ctx));'                          # 5.18.86's line: there is no $ctx there — every capture failed
B0_PO='private static function payloadOf(array $event): array'        # 5.18.87: the uCRM worker reads the payload WorkerBase decodes
B0_POCALL='self::payloadOf($event);'                                   # …in handle() and in onDead(): ×2
B0_LOC="'location'       => ['lat', 'lng', 'name', 'in_bounds'],"     # 5.18.87: BrainContext carries the pin
B0_CTXLOC="'location'  => \$ctx['location'] ?? null,"                  # 5.18.87: the sales context passes it on
B0_GUARD="return (int)(\$this->turnIds['conversation_id'] ?? (\$ctx['conversation_id'] ?? 0));"   # 5.18.87: the guard's audit ids
B0_DECIDED="if (in_array(\$r['action'], ['disabled', 'skipped'], true)) {"   # a switched-off sync is settled, never retried (R10)
QP_LIB=lib/QuotationPrefill.php                                                                         # 5.18.85's — live, must survive
QP_PICK='public static function pick($quotes, int $clientId): ?array'
QP_READ="\$iaQList = \$iaQCrm->get('billing/quotes?' . http_build_query(['clientId' => \$cid]));"
FORM_NOTE='Filled from quotation <b>'
TECHCALL="\$_whTech = \$_whJobNotifier ? whInstallTechName(\$assignedUserId, \$store, \$crm) : '';"
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
EXPECT_N=12; EXPECT_AD=2                # files in the release delta (set when the release commit was cut)
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
check "$(git -C "$REPO" rev-parse --short "$PIN^" 2>/dev/null)" "$BASE" "control: the release commit is cut on $BASE (5.18.86), the live version"
ver() { git -C "$REPO" show "$1:$P/manifest.json" | python3 -c 'import json,sys; print(json.load(sys.stdin)["information"]["version"])'; }
check "$(ver "$PIN")" "5.18.87" "the plugin at the pin is 5.18.87"
check "$(ver "$BASE")" "5.18.86" "control: the base is 5.18.86"
dl() { git -C "$REPO" diff --no-renames --name-only --diff-filter="$1" "$2" "$3" -- $P; }
CHANGED="$(dl AM "$BASE" "$PIN")"
N_CH="$(grep -c . <<<"$CHANGED")"; N_AD="$(dl A "$BASE" "$PIN" | grep -c .)"; N_MO=$((N_CH - N_AD)); N_DEL="$(dl D "$BASE" "$PIN" | grep -c .)"
echo "  5.18.87   $N_CH files: $N_MO changed, $N_AD added · $N_DEL removed or renamed"
check "$N_DEL" "0" "control: 5.18.86 → 5.18.87 removes no file"
check "$N_AD" "$EXPECT_AD" "control: 5.18.87 adds exactly $EXPECT_AD files"
check "$N_CH" "$EXPECT_N" "control: $EXPECT_N files in the delta"
for want in "workers/AiReplyWorker.php" "workers/UcrmLeadWorker.php" "lib/BrainContext.php" "tests/test_brain_context.php" \
            "tests/test_lead_path_batch0.php" "tests/test_lead_switches.php" "manifest.json" "tests/test_distributor_registry.php" \
            "tests/test_distributor_apply.php" "tests/test_distributor_territory.php" "tests/test_distributor_notify.php" "tests/test_distributor_link_ucrm.php"; do
  check "$(grep -c "^$P/$want$" <<<"$CHANGED")" "1" "control: the delta includes $want"
done
check "$(grep -cE "^$P/(partner_api\.php|lib/Partner|lib/StaffApiCsrf\.php|lib/Totp\.php|lib/DistributorPortalData\.php|lib/Media|lib/InboundMedia|lib/Voice|lib/Image|lib/Document|lib/Pdf|lib/Channel|lib/InboxReplyRoute|migrations/|tools/|cron/|evo_webhook\.php|webhook\.php|public\.php|includes/)" <<<"$CHANGED")" "0" \
  "control: no migration, no tool, no cron, no webhook, no page or API file, and no partner-portal, CSRF, AI media-layer or registry file in the delta"
check "$(grep "^$P/workers/" <<<"$CHANGED" | grep -vcE "^$P/workers/(AiReplyWorker|UcrmLeadWorker)\.php$")" "0" "control: the only workers in the delta are the AI worker and the uCRM lead worker"
same_as_b0() { [ "$(git -C "$REPO" rev-parse "$PIN:$P/$1" 2>/dev/null)" = "$(git -C "$REPO" rev-parse "$B0:$P/$1" 2>/dev/null)" ] && echo same || echo differs; }
check "$(same_as_b0 workers/AiReplyWorker.php) $(same_as_b0 workers/UcrmLeadWorker.php) $(same_as_b0 lib/BrainContext.php) $(same_as_b0 tests/test_brain_context.php) $(same_as_b0 tests/test_lead_path_batch0.php)" \
  "same same same same same" "control: the three production files, BrainContext's test and Batch 0's own test are Batch 0's ($B0) byte for byte"
at() { git -C "$REPO" show "$1:$P/$2" 2>/dev/null | grep -cF -- "$3"; }
check "$(at "$PIN" workers/AiReplyWorker.php "$B0_PIN")$(at "$PIN" workers/AiReplyWorker.php "$B0_OLDPIN")$(at "$PIN" workers/UcrmLeadWorker.php "$B0_PO")$(at "$PIN" workers/UcrmLeadWorker.php "$B0_POCALL")$(at "$PIN" lib/BrainContext.php "$B0_LOC")$(at "$PIN" workers/AiReplyWorker.php "$B0_CTXLOC")$(at "$PIN" workers/AiReplyWorker.php "$B0_GUARD")$(at "$PIN" workers/UcrmLeadWorker.php "$B0_DECIDED")" "10121111" \
  "control: at the pin Batch 0 is in place — the capture's pin from the context (5.18.86's \$ctx line gone), payloadOf() and its two calls, the pin's key, the sales context's pin, the guard's ids; a switched-off sync still settled"
check "$(at "$BASE" workers/AiReplyWorker.php "$B0_PIN")$(at "$BASE" workers/AiReplyWorker.php "$B0_OLDPIN")$(at "$BASE" workers/UcrmLeadWorker.php "$B0_PO")$(at "$BASE" workers/UcrmLeadWorker.php "$B0_POCALL")$(at "$BASE" lib/BrainContext.php "$B0_LOC")$(at "$BASE" workers/AiReplyWorker.php "$B0_CTXLOC")$(at "$BASE" workers/AiReplyWorker.php "$B0_GUARD")" "0100000" \
  "control: 5.18.86 has none of Batch 0, and its \$ctx line once — the defect production runs"
check "$(at "$PIN" "$IR_LIB" "$IR_DECIDE")$(at "$PIN" includes/api/api_whatsapp.php "$IR_CALL")$(at "$PIN" includes/api/api_whatsapp.php "$OLD_LINE")$(at "$PIN" lib/NotificationService.php "$CHSEND")$(at "$PIN" lib/EvolutionApiService.php "$FORSTORE")$(at "$PIN" workers/AiReplyWorker.php 'VoiceTranscription')$(at "$PIN" workers/AiReplyWorker.php 'replyRoleOrRefuse')" "1401100" \
  "control: 5.18.86's Inbox route is whole at the pin — the route, its four calls, the channel send, forStore() — and the AI worker carries no media path and no registry route"
check "$(at "$PIN" "$QP_LIB" "$QP_PICK")$(at "$PIN" includes/api/api_install_auth.php "$QP_READ")$(at "$PIN" tabs/support/scheduling.php "$FORM_NOTE")$(at "$PIN" webhook.php "$TECHCALL")" "1111" "control: 5.18.85's quotation form is whole at the pin: the reader, the prefill's read, the note, the technician's name"
check "$(at "$PIN" "$WA_LIB" "$WA_FLAG")$(at "$PIN" "$WA_LIB" "$WA_CLAIM")$(at "$PIN" webhook.php "$WA_CALL")$(at "$PIN" tools/set_config.php "$WA_KEYLINE")" "1111" "control: 5.18.84's booking WhatsApp is whole at the pin: its switch, claim, call and setting"
check "$(at "$PIN" lib/InstallAuth.php "$RULE")$(at "$PIN" public.php "$ROUTE")$(at "$PIN" includes/api/api_field_ops.php "$G_OUT")$(at "$PIN" lib/NotificationService.php "$NEVERQ")" "1111" "control: 5.18.83's authorisation is whole at the pin"
check "$(at "$PIN" lib/TenantProfile.php "$HANDOFF")$(at "$PIN" tabs/customer_app/portal.php "$PAY")$(at "$PIN" tabs/accounts/staff_cashbooks.php "$WORD72")$(at "$PIN" tabs/accounts/accounts_dashboard.php "$HERO71")$(at "$PIN" tools/cash_in_hand.php "$CIHTOOL")" "11111" "control: 5.18.74's hand-off and How-to-pay, 5.18.72's wording, 5.18.71's hero and tool at the pin"
check "$(at "$PIN" tabs/sales/wallet.php "$PREVMARK")$(at "$PIN" tabs/accounts/staff_cashbooks.php "$PREV69")$(at "$PIN" tabs/accounts/cashbook.php "$CHAINMARK")$(at "$PIN" lib/StaffCashPositionService.php 'dn_book_base(null)')" "1111" "control: 5.18.70's, 5.18.69's and 5.18.68's markers at the pin"
check "$(at "$PIN" webhook.php 'DistributorEvents::maybeNotify')$(at "$PIN" lib/DistributorNotifier.php 'new NullWhatsAppChannel')" "21" "control: webhook.php still draws the two draft hooks and the notifier still binds the Null channel"
HEADER_CMD="$(sed -n '/^# Run as root/,/^# The rollback is a separate command/p' "$DEPLOY")"
check "$(grep -c 'deploy-5.18.87.sh 2>&1 | tee' <<<"$HEADER_CMD")$(grep -cE -- '--rollback|--void|--relabel|--key|--value|set_config|git checkout [0-9a-f]{7}' <<<"$HEADER_CMD")" "10" \
  "the header's deploy command stands alone: no rollback, no --void/--relabel, no switch command (step 0 lives in docs/07 only) and no checkout of another commit in its block (docs/44 §16.9)"
check "$(grep -c "git fetch origin $RELEASE_BRANCH" <<<"$HEADER_CMD")" "1" "the header's deploy command fetches the release branch"
check "$(grep -cE -- '--key +(customer_wa_install_scheduled|install_auth_enabled|multi_number_channels_enabled|ai_lead_capture|ai_crm_lead_sync|ai_qualification|ai_sales_on_all_numbers)|(customer_wa_install_scheduled|install_auth_enabled|multi_number_channels_enabled|ai_lead_capture|ai_crm_lead_sync|ai_qualification|ai_sales_on_all_numbers) +--value' "$DEPLOY")" "0" \
  "the script carries no command that switches a feature, the registry or a lead switch — neither on nor off"

# ── The container: 5.18.86 installed AS PRODUCTION RUNS IT, its data beside it ─
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

install_base() {   # the installed plugin exactly as 5.18.86
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
seed_data() {      # the plugin's databases, written by 5.18.86's own store (084 and 086 applied by its runner); then production's
                   # 07 Oct state: authorisation switched on in both copies, activation #1 with job 20 exempt and its event, the
                   # booking WhatsApp switched on in both copies, the Inbox's own settings row naming Evolution and the three
                   # numbers (fictitious: never contacted), and the four lead switches ON in both copies — the uCRM lead write
                   # too, as production has it before the operator's step 0 (docs/55 §3: both lead switches read ON in Uganda)
  rm -rf "$DATA"/plugin.sqlite3* "$DATA"/dishnet.sqlite* "$DATA"/config.json "$DATA"/kyc_config.json "$DATA"/migration.log "$DATA"/uploads "$DATA"/webhook_log.json
  php -r '
    foreach (["bootstrap_data", "StoreInterface", "JsonStore", "SqliteStore"] as $l) require_once $argv[1] . "/lib/$l.php";
    require_once $argv[1] . "/lib/CashbookService.php";
    $s = SqliteStore::create($argv[2]);
    $s->save("kyc_config.json", ["crm_base_url" => "http://127.0.0.1:1", "company_name" => "DishNet Sandbox", "currency_code" => "UGX",
        "tenant_profile" => "uganda", "cashbook_base_currency" => "UGX", "cashbook_currencies" => "UGX,USD", "currency_symbol" => "UGX",
        "install_auth_job_titles" => "Starlink Installation", "install_auth_whatsapp" => "1",
        "evo_api_url" => "http://127.0.0.1:9/rh-evo", "evo_api_key" => "rh-key", "evo_instance_sales" => "rh-sales",
        "evo_instance_support" => "rh-support", "evo_instance_account" => "rh-account"]);
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
  for k in ai_qualification ai_sales_on_all_numbers ai_lead_capture ai_crm_lead_sync; do cfg_set "$k" on; done
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
installed_digest() {   # $1 a commit: the number of files 5.18.87 touches that are NOT installed as that commit has them
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
release_variant() {   # release_variant add|drop|revert PATH — a commit cut on 5.18.86 holding the release's tree with one file planted,
                      # removed, or put back as 5.18.86 has it
  local idx="$SB/variant.idx" tree c
  GIT_INDEX_FILE="$idx" git -C "$REPO" read-tree "$PIN"
  case "$1" in
    add)    GIT_INDEX_FILE="$idx" git -C "$REPO" update-index --add --cacheinfo "100644,$(printf '<?php // planted\n' | git -C "$REPO" hash-object -w --stdin),$P/$2" ;;
    revert) GIT_INDEX_FILE="$idx" git -C "$REPO" update-index --cacheinfo "100644,$(git -C "$REPO" rev-parse "$BASE:$P/$2"),$P/$2" ;;
    *)      GIT_INDEX_FILE="$idx" git -C "$REPO" update-index --force-remove "$P/$2" ;;
  esac
  tree="$(GIT_INDEX_FILE="$idx" git -C "$REPO" write-tree)"; rm -f "$idx"
  c="$(git -C "$REPO" -c user.name=rehearsal -c user.email=rehearsal@example.test commit-tree "$tree" -p "$(git -C "$REPO" rev-parse "$BASE")" -m "rehearsal variant: $1 $2")"
  git -C "$REPO" rev-parse --short "$c"
}

install_base; seed_data; vault UGX; fresh
flip --on   # enable the pilot through the real tool, exactly as the live server has it
for i in $(seq 1 50); do [ "$(standin_page customer_login)" = "200" ] && break; sleep 0.1; done
D0="$(data_digest)"
check "$(inst_ver)" "5.18.86" "control: the installed base is 5.18.86"
check "$(grep -cF -- "$B0_PIN" "$PD/workers/AiReplyWorker.php")$(grep -cF -- "$B0_OLDPIN" "$PD/workers/AiReplyWorker.php")$(grep -cF -- "$B0_PO" "$PD/workers/UcrmLeadWorker.php")$(grep -cF -- "$B0_LOC" "$PD/lib/BrainContext.php")" "0100" \
  "control: the base is production's lead path — the capture handed \$ctx, which does not exist there, no payloadOf(), no pin in the context"
check "$(grep -cF -- "$IR_CALL" "$PD/includes/api/api_whatsapp.php")$(grep -cF -- "$OLD_LINE" "$PD/includes/api/api_whatsapp.php")$(grep -cF -- "$CHSEND" "$PD/lib/NotificationService.php")$(test -f "$PD/$IR_LIB" && echo yes || echo no)" "401yes" \
  "control: the base has 5.18.86's Inbox route — the Inbox's four sends through it, 5.18.85's line gone, the channel send"
check "$(grep -cF -- "$QP_READ" "$PD/includes/api/api_install_auth.php")$(grep -cF -- "$TECHCALL" "$PD/webhook.php")$(test -f "$PD/$QP_LIB" && echo yes || echo no)" "11yes" "control: the base has 5.18.85's quotation form: the reader, the prefill's read, the technician's name in job.add"
check "$(grep -cF -- "$WA_FLAG" "$PD/$WA_LIB")$(grep -cF -- "$WA_CLAIM" "$PD/$WA_LIB")$(grep -cF -- "$WA_CALL" "$PD/webhook.php")$(grep -cF -- "$WA_KEYLINE" "$PD/tools/set_config.php")" "1111" "control: the base has 5.18.84's booking WhatsApp: the switch, the claim, the call, the setting"
check "$(grep -cF -- "$RULE" "$PD/lib/InstallAuth.php")$(grep -cF -- "$ROUTE" "$PD/public.php")$(grep -cF -- "$G_OUT" "$PD/includes/api/api_field_ops.php")$(grep -cF -- "$NEVERQ" "$PD/lib/NotificationService.php")" "1111" "control: the base has 5.18.83's authorisation: the rule, the route, the check-out guard, the never-queued link"
check "$(grep -cF -- "$HANDOFF" "$PD/lib/TenantProfile.php")$(grep -cF -- "$PAY" "$PD/tabs/customer_app/portal.php")$(grep -cF -- "$WORD72" "$PD/tabs/accounts/staff_cashbooks.php")$(grep -cF -- "$HERO71" "$PD/tabs/accounts/accounts_dashboard.php")$(grep -cF -- "$CIHTOOL" "$PD/tools/cash_in_hand.php" 2>/dev/null)" "11111" "control: the base has 5.18.74's hand-off setting and How-to-pay, 5.18.72's wording, 5.18.71's hero and tool"
check "$(ledger_rows)" "2" "control: the seeded UGX book holds its two approved rows (a receipt of 1,000,000 and a Staff Advance of 150,000)"
check "$(dist_tables)$(photo_tables)" "82" "control: the base plugin.sqlite3 has the 8 distributor tables and 084's two photo tables"
check "$(ia_objects)$(mig_row)$(ia_rows)" "5:10:410:1:1:1:0" "control: 086 applied by the plugin's own runner, complete, holding production's 06 Oct records: activation #1, job 20 exempt, its event"
both_copies() { echo "$(sq "SELECT json_extract(data, '$.$1') FROM kyc_config WHERE id = 0")$(php -r 'echo json_decode((string)file_get_contents($argv[1]), true)[$argv[2]] ?? "-";' "$DATA/kyc_config.json" "$1")"; }
row_has() { sq "SELECT CASE WHEN json_extract(data, '$.$1') IS NULL OR json_extract(data, '$.$1') = '' THEN 'no' ELSE 'yes' END FROM kyc_config WHERE id = 0"; }
check "$(both_copies install_auth_enabled)$(both_copies customer_wa_install_scheduled)" "1111" "control: install_auth_enabled and customer_wa_install_scheduled are ON in both copies, as the operator switched them on"
check "$(both_copies ai_qualification)$(both_copies ai_sales_on_all_numbers)$(both_copies ai_lead_capture)$(both_copies ai_crm_lead_sync)" "11111111" \
  "control: the four lead switches are ON in both copies — the uCRM lead write too, as production has it before step 0"
check "$(row_has evo_api_url)$(row_has evo_api_key)$(row_has evo_instance_sales)$(row_has evo_instance_support)$(row_has evo_instance_account)$(row_has multi_number_channels_enabled)" "yesyesyesyesyesno" \
  "control: the Inbox's own settings row names Evolution and the three numbers, and the registry switch is unset"
check "$(env PATH="$SB/bin:$PATH" DN_DATA_DIR="$DATA" bash "$SB/bin/docker" exec ucrm php "$IN_CONTAINER/tools/set_distributors.php" --show 2>/dev/null | awk '/distributors_enabled/ {print toupper($2); exit}')" "ON" "control: the pilot is ON on the base"
check "$(standin_page customer_login)$(standin_page install_auth)$(data_digest)" "200404$D0" "control: the stand-in boots the installed store, the live authorisation page refuses a request without a link, and neither changes any data"

# 1x take the configuration apart. cfg_set re-adds a key at the END of the store row, so putting a switch back with it changes
# the row's bytes though not its meaning: the copies are kept byte for byte here and put back exactly, and each refusal is
# checked on its own to leave the data as the test set it.
cfg_keep() { cp "$DATA/kyc_config.json" "$SB/kyc_config.kept"
  php -r '$p = new PDO("sqlite:" . $argv[1]); file_put_contents($argv[2], (string)$p->query("SELECT data FROM kyc_config WHERE id = 0")->fetchColumn());' "$DATA/plugin.sqlite3" "$SB/kyc_row.kept"; }
cfg_back() { cp "$SB/kyc_config.kept" "$DATA/kyc_config.json"
  php -r '$p = new PDO("sqlite:" . $argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $p->prepare("UPDATE kyc_config SET data = ? WHERE id = 0")->execute([(string)file_get_contents($argv[2])]);' "$DATA/plugin.sqlite3" "$SB/kyc_row.kept"; }
row_unset() {   # row_unset KEY — remove a key from the store row ONLY; the configuration file keeps it
  php -r '$p = new PDO("sqlite:" . $argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $r = json_decode((string)$p->query("SELECT data FROM kyc_config WHERE id = 0")->fetchColumn(), true); unset($r[$argv[2]]);
    $p->prepare("UPDATE kyc_config SET data = ? WHERE id = 0")->execute([json_encode($r)]);' "$DATA/plugin.sqlite3" "$1"; }
setcfg() {   # setcfg KEY VALUE — the docs/07 handover's own command, run as the operator runs it: the INSTALLED tools/set_config.php
  env PATH="$SB/bin:$PATH" DN_DATA_DIR="$DATA" bash "$SB/bin/docker" exec ucrm php "$IN_CONTAINER/tools/set_config.php" --key "$1" --value "$2" 2>&1
}
SWITCH_RX='--void|--relabel|SEPARATELY|--key +(customer_wa_install_scheduled|install_auth_enabled|multi_number_channels_enabled|ai_lead_capture|ai_crm_lead_sync|ai_qualification|ai_sales_on_all_numbers)|(customer_wa_install_scheduled|install_auth_enabled|multi_number_channels_enabled|ai_lead_capture|ai_crm_lead_sync|ai_qualification|ai_sales_on_all_numbers) +--value'

echo; echo "== 1. NO-GO before anything changes =="
printf '%s\n' "$OLDER" > "$PD/.deployed-commit"; fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: the container serves $OLDER; 5.18.87 was built and tested against $BASE (5.18.86) — deploy 5.18.86 first (scripts/deploy-5.18.86.sh) and send its log")" "yes" \
  "1a the server still runs 5.18.85: NO-GO — 5.18.86 goes first"
check "$(live)$(backups)" "${OLDER}0" "…nothing deployed, no backup taken"
printf '%s\n' "$BASE" > "$PD/.deployed-commit"
S="$(pinned_copy unpinned __PLUGIN_COMMIT__)"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
check "$(has "$OUT" 'STOP: this copy of the script is not pinned to a reviewed commit')$(live)$(backups)" "yes${BASE}0" \
  "1b a copy still carrying the placeholder pin: stops before anything is read"
S="$(pinned_copy tip "$TIP")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
check "$(has "$OUT" "STOP: $TIP is not cut on $BASE (5.18.86): its parent is")$(has "$OUT" 'this script is for another build')" "yesyes" \
  "1c a copy pinned to the branch tip ($TIP, parent not 5.18.86): refused — the undeployed work cannot ride along"
check "$(live)$(backups)$(cnt "$OUT" 'serves ')" "${BASE}00" "…before the container was even looked at: nothing deployed, no backup, no live read"
V_ADD="$(release_variant add lib/MediaPolicy.php)"; S="$(pinned_copy stray "$V_ADD")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
check "$(has "$OUT" "STOP: the pin carries files that are not 5.18.87's, which this release must not ship: lib/MediaPolicy.php(added)")$(live)$(backups)" "yes${BASE}0" \
  "1d a commit cut on 5.18.86 carrying the release AND an AI media-layer file: refused by the delta's allow-list, naming the file"
V_ADD2="$(release_variant add migrations/087_wa_channels.sql)"; S="$(pinned_copy stray2 "$V_ADD2")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
check "$(has "$OUT" "STOP: the pin carries files that are not 5.18.87's, which this release must not ship: migrations/087_wa_channels.sql(added)")$(live)$(backups)" "yes${BASE}0" \
  "1d' …and the release AND Batch 1's migration 087: refused, naming it — the registry's tables do not ride along"
V_REV="$(release_variant revert workers/UcrmLeadWorker.php)"; S="$(pinned_copy short "$V_REV")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
check "$(has "$OUT" "STOP: the pin lacks files 5.18.87 is made of: workers/UcrmLeadWorker.php")$(live)$(backups)" "yes${BASE}0" \
  "1e a commit cut on 5.18.86 carrying the release WITHOUT the uCRM worker's fix: refused, naming it"
cfg_keep
cfg_set customer_wa_install_scheduled off; cfg_set customer_wa_install_scheduled on files; DX="$(data_digest)"; fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: customer_wa_install_scheduled reads 'wa=on/absent' (files/store) — this script must find it readable, its two copies agreeing")$(live)$(backups)$(data_digest)" "yes${BASE}0$DX" \
  "1f the booking WhatsApp's two copies disagree (the files on, the store row the webhook reads first silent): NO-GO — nothing deployed, no backup, no data changed"
cfg_back
php -r '$f = json_decode((string)file_get_contents($argv[1]), true); unset($f["install_auth_enabled"]); file_put_contents($argv[1], json_encode($f));' "$DATA/kyc_config.json"
DX="$(data_digest)"; fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: install_auth_enabled reads 'ia=absent/on' (files/store) — this script must find it readable, its two copies agreeing")$(live)$(backups)$(data_digest)" "yes${BASE}0$DX" \
  "1g the authorisation's two copies disagree (the store row on, the files silent): NO-GO — a deploy would leave the tools and the pages acting differently; no data changed"
cfg_back
check "$(sqx 'DROP TRIGGER install_auth_status_moves')" "done" "control: one trigger dropped from 086 — what a PARTIAL run would leave"
fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: migration 086 (5.18.83's) is recorded but not complete on this server")$(live)$(backups)" "yes${BASE}0" \
  "1h 086 incomplete on the live server: NO-GO — the authorisation depends on it; nothing deployed"
php -r '$p = new PDO("sqlite:" . $argv[1]); $sql = (string)file_get_contents($argv[2]);
  if (!preg_match("/CREATE TRIGGER IF NOT EXISTS install_auth_status_moves.*?\nEND;/s", $sql, $m)) { fwrite(STDERR, "trigger not found\n"); exit(1); }
  $p->exec($m[0]); echo "restored";' "$DATA/plugin.sqlite3" "$PD/$MIG" >/dev/null
check "$(ia_objects)$(data_digest)" "5:10:4$D0" "control: the trigger restored, the switches back as production has them — the data is as seeded"
# A4 — the switches as production has them TODAY (docs/55: both read ON in Uganda's listing), before the operator's step 0.
DX="$(data_digest)"; fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "uCRM lead write ls=on/on")$(has "$OUT" "STOP: NO-GO: ai_crm_lead_sync reads 'ls=on/on' (files/store). The operator decided on 07 Oct to deploy 5.18.87 with the uCRM lead write OFF, so that no lead is created in uCRM yet. Switch it off first — the command is step 0 of the handover in docs/07, 07 Oct — then run this script again. Nothing was changed")$(live)$(backups)$(data_digest)" "yesyes${BASE}0$DX" \
  "1i the uCRM lead write still ON, as production has it today: A4 NO-GO — nothing deployed, no backup, no data changed"
check "$(grep -cE -- "$SWITCH_RX" <<<"$OUT")" "0" "…and the refusal points to step 0 of the handover without printing a switch command"
cfg_set ai_lead_capture off; cfg_set ai_lead_capture on files; DX="$(data_digest)"; fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: ai_lead_capture reads 'lc=on/absent' (files/store). The operator decided on 07 Oct to deploy 5.18.87 with lead capture ON in both copies")$(live)$(backups)$(data_digest)" "yes${BASE}0$DX" \
  "1j lead capture's two copies disagree (the files on, the store row silent): A4 NO-GO — nothing deployed, no data changed"
cfg_back
check "$(data_digest)" "$D0" "control: the copies put back byte for byte — the data is as seeded"
# Step 0 of the handover, exactly as the operator runs it: the INSTALLED tools/set_config.php, through docker exec.
S0="$(setcfg ai_crm_lead_sync 0)"
check "$(has "$S0" 'ai_crm_lead_sync = 0')$(both_copies ai_crm_lead_sync)$(both_copies ai_lead_capture)" "yes0011" \
  "step 0: the handover's command switches the uCRM write off in both copies — the files and the store row — and leaves lead capture ON"
D1="$(data_digest)"
cfg_keep; row_unset evo_instance_account; DX="$(data_digest)"; fresh; OUT="$(run --answer NO)"
check "$(has "$OUT" "note  the settings row the Inbox reads does not name every number (evo=yes sales=yes support=yes account=no registry=absent): 5.18.86's Inbox refuses a reply in a chat whose number it lacks. 5.18.87 does not change the Inbox")$(has "$OUT" 'ok    A4 the lead switches are as the operator decided on 07 Oct')$(has "$OUT" 'STOP: not confirmed')$(live)$(backups)$(data_digest)" "yesyesyes${BASE}1$DX" \
  "1k A3 is evidence now: the account number missing from the Inbox's row is said, never a reason to stop — the run reached the DEPLOY prompt (answered NO), nothing deployed"
check "$(cnt "$OUT" 'rh-evo')$(cnt "$OUT" 'rh-key')$(cnt "$OUT" 'rh-sales')$(cnt "$OUT" 'rh-account')" "0000" "…and the run printed no address, no key and no instance name: yes/no only"
cfg_back
check "$(data_digest)" "$D1" "control: the Inbox's row put back byte for byte — the data is as step 0 left it"

echo; echo "== 2. the deploy, as the operator runs it (after step 0) =="
fresh; OUT="$(run --answer DEPLOY)"
check "$(fails "$OUT")" "0" "no FAIL line"
check "$(has "$OUT" '5.18.87 (deploy): PASSED')" "yes" "PASSED"
for l in "ok    A0 the release delta is exactly 5.18.87's $N_CH files — $N_AD added, $N_MO changed, no migration; no partner-portal, CSRF or AI media-layer file, and none of multi-number Batch 1's registry and routing" \
         "ok    A2 PHP" \
         "authorisation   ia=on/on" \
         "booking WA      wa=on/on   (customer_wa_install_scheduled in the configuration files / the store row; the operator's setting — this run never changes it)" \
         "lead capture    lc=on/on   (ai_lead_capture in the configuration files / the store row; the operator's decision, 07 Oct: ON)" \
         "uCRM lead write ls=off/off   (ai_crm_lead_sync; the operator's decision, 07 Oct: OFF, until it is switched on as its own step)" \
         "qualification   qu=on/on   (ai_qualification — the rules that ask the assistant to record a lead; reported, never changed)" \
         "sales on all    sa=on/on   (ai_sales_on_all_numbers — the support and account number sell, and record leads, too; reported)" \
         "Inbox settings  evo=yes sales=yes support=yes account=yes registry=absent   (the kyc_config row the Inbox reads, judged by the installed EvolutionApiService; yes/no only)" \
         "ok    A3 the settings row the Inbox reads still reaches Evolution and names a sales and an account number — 5.18.86's Inbox replies keep a number to leave on (evo=yes sales=yes support=yes account=yes registry=absent)" \
         "ok    A4 the lead switches are as the operator decided on 07 Oct: ai_lead_capture ON in both copies, ai_crm_lead_sync OFF (lc=on/on, ls=off/off; files/store)" \
         "086 state       mig=applied:" \
         "rows=0:1:1:1:0 missing=-" \
         "job.add         2 received among the webhook log's last 300 entries, the last at 2026-10-06 15:02:11" \
         "— one consistent copy, integrity ok" "ok    backed up the installed plugin (5.18.86, $BASE)" "ok    backed up the configuration vault" \
         "GO — evidence recorded" \
         "checking out $PIN (5.18.87, the release commit) for the documented deploy" \
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
         "ok    V10 wa_send_reply without a login answers 401 — the staff guard, before any conversation is read or anything sent" \
         "ok    V3 the pilot switch is unchanged by the deploy (before=pilot=on, after=pilot=on)" \
         "ok    V3b install_auth_enabled is unchanged by this run (before=ia=on/on, after=ia=on/on; files/store) — ON in both: Customer Installation Authorisation stays live, as the operator left it" \
         "ok    V3c customer_wa_install_scheduled is unchanged by this run (before=wa=on/on, after=wa=on/on; files/store) — ON in both: the booking WhatsApp stays live, as the operator left it" \
         "ok    V3d the settings row the Inbox reads is unchanged by this run (before=evo=yes sales=yes support=yes account=yes registry=absent, after=evo=yes sales=yes support=yes account=yes registry=absent; yes/no only)" \
         "ok    V3e the lead switches are unchanged by this run (lc=on/on ls=off/off qu=on/on sa=on/on; files/store) — the script never switches them" \
         "ok    V4 no fatal or parse error of $P in the container log since" \
         "ok    R1 all $N_CH files 5.18.87 changes are installed exactly as $PIN has them ($N_AD new)" \
         "ok    R1 the installed manifest says 5.18.87" \
         "ok    R2 the installed set_distributors --show reports pilot=on" \
         "ok    R2 the installed InstallAuth reads install_auth_enabled ON in both places and enabled() is true — Customer Installation Authorisation stays live, as the operator left it" \
         "ok    R2 the installed InstallScheduledWhatsApp reads customer_wa_install_scheduled ON in both places and enabled() is true — the booking WhatsApp stays live, as the operator left it" \
         "ok    R3 migration 086 (5.18.83's) is still applied and complete — 5 tables, 10 triggers, 4 indexes, both CHECKs, the ledger row matching the installed file; its tables hold install_auth:events:activations:exempt:rate = 0:1:1:1:0" \
         "ok    R3 084 is still installed and its two tables exist on the live plugin.sqlite3" \
         "ok    R4 the pilot libs + the Distributors tab are installed as before" \
         "no partner-portal, CSRF or AI media-layer file is installed, nor multi-number Batch 1 (migration 087, the registry CLI, the webhook, worker and event-processor routing); the AI worker carries Batch 0 alone" \
         "ok    R5 all" \
         "ok    R6 every earlier surface is installed as before" \
         "5.18.86's Inbox (the route and its South Sudan rule, all four Inbox sends through it, the channel send that never falls back, forStore() and the constructor's rule, the registry's switch), and 5.18.87's Batch 0 is in place: the capture's pin from the context, the uCRM worker reading its payload, the sales context's pin, the guard's audit ids" \
         "ok    R7 cash in hand on the live book, read-only — what the landing hero shows: UGX CASH IN HAND 850,000.00 · USD CASH IN HAND 0.00" \
         "ok    R7 the photo tables and files read exactly as before the deploy" \
         "ok    R8 the installed quotation reader reads a quotation shaped like 000181 as the form will: the kit, the plan, installation 150000, transport asked for — nothing read from uCRM, nothing written" \
         "ok    R9 the installed Inbox route, on the server's own Inbox settings: a sales chat → the sales number, an account chat → the account number, support / accounts / web → sendVia as before; the registry dark — nothing sent, nothing written" \
         "ok    R10 the installed lead path, as the workers will act on this server's switches: the assistant is asked to record leads on the sales number and on the support/account number; AiLeadService records a qualified enquiry as a lead in Sales -> Leads; UcrmLeadWorker settles a lead's sync as \"not synced — switched off\": nothing reaches uCRM — read-only, nothing written" \
         "the switches      ai_lead_capture on/on, ai_crm_lead_sync off/off (files/store) — as the operator decided on 07 Oct, leads ON and uCRM later (A4, V3e); ai_qualification on/on, ai_sales_on_all_numbers on/on; install_auth_enabled on/on, customer_wa_install_scheduled on/on — as the operator left them (V3b, V3c, R2)" \
         "migrations        none (086, 5.18.83's, still complete: R3)" \
         "checks            41 ok, 0 failed, 0 notes"; do
  check "$(has "$OUT" "$l")" "yes" "2: ${l:0:96}"
done
check "$(cnt "$OUT" 'ok    backed up ')" "5" "five backups: two databases, the data directory, the installed plugin, the vault"
check "$(cnt "$OUT" '  note  ')" "0" "no note: every switch and the Inbox's settings read as expected, nothing to say"
check "$(cnt "$OUT" 'rh-evo')$(cnt "$OUT" 'rh-key')$(cnt "$OUT" 'rh-sales')$(cnt "$OUT" 'rh-support')$(cnt "$OUT" 'rh-account')" "00000" "the log carries no Evolution address, key or instance name — yes/no only"
check "$(live)$(installed_digest "$PIN")" "${PIN}0" "the container serves $PIN: every changed file exactly as the commit has it"
check "$(inst_ver)" "5.18.87" "the installed manifest is 5.18.87"
check "$(grep -cF -- "$B0_PIN" "$PD/workers/AiReplyWorker.php")$(grep -cF -- "$B0_OLDPIN" "$PD/workers/AiReplyWorker.php")$(grep -cF -- "$B0_PO" "$PD/workers/UcrmLeadWorker.php")$(grep -cF -- "$B0_LOC" "$PD/lib/BrainContext.php")" "1011" \
  "the installed plugin carries Batch 0: the capture's pin from the context (5.18.86's \$ctx line gone), payloadOf(), the sales context's pin"
check "$(grep -cF -- "$IR_CALL" "$PD/includes/api/api_whatsapp.php")$(grep -cF -- "$CHSEND" "$PD/lib/NotificationService.php")$(grep -cF -- "$QP_READ" "$PD/includes/api/api_install_auth.php")$(grep -cF -- "$TECHCALL" "$PD/webhook.php")$(grep -cF -- "$WA_CALL" "$PD/webhook.php")$(grep -cF -- "$WA_CLAIM" "$PD/$WA_LIB")$(grep -cF -- "$RULE" "$PD/lib/InstallAuth.php")$(grep -cF -- "$NEVERQ" "$PD/lib/NotificationService.php")" "41111111" \
  "…and still 5.18.86's Inbox route, 5.18.85's quotation form, 5.18.84's booking WhatsApp and 5.18.83's authorisation, whole"
check "$(for f in partner_api.php lib/StaffApiCsrf.php lib/MediaPolicy.php lib/InboundMedia.php migrations/085_wa_media.sql migrations/087_wa_channels.sql tools/channels.php workers/MediaWorker.php; do test -f "$PD/$f" && echo "$f"; done | wc -l | tr -d ' ')" "0" "no partner-portal, CSRF, AI media-layer or Batch 1 file installed"
check "$(grep -cF 'EvolutionApiService::forStore(' "$PD/evo_webhook.php")$(grep -cF '_epWorkerOwned' "$PD/cron/event_processor.php")$(grep -cF 'replyRoleOrRefuse' "$PD/workers/AiReplyWorker.php")$(grep -cF 'VoiceTranscription' "$PD/workers/AiReplyWorker.php")" "0000" \
  "the webhook and the event processor are 5.18.86's; the AI worker carries Batch 0 alone — no registry route, no media path"
check "$(ia_objects)$(mig_row)$(ia_rows)" "5:10:410:1:1:1:0" "086 is as it was: complete, one ledger row, production's records"
check "$(data_digest)" "$D1" "the deploy wrote no record and no configuration value — every table (086's included), the vault and the configuration files as step 0 left them"
check "$(grep -c "cd $REPO && bash scripts/deploy-5.18.87.sh --rollback" <<<"$OUT")" "1" "the rollback command is printed once, on its own line, never beside the deploy"
check "$(awk '/PASSED\. Send this LOG FILE back/ {p=1} p && /deploy-5\.18\.87\.sh --rollback/ {print "after"; exit}' <<<"$OUT")" "after" \
  "…the rollback block comes after the verdict, at the end of the log, never in the block the operator pasted"
check "$(grep -cE -- "$SWITCH_RX" <<<"$OUT")" "0" "no repair command and no switch command — not for a feature, the registry or a lead switch — anywhere in the log"
BK="$(ls -d "$SB/out"/backup-* | tail -1)"
check "$(tar -xzOf "$BK"/plugin-installed-5.18.86.tar.gz $P/manifest.json | grep -c '"version": "5.18.86"')" "1" "the code backup is 5.18.86"
check "$(tar -xzOf "$BK"/plugin-installed-5.18.86.tar.gz $P/workers/AiReplyWorker.php | grep -cF -- "$B0_OLDPIN")" "1" "…and holds 5.18.86's AI worker, its \$ctx line included: a rollback restores 5.18.86 exactly"

echo; echo "== 3. R1, R3, R5, R6 and R10 have teeth =="
cp "$PD/workers/AiReplyWorker.php" "$SB/worker.aside"
git -C "$REPO" show "$BASE:$P/workers/AiReplyWorker.php" > "$PD/workers/AiReplyWorker.php"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R1 installed files that differ: workers/AiReplyWorker.php')$(has "$OUT" 'ai-worker:capture-pin')$(has "$OUT" 'ai-worker:5.18.86-defect-still-present')" "yesyesyes" \
  "3a 5.18.86's AI worker back on the server: R1 names the file, R6 the missing capture pin and the \$ctx line"
cp "$SB/worker.aside" "$PD/workers/AiReplyWorker.php"
cp "$PD/workers/UcrmLeadWorker.php" "$SB/leadworker.aside"
git -C "$REPO" show "$BASE:$P/workers/UcrmLeadWorker.php" > "$PD/workers/UcrmLeadWorker.php"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R1 installed files that differ: workers/UcrmLeadWorker.php')$(has "$OUT" 'lead-worker:no-payloadOf')$(has "$OUT" 'lead-worker:payloadOf×0(want 2)')" "yesyesyes" \
  "3b 5.18.86's uCRM lead worker back: R1 names the file and R6 the missing payloadOf()"
cp "$SB/leadworker.aside" "$PD/workers/UcrmLeadWorker.php"
printf '\n// changed on the server\n' >> "$PD/$IR_LIB"; OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R5 files from Release A→5.18.86 differ on the server: $IR_LIB")" "yes" "3c a 5.18.86 file changed on the server: R5 fails, naming it — the live Inbox route is regression-checked"
git -C "$REPO" show "$PIN:$P/$IR_LIB" > "$PD/$IR_LIB"
check "$(sqx 'DROP TRIGGER install_auth_never_deleted')" "done" "control: one trigger dropped from 086"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R3 migration 086 is recorded but not complete: incomplete:install_auth_never_deleted')" "yes" "3d R3 fails, naming the missing trigger — the ledger row alone is not trusted"
php -r '$p = new PDO("sqlite:" . $argv[1]); $sql = (string)file_get_contents($argv[2]);
  if (!preg_match("/CREATE TRIGGER IF NOT EXISTS install_auth_never_deleted.*?\nEND;/s", $sql, $m)) { fwrite(STDERR, "trigger not found\n"); exit(1); }
  $p->exec($m[0]); echo "restored";' "$DATA/plugin.sqlite3" "$PD/$MIG" >/dev/null
python3 - "$PD/workers/UcrmLeadWorker.php" "$B0_DECIDED" <<'PY'
import sys
p, old = sys.argv[1], sys.argv[2]
s = open(p).read()
assert s.count(old) == 1, 'anchor'
open(p, 'w').write(s.replace(old, old.replace("['disabled', 'skipped']", "['skipped']")))
PY
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R1 installed files that differ: workers/UcrmLeadWorker.php')$(has "$OUT" 'UcrmLeadWorker:switched-off-retried')" "yesyes" \
  "3e a uCRM worker that would retry a switched-off sync: R1 names the file and R10 the rule it lost"
git -C "$REPO" show "$PIN:$P/workers/UcrmLeadWorker.php" > "$PD/workers/UcrmLeadWorker.php"
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" '5.18.87 (after): PASSED')$(ia_objects)" "0yes5:10:4" "3f each fault removed: --after-only PASSES again"

echo; echo "== 4. V3/R2 have teeth: the pilot switch is read live =="
flip --off; OUT="$(run --after-only)"
check "$(has "$OUT" 'the pilot reads off')$(has "$OUT" "ok    R2 the installed set_distributors --show reports pilot=off")$(fails "$OUT")" "yesyes0" "4a pilot turned off: V3/R2 read off, still PASSES"
flip --on; OUT="$(run --after-only)"
check "$(has "$OUT" 'the pilot reads on')$(has "$OUT" 'ok    R2 the installed set_distributors --show reports pilot=on')$(fails "$OUT")" "yesyes0" "4b pilot turned back on: V3/R2 read on again, PASSES"

echo; echo "== 5. the switches after the deploy: the uCRM write on as its own step, then off; copies apart; qualification off =="
D3="$(data_digest)"
S1="$(setcfg ai_crm_lead_sync 1)"; OUT="$(run --after-only)"
check "$(has "$S1" 'ai_crm_lead_sync = 1')$(fails "$OUT")$(has "$OUT" '5.18.87 (after): PASSED')" "yes0yes" "5a the uCRM write switched on, as its own step, with the same tool: --after-only PASSES"
check "$(has "$OUT" "UcrmLeadWorker creates or links a lead client in uCRM for each lead's sync it receives; 5.18.86's event processor can acknowledge a sync as an unknown type before the worker sees it, and that lead then does not reach uCRM (docs/65 §Z.5) — read-only, nothing written")$(has "$OUT" 'ok    V3e the lead switches are unchanged by this run (lc=on/on ls=on/on qu=on/on sa=on/on; files/store)')" "yesyes" \
  "5b R10 reads it live: leads now reach uCRM, and it says that 5.18.86's event processor can take a sync first; V3e reads ls=on/on"
S2="$(setcfg ai_crm_lead_sync 0)"; OUT="$(run --after-only)"
check "$(has "$S2" 'ai_crm_lead_sync = 0')$(fails "$OUT")$(has "$OUT" 'settles a lead'"'"'s sync as "not synced — switched off": nothing reaches uCRM')$(data_digest)" "yes0yes$D3" \
  "5c switched off again: R10 says nothing reaches uCRM, and the configuration is byte for byte as before 5a"
cfg_keep; row_unset ai_lead_capture; OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  V3e the lead switches CHANGED across this run, could not be read, or their two copies disagree (files/store): lc:copies-disagree(on/absent)')" "yes" \
  "5d V3e has teeth: lead capture's two copies apart (the files on, the store row silent) — V3e fails, naming it"
cfg_back
cfg_set ai_qualification off; OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" 'the assistant is asked to record leads nowhere while ai_qualification is off')$(has "$OUT" 'qu=absent/absent')" "0yesyes" \
  "5e ai_qualification off: --after-only PASSES, and R10 says the assistant is asked to record no lead anywhere"
cfg_back
cfg_set customer_wa_install_scheduled off; OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" 'ok    V3c customer_wa_install_scheduled is unchanged by this run (before=wa=absent/absent, after=wa=absent/absent; files/store) — OFF in both: no customer is sent the booking WhatsApp, as the operator left it')" "0yes" \
  "5f the booking WhatsApp switched off: --after-only PASSES and V3c says so"
cfg_back
row_unset evo_instance_account; OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R9 the installed Inbox route answered: sales:channel support:sender/support account:channel accounts:sender/accounts web:sender/support | registry=off sales=yes account=no')" "yes" \
  "5g R9 still has teeth: the account number taken out of the Inbox's settings row — R9 fails, naming account=no"
cfg_back
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" '5.18.87 (after): PASSED')$(data_digest)" "0yes$D3" "5h everything put back byte for byte: --after-only PASSES, the data exactly as before 5a"

echo; echo "== 6. the rollback: --rollback, typed ROLLBACK (every switch as the operator left it) =="
D2="$(data_digest)"; fresh; OUT="$(run --answer ROLLBACK --rollback)"
check "$(fails "$OUT")$(has "$OUT" '5.18.87 (rollback): PASSED')" "0yes" "PASSED"
for l in "note  after the rollback the AI's leads are lost again, as on 5.18.86: every capture fails as \"lead capture failed\" and every uCRM sync is dropped; leads already recorded stay in Sales -> Leads. The Inbox, the authorisation, the booking WhatsApp and the request form are 5.18.86's, unchanged by this release" \
         "GO — evidence recorded" "checking out $BASE (5.18.86) for the documented deploy" "ok    container serves $BASE (5.18.86)" \
         "ok    V6 the sign-in page allows pinch-zoom" \
         "ok    V3b install_auth_enabled is unchanged by this run (before=ia=on/on, after=ia=on/on; files/store) — ON in both" \
         "ok    V3c customer_wa_install_scheduled is unchanged by this run (before=wa=on/on, after=wa=on/on; files/store) — ON in both: the booking WhatsApp stays live, as the operator left it; 5.18.86 sends it too, naming the technician" \
         "ok    V3d the settings row the Inbox reads is unchanged by this run" \
         "ok    V3e the lead switches are unchanged by this run (lc=on/on ls=off/off qu=on/on sa=on/on; files/store)" \
         "ok    RB the installed manifest says 5.18.86" \
         "ok    RB the installed plugin is 5.18.86's again: Batch 0 is gone — the AI's leads fail as \"lead capture failed\" and every uCRM sync is dropped, as before" \
         "ok    RB 5.18.86's Inbox route is whole: all four Inbox sends through it, the channel send, forStore()" \
         "ok    RB 5.18.85's request form from the quotation and the technician's name are whole" \
         "ok    RB 5.18.84's booking WhatsApp is whole: the class with its switch and once-per-job claim, its call in job.add, its setting" \
         "ok    RB 5.18.83's Customer Installation Authorisation is whole: the one rule, the page behind its gate, the four guards, the link never queued, its switch in the tool" \
         "ok    RB migration 086 (5.18.83's) is still applied and complete" \
         "ok    RB the 5.18.74 hand-off setting and \"How to pay\" are still there" \
         "ok    RB the 5.18.72 Staff Cashbooks wording is still there" \
         "ok    RB the 5.18.71 landing hero and cash-in-hand tool are still there" \
         "ok    RB the 5.18.70 Field Register fix and records tool and the 5.18.69 Manual Entry stamp are still there" \
         "ok    RB the 5.18.68 staff-cash chain and card are still there" \
         "ok    RB the 5.18.66 photo surface and the 5.18.67 card are still there" \
         "note  RB the code is 5.18.86 again" \
         "Files 5.18.87 added stay on disk, reached by nothing" \
         "Leads already recorded stay in Sales -> Leads; their sync events stay settled"; do
  check "$(has "$OUT" "$l")" "yes" "6: ${l:0:90}"
done
check "$(has "$OUT" "ok    backed up the installed plugin (5.18.87, $PIN)")" "yes" "the backup first — of 5.18.87's code"
check "$(live)$(installed_digest "$BASE")" "${BASE}0" "the container serves $BASE: every changed file as 5.18.86 has it"
check "$(inst_ver)" "5.18.86" "the installed manifest is 5.18.86 again"
check "$(grep -cF -- "$B0_PIN" "$PD/workers/AiReplyWorker.php")$(grep -cF -- "$B0_OLDPIN" "$PD/workers/AiReplyWorker.php")$(grep -cF -- "$B0_PO" "$PD/workers/UcrmLeadWorker.php")$(grep -cF -- "$IR_CALL" "$PD/includes/api/api_whatsapp.php")$(grep -cF -- "$CHSEND" "$PD/lib/NotificationService.php")$(grep -cF -- "$RULE" "$PD/lib/InstallAuth.php")" "010411" \
  "after the rollback: Batch 0 gone (the \$ctx line back, no payloadOf()) — and 5.18.86's Inbox route and 5.18.83's authorisation whole"
check "$(test -f "$PD/tests/test_lead_switches.php" && echo yes || echo no)" "yes" "the test 5.18.87 added stays on disk (deploy-hybrid.sh never deletes) — reached by nothing"
check "$(standin_page install_auth)$(ia_objects)$(ia_rows)" "4045:10:40:1:1:1:0" "after the rollback the live authorisation still refuses a request without a link; 086 and its records as they were"
check "$(data_digest)$(ledger_rows)" "${D2}2" "the rollback changed no data — the seeded ledger still holds its two rows, every switch as it was"

echo; echo "== 7. weakened copies of the script must be caught (control on the control) =="
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
# A4 blinded, on the base (5.18.86 live) with the uCRM write still ON, as production has it before step 0: answered NO.
install_base; seed_data; vault UGX; fresh; flip --on
MUT="$(mutant a4 '    stop "NO-GO: $LS_KEY reads' '    true "NO-GO: $LS_KEY reads')"
OUT="$(run --answer NO --script "$MUT")"
check "$(has "$OUT" 'STOP: NO-GO: ai_crm_lead_sync reads')$(has "$OUT" 'STOP: not confirmed')$(live)" "noyes$BASE" "7a the A4-blinded copy goes on past the uCRM write to the DEPLOY prompt (answered NO) — the mutation is detected"
fresh; OUT="$(run --answer NO)"
check "$(has "$OUT" "STOP: NO-GO: ai_crm_lead_sync reads 'ls=on/on'")$(backups)$(live)" "yes0$BASE" "7b …the real script stops at A4 on the same switches: no backup, nothing deployed"
rm -f "$MUT"
setcfg ai_crm_lead_sync 0 >/dev/null
fresh; OUT="$(run --answer DEPLOY)"
check "$(fails "$OUT")$(has "$OUT" '5.18.87 (deploy): PASSED')" "0yes" "control: step 0 again, a fresh base deployed — PASSED"
MUT="$(mutant r6 "grep -qF '\$this->latestPin(\$convId, \$ctx));' \"\$DEST/workers/AiReplyWorker.php\" 2>/dev/null && R6_BAD=\"\$R6_BAD ai-worker:5.18.86-defect-still-present\"" 'true')"
git -C "$REPO" show "$BASE:$P/workers/AiReplyWorker.php" > "$PD/workers/AiReplyWorker.php"
OUT="$(run --script "$MUT" --after-only)"
check "$(has "$OUT" 'ai-worker:5.18.86-defect-still-present')$(has "$OUT" 'FAIL  R1 installed files that differ: workers/AiReplyWorker.php')" "noyes" \
  "7c the R6-blinded copy no longer names the \$ctx line with 5.18.86's AI worker installed (R1 still sees the file) — the mutation is detected"
OUT="$(run --after-only)"
check "$(has "$OUT" 'ai-worker:5.18.86-defect-still-present')$(has "$OUT" 'FAIL  R1 installed files that differ: workers/AiReplyWorker.php')" "yesyes" "7d …and the real script names it"
git -C "$REPO" show "$PIN:$P/workers/AiReplyWorker.php" > "$PD/workers/AiReplyWorker.php"; rm -f "$MUT"
MUT="$(mutant v3e 'sw_off "$sw" || sw_both_on "$sw" || V3E_BAD="$V3E_BAD ${sw%%=*}:copies-disagree(${sw#*=})"' 'true')"
cfg_keep; row_unset ai_lead_capture
OUT="$(run --script "$MUT" --after-only)"
check "$(has "$OUT" 'copies-disagree')$(has "$OUT" 'ok    V3e the lead switches are unchanged by this run (lc=on/absent')" "noyes" "7e the V3e-blinded copy passes lead capture's two copies apart — the mutation is detected"
OUT="$(run --after-only)"
check "$(has "$OUT" 'lc:copies-disagree(on/absent)')" "yes" "7f …and the real script names it"
cfg_back; rm -f "$MUT"
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" '5.18.87 (after): PASSED')" "0yes" "7g everything restored: --after-only PASSES"

echo; echo "== 8. what the rehearsal left behind =="
check "$(checkout_state)" "$CHECKOUT0" "this checkout is as the rehearsal found it: same commit, same tracked files"
check "$(ls "$REPO/scripts"/.mutant-* "$REPO/scripts"/.unpinned.sh "$REPO/scripts"/.tip.sh "$REPO/scripts"/.stray.sh "$REPO/scripts"/.stray2.sh "$REPO/scripts"/.short.sh 2>/dev/null | wc -l | tr -d ' ')" "0" "no weakened copy is left in the clone's scripts/"
check "$(grep -c 'Fatal\|Parse error' "$SB/web.log")" "0" "the stand-in served every request without a PHP fatal"

echo; echo "rehearsal: $PASS passed, $FAILN failed ($(cat "$SB/runs") runs of the script)"
[ "$FAILN" = "0" ]
