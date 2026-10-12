#!/usr/bin/env bash
#
# Rehearse scripts/quarantine-stray-store.sh — the stray store's quarantine, its --check and its --put-back — before the
# operator runs any of it (docs/07, "Stray-store quarantine").
#
# The sandbox is 5.18.93's rehearsal's, built the same way (scripts/harness/deploy-5.18.93/rehearse.sh, its edits asserted
# by the builder): 5.18.92 installed as production runs it since 10 Oct, the salesperson pilot as the operator left it,
# THE STRAY STORE inside the plugin folder untouched since 5.18.91's copy, 5.18.92's deploy record — and then the REAL
# deploy of 5.18.93 (DEPLOY typed), its copy moved a day back, master's record of a run of the retry job since, and the
# REAL day-later --after-only, its log written where the operator's tee writes it. Then the plugin's files are given to
# the server's owner (1000:1000) and the fake docker honours -u from there on, so the gate's question and every holder
# control run as the database's owner, as on the server. The quarantine script runs as the operator runs it: through
# `bash … 2>&1 | tee …` in a pseudo-terminal, the word typed at the prompt; a copy pinned to this sandbox's stray store
# (its two literals — the server's checksum, size and time, and its folder's time — replaced, each exactly once).
#
# This rehearsal proves:
#   · stage A refuses, before anything changes, each clause planted here on its own (Q1), and for most of them a copy of the
#     script blind to that clause reaches GO (Q2: caught) — not planted: no stray store at all, unreadable keys, 5.18.93's
#     record missing or without STRAY_COPY, STRAY_COPY_AT or STRAY_ENTS, the stray store not a regular file, a folder named
#     like a data folder on the quarantine folder's path; planted without a blind copy: 1d, 1w–1w'', 1y'', 1z', 1z''; every
#     stop before the move prints the rule that binds until it, and a copy that does not is
#     caught; master's record of the retry job unreadable is refused at A8; one run at a time — with the folder's lock held,
#     every mode stops at once, and a copy without the lock runs beside it (caught); a Ctrl-C during the holders control
#     leaves no throwaway folder (a copy without its trap does: caught);
#   · stage B's own reads refuse a change made while the prompt waits — a holder, the live commit, the configuration, an
#     entry, the file's change time, a gate file, master running the job (it waits 90 s first), a side name of the kept
#     file, master's record unreadable — and a copy blind to the holder, the gate, the folder, the running job or the
#     unreadable record reaches the link (QB: caught); a running record over 15 minutes old is noted as a dead run's;
#   · every state a stopped run can leave has the one command the record and the files name, reached by the REAL script
#     killed before the link, after it, before the unlink and after it, and in the put-back after its link and after its
#     unlink; --check calls each NOT CONCLUSIVE with that command, never PASSED (Q8); a put-back whose link is refused takes
#     its marker back (a copy that does not switches --check off: caught); an immutable kept file is refused before the word;
#   · a Ctrl-C and a hang-up of the operator's pipeline in the middle of the move do not stop it, and its own log keeps every
#     line (Q3); a holder that opens the file between stage B's last read and the unlink FAILS stage C, is recorded, and no
#     put-back command is printed (a copy blind to C6 PASSES: caught); a container that recycles after the move is waited
#     out, or read as not measured — never a lasting failure (a copy that reads a runtime's error text as a commit: caught);
#     a gate opened between stage B and stage C FAILS C8, and the put-back then refuses (a copy without that refusal puts
#     the file back under an open gate: caught); a gate probe that cannot answer is NOT CONCLUSIVE in stage C and --check,
#     and refused by the put-back;
#   · the quarantine moves exactly one name: the kept file is the same inode, bytes, owner, mode, times and birth; the
#     folder's entries less the one; the live database, the configuration and the vault untouched; no php or python
#     process opens either name (strace, with controls) (Q3, Q4);
#   · --check is conclusive only after a completed run of the retry job past the move (+120 s, never later than now), and
#     NOT CONCLUSIVE before (a copy whose verdict keys on failures alone says PASSED: caught); a record with no move time,
#     a stage the script never writes, none, or one the files contradict is never "held"; the INSTALLED job in a master
#     emulation makes nothing, 5.18.90's job makes a store and --check FAILS and keeps failing; a copy blind to any of
#     K4, K5, K6 or K10 calls the planted state held (caught); getDataDir's rescue copy and cliDataDir's refusal behave as
#     the quarantine is for (Q4);
#   · after the move every non-rollback run of deploy-5.18.91/92/93.sh fails R19 and records it (the runbook's rule,
#     measured), and the rollback to 5.18.92 passes with the store still absent (Q5);
#   · the put-back refuses over a new store, a changed kept file, a holder; it gives the same file its name back and
#     records the folder's new reference; a side file beside the restored store is said, not judged (Q6); a destination
#     on another filesystem, or behind a bind mount, moves nothing (Q7);
#   · a line appended to <plugin>/data/migration.log inside every holder read (cron/dpo_reconcile.php appends one every
#     five minutes) is never counted as a holder — not at A5, C6 or K10 (a copy that keeps it refuses at A5: caught), and
#     after the move C6 and K10 judge the kept file by its own state, not its old folder's (copies that do not FAIL for
#     good: caught) (Q3); the record's writes refused as on a full disk (strace's fault injection on that one file) at each
#     marker, before and after the two steps and in the put-back: each run stops or ends NOT CONCLUSIVE with one command,
#     never PASSED over a record it could not write, and a resumed run stops over its first run's recorded failure; a
#     stage-C FAIL on a disk that fills names one next step — never the quarantine command — and says whether the record
#     holds it; a run killed after a FAIL leaves it recorded (a copy that records it only at the end loses it: caught); a
#     write cut inside a line on a really full filesystem leaves the record as it was, and --check says when it cannot
#     record its own failure (Q8, Q4);
#   · a prompt left unanswered ends the run by itself and frees the lock (Q1); the put-back reads the two names again after
#     its word, and the gate again, sorted as before it — every arm (Q8, Q3); <plugin>/data replaced is no conclusion,
#     never a FAIL (Q4); with 5.18.91 live and the gate opened the put-back refuses, and after a rollback to 5.18.90
#     --check's one next step is the put-back, in its check and its verdict alike — only where the put-back can work, and
#     over a holder too, which the put-back reads itself — and the quarantine command, run again on a finished or a stopped
#     record, names it as well (Q6);
#   · nothing reaches Evolution; the clone, /tmp, /dev/shm and the script's own TMPDIR are left as found.
#
# Everything runs in a sandbox; it never touches this checkout, the server or the network, and refuses to run where the
# server could be. Everything seeded is fictitious.
#
#   bash scripts/harness/quarantine-stray-store/rehearse.sh            REHEARSE_KEEP=<dir> keeps every run's full output
set -u
R="$(cd "$(dirname "$0")/../../.." && pwd)"
for f in /data/ucrm /opt/dishnet /var/run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
BASE=247a480; RA_BASE=e076632; OLDER=dfad4d9; OLDER2=3d9cb5f; BRANCH_FILE=scripts/deploy-5.18.93.sh; P=dishnet-hybrid-sudan; RELEASE_BRANCH=release/5.18.93
# OLDER: 5.18.91, a server not yet on 5.18.92 (1a); OLDER2: 5.18.90, whose retry job has no gate (1n)
FIXDEV=1fb7415                                                                                # the change, on the branch
[ -n "${REHEARSE_KEEP:-}" ] && mkdir -p "$REHEARSE_KEEP"
MIG=migrations/087_wa_channels.sql; MIG86=migrations/086_install_authorisation.sql
MIG_SHA=3feda1b44ca79c1ad0057f029c032b741a581f4383e7378480129db1f1385aaa                           # the reviewed 087, applied 7 Oct
MASTER=cron/master.php; LOCK_TEST=tests/test_master_lock_release.php; MAIN=main.php; AP_TEST=tests/test_main_auto_pull_retired.php
MASTER92_SHA=5400be20c678d5ec87330e8c5f590f30f78e277787896792bb167556fcb145f1                      # 5.18.92's master.php — the pin's and the base's, byte for byte
JOB=cron_starlink_block_retry.php; SLR_LIB=lib/StarlinkRetryScope.php
JOB91_SHA=6da76a52792d0adf3dddade9e0335985f37e35e877c64d2333d8b53ef2f38ffe                         # 5.18.91's retry job, gated
GATE_CALL='    return StarlinkRetryScope::skip(__DIR__);'                                             # 5.18.91: the gate's question
LOCK_OWN='if (is_resource($_m_lockFp)) {'                                                             # 5.18.92: master's own release
MAIN92_SHA=9d770a9076aec7cea4e0a7d774eeba016444619dd5be1ab33a581b899cf6f773                        # 5.18.92's main.php — the base's: the nightly auto-pull in it
MAIN93_SHA=7be2a3ad3eeed9e9c4ac75aabe8e9c17a814ffe1bee982cbfee839f77d6ac1a9                        # 5.18.93's main.php — the pin's: the block a comment
SS_SIG="crm.lead.sync=done/0+unknown ai.reply=pending/0 ai.media=done/0+unknown wa.escalation=done/0+unknown install.ready=done/0+unknown"
UG_SIG="crm.lead.sync=pending/0 ai.reply=pending/0 ai.media=done/0+unknown wa.escalation=done/0 install.ready=done/0+unknown"
PS_SIG="cases=12 same=12 differs=- control=same base-ignores=no"
POL_SIG="policy=off/off refusal=-/- sql=-/- evo=off,-/off,- statements=0 registry-reads=0"
SLR_SERVER="live=same inside=no store=read tenant=uganda skip=yes"
SLR_STOPS="data=live left=- stray=unchanged ledger=behind"
SLR_OPENS="data=plugin left=config,configFile,extResult,notify,pdo,pluginDir,retryResult,store,svc stray=changed ledger=level"
SHAPE_ON="sales:in=sales support:in=support account:in=support shared=support+account others=1:1 evo=yes registry=on"     # production's since 10 Oct: support and account share, sales-001 routed, the registry in effect
SHAPE_OFF="sales:in=sales support:in=support account:in=support shared=support+account others=1:0 evo=yes registry=off"   # the same with the pilot off (1o, 6c): sales-001 held in the registry, not routed
# What a log with the pilot ON may never say (5.18.92's words, written for the registry off):
STALE_RX='registry (is |stays )?dark|is not dark|reads OFF in both copies|must read OFF|none switched on|registry being off|registry is off|with the registry off|switch is off|with the switch off|policy is inert|absent,? as here|switch the pilot on AFTER'
# The guards the pilot's weakened copies take out, each exactly as scripts/deploy-5.18.93.sh has it — kept here, one place
# to look when a check is reworded; an anchor not found is recorded and fails section 7, never skipped:
G_A0_DELTA='[ -z "$STRAY" ] || stop'                                                # A0: no file outside the delta
G_A6_POLICY='[ "$P90_PIN" = "$P90_SIGNATURE" ] || A6_BAD="$A6_BAD send-policy"'    # A6: the pin's policy on a throwaway database
G_A11='[ "$POL_P" = "$POL_A" ] || stop'                                             # A11: the pin's policy reads as the live code's, here
G_V3F='elif [ "$MN_AFTER" != "$MN_BEFORE" ]; then bad "V3f'                          # V3f: the registry's switch as stage A read it
G_V3H='[ "$a" = "$b" ] || V3H_BAD='                                                 # V3h: 5.18.89's three switches as stage A read them
G_R3_ROWS='[ "$st" = "${WA87_BEFORE:-}" ] || bad87='                                  # R3: 087's rows, every column, as stage A read them
G_R3_TRAIL='[ "$st" = "${WA87_BEFORE:-}" ] || bad87='                                 # R3: 087's trail as stage A read it — the same comparison: the run adds no row
G_A0_MISSING='[ -z "$MISSING" ] || stop'                                            # A0: every file of the release in the delta
G_A0_MASTER='[ "$M_PIN_SHA" = "$MASTER_PIN_SHA256" ] || stop'                       # A0: the pin's master.php 5.18.92's
G_A0_MAIN='[ "$MAIN_PIN_GOT" = "$MAIN_PIN_SHA256" ] || stop'                        # A0: the pin's main.php the reviewed release file
G_A15='[ "$LT_A" = "$LOCK_TEST_SIGNATURE" ] || {'                                   # A15: the pin's test of master's lock on the server's PHP
G_A16='[ "$AP_A" = "$AP_TEST_SIGNATURE" ] || {'                                     # A16: the pin's test of the retired auto-pull on the server's PHP
G_V4_WHERE='case "$v4w" in'                                                          # V4: the old fatal known by its file:line…
G_V4_TIME='&& { [ "$MODE" = "rollback" ] || { [ -n "$V4_COPY_T" ] && [ "$v4e" -gt 0 ] && [ "$v4e" -le $((V4_COPY_T + V4_OLD_MAX)) ]; }; }; then'   # …and within 30 minutes of the copy
LT_SIG="78 passed, 0 failed rc=0"
AP_SIG="47 passed, 0 failed rc=0"
EXPECT_N=10; EXPECT_AD=1
# 5.18.92's own nightly fatal, as main.php's handler logs it on the server (docs/07, 11 Oct): a run of the pull hour that
# skipped the wallet sync (:484), and one that had it (:509); and 5.18.91's, which 5.18.92 ended (4l: no longer old).
OLDFATAL='[DishNet UNCAUGHT] Value of type null is not callable in /data/ucrm/data/plugins/dishnet-hybrid-sudan/main.php:484'
OLDFATAL509='[DishNet UNCAUGHT] Class "DishNet\SqliteStore" not found in /data/ucrm/data/plugins/dishnet-hybrid-sudan/main.php:509'
FATAL405='NOTICE: PHP message: PHP Fatal error:  Uncaught TypeError: flock(): supplied resource is not a valid stream resource in /data/ucrm/data/plugins/dishnet-hybrid-sudan/cron/master.php:405'
checkout_state() { { git -C "$R" rev-parse HEAD; git -C "$R" status --porcelain --untracked-files=no; git -C "$R" diff HEAD; } | sha256sum | cut -c1-16; }
CHECKOUT0="$(checkout_state)"
left_tmp() { ls -d /tmp/dnb-5.18.93-* /tmp/dn-master-lock-* /tmp/dn_autopull_* 2>/dev/null | wc -l | tr -d ' '; }
TMP0="$(left_tmp)"
SB="$(mktemp -d)"; PIDS=()
cleanup() { for p in "${PIDS[@]:-}"; do kill "$p" 2>/dev/null; done; [ -f "$SB/shim.pids" ] && while read -r p; do kill "$p" 2>/dev/null; done < "$SB/shim.pids"
  [ -n "${PD:-}" ] && chattr -i -a "$PD/data" "$PD/data/plugin.sqlite3" 2>/dev/null; case "${SHM:-}" in /dev/shm/tmp.*) rm -rf "$SHM" ;; esac; rm -rf "$SB"; }
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
check "$(git -C "$REPO" rev-parse --short "$PIN^" 2>/dev/null)" "$BASE" "control: the release commit is cut on $BASE (5.18.92), the live version"
ver() { git -C "$REPO" show "$1:$P/manifest.json" | python3 -c 'import json,sys; print(json.load(sys.stdin)["information"]["version"])'; }
check "$(ver "$PIN")" "5.18.93" "the plugin at the pin is 5.18.93"
check "$(ver "$BASE")" "5.18.92" "control: the base is 5.18.92"
dl() { git -C "$REPO" diff --no-renames --name-only --diff-filter="$1" "$2" "$3" -- $P; }
CHANGED="$(dl AM "$BASE" "$PIN")"
N_CH="$(grep -c . <<<"$CHANGED")"; N_AD="$(dl A "$BASE" "$PIN" | grep -c .)"; N_MO=$((N_CH - N_AD)); N_DEL="$(dl D "$BASE" "$PIN" | grep -c .)"
echo "  5.18.93   $N_CH files: $N_MO changed, $N_AD added · $N_DEL removed or renamed"
check "$N_DEL" "0" "control: 5.18.92 → 5.18.93 removes no file"
check "$N_AD|$(dl A "$BASE" "$PIN")" "$EXPECT_AD|$P/$AP_TEST" "control: 5.18.93 adds one file — its test of the retired auto-pull"
check "$N_CH" "$EXPECT_N" "control: $EXPECT_N files in the delta"
TAKEN="$MAIN manifest.json $AP_TEST tests/test_tick_auto_pull_log.php tests/test_kyc_crm_create.php tests/test_distributor_apply.php tests/test_distributor_link_ucrm.php tests/test_distributor_notify.php tests/test_distributor_registry.php tests/test_distributor_territory.php"
for want in $TAKEN; do
  check "$(grep -c "^$P/$want$" <<<"$CHANGED")" "1" "control: the delta includes $want"
done
check "$(grep -c "^$P/migrations/" <<<"$CHANGED")$(git -C "$REPO" show "$PIN:$P/$MIG" | sha256sum | cut -c1-64)" "0$MIG_SHA" "control: no migration in the delta — 087 at the pin the reviewed file (sha256 ${MIG_SHA:0:16}…)"
check "$(git -C "$REPO" diff --name-only "$BASE" "$PIN" | grep -vc "^$P/")" "0" "control: the whole repository's delta is the plugin's files alone"
check "$(git -C "$REPO" rev-parse "$BASE:$P/dishnet-mikrotik-control-plane")$(git -C "$REPO" rev-parse "$BASE:$P/docs")" \
      "$(git -C "$REPO" rev-parse "$PIN:$P/dishnet-mikrotik-control-plane")$(git -C "$REPO" rev-parse "$PIN:$P/docs")" "control: Domain B's tree and the plugin's docs are the same trees at the base and the pin"
same_as_fix() { [ "$(git -C "$REPO" rev-parse "$PIN:$P/$1" 2>/dev/null)" = "$(git -C "$REPO" rev-parse "$FIXDEV:$P/$1" 2>/dev/null)" ] && echo same || echo differs; }
check "$(same_as_fix "$MAIN")$(same_as_fix "$AP_TEST")$(same_as_fix tests/test_tick_auto_pull_log.php)$(same_as_fix tests/test_kyc_crm_create.php)$(same_as_fix "$MASTER")" "samesamesamesamediffers" \
  "control: the pin's main.php, its test of the retired auto-pull, the tick test and the kyc test are the change's on the branch ($FIXDEV) byte for byte; master.php is not — the branch's carries the media job"
