#!/usr/bin/env bash
# Rehearse scripts/deploy-5.18.93.sh — the deploy, its checks, and the ROLLBACK — before the operator runs any of it.
#
# 5.18.93 over 5.18.92: main.php's nightly uCRM auto-pull retired (docs/07, 11 Oct). main.php, the five-minute run uCRM
# makes, carried a nightly pull of every uCRM client (ucrm_auto_pull_hour, default 03:00 — 00:00 UTC on the server) that
# never completed: it called $crmGet, which only the inline wallet sync defines, and only on a run whose interval has
# passed, so every other run of the pull hour called null, which the plugin's handler logs as "[DishNet UNCAUGHT] Value
# of type null is not callable in …/main.php:484" (5.18.92's day-later count, file:line only: 12 lines at main.php:484
# on the night of 10–11 Oct); and a run that had the wallet sync died at main.php:509 creating \DishNet\SqliteStore, a
# class that does not exist, before it wrote any file of its own. The block is now a comment saying what stood there;
# nothing else in main.php changes but the time guard's log line. No migration. Deployed from a RELEASE
# COMMIT on release/5.18.93, cut on the live 5.18.92 release commit (247a480), not from the branch tip, which also carries
# the partner portal, the PD-8 CSRF guard and the AI media layer — whose media job is in the branch's own cron/master.php.
# The base is installed AS PRODUCTION RUNS IT SINCE THE 10 OCT DEPLOY: 5.18.92, migrations 086 and 087 applied by the
# plugin's own runner, 087's three department rows; THE SALESPERSON PILOT AS THE OPERATOR LEFT IT ON 10–11 OCT (docs/66),
# each step through the installed tools/set_config.php or the installed card, in the operator's order: own leads only and
# the owned follow-ups ON in both copies; the registry refused until the sales and support numbers were verified on the
# card, then ON in both copies; sales-001 — added on 07 Oct, switched off, its assistant off — verified, its webhook
# registered, switched on, its assistant on; each card action with its trail row, Evolution's report given to the card in
# memory; the hand-over copy never set (absent: ON); the authorisation and the booking WhatsApp ON in both copies, the
# Inbox's settings row naming Evolution and the three numbers, lead capture ON and the uCRM write OFF, a live event queue
# and job photos; ucrm.json WITHOUT pluginDataDir; THE STRAY STORE inside the plugin folder, untouched since 5.18.91's
# copy; and 5.18.92's deploy record, as that deploy wrote it (its stray-store reference, its A14 "seen"). The Evolution
# the settings name is a stand-in that RECORDS every request.
# This rehearsal proves:
#   · the pin is exactly the release: cut on 5.18.92, 10 files (one added: the test), no migration — 087 the reviewed
#     one; its main.php, its test of the retired auto-pull, the tick test and the kyc test the branch's byte for byte;
#     its main.php 5.18.92's with exactly the auto-pull block replaced by its comment and the time guard's log line
#     shortened, shown independently of the derivation that wrote the script; its master.php and lock test 5.18.92's byte
#     for byte (the branch's master.php differs: the media job); Domain B untouched; the retry job 5.18.91's byte for
#     byte; the pin's two tests pass from copies shaped as A15's and A16's, the auto-pull test leaving its TMPDIR empty;
#   · stage A refuses, before anything changes: a server not on 5.18.92 (1a, 1b), an unpinned script (1c), a pin not cut on
#     5.18.92 (1d), a pin whose main.php or test of the auto-pull is not the reviewed file (1e, 1f), a pin that changes
#     master.php — outside the delta, and with that check blinded not 5.18.92's (1e', 1e'') — or the lock test (1f'), a
#     file outside the delta (1g), a pilot switch whose two copies disagree — the registry's (1h), own leads only's (1i),
#     the owned follow-ups' (1j) — 087 with one of its objects missing (1r), ucrm.json naming <plugin>/data (1k), a file
#     git does not track under the plugin folder (1l), the installed retry job without its gate and its control (1p,
#     1q), the two releases' code that cannot be copied (1w), and the layers that carry the releases: a pin whose
#     main.php is 5.18.92's — A16, with A0 blinded (1m) — a pin whose master releases the jobs' lock — A15, with A0
#     blinded (1m') — and a pin whose retry job is 5.18.90's, with no gate — A13, with A0 blinded (1n); the pilot OFF, as
#     5.18.92's deploy found it, is accepted as the pilot on is (1o); a pin whose automated-send policy acts differently
#     with the registry on is refused by A11 on this server's configuration (1s), by A6 with A11 blinded (1t), and — the
#     pilot off, where the two policies read alike on this configuration — by A6 alone (1u);
#   · weakened copies of stage A and V4 are caught (2a, 2b, 2a'', 2b'', 2b''', 2c–2g, 4e', 4h', 4i'), each layer with the
#     others blinded;
#   · the deploy then runs end to end and PASSES with two notes — R19's: no run of the retry job since the copy yet; V4's:
#     5.18.92's own fatal at main.php:484, planted just after the copy — A14 carried from 5.18.92's record, A15 and R20 the
#     lock test, A16 and R21 the test of the retired auto-pull, on the "server's" PHP, R1 the ten files (one new), the
#     stray store byte for byte and time for time as before, never opened; no data written at all;
#   · --after-only before master has run the job since the copy gives R19's note, not yet evidence (4a); once it has, it
#     PASSES with no note (4c); 5.18.92's fatal at main.php:484 or :509 within 30 minutes of the copy is a note and the run
#     still PASSES (4d, 4d''), one later fails (4e), its message at another line fails (4h), another message at :484 fails
#     (4i), 5.18.91's cron/master.php:405 is no longer old and fails (4l), any other fatal fails (4f, 4j); a job file in the
#     checkout neither commit holds changes nothing (4k, 4k'); a real open of the stray store after the copy fails R19 (4g);
#   · the checks have teeth: an installed main.php put back to 5.18.92's fails R21 and R1 (5a); an installed master.php
#     put back to 5.18.91's lock fails R20 and R6, not R1 — master.php is not this release's (5a');
#   · the rollback returns the plugin to 5.18.92: main.php byte for byte, its auto-pull back, master.php and the retry job
#     as they were, 5.18.92's own fatal a note, the stray store untouched, the file 5.18.93 added left on disk; a redeploy
#     over it passes (6b), 5.18.92's fatal at main.php:509 just after its copy a note;
#   · the pilot as the operator left it is accepted and left exactly so: each switch printed as found and unchanged by the
#     run (A5, A5b, V3f, V3h), 087's rows and trail as found and unchanged (A8, R3), the routing, the policy and the
#     prompt the two code roots' alike on this configuration (A9–A11, R2c, R12, R16, RB); nothing in the log says the
#     registry is dark or off; a switch cleared during a run fails V3f or V3h (5b, 5c), a 087 row changed or a trail row
#     added during a run fails R3 (5d, 5e) — and a copy of the script blind to each comparison passes it (5b'–5e': caught);
#   · the deploy review's additions (11 Oct): a registry switched on but not in effect is refused before anything changes,
#     at A9, at A9b and at A11, each with the others blinded (1v, 1v', 1v''); the hand-over copy's "absent means ON" both ways
#     (1x, 1y); a deploy started before the plugin's next run after a configuration change finds the vault behind it, and
#     stage A's set_distributors.php --show brings it up to date — the vault only — as the plugin's own boot does (1z;
#     rehearsal trial 4 found the vault unmodelled);
#     another fatal at main.php:509 fails, and V4 blind to its message is caught (4i'', 4n); a pilot changed between the deploy and an
#     --after-only is a note naming it (4m); R2b, R2c, R11, R12 and V3i are shown to fail with the switch, the row and the
#     trail (5b–5e); a column no count shows changed fails V3i, R3 and R11, and a key over the counts alone is caught (5f,
#     5f'); a row changed during a rollback fails RB, and RB blind to it is caught (6d, 6d');
#   · either state: the pilot OFF, as 5.18.92's deploy found it, deploys, is checked again and rolls back as cleanly
#     (6c), and the pilot is put back ON as the operator left it;
#   · nothing reaches Evolution; the clone, /tmp and the stand-in are left as found.
#
# Everything runs in a sandbox: a fake `docker` that maps /data/ucrm to a directory here (and supports exec, cp, logs), a
# clone of this checkout, the plugin installed as 5.18.92 exactly (git archive of the release commit), a stand-in web
# server for stage V's pages that boots the INSTALLED plugin's store on every request, and the recording stand-in for
# Evolution. DEPLOY and ROLLBACK are typed through a pseudo-terminal, as the operator types them. It never touches this
# checkout, the server or the network, and refuses to run where the server could be. Everything seeded is fictitious.
#
#   bash scripts/harness/deploy-5.18.93/rehearse.sh            REHEARSE_KEEP=<dir> keeps every run's full output
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

echo; echo "== 1. NO-GO before anything changes — each refusal, and nothing deployed, backed up or changed =="
nogo() {   # nogo LABEL EXPECTED-TEXT OUTPUT — the refusal said, nothing deployed, no backup, the data and the stray store as seeded
  check "$(has "$3" "$2")$(live)$(backups)$(data_digest)$(stray_snap)" "yes${BASE}0$D0$STRAY0" "$1"
}
printf '%s\n' "$OLDER" > "$PD/.deployed-commit"; fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" "STOP: NO-GO: the container serves $OLDER; 5.18.93 was built and tested against $BASE (5.18.92) — deploy 5.18.92 first (scripts/deploy-5.18.92.sh) and send its log")$(backups)$(data_digest)" "yes0$D0" \
  "1a the server still runs 5.18.91 — NO-GO, 5.18.92 goes first; no backup, no data changed"
printf '%s\n' "$BASE" > "$PD/.deployed-commit"
cp "$PD/manifest.json" "$SB/manifest.kept"; sed -i 's/"version": "5.18.92"/"version": "5.18.91"/' "$PD/manifest.json"; fresh; OUT="$(run --answer DEPLOY)"
nogo "1b the record says $BASE but the installed manifest says 5.18.91 — NO-GO on the version too" \
  "STOP: NO-GO: the container's record says $BASE, but the installed manifest says 5.18.91, not 5.18.92" "$OUT"
