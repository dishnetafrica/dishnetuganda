#!/usr/bin/env bash
#
# Rehearse scripts/deploy-5.18.97.sh — ECO Bank: the Ecobank statement screen below Cashbook, its two cards, the CEO Quick
# Link, switched off after the deploy (SPEC-separate v2 §1.5, §1.8, §2.8, §2.10b, §2.11) — over 5.18.96, before the
# operator runs it.
#
# The sandbox is 5.18.96's rehearsal's, built by its own code: scripts/harness/deploy-5.18.96/rehearse.sh, read up to just
# before its rollback section (the cut asserted: its anchor exactly once) and run here as it is — which builds 5.18.95's,
# 5.18.94's and the quarantine's sandboxes the same way, from their own rehearsals. By the time this file's own sections
# begin, the REAL 5.18.96 deploy (DEPLOY typed), its --after-only and the quarantine's --check have run: 5.18.96 live, its
# synthetic book, the planted webhook secret and vault credential, the observers in the fake docker — as production will
# be when the operator runs this release.
#
# On it, synthetic only: three staff records through the installed store (an active administrator, an inactive one and a
# former administrator, now an accountant, still active: the stale session); later, two Account Details statements made by
# the release's own generator of synthetic statements, kept on the book's two Ecobank accounts through the INSTALLED
# BankStatementStore; a stand-in receipt posted while a run goes on; and every plant SPEC §2.10b names. Then this
# release's script, through a pseudo-terminal, its word typed, as the operator runs it, and every command its summary
# prints for a later step — the --after-only, the switch, --check-bank, the two rollback steps, --redeploy — run as
# printed.
#
# The observers are 5.18.96's, extended to this release's folders (asserted): every docker call recorded, so that each PHP
# process run on a copy — ECO Bank's gate processes included — can be shown to carry a vault of its own; this run's folders
# in /tmp searched at every call for the planted values and for a vault at a default path; the live vault read before and
# after every run. They are switched on by the run's environment, never by the script.
#
# This rehearsal proves:
#   · the script is pinned to the release commit on release/5.18.97, cut on e35ecce (5.18.96); the delta is exactly the
#     reviewed set — eleven files added and fourteen changed: fourteen the development branch's (213c5ce0) byte for byte,
#     three ported (5.18.96's file with the development change's lines and no others), two tests re-pinned to e35eccee,
#     the manifest and five pins 5.18.96's with the version alone changed; one migration, 088, as the script pins it (sha256,
#     md5, 5 tables, 11 indexes, 11 triggers); SAFETY.md is 213c5ce0's and carries the privacy redaction (c60f5176), and
#     MANIFEST.md, the plugin's docs and Domain B are 5.18.96's; T1 and T4 pinned; the header's deploy command alone; every
#     later command — the switch and its clearing among them — defined with its own log wrapper; nothing writes the live
#     vault or reads the sheet out; the instruments BR2 and BK2 run (088's reading, the gate's refusal of a non-copy path)
#     shown to see what they must on a copy of the book (0);
#   · --check is stage A alone: with no active administrator it refuses at BK2 (zero administrators is a FAIL); with the
#     staff planted it says GO — the gate's three states exact, BN5's note, the deploy printed alone, nothing backed up or
#     written but its sheet; with no active non-administrator, BK2's note (the stale-session check not exercised); option
#     errors; --check-bank before the deploy refused (c);
#   · A14 reads 5.18.96's, 5.18.95's and 5.18.94's state files and 5.18.96's copy time, each blind copy caught (1) — and
#     this script's own copies: a --check --redeploy before the quarantine's --check after a rollback refuses (6); A22L,
#     a part-way copy, an installed file not 5.18.96's (BASE_DIFFERS) and BR1 — every tracked file — refuse, a copy blind to
#     BR1 caught (1b);
#   · six faulty pins of the cash library are refused first at A0 (a file 5.18.97 must not ship), then — each refusal taken
#     out of a copy in turn — by the pin's own T1 and by the check written for it, and a pin that counts bank rows by BK1;
#     a gate that trusts the session is refused at BK3 and, BK3 taken out, at BK2 by the stale session (stale=3); a menu link
#     not under the gate at BK3, and a CEO Quick Link not under it; a phone More menu that links to ECO Bank at A0 and, A0
#     taken out, at BK3; a gate that does not parse at A2 (K3's php -l) and, A2 taken out, at BK3; the switch in the store
#     copy alone keeps the gate closed (K2 false: GO, BR3's note), and a gate that reads the store is refused at BK2 by that
#     plant and by nothing without it (2);
#   · the copies are the evidence: the live-book trap, a copy reading the live store (A23.1 and the observer), a copy with
#     no vault — the cash code's and ECO Bank's gate processes' — caught by the observers, the live book put back after the
#     copy that reads it (its code's runner applies 088 there); the parts; the adjustment (2b);
#   · the deploy PASSES with a receipt posted between A23 and R23: every check of 5.18.96's and ECO Bank's (BR1–BR3, BN5,
#     BK1–BK3, BA1, BA2, BA4, V3j, R7's one row) with its fixed words; 088 applied by the plugin's own runner, empty; the
#     sheet's figures and its ECO Bank lines (row ids, no date) this rehearsal's own; no figure, secret, account name or
#     number anywhere else; the switch, --check-bank, the two rollback steps and the put-back printed alone, in that order,
#     after the verdict (3);
#   · --after-only as printed, then its weakened copies, each caught; R7 refuses a ledger row that is not 088's, and a copy
#     that takes any one row is caught; BA1 refuses 088 with a trigger dropped, and so does --check-bank's CB; BA4 refuses
#     ECO Bank's screen answering a visitor with no session; --check-bank before any statement (4);
#   · the switch command, run as printed: the one line it set, in its own 0600 log, both copies set, the vault untouched;
#     --after-only then says it, and prints no switch command; V3j FAILS the switch cleared while a run goes on (5);
#   · the rollback with the switch left on: RB says it and prints --redeploy; 5.18.96's tool refuses the key; a deploy
#     refuses at BR3 and names --redeploy; --check --redeploy is GO with BR3's note; --redeploy PASSES (6);
#   · statements kept on both cards: --after-only's BA1 sees them, --check-bank PASSES with both cards a figure — the
#     terminal's lines and the sheet's ids and dates this rehearsal's own (7);
#   · the rollback's two steps as printed: the switch cleared, 088's kept rows untouched; a deploy refuses at BR2 and names
#     --redeploy; a trigger dropped makes --redeploy refuse, and a copy that accepts it is caught; --redeploy PASSES with
#     every kept row unchanged; an --after-only FAILS BA1 over a kept line lost since the deploy (8);
#   · a rollback whose copy leaves 5.18.97's menu behind FAILS RB twice, a copy blind to it is caught; a --redeploy FAILS
#     BA1 over a kept line deleted while it runs, then rolled back; deployed again (9);
#   · nothing reaches Evolution; every observer quiet but on the weakened copies that trip one on purpose; no figure,
#     secret or ECO Bank name, number or ending in any output, log or state file (each sweep with its control); /tmp, the
#     host and the checkout as found (10).
#
# Everything runs in a sandbox; it never touches this checkout, the server or the network, and refuses to run where the
# server could be. Everything seeded is fictitious — every amount invented here, every name RH97, RH96 or RH-.
#
#   bash scripts/harness/deploy-5.18.97/rehearse.sh            REHEARSE_KEEP=<dir> keeps every run's full output
set -u
for f in /data/ucrm /opt/dishnet /var/run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
H97_DIR="$(cd "$(dirname "$0")" && pwd)"
H97_ROOT="$(cd "$H97_DIR/../../.." && pwd)"
F97=scripts/deploy-5.18.97.sh
# Before anything is built: the script must be pinned — to its release commit and to T1's count.
P97_EARLY="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT="\([^"]*\)".*/\1/p' "$H97_ROOT/$F97" 2>/dev/null)"
T1_EARLY97="$(sed -n 's/^T1_SIGNATURE="\([^"]*\)".*/\1/p' "$H97_ROOT/$F97" 2>/dev/null)"
case "$P97_EARLY" in __*|'') echo "refusing: $F97 is not pinned to a release commit (${P97_EARLY:-none})"; exit 2;; esac
case "$T1_EARLY97" in [1-9]*' passed, 0 failed rc=0') ;; *) echo "refusing: $F97 does not pin T1's count (T1_SIGNATURE=${T1_EARLY97:-none})"; exit 2;; esac
for h in rh97-oracle.php rh97-staff.php rh97-keep.php; do
  [ -f "$H97_DIR/$h" ] || { echo "refusing: this rehearsal's helper $h is not beside it"; exit 2; }
done
H96_HARNESS="$H97_DIR/../deploy-5.18.96/rehearse.sh"
H97_PREFIX="$(mktemp)"
python3 - "$H96_HARNESS" "$H97_PREFIX" <<'PY' || { echo "refusing: 5.18.96's rehearsal could not be cut after its --after-only and the quarantine's --check"; rm -f "$H97_PREFIX"; exit 2; }
import sys
src, dst = sys.argv[1], sys.argv[2]
anchor = 'echo; echo "== 96-5. the rollback, run exactly as the deploy\'s summary printed it — ROLLBACK typed — and deployed again =="'
lines = open(src, encoding='utf-8').read().split('\n')
at = [i for i, l in enumerate(lines) if l == anchor]
assert len(at) == 1, len(at)
assert at[0] > 1000, at[0]                  # 5.18.96's deploy, its --after-only and the quarantine's --check come before it
pre = '\n'.join(lines[:at[0]]) + '\n'
for need in ('H95_HARNESS=', 'run96() {', 'run_line96() {', 'fresh96() {', 'nogo96() {', 'trip96() {', 'audit96() {', 'hits96() {',
             'vault96() {', 'OUT96="$SB/out96"', 'STATE96="$OUT96/state-5.18.96.env"', 'R96="$SB/r96"', 'H96="$SB/rh96"',
             'SCAN96="$SB/tmpscan96.pat"', 'settle96() {', 'oracle96() {', 'release_variant96() {', 'copy_back96() {', 'data96x() {',
             'BOOKIDS="$(cd "$B96"', 'VAULT_IN96=', 'V_KINDS_OLD=', 'APPR_OLD=', 'TOOL_OLD=', 'R24A_OLD=',
             'echo; echo "== 96-3. the deploy, as the operator runs it — DEPLOY typed; a receipt posted between A23 and R23 =="',
             'echo; echo "== 96-4. --after-only, run exactly as the deploy\'s summary printed it; then its weakened copies =="',
             "4g the quarantine's own --check, as the runbook orders after this: PASSED with 5.18.96 live"):
    assert pre.count(need) >= 1, need
last = [l for l in pre.split('\n') if l.strip()][-1]
assert last == 'QREC96="$(sha256sum "$QREC" | cut -c1-16)"; KEPT96="$(kept_state)"', last[:80]   # the cut follows 4g's own record
open(dst, 'w', encoding='utf-8').write(pre)
PY
# shellcheck disable=SC1090
. "$H97_PREFIX"
rm -f "$H97_PREFIX"

# ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
# 5.18.97's own rehearsal, on the sandbox built above: 5.18.96 (e35ecce) live, its --after-only conclusive, the
# quarantine's --check passed after it.
# ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
REL97=release/5.18.97; DEPLOY97="$REPO/$F97"
FIX97=213c5ce0                                       # the change on the development branch (its tip, ECO Bank's seven commits)
DEV97_BASE=fae07c61                                  # …and the development branch before it
REDACT97=c60f5176                                    # the privacy redaction SAFETY.md carries (an ancestor of FIX97)
ADDED97="includes/post/post_bank.php lib/BankGate.php lib/BankStatementStore.php lib/EcobankDetailsCsv.php migrations/088_bank_statements.sql tabs/accounts/bank.php tests/fixtures/bank_statement_gen.php tests/test_bank_boundaries.php tests/test_bank_reader.php tests/test_bank_screen.php tests/test_bank_store.php"
WHOLE97="includes/post/post_bank.php lib/BankGate.php lib/BankStatementStore.php lib/EcobankDetailsCsv.php migrations/088_bank_statements.sql tabs/accounts/bank.php tests/fixtures/bank_statement_gen.php tests/test_bank_reader.php tests/test_bank_store.php SAFETY.md includes/navigation.php includes/post_handlers.php tabs/admin/ceo_dashboard.php tests/test_links_without_port.php"
PORT97="public.php includes/routes.php tools/set_config.php"          # 5.18.96's file with the development change's lines, no others
REPIN97="tests/test_bank_boundaries.php tests/test_bank_screen.php"    # the development text with its baseline pin 419e2ffd → e35eccee
PINS97="tests/test_distributor_apply.php tests/test_distributor_link_ucrm.php tests/test_distributor_notify.php tests/test_distributor_registry.php tests/test_distributor_territory.php"
N97=$(( $(wc -w <<<"$WHOLE97 $PORT97 $REPIN97 $PINS97") + 1 ))       # …and the manifest: 25
KEPT_FILES97="lib/CashbookService.php tabs/accounts/cashbook.php includes/post/post_cashbook.php tabs/accounts/accounts_dashboard.php cron/cashbook_summary.php tools/cash_in_hand.php tabs/sales/more_menu.php MANIFEST.md main.php cron/master.php cron_starlink_block_retry.php lib/StarlinkRetryScope.php tests/test_master_lock_release.php tests/test_main_auto_pull_retired.php migrations/087_wa_channels.sql lib/LegalContent.php lib/InstallationTerms.php profiles/uganda.json tests/test_legal_overrides.php lib/PdfLinkToken.php efris_pdf.php tabs/admin/efris.php cron_splynx_sync.php tabs/help/runbook.php tabs/admin/starlink_suspensions.php EMERGENCY_RECOVERY.md lib/StaffJobsGate.php lib/currency.php lib/PluginConfig.php lib/ConfigVault.php lib/TenantProfile.php lib/MigrationRunner.php lib/SqliteStore.php tests/test_cash_in_hand_p3b.php tests/test_cash_in_hand_p3b_mutants.php tests/test_cash_in_hand_p3b_screens.php tests/test_cash_in_hand_p3b_south_sudan.php tests/test_d1_bank_balance_readers.php tests/fixtures/staff_jobs_sandbox.php tests/fixtures/staff_jobs_scenario.php"
MIG97="migrations/088_bank_statements.sql"
if git -C "$R" ls-files --error-unmatch "$F97" >/dev/null 2>&1; then
  cmp -s "$R/$F97" "$DEPLOY97" || { echo "refusing: $F97 differs from the committed one — commit it, then rehearse"; exit 2; }
  WHICH97="the committed script"
else cp "$R/$F97" "$DEPLOY97"; WHICH97="the working copy (not yet committed; untracked in the clone)"; fi
git -C "$REPO" fetch -q origin "refs/heads/$REL97:refs/remotes/origin/$REL97" 2>/dev/null || true
P97="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT="\([^"]*\)".*/\1/p' "$DEPLOY97")"
T1SIG97="$(sed -n 's/^T1_SIGNATURE="\([^"]*\)".*/\1/p' "$DEPLOY97")"
T4SIG97="$(sed -n 's/^T4_SIGNATURE="\([^"]*\)".*/\1/p' "$DEPLOY97")"
MIG88_SHA97="$(sed -n 's/^MIG88_SHA256="\([0-9a-f]*\)".*/\1/p' "$DEPLOY97")"; MIG88_MD597="$(sed -n 's/^MIG88_MD5="\([0-9a-f]*\)".*/\1/p' "$DEPLOY97")"
OUT97="$SB/out97"; mkdir -p "$OUT97"; STATE97="$OUT97/state-5.18.97.env"
R97="$SB/r97"; mkdir -p "$R97/logs" "$R97/mutants" "$R97/sheets" "$R97/exec" "$R97/scan" "$R97/vault" "$R97/cmds"   # the sweeps' material (10)
: > "$R97/expected-trips"                            # the tags of the runs that trip an observer on purpose (weakened copies)
H97="$SB/rh97"; mkdir -p "$H97/v"; cp "$H97_DIR"/rh97-oracle.php "$H97_DIR"/rh97-staff.php "$H97_DIR"/rh97-keep.php "$H97/"
chmod 644 "$H97"/rh97-*.php; chown 1000:1000 "$H97/v"; chmod 700 "$H97/v"   # the helpers' own vaults and files, the store's user's
DATE_TS97='$(date -u +%Y%m%dT%H%M%SZ)'
LOGW97() { printf '2>&1 | ( umask 077; exec tee %s/%s-%s.log )' "$OUT97" "$1" "$DATE_TS97"; }   # the script's LOGW, as it prints it
EXP_AFTER97="cd $REPO && bash scripts/deploy-5.18.97.sh --after-only $(LOGW97 after)"
EXP_ROLLBACK97="cd $REPO && bash scripts/deploy-5.18.97.sh --rollback $(LOGW97 rollback)"
EXP_DEPLOY97="cd $REPO && bash scripts/deploy-5.18.97.sh $(LOGW97 deploy)"
EXP_REDEPLOY97="cd $REPO && bash scripts/deploy-5.18.97.sh --redeploy $(LOGW97 redeploy)"
EXP_CHECKBANK97="cd $REPO && bash scripts/deploy-5.18.97.sh --check-bank $(LOGW97 check-bank)"
EXP_PUTBACK97="( cd $REPO && git checkout $P96 && { bash scripts/deploy-hybrid.sh; git checkout -; } ) $(LOGW97 putback)"
PDD97="/data/ucrm/data/plugins/.$P-data"
DBO97="$(stat -c '%u:%g' "$DATA/plugin.sqlite3")"     # the database's owner, as the script asks the container
EXP_SWON97="docker exec -u $DBO97 -e DN_DATA_DIR=$PDD97 -w $IN_CONTAINER ucrm php tools/set_config.php --key bank_screen_enabled --value 1 2>&1 | sed '/^  AI SETTINGS\$/,\$d' | ( umask 077; exec tee $OUT97/switch-on-$DATE_TS97.log )"
EXP_SWCLR97="docker exec -u $DBO97 -e DN_DATA_DIR=$PDD97 -w $IN_CONTAINER ucrm php tools/set_config.php --key bank_screen_enabled --clear 2>&1 | sed '/^  AI SETTINGS\$/,\$d' | ( umask 077; exec tee $OUT97/switch-clear-$DATE_TS97.log )"
BK97=bank_screen_enabled

left_tmp97() { ls -d /tmp/dnb-5.18.97-* /tmp/dn-master-lock-* /tmp/dn_autopull_* 2>/dev/null | wc -l | tr -d ' '; }
TMP97="$(left_tmp97)"
host_code97() { ls -d "$OUT97"/code-* "$OUT97"/p3b-* "$OUT97"/br1-* "$OUT97"/domainb-* 2>/dev/null | wc -l | tr -d ' '; }
backups97() { ls -d "$OUT97"/backup-* 2>/dev/null | wc -l | tr -d ' '; }
states97() { ls -1 "$OUT97"/state-5.18.97.env* "$OUT97"/rollback-state-* 2>/dev/null | sort | while IFS= read -r f; do printf '%s %s\n' "${f##*/}" "$(sha256sum "$f" | cut -c1-16)"; done | sha256sum | cut -c1-16; }
fresh97() {   # the run folder as a new run finds it: the state files and the row digests kept; every log a printed command wrote
              # and every cash sheet moved aside for the sweeps (10)
  find "$OUT97" -mindepth 1 -maxdepth 1 -name '*.log' -exec mv -t "$R97/logs/" {} + 2>/dev/null
  find "$OUT97" -mindepth 1 -maxdepth 1 -name 'cash-*.txt' -exec mv -t "$R97/sheets/" {} + 2>/dev/null
  find "$OUT97" -mindepth 1 -maxdepth 1 ! -name 'state-5.18.97.env*' ! -name 'rollback-state-*' ! -name 'cih-rows-*' -exec rm -rf {} +; base_log; }
installed97() {   # installed97 COMMIT — the number of files 5.18.96 → 5.18.97 touches that are NOT installed as COMMIT has them
  local f rel bad=0
  for f in $(dl AM "$P96" "$P97"); do
    rel="${f#$P/}"
    if git -C "$REPO" cat-file -e "$1:$f" 2>/dev/null; then
      [ "$(git -C "$REPO" show "$1:$f" | sha256sum | cut -c1-64)" = "$(sha256sum "$PD/$rel" 2>/dev/null | cut -c1-64)" ] || bad=$((bad+1))
    fi
  done
  echo "$bad"
}
last97() { printf '%03d' "$(cat "$SB/runs")"; }   # the tag of the last run
run97() {   # [--answer WORD] [--script FILE] [--env K=V]… [--today YYYYMMDD], then the script's options — as run96, this
            # rehearsal's folders and observers
  local answer="" script="$DEPLOY97" extra=() today="$AFTER94" n tag o cmd
  while :; do case "${1:-}" in --answer) answer="$2"; shift 2 ;; --script) script="$2"; shift 2 ;; --env) extra+=("$2"); shift 2 ;;
    --today) today="$2"; shift 2 ;; *) break ;; esac; done
  : > "$SB/docker.log"
  n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"; tag="$(printf '%03d' "$n")"
  vt_host > "$R97/vault/run-$tag.before"; : > "$R97/exec/run-$tag.rec"; : > "$R97/scan/run-$tag.hits"
  cmd="$(printf '%q ' env PATH="$SB/datebin:$SB/bin:$PATH" FAKE_TODAY="$today" DNB_OUT="$OUT97" DNB_RECORDS="$RECS" DNB_WEBSITE="$WEB_OK" GUARD_SECONDS=3 DN_DATA_DIR="$DATA" \
         FAKE_EXEC_LOG="$R97/exec/run-$tag.rec" FAKE_TMP_SCAN="$SCAN96" FAKE_TMP_HITS="$R97/scan/run-$tag.hits" ${extra[@]+"${extra[@]}"} bash "$script" --plugin-base "$PLUGIN_BASE" "$@")"
  o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r')"
  vt_host > "$R97/vault/run-$tag.after"
  case "$o" in *"unknown option:"*) echo "run97-$n" >> "$SB/bad-runs" ;; esac
  if [ -n "${R97_MUTANT_OUT:-}" ]; then printf '%s\n' "$o" > "$R97/mutants/run-$tag.txt"; else printf '%s\n' "$o" > "$R97/run-$tag.txt"; fi
  [ -n "${REHEARSE_KEEP:-}" ] && printf '%s\n' "$o" > "$REHEARSE_KEEP/run97-$tag-${answer:-none}-$(basename "$script" .sh)$(printf '%s' "$*" | tr -c 'A-Za-z0-9-' '_').txt"
  printf '%s' "$o"
}
run_line97() {   # run_line97 WORD LINE — a command line exactly as a summary printed it, through a pseudo-terminal, WORD typed
  local answer="$1" line="$2" n tag o cmd
  : > "$SB/docker.log"
  n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"; tag="$(printf '%03d' "$n")"
  vt_host > "$R97/vault/run-$tag.before"; : > "$R97/exec/run-$tag.rec"; : > "$R97/scan/run-$tag.hits"
  cmd="$(printf '%q ' env PATH="$SB/datebin:$SB/bin:$PATH" FAKE_TODAY="$AFTER94" DNB_OUT="$OUT97" DNB_RECORDS="$RECS" DNB_WEBSITE="$WEB_OK" GUARD_SECONDS=3 DN_DATA_DIR="$DATA" PLUGIN_BASE="$PLUGIN_BASE" \
         FAKE_EXEC_LOG="$R97/exec/run-$tag.rec" FAKE_TMP_SCAN="$SCAN96" FAKE_TMP_HITS="$R97/scan/run-$tag.hits" bash -c "$line")"
  o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r')"
  vt_host > "$R97/vault/run-$tag.after"
  case "$o" in *"unknown option:"*) echo "line97-$n" >> "$SB/bad-runs" ;; esac
  printf '%s\n' "$o" > "$R97/line-$tag.txt"
  [ -n "${REHEARSE_KEEP:-}" ] && printf '%s\n' "$o" > "$REHEARSE_KEEP/line97-$tag-${answer:-none}.txt"
  printf '%s' "$o"
}
run_cmd97() {   # run_cmd97 LINE — a printed command that asks nothing (ECO Bank's switch), run as root exactly as printed; only
                # the sandbox's docker on the path. Not a run of the deploy script: no observer is asked for
  local o n; n=$(( $(cat "$SB/cmds97" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/cmds97"
  o="$(env PATH="$SB/datebin:$SB/bin:$PATH" FAKE_TODAY="$AFTER94" bash -c "$1" 2>&1 </dev/null)"
  printf '%s\n' "$o" > "$R97/cmds/cmd-$(printf '%03d' "$n").txt"
  [ -n "${REHEARSE_KEEP:-}" ] && printf '%s\n' "$o" > "$REHEARSE_KEEP/cmd97-$(printf '%03d' "$n").txt"
  printf '%s' "$o"
}
went97() { echo "$(has "$1" 'GO — evidence recorded')$(has "$1" 'Type DEPLOY')"; }   # the run reached the question
quiet97() { echo "$(live)|$(backups97)|$(data94)|$(kept_state)|$(oldname)"; }      # nothing deployed, backed up or changed
nogo97() {   # nogo97 LABEL EXPECTED-TEXT OUTPUT [DATA-DIGEST] [LIVE] — refused with that text and "Nothing was changed", never the
             # question, nothing deployed, backed up or changed
  check "$(has "$3" "$2")$(has "$3" 'Nothing was changed. Send the log file.')$(went97 "$3")|$(quiet97)" "yesyesnono|${5:-$P96}|0|${4:-$D97}|$KEPT97|0" "$1"
}
checkgo97() {   # checkgo97 OUTPUT — --check said GO: stage A held, nothing asked, nothing backed up, no state written
  echo "$(has "$1" "  5.18.97 (check): GO — stage A holds.")$(has "$1" '  --check           stage A alone, every refusal and note of a deploy')$(went97 "$1")$(fails "$1")|$(backups97)|$(states97)"
}
trip97() { printf '%s\n' "$(last97)" >> "$R97/expected-trips"; }   # the last run trips an observer on purpose (10); one tag a line
audit97() { python3 "$H97/rh97.py" audit "$R97/exec/run-$1.rec"; }  # audit97 TAG — copies=<n> novault=<n> plugin=<n> adj=<id|->
hits97() { local n; n="$(grep -c . "$R97/scan/run-$1.hits" 2>/dev/null)"; echo "${n:-0}"; }
vault97() { echo "$(cat "$R97/vault/run-$1.before")>$(cat "$R97/vault/run-$1.after")"; }
printed97() {   # printed97 OUTPUT — the summary's commands: how many | how many without their own log wrapper | how many lines of
                # the whole output hold two commands — by their command forms (bash scripts/…, php tools/set_config.php), never
                # by a name in words: R6's ok line names set_config.php three times
  local s; s="$(sed -n '/^== F\. Summary/,$p' <<<"$1")"
  echo "$(grep -cE '^      (cd |\( |docker exec )' <<<"$s")|$(grep -E '^      (cd |\( |docker exec )' <<<"$s" | grep -vcE ' \| \( umask 077; exec tee | \| tee ')|$(grep -cE '(bash scripts/deploy-5\.18\.97\.sh|bash scripts/quarantine-stray-store\.sh|php tools/set_config\.php).*(bash scripts/deploy-5\.18\.97\.sh|bash scripts/quarantine-stray-store\.sh|php tools/set_config\.php)' <<<"$1")"
}
rb_where97() {   # rb_where97 OUTPUT — each line naming the rollback command: before or after the run's verdict
  awk '/^  5\.18\.97 \((deploy|after)\): / { v = 1 } index($0, "deploy-5.18.97.sh --rollback") { print (v ? "after" : "before") }' <<<"$1" | paste -sd, -
}
order97() {   # order97 OUTPUT — the verdict, ECO Bank's two commands and the rollback's, in the order printed
  RH_OUT="$1" RH_P="$P96" python3 - <<'PY'
import os, re
t = os.environ['RH_OUT'].split('\n'); p = os.environ['RH_P']
pats = [('verdict', re.compile(r'^  5\.18\.97 \((deploy|after)\): PASSED')),
        ('switch-on', re.compile(r'^      docker exec .*tools/set_config\.php --key bank_screen_enabled --value 1 ')),
        ('check-bank', re.compile(r'^      cd .*deploy-5\.18\.97\.sh --check-bank ')),
        ('clear', re.compile(r'^      docker exec .*tools/set_config\.php --key bank_screen_enabled --clear ')),
        ('rollback', re.compile(r'^      cd .*deploy-5\.18\.97\.sh --rollback ')),
        ('putback', re.compile(r'^      \( cd .* && git checkout ' + re.escape(p) + ' '))]
seen = sorted((i, n) for i, l in enumerate(t) for n, rx in pats if rx.search(l))
names = [n for _, n in seen]
print('<'.join(names) if len(set(names)) == len(names) else 'repeated:' + ','.join(names))
PY
}
hdr97() {   # hdr97 SCRIPT — the header's command block: the deploy with its own log | anything that is not it | the --after-only with its own
  local h; h="$(sed -n '/^# Run as root/,/^# The rollback is a separate command/p' "$1")"
  echo "$(grep -cF 'bash scripts/deploy-5.18.97.sh 2>&1 | ( umask 077; exec tee /root/dnb-5.18.97/deploy-$(date -u +%Y%m%dT%H%M%SZ).log )' <<<"$h")$(grep -cE -- '--rollback|--put-back|--redeploy|--check-bank|set_config|git checkout [0-9a-f]{7}' <<<"$h")$(grep -cF 'bash scripts/deploy-5.18.97.sh --after-only 2>&1 | ( umask 077; exec tee /root/dnb-5.18.97/after-$(date -u +%Y%m%dT%H%M%SZ).log )' <<<"$h")"
}
# The nine commands the script prints for a later step, as it must define them — each with its own log wrapper; the
# quarantine's --check with its own tee. Read as whole lines from a quoted block, never expanded here.
DEFS97="$SB/defs97.txt"
cat > "$DEFS97" <<'DEFS'
AFTER_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --after-only $(LOGW after)"
ROLLBACK_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --rollback $(LOGW rollback)"
DEPLOY_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh $(LOGW deploy)"
PUTBACK_CMD="( cd $REPO && git checkout $BASELINE_COMMIT && { bash scripts/deploy-hybrid.sh; git checkout -; } ) $(LOGW putback)"
QCHECK_CMD="( umask 077 && cd $REPO && bash scripts/quarantine-stray-store.sh --check 2>&1 | tee $Q_DIR/check-\$(date -u +%Y%m%dT%H%M%SZ).log )"   # the quarantine's own form (root docs/68)
REDEPLOY_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --redeploy $(LOGW redeploy)"
CHECKBANK_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --check-bank $(LOGW check-bank)"
SWITCH_ON_CMD="docker exec -u $DB_OWNER -e DN_DATA_DIR=$PDD_IN -w $IN_CONTAINER $CONTAINER php tools/set_config.php --key $BK_KEY --value 1 2>&1 | sed '/^  AI SETTINGS\$/,\$d' $(LOGW switch-on | sed 's/^2>&1 //')"
SWITCH_CLEAR_CMD="docker exec -u $DB_OWNER -e DN_DATA_DIR=$PDD_IN -w $IN_CONTAINER $CONTAINER php tools/set_config.php --key $BK_KEY --clear 2>&1 | sed '/^  AI SETTINGS\$/,\$d' $(LOGW switch-clear | sed 's/^2>&1 //')"
DEFS
cmds97() {   # cmds97 SCRIPT — each of the nine definitions once (1 each) | the lines that print a command (printf, echo) | how many of
             # them name one of the nine | how many carry the wrapper themselves
  local f="$1" l d=""
  while IFS= read -r l; do d="$d$(grep -cxF -- "$l" "$f")"; done < "$DEFS97"
  printf '%s|%s|%s|%s|%s\n' "$d" "$(grep -cF "printf '\n      %s'" "$f")" "$(grep -cF 'echo "      $' "$f")" \
    "$(grep -cE "(printf '\\\\n      %s' \"\\\$|echo \"      \\\$)(AFTER_CMD|ROLLBACK_CMD|DEPLOY_CMD|QCHECK_CMD|PUTBACK_CMD|REDEPLOY_CMD|CHECKBANK_CMD|SWITCH_ON_CMD|SWITCH_CLEAR_CMD)\"" "$f")" \
    "$(grep -F "printf '\n      %s'" "$f" | grep -cF '$(LOGW ')"
}
ctl97() {   # ctl97 OUTPUT A22|R23 — of 5.18.95's seven checks, how many name exactly CTL95's defects for 5.18.94's copy, the control
  local out="$1" n=0 x
  for x in a b c d e f g; do
    case "$2" in
      A22) grep -qxF -- "$(printf '  %-15s the pin: %s   ·   %s (%s, the control): %s' "fix A22$x" ok "$P94" 5.18.94 "${CTL95[$x]}")" <<<"$out" \
             && [ "$(okline "$out" "A22$x" "— in the pin, kept by 5.18.97; 5.18.94's copy, the control, fails the same check (${CTL95[$x]})")" = yes ] && n=$((n+1)) ;;
      R23) [ "$(okline "$out" "R23$x" 'installed, kept by 5.18.97:' "— 5.18.94's copy, the control, fails the same check (${CTL95[$x]})")" = yes ] && n=$((n+1)) ;;
    esac
  done
  echo "$n"
}
noteids97() { grep -E '^  note  ' <<<"$1" | awk '{print $2}' | LC_ALL=C sort -u | paste -sd' ' -; }
copy_back97() {   # the 5.18.97 copy N minutes ago: public.php's time (its stamp_copy, COPY_MARK), the recorded copy time, the start before it
  touch -d "-$1 minutes" "$PD/public.php"; local t; t="$(stat -c %Y "$PD/public.php")"
  sed -i -e "s/^COPY_AT=.*/COPY_AT=$t/" -e "s/^DEPLOYED_AT=.*/DEPLOYED_AT=$(date -u -d "@$(( t - 60 ))" +%Y-%m-%dT%H:%M:%SZ)/" "$STATE97"; }
release_variant97() {   # release_variant97 PATH OLD NEW — the release commit with one file edited (OLD exactly once), cut on 5.18.96
  local idx="$SB/variant97.idx" blob tree c
  GIT_INDEX_FILE="$idx" git -C "$REPO" read-tree "$P97"
  blob="$(git -C "$REPO" show "$P97:$P/$1" | python3 -c 'import sys
s = sys.stdin.read(); old, new = sys.argv[1], sys.argv[2]
assert s.count(old) == 1, "variant anchor not unique"
sys.stdout.write(s.replace(old, new))' "$2" "$3" | git -C "$REPO" hash-object -w --stdin)"
  [ "$blob" = "$(printf '' | git -C "$REPO" hash-object --stdin)" ] && echo "release_variant97:$1" >> "$SB/anchor-missing"
  GIT_INDEX_FILE="$idx" git -C "$REPO" update-index --cacheinfo "100644,$blob,$P/$1"
  tree="$(GIT_INDEX_FILE="$idx" git -C "$REPO" write-tree)"; rm -f "$idx"
  c="$(git -C "$REPO" -c user.name=rehearsal -c user.email=rehearsal@example.test commit-tree "$tree" -p "$(git -C "$REPO" rev-parse "$P96")" -m "rehearsal variant: $1")"
  git -C "$REPO" rev-parse --short "$c"
}
data97x() { data_dump | grep -v '^master_schedule:' | grep -v '^cb_ledger:' | grep -v '^bank_' | sha256sum | cut -c1-16; }   # every table but the book, master's record and 088's
book97x() {   # the book's rows, every column, but the stand-in's receipt — the book as the deploy found it
  php -r '$p = new PDO("sqlite:" . $argv[1]); echo substr(hash("sha256", json_encode($p->query("SELECT * FROM cb_ledger WHERE description <> \x27RH97 receipt posted while the deploy ran\x27 ORDER BY id")->fetchAll(PDO::FETCH_NUM))), 0, 16);' "$DATA/plugin.sqlite3"; }
oracle97() { php "$H97/rh97-oracle.php" "$DATA/plugin.sqlite3"; }   # ECO Bank as this rehearsal reads the live store, read-only (JSON)
o97() { local f="$H97/o97-$(date +%s%N).json"; oracle97 > "$f"; printf '%s' "$f"; }   # a reading kept in a file of its own; prints its path
cash97() { local f="$H97/cash-$1.json"; oracle96 > "$f"; printf '%s' "$f"; }   # the cash cells as 5.18.96's rehearsal reads them, kept for the sweeps
forms97() { python3 "$H96/rh96.py" forms "$SB/forms97.txt" "$SB/seed96.amounts" "$SB/seed97.amounts" "$SB/stmt97.amounts" "$H97"/cash-*.json "$R97"/sheets/cash-*.txt "$OUT97"/cash-*.txt >/dev/null 2>&1; }
figs97() { forms97; python3 "$H96/rh96.py" hits "$SB/forms97.txt" "$@"; }   # figs97 FILE… — how many hold a figure, in any printed form
BANKPAT97="$SB/bank97.pat"   # what the script must never print of ECO Bank: the accounts' names, numbers and endings, the holder, the staff
printf '%s\n' 'Ecobank UGX' 'Ecobank USD' 'Bank 7000000001' '7000000001' 'RH-Account Holder One' 'RH-CLIENT' '0000000001' '0000000002' \
  'RH97 Admin' 'RH97 Former Admin' 'RH97 Inactive Admin' 'rh97-admin@example.test' > "$BANKPAT97"
bankhits97() { local n=0 f; for f in "$@"; do [ -f "$f" ] && grep -qF -f "$BANKPAT97" -- "$f" && n=$((n+1)); done; echo "$n"; }
pin_only97() {   # pin_only97 FILE — the release's file is 5.18.96's with every 5.18.96 → 5.18.97 and nothing else
  python3 - <(git -C "$REPO" show "$P96:$P/$1") <(git -C "$REPO" show "$P97:$P/$1") <<'PY'
import sys
a, b = open(sys.argv[1]).read(), open(sys.argv[2]).read()
print('pin' if a.count('5.18.96') >= 1 and a.replace('5.18.96', '5.18.97') == b and a != b else 'other')
PY
}
port97() {   # port97 FILE — the release's change to it is the development change's, line for line: the sorted +/- lines equal; prints how many
  python3 - <(git -C "$REPO" diff -U0 "$P96" "$P97" -- "$P/$1") <(git -C "$REPO" diff -U0 "$DEV97_BASE" "$FIX97" -- "$P/$1") <<'PY'
import sys
def ch(p):
    return sorted(l for l in open(p).read().split('\n') if l[:1] in ('+', '-') and not l.startswith(('+++', '---')))   # '' is in '+-': no empty line counted
a, b = ch(sys.argv[1]), ch(sys.argv[2])
print('%d:%s' % (len(a), 'same' if a == b and a else 'differs'))
PY
}
repin97() {   # repin97 FILE — the release's file is the development branch's with its baseline pin 419e2ffd → e35eccee and nothing else
  python3 - <(git -C "$REPO" show "$FIX97:$P/$1") <(git -C "$REPO" show "$P97:$P/$1") <<'PY'
import sys
a, b = open(sys.argv[1]).read(), open(sys.argv[2]).read()
print('%d:%s' % (a.count('419e2ffd'), 'repin' if a.count('419e2ffd') >= 1 and a.replace('419e2ffd', 'e35eccee') == b else 'other'))
PY
}

# ── This rehearsal's readers: 5.18.96's, derived by asserted replacements — its audit taught this release's folders, the
# gate's code copies (k-found, k-off, k-on) and a value-taking -w — and four readers of ECO Bank's lines added. ─────────
python3 - "$H96/rh96.py" "$H97/rh97.py" <<'PY' || echo "readers:rh97" >> "$SB/anchor-missing"
import sys
s = open(sys.argv[1]).read()
reps = [
    ("# 5.18.96's rehearsal: readers of what the runs leave",
     "# 5.18.97's rehearsal (the previous release's readers, by asserted replacements): readers of what the runs leave"),
    ("root = re.compile(r'^/tmp/dnb-5\\.18\\.96-(?:cih-[^/]+/[^/]+/(?:t|d|p0root)/dishnet-hybrid-sudan|code-[^/]+/p3b/[^/]+/dishnet-hybrid-sudan)(?:/|$)')",
     "root = re.compile(r'^/tmp/dnb-5\\.18\\.97-(?:cih-[^/]+/[^/]+/(?:t|d|p0root|k-(?:found|off|on))/dishnet-hybrid-sudan|code-[^/]+/p3b/[^/]+/dishnet-hybrid-sudan)(?:/|$)')"),
    ("if not env.get('DN_VAULT_FILE', '').startswith('/tmp/dnb-5.18.96-'): novault += 1",
     "if not env.get('DN_VAULT_FILE', '').startswith('/tmp/dnb-5.18.97-'): novault += 1"),
    ("            if a[i] in ('-u', '-e'):", "            if a[i] in ('-u', '-e', '-w'):"),
]
for old, new in reps:
    assert s.count(old) == 1, old[:70]
    s = s.replace(old, new)
assert s.count('5.18.96') == 0 and s.count('5\\.18\\.96') == 0, 'a 5.18.96 path is left'
s += '''elif cmd == 'ecosheet':                  # ECO Bank's account lines on a sheet, in order: id:cur/named/kept/linked:date
    rx = re.compile(r'^    account #([0-9]+)  ([A-Z]{3})  named Ecobank: (yes|no)  a statement kept: (yes|no)  linked: (yes|no)  last kept line: (none|[0-9]{4}-[0-9]{2}-[0-9]{2})$')
    out = ['%s:%s/%s/%s/%s:%s' % m.groups() for m in (rx.match(l) for l in read(sys.argv[2]).split('\\n')) if m]
    print(';'.join(out) if out else '-')
elif cmd == 'ecoexp':                    # the same from this rehearsal's oracle, N times over (one block per reading)
    o = json.loads(read(sys.argv[2])); n = int(sys.argv[3])
    ids = [x for x in o['ids'].split(',') if x]; lst = [x for x in o['list'].split(';') if x]
    last = dict(x.split(':', 1) for x in o['last'].split(',') if x)
    one = ['%s:%s:%s' % (i, l, last.get(i, 'none')) for i, l in zip(ids, lst)]
    print(';'.join(one * n) if one else '-')
elif cmd == 'ecoterm':                   # --check-bank's lines on the terminal, in order: cur/named/kept/linked/age-in-days
    rx = re.compile(r'^  account ([0-9]+) of ([0-9]+)  ([A-Z]{3})  named Ecobank: (yes|no)  a statement kept: (yes|no)  linked: (yes|no)  last kept line: (none|([0-9]+) day\\(s\\) old)$')
    out = []
    for l in read(sys.argv[2]).split('\\n'):
        m = rx.match(l)
        if m: out.append('%s/%s/%s/%s/%s' % (m.group(3), m.group(4), m.group(5), m.group(6), m.group(8) or '-'))
    print(';'.join(out) if out else '-')
elif cmd == 'ecotermexp':                # the same from the oracle: each last kept line's age in days, today in UTC
    import datetime
    o = json.loads(read(sys.argv[2]))
    ids = [x for x in o['ids'].split(',') if x]; lst = [x for x in o['list'].split(';') if x]
    last = dict(x.split(':', 1) for x in o['last'].split(',') if x)
    today = datetime.datetime.now(datetime.timezone.utc).date()
    out = ['%s/%s' % (l, (today - datetime.date.fromisoformat(last[i])).days if i in last else '-') for i, l in zip(ids, lst)]
    print(';'.join(out) if out else '-')
'''
open(sys.argv[2], 'w').write(s)
PY
check "$(python3 -c 'import ast, sys; ast.parse(open(sys.argv[1]).read()); print("parses")' "$H97/rh97.py" 2>&1)$(for f in "$H97"/rh97-*.php; do php -l "$f" 2>&1 | grep -c '^No syntax errors'; done | tr -d '\n')" "parses111" \
  "control: this rehearsal's readers (5.18.96's, derived) and its three PHP helpers parse"

echo; echo "== 97-pre. the sandbox as production will be: 5.18.96 live; ECO Bank's ground; the observers taught this release's folders =="
check "$(live)|$(inst_ver)|$(grep -c '^STRAY_Q_FAILED=' "$STATE96")|$(sed -n 's/^COPY_AT=\([0-9][0-9]*\)$/\1/p' "$STATE96" | tail -1 | grep -c .)" "$P96|5.18.96|0|1" \
  "pre-a 5.18.96 ($P96) is live and its state file holds its copy's time and no failure — production's state once 5.18.96's runbook is done (the quarantine's --check after it, 96-4g, passed above)"
[ -e "$RECS/dnb-5.18.96" ] || ln -s "$OUT96" "$RECS/dnb-5.18.96"
check "$(readlink -f "$RECS/dnb-5.18.96")|$(readlink -f "$RECS/dnb-5.18.95")|$(readlink -f "$RECS/dnb-5.18.94")" "$(readlink -f "$OUT96")|$(readlink -f "$OUT95")|$(readlink -f "$OUT94")" \
  "pre-b 5.18.96's, 5.18.95's and 5.18.94's records where 5.18.97's A14 looks for them (<records>/dnb-5.18.9x), as on the server"

# ── The fake docker learns -w, which ECO Bank's switch commands carry (docker exec -w <the plugin folder> … php
# tools/set_config.php): the folder in the container's own paths, mapped as every /data/… argument is; a folder that is
# not there refused as the runtime refuses it. Asserted, with a control. ───────────────────────────────────────────────
python3 - "$SB/bin/docker" "$MOUNT" <<'PY' || echo "fakedocker:workdir97" >> "$SB/anchor-missing"
import sys
p, mount = sys.argv[1], sys.argv[2]
s = open(p).read()
assert s.count('wdir') == 0
old = '  -u) user="$2"; shift 2 ;;\n'
assert s.count(old) == 1, s.count(old)
s = s.replace(old, old + '  -w) wdir="$2"; case "$wdir" in /data/*) wdir="' + mount + '/${wdir#/data/}" ;; esac; shift 2 ;;   # 5.18.97\'s rehearsal: the switch commands\' working folder\n', 1)
old2 = 'row "$1" >/dev/null || { echo "Error: No such container: $1" >&2; exit 1; }\n'
assert s.count(old2) == 1, s.count(old2)
s = s.replace(old2, old2 + 'if [ -n "${wdir:-}" ]; then cd "$wdir" 2>/dev/null || { echo "OCI runtime exec failed: chdir to cwd (\\"$wdir\\") set in config.json failed: no such file or directory" >&2; exit 126; }; fi\n', 1)
assert s.count('wdir') == 7, s.count('wdir')
open(p, 'w').write(s)
PY
check "$(env PATH="$SB/bin:$PATH" bash "$SB/bin/docker" exec -w "$IN_CONTAINER" ucrm pwd 2>&1)|$(env PATH="$SB/bin:$PATH" bash "$SB/bin/docker" exec -w /data/rh97-not-there ucrm pwd >/dev/null 2>&1; echo $?)" "$PD|126" \
  "control: the fake docker runs a command in the folder -w names, mapped from the container's paths, and refuses one that is not there"
# ── The observers search this release's folders in /tmp too: its cash folders, its synthetic books, its code copies and
# T1's and T4's run folders (the hook's one list, extended, asserted). ─────────────────────────────────────────────────
python3 - "$SB/bin/docker" <<'PY' || echo "fakedocker:observers97" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]; s = open(p).read()
old = '  for _rh_d in /tmp/dnb-5.18.96-cih-* /tmp/dnb-5.18.96-ss-* /tmp/dnb-5.18.96-code-*/p3b /tmp/dnb-5.18.96-code-*/t1run /tmp/dnb-5.18.96-code-*/t4run; do\n'
assert s.count(old) == 1, s.count(old)
new = old.replace('; do\n', ' /tmp/dnb-5.18.97-cih-* /tmp/dnb-5.18.97-ss-* /tmp/dnb-5.18.97-code-*/p3b /tmp/dnb-5.18.97-code-*/t1run /tmp/dnb-5.18.97-code-*/t4run; do\n')
s = s.replace(old, new, 1)
assert s.count('/tmp/dnb-5.18.97-') == 5 and s.count(old) == 0
open(p, 'w').write(s)
PY
mkdir -p /tmp/dnb-5.18.97-cih-rhcontrol; printf 'rh97-observer-control\n' > /tmp/dnb-5.18.97-cih-rhcontrol/webhook_secret
printf 'rh97-observer-control\n' > "$SB/obs97.pat"
env PATH="$SB/bin:$PATH" FAKE_EXEC_LOG="$SB/obs97.rec" FAKE_TMP_SCAN="$SB/obs97.pat" FAKE_TMP_HITS="$SB/obs97.hits" bash "$SB/bin/docker" exec -u 1000:1000 -e DN_DATA_DIR=/tmp/dnb-5.18.97-cih-x/a23-1/k-on-data ucrm php -r 'echo 1;' on /tmp/dnb-5.18.97-cih-x/a23-1/k-on/dishnet-hybrid-sudan >/dev/null 2>&1
check "$(grep -c '^value /tmp/dnb-5.18.97-cih-rhcontrol/webhook_secret$' "$SB/obs97.hits")$(grep -c '^file /tmp/dnb-5.18.97-cih-rhcontrol/webhook_secret$' "$SB/obs97.hits")|$(python3 "$H97/rh97.py" audit "$SB/obs97.rec")" "11|copies=1 novault=1 plugin=0 adj=-" \
  "control: the observers see this release's folders — a planted value and a file named webhook_secret in a run's folder in /tmp, and a gate process on an ECO Bank code copy (k-on) with no vault of its own"
rm -rf /tmp/dnb-5.18.97-cih-rhcontrol "$SB/obs97.pat" "$SB/obs97.rec" "$SB/obs97.hits"
# …and the audit counts what runs code on this release's copies, not 5.18.96's paths and not a command in the plugin folder:
# a gate process with a vault of its own, one without, a 5.18.96 copy, 088's reading through the pin's copy, the switch
# command (-w takes a value), and a gate process with no vault run with -w — counted only when -w is read as taking a
# value (the control on that edit) — written as the fake docker records them.
{ for c in \
    "exec|-u|1000:1000|-e|DN_VAULT_FILE=/tmp/dnb-5.18.97-cih-x/a23-1/k-found-vault.json|-e|DN_DATA_DIR=/tmp/dnb-5.18.97-cih-x/a23-1/k-found-data|ucrm|php|-d|display_errors=stderr|-r|echo 1;|found|/tmp/dnb-5.18.97-cih-x/a23-1/k-found/dishnet-hybrid-sudan|/tmp/dnb-5.18.97-cih-x/a23-1/k-found-data|/tmp/dnb-5.18.97-cih-x/" \
    "exec|-u|1000:1000|-e|DN_DATA_DIR=/tmp/dnb-5.18.97-cih-x/a23-1/k-on-data|ucrm|php|-d|display_errors=stderr|-r|echo 1;|on|/tmp/dnb-5.18.97-cih-x/a23-1/k-on/dishnet-hybrid-sudan|/tmp/dnb-5.18.97-cih-x/a23-1/k-on-data|/tmp/dnb-5.18.97-cih-x/" \
    "exec|-u|1000:1000|-e|DN_VAULT_FILE=/tmp/dnb-5.18.96-cih-x/a23-1/t-vault.json|ucrm|php|-r|echo 1;|/tmp/dnb-5.18.96-cih-x/a23-1/t/dishnet-hybrid-sudan" \
    "exec|-u|1000:1000|-e|DN_VAULT_FILE=/tmp/dnb-5.18.97-code-x/bank-vault-none.json|ucrm|php|-d|display_errors=stderr|-r|echo 1;|/data/ucrm/data/plugins/.$P-data/plugin.sqlite3|/tmp/dnb-5.18.97-code-x/p3b/pin/dishnet-hybrid-sudan|salt|tables" \
    "exec|-u|1000:1000|-e|DN_DATA_DIR=$PDD97|-w|$IN_CONTAINER|ucrm|php|tools/set_config.php|--key|bank_screen_enabled|--value|1" \
    "exec|-u|1000:1000|-w|$IN_CONTAINER|ucrm|php|-r|echo 1;|/tmp/dnb-5.18.97-cih-x/a23-1/k-on/dishnet-hybrid-sudan"; do
    printf '%s' "$c" | tr '|' '\037'; printf '\037\036'; done; } > "$SB/obs97b.rec"
check "$(python3 "$H97/rh97.py" audit "$SB/obs97b.rec")" "copies=4 novault=2 plugin=0 adj=-" \
  "control: the audit counts the gate processes on ECO Bank's code copies and 088's reading through the pin's copy (two of them without a vault — one run with -w, seen only because -w is read as taking a value), and neither a 5.18.96 path nor the switch command run in the plugin folder"
rm -f "$SB/obs97b.rec"

# ── The stand-in public.php learns four more triggers, each spent once: a receipt posted through the INSTALLED code's own
# writer while a run goes on (.add_receipt97) — at the first request, which in a deploy is V's, after A23 and before R23;
# 5.18.97's menu left behind by a rollback's copy (.left97, naming the file to copy) — at the first request once
# 5.18.96's manifest is installed again (9); ECO Bank's screen answering a visitor with no session with a card
# (.leak_bank97, BA4: 4); and a kept statement line deleted while a run goes on, its no-delete trigger dropped and put
# back with its own SQL text (.del_line97, BA1: 9). Asserted, as every edit here. ─────────────────────────────────────────────
python3 - "$SB/web/public.php" <<'PY' || echo "standin:triggers97" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]; s = open(p).read()
old = "$page = $_GET['page'] ?? '';\n"
assert s.count(old) == 1, s.count(old)
new = """if (($_GET['page'] ?? '') === 'rh97_probe') { echo 'rh97-triggers-ready'; exit; }
if ($spend('.add_receipt97')) {   // 5.18.97's rehearsal: a receipt posted through the INSTALLED code's own writer while a run goes on (R23's drift)
    putenv('DN_VAULT_FILE=' . $web . '/.rh97-receipt-vault.json');   // never the live vault
    require_once $root . '/lib/CashbookService.php';
    (new CashbookService($store, $dataDir))->addEntryRaw(['project' => 'dishnet', 'date' => gmdate('Y-m-d'), 'direction' => 'in', 'amount' => 314159.27,
        'currency' => 'UGX', 'category' => 'Receipt', 'category_raw' => 'Receipt', 'description' => 'RH97 receipt posted while the deploy ran',
        'status' => 'approved', 'approved_by' => 'RH97 Approver', 'source' => 'manual', 'account_id' => 0]);
    putenv('DN_VAULT_FILE');
}
$left97 = is_file($web . '/.left97') ? trim((string)file_get_contents($web . '/.left97')) : '';
if ($left97 !== '' && strpos((string)@file_get_contents($root . '/manifest.json'), '"5.18.96"') !== false && $spend('.left97')) {
    copy($left97, $root . '/includes/navigation.php');   // 5.18.97's rehearsal: 5.18.97's menu left behind by a rollback's copy (RB)
}
if (($_GET['page'] ?? '') === 'dashboard' && ($_GET['tab'] ?? '') === 'bank' && !isset($_GET['bk_export']) && $spend('.leak_bank97')) {
    echo '<html><body><div class="bk-card">leaked</div></body></html>'; exit;   // 5.18.97's rehearsal: ECO Bank's screen to a visitor with no session (BA4)
}
if ($spend('.del_line97')) {   // 5.18.97's rehearsal: a kept statement line deleted while a run goes on, its no-delete trigger put back as it was (BA1)
    $t97 = (string)$pdo->query("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = 'bank_lines_no_delete'")->fetchColumn();
    $pdo->exec('DROP TRIGGER bank_lines_no_delete');
    $pdo->exec('DELETE FROM bank_statement_lines WHERE rowid = (SELECT MAX(rowid) FROM bank_statement_lines)');
    $pdo->exec($t97);
}
""" + old
s = s.replace(old, new, 1)
assert s.count("'.add_receipt97'") == 1 and s.count("'.left97'") == 1 and s.count('rh97_probe') == 1 and s.count(old) == 1
assert s.count("'.leak_bank97'") == 1 and s.count("'.del_line97'") == 1 and s.count('bank_lines_no_delete') == 2
open(p, 'w').write(s)
PY
for i in $(seq 1 60); do pubget page=rh97_probe | grep -q 'rh97-triggers-ready' && break; sleep 0.1; done   # PHP re-reads the edited file
check "$(pubget page=rh97_probe)$(ls -A "$SB/web" | grep -c '^\.add_receipt97$\|^\.left97$\|^\.leak_bank97$\|^\.del_line97$')" "rh97-triggers-ready0" "control: the stand-in carries the four triggers, unspent and unset"
printf '%s\n' 314159.27 > "$SB/seed97.amounts"   # the stand-in's receipt; the statements' amounts are added when they are made (7)
O_G="$(o97)"
check "$(oj "$O_G" admins)|$(oj "$O_G" nonadmins)|$(oj "$O_G" curs)|$(oj "$O_G" offered)|$(oj "$O_G" tables)|$(oj "$O_G" ledger)|$(oj "$O_G" rows)|$(both_copies "$BK97")" \
      "0|2|UGX,USD|UGX:2:1:0,USD:1:1:0|0/5|absent|-:-:-:-:-|-" \
  "pre-c ECO Bank's ground, read with this rehearsal's own SQL: no active administrator among the book's staff, two others; UGX and USD; three bank accounts offered — two of UGX, one of them named Ecobank, one of USD named Ecobank; 088 absent, its ledger row too; the switch in neither place"
VT97_0="$(vt_host)"; D97="$(data94)"; KEPT97="$(kept_state)"; QREC97="$(sha256sum "$QREC" | cut -c1-16)"; DX97="$(data97x)"
check "$VT97_0" "$VT96_0" "pre-d the live vault as 5.18.96's rehearsal settled it (its fixed point), holding the planted webhook secret and credential"

echo; echo "== 97-0. what is rehearsed: 5.18.97 — the controls =="
echo "  script    $WHICH97, sha256 $(sha256sum "$DEPLOY97" | cut -c1-16)"
case "$P97" in __*|'') echo "  FAIL the script still carries the placeholder pin ($P97)"; exit 2;; esac
check "$P97" "$(git -C "$REPO" rev-parse --short "origin/$REL97" 2>/dev/null || echo none)" "the script is pinned to the release commit on $REL97 ($P97)"
check "$(git -C "$REPO" rev-parse --short "$P97^" 2>/dev/null)$(ver "$P97")$(ver "$P96")" "${P96}5.18.975.18.96" "the release commit is cut on $P96 (5.18.96, the live version) and is 5.18.97"
check "$(dl D "$P96" "$P97" | grep -c .)|$(dl A "$P96" "$P97" | grep -c .)|$(dl AM "$P96" "$P97" | grep -c .)|$(git -C "$REPO" diff --no-renames --name-only --diff-filter=CRTUXB "$P96" "$P97" | grep -c .)" "0|11|${N97}|0" \
  "5.18.96 → 5.18.97: nothing removed, copied, renamed or retyped, eleven files added, $N97 in all"
check "$(dl A "$P96" "$P97" | sort | paste -sd' ' -)" "$(for f in $ADDED97; do echo "$P/$f"; done | sort | paste -sd' ' -)" "…the eleven added are ECO Bank's library, gate, CSV reader, screen, post handler, migration 088, a fixture and four tests"
W97L=""; for f in $WHOLE97 $PORT97 $REPIN97 $PINS97 manifest.json; do grep -qx "$P/$f" <(dl AM "$P96" "$P97") || W97L="$W97L $f"; done
check "${W97L:-none}" "none" "…and every file of the reviewed set is in the delta"
check "$(git -C "$REPO" diff --name-only "$P96" "$P97" | grep -vc "^$P/")|$(dl AM "$P96" "$P97" | grep "^$P/migrations/" | paste -sd' ' -)" "0|$P/$MIG97" "nothing outside the plugin; one migration, 088"
check "$(git -C "$REPO" show "$P97:$P/$MIG97" | sha256sum | cut -c1-64)|$(git -C "$REPO" show "$P97:$P/$MIG97" | md5sum | cut -c1-32)" "$MIG88_SHA97|$MIG88_MD597" "088 at the pin is the file the script pins (sha256 ${MIG88_SHA97:0:16}…, md5 ${MIG88_MD597:0:16}…)"
check "$(git -C "$REPO" show "$P97:$P/$MIG97" | grep -cE '^CREATE TABLE')|$(git -C "$REPO" show "$P97:$P/$MIG97" | grep -cE '^CREATE (UNIQUE )?INDEX')|$(git -C "$REPO" show "$P97:$P/$MIG97" | grep -cE '^CREATE TRIGGER')" "5|11|11" \
  "…its objects: five tables, eleven indexes, eleven triggers — the 27 the script reads by name and SQL text"
check "$(git -C "$REPO" rev-list --count "$DEV97_BASE..$FIX97" -- "$P")" "7" "control: the development branch's change is its seven commits on $DEV97_BASE"
T97L=""; for f in $WHOLE97; do [ "$(git -C "$REPO" rev-parse "$P97:$P/$f")" = "$(git -C "$REPO" rev-parse "$FIX97:$P/$f" 2>/dev/null)" ] || T97L="$T97L $f"; done
check "${T97L:-same}" "same" "the fourteen files taken whole are the development branch's ($FIX97) byte for byte"
check "$(for f in $PORT97; do port97 "$f"; done | paste -sd' ' -)" "4:same 78:same 3:same" \
  "public.php, includes/routes.php and tools/set_config.php are 5.18.96's with the development change's lines and no others (4, 78 and 3 changed lines)"
check "$(for f in $REPIN97; do repin97 "$f"; done | paste -sd' ' -)" "6:repin 6:repin" "the two screen and boundary tests are the development branch's with their baseline pin moved to 5.18.96 (e35eccee), nothing else"
V97L=""; for f in manifest.json $PINS97; do [ "$(pin_only97 "$f")" = "pin" ] || V97L="$V97L $f"; done
check "${V97L:-pins}" "pins" "the manifest and the five version pins are 5.18.96's with the version alone changed"
check "$(pin_only97 tools/set_config.php)" "other" "control: a file of the change is not a version pin (the comparison can tell them apart)"
S97L=""; for f in $KEPT_FILES97; do [ "$(git -C "$REPO" rev-parse "$P97:$P/$f")" = "$(git -C "$REPO" rev-parse "$P96:$P/$f")" ] || S97L="$S97L $f"; done
check "${S97L:-same}" "same" "every Cashbook file, the phone's More menu, MANIFEST.md, 5.18.95's security files, main.php, master.php, the retry job, the documents, the gate's, the store's, the runner's and the vault's code, T1–T4 and the page tests' fixtures are 5.18.96's byte for byte"
check "$(git -C "$REPO" rev-parse "$P97:$P/SAFETY.md")|$(git -C "$REPO" merge-base --is-ancestor "$REDACT97" "$FIX97" && echo ancestor)|$(git -C "$REPO" diff --name-only "$REDACT97^" "$REDACT97" -- "$P/SAFETY.md" | grep -c .)" \
      "$(git -C "$REPO" rev-parse "$FIX97:$P/SAFETY.md")|ancestor|1" \
  "SAFETY.md is $FIX97's, taken whole — and so carries the privacy redaction ($REDACT97, an ancestor of $FIX97 that changed it)"
check "$(git -C "$REPO" rev-parse "$P97:$P/MANIFEST.md")|$( [ "$(git -C "$REPO" rev-parse "$FIX97:$P/MANIFEST.md")" != "$(git -C "$REPO" rev-parse "$P96:$P/MANIFEST.md")" ] && echo dev-differs)" \
      "$(git -C "$REPO" rev-parse "$P96:$P/MANIFEST.md")|dev-differs" "MANIFEST.md is 5.18.96's — the development branch's, which differs, is not carried"
check "$(git -C "$REPO" rev-parse "$P97:$P/dishnet-mikrotik-control-plane")$(git -C "$REPO" rev-parse "$P97:$P/docs")|$( [ "$(git -C "$REPO" rev-parse "$FIX97:$P/docs")" != "$(git -C "$REPO" rev-parse "$P96:$P/docs")" ] && echo dev-differs)" \
      "$(git -C "$REPO" rev-parse "$P96:$P/dishnet-mikrotik-control-plane")$(git -C "$REPO" rev-parse "$P96:$P/docs")|dev-differs" "Domain B's tree and the plugin's docs are 5.18.96's — the development branch's docs, which differ, are not carried"
check "$T1SIG97|$T4SIG97" "$T1SIG96|20 passed, 0 failed, 0 skipped rc=0" "the script pins T1's count ($T1SIG97 — T1 is unchanged by 5.18.97, as 5.18.96 pinned it) and T4's"
check "$(sed -n 's/^P0_TOOL_SHA="\([0-9a-f]*\)".*/\1/p' "$DEPLOY97")|$(sed -n 's/^ADJ_CENTS_SHA="\([0-9a-f]*\)".*/\1/p' "$DEPLOY97")" \
      "$(sed -n 's/^P0_TOOL_SHA="\([0-9a-f]*\)".*/\1/p' "$DEPLOY96")|$(sed -n 's/^ADJ_CENTS_SHA="\([0-9a-f]*\)".*/\1/p' "$DEPLOY96")" "P0's tool and R0's adjustment hash are pinned as 5.18.96 pinned them — hashes, never a figure"
check "$(hdr97 "$DEPLOY97")" "101" \
  "the header's deploy command stands alone, with its own log wrapper — no rollback, put-back, redeploy, check-bank, setting or checkout in its block (docs/44 §16.9) — and the later --after-only is its own, with its own"
HDR_DEPLOY_LINE97='#     && bash scripts/deploy-5.18.97.sh 2>&1 | ( umask 077; exec tee /root/dnb-5.18.97/deploy-$(date -u +%Y%m%dT%H%M%SZ).log )'
M="$(mutant hdrrb97 "$DEPLOY97" "$HDR_DEPLOY_LINE97" "$HDR_DEPLOY_LINE97
#   cd /opt/dishnet && docker exec -u 1000:1000 ucrm php tools/set_config.php --key bank_screen_enabled --clear && bash scripts/deploy-5.18.97.sh --rollback")"
check "$(hdr97 "$M")" "111" "0-hdr' a copy whose header carries the switch's clearing and the rollback inside the deploy command's block is caught by the same count"
rm -f "$M"
check "$(cmds97 "$DEPLOY97")" "111111111|12|10|21|1" \
  "every command the script prints for a later step is defined with its own log wrapper — the --after-only, the rollback, the deploy, the put-back, the quarantine's --check with its tee, --redeploy, --check-bank, the switch and its clearing — and each of the twenty-two lines that print one names one of them, or carries the wrapper itself"
SWON_DEF97="$(sed -n 8p "$DEFS97")"   # the switch's definition, as the quoted block above holds it
M="$(mutant fnowrap97s "$DEPLOY97" "$SWON_DEF97" 'SWITCH_ON_CMD="docker exec -u $DB_OWNER -e DN_DATA_DIR=$PDD_IN -w $IN_CONTAINER $CONTAINER php tools/set_config.php --key $BK_KEY --value 1"')"
check "$(cmds97 "$M")" "111111101|12|10|21|1" "0-cmd' a copy that defines the switch without its log wrapper (and without cutting the settings listing) is caught by the same reading"
rm -f "$M"
PR97_TWO="$(printf '%s\n' '== F. Summary ==' "      $EXP_DEPLOY97; $EXP_SWON97")"
PR97_R6="  ok    R6 every earlier surface is installed as before (… the registry's switch in set_config.php … the three switches in set_config.php … set_config.php refusing the registry …)"
check "$(printed97 "$PR97_TWO" | cut -d'|' -f3)|$(printed97 "$PR97_R6" | cut -d'|' -f3)" "1|0" \
  "control: printed97's third count sees a line holding two commands (the deploy and the switch pasted together) and not R6's ok line, which names set_config.php three times in words"
check "$(grep -cE '(>|>>) *"?\$(VAULT_HOST|VAULT)"?( |$)' "$DEPLOY97")$(grep -cE '(mv|rm|touch|chmod|chown|tee) [^|;]*"\$(VAULT_HOST|VAULT)"' "$DEPLOY97")$(grep -E 'cp [^|;]*"\$VAULT"' "$DEPLOY97" | grep -vcF 'cp -p "$VAULT" "$BK/config-vault.json"')" "000" \
  "the script never writes, moves or touches the live vault — it copies it only into its own backup"
check "$(grep -cE '(cat|head|tail|more|less|awk|sed|grep)( -[^ ]+)* +"\$SHEET"' "$DEPLOY97")$(grep -cF 'echo "      less $SHEET"' "$DEPLOY97")" "03" \
  "nothing in the script reads the cash sheet out — it prints only \`less <path>\`, on its own line, in each of its three summaries (deploy and after, --check, --check-bank)"
check "$(grep -c 'DISHNET_REPAIR' "$DEPLOY97")$(grep 'DISHNET_REPAIR' "$DEPLOY97" | grep -vc 'grep -')$(grep -cFf "$SB/spx.vals" "$DEPLOY97")" "400" \
  "the script names the old built-in key only in grep patterns (four), and holds none of 5.18.94's Splynx values"
check "$(grep -vE '^[[:space:]]*#' "$DEPLOY97" | grep -cE 'set_config\.php --key')|$(grep -vE '^[[:space:]]*#' "$DEPLOY97" | grep -cF "$BK97 --value")" "2|0" \
  "the script runs ECO Bank's switch nowhere: tools/set_config.php --key appears only in the two printed commands' definitions — the switch is the operator's (SPEC §2.10b)"
check "$(grep -cE 'Ecobank UGX|Ecobank USD|7000000001|RH-Account|RH-CLIENT|0000000001|0000000002' "$DEPLOY97")" "0" "the script names no account, number, holder or counterparty of the book or of the synthetic statements"
check "$(bash -n "$DEPLOY97" && echo parses)" "parses" "the script parses"
check "$(git -C "$REPO" log -1 --format=%h "$P97" -- $P)|$(git -C "$REPO" log -1 --format=%h "$P96" -- $P)" "$P97|$P96" \
  "control: the clone writes the 7-character short forms the script names"
# ── The instruments: BR2's reading of 088 (BANK_PHP) and the gate's processes (BANKK_PHP), taken from the script as it
# stands and run on a copy of the sandbox's book — 088 absent; applied by the pin's own runner from a folder holding 088
# alone; one trigger dropped; an index added — and the gate's refusal of a path that is not a copy. The live book untouched.
K97="$SB/k97"; rm -rf "$K97"; mkdir -p "$K97/pin" "$K97/m"
git -C "$REPO" archive "$P97" "$P/lib/MigrationRunner.php" "$P/$MIG97" | tar -x -C "$K97/pin"
python3 - "$DEPLOY97" "$K97" <<'PY' || echo "instrument:bank97" >> "$SB/anchor-missing"
import sys, re
s = open(sys.argv[1]).read()
for name in ('BANK_PHP', 'BANKK_PHP'):
    m = re.findall(r'(?m)^' + name + r'="\$\(cat <<\'PHP\'\n(.*?)\nPHP\n\)"', s, re.S)
    assert len(m) == 1, name
    open(sys.argv[2] + '/' + name.lower() + '.txt', 'w').write(m[0])
PY
bk97() { php -d display_errors=stderr -r "$(cat "$K97/bank_php.txt")" "$1" "$K97/pin/$P" "rh97-instrument-control-key-0123456789abcdef" "bank_account_profiles bank_checks bank_statements bank_statement_lines bank_line_events" 2>/dev/null; }
LED97_0="$(ledger95)"
[ -d "$K97" ] && [ -d "$K97/m" ] || echo "  diag 0-k: the instrument folder is missing before the copy ($(ls -ld "$SB" 2>&1 | cut -c1-60); uid $(id -u))"
php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec("VACUUM INTO " . $p->quote($argv[2]));' "$DATA/plugin.sqlite3" "$K97/copy.sqlite3" || echo "  diag 0-k: the copy failed ($(ls -ld "$K97" 2>&1 | cut -c1-60); $(ls -l "$DATA/plugin.sqlite3" 2>&1 | cut -c1-40); free $(df -Pk "$SB" | awk 'NR==2{print $4}')k)"
B0="$(bk97 "$K97/copy.sqlite3")"
cp "$K97/pin/$P/$MIG97" "$K97/m/"
php -r 'require $argv[1] . "/lib/MigrationRunner.php"; (new MigrationRunner(new PDO("sqlite:" . $argv[2]), $argv[3], $argv[4]))->run(); echo "ran";' "$K97/pin/$P" "$K97/copy.sqlite3" "$K97/m" "$K97/m.log" >/dev/null 2>&1
B1="$(bk97 "$K97/copy.sqlite3")"
php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec("DROP TRIGGER bank_lines_no_delete");' "$K97/copy.sqlite3"
B2="$(bk97 "$K97/copy.sqlite3")"
php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec("CREATE TRIGGER bank_lines_no_delete BEFORE DELETE ON bank_statement_lines BEGIN SELECT RAISE(ABORT, \x27x\x27); END; CREATE INDEX rh97_extra_index ON bank_checks (id);");' "$K97/copy.sqlite3"
B3="$(bk97 "$K97/copy.sqlite3")"
check "$(case "$B0" in "ledger=absent objects=0/27 exact=0/27 tables=0/5 indexes=0/11 triggers=0/11 checks=0/16 extra=0 rows=-:-:-:-:- control=yes "*) echo absent ;; *) echo "other(${B0:0:120})" ;; esac)|$(grep -c "OK: 088_bank_statements.sql (27 stmts," "$K97/m.log")|$(case "$B1" in "ledger=applied:$MIG88_MD597 objects=27/27 exact=27/27 tables=5/5 indexes=11/11 triggers=11/11 checks=16/16 extra=0 rows=0:0:0:0:0 control=yes "*"missing=- differs=- extras=-") echo complete ;; *) echo "other(${B1:0:120})" ;; esac)" \
      "absent|1|complete" \
  "0-k the instrument of BR2 and BA1, on a copy of the book: 088 absent (every object, the ledger row; the read sees cb_ledger, the control); applied by the pin's own runner — 27 statements — then all 27 objects exactly as the file makes them, its 16 CHECKs, every table empty, the ledger row with the reviewed checksum"
check "$(sed -E 's/.* triggers=([^ ]*) .* missing=([^ ]*) .*/\1|\2/' <<<"$B2")|$(sed -E 's/.* extra=([^ ]*) .* extras=([^ ]*)$/\1|\2/' <<<"$B3")|$(sed -E 's/.* objects=([^ ]*) exact=([^ ]*) .*/\1|\2/' <<<"$B3")" \
      "10/11|bank_lines_no_delete|1|rh97_extra_index|27/27|26/27" \
  "0-k' …and it sees a trigger dropped (10 of 11, named), an index added on 088's tables (named), and a trigger put back with other SQL text (present, not exact) — what BR2 refuses with or without --redeploy"
check "$(php -r "$(cat "$K97/bankk_php.txt")" found "$PD" "$DATA" /tmp/dnb-5.18.97-cih-rhk/ "" x 2>/dev/null)|$(php -r "$(cat "$K97/bankk_php.txt")" on /var/x/k-on/$P /var/x/k-on-data /var/x/ "" x 2>/dev/null)|$(both_copies "$BK97")|$(data94)|$(ledger95)" \
      "?notacopy|?notacopy|-|$D97|$LED97_0" \
  "0-k'' the gate's process refuses to start on the plugin folder or the live data folder, or on any folder outside the container's /tmp — before it writes the switch anywhere; the live configuration, the book and its ledger untouched by all of this"
rm -rf "$K97"

echo; echo "== 97-c. --check: stage A alone — no administrator; the staff planted: GO; no other staff; the option rules =="
fresh97; S0="$(states97)"; OUT="$(run97 --check)"
nogo97 "c1 the book holds no active administrator: --check refuses at BK2 — zero administrators is a FAIL, never a pass (SPEC §2.10b K2)" \
  "NO-GO (BK2): ECO Bank's gate on the copy is not as it must be (no-active-administrator(admins=0))" "$OUT"
check "$(okline "$OUT" BR2 "088's five tables are absent on the copy, its ledger row too")$(okline "$OUT" BR3 "ECO Bank's switch, $BK97, is absent from the configuration files")$(okline "$OUT" BK1 "cash in hand, 5.18.96's code and 5.18.97's on the same copy, the same to the last digit in all 8 cells")|$(states97)" "yesyesyes|$S0" \
  "…after BR2, BR3 and BK1 have held on the same copy; no state file written"
STAFF97="$(cd "$H97/v" && setpriv --reuid 1000 --regid 1000 --clear-groups -- env DN_VAULT_FILE="$H97/v/staff-vault.json" php "$H97/rh97-staff.php" "$PD" "$DATA" 2>"$H97/v/staff.err")"
ADM97="$(python3 -c 'import json, sys; print(json.loads(sys.argv[1])["admin"])' "$STAFF97" 2>/dev/null)"
O_S="$(o97)"
check "$(case "$ADM97" in [1-9]*) echo planted ;; *) echo "unplanted($(head -c 160 "$H97/v/staff.err"))" ;; esac)|$(oj "$O_S" admins)|$(oj "$O_S" nonadmins)|$(vt_host)" "planted|1|3|$VT97_0" \
  "c-staff three staff records through the installed store, as the staff screen keeps them: an active administrator, an inactive one, and a former administrator now an accountant, still active — the stale session; the book's two others as they are. One active administrator, three others; the live vault untouched"
rm -f "$H97/v/staff-vault.json"
D97="$(data94)"; DX97="$(data97x)"
fresh97; S0="$(states97)"; OUT="$(run97 --check)"; T="$(last97)"; SHC="$(sheet_of "$OUT")"
check "$(checkgo97 "$OUT")" "yesyesnono0|0|$S0" "c2 with the staff planted, --check is GO: stage A held — nothing asked, nothing backed up, no state file written"
# ready=yes: the found process opens its copy through the pin's own store, whose runner applies the pin's 088 to that copy
# before BankStatementStore::ready() is asked — BR2 read the copy before it, and says 088 absent.
check "$(grep -cxF '  the gate        as found: switch=absent halves=yes open=no admins=1 shown=0 nonadmins=3 stale=0 ready=yes offered=UGX:2:1:0,USD:1:1:0 both=no' <<<"$OUT")$(grep -cxF '                  off:      switch=absent halves=yes open=no admins=1 shown=0 nonadmins=3 stale=0' <<<"$OUT")$(grep -cxF '                  on:       switch=on halves=yes open=yes admins=1 shown=1 nonadmins=3 stale=0' <<<"$OUT")" "111" \
  "…the gate's three states, each in its own process: as found closed, off shown to nobody, on shown to the one administrator and to none of the three others handed a session copy that says is_admin — the stale session refused by the fresh record (stale=0)"
check "$(okline "$OUT" BK2 "off — no one of the 1 active administrator(s) is shown ECO Bank; on (written into the copy's kyc_config.json only, never the live one) — all 1 are, and none of the 3 active non-administrator(s)")$(noteline "$OUT" BN5 'the offered bank accounts are not one per currency named Ecobank — UGX: 2 offered, 1 named Ecobank, 0 of them with a statement kept; USD: 1 offered, 1 named Ecobank, 0 of them with a statement kept (counts only;')$(has "$OUT" 'BK2 the copy holds no active staff record')" "yesyesno" \
  "…BK2 holds; BN5's note counts the book's offered accounts per currency — this rehearsal's own count — and names none"
check "$(grep -cxF "      $EXP_DEPLOY97" <<<"$OUT")$(grep -cxF "      $EXP_REDEPLOY97" <<<"$OUT")$(grep -c 'deploy-5.18.97.sh --rollback' <<<"$OUT")|$(printed97 "$OUT")|$(grep -cxF "      less $SHC" <<<"$OUT")|$(stat -c %a "$SHC")" "100|1|0|0|1|600" \
  "…its summary prints the deploy alone, with its own log — not --redeploy, never a rollback — and \`less\` for its own sheet, 0600"
check "$(python3 "$H97/rh97.py" ecosheet "$SHC")" "$(python3 "$H97/rh97.py" ecoexp "$O_S" 1)" \
  "…the sheet's ECO Bank lines are the book's offered accounts as this rehearsal reads them — row id, currency, named, kept, linked, no kept line — in the cards' order"
check "$(grep -cE '^  account [0-9]+ of |#[0-9]+  (UGX|USD)' <<<"$OUT")|$(figs97 "$R97/run-$T.txt")|$(bankhits97 "$R97/run-$T.txt" "$SHC")|$(hits97 "$T")|$(audit97 "$T" | sed -E 's/^copies=[0-9]+ //; s/ adj=.*//')|$(vault97 "$T")" "0|0|0|0|novault=0 plugin=0|$VT97_0>$VT97_0" \
  "…no row id, figure, account name or number in the terminal (nor a name or number on the sheet); the observers quiet, every process on a copy with a vault of its own, the live vault untouched"
db_keep
sqx "UPDATE retailers SET data = json_set(data, '\$.is_active', json('false')) WHERE COALESCE(json_extract(data, '\$.is_admin'), 0) IN (0, '')" >/dev/null
sqx "UPDATE cb_accounts SET active = 0 WHERE id = $(python3 -c 'import json, sys; print(json.loads(sys.argv[1])["bank2"])' "$BOOKIDS")" >/dev/null
O_C3="$(o97)"; D97C3="$(data94)"
fresh97; OUT="$(run97 --check)"
check "$(oj "$O_C3" admins)|$(oj "$O_C3" nonadmins)|$(oj "$O_C3" offered)|$(checkgo97 "$OUT" | cut -d'|' -f1)" "1|0|UGX:1:1:0,USD:1:1:0|yesyesnono0" \
  "c3 every other staff record switched off and the second UGX bank account made inactive: --check is GO"
check "$(noteline "$OUT" BK2 "the copy holds no active staff record that is not an administrator's, so the stale-session refusal had no one to refuse on this book")$(okline "$OUT" BN5 'the book offers one bank account per currency, each named Ecobank — UGX: 1 offered, 1 named Ecobank, 0 of them with a statement kept; USD: 1 offered, 1 named Ecobank, 0 of them with a statement kept: ECO BANK UGX/USD each have their account')|$(data94)" "yesyes|$D97C3" \
  "…zero active non-administrators is BK2's NOTE (the stale-session check not exercised), never a pass of it; BN5 ok on the book's standard chart; the data as the plant left it"
db_back
check "$(data94)|$(oj "$(o97)" offered)" "$D97|UGX:2:1:0,USD:1:1:0" "…the book and its staff put back, byte for byte"
fresh97; OUT="$(run97 --after-only --rollback)"
check "$(has "$OUT" '--after-only, --rollback and --check-bank are each a run of their own: give one of them')$(went97 "$OUT")|$(quiet97)" "yesnono|$P96|0|$D97|$KEPT97|0" "c4 two modes in one run are refused before anything is read"
OUT="$(run97 --redeploy --after-only)"
check "$(has "$OUT" '--redeploy and --check go with a deploy only, never with --after-only, --rollback or --check-bank')$(went97 "$OUT")|$(quiet97)" "yesnono|$P96|0|$D97|$KEPT97|0" "c4' --redeploy with --after-only is refused"
OUT="$(run97 --check --check-bank)"
check "$(has "$OUT" '--redeploy and --check go with a deploy only, never with --after-only, --rollback or --check-bank')$(went97 "$OUT")|$(quiet97)" "yesnono|$P96|0|$D97|$KEPT97|0" "c4'' --check with --check-bank is refused"
fresh97; OUT="$(run97 --check-bank)"
nogo97 "c5 --check-bank with 5.18.96 live: refused — it reads ECO Bank as 5.18.97 has it" \
  "--check-bank reads ECO Bank as 5.18.97 has it: the container serves $P96 (5.18.96), not $P97 (5.18.97) — deploy it first. Nothing was changed" "$OUT"

echo; echo "== 97-1. A14: a failure 5.18.96's, 5.18.95's or 5.18.94's runs recorded is not forgotten; rule 3; no A20 =="
A14_LOOP97='for f in "$STATE" "$STATE".prev-* "$PREV_STATE" "$PREV_STATE".prev-* "$PREV2_STATE" "$PREV2_STATE".prev-* "$PREV3_STATE" "$PREV3_STATE".prev-*; do'
printf 'STRAY_Q_FAILED=%s\n' "$(date -u +%s)" >> "$STATE96"
fresh97; OUT="$(run97 --answer NO)"
nogo97 "1a a failure in 5.18.96's state file (STRAY_Q_FAILED), newer than the newest passing --check: NO-GO at A14" \
  "NO-GO (A14): a run of this script, of 5.18.96's, of 5.18.95's or of 5.18.94's found the quarantine broken at" "$OUT"
check "$(has "$OUT" '(STRAY_Q_FAILED in dnb-5.18.96/state-5.18.96.env)')$(has "$OUT" 'and the newest passing --check of the quarantine began before that')$(grep -cxF "      $EXP_QCHECK" <<<"$OUT")" "yesyes1" \
  "…it names 5.18.96's file, and prints the quarantine's --check on its own line, with its own log"
M="$(mutant a14prev97 "$DEPLOY97" "$A14_LOOP97" 'for f in "$STATE" "$STATE".prev-* "$PREV2_STATE" "$PREV2_STATE".prev-* "$PREV3_STATE" "$PREV3_STATE".prev-*; do')"
fresh97; OUT="$(run97 --answer NO --script "$M")"; rm -f "$M"
check "$(went97 "$OUT")$(has "$OUT" 'STOP: not confirmed')" "yesyesyes" "1a' a copy blind to 5.18.96's state files reaches the question over it (caught)"
sed -i '/^STRAY_Q_FAILED=/d' "$STATE96"; printf 'STRAY_Q_FAILED=%s\n' "$(date -u +%s)" > "$STATE96.prev-20261018T010000Z"
fresh97; OUT="$(run97 --answer NO)"
nogo97 "1b …in a state file a later deploy of 5.18.96 kept beside its own: NO-GO at A14, naming it" "(STRAY_Q_FAILED in dnb-5.18.96/state-5.18.96.env.prev-20261018T010000Z)" "$OUT"
rm -f "$STATE96.prev-20261018T010000Z"
printf 'STRAY_Q_FAILED=%s\n' "$(date -u +%s)" >> "$STATE95"
fresh97; OUT="$(run97 --answer NO)"
nogo97 "1c …in 5.18.95's state file: NO-GO at A14, naming it" "(STRAY_Q_FAILED in dnb-5.18.95/state-5.18.95.env)" "$OUT"
sed -i '/^STRAY_Q_FAILED=/d' "$STATE95"
printf 'STRAY_Q_FAILED=%s\n' "$(date -u +%s)" >> "$STATE94"
fresh97; OUT="$(run97 --answer NO)"
nogo97 "1d …in 5.18.94's state file, three releases back: NO-GO at A14, naming it" "(STRAY_Q_FAILED in dnb-5.18.94/state-5.18.94.env)" "$OUT"
M="$(mutant a14prev3_97 "$DEPLOY97" "$A14_LOOP97" 'for f in "$STATE" "$STATE".prev-* "$PREV_STATE" "$PREV_STATE".prev-* "$PREV2_STATE" "$PREV2_STATE".prev-*; do')"
fresh97; OUT="$(run97 --answer NO --script "$M")"; rm -f "$M"
check "$(went97 "$OUT")$(has "$OUT" 'STOP: not confirmed')" "yesyesyes" "1d' a copy blind to 5.18.94's state files — reading two releases back, as 5.18.96's A14 did — reaches the question over it (caught)"
sed -i '/^STRAY_Q_FAILED=/d' "$STATE94"
printf 'STRAY_Q_FAILED=soon\n' >> "$STATE96"
fresh97; OUT="$(run97 --answer NO)"
nogo97 "1e a recorded failure whose time cannot be read: NO-GO at A14" \
  "recorded the quarantine broken (STRAY_Q_FAILED in dnb-5.18.96/state-5.18.96.env) at a time that cannot be read" "$OUT"
sed -i '/^STRAY_Q_FAILED=/d' "$STATE96"
printf 'STRAY_Q_FAILED=%s\n' "$(( $(date -u +%s) - 30 ))" >> "$STATE96"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "1f the quarantine's own --check, begun after that failure: PASSED"
QREC97="$(sha256sum "$QREC" | cut -c1-16)"; KEPT97="$(kept_state)"
fresh97; OUT="$(run97 --answer NO)"
check "$(noteline "$OUT" A14 "a run of this script, of 5.18.96's, of 5.18.95's or of 5.18.94's found the quarantine broken at" '(STRAY_Q_FAILED in dnb-5.18.96/state-5.18.96.env)' 'after it, PASSED — so this deploy goes on')$(okline "$OUT" A14 'the quarantine holds')$(went97 "$OUT")$(has "$OUT" 'STOP: not confirmed')" "yesyesyesyesyes" \
  "1f' …then A14 passes and says the failure; a word other than DEPLOY stops the run at the question"
check "$(grep -cE '^  (ok    |note  |FAIL  )A20 |\(A20\)' <<<"$OUT")$(went97 "$OUT")" "0yesyes" "1g on 17 October in Kampala the deploy names no A20 — 5.18.94's own precondition, gone since 5.18.95 — and reaches its question"
sed -i '/^STRAY_Q_FAILED=/d' "$STATE96"
printf 'COPY_AT=%s\n' "$(date -u +%s)" > "$STATE96.prev-20261018T020000Z"
fresh97; OUT="$(run97 --answer NO)"
nogo97 "1h a copy of 5.18.96's recorded after the newest passing --check of the quarantine (its rule 3): NO-GO at A14" "came after the newest passing --check of the quarantine" "$OUT"
check "$(has "$OUT" '(dnb-5.18.96/state-5.18.96.env.prev-20261018T020000Z)')$(grep -cxF "      $EXP_QCHECK" <<<"$OUT")" "yes1" "…it names 5.18.96's file, and prints the quarantine's --check on its own line, with its own log"
M="$(mutant a14copy97 "$DEPLOY97" '[ "$QCL_T" -gt "$PREV_COPY" ] || stop' 'true || stop')"
fresh97; OUT="$(run97 --answer NO --script "$M")"; rm -f "$M"
check "$(went97 "$OUT")$(has "$OUT" 'STOP: not confirmed')" "yesyesyes" "1h' a copy blind to 5.18.96's copy time reaches the question over it (caught)"
rm -f "$STATE96.prev-20261018T020000Z"
fresh97; OUT="$(run97 --answer NO)"
check "$(okline "$OUT" A14 'the quarantine holds' "it began after 5.18.96's copy (")$(went97 "$OUT")" "yesyesyes" "1i …the planted copy time taken out: A14 passes again and says its --check began after 5.18.96's copy"
check "$(grep -c '^STRAY_Q_FAILED=' "$STATE96" "$STATE95" "$STATE94" | awk -F: '{s += $2} END {print s}')|$(quiet97 | cut -d'|' -f1,3-)|$(sha256sum "$QREC" | cut -c1-16)" "0|$P96|$D97|$KEPT97|0|$QREC97" \
  "…every planted line taken out: 5.18.96 live, the data, the quarantine and its record as before — the script read the record, never wrote it"

echo; echo "== 97-1b. the live code as found: A22L; a part-way copy; an installed file not 5.18.96's; BR1, every file 5.18.96 tracks =="
RUNBOOK_L2='// The local currency comes from configuration. This page taught every Ugandan'
cp -p "$PD/tabs/help/runbook.php" "$SB/runbook97.kept"
edit_installed tabs/help/runbook.php "$RUNBOOK_L2" "$RUNBOOK_L2
// $OLDKEY95"
fresh97; OUT="$(run97 --answer NO)"
nogo97 "1j the old built-in key named again in the LIVE help page: NO-GO at A22L, naming the check and its defect in fixed words" \
  "NO-GO (A22L): the live code, as installed, does not carry 5.18.95's fixes as they were reviewed: A22e(names-the-old-key-1x)" "$OUT"
check "$(has "$OUT" "$OLDKEY95")" "no" "…the old key's name never printed"
cat "$SB/runbook97.kept" > "$PD/tabs/help/runbook.php"; touch -r "$SB/runbook97.kept" "$PD/tabs/help/runbook.php"
check "$(sha256sum "$PD/tabs/help/runbook.php" | cut -c1-64)" "$(git -C "$REPO" show "$P96:$P/tabs/help/runbook.php" | sha256sum | cut -c1-64)" "…the live help page put back: 5.18.96's"
cp -p "$PD/manifest.json" "$SB/manifest97.kept"; git -C "$REPO" show "$P97:$P/manifest.json" > "$PD/manifest.json"
fresh97; OUT="$(run97 --answer NO)"
nogo97 "1k 5.18.96's record with 5.18.97's manifest installed — a copy that stopped part-way: NO-GO before anything changes" \
  "NO-GO: the container's record says $P96, but the installed manifest says 5.18.97: a copy of 5.18.97 that did not complete (a deploy that stopped part-way)" "$OUT"
check "$(grep -cxF "      $EXP_PUTBACK97" <<<"$OUT")$(grep -c 'deploy-5.18.97.sh --rollback' <<<"$OUT")" "10" "…its stop prints the by-hand put-back of 5.18.96 alone on its line, with its own log wrapper — and no rollback"
cat "$SB/manifest97.kept" > "$PD/manifest.json"; touch -r "$SB/manifest97.kept" "$PD/manifest.json"
cp -p "$PD/public.php" "$SB/public97.kept"; printf '\n' >> "$PD/public.php"
fresh97; OUT="$(run97 --answer NO)"
nogo97 "1l a file 5.18.97 changes, installed but not 5.18.96's (public.php, one byte more): NO-GO before anything changes, naming it" \
  "NO-GO: the container's record says $P96 (5.18.96), but these installed files are not 5.18.96's: public.php — " "$OUT"
check "$(grep -cxF "      $EXP_PUTBACK97" <<<"$OUT")" "1" "…and prints the put-back alone, with its own log"
cat "$SB/public97.kept" > "$PD/public.php"; touch -r "$SB/public97.kept" "$PD/public.php"
cp -p "$PD/tabs/admin/changelog.php" "$SB/changelog97.kept"
edit_installed tabs/admin/changelog.php "// Tab: changelog" "// Tab: changelog (edited by hand on the server)"
fresh97; OUT="$(run97 --answer NO)"
nogo97 "1m a file 5.18.97 does not change, edited by hand on the server: NO-GO at BR1 — every file e35ecce tracks, by sha256 — naming it" \
  "NO-GO (BR1): the installed plugin is not 5.18.96's as $P96 has it — these files differ or are missing (at most five named): tabs/admin/changelog.php" "$OUT"
M="$(mutant br1blind97 "$DEPLOY97" '  [ -z "$BR1_MISS" ] || stop "NO-GO (BR1)' '  true || stop "NO-GO (BR1)')"
fresh97; OUT="$(run97 --answer NO --script "$M")"; rm -f "$M"
check "$(went97 "$OUT")$(has "$OUT" 'STOP: not confirmed')" "yesyesyes" "1m' a copy blind to BR1 reaches the question over the edited file (caught)"
cat "$SB/changelog97.kept" > "$PD/tabs/admin/changelog.php"; touch -r "$SB/changelog97.kept" "$PD/tabs/admin/changelog.php"
check "$(inst_ver)$(installed97 "$P96")|$(sha256sum "$PD/tabs/admin/changelog.php" | cut -c1-64)|$(quiet97 | cut -d'|' -f1,3-)" \
      "5.18.960|$(git -C "$REPO" show "$P96:$P/tabs/admin/changelog.php" | sha256sum | cut -c1-64)|$P96|$D97|$KEPT97|0" "…every file put back: 5.18.96's, the data and the quarantine as before"

echo; echo "== 97-2. faulty pins: the cash library (A0, then A22-T1, then its own check), the gate (BK3, BK2), the menu, the store's switch =="
A0_OLD='[ -z "$STRAY" ] || stop'; A0_NEW='true || stop'
A232_OLD='[ "$(kv "$CIH_K" cmp_d2)" = "$A23_N/$A23_N" ] || stop "NO-GO (A23.2)'; A232_NEW='true || stop "NO-GO (A23.2)'
A232E_OLD='  case "$A23_E" in none:*|bad:*) stop "NO-GO (A23.2)'; A232E_NEW='  case "$A23_E" in rh97-never) stop "NO-GO (A23.2)'
BK3_OLD='  [ "$BK3_P" = "ok" ] || stop "NO-GO (BK3)'; BK3_NEW='  true || stop "NO-GO (BK3)'
v97() {   # v97 LABEL ID EXPECTED-TEXT OLD NEW — the pin with lib/CashbookService.php weakened, cut on 5.18.96: refused at A0 (5.18.97
          # must not ship the file); a copy without A0's refusal, at A22-T1 by the pin's own T1; a copy without both, by EXPECTED-TEXT.
          # Sets V97_LAST to the variant's commit and V97_SCRIPT to the last copy (removed by the caller)
  local v s m o
  v="$(release_variant97 lib/CashbookService.php "$4" "$5")"; V97_LAST="$v"
  s="$(pinned_copy "v97-$2" "$v" "$DEPLOY97")"
  fresh97; o="$(run97 --answer NO --script "$s")"
  nogo97 "$1 — refused at A0: the pin carries a file 5.18.97 must not ship" "the pin carries files that are not 5.18.97's, which this release must not ship: lib/CashbookService.php" "$o"
  m="$(mutant "v97a0-$2" "$s" "$A0_OLD" "$A0_NEW")"
  fresh97; o="$(run97 --answer NO --script "$m")"; rm -f "$m"
  nogo97 "$1 — with A0's refusal taken out of a copy: NO-GO at A22-T1, the pin's own T1 under this PHP" "NO-GO (A22-T1): under this server's own PHP the pin's T1 does not pass" "$o"
  m="$(mutant "v97t1-$2" "$s" "$A0_OLD" "$A0_NEW" "$A22T1_OLD" "$A22T1_NEW")"; rm -f "$s"
  fresh97; o="$(run97 --answer NO --script "$m")"; V97_SCRIPT="$m"
  nogo97 "$1 — with both taken out, the check written for it refuses on its own" "$3" "$o"
}
NOGO_A232="NO-GO (A23.2): the pin's cash in hand is not D2 = A's SQL on the same copy"
v97 "2a a pin that counts bank rows" bank "$NOGO_A232" "$V_KINDS_OLD" "$(cat <<'EOF'
" OR account_id IN (SELECT id FROM cb_accounts WHERE kind IN ({$kinds}, 'bank'))"
EOF
)"; rm -f "$V97_SCRIPT"
S="$(pinned_copy v97bk1 "$V97_LAST" "$DEPLOY97")"
M="$(mutant v97bk1m "$S" "$A0_OLD" "$A0_NEW" "$A22T1_OLD" "$A22T1_NEW" "$A232_OLD" "$A232_NEW" "$A232E_OLD" "$A232E_NEW")"; rm -f "$S"
fresh97; OUT="$(run97 --answer NO --script "$M")"; rm -f "$M"
nogo97 "2a' …and with A23.2 taken out too, BK1 refuses it: cash in hand under 5.18.96's code and the pin's not the same to the last digit" \
  "NO-GO (BK1): cash in hand under 5.18.96's code and under the pin's is not the same to the last digit on the same copy" "$OUT"
v97 "2b a pin that counts pending rows" pending "$NOGO_A232" "$V_STATUS_OLD" "$(cat <<'EOF'
return " AND status IN ('approved', 'pending_approval') AND (COALESCE(account_id,0)=0"
EOF
)"; rm -f "$V97_SCRIPT"
v97 "2c a pin that counts orphans and text ids" orphans "$NOGO_A232" "$V_KINDS_OLD" "$(cat <<'EOF'
" OR account_id IN (SELECT id FROM cb_accounts WHERE kind IN ({$kinds})) OR account_id NOT IN (SELECT id FROM cb_accounts)"
EOF
)"; rm -f "$V97_SCRIPT"
v97 "2d a pin whose gate lost its tenant half — a South Sudan tenant's books move" southsudan \
  "NO-GO (A24, South Sudan): the pin does not keep the books where the rule is off exactly as 5.18.96 reads them, its gate is not as it must be on them, or the check could not be made: pin-signature outputs-differ" \
  "$V_GATE_OLD" "$(cat <<'EOF'
$this->cashRuleCache = !dn_ssp_selectable($cfg);
EOF
)"; rm -f "$V97_SCRIPT"
v97 "2e a pin whose Balance column keeps the old rule" balance "NO-GO (A23.2): the pin's Balance column on this book does not reach its cash in hand as it must" "$V_STREAM_OLD" "$(cat <<'EOF'
$cashWhere  = '';
EOF
)"; rm -f "$V97_SCRIPT"
v97 "2f a pin whose gate fails, so rule A is not in force" gatefails "NO-GO (A23.7): rule A is NOT in force on this server's own configuration, as the pin's code reads it" "$V_GATE_OLD" "$(cat <<'EOF'
$this->cashRuleCache = false;
EOF
)"; rm -f "$V97_SCRIPT"
V97_GATEFAILS="$V97_LAST"
# The gate. BankGate::showLink() trusting the session's is_admin alone: BK3 reads its one line, and — BK3 taken out of a
# copy — BK2's on-process shows ECO Bank to the three others handed a stale session copy (the former administrator among them).
V_SHOW_OLD="$(cat <<'EOF'
return !empty($sessionRetailer['is_admin']) && self::open($dataDir) && self::allows('bank.view', $store, $sessionRetailer) !== null;
EOF
)"
V_SHOW_NEW="$(cat <<'EOF'
return !empty($sessionRetailer['is_admin']) && self::open($dataDir);
EOF
)"
V="$(release_variant97 lib/BankGate.php "$V_SHOW_OLD" "$V_SHOW_NEW")"
S="$(pinned_copy v97session "$V" "$DEPLOY97")"
fresh97; OUT="$(run97 --answer NO --script "$S")"
nogo97 "2g a gate whose showLink() trusts the session's is_admin, the fresh record not asked: NO-GO at BK3, its one line not the reviewed one" \
  "NO-GO (BK3): ECO Bank's links in the pin are not as they were reviewed: gate:showLink-not-the-reviewed-body" "$OUT"
