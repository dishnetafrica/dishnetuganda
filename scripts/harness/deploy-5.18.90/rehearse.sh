#!/usr/bin/env bash
# Rehearse scripts/deploy-5.18.90.sh — the deploy, its checks, and the ROLLBACK — before the operator runs any of it.
#
# 5.18.90 over 5.18.89: the pre-pilot safety fix (docs/65 §AD, docs/66), dark. Behind multi_number_channels_enabled, which
# stays OFF: no DishNet number's assistant answers another DishNet number (the internal-number guard), every automated send
# asks one policy first (AutomationPolicy), a salesperson's number answers nothing until the department numbers are
# verified, and the registry cannot be switched on before then. With the switches as they are, ONE thing changes: the
# department numbers' "Verify number" on the Salesperson numbers card (Uganda). No migration — 087 holds the numbers.
# Deployed from a RELEASE COMMIT on release/5.18.90, cut on the live 5.18.89 release commit (53d5c4d), not from the branch
# tip, which also carries the partner portal, the PD-8 CSRF guard and the AI media layer. The base is installed AS
# PRODUCTION RUNS IT AFTER THE 07 OCT PILOT: 5.18.89, migrations 086 and 087 applied by the plugin's own runner, 087's
# three department rows with no instance and no number, ONE salesperson's number added through 5.18.89's card — switched
# off, its assistant switched off, its trail — the authorisation and the booking WhatsApp ON in both copies, the Inbox's
# settings row naming Evolution and the three numbers — the support and account numbers sharing one instance, as
# production's do — lead capture ON and the uCRM write OFF in both copies, a live event queue and job photos. The Evolution
# the settings name is a stand-in that RECORDS every request. This rehearsal proves:
#   · the pin is exactly the release: cut on 5.18.89, 29 files (4 added, 25 changed), no migration — 087 the reviewed one,
#     unchanged — twenty-two files the safety fix's byte for byte, nothing of the media layer, the portal or the CSRF guard,
#     and Domain B untouched;
#   · stage A refuses, before anything changes, each refusal: a server not on 5.18.89 (by commit or by version); the
#     registry's switch ON or own leads only ON (either copy); 087 not recorded, recorded under another checksum, a trigger
#     missing, the pilot's number switched on by hand, a department number written by hand; a pin carrying a file that is
#     not the release's (a media-layer file, a migration, an altered 087) or lacking one; a pin touching Domain B or
#     anything outside the plugin; a pin whose Batch 2 rules or whose automated-send policy reach South Sudan (A6); a pin
#     whose policy is not inert with the registry off (A11); a backup that does not complete — and, with the allow-list
#     blinded, a pin whose code would route the three numbers differently (A9) or whose assistant prompt differs (A10);
#   · the deploy then runs end to end and PASSES: 087 complete before and after with the pilot's number untouched, the
#     registry dark and never consulted (R11), the policy inert on this server's configuration (A11, R2c) and acting as it
#     must on a throwaway database — on Uganda with the registry on, nothing on South Sudan (A6, R17) — the three numbers
#     routing exactly as on 5.18.89 (A9, R12), the assistant's prompt 5.18.89's (A10, R16), the Batch 2 rules as before
#     (A6, R15), the event processor as before (A6, R13), the event queue snapshotted and nothing lost, Domain B byte for
#     byte (R14), every live feature and switch as it was; the log free of every Evolution address, key and instance name
#     and of every number; no data written at all;
#   · each new check has teeth — R2c, R3's department numbers, R6, R11, R17, A8's two new refusals — and so does each one
#     carried over; department numbers verified through the installed registry as the card records them are reported,
#     never a failure; one written by hand is;
#   · the switches after the deploy, each with the installed tool: the registry's switch REFUSED by 5.18.90's set_config.php
#     while the department numbers are not verified, nothing saved; accepted once they are — and then the checks say at once
#     that the registry is not dark;
#   · the rollback returns the plugin to 5.18.89: the safety fix reached by nothing, Batch 2, Batch 1 and Batch 0 kept, 087
#     and the numbers in it still there and never read with the switch off; a redeploy over it passes with the department
#     numbers verified before the rollback; no data changed;
#   · nothing reaches Evolution: the stand-in records no request in any of the runs, and records the one the rehearsal makes
#     itself at the end — the control on the control;
#   · weakened copies of the script are caught;
#   · the clone, /tmp and the stand-in are left as found.
#
# Everything runs in a sandbox: a fake `docker` that maps /data/ucrm to a directory here (and supports exec, cp, logs), a
# clone of this checkout, the plugin installed as 5.18.89 exactly (git archive of the release commit), a stand-in web
# server for stage V's pages that boots the INSTALLED plugin's store on every request, as the real one does, and the
# recording stand-in for Evolution. DEPLOY and ROLLBACK are typed through a pseudo-terminal, as the operator types them. It
# never touches this checkout, the server or the network, and refuses to run where the server could be. The Evolution
# settings, the numbers and the staff seeded are fictitious.
#
#   bash scripts/harness/deploy-5.18.90/rehearse.sh            REHEARSE_KEEP=<dir> keeps every run's full output
set -u
R="$(cd "$(dirname "$0")/../../.." && pwd)"
for f in /data/ucrm /opt/dishnet /var/run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
BASE=53d5c4d; RA_BASE=e076632; OLDER=6464204; BRANCH_FILE=scripts/deploy-5.18.90.sh; P=dishnet-hybrid-sudan; RELEASE_BRANCH=release/5.18.90
FIXDEV=a694161                                                                                   # the safety fix, on the branch
MIG=migrations/087_wa_channels.sql; MIG86=migrations/086_install_authorisation.sql
MIG_SHA=3feda1b44ca79c1ad0057f029c032b741a581f4383e7378480129db1f1385aaa                           # the reviewed 087, applied 7 Oct
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
CARD='<div class="wa-card" id="sales-numbers">'                                                     # 5.18.89's card
PERSONA='        $owner = self::lineOwner($ctx, $transport);'                                       # 5.18.89's persona
OWNER_RULE="            if (\$owner !== null && (\$originRow['channel_owner_type'] ?? '') === 'staff'"   # 5.18.89's owned lead
HOLD="[\$holdSql, \$holdArgs] = \$ownedHold->sqlExclusion('c.channel');"                            # 5.18.89's follow-up hold
OL_LINE="'sales_own_leads_only' => ['bool',"                                                        # 5.18.89's switch
GATE_LV='            return \StaffJobsGate::applies($config, $dataDir);'                            # LeadVisibility's and the registry's country gate
GATE_SN='            if (!\StaffJobsGate::applies($config, $dataDir)) return false;'                # the card's country gate
IDLINE='            $p = "You are the DishNet assistant, replying to a customer {$where}.\n";'      # the identity line with no owner
POLICY_GATE='        if ($pdo === null || !\ChannelRegistry::enabled($config, $dataDir)) return self::inert();'   # 5.18.90: the policy, inert with the registry off
WH_STEP='        $_evoWhy = $evo->automationPolicy()->senderClass($channel, $phone);'                # 5.18.90: the webhook's DishNet-number step
DEPT_VERIFY="'sn_owner', 'sn_verify_department'];"                                                  # 5.18.90: the department numbers' Verify number
SC_REFUSE="if (!\$clear && \$key === 'multi_number_channels_enabled' && filter_var(\$new, FILTER_VALIDATE_BOOLEAN)) {"   # 5.18.90: set_config refuses the registry
GUARD_REG='    $_wg_regOn = ChannelRegistry::enabled($_wg_config, $_wg_data);'                       # 5.18.90: the guard records numbers only with the registry on
SENDER_CLASS="        if (\$this->mode !== 'on' || \$this->internal === null) return '';"             # 5.18.90: the policy classifies only with the registry on
SS_SIG="crm.lead.sync=done/0+unknown ai.reply=pending/0 ai.media=done/0+unknown wa.escalation=done/0+unknown install.ready=done/0+unknown"
UG_SIG="crm.lead.sync=pending/0 ai.reply=pending/0 ai.media=done/0+unknown wa.escalation=done/0 install.ready=done/0+unknown"
B2_SIG="uganda+on: card=shown visibility=on lead=hidden registry=on hold=held | uganda+off: card=shown visibility=off lead=sees registry=off hold=free | south-sudan+on: card=hidden visibility=off lead=sees registry=off hold=free"
PS_SIG="cases=12 same=12 differs=- control=same base-ignores=no"
P90_SIG="uganda+on: gaps=sales,support seller=internal_numbers_incomplete; verified: gaps=- seller=- seller>dept=internal_recipient dept>seller=internal_recipient dept=-; assistant-off: seller=channel_assistant_disabled | uganda+off: policy=off forStore=off seller=- seller>dept=- dept>seller=- own=- sql=- ai=- | south-sudan+on: policy=off forStore=off seller=- seller>dept=- dept>seller=- own=- sql=- ai=-"
POL_SIG="policy=off/off refusal=-/- sql=-/- evo=off,-/off,- statements=0 registry-reads=0"
SHAPE="sales:in=sales support:in=support account:in=support shared=support+account evo=yes registry=off"   # production's: support and account share
EXPECT_N=29; EXPECT_AD=4
checkout_state() { { git -C "$R" rev-parse HEAD; git -C "$R" status --porcelain --untracked-files=no; git -C "$R" diff HEAD; } | sha256sum | cut -c1-16; }
CHECKOUT0="$(checkout_state)"
left_tmp() { ls -d /tmp/dnb-5.18.90-* 2>/dev/null | wc -l | tr -d ' '; }
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
check "$(git -C "$REPO" rev-parse --short "$PIN^" 2>/dev/null)" "$BASE" "control: the release commit is cut on $BASE (5.18.89), the live version"
ver() { git -C "$REPO" show "$1:$P/manifest.json" | python3 -c 'import json,sys; print(json.load(sys.stdin)["information"]["version"])'; }
check "$(ver "$PIN")" "5.18.90" "the plugin at the pin is 5.18.90"
check "$(ver "$BASE")" "5.18.89" "control: the base is 5.18.89"
dl() { git -C "$REPO" diff --no-renames --name-only --diff-filter="$1" "$2" "$3" -- $P; }
CHANGED="$(dl AM "$BASE" "$PIN")"
N_CH="$(grep -c . <<<"$CHANGED")"; N_AD="$(dl A "$BASE" "$PIN" | grep -c .)"; N_MO=$((N_CH - N_AD)); N_DEL="$(dl D "$BASE" "$PIN" | grep -c .)"
echo "  5.18.90   $N_CH files: $N_MO changed, $N_AD added · $N_DEL removed or renamed"
check "$N_DEL" "0" "control: 5.18.89 → 5.18.90 removes no file"
check "$N_AD" "$EXPECT_AD" "control: 5.18.90 adds exactly $EXPECT_AD files"
check "$N_CH" "$EXPECT_N" "control: $EXPECT_N files in the delta"
TAKEN="lib/InternalNumbers.php lib/AutomationPolicy.php cron/followup_run.php cron/followup_scan.php cron/followup_send.php cron/wa_watchdog.php cron/wa_webhook_guard.php lib/ChannelRegistry.php lib/FollowUpService.php lib/SalesNumbersAdmin.php tabs/engage/wa_ai_setup.php tools/channels.php manifest.json tests/test_pilot_safety.php tests/test_sales_pilot.php tests/fixtures/staff_jobs_sandbox.php tests/test_channel_registry.php tests/test_distributor_apply.php tests/test_distributor_link_ucrm.php tests/test_distributor_notify.php tests/test_distributor_registry.php tests/test_distributor_territory.php"
PORTED="evo_webhook.php lib/EvolutionApiService.php workers/AiReplyWorker.php tools/set_config.php tests/test_multi_number_routing.php tests/test_sales_numbers.php tests/fixtures/fake_evo_server.php"
for want in $TAKEN $PORTED; do
  check "$(grep -c "^$P/$want$" <<<"$CHANGED")" "1" "control: the delta includes $want"
done
check "$(grep -c "^$P/migrations/" <<<"$CHANGED")$(git -C "$REPO" show "$PIN:$P/$MIG" | sha256sum | cut -c1-64)" "0$MIG_SHA" "control: no migration in the delta — 087 at the pin the reviewed file (sha256 ${MIG_SHA:0:16}…), unchanged"
check "$(grep -cE "^$P/(partner_api\.php|lib/Partner|lib/StaffApiCsrf\.php|lib/Totp\.php|lib/DistributorPortalData\.php|lib/Media|lib/InboundMedia|lib/Voice|lib/Image|lib/Document|lib/Pdf|lib/Handover\.php|workers/MediaWorker|run_media_worker|dishnet-mikrotik-control-plane/|docs/)" <<<"$CHANGED")" "0" \
  "control: no partner-portal, CSRF or AI media-layer file in the delta, and nothing of Domain B or the plugin's docs"
check "$(git -C "$REPO" diff --name-only "$BASE" "$PIN" | grep -vc "^$P/")" "0" "control: the whole repository's delta is the plugin's files alone"
check "$(git -C "$REPO" rev-parse "$BASE:$P/dishnet-mikrotik-control-plane")$(git -C "$REPO" rev-parse "$BASE:$P/docs")" \
      "$(git -C "$REPO" rev-parse "$PIN:$P/dishnet-mikrotik-control-plane")$(git -C "$REPO" rev-parse "$PIN:$P/docs")" "control: Domain B's tree and the plugin's docs are the same trees at the base and the pin"