cp "$SB/manifest.kept" "$PD/manifest.json"
S="$(pinned_copy unpinned __PLUGIN_COMMIT__)"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
check "$(has "$OUT" "this copy of the script is not pinned to a reviewed commit")$(live)$(backups)" "yes${BASE}0" "1c a copy of the script with no pin refuses at once"
S="$(pinned_copy oldpin "$BASE")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1d a pin that is not cut on 5.18.92 — another build — refuses" "is not cut on $BASE (5.18.92): its parent is" "$OUT"
V="$(release_variant edit "$MAIN" "// AUTO-PULL UCRM CLIENTS CACHE — RETIRED (5.18.93)" "// AUTO-PULL UCRM CLIENTS CACHE — RETIRED (5.18.93, edited)")"; S="$(pinned_copy v1e "$V")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1e a pin whose main.php is not the reviewed release file — one comment line changed — refuses (A0)" "the pin's $MAIN is not the reviewed release file" "$OUT"
# master.php and the lock test are 5.18.92's, not 5.18.93's to change: a pin that changes either carries a file outside
# the delta (1e'), and with that check blinded A0 still refuses each by its own reviewed file (1e'', 1f').
V="$(release_variant edit "$MASTER" "// ── Release lock ──" "// ── Release lock (edited) ──")"; S="$(pinned_copy v1e1 "$V")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1e' a pin whose master.php changed — one comment — refuses (A0): master.php is not 5.18.93's to ship" \
  "the pin carries files that are not 5.18.93's, which this release must not ship: $MASTER" "$OUT"
M="$(mutant blinda0d1e "$DEPLOY" "$G_A0_DELTA" 'true || stop')"
S="$(pinned_copy v1e2 "$V" "$M")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1e'' the same pin with A0's delta check blinded: A0 still refuses it — its master.php is not 5.18.92's" "the pin's $MASTER is not 5.18.92's" "$OUT"
V="$(release_variant edit "$AP_TEST" " * tests/test_main_auto_pull_retired.php — 5.18.93" " * tests/test_main_auto_pull_retired.php — 5.18.93 (edited)")"; S="$(pinned_copy v1f "$V")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1f a pin whose test of the retired auto-pull is not the reviewed one refuses (A0)" "the pin's $AP_TEST is not the reviewed one" "$OUT"
V="$(release_variant edit "$LOCK_TEST" "'the test left nothing behind'" "'the test left nothing behind (edited)'")"; S="$(pinned_copy v1f1 "$V" "$M")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S" "$M"
nogo "1f' a pin whose lock test is not 5.18.92's reviewed one, A0's delta check blinded, still refuses (A0)" "the pin's $LOCK_TEST is not the reviewed one" "$OUT"
V="$(release_variant add lib/Planted.php)"; S="$(pinned_copy v1g "$V")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1g a pin carrying a file that is not 5.18.93's refuses (A0)" "the pin carries files that are not 5.18.93's, which this release must not ship: lib/Planted.php(added)" "$OUT"
# A switch the scenario itself changes: nothing else changed by the refused run (the data as it was with the change).
nogo_sw() { check "$(has "$3" "$2")$(live)$(backups)$(data_digest)$(stray_snap)" "yes${BASE}0$4$STRAY0" "$1"; }
# 1h–1j — a switch of the pilot whose two copies disagree: the webhook reads the store row first, the workers and the
# crons the configuration file, so the plugin would act two ways at once. One copy taken away at a time, as a hand edit
# could leave it.
cfg_keep; row_unset multi_number_channels_enabled; DX="$(data_digest)"; fresh; OUT="$(run --answer DEPLOY)"
nogo_sw "1h the registry's switch ON in the configuration file and absent from the store row refuses (A5): its two copies disagree" \
  "STOP: NO-GO: multi_number_channels_enabled reads 'mn=on/absent' (files/store)" "$OUT" "$DX"
cfg_back; file_unset sales_own_leads_only; DX="$(data_digest)"; fresh; OUT="$(run --answer DEPLOY)"
nogo_sw "1i own leads only ON in the store row and absent from the configuration file refuses (A5b)" \
  "STOP: NO-GO: sales_own_leads_only reads 'ol=absent/on' (files/store)" "$OUT" "$DX"
cfg_back; row_unset wa_followups_on_owned_numbers; DX="$(data_digest)"; fresh; OUT="$(run --answer DEPLOY)"
nogo_sw "1j the owned follow-ups ON in the configuration file and absent from the store row refuses (A5b)" \
  "STOP: NO-GO: wa_followups_on_owned_numbers reads 'fh=on/absent' (files/store)" "$OUT" "$DX"
cfg_back
check "$(data_digest)" "$D0" "…both copies of every switch put back: the data exactly as seeded"
# 1r — 087's schema stays required whatever its rows hold: one of its triggers gone, A8 refuses.
db_keep; sqx "DROP TRIGGER wa_channels_never_deleted" >/dev/null; fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" 'STOP: NO-GO: migration 087 is recorded on this server, but not')$(has "$OUT" 'missing=wa_channels_never_deleted')$(live)$(backups)" "yesyes${BASE}0" \
  "1r 087 with a trigger missing refuses (A8): its schema is checked object by object, whatever its rows hold"
db_back
check "$(data_digest)$(wa_objects)" "${D0}2:3:3" "…087 whole again: the data exactly as seeded"
# The vault: ConfigVault::apply(), on the plugin's boot path (PluginConfig::load — every cron master runs, the webhook, and
# the installed set_distributors.php that stage A and R2 run), refreshes the vault from the configuration whenever they
# differ. On the server that happens within minutes of any change, so a deploy meets a vault that follows the
# configuration. A scenario that edits the configuration by hand must let the plugin boot once before it measures, and
# must put the vault back with the configuration (rehearsal trial 4, 11 Oct: 1v and 1x measured without either).
vault_keep() { cp -p "$VAULT" "$SB/vault.kept"; }
vault_back() { cp -p "$SB/vault.kept" "$VAULT"; }
vault_tenant() { php -r '$v = json_decode((string)@file_get_contents($argv[1]), true); echo $v["config"]["tenant_profile"] ?? "absent";' "$VAULT"; }
data_digest_novault() { data_dump | grep -v '^vault:' | sha256sum | cut -c1-16; }
plugin_boot() {   # the plugin's own boot path, as the server's crons and webhook run it: PluginConfig::load, nothing else
  env PATH="$SB/bin:$PATH" DN_DATA_DIR="$DATA" bash "$SB/bin/docker" exec ucrm php -r 'require $argv[1] . "/lib/bootstrap_data.php"; require $argv[1] . "/lib/PluginConfig.php"; PluginConfig::load($argv[1], cliDataDir($argv[1]));' "$IN_CONTAINER" >/dev/null 2>&1; }
# 1v — the registry's switch ON in both copies where the code does not take it: on an install that is not Uganda,
# ChannelRegistry::enabled() is false whatever the switch says. Stage A refuses it before anything changes — R11, R12 and
# R2c would fail on exactly this after the copy — at A9; with A9's refusal blinded, at A9b; with both blinded, at A11:
# each layer on its own (the deploy review's P1, 11 Oct).
tenant_both() {   # tenant_both PROFILE — tenant_profile in the configuration file AND the store row
  php -r '$f = $argv[1] . "/kyc_config.json"; $c = json_decode((string)file_get_contents($f), true); $c["tenant_profile"] = $argv[2]; file_put_contents($f, json_encode($c));
    $p = new PDO("sqlite:" . $argv[1] . "/plugin.sqlite3"); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $r = json_decode((string)$p->query("SELECT data FROM kyc_config WHERE id = 0")->fetchColumn(), true); $r["tenant_profile"] = $argv[2];
    $p->prepare("UPDATE kyc_config SET data = ? WHERE id = 0")->execute([json_encode($r)]);' "$DATA" "$1"; }
cfg_keep; vault_keep; tenant_both south-sudan
# 1z — what stage A does to a vault the plugin has not yet refreshed (a deploy started within minutes of a configuration
# change, before the plugin's next run): the installed set_distributors.php --show boots through PluginConfig::load and
# brings the vault up to date, as the plugin's own next run would. Pinned, so it stays known; nothing else changes.
VR="$(vault_tenant)"; DRN="$(data_digest_novault)"; fresh; OUT="$(run --answer DEPLOY)"
check "$(has "$OUT" 'the code does not take the registry')|$VR>$(vault_tenant)|$([ "$(data_digest_novault)" = "$DRN" ] && echo same || echo changed)|$(live)$(backups)$(stray_snap)" \
  "yes|uganda>south-sudan|same|${BASE}0$STRAY0" \
  "1z a deploy started before the plugin's next run after a configuration change: stage A's set_distributors.php --show brings the vault's tenant up to date, as the plugin's own boot would — the vault only, nothing else changed, nothing deployed"
vault_back; plugin_boot
check "$VR>$(vault_tenant)|$([ "$(data_digest_novault)" = "$DRN" ] && echo same || echo changed)" "uganda>south-sudan|same" \
  "1z …the control: the plugin's own boot path (PluginConfig::load, as every cron and the webhook run it) makes the same refresh, so on the server the vault follows the configuration within minutes"
DX="$(data_digest)"; fresh; OUT="$(run --answer DEPLOY)"
nogo_sw "1v the registry's switch ON in both copies where the code does not take it (an install that is not Uganda): A9 refuses before anything changes" \
  "the code does not take the registry" "$OUT" "$DX"
check "$(has "$OUT" 'R12 would fail on exactly this after the copy (A9). Nothing was changed')$(has "$OUT" 'ok    A9 the pin')$(has "$OUT" 'A12 ')" "yesyesno" \
  "1v …the refusal is A9's, after its own comparison of the pin with the live code passed, and before A12 would refuse the install"
M="$(mutant a9regblind "$DEPLOY" '*"registry=off"*) stop "NO-GO: the registry' '*"registry=off"*) note "(blinded A9) the registry')"
fresh; OUT="$(run --script "$M" --answer DEPLOY)"
nogo_sw "1v' with A9's refusal blinded, A9b refuses the same state: the installed code reads the registry not in effect" "R11 would fail on exactly this after the copy (A9b)" "$OUT" "$DX"
M2="$(mutant a9bregblind "$M" '      *) stop "NO-GO: the registry' '      *) note "(blinded A9b) the registry')"; rm -f "$M"
fresh; OUT="$(run --script "$M2" --answer DEPLOY)"; rm -f "$M2"
nogo_sw "1v'' with A9's and A9b's refusals blinded, A11 refuses it: the automated-send policy not in effect as the switch is set" "R2c would fail on exactly this after the copy (A11)" "$OUT" "$DX"
cfg_back; vault_back
# 1x, 1y — the hand-over copy, the one pilot switch whose absence means ON (AlertService): OFF in the configuration file and
# absent from the store row, its two copies disagree — refused (A5b); ON in the file and absent from the row, they agree —
# accepted up to the question, printed as found (the deploy review's RC-5).
file_set() {   # file_set KEY VALUE — a key in the configuration file ONLY; the store row keeps its own
  php -r '$f = $argv[1] . "/kyc_config.json"; $c = json_decode((string)file_get_contents($f), true); $c[$argv[2]] = $argv[3]; file_put_contents($f, json_encode($c));' "$DATA" "$1" "$2"; }