M="$(mutant v97sessionm "$S" "$BK3_OLD" "$BK3_NEW")"; rm -f "$S"
fresh97; OUT="$(run97 --answer NO --script "$M")"; rm -f "$M"
nogo97 "2g' …and with BK3's refusal taken out of a copy, BK2 refuses it by the stale session: on, ECO Bank shown to all three active non-administrators handed a session copy that says is_admin" \
  "NO-GO (BK2): ECO Bank's gate on the copy is not as it must be (on:switch=on,halves=yes,open=yes,shown=1-of-1,stale=3)" "$OUT"
V_NAV_OLD='      if (BankGate::showLink($dataDir ?? null, $store ?? null, is_array($retailer ?? null) ? $retailer : [])): ?>'
V="$(release_variant97 includes/navigation.php "$V_NAV_OLD" "      if (!empty(\$retailer['is_admin'])): ?>")"
S="$(pinned_copy v97nav "$V" "$DEPLOY97")"
fresh97; OUT="$(run97 --answer NO --script "$S")"; rm -f "$S"
nogo97 "2h a menu whose ECO Bank link is not under the pinned showLink() call: NO-GO at BK3, naming the file and what it lacks" \
  "NO-GO (BK3): ECO Bank's links in the pin are not as they were reviewed: navigation.php:gate-calls-0 navigation.php:link-not-under-the-pinned-gate" "$OUT"