check "$(diff <(git -C "$REPO" show "$PIN:$P/$MASTER") <(git -C "$REPO" show "$FIXDEV:$P/$MASTER") | grep -E '^[<>]' | grep -vcE "ai_media|run_media_worker|Batch 1|'flag'|_m_flagVal|slow download|shipped state|the one it was before|a job that exists only|1/true/on/yes|^> *$|^> +}$|^> +if \(!in_array")" "0" \
  "control: the pin's master.php is the branch's without the media job — every other line the same"
check "$(git -C "$REPO" show "$PIN:$P/$MASTER" | grep -cE 'ai_media|run_media_worker')$(git -C "$REPO" show "$PIN:$P/$MASTER" | sed '/^ *\/\//d' | grep -cE '\$lockFp\b|\$lockFile\b')$(git -C "$REPO" show "$PIN:$P/$MASTER" | grep -cF -- "$LOCK_OWN")" "002" \
  "control: at the pin master.php carries no media job, names no bare \$lockFp or \$lockFile outside its comments, and guards both of its own releases — the handler's and the normal end's"
check "$(git -C "$REPO" show "$PIN:$P/$MASTER" | sha256sum | cut -c1-64)$(git -C "$REPO" show "$BASE:$P/$MASTER" | sha256sum | cut -c1-64)$(git -C "$REPO" rev-parse "$PIN:$P/$LOCK_TEST")" \
      "$MASTER92_SHA$MASTER92_SHA$(git -C "$REPO" rev-parse "$BASE:$P/$LOCK_TEST")" "control: master.php is 5.18.92's byte for byte at the pin and at the base, and the lock test the base's — 5.18.93 changes neither"
check "$(git -C "$REPO" show "$PIN:$P/$JOB" | sha256sum | cut -c1-64)$(git -C "$REPO" show "$BASE:$P/$JOB" | sha256sum | cut -c1-64)" "$JOB91_SHA$JOB91_SHA" "control: the retry job is 5.18.91's at the pin and the base — the gate unchanged"
HEADER_CMD="$(sed -n '/^# Run as root/,/^# The rollback is a separate command/p' "$DEPLOY")"
check "$(grep -c 'deploy-5.18.93.sh 2>&1 | tee' <<<"$HEADER_CMD")$(grep -cE -- '--rollback|--key|--value|set_config|git checkout [0-9a-f]{7}' <<<"$HEADER_CMD")" "10" \
  "the header's deploy command stands alone: no rollback, no switch command and no checkout of another commit in its block (docs/44 §16.9)"
check "$(grep -c "git fetch origin $RELEASE_BRANCH" <<<"$HEADER_CMD")$(grep -c 'deploy-5.18.93.sh --after-only 2>&1 | tee' <<<"$HEADER_CMD")" "11" "the header's deploy command fetches the release branch; the later --after-only is its own command"
SWITCH_KEYS='customer_wa_install_scheduled|install_auth_enabled|multi_number_channels_enabled|ai_lead_capture|ai_crm_lead_sync|ai_qualification|ai_sales_on_all_numbers|sales_own_leads_only|wa_handover_copy_central|wa_followups_on_owned_numbers|ucrm_auto_pull_enabled|ucrm_auto_pull_hour'
check "$(grep -cE -- "--key +($SWITCH_KEYS)|($SWITCH_KEYS) +--value" "$DEPLOY")" "0" "the script carries no command that switches a feature, the registry, own leads only, a lead switch or the retired auto-pull's settings — neither on nor off"
check "$(grep -cE -- '(rm|mv|cp|unlink|rename)[^|;]*\$DEST/data|\$DEST/data[^ ]*\.(quarantine|bak)' "$DEPLOY")$(grep -cE -- 'tar -C "\$DEST" -czf "\$BK/stray-data\.tar\.gz" data' "$DEPLOY")" "01" \
  "the script never moves, copies over, renames or deletes the stray store's folder — it only reads it, as files, into the backup (the control: that read is there)"
R19S="$(sed -n '/^# R19 — the stray store/,/^# For the summary (F): what R19 established/p' "$DEPLOY")"
check "$(diff <(sed -n '/^# R19 — the stray store/,/^# For the summary (F): what R19 established/p' "$REPO/scripts/deploy-5.18.92.sh") <(printf '%s\n' "$R19S") | grep -E '^[<>]' | grep -vcE "5\.18\.91's (deploy|copy)|BASELINE_VERSION's (deploy|copy)")$( [ "$(grep -c . <<<"$R19S")" -gt 100 ] && echo read)" "0read" \
  "control: R19's judgement is 5.18.92's line for line — only its words for the earlier deploy now name it by its version (the full matrix was rehearsed for 5.18.91); the section was read (over 100 lines)"
# ── The container: 5.18.92 installed AS PRODUCTION RUNS IT since the 10 Oct deploy, its data beside it, the stray store in it ─
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
# (.plant_fatal), 5.18.92's own nightly fatal at main.php:484 or :509 (.plant_old, .plant_old509), the WhatsApp AI
# screen answering a visitor with no session with the card (.leak_card), a switch of
# the pilot cleared in both copies (.flip_switch, which names the key), sales-001's assistant switched off in its row with
# no trail row (.chg_087), a trail row added with no row changed (.add_trail), sales-001's display name one space longer —
# a column no count shows (.chg_col). Each trigger is spent once.
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
if ($spend('.plant_old')) {   // 5.18.92's own nightly fatal, as main.php's handler logs it: a run of the pull hour, no wallet sync
    file_put_contents($clog, gmdate('Y-m-d\TH:i:s') . '.000000001Z [DishNet UNCAUGHT] Value of type null is not callable in /data/ucrm/data/plugins/dishnet-hybrid-sudan/main.php:484' . "\n", FILE_APPEND);
}
if ($spend('.plant_old509')) {   // …and its other line: a run of the pull hour that had the wallet sync
    file_put_contents($clog, gmdate('Y-m-d\TH:i:s') . '.000000001Z [DishNet UNCAUGHT] Class "DishNet\SqliteStore" not found in /data/ucrm/data/plugins/dishnet-hybrid-sudan/main.php:509' . "\n", FILE_APPEND);
}
if ($spend('.plant_fatal')) {
    file_put_contents($clog, gmdate('Y-m-d\TH:i:s') . '.000000001Z PHP Fatal error:  Uncaught Error: planted by the rehearsal in /data/ucrm/data/plugins/dishnet-hybrid-sudan/cron/event_processor.php:1' . "\n", FILE_APPEND);
}
// The pilot changed DURING a run, after stage A read it: a switch cleared in both copies, as set_config.php's --clear
// clears it (.flip_switch names the key); sales-001's assistant switched off in its row, no trail row (.chg_087); a trail
// row added, no row changed (.add_trail); sales-001's display name one space longer, which no count shows (.chg_col).
$flip = is_file($web . '/.flip_switch') ? trim((string)file_get_contents($web . '/.flip_switch')) : '';
if ($flip !== '' && $spend('.flip_switch')) {
    $row = json_decode((string)$pdo->query("SELECT data FROM kyc_config WHERE id = 0")->fetchColumn(), true); unset($row[$flip]);
    $pdo->prepare("UPDATE kyc_config SET data = ? WHERE id = 0")->execute([json_encode($row)]);
    $cf = json_decode((string)file_get_contents($dataDir . '/kyc_config.json'), true); unset($cf[$flip]);
    file_put_contents($dataDir . '/kyc_config.json', json_encode($cf));
}
if ($spend('.chg_087')) $pdo->exec("UPDATE wa_channels SET ai_enabled = 0 WHERE channel_id = 'sales-001'");
if ($spend('.chg_col')) $pdo->exec("UPDATE wa_channels SET display_name = display_name || ' ' WHERE channel_id = 'sales-001'");
if ($spend('.add_trail')) {
    $pdo->exec("INSERT INTO wa_channel_log (channel_id, action, old_value, new_value, actor, reason) VALUES ('sales-001', 'ai_enabled', '1', '1', 'Rehearsal Admin (#1)', 'planted during the run')");
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
php -S "127.0.0.1:$WPORT" -t "$SB/web" >"$SB/web.log" 2>&1 & PIDS+=($!); WEB_PID=$!
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
seed_data() {      # the plugin's databases, written by 5.18.92's own store (084, 086 and 087 applied by its runner, as production's
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
installed_digest() {   # $1 a commit: the number of files 5.18.93 touches that are NOT installed as that commit has them
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

# ── The fake docker — strict: exec (-d, -i, -u, -e), cp into the container, logs, ps -aq, and inspect with exactly the
# formats the scripts use; anything else fails loudly, so a new call cannot pass silently. Its containers are a table:
# id|name|mounts (Destination=Source;…)|StartedAt|RestartCount. Once the plugin's files are the server's owner's
# ($SB/.server-owner), -u is honoured with setpriv, as the container's own user would be.
printf '%s\n' "ucrm|ucrm|/data=$MOUNT|2026-10-11T13:06:30.123456789Z|0" \
  "c0ffee000001|unms|/data=$SB/other/unms-data;/config=$SB/other/unms-config|2026-10-01T08:00:00.000000001Z|2" \
  "c0ffee000002|unms-postgres|/var/lib/postgresql/data=$SB/other/pg|2026-10-01T08:00:00.000000001Z|0" > "$SB/containers"
mkdir -p "$SB/other"
cat > "$SB/bin/docker" <<SH
#!/usr/bin/env bash
printf '%s\n' "\$*" >> "$SB/docker.log"
row() { grep "^\$1|" "$SB/containers" || grep "^[^|]*|\$1|" "$SB/containers"; }
case "\$1" in
  inspect)
    shift; fmt=""; names=()
    while [ \$# -gt 0 ]; do case "\$1" in --format|-f) fmt="\$2"; shift 2 ;; *) names+=("\$1"); shift ;; esac; done
    for n in "\${names[@]}"; do
      r="\$(row "\$n")" || { echo "Error: No such object: \$n" >&2; exit 1; }
      [ -n "\${FAKE_INSPECT_FAIL:-}" ] && [ "\$n" = "\$FAKE_INSPECT_FAIL" ] && { echo "Error: planted by the rehearsal" >&2; exit 1; }
      IFS='|' read -r id name mounts started restarts <<< "\$r"
      case "\$fmt" in
        '{{range .Mounts}}{{if eq .Destination "/data"}}{{.Source}}{{end}}{{end}}')
          printf '%s\n' "\$mounts" | tr ';' '\n' | sed -n 's#^/data=##p' ;;
        '{{.Name}}{{range .Mounts}}{{"\n"}}{{.Destination}}|{{.Source}}{{end}}')
          printf '/%s\n' "\$name"
          [ -n "\${FAKE_PAIRS_HIDE:-}" ] && [ "\$name" = "\$FAKE_PAIRS_HIDE" ] && continue
          printf '%s\n' "\$mounts" | tr ';' '\n' | grep . | sed 's/=/|/' ;;
        '{{.State.StartedAt}} {{.RestartCount}}') printf '%s %s\n' "\$started" "\$restarts" ;;
        *) echo "fake docker: unsupported inspect format: \$fmt" >&2; exit 99 ;;
      esac
    done
    exit 0 ;;
  ps) [ "\${2:-}" = "-aq" ] && [ \$# -eq 2 ] && { [ -n "\${FAKE_PS_FAIL:-}" ] && exit 1; cut -d'|' -f1 "$SB/containers"; exit 0; }
      echo "fake docker: ps \$* is not supported" >&2; exit 99 ;;
  logs) cat "$SB/container.log" 2>/dev/null; exit 0 ;;
  cp) [ -n "\${FAKE_CP_FAIL:-}" ] && { echo "fake docker: cp refused (rehearsal control)" >&2; exit 1; }
      src="\$2"; dst="\${3#*:}"; case "\$dst" in /data/*) dst="$MOUNT/\${dst#/data/}" ;; esac
      cp -a "\$src" "\$dst"; exit \$? ;;
  exec) shift ;;
  *) echo "fake docker: \$1 is not supported" >&2; exit 99 ;;
esac
detach=""; user=""
while [ \$# -gt 0 ]; do case "\$1" in
  -i) shift ;;
  -d) detach=1; shift ;;
  -u) user="\$2"; shift 2 ;;
  -e) kv="\$2"; v="\${kv#*=}"; case "\$v" in /data/*) v="$MOUNT/\${v#/data/}" ;; esac; export "\${kv%%=*}=\$v"; shift 2 ;;
  -*) echo "fake docker: exec \$1 is not supported" >&2; exit 99 ;;
  *) break ;;
esac; done
row "\$1" >/dev/null || { echo "Error: No such container: \$1" >&2; exit 1; }
shift
args=(); for a in "\$@"; do case "\$a" in /data/*) args+=("$MOUNT/\${a#/data/}") ;; /usr/local/etc|/usr/local/etc/*) args+=("$SB/etc\${a#/usr/local/etc}") ;; *) args+=("\$a") ;; esac; done
pre=()
if [ -n "\$user" ] && [ -f "$SB/.server-owner" ]; then pre=(setpriv --reuid "\${user%%:*}" --regid "\${user#*:}" --clear-groups --); fi
if [ -n "\$detach" ]; then
  [ -n "\${FAKE_EXEC_D_FAIL:-}" ] && { echo "fake docker: exec -d refused (rehearsal control)" >&2; exit 1; }
  "\${pre[@]}" "\${args[@]}" </dev/null >/dev/null 2>&1 & exit 0
fi
# Once the stray store's old name is gone: the gate's probe refused (FAKE_GATE_FAIL_ON_MOVE, or while $SB/gate-fail exists —
# made by a hook while a prompt waits), or answering a planted line (FAKE_GATE_LINE, or the content of $SB/gate-line)
# (FAKE_GATE_LINE), or the container recycling
# for N seconds from the first exec after the move (FAKE_RECYCLE_ON_MOVE=N) — a runtime's text on standard output and
# exit 126, or with FAKE_RECYCLE_STDERR the daemon's refusal on standard error and exit 1. FAKE_KILL_ON_MOVE=<script>:
# at the first container call after the move, once, the script and its subshells killed (SIGKILL — as a reboot or the OOM
# killer would), by their command line, which starts "bash <script>" (never the pseudo-terminal's runner or its shell).
if [ ! -e "$PD/data/plugin.sqlite3" ] && [ ! -L "$PD/data/plugin.sqlite3" ]; then
  if [ -n "\${FAKE_KILL_ON_MOVE:-}" ] && [ ! -f "$SB/killed-on-move" ]; then : > "$SB/killed-on-move"; pkill -KILL -f "^bash \$FAKE_KILL_ON_MOVE( |\$)"; exit 1; fi
  if [ -n "\${FAKE_GATE_FAIL_ON_MOVE:-}" ] || [ -f "$SB/gate-fail" ]; then case " \$* " in *" display_errors=stderr "*) echo "fake docker: the gate's probe refused (rehearsal control)" >&2; exit 1 ;; esac; fi
  if [ -f "$SB/gate-line" ]; then case " \$* " in *" display_errors=stderr "*) cat "$SB/gate-line"; echo; exit 0 ;; esac; fi
  if [ -n "\${FAKE_GATE_LINE:-}" ]; then case " \$* " in *" display_errors=stderr "*) printf '%s\n' "\$FAKE_GATE_LINE"; exit 0 ;; esac; fi
  if [ -n "\${FAKE_RECYCLE_ON_MOVE:-}" ]; then
    [ -f "$SB/recycle-until" ] || echo \$(( \$(date +%s) + FAKE_RECYCLE_ON_MOVE )) > "$SB/recycle-until"
    if [ "\$(date +%s)" -lt "\$(cat "$SB/recycle-until")" ]; then
      if [ -n "\${FAKE_RECYCLE_STDERR:-}" ]; then echo "Error response from daemon: Container ucrm is restarting" >&2; exit 1; fi
      printf 'NotFound: task %s not found\r\n' 3f9c2d1e0b7a; exit 126
    fi
  fi
fi
exec "\${pre[@]}" "\${args[@]}"
SH
chmod +x "$SB/bin/docker"

run() {   # [--answer WORD] [--script FILE] [--env K=V]…, then the script's own options — through a pseudo-terminal
  local answer="" script="$DEPLOY" extra=()
  while :; do case "${1:-}" in --answer) answer="$2"; shift 2 ;; --script) script="$2"; shift 2 ;; --env) extra+=("$2"); shift 2 ;; *) break ;; esac; done
  : > "$SB/docker.log"
  local n; n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"
  local cmd; cmd="$(printf '%q ' env PATH="$SB/bin:$PATH" DNB_OUT="$SB/out" DNB_PREV_STATE="$PREV" GUARD_SECONDS=0 DN_DATA_DIR="$DATA" ${extra[@]+"${extra[@]}"} bash "$script" --plugin-base "$PLUGIN_BASE" "$@")"
  local o; o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r')"
  case "$o" in *"unknown option:"*) echo "run-$n" >> "$SB/bad-runs" ;; esac   # a refused run must never read as a pass
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
release_variant() {   # release_variant add|addroot|revert|edit PATH [OLD NEW] — a commit cut on 5.18.92 holding the release's tree
                      # with one file planted (in the plugin, or anywhere in the repository), put back as the base has it, or edited
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
LEAK_RX='rh-evo|rh-key-secret|rh-sales|rh-shared|rh-moved|rh-seller|rh-two|Rehearsal Seller|Rehearsal Admin'   # the address, the key, every instance, and the people the card's actions name

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
NUM_RX='2567000003[0-9]{2}'   # the numbers the rehearsal's Evolution report names — sales, support, sales-001: never printed by the script
# Evolution's report as the card reads it on 10 Oct, fictitious: the three instances connected, each paired with its number.
EVO_REPORT='[{"name":"rh-sales","n":"256700000301"},{"name":"rh-shared","n":"256700000302"},{"name":"rh-seller","n":"256700000357"}]'
card() {   # card ACTION CHANNEL [KEY=VALUE]… — one press on the Salesperson numbers card, through the INSTALLED
           # SalesNumbersAdmin::handle, as a signed-in admin, on the store row as public.php reads it. Evolution's report is
           # given to the card in memory, and a webhook registration answered OK in memory: nothing goes over the network.
           # Prints "ok|<the card's answer>" or "no|<…>".
  php -r '
    foreach (["bootstrap_data", "StoreInterface", "JsonStore", "SqliteStore", "SalesNumbersAdmin"] as $l) require_once $argv[1] . "/lib/$l.php";
    class RehearsalEvo extends EvolutionApiService {
      public static $report = [];
      public function listInstances(): array {
        $o = []; foreach (self::$report as $r) $o[] = ["name" => $r["name"], "state" => "open", "connected" => true, "phone" => $r["n"], "profile" => "", "jid_phone" => $r["n"]];
        return $o;
      }
      public function setWebhook(string $instance, string $url, array $events = []): array { return ["ok" => true]; }
    }
    RehearsalEvo::$report = json_decode($argv[3], true);
    $s = SqliteStore::create($argv[2]); $c = (array)$s->load("kyc_config.json");
    $post = ["channel_id" => $argv[5], "reason" => "rehearsal: docs/66"];
    foreach (array_slice($argv, 6) as $kv) { $x = explode("=", $kv, 2); $post[$x[0]] = $x[1] ?? ""; }
    $r = (new SalesNumbersAdmin($s->getPdo(), $s, $c, $argv[2], new RehearsalEvo($c)))->handle($argv[4], $post,
        ["is_admin" => true, "name" => "Rehearsal Admin", "id" => 1], function () { return "https://plugin.example.test/rh-webhook"; });
    echo $r["ok"] ? "ok|" : "no|", $r["text"];' "$PD" "$DATA" "$EVO_REPORT" "$@" 2>&1
}
wa_state() {   # every channel as the card leaves it — id/instance/status/assistant/number/verified — the harness's own view
  sq "SELECT channel_id || '/' || ifnull(evo_instance,'NULL') || '/' || status || '/ai' || ai_enabled || '/' || CASE WHEN business_number IS NULL THEN 'none' ELSE 'number' END || CASE WHEN verified_at IS NULL THEN '' ELSE '+verified' END FROM wa_channels ORDER BY channel_id"; }
wa_trail() { sq "SELECT channel_id || ':' || action FROM wa_channel_log ORDER BY id"; }   # the trail, oldest first: which channel, what — never a value
reported() { sq "SELECT COUNT(*) FROM wa_evo_numbers"; }   # the numbers Evolution reported, as the card's verifications record them
pilot_policy() {   # the INSTALLED automated-send policy on the store row, computed here independently: on|off, then why nothing
                   # automated may go — sales-001 to a customer, sales-001 to the sales number, the sales number to sales-001's
  php -r 'foreach (["bootstrap_data", "StoreInterface", "JsonStore", "SqliteStore", "ChannelRegistry", "EvolutionApiService", "AutomationPolicy"] as $l) require_once $argv[1] . "/lib/$l.php";
    $s = SqliteStore::create($argv[2]); $p = AutomationPolicy::forInstall((array)$s->load("kyc_config.json"), $argv[2], $s->getPdo(), $s);
    $w = function ($x) { return $x === "" ? "-" : $x; };
    echo $p->active() ? "on" : "off", "|", $w($p->refusal("sales-001", "+256700000999")), "|", $w($p->refusal("sales-001", "+256700000301")), "|", $w($p->refusal("sales", "+256700000357"));' "$PD" "$DATA" 2>&1; }
snap_save() { rm -rf "$SB/snap-$1"; mkdir -p "$SB/snap-$1"; cp -p "$DATA"/plugin.sqlite3* "$DATA/kyc_config.json" "$SB/snap-$1/"; }   # snap_save NAME — the database and the configuration file as they are
snap_load() { rm -f "$DATA"/plugin.sqlite3*; cp -p "$SB/snap-$1"/plugin.sqlite3* "$SB/snap-$1/kyc_config.json" "$DATA/"; }      # snap_load NAME — …put back exactly
file_unset() {   # file_unset KEY — remove a key from the configuration file ONLY; the store row keeps it
  php -r '$f = $argv[1]; $c = json_decode((string)file_get_contents($f), true); unset($c[$argv[2]]); file_put_contents($f, json_encode($c));' "$DATA/kyc_config.json" "$1"; }
line_with() {   # line_with ok|FAIL OUTPUT ID FRAGMENT… — yes when one of the run's "ok" (or "FAIL") lines of check ID carries
                # every FRAGMENT: the wording is the script's own; what is pinned here is the verdict and the facts it names
  local kind="$1" out="$2" id="$3" pre l f all; shift 3
  if [ "$kind" = ok ]; then pre="  ok    $id "; else pre="  FAIL  $id "; fi
  while IFS= read -r l; do
    case "$l" in "$pre"*) ;; *) continue ;; esac
    all=yes; for f in "$@"; do case "$l" in *"$f"*) ;; *) all=no; break ;; esac; done
    [ "$all" = yes ] && { echo yes; return; }
  done <<<"$out"
  echo no
}
okline()   { line_with ok "$@"; }
failline() { line_with FAIL "$@"; }
pilot_trial() {   # pilot_trial TRIGGER CONTENT [run options…] — an --after-only run with a stand-in trigger planted; prints the run's
                  # log, then "trigger=spent" or "trigger=unspent". The database and both copies of the configuration are put
                  # back afterwards.
  local trig="$1" content="$2" out; shift 2
  db_keep; cfg_keep; printf '%s' "$content" > "$SB/web/$trig"; base_log
  out="$(run "$@" --after-only)"
  if [ -e "$SB/web/$trig" ]; then rm -f "$SB/web/$trig"; out="$out
trigger=unspent"; else out="$out
trigger=spent"; fi
  db_back; cfg_back
  printf '%s' "$out"
}

# ── 5.18.91: the stray store inside the plugin folder, master's schedule, the installed job's time ─────────────────
row_set() {   # row_set KEY VALUE — a key in the store row ONLY; the configuration file keeps its own
  php -r '$p = new PDO("sqlite:" . $argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $r = json_decode((string)$p->query("SELECT data FROM kyc_config WHERE id = 0")->fetchColumn(), true); $r[$argv[2]] = $argv[3];
    $p->prepare("UPDATE kyc_config SET data = ? WHERE id = 0")->execute([json_encode($r)]);' "$DATA/plugin.sqlite3" "$1" "$2"; }
STRAY_OPEN='foreach (["StoreInterface", "JsonStore", "SqliteStore"] as $l) require_once $argv[1] . "/lib/$l.php"; $s = SqliteStore::create($argv[2]); $s = null; echo "done";'
seed_stray() {   # the stray store as the server has it (docs/07, 09 Oct): inside the plugin folder, made by the installed plugin's
                 # own store, at every migration as the live one is — so an open of it changes neither its database nor its
                 # migration.log, only its folder (the -wal and -shm made and removed); its own settings file; all of it, the
                 # folder included, last changed thirty hours ago
  php -r "$STRAY_OPEN" "$PD" "$PD/data" >/dev/null; php -r "$STRAY_OPEN" "$PD" "$PD/data" >/dev/null
  printf '{"note":"the stray folder settings file"}' > "$PD/data/config.json"
  find "$PD/data" -type f -exec touch -d '-30 hours' {} +; touch -d '-30 hours' "$PD/data"
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

install_base; seed_data; seed_stray; vault UGX; fresh
# master's own record of its last completed run of the retry job (master_schedule in the live store): five seconds before
# the stray folder's last change — the last open of it, by 5.18.90's job, before 5.18.91 was installed (docs/07, 10 Oct)
SCHED0=$(( $(stat -c %Y "$PD/data") - 5 )); set_sched "$SCHED0"
flip --on   # enable the pilot through the real tool, exactly as the live server has it
for i in $(seq 1 50); do [ "$(standin_page customer_login)" = "200" ] && break; sleep 0.1; done
for i in $(seq 1 50); do evo_ready && break; sleep 0.1; done
S0="$(setcfg ai_crm_lead_sync 0)"
# The 07 Oct pilot as production's record has it: one salesperson's number added through the card, switched off, then its
# assistant switched off — through the installed registry, each with its trail row. That is the state 5.18.92's deploy
# found on 10 Oct: kept as it is, to rehearse the pilot OFF beside the pilot on (1o, 1u, 6c).
PILOT="$(add_number sales-001 rh-seller)$(set_ai sales-001 0)"
snap_save off; D_OFF="$(data_digest)"
# Then the salesperson pilot as the operator ran it on 10–11 Oct (docs/66; docs/07, 10 Oct), in the operator's order, each
# step through the installed tools/set_config.php or the installed card: own leads only and the owned follow-ups ON; the
# registry refused — the department numbers not verified — then Verify number on the sales row and the support row (account
# shares support's instance, so support's number covers it), and the registry ON; sales-001 verified, its webhook
# registered, switched on with its assistant off, then its assistant on. The hand-over copy is never set (absent: ON).
P_OL="$(setcfg sales_own_leads_only 1)"; P_FH="$(setcfg wa_followups_on_owned_numbers 1)"; P_MN0="$(setcfg multi_number_channels_enabled 1)"
P_CARD="$(card sn_verify_department sales)
$(card sn_verify_department support)"
P_MN1="$(setcfg multi_number_channels_enabled 1)"
P_CARD="$P_CARD
$(card sn_verify sales-001)
$(card sn_webhook sales-001)
$(card sn_status sales-001 status=active)
$(card sn_ai sales-001 value=1)"
snap_save on
# 5.18.92's deploy record, as deploy-5.18.92.sh wrote it on the server on 10 Oct: the stray store before it and at its
# copy — exactly the folder as it is now, untouched since 5.18.91's copy — its A14 "seen", and the copy's time, after the
# folder's last change (A14 only prints it). The lines are the ones that script writes (the control below reads them out
# of it).
PREV="$SB/prev/state-5.18.92.env"; mkdir -p "$SB/prev"
prev_record() {   # prev_record [CTRL] — 5.18.92's deploy record over the stray store as it is now
  local l; l="$(stray_line)"
  printf 'DEPLOYED_AT=2026-10-10T21:32:00Z\nSTRAY_BEFORE=%s\nSTRAY_CTRL=%s\nSTRAY_ENTS=%s\nSTRAY_COPY=%s\nSTRAY_COPY_LOCKS=0\nSTRAY_COPY_AT=%s\n' \
    "$l" "${1:-seen}" "$(stray_entries_here)" "$l" "$(( $(stat -c %Y "$PD/data") + 480 ))" > "$PREV"
}
prev_record
D0="$(data_digest)"; STRAY0="$(stray_snap)"; STRAY_LINE0="$(stray_line)"
M87MD5="$(md5sum "$PD/$MIG" | cut -c1-32)"
check "$(inst_ver)$(sha256sum "$PD/$MASTER" | cut -c1-64)$(sha256sum "$PD/$JOB" | cut -c1-64)$(sha256sum "$PD/$MAIN" | cut -c1-64)" "5.18.92$MASTER92_SHA$JOB91_SHA$MAIN92_SHA" \
  "control: the installed base is 5.18.92 — its master.php, its gated retry job and its main.php, the auto-pull in it, byte for byte"
check "$(has "$S0" 'ai_crm_lead_sync = 0')$(both_copies ai_crm_lead_sync)$(both_copies ai_lead_capture)" "yes0011" \
  "control: the lead switches as production has them — capture ON, the uCRM write OFF"
check "$(has "$P_OL" 'sales_own_leads_only = 1')$(has "$P_FH" 'wa_followups_on_owned_numbers = 1')$(has "$P_MN0" 'Not yet: the department numbers are not all verified (sales, support). Nothing was saved.')$(has "$P_MN1" 'multi_number_channels_enabled = 1')" "yesyesyesyes" \
  "control: the pilot's switches set through the installed set_config.php, as on 10 Oct — the registry refused until the sales and support numbers were verified, then ON"
check "$(both_copies multi_number_channels_enabled)$(both_copies sales_own_leads_only)$(both_copies wa_followups_on_owned_numbers)$(both_copies wa_handover_copy_central)" "111111-" \
  "control: the registry, own leads only and the owned follow-ups ON in both copies; the hand-over copy never set (absent: ON)"
check "$(grep -c '^ok|' <<<"$P_CARD")$(grep -c '^no|' <<<"$P_CARD")" "60" \
  "control: the card's six presses each answered as on 10 Oct — sales and support verified, sales-001 verified, its webhook registered, switched on, its assistant on"
check "$PILOT|$(mig87)$(wa_objects)|$(wa_state)|$(reported)" "donedone|12:3:3|account/NULL/active/ai1/none,sales/NULL/active/ai1/number+verified,sales-001/rh-seller/active/ai1/number+verified,support/NULL/active/ai1/number+verified|3" \
  "control: 087 applied; the department rows with NO instance — sales and support verified, account covered by support's number — and sales-001 verified, switched on, its assistant on; the three numbers Evolution reported recorded as the card records them"
check "$(wa_trail)" "sales:seeded,support:seeded,account:seeded,sales-001:created,sales-001:ai_enabled,sales:number,sales:verified_instance,support:number,support:verified_instance,sales-001:number,sales-001:status,sales-001:ai_enabled" \
  "control: the trail holds 087's three rows, the 07 Oct add and assistant-off, and each of the 10 Oct card actions, in the operator's order"
check "$(pilot_policy)" "on|-|internal_recipient|internal_recipient" \
  "control: the installed policy is ON on this configuration — sales-001 may answer a customer, never the sales number, and the sales number never answers sales-001 (docs/66 step 5's AI-to-AI test)"
check "$(env PATH="$SB/bin:$PATH" DN_DATA_DIR="$DATA" bash "$SB/bin/docker" exec ucrm php "$IN_CONTAINER/tools/channels.php" 2>&1 | grep -cE '^ +multi_number_channels_enabled +ON$|^ +registry in effect +yes|^ +department numbers: all verified for their instances$')" "3" \
  "control: the installed tools/channels.php reads the registry ON, in effect, and every department number verified"
check "$(php -r '$u = json_decode((string)file_get_contents($argv[1]), true); echo array_key_exists("pluginDataDir", (array)$u) ? "has" : "none";' "$PD/ucrm.json")|$(stray_ledger)/$(sq "SELECT COUNT(*) FROM _migrations")" "none|$(sq "SELECT COUNT(*) FROM _migrations")/$(sq "SELECT COUNT(*) FROM _migrations")" \
  "control: ucrm.json names no pluginDataDir, and the stray store inside the plugin folder is at every migration, as the server's"
check "$(grep -cF "printf 'STRAY_COPY=%s\nSTRAY_COPY_LOCKS=%s\nSTRAY_COPY_AT=%s\n'" "$REPO/scripts/deploy-5.18.92.sh")$(grep -cF "printf 'STRAY_CTRL=%s\n'" "$REPO/scripts/deploy-5.18.92.sh")$(grep -c "^STRAY_COPY=$STRAY_LINE0\$" "$PREV")$(grep -c '^STRAY_CTRL=seen$' "$PREV")" "1111" \
  "control: 5.18.92's record is written in the lines deploy-5.18.92.sh writes — its stray store at its copy exactly the folder as it is now, its A14 'seen'"
check "$(standin_page customer_login)$(data_digest)$(stray_snap)" "200$D0$STRAY0" "control: the stand-in boots the installed store, and nothing it serves changes any data or the stray store"
check "$(evo_ready && echo up)$(evo_calls)" "up0" "control: the recording stand-in for Evolution is listening, and nothing has called it"
# The pin's own lock test, on this machine's PHP, from a copy shaped as A15's (the controls inside it included).
LTC="$SB/ltcopy"; mkdir -p "$LTC"
git -C "$REPO" archive "$PIN" $P/lib $P/cron $P/migrations $P/profiles "$P/cron_*.php" $P/run_worker.php $P/$LOCK_TEST | tar -x -C "$LTC"
check "$(php "$LTC/$P/$LOCK_TEST" 2>&1 | grep -E '^[0-9]+ passed, [0-9]+ failed$' | tail -1) rc=${PIPESTATUS[0]}" "$LT_SIG" "control: the pin's lock test passes from a copy shaped as A15's ($LT_SIG)"
rm -rf "${LTC:?}"
# The pin's own test of the retired auto-pull, on this machine's PHP, from a copy shaped as A16's (its controls — 5.18.92's
# block put back, dying at main.php:484 and :509 — included), with a TMPDIR of its own: the test copies the code into a
# folder there and must leave nothing behind.
APC="$SB/apcopy"; APT="$SB/aptmp"; mkdir -p "$APC" "$APT"
git -C "$REPO" archive "$PIN" $P/lib $P/cron $P/migrations $P/profiles "$P/cron_*.php" $P/run_worker.php $P/$MAIN $P/$LOCK_TEST $P/$AP_TEST | tar -x -C "$APC"
APO="$(TMPDIR="$APT" php "$APC/$P/$AP_TEST" 2>&1)"; APRC=$?
check "$(grep -E '^[0-9]+ passed, [0-9]+ failed$' <<<"$APO" | tail -1) rc=$APRC|$(ls -A "$APT" | wc -l | tr -d ' ')" "$AP_SIG|0" \
  "control: the pin's test of the retired auto-pull passes from a copy shaped as A16's ($AP_SIG), and leaves its TMPDIR empty"
rm -rf "${APC:?}" "${APT:?}"; unset APO APRC
# The pin's main.php is 5.18.92's with exactly the change, shown here independently of the derivation that wrote the
# script: 5.18.92's file with the time guard's log line shortened (" + auto-pull" gone) and the auto-pull block — from its
# rule line before "// AUTO-PULL UCRM CLIENTS CACHE  (daily …" to the line before "} // end time-guard else block" —
# replaced by the pin's comment block (its two rule lines and the blank line after) is the pin's, byte for byte. The
# controls: the log line alone does not give the pin's file, nor does the pin's with one other line changed.
cat > "$SB/maindiff.py" <<'PY'
import sys
base, pin, mode = open(sys.argv[1], encoding='utf-8').read(), open(sys.argv[2], encoding='utf-8').read(), sys.argv[3]
bl, pl = base.split('\n'), pin.split('\n')
one = lambda ls, s: [i for i, l in enumerate(ls) if l == s]
b0, b1 = one(bl, '// AUTO-PULL UCRM CLIENTS CACHE  (daily — default 03:00 server time)'), one(bl, '} // end time-guard else block')
p0 = one(pl, '// AUTO-PULL UCRM CLIENTS CACHE — RETIRED (5.18.93)')
if len(b0) != 1 or len(b1) != 1 or len(p0) != 1 or base.count(' + auto-pull') != 1: print('no-anchor'); sys.exit()
b0, b1, p0 = b0[0], b1[0], p0[0]
rule = pl[p0 - 1]
close = [i for i in range(p0 + 1, len(pl) - 1) if pl[i] == rule]
if not rule.startswith('// ══') or bl[b0 - 1] != rule or not close or pl[close[0] + 1] != '': print('no-anchor'); sys.exit()
if any(not x.startswith('//') for x in pl[p0 - 1:close[0] + 1]): print('code-in-block'); sys.exit()   # the replacement is comment lines only
log = [i for i, l in enumerate(bl) if ' + auto-pull' in l][0]
bl[log] = bl[log].replace(' + auto-pull', '', 1)
if mode == 'fix': bl = bl[:b0 - 1] + pl[p0 - 1:close[0] + 2] + bl[b1:]
print('same' if '\n'.join(bl) == pin else 'differs')
PY
git -C "$REPO" show "$BASE:$P/$MAIN" > "$SB/main92.php"; git -C "$REPO" show "$PIN:$P/$MAIN" > "$SB/main93.php"
sed 's/main\.php total execution/main.php total run/' "$SB/main93.php" > "$SB/main93x.php"
sed '/^\/\/ AUTO-PULL UCRM CLIENTS CACHE — RETIRED (5\.18\.93)$/a $planted = 1;' "$SB/main93.php" > "$SB/main93c.php"   # a statement inside the comment block
check "$(sha256sum "$SB/main92.php" | cut -c1-64)$(sha256sum "$SB/main93.php" | cut -c1-64)" "$MAIN92_SHA$MAIN93_SHA" \
  "control: main.php at the base is 5.18.92's, and at the pin the reviewed release file"
check "$(python3 "$SB/maindiff.py" "$SB/main92.php" "$SB/main93.php" fix)$(python3 "$SB/maindiff.py" "$SB/main92.php" "$SB/main93.php" log)$(cmp -s "$SB/main93.php" "$SB/main93x.php" || echo edited)$(python3 "$SB/maindiff.py" "$SB/main92.php" "$SB/main93x.php" fix)$(grep -c '^\$planted = 1;$' "$SB/main93c.php")$(python3 "$SB/maindiff.py" "$SB/main92.php" "$SB/main93c.php" fix)" "samediffersediteddiffers1code-in-block" \
  "control: the pin's main.php is 5.18.92's with exactly the change — the time guard's log line shortened, the auto-pull block replaced by its comment, nothing else, byte for byte, the block comment lines only; the log line alone, one other line changed, or a statement put inside the block, does not match"
rm -f "$SB/main92.php" "$SB/main93.php" "$SB/main93x.php" "$SB/main93c.php" "$SB/maindiff.py"

# ════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
# The quarantine's own rehearsal, on the sandbox built above.
# ════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
QFILE=scripts/quarantine-stray-store.sh
QS="$REPO/$QFILE"
if git -C "$R" ls-files --error-unmatch "$QFILE" >/dev/null 2>&1; then
  cmp -s "$R/$QFILE" "$QS" || { echo "refusing: $QFILE differs from the committed one — commit it, then rehearse"; exit 2; }
  QWHICH="the committed script"
else cp "$R/$QFILE" "$QS"; QWHICH="the working copy (not yet committed; untracked in the clone)"; fi
echo "  …    rehearsing $QWHICH"
STATE="$SB/out/state-5.18.93.env"
RECS="$SB/records"; QD="$RECS/dnb-stray-quarantine"; KEPT="$QD/stray-plugin.sqlite3.kept"; QREC="$QD/quarantine.env"; SRC="$PD/data/plugin.sqlite3"
mkdir -p "$RECS"; ln -s "$SB/out" "$RECS/dnb-5.18.93"; ln -s "$SB/prev" "$RECS/dnb-5.18.92"
SHM0="$(ls /dev/shm | grep -c '^tmp\.')"
copy_back() {   # the copy N minutes ago, as a later --after-only finds it: main.php's time, the copy's recorded time, and the
                # deploy's start a minute before it (V4 reads the log from there)
  touch -d "-$1 minutes" "$PD/$MAIN"; local t; t="$(stat -c %Y "$PD/$MAIN")"
  sed -i -e "s/^STRAY_COPY_AT=.*/STRAY_COPY_AT=$t/" -e "s/^DEPLOYED_AT=.*/DEPLOYED_AT=$(date -u -d "@$(( t - 60 ))" +%Y-%m-%dT%H:%M:%SZ)/" "$STATE"; }
log_ts() { sed -nE 's/^== 5\.18\.93 \((deploy|after)\) — ([0-9]{8}T[0-9]{6}Z) — .* ==$/\2/p' <<<"$1" | head -1; }

echo; echo "== Q0. the real 5.18.93 deploy and its day-later check, as on the server =="
D1="$(data_digest)"
fresh; OUT="$(run --answer DEPLOY)"
check "$(fails "$OUT")$(has "$OUT" '5.18.93 (deploy): PASSED')$(live)" "0yes$PIN" "the deploy of 5.18.93 PASSES over the sandbox, as on 11 Oct"
check "$(grep -c '^STRAY_CTRL=seen$' "$STATE")$(grep -c "^STRAY_COPY=$(stray_line)\$" "$STATE")" "11" "its record holds A14's 'seen' and the stray store at the copy"
mkdir -p "$SB/pool"
copy_back 30; base_log; OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" 'note  R19 the stray folder has not changed since the copy')$(has "$OUT" '5.18.93 (after): PASSED')" "0yesyes" \
  "the 20-minute --after-only: PASSED, R19 not yet conclusive (no run of the retry job since the copy)"
printf '%s\n' "$OUT" > "$SB/pool/early.log"
copy_back 1500; COPY_T="$(stat -c %Y "$PD/$MAIN")"
set_sched $(( $(date -u +%s) - 300 ))
base_log; OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" '5.18.93 (after): PASSED')$(has "$OUT" 'ok    R19 the retry job ran at')$(has "$OUT" "after this deploy's copy (at $(utc "$COPY_T"))")" "0yesyesyes" \
  "the day-later --after-only (the copy moved a day and a quarter back, master's record of a run since): PASSED, R19 conclusive"
printf '%s\n' "$OUT" > "$SB/pool/day.log"; DAY_TS="$(log_ts "$OUT")"
base_log; logline $(( $(date -u +%s) - 30 )) "PHP Fatal error:  Uncaught Error: planted by the rehearsal in /data/ucrm/data/plugins/dishnet-hybrid-sudan/cron/event_processor.php:12"
OUT="$(run --after-only)"; base_log
check "$(fails "$OUT")$(has "$OUT" 'ok    R19 the retry job ran at')$(grep -c '^STRAY_R19_FAILED=' "$STATE")" "1yes0" "a day-later run that FAILS on V4 alone: R19 still conclusive, nothing recorded in the state file"
printf '%s\n' "$OUT" > "$SB/pool/fail.log"
OUT="$(run --answer NONE)"
check "$(has "$OUT" '== 5.18.93 (deploy) — ')$(has "$OUT" '5.18.93 (after): PASSED')$(has "$OUT" 'ok    R19 the retry job ran at')" "yesyesyes" \
  "a run with no option while 5.18.93 is live: its header says deploy, it runs as --after-only and PASSES"