same_as_fix() { [ "$(git -C "$REPO" rev-parse "$PIN:$P/$1" 2>/dev/null)" = "$(git -C "$REPO" rev-parse "$FIXDEV:$P/$1" 2>/dev/null)" ] && echo same || echo differs; }
check "$(for f in $TAKEN; do same_as_fix "$f"; done | sort | uniq -c | tr -s ' ' | tr '\n' ' ')" " 22 same " "control: the twenty-two files taken whole are the safety fix's ($FIXDEV) byte for byte"
check "$(for f in $PORTED; do same_as_fix "$f"; done | sort | uniq -c | tr -s ' ' | tr '\n' ' ')" " 7 differs " "control: the seven ported ones differ from the branch's — they carry no AI media layer"
at() { git -C "$REPO" show "$1:$P/$2" 2>/dev/null | grep -cF -- "$3"; }
check "$(at "$PIN" lib/AutomationPolicy.php "$POLICY_GATE")$(at "$PIN" evo_webhook.php "$WH_STEP")$(at "$PIN" lib/SalesNumbersAdmin.php "$DEPT_VERIFY")$(at "$PIN" tools/set_config.php "$SC_REFUSE")$(at "$PIN" cron/wa_webhook_guard.php "$GUARD_REG")" "11111" \
  "control: at the pin the safety fix is in place — the policy inert with the registry off, the webhook's DishNet-number step, the department numbers' Verify number, set_config.php's refusal, the guard's registry gate"
check "$(at "$PIN" evo_webhook.php 'MediaPolicy')$(at "$PIN" workers/AiReplyWorker.php 'VoiceTranscription')$(at "$PIN" tools/set_config.php "'ai_media_enabled'")$(at "$PIN" workers/AiReplyWorker.php 'Handover::escalate')" "0000" \
  "control: and no AI media layer at the pin — no media branch in the webhook, no media path or shared hand-over in the AI worker, no media switch"
check "$(at "$PIN" tabs/engage/wa_ai_setup.php "$CARD")$(at "$PIN" lib/DishNetAiBrain.php "$PERSONA")$(at "$PIN" lib/AiLeadService.php "$OWNER_RULE")$(at "$PIN" cron/followup_scan.php "$HOLD")$(at "$PIN" tools/set_config.php "$OL_LINE")$(at "$PIN" cron/event_processor.php "$LISTS")$(at "$PIN" evo_webhook.php "$FS_WH")$(at "$PIN" workers/AiReplyWorker.php "$FS_W")$(at "$PIN" lib/EventBus.php "$EXCL")$(at "$PIN" lib/AiLeadService.php "$ORIGIN")$(at "$PIN" tools/set_config.php "$FLAGLINE")$(at "$PIN" workers/AiReplyWorker.php "$B0_PIN")$(at "$PIN" workers/UcrmLeadWorker.php "$B0_PO")$(at "$PIN" includes/api/api_whatsapp.php "$IR_CALL")$(at "$PIN" lib/InstallAuth.php "$RULE")$(at "$PIN" webhook.php "$WA_CALL")$(at "$PIN" includes/api/api_install_auth.php "$QP_READ")" "12111111111114111" \
  "control: 5.18.89's Batch 2, 5.18.88's Batch 1, 5.18.87's Batch 0, 5.18.86's Inbox route, 5.18.85's quotation read, 5.18.84's booking WhatsApp and 5.18.83's authorisation are whole at the pin"
check "$(at "$BASE" lib/AutomationPolicy.php "$POLICY_GATE")$(at "$BASE" evo_webhook.php "$WH_STEP")$(at "$BASE" lib/SalesNumbersAdmin.php "$DEPT_VERIFY")$(at "$BASE" tools/set_config.php "$SC_REFUSE")$(at "$BASE" cron/wa_webhook_guard.php "$GUARD_REG")" "00000" "control: 5.18.89 has none of it"
HEADER_CMD="$(sed -n '/^# Run as root/,/^# The rollback is a separate command/p' "$DEPLOY")"
check "$(grep -c 'deploy-5.18.90.sh 2>&1 | tee' <<<"$HEADER_CMD")$(grep -cE -- '--rollback|--key|--value|set_config|git checkout [0-9a-f]{7}' <<<"$HEADER_CMD")" "10" \
  "the header's deploy command stands alone: no rollback, no switch command and no checkout of another commit in its block (docs/44 §16.9)"