# The CEO page's Quick Link (Q15), the phone's More menu and php -l (SPEC §2.10b K3), each made to refuse.
V="$(release_variant97 tabs/admin/ceo_dashboard.php "$V_NAV_OLD" "      if (!empty(\$retailer['is_admin'])): ?>")"
S="$(pinned_copy v97ceo "$V" "$DEPLOY97")"
fresh97; OUT="$(run97 --answer NO --script "$S")"; rm -f "$S"
nogo97 "2h' a CEO page whose ECO Bank Quick Link is not under the pinned showLink() call (Q15): NO-GO at BK3, naming the file and what it lacks" \
  "NO-GO (BK3): ECO Bank's links in the pin are not as they were reviewed: ceo_dashboard.php:gate-calls-0 ceo_dashboard.php:link-not-under-the-pinned-gate" "$OUT"
V="$(release_variant97 tabs/sales/more_menu.php 'href="?page=dashboard&tab=bulk_dispatch"' 'href="?page=dashboard&tab=bank"')"
S="$(pinned_copy v97more "$V" "$DEPLOY97")"
fresh97; OUT="$(run97 --answer NO --script "$S")"
nogo97 "2h'' a pin whose phone More menu links to ECO Bank — a file 5.18.97 must not ship: refused at A0" \
  "the pin carries files that are not 5.18.97's, which this release must not ship: tabs/sales/more_menu.php" "$OUT"