printf '%s\n' "$OUT" > "$SB/pool/noflag.log"
logs_only() { rm -f "$SB/out"/*.log; local f; for f in "$@"; do cp "$SB/pool/${f%%:*}" "$SB/out/${f#*:}"; done; }
retime() {   # retime POOL-IN EPOCH POOL-OUT — a pool log whose header carries another start time: the one line changed, asserted
  python3 - "$SB/pool/$1" "$(date -u -d "@$2" +%Y%m%dT%H%M%SZ)" "$SB/pool/$3" <<'PY' || echo "retime:$1" >> "$SB/anchor-missing"
import re, sys
s = open(sys.argv[1]).read(); ts = sys.argv[2]
n = len(re.findall(r'^== 5\.18\.93 \((?:deploy|after)\) — [0-9]{8}T[0-9]{6}Z — ', s, re.M))
assert n == 1, n
s2 = re.sub(r'^(== 5\.18\.93 \((?:deploy|after)\) — )[0-9]{8}T[0-9]{6}Z( — )', lambda m: m.group(1) + ts + m.group(2), s, count=1, flags=re.M)
assert s2 != s and s2.count(ts) >= 1
open(sys.argv[3], 'w').write(s2)
PY
}
BOUND=$(( COPY_T + 86400 ))
retime early.log $(( COPY_T + 1800 )) early20.log
retime day.log $(( BOUND - 1 )) day-1.log; retime day.log "$BOUND" day0.log; retime day.log $(( BOUND + 1 )) day+1.log
retime early.log $(( BOUND + 60 )) earlyday.log
logs_only "early20.log:after-20m.log" "day.log:after-$DAY_TS.log"

# ── The plugin's files as the server has them: owned by 1000:1000; -u honoured from here on ─────────────────────────
own_server() {
  kill "$WEB_PID" 2>/dev/null; wait "$WEB_PID" 2>/dev/null
  chmod 711 "$SB"; chown -R 1000:1000 "$PLUGINS" "$SB/web"; touch "$SB/.server-owner"
  setpriv --reuid 1000 --regid 1000 --clear-groups -- php -S "127.0.0.1:$WPORT" -t "$SB/web" >>"$SB/web.log" 2>&1 & WEB_PID=$!; PIDS+=("$WEB_PID")
  mkdir -p "$SB/u"; chown 1000:1000 "$SB/u"
}
own_server
PHPBIN="$(command -v php)"
php() { setpriv --reuid 1000 --regid 1000 --clear-groups -- "$PHPBIN" "$@"; }   # the harness's own reads and writes of the plugin's data, as its owner
cfg_keep() { cp -p "$DATA/kyc_config.json" "$SB/kyc_config.kept"
  php -r '$p = new PDO("sqlite:" . $argv[1]); echo (string)$p->query("SELECT data FROM kyc_config WHERE id = 0")->fetchColumn();' "$DATA/plugin.sqlite3" > "$SB/kyc_row.kept"; }
cfg_back() { cp "$SB/kyc_config.kept" "$DATA/kyc_config.json"
  php -r '$p = new PDO("sqlite:" . $argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $p->prepare("UPDATE kyc_config SET data = ? WHERE id = 0")->execute([$argv[2]]);' "$DATA/plugin.sqlite3" "$(cat "$SB/kyc_row.kept")"; }
file_set() { php -r '$f = $argv[1]; $c = json_decode((string)file_get_contents($f), true); $c[$argv[2]] = $argv[3]; file_put_contents($f, json_encode($c));' "$DATA/kyc_config.json" "$1" "$2"; }
vault_keep() { cp -p "$VAULT" "$SB/vault.kept"; }
vault_back() { cp "$SB/vault.kept" "$VAULT"; }
for i in $(seq 1 50); do [ "$(standin_page customer_login)" = "200" ] && break; sleep 0.1; done
check "$(stat -c %u:%g "$SRC")$(stat -c %u:%g "$DATA/plugin.sqlite3")$(standin_page customer_login)" "1000:10001000:1000200" \
  "control: the stray store and the live database are the server's owner's (1000:1000), and the stand-in still serves the installed plugin"
check "$(env PATH="$SB/bin:$PATH" bash "$SB/bin/docker" exec -u 1000:1000 ucrm id -u)$(env PATH="$SB/bin:$PATH" bash "$SB/bin/docker" exec ucrm id -u)" "10000" \
  "control: the fake docker runs -u 1000:1000 as that user, and no -u as root — as the container would"
check "$(env PATH="$SB/bin:$PATH" bash "$SB/bin/docker" inspect ucrm --format '{{.Unknown}}' >/dev/null 2>&1; echo $?)$(env PATH="$SB/bin:$PATH" bash "$SB/bin/docker" ps -a >/dev/null 2>&1; echo $?)" "9999" \
  "control: the fake docker refuses a format or an option the scripts do not use — a new call cannot pass silently"

# ── The quarantine script as the operator runs it ───────────────────────────────────────────────────────────────────
STRAY_DB_SB="$(sed -nE 's/^STRAY_COPY=db=([^ ]+) .*/\1/p' "$STATE" | head -1)"; STRAY_DIR_SB="$(sed -nE 's/^STRAY_COPY=.* dir=([0-9]+)$/\1/p' "$STATE" | head -1)"
qpin() {   # qpin NAME [DB] [DIR] — the script with the server's two literals set to this sandbox's (or to DB and DIR)
  python3 - "$QS" "$REPO/scripts/.$1.sh" "${2:-$STRAY_DB_SB}" "${3:-$STRAY_DIR_SB}" <<'PY' || echo "qpin:$1" >> "$SB/anchor-missing"
import sys
s = open(sys.argv[1]).read()
for k, v in (('EXPECTED_STRAY_DB="dc85ae6ddafaae19:2379776:1791366907"', 'EXPECTED_STRAY_DB="%s"' % sys.argv[3]),
             ('EXPECTED_STRAY_DIR_T="1791600347"', 'EXPECTED_STRAY_DIR_T="%s"' % sys.argv[4])):
    assert s.count(k) == 1, k
    s = s.replace(k, v)
open(sys.argv[2], 'w').write(s)
PY
  echo "$REPO/scripts/.$1.sh"
}
QP="$(qpin qpin)"
cat > "$SB/ptyrun.py" <<'PY'
# The operator's terminal: runs a command line in a pseudo-terminal, types the answer at the prompt (after a hook, when
# given), and — when asked — sends a Ctrl-C at a line of the output, or hangs up the terminal there (closes it), as an
# SSH session that drops does. Prints everything the terminal showed.
import os, pty, sys, time, select, re, subprocess, signal
args = sys.argv[1:]; opt = {'answer': '', 'hook': '', 'at': '', 'intr': '', 'delay': '0', 'timeout': '900'}
while args and args[0].startswith('--'):
    k = args.pop(0)[2:]
    if k == '': break
    opt[k] = args.pop(0)
pid, fd = pty.fork()
if pid == 0:
    os.execvp('bash', ['bash', '-c', args[0]])
buf = b''; answered = False; fired = False; t0 = time.time(); prompt = re.compile(rb'Type (QUARANTINE|PUTBACK) ')
while True:
    if time.time() - t0 > float(opt['timeout']):
        os.kill(pid, signal.SIGKILL); buf += b'\n[ptyrun: timeout]\n'; break
    try: r, _, _ = select.select([fd], [], [], 0.2)
    except (OSError, ValueError): break
    if fd in r:
        try: data = os.read(fd, 65536)
        except OSError: break
        if not data: break
        buf += data
    if not answered and prompt.search(buf):
        answered = True
        if opt['hook']: subprocess.run(opt['hook'], shell=True, executable='/bin/bash')
        if opt['answer'] != '__NONE__': os.write(fd, opt['answer'].encode() + b'\n')   # __NONE__: nothing typed at all
    if opt['at'] and not fired and opt['at'].encode() in buf:
        fired = True
        time.sleep(float(opt['delay']))
        if opt['intr'] == 'INT': os.write(fd, b'\x03')
        elif opt['intr'] == 'HUP': os.close(fd); break
try: os.waitpid(pid, 0)
except ChildProcessError: pass
sys.stdout.write(buf.decode('utf-8', 'replace').replace('\r', ''))
PY
mkdir -p "$SB/qtmp"   # the quarantine script's TMPDIR: the holders control's folder lands here and must be gone again
qrun() {   # [--answer W] [--script F] [--env K=V]… [--hook CMD] [--at TEXT [--delay S] --intr INT|HUP] [--wrap CMD] -- [options]
           # — the operator's command: bash <script> 2>&1 | tee <its folder>/run-N.log, in a terminal; QRC is the script's exit
  local answer="" script="$QP" extra=() hook="" at="" intr="" delay="0" wrap="" n o cmd
  while :; do case "${1:-}" in --answer) answer="$2"; shift 2;; --script) script="$2"; shift 2;; --env) extra+=("$2"); shift 2;;
    --hook) hook="$2"; shift 2;; --at) at="$2"; shift 2;; --intr) intr="$2"; shift 2;; --delay) delay="$2"; shift 2;; --wrap) wrap="$2"; shift 2;; --) shift; break;; *) break;; esac; done
  n=$(( $(cat "$SB/qruns" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/qruns"
  mkdir -p "${QLOGDIR:-$QD}"
  cmd="$(printf '%q ' env PATH="${QPATH:-$SB/bin:$PATH}" DNB_RECORDS="${QRECS:-$RECS}" TMPDIR="$SB/qtmp" ${extra[@]+"${extra[@]}"} bash "$script" "$@")"
  cmd="${wrap:+$wrap }$cmd 2>&1 | tee $(printf '%q' "${QLOGDIR:-$QD}/run-$n.log"); echo \"__rc=\${PIPESTATUS[0]}\""
  o="$(python3 "$SB/ptyrun.py" --answer "$answer" --hook "$hook" --at "$at" --delay "$delay" --intr "$intr" -- "$cmd")"
  QRC="$(sed -n 's/^__rc=//p' <<<"$o" | tail -1)"
  case "$o" in *"unknown option:"*) echo "qrun-$n" >> "$SB/bad-runs" ;; esac
  [ -n "${REHEARSE_KEEP:-}" ] && printf '%s\n' "$o" > "$REHEARSE_KEEP/qrun-$(printf '%03d' "$n")-$(basename "$script" .sh)$(printf '%s' "$*" | tr -c 'A-Za-z0-9-' '_').txt"
  printf '%s' "$o"
}
qlast() { cat "$QD/$(ls -t "$QD" | grep -m1 '\.own\.log$')" 2>/dev/null; }   # the newest run's own log
rec_last() { [ -f "$QREC" ] && sed -n "s/^$1=//p" "$QREC" | tail -1; }
SNAP0="$(stray_snap)"; DQ0="$(data_digest)"; VAULT0="$(sha256sum "$VAULT" | cut -c1-16)"; REF_DIR_T="$(stat -c %Y "$PD/data")"; REF_DIR_Y="$(stat -c %y "$PD/data")"; KEY0="$(stat -c '%d:%i' "$SRC")"
BIRTH0="$(stat -c %w "$SRC")"
unchanged() {   # nothing moved: the stray folder exactly as seeded, no kept file, no record stage of a move
  echo "$( [ "$(stray_snap)" = "$SNAP0" ] && echo snap)$( [ -e "$KEPT" ] || echo nokept)$(case "$(rec_last Q_STAGE)" in ''|abandoned) echo norec ;; *) echo "rec:$(rec_last Q_STAGE)" ;; esac)"
}
q_clean() { rm -rf "${QD:?}"; mkdir -p "$QD"; }   # the quarantine folder as the operator's mkdir leaves it: empty
Q1L=""; Q1T=""
q1() { Q1L="$1"; Q1T="$2"; shift 2; qrun --answer NO "$@" > "$SB/q1.last"; }   # q1 LABEL EXPECTED-NO-GO-TEXT [qrun options…] — run; q1ok checks it once the trial is undone
q1ok() {   # after the trial's planted change is undone: that refusal was said, never GO, and nothing changed
  check "$(has "$(cat "$SB/q1.last")" "$Q1T")$(has "$(cat "$SB/q1.last")" 'GO — evidence recorded')$(unchanged)" "yesnosnapnokeptnorec" "$Q1L"
}
went_go() { echo "$(has "$1" 'GO — evidence recorded')$(has "$1" 'Type QUARANTINE')$(has "$1" 'STOP: not confirmed')"; }   # a weakened copy reached the question (the word withheld)
q_clean
check "$(bash -n "$QS" && echo ok)$(grep -c 'quarantine-stray-store.sh 2>&1 | tee' <(sed -n '/^# Run as root/,/^# The put-back is a separate command/p' "$QS"))$(sed -n '/^# Run as root/,/^# The put-back is a separate command/p' "$QS" | grep -c -- '--put-back')" "ok10" \
  "the script parses; the header's run block carries the quarantine command and no put-back (docs/44 §16.9)"
check "$(grep -cE 'new PDO\(|sqlite3\.connect|SqliteStore' "$QS")$(grep -c 'PDO("sqlite:" . rtrim($pdd, "/") . "/plugin.sqlite3"' "$QS")$(grep -c 'PDO("sqlite:" . rtrim($argv\[1\], "/") . "/plugin.sqlite3"' "$QS")$(grep -c "sqlite3.connect(os.path.join(d, 'control.sqlite3'))" "$QS")" "3111" \
  "the script's every SQLite open is one of three: the live store for the gate's question, the live store for master's record, and the control's own throwaway store"

echo; echo "== Q1. stage A refuses, each clause on its own, before anything changes =="
OUT="$(qrun --answer NO)"
check "$(went_go "$OUT")$(unchanged)$(grep -c '^  ok    A[0-9] ' <<<"$OUT")" "yesyesyessnapnokeptnorec8" "control: the pinned copy, as the server would run it, reaches GO on this sandbox with A0–A7 ok, and a word other than QUARANTINE stops it with nothing changed"
check "$(has "$OUT" 'holders control host store: locks 2, descriptors 1 while open; 0, 0 once closed — container: descriptors on manifest.json 0, 1 while held, 0 after')$(okline "$OUT" A6 'live=same inside=no store=read tenant=uganda skip=yes | by-store=uganda by-files+vault=uganda')" "yesyes" \
  "control: both holder controls pass — a store held on the host and a file held in the container, seen and then gone — and the gate reads as on the server"
check "$(has "$OUT" 'backup copy     dnb-5.18.93/backup-')$(has "$OUT" 'the same bytes')" "yesyes" "control: A9 finds the deploy's backup copy of the stray store to be the same bytes, hashed as a stream"
for lg in "day0.log:after-b0.log" "day+1.log:after-b1.log" "noflag.log:after-nf.log"; do
  logs_only "early20.log:after-20m.log" "$lg"; OUT="$(qrun --answer NO)"
  check "$(went_go "$OUT")$(okline "$OUT" A3 'PASSED with 0 failed')" "yesyesyesyes" "A3 accepts ${lg%%.log:*}: $( case "$lg" in day0*) echo "a day-later log begun exactly at the bound";; day+1*) echo "one begun a second after it";; *) echo "a run with no option (its header says deploy, its summary after)";; esac )"
done
logs_only "early20.log:after-20m.log" "day-1.log:after-x.log"; OUT="$(qrun --answer NO --env TZ=America/New_York)"
check "$(has "$OUT" 'NO-GO: no day-later check yet')" "yes" "A3 under TZ=America/New_York: a log one second before the bound is still refused (the stamp is read as UTC)"
logs_only "early20.log:after-20m.log" "day0.log:after-b0.log"; OUT="$(qrun --answer NO --env TZ=America/New_York)"
check "$(went_go "$OUT")" "yesyesyes" "…and one at the bound still accepted"
logs_only "day.log:after-$DAY_TS.log"
printf '%s\n' "$BASE" > "$PD/.deployed-commit"; q1 "1a another live commit (5.18.92's record) — A0" "NO-GO: the container serves $BASE, not 69c87a6"; printf '%s\n' "$PIN" > "$PD/.deployed-commit"; q1ok
cp -p "$PD/manifest.json" "$SB/manifest.kept"; sed -i 's/"version": "5.18.93"/"version": "5.18.92"/' "$PD/manifest.json"
q1 "1b the installed manifest not 5.18.93's — A0" "but the installed manifest says 5.18.92, not 5.18.93"; cp -p "$SB/manifest.kept" "$PD/manifest.json"; q1ok
cp -p "$PD/lib/StarlinkRetryScope.php" "$SB/scope.kept"; printf '\n// edited\n' >> "$PD/lib/StarlinkRetryScope.php"
q1 "1c a file of the gate edited by hand — A0" "NO-GO: installed, not as 69c87a6 has them: lib/StarlinkRetryScope.php"; cp -p "$SB/scope.kept" "$PD/lib/StarlinkRetryScope.php"; q1ok
cp -p "$PD/ucrm.json" "$SB/ucrm.kept"; php -r '$f = $argv[1]; $u = json_decode((string)file_get_contents($f), true); $u["pluginDataDir"] = $argv[2]; file_put_contents($f, json_encode($u));' "$PD/ucrm.json" "$IN_CONTAINER/data"
q1 "1d ucrm.json naming <plugin>/data — the live directory would be the stray store itself" "NO-GO: the plugin's live data directory would be $IN_CONTAINER/data"; O1D="$(qrun -- --check)"; cp -p "$SB/ucrm.kept" "$PD/ucrm.json"; q1ok
check "$(has "$O1D" 'no live database is found — send this log now')$(has "$O1D" 'must stay where it is')$(has "$O1D" 'quarantine.env')" "yesnono" \
  "1d2 --check under the same ucrm.json: a reason that fits its mode (the file is not in <plugin>/data to stay there), and no record asked for that does not exist"
ucrm_pdd() { php -r '$f = $argv[1]; $u = json_decode((string)file_get_contents($f), true); $u["pluginDataDir"] = $argv[2]; file_put_contents($f, json_encode($u));' "$PD/ucrm.json" "$1"; }
ucrm_pdd "$IN_CONTAINER/./data"
q1 "1d' ucrm.json naming <plugin>/./data — the live path an alias of the stray store: A1 refuses before anything opens it" "NO-GO: the stray store and the live database are the same file"; cp -p "$SB/ucrm.kept" "$PD/ucrm.json"; q1ok
cp -p "$STATE" "$SB/state.kept"
sed -i 's/^STRAY_CTRL=seen$/STRAY_CTRL=not-seen/' "$STATE"; q1 "1e 5.18.93's A14 did not see the stray store — A2" "STRAY_CTRL=not-seen"; cp -p "$SB/state.kept" "$STATE"; q1ok
printf 'STRAY_R19_FAILED=%s\n' "$(date -u +%s)" >> "$STATE"; q1 "1f an R19 failure in 5.18.93's state file — A2" "dnb-5.18.93/state-5.18.93.env"; cp -p "$SB/state.kept" "$STATE"; q1ok
cp -p "$STATE" "$STATE.prev-20261011T000000Z"; printf 'STRAY_R19_FAILED=1\n' >> "$STATE.prev-20261011T000000Z"
q1 "1f' …in a kept earlier copy of it (.prev-*) — A2" "state-5.18.93.env.prev-20261011T000000Z"; rm -f "$STATE.prev-20261011T000000Z"; q1ok
cp -p "$PREV" "$SB/prev.kept"; printf 'STRAY_R19_FAILED=1\n' >> "$PREV"; q1 "1f'' …in 5.18.92's state file — A2" "dnb-5.18.92/state-5.18.92.env"; cp -p "$SB/prev.kept" "$PREV"; q1ok
sed -i "s/^STRAY_COPY=db=[^ ]*/STRAY_COPY=db=0000000000000000:1:1/" "$STATE"; q1 "1g 5.18.93's record of the stray store at the copy not the file's (the file as the literal) — A4" "not as 5.18.93's copy recorded it"; cp -p "$SB/state.kept" "$STATE"; q1ok
logs_only "early20.log:after-20m.log"; q1 "1h only the 20-minute log, no day-later one — A3" "NO-GO: no day-later check yet"; q1ok
check "$(has "$(cat "$SB/q1.last")" 'Until the quarantine has moved the file: deploy or roll back nothing')$(has "$(cat "$SB/q1.last")" 'run neither diagnose-sales-pipeline.sh nor migrations/migrate_tickets_data.php')$(has "$(cat "$SB/q1.last")" 'quarantine.env')" "yesyesno" \
  "…the refusal prints the rule that binds until the move: deploy or roll back nothing, save no plugin settings, run neither tool — and asks for no quarantine.env, which does not exist yet"
logs_only "early20.log:after-20m.log" "day-1.log:after-x.log"; q1 "1h' a day-later log begun one second before the bound — A3" "NO-GO: no day-later check yet"; q1ok
logs_only "day.log:after-$DAY_TS.log" "fail.log:after-zfail.log"; q1 "1i the latest day-later log FAILED (V4) — A3" "did not pass, or did not conclude R19"; q1ok
logs_only "earlyday.log:after-y.log"; q1 "1j a day-later log that PASSED without R19's conclusive line — A3" "did not pass, or did not conclude R19"; q1ok
logs_only "day.log:after-$DAY_TS.log"
CP2="$(qpin qlit 0000000000000000:1:1)"; q1 "1k the stray store not as the server's literal (the file as 5.18.93's record) — A4" "not as every deploy since 5.18.91 recorded it" --script "$CP2"; rm -f "$CP2"; q1ok
CP2="$(qpin qdir "" 1)"; q1 "1l the stray folder's time not the server's literal — A4" "not $(utc 1) as every deploy since 5.18.91 recorded it" --script "$CP2"; rm -f "$CP2"; q1ok
sed -i -E "s/^(STRAY_COPY=.* dir=)[0-9]+$/\1$(( REF_DIR_T + 1 ))/" "$STATE"; q1 "1l' the folder's time in 5.18.93's record not the folder's — A4" "not $(utc $(( REF_DIR_T + 1 ))) as 5.18.93's copy recorded it"; cp -p "$SB/state.kept" "$STATE"; q1ok
touch "$SRC-wal"; touch -d "$REF_DIR_Y" "$PD/data"; q1 "1m a side file beside the stray store — A4" "NO-GO: side files beside the stray store (-wal)"; rm -f "$SRC-wal"; touch -d "$REF_DIR_Y" "$PD/data"; q1ok
ln "$SRC" "$SB/elsewhere.link"; q1 "1n a second name of the stray store elsewhere — A4" "NO-GO: the stray store has 2 names"; rm -f "$SB/elsewhere.link"; q1ok
touch -d "@$(( REF_DIR_T + 60 ))" "$PD/data"; q1 "1o the stray folder's time moved — A4" "an entry was made or removed in it"; touch -d "$REF_DIR_Y" "$PD/data"; q1ok
setpriv --reuid 1000 --regid 1000 --clear-groups -- touch "$PD/data/planted.txt"; touch -d "$REF_DIR_Y" "$PD/data"
q1 "1p an extra entry in the stray folder, its time put back — A4" "NO-GO: the stray folder's entries are not as 5.18.93's copy recorded them"; rm -f "$PD/data/planted.txt"; touch -d "$REF_DIR_Y" "$PD/data"; q1ok
check "$(has "$(cat "$SB/q1.last")" 'added:planted.txt')" "yes" "…naming the entry, by name only"
if chattr +a "$PD/data" 2>/dev/null; then q1 "1q an append-only attribute on the stray folder — A4" "NO-GO: an immutable or append-only attribute is set"; chattr -a "$PD/data"; touch -d "$REF_DIR_Y" "$PD/data"; q1ok
else check "chattr refused" "chattr" "1q chattr +a works on this filesystem"; fi
if chattr +i "$SRC" 2>/dev/null; then q1 "1q' an immutable attribute on the stray store — A4" "NO-GO: an immutable or append-only attribute is set"; chattr -i "$SRC"; q1ok
else check "chattr refused" "chattr" "1q' chattr +i works on this filesystem"; fi
holder_py() {   # holder_py KIND PATH — a process holding PATH: fd (open, no lock), lock (a lock, no descriptor)
  rm -f "$SB/hold.ready" "$SB/hold.go"
  python3 - "$1" "$2" "$SB/hold.ready" "$SB/hold.go" >/dev/null 2>&1 <<'PY' &
import os, sys, fcntl, struct, ctypes, time
kind, f, ready, go = sys.argv[1:5]
fd = os.open(f, os.O_RDONLY)
if kind == 'lock':
    fcntl.fcntl(fd, 37, struct.pack('hhqqi', fcntl.F_RDLCK, 0, 0, 0, 0))   # F_OFD_SETLK: a read lock on the whole file
    libc = ctypes.CDLL(None); libc.mmap.restype = ctypes.c_void_p
    libc.mmap.argtypes = [ctypes.c_void_p, ctypes.c_size_t, ctypes.c_int, ctypes.c_int, ctypes.c_int, ctypes.c_long]
    libc.mmap(None, 4096, 1, 1, fd, 0); os.close(fd)                      # the map keeps the open file, and its lock
open(ready, 'w').close()
while not os.path.exists(go): time.sleep(0.05)
PY
  HOLD_PID=$!; PIDS+=("$HOLD_PID"); local i; for i in $(seq 1 100); do [ -f "$SB/hold.ready" ] && break; sleep 0.05; done
}
holder_stop() { touch "$SB/hold.go"; wait "$HOLD_PID" 2>/dev/null; rm -f "$SB/hold.ready" "$SB/hold.go"; }
holder_py fd "$SRC"; q1 "1r a process holding the stray store open, no lock — A5" "NO-GO: the stray store is held — locks and open descriptors: 0 1"; holder_stop; q1ok
holder_py lock "$SRC"; q1 "1r' a process holding a lock on it, no open descriptor — A5" "NO-GO: the stray store is held — locks and open descriptors: 1 0"; holder_stop; q1ok
ns_holder() {   # ns_holder PATH — a process in its own PID and mount namespace holding the file through a bind mount at another path
  rm -f "$SB/ns.ready" "$SB/ns.go"; mkdir -p "$SB/nsmnt"
  unshare --mount --pid --fork --mount-proc --propagation private sh -c 'mount --bind "$1" "$2" && exec 3<"$2/$3" && touch "$4" && while [ ! -e "$5" ]; do sleep 0.1; done' \
    sh "$(dirname "$1")" "$SB/nsmnt" "$(basename "$1")" "$SB/ns.ready" "$SB/ns.go" >/dev/null 2>&1 &
  NS_PID=$!; PIDS+=("$NS_PID"); local i; for i in $(seq 1 100); do [ -f "$SB/ns.ready" ] && break; sleep 0.05; done
}
ns_stop() { touch "$SB/ns.go"; wait "$NS_PID" 2>/dev/null; rm -f "$SB/ns.ready" "$SB/ns.go"; }
ns_holder "$SRC"; q1 "1s a process in another PID and mount namespace holding it through a bind mount at another path — A5" "NO-GO: the stray store is held"; ns_stop; q1ok
mkdir -p "$SB/nopy"; printf '#!/bin/sh\nexit 1\n' > "$SB/nopy/python3"; chmod +x "$SB/nopy/python3"
QPATH="$SB/nopy:$SB/bin:$PATH" q1 "1t the host control cannot run (python3 fails) — A5" "NO-GO: the holder instruments' controls did not pass"; q1ok
q1 "1t' the container control cannot run (exec -d refused) — A5" "NO-GO: the holder instruments' controls did not pass" --env FAKE_EXEC_D_FAIL=1; q1ok
cfg_keep; vault_keep; file_set tenant_profile south-sudan; file_set currency_code SSP; printf '{"config":{"currency_code":"SSP","tenant_profile":"south-sudan"}}' > "$VAULT"
q1 "1u the store row names Uganda, the configuration files and the vault South Sudan — A6 (the gate's answer when it cannot read the store)" "NO-GO: the installed retry job's gate does not read as it must"
check "$(has "$(cat "$SB/q1.last")" 'by-store=uganda by-files+vault=south-sudan')" "yes" "…its line names both sources"
row_set tenant_profile south-sudan; row_set currency_code SSP; cp "$SB/q1.last" "$SB/q1u.last"
Q1L_U="$Q1L"; Q1T_U="$Q1T"; q1 "1u' South Sudan everywhere — the gate lets the job run — A6" "skip=no"; cfg_back; vault_back; q1ok
cp "$SB/q1.last" "$SB/q1up.last"; cp "$SB/q1u.last" "$SB/q1.last"; Q1L="$Q1L_U"; Q1T="$Q1T_U"; q1ok
chmod 555 "$PLUGINS"; q1 "1v the plugins root not writable by the database's owner — the gate's live folder would be <plugin>/data — A6" "NO-GO: the installed retry job's gate does not read as it must"; chmod 755 "$PLUGINS"; q1ok
check "$(has "$(cat "$SB/q1.last")" 'inside=yes')" "yes" "…the line says inside=yes, as the uid the container runs it as finds it"
mkdir "$KEPT"; q1 "1w the kept name exists already (a directory), with no record — the record's table" "exists with no record beside it"; rmdir "$KEPT"; q1ok
mkdir -p "$SB/q1kept"; ln -s "$SB/q1kept" "$KEPT"; q1 "1w' the kept name a symlink to a directory" "exists with no record beside it"; rm -f "$KEPT"; q1ok
ln -s "$SB/nowhere" "$KEPT"; q1 "1w'' the kept name a dangling symlink" "exists with no record beside it"; rm -f "$KEPT"; q1ok
touch "$KEPT-wal"; q1 "1w''' a side name of the kept file exists — A7" "NO-GO: $KEPT-wal exists already"; rm -f "$KEPT-wal"; q1ok
cp -p "$SB/containers" "$SB/containers.kept"; printf '%s\n' "c0ffee000003|backup-agent|/backup=$SB/records|2026-10-01T08:00:00.000000001Z|0" >> "$SB/containers"
q1 "1x a container that mounts the records folder above the quarantine folder — A7" "NO-GO: the quarantine folder can be seen from inside a container: backup-agent:/backup"; cp -p "$SB/containers.kept" "$SB/containers"; q1ok
q1 "1y one listed container cannot be inspected — A7" "NO-GO: docker inspect failed for one container" --env FAKE_INSPECT_FAIL=c0ffee000002; q1ok
q1 "1y' the inspection does not show ucrm's /data — A7" "NO-GO: the inspection did not find ucrm's /data" --env FAKE_PAIRS_HIDE=ucrm; q1ok
q1 "1y'' docker ps fails — A7" "NO-GO: docker ps failed" --env FAKE_PS_FAIL=1; q1ok
SHM="$(mktemp -d -p /dev/shm)"; mkdir -p "$SHM/records"; ln -s "$SB/out" "$SHM/records/dnb-5.18.93"; ln -s "$SB/prev" "$SHM/records/dnb-5.18.92"
QRECS="$SHM/records" QLOGDIR="$SHM/records/dnb-stray-quarantine" q1 "1z the quarantine folder on another filesystem (tmpfs) — A7" "NO-GO: the quarantine folder is on another filesystem"; rm -rf "${SHM:?}"; q1ok
check "$(has "$(cat "$SB/q1.last")" 'tmpfs')" "yes" "…naming both filesystems"
mkdir -p "$MOUNT/rec"; ln -s "$SB/out" "$MOUNT/rec/dnb-5.18.93"; ln -s "$SB/prev" "$MOUNT/rec/dnb-5.18.92"
QRECS="$MOUNT/rec" QLOGDIR="$MOUNT/rec/dnb-stray-quarantine" q1 "1z' the quarantine folder inside the container's /data — A7" "NO-GO: the quarantine folder ($MOUNT/rec/dnb-stray-quarantine) is inside $MOUNT"; rm -rf "${MOUNT:?}/rec"; q1ok
mv "$QD" "$SB/qd.real"; ln -s "$SB/qd.real" "$QD"; OUT="$(qrun --answer NO)"; rm -f "$QD"; mv "$SB/qd.real" "$QD"
check "$(has "$OUT" 'is not a directory of its own (a symlink, or a file)')$(unchanged)" "yessnapnokeptnorec" "1z'' the quarantine folder a symlink — refused before anything is written into it"
OUT="$(qrun --answer quarantine)"; check "$(has "$OUT" 'STOP: not confirmed')$(unchanged)" "yessnapnokeptnorec" "1zz the word in lower case: not confirmed, nothing changed"
check "$(grep -c 'read -r -t 900 answer </dev/tty || answer=""' "$QS")" "2" "1zz' each prompt waits 15 minutes at most for its word (read -t 900, both)"
M="$(mutant q1-wait "$QP" 'local answer=""; read -r -t 900 answer </dev/tty || answer=""
  [ "$answer" = "QUARANTINE" ]' 'local answer=""; read -r -t 3 answer </dev/tty || answer=""
  [ "$answer" = "QUARANTINE" ]')"; OUT="$(qrun --answer __NONE__ --script "$M")"; rm -f "$M"
check "$(has "$OUT" 'STOP: not confirmed')$(has "$OUT" '[ptyrun: timeout]')$(unchanged)" "yesnosnapnokeptnorec" "1zz'' nothing typed at all: the run stops by itself at its time (3 s in a copy; 15 minutes in the script), nothing changed"
OUT="$(qrun --answer NO)"; check "$(went_go "$OUT")" "yesyesyes" "…and its lock goes with it: the next run reaches GO"
check "$(sha256sum "$VAULT" | cut -c1-16)$(data_digest)" "$VAULT0$DQ0" "Q1's refusals changed neither the vault nor any data of the plugin"
# Master's record of the retry job unreadable (its table gone): refused at A8, before the word. (After the data check: the
# record is written again, at another time, by the harness as master writes it.)
cat > "$SB/sched_clear.sh" <<SH
#!/usr/bin/env bash
exec setpriv --reuid 1000 --regid 1000 --clear-groups -- "$PHPBIN" -r '\$p = new PDO("sqlite:" . \$argv[1]); \$p->exec("DROP TABLE IF EXISTS master_schedule");' "$DATA/plugin.sqlite3"
SH
sched_clear; q1 "1aa master's record of the retry job cannot be read — A8" "NO-GO: master's record of the retry job could not be read"; set_sched $(( $(date -u +%s) - 300 )); q1ok
# One run at a time: the folder's lock held, as a run whose terminal was lost holds it — every mode stops at once.
touch -d '-1 hour' "$QD/quarantine-20261011T000000Z.own.log"   # at least one own log there, as a running run's would be
NOWN0="$(ls "$QD" | grep -c '\.own\.log$')"; NEWEST0="$(ls -t "$QD" | grep -m1 '\.own\.log$')"
exec 7<"$QD"; flock 7
OUT="$(qrun --answer QUARANTINE)"; O2="$(qrun -- --check)"; O3="$(qrun --answer PUTBACK -- --put-back)"
check "$(ls "$QD" | grep -c '\.own\.log$')$(has "$OUT$O2$O3" 'every *.own.log')$(ls -t "$QD" | grep -m1 '\.own\.log$')" "${NOWN0}no$NEWEST0" \
  "1ab the lock is taken before a run opens its own log: the three refused runs add no *.own.log, so the newest one is still the running run's"
m="$(mutant q1-lockorder "$QP" 'command -v flock >/dev/null 2>&1 || stop "no flock' 'OWN="$Q_DIR/$MODE-$TS.own.log"; exec 3>>"$OWN"; command -v flock >/dev/null 2>&1 || stop "no flock')"
o="$(qrun --answer NO --script "$m")"; rm -f "$m"
check "$(ls "$QD" | grep -c '\.own\.log$')$(has "$o" 'every *.own.log')" "$(( NOWN0 + 1 ))yes" "1ab'' a copy that opens its own log before the lock adds one, the newest, and points the operator at it (caught)"
rm -f "$QD"/*.own.log
check "$(has "$OUT" 'another run of this script is still going')$(has "$O2" 'another run of this script is still going')$(has "$O3" 'another run of this script is still going')$(has "$OUT$O2$O3" '== stray-store quarantine (')$(unchanged)" "yesyesyesnosnapnokeptnorec" \
  "1ab the lock held: the quarantine, --check and --put-back each stop at once, before reading anything; nothing changed"
m="$(mutant q1-lock "$QP" 'flock -n 9 || stop "another run' ': || stop "another run')"; OUT="$(qrun --answer NO --script "$m")"; rm -f "$m"
check "$(went_go "$OUT")$(unchanged)" "yesyesyessnapnokeptnorec" "1ab' a copy without the lock runs beside the held one, to GO (caught)"
flock -u 7; exec 7<&-
OUT="$(qrun --answer NO)"; check "$(went_go "$OUT")" "yesyesyes" "…the lock released: the next run reaches GO"
mkdir -p "$SB/norec"; OUT="$(QRECS="$SB/norec" qrun -- --check)"; rmdir "$SB/norec" 2>/dev/null
check "$(has "$OUT" 'nothing has been quarantined on this server')$(has "$OUT" 'Until the quarantine has moved the file')" "yesyes" "1ad --check with no quarantine folder at all: it stops, and prints the rule that binds until the move"
# A Ctrl-C while the holders control holds its throwaway store (python3 slowed here): the store's folder goes with it.
mkdir -p "$SB/slowpy"; printf '#!/bin/sh\nsleep 3\nexec %s "$@"\n' "$(command -v python3)" > "$SB/slowpy/python3"; chmod +x "$SB/slowpy/python3"
OUT="$(QPATH="$SB/slowpy:$SB/bin:$PATH" qrun --answer NO --at 'kept as' --delay 1 --intr INT)"; sleep 4
check "$(ls -A "$SB/qtmp" | wc -l | tr -d ' ')$(has "$OUT" 'holders control')$(unchanged)" "0nosnapnokeptnorec" "1ac a Ctrl-C during the holders control: the run ends there, and its throwaway folder is removed"
m="$(mutant q1-ctltrap "$QP" "  trap 'rm -rf -- \"\$d\"; exit 130' INT TERM HUP" "  : trap 'rm -rf -- \"\$d\"; exit 130' INT TERM HUP")"
OUT="$(QPATH="$SB/slowpy:$SB/bin:$PATH" qrun --answer NO --script "$m" --at 'kept as' --delay 1 --intr INT)"; rm -f "$m"; sleep 4
check "$(ls -A "$SB/qtmp" | wc -l | tr -d ' ')" "1" "1ac' a copy without that trap leaves the folder behind (caught)"; rm -rf "${SB:?}/qtmp/"*

echo; echo "== Q2. weakened copies of stage A — each reaches GO where the script refuses (caught) =="
Q2L=""; Q2O=""
q2() {   # q2 LABEL [OLD NEW]… -- [qrun options…] — run a copy of QP without one clause, the trial's state set up by the caller
  Q2L="$1"; shift; local pairs=() m; while [ "$1" != "--" ]; do pairs+=("$1"); shift; done; shift
  m="$(mutant "q2-$Q2L" "$QP" "${pairs[@]}")"
  if [ -f "$m" ]; then Q2O="$(qrun --answer NO --script "$m" "$@")"; rm -f "$m"; else Q2O="(no copy made)"; fi
}
q2ok() { check "$(went_go "$Q2O")$(unchanged)" "yesyesyessnapnokeptnorec" "2 $Q2L: the copy without that clause reaches GO (caught), and the word withheld changes nothing"; }
printf '%s\n' "$BASE" > "$PD/.deployed-commit"; q2 a0-commit '[ "$LIVE" = "$EXPECTED_LIVE_COMMIT" ] || stop' ': || stop' --; printf '%s\n' "$PIN" > "$PD/.deployed-commit"; q2ok
sed -i 's/"version": "5.18.93"/"version": "5.18.92"/' "$PD/manifest.json"; q2 a0-version '[ "$LIVE_VERSION" = "$EXPECTED_VERSION" ] || stop' ': || stop' --; cp -p "$SB/manifest.kept" "$PD/manifest.json"; q2ok
printf '\n// edited\n' >> "$PD/lib/StarlinkRetryScope.php"; q2 a0-files '[ -z "$gb" ] || stop "NO-GO: installed' ': || stop "NO-GO: installed' --; cp -p "$SB/scope.kept" "$PD/lib/StarlinkRetryScope.php"; q2ok
ucrm_pdd "$IN_CONTAINER/./data"; m="$(mutant q2-a1-same "$QP" '[ "$src_key" != "$LIVE_KEY" ] || stop' ': || stop')"; o="$(qrun --answer NO --script "$m")"; rm -f "$m"; cp -p "$SB/ucrm.kept" "$PD/ucrm.json"
SA1="$(stray_snap)"; rm -f "$SRC-wal" "$SRC-shm"; touch -d "$REF_DIR_Y" "$PD/data"
check "$(has "$o" 'ok    A1')$(has "$o" 'GO — evidence recorded')$( [ "$SA1" = "$SNAP0" ] && echo same || echo changed)$(unchanged)" "yesnochangedsnapnokeptnorec" \
  "2 a1-same: a copy without A1's same-file refusal passes A1 (caught) — and its gate probe then opens the stray store with SQLite, which changes the folder (put back here): A6's refusal comes too late"
sed -i 's/^STRAY_CTRL=seen$/STRAY_CTRL=not-seen/' "$STATE"; q2 a2-ctrl '[ "$c_ctrl" = "seen" ] || stop' ': || stop' --; cp -p "$SB/state.kept" "$STATE"; q2ok
printf 'STRAY_R19_FAILED=1\n' >> "$STATE"; q2 a2-r19 '[ -z "$r19f" ] || stop' ': || stop' --; cp -p "$SB/state.kept" "$STATE"; q2ok
logs_only "early20.log:after-20m.log" "day-1.log:after-x.log"; q2 a3-bound '[ "$te" -ge "$bound_e" ] && [ "$te" -ge "$best_e" ]' '[ "$te" -ge "$best_e" ]' --; q2ok
q2 a3-localtime 'te="$(date -u -d "${ts:0:4}-${ts:4:2}-${ts:6:2}T${ts:9:2}:${ts:11:2}:${ts:13:2}Z" +%s 2>/dev/null)"' 'te="$(date -d "${ts:0:4}-${ts:4:2}-${ts:6:2} ${ts:9:2}:${ts:11:2}:${ts:13:2}" +%s 2>/dev/null)"' -- --env TZ=America/New_York; q2ok
logs_only "day.log:after-$DAY_TS.log" "fail.log:after-zfail.log"
q2 a3-pass 'if grep -q "^  $EXPECTED_VERSION (after): PASSED\." "$best" && grep -qE '"'"'^  checks +[0-9]+ ok, 0 failed, [0-9]+ notes$'"'"' "$best" \' 'if true \' "&& ! grep -q '^  FAIL  ' \"\$best\" && grep" "&& grep" --; q2ok
logs_only "earlyday.log:after-y.log"; q2 a3-r19 "&& grep -F '  ok    R19 the retry job ran at ' \"\$best\" | grep -qF \"after this deploy's copy (at \$copy_utc)\"; then" "; then" --; q2ok
logs_only "day.log:after-$DAY_TS.log"
CP2="$(qpin qlit2 0000000000000000:1:1)"; Q2L=a4-literal; m="$(mutant q2-a4-literal "$CP2" '[ "$t" = "$EXPECTED_STRAY_DB" ] || stop' ': || stop')"; Q2O="$(qrun --answer NO --script "$m")"; rm -f "$m" "$CP2"; q2ok
sed -i "s/^STRAY_COPY=db=[^ ]*/STRAY_COPY=db=0000000000000000:1:1/" "$STATE"; q2 a4-state '[ "$t" = "$c_db" ] || stop' ': || stop' --; cp -p "$SB/state.kept" "$STATE"; q2ok
touch "$SRC-wal"; touch -d "$REF_DIR_Y" "$PD/data"; q2 a4-side '[ "$side" = "-" ] || stop' ': || stop' --; rm -f "$SRC-wal"; touch -d "$REF_DIR_Y" "$PD/data"; q2ok
ln "$SRC" "$SB/elsewhere.link"; q2 a4-links '[ "$links" = "1" ] || stop' ': || stop' --; rm -f "$SB/elsewhere.link"; q2ok
touch -d "@$(( REF_DIR_T + 60 ))" "$PD/data"; q2 a4-dir '[ "$dir_t" = "$EXPECTED_STRAY_DIR_T" ] || stop' ': || stop' '[ "$dir_t" = "$c_dir" ] || stop' ': || stop' --; touch -d "$REF_DIR_Y" "$PD/data"; q2ok
setpriv --reuid 1000 --regid 1000 --clear-groups -- touch "$PD/data/planted.txt"; touch -d "$REF_DIR_Y" "$PD/data"
q2 a4-entries 'stop "NO-GO: the stray folder'"'"'s entries are not as' 'say "NO-GO-blind: entries are not as' --; rm -f "$PD/data/planted.txt"; touch -d "$REF_DIR_Y" "$PD/data"; q2ok
if chattr +i "$SRC" 2>/dev/null; then q2 a4-attr 'case "$fa$da" in *i*|*a*) stop' 'case "$fa$da" in *x*) stop' --; chattr -i "$SRC"; q2ok; fi
holder_py fd "$SRC"; q2 a5-held 'held_by_none || stop "NO-GO: the stray store is $(hold_text)' ': || stop "NO-GO: the stray store is $(hold_text)' --; holder_stop; q2ok
holder_py fd "$SRC"; q2 a5-unresolved "xargs -0 -r stat -L -c '%d:%i' 2>/dev/null \\" "xargs -0 -r readlink 2>/dev/null \\" --; holder_stop
check "$(has "$Q2O" "NO-GO: the holder instruments' controls did not pass")$(has "$Q2O" 'GO — evidence recorded')$(unchanged)" "yesnosnapnokeptnorec" \
  "2 a5-unresolved: a copy whose descriptor scan does not resolve each descriptor to its file is stopped by the scan's own control (the control has teeth), before any holder count is believed"
QPATH="$SB/nopy:$SB/bin:$PATH" q2 a5-control '[ "$HOLDERS_OK" = "yes" ] || stop "NO-GO: the holder instruments'"'"' controls did not pass ($HOLDERS_CTL) — that' ': || stop "NO-GO: the holder instruments'"'"' controls did not pass ($HOLDERS_CTL) — that' \
  'if [ "${HOLDERS_OK:-}" != "yes" ]; then HOLD="? ?"; HOLD_BOTH="? ?"; STEADY="no"; HOLD_ANY="unread"; return 0; fi' ':' --; q2ok
ns_holder "$SRC"; m="$(mutant q2-a5-readlink "$QP" "xargs -0 -r stat -L -c '%d:%i' 2>/dev/null \\" "xargs -0 -r readlink 2>/dev/null | while read -r l; do stat -c '%d:%i' -- \"\$l\" 2>/dev/null; done \\")"
o="$(qrun --answer NO --script "$m")"; ns_stop; holder_py fd "$SRC"; o2="$(qrun --answer NO --script "$m")"; holder_stop; rm -f "$m"
check "$(went_go "$o")$(has "$o2" 'NO-GO: the stray store is held')$(unchanged)" "yesyesyesyessnapnokeptnorec" "2 a5-readlink: a copy that matches descriptors by their path, not by device and inode, misses the namespaced holder through the bind mount (caught) — and still sees one on the host path"
cfg_keep; vault_keep; file_set tenant_profile south-sudan; file_set currency_code SSP; printf '{"config":{"currency_code":"SSP","tenant_profile":"south-sudan"}}' > "$VAULT"
q2 a6-files 'case "$1" in *" | "*" by-files+vault=uganda") return 0 ;; esac; return 1' 'return 0' --; Q2O_F="$Q2O"
row_set tenant_profile south-sudan; row_set currency_code SSP
q2 a6-skip 'gate_ok "$gate" || stop' ': || stop' --; cfg_back; vault_back; q2ok
Q2L=a6-files; Q2O="$Q2O_F"; q2ok
touch "$KEPT-wal"; q2 a7-kept 'absent "$f" || stop "NO-GO: $f exists already' ': || stop "NO-GO: $f exists already' --; rm -f "$KEPT-wal"; q2ok
printf '%s\n' "c0ffee000003|backup-agent|/backup=$SB/records|2026-10-01T08:00:00.000000001Z|0" >> "$SB/containers"
q2 a7-sight '[ -z "$sight" ] || stop' ': || stop' --; cp -p "$SB/containers.kept" "$SB/containers"; q2ok
q2 a7-inspect '"$id" 2>/dev/null </dev/null)" || stop "NO-GO: docker inspect failed' '"$id" 2>/dev/null </dev/null)" || continue; : "NO-GO: docker inspect failed' \
  '[ "$n_ids" -gt 0 ] && [ "$n_ids" = "$n_ins" ] || stop' ': || stop' -- --env FAKE_INSPECT_FAIL=c0ffee000002; q2ok
