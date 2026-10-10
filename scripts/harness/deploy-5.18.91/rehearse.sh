#!/usr/bin/env bash
# Rehearse scripts/deploy-5.18.91.sh — the deploy, its checks, and the ROLLBACK — before the operator runs any of it.
#
# 5.18.91 over 5.18.90: the database safety fix, Uganda only (docs/07, 09 Oct). The Starlink retry job named <plugin>/data
# as its data directory and opened and migrated a second, stray plugin.sqlite3 there at most every 10 minutes; on Uganda it now
# stops at its first statement, and is not pointed at the live store instead. South Sudan runs 5.18.90's job exactly. No
# migration. Deployed from a RELEASE COMMIT on release/5.18.91, cut on the live 5.18.90 release commit (3d9cb5f), not from
# the branch tip, which also carries the partner portal, the PD-8 CSRF guard and the AI media layer. The base is installed
# AS PRODUCTION RUNS IT SINCE THE 08 OCT DEPLOY: 5.18.90, migrations 086 and 087 applied by the plugin's own runner, 087's
# three department rows with no instance and no number, ONE salesperson's number added through the card — switched off,
# its assistant switched off — the authorisation and the booking WhatsApp ON in both copies, the Inbox's settings row
# naming Evolution and the three numbers, lead capture ON and the uCRM write OFF, a live event queue and job photos; ucrm.json
# WITHOUT pluginDataDir, so the live data sits beside the plugin folder as on the server; and THE STRAY STORE inside the
# plugin folder — an older plugin.sqlite3, its migration.log and a settings file. The Evolution the settings name is a
# stand-in that RECORDS every request. This rehearsal proves:
#   · the pin is exactly the release: cut on 5.18.90, 9 files (2 added, 7 changed), no migration — 087 the reviewed one,
#     unchanged — each file the development branch's byte for byte, nothing of the media layer, the portal or the CSRF guard,
#     Domain B untouched; below the gate the retry job is 5.18.90's byte for byte;
#   · stage A refuses, before anything changes, each refusal carried over from 5.18.90 — a server not on 5.18.90, a pin
#     that is not the release, Domain B, the registry's switch, own leads only, 087 not as 5.18.90 left it, South Sudan,
#     a backup that does not complete — and the two new ones: a pin whose gate would not stop the retry job on THIS
#     server's own configuration (A12), and a pin whose retry job, on a throwaway layout shaped like this server, does not
#     stop on Uganda or does not keep South Sudan exactly as 5.18.90's (A13); each layer with the others blinded; and the
#     tenth review's: ucrm.json naming <plugin>/data (1aa), a file git does not track under the checkout's plugin folder,
#     untracked or ignored (1ab, 1ab2; one under data/ is not counted, 1ab3), a copy that stopped part-way — the deploy
#     refusing over it and the rollback refusing too, each naming the by-hand put-back, read from the manifest or from
#     any file the release changes (1ac–1ac4), and a real one, said at once, whose put-back the rehearsal takes from the
#     log and runs as printed — back on the branch even when its copy step fails (1ad–1ad3) — the guard, the
#     untracked-file refusal and the installed-file check weakened and caught (2x, 2y, 2z);
#   · the deploy then runs end to end and PASSES with ONE note — R19's: too soon after the copy to show the job no longer
#     opens the stray store — the stray store byte for byte and time for time as before, its folder copied into the backup,
#     never opened; every other check as 5.18.90's script has it; no data written at all;
#   · R19 is conclusive later, with --after-only. It reads the stray FOLDER's time and its inode:name entries (against the
#     record from before the deploy), the -wal and -shm, and the locks the kernel lists on the store (/proc/locks, with
#     a positive control), and judges from a REFERENCE: the copy, when the deploy found the store there held by no
#     process; otherwise a moment R19 records in the state file the first time it can — the side files the copy saw found
#     left behind, or that process's close, at most 30 minutes after the copy (the copy's time as the deploy recorded
#     it). PASS: a completed run of the job after
#     the reference and A14's "seen", with the folder unchanged, the side files as they were and no lock on the store
#     (4c, 4i; 4s, 6e2 from the recorded close; 4y2 and 4z with side files left behind, held by none). FAIL: the folder
#     changed with the same entries (4m — a real open by 5.18.90's own job — 4p, 4u), side files there that were not
#     (4v2, 4z4) or there again after the folder changed since the copy — an opener stopped, still holding it, or
#     read-only (4w2, 4w4, 4w5), side files that were there gone (4y3, 4z3), a process holding the store after the
#     reference (4x2, 4z2) or more than 30 minutes after the copy (4w1, 4w1q), a last change more than 30 minutes after
#     the copy (4r4), or the database file changed (4f); each failure recorded in the state file, and failing every later
#     run (4w2b). A note: entries added, removed or replaced (4o, and past the 30 minutes 4r6), no completed run yet
#     (4a, 4b, 4c2, 4r; since the recorded moment, 4y1), the store changing while R19 reads it (4c3) or its folder's
#     time unreadable (4c4), another writer's file beside a held store (4w6) or where recorded side files went (4y5),
#     the locks unreadable on a held store (4w7), a change
#     after master's last run began but within 30 minutes of the copy (4r2, recorded at the next run: 4r3), a process
#     holding the store within 30 minutes of the copy (4w), side files just found left behind (4y), the locks' control
#     failing (4z1), A14 not "seen" (4j, 4l), or no record from before the deploy, of the copy's time or of the store at
#     the copy (4h, 4h2, 4h3). The copy-time process's own close is no failure (4s,
#     6e2). Weakened copies, each caught: blind to the folder (4e), the database (4g), A14 (4k); the latest run as the
#     reference (4q); the copy as the reference whatever held the store (4t, 6e); the recorded failure forgotten
#     (4w2c); no 30-minute bound on a change (4r5) or on a holder (4w1m); another writer's file taken for an open
#     (4r6m, 4w6m, 4y5m); a run begun before the recorded moment counted (4y1m); the store's steadiness or its folder's
#     time not asked (4c3m, 4c4m); unreadable locks taken for none (4w7m); the recorded moment forgotten (4v, 4y4); side
#     files not compared with the copy's (4w3); the locks (4x). The summary's words are asserted where R19 fails, passes
#     or cannot compare (4c, 4f, 4h, 4m, 4s). Its migration.log is reported, not judged: another
#     job writing it passes (4d); and R18, R6 and R1 have teeth;
#   · the rollback returns the plugin to 5.18.90: the retry job 5.18.90's byte for byte, its old behaviour back on a
#     throwaway layout, 5.18.90's safety fix and everything before it kept, the stray store untouched; a redeploy over it
#     passes; a deploy run again keeps the earlier state file and says the R19 failure any kept file holds (6a, 6a3,
#     weakened 6a2); a rollback names a file git does not track and goes on (6c2);
#     a rollback declined at its question leaves the deploy's state file as it was (6f, 6f2);
#   · nothing reaches Evolution: the stand-in records no request in any of the runs, and records the one the rehearsal makes
#     itself at the end — the control on the control;
#   · weakened copies of the script are caught;
#   · the clone, /tmp and the stand-in are left as found.
#
# Everything runs in a sandbox: a fake `docker` that maps /data/ucrm to a directory here (and supports exec, cp, logs), a
# clone of this checkout, the plugin installed as 5.18.90 exactly (git archive of the release commit), a stand-in web
# server for stage V's pages that boots the INSTALLED plugin's store on every request, as the real one does, and the
# recording stand-in for Evolution. DEPLOY and ROLLBACK are typed through a pseudo-terminal, as the operator types them. It
# never touches this checkout, the server or the network, and refuses to run where the server could be. The Evolution
# settings, the numbers and the staff seeded are fictitious.
#
#   bash scripts/harness/deploy-5.18.91/rehearse.sh            REHEARSE_KEEP=<dir> keeps every run's full output
set -u
R="$(cd "$(dirname "$0")/../../.." && pwd)"
for f in /data/ucrm /opt/dishnet /var/run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
BASE=3d9cb5f; RA_BASE=e076632; OLDER=53d5c4d; BRANCH_FILE=scripts/deploy-5.18.91.sh; P=dishnet-hybrid-sudan; RELEASE_BRANCH=release/5.18.91
FIXDEV=2613fc2                                                                                # the fix, on the branch
[ -n "${REHEARSE_KEEP:-}" ] && mkdir -p "$REHEARSE_KEEP"
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
POLICY_GATE='        if ($pdo === null || !\ChannelRegistry::enabled($config, $dataDir)) return self::inert();'   # 5.18.90: the policy, inert with the registry off
WH_STEP='        $_evoWhy = $evo->automationPolicy()->senderClass($channel, $phone);'                # 5.18.90: the webhook's DishNet-number step
DEPT_VERIFY="'sn_owner', 'sn_verify_department'];"                                                  # 5.18.90: the department numbers' Verify number
SC_REFUSE="if (!\$clear && \$key === 'multi_number_channels_enabled' && filter_var(\$new, FILTER_VALIDATE_BOOLEAN)) {"   # 5.18.90: set_config refuses the registry
GUARD_REG='    $_wg_regOn = ChannelRegistry::enabled($_wg_config, $_wg_data);'                       # 5.18.90: the guard records numbers only with the registry on
JOB=cron_starlink_block_retry.php; SLR_LIB=lib/StarlinkRetryScope.php
JOB90_SHA=9e31237237b5b3cde453e14aebb587baf6c3359ec92e93b979b49d0668a5d743                         # 5.18.90's retry job
GATE_CALL='    return StarlinkRetryScope::skip(__DIR__);'                                             # 5.18.91: the gate's question
GATE_OPEN='if ((static function (): bool {'                                                          # 5.18.91: in a closure of its own
SKIP_LINE='            $out['"'"'skip'"'"'] = StaffJobsGate::applies($config, $live) === true;'     # 5.18.91: the decision
STORE_LINE='            $config = $stored + PluginConfig::read($pluginRoot, $live);'                  # 5.18.91: the live store and the files
NEVER_LINE='            // Never fopen() the database file: see the class comment. is_file() opens nothing.'   # 5.18.91, after the 09 Oct review
FOPEN_LINE="            \$h = @fopen(\$db, 'rb'); \$head = \$h ? (string)fread(\$h, 100) : ''; if (\$h) fclose(\$h);"   # the reviewed blocker, put back
SS_SIG="crm.lead.sync=done/0+unknown ai.reply=pending/0 ai.media=done/0+unknown wa.escalation=done/0+unknown install.ready=done/0+unknown"
UG_SIG="crm.lead.sync=pending/0 ai.reply=pending/0 ai.media=done/0+unknown wa.escalation=done/0 install.ready=done/0+unknown"
B2_SIG="uganda+on: card=shown visibility=on lead=hidden registry=on hold=held | uganda+off: card=shown visibility=off lead=sees registry=off hold=free | south-sudan+on: card=hidden visibility=off lead=sees registry=off hold=free"
PS_SIG="cases=12 same=12 differs=- control=same base-ignores=no"
P90_SIG="uganda+on: gaps=sales,support seller=internal_numbers_incomplete; verified: gaps=- seller=- seller>dept=internal_recipient dept>seller=internal_recipient dept=-; assistant-off: seller=channel_assistant_disabled | uganda+off: policy=off forStore=off seller=- seller>dept=- dept>seller=- own=- sql=- ai=- | south-sudan+on: policy=off forStore=off seller=- seller>dept=- dept>seller=- own=- sql=- ai=-"
POL_SIG="policy=off/off refusal=-/- sql=-/- evo=off,-/off,- statements=0 registry-reads=0"
SLR_SERVER="live=same inside=no store=read tenant=uganda skip=yes"
SLR_STOPS="data=live left=- stray=unchanged ledger=behind"
SLR_OPENS="data=plugin left=config,configFile,extResult,notify,pdo,pluginDir,retryResult,store,svc stray=changed ledger=level"
SHAPE="sales:in=sales support:in=support account:in=support shared=support+account evo=yes registry=off"   # production's: support and account share
EXPECT_N=9; EXPECT_AD=2
checkout_state() { { git -C "$R" rev-parse HEAD; git -C "$R" status --porcelain --untracked-files=no; git -C "$R" diff HEAD; } | sha256sum | cut -c1-16; }
CHECKOUT0="$(checkout_state)"
left_tmp() { ls -d /tmp/dnb-5.18.91-* 2>/dev/null | wc -l | tr -d ' '; }
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
check "$(git -C "$REPO" rev-parse --short "$PIN^" 2>/dev/null)" "$BASE" "control: the release commit is cut on $BASE (5.18.90), the live version"
ver() { git -C "$REPO" show "$1:$P/manifest.json" | python3 -c 'import json,sys; print(json.load(sys.stdin)["information"]["version"])'; }
check "$(ver "$PIN")" "5.18.91" "the plugin at the pin is 5.18.91"
check "$(ver "$BASE")" "5.18.90" "control: the base is 5.18.90"
dl() { git -C "$REPO" diff --no-renames --name-only --diff-filter="$1" "$2" "$3" -- $P; }
CHANGED="$(dl AM "$BASE" "$PIN")"
N_CH="$(grep -c . <<<"$CHANGED")"; N_AD="$(dl A "$BASE" "$PIN" | grep -c .)"; N_MO=$((N_CH - N_AD)); N_DEL="$(dl D "$BASE" "$PIN" | grep -c .)"
echo "  5.18.91   $N_CH files: $N_MO changed, $N_AD added · $N_DEL removed or renamed"
check "$N_DEL" "0" "control: 5.18.90 → 5.18.91 removes no file"
check "$N_AD" "$EXPECT_AD" "control: 5.18.91 adds exactly $EXPECT_AD files"
check "$N_CH" "$EXPECT_N" "control: $EXPECT_N files in the delta"
TAKEN="$JOB $SLR_LIB tests/test_sl_block_retry_scope.php manifest.json tests/test_distributor_apply.php tests/test_distributor_link_ucrm.php tests/test_distributor_notify.php tests/test_distributor_registry.php tests/test_distributor_territory.php"
for want in $TAKEN; do
  check "$(grep -c "^$P/$want$" <<<"$CHANGED")" "1" "control: the delta includes $want"
done
check "$(grep -c "^$P/migrations/" <<<"$CHANGED")$(git -C "$REPO" show "$PIN:$P/$MIG" | sha256sum | cut -c1-64)" "0$MIG_SHA" "control: no migration in the delta — 087 at the pin the reviewed file (sha256 ${MIG_SHA:0:16}…), unchanged"
check "$(grep -cE "^$P/(partner_api\.php|lib/Partner|lib/StaffApiCsrf\.php|lib/Totp\.php|lib/DistributorPortalData\.php|lib/Media|lib/InboundMedia|lib/Voice|lib/Image|lib/Document|lib/Pdf|lib/Handover\.php|workers/MediaWorker|run_media_worker|dishnet-mikrotik-control-plane/|docs/|lib/bootstrap_data\.php)" <<<"$CHANGED")" "0" \
  "control: no partner-portal, CSRF or AI media-layer file in the delta, nothing of Domain B or the plugin's docs, and getDataDir()/cliDataDir() untouched"
check "$(git -C "$REPO" diff --name-only "$BASE" "$PIN" | grep -vc "^$P/")" "0" "control: the whole repository's delta is the plugin's files alone"
check "$(git -C "$REPO" rev-parse "$BASE:$P/dishnet-mikrotik-control-plane")$(git -C "$REPO" rev-parse "$BASE:$P/docs")" \
      "$(git -C "$REPO" rev-parse "$PIN:$P/dishnet-mikrotik-control-plane")$(git -C "$REPO" rev-parse "$PIN:$P/docs")" "control: Domain B's tree and the plugin's docs are the same trees at the base and the pin"
same_as_fix() { [ "$(git -C "$REPO" rev-parse "$PIN:$P/$1" 2>/dev/null)" = "$(git -C "$REPO" rev-parse "$FIXDEV:$P/$1" 2>/dev/null)" ] && echo same || echo differs; }
check "$(for f in $TAKEN; do same_as_fix "$f"; done | sort | uniq -c | tr -s ' ' | tr '\n' ' ')" " 9 same " "control: all nine files are the fix's on the branch ($FIXDEV) byte for byte"
JOB_PIN="$(git -C "$REPO" show "$PIN:$P/$JOB")"
check "$(python3 -c 'import sys,hashlib; s=sys.stdin.read(); a=s.index("// ── 5.18.91: on Uganda"); b=s.index("// ── Ensure we\x27re in cron context"); print(hashlib.sha256((s[:a]+s[b:]).encode()).hexdigest())' <<<"$JOB_PIN")" "$JOB90_SHA" \
  "control: at the pin, without the 5.18.91 block, the retry job is 5.18.90's byte for byte — nothing below the gate changed"
at() { git -C "$REPO" show "$1:$P/$2" 2>/dev/null | grep -cF -- "$3"; }
check "$(at "$PIN" "$JOB" "$GATE_CALL")$(at "$PIN" "$JOB" "$GATE_OPEN")$(at "$PIN" "$SLR_LIB" "$SKIP_LINE")$(at "$PIN" "$SLR_LIB" "$STORE_LINE")" "1111" \
  "control: at the pin the gate is in place — the job asks StarlinkRetryScope in a closure of its own, which decides by StaffJobsGate on the live store and the files"
check "$(at "$BASE" "$JOB" 'StarlinkRetryScope')$(git -C "$REPO" cat-file -e "$BASE:$P/$SLR_LIB" 2>/dev/null && echo 1 || echo 0)" "00" "control: 5.18.90 has none of it"
check "$(at "$PIN" lib/AutomationPolicy.php "$POLICY_GATE")$(at "$PIN" evo_webhook.php "$WH_STEP")$(at "$PIN" lib/SalesNumbersAdmin.php "$DEPT_VERIFY")$(at "$PIN" tools/set_config.php "$SC_REFUSE")$(at "$PIN" cron/wa_webhook_guard.php "$GUARD_REG")$(at "$PIN" tabs/engage/wa_ai_setup.php "$CARD")$(at "$PIN" lib/DishNetAiBrain.php "$PERSONA")$(at "$PIN" lib/AiLeadService.php "$OWNER_RULE")$(at "$PIN" cron/followup_scan.php "$HOLD")$(at "$PIN" tools/set_config.php "$OL_LINE")$(at "$PIN" cron/event_processor.php "$LISTS")$(at "$PIN" evo_webhook.php "$FS_WH")$(at "$PIN" workers/AiReplyWorker.php "$FS_W")$(at "$PIN" lib/EventBus.php "$EXCL")$(at "$PIN" lib/AiLeadService.php "$ORIGIN")$(at "$PIN" tools/set_config.php "$FLAGLINE")$(at "$PIN" workers/AiReplyWorker.php "$B0_PIN")$(at "$PIN" workers/UcrmLeadWorker.php "$B0_PO")$(at "$PIN" includes/api/api_whatsapp.php "$IR_CALL")$(at "$PIN" lib/InstallAuth.php "$RULE")$(at "$PIN" webhook.php "$WA_CALL")$(at "$PIN" includes/api/api_install_auth.php "$QP_READ")" "1111112111111111114111" \
  "control: 5.18.90's safety fix, 5.18.89's Batch 2, 5.18.88's Batch 1, 5.18.87's Batch 0, 5.18.86's Inbox route, 5.18.85's quotation read, 5.18.84's booking WhatsApp and 5.18.83's authorisation are whole at the pin"
