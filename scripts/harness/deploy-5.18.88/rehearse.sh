#!/usr/bin/env bash
# Rehearse scripts/deploy-5.18.88.sh — the deploy, its checks, and the ROLLBACK — before the operator runs any of it.
#
# 5.18.88 over 5.18.87: the remaining Batch 1 release — the rest of multi-number Batch 1 (b1865ea, docs/65) without the AI
# media layer. On Uganda the event processor stops taking the workers' events (ai.reply, crm.lead.sync) and settles
# wa.escalation as known; migration 087 adds the channel registry, dark — three department rows, no instance stored; the
# webhook, the AI worker, the follow-ups and set_config.php carry the routing by channel behind
# multi_number_channels_enabled, which ships OFF. South Sudan keeps 5.18.87's loop exactly. Deployed from a RELEASE COMMIT
# on release/5.18.88, cut on the live 5.18.87 release commit (9cc81af), not from the branch tip, which also carries the
# partner portal, the PD-8 CSRF guard and the AI media layer. The base is installed AS PRODUCTION RUNS IT ON 07 OCT after
# 5.18.87's deploy: 5.18.87, migration 086 applied by the plugin's own runner, the authorisation and the booking WhatsApp
# ON in both copies, the Inbox's settings row naming Evolution and the three numbers — the support and account numbers
# sharing one instance, as production's do — lead capture ON and the uCRM write OFF in both copies, a live event queue
# and job photos. This rehearsal proves:
#   · the pin is exactly the release: cut on 5.18.87, 18 files (5 added, 13 changed), one migration — the reviewed 087 —
#     six files Batch 1's byte for byte, nothing of the media layer, the portal or the CSRF guard, and Domain B untouched;
#   · stage A refuses, before anything changes, each of the eight refusals: a server not on 5.18.87 (by commit or by
#     version); the registry's switch ON (either copy); 087 already recorded; 087's objects half there; a pin carrying a
#     file that is not the release's (a media-layer file, a second migration, an altered 087); a pin that would change
#     South Sudan's event loop or leave Uganda's unprotected; a pin touching Domain B or anything outside the plugin; a
#     backup that does not complete — and a pin whose code would route the three numbers differently (A9);
#   · the deploy then runs end to end and PASSES: 087 applied by the plugin's own runner on its first request and verified
#     object by object, its three rows with no instance; the registry dark and never consulted (R11); the three numbers
#     routing exactly as on 5.18.87 (A9, R12); the event processor protected on Uganda and 5.18.87's on South Sudan (A6,
#     R13); the event queue snapshotted and nothing lost; Domain B byte for byte (R14); every live feature and switch as it
#     was; the log free of every Evolution address, key and instance name; no data written but 087's;
#   · R3, R11, R12, R13, R14, V3f, V3g have teeth, and so does each regression check carried over;
#   · PART 4, the two defects of 5.18.87's deploy, with their controls: R7 accepts photos taken during the run and fails a
#     loss, where 5.18.87's R7 fails the growth; V4 in --after-only mode reads the log in its own time format and finds a
#     fatal planted after the deploy, where 5.18.87's compact stamp read no line and passed;
#   · the rollback returns the plugin to 5.18.87: Batch 1 gone, Batch 0 kept, 087's tables still there and never read by
#     5.18.87 with the switch off — and the recording handle that proves it does see the reads when the switch is on —
#     the switch still OFF, the numbers routing as before, the queue whole, every switch as it was; no data changed;
#   · weakened copies of the script are caught — the control on the control;
#   · the clone, /tmp and the stand-in are left as found.
#
# Everything runs in a sandbox: a fake `docker` that maps /data/ucrm to a directory here (and supports exec, cp, logs), a
# clone of this checkout, the plugin installed as 5.18.87 exactly (git archive of the release commit), and a stand-in web
# server for stage V's pages that boots the INSTALLED plugin's store on every request — as the real one does, so 087 is
# applied by the plugin's own runner. DEPLOY and ROLLBACK are typed through a pseudo-terminal, as the operator types them.
# It never touches this checkout, the server or the network, and refuses to run where the server could be. The Evolution
# settings seeded are fictitious and never contacted.
#
#   bash scripts/harness/deploy-5.18.88/rehearse.sh            REHEARSE_KEEP=<dir> keeps every run's full output
set -u
R="$(cd "$(dirname "$0")/../../.." && pwd)"
for f in /data/ucrm /opt/dishnet /var/run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
BASE=9cc81af; RA_BASE=e076632; OLDER=c2c96e1; BRANCH_FILE=scripts/deploy-5.18.88.sh; P=dishnet-hybrid-sudan; RELEASE_BRANCH=release/5.18.88
B1=b1865ea                                                                                       # multi-number Batch 1, on the branch
MIG=migrations/087_wa_channels.sql; MIG86=migrations/086_install_authorisation.sql
MIG_SHA=3feda1b44ca79c1ad0057f029c032b741a581f4383e7378480129db1f1385aaa                           # the reviewed 087
LISTS="\$_epWorkerOwned = \$_epUg ? ['ai.reply', 'crm.lead.sync'] : ['ai.reply'];"                 # 5.18.88's two lists
FS_WH='$evo     = EvolutionApiService::forStore($config, $pdo, $dataDir);'                            # the webhook's and the follow-ups'
FS_W='$this->evo   = EvolutionApiService::forStore($config, $this->pdo, $dataDir);'                   # the AI worker's
EXCL="public function consume(int \$limit = 20, string \$workerId = '', array \$types = [], array \$excludeTypes = []): array"
ORIGIN='public function withOrigin(?array $origin): self'
FLAGLINE="'multi_number_channels_enabled' => ['bool',"
B0_PIN='$this->latestPin($convId, $context));'                         # 5.18.87's Batch 0: the capture's pin from the context
B0_PO='private static function payloadOf(array $event): array'        # 5.18.87's Batch 0: the uCRM worker reads its payload
IR_CALL="InboxReplyRoute::decide((string)(\$conv['channel'] ?? ''), (array)\$config, \$dataDir, \$store->getPdo());"   # ×4, 5.18.86's
RULE='public static function decision(\PDO $pdo, array $config, array $job): array'   # 5.18.83's one rule — live, must survive
WA_CALL='            whInstallScheduledWhatsApp($jobId, is_array($job) ? $job : [], is_array($client ?? null) ? $client : [],'
QP_READ="\$iaQList = \$iaQCrm->get('billing/quotes?' . http_build_query(['clientId' => \$cid]));"
SS_SIG="crm.lead.sync=done/0+unknown ai.reply=pending/0 ai.media=done/0+unknown wa.escalation=done/0+unknown install.ready=done/0+unknown"
UG_SIG="crm.lead.sync=pending/0 ai.reply=pending/0 ai.media=done/0+unknown wa.escalation=done/0 install.ready=done/0+unknown"
SHAPE="sales:in=sales support:in=support account:in=support shared=support+account evo=yes registry=off"   # production's: support and account share
EXPECT_N=18; EXPECT_AD=5
checkout_state() { { git -C "$R" rev-parse HEAD; git -C "$R" status --porcelain --untracked-files=no; git -C "$R" diff HEAD; } | sha256sum | cut -c1-16; }
CHECKOUT0="$(checkout_state)"
left_tmp() { ls -d /tmp/dnb-5.18.88-* 2>/dev/null | wc -l | tr -d ' '; }
TMP0="$(left_tmp)"
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
notes() { grep -cE '^  note  ' <<<"$1"; }

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
case "$PIN" in __*) echo "  FAIL the script still carries the placeholder pin ($PIN)"; exit 2;; esac
REL="$(git -C "$REPO" rev-parse --short "origin/$RELEASE_BRANCH" 2>/dev/null || echo none)"
check "$PIN" "$REL" "the script is pinned to the release commit on $RELEASE_BRANCH ($PIN)"
check "$(git -C "$REPO" rev-parse --short "$PIN^" 2>/dev/null)" "$BASE" "control: the release commit is cut on $BASE (5.18.87), the live version"
ver() { git -C "$REPO" show "$1:$P/manifest.json" | python3 -c 'import json,sys; print(json.load(sys.stdin)["information"]["version"])'; }
check "$(ver "$PIN")" "5.18.88" "the plugin at the pin is 5.18.88"
check "$(ver "$BASE")" "5.18.87" "control: the base is 5.18.87"
dl() { git -C "$REPO" diff --no-renames --name-only --diff-filter="$1" "$2" "$3" -- $P; }
CHANGED="$(dl AM "$BASE" "$PIN")"
N_CH="$(grep -c . <<<"$CHANGED")"; N_AD="$(dl A "$BASE" "$PIN" | grep -c .)"; N_MO=$((N_CH - N_AD)); N_DEL="$(dl D "$BASE" "$PIN" | grep -c .)"
echo "  5.18.88   $N_CH files: $N_MO changed, $N_AD added · $N_DEL removed or renamed"
check "$N_DEL" "0" "control: 5.18.87 → 5.18.88 removes no file"
check "$N_AD" "$EXPECT_AD" "control: 5.18.88 adds exactly $EXPECT_AD files"
check "$N_CH" "$EXPECT_N" "control: $EXPECT_N files in the delta"
for want in cron/event_processor.php cron/followup_send.php evo_webhook.php lib/AiLeadService.php lib/EventBus.php manifest.json \
            tools/set_config.php workers/AiReplyWorker.php "$MIG" tools/channels.php tests/test_channel_registry.php \
            tests/test_event_processor_protected.php tests/test_multi_number_routing.php tests/test_distributor_apply.php \
            tests/test_distributor_link_ucrm.php tests/test_distributor_notify.php tests/test_distributor_registry.php tests/test_distributor_territory.php; do
  check "$(grep -c "^$P/$want$" <<<"$CHANGED")" "1" "control: the delta includes $want"
done
check "$(grep -c "^$P/migrations/" <<<"$CHANGED")$(git -C "$REPO" show "$PIN:$P/$MIG" | sha256sum | cut -c1-64)" "1$MIG_SHA" "control: exactly one migration in the delta — 087, the reviewed file (sha256 ${MIG_SHA:0:16}…)"
check "$(grep -cE "^$P/(partner_api\.php|lib/Partner|lib/StaffApiCsrf\.php|lib/Totp\.php|lib/DistributorPortalData\.php|lib/Media|lib/InboundMedia|lib/Voice|lib/Image|lib/Document|lib/Pdf|workers/MediaWorker|run_media_worker|dishnet-mikrotik-control-plane/|docs/)" <<<"$CHANGED")" "0" \
  "control: no partner-portal, CSRF or AI media-layer file in the delta, and nothing of Domain B or the plugin's docs"
check "$(git -C "$REPO" diff --name-only "$BASE" "$PIN" | grep -vc "^$P/")" "0" "control: the whole repository's delta is the plugin's files alone"
check "$(git -C "$REPO" rev-parse "$BASE:$P/dishnet-mikrotik-control-plane")$(git -C "$REPO" rev-parse "$BASE:$P/docs")" \
      "$(git -C "$REPO" rev-parse "$PIN:$P/dishnet-mikrotik-control-plane")$(git -C "$REPO" rev-parse "$PIN:$P/docs")" "control: Domain B's tree and the plugin's docs are the same trees at the base and the pin"
