#!/usr/bin/env bash
#
# Rehearse scripts/deploy-5.18.96.sh — P3b: cash in hand under D2 = A, X5 and D1 on the Cashbook (root docs/69 Part 5,
# docs/07, 17 Oct) — over 5.18.95, before the operator runs it.
#
# The sandbox is 5.18.95's rehearsal's, built by its own code: scripts/harness/deploy-5.18.95/rehearse.sh, read up to just
# before its rollback section (the cut asserted: its anchor exactly once) and run here as it is — which builds 5.18.94's
# sandbox and the quarantine's the same way, from their own rehearsals. By the time this file's own sections begin, the
# REAL 5.18.94 deploy, its --after-only and the quarantine's --check have run; then the REAL 5.18.95 deploy (DEPLOY typed),
# its key file's command and its --after-only, each run as its summary printed it, and the quarantine's --check after it:
# 5.18.95 live, as production will be when the operator runs this release.
#
# On it: a synthetic book — P0's own builder (scripts/harness/bank-recon-p0/build-book.php, variant ug, the settings line
# alone taken out: the sandbox's settings are Uganda's already) through the installed plugin's writers, and every class of
# row T1 names; a webhook secret planted in the live data folder and a credential in the live vault; a stand-in that posts
# a receipt while a run goes on. Then this release's script, through a pseudo-terminal, its word typed, as the operator
# runs it, and every command its summary prints for a later step — the --after-only, the rollback — run as printed.
#
# Three observers watch every run of the 5.18.96 script, switched on by the run's environment, never by the script: the
# fake docker records every call it receives (so that each PHP process run on a copy can be shown to carry a vault of its
# own, and none to run the tool or T1 from the plugin folder); at every call it searches the run's cash folders, synthetic
# books and P3b code copies in /tmp (T1's and T4's run folders among them) for the planted webhook secret, the planted
# vault credential and any vault written at a default path; and the live vault's
# sha256 and time are read before and after every run.
#
# This rehearsal proves:
#   · the script is pinned to the release commit on release/5.18.96, cut on 098f6b3 (5.18.95); the delta is exactly the
#     reviewed set — five tests added, six files byte for byte the development branch's change (c4f03dca), the manifest
#     and five pins 5.18.95's with the version alone changed; 5.18.95's security files, main.php, master.php, the retry job,
#     the documents and the vault's code 5.18.95's byte for byte; T1 pinned; P0's tool and R0's hash as reviewed; the
#     header's deploy command alone with its own log wrapper; every later command defined with its wrapper; nothing in the
#     script writes the live vault or reads the cash sheet out (0);
#   · A14 reads 5.18.95's and 5.18.94's state files: a failure recorded in either — or in one kept beside it — refuses until
#     a --check that began after it has PASSED; a time that cannot be read refuses; a copy blind to either file reaches the
#     question (caught); 5.18.95's copy after the newest passing --check refuses (rule 3; a copy blind to it is caught); no
#     A20, and a copy with 5.18.94's A20 kept refuses on 17 October (caught) (1);
#   · A22L refuses a live 5.18.95 that lost one of its fixes, naming the defect and never the old key; a part-way copy of
#     5.18.96 refuses and prints the by-hand put-back alone, with its own log (1b);
#   · six faulty pins — one that counts bank rows, one that counts pending rows, one that counts orphans, one whose
#     South Sudan books move (the tenant half of the gate taken out), one whose Balance column keeps the old rule, one whose
#     gate fails — each refused at A22-T1 by the pin's own T1; and each, with A22-T1's refusal taken out of a copy, refused
#     again by the check written for it: A23.2 three times, A24, A23.2's Balance column, A23.7 (2);
#   · the copies are the evidence: a row written to the LIVE book while A23 reads (a trap in the fake docker) moves nothing
#     A23 reads, and a copy that reads the live store is refused at A23.1 (caught), and puts the live data folder's webhook
#     secret into /tmp, where the observer finds it; a copy with no DN_VAULT_FILE on a code copy is caught by the observers;
#     a judge whose classes are wrong is refused by the parts check, and a copy without the parts check reaches the question
#     (caught); a copy without the rule-in-force check, on the gate-fails pin, is refused only later and for a weaker reason
#     (caught); a copy given the synthetic adjustment's hash names it to P0 by its id, and P0 still agrees (2b);
#   · the deploy PASSES with a receipt posted by a stand-in between A23 and R23: every equality holds on each copy; the
#     drift is that one row, named on the sheet by its id; A23's and R23's figures on the sheet equal this rehearsal's own
#     SQL on the live book; the counts, the rows not approved and E17's scopes the script says equal this rehearsal's; the
#     state file names the sheet (its sha256-16 as A23 left it), the row digest this rehearsal computes and the live vault as
#     it was; the cash sheet 0600 in a 700 folder; no figure, no secret in the terminal, the log or any other file; the live
#     vault untouched; every later command printed alone with its own log wrapper, the rollback once and after the
#     verdict (3);
#   · --after-only, run as printed: PASSED, R19 conclusive, R23 on a new copy with the drift against the deploy's digest,
#     Z1 against the deploy's reading, its own sheet and its own 0600 log; then weakened copies, each caught: one printing
#     the --after-only without its wrapper; one whose R23 drops the approved filter (R23.1 FAILS); one with a figure in an
#     ok line and one printing the sheet to the terminal (the figure sweep); one running the installed tool from the
#     plugin folder with no vault of its own (the live webhook secret restored into /tmp, seen by the observers); one whose
#     R24 is blind to an edited CEO page; the quarantine's --check PASSES (4);
#   · the rollback, run as printed, ROLLBACK typed: PASSED — 5.18.95's files by sha256, no rule A in the CEO page or the
#     library, 5.18.95's fixes in place, today's rule on a copy (its own sheet, equal to this rehearsal's SQL), its own 0600
#     log, the deploy's state file untouched; deployed again; a rollback whose copy leaves 5.18.96's library behind FAILS RB
#     three times, and a copy blind to the definition passes the cash check over it (caught); deployed again (5);
#   · nothing reaches Evolution; no run left a copy of the book, the code, a vault or a webhook secret in /tmp; every
#     observer quiet on every run but the weakened copies that trip it on purpose; no figure, secret or planted value in
#     any output, log, state or digest file (the sweep with its control); the clone, /tmp and the checkout as found (6).
#
# Everything runs in a sandbox; it never touches this checkout, the server or the network, and refuses to run where the
# server could be. Everything seeded is fictitious — every amount invented here, every name RH96 or RH-MARK.
#
#   bash scripts/harness/deploy-5.18.96/rehearse.sh            REHEARSE_KEEP=<dir> keeps every run's full output
set -u
for f in /data/ucrm /opt/dishnet /var/run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
H96_DIR="$(cd "$(dirname "$0")" && pwd)"
H96_ROOT="$(cd "$H96_DIR/../../.." && pwd)"
F96=scripts/deploy-5.18.96.sh
# Before anything is built: the script must be pinned — to its release commit, and to T1's count, which only the release
# suite's own run of that commit gives. A script that still carries either placeholder refuses to run, and so would every
# run here.
P96_EARLY="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT="\([^"]*\)".*/\1/p' "$H96_ROOT/$F96" 2>/dev/null)"
T1_EARLY="$(sed -n 's/^T1_SIGNATURE="\([^"]*\)".*/\1/p' "$H96_ROOT/$F96" 2>/dev/null)"
case "$P96_EARLY" in __*|'') echo "refusing: $F96 is not pinned to a release commit (${P96_EARLY:-none})"; exit 2;; esac
case "$T1_EARLY" in [1-9]*' passed, 0 failed rc=0') ;; *) echo "refusing: $F96 does not pin T1's count yet (T1_SIGNATURE=${T1_EARLY:-none}) — the release suite's own run of $P96_EARLY gives it; pin it, then rehearse"; exit 2;; esac
H95_HARNESS="$H96_DIR/../deploy-5.18.95/rehearse.sh"
H96_PREFIX="$(mktemp)"
python3 - "$H95_HARNESS" "$H96_PREFIX" <<'PY' || { echo "refusing: 5.18.95's rehearsal could not be cut after its --after-only and the quarantine's --check"; rm -f "$H96_PREFIX"; exit 2; }
import sys
src, dst = sys.argv[1], sys.argv[2]
anchor = 'echo; echo "== 95-6. the rollback, run exactly as the deploy\'s summary printed it — ROLLBACK typed — and deployed again =="'
lines = open(src, encoding='utf-8').read().split('\n')
at = [i for i, l in enumerate(lines) if l == anchor]
assert len(at) == 1, len(at)
assert at[0] > 700, at[0]                   # 5.18.95's deploy, its key file, its --after-only and the quarantine's --check come before it
pre = '\n'.join(lines[:at[0]]) + '\n'
for need in ('H94_ANCHOR=', 'run95() {', 'fresh95() {', 'run_line() {', 'printed() {', 'nogo95() {', 'ctl95() {', 'declare -A CTL95=(',
             'OUT95="$SB/out95"', 'STATE95="$OUT95/state-5.18.95.env"', 'copy_back95() {', 'SEC95="$SB/secrets95"', 'OLDKEY95="DISHNET_REPAIR"',
             'A20_94="$(awk', 'echo; echo "== 95-4. the deploy, as the operator runs it',
             'echo; echo "== 95-5. --after-only, run exactly as the deploy\'s summary printed it =="',
             "5g the quarantine's own --check, as the runbook orders after this: PASSED with 5.18.95 live"):
    assert pre.count(need) >= 1, need
last = [l for l in pre.split('\n') if l.strip()][-1]
assert last == 'QREC95="$(sha256sum "$QREC" | cut -c1-16)"; KEPT95="$(kept_state)"', last[:80]   # the cut follows 5g's own record
open(dst, 'w', encoding='utf-8').write(pre)
PY
# shellcheck disable=SC1090
. "$H96_PREFIX"
rm -f "$H96_PREFIX"

# ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
# 5.18.96's own rehearsal, on the sandbox built above: 5.18.95 (098f6b3) live, its --after-only conclusive, the
# quarantine's --check passed after it.
# ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
REL96=release/5.18.96; DEPLOY96="$REPO/$F96"
FIX96=c4f03dca                                      # the change on the development branch (its tip, holding P3b's three commits)
DEV96_BASE=423ff052                                 # …and the development branch before it: 5.18.95's script and rehearsal
ADDED96="tests/test_cash_in_hand_p3b.php tests/test_cash_in_hand_p3b_mutants.php tests/test_cash_in_hand_p3b_screens.php tests/test_cash_in_hand_p3b_south_sudan.php tests/test_d1_bank_balance_readers.php"
TAKEN96="lib/CashbookService.php tabs/accounts/cashbook.php tabs/accounts/accounts_dashboard.php tabs/admin/ceo_dashboard.php cron/cashbook_summary.php tools/cash_in_hand.php"
PINS96="tests/test_distributor_apply.php tests/test_distributor_link_ucrm.php tests/test_distributor_notify.php tests/test_distributor_registry.php tests/test_distributor_territory.php"
N96=$(( $(wc -w <<<"$ADDED96 $TAKEN96 $PINS96") + 1 ))   # …and the manifest: 17
KEPT_FILES96="main.php cron/master.php cron_starlink_block_retry.php lib/StarlinkRetryScope.php tests/test_master_lock_release.php tests/test_main_auto_pull_retired.php migrations/087_wa_channels.sql lib/LegalContent.php lib/InstallationTerms.php profiles/uganda.json tests/test_legal_overrides.php lib/PdfLinkToken.php public.php includes/routes.php efris_pdf.php tabs/admin/efris.php cron_splynx_sync.php tabs/help/runbook.php tabs/admin/starlink_suspensions.php EMERGENCY_RECOVERY.md lib/StaffJobsGate.php lib/currency.php lib/PluginConfig.php lib/ConfigVault.php lib/TenantProfile.php tests/fixtures/staff_jobs_sandbox.php tests/fixtures/staff_jobs_scenario.php"
if git -C "$R" ls-files --error-unmatch "$F96" >/dev/null 2>&1; then
  cmp -s "$R/$F96" "$DEPLOY96" || { echo "refusing: $F96 differs from the committed one — commit it, then rehearse"; exit 2; }
  WHICH96="the committed script"
else cp "$R/$F96" "$DEPLOY96"; WHICH96="the working copy (not yet committed; untracked in the clone)"; fi
git -C "$REPO" fetch -q origin "refs/heads/$REL96:refs/remotes/origin/$REL96" 2>/dev/null || true
P96="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT="\([^"]*\)".*/\1/p' "$DEPLOY96")"
T1SIG96="$(sed -n 's/^T1_SIGNATURE="\([^"]*\)".*/\1/p' "$DEPLOY96")"
T4SIG96="$(sed -n 's/^T4_SIGNATURE="\([^"]*\)".*/\1/p' "$DEPLOY96")"
OUT96="$SB/out96"; mkdir -p "$OUT96"; STATE96="$OUT96/state-5.18.96.env"
R96="$SB/r96"; mkdir -p "$R96/logs" "$R96/mutants" "$R96/sheets" "$R96/exec" "$R96/scan" "$R96/vault"   # every run's output and records: the sweeps' material (6)
: > "$R96/expected-trips"                            # the tags of the runs that trip an observer on purpose (weakened copies)
H96="$SB/rh96"; mkdir -p "$H96"                     # this rehearsal's own helpers (an oracle in PHP, readers in Python)
DATE_TS96='$(date -u +%Y%m%dT%H%M%SZ)'
LOGW96() { printf '2>&1 | ( umask 077; exec tee %s/%s-%s.log )' "$OUT96" "$1" "$DATE_TS96"; }   # the script's LOGW, as it prints it
EXP_AFTER96="cd $REPO && bash scripts/deploy-5.18.96.sh --after-only $(LOGW96 after)"
EXP_ROLLBACK96="cd $REPO && bash scripts/deploy-5.18.96.sh --rollback $(LOGW96 rollback)"
EXP_DEPLOY96="cd $REPO && bash scripts/deploy-5.18.96.sh $(LOGW96 deploy)"
EXP_PUTBACK96="( cd $REPO && git checkout $P95 && { bash scripts/deploy-hybrid.sh; git checkout -; } ) $(LOGW96 putback)"
VAULT_IN96="/data/ucrm/data/plugins/.dishnet-sudan.vault.json"   # where the live code keeps its vault, as the script asks it (the plugins folder, writable by its user)

left_tmp96() { ls -d /tmp/dnb-5.18.96-* /tmp/dn-master-lock-* /tmp/dn_autopull_* 2>/dev/null | wc -l | tr -d ' '; }
TMP96="$(left_tmp96)"
host_code96() { ls -d "$OUT96"/code-* "$OUT96"/p3b-* 2>/dev/null | wc -l | tr -d ' '; }
backups96() { ls -d "$OUT96"/backup-* 2>/dev/null | wc -l | tr -d ' '; }
fresh96() {   # the run folder as a new run finds it: the state files and A23's row digests kept (a later --after-only reads them);
              # every log a printed command wrote and every cash sheet moved aside for the sweeps (6)
  find "$OUT96" -mindepth 1 -maxdepth 1 -name '*.log' -exec mv -t "$R96/logs/" {} + 2>/dev/null
  find "$OUT96" -mindepth 1 -maxdepth 1 -name 'cash-*.txt' -exec mv -t "$R96/sheets/" {} + 2>/dev/null
  find "$OUT96" -mindepth 1 -maxdepth 1 ! -name 'state-5.18.96.env*' ! -name 'rollback-state-*' ! -name 'cih-rows-*' -exec rm -rf {} +; base_log; }
installed96() {   # installed96 COMMIT — the number of files 5.18.95 → 5.18.96 touches that are NOT installed as COMMIT has them
  local f rel bad=0
  for f in $(dl AM "$P95" "$P96"); do
    rel="${f#$P/}"
    if git -C "$REPO" cat-file -e "$1:$f" 2>/dev/null; then
      [ "$(git -C "$REPO" show "$1:$f" | sha256sum | cut -c1-64)" = "$(sha256sum "$PD/$rel" 2>/dev/null | cut -c1-64)" ] || bad=$((bad+1))
    fi
  done
  echo "$bad"
}
vt_host() { if [ -e "$VAULT" ]; then echo "$(sha256sum "$VAULT" | cut -c1-16):$(stat -c %Y "$VAULT")"; else echo absent; fi; }   # as the script's vt_now reads it
last96() { printf '%03d' "$(cat "$SB/runs")"; }   # the tag of the last run
run96() {   # [--answer WORD] [--script FILE] [--env K=V]… [--today YYYYMMDD], then the script's options — through a pseudo-terminal,
            # as run95, with this rehearsal's observers on: every docker call recorded (FAKE_EXEC_LOG), the run's folders in
            # /tmp searched at every call (FAKE_TMP_SCAN), the live vault read before and after. R96_MUTANT_OUT=1 keeps the
            # output out of the figure sweep (a weakened copy that prints a figure on purpose)
  local answer="" script="$DEPLOY96" extra=() today="$AFTER94" n tag o cmd
  while :; do case "${1:-}" in --answer) answer="$2"; shift 2 ;; --script) script="$2"; shift 2 ;; --env) extra+=("$2"); shift 2 ;;
    --today) today="$2"; shift 2 ;; *) break ;; esac; done
  : > "$SB/docker.log"
  n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"; tag="$(printf '%03d' "$n")"
  vt_host > "$R96/vault/run-$tag.before"; : > "$R96/exec/run-$tag.rec"; : > "$R96/scan/run-$tag.hits"
  cmd="$(printf '%q ' env PATH="$SB/datebin:$SB/bin:$PATH" FAKE_TODAY="$today" DNB_OUT="$OUT96" DNB_RECORDS="$RECS" DNB_WEBSITE="$WEB_OK" GUARD_SECONDS=3 DN_DATA_DIR="$DATA" \
         FAKE_EXEC_LOG="$R96/exec/run-$tag.rec" FAKE_TMP_SCAN="$SCAN96" FAKE_TMP_HITS="$R96/scan/run-$tag.hits" ${extra[@]+"${extra[@]}"} bash "$script" --plugin-base "$PLUGIN_BASE" "$@")"
  o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r')"
  vt_host > "$R96/vault/run-$tag.after"
  case "$o" in *"unknown option:"*) echo "run96-$n" >> "$SB/bad-runs" ;; esac
  if [ -n "${R96_MUTANT_OUT:-}" ]; then printf '%s\n' "$o" > "$R96/mutants/run-$tag.txt"; else printf '%s\n' "$o" > "$R96/run-$tag.txt"; fi
  [ -n "${REHEARSE_KEEP:-}" ] && printf '%s\n' "$o" > "$REHEARSE_KEEP/run96-$tag-${answer:-none}-$(basename "$script" .sh)$(printf '%s' "$*" | tr -c 'A-Za-z0-9-' '_').txt"
  printf '%s' "$o"
}
run_line96() {   # run_line96 WORD LINE — a command line exactly as a summary printed it, through a pseudo-terminal, WORD typed; only
                 # the sandbox around it (as run_line), and this rehearsal's observers
  local answer="$1" line="$2" n tag o cmd
  : > "$SB/docker.log"
  n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"; tag="$(printf '%03d' "$n")"
  vt_host > "$R96/vault/run-$tag.before"; : > "$R96/exec/run-$tag.rec"; : > "$R96/scan/run-$tag.hits"
  cmd="$(printf '%q ' env PATH="$SB/datebin:$SB/bin:$PATH" FAKE_TODAY="$AFTER94" DNB_OUT="$OUT96" DNB_RECORDS="$RECS" DNB_WEBSITE="$WEB_OK" GUARD_SECONDS=3 DN_DATA_DIR="$DATA" PLUGIN_BASE="$PLUGIN_BASE" \
         FAKE_EXEC_LOG="$R96/exec/run-$tag.rec" FAKE_TMP_SCAN="$SCAN96" FAKE_TMP_HITS="$R96/scan/run-$tag.hits" bash -c "$line")"
  o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r')"
  vt_host > "$R96/vault/run-$tag.after"
  case "$o" in *"unknown option:"*) echo "line96-$n" >> "$SB/bad-runs" ;; esac
  printf '%s\n' "$o" > "$R96/line-$tag.txt"
  [ -n "${REHEARSE_KEEP:-}" ] && printf '%s\n' "$o" > "$REHEARSE_KEEP/line96-$tag-${answer:-none}.txt"
  printf '%s' "$o"
}
went96() { echo "$(has "$1" 'GO — evidence recorded')$(has "$1" 'Type DEPLOY')"; }   # the run reached the question
quiet96() { echo "$(live)|$(backups96)|$(data94)|$(kept_state)|$(oldname)"; }      # 5.18.95 live, no backup, the data and the quarantine as they were
nogo96() {   # nogo96 LABEL EXPECTED-TEXT OUTPUT [DATA-DIGEST] — refused with that text and "Nothing was changed", never the question,
             # nothing deployed, backed up or changed
  check "$(has "$3" "$2")$(has "$3" 'Nothing was changed. Send the log file.')$(went96 "$3")|$(quiet96)" "yesyesnono|$P95|0|${4:-$D96}|$KEPT96|0" "$1"
}
trip96() { last96 >> "$R96/expected-trips"; }   # the last run trips an observer on purpose: left out of the observers' sweep (6)
audit96() { python3 "$H96/rh96.py" audit "$R96/exec/run-$1.rec"; }   # audit96 TAG — copies=<n> novault=<n> plugin=<n> adj=<id|->
hits96() { local n; n="$(grep -c . "$R96/scan/run-$1.hits" 2>/dev/null)"; echo "${n:-0}"; }   # hits96 TAG — what the /tmp observer found during that run
vault96() { echo "$(cat "$R96/vault/run-$1.before")>$(cat "$R96/vault/run-$1.after")"; }   # vault96 TAG — the live vault before>after the run
sheet_of() { sed -n 's/^  cash sheet        \(\/[^ ]*\) — [0-9]* lines, sha256 [0-9a-f]*$/\1/p' <<<"$1" | tail -1; }        # F's cash sheet line: the path
sheetsha_of() { sed -n 's/^  cash sheet        \/[^ ]* — [0-9]* lines, sha256 \([0-9a-f]*\)$/\1/p' <<<"$1" | tail -1; }   # …and its sha256-16
printed96() {   # printed96 OUTPUT — the summary's commands: how many | how many without their own log wrapper | how many lines of the
                # whole output hold two commands
  local s; s="$(sed -n '/^== F. Summary ==$/,$p' <<<"$1")"
  echo "$(grep -cE '^      (cd |\( )' <<<"$s")|$(grep -E '^      (cd |\( )' <<<"$s" | grep -vcE ' \| \( umask 077; exec tee | \| tee ')|$(grep -cE 'deploy-5\.18\.96\.sh.*(deploy-5\.18\.96\.sh|quarantine-stray-store\.sh)|quarantine-stray-store\.sh.*deploy-5\.18\.96\.sh' <<<"$1")"
}
rb_where96() {   # rb_where96 OUTPUT — each line naming the rollback command: before or after the run's verdict
  awk '/^  5\.18\.96 \((deploy|after)\): / { v = 1 } index($0, "deploy-5.18.96.sh --rollback") { print (v ? "after" : "before") }' <<<"$1" | paste -sd, -
}
hdr96() {   # hdr96 SCRIPT — the header's command block: the deploy command with its own log | anything that is not it | the later --after-only with its own log
  local h; h="$(sed -n '/^# Run as root/,/^# The rollback is a separate command/p' "$1")"
  echo "$(grep -cF 'bash scripts/deploy-5.18.96.sh 2>&1 | ( umask 077; exec tee /root/dnb-5.18.96/deploy-$(date -u +%Y%m%dT%H%M%SZ).log )' <<<"$h")$(grep -cE -- '--rollback|--put-back|set_config|git checkout [0-9a-f]{7}' <<<"$h")$(grep -cF 'bash scripts/deploy-5.18.96.sh --after-only 2>&1 | ( umask 077; exec tee /root/dnb-5.18.96/after-$(date -u +%Y%m%dT%H%M%SZ).log )' <<<"$h")"
}
cmds96() {   # cmds96 SCRIPT — the commands it prints for a later step, each defined with its own log wrapper (the --after-only, the
             # rollback, the deploy, the by-hand put-back; the quarantine's --check with its own tee) | the lines that print a
             # command (printf, echo) | how many of them name one of those | how many carry the wrapper themselves
  local f="$1"
  printf '%s%s%s%s%s|%s|%s|%s|%s\n' \
    "$(grep -cxF 'AFTER_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --after-only $(LOGW after)"' "$f")" \
    "$(grep -cxF 'ROLLBACK_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --rollback $(LOGW rollback)"' "$f")" \
    "$(grep -cxF 'DEPLOY_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh $(LOGW deploy)"' "$f")" \
    "$(grep -cxF 'PUTBACK_CMD="( cd $REPO && git checkout $BASELINE_COMMIT && { bash scripts/deploy-hybrid.sh; git checkout -; } ) $(LOGW putback)"' "$f")" \
    "$(grep -cF 'bash scripts/quarantine-stray-store.sh --check 2>&1 | tee $Q_DIR/check-\$(date -u +%Y%m%dT%H%M%SZ).log )"' "$f")" \
    "$(grep -cF "printf '\n      %s'" "$f")" "$(grep -cF 'echo "      $' "$f")" \
    "$(grep -cE "(printf '\\\\n      %s' \"\\\$|echo \"      \\\$)(AFTER_CMD|ROLLBACK_CMD|DEPLOY_CMD|QCHECK_CMD|PUTBACK_CMD)\"" "$f")" \
    "$(grep -F "printf '\n      %s'" "$f" | grep -cF '$(LOGW ')"
}
ctl96() {   # ctl96 OUTPUT A22|R23 — of 5.18.95's seven checks, how many name exactly CTL95's defects for 5.18.94's copy, the control
  local out="$1" n=0 x
  for x in a b c d e f g; do
    case "$2" in
      A22) grep -qxF -- "$(printf '  %-15s the pin: %s   ·   %s (%s, the control): %s' "fix A22$x" ok "$P94" 5.18.94 "${CTL95[$x]}")" <<<"$out" \
             && [ "$(okline "$out" "A22$x" "— in the pin, kept by 5.18.96; 5.18.94's copy, the control, fails the same check (${CTL95[$x]})")" = yes ] && n=$((n+1)) ;;
      R23) [ "$(okline "$out" "R23$x" 'installed, kept by 5.18.96:' "— 5.18.94's copy, the control, fails the same check (${CTL95[$x]})")" = yes ] && n=$((n+1)) ;;
    esac
  done
  echo "$n"
}
noteids96() { grep -E '^  note  ' <<<"$1" | awk '{print $2}' | LC_ALL=C sort -u | paste -sd' ' -; }   # the ids of a run's notes
copy_back96() {   # the 5.18.96 copy N minutes ago: lib/CashbookService.php's time (its stamp_copy), the recorded copy time, the start before it
  touch -d "-$1 minutes" "$PD/lib/CashbookService.php"; local t; t="$(stat -c %Y "$PD/lib/CashbookService.php")"
  sed -i -e "s/^COPY_AT=.*/COPY_AT=$t/" -e "s/^DEPLOYED_AT=.*/DEPLOYED_AT=$(date -u -d "@$(( t - 60 ))" +%Y-%m-%dT%H:%M:%SZ)/" "$STATE96"; }