q2 a7-ucrm '[ -n "$seen_ucrm" ] || stop' ': || stop' -- --env FAKE_PAIRS_HIDE=ucrm; q2ok
SHM="$(mktemp -d -p /dev/shm)"; mkdir -p "$SHM/records"; ln -s "$SB/out" "$SHM/records/dnb-5.18.93"; ln -s "$SB/prev" "$SHM/records/dnb-5.18.92"
QRECS="$SHM/records" QLOGDIR="$SHM/records/dnb-stray-quarantine" q2 a7-device '[ -n "$dev_s" ] && [ "$dev_s" = "$dev_q" ] || stop' ': || stop' --; rm -rf "${SHM:?}"; q2ok
sched_clear; q2 a8-record "case \"\${slr#running:}\" in ''|*[!0-9]*) stop \"NO-GO: master's record" "case \"\${slr#running:}\" in ''|*[!0-9]*) : \"NO-GO: master's record" --; set_sched $(( $(date -u +%s) - 300 )); q2ok
logs_only "early20.log:after-20m.log"; m="$(mutant q2-prerule "$QP" 'pre_rule; say "  Nothing further' 'say "  Nothing further')"; o="$(qrun --answer NO --script "$m")"; rm -f "$m"; logs_only "day.log:after-$DAY_TS.log"
check "$(has "$o" 'NO-GO: no day-later check yet')$(has "$o" 'Until the quarantine has moved the file')" "yesno" "2 pre-rule: a copy whose stops do not print the rule leaves A3's refusal without it (caught by 1h's check)"

echo; echo "== QB. stage B reads everything again after the word, before anything moves =="
qb() {   # qb LABEL HOOK EXPECTED [qrun options…] — the change made while the prompt waits; the word typed; nothing moved
  local label="$1" hook="$2" text="$3"; shift 3; local o
  o="$(qrun --answer QUARANTINE --hook "$hook" "$@")"
  check "$(has "$o" "$text")$(has "$o" 'linked ')$( [ -e "$KEPT" ] && echo kept || echo nokept)$( [ -e "$SRC" ] && echo src)$( [ -e "$QREC" ] && echo rec || echo norec)" "yesnonokeptsrcnorec" "$label"
  rm -f "$QREC"   # cleanup only: no record is written — the reads come first (asserted above)
}
qb "B1 a holder starts while the prompt waits: 'nothing moved: the stray store, read again now, is held'" "touch '$SB/qb.ready'; python3 -c 'import os,time; fd=os.open(\"$SRC\", os.O_RDONLY); open(\"$SB/qb.held\",\"w\").close(); time.sleep(30)' >/dev/null 2>&1 & echo \$! > '$SB/qb.pid'; for i in \$(seq 1 50); do [ -f '$SB/qb.held' ] && break; sleep 0.1; done" \
  "nothing moved: the stray store, read again now, is held — locks and open descriptors: 0 1"
kill "$(cat "$SB/qb.pid")" 2>/dev/null; rm -f "$SB/qb.pid" "$SB/qb.held" "$SB/qb.ready"
check "$(stray_snap)" "$SNAP0" "…the stray folder exactly as seeded"
qb "B2 the live commit changes while the prompt waits" "printf '%s\n' '$BASE' > '$PD/.deployed-commit'" "nothing moved: the container now serves $BASE"; printf '%s\n' "$PIN" > "$PD/.deployed-commit"
cfg_keep; vault_keep
qb "B3 the configuration changes while the prompt waits: the gate is asked again" "php() { setpriv --reuid 1000 --regid 1000 --clear-groups -- $PHPBIN \"\$@\"; }; php -r '\$f = \$argv[1]; \$c = json_decode((string)file_get_contents(\$f), true); \$c[\"tenant_profile\"] = \"south-sudan\"; \$c[\"currency_code\"] = \"SSP\"; file_put_contents(\$f, json_encode(\$c));' '$DATA/kyc_config.json'; printf '%s' '{\"config\":{\"currency_code\":\"SSP\",\"tenant_profile\":\"south-sudan\"}}' > '$VAULT'" \
  "nothing moved: the gate no longer reads as it must"; cfg_back; vault_back
qb "B4 an entry is made in the stray folder while the prompt waits" "setpriv --reuid 1000 --regid 1000 --clear-groups -- touch '$PD/data/planted.txt'" "nothing moved: the stray folder changed since stage A read it"
rm -f "$PD/data/planted.txt"; touch -d "$REF_DIR_Y" "$PD/data"
qb "B5 the stray store's time is touched and put back while the prompt waits (its change time moved)" "touch -d '$(stat -c %y "$SRC")' '$SRC'" "nothing moved: the stray store changed since stage A read it"
SNAP0="$(stray_snap)"   # the change time is not in the snapshot; the file is as before
qb "B6 a file of the gate edited while the prompt waits" "printf '\n// edited\n' >> '$PD/lib/StarlinkRetryScope.php'" "nothing moved: installed, not as 69c87a6 has them: lib/StarlinkRetryScope.php"; cp -p "$SB/scope.kept" "$PD/lib/StarlinkRetryScope.php"
set_sched $(( $(date -u +%s) - 60 )) -1
qb "B7 master is running the retry job (begun a minute ago): stage B waits 90 s for it to end, then refuses" "" "nothing moved: master is running the retry job now"
set_sched $(( $(date -u +%s) - 300 ))
qb "B8 a side name of the kept file appears while the prompt waits" "touch '$KEPT-wal'" "nothing moved: $KEPT-wal appeared"; rm -f "$KEPT-wal"
qb "B10 master's record of the retry job becomes unreadable while the prompt waits" "bash '$SB/sched_clear.sh'" "nothing moved: master's record of the retry job could not be read"
set_sched $(( $(date -u +%s) - 300 ))
check "$(unchanged)" "snapnokeptnorec" "after QB: nothing moved and no record"
# Weakened copies of stage B's reads: each made safe by a link that cannot be made — reaching it means the read was passed.
qbm() {   # qbm LABEL ANCHOR REPLACEMENT HOOK
  local label="$1" old="$2" new="$3" hook="$4" m o
  m="$(mutant "qb-$label" "$QP" "$old" "$new" 'if ! ln -T -- "$SRC" "$KEPT" 2>/dev/null; then' 'if ! false; then')"
  o="$(qrun --answer QUARANTINE --hook "$hook" --script "$m")"; rm -f "$m"
  check "$(has "$o" 'nothing moved: the link could not be made')$(rec_last Q_STAGE)$( [ -e "$KEPT" ] && echo kept || echo nokept)" "yesabandonednokept" "B' $label: the copy without that read goes on to the link (caught); safe here: its link is never made, and it records abandoned"
  rm -f "$QREC" "$QD"/quarantine.env.void-*
}
qbm held 'holders "$src_key"; held_by_none || stop "nothing moved: the stray store, read again now' 'holders "$src_key"; : || stop "nothing moved: the stray store, read again now' \
  "python3 -c 'import os,time; fd=os.open(\"$SRC\", os.O_RDONLY); open(\"$SB/qb.held\",\"w\").close(); time.sleep(30)' >/dev/null 2>&1 & echo \$! > '$SB/qb.pid'; for i in \$(seq 1 50); do [ -f '$SB/qb.held' ] && break; sleep 0.1; done"
kill "$(cat "$SB/qb.pid")" 2>/dev/null; rm -f "$SB/qb.pid" "$SB/qb.held"
qbm gate 'g2="$(gate_line)"; gate_ok "$g2" || stop' 'g2="$(gate_line)"; : || stop' "php() { setpriv --reuid 1000 --regid 1000 --clear-groups -- $PHPBIN \"\$@\"; }; php -r '\$f = \$argv[1]; \$c = json_decode((string)file_get_contents(\$f), true); \$c[\"tenant_profile\"] = \"south-sudan\"; \$c[\"currency_code\"] = \"SSP\"; file_put_contents(\$f, json_encode(\$c));' '$DATA/kyc_config.json'; printf '%s' '{\"config\":{\"currency_code\":\"SSP\",\"tenant_profile\":\"south-sudan\"}}' > '$VAULT'"
cfg_back; vault_back
qbm folder '&& [ "$(stray_entries)" = "$ents" ] || stop "nothing moved: the stray folder changed' '|| : "nothing moved: the stray folder changed' "setpriv --reuid 1000 --regid 1000 --clear-groups -- touch '$PD/data/planted.txt'"
rm -f "$PD/data/planted.txt"; touch -d "$REF_DIR_Y" "$PD/data"; SNAP0="$(stray_snap)"
set_sched $(( $(date -u +%s) - 60 )) -1
qbm running 'running:[0-9]*) [ $(( $(date -u +%s) - rt )) -ge 900 ] || stop' 'running:[0-9]*) : || stop' ""
set_sched $(( $(date -u +%s) - 300 ))
qbm record "''|*[!0-9]*) stop \"nothing moved: master's record" "''|*[!0-9]*) : \"nothing moved: master's record" "bash '$SB/sched_clear.sh'"
set_sched $(( $(date -u +%s) - 1000 )) -1
m="$(mutant qb-stale "$QP" 'if ! ln -T -- "$SRC" "$KEPT" 2>/dev/null; then' 'if ! false; then')"; o="$(qrun --answer QUARANTINE --script "$m")"; rm -f "$m"
check "$(has "$o" 'over 15 minutes')$(has "$o" 'nothing moved: the link could not be made')$(rec_last Q_STAGE)" "yesyesabandoned" "B9 a running record over 15 minutes old is noted as one a dead run left, and the move goes on (to a link made safe here)"
rm -f "$QREC" "$QD"/quarantine.env.void-*
set_sched $(( $(date -u +%s) - 300 ))
check "$(unchanged)" "snapnokeptnorec" "after QB': nothing moved"

echo; echo "== Q8. every state a stopped run leaves has one command — made by the REAL script, killed before, between and after its two steps, and in the put-back =="
mkdir -p "$SB/shim"
for t in ln rm; do cat > "$SB/shim/$t" <<SH
#!/usr/bin/env bash
# rehearsal shim for $t — passes every call through, except the one the trial names by an argument: _PREKILL kills the
# script before it, _FAIL fails it (nothing done), _HOLD opens and locks the file first (a holder the script did not
# start, without its lock), _AFTER runs SHIM_AFTER_CMD after it, _KILL kills the script after it
real="\$(PATH="\$(printf '%s' "\$PATH" | sed 's#^$SB/shim:##')" command -v $t)"
want="\${SHIM_${t^^}_KILL:-}"; hold="\${SHIM_${t^^}_HOLD:-}"; pre="\${SHIM_${t^^}_PREKILL:-}"; fail="\${SHIM_${t^^}_FAIL:-}"; after="\${SHIM_${t^^}_AFTER:-}"
case " \$* " in *" \$pre "*) [ -n "\$pre" ] && { kill -KILL \$PPID; exit 0; } ;; esac
case " \$* " in *" \$fail "*) [ -n "\$fail" ] && exit 1 ;; esac
case " \$* " in
  *" \$hold "*) [ -n "\$hold" ] && { python3 -c 'import os,sys,time,fcntl; fd=os.open(sys.argv[1], os.O_RDONLY); fcntl.lockf(fd, fcntl.LOCK_SH); open(sys.argv[2],"w").close(); time.sleep(float(sys.argv[3]))' "\$hold" "$SB/shim.held" "\${SHIM_HOLD_SECS:-120}" >/dev/null 2>&1 9<&- & echo \$! >> "$SB/shim.pids"
         for i in \$(seq 1 50); do [ -f "$SB/shim.held" ] && break; sleep 0.1; done; } ;;
esac
"\$real" "\$@"; rc=\$?
case " \$* " in *" \$after "*) [ -n "\$after" ] && bash "\$SHIM_AFTER_CMD" >/dev/null 2>&1 ;; esac
case " \$* " in *" \$want "*) [ -n "\$want" ] && kill -KILL \$PPID ;; esac
exit \$rc
SH
chmod +x "$SB/shim/$t"; done
shim_stop() { [ -f "$SB/shim.pids" ] && while read -r p; do kill -KILL "$p" 2>/dev/null; done < "$SB/shim.pids"; rm -f "$SB/shim.pids" "$SB/shim.held"; }   # -KILL: the holder inherits the ignored TERM
q_reset() {   # after a put-back: the folder's time as seeded (the file, its inode and its entries are back), the quarantine folder emptied
  touch -d "$REF_DIR_Y" "$PD/data"; q_clean
  check "$(stray_snap)$(stat -c '%d:%i' "$SRC")" "$SNAP0$KEY0" "…reset: the stray folder as seeded, the stray store the same inode"
}
SH_PATH="$SB/shim:$SB/bin:$PATH"
voids() { ls "$QD" | grep -c '^quarantine.env.void-'; }
# 8a: killed straight after the link — the record says prepared, two names.
OUT="$(QPATH="$SH_PATH" qrun --answer QUARANTINE --env "SHIM_LN_KILL=$KEPT")"
check "$(rec_last Q_STAGE)$(stat -c %h "$SRC")$( [ "$(stat -c '%d:%i' "$KEPT")" = "$KEY0" ] && echo samekey)" "prepared2samekey" "8a killed straight after the link: the record says prepared, the stray store has two names (made by the real script)"
OUT="$(qrun --answer NO -- --check)"
check "$(has "$OUT" 'stopped between its two steps (record: prepared)')$(has "$OUT" 'run --put-back')$(has "$OUT" '(check): NOT CONCLUSIVE — HALF-DONE: run --put-back')$(has "$OUT" '(check): PASSED')$(fails "$OUT")" "yesyesyesno0" \
  "…--check: stopped between its two steps — run --put-back; NOT CONCLUSIVE, never PASSED"
OUT="$(qrun --answer NO)"; check "$(has "$OUT" 'STOP: an earlier run stopped between its two steps')$(has "$OUT" 'Run --put-back: it removes only the kept name')$(has "$OUT" 'Until the quarantine has moved the file')$(has "$OUT" 'and its quarantine.env')" "yesyesyesyes" \
  "…the quarantine command names the one command, --put-back, and the rule that binds until the move"