same_as_b1() { [ "$(git -C "$REPO" rev-parse "$PIN:$P/$1" 2>/dev/null)" = "$(git -C "$REPO" rev-parse "$B1:$P/$1" 2>/dev/null)" ] && echo same || echo differs; }
check "$(for f in cron/followup_send.php lib/AiLeadService.php lib/EventBus.php "$MIG" tools/channels.php tests/test_channel_registry.php; do same_as_b1 "$f"; done | tr '\n' ' ')" \
  "same same same same same same " "control: the six files taken whole are Batch 1's ($B1) byte for byte"
at() { git -C "$REPO" show "$1:$P/$2" 2>/dev/null | grep -cF -- "$3"; }
check "$(at "$PIN" cron/event_processor.php "$LISTS")$(at "$PIN" cron/event_processor.php 'ai.media')$(at "$PIN" evo_webhook.php "$FS_WH")$(at "$PIN" cron/followup_send.php "$FS_WH")$(at "$PIN" workers/AiReplyWorker.php "$FS_W")$(at "$PIN" lib/EventBus.php "$EXCL")$(at "$PIN" lib/AiLeadService.php "$ORIGIN")$(at "$PIN" tools/set_config.php "$FLAGLINE")" "10111111" \
  "control: at the pin Batch 1 is in place — the two lists (no ai.media anywhere in the processor), forStore() in the webhook, the follow-ups and the AI worker, EventBus's exclusion, the lead's origin, the switch in set_config.php"
check "$(at "$PIN" evo_webhook.php 'MediaPolicy')$(at "$PIN" workers/AiReplyWorker.php 'VoiceTranscription')$(at "$PIN" tools/set_config.php "'ai_media_enabled'")" "000" "control: and no AI media layer at the pin — no media branch in the webhook, no media path in the AI worker, no media switch"
check "$(at "$PIN" workers/AiReplyWorker.php "$B0_PIN")$(at "$PIN" workers/UcrmLeadWorker.php "$B0_PO")$(at "$PIN" includes/api/api_whatsapp.php "$IR_CALL")$(at "$PIN" lib/InstallAuth.php "$RULE")$(at "$PIN" webhook.php "$WA_CALL")$(at "$PIN" includes/api/api_install_auth.php "$QP_READ")" "114111" \
  "control: 5.18.87's Batch 0, 5.18.86's Inbox route, 5.18.85's quotation read, 5.18.84's booking WhatsApp and 5.18.83's authorisation are whole at the pin"
check "$(at "$BASE" cron/event_processor.php "$LISTS")$(at "$BASE" evo_webhook.php "$FS_WH")$(at "$BASE" workers/AiReplyWorker.php "$FS_W")" "000" "control: 5.18.87 has none of it"
HEADER_CMD="$(sed -n '/^# Run as root/,/^# The rollback is a separate command/p' "$DEPLOY")"
check "$(grep -c 'deploy-5.18.88.sh 2>&1 | tee' <<<"$HEADER_CMD")$(grep -cE -- '--rollback|--key|--value|set_config|git checkout [0-9a-f]{7}' <<<"$HEADER_CMD")" "10" \
  "the header's deploy command stands alone: no rollback, no switch command and no checkout of another commit in its block (docs/44 §16.9)"
check "$(grep -c "git fetch origin $RELEASE_BRANCH" <<<"$HEADER_CMD")" "1" "the header's deploy command fetches the release branch"
SWITCH_RX='--key +(customer_wa_install_scheduled|install_auth_enabled|multi_number_channels_enabled|ai_lead_capture|ai_crm_lead_sync|ai_qualification|ai_sales_on_all_numbers)|(customer_wa_install_scheduled|install_auth_enabled|multi_number_channels_enabled|ai_lead_capture|ai_crm_lead_sync|ai_qualification|ai_sales_on_all_numbers) +--value'
check "$(grep -cE -- "$SWITCH_RX" "$DEPLOY")" "0" "the script carries no command that switches a feature, the registry or a lead switch — neither on nor off"

