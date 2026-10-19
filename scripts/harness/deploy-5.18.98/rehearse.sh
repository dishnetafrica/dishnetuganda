#!/usr/bin/env bash
#
# Rehearse scripts/deploy-5.18.98.sh — the Cashbook's list physical cash only, Ecobank and every other non-cash entry on a
# read-only tab of their own, "Bank & other accounts" (SPEC-separate v2 §2.1–§2.7, §2.10, §2.11) — over 5.18.97 with ECO
# Bank switched on and a statement kept on each card, before the operator runs it.
#
# The sandbox is 5.18.97's rehearsal's, built by its own code: scripts/harness/deploy-5.18.97/rehearse.sh, read up to just
# before its section 97-8 (the cut asserted: its anchor exactly once, and the one line that names its own folder pointed
# at that folder, also exactly once) and run here as it is — which builds 5.18.96's, 5.18.95's, 5.18.94's and the
# quarantine's sandboxes the same way, from their own rehearsals. By the time this file's own sections begin, the REAL
# 5.18.97 deploy has run, been rolled back and deployed again with --redeploy, its switch is ON in both copies (run as its
# summary printed it), a synthetic Account Details statement is kept on each Ecobank card through the INSTALLED store by
# the active administrator, and its --after-only and --check-bank have PASSED: production's state on 19 Oct, when the
# operator switched ECO Bank on and kept both statements. The quarantine's own --check is run once more here, as the
# runbook orders after 5.18.97's --after-only.
#
# On it, synthetic only: an extra bank account the book names Ecobank, USD, with no statement (RH98 Ecobank USD Reserve,
# through the INSTALLED code's own addAccount()); a non-cash row posted by the stand-in through the INSTALLED code's own
# writer onto the Ecobank UGX account while a deploy runs (between the before-copy and the after-copy); a release variant
# whose library writes on a read path; and every weakened copy of the script named below. Then this release's script,
# through a pseudo-terminal, its word typed, as the operator runs it, and every command its summary prints for a later
# step — the --after-only, --check-bank, the rollback — run as printed.
#
# The observers are 5.18.97's, taught this release's folders (asserted, each with its control): every docker call
# recorded, so that each PHP process run on a copy — the copy checks' two codes (c-old, c-new) among them — can be shown
# to carry a vault of its own; this run's folders in /tmp searched at every call for the planted values and for a vault
# at a default path; the live vault read before and after every run. Switched on by the run's environment, never by the
# script.
#
# This rehearsal proves:
#   · the script is pinned to the release commit on release/5.18.98 (short and full), cut on ec0accd (5.18.97); the delta is
#     exactly the reviewed seventeen files, its tree the development branch's (cash-98, 5870933d), each file's sha256 as the
#     script pins it, computed here from Git; the fifteen 5.18.97 files it pins as kept byte for byte in both commits, and
#     every bank-module file, the menu, the CEO page, the More menu, the styles and the Cashbook's post handler (SPEC §2.9 M)
#     among the files 5.18.98 leaves alone; no migration; T1 and T4 pinned as 5.18.97 pinned them; the header's deploy
#     command alone; every later command defined with its own log wrapper; no switch command left; the live vault never
#     written, the sheet never read out; the instruments — C5's digests see a row, an account and a schema change; the
#     judge names every term — C1, C2, C3's four, C4, C5, rule A — and a copy without any one line is blind; CPJ_PHP's
#     comparison counts a changed cell short and never calls readings that hold nothing whole; N1–N4's own SQL counts
#     what this rehearsal counts on a copy that holds a row of each class N2 counts (uCRM's gap-fill among them) and an
#     account N4 counts — never 0 against 0 — and a copy with N2's literal or the gap-fill's source taken out is caught;
#     printed98 sees a line holding two commands;
#   · --check is GO on 5.18.97 as production has it: G1–G3 hold, G3's lines are the book's offered accounts by row id
#     (this rehearsal's own reading), the offered account not named Ecobank with no statement a NOTE; C1–C5 hold; N1–N4
#     the counts this rehearsal counts; its summary prints the runbook's command alone. With that account made inactive
#     — the control, holding a row, with N2's plant — no G3 note and BN5 ok, N2 and N4 counting it. Option errors;
#     --check-bank and --after-only before the deploy refused (c);
#   · the refusals before anything changes: the live install not 5.18.97 (e35ecce serving), a part-way copy, another
#     manifest, a file 5.18.98 changes not 5.18.97's, a bank-module file edited by hand — at BR1, then (BR1 taken out of a
#     copy) at G1, and a copy without both reaches the question (caught); A14 over a failure 5.18.97 and 5.18.94 recorded
#     and over 5.18.97's copy after the newest --check, each copy blind to it caught; rule A off (SSP among the book's
#     currencies) refused at A23.7 by the pin's reading, then by the live code's (G2), and a copy without both no longer
#     names the rule (caught) (1);
#   · G3 both ways: a card that shows no figure (an offered account named Ecobank with no statement) refuses — nothing
#     changed, the --without-bank-balance command printed alone with its own log — and a copy without the refusal says
#     GO over it (caught); --check --without-bank-balance is GO with G3 a NOTE in the operator's words; over cards that
#     both show a figure the flag does nothing, and the deploy command is printed without it; ECO Bank's switch cleared
#     refuses at G3 on the switch alone, and a copy without BankGate::open() says GO over it (caught) (2);
#   · C5 against a planted write: a release whose library writes on a read path is refused first at A0 by the sha256 this
#     script pins; pinned to it, the copy checks refuse at C5; a copy without C5's judgement says GO over it (caught); a
#     copy whose copy checks run without a vault is caught by the observers; a release whose countEntries() counts one
#     more is refused at C4 alone, and a copy without C4's judgement says GO over it (caught) (3);
#   · the deploy, DEPLOY typed, PASSES with a non-cash row arriving between the before-copy and the after-copy: CA3 says it
#     as a NOTE (one row added, its id on the sheet only), every other check holds; the notes are exactly the ones named;
#     the sheet's figures, ECO Bank lines, copy checks and N4 this rehearsal's own; nothing printed but counts and hashes;
#     the summary's commands each alone with its own log, the rollback last, after the verdict, its put-back apart (4);
#   · --after-only as printed PASSES (R19 conclusive, CA3 the row again, V3j ON and unchanged); a copy whose CA3 FAILs on
#     drift is caught; CA4 FAILs over the Cashbook's tab answering a visitor with no session, and a copy blind to it is
#     caught; a write planted in the INSTALLED library FAILs CA2 at C5 (and R1, CA1 by bytes), and a copy without C5's
#     judgement passes CA2 over it (caught); a card with no statement added after the deploy FAILs BA2's G3 again, and a
#     copy turning that FAIL into an ok is caught; CA4 FAILs over the tab's export answering with a file, naming it alone,
#     and a copy that never asks for it is caught; CA1 FAILs over a kept file edited and a page that no longer parses; V3j
#     FAILs the switch cleared while a run goes on, BA2 saying it as the switch changed; --check refused with 5.18.98
#     live; --check-bank PASSES, its lines this rehearsal's own (5);
#   · the rollback as printed, ROLLBACK typed: PASSED — 5.18.97's twelve files by sha256, the split gone, ECO Bank, its
#     switch, 088 and every kept row unchanged, both cards still a figure, cash in hand on a copy; a --redeploy before the
#     quarantine's --check refused at A14 by the rollback's own record (6);
#   · --redeploy after the rollback: refused at G3 over a card with no statement (nothing changed); with
#     --without-bank-balance PASSED, G3 and BA2 notes in the operator's words, --check-bank printed in its own block; its
#     --after-only and that --check-bank as printed PASS once the card is gone (7);
#   · a rollback whose copy leaves 5.18.98's routes.php behind FAILS RB twice; put back; --redeploy PASSES clean, CA3 ok —
#     the control for CA3's note — typed with --without-bank-balance over cards that both show a figure, which is
#     recorded as nothing, so that its --after-only FAILs BA2 over a card added since without a statement (8);
#   · nothing reaches Evolution; every observer quiet but on the one copy that trips it on purpose; no figure, secret, or
#     ECO Bank name, number or ending in any output, log or state file (each sweep with its control); /tmp, the host and
#     the checkout as found (10).
#
# Everything runs in a sandbox; it never touches this checkout, the server or the network, and refuses to run where the
# server could be. Everything seeded is fictitious — every amount invented here, every name RH98, RH97, RH96 or RH-.
#
#   bash scripts/harness/deploy-5.18.98/rehearse.sh            REHEARSE_KEEP=<dir> keeps every run's full output
set -u
for f in /data/ucrm /opt/dishnet /var/run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
H98_DIR="$(cd "$(dirname "$0")" && pwd)"
H98_ROOT="$(cd "$H98_DIR/../../.." && pwd)"
F98=scripts/deploy-5.18.98.sh
# Before anything is built: the script must be pinned — to its release commit, short and full, and to T1's count.
P98_EARLY="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT="\([^"]*\)".*/\1/p' "$H98_ROOT/$F98" 2>/dev/null)"
P98F_EARLY="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT_FULL="\([^"]*\)".*/\1/p' "$H98_ROOT/$F98" 2>/dev/null)"
T1_EARLY98="$(sed -n 's/^T1_SIGNATURE="\([^"]*\)".*/\1/p' "$H98_ROOT/$F98" 2>/dev/null)"
case "$P98_EARLY" in __*|'') echo "refusing: $F98 is not pinned to a release commit (${P98_EARLY:-none})"; exit 2;; esac
case "$P98F_EARLY" in "$P98_EARLY"*) [ "${#P98F_EARLY}" = "40" ] || { echo "refusing: $F98 does not pin its release commit in full"; exit 2; } ;; *) echo "refusing: $F98's full pin does not begin with its short one"; exit 2;; esac
case "$T1_EARLY98" in [1-9]*' passed, 0 failed rc=0') ;; *) echo "refusing: $F98 does not pin T1's count (T1_SIGNATURE=${T1_EARLY98:-none})"; exit 2;; esac
for h in rh98-oracle.php rh98-account.php rh98-rows.php; do
  [ -f "$H98_DIR/$h" ] || { echo "refusing: this rehearsal's helper $h is not beside it"; exit 2; }
done
H97_HARNESS="$H98_DIR/../deploy-5.18.97/rehearse.sh"
H98_PREFIX="$(mktemp)"
python3 - "$H97_HARNESS" "$H98_PREFIX" <<'PY' || { echo "refusing: 5.18.97's rehearsal could not be cut after its section 97-7 (both statements kept, --check-bank passed)"; rm -f "$H98_PREFIX"; exit 2; }
import sys
src, dst = sys.argv[1], sys.argv[2]
anchor = 'echo; echo "== 97-8. the rollback\'s two steps, as printed: the switch cleared, then ROLLBACK; refusals; a trigger dropped; --redeploy =="'
lines = open(src, encoding='utf-8').read().split('\n')
at = [i for i, l in enumerate(lines) if l == anchor]
assert len(at) == 1, len(at)
assert at[0] > 1100, at[0]                  # 5.18.97's deploy, rollback, --redeploy, switch and statements come before it
pre = '\n'.join(lines[:at[0]]) + '\n'
# 5.18.97's rehearsal finds its own helpers beside itself ($0's folder): sourced from here, $0 is this file, so its one
# line naming that folder points at 5.18.97's own folder instead — the one edit, asserted.
old_dir = 'H97_DIR="$(cd "$(dirname "$0")" && pwd)"\n'
new_dir = 'H97_DIR="$(cd "$(dirname "$0")/../deploy-5.18.97" && pwd)"   # 5.18.98\'s rehearsal: 5.18.97\'s own folder, where its helpers are\n'
assert pre.count(old_dir) == 1, pre.count(old_dir)
pre = pre.replace(old_dir, new_dir, 1)
assert pre.count(new_dir) == 1 and pre.count(old_dir) == 0
for need in ('H96_HARNESS="$H97_DIR/../deploy-5.18.96/rehearse.sh"', 'run97() {', 'run_line97() {', 'fresh97() {', 'nogo97() {',
             'trip97() {', 'audit97() {', 'hits97() {', 'vault97() {', 'OUT97="$SB/out97"', 'STATE97="$OUT97/state-5.18.97.env"',
             'R97="$SB/r97"', 'H97="$SB/rh97"', 'o97() {', 'oracle97() {', 'cash97() {', 'BANKPAT97="$SB/bank97.pat"',
             'copy_back97() {', 'BK97=bank_screen_enabled', 'STAFF97="$(cd "$H97/v"', 'ADM97="$(python3', 'KEEP97="$(cd "$H97/v"',
             'O_K="$(o97)"', 'OUT97A3="$OUT"', 'python3 - "$H96/rh96.py" "$H97/rh97.py" <<\'PY\'',
             'echo; echo "== 97-3. the deploy, as the operator runs it — DEPLOY typed; a receipt posted between A23 and R23 =="',
             'echo; echo "== 97-6. the rollback, run as printed — the switch left on (step 1 skipped); refusals; --check --redeploy; --redeploy =="',
             'echo; echo "== 97-7. statements kept on both cards (the installed store, the administrator); --after-only; --check-bank =="'):
    assert pre.count(need) >= 1, need
last = [l for l in pre.split('\n') if l.strip()][-1]
assert last.startswith('  "…the terminal carries no row id and no kept line\'s date'), last[:80]   # the cut follows 97-7's last check
open(dst, 'w', encoding='utf-8').write(pre)
PY
# shellcheck disable=SC1090
. "$H98_PREFIX"
rm -f "$H98_PREFIX"

# ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
# 5.18.98's own rehearsal, on the sandbox built above: 5.18.97 (ec0accd) live, ECO Bank switched on in both copies, a
# statement kept on each card, its --after-only and --check-bank passed.
# ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
REL98=release/5.18.98; DEPLOY98="$REPO/$F98"
FIX98=5870933d                                       # the change on the development branch (cash-98's tip: its five commits)
DEV98_BASE=ec0accd4                                  # …and that branch's base, the 5.18.97 release commit
ADDED98="includes/cashbook_noncash_csv.php lib/CashbookNonCashView.php tabs/accounts/cashbook_noncash.php tests/test_cashbook_physical_list.php tests/test_cashbook_physical_list_mutants.php"
CHANGED98="SAFETY.md includes/routes.php lib/CashbookService.php manifest.json tabs/accounts/cashbook.php tests/test_bank_boundaries.php tests/test_cash_in_hand_p3b_screens.php tests/test_distributor_apply.php tests/test_distributor_link_ucrm.php tests/test_distributor_notify.php tests/test_distributor_registry.php tests/test_distributor_territory.php"
PINS98="tests/test_distributor_apply.php tests/test_distributor_link_ucrm.php tests/test_distributor_notify.php tests/test_distributor_registry.php tests/test_distributor_territory.php"
N98=$(wc -w <<<"$ADDED98 $CHANGED98")               # 17
# SPEC §2.9 M: what 5.18.98 must leave byte for byte — every bank-module file, the two links, the More menu, the styles,
# the Cashbook's post handler — and (§2.7, §2.9) the readers that pass no key and the tests that must stay green unedited.
KEPT_BANK98="lib/BankGate.php lib/BankStatementStore.php lib/EcobankDetailsCsv.php tabs/accounts/bank.php includes/post/post_bank.php migrations/088_bank_statements.sql tests/fixtures/bank_statement_gen.php tests/test_bank_reader.php tests/test_bank_screen.php tests/test_bank_store.php includes/navigation.php tabs/admin/ceo_dashboard.php tabs/sales/more_menu.php includes/styles.php includes/post/post_cashbook.php"
KEPT_READERS98="cron/cashbook_summary.php cron_maintenance.php lib/DailyReportService.php includes/api/api_cashbook.php tabs/accounts/ssp_cashbook.php tabs/accounts/accounts_dashboard.php tools/cash_in_hand.php tools/cashbook_assign_accounts.php public.php includes/post_handlers.php tools/set_config.php tests/test_cash_in_hand_p3b.php tests/test_cash_in_hand_p3b_south_sudan.php tests/test_cash_in_hand_p3b_mutants.php tests/test_d1_bank_balance_readers.php tests/test_cashbook_currency.php main.php cron/master.php lib/PluginConfig.php lib/ConfigVault.php lib/TenantProfile.php lib/MigrationRunner.php lib/SqliteStore.php MANIFEST.md"
if git -C "$R" ls-files --error-unmatch "$F98" >/dev/null 2>&1; then
  cmp -s "$R/$F98" "$DEPLOY98" || { echo "refusing: $F98 differs from the committed one — commit it, then rehearse"; exit 2; }
  WHICH98="the committed script"
else cp "$R/$F98" "$DEPLOY98"; WHICH98="the working copy (not yet committed; untracked in the clone)"; fi
git -C "$REPO" fetch -q origin "refs/heads/$REL98:refs/remotes/origin/$REL98" 2>/dev/null || true
git -C "$REPO" fetch -q origin "refs/heads/cash-98:refs/remotes/origin/cash-98" 2>/dev/null || true
P98="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT="\([^"]*\)".*/\1/p' "$DEPLOY98")"
P98F="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT_FULL="\([^"]*\)".*/\1/p' "$DEPLOY98")"
T1SIG98="$(sed -n 's/^T1_SIGNATURE="\([^"]*\)".*/\1/p' "$DEPLOY98")"
T4SIG98="$(sed -n 's/^T4_SIGNATURE="\([^"]*\)".*/\1/p' "$DEPLOY98")"
MIG88_SHA98="$(sed -n 's/^MIG88_SHA256="\([0-9a-f]*\)".*/\1/p' "$DEPLOY98")"; MIG88_MD598="$(sed -n 's/^MIG88_MD5="\([0-9a-f]*\)".*/\1/p' "$DEPLOY98")"
OUT98="$SB/out98"; mkdir -p "$OUT98"; STATE98="$OUT98/state-5.18.98.env"
R98="$SB/r98"; mkdir -p "$R98/logs" "$R98/mutants" "$R98/sheets" "$R98/exec" "$R98/scan" "$R98/vault"   # the sweeps' material (10)
: > "$R98/expected-trips"                            # the tags of the runs that trip an observer on purpose (weakened copies)
H98="$SB/rh98"; mkdir -p "$H98/v"; cp "$H98_DIR"/rh98-oracle.php "$H98_DIR"/rh98-account.php "$H98_DIR"/rh98-rows.php "$H98/"
chmod 644 "$H98"/rh98-*.php; chown 1000:1000 "$H98/v"; chmod 700 "$H98/v"   # the helpers' own vaults, the store's user's
DATE_TS98='$(date -u +%Y%m%dT%H%M%SZ)'
LOGW98() { printf '2>&1 | ( umask 077; exec tee %s/%s-%s.log )' "$OUT98" "$1" "$DATE_TS98"; }   # the script's LOGW, as it prints it
EXP_AFTER98="cd $REPO && bash scripts/deploy-5.18.98.sh --after-only $(LOGW98 after)"
EXP_ROLLBACK98="cd $REPO && bash scripts/deploy-5.18.98.sh --rollback $(LOGW98 rollback)"
EXP_DEPLOY98="cd $REPO && bash scripts/deploy-5.18.98.sh $(LOGW98 deploy)"
EXP_REDEPLOY98="cd $REPO && bash scripts/deploy-5.18.98.sh --redeploy $(LOGW98 redeploy)"
EXP_CHECKBANK98="cd $REPO && bash scripts/deploy-5.18.98.sh --check-bank $(LOGW98 check-bank)"
EXP_PUTBACK98="( cd $REPO && git checkout $P97 && { bash scripts/deploy-hybrid.sh; git checkout -; } ) $(LOGW98 putback)"
EXP_RUNBOOK98="cd $REPO && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.98 && mkdir -p $OUT98 && bash scripts/deploy-5.18.98.sh $(LOGW98 deploy)"
EXP_RUNBOOK98W="cd $REPO && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.98 && mkdir -p $OUT98 && bash scripts/deploy-5.18.98.sh --without-bank-balance $(LOGW98 deploy)"
EXP_WBB98="cd $REPO && bash scripts/deploy-5.18.98.sh --without-bank-balance $(LOGW98 deploy)"            # G3's own refusal prints it
EXP_WBBRD98="cd $REPO && bash scripts/deploy-5.18.98.sh --without-bank-balance --redeploy $(LOGW98 deploy)"   # …and with --redeploy
BANKU98="$(python3 -c 'import json, sys; print(json.loads(sys.argv[1])["bankU"])' "$BOOKIDS")"
BANKD98="$(python3 -c 'import json, sys; print(json.loads(sys.argv[1])["bankD"])' "$BOOKIDS")"
BANK298="$(python3 -c 'import json, sys; print(json.loads(sys.argv[1])["bank2"])' "$BOOKIDS")"
ACCNAME98="RH98 Ecobank USD Reserve"                 # the plant for G3: an offered account the book names Ecobank, no statement
ACCNAME98N="RH98 Inactive USD Account"               # the plant for N4: a bank account ECO Bank does not offer, holding a row
NC_DESC98="RH98 non-cash row posted while the deploy ran"
# The plant for N2 (the fourth review, RH-1): one approved row on no account of each class N2 counts — uCRM's online rows
# from its webhook and from the gap-fill (cron_crm_payment_gap.php's crm_gap_fill), a Bank Transfer in, a Bank Transfer
# out and a Bank Charges row — added through the INSTALLED code's own addEntryRaw() (rh98-rows.php). Amounts invented.
N2ROWS98='[{"project":"dishnet","date":"2026-09-01","direction":"in","amount":314159.26,"currency":"UGX","category":"Receipt","category_raw":"Receipt","description":"RH98 N2 a uCRM webhook payment, online","status":"approved","approved_by":"RH98 Approver","source":"crm_webhook","validation_status":"online","account_id":0},
 {"project":"dishnet","date":"2026-09-01","direction":"in","amount":161803.39,"currency":"UGX","category":"Receipt","category_raw":"Receipt","description":"RH98 N2 a uCRM payment filled in by the gap-fill, online","status":"approved","approved_by":"RH98 Approver","source":"crm_gap_fill","validation_status":"online","account_id":0},
 {"project":"dishnet","date":"2026-09-01","direction":"in","amount":141421.35,"currency":"UGX","category":"Bank Transfer","category_raw":"Bank Transfer","description":"RH98 N2 a Bank Transfer in","status":"approved","approved_by":"RH98 Approver","source":"manual","account_id":0},
 {"project":"dishnet","date":"2026-09-01","direction":"out","amount":173205.08,"currency":"UGX","category":"Bank Transfer","category_raw":"Bank Transfer","description":"RH98 N2 a Bank Transfer out","status":"approved","approved_by":"RH98 Approver","source":"manual","account_id":0},
 {"project":"dishnet","date":"2026-09-01","direction":"out","amount":223606.79,"currency":"UGX","category":"Bank Charges","category_raw":"Bank Charges","description":"RH98 N2 a Bank Charges row","status":"approved","approved_by":"RH98 Approver","source":"manual","account_id":0}]'
acct_row98() {   # acct_row98 ACCOUNT-ID CUR AMOUNT DESCRIPTION — one listed row on that account, as rh98-rows.php takes it (JSON)
  printf '[{"project":"dishnet","date":"2026-09-01","direction":"in","amount":%s,"currency":"%s","category":"Receipt","category_raw":"Receipt","description":"%s","status":"approved","approved_by":"RH98 Approver","source":"manual","account_id":%s}]' "$3" "$2" "$4" "$1"
}

left_tmp98() { ls -d /tmp/dnb-5.18.98-* /tmp/dn-master-lock-* /tmp/dn_autopull_* 2>/dev/null | wc -l | tr -d ' '; }
TMP98="$(left_tmp98)"
host_code98() { ls -d "$OUT98"/code-* "$OUT98"/p3b-* "$OUT98"/br1-* "$OUT98"/domainb-* 2>/dev/null | wc -l | tr -d ' '; }
backups98() { ls -d "$OUT98"/backup-* 2>/dev/null | wc -l | tr -d ' '; }
states98() { ls -1 "$OUT98"/state-5.18.98.env* "$OUT98"/rollback-state-* 2>/dev/null | sort | while IFS= read -r f; do printf '%s %s\n' "${f##*/}" "$(sha256sum "$f" | cut -c1-16)"; done | sha256sum | cut -c1-16; }
fresh98() {   # the run folder as a new run finds it: the state files and the row digests kept; every log a printed command
              # wrote and every cash sheet moved aside for the sweeps (10)
  find "$OUT98" -mindepth 1 -maxdepth 1 -name '*.log' -exec mv -t "$R98/logs/" {} + 2>/dev/null
  find "$OUT98" -mindepth 1 -maxdepth 1 -name 'cash-*.txt' -exec mv -t "$R98/sheets/" {} + 2>/dev/null
  find "$OUT98" -mindepth 1 -maxdepth 1 ! -name 'state-5.18.98.env*' ! -name 'rollback-state-*' ! -name 'cih-rows-*' -exec rm -rf {} +; base_log; }
installed98() {   # installed98 COMMIT — the number of files 5.18.97 → 5.18.98 touches that are NOT installed as COMMIT has them
  local f rel bad=0
  for f in $(dl AM "$P97" "$P98"); do
    rel="${f#$P/}"
    if git -C "$REPO" cat-file -e "$1:$f" 2>/dev/null; then
      [ "$(git -C "$REPO" show "$1:$f" | sha256sum | cut -c1-64)" = "$(sha256sum "$PD/$rel" 2>/dev/null | cut -c1-64)" ] || bad=$((bad+1))
    fi
  done
  echo "$bad"
}
last98() { printf '%03d' "$(cat "$SB/runs")"; }   # the tag of the last run
run98() {   # [--answer WORD] [--script FILE] [--env K=V]… [--today YYYYMMDD], THEN the script's options — as run97 (its own
            # options first: a script option before them is passed to the script, which answers "unknown option"), this
            # rehearsal's folders and observers. R98_MUTANT_OUT=1 keeps a run's output out of the figure sweep
  local answer="" script="$DEPLOY98" extra=() today="$AFTER94" n tag o cmd
  while :; do case "${1:-}" in --answer) answer="$2"; shift 2 ;; --script) script="$2"; shift 2 ;; --env) extra+=("$2"); shift 2 ;;
    --today) today="$2"; shift 2 ;; *) break ;; esac; done
  : > "$SB/docker.log"
  n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"; tag="$(printf '%03d' "$n")"
  vt_host > "$R98/vault/run-$tag.before"; : > "$R98/exec/run-$tag.rec"; : > "$R98/scan/run-$tag.hits"
  cmd="$(printf '%q ' env PATH="$SB/datebin:$SB/bin:$PATH" FAKE_TODAY="$today" DNB_OUT="$OUT98" DNB_RECORDS="$RECS" DNB_WEBSITE="$WEB_OK" GUARD_SECONDS=3 DN_DATA_DIR="$DATA" \
         FAKE_EXEC_LOG="$R98/exec/run-$tag.rec" FAKE_TMP_SCAN="$SCAN96" FAKE_TMP_HITS="$R98/scan/run-$tag.hits" ${extra[@]+"${extra[@]}"} bash "$script" --plugin-base "$PLUGIN_BASE" "$@")"
  o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r')"
  vt_host > "$R98/vault/run-$tag.after"
  case "$o" in *"unknown option:"*) echo "run98-$n" >> "$SB/bad-runs" ;; esac
  if [ -n "${R98_MUTANT_OUT:-}" ]; then printf '%s\n' "$o" > "$R98/mutants/run-$tag.txt"; else printf '%s\n' "$o" > "$R98/run-$tag.txt"; fi
  [ -n "${REHEARSE_KEEP:-}" ] && printf '%s\n' "$o" > "$REHEARSE_KEEP/run98-$tag-${answer:-none}-$(basename "$script" .sh)$(printf '%s' "$*" | tr -c 'A-Za-z0-9-' '_').txt"
  printf '%s' "$o"
}
run_line98() {   # run_line98 WORD LINE — a command line exactly as a summary printed it, through a pseudo-terminal, WORD typed
  local answer="$1" line="$2" n tag o cmd
  : > "$SB/docker.log"
  n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"; tag="$(printf '%03d' "$n")"
  vt_host > "$R98/vault/run-$tag.before"; : > "$R98/exec/run-$tag.rec"; : > "$R98/scan/run-$tag.hits"
  cmd="$(printf '%q ' env PATH="$SB/datebin:$SB/bin:$PATH" FAKE_TODAY="$AFTER94" DNB_OUT="$OUT98" DNB_RECORDS="$RECS" DNB_WEBSITE="$WEB_OK" GUARD_SECONDS=3 DN_DATA_DIR="$DATA" PLUGIN_BASE="$PLUGIN_BASE" \
         FAKE_EXEC_LOG="$R98/exec/run-$tag.rec" FAKE_TMP_SCAN="$SCAN96" FAKE_TMP_HITS="$R98/scan/run-$tag.hits" bash -c "$line")"
  o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r')"
  vt_host > "$R98/vault/run-$tag.after"
  case "$o" in *"unknown option:"*) echo "line98-$n" >> "$SB/bad-runs" ;; esac
  printf '%s\n' "$o" > "$R98/line-$tag.txt"
  [ -n "${REHEARSE_KEEP:-}" ] && printf '%s\n' "$o" > "$REHEARSE_KEEP/line98-$tag-${answer:-none}.txt"
  printf '%s' "$o"
}
went98() { echo "$(has "$1" 'GO — evidence recorded')$(has "$1" 'Type DEPLOY')"; }   # the run reached the question
quiet98() { echo "$(live)|$(backups98)|$(data94)|$(kept_state)|$(oldname)"; }      # nothing deployed, backed up or changed
nogo98() {   # nogo98 LABEL EXPECTED-TEXT OUTPUT [DATA-DIGEST] [LIVE] — refused with that text and "Nothing was changed", never the
             # question, nothing deployed, backed up or changed
  check "$(has "$3" "$2")$(has "$3" 'Nothing was changed. Send the log file.')$(went98 "$3")|$(quiet98)" "yesyesnono|${5:-$P97}|0|${4:-$D98}|$KEPT98|0" "$1"
}
checkgo98() {   # checkgo98 OUTPUT — --check said GO: stage A held, nothing asked, nothing backed up, no state written
  echo "$(has "$1" "  5.18.98 (check): GO — stage A holds.")$(has "$1" '  --check           stage A alone, every refusal and note of a deploy')$(went98 "$1")$(fails "$1")|$(backups98)|$(states98)"
}
trip98() { printf '%s\n' "$(last98)" >> "$R98/expected-trips"; }   # the last run trips an observer on purpose (10); one tag a line
audit98() { python3 "$H98/rh98.py" audit "$R98/exec/run-$1.rec"; }  # audit98 TAG — copies=<n> novault=<n> plugin=<n> adj=<id|->
hits98() { local n; n="$(grep -c . "$R98/scan/run-$1.hits" 2>/dev/null)"; echo "${n:-0}"; }
vault98() { echo "$(cat "$R98/vault/run-$1.before")>$(cat "$R98/vault/run-$1.after")"; }
quietobs98() { echo "$(hits98 "$1")|$(audit98 "$1" | sed -E 's/^copies=[0-9]+ //; s/ adj=.*//')|$(vault98 "$1")"; }   # the observers on run TAG
printed98() {   # printed98 OUTPUT — the summary's commands: how many | how many without their own log wrapper | how many lines of
                # the whole output hold two commands — by their command forms, never by a name in words
  local s; s="$(sed -n '/^== F\. Summary/,$p' <<<"$1")"
  echo "$(grep -cE '^      (cd |\( )' <<<"$s")|$(grep -E '^      (cd |\( )' <<<"$s" | grep -vcE ' \| \( umask 077; exec tee | \| tee ')|$(grep -cE '(bash scripts/deploy-5\.18\.98\.sh|bash scripts/quarantine-stray-store\.sh).*(bash scripts/deploy-5\.18\.98\.sh|bash scripts/quarantine-stray-store\.sh)' <<<"$1")"
}
rb_where98() {   # rb_where98 OUTPUT — each line naming the rollback command: before or after the run's verdict
  awk '/^  5\.18\.98 \((deploy|after)\): / { v = 1 } index($0, "deploy-5.18.98.sh --rollback") { print (v ? "after" : "before") }' <<<"$1" | paste -sd, -
}
order98() {   # order98 OUTPUT — the verdict, --check-bank's block (with --without-bank-balance), the rollback and the put-back, in order
  RH_OUT="$1" RH_P="$P97" python3 - <<'PY'
import os, re
t = os.environ['RH_OUT'].split('\n'); p = os.environ['RH_P']
pats = [('verdict', re.compile(r'^  5\.18\.98 \((deploy|after)\): PASSED')),
        ('check-bank', re.compile(r'^      cd .*deploy-5\.18\.98\.sh --check-bank ')),
        ('rollback', re.compile(r'^      cd .*deploy-5\.18\.98\.sh --rollback ')),
        ('putback', re.compile(r'^      \( cd .* && git checkout ' + re.escape(p) + ' '))]
seen = sorted((i, n) for i, l in enumerate(t) for n, rx in pats if rx.search(l))
names = [n for _, n in seen]
print('<'.join(names) if len(set(names)) == len(names) else 'repeated:' + ','.join(names))
PY
}
hdr98() {   # hdr98 SCRIPT — the header's command block: the deploy with its own log | anything that is not it | the --after-only with its own
  local h; h="$(sed -n '/^# Run as root/,/^# The rollback is a separate command/p' "$1")"
  echo "$(grep -cF 'bash scripts/deploy-5.18.98.sh 2>&1 | ( umask 077; exec tee /root/dnb-5.18.98/deploy-$(date -u +%Y%m%dT%H%M%SZ).log )' <<<"$h")$(grep -cE -- '--rollback|--put-back|--redeploy|--check-bank|--without-bank-balance|set_config|git checkout [0-9a-f]{7}' <<<"$h")$(grep -cF 'bash scripts/deploy-5.18.98.sh --after-only 2>&1 | ( umask 077; exec tee /root/dnb-5.18.98/after-$(date -u +%Y%m%dT%H%M%SZ).log )' <<<"$h")"
}
# The eight commands the script prints for a later step, as it must define them — each with its own log wrapper; the
# quarantine's --check with its own tee. Read as whole lines from a quoted block, never expanded here. ECO Bank's two switch
# commands of 5.18.97 are gone: no definition of either may remain.
DEFS98="$SB/defs98.txt"
cat > "$DEFS98" <<'DEFS'
AFTER_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --after-only $(LOGW after)"
ROLLBACK_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --rollback $(LOGW rollback)"
DEPLOY_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh $(LOGW deploy)"
PUTBACK_CMD="( cd $REPO && git checkout $BASELINE_COMMIT && { bash scripts/deploy-hybrid.sh; git checkout -; } ) $(LOGW putback)"
QCHECK_CMD="( umask 077 && cd $REPO && bash scripts/quarantine-stray-store.sh --check 2>&1 | tee $Q_DIR/check-\$(date -u +%Y%m%dT%H%M%SZ).log )"   # the quarantine's own form (root docs/68)
REDEPLOY_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --redeploy $(LOGW redeploy)"
CHECKBANK_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --check-bank $(LOGW check-bank)"
RUNBOOK_CMD="cd $REPO && git pull origin $BRANCH && git fetch origin $RELEASE_BRANCH && mkdir -p $OUT && bash scripts/deploy-$EXPECTED_VERSION.sh $(LOGW deploy)"
DEFS
cmds98() {   # cmds98 SCRIPT — each of the eight definitions once (1 each) | the lines that print a command (printf, echo) | how many
             # of them name one of the eight | how many carry the wrapper themselves | the switch commands' definitions left
  local f="$1" l d=""
  while IFS= read -r l; do d="$d$(grep -cxF -- "$l" "$f")"; done < "$DEFS98"
  printf '%s|%s|%s|%s|%s|%s\n' "$d" "$(grep -cF "printf '\n      %s'" "$f")" "$(grep -cF 'echo "      $' "$f")" \
    "$(grep -cE "(printf '\\\\n      %s' \"\\\$|echo \"      \\\$)(AFTER_CMD|ROLLBACK_CMD|DEPLOY_CMD|QCHECK_CMD|PUTBACK_CMD|REDEPLOY_CMD|CHECKBANK_CMD|RUNBOOK_CMD)\"" "$f")" \
    "$(grep -F "printf '\n      %s'" "$f" | grep -cF '$(LOGW ')" "$(grep -cE 'SWITCH_ON_CMD|SWITCH_CLEAR_CMD' "$f")"
}
noteids98() { grep -E '^  note  ' <<<"$1" | awk '{print $2}' | LC_ALL=C sort -u | paste -sd' ' -; }
ctl98() {   # ctl98 OUTPUT A22|R23 — 5.18.97's ctl97 in this release's words: the script says "kept by $EXPECTED_VERSION",
            # so A22 and R23 read "kept by 5.18.98" here (ctl97 counts 5.18.97's words, and its own sections need it)
  local out="$1" n=0 x
  for x in a b c d e f g; do
    case "$2" in
      A22) grep -qxF -- "$(printf '  %-15s the pin: %s   ·   %s (%s, the control): %s' "fix A22$x" ok "$P94" 5.18.94 "${CTL95[$x]}")" <<<"$out" \
             && [ "$(okline "$out" "A22$x" "— in the pin, kept by 5.18.98; 5.18.94's copy, the control, fails the same check (${CTL95[$x]})")" = yes ] && n=$((n+1)) ;;
      R23) [ "$(okline "$out" "R23$x" 'installed, kept by 5.18.98:' "— 5.18.94's copy, the control, fails the same check (${CTL95[$x]})")" = yes ] && n=$((n+1)) ;;
    esac
  done
  echo "$n"
}
copy_back98() {   # the 5.18.98 copy N minutes ago: lib/CashbookService.php's time (its stamp_copy, COPY_MARK), the recorded copy
                  # time, the start before it
  touch -d "-$1 minutes" "$PD/lib/CashbookService.php"; local t; t="$(stat -c %Y "$PD/lib/CashbookService.php")"
  sed -i -e "s/^COPY_AT=.*/COPY_AT=$t/" -e "s/^DEPLOYED_AT=.*/DEPLOYED_AT=$(date -u -d "@$(( t - 60 ))" +%Y-%m-%dT%H:%M:%SZ)/" "$STATE98"; }
release_variant98() {   # release_variant98 PATH OLD NEW — the release commit with one file edited (OLD exactly once), cut on 5.18.97
  local idx="$SB/variant98.idx" blob tree c
  GIT_INDEX_FILE="$idx" git -C "$REPO" read-tree "$P98"
  blob="$(git -C "$REPO" show "$P98:$P/$1" | python3 -c 'import sys
s = sys.stdin.read(); old, new = sys.argv[1], sys.argv[2]
assert s.count(old) == 1, "variant anchor not unique"
sys.stdout.write(s.replace(old, new))' "$2" "$3" | git -C "$REPO" hash-object -w --stdin)"
  [ "$blob" = "$(printf '' | git -C "$REPO" hash-object --stdin)" ] && echo "release_variant98:$1" >> "$SB/anchor-missing"
  GIT_INDEX_FILE="$idx" git -C "$REPO" update-index --cacheinfo "100644,$blob,$P/$1"
  tree="$(GIT_INDEX_FILE="$idx" git -C "$REPO" write-tree)"; rm -f "$idx"
  c="$(git -C "$REPO" -c user.name=rehearsal -c user.email=rehearsal@example.test commit-tree "$tree" -p "$(git -C "$REPO" rev-parse "$P97")" -m "rehearsal variant: $1")"
  git -C "$REPO" rev-parse --short "$c"
}
pinned_copy98() {   # pinned_copy98 NAME COMMIT SRC [FILE SHA256] — a copy of SRC pinned to another commit, short AND full (5.18.98
                    # checks both); with FILE, that file's line of PIN_SHA256S re-pinned too. Each anchor exactly once, asserted
  local full; full="$(git -C "$REPO" rev-parse "$2")"
  rm -f "$REPO/scripts/.$1.sh"
  python3 - "$3" "$REPO/scripts/.$1.sh" "$2" "$full" "${4:-}" "${5:-}" <<'PY' || echo "pinned_copy98:$1" >> "$SB/anchor-missing"
import sys, re
src, dst, short, full, f, sha = sys.argv[1:7]
s = open(src).read()
for k, v in (('EXPECTED_PLUGIN_COMMIT', short), ('EXPECTED_PLUGIN_COMMIT_FULL', full)):
    rx = re.compile(r'(?m)^' + k + r'="[^"]*"')
    assert len(rx.findall(s)) == 1, k
    s = rx.sub(lambda m: k + '="' + v + '"', s, count=1)
if f:
    rx = re.compile(r'(?m)^[0-9a-f]{64}  ' + re.escape(f) + r'$')
    assert len(rx.findall(s)) == 1, f
    assert len(sha) == 64, sha
    s = rx.sub(lambda m: sha + '  ' + f, s, count=1)
open(dst, 'w').write(s)
PY
  echo "$REPO/scripts/.$1.sh"
}
data98x() { data_dump | grep -v '^master_schedule:' | grep -v '^cb_ledger:' | grep -v '^bank_' | sha256sum | cut -c1-16; }   # every table but the book, master's record and 088's
book98x() {   # the book's rows, every column, but the stand-in's non-cash row — the book as the deploy found it
  php -r '$p = new PDO("sqlite:" . $argv[1]); echo substr(hash("sha256", json_encode($p->query("SELECT * FROM cb_ledger WHERE description <> " . $p->quote($argv[2]) . " ORDER BY id")->fetchAll(PDO::FETCH_NUM))), 0, 16);' "$DATA/plugin.sqlite3" "$NC_DESC98"; }
oracle98() { php "$H98/rh98-oracle.php" "$DATA/plugin.sqlite3"; }   # the split as this rehearsal reads the live store, read-only (JSON)
o98() { local f="$H98/o98-$(date +%s%N).json"; oracle98 > "$f"; printf '%s' "$f"; }   # a reading kept in a file of its own
cash98() { local f="$H98/cash-$1.json"; oracle96 > "$f"; printf '%s' "$f"; }   # the cash cells as 5.18.96's rehearsal reads them
kv98() { printf '%s' "$1" | sed -nE "s/^(.* )?$2=([^ ]*).*\$/\2/p" | head -1; }   # the script's kv, for this rehearsal's own lines
sub98() { printf '%s' "$1" | tr ',' '\n' | sed -n "s/^$2://p" | head -1; }        # one field of a "key:value,…" list
# the stand-in's non-cash row, N2's five planted rows and N4's two (RH-1); nothing else this rehearsal seeds is a figure
printf '%s\n' 271828.18 314159.26 161803.39 141421.35 173205.08 223606.79 57721.56 69314.72 > "$SB/seed98.amounts"
forms98() { python3 "$H98/rh98.py" forms "$SB/forms98.txt" "$SB/seed96.amounts" "$SB/seed97.amounts" "$SB/stmt97.amounts" "$SB/seed98.amounts" "$H97"/cash-*.json "$H98"/cash-*.json "$R97"/sheets/cash-*.txt "$R98"/sheets/cash-*.txt "$OUT97"/cash-*.txt "$OUT98"/cash-*.txt >/dev/null 2>&1; }
figs98() { forms98; python3 "$H98/rh98.py" hits "$SB/forms98.txt" "$@"; }   # figs98 FILE… — how many hold a figure, in any printed form
BANKPAT98="$SB/bank98.pat"   # what the script must never print of ECO Bank: 5.18.97's list, and this rehearsal's account
{ cat "$BANKPAT97"; printf '%s\n' "$ACCNAME98" "$ACCNAME98N"; } > "$BANKPAT98"
bankhits98() { local n=0 f; for f in "$@"; do [ -f "$f" ] && grep -qF -f "$BANKPAT98" -- "$f" && n=$((n+1)); done; echo "$n"; }
noids98() {   # noids98 OUTPUT — lines holding a row id ("#<digits>") that are neither G3's nor --check-bank's own per-account lines
  grep -E '#[0-9]+' <<<"$1" | grep -vcE '^  G3              account #[0-9]+ [A-Z]{3} ecobank-named=(yes|no) statement-kept=(yes|no)$|^  account [0-9]+ of [0-9]+  account #[0-9]+ [A-Z]{3}  named Ecobank: '
}
acct_add98() {   # acct_add98 NAME CUR — an account added through the INSTALLED code's own addAccount(), as the store's user; its id
  local o; o="$(cd "$H98/v" && setpriv --reuid 1000 --regid 1000 --clear-groups -- env DN_VAULT_FILE="$H98/v/acct-vault.json" "$PHPBIN" "$H98/rh98-account.php" "$PD" "$DATA" "$1" "$2" 2>"$H98/v/acct.err")"
  rm -f "$H98/v/acct-vault.json"
  python3 -c 'import json, sys; print(json.loads(sys.argv[1])["id"])' "$o" 2>/dev/null || echo "none($(head -c 120 "$H98/v/acct.err"))"
}
rows_add98() {   # rows_add98 JSON — listed rows added through the INSTALLED code's own addEntryRaw(), as the store's user, with a
                 # vault of its own; {"rows":N}, or none(<why>)
  local o; o="$(cd "$H98/v" && setpriv --reuid 1000 --regid 1000 --clear-groups -- env DN_VAULT_FILE="$H98/v/rows-vault.json" "$PHPBIN" "$H98/rh98-rows.php" "$PD" "$DATA" "$1" 2>"$H98/v/rows.err")"
  rm -f "$H98/v/rows-vault.json"
  case "$o" in '{"rows":'*) printf '%s' "$o" ;; *) echo "none($(head -c 120 "$H98/v/rows.err"))" ;; esac
}
n2ok98() {   # n2ok98 ORACLE-FILE — 1112 when the oracle's N2 counts every class: Bank Transfer in, out, Bank Charges, uCRM two or more
  local k o=""; for k in btin btout charges; do [ "$(sub98 "$(oj "$1" n2)" "$k")" -ge 1 ] 2>/dev/null && o="${o}1" || o="${o}0"; done
  [ "$(sub98 "$(oj "$1" n2)" crm)" -ge 2 ] 2>/dev/null && o="${o}2" || o="${o}0"; echo "$o"
}
g3say98() { python3 "$H98/rh98.py" g3term "$1"; }   # G3's lines on a run's terminal file: id:CUR/named/kept
n1say98() { printf '%s' "$1" | sed 's/,/; /g'; }   # N1's counts in the note's form