M="$(mutant v97morem "$S" "$A0_OLD" "$A0_NEW")"; rm -f "$S"
fresh97; OUT="$(run97 --answer NO --script "$M")"; rm -f "$M"
nogo97 "2h''' …and with A0's refusal taken out of a copy, BK3 refuses it: the More menu not 5.18.96's byte for byte, and a link to ECO Bank in it (SPEC §2.8.4)" \
  "NO-GO (BK3): ECO Bank's links in the pin are not as they were reviewed: more_menu:changed more_menu:links-to-ECO-Bank" "$OUT"
V="$(release_variant97 lib/BankGate.php "    public const SWITCH_KEY = 'bank_screen_enabled';" "    public const SWITCH_KEY = 'bank_screen_enabled'")"
S="$(pinned_copy v97lint "$V" "$DEPLOY97")"
fresh97; OUT="$(run97 --answer NO --script "$S")"
nogo97 "2h4 a pin whose ECO Bank gate does not parse: NO-GO at A2 (K3's php -l) — the server's own PHP rejects it before a byte is copied, naming the file" \
  "in the container rejects: lib/BankGate.php" "$OUT"
A2_OLD97='[ -z "$L_BAD" ] || stop "NO-GO: PHP $PHPV in the container rejects:$L_BAD"'
M="$(mutant v97lintm "$S" "$A2_OLD97" 'true || stop "NO-GO: PHP $PHPV in the container rejects:$L_BAD"')"; rm -f "$S"
fresh97; OUT="$(run97 --answer NO --script "$M")"; rm -f "$M"
nogo97 "2h4' …and with A2's refusal taken out of a copy, BK3 refuses the same gate by its text: its switch's key line is not the reviewed one" \
  "NO-GO (BK3): ECO Bank's links in the pin are not as they were reviewed: gate:switch-key" "$OUT"