OUT="$(qrun --answer PUTBACK -- --put-back)"
check "$(fails "$OUT")$(has "$OUT" 'the quarantine stopped between its two steps')$(rec_last Q_STAGE)$(stat -c %h "$SRC")$( [ -e "$KEPT" ] && echo kept || echo nokept)$(stray_snap)" "0yesabandoned1nokept$SNAP0" \
  "…--put-back removes only the kept name: the record abandoned, one name, the stray folder exactly as seeded (it was never changed)"
check "$(has "$OUT" 'Until the quarantine has moved the file')$(has "$OUT" 'From now on')" "yesno" "…and prints the rule that binds until the move, not the runbook for after it"
OUT="$(qrun --answer PUTBACK -- --put-back)"
check "$(has "$OUT" 'nothing is kept')$(has "$OUT" 'not the kept stray store')$(has "$OUT" 'Type PUTBACK')$(rec_last Q_STAGE)" "yesnonoabandoned" "…a second --put-back: nothing is kept, nothing to put back — before the word"
# 8a': killed straight BEFORE the link — the abandoned record set aside; the new one says prepared, one name, nothing kept.
OUT="$(QPATH="$SH_PATH" qrun --answer QUARANTINE --env "SHIM_LN_PREKILL=$KEPT")"
check "$(rec_last Q_STAGE)$(stat -c %h "$SRC")$( [ -e "$KEPT" ] && echo kept || echo nokept)$(voids)" "prepared1nokept1" "8a' killed straight before the link: the record says prepared, one name, nothing kept; the abandoned record set aside"
OUT="$(qrun --answer NO -- --check)"
check "$(has "$OUT" 'not quarantined (record: prepared): an earlier run moved nothing')$(has "$OUT" '(check): NOT CONCLUSIVE — NOT QUARANTINED')$(has "$OUT" 'Until the quarantine has moved the file')$(fails "$OUT")" "yesyesyes0" \
  "…--check: not quarantined — nothing checked, NOT CONCLUSIVE, the rule until the move"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(has "$OUT" 'nothing is kept')$(has "$OUT" '(stage prepared)')$(has "$OUT" 'Type PUTBACK')" "yesyesno" "…--put-back: nothing is kept"
# 8b': killed straight before the old name goes — the prepared record set aside; two names, the record says linked.
OUT="$(QPATH="$SH_PATH" qrun --answer QUARANTINE --env "SHIM_RM_PREKILL=$SRC")"
check "$(has "$OUT" 'an earlier run stopped before it moved anything')$(rec_last Q_STAGE)$(stat -c %h "$SRC")$( [ "$(stat -c '%d:%i' "$KEPT")" = "$KEY0" ] && echo samekey)$(voids)" "yeslinked2samekey2" \
  "8b' killed straight before the old name went: the prepared record set aside; the record says linked, two names"
OUT="$(qrun --answer NO -- --check)"
check "$(has "$OUT" 'stopped between its two steps (record: linked)')$(has "$OUT" '(check): NOT CONCLUSIVE — HALF-DONE: run --put-back')$(fails "$OUT")" "yesyes0" "…--check: two names — run --put-back"
OUT="$(qrun --answer NO)"; check "$(has "$OUT" 'STOP: an earlier run stopped between its two steps')$(has "$OUT" 'Until the quarantine has moved the file')" "yesyes" "…the quarantine command: --put-back, and the rule until the move"
OUT="$(qrun --answer NO --env UCRM_CONTAINER=no-such-container)"
check "$(has "$OUT" "STOP: container 'no-such-container' has no /data mount")$(has "$OUT" 'Until the quarantine has moved the file')" "yesyes" "…a stop before the plugin folder is found still prints it: the old name is read from the record"
M="$(mutant q8-halfrule "$QP" '  if [ "$case_" = "half" ] && [ -z "$pst" ]; then pre_rule; else runbook; fi; summary' '  runbook; summary')"
OUT="$(qrun --answer PUTBACK --script "$M" -- --put-back)"; rm -f "$M"
check "$(fails "$OUT")$(rec_last Q_STAGE)$(stat -c %h "$SRC")$( [ -e "$KEPT" ] && echo kept || echo nokept)$(stray_snap)" "0abandoned1nokept$SNAP0" "…--put-back: the kept name removed, the record abandoned, the stray folder as seeded"
check "$(has "$OUT" 'Until the quarantine has moved the file')$(has "$OUT" 'From now on')" "noyes" "8b2 a copy that closes a half-done quarantine with the runbook for after the move tells the operator rules that are untrue now (caught by 8a's check)"
# 8b: killed straight after the old name went — the record says linked, the old name gone.
OUT="$(QPATH="$SH_PATH" qrun --answer QUARANTINE --env "SHIM_RM_KILL=$SRC")"
check "$(rec_last Q_STAGE)$( [ -e "$SRC" ] && echo src || echo nosrc)$(stat -c %h "$KEPT")$(voids)" "linkednosrc13" "8b killed straight after the old name went: the record says linked, the old name gone, the kept file one name; the abandoned record set aside"
OUT="$(qrun --answer NO -- --check)"
check "$(has "$OUT" 'did not finish its checks (record: linked) — run the quarantine command again')$(has "$OUT" '(check): NOT CONCLUSIVE — HALF-DONE: run the quarantine command again')$(fails "$OUT")" "yesyes0" \
  "…--check names the one command: the quarantine again; NOT CONCLUSIVE"
OUT="$(qrun --answer NO)"
check "$(fails "$OUT")$(has "$OUT" 'did not finish its checks: this run does them, and changes nothing')$(has "$OUT" 'Type QUARANTINE')$(rec_last Q_STAGE)$( [ -n "$(rec_last Q_AT)" ] && echo qat)" "0yesnodoneqat" \
  "…the quarantine command resumes: stage C alone, no question, done — the move's time taken from the kept file's change time"
check "$(okline "$OUT" C3 'exactly as before, less the one entry')$(okline "$OUT" C4 'the stray store itself')$(okline "$OUT" C6 'no process holds')" "yesyesyes" "…its stage C passes from the record"
check "$(has "$OUT" '(quarantine): PASSED. Send this LOG FILE back (not a copy of the terminal), with every *.own.log in')$(has "$OUT" 'quarantine.env.')" "yesyes" "…and its last line asks for every own log — the first run's too — and the record"
# 8c: the put-back killed straight after ITS link — PUTBACK_STAGE prepared, two names.
OUT="$(QPATH="$SH_PATH" qrun --answer PUTBACK --env "SHIM_LN_KILL=$SRC" -- --put-back)"
check "$(rec_last PUTBACK_STAGE)$(stat -c %h "$SRC")$( [ -n "$(rec_last PUTBACK_AT)" ] && echo pat || echo nopat)" "prepared2nopat" "8c the put-back killed straight after its link: the record says a put-back began, two names"
OUT="$(qrun --answer NO)"; check "$(has "$OUT" 'STOP: a put-back did not finish: run --put-back again')" "yes" "…the quarantine command names --put-back"
OUT="$(qrun --answer NO -- --check)"; check "$(has "$OUT" 'K1 a put-back did not finish')$(has "$OUT" '(check): NOT CONCLUSIVE — a put-back did not finish')$(fails "$OUT")" "yesyes0" "…--check names --put-back, NOT CONCLUSIVE"
OUT="$(qrun --answer PUTBACK -- --put-back)"
check "$(fails "$OUT")$(has "$OUT" 'a put-back stopped between its two steps')$(okline "$OUT" PB1 'the stray store again')$( [ -n "$(rec_last PUTBACK_AT)" ] && echo pat)$(stat -c '%d:%i' "$SRC")$(stat -c %w "$SRC")" "0yesyespat$KEY0$BIRTH0" \
  "…--put-back again: the kept name removed, the put-back recorded — the same file at the old name, key and birth as seeded"
OUT="$(qrun --answer NO)"; check "$(has "$OUT" 'STOP: the stray store was put back at')" "yes" "…after a put-back, the quarantine refuses: a second one needs its own reviewed script"
q_reset
# 8d: the put-back killed straight after the kept name went — only its record is missing.
OUT="$(qrun --answer QUARANTINE)"; check "$(rec_last Q_STAGE)$(fails "$(qlast)")" "done0" "8d a quarantine to its end"
OUT="$(QPATH="$SH_PATH" qrun --answer PUTBACK --env "SHIM_RM_KILL=$KEPT" -- --put-back)"
check "$(rec_last PUTBACK_STAGE)$( [ -e "$KEPT" ] && echo kept || echo nokept)$(stat -c %h "$SRC")$( [ -n "$(rec_last PUTBACK_AT)" ] && echo pat || echo nopat)" "linkednokept1nopat" "…the put-back killed straight after the kept name went: one name, no record of the end"
OUT="$(qrun --answer PUTBACK -- --put-back)"
check "$(fails "$OUT")$(has "$OUT" 'only the record is written')$( [ -n "$(rec_last PUTBACK_AT)" ] && echo pat)$(rec_last PUTBACK_DIR_T)" "0yespat$(stat -c %Y "$PD/data")" "…--put-back again: only the record is written, the folder's reference with it"
q_reset
# 8e: the put-back's link refused (nothing done): the marker is taken back, so --check still judges.
OUT="$(qrun --answer QUARANTINE)"; check "$(rec_last Q_STAGE)$(fails "$(qlast)")" "done0" "8e a quarantine to its end"; cp -p "$QREC" "$SB/qrec8.kept"
OUT="$(QPATH="$SH_PATH" qrun --answer PUTBACK --env "SHIM_LN_FAIL=$SRC" -- --put-back)"
check "$(has "$OUT" 'STOP: nothing changed: the link could not be made')$(rec_last PUTBACK_STAGE | grep -c .)$( [ -e "$SRC" ] && echo src || echo nosrc)$( [ "$(stat -c '%d:%i' "$KEPT")" = "$KEY0" ] && echo kept)" "yes0nosrckept" \
  "8e the put-back's link refused: nothing changed, and the put-back's marker is taken back"
OUT="$(qrun -- --check)"; check "$(has "$OUT" 'a put-back did not finish')$(okline "$OUT" K4)$(okline "$OUT" K8)" "noyesyes" "…--check judges again (K4, K8), not 'a put-back did not finish'"
M="$(mutant q8-pbreset "$QP" '{ rec_put PUTBACK_STAGE ""; absent "$SRC" && stop "nothing changed: the link could not be made"' '{ absent "$SRC" && stop "nothing changed: the link could not be made"')"
OUT="$(QPATH="$SH_PATH" qrun --answer PUTBACK --script "$M" --env "SHIM_LN_FAIL=$SRC" -- --put-back)"; rm -f "$M"; OUT="$(qrun -- --check)"
check "$(has "$OUT" 'a put-back did not finish')$(okline "$OUT" K4)" "yesno" "8e' a copy that leaves the marker switches --check off for good (caught)"; cp -p "$SB/qrec8.kept" "$QREC"
if chattr +i "$KEPT" 2>/dev/null; then
  OUT="$(qrun --answer PUTBACK -- --put-back)"; chattr -i "$KEPT"
  check "$(has "$OUT" 'NO-GO: an immutable or append-only attribute is set on the kept file')$(has "$OUT" 'Type PUTBACK')$(rec_last PUTBACK_STAGE | grep -c .)" "yesno0" "8e'' an immutable kept file: the put-back refuses before the word"
else check "chattr refused" "chattr" "8e'' chattr +i works on this filesystem"; fi
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(fails "$OUT")$(okline "$OUT" PB1 'the stray store again')" "0yes" "…then the put-back: the same file at the old name"
q_reset
# 8f: a resumed run whose holder controls do not pass (the container's control refused): C6 not measured — never lasting.
OUT="$(QPATH="$SH_PATH" qrun --answer QUARANTINE --env "SHIM_RM_KILL=$SRC")"; check "$(rec_last Q_STAGE)" "linked" "8f killed straight after the old name went"
OUT="$(qrun --answer NO --env FAKE_EXEC_D_FAIL=1)"
check "$(has "$OUT" 'note  C6 whether anything holds the moved file was not measured')$(fails "$OUT")$(has "$OUT" '(quarantine): NOT CONCLUSIVE — the holders were not measured')$(rec_last Q_STAGE)$(rec_last Q_CHECK_FAILED | grep -c .)" "yes0yesdone0" \
  "…resumed with the container's holder control refused: C6 not measured, NOT CONCLUSIVE, done, nothing recorded as failed"
OUT="$(qrun -- --check)"; check "$(failline "$OUT" K0)$(okline "$OUT" K10)" "noyes" "…the next --check measures the holders again (K10), and no K0"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(fails "$OUT")" "0" "…put back"; q_reset
OUT="$(QPATH="$SH_PATH" qrun --answer QUARANTINE --env "SHIM_RM_KILL=$SRC")"
M="$(mutant q8-c6ctl "$QP" '    note "C6 whether anything holds the moved file was not measured: the controls did not pass' '    bad "C6 whether anything holds the moved file was not measured: the controls did not pass')"
OUT="$(qrun --answer NO --script "$M" --env FAKE_EXEC_D_FAIL=1)"; rm -f "$M"
check "$(failline "$OUT" C6)$(rec_last Q_CHECK_FAILED | grep -c .)" "yes1" "8f2 a copy that FAILS C6 on an unmeasured holder records a failure that nothing observed, for good (caught)"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(fails "$OUT")" "0" "…put back"; q_reset
# 8g: the old name's removal refused (nothing done): the kept name is taken back, and nothing has moved.
OUT="$(QPATH="$SH_PATH" qrun --answer QUARANTINE --env "SHIM_RM_FAIL=$SRC")"
check "$(has "$OUT" 'nothing moved: the old name could not be removed; the kept name was removed again')$(rec_last Q_STAGE)$(stat -c %h "$SRC")$(stat -c '%d:%i' "$SRC")$( [ -e "$KEPT" ] && echo kept || echo nokept)$(has "$OUT" 'Until the quarantine has moved the file')" "yesabandoned1${KEY0}nokeptyes" \
  "8g the old name's removal refused: the kept name taken back, the record abandoned, the stray store one name as seeded, the rule until the move printed"
check "$(stray_snap)" "$SNAP0" "…the stray folder exactly as seeded"
M="$(mutant q8-takeback "$QP" '      rm -f -- "$KEPT" 2>/dev/null
      if absent "$KEPT" && [ "$(links_of "$SRC")" = "1" ]; then rec_put Q_STAGE abandoned' '      if absent "$KEPT" && [ "$(links_of "$SRC")" = "1" ]; then rec_put Q_STAGE abandoned')"
OUT="$(QPATH="$SH_PATH" qrun --answer QUARANTINE --script "$M" --env "SHIM_RM_FAIL=$SRC")"; rm -f "$M"
check "$(has "$OUT" 'the kept name could not be taken back')$(rec_last Q_STAGE)$(stat -c %h "$SRC")" "yeslinked2" "8g' a copy that does not take the kept name back leaves two names and a record that says linked (caught)"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(fails "$OUT")$(rec_last Q_STAGE)$(stat -c %h "$SRC")" "0abandoned1" "…which --put-back closes"; q_reset
# 8h: a half-done quarantine whose old name goes while PUTBACK waits: the kept name — then the store's only one — stays.
OUT="$(QPATH="$SH_PATH" qrun --answer QUARANTINE --env "SHIM_LN_KILL=$KEPT")"; check "$(rec_last Q_STAGE)$(stat -c %h "$SRC")" "prepared2" "8h killed straight after the link: two names"
OUT="$(qrun --answer PUTBACK --hook "rm -f '$SRC'" -- --put-back)"
check "$(has "$OUT" 'are no longer the stray store')$( [ "$(stat -c '%d:%i' "$KEPT")" = "$KEY0" ] && echo kept)$(stat -c %h "$KEPT")$(rec_last Q_STAGE)" "yeskept1prepared" \
  "…the old name removed while PUTBACK waits: read again after the word, refused — the kept name, now the store's only one, is not removed"
ln "$KEPT" "$SRC"; ln "$SRC" "$SB/store.safety"   # the two names back, and a third, so that the weakened copy below cannot lose the store
M="$(mutant q8-halfre "$QP" '      [ -f "$SRC" ] && [ ! -L "$SRC" ] && [ "$(key_of "$SRC")" = "$kq" ] && [ "$(key_of "$KEPT")" = "$kq" ] && [ "$(links_of "$KEPT")" = "2" ] \' '      true \')"
OUT="$(qrun --answer PUTBACK --script "$M" --hook "rm -f '$SRC'" -- --put-back)"; rm -f "$M"
check "$( [ -e "$KEPT" ] && echo kept || echo nokept)$( [ -e "$SRC" ] && echo src || echo nosrc)$(stat -c %h "$SB/store.safety")" "nokeptnosrc1" \
  "8h' a copy that does not read the names again after the word removes the store's last name (caught — it survives here only by the third name the trial made)"
ln "$SB/store.safety" "$SRC"; rm -f "$SB/store.safety"; q_reset
# 8i: linked, the store at its old name with one name, nothing kept — what an undo interrupted before its record leaves.
OUT="$(QPATH="$SH_PATH" qrun --answer QUARANTINE --env "SHIM_RM_PREKILL=$SRC")"; rm -f "$KEPT"
check "$(rec_last Q_STAGE)$(stat -c %h "$SRC")$( [ -e "$KEPT" ] && echo kept || echo nokept)" "linked1nokept" "8i killed before the old name went, then the kept name removed (an undo interrupted before its record): linked, one name, nothing kept"
OUT="$(qrun -- --check)"; check "$(has "$OUT" 'not quarantined (record: linked)')$(fails "$OUT")$(has "$OUT" '(check): NOT CONCLUSIVE — NOT QUARANTINED')" "yes0yes" "…--check: not quarantined — never a FAIL"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(has "$OUT" 'nothing is kept')$(has "$OUT" 'the record says linked')$(has "$OUT" 'by another hand')$(has "$OUT" 'Type PUTBACK')" "yesyesnono" "…--put-back: nothing to put back — not 'by another hand'"
M="$(mutant q8-linked1 "$QP" '      if [ "$st" = "linked" ] && key_ok "$kq" && [ -f "$SRC" ]' '      if false && [ -f "$SRC" ]')"; OUT="$(qrun --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" 'the files are not as that leaves them')$(went_go "$OUT")" "yesnonono" "8i' a copy without that case sends the operator to us over a state the script's own undo leaves (caught)"
OUT="$(qrun --answer NO)"; check "$(has "$OUT" 'an earlier run moved nothing in the end (its record: linked')$(went_go "$OUT")" "yesyesyesyes" "…the quarantine command sets that record aside and starts again, to GO"
q_reset
# 8j: a run resumed 25 minutes after the move, its gate not measured: one --check time in its log, and not one already past.
ho_times() { VT="$(sed -n 's/.*(quarantine): NOT CONCLUSIVE — the gate was not measured: run --check at or after \([0-9TZ:-]*\)\..*/\1/p' <<<"$1")"; HT="$(sed -n 's/^  --check           at or after \([0-9TZ:-]*\):.*/\1/p' <<<"$1")"; }
OUT="$(QPATH="$SH_PATH" qrun --answer QUARANTINE --env "SHIM_RM_KILL=$SRC")"; printf 'Q_AT=%s\n' "$(( $(date -u +%s) - 1500 ))" >> "$QREC"
OUT="$(qrun --answer NO --env FAKE_GATE_FAIL_ON_MOVE=1)"; ho_times "$(qlast)"
check "$( [ -n "$VT" ] && [ "$VT" = "$HT" ] && echo same || echo "differ:$VT/$HT")$( [ -n "$VT" ] && [ "$(date -u -d "$VT" +%s)" -gt "$(date -u +%s)" ] && echo ahead)" "sameahead" \
  "8j a run resumed 25 minutes after the move, its gate not measured: the verdict and the hand-over name one --check time, ten minutes on — not the move + 20 minutes, already past"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(fails "$OUT")" "0" "…put back"; q_reset
OUT="$(QPATH="$SH_PATH" qrun --answer QUARANTINE --env "SHIM_RM_KILL=$SRC")"; printf 'Q_AT=%s\n' "$(( $(date -u +%s) - 1500 ))" >> "$QREC"
M="$(mutant q8-ho "$QP" '  say "  --check           at or after $(check_at):' '  say "  --check           at or after $(check_time "$(rec_get Q_AT)"):')"; OUT="$(qrun --answer NO --script "$M" --env FAKE_GATE_FAIL_ON_MOVE=1)"; rm -f "$M"; ho_times "$(qlast)"
check "$( [ -n "$VT" ] && [ -n "$HT" ] && [ "$VT" != "$HT" ] && echo differ)" "differ" "8j' a copy whose hand-over keeps the move + 20 minutes names two times in one log, one already past (caught)"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(fails "$OUT")" "0" "…put back"; q_reset
# 8k–8p: the record's writes refused as on a full disk — strace's fault injection on that one file: ENOSPC from the Nth write on.
# bash writes a printf's output a line at a time, so the Nth write is the record's Nth line: the prepared block's lines are
# counted from the script itself (asserted), then linked, Q_AT, moved, and stage C's first.
nospace() { printf 'strace -f -qq -o /dev/null -P %s -e trace=write -e inject=write:error=ENOSPC%s' "$QREC" "${1:+:when=$1}"; }
PREP="$(sed -n '/printf .Q_TS=%s/,/>> "\$RECORD"/p' "$QP" | grep -o '\\n' | wc -l)"
check "$PREP" "20" "control: the prepared record is 20 lines, each its own write — so the marker 'linked' is write 21, Q_AT 22, moved 23, stage C's first 24"
OUT="$(qrun --answer QUARANTINE --wrap "$(nospace)")"
check "$(has "$OUT" 'nothing moved: the record could not be written (is the disk full?)')$( [ -e "$QREC" ] && echo rec || echo norec)$(unchanged)$(has "$OUT" 'Until the quarantine has moved the file')$(has "$OUT" 'quarantine.env')" "yesnorecsnapnokeptnorecyesno" \
  "8k the record's first write refused (the file made, nothing in it): nothing moved, the record removed — a fresh one — and the stop asks for no record"
OUT="$(qrun --answer NO)"; check "$(went_go "$OUT")" "yesyesyes" "…the next run is not blocked by it: GO"
M="$(mutant q8-emptyrec "$QP" '|| { rm -f -- "$RECORD"; stop' '|| { stop')"; OUT="$(qrun --answer QUARANTINE --script "$M" --wrap "$(nospace)")"; rm -f "$M"
OUT="$(qrun --answer NO)"; check "$(stat -c %s "$QREC" 2>/dev/null)$(has "$OUT" 'exists with no stage in it')" "0yes" "8k' a copy that leaves the empty record blocks every later run (caught)"; rm -f "$QREC"
OUT="$(qrun --answer QUARANTINE --wrap "$(nospace 3+)")"
check "$(has "$OUT" 'nothing moved: the record could not be written (is the disk full?)')$( [ -e "$QREC" ] && echo rec || echo norec)$(unchanged)$(has "$OUT" 'quarantine.env')" "yesnorecsnapnokeptnorecno" \
  "8k2 the record cut off after its second line (Q_TS, Q_STAGE=prepared): nothing moved, and the record — a fresh one — removed whole"
OUT="$(qrun --answer NO)"; check "$(went_go "$OUT")$(has "$OUT" 'an earlier run')" "yesyesyesno" "…the next run starts as the first did, to GO"; q_clean
# 8q: a write cut inside a line on a really full filesystem (a private 16 KB tmpfs): the script's own rec_put leaves the
# record byte for byte as it was. (strace's injection above refuses a write whole; a full disk cuts it at a block's end.)
fullfs() {   # fullfs REC_PUT_DEFINITION — "same|changed RC STAGE" after rec_put Q_STAGE done on a record 10 bytes short of a full page, the filesystem full
  local d; d="$(mktemp -d -p "$SB")"
  unshare --mount --propagation private bash -c '
    eval "$1"; d="$2"; mount -t tmpfs -o size=16k tmpfs "$d" || { echo nomount; exit 0; }
    RECORD="$d/quarantine.env"
    { printf "Q_STAGE=moved\n"; head -c 4071 /dev/zero | tr "\0" x; printf "\n"; } > "$RECORD"
    dd if=/dev/zero of="$d/fill" bs=512 2>/dev/null; b="$(sha256sum < "$RECORD")"
    rec_put Q_STAGE done 2>/dev/null; rc=$?; a="$(sha256sum < "$RECORD")"
    echo "$( [ "$a" = "$b" ] && echo same || echo changed) $rc $(sed -n "s/^Q_STAGE=//p" "$RECORD" | tail -n1)"' _ "$1" "$d"
  rmdir "$d" 2>/dev/null
}
check "$(grep -c '^rec_put() {' "$QP")|$(fullfs "$(grep '^rec_put() {' "$QP")")" "1|same 1 moved" \
  "8q a write cut inside a line on a really full filesystem (a private 16 KB tmpfs): rec_put fails and leaves the record byte for byte as it was — the stage still moved"
M="$(mutant q8-trunc "$QP" '[ -n "$n" ] && truncate -s "$n" -- "$RECORD" 2>/dev/null; return 1; }' 'return 1; }')"
check "$(fullfs "$(grep '^rec_put() {' "$M")")" "changed 1 do" "8q' a copy without the truncate leaves a half line: the stage reads 'do', which no mode accepts (caught)"; rm -f "$M"
OUT="$(qrun --answer QUARANTINE --wrap "$(nospace $(( PREP + 1 ))+)")"
check "$(has "$OUT" 'nothing removed: the record could not be written (is the disk full?)')$(rec_last Q_STAGE)$(stat -c %h "$SRC")$(has "$OUT" 'Until the quarantine has moved the file')" "yesprepared2yes" \
  "8l the marker before the unlink refused: nothing removed — two names, the record prepared — and --put-back named"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(fails "$OUT")$(rec_last Q_STAGE)$(stat -c %h "$SRC")" "0abandoned1" "…which --put-back closes"; q_reset
OUT="$(qrun --answer QUARANTINE --wrap "$(nospace $(( PREP + 2 ))+)")"
check "$(has "$OUT" 'the file is moved, but the record could not be written')$(rec_last Q_STAGE)$( [ -e "$SRC" ] && echo src || echo nosrc)$(has "$OUT" '(quarantine): PASSED')" "yeslinkednosrcno" \
  "8m the move's own record refused after the unlink: it stops saying so — the record linked, the old name gone — never PASSED"
OUT="$(qrun --answer NO)"; check "$(has "$OUT" 'this run does them, and changes nothing')$(rec_last Q_STAGE)$(fails "$OUT")" "yesdone0" "…the quarantine command resumes it: done"
OUT="$(qrun --answer PUTBACK --wrap "$(nospace)" -- --put-back)"
check "$(has "$OUT" 'nothing changed: the record could not be written (is the disk full?)')$( [ -e "$SRC" ] && echo src || echo nosrc)$( [ "$(stat -c '%d:%i' "$KEPT")" = "$KEY0" ] && echo kept)$(rec_last PUTBACK_STAGE | grep -c .)" "yesnosrckept0" \
  "8n the put-back's first marker refused: nothing changed — the kept file kept, no put-back recorded"
M="$(mutant q8-pbmark "$QP" '      rec_put PUTBACK_STAGE prepared || stop "nothing changed: the record could not be written' '      rec_put PUTBACK_STAGE prepared; : "nothing changed: the record could not be written')"
OUT="$(qrun --answer PUTBACK --script "$M" --wrap "$(nospace)" -- --put-back)"; rm -f "$M"
check "$(okline "$OUT" PB1)$(rec_last PUTBACK_STAGE | grep -c .)$(stat -c %h "$SRC")$( [ -e "$KEPT" ] && echo kept || echo nokept)" "yes01nokept" \
  "8n' a copy without that precondition puts the file back while the record says nothing of it (caught)"
printf 'PUTBACK_STAGE=linked\n' >> "$QREC"; OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(has "$OUT" 'only the record is written')$(fails "$OUT")" "yes0" "…its record written by hand to what it left, then --put-back writes the rest"; q_reset
OUT="$(qrun --answer QUARANTINE --wrap "$(nospace $(( PREP + 4 ))+)")"
check "$(has "$OUT" '(quarantine): NOT CONCLUSIVE — the record could not be written (is the disk full?)')$(rec_last Q_STAGE)$(fails "$OUT")$(has "$OUT" 'note  C10 the record could not be written')$(has "$OUT" '  --check           at or after')$(has "$OUT" '--put-back 2>&1 | tee')" "yesmoved0yesnono" \
  "8o stage C's record refused: NOT CONCLUSIVE, the record left at moved — never done over a record it could not finish; said as a line, and no --check time or put-back beside it"
OUT="$(qrun --answer NO)"; check "$(rec_last Q_STAGE)$(fails "$OUT")$(has "$OUT" '(quarantine): PASSED')" "done0yes" "…the quarantine command finishes it: done, PASSED"
OUT="$(qrun --answer PUTBACK --wrap "$(nospace 2+)" -- --put-back)"
check "$(okline "$OUT" PB1)$(has "$OUT" '(putback): NOT CONCLUSIVE — the put-back is done, but its record could not be written')$(rec_last PUTBACK_STAGE)$(rec_last PUTBACK_AT | grep -c .)" "yesyesprepared0" \
  "8p the put-back's closing writes refused: the file is back, NOT CONCLUSIVE (never PASSED), the record left at prepared"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(has "$OUT" 'only the record is written')$(fails "$OUT")$(rec_last PUTBACK_AT | grep -c .)" "yes01" "…--put-back again writes only the record"; q_reset
rm -f "$SB/recycle-until"; OUT="$(qrun --answer QUARANTINE --env FAKE_RECYCLE_ON_MOVE=150 --wrap "$(nospace $(( PREP + 4 ))+)")"; rm -f "$SB/recycle-until"
check "$(has "$OUT" 'note  C7 the live database')$(has "$OUT" "note  C8 the gate's question could not be asked")$(has "$OUT" 'note  C10 the record could not be written')$(has "$OUT" '(quarantine): NOT CONCLUSIVE — the record could not be written')$(rec_last Q_STAGE)$(has "$OUT" 'run --check at or after')" "yesyesyesyesmovedno" \
  "8o2 the same with C7 and C8 not measured first (the container recycling): the record's failure is still said, and it is the log's one step — C8's note names no --check time"
OUT="$(qrun --answer NO)"; check "$(rec_last Q_STAGE)$(fails "$OUT")" "done0" "…resumed: done"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(fails "$OUT")" "0" "…put back"; q_reset
# 8r: a resumed run over a failure its first run recorded before it could finish: it stops — never PASSED over it.
OUT="$(QPATH="$SH_PATH" qrun --answer QUARANTINE --env "SHIM_RM_KILL=$SRC")"; printf 'Q_CHECK_FAILED=%s\n' "$(date -u +%s)" >> "$QREC"
OUT="$(qrun --answer NO)"
check "$(has "$OUT" 'and its own checks FAILED at')$(has "$OUT" 'before its record was finished')$(has "$OUT" '(quarantine): PASSED')$(has "$OUT" '--put-back 2>&1 | tee')$(rec_last Q_STAGE)" "yesyesnonolinked" \
  "8r a moved record holding its first run's failure: the quarantine command stops — never resumed to PASSED over it"