release_variant96() {   # release_variant96 PATH OLD NEW — the release commit with one file edited (OLD exactly once), cut on 5.18.95
  local idx="$SB/variant96.idx" blob tree c
  GIT_INDEX_FILE="$idx" git -C "$REPO" read-tree "$P96"
  blob="$(git -C "$REPO" show "$P96:$P/$1" | python3 -c 'import sys
s = sys.stdin.read(); old, new = sys.argv[1], sys.argv[2]
assert s.count(old) == 1, "variant anchor not unique"
sys.stdout.write(s.replace(old, new))' "$2" "$3" | git -C "$REPO" hash-object -w --stdin)"
  [ "$blob" = "$(printf '' | git -C "$REPO" hash-object --stdin)" ] && echo "release_variant96:$1" >> "$SB/anchor-missing"
  GIT_INDEX_FILE="$idx" git -C "$REPO" update-index --cacheinfo "100644,$blob,$P/$1"
  tree="$(GIT_INDEX_FILE="$idx" git -C "$REPO" write-tree)"; rm -f "$idx"
  c="$(git -C "$REPO" -c user.name=rehearsal -c user.email=rehearsal@example.test commit-tree "$tree" -p "$(git -C "$REPO" rev-parse "$P95")" -m "rehearsal variant: $1")"
  git -C "$REPO" rev-parse --short "$c"
}
data96x() { data_dump | grep -v '^master_schedule:' | grep -v '^cb_ledger:' | sha256sum | cut -c1-16; }   # every table but the book and master's record
book96x() {   # the book's rows, every column, but the stand-in's receipt — the book as the deploy found it
  php -r '$p = new PDO("sqlite:" . $argv[1]); echo substr(hash("sha256", json_encode($p->query("SELECT * FROM cb_ledger WHERE description <> \x27RH96 receipt posted while the deploy ran\x27 ORDER BY id")->fetchAll(PDO::FETCH_NUM))), 0, 16);' "$DATA/plugin.sqlite3"; }
oracle96() { php "$H96/oracle.php" "$DATA/plugin.sqlite3"; }   # the rehearsal's own reading of the live book, read-only (JSON)
oj() { python3 "$H96/rh96.py" oj "$1" "$2"; }                    # oj ORACLE-FILE KEY — one value of an oracle reading
forms96() { python3 "$H96/rh96.py" forms "$SB/forms96.txt" "$SB/seed96.amounts" "$H96"/oracle-*.json "$R96"/sheets/cash-*.txt "$OUT96"/cash-*.txt >/dev/null 2>&1; }
figs96() { forms96; python3 "$H96/rh96.py" hits "$SB/forms96.txt" "$@"; }   # figs96 FILE… — how many of the files hold a figure of the book, in any printed form

# ── This rehearsal's helpers, written once: an oracle of its own in PHP (plain SQL, read-only, from the design — never the
# script's), and readers in Python: the docker record, the figure forms and their sweep, the sheet's cells, its A23 part ─
cat > "$H96/oracle.php" <<'PHP'
<?php
// 5.18.96's rehearsal: its own reading of the sandbox's book — read-only, plain SQL written from the design (SPEC §a.3,
// §a.4), no plugin code, never the script's. JSON: every currency × scope under today's rule and under D2 = A; the counts
// A23.6 names; the rows not approved in A's locations; E17's scopes; the row digest A23 records; the classes the book must
// hold; whether USD reads below zero.
declare(strict_types=1);
$p = new PDO('sqlite:' . $argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15]);
$p->exec('PRAGMA query_only = ON');   // read-only by the connection's own rule: the live store is in WAL mode, and a reader must not need to create its -shm
$one  = function (string $sql) use ($p) { return $p->query($sql)->fetchColumn(); };
$live = "status NOT IN ('voided','voided_reconcile')";
$inA  = "(COALESCE(account_id,0)=0 OR account_id IN (SELECT id FROM cb_accounts WHERE kind IN ('cash','momo')))";
$net  = "COALESCE(SUM(CASE WHEN direction='in' THEN amount ELSE -amount END),0)";
$cells = [];
foreach (['UGX', 'USD'] as $c) foreach (['', 'dishnet', '4g', 'bluecard'] as $s) {
    $cur = $c === 'UGX' ? "(currency='UGX' OR currency IS NULL OR currency='')" : 'currency=' . $p->quote($c);
    $prj = $s === '' ? '' : ' AND project=' . $p->quote($s);
    $t = round((float)$one("SELECT ROUND($net,2) FROM cb_ledger WHERE $live AND $cur$prj"), 2);
    $d = round((float)$one("SELECT ROUND($net,2) FROM cb_ledger WHERE $live AND status='approved' AND $inA AND $cur$prj"), 2);
    $cells["$c|$s"] = [$t, $d];
}
$kinds = "'bank','cash','momo','receivable','payable','inventory','asset','director','equity'";
$cnt = [
    'orphan'   => (int)$one("SELECT COUNT(*) FROM cb_ledger WHERE $live AND typeof(account_id) IN ('integer','real') AND account_id <> 0 AND account_id NOT IN (SELECT id FROM cb_accounts)"),
    'text'     => (int)$one("SELECT COUNT(*) FROM cb_ledger WHERE $live AND typeof(account_id) = 'text'"),
    'mismatch' => (int)$one("SELECT COUNT(*) FROM cb_ledger l JOIN cb_accounts a ON a.id = l.account_id WHERE l.status NOT IN ('voided','voided_reconcile') AND UPPER(CASE WHEN l.currency IS NULL OR l.currency = '' THEN 'UGX' ELSE l.currency END) <> UPPER(COALESCE(a.currency, ''))"),
    'kinds'    => (int)$one("SELECT COUNT(*) FROM cb_accounts WHERE kind NOT IN ($kinds)"),
    'kindrows' => (int)$one("SELECT COUNT(*) FROM cb_ledger WHERE $live AND account_id IN (SELECT id FROM cb_accounts WHERE kind NOT IN ($kinds))"),
    'blank'    => (int)$one("SELECT COUNT(*) FROM cb_ledger WHERE $live AND (currency IS NULL OR currency = '')"),
    'lower'    => (int)$one("SELECT COUNT(*) FROM cb_ledger WHERE $live AND currency IS NOT NULL AND currency <> '' AND currency <> UPPER(currency)"),
];
$bn = 0;
foreach (['', 'dishnet', '4g', 'bluecard'] as $s) {
    $r = $p->query("SELECT currency FROM cb_ledger WHERE $live AND status='approved' AND $inA AND (currency='UGX' OR currency IS NULL OR currency='')"
         . ($s === '' ? '' : ' AND project=' . $p->quote($s)) . ' ORDER BY date DESC, id DESC LIMIT 1')->fetchColumn();
    if ($r !== false && (string)$r === '') $bn++;
}
$ctx = hash_init('sha256'); $n = 0; $mx = 0;
foreach ($p->query('SELECT id, status, direction, amount, ssp_amount, currency, account_id, project FROM cb_ledger ORDER BY id')->fetchAll(PDO::FETCH_NUM) as $r) {
    hash_update($ctx, (int)$r[0] . ' ' . substr(hash('sha256', json_encode($r)), 0, 16) . "\n"); $n++; if ((int)$r[0] > $mx) $mx = (int)$r[0];
}
$acc = function (string $w): string { return "account_id IN (SELECT id FROM cb_accounts WHERE $w)"; };
$cls = [
    'unassigned' => "COALESCE(account_id,0)=0 AND typeof(account_id) <> 'text' AND status='approved'",
    'cash' => $acc("kind='cash' AND active=1"), 'momo' => $acc("kind='momo' AND active=1"), 'inactive-momo' => $acc("kind='momo' AND active=0"),
    'bank' => $acc("kind='bank'"), 'inventory' => $acc("kind='inventory'"), 'asset' => $acc("kind='asset'"), 'director' => $acc("kind='director'"),
    'equity' => $acc("kind='equity'"), 'receivable' => $acc("kind='receivable'"), 'payable' => $acc("kind='payable'"),
    'kind-Cash' => $acc("kind='Cash'"), 'kind-cash-space' => $acc("kind='cash '"),
    'usd-on-a-ugx-account' => "currency='USD' AND " . $acc("currency='UGX'"),
    'orphan' => "typeof(account_id) IN ('integer','real') AND account_id > 0 AND account_id NOT IN (SELECT id FROM cb_accounts)",
    'negative-id' => "typeof(account_id) IN ('integer','real') AND account_id < 0",
    'text-empty' => "typeof(account_id) = 'text' AND account_id = ''", 'text-word' => "typeof(account_id) = 'text' AND account_id <> ''",
    'pending' => "status='pending_approval'", 'rejected' => "status='rejected'", 'voided' => "status='voided'", 'voided-reconcile' => "status='voided_reconcile'",
    'status-Approved' => "status='Approved'", 'status-empty' => "status=''", 'status-other' => "status='on_hold'",
    'blank-currency' => "(currency IS NULL OR currency='')", 'lowercase' => "currency='ugx'", 'usd' => "currency='USD'",
    'adjustment-shaped' => "substr(date,1,10)='2026-09-12' AND direction='out' AND COALESCE(account_id,0)=0",
    'project-4g' => "project='4g'", 'project-bluecard' => "project='bluecard'",
];
$missing = [];
foreach ($cls as $k => $w) if ((int)$one("SELECT COUNT(*) FROM cb_ledger WHERE $w") < 1) $missing[] = $k;
echo json_encode([
    'cells' => $cells,
    'counts' => "orphan:{$cnt['orphan']},text:{$cnt['text']},mismatch:{$cnt['mismatch']},kinds:{$cnt['kinds']}/{$cnt['kindrows']},blank:{$cnt['blank']},lower:{$cnt['lower']}",
    'notappr' => (int)$one("SELECT COUNT(*) FROM cb_ledger WHERE $live AND status <> 'approved' AND $inA"),
    'blanknewer' => $bn,
    'digest' => "$n:$mx:" . hash_final($ctx),
    'rows' => $n,
    'missing' => $missing ? implode(',', $missing) : '-',
    'usdneg' => $cells['USD|'][1] < 0 ? 'yes' : 'no',
    'moves' => ($cells['UGX|'][0] !== $cells['UGX|'][1] && $cells['USD|'][0] !== $cells['USD|'][1]) ? 'yes' : 'no',
]), "\n";
PHP
cat > "$H96/rh96.py" <<'PY'
# 5.18.96's rehearsal: readers of what the runs leave — never a value printed, only counts, ids and verdicts.
import sys, re, json, hashlib
from decimal import Decimal, ROUND_HALF_UP, ROUND_DOWN
D = '—'
TOK = re.compile(r'(?<![0-9.,])-?((?:[0-9]{1,3}(?:,[0-9]{3})+)|[0-9]+)\.([0-9]{2})(?![0-9])')
def read(p):
    try: return open(p, encoding='utf-8', errors='replace').read()
    except OSError: return ''