# The switch in the store copy alone (SPEC §2.10b: K2 must stay false). The real pin: GO, BR3 says it, the gate closed as
# found. A gate that also reads the store row: refused at BK2 by that plant — off, ECO Bank shown to the administrator —
# and, the plant taken out, GO: only the plant shows it.
db_keep
row_set "$BK97" 1; D97S="$(data94)"
check "$(both_copies "$BK97")" "1-" "2i the switch planted in the store row alone (set_config.php writes both; the row is what public.php reads, BankGate never)"
fresh97; S0="$(states97)"; OUT="$(run97 --check)"
check "$(checkgo97 "$OUT")|$(okline "$OUT" BR3 "ECO Bank's switch, $BK97, is absent from the configuration files" '(bk=absent/on; files/store)')$(noteline "$OUT" BR3 "$BK97 is in the store row alone (on): BankGate reads the configuration files, never the row, so ECO Bank stays closed")$(grep -cxF '  the gate        as found: switch=absent halves=yes open=no admins=1 shown=0 nonadmins=3 stale=0 ready=yes offered=UGX:2:1:0,USD:1:1:0 both=no' <<<"$OUT")$(okline "$OUT" BK2 'off — no one of the 1 active administrator(s) is shown ECO Bank')" \
      "yesyesnono0|0|$S0|yesyes1yes" \
  "…the real pin: --check GO — BR3 ok and its note (the row alone), the gate closed as found, BK2's off-process shows ECO Bank to nobody: K2 stays false"
V_OPEN_OLD="$(cat <<'EOF'
return self::halves($cfg, (string)$dataDir) && self::switchOn($cfg);
EOF
)"
V_OPEN_NEW="$(cat <<'EOF'
return self::halves($cfg, (string)$dataDir) && (self::switchOn($cfg) || self::switchOn((array)json_decode((string)(new \PDO('sqlite:' . $dataDir . '/plugin.sqlite3'))->query("SELECT data FROM kyc_config WHERE id = 0")->fetchColumn(), true)));
EOF
)"
V97_STORE="$(release_variant97 lib/BankGate.php "$V_OPEN_OLD" "$V_OPEN_NEW")"
S="$(pinned_copy v97store "$V97_STORE" "$DEPLOY97")"
fresh97; OUT="$(run97 --script "$S" --check)"
nogo97 "2i' a gate that also reads the store row, over the planted switch: NO-GO at BK2 — off, the administrator shown ECO Bank — what K2 must never be" \
  "NO-GO (BK2): ECO Bank's gate on the copy is not as it must be (off:switch=absent,halves=yes,open=yes,shown=1,stale=0)" "$OUT" "$D97S"
db_back
check "$(both_copies "$BK97")|$(data94)" "-|$D97" "…the planted row taken out (the book put back byte for byte): the switch in neither place"
fresh97; S0="$(states97)"; OUT="$(run97 --script "$S" --check)"; rm -f "$S"
check "$(checkgo97 "$OUT")|$(okline "$OUT" BK2 'off — no one of the 1 active administrator(s) is shown ECO Bank')" "yesyesnono0|0|$S0|yes" \
  "2i'' control: the same gate, the plant taken out, is GO — only the plant shows it; what reaches the store alone must be planted to be seen"
check "$(quiet97 | cut -d'|' -f1,3-)|$(sha256sum "$QREC" | cut -c1-16)|$(vt_host)" "$P96|$D97|$KEPT97|0|$QREC97|$VT97_0" "…after section 2: 5.18.96 live, the data, the quarantine and the live vault as before"

echo; echo "== 97-2b. the copies are the evidence: the live-book trap; copies without a vault — the cash code's and the gate's; the parts; the adjustment =="
: > "$SB/trap96"
fresh97; OUT="$(run97 --answer NO --env FAKE_LIVE_TRAP="$SB/trap96")"; T="$(last97)"
check "$( [ -e "$SB/trap96" ] && echo unspent || echo spent)$( [ -e "$SB/trap96.fired" ] && echo fired)$(sq "SELECT COUNT(*) FROM cb_ledger WHERE sr = 'RH96-TRAP-1'")|$(went97 "$OUT")|$(okline "$OUT" A23.1 'equals D2 = A')$(okline "$OUT" A23.2 'equals D2 = A')$(okline "$OUT" BK1 'the same to the last digit')" "spentfired1|yesyes|yesyesyes" \
  "2j a row written to the LIVE book the moment the live code starts reading its copy: A23 reads the copies, made before — every equality holds, BK1 too, and the run reaches its question"
sqx "DELETE FROM cb_ledger WHERE sr = 'RH96-TRAP-1'" >/dev/null; rm -f "$SB/trap96.fired"
check "$(data94)|$(hits97 "$T")|$(audit97 "$T" | sed -E 's/^copies=[0-9]+ //; s/ adj=.*//')" "$D97|0|novault=0 plugin=0" \
  "…the trap's row taken out again; the observers quiet: no planted value or default-path vault in /tmp, every PHP process on a copy — ECO Bank's gate processes too — with a vault of its own"
LIVE_READ_OLD97='-e DN_DATA_DIR="$sub/$nm-data" "$CONTAINER" php -d display_errors=stderr -r "$CIHC_PHP" "$sub/$nm/$PLUGIN" "$sub/$nm-data" "$sub/$nm.json" "$want"'
LIVE_READ_NEW97='-e DN_DATA_DIR="$PDD_IN" "$CONTAINER" php -d display_errors=stderr -r "$CIHC_PHP" "$sub/$nm/$PLUGIN" "$PDD_IN" "$sub/$nm.json" "$want"'
PHPERR0="$( [ -e "$DATA/php_error.log" ] && echo present || echo absent)"
# That copy's code opens the LIVE store through the pin's own SqliteStore::create(), whose runner applies the pin's
# migrations — 088 among them — to the sandbox's live book and writes its migration.log: both are kept first and put
# back after, so that the deploy (3) still finds 088 absent and applies it itself (the review of 18 Oct).
db_keep; MIGLOG97=absent; [ -f "$DATA/migration.log" ] && { cp -p "$DATA/migration.log" "$SB/miglog97.kept"; MIGLOG97=present; }
M="$(mutant livestore97 "$DEPLOY97" "$LIVE_READ_OLD97" "$LIVE_READ_NEW97")"
: > "$SB/trap96"
fresh97; OUT="$(run97 --answer NO --script "$M" --env FAKE_LIVE_TRAP="$SB/trap96")"; T="$(last97)"; trip97; rm -f "$M"
check "$( [ -e "$SB/trap96.fired" ] && echo fired)$(grep -c '^value ' "$R97/scan/run-$T.hits" | sed 's/^[1-9][0-9]*$/found/')" "firedfound" \
  "2k a copy whose code reads the LIVE store, the trap planted: the trap fires, and the live data folder's webhook secret turns up in /tmp — the observer finds it"
db_back; if [ "$MIGLOG97" = present ]; then cp -p "$SB/miglog97.kept" "$DATA/migration.log"; else rm -f "$DATA/migration.log"; fi
rm -f "$SB/miglog97.kept" "$SB/trap96.fired"
[ "$PHPERR0" = absent ] && rm -f "$DATA/php_error.log"
O_K297="$(o97)"
check "$(oj "$O_K297" tables)|$(oj "$O_K297" ledger)|$(data94)" "0/5|absent|$D97" \
  "…the live book put back as it was before that run — the trap's row, and 088, which the pin's code applied to the LIVE store through its own runner, taken out: 088 absent, its ledger row too, every table as before"
nogo97 "2k' …and the script refuses it by itself, at A23.1: the live code's figure is not D2 = A's SQL on the copy (caught; the trap's row taken out)" \
  "NO-GO (A23.1): the live code's cash in hand is not D2 = A's SQL on the same copy" "$OUT"
NOVAULT_OLD97='-e DN_VAULT_FILE="$sub/$nm-vault.json" -e DN_DATA_DIR="$sub/$nm-data"'
M="$(mutant novault97 "$DEPLOY97" "$NOVAULT_OLD97" '-e DN_DATA_DIR="$sub/$nm-data"')"
fresh97; OUT="$(run97 --answer NO --script "$M")"; T="$(last97)"; trip97; rm -f "$M"
check "$(went97 "$OUT")|$(grep -c '^file /tmp/dnb-5\.18\.97-cih-[^ ]*/\.dishnet-sudan\.vault\.json$' "$R97/scan/run-$T.hits" | sed 's/^[1-9][0-9]*$/found/')|$(audit97 "$T" | sed -E 's/.* novault=([0-9]+) .*/\1/' | sed 's/^[1-9][0-9]*$/some/')|$(vault97 "$T")" "yesyes|found|some|$VT97_0>$VT97_0" \
  "2l a copy that runs the cash code on its copies without DN_VAULT_FILE reaches the question — caught by the observers: a vault at the code copy's default path in /tmp, PHP on a copy with no vault of its own"
BANKK_EXEC_OLD='o="$(docker exec -u "$DB_OWNER" -e DN_VAULT_FILE="$sub/k-$nm-vault.json" -e DN_DATA_DIR="$sub/k-$nm-data" "$CONTAINER" php -d display_errors=stderr -r "$BANKK_PHP"'
BANKK_EXEC_NEW='o="$(docker exec -u "$DB_OWNER" -e DN_DATA_DIR="$sub/k-$nm-data" "$CONTAINER" php -d display_errors=stderr -r "$BANKK_PHP"'
M="$(mutant banknovault97 "$DEPLOY97" "$BANKK_EXEC_OLD" "$BANKK_EXEC_NEW")"
fresh97; OUT="$(run97 --answer NO --script "$M")"; T="$(last97)"; trip97; rm -f "$M"
check "$(went97 "$OUT")|$(audit97 "$T" | sed -E 's/.* novault=([0-9]+) .*/\1/' | awk '{print ($1 >= 3 ? "three-or-more" : "only-" $1)}')|$(vault97 "$T")|$(data94)" "yesyes|three-or-more|$VT97_0>$VT97_0|$D97" \
  "2m a copy whose gate processes (found, off, on) run on ECO Bank's code copies without a vault of their own reaches the question — caught by the observers' record: three PHP processes on a copy with no DN_VAULT_FILE; the live vault and the data untouched"
M="$(mutant judgeloc97 "$DEPLOY97" "$LOC_OLD" "$LOC_NEW")"
fresh97; OUT="$(run97 --answer NO --script "$M")"; rm -f "$M"
nogo97 "2n a judge whose classes put the mobile-money rows among the other kinds: NO-GO at A23.3 — the parts no longer add up" \
  "NO-GO (A23.3): the parts do not add up to today − D2 = A in every cell" "$OUT"
M="$(mutant noparts97 "$DEPLOY97" "$LOC_OLD" "$LOC_NEW" "$PARTS_OLD" "$PARTS_NEW")"
fresh97; OUT="$(run97 --answer NO --script "$M")"; rm -f "$M"
check "$(went97 "$OUT")$(okline "$OUT" A23.3 'the parts add up')" "yesyesyes" "2n' …a copy without the parts check reaches the question over the same wrong split, and calls the parts added up (caught)"
S="$(pinned_copy v97rule "$V97_GATEFAILS" "$DEPLOY97")"
M="$(mutant norule97 "$S" "$A0_OLD" "$A0_NEW" "$A22T1_OLD" "$A22T1_NEW" "$RULE_OLD" "$RULE_NEW")"; rm -f "$S"
fresh97; OUT="$(run97 --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" 'NO-GO (A23.7)')$(has "$OUT" "$NOGO_A232")$(went97 "$OUT")" "noyesnono" \
  "2o a copy without the rule-in-force check, on the pin whose gate fails: its refusal moves to A23.2, on a figure, no longer naming the rule (caught)"