# ── The container: 5.18.87 installed AS PRODUCTION RUNS IT, its data beside it ─
MOUNT="$SB/mount"; PLUGINS="$MOUNT/ucrm/data/plugins"; PD="$PLUGINS/$P"; DATA="$PLUGINS/.$P-data"
VAULT="$PLUGINS/.dishnet-sudan.vault.json"
mkdir -p "$PD" "$DATA" "$SB/web" "$SB/bin" "$SB/out"
IN_CONTAINER="/data/ucrm/data/plugins/$P"
free_port() { python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()'; }
WPORT="$(free_port)"; PLUGIN_BASE="http://127.0.0.1:$WPORT/public.php"
printf '%s\n%s\n%s\n%s\n' "$PD" "$DATA" "$SB/container.log" "$SB/web" > "$SB/web/.installed_plugin"
# The stand-in public.php. On every request it boots the INSTALLED plugin's store, as the real public.php does — so the
# plugin's own runner applies 087 on the first request after the copy. ?page=install_auth runs the INSTALLED page itself;
# the sign-in page carries the installed viewport line. A test drops a trigger file to make something happen DURING a run,
# on its first request: a photo taken (.add_photo), a photo lost (.del_photo), the Inbox's account number moved
# (.mutate_cfg), a fatal of the plugin in the container log (.plant_fatal). Each trigger is spent once.
cat > "$SB/web/public.php" <<'PHP'
<?php
[$root, $dataDir, $clog, $web] = array_map('trim', array_slice(explode("\n", (string)@file_get_contents(__DIR__ . '/.installed_plugin')), 0, 4));
foreach (['bootstrap_data', 'StoreInterface', 'JsonStore', 'SqliteStore'] as $l) require_once $root . "/lib/$l.php";
$store = SqliteStore::create($dataDir);
$pdo = $store->getPdo();
$spend = function (string $t) use ($web): bool { $f = $web . '/' . $t; if (!is_file($f)) return false; unlink($f); return true; };
if ($spend('.add_photo')) {
    @mkdir($dataDir . '/uploads/job_photos/20', 0777, true);
    file_put_contents($dataDir . '/uploads/job_photos/20/kit-1-during.jpg', 'jpeg');
    $pdo->exec("INSERT INTO job_photos(job_id, label, retailer_id, file_rel, mime, bytes, sha256) VALUES (20, 'kit', 1, 'uploads/job_photos/20/kit-1-during.jpg', 'image/jpeg', 4, 'x')");
}
if ($spend('.del_photo')) {
    $r = $pdo->query("SELECT id, file_rel FROM job_photos ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($r) { $pdo->exec('DELETE FROM job_photos WHERE id = ' . (int)$r['id']); @unlink($dataDir . '/' . $r['file_rel']); }
}
if ($spend('.mutate_cfg')) {
    $row = json_decode((string)$pdo->query("SELECT data FROM kyc_config WHERE id = 0")->fetchColumn(), true);
    $row['evo_instance_account'] = 'rh-moved';
    $pdo->prepare("UPDATE kyc_config SET data = ? WHERE id = 0")->execute([json_encode($row)]);
}
if ($spend('.plant_fatal')) {
    file_put_contents($clog, gmdate('Y-m-d\TH:i:s') . '.000000001Z PHP Fatal error:  Uncaught Error: planted by the rehearsal in /data/ucrm/data/plugins/dishnet-hybrid-sudan/cron/event_processor.php:1' . "\n", FILE_APPEND);
}
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

install_commit() {   # the installed plugin exactly as a commit has it
  find "$PD" -mindepth 1 -maxdepth 1 ! -name ucrm.json -exec rm -rf {} +
  git -C "$REPO" archive "$1:$P" | tar -x -C "$PD"
  printf '%s\n' "$1" > "$PD/.deployed-commit"
}
install_base() { install_commit "$BASE"; }
printf '{"pluginDataDir":"/data/ucrm/data/plugins/.%s-data","ucrmPublicUrl":"http://127.0.0.1:1/crm"}' "$P" > "$PD/ucrm.json"
vault() { printf '{"config":{"currency_code":"%s"}}' "$1" > "$VAULT"; }
sq() { php -r '$p = new PDO("sqlite:" . $argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $r = $p->query($argv[2]); echo $r ? implode(",", $r->fetchAll(PDO::FETCH_COLUMN)) : "";' "$DATA/plugin.sqlite3" "$1" 2>/dev/null || echo err; }
sqx() { php -r '$p = new PDO("sqlite:" . $argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $p->exec($argv[2]); echo "done";' "$DATA/plugin.sqlite3" "$1" 2>&1; }
cfg_set() {   # cfg_set KEY on|off [files|store]: a switch as set_config.php writes it — the override file AND the store row
              # ("files"/"store": that copy only, so the two disagree); off removes the key from both
  php -r '
    $key = $argv[1]; $mode = $argv[2]; $data = $argv[3]; $only = $argv[4] ?? "";
    $file = $data . "/kyc_config.json";
    $p = new PDO("sqlite:" . $data . "/plugin.sqlite3"); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $f = json_decode((string)file_get_contents($file), true); $r = json_decode((string)$p->query("SELECT data FROM kyc_config WHERE id = 0")->fetchColumn(), true);
    if ($mode === "on") { if ($only !== "store") $f[$key] = "1"; if ($only !== "files") $r[$key] = "1"; }
    else { unset($f[$key]); unset($r[$key]); }
    file_put_contents($file, json_encode($f)); $p->prepare("UPDATE kyc_config SET data = ? WHERE id = 0")->execute([json_encode($r)]);
    echo "done";
  ' "$1" "$2" "$DATA" "${3:-}" >/dev/null
}
seed_data() {      # the plugin's databases, written by 5.18.87's own store (084 and 086 applied by its runner); then production's
                   # 07 Oct state after 5.18.87's deploy: the authorisation and the booking WhatsApp ON in both copies, activation #1
                   # with job 20 exempt, the Inbox's settings row naming Evolution and the three numbers — support and account
                   # sharing one instance, as production's do (fictitious: never contacted) — lead capture ON and the uCRM write
                   # OFF in both copies, qualification and selling on every number ON; an event queue with work in it; two photos
  rm -rf "$DATA"/plugin.sqlite3* "$DATA"/dishnet.sqlite* "$DATA"/config.json "$DATA"/kyc_config.json "$DATA"/migration.log "$DATA"/uploads "$DATA"/webhook_log.json
  php -r '
    foreach (["bootstrap_data", "StoreInterface", "JsonStore", "SqliteStore", "EventBus"] as $l) require_once $argv[1] . "/lib/$l.php";
    require_once $argv[1] . "/lib/CashbookService.php";
    $s = SqliteStore::create($argv[2]);
    $s->save("kyc_config.json", ["crm_base_url" => "http://127.0.0.1:1", "company_name" => "DishNet Sandbox", "currency_code" => "UGX",
        "tenant_profile" => "uganda", "cashbook_base_currency" => "UGX", "cashbook_currencies" => "UGX,USD", "currency_symbol" => "UGX",
        "install_auth_job_titles" => "Starlink Installation", "install_auth_whatsapp" => "1", "ai_enabled" => "1", "ai_provider" => "openai",
        "evo_api_url" => "http://127.0.0.1:9/rh-evo", "evo_api_key" => "rh-key-secret", "evo_instance_sales" => "rh-sales",
        "evo_instance_support" => "rh-shared", "evo_instance_account" => "rh-shared"]);
    file_put_contents($argv[2] . "/kyc_config.json", json_encode($s->load("kyc_config.json")));
    $s->appendWithId("retailers.json", ["name" => "Rehearsal Tech", "email" => "tech@example.test", "role" => "support", "is_active" => true]);
    $cb = new CashbookService($s, $argv[2]);
    $cb->addEntryRaw(["project" => "dishnet", "date" => date("Y-m-d"), "direction" => "in", "amount" => 1000000, "currency" => "UGX",
        "category" => "Receipt", "category_raw" => "Receipt", "person" => "", "description" => "opening float", "status" => "approved", "source" => "manual"]);
    $cb->addEntryRaw(["project" => "dishnet", "date" => date("Y-m-d"), "direction" => "out", "amount" => 150000, "currency" => "UGX",
        "category" => "Staff Advance", "category_raw" => "Staff Advance", "person" => "Rehearsal Tech", "description" => "rehearsal", "status" => "approved", "source" => "manual"]);
    $p = $s->getPdo(); $bus = new EventBus($p);
    for ($i = 0; $i < 3; $i++) { $id = $bus->emit("wa.send", "test", $i, ["phone" => "", "message" => ""], 5, "rehearsal"); $bus->ack($id); }
    $bus->emit("ai.reply", "conversation", 7, ["channel" => "sales"], 3, "rehearsal");
    $f = $bus->emit("crm.lead.sync", "lead", 1, ["lead_id" => 1], 5, "rehearsal"); $p->exec("UPDATE events SET status = \x27failed\x27, attempts = 1, next_retry_at = datetime(\x27now\x27, \x27+1 hour\x27) WHERE id = $f");
    $d = $bus->emit("efris.submit", "invoice", 9, ["invoice_id" => 9], 5, "rehearsal"); $p->exec("UPDATE events SET status = \x27dead\x27, attempts = 5 WHERE id = $d");
    @mkdir($argv[2] . "/uploads/job_photos/20", 0777, true);
    foreach (["kit-1-a.jpg", "cable-1-b.jpg"] as $n) {
        file_put_contents($argv[2] . "/uploads/job_photos/20/" . $n, "jpeg");
        $p->prepare("INSERT INTO job_photos(job_id, label, retailer_id, file_rel, mime, bytes, sha256) VALUES (20, ?, 1, ?, \x27image/jpeg\x27, 4, \x27x\x27)")
          ->execute([explode("-", $n)[0], "uploads/job_photos/20/" . $n]);
    }
  ' "$PD" "$DATA" >/dev/null
  cfg_set install_auth_enabled on
  cfg_set customer_wa_install_scheduled on
  for k in ai_qualification ai_sales_on_all_numbers ai_lead_capture; do cfg_set "$k" on; done
  sqx "INSERT INTO install_auth_activations(activated_at, activated_by, jobs_read, exempted) VALUES ('2026-10-06 11:50:00', 'tools/set_config.php as root', 1, 1)" >/dev/null
  sqx "INSERT INTO install_auth_exempt(job_id, activation_id, crm_client_id, title, recorded_at) VALUES (20, 1, 0, 'Starlink Installation — Rehearsal Customer', '2026-10-06 11:50:00')" >/dev/null
  sqx "INSERT INTO install_auth_events(job_id, event, actor_kind, actor_id, detail, created_at) VALUES (20, 'INSTALLATION_EXEMPTED', 'system', NULL, 'activation:1;status:1', '2026-10-06 11:50:00')" >/dev/null
  php -r 'file_put_contents($argv[1], json_encode([
      ["id" => 2, "event" => "job.add", "message" => "Received UCRM webhook: job.add", "data" => [], "received_at" => "2026-10-07 09:26:31", "ip" => ""],
      ["id" => 1, "event" => "job.add", "message" => "Received UCRM webhook: job.add", "data" => [], "received_at" => "2026-10-06 15:02:11", "ip" => ""]]));' "$DATA/webhook_log.json"
}
inst_ver() { grep -o '"version": *"5[^"]*"' "$PD/manifest.json" | head -1 | sed -E 's/.*"(5[^"]*)".*/\1/'; }
live() { tail -n1 "$PD/.deployed-commit" 2>/dev/null | tr -cd '0-9a-f'; }
backups() { ls -d "$SB/out"/backup-* 2>/dev/null | wc -l | tr -d ' '; }
wa_objects() { sq "SELECT (SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('wa_channels','wa_channel_log')) || ':' || (SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name IN ('idx_wa_channels_instance','idx_wa_channels_number','idx_wa_channel_log_channel')) || ':' || (SELECT COUNT(*) FROM sqlite_master WHERE type='trigger' AND name IN ('wa_channel_log_append_only_update','wa_channel_log_append_only_delete','wa_channels_never_deleted'))"; }
wa_rows() { sq "SELECT channel_id || '/' || ifnull(evo_instance,'NULL') || '/' || status FROM wa_channels ORDER BY channel_id"; }
mig87() { sq "SELECT COUNT(*) FROM _migrations WHERE filename = '087_wa_channels.sql'"; }
ia_objects() { sq "SELECT (SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('install_auth','install_auth_events','install_auth_activations','install_auth_exempt','install_auth_rate')) || ':' || (SELECT COUNT(*) FROM sqlite_master WHERE type='trigger' AND name LIKE 'install_auth%') || ':' || (SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name IN ('idx_install_auth_client','idx_install_auth_status','idx_install_auth_events_job','idx_install_auth_rate'))"; }
ia_rows() { sq "SELECT (SELECT COUNT(*) FROM install_auth) || ':' || (SELECT COUNT(*) FROM install_auth_events) || ':' || (SELECT COUNT(*) FROM install_auth_activations) || ':' || (SELECT COUNT(*) FROM install_auth_exempt) || ':' || (SELECT COUNT(*) FROM install_auth_rate)"; }
_canon() { php -r 'function c($x){ if (is_array($x)) { ksort($x); foreach ($x as &$v) $v = c($v); } return $x; }
  echo json_encode(c(json_decode((string)@file_get_contents($argv[1]), true)));' "$1" 2>/dev/null; }
data_dump() {   # every table's rows but the ledger of migrations and 087's two tables; the vault; the configuration files; the photos
  php -r '$p = new PDO("sqlite:" . $argv[1]); $skip = ["_migrations", "sqlite_sequence", "wa_channels", "wa_channel_log"];
      foreach ($p->query("SELECT name FROM sqlite_master WHERE type = \x27table\x27 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if (in_array($t, $skip, true)) continue;
        echo $t, ":", json_encode($p->query("SELECT * FROM [$t]")->fetchAll(PDO::FETCH_NUM)), "\n"; }' "$DATA/plugin.sqlite3"
  echo "vault:$(_canon "$VAULT")"
  [ -f "$DATA/kyc_config.json" ] && echo "kyc:$(_canon "$DATA/kyc_config.json")"
  [ -f "$DATA/config.json" ] && echo "config:$(_canon "$DATA/config.json")"
  find "$DATA/uploads" -type f 2>/dev/null | sort
  true
}
data_digest() { data_dump | sha256sum | cut -c1-16; }
installed_digest() {   # $1 a commit: the number of files 5.18.88 touches that are NOT installed as that commit has them
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

# ── The fake docker: exec (with -i, -u and -e), cp into the container, logs ──
cat > "$SB/bin/docker" <<SH
#!/usr/bin/env bash
printf '%s\n' "\$*" >> "$SB/docker.log"
case "\$1" in
  inspect) echo "$MOUNT"; exit 0 ;;
  logs) cat "$SB/container.log" 2>/dev/null; exit 0 ;;
  cp) [ -n "\${FAKE_CP_FAIL:-}" ] && { echo "fake docker: cp refused (rehearsal control)" >&2; exit 1; }
      src="\$2"; dst="\${3#*:}"; case "\$dst" in /data/*) dst="$MOUNT/\${dst#/data/}" ;; esac
      cp -a "\$src" "\$dst"; exit \$? ;;
  exec) shift ;;
  *) echo "fake docker: \$1 is not supported" >&2; exit 1 ;;
esac
while [ \$# -gt 0 ]; do case "\$1" in
  -i) shift ;;
  -u) shift 2 ;;
  -e) kv="\$2"; v="\${kv#*=}"; case "\$v" in /data/*) v="$MOUNT/\${v#/data/}" ;; esac; export "\${kv%%=*}=\$v"; shift 2 ;;
  *) break ;;
esac; done
shift
args=(); for a in "\$@"; do case "\$a" in /data/*) args+=("$MOUNT/\${a#/data/}") ;; /usr/local/etc|/usr/local/etc/*) args+=("$SB/etc\${a#/usr/local/etc}") ;; *) args+=("\$a") ;; esac; done
exec "\${args[@]}"
SH
chmod +x "$SB/bin/docker"

run() {   # [--answer WORD] [--script FILE] [--env K=V]…, then the script's own options — through a pseudo-terminal
  local answer="" script="$DEPLOY" extra=()
  while :; do case "${1:-}" in --answer) answer="$2"; shift 2 ;; --script) script="$2"; shift 2 ;; --env) extra+=("$2"); shift 2 ;; *) break ;; esac; done
  : > "$SB/docker.log"
  local n; n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"
  local cmd; cmd="$(printf '%q ' env PATH="$SB/bin:$PATH" DNB_OUT="$SB/out" GUARD_SECONDS=0 DN_DATA_DIR="$DATA" ${extra[@]+"${extra[@]}"} bash "$script" --plugin-base "$PLUGIN_BASE" "$@")"
  local o; o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r')"
  if [ -n "${REHEARSE_KEEP:-}" ]; then
    local label; label="$(printf '%s-%s%s' "${answer:-none}" "$(basename "$script" .sh)" "$(printf '%s' "$*" | tr -c 'A-Za-z0-9-' '_')")"
    printf '%s\n' "$o" > "$REHEARSE_KEEP/run-$(printf '%02d' "$n")-$label.txt"
  fi
  printf '%s' "$o"
}

pinned_copy() {   # pinned_copy NAME COMMIT [SRC] — a copy of the script (or of SRC) pinned to another commit
  sed "s/^EXPECTED_PLUGIN_COMMIT=\"[^\"]*\"/EXPECTED_PLUGIN_COMMIT=\"$2\"/" "${3:-$DEPLOY}" > "$REPO/scripts/.$1.sh"; echo "$REPO/scripts/.$1.sh"
}
mutant() {   # mutant NAME SRC OLD NEW [OLD NEW]… — a copy of SRC with each OLD replaced, each exactly once
  local name="$1" src="$2"; shift 2
  python3 - "$src" "$REPO/scripts/.mutant-$name.sh" "$@" <<'PY'
import sys
src, dst, pairs = sys.argv[1], sys.argv[2], sys.argv[3:]
s = open(src).read()
for i in range(0, len(pairs), 2):
    old, new = pairs[i], pairs[i + 1]
    assert s.count(old) == 1, "mutant anchor not unique: " + old[:90]
    s = s.replace(old, new)
open(dst, 'w').write(s)
PY
  echo "$REPO/scripts/.mutant-$name.sh"
}
release_variant() {   # release_variant add|addroot|revert|edit PATH [OLD NEW] — a commit cut on 5.18.87 holding the release's tree
                      # with one file planted (in the plugin, or anywhere in the repository), put back as 5.18.87 has it, or edited
  local idx="$SB/variant.idx" tree c mode="$1" path="$2" blob=""
  GIT_INDEX_FILE="$idx" git -C "$REPO" read-tree "$PIN"
  case "$mode" in
    add)     GIT_INDEX_FILE="$idx" git -C "$REPO" update-index --add --cacheinfo "100644,$(printf '<?php // planted\n' | git -C "$REPO" hash-object -w --stdin),$P/$path" ;;
    addroot) GIT_INDEX_FILE="$idx" git -C "$REPO" update-index --add --cacheinfo "100644,$(printf 'planted\n' | git -C "$REPO" hash-object -w --stdin),$path" ;;
    revert)  GIT_INDEX_FILE="$idx" git -C "$REPO" update-index --cacheinfo "100644,$(git -C "$REPO" rev-parse "$BASE:$P/$path"),$P/$path" ;;
    edit)    blob="$(git -C "$REPO" show "$PIN:$P/$path" | python3 -c 'import sys
s = sys.stdin.read(); old, new = sys.argv[1], sys.argv[2]
assert s.count(old) == 1, "variant anchor not unique"
sys.stdout.write(s.replace(old, new))' "$3" "$4" | git -C "$REPO" hash-object -w --stdin)"
             GIT_INDEX_FILE="$idx" git -C "$REPO" update-index --cacheinfo "100644,$blob,$P/$path" ;;
  esac
  tree="$(GIT_INDEX_FILE="$idx" git -C "$REPO" write-tree)"; rm -f "$idx"
  c="$(git -C "$REPO" -c user.name=rehearsal -c user.email=rehearsal@example.test commit-tree "$tree" -p "$(git -C "$REPO" rev-parse "$BASE")" -m "rehearsal variant: $mode $path")"
  git -C "$REPO" rev-parse --short "$c"
}
both_copies() { echo "$(sq "SELECT json_extract(data, '$.$1') FROM kyc_config WHERE id = 0")$(php -r 'echo json_decode((string)file_get_contents($argv[1]), true)[$argv[2]] ?? "-";' "$DATA/kyc_config.json" "$1")"; }
row_has() { sq "SELECT CASE WHEN json_extract(data, '$.$1') IS NULL OR json_extract(data, '$.$1') = '' THEN 'no' ELSE 'yes' END FROM kyc_config WHERE id = 0"; }
cfg_keep() { cp "$DATA/kyc_config.json" "$SB/kyc_config.kept"
  php -r '$p = new PDO("sqlite:" . $argv[1]); file_put_contents($argv[2], (string)$p->query("SELECT data FROM kyc_config WHERE id = 0")->fetchColumn());' "$DATA/plugin.sqlite3" "$SB/kyc_row.kept"; }
cfg_back() { cp "$SB/kyc_config.kept" "$DATA/kyc_config.json"
  php -r '$p = new PDO("sqlite:" . $argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $p->prepare("UPDATE kyc_config SET data = ? WHERE id = 0")->execute([(string)file_get_contents($argv[2])]);' "$DATA/plugin.sqlite3" "$SB/kyc_row.kept"; }
row_unset() {   # row_unset KEY — remove a key from the store row ONLY; the configuration file keeps it
  php -r '$p = new PDO("sqlite:" . $argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $r = json_decode((string)$p->query("SELECT data FROM kyc_config WHERE id = 0")->fetchColumn(), true); unset($r[$argv[2]]);
    $p->prepare("UPDATE kyc_config SET data = ? WHERE id = 0")->execute([json_encode($r)]);' "$DATA/plugin.sqlite3" "$1"; }
setcfg() {   # setcfg KEY VALUE — the INSTALLED tools/set_config.php through docker exec, as the operator runs it
  env PATH="$SB/bin:$PATH" DN_DATA_DIR="$DATA" bash "$SB/bin/docker" exec ucrm php "$IN_CONTAINER/tools/set_config.php" --key "$1" --value "$2" 2>&1
}
db_keep() { rm -rf "$SB/dbkeep"; mkdir -p "$SB/dbkeep"; cp -p "$DATA"/plugin.sqlite3* "$SB/dbkeep/"; }
db_back() { rm -f "$DATA"/plugin.sqlite3*; cp -p "$SB/dbkeep"/plugin.sqlite3* "$DATA/"; }
host_code_left() { ls -d "$SB/out"/code-* "$SB/out"/domainb-* 2>/dev/null | wc -l | tr -d ' '; }
LEAK_RX='rh-evo|rh-key-secret|rh-sales|rh-shared|rh-moved'

install_base; seed_data; vault UGX; fresh
flip --on   # enable the pilot through the real tool, exactly as the live server has it
for i in $(seq 1 50); do [ "$(standin_page customer_login)" = "200" ] && break; sleep 0.1; done
# Step 0 of 5.18.87's handover, as the operator ran it on 07 Oct: the uCRM lead write off, with the installed tool.
S0="$(setcfg ai_crm_lead_sync 0)"
D0="$(data_digest)"
check "$(inst_ver)" "5.18.87" "control: the installed base is 5.18.87"
check "$(grep -cF -- "$B0_PIN" "$PD/workers/AiReplyWorker.php")$(grep -cF -- "$B0_PO" "$PD/workers/UcrmLeadWorker.php")$(grep -cF -- "$LISTS" "$PD/cron/event_processor.php")$(grep -cF -- "$FS_WH" "$PD/evo_webhook.php")" "1100" \
  "control: the base is production's: Batch 0's lead fixes in place, none of Batch 1's event-processor change or routing"
check "$(has "$S0" 'ai_crm_lead_sync = 0')$(both_copies ai_crm_lead_sync)$(both_copies ai_lead_capture)$(both_copies ai_qualification)$(both_copies ai_sales_on_all_numbers)" "yes00111111" \
  "control: the lead switches as production has them since 5.18.87's step 0 — capture ON, the uCRM write OFF, both copies"
check "$(row_has evo_api_url)$(row_has evo_api_key)$(row_has evo_instance_sales)$(both_copies evo_instance_support)$(both_copies evo_instance_account)$(row_has multi_number_channels_enabled)" "yesyesyesrh-sharedrh-sharedrh-sharedrh-sharedno" \
  "control: the Inbox's row names Evolution and three numbers, support and account sharing one instance as production's do; the registry's switch unset"
check "$(ia_objects)$(sq "SELECT COUNT(*) FROM _migrations WHERE filename = '086_install_authorisation.sql'")$(ia_rows)$(mig87)$(wa_objects)" "5:10:410:1:1:1:000:0:0" \
  "control: 086 applied by the plugin's own runner, complete, with production's records; no 087 and none of its objects"
check "$(sq "SELECT COUNT(*) FROM events")|$(sq "SELECT COUNT(*) FROM events WHERE status <> 'done'")|$(sq "SELECT COUNT(*) FROM job_photos")|$(find "$DATA/uploads/job_photos" -type f | wc -l | tr -d ' ')" "6|3|2|2" \
  "control: an event queue with work in it (6 events, 3 not done) and two photos with their files"
check "$(env PATH="$SB/bin:$PATH" DN_DATA_DIR="$DATA" bash "$SB/bin/docker" exec ucrm php "$IN_CONTAINER/tools/set_distributors.php" --show 2>/dev/null | awk '/distributors_enabled/ {print toupper($2); exit}')" "ON" "control: the pilot is ON on the base"
check "$(standin_page customer_login)$(standin_page install_auth)$(data_digest)$(wa_objects)" "200404${D0}0:0:0" "control: the stand-in boots the installed 5.18.87 store, the live authorisation page refuses a request without a link, and neither changes any data"

echo; echo "== 1. NO-GO before anything changes — each refusal, and nothing deployed, backed up or changed =="
nogo() {   # nogo LABEL EXPECTED-TEXT OUTPUT — the refusal said, nothing deployed, no backup, the data as seeded
  check "$(has "$3" "$2")$(live)$(backups)$(data_digest)" "yes${BASE}0$D0" "$1"
}
printf '%s\n' "$OLDER" > "$PD/.deployed-commit"; fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: the container serves $OLDER; 5.18.88 was built and tested against $BASE (5.18.87) — deploy 5.18.87 first (scripts/deploy-5.18.87.sh) and send its log")$(backups)$(data_digest)" "yes0$D0" \
  "1a refusal 1: the server still runs 5.18.86 — NO-GO, 5.18.87 goes first; no backup, no data changed"
check "$(live)" "$OLDER" "…the record of 5.18.86 left as found"; printf '%s\n' "$BASE" > "$PD/.deployed-commit"
cp "$PD/manifest.json" "$SB/manifest.kept"; sed -i 's/"version": "5.18.87"/"version": "5.18.86"/' "$PD/manifest.json"; fresh; OUT="$(run --answer DEPLOY)"
nogo "1b refusal 1: the record says $BASE but the installed manifest says 5.18.86 — NO-GO on the version too" \
  "STOP: NO-GO: the container's record says $BASE, but the installed manifest says 5.18.86, not 5.18.87" "$OUT"
cp "$SB/manifest.kept" "$PD/manifest.json"
S="$(pinned_copy unpinned __PLUGIN_COMMIT__)"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1c a copy still carrying the placeholder pin: stops before anything is read" "STOP: this copy of the script is not pinned to a reviewed commit" "$OUT"
S="$(pinned_copy tip "$TIP")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1d a copy pinned to the branch tip ($TIP, parent not 5.18.87): refused — the undeployed work cannot ride along" "STOP: $TIP is not cut on $BASE (5.18.87): its parent is" "$OUT"
V="$(release_variant add lib/MediaPolicy.php)"; S="$(pinned_copy v "$V")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1e refusal 5: the release AND an AI media-layer file — refused by the delta's allow-list, naming it" \
  "STOP: the pin carries files that are not 5.18.88's, which this release must not ship: lib/MediaPolicy.php(added)" "$OUT"
V="$(release_variant add migrations/088_rehearsal.sql)"; S="$(pinned_copy v "$V")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1f refusal 5: the release AND a second migration — refused, naming it" \
  "STOP: the pin carries files that are not 5.18.88's, which this release must not ship: migrations/088_rehearsal.sql(added)" "$OUT"
V="$(release_variant edit "$MIG" '-- 087_wa_channels.sql — the WhatsApp channel registry' '-- 087_wa_channels.sql (altered) — the WhatsApp channel registry')"; S="$(pinned_copy v "$V")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1g refusal 5: an 087 one comment different from the reviewed file — refused by its sha256" \
  "STOP: the pin's migrations/087_wa_channels.sql is not the reviewed one (sha256" "$OUT"
V="$(release_variant revert cron/followup_send.php)"; S="$(pinned_copy v "$V")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1h the release WITHOUT the follow-ups' change — refused, naming the file it lacks" "STOP: the pin lacks files 5.18.88 is made of: cron/followup_send.php" "$OUT"
V="$(release_variant add dishnet-mikrotik-control-plane/REHEARSAL.md)"; S="$(pinned_copy v "$V")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1i refusal 7: the release AND a file under Domain B — refused as Domain B, before the allow-list" \
  "STOP: NO-GO (Domain B): the pin changes Domain B, the plugin's documents or files outside the plugin: dishnet-mikrotik-control-plane/REHEARSAL.md dishnet-mikrotik-control-plane/:tree-differs" "$OUT"
V="$(release_variant add docs/REHEARSAL.md)"; S="$(pinned_copy v "$V")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1j refusal 7: the release AND a file in the plugin's docs — refused" "STOP: NO-GO (Domain B): the pin changes Domain B, the plugin's documents or files outside the plugin: docs/REHEARSAL.md docs/:tree-differs" "$OUT"
V="$(release_variant addroot scripts/REHEARSAL.txt)"; S="$(pinned_copy v "$V")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1k refusal 7: the release AND a file outside the plugin — refused" \
  "STOP: NO-GO (Domain B): the pin changes Domain B, the plugin's documents or files outside the plugin: scripts/REHEARSAL.txt(outside-the-plugin)" "$OUT"
cfg_keep
for c in both files store; do
  case "$c" in both) cfg_set multi_number_channels_enabled on; want="mn=on/on" ;; files) cfg_set multi_number_channels_enabled on files; want="mn=on/absent" ;; store) cfg_set multi_number_channels_enabled on store; want="mn=absent/on" ;; esac
  DX="$(data_digest)"; fresh; OUT="$(run --answer DEPLOY)"
  check "$(has "$OUT" "STOP: NO-GO: multi_number_channels_enabled reads '$want' (files/store). 5.18.88 ships the channel registry dark")$(live)$(backups)$(data_digest)" "yes${BASE}0$DX" \
    "1l refusal 2: the registry's switch ON ($c — $want): NO-GO, nothing deployed, no data changed"
  check "$(grep -cE -- "$SWITCH_RX" <<<"$OUT")" "0" "…and the refusal prints no switch command"
  cfg_back
done
check "$(data_digest)" "$D0" "control: the copies put back byte for byte"
check "$(sqx "INSERT INTO _migrations(filename, checksum) VALUES ('087_wa_channels.sql', 'deadbeef')")" "done" "control: an 087 recorded by hand"
fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: a migration 087 is already recorded on this server (mig=applied:deadbeef tables=0/2")$(live)$(backups)" "yes${BASE}0" \
  "1m refusal 3: 087 already recorded — NO-GO: the runner would skip this release's 087"
sqx "DELETE FROM _migrations WHERE filename = '087_wa_channels.sql'" >/dev/null
check "$(sqx "CREATE TABLE wa_channel_log (id INTEGER PRIMARY KEY, channel_id TEXT)")" "done" "control: one of 087's tables made by hand, no ledger row"
fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: 087 is not recorded, yet some of its objects exist on this server (mig=absent tables=1/2")$(live)$(backups)" "yes${BASE}0" \
  "1n refusal 4: 087 half there — NO-GO: this release's 087 would meet it half-built"
sqx "DROP TABLE wa_channel_log" >/dev/null
check "$(data_digest)$(wa_objects)$(mig87)" "${D0}0:0:00" "control: the hand-made table dropped — the data as seeded, no 087 anywhere"
V_SS="$(release_variant edit cron/event_processor.php "$LISTS" "\$_epWorkerOwned = \$_epUg ? ['ai.reply', 'crm.lead.sync'] : ['ai.reply', 'ai.media'];")"
S="$(pinned_copy v "$V_SS")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1o refusal 6: a pin that puts ai.media on South Sudan's list — NO-GO (South Sudan): named statically, and its run differs from the live one" \
  "STOP: NO-GO (South Sudan): the pin does not keep South Sudan's event loop exactly, or the check could not be made: lists ai.media-named south-sudan-differs" "$OUT"
check "$(has "$OUT" "pin  5.18.88: crm.lead.sync=done/0+unknown ai.reply=pending/0 ai.media=pending/0 wa.escalation=done/0+unknown")$(has "$OUT" "live 5.18.87: $SS_SIG")" "yesyes" \
  "…the two runs printed: the live loop acknowledges ai.media as unknown, the pin's releases it"
V="$(release_variant edit cron/event_processor.php "$LISTS" "\$_epWorkerOwned = \$_epUg ? ['ai.reply'] : ['ai.reply'];")"; S="$(pinned_copy v "$V")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1p refusal 6: a pin whose Uganda list lacks crm.lead.sync — NO-GO: Uganda's lead syncs not protected" \
  "STOP: NO-GO (South Sudan): the pin does not keep South Sudan's event loop exactly, or the check could not be made: lists uganda-not-protected" "$OUT"
V="$(release_variant edit cron/event_processor.php '    if (empty($events)) return; // nothing to process
' '')"; S="$(pinned_copy v "$V")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1q refusal 6: a pin without South Sudan's early return on an empty claim — NO-GO" \
  "STOP: NO-GO (South Sudan): the pin does not keep South Sudan's event loop exactly, or the check could not be made: early-return" "$OUT"
fresh; OUT="$(run --answer DEPLOY --env FAKE_CP_FAIL=1)"
nogo "1r the two releases' code cannot be copied into the container: NO-GO — A9 and A6 are refusals and cannot be made without it" \
  "STOP: NO-GO: the two releases' code could not be copied into the container's /tmp — A9 and A6 compare it under the server's own PHP, and both are refusals" "$OUT"
fresh; OUT="$(run --answer DEPLOY --env DNB_SNAPSHOT_TMP=/nonexistent-dir)"
check "$(has "$OUT" 'FAIL  backup of plugin.sqlite3 failed')$(has "$OUT" 'STOP: NO-GO: the backup or a before-check did not complete')$(live)$(data_digest)" "yesyes${BASE}$D0" \
  "1s refusal 8: the database's backup fails — NO-GO after the backup stage, nothing deployed, no data changed"
check "$(has "$OUT" 'Type DEPLOY')" "no" "…never reaching the DEPLOY prompt"
cfg_keep; S1="$(setcfg ai_crm_lead_sync 1)"; DX="$(data_digest)"; fresh; OUT="$(run --answer NO)"
check "$(has "$OUT" "note  the lead switches read lc=on/on ls=on/on (files/store), not the 07 Oct decision")$(has "$OUT" 'STOP: not confirmed')$(live)$(data_digest)" "yesyes${BASE}$DX" \
  "1t A4 is evidence now: the uCRM write switched on is said, never a stop — the run reached the DEPLOY prompt (answered NO), nothing deployed"
cfg_back
cfg_keep; row_unset evo_instance_account; DX="$(data_digest)"; fresh; OUT="$(run --answer NO)"
check "$(has "$OUT" "note  the settings row the Inbox reads does not name every number (evo=yes sales=yes support=yes account=no registry=absent)")$(has "$OUT" 'STOP: not confirmed')$(live)$(data_digest)" "yesyes${BASE}$DX" \
  "1u A3 is evidence too: the account number missing from the Inbox's row is said, and the run goes on to the prompt"
check "$(grep -cE -- "$LEAK_RX" <<<"$OUT")" "0" "…and the run printed no Evolution address, key or instance name"
cfg_back
check "$(data_digest)$(host_code_left)$(left_tmp)" "${D0}0$TMP0" "control: the data as seeded; every run removed its copies of the code — on the host and in the container's /tmp"

echo; echo "== 2. weakened copies of stage A are caught (the control on the control) =="
cfg_keep; cfg_set multi_number_channels_enabled on
M="$(mutant a5 "$DEPLOY" '  if ! sw_off "$MN_BEFORE"; then' '  if false; then')"
fresh; OUT="$(run --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" 'STOP: NO-GO: multi_number_channels_enabled reads')$(has "$OUT" 'STOP: not confirmed')$(live)" "noyes$BASE" "2a the A5-blinded copy goes on with the registry's switch ON, to the DEPLOY prompt (answered NO) — the mutation is detected"
fresh; OUT="$(run --answer NO)"
check "$(has "$OUT" "STOP: NO-GO: multi_number_channels_enabled reads 'mn=on/on'")$(backups)" "yes0" "2b …the real script stops at A5 on the same switch"
cfg_back
sqx "INSERT INTO _migrations(filename, checksum) VALUES ('087_wa_channels.sql', 'deadbeef')" >/dev/null
M="$(mutant a8 "$DEPLOY" '    "mig=applied:"*) stop "NO-GO: a migration 087' '    "mig=applied:"*) true "NO-GO: a migration 087')"
fresh; OUT="$(run --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" 'STOP: NO-GO: a migration 087 is already recorded')$(has "$OUT" 'STOP: not confirmed')" "noyes" "2c the A8-blinded copy goes on with 087 recorded, to the prompt — detected"
fresh; OUT="$(run --answer NO)"
check "$(has "$OUT" 'STOP: NO-GO: a migration 087 is already recorded')" "yes" "2d …the real script stops at A8"
sqx "DELETE FROM _migrations WHERE filename = '087_wa_channels.sql'" >/dev/null
S="$(pinned_copy ssv "$V_SS")"; M="$(mutant a6 "$S" '[ -z "$A6_BAD" ] || stop' '[ -z "$A6_BAD" ] || true')"; rm -f "$S"
fresh; OUT="$(run --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" 'STOP: NO-GO (South Sudan)')$(has "$OUT" 'STOP: not confirmed')" "noyes" "2e the A6-blinded copy, pinned to the pin that changes South Sudan's loop, goes on to the prompt — detected"
V_DB="$(release_variant add dishnet-mikrotik-control-plane/REHEARSAL.md)"; S="$(pinned_copy dbv "$V_DB")"; M="$(mutant a7 "$S" '[ -z "$DB_BAD" ] || stop' '[ -z "$DB_BAD" ] || true')"; rm -f "$S"
fresh; OUT="$(run --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" 'NO-GO (Domain B)')$(has "$OUT" "STOP: the pin carries files that are not 5.18.88's, which this release must not ship: dishnet-mikrotik-control-plane/REHEARSAL.md(added)")" "noyes" \
  "2f the A7-blinded copy no longer refuses the Domain B pin as Domain B (the allow-list still stops it) — detected"
V_RT="$(release_variant edit lib/EvolutionApiService.php "        return \$this->instanceToChannel[mb_strtolower(trim(\$instance))] ?? '';" "        \$c = \$this->instanceToChannel[mb_strtolower(trim(\$instance))] ?? ''; return \$c === 'support' ? 'account' : \$c;")"
S="$(pinned_copy rtv "$V_RT")"; fresh; OUT="$(run --answer NO --script "$S")"
check "$(has "$OUT" "STOP: the pin carries files that are not 5.18.88's, which this release must not ship: lib/EvolutionApiService.php")" "yes" \
  "2g control: a pin that changes how the shared number's inbound is routed is stopped by the allow-list first"
M="$(mutant a0 "$S" '[ -z "$STRAY" ] || stop' '[ -z "$STRAY" ] || true')"; rm -f "$S"
fresh; OUT="$(run --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" "STOP: NO-GO: with the registry off the pin's code would not route the three numbers exactly as the live code does (files: sales:in=sales support:in=account account:in=account shared=support+account evo=yes registry=off")$(live)$(backups)" "yes${BASE}0" \
  "2h …and with the allow-list blinded, A9 stops it on its own: the shared number's inbound would land in account — A9 has teeth"
check "$(data_digest)$(host_code_left)$(left_tmp)" "${D0}0$TMP0" "control: the data as seeded, no code copy left anywhere"

echo; echo "== 3. the deploy, as the operator runs it =="
D1="$(data_digest)"; EV1="$(sq "SELECT group_concat(id || ':' || event_type || ':' || status, ',') FROM events")"
fresh; OUT="$(run --answer DEPLOY)"
echo "  …    the deploy's own tally:$(grep -E '^  checks +[0-9]+ ok, ' <<<"$OUT" | sed -E 's/^  checks +/ /')"
check "$(fails "$OUT")$(notes "$OUT")" "00" "no FAIL line and no note"
check "$(has "$OUT" '5.18.88 (deploy): PASSED')" "yes" "PASSED"
for l in "ok    A7 Domain B untouched: the whole repository differs between $BASE and the pin by the plugin's $N_CH files alone, none under Domain B, and its trees are the live release's — dishnet-mikrotik-control-plane/ $(git -C "$REPO" rev-parse --short "$PIN:$P/dishnet-mikrotik-control-plane") docs/ $(git -C "$REPO" rev-parse --short "$PIN:$P/docs")" \
         "ok    A0 the release delta is exactly 5.18.88's $N_CH files — $N_AD added, $N_MO changed, one migration: the reviewed 087 (sha256 ${MIG_SHA:0:16}…); no partner-portal, CSRF or AI media-layer file" \
         "release code    $PIN (5.18.88) and $BASE (5.18.87), copied into the container's /tmp for the comparisons below — removed when this run ends" \
         "registry switch mn=absent/absent   (multi_number_channels_enabled in the configuration files / the store row; must read OFF — this release ships the registry dark)" \
         "087 state       mig=absent tables=0/2 indexes=0/3 triggers=0/3 checks=0/2 rows=-:- seed=- other=0 trail=-" \
         "ok    A3 the settings row the Inbox reads still reaches Evolution and names a sales and an account number" \
         "ok    A4 the lead switches are as the operator decided on 07 Oct: ai_lead_capture ON in both copies, ai_crm_lead_sync OFF (lc=on/on, ls=off/off; files/store)" \
         "ok    A5 the channel registry's switch reads OFF in both copies (mn=absent/absent; files/store) — 5.18.88 ships the registry dark" \
         "ok    A8 migration 087 is not recorded on this server and none of its objects exists — the plugin's runner will apply it, whole, on its first request after the copy" \
         "routing         $SHAPE   (the files the webhook and the workers read)" \
         "                  $SHAPE   (the row the Inbox reads)" \
         "ok    A9 the pin's code routes the three numbers exactly as the live code does, on this server's own configuration — the files the webhook and the workers read, and the Inbox's row: $SHAPE (instance names compared, never printed)" \
         "ok    A2 PHP" \
         "South Sudan     live 5.18.87: $SS_SIG" \
         "                  pin  5.18.88: $SS_SIG" \
         "Uganda          pin  5.18.88: $UG_SIG" \
         "ok    A6 South Sudan keeps 5.18.87's event loop exactly" \
         "— one consistent copy, integrity ok" "ok    backed up the installed plugin (5.18.87, $BASE)" "ok    backed up the configuration vault" \
         "event-queue.tsv — 3 event(s) not done yet: id, type, status, attempts and times, never a payload (total=6 done=3 pending=1 processing=0 failed=1 dead=1; not done: ai.reply=1 crm.lead.sync=1 wa.escalation=0)" \
         "GO — evidence recorded" \
         "checking out $PIN (5.18.88, the release commit) for the documented deploy" \
         "ok    container serves $PIN" \
         "ok    V1 the sign-in page on the public address answers 200 with zero redirects (no loop)" \
         "ok    V7 the customer authorisation page answers 404 \"This link is not valid\" — the switch is on, and a request without a link is refused" \
         "ok    V10 wa_send_reply without a login answers 401" \
         "ok    V3b install_auth_enabled is unchanged by this run (before=ia=on/on, after=ia=on/on; files/store)" \
         "ok    V3c customer_wa_install_scheduled is unchanged by this run (before=wa=on/on, after=wa=on/on; files/store)" \
         "ok    V3d the settings row the Inbox reads is unchanged by this run" \
         "ok    V3e the lead switches are unchanged by this run (lc=on/on ls=off/off qu=on/on sa=on/on; files/store)" \
         "ok    V3f the channel registry's switch is unchanged by this run and OFF in both copies (mn=absent/absent; files/store) — the registry stays dark" \
         "ok    V3g S6 the AI and WhatsApp settings are unchanged by this run (files=" \
         "ok    V4 no fatal or parse error of $P in the container log since" \
         "ok    R1 all $N_CH files 5.18.88 changes are installed exactly as $PIN has them ($N_AD new)" \
         "ok    R1 the installed manifest says 5.18.88" \
         "ok    R2 the installed InstallAuth reads install_auth_enabled ON in both places" \
         "ok    R2 the installed InstallScheduledWhatsApp reads customer_wa_install_scheduled ON in both places" \
         "ok    R3 migration 086 (5.18.83's) is still applied and complete" \
         "ok    R3 084 is still installed and its two tables exist on the live plugin.sqlite3 — job_photos 2:0 rows" \
         "ok    R3 migration 087 is applied and complete — 2 tables, 3 indexes, 3 triggers, both CHECKs, the ledger row matching the installed file — holding exactly the three department rows as 087 seeds them, NO instance stored, no other channel, and the trail of three" \
         "OK: 087_wa_channels.sql (14 stmts, " \
         "ok    R4 the pilot libs + the Distributors tab are installed as before" \
         "no partner-portal, CSRF or AI media-layer file is installed, and neither the event processor, the webhook nor the AI worker carries any of the media layer" \
         "ok    R5 all" \
         "and 5.18.88's Batch 1 is in place: the webhook, the AI worker and the follow-ups through forStore(), a switched-off channel refused, the assistant's switch per number, the reply route behind the registry, the event processor's two lists and EventBus's exclusion, the lead's origin, the registry's switch in set_config.php, the read-only CLI and 087" \
         "ok    R7 cash in hand on the live book, read-only — what the landing hero shows: UGX CASH IN HAND 850,000.00 · USD CASH IN HAND 0.00" \
         "ok    R7 the photo tables and files read exactly as before the run (present:2:0, files:2) — the tool touched no data" \
         "ok    R8 the installed quotation reader reads a quotation shaped like 000181 as the form will" \
         "ok    R9 the installed Inbox route, on the server's own Inbox settings: a sales chat → the sales number, an account chat → the account number" \
         "UcrmLeadWorker settles a lead's sync as \"not synced — switched off\": nothing reaches uCRM. S7: with the registry off a lead carries no channel fields and its sync names no channel — 5.18.87's lead, field for field" \
         "ok    R11 S4/S5 the registry is dark: its switch OFF in both copies; ChannelRegistry::enabled() and forStore() off for both; the Inbox's route for every kind of chat — 0 statements, none on the registry's tables; 087 holds the three department rows alone, no instance stored, so no Evolution instance was added; tools/channels.php says OFF, not in effect, installed" \
         "ok    R12 S1–S3 the three numbers route exactly as on 5.18.87 — the installed code and 5.18.87's, on this server's own configuration: $SHAPE (the files the webhook and the workers read); the Inbox's row: $SHAPE — instance names compared, never printed" \
         "ok    R13 S8 the installed event processor, on throwaway databases: on Uganda it leaves ai.reply and crm.lead.sync to their workers and settles wa.escalation as known ($UG_SIG); as a South Sudan install it does exactly what 5.18.87's does ($SS_SIG)" \
         "ok    R13 the plugin's own event queue lost nothing: kept=3/3 missing=- of the events not done at the snapshot are still there" \
         "ok    R14 Domain B is installed exactly as $PIN has it: all 363 files of dishnet-mikrotik-control-plane/ and the plugin's docs/, byte for byte — this release touched neither" \
         "migrations        one, 087 — two tables, three indexes, three triggers, the three department rows with no instance stored, applied by the plugin's own runner (R3); 086 still complete"; do
  check "$(has "$OUT" "$l")" "yes" "3: ${l:0:100}"
done
check "$(cnt "$OUT" 'ok    backed up ')$(cnt "$OUT" 'ok    snapshot of the event queue')" "41" "four backups — the database, the data directory, the installed plugin, the vault — and the event queue's snapshot"
check "$(grep -cE -- "$LEAK_RX" <<<"$OUT")" "0" "the log carries no Evolution address, key or instance name — the routing as a shape, the names compared under a key"
check "$(live)$(installed_digest "$PIN")$(inst_ver)" "${PIN}05.18.88" "the container serves $PIN: every changed file exactly as the commit has it, manifest 5.18.88"
check "$(mig87)$(wa_objects)|$(wa_rows)|$(sq "SELECT COUNT(*) FROM wa_channel_log WHERE action = 'seeded' AND actor = 'migration 087'")" "12:3:3|account/NULL/active,sales/NULL/active,support/NULL/active|3" \
  "087 applied by the plugin's own runner on its first request: recorded once, two tables, three indexes, three triggers, the three department rows with NO instance, the trail of three"
check "$(sq "SELECT checksum FROM _migrations WHERE filename = '087_wa_channels.sql'")" "$(md5sum "$PD/$MIG" | cut -c1-32)" "…its ledger row carries the installed file's checksum"
check "$(grep -cF '087_wa_channels.sql' "$DATA/migration.log")$(grep -cF 'OK: 087_wa_channels.sql (14 stmts, ' "$DATA/migration.log")$(grep -cE 'PARTIAL|ERROR|WARNING' "$DATA/migration.log")" "110" \
  "…and the runner's own migration.log, read in the sandbox: one line for 087, OK, all 14 statements counted, and nothing PARTIAL anywhere"
check "$(data_digest)" "$D1" "the deploy wrote no record and no configuration value — every table but 087's two (and the ledger), the vault, the configuration files and the photos as before"
check "$(sq "SELECT group_concat(id || ':' || event_type || ':' || status, ',') FROM events")" "$EV1" "…the event queue exactly as before: the deploy processed nothing"
STATE="$SB/out/state-5.18.88.env"; DA="$(sed -n 's/^DEPLOYED_AT=//p' "$STATE")"
check "$(grep -cE '^DEPLOYED_AT=[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$' "$STATE")$(grep -c '^EQ_FILE=.*/event-queue.tsv$' "$STATE")$(grep -c '^MN_BEFORE=mn=absent/absent$' "$STATE")" "111" \
  "the state file records when the deploy started in ISO-8601 — the form the container log writes (PART 4, V4) — and the queue's snapshot"