# ── This rehearsal's readers: 5.18.97's, derived by asserted replacements — its audit taught this release's folders and the
# copy checks' two code copies (c-old, c-new) — and readers of G3's lines, --check-bank's, the copy checks' and N4's sheet
# blocks added. ───────────────────────────────────────────────────────────────────────────────────────────────────────────
python3 - "$H97/rh97.py" "$H98/rh98.py" <<'PY' || { echo "readers:rh98" >> "$SB/anchor-missing"; echo "refusing: this rehearsal's readers could not be derived from 5.18.97's (rh97.py) — every reader would print nothing, and every check reading one would compare nothing with nothing"; exit 2; }
import sys
s = open(sys.argv[1]).read()
reps = [
    ("# 5.18.97's rehearsal (the previous release's readers, by asserted replacements): readers of what the runs leave",
     "# 5.18.98's rehearsal (the previous release's readers, by asserted replacements): readers of what the runs leave"),
    ("root = re.compile(r'^/tmp/dnb-5\\.18\\.97-(?:cih-[^/]+/[^/]+/(?:t|d|p0root|k-(?:found|off|on))/dishnet-hybrid-sudan|code-[^/]+/p3b/[^/]+/dishnet-hybrid-sudan)(?:/|$)')",
     "root = re.compile(r'^/tmp/dnb-5\\.18\\.98-(?:cih-[^/]+/[^/]+/(?:t|d|p0root|k-(?:found|off|on)|c-(?:old|new))/dishnet-hybrid-sudan|code-[^/]+/p3b/[^/]+/dishnet-hybrid-sudan)(?:/|$)')"),
    ("if not env.get('DN_VAULT_FILE', '').startswith('/tmp/dnb-5.18.97-'): novault += 1",
     "if not env.get('DN_VAULT_FILE', '').startswith('/tmp/dnb-5.18.98-'): novault += 1"),
]
for old, new in reps:
    assert s.count(old) == 1, old[:70]
    s = s.replace(old, new)
assert s.count('5.18.97') == 0 and s.count('5\\.18\\.97') == 0, 'a 5.18.97 path is left'
s += '''elif cmd == 'g3term':                  # G3's lines on a terminal, in order: id:CUR/named/kept
    rx = re.compile(r'^  G3              account #([0-9]+) ([A-Z]{3}) ecobank-named=(yes|no) statement-kept=(yes|no)$')
    out = ['%s:%s/%s/%s' % m.groups() for m in (rx.match(l) for l in read(sys.argv[2]).split('\\n')) if m]
    print(';'.join(out) if out else '-')
elif cmd == 'g3exp':                     # the same from 5.18.97's oracle: each offered account's id, currency, named, kept
    o = json.loads(read(sys.argv[2]))
    ids = [x for x in o['ids'].split(',') if x]; lst = [x for x in o['list'].split(';') if x]
    out = ['%s:%s' % (i, '/'.join(l.split('/')[:3])) for i, l in zip(ids, lst)]
    print(';'.join(out) if out else '-')
elif cmd == 'g3nn':                      # how many offered accounts are neither named Ecobank nor hold a statement: G3's note
    o = json.loads(read(sys.argv[2]))
    print(sum(1 for l in o['list'].split(';') if l and l.split('/')[1:3] == ['no', 'no']))
elif cmd == 'cbterm':                    # --check-bank's lines on a terminal, in order: id:CUR/named/kept/linked/age-in-days
    rx = re.compile(r'^  account ([0-9]+) of ([0-9]+)  account #([0-9]+) ([A-Z]{3})  named Ecobank: (yes|no)  a statement kept: (yes|no)  linked: (yes|no)  last kept line: (none|([0-9]+) day\\(s\\) old)$')
    out = []
    for l in read(sys.argv[2]).split('\\n'):
        m = rx.match(l)
        if m: out.append('%s:%s/%s/%s/%s/%s' % (m.group(3), m.group(4), m.group(5), m.group(6), m.group(7), m.group(9) or '-'))
    print(';'.join(out) if out else '-')
elif cmd == 'cbexp':                     # the same from the oracle: each last kept line's age in days, today in UTC
    import datetime
    o = json.loads(read(sys.argv[2]))
    ids = [x for x in o['ids'].split(',') if x]; lst = [x for x in o['list'].split(';') if x]
    last = dict(x.split(':', 1) for x in o['last'].split(',') if x)
    today = datetime.datetime.now(datetime.timezone.utc).date()
    out = ['%s:%s/%s' % (i, l, (today - datetime.date.fromisoformat(last[i])).days if i in last else '-') for i, l in zip(ids, lst)]
    print(';'.join(out) if out else '-')
elif cmd == 'cpsheet':                   # each copy-checks block of a sheet, in order: "all projects All" listed/cash/noncash
    out = []; inb = False
    for l in read(sys.argv[2]).split('\\n'):
        if l.startswith('== '): inb = l.startswith('== the copy checks C1'); continue
        m = re.match(r'^    all projects\\s+All\\s+([0-9]+)\\s+([0-9]+)\\s+([0-9]+)\\s*$', l) if inb else None
        if m: out.append('%s/%s/%s' % m.groups())
    print(';'.join(out) if out else '-')
elif cmd == 'n4sheet':                   # N4's accounts in a sheet's notes block, by row id
    out = []; inb = False
    for l in read(sys.argv[2]).split('\\n'):
        if l.startswith('== '): inb = l.startswith('== N1'); continue
        m = re.match(r'^    account #([0-9]+)  (active|inactive)  ', l) if inb else None
        if m: out.append(m.group(1))
    print(','.join(out))
elif cmd == 'nblocks':                   # how many notes blocks a sheet holds
    print(sum(1 for l in read(sys.argv[2]).split('\\n') if l.startswith('== N1')))
'''
open(sys.argv[2], 'w').write(s)
PY
check "$(python3 -c 'import ast, sys; ast.parse(open(sys.argv[1]).read()); print("parses")' "$H98/rh98.py" 2>&1)$(for f in "$H98"/rh98-*.php; do php -l "$f" 2>&1 | grep -c '^No syntax errors'; done | tr -d '\n')" "parses111" \
  "control: this rehearsal's readers (5.18.97's, derived) and its three PHP helpers parse"