check "$(at "$PIN" evo_webhook.php 'MediaPolicy')$(at "$PIN" workers/AiReplyWorker.php 'VoiceTranscription')$(at "$PIN" tools/set_config.php "'ai_media_enabled'")$(at "$PIN" workers/AiReplyWorker.php 'Handover::escalate')" "0000" \
  "control: and no AI media layer at the pin — no media branch in the webhook, no media path or shared hand-over in the AI worker, no media switch"
HEADER_CMD="$(sed -n '/^# Run as root/,/^# The rollback is a separate command/p' "$DEPLOY")"
check "$(grep -c 'deploy-5.18.91.sh 2>&1 | tee' <<<"$HEADER_CMD")$(grep -cE -- '--rollback|--key|--value|set_config|git checkout [0-9a-f]{7}' <<<"$HEADER_CMD")" "10" \
  "the header's deploy command stands alone: no rollback, no switch command and no checkout of another commit in its block (docs/44 §16.9)"
check "$(grep -c "git fetch origin $RELEASE_BRANCH" <<<"$HEADER_CMD")$(grep -c 'deploy-5.18.91.sh --after-only 2>&1 | tee' <<<"$HEADER_CMD")" "11" "the header's deploy command fetches the release branch; the later --after-only check is its own command"
SWITCH_KEYS='customer_wa_install_scheduled|install_auth_enabled|multi_number_channels_enabled|ai_lead_capture|ai_crm_lead_sync|ai_qualification|ai_sales_on_all_numbers|sales_own_leads_only|wa_handover_copy_central|wa_followups_on_owned_numbers'
SWITCH_RX="--key +($SWITCH_KEYS)|($SWITCH_KEYS) +--value"
check "$(grep -cE -- "$SWITCH_RX" "$DEPLOY")" "0" "the script carries no command that switches a feature, the registry, own leads only or a lead switch — neither on nor off"
check "$(sed '/^P90_PHP="\$(cat <<.PHP.$/,/^PHP$/d' "$DEPLOY" | grep -cE -- '->(create|verifyNumber|verifyDepartmentNumber|setStatus|setAiEnabled|setInstance|setOwner)\(')$(grep -cE -- '->(create|verifyNumber|verifyDepartmentNumber|setStatus|setAiEnabled|setInstance|setOwner)\(' "$DEPLOY")" "06" \
  "the script never verifies, creates or switches a number on the plugin's data — every registry write it names is P90's, on the throwaway database (the control: it names them there)"
check "$(grep -cE -- '(rm|mv|cp|unlink|rename)[^|;]*\$DEST/data|\$DEST/data[^ ]*\.(quarantine|bak)' "$DEPLOY")$(grep -cE -- 'tar -C "\$DEST" -czf "\$BK/stray-data\.tar\.gz" data' "$DEPLOY")" "01" \
  "the script never moves, copies over, renames or deletes the stray store's folder — it only reads it, as files, into the backup (the control: that read is there)"
# ── The container: 5.18.90 installed AS PRODUCTION RUNS IT since the 08 Oct deploy, its data beside it, the stray store in it ─
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
  find "$PD" -mindepth 1 -maxdepth 1 ! -name ucrm.json ! -name data -exec rm -rf {} +
  git -C "$REPO" archive "$1:$P" | tar -x -C "$PD"
  printf '%s\n' "$1" > "$PD/.deployed-commit"
}
install_base() { install_commit "$BASE"; }
printf '{"ucrmPublicUrl":"http://127.0.0.1:1/crm","pluginId":7}' > "$PD/ucrm.json"   # production's: no pluginDataDir
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
seed_data() {      # the plugin's databases, written by 5.18.90's own store (084, 086 and 087 applied by its runner, as production's
                   # runner applied 087 on 07 Oct); then production's state after 5.18.90's deploy: the authorisation and the
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
installed_digest() {   # $1 a commit: the number of files 5.18.91 touches that are NOT installed as that commit has them
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
  rm -f "$REPO/scripts/.mutant-$name.sh"
  python3 - "$src" "$REPO/scripts/.mutant-$name.sh" "$@" <<'PY' || echo "mutant:$name" >> "$SB/anchor-missing"
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
release_variant() {   # release_variant add|addroot|revert|edit PATH [OLD NEW] — a commit cut on 5.18.90 holding the release's tree
                      # with one file planted (in the plugin, or anywhere in the repository), put back as 5.18.90 has it, or edited
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
             [ "$blob" = "$(printf '' | git -C "$REPO" hash-object --stdin)" ] && echo "release_variant:$path" >> "$SB/anchor-missing"
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
  python3 - "$PD/$1" "$2" "$3" <<'PY' || echo "edit_installed:$1" >> "$SB/anchor-missing"
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

# ── 5.18.91: the stray store inside the plugin folder, master's schedule, the installed job's time ─────────────────
row_set() {   # row_set KEY VALUE — a key in the store row ONLY; the configuration file keeps its own
  php -r '$p = new PDO("sqlite:" . $argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $r = json_decode((string)$p->query("SELECT data FROM kyc_config WHERE id = 0")->fetchColumn(), true); $r[$argv[2]] = $argv[3];
    $p->prepare("UPDATE kyc_config SET data = ? WHERE id = 0")->execute([json_encode($r)]);' "$DATA/plugin.sqlite3" "$1" "$2"; }
STRAY_OPEN='foreach (["StoreInterface", "JsonStore", "SqliteStore"] as $l) require_once $argv[1] . "/lib/$l.php"; $s = SqliteStore::create($argv[2]); $s = null; echo "done";'
seed_stray() {   # the stray store as the server has it (docs/07, 09 Oct): inside the plugin folder, made by the installed plugin's
                 # own store, at every migration as the live one is — so an open of it changes neither its database nor its
                 # migration.log, only its folder (the -wal and -shm made and removed); its own settings file; all of it, the
                 # folder included, last changed two hours ago
  php -r "$STRAY_OPEN" "$PD" "$PD/data" >/dev/null; php -r "$STRAY_OPEN" "$PD" "$PD/data" >/dev/null
  printf '{"note":"the stray folder settings file"}' > "$PD/data/config.json"
  find "$PD/data" -type f -exec touch -d '-2 hours' {} +; touch -d '-2 hours' "$PD/data"
}
stray_probe() {   # R19's instrument on a COPY of the stray store: one open by the installed plugin's own store — lines added to its
                  # migration.log, whether the database file changed (checksum, size, time), whether the folder changed
  local d="$SB/strayprobe" n0 n1 i0 i1 f0 f1
  rm -rf "$d"; cp -a "$PD/data" "$d"
  n0="$(wc -l < "$d/migration.log")"; i0="$(sha256sum "$d/plugin.sqlite3" | cut -c1-16):$(stat -c '%s:%Y' "$d/plugin.sqlite3")"; f0="$(stat -c %y "$d")"
  php -r "$STRAY_OPEN" "$PD" "$d" >/dev/null 2>&1
  n1="$(wc -l < "$d/migration.log")"; i1="$(sha256sum "$d/plugin.sqlite3" | cut -c1-16):$(stat -c '%s:%Y' "$d/plugin.sqlite3")"; f1="$(stat -c %y "$d")"
  rm -rf "$d"; echo "$((n1 - n0)):$( [ "$i0" = "$i1" ] && echo same || echo changed):$( [ "$f0" = "$f1" ] && echo same || echo changed)"
}
dpo_writer() {   # what cron/dpo_reconcile.php does on the server every 5 minutes: MigrationRunner with its DEFAULT log —
                 # <plugin>/data/migration.log, the stray folder's — on a store whose ledger does not match one file (the live
                 # store's 071 there). Here a throwaway ledger, every file recorded, one with another checksum: one warning line.
  php -r 'require $argv[1] . "/lib/MigrationRunner.php"; $p = new PDO("sqlite:" . $argv[2]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $p->exec("CREATE TABLE IF NOT EXISTS _migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, filename TEXT UNIQUE NOT NULL, checksum TEXT NOT NULL, applied_at TEXT DEFAULT (datetime(\x27now\x27)), duration_ms INTEGER)");
    $ins = $p->prepare("INSERT OR IGNORE INTO _migrations (filename, checksum) VALUES (?, ?)");
    foreach (glob($argv[1] . "/migrations/*.sql") as $f) { $b = basename($f); $ins->execute([$b, $b === "071_dpo_payments.sql" ? "0000rehearsal-mismatch" : md5((string)file_get_contents($f))]); }
    (new MigrationRunner($p, $argv[1] . "/migrations"))->run(); echo "done";' "$PD" "$SB/dpo-emu.sqlite"
  rm -f "$SB/dpo-emu.sqlite"
}
stray_keep() { rm -rf "$SB/strayk"; cp -a "$PD/data" "$SB/strayk"; stat -c %y "$PD/data" > "$SB/strayk.t"; stray_entries_here > "$SB/strayk.ents"; }
stray_entries_here() { find "$PD/data" -mindepth 1 -maxdepth 1 ! -name 'plugin.sqlite3-wal' ! -name 'plugin.sqlite3-shm' ! -name 'plugin.sqlite3-journal' -printf '%i:%f\n' | sort | paste -sd/ -; }
copy_dir() { sed -n 's/^STRAY_COPY=.* dir=\([0-9]*\)$/\1/p' "$STATE" | head -1; }   # the folder's time the deploy recorded at the copy
stray_back() {   # in place: an entry the scenario added is removed, every kept file written back INTO its own inode (cp onto an
                 # existing file truncates and writes it), then the folder's time — so R19's inode:name record still matches
  local f; for f in "$PD/data"/* "$PD/data"/.[!.]*; do [ -e "$f" ] || continue; [ -e "$SB/strayk/$(basename "$f")" ] || rm -rf "$f"; done
  cp -a "$SB/strayk/." "$PD/data/"; touch -d "$(cat "$SB/strayk.t")" "$PD/data"; rm -rf "$SB/strayk" "$SB/strayk.t" "$SB/strayk.ents"; }
plugin_files() { find "$PD" -path "$PD/data" -prune -o -type f -printf '%P %s %T@\n' | sort | sha256sum | cut -c1-16; }
stray_snap() {   # every entry of the stray folder — bytes, time and inode — and the folder's own time: a file made and removed shows
  { stat -c '%n %y %i' "$PD/data"; find "$PD/data" -type f | sort | while read -r f; do printf '%s %s %s %s\n' "$f" "$(stat -c %y "$f")" "$(stat -c %i "$f")" "$(sha256sum "$f" | cut -c1-64)"; done; } | sha256sum | cut -c1-16
}
stray_line() {   # what the script prints for the stray store (its stray_state), computed here independently
  local d="$PD/data" side="" s
  for s in -wal -shm -journal; do [ -e "$d/plugin.sqlite3$s" ] && side="$side$s"; done
  echo "db=$(sha256sum "$d/plugin.sqlite3" | cut -c1-16):$(stat -c '%s:%Y' "$d/plugin.sqlite3") side=${side:--} log=$(stat -c '%s:%Y' "$d/migration.log") dir=$(stat -c %Y "$d")"
}
stray_ledger() {   # the stray store's migrations, read immutably — the reading creates no -wal or -shm beside it
  php -r '$p = new PDO("sqlite:file:" . $argv[1] . "?immutable=1", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]); echo (int)$p->query("SELECT COUNT(*) FROM _migrations")->fetchColumn();' "$PD/data/plugin.sqlite3" 2>/dev/null || echo err
}
set_sched() {   # set_sched EPOCH [DURATION_MS] — master's record of a run of the retry job then (master_schedule, as master saves
                # it): a completed run by default, one still running with -1, as master writes before it includes the job
  php -r 'foreach (["StoreInterface", "JsonStore", "SqliteStore"] as $l) require_once $argv[1] . "/lib/$l.php";
    SqliteStore::create($argv[2])->save("master_schedule.json", ["sl_block_retry" => ["last_run" => (int)$argv[3], "last_run_at" => gmdate("Y-m-d H:i:s", (int)$argv[3]), "duration_ms" => (int)$argv[4]]]);' "$PD" "$DATA" "$1" "${2:-850}" >/dev/null 2>&1
}
sched_clear() { sqx "DROP TABLE IF EXISTS master_schedule" >/dev/null; }
job_t() { stat -c %Y "$PD/$JOB"; }
utc() { date -u -d "@$1" +%Y-%m-%dT%H:%M:%SZ; }   # an epoch as the script prints it
holder_start() {   # a process that opens the stray store as 5.18.90's job does — the installed plugin's own store: WAL, the -wal and
                   # -shm made — and keeps it open until told to end: the old job's master process, still running
  rm -f "$SB/holder.go" "$SB/holder.ready"
  php -r 'foreach (["StoreInterface", "JsonStore", "SqliteStore"] as $l) require_once $argv[1] . "/lib/$l.php"; $s = SqliteStore::create($argv[2]);
    touch($argv[3] . ".ready"); while (!file_exists($argv[3] . ".go")) usleep(100000); echo "done";' "$PD" "$PD/data" "$SB/holder" >/dev/null 2>&1 &
  HOLDER=$!; PIDS+=("$HOLDER")
  local i; for i in $(seq 1 100); do [ -f "$SB/holder.ready" ] && break; sleep 0.1; done
}
holder_reaped() {   # the holder is gone: out of PIDS, so cleanup never signals a number the system may since have reused
  local q k=(); for q in "${PIDS[@]}"; do [ "$q" = "$HOLDER" ] || k+=("$q"); done; PIDS=("${k[@]}"); HOLDER=""
  rm -f "$SB/holder.go" "$SB/holder.ready"; }
holder_end() {    # the process ends: as its last connection closes, SQLite removes the -wal and -shm — a change of the folder
  touch "$SB/holder.go"; wait "$HOLDER" 2>/dev/null; holder_reaped; }
holder_kill() {   # the process is killed before it can close the store: the -wal and -shm stay where they are
  kill -KILL "$HOLDER" 2>/dev/null; wait "$HOLDER" 2>/dev/null; holder_reaped; }
side_files() { ls "$PD/data" | grep -E '^plugin\.sqlite3-(wal|shm)$' | paste -sd, -; }
stray_locks_here() {   # POSIX locks on the stray database and its -shm, by inode, from /proc/locks — computed here independently
  local f n=0 i; for f in "$PD/data/plugin.sqlite3" "$PD/data/plugin.sqlite3-shm"; do [ -e "$f" ] || continue; i="$(stat -c %i "$f")"
    n=$(( n + $(awk -v i=":$i" '$2 != "->" && substr($6, length($6) - length(i) + 1) == i {c++} END {print c+0}' /proc/locks) )); done; echo "$n"; }
job_back() {   # the copy N minutes ago, as a later --after-only finds it: the job's time and the copy's recorded time
  touch -d "-$1 minutes" "$PD/$JOB"
  sed -i "s/^STRAY_COPY_AT=.*/STRAY_COPY_AT=$(stat -c %Y "$PD/$JOB")/" "$STATE"
}
copy_at() { sed -i "s/^STRAY_COPY_AT=.*/STRAY_COPY_AT=$1/" "$STATE"; }   # the copy's recorded time, set for one case

install_base; seed_data; seed_stray; vault UGX; fresh
# master's own record of its last completed run of the retry job (master_schedule in the live store): five seconds before
# the stray folder's last change, as on the server — the job's open makes and removes the -wal and -shm there (A14 reads
# the two together)
SCHED0=$(( $(stat -c %Y "$PD/data") - 5 )); set_sched "$SCHED0"
flip --on   # enable the pilot through the real tool, exactly as the live server has it
for i in $(seq 1 50); do [ "$(standin_page customer_login)" = "200" ] && break; sleep 0.1; done
for i in $(seq 1 50); do evo_ready && break; sleep 0.1; done
# Step 0 of 5.18.87's handover, as the operator ran it on 07 Oct: the uCRM lead write off, with the installed tool.
S0="$(setcfg ai_crm_lead_sync 0)"
# The 07 Oct pilot as production's record has it: one salesperson's number added through the card, switched off, then its
# assistant switched off — through the installed registry, each with its trail row.
PILOT="$(add_number sales-001 rh-seller)$(set_ai sales-001 0)"
D0="$(data_digest)"; STRAY0="$(stray_snap)"
M87MD5="$(md5sum "$PD/$MIG" | cut -c1-32)"
ROWS0="$(sq "SELECT COUNT(*) FROM wa_channels"):$(sq "SELECT COUNT(*) FROM wa_channel_log")"
ST0="mig=applied:$M87MD5 tables=2/2 indexes=3/3 triggers=3/3 checks=2/2 rows=$ROWS0 seed=ok dnum=0 other=1 active=0 oai=0 trail=ok dtrail=0 missing=-"
check "$(inst_ver)" "5.18.90" "control: the installed base is 5.18.90"
check "$(grep -cF -- "$B0_PIN" "$PD/workers/AiReplyWorker.php")$(grep -cF -- "$LISTS" "$PD/cron/event_processor.php")$(grep -cF -- "$CARD" "$PD/tabs/engage/wa_ai_setup.php")$(grep -cF -- "$WH_STEP" "$PD/evo_webhook.php")$(test -f "$PD/lib/AutomationPolicy.php" && echo 1 || echo 0)$(grep -c 'StarlinkRetryScope' "$PD/$JOB")$(test -f "$PD/$SLR_LIB" && echo 1 || echo 0)$(sha256sum "$PD/$JOB" | cut -c1-64)" "1111100$JOB90_SHA" \
  "control: the base is production's: Batch 0, Batch 1, Batch 2 and 5.18.90's safety fix in place; the retry job 5.18.90's byte for byte, no gate"
check "$(has "$S0" 'ai_crm_lead_sync = 0')$(both_copies ai_crm_lead_sync)$(both_copies ai_lead_capture)$(both_copies multi_number_channels_enabled)$(both_copies sales_own_leads_only)" "yes0011--" \
  "control: the lead switches as production has them — capture ON, the uCRM write OFF — and the registry's switch and own leads only unset"
check "$(ia_objects)$(sq "SELECT COUNT(*) FROM _migrations WHERE filename = '086_install_authorisation.sql'")$(ia_rows)" "5:10:410:1:1:1:0" "control: 086 applied by the plugin's own runner, complete, with production's records"
check "$PILOT|$(mig87)$(wa_objects)|$(wa_rows)|$(sq "SELECT COUNT(*) FROM wa_channels WHERE business_number IS NOT NULL")" \
  "donedone|12:3:3|account/NULL/active,sales/NULL/active,sales-001/rh-seller/disabled,support/NULL/active|0" \
  "control: 087 applied, the three department rows with NO instance and NO number, and the pilot's number switched off, its assistant off"
check "$(sq "SELECT COUNT(*) FROM events")|$(sq "SELECT COUNT(*) FROM events WHERE status <> 'done'")|$(sq "SELECT COUNT(*) FROM job_photos")" "6|3|2" "control: an event queue with work in it and two photos"
# The layout this release is about, as the server has it (docs/07, 09 Oct): ucrm.json with no pluginDataDir, the live store
# beside the plugin folder — the one the installed plugin's own getDataDir() chooses — and the stray store inside it, older.
check "$(php -r '$u = json_decode((string)file_get_contents($argv[1]), true); echo array_key_exists("pluginDataDir", (array)$u) ? "has" : "none";' "$PD/ucrm.json")|$(php -r 'require $argv[1] . "/lib/bootstrap_data.php"; echo getDataDir($argv[1]) === $argv[2] ? "sibling" : "other";' "$PD" "$DATA")" \
  "none|sibling" "control: ucrm.json names no pluginDataDir, and the installed plugin's getDataDir() chooses the folder beside it — as on the server"
check "$(stray_ledger)/$(sq "SELECT COUNT(*) FROM _migrations")|$(ls "$PD/data" | grep -cxE 'plugin\.sqlite3|migration\.log|config\.json')$(ls "$PD/data" | grep -cE 'sqlite3-(wal|shm)$')" "$(sq "SELECT COUNT(*) FROM _migrations")/$(sq "SELECT COUNT(*) FROM _migrations")|30" \
  "control: the stray store inside the plugin folder at every migration, as the live one and as the server's (087, docs/07 09 Oct) — with its log and its own settings file"
check "$(stray_probe)$(stray_snap)" "0:same:changed$STRAY0" \
  "control on R19's instrument: an open of a copy of the stray store by 5.18.90's own store writes nothing to its migration.log and leaves the database file as it is — only the folder changes (the -wal and -shm made and removed); the stray store itself untouched by the probe"
check "$(sq "SELECT json_extract(data, '$.sl_block_retry.last_run') || '/' || json_extract(data, '$.sl_block_retry.duration_ms') FROM master_schedule WHERE id = 0")|$(( $(stat -c %Y "$PD/data") - $(date -u +%s) < -3600 ))" "$SCHED0/850|1" \
  "control: master's record of a completed run of the retry job, five seconds before the stray folder's last change — both two hours old"
check "$(env PATH="$SB/bin:$PATH" DN_DATA_DIR="$DATA" bash "$SB/bin/docker" exec ucrm php "$IN_CONTAINER/tools/set_distributors.php" --show 2>/dev/null | awk '/distributors_enabled/ {print toupper($2); exit}')" "ON" "control: the pilot is ON on the base"
check "$(standin_page customer_login)$(standin_page install_auth)$(standin_page 'dashboard&tab=wa_ai_setup')$(data_digest)$(stray_snap)" "200404302$D0$STRAY0" \
  "control: the stand-in boots the installed store, and nothing it serves changes any data or the stray store"
check "$(evo_ready && echo up)$(evo_calls)" "up0" "control: the recording stand-in for Evolution is listening, and nothing has called it"

echo; echo "== 1. NO-GO before anything changes — each refusal, and nothing deployed, backed up or changed =="
nogo() {   # nogo LABEL EXPECTED-TEXT OUTPUT — the refusal said, nothing deployed, no backup, the data and the stray store as seeded
  check "$(has "$3" "$2")$(live)$(backups)$(data_digest)$(stray_snap)" "yes${BASE}0$D0$STRAY0" "$1"
}
printf '%s\n' "$OLDER" > "$PD/.deployed-commit"; fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: the container serves $OLDER; 5.18.91 was built and tested against $BASE (5.18.90) — deploy 5.18.90 first (scripts/deploy-5.18.90.sh) and send its log")$(backups)$(data_digest)" "yes0$D0" \
  "1a refusal 1: the server still runs 5.18.89 — NO-GO, 5.18.90 goes first; no backup, no data changed"
printf '%s\n' "$BASE" > "$PD/.deployed-commit"
cp "$PD/manifest.json" "$SB/manifest.kept"; sed -i 's/"version": "5.18.90"/"version": "5.18.89"/' "$PD/manifest.json"; fresh; OUT="$(run --answer DEPLOY)"
nogo "1b refusal 1: the record says $BASE but the installed manifest says 5.18.89 — NO-GO on the version too" \
  "STOP: NO-GO: the container's record says $BASE, but the installed manifest says 5.18.89, not 5.18.90" "$OUT"
cp "$SB/manifest.kept" "$PD/manifest.json"
S="$(pinned_copy unpinned __PLUGIN_COMMIT__)"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1c a copy still carrying the placeholder pin: stops before anything is read" "STOP: this copy of the script is not pinned to a reviewed commit" "$OUT"
S="$(pinned_copy tip "$TIP")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1d a copy pinned to the branch tip ($TIP, parent not 5.18.90): refused — the undeployed work cannot ride along" "STOP: $TIP is not cut on $BASE (5.18.90): its parent is" "$OUT"
V="$(release_variant add lib/MediaPolicy.php)"; S="$(pinned_copy v "$V")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1e refusal 5: the release AND an AI media-layer file — refused by the delta's allow-list, naming it" \
  "STOP: the pin carries files that are not 5.18.91's, which this release must not ship: lib/MediaPolicy.php(added)" "$OUT"
V_MIG="$(release_variant add migrations/088_rehearsal.sql)"; S="$(pinned_copy v "$V_MIG")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1f refusal 5: the release AND a migration — refused, naming it" \
  "STOP: the pin carries files that are not 5.18.91's, which this release must not ship: migrations/088_rehearsal.sql(added)" "$OUT"
V="$(release_variant edit lib/bootstrap_data.php "function cliDataDir(string \$pluginRoot): string" "function cliDataDir(string \$pluginRoot): string // rehearsal")"; S="$(pinned_copy v "$V")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1g refusal 5: the release AND a change to bootstrap_data.php (cliDataDir, getDataDir's rescue copy) — not 5.18.91's to change: refused, naming it" \
  "STOP: the pin carries files that are not 5.18.91's, which this release must not ship: lib/bootstrap_data.php" "$OUT"
V="$(release_variant revert "$JOB")"; S="$(pinned_copy v "$V")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1h the release WITHOUT the retry job's gate — refused, naming the file it lacks" "STOP: the pin lacks files 5.18.91 is made of: $JOB" "$OUT"
V_DB="$(release_variant add dishnet-mikrotik-control-plane/REHEARSAL.md)"; S="$(pinned_copy v "$V_DB")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1i refusal 7: the release AND a file under Domain B — refused as Domain B, before the allow-list" \
  "STOP: NO-GO (Domain B): the pin changes Domain B, the plugin's documents or files outside the plugin: dishnet-mikrotik-control-plane/REHEARSAL.md dishnet-mikrotik-control-plane/:tree-differs" "$OUT"
V="$(release_variant addroot scripts/REHEARSAL.txt)"; S="$(pinned_copy v "$V")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1k refusal 7: the release AND a file outside the plugin — refused" \
  "STOP: NO-GO (Domain B): the pin changes Domain B, the plugin's documents or files outside the plugin: scripts/REHEARSAL.txt(outside-the-plugin)" "$OUT"
cfg_keep
for c in both store; do
  case "$c" in both) cfg_set multi_number_channels_enabled on; want="mn=on/on" ;; store) cfg_set multi_number_channels_enabled on store; want="mn=absent/on" ;; esac
  DX="$(data_digest)"; fresh; OUT="$(run --answer DEPLOY)"
  check "$(has "$OUT" "STOP: NO-GO: multi_number_channels_enabled reads '$want' (files/store). With the registry on, a salesperson's number is routed and 5.18.90's automated-send policy acts — not the state 5.18.91 was rehearsed against")$(live)$(backups)$(data_digest)$(stray_snap)" "yes${BASE}0$DX$STRAY0" \
    "1l refusal 2: the registry's switch ON ($c — $want): NO-GO, nothing deployed, no data changed"
  cfg_back
done
cfg_set sales_own_leads_only on; DX="$(data_digest)"; fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: sales_own_leads_only reads 'ol=on/on' (files/store). With it on every salesperson sees only their own leads — not the state 5.18.91 was rehearsed against, and the operator decided it stays OFF")$(live)$(backups)$(data_digest)" "yes${BASE}0$DX" \
  "1m refusal 9: own leads only ON: NO-GO — the operator decided it stays OFF"
cfg_back
check "$(data_digest)" "$D0" "control: the copies put back byte for byte"
db_keep
check "$(sqx "DELETE FROM _migrations WHERE filename = '087_wa_channels.sql'")" "done" "control: 087's ledger row removed by hand"
fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: migration 087 is not recorded on this server (${ST0/mig=applied:$M87MD5/mig=absent}), although 5.18.90 is live")$(live)$(backups)" "yes${BASE}0" \
  "1n refusal 3: 087 not recorded although 5.18.90 is live — NO-GO"
db_back
check "$(sqx "UPDATE wa_channels SET status = 'active' WHERE channel_id = 'sales-001'")" "done" "control: the pilot's number switched on by hand"
fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: migration 087 is recorded on this server, but not as 5.18.90 left it (${ST0/active=0/active=1}) — 5.18.90's code reads its tables")$(live)$(backups)" "yes${BASE}0" \
  "1p refusal 4: a salesperson's number switched on before the deploy — NO-GO"
check "$(grep -cE -- "$LEAK_RX" <<<"$OUT")" "0" "…without printing its instance name"
db_back
# A12 — the gate on THIS server's own configuration. A pin whose gate is inverted; a server whose configuration does not
# say Uganda: either way the fix would not take effect here, and nothing is deployed.
V_INV="$(release_variant edit "$JOB" "$GATE_CALL" '    return !StarlinkRetryScope::skip(__DIR__);')"
S="$(pinned_copy v "$V_INV")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1r refusal 13: a pin whose gate is inverted — A12 reads the library, which still says skip; A13 runs the job and sees it inverted: NO-GO, both countries named" \
  "STOP: NO-GO: the pin's retry job does not stop on Uganda, does not keep South Sudan exactly as it is, or costs master its lock on the live database — or the check could not be made: south-sudan-differs uganda-not-stopped" "$OUT"
V_NOSKIP="$(release_variant edit "$SLR_LIB" "$SKIP_LINE" "            \$out['skip'] = false;")"
S="$(pinned_copy v "$V_NOSKIP")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1s refusal 12: a pin whose library never says skip — A12 on this server's own configuration: NO-GO, the fix would not take effect here" \
  "STOP: NO-GO: on this server's own configuration the pin's gate would not stop the Starlink retry job before it touches <plugin>/data (live=same inside=no store=read tenant=uganda skip=no" "$OUT"
cfg_keep; row_set tenant_profile south-sudan; DX="$(data_digest)"; fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: on this server's own configuration the pin's gate would not stop the Starlink retry job before it touches <plugin>/data (live=same inside=no store=read tenant=south-sudan skip=no | by-store=south-sudan by-files+vault=uganda; expected $SLR_SERVER)")$(live)$(backups)$(data_digest)$(stray_snap)" "yes${BASE}0$DX$STRAY0" \
  "1t refusal 12: a server whose live store names South Sudan — A12: the fix would not take effect, NO-GO; each source's country printed"
cfg_back
# A13 — the retry job itself, on throwaway layouts. A pin whose library skips everywhere (South Sudan too); a pin that
# never reads the live store, where the throwaway layout names the country in the store alone.
V_ALL="$(release_variant edit "$SLR_LIB" "$SKIP_LINE" "            \$out['skip'] = true;")"
S="$(pinned_copy v "$V_ALL")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1u refusal 13: a pin whose gate stops the job on South Sudan too — A12 is satisfied here, A13 is not: NO-GO, South Sudan named" \
  "STOP: NO-GO: the pin's retry job does not stop on Uganda, does not keep South Sudan exactly as it is, or costs master its lock on the live database — or the check could not be made: south-sudan-differs" "$OUT"
check "$(has "$OUT" 'ok    A12 on this server')$(has "$OUT" "pin  5.18.91, South Sudan: $SLR_STOPS")" "yesyes" "…A12 passed it on this server's configuration, and A13 printed the pin stopping the job as South Sudan"
V_NOSTORE="$(release_variant edit "$SLR_LIB" "$STORE_LINE" '            $config = PluginConfig::read($pluginRoot, $live);')"
S="$(pinned_copy v "$V_NOSTORE")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1v refusal 13: a pin that never reads the live store — here the vault says Uganda, so A12 passes; on the throwaway layout the store alone says it: NO-GO (A13)" \
  "STOP: NO-GO: the pin's retry job does not stop on Uganda, does not keep South Sudan exactly as it is, or costs master its lock on the live database — or the check could not be made: uganda-not-stopped" "$OUT"
V_FOPEN="$(release_variant edit "$SLR_LIB" "$NEVER_LINE" "$FOPEN_LINE"$'\n'"$NEVER_LINE")"
S="$(pinned_copy v "$V_FOPEN")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1v2 refusal 13: a pin whose library reads the live database's header with fopen() — the 09 Oct review's blocker — costs master its lock on the live database: NO-GO, on the server's own PHP" \
  "STOP: NO-GO: the pin's retry job does not stop on Uganda, does not keep South Sudan exactly as it is, or costs master its lock on the live database — or the check could not be made: live-store-lock-dropped" "$OUT"
check "$(has "$OUT" "pin  5.18.91, Uganda:      $SLR_STOPS | locks=dropped")$(has "$OUT" 'ok    A12 on this server')" "yesyes" "…the pin still stops the job and A12 passes — only the lock shows the fault"
cp -p "$PD/$JOB" "$SB/job90.aside"; edit_installed "$JOB" "\$dataDir = \$pluginDir . '/data';" "\$dataDir = \$pluginDir . '/data-rh';"
fresh; OUT="$(run --answer DEPLOY)"
nogo "1v3 the installed 5.18.90 job edited (its data folder renamed): since the eleventh review the installed-file check refuses first, naming it — the job is not 5.18.90's" \
  "STOP: NO-GO: the container's record says $BASE (5.18.90), but these installed files are not 5.18.90's: $JOB — a copy that did not complete (a deploy that stopped part-way), or an edit by hand" "$OUT"
# A13's own control, behind that check: the same edited job, with an instrumented copy of the script that skips the
# installed-file check — A13, the layer beneath it, still refuses.
M="$(mutant a13ctl "$DEPLOY" '  if [ -n "$BASE_DIFFERS" ]; then' '  if false; then')"
fresh; OUT="$(run --answer DEPLOY --script "$M")"; rm -f "$M"
cp -p "$SB/job90.aside" "$PD/$JOB"
nogo "1v3b refusal 13's control: the live 5.18.90 job no longer opening the stray store on the throwaway layouts — A13 cannot see a change, so it cannot judge the pin: NO-GO (the installed-file check skipped to reach it)" \
  "STOP: NO-GO: the pin's retry job does not stop on Uganda, does not keep South Sudan exactly as it is, or costs master its lock on the live database — or the check could not be made: live-uganda-not-measured live-south-sudan-not-measured" "$OUT"
check "$(sha256sum "$PD/$JOB" | cut -c1-64)" "$JOB90_SHA" "control: the installed job put back, 5.18.90's byte for byte"
fresh; OUT="$(run --answer DEPLOY --env FAKE_CP_FAIL=1)"
nogo "1w the two releases' code cannot be copied into the container: NO-GO before A12 and A13, which need it too" \
  "STOP: NO-GO: the two releases' code could not be copied into the container's /tmp" "$OUT"
fresh; OUT="$(run --answer DEPLOY --env DNB_SNAPSHOT_TMP=/nonexistent-dir)"
check "$(has "$OUT" 'FAIL  backup of plugin.sqlite3 failed')$(has "$OUT" 'STOP: NO-GO: the backup or a before-check did not complete')$(live)$(data_digest)$(stray_snap)" "yesyes${BASE}$D0$STRAY0" \
  "1x refusal 8: the database's backup fails — NO-GO after the backup stage, nothing deployed, no data or stray store changed"
cfg_keep; cp -p "$VAULT" "$SB/vault.kept1"; [ -f "$DATA/config.json" ] && cp -p "$DATA/config.json" "$SB/config.kept1"; vault USD
php -r '$f = $argv[1]; $c = json_decode((string)file_get_contents($f), true); foreach (["tenant_profile", "currency_code", "cashbook_base_currency"] as $k) unset($c[$k]); file_put_contents($f, json_encode($c));' "$DATA/kyc_config.json"
fresh; OUT="$(run --answer NO)"
cfg_back; cp -p "$SB/vault.kept1" "$VAULT"; [ -f "$SB/config.kept1" ] && cp -p "$SB/config.kept1" "$DATA/config.json"
check "$(has "$OUT" "ok    A12 on this server's own configuration the pin's gate stops")$(has "$OUT" "note  A12 the configuration files and the vault alone do not name Uganda (by-store=uganda by-files+vault=south-sudan)")$(has "$OUT" 'STOP: not confirmed')$(live)$(data_digest)$(stray_snap)" "yesyesyes${BASE}$D0$STRAY0" \
  "1y the configuration files and the vault alone naming no Uganda: A12 passes on the store, notes the one 02:00 run it would cost, and nothing is deployed (answered NO); the files and the vault put back byte for byte"
db_keep; sched_clear; fresh; OUT="$(run --answer NO)"; db_back
check "$(has "$OUT" "note  A14 the stray folder's last change was not within one dispatch of 5.18.90's retry job")$(has "$OUT" "master's last dispatch: — no record (?))")$(has "$OUT" 'master last dispatched the retry job — no record (?); its migration.log')$(live)$(data_digest)" "yesyesyes${BASE}$D0" \
  "1z no record of master's runs at all: A14 cannot hold the folder against the job — a note, never 'seen'"
set_sched $(( SCHED0 - 7200 )); fresh; OUT="$(run --answer NO)"; set_sched "$SCHED0"
check "$(has "$OUT" "note  A14 the stray folder's last change was not within one dispatch of 5.18.90's retry job (last changed: $(utc $(( SCHED0 + 5 ))); master's last dispatch: $(utc $(( SCHED0 - 7200 ))))")$(live)$(data_digest)" "yes${BASE}$D0" \
  "1z2 the folder's last change two hours after master's last run of the job: something else moved it — A14 says not seen (the window has both bounds)"
set_sched "$SCHED0" -1; fresh; OUT="$(run --answer NO)"; set_sched "$SCHED0"
check "$(has "$OUT" "master last dispatched the retry job $(utc "$SCHED0") (still running); its migration.log last written")$(has "$OUT" "ok    A14 the stray folder last changed at $(utc $(( SCHED0 + 5 ))), within one dispatch of master's last dispatch of 5.18.90's retry job ($(utc "$SCHED0") (still running)) — consistent with that job opening the stray store")$(live)$(data_digest)" "yesyes${BASE}$D0" \
  "1z3 master's record of a run still under way (duration -1): A14 says so on one line, and the folder's change falls within that dispatch — 'seen'"
# The plugin's data directory is never <plugin>/data, whatever names it (the tenth review): ucrm.json naming that folder.
pdd_stray() { php -r '$f = $argv[1]; $u = json_decode((string)file_get_contents($f), true); $u["pluginDataDir"] = $argv[2]; file_put_contents($f, json_encode($u));' "$PD/ucrm.json" "$IN_CONTAINER/data"; }
cp -p "$PD/ucrm.json" "$SB/ucrm.kept"; pdd_stray; fresh; OUT="$(run --answer DEPLOY)"; cp -p "$SB/ucrm.kept" "$PD/ucrm.json"
nogo "1aa ucrm.json naming <plugin>/data as the plugin's data directory: NO-GO before anything is read — the stray store is never opened as if it were the live one (the tenth review)" \
  "STOP: NO-GO: the plugin's data directory would be $IN_CONTAINER/data — the stray store under the plugin folder, which this release must never open" "$OUT"
# Files git does not track under the checkout's plugin folder (the tenth review): deploy-hybrid.sh copies the working
# tree, so an untracked or ignored file there would be installed unseen — refused, naming it. One under data/ is never
# copied, and is not counted.
printf '<?php // rehearsal\n' > "$REPO/$P/lib/RehearsalUntracked.php"; fresh; OUT="$(run --answer DEPLOY)"; rm -f "$REPO/$P/lib/RehearsalUntracked.php"
nogo "1ab a file git does not track under the checkout's plugin folder: NO-GO, naming it — deploy-hybrid.sh would have installed it unseen" \
  "STOP: NO-GO: the checkout holds 1 file(s) git does not track under $P, outside data/ ($P/lib/RehearsalUntracked.php): scripts/deploy-hybrid.sh copies the working tree, so they would be installed unseen" "$OUT"
check "$(git -C "$REPO" check-ignore -q "$P/lib/rehearsal.bak" && echo ignored || echo not)" "ignored" "control: the repository's ignore list covers a *.bak under the plugin folder"
printf 'x' > "$REPO/$P/lib/rehearsal.bak"; fresh; OUT="$(run --answer DEPLOY)"; rm -f "$REPO/$P/lib/rehearsal.bak"
nogo "1ab2 an IGNORED file (*.bak) there: NO-GO as well — git's ignore list does not stop deploy-hybrid.sh copying it" \
  "STOP: NO-GO: the checkout holds 1 file(s) git does not track under $P, outside data/ ($P/lib/rehearsal.bak)" "$OUT"
printf 'x' > "$REPO/$P/data/rehearsal.txt"; fresh; OUT="$(run --answer NO)"; rm -f "$REPO/$P/data/rehearsal.txt"
check "$(has "$OUT" "not tracked     0 file(s) under $P, outside data/")$(has "$OUT" 'STOP: NO-GO: the checkout holds')$(has "$OUT" 'STOP: not confirmed')$(live)" "yesnoyes$BASE" \
  "1ab3 control: a file under the checkout's data/ — which deploy-hybrid.sh never copies — is not counted: on to the prompt (answered NO)"
# A copy that stopped part-way (the tenth review): the container's record still 5.18.90's, the manifest already 5.18.91's.
cp -p "$PD/manifest.json" "$SB/manifest.kept2"; git -C "$REPO" show "$PIN:$P/manifest.json" > "$PD/manifest.json"
fresh; OUT="$(run --answer DEPLOY)"
nogo "1ac the record says $BASE, the installed manifest 5.18.91: a copy that did not complete — NO-GO, naming the by-hand put-back" \
  "STOP: NO-GO: the container's record says $BASE, but the installed manifest says 5.18.91: a copy of 5.18.91 that did not complete (a deploy that stopped part-way). Nothing was changed. Put 5.18.90 back by hand first, its own command: cd $REPO && git checkout $BASE && { bash scripts/deploy-hybrid.sh; git checkout -; }" "$OUT"
fresh; OUT="$(run --answer ROLLBACK --rollback)"
nogo "1ac2 …and --rollback there refuses, instead of finding nothing to roll back, naming the file and the same put-back" \
  "STOP: the container's record says $BASE (5.18.90), but these installed files are not 5.18.90's: manifest.json — a copy that did not complete (a deploy that stopped part-way), or an edit by hand. This command rolls back only a deploy the record shows, so it changes nothing here. Put 5.18.90 back by hand, its own command: cd $REPO && git checkout $BASE && { bash scripts/deploy-hybrid.sh; git checkout -; }" "$OUT"
cp -p "$SB/manifest.kept2" "$PD/manifest.json"
# The same with the manifest still 5.18.90's — a copy that stopped before it (the eleventh review): the installed files
# this release changes are read, not the manifest alone.
DAPP="tests/test_distributor_apply.php"; cp -p "$PD/$DAPP" "$SB/dapp.kept"; git -C "$REPO" show "$PIN:$P/$DAPP" > "$PD/$DAPP"
check "$(inst_ver)$(cmp -s "$PD/$DAPP" "$SB/dapp.kept" && echo same || echo differs)" "5.18.90differs" "control: the manifest still 5.18.90's, one file this release changes already 5.18.91's"
fresh; OUT="$(run --answer DEPLOY)"
nogo "1ac3 …a copy that stopped before manifest.json: the deploy refuses, naming the file, and the put-back (the eleventh review)" \
  "STOP: NO-GO: the container's record says $BASE (5.18.90), but these installed files are not 5.18.90's: $DAPP — a copy that did not complete (a deploy that stopped part-way), or an edit by hand. Nothing was changed. Put 5.18.90 back by hand first, its own command: cd $REPO && git checkout $BASE && { bash scripts/deploy-hybrid.sh; git checkout -; }" "$OUT"
fresh; OUT="$(run --answer ROLLBACK --rollback)"
nogo "1ac4 …and so does --rollback (the eleventh review)" \
  "STOP: the container's record says $BASE (5.18.90), but these installed files are not 5.18.90's: $DAPP — a copy that did not complete" "$OUT"
cp -p "$SB/dapp.kept" "$PD/$DAPP"
# …and a real one: one file of the copy fails — a folder stands where the release adds its library — so deploy-hybrid.sh
# stops before it writes the record. The deploy says the copy did not complete and names the by-hand put-back, which the
# rehearsal then runs exactly as printed.
mkdir -p "$PD/$SLR_LIB"; touch "$PD/$SLR_LIB/rehearsal-blocker"
fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "note  deploy-hybrid.sh stopped before it wrote the container's record (")$(has "$OUT" 'the container was still restarting')$(has "$OUT" "STOP: deploy did not verify (rc=2): the container's record still names $BASE (5.18.90), so the copy itself did not complete")$(has "$OUT" "Put 5.18.90 back by hand, its own command: cd $REPO && git checkout $BASE && { bash scripts/deploy-hybrid.sh; git checkout -; } — then send")$(live)$(data_digest)$(stray_snap)" "yesnoyesyes${BASE}$D0$STRAY0" \
  "1ad one file of the copy fails: deploy-hybrid.sh stops before the record — the deploy says so at once, with no wait on the container, and names the by-hand put-back, not --rollback; no data and not the stray store changed"
rm -rf "$PD/$SLR_LIB"
CMD="$(grep -oF -- "cd $REPO && git checkout $BASE && { bash scripts/deploy-hybrid.sh; git checkout -; }" <<<"$OUT" | head -1)"
HAND="$(env PATH="$SB/bin:$PATH" bash -c "$CMD" 2>&1)"; HAND_RC=$?
check "$( [ -n "$CMD" ] && echo printed || echo missing)|$HAND_RC|$(live)$(installed_digest "$BASE")$(inst_ver)$(sha256sum "$PD/$JOB" | cut -c1-64)$(test -e "$PD/$SLR_LIB" && echo lib || echo nolib)|$(git -C "$REPO" symbolic-ref -q --short HEAD)$(git -C "$REPO" status --porcelain --untracked-files=no | wc -l | tr -d ' ')" "printed|0|${BASE}05.18.90${JOB90_SHA}nolib|${BRANCH}0" \
  "1ad2 the put-back, taken from the log and run exactly as printed: 5.18.90 served again, every file 5.18.90 has as it has it, the checkout back on its branch (a file this release adds, if the copy reached it, stays on disk, reached by nothing — as after a rollback)"
# The put-back when deploy-hybrid.sh itself exits non-zero (the container not answering in time): the checkout still goes
# back to its branch (the eleventh review).
CMD_FAIL="$(printf '%s' "$CMD" | sed 's/bash scripts\/deploy-hybrid.sh/false/')"
HAND="$(bash -c "$CMD_FAIL" 2>&1)"; HAND_RC=$?
check "$( [ "$CMD_FAIL" != "$CMD" ] && echo edited || echo same)|$(git -C "$REPO" symbolic-ref -q --short HEAD)$(git -C "$REPO" status --porcelain --untracked-files=no | wc -l | tr -d ' ')" "edited|${BRANCH}0" \
  "1ad3 …and with its copy step failing, the put-back still returns the checkout to its branch"
check "$(data_digest)$(stray_snap)$(host_code_left)$(left_tmp)$(evo_calls)" "${D0}${STRAY0}0${TMP0}0" "control: the data and the stray store as seeded; every run removed its copies of the code — on the host and in the container's /tmp; Evolution never called"

echo; echo "== 2. weakened copies of stage A are caught (the control on the control) =="
S="$(pinned_copy inv "$V_INV")"; M="$(mutant a13 "$S" '  [ -z "$A13_BAD" ] || stop' '  [ -z "$A13_BAD" ] || true')"; rm -f "$S"
fresh; OUT="$(run --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" "STOP: NO-GO: the pin's retry job")$(has "$OUT" 'STOP: not confirmed')$(live)$(backups)" "noyes${BASE}1" "2a the A13-blinded copy goes on with the inverted gate, to the prompt (answered NO) — detected"
S="$(pinned_copy noskip "$V_NOSKIP")"; M="$(mutant a12 "$S" '  [ "${SLRS_A%% | *}" = "$SLR_SERVER_SIGNATURE" ] || stop' '  [ "${SLRS_A%% | *}" = "$SLR_SERVER_SIGNATURE" ] || true')"; rm -f "$S"
fresh; OUT="$(run --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" "STOP: NO-GO: on this server's own configuration the pin's gate")$(has "$OUT" "STOP: NO-GO: the pin's retry job does not stop on Uganda, does not keep South Sudan exactly as it is, or costs master its lock on the live database — or the check could not be made: uganda-not-stopped")$(live)" "noyes$BASE" \
  "2b the A12-blinded copy, with the library that never says skip, is no longer stopped at A12 — detected; A13, the second layer, stops it before anything changes"
S="$(pinned_copy noskip2 "$V_NOSKIP")"; M="$(mutant a12a13 "$S" '  [ "${SLRS_A%% | *}" = "$SLR_SERVER_SIGNATURE" ] || stop' '  [ "${SLRS_A%% | *}" = "$SLR_SERVER_SIGNATURE" ] || true' '  [ -z "$A13_BAD" ] || stop' '  [ -z "$A13_BAD" ] || true')"; rm -f "$S"
fresh; OUT="$(run --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" "STOP: NO-GO: on this server's own configuration the pin's gate")$(has "$OUT" "STOP: NO-GO: the pin's retry job")$(has "$OUT" 'STOP: not confirmed')$(live)" "nonoyes$BASE" \
  "2c blinded at A12 and at A13, the copy goes on to the DEPLOY prompt (answered NO) — the two layers are independent"
cfg_keep; cfg_set multi_number_channels_enabled on
M="$(mutant a5 "$DEPLOY" '  if ! sw_off "$MN_BEFORE"; then' '  if false; then')"
fresh; OUT="$(run --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" 'STOP: NO-GO: multi_number_channels_enabled reads')$(has "$OUT" "STOP: NO-GO: on this server's own configuration the pin's automated-send policy is not inert with the registry off")$(live)$(backups)" "noyes${BASE}0" \
  "2d the A5-blinded copy is no longer stopped at A5 with the registry's switch ON — detected; A11 stops it"