O2="$(qrun -- --check)"; check "$(failline "$O2" K1 'its own checks FAILED at')$(has "$O2" 'run the quarantine command again')" "yesno" "…--check says the same, not 'run the quarantine command again'"
M="$(mutant q8-resfail "$QP" '[ -z "$(rec_get Q_CHECK_FAILED)" ] || stop "an earlier run moved the file' ': || stop "an earlier run moved the file')"; OUT="$(qrun --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" '(quarantine): PASSED')" "yes" "8r' a copy without that stop resumes and says PASSED over the recorded failure (caught)"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(fails "$OUT")" "0" "…put back"; q_reset

# 8s–8u: stage C FAILS (a holder opens the store just before the unlink, as 3e) on a disk that fills: the log names one
# next step — never the quarantine command, which would judge afresh — and says whether the record holds the failure.
# Under strace -f a run ends when its holder does: the shim's holder lasts 40 s here.
OUT="$(QPATH="$SH_PATH" qrun --answer QUARANTINE --env "SHIM_RM_HOLD=$SRC" --env SHIM_HOLD_SECS=40 --wrap "$(nospace $(( PREP + 4 ))+)")"; OWNLOG="$(qlast)"; shim_stop
check "$(failline "$OWNLOG" C6)$(has "$OWNLOG" 'note  C10 the record could not be written')$(has "$OWNLOG" 'the failure above is NOT recorded in it')$(has "$OWNLOG" 'Do not run the quarantine command again')$(has "$OWNLOG" 'then run the quarantine command again')$(has "$OWNLOG" '(quarantine): 1 FAILED — send the log file')$(rec_last Q_STAGE)$(rec_last Q_CHECK_FAILED | grep -c .)" "yesyesyesyesnoyesmoved0" \
  "8s a C6 FAIL, every record write after the move refused: C10 says the failure is NOT recorded and not to run the quarantine command again — the verdict's step is the log's one step"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(fails "$OUT")" "0" "…put back"; q_reset
M="$(mutant q8-c10fail "$QP" '    if [ "$FAIL" = "0" ]; then
      note "C10' '    if true; then
      note "C10')"
OUT="$(QPATH="$SH_PATH" qrun --answer QUARANTINE --script "$M" --env "SHIM_RM_HOLD=$SRC" --env SHIM_HOLD_SECS=40 --wrap "$(nospace $(( PREP + 4 ))+)")"; OWNLOG="$(qlast)"; shim_stop; rm -f "$M"
check "$(failline "$OWNLOG" C6)$(has "$OWNLOG" 'then run the quarantine command again')$(has "$OWNLOG" '(quarantine): 1 FAILED')" "yesyesyes" \
  "8s' a copy whose C10 names the quarantine command after a FAIL gives two next steps — the one it names resumes and judges afresh (caught)"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(fails "$OUT")" "0" "…put back"; q_reset
OUT="$(QPATH="$SH_PATH" qrun --answer QUARANTINE --env "SHIM_RM_HOLD=$SRC" --env SHIM_HOLD_SECS=40 --wrap "$(nospace $(( PREP + 5 ))+)")"; OWNLOG="$(qlast)"; shim_stop
check "$(failline "$OWNLOG" C6)$(has "$OWNLOG" 'note  C10 the record could not be finished')$(has "$OWNLOG" 'it holds the failure above (Q_CHECK_FAILED)')$(has "$OWNLOG" 'then run the quarantine command again')$(rec_last Q_STAGE)$(rec_last Q_CHECK_FAILED | grep -c .)" "yesyesyesnomoved1" \
  "8t the same with the failure's own line written first (write $(( PREP + 4 ))), the rest refused: C10 says the record holds it"
OUT="$(qrun --answer NO)"; check "$(has "$OUT" 'and its own checks FAILED at')$(has "$OUT" 'before its record was finished')$(has "$OUT" '(quarantine): PASSED')" "yesyesno" "…and the quarantine command, run again, stops over it"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(fails "$OUT")" "0" "…put back"; q_reset
OUT="$(QPATH="$SH_PATH" qrun --answer QUARANTINE --env "SHIM_RM_HOLD=$SRC" --env SHIM_HOLD_SECS=40 --wrap "$(nospace $(( PREP + 4 )))")"; OWNLOG="$(qlast)"; shim_stop
check "$(failline "$OWNLOG" C6)$(rec_last Q_STAGE)$(rec_last Q_CHECK_FAILED | grep -c .)$(has "$OWNLOG" 'note  C10')" "yesdone1no" \
  "8u only the failure's first write refused (write $(( PREP + 4 ))): it is written again after C8 — the record ends done, holding it"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(fails "$OUT")" "0" "…put back"; q_reset
M="$(mutant q8-early2 "$QP" '  fail_record
  # The commit is read through the container' '  # The commit is read through the container' '  gate_after C8; fail_record
' '  gate_after C8
')"
OUT="$(QPATH="$SH_PATH" qrun --answer QUARANTINE --script "$M" --env "SHIM_RM_HOLD=$SRC" --env SHIM_HOLD_SECS=40 --wrap "$(nospace $(( PREP + 4 )))")"; OWNLOG="$(qlast)"; shim_stop; rm -f "$M"
check "$(rec_last Q_STAGE)$(rec_last Q_CHECK_FAILED | grep -c .)$(has "$OWNLOG" 'note  C10')" "moved0yes" "8u' a copy that records the failure only in the closing chain loses that one write for good (caught)"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(fails "$OUT")" "0" "…put back"; q_reset
# 8v: the same holder, the run killed (SIGKILL) at stage C's first container call — C7's commit read, after C6: the
# record already holds the failure, and the resumed run stops over it.
rm -f "$SB/killed-on-move"; OUT="$(QPATH="$SH_PATH" qrun --answer QUARANTINE --env "SHIM_RM_HOLD=$SRC" --env "FAKE_KILL_ON_MOVE=$QP")"; OWNLOG="$(qlast)"; shim_stop
check "$( [ -f "$SB/killed-on-move" ] && echo killed)$(failline "$OWNLOG" C6)$(has "$OWNLOG" 'stray-store quarantine (quarantine):')$(rec_last Q_STAGE)$(rec_last Q_CHECK_FAILED | grep -c .)" "killedyesnomoved1" \
  "8v a run killed after its C6 FAIL, at its next container call: no verdict, and the record already holds the failure"
rm -f "$SB/killed-on-move"
OUT="$(qrun --answer NO)"; check "$(has "$OUT" 'and its own checks FAILED at')$(has "$OUT" '(quarantine): PASSED')$(has "$OUT" '--put-back 2>&1 | tee')" "yesnono" "…the resumed run stops over it — never PASSED"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(fails "$OUT")" "0" "…put back"; q_reset
M="$(mutant q8-early "$QP" '  fail_record
  # The commit is read through the container' '  # The commit is read through the container')"
OUT="$(QPATH="$SH_PATH" qrun --answer QUARANTINE --script "$M" --env "SHIM_RM_HOLD=$SRC" --env "FAKE_KILL_ON_MOVE=$M")"; shim_stop; rm -f "$M"
check "$( [ -f "$SB/killed-on-move" ] && echo killed)$(rec_last Q_STAGE)$(rec_last Q_CHECK_FAILED | grep -c .)" "killedmoved0" "8v' a copy without the record line straight after C6 loses the failure to the kill (caught)"
rm -f "$SB/killed-on-move"
OUT="$(qrun --answer NO)"; check "$(has "$OUT" '(quarantine): PASSED')" "yes" "…and the resumed run — the real script, which cannot know — says PASSED over it"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(fails "$OUT")" "0" "…put back"; q_reset

echo; echo "== Q7. a destination behind a bind mount of the same device: nothing moved =="
mkdir -p "$SB/bindsrc"
OUT="$(qrun --answer QUARANTINE --script "$(qpin qpin7 "" "")" --wrap "unshare --mount --propagation private sh -c 'mount --bind \"\$1\" \"\$2\" && shift 2 && exec \"\$@\"' sh $SB/bindsrc $QD")"
check "$(has "$OUT" 'nothing moved: the link could not be made')$(stray_snap)$( [ -e "$SB/bindsrc/stray-plugin.sqlite3.kept" ] && echo kept || echo nokept)" "yes${SNAP0}nokept"  \
  "7a the quarantine folder a bind mount (same device, another mount): the link fails, nothing is moved, the record says abandoned"
rm -f "$REPO/scripts/.qpin7.sh"; rm -rf "${SB:?}/bindsrc"; rm -f "$QREC"

echo; echo "== Q3. the quarantine — through a Ctrl-C and a hang-up of the operator's terminal =="
DQ3="$(data_digest)"   # 3a's reference, taken after Q1–Q8's own writes of master's record (1aa, QB): the live store as the run finds it
OUT="$(qrun --answer QUARANTINE --at 'the old name is gone at' --intr INT)"
for i in $(seq 1 120); do [ "$(rec_last Q_STAGE)" = "done" ] && ! pgrep -f "$QP" >/dev/null && break; sleep 0.5; done
OWNLOG="$(qlast)"
check "$(rec_last Q_STAGE)$(grep -c '^  FAIL  ' <<<"$OWNLOG")$(has "$OWNLOG" 'stray-store quarantine (quarantine): PASSED')$(has "$OWNLOG" 'From now on')" "done0yesyes" \
  "3a a Ctrl-C at the moment the old name went: the run goes on to the end — done, PASSED — and its own log holds every line"
check "$(grep -c '^  ok    C' <<<"$OWNLOG")$(has "$OWNLOG" 'the old name is gone at')" "8yes" "…its own log holds the move and C1–C8"
check "$(okline "$OWNLOG" C1)$(okline "$OWNLOG" C2)$(okline "$OWNLOG" C3)$(okline "$OWNLOG" C4)$(okline "$OWNLOG" C5)$(okline "$OWNLOG" C6)$(okline "$OWNLOG" C7)$(okline "$OWNLOG" C8)" "yesyesyesyesyesyesyesyes" "…C1–C8 each ok"
check "$( [ -e "$SRC" ] && echo src || echo nosrc)$(stat -c '%d:%i %h %u:%g %a' "$KEPT")$(stat -c %w "$KEPT")$(sha256sum "$KEPT" | cut -c1-16):$(stat -c '%s:%Y' "$KEPT")" "nosrc$KEY0 1 1000:1000 $(rec_last Q_MODE)$BIRTH0$STRAY_DB_SB" \
  "the old name gone; the kept file IS the stray store: the same inode, one name, owner 1000:1000, its mode, its birth, its checksum, size and time"
check "$(stray_entries_here)" "$(rec_last Q_ENTS_BEFORE | tr '/' '\n' | grep -vxF "${KEY0#*:}:plugin.sqlite3" | paste -sd/ -)" "the stray folder's entries are exactly as before, less the one"
check "$(data_digest)$(sha256sum "$VAULT" | cut -c1-16)$(stat -c %a "$QD")$(stat -c %a "$QREC")" "$DQ3${VAULT0}700600" "the live database, the configuration and the vault untouched by the run; the quarantine folder 700, its record 600"
for k in Q_SHA256 Q_DB Q_OWNER Q_MODE Q_KEY_BEFORE Q_BIRTH Q_DIR_T_BEFORE Q_ENTS_BEFORE Q_DATA_KEY Q_SML Q_MIGRATED Q_LIVE_COMMIT Q_LIVE_KEY Q_PDD_IN Q_GATE Q_CTR Q_AT Q_DIR_T_AFTER Q_ENTS_AFTER Q_KEPT_CTIME; do
  [ -n "$(rec_last "$k")" ] || echo "missing:$k"; done > "$SB/reckeys"; check "$(cat "$SB/reckeys")" "" "the record holds every key the next deploy script must read"
OUT="$(qrun --answer NO)"; check "$(has "$OUT" 'STOP: already quarantined at')$(has "$OUT" 'run --check at or after')$(has "$OUT" 'Until the quarantine has moved the file')" "yesyesno" \
  "the quarantine run again: already quarantined — run --check at its time; the rule before the move is not printed after it"
chmod 644 "$QP"
OUT="$(qrun --answer NO --wrap "setpriv --reuid 1000 --regid 1000 --clear-groups --")"
check "$(has "$OUT" 'STOP: run it as root')$(has "$OUT" 'Until the quarantine has moved the file')" "yesno" "3a' run without root after the move: it stops — and, unable to read the record, judges nothing: no rule before the move after it"
M="$(mutant q3-root "$QP" '  [ "$(id -u)" = "0" ] || return 0   # the record under /root cannot be read: judge nothing' '  :')"; chmod 644 "$M"
OUT="$(qrun --answer NO --script "$M" --wrap "setpriv --reuid 1000 --regid 1000 --clear-groups --")"; rm -f "$M"
check "$(has "$OUT" 'STOP: run it as root')$(has "$OUT" 'Until the quarantine has moved the file')" "yesyes" "3a'' a copy that judges without root prints the rule before the move after it (caught)"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(fails "$OUT")$(stat -c '%d:%i' "$SRC")" "0$KEY0" "…put back"; q_reset
OUT="$(qrun --answer QUARANTINE --at 'the old name is gone at' --intr HUP)"
for i in $(seq 1 120); do [ "$(rec_last Q_STAGE)" = "done" ] && ! pgrep -f "$QP" >/dev/null && break; sleep 0.5; done
OWNLOG="$(qlast)"
check "$(rec_last Q_STAGE)$(grep -c '^  FAIL  ' <<<"$OWNLOG")$(has "$OWNLOG" 'stray-store quarantine (quarantine): PASSED')" "done0yes" "3b a hang-up (the terminal closed, as an SSH session that drops) at the same moment: the run still ends done and PASSED, in its own log"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(fails "$OUT")" "0" "…put back"; q_reset
# A weakened copy that does not ignore the terminal's signals: the Ctrl-C ends it in the middle (caught).
M="$(mutant q3-sig "$QP" '  trap '"''"' INT TERM HUP PIPE
  # Everything again' '  : INT TERM HUP PIPE
  # Everything again')"
OUT="$(qrun --answer QUARANTINE --script "$M" --at 'the old name is gone at' --intr INT)"; sleep 3
check "$(rec_last Q_STAGE)" "moved" "3c a copy that does not ignore the terminal's signals is ended by the Ctrl-C between the move and its checks (caught): the record says moved"
OUT="$(qrun --answer NO)"; check "$(has "$OUT" 'this run does them, and changes nothing')$(rec_last Q_STAGE)" "yesdone" "…and the quarantine command finishes it"; rm -f "$M"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(fails "$OUT")" "0" "…put back"; q_reset
# A holder opens the store after stage B's last read, just before the unlink: stage C FAILS C6, records it, never PASSES.
OUT="$(QPATH="$SB/shim:$SB/bin:$PATH" qrun --answer QUARANTINE --env "SHIM_RM_HOLD=$SRC")"; OWNLOG="$(qlast)"
check "$(failline "$OWNLOG" C6 'is held — locks and open descriptors')$(okline "$OWNLOG" C5)$(rec_last Q_STAGE)$(rec_last Q_CHECK_FAILED | grep -c .)$(has "$OWNLOG" '(quarantine): PASSED')$(has "$OWNLOG" '(quarantine): 1 FAILED')$(has "$OWNLOG" 'Do not run the put-back: a check above FAILED')$(has "$OWNLOG" '--put-back 2>&1 | tee')$(has "$OWNLOG" 'hand-over')" "yesyesdone1noyesyesnono" \
  "3e a holder opens the store between stage B's last read and the unlink: stage C FAILS C6 and records it, never PASSED; no put-back command and no --check time: one next step"
OUT="$(qrun --answer NO)"; check "$(has "$OUT" 'but its own checks FAILED at')$(has "$OUT" 'and wait for a decision')$(has "$OUT" 'run --check now')" "yesyesno" "…the quarantine run again says its own checks FAILED, and to wait for a decision"
shim_stop; OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(fails "$OUT")" "0" "…put back"; q_reset
M="$(mutant q3-c6 "$QP" 'if held_by_none; then ok "C6' 'if true; then ok "C6')"
OUT="$(QPATH="$SB/shim:$SB/bin:$PATH" qrun --answer QUARANTINE --script "$M" --env "SHIM_RM_HOLD=$SRC")"; OWNLOG="$(qlast)"; rm -f "$M"
check "$(failline "$OWNLOG" C6)$(has "$OWNLOG" '(quarantine): PASSED')" "noyes" "3e' a copy blind to C6 passes the same holder (caught)"
shim_stop; OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(fails "$OUT")" "0" "…put back"; q_reset
# The container recycling straight after the move: the commit and the gate are read through it; an unread one is not
# measured — never "changed", never a permanent failure.
rm -f "$SB/recycle-until"; OUT="$(qrun --answer QUARANTINE --env FAKE_RECYCLE_ON_MOVE=40)"; OWNLOG="$(qlast)"
check "$(okline "$OWNLOG" C7)$(okline "$OWNLOG" C8)$(fails "$OWNLOG")$(has "$OWNLOG" '(quarantine): PASSED')$(rec_last Q_STAGE)$(rec_last Q_CHECK_FAILED | grep -c .)" "yesyes0yesdone0" \
  "3f the container answers a runtime's error text on standard output for 40 s after the move: C7 waits it out and reads the commit; PASSED, nothing recorded as failed"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(fails "$OUT")" "0" "…put back"; q_reset
rm -f "$SB/recycle-until"; OUT="$(qrun --answer QUARANTINE --env FAKE_RECYCLE_ON_MOVE=40 --env FAKE_RECYCLE_STDERR=1)"; OWNLOG="$(qlast)"
check "$(okline "$OWNLOG" C7)$(okline "$OWNLOG" C8)$(fails "$OWNLOG")$(has "$OWNLOG" '(quarantine): PASSED')$(rec_last Q_CHECK_FAILED | grep -c .)" "yesyes0yes0" \
  "3f' …the daemon's refusal on standard error instead: the same"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(fails "$OUT")" "0" "…put back"; q_reset
rm -f "$SB/recycle-until"; OUT="$(qrun --answer QUARANTINE --env FAKE_RECYCLE_ON_MOVE=150)"; OWNLOG="$(qlast)"
check "$(has "$OWNLOG" 'note  C7 the live database')$(has "$OWNLOG" "note  C8 the gate's question could not be asked")$(fails "$OWNLOG")$(has "$OWNLOG" '(quarantine): NOT CONCLUSIVE — the live commit was not read after the move')$(rec_last Q_STAGE)$(rec_last Q_CHECK_FAILED | grep -c .)" "yesyes0yesdone0" \
  "3f'' …for longer than C7 waits (150 s): C7 and C8 not measured, NOT CONCLUSIVE, nothing recorded as failed"
OUT="$(qrun -- --check)"
check "$(failline "$OUT" K0)$(fails "$OUT")$(okline "$OUT" K11)$(has "$OUT" '(check): NOT CONCLUSIVE')" "no0yesyes" "…the next --check, the container back: no K0, the gate read, not yet conclusive (no run of the job since the move)"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(fails "$OUT")" "0" "…put back"; q_reset
M="$(mutant q3-hexonly "$QP" "| tail -n1 | tr -d '[:space:]')\"" "| tail -n1 | tr -cd '0-9a-f')\"")"
rm -f "$SB/recycle-until"; OUT="$(qrun --answer QUARANTINE --script "$M" --env FAKE_RECYCLE_ON_MOVE=40)"; OWNLOG="$(qlast)"; rm -f "$M"
check "$(failline "$OWNLOG" C7)$(rec_last Q_CHECK_FAILED | grep -c .)" "yes1" "3f''' control: a copy that keeps the hex letters of the runtime's text reads them as a commit and FAILS C7 for good (caught)"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(fails "$OUT")" "0" "…put back"; q_reset; rm -f "$SB/recycle-until"
# The configuration turned to South Sudan between stage B and stage C: a parsed reading that opens the gate FAILS C8.
cfg_keep; vault_keep
cat > "$SB/ss_all.sh" <<'SH'
P() { setpriv --reuid 1000 --regid 1000 --clear-groups -- @PHPBIN@ "$@"; }
P -r '$f = $argv[1]; $c = json_decode((string)file_get_contents($f), true); $c["tenant_profile"] = "south-sudan"; $c["currency_code"] = "SSP"; file_put_contents($f, json_encode($c));' '@DATA@/kyc_config.json'
printf '%s' '{"config":{"currency_code":"SSP","tenant_profile":"south-sudan"}}' > '@VAULT@'
P -r '$p = new PDO("sqlite:" . $argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $r = json_decode((string)$p->query("SELECT data FROM kyc_config WHERE id = 0")->fetchColumn(), true); $r["tenant_profile"] = "south-sudan"; $r["currency_code"] = "SSP";
  $p->prepare("UPDATE kyc_config SET data = ? WHERE id = 0")->execute([json_encode($r)]);' '@DATA@/plugin.sqlite3'
SH
sed -i -e "s#@PHPBIN@#$PHPBIN#g" -e "s#@DATA@#$DATA#g" -e "s#@VAULT@#$VAULT#g" "$SB/ss_all.sh"
OUT="$(QPATH="$SB/shim:$SB/bin:$PATH" qrun --answer QUARANTINE --env "SHIM_RM_AFTER=$SRC" --env "SHIM_AFTER_CMD=$SB/ss_all.sh")"; OWNLOG="$(qlast)"
check "$(failline "$OWNLOG" C8 'skip=no' 'Do NOT put the kept file back')$(rec_last Q_STAGE)$(rec_last Q_CHECK_FAILED | grep -c .)$(has "$OWNLOG" 'Do not run the put-back: a check above FAILED')$(has "$OWNLOG" '--put-back 2>&1 | tee')" "yesdone1yesno" \
  "3g the configuration turned to South Sudan between stage B and stage C: C8 FAILS on the parsed reading (skip=no) and records it; no put-back command printed"
OUT="$(qrun --answer PUTBACK -- --put-back)"
check "$(has "$OUT" "NO-GO: the INSTALLED retry job's gate no longer reads as it must")$(has "$OUT" 'Type PUTBACK')$( [ -e "$SRC" ] && echo src || echo nosrc)$( [ "$(stat -c '%d:%i' "$KEPT")" = "$KEY0" ] && echo kept)" "yesnonosrckept" \
  "…--put-back refuses before the word while 5.18.93 is live with the gate open: the job would open the file"
M="$(mutant q3-pbgate "$QP" "    stop \"NO-GO: the INSTALLED retry job's gate no longer reads as it must (\$g) while" "    : \"NO-GO: the INSTALLED retry job's gate no longer reads as it must (\$g) while")"
OUT="$(qrun --answer PUTBACK --script "$M" -- --put-back)"; rm -f "$M"
check "$(has "$OUT" 'Type PUTBACK')$(has "$OUT" 'nothing changed: the gate no longer reads as it must')$( [ -e "$SRC" ] && echo src || echo nosrc)" "yesyesnosrc" \
  "3g' a copy without that refusal before the word is still stopped by the read after it, before the link: two layers"
M="$(mutant q3-pbgate2 "$QP" "    stop \"NO-GO: the INSTALLED retry job's gate no longer reads as it must (\$g) while" "    : \"NO-GO: the INSTALLED retry job's gate no longer reads as it must (\$g) while" \
  '      if ! { { gate_installed && gate_ok "$g2"; } || ungated_version "$v2"; }; then' '      if false; then')"
OUT="$(qrun --answer PUTBACK --script "$M" -- --put-back)"; rm -f "$M"
check "$(okline "$OUT" PB1)$(stat -c '%d:%i' "$SRC" 2>/dev/null)" "yes$KEY0" "3g'' a copy without either puts the file back under an open gate (caught)"
cfg_back; vault_back; q_reset
# The gate's probe refused once the old name is gone: not measured — NOT CONCLUSIVE in stage C and in --check; the
# put-back refuses while a release with the gate is live.
OUT="$(qrun --answer QUARANTINE --env FAKE_GATE_FAIL_ON_MOVE=1)"; OWNLOG="$(qlast)"
check "$(okline "$OWNLOG" C7)$(has "$OWNLOG" "note  C8 the gate's question could not be asked")$(fails "$OWNLOG")$(has "$OWNLOG" '(quarantine): NOT CONCLUSIVE — the gate was not measured')$(rec_last Q_CHECK_FAILED | grep -c .)$(has "$OWNLOG" '--put-back 2>&1 | tee')$(has "$OWNLOG" 'Do nothing; run --check at or after')" "yesyes0yes0yesno" \
  "3h the gate's probe refused after the move: C8 not measured, NOT CONCLUSIVE (never PASSED), nothing recorded as failed; C8's note names no time — the verdict and the hand-over do"
VT="$(sed -n 's/.*(quarantine): NOT CONCLUSIVE — the gate was not measured: run --check at or after \([0-9TZ:-]*\)\..*/\1/p' <<<"$OWNLOG")"; HT="$(sed -n 's/^  --check           at or after \([0-9TZ:-]*\):.*/\1/p' <<<"$OWNLOG")"
check "$( [ -n "$VT" ] && [ "$VT" = "$HT" ] && echo same || echo "differ:$VT/$HT")" "same" "…the verdict and the hand-over name one --check time (the move + 20 minutes)"
OUT="$(qrun --env FAKE_GATE_FAIL_ON_MOVE=1 -- --check)"
check "$(has "$OUT" "note  K11 the gate's question could not be asked")$(fails "$OUT")$(has "$OUT" '(check): NOT CONCLUSIVE — the gate was not measured')$(has "$OUT" '(check): PASSED')" "yes0yesno" "…--check with the probe refused: K11 not measured, NOT CONCLUSIVE"
OUT="$(qrun --answer PUTBACK --env FAKE_GATE_FAIL_ON_MOVE=1 -- --put-back)"
check "$(has "$OUT" "NO-GO: the gate's question could not be asked")$(has "$OUT" 'Type PUTBACK')$( [ -e "$SRC" ] && echo src || echo nosrc)" "yesnonosrc" "…--put-back with the probe refused while 5.18.93 is live: refused before the word, nothing changed"
OUT="$(qrun --answer PUTBACK --hook "touch '$SB/gate-fail'" -- --put-back)"; rm -f "$SB/gate-fail"
check "$(has "$OUT" "nothing changed: the gate's question could not be asked now")$(has "$OUT" 'the job would open it')$( [ -e "$SRC" ] && echo src || echo nosrc)$(rec_last PUTBACK_STAGE | grep -c .)" "yesnonosrc0" \
  "3h' the probe stops answering while PUTBACK waits: the read after the word says not measured — run again in ten minutes — never 'the job would open it'"
GLR='live=same inside=no store=error tenant=uganda skip=yes | by-store=none by-files+vault=uganda'
OUT="$(qrun --answer PUTBACK --hook "printf '%s' '$GLR' > '$SB/gate-line'" -- --put-back)"; rm -f "$SB/gate-line"
check "$(has "$OUT" 'nothing changed: the gate still stops the retry job but was not read as required now')$(has "$OUT" 'the job would open it')$( [ -e "$SRC" ] && echo src || echo nosrc)$(rec_last PUTBACK_STAGE | grep -c .)" "yesnonosrc0" \
  "3h'' the live store not read a second time while PUTBACK waits: the read after the word says run again in ten minutes — never 'the job would open it'"
M="$(mutant q3-pbsort "$QP" '          "live=same inside=no store="*" tenant=uganda skip=yes | by-store=uganda by-files+vault=uganda"|"live=same inside=no store="*" tenant=uganda skip=yes | by-store=none by-files+vault=uganda")
            stop "nothing changed: the gate still stops the retry job but was not read as required now ($g2) — run --put-back again in ten minutes" ;;
' '')"
OUT="$(qrun --answer PUTBACK --script "$M" --hook "printf '%s' '$GLR' > '$SB/gate-line'" -- --put-back)"; rm -f "$M" "$SB/gate-line"
check "$(has "$OUT" 'the job would open it')$( [ -e "$SRC" ] && echo src || echo nosrc)" "yesnosrc" "3h3 a copy without that arm tells the operator the job would open the file over a reading in which the gate still stops it (caught)"
git -C "$REPO" show "$OLDER2:$P/$JOB" > "$SB/job590.php"; cp -p "$PD/cron_starlink_block_retry.php" "$SB/job93.kept"
job_back() { cat "$SB/job93.kept" > "$PD/cron_starlink_block_retry.php"; touch -r "$SB/job93.kept" "$PD/cron_starlink_block_retry.php"; }
OUT="$(qrun --answer PUTBACK --hook "cat '$SB/job590.php' > '$PD/cron_starlink_block_retry.php'" -- --put-back)"; job_back
check "$(has "$OUT" 'nothing changed: the INSTALLED retry job has no gate while the manifest says 5.18.93')$( [ -e "$SRC" ] && echo src || echo nosrc)" "yesnosrc" \
  "3h4 5.18.90's job put in while PUTBACK waits, the manifest still 5.18.93's: the read after the word refuses — the installed code is not one release"
M="$(mutant q3-pbjob "$QP" '        gate_installed || stop "nothing changed: the INSTALLED retry job has no gate while the manifest says' '        : || stop "nothing changed: the INSTALLED retry job has no gate while the manifest says')"
OUT="$(qrun --answer PUTBACK --script "$M" --hook "cat '$SB/job590.php' > '$PD/cron_starlink_block_retry.php'" -- --put-back)"; rm -f "$M"; job_back
check "$(has "$OUT" 'not one release')$(has "$OUT" 'run --put-back again in ten minutes')$( [ -e "$SRC" ] && echo src || echo nosrc)" "noyesnosrc" "3h4' a copy without that refusal tells the operator to run again in ten minutes over code that is not one release (caught)"
check "$(sha256sum < "$PD/cron_starlink_block_retry.php")" "$(sha256sum < "$SB/job93.kept")" "…5.18.93's job back, byte for byte"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(fails "$OUT")" "0" "…put back"; q_reset
M="$(mutant q3-whenmode "$QP" '[ "$MODE" != "check" ] || [ "$FAIL" != "0" ] || when=' '[ "$FAIL" != "0" ] || when=')"
OUT="$(qrun --answer QUARANTINE --script "$M" --env FAKE_GATE_FAIL_ON_MOVE=1)"; OWNLOG="$(qlast)"; rm -f "$M"
check "$(has "$OWNLOG" "note  C8 the gate's question could not be asked")$(has "$OWNLOG" 'Do nothing; run --check at or after')" "yesyes" \
  "3h5 a copy whose stage-C gate note names the --check time too puts a second instruction in the log — beside C10's, when the record cannot be written (caught)"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(fails "$OUT")" "0" "…put back"; q_reset
# A job migrating the LIVE store appends to <plugin>/data/migration.log while a holder count is read — as
# cron/dpo_reconcile.php does every five minutes: no reading of the stray store or of the kept file may count that as a holder.
ML_Y="$(stat -c %y "$PD/data/migration.log")"; cp -p "$PD/data/migration.log" "$SB/ml.kept"; ML0="$(stat -c %s "$PD/data/migration.log")"
DWU="$(declare -f dpo_writer | sed 's#"\$SB/dpo-emu.sqlite"#"$SB/u/dpo-emu.sqlite"#g; 1s/^dpo_writer /dpo_writer_owner /')"   # its throwaway ledger where the server's owner can write
check "$(grep -c 'SB/u/dpo-emu.sqlite' <<<"$DWU")$(head -1 <<<"$DWU")" "2dpo_writer_owner () " "control: the real writer's own text, only its throwaway ledger moved to a folder the server's owner (who runs it) can write"
eval "$DWU"; DW="$(dpo_writer_owner)"
check "$DW$( [ "$(stat -c %s "$PD/data/migration.log")" -gt "$ML0" ] && echo grew)$(stat -c %u:%g "$PD/data/migration.log")" "donegrew1000:1000" \
  "3i control: the real writer — MigrationRunner with its default log, as cron/dpo_reconcile.php runs it — appends to <plugin>/data/migration.log"