check "$(grep -c "git fetch origin $RELEASE_BRANCH" <<<"$HEADER_CMD")" "1" "the header's deploy command fetches the release branch"
SWITCH_KEYS='customer_wa_install_scheduled|install_auth_enabled|multi_number_channels_enabled|ai_lead_capture|ai_crm_lead_sync|ai_qualification|ai_sales_on_all_numbers|sales_own_leads_only|wa_handover_copy_central|wa_followups_on_owned_numbers'
SWITCH_RX="--key +($SWITCH_KEYS)|($SWITCH_KEYS) +--value"
check "$(grep -cE -- "$SWITCH_RX" "$DEPLOY")" "0" "the script carries no command that switches a feature, the registry, own leads only or a lead switch — neither on nor off"
check "$(sed '/^P90_PHP="\$(cat <<.PHP.$/,/^PHP$/d' "$DEPLOY" | grep -cE -- '->(create|verifyNumber|verifyDepartmentNumber|setStatus|setAiEnabled|setInstance|setOwner)\(')$(grep -cE -- '->(create|verifyNumber|verifyDepartmentNumber|setStatus|setAiEnabled|setInstance|setOwner)\(' "$DEPLOY")" "06" \
  "the script never verifies, creates or switches a number on the plugin's data — every registry write it names is P90's, on the throwaway database (the control: it names them there)"

# ── The container: 5.18.89 installed AS PRODUCTION RUNS IT after the 07 Oct pilot, its data beside it ─
MOUNT="$SB/mount"; PLUGINS="$MOUNT/ucrm/data/plugins"; PD="$PLUGINS/$P"; DATA="$PLUGINS/.$P-data"
VAULT="$PLUGINS/.dishnet-sudan.vault.json"
mkdir -p "$PD" "$DATA" "$SB/web" "$SB/bin" "$SB/out"
IN_CONTAINER="/data/ucrm/data/plugins/$P"
free_port() { python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()'; }
WPORT="$(free_port)"; PLUGIN_BASE="http://127.0.0.1:$WPORT/public.php"
printf '%s\n%s\n%s\n%s\n' "$PD" "$DATA" "$SB/container.log" "$SB/web" > "$SB/web/.installed_plugin"
# The stand-in public.php. On every request it boots the INSTALLED plugin's store, as the real public.php does.
# ?page=install_auth runs the INSTALLED page itself; the sign-in page carries the installed viewport line; the staff
# dashboard sends a visitor with no session to the sign-in page, as public.php's requireLogin() does. A test drops a
# trigger file to make something happen DURING a run, on its first request: a photo taken (.add_photo), a photo lost
# (.del_photo), the Inbox's account number moved (.mutate_cfg), a fatal of the plugin in the container log
# (.plant_fatal), the WhatsApp AI screen answering a visitor with no session with the card (.leak_card). Each trigger is
# spent once.
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
if ($page === 'dashboard') {
    if ($spend('.leak_card')) { echo '<html><body><div class="wa-card" id="sales-numbers">leaked</div></body></html>'; exit; }
    header('Location: ?page=login', true, 302); exit;
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
# A stand-in for Evolution that answers anything and RECORDS every request it receives: the Inbox's settings row names it,
# so a call by the deploy, its checks, the installed code they run or the rollback would show here (section 8).
EPORT="$(free_port)"; EVO_URL="http://127.0.0.1:$EPORT/rh-evo"; mkdir -p "$SB/evo"
cat > "$SB/evo/router.php" <<'PHP'
<?php
file_put_contents(__DIR__ . '/requests.log', $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND);
header('Content-Type: application/json'); echo '[]';
PHP
php -S "127.0.0.1:$EPORT" "$SB/evo/router.php" >"$SB/evo.log" 2>&1 & PIDS+=($!)
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
add_number() {     # add_number ID INSTANCE — a salesperson's number exactly as the card adds one: through the INSTALLED
                   # ChannelRegistry, staff-owned by the seeded salesperson, handing over to them, switched OFF, with the trail
  php -r '
    foreach (["bootstrap_data", "StoreInterface", "JsonStore", "SqliteStore", "ChannelRegistry", "EvolutionApiService"] as $l) require_once $argv[1] . "/lib/$l.php";
    $s = SqliteStore::create($argv[2]);
    $staff = 0; foreach ((array)($s->load("retailers.json") ?? []) as $r) if (($r["role"] ?? "") === "sales") $staff = (int)$r["id"];
    (new ChannelRegistry($s->getPdo(), $s))->create(["channel_id" => $argv[3], "evo_instance" => $argv[4], "display_name" => "Sales — Rehearsal Seller",
        "role" => "sales", "owner_type" => "staff", "owner_staff_id" => $staff, "portfolio_scope" => "own", "handover_to" => "owner",
        "ai_enabled" => true, "status" => "disabled"], "Rehearsal Admin (#1)", "added on the WhatsApp AI screen",
        EvolutionApiService::configInstanceMap((array)$s->load("kyc_config.json")));
    echo "done";' "$PD" "$DATA" "$1" "$2" 2>&1
}
seed_data() {      # the plugin's databases, written by 5.18.89's own store (084, 086 and 087 applied by its runner, as production's
                   # runner applied 087 on 07 Oct); then production's state after 5.18.89's deploy: the authorisation and the
                   # booking WhatsApp ON in both copies, activation #1
                   # with job 20 exempt, the Inbox's settings row naming Evolution and the three numbers — support and account
                   # sharing one instance, as production's do (fictitious: the recording stand-in) — lead capture ON and the uCRM write
                   # OFF in both copies, qualification and selling on every number ON; an event queue with work in it; two photos
  rm -rf "$DATA"/plugin.sqlite3* "$DATA"/dishnet.sqlite* "$DATA"/config.json "$DATA"/kyc_config.json "$DATA"/migration.log "$DATA"/uploads "$DATA"/webhook_log.json
  php -r '
    foreach (["bootstrap_data", "StoreInterface", "JsonStore", "SqliteStore", "EventBus"] as $l) require_once $argv[1] . "/lib/$l.php";
    require_once $argv[1] . "/lib/CashbookService.php";
    $s = SqliteStore::create($argv[2]);
    $s->save("kyc_config.json", ["crm_base_url" => "http://127.0.0.1:1", "company_name" => "DishNet Sandbox", "currency_code" => "UGX",
        "tenant_profile" => "uganda", "cashbook_base_currency" => "UGX", "cashbook_currencies" => "UGX,USD", "currency_symbol" => "UGX",
        "install_auth_job_titles" => "Starlink Installation", "install_auth_whatsapp" => "1", "ai_enabled" => "1", "ai_provider" => "openai",
        "evo_api_url" => $argv[3], "evo_api_key" => "rh-key-secret", "evo_instance_sales" => "rh-sales",
        "evo_instance_support" => "rh-shared", "evo_instance_account" => "rh-shared"]);
    file_put_contents($argv[2] . "/kyc_config.json", json_encode($s->load("kyc_config.json")));
    $s->appendWithId("retailers.json", ["name" => "Rehearsal Tech", "email" => "tech@example.test", "role" => "support", "is_active" => true]);
    $s->appendWithId("retailers.json", ["name" => "Rehearsal Seller", "email" => "seller@example.test", "role" => "sales", "is_active" => true]);
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
  ' "$PD" "$DATA" "$EVO_URL" >/dev/null
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
data_dump() {   # every table's rows — 087's two included — but the ledger of migrations; the vault; the configuration files; the photos
  php -r '$p = new PDO("sqlite:" . $argv[1]); $skip = ["_migrations", "sqlite_sequence"];
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
installed_digest() {   # $1 a commit: the number of files 5.18.90 touches that are NOT installed as that commit has them
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
release_variant() {   # release_variant add|addroot|revert|edit PATH [OLD NEW] — a commit cut on 5.18.89 holding the release's tree
                      # with one file planted (in the plugin, or anywhere in the repository), put back as 5.18.89 has it, or edited
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
LEAK_RX='rh-evo|rh-key-secret|rh-sales|rh-shared|rh-moved|rh-seller|rh-two'

edit_installed() {   # edit_installed FILE OLD NEW — the INSTALLED file with OLD replaced, exactly once
  python3 - "$PD/$1" "$2" "$3" <<'PY'
import sys
p, old, new = sys.argv[1], sys.argv[2], sys.argv[3]
s = open(p).read(); assert s.count(old) == 1, "installed anchor not unique: " + old[:80]
open(p, 'w').write(s.replace(old, new))
PY
}

set_ai() {         # set_ai ID 0|1 — a number's assistant switched as the card switches it: through the INSTALLED ChannelRegistry, with its trail
  php -r '
    foreach (["bootstrap_data", "StoreInterface", "JsonStore", "SqliteStore", "ChannelRegistry"] as $l) require_once $argv[1] . "/lib/$l.php";
    $s = SqliteStore::create($argv[2]);
    (new ChannelRegistry($s->getPdo(), $s))->setAiEnabled($argv[3], $argv[4] === "1", "Rehearsal Admin (#1)", "switched on the WhatsApp AI screen");
    echo "done";' "$PD" "$DATA" "$1" "$2" 2>&1
}
verify_dept() {    # verify_dept ID NUMBER — a department's number as 5.18.90's card records it once Evolution has reported it: through
                   # the INSTALLED ChannelRegistry::verifyDepartmentNumber, beside the instance the configuration names (no Evolution call)
  php -r '
    foreach (["bootstrap_data", "StoreInterface", "JsonStore", "SqliteStore", "ChannelRegistry", "EvolutionApiService"] as $l) require_once $argv[1] . "/lib/$l.php";
    $s = SqliteStore::create($argv[2]);
    $inst = (string)(EvolutionApiService::configInstanceMap((array)$s->load("kyc_config.json"))[$argv[3]] ?? "");
    (new ChannelRegistry($s->getPdo(), $s))->verifyDepartmentNumber($argv[3], $argv[4], "Rehearsal Admin (#1)", "read from Evolution\x27s report", $inst);
    echo "done";' "$PD" "$DATA" "$1" "$2" 2>&1
}
evo_calls() { local n=0; [ -f "$SB/evo/requests.log" ] && n="$(wc -l < "$SB/evo/requests.log" | tr -d ' ')"; echo "$n"; }
evo_ready() { python3 -c 'import socket,sys; s=socket.socket(); s.settimeout(0.2); s.connect(("127.0.0.1", int(sys.argv[1]))); s.close()' "$EPORT" 2>/dev/null; }
NUM_RX='2567000003[0-9]{2}'   # the department numbers the rehearsal verifies: never printed by the script

install_base; seed_data; vault UGX; fresh
flip --on   # enable the pilot through the real tool, exactly as the live server has it
for i in $(seq 1 50); do [ "$(standin_page customer_login)" = "200" ] && break; sleep 0.1; done
for i in $(seq 1 50); do evo_ready && break; sleep 0.1; done
# Step 0 of 5.18.87's handover, as the operator ran it on 07 Oct: the uCRM lead write off, with the installed tool.
S0="$(setcfg ai_crm_lead_sync 0)"
# The 07 Oct pilot as production's record has it: one salesperson's number added through 5.18.89's card, switched off,
# then its assistant switched off — through the installed 5.18.89 registry, each with its trail row.
PILOT="$(add_number sales-001 rh-seller)$(set_ai sales-001 0)"
D0="$(data_digest)"
M87MD5="$(md5sum "$PD/$MIG" | cut -c1-32)"
ROWS0="$(sq "SELECT COUNT(*) FROM wa_channels"):$(sq "SELECT COUNT(*) FROM wa_channel_log")"
ST0="mig=applied:$M87MD5 tables=2/2 indexes=3/3 triggers=3/3 checks=2/2 rows=$ROWS0 seed=ok dnum=0 other=1 active=0 oai=0 trail=ok dtrail=0 missing=-"
check "$(inst_ver)" "5.18.89" "control: the installed base is 5.18.89"
check "$(grep -cF -- "$B0_PIN" "$PD/workers/AiReplyWorker.php")$(grep -cF -- "$B0_PO" "$PD/workers/UcrmLeadWorker.php")$(grep -cF -- "$LISTS" "$PD/cron/event_processor.php")$(grep -cF -- "$FS_WH" "$PD/evo_webhook.php")$(grep -cF -- "$CARD" "$PD/tabs/engage/wa_ai_setup.php")$(grep -cF -- "$PERSONA" "$PD/lib/DishNetAiBrain.php")$(grep -cF -- "$WH_STEP" "$PD/evo_webhook.php")$(test -f "$PD/lib/AutomationPolicy.php" && echo 1 || echo 0)" "11111200" \
  "control: the base is production's: Batch 0's lead fixes, Batch 1 and Batch 2 (the persona twice: the identity line and rule 4a) in place, none of the safety fix"
check "$(has "$S0" 'ai_crm_lead_sync = 0')$(both_copies ai_crm_lead_sync)$(both_copies ai_lead_capture)$(both_copies ai_qualification)$(both_copies ai_sales_on_all_numbers)" "yes00111111" \
  "control: the lead switches as production has them since 5.18.87's step 0 — capture ON, the uCRM write OFF, both copies"
check "$(row_has evo_api_url)$(row_has evo_api_key)$(row_has evo_instance_sales)$(both_copies evo_instance_support)$(both_copies evo_instance_account)$(row_has multi_number_channels_enabled)$(row_has sales_own_leads_only)" "yesyesyesrh-sharedrh-sharedrh-sharedrh-sharednono" \
  "control: the Inbox's row names Evolution (the recording stand-in) and three numbers, support and account sharing one instance as production's do; the registry's switch and own leads only unset"
check "$(ia_objects)$(sq "SELECT COUNT(*) FROM _migrations WHERE filename = '086_install_authorisation.sql'")$(ia_rows)" "5:10:410:1:1:1:0" \
  "control: 086 applied by the plugin's own runner, complete, with production's records"
check "$PILOT|$(mig87)$(wa_objects)|$(wa_rows)|$(sq "SELECT ai_enabled FROM wa_channels WHERE channel_id = 'sales-001'")|$(sq "SELECT COUNT(*) FROM wa_channels WHERE business_number IS NOT NULL")|$(sq "SELECT group_concat(action, ',') FROM wa_channel_log WHERE channel_id = 'sales-001'")|$(sq "SELECT checksum FROM _migrations WHERE filename = '087_wa_channels.sql'")" \
  "donedone|12:3:3|account/NULL/active,sales/NULL/active,sales-001/rh-seller/disabled,support/NULL/active|0|0|created,ai_enabled|$M87MD5" \
  "control: 087 applied by 5.18.88's runner, the three department rows with NO instance and NO number, and the pilot's number as production has it — added through the card, switched off, its assistant off, its trail; the ledger row the installed file's"
check "$(grep -cF 'OK: 087_wa_channels.sql (14 stmts, ' "$DATA/migration.log")$(grep -cE 'PARTIAL|ERROR|WARNING' "$DATA/migration.log")" "10" "control: …and the runner's own log line for it, all 14 statements counted, nothing PARTIAL"
check "$(sq "SELECT COUNT(*) FROM events")|$(sq "SELECT COUNT(*) FROM events WHERE status <> 'done'")|$(sq "SELECT COUNT(*) FROM job_photos")|$(find "$DATA/uploads/job_photos" -type f | wc -l | tr -d ' ')" "6|3|2|2" \
  "control: an event queue with work in it (6 events, 3 not done) and two photos with their files"
check "$(env PATH="$SB/bin:$PATH" DN_DATA_DIR="$DATA" bash "$SB/bin/docker" exec ucrm php "$IN_CONTAINER/tools/set_distributors.php" --show 2>/dev/null | awk '/distributors_enabled/ {print toupper($2); exit}')" "ON" "control: the pilot is ON on the base"
check "$(standin_page customer_login)$(standin_page install_auth)$(standin_page 'dashboard&tab=wa_ai_setup')$(data_digest)" "200404302$D0" \
  "control: the stand-in boots the installed 5.18.89 store, the live authorisation page refuses a request without a link, the staff dashboard sends a visitor with no session to the sign-in page, and none changes any data"
check "$(evo_ready && echo up)$(evo_calls)" "up0" "control: the recording stand-in for Evolution is listening, and nothing has called it"

echo; echo "== 1. NO-GO before anything changes — each refusal, and nothing deployed, backed up or changed =="
nogo() {   # nogo LABEL EXPECTED-TEXT OUTPUT — the refusal said, nothing deployed, no backup, the data as seeded
  check "$(has "$3" "$2")$(live)$(backups)$(data_digest)" "yes${BASE}0$D0" "$1"
}
printf '%s\n' "$OLDER" > "$PD/.deployed-commit"; fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: the container serves $OLDER; 5.18.90 was built and tested against $BASE (5.18.89) — deploy 5.18.89 first (scripts/deploy-5.18.89.sh) and send its log")$(backups)$(data_digest)" "yes0$D0" \
  "1a refusal 1: the server still runs 5.18.88 — NO-GO, 5.18.89 goes first; no backup, no data changed"
check "$(live)" "$OLDER" "…the record of 5.18.88 left as found"; printf '%s\n' "$BASE" > "$PD/.deployed-commit"
cp "$PD/manifest.json" "$SB/manifest.kept"; sed -i 's/"version": "5.18.89"/"version": "5.18.88"/' "$PD/manifest.json"; fresh; OUT="$(run --answer DEPLOY)"
nogo "1b refusal 1: the record says $BASE but the installed manifest says 5.18.88 — NO-GO on the version too" \
  "STOP: NO-GO: the container's record says $BASE, but the installed manifest says 5.18.88, not 5.18.89" "$OUT"
cp "$SB/manifest.kept" "$PD/manifest.json"
S="$(pinned_copy unpinned __PLUGIN_COMMIT__)"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1c a copy still carrying the placeholder pin: stops before anything is read" "STOP: this copy of the script is not pinned to a reviewed commit" "$OUT"
S="$(pinned_copy tip "$TIP")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1d a copy pinned to the branch tip ($TIP, parent not 5.18.89): refused — the undeployed work cannot ride along" "STOP: $TIP is not cut on $BASE (5.18.89): its parent is" "$OUT"
V="$(release_variant add lib/MediaPolicy.php)"; S="$(pinned_copy v "$V")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1e refusal 5: the release AND an AI media-layer file — refused by the delta's allow-list, naming it" \
  "STOP: the pin carries files that are not 5.18.90's, which this release must not ship: lib/MediaPolicy.php(added)" "$OUT"
V_MIG="$(release_variant add migrations/088_rehearsal.sql)"; S="$(pinned_copy v "$V_MIG")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1f refusal 5: the release AND a migration — refused, naming it" \
  "STOP: the pin carries files that are not 5.18.90's, which this release must not ship: migrations/088_rehearsal.sql(added)" "$OUT"
V_87="$(release_variant edit "$MIG" '-- 087_wa_channels.sql — the WhatsApp channel registry' '-- 087_wa_channels.sql (altered) — the WhatsApp channel registry')"; S="$(pinned_copy v "$V_87")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1g refusal 5: 087 one comment different at the pin — 5.18.90 changes no migration: refused, naming it" \
  "STOP: the pin carries files that are not 5.18.90's, which this release must not ship: migrations/087_wa_channels.sql" "$OUT"
V="$(release_variant revert cron/followup_scan.php)"; S="$(pinned_copy v "$V")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1h the release WITHOUT the follow-up scan's policy — refused, naming the file it lacks" "STOP: the pin lacks files 5.18.90 is made of: cron/followup_scan.php" "$OUT"
V_DB="$(release_variant add dishnet-mikrotik-control-plane/REHEARSAL.md)"; S="$(pinned_copy v "$V_DB")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
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
  check "$(has "$OUT" "STOP: NO-GO: multi_number_channels_enabled reads '$want' (files/store). 5.18.90 ships dark")$(live)$(backups)$(data_digest)" "yes${BASE}0$DX" \
    "1l refusal 2: the registry's switch ON ($c — $want): NO-GO, nothing deployed, no data changed"
  check "$(grep -cE -- "$SWITCH_RX" <<<"$OUT")" "0" "…and the refusal prints no switch command"
  cfg_back
done
for c in both files store; do
  case "$c" in both) cfg_set sales_own_leads_only on; want="ol=on/on" ;; files) cfg_set sales_own_leads_only on files; want="ol=on/absent" ;; store) cfg_set sales_own_leads_only on store; want="ol=absent/on" ;; esac
  DX="$(data_digest)"; fresh; OUT="$(run --answer DEPLOY)"
  check "$(has "$OUT" "STOP: NO-GO: sales_own_leads_only reads '$want' (files/store). With it on every salesperson sees only their own leads — not the state 5.18.90 was rehearsed against, and the operator decided it stays OFF")$(live)$(backups)$(data_digest)" "yes${BASE}0$DX" \
    "1m refusal 9: own leads only ON ($c — $want): NO-GO — the operator decided it stays OFF"
  check "$(grep -cE -- "$SWITCH_RX" <<<"$OUT")" "0" "…and the refusal prints no switch command"
  cfg_back
done
check "$(data_digest)" "$D0" "control: the copies put back byte for byte"
db_keep
check "$(sqx "DELETE FROM _migrations WHERE filename = '087_wa_channels.sql'")" "done" "control: 087's ledger row removed by hand"
fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: migration 087 is not recorded on this server (${ST0/mig=applied:$M87MD5/mig=absent}), although 5.18.89 is live")$(live)$(backups)" "yes${BASE}0" \
  "1n refusal 3: 087 not recorded although 5.18.89 is live — NO-GO: 5.18.90 reads its tables"
db_back
check "$(sqx "UPDATE _migrations SET checksum = 'deadbeef' WHERE filename = '087_wa_channels.sql'")" "done" "control: 087's ledger row given another checksum"
fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: migration 087 is recorded on this server, but not as 5.18.89 left it (mig=applied:deadbeef tables=2/2")$(live)$(backups)" "yes${BASE}0" \
  "1o refusal 3: 087 recorded under a checksum that is not the installed file's — NO-GO"
db_back
check "$(sqx "UPDATE wa_channels SET status = 'active' WHERE channel_id = 'sales-001'")" "done" "control: the pilot's number switched on by hand — the card cannot do it while the registry is off"
fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: migration 087 is recorded on this server, but not as 5.18.89 left it (${ST0/active=0/active=1})")$(live)$(backups)" "yes${BASE}0" \
  "1p refusal 4: a salesperson's number switched on before the deploy — NO-GO"
check "$(grep -cE -- "$LEAK_RX" <<<"$OUT")" "0" "…without printing its instance name"
db_back
check "$(sqx "UPDATE wa_channels SET business_number = '+256700000301' WHERE channel_id = 'sales'")" "done" "control: a department's number written by hand — 5.18.89 cannot record one, and nobody verified it"
fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: migration 087 is recorded on this server, but not as 5.18.89 left it (mig=applied:$M87MD5 tables=2/2 indexes=3/3 triggers=3/3 checks=2/2 rows=$ROWS0 seed=differs:sales(number) dnum=1 other=1 active=0 oai=0 trail=ok dtrail=0 missing=-)")$(live)$(backups)" "yes${BASE}0" \
  "1p2 refusal 4: a department's number with no verification on the record — NO-GO, the department named, the number never printed"
check "$(grep -cE -- "$LEAK_RX|$NUM_RX" <<<"$OUT")" "0" "…the number printed nowhere"
db_back
check "$(sqx 'DROP TRIGGER wa_channels_never_deleted')" "done" "control: one of 087's triggers dropped"
fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: migration 087 is recorded on this server, but not as 5.18.89 left it (mig=applied:$M87MD5 tables=2/2 indexes=3/3 triggers=2/3 checks=2/2 rows=$ROWS0 seed=ok dnum=0 other=1 active=0 oai=0 trail=ok dtrail=0 missing=wa_channels_never_deleted)")$(live)$(backups)" "yes${BASE}0" \
  "1q refusal 4: 087 missing a trigger — NO-GO, naming it"
db_back
check "$(data_digest)$(mig87)$(wa_objects)" "${D0}12:3:3" "control: the database put back — the data as seeded, 087 whole"
V_SS="$(release_variant edit lib/AutomationPolicy.php "$POLICY_GATE" "        if (\$pdo === null || !filter_var(\$config['multi_number_channels_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) return self::inert();")"
S="$(pinned_copy v "$V_SS")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1r refusal 6: a pin whose automated-send policy forgets the country — NO-GO (South Sudan): the send policy named" \
  "STOP: NO-GO (South Sudan): the pin does not keep South Sudan exactly as it is, or the check could not be made: send-policy" "$OUT"
check "$(has "$OUT" '| south-sudan+on: policy=on forStore=off')$(has "$OUT" 'ok    A11 the pin')" "yesyes" \
  "…the pin's run printed the policy ON as South Sudan with every switch on — while on this server, the registry off, A11 still found it inert"
V="$(release_variant edit lib/ChannelRegistry.php "$GATE_LV" '            return true;')"; S="$(pinned_copy v "$V")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
check "$(has "$OUT" "STOP: NO-GO (South Sudan): the pin does not keep South Sudan exactly as it is, or the check could not be made: batch2-rules send-policy")$(has "$OUT" '| south-sudan+on: card=hidden visibility=off lead=sees registry=on hold=held')$(live)$(backups)$(data_digest)" "yesyes${BASE}0$D0" \
  "1s refusal 6: a pin whose registry forgets the country — NO-GO: on South Sudan the registry would be on, a salesperson's number held, and the policy active"
V="$(release_variant edit lib/SalesNumbersAdmin.php "$GATE_SN" '')"; S="$(pinned_copy v "$V")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
check "$(has "$OUT" "STOP: NO-GO (South Sudan): the pin does not keep South Sudan exactly as it is, or the check could not be made: batch2-rules")$(has "$OUT" '| south-sudan+on: card=shown visibility=off lead=sees registry=off hold=free')$(live)$(backups)$(data_digest)" "yesyes${BASE}0$D0" \
  "1t refusal 6: a pin whose card forgets the country — NO-GO: on South Sudan the card would be shown"
V_NI="$(release_variant edit lib/AutomationPolicy.php "$POLICY_GATE" '        if ($pdo === null) return self::inert();')"
S="$(pinned_copy v "$V_NI")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1u refusal 11: a pin whose automated-send policy reads the registry with its switch off — NO-GO (A11), on this server's own configuration" \
  "STOP: NO-GO: on this server's own configuration the pin's automated-send policy is not inert with the registry off, or could not be read (policy=on/on" "$OUT"
fresh; OUT="$(run --answer DEPLOY --env FAKE_CP_FAIL=1)"
nogo "1w the two releases' code cannot be copied into the container: NO-GO — A9, A10, A11 and A6 are refusals and cannot be made without it" \
  "STOP: NO-GO: the two releases' code could not be copied into the container's /tmp — A9, A10, A11 and A6 compare it under the server's own PHP, and all four are refusals" "$OUT"
fresh; OUT="$(run --answer DEPLOY --env DNB_SNAPSHOT_TMP=/nonexistent-dir)"
check "$(has "$OUT" 'FAIL  backup of plugin.sqlite3 failed')$(has "$OUT" 'STOP: NO-GO: the backup or a before-check did not complete')$(live)$(data_digest)" "yesyes${BASE}$D0" \
  "1x refusal 8: the database's backup fails — NO-GO after the backup stage, nothing deployed, no data changed"
check "$(has "$OUT" 'Type DEPLOY')" "no" "…never reaching the DEPLOY prompt"
cfg_keep; S1="$(setcfg ai_crm_lead_sync 1)"; DX="$(data_digest)"; fresh; OUT="$(run --answer NO)"
check "$(has "$OUT" "note  the lead switches read lc=on/on ls=on/on (files/store), not the 07 Oct decision")$(has "$OUT" 'STOP: not confirmed')$(live)$(data_digest)" "yesyes${BASE}$DX" \
  "1y A4 is evidence: the uCRM write switched on is said, never a stop — the run reached the DEPLOY prompt (answered NO), nothing deployed"
cfg_back
cfg_keep; cfg_set wa_followups_on_owned_numbers on; DX="$(data_digest)"; fresh; OUT="$(run --answer NO)"
check "$(has "$OUT" 'ok    A5b sales_own_leads_only reads OFF in both copies (ol=absent/absent; files/store) — every salesperson keeps seeing every lead; wa_handover_copy_central (hc=absent/absent) and wa_followups_on_owned_numbers (fh=on/on)')$(has "$OUT" 'STOP: not confirmed')$(live)$(data_digest)" "yesyes${BASE}$DX" \
  "1z A5b reports the other two switches and never stops on them — they act only on a salesperson's number"
cfg_back
db_keep; set_ai sales-001 1 >/dev/null; DX="$(data_digest)"; fresh; OUT="$(run --answer NO)"
check "$(has "$OUT" 'note  1 salesperson number(s) added through the card have their assistant ON')$(has "$OUT" 'none switched on, 1 with the assistant on; 5.18.90 adds no migration')$(has "$OUT" 'STOP: not confirmed')$(live)$(data_digest)" "yesyesyes${BASE}$DX" \
  "1z3 the pilot's assistant switched on before the deploy: A8 says so in a note and goes on to the prompt — with the registry off nothing answers on it"
db_back
cfg_keep; row_unset evo_instance_account; DX="$(data_digest)"; fresh; OUT="$(run --answer NO)"
check "$(has "$OUT" "note  the settings row the Inbox reads does not name every number (evo=yes sales=yes support=yes account=no registry=absent)")$(has "$OUT" 'STOP: not confirmed')$(live)$(data_digest)" "yesyes${BASE}$DX" \
  "1z2 A3 is evidence too: the account number missing from the Inbox's row is said, and the run goes on to the prompt"
check "$(grep -cE -- "$LEAK_RX" <<<"$OUT")" "0" "…and the run printed no Evolution address, key or instance name"
cfg_back
check "$(data_digest)$(host_code_left)$(left_tmp)$(evo_calls)" "${D0}0${TMP0}0" "control: the data as seeded; every run removed its copies of the code — on the host and in the container's /tmp; Evolution never called"

echo; echo "== 2. weakened copies of stage A are caught (the control on the control) =="
cfg_keep; cfg_set multi_number_channels_enabled on
M="$(mutant a5 "$DEPLOY" '  if ! sw_off "$MN_BEFORE"; then' '  if false; then')"
fresh; OUT="$(run --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" 'STOP: NO-GO: multi_number_channels_enabled reads')$(has "$OUT" "STOP: NO-GO: on this server's own configuration the pin's automated-send policy is not inert with the registry off")$(has "$OUT" 'STOP: not confirmed')$(live)$(backups)" "noyesno${BASE}0" \
  "2a the A5-blinded copy is no longer stopped at A5 with the registry's switch ON — detected; A11 stops it, the second layer, before anything changes"
M="$(mutant a5a11 "$DEPLOY" '  if ! sw_off "$MN_BEFORE"; then' '  if false; then' '  [ "$POL_A" = "$POL_SIGNATURE" ] || stop' '  [ "$POL_A" = "$POL_SIGNATURE" ] || true')"
fresh; OUT="$(run --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" 'STOP: NO-GO: multi_number_channels_enabled reads')$(has "$OUT" "STOP: NO-GO: on this server's own configuration the pin's automated-send policy")$(has "$OUT" 'STOP: not confirmed')$(live)" "nonoyes$BASE" \
  "2a2 blinded at A5 and at A11, the copy goes on to the DEPLOY prompt (answered NO) — the two layers are independent"
fresh; OUT="$(run --answer NO)"
check "$(has "$OUT" "STOP: NO-GO: multi_number_channels_enabled reads 'mn=on/on'")$(backups)" "yes0" "2b …the real script stops at A5 on the same switch"
cfg_back
cfg_keep; cfg_set sales_own_leads_only on
M="$(mutant a5b "$DEPLOY" '  if ! sw_off "$OL_BEFORE"; then' '  if false; then')"
fresh; OUT="$(run --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" 'STOP: NO-GO: sales_own_leads_only reads')$(has "$OUT" 'STOP: not confirmed')$(live)" "noyes$BASE" "2c the A5b-blinded copy goes on with own leads only ON, to the prompt — detected"
fresh; OUT="$(run --answer NO)"
check "$(has "$OUT" "STOP: NO-GO: sales_own_leads_only reads 'ol=on/on'")$(backups)" "yes0" "2d …the real script stops at A5b on the same switch"
cfg_back
db_keep; sqx "DELETE FROM _migrations WHERE filename = '087_wa_channels.sql'" >/dev/null
M="$(mutant a8 "$DEPLOY" '    "mig=absent "*)  stop "NO-GO: migration 087 is not recorded' '    "mig=absent "*)  true "NO-GO: migration 087 is not recorded')"
fresh; OUT="$(run --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" 'STOP: NO-GO: migration 087 is not recorded')$(has "$OUT" 'STOP: not confirmed')" "noyes" "2e the A8-blinded copy goes on with 087 not recorded, to the prompt — detected"
fresh; OUT="$(run --answer NO)"
check "$(has "$OUT" 'STOP: NO-GO: migration 087 is not recorded')" "yes" "2f …the real script stops at A8"
db_back
sqx "UPDATE wa_channels SET status = 'active' WHERE channel_id = 'sales-001'" >/dev/null
M="$(mutant a8b "$DEPLOY" '    "mig=applied:"*) stop "NO-GO: migration 087 is recorded on this server, but not as' '    "mig=applied:"*) true "NO-GO: migration 087 is recorded on this server, but not as')"
fresh; OUT="$(run --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" 'STOP: NO-GO: migration 087 is recorded on this server')$(has "$OUT" 'STOP: not confirmed')" "noyes" "2e2 the copy blind to a changed 087 goes on with the pilot's number switched on, to the prompt — detected"
fresh; OUT="$(run --answer NO)"
check "$(has "$OUT" 'STOP: NO-GO: migration 087 is recorded on this server, but not as 5.18.89 left it')" "yes" "2f2 …the real script stops at A8"
db_back
S="$(pinned_copy ssv "$V_SS")"; M="$(mutant a6 "$S" '[ -z "$A6_BAD" ] || stop' '[ -z "$A6_BAD" ] || true')"; rm -f "$S"
fresh; OUT="$(run --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" 'STOP: NO-GO (South Sudan)')$(has "$OUT" 'STOP: not confirmed')" "noyes" "2g the A6-blinded copy, pinned to the pin whose policy reaches South Sudan, goes on to the prompt — detected"
S="$(pinned_copy dbv "$V_DB")"; M="$(mutant a7 "$S" '[ -z "$DB_BAD" ] || stop' '[ -z "$DB_BAD" ] || true')"; rm -f "$S"
fresh; OUT="$(run --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" 'NO-GO (Domain B)')$(has "$OUT" "STOP: the pin carries files that are not 5.18.90's, which this release must not ship: dishnet-mikrotik-control-plane/REHEARSAL.md(added)")" "noyes" \
  "2h the A7-blinded copy no longer refuses the Domain B pin as Domain B (the allow-list still stops it) — detected"
V_RT="$(release_variant edit lib/EvolutionApiService.php "        return \$this->instanceToChannel[mb_strtolower(trim(\$instance))] ?? '';" "        \$c = \$this->instanceToChannel[mb_strtolower(trim(\$instance))] ?? ''; return \$c === 'support' ? 'account' : \$c;")"
S="$(pinned_copy rtv "$V_RT")"; fresh; OUT="$(run --answer NO --script "$S")"
check "$(has "$OUT" "STOP: NO-GO: with the registry off the pin's code would not route the three numbers exactly as the live code does (files: sales:in=sales support:in=account account:in=account shared=support+account evo=yes registry=off")$(live)$(backups)" "yes${BASE}0" \
  "2i a pin that changes how the shared number's inbound is routed — EvolutionApiService is one of the release's files, so the allow-list lets it through and A9 stops it on its own"
M="$(mutant a9 "$S" '  if [ "$MAP_P_F" != "$MAP_A_F" ] || [ "$MAP_P_S" != "$MAP_A_S" ]; then' '  if false; then')"; rm -f "$S"
fresh; OUT="$(run --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" "STOP: NO-GO: with the registry off the pin's code would not route")$(has "$OUT" 'STOP: not confirmed')" "noyes" "2j the A9-blinded copy goes on with that pin, to the prompt — detected"
S="$(pinned_copy migv "$V_MIG")"; M="$(mutant a0m "$S" '[ -z "$STRAY" ] || stop' '[ -z "$STRAY" ] || true')"; rm -f "$S"
fresh; OUT="$(run --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" "STOP: the pin carries 1 migration(s) (088_rehearsal.sql); 5.18.90 adds none — this script is for another build")$(live)$(backups)" "yes${BASE}0" \
  "2k with the allow-list blinded, a pin carrying a migration is stopped by the migration count"