cfg_keep; vault_keep; file_set wa_handover_copy_central 0; plugin_boot; DX="$(data_digest)"; fresh; OUT="$(run --answer DEPLOY)"
nogo_sw "1x the hand-over copy OFF in the configuration file and absent from the store row (absent: ON) refuses (A5b): its two copies disagree" \
  "STOP: NO-GO: wa_handover_copy_central reads 'hc=off/absent' (files/store)" "$OUT" "$DX"
cfg_back; vault_back; file_set wa_handover_copy_central 1; plugin_boot; fresh; OUT="$(run --answer NO)"
check "$(fails "$OUT")|$(has "$OUT" 'STOP: not confirmed')|$(okline "$OUT" A5b 'hc=on/absent')" "0|yes|yes" \
  "1y the hand-over copy ON in the configuration file and absent from the store row is accepted — absent reads ON, as the code reads it — and printed as found"
cfg_back; vault_back
check "$(data_digest)" "$D0" "…the tenant, the hand-over copy and the vault put back: the data exactly as seeded"
pdd_stray() { php -r '$f = $argv[1]; $u = json_decode((string)file_get_contents($f), true); $u["pluginDataDir"] = $argv[2]; file_put_contents($f, json_encode($u));' "$PD/ucrm.json" "$IN_CONTAINER/data"; }
cp "$PD/ucrm.json" "$SB/ucrm.kept"; pdd_stray; fresh; OUT="$(run --answer DEPLOY)"
nogo "1k ucrm.json naming <plugin>/data refuses before anything is read from it" "NO-GO: the plugin's data directory would be $IN_CONTAINER/data — the stray store under the plugin folder" "$OUT"
cp "$SB/ucrm.kept" "$PD/ucrm.json"
printf 'planted\n' > "$REPO/$P/lib/untracked-plant.php"; fresh; OUT="$(run --answer DEPLOY)"; rm -f "$REPO/$P/lib/untracked-plant.php"
nogo "1l a file git does not track under the checkout's plugin folder refuses (scripts/deploy-hybrid.sh copies the working tree)" "NO-GO: the checkout holds 1 file(s) git does not track under $P, outside data/ (dishnet-hybrid-sudan/lib/untracked-plant.php)" "$OUT"
# 5.18.93's own layer, with A0 blinded: a pin whose main.php is 5.18.92's — the auto-pull still in it — is refused by the
# pin's own test of the retired auto-pull on the server's PHP (A16). A main.php put back drops out of the delta, so A0's
# check that every file of the release is there is blinded together with its main.php check.
BLIND_A0="$(mutant blinda0 "$DEPLOY" "$G_A0_MISSING" 'true || stop' "$G_A0_MAIN" 'true || stop')"
VMAIN="$(release_variant revert "$MAIN")"
S="$(pinned_copy v1m "$VMAIN" "$BLIND_A0")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1m with A0 blinded, a pin whose main.php is 5.18.92's — the auto-pull still in it — is refused by A16: its own test fails on the server's PHP" \
  "NO-GO: under this server's own PHP the pin's test of the retired auto-pull does not pass" "$OUT"
check "$(has "$OUT" 'ok    A16')$(has "$OUT" 'ok    A15')" "noyes" "…and A16 said no ok line — A15, before it, its own"
check "$(has "$OUT" "auto-pull       what the test printed:")$( [ "$(grep -c '^    | ' <<<"$OUT")" -gt 0 ] && echo some || echo none)" "yessome" \
  "…and the log shows why, as the test printed it: its failed assertions, then its last lines"
# 5.18.92's layer, kept: with A0's delta and master.php checks blinded, a pin whose master releases the jobs' lock again —
# the release put back — is refused by the lock test on the server's PHP (A15).
BLIND_A0M="$(mutant blinda0m "$DEPLOY" "$G_A0_DELTA" 'true || stop' "$G_A0_MASTER" 'true || stop')"
VLOCK="$(release_variant edit "$MASTER" "if (is_resource(\$_m_lockFp)) {
    flock(\$_m_lockFp, LOCK_UN);
    fclose(\$_m_lockFp);
}" "flock(\$lockFp, LOCK_UN);
fclose(\$lockFp);")"
S="$(pinned_copy v1m1 "$VLOCK" "$BLIND_A0M")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1m' with A0 blinded, a pin whose master releases \$lockFp again is refused by A15 — 5.18.92's own test fails on the server's PHP" \
  "NO-GO: under this server's own PHP the pin's test of master's lock does not pass" "$OUT"
check "$(has "$OUT" 'ok    A15')" "no" "…and A15 said no ok line"
check "$(has "$OUT" "master's lock   what the test printed:")$( [ "$(grep -c '^    | ' <<<"$OUT")" -gt 0 ] && echo some || echo none)" "yessome" \
  "…and the log shows why, as the test printed it: its failed assertions, then its last lines"
# With A0's delta check blinded, a pin carrying 5.18.90's retry job — no gate — is refused by A13.
BLIND_A0D="$(mutant blinda0d "$DEPLOY" '[ -z "$STRAY" ] || stop' 'true || stop')"
# the release's tree with 5.18.90's retry job in it (release_variant's revert takes the base's, which is the pin's own)
VIDX="$SB/v.idx"; GIT_INDEX_FILE="$VIDX" git -C "$REPO" read-tree "$PIN"
GIT_INDEX_FILE="$VIDX" git -C "$REPO" update-index --cacheinfo "100644,$(git -C "$REPO" rev-parse "$OLDER2:$P/$JOB"),$P/$JOB"
VT="$(GIT_INDEX_FILE="$VIDX" git -C "$REPO" write-tree)"; rm -f "$VIDX"
V="$(git -C "$REPO" -c user.name=rehearsal -c user.email=rehearsal@example.test commit-tree "$VT" -p "$(git -C "$REPO" rev-parse "$BASE")" -m "rehearsal variant: 5.18.90's retry job")"
S="$(pinned_copy v1n "$(git -C "$REPO" rev-parse --short "$V")" "$BLIND_A0D")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1n with A0's delta check blinded, a pin carrying 5.18.90's retry job (no gate) is refused by A13" "NO-GO: the pin's retry job does not stop on Uganda" "$OUT"
check "$(has "$OUT" "could not be made: pin-job-not-5.18.91's uganda-not-stopped — nothing was changed")" "yes" \
  "…naming exactly these two: the job is not 5.18.91's, and it would not stop on Uganda — the live job's own results as they should be"
rm -f "$BLIND_A0" "$BLIND_A0M" "$BLIND_A0D"
# A13's live side: the installed retry job not 5.18.91's, behind a record that says it is. The base check reads only the
# files this release changes, and the retry job is not one of them, so A13 is the layer that sees it. Its gate taken out:
# on Uganda it no longer stops.
cp -p "$PD/$JOB" "$SB/job91.aside"; edit_installed "$JOB" "    return StarlinkRetryScope::skip(__DIR__);" "    return false;"
fresh; OUT="$(run --answer DEPLOY)"; cp -p "$SB/job91.aside" "$PD/$JOB"
nogo "1p the installed retry job with its gate taken out, behind a record that says 5.18.92: A13 refuses — the live job would not stop on Uganda" \
  "could not be made: live-uganda-not-stopped — nothing was changed" "$OUT"
# Its control: the installed job no longer opening anything (its data folder renamed) — the open by which A13 shows its
# observation sees a change is gone on South Sudan, so it cannot judge the pin.
edit_installed "$JOB" "\$dataDir = \$pluginDir . '/data';" "\$dataDir = \$pluginDir . '/data-rh';"
fresh; OUT="$(run --answer DEPLOY)"; cp -p "$SB/job91.aside" "$PD/$JOB"; rm -f "$SB/job91.aside"
nogo "1q A13's control: the installed job no longer opening the stray store on South Sudan — the change the observation must see is gone: NO-GO" \
  "could not be made: live-south-sudan-not-measured south-sudan-differs — nothing was changed" "$OUT"
check "$(sha256sum "$PD/$JOB" | cut -c1-64)" "$JOB91_SHA" "…the installed job put back, 5.18.91's byte for byte"
fresh; OUT="$(run --answer DEPLOY --env FAKE_CP_FAIL=1)"
nogo "1w the two releases' code cannot be copied into the container: NO-GO before the checks that need it, A15 and A16 among them" \
  "STOP: NO-GO: the two releases' code could not be copied into the container's /tmp" "$OUT"
# 1o — either state: the pilot OFF, exactly as 5.18.92's deploy found it on 10 Oct (the registry, own leads only and the
# owned follow-ups absent in both copies, the department numbers not verified, sales-001 switched off, its assistant off),
# is accepted as the pilot on is: the deploy goes as far as its question (declined here), each switch printed as found.
snap_load off; check "$(data_digest)" "$D_OFF" "the pilot OFF, exactly as kept before the 10 Oct steps"
fresh; OUT="$(run --answer NO)"
check "$(fails "$OUT")|$(has "$OUT" 'STOP: not confirmed')|$(okline "$OUT" A5 'mn=absent/absent')$(okline "$OUT" A5b 'ol=absent/absent' 'fh=absent/absent')$(okline "$OUT" A8 'migration 087')$(okline "$OUT" A9 "$SHAPE_OFF")$(okline "$OUT" A11 'policy=off/off')|$(has "$OUT" ' dnum=0 other=1 active=0 oai=0 ')" \
  "0|yes|yesyesyesyesyes|yes" \
  "1o the pilot OFF, as 5.18.92's deploy found it, is accepted: every check passes up to the question — the switches absent in both copies, 087's rows as found, the routing and the policy the live code's"