def norm(x):
    s = '{:.2f}'.format(Decimal(str(x)).quantize(Decimal('0.01'), rounding=ROUND_HALF_UP))
    return '0.00' if s == '-0.00' else s
def forms_of(v):
    v = abs(v); f = set()
    if v < 100 or v == v.to_integral_value(): return f          # a figure with cents, of three digits or more: distinctive
    f.add('2:{:,.2f}'.format(v)); f.add('2:{:.2f}'.format(v))
    if v >= 100000:                                              # without its cents too, rounded and cut, once it has six digits:
        for r in (v.quantize(Decimal(1), rounding=ROUND_HALF_UP), v.quantize(Decimal(1), rounding=ROUND_DOWN)):
            f.add('0:{:,}'.format(int(r)))                       # grouped, as the pages print it,
            if v >= 1000000: f.add('0:{}'.format(int(r)))        # and ungrouped from seven digits (six could be a log name's HHMMSS)
    return f
def blocks(t):
    out = {}; cur = None; ctx = None
    for l in t.split('\n'):
        m = re.match(r'^== (A23|R23|RB) ' + D + ' ', l)
        if m: cur = m.group(1); out.setdefault(cur, {}); ctx = None; continue
        if cur is None: continue
        m = re.match(r'^  ([A-Z]{3})  (all projects|project (\S+))', l)
        if m: ctx = '%s|%s' % (m.group(1), '' if m.group(2).startswith('all projects') else m.group(3)); continue
        m = re.match(r"^     today's rule " + D + r" every entry not voided, any account\s+(-?[0-9][0-9,]*\.[0-9]{2})$", l)
        if m and ctx: out[cur].setdefault(ctx, {})['t'] = norm(m.group(1).replace(',', '')); continue
        m = re.match(r'^     D2 = A ' + D + r' approved; no account, or a cash or mobile-money account\s+(-?[0-9][0-9,]*\.[0-9]{2})$', l)
        if m and ctx: out[cur].setdefault(ctx, {})['d'] = norm(m.group(1).replace(',', ''))
    return out
cmd = sys.argv[1]
if cmd == 'audit':                       # the docker record of one run: PHP run on a copy, each with a vault of its own?
    try: data = open(sys.argv[2], 'rb').read()
    except OSError: data = b''
    root = re.compile(r'^/tmp/dnb-5\.18\.96-(?:cih-[^/]+/[^/]+/(?:t|d|p0root)/dishnet-hybrid-sudan|code-[^/]+/p3b/[^/]+/dishnet-hybrid-sudan)(?:/|$)')
    copies = novault = plugin = 0; adj = '-'
    for rec in data.split(b'\x1e'):
        a = [x.decode('utf-8', 'replace') for x in rec.split(b'\x1f')]
        if a and a[-1] == '': a = a[:-1]
        if not a or a[0] != 'exec': continue
        i, env = 1, {}
        while i < len(a) and a[i].startswith('-'):
            if a[i] in ('-u', '-e'):
                if a[i] == '-e' and i + 1 < len(a) and '=' in a[i + 1]:
                    k, v = a[i + 1].split('=', 1); env[k] = v
                i += 2
            else: i += 1
        c = a[i + 1:]
        if not c: continue
        if not (c[0] == 'php' or (c[0] == 'sh' and len(c) > 2 and re.search(r'(?:^|[\s;&|(])php\s', c[2]))): continue
        j = '\n'.join(c)
        tool = 'tools/cash_in_hand.php' in j; t1 = 'tests/test_cash_in_hand_p3b.php' in j
        if not ([x for x in c if root.match(x)] or tool or t1): continue
        copies += 1
        if not env.get('DN_VAULT_FILE', '').startswith('/tmp/dnb-5.18.96-'): novault += 1
        if (tool or t1) and any(x.startswith('/data/') and 'dishnet-hybrid-sudan' in x for x in c[1:]): plugin += 1
        for x in c:
            if x.startswith('--adjustment-id='): adj = x.split('=', 1)[1]
    print('copies=%d novault=%d plugin=%d adj=%s' % (copies, novault, plugin, adj))
elif cmd == 'forms':                     # every printed form of every figure the sheets and the oracle hold, and of the seeded amounts
    vals = set()
    for p in sys.argv[3:]:
        t = read(p)
        if p.endswith('.json'):
            for line in t.split('\n'):
                if not line.strip(): continue
                for k, (a, b) in json.loads(line).get('cells', {}).items():
                    for x in (a, b, a - b): vals.add(Decimal(norm(x)))
        elif p.endswith('.amounts'):
            for line in t.split('\n'):
                if line.strip(): vals.add(Decimal(line.strip()))
        else:
            for m in TOK.finditer(t): vals.add(Decimal(m.group(1).replace(',', '') + '.' + m.group(2)))
    f = set()
    for v in vals: f |= forms_of(v)
    with open(sys.argv[2], 'w') as w: w.write(''.join(x + '\n' for x in sorted(f)))
    sys.stdout.write('%d\n' % len(f))
elif cmd == 'hits':                      # how many files hold one of those forms — a count; the files' names to a side file
    two, zero = [], []
    for l in read(sys.argv[2]).split('\n'):
        if l.startswith('2:'): two.append(re.escape(l[2:]))
        elif l.startswith('0:'): zero.append(re.escape(l[2:]))
    rx = []
    if two: rx.append(re.compile(r'(?<![0-9.,])(?:' + '|'.join(sorted(two, key=len, reverse=True)) + r')(?![0-9])'))
    if zero: rx.append(re.compile(r'(?<![0-9A-Fa-f.,])(?:' + '|'.join(sorted(zero, key=len, reverse=True)) + r')(?![0-9A-Fa-f]|[.,][0-9])'))
    n = 0; where = []
    for p in sys.argv[3:]:
        t = read(p)
        if t and any(r.search(t) for r in rx): n += 1; where.append(p)
    with open(sys.argv[2] + '.where', 'a') as w:
        for p in where: w.write(p + '\n')
    print(n if rx else 'noforms')
elif cmd == 'sheet':                     # a block of a sheet against the oracle: cells equal / cells
    b = blocks(read(sys.argv[2])).get(sys.argv[4], {})
    cells = json.loads(read(sys.argv[3]))['cells']
    m = sum(1 for k, (t, d) in cells.items() if b.get(k, {}).get('t') == norm(t) and b.get(k, {}).get('d') == norm(d))
    print('%d/%d' % (m, len(cells)))
elif cmd == 'presha':                    # the sheet as A23 left it — everything before R23's block — its sha256, 16 hex
    t = open(sys.argv[2], 'rb').read()
    i = t.find(('\n== R23 ' + D + ' ').encode('utf-8'))
    print(hashlib.sha256(t[:i] if i >= 0 else t).hexdigest()[:16])
elif cmd == 'drift':                     # the drift R23 wrote on the sheet: added N: ids
    m = re.search(r'^    added   ([0-9]+): ?(.*)$', read(sys.argv[2]), re.M)
    print('%s:%s' % (m.group(1), m.group(2).strip()) if m else 'none')
elif cmd == 'oj':
    v = json.loads(read(sys.argv[2]))[sys.argv[3]]
    print(v if not isinstance(v, dict) else json.dumps(v, sort_keys=True))
PY
check "$(python3 -c 'import ast, sys; ast.parse(open(sys.argv[1]).read()); print("parses")' "$H96/rh96.py" 2>&1)$(php -l "$H96/oracle.php" 2>&1 | grep -c '^No syntax errors')" "parses1" \
  "control: this rehearsal's own oracle (PHP) and readers (Python) parse"

echo; echo "== 96-pre. the sandbox as production will be: 5.18.95 live; the book; the planted secret; the observers =="
check "$(live)|$(inst_ver)|$(grep -c '^STRAY_Q_FAILED=' "$STATE95")|$(sed -n 's/^COPY_AT=\([0-9][0-9]*\)$/\1/p' "$STATE95" | tail -1 | grep -c .)" "$P95|5.18.95|0|1" \
  "pre-a 5.18.95 ($P95) is live and its state file holds its copy's time and no failure — production's state once 5.18.95's runbook is done (the quarantine's --check after it, 95-5g, passed above)"
[ -e "$RECS/dnb-5.18.95" ] || ln -s "$OUT95" "$RECS/dnb-5.18.95"
check "$(readlink -f "$RECS/dnb-5.18.95")|$(readlink -f "$RECS/dnb-5.18.94")" "$(readlink -f "$OUT95")|$(readlink -f "$OUT94")" \
  "pre-b 5.18.95's and 5.18.94's records where 5.18.96's A14 looks for them (<records>/dnb-5.18.95, <records>/dnb-5.18.94), as on the server"

# ── The stand-in public.php learns two more triggers, each spent once: a receipt posted through the INSTALLED code's own
# writer while a run goes on (.add_receipt) — at the first request, which in a deploy is V's, after A23 and before R23 (3);
# and 5.18.96's library left behind by a rollback's copy (.left96, naming the file to copy) — at the first request once
# 5.18.95's manifest is installed again (5). Asserted, as every edit here. ─────────────────────────────────────────────
python3 - "$SB/web/public.php" <<'PY' || echo "standin:receipt96" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]; s = open(p).read()
old = "$page = $_GET['page'] ?? '';\n"
assert s.count(old) == 1, s.count(old)
new = """if (($_GET['page'] ?? '') === 'rh_receipt_probe') { echo 'receipt-trigger-ready'; exit; }
if ($spend('.add_receipt')) {   // 5.18.96's rehearsal: a receipt posted through the INSTALLED code's own writer while a run goes on (R23's drift)
    putenv('DN_VAULT_FILE=' . $web . '/.rh96-receipt-vault.json');   // never the live vault
    require_once $root . '/lib/CashbookService.php';
    (new CashbookService($store, $dataDir))->addEntryRaw(['project' => 'dishnet', 'date' => gmdate('Y-m-d'), 'direction' => 'in', 'amount' => 618033.99,
        'currency' => 'UGX', 'category' => 'Receipt', 'category_raw' => 'Receipt', 'description' => 'RH96 receipt posted while the deploy ran',
        'status' => 'approved', 'approved_by' => 'RH96 Approver', 'source' => 'manual', 'account_id' => 0]);
    putenv('DN_VAULT_FILE');   // the server process serves every later request: the variable goes again
}
$left96 = is_file($web . '/.left96') ? trim((string)file_get_contents($web . '/.left96')) : '';
if ($left96 !== '' && strpos((string)@file_get_contents($root . '/manifest.json'), '"5.18.95"') !== false && $spend('.left96')) {
    copy($left96, $root . '/lib/CashbookService.php');   // 5.18.96's rehearsal: 5.18.96's library left behind by a rollback's copy (RB)
}
""" + old
s = s.replace(old, new, 1)
assert s.count("'.add_receipt'") == 1 and s.count("'.left96'") == 1 and s.count('rh_receipt_probe') == 1 and s.count(old) == 1
open(p, 'w').write(s)
PY
for i in $(seq 1 60); do pubget page=rh_receipt_probe | grep -q 'receipt-trigger-ready' && break; sleep 0.1; done   # PHP re-reads the edited file
check "$(pubget page=rh_receipt_probe)$(ls -A "$SB/web" | grep -c '^\.add_receipt$\|^\.left96$')" "receipt-trigger-ready0" \
  "control: the stand-in carries the two triggers, unspent and unset"

# ── The fake docker learns three observers, each on only when the run's environment asks for it (run96 and run_line96 set
# them; the script never does): every call recorded, its arguments apart (FAKE_EXEC_LOG); at every call, the run's folders
# in /tmp searched for the planted values and for a vault written at a default path (FAKE_TMP_SCAN, FAKE_TMP_HITS); and a
# trap that writes one row to the LIVE book the moment a code reading of the book starts (FAKE_LIVE_TRAP, spent once). ─
cat > "$SB/docker96.hook" <<'HOOK'
# ── 5.18.96's rehearsal: three observers, each on only when the run's environment asks for it ──
if [ -n "${FAKE_EXEC_LOG:-}" ]; then { printf '%s\037' "$@"; printf '\036'; } >> "$FAKE_EXEC_LOG"; fi
if [ -n "${FAKE_TMP_SCAN:-}" ] && [ -s "$FAKE_TMP_SCAN" ] && [ -n "${FAKE_TMP_HITS:-}" ]; then
  for _rh_d in /tmp/dnb-5.18.96-cih-* /tmp/dnb-5.18.96-ss-* /tmp/dnb-5.18.96-code-*/p3b /tmp/dnb-5.18.96-code-*/t1run /tmp/dnb-5.18.96-code-*/t4run; do
    [ -e "$_rh_d" ] || continue
    grep -rlF --exclude='*.php' -f "$FAKE_TMP_SCAN" -- "$_rh_d" 2>/dev/null | sed 's/^/value /'
    find "$_rh_d" \( -name webhook_secret -o -name '.dishnet-sudan.vault.json' -o -name config_vault.json \) -print 2>/dev/null | sed 's/^/file /'
  done >> "$FAKE_TMP_HITS"
fi
if [ -n "${FAKE_LIVE_TRAP:-}" ] && [ -f "$FAKE_LIVE_TRAP" ] && [ "${1:-}" = exec ]; then
  case "$*" in *'$want === "e17"'*)
    rm -f "$FAKE_LIVE_TRAP"
    setpriv --reuid 1000 --regid 1000 --clear-groups -- php -r '$p = new PDO("sqlite:" . $argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      $p->exec("INSERT INTO cb_ledger (sr, project, date, direction, amount, currency, category, description, status, account_id, created_at, updated_at) VALUES (\x27RH96-TRAP-1\x27, \x27dishnet\x27, \x272026-09-28\x27, \x27in\x27, 271828.18, \x27UGX\x27, \x27Receipt\x27, \x27RH96 written to the live book while its copy was read\x27, \x27approved\x27, 0, \x272026-09-28\x27, \x272026-09-28\x27)");' "@DATA@/plugin.sqlite3" >/dev/null 2>&1 \
      && : > "${FAKE_LIVE_TRAP}.fired" ;;
  esac