S="$(pinned_copy m87v "$V_87")"; M="$(mutant a0n "$S" '[ -z "$STRAY" ] || stop' '[ -z "$STRAY" ] || true' '[ "$N_MIG" = "0" ] || stop' '[ "$N_MIG" = "0" ] || true')"; rm -f "$S"
fresh; OUT="$(run --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" "STOP: the pin's migrations/087_wa_channels.sql is not the reviewed one production applied on 7 Oct (sha256")$(live)$(backups)" "yes${BASE}0" \
  "2l with the allow-list and the count blinded, an altered 087 is stopped by its sha256 — three layers"
V_ID="$(release_variant edit lib/DishNetAiBrain.php "$IDLINE" '            $p = "You are the DishNet assistant (rehearsal), replying to a customer {$where}.\n";')"
S="$(pinned_copy idv "$V_ID")"; fresh; OUT="$(run --answer NO --script "$S")"
check "$(has "$OUT" "STOP: the pin carries files that are not 5.18.90's, which this release must not ship: lib/DishNetAiBrain.php")" "yes" \
  "2m control: a pin that changes the assistant's prompt is stopped by the allow-list first — the brain is not one of 5.18.90's files"
M="$(mutant a0b "$S" '[ -z "$STRAY" ] || stop' '[ -z "$STRAY" ] || true')"
fresh; OUT="$(run --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" "STOP: NO-GO (D3): on this server's own configuration the pin's assistant prompt is not 5.18.89's, or the comparison could not be made (cases=12 same=0 differs=sales,sales_identified,support,account,web,email,owner_null,owner_blank,owner_two_words,owner_injection,owner_by_email,owner_on_web")$(live)$(backups)" "yes${BASE}0" \
  "2n …and with the allow-list blinded, A10 stops it on its own: every conversation's prompt named — A10 has teeth"