check "$(grep -cE -- "$LEAK_RX|$NUM_RX" <<<"$OUT")" "0" "…and the log names no instance, no person and no number"
snap_load on; rm -rf "$SB/out"/state-*.env* "$SB/out"/backup-*
check "$(data_digest)" "$D0" "…the pilot back ON, exactly as seeded"
# 1s–1u — the automated-send policy compared on THIS server's configuration (A11). A pin whose policy acts differently only
# where the registry is on — every answer it would give with the registry on becomes a refusal, 'rehearsal_edit' — with A0's
# delta check blinded so it reaches the comparisons. With the pilot on, A11 sees it before anything changes (1s); with A11
# blinded too, A6's throwaway database does (1t); with the pilot off the two policies read alike on this configuration,
# so A11 passes and A6 alone refuses it (1u) — A11 compares the two code roots on the live configuration, never a fixed
# signature.
BLIND_A0P="$(mutant blinda0p "$DEPLOY" "$G_A0_DELTA" 'true || stop')"
VPOL="$(release_variant edit lib/AutomationPolicy.php "        \$r = \$this->channelRefusal(\$channel, \$fresh);
        if (\$r !== '') return \$r;" "        \$r = \$this->channelRefusal(\$channel, \$fresh);
        if (\$r === '' && \$this->mode === 'on') return 'rehearsal_edit';
        if (\$r !== '') return \$r;")"
S="$(pinned_copy v1s "$VPOL" "$BLIND_A0P")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo "1s with A0 blinded, a pin whose automated-send policy reads differently from the live code's on this server's configuration — the pilot on — is refused by A11" \
  "NO-GO: on this server's own configuration the pin's automated-send policy" "$OUT"
check "$(has "$OUT" 'ok    A11')$(has "$OUT" 'send-policy')" "nono" "…by A11 itself, before A6 runs"
M="$(mutant blinda11 "$BLIND_A0P" "$G_A11" 'true || stop')"
S="$(pinned_copy v1t "$VPOL" "$M")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S" "$M"
nogo "1t with A0 and A11 blinded, the same pin is refused by A6 — its policy on a throwaway database with the registry on: the two layers are independent" \
  "could not be made: send-policy — nothing was changed" "$OUT"
snap_load off; DX="$(data_digest)"
S="$(pinned_copy v1u "$VPOL" "$BLIND_A0P")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S"
nogo_sw "1u the same pin with the pilot OFF: on this configuration its policy reads as the live code's, so A11 passes, and A6 refuses it" \
  "could not be made: send-policy — nothing was changed" "$OUT" "$DX"
check "$(okline "$OUT" A11 'policy=off/off')" "yes" "…A11 said ok there, reading policy=off/off for the pin and the live code alike"
snap_load on; check "$(data_digest)" "$D0" "…the pilot back ON, exactly as seeded"

echo; echo "== 2. weakened copies of stage A and V4 are caught (the control on the control) =="
# 2a — A15 blinded too: the pin of 1m' now reaches the question — A15 was the layer that refused it.
M="$(mutant blinda15 "$DEPLOY" "$G_A0_DELTA" 'true || stop' "$G_A0_MASTER" 'true || stop' "$G_A15" 'true || {')"
S="$(pinned_copy v2a "$VLOCK" "$M")"; fresh; OUT="$(run --answer NO --script "$S")"; rm -f "$S" "$M"
check "$(has "$OUT" 'STOP: not confirmed')$(has "$OUT" 'NO-GO: under this server')" "yesno" "2a with A0 and A15 blinded, the pin of 1m' reaches the question — A15 is the layer that refuses it (caught)"
rm -rf "$SB/out"/state-5.18.93.env* "$SB/out"/backup-*
# 2b — A0 blinded alone: A15 still refuses (1m') — shown above; here A15 blinded alone: A0 still refuses, by the delta.
M="$(mutant blinda15only "$DEPLOY" "$G_A15" 'true || {')"
S="$(pinned_copy v2b "$VLOCK" "$M")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S" "$M"
nogo "2b with A15 blinded alone, A0 still refuses the same pin — its master.php is not 5.18.93's to ship: the two layers are independent" \
  "the pin carries files that are not 5.18.93's, which this release must not ship: $MASTER" "$OUT"
# 2a'' — A16 blinded too: 1m's pin — 5.18.92's main.php — now reaches the question: A16 was the layer that refused it.
M="$(mutant blinda16 "$DEPLOY" "$G_A0_MISSING" 'true || stop' "$G_A0_MAIN" 'true || stop' "$G_A16" 'true || {')"
S="$(pinned_copy v2a2 "$VMAIN" "$M")"; fresh; OUT="$(run --answer NO --script "$S")"; rm -f "$S" "$M"
check "$(has "$OUT" 'STOP: not confirmed')$(has "$OUT" 'NO-GO: under this server')$(has "$OUT" 'ok    A15')" "yesnoyes" \
  "2a'' with A0's two main.php checks and A16 blinded, 1m's pin reaches the question — A16 is the layer that refuses it (caught)"
rm -rf "$SB/out"/state-5.18.93.env* "$SB/out"/backup-*
# 2b'' — A16 blinded alone: A0 still refuses 1m's pin — first because its main.php, 5.18.92's, is no longer in the delta…
M="$(mutant blinda16only "$DEPLOY" "$G_A16" 'true || {')"
S="$(pinned_copy v2b2 "$VMAIN" "$M")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S" "$M"
nogo "2b'' with A16 blinded alone, A0 still refuses 1m's pin: main.php is missing from its delta — the two layers are independent" \
  "the pin lacks files 5.18.93 is made of: $MAIN" "$OUT"