cfg_back
S="$(pinned_copy dbv "$V_DB")"; M="$(mutant a7 "$S" '[ -z "$DB_BAD" ] || stop' '[ -z "$DB_BAD" ] || true')"; rm -f "$S"
fresh; OUT="$(run --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" 'NO-GO (Domain B)')$(has "$OUT" "STOP: the pin carries files that are not 5.18.91's, which this release must not ship: dishnet-mikrotik-control-plane/REHEARSAL.md(added)")" "noyes" \
  "2e the A7-blinded copy no longer refuses the Domain B pin as Domain B (the allow-list still stops it) — detected"
S="$(pinned_copy migv "$V_MIG")"; M="$(mutant a0m "$S" '[ -z "$STRAY" ] || stop' '[ -z "$STRAY" ] || true')"; rm -f "$S"
fresh; OUT="$(run --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" "STOP: the pin carries 1 migration(s) (088_rehearsal.sql); 5.18.91 adds none — this script is for another build")$(live)$(backups)" "yes${BASE}0" \
  "2f with the allow-list blinded, a pin carrying a migration is stopped by the migration count"
check "$(data_digest)$(stray_snap)$(host_code_left)$(left_tmp)$(evo_calls)" "${D0}${STRAY0}0${TMP0}0" "control: the data and the stray store as seeded, no code copy left anywhere, Evolution never called"