M="$(mutant a10 "$S" '[ -z "$STRAY" ] || stop' '[ -z "$STRAY" ] || true' '  [ "$PS_A" = "$PERSONA_SIGNATURE" ] || stop' '  [ "$PS_A" = "$PERSONA_SIGNATURE" ] || true')"; rm -f "$S"
fresh; OUT="$(run --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" 'STOP: NO-GO (D3)')$(has "$OUT" 'STOP: not confirmed')" "noyes" "2o the copy blind to the allow-list and to A10 goes on with that pin, to the prompt — detected"
S="$(pinned_copy niv "$V_NI")"; M="$(mutant a11 "$S" '  [ "$POL_A" = "$POL_SIGNATURE" ] || stop' '  [ "$POL_A" = "$POL_SIGNATURE" ] || true')"; rm -f "$S"
fresh; OUT="$(run --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" "STOP: NO-GO: on this server's own configuration the pin's automated-send policy")$(has "$OUT" "STOP: NO-GO (South Sudan): the pin does not keep South Sudan exactly as it is, or the check could not be made: send-policy")$(live)$(backups)" "noyes${BASE}0" \
  "2p the A11-blinded copy, pinned to the pin whose policy reads the registry with its switch off, is no longer stopped at A11 — detected; A6, on its throwaway database, still stops it before anything changes"
check "$(data_digest)$(host_code_left)$(left_tmp)$(evo_calls)" "${D0}0${TMP0}0" "control: the data as seeded, no code copy left anywhere, Evolution never called"

echo; echo "== 3. the deploy, as the operator runs it =="
D1="$(data_digest)"; EV1="$(sq "SELECT group_concat(id || ':' || event_type || ':' || status, ',') FROM events")"
DB_N="$(git -C "$REPO" ls-tree -r --name-only "$PIN" -- "$P/dishnet-mikrotik-control-plane" "$P/docs" | wc -l | tr -d ' ')"
fresh; OUT="$(run --answer DEPLOY)"
echo "  …    the deploy's own tally:$(grep -E '^  checks +[0-9]+ ok, ' <<<"$OUT" | sed -E 's/^  checks +/ /')"
check "$(fails "$OUT")$(notes "$OUT")" "00" "no FAIL line and no note"
check "$(has "$OUT" '5.18.90 (deploy): PASSED')" "yes" "PASSED"
for l in "ok    A7 Domain B untouched: the whole repository differs between $BASE and the pin by the plugin's $N_CH files alone, none under Domain B, and its trees are the live release's — dishnet-mikrotik-control-plane/ $(git -C "$REPO" rev-parse --short "$PIN:$P/dishnet-mikrotik-control-plane") docs/ $(git -C "$REPO" rev-parse --short "$PIN:$P/docs")" \
         "ok    A0 the release delta is exactly 5.18.90's $N_CH files — $N_AD added, $N_MO changed, no migration (087 the reviewed one, sha256 ${MIG_SHA:0:16}…, unchanged); no partner-portal, CSRF or AI media-layer file" \
         "migrations      0 added since 5.18.89 — 5.18.90 adds none; 087 (5.18.88's) stays as it is" \
         "release code    $PIN (5.18.90) and $BASE (5.18.89), copied into the container's /tmp for the comparisons below — removed when this run ends" \
         "registry switch mn=absent/absent   (multi_number_channels_enabled in the configuration files / the store row; must read OFF — this release keeps the registry dark)" \
         "own leads only  ol=absent/absent   (sales_own_leads_only — 5.18.89's; absent means OFF, and it must read OFF: A5b)" \
         "hand-over copy  hc=absent/absent" "owned follow-up fh=absent/absent" \
         "087 state       $ST0" \
         "ok    A3 the settings row the Inbox reads still reaches Evolution and names a sales and an account number" \
         "ok    A4 the lead switches are as the operator decided on 07 Oct: ai_lead_capture ON in both copies, ai_crm_lead_sync OFF (lc=on/on, ls=off/off; files/store)" \
         "ok    A5 the channel registry's switch reads OFF in both copies (mn=absent/absent; files/store) — 5.18.90 ships dark" \
         "ok    A5b sales_own_leads_only reads OFF in both copies (ol=absent/absent; files/store) — every salesperson keeps seeing every lead; wa_handover_copy_central (hc=absent/absent) and wa_followups_on_owned_numbers (fh=absent/absent) act only on a salesperson's number, which the registry being off rules out" \
         "ok    A8 migration 087 (5.18.88's) is applied and complete on this server — 2 tables, 3 indexes, 3 triggers, both CHECKs, the ledger row matching the installed file — holding the three department rows exactly as it seeds them, no instance stored and no number, and 1 salesperson number(s) added through the card, none switched on, 0 with the assistant on; 5.18.90 adds no migration" \
         "routing         $SHAPE   (the files the webhook and the workers read)" \
         "                  $SHAPE   (the row the Inbox reads)" \
         "ok    A9 the pin's code routes the three numbers exactly as the live code does, on this server's own configuration — the files the webhook and the workers read, and the Inbox's row: $SHAPE (instance names compared, never printed)" \
         "ok    A10 D3 the pin's assistant builds exactly 5.18.89's prompt on this server's own configuration for all twelve conversations — the three numbers, a known and an unknown customer, the website chat, e-mail, and every kind of owner — and for the control, an owner named, which the comparison sees differ from a department's conversation" \
         "ok    A11 the pin's automated-send policy is inert on this server's own configuration, in the files the webhook, the workers and the crons read and in the Inbox's row: nothing refused, nothing classified as DishNet's own, no SQL added to the follow-ups, the assistant never stopped — and the registry never asked ($POL_SIG)" \
         "ok    A2 PHP" \
         "South Sudan     live 5.18.89: $SS_SIG" \
         "                  pin  5.18.90: $SS_SIG" \
         "Uganda          live 5.18.89: $UG_SIG" \
         "                  pin  5.18.90: $UG_SIG" \
         "Batch 2 rules   pin  5.18.90: $B2_SIG" \
         "send policy     pin  5.18.90: $P90_SIG" \
         "ok    A6 South Sudan stays exactly as it is" \
         "and the pin's automated-send policy, with every switch on, refuses and classifies nothing as a South Sudan install, nor on Uganda with the switches absent, while on Uganda with the registry on it keeps a salesperson's number from answering a department's" \
         "— one consistent copy, integrity ok" "ok    backed up the installed plugin (5.18.89, $BASE)" "ok    backed up the configuration vault" \
         "event-queue.tsv — 3 event(s) not done yet: id, type, status, attempts and times, never a payload (total=6 done=3 pending=1 processing=0 failed=1 dead=1; not done: ai.reply=1 crm.lead.sync=1 wa.escalation=0)" \
         "GO — evidence recorded" \
         "checking out $PIN (5.18.90, the release commit) for the documented deploy" \
         "ok    container serves $PIN" \
         "ok    V1 the sign-in page on the public address answers 200 with zero redirects (no loop)" \
         "ok    V7 the customer authorisation page answers 404 \"This link is not valid\" — the switch is on, and a request without a link is refused" \
         "ok    V10 wa_send_reply without a login answers 401" \
         "ok    V11 log_call without a login answers 401 — the staff guard, before any lead is read" \
         "ok    V12 the WhatsApp AI screen without a session sends the visitor to the sign-in page (302) — no part of the Salesperson numbers card is in the answer" \
         "ok    V3b install_auth_enabled is unchanged by this run (before=ia=on/on, after=ia=on/on; files/store)" \
         "ok    V3c customer_wa_install_scheduled is unchanged by this run (before=wa=on/on, after=wa=on/on; files/store)" \
         "ok    V3d the settings row the Inbox reads is unchanged by this run" \
         "ok    V3e the lead switches are unchanged by this run (lc=on/on ls=off/off qu=on/on sa=on/on; files/store)" \
         "ok    V3f the channel registry's switch is unchanged by this run and OFF in both copies (mn=absent/absent; files/store) — the registry stays dark" \
         "ok    V3g S6 the AI and WhatsApp settings are unchanged by this run (files=" \
         "ok    V3h 5.18.89's switches are unchanged by this run (ol=absent/absent hc=absent/absent fh=absent/absent; files/store) — sales_own_leads_only OFF: every salesperson sees every lead, as before" \
         "ok    V4 no fatal or parse error of $P in the container log since" \
         "ok    R1 all $N_CH files 5.18.90 changes are installed exactly as $PIN has them ($N_AD new)" \
         "ok    R1 the installed manifest says 5.18.90" \
         "ok    R2 the installed InstallAuth reads install_auth_enabled ON in both places" \
         "ok    R2 the installed InstallScheduledWhatsApp reads customer_wa_install_scheduled ON in both places" \
         "ok    R2b the installed code reads 5.18.89's switches as the plugin will, in the configuration files and in the store row: own leads only OFF, the follow-up hold holding nothing (the registry is off), and the Salesperson numbers card shown to admins on the WhatsApp AI screen" \
         "ok    R2c the installed automated-send policy is inert on this server's own configuration, both copies: nothing refused, nothing classified as DishNet's own, no SQL added to the follow-ups, the assistant never stopped — the registry never asked ($POL_SIG)" \
         "ok    R3 migration 086 (5.18.83's) is still applied and complete" \
         "ok    R3 084 is still installed and its two tables exist on the live plugin.sqlite3 — job_photos 2:0 rows" \
         "ok    R3 migration 087 is applied and complete — 2 tables, 3 indexes, 3 triggers, both CHECKs, the ledger row matching the installed file — the three department rows exactly as 087 seeds them, NO instance and no number stored, and 1 salesperson number(s) added through the card, none switched on (the registry is off), 0 with the assistant on" \
         "OK: 087_wa_channels.sql (14 stmts, " \
         "ok    R4 the pilot libs + the Distributors tab are installed as before" \
         "no partner-portal, CSRF or AI media-layer file is installed, and neither the event processor, the webhook nor the AI worker carries any of the media layer" \
         "ok    R5 all" \
         "and 5.18.89's salesperson numbers are in place: the five libraries; the persona in the identity line and rule 4a from one answer, WhatsApp only" \
         "and 5.18.90's safety fix is in place: the policy and the internal numbers, inert with the registry off" \
         "ok    R7 cash in hand on the live book, read-only — what the landing hero shows: UGX CASH IN HAND 850,000.00 · USD CASH IN HAND 0.00" \
         "ok    R7 the photo tables and files read exactly as before the run (present:2:0, files:2) — the tool touched no data" \
         "ok    R8 the installed quotation reader reads a quotation shaped like 000181 as the form will" \
         "ok    R9 the installed Inbox route, on the server's own Inbox settings: a sales chat → the sales number, an account chat → the account number" \
         "UcrmLeadWorker settles a lead's sync as \"not synced — switched off\": nothing reaches uCRM. S7: with the registry off a lead carries no channel fields, is given to nobody by the number it came in on, and its sync names no channel — 5.18.88's lead, field for field" \
         "ok    R11 S4/S5 the registry is dark: its switch OFF in both copies; ChannelRegistry::enabled() and forStore() off for both; the Inbox's route for every kind of chat — 0 statements, none on the registry's tables; 087's three department rows as it seeds them, no instance stored, and 1 salesperson number(s) added through the card, none switched on, 0 with the assistant on; tools/channels.php says OFF, not in effect, installed — and \"department numbers: NOT all verified — sales, support\" (the registry cannot be switched on until they are all verified)" \
         "ok    R12 S1–S3 the three numbers route exactly as on 5.18.89 — the installed code and 5.18.89's, on this server's own configuration: $SHAPE (the files the webhook and the workers read); the Inbox's row: $SHAPE — instance names compared, never printed" \
         "ok    R13 S8 the installed event processor, on throwaway databases: on Uganda it leaves ai.reply and crm.lead.sync to their workers and settles wa.escalation as known ($UG_SIG); as a South Sudan install it does exactly what 5.18.89's does ($SS_SIG)" \
         "ok    R13 the plugin's own event queue lost nothing: kept=3/3 missing=- of the events not done at the snapshot are still there" \
         "ok    R14 Domain B is installed exactly as $PIN has it: all $DB_N files of dishnet-mikrotik-control-plane/ and the plugin's docs/, byte for byte — this release touched neither" \
         "ok    R15 the installed Batch 2 rules, on throwaway databases: on Uganda with every switch on they apply in full; with the switches absent, as here, they show the card alone; as a South Sudan install with every switch on they apply nothing" \
         "ok    R16 D3 the installed assistant builds exactly 5.18.89's prompt on this server's own configuration for all twelve conversations and for the control, an owner named — which the comparison sees differ from a department's conversation" \
         "ok    R17 the installed automated-send policy, on a throwaway database: on Uganda with the registry on it holds a salesperson's number until the department numbers are verified, then keeps it and the departments from answering each other, and stops it with its assistant; with the switches absent, as here, and as a South Sudan install with every switch on, it refuses and classifies nothing" \
         "migrations        none — 087 (5.18.88's) complete before and after (A8, R3); 086 still complete"; do
  check "$(has "$OUT" "$l")" "yes" "3: ${l:0:100}"