# 2b''' — …and, that check blinded too, because its main.php is not the reviewed release file.
M="$(mutant blinda16miss "$DEPLOY" "$G_A0_MISSING" 'true || stop' "$G_A16" 'true || {')"
S="$(pinned_copy v2b3 "$VMAIN" "$M")"; fresh; OUT="$(run --answer DEPLOY --script "$S")"; rm -f "$S" "$M"
nogo "2b''' with A16 and A0's completeness check blinded, A0's main.php check still refuses 1m's pin" "the pin's $MAIN is not the reviewed release file" "$OUT"
# 2c — A14 blind to the folder: it says 'seen' over a folder changed since 5.18.92's copy.
stray_keep; touch "$PD/data"; sleep 1
fresh; OUT="$(run --answer NO)"
check "$(has "$OUT" 'note  A14 the stray store is not as 5.18.92')$(has "$OUT" 'ok    A14')" "yesno" "2c (control) the stray folder changed since 5.18.92's copy: A14 is a note, never seen"
M="$(mutant blinda14 "$DEPLOY" '&& [ "$SLR_F0" = "$P91_DIR" ]; then' '; then')"
fresh; OUT="$(run --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" 'ok    A14')" "yes" "2c the copy blind to the folder's time says 'seen' over that folder (caught)"
stray_back; rm -rf "$SB/out"/state-5.18.93.env* "$SB/out"/backup-*
check "$(stray_snap)" "$STRAY0" "…the stray folder back exactly as seeded"
# 2d — A14 with no 5.18.92 record, or one holding an R19 failure: a note each.
mv "$PREV" "$PREV.away"; fresh; OUT="$(run --answer NO)"; mv "$PREV.away" "$PREV"
check "$(has "$OUT" "note  A14 5.18.92's deploy record ($PREV) is not on this server")" "yes" "2d no 5.18.92 record: A14 is a note"
cp "$PREV" "$PREV.kept"; printf 'STRAY_R19_FAILED=1760060000\n' >> "$PREV"; fresh; OUT="$(run --answer NO)"; cp "$PREV.kept" "$PREV"; rm -f "$PREV.kept"
check "$(has "$OUT" "note  A14 5.18.92's record holds an R19 failure (STRAY_R19_FAILED in $PREV)")$(has "$OUT" "note  an earlier deploy's R19 FAILED")" "yesyes" "2d a 5.18.92 record holding an R19 failure: A14 and the state check each say so"
rm -rf "$SB/out"/state-5.18.93.env* "$SB/out"/backup-*
# 2e — each of A14's other conditions alone: the record's own A14 not 'seen'; the database file changed, its folder's time
# kept; a side file beside it now, its folder's time kept; a side file in the record. Each is a note, never 'seen' — and a
# copy of the script blind to that one condition says 'seen' (caught).
a14_case() {   # a14_case LABEL MUTANT-ANCHOR NAME
  local out m
  fresh; out="$(run --answer NO)"
  check "$(has "$out" 'note  A14 the stray store is not as 5.18.92')$(has "$out" 'ok    A14')" "yesno" "2e (control) $1: A14 is a note, never seen"
  m="$(mutant "a14-$3" "$DEPLOY" "$2" '')"
  fresh; out="$(run --answer NO --script "$m")"; rm -f "$m"
  check "$(has "$out" 'ok    A14')" "yes" "2e …the copy blind to it says 'seen' (caught)"
  rm -rf "$SB/out"/state-5.18.93.env* "$SB/out"/backup-*
}
prev_record not-seen
a14_case "5.18.92's record says its A14 did not see the instrument work" '[ "$P91_CTRL" = "seen" ] && ' ctrl
prev_record
stray_keep; touch -m -d '-2 hours' "$PD/data/plugin.sqlite3"; touch -d "$(cat "$SB/strayk.t")" "$PD/data"
a14_case "the stray database file changed since 5.18.92's copy, its folder's time kept" '&& [ "${P91_COPY%% *}" = "${STRAY_A%% *}" ] ' db
stray_back
stray_keep; : > "$PD/data/plugin.sqlite3-wal"; touch -d "$(cat "$SB/strayk.t")" "$PD/data"
a14_case "a side file beside the stray store now, its folder's time kept" '&& [ "$A_SIDE" = "-" ] ' side-now
stray_back
sed -i 's/^\(STRAY_COPY=.*\) side=- /\1 side=-wal-shm /' "$PREV"
check "$(grep -c '^STRAY_COPY=.* side=-wal-shm ' "$PREV")" "1" "…5.18.92's record edited: a side file beside the stray store at its copy"
a14_case "a side file beside the stray store at 5.18.92's copy" '&& [ "$P91_SIDE" = "-" ] ' side-then
prev_record
check "$(stray_snap)$(grep -c "^STRAY_COPY=$STRAY_LINE0\$" "$PREV")$(grep -c '^STRAY_CTRL=seen$' "$PREV")" "${STRAY0}11" "…the stray folder and 5.18.92's record back exactly as seeded"
# 2f — the stray store's migration.log grown since 5.18.92's copy, as cron/dpo_reconcile.php grows it on the server every
# five minutes: A14 compares the database file, the side files and the folder's time, not the log — still 'seen'.
stray_keep; DW="$(dpo_writer)"
fresh; OUT="$(run --answer NO)"
check "$DW|$( [ "$(stray_line)" != "$STRAY_LINE0" ] && echo moved)|$( [ "$(stat -c %Y "$PD/data")" = "${STRAY_LINE0##* dir=}" ] && echo folder-kept)|$(has "$OUT" 'ok    A14 the stray store is exactly as 5.18.92')" "done|moved|folder-kept|yes" \
  "2f the stray store's migration.log grown since 5.18.92's copy, as dpo_reconcile grows it, the folder's time unchanged by it: A14 still 'seen' — the log is not what it compares"
stray_back; rm -rf "$SB/out"/state-5.18.93.env* "$SB/out"/backup-*
check "$(stray_snap)" "$STRAY0" "…the stray folder back exactly as seeded"

# 2g — A0, A11 and A6's policy check all blinded: 1s's pin now reaches the question — A11 and A6 are the layers that refuse it.
M="$(mutant blinda11a6 "$BLIND_A0P" "$G_A11" 'true || stop' "$G_A6_POLICY" 'true || A6_BAD="$A6_BAD send-policy"')"
S="$(pinned_copy v2g "$VPOL" "$M")"; fresh; OUT="$(run --answer NO --script "$S")"; rm -f "$S" "$M" "$BLIND_A0P"
check "$(has "$OUT" 'STOP: not confirmed')$(has "$OUT" 'STOP: NO-GO')" "yesno" "2g with A0, A11 and A6's policy check blinded, 1s's pin reaches the question (caught: A11 and A6 refuse it)"
rm -rf "$SB/out"/state-*.env* "$SB/out"/backup-*

echo; echo "== 3. the deploy, as the operator runs it =="
D1="$(data_digest)"; EV1="$(sq "SELECT group_concat(id || ':' || event_type || ':' || status, ',') FROM events")"
STRAY_LINE="$(stray_line)"
fresh; touch "$SB/web/.plant_old"; OUT="$(run --answer DEPLOY)"
echo "  …    the deploy's own tally:$(grep -E '^  checks +[0-9]+ ok, ' <<<"$OUT" | sed -E 's/^  checks +/ /')"
# NOTES: confirm on the first run — R19's note and V4's, as on 5.18.92's deploy; the pilot as found adds none.
check "$(fails "$OUT")$(notes "$OUT")" "02" "no FAIL line and two notes — R19's, and V4's for 5.18.92's own fatal at main.php:484 planted during the run"
check "$(has "$OUT" '5.18.93 (deploy): PASSED')" "yes" "PASSED"
for l in "ok    A0 the release delta is exactly 5.18.93's 10 files — 1 added, 9 changed, no migration (087 the reviewed one, sha256 ${MIG_SHA:0:16}…, unchanged); $MAIN the reviewed release file" \
         "and its test the reviewed one; $MASTER 5.18.92's byte for byte (sha256 ${MASTER92_SHA:0:16}…), with no AI media job" \
         "ok    A7 Domain B untouched" \
         "release code    $PIN (5.18.93) and $BASE (5.18.92), copied into the container's /tmp for the comparisons below" \
         "ok    A8 migration 087 (5.18.88's) is applied and complete on this server" \
         "ok    A9 the pin's code routes the three numbers exactly as the live code does" \
         "ok    A10 D3 the pin's assistant builds exactly 5.18.92's prompt" \
         "ok    A6 South Sudan stays exactly as it is" \
         "stray store     at 5.18.92's copy" \
         "ok    A14 the stray store is exactly as 5.18.92's deploy recorded it at its copy" \
         "ok    A12 on this server's own configuration the pin's gate stops the Starlink retry job" \
         "retry job       live 5.18.92, Uganda:      $SLR_STOPS | locks=kept" \
         "                  pin  5.18.93, Uganda:      $SLR_STOPS | locks=kept" \
         "                  live 5.18.92, South Sudan: $SLR_OPENS | locks=kept" \
         "ok    A13 the pin's retry job — the live 5.18.92 job byte for byte —" \
         "master's lock   pin  5.18.93's $LOCK_TEST under PHP" \
         "ok    A15 under this server's own PHP" \
         "the live $MASTER is 5.18.92's byte for byte" \
         "auto-pull       pin  5.18.93's $AP_TEST under PHP" \
         "ok    A16 under this server's own PHP" \
         "the live $MAIN still carries the block" \
         "GO — evidence recorded" \
         "checking out $PIN (5.18.93, the release commit) for the documented deploy" \
         "ok    container serves $PIN" \
         "ok    V3f the channel registry's switch is unchanged by this run (before=" \
         "ok    V3h 5.18.89's switches are unchanged by this run (" \
         "ok    V3i migration 087's rows and trail are unchanged by this run, every column compared under this run's key, as found before it" \
         "ok    V4 no new fatal or parse error of $P in the container log since" \
         "note  V4 1 line(s) of the old fatal at main.php:484 or :509 since" \
         "ok    R1 all 10 files 5.18.93 changes are installed exactly as $PIN has them (1 new)" \
         "ok    R1 the installed manifest says 5.18.93" \
         "ok    R5 all" \
         "ok    R11 S4/S5 the channel registry is as the operator left it, unchanged by this run" \
         "ok    R12 S1–S3 the three numbers route exactly as on 5.18.92" \
         "ok    R16 D3 the installed assistant builds exactly 5.18.92's prompt" \
         "ok    R18 the installed retry job, on throwaway layouts" \
         "ok    R19 the stray store under the plugin folder is exactly as before the deploy" \
         "note  R19 the stray folder has not changed since the copy" \
         "but master's record shows no completed run of the retry job since this deploy's copy at" \
         "ok    R20 under this server's own PHP the installed test of master's lock passes ($LT_SIG)" \
         "ok    R21 under this server's own PHP the installed test of the retired auto-pull passes ($AP_SIG)" \
         "what changed      main.php's nightly uCRM auto-pull is retired" \
         "and once more a day later"; do
  check "$(has "$OUT" "$l")" "yes" "3: ${l:0:100}"
done
check "$(grep -cE -- "$LEAK_RX|$NUM_RX" <<<"$OUT")" "0" "the log carries no Evolution address, key or instance name, and no number"
# The pilot as the operator left it: each switch printed as found and unchanged by the run; 087's rows and trail as found and
# unchanged; the routing, the policy and the prompt the two code roots' alike on this configuration — each check's verdict
# and the facts its line names (okline), the wording the script's own.
check "$(okline "$OUT" A5 'mn=on/on')$(okline "$OUT" A5b 'ol=on/on' 'fh=on/on' 'hc=absent/absent')$(okline "$OUT" A8 'migration 087')$(has "$OUT" ' dnum=2 other=1 active=1 oai=1 ')" "yesyesyesyes" \
  "3 pilot: A5 and A5b print the switches as found — the registry, own leads only and the owned follow-ups ON in both copies, the hand-over copy absent — and A8 accepts 087 with its rows as found: two department numbers verified, sales-001 switched on, its assistant on"
check "$(okline "$OUT" A9 "$SHAPE_ON")$(okline "$OUT" A11 'policy=on/on')" "yesyes" \
  "3 pilot: A9 the routing through the registry, A11 the policy ON — the pin's each equal to the live code's on this configuration"
check "$(okline "$OUT" V3f 'mn=on/on')$(okline "$OUT" V3h 'ol=on/on' 'fh=on/on' 'hc=absent/absent')" "yesyes" \
  "3 pilot: V3f and V3h — each switch unchanged by the run, as found"
check "$(okline "$OUT" R2b 'files=on,free,shown store=on,free,shown')$(okline "$OUT" R2c 'policy=on/on')$(okline "$OUT" R3 'migration 087' 'unchanged')$(okline "$OUT" R11 'enabled=on/on' 'all verified for their instances')$(okline "$OUT" R12 "$SHAPE_ON")" "yesyesyesyesyes" \
  "3 pilot: R2b own leads only on, the follow-up hold free, the card shown; R2c the installed policy as before the copy; R3 087's rows and trail unchanged; R11 the registry in effect, every department number verified; R12 the routing as before"
check "$(has "$OUT" 'multi_number_channels_enabled on/on')$(grep -cE -- "$STALE_RX" <<<"$OUT")" "yes0" \
  "3 pilot: the summary names the registry's switch as found, and nothing in the log says the registry is dark or off, or the policy inert"
check "$(has "$OUT" "what the test printed")" "no" "neither test's own output is printed when it passes (A15, A16, R20, R21)"
check "$(grep -A7 -E '^  (note|FAIL)  V4' <<<"$OUT" | grep -cE 'Value of type null|not callable|UNCAUGHT')$(grep -A7 -E '^  note  V4' <<<"$OUT" | grep -cE '^ +20[0-9-]+T[0-9:]+Z main\.php:484$')" "01" "V4 names the old fatal by its time and file:line only, never its message"
check "$(live)$(installed_digest "$PIN")$(inst_ver)" "${PIN}05.18.93" "the container serves $PIN: every changed file exactly as the commit has it — the test it adds among them — manifest 5.18.93"
check "$(data_digest)" "$D1" "the deploy wrote no record and no configuration value"
check "$(sq "SELECT group_concat(id || ':' || event_type || ':' || status, ',') FROM events")" "$EV1" "…the event queue exactly as before"
check "$(stray_snap)" "$STRAY0" "the stray store's folder is exactly as before — the deploy neither copied into it nor opened it"
STATE="$SB/out/state-5.18.93.env"
check "$(grep -c '^STRAY_CTRL=seen$' "$STATE")$(grep -c "^STRAY_COPY=$STRAY_LINE\$" "$STATE")$(grep -c "^STRAY_COPY_AT=$(stat -c %Y "$PD/$MAIN")\$" "$STATE")" "111" \
  "the state file records A14's 'seen', the stray store at the copy, and the copy's time as the time of main.php the copy stamped"
check "$(host_code_left)$(left_tmp)" "0$TMP0" "the run removed its copies of the two releases' code, every throwaway layout and the two tests' own files"
check "$(grep -c "cd $REPO && bash scripts/deploy-5.18.93.sh --rollback" <<<"$OUT")" "1" "the rollback command is printed once, on its own line"
check "$(awk '/PASSED\. Send this LOG FILE back/ {p=1} p && /deploy-5\.18\.93\.sh --rollback/ {print "after"; exit}' <<<"$OUT")" "after" "…after the verdict, at the end of the log"
check "$(evo_calls)" "0" "Evolution was never called — not by the deploy, its checks or the installed code it ran"
check "$(grep -cxE 'MN_BEFORE=mn=on/on|OL_BEFORE=ol=on/on|FH_BEFORE=fh=on/on|HC_BEFORE=hc=absent/absent' "$STATE")$(grep -c '^WA87_BEFORE=.* dnum=2 other=1 active=1 oai=1 ' "$STATE")" "41" \
  "the state file records the pilot as this deploy found it — each switch, both copies, and 087's rows"

echo; echo "== 4. --after-only: R19 conclusive once master has run the job; V4 and the old fatal =="
copy_back() {   # the copy N minutes ago, as a later --after-only finds it: main.php's time, the copy's recorded time, and the
                # deploy's start a minute before it (V4 reads the log from there)
  touch -d "-$1 minutes" "$PD/$MAIN"; local t; t="$(stat -c %Y "$PD/$MAIN")"
  sed -i -e "s/^STRAY_COPY_AT=.*/STRAY_COPY_AT=$t/" -e "s/^DEPLOYED_AT=.*/DEPLOYED_AT=$(date -u -d "@$(( t - 60 ))" +%Y-%m-%dT%H:%M:%SZ)/" "$STATE"; }
copy_back 30; COPY_T="$(stat -c %Y "$PD/$MAIN")"
base_log; OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" "note  R19 the stray folder has not changed since the copy")$(has "$OUT" "(its last: $(utc "$SCHED0"))")" "0yesyes" \
  "4a 30 minutes on, master's last record of the job still from before the deploy: a note — not yet evidence"
set_sched $(( $(date -u +%s) - 300 ))
base_log; OUT="$(run --after-only)"
check "$(fails "$OUT")$(notes "$OUT")$(has "$OUT" '5.18.93 (after): PASSED')$(has "$OUT" 'ok    R19 the retry job ran at')$(has "$OUT" "after this deploy's copy (at $(utc "$COPY_T"))")$(has "$OUT" "nothing has opened the stray store since 5.18.92's copy")" "00yesyesyesyes" \
  "4c master completed a run of the retry job after the copy and the folder stayed quiet since 5.18.92's copy: R19 PASSES with 0 failed, 0 notes — the evidence the quarantine waits for"
check "$(has "$OUT" 'ok    R20 under this server')$(has "$OUT" 'ok    R21 under this server')$(has "$OUT" 'ok    V4 no new fatal')" "yesyesyes" "…4c: R20, R21 and V4 pass in --after-only too"
base_log; logline $(( COPY_T + 600 )) "$OLDFATAL"; OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" "note  V4 1 line(s) of the old fatal at main.php:484 or :509")$(has "$OUT" "$(utc $(( COPY_T + 600 ))) main.php:484")$(has "$OUT" '5.18.93 (after): PASSED')" "0yesyesyes" \
  "4d 5.18.92's own fatal at main.php:484 10 minutes after the copy — a main.php run of the pull hour begun on 5.18.92's code: a note, named by time and file:line; still PASSED"
base_log; logline $(( COPY_T + 600 )) "$OLDFATAL509"; OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" "note  V4 1 line(s) of the old fatal at main.php:484 or :509")$(has "$OUT" "$(utc $(( COPY_T + 600 ))) main.php:509")$(has "$OUT" '5.18.93 (after): PASSED')" "0yesyesyes" \
  "4d'' its other line, main.php:509 — the run that had the wallet sync — 10 minutes after the copy: a note too; still PASSED"
base_log; logline $(( COPY_T + 2400 )) "$OLDFATAL"; OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" "FAIL  V4 1 fatal line(s) of $P")$(has "$OUT" "$(utc $(( COPY_T + 2400 ))) main.php:484")" "1yesyes" \
  "4e the same line 40 minutes after the copy — beyond V4's 30-minute bound, so 5.18.92's main.php is still being run: FAIL"
base_log; logline $(( $(date -u +%s) - 30 )) "PHP Fatal error:  Uncaught Error: planted by the rehearsal in /data/ucrm/data/plugins/dishnet-hybrid-sudan/cron/event_processor.php:12"; OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" " cron/event_processor.php:12")" "1yes" "4f any other fatal of the plugin: FAIL, named by file:line"
OTHER484='[DishNet UNCAUGHT] planted by the rehearsal in /data/ucrm/data/plugins/dishnet-hybrid-sudan/main.php:484'
base_log; logline $(( COPY_T + 600 )) "${OLDFATAL%:484}:485"; OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" "FAIL  V4 1 fatal line(s) of $P")$(has "$OUT" "$(utc $(( COPY_T + 600 ))) main.php:485")" "1yesyes" \
  "4h 10 minutes after the copy, the old fatal's message at another line of main.php: not the old fatal — FAIL, named by file:line"
base_log; logline $(( COPY_T + 600 )) "$OTHER484"; OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" "FAIL  V4 1 fatal line(s) of $P")$(has "$OUT" "$(utc $(( COPY_T + 600 ))) main.php:484")" "1yesyes" \
  "4i 10 minutes after the copy, another fatal at the old line: not the old fatal — FAIL"
base_log; logline $(( COPY_T + 600 )) "$FATAL405"; OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" "FAIL  V4 1 fatal line(s) of $P")$(has "$OUT" "$(utc $(( COPY_T + 600 ))) cron/master.php:405")" "1yesyes" \
  "4l 5.18.91's own fatal, cron/master.php:405, 10 minutes after the copy: no longer an old fatal — 5.18.92 has run since 10 Oct — so it FAILS"
M="$(mutant v4noline "$DEPLOY" "$G_V4_WHERE" 'case "main.php:484" in')"
base_log; logline $(( COPY_T + 600 )) "${OLDFATAL%:484}:485"; OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(fails "$OUT")$(has "$OUT" '5.18.93 (after): PASSED')" "0yes" "4h' the copy of V4 that does not compare the file:line takes 4h's line for the old fatal and passes (caught: 4h fails it)"
V4MSG="$(cat <<'A'
main.php:484) printf '%s' "$v4l" | grep -qF 'Value of type null is not callable' && v4o=1 ;;
A
)"
M="$(mutant v4nomsg "$DEPLOY" "$V4MSG" 'main.php:484) v4o=1 ;;')"
base_log; logline $(( COPY_T + 600 )) "$OTHER484"; OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(fails "$OUT")$(has "$OUT" '5.18.93 (after): PASSED')" "0yes" "4i' the copy of V4 that does not read the message takes 4i's line for the old fatal and passes (caught: 4i fails it)"
OTHER509='[DishNet UNCAUGHT] planted by the rehearsal in /data/ucrm/data/plugins/dishnet-hybrid-sudan/main.php:509'
base_log; logline $(( COPY_T + 600 )) "$OTHER509"; OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" "FAIL  V4 1 fatal line(s) of $P")$(has "$OUT" "$(utc $(( COPY_T + 600 ))) main.php:509")" "1yesyes" \
  "4i'' 10 minutes after the copy, another fatal at :509: not the old fatal — FAIL (the deploy review's RC-6)"