BK="$(ls -d "$SB/out"/backup-* | tail -1)"
check "$(grep -c . "$BK/event-queue.tsv")$(cut -f2,3 "$BK/event-queue.tsv" | tr '\t\n' ': ')" "3ai.reply:pending crm.lead.sync:failed efris.submit:dead " "the event queue's snapshot holds the three events not done — type and status, never a payload"
check "$(tar -xzOf "$BK"/plugin-installed-5.18.87.tar.gz $P/manifest.json | grep -c '"version": "5.18.87"')" "1" "the code backup is 5.18.87"
check "$(host_code_left)$(left_tmp)" "0$TMP0" "the run removed its copies of the two releases' code and every throwaway database — on the host and in the container's /tmp"
check "$(grep -c "cd $REPO && bash scripts/deploy-5.18.88.sh --rollback" <<<"$OUT")" "1" "the rollback command is printed once, on its own line, never beside the deploy"
check "$(awk '/PASSED\. Send this LOG FILE back/ {p=1} p && /deploy-5\.18\.88\.sh --rollback/ {print "after"; exit}' <<<"$OUT")" "after" "…after the verdict, at the end of the log"
check "$(grep -cE -- "$SWITCH_RX" <<<"$OUT")" "0" "no switch command anywhere in the log — not for a feature, the registry or a lead switch"
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" '5.18.88 (after): PASSED')$(data_digest)" "0yes$D1" "--after-only right after: PASSES, nothing written"