echo; echo "== 98-pre. the sandbox as production is: 5.18.97 live, ECO Bank on, both statements kept; the observers taught this release's folders =="
O97_0="$(o97)"
check "$(live)|$(inst_ver)|$(both_copies "$BK97")|$(oj "$O97_0" offered)|$(oj "$O97_0" both)|$(oj "$O97_0" ledger)|$(oj "$O97_0" tables)" \
      "$P97|5.18.97|11|UGX:2:1:1,USD:1:1:1|yes|applied:$MIG88_MD598|5/5" \
  "pre-a 5.18.97 ($P97) is live, ECO Bank's switch on in both copies, a statement kept on each Ecobank card (UGX and USD), 088 applied — production's state since 19 Oct, 03:40 UTC; a second UGX account, not named Ecobank, offered with no statement"
[ -e "$RECS/dnb-5.18.97" ] || ln -s "$OUT97" "$RECS/dnb-5.18.97"
check "$(readlink -f "$RECS/dnb-5.18.97")|$(readlink -f "$RECS/dnb-5.18.96")|$(readlink -f "$RECS/dnb-5.18.95")|$(readlink -f "$RECS/dnb-5.18.94")" "$(readlink -f "$OUT97")|$(readlink -f "$OUT96")|$(readlink -f "$OUT95")|$(readlink -f "$OUT94")" \
  "pre-b 5.18.97's, 5.18.96's, 5.18.95's and 5.18.94's records where 5.18.98's A14 looks for them (<records>/dnb-5.18.9x), as on the server"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "pre-c the quarantine's own --check, as the runbook orders after 5.18.97's --after-only: PASSED with 5.18.97 live"
QREC98="$(sha256sum "$QREC" | cut -c1-16)"; KEPT98="$(kept_state)"
# ── The observers search this release's folders in /tmp too: its cash folders, its synthetic books, its code copies and
# T1's and T4's run folders (the hook's one list, extended, asserted). ─────────────────────────────────────────────────
python3 - "$SB/bin/docker" <<'PY' || echo "fakedocker:observers98" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]; s = open(p).read()
old = ' /tmp/dnb-5.18.97-code-*/t4run; do\n'
assert s.count(old) == 1, s.count(old)
s = s.replace(old, ' /tmp/dnb-5.18.97-code-*/t4run /tmp/dnb-5.18.98-cih-* /tmp/dnb-5.18.98-ss-* /tmp/dnb-5.18.98-code-*/p3b /tmp/dnb-5.18.98-code-*/t1run /tmp/dnb-5.18.98-code-*/t4run; do\n', 1)
assert s.count('/tmp/dnb-5.18.98-') == 5 and s.count(old) == 0
open(p, 'w').write(s)
PY
mkdir -p /tmp/dnb-5.18.98-cih-rhcontrol; printf 'rh98-observer-control\n' > /tmp/dnb-5.18.98-cih-rhcontrol/webhook_secret
printf 'rh98-observer-control\n' > "$SB/obs98.pat"
env PATH="$SB/bin:$PATH" FAKE_EXEC_LOG="$SB/obs98.rec" FAKE_TMP_SCAN="$SB/obs98.pat" FAKE_TMP_HITS="$SB/obs98.hits" bash "$SB/bin/docker" exec -u 1000:1000 -e DN_DATA_DIR=/tmp/dnb-5.18.98-cih-x/a23-1/c-data ucrm php -r 'echo 1;' ctor /tmp/dnb-5.18.98-cih-x/a23-1/c-new/dishnet-hybrid-sudan /tmp/dnb-5.18.98-cih-x/a23-1/c-data none new >/dev/null 2>&1
check "$(grep -c '^value /tmp/dnb-5.18.98-cih-rhcontrol/webhook_secret$' "$SB/obs98.hits")$(grep -c '^file /tmp/dnb-5.18.98-cih-rhcontrol/webhook_secret$' "$SB/obs98.hits")|$(python3 "$H98/rh98.py" audit "$SB/obs98.rec")" "11|copies=1 novault=1 plugin=0 adj=-" \
  "control: the observers see this release's folders — a planted value and a file named webhook_secret in a run's folder in /tmp, and a process of the copy checks on its code copy (c-new) with no vault of its own"
rm -rf /tmp/dnb-5.18.98-cih-rhcontrol "$SB/obs98.pat" "$SB/obs98.rec" "$SB/obs98.hits"
# …and the audit counts what runs code on this release's copies, not 5.18.97's paths: a copy-checks process on each code
# copy with a vault of its own, one on c-old without, a gate process on a k-found copy, a 5.18.97 copy, and the digest and
# notes readers, which load no plugin code (no code root among their arguments) — written as the fake docker records them.
{ for c in \
    "exec|-u|1000:1000|-e|DN_VAULT_FILE=/tmp/dnb-5.18.98-cih-x/a23-1/c-new-vault.json|-e|DN_DATA_DIR=/tmp/dnb-5.18.98-cih-x/a23-1/c-data|ucrm|php|-d|display_errors=stderr|-r|echo 1;|read|/tmp/dnb-5.18.98-cih-x/a23-1/c-new/dishnet-hybrid-sudan|/tmp/dnb-5.18.98-cih-x/a23-1/c-data|/tmp/dnb-5.18.98-cih-x/a23-1/c-new.json|new" \
    "exec|-u|1000:1000|-e|DN_DATA_DIR=/tmp/dnb-5.18.98-cih-x/a23-1/c-data|ucrm|php|-d|display_errors=stderr|-r|echo 1;|ctor|/tmp/dnb-5.18.98-cih-x/a23-1/c-old/dishnet-hybrid-sudan|/tmp/dnb-5.18.98-cih-x/a23-1/c-data|none|old" \
    "exec|-u|1000:1000|-e|DN_VAULT_FILE=/tmp/dnb-5.18.98-cih-x/a23-1/k-found-vault.json|-e|DN_DATA_DIR=/tmp/dnb-5.18.98-cih-x/a23-1/k-found-data|ucrm|php|-d|display_errors=stderr|-r|echo 1;|found|/tmp/dnb-5.18.98-cih-x/a23-1/k-found/dishnet-hybrid-sudan|/tmp/dnb-5.18.98-cih-x/a23-1/k-found-data|/tmp/dnb-5.18.98-cih-x/" \
    "exec|-u|1000:1000|-e|DN_VAULT_FILE=/tmp/dnb-5.18.97-cih-x/a23-1/c-new-vault.json|ucrm|php|-r|echo 1;|/tmp/dnb-5.18.97-cih-x/a23-1/c-new/dishnet-hybrid-sudan" \
    "exec|-u|1000:1000|-e|DN_VAULT_FILE=/tmp/dnb-5.18.98-cih-x/a23-1/c-vault-none.json|ucrm|php|-d|display_errors=stderr|-r|echo 1;|/tmp/dnb-5.18.98-cih-x/a23-1/c-data/plugin.sqlite3" \
    "exec|-u|1000:1000|-e|DN_VAULT_FILE=/tmp/dnb-5.18.98-cih-x/a23-1/notes-vault-none.json|ucrm|php|-d|display_errors=stderr|-r|echo 1;|/tmp/dnb-5.18.98-cih-x/a23-1/master/plugin.sqlite3|UGX,USD|/tmp/dnb-5.18.98-cih-x/a23-1/notes.txt"; do
    printf '%s' "$c" | tr '|' '\037'; printf '\037\036'; done; } > "$SB/obs98b.rec"
check "$(python3 "$H98/rh98.py" audit "$SB/obs98b.rec")" "copies=3 novault=1 plugin=0 adj=-" \
  "control: the audit counts the copy checks' processes on their code copies (c-new, c-old) and a gate process on ECO Bank's — one without a vault — and neither a 5.18.97 path nor the digest and notes readers, which run no plugin code"
rm -f "$SB/obs98b.rec"

# ── The stand-in public.php learns four more triggers, each spent once: a non-cash row — on the account the trigger names,
# through the INSTALLED code's own writer — posted while a run goes on (.add_noncash98), at the first request, which in a
# deploy is V's: after the before-copy (A23) and before the after-copy (R23); the Cashbook's tab "Bank & other accounts"
# answering a visitor with no session with the page (.leak_cb98, CA4); the tab's own export (cb_book=noncash) answering
# one with a file — 200, a Content-Disposition, no redirect (.leak_cbx98, CA4); and 5.18.98's routes.php left behind by a
# rollback's copy (.left98, naming the file to copy) — at the first request once 5.18.97's manifest is installed again
# (8). Asserted, as every edit here. ─────────────────────────────────────────────────────────────────────────────────────
python3 - "$SB/web/public.php" <<'PY' || echo "standin:triggers98" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]; s = open(p).read()
old = "$page = $_GET['page'] ?? '';\n"
assert s.count(old) == 1, s.count(old)
new = """if (($_GET['page'] ?? '') === 'rh98_probe') { echo 'rh98-triggers-ready'; exit; }
$nc98 = is_file($web . '/.add_noncash98') ? (int)trim((string)file_get_contents($web . '/.add_noncash98')) : 0;
if ($nc98 > 0 && $spend('.add_noncash98')) {   // 5.18.98's rehearsal: a non-cash row, through the INSTALLED code's own writer, while a run goes on (CA3)
    putenv('DN_VAULT_FILE=' . $web . '/.rh98-noncash-vault.json');   // never the live vault
    require_once $root . '/lib/CashbookService.php';
    (new CashbookService($store, $dataDir))->addEntryRaw(['project' => 'dishnet', 'date' => gmdate('Y-m-d'), 'direction' => 'in', 'amount' => 271828.18,
        'currency' => 'UGX', 'category' => 'Receipt', 'category_raw' => 'Receipt', 'description' => 'RH98 non-cash row posted while the deploy ran',
        'status' => 'approved', 'approved_by' => 'RH98 Approver', 'source' => 'manual', 'account_id' => $nc98]);
    putenv('DN_VAULT_FILE');
}
if (($_GET['page'] ?? '') === 'dashboard' && ($_GET['tab'] ?? '') === 'cashbook' && ($_GET['cb_view'] ?? '') === 'noncash' && !isset($_GET['cb_export']) && $spend('.leak_cb98')) {
    echo '<html><body><div class="cb3-hero">CASH IN HAND</div></body></html>'; exit;   // 5.18.98's rehearsal: the tab to a visitor with no session (CA4)
}
if (($_GET['page'] ?? '') === 'dashboard' && ($_GET['tab'] ?? '') === 'cashbook' && isset($_GET['cb_export']) && ($_GET['cb_book'] ?? '') === 'noncash' && $spend('.leak_cbx98')) {
    header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="rh98-noncash.csv"');   // 5.18.98's rehearsal: the tab's
    echo "id,date\\n"; exit;                                                                                       // export, a file, to a visitor with no session (CA4)
}
$left98 = is_file($web . '/.left98') ? trim((string)file_get_contents($web . '/.left98')) : '';
if ($left98 !== '' && strpos((string)@file_get_contents($root . '/manifest.json'), '"5.18.97"') !== false && $spend('.left98')) {
    copy($left98, $root . '/includes/routes.php');   // 5.18.98's rehearsal: 5.18.98's routes.php left behind by a rollback's copy (RB)
}
""" + old
s = s.replace(old, new, 1)
assert s.count("'.add_noncash98'") == 1 and s.count("'.leak_cb98'") == 1 and s.count("'.leak_cbx98'") == 1 and s.count("'.left98'") == 1 and s.count('rh98_probe') == 1 and s.count(old) == 1
open(p, 'w').write(s)
PY
for i in $(seq 1 60); do pubget page=rh98_probe | grep -q 'rh98-triggers-ready' && break; sleep 0.1; done   # PHP re-reads the edited file
check "$(pubget page=rh98_probe)$(ls -A "$SB/web" | grep -c '^\.add_noncash98$\|^\.leak_cb98$\|^\.leak_cbx98$\|^\.left98$')" "rh98-triggers-ready0" "control: the stand-in carries the four triggers, unspent and unset"
VT98_0="$(vt_host)"; D98="$(data94)"; DX98="$(data98x)"; O98_0="$(o98)"
check "$VT98_0" "$VT97_0" "pre-d the live vault as 5.18.97's rehearsal settled it (its fixed point), holding the planted webhook secret and credential"
check "$(oj "$O98_0" curs)|$( [ "$(oj "$O98_0" listed)" -gt 0 ] 2>/dev/null && echo listed)|$( [ "$(sub98 "$(oj "$O98_0" noncash)" all)" -gt 0 ] 2>/dev/null && echo moves)|$(sub98 "$(oj "$O98_0" n1)" all | awk -F/ '$1 > 0 { print "bank" }')" "UGX,USD|listed|moves|bank" \
  "pre-e the split, as this rehearsal reads the design on the book: UGX and USD; rows listed (the positive control); some leave the list (N1 is not vacuous), bank rows among them"