# The two refusals the tenth review added, each weakened: the copy without them goes on.
cp -p "$PD/ucrm.json" "$SB/ucrm.kept"; pdd_stray; stray_keep
M="$(mutant pddguard "$DEPLOY" '[ "${PDD_IN%/}" = "$IN_CONTAINER/data" ] && stop' '[ "${PDD_IN%/}" = "$IN_CONTAINER/data" ] && true')"
fresh; OUT="$(run --answer NO --script "$M")"; rm -f "$M"; cp -p "$SB/ucrm.kept" "$PD/ucrm.json"
check "$(has "$OUT" "the plugin's data directory would be")$( [ "$(stray_snap)" = "$STRAY0" ] && echo untouched || echo touched)" "notouched" \
  "2x the copy without the guard takes <plugin>/data for the live store and reads it — the stray store opened, as the real script never does — detected"
stray_back
check "$(stray_snap)$(data_digest)" "$STRAY0$D0" "…the stray folder put back exactly, its own time included; no data changed"
printf '<?php // rehearsal\n' > "$REPO/$P/lib/RehearsalUntracked.php"
M="$(mutant untracked "$DEPLOY" 'if [ "${UNTRACKED_N:-0}" != "0" ]; then' 'if false; then')"
fresh; OUT="$(run --answer NO --script "$M")"; rm -f "$M" "$REPO/$P/lib/RehearsalUntracked.php"
check "$(has "$OUT" 'STOP: NO-GO: the checkout holds')$(has "$OUT" "not tracked     1 file(s) under $P, outside data/: $P/lib/RehearsalUntracked.php")$(has "$OUT" 'STOP: not confirmed')$(live)" "noyesyes$BASE" \
  "2y the copy without that refusal goes on to the DEPLOY prompt (answered NO) with the untracked file counted — detected"

DAPP="tests/test_distributor_apply.php"; cp -p "$PD/$DAPP" "$SB/dapp.kept"; git -C "$REPO" show "$PIN:$P/$DAPP" > "$PD/$DAPP"
M="$(mutant basediff "$DEPLOY" '  if [ -n "$BASE_DIFFERS" ]; then' '  if false; then')"
fresh; OUT="$(run --answer NO --script "$M")"; rm -f "$M"; cp -p "$SB/dapp.kept" "$PD/$DAPP"
check "$(has "$OUT" "these installed files are not 5.18.90's")$(has "$OUT" 'STOP: not confirmed')$(live)" "noyes$BASE" \
  "2z the copy without the installed-file check goes on to the DEPLOY prompt (answered NO) over a file a part-way copy replaced — detected"