fi
HOOK
python3 - "$SB/bin/docker" "$SB/docker96.hook" "$DATA" <<'PY' || echo "fakedocker:observers96" >> "$SB/anchor-missing"
import sys
p, hook, data = sys.argv[1], sys.argv[2], sys.argv[3]
s = open(p).read(); h = open(hook).read().replace('@DATA@', data)
lines = s.split('\n')
at = [i for i, l in enumerate(lines) if l.startswith('row() { grep "^$1|" ')]
assert len(at) == 1, len(at)
assert s.count('FAKE_EXEC_LOG') == 0
lines[at[0]:at[0]] = h.rstrip('\n').split('\n')
s2 = '\n'.join(lines)
assert s2.count('FAKE_EXEC_LOG') == 2 and s2.count('FAKE_LIVE_TRAP') == 4 and s2.count(data + '/plugin.sqlite3') == 1
open(p, 'w').write(s2)
PY
rm -f "$SB/docker96.hook"
# ── One answer in the container's own paths. 5.18.96 asks the live code, inside the container, where it keeps its vault
# (ConfigVault::path() written out: the plugins folder when the database's owner may write it), and refuses unless the
# answer is a /data/… path. A real `docker exec` prints /data/ucrm/data/plugins/.dishnet-sudan.vault.json; the fake one runs
# PHP on the host with the paths mapped, so it would print the sandbox's own path and the script would refuse every run
# for a reason the server cannot have. The fake docker maps that one answer back, as the container would print it —
# nothing else it prints is touched. Asserted, with the probe read from the script itself and a control. ──────────────
VPROBE96="$(python3 - "$DEPLOY96" <<'PY'
import sys, re
s = open(sys.argv[1]).read()
m = re.findall(r"php -r '(\$o = \(string\)getenv\(\"DN_VAULT_FILE\"\); if \(\$o !== \"\"\) \{ echo \$o; exit; \}[^']*)' \"\$IN_CONTAINER\" \"\$PDD_IN\"", s)
assert len(m) == 1, len(m)
print(m[0])
PY
)" || echo "probe:vault96" >> "$SB/anchor-missing"
python3 - "$SB/bin/docker" "$MOUNT" <<'PY' || echo "fakedocker:vaultpath96" >> "$SB/anchor-missing"
import sys
p, mount = sys.argv[1], sys.argv[2]
s = open(p).read()
old = 'exec "${pre[@]}" "${args[@]}"\n'
assert s.count(old) == 1, s.count(old)
new = ('case "$*" in *\'$o = (string)getenv("DN_VAULT_FILE"); if ($o !== "") { echo $o; exit; }\'*)   # 5.18.96\'s rehearsal: the vault-path probe answers in the container\'s own paths\n'
       '  "${pre[@]}" "${args[@]}" | sed "s#^' + mount + '/#/data/#"; exit "${PIPESTATUS[0]}" ;;\n'
       'esac\n')
s = s.replace(old, new + old, 1)
assert s.count('the vault-path probe answers') == 1 and s.count(old) == 1
open(p, 'w').write(s)
PY
check "$(env PATH="$SB/bin:$PATH" bash "$SB/bin/docker" exec -u 1000:1000 ucrm php -r "$VPROBE96" "$IN_CONTAINER" "/data/ucrm/data/plugins/.$P-data" 2>&1)|$(php -r "$VPROBE96" "$PD" "$DATA" 2>&1)|$(env PATH="$SB/bin:$PATH" bash "$SB/bin/docker" exec -u 1000:1000 ucrm php -r 'echo $argv[1];' "$IN_CONTAINER" 2>&1)" \
      "/data/ucrm/data/plugins/.dishnet-sudan.vault.json|$VAULT|$PD" \
  "control: the script's vault-path probe, through the fake docker, answers as the container would (/data/…); run on the host it answers the sandbox's path, the live vault — and nothing else the fake docker prints is mapped back"
SCAN96="$SB/tmpscan96.pat"; : > "$SCAN96"   # the planted values the /tmp observer searches for — filled below, never printed
mkdir -p /tmp/dnb-5.18.96-cih-rhcontrol; printf 'rh96-observer-control\n' > /tmp/dnb-5.18.96-cih-rhcontrol/webhook_secret
printf 'rh96-observer-control\n' > "$SB/obs96.pat"
env PATH="$SB/bin:$PATH" FAKE_EXEC_LOG="$SB/obs96.rec" FAKE_TMP_SCAN="$SB/obs96.pat" FAKE_TMP_HITS="$SB/obs96.hits" bash "$SB/bin/docker" exec -u 1000:1000 -e DN_DATA_DIR=/tmp/dnb-5.18.96-cih-x/a23-1/t-data ucrm php -r 'echo 1;' /tmp/dnb-5.18.96-cih-x/a23-1/t/dishnet-hybrid-sudan >/dev/null 2>&1
check "$(grep -c '^value /tmp/dnb-5.18.96-cih-rhcontrol/webhook_secret$' "$SB/obs96.hits")$(grep -c '^file /tmp/dnb-5.18.96-cih-rhcontrol/webhook_secret$' "$SB/obs96.hits")|$(python3 "$H96/rh96.py" audit "$SB/obs96.rec")" "11|copies=1 novault=1 plugin=0 adj=-" \
  "control: the observers see — a planted value and a file named webhook_secret in a run's folder in /tmp, and a PHP process on a code copy without a vault of its own"
rm -rf /tmp/dnb-5.18.96-cih-rhcontrol "$SB/obs96.pat" "$SB/obs96.rec" "$SB/obs96.hits"
: > "$SB/trap96.control"
env PATH="$SB/bin:$PATH" FAKE_LIVE_TRAP="$SB/trap96.control" bash "$SB/bin/docker" exec ucrm php -r 'exit(0); /* $want === "e17" */' >/dev/null 2>&1
check "$(sq "SELECT COUNT(*) FROM cb_ledger WHERE sr = 'RH96-TRAP-1'")$( [ -e "$SB/trap96.control" ] && echo unspent || echo spent)$( [ -e "$SB/trap96.control.fired" ] && echo fired)" "1spentfired" \
  "control: the live-book trap writes its one row at the start of a code reading of the book, once"
sqx "DELETE FROM cb_ledger WHERE sr = 'RH96-TRAP-1'" >/dev/null; rm -f "$SB/trap96.control.fired"
check "$(sq "SELECT COUNT(*) FROM cb_ledger WHERE sr = 'RH96-TRAP-1'")" "0" "…and its row taken out again"