V4MSG509="$(cat <<'A'
main.php:509) printf '%s' "$v4l" | grep -qF 'DishNet\SqliteStore' && v4o=1 ;;
A
)"
M="$(mutant v4nomsg509 "$DEPLOY" "$V4MSG509" 'main.php:509) v4o=1 ;;')"
base_log; logline $(( COPY_T + 600 )) "$OTHER509"; OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(fails "$OUT")$(has "$OUT" '5.18.93 (after): PASSED')" "0yes" "4n the copy of V4 blind to :509's message takes that line for the old fatal and passes (caught: 4i'' fails it)"
# 4m — the pilot changed BETWEEN the deploy and an --after-only (the operator's card, set_config.php): the run compares
# only with itself, so it passes, and sets what it found against the deploy's record — a note naming what is not as the
# deploy found it, never a number (the deploy review's RC-7).
D4M="$(data_digest)"; cfg_keep; cfg_set sales_own_leads_only off; base_log; OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" 'note  the salesperson pilot is not as the deploy found it')$(has "$OUT" 'sales_own_leads_only: on/on → absent/absent')$(grep -cE -- "$LEAK_RX|$NUM_RX" <<<"$OUT")" "0yesyes0" \
  "4m own leads only cleared between the deploy and an --after-only: 0 failed, and a note names what is not as the deploy found it — no number"
cfg_back
check "$(data_digest)" "$D4M" "…own leads only put back in both copies"
T4J=$(( $(date -u +%s) - 40 ))
base_log; logline "$T4J" 'NOTICE: PHP message: PHP Fatal error:  Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes) in /data/ucrm/data/plugins/dishnet-hybrid-sudan/lib/Planted.php on line 77'
logline $(( T4J + 10 )) 'NOTICE: PHP message: PHP Parse error:  syntax error, unexpected token "}" in /data/ucrm/data/plugins/dishnet-hybrid-sudan/cron/planted.php on line 9'
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" "FAIL  V4 2 fatal line(s) of $P")$(has "$OUT" "$(utc "$T4J") lib/Planted.php:77")$(has "$OUT" "$(utc $(( T4J + 10 ))) cron/planted.php:9")$(has "$OUT" '(no file:line on the line)')" "1yesyesyesno" \
  "4j a fatal and a parse error as PHP writes them, 'in <file> on line <N>': FAIL, each named by file:line"