echo; echo "== 4. the checks have teeth — every fault named, then removed =="
db_keep
cp "$PD/cron/event_processor.php" "$SB/ep.aside"
python3 - "$PD/cron/event_processor.php" "$LISTS" <<'PY'
import sys
p, old = sys.argv[1], sys.argv[2]
s = open(p).read(); assert s.count(old) == 1
open(p, 'w').write(s.replace(old, old.replace(": ['ai.reply'];", ": ['ai.reply', 'ai.media'];")))
PY
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R1 installed files that differ: cron/event_processor.php')$(has "$OUT" 'event-processor:lists')$(has "$OUT" 'event_processor:ai.media(not-in-this-release)')$(has "$OUT" 'south-sudan:crm.lead.sync=done/0+unknown ai.reply=pending/0 ai.media=pending/0')" "yesyesyesyes" \
  "4a ai.media on South Sudan's list on the server: R1 names the file, R6 the lists, R4 the media layer, R13 South Sudan's changed run"
cp "$SB/ep.aside" "$PD/cron/event_processor.php"
git -C "$REPO" show "$BASE:$P/cron/event_processor.php" > "$PD/cron/event_processor.php"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R1 installed files that differ: cron/event_processor.php')$(has "$OUT" "uganda:$SS_SIG")" "yesyes" "4b 5.18.87's event processor back: R1 names it, and R13 reads Uganda's run as 5.18.87's — lead syncs unprotected"
cp "$SB/ep.aside" "$PD/cron/event_processor.php"
cp "$PD/workers/AiReplyWorker.php" "$SB/worker.aside"; git -C "$REPO" show "$BASE:$P/workers/AiReplyWorker.php" > "$PD/workers/AiReplyWorker.php"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R1 installed files that differ: workers/AiReplyWorker.php')$(has "$OUT" 'ai-worker:no-forStore')$(has "$OUT" 'AiReplyWorker:origin-with-the-registry-off')" "yesyesyes" \
  "4c 5.18.87's AI worker back: R1 names it, R6 its missing routing, R10 the lead's origin"