# ── The book: P0's own builder, variant ug — the rows only: the sandbox's settings are Uganda's already (UGX; UGX,USD) and
# its store row carries the pilot's settings, which the variant's settings line would overwrite — then every class of row
# T1 names, through the INSTALLED plugin's writers where they accept the row, by plain SQL only where no writer can make
# it. As the database's owner, with a vault of its own; every amount invented, every name RH96 or RH-MARK. ──────────────
B96="$SB/book96"; mkdir -p "$B96"; chown 1000:1000 "$B96"
python3 - "$REPO/scripts/harness/bank-recon-p0/build-book.php" "$B96/build-book.php" <<'PY' || echo "builder:ug96" >> "$SB/anchor-missing"
import sys
s = open(sys.argv[1]).read()
old = "    case 'ug': case 'two-adj': $store->save('kyc_config.json', $UG); $put(\"$data/kyc_config.json\", $UG); break;\n"
assert s.count(old) == 1, s.count(old)
s = s.replace(old, "    case 'ug': case 'two-adj': break;   // 5.18.96's rehearsal: the rows only; the sandbox's settings are Uganda's already\n", 1)
assert s.count("case 'ug': case 'two-adj': break;") == 1 and s.count(old) == 0 and s.count("$store->save('kyc_config.json', $UG)") == 1   # cfg-diff's own line stays
open(sys.argv[2], 'w').write(s)
PY
cat > "$B96/rh96-book.php" <<'PHP'
<?php
// 5.18.96's rehearsal: T1's classes added to the book P0's builder made — through the INSTALLED plugin's own writers where
// they accept the row, by plain SQL only where no writer can make it (a kind outside the list, a text or a negative id, a
// row on an account of another currency, a status no writer sets). Every amount invented; every name RH96.
declare(strict_types=1);
[$_, $root, $data, $idsJson] = $argv + [null, '', '', '{}'];
if ((string)getenv('DN_VAULT_FILE') === '') { fwrite(STDERR, "refusing: DN_VAULT_FILE must point into the sandbox\n"); exit(2); }
$ids = json_decode($idsJson, true);
foreach (['bankU', 'bankD', 'cash', 'momo', 'dir'] as $k) if (!is_int($ids[$k] ?? null) || $ids[$k] < 1) { fwrite(STDERR, "no id for $k\n"); exit(2); }
$GLOBALS['dataDir'] = $data;
require_once "$root/lib/SqliteStore.php";
require_once "$root/lib/CashbookService.php";
$store = SqliteStore::create($data); $pdo = $store->getPdo(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$cb = new CashbookService($store, $data);
$must = static function ($r, string $w) { if (!is_array($r) || empty($r['ok'])) { fwrite(STDERR, "step failed: $w\n"); exit(3); } return $r; };
$acc = static function (string $n, string $c, string $k) use ($cb, $must): int { return (int)$must($cb->addAccount($n, $c, $k), $n)['id']; };
$ia = $pdo->prepare('INSERT INTO cb_accounts (name, currency, kind) VALUES (?, ?, ?)');
$ia->execute(['RH96 Till Upper', 'UGX', 'Cash']);  $kUpper = (int)$pdo->lastInsertId();   // addAccount refuses both kinds
$ia->execute(['RH96 Till Space', 'UGX', 'cash ']); $kSpace = (int)$pdo->lastInsertId();
$momoOld = $acc('RH96 Wallet Old', 'UGX', 'momo'); $pdo->exec('UPDATE cb_accounts SET active = 0 WHERE id = ' . $momoOld);
$cashUSD = $acc('RH96 Till USD', 'USD', 'cash');
$asset = $acc('RH96 Asset', 'UGX', 'asset'); $equity = $acc('RH96 Equity', 'UGX', 'equity');
$recv = $acc('RH96 Receivable', 'UGX', 'receivable'); $pay = $acc('RH96 Payable', 'UGX', 'payable');
$n = 0;
$row = static function (string $cur, string $dir, float $amt, string $status, string $prj, int $acct, string $date, string $what) use ($cb, &$n): void {
    $cb->addEntryRaw(['project' => $prj, 'date' => $date, 'direction' => $dir, 'amount' => $amt, 'currency' => $cur, 'category' => $dir === 'in' ? 'Receipt' : 'Purchase',
        'category_raw' => '', 'description' => 'RH96 ' . $what, 'status' => $status, 'approved_by' => 'RH96 Approver', 'source' => 'manual', 'account_id' => $acct]);
    $n++;
};
$ir = $pdo->prepare('INSERT INTO cb_ledger (sr, project, date, direction, amount, currency, category, description, status, account_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$raw = static function (string $sr, string $cur, string $dir, string $amt, string $status, string $prj, string $acct, string $date, string $what) use ($ir, &$n): void {
    $ir->execute([$sr, $prj, $date, $dir, $amt, $cur, $dir === 'in' ? 'Receipt' : 'Purchase', 'RH96 ' . $what, $status, $acct, $date, $date]); $n++;
};
// UGX, project dishnet: every location and status class
$row('UGX', 'in', 1834672.41, 'approved', 'dishnet', 0, '2026-09-20', 'unassigned receipt');
$row('UGX', 'in', 213457.19, 'approved', 'dishnet', $kUpper, '2026-09-20', 'on a Cash kind');
$row('UGX', 'out', 91872.06, 'approved', 'dishnet', $kSpace, '2026-09-21', 'on a cash-with-a-space kind');
$row('UGX', 'in', 57104.38, 'approved', 'dishnet', $momoOld, '2026-09-21', 'on an inactive wallet');
$row('UGX', 'out', 70219.64, 'approved', 'dishnet', $asset, '2026-09-22', 'on an asset');
$row('UGX', 'in', 61873.42, 'approved', 'dishnet', $equity, '2026-09-22', 'on equity');
$row('UGX', 'out', 43602.95, 'approved', 'dishnet', $recv, '2026-09-23', 'on a receivable');
$row('UGX', 'in', 32517.08, 'approved', 'dishnet', $pay, '2026-09-23', 'on a payable');
$row('UGX', 'out', 7186.35, 'approved', 'dishnet', (int)$ids['dir'], '2026-09-24', 'on the director');
$row('UGX', 'in', 7462.19, 'Approved', 'dishnet', 0, '2026-09-24', 'status written differently');
$row('UGX', 'out', 6380.57, '', 'dishnet', 0, '2026-09-25', 'status blank');
$row('UGX', 'out', 5207.83, 'on_hold', 'dishnet', 0, '2026-09-25', 'another status');
$raw('RH96-NEG', 'UGX', 'in', '4682.51', 'approved', 'dishnet', '-5', '2026-09-26', 'a negative id');
$raw('RH96-TXT0', 'UGX', 'out', '3907.26', 'approved', 'dishnet', '', '2026-09-26', 'an empty text id');
$raw('RH96-TXTA', 'UGX', 'in', '2856.14', 'approved', 'dishnet', 'abc', '2026-09-26', 'a word for an id');
$raw('RH96-VR', 'UGX', 'in', '1306.79', 'voided_reconcile', 'dishnet', '0', '2026-09-27', 'voided by a reconcile');
$raw('RH96-E17', '', 'in', '3519.42', 'approved', 'dishnet', '0', '2099-12-31', 'no currency, newer than every UGX row');   // E17's exception
// UGX, projects 4g and bluecard
$row('UGX', 'in', 764318.29, 'approved', '4g', 0, '2026-09-20', '4g unassigned');
$row('UGX', 'out', 86137.54, 'approved', '4g', (int)$ids['bankU'], '2026-09-21', '4g bank');
$row('UGX', 'in', 349205.17, 'approved', 'bluecard', 0, '2026-09-20', 'bluecard unassigned');
$row('UGX', 'in', 23971.46, 'approved', 'bluecard', (int)$ids['momo'], '2026-09-21', 'bluecard wallet');
$row('UGX', 'out', 18306.52, 'approved', 'bluecard', (int)$ids['bankU'], '2026-09-22', 'bluecard bank');
// USD: below zero under D2 = A, its bank receipts left out
$raw('RH96-USDCASHUGX', 'USD', 'in', '163.27', 'approved', 'dishnet', (string)$ids['cash'], '2026-09-27', 'USD on a UGX cash account');
$row('USD', 'in', 9418.07, 'approved', 'dishnet', (int)$ids['bankD'], '2026-09-27', 'USD bank receipt');
$row('USD', 'out', 1297.43, 'approved', 'dishnet', 0, '2026-09-28', 'USD spent, no account');
$row('USD', 'in', 362.81, 'approved', 'dishnet', $cashUSD, '2026-09-28', 'USD into the till');
$row('USD', 'out', 471.36, 'pending_approval', 'dishnet', 0, '2026-09-28', 'USD pending');
$row('USD', 'out', 693.24, 'approved', '4g', 0, '2026-09-29', '4g USD spent');
$row('USD', 'in', 2731.58, 'approved', '4g', (int)$ids['bankD'], '2026-09-29', '4g USD bank');
$row('USD', 'in', 87.29, 'approved', 'bluecard', 0, '2026-09-29', 'bluecard USD');
$row('USD', 'out', 584.17, 'approved', 'bluecard', 0, '2026-09-29', 'bluecard USD spent');
echo json_encode(['rows' => $n]), "\n";
PHP
chown -R 1000:1000 "$B96"
printf '%s\n' 1834672.41 213457.19 91872.06 57104.38 70219.64 61873.42 43602.95 32517.08 7186.35 7462.19 6380.57 5207.83 4682.51 3907.26 2856.14 1306.79 3519.42 \
  764318.29 86137.54 349205.17 23971.46 18306.52 163.27 9418.07 1297.43 362.81 471.36 693.24 2731.58 584.17 618033.99 271828.18 > "$SB/seed96.amounts"   # every amount seeded here, the stand-in's receipt and the trap's row
VTB0="$(vt_host)"; BR0="$(sq "SELECT COUNT(*) FROM cb_ledger")"
BOOKIDS="$(cd "$B96" && DN_VAULT_FILE="$B96/vault.json" php "$B96/build-book.php" "$PD" "$DATA" ug 2>"$B96/build.err")"
BOOKN="$(cd "$B96" && DN_VAULT_FILE="$B96/vault.json" php "$B96/rh96-book.php" "$PD" "$DATA" "$BOOKIDS" 2>"$B96/rh96.err")"
check "$(case "$BOOKIDS" in '{"variant":"ug",'*) echo built ;; *) echo "unbuilt($(head -c 200 "$B96/build.err"))" ;; esac)|$BOOKN|$( [ "$(vt_host)" = "$VTB0" ] && echo vault-untouched)" "built|{\"rows\":31}|vault-untouched" \
  "pre-c the book: P0's builder (variant ug, its settings line taken out) and T1's classes, 31 rows of them, through the installed plugin's writers on the sandbox's live store — on a vault of their own, the live vault untouched"
ADJ96="$(python3 -c 'import json, sys; print(json.loads(sys.argv[1])["adj"])' "$BOOKIDS" 2>/dev/null)"
rm -f "$B96/vault.json"
O0="$H96/oracle-0.json"; oracle96 > "$O0"
check "$(oj "$O0" missing)|$(oj "$O0" moves)|$(oj "$O0" usdneg)|$(( $(oj "$O0" rows) - BR0 > 50 ))" "-|yes|yes|1" \
  "control: the book holds a row of every class T1 names (unassigned, cash, momo, an inactive wallet, bank, every other kind, Cash and 'cash ' kinds, a USD row on a UGX account, orphan, negative and text ids, pending, rejected, voided both ways, 'Approved', blank and another status, blank and lowercase currencies, the adjustment's shape, three projects); it moves under D2 = A in both currencies, and USD reads below zero — an empty or a still book could not prove anything"

# ── The planted secret and credential: a webhook secret in the live data folder, and the vault carrying it, as the plugin's
# own refresh keeps it (webhook_secret_file), with a credential the vault keeps (email_ai_mailbox_pw). Fictitious; each
# written to the patterns the /tmp observer searches for, never printed. Then the vault settled as the plugin's own read of
# the distributor switch leaves it — the read the script itself makes as root — so that its fixed point is the baseline. ─
W96="rh96-webhook-planted-$(openssl rand -hex 12)"; V96CRED="rh96-vault-credential-planted-$(openssl rand -hex 12)"
printf '%s\n%s\n' "$W96" "$V96CRED" > "$SCAN96"; chmod 600 "$SCAN96"
( umask 077; printf '%s\n' "$W96" > "$DATA/webhook_secret" ); chown 1000:1000 "$DATA/webhook_secret"
VOWN96="$(stat -c '%u:%g %a' "$VAULT" 2>/dev/null || echo '1000:1000 640')"   # the vault's owner and mode, kept as they are
"$PHPBIN" -r '$v = json_decode((string)@file_get_contents($argv[1]), true); if (!is_array($v)) $v = [];
  $v["config"] = (array)($v["config"] ?? []); $v["config"]["email_ai_mailbox_pw"] = $argv[2]; $v["webhook_secret_file"] = $argv[3];
  exit(file_put_contents($argv[1], json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false ? 1 : 0);' "$VAULT" "$V96CRED" "$W96" \
  && chown "${VOWN96%% *}" "$VAULT" && chmod "${VOWN96##* }" "$VAULT" || echo "plant:vault96" >> "$SB/anchor-missing"
settle96() { env PATH="$SB/bin:$PATH" DN_DATA_DIR="$DATA" bash "$SB/bin/docker" exec ucrm php "$IN_CONTAINER/tools/set_distributors.php" --show >/dev/null 2>&1; }
settle96; sleep 1; VT_S1="$(vt_host)"; settle96; VT96_0="$(vt_host)"
check "$( [ "$VT_S1" = "$VT96_0" ] && echo fixed)|$(grep -cF "$W96" "$VAULT")$(grep -cF "$V96CRED" "$VAULT")$(grep -cxF "$W96" "$DATA/webhook_secret")" "fixed|111" \
  "pre-d the live vault at its fixed point — the plugin's own read of the distributor switch leaves it as it is — holding the planted webhook secret and credential; the secret in the live data folder (neither value printed)"
D96="$(data94)"; KEPT96="$(kept_state)"; QREC96="$(sha256sum "$QREC" | cut -c1-16)"; DX96="$(data96x)"
check "$(sha256sum "$SCAN96" | cut -c1-16 | grep -c .)$(grep -c . "$SCAN96")" "12" "control: the /tmp observer's patterns: the two planted values (never printed)"

echo; echo "== 96-0. what is rehearsed: 5.18.96 — the controls =="
echo "  script    $WHICH96, sha256 $(sha256sum "$DEPLOY96" | cut -c1-16)"
case "$P96" in __*|'') echo "  FAIL the script still carries the placeholder pin ($P96)"; exit 2;; esac
check "$P96" "$(git -C "$REPO" rev-parse --short "origin/$REL96" 2>/dev/null || echo none)" "the script is pinned to the release commit on $REL96 ($P96)"
check "$(git -C "$REPO" rev-parse --short "$P96^" 2>/dev/null)$(ver "$P96")$(ver "$P95")" "${P95}5.18.965.18.95" "the release commit is cut on $P95 (5.18.95, the live version) and is 5.18.96"
check "$(dl D "$P95" "$P96" | grep -c .)$(dl A "$P95" "$P96" | grep -c .)$(dl AM "$P95" "$P96" | grep -c .)$(git -C "$REPO" diff --no-renames --name-only --diff-filter=CRTUXB "$P95" "$P96" | grep -c .)" "05${N96}0" \
  "5.18.95 → 5.18.96: nothing removed, copied, renamed or retyped, five files added, $N96 in all"
check "$(dl A "$P95" "$P96" | sort | paste -sd' ' -)" "$(for f in $ADDED96; do echo "$P/$f"; done | sort | paste -sd' ' -)" "…the five added are the five new tests (T1–T4 and T1's weakened copies)"
W96L=""; for f in $ADDED96 $TAKEN96 $PINS96 manifest.json; do grep -qx "$P/$f" <(dl AM "$P95" "$P96") || W96L="$W96L $f"; done
check "${W96L:-none}" "none" "…and every file of the reviewed set is in the delta"
check "$(git -C "$REPO" diff --name-only "$P95" "$P96" | grep -vc "^$P/")$(dl AM "$P95" "$P96" | grep -c "^$P/migrations/")" "00" "nothing outside the plugin, and no migration"
check "$(git -C "$REPO" rev-list --count "$DEV96_BASE..$FIX96" -- "$P")" "3" "control: the development branch's change is its three commits on $DEV96_BASE"
T96L=""; for f in $ADDED96 $TAKEN96; do [ "$(git -C "$REPO" rev-parse "$P96:$P/$f")" = "$(git -C "$REPO" rev-parse "$FIX96:$P/$f" 2>/dev/null)" ] || T96L="$T96L $f"; done
check "${T96L:-same}" "same" "the eleven files taken whole are the development branch's ($FIX96) byte for byte"
pin_only96() {   # pin_only96 FILE — the release's file is 5.18.95's with every 5.18.95 → 5.18.96 and nothing else
  python3 - <(git -C "$REPO" show "$P95:$P/$1") <(git -C "$REPO" show "$P96:$P/$1") <<'PY'
import sys
a, b = open(sys.argv[1]).read(), open(sys.argv[2]).read()
print('pin' if a.count('5.18.95') >= 1 and a.replace('5.18.95', '5.18.96') == b and a != b else 'other')
PY
}
V96L=""; for f in manifest.json $PINS96; do [ "$(pin_only96 "$f")" = "pin" ] || V96L="$V96L $f"; done
check "${V96L:-pins}" "pins" "the manifest and the five version pins are 5.18.95's with the version alone changed"
check "$(pin_only96 lib/CashbookService.php)" "other" "control: a file of the change is not a version pin (the comparison can tell them apart)"
S96L=""; for f in $KEPT_FILES96; do [ "$(git -C "$REPO" rev-parse "$P96:$P/$f")" = "$(git -C "$REPO" rev-parse "$P95:$P/$f")" ] || S96L="$S96L $f"; done
check "${S96L:-same}" "same" "5.18.95's security files, main.php, master.php, the retry job and its gate, the documents, the gate's and the vault's code and the page tests' fixtures are 5.18.95's byte for byte"
check "$(git -C "$REPO" rev-parse "$P95:$P/dishnet-mikrotik-control-plane")$(git -C "$REPO" rev-parse "$P95:$P/docs")" \
      "$(git -C "$REPO" rev-parse "$P96:$P/dishnet-mikrotik-control-plane")$(git -C "$REPO" rev-parse "$P96:$P/docs")" "Domain B's tree and the plugin's docs are 5.18.95's"
check "$(case "$T1SIG96" in [1-9]*' passed, 0 failed rc=0') echo pinned ;; *) echo "unpinned($T1SIG96)" ;; esac)|$T4SIG96" "pinned|20 passed, 0 failed, 0 skipped rc=0" \
  "the script pins T1's count ($T1SIG96, the release suite's run of $P96) and T4's (no weakened copies)"
check "$(git -C "$REPO" cat-file blob 5954ede5:scripts/bank-recon-p0.php | sha256sum | cut -c1-64)" "$(sed -n 's/^P0_TOOL_SHA="\([0-9a-f]*\)".*/\1/p' "$DEPLOY96")" \
  "P0's own tool at 5954ede5, as the clone holds it, is the one the script pins by sha256"
check "$(sed -n "s/^const ADJ_CENTS_SHA = '\([0-9a-f]*\)';.*/\1/p" "$REPO/scripts/bank-recon-r0.php")" "$(sed -n 's/^ADJ_CENTS_SHA="\([0-9a-f]*\)".*/\1/p' "$DEPLOY96")" \
  "the adjustment's hash is R0's (scripts/bank-recon-r0.php) — a hash, never a figure"
check "$(hdr96 "$DEPLOY96")" "101" \
  "the header's deploy command stands alone, with its own log wrapper — no rollback, put-back, setting or checkout in its block (docs/44 §16.9) — and the later --after-only is its own, with its own"
HDR_DEPLOY_LINE96='#     && bash scripts/deploy-5.18.96.sh 2>&1 | ( umask 077; exec tee /root/dnb-5.18.96/deploy-$(date -u +%Y%m%dT%H%M%SZ).log )'
M="$(mutant hdrrb96 "$DEPLOY96" "$HDR_DEPLOY_LINE96" "$HDR_DEPLOY_LINE96
#   cd /opt/dishnet && bash scripts/deploy-5.18.96.sh --rollback 2>&1 | ( umask 077; exec tee /root/dnb-5.18.96/rollback-\$(date -u +%Y%m%dT%H%M%SZ).log )")"
check "$(hdr96 "$M")" "111" "0-hdr' a copy whose header carries the rollback inside the deploy command's block is caught by the same count"
rm -f "$M"
check "$(cmds96 "$DEPLOY96")" "11111|6|6|11|1" \
  "every command the script prints for a later step is defined with its own log wrapper — the --after-only, the rollback, the deploy, the put-back, and the quarantine's --check with its tee — and each of the twelve lines that print one names one of them, or carries the wrapper itself"
M="$(mutant fnowrap96s "$DEPLOY96" 'AFTER_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --after-only $(LOGW after)"' 'AFTER_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --after-only"')"
check "$(cmds96 "$M")" "01111|6|6|11|1" "0-cmd' a copy that defines the --after-only without its log wrapper is caught by the same reading (and by its run, 4a')"
rm -f "$M"
check "$(grep -cE '(>|>>) *"?\$(VAULT_HOST|VAULT)"?( |$)' "$DEPLOY96")$(grep -cE '(mv|rm|touch|chmod|chown|tee) [^|;]*"\$(VAULT_HOST|VAULT)"' "$DEPLOY96")$(grep -E 'cp [^|;]*"\$VAULT"' "$DEPLOY96" | grep -vcF 'cp -p "$VAULT" "$BK/config-vault.json"')" "000" \
  "the script never writes, moves or touches the live vault — it copies it only into its own backup"
check "$(grep -cE '(cat|head|tail|more|less|awk|sed|grep)( -[^ ]+)* +"\$SHEET"' "$DEPLOY96")$(grep -cF 'echo "      less $SHEET"' "$DEPLOY96")" "01" \
  "nothing in the script reads the cash sheet out — it prints only the command to read it, \`less <path>\`, on its own line"
check "$(grep -c 'DISHNET_REPAIR' "$DEPLOY96")$(grep 'DISHNET_REPAIR' "$DEPLOY96" | grep -vc 'grep -')$(grep -cFf "$SB/spx.vals" "$DEPLOY96")" "400" \
  "the script names the old built-in key only in grep patterns (four), and holds none of 5.18.94's Splynx values"
check "$(bash -n "$DEPLOY96" && echo parses)" "parses" "the script parses"
check "$(git -C "$REPO" log -1 --format=%h "$P96" -- $P)|$(git -C "$REPO" log -1 --format=%h "$P95" -- $P)" "$P96|$P95" \
  "control: the clone, in one pack as a fresh clone is, writes the 7-character short forms the script names"

echo; echo "== 96-1. A14: a failure 5.18.95's or 5.18.94's runs recorded is not forgotten; rule 3; no A20 =="
EXP_QCHECK96LINE="      $EXP_QCHECK"
printf 'STRAY_Q_FAILED=%s\n' "$(date -u +%s)" >> "$STATE95"
fresh96; OUT="$(run96 --answer NO)"
nogo96 "1a a failure in 5.18.95's state file (STRAY_Q_FAILED, as its R19 or RB writes it), newer than the newest passing --check: NO-GO at A14" \
  "NO-GO (A14): a run of this script, of 5.18.95's or of 5.18.94's found the quarantine broken at" "$OUT"
check "$(has "$OUT" '(STRAY_Q_FAILED in dnb-5.18.95/state-5.18.95.env)')$(has "$OUT" 'and the newest passing --check of the quarantine began before that')$(grep -cxF "$EXP_QCHECK96LINE" <<<"$OUT")" "yesyes1" \
  "…it names 5.18.95's file, and prints the quarantine's --check on its own line, with its own log"
A14_LOOP='for f in "$STATE" "$STATE".prev-* "$PREV_STATE" "$PREV_STATE".prev-* "$PREV2_STATE" "$PREV2_STATE".prev-*; do'
M="$(mutant a14prev96 "$DEPLOY96" "$A14_LOOP" 'for f in "$STATE" "$STATE".prev-* "$PREV2_STATE" "$PREV2_STATE".prev-*; do')"
fresh96; OUT="$(run96 --answer NO --script "$M")"; rm -f "$M"
check "$(went96 "$OUT")$(has "$OUT" 'STOP: not confirmed')" "yesyesyes" "1a' a copy blind to 5.18.95's state files reaches the question over it (caught)"
sed -i '/^STRAY_Q_FAILED=/d' "$STATE95"; printf 'STRAY_Q_FAILED=%s\n' "$(date -u +%s)" > "$STATE95.prev-20261017T010000Z"
fresh96; OUT="$(run96 --answer NO)"
nogo96 "1b …in a state file a later deploy of 5.18.95 kept beside its own: NO-GO at A14, naming it" "(STRAY_Q_FAILED in dnb-5.18.95/state-5.18.95.env.prev-20261017T010000Z)" "$OUT"
rm -f "$STATE95.prev-20261017T010000Z"
printf 'STRAY_Q_FAILED=%s\n' "$(date -u +%s)" >> "$STATE94"
fresh96; OUT="$(run96 --answer NO)"
nogo96 "1c …in 5.18.94's state file, two releases back: NO-GO at A14, naming it" "(STRAY_Q_FAILED in dnb-5.18.94/state-5.18.94.env)" "$OUT"
M="$(mutant a14prev2_96 "$DEPLOY96" "$A14_LOOP" 'for f in "$STATE" "$STATE".prev-* "$PREV_STATE" "$PREV_STATE".prev-*; do')"
fresh96; OUT="$(run96 --answer NO --script "$M")"; rm -f "$M"
check "$(went96 "$OUT")$(has "$OUT" 'STOP: not confirmed')" "yesyesyes" "1c' a copy blind to 5.18.94's state files — reading 5.18.95's only, as 5.18.95's A14 did — reaches the question over it (caught)"
sed -i '/^STRAY_Q_FAILED=/d' "$STATE94"
printf 'STRAY_Q_FAILED=soon\n' >> "$STATE95"
fresh96; OUT="$(run96 --answer NO)"
nogo96 "1d a recorded failure whose time cannot be read: NO-GO at A14" \
  "recorded the quarantine broken (STRAY_Q_FAILED in dnb-5.18.95/state-5.18.95.env) at a time that cannot be read" "$OUT"
sed -i '/^STRAY_Q_FAILED=/d' "$STATE95"
printf 'STRAY_Q_FAILED=%s\n' "$(( $(date -u +%s) - 30 ))" >> "$STATE95"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "1e the quarantine's own --check, begun after that failure: PASSED"
QREC96="$(sha256sum "$QREC" | cut -c1-16)"; KEPT96="$(kept_state)"
fresh96; OUT="$(run96 --answer NO)"
check "$(noteline "$OUT" A14 "a run of this script, of 5.18.95's or of 5.18.94's found the quarantine broken at" '(STRAY_Q_FAILED in dnb-5.18.95/state-5.18.95.env)' 'after it, PASSED — so this deploy goes on')$(okline "$OUT" A14 'the quarantine holds')$(went96 "$OUT")$(has "$OUT" 'STOP: not confirmed')" "yesyesyesyesyes" \
  "1e' …then A14 passes and says the failure; a word other than DEPLOY stops the run at the question"
check "$(grep -cE '^  (ok    |note  |FAIL  )A20 |\(A20\)' <<<"$OUT")$(went96 "$OUT")" "0yesyes" \
  "1f on 17 October in Kampala — the day after 5.18.94's documents' date — the deploy names no A20 and reaches its question"
A20_OLD96="# A19 (the website) and A20 (the documents' date) were 5.18.94's own preconditions, gone since 5.18.95; so are 5.18.95's"
M="$(mutant a20kept96 "$DEPLOY96" "$A20_OLD96" "if [ \"\$MODE\" = \"deploy\" ]; then   # a weakened copy: 5.18.94's A20, kept
$A20_94
fi
$A20_OLD96")"
fresh96; OUT="$(run96 --answer NO --script "$M" --today "$AFTER94")"; rm -f "$M"
check "$(has "$OUT" "NO-GO (A20): the Uganda documents are dated 16 October 2026, before today in Kampala ($AFTER94)")$(went96 "$OUT")|$(quiet96)" "yesnono|$P95|0|$D96|$KEPT96|0" \
  "1f' a copy with 5.18.94's A20 kept refuses on 17 October, as it would on every day after 16 October (caught: the script itself reaches its question)"
sed -i '/^STRAY_Q_FAILED=/d' "$STATE95"
printf 'COPY_AT=%s\n' "$(date -u +%s)" > "$STATE95.prev-20261017T020000Z"
fresh96; OUT="$(run96 --answer NO)"
nogo96 "1g a copy of 5.18.95's recorded after the newest passing --check of the quarantine (its rule 3: a --check after every plugin upload): NO-GO at A14" \
  "came after the newest passing --check of the quarantine" "$OUT"
check "$(has "$OUT" '(dnb-5.18.95/state-5.18.95.env.prev-20261017T020000Z)')$(grep -cxF "$EXP_QCHECK96LINE" <<<"$OUT")" "yes1" \
  "…it names 5.18.95's file, and prints the quarantine's --check on its own line, with its own log"
M="$(mutant a14copy96 "$DEPLOY96" '[ "$QCL_T" -gt "$PREV_COPY" ] || stop' 'true || stop')"
fresh96; OUT="$(run96 --answer NO --script "$M")"; rm -f "$M"
check "$(went96 "$OUT")$(has "$OUT" 'STOP: not confirmed')" "yesyesyes" "1g' a copy blind to 5.18.95's copy time reaches the question over it (caught)"
rm -f "$STATE95.prev-20261017T020000Z"
fresh96; OUT="$(run96 --answer NO)"
check "$(okline "$OUT" A14 'the quarantine holds' "it began after 5.18.95's copy (")$(went96 "$OUT")" "yesyesyes" \
  "1h …that planted copy time taken out: A14 passes again and says its --check began after 5.18.95's copy"
check "$(grep -c '^STRAY_Q_FAILED=' "$STATE95" "$STATE94" | awk -F: '{s += $2} END {print s}')|$(quiet96 | cut -d'|' -f1,3-)|$(sha256sum "$QREC" | cut -c1-16)" "0|$P95|$D96|$KEPT96|0|$QREC96" \
  "…every planted line taken out: 5.18.95 live, the data, the quarantine and its record as before — the script read the record, never wrote it"

echo; echo "== 96-1b. A22L: the live 5.18.95 without one of its fixes; a part-way copy: the put-back printed alone =="
cp -p "$PD/public.php" "$SB/public96.kept"
edit_installed public.php "    \$storedKey = is_string(\$storedKey) ? trim(\$storedKey) : '';" "    \$storedKey = is_string(\$storedKey) ? trim(\$storedKey) : '$OLDKEY95';"
fresh96; OUT="$(run96 --answer NO)"
nogo96 "1i the built-in key back in the LIVE public.php: NO-GO at A22L, naming the check and its defect in fixed words" \
  "NO-GO (A22L): the live code, as installed, does not carry 5.18.95's fixes as they were reviewed: A22b(fallback-key old-key-named-2x)" "$OUT"
check "$(has "$OUT" "$OLDKEY95")$(okline "$OUT" A22b 'in the pin, kept by 5.18.96')" "noyes" "…the old key's name never printed; the pin's own check of the same fix ok (A22b reads the pin from Git)"
cat "$SB/public96.kept" > "$PD/public.php"; touch -r "$SB/public96.kept" "$PD/public.php"
check "$(sha256sum "$PD/public.php" | cut -c1-64)" "$(git -C "$REPO" show "$P95:$P/public.php" | sha256sum | cut -c1-64)" "…the live public.php put back: 5.18.95's"
cp -p "$PD/manifest.json" "$SB/manifest96.kept"; git -C "$REPO" show "$P96:$P/manifest.json" > "$PD/manifest.json"
fresh96; OUT="$(run96 --answer NO)"
nogo96 "1j 5.18.95's record with 5.18.96's manifest installed — a copy that stopped part-way: NO-GO before anything changes" \
  "NO-GO: the container's record says $P95, but the installed manifest says 5.18.96: a copy of 5.18.96 that did not complete (a deploy that stopped part-way)" "$OUT"
check "$(grep -cxF "      $EXP_PUTBACK96" <<<"$OUT")$(grep -c 'deploy-5.18.96.sh --rollback' <<<"$OUT")" "10" \
  "…its stop prints the by-hand put-back of 5.18.95 alone on its line, with its own log wrapper — and no rollback"
cat "$SB/manifest96.kept" > "$PD/manifest.json"; touch -r "$SB/manifest96.kept" "$PD/manifest.json"
check "$(inst_ver)$(installed96 "$P95")" "5.18.950" "…the manifest put back: every file 5.18.96 touches is 5.18.95's"

echo; echo "== 96-2. six faulty pins: each refused by the pin's own T1 (A22-T1), and — A22-T1 taken out — by its own check =="
A22T1_OLD='  [ "$T1_A" = "$T1_SIGNATURE" ] || {'; A22T1_NEW='  true || {'
V_KINDS_OLD="$(cat <<'EOF'
" OR account_id IN (SELECT id FROM cb_accounts WHERE kind IN ({$kinds}))"
EOF
)"
V_STATUS_OLD="$(cat <<'EOF'
return " AND status='approved' AND (COALESCE(account_id,0)=0"
EOF
)"
V_GATE_OLD="$(cat <<'EOF'
$this->cashRuleCache = StaffJobsGate::applies($cfg, $this->dataDir) && !dn_ssp_selectable($cfg);
EOF
)"
V_STREAM_OLD="$(cat <<'EOF'
$cashWhere  = $this->cashOnlyWhere();
EOF
)"
v96() {   # v96 LABEL ID EXPECTED-TEXT OLD NEW — the pin with lib/CashbookService.php weakened, cut on 5.18.95: refused at A22-T1 by the
          # real script; and refused with EXPECTED-TEXT by a copy without A22-T1's refusal. Sets V96_LAST to the variant's commit
  local v s m o
  v="$(release_variant96 lib/CashbookService.php "$4" "$5")"; V96_LAST="$v"
  s="$(pinned_copy "v96-$2" "$v" "$DEPLOY96")"
  fresh96; o="$(run96 --answer NO --script "$s")"
  nogo96 "$1 — NO-GO at A22-T1: the pin's own T1, under this PHP, fails on the planted fault" "NO-GO (A22-T1): under this server's own PHP the pin's T1 does not pass" "$o"
  m="$(mutant "v96t1-$2" "$s" "$A22T1_OLD" "$A22T1_NEW")"; rm -f "$s"
  fresh96; o="$(run96 --answer NO --script "$m")"; rm -f "$m"
  nogo96 "$1 — with A22-T1's refusal taken out of a copy, the check written for it refuses on its own" "$3" "$o"
}
NOGO_A232="NO-GO (A23.2): the pin's cash in hand is not D2 = A's SQL on the same copy"
v96 "2a a pin that counts bank rows" bank "$NOGO_A232" "$V_KINDS_OLD" "$(cat <<'EOF'
" OR account_id IN (SELECT id FROM cb_accounts WHERE kind IN ({$kinds}, 'bank'))"
EOF
)"
v96 "2b a pin that counts pending rows" pending "$NOGO_A232" "$V_STATUS_OLD" "$(cat <<'EOF'
return " AND status IN ('approved', 'pending_approval') AND (COALESCE(account_id,0)=0"
EOF
)"
v96 "2c a pin that counts orphans and text ids" orphans "$NOGO_A232" "$V_KINDS_OLD" "$(cat <<'EOF'
" OR account_id IN (SELECT id FROM cb_accounts WHERE kind IN ({$kinds})) OR account_id NOT IN (SELECT id FROM cb_accounts)"
EOF
)"
v96 "2d a pin whose gate lost its tenant half — a South Sudan tenant's books move" southsudan \
  "NO-GO (A24, South Sudan): the pin does not keep the books where the rule is off exactly as 5.18.95 reads them, its gate is not as it must be on them, or the check could not be made: pin-signature outputs-differ" \
  "$V_GATE_OLD" "$(cat <<'EOF'