echo; echo "== 3. the deploy, as the operator runs it =="
D1="$(data_digest)"; EV1="$(sq "SELECT group_concat(id || ':' || event_type || ':' || status, ',') FROM events")"
STRAY_LINE="$(stray_line)"; LOGT="$(stat -c %Y "$PD/data/migration.log")"
fresh; OUT="$(run --answer DEPLOY)"
echo "  …    the deploy's own tally:$(grep -E '^  checks +[0-9]+ ok, ' <<<"$OUT" | sed -E 's/^  checks +/ /')"
check "$(fails "$OUT")$(notes "$OUT")" "01" "no FAIL line and one note — R19's"
check "$(has "$OUT" '5.18.91 (deploy): PASSED')" "yes" "PASSED"
for l in "ok    A0 the release delta is exactly 5.18.91's $N_CH files — $N_AD added, $N_MO changed, no migration (087 the reviewed one, sha256 ${MIG_SHA:0:16}…, unchanged); no partner-portal, CSRF or AI media-layer file" \
         "ok    A7 Domain B untouched" \
         "migrations      0 added since 5.18.90 — 5.18.91 adds none; 087 (5.18.88's) stays as it is" \
         "release code    $PIN (5.18.91) and $BASE (5.18.90), copied into the container's /tmp for the comparisons below — removed when this run ends" \
         "ok    A3 the settings row the Inbox reads still reaches Evolution" \
         "ok    A4 the lead switches are as the operator decided on 07 Oct" \
         "ok    A5 the channel registry's switch reads OFF in both copies (mn=absent/absent; files/store) — the registry stays dark" \
         "ok    A5b sales_own_leads_only reads OFF in both copies" \
         "ok    A8 migration 087 (5.18.88's) is applied and complete on this server — 2 tables, 3 indexes, 3 triggers, both CHECKs, the ledger row matching the installed file — holding the three department rows exactly as it seeds them, no instance stored and no number, and 1 salesperson number(s) added through the card, none switched on, 0 with the assistant on; 5.18.91 adds no migration" \
         "ok    A9 the pin's code routes the three numbers exactly as the live code does" \
         "ok    A10 D3 the pin's assistant builds exactly 5.18.90's prompt" \
         "ok    A11 the pin's automated-send policy is inert on this server's own configuration" \
         "ok    A2 PHP" \
         "ok    A6 South Sudan stays exactly as it is" \
         "stray store     $STRAY_LINE   (<plugin>/data/plugin.sqlite3 as checksum:size:time" \
         "retry gate      pin  5.18.91: $SLR_SERVER | by-store=uganda by-files+vault=uganda" \
         "ok    A12 on this server's own configuration the pin's gate stops the Starlink retry job before it touches <plugin>/data: the live directory is the one this script found, outside the plugin folder; the live store read as master holds it; Uganda; skip (by-store=uganda by-files+vault=uganda)" \
         "retry job       live 5.18.90, Uganda:      $SLR_OPENS | locks=kept" \
         "                  pin  5.18.91, Uganda:      $SLR_STOPS | locks=kept" \
         "                  live 5.18.90, South Sudan: $SLR_OPENS | locks=kept" \
         "                  pin  5.18.91, South Sudan: $SLR_OPENS | locks=kept" \
         "ok    A13 the pin's retry job, inside master's scope on throwaway layouts shaped like this server: on Uganda it leaves \$dataDir on the live directory, no variable behind and the stray folder untouched; on South Sudan it does exactly what the live 5.18.90 job does — the same variables, the stray store opened and migrated — as the live job does on Uganda too, the control; master's locks on the live database and its -shm kept throughout (/proc/locks)" \
         "ok    A14 the stray folder last changed at $(utc $(( SCHED0 + 5 ))), within one dispatch of master's last dispatch of 5.18.90's retry job ($(utc "$SCHED0")) — consistent with that job opening the stray store, whose -wal and -shm are made and removed there: R19 reads the same folder time" \
         "master last dispatched the retry job $(utc "$SCHED0"); its migration.log last written $(utc "$LOGT") — other jobs write that log too, so it is reported, not judged" \
         "locks control   held=" \
         "— a SQLite store held open on this host shows in /proc/locks, and not once closed" \
         "stray store     at the copy: $STRAY_LINE   locks: 0" \
         "ok    copied the stray store's folder (<plugin>/data) → " \
         "— as files; the folder itself stays exactly where it is" \
         "GO — evidence recorded" \
         "checking out $PIN (5.18.91, the release commit) for the documented deploy" \
         "ok    container serves $PIN" \
         "ok    V3f the channel registry's switch is unchanged by this run and OFF in both copies (mn=absent/absent; files/store) — the registry stays dark" \
         "ok    V4 no fatal or parse error of $P in the container log since" \
         "ok    R1 all $N_CH files 5.18.91 changes are installed exactly as $PIN has them ($N_AD new)" \
         "ok    R1 the installed manifest says 5.18.91" \
         "ok    R2c the installed automated-send policy is inert on this server's own configuration" \
         "ok    R3 migration 087 is applied and complete" \
         "ok    R5 all" \
         "and 5.18.90's safety fix is in place: the policy and the internal numbers, inert with the registry off" \
         "and 5.18.91's gate is in place: the retry job asks StarlinkRetryScope first, in a closure of its own" \
         "ok    R11 S4/S5 the registry is dark" \
         "ok    R12 S1–S3 the three numbers route exactly as on 5.18.90" \
         "ok    R13 S8 the installed event processor, on throwaway databases" \
         "ok    R14 Domain B is installed exactly as $PIN has it" \
         "ok    R16 D3 the installed assistant builds exactly 5.18.90's prompt" \
         "ok    R17 the installed automated-send policy, on a throwaway database" \
         "ok    R18 on this server's own configuration the installed gate stops the Starlink retry job before it touches <plugin>/data: the live directory outside the plugin folder, the live store read, Uganda, skip (by-store=uganda by-files+vault=uganda)" \
         "ok    R18 the installed retry job, on throwaway layouts: on Uganda it leaves \$dataDir on the live directory, no variable behind, the stray folder untouched ($SLR_STOPS | locks=kept); on South Sudan it is 5.18.90's ($SLR_OPENS | locks=kept)" \
         "stray store     before: $STRAY_LINE" \
         "                  copy:   $STRAY_LINE   locks: 0" \
         "                  now:    $STRAY_LINE   locks: 0" \
         "master's last run of it $(utc "$SCHED0"); the stray folder last changed $(utc $(( SCHED0 + 5 ))); its migration.log last written $(utc "$LOGT") (reported, not judged: other jobs write it too)" \
         "ok    R19 the stray store under the plugin folder is exactly as before the deploy — the same checksum, size and time (${STRAY_LINE%% *}): not moved, not copied over, not written" \
         "note  R19 the stray folder has not changed since the copy (the folder's time then: $(utc $(( SCHED0 + 5 ))); side files then: -), but master's record shows no completed run of the retry job since the fix was installed at" \
         "(its last: $(utc "$SCHED0")) — not yet evidence: run --after-only after" \
         "what did not      the stray store itself — not moved, renamed, opened or written by this run; its database file unchanged, and whether anything has opened it since the fix: not yet evidence (R19's note)" \
         "what changed      on Uganda the Starlink retry job stops at its first statement" \
         "check it          at least 20 minutes after the copy" \
         "migrations        none — 087 (5.18.88's) complete before and after (A8, R3); 086 still complete"; do
  check "$(has "$OUT" "$l")" "yes" "3: ${l:0:100}"
done
check "$(cnt "$OUT" 'ok    backed up ')$(cnt "$OUT" 'ok    snapshot of the event queue')$(cnt "$OUT" "ok    copied the stray store's folder")" "411" "four backups, the event queue's snapshot and the stray store's folder copied"
check "$(grep -cE -- "$LEAK_RX|$NUM_RX" <<<"$OUT")" "0" "the log carries no Evolution address, key or instance name, and no number"
check "$(live)$(installed_digest "$PIN")$(inst_ver)" "${PIN}05.18.91" "the container serves $PIN: every changed file exactly as the commit has it, manifest 5.18.91"
check "$(data_digest)" "$D1" "the deploy wrote no record and no configuration value — every table, the vault, the configuration files and the photos as before"
check "$(sq "SELECT group_concat(id || ':' || event_type || ':' || status, ',') FROM events")" "$EV1" "…the event queue exactly as before: the deploy processed nothing"
check "$(stray_snap)" "$STRAY0" "the stray store's folder is exactly as before — every file's bytes and time: the deploy neither copied into it nor opened it"
STATE="$SB/out/state-5.18.91.env"; DA="$(sed -n 's/^DEPLOYED_AT=//p' "$STATE")"
check "$(grep -c "^STRAY_BEFORE=$STRAY_LINE\$" "$STATE")$(grep -c '^STRAY_CTRL=seen$' "$STATE")$(grep -cE '^STRAY_ENTS=[0-9]+:[^/]+(/[0-9]+:[^/]+)+$' "$STATE")$(grep -c "^STRAY_COPY=$STRAY_LINE\$" "$STATE")$(grep -c '^STRAY_COPY_LOCKS=0$' "$STATE")$(grep -c "^STRAY_COPY_AT=$(job_t)\$" "$STATE")$(grep -c '^STRAY_QUIET_FROM=' "$STATE")$(grep -cE '^DEPLOYED_AT=[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$' "$STATE")" "11111101" "the state file records the stray store as found, A14's 'seen', the folder's entries, the stray store as the copy left it and the locks on it (no side files, none held: nothing had it open), the copy's time — the one it gave the retry job — no reference moment yet, and when the deploy started"
BK="$(ls -d "$SB/out"/backup-* | tail -1)"
check "$(tar -tzf "$BK/stray-data.tar.gz" | grep -cE '^data/(plugin\.sqlite3|migration\.log|config\.json)$')$(tar -xzOf "$BK/stray-data.tar.gz" data/plugin.sqlite3 | sha256sum | cut -c1-64)" "3$(sha256sum "$PD/data/plugin.sqlite3" | cut -c1-64)" \
  "the backup holds the stray store's folder — its database byte for byte, its log and its settings file"
check "$(tar -xzOf "$BK"/plugin-installed-5.18.90.tar.gz $P/manifest.json | grep -c '"version": "5.18.90"')$(tar -tzf "$BK"/plugin-installed-5.18.90.tar.gz | grep -c "^$P/data/plugin.sqlite3")" "10" "the code backup is 5.18.90, without the plugin's data/ — the stray store is in its own copy"
check "$(host_code_left)$(left_tmp)" "0$TMP0" "the run removed its copies of the two releases' code and every throwaway layout — on the host and in the container's /tmp"
check "$(grep -c "cd $REPO && bash scripts/deploy-5.18.91.sh --rollback" <<<"$OUT")" "1" "the rollback command is printed once, on its own line, never beside the deploy"
check "$(awk '/PASSED\. Send this LOG FILE back/ {p=1} p && /deploy-5\.18\.91\.sh --rollback/ {print "after"; exit}' <<<"$OUT")" "after" "…after the verdict, at the end of the log"
check "$(evo_calls)" "0" "Evolution was never called — not by the deploy, its checks or the installed code it ran"
OUT="$(run --after-only)"
check "$(fails "$OUT")$(notes "$OUT")$(has "$OUT" '5.18.91 (after): PASSED')$(data_digest)$(stray_snap)" "01yes$D1$STRAY0" "--after-only right after: PASSES with R19's note, nothing written"

# Every R19 failure is recorded in the state file and fails every later run (the seventh review). After each failing
# scenario the rehearsal checks that it was recorded, once, and removes it, so that the next scenario starts clean; what
# the record does is rehearsed by itself (4w2b, with its weakened copy 4w2c).
r19_failed_kept() {   # r19_failed_kept LABEL — the failure just seen is recorded once; the record is then removed
  check "$(grep -c '^STRAY_R19_FAILED=[0-9]*$' "$STATE")" "1" "…$1: the failure is recorded in the state file (STRAY_R19_FAILED), once"
  sed -i '/^STRAY_R19_FAILED=/d' "$STATE"
}
r19_unfail() { sed -i '/^STRAY_R19_FAILED=/d' "$STATE"; }   # after a weakened copy's failing run
ro_open() {   # a read-only open of the stray store, as a reader with SQLITE_OPEN_READONLY makes one: it makes the -wal and
  #             -shm when they are missing, and leaves them when it closes
  php -r '$p = new PDO("sqlite:" . $argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]); $p->query("SELECT count(*) FROM sqlite_master")->fetchColumn(); $p = null;' "$PD/data/plugin.sqlite3"
}

echo; echo "== 4. R19 over time: the stray store, conclusive with --after-only once master has run the job =="
job_back 30   # the copy 30 minutes ago, as --after-only would find it
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" "note  R19 the stray folder has not changed since the copy")$(has "$OUT" "(its last: $(utc "$SCHED0"))")" "0yesyes" \
  "4a 30 minutes on, master's last record of the job still from before the deploy: a note — a quiet folder alone is not evidence"
set_sched $(( $(job_t) - 60 ))
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" "but master's record shows no completed run of the retry job since the fix was installed at $(utc "$(job_t)") (its last: $(utc $(( $(job_t) - 60 ))))")" "0yes" \
  "4b master's last run of the retry job BEFORE the copy: still a note, naming the run"
set_sched $(( $(date -u +%s) - 300 ))
OUT="$(run --after-only)"
check "$(fails "$OUT")$(notes "$OUT")$(has "$OUT" 'ok    R19 the retry job ran at')$(has "$OUT" "with the fix installed")$(has "$OUT" "nothing held the store at the copy, and before the deploy its last change fell within one dispatch of the job's (A14)")$(has "$OUT" 'nothing has opened the stray store since')$(has "$OUT" '5.18.91 (after): PASSED')" "00yesyesyesyesyes" \
  "4c master completed a run of the retry job after the copy, the stray folder stayed quiet, and A14 recorded 'seen' before the deploy: R19 PASSES — the evidence the quarantine waits for"
check "$(has "$OUT" "whether anything has opened it since the fix: nothing has opened it since the copy (the folder's time then:")" "yes" "…4c's summary says what R19 established, in its words: nothing has opened it since the copy (the tenth review)"
check "$(stray_snap)" "$STRAY0" "…and that run read the schedule without changing the stray store"
# The store changing while R19 reads it, and its folder's time unreadable (the tenth review): an instrumented copy makes
# each — a side file made between R19's two reads; the folder's time read as nothing — and the branch that answers it is
# the script's own. Each is a note, never a pass; each branch weakened, a pass — detected.
UNSTEADY_OLD='  l1="$(stray_locks)"; STRAY_NOW="$(stray_state)"; l2="$(stray_locks)"; s2="$(stray_state)"'
UNSTEADY_NEW='  l1="$(stray_locks)"; STRAY_NOW="$(stray_state)"; l2="$(stray_locks)"; touch "$DEST/data/plugin.sqlite3-journal"; s2="$(stray_state)"'
NODIR_OLD='  echo "db=$db side=${side:--} log=$lg dir=$(stat -c %Y "$d" 2>/dev/null || echo 0)"'
NODIR_NEW='  echo "db=$db side=${side:--} log=$lg dir=0"'
stray_keep; M="$(mutant unsteady "$DEPLOY" "$UNSTEADY_OLD" "$UNSTEADY_NEW")"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"; stray_back
check "$(has "$OUT" 'note  R19 the stray store changed while R19 read it')$(has "$OUT" 'nothing has opened the stray store since')$(fails "$OUT")" "yesno0" \
  "4c3 a side file made between R19's two reads of the store: a note — something has it open or is closing it now — never a pass (the tenth review)"
stray_keep; M="$(mutant unsteadyw "$DEPLOY" "$UNSTEADY_OLD" "$UNSTEADY_NEW" 'elif [ "$STRAY_NOW_STEADY" != "yes" ]; then note "R19 the stray store changed while R19 read it' 'elif false; then note "R19 the stray store changed while R19 read it')"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"; stray_back
check "$(has "$OUT" 'R19 the stray store changed while R19 read it')$(has "$OUT" 'nothing has opened the stray store since')" "noyes" "4c3m the copy that does not ask whether the store was steady passes on the first read — detected"
M="$(mutant nodir "$DEPLOY" "$NODIR_OLD" "$NODIR_NEW")"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" "note  R19 the stray folder's time could not be read")$(has "$OUT" 'nothing has opened the stray store since')$(fails "$OUT")" "yesno0" \
  "4c4 the stray folder's time unreadable: a note — R19 cannot say whether anything opened the store — never a pass (the tenth review)"
M="$(mutant nodirw "$DEPLOY" "$NODIR_OLD" "$NODIR_NEW" "elif case \"\$SLR_DIR_T\" in ''|*[!0-9]*|0) true;; *) false;; esac; then note \"R19 the stray folder's time could not be read" "elif false; then note \"R19 the stray folder's time could not be read")"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" "R19 the stray folder's time could not be read")$(has "$OUT" 'nothing has opened the stray store since')" "noyes" "4c4m the copy that does not ask whether the folder's time was read passes — detected"
check "$(stray_snap)" "$STRAY0" "…the stray folder exactly as before 4c3, its own time included"
RUNNING_T=$(( $(date -u +%s) - 20 )); set_sched "$RUNNING_T" -1
OUT="$(run --after-only)"
check "$(has "$OUT" "but master's record shows no completed run of the retry job since the fix was installed at $(utc "$(job_t)") (its last: $(utc "$RUNNING_T") (still running))")$(has "$OUT" "master's last run of it $(utc "$RUNNING_T") (still running); the stray folder last changed")$(has "$OUT" 'nothing has opened the stray store since')$(fails "$OUT")" "yesyesno0" \
  "4c2 a run of the retry job still under way (duration -1, as master writes it before the job): not counted — a note, never a pass"
set_sched $(( $(date -u +%s) - 300 ))
# Another job writes the stray folder's migration.log after the copy — MigrationRunner's default log, as cron/dpo_reconcile.php
# does on the server every 5 minutes. That is no open of the stray store: R19 reports the log and still passes.
stray_keep; DPO_OUT="$(dpo_writer)"
OUT="$(run --after-only)"
check "$DPO_OUT|$(tail -1 "$PD/data/migration.log" | grep -c 'WARNING: 071_dpo_payments.sql has been modified after being applied')|$(fails "$OUT")$(has "$OUT" 'nothing has opened the stray store since')$(has "$OUT" "its migration.log last written $(utc "$(stat -c %Y "$PD/data/migration.log")") (reported, not judged: other jobs write it too)")$(( $(stat -c %Y "$PD/data/migration.log") > LOGT ))" "done|1|0yesyes1" \
  "4d the stray folder's migration.log written AFTER the copy by another job (MigrationRunner's default log, as dpo_reconcile writes it): reported, not judged — R19 still passes"
stray_back
check "$(stray_snap)" "$STRAY0" "…the stray folder put back exactly, its own time included"
# A REAL open after the copy: 5.18.90's own retry job, put in the plugin folder under another name and run once — it opens
# <plugin>/data exactly as it does on the server. R19 must see it.
stray_keep; PF0="$(plugin_files)"
git -C "$REPO" show "$BASE:$P/$JOB" > "$PD/.rh-job90.php"
( cd "$PD" && env -u DN_DATA_DIR php "$PD/.rh-job90.php" >/dev/null 2>&1 ); rm -f "$PD/.rh-job90.php"
check "$(plugin_files)" "$PF0" "control: running 5.18.90's job changed nothing in the plugin folder outside its data/"
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R19 the stray folder changed at")$(has "$OUT" 'as an open of the stray store does with its -wal and -shm. Something still opens the stray store. Do not quarantine it')$(has "$OUT" 'ok    R19 the stray store under the plugin folder is exactly as before')$(has "$OUT" 'nothing has opened the stray store since')$(has "$OUT" '5.18.91 (after): PASSED')" "yesyesyesnono" \
  "4m a REAL open of the stray store after the copy — 5.18.90's own job, run once: the database file unchanged and the log as it was, as on the server, but the folder changed — R19 FAILS: something still opens it; do not quarantine"