base_log
# 4k — a job file in the checkout that neither release holds (a later push to the development branch, or a file left by
# hand), the script run from the checkout as the operator runs it: the code copied for the comparisons is still each
# commit's own — git, not the shell, matches cron_*.php.
printf '<?php // planted by the rehearsal\n' > "$REPO/$P/cron_planted_rh.php"
OUT="$( cd "$REPO" && run --after-only )"
check "$(fails "$OUT")$(has "$OUT" '?nocode')" "0no" "4k a cron_*.php in the checkout that neither commit holds, run from the checkout: the code is still copied — 0 failed"
M="$(mutant globbed "$DEPLOY" '"$EXPECTED_PLUGIN_COMMIT" "${CHK_PARTS_A[@]}" "$PLUGIN/$AP_TEST" | tar' '"$EXPECTED_PLUGIN_COMMIT" $CHK_PARTS "$PLUGIN/$AP_TEST" | tar' '"$BASELINE_COMMIT" "${CHK_PARTS_A[@]}" | tar' '"$BASELINE_COMMIT" $CHK_PARTS | tar')"
base_log; OUT="$( cd "$REPO" && run --script "$M" --after-only )"; rm -f "$M"
check "$( [ "$(fails "$OUT")" -gt 0 ] && echo failed)$(has "$OUT" '?nocode')$(has "$OUT" '5.18.93 (after): ')$(has "$OUT" 'FAILED — send the log file')" "failedyesyesyes" "4k' the copy that lets the shell expand the parts against the checkout (the first draft) cannot copy the code then: it fails (caught)"
rm -f "$REPO/$P/cron_planted_rh.php"; base_log
M="$(mutant v4blind "$DEPLOY" "$G_V4_TIME" '; then')"
base_log; logline $(( COPY_T + 2400 )) "$OLDFATAL"; OUT="$(run --script "$M" --after-only)"; rm -f "$M"
check "$(fails "$OUT")$(has "$OUT" '5.18.93 (after): PASSED')" "0yes" "4e' the copy of V4 with no time bound takes the late line for the old one and passes (caught: 4e fails it)"
base_log
# A real open of the stray store after the copy: the installed plugin's own store opens it, and closes — R19 fails.
stray_keep; holder_start; holder_end
OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" "FAIL  R19 the stray folder changed at")$(grep -c '^STRAY_R19_FAILED=' "$STATE")" "1yes1" "4g an open of the stray store after the copy: R19 FAILS, and the failure is recorded"
sed -i '/^STRAY_R19_FAILED=/d' "$STATE"; stray_back
check "$(stray_snap)" "$STRAY0" "…the stray folder back exactly as seeded"

echo; echo "== 5. the checks have teeth =="
# 5a — the INSTALLED main.php put back to 5.18.92's (the auto-pull in it again), its time kept as the copy's: R21 fails —
# the installed test of the retired auto-pull runs the installed main.php — and R1 names main.php.
git -C "$REPO" show "$BASE:$P/$MAIN" > "$PD/$MAIN"; touch -d "@$COPY_T" "$PD/$MAIN"
base_log; OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R21 under this server')$(has "$OUT" "FAIL  R1 installed files that differ: $MAIN")$(has "$OUT" 'auto-pull       what the test printed:')" "yesyesyes" \
  "5a an installed main.php put back to 5.18.92's: R21 and R1 fail — R1 naming main.php, R21 showing what the test printed"
git -C "$REPO" show "$PIN:$P/$MAIN" > "$PD/$MAIN"; touch -d "@$COPY_T" "$PD/$MAIN"
base_log; OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" 'ok    R21 under this server')" "0yes" "…put back as the pin has it: R21 passes again"
# 5a' — the INSTALLED master.php put back to the jobs' lock (5.18.92's own 5a): R20 fails, and R6 — master.php is
# 5.18.92's surface now, not this release's — while R1, which reads only this release's files, does not.
MT_MASTER="$(stat -c %Y "$PD/$MASTER")"
edit_installed "$MASTER" "if (is_resource(\$_m_lockFp)) {
    flock(\$_m_lockFp, LOCK_UN);
    fclose(\$_m_lockFp);
}" "flock(\$lockFp, LOCK_UN);
fclose(\$lockFp);"
base_log; OUT="$(run --after-only)"
check "$(has "$OUT" 'FAIL  R20 under this server')$(has "$OUT" 'FAIL  R6 incomplete:')$(has "$OUT" "master:not-5.18.92's")$(has "$OUT" 'FAIL  R1 ')" "yesyesyesno" \
  "5a' an installed master.php put back to the jobs' lock: R20 and R6 fail — R6 naming master.php not 5.18.92's — and R1 does not"
git -C "$REPO" show "$PIN:$P/$MASTER" > "$PD/$MASTER"; touch -d "@$MT_MASTER" "$PD/$MASTER"
base_log; OUT="$(run --after-only)"
check "$(fails "$OUT")$(has "$OUT" 'ok    R20 under this server')$(sha256sum "$PD/$MASTER" | cut -c1-64)" "0yes$MASTER92_SHA" "…put back as the pin has it — 5.18.92's byte for byte: R20 passes again"

# 5b–5e — the pilot changed DURING a run, after stage A read it (on the stand-in's first request, at V1): a switch cleared
# in both copies — so its two copies still agree, and only the comparison with stage A can see it; a 087 row changed with no
# trail row; a trail row added with no row changed. Each fails its check; a copy of the script blind to that comparison
# passes it (caught). The database and both copies of the configuration are put back after each.
D5="$(data_digest)"
OUT="$(pilot_trial .flip_switch multi_number_channels_enabled)"
check "$(failline "$OUT" V3f 'CHANGED' 'mn=on/on' 'mn=absent/absent')$(has "$OUT" 'trigger=spent')$(has "$OUT" '(after): PASSED')" "yesyesno" \
  "5b the registry's switch cleared in both copies during the run, after A read it: V3f FAILS — before mn=on/on, after mn=absent/absent"
check "$(failline "$OUT" R2c 'changed-since-stage-A')$(failline "$OUT" R11 'changed-since')$(failline "$OUT" R12 'changed-since-stage-A')" "yesyesyes" \
  "5b …and R2c, R11 and R12 FAIL on it too: the policy, the registry and the routing not as stage A read them"
M="$(mutant v3fblind "$DEPLOY" "$G_V3F" 'elif false; then bad "V3f')"
OUT="$(pilot_trial .flip_switch multi_number_channels_enabled --script "$M")"; rm -f "$M"
check "$(okline "$OUT" V3f 'mn=absent/absent')$(has "$OUT" 'trigger=spent')" "yesyes" "5b' the copy of V3f that does not compare with stage A says ok over the cleared switch (caught: 5b fails it)"
OUT="$(pilot_trial .flip_switch sales_own_leads_only)"
check "$(failline "$OUT" V3h 'ol:on/on→absent/absent')$(has "$OUT" 'trigger=spent')$(has "$OUT" '(after): PASSED')" "yesyesno" \
  "5c own leads only cleared in both copies during the run: V3h FAILS, naming ol:on/on→absent/absent"
check "$(failline "$OUT" R2b 'changed-since')" "yes" "5c …and R2b FAILS on it: 5.18.89's rules not as stage A read them"
M="$(mutant v3hblind "$DEPLOY" "$G_V3H" 'true || V3H_BAD=')"
OUT="$(pilot_trial .flip_switch sales_own_leads_only --script "$M")"; rm -f "$M"
check "$(okline "$OUT" V3h 'ol=absent/absent')$(has "$OUT" 'trigger=spent')" "yesyes" "5c' the copy of V3h that does not compare with stage A says ok over it (caught: 5c fails it)"
OUT="$(pilot_trial .chg_087 '')"
check "$(failline "$OUT" R3 '087')$(has "$OUT" 'trigger=spent')$(has "$OUT" '(after): PASSED')" "yesyesno" \
  "5d sales-001's assistant switched off in its row during the run, with no trail row: R3 FAILS — 087's rows are not as stage A read them"
check "$(failline "$OUT" V3i 'CHANGED' 'oai=1' 'oai=0')$(failline "$OUT" R11 'wa_channels')$(failline "$OUT" R12 'changed-since-stage-A')" "yesyesyes" \
  "5d …and V3i (one assistant fewer in its counts), R11 and R12 FAIL on it"
M="$(mutant r3rowsblind "$DEPLOY" "$G_R3_ROWS" 'true || bad87=')"
OUT="$(pilot_trial .chg_087 '' --script "$M")"; rm -f "$M"
check "$(okline "$OUT" R3 'migration 087')$(has "$OUT" 'trigger=spent')" "yesyes" "5d' the copy of R3 that does not compare the rows says ok over the changed row (caught: 5d fails it)"
OUT="$(pilot_trial .add_trail '')"
check "$(failline "$OUT" R3 '087')$(has "$OUT" 'trigger=spent')$(has "$OUT" '(after): PASSED')" "yesyesno" \
  "5e a trail row added during the run, no row changed: R3 FAILS — the run adds none"
check "$(failline "$OUT" V3i 'CHANGED' 'rows=4:12' 'rows=4:13')$(failline "$OUT" R11 'wa_channels')" "yesyes" "5e …and V3i (the trail one row longer) and R11 FAIL on it"
M="$(mutant r3trailblind "$DEPLOY" "$G_R3_TRAIL" 'true || bad87=')"
OUT="$(pilot_trial .add_trail '' --script "$M")"; rm -f "$M"
check "$(okline "$OUT" R3 'migration 087')$(has "$OUT" 'trigger=spent')" "yesyes" "5e' the same copy — R3 compares the rows and the trail as one — says ok over the added row too (caught: 5e fails it)"
# 5f — a column no count shows (sales-001's display name, one space longer) changed during the run: only the comparison of
# every column under the run's key sees it — V3i, R3 and R11 FAIL; a copy whose key covers the counts alone says ok (the
# deploy review's RC-1).
OUT="$(pilot_trial .chg_col '')"
check "$(failline "$OUT" V3i 'no count shows')$(failline "$OUT" R3 '087')$(failline "$OUT" R11 'wa_channels')$(has "$OUT" 'trigger=spent')$(has "$OUT" '(after): PASSED')" "yesyesyesyesno" \
  "5f sales-001's display name changed during the run, no count with it: V3i, R3 and R11 FAIL — every column compared under the run's key"
M="$(mutant sigcounts "$DEPLOY" '$h("SELECT * FROM wa_channels ORDER BY channel_id") . "/" . $h("SELECT * FROM wa_channel_log ORDER BY id")' '$h("SELECT COUNT(*) FROM wa_channels") . "/" . $h("SELECT COUNT(*) FROM wa_channel_log")')"
OUT="$(pilot_trial .chg_col '' --script "$M")"; rm -f "$M"
check "$(okline "$OUT" V3i 'unchanged')$(okline "$OUT" R3 'migration 087')$(has "$OUT" 'trigger=spent')" "yesyesyes" \
  "5f' the copy whose key covers only the counts says ok over the changed column at V3i and R3 (caught: 5f fails it)"