$this->cashRuleCache = !dn_ssp_selectable($cfg);
EOF
)"
v96 "2e a pin whose Balance column keeps the old rule" balance "NO-GO (A23.2): the pin's Balance column on this book does not reach its cash in hand as it must" "$V_STREAM_OLD" "$(cat <<'EOF'
$cashWhere  = '';
EOF
)"
v96 "2f a pin whose gate fails, so rule A is not in force" gatefails "NO-GO (A23.7): rule A is NOT in force on this server's own configuration, as the pin's code reads it" "$V_GATE_OLD" "$(cat <<'EOF'
$this->cashRuleCache = false;
EOF
)"
V96_GATEFAILS="$V96_LAST"
check "$(quiet96 | cut -d'|' -f1,3-)|$(sha256sum "$QREC" | cut -c1-16)|$(vt_host)" "$P95|$D96|$KEPT96|0|$QREC96|$VT96_0" "…after section 2: 5.18.95 live, the data, the quarantine and the live vault as before"

echo; echo "== 96-2b. the copies are the evidence: the live-book trap, a copy without a vault, the parts, the rule, the adjustment =="
: > "$SB/trap96"
fresh96; OUT="$(run96 --answer NO --env FAKE_LIVE_TRAP="$SB/trap96")"; T="$(last96)"
check "$( [ -e "$SB/trap96" ] && echo unspent || echo spent)$( [ -e "$SB/trap96.fired" ] && echo fired)$(sq "SELECT COUNT(*) FROM cb_ledger WHERE sr = 'RH96-TRAP-1'")|$(went96 "$OUT")|$(okline "$OUT" A23.1 'equals today')$(okline "$OUT" A23.2 'equals D2 = A')" "spentfired1|yesyes|yesyes" \
  "2g a row written to the LIVE book the moment the live code starts reading its copy: A23 reads the copies, made before — every equality holds and the run reaches its question"
sqx "DELETE FROM cb_ledger WHERE sr = 'RH96-TRAP-1'" >/dev/null; rm -f "$SB/trap96.fired"
check "$(data94)|$(hits96 "$T")|$(audit96 "$T" | sed 's/ adj=.*//')" "$D96|0|$(audit96 "$T" | sed -E 's/ novault=.*//') novault=0 plugin=0" \
  "…the trap's row taken out again; the observers quiet: no planted value or default-path vault in /tmp, every PHP process on a copy with a vault of its own"
LIVE_READ_OLD='-e DN_DATA_DIR="$sub/$nm-data" "$CONTAINER" php -d display_errors=stderr -r "$CIHC_PHP" "$sub/$nm/$PLUGIN" "$sub/$nm-data"'
LIVE_READ_NEW='-e DN_DATA_DIR="$PDD_IN" "$CONTAINER" php -d display_errors=stderr -r "$CIHC_PHP" "$sub/$nm/$PLUGIN" "$PDD_IN"'
PHPERR0="$( [ -e "$DATA/php_error.log" ] && echo present || echo absent)"
M="$(mutant livestore96 "$DEPLOY96" "$LIVE_READ_OLD" "$LIVE_READ_NEW")"
: > "$SB/trap96"
fresh96; OUT="$(run96 --answer NO --script "$M" --env FAKE_LIVE_TRAP="$SB/trap96")"; T="$(last96)"; trip96; rm -f "$M"
check "$( [ -e "$SB/trap96.fired" ] && echo fired)$(grep -c '^value ' "$R96/scan/run-$T.hits" | sed 's/^[1-9][0-9]*$/found/')" "firedfound" \
  "2h a copy whose code reads the LIVE store, the trap planted: the trap fires, and the live data folder's webhook secret turns up in /tmp (in that code's vault copy) — the observer finds it"
sqx "DELETE FROM cb_ledger WHERE sr = 'RH96-TRAP-1'" >/dev/null; rm -f "$SB/trap96.fired"
[ "$PHPERR0" = absent ] && rm -f "$DATA/php_error.log"
nogo96 "2h' …and the script refuses it by itself, at A23.1: the live code's figure is not today's SQL on the copy (caught; the trap's row taken out)" \
  "NO-GO (A23.1): the live code's cash in hand is not today's SQL on the same copy" "$OUT"
NOVAULT_OLD='-e DN_VAULT_FILE="$sub/$nm-vault.json" -e DN_DATA_DIR="$sub/$nm-data"'
M="$(mutant novault96 "$DEPLOY96" "$NOVAULT_OLD" '-e DN_DATA_DIR="$sub/$nm-data"')"
fresh96; OUT="$(run96 --answer NO --script "$M")"; T="$(last96)"; trip96; rm -f "$M"
check "$(went96 "$OUT")|$(grep -c '^file /tmp/dnb-5\.18\.96-cih-[^ ]*/\.dishnet-sudan\.vault\.json$' "$R96/scan/run-$T.hits" | sed 's/^[1-9][0-9]*$/found/')|$(audit96 "$T" | sed -E 's/.* novault=([0-9]+) .*/\1/' | sed 's/^[1-9][0-9]*$/some/')|$(vault96 "$T")" "yesyes|found|some|$VT96_0>$VT96_0" \
  "2i a copy that runs the code on its copies without DN_VAULT_FILE reaches the question — caught by the observers: a vault written at the code copy's default path in /tmp, PHP on a copy with no vault of its own (the live vault untouched only because the copy's parent folder is writable)"
check "$(data94)" "$D96" "…nothing of the plugin's data changed"
PARTS_OLD='  [ "$(kv "$CIH_K" parts)" = "$A23_N/$A23_N" ] || stop "NO-GO (A23.3)'; PARTS_NEW='  true || stop "NO-GO (A23.3)'
LOC_OLD="kind IN ('cash','momo')) THEN 'CM'\""; LOC_NEW="kind IN ('cash')) THEN 'CM'\""
M="$(mutant judgeloc96 "$DEPLOY96" "$LOC_OLD" "$LOC_NEW")"
fresh96; OUT="$(run96 --answer NO --script "$M")"; rm -f "$M"
nogo96 "2j a judge whose classes put the mobile-money rows among the other kinds: NO-GO at A23.3 — the parts no longer add up to the difference" \
  "NO-GO (A23.3): the parts do not add up to today − D2 = A in every cell" "$OUT"
M="$(mutant noparts96 "$DEPLOY96" "$LOC_OLD" "$LOC_NEW" "$PARTS_OLD" "$PARTS_NEW")"
fresh96; OUT="$(run96 --answer NO --script "$M")"; rm -f "$M"
check "$(went96 "$OUT")$(okline "$OUT" A23.3 'the parts add up')" "yesyesyes" "2j' …a copy without the parts check reaches the question over the same wrong split, and calls the parts added up (caught)"
RULE_OLD='[ "$(kv "$CIH_D" rule)" = "1" ] || stop "NO-GO (A23.7)'; RULE_NEW='true || stop "NO-GO (A23.7)'
S="$(pinned_copy "v96rule" "$V96_GATEFAILS" "$DEPLOY96")"
M="$(mutant norule96 "$S" "$A22T1_OLD" "$A22T1_NEW" "$RULE_OLD" "$RULE_NEW")"; rm -f "$S"
fresh96; OUT="$(run96 --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" 'NO-GO (A23.7)')$(has "$OUT" "$NOGO_A232")$(went96 "$OUT")" "noyesnono" \
  "2k a copy without the rule-in-force check, on the pin whose gate fails: its refusal moves to A23.2, on a figure, no longer naming the rule (caught) — on a book that did not move under D2 = A it would reach the question"
ADJ_SYN="$(printf 'dn-r0-adj|%s' 12345600 | sha256sum | cut -c1-64)"   # the builder's adjustment-shaped row, in cents — synthetic, from build-book.php
M="$(mutant adjnamed96 "$DEPLOY96" "$(grep -m1 '^ADJ_CENTS_SHA=' "$DEPLOY96")" "ADJ_CENTS_SHA=\"$ADJ_SYN\"")"
fresh96; OUT="$(run96 --answer NO --script "$M")"; T="$(last96)"; rm -f "$M"
check "$(okline "$OUT" A23.5 'the 12 Sep adjustment is in the book: one row of that day, out, UGX, approved, on no account')$(okline "$OUT" A23.4 "P0's own tool")|$(audit96 "$T" | sed 's/.* adj=//')|$(went96 "$OUT")" "yesyes|$ADJ96|yesyes" \
  "2l a copy given the synthetic adjustment's hash finds that row, names it to P0 by its id (--adjustment-id=$ADJ96 in P0's own call), and P0 still agrees — its amount never read out"
check "$(quiet96 | cut -d'|' -f1,3-)|$(vt_host)" "$P95|$D96|$KEPT96|0|$VT96_0" "…after section 2b: 5.18.95 live, the data, the quarantine and the live vault as before"

echo; echo "== 96-3. the deploy, as the operator runs it — DEPLOY typed; a receipt posted between A23 and R23 =="
O_PRE="$H96/oracle-pre.json"; oracle96 > "$O_PRE"; DIG_PRE="$(oj "$O_PRE" digest)"; BOOK_PRE="$(book96x)"
LEDGER96="$(ledger95)"
touch "$SB/web/.add_receipt"
fresh96; OUT="$(run96 --answer DEPLOY)"; OUT96D="$OUT"; T96D="$(last96)"
O_POST="$H96/oracle-post.json"; oracle96 > "$O_POST"
SHEET96D="$(sheet_of "$OUT")"
echo "  …    the deploy's own tally:$(grep -E '^  checks +[0-9]+ ok, ' <<<"$OUT" | sed -E 's/^  checks +/ /')"
check "$(fails "$OUT")$(has "$OUT" '5.18.96 (deploy): PASSED')$(live)" "0yes$P96" "the deploy PASSES: 0 failed, the container serves $P96"
for l in "ok    A0 the release delta is exactly 5.18.96's $N96 files — 5 added, $(( N96 - 5 )) changed, no migration" \
         "ok    A7 Domain B untouched" \
         "ok    A22L the live code, as installed, carries all seven of 5.18.95's fixes" \
         "ok    A14 the stray store is quarantined and the quarantine holds" \
         "ok    A12 on this server's own configuration the pin's gate stops the Starlink retry job" \
         "ok    A13 the pin's retry job" "ok    A15 under this server's own PHP" "ok    A16 under this server's own PHP" \
         "ok    A17 5.18.95's documents, unchanged by 5.18.96, under this server's own PHP" \
         "ok    A22-T1 under this server's own PHP" "($T1SIG96)" \
         "ok    A23.1 the live code's figure — 5.18.95's cashInHand(), installed, run from its code copy on a copy of its own — equals today's SQL" "8 of 8 cells (UGX,USD × all projects, dishnet, 4g, bluecard)" \
         "ok    A23.2 the pin's figure equals D2 = A's SQL on the copy" \
         "ok    A23.3 the parts add up in all 8 cells, to the cent" \
         "ok    A23.4 P0's own tool (5954ede5," "(4 of 4 each)" \
         "note  A23.5 the 12 Sep adjustment is not in the book" \
         "ok    A23.7 rule A is in force on this server's own configuration, as the pin's code reads it" \
         "ok    A23.8 the pages' settings row names the service's base and currencies — the same (UGX; UGX,USD)" \
         "ok    A24 South Sudan, a South Sudan tenant without SSP and a Uganda tenant with SSP read exactly as under 5.18.95" \
         "GO — evidence recorded" "and without the repair key file, never copied" \
         "checking out $P96 (5.18.96, the release commit) for the documented deploy" "ok    container serves $P96" \
         "ok    V4 no fatal or parse error of" \
         "ok    V13 the public Terms and Privacy are 5.18.95's, unchanged by 5.18.96: version 1.2, the identity sentence" \
         "ok    R1 all $N96 files 5.18.96 changes are installed exactly as $P96 has them (5 new)" "ok    R1 the installed manifest says 5.18.96" \
         "ok    R5 all" "ok    R7 the database since this run's stage A: the migration ledger's last row the same" \
         "ok    R18 the installed retry job" "ok    R20 under this server's own PHP the installed test of master's lock passes" \
         "ok    R21 under this server's own PHP the installed test of the retired auto-pull passes" "ok    R22 the installed documents are 5.18.95's, unchanged by 5.18.96" \
         "ok    R23.7 rule A is in force on this server's own configuration, as the installed code reads it" \
         "ok    R23.8 the pages' settings row still names the service's base and currencies (UGX; UGX,USD)" \
         "ok    R23.1 the installed figure equals D2 = A's SQL on the copy" "ok    R23.2 5.18.95's code on the same copy equals today's SQL in all 8 cells" \
         "ok    R23.3 the parts add up in all 8 cells" "ok    R23.4 P0's own tool (5954ede5, unchanged), on the same copy" \
         "ok    R23.5 the installed Balance column, in every view and project" \
         "ok    R23.6 the installed tools/cash_in_hand.php, run from the installed code's copy on a copy of its own" \
         "note  R23.10 the book moved since A23 (" "1 row(s) added, 0 changed, 0 removed (ids on the sheet)" \
         "ok    R24 X5 and D1, statically: T4 on the installed files passes ($T4SIG96)" "ok    R24 the installed CEO page is the pin's byte for byte" \
         "ok    R25 under this server's own PHP the installed T1 passes ($T1SIG96)" \
         "ok    R26 South Sudan, a South Sudan tenant without SSP and a Uganda tenant with SSP read exactly as under 5.18.95 through the installed library" \
         "ok    Z1 the live vault ($VAULT_IN96) is as this run found it" "ok    Z2 nothing of the vault, no webhook_secret file, no settings file and no copy of the book is left" \
         "note  R19 the quarantine holds — nothing at the old name, nothing a new store makes, the kept file unchanged — but master has not completed a run of the retry job since the copy" \
         "  what changed      cash in hand on this Uganda book, rule A in force on its own configuration (A23.7, R23.7)"; do
  check "$(has "$OUT" "$l")" "yes" "3: ${l:0:110}"