M="$(mutant adjnamed97 "$DEPLOY97" "$(grep -m1 '^ADJ_CENTS_SHA=' "$DEPLOY97")" "ADJ_CENTS_SHA=\"$ADJ_SYN\"")"
fresh97; OUT="$(run97 --answer NO --script "$M")"; T="$(last97)"; rm -f "$M"
check "$(okline "$OUT" A23.5 'the 12 Sep adjustment is in the book: one row of that day, out, UGX, approved, on no account')$(okline "$OUT" A23.4 "P0's own tool")|$(audit97 "$T" | sed 's/.* adj=//')|$(went97 "$OUT")" "yesyes|$ADJ96|yesyes" \
  "2p a copy given the synthetic adjustment's hash finds that row, names it to P0 by its id, and P0 still agrees — its amount never read out"
check "$(quiet97 | cut -d'|' -f1,3-)|$(vt_host)" "$P96|$D97|$KEPT97|0|$VT97_0" "…after section 2b: 5.18.96 live, the data, the quarantine and the live vault as before"

echo; echo "== 97-3. the deploy, as the operator runs it — DEPLOY typed; a receipt posted between A23 and R23 =="
O_PRE="$(cash97 pre)"; DIG_PRE="$(oj "$O_PRE" digest)"; BOOK_PRE="$(book97x)"; OB_PRE="$(o97)"
LEDGER97="$(ledger95)"; LEDN97="${LEDGER97%%:*}"
touch "$SB/web/.add_receipt97"
fresh97; OUT="$(run97 --answer DEPLOY)"; OUT97D="$OUT"; T97D="$(last97)"
O_POST="$(cash97 post)"; OB_POST="$(o97)"
SHEET97D="$(sheet_of "$OUT")"
echo "  …    the deploy's own tally:$(grep -E '^  checks +[0-9]+ ok, ' <<<"$OUT" | sed -E 's/^  checks +/ /')"
check "$(fails "$OUT")$(has "$OUT" '5.18.97 (deploy): PASSED')$(live)" "0yes$P97" "the deploy PASSES: 0 failed, the container serves $P97"
for l in "ok    A0 the release delta is exactly 5.18.97's $N97 files — 11 added, $(( N97 - 11 )) changed, one migration, 088_bank_statements.sql, the reviewed one (sha256 ${MIG88_SHA97:0:16}…)" \
         "ok    A7 Domain B untouched" \
         "ok    A22L the live code, as installed, carries all seven of 5.18.95's fixes" \
         "ok    BR1 the installed plugin is live 5.18.96 ($P96): all " \
         "ok    BK3 ECO Bank's links in the pin, as text: includes/navigation.php and tabs/admin/ceo_dashboard.php each hold exactly one href=\"?page=dashboard&tab=bank\", under the pinned BankGate::showLink() call" \
         "ok    A14 the stray store is quarantined and the quarantine holds" \
         "ok    A12 on this server's own configuration the pin's gate stops the Starlink retry job" "ok    A13 the pin's retry job" \
         "ok    A15 under this server's own PHP" "ok    A16 under this server's own PHP" \
         "ok    A17 5.18.96's documents, unchanged by 5.18.97, under this server's own PHP" \
         "ok    A22-T1 under this server's own PHP" "($T1SIG97)" \
         "ok    A23.1 the live code's figure — 5.18.96's cashInHand(), installed, its rule A in force, run from its code copy on a copy of its own — equals D2 = A's SQL" \
         "ok    A23.2 the pin's figure equals D2 = A's SQL on the copy" \
         "ok    BK1 cash in hand, 5.18.96's code and 5.18.97's on the same copy, the same to the last digit in all 8 cells" \
         "ok    A23.3 the parts add up in all 8 cells, to the cent" \
         "note  A23.5 the 12 Sep adjustment is not in the book" \
         "ok    A23.7 rule A is in force on this server's own configuration, as both codes read it" \
         "ok    A23.8 the pages' settings row names the service's base and currencies — the same (" \
         "ok    BR2 088's five tables are absent on the copy, its ledger row too" \
         "ok    BR3 ECO Bank's switch, $BK97, is absent from the configuration files" \
         "ok    BK2 BankGate::showLink(), through the pin's code on copies of this book, each switch state in a PHP process of its own: off — no one of the 1 active administrator(s) is shown ECO Bank; on (written into the copy's kyc_config.json only, never the live one) — all 1 are, and none of the 3 active non-administrator(s)" \
         "ok    A24 South Sudan, a South Sudan tenant without SSP and a Uganda tenant with SSP read exactly as under 5.18.96" \
         "GO — evidence recorded" "and without the repair key file, never copied" \
         "checking out $P97 (5.18.97, the release commit) for the documented deploy" "ok    container serves $P97" \
         "ok    V4 no fatal or parse error of" \
         "ok    BA4 ECO Bank (?page=dashboard&tab=bank) and its export (bk_export=csv) without a session each send the visitor to the sign-in page (302)" \
         "ok    V3j ECO Bank's switch, $BK97, is unchanged by this run (before=bk=absent/absent, after=bk=absent/absent; files/store)" \
         "ok    R1 all $N97 files 5.18.97 changes are installed exactly as $P97 has them (11 new)" "ok    R1 the installed manifest says 5.18.97" \
         "ok    BA1 ECO Bank is installed whole" "ok    BK3 ECO Bank's links as installed: includes/navigation.php and tabs/admin/ceo_dashboard.php each hold exactly one" \
         "ok    R5 all" "ok    R7 the database since this run's stage A: the migration ledger's last row 088's, the one row added (" \
         "ok    BA1 migration 088 is applied exactly as the release's 088_bank_statements.sql makes it" " — empty: nothing is kept until the switch is on" \
         "migration.log: " "OK: 088_bank_statements.sql (27 stmts," \
         "ok    R18 the installed retry job" "ok    R20 under this server's own PHP the installed test of master's lock passes" \
         "ok    R21 under this server's own PHP the installed test of the retired auto-pull passes" "ok    R22 the installed documents are 5.18.96's, unchanged by 5.18.97" \
         "ok    R23.7 rule A is in force on this server's own configuration, as the installed code reads it" \
         "ok    R23.1 the installed figure equals D2 = A's SQL on the copy" "ok    R23.2 5.18.96's code (from Git) on the same copy equals D2 = A's SQL in all 8 cells" \
         "ok    BA2 BK1 again: cash in hand under the installed 5.18.97 and under 5.18.96's code, on the same new copy, the same to the last digit in all 8 cells" \
         "ok    R23.3 the parts add up in all 8 cells" "ok    R23.5 the installed Balance column, in every view and project" \
         "ok    R23.6 the installed tools/cash_in_hand.php, run from the installed code's copy on a copy of its own" \
         "note  R23.10 the book moved since A23 (" "1 row(s) added, 0 changed, 0 removed (ids on the sheet)" \
         "ok    BA2 BK2 again, through the installed code on a new copy, each switch state in its own PHP process: off — none of the 1 active administrator(s) is shown ECO Bank; on (the copy's kyc_config.json only) — all 1 are, and none of the 3 active non-administrator(s)" \
         "ok    R24 X5 and D1, statically: T4 on the installed files passes ($T4SIG97)" "ok    R24 the installed CEO page is the pin's byte for byte" \
         "ok    R25 under this server's own PHP the installed T1 passes ($T1SIG97)" \
         "ok    R26 South Sudan, a South Sudan tenant without SSP and a Uganda tenant with SSP read exactly as under 5.18.96 through the installed lib" \
         "ok    Z1 the live vault ($VAULT_IN96) is as this run found it" "ok    Z2 nothing of the vault, no webhook_secret file, no settings file and no copy of the book is left" \
         "note  R19 the quarantine holds — nothing at the old name, nothing a new store makes, the kept file unchanged — but master has not completed a run of the retry job since the copy" \
         "  what changed      ECO Bank, for administrators on this Uganda book with no SSP" \
         "  migrations        088 (088_bank_statements.sql), applied by the plugin's own runner after the copy"; do
  check "$(has "$OUT" "$l")" "yes" "3: ${l:0:110}"
done
check "$(ctl97 "$OUT" A22)$(ctl97 "$OUT" R23)" "77" "5.18.95's seven fixes read with their controls, before (A22, the pin) and after (R23, the installed code): each control names 5.18.94's defects exactly"
check "$(noteids97 "$OUT")" "A17 A23.4 A23.5 A23.6 BN5 R19 R23.10 R23.9" \
  "its notes are exactly 5.18.96's (A17, A23.4–A23.6, R19, R23.9, R23.10 — the receipt) and BN5's, the offered accounts not one per currency named Ecobank"
check "$(noteline "$OUT" A23.6 "($(oj "$O_PRE" counts) — counts of rows not voided)")|$(noteline "$OUT" A23.6 "E17's exception can show now: in $(oj "$O_PRE" blanknewer) scope(s)")|$(noteline "$OUT" A23.4 "$(oj "$O_PRE" notappr) row(s) not approved")" "yes|yes|yes" \
  "the counts, E17's scopes and the rows not approved the script prints are this rehearsal's own, read with its own SQL"
RID97="$(sq "SELECT id FROM cb_ledger WHERE description = 'RH97 receipt posted while the deploy ran'")"
check "$( [ -e "$SB/web/.add_receipt97" ] && echo unspent || echo spent)|$(sq "SELECT COUNT(*) FROM cb_ledger WHERE description = 'RH97 receipt posted while the deploy ran'")|$(python3 "$H97/rh97.py" drift "$SHEET97D")" "spent|1|1:$RID97" \
  "3a the stand-in's receipt was posted during the run, after A23's copy — the drift the sheet names is that one row, by its id"
check "$(python3 "$H97/rh97.py" sheet "$SHEET97D" "$O_PRE" A23)|$(python3 "$H97/rh97.py" sheet "$SHEET97D" "$O_POST" R23)" "8/8|8/8" \
  "3b the sheet's figures are the book's: A23's equal this rehearsal's own SQL before the run, R23's after it — the receipt in, every cell"
check "$(python3 "$H97/rh97.py" ecosheet "$SHEET97D")" "$(python3 "$H97/rh97.py" ecoexp "$OB_PRE" 2)" \
  "3c the sheet's ECO Bank lines, A23's and R23's: each offered account by its row id, currency, named, kept, linked — this rehearsal's own reading; no kept line yet"
check "$(stat -c %a "$SHEET97D")|$(stat -c %a "$OUT97")|$(grep -cxF "      less $SHEET97D" <<<"$OUT")|$(sheetsha_of "$OUT")|$(grep -c 'the physical count' "$SHEET97D")|$(bankhits97 "$SHEET97D")" "600|700|1|$(sha256sum "$SHEET97D" | cut -c1-16)|1|0" \
  "3d the cash sheet is 0600 in a folder of mode 700; F names it with its sha256-16 and prints \`less <path>\` alone; it ends with the physical count's lines; it names no account, number or holder"
ROWS97D="$(sed -n 's/^CIH_A_ROWS=//p' "$STATE97" | tail -1)"
check "$(grep -cE '^CIH_A_AT=20[0-9]{2}-[0-9]{2}-[0-9]{2}T[0-9:]{8}Z$' "$STATE97")|$(sed -n 's/^CIH_A_SHEET=//p' "$STATE97" | tail -1)|$(sed -n 's/^CIH_A_DIGEST=//p' "$STATE97" | tail -1)|$(sed -n 's/^VAULT_A=//p' "$STATE97" | tail -1)" \
      "1|$SHEET97D:$(python3 "$H97/rh97.py" presha "$SHEET97D")|$DIG_PRE|$VT97_0" \
  "3e the state file names A23's time, the sheet with its sha256-16 as A23 left it (its ECO Bank lines in), the row digest this rehearsal computes and the live vault as it was — no figure"
check "$(sed -n 's/^BK_BEFORE=//p' "$STATE97" | tail -1)|$(sed -n 's/^BANK_BEFORE=//p' "$STATE97" | tail -1 | grep -c '^ledger=absent objects=0/27 .* rows=-:-:-:-:- control=yes')|$(grep -c '^BANK_BEFORE=.* sig=' "$STATE97")|$(sed -n 's/^REDEPLOY=//p' "$STATE97" | tail -1)" "bk=absent/absent|1|0|" \
  "3f …and ECO Bank as stage A found it: the switch in neither place, 088 absent — its reading without the HMAC; not a redeploy"
check "$(stat -c %a "$ROWS97D")|$(grep -c . "$ROWS97D")|$(grep -cvE '^[0-9]+ [0-9a-f]{16}$' "$ROWS97D")" "600|$(oj "$O_PRE" rows)|0" "3g A23's row file: 0600, one line per row of the book (an id and a 16-hex hash), nothing else"
check "$(grep -c "^COPY_AT=$(stat -c %Y "$PD/public.php")\$" "$STATE97")$(grep -c '^Q_HOLDS_BEFORE=yes$' "$STATE97")$(grep -c '^STRAY_Q_FAILED=' "$STATE97")" "110" \
  "the state file records the copy's time (as stamp_copy gave public.php, the file the copy is read off since 5.18.97), that the quarantine held before; no failure"
check "$(figs97 "$R97/run-$T97D.txt" "$STATE97" "$ROWS97D" "$OUT97"/*.log "$R97/exec/run-$T97D.rec" 2>/dev/null)|$(grep -cF -f "$SCAN96" "$R97/run-$T97D.txt" "$STATE97" | awk -F: '{s += $NF} END {print s + 0}')|$(bankhits97 "$R97/run-$T97D.txt" "$STATE97" "$ROWS97D")" "0|0|0" \
  "3h no figure of the book in any printed form, no planted secret, no account name, number or holder in the run's terminal, its state file or its row file"
check "$(hits97 "$T97D")|$(audit97 "$T97D" | sed -E 's/^copies=([0-9]+) /\1 /' | awk '{print ($1 >= 15 ? "many" : $1 " only") " " $2 " " $3}')|$(vault97 "$T97D")" "0|many novault=0 plugin=0|$VT97_0>$VT97_0" \
  "3i the observers: nothing planted in /tmp; every PHP process on a copy (fifteen or more — the gate's three per reading among them) carried a vault of its own, none ran the tool or T1 from the plugin folder; the live vault the same"
SUM97="$(sed -n '/^== F\. Summary ==$/,$p' <<<"$OUT")"
check "$(grep -cxF "      $EXP_AFTER97" <<<"$SUM97")$(grep -cxF "      $EXP_QCHECK" <<<"$SUM97")$(grep -cxF "      $EXP_SWON97" <<<"$SUM97")$(grep -cxF "      $EXP_CHECKBANK97" <<<"$SUM97")$(grep -cxF "      $EXP_SWCLR97" <<<"$SUM97")$(grep -cxF "      $EXP_ROLLBACK97" <<<"$SUM97")$(grep -cxF "      $EXP_PUTBACK97" <<<"$SUM97")$(grep -cxF "      $EXP_DEPLOY97" <<<"$OUT")$(grep -cxF "      $EXP_REDEPLOY97" <<<"$OUT")" "111111100" \
  "every later command printed exactly, on its own line — the --after-only, the quarantine's --check, ECO Bank's switch, --check-bank, the switch's clearing, the rollback and the by-hand put-back — and the deploy's own and --redeploy never"
check "$(printed97 "$OUT")|$(order97 "$OUT")|$(rb_where97 "$OUT")|$(grep -c 'deploy-5.18.97.sh --rollback' <<<"$OUT")" "7|0|0|verdict<switch-on<check-bank<clear<rollback<putback|after|1" \
  "…seven commands in the summary, each with its own log wrapper, no line holding two; after the verdict: the switch, --check-bank, then the rollback's two steps — the switch cleared first — and the put-back; the rollback once"
check "$(installed97 "$P97")$(inst_ver)$(data97x)$(book97x)|$(ledger95)" "05.18.97$DX97$BOOK_PRE|$(( LEDN97 + 1 )):088_bank_statements.sql:${MIG88_MD597:0:16}" \
  "every file 5.18.96 → 5.18.97 changes installed as the pin has it; every table of the plugin's data as before, the book as before but for the receipt; the migration ledger one row more — 088's, by the plugin's own runner"
check "$(oj "$OB_POST" tables)|$(oj "$OB_POST" rows)|$(oj "$OB_POST" triggers)|$(oj "$OB_POST" trigmissing)|$(oj "$OB_POST" ledger)|$(both_copies "$BK97")|$(grep -c "OK: 088_bank_statements.sql (27 stmts," "$DATA/migration.log")" \
      "5/5|0:0:0:0:0|11/11|-|applied:$MIG88_MD597|-|1" \
  "088 on the live book, read with this rehearsal's own SQL: five tables, empty; eleven triggers; its ledger row; the runner's log one OK line of 27 statements; the switch in neither place"
check "$(kept_state)$(oldname)$(sha256sum "$QREC" | cut -c1-16)$(host_code97)$(left_tmp97)$(evo_calls)" "${KEPT97}0${QREC97}0${TMP97}0" \
  "the deploy left the quarantine as it was, no copy of the code on the host or in /tmp, and never called Evolution"
BKP97="$(ls -d "$OUT97"/backup-* 2>/dev/null | head -1)"
check "$(tar -tzf "$BKP97/data.tar.gz" 2>/dev/null | grep -c 'emergency_repair_key')$(tar -tzf "$BKP97/data.tar.gz" 2>/dev/null | grep -c '/kyc_config.json$')" "01" \
  "its backup holds the data folder's settings file and never the repair key file (5.18.95's rule, kept)"

echo; echo "== 97-4. --after-only, run exactly as the deploy's summary printed it; its weakened copies; --check-bank before a statement =="
copy_back97 30; COPY97="$(sed -n 's/^COPY_AT=//p' "$STATE97" | tail -1)"
set_sched $(( COPY97 + 300 )); base_log
ALINE="$(printed "$OUT97D" "cd $REPO && bash scripts/deploy-5.18.97.sh --after-only")"
check "$ALINE" "$EXP_AFTER97" "4 the --after-only command, as the deploy's summary printed it"
fresh97; O_A="$(cash97 after)"
OUT="$(run_line97 '' "$ALINE")"; TA="$(last97)"; OUT97A="$OUT"; ALOG="$(ls -1 "$OUT97"/after-*.log 2>/dev/null)"; SHEETA="$(sheet_of "$OUT")"
check "$(fails "$OUT")$(has "$OUT" '5.18.97 (after): PASSED')$(okline "$OUT" R19 'the quarantine holds: master ran the retry job at' "after the copy (at $(utc "$COPY97"))")" "0yesyes" \
  "4a master's run of the job since the copy: R19 conclusive — the quarantine holds — PASSED"
check "$(okline "$OUT" R23.1 'the installed figure equals')$(okline "$OUT" R23.2 "5.18.96's code (from Git) on the same copy")$(noteline "$OUT" R23.10 'the book moved since A23' '1 row(s) added, 0 changed, 0 removed')$(okline "$OUT" Z1 "the live vault is as the deploy's stage A found it")$(okline "$OUT" R7 "the database since the deploy's stage A (its state file): the migration ledger's last row 088's, the one row added (")$(okline "$OUT" R7 "the database since this run's stage A: the migration ledger's last row the same (")$(ctl97 "$OUT" R23)" "yesyesyesyesyesyes7" \
  "…R23 on a new copy, its drift against the deploy's own digest (the receipt); Z1 against the deploy's reading; R7 says 088's one row against the deploy's reading and nothing against its own; R23a–g with their controls"
check "$(okline "$OUT" BA1 "migration 088 is applied exactly as the release's 088_bank_statements.sql makes it" " — none lost since the deploy's stage A, none changed while this run read them")$(okline "$OUT" V3j 'is unchanged by this run (before=bk=absent/absent, after=bk=absent/absent; files/store)')$(okline "$OUT" BA2 'BK2 again')" "yesyesyes" \
  "…BA1 holds 088 as the deploy left it; the switch unchanged (V3j); BA2 the gate again on a new copy"
check "$(python3 "$H97/rh97.py" sheet "$SHEETA" "$O_A" R23)|$(stat -c %a "$SHEETA")|$(grep -cxF "      less $SHEETA" <<<"$OUT")|$(python3 "$H97/rh97.py" drift "$SHEETA")" "8/8|600|1|1:$RID97" \
  "…its own cash sheet, 0600: its figures this rehearsal's own SQL on the book now, its drift the receipt by its id"
check "$(printed97 "$OUT")|$(order97 "$OUT")|$(grep -cxF "      $EXP_AFTER97" <<<"$OUT")$(grep -cxF "      $EXP_SWON97" <<<"$OUT")$(grep -cxF "      $EXP_CHECKBANK97" <<<"$OUT")$(grep -cxF "      $EXP_SWCLR97" <<<"$OUT")$(grep -cxF "      $EXP_ROLLBACK97" <<<"$OUT")" \
      "7|0|0|verdict<switch-on<check-bank<clear<rollback<putback|11111" \
  "…its summary prints the same seven commands, each alone with its own log wrapper, in the same order"
check "$(printf '%s\n' "$ALOG" | grep -c .)|$(stat -c %a "$ALOG" 2>/dev/null)|$(grep -c '5.18.97 (after): PASSED' "$ALOG" 2>/dev/null)|$(figs97 "$R97/line-$TA.txt" "$ALOG")|$(bankhits97 "$R97/line-$TA.txt" "$ALOG")|$(hits97 "$TA")|$(audit97 "$TA" | sed 's/^copies=[0-9]* //; s/ adj=.*//')|$(vault97 "$TA")" \
      "1|600|1|0|0|0|novault=0 plugin=0|$VT97_0>$VT97_0" \
  "…its own log, 0600, holds the run; no figure, account name or number in the terminal or the log; the observers quiet; the live vault untouched"
M="$(mutant fnowrap97 "$DEPLOY97" 'AFTER_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --after-only $(LOGW after)"' 'AFTER_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --after-only"')"
base_log; OUT="$(run97 --script "$M" --after-only)"; rm -f "$M"
check "$(printed97 "$OUT")|$(grep -cxF "      $EXP_AFTER97" <<<"$OUT")" "7|1|0|0" "4a' a copy that prints the --after-only without its log wrapper is caught: one command of seven without it, and not the line the runbook gives"
M="$(mutant r23noappr97 "$DEPLOY97" "$APPR_OLD" '    $frag = " AND (" . $inA . ")";')"
base_log; OUT="$(run97 --script "$M" --after-only)"; rm -f "$M"
check "$(failline "$OUT" R23.1 "the installed cash in hand is not D2 = A's SQL on the copy")$(has "$OUT" '5.18.97 (after): PASSED')" "yesno" "4b a copy whose R23 drops the approved filter FAILS R23.1 (caught)"
M="$(mutant okfig97 "$DEPLOY97" 'ok "R23.1 the installed figure equals' "$OKFIG_NEW")"
base_log; OUT="$(R97_MUTANT_OUT=1 run97 --script "$M" --after-only)"; T="$(last97)"; rm -f "$M"
check "$(okline "$OUT" R23.1 "today's rule — every entry not voided")|$(figs97 "$R97/mutants/run-$T.txt")" "yes|1" "4c a copy whose ok line carries a figure from the sheet is caught by the figure sweep of its own terminal"
CATSHEET_OLD='  echo "                    never paste it, copy it or send it. This log holds none of it. To read it:"
  echo "      less $SHEET"'
M="$(mutant catsheet97 "$DEPLOY97" "$CATSHEET_OLD" "$CATSHEET_OLD
  cat \"\$SHEET\"")"