cp "$SB/worker.aside" "$PD/workers/AiReplyWorker.php"
check "$(sqx 'DROP TRIGGER wa_channels_never_deleted')" "done" "control: one trigger dropped from 087"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R3 migration 087 is recorded but not as it must be: incomplete:wa_channels_never_deleted')" "yes" "4d R3 names the missing trigger — the ledger row alone is not trusted"
db_back
check "$(sqx "INSERT INTO wa_channels(channel_id, evo_instance, display_name, role) VALUES ('sales-002', 'rh-two', 'Second', 'sales')")" "done" "control: a second sales number added to the registry by hand"
OUT="$(run --after-only)"
check "$(has "$OUT" 'rows=4:3 seed=ok other=1 trail=ok')$(has "$OUT" 'FAIL  R11 the registry is not dark, or not as 087 seeds it: wa_channels:rows=4:3 seed=ok other=1')" "yesyes" \
  "4e R3 and R11 name the channel that is not the three departments' — a new Evolution instance in the registry is seen"
check "$(grep -cE -- 'rh-two' <<<"$OUT")" "0" "…without printing its instance name"
db_back
sqx "UPDATE wa_channels SET evo_instance = 'rh-x' WHERE channel_id = 'sales'" >/dev/null
OUT="$(run --after-only)"
check "$(has "$OUT" 'seed=differs:sales')" "yes" "4f a department row given an instance: R3 names the row that is no longer as 087 seeded it"
db_back
cfg_keep; cfg_set multi_number_channels_enabled on
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  V3f multi_number_channels_enabled reads ON (mn=on/on, files/store)')$(has "$OUT" 'FAIL  R11 the registry is not dark')$(has "$OUT" 'registry-on')" "yesyesyes" \
  "4g the registry's switch ON: V3f, R11 and R12 each say the registry is not dark"