done
check "$(ctl96 "$OUT" A22)$(ctl96 "$OUT" R23)" "77" "5.18.95's seven fixes read with their controls, before (A22, the pin) and after (R23, the installed code): each control names 5.18.94's defects exactly"
check "$(noteids96 "$OUT")" "A17 A23.4 A23.5 A23.6 R19 R23.10 R23.9" \
  "its notes are exactly A17's pending v1.0 request, A23.4 (rows not approved), A23.5 (no adjustment of R0's), A23.6 (the counts; E17's exception), R19 (not yet conclusive), R23.9 and R23.10 (the receipt)"
check "$(noteline "$OUT" A23.6 "($(oj "$O_PRE" counts) — counts of rows not voided)")|$(noteline "$OUT" A23.6 "E17's exception can show now: in $(oj "$O_PRE" blanknewer) scope(s)")|$(noteline "$OUT" A23.4 "$(oj "$O_PRE" notappr) row(s) not approved")" "yes|yes|yes" \
  "the counts, E17's scopes and the rows not approved the script prints are this rehearsal's own, read with its own SQL ($(oj "$O_PRE" counts); E17 in $(oj "$O_PRE" blanknewer) scope(s); $(oj "$O_PRE" notappr) not approved)"
RID96="$(sq "SELECT id FROM cb_ledger WHERE description = 'RH96 receipt posted while the deploy ran'")"
check "$( [ -e "$SB/web/.add_receipt" ] && echo unspent || echo spent)|$(sq "SELECT COUNT(*) FROM cb_ledger WHERE description = 'RH96 receipt posted while the deploy ran'")|$(python3 "$H96/rh96.py" drift "$SHEET96D")" "spent|1|1:$RID96" \
  "3a the stand-in's receipt was posted during the run, after A23's copy — the drift the sheet names is that one row, by its id"
check "$(python3 "$H96/rh96.py" sheet "$SHEET96D" "$O_PRE" A23)|$(python3 "$H96/rh96.py" sheet "$SHEET96D" "$O_POST" R23)" "8/8|8/8" \
  "3b the sheet's figures are the book's: A23's today and D2 = A equal this rehearsal's own SQL on the live book before the run, R23's after it — the receipt in, every cell"
check "$(stat -c %a "$SHEET96D")|$(stat -c %a "$OUT96")|$(grep -cxF "      less $SHEET96D" <<<"$OUT")|$(sheetsha_of "$OUT")|$(grep -c 'the physical count' "$SHEET96D")" "600|700|1|$(sha256sum "$SHEET96D" | cut -c1-16)|1" \
  "3c the cash sheet is 0600 in a folder of mode 700; F names it with its sha256-16 and prints \`less <path>\` alone on its line; it ends with the physical count's lines"
ROWS96D="$(sed -n 's/^CIH_A_ROWS=//p' "$STATE96" | tail -1)"
check "$(grep -cE '^CIH_A_AT=20[0-9]{2}-[0-9]{2}-[0-9]{2}T[0-9:]{8}Z$' "$STATE96")|$(sed -n 's/^CIH_A_SHEET=//p' "$STATE96" | tail -1)|$(sed -n 's/^CIH_A_DIGEST=//p' "$STATE96" | tail -1)|$(sed -n 's/^VAULT_A=//p' "$STATE96" | tail -1)" \
      "1|$SHEET96D:$(python3 "$H96/rh96.py" presha "$SHEET96D")|$DIG_PRE|$VT96_0" \
  "3d the state file names A23's time, the sheet with its sha256-16 as A23 left it, the row digest this rehearsal computes on the book as it was, and the live vault as it was — no figure"
check "$(stat -c %a "$ROWS96D")|$(grep -c . "$ROWS96D")|$(grep -cvE '^[0-9]+ [0-9a-f]{16}$' "$ROWS96D")" "600|$(oj "$O_PRE" rows)|0" \
  "3e A23's row file: 0600, one line per row of the book (an id and a 16-hex hash), nothing else"
check "$(grep -c "^COPY_AT=$(stat -c %Y "$PD/lib/CashbookService.php")\$" "$STATE96")$(grep -c '^Q_HOLDS_BEFORE=yes$' "$STATE96")$(grep -c '^STRAY_Q_FAILED=' "$STATE96")" "110" \
  "the state file records the copy's time (as stamp_copy gave lib/CashbookService.php), that the quarantine held before; no failure"
check "$(figs96 "$R96/run-$T96D.txt" "$STATE96" "$ROWS96D" "$OUT96"/*.log "$R96/exec/run-$T96D.rec" 2>/dev/null)|$(grep -cF -f "$SCAN96" "$R96/run-$T96D.txt" "$STATE96" | awk -F: '{s += $NF} END {print s + 0}')" "0|0" \
  "3f no figure of the book, in any printed form — with cents, with and without separators, signed; without cents, grouped as the pages print it, and ungrouped from seven digits — in the run's terminal, its state file or its row file; no planted secret either"
check "$(hits96 "$T96D")|$(audit96 "$T96D" | sed -E 's/^copies=([0-9]+) /\1 /' | awk '{print ($1 >= 12 ? "many" : $1 " only") " " $2 " " $3}')|$(vault96 "$T96D")" "0|many novault=0 plugin=0|$VT96_0>$VT96_0" \
  "3g the observers: nothing planted turned up in /tmp; every one of the PHP processes run on copies (twelve or more) carried a vault of its own, none ran the tool or T1 from the plugin folder; the live vault the same before and after"
SUM96="$(sed -n '/^== F. Summary ==$/,$p' <<<"$OUT")"
check "$(grep -cxF "      $EXP_AFTER96" <<<"$SUM96")$(grep -cxF "      $EXP_QCHECK" <<<"$SUM96")$(grep -cxF "      $EXP_ROLLBACK96" <<<"$SUM96")$(grep -cxF "      $EXP_PUTBACK96" <<<"$SUM96")$(grep -cxF "      $EXP_DEPLOY96" <<<"$OUT")" "11110" \
  "every later command printed exactly, on its own line — the --after-only, the quarantine's --check, the rollback and the by-hand put-back — and the deploy's own never"
check "$(printed96 "$OUT")" "4|0|0" "…four commands in the summary, each with its own log wrapper on its line, and no line holding two commands"
check "$(grep -c 'deploy-5.18.96.sh --rollback' <<<"$OUT")$(rb_where96 "$OUT")$(awk '/^  5\.18\.96 \(deploy\): PASSED/ {p=1} p && index($0, "git checkout '"$P95"'") {print "after"; exit}' <<<"$OUT")" "1afterafter" \
  "the rollback command is printed once, on its own line, after the verdict — and the by-hand put-back after it"
check "$(installed96 "$P96")$(inst_ver)$(data96x)$(book96x)|$(ledger95)" "05.18.96$DX96$BOOK_PRE|$LEDGER96" \
  "every file 5.18.95 → 5.18.96 changes is installed as the pin has it; every table of the plugin's data as before, the book as before but for the receipt; the migration ledger as before"
check "$(kept_state)$(oldname)$(sha256sum "$QREC" | cut -c1-16)$(host_code96)$(left_tmp96)$(evo_calls)" "${KEPT96}0${QREC96}0${TMP96}0" \
  "the deploy left the quarantine as it was, no copy of the code on the host or in /tmp, and never called Evolution"
BK96="$(ls -d "$OUT96"/backup-* 2>/dev/null | head -1)"
check "$(tar -tzf "$BK96/data.tar.gz" 2>/dev/null | grep -c 'emergency_repair_key')$(tar -tzf "$BK96/data.tar.gz" 2>/dev/null | grep -c '/kyc_config.json$')" "01" \
  "its backup holds the data folder's settings file and never the repair key file (5.18.95's rule, kept)"

echo; echo "== 96-4. --after-only, run exactly as the deploy's summary printed it; then its weakened copies =="
copy_back96 30; COPY96="$(sed -n 's/^COPY_AT=//p' "$STATE96" | tail -1)"
set_sched $(( COPY96 + 300 )); base_log
ALINE="$(printed "$OUT96D" "cd $REPO && bash scripts/deploy-5.18.96.sh --after-only")"
check "$ALINE" "$EXP_AFTER96" "4 the --after-only command, as the deploy's summary printed it"
fresh96; O_A="$H96/oracle-after.json"; oracle96 > "$O_A"
OUT="$(run_line96 '' "$ALINE")"; TA="$(last96)"; ALOG="$(ls -1 "$OUT96"/after-*.log 2>/dev/null)"; SHEETA="$(sheet_of "$OUT")"
check "$(fails "$OUT")$(has "$OUT" '5.18.96 (after): PASSED')$(okline "$OUT" R19 'the quarantine holds: master ran the retry job at' "after the copy (at $(utc "$COPY96"))")" "0yesyes" \
  "4a master's run of the job since the copy: R19 conclusive — the quarantine holds — PASSED"
check "$(okline "$OUT" R23.1 'the installed figure equals')$(okline "$OUT" R23.2 "5.18.95's code on the same copy")$(okline "$OUT" R23.5 'the installed Balance column')$(noteline "$OUT" R23.10 'the book moved since A23' '1 row(s) added, 0 changed, 0 removed')$(okline "$OUT" Z1 "the live vault is as the deploy's stage A found it")$(okline "$OUT" R7 "the database since the deploy's stage A (its state file)")$(ctl96 "$OUT" R23)" "yesyesyesyesyesyes7" \
  "…R23 on a new copy, its drift against the deploy's own digest (the receipt); Z1 and R7 against the deploy's own readings; R23a–g with their controls"
check "$(python3 "$H96/rh96.py" sheet "$SHEETA" "$O_A" R23)|$(stat -c %a "$SHEETA")|$(grep -cxF "      less $SHEETA" <<<"$OUT")|$(python3 "$H96/rh96.py" drift "$SHEETA")" "8/8|600|1|1:$RID96" \
  "…its own cash sheet, 0600: its figures this rehearsal's own SQL on the book now, its drift the receipt by its id; \`less <path>\` printed alone"
check "$(printed96 "$OUT")|$(grep -cxF "      $EXP_AFTER96" <<<"$OUT")$(grep -cxF "      $EXP_QCHECK" <<<"$OUT")$(grep -cxF "      $EXP_ROLLBACK96" <<<"$OUT")$(grep -cxF "      $EXP_PUTBACK96" <<<"$OUT")|$(rb_where96 "$OUT")" "4|0|0|1111|after" \
  "…its summary prints four commands, each alone with its own log wrapper — the --after-only again, the quarantine's --check, the rollback and the put-back — the rollback once, after the verdict"
check "$(printf '%s\n' "$ALOG" | grep -c .)|$(stat -c %a "$ALOG" 2>/dev/null)|$(grep -c '5.18.96 (after): PASSED' "$ALOG" 2>/dev/null)|$(figs96 "$R96/line-$TA.txt" "$ALOG")|$(hits96 "$TA")|$(audit96 "$TA" | sed 's/^copies=[0-9]* //; s/ adj=.*//')|$(vault96 "$TA")" \
      "1|600|1|0|0|novault=0 plugin=0|$VT96_0>$VT96_0" \
  "…its own log, 0600, holds the run; no figure in the terminal or the log; the observers quiet; the live vault untouched"
M="$(mutant fnowrap96 "$DEPLOY96" 'AFTER_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --after-only $(LOGW after)"' 'AFTER_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --after-only"')"
base_log; OUT="$(run96 --script "$M" --after-only)"; rm -f "$M"
check "$(printed96 "$OUT")|$(grep -cxF "      $EXP_AFTER96" <<<"$OUT")" "4|1|0|0" \
  "4a' a copy that prints the --after-only without its log wrapper is caught: one command of four without it, and not the line the runbook gives"
APPR_OLD="$(cat <<'EOF'
    $frag = " AND status='approved' AND (" . $inA . ")";
EOF
)"
M="$(mutant r23noappr96 "$DEPLOY96" "$APPR_OLD" '    $frag = " AND (" . $inA . ")";')"
base_log; OUT="$(run96 --script "$M" --after-only)"; rm -f "$M"
check "$(failline "$OUT" R23.1 "the installed cash in hand is not D2 = A's SQL on the copy")$(has "$OUT" '5.18.96 (after): PASSED')" "yesno" \
  "4b a copy whose R23 drops the approved filter FAILS R23.1: the installed code counts approved rows only, the book holds pending and rejected rows in A's locations (caught)"
OKFIG_NEW="$(cat <<'EOF'
ok "R23.1 $(grep -m1 "today's rule — every entry not voided" "$SHEET") the installed figure equals
EOF
)"
M="$(mutant okfig96 "$DEPLOY96" 'ok "R23.1 the installed figure equals' "$OKFIG_NEW")"
base_log; OUT="$(R96_MUTANT_OUT=1 run96 --script "$M" --after-only)"; T="$(last96)"; rm -f "$M"
check "$(okline "$OUT" R23.1 "today's rule — every entry not voided")|$(figs96 "$R96/mutants/run-$T.txt")" "yes|1" \
  "4c a copy whose ok line carries a figure from the sheet is caught by the figure sweep of its own terminal"
M="$(mutant catsheet96 "$DEPLOY96" '  echo "      less $SHEET"' '  echo "      less $SHEET"
  cat "$SHEET"')"
base_log; OUT="$(R96_MUTANT_OUT=1 run96 --script "$M" --after-only)"; T="$(last96)"; rm -f "$M"
check "$(figs96 "$R96/mutants/run-$T.txt")|$(grep -c "^== R23 — cash in hand AFTER the deploy" <<<"$OUT")" "1|1" \
  "4d a copy that prints the cash sheet to the terminal is caught by the same sweep"
cp -p "$VAULT" "$SB/vault96.kept"
TOOL_OLD="$(cat <<'EOF'
-e DN_DATA_DIR="$sub/tool-data" -e DN_VAULT_FILE="$sub/tool-vault.json" "$CONTAINER" sh -c 'php "$1/tools/cash_in_hand.php" > "$2" 2>&1; echo "rc=$?"' _ "$sub/d/$PLUGIN"
EOF
)"
TOOL_NEW="$(cat <<'EOF'
-e DN_DATA_DIR="$sub/tool-data" "$CONTAINER" sh -c 'php "$1/tools/cash_in_hand.php" > "$2" 2>&1; echo "rc=$?"' _ "$IN_CONTAINER"
EOF
)"
M="$(mutant toolplugin96 "$DEPLOY96" "$TOOL_OLD" "$TOOL_NEW")"
base_log; OUT="$(run96 --script "$M" --after-only)"; T="$(last96)"; trip96; rm -f "$M"
TP96="$(if [ "$(fails "$OUT")" = "0" ]; then echo blind; elif [ "$(fails "$OUT")" = "1" ] && [ "$(failline "$OUT" R23 'the live vault')" = yes ]; then echo vault-watch; else echo "other($(fails "$OUT"))"; fi)"
check "$(grep -c '^\(value\|file\) /tmp/dnb-5\.18\.96-cih-[^ ]*/tool-data/webhook_secret$' "$R96/scan/run-$T.hits" | sed 's/^[1-9][0-9]*$/found/')|$(audit96 "$T" | sed -E 's/.* novault=([0-9]+) plugin=([0-9]+) .*/\1:\2/; s/^[1-9][0-9]*:/some:/')|$(okline "$OUT" R23.6 'the installed tools/cash_in_hand.php')|$(case "$TP96" in blind|vault-watch) echo script-blind-or-vault-watch ;; *) echo "$TP96" ;; esac)" \
      "found|some:1|yes|script-blind-or-vault-watch" \
  "4e a copy that runs the installed tool from the plugin folder with no vault of its own — 5.18.94's R7, on a copy of the book — is caught by the observers: the live vault's webhook secret restored into the tool's copy in /tmp, the tool run from the plugin folder with no vault of its own; R23.6 still ok — the script itself passes over it, or fails only on its watch of the live vault if the plugin's refresh rewrote it ($TP96)"