base_log; OUT="$(R97_MUTANT_OUT=1 run97 --script "$M" --after-only)"; T="$(last97)"; rm -f "$M"
check "$(figs97 "$R97/mutants/run-$T.txt")|$(grep -c '^== R23 — cash in hand AFTER the deploy' <<<"$OUT")|$(grep -c '^    account #[0-9]*  ' <<<"$OUT")" "1|1|3" \
  "4d a copy that prints the cash sheet to the terminal is caught by the same sweep — and would carry ECO Bank's row ids there too"
cp -p "$VAULT" "$SB/vault97.kept"
M="$(mutant toolplugin97 "$DEPLOY97" "$TOOL_OLD" "$TOOL_NEW")"
base_log; OUT="$(run97 --script "$M" --after-only)"; T="$(last97)"; trip97; rm -f "$M"
TP97="$(if [ "$(fails "$OUT")" = "0" ]; then echo blind; elif [ "$(fails "$OUT")" = "1" ] && [ "$(failline "$OUT" R23 'the live vault')" = yes ]; then echo vault-watch; else echo "other($(fails "$OUT"))"; fi)"
check "$(grep -c '^\(value\|file\) /tmp/dnb-5\.18\.97-cih-[^ ]*/tool-data/webhook_secret$' "$R97/scan/run-$T.hits" | sed 's/^[1-9][0-9]*$/found/')|$(audit97 "$T" | sed -E 's/.* novault=([0-9]+) plugin=([0-9]+) .*/\1:\2/; s/^[1-9][0-9]*:/some:/')|$(okline "$OUT" R23.6 'the installed tools/cash_in_hand.php')|$(case "$TP97" in blind|vault-watch) echo script-blind-or-vault-watch ;; *) echo "$TP97" ;; esac)" \
      "found|some:1|yes|script-blind-or-vault-watch" \
  "4e a copy that runs the installed tool from the plugin folder with no vault of its own is caught by the observers: the live vault's webhook secret restored into /tmp, the tool run from the plugin folder ($TP97)"
cp -p "$SB/vault97.kept" "$VAULT"
check "$(vt_host)" "$VT97_0" "…the live vault put back: bytes and time as before"
CEO_LINE97="$(grep -m1 -F 'foreach (dn_book_currencies($config ?? null) as $_ceoCihCur) $_ceoCih[$_ceoCihCur] = $_ceoCb->cashInHand($_ceoCihCur);' "$PD/tabs/admin/ceo_dashboard.php")"
cp -p "$PD/tabs/admin/ceo_dashboard.php" "$SB/ceo97.kept"
edit_installed tabs/admin/ceo_dashboard.php "$CEO_LINE97" "$CEO_LINE97
            \$_ceoPositions = \$_ceoCb->currencyPositions();"
base_log; OUT="$(run97 --after-only)"
check "$(failline "$OUT" R24 'T4 on the installed files does not pass')$(failline "$OUT" R24 "the installed CEO page is not the pin's")$(failline "$OUT" R1 'installed files that differ' 'tabs/admin/ceo_dashboard.php')" "yesyesyes" \
  "4f a second currencyPositions() call put into the INSTALLED CEO page's rule-A branch: R24 FAILS twice and R1 names it"
M="$(mutant r24blind97 "$DEPLOY97" "$R24A_OLD" "$R24A_NEW" "$R24B_OLD" 'if true; then')"
base_log; OUT="$(run97 --script "$M" --after-only)"; rm -f "$M"
check "$(okline "$OUT" R24 'X5 and D1, statically')$(okline "$OUT" R24 "the installed CEO page is the pin's")$(failline "$OUT" R1 'installed files that differ')" "yesyesyes" "4f' a copy whose R24 is blind to the CEO page passes R24 over the edit (caught) — only R1's bytes still see it"
cat "$SB/ceo97.kept" > "$PD/tabs/admin/ceo_dashboard.php"; touch -r "$SB/ceo97.kept" "$PD/tabs/admin/ceo_dashboard.php"
check "$(installed97 "$P97")$(stat -c '%u:%g' "$PD/tabs/admin/ceo_dashboard.php")" "01000:1000" "…the installed CEO page put back: every file as the pin has it"
LEDGER97A="$(ledger95)"
touch "$SB/web/.add_ledger"; base_log; OUT="$(run97 --after-only)"
check "$(failline "$OUT" R7 'the migration ledger CHANGED' "since this run's stage A")$(failline "$OUT" R7 'the migration ledger CHANGED' "since the deploy's stage A (its state file)")$( [ -e "$SB/web/.add_ledger" ] && echo unspent || echo spent)$(sq "SELECT COUNT(*) FROM _migrations WHERE filename = '999_rehearsal_planted.sql'")" "yesyesspent1" \
  "4g a ledger row that is not 088's, written during the run after stage A read the ledger: R7 FAILS against both readings — the one row it accepts is 088's alone"
sqx "DELETE FROM _migrations WHERE filename = '999_rehearsal_planted.sql'" >/dev/null
R7ANY_OLD='[ "$nn" = "$((bn + 1))" ] && [ "${2#*:}" = "${MIG88#migrations/}:${MIG88_MD5:0:16}" ] && [ "$bf" != "${MIG88#migrations/}" ]'
M="$(mutant r7any97 "$DEPLOY97" "$R7ANY_OLD" '[ "$nn" = "$((bn + 1))" ]')"
touch "$SB/web/.add_ledger"; base_log; OUT="$(R97_MUTANT_OUT=1 run97 --script "$M" --after-only)"; rm -f "$M"
check "$(okline "$OUT" R7 "the database since this run's stage A: the migration ledger's last row 088's, the one row added (")$(failline "$OUT" R7 'the migration ledger CHANGED' "since this run's stage A")$(sq "SELECT COUNT(*) FROM _migrations WHERE filename = '999_rehearsal_planted.sql'")" "yesno1" \
  "4g' a copy that takes any one row added for 088's calls the planted row 088's against its own stage A (caught)"
sqx "DELETE FROM _migrations WHERE filename = '999_rehearsal_planted.sql'" >/dev/null
check "$(ledger95)" "$LEDGER97A" "…the planted row taken out again: the ledger as the deploy left it"
D97H="$(data94)"; db_keep
sqx "DROP TRIGGER bank_lines_no_delete" >/dev/null
base_log; OUT="$(run97 --after-only)"
check "$(failline "$OUT" BA1 "migration 088 is on the live database, but not as the release's 088_bank_statements.sql makes it:" 'triggers 10/11' 'missing: bank_lines_no_delete')$(has "$OUT" '5.18.97 (after): PASSED')" "yesno" \
  "4h one of 088's triggers dropped on the live book: BA1 FAILS, naming it — the runner never applies a recorded migration again"
OUT="$(run97 --check-bank)"
check "$(failline "$OUT" CB "088 is on this book, but not as the release's 088 makes it:" 'triggers 10/11' 'missing: bank_lines_no_delete')$(has "$OUT" '5.18.97 (check-bank): PASSED')" "yesno" \
  "4h' …and --check-bank over the same book FAILS CB, naming the trigger missing — 088 on the book only in part (its FAIL path)"
db_back
check "$(oj "$(o97)" triggers)|$(data94)" "11/11|$D97H" "…the book put back: 088's eleven triggers, every row as before"
touch "$SB/web/.leak_bank97"; base_log; OUT="$(run97 --after-only)"
check "$(failline "$OUT" BA4 'does not answer with the sign-in page as it must: the screen 200|other|1|00, the export 302|login|0|00')$( [ -e "$SB/web/.leak_bank97" ] && echo unspent || echo spent)$(has "$OUT" '5.18.97 (after): PASSED')" "yesspentno" \
  "4h'' ECO Bank's screen answering a visitor with no session with a card (the stand-in's .leak_bank97): BA4 FAILS, naming each answer — the export's still the sign-in page"
fresh97; OUT="$(run97 --check)"
nogo97 "4i --check with 5.18.97 live: refused — it is stage A before a deploy — and the --after-only printed instead, alone" \
  "--check is stage A before a deploy, and the container already serves $P97 (5.18.97) — run --after-only instead, its own command, alone:" "$OUT" "$(data94)" "$P97"
check "$(grep -cxF "      $EXP_AFTER97" <<<"$OUT")" "1" "…the --after-only on its own line, with its own log"
CBLINE="$(printed "$OUT97A" "cd $REPO && bash scripts/deploy-5.18.97.sh --check-bank")"
check "$CBLINE" "$EXP_CHECKBANK97" "4j --check-bank, as the --after-only's summary printed it"
fresh97; OB_CB="$(o97)"; S0="$(states97)"
OUT="$(run_line97 '' "$CBLINE")"; T="$(last97)"; SHCB="$(sheet_of "$OUT")"; CBLOG="$(ls -1 "$OUT97"/check-bank-*.log 2>/dev/null)"
check "$(fails "$OUT")$(has "$OUT" '5.18.97 (check-bank): PASSED')|$(okline "$OUT" CB "088 is applied on the copy exactly as the release's 088 makes it" 'its tables hold 0:0:0:0:0')$(noteline "$OUT" CB 'not every ECO BANK card shows a figure yet: UGX: 2 offered, 1 named Ecobank, 0 of them with a statement kept; USD: 1 offered, 1 named Ecobank, 0 of them with a statement kept')" "0yes|yesyes" \
  "4j' before any statement: --check-bank PASSES — 088 as the release makes it, empty — and says not every card shows a figure yet, in counts"
check "$(python3 "$H97/rh97.py" ecoterm "$R97/line-$T.txt")|$(python3 "$H97/rh97.py" ecosheet "$SHCB")" "$(python3 "$H97/rh97.py" ecotermexp "$OB_CB")|$(python3 "$H97/rh97.py" ecoexp "$OB_CB" 1)" \
  "…one line per offered account in the cards' order — currency, named, kept, linked, no kept line — and the sheet the same with each row id: this rehearsal's own reading"
check "$(printed97 "$OUT")|$(grep -cE '#[0-9]+' <<<"$OUT")|$(states97)|$(backups97)|$(stat -c %a "$SHCB")|$(stat -c %a "$CBLOG" 2>/dev/null)|$(figs97 "$R97/line-$T.txt" "$CBLOG")|$(bankhits97 "$R97/line-$T.txt" "$CBLOG" "$SHCB")|$(hits97 "$T")|$(audit97 "$T" | sed 's/^copies=[0-9]* //; s/ adj=.*//')|$(vault97 "$T")" \
      "0|0|0|0|$S0|0|600|600|0|0|0|novault=0 plugin=0|$VT97_0>$VT97_0" \
  "…it prints no command but \`less\`, no row id in the terminal, writes no state and no backup; its sheet and its log 0600; no figure, name or number; the observers quiet, the vault untouched"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "4k the quarantine's own --check, as the runbook orders after this: PASSED with 5.18.97 live"
QREC97="$(sha256sum "$QREC" | cut -c1-16)"; KEPT97="$(kept_state)"

echo; echo "== 97-5. ECO Bank's switch, run exactly as the summary printed it; the --after-only after it =="
SWLINE="$(printed "$OUT97A" "docker exec -u $DBO97 -e DN_DATA_DIR=$PDD97 -w $IN_CONTAINER ucrm php tools/set_config.php --key $BK97 --value 1")"
check "$SWLINE" "$EXP_SWON97" "5 the switch command, as the --after-only's summary printed it"
fresh97; OUT="$(run_cmd97 "$SWLINE")"; SWLOG="$(ls -1 "$OUT97"/switch-on-*.log 2>/dev/null)"
check "$(grep -cx "  $BK97 = 1" <<<"$OUT")$(grep -c 'AI SETTINGS' <<<"$OUT")|$(printf '%s\n' "$SWLOG" | grep -c .)|$(stat -c %a "$SWLOG" 2>/dev/null)|$(grep -cx "  $BK97 = 1" "$SWLOG")$(grep -c 'AI SETTINGS' "$SWLOG")|$(both_copies "$BK97")|$(vt_host)" "10|1|600|10|11|$VT97_0" \
  "5a it sets the one switch and says so — the one line, never the other settings' listing — in its own 0600 log; both copies set, as set_config.php writes them; the live vault untouched"
copy_back97 30; COPY97="$(sed -n 's/^COPY_AT=//p' "$STATE97" | tail -1)"; set_sched $(( COPY97 + 300 )); base_log
OUT="$(run_line97 '' "$ALINE")"; T="$(last97)"; OUT97A2="$OUT"
check "$(fails "$OUT")$(has "$OUT" '5.18.97 (after): PASSED')|$(noteline "$OUT" V3j "$BK97 is not as the deploy found it (absent/absent → on/on; files/store)")$(okline "$OUT" V3j 'is unchanged by this run (before=bk=on/on, after=bk=on/on; files/store)')$(okline "$OUT" BA2 'BK2 again')" "0yes|yesyesyes" \
  "5b the --after-only after it PASSES: V3j says the switch is not as the deploy found it — the operator's, never this script's — and that this run left it as it was; the gate again on a new copy"
check "$(printed97 "$OUT")|$(order97 "$OUT")|$(grep -c "set_config.php --key $BK97 --value 1" <<<"$OUT")|$(figs97 "$R97/line-$T.txt")|$(hits97 "$T")|$(vault97 "$T")" "6|0|0|verdict<check-bank<clear<rollback<putback|0|0|0|$VT97_0>$VT97_0" \
  "…its summary no longer prints the switch command — the switch is on — and the rest as before, in order"
cfg_keep; D97W="$(data94)"
printf '%s' "$BK97" > "$SB/web/.flip_switch"
base_log; OUT="$(run97 --after-only)"
check "$(failline "$OUT" V3j "ECO Bank's switch, $BK97, CHANGED across this run (before=bk=on/on, after=bk=absent/absent; files/store)")$( [ -e "$SB/web/.flip_switch" ] && echo unspent || echo spent)$(has "$OUT" '5.18.97 (after): PASSED')" "yesspentno" \
  "5c the switch cleared in both places while an --after-only runs, after its stage A read it (the stand-in's .flip_switch): V3j FAILS, naming both readings — this script never sets it"
cfg_back
check "$(both_copies "$BK97")|$(data94)" "11|$D97W" "…the switch put back in both places, as the switch command left it: every table and file as before"

echo; echo "== 97-6. the rollback, run as printed — the switch left on (step 1 skipped); refusals; --check --redeploy; --redeploy =="
RLINE="$(printed "$OUT97A2" "cd $REPO && bash scripts/deploy-5.18.97.sh --rollback")"
check "$RLINE" "$EXP_ROLLBACK97" "6 the rollback command, as the --after-only's summary printed it after its verdict"
S97SHA="$(sha256sum "$STATE97" | cut -c1-64)"; DR0="$(data94)"; LEDGER97R="$(ledger95)"
fresh97; O_RB="$(cash97 rb)"
OUT="$(run_line97 ROLLBACK "$RLINE")"; TR="$(last97)"; RLOG="$(ls -1 "$OUT97"/rollback-*.log 2>/dev/null)"; SHEETR="$(sheet_of "$OUT")"
check "$(fails "$OUT")$(has "$OUT" '5.18.97 (rollback): PASSED')$(live)" "0yes$P96" "6a the rollback PASSES: 5.18.96 serving again"
check "$(has "$OUT" "  note  ECO Bank's switch is still set (bk=on/on; files/store): step 1 of the rollback clears it with 5.18.97's own tool, which 5.18.96's refuses")$(sed -n '/^== B\./,/^== V\./p' <<<"$OUT" | grep -cxF "      $EXP_SWCLR97")" "yes1" \
  "…before its question it says the switch is still set and prints step 1, alone, with its own log"
for l in "ok    container serves $P96 (5.18.96)" "ok    RB the installed manifest says 5.18.96" \
         "ok    RB all 14 files 5.18.97 changed are 5.18.96's again, byte for byte (sha256); the 11 files it added — ECO Bank's library, screen, post handler, migration 088, a fixture and four tests — stay on disk; of them 5.18.96 reads only migrations/088_bank_statements.sql" \
         "ok    RB ECO Bank is gone from the code: no BankGate in the menu, the CEO page, public.php, the post handlers or the routes" \
         "ok    RB 5.18.95's seven fixes are still installed after the rollback" \
         "ok    RB cash in hand reads exactly as before the deploy on a copy of the book" \
         "ok    RB the database across the rollback: the migration ledger's last row the same (" \
         "ok    RB 088's tables stay after the rollback, complete — all 27 objects as the release's 088 makes them — and every row exactly as before it (0:0:0:0:0" \
         "note  RB ECO Bank's switch, $BK97, is still set (bk=on/on; files/store): 5.18.96 does not read it, and its tools/set_config.php refuses the key" \
         "note  RB 5.18.96's emergency repair page does not list 088's five tables among those it refuses to drop" \
         "ok    RB the documents are still 5.18.96's" "ok    RB every authorisation record still matches its terms under 5.18.96's code" \
         "ok    RB the quarantine still holds, as its record says" \
         "ok    Z1 the live vault is as the deploy's stage A found it" "ok    Z2 nothing of the vault" \
         "  serving           $P96  (plugin 5.18.96)" "  what it means     ECO Bank goes"; do
  check "$(has "$OUT" "$l")" "yes" "6a: ${l:0:110}"
done
check "$(grep -cxF "      $EXP_QCHECK" <<<"$OUT")$(grep -cxF "      $EXP_REDEPLOY97" <<<"$OUT")$(grep -cxF "      $EXP_DEPLOY97" <<<"$OUT")$(grep -c 'deploy-5.18.97.sh --rollback' <<<"$OUT")|$(printed97 "$OUT")|$(grep -cxF "      less $SHEETR" <<<"$OUT")" "1200|2|0|0|1" \
  "…it prints the quarantine's --check and --redeploy (RB's note and the summary), each alone with its own log, never the plain deploy or a rollback"
check "$(python3 "$H97/rh97.py" sheet "$SHEETR" "$O_RB" RB | cut -d/ -f1)|$(stat -c %a "$SHEETR")" "8|600" "…the rollback's own cash sheet, 0600: D2 = A on a copy, this rehearsal's own SQL in every cell"
check "$(installed97 "$P96")$(inst_ver)$(for f in $ADDED97; do [ -f "$PD/$f" ] && echo kept; done | grep -c kept)" "05.18.9611" "every changed file as 5.18.96 has it, manifest 5.18.96; the eleven added files stay on disk"
check "$(printf '%s\n' "$RLOG" | grep -c .)|$(stat -c %a "$RLOG" 2>/dev/null)|$(grep -c '5.18.97 (rollback): PASSED' "$RLOG" 2>/dev/null)|$(sha256sum "$STATE97" | cut -c1-64)|$(ls "$OUT97"/rollback-state-*.env 2>/dev/null | grep -c .)|$(grep -c "^RB_COPY_AT=$(stat -c %Y "$PD/public.php")\$" "$OUT97"/rollback-state-*.env 2>/dev/null)" "1|600|1|$S97SHA|1|1" \
  "…its own log, 0600; the deploy's state file untouched — the rollback's record is a file of its own, holding its copy's time (RB_COPY_AT, as stamp_copy gave public.php) for A14 of a later deploy"
check "$(data94)$(ledger95)$(kept_state)$(oldname)$(sha256sum "$QREC" | cut -c1-16)$(host_code97)$(left_tmp97)|$(figs97 "$R97/line-$TR.txt" "$RLOG")|$(bankhits97 "$R97/line-$TR.txt" "$RLOG")|$(hits97 "$TR")|$(vault97 "$TR")|$(both_copies "$BK97")" \
      "$DR0$LEDGER97R${KEPT97}0${QREC97}0${TMP97}|0|0|0|$VT97_0>$VT97_0|11" \
  "the rollback changed no data — 088's tables stay, the switch stays set — not the ledger and not the quarantine; no copy left, no figure printed; the observers quiet"
fresh97; OUT="$(run97 --check --redeploy)"
nogo97 "6a' --check --redeploy right after the rollback, before the quarantine's --check its runbook asks for: NO-GO at A14 — the rollback's own copy came after the newest passing --check (rule 3)" \
  "NO-GO (A14): a rollback of 5.18.97 by this script copied the plugin at" "$OUT" "$DR0"
check "$(has "$OUT" 'after the newest passing --check of the quarantine, begun')$(has "$OUT" "($(basename "$OUT97")/rollback-state-")$(grep -cxF "      $EXP_QCHECK" <<<"$OUT")" "yesyes1" \
  "…naming the rollback's own record, and printing the quarantine's --check on its own line, with its own log"
A14OWN_OLD97='for f in "$STATE" "$STATE".prev-* "$OUT"/rollback-state-*.env; do'
M="$(mutant a14own97 "$DEPLOY97" "$A14OWN_OLD97" 'for f in /rh97-none; do')"
fresh97; S0="$(states97)"; OUT="$(run97 --script "$M" --check --redeploy)"; rm -f "$M"
check "$(checkgo97 "$OUT")" "yesyesnono0|0|$S0" "6a'' a copy blind to this script's own copies says GO over the same rollback (caught)"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "…the quarantine's own --check after the rollback, as RB asks: PASSED"
QREC97="$(sha256sum "$QREC" | cut -c1-16)"; KEPT97="$(kept_state)"
fresh97; OUT="$(run_cmd97 "$EXP_SWCLR97")"
check "$(has "$OUT" "\"$BK97\" is not one of the settings this tool manages.")|$(both_copies "$BK97")|$(vt_host)" "yes|11|$VT97_0" \
  "6b step 1 run only now, with 5.18.96 back: its tools/set_config.php refuses the key — the switch stays set in both places; why the rollback's order is the switch first"
D97="$(data94)"
fresh97; OUT="$(run97 --answer NO)"
nogo97 "6c a deploy over that rollback: NO-GO at BR3 — the switch present in the configuration files — naming --redeploy" \
  "NO-GO (BR3): $BK97 is present in the configuration files (on on the copy; bk=on/on, files/store), although 5.18.96 is live" "$OUT"
check "$(grep -cxF "      $EXP_REDEPLOY97" <<<"$OUT")$(noteline "$OUT" BR1 "11 of 5.18.97's own files are on disk although --redeploy was not asked for")$(okline "$OUT" BR2 '088 is already recorded on the copy' '(0:0:0:0:0: profiles:checks:statements:lines:events)')" "1yesyes" \
  "…--redeploy printed alone, with its own log; BR1 says 5.18.97's eleven files are on disk; BR2 holds 088 recorded and empty"
fresh97; S0="$(states97)"; OUT="$(run97 --check --redeploy)"
check "$(checkgo97 "$OUT")|$(noteline "$OUT" BR3 "--redeploy: $BK97 is present in the configuration files (on on the copy; bk=on/on, files/store) — left by a rollback done without clearing it. ECO Bank shows to administrators as soon as the files are in place")$(grep -cxF "      $EXP_REDEPLOY97" <<<"$OUT")$(grep -cxF "      $EXP_DEPLOY97" <<<"$OUT")$(has "$OUT" 'BR1 11 of')" \
      "yesyesnono0|0|$S0|yes10no" \
  "6d --check --redeploy: GO — BR3 is a NOTE with --redeploy, in the words SPEC §2.10b gives it; its summary prints --redeploy alone, never the plain deploy; no BR1 note"