mkdir -p "$SB/logshim"; cat > "$SB/logshim/xargs" <<SH
#!/usr/bin/env bash
# rehearsal shim: one line appended to <plugin>/data/migration.log inside every descriptor scan — between a holder count's
# two reads — then the real xargs
printf '%s WARNING rehearsal: a line as cron/dpo_reconcile.php appends it\n' "\$(date -u +%Y-%m-%dT%H:%M:%SZ)" >> "$PD/data/migration.log"
real="\$(PATH="\$(printf '%s' "\$PATH" | sed 's#^$SB/logshim:##')" command -v xargs)"; exec "\$real" "\$@"
SH
chmod +x "$SB/logshim/xargs"; LS_PATH="$SB/logshim:$SB/bin:$PATH"
M="$(mutant q3-log "$QP" "else stray_state | sed 's/ log=[^ ]*//'; fi; }" "else stray_state; fi; }")"; OUT="$(QPATH="$LS_PATH" qrun --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" 'NO-GO: the stray store is not shown unheld')$(has "$OUT" ' log=')$(went_go "$OUT")" "yesyesnonono" "3i' a copy that keeps migration.log in the steadiness test refuses at A5 over a holder that does not exist (caught)"
ML1="$(wc -l < "$PD/data/migration.log")"
OUT="$(QPATH="$LS_PATH" qrun --answer QUARANTINE)"; OWNLOG="$(qlast)"
check "$(okline "$OWNLOG" A5)$(okline "$OWNLOG" C6)$(fails "$OWNLOG")$(has "$OWNLOG" '(quarantine): PASSED')$(rec_last Q_STAGE)$( [ "$(wc -l < "$PD/data/migration.log")" -gt $(( ML1 + 2 )) ] && echo appended)" "yesyes0yesdoneappended" \
  "3i the quarantine with a line appended to migration.log inside every holder read: A5 and C6 ok, PASSED"
OUT="$(QPATH="$LS_PATH" qrun -- --check)"
check "$(okline "$OUT" K10)$(fails "$OUT")" "yes0" "…and --check the same: K10 ok, nothing FAILED"
cp -p "$QREC" "$SB/qrecml.kept"
M="$(mutant q3-log2 "$QP" 'hold_state() { if [ -n "$HOLD_WATCH" ]; then' 'hold_state() { if false; then' "else stray_state | sed 's/ log=[^ ]*//'; fi; }" "else stray_state; fi; }")"
OUT="$(QPATH="$LS_PATH" qrun --script "$M" -- --check)"; rm -f "$M"
check "$(failline "$OUT" K10 'not shown unheld')$(rec_last Q_CHECK_FAILED | grep -c .)" "yes1" "3i'' a copy that judges the kept file by the old folder's state, migration.log included, FAILS K10 and records it for good (caught)"
cp -p "$SB/qrecml.kept" "$QREC"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(fails "$OUT")" "0" "…put back"
cat "$SB/ml.kept" > "$PD/data/migration.log"; touch -d "$ML_Y" "$PD/data/migration.log"; q_reset
# Another writer making or removing an entry in <plugin>/data between the two reads of a holder count: the folder's time
# moves. Before the move that refuses (A5, B — the stray store's own folder); after it, C6 and K10 judge the kept file by
# its own state. (Alternating fixed past times: two changes inside one second would hide each other.)
mkdir -p "$SB/dirshim"; cat > "$SB/dirshim/xargs" <<SH
#!/usr/bin/env bash
# rehearsal shim: once the stray store's old name is gone, <plugin>/data's time set to one of two fixed past seconds, in
# turn, on every descriptor scan; then the real xargs
if [ ! -e "$PD/data/plugin.sqlite3" ] && [ ! -L "$PD/data/plugin.sqlite3" ]; then
  n=\$(( \$(cat "$SB/dirshim.n" 2>/dev/null || echo 0) + 1 )); echo "\$n" > "$SB/dirshim.n"
  touch -d "@\$(( $REF_DIR_T - 100 - n % 2 ))" "$PD/data"
fi
real="\$(PATH="\$(printf '%s' "\$PATH" | sed 's#^$SB/dirshim:##')" command -v xargs)"; exec "\$real" "\$@"
SH
chmod +x "$SB/dirshim/xargs"; DS_PATH="$SB/dirshim:$SB/bin:$PATH"; rm -f "$SB/dirshim.n"
OUT="$(QPATH="$DS_PATH" qrun --answer QUARANTINE)"; OWNLOG="$(qlast)"
check "$(okline "$OWNLOG" C6)$(fails "$OWNLOG")$(has "$OWNLOG" '(quarantine): PASSED')$(rec_last Q_CHECK_FAILED | grep -c .)$( [ "$(cat "$SB/dirshim.n" 2>/dev/null || echo 0)" -ge 2 ] && echo moved)" "yes0yes0moved" \
  "3j <plugin>/data's time moved between the two reads of every holder count after the move: C6 judges the kept file by its own state — ok, PASSED"
OUT="$(QPATH="$DS_PATH" qrun -- --check)"; check "$(okline "$OUT" K10)$(fails "$OUT")" "yes0" "…and K10 the same"
cp -p "$QREC" "$SB/qrecds.kept"
M="$(mutant q3-k10dir "$QP" 'holders -f "$KEPT" "$rk"
    if held_by_none; then ok "K10' 'holders "$rk"
    if held_by_none; then ok "K10')"; OUT="$(QPATH="$DS_PATH" qrun --script "$M" -- --check)"; rm -f "$M"
check "$(failline "$OUT" K10 'not shown unheld')$(rec_last Q_CHECK_FAILED | grep -c .)" "yes1" "3j' a copy whose K10 judges the kept file by the old folder's state FAILS it and records it for good (caught)"
cp -p "$SB/qrecds.kept" "$QREC"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(fails "$OUT")" "0" "…put back"; q_reset
M="$(mutant q3-c6dir "$QP" 'holders -f "$KEPT" "$rk"
    if held_by_none; then ok "C6' 'holders "$rk"
    if held_by_none; then ok "C6')"; OUT="$(QPATH="$DS_PATH" qrun --answer QUARANTINE --script "$M")"; OWNLOG="$(qlast)"; rm -f "$M"
check "$(failline "$OWNLOG" C6 'not shown unheld')$(rec_last Q_CHECK_FAILED | grep -c .)" "yes1" "3j'' a copy whose C6 does the same FAILS C6 and records it for good (caught)"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(fails "$OUT")" "0" "…put back"; q_reset; rm -f "$SB/dirshim.n"
# The quarantine, traced: no php or python process opens either name; nothing made, linked or removed in <plugin>/data but the one name.
OUT="$(qrun --answer QUARANTINE --wrap "strace -f -qq -o $SB/strace.out -e trace=execve,open,openat,creat,link,linkat,unlink,unlinkat,rename,renameat,renameat2,mkdir,mkdirat")"
cat > "$SB/trace.py" <<'PY'
import re, sys
trace, src, kept, data = sys.argv[1:5]
exe = {}; bad = []
names = {src, kept} | {src + s for s in ('-wal', '-shm', '-journal')} | {kept + s for s in ('-wal', '-shm', '-journal')}
for line in open(trace, errors='replace'):
    m = re.match(r'(\d+) +(\w+)\((.*)', line)
    if not m: continue
    pid, call, rest = m.groups()
    paths = re.findall(r'"([^"]*)"', rest)
    if call == 'execve' and paths: exe[pid] = paths[0].rsplit('/', 1)[-1]; continue
    who = exe.get(pid, '?')
    if call in ('open', 'openat', 'creat'):
        if any(p in names for p in paths) and (who.startswith('php') or who.startswith('python')): bad.append('sqlite-capable %s opened %s' % (who, [p for p in paths if p in names]))
        if 'O_CREAT' in rest and any(p.startswith(data + '/') for p in paths): bad.append('created under data/: %s by %s' % (paths, who))
    if call in ('unlink', 'unlinkat') and any(p.startswith(data + '/') and p != src for p in paths) and '= 0' in rest: bad.append('removed under data/: %s' % paths)
    if call in ('link', 'linkat', 'rename', 'renameat', 'renameat2') and any(p.startswith(data + '/') for p in paths[1:]) and '= 0' in rest: bad.append('named under data/: %s' % paths)
print('\n'.join(bad) if bad else 'clean')
PY
check "$(rec_last Q_STAGE)$(python3 "$SB/trace.py" "$SB/strace.out" "$SRC" "$KEPT" "$PD/data")" "doneclean" "3d the quarantine under strace: no php or python process opened either name or a side name, and nothing was made, named or removed in <plugin>/data but the one name"
check "$(grep -cE '^[0-9]+ +(unlink|unlinkat)\(.*"'"$SRC"'".*= 0' "$SB/strace.out")$(grep -cE '^[0-9]+ +(link|linkat)\(.*"'"$SRC"'".*"'"$KEPT"'".*= 0' "$SB/strace.out")" "11" "…control: the trace holds the one link and the one unlink"
CT="$(mktemp -d)"; cp "$KEPT" "$CT/stray-plugin.sqlite3.kept"
strace -f -qq -o "$SB/strace2.out" -e trace=execve,open,openat "$PHPBIN" -r 'new PDO("sqlite:" . $argv[1]);' "$CT/stray-plugin.sqlite3.kept" 2>/dev/null
check "$(python3 "$SB/trace.py" "$SB/strace2.out" "$CT/plugin.sqlite3" "$CT/stray-plugin.sqlite3.kept" "$CT/none" | grep -c 'sqlite-capable php')" "1" "…control on the control: a php that only constructs a PDO on a copy of the kept file is flagged"
rm -rf "${CT:?}" "$SB/strace.out" "$SB/strace2.out"
QAT="$(rec_last Q_AT)"

echo; echo "== Q4. --check: conclusive only after a completed run of the retry job past the move =="
OUT="$(qrun -- --check)"
check "$(fails "$OUT")$(has "$OUT" 'note  K13 master has not begun the retry job more than two minutes after the move yet')$(okline "$OUT" K4)$(okline "$OUT" K8)$(okline "$OUT" K10)$(okline "$OUT" K11)$(has "$OUT" '(check): NOT CONCLUSIVE — run --check again after')$(has "$OUT" '(check): PASSED')" "0yesyesyesyesyesyesno" \
  "4a straight after: K4–K11 ok, not yet conclusive (master's last run of the job is from before the move): NOT CONCLUSIVE, never PASSED"
M="$(mutant q4-verdict "$QP" '  elif [ -n "$UNSETTLED" ]; then say' '  elif false; then say')"; OUT="$(qrun --script "$M" -- --check)"; rm -f "$M"
check "$(has "$OUT" '(check): PASSED')" "yes" "4a' a copy whose verdict keys on failures alone calls the same check PASSED (caught)"
qat_back() { sed -i "s/^Q_AT=.*/Q_AT=$1/" "$QREC"; }   # the move N seconds ago, as a later --check finds it (the record's line, as copy_back moves the deploy's)
NOW="$(date -u +%s)"; qat_back $(( NOW - 1200 )); QAT=$(( NOW - 1200 ))
set_sched $(( QAT + 120 )); OUT="$(qrun -- --check)"
check "$(notes "$OUT")$(has "$OUT" 'note  K13 master has not begun the retry job more than two minutes after the move, in the 20 minutes since it')$(has "$OUT" '(check): NOT CONCLUSIVE — master has not begun the retry job more than two minutes after the move, 20 minutes ago')" "1yesyes" \
  "4b a run that began 120 s after the move, read 20 minutes after it: not conclusive (the margin is strict) — and, with no run since in twice the job's interval, said so instead of a time already past"
M="$(mutant q4-past "$QP" 'elif [ "$now" -lt $(( q_at + 1200 )) ]; then note "K13 master has not begun' 'elif true; then note "K13 master has not begun')"; OUT="$(qrun --script "$M" -- --check)"; rm -f "$M"
check "$(has "$OUT" 'minutes since it (its last')" "no" "4b2 a copy that always names the move + 20 minutes sends the operator to a time already past (caught)"
set_sched $(( QAT + 121 )); OUT="$(qrun -- --check)"; check "$(fails "$OUT")$(okline "$OUT" K13 'the quarantine holds')" "0yes" "4c 121 s after: conclusive — the quarantine holds"
set_sched $(( QAT + 600 )) -1; OUT="$(qrun -- --check)"; check "$(has "$OUT" 'not a completed run')$(okline "$OUT" K13)" "yesno" "4d a run still running: not conclusive"
set_sched $(( NOW + 300 )); OUT="$(qrun -- --check)"; check "$(has "$OUT" 'is later than now: not evidence')$(okline "$OUT" K13)" "yesno" "4e master's record later than now: not evidence"
M="$(mutant q4-margin "$QP" '[ "$rt" -gt $(( q_at + 120 )) ]' '[ "$rt" -ge $(( q_at + 120 )) ]')"; set_sched $(( QAT + 120 )); OUT="$(qrun --script "$M" -- --check)"; rm -f "$M"
check "$(okline "$OUT" K13)" "yes" "4b' a copy with the margin not strict calls 120 s conclusive (caught)"
M="$(mutant q4-future "$QP" 'elif [ "$rt" -gt "$now" ]; then' 'elif false; then')"; set_sched $(( NOW + 300 )); OUT="$(qrun --script "$M" -- --check)"; rm -f "$M"
check "$(okline "$OUT" K13)" "yes" "4e' a copy that believes a record later than now calls it conclusive (caught)"
# The INSTALLED retry job, as master runs it, against the real plugin folder — then master's record of that run.
master_job() {   # master_job JOB-FILE — the job included in master's shared scope, as the server's owner, the live store open
  php -r 'foreach (["StoreInterface", "JsonStore", "SqliteStore", "bootstrap_data"] as $l) require_once $argv[1] . "/lib/$l.php";
    $pluginRoot = $argv[1]; $dataDir = getDataDir($pluginRoot); $_m_store = SqliteStore::create($dataDir); $_m_config = $_m_store->load("kyc_config.json") ?? [];
    ob_start(); include $argv[2]; ob_end_clean(); echo "ran"; ' "$PD" "$1" 2>/dev/null
}
DS0="$(stray_snap)"
sml_keep() { cp -p "$PD/data/sqlite_migration.log" "$SB/sml.kept"; SML_Y="$(stat -c %y "$PD/data/sqlite_migration.log")"; }
sml_back() { cat "$SB/sml.kept" > "$PD/data/sqlite_migration.log"; touch -d "$SML_Y" "$PD/data/sqlite_migration.log"; }   # in place: the same inode, its bytes and time
SML0="$( [ -f "$PD/data/sqlite_migration.log" ] && echo present || echo absent)"
check "$(master_job "$PD/cron_starlink_block_retry.php")$( [ "$(stray_snap)" = "$DS0" ] && echo same)$(ls "$PD/data" | grep -cE '^plugin\.sqlite3|\.json\.migrated$')" "ransame0" \
  "4f the INSTALLED retry job, run as master runs it against the real plugin folder: it makes nothing there — no database, side file, sqlite_migration.log or *.json.migrated"
set_sched $(( $(date -u +%s) - 1 )); OUT="$(qrun -- --check)"; check "$(fails "$OUT")$(okline "$OUT" K13 'the quarantine holds')" "0yes" "…and --check after that run: conclusive"
cp -p "$QREC" "$SB/qrec.kept"
fail_trial() {   # fail_trial LABEL ID EXPECTED-FRAGMENT — --check FAILS that check and records it; the next --check fails K0 too; the record put back
  local o o2; o="$(qrun -- --check)"; o2="$(qrun -- --check)"
  check "$(failline "$o" "$2" "$3")$(rec_last Q_CHECK_FAILED | grep -c .)$(failline "$o2" K0)" "yes1yes" "$1"
  cp -p "$SB/qrec.kept" "$QREC"
}
setpriv --reuid 1000 --regid 1000 --clear-groups -- touch "$SRC-wal"; fail_trial "4g a side file at the old name: K4 FAILS, recorded; the next --check FAILS K0" K4 'side files -wal'; rm -f "$SRC-wal"
sml_keep; setpriv --reuid 1000 --regid 1000 --clear-groups -- touch -d '+1 minute' "$PD/data/sqlite_migration.log"; fail_trial "4h the stray folder's sqlite_migration.log written again (a new store's first start rewrites it): K5 FAILS" K5 'sqlite_migration.log changed'; sml_back
setpriv --reuid 1000 --regid 1000 --clear-groups -- touch "$PD/data/wallet.json.migrated"; fail_trial "4i a *.json.migrated appears: K6 FAILS" K6 'wallet.json.migrated'; rm -f "$PD/data/wallet.json.migrated"
setpriv --reuid 1000 --regid 1000 --clear-groups -- touch "$PD/data/fiber_events.log"; OUT="$(qrun -- --check)"
check "$(fails "$OUT")$(has "$OUT" 'note  K7 other entries made or removed in <plugin>/data since the move')$(has "$OUT" 'new:fiber_events.log')$(okline "$OUT" K13)" "0yesyesyes" "4j another writer's file appears there: a note naming it — still conclusive"; rm -f "$PD/data/fiber_events.log"
KM="$(stat -c %y "$KEPT")"; touch -d "@$(( $(stat -c %Y "$KEPT") + 1 ))" "$KEPT"; fail_trial "4k the kept file's time moved: K8 FAILS" K8 'missing or changed'; touch -d "$KM" "$KEPT"
touch -d "@$(( $(stat -c %Y "$KEPT") + 1 ))" "$KEPT"; OUT="$(qrun --env FAKE_GATE_FAIL_ON_MOVE=1 -- --check)"; cp -p "$SB/qrec.kept" "$QREC"
check "$(failline "$OUT" K8)$(has "$OUT" "note  K11 the gate's question could not be asked")$(has "$OUT" 'run --check at or after')$(has "$OUT" '(check): 1 FAILED')" "yesyesnoyes" \
  "4k2 a FAIL and an unmeasured gate in one log: the gate's note names no --check time — after a FAIL, one next step"
M="$(mutant q4-when "$QP" '[ "$FAIL" != "0" ] || when=' 'when=')"; OUT="$(qrun --script "$M" --env FAKE_GATE_FAIL_ON_MOVE=1 -- --check)"; rm -f "$M"; cp -p "$SB/qrec.kept" "$QREC"; touch -d "$KM" "$KEPT"
check "$(failline "$OUT" K8)$(has "$OUT" 'run --check at or after')" "yesyes" "4k2' a copy that names the time whatever FAIL is prints it beside the FAIL (caught)"
holder_py fd "$KEPT"; fail_trial "4l a process holds the kept file: K10 FAILS, naming both reads' counts" K10 'held — locks and open descriptors: 0 1 → 0 1 (two reads)'; holder_stop
M="$(mutant q4-holdboth "$QP" '"$HOLD_BOTH" "$( [ "$STEADY"' '"$HOLD" "$( [ "$STEADY"')"; holder_py fd "$KEPT"; OUT="$(qrun --script "$M" -- --check)"; holder_stop; rm -f "$M"; cp -p "$SB/qrec.kept" "$QREC"
check "$(failline "$OUT" K10 'held')$(has "$OUT" '0 1 → 0 1')" "yesno" "4l'' a copy that names only the second read's counts leaves a lasting FAIL resting on words that may not show its cause (caught)"
ns_holder "$KEPT"; fail_trial "4l' a namespaced process holds it through a bind mount: K10 FAILS" K10 'held'; ns_stop
setpriv --reuid 1000 --regid 1000 --clear-groups -- true; touch "$KEPT-shm"; fail_trial "4m a side file beside the kept file: K9 FAILS" K9 'side files beside the kept file (-shm)'; rm -f "$KEPT-shm"
touch "$KEPT-shm"; OUT="$(qrun --wrap "$(nospace)" -- --check)"; rm -f "$KEPT-shm"; cp -p "$SB/qrec.kept" "$QREC"
check "$(failline "$OUT" K9)$(has "$OUT" 'note  K14 the failure could not be recorded')$(has "$OUT" 'the failure is recorded in')$(has "$OUT" '(check): 1 FAILED')$(rec_last Q_CHECK_FAILED | grep -c .)" "yesyesnoyes0" \
  "4m2 the same on a disk that refuses the record's one line: K14 says the failure could not be recorded — never silent"
M="$(mutant q4-k14 "$QP" '    else note "K14' '    else : "K14')"; touch "$KEPT-shm"; OUT="$(qrun --script "$M" --wrap "$(nospace)" -- --check)"; rm -f "$KEPT-shm" "$M"; cp -p "$SB/qrec.kept" "$QREC"
check "$(failline "$OUT" K9)$(has "$OUT" 'K14')$(rec_last Q_CHECK_FAILED | grep -c .)" "yesno0" "4m2' a copy silent about it leaves a failure no later --check can see, and says nothing (caught)"
cfg_keep; vault_keep; file_set tenant_profile south-sudan; file_set currency_code SSP; printf '{"config":{"currency_code":"SSP","tenant_profile":"south-sudan"}}' > "$VAULT"
OUT="$(qrun -- --check)"; check "$(failline "$OUT" K11 'Do NOT put the kept file back')" "yes" "4n the gate no longer reads as it must: K11 FAILS, saying when the job runs next and not to put the file back"; cp -p "$SB/qrec.kept" "$QREC"; cfg_back; vault_back
for t in "K4|absent \"\$SRC\" && [ \"\$(side_of \"\$SRC\")\" = \"-\" ]; then ok \"K4|true; then ok \"K4|$SRC-wal|side" \
         "K5|if [ \"\$(sml_state)\" = \"\$(rec_get Q_SML)\" ]; then ok \"K5|if true; then ok \"K5|$PD/data/sqlite_migration.log|sml" \
         "K6|if [ \"\$(migrated_of)\" = \"\$(rec_get Q_MIGRATED)\" ]; then ok \"K6|if true; then ok \"K6|$PD/data/w.json.migrated|mig"; do
  IFS='|' read -r id old new plant nm <<< "$t"
  M="$(mutant "q4-$nm" "$QP" "$old" "$new")"; [ "$nm" = sml ] && sml_keep; setpriv --reuid 1000 --regid 1000 --clear-groups -- touch -d '+1 minute' "$plant"; OUT="$(qrun --script "$M" -- --check)"; rm -f "$M"
  if [ "$nm" = sml ]; then sml_back; else rm -f "$plant"; fi
  check "$(failline "$OUT" "$id")$(fails "$OUT")$(okline "$OUT" K13 'the quarantine holds')$(has "$OUT" 'stray-store quarantine (check): PASSED')" "no0yesyes" \
    "4o a copy blind to $id passes the planted file: nothing else FAILs it, and it calls the quarantine held (caught)"; cp -p "$SB/qrec.kept" "$QREC"
done
M="$(mutant q4-held "$QP" '    if held_by_none; then ok "K10' '    if true; then ok "K10')"; holder_py fd "$KEPT"; OUT="$(qrun --script "$M" -- --check)"; holder_stop; rm -f "$M"
check "$(failline "$OUT" K10)$(fails "$OUT")$(okline "$OUT" K13 'the quarantine holds')$(has "$OUT" 'stray-store quarantine (check): PASSED')" "no0yesyes" \
  "4o' a copy blind to K10's holders passes a held kept file: nothing else FAILs it, and it calls the quarantine held (caught)"; cp -p "$SB/qrec.kept" "$QREC"
# The record itself: no move time, a stage the script never writes, none at all, or one the files contradict.
sed -i '/^Q_AT=/d' "$QREC"; OUT="$(qrun -- --check)"
check "$(okline "$OUT" K13)$(has "$OUT" 'the record holds no usable move time')$(has "$OUT" '(check): NOT CONCLUSIVE')" "noyesyes" "4r a record with no move time: K13 not conclusive, never 'the quarantine holds'"
M="$(mutant q4-qat "$QP" "  elif case \"\$q_at\" in ''|*[!0-9]*) true ;; *) false ;; esac; then note \"K13" "  elif false; then note \"K13")"; OUT="$(qrun --script "$M" -- --check)"; rm -f "$M"
check "$(okline "$OUT" K13 'the quarantine holds')" "yes" "4r' a copy that reads no move time as zero calls the quarantine held (caught)"; cp -p "$SB/qrec.kept" "$QREC"
printf 'Q_STAGE=bogus\n' >> "$QREC"; OUT="$(qrun -- --check)"
check "$(failline "$OUT" K1 'not one this script writes (bogus)')$(rec_last Q_CHECK_FAILED | grep -c .)" "yes0" "4s a record whose stage the script never writes: K1 FAILS (not recorded: it is the record that is wrong)"; cp -p "$SB/qrec.kept" "$QREC"
sed -i '/^Q_STAGE=/d' "$QREC"; OUT="$(qrun -- --check)"; check "$(failline "$OUT" K1 'not one this script writes (none)')" "yes" "…a record with no stage: K1 FAILS"; cp -p "$SB/qrec.kept" "$QREC"
printf 'Q_STAGE=linked\n' >> "$QREC"; setpriv --reuid 1000 --regid 1000 --clear-groups -- sh -c "printf x > '$SRC'"; OUT="$(qrun -- --check)"; rm -f "$SRC"
check "$(failline "$OUT" K1 'match no state a run leaves')" "yes" "…a record that says linked over a new file at the old name: K1 FAILS"; cp -p "$SB/qrec.kept" "$QREC"
GL_ERR='live=same inside=no store=error tenant=uganda skip=yes | by-store=none by-files+vault=uganda'
GL_SS='live=same inside=no store=error tenant=uganda skip=yes | by-store=south-sudan by-files+vault=uganda'
OUT="$(qrun --env "FAKE_GATE_LINE=$GL_ERR" -- --check)"
check "$(has "$OUT" 'note  K11 the gate still stops the retry job, but it was not read as required')$(fails "$OUT")$(has "$OUT" '(check): NOT CONCLUSIVE — the gate was not read as required')$(rec_last Q_CHECK_FAILED | grep -c .)$(okline "$OUT" K13)$(okline "$OUT" K13 'the quarantine holds')" "yes0yes0yesno" \
  "4t the gate stops the job on the files and the vault, but the live store could not be read a second time: not measured — NOT CONCLUSIVE, nothing recorded as failed; K13 says the job ran, never 'the quarantine holds'"
M="$(mutant q4-holds "$QP" "\$( [ -z \"\$UNSETTLED\" ] && printf ': the quarantine holds')" ": the quarantine holds")"; OUT="$(qrun --script "$M" --env "FAKE_GATE_LINE=$GL_ERR" -- --check)"; rm -f "$M"
check "$(okline "$OUT" K13 'the quarantine holds')$(has "$OUT" '(check): NOT CONCLUSIVE')" "yesyes" "4t' a copy whose K13 always concludes says 'the quarantine holds' in a NOT CONCLUSIVE log (caught)"
OUT="$(qrun --env "FAKE_GATE_LINE=$GL_SS" -- --check)"
check "$(failline "$OUT" K11 'by-store=south-sudan')$(rec_last Q_CHECK_FAILED | grep -c .)" "yes1" "4u control: the same reading with the store naming another tenant FAILS K11 and is recorded"; cp -p "$SB/qrec.kept" "$QREC"
M="$(mutant q4-anyskip "$QP" '"live=same inside=no store="*" tenant=uganda skip=yes | by-store=uganda by-files+vault=uganda"|"live=same inside=no store="*" tenant=uganda skip=yes | by-store=none by-files+vault=uganda")
       note' '*" skip=yes | "*)
       note')"; OUT="$(qrun --script "$M" --env "FAKE_GATE_LINE=$GL_SS" -- --check)"; rm -f "$M"
check "$(failline "$OUT" K11)$(has "$OUT" 'not read as required')" "noyes" "4v a copy that takes any skip=yes reading as not measured lets 4u's control through (caught)"; cp -p "$SB/qrec.kept" "$QREC"
OUT="$(qrun --answer PUTBACK --env "FAKE_GATE_LINE=$GL_ERR" -- --put-back)"
check "$(has "$OUT" 'NO-GO: the gate still stops the retry job but was not read as required')$(has "$OUT" 'Type PUTBACK')$( [ -e "$SRC" ] && echo src || echo nosrc)" "yesnonosrc" "4w the put-back refuses such a reading while 5.18.93 is live, before the word"
# <plugin>/data replaced (an upload of the plugin, a restore): K3 has no conclusion, and K5/K6 compare nothing with the old folder's files.
mv "$PD/data" "$SB/data.real"; mkdir "$PD/data"; cp -p "$SB/data.real/config.json" "$PD/data/"; chown -R 1000:1000 "$PD/data"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'note  K3')$(has "$OUT" 'note  K5/K6 not compared')$(fails "$OUT")$(has "$OUT" '(check): NOT CONCLUSIVE')$(rec_last Q_CHECK_FAILED | grep -c .)" "yesyes0yes0" \
  "4x <plugin>/data replaced by a new folder (no sqlite_migration.log in it): K3 no conclusion, K5/K6 not compared — NOT CONCLUSIVE, nothing recorded as failed"
M="$(mutant q4-k3 "$QP" 'if [ -n "$indet" ]; then   # not the folder of the move' 'if false; then   # not the folder of the move')"; OUT="$(qrun --script "$M" -- --check)"; rm -f "$M"
check "$(failline "$OUT" K5)$(rec_last Q_CHECK_FAILED | grep -c .)" "yes1" "4x' a copy that compares the new folder's files with the old one's FAILS K5 on a log that is merely absent, and records it for good (caught)"; cp -p "$SB/qrec.kept" "$QREC"
rm -rf "${PD:?}/data"; cp -a "$SB/data.real" "$PD/data"   # a copy of the folder: the same files and times, another folder
OUT="$(qrun -- --check)"; check "$(has "$OUT" 'note  K3')$(fails "$OUT")$(has "$OUT" '(check): NOT CONCLUSIVE')" "yes0yes" "4x2 …replaced by a copy of itself (the same files and times): still no conclusion"
M="$(mutant q4-k3b "$QP" 'elif [ "$(data_key)" != "$(rec_get Q_DATA_KEY)" ]; then' 'elif false; then')"; OUT="$(qrun --script "$M" -- --check)"; rm -f "$M"
check "$(okline "$OUT" K13 'the quarantine holds')$(has "$OUT" '(check): PASSED')" "yesyes" "4x2' a copy blind to K3 takes the copy for the folder of the move and calls the quarantine held (caught)"
rm -rf "${PD:?}/data"; mv "$SB/data.real" "$PD/data"; cp -p "$SB/qrec.kept" "$QREC"
OUT="$(qrun -- --check)"; check "$(okline "$OUT" K3)$(fails "$OUT")" "yes0" "…the folder of the move back: K3 ok again"
# The gate-down control: 5.18.90's retry job, which has no gate, run as master runs it against the real plugin folder.
git -C "$REPO" show "$OLDER2:$P/$JOB" > "$PD/cron_starlink_block_retry_590.php"; chown 1000:1000 "$PD/cron_starlink_block_retry_590.php"
sml_keep; SML_T0="$(sha256sum "$PD/data/sqlite_migration.log" | cut -c1-16):$(stat -c %Y "$PD/data/sqlite_migration.log")"
check "$(master_job "$PD/cron_starlink_block_retry_590.php")$( [ -e "$SRC" ] && echo store)$( [ "$(sha256sum "$PD/data/sqlite_migration.log" | cut -c1-16):$(stat -c %Y "$PD/data/sqlite_migration.log")" != "$SML_T0" ] && echo smlnew)" "ranstoresmlnew" \
  "4p control: 5.18.90's retry job (no gate), run the same way, makes a new database in <plugin>/data, with its first-start log — what the gate prevents"