done
check "$(cnt "$OUT" 'ok    backed up ')$(cnt "$OUT" 'ok    snapshot of the event queue')" "41" "four backups — the database, the data directory, the installed plugin, the vault — and the event queue's snapshot"
check "$(grep -cE -- "$LEAK_RX|$NUM_RX" <<<"$OUT")" "0" "the log carries no Evolution address, key or instance name, and no number — the routing as a shape, the names compared under a key"
check "$(live)$(installed_digest "$PIN")$(inst_ver)" "${PIN}05.18.90" "the container serves $PIN: every changed file exactly as the commit has it, manifest 5.18.90"
check "$(mig87)$(wa_objects)|$(wa_rows)|$(sq "SELECT COUNT(*) FROM wa_channel_log")" "12:3:3|account/NULL/active,sales/NULL/active,sales-001/rh-seller/disabled,support/NULL/active|${ROWS0#*:}" \
  "087 as 5.18.89 left it: recorded once, its objects, the three department rows with NO instance, the pilot's number switched off, the trail as it was — 5.18.90 adds no migration and wrote nothing there"
check "$(data_digest)" "$D1" "the deploy wrote no record and no configuration value — every table (087's two included), the vault, the configuration files and the photos as before"
check "$(sq "SELECT group_concat(id || ':' || event_type || ':' || status, ',') FROM events")" "$EV1" "…the event queue exactly as before: the deploy processed nothing"
STATE="$SB/out/state-5.18.90.env"; DA="$(sed -n 's/^DEPLOYED_AT=//p' "$STATE")"
check "$(grep -cE '^DEPLOYED_AT=[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$' "$STATE")$(grep -c '^EQ_FILE=.*/event-queue.tsv$' "$STATE")$(grep -c '^MN_BEFORE=mn=absent/absent$' "$STATE")$(grep -c '^OL_BEFORE=ol=absent/absent$' "$STATE")$(grep -c "^WA87_BEFORE=$ST0\$" "$STATE")" "11111" \
  "the state file records when the deploy started in ISO-8601, the queue's snapshot, the registry's switch, own leads only and 087's state as found"
BK="$(ls -d "$SB/out"/backup-* | tail -1)"
check "$(grep -c . "$BK/event-queue.tsv")$(cut -f2,3 "$BK/event-queue.tsv" | tr '\t\n' ': ')" "3ai.reply:pending crm.lead.sync:failed efris.submit:dead " "the event queue's snapshot holds the three events not done — type and status, never a payload"
check "$(tar -xzOf "$BK"/plugin-installed-5.18.89.tar.gz $P/manifest.json | grep -c '"version": "5.18.89"')" "1" "the code backup is 5.18.89"
check "$(host_code_left)$(left_tmp)" "0$TMP0" "the run removed its copies of the two releases' code and every throwaway database — on the host and in the container's /tmp"
check "$(grep -c "cd $REPO && bash scripts/deploy-5.18.90.sh --rollback" <<<"$OUT")" "1" "the rollback command is printed once, on its own line, never beside the deploy"
check "$(awk '/PASSED\. Send this LOG FILE back/ {p=1} p && /deploy-5\.18\.90\.sh --rollback/ {print "after"; exit}' <<<"$OUT")" "after" "…after the verdict, at the end of the log"
check "$(grep -cE -- "$SWITCH_RX" <<<"$OUT")" "0" "no switch command anywhere in the log — not for a feature, the registry, own leads only or a lead switch"
check "$(evo_calls)" "0" "Evolution was never called — not by the deploy, its checks or the installed code it ran"
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" '5.18.90 (after): PASSED')$(data_digest)" "0yes$D1" "--after-only right after: PASSES, nothing written"

echo; echo "== 4. the checks have teeth — every fault named, then removed =="
db_keep
cp "$PD/tabs/engage/wa_ai_setup.php" "$SB/card.aside"; git -C "$REPO" show "$BASE:$P/tabs/engage/wa_ai_setup.php" > "$PD/tabs/engage/wa_ai_setup.php"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R1 installed files that differ: tabs/engage/wa_ai_setup.php')$(has "$OUT" 'screen:no-verify-button')" "yesyes" "4a 5.18.89's WhatsApp AI screen back on the server: R1 names it, R6 the missing Verify number"
cp "$SB/card.aside" "$PD/tabs/engage/wa_ai_setup.php"
cp "$PD/lib/AutomationPolicy.php" "$SB/policy.aside"; edit_installed lib/AutomationPolicy.php "$POLICY_GATE" '        if ($pdo === null) return self::inert();'
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R1 installed files that differ: lib/AutomationPolicy.php')$(has "$OUT" 'policy:not-inert-with-the-registry-off')$(has "$OUT" 'FAIL  R2c the installed automated-send policy is not inert on this server'"'"'s own configuration, or could not be read: policy=on/on')$(has "$OUT" 'FAIL  R17 the installed automated-send policy does not act as 5.18.90'"'"'s must:')" "yesyesyesyes" \
  "4b the installed policy reading the registry with its switch off: R1 names the file, R6 the rule, R2c this server's configuration, R17 South Sudan"
M="$(mutant r2c "$DEPLOY" 'if [ "$POL_R" = "$POL_SIGNATURE" ]; then ok "R2c' 'if true; then ok "R2c')"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" 'FAIL  R2c')$(has "$OUT" 'ok    R2c the installed automated-send policy is inert')" "noyes" "4c the R2c-blinded copy passes the same policy — detected"
cp "$SB/policy.aside" "$PD/lib/AutomationPolicy.php"
cp "$PD/lib/AutomationPolicy.php" "$SB/policy.aside"; edit_installed lib/AutomationPolicy.php "$SENDER_CLASS" "        return '';"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R1 installed files that differ: lib/AutomationPolicy.php')$(has "$OUT" 'FAIL  R17 the installed automated-send policy does not act as 5.18.90'"'"'s must: uganda+on: gaps=sales,support seller=internal_numbers_incomplete; verified: gaps=- seller=- seller>dept=- dept>seller=-')$(has "$OUT" 'FAIL  R2c')" "yesyesno" \
  "4d the installed policy no longer recognising DishNet's own numbers: R1 names the file; R17 shows a salesperson's number and a department answering each other — R2c, rightly, still inert"
M="$(mutant r17 "$DEPLOY" 'if [ "$P90_NOW" = "$P90_SIGNATURE" ]; then ok "R17' 'if true; then ok "R17')"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" 'FAIL  R17')$(has "$OUT" 'ok    R17 the installed automated-send policy')" "noyes" "4e the R17-blinded copy passes the same policy — detected"
cp "$SB/policy.aside" "$PD/lib/AutomationPolicy.php"
check "$(sqx 'DROP TRIGGER wa_channels_never_deleted')" "done" "control: one trigger dropped from 087"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R3 migration 087 is recorded but not as it must be: incomplete:wa_channels_never_deleted')" "yes" "4f R3 names the missing trigger — the ledger row alone is not trusted"
db_back
check "$(verify_dept sales +256700000301)$(verify_dept support +256700000302)" "donedone" "control: the department numbers verified through the installed registry, as 5.18.90's card records them — sales, and support for the shared instance"
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" 'ok    R3 migration 087 is applied and complete — 2 tables, 3 indexes, 3 triggers, both CHECKs, the ledger row matching the installed file — the three department rows as 087 seeds them, NO instance stored, 2 of them with its number verified through the card (5.18.90'"'"'s Verify number, read from Evolution'"'"'s report, on the record), and 1 salesperson number(s) added through the card, none switched on (the registry is off), 0 with the assistant on')$(has "$OUT" '— and "department numbers: all verified for their instances"')$(has "$OUT" '5.18.90 (after): PASSED')" "0yesyesyes" \
  "4g department numbers verified through the card: R3 and R11 report them and --after-only still PASSES — the release's own use is no failure"
check "$(grep -cE -- "$LEAK_RX|$NUM_RX" <<<"$OUT")" "0" "…and neither a number nor an instance name is printed"
check "$(sqx "INSERT INTO wa_channel_log(channel_id, action, old_value, new_value, actor, reason) VALUES ('support', 'status', 'active', 'paused', 'by hand', 'planted')")" "done" "control: a trail row of another kind written on a department by hand"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R3 migration 087 is recorded but not as it must be: rows=')$(has "$OUT" 'trail=differs')" "yesyes" "4g2 a department's trail with anything but 5.18.90's verification: R3 fails on it"
db_back
check "$(verify_dept sales +256700000301)$(sqx "UPDATE wa_channels SET business_number = '+256700000399' WHERE channel_id = 'sales'")" "donedone" "control: the sales number verified as the card records it, then overwritten by hand"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R3 migration 087 is recorded but not as it must be: rows=')$(has "$OUT" 'seed=differs:sales(number) dnum=1')$(grep -cE -- "$NUM_RX" <<<"$OUT")" "yesyes0" \
  "4g4 a department number changed by hand AFTER its verification: R3 fails on it — the number must be the one the last verification recorded"
db_back
check "$(set_ai sales-001 1)" "done" "control: the pilot's assistant switched on through the installed registry, as the card does it — the registry still off"
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" 'none switched on (the registry is off), 1 with the assistant on')$(has "$OUT" '5.18.90 (after): PASSED')" "0yesyes" \
  "4g5 a salesperson's assistant ON while the registry is off: reported by R3, never a failure — nothing answers on it"