check "$(has "$OUT" "its database file unchanged, and whether anything has opened it since the fix: R19 FAILED — do not quarantine it")" "yes" "…4m's summary says the file unchanged and R19 FAILED (the tenth review)"
r19_failed_kept 4m
M="$(mutant r19 "$DEPLOY" '  if [ "$SLR_DIR_T" -gt "$SLR_REF_T" ]; then' '  if false; then')"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" 'FAIL  R19')$(has "$OUT" 'nothing has opened the stray store since')" "noyes" "4e the copy blind to the folder's time passes the same real open — detected"
stray_back
check "$(stray_snap)" "$STRAY0" "…the stray folder put back exactly, its own time included"
stray_keep; printf '{}' > "$PD/data/rh-other-writer.json"
OUT="$(run --after-only)"
check "$(has "$OUT" "note  R19 the stray folder changed at")$(has "$OUT" "made, removed or replaced: rh-other-writer.json")$(has "$OUT" 'FAIL  R19 the stray folder')$(has "$OUT" 'nothing has opened the stray store since')" "yesyesnono" \
  "4o a file of another writer's appears in the stray folder after the copy: R19 names it and cannot conclude — a note, never a pass, never a failure of its own"
stray_back
check "$(stray_snap)" "$STRAY0" "…the stray folder put back exactly, its own time included"
cp -p "$PD/data/plugin.sqlite3" "$SB/sdb.aside"; printf 'x' >> "$PD/data/plugin.sqlite3"
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R19 the stray store under the plugin folder CHANGED since before the deploy")$(has "$OUT" 'Do not quarantine it')" "yesyes" "4f the stray store's database changed after the deploy: R19 FAILS on its checksum"
check "$(has "$OUT" "its database file CHANGED, and whether anything has opened it since the fix: R19 FAILED — do not quarantine it")" "yes" "…4f's summary says the file CHANGED and R19 FAILED (the tenth review)"
r19_failed_kept 4f
M="$(mutant r19db "$DEPLOY" 'elif [ "${STRAY_R%% *}" = "${STRAY_REF%% *}" ]; then ok "R19' 'elif true; then ok "R19')"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" 'FAIL  R19 the stray store under the plugin folder CHANGED')$(has "$OUT" 'ok    R19 the stray store under the plugin folder is exactly as before')" "noyes" "4g the copy that compares nothing passes the changed database — detected"
cp -p "$SB/sdb.aside" "$PD/data/plugin.sqlite3"
cp "$STATE" "$SB/state.kept"; sed -i 's/^STRAY_CTRL=seen$/STRAY_CTRL=not-seen/' "$STATE"
OUT="$(run --after-only)"
check "$(has "$OUT" "note  R19 master ran the retry job at")$(has "$OUT" '(A14: not-seen)')$(has "$OUT" 'nothing has opened the stray store since')$(fails "$OUT")" "yesyesno0" \
  "4j the deploy's record says the stray folder's last change was NOT within one dispatch of the job (A14): the quiet folder after it is a note, never a pass"
M="$(mutant r19ctl "$DEPLOY" 'elif [ "$STRAY_CTRL_REF" != "seen" ]; then' 'elif false; then')"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" '(A14: not-seen)')$(has "$OUT" 'nothing has opened the stray store since')" "noyes" "4k the copy without A14's control passes the same quiet folder — detected"
sed -i '/^STRAY_CTRL=/d' "$STATE"
OUT="$(run --after-only)"
check "$(has "$OUT" '(A14: no record)')$(has "$OUT" 'nothing has opened the stray store since')$(fails "$OUT")" "yesno0" "4l a state file with no A14 record: a note, never a pass"
cp "$SB/state.kept" "$STATE"; sed -i '/^STRAY_BEFORE=/d' "$STATE"
OUT="$(run --after-only)"
check "$(has "$OUT" "note  R19 no record of the stray store from before the deploy in")$(has "$OUT" 'and without that record R19 judges nothing')$(has "$OUT" 'nothing has opened the stray store since')$(fails "$OUT")" "yesyesno0" \
  "4h a state file with no record of the stray store from before the deploy: a note, and R19 judges nothing from the rest of the record — never a pass (the seventh review)"