fresh97; OUT="$(run97 --answer DEPLOY --redeploy)"; OUT97RD="$OUT"
check "$(fails "$OUT")$(has "$OUT" '5.18.97 (deploy): PASSED')$(live)$(installed97 "$P97")$(inst_ver)" "0yes${P97}05.18.97" "6e --redeploy, DEPLOY typed: PASSED, $P97 serving, every file as the pin has it"
check "$(okline "$OUT" BA1 "migration 088 is applied exactly as" ' — empty: nothing is kept until the switch is on')$(okline "$OUT" R7 "the database since this run's stage A: the migration ledger's last row the same (")$(okline "$OUT" V3j 'is unchanged by this run (before=bk=on/on, after=bk=on/on; files/store)')$(noteline "$OUT" BR3 '--redeploy:')|$(ledger95)|$(order97 "$OUT")|$(grep -cxF "      $EXP_SWON97" <<<"$OUT")" \
      "yesyesyesyes|$LEDGER97R|verdict<check-bank<clear<rollback<putback|0" \
  "…088 kept as it was (empty), the ledger the same, the switch on as the operator left it — so no switch command printed, the rest in order"

echo; echo "== 97-7. statements kept on both cards (the installed store, the administrator); --after-only; --check-bank =="
KEEP97="$(cd "$H97/v" && setpriv --reuid 1000 --regid 1000 --clear-groups -- env DN_VAULT_FILE="$H97/v/keep-vault.json" php "$H97/rh97-keep.php" "$PD" "$DATA" "$BOOKIDS" "$ADM97" "$H97/v/stmt97.amounts" 2>"$H97/v/keep.err")"
cat "$H97/v/stmt97.amounts" > "$SB/stmt97.amounts" 2>/dev/null; chmod 600 "$SB/stmt97.amounts"; rm -f "$H97/v/stmt97.amounts" "$H97/v/keep-vault.json"
O_K="$(o97)"
check "$KEEP97|$(oj "$O_K" offered)|$(oj "$O_K" list)|$(oj "$O_K" both)|$(oj "$O_K" rows | sed -E 's/^2:2:2:70:[1-9][0-9]*$/kept/')|$(vt_host)" \
      "UGX:40:linked USD:30:linked|UGX:2:1:1,USD:1:1:1|UGX/yes/yes/yes;UGX/no/no/no;USD/yes/yes/yes|yes|kept|$VT97_0" \
  "7 a synthetic Account Details statement checked and kept, linked, on each Ecobank account — 40 and 30 lines — by the active administrator through the installed store; the live vault untouched"
copy_back97 30; COPY97="$(sed -n 's/^COPY_AT=//p' "$STATE97" | tail -1)"; set_sched $(( COPY97 + 300 )); base_log
ALINE2="$(printed "$OUT97RD" "cd $REPO && bash scripts/deploy-5.18.97.sh --after-only")"
fresh97; OUT="$(run_line97 '' "$ALINE2")"; T="$(last97)"; OUT97A3="$OUT"
check "$(fails "$OUT")$(has "$OUT" '5.18.97 (after): PASSED')|$(okline "$OUT" BA1 "migration 088 is applied exactly as" "its tables hold $(oj "$O_K" rows) (profiles:checks:statements:lines:events) — none lost since the deploy's stage A, none changed while this run read them")$(okline "$OUT" BA2 'BK2 again')|$(figs97 "$R97/line-$T.txt")|$(bankhits97 "$R97/line-$T.txt")" \
      "0yes|yesyes|0|0" "7a --after-only PASSES with the statements kept: BA1 sees them, none lost since the deploy, none changed while it read; no figure, name or number printed"
CBLINE2="$(printed "$OUT97A3" "cd $REPO && bash scripts/deploy-5.18.97.sh --check-bank")"
fresh97; OUT="$(run_line97 '' "$CBLINE2")"; T="$(last97)"; SHCB="$(sheet_of "$OUT")"
check "$(fails "$OUT")$(has "$OUT" '5.18.97 (check-bank): PASSED')|$(okline "$OUT" CB "both ECO BANK cards show a figure: in every book currency an offered account named Ecobank, each with a statement kept (UGX: 2 offered, 1 named Ecobank, 1 of them with a statement kept; USD: 1 offered, 1 named Ecobank, 1 of them with a statement kept)")" "0yes|yes" \
  "7b --check-bank PASSES and says both ECO BANK cards show a figure — 5.18.98's R3, asked early — in counts"
check "$(python3 "$H97/rh97.py" ecoterm "$R97/line-$T.txt")|$(python3 "$H97/rh97.py" ecosheet "$SHCB")" "$(python3 "$H97/rh97.py" ecotermexp "$O_K")|$(python3 "$H97/rh97.py" ecoexp "$O_K" 1)" \
  "…its lines: each offered account's currency, named, kept, linked and its last kept line's age in days; the sheet each one's row id and that line's date — this rehearsal's own reading of the kept statements"
check "$(grep -cE '#[0-9]+' <<<"$OUT")|$(for x in $(oj "$O_K" last | tr ',' ' '); do grep -cF "${x#*:}" <<<"$OUT"; done | awk '{s += $1} END {print s + 0}')|$(figs97 "$R97/line-$T.txt")|$(bankhits97 "$R97/line-$T.txt" "$SHCB")|$(hits97 "$T")|$(vault97 "$T")" "0|0|0|0|0|$VT97_0>$VT97_0" \
  "…the terminal carries no row id and no kept line's date, no figure of a statement, no account name, number, holder or counterparty (nor the sheet a name or number); the observers quiet"

echo; echo "== 97-8. the rollback's two steps, as printed: the switch cleared, then ROLLBACK; refusals; a trigger dropped; --redeploy =="
CLRLINE="$(printed "$OUT97A3" "docker exec -u $DBO97 -e DN_DATA_DIR=$PDD97 -w $IN_CONTAINER ucrm php tools/set_config.php --key $BK97 --clear")"
check "$CLRLINE" "$EXP_SWCLR97" "8 step 1, as the --after-only's summary printed it"
fresh97; OUT="$(run_cmd97 "$CLRLINE")"; CLRLOG="$(ls -1 "$OUT97"/switch-clear-*.log 2>/dev/null)"
check "$(grep -cx "  $BK97 cleared — back to the default." <<<"$OUT")$(grep -c 'AI SETTINGS' <<<"$OUT")|$(stat -c %a "$CLRLOG" 2>/dev/null)|$(both_copies "$BK97")|$(vt_host)" "10|600|-|$VT97_0" \
  "8a step 1: 5.18.97's own tool clears the switch in both places and says so, in its own 0600 log; the vault untouched"
RLINE2="$(printed "$OUT97A3" "cd $REPO && bash scripts/deploy-5.18.97.sh --rollback")"
O_KR="$(o97)"; S97SHA="$(sha256sum "$STATE97" | cut -c1-64)"
fresh97; OUT="$(run_line97 ROLLBACK "$RLINE2")"; T="$(last97)"
check "$(fails "$OUT")$(has "$OUT" '5.18.97 (rollback): PASSED')$(live)|$(has "$OUT" "ECO Bank's switch is still set")|$(okline "$OUT" RB "088's tables stay after the rollback, complete" "every row exactly as before it ($(oj "$O_KR" rows):")$(okline "$OUT" RB "ECO Bank's switch, $BK97, is cleared in both places (bk=absent/absent; files/store)")$(okline "$OUT" RB 'ECO Bank is gone from the code')|$(oj "$(o97)" rows)|$(sha256sum "$STATE97" | cut -c1-64)" \
      "0yes${P96}|no|yesyesyes|$(oj "$O_KR" rows)|$S97SHA" \
  "8b step 2, ROLLBACK typed: PASSED — no switch to warn of; 088's tables and every statement kept stay, every row as before; the switch cleared; ECO Bank gone from the code; the deploy's state untouched"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "…the quarantine's own --check after the rollback: PASSED"
QREC97="$(sha256sum "$QREC" | cut -c1-16)"; KEPT97="$(kept_state)"; D97="$(data94)"
fresh97; OUT="$(run97 --answer NO)"
nogo97 "8c a deploy over that rollback: NO-GO at BR2 — 088's tables hold the statements kept — naming --redeploy" \
  "NO-GO (BR2): 088's tables hold rows on this server ($(oj "$O_KR" rows): profiles:checks:statements:lines:events), although 5.18.96 is live" "$OUT"
check "$(grep -cxF "      $EXP_REDEPLOY97" <<<"$OUT")" "1" "…--redeploy printed alone, with its own log"
db_keep
sqx "DROP TRIGGER bank_lines_no_delete" >/dev/null
D97T="$(data94)"
fresh97; OUT="$(run97 --answer NO --redeploy)"
nogo97 "8d one of 088's triggers dropped: --redeploy refuses at BR2 — 088 on the book only in part" \
  "NO-GO (BR2): 088 is on this server's book only in part, or not as the release's 088_bank_statements.sql makes it" "$OUT" "$D97T"
check "$(has "$OUT" 'triggers 10/11')$(has "$OUT" 'missing: bank_lines_no_delete')" "yesyes" "…naming what is missing, by name and count"
BR2P_OLD='      stop "NO-GO (BR2): 088 is on this server'"'"'s book only in part'
M="$(mutant br2part97 "$DEPLOY97" "$BR2P_OLD" '      true || stop "NO-GO (BR2): 088 is on this server'"'"'s book only in part')"
fresh97; S0="$(states97)"; OUT="$(run97 --script "$M" --check --redeploy)"; rm -f "$M"
check "$(checkgo97 "$OUT")" "yesyesnono0|0|$S0" "8d' a copy that accepts 088 in part says GO over the dropped trigger (caught)"
db_back
check "$(oj "$(o97)" triggers)|$(data94)" "11/11|$D97" "…the book put back: 088's eleven triggers, every kept row"
fresh97; OUT="$(run97 --answer DEPLOY --redeploy)"; OUT97RD2="$OUT"
check "$(fails "$OUT")$(has "$OUT" '5.18.97 (deploy): PASSED')$(live)|$(okline "$OUT" BR2 '--redeploy: 088 is recorded on the copy and intact')$(noteline "$OUT" BR2 "--redeploy: 088's tables hold what was kept before the rollback ($(oj "$O_KR" rows):")$(okline "$OUT" BA1 "migration 088 is applied exactly as" ' — exactly the rows kept before the rollback, every one unchanged (--redeploy)')|$(oj "$(o97)" rows)" \
      "0yes${P97}|yesyesyes|$(oj "$O_KR" rows)" \
  "8e --redeploy, DEPLOY typed: PASSED — BR2 088 intact and its NOTE with the rows kept; BA1 every kept row unchanged by the deploy"
check "$(order97 "$OUT")|$(grep -cxF "      $EXP_SWON97" <<<"$OUT")" "verdict<switch-on<check-bank<clear<rollback<putback|1" "…the switch off again: its command printed once more, in order"
D97L="$(data94)"; db_keep
TRG97="$(sq "SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = 'bank_lines_no_delete'")"
sqx "DROP TRIGGER bank_lines_no_delete" >/dev/null; sqx "DELETE FROM bank_statement_lines WHERE rowid = (SELECT MAX(rowid) FROM bank_statement_lines)" >/dev/null; sqx "$TRG97" >/dev/null
O_L97="$(o97)"; LN_KR97="$(oj "$O_KR" rows | cut -d: -f4)"
check "$(oj "$O_L97" triggers)|$(oj "$O_L97" trigmissing)|$(oj "$O_L97" rows | cut -d: -f4)" "11/11|-|$(( LN_KR97 - 1 ))" \
  "8f one kept statement line deleted on the live book, its no-delete trigger dropped and put back with its own SQL text: 088 whole, one line fewer"
base_log; OUT="$(run97 --after-only)"
check "$(failline "$OUT" BA1 'migration 088 is recorded but not as it must be:' "fewer-rows-than-the-deploy-found($(oj "$O_KR" rows)→$(oj "$O_L97" rows))")$(has "$OUT" '5.18.97 (after): PASSED')" "yesno" \
  "…an --after-only over it FAILS BA1: fewer rows than the deploy's stage A found — a kept statement is never lost"
db_back
check "$(oj "$(o97)" rows)|$(oj "$(o97)" triggers)|$(data94)" "$(oj "$O_KR" rows)|11/11|$D97L" "…the book put back: every kept line, 088's eleven triggers, every table as before"

echo; echo "== 97-9. a rollback whose copy leaves 5.18.97's menu behind; a copy blind to it; deployed again =="
git -C "$REPO" show "$P97:$P/includes/navigation.php" > "$SB/nav97.left"; chmod 644 "$SB/nav97.left"
printf '%s\n' "$SB/nav97.left" > "$SB/web/.left97"
fresh97; OUT="$(run97 --answer ROLLBACK --rollback)"
check "$(live)|$( [ -e "$SB/web/.left97" ] && echo unspent || echo spent)|$(cmp -s "$PD/includes/navigation.php" "$SB/nav97.left" && echo left)|$(fails "$OUT")|$(has "$OUT" '5.18.97 (rollback): 2 FAILED')" "$P96|spent|left|2|yes" \
  "9a a rollback whose copy leaves 5.18.97's menu behind: 5.18.96 serving, the menu 5.18.97's, two checks FAIL"
check "$(failline "$OUT" RB "installed files that are not 5.18.96's after the rollback: includes/navigation.php")$(failline "$OUT" RB "the installed code still carries 5.18.97's ECO Bank, or lost 5.18.96's own P3b, after the rollback:" 'navigation.php:BankGate' 'navigation.php:link')" "yesyes" \
  "…RB FAILS by sha256 naming the file, and by ECO Bank's gate and link still in the menu"
git -C "$REPO" show "$P96:$P/includes/navigation.php" > "$PD/includes/navigation.php"
printf '%s\n' "$SB/nav97.left" > "$SB/web/.left97"
RBBK1_OLD='for f in "$BK_NAV" "$BK_CEO" public.php includes/post_handlers.php includes/routes.php; do grep -qF '"'"'BankGate'"'"' "$DEST/$f" 2>/dev/null && RB_BK="$RB_BK ${f##*/}:BankGate"; done'
RBBK2_OLD='for f in "$BK_NAV" "$BK_CEO"; do grep -qF -- "$BK_HREF" "$DEST/$f" 2>/dev/null && RB_BK="$RB_BK ${f##*/}:link"; done'
M="$(mutant rbblind97 "$DEPLOY97" "$RBBK1_OLD" 'true' "$RBBK2_OLD" 'true')"
fresh97; OUT="$(run97 --answer ROLLBACK --script "$M" --rollback)"; rm -f "$M"
check "$(live)|$( [ -e "$SB/web/.left97" ] && echo unspent || echo spent)|$(okline "$OUT" RB 'ECO Bank is gone from the code')$(failline "$OUT" RB "installed files that are not 5.18.96's after the rollback: includes/navigation.php")|$(fails "$OUT")" "$P96|spent|yesyes|1" \
  "9b a copy whose RB is blind to ECO Bank's gate and link, over the same menu left behind (5.18.96 already serving): it calls ECO Bank gone (caught) — only the sha256 still sees it"
git -C "$REPO" show "$P96:$P/includes/navigation.php" > "$PD/includes/navigation.php"
check "$(installed97 "$P96")$(stat -c '%u:%g' "$PD/includes/navigation.php")" "01000:1000" "…the menu put back as 5.18.96's: every file 5.18.96's again"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "…the quarantine's own --check: PASSED"
QREC97="$(sha256sum "$QREC" | cut -c1-16)"; KEPT97="$(kept_state)"
D97P="$(data94)"; O_P97="$(o97)"; db_keep
touch "$SB/web/.del_line97"
fresh97; OUT="$(run97 --answer DEPLOY --redeploy)"
check "$( [ -e "$SB/web/.del_line97" ] && echo unspent || echo spent)|$(live)|$(failline "$OUT" BA1 'migration 088 is recorded but not as it must be:' "kept-rows-changed($(oj "$O_P97" rows)→")$(has "$OUT" '5.18.97 (deploy): PASSED')" "spent|$P97|yesno" \
  "9c' a kept statement line deleted while a --redeploy runs, after its stage A, its no-delete trigger dropped and put back as it was (the stand-in's .del_line97): BA1 FAILS — the kept rows changed by the deploy"
fresh97; OUT="$(run97 --answer ROLLBACK --rollback)"
check "$(live)|$(has "$OUT" '5.18.97 (rollback): PASSED')" "$P96|yes" "…rolled back again: 5.18.96 serving"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "…the quarantine's own --check after that rollback: PASSED"
QREC97="$(sha256sum "$QREC" | cut -c1-16)"; KEPT97="$(kept_state)"
db_back
check "$(oj "$(o97)" rows)|$(data94)" "$(oj "$O_P97" rows)|$D97P" "…the book put back as before that --redeploy: every kept line, every table as before"
fresh97; OUT="$(run97 --answer DEPLOY --redeploy)"
check "$(fails "$OUT")$(has "$OUT" '5.18.97 (deploy): PASSED')$(live)$(installed97 "$P97")$(inst_ver)|$(oj "$(o97)" rows)" "0yes${P97}05.18.97|$(oj "$O_KR" rows)" "9c deployed again with --redeploy: PASSED, $P97 serving, every file as the pin has it, every kept row unchanged"

echo; echo "== 97-10. what the rehearsal left behind, and what it never reached =="
fresh97   # the last logs and sheets into the sweeps' folders
check "$(evo_calls)" "0" "Evolution never called — not by the deploys, their checks, the rollbacks, the switch or the code they ran on copies"
check "$(ls -a "$REPO/scripts" | grep -E '^\..+\.sh$' | paste -sd, -)" ".qpin.sh" "no weakened or re-pinned copy is left in the clone's scripts/ — the quarantine's one pinned copy aside"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )" "" "every weakened copy, release variant and planted edit found its anchor exactly once — none was skipped"
M="$(mutant anchorprobe97 "$DEPLOY97" 'NO SUCH ANCHOR IN THE SCRIPT' 'x' 2>/dev/null)"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )$(test -e "$M" && echo made || echo none)" "mutant:anchorprobe97 none" \
  "control on the control: a weakened copy whose anchor is missing is recorded as such, and no copy is made"
rm -f "$SB/anchor-missing"
check "$( [ -f "$SB/bad-runs" ] && tr '\n' ' ' < "$SB/bad-runs" )" "" "every run took the options it was given — none answered 'unknown option'"
OUT="$(run97 --after-only --rehearsal-unknown-option)"
check "$(has "$OUT" 'unknown option: --rehearsal-unknown-option')$( [ -s "$SB/bad-runs" ] && echo recorded)" "yesrecorded" "control on the control: a run given an option the script does not know is recorded as such"
rm -f "$SB/bad-runs"
check "$(ls -A "$SB/web" | grep -c '^\.add_receipt97$\|^\.left97$\|^\.add_ledger$\|^\.add_receipt$\|^\.left96$\|^\.leak_bank97$\|^\.del_line97$\|^\.flip_switch$')$(ls "$SB"/trap96* 2>/dev/null | grep -c .)" "00" "every trigger and trap the rehearsal planted was spent"
# The observers over every run of the 5.18.97 script, but those that trip one on purpose (recorded as they ran).
OBS_BAD=0; OBS_N=0
for rec in "$R97"/exec/run-*.rec; do
  t="${rec##*/run-}"; t="${t%.rec}"; OBS_N=$((OBS_N+1))
  grep -qx "$t" "$R97/expected-trips" && continue
  a="$(audit97 "$t")"; case "$a" in *" novault=0 plugin=0 "*) ;; *) OBS_BAD=$((OBS_BAD+1)) ;; esac
  [ "$(hits97 "$t")" = "0" ] || OBS_BAD=$((OBS_BAD+1))
  b="$(cat "$R97/vault/run-$t.before")"; [ "$b" = "$(cat "$R97/vault/run-$t.after")" ] || OBS_BAD=$((OBS_BAD+1))
done
check "$( [ "$OBS_N" -ge 60 ] && echo many)|$OBS_BAD|$(grep -c . "$R97/expected-trips")" "many|0|4" \
  "10 across every run of the 5.18.97 script — the deploys, the redeploys, the after-onlys, the checks, the rollbacks, the refusals and the weakened copies — the observers were quiet: no PHP process on a copy without a vault of its own, none running the tool or T1 from the plugin folder, nothing planted in /tmp, the live vault the same before and after each; the four copies that trip them on purpose aside (2k, 2l, 2m, 4e)"
check "$(vt_host)|$(grep -cxF "$W96" "$DATA/webhook_secret")" "$VT97_0|1" "…the live vault as it was before the first run, bytes and time; the planted webhook secret where it was planted"
sweep_files97() { ls -1 "$R97"/run-*.txt "$R97"/line-*.txt "$R97"/cmds/*.txt "$R97"/logs/*.log "$OUT97"/*.log "$OUT97"/state-5.18.97.env* "$OUT97"/rollback-state-* "$OUT97"/cih-rows-* 2>/dev/null; }
check "$( [ "$(sweep_files97 | grep -c .)" -ge 60 ] && echo many)|$( [ "$(ls "$R97"/sheets/cash-*.txt 2>/dev/null | grep -c .)" -ge 10 ] && echo sheets)|$(forms97; grep -c . "$SB/forms97.txt" | awk '{print ($1 >= 100 ? "forms" : "few:" $1)}')|$( [ "$(grep -c . "$SB/stmt97.amounts" 2>/dev/null)" -ge 50 ] && echo amounts)" "many|sheets|forms|amounts" \
  "the sweep has material: every run's terminal, every printed command's output and log, the state files and row files; every run's cash sheet; a hundred and more printed forms of the book's figures and of the statements' amounts"
check "$(figs97 $(sweep_files97))" "0" \
  "10a no figure of the book or of a kept statement — in any printed form — in any terminal, log, state file or row file of any run of the 5.18.97 script or of the switch (the weakened copies that print one on purpose aside): only in the cash sheets"
check "$(sweep_files97 | xargs grep -lF -f "$SCAN96" 2>/dev/null | grep -c .)|$(cat "$SEC95" | grep -v '^$' > "$SB/sec97.pat"; sweep_files97 | xargs grep -lF -f "$SB/sec97.pat" 2>/dev/null | grep -c .)|$( [ "$(grep -c . "$SB/sec97.pat")" -ge 15 ] && [ "$(grep -c . "$SCAN96")" = "2" ] && echo listed)" "0|0|listed" \
  "10b no output, log or state file holds the planted webhook secret or vault credential, a repair key, a planted Splynx or pdf value, or one of 5.18.94's Splynx values"
check "$(bankhits97 $(sweep_files97))|$(for f in "$R97"/sheets/cash-*.txt; do grep -E '^    account #|^== ECO Bank — |^  both ECO BANK cards' "$f"; done | grep -cF -f "$BANKPAT97")" "0|0" \
  "10c no output, log or state file — and no ECO Bank line of any sheet — names an account, an account number or ending, the statements' holder or counterparties, or a staff member"
printf 'planted %s, %s and %s\n' "$(grep -m1 '^2:' "$SB/forms97.txt" | cut -c3-)" "$W96" "$(sed -n 1p "$BANKPAT97")" > "$R97/zz-sweep-control.txt"
check "$(figs97 "$R97/zz-sweep-control.txt")$(grep -lF -f "$SCAN96" "$R97/zz-sweep-control.txt" | grep -c .)$(bankhits97 "$R97/zz-sweep-control.txt")" "111" "control on the sweeps: a file holding one figure form, the planted secret and an account's name is found by all three"
rm -f "$R97/zz-sweep-control.txt" "$SB/sec97.pat"
check "$(left_tmp97)$(host_code97)$(ls -d /tmp/dnb-backup-* 2>/dev/null | grep -c .)" "${TMP97}00" "no copy of the book or the code, no vault, no synthetic book and no backup copy is left in /tmp or on the host"
check "$(grep -c 'Fatal\|Parse error' "$SB/web.log")" "0" "the stand-in served every request without a PHP fatal"
check "$(checkout_state)" "$CHECKOUT0" "this checkout is as the rehearsal found it: same commit, same tracked files"

echo; echo "rehearsal: $PASS passed, $FAILN failed ($(cat "$SB/runs") runs of the deploy scripts, $(cat "$SB/qruns" 2>/dev/null || echo 0) of the quarantine, $(cat "$SB/cmds97" 2>/dev/null || echo 0) printed switch commands)"
[ "$FAILN" = "0" ]