db_back
check "$(sqx "UPDATE wa_channels SET business_number = '+256700000301' WHERE channel_id = 'sales'")" "done" "control: a department's number written by hand after the deploy, with no verification on the record"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R3 migration 087 is recorded but not as it must be: rows=')$(has "$OUT" 'seed=differs:sales(number) dnum=1')$(grep -cE -- "$NUM_RX" <<<"$OUT")" "yesyes0" "4g3 R3 names the department whose number nobody verified, never the number"
db_back
check "$(sqx "UPDATE wa_channels SET status = 'active' WHERE channel_id = 'sales-001'")" "done" "control: the pilot's number switched on by hand, with the registry off"
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R3 migration 087 is recorded but not as it must be: rows=$ROWS0 seed=ok dnum=0 other=1 active=1 oai=0 trail=ok dtrail=0")$(has "$OUT" "FAIL  R11 the registry is not dark, or not as 087 seeds it: wa_channels:rows=$ROWS0 seed=ok dnum=0 other=1 active=1 oai=0 trail=ok dtrail=0")" "yesyes" \
  "4h a number switched on while the registry is off: R3 and R11 fail on it"
db_back
sqx "UPDATE wa_channels SET evo_instance = 'rh-x' WHERE channel_id = 'sales'" >/dev/null
OUT="$(run --after-only)"
check "$(has "$OUT" 'seed=differs:sales')" "yes" "4i a department row given an instance: R3 names the row that is no longer as 087 seeded it"
db_back
cfg_keep; cfg_set multi_number_channels_enabled on
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  V3f multi_number_channels_enabled reads ON (mn=on/on, files/store)')$(has "$OUT" 'FAIL  R11 the registry is not dark')$(has "$OUT" 'registry-on')$(has "$OUT" 'FAIL  R2c')" "yesyesyesyes" \
  "4j the registry's switch ON: V3f, R11, R12 and R2c each say the registry is not dark"
cfg_back
cfg_keep; cfg_set sales_own_leads_only on
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  V3h 5.18.89's switches CHANGED across this run, could not be read, or sales_own_leads_only is not off (files/store): sales_own_leads_only:not-off(on/on)")$(has "$OUT" "FAIL  R2b the installed code does not read 5.18.89's switches as it must (own leads only, the follow-up hold, the card; files/store): files=on,free,shown store=on,free,shown")" "yesyes" \
  "4k own leads only ON: V3h and R2b each say every salesperson now sees only their own leads"
cfg_back
DBF="$(git -C "$REPO" ls-tree -r --name-only "$PIN" -- "$P/dishnet-mikrotik-control-plane" | head -1)"; DBF="${DBF#$P/}"
cp "$PD/$DBF" "$SB/db.aside"; printf '\n# changed on the server\n' >> "$PD/$DBF"
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R14 Domain B files on the server differ from $PIN: ./$DBF")" "yes" "4l a Domain B file changed on the server: R14 names it"
cp "$SB/db.aside" "$PD/$DBF"
EVF="$(sq "SELECT id FROM events WHERE status = 'failed'")"; sqx "DELETE FROM events WHERE id = $EVF" >/dev/null
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R13 events that were not done at the snapshot are GONE from the queue (kept=2/3 missing=$EVF)")" "yes" "4m an event not done deleted from the queue: R13 names its id"
db_back
M="$(mutant r13 "$DEPLOY" '  case "$EQ_KEPT" in
    "kept="*" missing=-") ok "R13 the plugin' '  case "kept=- missing=-" in
    "kept="*" missing=-") ok "R13 the plugin')"
sqx "DELETE FROM events WHERE id = $EVF" >/dev/null
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" 'GONE from the queue')$(has "$OUT" "ok    R13 the plugin's own event queue lost nothing")" "noyes" "4n the R13-blinded copy passes the lost event — detected"
db_back
M="$(mutant r11 "$DEPLOY" 'if [ -z "$R11_BAD" ]; then ok "R11 S4/S5' 'if true; then ok "R11 S4/S5')"
cfg_keep; cfg_set multi_number_channels_enabled on
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" 'FAIL  R11')$(has "$OUT" 'ok    R11 S4/S5 the registry is dark')" "noyes" "4o the R11-blinded copy calls the registry dark with its switch ON — detected"
cfg_back
cp "$PD/tools/channels.php" "$SB/cli.aside"; git -C "$REPO" show "$BASE:$P/tools/channels.php" > "$PD/tools/channels.php"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R11 the registry is not dark, or not as 087 seeds it: channels.php:no-department-line')$(has "$OUT" 'channels-cli:no-department-line')" "yesyes" "4o2 5.18.89's CLI back on the server: R11 misses its department line, R6 names it"
cp "$SB/cli.aside" "$PD/tools/channels.php"
touch "$SB/web/.leak_card"; OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  V12 the WhatsApp AI screen without a session → 200; 'sales-numbers' ×1 (expected 302 to the sign-in page)")$(test -f "$SB/web/.leak_card" && echo left || echo spent)" "yesspent" \
  "4p the WhatsApp AI screen answering a visitor with no session with the card (the run's dashboard request leaks it): V12 fails"
V12_IF="$(cat <<'X'
      if [ "$HTTP_CODE" = "302" ] && printf '%s' "$HTTP_LOCATION" | grep -qF 'page=login' && [ "$(count 'sales-numbers')" = "0" ]; then