check "$(has "$OUT" "its database file not compared (no record from before the deploy), and whether anything has opened it since the fix: not yet evidence (R19's note)")" "yes" "…4h's summary says the file was not compared (the tenth review)"
cp "$SB/state.kept" "$STATE"; sed -i '/^STRAY_COPY_AT=/d' "$STATE"
OUT="$(run --after-only)"
check "$(has "$OUT" "note  R19 there is no usable record of the copy's time in")$(has "$OUT" 'nothing has opened the stray store since')$(fails "$OUT")" "yesno0" \
  "4h2 a state file with no record of the copy's time: a note, and R19 judges nothing — never a pass, never a failure (the eighth review)"
cp "$SB/state.kept" "$STATE"; sed -i '/^STRAY_COPY=/d; /^STRAY_COPY_LOCKS=/d' "$STATE"
OUT="$(run --after-only)"
check "$(has "$OUT" "note  R19 there is no usable record of the stray store at the copy in")$(has "$OUT" 'nothing has opened the stray store since')$(fails "$OUT")" "yesno0" \
  "4h3 a state file with no record of the stray store at the copy: a note, and R19 judges nothing (the eighth review)"
cp "$SB/state.kept" "$STATE"
# The reference R19 judges from (the fourth, fifth and sixth reviews of 09 Oct): the copy, when the deploy found the stray
# store there held by no process; otherwise a moment R19 records in the state file. The state file is edited to say
# what the copy found; real processes hold, close and abandon the stray store, as 5.18.90's job does.
stray_keep; CD="$(copy_dir)"; cp "$STATE" "$SB/state.free"
held_state()  { cp "$SB/state.free" "$STATE"; sed -i -E 's/^(STRAY_COPY=.*) side=- /\1 side=-wal-shm /; s/^STRAY_COPY_LOCKS=0$/STRAY_COPY_LOCKS=2/' "$STATE"; }
stale_state() { cp "$SB/state.free" "$STATE"; sed -i -E 's/^(STRAY_COPY=.*) side=- /\1 side=-wal-shm /' "$STATE"; }
check "$CD|$(grep -c '^STRAY_COPY_LOCKS=0$' "$STATE")$(grep -c '^STRAY_QUIET_FROM=' "$STATE")" "$(( SCHED0 + 5 ))|10" "control: the deploy recorded the folder's time at the copy, no lock on the store, and no reference moment"
# 1. Nothing held the store at the copy: the folder must be quiet since the copy itself — a change before master's last
#    completed run is an open all the same (the fifth review: a reference that moves with every run would miss it).
holder_start; holder_end; touch -d "@$(( $(job_t) + 300 ))" "$PD/data"; set_sched $(( $(job_t) + 900 ))
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R19 the stray folder changed at $(utc $(( $(job_t) + 300 ))), after the copy (the folder's time then: $(utc "$CD"); side files then: -)")$(has "$OUT" 'Something still opens the stray store')$(has "$OUT" 'nothing has opened the stray store since')" "yesyesno" \
  "4p nothing held at the copy, an open and close after it, then master's next completed run: R19 FAILS — the copy is the reference, not the latest run"
r19_failed_kept 4p
M="$(mutant r19runref "$DEPLOY" 'SLR_REF_ORIGIN="copy"; SLR_REF_T="$SLR_COPY_DIR"' 'SLR_REF_ORIGIN="copy"; SLR_REF_T=$((SLR_RUN_T - 1))')"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" 'FAIL  R19 the stray folder changed')$(has "$OUT" 'nothing has opened the stray store since')" "noyes" "4q the copy that judges from master's latest run passes the same open — detected"
touch -d "$(cat "$SB/strayk.t")" "$PD/data"
# 2. A process held the store at the copy (side files there, with locks): its close changes the folder whenever it ends.
held_state
check "$(grep -cE '^STRAY_COPY=db=[^ ]+ side=-wal-shm ' "$STATE")$(grep -c '^STRAY_COPY_LOCKS=2$' "$STATE")" "11" "control: the state file now says a process held the stray store at the copy"
holder_start; HELD_SIDE="$(side_files)"; HELD_LOCKS="$(stray_locks_here)"; holder_end; touch -d "@$(( $(job_t) + 600 ))" "$PD/data"
check "$HELD_SIDE|$(( HELD_LOCKS > 0 ))|$(side_files)|$(stray_entries_here)" "plugin.sqlite3-shm,plugin.sqlite3-wal|1||$(cat "$SB/strayk.ents")" "control: that process held the -wal and -shm, with locks the kernel lists, and ending it removed them — every other entry as it was"
set_sched $(( $(job_t) - 60 ))
OUT="$(run --after-only)"
check "$(has "$OUT" "note  R19 a process that opened the stray store before the fix still held it at the copy (its side files then: -wal-shm; locks: 2), and master's record shows no completed run of the retry job since the fix was installed at $(utc "$(job_t)") (its last: $(utc $(( $(job_t) - 60 ))))")$(fails "$OUT")$(has "$OUT" 'nothing has opened the stray store since')$(grep -c '^STRAY_QUIET_FROM=' "$STATE")" "yes0no0" \
  "4r held at the copy, the folder changed when that process ended, no completed run since: not yet evidence — a note, and no reference recorded"
set_sched $(( $(job_t) + 900 ))
M="$(mutant r19held "$DEPLOY" '  elif [ -z "$SLR_HELD_WHAT" ]; then' '  elif true; then')"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" 'FAIL  R19 the side files there at the copy')$(has "$OUT" '5.18.91 (after): PASSED')$(grep -c '^STRAY_QUIET_FROM=' "$STATE")" "yesno0" \
  "4t the copy that judges from the copy whatever held the store then FAILS on that process's own close — the false failure the fourth review found — detected"
r19_unfail
# The held-at-copy flow before any reference is recorded. A change after master's last completed run began, within 30
# minutes of the copy, may be that process's own late close: not yet evidence (the eighth review); master's next
# completed run lets R19 record it. More than 30 minutes after the copy it is an open after the fix (the seventh).
held_state; touch -d "@$(( $(job_t) + 950 ))" "$PD/data"
OUT="$(run --after-only)"
check "$(has "$OUT" "note  R19 a process that opened the stray store before the fix still held it at the copy (its side files then: -wal-shm; locks: 2); its side files are gone, and the stray folder last changed at $(utc $(( $(job_t) + 950 ))), after master's last completed run of the retry job began (at $(utc $(( $(job_t) + 900 )))) but within 30 minutes of the copy")$(fails "$OUT")$(grep -c '^STRAY_QUIET_FROM=' "$STATE")$(grep -c '^STRAY_R19_FAILED=' "$STATE")" "yes000" \
  "4r2 held at the copy, that process gone, the folder changed after master's last completed run began but within 30 minutes of the copy: not yet evidence — a note, nothing recorded"
set_sched $(( $(job_t) + 1200 ))
OUT="$(run --after-only)"
check "$(fails "$OUT")$(notes "$OUT")$(has "$OUT" "the stray folder has not changed since $(utc $(( $(job_t) + 950 ))), the stray folder's last change — within 30 minutes of the copy")$(grep -c "^STRAY_QUIET_FROM=$(( $(job_t) + 950 ))\$" "$STATE")" "00yes1" \
  "4r3 master's next completed run began after that change: R19 records it as the moment and PASSES"
held_state; touch -d "@$(( $(job_t) + 1920 ))" "$PD/data"; set_sched $(( $(job_t) + 2400 ))
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R19 a process that opened the stray store before the fix still held it at the copy (its side files then: -wal-shm; locks: 2); its side files are gone, and the stray folder last changed at $(utc $(( $(job_t) + 1920 ))) — more than 30 minutes after the copy (at $(utc "$(job_t)"))")$(has "$OUT" 'nothing has opened the stray store since')$(grep -c '^STRAY_QUIET_FROM=' "$STATE")" "yesno0" \
  "4r4 held at the copy, its side files gone, the folder's last change more than 30 minutes after the copy — later than that process can have closed it: R19 FAILS"
r19_failed_kept 4r4
M="$(mutant r19bound "$DEPLOY" '    elif [ "$SLR_DIR_T" -gt $((SLR_JOB_T + SLR_HOLD_MAX)) ]; then' '    elif false; then')"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" 'FAIL  R19')$(has "$OUT" 'nothing has opened the stray store since')" "noyes" "4r5 the copy without the 30-minute bound takes that late change for the close and passes — detected"
held_state; printf '{}' > "$PD/data/rh-other-writer.json"; touch -d "@$(( $(job_t) + 1920 ))" "$PD/data"; set_sched $(( $(job_t) + 2400 ))
OUT="$(run --after-only)"
check "$(has "$OUT" "note  R19 a process that opened the stray store before the fix still held it at the copy (its side files then: -wal-shm; locks: 2); its side files are gone, the stray folder last changed at $(utc $(( $(job_t) + 1920 ))) — more than 30 minutes after the copy (at $(utc "$(job_t)")) — and its entries are not the ones recorded before the deploy")$(fails "$OUT")$(grep -c '^STRAY_QUIET_FROM=' "$STATE")$(grep -c '^STRAY_R19_FAILED=' "$STATE")" "yes000" \
  "4r6 the same late change with a file of another writer's in the folder: not evidence either way — a note, nothing recorded, never a failure (the ninth review)"
M="$(mutant r19late30note "$DEPLOY" '        note "R19 $SLR_HELD_WHAT; its side files are gone, the stray folder last changed at' '        bad "R19 $SLR_HELD_WHAT; its side files are gone, the stray folder last changed at')"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" 'FAIL  R19 a process that opened the stray store before the fix still held it at the copy')" "yes" "4r6m the copy that takes another writer's file for an open fails, and records it for good — detected"
r19_unfail; rm -f "$PD/data/rh-other-writer.json"
held_state; touch -d "@$(( $(job_t) + 600 ))" "$PD/data"; set_sched $(( $(job_t) + 900 ))
OUT="$(run --after-only)"
check "$(fails "$OUT")$(notes "$OUT")$(has "$OUT" "ok    R19 the retry job ran at $(utc $(( $(job_t) + 900 ))) with the fix installed")$(has "$OUT" "the stray folder has not changed since $(utc $(( $(job_t) + 600 ))), the stray folder's last change — within 30 minutes of the copy and no later than the start of master's last completed run of the retry job (at $(utc $(( $(job_t) + 900 ))))")$(grep -c "^STRAY_QUIET_FROM=$(( $(job_t) + 600 ))\$" "$STATE")$(grep -c '^STRAY_QUIET_SIDE=-$' "$STATE")$(has "$OUT" '5.18.91 (after): PASSED')" "00yesyes11yes" \
  "4s held at the copy, that process gone ten minutes after it, and master's next completed run left the folder quiet: R19 PASSES, and records that close — the folder's last change — as its reference"
check "$(has "$OUT" "whether anything has opened it since the fix: nothing has opened it since $(utc $(( $(job_t) + 600 ))), the stray folder's last change")$(has "$OUT" "between the copy and then, an open cannot be told from what the copy saw holding it")" "yesyes" "…4s's summary names the recorded moment, and what it cannot tell (the tenth review)"
holder_start; holder_end; touch -d "@$(( $(job_t) + 1000 ))" "$PD/data"; set_sched $(( $(job_t) + 1200 ))
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R19 the stray folder changed at $(utc $(( $(job_t) + 1000 )))")$(has "$OUT" "after $(utc $(( $(job_t) + 600 ))), the moment an earlier run recorded")$(has "$OUT" 'nothing has opened the stray store since')" "yesyesno" \
  "4u after that moment an open and close, then a later completed run: R19 FAILS — it judges from the recorded moment, not from the latest run"
r19_failed_kept 4u
M="$(mutant r19pin "$DEPLOY" '  if [ -n "$SLR_QFROM" ]; then' '  if false; then')"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" 'FAIL  R19 the stray folder changed')$(has "$OUT" 'nothing has opened the stray store since')" "noyes" \
  "4v the copy that forgets the recorded moment judges from the latest run and passes the same open — the window the fifth review found — detected"
holder_start
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R19 the stray store's side files are -wal-shm now (")$(has "$OUT" "lock(s) on the store), and were - at $(utc $(( $(job_t) + 600 ))), the moment an earlier run recorded")" "yesyes" \
  "4v2 after that moment a process holds the stray store: R19 FAILS — the process from the copy is known to be gone (the sixth review)"
r19_failed_kept 4v2
holder_end
held_state; touch -d "@$CD" "$PD/data"
holder_start; touch -d "@$CD" "$PD/data"   # held since before the copy: the folder as the copy saw it
copy_at $(( $(date -u +%s) - 600 ))        # the copy ten minutes ago: the holder may still be the one from then
OUT="$(run --after-only)"
check "$(has "$OUT" "note  R19 a process that opened the stray store before the fix still held it at the copy")$(has "$OUT" "and a process holds it open now (")$(fails "$OUT")$(has "$OUT" 'nothing has opened the stray store since')$(grep -c '^STRAY_QUIET_FROM=' "$STATE")" "yesyes0no0" \
  "4w a process holding the stray store now, as one held it at the copy: while it does, an open leaves no trace — a note, never a pass, nothing recorded"
# The same holder, with a file of another writer's added since the copy (the tenth review): the folder changed, the side
# files the same — not evidence either way: a note, never a failure.
printf '{}' > "$PD/data/rh-other-writer.json"
OUT="$(run --after-only)"
check "$(has "$OUT" "note  R19 a process that opened the stray store before the fix still held it at the copy (its side files then: -wal-shm; locks: 2); the stray folder changed at")$(has "$OUT" ", side files are there now (-wal-shm, ")$(has "$OUT" "and its entries are not the ones recorded before the deploy — not evidence either way")$(fails "$OUT")$(grep -c '^STRAY_QUIET_FROM=' "$STATE")$(grep -c '^STRAY_R19_FAILED=' "$STATE")" "yesyesyes000" \
  "4w6 held at the copy and held now, with a file of another writer's added since: not evidence either way — a note, nothing recorded, never a failure (the tenth review)"
M="$(mutant r19held12 "$DEPLOY" '          note "R19 $SLR_HELD_WHAT; the stray folder changed at $(utc_of "$SLR_DIR_T"), after $SLR_COPY_WHAT, side files' '          bad "R19 $SLR_HELD_WHAT; the stray folder changed at $(utc_of "$SLR_DIR_T"), after $SLR_COPY_WHAT, side files')"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" 'FAIL  R19 a process that opened the stray store before the fix still held it at the copy')" "yes" "4w6m the copy that takes another writer's file beside a held store for an open fails, and records it for good — detected"
r19_unfail; rm -f "$PD/data/rh-other-writer.json"; touch -d "@$CD" "$PD/data"
# The same holder, and the locks unreadable now (the tenth review — the locks' control failing): whether a process holds
# the side files cannot be told — a note; R19 records nothing a later run could fail on.
M="$(mutant locksctl2 "$DEPLOY" '  command -v python3 >/dev/null 2>&1 ||' '  command -v no-such-python3 >/dev/null 2>&1 ||')"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" "note  R19 a process that opened the stray store before the fix still held it at the copy (its side files then: -wal-shm; locks: 2); its side files (-wal-shm) are there now, and whether a process holds them cannot be told")$(fails "$OUT")$(grep -c '^STRAY_QUIET_FROM=' "$STATE")" "yes00" \
  "4w7 held at the copy, the same side files now and the locks unreadable: a note, nothing recorded (the tenth review)"
M="$(mutant locksctl2w "$DEPLOY" '  command -v python3 >/dev/null 2>&1 ||' '  command -v no-such-python3 >/dev/null 2>&1 ||' '          0) record_ref "$(date -u +%s)" "$SLR_SIDE_NOW"' "          0|'?') record_ref \"\$(date -u +%s)\" \"\$SLR_SIDE_NOW\"")"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(grep -c '^STRAY_QUIET_FROM=' "$STATE")" "1" "4w7m the copy that takes unreadable locks for none records the side files as left behind — a moment later runs would judge from — detected"
sed -i '/^STRAY_QUIET_FROM=/d; /^STRAY_QUIET_SIDE=/d' "$STATE"
held_state   # the copy as recorded: more than 30 minutes ago, and the same process still holds the store
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R19 a process that opened the stray store before the fix still held it at the copy (its side files then: -wal-shm; locks: 2), and a process holds it open now (")$(has "$OUT" "more than 30 minutes after the copy (at $(utc "$(job_t)"))")$(grep -c '^STRAY_QUIET_FROM=' "$STATE")" "yesyes0" \
  "4w1 the store still held more than 30 minutes after the copy: not the process from the copy — R19 FAILS, whatever ends that holder later (the eighth review)"
r19_failed_kept 4w1
M="$(mutant r19heldbound "$DEPLOY" '          *) if [ "$(date -u +%s)" -gt $((SLR_JOB_T + SLR_HOLD_MAX)) ]; then' '          *) if false; then')"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" 'FAIL  R19')$(has "$OUT" 'and a process holds it open now (')" "noyes" "4w1m the copy without that bound only notes the holder — detected"
held_state; sed -i 's/^STRAY_COPY_LOCKS=2$/STRAY_COPY_LOCKS=?/' "$STATE"
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R19 side files were there at the copy (-wal-shm), and whether a process held them then could not be told (locks: ?), and a process holds it open now (")$(has "$OUT" "more than 30 minutes after the copy (at $(utc "$(job_t)"))")$(grep -c '^STRAY_QUIET_FROM=' "$STATE")" "yesyes0" \
  "4w1q the copy could not tell whether a process held the store (locks unread then), and a holder now, past the 30 minutes: R19 FAILS, saying what the copy could not tell (the ninth review)"
r19_failed_kept 4w1q
held_state
printf 'STRAY_QUIET_FROM=%s\nSTRAY_QUIET_SIDE=-wal-shm\n' "$(date -u +%s)" >> "$STATE"; set_sched $(( $(date -u +%s) + 120 ))
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R19 a process holds the stray store open now (")$(has "$OUT" 'nothing has opened the stray store since')" "yesno" \
  "4x2 a process holding the store after R19 recorded its side files as left behind: R19 FAILS"
r19_failed_kept 4x2
M="$(mutant r19locks "$DEPLOY" 'SLR_LOCKS_NOW="$STRAY_NOW_LOCKS"' 'SLR_LOCKS_NOW=0')"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" 'a process holds the stray store open now')$(has "$OUT" 'nothing has opened the stray store since')" "noyes" \
  "4x the copy blind to the locks takes the held side files for ones left behind and passes — detected"
# 3. That process killed before it closed the store (as uCRM's restart after a copy may do): R19 records its side files as
#    left behind, and from then on they must stay — the sixth review: once seen, never forgotten.
holder_kill; held_state; set_sched $(( $(job_t) + 900 ))
OUT="$(run --after-only)"
check "$(side_files)|$(fails "$OUT")$(has "$OUT" "its side files (-wal-shm) are still there, the folder unchanged since the copy, and held by no process now: it was stopped before it closed the store")$(has "$OUT" 'nothing has opened the stray store since')$(grep -c '^STRAY_QUIET_SIDE=-wal-shm$' "$STATE")" "plugin.sqlite3-shm,plugin.sqlite3-wal|0yesno1" \
  "4y held at the copy, then killed before it closed the store: its side files stay, held by none — R19 records them as left behind, and waits for a completed run after that — a note"
# Run again before master's next completed run (the tenth review): the folder unchanged since the recorded moment, but no
# completed run of the job since it — not yet evidence.
QF="$(sed -n 's/^STRAY_QUIET_FROM=//p' "$STATE")"
OUT="$(run --after-only)"
check "$(has "$OUT" "note  R19 the stray folder has not changed since $(utc "$QF"), the moment an earlier run recorded (STRAY_QUIET_FROM; the side files then: -wal-shm), but master's record shows no completed run of the retry job since that moment (its last: $(utc $(( $(job_t) + 900 ))))")$(fails "$OUT")$(has "$OUT" 'nothing has opened the stray store since')$(grep -c "^STRAY_QUIET_FROM=$QF\$" "$STATE")" "yes0no1" \
  "4y1 run again before master's next completed run: the folder unchanged since the recorded moment, but no completed run since it — a note, never a pass (the tenth review)"
M="$(mutant r19recrun "$DEPLOY" '  elif [ -z "$SLR_RUN_DONE" ] || { [ "$SLR_REF_ORIGIN" = "recorded" ] && [ "$SLR_RUN_T" -le "$SLR_REF_T" ]; }; then' '  elif [ -z "$SLR_RUN_DONE" ]; then')"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" 'nothing has opened the stray store since')" "yes" "4y1m the copy that counts a run begun before the recorded moment passes — detected"
sed -i "s/^STRAY_QUIET_FROM=.*/STRAY_QUIET_FROM=$(( $(job_t) + 900 ))/" "$STATE"   # that moment, placed before 4y3's open (the eighth review)
set_sched $(( $(date -u +%s) + 120 ))
OUT="$(run --after-only)"
check "$(fails "$OUT")$(notes "$OUT")$(has "$OUT" "the moment an earlier run recorded (STRAY_QUIET_FROM; the side files then: -wal-shm) are still there, held by no process")$(has "$OUT" '5.18.91 (after): PASSED')" "00yesyes" \
  "4y2 a completed run after that moment, the side files still there and held by none, the folder unchanged: R19 PASSES"
holder_start; holder_end; touch -d "@$(( $(job_t) + 1000 ))" "$PD/data"; set_sched $(( $(date -u +%s) + 120 ))
OUT="$(run --after-only)"
check "$(side_files)|$(has "$OUT" "FAIL  R19 the side files there at")$(has "$OUT" "are gone")$(has "$OUT" 'nothing has opened the stray store since')" "|yesyesno" \
  "4y3 then an open and close removes them: R19 FAILS — what it saw left behind it does not forget (the sixth review's case)"
r19_failed_kept 4y3
M="$(mutant r19forget "$DEPLOY" '  if [ -n "$SLR_QFROM" ]; then' '  if false; then')"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" 'FAIL  R19')$(has "$OUT" 'nothing has opened the stray store since')" "noyes" \
  "4y4 the copy that forgets what it recorded passes the same open — detected"
# The side files recorded gone, and the folder's entries not the ones recorded (the tenth review): another writer may
# explain the change — not evidence either way: a note, never a failure.
printf '{}' > "$PD/data/rh-other-writer.json"
OUT="$(run --after-only)"
check "$(has "$OUT" "note  R19 the side files there at")$(has "$OUT" "are gone, and the stray folder's entries are not the ones recorded before the deploy — not evidence either way")$(fails "$OUT")$(grep -c '^STRAY_R19_FAILED=' "$STATE")" "yesyes00" \
  "4y5 the side files recorded gone, with a file of another writer's in the folder: a note, never a failure (the tenth review)"
M="$(mutant r19gone "$DEPLOY" '      note "R19 the side files there at $SLR_REF_WHAT are gone, and the stray folder'"'"'s entries' '      bad "R19 the side files there at $SLR_REF_WHAT are gone, and the stray folder'"'"'s entries')"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" "FAIL  R19 the side files there at")" "yes" "4y5m the copy that takes the other writer's change for an open fails, and records it — detected"
r19_unfail; rm -f "$PD/data/rh-other-writer.json"
# 3b. Side files there again after the folder changed since the copy: those the copy saw were removed — that process's
#     close — and these were made since, by an open after the fix: killed, still open, or read-only (the seventh review).
held_state; holder_start; holder_kill; touch -d "@$(( $(job_t) + 1100 ))" "$PD/data"; set_sched $(( $(job_t) + 1200 ))
OUT="$(run --after-only)"
check "$(side_files)|$(has "$OUT" "FAIL  R19 a process that opened the stray store before the fix still held it at the copy (its side files then: -wal-shm; locks: 2); the stray folder changed at $(utc $(( $(job_t) + 1100 ))), after the copy (the folder's time then: $(utc "$CD"); side files then: -wal-shm), and side files are there now (-wal-shm, held by no process) with every other entry as recorded before the deploy")$(has "$OUT" 'something opened the stray store after the fix')$(grep -c '^STRAY_QUIET_FROM=' "$STATE")" \
  "plugin.sqlite3-shm,plugin.sqlite3-wal|yesyes0" "4w2 held at the copy, that process gone, then an opener stopped before it closed the store: its side files there again — R19 FAILS, and records no reference"
check "$(grep -c '^STRAY_R19_FAILED=[0-9]*$' "$STATE")" "1" "…4w2: the failure is recorded in the state file (STRAY_R19_FAILED), once"
cp "$STATE" "$SB/state.w2"; r19_unfail
M="$(mutant r19again "$DEPLOY" '      if [ "$SLR_DIR_T" -gt "$SLR_COPY_DIR" ] || [ "$SLR_SIDE_NOW" != "$SLR_SIDE_COPY" ]; then' '      if false; then')"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" 'FAIL  R19')$(has "$OUT" 'it was stopped before it closed the store')" "noyes" "4w3 the copy that does not compare the side files with the copy's takes them for that process's own and records them — detected"
# The failure outlives its evidence: an open and close removes those side files within 30 minutes of the copy, and
# master's next completed run begins after it — judged afresh, the folder would pass (the seventh review).
cp "$SB/state.w2" "$STATE"; rm -f "$SB/state.w2"
holder_start; holder_end; touch -d "@$(( $(job_t) + 1150 ))" "$PD/data"; set_sched $(( $(job_t) + 1300 ))
OUT="$(run --after-only)"
check "$(side_files)|$(has "$OUT" "FAIL  R19 an earlier run FAILED R19 (at ")$(has "$OUT" 'so no later run passes over it')$(has "$OUT" 'nothing has opened the stray store since')$(grep -c '^STRAY_R19_FAILED=' "$STATE")" "|yesyesno1" \
  "4w2b its evidence then gone from the folder: R19 FAILS on its own record of the failure, and does not record it twice"
M="$(mutant r19sticky "$DEPLOY" 'if [ -n "$SLR_FAILED_LINE" ]; then' 'if false; then')"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" 'FAIL  R19')$(has "$OUT" 'nothing has opened the stray store since')" "noyes" "4w2c the copy that forgets its recorded failure passes the same folder — detected"
r19_unfail
held_state; holder_start; holder_end; holder_start; touch -d "@$(( $(job_t) + 1100 ))" "$PD/data"
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R19 a process that opened the stray store before the fix still held it at the copy")$(has "$OUT" "and side files are there now (-wal-shm, ")$(has "$OUT" " lock(s) on the store) with every other entry as recorded before the deploy")" "yesyesyes" \
  "4w4 held at the copy, that process gone, then a new process holding the store: R19 FAILS — the holder now is not the one from the copy"
r19_failed_kept 4w4
holder_end; held_state; RO_BEFORE="$(side_files)"; ro_open && RO_RC=0 || RO_RC=$?; touch -d "@$(( $(job_t) + 1100 ))" "$PD/data"
check "$RO_BEFORE|$RO_RC|$(side_files)|$(stray_locks_here)" "|0|plugin.sqlite3-shm,plugin.sqlite3-wal|0" "control: no side files before; a read-only open of the stray store ran, made its -wal and -shm and left them, held by none"
OUT="$(run --after-only)"
check "$(has "$OUT" "and side files are there now (-wal-shm, held by no process) with every other entry as recorded before the deploy")$(has "$OUT" 'something opened the stray store after the fix')$(has "$OUT" 'nothing has opened the stray store since')" "yesyesno" \
  "4w5 held at the copy, that process gone, then a READ-ONLY open: the side files it leaves are there again — R19 FAILS"
r19_failed_kept 4w5
holder_start; holder_end
check "$(side_files)" "" "control: a read-write open and close removed the side files the read-only open left"
# 4. Side files left at the copy by a process already stopped (held by none at the copy): the copy is the reference.
holder_start; holder_kill; stale_state; touch -d "@$CD" "$PD/data"; set_sched $(( $(date -u +%s) + 120 ))
OUT="$(run --after-only)"
check "$(fails "$OUT")$(notes "$OUT")$(has "$OUT" "the side files there at the copy (the folder's time then: $(utc "$CD"); side files then: -wal-shm, held by no process) are still there, held by no process")$(has "$OUT" '5.18.91 (after): PASSED')" "00yesyes" \
  "4z left behind at the copy and still there, held by none, the folder unchanged since the copy: R19 PASSES"
M="$(mutant locksctl "$DEPLOY" '  command -v python3 >/dev/null 2>&1 ||' '  command -v no-such-python3 >/dev/null 2>&1 ||')"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" "note  the locks' control did not pass (no python3 on this host)")$(has "$OUT" 'whether a process holds them now cannot be told')$(has "$OUT" 'nothing has opened the stray store since')" "yesyesno" \
  "4z1 with the locks' control failing, R19 cannot tell left-behind side files from held ones: a note, never a pass"
holder_start
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R19 a process holds the stray store open now (")$(has "$OUT" "), after the copy (the folder's time then: $(utc "$CD"); side files then: -wal-shm, held by no process) — something opened it since")$(grep -c '^STRAY_QUIET_FROM=' "$STATE")" "yesyes0" \
  "4z2 a process holding the store when the copy found it held by none: R19 FAILS, judged from the copy"
r19_failed_kept 4z2
holder_end
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R19 the side files there at the copy")$(has "$OUT" "are gone")" "yesyes" "4z3 side files left at the copy, gone since: only the close of an open removes them — R19 FAILS"
r19_failed_kept 4z3
holder_start; holder_kill; cp "$SB/state.free" "$STATE"
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R19 the stray store's side files are -wal-shm now (held by no process), and were - at the copy")$(has "$OUT" 'Do not quarantine it')" "yesyes" \
  "4z4 side files left behind now when nothing was there at the copy: made after it — R19 FAILS"
r19_failed_kept 4z4
cp "$SB/state.free" "$STATE"; rm -f "$SB/state.free"
stray_back; set_sched $(( $(date -u +%s) - 300 ))
check "$(stray_snap)$(side_files)$(stray_locks_here)$(grep -c '^STRAY_QUIET_FROM=' "$STATE")$(grep -c '^STRAY_R19_FAILED=' "$STATE")" "${STRAY0}000" "…the stray folder put back exactly, its own time included, no side file left, no lock on it, no reference and no failure recorded"
OUT="$(run --after-only)"
check "$(fails "$OUT")$(notes "$OUT")$(has "$OUT" 'nothing has opened the stray store since')$(has "$OUT" '5.18.91 (after): PASSED')$(stray_snap)" "00yesyes$STRAY0" "4i every fault removed: --after-only PASSES with no note, the stray store as after the deploy"
set_sched "$SCHED0"
check "$(data_digest)" "$D1" "control: master's schedule put back as seeded — the data as after the deploy"

echo; echo "== 5. the other checks have teeth — every fault named, then removed =="
cp -p "$PD/$JOB" "$SB/job.aside"; git -C "$REPO" show "$BASE:$P/$JOB" > "$PD/$JOB"
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R1 installed files that differ: $JOB")$(has "$OUT" 'retry-job:no-gate')$(has "$OUT" "FAIL  R18 the installed retry job does not act as 5.18.91's must: Uganda $SLR_OPENS")" "yesyesyes" \
  "5a 5.18.90's retry job back on the server: R1 names it, R6 the missing gate, R18 the job opening the stray store on Uganda"
cp -p "$SB/job.aside" "$PD/$JOB"
cp -p "$PD/$SLR_LIB" "$SB/lib.aside"; edit_installed "$SLR_LIB" "$SKIP_LINE" "            \$out['skip'] = false;"
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R1 installed files that differ: $SLR_LIB")$(has "$OUT" "FAIL  R18 on this server's own configuration the installed gate would not stop the retry job: live=same inside=no store=read tenant=uganda skip=no")$(has "$OUT" "FAIL  R18 the installed retry job does not act as 5.18.91's must")" "yesyesyes" \
  "5b the installed library never saying skip: R1 names it, R18 on this server's configuration and on the throwaway layout"
M="$(mutant r18 "$DEPLOY" 'if [ "${SLRS_R%% | *}" = "$SLR_SERVER_SIGNATURE" ]; then ok "R18' 'if true; then ok "R18' 'if [ "${SLR_R_UG%% | *}" = "$SLR_STOPS" ] && [ "${SLR_R_SS%% | *}" = "$SLR_OPENS" ] && case "$SLR_R_UG|$SLR_R_SS" in *"locks=dropped"*) false;; *) true;; esac; then ok "R18' 'if true; then ok "R18')"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" 'FAIL  R18')$(cnt "$OUT" 'ok    R18 ')" "no2" "5c the R18-blinded copy passes the same library — detected"
cp -p "$SB/lib.aside" "$PD/$SLR_LIB"
# The installed library reading the live store's -shm with file() — the second review's case: the job still stops, but
# master loses its lock on the -shm.
edit_installed "$SLR_LIB" "$NEVER_LINE" "            \$peek = @file(\$db . '-shm');"$'\n'"$NEVER_LINE"
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R1 installed files that differ: $SLR_LIB")$(has "$OUT" "FAIL  R18 the installed retry job does not act as 5.18.91's must: Uganda $SLR_STOPS | locks=dropped")$(has "$OUT" "ok    R18 on this server's own configuration the installed gate stops")" "yesyesyes" \
  "5c2 the installed library reading the live store's -shm with file(): the gate still stops the job, and R18 FAILS on the lock master lost"
M="$(mutant r18lk "$DEPLOY" ' && case "$SLR_R_UG|$SLR_R_SS" in *"locks=dropped"*) false;; *) true;; esac; then ok "R18' '; then ok "R18')"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" 'FAIL  R18 the installed retry job does not act')$(has "$OUT" 'ok    R18 the installed retry job, on throwaway layouts')" "noyes" "5c3 the copy blind to the lock passes the same library — detected"
cp -p "$SB/lib.aside" "$PD/$SLR_LIB"
# The configuration files and the vault not naming Uganda by themselves: R18 still passes on the server's configuration —
# the store names Uganda — and says what that would cost after the 02:00 maintenance copy.
cfg_keep; cp -p "$VAULT" "$SB/vault.kept"; [ -f "$DATA/config.json" ] && cp -p "$DATA/config.json" "$SB/config.kept"; vault USD
php -r '$f = $argv[1]; $c = json_decode((string)file_get_contents($f), true); foreach (["tenant_profile", "currency_code", "cashbook_base_currency"] as $k) unset($c[$k]); file_put_contents($f, json_encode($c));' "$DATA/kyc_config.json"
OUT="$(run --after-only)"
check "$(has "$OUT" "ok    R18 on this server's own configuration the installed gate stops")$(has "$OUT" "note  R18 the configuration files and the vault alone do not name Uganda (by-store=uganda by-files+vault=south-sudan)")" "yesyes" \
  "5l the files and the vault alone naming no Uganda: R18 passes on the store, and notes the one 02:00 run it would cost"
cfg_back; cp -p "$SB/vault.kept" "$VAULT"; [ -f "$SB/config.kept" ] && cp -p "$SB/config.kept" "$DATA/config.json"
check "$(data_digest)" "$D1" "control: the configuration files and the vault put back byte for byte — the data as after the deploy"
db_keep; check "$(sqx 'DROP TRIGGER wa_channels_never_deleted')" "done" "control: one trigger dropped from 087"
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R3 migration 087 is recorded but not as it must be: incomplete:wa_channels_never_deleted')" "yes" "5d R3 names the missing trigger"
db_back
cfg_keep; cfg_set multi_number_channels_enabled on
OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  V3f multi_number_channels_enabled reads ON (mn=on/on, files/store)')$(has "$OUT" 'FAIL  R11 the registry is not dark')$(has "$OUT" 'FAIL  R2c')" "yesyesyes" "5e the registry's switch ON: V3f, R11 and R2c each say the registry is not dark"
cfg_back
DBF="$(git -C "$REPO" ls-tree -r --name-only "$PIN" -- "$P/dishnet-mikrotik-control-plane" | head -1)"; DBF="${DBF#$P/}"
cp "$PD/$DBF" "$SB/db.aside"; printf '\n# changed on the server\n' >> "$PD/$DBF"
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R14 Domain B files on the server differ from $PIN: ./$DBF")" "yes" "5f a Domain B file changed on the server: R14 names it"
cp "$SB/db.aside" "$PD/$DBF"
db_keep; EVF="$(sq "SELECT id FROM events WHERE status = 'failed'")"; sqx "DELETE FROM events WHERE id = $EVF" >/dev/null
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  R13 events that were not done at the snapshot are GONE from the queue (kept=2/3 missing=$EVF)")" "yes" "5g an event not done deleted from the queue: R13 names its id"
db_back
touch "$SB/web/.del_photo"; db_keep; OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R7 the photo tables or files LOST something during the run (present:2:0 → present:1:0, files:2 → files:1)')" "yes" "5h R7: a photo lost during the run fails, naming both counts"
db_back; printf 'jpeg' > "$DATA/uploads/job_photos/20/cable-1-b.jpg"
base_log; logline "$(date -u +%s)" "PHP Fatal error:  Uncaught Error: planted by the rehearsal in /data/ucrm/data/plugins/$P/cron/event_processor.php:1"
OUT="$(run --after-only)"
check "$(has "$OUT" "FAIL  V4 1 fatal line(s) of $P since $DA:")" "yes" "5i V4 in --after-only mode reads the log since the deploy and finds a fatal planted after it"
base_log
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" '5.18.91 (after): PASSED')$(data_digest)$(stray_snap)" "0yes$D1$STRAY0" "5j every fault removed: --after-only PASSES, the data and the stray store as after the deploy"
S3="$(setcfg multi_number_channels_enabled 1)"
check "$(has "$S3" 'Not yet: the department numbers are not all verified (sales, support). Nothing was saved.')$(both_copies multi_number_channels_enabled)" "yes-" \
  "5k control: 5.18.90's refusal is still in place — the registry's switch set with the installed tool is refused while the department numbers are not verified, nothing saved"
check "$(evo_calls)" "0" "control: Evolution never called — not by the installed tool or the checks"

echo; echo "== 6. the rollback: --rollback, typed ROLLBACK =="
D2="$(data_digest)"; fresh; OUT="$(run --answer ROLLBACK --rollback)"
check "$(fails "$OUT")$(has "$OUT" '5.18.91 (rollback): PASSED')" "0yes" "PASSED"
for l in "note  after the rollback the code is 5.18.90's again: the Starlink retry job opens the stray store under the plugin folder again on Uganda, at most every 10 minutes (applying any migration it lacks — none is due)" \
         "ok    backed up the installed plugin (5.18.91, $PIN)" "ok    copied the stray store's folder (<plugin>/data)" \
         "GO — evidence recorded" "checking out $BASE (5.18.90) for the documented deploy" "ok    container serves $BASE (5.18.90)" \
         "ok    V3f the channel registry's switch is unchanged by this run and OFF in both copies (mn=absent/absent; files/store)" \
         "ok    RB the installed manifest says 5.18.90" \
         "ok    RB the installed plugin is 5.18.90's again: the retry job is 5.18.90's byte for byte, its gate gone (lib/StarlinkRetryScope.php stays on disk, reached by nothing); 5.18.90's safety fix — the policy, the internal-number guard, the department numbers' Verify number, the registry's refusal in set_config.php — 5.18.89's salesperson numbers, 5.18.88's Batch 1 and 5.18.87's lead fixes are all in place" \
         "ok    RB the event processor is 5.18.90's: on Uganda it leaves the workers' events to them ($UG_SIG), on South Sudan the loop as ever ($SS_SIG)" \
         "ok    RB the assistant's prompt is 5.18.90's, byte for byte, on this server's own configuration — every conversation and the control ($PS_SIG)" \
         "ok    RB the three numbers route exactly as before the rollback: $SHAPE" \
         "ok    RB the event queue lost nothing across the rollback (kept=3/3 missing=-)" \
         "ok    RB the retry job is 5.18.90's again, on throwaway layouts: on Uganda and on South Sudan it opens and migrates <plugin>/data and leaves its variables there ($SLR_OPENS | locks=kept)" \
         "note  RB with 5.18.90's retry job back, the stray store under the plugin folder is opened at most every 10 minutes again (any migration it lacks applied — none is due), as before 5.18.91; it was not moved or changed by this run (db=" \
         "ok    RB 5.18.83's Customer Installation Authorisation is whole" \
         "note  RB the code is 5.18.90 again" "so does the stray store under the plugin folder, which this run never touches"; do
  check "$(has "$OUT" "$l")" "yes" "6: ${l:0:96}"
done
check "$(live)$(installed_digest "$BASE")$(inst_ver)$(sha256sum "$PD/$JOB" | cut -c1-64)" "${BASE}05.18.90$JOB90_SHA" "the container serves $BASE: every changed file as 5.18.90 has it — the retry job byte for byte — manifest 5.18.90"
check "$(test -f "$PD/$SLR_LIB" && echo kept || echo gone)$(grep -rlF 'StarlinkRetryScope' "$PD" --include=*.php | grep -v '/tests/' | sed "s#^$PD/##" | sort | tr '\n' ' ')" "kept$SLR_LIB " "after the rollback the library stays on disk, named by nothing but itself — deploy-hybrid.sh never deletes"
check "$(grep -cF -- "$WH_STEP" "$PD/evo_webhook.php")$(grep -cF -- "$DEPT_VERIFY" "$PD/lib/SalesNumbersAdmin.php")$(grep -cF -- "$SC_REFUSE" "$PD/tools/set_config.php")$(test -f "$PD/lib/AutomationPolicy.php" && echo 1 || echo 0)" "1111" "…and 5.18.90's safety fix is kept"
check "$(data_digest)$(stray_snap)$(host_code_left)$(left_tmp)" "${D2}${STRAY0}0$TMP0" "the rollback changed no data and not the stray store, and left no copy of the code anywhere"
# A deploy run again keeps the earlier state file and says an R19 failure it recorded (the tenth review): here a deploy
# declined at its question, over an earlier record that holds one.
fresh; printf 'DEPLOYED_AT=2026-10-09T00:00:00Z\nSTRAY_R19_FAILED=1760000000\n' > "$SB/out/state-5.18.91.env"; cp -p "$SB/out/state-5.18.91.env" "$SB/state.planted"
OUT="$(run --answer NO)"
PREV="$(ls "$SB/out"/state-5.18.91.env.prev-* 2>/dev/null | head -1)"
check "$(has "$OUT" "kept the earlier state file as $SB/out/state-5.18.91.env.prev-")$(has "$OUT" "note  an earlier deploy's R19 FAILED (STRAY_R19_FAILED in $SB/out/state-5.18.91.env.prev-")$(has "$OUT" 'STOP: not confirmed')$( [ -n "$PREV" ] && cmp -s "$PREV" "$SB/state.planted" && echo same || echo differs)$(grep -c '^STRAY_R19_FAILED=' "$SB/out/state-5.18.91.env")$(live)" "yesyesyessame0$BASE" \
  "6a a deploy declined at its question writes a new state file — and keeps the earlier one beside it, byte for byte, saying the R19 failure it recorded (the tenth review)"
sleep 1; OUT="$(run --answer NO)"
check "$(ls "$SB/out" | grep -c '^state-5.18.91.env.prev-')$(has "$OUT" "note  an earlier deploy's R19 FAILED (STRAY_R19_FAILED in $PREV)")" "2yes" \
  "6a3 declined once more: two earlier state files kept, and the failure still said, from the first of them — a later deploy run does not lose it (the eleventh review)"
fresh; cp -p "$SB/state.planted" "$SB/out/state-5.18.91.env"
M="$(mutant keepstate "$DEPLOY" '  if [ "$MODE" = "deploy" ] && [ -f "$STATE" ]; then' '  if false; then')"
OUT="$(run --answer NO --script "$M")"; rm -f "$M"
check "$(ls "$SB/out" | grep -c '^state-5.18.91.env.prev-')$(has "$OUT" "an earlier deploy's R19 FAILED")$(grep -c '^STRAY_R19_FAILED=' "$SB/out/state-5.18.91.env")" "0no0" \
  "6a2 the copy that does not keep it truncates the earlier record, its failure with it, silently — detected"
rm -f "$SB/state.planted"
set_sched "$(date -u +%s)"   # master ran the job just now — and the stray folder last changed two hours ago: A14 cannot see it move
fresh; OUT="$(run --answer DEPLOY)"
check "$(fails "$OUT")$(notes "$OUT")$(has "$OUT" '5.18.91 (deploy): PASSED')$(has "$OUT" "note  A14 the stray folder's last change was not within one dispatch of 5.18.90's retry job")$(live)$(stray_snap)" "02yesyes$PIN$STRAY0" \
  "6b deployed again over the rolled-back 5.18.90, master's last run of the job far from the stray folder's last change: PASSES, with A14's note and R19's, the stray store untouched"
set_sched "$SCHED0"
fresh; OUT="$(run --answer ROLLBACK --rollback)"
check "$(fails "$OUT")$(has "$OUT" '5.18.91 (rollback): PASSED')$(live)$(data_digest)$(stray_snap)" "0yes${BASE}$D2$STRAY0" "6c …and rolled back once more: PASSES, the data and the stray store as before"
printf '<?php // rehearsal\n' > "$REPO/$P/lib/RehearsalUntracked.php"; fresh; OUT="$(run --answer ROLLBACK --rollback)"; rm -f "$REPO/$P/lib/RehearsalUntracked.php"
check "$(has "$OUT" "note  the checkout holds 1 file(s) git does not track under $P, outside data/ ($P/lib/RehearsalUntracked.php): the rollback's copy installs them too")$(has "$OUT" 'STOP: NO-GO: the checkout holds')$(fails "$OUT")$(live)$(data_digest)$(stray_snap)" "yesno0${BASE}$D2$STRAY0" \
  "6c2 a rollback over a file git does not track in the checkout's plugin folder names it, and is not stopped by it (the eleventh review)"
# …and deployed once more, as the rehearsal leaves it — with 5.18.90's job holding the stray store at the copy: master
# dispatched it a moment before, and its process is still running when the files are copied (the fourth review of 09 Oct).
stray_keep; holder_start; set_sched "$(date -u +%s)" -1
fresh; OUT="$(run --answer DEPLOY)"; STATE="$SB/out/state-5.18.91.env"
check "$(fails "$OUT")$(notes "$OUT")$(has "$OUT" '5.18.91 (deploy): PASSED')$(has "$OUT" 'ok    A14 the stray folder last changed at')$(grep -cE '^STRAY_COPY=db=[^ ]+ side=-wal-shm ' "$STATE")$(grep -cE '^STRAY_COPY_LOCKS=[1-9][0-9]*$' "$STATE")$(has "$OUT" "note  R19 a process that opened the stray store before the fix still held it at the copy")$(has "$OUT" "and a process holds it open now (")$(has "$OUT" 'ok    R19 the stray store under the plugin folder is exactly as before the deploy')$(live)" "01yesyes11yesyesyes$PIN" \
  "6d …and deployed once more, as the rehearsal leaves it, with 5.18.90's job holding the stray store across the copy: PASSES; the state file records the hold and its locks, and R19 says not yet — a note"
holder_end; CLOSE_T="$(stat -c %Y "$PD/data")"; set_sched $(( $(date -u +%s) + 300 ))
M="$(mutant r19held6 "$DEPLOY" '  elif [ -z "$SLR_HELD_WHAT" ]; then' '  elif true; then')"
OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" 'FAIL  R19 the side files there at the copy')$(grep -c '^STRAY_QUIET_FROM=' "$STATE")" "yes0" \
  "6e that process ends after the copy: the copy that judges from the copy whatever held the store then FAILS on that close — the false failure — and records nothing"
r19_unfail
OUT="$(run --after-only)"
check "$(fails "$OUT")$(notes "$OUT")$(has "$OUT" "the stray folder has not changed since $(utc "$CLOSE_T"), the stray folder's last change — within 30 minutes of the copy")$(grep -c "^STRAY_QUIET_FROM=$CLOSE_T\$" "$STATE")$(( CLOSE_T >= $(copy_dir) ))$(has "$OUT" '5.18.91 (after): PASSED')" "00yes11yes" \
  "6e2 …while the script as it is waits for master's next completed run after that close, then PASSES, judging from that close — no false failure"
# A rollback declined at its question (the seventh review): it must leave the deploy's state file as the deploy wrote it.
cp "$STATE" "$SB/state.6e2"; NRB0="$(ls "$SB/out" | grep -c '^rollback-state-')"
OUT="$(run --answer NO --rollback)"
check "$(has "$OUT" 'not confirmed')$(live)$(cmp -s "$STATE" "$SB/state.6e2" && echo same || echo changed)$(( $(ls "$SB/out" | grep -c '^rollback-state-') - NRB0 ))" "yes${PIN}same1" \
  "6f a rollback declined at its question leaves this release live and the deploy's state file byte for byte as it was — its own record goes to a file of its own"
OUT="$(run --after-only)"
check "$(fails "$OUT")$(notes "$OUT")$(has "$OUT" 'nothing has opened the stray store since')$(has "$OUT" '5.18.91 (after): PASSED')" "00yesyes" "6f2 …and the next --after-only still judges from the deploy's record: PASSES"
rm -f "$SB/state.6e2"
# Only the folder's time is put back — the time the holder's open and close moved. Every file, its bytes, time and inode,
# is left as 6d and 6e left it, so section 7 still sees what the last deploy did.
touch -d "$(cat "$SB/strayk.t")" "$PD/data"; rm -rf "$SB/strayk" "$SB/strayk.t" "$SB/strayk.ents"; set_sched "$SCHED0"
check "$(stray_snap)$(side_files)$(stray_locks_here)$(data_digest)" "${STRAY0}0$D2" "control: the stray folder as seeded once its time is put back — nothing else needed restoring — and master's schedule as seeded"

echo; echo "== 7. what the rehearsal left behind, and what it never reached =="
check "$(evo_calls)" "0" "Evolution never called in $(cat "$SB/runs") runs of the script — no instance read, created or paired, no message sent"
curl -s --noproxy '*' -o /dev/null "$EVO_URL/rehearsal-control"
check "$(evo_calls)$(cat "$SB/evo/requests.log" 2>/dev/null)" "1GET /rh-evo/rehearsal-control" "control on the control: the stand-in records the one request the rehearsal makes itself — a call would have been seen"
check "$(stray_snap)" "$STRAY0" "the stray store's folder ends the rehearsal exactly as seeded — through every deploy, check and rollback"
check "$(checkout_state)" "$CHECKOUT0" "this checkout is as the rehearsal found it: same commit, same tracked files"
check "$(ls -a "$REPO/scripts" | grep -cE '^\..+\.sh$')" "0" "no weakened or re-pinned copy is left in the clone's scripts/"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )" "" "every weakened copy of the script and every edit of an installed file found its anchor exactly once — none was skipped"
M="$(mutant anchorprobe "$DEPLOY" 'NO SUCH ANCHOR IN THE SCRIPT' 'x' 2>/dev/null)"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )$(test -e "$M" && echo made || echo none)" "mutant:anchorprobe none" \
  "control on the control: a weakened copy whose anchor is missing is recorded as such, and no copy is made"
rm -f "$SB/anchor-missing"
check "$(left_tmp)" "$TMP0" "no copy of the code and no throwaway layout is left in /tmp ($TMP0 before the rehearsal)"
check "$(grep -c 'Fatal\|Parse error' "$SB/web.log")" "0" "the stand-in served every request without a PHP fatal"

echo; echo "rehearsal: $PASS passed, $FAILN failed ($(cat "$SB/runs") runs of the script)"
[ "$FAILN" = "0" ]