cfg_back
DBF="$(git -C "$REPO" ls-tree -r --name-only "$PIN" -- "$P/dishnet-mikrotik-control-plane" | head -1)"; DBF="${DBF#$P/}"
cp "$PD/$DBF" "$SB/db.aside"; printf '\n# changed on the server\n' >> "$PD/$DBF"
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R14 Domain B files on the server differ from $PIN: ./$DBF")" "yes" "4h a Domain B file changed on the server: R14 names it"
cp "$SB/db.aside" "$PD/$DBF"
EVF="$(sq "SELECT id FROM events WHERE status = 'failed'")"; sqx "DELETE FROM events WHERE id = $EVF" >/dev/null
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R13 events that were not done at the snapshot are GONE from the queue (kept=2/3 missing=$EVF)")" "yes" "4i an event not done deleted from the queue: R13 names its id"
db_back
M="$(mutant r13 "$DEPLOY" '  case "$EQ_KEPT" in
    "kept="*" missing=-") ok "R13 the plugin' '  case "kept=- missing=-" in
    "kept="*" missing=-") ok "R13 the plugin')"
sqx "DELETE FROM events WHERE id = $EVF" >/dev/null
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" 'GONE from the queue')$(has "$OUT" "ok    R13 the plugin's own event queue lost nothing")" "noyes" "4j the R13-blinded copy passes the lost event — detected"
db_back
M="$(mutant r11 "$DEPLOY" 'if [ -z "$R11_BAD" ]; then ok "R11 S4/S5' 'if true; then ok "R11 S4/S5')"
cfg_keep; cfg_set multi_number_channels_enabled on
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" 'FAIL  R11')$(has "$OUT" 'ok    R11 S4/S5 the registry is dark')" "noyes" "4k the R11-blinded copy calls the registry dark with its switch ON — detected"
cfg_back
cp -p "$DATA/migration.log" "$SB/mlog.aside"
sed -i 's/OK: 087_wa_channels\.sql (14 stmts, /OK: 087_wa_channels.sql (13 stmts, /' "$DATA/migration.log"
check "$(grep -cF 'OK: 087_wa_channels.sql (13 stmts, ' "$DATA/migration.log") line / $(wa_objects)|$(wa_rows)" "1 line / 2:3:3|account/NULL/active,sales/NULL/active,support/NULL/active" \
  "control: the runner's line made to count 13 of 14 — the store itself still exactly as 087 makes it"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R3 migration 087 is recorded but not as it must be: migration.log:OK-counts-13-of-14-statements')$(has "$OUT" 'migration.log: [')$(fails "$OUT")" "yesyes1" \
  "4m the runner says OK but counts 13 of 14 — what it writes when it skips a statement it calls safe: R3 fails on that alone, and shows the line"
cp -p "$SB/mlog.aside" "$DATA/migration.log"
printf '[2026-10-07 00:00:00] PARTIAL: 087_wa_channels.sql — 13 ok, 1 skipped: CREATE TRIGGER … — planted\n' >> "$DATA/migration.log"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R3 migration 087 is recorded but not as it must be: migration.log:PARTIAL')$(fails "$OUT")" "yes1" "4n a PARTIAL line for 087 in the runner's log: R3 fails on it, the store notwithstanding"
cp -p "$SB/mlog.aside" "$DATA/migration.log"
grep -vF '087_wa_channels.sql' "$SB/mlog.aside" > "$DATA/migration.log"
OUT="$(run --after-only)"
check "$(has "$OUT" 'ok    R3 migration 087 is applied and complete')$(has "$OUT" "note  R3 migration.log holds no line for 087")$(fails "$OUT")" "yesyes0" \
  "4o no line for 087 in the log at all: R3 passes on the store — every statement's effect read — and says the log was silent"
cp -p "$SB/mlog.aside" "$DATA/migration.log"
M="$(mutant r3log "$DEPLOY" '      elif [ "${nl:-0}" -gt 0 ] && [ "${nok:-0}" != "$nl" ]; then' '      elif false; then')"
sed -i 's/OK: 087_wa_channels\.sql (14 stmts, /OK: 087_wa_channels.sql (13 stmts, /' "$DATA/migration.log"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" 'OK-counts-13-of-14')$(has "$OUT" 'ok    R3 migration 087 is applied and complete')" "noyes" "4p a copy without the count check passes the 13-of-14 line — the check above is what catches it"
cp -p "$SB/mlog.aside" "$DATA/migration.log"
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" '5.18.88 (after): PASSED')$(data_digest)" "0yes$D1" "4l every fault removed: --after-only PASSES, the data as after the deploy"

echo; echo "== 5. PART 4: the two defects of 5.18.87's deploy, fixed — each with its control =="
db_keep
touch "$SB/web/.add_photo"; OUT="$(run --after-only)"
check "$(has "$OUT" 'ok    R7 the photo tables and files only grew during the run (present:2:0 → present:3:0, files:2 → files:3): photos taken meanwhile')$(fails "$OUT")$(has "$OUT" '5.18.88 (after): PASSED')" "yes0yes" \
  "5a R7: a photo taken during the run (the first request of the run takes it) — growth is fine: R7 says so and the run PASSES"
db_back; rm -f "$DATA/uploads/job_photos/20/kit-1-during.jpg"
M="$(mutant r7old "$DEPLOY" 'case "$(photo_growth "$TB_BEFORE" "$TB_AFTER" "$PF_BEFORE" "$PF_AFTER")" in' 'case "$( [ "$TB_AFTER" = "$TB_BEFORE" ] && [ "$PF_AFTER" = "$PF_BEFORE" ] && echo same || echo lost )" in')"
touch "$SB/web/.add_photo"; OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" 'FAIL  R7 the photo tables or files LOST something during the run (present:2:0 → present:3:0')" "yes" \
  "5b control on the control: 5.18.87's rule (the counts must be equal) put back — the same photo fails the run, as on 07 Oct; the fix is what passes it"
db_back; rm -f "$DATA/uploads/job_photos/20/kit-1-during.jpg"
touch "$SB/web/.del_photo"; OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R7 the photo tables or files LOST something during the run (present:2:0 → present:1:0, files:2 → files:1)')" "yes" "5c R7: a photo lost during the run fails, naming both counts"
db_back; printf 'jpeg' > "$DATA/uploads/job_photos/20/cable-1-b.jpg"
check "$(data_digest)" "$D1" "control: the photos put back — the data as after the deploy"
check "$(sed -n 's/^DEPLOYED_AT=//p' "$STATE")" "$DA" "control: the state file is the deploy's, its time in ISO-8601 ($DA)"
base_log; logline "$(date -u +%s)" "PHP Fatal error:  Uncaught Error: planted by the rehearsal in /data/ucrm/data/plugins/$P/cron/event_processor.php:1"
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  V4 1 fatal line(s) of $P since $DA:")$(has "$OUT" 'planted by the rehearsal')" "yesyes" \
  "5d V4 in --after-only mode reads the log since the deploy and finds a fatal planted after it — on 07 Oct the same mode read no line at all"
cp "$STATE" "$SB/state.kept"
sed -i "s/^DEPLOYED_AT=.*/DEPLOYED_AT=$(date -u -d "$DA" +%Y%m%dT%H%M%SZ)/" "$STATE"
check "$(grep -cE '^DEPLOYED_AT=[0-9]{8}T[0-9]{6}Z$' "$STATE")" "1" "control: the state file rewritten with the compact stamp 5.18.60–5.18.87 wrote"
M="$(mutant v4old "$DEPLOY" '  local t; t="$(iso_time "$1")"' '  local t; t="$1"' 'V4_SINCE="$(iso_time "$DEPLOY_STARTED")"' 'V4_SINCE="$DEPLOY_STARTED"')"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" 'ok    V4 no fatal or parse error')$(has "$OUT" '(0 line(s) read)')$(has "$OUT" 'planted by the rehearsal')" "yesyesno" \
  "5e control on the control: 5.18.87's way of reading the log put back, with its compact stamp — the planted fatal is missed and V4 passes having read 0 lines: the defect, reproduced"
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  V4 1 fatal line(s) of $P since $DA:")" "yes" "5f …while the real script turns the same compact stamp into ISO-8601 and finds the fatal"
cp "$SB/state.kept" "$STATE"; base_log
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" '5.18.88 (after): PASSED')$(data_digest)" "0yes$D1" "5g the log clean again: --after-only PASSES"

echo; echo "== 6. the switches after the deploy, each as its own step with the installed tool =="
D3="$(data_digest)"
S1="$(setcfg ai_crm_lead_sync 1)"; OUT="$(run --after-only)"
check "$(has "$S1" 'ai_crm_lead_sync = 1')$(fails "$OUT")$(has "$OUT" "creates or links a lead client in uCRM for each lead's sync it receives — and on Uganda the event processor now leaves every sync to it (docs/65 §Z.5)")$(has "$OUT" 'ls=on/on')" "yes0yesyes" \
  "6a the uCRM write switched on: --after-only PASSES, and R10 says leads reach uCRM — the event processor no longer takes their syncs"
S2="$(setcfg ai_crm_lead_sync 0)"; OUT="$(run --after-only)"
check "$(has "$S2" 'ai_crm_lead_sync = 0')$(fails "$OUT")$(data_digest)" "yes0$D3" "6b switched off again: PASSES, the data byte for byte as before 6a"
cfg_keep; S3="$(setcfg multi_number_channels_enabled 1)"; OUT="$(run --after-only)"
check "$(has "$S3" 'multi_number_channels_enabled')$(has "$OUT" 'FAIL  V3f multi_number_channels_enabled reads ON')$(has "$OUT" 'FAIL  R11')$(has "$OUT" '5.18.88 (after): PASSED')" "yesyesyesno" \
  "6c the registry's switch set with the installed tool (it lists the switch now): the checks say at once that the registry is not dark"
cfg_back; OUT="$(run --after-only)"
check "$(fails "$OUT")$(data_digest)" "0$D3" "6d …put back: PASSES, the data as before"
cfg_keep; cfg_set ai_qualification off; OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" 'the assistant is asked to record leads nowhere while ai_qualification is off')" "0yes" "6e qualification off: PASSES, and R10 says no lead is asked for anywhere"
cfg_back
cfg_keep; row_unset evo_instance_account; OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R9 the installed Inbox route answered: sales:channel support:sender/support account:channel accounts:sender/accounts web:sender/support | registry=off sales=yes account=no')" "yes" \
  "6f R9 still has teeth: the account number taken out of the Inbox's row"
cfg_back
cfg_keep; touch "$SB/web/.mutate_cfg"; OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  V3g the AI or WhatsApp settings CHANGED across this run: store.evo_instance_account')$(grep -cE -- "$LEAK_RX" <<<"$OUT")$(test -f "$SB/web/.mutate_cfg" && echo left || echo spent)" "yes0spent" \
  "6g the Inbox's account number moved DURING a run (the run's first request moves it): V3g names the key — never its values"
cfg_back
OUT="$(run --after-only)"
check "$(fails "$OUT")$(data_digest)" "0$D3" "6h the number put back: PASSES, the data as before"

echo; echo "== 7. the rollback: --rollback, typed ROLLBACK =="
D2="$(data_digest)"; fresh; OUT="$(run --answer ROLLBACK --rollback)"
check "$(fails "$OUT")$(has "$OUT" '5.18.88 (rollback): PASSED')" "0yes" "PASSED"
for l in "note  after the rollback the event processor is 5.18.87's again: on Uganda it claims every type, so a lead's uCRM sync can be acknowledged as an unknown type before the uCRM worker sees it (docs/65 §Z.5)" \
         "ok    backed up the installed plugin (5.18.88, $PIN)" "ok    snapshot of the event queue" \
         "GO — evidence recorded" "checking out $BASE (5.18.87) for the documented deploy" "ok    container serves $BASE (5.18.87)" \
         "ok    V3e the lead switches are unchanged by this run (lc=on/on ls=off/off qu=on/on sa=on/on; files/store)" \
         "ok    V3f the channel registry's switch is unchanged by this run and OFF in both copies (mn=absent/absent; files/store)" \
         "ok    V3g S6 the AI and WhatsApp settings are unchanged by this run" \
         "ok    RB the installed manifest says 5.18.87" \
         "ok    RB the installed plugin is 5.18.87's again: Batch 1's routing and the event processor's change are gone, and 5.18.87's lead fixes are there — a qualified enquiry is still recorded as a lead" \
         "ok    RB 087's tables stay in plugin.sqlite3, as the plugin applied them; 5.18.87 does not read them with the switch off — ChannelRegistry::enabled() and forStore() off for both copies, the Inbox's route for every kind of chat: 0 statements on them; the switch stays OFF (mn=absent/absent)" \
         "ok    RB the event processor is 5.18.87's again, on Uganda and South Sudan alike ($SS_SIG)" \
         "ok    RB the three numbers route exactly as before the rollback: $SHAPE" \
         "ok    RB the event queue lost nothing across the rollback (kept=3/3 missing=-)" \
         "ok    RB Domain B is installed exactly as $BASE has it: all 363 files" \
         "ok    RB 5.18.86's Inbox route is whole" "ok    RB 5.18.85's request form from the quotation and the technician's name are whole" \
         "ok    RB 5.18.84's booking WhatsApp is whole" "ok    RB 5.18.83's Customer Installation Authorisation is whole" \
         "ok    RB migration 086 (5.18.83's) is still applied and complete" \
         "note  RB the code is 5.18.87 again" "087's file among them, already recorded wherever it was applied. Its tables stay too, read by nothing while the switch is off"; do
  check "$(has "$OUT" "$l")" "yes" "7: ${l:0:96}"
done
check "$(live)$(installed_digest "$BASE")$(inst_ver)" "${BASE}05.18.87" "the container serves $BASE: every changed file as 5.18.87 has it, manifest 5.18.87"
check "$(grep -cF -- "$B0_PIN" "$PD/workers/AiReplyWorker.php")$(grep -cF -- "$LISTS" "$PD/cron/event_processor.php")$(grep -cF -- "$FS_WH" "$PD/evo_webhook.php")$(grep -cF -- "$IR_CALL" "$PD/includes/api/api_whatsapp.php")" "1004" \
  "after the rollback: Batch 0 kept, Batch 1 gone, 5.18.86's Inbox route whole"
check "$(mig87)$(wa_objects)|$(wa_rows)" "12:3:3|account/NULL/active,sales/NULL/active,support/NULL/active" "087's tables stay, as the plugin applied them — read by nothing while the switch is off"
check "$(data_digest)$(host_code_left)$(left_tmp)" "${D2}0$TMP0" "the rollback changed no data, and left no copy of the code anywhere"
cfg_keep; cfg_set multi_number_channels_enabled on store; fresh; OUT="$(run --answer ROLLBACK --rollback)"
check "$(has "$OUT" 'note  the container already serves')$(has "$OUT" 'FAIL  RB 5.18.87 reads the registry, or its switch is not off: enabled=off/on forStore=off/on')$(has "$OUT" ' registry-reads=0')" "yesyesno" \
  "7b control on the control: with the switch ON in the Inbox's row, the same RB check sees 5.18.87 read the registry — the recording handle sees reads when there are any"
cfg_back; fresh; OUT="$(run --answer ROLLBACK --rollback)"
check "$(fails "$OUT")$(has "$OUT" '5.18.88 (rollback): PASSED')" "0yes" "7c the switch off again: the rollback's checks PASS"

echo; echo "== 8. what the rehearsal left behind =="
check "$(checkout_state)" "$CHECKOUT0" "this checkout is as the rehearsal found it: same commit, same tracked files"
check "$(ls "$REPO/scripts"/.mutant-* "$REPO/scripts"/.v.sh "$REPO/scripts"/.unpinned.sh "$REPO/scripts"/.tip.sh "$REPO/scripts"/.ssv.sh "$REPO/scripts"/.dbv.sh "$REPO/scripts"/.rtv.sh 2>/dev/null | wc -l | tr -d ' ')" "0" "no weakened or re-pinned copy is left in the clone's scripts/"
check "$(left_tmp)" "$TMP0" "no copy of the code and no throwaway database is left in /tmp ($TMP0 before the rehearsal)"
check "$(grep -c 'Fatal\|Parse error' "$SB/web.log")" "0" "the stand-in served every request without a PHP fatal"

echo; echo "rehearsal: $PASS passed, $FAILN failed ($(cat "$SB/runs") runs of the script)"
[ "$FAILN" = "0" ]