X
)"
M="$(mutant v12 "$DEPLOY" "$V12_IF" '      if true; then')"
touch "$SB/web/.leak_card"; OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" 'FAIL  V12')$(has "$OUT" 'ok    V12 the WhatsApp AI screen without a session')" "noyes" "4q the V12-blinded copy passes the leaked card — detected"
rm -f "$SB/web/.leak_card"
cp -p "$DATA/migration.log" "$SB/mlog.aside"
sed -i 's/OK: 087_wa_channels\.sql (14 stmts, /OK: 087_wa_channels.sql (13 stmts, /' "$DATA/migration.log"
check "$(grep -cF 'OK: 087_wa_channels.sql (13 stmts, ' "$DATA/migration.log") line / $(wa_objects)" "1 line / 2:3:3" \
  "control: the runner's line made to count 13 of 14 — the store itself still exactly as 087 makes it"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R3 migration 087 is recorded but not as it must be: migration.log:OK-counts-13-of-14-statements')$(has "$OUT" 'migration.log: [')$(fails "$OUT")" "yesyes1" \
  "4r the runner says OK but counts 13 of 14 — what it writes when it skips a statement it calls safe: R3 fails on that alone, and shows the line"
cp -p "$SB/mlog.aside" "$DATA/migration.log"
printf '[2026-10-07 00:00:00] PARTIAL: 087_wa_channels.sql — 13 ok, 1 skipped: CREATE TRIGGER … — planted\n' >> "$DATA/migration.log"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R3 migration 087 is recorded but not as it must be: migration.log:PARTIAL')$(fails "$OUT")" "yes1" "4s a PARTIAL line for 087 in the runner's log: R3 fails on it, the store notwithstanding"
cp -p "$SB/mlog.aside" "$DATA/migration.log"
grep -vF '087_wa_channels.sql' "$SB/mlog.aside" > "$DATA/migration.log"
OUT="$(run --after-only)"
check "$(has "$OUT" 'ok    R3 migration 087 is applied and complete')$(has "$OUT" "note  R3 migration.log holds no line for 087")$(fails "$OUT")" "yesyes0" \
  "4t no line for 087 in the log at all: R3 passes on the store — every statement's effect read — and says the log was silent"
cp -p "$SB/mlog.aside" "$DATA/migration.log"
M="$(mutant r3log "$DEPLOY" '      elif [ "${nl:-0}" -gt 0 ] && [ "${nok:-0}" != "$nl" ]; then' '      elif false; then')"
sed -i 's/OK: 087_wa_channels\.sql (14 stmts, /OK: 087_wa_channels.sql (13 stmts, /' "$DATA/migration.log"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" 'OK-counts-13-of-14')$(has "$OUT" 'ok    R3 migration 087 is applied and complete')" "noyes" "4u a copy without the count check passes the 13-of-14 line — the check above is what catches it"
cp -p "$SB/mlog.aside" "$DATA/migration.log"
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" '5.18.90 (after): PASSED')$(data_digest)" "0yes$D1" "4v every fault removed: --after-only PASSES, the data as after the deploy"

echo; echo "== 5. 5.18.88's two fixes carried over — R7 and V4 — each with its control =="
db_keep
touch "$SB/web/.add_photo"; OUT="$(run --after-only)"
check "$(has "$OUT" 'ok    R7 the photo tables and files only grew during the run (present:2:0 → present:3:0, files:2 → files:3): photos taken meanwhile')$(fails "$OUT")$(has "$OUT" '5.18.90 (after): PASSED')" "yes0yes" \
  "5a R7: a photo taken during the run (the first request of the run takes it) — growth is fine: R7 says so and the run PASSES"
db_back; rm -f "$DATA/uploads/job_photos/20/kit-1-during.jpg"
M="$(mutant r7old "$DEPLOY" 'case "$(photo_growth "$TB_BEFORE" "$TB_AFTER" "$PF_BEFORE" "$PF_AFTER")" in' 'case "$( [ "$TB_AFTER" = "$TB_BEFORE" ] && [ "$PF_AFTER" = "$PF_BEFORE" ] && echo same || echo lost )" in')"
touch "$SB/web/.add_photo"; OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" 'FAIL  R7 the photo tables or files LOST something during the run (present:2:0 → present:3:0')" "yes" \
  "5b control on the control: 5.18.87's rule (the counts must be equal) put back — the same photo fails the run; the fix is what passes it"
db_back; rm -f "$DATA/uploads/job_photos/20/kit-1-during.jpg"
touch "$SB/web/.del_photo"; OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R7 the photo tables or files LOST something during the run (present:2:0 → present:1:0, files:2 → files:1)')" "yes" "5c R7: a photo lost during the run fails, naming both counts"
db_back; printf 'jpeg' > "$DATA/uploads/job_photos/20/cable-1-b.jpg"
check "$(data_digest)" "$D1" "control: the photos put back — the data as after the deploy"
check "$(sed -n 's/^DEPLOYED_AT=//p' "$STATE")" "$DA" "control: the state file is the deploy's, its time in ISO-8601 ($DA)"
base_log; logline "$(date -u +%s)" "PHP Fatal error:  Uncaught Error: planted by the rehearsal in /data/ucrm/data/plugins/$P/cron/event_processor.php:1"
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  V4 1 fatal line(s) of $P since $DA:")$(has "$OUT" 'planted by the rehearsal')" "yesyes" \
  "5d V4 in --after-only mode reads the log since the deploy and finds a fatal planted after it"
cp "$STATE" "$SB/state.kept"
sed -i "s/^DEPLOYED_AT=.*/DEPLOYED_AT=$(date -u -d "$DA" +%Y%m%dT%H%M%SZ)/" "$STATE"
check "$(grep -cE '^DEPLOYED_AT=[0-9]{8}T[0-9]{6}Z$' "$STATE")" "1" "control: the state file rewritten with the compact stamp 5.18.60–5.18.87 wrote"
M="$(mutant v4old "$DEPLOY" '  local t; t="$(iso_time "$1")"' '  local t; t="$1"' 'V4_SINCE="$(iso_time "$DEPLOY_STARTED")"' 'V4_SINCE="$DEPLOY_STARTED"')"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" 'ok    V4 no fatal or parse error')$(has "$OUT" '(0 line(s) read)')$(has "$OUT" 'planted by the rehearsal')" "yesyesno" \
  "5e control on the control: 5.18.87's way of reading the log put back, with its compact stamp — the planted fatal is missed, 0 lines read"
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  V4 1 fatal line(s) of $P since $DA:")" "yes" "5f …while the real script turns the same compact stamp into ISO-8601 and finds the fatal"
cp "$SB/state.kept" "$STATE"; base_log
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" '5.18.90 (after): PASSED')$(data_digest)" "0yes$D1" "5g the log clean again: --after-only PASSES"

echo; echo "== 6. the switches after the deploy, each as its own step with the installed tool =="
D3="$(data_digest)"
S1="$(setcfg ai_crm_lead_sync 1)"; OUT="$(run --after-only)"
check "$(has "$S1" 'ai_crm_lead_sync = 1')$(fails "$OUT")$(has "$OUT" "creates or links a lead client in uCRM for each lead's sync it receives — and on Uganda the event processor now leaves every sync to it (docs/65 §Z.5)")$(has "$OUT" 'ls=on/on')" "yes0yesyes" \
  "6a the uCRM write switched on: --after-only PASSES, and R10 says leads reach uCRM"
S2="$(setcfg ai_crm_lead_sync 0)"; OUT="$(run --after-only)"
check "$(has "$S2" 'ai_crm_lead_sync = 0')$(fails "$OUT")$(data_digest)" "yes0$D3" "6b switched off again: PASSES, the data byte for byte as before 6a"
S3="$(setcfg multi_number_channels_enabled 1)"; OUT="$(run --after-only)"
check "$(has "$S3" 'Not yet: the department numbers are not all verified (sales, support). Nothing was saved.')$(both_copies multi_number_channels_enabled)$(fails "$OUT")$(data_digest)" "yes-0$D3" \
  "6c the registry's switch set with the installed tool: 5.18.90 REFUSES it while the department numbers are not verified — nothing saved, the checks still PASS"
db_keep; cfg_keep
check "$(verify_dept sales +256700000301)$(verify_dept support +256700000302)" "donedone" "control: the department numbers verified as the card records them"
S4="$(setcfg multi_number_channels_enabled 1)"; OUT="$(run --after-only)"
check "$(has "$S4" 'multi_number_channels_enabled = 1')$(both_copies multi_number_channels_enabled)$(has "$OUT" 'FAIL  V3f multi_number_channels_enabled reads ON')$(has "$OUT" 'FAIL  R11')$(has "$OUT" 'FAIL  R2c')$(has "$OUT" '5.18.90 (after): PASSED')" "yes11yesyesyesno" \
  "6c2 …once they are verified the tool accepts it — and the checks say at once that the registry is not dark (V3f, R11, R2c)"
check "$(grep -cE -- "$LEAK_RX|$NUM_RX" <<<"$OUT$S4")" "0" "…printing no number and no instance name"
cfg_back; db_back; OUT="$(run --after-only)"
check "$(fails "$OUT")$(data_digest)" "0$D3" "6d …put back: PASSES, the data as before"
cfg_keep; S5="$(setcfg wa_handover_copy_central 0)"; S6="$(setcfg wa_followups_on_owned_numbers 1)"; OUT="$(run --after-only)"
check "$(has "$S5" 'wa_handover_copy_central = 0')$(has "$S6" 'wa_followups_on_owned_numbers = 1')$(fails "$OUT")$(has "$OUT" 'ok    V3h 5.18.89'"'"'s switches are unchanged by this run (ol=absent/absent hc=off/off fh=on/on; files/store)')" "yesyes0yes" \
  "6e the hand-over copy off and owned follow-ups on, with the installed tool: --after-only PASSES and reports them — they act only with the registry on"
cfg_back
cfg_keep; S7="$(setcfg sales_own_leads_only 1)"; OUT="$(run --after-only)"
check "$(has "$S7" 'sales_own_leads_only = 1')$(has "$OUT" 'FAIL  V3h')$(has "$OUT" 'FAIL  R2b')$(has "$OUT" '5.18.90 (after): PASSED')" "yesyesyesno" \
  "6f own leads only switched on with the installed tool: the checks say so at once — the operator decided it stays OFF"
cfg_back; OUT="$(run --after-only)"
check "$(fails "$OUT")$(data_digest)" "0$D3" "6g …put back: PASSES, the data as before"
cfg_keep; cfg_set ai_qualification off; OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" 'the assistant is asked to record leads nowhere while ai_qualification is off')" "0yes" "6h qualification off: PASSES, and R10 says no lead is asked for anywhere"
cfg_back
cfg_keep; row_unset evo_instance_account; OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R9 the installed Inbox route answered: sales:channel support:sender/support account:channel accounts:sender/accounts web:sender/support | registry=off sales=yes account=no')" "yes" \
  "6i R9 still has teeth: the account number taken out of the Inbox's row"
cfg_back
cfg_keep; touch "$SB/web/.mutate_cfg"; OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  V3g the AI or WhatsApp settings CHANGED across this run: store.evo_instance_account')$(grep -cE -- "$LEAK_RX" <<<"$OUT")$(test -f "$SB/web/.mutate_cfg" && echo left || echo spent)" "yes0spent" \
  "6j the Inbox's account number moved DURING a run (the run's first request moves it): V3g names the key — never its values"
cfg_back
OUT="$(run --after-only)"
check "$(fails "$OUT")$(data_digest)" "0$D3" "6k the number put back: PASSES, the data as before"
check "$(evo_calls)" "0" "control: Evolution never called — not by the installed tool, the checks or the registry writes"

echo; echo "== 7. the rollback: --rollback, typed ROLLBACK =="
D2="$(data_digest)"; fresh; OUT="$(run --answer ROLLBACK --rollback)"
check "$(fails "$OUT")$(has "$OUT" '5.18.90 (rollback): PASSED')" "0yes" "PASSED"
for l in "note  after the rollback the code is 5.18.89's again: the department numbers' Verify number goes from the card, and with it the automated-send policy and the internal-number guard" \
         "ok    backed up the installed plugin (5.18.90, $PIN)" "ok    snapshot of the event queue" \
         "GO — evidence recorded" "checking out $BASE (5.18.89) for the documented deploy" "ok    container serves $BASE (5.18.89)" \
         "ok    V3e the lead switches are unchanged by this run (lc=on/on ls=off/off qu=on/on sa=on/on; files/store)" \
         "ok    V3f the channel registry's switch is unchanged by this run and OFF in both copies (mn=absent/absent; files/store)" \
         "ok    V3g S6 the AI and WhatsApp settings are unchanged by this run" \
         "ok    V3h 5.18.89's switches are unchanged by this run (ol=absent/absent hc=absent/absent fh=absent/absent; files/store) — sales_own_leads_only OFF: every salesperson sees every lead, as before" \
         "ok    RB the installed manifest says 5.18.89" \
         "ok    RB the installed plugin is 5.18.89's again: the safety fix's code is reached by nothing — the policy, the internal-number guard, the department numbers' Verify number, the registry's refusal in set_config.php — and 5.18.89's salesperson numbers, 5.18.88's Batch 1 and 5.18.87's lead fixes are there" \
         "ok    RB 087's tables stay in plugin.sqlite3, complete, the three department rows as it seeds them, and 1 number(s) the card added, none switched on, 0 with the assistant on; 5.18.89 does not read them with the switch off — ChannelRegistry::enabled() and forStore() off for both copies, the Inbox's route for every kind of chat: 0 statements on them; the switch stays OFF (mn=absent/absent)" \
         "ok    RB the event processor is 5.18.89's: on Uganda it leaves the workers' events to them ($UG_SIG), on South Sudan the loop as ever ($SS_SIG)" \
         "ok    RB the assistant's prompt is 5.18.89's, byte for byte, on this server's own configuration — every conversation and the control ($PS_SIG)" \
         "ok    RB 5.18.89's Batch 2 rules, on a throwaway database, act as before the deploy: in full on Uganda with every switch on, the card alone with the switches absent, nothing on South Sudan" \
         "ok    RB the three numbers route exactly as before the rollback: $SHAPE" \
         "ok    RB the event queue lost nothing across the rollback (kept=3/3 missing=-)" \
         "ok    RB Domain B is installed exactly as $BASE has it: all $DB_N files" \
         "ok    RB 5.18.86's Inbox route is whole" "ok    RB 5.18.85's request form from the quotation and the technician's name are whole" \
         "ok    RB 5.18.84's booking WhatsApp is whole" "ok    RB 5.18.83's Customer Installation Authorisation is whole" \
         "ok    RB migration 086 (5.18.83's) is still applied and complete" \
         "note  RB the code is 5.18.89 again" "087's tables stay, with any number the card added or verified, read by nothing while the switch is off"; do
  check "$(has "$OUT" "$l")" "yes" "7: ${l:0:96}"
done
check "$(live)$(installed_digest "$BASE")$(inst_ver)" "${BASE}05.18.89" "the container serves $BASE: every changed file as 5.18.89 has it, manifest 5.18.89"
check "$(grep -cF -- "$WH_STEP" "$PD/evo_webhook.php")$(grep -cF -- "$DEPT_VERIFY" "$PD/lib/SalesNumbersAdmin.php")$(grep -cF -- "$SC_REFUSE" "$PD/tools/set_config.php")$(grep -cF -- "$GUARD_REG" "$PD/cron/wa_webhook_guard.php")$(grep -rlF 'AutomationPolicy' "$PD" --include=*.php | grep -v '/tests/' | sed "s#^$PD/##" | sort | tr '\n' ' ')|$(grep -cF -- "$CARD" "$PD/tabs/engage/wa_ai_setup.php")$(grep -cF -- "$PERSONA" "$PD/lib/DishNetAiBrain.php")$(grep -cF -- "$LISTS" "$PD/cron/event_processor.php")$(grep -cF -- "$B0_PIN" "$PD/workers/AiReplyWorker.php")$(grep -cF -- "$IR_CALL" "$PD/includes/api/api_whatsapp.php")" \
  "0000lib/AutomationPolicy.php |12114" \
  "after the rollback: the safety fix gone from every file that reached it — the policy's own file left on disk, named by nothing else; Batch 2, Batch 1, Batch 0 and 5.18.86's Inbox route kept"
check "$(mig87)$(wa_objects)|$(wa_rows)" "12:3:3|account/NULL/active,sales/NULL/active,sales-001/rh-seller/disabled,support/NULL/active" "087's tables as they were — read by nothing while the switch is off"
check "$(data_digest)$(host_code_left)$(left_tmp)" "${D2}0$TMP0" "the rollback changed no data, and left no copy of the code anywhere"
cfg_keep; cfg_set multi_number_channels_enabled on store; fresh; OUT="$(run --answer ROLLBACK --rollback)"
check "$(has "$OUT" 'note  the container already serves')$(has "$OUT" 'FAIL  RB 087 is not as it must be, 5.18.89 reads the registry, or its switch is not off:')$(has "$OUT" 'enabled=off/on forStore=off/on')$(has "$OUT" ' registry-reads=0')" "yesyesyesno" \
  "7b control on the control: with the switch ON in the Inbox's row, the same RB check sees 5.18.89 read the registry — the recording handle sees reads when there are any"
cfg_back; fresh; OUT="$(run --answer ROLLBACK --rollback)"
check "$(fails "$OUT")$(has "$OUT" '5.18.90 (rollback): PASSED')" "0yes" "7c the switch off again: the rollback's checks PASS"
fresh; OUT="$(run --answer DEPLOY)"
check "$(fails "$OUT")$(has "$OUT" '5.18.90 (deploy): PASSED')$(live)" "0yes$PIN" "7d deployed again over the rolled-back 5.18.89: PASSES"
check "$(verify_dept sales +256700000301)$(verify_dept support +256700000302)" "donedone" "…and the department numbers verified through the installed 5.18.90 registry, as the card records them"
D4="$(data_digest)"; fresh; OUT="$(run --answer ROLLBACK --rollback)"
check "$(fails "$OUT")$(has "$OUT" '5.18.90 (rollback): PASSED')$(has "$OUT" "ok    RB 087's tables stay in plugin.sqlite3, complete, the three department rows as it seeds them — 2 with its number verified through the card, and 1 number(s) the card added, none switched on, 0 with the assistant on; 5.18.89 does not read them with the switch off")" "0yesyes" \
  "7e rolled back with the department numbers verified: PASSES, the numbers reported — they stay, read by nothing while the registry is off"
check "$(live)$(sq "SELECT COUNT(*) FROM wa_channels WHERE business_number IS NOT NULL AND channel_id IN ('sales', 'support', 'account')")$(data_digest)" "${BASE}2$D4" "…the numbers kept as they were, no data changed by the rollback"
check "$(grep -cE -- "$LEAK_RX|$NUM_RX" <<<"$OUT")" "0" "…and neither a number nor an instance name printed"
fresh; OUT="$(run --answer DEPLOY)"
check "$(fails "$OUT")$(has "$OUT" '5.18.90 (deploy): PASSED')$(has "$OUT" "ok    A8 migration 087 (5.18.88's) is applied and complete on this server — 2 tables, 3 indexes, 3 triggers, both CHECKs, the ledger row matching the installed file — holding the three department rows exactly as it seeds them, no instance stored, 2 with its number verified through 5.18.90's card before a rollback (on the record), and 1 salesperson number(s) added through the card, none switched on")$(live)" "0yesyes$PIN" \
  "7f deployed again over 5.18.89 with those numbers in 087: A8 reports them, with their verification on the record, and the deploy PASSES"
fresh; OUT="$(run --answer ROLLBACK --rollback)"
check "$(fails "$OUT")$(has "$OUT" '5.18.90 (rollback): PASSED')$(live)$(data_digest)" "0yes${BASE}$D4" "7g …and rolled back once more: PASSES, the data as before"

echo; echo "== 8. what the rehearsal left behind, and what it never reached =="
check "$(evo_calls)" "0" "Evolution never called in $(cat "$SB/runs") runs of the script — no instance read, created or paired, no message sent"
curl -s --noproxy '*' -o /dev/null "$EVO_URL/rehearsal-control"
check "$(evo_calls)$(cat "$SB/evo/requests.log" 2>/dev/null)" "1GET /rh-evo/rehearsal-control" "control on the control: the stand-in records the one request the rehearsal makes itself — a call would have been seen"
check "$(checkout_state)" "$CHECKOUT0" "this checkout is as the rehearsal found it: same commit, same tracked files"
check "$(ls -a "$REPO/scripts" | grep -cE '^\..+\.sh$')" "0" "no weakened or re-pinned copy is left in the clone's scripts/"
check "$(left_tmp)" "$TMP0" "no copy of the code and no throwaway database is left in /tmp ($TMP0 before the rehearsal)"
check "$(grep -c 'Fatal\|Parse error' "$SB/web.log")" "0" "the stand-in served every request without a PHP fatal"

echo; echo "rehearsal: $PASS passed, $FAILN failed ($(cat "$SB/runs") runs of the script)"
[ "$FAILN" = "0" ]