check "$(data_digest)$(ls -A "$SB/web" | grep -cE '^\.(flip_switch|chg_087|add_trail|chg_col)$')" "${D5}0" "…the pilot put back after each, exactly as before 5b, and no trigger left"

echo; echo "== 6. the rollback: --rollback, typed ROLLBACK =="
D2="$(data_digest)"; fresh; touch "$SB/web/.plant_old"; OUT="$(run --answer ROLLBACK --rollback)"
check "$(fails "$OUT")$(has "$OUT" '5.18.93 (rollback): PASSED')" "0yes" "PASSED"
for l in "note  after the rollback the code is 5.18.92's again: main.php's nightly auto-pull is back" \
         "ok    backed up the installed plugin (5.18.93, $PIN)" \
         "checking out $BASE (5.18.92) for the documented deploy" "ok    container serves $BASE (5.18.92)" \
         "ok    V4 no new fatal" "note  V4 1 line(s) of 5.18.92's own fatal at main.php:484 or :509" \
         "ok    RB the installed manifest says 5.18.92" \
         "ok    RB the installed plugin is 5.18.92's again: $MAIN is 5.18.92's byte for byte" \
         "ok    RB the retry job is 5.18.92's, as before the deploy, on throwaway layouts: on Uganda it stops before <plugin>/data ($SLR_STOPS | locks=kept)" \
         "note  RB with 5.18.92 back, main.php's nightly auto-pull is back" \
         "ok    RB the event processor is 5.18.92's" \
         "ok    RB the assistant's prompt is 5.18.92's" \
         "note  RB the code is 5.18.92 again. The rollback restores code only. The file 5.18.93 added stays on disk, reached by nothing"; do
  check "$(has "$OUT" "$l")" "yes" "6: ${l:0:96}"
done
check "$(live)$(installed_digest "$BASE")$(inst_ver)$(sha256sum "$PD/$MASTER" | cut -c1-64)$(sha256sum "$PD/$JOB" | cut -c1-64)$(sha256sum "$PD/$MAIN" | cut -c1-64)" "${BASE}05.18.92$MASTER92_SHA$JOB91_SHA$MAIN92_SHA" \
  "the container serves $BASE: every changed file as 5.18.92 has it — main.php, master.php and the gated retry job byte for byte"
check "$( [ -f "$PD/$AP_TEST" ] && echo kept)$(has "$OUT" "reached by nothing (deploy-hybrid.sh never deletes): $AP_TEST")" "keptyes" \
  "…and the file 5.18.93 added, its test, stays on disk, reached by nothing — the rollback's log names it"
check "$(data_digest)$(stray_snap)$(host_code_left)$(left_tmp)" "${D2}${STRAY0}0$TMP0" "the rollback changed no data and not the stray store, and left no copy of the code anywhere"
check "$(okline "$OUT" V3f 'mn=on/on')$(okline "$OUT" V3h 'ol=on/on' 'fh=on/on' 'hc=absent/absent')$(okline "$OUT" RB '087' 'unchanged')$(okline "$OUT" RB 'policy=on/on')$(grep -cE -- "$STALE_RX|$LEAK_RX|$NUM_RX" <<<"$OUT")" "yesyesyesyes0" \
  "6 pilot: the rollback leaves the pilot as the operator left it — each switch, 087's rows and trail unchanged by it, the rolled-back policy reading on this configuration as before; its log says nothing of a dark registry and names no instance, person or number"
# 6c — either state: the pilot OFF, exactly as 5.18.92's deploy found it on 10 Oct, deployed, checked again and rolled back
# as the pilot on is; then the pilot put back ON as the operator left it, for 6b.
snap_save on6; D6ON="$(data_digest)"; snap_load off; D6="$(data_digest)"
check "$D6" "$D_OFF" "6c the pilot OFF, exactly as kept before the 10 Oct steps"
fresh; OUT="$(run --answer DEPLOY)"
check "$(fails "$OUT")|$(live)|$(has "$OUT" '(deploy): PASSED')|$(okline "$OUT" A5 'mn=absent/absent')$(okline "$OUT" A5b 'ol=absent/absent' 'fh=absent/absent')$(okline "$OUT" A9 "$SHAPE_OFF")$(okline "$OUT" A11 'policy=off/off')|$(has "$OUT" ' dnum=0 other=1 active=0 oai=0 ')" \
  "0|$PIN|yes|yesyesyesyes|yes" \
  "6c the pilot OFF: the deploy PASSES — the switches absent in both copies, 087's rows as found, the routing and the policy the pin's and the live code's alike"
check "$(okline "$OUT" V3f 'mn=absent/absent')$(okline "$OUT" V3h 'ol=absent/absent' 'fh=absent/absent')$(okline "$OUT" R2b 'files=off,free,shown store=off,free,shown')$(okline "$OUT" R2c 'policy=off/off')$(okline "$OUT" R3 'migration 087' 'unchanged')$(okline "$OUT" R11 'enabled=off/off')$(okline "$OUT" R12 "$SHAPE_OFF")" "yesyesyesyesyesyesyes" \
  "6c …V and R accept it as they accept the pilot on: each switch and 087 unchanged by the run, the registry not in effect, the routing and the policy as before the copy"
check "$(data_digest)$(grep -cE -- "$LEAK_RX|$NUM_RX" <<<"$OUT")" "${D6}0" "6c …no data written, and no instance, person or number in the log"
base_log; OUT="$(run --after-only)"
check "$(fails "$OUT")$(okline "$OUT" V3f 'mn=absent/absent')$(okline "$OUT" R3 'migration 087' 'unchanged')" "0yesyes" "6c --after-only with the pilot OFF: 0 failed"
fresh; OUT="$(run --answer ROLLBACK --rollback)"
check "$(fails "$OUT")|$(live)|$(has "$OUT" '(rollback): PASSED')|$(okline "$OUT" V3f 'mn=absent/absent')$(okline "$OUT" RB '087' 'unchanged')$(okline "$OUT" RB 'policy=off/off')|$(data_digest)" "0|$BASE|yes|yesyesyes|$D6" \
  "6c the rollback with the pilot OFF PASSES — the switches, 087 and the data as they were"
snap_load on6
check "$(data_digest)$(live)" "$D6ON$BASE" "…the pilot back ON, exactly as the operator left it, the base serving again: 6b deploys over it"
fresh; touch "$SB/web/.plant_old509"; OUT="$(run --answer DEPLOY)"
check "$(fails "$OUT")$(has "$OUT" '5.18.93 (deploy): PASSED')$(has "$OUT" 'ok    A14 the stray store is exactly as 5.18.92')$(has "$OUT" 'ok    R20 under this server')$(has "$OUT" 'ok    R21 under this server')" "0yesyesyesyes" \
  "6b deployed again over the rollback: PASSED, A14 still carried from 5.18.92's record, R20 and R21 again"
check "$(has "$OUT" 'note  V4 1 line(s) of the old fatal at main.php:484 or :509')$(grep -A7 -E '^  note  V4' <<<"$OUT" | grep -cE '^ +20[0-9-]+T[0-9:]+Z main\.php:509$')$(ls -A "$SB/web" | grep -c '^\.plant_old509$')" "yes10" \
  "6b …5.18.92's fatal at main.php:509, planted just after its copy, is the old fatal: a note, named by time and file:line — the trigger spent"

# 6d — the pilot changed DURING a rollback (sales-001's assistant switched off in its row, no trail row): RB FAILS — it
# compares 087's rows with what its own stage A read; a copy blind to that comparison says ok (caught), V3i still sees it.
# The database is put back after each; 5.18.92 is left serving (the deploy review's RC-3).
db_keep; M="$(mutant rb087blind "$DEPLOY" '[ "$WA87_RB" = "${WA87_BEFORE:-}" ] || RB87_BAD=' 'true || RB87_BAD=')"
fresh; touch "$SB/web/.chg_087"; OUT="$(run --script "$M" --answer ROLLBACK --rollback)"; rm -f "$M"
check "$(okline "$OUT" RB '087' 'unchanged')$(failline "$OUT" V3i 'CHANGED')$(live)$(ls -A "$SB/web" | grep -c '^\.chg_087$')" "yesyes${BASE}0" \
  "6d' the copy of RB that does not compare 087's rows says ok over a row changed during the rollback (caught: 6d fails it); V3i still sees it"
db_back; fresh; OUT="$(run --answer DEPLOY)"
check "$(fails "$OUT")$(has "$OUT" '5.18.93 (deploy): PASSED')$(live)" "0yes$PIN" "…deployed again over it, the pilot as the operator left it"
db_keep; fresh; touch "$SB/web/.chg_087"; OUT="$(run --answer ROLLBACK --rollback)"
check "$(failline "$OUT" RB '087:rows-or-trail-not-as-before-the-rollback')$(has "$OUT" '(rollback): PASSED')$(live)$(ls -A "$SB/web" | grep -c '^\.chg_087$')" "yesno${BASE}0" \
  "6d sales-001's assistant switched off in its row during a rollback: RB FAILS — 087's rows are not as its stage A read them; the code is 5.18.92's"
db_back
check "$(sq "SELECT ai_enabled FROM wa_channels WHERE channel_id = 'sales-001'")" "1" "…the row put back: sales-001's assistant on, as the operator left it"

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
check "$( [ -f "$SB/bad-runs" ] && tr '\n' ' ' < "$SB/bad-runs" )" "" "every run of the script took the options it was given — none answered 'unknown option', so no check read a refused run as a pass"
OUT="$(run --after-only --rehearsal-unknown-option)"
check "$(has "$OUT" 'unknown option: --rehearsal-unknown-option')$( [ -s "$SB/bad-runs" ] && echo recorded)" "yesrecorded" \
  "control on the control: a run given an option the script does not know is recorded as such"
rm -f "$SB/bad-runs"
check "$(left_tmp)" "$TMP0" "no copy of the code, no throwaway layout, no lock-test folder and no auto-pull test folder is left in /tmp ($TMP0 before the rehearsal)"
check "$(grep -c 'Fatal\|Parse error' "$SB/web.log")" "0" "the stand-in served every request without a PHP fatal"

echo; echo "rehearsal: $PASS passed, $FAILN failed ($(cat "$SB/runs") runs of the script)"
[ "$FAILN" = "0" ]
