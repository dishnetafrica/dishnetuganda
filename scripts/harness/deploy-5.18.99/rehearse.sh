#!/usr/bin/env bash
#
# Rehearse scripts/deploy-5.18.99.sh — Uganda's app customers and app quotes on one company: every new app customer filed
# under the quoting organization (1), and no uCRM quote made for a client filed under another organization
# (SPEC-quote-company §1–§6; root docs/07, 20 Oct) — over the live release with ECO Bank switched on, before the operator
# runs it.
#
# The sandbox is the previous release's rehearsal's, built by its own code: scripts/harness/deploy-5.18.98/rehearse.sh,
# read up to just before its own last section (what it left behind, its sweeps) — the cut asserted: its anchor exactly
# once, its last check the one named, and the one line that names its own folder pointed at that folder, also exactly
# once — and run here as it is, which builds every earlier sandbox the same way, from their own rehearsals. By the time
# this file's own sections begin, the previous release has been deployed, rolled back and deployed again with --redeploy
# there, ECO Bank's switch is ON in both copies, a statement is kept on each Ecobank card. Here its --after-only runs once
# more after its copy (R19 conclusive), then the quarantine's own --check: production's state on the day.
#
# On it, synthetic only: a stand-in uCRM (rh99-ucrm.php, on 127.0.0.1, never the network) holding two organizations, a
# few clients — every name, contact and key a marked secret of this rehearsal's (RH99SECRET-…, rh99-…) — and two quotes;
# the plugin's ucrm.json naming it (the local URL and the app key uCRM writes there); an admin token in the store row;
# the app's own records of uCRM clients — applications, leads, the quotes log, the legacy queue — added through the
# INSTALLED code's own writer (rh99-seed.php: SqliteStore::appendWithId); and every plant and weakened copy named below.
# Then this release's script, through a pseudo-terminal, its word typed, as the operator runs it, and every command its
# summary prints for a later step — the --after-only, the rollback — run as printed.
#
# The observers are the previous rehearsals', taught this release's folders (asserted, each with its control), and two
# more: at every docker call, how many token copies (token.json) lie in this release's folders in /tmp and which PHP mode
# runs (FAKE_TOK_HITS) — so that the token copy can be shown made before the probe and gone at the next call; and a
# trigger that plants a token.json in a run's code folder at Q-CA6's call, which Z2 must find (FAKE_Z2_PLANT). The fake
# docker learns one thing more: an argument "key=/data/…" (the quoting probe's ucrm=) is mapped as every /data/…
# argument is. The stand-in uCRM records every request — its method, its path, its query's keys, which key it carried —
# never a key's value; this rehearsal reads that record after every run.
#
# This rehearsal proves:
#   · the script is pinned to the release commit on release/5.18.99 (short and full), cut on the live release; the delta is
#     exactly the reviewed thirty-six files, its tree the development branch's (quote-99), each file's sha256 as the script
#     pins it, computed here from Git; the thirty-one kept files by theirs in both commits, the thirty changed ones by
#     their previous form; no migration; T1 and T4 pinned as before; the header's three commands, each alone; every later
#     command defined with its own log wrapper; the instruments — C5's digests, the judge's every term (the split and the
#     tab's buttons against the old code's among them, each line's weakened copy caught), the copy checks' comparison,
#     the GET-only wrapper (a weakened one lets a POST through: caught), the token copy as the probe makes it (0600, the
#     two keys alone, never printed), and the probe's refusal of a path outside /tmp (0);
#   · --check is GO on the live release as production has it: every quoting check holds — the tenant through the
#     installed code, the quoting organization through the release's, uCRM's organizations and the token client's (and
#     through crm_base_url, when set), read
#     with GETs only (the stand-in's record: nothing but GETs, as many as the script counts) — and the notes are this
#     rehearsal's own counts; the move-list on the sheet by id, never a name; nothing changed: the installed tree, the
#     store's bytes and rows, the vault, both settings files and ucrm.json as before; the token copy made and gone (c);
#   · --move-list-only PASSES with its sheet (0600, in a folder of 700), the move-list this rehearsal's own reading, ids
#     only, nothing changed; every refusal it has before it reads uCRM — Q-G1 on a kept file and on a file 5.18.99
#     changes, Q-G2, Q-G3's three, Q-G4's three — each with the weakened copy that turns it into a PASS (m);
#   · a deploy's quoting refusals: the quoting organization not in uCRM's list (DEPLOY typed: stops before the
#     question), the admin token refused and the token client's list without it, Q-G3's 0/off, Q-G5 and Q-G6 — each flag
#     recorded only where its check needed it, its refusal printing its own command alone, a flag typed where nothing
#     needed it said so and dropped from the command; a release that asks uCRM a POST refused by the sha256 this script
#     pins, then by the GET-only count — and with the wrapper weakened the POST reaches the stand-in (caught); every new
#     check's weakened copy caught (1);
#   · 5.18.98's carried checks still bite: ECO Bank's gate edited by hand (BR1, then Q-G1, then a copy without both
#     reaches the question), the switch cleared (G3), a write on a read path (C5, its judgement weakened: GO, caught), and
#     South Sudan's books read with the rule on (A24, weakened: GO, caught) (2);
#   · the deploy, DEPLOY typed, PASSES: every check of the release's and the carried ones, stage A's readings in the state
#     file, the sheet's move-list, GETs only, the token copy made twice and gone each time, nothing secret printed (4);
#   · --after-only as printed PASSES; each after-check bites — Q-CA1 a kept file, Q-CA2 and both its controls, Q-CA3 a
#     POST, a client moved between its discovery and its check (inside, and outside), the token client answering
#     otherwise, uCRM's list grown, the probe's lines in its code copy's logs, a line of this run's in the live plugin
#     log (a staff line there only a note), Q-CA4, Q-CA5, Q-CA6's profile, listing and refusal, Z2 a token copy left, V3j
#     the switch cleared — and each one's weakened copy is caught; its two clients on its sheet, by id; Q-N3's window the
#     deploy's; --check and --move-list-only refused with 5.18.99 live; --check-bank PASSES (5);
#   · the rollback as printed, ROLLBACK typed: PASSED — 5.18.98's files by sha256, the quoting code gone, ECO Bank, its
#     switch and every kept row unchanged; a --redeploy before the quarantine's --check refused at A14 (6);
#   · --redeploy after the rollback with all three quoting flags over a book where only Q-G5 needs its: PASSED, the state
#     file recording what each flag did, not that it was typed; its --after-only PASSES (7);
#   · a rollback whose copy leaves 5.18.99's debug route behind FAILS RB twice, and a copy blind to the quoting code
#     there FAILS once (caught); over rows 5.18.99 wrote, RB counts them, and with its Sync All gate, Uganda profile and
#     set_config left behind it FAILS naming all three (8);
#   · nothing reaches Evolution; every observer quiet but where a run trips one on purpose; no figure, secret, client id,
#     client name or contact, or uCRM key in any output, log, state file or request record, the sheets aside for ids;
#     no stand-in uCRM, token copy or code copy left; /tmp, the host and the checkout as found (10).
#
# Everything runs in a sandbox; it never touches this checkout, the server or the network, and refuses to run where the
# server could be, or while a stand-in uCRM of an earlier run of it is still up. Everything seeded is fictitious.
#
#   bash scripts/harness/deploy-5.18.99/rehearse.sh            REHEARSE_KEEP=<dir> keeps every run's full output
set -u
for f in /data/ucrm /opt/dishnet /var/run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
H99_DIR="$(cd "$(dirname "$0")" && pwd)"
H99_ROOT="$(cd "$H99_DIR/../../.." && pwd)"
F99=scripts/deploy-5.18.99.sh
# Before anything is built: the script must be pinned — to its release commit, short and full, and to T1's count.
P99_EARLY="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT="\([^"]*\)".*/\1/p' "$H99_ROOT/$F99" 2>/dev/null)"
P99F_EARLY="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT_FULL="\([^"]*\)".*/\1/p' "$H99_ROOT/$F99" 2>/dev/null)"
T1_EARLY99="$(sed -n 's/^T1_SIGNATURE="\([^"]*\)".*/\1/p' "$H99_ROOT/$F99" 2>/dev/null)"
case "$P99_EARLY" in __*|'') echo "refusing: $F99 is not pinned to a release commit (${P99_EARLY:-none})"; exit 2;; esac
case "$P99F_EARLY" in "$P99_EARLY"*) [ "${#P99F_EARLY}" = "40" ] || { echo "refusing: $F99 does not pin its release commit in full"; exit 2; } ;; *) echo "refusing: $F99's full pin does not begin with its short one"; exit 2;; esac
case "$T1_EARLY99" in [1-9]*' passed, 0 failed rc=0') ;; *) echo "refusing: $F99 does not pin T1's count (T1_SIGNATURE=${T1_EARLY99:-none})"; exit 2;; esac
for h in rh99-ucrm.php rh99-seed.php rh99-oracle.php; do
  [ -f "$H99_DIR/$h" ] || { echo "refusing: this rehearsal's helper $h is not beside it"; exit 2; }
done
# A stand-in uCRM is this rehearsal's own php -S, serving its own copy of rh99-ucrm.php from a folder named ucrm99 in its
# sandbox: that path in a process's command line is its marker. One whose parent is gone (ppid 1) was left by an earlier
# run that did not end cleanly; one with a parent is another run of this rehearsal, going on now. Either way: refused,
# never reused — its record and state would not be this run's. (The marker is built from two words, so that the search
# never finds itself.)
U99_SEEN="$(ps -eo pid=,ppid=,args= 2>/dev/null | awk -v a='/ucrm99/' -v b='rh99-ucrm.php' 'index($0, a b) { print $1 ":" $2 }')"
if [ -n "$U99_SEEN" ]; then
  if printf '%s\n' "$U99_SEEN" | grep -qE '^[0-9]+:1$'; then
    echo "refusing: a stand-in uCRM left by an earlier run of this rehearsal is still up (pid:ppid $(printf '%s' "$U99_SEEN" | paste -sd' ' -)) — its parent is gone; stop it (kill <pid>), then run this again"
  else echo "refusing: a stand-in uCRM of another run of this rehearsal is up (pid:ppid $(printf '%s' "$U99_SEEN" | paste -sd' ' -)) — let that run end first"; fi
  exit 2
fi
H98_HARNESS="$H99_DIR/../deploy-5.18.98/rehearse.sh"
H99_PREFIX="$(mktemp)"
python3 - "$H98_HARNESS" "$H99_PREFIX" <<'PY' || { echo "refusing: the previous release's rehearsal could not be cut before its last section (its sweeps)"; rm -f "$H99_PREFIX"; exit 2; }
import sys
src, dst = sys.argv[1], sys.argv[2]
anchor = 'echo; echo "== 98-10. what the rehearsal left behind, and what it never reached =="'
lines = open(src, encoding='utf-8').read().split('\n')
at = [i for i, l in enumerate(lines) if l == anchor]
assert len(at) == 1, len(at)
assert at[0] > 1300, at[0]                  # its deploy, rollback, --redeploy and the rollback that left a file behind come before it
pre = '\n'.join(lines[:at[0]]) + '\n'
# That rehearsal finds its own helpers beside itself ($0's folder): sourced from here, $0 is this file, so its one line
# naming that folder points at its own folder instead — the one edit, asserted.
old_dir = 'H98_DIR="$(cd "$(dirname "$0")" && pwd)"\n'
new_dir = 'H98_DIR="$(cd "$(dirname "$0")/../deploy-5.18.98" && pwd)"   # the next release\'s rehearsal: this one\'s own folder, where its helpers are\n'
assert pre.count(old_dir) == 1, pre.count(old_dir)
pre = pre.replace(old_dir, new_dir, 1)
assert pre.count(new_dir) == 1 and pre.count(old_dir) == 0
for need in ('H97_HARNESS="$H98_DIR/../deploy-5.18.97/rehearse.sh"', 'run98() {', 'run_line98() {', 'fresh98() {', 'nogo98() {',
             'went98() {', 'quiet98() {', 'checkgo98() {', 'trip98() {', 'audit98() {', 'hits98() {', 'vault98() {', 'quietobs98() {',
             'printed98() {', 'ctl98() {', 'copy_back98() {', 'release_variant98() {', 'pinned_copy98() {', 'installed98() {',
             'OUT98="$SB/out98"; mkdir -p "$OUT98"; STATE98="$OUT98/state-5.18.98.env"', 'R98="$SB/r98"', 'H98="$SB/rh98"',
             'o98() {', 'cash98() {', 'kv98() {', 'sub98() {', 'figs98() {', 'BANKPAT98="$SB/bank98.pat"', 'bankhits98() {',
             'noteids98() {', 'n1say98() {', 'g3say98() {', 'data98x() {', 'book98x() {', 'DATE_TS98=', 'LOGW98() {',
             'python3 - "$H97/rh97.py" "$H98/rh98.py" <<\'PY\'',
             'echo; echo "== 98-4. the deploy, as the operator runs it — DEPLOY typed; a non-cash row posted between the before-copy and the after-copy =="',
             'echo; echo "== 98-6. the rollback, run exactly as printed — ROLLBACK typed; ECO Bank and its switch unchanged; --redeploy too soon =="',
             'echo; echo "== 98-8. a rollback whose copy leaves 5.18.98\'s routes.php behind; deployed again with --redeploy, clean =="'):
    assert pre.count(need) >= 1, need
last = [l for l in pre.split('\n') if l.strip()][-1]
assert last.startswith('check "$(data94)|$(oj "$(o97)" offered)" "$D98F|'), last[:80]   # the cut follows its section 8's last check
open(dst, 'w', encoding='utf-8').write(pre)
PY
# shellcheck disable=SC1090
. "$H99_PREFIX"
rm -f "$H99_PREFIX"

# ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
# 5.18.99's own rehearsal, on the sandbox built above: 5.18.98 (ce7ac38) live, deployed again with --redeploy, ECO Bank
# switched on in both copies, a statement kept on each card.
# ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
REL99=release/5.18.99; DEPLOY99="$REPO/$F99"
DEV99=quote-99                                       # the change on the development branch
DEV99_TIP=d813d254                                   # …its tip: the five commits of 5.18.99 on the live release
ADDED99="lib/QuoteOrganization.php tests/fixtures/fake_ucrm_quote_org.php tests/fixtures/quote_company_journey.php tests/test_fake_smtp_relay.php tests/test_quote_company.php tests/test_quote_company_mutants.php"
CHANGED99="SAFETY.md cron_quote_wa.php includes/api/api_crm_misc.php includes/api/api_cron_debug.php includes/api/api_products_admin.php includes/post/post_kyc.php lib/KycCrmSync.php lib/KycService.php lib/PluginQuotePdf.php lib/QuotationService.php lib/QuotePdfSource.php lib/UcrmClientTarget.php lib/UcrmLeadSync.php manifest.json profiles/uganda.json tabs/lte/lte_dashboard.php tabs/sales/applications.php tabs/sales/kyc_form.php tests/fixtures/fake_smtp_server.php tests/fixtures/fake_ucrm_staff_jobs.php tests/test_bank_boundaries.php tests/test_cashbook_physical_list.php tests/test_distributor_apply.php tests/test_distributor_link_ucrm.php tests/test_distributor_notify.php tests/test_distributor_registry.php tests/test_distributor_territory.php tests/test_notify_kyc_quote_send.php tests/test_notify_quote_once.php tools/set_config.php"
PINS99="tests/test_distributor_apply.php tests/test_distributor_link_ucrm.php tests/test_distributor_notify.php tests/test_distributor_registry.php tests/test_distributor_territory.php"
N99=$(wc -w <<<"$ADDED99 $CHANGED99")               # 36
# SPEC §6 (Q-G1, Q-CA1): what 5.18.99 must leave byte for byte — ECO Bank's module, its links, the More menu, the styles,
# the Cashbook's post handler, ECO Bank's wiring, the cash-in-hand tool, 5.18.98's list split, and the files the
# quoting code reads unchanged.
KEPTF99="lib/BankGate.php lib/BankStatementStore.php lib/EcobankDetailsCsv.php tabs/accounts/bank.php includes/post/post_bank.php migrations/088_bank_statements.sql includes/navigation.php tabs/admin/ceo_dashboard.php tabs/sales/more_menu.php includes/styles.php includes/post/post_cashbook.php public.php includes/post_handlers.php tools/cash_in_hand.php lib/CashbookService.php tabs/accounts/cashbook.php tabs/accounts/cashbook_noncash.php includes/routes.php includes/cashbook_noncash_csv.php lib/CashbookNonCashView.php webhook.php api/index.php includes/post/post_leads.php lib/TenantProfile.php lib/QuoteTaxLine.php lib/CrmApiClient.php lib/PluginLog.php profiles/south-sudan.json tools/org_probe.php lib/ConfigVault.php lib/PluginConfig.php"
if git -C "$R" ls-files --error-unmatch "$F99" >/dev/null 2>&1; then
  cmp -s "$R/$F99" "$DEPLOY99" || { echo "refusing: $F99 differs from the committed one — commit it, then rehearse"; exit 2; }
  WHICH99="the committed script"
else cp "$R/$F99" "$DEPLOY99"; WHICH99="the working copy (not yet committed; untracked in the clone)"; fi
git -C "$REPO" fetch -q origin "refs/heads/$REL99:refs/remotes/origin/$REL99" 2>/dev/null || true
git -C "$REPO" fetch -q origin "refs/heads/$DEV99:refs/remotes/origin/$DEV99" 2>/dev/null || true
P99="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT="\([^"]*\)".*/\1/p' "$DEPLOY99")"
P99F="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT_FULL="\([^"]*\)".*/\1/p' "$DEPLOY99")"
T1SIG99="$(sed -n 's/^T1_SIGNATURE="\([^"]*\)".*/\1/p' "$DEPLOY99")"
T4SIG99="$(sed -n 's/^T4_SIGNATURE="\([^"]*\)".*/\1/p' "$DEPLOY99")"
MIG88_SHA99="$(sed -n 's/^MIG88_SHA256="\([0-9a-f]*\)".*/\1/p' "$DEPLOY99")"
OUT99="$SB/out99"; mkdir -p "$OUT99"; STATE99="$OUT99/state-5.18.99.env"
R99="$SB/r99"; mkdir -p "$R99/logs" "$R99/mutants" "$R99/sheets" "$R99/exec" "$R99/scan" "$R99/vault" "$R99/tok" "$R99/ucrm"   # the sweeps' material (10)
: > "$R99/expected-trips"; : > "$R99/expected-toks"; : > "$R99/expected-posts"   # the tags of the runs that trip an observer on purpose
H99="$SB/rh99"; mkdir -p "$H99/v"; cp "$H99_DIR"/rh99-oracle.php "$H99_DIR"/rh99-seed.php "$H99/"
chmod 644 "$H99"/rh99-*.php; chown 1000:1000 "$H99/v"; chmod 700 "$H99/v"   # the helpers' own folder for what the store's user writes
LOGW99() { printf '2>&1 | ( umask 077; exec tee %s/%s-%s.log )' "$OUT99" "$1" "$DATE_TS98"; }   # the script's LOGW, as it prints it
EXP_AFTER99="cd $REPO && bash scripts/deploy-5.18.99.sh --after-only $(LOGW99 after)"
EXP_ROLLBACK99="cd $REPO && bash scripts/deploy-5.18.99.sh --rollback $(LOGW99 rollback)"
EXP_DEPLOY99="cd $REPO && bash scripts/deploy-5.18.99.sh $(LOGW99 deploy)"
EXP_REDEPLOY99="cd $REPO && bash scripts/deploy-5.18.99.sh --redeploy $(LOGW99 redeploy)"
EXP_CHECKBANK99="cd $REPO && bash scripts/deploy-5.18.99.sh --check-bank $(LOGW99 check-bank)"
EXP_PUTBACK99="( cd $REPO && git checkout $P98 && { bash scripts/deploy-hybrid.sh; git checkout -; } ) $(LOGW99 putback)"
EXP_RUNBOOK99="cd $REPO && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.99 && mkdir -p $OUT99 && bash scripts/deploy-5.18.99.sh $(LOGW99 deploy)"
EXP_MOVELIST99="cd $REPO && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.99 && mkdir -p $OUT99 && bash scripts/deploy-5.18.99.sh --move-list-only $(LOGW99 movelist)"
runbook_with99() { printf 'cd %s && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.99 && mkdir -p %s && bash scripts/deploy-5.18.99.sh %s %s' "$REPO" "$OUT99" "$1" "$(LOGW99 deploy)"; }   # --check's deploy with FLAGS
with_flags99() { printf 'cd %s && bash scripts/deploy-5.18.99.sh %s %s' "$REPO" "$1" "$(LOGW99 "$2")"; }   # with_flags99 FLAGS MODE — a refusal's own command, as with_flags prints it

left_tmp99() { ls -d /tmp/dnb-5.18.99-* /tmp/dn-master-lock-* /tmp/dn_autopull_* 2>/dev/null | wc -l | tr -d ' '; }
TMP99="$(left_tmp99)"
host_code99() { ls -d "$OUT99"/code-* "$OUT99"/p3b-* "$OUT99"/br1-* "$OUT99"/domainb-* 2>/dev/null | wc -l | tr -d ' '; }
backups99() { ls -d "$OUT99"/backup-* 2>/dev/null | wc -l | tr -d ' '; }
states99() { ls -1 "$OUT99"/state-5.18.99.env* "$OUT99"/rollback-state-* 2>/dev/null | sort | while IFS= read -r f; do printf '%s %s\n' "${f##*/}" "$(sha256sum "$f" | cut -c1-16)"; done | sha256sum | cut -c1-16; }
fresh99() {   # the run folder as a new run finds it: the state files and the row digests kept; every log a printed command
              # wrote and every sheet moved aside for the sweeps (10)
  find "$OUT99" -mindepth 1 -maxdepth 1 -name '*.log' -exec mv -t "$R99/logs/" {} + 2>/dev/null
  find "$OUT99" -mindepth 1 -maxdepth 1 -name 'cash-*.txt' -exec mv -t "$R99/sheets/" {} + 2>/dev/null
  find "$OUT99" -mindepth 1 -maxdepth 1 ! -name 'state-5.18.99.env*' ! -name 'rollback-state-*' ! -name 'cih-rows-*' -exec rm -rf {} +; base_log; }
installed99() {   # installed99 COMMIT — the number of files 5.18.98 → 5.18.99 touches that are NOT installed as COMMIT has them
  local f rel bad=0
  for f in $(dl AM "$P98" "$P99"); do
    rel="${f#$P/}"
    if git -C "$REPO" cat-file -e "$1:$f" 2>/dev/null; then
      [ "$(git -C "$REPO" show "$1:$f" | sha256sum | cut -c1-64)" = "$(sha256sum "$PD/$rel" 2>/dev/null | cut -c1-64)" ] || bad=$((bad+1))
    fi
  done
  echo "$bad"
}
last99() { printf '%03d' "$(cat "$SB/runs")"; }   # the tag of the last run
run99() {   # [--answer WORD] [--script FILE] [--env K=V]… [--today YYYYMMDD], THEN the script's options — as run98, this
            # rehearsal's folders and observers, and the stand-in uCRM's record of the run kept beside it. R99_MUTANT_OUT=1
            # keeps a run's output out of the figure sweep
  local answer="" script="$DEPLOY99" extra=() today="$AFTER94" n tag o cmd
  while :; do case "${1:-}" in --answer) answer="$2"; shift 2 ;; --script) script="$2"; shift 2 ;; --env) extra+=("$2"); shift 2 ;;
    --today) today="$2"; shift 2 ;; *) break ;; esac; done
  : > "$SB/docker.log"; : > "$U99/requests.log"
  n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"; tag="$(printf '%03d' "$n")"
  vt_host > "$R99/vault/run-$tag.before"; : > "$R99/exec/run-$tag.rec"; : > "$R99/scan/run-$tag.hits"; : > "$R99/tok/run-$tag.tok"
  cmd="$(printf '%q ' env PATH="$SB/datebin:$SB/bin:$PATH" FAKE_TODAY="$today" DNB_OUT="$OUT99" DNB_RECORDS="$RECS" DNB_WEBSITE="$WEB_OK" GUARD_SECONDS=3 DN_DATA_DIR="$DATA" \
         FAKE_EXEC_LOG="$R99/exec/run-$tag.rec" FAKE_TMP_SCAN="$SCAN96" FAKE_TMP_HITS="$R99/scan/run-$tag.hits" FAKE_TOK_HITS="$R99/tok/run-$tag.tok" \
         ${extra[@]+"${extra[@]}"} bash "$script" --plugin-base "$PLUGIN_BASE" "$@")"
  o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r')"
  vt_host > "$R99/vault/run-$tag.after"; cp "$U99/requests.log" "$R99/ucrm/run-$tag.req"
  case "$o" in *"unknown option:"*) echo "run99-$n" >> "$SB/bad-runs" ;; esac
  if [ -n "${R99_MUTANT_OUT:-}" ]; then printf '%s\n' "$o" > "$R99/mutants/run-$tag.txt"; else printf '%s\n' "$o" > "$R99/run-$tag.txt"; fi
  if [ -n "${REHEARSE_KEEP:-}" ]; then
    printf '%s\n' "$o" > "$REHEARSE_KEEP/run99-$tag-${answer:-none}-$(basename "$script" .sh)$(printf '%s' "$*" | tr -c 'A-Za-z0-9-' '_').txt"
    cp "$U99/requests.log" "$REHEARSE_KEEP/run99-$tag.ucrm-requests.txt"
  fi
  printf '%s' "$o"
}
run_line99() {   # run_line99 WORD LINE — a command line exactly as a summary printed it, through a pseudo-terminal, WORD typed
  local answer="$1" line="$2" n tag o cmd
  : > "$SB/docker.log"; : > "$U99/requests.log"
  n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"; tag="$(printf '%03d' "$n")"
  vt_host > "$R99/vault/run-$tag.before"; : > "$R99/exec/run-$tag.rec"; : > "$R99/scan/run-$tag.hits"; : > "$R99/tok/run-$tag.tok"
  cmd="$(printf '%q ' env PATH="$SB/datebin:$SB/bin:$PATH" FAKE_TODAY="$AFTER94" DNB_OUT="$OUT99" DNB_RECORDS="$RECS" DNB_WEBSITE="$WEB_OK" GUARD_SECONDS=3 DN_DATA_DIR="$DATA" PLUGIN_BASE="$PLUGIN_BASE" \
         FAKE_EXEC_LOG="$R99/exec/run-$tag.rec" FAKE_TMP_SCAN="$SCAN96" FAKE_TMP_HITS="$R99/scan/run-$tag.hits" FAKE_TOK_HITS="$R99/tok/run-$tag.tok" bash -c "$line")"
  o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r')"
  vt_host > "$R99/vault/run-$tag.after"; cp "$U99/requests.log" "$R99/ucrm/run-$tag.req"
  case "$o" in *"unknown option:"*) echo "line99-$n" >> "$SB/bad-runs" ;; esac
  printf '%s\n' "$o" > "$R99/line-$tag.txt"
  if [ -n "${REHEARSE_KEEP:-}" ]; then printf '%s\n' "$o" > "$REHEARSE_KEEP/line99-$tag-${answer:-none}.txt"; cp "$U99/requests.log" "$REHEARSE_KEEP/line99-$tag.ucrm-requests.txt"; fi
  printf '%s' "$o"
}
termfile99() { if [ -f "$R99/run-$1.txt" ]; then echo "$R99/run-$1.txt"; elif [ -f "$R99/line-$1.txt" ]; then echo "$R99/line-$1.txt"; else echo "$R99/mutants/run-$1.txt"; fi; }
went99() { echo "$(has "$1" 'GO — evidence recorded')$(has "$1" 'Type DEPLOY')"; }   # the run reached the question
quiet99() { echo "$(live)|$(backups99)|$(data94)|$(kept_state)|$(oldname)"; }      # nothing deployed, backed up or changed
nogo99() {   # nogo99 LABEL EXPECTED-TEXT OUTPUT [DATA-DIGEST] [LIVE] — refused with that text and "Nothing was changed", never the
             # question, nothing deployed, backed up or changed
  check "$(has "$3" "$2")$(has "$3" 'Nothing was changed. Send the log file.')$(went99 "$3")|$(quiet99)" "yesyesnono|${5:-$P98}|0|${4:-$D99}|$KEPT99|0" "$1"
}
checkgo99() {   # checkgo99 OUTPUT — --check said GO: stage A held — nothing asked, nothing backed up, no state written
  echo "$(has "$1" "  5.18.99 (check): GO — stage A holds.")$(has "$1" '  --check           stage A alone, every refusal and note of a deploy')$(went99 "$1")$(fails "$1")|$(backups99)|$(states99)"
}
mlpass99() {   # mlpass99 OUTPUT — --move-list-only PASSED: no failure, never the question, nothing backed up, no state written
  echo "$(fails "$1")$(has "$1" '  5.18.99 (move-list): PASSED. Send this LOG FILE back (not a copy of the terminal, and never the sheet).')$(went99 "$1")|$(backups99)|$(states99)"
}
trip99() { printf '%s\n' "$(last99)" >> "$R99/expected-trips"; }   # the last run trips the copy observers on purpose (10)
audit99() { python3 "$H99/rh99.py" audit "$R99/exec/run-$1.rec"; }  # audit99 TAG — copies=<n> novault=<n> plugin=<n> adj=<id|->
hits99() { local n; n="$(grep -c . "$R99/scan/run-$1.hits" 2>/dev/null)"; echo "${n:-0}"; }
vault99() { echo "$(cat "$R99/vault/run-$1.before")>$(cat "$R99/vault/run-$1.after")"; }
quietobs99() { echo "$(hits99 "$1")|$(audit99 "$1" | sed -E 's/^copies=[0-9]+ //; s/ adj=.*//')|$(vault99 "$1")"; }   # the copy observers on run TAG
ureq99() { python3 "$H99/rh99.py" u99req "$R99/ucrm/run-$1.req"; }       # ureq99 TAG — the stand-in uCRM's record of run TAG, counted
uother99() { python3 "$H99/rh99.py" u99other "$R99/ucrm/run-$1.req"; }   # …the requests in it that were not a GET, in order
tok99() { python3 "$H99/rh99.py" tok99 "$R99/tok/run-$1.tok"; }          # tok99 TAG — remote=<n> copied=<n> left=<n>
qget99() { sed -n 's/^  uCRM              asked \([0-9]*\) GET(s) by this run, through its GET-only wrappers, and nothing else (\([0-9]*\) other)$/\1:\2/p' <<<"$1" | tail -1; }
qgetml99() { sed -n 's/^                    uCRM was asked \([0-9]*\) GET(s) and nothing else; every copy is gone$/\1/p' <<<"$1" | tail -1; }
mlsheet_of() { sed -n 's/^  the sheet         \(\/[^ ]*\) — [0-9]* lines, sha256 [0-9a-f]*$/\1/p' <<<"$1" | tail -1; }   # F's sheet line of a --move-list-only run
printed99() {   # printed99 OUTPUT — the summary's commands: how many | how many without their own log wrapper | how many lines of
                # the whole output hold two commands — by their command forms, never by a name in words
  local s; s="$(sed -n '/^== F\. Summary/,$p' <<<"$1")"
  echo "$(grep -cE '^      (cd |\( )' <<<"$s")|$(grep -E '^      (cd |\( )' <<<"$s" | grep -vcE ' \| \( umask 077; exec tee | \| tee ')|$(grep -cE '(bash scripts/deploy-5\.18\.99\.sh|bash scripts/quarantine-stray-store\.sh).*(bash scripts/deploy-5\.18\.99\.sh|bash scripts/quarantine-stray-store\.sh)' <<<"$1")"
}
rb_where99() {   # rb_where99 OUTPUT — each line naming the rollback command: before or after the run's verdict
  awk '/^  5\.18\.99 \((deploy|after)\): / { v = 1 } index($0, "deploy-5.18.99.sh --rollback") { print (v ? "after" : "before") }' <<<"$1" | paste -sd, -
}
order99() {   # order99 OUTPUT — the verdict, --check-bank's block, the rollback and the put-back, in order
  RH_OUT="$1" RH_P="$P98" python3 - <<'PY'
import os, re
t = os.environ['RH_OUT'].split('\n'); p = os.environ['RH_P']
pats = [('verdict', re.compile(r'^  5\.18\.99 \((deploy|after)\): PASSED')),
        ('check-bank', re.compile(r'^      cd .*deploy-5\.18\.99\.sh --check-bank ')),
        ('rollback', re.compile(r'^      cd .*deploy-5\.18\.99\.sh --rollback ')),
        ('putback', re.compile(r'^      \( cd .* && git checkout ' + re.escape(p) + ' '))]
seen = sorted((i, n) for i, l in enumerate(t) for n, rx in pats if rx.search(l))
names = [n for _, n in seen]
print('<'.join(names) if len(set(names)) == len(names) else 'repeated:' + ','.join(names))
PY
}
hdr99() {   # hdr99 SCRIPT — the header's command block: the move-list run with its own log | the deploy with its own | anything
            # that is not one of the three | the --after-only with its own | how many command lines it holds. Each of the
            # three read as a WHOLE line, so that a line that carries one of them and something more is not that command
  local h; h="$(sed -n '/^# Days before the deploy/,/^# The rollback is a separate command/p' "$1")"
  echo "$(grep -cxF '#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.99 && mkdir -p /root/dnb-5.18.99 && bash scripts/deploy-5.18.99.sh --move-list-only 2>&1 | ( umask 077; exec tee /root/dnb-5.18.99/movelist-$(date -u +%Y%m%dT%H%M%SZ).log )' <<<"$h")$(grep -cxF '#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.99 && mkdir -p /root/dnb-5.18.99 && bash scripts/deploy-5.18.99.sh 2>&1 | ( umask 077; exec tee /root/dnb-5.18.99/deploy-$(date -u +%Y%m%dT%H%M%SZ).log )' <<<"$h")$(grep -cE -- '--rollback|--put-back|--redeploy|--check-bank|--without-bank-balance|--quote-company-off|--quote-create-unproven|--lead-org-differs|set_config|git checkout [0-9a-f]{7}' <<<"$h")$(grep -cxF '#   cd /opt/dishnet && bash scripts/deploy-5.18.99.sh --after-only 2>&1 | ( umask 077; exec tee /root/dnb-5.18.99/after-$(date -u +%Y%m%dT%H%M%SZ).log )' <<<"$h")|$(grep -cE '^#   cd ' <<<"$h")"
}
# The nine commands the script prints for a later step, as it must define them — each with its own log wrapper; the
# quarantine's --check with its own tee. Read as whole lines from a quoted block, never expanded here.
DEFS99="$SB/defs99.txt"
cat > "$DEFS99" <<'DEFS'
AFTER_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --after-only $(LOGW after)"
ROLLBACK_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --rollback $(LOGW rollback)"
DEPLOY_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh $(LOGW deploy)"
PUTBACK_CMD="( cd $REPO && git checkout $BASELINE_COMMIT && { bash scripts/deploy-hybrid.sh; git checkout -; } ) $(LOGW putback)"
QCHECK_CMD="( umask 077 && cd $REPO && bash scripts/quarantine-stray-store.sh --check 2>&1 | tee $Q_DIR/check-\$(date -u +%Y%m%dT%H%M%SZ).log )"   # the quarantine's own form (root docs/68)
REDEPLOY_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --redeploy $(LOGW redeploy)"
CHECKBANK_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --check-bank $(LOGW check-bank)"
RUNBOOK_CMD="cd $REPO && git pull origin $BRANCH && git fetch origin $RELEASE_BRANCH && mkdir -p $OUT && bash scripts/deploy-$EXPECTED_VERSION.sh $(LOGW deploy)"
MOVELIST_CMD="cd $REPO && git pull origin $BRANCH && git fetch origin $RELEASE_BRANCH && mkdir -p $OUT && bash scripts/deploy-$EXPECTED_VERSION.sh --move-list-only $(LOGW movelist)"
DEFS
cmds99() {   # cmds99 SCRIPT — each of the nine definitions once (1 each) | the lines that print a command with printf | with echo |
             # how many of those name one of the nine (or --check's own) | how many print with_flags | how many carry the
             # wrapper themselves | with_flags' own line | the move-list's printf | --check's two forms | switch commands left
  local f="$1" l d=""
  while IFS= read -r l; do d="$d$(grep -cxF -- "$l" "$f")"; done < "$DEFS99"
  printf '%s|%s|%s|%s|%s|%s|%s|%s|%s|%s\n' "$d" "$(grep -cF "printf '\n      %s'" "$f")" "$(grep -cF 'echo "      $' "$f")" \
    "$(grep -cE "(printf '\\\\n      %s' \"\\\$|echo \"      \\\$)(AFTER_CMD|ROLLBACK_CMD|DEPLOY_CMD|QCHECK_CMD|PUTBACK_CMD|REDEPLOY_CMD|CHECKBANK_CMD|RUNBOOK_CMD|MOVELIST_CMD|F_RUN_CMD)\"" "$f")" \
    "$(grep -cF "printf '\n      %s' \"\$(with_flags " "$f")" "$(grep -F "printf '\n      %s'" "$f" | grep -cF '$(LOGW ')" \
    "$(grep -cF "printf 'cd %s && bash scripts/deploy-%s.sh%s %s' \"\$REPO\" \"\$EXPECTED_VERSION\" \"\$f\" \"\$(LOGW \"\$w\")\"" "$f")" \
    "$(grep -cxF "    printf '      %s\n' \"\$MOVELIST_CMD\"" "$f")" "$(grep -cF 'then F_RUN_CMD="$RUNBOOK_CMD"' "$f")$(grep -F 'else F_RUN_CMD="cd $REPO && git pull origin $BRANCH' "$f" | grep -cF '$(LOGW "')" \
    "$(grep -cE 'SWITCH_ON_CMD|SWITCH_CLEAR_CMD' "$f")"
}
ctl99() {   # ctl99 OUTPUT A22|R23 — the earlier readers in this release's words: "kept by 5.18.99"
  local out="$1" n=0 x
  for x in a b c d e f g; do
    case "$2" in
      A22) grep -qxF -- "$(printf '  %-15s the pin: %s   ·   %s (%s, the control): %s' "fix A22$x" ok "$P94" 5.18.94 "${CTL95[$x]}")" <<<"$out" \
             && [ "$(okline "$out" "A22$x" "— in the pin, kept by 5.18.99; 5.18.94's copy, the control, fails the same check (${CTL95[$x]})")" = yes ] && n=$((n+1)) ;;
      R23) [ "$(okline "$out" "R23$x" 'installed, kept by 5.18.99:' "— 5.18.94's copy, the control, fails the same check (${CTL95[$x]})")" = yes ] && n=$((n+1)) ;;
    esac
  done
  echo "$n"
}
copy_back99() {   # the 5.18.99 copy N minutes ago: lib/KycService.php's time (its stamp_copy, COPY_MARK), the recorded copy
                  # time, the start before it
  touch -d "-$1 minutes" "$PD/lib/KycService.php"; local t; t="$(stat -c %Y "$PD/lib/KycService.php")"
  sed -i -e "s/^COPY_AT=.*/COPY_AT=$t/" -e "s/^DEPLOYED_AT=.*/DEPLOYED_AT=$(date -u -d "@$(( t - 60 ))" +%Y-%m-%dT%H:%M:%SZ)/" "$STATE99"; }
release_variant99() {   # release_variant99 PATH OLD NEW — the release commit with one file edited (OLD exactly once), cut on 5.18.98
  local idx="$SB/variant99.idx" blob tree c
  GIT_INDEX_FILE="$idx" git -C "$REPO" read-tree "$P99"
  blob="$(git -C "$REPO" show "$P99:$P/$1" | python3 -c 'import sys
s = sys.stdin.read(); old, new = sys.argv[1], sys.argv[2]
assert s.count(old) == 1, "variant anchor not unique"
sys.stdout.write(s.replace(old, new))' "$2" "$3" | git -C "$REPO" hash-object -w --stdin)"
  [ "$blob" = "$(printf '' | git -C "$REPO" hash-object --stdin)" ] && echo "release_variant99:$1" >> "$SB/anchor-missing"
  GIT_INDEX_FILE="$idx" git -C "$REPO" update-index --cacheinfo "100644,$blob,$P/$1"
  tree="$(GIT_INDEX_FILE="$idx" git -C "$REPO" write-tree)"; rm -f "$idx"
  c="$(git -C "$REPO" -c user.name=rehearsal -c user.email=rehearsal@example.test commit-tree "$tree" -p "$(git -C "$REPO" rev-parse "$P98")" -m "rehearsal variant: $1")"
  git -C "$REPO" rev-parse --short "$c"
}
pinned_copy99() {   # pinned_copy99 NAME COMMIT SRC [FILE SHA256] — a copy of SRC pinned to another commit, short AND full; with
                    # FILE, that file's line of PIN_SHA256S re-pinned too. Each anchor exactly once, asserted
  local full; full="$(git -C "$REPO" rev-parse "$2")"
  rm -f "$REPO/scripts/.$1.sh"
  python3 - "$3" "$REPO/scripts/.$1.sh" "$2" "$full" "${4:-}" "${5:-}" <<'PY' || echo "pinned_copy99:$1" >> "$SB/anchor-missing"
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
# ── Every anchor this rehearsal's weakened copies, release variants and planted edits replace, with what replaces it,
# in one table: name, the file it is found in (deploy: the script; qo, cb, sc: the release's QuoteOrganization,
# CashbookService and tools/set_config.php; qt, ss, crm: the live release's QuoteTaxLine, South Sudan profile and
# CrmApiClient), the anchor
# (exactly once in that file — asserted wherever it is used, and recorded as missing otherwise), and its replacement.
# Tab-separated, read as written: no line of it is ever expanded. ──────────────────────────────────────────────────────
ANCH99="$SB/anchors99.tsv"
cat > "$ANCH99" <<'ANCH'
qg1kept	deploy	|| G1_BAD="$G1_BAD $g1_rel"	|| true
qg2	deploy	|| stop "NO-GO (Q-G2): this server is not the Uganda tenant	|| true || stop "NO-GO (Q-G2): this server is not the Uganda tenant
qg3dis	deploy	[ "$s3" = "$r3" ] || stop "NO-GO (Q-G3): the configuration files and the store row	true || stop "NO-GO (Q-G3): the configuration files and the store row
qg3inv	deploy	    *:invalid) stop "NO-GO (Q-G3): ucrm_quote_organization_id is set on this server, and INVALID	    *:invalid) true || stop "NO-GO (Q-G3): ucrm_quote_organization_id is set on this server, and INVALID
qg3off	deploy	      if [ -n "$QOFF" ]; then QOFF_NOTED=1	      if true; then QOFF_NOTED=1
qg4has	deploy	    [ "$has" = "yes" ] || stop "NO-GO (Q-G4): uCRM lists organization(s)	    true || stop "NO-GO (Q-G4): uCRM lists organization(s)
qg4cur	deploy	      *) stop "NO-GO (Q-G4): the quoting organization #$qorg is a $qcur company in uCRM	      *) true || stop "NO-GO (Q-G4): the quoting organization #$qorg is a $qcur company in uCRM
qg4read	deploy	    *) stop "NO-GO (Q-G4): uCRM's organizations could not be read through the plugin's own client, GET-only	    *) true || stop "NO-GO (Q-G4): uCRM's organizations could not be read through the plugin's own client, GET-only
qg4get	deploy	  [ "$QO_OTHER" = "0" ] && [ -z "$QO_COUNT_BAD" ] || stop "NO-GO (Q-G4): the probe asked uCRM something other than a GET	  true || stop "NO-GO (Q-G4): the probe asked uCRM something other than a GET
qg4b1	deploy	      case "$tok" in read:*) ;; *) stop "NO-GO (Q-G4b): token client: ${tok#failed:}	      case "$tok" in read:*) ;; *) true || stop "NO-GO (Q-G4b): token client: ${tok#failed:}
qg4b2	deploy	      [ -z "$qorg" ] || [ "${tok##*:}" = "yes" ] || stop "NO-GO (Q-G4b): token client: organizations read, and	      true || stop "NO-GO (Q-G4b): token client: organizations read, and
qg5	deploy	    else stop "NO-GO (Q-G5): of $n5 Lead or Manual quote(s)	    else true || stop "NO-GO (Q-G5): of $n5 Lead or Manual quote(s)
qg6	deploy	    else stop "NO-GO (Q-G6): ucrm_lead_organization_id names organization	    else true || stop "NO-GO (Q-G6): ucrm_lead_organization_id names organization
qn2sheet	deploy	                foreach ($outside as [$id, $n]) $S[] = "    client #" . $id . "  " . ($n > 0 ? "organization #" . $n : "no organization in uCRM");	                foreach ([] as [$id, $n]) $S[] = "    client #" . $id . "  " . ($n > 0 ? "organization #" . $n : "no organization in uCRM");
qn3	deploy	  q="$(kv "$QO_R" qn3)"; QN3_A="${q%%:*}"	  q="$(kv "$QO_R" qn3)"; QN3_A="${q##*:}"
qn4other	deploy	            if ($m > 0 && $po > 0 && $po !== $m) $kc["otherorg"]++;	            if (false) $kc["otherorg"]++;
tokunlink	deploy	            $t = json_decode((string)@file_get_contents($tf), true); @unlink($tf);	            $t = json_decode((string)@file_get_contents($tf), true);
wrappost	deploy	    public function post(string $p, array $x = []) { $this->no("POST"); }	    public function post(string $p, array $x = []) { return $this->c->post($p, $x); }
qca1kept	deploy	|| CA1_BAD="$CA1_BAD $ca1_rel(not-$BASELINE_VERSION's)"	|| true
qca2main	deploy	    elif [ "$(kv "$QO_G2" tenant)" = "uganda/uganda" ] && [ "$s2" = "$QG3_A" ] && [ "$r2" = "$QG3_A" ]; then ok "Q-CA2 the INSTALLED	    elif true; then ok "Q-CA2 the INSTALLED
qca2c1	deploy	    if [ "$(kv "$QO_C1" setting)" = "off:-:explicit-off:-/off:-:explicit-off:-" ]; then ok "Q-CA2 control: the same copy with ucrm_quote_organization_id=0	    if true; then ok "Q-CA2 control: the same copy with ucrm_quote_organization_id=0
qca3orgs	deploy	        elif [ "$t" = "$QG4_A" ]; then ok "Q-CA3 uCRM's organizations, through the INSTALLED	        elif true; then ok "Q-CA3 uCRM's organizations, through the INSTALLED
qca3in	deploy	        *) bad "Q-CA3 checkClient() on a client stage A read in the quoting organization	        *) ok "Q-CA3 checkClient() on a client stage A read in the quoting organization
qca3log	deploy	    if [ "$QO_LOGHIT" != "0" ]; then bad "Q-CA3 the live data/plugin.log gained $QO_LOGHIT line(s) labelled this run's (deploy-check)	    if false; then bad "Q-CA3 the live data/plugin.log gained $QO_LOGHIT line(s) labelled this run's (deploy-check)
qca3cp	deploy	    if [ "$QO_CPROBE" = "0:1 0:1" ]; then ok "Q-CA3 the probe's refusal lines reached neither	    if true; then ok "Q-CA3 the probe's refusal lines reached neither
qca3nosink	deploy	$r = (new QuoteOrganization($c, $setting, $sink))->checkClient($id, "deploy-check");	$r = (new QuoteOrganization($c, $setting, null))->checkClient($id, "deploy-check");
qca3noctl	deploy	$ctl = (new QuoteOrganization($plug, ["on" => true, "id" => null, "source" => "setting", "error" => "invalid"], null))->checkClient(0, "deploy-check-control");	$ctl = ["ok" => false, "code" => "R6"];
qca3out	deploy	        *) bad "Q-CA3 checkClient() on a client outside the quoting organization → $out	        *) ok "Q-CA3 checkClient() on a client outside the quoting organization → $out
qca3tok	deploy	        token=set*) if [ "$(kv "$QO_R" tin)" = "$in" ] && [ "$(kv "$QO_R" tout)" = "$out" ]; then ok "Q-CA3 the same two checks through the token client	        token=set*) if true; then ok "Q-CA3 the same two checks through the token client
qca2c0	deploy	    if [ "$(kv "$QO_C0" tenant)" = "south-sudan/south-sudan" ] && [ "$(kv "$QO_C0" setting)" = "off:-:off:-/off:-:off:-" ]; then ok "Q-CA2 control: the same copy with tenant_profile=south-sudan	    if true; then ok "Q-CA2 control: the same copy with tenant_profile=south-sudan
qca4	deploy	        *) bad "Q-CA4 Sync All is not as $EXPECTED_VERSION makes it:	        *) ok "Q-CA4 Sync All is not as $EXPECTED_VERSION makes it:
qca5	deploy	        [ "$qca5v" = "302|login|00" ] || QCA5_R="$QCA5_R ${qca5%%:*}=$qca5v;"	        true || QCA5_R="$QCA5_R ${qca5%%:*}=$qca5v;"
qca6	deploy	    [ "$o" = "uganda=1 south-sudan=NULL" ] && ok "Q-CA6 the installed profiles	    true && ok "Q-CA6 the installed profiles
qca6list	deploy	    grep -qF "'ucrm_quote_organization_id' => ['text'," "$DEST/tools/set_config.php" 2>/dev/null	    true
qca6ref	deploy	      if [ "$rc" = "rc=1" ] && grep -qF '"disabled" is not an organization number or 0/off, so nothing was saved.' <<<"$o" && [ "$t" = "0" ]; then ok "Q-CA6 the installed tools/set_config.php, from the copy	      if true; then ok "Q-CA6 the installed tools/set_config.php, from the copy
qg1base	deploy	      stop "NO-GO (Q-G1): the container's record says	      true || stop "NO-GO (Q-G1): the container's record says
qg1chg	deploy	|| G1_BAD="$G1_BAD $g1_rel(not-$BASELINE_VERSION's)"	|| true
z2tok	deploy	 -o -iname "*vault*.json" -o -name "token.json" -o -name "plugin.sqlite3*"	 -o -iname "*vault*.json" -o -name "plugin.sqlite3*"
rbqo	deploy	  grep -qF 'QuoteOrganization' "$DEST/$rbq" 2>/dev/null && RB_QO="$RB_QO $rbq:QuoteOrganization"	  true
a0stray	deploy	[ -z "$STRAY" ] || stop	true || stop
a0ps	deploy	[ -z "$PS_BAD" ] || stop	true || stop
a0ks	deploy	[ -z "$KS_BAD" ] || stop	true || stop
a0pc	deploy	[ -z "$PC_BAD" ] || stop	true || stop
a22t1	deploy	  [ "$T1_A" = "$T1_SIGNATURE" ] || {	  true || {
a24	deploy	  [ -z "$A24_BAD" ] || stop "NO-GO (A24, South Sudan)	  true || stop "NO-GO (A24, South Sudan)
c5j	deploy	  [ "$(kv "$l" c5)" = "same" ] || w="$w C5:a-digest-moved-under-the-new-code's-reads"	  true
br1	deploy	  [ -z "$BR1_MISS" ] || stop "NO-GO (BR1)	  true || stop "NO-GO (BR1)
qg1g1	deploy	  [ -z "$G1_BAD" ] || stop "NO-GO (Q-G1): the installed plugin is not	  true || stop "NO-GO (Q-G1): the installed plugin is not
hdrdeploy	deploy	#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.99 && mkdir -p /root/dnb-5.18.99 && bash scripts/deploy-5.18.99.sh 2>&1 | ( umask 077; exec tee /root/dnb-5.18.99/deploy-$(date -u +%Y%m%dT%H%M%SZ).log )	#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.99 && mkdir -p /root/dnb-5.18.99 && bash scripts/deploy-5.18.99.sh 2>&1 | ( umask 077; exec tee /root/dnb-5.18.99/deploy-$(date -u +%Y%m%dT%H%M%SZ).log ) && bash scripts/deploy-5.18.99.sh --rollback
runbookdef	deploy	RUNBOOK_CMD="cd $REPO && git pull origin $BRANCH && git fetch origin $RELEASE_BRANCH && mkdir -p $OUT && bash scripts/deploy-$EXPECTED_VERSION.sh $(LOGW deploy)"	RUNBOOK_CMD="cd $REPO && git pull origin $BRANCH && git fetch origin $RELEASE_BRANCH && mkdir -p $OUT && bash scripts/deploy-$EXPECTED_VERSION.sh"
qoorgs	qo	        $rows = $this->crm->get('organizations');	        try { $this->crm->post('organizations', ['rh99' => 1]); } catch (\Throwable $rh99) { } $rows = $this->crm->get('organizations');   // RH99: a request that is not a GET, planted before the read
qooff	qo	    private const OFF_WORDS   = ['0', 'off', 'no', 'none', 'false'];	    private const OFF_WORDS   = ['off', 'no', 'none', 'false'];   // RH99: 0 no longer switches the gate off
qotenant	qo	            if ($profile->id() !== 'uganda') return $off;	            if (false) return $off;   // RH99: the tenant no longer read
scqlist	sc	    'ucrm_quote_organization_id' => ['text',	    'ucrm_quote_organization_id' => ['text' ,
scqoff	sc	    if (!in_array($new, ['0', 'off', 'no', 'none', 'false'], true)) {	    if (!in_array($new, ['0', 'off', 'no', 'none', 'false', 'disabled'], true)) {   // RH99: "disabled" taken for off
cbwrite	cb	            $cashKinds = implode(', ', array_map([$this->pdo(), 'quote'], self::CASH_IN_HAND_KINDS));	            $this->pdo()->exec("UPDATE cb_accounts SET updated_at = updated_at || '.' WHERE id = (SELECT MIN(id) FROM cb_accounts)"); $cashKinds = implode(', ', array_map([$this->pdo(), 'quote'], self::CASH_IN_HAND_KINDS));   // RH99: a write planted on a read path
cbgate	cb	                $this->cashRuleCache = StaffJobsGate::applies($cfg, $this->dataDir) && !dn_ssp_selectable($cfg);	                $this->cashRuleCache = true;   // RH99: rule A on for every tenant, South Sudan's books among them
qtax	qt	->id() === 'uganda';	->id() === 'rh99-not-uganda';
ssquote	ss	  "id": "south-sudan",	  "id": "south-sudan", "quoting": {"ucrm_organization_id": 1},
crmbyte	crm	class CrmApiClient	class CrmApiClient   // RH99: one comment more, by hand
ANCH
a99() { awk -F'\t' -v k="$1" '$1 == k { print $3; exit }' "$ANCH99"; }   # a99 NAME — the anchor
r99() { awk -F'\t' -v k="$1" '$1 == k { print $4; exit }' "$ANCH99"; }   # r99 NAME — what replaces it
weak99() {   # weak99 NAME SRC KEY… — a copy of SRC with each KEY's anchor replaced by its weakened form, each exactly once
  local name="$1" src="$2" k a=(); shift 2
  for k in "$@"; do a+=("$(a99 "$k")" "$(r99 "$k")"); done
  mutant "$name" "$src" "${a[@]}"
}
sha16() { if [ -f "$1" ]; then sha256sum "$1" | cut -c1-16; else echo absent; fi; }
tree99() { ( cd "$PD" && find . -path ./data -prune -o -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum ) | sha256sum | cut -c1-16; }
fp99() {   # what --check and --move-list-only must leave as they found it: the installed tree (data/ aside), the store's bytes
           # (after a checkpoint, so that a later one changes none of them) and every row, the vault (bytes and time), both
           # settings files, the stray folder's settings file, ucrm.json and the record of the live commit
  sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1
  echo "tree=$(tree99) db=$(sha16 "$DATA/plugin.sqlite3") rows=$(data94) vault=$(vt_host) kyc=$(sha16 "$DATA/kyc_config.json") config=$(sha16 "$DATA/config.json") stray=$(sha16 "$PD/data/config.json") ucrm=$(sha16 "$PD/ucrm.json") commit=$(live)"
}
rows_add99() {   # rows_add99 JSON — the app's records of uCRM clients added through the INSTALLED code's own writer
                 # (SqliteStore::appendWithId), as the store's user, with a vault of its own; {"rows":N}, or none(<why>)
  local o; o="$(cd "$H99/v" && setpriv --reuid 1000 --regid 1000 --clear-groups -- env DN_VAULT_FILE="$H99/v/seed-vault.json" "$PHPBIN" "$H99/rh99-seed.php" "$PD" "$DATA" "$1" 2>"$H99/v/seed.err")"
  rm -f "$H99/v/seed-vault.json"
  case "$o" in '{"rows":'*) printf '%s' "$o" ;; *) echo "none($(head -c 120 "$H99/v/seed.err"))" ;; esac
}
o99() {   # o99 [QORG] — this rehearsal's own reading of the quoting checks' counts on the live store, read-only (JSON, in a file of
          # its own; the client ids beside it, in the store user's folder): prints the JSON file's path
  local s; s="o99-$(date +%s%N)"
  php "$H99/rh99-oracle.php" "$DATA/plugin.sqlite3" "${1:-1}" "$H99/v/$s.ids" > "$H99/$s.json" 2>/dev/null
  printf '%s' "$H99/$s.json"
}
oids99() { printf '%s' "$H99/v/$(basename "$1" .json).ids"; }        # oids99 O99-FILE — the client ids of that reading, newest first
qx99() { python3 "$H99/rh99.py" qn2exp "$(oids99 "$1")" "$U99/state.json" "${2:-1}"; }   # what Q-N2 must read, from that reading and the stand-in's state
qj() { python3 -c 'import json, sys; print(json.loads(sys.argv[1])[sys.argv[2]])' "$1" "$2"; }   # qj JSON KEY
q6say99() { if [ "$1" = "-" ]; then echo "no lte_subscribers table"; else echo "${1%%:*}, ${1#*:} of them with a uCRM link"; fi; }   # Q-N6's count in the note's words
q99delta() {   # q99delta NOW BEFORE — the difference of two oracle counts, "n:l" or "pm:N,plans:N,hw:N", field by field
  python3 -c 'import sys
a, b = sys.argv[1], sys.argv[2]
if a.startswith("pm:") and b.startswith("pm:"):
    pa = dict(x.split(":") for x in a.split(",")); pb = dict(x.split(":") for x in b.split(","))
    print(",".join("%s:%d" % (k, int(pa[k]) - int(pb.get(k, 0))) for k in pa))
elif a.count(":") == 1 and b.count(":") == 1:
    print("%d:%d" % tuple(int(x) - int(y) for x, y in zip(a.split(":"), b.split(":"))))
else:
    print("none(%s|%s)" % (a, b))' "$1" "$2" 2>&1; }
cash99() { local f="$H99/cash-$1.json"; oracle96 > "$f"; printf '%s' "$f"; }   # the cash cells as the earlier rehearsal reads them
forms99() { python3 "$H99/rh99.py" forms "$SB/forms99.txt" "$SB/seed96.amounts" "$SB/seed97.amounts" "$SB/stmt97.amounts" "$SB/seed98.amounts" "$H97"/cash-*.json "$H98"/cash-*.json "$H99"/cash-*.json "$R97"/sheets/cash-*.txt "$R98"/sheets/cash-*.txt "$R99"/sheets/cash-*.txt "$OUT97"/cash-*.txt "$OUT98"/cash-*.txt "$OUT99"/cash-*.txt >/dev/null 2>&1; }
figs99() { forms99; python3 "$H99/rh99.py" hits "$SB/forms99.txt" "$@"; }   # figs99 FILE… — how many hold a figure of the book, in any printed form
secrets99() { local n=0 f; for f in "$@"; do [ -f "$f" ] && grep -qF -f "$U99_SECRETS" -- "$f" && n=$((n+1)); done; echo "$n"; }   # how many hold a stand-in secret
noids99() { local n=0 f; for f in "$@"; do [ -f "$f" ] && grep -qwF -f "$SB/ids99.pat" -- "$f" && n=$((n+1)); done; echo "$n"; }    # how many name a seeded client id

# ── This rehearsal's readers: the previous release's, derived by asserted replacements — the audit taught this release's
# folders and the quoting checks' code copies (g-i, g-p, g-c0, g-c1, l, u) — and readers of the stand-in uCRM's record,
# the token observer's, Q-N2's move-list and Q-G4's lines added. ─────────────────────────────────────────────────────────
python3 - "$H98/rh98.py" "$H99/rh99.py" <<'PY' || { echo "readers:rh99" >> "$SB/anchor-missing"; echo "refusing: this rehearsal's readers could not be derived from the previous release's — every reader would print nothing, and every check reading one would compare nothing with nothing"; exit 2; }
import sys
s = open(sys.argv[1]).read()
reps = [
    ("# 5.18.98's rehearsal (the previous release's readers, by asserted replacements): readers of what the runs leave",
     "# 5.18.99's rehearsal (the previous release's readers, by asserted replacements): readers of what the runs leave"),
    ("root = re.compile(r'^/tmp/dnb-5\\.18\\.98-(?:cih-[^/]+/[^/]+/(?:t|d|p0root|k-(?:found|off|on)|c-(?:old|new))/dishnet-hybrid-sudan|code-[^/]+/p3b/[^/]+/dishnet-hybrid-sudan)(?:/|$)')",
     "root = re.compile(r'^/tmp/dnb-5\\.18\\.99-(?:cih-[^/]+/[^/]+/(?:t|d|p0root|k-(?:found|off|on)|c-(?:old|new)|g-(?:i|p|c0|c1)|l|u)/dishnet-hybrid-sudan|code-[^/]+/p3b/[^/]+/dishnet-hybrid-sudan)(?:/|$)')"),
    ("if not env.get('DN_VAULT_FILE', '').startswith('/tmp/dnb-5.18.98-'): novault += 1",
     "if not env.get('DN_VAULT_FILE', '').startswith('/tmp/dnb-5.18.99-'): novault += 1"),
]
for old, new in reps:
    assert s.count(old) == 1, old[:70]
    s = s.replace(old, new)
assert s.count('5.18.98') == 0 and s.count('5\\.18\\.98') == 0, 'a path of the previous release is left'
add = '''elif cmd == 'u99req':                    # the stand-in uCRM's record of one run: requests, GETs, others, by key, and odd shapes
    lines = [l for l in read(sys.argv[2]).split('\\n') if l.strip()]
    kinds = [l.rsplit(' ', 1)[-1] for l in lines]
    other = [l for l in lines if not l.startswith('GET ')]
    shape = re.compile(r'^GET (/organizations|/clients/[0-9]+|/clients\\?limit|/billing/quotes\\?createdDateFrom) (app-key|token)$')
    odd = sum(1 for l in lines if l.startswith('GET ') and not shape.match(l))
    print('total=%d get=%d other=%d app=%d token=%d none=%d odd=%d' % (len(lines), len(lines) - len(other), len(other), kinds.count('app-key'), kinds.count('token'), kinds.count('none'), odd))
elif cmd == 'u99other':                  # the requests of one run that were not a GET: method, path and key, in order
    out = [l for l in read(sys.argv[2]).split('\\n') if l.strip() and not l.startswith('GET ')]
    print(';'.join(out) if out else '-')
elif cmd == 'tok99':                     # the token observer's record of one run: at each remote probe, a token copy there; at the next call, gone?
    rows = [l.split(' ', 1) for l in read(sys.argv[2]).split('\\n') if l.strip()]
    remote = copied = left = 0
    for i, r in enumerate(rows):
        if len(r) == 2 and r[1] == 'remote':
            remote += 1
            if r[0] != '0': copied += 1
            if i + 1 < len(rows) and rows[i + 1][0] != '0': left += 1
    print('remote=%d copied=%d left=%d' % (remote, copied, left))
elif cmd == 'qn2exp':                    # what Q-N2 must read, from the client ids (newest first) and the stand-in's state
    ids = [int(x) for x in read(sys.argv[2]).split() if x.strip()]
    st = json.loads(read(sys.argv[3])); m = int(sys.argv[4]); cl = st.get('clients', {})
    by = {}; nf = 0; outside = []; pin = pout = porg = 0; disc = 0
    for k, i in enumerate(ids):
        c = cl.get(str(i))
        if not isinstance(c, dict): nf += 1; continue
        n = c.get('org'); n = n if isinstance(n, int) else 0
        by[n] = by.get(n, 0) + 1
        if m > 0 and n == m:
            if pin == 0: pin = i
        elif m > 0:
            outside.append((i, n))
            if pout == 0 and n > 0: pout, porg = i, n
        if disc == 0 and pin > 0 and pout > 0: disc = k + 1
    bs = [('org%d' % x if x > 0 else 'none') + ':' + str(by[x]) for x in sorted(by)]
    qn2 = 'read:%d/%d,' % (len(ids), len(ids)) + (','.join(bs) + ',' if bs else '') + 'notfound:%d,unreadable:0,unread:0' % nf
    print(json.dumps({'qn2': qn2, 'outside': len(outside), 'sheet': ';'.join('%d:%d' % o for o in outside) or '-',
                      'in': pin, 'out': pout, 'outorg': porg, 'n': len(ids), 'disc': disc}))
elif cmd == 'qn2say':                    # Q-N2's counts in the note's words, as the script's qn2_say writes them
    parts = []
    for f in sys.argv[2].split(','):
        k, _, v = f.partition(':')
        if re.match(r'^org[0-9]+$', k): parts.append('org #%s: %s' % (k[3:], v))
        elif k == 'none': parts.append('no organization: %s' % v)
        elif k == 'notfound': parts.append('not found: %s' % v)
        elif k == 'unreadable': parts.append('unreadable: %s' % v)
        elif k == 'unread' and v.isdigit() and int(v) > 0: parts.append('not read within the time budget: %s' % v)
    print(' \\u00b7 '.join(parts) if parts else 'none read')
elif cmd == 'mlsheet':                   # the move-list lines of a sheet, in order: id:organization (0: none in uCRM)
    out = []
    for l in read(sys.argv[2]).split('\\n'):
        m2 = re.match(r'^    client #([0-9]+)  (?:organization #([0-9]+)|no organization in uCRM)$', l)
        if m2: out.append('%s:%s' % (m2.group(1), m2.group(2) or '0'))
    print(';'.join(out) if out else '-')
elif cmd == 'qg4lines':                  # Q-G4's organization lines on a terminal, in order
    p = '  Q-G4            organization #'
    out = [l[len('  Q-G4            '):] for l in read(sys.argv[2]).split('\\n') if l.startswith(p)]
    print(';'.join(out) if out else '-')
elif cmd == 'qg4exp':                    # the same from the stand-in's state: "organization #<id>[ [selected]] <CUR> <name>"
    st = json.loads(read(sys.argv[2])); out = []
    for o in st.get('orgs', []):
        cc = re.sub(r'[^A-Za-z]', '', str(o.get('currencyCode') or '')).upper()
        out.append('organization #%d%s %s %s' % (int(o['id']), ' [selected]' if o.get('selected') else '', cc if cc else '(no currency given)', str(o.get('name', '')).strip()))
    print(';'.join(out) if out else '-')
'''
assert add.count('5.18.98') == 0
s += add
assert s.count('5.18.98') == 0 and s.count('5\\.18\\.98') == 0
open(sys.argv[2], 'w').write(s)
PY
cp "$H99_DIR"/rh99-ucrm.php "$SB/rh99-ucrm.check.php"
check "$(python3 -c 'import ast, sys; ast.parse(open(sys.argv[1]).read()); print("parses")' "$H99/rh99.py" 2>&1)$(for f in "$H99"/rh99-*.php "$SB/rh99-ucrm.check.php"; do php -l "$f" 2>&1 | grep -c '^No syntax errors'; done | tr -d '\n')|$(grep -c '5\.18\.98' "$H99/rh99.py")" "parses111|0" \
  "control: this rehearsal's readers (the previous release's, derived, naming none of its paths) and its three PHP helpers parse"
rm -f "$SB/rh99-ucrm.check.php"

echo; echo "== 99-pre. the sandbox as production is: 5.18.98 live, its --after-only and the quarantine's --check passed after its copy =="
copy_back98 30; COPY98="$(sed -n 's/^COPY_AT=//p' "$STATE98" | tail -1)"
set_sched $(( COPY98 + 300 )); base_log
fresh98; OUT="$(run98 --after-only)"
check "$(fails "$OUT")$(has "$OUT" '5.18.98 (after): PASSED')$(okline "$OUT" R19 'the quarantine holds: master ran the retry job at' "after the copy (at $(utc "$COPY98"))")|$(live)|$(inst_ver)|$(both_copies "$BK97")" \
      "0yesyes|$P98|5.18.98|11" \
  "pre-a 5.18.98 ($P98) is live, its --after-only PASSES once master has run the retry job after its copy (R19 conclusive), ECO Bank's switch ON in both copies — production's state before this release"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "pre-b the quarantine's own --check, as the runbook orders after 5.18.98's --after-only: PASSED, begun after 5.18.98's copy"
QREC99="$(sha256sum "$QREC" | cut -c1-16)"; KEPT99="$(kept_state)"
[ -e "$RECS/dnb-5.18.98" ] || ln -s "$OUT98" "$RECS/dnb-5.18.98"
check "$(readlink -f "$RECS/dnb-5.18.98")|$(readlink -f "$RECS/dnb-5.18.97")|$(readlink -f "$RECS/dnb-5.18.96")|$(readlink -f "$RECS/dnb-5.18.95")|$(readlink -f "$RECS/dnb-5.18.94")" \
      "$(readlink -f "$OUT98")|$(readlink -f "$OUT97")|$(readlink -f "$OUT96")|$(readlink -f "$OUT95")|$(readlink -f "$OUT94")" \
  "pre-c 5.18.98's, 5.18.97's, 5.18.96's, 5.18.95's and 5.18.94's records where 5.18.99's A14 looks for them (<records>/dnb-5.18.9x), as on the server"

# ── The stand-in uCRM: rh99-ucrm.php, served by php -S on 127.0.0.1 from a folder of its own (ucrm99, its marker),
# answering the four reads the quoting checks make and recording every request. Its state — two organizations, seven
# clients (one id the app holds is not among them: Q-N2's "not found"), two quotes, the plugin's app key and an admin
# token — is written here; every name, contact and key in it is a secret of this rehearsal's (RH99SECRET-…, rh99-…),
# listed in a 0600 file for the sweeps and never printed. ───────────────────────────────────────────────────────────────
U99="$SB/ucrm99"; mkdir -p "$U99"; cp "$H99_DIR/rh99-ucrm.php" "$U99/"; chmod 644 "$U99/rh99-ucrm.php"
U99_SECRETS="$SB/ucrm99.secrets"
python3 - "$U99/base.json" "$U99_SECRETS" "$SB/ids99.pat" <<'PY' || echo "ucrm99:base" >> "$SB/anchor-missing"
import sys, json, secrets, os
base, sec, idf = sys.argv[1], sys.argv[2], sys.argv[3]
h = lambda: secrets.token_hex(6)
plan = [(71930451, 1), (71930452, 2), (71930453, 1), (71930454, 2), (71930455, 1), (71930456, None), (71930458, 2)]
clients = {}; S = []
for i, (cid, org) in enumerate(plan, 1):
    c = {'org': org, 'first': 'RH99SECRET-F%d-%s' % (i, h()), 'last': 'RH99SECRET-L%d-%s' % (i, h()),
         'phone': 'RH99SECRET-PHONE-%d-%s' % (i, h()), 'email': 'rh99secret-%d-%s@example.invalid' % (i, h())}
    clients[str(cid)] = c; S += [c['first'], c['last'], c['phone'], c['email']]
st = {'app_key': 'rh99-appkey-' + secrets.token_hex(16), 'token': 'rh99-token-' + secrets.token_hex(16),
      'orgs': [{'id': 1, 'name': 'RH99 Org One', 'currencyCode': 'UGX', 'selected': False},
               {'id': 2, 'name': 'RH99 Org Two', 'currencyCode': 'UGX', 'selected': True}],
      'clients': clients, 'quotes': [88017401, 88017402]}
S += [st['app_key'], st['token']]
open(base, 'w').write(json.dumps(st)); os.chmod(base, 0o600)
for p, text in ((sec, '\n'.join(S) + '\n'), (idf, '\n'.join(str(c) for c in [71930451, 71930452, 71930453, 71930454, 71930455, 71930456, 71930457, 71930458]) + '\n')):
    fd = os.open(p, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600); os.write(fd, text.encode()); os.close(fd)
PY
APPKEY99="$(python3 -c 'import json, sys; print(json.load(open(sys.argv[1]))["app_key"])' "$U99/base.json")"
TOKEN99="$(python3 -c 'import json, sys; print(json.load(open(sys.argv[1]))["token"])' "$U99/base.json")"
u99_set() {   # u99_set [KEY=JSON]… — the stand-in's state: its base with each KEY set to that JSON value, written atomically; the
              # memory of its flip toggle cleared
  rm -f "$U99"/flip-seen-*
  python3 - "$U99/base.json" "$U99/state.json" "$@" <<'PY' || echo "ucrm99:state" >> "$SB/anchor-missing"
import sys, json, os
src, dst = sys.argv[1], sys.argv[2]
s = json.load(open(src))
for kv in sys.argv[3:]:
    k, v = kv.split('=', 1)
    s[k] = json.loads(v)
t = dst + '.tmp'
open(t, 'w').write(json.dumps(s)); os.chmod(t, 0o644); os.replace(t, dst)
PY
}
ORGS99_MISSING='[{"id":2,"name":"RH99 Org Two","currencyCode":"UGX","selected":true},{"id":3,"name":"RH99 Org Three","currencyCode":"UGX","selected":false}]'
ORGS99_USD='[{"id":1,"name":"RH99 Org One","currencyCode":"USD","selected":false},{"id":2,"name":"RH99 Org Two","currencyCode":"UGX","selected":true}]'
ORGS99_GROWN='[{"id":1,"name":"RH99 Org One","currencyCode":"UGX","selected":false},{"id":2,"name":"RH99 Org Two","currencyCode":"UGX","selected":true},{"id":3,"name":"RH99 Org Three","currencyCode":"UGX","selected":false}]'
u99_set; : > "$U99/requests.log"
UPORT99="$(free_port)"; U99_URL="http://127.0.0.1:$UPORT99/crm"
"$PHPBIN" -S "127.0.0.1:$UPORT99" "$U99/rh99-ucrm.php" > "$U99/server.log" 2>&1 & U99_PID=$!; PIDS+=("$U99_PID")
cleanup99() { [ -n "${U99_PID:-}" ] && kill "$U99_PID" 2>/dev/null; pkill -f "$U99/rh99-ucrm.php" 2>/dev/null; rm -rf /tmp/rh99-k.* 2>/dev/null; cleanup; }
trap cleanup99 EXIT
for i in $(seq 1 100); do [ "$(curl -s --noproxy '*' "http://127.0.0.1:$UPORT99/__rh99/ping" 2>/dev/null)" = "rh99-ucrm-ready" ] && break; sleep 0.1; done
ug99() { curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "$@"; }
UC1="$(ug99 -H "X-Auth-App-Key: $APPKEY99" "$U99_URL/api/v2.1/organizations")"
UC2="$(ug99 -H "X-Auth-App-Key: rh99-wrong-key" "$U99_URL/api/v2.1/organizations")"
UC3="$(ug99 -X POST -H "X-Auth-App-Key: $APPKEY99" "$U99_URL/api/v2.1/clients")"
UC4="$(ug99 -H "x-auth-token: $TOKEN99" "$U99_URL/api/v2.1/clients/71930457")"
UC5="$(ug99 -H "x-auth-token: $TOKEN99" "$U99_URL/api/v2.1/clients/71930451")"
check "$UC1|$UC2|$UC3|$UC4|$UC5|$(python3 "$H99/rh99.py" u99req "$U99/requests.log")|$(python3 "$H99/rh99.py" u99other "$U99/requests.log")|$(secrets99 "$U99/requests.log")" \
      "200|401|404|404|200|total=5 get=4 other=1 app=3 token=2 none=0 odd=0|POST /clients app-key|0" \
  "control: the stand-in uCRM answers its key and the token, refuses another key, answers a POST 404 and an unknown client 404 — and records each request, a POST among them, by method, path and which key it carried, never a key's value"
: > "$U99/requests.log"
# ── The plugin's ucrm.json names it, as uCRM writes ucrm.json for a plugin: its local URL and the plugin's app key, beside
# the public URL the sandbox had (and still no pluginDataDir). Asserted; the control is the installed CrmApiClient's own
# reading of it, and one GET through it, recorded. ───────────────────────────────────────────────────────────────────────
python3 - "$PD/ucrm.json" "$U99_URL" "$APPKEY99" <<'PY' || echo "ucrm99:ucrm.json" >> "$SB/anchor-missing"
import sys, json
p, url, key = sys.argv[1:4]
u = json.load(open(p))
assert 'ucrmLocalUrl' not in u and 'pluginAppKey' not in u and 'pluginDataDir' not in u and 'ucrmPublicUrl' in u, sorted(u)
u['ucrmLocalUrl'] = url; u['pluginAppKey'] = key
open(p, 'w').write(json.dumps(u))
PY
check "$(php -r 'require $argv[1] . "/lib/CrmApiClient.php"; $c = CrmApiClient::fromUcrm($argv[1], []); echo $c->getBaseUrl(), "|", $c->getAuthHeader(), "|", count((array)$c->get("organizations"));' "$PD" 2>&1)|$(python3 "$H99/rh99.py" u99req "$U99/requests.log")" \
      "$U99_URL/api/v2.1|X-Auth-App-Key|2|total=1 get=1 other=0 app=1 token=0 none=0 odd=0" \
  "control: the installed CrmApiClient::fromUcrm() on the plugin folder reads the stand-in's URL and the app key from ucrm.json, and its GET of the organizations reaches the stand-in, recorded"
: > "$U99/requests.log"
# ── The fake docker learns one argument form: "key=/data/…", which the quoting probe passes as ucrm=<the plugin folder>
# — mapped as every /data/… argument is; an argument whose key is not lower case, or whose value only contains /data/
# later, is left as it is. Asserted, with a control. ──────────────────────────────────────────────────────────────────
python3 - "$SB/bin/docker" "$MOUNT" <<'PY' || echo "fakedocker:keyvalue99" >> "$SB/anchor-missing"
import sys
p, mount = sys.argv[1], sys.argv[2]
s = open(p).read()
old = ' *) args+=("$a") ;; esac; done\n'
assert s.count(old) == 1, s.count(old)
assert s.count("a key=/data/") == 0
new = (' *) if [[ "$a" =~ ^[a-z_]+=/data/ ]]; then args+=("${a%%=*}=' + mount + '/${a#*=/data/}"); else args+=("$a"); fi ;; esac; done'
       "   # the next release's rehearsal: a key=/data/... argument (the quoting probe's ucrm=) mapped as every /data/... argument is\n")
s = s.replace(old, new, 1)
assert s.count(old) == 0 and s.count("a key=/data/") == 1
open(p, 'w').write(s)
PY
check "$(env PATH="$SB/bin:$PATH" bash "$SB/bin/docker" exec ucrm php -r 'echo implode("|", array_slice($argv, 1));' "ucrm=$IN_CONTAINER" "label=a=/data/x" "Ucrm=/data/y" /data/ucrm 2>&1)" \
      "ucrm=$PD|label=a=/data/x|Ucrm=/data/y|$MOUNT/ucrm" \
  "control: the fake docker maps ucrm=/data/… to the sandbox's folder, as it maps a bare /data/… argument — and leaves a value that only contains /data/ later, and a key that is not lower case, as they are"
# ── The observers search this release's folders in /tmp too (the one list, extended, asserted); and two more, each on only
# when a run's environment asks for it: the token observer (FAKE_TOK_HITS) and Z2's plant (FAKE_Z2_PLANT). ───────────────
python3 - "$SB/bin/docker" <<'PY' || echo "fakedocker:observers99" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]; s = open(p).read()
old = ' /tmp/dnb-5.18.98-code-*/t4run; do\n'
assert s.count(old) == 1, s.count(old)
assert s.count('/tmp/dnb-5.18.99-') == 0
s = s.replace(old, ' /tmp/dnb-5.18.98-code-*/t4run /tmp/dnb-5.18.99-cih-* /tmp/dnb-5.18.99-ss-* /tmp/dnb-5.18.99-code-*/p3b /tmp/dnb-5.18.99-code-*/t1run /tmp/dnb-5.18.99-code-*/t4run; do\n', 1)
assert s.count('/tmp/dnb-5.18.99-') == 5 and s.count(old) == 0
open(p, 'w').write(s)
PY
cat > "$SB/docker99.hook" <<'HOOK'
# ── the next release's rehearsal: two more observers, each on only when the run's environment asks for it ──
# FAKE_TOK_HITS: at every call, how many token copies (token.json) lie in this release's cash and quoting folders in /tmp,
# and the PHP mode the call runs (the word after its -r source) — the rehearsal reads the record after the run.
if [ -n "${FAKE_TOK_HITS:-}" ]; then
  _rh_k=0; _rh_m=-
  for _rh_a in "$@"; do
    case "$_rh_k" in 2) _rh_m="$(printf '%s' "$_rh_a" | tr -cd 'a-z' | cut -c1-12)"; break ;; 1) _rh_k=2 ;; esac
    [ "$_rh_a" = "-r" ] && [ "$_rh_k" = 0 ] && _rh_k=1
  done
  printf '%s %s\n' "$(find /tmp/dnb-5.18.99-cih-* -name token.json 2>/dev/null | grep -c .)" "${_rh_m:--}" >> "$FAKE_TOK_HITS"
fi
# FAKE_Z2_PLANT: at the first call that runs tools/set_config.php from a code copy of this release (Q-CA6's), a file named
# token.json planted in that run's code folder — what Z2 must find — once: the trigger file is removed.
if [ -n "${FAKE_Z2_PLANT:-}" ] && [ -f "$FAKE_Z2_PLANT" ]; then
  case "$*" in *tools/set_config.php*)
    for _rh_a in "$@"; do case "$_rh_a" in /tmp/dnb-5.18.99-code-*/p3b/*)
      _rh_c="${_rh_a%%/p3b/*}"; mkdir -p "$_rh_c/rh99-planted" && printf '{}\n' > "$_rh_c/rh99-planted/token.json" && rm -f "$FAKE_Z2_PLANT"; break ;; esac; done ;;
  esac
fi
HOOK
python3 - "$SB/bin/docker" "$SB/docker99.hook" <<'PY' || echo "fakedocker:hook99" >> "$SB/anchor-missing"
import sys
p, hook = sys.argv[1], sys.argv[2]
s = open(p).read(); h = open(hook).read()
lines = s.split('\n')
at = [i for i, l in enumerate(lines) if l.startswith('row() { grep "^$1|" ')]
assert len(at) == 1, len(at)
assert s.count('FAKE_TOK_HITS') == 0 and s.count('FAKE_Z2_PLANT') == 0
lines[at[0]:at[0]] = h.rstrip('\n').split('\n')
s2 = '\n'.join(lines)
assert s2.count('FAKE_TOK_HITS') == 3 and s2.count('FAKE_Z2_PLANT') == 4 and s2.count('/tmp/dnb-5.18.99-') == 7
open(p, 'w').write(s2)
PY
rm -f "$SB/docker99.hook"
check "$(bash -n "$SB/bin/docker" && echo parses)" "parses" "control: the fake docker, with this release's edits, parses"
mkdir -p /tmp/dnb-5.18.99-cih-rhcontrol/qo-a-1/tok; printf '{"crm_auth_token":"x"}\n' > /tmp/dnb-5.18.99-cih-rhcontrol/qo-a-1/tok/token.json
printf 'rh99-observer-control\n' > /tmp/dnb-5.18.99-cih-rhcontrol/webhook_secret; printf 'rh99-observer-control\n' > "$SB/obs99.pat"
env PATH="$SB/bin:$PATH" FAKE_TOK_HITS="$SB/obs99.tok" FAKE_EXEC_LOG="$SB/obs99.rec" FAKE_TMP_SCAN="$SB/obs99.pat" FAKE_TMP_HITS="$SB/obs99.hits" bash "$SB/bin/docker" exec -u 1000:1000 -e DN_DATA_DIR=/tmp/dnb-5.18.99-cih-x/qo-a-1/g-i-data ucrm php -r 'echo 1;' gate /tmp/dnb-5.18.99-cih-x/qo-a-1/g-i/dishnet-hybrid-sudan >/dev/null 2>&1
env PATH="$SB/bin:$PATH" FAKE_TOK_HITS="$SB/obs99.tok" bash "$SB/bin/docker" exec ucrm php -r 'echo 1;' remote /tmp/x >/dev/null 2>&1
rm -f /tmp/dnb-5.18.99-cih-rhcontrol/qo-a-1/tok/token.json
env PATH="$SB/bin:$PATH" FAKE_TOK_HITS="$SB/obs99.tok" bash "$SB/bin/docker" exec ucrm php -r 'echo 1;' token /tmp/x >/dev/null 2>&1
printf '1 remote\n1 rm\n' > "$SB/obs99b.tok"
check "$(paste -sd, "$SB/obs99.tok")|$(python3 "$H99/rh99.py" tok99 "$SB/obs99.tok")|$(python3 "$H99/rh99.py" tok99 "$SB/obs99b.tok")|$(grep -c '^value /tmp/dnb-5.18.99-cih-rhcontrol/webhook_secret$' "$SB/obs99.hits")$(grep -c '^file /tmp/dnb-5.18.99-cih-rhcontrol/webhook_secret$' "$SB/obs99.hits")|$(python3 "$H99/rh99.py" audit "$SB/obs99.rec")" \
      "1 gate,1 remote,0 token|remote=1 copied=1 left=0|remote=1 copied=1 left=1|11|copies=1 novault=1 plugin=0 adj=-" \
  "control: the observers see this release's folders — the token observer counts a token copy at each call and names the PHP mode (a copy there at the remote probe, gone at the next call; and, written out, one still there at the next call: left=1), a planted value and a file named webhook_secret, and a quoting gate process on its code copy (g-i) with no vault of its own"
rm -rf /tmp/dnb-5.18.99-cih-rhcontrol "$SB/obs99.pat" "$SB/obs99.rec" "$SB/obs99.hits" "$SB/obs99.tok" "$SB/obs99b.tok"
# …and the audit counts what runs code on this release's quoting copies, each with a vault of its own or without: the
# remote probe on its u/ copy, the local reading on its l/ copy, a control gate on c1/, one gate without a vault (counted
# as such) — and neither the settings copies (QOP: the live folder's path, no code root) nor a 5.18.98 path.
{ for c in \
    "exec|-u|1000:1000|-e|DN_VAULT_FILE=/tmp/dnb-5.18.99-cih-x/qo-a-1/u-vault-none.json|-e|DN_DATA_DIR=/tmp/dnb-5.18.99-cih-x/qo-a-1/u-none|ucrm|php|-d|display_errors=stderr|-r|echo 1;|remote|/tmp/dnb-5.18.99-cih-x/qo-a-1/u/dishnet-hybrid-sudan|ucrm=/data/ucrm/data/plugins/dishnet-hybrid-sudan" \
    "exec|-u|1000:1000|-e|DN_VAULT_FILE=/tmp/dnb-5.18.99-cih-x/qo-a-1/l-vault.json|-e|DN_DATA_DIR=/tmp/dnb-5.18.99-cih-x/qo-a-1/l-data|ucrm|php|-d|display_errors=stderr|-r|echo 1;|local|/tmp/dnb-5.18.99-cih-x/qo-a-1/l/dishnet-hybrid-sudan|data=/tmp/dnb-5.18.99-cih-x/qo-a-1/l-data" \
    "exec|-u|1000:1000|-e|DN_VAULT_FILE=/tmp/dnb-5.18.99-cih-x/qo-r-2/g-c1-vault.json|ucrm|php|-d|display_errors=stderr|-r|echo 1;|gate|/tmp/dnb-5.18.99-cih-x/qo-r-2/g-c1/dishnet-hybrid-sudan|override=ucrm_quote_organization_id=0" \
    "exec|-u|1000:1000|ucrm|php|-d|display_errors=stderr|-r|echo 1;|gate|/tmp/dnb-5.18.99-cih-x/qo-a-1/g-p/dishnet-hybrid-sudan" \
    "exec|-u|1000:1000|ucrm|php|-d|display_errors=stderr|-r|echo 1;|conf|/data/ucrm/data/plugins/dishnet-hybrid-sudan|/data/ucrm/data/plugins/.dishnet-hybrid-sudan-data|none|/tmp/dnb-5.18.99-cih-x/qo-a-1/conf" \
    "exec|-u|1000:1000|-e|DN_VAULT_FILE=/tmp/dnb-5.18.98-cih-x/a23-1/c-new-vault.json|ucrm|php|-r|echo 1;|/tmp/dnb-5.18.98-cih-x/a23-1/c-new/dishnet-hybrid-sudan"; do
    printf '%s' "$c" | tr '|' '\037'; printf '\037\036'; done; } > "$SB/obs99b.rec"
check "$(python3 "$H99/rh99.py" audit "$SB/obs99b.rec")" "copies=4 novault=1 plugin=0 adj=-" \
  "control: the audit counts the quoting checks' processes on their code copies (u, l, g-c1, g-p) — the one without a vault as such — and neither the settings copies nor a 5.18.98 path"
rm -f "$SB/obs99b.rec"
# Z2's plant, its control: at a call running tools/set_config.php from a code copy of this release, a token.json in that
# run's code folder, once.
mkdir -p /tmp/dnb-5.18.99-code-rhcontrol/p3b/icode-r/dishnet-hybrid-sudan; : > "$SB/z2control.plant"
env PATH="$SB/bin:$PATH" FAKE_Z2_PLANT="$SB/z2control.plant" bash "$SB/bin/docker" exec ucrm sh -c 'true tools/set_config.php "$1"' _ /tmp/dnb-5.18.99-code-rhcontrol/p3b/icode-r/dishnet-hybrid-sudan >/dev/null 2>&1
check "$( [ -f /tmp/dnb-5.18.99-code-rhcontrol/rh99-planted/token.json ] && echo planted)|$( [ -e "$SB/z2control.plant" ] && echo unspent || echo spent)" "planted|spent" \
  "control: Z2's plant puts a token.json in the run's code folder at a call that runs tools/set_config.php from a code copy, once"
rm -rf /tmp/dnb-5.18.99-code-rhcontrol "$SB/z2control.plant"

# ── The stand-in public.php learns three more triggers, each spent once: Sync All's route answering a visitor with no
# session with a page (.leak_sync99, Q-CA4); the KYC form answering one (.leak_kyc99, Q-CA5); and 5.18.99's debug route
# left behind by a rollback's copy (.left99, naming the file to copy) — at the first request once 5.18.98's manifest is
# installed again (8). Asserted, as every edit here. ───────────────────────────────────────────────────────────────────
python3 - "$SB/web/public.php" <<'PY' || echo "standin:triggers99" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]; s = open(p).read()
old = "$page = $_GET['page'] ?? '';\n"
assert s.count(old) == 1, s.count(old)
new = """if (($_GET['page'] ?? '') === 'rh99_probe') { echo 'rh99-triggers-ready'; exit; }
if (($_GET['page'] ?? '') === 'api' && ($_GET['action'] ?? '') === 'push_products_to_ucrm' && $spend('.leak_sync99')) {
    header('Content-Type: application/json'); echo '{"status":"ok","created":0}'; exit;   // the next rehearsal: Sync All to a visitor with no session (Q-CA4)
}
if (($_GET['page'] ?? '') === 'dashboard' && ($_GET['tab'] ?? '') === 'form' && $spend('.leak_kyc99')) {
    echo '<html><body><form class="kyc-form">KYC</form></body></html>'; exit;   // the next rehearsal: the KYC form to a visitor with no session (Q-CA5)
}
$left99 = is_file($web . '/.left99') ? trim((string)file_get_contents($web . '/.left99')) : '';
if ($left99 !== '' && strpos((string)@file_get_contents($root . '/manifest.json'), '"5.18.98"') !== false && $spend('.left99')) {
    copy($left99, $root . '/includes/api/api_cron_debug.php');   // the next rehearsal: its debug route left behind by a rollback's copy (RB)
}
""" + old
s = s.replace(old, new, 1)
assert s.count("'.leak_sync99'") == 1 and s.count("'.leak_kyc99'") == 1 and s.count("'.left99'") == 1 and s.count('rh99_probe') == 1 and s.count(old) == 1
open(p, 'w').write(s)
PY
for i in $(seq 1 60); do pubget page=rh99_probe | grep -q 'rh99-triggers-ready' && break; sleep 0.1; done   # PHP re-reads the edited file
check "$(pubget page=rh99_probe)$(ls -A "$SB/web" | grep -c '^\.leak_sync99$\|^\.leak_kyc99$\|^\.left99$')|$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "$PLUGIN_BASE?page=api&action=push_products_to_ucrm&dry_run=1")|$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "$PLUGIN_BASE?page=dashboard&tab=form")" \
      "rh99-triggers-ready0|401|302" \
  "control: the stand-in carries the three triggers, unspent and unset — Sync All's route answers 401 and the KYC form the sign-in page while they are"

# ── The app's records of uCRM clients, through the INSTALLED 5.18.98 code's own writer (rh99-seed.php: SqliteStore::
# appendWithId), as the store's user, with a vault of its own: applications (one synced under each organization, one
# pending whose saved request names organization 2, one whose id is a string of digits), leads, the quotes log (a Lead
# and a Manual quote with a uCRM quote, a Manual one without, for a client uCRM does not hold, and an LTE one) and the
# legacy queue. And the admin token in the store row, where the KYC path reads it. Nothing of either is ever printed. ──
SEED99='{"kyc_applications.json":[
 {"_rh99":true,"crm_client_id":71930451,"crm_sync_status":"synced","created_at":"rel:-3 days"},
 {"_rh99":true,"crm_client_id":71930452,"crm_sync_status":"synced","created_at":"rel:-3 days"},
 {"_rh99":true,"crm_client_id":0,"crm_sync_status":"pending","crm_sync_attempts":2,"crm_sync_payload":{"organizationId":2},"created_at":"rel:-2 days"},
 {"_rh99":true,"crm_client_id":"71930453","created_at":"rel:-1 day"}],
 "leads.json":[{"_rh99":true,"crm_client_id":71930454,"created_at":"rel:-2 days"},{"_rh99":true,"crm_client_id":71930451,"created_at":"rel:-1 day"}],
 "quotes_log.json":[
 {"_rh99":true,"type":"lead","crm_client_id":71930455,"crm_quote_id":88017401,"created_at":"rel:-1 day"},
 {"_rh99":true,"type":"manual","crm_client_id":71930456,"crm_quote_id":88017402,"created_at":"rel:-1 day"},
 {"_rh99":true,"type":"manual","crm_client_id":71930457,"crm_quote_id":0,"created_at":"rel:-2 days"},
 {"_rh99":true,"type":"lte","crm_client_id":71930458,"crm_quote_id":0,"created_at":"rel:-1 day"}],
 "crm_queue.json":[{"_rh99":true,"status":"pending"},{"_rh99":true,"status":"done"}]}'
# Q-N7's staff records: five accounts of a role id no role has (987654321, so the RBAC tables grant it nothing and only
# the account's own modules list counts) — active with Subscription Plans and the Hardware Catalog, active with Plans
# alone, active with neither, inactive, an admin: three counted (all three Product Mapping), two with Plans, one with the
# Hardware Catalog. Through the installed code's own writer, as the rest.
SEED99RT='{"retailers.json":[
 {"_rh99":true,"name":"rh99-staff-1","role_id":987654321,"is_active":true,"modules":["plans","hardware"]},
 {"_rh99":true,"name":"rh99-staff-2","role_id":987654321,"is_active":true,"modules":["plans"]},
 {"_rh99":true,"name":"rh99-staff-3","role_id":987654321,"is_active":true},
 {"_rh99":true,"name":"rh99-staff-4","role_id":987654321,"is_active":false,"modules":["plans","hardware"]},
 {"_rh99":true,"name":"rh99-staff-5","role_id":987654321,"is_active":true,"is_admin":true,"modules":["plans"]}]}'
# Q-N6's LTE registrations, in lte_subscribers (a table of columns, migration 020): three days old with a uCRM id, three
# days old without, 120 days old, and three days old but deleted — two counted, one of them with a uCRM link.
LTE99_SQL="INSERT INTO lte_subscribers (name, ucrm_id, created_at, deleted_at, notes) VALUES ('rh99-lte-1', 71930451, datetime('now', '-3 days'), NULL, 'rh99'), ('rh99-lte-2', NULL, datetime('now', '-3 days'), NULL, 'rh99'), ('rh99-lte-3', 71930451, datetime('now', '-120 days'), NULL, 'rh99'), ('rh99-lte-4', 71930451, datetime('now', '-3 days'), datetime('now', '-1 days'), 'rh99')"
# RB's positive control (8b): an application with a quote refusal recorded and a lead flagged quote_org_missing, as 5.18.99
# writes them — what a rollback must count and leave.
SEED99RB='{"kyc_applications.json":[{"_rh99":true,"_rh99rb":true,"quote_refused":"R1","created_at":"rel:-1 day"}],
 "leads.json":[{"_rh99":true,"_rh99rb":true,"crm_sync_flag":"quote_org_missing","created_at":"rel:-1 day"}]}'
# Q-G5's plant: two more Manual quotes with a uCRM client and no uCRM quote — fewer than half then have one.
PLANT99_QG5='{"quotes_log.json":[
 {"_rh99":true,"_rh99plant":true,"type":"manual","crm_client_id":71930451,"crm_quote_id":0,"created_at":"rel:-1 day"},
 {"_rh99":true,"_rh99plant":true,"type":"manual","crm_client_id":71930453,"crm_quote_id":0,"created_at":"rel:-1 day"}]}'
settle96; sleep 1; VTS99A="$(vt_host)"; settle96; VTS99="$(vt_host)"   # the live vault at its fixed point before anything is seeded
O99_PRE="$(o99 1)"
RS99="$(rows_add99 "$SEED99")"; RT99="$(rows_add99 "$SEED99RT")"; LT99="$(sqx "$LTE99_SQL")"
row_set crm_auth_token "$TOKEN99"
settle96; sleep 1; settle96; VT99_0="$(vt_host)"
O99_0="$(o99 1)"; QX99_0="$(qx99 "$O99_0")"
check "$RS99|$(( $(oj "$O99_0" all) - $(oj "$O99_PRE" all) ))|$(grep -cwF -f "$SB/ids99.pat" "$(oids99 "$O99_0")")|$(sq "SELECT json_extract(data, '$.crm_auth_token') IS NOT NULL FROM kyc_config WHERE id = 0")|$VTS99A|$VT99_0" \
      "{\"rows\":12}|8|8|1|$VTS99|$VTS99" \
  "pre-d the app's records of eight uCRM clients — twelve rows — added through the installed code's own writer (this rehearsal's own reading counts eight more distinct ids, every one seeded), the admin token in the store row; the live vault at its fixed point before (a second boot leaves it as it is) and untouched by either"
check "$(qj "$QX99_0" outside)|$(qj "$QX99_0" sheet)|$(qj "$QX99_0" in)|$(qj "$QX99_0" out)|$(qj "$QX99_0" outorg)|$(oj "$O99_0" qg5)|$(sub98 "$(oj "$O99_0" qn4)" otherorg)" \
      "4|71930452:2;71930454:2;71930458:2;71930456:0|71930453|71930452|2|3:2|1" \
  "pre-e the reading the quoting checks are set against is not vacuous: four of the app's clients are filed under another organization or none (the move-list), one of each side to probe, three recent Lead and Manual quotes with a client, two of them with a uCRM quote, and one pending application whose request names organization 2"
check "$RT99|$LT99|$(sq "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'lte_subscribers'")|$(q99delta "$(oj "$O99_0" qn6)" "$(oj "$O99_PRE" qn6)")|$(q99delta "$(oj "$O99_0" qn7)" "$(oj "$O99_PRE" qn7)")" \
      '{"rows":5}|done|1|2:1|pm:3,plans:2,hw:1' \
  "pre-e' Q-N6's and Q-N7's readings are not vacuous: five staff records and four LTE registrations added — this rehearsal's own reading counts two more recent registrations, one with a uCRM link, and three more accounts that could open Sync All, two with Subscription Plans, one with the Hardware Catalog"
D99="$(data94)"; QREC99="$(sha256sum "$QREC" | cut -c1-16)"; KEPT99="$(kept_state)"

echo; echo "== 99-0. what is rehearsed: 5.18.99 — the controls =="
echo "  script    $WHICH99, sha256 $(sha256sum "$DEPLOY99" | cut -c1-16)"
case "$P99" in __*|'') echo "  FAIL the script still carries the placeholder pin ($P99)"; exit 2;; esac
check "$P99|$P99F" "$(git -C "$REPO" rev-parse --short "origin/$REL99" 2>/dev/null || echo none)|$(git -C "$REPO" rev-parse "origin/$REL99" 2>/dev/null || echo none)" "the script is pinned to the release commit on $REL99, short and in full ($P99)"
check "$(git -C "$REPO" rev-parse --short "$P99^" 2>/dev/null)|$(git -C "$REPO" rev-parse "$P99^" 2>/dev/null)|$(ver "$P99")$(ver "$P98")" "$P98|$(sed -n 's/^BASELINE_COMMIT_FULL="\([^"]*\)".*/\1/p' "$DEPLOY99")|5.18.995.18.98" \
  "the release commit is cut on $P98 (5.18.98, the live version, its full hash as the script pins it) and is 5.18.99"
check "$(dl D "$P98" "$P99" | grep -c .)|$(dl A "$P98" "$P99" | grep -c .)|$(dl AM "$P98" "$P99" | grep -c .)|$(git -C "$REPO" diff --no-renames --name-only --diff-filter=CRTUXB "$P98" "$P99" | grep -c .)|$(git -C "$REPO" diff --name-only "$P98" "$P99" | grep -vc "^$P/")" "0|6|$N99|0|0" \
  "5.18.98 → 5.18.99: nothing removed, copied, renamed or retyped, nothing outside the plugin; six files added, $N99 in all"
check "$(dl A "$P98" "$P99" | sort | paste -sd' ' -)|$(dl M "$P98" "$P99" | sort | paste -sd' ' -)|$(sed -n 's/^EXPECTED_ADDED="\(.*\)"$/\1/p' "$DEPLOY99")|$(sed -n 's/^EXPECTED_CHANGED="\(.*\)"$/\1/p' "$DEPLOY99")" \
      "$(for f in $ADDED99; do echo "$P/$f"; done | sort | paste -sd' ' -)|$(for f in $CHANGED99; do echo "$P/$f"; done | sort | paste -sd' ' -)|$ADDED99|$CHANGED99" \
  "…the six added are QuoteOrganization, its test, its weakened copies, two fixtures and the fake mail relay's test; the thirty changed the KYC, quotation, lead-sync and PDF classes, the three tab pages, the profile, set_config, the manifest, SAFETY.md, the fake relay and the tests that name them — and the script's own lists say exactly that"
check "$(dl AM "$P98" "$P99" | grep -c "^$P/migrations/")|$(git -C "$REPO" show "$P99:$P/migrations/088_bank_statements.sql" | sha256sum | cut -c1-64)" "0|$MIG88_SHA99" "no migration; 088 at the pin the reviewed file the script pins (sha256 ${MIG88_SHA99:0:16}…)"
check "$(git -C "$REPO" cat-file -e "$DEV99_TIP^{commit}" 2>/dev/null && echo held)|$(git -C "$REPO" rev-parse "$P99:$P")|$(git -C "$REPO" rev-list --count "$P98F..$DEV99_TIP" -- "$P")|$(git -C "$REPO" merge-base --is-ancestor "$P98F" "$DEV99_TIP" && echo on-the-live-release)" \
      "held|$(git -C "$REPO" rev-parse "$DEV99_TIP:$P" 2>/dev/null)|5|on-the-live-release" \
  "the release's plugin tree is the development branch's ($DEV99, $DEV99_TIP) exactly — its five commits on $P98"
PS99="$(sed -n '/^PIN_SHA256S="\$(cat <<.SUMS.$/,/^SUMS$/p' "$DEPLOY99" | grep -E '^[0-9a-f]{64}  ')"
KS99="$(sed -n '/^PREV_KEPT_SHA256S="\$(cat <<.SUMS.$/,/^SUMS$/p' "$DEPLOY99" | grep -E '^[0-9a-f]{64}  ')"
PC99="$(sed -n '/^PREV_CHANGED_SHA256S="\$(cat <<.SUMS.$/,/^SUMS$/p' "$DEPLOY99" | grep -E '^[0-9a-f]{64}  ')"
PSL99=""; while read -r s f; do [ -n "$f" ] || continue; [ "$(git -C "$REPO" show "$P99:$P/$f" | sha256sum | cut -c1-64)" = "$s" ] || PSL99="$PSL99 $f"; done <<<"$PS99"
check "$(grep -c . <<<"$PS99")|$(awk '{print $2}' <<<"$PS99" | sort | paste -sd' ' -)|${PSL99:-same}|$(sed -n 's/^PIN_SHA_N=\([0-9]*\)$/\1/p' "$DEPLOY99")" "$N99|$(for f in $ADDED99 $CHANGED99; do echo "$f"; done | sort | paste -sd' ' -)|same|$N99" \
  "the script pins each of the thirty-six files by the sha256 Git gives it at the pin — this rehearsal's own reading — and pins no other"
KSL99=""; while read -r s f; do [ -n "$f" ] || continue; for c in "$P98" "$P99"; do [ "$(git -C "$REPO" show "$c:$P/$f" | sha256sum | cut -c1-64)" = "$s" ] || KSL99="$KSL99 $f@$c"; done; done <<<"$KS99"
check "$(grep -c . <<<"$KS99")|$(awk '{print $2}' <<<"$KS99" | sort | paste -sd' ' -)|${KSL99:-same}|$(sed -n 's/^PREV_KEPT_N=\([0-9]*\)$/\1/p' "$DEPLOY99")" "31|$(for f in $KEPTF99; do echo "$f"; done | sort | paste -sd' ' -)|same|31" \
  "…the thirty-one 5.18.98 files it keeps — ECO Bank's module, its links, the More menu, the styles, the Cashbook's post handler and its list split, ECO Bank's wiring, the cash-in-hand tool and the files the quoting code reads — by the sha256 Git gives each in both release commits"
PCL99=""; while read -r s f; do [ -n "$f" ] || continue; [ "$(git -C "$REPO" show "$P98:$P/$f" | sha256sum | cut -c1-64)" = "$s" ] || PCL99="$PCL99 $f"; done <<<"$PC99"
check "$(grep -c . <<<"$PC99")|$(awk '{print $2}' <<<"$PC99" | sort | paste -sd' ' -)|${PCL99:-same}|$(sed -n 's/^PREV_CHANGED_N=\([0-9]*\)$/\1/p' "$DEPLOY99")" "30|$(for f in $CHANGED99; do echo "$f"; done | sort | paste -sd' ' -)|same|30" \
  "…and the thirty files it changes by the sha256 Git gives each at 5.18.98's release commit — what Q-G1 requires installed before anything changes, and RB after a rollback"
check "$(git -C "$REPO" diff --name-only "$P98" "$P99" -- "$P/dishnet-mikrotik-control-plane" "$P/docs" | grep -c .)" "0" "Domain B's tree and the plugin's docs are 5.18.98's"
V99L=""; python3 - <(git -C "$REPO" show "$P98:$P/manifest.json") <(git -C "$REPO" show "$P99:$P/manifest.json") <<'PY' || V99L="$V99L manifest.json"
import sys
a, b = open(sys.argv[1]).read(), open(sys.argv[2]).read()
assert a.count('5.18.98') >= 1 and a.replace('5.18.98', '5.18.99') == b and a != b
PY
for f in $PINS99; do
  d="$(git -C "$REPO" diff -U0 "$P98" "$P99" -- "$P/$f" | grep -E '^[-+][^-+]')"
  [ "$(grep -c '^-' <<<"$d")|$(grep -c '^+' <<<"$d")|$(grep '^-' <<<"$d" | grep -c "'5.18.98'")|$(grep '^+' <<<"$d" | grep -c "'5.18.99'")" = "1|1|1|1" ] || V99L="$V99L $f"
done
check "${V99L:-pins}" "pins" "the manifest is 5.18.98's with the version alone changed, and the five distributor tests each change one line, the version they pin"
check "$T1SIG99|$T4SIG99" "$T1SIG98|$T4SIG98" "the script pins T1's and T4's counts as 5.18.98 pinned them ($T1SIG99; $T4SIG99): 5.18.99 changes neither, nor any file either reads"
check "$(sed -n 's/^P0_TOOL_SHA="\([0-9a-f]*\)".*/\1/p' "$DEPLOY99")|$(sed -n 's/^ADJ_CENTS_SHA="\([0-9a-f]*\)".*/\1/p' "$DEPLOY99")|$(sed -n 's/^COPY_MARK="\([^"]*\)"$/\1/p' "$DEPLOY99")" \
      "$(sed -n 's/^P0_TOOL_SHA="\([0-9a-f]*\)".*/\1/p' "$DEPLOY98")|$(sed -n 's/^ADJ_CENTS_SHA="\([0-9a-f]*\)".*/\1/p' "$DEPLOY98")|lib/KycService.php" \
  "P0's tool and R0's adjustment hash pinned as 5.18.98 pinned them — hashes, never a figure — and the copy's time read off lib/KycService.php, a file 5.18.99 changes"
check "$(hdr99 "$DEPLOY99")" "1101|3" \
  "the header's three commands — the move-list run, the deploy, the --after-only — each with its own log wrapper, and nothing else in their block: no rollback, put-back, redeploy, check-bank, flag, setting or checkout (docs/44 §16.9)"
M="$(weak99 hdrrb99 "$DEPLOY99" hdrdeploy)"
check "$(hdr99 "$M")" "1011|3" "0-hdr' a copy whose header pastes the rollback onto the deploy's line is caught by the same reading: that line is no longer the deploy alone, and the rollback is named in the block"
rm -f "$M"
check "$(cmds99 "$DEPLOY99")" "111111111|12|8|15|4|1|1|1|11|0" \
  "every command the script prints for a later step is defined with its own log wrapper — the --after-only, the rollback, the deploy, the put-back, the quarantine's --check with its tee, --redeploy, --check-bank, the runbook's deploy and the move-list run — and every line that prints one names one of them, prints with_flags' own (its one line carrying the wrapper), or carries the wrapper itself; --check's own forms both carry it; no switch command"
M="$(weak99 fnowrap99 "$DEPLOY99" runbookdef)"
check "$(cmds99 "$M" | cut -d'|' -f1)" "111111101" "0-cmd' a copy that defines the runbook's deploy without its log wrapper is caught by the same reading"
rm -f "$M"
PR99_TWO="$(printf '%s\n' '== F. Summary ==' "      $EXP_AFTER99; $EXP_ROLLBACK99")"
PR99_WORDS="  ok    Q-CA3 deploy-5.18.99.sh's GETs, as deploy-5.18.99.sh's stage A read them — the script named twice, in words"
check "$(printed99 "$PR99_TWO")|$(printed99 "$PR99_WORDS" | cut -d'|' -f3)" "1|0|1|0" \
  "control: printed99 sees a summary line holding two commands — the --after-only and the rollback pasted together — and not an ok line that names deploy-5.18.99.sh twice in words"
check "$(grep -cE '(>|>>) *"?\$(VAULT_HOST|VAULT)"?( |$)' "$DEPLOY99")$(grep -cE '(mv|rm|touch|chmod|chown|tee) [^|;]*"\$(VAULT_HOST|VAULT)"' "$DEPLOY99")$(grep -E 'cp [^|;]*"\$VAULT"' "$DEPLOY99" | grep -vcF 'cp -p "$VAULT" "$BK/config-vault.json"')" "000" \
  "the script never writes, moves or touches the live vault — it copies it only into its own backup"
check "$(grep -cE '(cat|head|tail|more|less|awk|sed|grep)( -[^ ]+)* +"\$SHEET"' "$DEPLOY99")$(grep -cF 'echo "      less $SHEET"' "$DEPLOY99")" "04" \
  "nothing in the script reads the sheet out — it prints only \`less <path>\`, on its own line, in each of its four summaries"
SC99="$(grep -vE '^[[:space:]]*#' "$DEPLOY99" | grep -E 'set_config\.php"? --key')"
check "$(grep -c . <<<"$SC99")|$(grep -cF -- '-e DN_DATA_DIR="$d"' <<<"$SC99")$(grep -cF -- '--key ucrm_quote_organization_id --value disabled' <<<"$SC99")|$(grep -vE '^[[:space:]]*#' "$DEPLOY99" | grep -cF "$BK97 --value")|$(grep -vE '^[[:space:]]*#' "$DEPLOY99" | grep -cF "$BK97 --clear")|$(grep -vE '^[[:space:]]*#' "$DEPLOY99" | grep -cE -- '--key ucrm_quote_organization_id --value [0-9]')" "1|11|0|0|0" \
  "the script sets no key on the live install — not ECO Bank's switch, not the quoting organization: its one call of set_config with a key, in any quoting, is Q-CA6's refusal (--value disabled) on a throwaway data folder (DN_DATA_DIR=\$d)"
check "$(grep -c 'DISHNET_REPAIR' "$DEPLOY99")$(grep 'DISHNET_REPAIR' "$DEPLOY99" | grep -vc 'grep -')$(grep -cFf "$SB/spx.vals" "$DEPLOY99")" "400" \
  "the script names the old built-in key only in grep patterns (four), and holds none of 5.18.94's Splynx values"
check "$(grep -vE '^[[:space:]]*#' "$DEPLOY99" | grep -cE 'Ecobank UGX|Ecobank USD|7000000001|RH-Account|RH-CLIENT|0000000001|0000000002|RH98 |RH99|rh99|71930')|$(grep -cF -f "$U99_SECRETS" "$DEPLOY99")" "0|0" \
  "the script's code names no account, number, holder or counterparty, and nothing of this rehearsal's stand-in uCRM"
check "$(bash -n "$DEPLOY99" && echo parses)" "parses" "the script parses"
check "$(git -C "$REPO" log -1 --format=%h "$P99" -- $P)|$(git -C "$REPO" log -1 --format=%h "$P98" -- $P)" "$P99|$P98" "control: the clone writes the 7-character short forms the script names"
# ── The instruments, taken from the script as it stands and run on copies in a folder of this rehearsal's own under /tmp
# — never one the observers watch — owned by the store's user. The live book untouched. ──────────────────────────────
K99="$(mktemp -d /tmp/rh99-k.XXXXXX)"
python3 - "$DEPLOY99" "$K99" <<'PY' || echo "instrument:extract99" >> "$SB/anchor-missing"
import sys, re
s = open(sys.argv[1]).read(); d = sys.argv[2]
for name in ('CPD_PHP', 'CPJ_PHP', 'QO_PHP', 'QOP_PHP'):
    m = re.findall(r'(?m)^' + name + r'="\$\(cat <<\'PHP\'\n(.*?)\nPHP\n\)"', s, re.S)
    assert len(m) == 1, name
    open(d + '/' + name.lower() + '.txt', 'w').write(m[0])
out = []
for fn in ('kv', 'sec_say', 'subkv', 'c3f', 'c_full'):
    m = re.findall(r'(?m)^' + fn + r'\(\) +\{.*\}[^\n]*$', s)
    assert len(m) == 1, fn
    out.append(m[0])
m = re.findall(r'(?ms)^c_judge\(\) \{.*?^\}$', s)
assert len(m) == 1, 'c_judge'
out.append(m[0])
open(d + '/cj.sh', 'w').write('\n'.join(out) + '\n')
q = open(d + '/qo_php.txt').read()
m = re.findall(r'(?ms)^final class DnbGetOnly\n\{\n.*?^\}\n', q)
assert len(m) == 1, 'DnbGetOnly'
open(d + '/getonly.php', 'w').write('<?php\n' + m[0])
PY
chown -R 1000:1000 "$K99"
php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec("VACUUM INTO " . $p->quote($argv[2]));' "$DATA/plugin.sqlite3" "$K99/copy.sqlite3" || echo "  diag 0-k99: the copy failed"
cpd99() { php -r "$(cat "$K99/cpd_php.txt")" "$K99/copy.sqlite3" 2>/dev/null; }
cmpd99() { local w="" k; for k in rows acc master; do [ "$(kv98 "$1" "$k")" = "$(kv98 "$2" "$k")" ] || w="$w,$k"; done; w="${w#,}"; echo "${w:--}"; }
C5D0="$(cpd99)"; C5D0B="$(cpd99)"
php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec("UPDATE cb_ledger SET description = COALESCE(description, \x27\x27) || \x27.\x27 WHERE id = (SELECT MAX(id) FROM cb_ledger)");' "$K99/copy.sqlite3"; C5D1="$(cpd99)"
php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec("UPDATE cb_accounts SET updated_at = updated_at || \x27.\x27 WHERE id = (SELECT MIN(id) FROM cb_accounts)");' "$K99/copy.sqlite3"; C5D2="$(cpd99)"
php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec("CREATE TABLE rh99_planted_write (x INTEGER)");' "$K99/copy.sqlite3"; C5D3="$(cpd99)"
check "$(case "$C5D0" in "rows="*":$(sq "SELECT COUNT(*) FROM cb_ledger") acc="*" master="*) echo read ;; *) echo "other(${C5D0:0:60})" ;; esac)|$(cmpd99 "$C5D0" "$C5D0B")|$(cmpd99 "$C5D0B" "$C5D1")|$(cmpd99 "$C5D1" "$C5D2")|$(cmpd99 "$C5D2" "$C5D3")" "read|-|rows|acc|master" \
  "0-k C5's digest reader, as 5.18.99 carries it, on a copy of the book: it reads every row of cb_ledger and gives the same digests twice; one row's text changed moves the row digest alone, one account's the cb_accounts digest alone, one table created sqlite_master's alone"
CJ_GOOD99='c1=8/8 c2=12/12 c3=parts:12/12,counts:12/12,nulls:12/12,chips:3/3 c3same=12/12 c3chips=same c4=12/12 c5=same c5old=same rules=1/1 noncash=all:5,dishnet:3,4g:1,bluecard:1'
cj99() { bash -c '. "$1"; c_judge "$2"' _ "$2" "$1" 2>&1; }
check "$(cj99 "$CJ_GOOD99" "$K99/cj.sh")|$(cj99 "${CJ_GOOD99/c5=same/c5=differs}" "$K99/cj.sh")|$(cj99 "${CJ_GOOD99/rules=1\/1/rules=1/0}" "$K99/cj.sh")|$(cj99 "${CJ_GOOD99/c3same=12\/12/c3same=11/12}" "$K99/cj.sh")|$(cj99 "${CJ_GOOD99/c3chips=same/c3chips=differs}" "$K99/cj.sh")" \
      "ok|C5:a-digest-moved-under-the-new-code's-reads|rule-A-not-in-force-for-both-codes(1/0)|C3:the-split-not-the-old-code's(11/12)|C3:the-tab's-buttons-not-the-old-code's(differs)" \
  "0-k' the copy checks' judge, as 5.18.99 defines it: a line where everything holds is ok; a digest moved names C5, a code without rule A names it, and the two terms 5.18.99 adds — the split and the tab's buttons against the old code's — each named when they differ"
check "$(cj99 "${CJ_GOOD99/c1=8\/8/c1=7/8}" "$K99/cj.sh")|$(cj99 "${CJ_GOOD99/c2=12\/12/c2=11/12}" "$K99/cj.sh")|$(cj99 "${CJ_GOOD99/parts:12\/12/parts:11/12}" "$K99/cj.sh")|$(cj99 "${CJ_GOOD99/counts:12\/12/counts:11/12}" "$K99/cj.sh")|$(cj99 "${CJ_GOOD99/nulls:12\/12/nulls:11/12}" "$K99/cj.sh")|$(cj99 "${CJ_GOOD99/chips:3\/3/chips:2/3}" "$K99/cj.sh")|$(cj99 "${CJ_GOOD99/c4=12\/12/c4=11/12}" "$K99/cj.sh")" \
      "C1:cash-in-hand(7/8)|C2:the-list-with-no-key(11/12)|C3:parts(11/12)|C3:counts(11/12)|C3:nulls(11/12)|C3:chips(2/3)|C4:counts-pending-pl(11/12)" \
  "0-k' …and every term carried from 5.18.98, each short of full, named in the judge's own words"
python3 - "$K99/cj.sh" "$K99" <<'PY' || echo "instrument:cj-weak99" >> "$SB/anchor-missing"
import sys
s = open(sys.argv[1]).read(); d = sys.argv[2]
loop = 'for x in parts counts nulls chips; do'
cuts = (('cjw-c5', '  [ "$(kv "$l" c5)" = "same" ] || w="$w C5:a-digest-moved-under-the-new-code\'s-reads"\n', ''),
        ('cjw-c3same', '  c_full "$(kv "$l" c3same)" || w="$w C3:the-split-not-the-old-code\'s($(kv "$l" c3same))"', '  true'),
        ('cjw-c3chips', '  [ "$(kv "$l" c3chips)" = "same" ] || w="$w C3:the-tab\'s-buttons-not-the-old-code\'s($(kv "$l" c3chips))"\n', ''),
        ('cjw-c1', '  c_full "$(kv "$l" c1)" || w="$w C1:cash-in-hand($(kv "$l" c1))"\n', ''),
        ('cjw-c2', '  c_full "$(kv "$l" c2)" || w="$w C2:the-list-with-no-key($(kv "$l" c2))"\n', ''),
        ('cjw-c4', '  c_full "$(kv "$l" c4)" || w="$w C4:counts-pending-pl($(kv "$l" c4))"\n', ''),
        ('cjw-parts', loop, 'for x in counts nulls chips; do'),
        ('cjw-counts', loop, 'for x in parts nulls chips; do'),
        ('cjw-nulls', loop, 'for x in parts counts chips; do'),
        ('cjw-chips', loop, 'for x in parts counts nulls; do'))
for name, old, new in cuts:
    assert s.count(old) == 1, (name, s.count(old))
    t = s.replace(old, new, 1)
    assert t != s and t.count(old) == 0, name
    open(d + '/' + name + '.sh', 'w').write(t)
PY
check "$(cj99 "${CJ_GOOD99/c5=same/c5=differs}" "$K99/cjw-c5.sh")|$(cj99 "${CJ_GOOD99/c3same=12\/12/c3same=11/12}" "$K99/cjw-c3same.sh")|$(cj99 "${CJ_GOOD99/c3chips=same/c3chips=differs}" "$K99/cjw-c3chips.sh")|$(cj99 "${CJ_GOOD99/c1=8\/8/c1=7/8}" "$K99/cjw-c1.sh")|$(cj99 "${CJ_GOOD99/c2=12\/12/c2=11/12}" "$K99/cjw-c2.sh")|$(cj99 "${CJ_GOOD99/c4=12\/12/c4=11/12}" "$K99/cjw-c4.sh")|$(cj99 "${CJ_GOOD99/parts:12\/12/parts:11/12}" "$K99/cjw-parts.sh")|$(cj99 "${CJ_GOOD99/counts:12\/12/counts:11/12}" "$K99/cjw-counts.sh")|$(cj99 "${CJ_GOOD99/nulls:12\/12/nulls:11/12}" "$K99/cjw-nulls.sh")|$(cj99 "${CJ_GOOD99/chips:3\/3/chips:2/3}" "$K99/cjw-chips.sh")" \
      "ok|ok|ok|ok|ok|ok|ok|ok|ok|ok" \
  "0-k'' controls on the control: a judge without its C5 line, its split line, its buttons line, its C1, C2 or C4 line, or with parts, counts, nulls or chips dropped from C3's terms, calls that same short reading ok — each line is what stands between a defect and GO (caught)"
# CPJ_PHP — the copy checks' own comparison — on synthetic readings: two the same, the split and the buttons among them;
# one cell of C1, C2 and C4, one view's split and the buttons changed; and two that hold nothing. Counts only, no book.
printf '%s' '{"role":"old","rule":1,"c1":{"UGX|":"a","UGX|dishnet":"b"},"c2":{"|":[1,"x"],"dishnet|":[2,"y"]},"c4":{"count|":"p","pl|":"q"},"c3":{"sig":{"|":"s1","dishnet|":"s2"},"chipd":{"all":"1:3"}}}' > "$K99/cpj-o.json"
printf '%s' '{"role":"new","rule":1,"c1":{"UGX|":"a","UGX|dishnet":"b"},"c2":{"|":[1,"x"],"dishnet|":[2,"y"]},"c4":{"count|":"p","pl|":"q"},"c3":{"views":2,"parts":2,"counts":2,"nulls":2,"chips":3,"rows":{"|":[3,2,1]},"sig":{"|":"s1","dishnet|":"s2"},"chipd":{"all":"1:3"}}}' > "$K99/cpj-same.json"
printf '%s' '{"role":"new","rule":1,"c1":{"UGX|":"a","UGX|dishnet":"B"},"c2":{"|":[1,"x"],"dishnet|":[2,"Y"]},"c4":{"count|":"P","pl|":"q"},"c3":{"views":2,"parts":2,"counts":2,"nulls":2,"chips":3,"rows":{"|":[3,2,1]},"sig":{"|":"s1","dishnet|":"S2"},"chipd":{"all":"1:4"}}}' > "$K99/cpj-diff.json"
printf '%s' '{"role":"old","rule":1}' > "$K99/cpj-oe.json"; printf '%s' '{"role":"new","rule":1}' > "$K99/cpj-ne.json"
CPJD99="rows=0123456789abcdef:1 acc=0123456789abcdef:1 master=0123456789abcdef:1"
cpj99() { php -r "$(cat "$K99/cpj_php.txt")" "$K99/$1" "$K99/$2" "$K99/cpj-sheet.txt" rh99 "$CPJD99" "$CPJD99" "$CPJD99" 2>/dev/null; }
CPJS99="$(cpj99 cpj-o.json cpj-same.json)"; CPJX99="$(cpj99 cpj-o.json cpj-diff.json)"; CPJE99="$(cpj99 cpj-oe.json cpj-ne.json)"
check "$(kv98 "$CPJS99" c3same)/$(kv98 "$CPJS99" c3chips)|$(cj99 "$CPJS99" "$K99/cj.sh")|$(cj99 "$CPJX99" "$K99/cj.sh")|$(cj99 "$CPJE99" "$K99/cj.sh")" \
      "2/2/same|ok|C1:cash-in-hand(1/2) C2:the-list-with-no-key(1/2) C3:the-split-not-the-old-code's(1/2) C3:the-tab's-buttons-not-the-old-code's(differs) C4:counts-pending-pl(1/2)|C1:cash-in-hand(0/0) C2:the-list-with-no-key(0/0) C3:parts(0/0) C3:counts(0/0) C3:nulls(0/0) C3:chips(0/3) C3:the-split-not-the-old-code's(0/0) C3:the-tab's-buttons-not-the-old-code's(differs) C4:counts-pending-pl(0/0)" \
  "0-k'c the copy checks' comparison (CPJ_PHP), as 5.18.99 carries it, then its judge: two readings the same — the split and the buttons too — ok; a cell of C1, C2 and C4, a view's split and the buttons changed, each counted short and named; two readings that hold nothing never ok"
# The GET-only wrapper (QO_PHP's DnbGetOnly), alone, around a client that records what reaches it: a get() passes and is
# counted; post, patch, put, delete and any other call are counted and refused, and never reach the client. Its weakened
# copy, whose post() passes the call on, lets the POST reach the client (caught).
cat > "$K99/getonly-probe.php" <<'PHP'
<?php
$GLOBALS["dnbN"] = ["GET" => 0, "other" => 0];
require $argv[1];
final class RhFakeClient
{
    public array $seen = [];
    public function get(string $p): ?array { $this->seen[] = "get"; return []; }
    public function post(string $p, array $x = []) { $this->seen[] = "post"; return []; }
    public function patch(string $p, array $x = []) { $this->seen[] = "patch"; return []; }
    public function put(string $p, array $x = []) { $this->seen[] = "put"; return []; }
    public function delete(string $p) { $this->seen[] = "delete"; return []; }
    public function getLastError(): array { return []; }
    public function getBaseUrl(): string { return "rh99"; }
}
$f = new RhFakeClient(); $w = new DnbGetOnly($f); $refused = 0;
$w->get("organizations");
foreach (["post", "patch", "put", "delete", "rh99call"] as $m) {
    try { if ($m === "delete") $w->delete("x"); elseif ($m === "rh99call") $w->rh99call("x"); else $w->$m("x", []); }
    catch (RuntimeException $e) { $refused++; }
}
echo "GET=", $GLOBALS["dnbN"]["GET"], " other=", $GLOBALS["dnbN"]["other"], " refused=", $refused, " reached=", implode(",", $f->seen);
PHP
python3 - "$K99/getonly.php" "$K99/getonly-w.php" "$(a99 wrappost)" "$(r99 wrappost)" <<'PY' || echo "instrument:getonly-weak99" >> "$SB/anchor-missing"
import sys
s = open(sys.argv[1]).read(); old, new = sys.argv[3], sys.argv[4]
assert s.count(old) == 1, s.count(old)
open(sys.argv[2], 'w').write(s.replace(old, new, 1))
PY
chown -R 1000:1000 "$K99"
check "$(php "$K99/getonly-probe.php" "$K99/getonly.php" 2>&1)|$(php "$K99/getonly-probe.php" "$K99/getonly-w.php" 2>&1)" \
      "GET=1 other=5 refused=5 reached=get|GET=1 other=4 refused=4 reached=get,post" \
  "0-g the GET-only wrapper, as the script carries it: a GET passes and is counted; POST, PATCH, PUT, DELETE and any other call are counted and refused before they reach the client — and its weakened copy lets the POST through (caught)"
# The token copy, as the probe's QOP_PHP makes it, on a copy of the store holding this rehearsal's token: crm_auth_token
# alone (no crm_base_url is set), 0600; what it prints names neither; the store row's copy holds no credential.
mkdir -p "$K99/d" "$K99/tok" "$K99/conf"; cp "$K99/copy.sqlite3" "$K99/d/plugin.sqlite3"; chown -R 1000:1000 "$K99"; chmod 700 "$K99/tok"
QOPT99="$(php -r "$(cat "$K99/qop_php.txt")" token /tmp/rh99-none "$K99/d" none "$K99/tok" 2>&1)"
QOPR99="$(php -r "$(cat "$K99/qop_php.txt")" row /tmp/rh99-none "$K99/d" none "$K99/conf" 2>&1)"
check "$QOPT99|$(stat -c %a "$K99/tok/token.json" 2>/dev/null)|$(python3 -c 'import json, sys; print(",".join(sorted(json.load(open(sys.argv[1])))))' "$K99/tok/token.json" 2>&1)|$(grep -cF "$TOKEN99" "$K99/tok/token.json")|$(printf '%s%s' "$QOPT99" "$QOPR99" | grep -cF "$TOKEN99")|$(grep -cF "$TOKEN99" "$K99/conf/row.json")|$(grep -c crm_auth_token "$K99/conf/row.json")" \
      "token=set url=unset|600|crm_auth_token|1|0|0|0" \
  "0-t the token copy, as the script's QOP_PHP makes it: crm_auth_token alone, in a 0600 file — what it prints says set/unset only; the store row's copy for the gate holds no credential"
check "$(php -r "$(cat "$K99/qo_php.txt")" gate /var/rh99/root "data=/tmp/x" 2>/dev/null)|$(php -r "$(cat "$K99/qo_php.txt")" remote /tmp/rh99/root "ucrm=/data/x" "out=/var/rh99/out" 2>/dev/null)|$(php -r "$(cat "$K99/qo_php.txt")" local /tmp/rh99/root "ids=/root/rh99" 2>/dev/null)|$(php -r "$(cat "$K99/qop_php.txt")" conf /tmp/x /tmp/y none /var/rh99 2>/dev/null)|$( [ -e /var/rh99 ] && echo made || echo none)" \
      "?notacopy:root|?notacopy:out|?notacopy:ids|?dest|none" \
  "0-p the quoting probe and the settings copier each refuse to start on a path outside the container's /tmp — a code root, its sheet's file, its ids file, a copy's folder — before they read or write anything"
rm -rf "$K99"
check "$(data94)|$(ls -d /tmp/rh99-k.* 2>/dev/null | grep -c .)|$(vt_host)" "$D99|0|$VT99_0" "…the live book and vault untouched by all of this; the instruments' folder gone"

echo; echo "== 99-c. --check: stage A alone — GO on 5.18.98 as production has it; the quoting organization read with GETs only; the option rules =="
fresh99; S0="$(states99)"; FP0="$(fp99)"; O="$(o99 1)"; QX="$(qx99 "$O")"; OC="$(o97)"; NC="$(o98)"
OUT="$(run99 --check)"; T="$(last99)"; SHC="$(sheet_of "$OUT")"; FP1="$(fp99)"; TC99="$T"
check "$(checkgo99 "$OUT")" "yesyesnono0|0|$S0" "c1 --check is GO: stage A held — nothing asked, nothing backed up, no state file written"
check "$FP1" "$FP0" "c1' …and nothing changed: the installed tree, the store's bytes and every row, the vault (bytes and time), both settings files, the stray folder's, ucrm.json and the record of the live commit as before the run"
check "$(okline "$OUT" Q-G1 "installed is 5.18.98 ($P98): its manifest says 5.18.98; all 30 files 5.18.99 changes carry the sha256 pinned for 5.18.98, and all 31 files it must not touch")$(okline "$OUT" Q-G2 'the tenant is Uganda, through the INSTALLED 5.18.98 code on a copy' '(uganda/uganda, yes/yes)')$(okline "$OUT" Q-G3 "quoting organization #1 (from the Uganda profile), through the RELEASE's QuoteOrganization::setting() on the same copy" '(on:1:profile:-)')" "yesyesyes" \
  "c2 Q-G1 (every file 5.18.99 changes and every file it keeps, by the sha256 pinned for 5.18.98), Q-G2 (Uganda through the installed code), Q-G3 (organization #1 from the profile, the files and the store row alike)"
check "$(okline "$OUT" Q-G4 "QuoteOrganization::organizations(): 1,2 — the quoting organization #1 among them, in the book's currency (UGX)")$(okline "$OUT" Q-G4b 'token client: organizations read (2)' "the plugin's base URL, x-auth-token" 'its copy removed once read; the quoting organization #1 among them' "the debug retry's own client is that one here")|$(python3 "$H99/rh99.py" qg4lines "$R99/run-$T.txt")" \
      "yesyes|$(python3 "$H99/rh99.py" qg4exp "$U99/state.json")" \
  "c2' Q-G4 (uCRM's organizations through the plugin's own client, GET-only: #1 among them, in UGX) and Q-G4b (the token client, the debug retry's the same) — the organization lines the stand-in's own, in order"
check "$(okline "$OUT" Q-G5 "the last 30 days' Lead and Manual quotes with a uCRM client: $(oj "$O" qg5 | cut -d: -f1), $(oj "$O" qg5 | cut -d: -f2) of them with a uCRM quote — at least half")$(okline "$OUT" Q-G6 'ucrm_lead_organization_id is unset in the configuration files and in the store row: a new AI-lead client falls back to the quoting organization #1')" "yesyes" \
  "c2'' Q-G5 in this rehearsal's own counts (three, two with a uCRM quote) and Q-G6 (no lead organization set)"
check "$(noteline "$OUT" Q-N1 'ucrm_lead_organization_id unset/unset' 'crm_auth_token unset/set' 'ucrm_quote_organization_id unset/unset')$(noteline "$OUT" Q-N2 "read/all $(oj "$O" cap)/$(oj "$O" all) (at most 300 read), one GET each: $(python3 "$H99/rh99.py" qn2say "$(qj "$QX" qn2)") — the $(qj "$QX" outside) filed under another organization are on the sheet by id")$(noteline "$OUT" Q-N3 'the newest uCRM quote: #88017402 (2 created since')" "yesyesyes" \
  "c3 Q-N1 (the token in the store row alone, nothing set of the quoting keys), Q-N2 (every id the app holds read once, counted by organization — this rehearsal's own reading of the store and the stand-in's), Q-N3 (the stand-in's newest quote)"
Q4=$(oj "$O" qn4)
check "$(noteline "$OUT" Q-N4 "pending $(sub98 "$Q4" pending) ($(sub98 "$Q4" spent) of them at ten attempts; $(sub98 "$Q4" otherorg) whose saved request names another organization), review $(sub98 "$Q4" review), failed $(sub98 "$Q4" failed); synced $(sub98 "$Q4" synced)")$(noteline "$OUT" Q-N5 "crm_queue.json by status: $(oj "$O" qn5 | sed 's/,/, /g; s/:/ /g')")$(noteline "$OUT" Q-N6 "LTE registrations of the last 90 days: $(q6say99 "$(oj "$O" qn6)") — ")$(noteline "$OUT" Q-N7 'the active non-admin accounts that could open a Sync All screen' "UCRM Product Mapping $(sub98 "$(oj "$O" qn7)" pm) (every one), Subscription Plans $(sub98 "$(oj "$O" qn7)" plans), the Hardware Catalog $(sub98 "$(oj "$O" qn7)" hw) — ")" "yesyesyesyes" \
  "c3' Q-N4 to Q-N7 in this rehearsal's own counts — the pending application whose request names organization 2 among them; the LTE registrations of 90 days and their uCRM links; the accounts that could open each Sync All screen, by the RBAC tables and their own modules lists"
check "$(noteids98 "$OUT")" "A17 A23.4 A23.5 A23.6 BN5 G3 N1 N2 N3 N4 Q-N1 Q-N2 Q-N3 Q-N4 Q-N5 Q-N6 Q-N7" "c3'' its notes are exactly 5.18.98's stage-A notes on this book and Q-N1–Q-N7"
check "$(okline "$OUT" G2 'rule A is on, read through the INSTALLED code on a copy')$(okline "$OUT" G3 'both ECO BANK cards show a figure, through the live 5.18.98 classes on the copy')$(okline "$OUT" BR3 "ECO Bank's switch, $BK97, is ON in the configuration files")$(okline "$OUT" C1 'the same in all 8/8 currency × project cells')$(okline "$OUT" C3 '(12/12 and 12/12)')$(okline "$OUT" C5 "unchanged by every read of 5.18.99's code")|$(g3say98 "$R99/run-$T.txt")|$(noteline "$OUT" N4 "that hold rows: $(sub98 "$(oj "$NC" n4)" accounts), with $(sub98 "$(oj "$NC" n4)" rows) row(s)")" \
      "yesyesyesyesyesyes|$(python3 "$H98/rh98.py" g3exp "$OC")|yes" \
  "c4 5.18.98's checks, carried: G2, G3 and its lines by row id, BR3 the switch ON, C1, C3 and C5 in their full counts, N4 this rehearsal's own count"
check "$(grep -cxF "      $EXP_RUNBOOK99" <<<"$OUT")$(grep -cxF "      $EXP_DEPLOY99" <<<"$OUT")$(grep -c 'deploy-5.18.99.sh --rollback' <<<"$OUT")$(grep -c -- '--quote-company-off\|--quote-create-unproven\|--lead-org-differs' <<<"$OUT")|$(printed99 "$OUT")|$(grep -cxF "      less $SHC" <<<"$OUT")|$(stat -c %a "$SHC")|$(stat -c %a "$OUT99")" "1000|1|0|0|1|600|700" \
  "c5 its summary prints the runbook's deploy alone, with its own log — never the plain one, a rollback or a flag; \`less\` for its own sheet, 0600 in a folder of 700"
check "$(python3 "$H99/rh99.py" mlsheet "$SHC")|$(grep -cxF "  Q-N2 the app's uCRM clients filed under another organization than #1 — the move-list for uCRM: $(qj "$QX" outside)" "$SHC")$(grep -cxF "  Q-N2 the app's uCRM client ids: $(oj "$O" all) distinct (kyc_applications $(sub98 "$(oj "$O" src)" apps), leads $(sub98 "$(oj "$O" src)" leads), quotes_log $(sub98 "$(oj "$O" src)" quotes)); read: $(oj "$O" cap) (at most 300)" "$SHC")$(grep -cxF "  Q-G5 the last 30 days' Lead and Manual quotes with a uCRM client: $(oj "$O" qg5 | cut -d: -f1), of them with a uCRM quote: $(oj "$O" qg5 | cut -d: -f2)" "$SHC")|$(secrets99 "$SHC")" \
      "$(qj "$QX" sheet)|111|0" \
  "c6 the sheet: the move-list by client id and organization — this rehearsal's own reading of the store and the stand-in, in the probe's order — and the counts behind Q-N2 and Q-G5; no name, contact or key of the stand-in's"
U="$(ureq99 "$T")"; G="$(qget99 "$OUT")"
check "$U|$(uother99 "$T")|$G" "total=$(( 3 + $(qj "$QX" n) )) get=$(( 3 + $(qj "$QX" n) )) other=0 app=$(( 2 + $(qj "$QX" n) )) token=1 none=0 odd=0|-|$(( 3 + $(qj "$QX" n) )):0" \
  "c7 GETs only: the stand-in recorded the organizations through the plugin's key, again through the token, one GET per client id the app holds, and the quotes — nothing but GETs, every one of the four shapes, and exactly as many as the script counts and prints (no other)"
check "$(tok99 "$T")|$(okline "$OUT" Z2 'nothing of the vault, no webhook_secret file, no token copy')|$(find /tmp -maxdepth 6 -path '/tmp/dnb-5.18.99-*' -name token.json 2>/dev/null | grep -c .)" "remote=1 copied=1 left=0|yes|0" \
  "c8 the token copy: made before the probe (there at its call), gone at the next call — the probe deleted it once read — Z2 finds none, and none is left in /tmp"
check "$(secrets99 "$R99/run-$T.txt" "$SHC" "$R99/ucrm/run-$T.req")|$(noids99 "$R99/run-$T.txt")|$(figs99 "$R99/run-$T.txt")|$(bankhits98 "$R99/run-$T.txt" "$SHC")|$(quietobs99 "$T")" "0|0|0|0|0|novault=0 plugin=0|$VT99_0>$VT99_0" \
  "c9 no name, contact or key of the stand-in's in the terminal, the sheet or its record; no client id and no figure in the terminal; no account name or number anywhere; the copy observers quiet — every process on a copy, the quoting ones among them, with a vault of its own — the live vault untouched"
# crm_base_url set in the store row, on the stand-in's second path: the KYC path's token client — and the debug retry's,
# the same client then — reads there, the plugin's own client where ucrm.json says. The stand-in records each request's
# path before /api (prefix.log), never a key.
cfg_keep; row_set crm_base_url "http://127.0.0.1:$UPORT99/crm2/api/v2.1"; : > "$U99/prefix.log"
fresh99; S0="$(states99)"; OUT="$(run99 --check)"; T="$(last99)"
check "$(checkgo99 "$OUT")|$(okline "$OUT" Q-G4b 'token client: organizations read (2)' '(crm_base_url, x-auth-token)' "the debug retry's own client is that one here")$(noteline "$OUT" Q-N1 'crm_base_url unset/same-host')|$(LC_ALL=C sort -u "$U99/prefix.log" | paste -sd, -)|$(ureq99 "$T" | sed -E 's/^total=[0-9]+ get=[0-9]+ //')" \
      "yesyesnono0|0|$S0|yesyes|/crm app-key,/crm2 token|other=0 app=$(( 2 + $(qj "$QX" n) )) token=1 none=0 odd=0" \
  "c9' crm_base_url set in the store row: Q-G4b says the token client reads through crm_base_url, the debug retry's the same client; Q-N1 says it is set in the store row, on the plugin's own uCRM host — and the stand-in saw the token's request on that path, every request of the plugin's own client on ucrm.json's, all GETs"
cfg_back
check "$(data94)" "$D99" "…crm_base_url taken out again: the data as before"
fresh99; OUT="$(run99 --after-only --rollback)"; C15T99="$(last99)"
check "$(has "$OUT" '--after-only, --rollback, --check-bank and --move-list-only are each a run of their own: give one of them')$(went99 "$OUT")|$(quiet99)" "yesnono|$P98|0|$D99|$KEPT99|0" "c10 two modes in one run are refused"
OUT="$(run99 --move-list-only --after-only)"; C15T99="$C15T99 $(last99)"
check "$(has "$OUT" '--after-only, --rollback, --check-bank and --move-list-only are each a run of their own: give one of them')$(went99 "$OUT")|$(quiet99)" "yesnono|$P98|0|$D99|$KEPT99|0" "c10' …the move-list run with another mode too"
for o in --check --redeploy --without-bank-balance --quote-create-unproven --lead-org-differs; do
  OUT="$(run99 --move-list-only "$o")"; C15T99="$C15T99 $(last99)"
  check "$(has "$OUT" '--redeploy, --check, --without-bank-balance, --quote-create-unproven and --lead-org-differs go with a deploy only, never with --after-only, --rollback, --check-bank or --move-list-only')$(went99 "$OUT")|$(quiet99)" "yesnono|$P98|0|$D99|$KEPT99|0" "c11 --move-list-only with $o is refused before anything is read"
done
for m in --after-only --rollback --check-bank; do
  OUT="$(run99 "$m" --quote-company-off)"; C15T99="$C15T99 $(last99)"
  check "$(has "$OUT" '--quote-company-off goes with a deploy, --check or --move-list-only, never with --after-only, --rollback or --check-bank')$(went99 "$OUT")|$(quiet99)" "yesnono|$P98|0|$D99|$KEPT99|0" "c12 --quote-company-off with $m is refused before anything is read"
done
fresh99; OUT="$(run99 --check-bank)"; C15T99="$C15T99 $(last99)"
nogo99 "c13 --check-bank with 5.18.98 live: refused — it reads ECO Bank as 5.18.99 has it" \
  "--check-bank reads ECO Bank as 5.18.99 has it: the container serves $P98 (5.18.98), not $P99 (5.18.99) — deploy it first. Nothing was changed" "$OUT"
fresh99; OUT="$(run99 --after-only)"; C15T99="$C15T99 $(last99)"
nogo99 "c14 --after-only with 5.18.98 live: refused — there is no deploy to check" "the container serves $P98, not $P99" "$OUT"
C15B99=""; for t in $C15T99; do [ "$(ureq99 "$t" | cut -d' ' -f1)" = "total=0" ] || C15B99="$C15B99 $t"; done
check "$(wc -w <<<"$C15T99" | tr -d ' ')|${C15B99:-none}" "12|none" "c15 …and none of the twelve refused runs (c10–c14) asked uCRM anything — each run's own record read, not the last one's alone"

echo; echo "== 99-m. --move-list-only: the move-list on its sheet, nothing else read or changed; its refusals before uCRM is asked =="
fresh99; S0="$(states99)"; FP0="$(fp99)"; O="$(o99 1)"; QX="$(qx99 "$O")"
OUT="$(run99 --move-list-only)"; T="$(last99)"; SHM="$(mlsheet_of "$OUT")"; FP1="$(fp99)"
check "$(mlpass99 "$OUT")" "0yesnono|0|$S0" "m1 --move-list-only PASSES: no failure, never the question, nothing backed up, no state file written"
check "$FP1" "$FP0" "m1' …and nothing changed: the installed tree, the store's bytes and rows, the vault, both settings files, the stray folder's, ucrm.json and the live commit as before"
check "$(okline "$OUT" Q-G1 "installed is 5.18.98 ($P98)")$(okline "$OUT" Q-G2 '(uganda/uganda, yes/yes)')$(okline "$OUT" Q-G3 '(on:1:profile:-)')$(okline "$OUT" Q-G4 '1,2 — the quoting organization #1 among them')$(noteline "$OUT" Q-N2 "one GET each: $(python3 "$H99/rh99.py" qn2say "$(qj "$QX" qn2)") — the $(qj "$QX" outside) filed under another organization are on the sheet by id")|$(python3 "$H99/rh99.py" qg4lines "$R99/run-$T.txt")" \
      "yesyesyesyesyes|$(python3 "$H99/rh99.py" qg4exp "$U99/state.json")" \
  "m2 Q-G1, Q-G2, Q-G3 and Q-G4 hold, and Q-N2 counts the app's clients by organization in this rehearsal's own counts"
M3RX99='^  (ok|note|FAIL) +(A14|A22|A23|A24|BR1|BR2|C[1-5]|G2|G3|Q-G4b|Q-G5|Q-G6|Q-N1|Q-N3|Q-N4|Q-N5|Q-N6|Q-N7) '   # ok() pads its word to four spaces, note() and bad() to two
check "$(grep -cE "$M3RX99" <<<"$OUT")|$(grep -c '^  Q-G4b ' <<<"$OUT")|$( [ "$(grep -cE "$M3RX99" "$R99/run-$TC99.txt")" -ge 10 ] && echo seen)" "0|0|seen" \
  "m3 …and nothing else of stage A runs: no quarantine, no cash, bank or copy check, no token client, no Q-G5, Q-G6 or other note — control: the same reading finds ten or more of those lines, ok, note or FAIL, in --check's run (c1)"
check "$(stat -c %a "$SHM" 2>/dev/null)|$(stat -c %a "$OUT99")|$(python3 "$H99/rh99.py" mlsheet "$SHM")|$(grep -cxF "  Q-N2 the app's uCRM clients filed under another organization than #1 — the move-list for uCRM: $(qj "$QX" outside)" "$SHM")|$(grep -cxF "      less $SHM" <<<"$OUT")|$(secrets99 "$SHM")|$(figs99 "$SHM")" \
      "600|700|$(qj "$QX" sheet)|1|1|0|0" \
  "m4 its sheet: 0600 in a folder of 700 — the move-list by client id and organization, this rehearsal's own reading; \`less\` for it alone; no name, contact or key of the stand-in's, no figure of the book"
check "$(grep -cxF "      $EXP_MOVELIST99" <<<"$OUT")$(grep -c 'deploy-5.18.99.sh --rollback' <<<"$OUT")$(grep -cxF "      $EXP_RUNBOOK99" <<<"$OUT")|$(printed99 "$OUT")|$(has "$OUT" '  in uCRM           move each listed client to organization #1, then run this again')" "100|1|0|0|yes" \
  "m5 its summary: move each listed client to #1, then the move-list run again — its own command alone, with its own log; no deploy, no rollback"
U="$(ureq99 "$T")"
check "$U|$(qgetml99 "$OUT")|$(tok99 "$T")" "total=$(( 1 + $(qj "$QX" n) )) get=$(( 1 + $(qj "$QX" n) )) other=0 app=$(( 1 + $(qj "$QX" n) )) token=0 none=0 odd=0|$(( 1 + $(qj "$QX" n) ))|remote=1 copied=0 left=0" \
  "m6 GETs only, through the plugin's key alone: the organizations and one GET per client id — as many as it prints — and no token copy is ever made for it"
check "$(secrets99 "$R99/run-$T.txt" "$R99/ucrm/run-$T.req")|$(noids99 "$R99/run-$T.txt")|$(quietobs99 "$T")" "0|0|0|novault=0 plugin=0|$VT99_0>$VT99_0" \
  "m7 no stand-in secret and no client id in its terminal; the copy observers quiet; the live vault untouched"
# Q-G1: a file 5.18.99 must leave alone, edited by hand — lib/CrmApiClient.php one line longer; its weakened copy PASSES.
cp -p "$PD/lib/CrmApiClient.php" "$SB/crm99.kept"; edit_installed lib/CrmApiClient.php "$(a99 crmbyte)" "$(r99 crmbyte)"
fresh99; OUT="$(run99 --move-list-only)"
nogo99 "m8 the uCRM client, a file 5.18.99 keeps, edited by hand: NO-GO at Q-G1 by the sha256 pinned for 5.18.98, naming it — nothing read" \
  "NO-GO (Q-G1): the installed plugin is not 5.18.98 as it was reviewed — these installed files do not carry the sha256 this script pins for them: lib/CrmApiClient.php — an edit by hand, or another build" "$OUT"
check "$(python3 "$H99/rh99.py" u99req "$U99/requests.log")" "total=0 get=0 other=0 app=0 token=0 none=0 odd=0" "m8' …uCRM never asked"
M="$(weak99 qg1kept99 "$DEPLOY99" qg1kept)"
fresh99; OUT="$(run99 --script "$M" --move-list-only)"; rm -f "$M"
check "$(mlpass99 "$OUT" | cut -d'|' -f1)|$(okline "$OUT" Q-G1 "installed is 5.18.98 ($P98)")" "0yesnono|yes" "m8'' a copy blind to the kept files' sha256 — Q-G1's weakened copy — PASSES over the edited client, and calls it 5.18.98's (caught)"
cat "$SB/crm99.kept" > "$PD/lib/CrmApiClient.php"; touch -r "$SB/crm99.kept" "$PD/lib/CrmApiClient.php"
# Q-G2: the installed QuoteTaxLine no longer reading the tenant as Uganda — a kept file, so Q-G1 refuses first; then Q-G2 by
# its reading through the installed code; a copy without both PASSES (caught).
cp -p "$PD/lib/QuoteTaxLine.php" "$SB/qtax99.kept"; edit_installed lib/QuoteTaxLine.php "$(a99 qtax)" "$(r99 qtax)"
fresh99; OUT="$(run99 --move-list-only)"
nogo99 "m9 the installed QuoteTaxLine reading the tenant as not Uganda: NO-GO at Q-G1 first — a file 5.18.99 keeps, not 5.18.98's" "these installed files do not carry the sha256 this script pins for them: lib/QuoteTaxLine.php" "$OUT"
M="$(weak99 qg1g2a99 "$DEPLOY99" qg1kept)"
fresh99; OUT="$(run99 --script "$M" --move-list-only)"; rm -f "$M"
nogo99 "m9' …with Q-G1 blind to it, Q-G2 refuses by its own reading: QuoteTaxLine::applies() through the installed code on a copy" \
  "NO-GO (Q-G2): this server is not the Uganda tenant as the installed 5.18.98 code reads it on a copy of its configuration (TenantProfile uganda/uganda, QuoteTaxLine::applies() no/no" "$OUT"
M="$(weak99 qg1g2b99 "$DEPLOY99" qg1kept qg2)"
fresh99; OUT="$(run99 --script "$M" --move-list-only)"; rm -f "$M"
check "$(mlpass99 "$OUT" | cut -d'|' -f1)|$(has "$OUT" 'NO-GO (Q-G2)')" "0yesnono|no" "m9'' a copy without both — Q-G2's weakened copy — PASSES over a tenant the installed code does not read as Uganda (caught)"
cat "$SB/qtax99.kept" > "$PD/lib/QuoteTaxLine.php"; touch -r "$SB/qtax99.kept" "$PD/lib/QuoteTaxLine.php"
check "$(sha256sum "$PD/lib/CrmApiClient.php" "$PD/lib/QuoteTaxLine.php" | cut -c1-64 | paste -sd, -)|$(stat -c '%u:%g' "$PD/lib/CrmApiClient.php" "$PD/lib/QuoteTaxLine.php" | paste -sd, -)|$(quiet99)" \
      "$(git -C "$REPO" show "$P98:$P/lib/CrmApiClient.php" | sha256sum | cut -c1-64),$(git -C "$REPO" show "$P98:$P/lib/QuoteTaxLine.php" | sha256sum | cut -c1-64)|1000:1000,1000:1000|$P98|0|$D99|$KEPT99|0" \
  "…both put back, 5.18.98's, owned by the store's user; nothing else changed"
# Q-G3's three: the setting invalid, the two copies disagreeing, 0/off — each refused, each weakened copy PASSES.
cfg_keep; file_set ucrm_quote_organization_id rh99-invalid; row_set ucrm_quote_organization_id rh99-invalid; D99Q="$(data94)"
fresh99; OUT="$(run99 --move-list-only)"
nogo99 "m10 ucrm_quote_organization_id set and invalid, in both copies: NO-GO at Q-G3, through the release's setting()" \
  "NO-GO (Q-G3): ucrm_quote_organization_id is set on this server, and INVALID (on:-:setting:invalid; files/store row)" "$OUT" "$D99Q"
M="$(weak99 qg3inv99 "$DEPLOY99" qg3inv)"; fresh99; OUT="$(run99 --script "$M" --move-list-only)"; rm -f "$M"
check "$(mlpass99 "$OUT" | cut -d'|' -f1)|$(has "$OUT" '  in uCRM           no quoting organization is in force here')" "0yesnono|yes" "m10' a copy without that refusal PASSES over an invalid setting, with no quoting organization in force (caught)"
cfg_back; cfg_keep; file_set ucrm_quote_organization_id 1; D99Q="$(data94)"
fresh99; OUT="$(run99 --move-list-only)"
nogo99 "m11 the configuration file saying 1 and the store row saying nothing: NO-GO at Q-G3 — the two give the release's setting() different answers" \
  "NO-GO (Q-G3): the configuration files and the store row give the pin's QuoteOrganization::setting() different answers (files on:1:setting:-; store row on:1:profile:-)" "$OUT" "$D99Q"
M="$(weak99 qg3dis99 "$DEPLOY99" qg3dis)"; fresh99; OUT="$(run99 --script "$M" --move-list-only)"; rm -f "$M"
check "$(mlpass99 "$OUT" | cut -d'|' -f1)|$(okline "$OUT" Q-G3 '(on:1:setting:-)')" "0yesnono|yes" "m11' a copy without that refusal PASSES over the two copies disagreeing (caught)"
cfg_back; cfg_keep; file_set ucrm_quote_organization_id 0; row_set ucrm_quote_organization_id 0; D99Q="$(data94)"
fresh99; OUT="$(run99 --move-list-only)"
nogo99 "m12 ucrm_quote_organization_id 0 in both copies: NO-GO at Q-G3 — 5.18.99 would quote as 5.18.98" "NO-GO (Q-G3): ucrm_quote_organization_id is 0/off on this server (off:-:explicit-off:-; files/store row)" "$OUT" "$D99Q"
check "$(grep -cxF "      $(with_flags99 '--move-list-only --quote-company-off' movelist)" <<<"$OUT")$(grep -c 'deploy-5.18.99.sh --rollback' <<<"$OUT")" "10" "m12' …and prints its own command, the move-list run with --quote-company-off, alone, with its own log"
fresh99; S0="$(states99)"; OUT="$(run99 --move-list-only --quote-company-off)"
check "$(mlpass99 "$OUT")|$(noteline "$OUT" Q-G3 "--quote-company-off, the operator's explicit choice: ucrm_quote_organization_id is 0/off on this server (off:-:explicit-off:-)")$(has "$OUT" '  in uCRM           no quoting organization is in force here (Q-G3, the operator')" "0yesnono|0|$S0|yesyes" \
  "m12'' with --quote-company-off it PASSES, Q-G3 a NOTE in the operator's words, and no client needs moving"
M="$(weak99 qg3off99 "$DEPLOY99" qg3off)"; fresh99; OUT="$(run99 --script "$M" --move-list-only)"; rm -f "$M"
check "$(mlpass99 "$OUT" | cut -d'|' -f1)|$(noteline "$OUT" Q-G3 '--quote-company-off')" "0yesnono|yes" "m12''' a copy that takes 0/off as chosen without the flag PASSES over it (caught)"
cfg_back
check "$(data94)|$(vt_host)" "$D99|$VT99_0" "…both copies of the configuration put back; the vault untouched (the quoting keys are no vault keys)"
# Q-G4's three, in uCRM's answers: the quoting organization not listed, listed in another currency, the list unreadable.
u99_set "orgs=$ORGS99_MISSING"; fresh99; OUT="$(run99 --move-list-only)"
nogo99 "m13 uCRM listing organizations 2 and 3, not 1: NO-GO at Q-G4" "NO-GO (Q-G4): uCRM lists organization(s) 2,3, and not the quoting organization #1" "$OUT"
M="$(weak99 qg4has99 "$DEPLOY99" qg4has)"; fresh99; OUT="$(run99 --script "$M" --move-list-only)"; rm -f "$M"
check "$(mlpass99 "$OUT" | cut -d'|' -f1)|$(okline "$OUT" Q-G4 '2,3 — the quoting organization #1 among them')" "0yesnono|yes" "m13' a copy without that refusal PASSES — and calls #1 among them (caught)"
u99_set "orgs=$ORGS99_USD"; fresh99; OUT="$(run99 --move-list-only)"
nogo99 "m14 the quoting organization a USD company in uCRM, the book UGX: NO-GO at Q-G4" "NO-GO (Q-G4): the quoting organization #1 is a USD company in uCRM, and the book's currency is UGX" "$OUT"
M="$(weak99 qg4cur99 "$DEPLOY99" qg4cur)"; fresh99; OUT="$(run99 --script "$M" --move-list-only)"; rm -f "$M"
check "$(mlpass99 "$OUT" | cut -d'|' -f1)" "0yesnono" "m14' a copy without that refusal PASSES over a company in another currency (caught)"
u99_set "orgs_500=true"; fresh99; OUT="$(run99 --move-list-only)"
nogo99 "m15 uCRM's organizations unreadable (500): NO-GO at Q-G4, its reason said" "NO-GO (Q-G4): uCRM's organizations could not be read through the plugin's own client, GET-only (failed:http500)" "$OUT"
M="$(weak99 qg4read99 "$DEPLOY99" qg4read qg4has)"; fresh99; OUT="$(run99 --script "$M" --move-list-only)"; rm -f "$M"
check "$(mlpass99 "$OUT" | cut -d'|' -f1)" "0yesnono" "m15' a copy without that refusal (and Q-G4's next, which an empty list then meets) PASSES over an unreadable list (caught)"
u99_set
# Q-N2's sheet: a copy that writes no move-list line PASSES as the real one does — only the sheet shows it (caught here).
M="$(weak99 qn2sheet99 "$DEPLOY99" qn2sheet)"; fresh99; OUT="$(run99 --script "$M" --move-list-only)"; rm -f "$M"; SHM="$(mlsheet_of "$OUT")"
check "$(mlpass99 "$OUT" | cut -d'|' -f1)|$(python3 "$H99/rh99.py" mlsheet "$SHM")|$(qj "$QX" sheet)" "0yesnono|-|71930452:2;71930454:2;71930458:2;71930456:0" \
  "m16 a copy whose sheet lists no client — Q-N2's weakened copy — PASSES with an empty move-list where this rehearsal's own reading has four clients (caught)"
# A copy of 5.18.99 that stopped part-way: a file it changes installed but not 5.18.98's — refused before anything is read.
cp -p "$PD/lib/KycService.php" "$SB/kyc99.kept"; printf '\n' >> "$PD/lib/KycService.php"
fresh99; OUT="$(run99 --move-list-only)"
nogo99 "m17 a file 5.18.99 changes installed but not 5.18.98's (the KYC service, one byte more): NO-GO at Q-G1, naming it — nothing read" \
  "NO-GO (Q-G1): the container's record says $P98 (5.18.98), but these installed files are not 5.18.98's: lib/KycService.php — " "$OUT"
check "$(grep -cxF "      $EXP_PUTBACK99" <<<"$OUT")$(grep -c 'deploy-5.18.99.sh --rollback' <<<"$OUT")|$(python3 "$H99/rh99.py" u99req "$U99/requests.log")" "10|total=0 get=0 other=0 app=0 token=0 none=0 odd=0" \
  "m17' …it prints the by-hand put-back of 5.18.98 alone, with its own log — no rollback — and uCRM is never asked"
M="$(weak99 qg1base99 "$DEPLOY99" qg1base)"; fresh99; OUT="$(run99 --script "$M" --move-list-only)"; rm -f "$M"
nogo99 "m17'' …with that refusal taken out of a copy, Q-G1 refuses it by the sha256 this script pins for 5.18.98's KYC service" \
  "NO-GO (Q-G1): the installed plugin is not 5.18.98 as it was reviewed — these installed files do not carry the sha256 this script pins for them: lib/KycService.php(not-5.18.98's) — an edit by hand, or another build" "$OUT"
M="$(weak99 qg1both99 "$DEPLOY99" qg1base qg1chg)"; fresh99; OUT="$(run99 --script "$M" --move-list-only)"; rm -f "$M"
check "$(mlpass99 "$OUT" | cut -d'|' -f1)|$(okline "$OUT" Q-G1 "installed is 5.18.98 ($P98)")" "0yesnono|yes" "m17''' a copy without both PASSES over the changed KYC service, and calls it 5.18.98's (caught)"
cat "$SB/kyc99.kept" > "$PD/lib/KycService.php"; touch -r "$SB/kyc99.kept" "$PD/lib/KycService.php"
check "$(installed99 "$P98")|$(quiet99)" "0|$P98|0|$D99|$KEPT99|0" "…every file 5.18.98's again; nothing changed"

echo; echo "== 99-1. a deploy's quoting refusals, each before anything changes; the flags; GETs only; every new check's weakened copy =="
u99_set "orgs=$ORGS99_MISSING"; fresh99; OUT="$(run99 --answer DEPLOY)"
nogo99 "1a the quoting organization not in uCRM's list, DEPLOY typed: NO-GO at Q-G4 before the question — nothing changed" \
  "NO-GO (Q-G4): uCRM lists organization(s) 2,3, and not the quoting organization #1 — after the deploy every Uganda app quote would be refused (R5)" "$OUT"
u99_set "token_401=true"; fresh99; OUT="$(run99 --check)"
nogo99 "1b the admin token refused by uCRM (401): NO-GO at Q-G4b — the KYC form reads uCRM through it before every quote" \
  "NO-GO (Q-G4b): token client: http401 — the KYC form reads uCRM through the admin token (crm_auth_token) before every quote" "$OUT"
check "$(python3 "$H99/rh99.py" u99req "$U99/requests.log" | sed -E 's/^total=[0-9]+ get=[0-9]+ //')|$(grep -c '^GET /organizations token$' "$U99/requests.log")" "other=0 app=$(( 2 + $(qj "$QX99_0" n) )) token=1 none=0 odd=0|1" \
  "1b' …the token's one GET refused by the stand-in, every other request the plugin's, all GETs"
M="$(weak99 qg4b99 "$DEPLOY99" qg4b1 qg4b2)"; fresh99; S0="$(states99)"; OUT="$(run99 --script "$M" --check)"; rm -f "$M"
check "$(checkgo99 "$OUT")" "yesyesnono0|0|$S0" "1b'' a copy without the token client's two refusals says GO over a token uCRM refuses (caught)"
u99_set 'token_orgs=[{"id":2,"name":"RH99 Org Two","currencyCode":"UGX","selected":true}]'; fresh99; OUT="$(run99 --check)"
nogo99 "1c the token client's list without the quoting organization: NO-GO at Q-G4b" "NO-GO (Q-G4b): token client: organizations read, and the quoting organization #1 not among them" "$OUT"
M="$(weak99 qg4b2w99 "$DEPLOY99" qg4b2)"; fresh99; S0="$(states99)"; OUT="$(run99 --script "$M" --check)"; rm -f "$M"
check "$(checkgo99 "$OUT")" "yesyesnono0|0|$S0" "1c' a copy without that refusal says GO over it (caught)"
u99_set
# Q-G3's 0/off on a deploy: refused, its own command printed; --check with the flag GO, the flag in the deploy it prints;
# typed where nothing needed it, said so and dropped.
cfg_keep; file_set ucrm_quote_organization_id 0; row_set ucrm_quote_organization_id 0; D99Q="$(data94)"
fresh99; OUT="$(run99 --check)"
nogo99 "1d ucrm_quote_organization_id 0 in both copies, --check: NO-GO at Q-G3" "NO-GO (Q-G3): ucrm_quote_organization_id is 0/off on this server (off:-:explicit-off:-; files/store row)" "$OUT" "$D99Q"
check "$(grep -cxF "      $(with_flags99 --quote-company-off deploy)" <<<"$OUT")$(grep -c 'deploy-5.18.99.sh --rollback' <<<"$OUT")" "10" "1d' …it prints the deploy with --quote-company-off alone, with its own log — the operator's choice, never this script's"
fresh99; S0="$(states99)"; OUT="$(run99 --check --quote-company-off)"
check "$(checkgo99 "$OUT")|$(noteline "$OUT" Q-G3 "--quote-company-off, the operator's explicit choice")$(okline "$OUT" Q-G4 'no quoting organization is in force here (Q-G3), so none is looked for')$(has "$OUT" '  Q-G6            not asked: no quoting organization is in force (Q-G3)')|$(has "$OUT" '  --check           stage A alone, every refusal and note of a deploy with --quote-company-off:')$(grep -cxF "      $(runbook_with99 --quote-company-off)" <<<"$OUT")$(grep -cxF "      $EXP_RUNBOOK99" <<<"$OUT")" \
      "yesyesnono0|0|$S0|yesyesyes|yes10" \
  "1d'' --check --quote-company-off: GO, Q-G3 a NOTE, nothing looked for in uCRM's list or the lead setting; its summary prints the runbook's deploy WITH the flag, alone"
cfg_back
fresh99; S0="$(states99)"; OUT="$(run99 --check --quote-company-off --quote-create-unproven --lead-org-differs)"
check "$(checkgo99 "$OUT")|$(has "$OUT" '(typed and not needed: --quote-company-off --quote-create-unproven --lead-org-differs — each check held without it; the deploy below is printed without it)')$(grep -cxF "      $EXP_RUNBOOK99" <<<"$OUT")$(grep -c -- '--quote-company-off\|--quote-create-unproven\|--lead-org-differs' <<<"$(sed -n '/^== F\. Summary/,$p' <<<"$OUT" | grep '^      ')")|$(noteline "$OUT" Q-G3 '--quote-company-off')$(noteline "$OUT" Q-G5 '--quote-create-unproven')$(noteline "$OUT" Q-G6 '--lead-org-differs')" \
      "yesyesnono0|0|$S0|yes10|nonono" \
  "1e the three flags typed over checks that hold: GO, each said typed and not needed, none a NOTE, and the runbook's deploy printed without any of them"
# Q-G5: most recent Lead and Manual quotes with a client have no uCRM quote.
db_keep; RP99="$(rows_add99 "$PLANT99_QG5")"; OP="$(o99 1)"; D99Q="$(data94)"
check "$RP99|$(oj "$OP" qg5)|$( [ $(( 2 * $(oj "$OP" qg5 | cut -d: -f2) )) -lt "$(oj "$OP" qg5 | cut -d: -f1)" ] && echo fewer-than-half)" "{\"rows\":2}|$(( $(oj "$O99_0" qg5 | cut -d: -f1) + 2 )):$(oj "$O99_0" qg5 | cut -d: -f2)|fewer-than-half" \
  "1f the plant: two more Manual quotes with a client and no uCRM quote, through the installed code's own writer — fewer than half now have one, in this rehearsal's own count"
fresh99; OUT="$(run99 --check)"
nogo99 "1f' --check over it: NO-GO at Q-G5" "NO-GO (Q-G5): of $(oj "$OP" qg5 | cut -d: -f1) Lead or Manual quote(s) with a uCRM client in the last 30 days, $(oj "$OP" qg5 | cut -d: -f2) have a uCRM quote" "$OUT" "$D99Q"
check "$(grep -cxF "      $(with_flags99 --quote-create-unproven deploy)" <<<"$OUT")" "1" "1f'' …it prints the deploy with --quote-create-unproven alone, with its own log"
fresh99; S0="$(states99)"; OUT="$(run99 --check --quote-create-unproven)"
check "$(checkgo99 "$OUT")|$(noteline "$OUT" Q-G5 "--quote-create-unproven, the operator's explicit choice: of $(oj "$OP" qg5 | cut -d: -f1) Lead or Manual quote(s)")|$(grep -cxF "      $(runbook_with99 --quote-create-unproven)" <<<"$OUT")" "yesyesnono0|0|$S0|yes|1" \
  "1f''' with the flag: GO, Q-G5 a NOTE in the operator's words, the runbook's deploy printed with it"
M="$(weak99 qg5w99 "$DEPLOY99" qg5)"; fresh99; S0="$(states99)"; OUT="$(run99 --script "$M" --check)"; rm -f "$M"
check "$(checkgo99 "$OUT")|$(noteline "$OUT" Q-G5 '--quote-create-unproven')" "yesyesnono0|0|$S0|no" "1f'''' a copy without that refusal says GO over it, with no word of it (caught)"
db_back
# Q-G6: the lead organization another.
cfg_keep; file_set ucrm_lead_organization_id 2; row_set ucrm_lead_organization_id 2; D99Q="$(data94)"
fresh99; OUT="$(run99 --check)"
nogo99 "1g ucrm_lead_organization_id 2 in both copies: NO-GO at Q-G6" "NO-GO (Q-G6): ucrm_lead_organization_id names organization 2/2 (files/store row, - unset), not the quoting organization #1" "$OUT" "$D99Q"
check "$(grep -cxF "      $(with_flags99 --lead-org-differs deploy)" <<<"$OUT")" "1" "1g' …it prints the deploy with --lead-org-differs alone, with its own log"
fresh99; S0="$(states99)"; OUT="$(run99 --check --lead-org-differs)"
check "$(checkgo99 "$OUT")|$(noteline "$OUT" Q-G6 "--lead-org-differs, the operator's explicit choice: ucrm_lead_organization_id names organization 2/2")|$(grep -cxF "      $(runbook_with99 --lead-org-differs)" <<<"$OUT")" "yesyesnono0|0|$S0|yes|1" \
  "1g'' with the flag: GO, Q-G6 a NOTE in the operator's words, the runbook's deploy printed with it"
M="$(weak99 qg6w99 "$DEPLOY99" qg6)"; fresh99; S0="$(states99)"; OUT="$(run99 --script "$M" --check)"; rm -f "$M"
check "$(checkgo99 "$OUT")" "yesyesnono0|0|$S0" "1g''' a copy without that refusal says GO over it (caught)"
cfg_back
check "$(data94)|$(vt_host)|$(quiet99)" "$D99|$VT99_0|$P98|0|$D99|$KEPT99|0" "…the book and both copies of the configuration put back; the vault and the quarantine as before"
# A release that asks uCRM a POST: refused by the sha256 this script pins; pinned to it, by the GET-only count; with the
# count blind, GO over it (caught); with the wrapper weakened, the POST reaches the stand-in (caught by its record).
V99P="$(release_variant99 lib/QuoteOrganization.php "$(a99 qoorgs)" "$(r99 qoorgs)")"
V99P_SHA="$(git -C "$REPO" show "$V99P:$P/lib/QuoteOrganization.php" | sha256sum | cut -c1-64)"
check "$(git -C "$REPO" diff --name-only "$P99" "$V99P" | paste -sd' ' -)|$(git -C "$REPO" rev-parse --short "$V99P^")|$(git -C "$REPO" show "$V99P:$P/lib/QuoteOrganization.php" | grep -c 'RH99: a request that is not a GET')" "$P/lib/QuoteOrganization.php|$P98|1" \
  "1h the variant: the release commit with a POST planted before QuoteOrganization's read of the organizations (caught, then ignored), cut on $P98"
S="$(pinned_copy99 v99post "$V99P" "$DEPLOY99")"; fresh99; OUT="$(run99 --script "$S" --check)"; rm -f "$S"
nogo99 "1h' a copy pinned to it: refused at A0 — the pin's QuoteOrganization is not the reviewed one by the sha256 this script pins" \
  "the pin's files are not the reviewed 5.18.99's by sha256: lib/QuoteOrganization.php — this script is for another build. Nothing was changed" "$OUT"
S="$(pinned_copy99 v99post2 "$V99P" "$DEPLOY99" lib/QuoteOrganization.php "$V99P_SHA")"
fresh99; OUT="$(run99 --script "$S" --check)"; T="$(last99)"
nogo99 "1h'' …with that file's sha256 re-pinned too: NO-GO at Q-G4 — the probe asked uCRM something other than a GET; the wrapper refused it" \
  "NO-GO (Q-G4): the probe asked uCRM something other than a GET, or its count of requests could not be read (other 2; counted)" "$OUT"
check "$(uother99 "$T")|$(ureq99 "$T" | sed -E 's/ app=.*//')" "-|total=$(( 3 + $(qj "$QX99_0" n) )) get=$(( 3 + $(qj "$QX99_0" n) )) other=0" "1h''' …and the stand-in received no POST: every request it recorded a GET"
M="$(weak99 qg4getw99 "$S" qg4get)"; fresh99; S0="$(states99)"; OUT="$(run99 --script "$M" --check)"; rm -f "$M"
check "$(checkgo99 "$OUT")|$(qget99 "$OUT" | cut -d: -f2)" "yesyesnono0|0|$S0|2" "1h'''' a copy blind to the count says GO — over a summary that says two requests other than a GET were asked (caught)"
M="$(weak99 wrappostw99 "$S" wrappost)"; fresh99; S0="$(states99)"; OUT="$(run99 --script "$M" --check)"; T="$(last99)"; rm -f "$M" "$S"
printf '%s\n' "$T" >> "$R99/expected-posts"
check "$(checkgo99 "$OUT")|$(qget99 "$OUT" | cut -d: -f2)|$(uother99 "$T")" "yesyesnono0|0|$S0|0|POST /organizations app-key;POST /organizations token" \
  "1h''''' a copy whose GET-only wrapper passes a POST on: GO, its count clean — and the stand-in received both POSTs, the plugin's and the token client's (caught by its record)"
# Q-N3, Q-N4 and the token copy: weakened copies the script's own verdict does not see, each caught by this rehearsal's reading.
M="$(weak99 qn3w99 "$DEPLOY99" qn3)"; fresh99; OUT="$(run99 --script "$M" --check)"; rm -f "$M"
check "$(checkgo99 "$OUT" | cut -d'|' -f1)|$(noteline "$OUT" Q-N3 'the newest uCRM quote: #88017402')" "yesyesnono0|no" "1i a copy that keeps the wrong field of uCRM's newest quote says GO — but its Q-N3 no longer names the stand-in's newest, #88017402 (caught)"
M="$(weak99 qn4w99 "$DEPLOY99" qn4other)"; fresh99; OUT="$(run99 --script "$M" --check)"; rm -f "$M"
check "$(checkgo99 "$OUT" | cut -d'|' -f1)|$(noteline "$OUT" Q-N4 "$(sub98 "$(oj "$O99_0" qn4)" otherorg) whose saved request names another organization")" "yesyesnono0|no" "1j a copy that never counts a pending application naming another organization says GO — its Q-N4 says 0 where this rehearsal counts 1 (caught)"
M="$(weak99 tokunlinkw99 "$DEPLOY99" tokunlink)"; fresh99; OUT="$(run99 --script "$M" --check)"; T="$(last99)"; rm -f "$M"
printf '%s\n' "$T" >> "$R99/expected-toks"
check "$(checkgo99 "$OUT" | cut -d'|' -f1)|$(okline "$OUT" Z2 'no token copy')|$(tok99 "$T")|$(find /tmp -maxdepth 6 -path '/tmp/dnb-5.18.99-*' -name token.json 2>/dev/null | grep -c .)" "yesyesnono0|yes|remote=1 copied=1 left=1|0" \
  "1k a copy whose probe reads the token copy and leaves it says GO, Z2 clean — the script's own removal came after it — but the token observer sees the copy still there at the next call (caught)"

echo; echo "== 99-2. 5.18.98's carried checks still bite: ECO Bank's bytes, its switch, C5, South Sudan =="
cp -p "$PD/lib/BankGate.php" "$SB/bankgate99.kept"; printf '// edited by hand on the server\n' >> "$PD/lib/BankGate.php"
fresh99; OUT="$(run99 --answer NO)"
nogo99 "2a ECO Bank's gate edited by hand on the server: NO-GO at BR1 — every file 5.18.98 tracks, by sha256 — naming it" \
  "NO-GO (BR1): the installed plugin is not 5.18.98's as $P98 has it — these files differ or are missing (at most five named): lib/BankGate.php" "$OUT"
M="$(weak99 br1w99 "$DEPLOY99" br1)"; fresh99; OUT="$(run99 --answer NO --script "$M")"; rm -f "$M"
nogo99 "2a' …and with BR1's refusal taken out of a copy, Q-G1 refuses it by the sha256 this script pins for 5.18.98's gate" \
  "NO-GO (Q-G1): the installed plugin is not 5.18.98 as it was reviewed — these installed files do not carry the sha256 this script pins for them: lib/BankGate.php — an edit by hand, or another build" "$OUT"
M="$(weak99 br1g1w99 "$DEPLOY99" br1 qg1g1)"; fresh99; OUT="$(run99 --answer NO --script "$M")"; rm -f "$M"
check "$(went99 "$OUT")$(has "$OUT" 'STOP: not confirmed')$(okline "$OUT" Q-G1 'installed is 5.18.98')" "yesyesyesyes" "2a'' a copy without both reaches the question over the edited gate and calls it 5.18.98's (caught)"
cat "$SB/bankgate99.kept" > "$PD/lib/BankGate.php"; touch -r "$SB/bankgate99.kept" "$PD/lib/BankGate.php"
check "$(sha256sum "$PD/lib/BankGate.php" | cut -c1-64)|$(stat -c '%u:%g' "$PD/lib/BankGate.php")|$(live)|$(data94)|$(kept_state)" "$(git -C "$REPO" show "$P98:$P/lib/BankGate.php" | sha256sum | cut -c1-64)|1000:1000|$P98|$D99|$KEPT99" "…the gate put back, 5.18.98's; nothing else changed"
cfg_keep; cfg_set "$BK97" off; D99O="$(data94)"
fresh99; OUT="$(run99 --check)"
nogo99 "2b ECO Bank's switch cleared in both copies: NO-GO at G3 — ECO Bank closed" "NO-GO (G3): not both ECO BANK cards show a figure (ECO-Bank-closed(switch:absent," "$OUT" "$D99O"
cfg_back
check "$(both_copies "$BK97")|$(data94)" "11|$D99" "…the switch put back in both copies"
# C5 against a planted write — the library is a file 5.18.99 keeps, so the variant is refused at A0 by the kept files'
# sha256 in both commits; with A0's four delta checks taken out of the copy, the copy checks refuse it at C5; without C5's
# judgement too, GO (caught).
V99W="$(release_variant99 lib/CashbookService.php "$(a99 cbwrite)" "$(r99 cbwrite)")"
check "$(git -C "$REPO" diff --name-only "$P99" "$V99W" | paste -sd' ' -)|$(git -C "$REPO" rev-parse --short "$V99W^")|$(git -C "$REPO" show "$V99W:$P/lib/CashbookService.php" | grep -c 'RH99: a write planted on a read path')" "$P/lib/CashbookService.php|$P98|1" \
  "2c the variant: the release commit with a write planted on the library's list predicate — on every read that asks for the split — cut on $P98"
S="$(pinned_copy99 v99write "$V99W" "$DEPLOY99")"; fresh99; OUT="$(run99 --script "$S" --check)"
nogo99 "2c' a copy pinned to it: refused at A0 — the delta holds a file that is not 5.18.99's" "the pin carries files that are not 5.18.99's, which this release must not ship: lib/CashbookService.php" "$OUT"
M="$(weak99 c5open99 "$S" a0stray a0ps a0ks a0pc)"; fresh99; OUT="$(run99 --script "$M" --check)"; rm -f "$M"
nogo99 "2c'' …with A0's delta checks taken out of the copy, the copy checks refuse it: C5 — a digest moved under the new code's reads" \
  "NO-GO (C1–C5): the copy checks do not hold on this book — C5:a-digest-moved-under-the-new-code's-reads" "$OUT"
M="$(weak99 c5blind99 "$S" a0stray a0ps a0ks a0pc c5j)"; fresh99; S0="$(states99)"; OUT="$(run99 --script "$M" --check)"; rm -f "$M" "$S"
check "$(checkgo99 "$OUT")|$(okline "$OUT" C5 "unchanged by every read of 5.18.99's code")" "yesyesnono0|0|$S0|yes" "2c''' a copy without C5's judgement says GO over the planted write, and calls the digests unchanged (caught)"
# South Sudan: rule A on for every tenant in the release's library — T1's synthetic books see it first; with T1's verdict
# taken out, A24 refuses: the books where the rule is off read differently under the pin; without A24's too, GO (caught).
V99G="$(release_variant99 lib/CashbookService.php "$(a99 cbgate)" "$(r99 cbgate)")"
S="$(pinned_copy99 v99gate "$V99G" "$DEPLOY99")"
M="$(weak99 ssopen99 "$S" a0stray a0ps a0ks a0pc a22t1)"; fresh99; OUT="$(run99 --script "$M" --check)"; rm -f "$M"
nogo99 "2d a release whose library turns rule A on for every tenant (A0 and T1's verdict taken out of the copy): NO-GO at A24 — South Sudan's books read differently under the pin" \
  "NO-GO (A24, South Sudan): the pin does not keep the books where the rule is off exactly as 5.18.98 reads them" "$OUT"
check "$(has "$OUT" 'could not be made: pin-signature outputs-differ')" "yes" "2d' …naming both: the pin's signature on those books, and their figures"
M="$(weak99 ssblind99 "$S" a0stray a0ps a0ks a0pc a22t1 a24)"; fresh99; S0="$(states99)"; OUT="$(run99 --script "$M" --check)"; rm -f "$M" "$S"
check "$(checkgo99 "$OUT")" "yesyesnono0|0|$S0" "2d'' a copy without A24's refusal says GO over South Sudan read with the rule on (caught)"
check "$(quiet99 | cut -d'|' -f1,3-)|$(sha256sum "$QREC" | cut -c1-16)|$(vt_host)" "$P98|$D99|$KEPT99|0|$QREC99|$VT99_0" "…after sections 1 and 2: 5.18.98 live, the data, the quarantine and the live vault as before"

echo; echo "== 99-4. the deploy, as the operator runs it — DEPLOY typed =="
O_PRE="$(cash99 pre)"; OB_PRE="$(o97)"; NP_PRE="$(o98)"; OQ="$(o99 1)"; QX="$(qx99 "$OQ")"
LEDGER99="$(ledger95)"; DX99="$(data98x)"; BOOK99="$(book98x)"
fresh99; OUT="$(run99 --answer DEPLOY)"; OUT99D="$OUT"; T99D="$(last99)"; SHEET99D="$(sheet_of "$OUT")"
echo "  …    the deploy's own tally:$(grep -E '^  checks +[0-9]+ ok, ' <<<"$OUT" | sed -E 's/^  checks +/ /')"
check "$(fails "$OUT")$(has "$OUT" '5.18.99 (deploy): PASSED')$(live)" "0yes$P99" "the deploy PASSES: 0 failed, the container serves $P99"
for l in "ok    A0 the release delta is exactly 5.18.99's 36 files — 6 added, 30 changed, each the reviewed one by the sha256 this script pins (36 of 36) — and no migration: 088_bank_statements.sql the reviewed one in both release commits (sha256 ${MIG88_SHA99:0:16}…)" \
         "ok    A7 Domain B untouched" "ok    A22L the live code, as installed, carries all seven of 5.18.95's fixes" \
         "ok    BR1 the installed plugin is live 5.18.98 ($P98): all " \
         "ok    Q-G1 installed is 5.18.98 ($P98): its manifest says 5.18.98; all 30 files 5.18.99 changes carry the sha256 pinned for 5.18.98, and all 31 files it must not touch" \
         "ok    A14 the stray store is quarantined and the quarantine holds" "ok    A22-T1 under this server's own PHP" "($T1SIG99)" \
         "ok    BK1 cash in hand, 5.18.98's code (installed) and 5.18.99's on the same copy, the same to the last digit in all 8 cells" \
         "ok    G2 rule A is on, read through the INSTALLED code on a copy" "ok    BR2 088 is recorded on the copy and intact" \
         "ok    G3 both ECO BANK cards show a figure, through the live 5.18.98 classes on the copy" \
         "ok    C1 cash in hand, 5.18.98's code from Git and 5.18.99's from Git on ONE copy of the book" "ok    C2 the Cashbook's list with no key" \
         "ok    C3 on 5.18.99's code: per project × view" "ok    C4 countEntries()" "ok    C5 the copy's row digest" \
         "ok    A24 South Sudan, a South Sudan tenant without SSP and a Uganda tenant with SSP read exactly as under 5.18.98" \
         "ok    Q-G2 the tenant is Uganda" "ok    Q-G3 quoting organization #1 (from the Uganda profile)" "ok    Q-G4 uCRM's organizations, read once through the plugin's own client" \
         "ok    Q-G4b token client: organizations read (2)" "ok    Q-G5 the last 30 days' Lead and Manual quotes with a uCRM client: 3, 2 of them with a uCRM quote" \
         "ok    Q-G6 ucrm_lead_organization_id is unset" "GO — evidence recorded" \
         "checking out $P99 (5.18.99, the release commit) for the documented deploy" "ok    container serves $P99" \
         "ok    V4 no fatal or parse error of" "ok    CA4 the Cashbook (?page=dashboard&tab=cashbook), its tab" \
         "ok    Q-CA4 Sync All is admin-only: the installed admin-only gate (\$_gateAdminOnly, read as text) names push_products_to_ucrm, and its route (dry_run=1) answers a visitor with no session 401" \
         "ok    Q-CA5 the KYC form, Send Quotation, Quote History, Orders and the LTE dashboard without a session each send the visitor to the sign-in page (302)" \
         "ok    V3j ECO Bank's switch, $BK97, is unchanged by this run and ON (before=bk=on/on, after=bk=on/on; files/store)" \
         "ok    R1 all 36 files 5.18.99 changes are installed exactly as $P99 has them (6 new)" "ok    R1 the installed manifest says 5.18.99" \
         "ok    Q-CA1 installed = 5.18.99's release: all 36 of its files carry the sha256 this script pins; all 31 files of 5.18.98 it must not touch" \
         "ok    R5 all" "ok    CA3 no drift since A23" "ok    CA2 C1–C5 again, on a NEW copy, 5.18.98's code from Git and the INSTALLED 5.18.99" \
         "ok    BA2 G3 again, through the installed code on the new copy: ECO Bank open, and both ECO BANK cards show a figure" \
         "ok    R25 under this server's own PHP the installed T1 passes ($T1SIG99)" \
         "ok    R26 South Sudan, a South Sudan tenant without SSP and a Uganda tenant with SSP read exactly as under 5.18.98 through the installed lib" \
         "ok    Q-CA2 the INSTALLED QuoteOrganization::setting() on a copy reads what stage A read through the pin's: on:1:profile:- — the configuration files and the store row alike" \
         "ok    Q-CA2 control: the same copy with tenant_profile=south-sudan, in a PHP process of its own → off (off:-:off:-)" \
         "ok    Q-CA2 control: the same copy with ucrm_quote_organization_id=0, in a PHP process of its own → explicit-off" \
         "ok    Q-CA3 nothing was created in uCRM: every request this run made of it, through its GET-only wrappers, was a GET" \
         "ok    Q-CA3 uCRM's organizations, through the INSTALLED QuoteOrganization::organizations(), wrapped GET-only: 1,2 — what stage A read (Q-G4)" \
         "ok    Q-CA3 checkClient() on a client of the quoting organization #1 (its id on the sheet) → pass" \
         "ok    Q-CA3 checkClient() on a client outside it (its id on the sheet) → refused: filed under organization #2" \
         "ok    Q-CA3 the same two checks through the token client (built as KycService::postQuote builds it, its copy removed once read): pass, refused:R1" \
         "  Q-CA3           the guard's refusal lines went to this run's own sink: 2 — never to the plugin's log" \
         "ok    Q-CA3 the probe's refusal lines reached neither its code copy's data/plugin.log nor its PHP error log (0 and 0) — its own sink took them; control: the same guard with no sink, on the same copy, wrote its one line to each (1 and 1)" \
         "  Q-CA3           the live data/plugin.log gained no line labelled this run's or naming a probed client while the probe ran" "ok    Q-CA3 the newest uCRM quote is stage A's (#88017402, created since " \
         "ok    Q-CA6 the installed profiles, through the installed TenantProfile: profiles/uganda.json gives quoting.ucrm_organization_id 1, South Sudan none" \
         "ok    Q-CA6 the installed tools/set_config.php lists ucrm_quote_organization_id as text" "ok    Q-CA6 the installed tools/set_config.php, from the copy of the installed code on a throwaway data folder" \
         "ok    Z1 the live vault" "ok    Z2 nothing of the vault, no webhook_secret file, no token copy" \
         "  names             Q-G1–Q-G6, Q-N1–Q-N7 and Q-CA1–Q-CA6 are SPEC-quote-company §6's own" \
         "  what changed      on this Uganda server, the quoting organization is #1 (Q-G3, Q-CA2)"; do
  check "$(has "$OUT" "$l")" "yes" "4: ${l:0:110}"
done
check "$(ctl99 "$OUT" A22)$(ctl99 "$OUT" R23)|$(ctl98 "$OUT" A22)$(ctl98 "$OUT" R23)" "77|00" \
  "5.18.95's seven fixes read with their controls before (A22) and after (R23), in this release's words — and the previous release's reader finds none of them here"
check "$(noteids98 "$OUT")" "A17 A23.4 A23.5 A23.6 BN5 G3 N1 N2 N3 N4 Q-N1 Q-N2 Q-N3 Q-N4 Q-N5 Q-N6 Q-N7 R19 R23.9" \
  "its notes are exactly 5.18.98's on this book (A17, A23.4–A23.6, BN5, G3, N1–N4, R19, R23.9) and Q-N1–Q-N7"
check "$(sed -n 's/^QG3_A=//p' "$STATE99" | tail -1)|$(sed -n 's/^QG4_A=//p' "$STATE99" | tail -1)|$(sed -n 's/^QN3_A=//p' "$STATE99" | tail -1)|$(sed -n 's/^QOFF_NOTED=//p' "$STATE99" | tail -1)|$(sed -n 's/^QG5_NOTED=//p' "$STATE99" | tail -1)|$(sed -n 's/^QG6_NOTED=//p' "$STATE99" | tail -1)|$(sed -n 's/^REDEPLOY=//p' "$STATE99" | tail -1)|$(sed -n 's/^BK_BEFORE=//p' "$STATE99" | tail -1)" \
      "on:1:profile:-|1,2|88017402||||bk=on/on" \
  "4a the state file records stage A's readings of the quoting organization — the setting's answer, uCRM's organization ids, the newest quote's id — and no flag, none having decided a check; ECO Bank's switch as found"
QS99="$(sed -n 's/^QN3_SINCE=//p' "$STATE99" | tail -1)"
check "$(grep -cE '^QN3_SINCE=[0-9]{4}-[0-9]{2}-[0-9]{2}$' "$STATE99")|$(noteline "$OUT" Q-N3 "created since $QS99")$(okline "$OUT" Q-CA3 "the newest uCRM quote is stage A's (#88017402, created since $QS99: stage A's window, read again)")" "1|yesyes" \
  "4a' …and Q-N3's window, the date its quotes were read from: stage A's note names it, and stage R read the same window again"
check "$(grep -c "^COPY_AT=$(stat -c %Y "$PD/lib/KycService.php")\$" "$STATE99")$(grep -c '^Q_HOLDS_BEFORE=yes$' "$STATE99")$(grep -c '^STRAY_Q_FAILED=' "$STATE99")|$(grep -cwF -f "$SB/ids99.pat" "$STATE99")|$(secrets99 "$STATE99")" "110|0|0" \
  "4b …the copy's time as stamp_copy gave lib/KycService.php, the quarantine held before, no failure — and no client id, name, contact or key"
check "$(python3 "$H99/rh99.py" mlsheet "$SHEET99D")|$(stat -c %a "$SHEET99D")|$(grep -cxF "      less $SHEET99D" <<<"$OUT")|$(sheetsha_of "$OUT")|$(secrets99 "$SHEET99D")|$(python3 "$H96/rh96.py" sheet "$SHEET99D" "$O_PRE" A23)" \
      "$(qj "$QX" sheet)|600|1|$(sha256sum "$SHEET99D" | cut -c1-16)|0|8/8" \
  "4c the sheet: stage A's move-list by id, this rehearsal's own reading; 0600; \`less\` for it alone and its sha256-16 in F; no name, contact or key; A23's figures this rehearsal's own SQL before the run"
QCA3L99="  Q-CA3 checkClient() through the installed code, the quoting organization #1: inside it client #$(qj "$QX" in) → pass, outside it client #$(qj "$QX" out) → refused:R1; through the token client: pass, refused:R1"
check "$(grep -cxF "$QCA3L99" "$SHEET99D")" "1" "4c' …and stage R's two Q-CA3 clients, by id, with what each answered through both clients — on the sheet, where the log points"
U="$(ureq99 "$T99D")"; G="$(qget99 "$OUT")"
check "$U|$(uother99 "$T99D")|$G" "total=$(( 12 + $(qj "$QX" n) )) get=$(( 12 + $(qj "$QX" n) )) other=0 app=$(( 7 + $(qj "$QX" n) )) token=5 none=0 odd=0|-|$(( 12 + $(qj "$QX" n) )):0" \
  "4d GETs only, across the whole deploy: stage A's reads and stage R's — the organizations twice per client, the two probed clients and the list behind the refusal's words, through each client, and the newest quote — exactly as many as the script counts"
check "$(tok99 "$T99D")|$(find /tmp -maxdepth 6 -path '/tmp/dnb-5.18.99-*' -name token.json 2>/dev/null | grep -c .)" "remote=2 copied=2 left=0|0" "4e the token copy made for each of the two probes and gone at the next call each time; none left"
check "$(secrets99 "$R99/run-$T99D.txt" "$R99/ucrm/run-$T99D.req" "$OUT99"/*.log 2>/dev/null)|$(noids99 "$R99/run-$T99D.txt" "$STATE99")|$(figs99 "$R99/run-$T99D.txt" "$STATE99")|$(bankhits98 "$R99/run-$T99D.txt" "$STATE99")" "0|0|0|0" \
  "4f no name, contact or key of the stand-in's, no client id, no figure and no account name or number in the terminal, the state file or the request record"
check "$(hits99 "$T99D")|$(audit99 "$T99D" | sed -E 's/^copies=([0-9]+) /\1 /' | awk '{print ($1 >= 20 ? "many" : $1 " only") " " $2 " " $3}')|$(vault99 "$T99D")" "0|many novault=0 plugin=0|$VT99_0>$VT99_0" \
  "4g the copy observers: nothing planted in /tmp; every PHP process on a copy — twenty or more, the quoting probes' among them — carried a vault of its own; the live vault the same"
SUM99="$(sed -n '/^== F\. Summary ==$/,$p' <<<"$OUT")"
check "$(grep -cxF "      $EXP_AFTER99" <<<"$SUM99")$(grep -cxF "      $EXP_QCHECK" <<<"$SUM99")$(grep -cxF "      $EXP_ROLLBACK99" <<<"$SUM99")$(grep -cxF "      $EXP_PUTBACK99" <<<"$SUM99")$(grep -cxF "      $EXP_DEPLOY99" <<<"$OUT")$(grep -cxF "      $EXP_REDEPLOY99" <<<"$OUT")$(grep -cxF "      $EXP_CHECKBANK99" <<<"$OUT")|$(printed99 "$OUT")|$(order99 "$OUT")|$(rb_where99 "$OUT")" \
      "1111000|4|0|0|verdict<rollback<putback|after" \
  "4h every later command printed exactly, on its own line — the --after-only, the quarantine's --check, the rollback and the put-back — the rollback after the verdict, once, then the put-back; no line holding two"
check "$(installed99 "$P99")$(inst_ver)$(data98x)$(book98x)|$(ledger95)|$(both_copies "$BK97")" "05.18.99$DX99$BOOK99|$LEDGER99|11" \
  "4i every file 5.18.98 → 5.18.99 changes installed as the pin has it; the plugin's data and the book as before; the migration ledger unchanged; ECO Bank's switch on in both places"
check "$(kept_state)$(oldname)$(sha256sum "$QREC" | cut -c1-16)$(host_code99)$(left_tmp99)$(evo_calls)" "${KEPT99}0${QREC99}0${TMP99}0" \
  "4j the deploy left the quarantine as it was, no copy of the code on the host or in /tmp, and never called Evolution"

echo; echo "== 99-5. --after-only, run exactly as the deploy's summary printed it; each after-check's plant and weakened copy =="
copy_back99 30; COPY99="$(sed -n 's/^COPY_AT=//p' "$STATE99" | tail -1)"
set_sched $(( COPY99 + 300 )); base_log
ALINE="$(printed "$OUT99D" "cd $REPO && bash scripts/deploy-5.18.99.sh --after-only")"
check "$ALINE" "$EXP_AFTER99" "5 the --after-only command, as the deploy's summary printed it"
fresh99; OUT="$(run_line99 '' "$ALINE")"; TA="$(last99)"; OUT99A="$OUT"; ALOG="$(ls -1 "$OUT99"/after-*.log 2>/dev/null)"
check "$(fails "$OUT")$(has "$OUT" '5.18.99 (after): PASSED')$(okline "$OUT" R19 'the quarantine holds: master ran the retry job at' "after the copy (at $(utc "$COPY99"))")" "0yesyes" "5a --after-only PASSES, R19 conclusive"
check "$(okline "$OUT" Q-CA2 'reads what stage A read through the pin' 'on:1:profile:-')$(okline "$OUT" Q-CA3 'checkClient() on a client of the quoting organization #1 (its id on the sheet) → pass')$(okline "$OUT" Q-CA3 'refused: filed under organization #2')$(okline "$OUT" Q-CA3 'the same two checks through the token client' 'pass, refused:R1')$(okline "$OUT" Q-CA3 'the newest uCRM quote is stage A' '#88017402')$(okline "$OUT" Q-CA6 'South Sudan none')$(okline "$OUT" Q-CA1 "installed = 5.18.99's release")$(okline "$OUT" Z2 'no token copy')" "yesyesyesyesyesyesyesyes" \
  "5a' Q-CA2 against the deploy's recorded reading, Q-CA3 on the two clients it picks again from Q-N2's list — one in the quoting organization, one outside — through both clients, the newest quote the deploy's, Q-CA6, Q-CA1, Z2"
U="$(ureq99 "$TA")"; G="$(qget99 "$OUT")"
check "$U|$(uother99 "$TA")|$G|$(tok99 "$TA")" "total=$(( 9 + $(qj "$QX" disc) )) get=$(( 9 + $(qj "$QX" disc) )) other=0 app=$(( 5 + $(qj "$QX" disc) )) token=4 none=0 odd=0|-|$(( 9 + $(qj "$QX" disc) )):0|remote=1 copied=1 left=0" \
  "5a'' GETs only: the client list read only until one client of each side is found, then the checks — exactly as many as it counts; its token copy made and gone"
check "$(printed99 "$OUT")|$(order99 "$OUT")|$(grep -cxF "      $EXP_ROLLBACK99" <<<"$OUT")|$(printf '%s\n' "$ALOG" | grep -c .)|$(stat -c %a "$ALOG" 2>/dev/null)|$(secrets99 "$R99/line-$TA.txt" "$ALOG")|$(noids99 "$R99/line-$TA.txt" "$ALOG")|$(quietobs99 "$TA")" \
      "4|0|0|verdict<rollback<putback|1|1|600|0|0|0|novault=0 plugin=0|$VT99_0>$VT99_0" \
  "5a''' its summary the same four commands in the same order; its own log, 0600; no secret and no client id in either; the observers quiet"
SHA99="$(sheet_of "$OUT99A")"
check "$(grep -cxF "$QCA3L99" "$SHA99" 2>/dev/null)|$(stat -c %a "$SHA99" 2>/dev/null)|$(cat "$R99/line-$TA.txt" "$ALOG" | grep -cwF -e "$(qj "$QX" in)" -e "$(qj "$QX" out)")" "1|600|0" \
  "5a'''' the two clients it picked again and probed are on its sheet, by id, with what each answered (0600) — and neither id is in its terminal or its log"
afterfails99() { base_log; local o; o="$(run99 "$@" --after-only)"; printf '%s' "$o"; }   # an --after-only, the log's start put back first
# Q-CA1: a kept file edited after the deploy.
cp -p "$PD/lib/CrmApiClient.php" "$SB/crm99a.kept"; edit_installed lib/CrmApiClient.php "$(a99 crmbyte)" "$(r99 crmbyte)"
OUT="$(afterfails99)"
check "$(failline "$OUT" Q-CA1 'the installed files are not as the release and 5.18.98' "lib/CrmApiClient.php(not-5.18.98's)")$(has "$OUT" '5.18.99 (after): PASSED')$(fails "$OUT")" "yesno1" "5b a kept file edited after the deploy (the uCRM client): Q-CA1 FAILS naming it — the one failure"
M="$(weak99 qca1w99 "$DEPLOY99" qca1kept)"; OUT="$(afterfails99 --script "$M")"; rm -f "$M"
check "$(fails "$OUT")$(has "$OUT" '5.18.99 (after): PASSED')" "0yes" "5b' a copy blind to the kept files' sha256 — Q-CA1's weakened copy — PASSES over it (caught)"
cat "$SB/crm99a.kept" > "$PD/lib/CrmApiClient.php"; touch -r "$SB/crm99a.kept" "$PD/lib/CrmApiClient.php"
# Q-CA2's main check: the setting changed since the deploy (organization 2 in both copies).
cfg_keep; file_set ucrm_quote_organization_id 2; row_set ucrm_quote_organization_id 2
OUT="$(afterfails99)"
check "$(failline "$OUT" Q-CA2 'reads on:2:setting:- (files) / on:2:setting:- (store row)' 'stage A read on:1:profile:- through the pin')$(fails "$OUT")|$(okline "$OUT" Q-CA3 'refused: filed under organization #1')" "yes1|yes" \
  "5c the quoting organization set to 2 since the deploy: Q-CA2 FAILS against stage A's recorded reading — the one failure (Q-CA3 then probes against #2, and holds)"
M="$(weak99 qca2w99 "$DEPLOY99" qca2main)"; OUT="$(afterfails99 --script "$M")"; rm -f "$M"
check "$(fails "$OUT")$(has "$OUT" '5.18.99 (after): PASSED')" "0yes" "5c' a copy whose Q-CA2 never compares — its weakened copy — PASSES over it (caught)"
cfg_back
# Q-CA2's control: the installed code no longer reading 0 as off — the control FAILS (and Q-CA1 and R1 by the bytes);
# its weakened copy counts one failure fewer.
cp -p "$PD/lib/QuoteOrganization.php" "$SB/qo99.kept"; edit_installed lib/QuoteOrganization.php "$(a99 qooff)" "$(r99 qooff)"
OUT="$(afterfails99)"; FQ1="$(fails "$OUT")"
check "$(failline "$OUT" Q-CA2 'control: the same copy with ucrm_quote_organization_id=0 reads on:-:setting:invalid/on:-:setting:invalid')$(failline "$OUT" Q-CA1 'lib/QuoteOrganization.php')$(failline "$OUT" R1 'installed files that differ' 'lib/QuoteOrganization.php')" "yesyesyes" \
  "5d the installed QuoteOrganization no longer taking 0 as off: Q-CA2's control FAILS — and Q-CA1 and R1 by its bytes"
M="$(weak99 qca2cw99 "$DEPLOY99" qca2c1)"; OUT="$(afterfails99 --script "$M")"; rm -f "$M"
check "$(okline "$OUT" Q-CA2 'control: the same copy with ucrm_quote_organization_id=0')|$(( FQ1 - $(fails "$OUT") ))" "yes|1" "5d' a copy whose control never compares counts the same install one failure fewer, as an ok (caught)"
cat "$SB/qo99.kept" > "$PD/lib/QuoteOrganization.php"; touch -r "$SB/qo99.kept" "$PD/lib/QuoteOrganization.php"
# Q-CA3: the installed code asking uCRM a POST — the wrapper refuses each (FAIL); with the wrapper weakened, the POSTs
# reach the stand-in (caught by its record).
edit_installed lib/QuoteOrganization.php "$(a99 qoorgs)" "$(r99 qoorgs)"
OUT="$(afterfails99)"; T="$(last99)"
check "$(failline "$OUT" Q-CA3 'a request other than a GET was asked of uCRM (other 4; counted)')|$(uother99 "$T")|$(qget99 "$OUT" | cut -d: -f2)" "yes|-|4" \
  "5e a POST planted in the installed QuoteOrganization: Q-CA3 FAILS — four asked, each refused by the wrapper — and the stand-in received none"
M="$(weak99 wrappostaw99 "$DEPLOY99" wrappost)"; OUT="$(afterfails99 --script "$M")"; T="$(last99)"; rm -f "$M"
printf '%s\n' "$T" >> "$R99/expected-posts"
check "$(okline "$OUT" Q-CA3 'nothing was created in uCRM')$(failline "$OUT" Q-CA3 'other than a GET')|$(uother99 "$T")" "yesno|POST /organizations app-key;POST /organizations token;POST /organizations app-key;POST /organizations token" \
  "5e' a copy whose wrapper passes a POST on says nothing was created — and the stand-in received all four POSTs (caught by its record)"
cat "$SB/qo99.kept" > "$PD/lib/QuoteOrganization.php"; touch -r "$SB/qo99.kept" "$PD/lib/QuoteOrganization.php"
check "$(installed99 "$P99")|$(stat -c '%u:%g' "$PD/lib/QuoteOrganization.php" "$PD/lib/CrmApiClient.php" | paste -sd, -)" "0|1000:1000,1000:1000" "…the installed files put back as the pin has them, owned by the store's user"
# Q-CA3: a client moved in uCRM between its discovery and its check (the stand-in's flip) — the check refuses it.
u99_set "flip={\"$(qj "$QX" in)\": 2}"
OUT="$(afterfails99)"
check "$(failline "$OUT" Q-CA3 'checkClient() on a client stage A read in the quoting organization #1 → refused:R1')$(fails "$OUT")|$(ls "$U99"/flip-seen-* 2>/dev/null | grep -c .)" "yes1|1" \
  "5f the client picked in the quoting organization filed under #2 by the time it is checked: Q-CA3 FAILS — the one failure; the flip happened"
u99_set "flip={\"$(qj "$QX" in)\": 2}"
M="$(weak99 qca3inw99 "$DEPLOY99" qca3in)"; OUT="$(afterfails99 --script "$M")"; rm -f "$M"
check "$(fails "$OUT")$(has "$OUT" '5.18.99 (after): PASSED')" "0yes" "5f' a copy that calls that refusal ok — its weakened copy — PASSES (caught)"
u99_set "orgs=$ORGS99_GROWN"
OUT="$(afterfails99)"
check "$(failline "$OUT" Q-CA3 "uCRM's organizations, through the installed code: 1,2,3 — stage A read 1,2 (Q-G4)")$(fails "$OUT")" "yes1" "5g uCRM's list grown since stage A: Q-CA3 FAILS against the deploy's recorded list — the one failure"
M="$(weak99 qca3ow99 "$DEPLOY99" qca3orgs)"; OUT="$(afterfails99 --script "$M")"; rm -f "$M"
check "$(fails "$OUT")$(has "$OUT" '5.18.99 (after): PASSED')" "0yes" "5g' a copy that never compares the lists PASSES (caught)"
u99_set
# Q-CA3: the client picked outside the quoting organization filed under no organization by the time it is checked (R2,
# not R1) — the check refuses it, and its weakened copy calls it ok.
u99_set "flip={\"$(qj "$QX" out)\": 0}"
OUT="$(afterfails99)"
check "$(failline "$OUT" Q-CA3 'checkClient() on a client outside the quoting organization → refused:R2' 'it must be refused R1')$(fails "$OUT")|$(ls "$U99"/flip-seen-* 2>/dev/null | grep -c .)" "yes1|1" \
  "5r the client picked outside the quoting organization filed under none by the time it is checked: Q-CA3 FAILS — refused, but R2, not R1 — the one failure; the flip happened"
u99_set "flip={\"$(qj "$QX" out)\": 0}"
M="$(weak99 qca3outw99 "$DEPLOY99" qca3out)"; OUT="$(afterfails99 --script "$M")"; rm -f "$M"
check "$(fails "$OUT")$(has "$OUT" '5.18.99 (after): PASSED')" "0yes" "5r' a copy that calls any answer for the outside client ok — its weakened copy — PASSES (caught)"
# Q-CA3: the token client answering otherwise than the plugin's — its list without the quoting organization (R5).
u99_set 'token_orgs=[{"id":2,"name":"RH99 Org Two","currencyCode":"UGX","selected":true}]'
OUT="$(afterfails99)"
check "$(failline "$OUT" Q-CA3 'through the token client the two checks answer pass, refused:R5' 'answered pass, refused:R1')$(fails "$OUT")" "yes1" \
  "5s the token client's list without the quoting organization: through it the outside client is refused R5, through the plugin's R1 — Q-CA3 FAILS, the one failure"
M="$(weak99 qca3tokw99 "$DEPLOY99" qca3tok)"; OUT="$(afterfails99 --script "$M")"; rm -f "$M"
check "$(fails "$OUT")$(has "$OUT" '5.18.99 (after): PASSED')" "0yes" "5s' a copy that never sets the token client's answers against the plugin's PASSES (caught)"
u99_set
# Q-CA3: where the probe's refusal lines went, read on its code copy — a probe that gives the guard no sink of its own
# leaves them in its copy's plugin log and PHP error log (FAIL); without that reading's verdict, PASS (caught); without
# the control's line, the reading sees nothing and FAILS rather than pass on nothing.
M="$(weak99 qca3nosink99 "$DEPLOY99" qca3nosink)"; OUT="$(afterfails99 --script "$M")"; rm -f "$M"
check "$(failline "$OUT" Q-CA3 "where the probe's refusal lines went, read on its code copy" ': 2:1 2:1 — ')$(fails "$OUT")|$(has "$OUT" "the guard's refusal lines went to this run's own sink: 0")" "yes1|yes" \
  "5t a copy whose probe gives the guard no sink: its two refusals land in its code copy's plugin log and its PHP error log, beside the control's line — Q-CA3 FAILS, the one failure (the live log untouched)"
M="$(weak99 qca3nosinkw99 "$DEPLOY99" qca3nosink qca3cp)"; OUT="$(afterfails99 --script "$M")"; rm -f "$M"
check "$(fails "$OUT")$(has "$OUT" '5.18.99 (after): PASSED')" "0yes" "5t' …and with that reading's verdict taken out too, PASS over it (caught)"
M="$(weak99 qca3noctl99 "$DEPLOY99" qca3noctl)"; OUT="$(afterfails99 --script "$M")"; rm -f "$M"
check "$(failline "$OUT" Q-CA3 "where the probe's refusal lines went" ': 0:0 0:0 — ')$(fails "$OUT")" "yes1" \
  "5t'' control on the control: a copy without the control's line reads 0:0 in both — a reading that cannot see a line — and FAILS, never a pass on nothing"
# Q-CA3: refusal lines in the live plugin log while the probe runs (the stand-in writes one per client read).
PLOG99="$PD/data/plugin.log"; PLOG99_HAD=""; [ -f "$PLOG99" ] && { PLOG99_HAD=1; cp -p "$PLOG99" "$SB/plog99.kept"; }
[ -f "$PLOG99" ] || install -o 1000 -g 1000 -m 644 /dev/null "$PLOG99"
u99_set "leaklog=\"$PLOG99\""
OUT="$(afterfails99)"
check "$(failline "$OUT" Q-CA3 'the live data/plugin.log gained' "line(s) labelled this run's (deploy-check) while the probe ran")$(fails "$OUT")|$( [ "$(grep -c "planted by the rehearsal's stand-in uCRM" "$PLOG99")" -ge 1 ] && echo written)" "yes1|written" \
  "5h refusal lines labelled this run's (deploy-check) written to the live plugin log while the probe runs — what only a code copy logging where the live plugin does could write: Q-CA3 FAILS — the one failure; the lines were written"
M="$(weak99 qca3lw99 "$DEPLOY99" qca3log)"; OUT="$(afterfails99 --script "$M")"; rm -f "$M"
check "$(fails "$OUT")$(has "$OUT" '5.18.99 (after): PASSED')" "0yes" "5h' a copy blind to this run's label in the live plugin log PASSES over them — labelled, they are no staff note either (caught)"
u99_set "leaklog=\"$PLOG99\"" 'leakwhere="kyc-new"'
OUT="$(afterfails99)"
check "$(fails "$OUT")$(has "$OUT" '5.18.99 (after): PASSED')|$(noteline "$OUT" Q-CA3 'the live data/plugin.log gained' "naming a probed client (its id on the sheet) while the probe ran, none labelled this run's" 'staff activity on that client, never this run')|$( [ "$(grep -c '(kyc-new): planted' "$PLOG99")" -ge 1 ] && echo written)" "0yes|yes|written" \
  "5h'' lines naming a probed client in the live plugin log, labelled as staff's KYC path writes them: a NOTE — staff at work — never a FAIL that blames the probe"
u99_set
if [ -n "$PLOG99_HAD" ]; then cat "$SB/plog99.kept" > "$PLOG99"; touch -r "$SB/plog99.kept" "$PLOG99"; else rm -f "$PLOG99"; fi
check "$( [ -n "$PLOG99_HAD" ] && cmp -s "$SB/plog99.kept" "$PLOG99" && echo as-found || { [ -z "$PLOG99_HAD" ] && [ ! -e "$PLOG99" ] && echo as-found; } )" "as-found" "…the plugin log as it was found"
# Q-CA4 and Q-CA5: Sync All's route and the KYC form answering a visitor with no session.
touch "$SB/web/.leak_sync99"; OUT="$(afterfails99)"
check "$(failline "$OUT" Q-CA4 'Sync All is not as 5.18.99 makes it: the installed gate names push_products_to_ucrm: yes; its route without a session → 200; fatals 00')$(fails "$OUT")$( [ -e "$SB/web/.leak_sync99" ] && echo unspent || echo spent)" "yes1spent" \
  "5i Sync All's route answering a visitor with no session (the stand-in's .leak_sync99): Q-CA4 FAILS — the one failure"
touch "$SB/web/.leak_sync99"; M="$(weak99 qca4w99 "$DEPLOY99" qca4)"; OUT="$(afterfails99 --script "$M")"; rm -f "$M"
check "$(fails "$OUT")$(okline "$OUT" Q-CA4 'Sync All is not as 5.18.99 makes it')$( [ -e "$SB/web/.leak_sync99" ] && echo unspent || echo spent)" "0yesspent" "5i' a copy that calls it ok — Q-CA4's weakened copy — PASSES over the same leak (caught)"
touch "$SB/web/.leak_kyc99"; OUT="$(afterfails99)"
check "$(failline "$OUT" Q-CA5 'a page 5.18.99 changes does not answer a visitor with no session with the sign-in page as it must:' 'the KYC form=200|other|00;')$(fails "$OUT")$( [ -e "$SB/web/.leak_kyc99" ] && echo unspent || echo spent)" "yes1spent" \
  "5j the KYC form answering a visitor with no session (the stand-in's .leak_kyc99): Q-CA5 FAILS naming it — the one failure"
touch "$SB/web/.leak_kyc99"; M="$(weak99 qca5w99 "$DEPLOY99" qca5)"; OUT="$(afterfails99 --script "$M")"; rm -f "$M"
check "$(fails "$OUT")$(has "$OUT" '5.18.99 (after): PASSED')$( [ -e "$SB/web/.leak_kyc99" ] && echo unspent || echo spent)" "0yesspent" "5j' a copy that never judges the answers PASSES over the same leak (caught)"
# Q-CA6: South Sudan's installed profile given a quoting organization — FAILS (and Q-CA1 and R5 by the bytes of a kept
# file); its weakened copy counts one failure fewer.
cp -p "$PD/profiles/south-sudan.json" "$SB/ss99.kept"; edit_installed profiles/south-sudan.json "$(a99 ssquote)" "$(r99 ssquote)"
OUT="$(afterfails99)"; FQ6="$(fails "$OUT")"
check "$(failline "$OUT" Q-CA6 'the installed profiles, through the installed TenantProfile: uganda=1 south-sudan=1')$(failline "$OUT" Q-CA1 'profiles/south-sudan.json')" "yesyes" \
  "5k South Sudan's installed profile naming a quoting organization: Q-CA6 FAILS — and Q-CA1 by the bytes of a kept file"
M="$(weak99 qca6w99 "$DEPLOY99" qca6)"; OUT="$(afterfails99 --script "$M")"; rm -f "$M"
check "$(okline "$OUT" Q-CA6 'the installed profiles')|$(( FQ6 - $(fails "$OUT") ))" "yes|1" "5k' a copy that calls any profile ok counts the same install one failure fewer, as an ok (caught)"
# Q-CA2's South Sudan control: over that profile, the installed QuoteOrganization no longer reading the tenant — setting()
# turns on for South Sudan: the control FAILS; its weakened copy counts the same install one failure fewer, as an ok.
cp -p "$PD/lib/QuoteOrganization.php" "$SB/qo99c0.kept"; edit_installed lib/QuoteOrganization.php "$(a99 qotenant)" "$(r99 qotenant)"
OUT="$(afterfails99)"; FQ0="$(fails "$OUT")"
check "$(failline "$OUT" Q-CA2 'control: the same copy with tenant_profile=south-sudan reads tenant south-sudan/south-sudan, setting on:1:profile:-/on:1:profile:-' 'it must be off')$(okline "$OUT" Q-CA2 'control: the same copy with ucrm_quote_organization_id=0')" "yesyes" \
  "5k'' …and the installed QuoteOrganization no longer reading the tenant: the control with tenant_profile=south-sudan FAILS — on, organization 1, from South Sudan's profile — the other control still holds"
M="$(weak99 qca2c0w99 "$DEPLOY99" qca2c0)"; OUT="$(afterfails99 --script "$M")"; rm -f "$M"
check "$(okline "$OUT" Q-CA2 'control: the same copy with tenant_profile=south-sudan')|$(( FQ0 - $(fails "$OUT") ))" "yes|1" "5k''' a copy whose South Sudan control never compares counts the same install one failure fewer, as an ok (caught)"
cat "$SB/qo99c0.kept" > "$PD/lib/QuoteOrganization.php"; touch -r "$SB/qo99c0.kept" "$PD/lib/QuoteOrganization.php"
cat "$SB/ss99.kept" > "$PD/profiles/south-sudan.json"; touch -r "$SB/ss99.kept" "$PD/profiles/south-sudan.json"
# Q-CA6's set_config checks: the installed tools/set_config.php listing the key in another form (FAIL, and Q-CA1 by its
# bytes); then taking "disabled" for off, so that the throwaway folder is written (FAIL). Each weakened copy counts the
# same install one failure fewer, as an ok.
cp -p "$PD/tools/set_config.php" "$SB/sc99.kept"; edit_installed tools/set_config.php "$(a99 scqlist)" "$(r99 scqlist)"
OUT="$(afterfails99)"; FQ7="$(fails "$OUT")"
check "$(failline "$OUT" Q-CA6 'the installed tools/set_config.php does not list ucrm_quote_organization_id as text')$(failline "$OUT" Q-CA1 'tools/set_config.php')$(okline "$OUT" Q-CA6 'refuses --value disabled')" "yesyesyes" \
  "5u the installed set_config listing the key in another form: Q-CA6 FAILS on the listing — and Q-CA1 by its bytes; its refusal still holds"
M="$(weak99 qca6listw99 "$DEPLOY99" qca6list)"; OUT="$(afterfails99 --script "$M")"; rm -f "$M"
check "$(okline "$OUT" Q-CA6 'the installed tools/set_config.php lists ucrm_quote_organization_id as text')|$(( FQ7 - $(fails "$OUT") ))" "yes|1" "5u' a copy that never reads the listing counts the same install one failure fewer, as an ok (caught)"
cat "$SB/sc99.kept" > "$PD/tools/set_config.php"; touch -r "$SB/sc99.kept" "$PD/tools/set_config.php"
edit_installed tools/set_config.php "$(a99 scqoff)" "$(r99 scqoff)"
OUT="$(afterfails99)"; FQ8="$(fails "$OUT")"
check "$(failline "$OUT" Q-CA6 'the installed tools/set_config.php on a throwaway data folder, --value disabled:' 'the refusal NOT printed' 'it must refuse with exit 1 and write nothing')$(okline "$OUT" Q-CA6 'lists ucrm_quote_organization_id as text')|$(left_tmp99)" "yesyes|$TMP99" \
  "5v the installed set_config taking \"disabled\" for off: on the throwaway folder it saves instead of refusing — Q-CA6 FAILS on the refusal; the listing still holds; the folder removed with the run"
M="$(weak99 qca6refw99 "$DEPLOY99" qca6ref)"; OUT="$(afterfails99 --script "$M")"; rm -f "$M"
check "$(okline "$OUT" Q-CA6 'refuses --value disabled')|$(( FQ8 - $(fails "$OUT") ))" "yes|1" "5v' a copy that never judges the refusal counts the same install one failure fewer, as an ok (caught)"
cat "$SB/sc99.kept" > "$PD/tools/set_config.php"; touch -r "$SB/sc99.kept" "$PD/tools/set_config.php"
check "$(installed99 "$P99")|$(stat -c '%u:%g' "$PD/tools/set_config.php" "$PD/lib/QuoteOrganization.php" | paste -sd, -)" "0|1000:1000,1000:1000" "…set_config and QuoteOrganization put back as the pin has them, owned by the store's user"
# Z2: a token copy left in the run's code folder.
: > "$SB/z2.plant"; OUT="$(afterfails99 --env "FAKE_Z2_PLANT=$SB/z2.plant")"
check "$(failline "$OUT" Z2 'this run left behind, in the container' 'file:rh99-planted/token.json')$(fails "$OUT")$( [ -e "$SB/z2.plant" ] && echo unspent || echo spent)|$(left_tmp99)" "yes1spent|$TMP99" \
  "5l a token.json planted in the run's code folder: Z2 FAILS naming it — the one failure — and the run's end removes it"
: > "$SB/z2.plant"; M="$(weak99 z2w99 "$DEPLOY99" z2tok)"; OUT="$(afterfails99 --script "$M" --env "FAKE_Z2_PLANT=$SB/z2.plant")"; rm -f "$M"
check "$(fails "$OUT")$(okline "$OUT" Z2 'no token copy')$( [ -e "$SB/z2.plant" ] && echo unspent || echo spent)" "0yesspent" "5l' a copy whose Z2 never looks for a token copy PASSES over it, and says there is none (caught)"
rm -f "$SB/z2.plant"
# V3j: ECO Bank's switch cleared while an --after-only runs.
cfg_keep; printf '%s' "$BK97" > "$SB/web/.flip_switch"; OUT="$(afterfails99)"
check "$(failline "$OUT" V3j "ECO Bank's switch, $BK97, CHANGED across this run (before=bk=on/on, after=bk=absent/absent; files/store)")$( [ -e "$SB/web/.flip_switch" ] && echo unspent || echo spent)$(has "$OUT" '5.18.99 (after): PASSED')" "yesspentno" \
  "5m the switch cleared in both places while an --after-only runs: V3j FAILS, naming both readings"
cfg_back
check "$(both_copies "$BK97")|$(installed99 "$P99")" "11|0" "…the switch and every file put back"
# Q-N3's window, read again by an --after-only from the deploy's state file — stage A's date, never one of the run's own: a
# date planted there is the one the stand-in is asked for; uCRM listing no quote in it where stage A read one is not
# comparable, never staff quoting; a newer one in it is staff quoting — notes, never a failure.
cp -p "$STATE99" "$SB/state99.kept"; sed -i 's/^QN3_SINCE=.*/QN3_SINCE=2000-01-02/' "$STATE99"; : > "$U99/quotes-since.log"
OUT="$(afterfails99)"
check "$(fails "$OUT")$(has "$OUT" '5.18.99 (after): PASSED')|$(okline "$OUT" Q-CA3 "the newest uCRM quote is stage A's (#88017402, created since 2000-01-02: stage A's window, read again)")|$(LC_ALL=C sort -u "$U99/quotes-since.log" | paste -sd, -)" "0yes|yes|2000-01-02" \
  "5w an --after-only reads Q-N3's window from the deploy's state file: a date planted there is the one it asks uCRM for, and the one it names"
cat "$SB/state99.kept" > "$STATE99"; touch -r "$SB/state99.kept" "$STATE99"
u99_set 'quotes=[]'; OUT="$(afterfails99)"
check "$(fails "$OUT")$(has "$OUT" '5.18.99 (after): PASSED')|$(noteline "$OUT" Q-CA3 'is none now, and stage A read #88017402 in the same window' 'not comparable')$(noteline "$OUT" Q-CA3 'staff quoting')" "0yes|yesno" \
  "5w' uCRM listing no quote in that window where stage A read one: a NOTE, not comparable — never called staff quoting"
u99_set 'quotes=[88017401,88017402,88017403]'; OUT="$(afterfails99)"
check "$(fails "$OUT")$(has "$OUT" '5.18.99 (after): PASSED')|$(noteline "$OUT" Q-CA3 'is #88017403, stage A read #88017402 in the same window' 'staff quoting since, never this run')" "0yes|yes" \
  "5w'' a newer quote in that window: a NOTE, staff quoting since — never this run's, never a failure"
u99_set
check "$(sha256sum "$STATE99" | cut -c1-16)" "$(sha256sum "$SB/state99.kept" | cut -c1-16)" "…the deploy's state file as it was"
fresh99; OUT="$(run99 --check)"
nogo99 "5n --check with 5.18.99 live: refused — the --after-only printed instead, alone" \
  "--check is stage A before a deploy, and the container already serves $P99 (5.18.99) — run --after-only instead, its own command, alone:" "$OUT" "$(data94)" "$P99"
check "$(grep -cxF "      $EXP_AFTER99" <<<"$OUT")" "1" "…the --after-only on its own line, with its own log"
fresh99; OUT="$(run99 --move-list-only)"
nogo99 "5o --move-list-only with 5.18.99 live: refused at Q-G1 — it reads the app's clients before the deploy" \
  "NO-GO (Q-G1): --move-list-only reads the app's clients before the deploy, while 5.18.98 is live — the container serves $P99 (5.18.99), not $P98 (5.18.98): 5.18.99 is deployed already" "$OUT" "$(data94)" "$P99"
fresh99; OB_CB="$(o97)"; OUT="$(run99 --check-bank)"; T="$(last99)"
check "$(fails "$OUT")$(has "$OUT" '5.18.99 (check-bank): PASSED')$(okline "$OUT" CB 'both ECO BANK cards show a figure' 'answers yes')|$(python3 "$H98/rh98.py" cbterm "$R99/run-$T.txt")|$(ureq99 "$T" | cut -d' ' -f1)" "0yesyes|$(python3 "$H98/rh98.py" cbexp "$OB_CB")|total=0" \
  "5p --check-bank PASSES on the installed 5.18.99: both cards a figure, its lines this rehearsal's own reading; it asks uCRM nothing"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "5q the quarantine's own --check, as the runbook orders after this: PASSED with 5.18.99 live"
QREC99="$(sha256sum "$QREC" | cut -c1-16)"; KEPT99="$(kept_state)"

echo; echo "== 99-6. the rollback, run exactly as printed — ROLLBACK typed; ECO Bank and its switch unchanged; --redeploy too soon =="
RLINE="$(printed "$OUT99A" "cd $REPO && bash scripts/deploy-5.18.99.sh --rollback")"
check "$RLINE" "$EXP_ROLLBACK99" "6 the rollback command, as the --after-only's summary printed it after its verdict"
S99SHA="$(sha256sum "$STATE99" | cut -c1-64)"; DR0="$(data94)"; LEDGER99R="$(ledger95)"; OB_RB0="$(o97)"
fresh99; OUT="$(run_line99 ROLLBACK "$RLINE")"; TR="$(last99)"; RLOG="$(ls -1 "$OUT99"/rollback-*.log 2>/dev/null)"
check "$(fails "$OUT")$(has "$OUT" '5.18.99 (rollback): PASSED')$(live)" "0yes$P98" "6a the rollback PASSES: 5.18.98 serving again"
for l in "ok    container serves $P98 (5.18.98)" "ok    RB the installed manifest says 5.18.98" \
         "ok    RB all 30 files 5.18.99 changed are 5.18.98's again, byte for byte — by Git's sha256 and by the 30 this script pins (Q-G1's set); the 6 files it added" \
         "ok    RB 5.18.99's quoting code is gone from the installed code — no file that reaches uCRM names QuoteOrganization" \
         "note  RB what 5.18.99 may have written stays and is inert under 5.18.98, which reads none of it: applications with a quote refusal recorded 0, leads flagged quote_org_missing 0, ucrm_quote_organization_id unset/unset" \
         "ok    RB 5.18.95's seven fixes are still installed after the rollback" "ok    RB cash in hand reads exactly as before the deploy on a copy of the book" \
         "ok    RB ECO Bank as the rollback leaves it, through the installed 5.18.98 classes on the copy" "ok    RB 088 stays after the rollback, complete" \
         "ok    RB ECO Bank's switch, $BK97, as before the rollback (bk=on/on; files/store)" "ok    V3j ECO Bank's switch, $BK97, is unchanged by this run and ON" \
         "ok    RB the quarantine still holds, as its record says" "note  RB run the quarantine's --check next" "note  RB the code is 5.18.98 again." \
         "  serving           $P98  (plugin 5.18.98)"; do
  check "$(has "$OUT" "$l")" "yes" "6a: ${l:0:110}"
done
RBQ99=""; for f in lib/KycService.php lib/UcrmClientTarget.php lib/KycCrmSync.php lib/UcrmLeadSync.php lib/QuotationService.php lib/PluginQuotePdf.php includes/api/api_cron_debug.php; do grep -qF 'QuoteOrganization' "$PD/$f" && RBQ99="$RBQ99 $f"; done
check "$(installed99 "$P98")$(inst_ver)|${RBQ99:-gone}|$(for f in $ADDED99; do [ -f "$PD/$f" ] && echo kept; done | grep -c kept)" "05.18.98|gone|6" \
  "6b this rehearsal's own reading: every file 5.18.99 changed is 5.18.98's again, no file that reaches uCRM names QuoteOrganization; the six added files stay on disk, read by nothing"
check "$(grep -cxF "      $EXP_QCHECK" <<<"$OUT")$(grep -cxF "      $EXP_REDEPLOY99" <<<"$OUT")$(grep -cxF "      $EXP_DEPLOY99" <<<"$OUT")$(grep -c 'deploy-5.18.99.sh --rollback' <<<"$OUT")|$(printed99 "$OUT")" "1100|2|0|0" \
  "6c it prints the quarantine's --check and --redeploy, each alone with its own log — never the plain deploy or a rollback"
check "$(printf '%s\n' "$RLOG" | grep -c .)|$(stat -c %a "$RLOG" 2>/dev/null)|$(sha256sum "$STATE99" | cut -c1-64)|$(ls "$OUT99"/rollback-state-*.env 2>/dev/null | grep -c .)|$(grep -c "^RB_COPY_AT=$(stat -c %Y "$PD/lib/KycService.php")\$" "$OUT99"/rollback-state-*.env 2>/dev/null)" "1|600|$S99SHA|1|1" \
  "6d its own log, 0600; the deploy's state file untouched — the rollback's record a file of its own, holding its copy's time as stamp_copy gave lib/KycService.php"
check "$(data94)$(ledger95)$(kept_state)$(oldname)$(sha256sum "$QREC" | cut -c1-16)$(host_code99)$(left_tmp99)|$(oj "$(o97)" rows)|$(both_copies "$BK97")|$(ureq99 "$TR" | cut -d' ' -f1)|$(secrets99 "$R99/line-$TR.txt" "$RLOG")|$(quietobs99 "$TR")" \
      "$DR0$LEDGER99R${KEPT99}0${QREC99}0${TMP99}|$(oj "$OB_RB0" rows)|11|total=0|0|0|novault=0 plugin=0|$VT99_0>$VT99_0" \
  "6e the rollback changed no data — 088's rows stay, the switch stays on — nor the ledger or the quarantine; it asked uCRM nothing; no secret printed; the observers quiet"
fresh99; OUT="$(run99 --answer NO --redeploy)"
nogo99 "6f --redeploy right after the rollback, before the quarantine's --check: NO-GO at A14 — the rollback's own copy came after the newest passing --check" \
  "NO-GO (A14): a rollback of 5.18.99 by this script copied the plugin at" "$OUT" "$DR0"
check "$(has "$OUT" "($(basename "$OUT99")/rollback-state-")$(grep -cxF "      $EXP_QCHECK" <<<"$OUT")" "yes1" "…naming the rollback's own record, and printing the quarantine's --check on its own line, with its own log"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "…the quarantine's own --check after the rollback, as RB asks: PASSED"
QREC99="$(sha256sum "$QREC" | cut -c1-16)"; KEPT99="$(kept_state)"; D99="$(data94)"

echo; echo "== 99-7. --redeploy after the rollback, all three quoting flags typed where only Q-G5 needs its =="
D99P="$(data94)"; RP99="$(rows_add99 "$PLANT99_QG5")"; OP="$(o99 1)"
check "$RP99|$( [ $(( 2 * $(oj "$OP" qg5 | cut -d: -f2) )) -lt "$(oj "$OP" qg5 | cut -d: -f1)" ] && echo fewer-than-half)" '{"rows":2}|fewer-than-half' "7 Q-G5's plant again, through the installed 5.18.98 code's own writer: fewer than half of the recent quotes have a uCRM quote"
fresh99; OUT="$(run99 --answer DEPLOY --redeploy --quote-company-off --quote-create-unproven --lead-org-differs)"; OUT99R="$OUT"; T="$(last99)"
check "$(fails "$OUT")$(has "$OUT" '5.18.99 (deploy): PASSED')$(live)$(installed99 "$P99")$(inst_ver)" "0yes${P99}05.18.99" "7a --redeploy with the three flags, DEPLOY typed: PASSED, $P99 serving, every file as the pin has it"
check "$(noteline "$OUT" Q-G5 "--quote-create-unproven, the operator's explicit choice: of $(oj "$OP" qg5 | cut -d: -f1) Lead or Manual quote(s)")$(noteline "$OUT" Q-G3 '--quote-company-off')$(noteline "$OUT" Q-G6 '--lead-org-differs')$(okline "$OUT" Q-G3 '(on:1:profile:-)')$(okline "$OUT" Q-G6 'is unset')$(okline "$OUT" BR1 "6 of the 6 files 5.18.99 adds are already on disk")$(noteline "$OUT" BR1 'although --redeploy was not asked for')" "yesnonoyesyesyesno" \
  "7b Q-G5 a NOTE in the operator's words; --quote-company-off and --lead-org-differs typed over checks that held: Q-G3 and Q-G6 ok, never a note; BR1 the six files on disk, as --redeploy expects"
check "$(sed -n 's/^QOFF_NOTED=//p' "$STATE99" | tail -1)|$(sed -n 's/^QG5_NOTED=//p' "$STATE99" | tail -1)|$(sed -n 's/^QG6_NOTED=//p' "$STATE99" | tail -1)|$(sed -n 's/^REDEPLOY=//p' "$STATE99" | tail -1)|$(sed -n 's/^QG3_A=//p' "$STATE99" | tail -1)|$(order99 "$OUT")|$(printed99 "$OUT")|$(uother99 "$T")" \
      "|1||1|on:1:profile:-|verdict<rollback<putback|4|0|0|-" \
  "7c the state file records what each flag did — Q-G5's alone — never that the three were typed; --redeploy recorded; the summary's order as before; GETs only"
copy_back99 30; COPY99="$(sed -n 's/^COPY_AT=//p' "$STATE99" | tail -1)"; set_sched $(( COPY99 + 300 )); base_log
ALINE2="$(printed "$OUT99R" "cd $REPO && bash scripts/deploy-5.18.99.sh --after-only")"
fresh99; OUT="$(run_line99 '' "$ALINE2")"; T="$(last99)"
check "$ALINE2|$(fails "$OUT")$(has "$OUT" '5.18.99 (after): PASSED')|$(okline "$OUT" Q-CA2 'on:1:profile:-')$(okline "$OUT" Q-CA3 '→ pass')|$(uother99 "$T")" "$EXP_AFTER99|0yes|yesyes|-" \
  "7d its --after-only, as printed: PASSED, Q-CA2 and Q-CA3 against the redeploy's own readings; GETs only"
sqx "DELETE FROM quotes_log WHERE json_extract(data, '\$._rh99plant') = 1" >/dev/null
check "$(data94)|$(sq "SELECT COUNT(*) FROM quotes_log WHERE json_extract(data, '\$._rh99plant') = 1")" "$D99P|0" "…Q-G5's plant taken out: the data as before it"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "7e the quarantine's own --check, as the runbook orders: PASSED"
QREC99="$(sha256sum "$QREC" | cut -c1-16)"; KEPT99="$(kept_state)"

echo; echo "== 99-8. a rollback whose copy leaves 5.18.99's debug route behind =="
git -C "$REPO" show "$P99:$P/includes/api/api_cron_debug.php" > "$SB/cron99.left"; chmod 644 "$SB/cron99.left"
printf '%s\n' "$SB/cron99.left" > "$SB/web/.left99"
fresh99; OUT="$(run99 --answer ROLLBACK --rollback)"
check "$(live)|$( [ -e "$SB/web/.left99" ] && echo unspent || echo spent)|$(cmp -s "$PD/includes/api/api_cron_debug.php" "$SB/cron99.left" && echo left)|$(fails "$OUT")|$(has "$OUT" '5.18.99 (rollback): 2 FAILED')" "$P98|spent|left|2|yes" \
  "8a a rollback whose copy leaves 5.18.99's debug route behind: 5.18.98 serving, the route 5.18.99's, two checks FAIL"
check "$(failline "$OUT" RB "installed files that are not 5.18.98's after the rollback: includes/api/api_cron_debug.php")$(failline "$OUT" RB "the installed code after the rollback is not 5.18.98's as it must be:" "5.18.99's quoting code still there: includes/api/api_cron_debug.php:QuoteOrganization")" "yesyes" \
  "…RB FAILS by sha256, naming the file, and by the quoting code still there"
git -C "$REPO" show "$P98:$P/includes/api/api_cron_debug.php" > "$PD/includes/api/api_cron_debug.php"
printf '%s\n' "$SB/cron99.left" > "$SB/web/.left99"
M="$(weak99 rbqow99 "$DEPLOY99" rbqo)"; fresh99; OUT="$(run99 --answer ROLLBACK --script "$M" --rollback)"; rm -f "$M"
check "$(live)|$( [ -e "$SB/web/.left99" ] && echo unspent || echo spent)|$(cmp -s "$PD/includes/api/api_cron_debug.php" "$SB/cron99.left" && echo left)|$(okline "$OUT" RB "5.18.99's quoting code is gone from the installed code")$(failline "$OUT" RB "installed files that are not 5.18.98's after the rollback: includes/api/api_cron_debug.php")|$(fails "$OUT")" "$P98|spent|left|yesyes|1" \
  "8a' a copy whose RB is blind to the quoting code — its weakened copy — over the same route left behind (5.18.98 already serving): it calls the quoting code gone (caught); only the sha256 still sees the file"
git -C "$REPO" show "$P98:$P/includes/api/api_cron_debug.php" > "$PD/includes/api/api_cron_debug.php"
check "$(installed99 "$P98")$(stat -c '%u:%g' "$PD/includes/api/api_cron_debug.php")" "01000:1000" "…the route put back as 5.18.98's: every file 5.18.98's again"
# RB's count of what 5.18.99 left behind, against a positive control — an application with a quote refusal recorded and a
# lead flagged quote_org_missing, through the installed code's own writer, and the key set in the configuration file —
# and three more of its quoting markers left in place by a copy that did not complete: 5.18.99's Sync All gate, Uganda
# profile and set_config, over 5.18.98 already serving (the rollback's checks alone).
D8B99="$(data94)"; cfg_keep; RQ99="$(rows_add99 "$SEED99RB")"; file_set ucrm_quote_organization_id 1
for f in includes/api/api_products_admin.php profiles/uganda.json tools/set_config.php; do cp -p "$PD/$f" "$SB/rbq99-${f##*/}.kept"; git -C "$REPO" show "$P99:$P/$f" > "$PD/$f"; done
fresh99; OUT="$(run99 --answer ROLLBACK --rollback)"; T="$(last99)"
check "$RQ99|$(live)|$(noteline "$OUT" RB 'applications with a quote refusal recorded 1, leads flagged quote_org_missing 1, ucrm_quote_organization_id set/unset')$(failline "$OUT" RB "the installed code after the rollback is not 5.18.98's as it must be:" "5.18.99's quoting code still there: api_products_admin.php:push_products_to_ucrm-admin-only profiles/uganda.json:quoting tools/set_config.php:ucrm_quote_organization_id")$(failline "$OUT" RB "installed files that are not 5.18.98's after the rollback:" 'includes/api/api_products_admin.php' 'profiles/uganda.json' 'tools/set_config.php')|$(ureq99 "$T" | cut -d' ' -f1)" \
      "{\"rows\":2}|$P98|yesyesyes|total=0" \
  "8b over rows 5.18.99 wrote and the key in the configuration file, RB counts them — 1, 1, set/unset — and, with 5.18.99's Sync All gate, Uganda profile and set_config left in place, FAILS naming all three markers, and by sha256; it asks uCRM nothing"
for f in includes/api/api_products_admin.php profiles/uganda.json tools/set_config.php; do cat "$SB/rbq99-${f##*/}.kept" > "$PD/$f"; touch -r "$SB/rbq99-${f##*/}.kept" "$PD/$f"; done
cfg_back; sqx "DELETE FROM kyc_applications WHERE json_extract(data, '\$._rh99rb') = 1" >/dev/null; sqx "DELETE FROM leads WHERE json_extract(data, '\$._rh99rb') = 1" >/dev/null
check "$(installed99 "$P98")|$(stat -c '%u:%g' "$PD/includes/api/api_products_admin.php" "$PD/profiles/uganda.json" "$PD/tools/set_config.php" | paste -sd, -)|$(data94)" "0|1000:1000,1000:1000,1000:1000|$D8B99" \
  "…the three files 5.18.98's again, owned by the store's user; the two rows and the key taken out: the data as before"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "…the quarantine's own --check after that rollback: PASSED"

echo; echo "== 99-10. what the rehearsal left behind, and what it never reached =="
fresh99   # the last logs and sheets into the sweeps' folders
check "$(evo_calls)" "0" "Evolution never called — not by the deploys, their checks, the rollbacks or the code they ran on copies"
check "$(ls -a "$REPO/scripts" | grep -E '^\..+\.sh$' | paste -sd, -)" ".qpin.sh" "no weakened or re-pinned copy is left in the clone's scripts/ — the quarantine's one pinned copy aside"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )" "" "every weakened copy, release variant, pinned copy and planted edit found its anchor exactly once — none was skipped"
M="$(mutant anchorprobe99 "$DEPLOY99" 'NO SUCH ANCHOR IN THE SCRIPT' 'x' 2>/dev/null)"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )$(test -e "$M" && echo made || echo none)" "mutant:anchorprobe99 none" \
  "control on the control: a weakened copy whose anchor is missing is recorded as such, and no copy is made"
rm -f "$SB/anchor-missing"
M="$(weak99 tableprobe99 "$DEPLOY99" no-such-entry 2>/dev/null)"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )$(test -e "$M" && echo made || echo none)" "mutant:tableprobe99 none" \
  "control on the control: a weakened copy asked for an entry the table does not hold is recorded as such, and no copy is made"
rm -f "$SB/anchor-missing"
S="$(pinned_copy99 pinprobe99 "$P99" "$DEPLOY99" lib/NoSuchFile.php "$(printf '0%.0s' $(seq 1 64))" 2>/dev/null)"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )" "pinned_copy99:pinprobe99 " "control on the control: a re-pinned copy whose file has no pinned line is recorded as such"
rm -f "$SB/anchor-missing" "$S"
check "$( [ -f "$SB/bad-runs" ] && tr '\n' ' ' < "$SB/bad-runs" )" "" "every run took the options it was given — none answered 'unknown option'"
OUT="$(run99 --after-only --rehearsal-unknown-option)"
check "$(has "$OUT" 'unknown option: --rehearsal-unknown-option')$( [ -s "$SB/bad-runs" ] && echo recorded)" "yesrecorded" "control on the control: a run given an option the script does not know is recorded as such"
rm -f "$SB/bad-runs"
check "$(ls -A "$SB/web" | grep -c '^\.leak_sync99$\|^\.leak_kyc99$\|^\.left99$\|^\.add_noncash98$\|^\.leak_cb98$\|^\.leak_cbx98$\|^\.left98$\|^\.flip_switch$')|$(ls "$U99"/flip-seen-* 2>/dev/null | grep -c .)" "0|0" "every trigger the rehearsal planted was spent, and the stand-in uCRM's flip memory is clear"
# The observers over every run of the 5.18.99 script, but those that trip one on purpose (recorded as they ran).
OBS_BAD=0; OBS_N=0; TOK_BAD=0; GET_BAD=0
for rec in "$R99"/exec/run-*.rec; do
  t="${rec##*/run-}"; t="${t%.rec}"; OBS_N=$((OBS_N+1))
  if ! grep -qx "$t" "$R99/expected-trips"; then
    a="$(audit99 "$t")"; case "$a" in *" novault=0 plugin=0 "*) ;; *) OBS_BAD=$((OBS_BAD+1)) ;; esac
    [ "$(hits99 "$t")" = "0" ] || OBS_BAD=$((OBS_BAD+1))
    [ "$(cat "$R99/vault/run-$t.before")" = "$(cat "$R99/vault/run-$t.after")" ] || OBS_BAD=$((OBS_BAD+1))
  fi
  grep -qx "$t" "$R99/expected-toks" || case "$(tok99 "$t")" in *" left=0") ;; *) TOK_BAD=$((TOK_BAD+1)) ;; esac
  grep -qx "$t" "$R99/expected-posts" || [ "$(uother99 "$t")" = "-" ] || GET_BAD=$((GET_BAD+1))
done
check "$( [ "$OBS_N" -ge 60 ] && echo many)|$OBS_BAD|$TOK_BAD|$GET_BAD|$(grep -c . "$R99/expected-trips")|$(grep -c . "$R99/expected-toks")|$(grep -c . "$R99/expected-posts")" "many|0|0|0|0|1|2" \
  "10 across every run of the 5.18.99 script — the deploys, the redeploy, the after-onlys, the checks, the move-list runs, the rollbacks, the refusals and the weakened copies — the copy observers were quiet, every token copy was gone at the call after its probe, and the stand-in uCRM received nothing but GETs; the token copy's weakened copy (1k) and the two whose wrapper passes a POST (1h, 5e) aside"
check "$(vt_host)|$(grep -cxF "$W96" "$DATA/webhook_secret")" "$VT99_0|1" "…the live vault as it was before the first run, bytes and time; the planted webhook secret where it was planted"
sweep_files99() { ls -1 "$R99"/run-*.txt "$R99"/line-*.txt "$R99"/logs/*.log "$OUT99"/*.log "$OUT99"/state-5.18.99.env* "$OUT99"/rollback-state-* "$OUT99"/cih-rows-* 2>/dev/null; }
check "$( [ "$(sweep_files99 | grep -c .)" -ge 60 ] && echo many)|$( [ "$(ls "$R99"/sheets/cash-*.txt 2>/dev/null | grep -c .)" -ge 20 ] && echo sheets)|$(forms99; grep -c . "$SB/forms99.txt" | awk '{print ($1 >= 100 ? "forms" : "few:" $1)}')|$( [ "$(ls "$R99"/ucrm/run-*.req 2>/dev/null | grep -c .)" -ge 60 ] && echo records)" "many|sheets|forms|records" \
  "the sweep has material: every run's terminal and log, the state files and row files; every run's sheet; a hundred and more printed forms of the book's figures; every run's request record"
check "$(figs99 $(sweep_files99))" "0" "10a no figure of the book or of a kept statement — in any printed form — in any terminal, log, state file or row file of any run of the 5.18.99 script: only in the sheets"
check "$(sweep_files99 | xargs grep -lF -f "$SCAN96" 2>/dev/null | grep -c .)|$(grep -v '^$' "$SEC95" > "$SB/sec99.pat"; sweep_files99 | xargs grep -lF -f "$SB/sec99.pat" 2>/dev/null | grep -c .)|$(secrets99 $(sweep_files99) "$R99"/sheets/cash-*.txt "$R99"/ucrm/run-*.req)" "0|0|0" \
  "10b no output, log, state file, sheet or request record holds the planted webhook secret or vault credential, a repair key, a planted Splynx or pdf value — or a name, contact or key of the stand-in uCRM's"
check "$(noids99 $(sweep_files99))|$(bankhits98 $(sweep_files99))|$( [ "$(grep -lwF -f "$SB/ids99.pat" "$R99"/sheets/cash-*.txt 2>/dev/null | grep -c .)" -ge 4 ] && echo on-sheets)" "0|0|on-sheets" \
  "10c no output, log or state file names a client id the app holds — the sheets do, the move-list's only place — nor an account name or number"
printf 'planted %s, %s, %s and client %s\n' "$(grep -m1 '^2:' "$SB/forms99.txt" | cut -c3-)" "$W96" "$(head -1 "$U99_SECRETS")" "$(head -1 "$SB/ids99.pat")" > "$R99/zz-sweep-control.txt"
check "$(figs99 "$R99/zz-sweep-control.txt")$(grep -lF -f "$SCAN96" "$R99/zz-sweep-control.txt" | grep -c .)$(secrets99 "$R99/zz-sweep-control.txt")$(noids99 "$R99/zz-sweep-control.txt")" "1111" \
  "control on the sweeps: a file holding one figure form, the planted secret, a stand-in secret and a client id is found by all four"
rm -f "$R99/zz-sweep-control.txt" "$SB/sec99.pat"
rterr99() {   # rterr99 FILE… — how many of the files hold a bash runtime error of a script ("<x>.sh: line N: …")
  [ "$#" -gt 0 ] || { echo none; return; }
  grep -lE '\.sh: line [0-9]+: .*(bad substitution|unbound variable|syntax error|command not found)' "$@" 2>/dev/null | grep -c .
}
printf '%s\n' 'X=1; Y=2' 'echo "a:${X:+ the copy'"'"'s:$X}${Y:+ the base'"'"'s:$Y} end"' > "$SB/rterr99.sh"
bash "$SB/rterr99.sh" > "$R99/zz-rterr-control.txt" 2>&1
check "$(rterr99 $(sweep_files99))|$(rterr99 "$R99/zz-rterr-control.txt")" "0|1" \
  "10d no terminal or log of any run of the 5.18.99 script holds a bash runtime error — a line that cannot run counts nothing; control: a script with an apostrophe inside \${…:+…} is caught"
rm -f "$SB/rterr99.sh" "$R99/zz-rterr-control.txt"
check "$(left_tmp99)$(host_code99)$(ls -d /tmp/dnb-backup-* /tmp/rh99-k.* 2>/dev/null | grep -c .)$(find /tmp -maxdepth 6 -path '/tmp/dnb-5.18.99-*' -name token.json 2>/dev/null | grep -c .)" "${TMP99}000" "no copy of the book or the code, no vault, no token copy and no backup copy is left in /tmp or on the host"
check "$(grep -c 'Fatal\|Parse error' "$SB/web.log")|$(grep -c 'Fatal\|Parse error' "$U99/server.log")" "0|0" "the stand-in plugin and the stand-in uCRM served every request without a PHP fatal"
check "$(checkout_state)" "$CHECKOUT0" "this checkout is as the rehearsal found it: same commit, same tracked files"

echo; echo "rehearsal: $PASS passed, $FAILN failed ($(cat "$SB/runs") runs of the deploy scripts, $(cat "$SB/qruns" 2>/dev/null || echo 0) of the quarantine)"
[ "$FAILN" = "0" ]