cp -p "$SB/vault96.kept" "$VAULT"
check "$(vt_host)" "$VT96_0" "…the live vault put back: bytes and time as before"
CEO_LINE="$(grep -m1 -F 'foreach (dn_book_currencies($config ?? null) as $_ceoCihCur) $_ceoCih[$_ceoCihCur] = $_ceoCb->cashInHand($_ceoCihCur);' "$PD/tabs/admin/ceo_dashboard.php")"
cp -p "$PD/tabs/admin/ceo_dashboard.php" "$SB/ceo96.kept"
edit_installed tabs/admin/ceo_dashboard.php "$CEO_LINE" "$CEO_LINE
            \$_ceoPositions = \$_ceoCb->currencyPositions();"
base_log; OUT="$(run96 --after-only)"
check "$(failline "$OUT" R24 'T4 on the installed files does not pass')$(failline "$OUT" R24 "the installed CEO page is not the pin's")$(failline "$OUT" R1 'installed files that differ' 'tabs/admin/ceo_dashboard.php')" "yesyesyes" \
  "4f a second currencyPositions() call put into the INSTALLED CEO page's rule-A branch: R24 FAILS twice — T4 on the installed files, and the page not the pin's — and R1 names it"
R24A_OLD='if [ "$T4_R" = "$T4_SIGNATURE" ]; then ok "R24 X5 and D1'; R24A_NEW='if true; then ok "R24 X5 and D1'
R24B_OLD='if [ "$(git -C "$REPO" show "$EXPECTED_PLUGIN_COMMIT:$PLUGIN/$R24_CEO" 2>/dev/null | sha256sum | cut -c1-64)" = "$(sha256sum "$DEST/$R24_CEO" 2>/dev/null | cut -c1-64)" ]; then'
M="$(mutant r24blind96 "$DEPLOY96" "$R24A_OLD" "$R24A_NEW" "$R24B_OLD" 'if true; then')"
base_log; OUT="$(run96 --script "$M" --after-only)"; rm -f "$M"
check "$(okline "$OUT" R24 'X5 and D1, statically')$(okline "$OUT" R24 "the installed CEO page is the pin's")$(failline "$OUT" R1 'installed files that differ')" "yesyesyes" \
  "4f' a copy whose R24 is blind to the CEO page passes R24 over the edit (caught) — only R1's bytes still see it"
cat "$SB/ceo96.kept" > "$PD/tabs/admin/ceo_dashboard.php"; touch -r "$SB/ceo96.kept" "$PD/tabs/admin/ceo_dashboard.php"
check "$(installed96 "$P96")$(stat -c '%u:%g' "$PD/tabs/admin/ceo_dashboard.php")" "01000:1000" "…the installed CEO page put back: every file as the pin has it"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "4g the quarantine's own --check, as the runbook orders after this: PASSED with 5.18.96 live"
QREC96="$(sha256sum "$QREC" | cut -c1-16)"; KEPT96="$(kept_state)"

echo; echo "== 96-5. the rollback, run exactly as the deploy's summary printed it — ROLLBACK typed — and deployed again =="
RLINE="$(printed "$OUT96D" "cd $REPO && bash scripts/deploy-5.18.96.sh --rollback")"
check "$RLINE" "$EXP_ROLLBACK96" "5 the rollback command, as the deploy's summary printed it after its verdict"
S96SHA="$(sha256sum "$STATE96" | cut -c1-64)"; DR0="$(data94)"
fresh96; O_RB="$H96/oracle-rb.json"; oracle96 > "$O_RB"
OUT="$(run_line96 ROLLBACK "$RLINE")"; TR="$(last96)"; RLOG="$(ls -1 "$OUT96"/rollback-*.log 2>/dev/null)"; SHEETR="$(sheet_of "$OUT")"
check "$(fails "$OUT")$(has "$OUT" '5.18.96 (rollback): PASSED')$(live)" "0yes$P95" "5a the rollback PASSES: 5.18.95 serving again"
for l in "ok    container serves $P95 (5.18.95)" "ok    RB the installed manifest says 5.18.95" \
         "ok    RB all 12 files 5.18.96 changed are 5.18.95's again, byte for byte (sha256); the 5 tests it added stay on disk, reached by nothing" \
         "ok    RB the installed CEO page has no rule-A branch and draws the account positions again, and the installed library has no cash-in-hand gate" \
         "ok    RB 5.18.95's seven fixes are still installed after the rollback" \
         "ok    RB cash in hand reads 5.18.95's rule again on a copy of the book" \
         "ok    RB the database across the rollback: the migration ledger's last row the same (" \
         "ok    RB the documents are still 5.18.95's" "ok    RB every authorisation record still matches its terms under 5.18.95's code" \
         "ok    RB the quarantine still holds, as its record says" \
         "ok    V13 the public Terms and Privacy are 5.18.95's, after the rollback as before it" \
         "note  RB the code is 5.18.95 again. The rollback restores code only. The files 5.18.96 added stay on disk" \
         "ok    Z1 the live vault is as the deploy's stage A found it" "ok    Z2 nothing of the vault" \
         "  serving           $P95  (plugin 5.18.95)" "  what it means     cash in hand reads 5.18.95's rule again"; do
  check "$(has "$OUT" "$l")" "yes" "5a: ${l:0:110}"
done
check "$(grep -cxF "      $EXP_QCHECK" <<<"$OUT")$(grep -cxF "      $EXP_DEPLOY96" <<<"$OUT")$(grep -c 'deploy-5.18.96.sh --rollback' <<<"$OUT")|$(printed96 "$OUT")|$(grep -cxF "      less $SHEETR" <<<"$OUT")" "110|2|0|0|1" \
  "…its summary prints the quarantine's --check and the deploy again, each alone with its own log, and \`less\` for its own sheet — never a rollback"
check "$(python3 "$H96/rh96.py" sheet "$SHEETR" "$O_RB" RB | cut -d/ -f1)|$(stat -c %a "$SHEETR")" "8|600" \
  "…the rollback's own cash sheet, 0600: today's rule on a copy, equal to this rehearsal's own SQL on the book in every cell"
check "$(installed96 "$P95")$(inst_ver)$(for f in $ADDED96; do [ -f "$PD/$f" ] && echo kept; done | grep -c kept)" "05.18.955" "every changed file as 5.18.95 has it, manifest 5.18.95; the five added tests stay on disk"
check "$(printf '%s\n' "$RLOG" | grep -c .)|$(stat -c %a "$RLOG" 2>/dev/null)|$(grep -c '5.18.96 (rollback): PASSED' "$RLOG" 2>/dev/null)|$(sha256sum "$STATE96" | cut -c1-64)|$(ls "$OUT96"/rollback-state-*.env 2>/dev/null | grep -c .)" "1|600|1|$S96SHA|1" \
  "…its own log, 0600; the deploy's state file untouched — the rollback's record is a file of its own"
check "$(data94)$(ledger95)$(kept_state)$(oldname)$(sha256sum "$QREC" | cut -c1-16)$(host_code96)$(left_tmp96)|$(figs96 "$R96/line-$TR.txt" "$RLOG")|$(hits96 "$TR")|$(vault96 "$TR")" \
      "$DR0$LEDGER96${KEPT96}0${QREC96}0${TMP96}|0|0|$VT96_0>$VT96_0" \
  "the rollback changed no data, not the ledger and not the quarantine, left no copy, printed no figure; the observers quiet, the live vault untouched"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "…the quarantine's own --check after the rollback, as RB asks: PASSED"
QREC96="$(sha256sum "$QREC" | cut -c1-16)"; KEPT96="$(kept_state)"
fresh96; OUT="$(run96 --answer DEPLOY)"
check "$(fails "$OUT")$(has "$OUT" '5.18.96 (deploy): PASSED')$(live)$(okline "$OUT" R23.10 'no drift since A23')$(ctl96 "$OUT" A22)$(ctl96 "$OUT" R23)" "0yes${P96}yes77" \
  "5b deployed again over the rollback: PASSED, $P96 serving, no drift between its A23 and R23, every fix with its control"
# A rollback whose copy leaves 5.18.96's library behind — put back by the stand-in at the first request once 5.18.95's
# manifest is installed, so after the copy and before RB: RB FAILS by sha256, by the library's gate, and by cash in hand.
git -C "$REPO" show "$P96:$P/lib/CashbookService.php" > "$SB/cbs96.left"; chmod 644 "$SB/cbs96.left"
printf '%s\n' "$SB/cbs96.left" > "$SB/web/.left96"
fresh96; OUT="$(run96 --answer ROLLBACK --rollback)"
check "$(live)|$( [ -e "$SB/web/.left96" ] && echo unspent || echo spent)|$(cmp -s "$PD/lib/CashbookService.php" "$SB/cbs96.left" && echo left)|$(fails "$OUT")|$(has "$OUT" '5.18.96 (rollback): 3 FAILED')" "$P95|spent|left|3|yes" \
  "5c a rollback whose copy leaves 5.18.96's library behind: 5.18.95 serving, the library 5.18.96's, three checks FAIL"
check "$(failline "$OUT" RB "installed files that are not 5.18.95's after the rollback: lib/CashbookService.php")$(failline "$OUT" RB "the installed CEO page or library still carries 5.18.96's rule" 'the gate: present')$(failline "$OUT" RB "the installed cash in hand is not 5.18.95's rule on the copy (gate 1;")" "yesyesyes" \
  "…RB FAILS by sha256 naming the file, by the library's gate, and by cash in hand on a copy: the gate on, D2 = A where today's rule must be"
git -C "$REPO" show "$P95:$P/lib/CashbookService.php" > "$PD/lib/CashbookService.php"
printf '%s\n' "$SB/cbs96.left" > "$SB/web/.left96"
RBDEF_OLD='  if [ "$(kv "$CIH_T" rule)" = "-" ] && [ "$RB_N" -gt 0 ] && [ "$(kv "$CIH_K" cmp_today)" = "$RB_N/$RB_N" ] && [ "$(kv "$CIH_K" parts)" = "$RB_N/$RB_N" ]; then'
M="$(mutant rbblind96 "$DEPLOY96" "$RBDEF_OLD" '  if [ "$RB_N" -gt 0 ]; then')"
fresh96; OUT="$(run96 --answer ROLLBACK --script "$M" --rollback)"; rm -f "$M"
check "$(live)|$( [ -e "$SB/web/.left96" ] && echo unspent || echo spent)|$(okline "$OUT" RB "cash in hand reads 5.18.95's rule again on a copy of the book")$(failline "$OUT" RB "installed files that are not 5.18.95's after the rollback: lib/CashbookService.php")|$(fails "$OUT")" "$P95|spent|yesyes|2" \
  "5d' a copy whose RB is blind to the definition, run over the same library left behind (5.18.95 already serving): it calls cash in hand today's rule (caught) — only the sha256 and the library's gate still see it"
git -C "$REPO" show "$P95:$P/lib/CashbookService.php" > "$PD/lib/CashbookService.php"
check "$(installed96 "$P95")$(stat -c '%u:%g' "$PD/lib/CashbookService.php")" "01000:1000" "…the library put back as 5.18.95's: every file 5.18.95's again"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "…the quarantine's own --check: PASSED"
QREC96="$(sha256sum "$QREC" | cut -c1-16)"; KEPT96="$(kept_state)"
fresh96; OUT="$(run96 --answer DEPLOY)"
check "$(fails "$OUT")$(has "$OUT" '5.18.96 (deploy): PASSED')$(live)$(installed96 "$P96")$(inst_ver)" "0yes${P96}05.18.96" "5e deployed again: PASSED, $P96 serving, every file as the pin has it"

echo; echo "== 96-6. what the rehearsal left behind, and what it never reached =="
fresh96   # the last logs and sheets into the sweeps' folders
check "$(evo_calls)" "0" "Evolution never called — not by the deploys, their checks, the rollbacks or the code they ran on copies"
check "$(ls -a "$REPO/scripts" | grep -E '^\..+\.sh$' | paste -sd, -)" ".qpin.sh" "no weakened or re-pinned copy is left in the clone's scripts/ — the quarantine's one pinned copy aside"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )" "" "every weakened copy, release variant and planted edit found its anchor exactly once — none was skipped"
M="$(mutant anchorprobe96 "$DEPLOY96" 'NO SUCH ANCHOR IN THE SCRIPT' 'x' 2>/dev/null)"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )$(test -e "$M" && echo made || echo none)" "mutant:anchorprobe96 none" \
  "control on the control: a weakened copy whose anchor is missing is recorded as such, and no copy is made"
rm -f "$SB/anchor-missing"
check "$( [ -f "$SB/bad-runs" ] && tr '\n' ' ' < "$SB/bad-runs" )" "" "every run took the options it was given — none answered 'unknown option'"
OUT="$(run96 --after-only --rehearsal-unknown-option)"
check "$(has "$OUT" 'unknown option: --rehearsal-unknown-option')$( [ -s "$SB/bad-runs" ] && echo recorded)" "yesrecorded" "control on the control: a run given an option the script does not know is recorded as such"
rm -f "$SB/bad-runs"
check "$(ls -A "$SB/web" | grep -c '^\.add_receipt$\|^\.left96$\|^\.add_ledger$\|^\.left95$')$(ls "$SB"/trap96* 2>/dev/null | grep -c .)" "00" "every trigger and trap the rehearsal planted was spent"
# The observers over every run of the 5.18.96 script, but those that trip one on purpose (recorded as they ran).
OBS_BAD=0; OBS_N=0
for rec in "$R96"/exec/run-*.rec; do
  t="${rec##*/run-}"; t="${t%.rec}"; OBS_N=$((OBS_N+1))
  grep -qx "$t" "$R96/expected-trips" && continue
  a="$(audit96 "$t")"; case "$a" in *" novault=0 plugin=0 "*) ;; *) OBS_BAD=$((OBS_BAD+1)) ;; esac
  [ "$(hits96 "$t")" = "0" ] || OBS_BAD=$((OBS_BAD+1))
  b="$(cat "$R96/vault/run-$t.before")"; [ "$b" = "$(cat "$R96/vault/run-$t.after")" ] || OBS_BAD=$((OBS_BAD+1))
done
check "$( [ "$OBS_N" -ge 30 ] && echo many)|$OBS_BAD|$(grep -c . "$R96/expected-trips")" "many|0|3" \
  "6 across every run of the 5.18.96 script — the deploys, the after-onlys, the rollbacks, the refusals and the weakened copies — the observers were quiet: no PHP process on a copy without a vault of its own, none running the tool or T1 from the plugin folder, nothing planted in /tmp, the live vault the same before and after each; the three copies that trip them on purpose aside (2h, 2i, 4e)"
check "$(vt_host)|$(grep -cxF "$W96" "$DATA/webhook_secret")" "$VT96_0|1" "…the live vault as it was before the first run, bytes and time; the planted webhook secret where it was planted"
sweep_files96() { ls -1 "$R96"/run-*.txt "$R96"/line-*.txt "$R96"/logs/*.log "$OUT96"/*.log "$OUT96"/state-5.18.96.env* "$OUT96"/rollback-state-* "$OUT96"/cih-rows-* 2>/dev/null; }
check "$( [ "$(sweep_files96 | grep -c .)" -ge 30 ] && echo many)|$( [ "$(ls "$R96"/sheets/cash-*.txt 2>/dev/null | grep -c .)" -ge 5 ] && echo sheets)|$(forms96; grep -c . "$SB/forms96.txt" | awk '{print ($1 >= 100 ? "forms" : "few:" $1)}')" "many|sheets|forms" \
  "the sweep has material: every run's terminal, every printed command's log, the state files and A23's row files; every run's cash sheet; a hundred and more printed forms of the book's figures"
check "$(figs96 $(sweep_files96))" "0" \
  "6a no figure of the book — in any printed form: with cents, with and without separators, signed; without cents, grouped, and ungrouped from seven digits — in any terminal, log, state file or row file of any run of the 5.18.96 script (the weakened copies that print one on purpose aside): only in the cash sheets"
check "$(sweep_files96 | xargs grep -lF -f "$SCAN96" 2>/dev/null | grep -c .)|$(cat "$SEC95" | grep -v '^$' > "$SB/sec96.pat"; sweep_files96 | xargs grep -lF -f "$SB/sec96.pat" 2>/dev/null | grep -c .)|$( [ "$(grep -c . "$SB/sec96.pat")" -ge 15 ] && [ "$(grep -c . "$SCAN96")" = "2" ] && echo listed)" "0|0|listed" \
  "6b no output, log or state file holds the planted webhook secret or vault credential, a repair key, a planted Splynx or pdf value, or one of 5.18.94's Splynx values — over pattern lists that are there (the two planted values; 5.18.95's fifteen and more)"
printf 'planted %s and %s\n' "$(grep -m1 '^2:' "$SB/forms96.txt" | cut -c3-)" "$W96" > "$R96/zz-sweep-control.txt"
check "$(figs96 "$R96/zz-sweep-control.txt")$(grep -lF -f "$SCAN96" "$R96/zz-sweep-control.txt" | grep -c .)" "11" "control on the sweep: a file holding one figure form and the planted secret is found by both"
rm -f "$R96/zz-sweep-control.txt" "$SB/sec96.pat"
check "$(left_tmp96)$(host_code96)$(ls -d /tmp/dnb-backup-* 2>/dev/null | grep -c .)" "${TMP96}00" "no copy of the book or the code, no vault, no synthetic book and no backup copy is left in /tmp or on the host"
check "$(grep -c 'Fatal\|Parse error' "$SB/web.log")" "0" "the stand-in served every request without a PHP fatal"
check "$(checkout_state)" "$CHECKOUT0" "this checkout is as the rehearsal found it: same commit, same tracked files"

echo; echo "rehearsal: $PASS passed, $FAILN failed ($(cat "$SB/runs") runs of the deploy scripts, $(cat "$SB/qruns" 2>/dev/null || echo 0) of the quarantine)"
[ "$FAILN" = "0" ]