echo; echo "== 98-0. what is rehearsed: 5.18.98 — the controls =="
echo "  script    $WHICH98, sha256 $(sha256sum "$DEPLOY98" | cut -c1-16)"
case "$P98" in __*|'') echo "  FAIL the script still carries the placeholder pin ($P98)"; exit 2;; esac
check "$P98|$P98F" "$(git -C "$REPO" rev-parse --short "origin/$REL98" 2>/dev/null || echo none)|$(git -C "$REPO" rev-parse "origin/$REL98" 2>/dev/null || echo none)" "the script is pinned to the release commit on $REL98, short and in full ($P98)"
check "$(git -C "$REPO" rev-parse --short "$P98^" 2>/dev/null)|$(git -C "$REPO" rev-parse "$P98^" 2>/dev/null)|$(ver "$P98")$(ver "$P97")" "$P97|$(sed -n 's/^BASELINE_COMMIT_FULL="\([^"]*\)".*/\1/p' "$DEPLOY98")|5.18.985.18.97" \
  "the release commit is cut on $P97 (5.18.97, the live version, its full hash as the script pins it) and is 5.18.98"
check "$(dl D "$P97" "$P98" | grep -c .)|$(dl A "$P97" "$P98" | grep -c .)|$(dl AM "$P97" "$P98" | grep -c .)|$(git -C "$REPO" diff --no-renames --name-only --diff-filter=CRTUXB "$P97" "$P98" | grep -c .)|$(git -C "$REPO" diff --name-only "$P97" "$P98" | grep -vc "^$P/")" "0|5|$N98|0|0" \
  "5.18.97 → 5.18.98: nothing removed, copied, renamed or retyped, nothing outside the plugin; five files added, $N98 in all"
check "$(dl A "$P97" "$P98" | sort | paste -sd' ' -)|$(dl M "$P97" "$P98" | sort | paste -sd' ' -)" "$(for f in $ADDED98; do echo "$P/$f"; done | sort | paste -sd' ' -)|$(for f in $CHANGED98; do echo "$P/$f"; done | sort | paste -sd' ' -)" \
  "…the five added are the tab's class, page and export and the two tests of the split; the twelve changed the library, the Cashbook page, the routes, SAFETY.md, the manifest, two rewritten tests and five version pins"
check "$(dl AM "$P97" "$P98" | grep -c "^$P/migrations/")|$(git -C "$REPO" show "$P98:$P/migrations/088_bank_statements.sql" | sha256sum | cut -c1-64)" "0|$MIG88_SHA98" "no migration; 088 at the pin the reviewed file the script pins (sha256 ${MIG88_SHA98:0:16}…)"
check "$(git -C "$REPO" cat-file -e "$FIX98^{commit}" 2>/dev/null && echo held)|$(git -C "$REPO" rev-parse "$P98:$P")|$(git -C "$REPO" rev-list --count "$DEV98_BASE..$FIX98" -- "$P")|$(git -C "$REPO" merge-base --is-ancestor "$DEV98_BASE" "$FIX98" && echo on-ec0accd)" \
      "held|$(git -C "$REPO" rev-parse "$FIX98:$P" 2>/dev/null)|5|on-ec0accd" \
  "the release's plugin tree is the development branch's (cash-98, $FIX98) exactly — its five commits on $P97"
PS98="$(sed -n '/^PIN_SHA256S="\$(cat <<.SUMS.$/,/^SUMS$/p' "$DEPLOY98" | grep -E '^[0-9a-f]{64}  ')"
KS98="$(sed -n '/^PREV_KEPT_SHA256S="\$(cat <<.SUMS.$/,/^SUMS$/p' "$DEPLOY98" | grep -E '^[0-9a-f]{64}  ')"
PSL98=""; while read -r s f; do [ -n "$f" ] || continue; [ "$(git -C "$REPO" show "$P98:$P/$f" | sha256sum | cut -c1-64)" = "$s" ] || PSL98="$PSL98 $f"; done <<<"$PS98"
check "$(grep -c . <<<"$PS98")|$(awk '{print $2}' <<<"$PS98" | sort | paste -sd' ' -)|${PSL98:-same}|$(sed -n 's/^PIN_SHA_N=\([0-9]*\)$/\1/p' "$DEPLOY98")" "$N98|$(for f in $ADDED98 $CHANGED98; do echo "$f"; done | sort | paste -sd' ' -)|same|$N98" \
  "the script pins each of the seventeen files by the sha256 Git gives it at the pin — this rehearsal's own reading — and pins no other"
KSL98=""; while read -r s f; do [ -n "$f" ] || continue; for c in "$P97" "$P98"; do [ "$(git -C "$REPO" show "$c:$P/$f" | sha256sum | cut -c1-64)" = "$s" ] || KSL98="$KSL98 $f@$c"; done; done <<<"$KS98"
check "$(grep -c . <<<"$KS98")|${KSL98:-same}|$(sed -n 's/^PREV_KEPT_N=\([0-9]*\)$/\1/p' "$DEPLOY98")" "15|same|15" \
  "…and the fifteen 5.18.97 files it keeps — ECO Bank's module, its two links, the More menu, the styles, the Cashbook's post handler, ECO Bank's wiring and the cash-in-hand tool — by the sha256 Git gives each in both release commits"
KB98L=""; for f in $KEPT_BANK98 $KEPT_READERS98; do [ -n "$(git -C "$REPO" rev-parse -q --verify "$P97:$P/$f" 2>/dev/null)" ] && [ "$(git -C "$REPO" rev-parse "$P98:$P/$f" 2>/dev/null)" = "$(git -C "$REPO" rev-parse "$P97:$P/$f")" ] || KB98L="$KB98L $f"; done
KBN98L=""; for f in lib/BankGate.php lib/BankStatementStore.php lib/EcobankDetailsCsv.php tabs/accounts/bank.php includes/post/post_bank.php migrations/088_bank_statements.sql includes/navigation.php tabs/admin/ceo_dashboard.php tabs/sales/more_menu.php includes/styles.php includes/post/post_cashbook.php; do grep -qE "  $f\$" <<<"$KS98" || KBN98L="$KBN98L $f"; done
check "${KB98L:-same}|${KBN98L:-pinned}" "same|pinned" \
  "SPEC §2.9 M: every bank-module file, the menu, the CEO page, the More menu, the styles and the Cashbook's post handler — and §2.7's readers that pass no key, and the P3b tests that stay green unedited — 5.18.97's byte for byte; each of the first eleven among the files the script pins as kept"
check "$(git -C "$REPO" diff --name-only "$P97" "$P98" -- "$P/dishnet-mikrotik-control-plane" "$P/docs" | grep -c .)" "0" "Domain B's tree and the plugin's docs are 5.18.97's"
V98L=""; for f in manifest.json $PINS98; do python3 - <(git -C "$REPO" show "$P97:$P/$f") <(git -C "$REPO" show "$P98:$P/$f") <<'PY' || V98L="$V98L $f"
import sys
a, b = open(sys.argv[1]).read(), open(sys.argv[2]).read()
assert a.count('5.18.97') >= 1 and a.replace('5.18.97', '5.18.98') == b and a != b
PY
done
check "${V98L:-pins}" "pins" "the manifest and the five version pins are 5.18.97's with the version alone changed"
check "$T1SIG98|$T4SIG98" "$T1SIG97|$T4SIG97" "the script pins T1's and T4's counts as 5.18.97 pinned them ($T1SIG98; $T4SIG98): 5.18.98 changes T1 and T4 neither, and adds to the library only behind keys T1 never passes"
check "$(sed -n 's/^P0_TOOL_SHA="\([0-9a-f]*\)".*/\1/p' "$DEPLOY98")|$(sed -n 's/^ADJ_CENTS_SHA="\([0-9a-f]*\)".*/\1/p' "$DEPLOY98")|$(sed -n 's/^COPY_MARK="\([^"]*\)"$/\1/p' "$DEPLOY98")" \
      "$(sed -n 's/^P0_TOOL_SHA="\([0-9a-f]*\)".*/\1/p' "$DEPLOY97")|$(sed -n 's/^ADJ_CENTS_SHA="\([0-9a-f]*\)".*/\1/p' "$DEPLOY97")|lib/CashbookService.php" \
  "P0's tool and R0's adjustment hash pinned as 5.18.97 pinned them — hashes, never a figure — and the copy's time read off lib/CashbookService.php, a file 5.18.98 changes"
check "$(hdr98 "$DEPLOY98")" "101" \
  "the header's deploy command stands alone, with its own log wrapper — no rollback, put-back, redeploy, check-bank, --without-bank-balance, setting or checkout in its block (docs/44 §16.9) — and the later --after-only is its own, with its own"
HDR_DEPLOY_LINE98='#     && bash scripts/deploy-5.18.98.sh 2>&1 | ( umask 077; exec tee /root/dnb-5.18.98/deploy-$(date -u +%Y%m%dT%H%M%SZ).log )'
M="$(mutant hdrrb98 "$DEPLOY98" "$HDR_DEPLOY_LINE98" "$HDR_DEPLOY_LINE98
#   cd /opt/dishnet && bash scripts/deploy-5.18.98.sh --rollback")"
check "$(hdr98 "$M")" "111" "0-hdr' a copy whose header carries the rollback inside the deploy command's block is caught by the same count"
rm -f "$M"
check "$(cmds98 "$DEPLOY98")|$(grep -cF 'then F_RUN_CMD="$RUNBOOK_CMD"' "$DEPLOY98")$(grep -F 'else F_RUN_CMD="cd $REPO && git pull origin $BRANCH' "$DEPLOY98" | grep -cF '$(LOGW "')$(grep -cxF '    echo "      $F_RUN_CMD"' "$DEPLOY98")" "11111111|9|8|14|2|0|111" \
  "every command the script prints for a later step is defined with its own log wrapper — the --after-only, the rollback, the deploy, the put-back, the quarantine's --check with its tee, --redeploy, --check-bank and the runbook's deploy — each of the lines that print one names one of them or carries the wrapper itself, and --check's own line is the runbook's or carries it; no switch command is defined any more"
RB_DEF98="$(sed -n 8p "$DEFS98")"
M="$(mutant fnowrap98 "$DEPLOY98" "$RB_DEF98" 'RUNBOOK_CMD="cd $REPO && git pull origin $BRANCH && git fetch origin $RELEASE_BRANCH && mkdir -p $OUT && bash scripts/deploy-$EXPECTED_VERSION.sh"')"
check "$(cmds98 "$M" | cut -d'|' -f1)" "11111110" "0-cmd' a copy that defines the runbook's deploy without its log wrapper is caught by the same reading"
rm -f "$M"
# printed98's third count — the lines that hold two commands (root docs/44 §16.9) — is asserted 0 on every run below; its
# control, as 5.18.97's rehearsal carried one (RH-6): a summary line holding the --after-only and the rollback pasted
# together reads 1, and an ok line that names the script twice in words, never in a command's form, reads 0.
PR98_TWO="$(printf '%s\n' '== F. Summary ==' "      $EXP_AFTER98; $EXP_ROLLBACK98")"
PR98_WORDS="  ok    R26 deploy-5.18.98.sh's South Sudan check, as deploy-5.18.98.sh's A24 reads it — the script named twice, in words"
check "$(printed98 "$PR98_TWO")|$(printed98 "$PR98_WORDS" | cut -d'|' -f3)" "1|0|1|0" \
  "control: printed98 sees a summary line holding two commands — the --after-only and the rollback pasted together: one command line, its log wrapper on it, and one line holding two — and not an ok line that names deploy-5.18.98.sh twice in words"
check "$(grep -cE '(>|>>) *"?\$(VAULT_HOST|VAULT)"?( |$)' "$DEPLOY98")$(grep -cE '(mv|rm|touch|chmod|chown|tee) [^|;]*"\$(VAULT_HOST|VAULT)"' "$DEPLOY98")$(grep -E 'cp [^|;]*"\$VAULT"' "$DEPLOY98" | grep -vcF 'cp -p "$VAULT" "$BK/config-vault.json"')" "000" \
  "the script never writes, moves or touches the live vault — it copies it only into its own backup"
check "$(grep -cE '(cat|head|tail|more|less|awk|sed|grep)( -[^ ]+)* +"\$SHEET"' "$DEPLOY98")$(grep -cF 'echo "      less $SHEET"' "$DEPLOY98")" "03" \
  "nothing in the script reads the cash sheet out — it prints only \`less <path>\`, on its own line, in each of its three summaries"
check "$(grep -vE '^[[:space:]]*#' "$DEPLOY98" | grep -cE 'set_config\.php --key')|$(grep -vE '^[[:space:]]*#' "$DEPLOY98" | grep -cF "$BK97 --value")|$(grep -vE '^[[:space:]]*#' "$DEPLOY98" | grep -cF "$BK97 --clear")" "0|0|0" \
  "the script runs and prints ECO Bank's switch nowhere — neither set nor cleared: since 5.18.97 it is the operator's, ON"
check "$(grep -c 'DISHNET_REPAIR' "$DEPLOY98")$(grep 'DISHNET_REPAIR' "$DEPLOY98" | grep -vc 'grep -')$(grep -cFf "$SB/spx.vals" "$DEPLOY98")" "400" \
  "the script names the old built-in key only in grep patterns (four), and holds none of 5.18.94's Splynx values"
check "$(grep -vE '^[[:space:]]*#' "$DEPLOY98" | grep -cE 'Ecobank UGX|Ecobank USD|7000000001|RH-Account|RH-CLIENT|0000000001|0000000002|RH98 ')" "0" "the script's code names no account, number, holder or counterparty of the book or of the synthetic statements"
check "$(bash -n "$DEPLOY98" && echo parses)" "parses" "the script parses"
check "$(git -C "$REPO" log -1 --format=%h "$P98" -- $P)|$(git -C "$REPO" log -1 --format=%h "$P97" -- $P)" "$P98|$P97" \
  "control: the clone writes the 7-character short forms the script names"
# ── The instruments: C5's digest reader (CPD_PHP), its judge (c_judge) and N1–N4's own SQL (NOTES_PHP), taken from the
# script as it stands and run on a copy of the sandbox's book in a folder of this rehearsal's own under /tmp — never one
# the observers watch — owned by the store's user, as the harness's php() runs (5.18.97's trial 2). The live book untouched.
K98="$(mktemp -d /tmp/rh98-k.XXXXXX)"
python3 - "$DEPLOY98" "$K98" <<'PY' || echo "instrument:c5-98" >> "$SB/anchor-missing"
import sys, re
s = open(sys.argv[1]).read()
for name in ('CPD_PHP', 'NOTES_PHP', 'CP_PHP', 'CPJ_PHP'):
    m = re.findall(r'(?m)^' + name + r'="\$\(cat <<\'PHP\'\n(.*?)\nPHP\n\)"', s, re.S)
    assert len(m) == 1, name
    open(sys.argv[2] + '/' + name.lower() + '.txt', 'w').write(m[0])
out = []
for fn in ('kv', 'sec_say', 'subkv', 'c3f', 'c_full'):
    m = re.findall(r'(?m)^' + fn + r'\(\) +\{.*\}[^\n]*$', s)
    assert len(m) == 1, fn
    out.append(m[0])
m = re.findall(r'(?ms)^c_judge\(\) \{.*?^\}$', s)
assert len(m) == 1, 'c_judge'
out.append(m[0])
open(sys.argv[2] + '/cj.sh', 'w').write('\n'.join(out) + '\n')
PY
chown -R 1000:1000 "$K98"
php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec("VACUUM INTO " . $p->quote($argv[2]));' "$DATA/plugin.sqlite3" "$K98/copy.sqlite3" || echo "  diag 0-k98: the copy failed ($(ls -ld "$K98" 2>&1 | cut -c1-60))"
# N1–N4's own SQL is read on a book that holds what N2 and N4 count, never 0 against 0 (the fourth review, RH-1): the plant
# of N2 (N2ROWS98: a row of each class it counts, uCRM's webhook and gap-fill rows among them) and N4's account — USD,
# made inactive, so ECO Bank does not offer it — holding one listed row, through the installed code's own writers, in the
# live book only for as long as a copy of it takes (db_keep/db_back). The notes are read on that copy (notes.sqlite3); the
# oracle reads the planted book before it is put back.
db_keep
ACC98N="$(acct_add98 "$ACCNAME98N" USD)"
RW98N="$(rows_add98 "$N2ROWS98")"
case "$ACC98N" in
  [1-9]*) RW98NI="$(rows_add98 "$(acct_row98 "$ACC98N" USD 57721.56 'RH98 N4 a row on a bank account ECO Bank does not offer')")"
          sqx "UPDATE cb_accounts SET active = 0 WHERE id = $ACC98N" >/dev/null ;;
  *) RW98NI="none(no account)" ;;
esac
php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec("VACUUM INTO " . $p->quote($argv[2]));' "$DATA/plugin.sqlite3" "$K98/notes.sqlite3" || echo "  diag 0-k98: the notes' copy failed"
O98_K="$(o98)"
db_back
check "$(case "$ACC98N" in [1-9]*) echo added ;; *) echo "$ACC98N" ;; esac)|$RW98N|$RW98NI|$(data94)|$(vt_host)" "added|{\"rows\":5}|{\"rows\":1}|$D98|$VT98_0" \
  "0-k98 the notes' plant: an inactive USD bank account and six listed rows, through the installed code's own addAccount() and addEntryRaw(), copied — and the live book put back byte for byte, the live vault untouched"
NT98="$(php -r "$(cat "$K98/notes_php.txt")" "$K98/notes.sqlite3" "$(oj "$O98_K" curs)" "$K98/notes.txt" 2>/dev/null)"
cpd98() { php -r "$(cat "$K98/cpd_php.txt")" "$K98/copy.sqlite3" 2>/dev/null; }
cmpd98() { local w="" k; for k in rows acc master; do [ "$(kv98 "$1" "$k")" = "$(kv98 "$2" "$k")" ] || w="$w,$k"; done; w="${w#,}"; echo "${w:--}"; }
C5D0="$(cpd98)"; C5D0B="$(cpd98)"
php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec("UPDATE cb_ledger SET description = COALESCE(description, \x27\x27) || \x27.\x27 WHERE id = (SELECT MAX(id) FROM cb_ledger)");' "$K98/copy.sqlite3"; C5D1="$(cpd98)"
php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec("UPDATE cb_accounts SET updated_at = updated_at || \x27.\x27 WHERE id = (SELECT MIN(id) FROM cb_accounts)");' "$K98/copy.sqlite3"; C5D2="$(cpd98)"
php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec("CREATE TABLE rh98_planted_write (x INTEGER)");' "$K98/copy.sqlite3"; C5D3="$(cpd98)"
check "$(case "$C5D0" in "rows="*":$(sq "SELECT COUNT(*) FROM cb_ledger") acc="*" master="*) echo read ;; *) echo "other(${C5D0:0:60})" ;; esac)|$(cmpd98 "$C5D0" "$C5D0B")|$(cmpd98 "$C5D0B" "$C5D1")|$(cmpd98 "$C5D1" "$C5D2")|$(cmpd98 "$C5D2" "$C5D3")" "read|-|rows|acc|master" \
  "0-k C5's digest reader, on a copy of the book: it reads every row of cb_ledger (the positive control: its count is the book's) and gives the same digests twice; then one row's text changed moves the row digest alone, one account's the cb_accounts digest alone, one table created sqlite_master's alone — every kind of write C5 must see"
CJ_GOOD='c1=8/8 c2=12/12 c3=parts:12/12,counts:12/12,nulls:12/12,chips:3/3 c4=12/12 c5=same c5old=same rules=1/1 noncash=all:5,dishnet:3,4g:1,bluecard:1'
cj98() { bash -c '. "$1"; c_judge "$2"' _ "$2" "$1" 2>&1; }
check "$(cj98 "$CJ_GOOD" "$K98/cj.sh")|$(cj98 "${CJ_GOOD/c5=same/c5=differs}" "$K98/cj.sh")|$(cj98 "${CJ_GOOD/parts:12\/12/parts:11/12}" "$K98/cj.sh")|$(cj98 "${CJ_GOOD/rules=1\/1/rules=1/0}" "$K98/cj.sh")" \
      "ok|C5:a-digest-moved-under-the-new-code's-reads|C3:parts(11/12)|rule-A-not-in-force-for-both-codes(1/0)" \
  "0-k' C5's judge, as the script defines it: a line where everything holds is ok; a digest moved names C5, a split that is not whole names C3, a code without rule A names it"
python3 - "$K98/cj.sh" "$K98/cjw.sh" <<'PY' || echo "instrument:cj-weak98" >> "$SB/anchor-missing"
import sys
s = open(sys.argv[1]).read()
old = '  [ "$(kv "$l" c5)" = "same" ] || w="$w C5:a-digest-moved-under-the-new-code\'s-reads"\n'
assert s.count(old) == 1, s.count(old)
open(sys.argv[2], 'w').write(s.replace(old, ''))
PY
check "$(cj98 "${CJ_GOOD/c5=same/c5=differs}" "$K98/cjw.sh")" "ok" "0-k'' control on the control: the judge without its C5 line calls the moved digest ok — what the weakened copies of 3 and 5 are, and must be seen to be"
# …and every other term of the judge (the fourth review, RH-2): C1, C2, C4 and C3's counts, nulls and chips, each short of
# full, named in its own words; each line taken out of a copy of the judge, that copy calls the same short count ok.
check "$(cj98 "${CJ_GOOD/c1=8\/8/c1=7/8}" "$K98/cj.sh")|$(cj98 "${CJ_GOOD/c2=12\/12/c2=11/12}" "$K98/cj.sh")|$(cj98 "${CJ_GOOD/counts:12\/12/counts:11/12}" "$K98/cj.sh")|$(cj98 "${CJ_GOOD/nulls:12\/12/nulls:11/12}" "$K98/cj.sh")|$(cj98 "${CJ_GOOD/chips:3\/3/chips:2/3}" "$K98/cj.sh")|$(cj98 "${CJ_GOOD/c4=12\/12/c4=11/12}" "$K98/cj.sh")" \
      "C1:cash-in-hand(7/8)|C2:the-list-with-no-key(11/12)|C3:counts(11/12)|C3:nulls(11/12)|C3:chips(2/3)|C4:counts-pending-pl(11/12)" \
  "0-k' …and every other term: cash in hand (C1), the list with no key (C2), C3's counts, nulls and chips, and the counts, pending and P&L (C4), each short of full, named in the judge's own words"
python3 - "$K98/cj.sh" "$K98" <<'PY' || echo "instrument:cj-weak98b" >> "$SB/anchor-missing"
import sys
s = open(sys.argv[1]).read(); d = sys.argv[2]
loop = 'for x in parts counts nulls chips; do'
cuts = (('cjw-c1', '  c_full "$(kv "$l" c1)" || w="$w C1:cash-in-hand($(kv "$l" c1))"\n', ''),
        ('cjw-c2', '  c_full "$(kv "$l" c2)" || w="$w C2:the-list-with-no-key($(kv "$l" c2))"\n', ''),
        ('cjw-c4', '  c_full "$(kv "$l" c4)" || w="$w C4:counts-pending-pl($(kv "$l" c4))"\n', ''),
        ('cjw-counts', loop, 'for x in parts nulls chips; do'),
        ('cjw-nulls', loop, 'for x in parts counts chips; do'),
        ('cjw-chips', loop, 'for x in parts counts nulls; do'))
for name, old, new in cuts:
    assert s.count(old) == 1, (name, s.count(old))
    t = s.replace(old, new, 1)
    assert t != s and t.count(old) == 0 and (new == '' or t.count(new) == 1), name
    open(d + '/' + name + '.sh', 'w').write(t)
PY
check "$(cj98 "${CJ_GOOD/c1=8\/8/c1=7/8}" "$K98/cjw-c1.sh")|$(cj98 "${CJ_GOOD/c2=12\/12/c2=11/12}" "$K98/cjw-c2.sh")|$(cj98 "${CJ_GOOD/counts:12\/12/counts:11/12}" "$K98/cjw-counts.sh")|$(cj98 "${CJ_GOOD/nulls:12\/12/nulls:11/12}" "$K98/cjw-nulls.sh")|$(cj98 "${CJ_GOOD/chips:3\/3/chips:2/3}" "$K98/cjw-chips.sh")|$(cj98 "${CJ_GOOD/c4=12\/12/c4=11/12}" "$K98/cjw-c4.sh")" \
      "ok|ok|ok|ok|ok|ok" \
  "0-k'' controls on the control: a judge without its C1 line, its C2 line, its C4 line, or with counts, nulls or chips dropped from C3's terms, calls that same short count ok — each of those lines is what stands between a defect and GO (caught)"
# CPJ_PHP — the copy checks' own comparison — on synthetic readings in this rehearsal's folder: two the same; one cell of C1,
# of C2 and of C4 changed; and two that hold nothing, as a CP_PHP that compared nothing would leave. Counts only, no book.
printf '%s' '{"role":"old","rule":1,"c1":{"UGX|":"a","UGX|dishnet":"b"},"c2":{"|":[1,"x"],"dishnet|":[2,"y"]},"c4":{"count|":"p","pl|":"q"}}' > "$K98/cpj-o.json"
printf '%s' '{"role":"new","rule":1,"c1":{"UGX|":"a","UGX|dishnet":"b"},"c2":{"|":[1,"x"],"dishnet|":[2,"y"]},"c4":{"count|":"p","pl|":"q"},"c3":{"views":2,"parts":2,"counts":2,"nulls":2,"chips":3,"rows":{"|":[3,2,1]},"chipd":{}}}' > "$K98/cpj-same.json"
printf '%s' '{"role":"new","rule":1,"c1":{"UGX|":"a","UGX|dishnet":"B"},"c2":{"|":[1,"x"],"dishnet|":[2,"Y"]},"c4":{"count|":"P","pl|":"q"},"c3":{"views":2,"parts":2,"counts":2,"nulls":2,"chips":3,"rows":{"|":[3,2,1]},"chipd":{}}}' > "$K98/cpj-diff.json"
printf '%s' '{"role":"old","rule":1}' > "$K98/cpj-oe.json"; printf '%s' '{"role":"new","rule":1}' > "$K98/cpj-ne.json"
CPJD98="rows=0123456789abcdef:1 acc=0123456789abcdef:1 master=0123456789abcdef:1"
cpj98() { php -r "$(cat "$K98/cpj_php.txt")" "$K98/$1" "$K98/$2" "$K98/cpj-sheet.txt" rh98 "$CPJD98" "$CPJD98" "$CPJD98" 2>/dev/null; }
CPJS98="$(cpj98 cpj-o.json cpj-same.json)"; CPJX98="$(cpj98 cpj-o.json cpj-diff.json)"; CPJE98="$(cpj98 cpj-oe.json cpj-ne.json)"
check "$(kv98 "$CPJS98" c1)/$(kv98 "$CPJS98" c2)/$(kv98 "$CPJS98" c4)|$(cj98 "$CPJS98" "$K98/cj.sh")|$(kv98 "$CPJX98" c1)/$(kv98 "$CPJX98" c2)/$(kv98 "$CPJX98" c4)|$(cj98 "$CPJX98" "$K98/cj.sh")|$(cj98 "$CPJE98" "$K98/cj.sh")" \
      "2/2/2/2/2/2|ok|1/2/1/2/1/2|C1:cash-in-hand(1/2) C2:the-list-with-no-key(1/2) C4:counts-pending-pl(1/2)|C1:cash-in-hand(0/0) C2:the-list-with-no-key(0/0) C3:parts(0/0) C3:counts(0/0) C3:nulls(0/0) C3:chips(0/3) C4:counts-pending-pl(0/0)" \
  "0-k'c the copy checks' comparison (CPJ_PHP), as the script carries it, then its judge: two readings the same, every term full — ok; one cell of C1, of C2 and of C4 changed, each counted short and named; two readings that hold nothing — what a CP_PHP that compared nothing would leave — never ok: every term 0/0, named"
check "$(n2ok98 "$O98_K")|$( [ "$(sub98 "$(oj "$O98_K" n4)" accounts)" -ge 1 ] 2>/dev/null && echo n4)|$(case ",$(oj "$O98_K" n4ids)," in *",$ACC98N,"*) echo listed ;; *) echo "not-listed($(oj "$O98_K" n4ids))" ;; esac)|$(python3 "$H98/rh98.py" n4sheet "$K98/notes.txt" | tr ',' '\n' | grep -c .)" \
      "1112|n4|listed|$(oj "$O98_K" n4ids | tr ',' '\n' | grep -c .)" \
  "0-k''' control: the reading the notes are set against is not vacuous — this rehearsal's own reading counts every class of N2 on the planted copy (uCRM's two, its webhook's and its gap-fill's), N4 the inactive USD account by its row id; and the notes' sheet names as many accounts as that reading does"
check "$(kv98 "$NT98" n1)|$(kv98 "$NT98" n2)|$(kv98 "$NT98" n3)|$(kv98 "$NT98" n4)|$(kv98 "$NT98" acc)|$(stat -c %a "$K98/notes.txt" 2>/dev/null)|$(python3 "$H98/rh98.py" n4sheet "$K98/notes.txt")|$(bankhits98 "$K98/notes.txt")" \
      "$(oj "$O98_K" n1)|$(oj "$O98_K" n2)|$(oj "$O98_K" n3)|$(oj "$O98_K" n4)|yes|600|$(oj "$O98_K" n4ids)|0" \
  "0-k''' N1–N4's own SQL, as the script carries it, on a copy of the book that holds what N2 and N4 count: every count this rehearsal counts from the design, row by row in PHP — N1 per project and kind, N2, N3, N4 — and its sheet lines 0600, N4's accounts by row id, no name"
python3 - "$K98/notes_php.txt" "$K98" <<'PY' || echo "instrument:notes-weak98" >> "$SB/anchor-missing"
import sys
s = open(sys.argv[1]).read(); d = sys.argv[2]
for name, old, new in (('notes_w1', "category = 'Bank Charges'", "category = 'Bank Charge'"),
                       ('notes_w2', "source IN ('crm_sync', 'crm_api_sync', 'crm_webhook', 'crm_gap_fill')", "source IN ('crm_sync', 'crm_api_sync', 'crm_webhook')")):
    assert s.count(old) == 1, (name, s.count(old))
    t = s.replace(old, new, 1)
    assert t != s and t.count(old) == 0 and t.count(new) == 1, name
    open(d + '/' + name + '.txt', 'w').write(t)
PY
NTW198="$(php -r "$(cat "$K98/notes_w1.txt")" "$K98/notes.sqlite3" "$(oj "$O98_K" curs)" "$K98/notes-w1.txt" 2>/dev/null)"
NTW298="$(php -r "$(cat "$K98/notes_w2.txt")" "$K98/notes.sqlite3" "$(oj "$O98_K" curs)" "$K98/notes-w2.txt" 2>/dev/null)"
CRMK98="$(sub98 "$(oj "$O98_K" n2)" crm)"; CRMW98="$(sub98 "$(kv98 "$NTW298" n2)" crm)"
check "$( [ "$(kv98 "$NTW198" n2)" = "$(oj "$O98_K" n2)" ] && echo blind || echo caught)|$(sub98 "$(kv98 "$NTW198" n2)" charges)|$( [ "$(kv98 "$NTW298" n2)" = "$(oj "$O98_K" n2)" ] && echo blind || echo caught)|$(( ${CRMK98:-0} - ${CRMW98:-0} ))" "caught|0|caught|1" \
  "0-k''' controls on the control: N2's SQL with its Bank Charges literal misspelt counts none and no longer agrees — the comparison above FAILS on it (caught); without the gap-fill's source (crm_gap_fill) it counts one uCRM row fewer — the row SPEC §2.10 N2 counts and the script's first copy left out (caught)"
check "$(php -r "$(cat "$K98/cp_php.txt")" ctor "/var/x/c-new/$P" /var/x/c-data none new 2>/dev/null)|$(php -r "$(cat "$K98/cpj_php.txt")" /var/x/o.json /var/x/n.json /var/x/sheet.txt x d0 d1 d2 2>/dev/null)|$(php -r "$(cat "$K98/notes_php.txt")" "$K98/copy.sqlite3" UGX /var/x/n2.txt 2>/dev/null)|$( [ -e /var/x ] && echo made || echo none)" \
      "?notacopy|?notacopy|?notacopy|none" \
  "0-k'''' the copy checks' code, their judge and the notes' SQL each refuse to start on a path outside the container's /tmp — a code root, the judge's sheet, the notes' sheet (on a book under /tmp) — before they read or write anything"
rm -rf "$K98"
check "$(data94)|$(ls -d /tmp/rh98-k.* 2>/dev/null | grep -c .)" "$D98|0" "…the live book untouched by all of this; the instrument's folder gone"

echo; echo "== 98-c. --check: stage A alone — GO on 5.18.97 as production has it; the non-Ecobank account's NOTE and its control; the option rules =="
fresh98; S0="$(states98)"; RN98="$(sq "SELECT COUNT(*) FROM cb_ledger")"; OC="$(o97)"; NC="$(o98)"
OUT="$(run98 --check)"; T="$(last98)"; SHC="$(sheet_of "$OUT")"
check "$(checkgo98 "$OUT")" "yesyesnono0|0|$S0" "c1 --check is GO: stage A held — nothing asked, nothing backed up, no state file written"
check "$(okline "$OUT" G1 "installed is 5.18.97 ($P97): its manifest says 5.18.97, and all 15 files of ECO Bank's module, includes/navigation.php, tabs/admin/ceo_dashboard.php, the More menu, the styles, the Cashbook's post handler, ECO Bank's wiring and the cash-in-hand tool carry the sha256 pinned for 5.18.97")$(okline "$OUT" G2 'rule A is on, read through the INSTALLED code on a copy' 'says true (rule=1)')$(okline "$OUT" G3 'both ECO BANK cards show a figure, through the live 5.18.97 classes on the copy' "($(oj "$OC" bn5))")" "yesyesyes" \
  "…G1 (every pinned 5.18.97 file installed by its sha256), G2 (rule A through the installed code), G3 (both cards a figure, in this rehearsal's own counts)"
check "$(g3say98 "$R98/run-$T.txt")|$(noteline "$OUT" G3 "$(python3 "$H98/rh98.py" g3nn "$OC") offered bank account(s) not named Ecobank hold no statement")" "$(python3 "$H98/rh98.py" g3exp "$OC")|yes" \
  "…G3's lines are the book's offered accounts, by row id, currency, named and kept — this rehearsal's own reading — and the offered account not named Ecobank with no statement is a NOTE, never a refusal"
check "$(okline "$OUT" BR2 '088 is recorded on the copy and intact' "its tables hold what ECO Bank has kept ($(oj "$OC" rows): profiles:checks:statements:lines:events)")$(okline "$OUT" BR3 "ECO Bank's switch, $BK97, is ON in the configuration files" '(bk=on/on; files/store)')$(noteline "$OUT" BN5 "the offered bank accounts are not one per currency named Ecobank — $(oj "$OC" bn5) (counts only; G3 lists them by row id)")" "yesyesyes" \
  "…BR2 088 intact with the kept statements, BR3 the switch ON in both copies, BN5's note in this rehearsal's own counts"
check "$(grep -cxF "  the cards       through the live 5.18.97 classes: switch=on halves=yes open=yes admins=1 shown=1 nonadmins=3 stale=0 ready=yes offered=$(oj "$OC" offered) both=yes" <<<"$OUT")$(grep -cxF "  the gate        the pin's, as found: switch=on halves=yes open=yes admins=1 shown=1 nonadmins=3 stale=0 ready=yes offered=$(oj "$OC" offered) both=yes" <<<"$OUT")$(grep -cxF '                  off:      switch=absent halves=yes open=no admins=1 shown=0 nonadmins=3 stale=0' <<<"$OUT")$(grep -cxF '                  on:       switch=on halves=yes open=yes admins=1 shown=1 nonadmins=3 stale=0' <<<"$OUT")$(okline "$OUT" BK2 'off — no one of the 1 active administrator(s) is shown ECO Bank; on (written into the copy' 'none of the 3 active non-administrator(s)')" "1111yes" \
  "…the cards through the live classes and the pin's read alike; the gate's three states, each in its own process: the stale session refused by the fresh record"
check "$(okline "$OUT" C1 'the same in all 8/8 currency × project cells')$(okline "$OUT" C2 'every row' '12/12 lists')$(okline "$OUT" C3 '(12/12 and 12/12)' '(12/12)' '(3/3 projects)')$(okline "$OUT" C4 'the same under both codes, 12/12 cells')$(okline "$OUT" C5 "unchanged by every read of 5.18.98's code (rows $RN98, digest ")" "yesyesyesyesyes" \
  "…C1–C5 on one copy, each in its full count: cash in hand, the list with no key, the split whole and countFiltered agreeing, the counts, and the three digests unchanged by the new code's reads — over every row of the book"
check "$(noteline "$OUT" N1 "per project — on bank accounts / on accounts of another kind / naming no account / a negative or text id: $(n1say98 "$(oj "$NC" n1)") (the same totals as C3's")$(noteline "$OUT" N2 "uCRM's online $(sub98 "$(oj "$NC" n2)" crm), Bank Transfer in $(sub98 "$(oj "$NC" n2)" btin), Bank Transfer out (cash really banked) $(sub98 "$(oj "$NC" n2)" btout), Bank Charges $(sub98 "$(oj "$NC" n2)" charges)")$(noteline "$OUT" N3 "rows with no currency on the list: $(sub98 "$(oj "$NC" n3)" blank), $(sub98 "$(oj "$NC" n3)" cash) of them on the physical-cash side")$(noteline "$OUT" N4 "that hold rows: $(sub98 "$(oj "$NC" n4)" accounts), with $(sub98 "$(oj "$NC" n4)" rows) row(s)")" "yesyesyesyes" \
  "…N1–N4 are notes, and their counts are this rehearsal's own, read from the design row by row"
check "$(noteids98 "$OUT")" "A17 A23.4 A23.5 A23.6 BN5 G3 N1 N2 N3 N4" "…its notes are exactly 5.18.97's stage-A notes on this book, BN5, G3's and N1–N4"
check "$(grep -cxF "      $EXP_RUNBOOK98" <<<"$OUT")$(grep -cxF "      $EXP_DEPLOY98" <<<"$OUT")$(grep -c 'deploy-5.18.98.sh --rollback' <<<"$OUT")$(grep -c 'without-bank-balance' <<<"$OUT")|$(printed98 "$OUT")|$(grep -cxF "      less $SHC" <<<"$OUT")|$(stat -c %a "$SHC")|$(has "$OUT" "  names             G1–G3 are SPEC-separate-v2 §2.10's refusals R1–R3, and CA1–CA4 its after-checks A1–A4")" "1000|1|0|0|1|600|yes" \
  "…its summary prints the runbook's deploy alone, with its own log — the branch pulled, the release fetched, the folder made — never the plain one or a rollback; \`less\` for its own sheet, 0600; and which of the design's checks each G and CA is"
check "$(python3 "$H98/rh98.py" ecosheet "$SHC")|$(python3 "$H98/rh98.py" cpsheet "$SHC")|$(python3 "$H98/rh98.py" nblocks "$SHC"):$(python3 "$H98/rh98.py" n4sheet "$SHC")" \
      "$(python3 "$H98/rh98.py" ecoexp "$OC" 2)|$(oj "$NC" listed)/$(( $(oj "$NC" listed) - $(sub98 "$(oj "$NC" noncash)" all) ))/$(sub98 "$(oj "$NC" noncash)" all)|1:$(oj "$NC" n4ids)" \
  "…the sheet: ECO Bank's offered accounts twice (the live classes, then the pin's), by row id — this rehearsal's own reading; the copy checks' rows listed / cash / not cash for all projects, this rehearsal's own count; N4's accounts by row id"
check "$(noids98 "$OUT")|$(figs98 "$R98/run-$T.txt")|$(bankhits98 "$R98/run-$T.txt" "$SHC")|$(quietobs98 "$T")" "0|0|0|0|novault=0 plugin=0|$VT98_0>$VT98_0" \
  "…no row id in the terminal but G3's own lines, no figure, no account name or number (nor a name on the sheet); the observers quiet — every process on a copy, the copy checks' two codes among them, with a vault of its own — the live vault untouched"
# The control for G3's note: the second UGX account switched off (inactive), so every offered account is named Ecobank —
# and, so that a run's N2 and N4 notes count something rather than 0 against 0 (the fourth review, RH-1), a listed row on
# that account before it is switched off (N4's: an account ECO Bank does not offer, holding rows) and N2's plant, a row of
# each class it counts, through the installed code's own writer.
db_keep
RW98C2="$(rows_add98 "$N2ROWS98")|$(rows_add98 "$(acct_row98 "$BANK298" UGX 69314.72 'RH98 N4 a row on the second UGX account')")"
sqx "UPDATE cb_accounts SET active = 0 WHERE id = $BANK298" >/dev/null
OC2="$(o97)"; NC2="$(o98)"; D98C2="$(data94)"
fresh98; OUT="$(run98 --check)"; T="$(last98)"; SHC2="$(sheet_of "$OUT")"
check "$(oj "$OC2" offered)|$(checkgo98 "$OUT" | cut -d'|' -f1)|$(noteline "$OUT" G3 'not named Ecobank hold no statement')$(okline "$OUT" BN5 "the book offers one bank account per currency, each named Ecobank — $(oj "$OC2" bn5): ECO BANK UGX/USD each have their account")|$(g3say98 "$R98/run-$T.txt")" \
      "UGX:1:1:1,USD:1:1:1|yesyesnono0|noyes|$(python3 "$H98/rh98.py" g3exp "$OC2")" \
  "c2 control: that account made inactive — no longer offered — and --check is GO with no G3 note, BN5 ok, G3's lines the two Ecobank accounts alone"
check "$RW98C2|$(n2ok98 "$NC2")|$( [ "$(sub98 "$(oj "$NC2" n4)" accounts)" -ge 1 ] 2>/dev/null && echo n4)|$(case ",$(oj "$NC2" n4ids)," in *",$BANK298,"*) echo listed ;; *) echo "not-listed($(oj "$NC2" n4ids))" ;; esac)" '{"rows":5}|{"rows":1}|1112|n4|listed' \
  "c2' the plant is not vacuous: five rows of N2's classes and one on the second UGX account, through the installed code's own writer — this rehearsal's own reading counts every class of N2 (uCRM's two) and that account under N4, by its row id"
check "$(noteline "$OUT" N2 "uCRM's online $(sub98 "$(oj "$NC2" n2)" crm), Bank Transfer in $(sub98 "$(oj "$NC2" n2)" btin), Bank Transfer out (cash really banked) $(sub98 "$(oj "$NC2" n2)" btout), Bank Charges $(sub98 "$(oj "$NC2" n2)" charges)")$(noteline "$OUT" N4 "that hold rows: $(sub98 "$(oj "$NC2" n4)" accounts), with $(sub98 "$(oj "$NC2" n4)" rows) row(s)")|$(python3 "$H98/rh98.py" nblocks "$SHC2"):$(python3 "$H98/rh98.py" n4sheet "$SHC2")|$(data94)" "yesyes|1:$(oj "$NC2" n4ids)|$D98C2" \
  "…N2 and N4 still this rehearsal's own counts, now of something — N2 every class it counts, N4 an inactive bank account holding a row, one ECO Bank does not offer, by its row id on the sheet alone; the data as the plant left it"
db_back
check "$(data94)|$(oj "$(o97)" offered)" "$D98|UGX:2:1:1,USD:1:1:1" "…the book put back, byte for byte"
fresh98; OUT="$(run98 --after-only --without-bank-balance)"
check "$(has "$OUT" '--redeploy, --check and --without-bank-balance go with a deploy only, never with --after-only, --rollback or --check-bank')$(went98 "$OUT")|$(quiet98)" "yesnono|$P97|0|$D98|$KEPT98|0" "c3 --without-bank-balance with --after-only is refused before anything is read"
OUT="$(run98 --check-bank --without-bank-balance)"
check "$(has "$OUT" '--redeploy, --check and --without-bank-balance go with a deploy only, never with --after-only, --rollback or --check-bank')$(went98 "$OUT")|$(quiet98)" "yesnono|$P97|0|$D98|$KEPT98|0" "c3' …and with --check-bank"
OUT="$(run98 --after-only --rollback)"
check "$(has "$OUT" '--after-only, --rollback and --check-bank are each a run of their own: give one of them')$(went98 "$OUT")|$(quiet98)" "yesnono|$P97|0|$D98|$KEPT98|0" "c3'' two modes in one run are refused"
fresh98; OUT="$(run98 --check-bank)"
nogo98 "c4 --check-bank with 5.18.97 live: refused — it reads ECO Bank as 5.18.98 has it" \
  "--check-bank reads ECO Bank as 5.18.98 has it: the container serves $P97 (5.18.97), not $P98 (5.18.98) — deploy it first. Nothing was changed" "$OUT"
fresh98; OUT="$(run98 --after-only)"
nogo98 "c5 --after-only with 5.18.97 live: refused — there is no deploy to check" "the container serves $P97, not $P98" "$OUT"

echo; echo "== 98-1. refusals before anything changes: the live install not 5.18.97; a part-way copy; a bank-module file; A14 =="
cp -p "$PD/.deployed-commit" "$SB/deployed98.kept"; printf '%s\n' "$P96" >> "$PD/.deployed-commit"
fresh98; OUT="$(run98 --answer NO)"
nogo98 "1a the container serving 5.18.96 ($P96): NO-GO — 5.18.98 was built and tested against 5.18.97" \
  "NO-GO: the container serves $P96; 5.18.98 was built and tested against $P97 (5.18.97) — deploy 5.18.97 first (scripts/deploy-5.18.97.sh) and send its log" "$OUT" "$D98" "$P96"
cat "$SB/deployed98.kept" > "$PD/.deployed-commit"; touch -r "$SB/deployed98.kept" "$PD/.deployed-commit"
check "$(live)" "$P97" "…the container's record put back: 5.18.97"
cp -p "$PD/manifest.json" "$SB/manifest98.kept"; git -C "$REPO" show "$P98:$P/manifest.json" > "$PD/manifest.json"
fresh98; OUT="$(run98 --answer NO)"
nogo98 "1b 5.18.97's record with 5.18.98's manifest installed — a copy that stopped part-way: NO-GO before anything changes" \
  "NO-GO: the container's record says $P97, but the installed manifest says 5.18.98: a copy of 5.18.98 that did not complete (a deploy that stopped part-way)" "$OUT"
check "$(grep -cxF "      $EXP_PUTBACK98" <<<"$OUT")$(grep -c 'deploy-5.18.98.sh --rollback' <<<"$OUT")" "10" "…its stop prints the by-hand put-back of 5.18.97 alone on its line, with its own log wrapper — and no rollback"
git -C "$REPO" show "$P96:$P/manifest.json" > "$PD/manifest.json"
fresh98; OUT="$(run98 --answer NO)"
nogo98 "1c 5.18.97's record with another release's manifest installed (5.18.96's): NO-GO — not the release this script was built against" \
  "NO-GO: the container's record says $P97, but the installed manifest says 5.18.96, not 5.18.97 — the installed plugin is not the release this script was built against" "$OUT"
cat "$SB/manifest98.kept" > "$PD/manifest.json"; touch -r "$SB/manifest98.kept" "$PD/manifest.json"
cp -p "$PD/tabs/accounts/cashbook.php" "$SB/cashbook98.kept"; printf '\n' >> "$PD/tabs/accounts/cashbook.php"
fresh98; OUT="$(run98 --answer NO)"
nogo98 "1d a file 5.18.98 changes, installed but not 5.18.97's (the Cashbook page, one byte more): NO-GO before anything changes, naming it" \
  "NO-GO: the container's record says $P97 (5.18.97), but these installed files are not 5.18.97's: tabs/accounts/cashbook.php — " "$OUT"
check "$(grep -cxF "      $EXP_PUTBACK98" <<<"$OUT")" "1" "…and prints the put-back alone, with its own log"
cat "$SB/cashbook98.kept" > "$PD/tabs/accounts/cashbook.php"; touch -r "$SB/cashbook98.kept" "$PD/tabs/accounts/cashbook.php"
# A bank-module file edited by hand: BR1 (every file ec0accd tracks) sees it first; with BR1's refusal taken out of a copy,
# G1 (the sha256 this script pins) sees it; with both taken out, the run reaches its question — G1's weakened copy, caught.
cp -p "$PD/lib/BankGate.php" "$SB/bankgate98.kept"; printf '// edited by hand on the server\n' >> "$PD/lib/BankGate.php"
fresh98; OUT="$(run98 --answer NO)"
nogo98 "1e ECO Bank's gate edited by hand on the server: NO-GO at BR1 — every file 5.18.97 tracks, by sha256 — naming it" \
  "NO-GO (BR1): the installed plugin is not 5.18.97's as $P97 has it — these files differ or are missing (at most five named): lib/BankGate.php" "$OUT"
BR1_OLD98='  [ -z "$BR1_MISS" ] || stop "NO-GO (BR1)'; G1_OLD98='  [ -z "$G1_BAD" ] || stop "NO-GO (G1)'
M="$(mutant br1blind98 "$DEPLOY98" "$BR1_OLD98" '  true || stop "NO-GO (BR1)')"
fresh98; OUT="$(run98 --answer NO --script "$M")"; rm -f "$M"
nogo98 "1e' …and with BR1's refusal taken out of a copy, G1 refuses it by the sha256 this script pins for 5.18.97's gate" \
  "NO-GO (G1): the installed plugin is not 5.18.97 as it was reviewed — these installed files do not carry the sha256 this script pins for them: lib/BankGate.php — an edit by hand, or another build" "$OUT"
M="$(mutant g1blind98 "$DEPLOY98" "$BR1_OLD98" '  true || stop "NO-GO (BR1)' "$G1_OLD98" '  true || stop "NO-GO (G1)')"
fresh98; OUT="$(run98 --answer NO --script "$M")"; rm -f "$M"
check "$(went98 "$OUT")$(has "$OUT" 'STOP: not confirmed')$(okline "$OUT" G1 'installed is 5.18.97')" "yesyesyesyes" "1e'' a copy without both — G1's weakened copy — reaches the question over the edited gate and calls it 5.18.97's (caught)"
cat "$SB/bankgate98.kept" > "$PD/lib/BankGate.php"; touch -r "$SB/bankgate98.kept" "$PD/lib/BankGate.php"
check "$(inst_ver)$(installed98 "$P97")|$(sha256sum "$PD/lib/BankGate.php" | cut -c1-64)|$(quiet98 | cut -d'|' -f1,3-)" \
      "5.18.970|$(git -C "$REPO" show "$P97:$P/lib/BankGate.php" | sha256sum | cut -c1-64)|$P97|$D98|$KEPT98|0" "…every file put back: 5.18.97's, the data and the quarantine as before"
A14_LOOP98='for f in "$STATE" "$STATE".prev-* "$PREV_STATE" "$PREV_STATE".prev-* "$PREV2_STATE" "$PREV2_STATE".prev-* "$PREV3_STATE" "$PREV3_STATE".prev-* "$PREV4_STATE" "$PREV4_STATE".prev-*; do'
printf 'STRAY_Q_FAILED=%s\n' "$(date -u +%s)" >> "$STATE97"
fresh98; OUT="$(run98 --answer NO)"
nogo98 "1f a failure in 5.18.97's state file (STRAY_Q_FAILED), newer than the newest passing --check: NO-GO at A14, naming it" \
  "(STRAY_Q_FAILED in dnb-5.18.97/state-5.18.97.env)" "$OUT"
check "$(has "$OUT" "NO-GO (A14): a run of this script, of 5.18.97's, of 5.18.96's, of 5.18.95's or of 5.18.94's found the quarantine broken at")$(grep -cxF "      $EXP_QCHECK" <<<"$OUT")" "yes1" "…in A14's words, and the quarantine's --check printed on its own line, with its own log"
M="$(mutant a14prev98 "$DEPLOY98" "$A14_LOOP98" 'for f in "$STATE" "$STATE".prev-* "$PREV2_STATE" "$PREV2_STATE".prev-* "$PREV3_STATE" "$PREV3_STATE".prev-* "$PREV4_STATE" "$PREV4_STATE".prev-*; do')"
fresh98; OUT="$(run98 --answer NO --script "$M")"; rm -f "$M"
check "$(went98 "$OUT")$(has "$OUT" 'STOP: not confirmed')" "yesyesyes" "1f' a copy blind to 5.18.97's state files reaches the question over it (caught)"
sed -i '/^STRAY_Q_FAILED=/d' "$STATE97"
printf 'STRAY_Q_FAILED=%s\n' "$(date -u +%s)" >> "$STATE94"
fresh98; OUT="$(run98 --answer NO)"
nogo98 "1g …in 5.18.94's state file, four releases back: NO-GO at A14, naming it" "(STRAY_Q_FAILED in dnb-5.18.94/state-5.18.94.env)" "$OUT"
M="$(mutant a14prev4_98 "$DEPLOY98" "$A14_LOOP98" 'for f in "$STATE" "$STATE".prev-* "$PREV_STATE" "$PREV_STATE".prev-* "$PREV2_STATE" "$PREV2_STATE".prev-* "$PREV3_STATE" "$PREV3_STATE".prev-*; do')"
fresh98; OUT="$(run98 --answer NO --script "$M")"; rm -f "$M"
check "$(went98 "$OUT")$(has "$OUT" 'STOP: not confirmed')" "yesyesyes" "1g' a copy blind to 5.18.94's state files — reading three releases back, as 5.18.97's A14 did — reaches the question over it (caught)"
sed -i '/^STRAY_Q_FAILED=/d' "$STATE94"
printf 'COPY_AT=%s\n' "$(date -u +%s)" > "$STATE97.prev-20261019T020000Z"
fresh98; OUT="$(run98 --answer NO)"
nogo98 "1h a copy of 5.18.97's recorded after the newest passing --check of the quarantine (its rule 3): NO-GO at A14, naming it" \
  "(dnb-5.18.97/state-5.18.97.env.prev-20261019T020000Z) came after the newest passing --check of the quarantine" "$OUT"
M="$(mutant a14copy98 "$DEPLOY98" '[ "$QCL_T" -gt "$PREV_COPY" ] || stop' 'true || stop')"
fresh98; OUT="$(run98 --answer NO --script "$M")"; rm -f "$M"
check "$(went98 "$OUT")$(has "$OUT" 'STOP: not confirmed')" "yesyesyes" "1h' a copy blind to 5.18.97's copy time reaches the question over it (caught)"
rm -f "$STATE97.prev-20261019T020000Z"
# G2 (SPEC-separate-v2 §2.10 R2, the fourth review RH-3): rule A read through the installed code. SSP added to the book's
# currencies in both copies of the configuration — the file and the store row, so that the pages and the service still
# agree (A23.8) — turns rule A off for both codes: the pin's reading refuses first; with that line taken out of a copy,
# the LIVE code's reading — G2's own — refuses; with both taken out, the refusal no longer names the rule (caught).
# cashbook_currencies is one of the vault's keys (ConfigVault::VAULT_KEYS), and every stage A reads the configuration
# through the installed plugin (switch_state: set_distributors.php --show, whose load refreshes the live vault — the
# script's header and Z1 say so; SPEC-separate-v2 §2.10, review 0-3). So the plugin boots once on the planted
# configuration before anything is measured — as the server's own crons would within minutes — and the vault is kept and
# put back with the configuration, bytes and time (5.18.93's lesson, its rehearsal trial 4 of 11 Oct; trial 1 of this
# rehearsal measured without either, and its "nothing changed" saw the plugin's own refresh).
cfg_keep; cp -p "$VAULT" "$SB/vault98s.kept"
file_set cashbook_currencies UGX,USD,SSP; row_set cashbook_currencies UGX,USD,SSP
settle96; VT98S="$(vt_host)"; sleep 1; settle96
check "$( [ "${VT98S%%:*}" != "${VT98_0%%:*}" ] && echo moved)|$(vt_host)|$(python3 -c 'import json, sys; print(json.load(open(sys.argv[1])).get("config", {}).get("cashbook_currencies", "-"))' "$VAULT" 2>&1)" "moved|$VT98S|UGX,USD,SSP" \
  "1i-pre control: the plugin booted once on the planted configuration — the live vault learned SSP (its bytes moved), and a second boot leaves it as it is (bytes and time): 1i's \"nothing changed\" measures the script, not the plugin's own refresh"
D98S="$(data94)"
fresh98; OUT="$(run98 --check)"
nogo98 "1i SSP among the book's currencies, in both copies of the configuration: rule A off — NO-GO at A23.7 on the pin's reading, before anything changes" \
  "NO-GO (A23.7): rule A is NOT in force on this server's own configuration, as the pin's code reads it" "$OUT" "$D98S"
G2PIN_OLD98='  [ "$(kv "$CIH_D" rule)" = "1" ] || stop "NO-GO (A23.7)'; G2LIVE_OLD98='  [ "$(kv "$CIH_T" rule)" = "1" ] || stop "NO-GO (A23.7)'
M="$(mutant g2pin98 "$DEPLOY98" "$G2PIN_OLD98" '  true || stop "NO-GO (A23.7)')"
fresh98; OUT="$(run98 --script "$M" --check)"; rm -f "$M"
nogo98 "1i' …and with the pin's line taken out of a copy, G2 itself refuses: rule A as the LIVE code reads it, through the installed code" \
  "NO-GO (A23.7): rule A is NOT in force on this server's own configuration as the LIVE code reads it" "$OUT" "$D98S"
M="$(mutant g2blind98 "$DEPLOY98" "$G2PIN_OLD98" '  true || stop "NO-GO (A23.7)' "$G2LIVE_OLD98" '  true || stop "NO-GO (A23.7)')"
fresh98; OUT="$(run98 --script "$M" --check)"; rm -f "$M"
check "$(has "$OUT" 'NO-GO (A23.7)')$(has "$OUT" 'rule A is NOT in force')|$(quiet98)" "nono|$P97|0|$D98S|$KEPT98|0" \
  "1i'' a copy without both — G2's weakened copy — no longer refuses on the rule: whatever stops it next, rule A is never named (caught); nothing changed"
cfg_back; cp -p "$SB/vault98s.kept" "$VAULT"; rm -f "$SB/vault98s.kept"
check "$(data94)|$(oj "$(o98)" curs)|$(vt_host)" "$D98|UGX,USD|$VT98_0" "…both copies of the configuration put back: the book's currencies UGX and USD again, every table as before — and the live vault put back with them: bytes and time as before"
check "$(grep -c '^STRAY_Q_FAILED=' "$STATE97" "$STATE96" "$STATE95" "$STATE94" | awk -F: '{s += $2} END {print s}')|$(quiet98 | cut -d'|' -f1,3-)|$(sha256sum "$QREC" | cut -c1-16)" "0|$P97|$D98|$KEPT98|0|$QREC98" \
  "…every planted line taken out: 5.18.97 live, the data, the quarantine and its record as before — the script read the record, never wrote it"

echo; echo "== 98-2. G3 both ways: a card that shows no figure refuses; --without-bank-balance makes it a NOTE and goes on =="
db_keep
ACC98="$(acct_add98 "$ACCNAME98" USD)"
OG="$(o97)"; D98G="$(data94)"
check "$(case "$ACC98" in [1-9]*) echo added ;; *) echo "$ACC98" ;; esac)|$(oj "$OG" offered)|$(oj "$OG" both)|$(vt_host)" "added|UGX:2:1:1,USD:2:2:1|no|$VT98_0" \
  "2 an account the book names Ecobank, USD, added through the installed code's own addAccount() as the Accounts screen adds one — offered on ECO Bank, its card with no statement: USD's Ecobank cards no longer all show a figure; the live vault untouched"
fresh98; OUT="$(run98 --check)"; T="$(last98)"
nogo98 "2a --check over it: NO-GO at G3 — not both ECO BANK cards show a figure — nothing changed" \
  "NO-GO (G3): not both ECO BANK cards show a figure (USD(offered:named:kept=2:2:1) both=no)" "$OUT" "$D98G"
check "$(g3say98 "$R98/run-$T.txt")|$(grep -cxF "      $EXP_WBB98" <<<"$OUT")$(grep -cxF "      $EXP_RUNBOOK98" <<<"$OUT")$(grep -c 'deploy-5.18.98.sh --rollback' <<<"$OUT")|$(noids98 "$OUT")|$(bankhits98 "$R98/run-$T.txt")" \
      "$(python3 "$H98/rh98.py" g3exp "$OG")|100|0|0" \
  "…G3's lines name the card without a statement by its row id (this rehearsal's own reading); the deploy with --without-bank-balance printed alone with its own log — the operator's choice, never this script's; no name printed"
G3STOP_OLD98='    stop "NO-GO (G3): not both ECO BANK cards show a figure ('
M="$(mutant g3blind98 "$DEPLOY98" "$G3STOP_OLD98" '    true || stop "NO-GO (G3): not both ECO BANK cards show a figure (')"
fresh98; S0="$(states98)"; OUT="$(run98 --script "$M" --check)"; rm -f "$M"
check "$(checkgo98 "$OUT")|$(okline "$OUT" G3 'both ECO BANK cards show a figure')$(noteline "$OUT" G3 '--without-bank-balance')" "yesyesnono0|0|$S0|nono" \
  "2b a copy without G3's refusal — G3's weakened copy — says GO over a card that shows no figure, with neither G3's ok nor its note (caught)"
fresh98; S0="$(states98)"; OUT="$(run98 --check --without-bank-balance)"; T="$(last98)"
check "$(checkgo98 "$OUT")|$(noteline "$OUT" G3 "--without-bank-balance, the operator's explicit choice: not both ECO BANK cards show a figure (USD(offered:named:kept=2:2:1) both=no)" 'ECO Bank shows no balance for the account(s) above')$(okline "$OUT" G3 'both ECO BANK cards')" "yesyesnono0|0|$S0|yesno" \
  "2c --check --without-bank-balance over the same card: GO, G3 a NOTE in the operator's words (SPEC Q2) — every other check of stage A held"
check "$(has "$OUT" '  --check           stage A alone, every refusal and note of a deploy with --without-bank-balance')$(grep -cxF "      $EXP_RUNBOOK98W" <<<"$OUT")$(grep -cxF "      $EXP_RUNBOOK98" <<<"$OUT")|$(printed98 "$OUT")|$(g3say98 "$R98/run-$T.txt")" "yes10|1|0|0|$(python3 "$H98/rh98.py" g3exp "$OG")" \
  "…its summary says so and prints the runbook's deploy WITH --without-bank-balance, alone, with its own log — never without it"
db_back
check "$(data94)|$(oj "$(o97)" offered)" "$D98|UGX:2:1:1,USD:1:1:1" "…the account taken out (the book put back byte for byte): both cards a figure again"
# --without-bank-balance typed over cards that both show a figure (the fourth review, conf-2/OPS-1): G3 holds, so the
# flag changes nothing — and the deploy command --check hands over is the runbook's, without it.
fresh98; S0="$(states98)"; OUT="$(run98 --check --without-bank-balance)"
check "$(checkgo98 "$OUT")|$(okline "$OUT" G3 'both ECO BANK cards show a figure')$(noteline "$OUT" G3 '--without-bank-balance')|$(has "$OUT" ' with --without-bank-balance (not needed: G3 held, both ECO BANK cards show a figure — the deploy below is printed without it)')$(grep -cxF "      $EXP_RUNBOOK98" <<<"$OUT")$(grep -cxF "      $EXP_RUNBOOK98W" <<<"$OUT")" "yesyesnono0|0|$S0|yesno|yes10" \
  "2d --check --without-bank-balance over cards that both show a figure: GO, G3 ok and no note — the flag did nothing; the summary says it is not needed and prints the runbook's deploy WITHOUT it, never carrying the choice where it changed nothing"
# G3's first condition, BankGate::open(), on its own (the fourth review, RH-4): ECO Bank's switch cleared in both copies of
# the configuration, every card's statement still kept — G3 refuses on the switch alone; a copy without that line says GO
# over a closed ECO Bank (caught).
cfg_keep
cfg_set "$BK97" off; D98O="$(data94)"
fresh98; OUT="$(run98 --check)"
nogo98 "2e ECO Bank's switch cleared in both copies, both cards' statements kept: NO-GO at G3 — ECO Bank closed — nothing changed" \
  "NO-GO (G3): not both ECO BANK cards show a figure (ECO-Bank-closed(switch:absent," "$OUT" "$D98O"
check "$(noteline "$OUT" BR3 "ECO Bank's switch, $BK97, is absent in the configuration files")|$(both_copies "$BK97")" "yes|-" \
  "…BR3 says the switch is not ON, as a note; G3 decides, on the switch alone — the offered accounts, named and kept, are as before"
G3OPEN_OLD98='  [ "$(kv "$BK_A_KI" open)" = "yes" ] || G3_WHY='
M="$(mutant g3open98 "$DEPLOY98" "$G3OPEN_OLD98" '  true || G3_WHY=')"
fresh98; S0="$(states98)"; OUT="$(run98 --script "$M" --check)"; rm -f "$M"
check "$(checkgo98 "$OUT")|$(okline "$OUT" G3 'both ECO BANK cards show a figure' 'BankGate::open() true')" "yesyesnono0|0|$S0|yes" \
  "2f a copy without G3's first condition — BankGate::open() — says GO over a closed ECO Bank, and calls it open (caught)"
cfg_back
check "$(both_copies "$BK97")|$(data94)" "11|$D98" "…the switch put back in both copies: every table and file as before"

echo; echo "== 98-3. C5 against a planted write: a release whose library writes on a read path; the copy checks without a vault =="
# The plant sits inside listLocationWhere()'s own "cb_accounts exists" branch — the only place the split reads the chart of
# accounts — so it writes only where that table is (never an exception on a book without it), and only when the split is
# asked for: cash in hand, T1 and the list with no key never reach it; C3's keyed lists do.
V_W_OLD98="$(cat <<'EOF'
            $cashKinds = implode(', ', array_map([$this->pdo(), 'quote'], self::CASH_IN_HAND_KINDS));
EOF
)"
V_W_NEW98="$(cat <<'EOF'
            $this->pdo()->exec("UPDATE cb_accounts SET updated_at = updated_at || '.' WHERE id = (SELECT MIN(id) FROM cb_accounts)");   // RH98: a write planted on a read path
            $cashKinds = implode(', ', array_map([$this->pdo(), 'quote'], self::CASH_IN_HAND_KINDS));
EOF
)"
V98W="$(release_variant98 lib/CashbookService.php "$V_W_OLD98" "$V_W_NEW98")"
V98W_SHA="$(git -C "$REPO" show "$V98W:$P/lib/CashbookService.php" | sha256sum | cut -c1-64)"
check "$(git -C "$REPO" diff --name-only "$P98" "$V98W" | paste -sd' ' -)|$(git -C "$REPO" rev-parse --short "$V98W^")|$(git -C "$REPO" show "$V98W:$P/lib/CashbookService.php" | grep -c 'RH98: a write planted on a read path')" "$P/lib/CashbookService.php|$P97|1" \
  "3 the variant: the release commit with one line added to the library's list predicate — a write on every read that asks for the split — cut on $P97"
S="$(pinned_copy98 v98write "$V98W" "$DEPLOY98")"
fresh98; OUT="$(run98 --script "$S" --check)"; rm -f "$S"
nogo98 "3a a copy pinned to it, short and in full: refused at A0 — the pin's library is not the reviewed one by the sha256 this script pins" \
  "the pin's files are not the reviewed 5.18.98's by sha256: lib/CashbookService.php — this script is for another build. Nothing was changed" "$OUT"
S="$(pinned_copy98 v98write2 "$V98W" "$DEPLOY98" lib/CashbookService.php "$V98W_SHA")"
fresh98; OUT="$(run98 --script "$S" --check)"
nogo98 "3b …and with that file's sha256 re-pinned too, the copy checks refuse it: C5 — a digest moved under the new code's reads" \
  "NO-GO (C1–C5): the copy checks do not hold on this book — C5:a-digest-moved-under-the-new-code's-reads" "$OUT"
check "$(okline "$OUT" A22-T1 "under this server's own PHP")$(okline "$OUT" BK1 'the same to the last digit')$(has "$OUT" 'C1:')$(has "$OUT" 'C2:')$(has "$OUT" 'C3:')$(has "$OUT" 'C4:')" "yesyesnononono" \
  "…every check before it held — T1 and cash in hand never ask for the split — and C1–C4 too: C5 alone sees the write (on the copy's cb_accounts digest)"
C5J_OLD98="  [ \"\$(kv \"\$l\" c5)\" = \"same\" ] || w=\"\$w C5:a-digest-moved-under-the-new-code's-reads\""
M="$(mutant c5blind98 "$S" "$C5J_OLD98" '  true')"; rm -f "$S"
fresh98; S0="$(states98)"; OUT="$(run98 --script "$M" --check)"; rm -f "$M"
check "$(checkgo98 "$OUT")|$(okline "$OUT" C5 "unchanged by every read of 5.18.98's code")" "yesyesnono0|0|$S0|yes" \
  "3c a copy without C5's judgement — C5's weakened copy — says GO over the planted write, and calls the digests unchanged (caught)"
CTOR_OLD98='    o="$(docker exec -u "$DB_OWNER" -e DN_VAULT_FILE="$sub/c-$nm-vault.json" -e DN_DATA_DIR="$d" "$CONTAINER" php -d display_errors=stderr -r "$CP_PHP" ctor'
M="$(mutant cnovault98 "$DEPLOY98" "$CTOR_OLD98" '    o="$(docker exec -u "$DB_OWNER" -e DN_DATA_DIR="$d" "$CONTAINER" php -d display_errors=stderr -r "$CP_PHP" ctor')"
fresh98; OUT="$(run98 --script "$M" --check)"; T="$(last98)"; trip98; rm -f "$M"
check "$(checkgo98 "$OUT" | cut -d'|' -f1)|$(audit98 "$T" | sed -E 's/.* novault=([0-9]+) .*/\1/' | awk '{print ($1 >= 2 ? "two-or-more" : "only-" $1)}')|$(vault98 "$T")|$(data94)" \
      "yesyesnono0|two-or-more|$VT98_0>$VT98_0|$D98" \
  "3d a copy whose copy checks start each code without a vault of its own says GO — caught by the observers' record: two PHP processes or more on the copy checks' code copies (c-old, c-new) with no DN_VAULT_FILE of their own; the live vault and the data untouched"
# C4 against a release that counts differently (the fourth review, RH-2): countEntries() one more for all projects — a
# read that neither T1, A24 nor cash in hand makes, so only the copy checks can see it. Pinned to it, its sha256 re-pinned
# as 3b's, the copy checks refuse at C4 alone; a copy without C4's judgement says GO over it (caught). C1's and C2's
# defects are refused before the copy checks — cash in hand by A22-T1, A23.2 and BK1, the list with no key by T1 — so
# their judge lines are shown above on the judge itself (0-k', 0-k'', 0-k'c).
V_C4_OLD98="$(cat <<'EOF'
            $r = $this->dbq("SELECT COUNT(*) as n FROM cb_ledger WHERE NOT(amount=0 AND sr='')");
EOF
)"
V_C4_NEW98="$(cat <<'EOF'
            $r = $this->dbq("SELECT COUNT(*) + 1 as n FROM cb_ledger WHERE NOT(amount=0 AND sr='')");   // RH98: countEntries() one more for all projects
EOF
)"
V98C4="$(release_variant98 lib/CashbookService.php "$V_C4_OLD98" "$V_C4_NEW98")"
V98C4_SHA="$(git -C "$REPO" show "$V98C4:$P/lib/CashbookService.php" | sha256sum | cut -c1-64)"
check "$(git -C "$REPO" diff --name-only "$P98" "$V98C4" | paste -sd' ' -)|$(git -C "$REPO" rev-parse --short "$V98C4^")|$(git -C "$REPO" show "$V98C4:$P/lib/CashbookService.php" | grep -c 'RH98: countEntries() one more for all projects')" "$P/lib/CashbookService.php|$P97|1" \
  "3e the variant: the release commit with countEntries()'s count for all projects one more — cut on $P97"
S="$(pinned_copy98 v98c4 "$V98C4" "$DEPLOY98" lib/CashbookService.php "$V98C4_SHA")"
fresh98; OUT="$(run98 --script "$S" --check)"
nogo98 "3f a copy pinned to it, that file's sha256 re-pinned: the copy checks refuse it at C4 — countEntries() not the same under both codes for all projects" \
  "NO-GO (C1–C5): the copy checks do not hold on this book — C4:counts-pending-pl(11/12)" "$OUT"
check "$(okline "$OUT" A22-T1 "under this server's own PHP")$(okline "$OUT" BK1 'the same to the last digit')$(has "$OUT" 'C1:')$(has "$OUT" 'C2:')$(has "$OUT" 'C3:')$(has "$OUT" 'C5:')" "yesyesnononono" \
  "…every check before it held — T1 and cash in hand never ask countEntries() — and C1, C2, C3 and C5 too: C4 alone sees it"
C4J_OLD98='  c_full "$(kv "$l" c4)" || w="$w C4:counts-pending-pl($(kv "$l" c4))"'
M="$(mutant c4blind98 "$S" "$C4J_OLD98" '  true')"; rm -f "$S"
fresh98; S0="$(states98)"; OUT="$(run98 --script "$M" --check)"; rm -f "$M"
check "$(checkgo98 "$OUT")|$(okline "$OUT" C4 'the same under both codes, 11/12 cells')" "yesyesnono0|0|$S0|yes" \
  "3g a copy without C4's judgement — C4's weakened copy — says GO over it, and calls 11 of 12 cells the same (caught)"
check "$(quiet98 | cut -d'|' -f1,3-)|$(sha256sum "$QREC" | cut -c1-16)|$(vt_host)" "$P97|$D98|$KEPT98|0|$QREC98|$VT98_0" "…after sections 1–3: 5.18.97 live, the data, the quarantine and the live vault as before"

echo; echo "== 98-4. the deploy, as the operator runs it — DEPLOY typed; a non-cash row posted between the before-copy and the after-copy =="
O_PRE="$(cash98 pre)"; DIG_PRE="$(oj "$O_PRE" digest)"; BOOK_PRE="$(book98x)"; OB_PRE="$(o97)"; NP_PRE="$(o98)"
LEDGER98="$(ledger95)"; RN98="$(sq "SELECT COUNT(*) FROM cb_ledger")"
printf '%s\n' "$BANKU98" > "$SB/web/.add_noncash98"
fresh98; OUT="$(run98 --answer DEPLOY)"; OUT98D="$OUT"; T98D="$(last98)"
O_POST="$(cash98 post)"; OB_POST="$(o97)"; NP_POST="$(o98)"
SHEET98D="$(sheet_of "$OUT")"
echo "  …    the deploy's own tally:$(grep -E '^  checks +[0-9]+ ok, ' <<<"$OUT" | sed -E 's/^  checks +/ /')"
check "$(fails "$OUT")$(has "$OUT" '5.18.98 (deploy): PASSED')$(live)" "0yes$P98" "the deploy PASSES: 0 failed, the container serves $P98"
for l in "ok    A0 the release delta is exactly 5.18.98's $N98 files — 5 added, $(( N98 - 5 )) changed, each the reviewed one by the sha256 this script pins ($N98 of $N98) — and no migration: 088_bank_statements.sql the reviewed one in both release commits (sha256 ${MIG88_SHA98:0:16}…)" \
         "ok    A7 Domain B untouched" \
         "ok    A22L the live code, as installed, carries all seven of 5.18.95's fixes" \
         "ok    BR1 the installed plugin is live 5.18.97 ($P97): all " \
         "ok    G1 installed is 5.18.97 ($P97): its manifest says 5.18.97, and all 15 files" \
         "ok    BK3 ECO Bank's links in the pin, as text: includes/navigation.php and tabs/admin/ceo_dashboard.php each hold exactly one href=\"?page=dashboard&tab=bank\", under the pinned BankGate::showLink() call" \
         "ok    A14 the stray store is quarantined and the quarantine holds" \
         "ok    A12 on this server's own configuration the pin's gate stops the Starlink retry job" "ok    A13 the pin's retry job" \
         "ok    A15 under this server's own PHP" "ok    A16 under this server's own PHP" \
         "ok    A17 5.18.97's documents, unchanged by 5.18.98, under this server's own PHP" \
         "ok    A22-T1 under this server's own PHP" "($T1SIG98)" \
         "ok    A23.1 the live code's figure — 5.18.97's cashInHand(), installed, its rule A in force, run from its code copy on a copy of its own — equals D2 = A's SQL" \
         "ok    A23.2 the pin's figure equals D2 = A's SQL on the copy" \
         "ok    BK1 cash in hand, 5.18.97's code (installed) and 5.18.98's on the same copy, the same to the last digit in all 8 cells" \
         "ok    A23.3 the parts add up in all 8 cells, to the cent" \
         "note  A23.5 the 12 Sep adjustment is not in the book" \
         "ok    A23.7 rule A is in force on this server's own configuration, as both codes read it" \
         "ok    G2 rule A is on, read through the INSTALLED code on a copy" \
         "ok    A23.8 the pages' settings row names the service's base and currencies — the same (" \
         "ok    BR2 088 is recorded on the copy and intact" \
         "ok    BR3 ECO Bank's switch, $BK97, is ON in the configuration files — the operator's" \
         "ok    G3 both ECO BANK cards show a figure, through the live 5.18.97 classes on the copy" \
         "ok    BK2 BankGate::showLink(), through the pin's code on copies of this book" \
         "ok    C1 cash in hand, 5.18.97's code from Git and 5.18.98's from Git on ONE copy of the book" "ok    C2 the Cashbook's list with no key" \
         "ok    C3 on 5.18.98's code: per project × view" "ok    C4 countEntries()" "ok    C5 the copy's row digest" \
         "ok    A24 South Sudan, a South Sudan tenant without SSP and a Uganda tenant with SSP read exactly as under 5.18.97" \
         "GO — evidence recorded" "and without the repair key file, never copied" \
         "checking out $P98 (5.18.98, the release commit) for the documented deploy" "ok    container serves $P98" \
         "ok    V4 no fatal or parse error of" \
         "ok    BA4 ECO Bank (?page=dashboard&tab=bank) and its export (bk_export=csv) without a session each send the visitor to the sign-in page (302)" \
         "ok    CA4 the Cashbook (?page=dashboard&tab=cashbook), its tab \"Bank & other accounts\" (cb_view=noncash), its cash export (cb_export=csv) and the tab's own export (cb_book=noncash) without a session each send the visitor to the sign-in page (302)" \
         "ok    V3j ECO Bank's switch, $BK97, is unchanged by this run and ON (before=bk=on/on, after=bk=on/on; files/store)" \
         "ok    R1 all $N98 files 5.18.98 changes are installed exactly as $P98 has them (5 new)" "ok    R1 the installed manifest says 5.18.98" \
         "ok    CA1 installed = 5.18.98's release: all $N98 of its files carry the sha256 this script pins; all 15 files of 5.18.97 it keeps" "accepts 15 of its changed or new PHP files as installed" \
         "ok    BA1 ECO Bank is installed whole" "ok    BK3 ECO Bank's links as installed: includes/navigation.php and tabs/admin/ceo_dashboard.php each hold exactly one" \
         "ok    R5 all" "ok    R7 the database since this run's stage A: the migration ledger's last row the same (" \
         "ok    BA1 migration 088 is applied exactly as the release's 088_bank_statements.sql makes it, as before this run" "every row exactly as this run's stage A read it" \
         "ok    R18 the installed retry job" "ok    R20 under this server's own PHP the installed test of master's lock passes" \
         "ok    R21 under this server's own PHP the installed test of the retired auto-pull passes" "ok    R22 the installed documents are 5.18.97's, unchanged by 5.18.98" \
         "ok    R23.7 rule A is in force on this server's own configuration, as the installed code reads it" \
         "ok    R23.1 the installed figure equals D2 = A's SQL on the copy" "ok    R23.2 5.18.97's code (from Git) on the same copy equals D2 = A's SQL in all 8 cells" \
         "ok    BA2 BK1 again: cash in hand under the installed 5.18.98 and under 5.18.97's code, on the same new copy, the same to the last digit in all 8 cells" \
         "ok    R23.3 the parts add up in all 8 cells" "ok    R23.5 the installed Balance column, in every view and project" \
         "ok    R23.6 the installed tools/cash_in_hand.php, run from the installed code's copy on a copy of its own" \
         "note  CA3 the book moved since A23 (" "1 row(s) added, 0 changed, 0 removed (ids on the sheet only)" \
         "ok    BA2 BK2 again, through the installed code on a new copy, each switch state in its own PHP process" \
         "ok    BA2 G3 again, through the installed code on the new copy: ECO Bank open, and both ECO BANK cards show a figure" \
         "ok    CA2 C1–C5 again, on a NEW copy, 5.18.97's code from Git and the INSTALLED 5.18.98" \
         "ok    R24 X5 and D1, statically: T4 on the installed files passes ($T4SIG98)" "ok    R24 the installed CEO page is the pin's byte for byte" \
         "ok    R25 under this server's own PHP the installed T1 passes ($T1SIG98)" \
         "ok    R26 South Sudan, a South Sudan tenant without SSP and a Uganda tenant with SSP read exactly as under 5.18.97 through the installed lib" \
         "ok    Z1 the live vault ($VAULT_IN96) is as this run found it" "ok    Z2 nothing of the vault, no webhook_secret file, no settings file and no copy of the book is left" \
         "note  R19 the quarantine holds — nothing at the old name, nothing a new store makes, the kept file unchanged — but master has not completed a run of the retry job since the copy" \
         "  names             G1–G3 are SPEC-separate-v2 §2.10's refusals R1–R3, and CA1–CA4 its after-checks A1–A4" \
         "  what changed      the Cashbook's list, on this Uganda book with no SSP (rule A), shows physical cash only" \
         "  migrations        none added (A0, R7): the ledger the same before and after"; do
  check "$(has "$OUT" "$l")" "yes" "4: ${l:0:110}"
done
check "$(ctl98 "$OUT" A22)$(ctl98 "$OUT" R23)" "77" "5.18.95's seven fixes read with their controls, before (A22, the pin) and after (R23, the installed code): each control names 5.18.94's defects exactly"
check "$(ctl97 "$OUT" A22)$(ctl97 "$OUT" R23)|$(ctl98 "$OUT97D" A22)$(ctl98 "$OUT97D" R23)|$(ctl97 "$OUT97D" A22)$(ctl97 "$OUT97D" R23)" "00|00|77" \
  "control: the reader counts this release's words — 5.18.97's reader finds none of them on this deploy, this release's finds none on 5.18.97's deploy (97-3), and 5.18.97's still finds all fourteen there"
check "$(noteids98 "$OUT")" "A17 A23.4 A23.5 A23.6 BN5 CA3 G3 N1 N2 N3 N4 R19 R23.9" \
  "its notes are exactly 5.18.97's on this book (A17, A23.4–A23.6, R19, R23.9), BN5's, G3's (the account not named Ecobank), N1–N4 and CA3's — the non-cash row"
check "$(noteline "$OUT" N1 "$(n1say98 "$(oj "$NP_PRE" n1)") (the same totals as C3's")$(noteline "$OUT" N4 "that hold rows: $(sub98 "$(oj "$NP_PRE" n4)" accounts), with $(sub98 "$(oj "$NP_PRE" n4)" rows) row(s)")|$(g3say98 "$R98/run-$T98D.txt")" "yesyes|$(python3 "$H98/rh98.py" g3exp "$OB_PRE")" \
  "N1 and N4 count what this rehearsal counts before the deploy; G3's lines the offered accounts by row id"
RID98="$(sq "SELECT id FROM cb_ledger WHERE description = '$NC_DESC98'")"
check "$( [ -e "$SB/web/.add_noncash98" ] && echo unspent || echo spent)|$(sq "SELECT COUNT(*) FROM cb_ledger WHERE description = '$NC_DESC98' AND account_id = $BANKU98")|$(python3 "$H98/rh98.py" drift "$SHEET98D")|$(sub98 "$(oj "$NP_POST" noncash)" all)" "spent|1|1:$RID98|$(( $(sub98 "$(oj "$NP_PRE" noncash)" all) + 1 ))" \
  "4a the stand-in's non-cash row was posted on the Ecobank UGX account during the run, after A23's copy — CA3's drift is that one row, named on the sheet by its id, and it is one more row off the list, as this rehearsal reads the design"
check "$(python3 "$H98/rh98.py" sheet "$SHEET98D" "$O_PRE" A23)|$(python3 "$H98/rh98.py" sheet "$SHEET98D" "$O_POST" R23)|$(oj "$O_PRE" digest | cut -d: -f1)" "8/8|8/8|$(oj "$O_POST" digest | cut -d: -f1 | awk '{print $1 - 1}')" \
  "4b the sheet's figures are the book's: A23's equal this rehearsal's own SQL before the run, R23's after it — a non-cash row moves no cell of cash in hand"
check "$(python3 "$H98/rh98.py" ecosheet "$SHEET98D")|$(python3 "$H98/rh98.py" cpsheet "$SHEET98D")|$(python3 "$H98/rh98.py" nblocks "$SHEET98D"):$(python3 "$H98/rh98.py" n4sheet "$SHEET98D")" \
      "$(python3 "$H98/rh98.py" ecoexp "$OB_PRE" 3)|$(oj "$NP_PRE" listed)/$(( $(oj "$NP_PRE" listed) - $(sub98 "$(oj "$NP_PRE" noncash)" all) ))/$(sub98 "$(oj "$NP_PRE" noncash)" all);$(oj "$NP_POST" listed)/$(( $(oj "$NP_POST" listed) - $(sub98 "$(oj "$NP_POST" noncash)" all) ))/$(sub98 "$(oj "$NP_POST" noncash)" all)|1:$(oj "$NP_PRE" n4ids)" \
  "4c the sheet's ECO Bank lines (A23 twice, R23 once), its copy checks' rows — A23's before the row, R23's after it — and N4's accounts by row id: this rehearsal's own reading"
check "$(stat -c %a "$SHEET98D")|$(stat -c %a "$OUT98")|$(grep -cxF "      less $SHEET98D" <<<"$OUT")|$(sheetsha_of "$OUT")|$(grep -c 'the physical count' "$SHEET98D")|$(bankhits98 "$SHEET98D")" "600|700|1|$(sha256sum "$SHEET98D" | cut -c1-16)|1|0" \
  "4d the cash sheet is 0600 in a folder of mode 700; F names it with its sha256-16 and prints \`less <path>\` alone; it ends with the physical count's lines; it names no account, number or holder"
ROWS98D="$(sed -n 's/^CIH_A_ROWS=//p' "$STATE98" | tail -1)"
check "$(grep -cE '^CIH_A_AT=20[0-9]{2}-[0-9]{2}-[0-9]{2}T[0-9:]{8}Z$' "$STATE98")|$(sed -n 's/^CIH_A_SHEET=//p' "$STATE98" | tail -1)|$(sed -n 's/^CIH_A_DIGEST=//p' "$STATE98" | tail -1)|$(sed -n 's/^VAULT_A=//p' "$STATE98" | tail -1)" \
      "1|$SHEET98D:$(python3 "$H98/rh98.py" presha "$SHEET98D")|$DIG_PRE|$VT98_0" \
  "4e the state file names A23's time, the sheet with its sha256-16 as A23 left it, the row digest this rehearsal computes and the live vault as it was — no figure"
check "$(sed -n 's/^BK_BEFORE=//p' "$STATE98" | tail -1)|$(sed -n 's/^BANK_BEFORE=//p' "$STATE98" | tail -1 | grep -c "^ledger=applied:$MIG88_MD598 objects=27/27 .* rows=$(oj "$OB_PRE" rows) ")|$(grep -c '^BANK_BEFORE=.* sig=' "$STATE98")|$(sed -n 's/^REDEPLOY=//p' "$STATE98" | tail -1)|$(sed -n 's/^WITHOUT_BANK=//p' "$STATE98" | tail -1)" "bk=on/on|1|0||" \
  "4f …and ECO Bank as stage A found it: the switch on in both places, 088 complete with the kept rows — its reading without the HMAC; not a redeploy, not --without-bank-balance"
check "$(stat -c %a "$ROWS98D")|$(grep -c . "$ROWS98D")|$(grep -cvE '^[0-9]+ [0-9a-f]{16}$' "$ROWS98D")|$(grep -c "^COPY_AT=$(stat -c %Y "$PD/lib/CashbookService.php")\$" "$STATE98")$(grep -c '^Q_HOLDS_BEFORE=yes$' "$STATE98")$(grep -c '^STRAY_Q_FAILED=' "$STATE98")" "600|$(oj "$O_PRE" rows)|0|110" \
  "4g A23's row file 0600, one line per row (an id and a 16-hex hash); the state file records the copy's time as stamp_copy gave lib/CashbookService.php, the quarantine held before, no failure"
check "$(figs98 "$R98/run-$T98D.txt" "$STATE98" "$ROWS98D" "$OUT98"/*.log "$R98/exec/run-$T98D.rec" 2>/dev/null)|$(grep -cF -f "$SCAN96" "$R98/run-$T98D.txt" "$STATE98" | awk -F: '{s += $NF} END {print s + 0}')|$(bankhits98 "$R98/run-$T98D.txt" "$STATE98" "$ROWS98D")|$(noids98 "$OUT")" "0|0|0|0" \
  "4h no figure of the book in any printed form, no planted secret, no account name, number or holder in the run's terminal, its state file or its row file; no row id in the terminal but G3's"
check "$(hits98 "$T98D")|$(audit98 "$T98D" | sed -E 's/^copies=([0-9]+) /\1 /' | awk '{print ($1 >= 15 ? "many" : $1 " only") " " $2 " " $3}')|$(vault98 "$T98D")" "0|many novault=0 plugin=0|$VT98_0>$VT98_0" \
  "4i the observers: nothing planted in /tmp; every PHP process on a copy (fifteen or more — the copy checks' two codes per reading among them) carried a vault of its own, none ran the tool or T1 from the plugin folder; the live vault the same"
SUM98="$(sed -n '/^== F\. Summary ==$/,$p' <<<"$OUT")"
check "$(grep -cxF "      $EXP_AFTER98" <<<"$SUM98")$(grep -cxF "      $EXP_QCHECK" <<<"$SUM98")$(grep -cxF "      $EXP_ROLLBACK98" <<<"$SUM98")$(grep -cxF "      $EXP_PUTBACK98" <<<"$SUM98")$(grep -cxF "      $EXP_DEPLOY98" <<<"$OUT")$(grep -cxF "      $EXP_REDEPLOY98" <<<"$OUT")$(grep -cxF "      $EXP_CHECKBANK98" <<<"$OUT")$(grep -cE '^ +.*set_config\.php --key|set_config\.php --key [a-z_]+ --(value|clear)' <<<"$OUT" | sed 's/^[1-9].*/some/')" "11110000" \
  "every later command printed exactly, on its own line — the --after-only, the quarantine's --check, the rollback and the by-hand put-back — and the deploy's own, --redeploy, --check-bank (no --without-bank-balance here) and any switch command never"
check "$(printed98 "$OUT")|$(order98 "$OUT")|$(rb_where98 "$OUT")|$(grep -c 'deploy-5.18.98.sh --rollback' <<<"$OUT")" "4|0|0|verdict<rollback<putback|after|1" \
  "…four commands in the summary, each with its own log wrapper, no line holding two; the rollback after the verdict, once, then the put-back in a block of its own"
check "$(installed98 "$P98")$(inst_ver)$(data98x)$(book98x)|$(ledger95)" "05.18.98$DX98$BOOK_PRE|$LEDGER98" \
  "every file 5.18.97 → 5.18.98 changes installed as the pin has it; every table of the plugin's data as before, the book as before but for the stand-in's row; the migration ledger unchanged — no migration"
check "$(oj "$OB_POST" tables)|$(oj "$OB_POST" rows)|$(oj "$OB_POST" triggers)|$(oj "$OB_POST" ledger)|$(oj "$OB_POST" list)|$(both_copies "$BK97")" "5/5|$(oj "$OB_PRE" rows)|11/11|applied:$MIG88_MD598|$(oj "$OB_PRE" list)|11" \
  "ECO Bank as the deploy found it, read with 5.18.97's rehearsal's own SQL: 088 complete, every kept row, every offered account as before; the switch on in both places"
check "$(kept_state)$(oldname)$(sha256sum "$QREC" | cut -c1-16)$(host_code98)$(left_tmp98)$(evo_calls)" "${KEPT98}0${QREC98}0${TMP98}0" \
  "the deploy left the quarantine as it was, no copy of the code on the host or in /tmp, and never called Evolution"
BKP98="$(ls -d "$OUT98"/backup-* 2>/dev/null | head -1)"
check "$(tar -tzf "$BKP98/data.tar.gz" 2>/dev/null | grep -c 'emergency_repair_key')$(tar -tzf "$BKP98/data.tar.gz" 2>/dev/null | grep -c '/kyc_config.json$')" "01" \
  "its backup holds the data folder's settings file and never the repair key file (5.18.95's rule, kept)"

echo; echo "== 98-5. --after-only, run exactly as the deploy's summary printed it; its weakened copies; --check-bank =="
copy_back98 30; COPY98="$(sed -n 's/^COPY_AT=//p' "$STATE98" | tail -1)"
set_sched $(( COPY98 + 300 )); base_log
ALINE="$(printed "$OUT98D" "cd $REPO && bash scripts/deploy-5.18.98.sh --after-only")"
check "$ALINE" "$EXP_AFTER98" "5 the --after-only command, as the deploy's summary printed it"
fresh98; O_A="$(cash98 after)"; OB_A="$(o97)"
OUT="$(run_line98 '' "$ALINE")"; TA="$(last98)"; OUT98A="$OUT"; ALOG="$(ls -1 "$OUT98"/after-*.log 2>/dev/null)"; SHEETA="$(sheet_of "$OUT")"
check "$(fails "$OUT")$(has "$OUT" '5.18.98 (after): PASSED')$(okline "$OUT" R19 'the quarantine holds: master ran the retry job at' "after the copy (at $(utc "$COPY98"))")" "0yesyes" \
  "5a master's run of the job since the copy: R19 conclusive — the quarantine holds — PASSED"
check "$(noteline "$OUT" CA3 'the book moved since A23' '1 row(s) added, 0 changed, 0 removed')$(okline "$OUT" CA2 'C1–C5 again, on a NEW copy')$(okline "$OUT" CA1 "installed = 5.18.98's release")$(okline "$OUT" CA4 'without a session each send the visitor to the sign-in page (302)')$(okline "$OUT" R7 "the database since the deploy's stage A (its state file): the migration ledger's last row the same (")$(okline "$OUT" Z1 "the live vault is as the deploy's stage A found it")" "yesyesyesyesyesyes" \
  "…CA3 against the deploy's own A23 reading names the non-cash row again, as a NOTE; CA1, CA2, CA4; R7 against the deploy's reading; Z1 against the deploy's"
check "$(okline "$OUT" BA1 "migration 088 is applied exactly as the release's 088_bank_statements.sql makes it" "none fewer than the deploy's stage A recorded")$(okline "$OUT" V3j "is unchanged by this run and ON (before=bk=on/on, after=bk=on/on; files/store)")$(okline "$OUT" BA2 'G3 again')$(okline "$OUT" BA2 'BK2 again')$(ctl98 "$OUT" R23)" "yesyesyesyes7" \
  "…BA1 holds 088 as the deploy left it; V3j the switch ON and unchanged; BA2 the gate and both cards again on a new copy; R23a–g with their controls"
check "$(python3 "$H98/rh98.py" sheet "$SHEETA" "$O_A" R23)|$(stat -c %a "$SHEETA")|$(grep -cxF "      less $SHEETA" <<<"$OUT")|$(python3 "$H98/rh98.py" drift "$SHEETA")|$(python3 "$H98/rh98.py" ecosheet "$SHEETA")" "8/8|600|1|1:$RID98|$(python3 "$H98/rh98.py" ecoexp "$OB_A" 1)" \
  "…its own cash sheet, 0600: its figures this rehearsal's own SQL on the book now, its drift the non-cash row by its id, ECO Bank's offered accounts once"
check "$(printed98 "$OUT")|$(order98 "$OUT")|$(grep -cxF "      $EXP_AFTER98" <<<"$OUT")$(grep -cxF "      $EXP_ROLLBACK98" <<<"$OUT")$(grep -cxF "      $EXP_CHECKBANK98" <<<"$OUT")" "4|0|0|verdict<rollback<putback|110" \
  "…its summary prints the same four commands, each alone with its own log wrapper, in the same order"
check "$(printf '%s\n' "$ALOG" | grep -c .)|$(stat -c %a "$ALOG" 2>/dev/null)|$(grep -c '5.18.98 (after): PASSED' "$ALOG" 2>/dev/null)|$(figs98 "$R98/line-$TA.txt" "$ALOG")|$(bankhits98 "$R98/line-$TA.txt" "$ALOG")|$(noids98 "$OUT")|$(quietobs98 "$TA")" \
      "1|600|1|0|0|0|0|novault=0 plugin=0|$VT98_0>$VT98_0" \
  "…its own log, 0600, holds the run; no figure, account name, number or row id in the terminal or the log; the observers quiet; the live vault untouched"
# BA2's G3 again, the FAIL (the fourth review, RH-4): an account the book names Ecobank, USD, with no statement, added after
# the deploy, whose stage A found both cards a figure and which went without --without-bank-balance — the after-check
# FAILS, never a note; a copy whose FAIL is turned into an ok passes over it (caught). The book put back afterwards.
D98B5="$(data94)"; db_keep
ACC98B="$(acct_add98 "$ACCNAME98" USD)"
base_log; OUT="$(run98 --after-only)"; FB98="$(fails "$OUT")"
check "$(case "$ACC98B" in [1-9]*) echo added ;; *) echo "$ACC98B" ;; esac)|$(failline "$OUT" BA2 'G3 again: not both ECO BANK cards show a figure through the installed code' 'stage A found both')$(noteline "$OUT" BA2 'G3 again')$(has "$OUT" '5.18.98 (after): PASSED')" "added|yesnono" \
  "5a' a card with no statement added after the deploy: BA2's G3 again FAILS through the installed code — stage A found both, and the deploy went without --without-bank-balance — never a note"
BA2G3_OLD98='    else bad "BA2 G3 again: not both ECO BANK cards show a figure through the installed code'
M="$(mutant ba2g3ok98 "$DEPLOY98" "$BA2G3_OLD98" '    else ok "BA2 G3 again: not both ECO BANK cards show a figure through the installed code')"
base_log; OUT="$(run98 --script "$M" --after-only)"; rm -f "$M"
check "$(okline "$OUT" BA2 'G3 again: not both ECO BANK cards show a figure through the installed code')$(failline "$OUT" BA2 'G3 again')|$(( ${FB98:-0} - $(fails "$OUT") ))" "yesno|1" \
  "5a'' a copy whose BA2 says ok where it must FAIL — BA2's weakened copy — counts the same card one failure fewer, as an ok (caught)"
db_back
check "$(data94)|$(oj "$(o97)" offered)" "$D98B5|UGX:2:1:1,USD:1:1:1" "…the account taken out: the book put back byte for byte, both cards a figure again"
CA3_OLD98='    else note "CA3 the book moved since A23'
M="$(mutant ca3fail98 "$DEPLOY98" "$CA3_OLD98" '    else bad "CA3 the book moved since A23')"
base_log; OUT="$(run98 --script "$M" --after-only)"; rm -f "$M"
check "$(failline "$OUT" CA3 'the book moved since A23' '1 row(s) added')$(has "$OUT" '5.18.98 (after): PASSED')$(fails "$OUT")" "yesno1" \
  "5b a copy whose CA3 FAILs on the drift — A3-as-a-note's weakened copy — FAILs the same --after-only on the non-cash row the real script says as a NOTE (caught): the book moves all the time, and C5 has proved the code writes nothing"
touch "$SB/web/.leak_cb98"; base_log; OUT="$(run98 --after-only)"; CA4L98="$(grep -E '^  FAIL  CA4 ' <<<"$OUT" | head -1)"
check "$(failline "$OUT" CA4 'does not answer with the sign-in page as it must:' 'the tab=200|other|11|0|00;')$( [ -e "$SB/web/.leak_cb98" ] && echo unspent || echo spent)$(has "$OUT" '5.18.98 (after): PASSED')$(fails "$OUT")|$(has "$CA4L98" 'the page=')$(has "$CA4L98" 'the cash export=')$(has "$CA4L98" "the tab's export=")" "yesspentno1|nonono" \
  "5c the Cashbook's tab \"Bank & other accounts\" answering a visitor with no session with the page (the stand-in's .leak_cb98): CA4 FAILS, naming that answer alone — the page, the cash export and the tab's export still the sign-in page, none of them named"
CA4_OLD98='        [ "$ca4v" = "302|login|00|0|00" ] || CA4_R="$CA4_R ${ca4%%:*}=$ca4v;"'
M="$(mutant ca4blind98 "$DEPLOY98" "$CA4_OLD98" '        true || CA4_R="$CA4_R ${ca4%%:*}=$ca4v;"')"
touch "$SB/web/.leak_cb98"; base_log; OUT="$(run98 --script "$M" --after-only)"; rm -f "$M"
check "$(okline "$OUT" CA4 'without a session each send the visitor to the sign-in page (302)')$( [ -e "$SB/web/.leak_cb98" ] && echo unspent || echo spent)$(has "$OUT" '5.18.98 (after): PASSED')" "yesspentyes" \
  "5c' a copy whose CA4 does not judge the answers — CA4's weakened copy — PASSES over the same leak (caught)"
# The tab's own export (cb_book=noncash) answering a visitor with no session with a file — 200, a Content-Disposition,
# no redirect (the stand-in's .leak_cbx98; the fourth review, RH-5): CA4 FAILS naming that answer alone; a copy whose
# CA4 never asks for that export passes over the same leak (caught).
touch "$SB/web/.leak_cbx98"; base_log; OUT="$(run98 --after-only)"; CA4L98="$(grep -E '^  FAIL  CA4 ' <<<"$OUT" | head -1)"
check "$(failline "$OUT" CA4 'does not answer with the sign-in page as it must:' "the tab's export=200|other|00|1|00;")$( [ -e "$SB/web/.leak_cbx98" ] && echo unspent || echo spent)$(has "$OUT" '5.18.98 (after): PASSED')$(fails "$OUT")|$(has "$CA4L98" 'the page=')$(has "$CA4L98" 'the tab=')$(has "$CA4L98" 'the cash export=')" "yesspentno1|nonono" \
  "5c'' the tab's own export answering a visitor with no session with a file (the stand-in's .leak_cbx98): CA4 FAILS, naming that answer alone — 200, no redirect, one Content-Disposition — the page, the tab and the cash export still the sign-in page"
CA4X_OLD98=' "the cash export:tab=cashbook&cb_export=csv&cb_proj=dishnet" "the tab'"'"'s export:tab=cashbook&cb_export=csv&cb_proj=dishnet&cb_book=noncash&cb_acct=other"; do'
M="$(mutant ca4noexp98 "$DEPLOY98" "$CA4X_OLD98" ' "the cash export:tab=cashbook&cb_export=csv&cb_proj=dishnet"; do')"
touch "$SB/web/.leak_cbx98"; base_log; OUT="$(run98 --script "$M" --after-only)"; rm -f "$M"
check "$(okline "$OUT" CA4 'without a session each send the visitor to the sign-in page (302)')$( [ -e "$SB/web/.leak_cbx98" ] && echo unspent || echo spent)$(has "$OUT" '5.18.98 (after): PASSED')" "yesunspentyes" \
  "5c''' a copy whose CA4 probes the page, the tab and the cash export but not the tab's own export — CA4's weakened copy — PASSES over the same leak, never asking for it (caught)"
rm -f "$SB/web/.leak_cbx98"
cp -p "$PD/lib/CashbookService.php" "$SB/cbsvc98.kept"
edit_installed lib/CashbookService.php "$V_W_OLD98" "$V_W_NEW98"
base_log; OUT="$(run98 --after-only)"
check "$(failline "$OUT" CA2 'the copy checks do not hold on the new copy with the installed code' "C5:a-digest-moved-under-the-new-code's-reads")$(failline "$OUT" R1 'installed files that differ' 'lib/CashbookService.php')$(failline "$OUT" CA1 'the installed files are not as the release' 'lib/CashbookService.php')$(okline "$OUT" BA2 'BK1 again')" "yesyesyesyes" \
  "5d a write planted on a read path of the INSTALLED library: CA2 FAILS at C5 — a digest moved under the installed code's reads, on the new copy — and R1 and CA1 by its bytes; cash in hand the same (BA2's BK1)"
M="$(mutant c5blind98b "$DEPLOY98" "$C5J_OLD98" '  true')"
base_log; OUT="$(run98 --script "$M" --after-only)"; rm -f "$M"
check "$(okline "$OUT" CA2 'C1–C5 again, on a NEW copy')$(failline "$OUT" CA2 'C5:')$(failline "$OUT" R1 'installed files that differ' 'lib/CashbookService.php')" "yesnoyes" \
  "5d' a copy without C5's judgement passes CA2 over the same write (caught) — only R1's bytes still see it"
cat "$SB/cbsvc98.kept" > "$PD/lib/CashbookService.php"; touch -r "$SB/cbsvc98.kept" "$PD/lib/CashbookService.php"
check "$(installed98 "$P98")$(stat -c '%u:%g' "$PD/lib/CashbookService.php")|$(sed -n 's/^COPY_AT=//p' "$STATE98" | tail -1)" "01000:1000|$(stat -c %Y "$PD/lib/CashbookService.php")" "…the installed library put back: every file as the pin has it, its time the copy's"
# CA1's other two clauses (the fourth review, RH-7): a file of 5.18.97's that this release keeps, edited after the deploy
# (ECO Bank's gate, one byte longer), and a changed PHP file that no longer parses (the tab's page): CA1 FAILS naming each
# — the kept file as not 5.18.97's by the sha256 this script pins, the page by php -l under the container's PHP. Each put
# back afterwards as it was, its owner the store's user.
cp -p "$PD/lib/BankGate.php" "$SB/bankgate98a.kept"; cp -p "$PD/tabs/accounts/cashbook_noncash.php" "$SB/cbnc98.kept"
printf '\n' >> "$PD/lib/BankGate.php"; printf '\n<?php rh98 planted parse error (\n' >> "$PD/tabs/accounts/cashbook_noncash.php"
base_log; OUT="$(run98 --after-only)"
check "$(failline "$OUT" CA1 'the installed files are not as the release' "lib/BankGate.php(not-5.18.97's)" 'php-l:tabs/accounts/cashbook_noncash.php')$(has "$OUT" '5.18.98 (after): PASSED')" "yesno" \
  "5d'' after the deploy, ECO Bank's gate one byte longer and the tab's page no longer parsing: CA1 FAILS naming both — the kept file not 5.18.97's by the sha256 this script pins, the page by php -l"
cat "$SB/bankgate98a.kept" > "$PD/lib/BankGate.php"; touch -r "$SB/bankgate98a.kept" "$PD/lib/BankGate.php"
cat "$SB/cbnc98.kept" > "$PD/tabs/accounts/cashbook_noncash.php"; touch -r "$SB/cbnc98.kept" "$PD/tabs/accounts/cashbook_noncash.php"
check "$(installed98 "$P98")|$(stat -c '%u:%g' "$PD/lib/BankGate.php" "$PD/tabs/accounts/cashbook_noncash.php" | paste -sd, -)|$(sha256sum "$PD/lib/BankGate.php" | cut -c1-64)" \
      "0|1000:1000,1000:1000|$(git -C "$REPO" show "$P97:$P/lib/BankGate.php" | sha256sum | cut -c1-64)" "…both put back: every file as the pin has it, ECO Bank's gate 5.18.97's, each owned by the store's user"
cfg_keep; D98W="$(data94)"
printf '%s' "$BK97" > "$SB/web/.flip_switch"
base_log; OUT="$(run98 --after-only)"
check "$(failline "$OUT" V3j "ECO Bank's switch, $BK97, CHANGED across this run (before=bk=on/on, after=bk=absent/absent; files/store)")$( [ -e "$SB/web/.flip_switch" ] && echo unspent || echo spent)$(has "$OUT" '5.18.98 (after): PASSED')|$(noteline "$OUT" BA2 'G3 again: not both ECO BANK cards show a figure' "ECO Bank's switch was changed since the deploy (V3j)")$(noteline "$OUT" BA2 'the deploy went with --without-bank-balance')$(failline "$OUT" BA2 'G3 again')" "yesspentno|yesnono" \
  "5e the switch cleared in both places while an --after-only runs, after its stage A read it (the stand-in's .flip_switch): V3j FAILS, naming both readings — this script never sets it; BA2's G3 again, on the new copy closed, says it as the switch changed since the deploy — V3j's to judge — never the --without-bank-balance note, never a second failure (the fourth review, RH-4)"
cfg_back
check "$(both_copies "$BK97")|$(data94)" "11|$D98W" "…the switch put back in both places: every table and file as before"
fresh98; OUT="$(run98 --check)"
nogo98 "5f --check with 5.18.98 live: refused — it is stage A before a deploy — and the --after-only printed instead, alone" \
  "--check is stage A before a deploy, and the container already serves $P98 (5.18.98) — run --after-only instead, its own command, alone:" "$OUT" "$(data94)" "$P98"
check "$(grep -cxF "      $EXP_AFTER98" <<<"$OUT")" "1" "…the --after-only on its own line, with its own log"
fresh98; OB_CB="$(o97)"; S0="$(states98)"
OUT="$(run98 --check-bank)"; T="$(last98)"; SHCB="$(sheet_of "$OUT")"; CBLOG="$(ls -1 "$OUT98"/check-bank-*.log 2>/dev/null | grep -c .)"
check "$(fails "$OUT")$(has "$OUT" '5.18.98 (check-bank): PASSED')|$(okline "$OUT" CB "both ECO BANK cards show a figure: ECO Bank open, and in every book currency an offered account named Ecobank, each with a statement kept ($(oj "$OB_CB" bn5)) — G3's question, asked of the installed code, answers yes")$(okline "$OUT" CB "088 is applied on the copy exactly as the release's 088 makes it" "its tables hold $(oj "$OB_CB" rows)")" "0yes|yesyes" \
  "5g --check-bank PASSES on the installed 5.18.98: 088 as the release makes it, holding the kept statements; both cards a figure — G3's question, asked again — in this rehearsal's own counts"
check "$(python3 "$H98/rh98.py" cbterm "$R98/run-$T.txt")|$(python3 "$H98/rh98.py" ecosheet "$SHCB")" "$(python3 "$H98/rh98.py" cbexp "$OB_CB")|$(python3 "$H98/rh98.py" ecoexp "$OB_CB" 1)" \
  "…one line per offered account, by row id — currency, named, kept, linked, its last kept line's age — and the sheet each one's last kept line's date: this rehearsal's own reading"
check "$(printed98 "$OUT")|$(noids98 "$OUT")|$(states98)|$(backups98)|$(stat -c %a "$SHCB")|$(figs98 "$R98/run-$T.txt")|$(bankhits98 "$R98/run-$T.txt" "$SHCB")|$(quietobs98 "$T")" \
      "0|0|0|0|$S0|0|600|0|0|0|novault=0 plugin=0|$VT98_0>$VT98_0" \
  "…it prints no command but \`less\`, no row id but its own lines, writes no state and no backup; its sheet 0600; no figure, name or number; the observers quiet"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "5h the quarantine's own --check, as the runbook orders after this: PASSED with 5.18.98 live"
QREC98="$(sha256sum "$QREC" | cut -c1-16)"; KEPT98="$(kept_state)"

echo; echo "== 98-6. the rollback, run exactly as printed — ROLLBACK typed; ECO Bank and its switch unchanged; --redeploy too soon =="
RLINE="$(printed "$OUT98A" "cd $REPO && bash scripts/deploy-5.18.98.sh --rollback")"
check "$RLINE" "$EXP_ROLLBACK98" "6 the rollback command, as the --after-only's summary printed it after its verdict"
S98SHA="$(sha256sum "$STATE98" | cut -c1-64)"; DR0="$(data94)"; LEDGER98R="$(ledger95)"; OB_RB0="$(o97)"
fresh98; O_RB="$(cash98 rb)"
OUT="$(run_line98 ROLLBACK "$RLINE")"; TR="$(last98)"; RLOG="$(ls -1 "$OUT98"/rollback-*.log 2>/dev/null)"; SHEETR="$(sheet_of "$OUT")"
check "$(fails "$OUT")$(has "$OUT" '5.18.98 (rollback): PASSED')$(live)" "0yes$P97" "6a the rollback PASSES: 5.18.97 serving again"
for l in "ok    container serves $P97 (5.18.97)" "ok    RB the installed manifest says 5.18.97" \
         "ok    RB all 12 files 5.18.98 changed are 5.18.97's again, byte for byte (sha256); the 5 files it added — the tab's class and page, its export and two tests — stay on disk, and 5.18.97 reads none of them" \
         "ok    RB the Cashbook's split is gone from the code — no CashbookNonCashView, no tab page, no non-cash export, no split in the library: 5.18.97's; ECO Bank stays exactly as it was — all 15 of 5.18.97's files it keeps by their pinned sha256" \
         "ok    RB 5.18.95's seven fixes are still installed after the rollback" \
         "ok    RB the database across the rollback: the migration ledger's last row the same (" \
         "ok    RB cash in hand reads exactly as before the deploy on a copy of the book" \
         "ok    RB ECO Bank as the rollback leaves it, through the installed 5.18.97 classes on the copy: its switch ON, open, and both ECO BANK cards show a figure ($(oj "$OB_RB0" bn5))" \
         "ok    RB 088 stays after the rollback, complete — all 27 objects as the release's 088 makes them — and every row exactly as before it ($(oj "$OB_RB0" rows):" \
         "ok    RB ECO Bank's switch, $BK97, as before the rollback (bk=on/on; files/store) — the rollback changes no setting" \
         "ok    V3j ECO Bank's switch, $BK97, is unchanged by this run and ON" \
         "ok    RB the installed plugin is 5.18.97's again: its own P3b in place" \
         "ok    RB the documents are still 5.18.97's" "ok    RB every authorisation record still matches its terms under 5.18.97's code" \
         "ok    RB the quarantine still holds, as its record says" "note  RB run the quarantine's --check next" "note  RB the code is 5.18.97 again." \
         "ok    Z1 the live vault is as the deploy's stage A found it" "ok    Z2 nothing of the vault" \
         "  serving           $P97  (plugin 5.18.97)" "  what it means     the Cashbook's list shows every entry again"; do
  check "$(has "$OUT" "$l")" "yes" "6a: ${l:0:110}"
done
check "$(grep -cxF "      $EXP_QCHECK" <<<"$OUT")$(grep -cxF "      $EXP_REDEPLOY98" <<<"$OUT")$(grep -cxF "      $EXP_DEPLOY98" <<<"$OUT")$(grep -c 'deploy-5.18.98.sh --rollback' <<<"$OUT")|$(printed98 "$OUT")|$(grep -cxF "      less $SHEETR" <<<"$OUT")|$(grep -cE '^ +.*set_config\.php --key|set_config\.php --key [a-z_]+ --(value|clear)' <<<"$OUT" | sed 's/^[1-9].*/some/')" "1100|2|0|0|1|0" \
  "…it prints the quarantine's --check and --redeploy, each alone with its own log — never the plain deploy, a rollback or a switch command: no step of it clears ECO Bank's switch"
check "$(python3 "$H98/rh98.py" sheet "$SHEETR" "$O_RB" RB | cut -d/ -f1)|$(stat -c %a "$SHEETR")|$(python3 "$H98/rh98.py" ecosheet "$SHEETR")" "8|600|$(python3 "$H98/rh98.py" ecoexp "$OB_RB0" 1)" \
  "…the rollback's own cash sheet, 0600: D2 = A on a copy, this rehearsal's own SQL in every cell; ECO Bank's offered accounts as the installed 5.18.97 classes read them"
check "$(installed98 "$P97")$(inst_ver)$(for f in $ADDED98; do [ -f "$PD/$f" ] && echo kept; done | grep -c kept)" "05.18.975" "every changed file as 5.18.97 has it, manifest 5.18.97; the five added files stay on disk"
check "$(printf '%s\n' "$RLOG" | grep -c .)|$(stat -c %a "$RLOG" 2>/dev/null)|$(grep -c '5.18.98 (rollback): PASSED' "$RLOG" 2>/dev/null)|$(sha256sum "$STATE98" | cut -c1-64)|$(ls "$OUT98"/rollback-state-*.env 2>/dev/null | grep -c .)|$(grep -c "^RB_COPY_AT=$(stat -c %Y "$PD/lib/CashbookService.php")\$" "$OUT98"/rollback-state-*.env 2>/dev/null)" "1|600|1|$S98SHA|1|1" \
  "…its own log, 0600; the deploy's state file untouched — the rollback's record a file of its own, holding its copy's time (RB_COPY_AT, as stamp_copy gave lib/CashbookService.php) for A14 of a later deploy"
check "$(data94)$(ledger95)$(kept_state)$(oldname)$(sha256sum "$QREC" | cut -c1-16)$(host_code98)$(left_tmp98)|$(oj "$(o97)" rows)|$(both_copies "$BK97")|$(figs98 "$R98/line-$TR.txt" "$RLOG")|$(bankhits98 "$R98/line-$TR.txt" "$RLOG")|$(quietobs98 "$TR")" \
      "$DR0$LEDGER98R${KEPT98}0${QREC98}0${TMP98}|$(oj "$OB_RB0" rows)|11|0|0|0|novault=0 plugin=0|$VT98_0>$VT98_0" \
  "the rollback changed no data — 088's rows stay, the switch stays on — not the ledger and not the quarantine; no copy left, no figure or name printed; the observers quiet"
fresh98; OUT="$(run98 --answer NO --redeploy)"
nogo98 "6b --redeploy right after the rollback, before the quarantine's --check its runbook asks for: NO-GO at A14 — the rollback's own copy came after the newest passing --check (rule 3)" \
  "NO-GO (A14): a rollback of 5.18.98 by this script copied the plugin at" "$OUT" "$DR0"
check "$(has "$OUT" "($(basename "$OUT98")/rollback-state-")$(grep -cxF "      $EXP_QCHECK" <<<"$OUT")" "yes1" "…naming the rollback's own record, and printing the quarantine's --check on its own line, with its own log"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "…the quarantine's own --check after the rollback, as RB asks: PASSED"
QREC98="$(sha256sum "$QREC" | cut -c1-16)"; KEPT98="$(kept_state)"; D98="$(data94)"

echo; echo "== 98-7. --redeploy after the rollback, G3 both ways for real: refused over a card with no statement; --without-bank-balance =="
ACC98="$(acct_add98 "$ACCNAME98" USD)"
OG="$(o97)"; D98G="$(data94)"
check "$(case "$ACC98" in [1-9]*) echo added ;; *) echo "$ACC98" ;; esac)|$(oj "$OG" offered)|$(oj "$OG" both)" "added|UGX:2:1:1,USD:2:2:1|no" "7 the account named Ecobank with no statement added again, through the installed 5.18.97's own addAccount()"
fresh98; OUT="$(run98 --answer DEPLOY --redeploy)"
nogo98 "7a --redeploy over it, DEPLOY typed: NO-GO at G3, before the question — nothing changed" \
  "NO-GO (G3): not both ECO BANK cards show a figure (USD(offered:named:kept=2:2:1) both=no)" "$OUT" "$D98G"
check "$(grep -cxF "      $EXP_WBBRD98" <<<"$OUT")$(okline "$OUT" BR1 "5 of the 5 files 5.18.98 adds are already on disk")$(noteline "$OUT" BR1 'although --redeploy was not asked for')" "1yesno" \
  "…it prints the redeploy with --without-bank-balance, alone, with its own log; BR1 says 5.18.98's five files are on disk, as --redeploy expects them"
fresh98; OUT="$(run98 --answer DEPLOY --redeploy --without-bank-balance)"; OUT98W="$OUT"; T="$(last98)"
check "$(fails "$OUT")$(has "$OUT" '5.18.98 (deploy): PASSED')$(live)$(installed98 "$P98")$(inst_ver)" "0yes${P98}05.18.98" "7b --redeploy --without-bank-balance, DEPLOY typed: PASSED, $P98 serving, every file as the pin has it"
check "$(noteline "$OUT" G3 "--without-bank-balance, the operator's explicit choice: not both ECO BANK cards show a figure (USD(offered:named:kept=2:2:1) both=no)")$(noteline "$OUT" BA2 'G3 again: not both ECO BANK cards show a figure' "the deploy went with --without-bank-balance, the operator's explicit choice")$(okline "$OUT" V3j 'is unchanged by this run and ON')$(okline "$OUT" CA3 'no drift since A23')$(has "$OUT" '— or, with --without-bank-balance, said otherwise')" "yesyesyesyesyes" \
  "…G3 a NOTE and BA2's G3 a NOTE, both in the operator's words; the switch ON and unchanged; no drift; the summary says the cards are said otherwise"
check "$(sed -n 's/^WITHOUT_BANK=//p' "$STATE98" | tail -1)|$(sed -n 's/^REDEPLOY=//p' "$STATE98" | tail -1)|$(order98 "$OUT")|$(grep -cxF "      $EXP_CHECKBANK98" <<<"$OUT")|$(printed98 "$OUT")" "1|1|verdict<check-bank<rollback<putback|1|5|0|0" \
  "…its state file records --without-bank-balance and --redeploy; after the verdict --check-bank in a block of its own, then the rollback and the put-back — each alone with its own log"
sqx "DELETE FROM cb_accounts WHERE id = $ACC98" >/dev/null
check "$(oj "$(o97)" offered)|$(oj "$(o97)" both)" "UGX:2:1:1,USD:1:1:1|yes" "7c the account taken out of the chart again: both cards a figure"
copy_back98 30; COPY98="$(sed -n 's/^COPY_AT=//p' "$STATE98" | tail -1)"; set_sched $(( COPY98 + 300 )); base_log
ALINE2="$(printed "$OUT98W" "cd $REPO && bash scripts/deploy-5.18.98.sh --after-only")"
fresh98; OUT="$(run_line98 '' "$ALINE2")"; T="$(last98)"; OUT98A2="$OUT"
check "$(fails "$OUT")$(has "$OUT" '5.18.98 (after): PASSED')|$(okline "$OUT" BA2 'G3 again, through the installed code on the new copy: ECO Bank open, and both ECO BANK cards show a figure')$(okline "$OUT" CA3 'no drift since A23')$(okline "$OUT" V3j 'is unchanged by this run and ON')|$(order98 "$OUT")" "0yes|yesyesyes|verdict<check-bank<rollback<putback" \
  "7d its --after-only, as printed, PASSES: both cards a figure again (BA2's G3 ok), no drift; and since the deploy went with --without-bank-balance it prints --check-bank, alone, before the rollback"
CBLINE="$(printed "$OUT98A2" "cd $REPO && bash scripts/deploy-5.18.98.sh --check-bank")"
check "$CBLINE" "$EXP_CHECKBANK98" "7e --check-bank, as that summary printed it"
fresh98; OB_CB="$(o97)"; OUT="$(run_line98 '' "$CBLINE")"; T="$(last98)"
check "$(fails "$OUT")$(has "$OUT" '5.18.98 (check-bank): PASSED')$(okline "$OUT" CB 'both ECO BANK cards show a figure' 'answers yes')|$(python3 "$H98/rh98.py" cbterm "$R98/line-$T.txt")|$(quietobs98 "$T")" "0yesyes|$(python3 "$H98/rh98.py" cbexp "$OB_CB")|0|novault=0 plugin=0|$VT98_0>$VT98_0" \
  "…run as printed: PASSED, both cards a figure, its lines this rehearsal's own reading; the observers quiet"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "7f the quarantine's own --check, as the runbook orders: PASSED"
QREC98="$(sha256sum "$QREC" | cut -c1-16)"; KEPT98="$(kept_state)"

echo; echo "== 98-8. a rollback whose copy leaves 5.18.98's routes.php behind; deployed again with --redeploy, clean =="
git -C "$REPO" show "$P98:$P/includes/routes.php" > "$SB/routes98.left"; chmod 644 "$SB/routes98.left"
printf '%s\n' "$SB/routes98.left" > "$SB/web/.left98"
fresh98; OUT="$(run98 --answer ROLLBACK --rollback)"
check "$(live)|$( [ -e "$SB/web/.left98" ] && echo unspent || echo spent)|$(cmp -s "$PD/includes/routes.php" "$SB/routes98.left" && echo left)|$(fails "$OUT")|$(has "$OUT" '5.18.98 (rollback): 2 FAILED')" "$P97|spent|left|2|yes" \
  "8a a rollback whose copy leaves 5.18.98's routes.php behind: 5.18.97 serving, the routes 5.18.98's, two checks FAIL"
check "$(failline "$OUT" RB "installed files that are not 5.18.97's after the rollback: includes/routes.php")$(failline "$OUT" RB "the installed code after the rollback is not 5.18.97's as it must be:" 'includes/routes.php:cashbook_noncash_csv.php')" "yesyes" \
  "…RB FAILS by sha256, naming the file, and by the non-cash export still routed"
git -C "$REPO" show "$P97:$P/includes/routes.php" > "$PD/includes/routes.php"
# The control on 8a's second FAIL, as 5.18.97's 9b is on its own: a copy whose RB content check reads nothing, over the same
# routes left behind (the trigger planted again; 5.18.97 already serving, so the run checks what it finds) — it calls the
# split gone, and only the sha256 still sees the file: 8a's second FAIL is the content check's, not an echo of the first.
printf '%s\n' "$SB/routes98.left" > "$SB/web/.left98"
RBM98_OLD='  grep -qF -- "${rbm#*|}" "$DEST/${rbm%%|*}" 2>/dev/null && RB_BK="$RB_BK ${rbm%%|*}:${rbm#*|}"'
M="$(mutant rbblind98 "$DEPLOY98" "$RBM98_OLD" '  true')"
fresh98; OUT="$(run98 --answer ROLLBACK --script "$M" --rollback)"; rm -f "$M"
check "$(live)|$( [ -e "$SB/web/.left98" ] && echo unspent || echo spent)|$(cmp -s "$PD/includes/routes.php" "$SB/routes98.left" && echo left)|$(okline "$OUT" RB "the Cashbook's split is gone from the code")$(failline "$OUT" RB "installed files that are not 5.18.97's after the rollback: includes/routes.php")|$(fails "$OUT")" "$P97|spent|left|yesyes|1" \
  "8a' a copy whose RB content check is blind to the split — RB's weakened copy — over the same routes left behind (5.18.97 already serving): it calls the split gone (caught); only the sha256 still sees the file"
git -C "$REPO" show "$P97:$P/includes/routes.php" > "$PD/includes/routes.php"
check "$(installed98 "$P97")$(stat -c '%u:%g' "$PD/includes/routes.php")" "01000:1000" "…the routes put back as 5.18.97's: every file 5.18.97's again"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "…the quarantine's own --check after that rollback: PASSED"
QREC98="$(sha256sum "$QREC" | cut -c1-16)"; KEPT98="$(kept_state)"
OB_F="$(o97)"
# Deployed again with --without-bank-balance typed over cards that both show a figure (the fourth review, conf-2/OPS-1):
# G3 holds, so the flag does nothing — and is recorded as nothing, so that a later --after-only relaxes nothing on it.
fresh98; OUT="$(run98 --answer DEPLOY --redeploy --without-bank-balance)"; T="$(last98)"
check "$(fails "$OUT")$(has "$OUT" '5.18.98 (deploy): PASSED')$(live)$(installed98 "$P98")$(inst_ver)|$(okline "$OUT" CA3 'no drift since A23')$(okline "$OUT" G3 'both ECO BANK cards show a figure')$(okline "$OUT" BR1 "5 of the 5 files 5.18.98 adds are already on disk")|$(oj "$(o97)" rows)|$(both_copies "$BK97")" \
      "0yes${P98}05.18.98|yesyesyes|$(oj "$OB_F" rows)|11" \
  "8b deployed again with --redeploy (and --without-bank-balance, over cards that both show a figure): PASSED, $P98 serving; CA3 ok — no drift: the control for its NOTE — G3 ok, BR1 the five files on disk; every kept row and the switch unchanged"
check "$(noteids98 "$OUT")|$(order98 "$OUT")" "A17 A23.4 A23.5 A23.6 BN5 G3 N1 N2 N3 N4 R19 R23.9|verdict<rollback<putback" "…its notes are the clean deploy's without CA3's; its summary's order as before — no --check-bank block: the flag did nothing"
check "$(sed -n 's/^WITHOUT_BANK=//p' "$STATE98" | tail -1)|$(sed -n 's/^REDEPLOY=//p' "$STATE98" | tail -1)|$(noteline "$OUT" G3 '--without-bank-balance')$(has "$OUT" '— or, with --without-bank-balance, said otherwise')$(grep -cxF "      $EXP_CHECKBANK98" <<<"$OUT")" "|1|nono0" \
  "8c …and the flag typed over cards that both show a figure is recorded as what it did, nothing: the state file's WITHOUT_BANK empty, REDEPLOY 1; no G3 note in the operator's words, no \"said otherwise\", no --check-bank"
D98F="$(data94)"; db_keep
ACC98C="$(acct_add98 "$ACCNAME98" USD)"
base_log; OUT="$(run98 --after-only)"
check "$(case "$ACC98C" in [1-9]*) echo added ;; *) echo "$ACC98C" ;; esac)|$(failline "$OUT" BA2 'G3 again: not both ECO BANK cards show a figure through the installed code' 'stage A found both')$(noteline "$OUT" BA2 'the deploy went with --without-bank-balance')$(has "$OUT" '5.18.98 (after): PASSED')" "added|yesnono" \
  "8d its --after-only over a card with no statement added since: BA2's G3 again FAILS — the flag typed at that deploy changed nothing there, so it relaxes nothing here: the state file's reading, never the typed flag"
db_back
check "$(data94)|$(oj "$(o97)" offered)" "$D98F|UGX:2:1:1,USD:1:1:1" "…the account taken out: the book put back byte for byte, both cards a figure again"

echo; echo "== 98-10. what the rehearsal left behind, and what it never reached =="
fresh98   # the last logs and sheets into the sweeps' folders
check "$(evo_calls)" "0" "Evolution never called — not by the deploys, their checks, the rollbacks or the code they ran on copies"
check "$(ls -a "$REPO/scripts" | grep -E '^\..+\.sh$' | paste -sd, -)" ".qpin.sh" "no weakened or re-pinned copy is left in the clone's scripts/ — the quarantine's one pinned copy aside"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )" "" "every weakened copy, release variant, pinned copy and planted edit found its anchor exactly once — none was skipped"
M="$(mutant anchorprobe98 "$DEPLOY98" 'NO SUCH ANCHOR IN THE SCRIPT' 'x' 2>/dev/null)"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )$(test -e "$M" && echo made || echo none)" "mutant:anchorprobe98 none" \
  "control on the control: a weakened copy whose anchor is missing is recorded as such, and no copy is made"
rm -f "$SB/anchor-missing"
S="$(pinned_copy98 pinprobe98 "$P98" "$DEPLOY98" lib/NoSuchFile.php "$(printf '0%.0s' $(seq 1 64))" 2>/dev/null)"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )" "pinned_copy98:pinprobe98 " "control on the control: a re-pinned copy whose file has no pinned line is recorded as such"
rm -f "$SB/anchor-missing" "$S"
check "$( [ -f "$SB/bad-runs" ] && tr '\n' ' ' < "$SB/bad-runs" )" "" "every run took the options it was given — none answered 'unknown option'"
OUT="$(run98 --after-only --rehearsal-unknown-option)"
check "$(has "$OUT" 'unknown option: --rehearsal-unknown-option')$( [ -s "$SB/bad-runs" ] && echo recorded)" "yesrecorded" "control on the control: a run given an option the script does not know is recorded as such"
rm -f "$SB/bad-runs"
check "$(ls -A "$SB/web" | grep -c '^\.add_noncash98$\|^\.leak_cb98$\|^\.leak_cbx98$\|^\.left98$\|^\.flip_switch$\|^\.add_receipt97$\|^\.left97$\|^\.leak_bank97$\|^\.del_line97$\|^\.add_ledger$\|^\.add_receipt$\|^\.left96$')$(ls "$SB"/trap96* 2>/dev/null | grep -c .)" "00" "every trigger and trap the rehearsal planted was spent"
# The observers over every run of the 5.18.98 script, but those that trip one on purpose (recorded as they ran).
OBS_BAD=0; OBS_N=0
for rec in "$R98"/exec/run-*.rec; do
  t="${rec##*/run-}"; t="${t%.rec}"; OBS_N=$((OBS_N+1))
  grep -qx "$t" "$R98/expected-trips" && continue
  a="$(audit98 "$t")"; case "$a" in *" novault=0 plugin=0 "*) ;; *) OBS_BAD=$((OBS_BAD+1)) ;; esac
  [ "$(hits98 "$t")" = "0" ] || OBS_BAD=$((OBS_BAD+1))
  b="$(cat "$R98/vault/run-$t.before")"; [ "$b" = "$(cat "$R98/vault/run-$t.after")" ] || OBS_BAD=$((OBS_BAD+1))
done
check "$( [ "$OBS_N" -ge 40 ] && echo many)|$OBS_BAD|$(grep -c . "$R98/expected-trips")" "many|0|1" \
  "10 across every run of the 5.18.98 script — the deploys, the redeploys, the after-onlys, the checks, the rollbacks, the refusals and the weakened copies — the observers were quiet: no PHP process on a copy without a vault of its own, none running the tool or T1 from the plugin folder, nothing planted in /tmp, the live vault the same before and after each; the one copy that trips them on purpose aside (3d)"
check "$(vt_host)|$(grep -cxF "$W96" "$DATA/webhook_secret")" "$VT98_0|1" "…the live vault as it was before the first run, bytes and time; the planted webhook secret where it was planted"
sweep_files98() { ls -1 "$R98"/run-*.txt "$R98"/line-*.txt "$R98"/logs/*.log "$OUT98"/*.log "$OUT98"/state-5.18.98.env* "$OUT98"/rollback-state-* "$OUT98"/cih-rows-* 2>/dev/null; }
check "$( [ "$(sweep_files98 | grep -c .)" -ge 40 ] && echo many)|$( [ "$(ls "$R98"/sheets/cash-*.txt 2>/dev/null | grep -c .)" -ge 8 ] && echo sheets)|$(forms98; grep -c . "$SB/forms98.txt" | awk '{print ($1 >= 100 ? "forms" : "few:" $1)}')" "many|sheets|forms" \
  "the sweep has material: every run's terminal and log, the state files and row files; every run's cash sheet; a hundred and more printed forms of the book's figures, the statements' amounts and the planted row's"
check "$(figs98 $(sweep_files98))" "0" \
  "10a no figure of the book, of a kept statement or of the planted row — in any printed form — in any terminal, log, state file or row file of any run of the 5.18.98 script: only in the cash sheets"
check "$(sweep_files98 | xargs grep -lF -f "$SCAN96" 2>/dev/null | grep -c .)|$(cat "$SEC95" | grep -v '^$' > "$SB/sec98.pat"; sweep_files98 | xargs grep -lF -f "$SB/sec98.pat" 2>/dev/null | grep -c .)|$( [ "$(grep -c . "$SB/sec98.pat")" -ge 15 ] && [ "$(grep -c . "$SCAN96")" = "2" ] && echo listed)" "0|0|listed" \
  "10b no output, log or state file holds the planted webhook secret or vault credential, a repair key, a planted Splynx or pdf value, or one of 5.18.94's Splynx values"
check "$(bankhits98 $(sweep_files98))|$(for f in "$R98"/sheets/cash-*.txt; do grep -E '^    account #|^== ECO Bank — |^  both ECO BANK cards|^== N1|^== the copy checks' "$f"; done | grep -cF -f "$BANKPAT98")|$(for f in $(sweep_files98); do noids98 "$(cat "$f")"; done | awk '{s += $1} END {print s + 0}')" "0|0|0" \
  "10c no output, log or state file — and no ECO Bank, copy-check or N4 line of any sheet — names an account, an account number or ending, the statements' holder or counterparties, or a staff member; no row id printed but G3's and --check-bank's own"
printf 'planted %s, %s and %s; and #%s\n' "$(grep -m1 '^2:' "$SB/forms98.txt" | cut -c3-)" "$W96" "$ACCNAME98" "$BANKU98" > "$R98/zz-sweep-control.txt"
check "$(figs98 "$R98/zz-sweep-control.txt")$(grep -lF -f "$SCAN96" "$R98/zz-sweep-control.txt" | grep -c .)$(bankhits98 "$R98/zz-sweep-control.txt")$(noids98 "$(cat "$R98/zz-sweep-control.txt")")" "1111" \
  "control on the sweeps: a file holding one figure form, the planted secret, this rehearsal's account's name and a row id is found by all four"
rm -f "$R98/zz-sweep-control.txt" "$SB/sec98.pat"
rterr98() {   # rterr98 FILE… — how many of the files hold a bash runtime error of a script ("<x>.sh: line N: …")
  [ "$#" -gt 0 ] || { echo none; return; }
  grep -lE '\.sh: line [0-9]+: .*(bad substitution|unbound variable|syntax error|command not found)' "$@" 2>/dev/null | grep -c .
}
printf '%s\n' 'X=1; Y=2' 'echo "a:${X:+ the copy'"'"'s:$X}${Y:+ the base'"'"'s:$Y} end"' > "$SB/rterr98.sh"
bash "$SB/rterr98.sh" > "$R98/zz-rterr-control.txt" 2>&1
check "$(rterr98 $(sweep_files98))|$(rterr98 "$R98/zz-rterr-control.txt")" "0|1" \
  "10d no terminal or log of any run of the 5.18.98 script holds a bash runtime error — bad substitution, unbound variable, syntax error, command not found: a line that cannot run counts nothing (trial 1, 8a: RB's FAIL never counted); control: a script with an apostrophe inside \${…:+…}, as RB's line had, is caught"
rm -f "$SB/rterr98.sh" "$R98/zz-rterr-control.txt"
check "$(left_tmp98)$(host_code98)$(ls -d /tmp/dnb-backup-* /tmp/rh98-k.* 2>/dev/null | grep -c .)" "${TMP98}00" "no copy of the book or the code, no vault, no synthetic book and no backup copy is left in /tmp or on the host"
check "$(grep -c 'Fatal\|Parse error' "$SB/web.log")" "0" "the stand-in served every request without a PHP fatal"
check "$(checkout_state)" "$CHECKOUT0" "this checkout is as the rehearsal found it: same commit, same tracked files"

echo; echo "rehearsal: $PASS passed, $FAILN failed ($(cat "$SB/runs") runs of the deploy scripts, $(cat "$SB/qruns" 2>/dev/null || echo 0) of the quarantine)"
[ "$FAILN" = "0" ]