OUT="$(qrun -- --check)"
check "$(failline "$OUT" K4)$(failline "$OUT" K5)$(rec_last Q_CHECK_FAILED | grep -c .)" "yesyes1" "…--check FAILS it (K4 a database at the old name, K5 the first-start log) and records the failure"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(has "$OUT" 'NO-GO: there is a file at')$(has "$OUT" 'it is never overwritten')$( [ -e "$KEPT" ] && echo kept)" "yesyeskept" "…--put-back refuses over the new store: never overwritten; the kept file stays"
rm -f "$PD/cron_starlink_block_retry_590.php" "$SRC" "$SRC-wal" "$SRC-shm"; sml_back
OUT="$(qrun -- --check)"; check "$(okline "$OUT" K4)$(okline "$OUT" K5)$(failline "$OUT" K0)" "yesyesyes" "…the new store removed, the log written back: K4 and K5 read ok again, and K0 still FAILS on the recorded failure until the record is put back"
cp -p "$SB/qrec.kept" "$QREC"
# What the quarantine is for, on throwaway copies of the layout: getDataDir's rescue copy and cliDataDir's guard.
purpose() {   # purpose WITH|WITHOUT — a throwaway copy of the plugins root, its live store gone (rescue) or its root not writable (guard)
  local t; t="$(mktemp -d)"; chmod 711 "$t"; cp -a "$PLUGINS" "$t/plugins"
  if [ "$1" = with ] && [ ! -e "$t/plugins/$P/data/plugin.sqlite3" ]; then cp -p "$KEPT" "$t/plugins/$P/data/plugin.sqlite3"; fi
  if [ "$1" = without ]; then rm -f "$t/plugins/$P/data/plugin.sqlite3"; fi
  rm -rf "$t/plugins/.$P-data"
  local r; r="$(cd /tmp && php -r 'require $argv[1] . "/lib/bootstrap_data.php"; $d = getDataDir($argv[1]); echo is_file($d . "/plugin.sqlite3") ? "copied" : "none";' "$t/plugins/$P" 2>/dev/null)"
  chmod 555 "$t/plugins"; rm -rf "$t/plugins/.$P-data" 2>/dev/null
  local g; g="$(cd /tmp && php -r 'require $argv[1] . "/lib/bootstrap_data.php"; echo "dir=", cliDataDir($argv[1]);' "$t/plugins/$P" >/dev/null 2>&1; echo "rc=$?")"
  chmod 755 "$t/plugins"; rm -rf "${t:?}"; echo "$r $g"
}
check "$(purpose with)|$(purpose without)" "copied rc=0|none rc=2" \
  "4q what it is for: with the file there, getDataDir() copies it into a missing live folder and cliDataDir() hands a tool <plugin>/data; without it, nothing is copied and cliDataDir() refuses (exit 2)"

echo; echo "== Q5. after the move: every other mode of the deploy scripts fails R19 and records it; the rollback passes =="
cp -p "$STATE" "$SB/state93.kept"
base_log; OUT="$(run --after-only)"
check "$(failline "$OUT" R19 'CHANGED')$(grep -c '^STRAY_R19_FAILED=' "$STATE")" "yes1" "5a deploy-5.18.93.sh --after-only after the move: R19 FAILS on the absent file, and STRAY_R19_FAILED is written for good"
OUT="$(qrun -- --check)"; check "$(has "$OUT" 'note  K12 dnb-5.18.93/state-5.18.93.env records an R19 failure')$(fails "$OUT")" "yes0" "…--check names it: the known consequence of a forbidden run, not an opener"
cp -p "$SB/state93.kept" "$STATE"; base_log; OUT="$(run --answer NONE)"
check "$(failline "$OUT" R19 'CHANGED')$(grep -c '^STRAY_R19_FAILED=' "$STATE")" "yes1" "5b the same script with no option (5.18.93 live): it runs as --after-only, R19 FAILS and is written"
cp -p "$SB/state93.kept" "$STATE"
for v in 5.18.92:$BASE 5.18.91:$OLDER; do
  ver="${v%%:*}"; pin="${v#*:}"; mkdir -p "$SB/rec$ver"
  { printf 'DEPLOYED_AT=2026-10-09T00:00:00Z\nSTRAY_BEFORE=%s\nSTRAY_CTRL=seen\nSTRAY_ENTS=%s\nSTRAY_COPY=%s\nSTRAY_COPY_LOCKS=0\nSTRAY_COPY_AT=%s\n' \
      "$(sed -n 's/^STRAY_COPY=//p' "$SB/state93.kept")" "$(sed -n 's/^STRAY_ENTS=//p' "$SB/state93.kept")" "$(sed -n 's/^STRAY_COPY=//p' "$SB/state93.kept")" "$COPY_T"; } > "$SB/rec$ver/state-$ver.env"
  printf '%s\n' "$pin" > "$PD/.deployed-commit"; base_log
  OUT="$(env PATH="$SB/bin:$PATH" DNB_OUT="$SB/rec$ver" DNB_PREV_STATE="$SB/none.env" GUARD_SECONDS=0 bash "$REPO/scripts/deploy-$ver.sh" --plugin-base "$PLUGIN_BASE" --after-only 2>&1)"
  check "$(grep -cE '^  FAIL  R19 ' <<<"$OUT" | sed 's/^[1-9][0-9]*$/fail/')$(grep -c '^STRAY_R19_FAILED=' "$SB/rec$ver/state-$ver.env")" "fail1" "5c deploy-$ver.sh --after-only with its own release live, after the move: R19 FAILS and is written for good — the runbook's first rule, measured"
done
printf '%s\n' "$PIN" > "$PD/.deployed-commit"; rm -rf "$SB/rec5.18.92" "$SB/rec5.18.91"
base_log; OUT="$(run --answer ROLLBACK --rollback)"
check "$(fails "$OUT")$(has "$OUT" '(rollback): PASSED')$(live)$( [ -e "$SRC" ] && echo src || echo nosrc)" "0yes${BASE}nosrc" "5d deploy-5.18.93.sh --rollback after the move PASSES (RB): 5.18.92 live, the stray store still absent"
check "$(master_job "$PD/cron_starlink_block_retry.php")$(ls "$PD/data" | grep -cE '^plugin\.sqlite3|\.json\.migrated$')" "ran0" "…5.18.92's retry job (5.18.91's gate) run as master runs it: nothing made"
set_sched $(( $(date -u +%s) - 1 )); OUT="$(qrun -- --check)"
check "$(has "$OUT" "note  K2 the container serves $BASE")$(fails "$OUT")$(okline "$OUT" K13 'the quarantine holds')" "yes0yes" "…--check with 5.18.92 live: a note, and conclusive"

echo; echo "== Q6. the put-back =="
cp -p "$QREC" "$SB/qrec.kept"
setpriv --reuid 1000 --regid 1000 --clear-groups -- sh -c "printf x > '$SRC'"
OUT="$(qrun --answer PUTBACK -- --put-back)"; check "$(has "$OUT" 'NO-GO: there is a file at')$(cat "$SRC")$( [ -e "$KEPT" ] && echo kept)" "yesxkept" "6a a file at the old name: refused, never overwritten"; rm -f "$SRC"
KM="$(stat -c %y "$KEPT")"; touch -d "@$(( $(stat -c %Y "$KEPT") + 1 ))" "$KEPT"; OUT="$(qrun --answer PUTBACK -- --put-back)"
check "$(has "$OUT" 'NO-GO: the kept file is not the stray store as recorded')$( [ -e "$SRC" ] && echo src || echo nosrc)" "yesnosrc" "6b the kept file changed: refused"; touch -d "$KM" "$KEPT"
holder_py fd "$KEPT"; OUT="$(qrun --answer PUTBACK -- --put-back)"; holder_stop
check "$(has "$OUT" 'NO-GO: the stray store, read now, is held — locks and open descriptors: 0 1 → 0 1 (two reads)')$( [ -e "$SRC" ] && echo src || echo nosrc)" "yesnosrc" "6c a holder of the kept file: refused, naming both reads' counts"
OUT="$(qrun --answer putback -- --put-back)"; check "$(has "$OUT" 'STOP: not confirmed')$( [ -e "$SRC" ] && echo src || echo nosrc)" "yesnosrc" "6d a word other than PUTBACK: nothing changed"
M="$(mutant q6-src "$QP" 'else stop "NO-GO: there is a file at $SRC' 'else case_="back"; : "NO-GO: there is a file at $SRC')"
setpriv --reuid 1000 --regid 1000 --clear-groups -- sh -c "printf x > '$SRC'"; OUT="$(qrun --answer PUTBACK --script "$M" -- --put-back)"; rm -f "$M"
check "$(has "$OUT" 'NO-GO: there is a file at')$(cat "$SRC")" "nox" "6e a copy without the refusal over a file at the old name gets past it (caught; its own link then fails, and the file is not overwritten)"; rm -f "$SRC"; cp -p "$SB/qrec.kept" "$QREC"
# The gate opens while PUTBACK waits (5.18.92 live): the put-back reads it again after the word and refuses.
cfg_keep; vault_keep
OUT="$(qrun --answer PUTBACK --hook "bash '$SB/ss_all.sh'" -- --put-back)"
check "$(has "$OUT" 'nothing changed: the gate no longer reads as it must')$( [ -e "$SRC" ] && echo src || echo nosrc)$( [ "$(stat -c '%d:%i' "$KEPT")" = "$KEY0" ] && echo kept)$(rec_last PUTBACK_STAGE | grep -c .)$(rec_last PUTBACK_AT | grep -c .)" "yesnosrckept00" \
  "6j the configuration turned to South Sudan while PUTBACK waits: the put-back reads the gate again and refuses — nothing changed, nothing recorded"
cfg_back; vault_back   # Uganda again before the weakened copy's run: its hook turns it while its own prompt waits
M="$(mutant q6-pbregate "$QP" '      if ! { { gate_installed && gate_ok "$g2"; } || ungated_version "$v2"; }; then' '      if false; then' '      ln -T -- "$KEPT" "$SRC" 2>/dev/null || {' '      false || {')"
OUT="$(qrun --answer PUTBACK --hook "bash '$SB/ss_all.sh'" --script "$M" -- --put-back)"; rm -f "$M"
check "$(has "$OUT" 'nothing changed: the gate no longer reads as it must')$(has "$OUT" 'nothing changed: the link could not be made')" "noyes" "6j2 a copy that does not read the gate again goes on to the link under an open gate (caught; its link made safe here)"
cfg_back; vault_back; cp -p "$SB/qrec.kept" "$QREC"
# 5.18.91 live (after the rollbacks the runbook allows), the gate opened: both of the put-back's refusals rest on 5.18.91
# being read as a release with the gate.
cp -p "$PD/manifest.json" "$SB/manifest6k.kept"; sed -i 's/"version": "5.18.92"/"version": "5.18.91"/' "$PD/manifest.json"; cfg_keep; vault_keep; bash "$SB/ss_all.sh"
OUT="$(qrun --answer PUTBACK -- --put-back)"
check "$(has "$OUT" "NO-GO: the INSTALLED retry job's gate no longer reads as it must")$(has "$OUT" 'while 5.18.91 is live')$(has "$OUT" 'Type PUTBACK')$( [ -e "$SRC" ] && echo src || echo nosrc)" "yesyesnonosrc" \
  "6k 5.18.91 live with the gate opened: the put-back refuses before the word"
M="$(mutant q6-591 "$QP" '[ "$1" != "5.18.91" ] && ' '' '      ln -T -- "$KEPT" "$SRC" 2>/dev/null || {' '      false || {')"
OUT="$(qrun --answer PUTBACK --script "$M" -- --put-back)"; rm -f "$M"
check "$(has "$OUT" '5.18.91 has no gate')$(has "$OUT" 'nothing changed: the link could not be made')" "yesyes" \
  "6k' a copy that reads 5.18.91 as a release without the gate passes both of the put-back's gate refusals, to the link (caught; its link made safe here)"
cp -p "$SB/manifest6k.kept" "$PD/manifest.json"; cfg_back; vault_back; cp -p "$SB/qrec.kept" "$QREC"
# A rollback to 5.18.90 without the put-back first: its retry job has no gate, while the gate's library stays on disk
# (deploy-hybrid.sh deletes nothing) and still answers the probe as before.
cp -p "$PD/cron_starlink_block_retry.php" "$SB/job92.kept"; cp -p "$PD/manifest.json" "$SB/manifest92.kept"
git -C "$REPO" show "$OLDER2:$P/$JOB" > "$PD/cron_starlink_block_retry.php"; chown 1000:1000 "$PD/cron_starlink_block_retry.php"
OUT="$(qrun --answer PUTBACK -- --put-back)"
check "$(has "$OUT" 'NO-GO: the INSTALLED retry job has no gate')$(has "$OUT" 'the installed code is not one release')$( [ -e "$SRC" ] && echo src || echo nosrc)" "yesyesnosrc" "6h 5.18.90's job under 5.18.92's manifest: the put-back refuses — the installed code is not one release"
set_sched $(( $(date -u +%s) - 1 )); OUT="$(qrun -- --check)"; cp -p "$SB/qrec.kept" "$QREC"
check "$(failline "$OUT" K11 'has no gate' 'Send this log now and wait for a decision')$(has "$OUT" 'Run --put-back now')$(has "$OUT" '(check): 1 FAILED — send the log file')$(has "$OUT" 'Do nothing else first.')" "yesnoyesyes" \
  "6h2 --check in that state: K11 FAILS — send the log and wait — and names no put-back, which would refuse here (the installed code is not one release)"
sed -i 's/"version": "5.18.92"/"version": "5.18.90"/' "$PD/manifest.json"
set_sched $(( $(date -u +%s) - 1 ))
OUT="$(qrun -- --check)"
check "$(failline "$OUT" K11 'has no gate' 'Run --put-back now')$(has "$OUT" '(check): PASSED')$(rec_last Q_CHECK_FAILED | grep -c .)$(has "$OUT" '(check): 1 FAILED — run --put-back now (runbook rule 2)')$(has "$OUT" 'Do nothing else first')" "yesno1yesno" \
  "6i after a rollback to 5.18.90 without the put-back: --check FAILS K11 — the installed job has no gate, though its library would still answer — and names the put-back, in K11 and in the verdict alike: one next step; never PASSED"
cp -p "$SB/qrec.kept" "$QREC"
M="$(mutant q6-failstep "$QP" '${FAIL_STEP:-send the log file' '${FAIL_STEP_IGNORED:-send the log file')"; OUT="$(qrun --script "$M" -- --check)"; rm -f "$M"
check "$(has "$OUT" 'Run --put-back now')$(has "$OUT" 'Do nothing else first')" "yesyes" "6i' a copy whose verdict ignores K11's step tells the operator both to put back now and to do nothing else first (caught)"; cp -p "$SB/qrec.kept" "$QREC"
# 6m–6n: the quarantine command after that rollback — on the finished record, and on one a run left at moved — and
# --check on the latter: the put-back, now (rule 2), before anything else.
OUT="$(qrun --answer NO)"
check "$(has "$OUT" 'STOP: the file is moved, and 5.18.90 is live with a retry job that has no gate: run --put-back now (runbook rule 2)')$(has "$OUT" 'run --check at or after')$(has "$OUT" 'already quarantined')" "yesnono" \
  "6m the quarantine command run again (a lost terminal) after a rollback to 5.18.90 without the put-back: it names the put-back, now — not a --check time"
printf 'Q_CHECK_FAILED=%s\n' "$(date -u +%s)" >> "$QREC"; OUT="$(qrun --answer NO)"; cp -p "$SB/qrec.kept" "$QREC"
check "$(has "$OUT" 'STOP: the file is moved, and 5.18.90 is live')$(has "$OUT" 'but its own checks FAILED')" "yesno" "…and the same over a recorded failure: rule 2 first"
M="$(mutant q6-donerule2 "$QP" '      if ! gate_installed && ungated_version "$LIVE_VERSION" && kept_restorable; then
        stop "the file is moved' '      if false; then
        stop "the file is moved')"; OUT="$(qrun --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" 'STOP: the file is moved')$(has "$OUT" 'STOP: already quarantined at')" "noyes" "6m' a copy without that stop names a --check time while the ungated job's next run nears (caught)"
printf 'Q_STAGE=moved\n' >> "$QREC"; OUT="$(qrun --answer NO)"
check "$(has "$OUT" 'STOP: the file is moved, and 5.18.90 is live')$(has "$OUT" 'this run does them')$(rec_last Q_STAGE)" "yesnomoved" \
  "6n a record a run left at moved, 5.18.90 live: the quarantine command names the put-back, now — it does not resume into stage C"
O2="$(qrun -- --check)"
check "$(failline "$O2" K1 'Run --put-back now (runbook rule 2)')$(has "$O2" '(check): 1 FAILED — run --put-back now (runbook rule 2)')$(has "$O2" 'run the quarantine command again')" "yesyesno" "…and --check FAILS K1, naming the put-back in K1 and in the verdict"
M="$(mutant q6-resrule2 "$QP" '        if ! gate_installed && ungated_version "$LIVE_VERSION" && kept_restorable; then
          stop "the file is moved' '        if false; then
          stop "the file is moved')"; OUT="$(qrun --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" 'this run does them')$(has "$OUT" 'Do not run the put-back')" "yesyes" "6n' a copy without the resumed run's stop goes into stage C and ends 'Do not run the put-back' (caught)"
cp -p "$SB/qrec.kept" "$QREC"; printf 'Q_STAGE=moved\n' >> "$QREC"
M="$(mutant q6-k1rule2 "$QP" '        if ! gate_installed && ungated_version "$LIVE_VERSION" && kept_restorable; then
          bad "K1 the quarantine moved' '        if false; then
          bad "K1 the quarantine moved')"; O2="$(qrun --script "$M" -- --check)"; rm -f "$M"
check "$(has "$O2" 'run the quarantine command again')$(has "$O2" 'Run --put-back now')" "yesno" "6n'' a copy without K1's arm sends the operator to the quarantine command instead (caught)"
touch "$KEPT-shm"; O2="$(qrun -- --check)"; OUT="$(qrun --answer NO)"; rm -f "$KEPT-shm"; cp -p "$SB/qrec.kept" "$QREC"
check "$(has "$O2" 'Run --put-back now')$(has "$OUT" 'run --put-back now')" "nono" "…with a side file beside the kept file neither names the put-back, which would refuse over it"
# 6o: a holder of the kept file during --check: K10 FAILS, and K11 still names the put-back — which reads the holders
# itself: refused before its word while one holds the file (and done once it is gone: 6f).
holder_py fd "$KEPT"; OUT="$(qrun -- --check)"; cp -p "$SB/qrec.kept" "$QREC"; O2="$(qrun --answer PUTBACK -- --put-back)"; holder_stop
check "$(failline "$OUT" K10 'held — locks and open descriptors: 0 1 → 0 1 (two reads)')$(failline "$OUT" K11 'Run --put-back now')$(has "$OUT" '(check): 2 FAILED — run --put-back now (runbook rule 2)')" "yesyesyes" \
  "6o a process holds the kept file during --check, 5.18.90 live: K10 FAILS, and the one next step is still the put-back — the job's next run is coming"
check "$(has "$O2" 'NO-GO: the stray store, read now, is held')$(has "$O2" 'Nothing was changed')$( [ -e "$SRC" ] && echo src || echo nosrc)$(rec_last PUTBACK_STAGE | grep -c .)" "yesyesnosrc0" "…and the put-back, while it holds, refuses before its word: nothing changed"
M="$(mutant q6-holdany "$QP" 'ungated_version "$LIVE_VERSION" && kept_restorable; then
      bad "$1 the INSTALLED' 'ungated_version "$LIVE_VERSION" && kept_restorable && [ "${HOLD_ANY:-no}" != "yes" ]; then
      bad "$1 the INSTALLED')"
holder_py fd "$KEPT"; OUT="$(qrun --script "$M" -- --check)"; holder_stop; rm -f "$M"; cp -p "$SB/qrec.kept" "$QREC"
check "$(has "$OUT" 'Run --put-back now')$(has "$OUT" 'Do nothing else first')" "noyes" "6o' a copy that withholds the put-back over a holder sends the operator to wait while the ungated job's next run nears (caught)"
# 6p: a second name for the kept file: K8 FAILS; the put-back is not named, and refuses before its word.
ln "$KEPT" "$QD/kept.second"; OUT="$(qrun -- --check)"; cp -p "$SB/qrec.kept" "$QREC"
check "$(failline "$OUT" K8 'names 2')$(failline "$OUT" K11 'Send this log now and wait for a decision')$(has "$OUT" 'Run --put-back now')" "yesyesno" \
  "6p the kept file with a second name, 5.18.90 live: K8 FAILS, and the put-back — which refuses while the file has another name — is not named"
OUT="$(qrun --answer PUTBACK -- --put-back)"
check "$(has "$OUT" 'NO-GO: the kept file has 2 names')$(has "$OUT" 'Type PUTBACK')$(rec_last PUTBACK_STAGE | grep -c .)$( [ -e "$SRC" ] && echo src || echo nosrc)$(stat -c %h "$KEPT")" "yesno0nosrc2" \
  "…and the put-back refuses before its word: nothing linked, nothing recorded"
M="$(mutant q6-links "$QP" ' && [ ! -L "$KEPT" ] && [ "$(links_of "$KEPT")" = "1" ] \' ' && [ ! -L "$KEPT" ] \')"; OUT="$(qrun --script "$M" -- --check)"; rm -f "$M"; cp -p "$SB/qrec.kept" "$QREC"
check "$(has "$OUT" 'Run --put-back now')" "yes" "6p' a copy that leaves the names out of that test names a put-back that would refuse (caught)"
M="$(mutant q6-pblinks "$QP" '    [ "$case_" != "back" ] || [ "$(links_of "$KEPT")" = "1" ] \' '    true \')"; OUT="$(qrun --answer PUTBACK --script "$M" -- --put-back)"; rm -f "$M"
check "$(has "$OUT" 'Type PUTBACK')$( [ -e "$SRC" ] && echo src || echo nosrc)$(stat -c %h "$KEPT")" "yessrc3" "6p'' a copy without the put-back's own refusal links the old name as a third and stops half-done (caught)"
rm -f "$SRC"; rm -f "$QD/kept.second"; cp -p "$SB/qrec.kept" "$QREC"
# 6q: the kept file's mode changed: K8 FAILS; the put-back, which refuses over it, is not named.
KMODE="$(stat -c %a "$KEPT")"; KMODE2="$( [ "$KMODE" = 640 ] && echo 600 || echo 640)"
chmod "$KMODE2" "$KEPT"; OUT="$(qrun -- --check)"; chmod "$KMODE" "$KEPT"; cp -p "$SB/qrec.kept" "$QREC"
check "$(failline "$OUT" K8 "mode $KMODE2")$(has "$OUT" 'Run --put-back now')" "yesno" "6q the kept file's mode changed, 5.18.90 live: K8 FAILS, and the put-back — which refuses over it — is not named"
M="$(mutant q6-mode "$QP" ' && [ "$(mode_of "$KEPT")" = "$(rec_get Q_MODE)" ] && [ "$(side_of "$KEPT")" = "-" ] \' ' && [ "$(side_of "$KEPT")" = "-" ] \')"
chmod "$KMODE2" "$KEPT"; OUT="$(qrun --script "$M" -- --check)"; chmod "$KMODE" "$KEPT"; rm -f "$M"; cp -p "$SB/qrec.kept" "$QREC"
check "$(has "$OUT" 'Run --put-back now')" "yes" "6q' a copy that leaves the mode out of that test names a put-back that would refuse (caught)"
touch "$KEPT-shm"; OUT="$(qrun -- --check)"; rm -f "$KEPT-shm"; cp -p "$SB/qrec.kept" "$QREC"
check "$(failline "$OUT" K9)$(failline "$OUT" K11 'Send this log now and wait for a decision')$(has "$OUT" 'Run --put-back now')$(has "$OUT" 'Do nothing else first')" "yesyesnoyes" \
  "6l 5.18.90 live and a side file beside the kept file: K9 and K11 FAIL, and the put-back — which refuses over that side file — is not named: send the log and wait"
M="$(mutant q6-restorable "$QP" ' && [ "$(side_of "$KEPT")" = "-" ] \' ' \')"; touch "$KEPT-shm"; OUT="$(qrun --script "$M" -- --check)"; rm -f "$KEPT-shm" "$M"; cp -p "$SB/qrec.kept" "$QREC"
check "$(has "$OUT" 'Run --put-back now')" "yes" "6l' a copy that leaves the side file out of that test names a put-back that would refuse (caught)"
sml_keep; master_job "$PD/cron_starlink_block_retry.php" >/dev/null; OUT="$(qrun -- --check)"
check "$( [ -e "$SRC" ] && echo store)$(failline "$OUT" K4)$(has "$OUT" 'Run --put-back now')" "storeyesno" "6l2 …and once 5.18.90's job has made a store at the old name: K4 FAILS, and the put-back (which never overwrites it) is not named"
rm -f "$SRC" "$SRC-wal" "$SRC-shm"; sml_back; cp -p "$SB/qrec.kept" "$QREC"
M="$(mutant q6-gatejob "$QP" '  if ! gate_installed; then' '  if false; then')"; OUT="$(qrun --script "$M" -- --check)"; rm -f "$M"
check "$(okline "$OUT" K11 'skip=yes')$(has "$OUT" '(check): PASSED')" "yesyes" "6i2 a copy that asks the library alone calls the gate holding and the quarantine PASSED (caught)"; cp -p "$SB/qrec.kept" "$QREC"
OUT="$(qrun --answer PUTBACK -- --put-back)"
check "$(has "$OUT" '5.18.90 has no gate (its retry job calls none): the retry job WILL open it again')" "yes" "6f with 5.18.90 live the put-back goes ahead (runbook rule 2), saying the job will open the file again"
check "$(fails "$OUT")$(okline "$OUT" PB1 'the stray store again')$(stat -c '%d:%i %h %u:%g' "$SRC")$(stat -c %w "$SRC")$(sha256sum "$SRC" | cut -c1-16):$(stat -c '%s:%Y' "$SRC")$( [ -e "$KEPT" ] && echo kept || echo nokept)" \
  "0yes$KEY0 1 1000:1000$BIRTH0${STRAY_DB_SB}nokept" "6f the put-back: the same file at the old name — inode, one name, owner, birth, checksum, size and time — and nothing kept"
cp -p "$SB/job92.kept" "$PD/cron_starlink_block_retry.php"; cp -p "$SB/manifest92.kept" "$PD/manifest.json"   # 5.18.92 as the rollback left it
check "$(rec_last PUTBACK_DIR_T)|$(rec_last PUTBACK_ENTS)|$(rec_last PUTBACK_STRAY | grep -c .)" "$(stat -c %Y "$PD/data")|$(stray_entries_here)|1" \
  "…the record holds the folder's new reference: its time (the put-back's own link), its entries, the store as files"
OUT="$(qrun -- --check)"; check "$(okline "$OUT" K1 'put back')$(fails "$OUT")" "yes0" "…--check reports the put-back"
setpriv --reuid 1000 --regid 1000 --clear-groups -- touch "$SRC-wal"; OUT="$(qrun -- --check)"; rm -f "$SRC-wal"
check "$(okline "$OUT" K1 'put back')$(has "$OUT" 'note  K1 side files beside the restored store (-wal)')$(has "$OUT" 'note  K1 <plugin>/data changed since the put-back')$(fails "$OUT")" "yesyesyes0" \
  "6g after the put-back, a side file beside the restored store (as 5.18.90's job leaves one): said, not judged"
OUT="$(qrun --answer NO)"; check "$(has "$OUT" 'STOP: the stray store was put back at')" "yes" "…and the quarantine refuses to run again"

echo; echo "== Q9. what the rehearsal left behind =="
shim_stop
check "$(evo_calls)" "0" "Evolution never called in $(cat "$SB/runs") runs of the deploy script and $(cat "$SB/qruns") of the quarantine script"
check "$(checkout_state)" "$CHECKOUT0" "this checkout is as the rehearsal found it"
check "$(ls -a "$REPO/scripts" | grep -cE '^\..+\.sh$' | sed 's/^1$/pin/')" "pin" "no weakened copy is left in the clone's scripts/ (the one pinned copy aside)"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )" "" "every weakened copy, pinned copy and re-timed log found its anchor exactly once"
M="$(mutant anchorprobe "$QP" 'NO SUCH ANCHOR IN THE SCRIPT' 'x' 2>/dev/null)"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )$(test -e "$M" && echo made || echo none)" "mutant:anchorprobe none" "control on the control: a missing anchor is recorded, and no copy is made"
rm -f "$SB/anchor-missing"
check "$( [ -f "$SB/bad-runs" ] && tr '\n' ' ' < "$SB/bad-runs" )" "" "every run took the options it was given"
OUT="$(qrun -- --rehearsal-unknown-option)"; check "$(has "$OUT" 'unknown option: --rehearsal-unknown-option')$( [ -s "$SB/bad-runs" ] && echo recorded)" "yesrecorded" "control on the control: an unknown option is recorded"
rm -f "$SB/bad-runs"
check "$(left_tmp)" "$TMP0" "nothing of the deploy script's left in /tmp"
check "$(ls -A "$SB/qtmp" | wc -l | tr -d ' ')" "0" "nothing of the quarantine script's left in its TMPDIR after $(cat "$SB/qruns") runs: the holders control removes its folder"
M="$(mutant q9-tmp "$QP" '  rm -rf -- "$d"; trap - INT TERM HUP' '  : rm -rf -- "$d"; trap - INT TERM HUP')"; qrun --answer NO --script "$M" >/dev/null; rm -f "$M"
check "$(ls -A "$SB/qtmp" | wc -l | tr -d ' ')" "1" "control on the control: a copy that keeps the control's folder leaves exactly one there"; rm -rf "${SB:?}/qtmp/"*
check "$(ls /dev/shm | grep -c '^tmp\.')" "$SHM0" "nothing left in /dev/shm"
check "$(grep -c 'Fatal\|Parse error' "$SB/web.log")" "0" "the stand-in served every request without a PHP fatal"

echo; echo "rehearsal: $PASS passed, $FAILN failed ($(cat "$SB/runs") runs of the deploy script, $(cat "$SB/qruns") of the quarantine script)"
[ "$FAILN" = "0" ]
