#!/usr/bin/env bash
#
# Rehearse scripts/deploy-5.18.102.sh — on Uganda the plugin's KYC quotations take their lines and prices from uCRM's
# Products and Service Plans through one new resolver, lib/UcrmQuoteLines.php (root docs/71) — over the live release,
# 5.18.101, before the operator runs it.
#
# The sandbox is the previous release's rehearsal's, built by its own code: scripts/harness/deploy-5.18.101/rehearse.sh,
# read up to just before its own last section (what it left behind, its sweeps) — the cut asserted: its anchor exactly
# once, its last check the one named, and the one line that names its own folder pointed at that folder, also exactly
# once — and run here as it is, which builds every earlier sandbox the same way, from their own rehearsals. By the time
# this file's own sections begin, 5.18.101 has been deployed, rolled back and deployed again with --redeploy there, its
# copy moved half an hour back, its --after-only passed (R19 conclusive) and the quarantine's own --check passed after it:
# production's state on the day. The stand-in uCRM of 5.18.99's rehearsal (rh99-ucrm.php, its sandbox copy) learns, by an
# asserted insertion before its final 404, the four reads 5.18.102's checks make — GET products?limit, service-plans?limit,
# custom-attributes and billing/quotes/{id} — two answers below 400 the release must refuse (500 Products, an HTML page),
# and its base state a catalogue that prices every plan and kit this rehearsal seeds in the store (through the installed
# plugin's own writer, rh102-seed.php), and "Professional Installation". The fake docker learns, by an asserted hook, two
# triggers armed by this rehearsal's files: a read of the container's record answered wrong once after the deploy's own
# rollback copied (6w), and the folder of the quoting reading after the copy refused once (8).
#
# This rehearsal proves:
#   · the script is pinned to the release commit on release/5.18.102 (short and full), cut on 5.18.101; the delta is
#     exactly the 21 added and 38 changed reviewed files, each file's sha256 as the script pins it, computed here from Git;
#     the 55 kept files by theirs in both commits, the 38 changed ones by their previous form; no migration; T1 carried;
#     the header's two commands between its two marker lines, each alone, neither a rollback; the header saying QL-RB2's
#     two counts (stage A and as ROLLBACK is typed), V1's count after its automatic rollback and the put-back's none, and
#     review 2's words (D11, Q-CA6, C5's, the rollback's on Uganda, V1's unverified rollback, the reasons' categories);
#     QL-RB1's markers counted in Git as the script pins them at the release and absent at 5.18.101; the sign-off reader
#     QS-G1 carries, with its control on 5.18.100 (0);
#   · the seeded catalogue really resolves: the RELEASE's own UcrmQuoteLines, run here on the store's offered rows against
#     the stand-in, prices every row, and one price set to 0 makes exactly one row unpriced (pre);
#   · --check is GO: QL-G1 ok with the counts the release's resolver gives, the notes QL-N1–QL-N7 as seeded — QL-N3's D11
#     count as this rehearsal's own reading of the release's rules gives it — the stand-in asked only GETs of the two
#     lists, once per client, with limit=500; the names on the 0600 sheet and nowhere else, under QL-G1's own name;
#     nothing changed (c);
#   · a NO-GO on one unpriced row — said client by client ("1 row(s) through the plugin's own client … and 1 through the
#     token client …, the same rows judged by each"), never as a sum of rows, and where it is fixed (uCRM's Products and
#     Service Plans, or the row's name) — the deploy-with-flag command printed alone; the same with --accept-unpriced GO
#     and its note, in the same words; a plan with no Service Plan said under "differs" by all it holds; an unreadable
#     catalogue (products, service plans, the token client) a NO-GO the flag does not lift, and so 500 Products and an
#     HTML page, said as answers the release cannot use, never "no answer"; the toggles (two salesPerson attributes, none,
#     quotation 000314's application) read as notes; eight applications planted for D11, counted as the release's rules
#     count them; each refusal weakened and caught; nothing changed by any of them (1);
#   · the deploy, DEPLOY typed, PASSES: QL-G1 ok, QL-CA1 equal to stage A's, Q-CA3's GETs only and the stand-in's record
#     the same count, QS-G1/QS-CA1, Q-CA*, TN-CA* carried, stage A's catalogue counts in the state file; Z2 naming the
#     catalogue files; F's summary as fixed — the Uganda Cash customer's welcome, the app's Credit New Connection, the
#     kit-only additional service, Taxable (no step 5), D9's qualifier, D11 with QL-N3's count, the database's line —
#     the sheet's two catalogue sections each under its own check's name, and the printed rollback's block saying what the
#     customer sees again, the rollback's choice and the put-back's hazard each on lines of their own (4);
#   · --after-only as printed PASSES, its counts said client by client; after a catalogue change in the stand-in it
#     PASSES with a NOTE naming the direction, over both clients, F's step 3 "required now"; an unreadable catalogue FAILS
#     QL-CA1, step 3 "no count to judge", and its weakened copy says ok; the quarantine's --check after them (5);
#   · the rollback: QL-RB2 ok and the question reached (NO typed), stage A's note qualified as RB's and F's are (and its
#     weakened copy caught); an unpriced pending application saved AFTER stage A
#     counted none and while the question waits (planted by the harness's typed-answer step, then ROLLBACK typed) —
#     refused by the count made as ROLLBACK is typed, before the copy, nothing changed; the same with that count
#     replaced by a stop, caught: the copy would have begun over it; planted before the run — refused at stage A, and
#     with --rollback-unpriced-pending stage A's notes, NO typed; QL-RB2 weakened, caught; then planted at the question
#     again with --rollback-unpriced-pending, ROLLBACK typed: stage A ok, the count as ROLLBACK is typed noted as the
#     operator's choice, PASSED, RB's counts set beside that last count, 5.18.101's 38 files back, QL-RB1's absences,
#     the 21 added files on disk — counted, never taken from Git — and reached by nothing; a --redeploy too soon refused
#     at A14; QL-RB1 made to fail by a planted reference with one added file gone (20 of the 21, said so by QL-RB1, RB and
#     F), and its weakened copy, RB saying that run rolled nothing back (6);
#   · a deploy (--redeploy) whose public sign-in page redirects: V1 FAILS and it rolls itself back without waiting, then
#     counts QL-RB2 on a copy — the counts line, the hazard notes and the stop line carrying the count, no command
#     printed, the flag never named; 5.18.101 serving; the quarantine's --check after it (6v); the same with the deploy's
#     own rollback not verifying: the counts and notes said as after an attempt that did not complete, the stop saying
#     so with the put-back's hazard and the put-back alone on its line — and a copy ignoring do_rollback's answer caught (6w);
#   · --redeploy: PASSED with BR1's "21 of the 21" clause; its --after-only PASSES (7);
#   · the quoting reading after the copy refused: F keeps stage A's narrative, said as stage A's — in an --after-only and in
#     a deploy over an unpriced row on --accept-unpriced ("required unless fixed since") — and a summary gate reading it as
#     "off" caught; then, the row unpriced and fixed, F from that run's own reading (8);
#   · the quoting organization off, --quote-company-off: --check GO, then a deploy and its --after-only PASS with QL-N1 and
#     QL-CA2 notes that "Prepared by" is not used — never an ok — QL-N3 without D11, nothing of the catalogue read, F's own
#     words for it; QL-N1's and QL-CA2's gate guards each weakened, caught; the setting put back (9);
#   · nothing reaches Evolution; across every run of the 5.18.102 script the copy observers were quiet, every token copy
#     gone, the stand-in given GETs only, the live vault as it was; no seeded name, price, stand-in token or run key in any
#     output, log, state file or terminal; no page cache; no code copy or run folder left in /tmp or on the host; the
#     printed rollback after the verdict and never in a block with a deploy command; no output calling an application
#     one "5.18.102 could not price" (RH-1); no trigger left and every plant at the question made; each check with its
#     control on the control (10).
#
# Everything runs in a sandbox; it never touches this checkout, the server or the network, and refuses to run where the
# server could be. Everything seeded is fictitious.
#
#   bash scripts/harness/deploy-5.18.102/rehearse.sh            REHEARSE_KEEP=<dir> keeps every run's full output
set -u
for f in /data/ucrm /opt/dishnet /var/run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
H102_DIR="$(cd "$(dirname "$0")" && pwd)"
H102_ROOT="$(cd "$H102_DIR/../../.." && pwd)"
F102=scripts/deploy-5.18.102.sh
P102_EARLY="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT="\([^"]*\)".*/\1/p' "$H102_ROOT/$F102" 2>/dev/null)"
P102F_EARLY="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT_FULL="\([^"]*\)".*/\1/p' "$H102_ROOT/$F102" 2>/dev/null)"
T1_EARLY102="$(sed -n 's/^T1_SIGNATURE="\([^"]*\)".*/\1/p' "$H102_ROOT/$F102" 2>/dev/null)"
case "$P102_EARLY" in __*|'') echo "refusing: $F102 is not pinned to a release commit (${P102_EARLY:-none})"; exit 2;; esac
case "$P102F_EARLY" in "$P102_EARLY"*) [ "${#P102F_EARLY}" = "40" ] || { echo "refusing: $F102 does not pin its release commit in full"; exit 2; } ;; *) echo "refusing: $F102's full pin does not begin with its short one"; exit 2;; esac
case "$T1_EARLY102" in [1-9]*' passed, 0 failed rc=0') ;; *) echo "refusing: $F102 does not pin T1's count (T1_SIGNATURE=${T1_EARLY102:-none})"; exit 2;; esac
for f102 in rh102-seed.php rh102-ref.php rh102q.py; do
  [ -f "$H102_DIR/$f102" ] || { echo "refusing: this rehearsal's helper $f102 is not beside it"; exit 2; }
done
H101_HARNESS="$H102_DIR/../deploy-5.18.101/rehearse.sh"
H102_PREFIX="$(mktemp)"
python3 - "$H101_HARNESS" "$H102_PREFIX" <<'PY' || { echo "refusing: the previous release's rehearsal could not be cut before its last section (its sweeps)"; rm -f "$H102_PREFIX"; exit 2; }
import sys
src, dst = sys.argv[1], sys.argv[2]
anchor = 'echo; echo "== 101-10. what the rehearsal left behind, and what it never reached =="'
lines = open(src, encoding='utf-8').read().split('\n')
at = [i for i, l in enumerate(lines) if l == anchor]
assert len(at) == 1, len(at)
assert at[0] > 470, at[0]                   # its deploy, rollback and --redeploy come before it
pre = '\n'.join(lines[:at[0]]) + '\n'
# That rehearsal finds its own helpers beside itself ($0's folder): sourced from here, $0 is this file, so its one line
# naming that folder points at its own folder instead — the one edit, asserted.
old_dir = 'H101_DIR="$(cd "$(dirname "$0")" && pwd)"\n'
new_dir = 'H101_DIR="$(cd "$(dirname "$0")/../deploy-5.18.101" && pwd)"   # the next release\'s rehearsal: this one\'s own folder, where its helpers are\n'
assert pre.count(old_dir) == 1, pre.count(old_dir)
pre = pre.replace(old_dir, new_dir, 1)
assert pre.count(new_dir) == 1 and pre.count(old_dir) == 0
for need in ('H100_HARNESS="$H101_DIR/../deploy-5.18.100/rehearse.sh"', 'run101() {', 'run_line101() {', 'fresh101() {', 'nogo101() {',
             'went101() {', 'quiet101() {', 'checkgo101() {', 'audit101() {', 'hits101() {', 'vault101() {', 'uother101() {',
             'copy_back101() {', 'release_variant101() {', 'pinned_copy101() {', 'installed101() {', 'weak101() {',
             'qs_read101() {', 'tn_read101() {', 'OUT101="$SB/out101"; mkdir -p "$OUT101"; STATE101="$OUT101/state-5.18.101.env"',
             'R101="$SB/r101"', 'H101="$SB/rh101"', 'REL101=release/5.18.101; DEPLOY101="$REPO/$F101"', 'LOGW101() {',
             'ANCH101="$SB/anchors101.tsv"', 'echo; echo "== 101-7. --redeploy after the rollback =="'):
    assert pre.count(need) == 1, need
last = [l for l in pre.split('\n') if l.strip()][-1]
assert last.startswith('check "$(has "$OUT" \'stray-store quarantine (check): PASSED.\')" "yes" "7d the quarantine\'s own --check, as the runbook orders: PASSED"'), last[:90]
open(dst, 'w', encoding='utf-8').write(pre)
PY
# shellcheck disable=SC1090
. "$H102_PREFIX"
rm -f "$H102_PREFIX"
for f102 in mutant checkout_state row_set row_unset cfg_keep cfg_back file_set sqx sq qj okline failline noteline has fails live data94 kept_state oldname printed qrun \
            fp99 secrets99 noids99 bankhits98 vt_host ledger95 both_copies evo_calls inst_ver ver dl o100 names100 row_keep100 row_back100 \
            u99_set rows_add99 set99 spent99 set_sched base_log utc epoch99 settle96 \
            run101 run_line101 fresh101 installed101 copy_back101 weak101 a101 r101 qs_read101 tn_read101 checkgo101 nogo101 states101 \
            backups101 host_code101 left_tmp101 LOGW101 audit101 uother101 pubget; do
  declare -F "$f102" >/dev/null || { echo "refusing: the rehearsals this one runs did not define $f102()"; exit 2; }
done
for v102 in SB REPO R P PD DATA VAULT MOUNT PHPBIN IN_CONTAINER U99 U99_URL U99_SECRETS TOKEN99 APPKEY99 RECS QD QREC PLUGIN_BASE WEB_OK \
            AFTER94 SCAN96 SEC95 W96 BK97 DATE_TS98 EXP_QCHECK OUT96 OUT97 OUT98 OUT99 OUT100 OUT101 STATE101 H99 H100 H101 P99 P100 P101 \
            DEPLOY100 DEPLOY101 CHECKOUT0 VT99_0 TECH100 R97 R98 R99 R100 R101 H97 H98; do
  [ -n "${!v102:-}" ] || { echo "refusing: the rehearsals this one runs did not set $v102"; exit 2; }
done
FAIL_PRE102=$FAILN   # the earlier rehearsals' failures, before this release's own sections: the last line names them

# ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
# 5.18.102's own rehearsal, on the sandbox built above: 5.18.101 serving after 101-7's --redeploy, its --after-only and
# the quarantine's --check passed — as production has it.
# ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
REL102=release/5.18.102; DEPLOY102="$REPO/$F102"
ADDED102="lib/UcrmQuoteLines.php tests/quote_from_ucrm/A_resolver.php tests/quote_from_ucrm/B_submission.php tests/quote_from_ucrm/C_retries.php tests/quote_from_ucrm/D_prepared_by.php tests/quote_from_ucrm/E_readback.php tests/quote_from_ucrm/F_send_quote.php tests/quote_from_ucrm/G_documents_screens.php tests/quote_from_ucrm/H_kyc_form.php tests/quote_from_ucrm/I_boundary.php tests/quote_from_ucrm/_lib.php tests/quote_from_ucrm/f_lib.php tests/quote_from_ucrm/g_api_router.php tests/quote_from_ucrm/h_kyc_form_browser.js tests/quote_from_ucrm/h_router.php tests/quote_from_ucrm/mutants.php tests/quote_from_ucrm/mutants_F.php tests/quote_from_ucrm/mutants_G.php tests/quote_from_ucrm/mutants_H.php tests/quote_from_ucrm/mutants_I.php tests/test_quote_from_ucrm.php"
CHANGED102="SAFETY.md includes/api/api_cron_debug.php includes/api/api_products_admin.php includes/post/post_sync.php lib/CrmApiClient.php lib/DeliveryPdfService.php lib/KycCrmSync.php lib/KycService.php lib/NotificationService.php lib/PluginQuotePdf.php lib/QuotationService.php lib/UcrmClientTarget.php manifest.json tabs/admin/settings.php tabs/sales/applications.php tabs/sales/hardware.php tabs/sales/kyc_form.php tabs/sales/product_mapping.php tabs/sales/send_quote.php tabs/sales/subscription_plans.php tests/fixtures/fake_ucrm_kyc.php tests/fixtures/fake_ucrm_quote_org.php tests/fixtures/quote_company_journey.php tests/test_bank_boundaries.php tests/test_cashbook_physical_list.php tests/test_distributor_apply.php tests/test_distributor_link_ucrm.php tests/test_distributor_notify.php tests/test_distributor_registry.php tests/test_distributor_territory.php tests/test_field_cash_in_currency.php tests/test_kyc_crm_messages.php tests/test_notify_kyc_quote_send.php tests/test_notify_kyc_race.php tests/test_quote_company.php tests/test_quote_company_mutants.php tests/test_quote_summary.php tests/test_staff_manual_entry_currency.php"
NA102=$(wc -w <<<"$ADDED102"); NC102=$(wc -w <<<"$CHANGED102"); N102=$((NA102 + NC102))   # 21, 38, 59
if git -C "$R" ls-files --error-unmatch "$F102" >/dev/null 2>&1; then
  cmp -s "$R/$F102" "$DEPLOY102" || { echo "refusing: $F102 differs from the committed one — commit it, then rehearse"; exit 2; }
  WHICH102="the committed script"
else cp "$R/$F102" "$DEPLOY102"; WHICH102="the working copy (not yet committed; untracked in the clone)"; fi
git -C "$REPO" fetch -q origin "refs/heads/$REL102:refs/remotes/origin/$REL102" 2>/dev/null || true
P102="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT="\([^"]*\)".*/\1/p' "$DEPLOY102")"
P102F="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT_FULL="\([^"]*\)".*/\1/p' "$DEPLOY102")"
OUT102="$SB/out102"; mkdir -p "$OUT102"; STATE102="$OUT102/state-5.18.102.env"
R102="$SB/r102"; mkdir -p "$R102/logs" "$R102/mutants" "$R102/sheets" "$R102/exec" "$R102/scan" "$R102/vault" "$R102/tok" "$R102/ucrm"
H102="$SB/rh102"; mkdir -p "$H102/v"; cp "$H102_DIR"/rh102-seed.php "$H102_DIR"/rh102-ref.php "$H102_DIR"/rh102q.py "$H102/"
chmod 755 "$H102"; chmod 644 "$H102"/rh102-*.php "$H102/rh102q.py"; chown 1000:1000 "$H102/v"; chmod 700 "$H102/v"   # the seed's own folder, the store's user's
LOGW102() { printf '2>&1 | ( umask 077; exec tee %s/%s-%s.log )' "$OUT102" "$1" "$DATE_TS98"; }   # the script's LOGW, as it prints it
EXP_AFTER102="cd $REPO && bash scripts/deploy-5.18.102.sh --after-only $(LOGW102 after)"
EXP_ROLLBACK102="cd $REPO && bash scripts/deploy-5.18.102.sh --rollback $(LOGW102 rollback)"
EXP_REDEPLOY102="cd $REPO && bash scripts/deploy-5.18.102.sh --redeploy $(LOGW102 redeploy)"
EXP_PUTBACK102="( cd $REPO && git checkout $P101 && { bash scripts/deploy-hybrid.sh; git checkout -; } ) $(LOGW102 putback)"   # the script's PUTBACK_CMD, as it prints it
EXP_RUNBOOK102="cd $REPO && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.102 && mkdir -p $OUT102 && bash scripts/deploy-5.18.102.sh $(LOGW102 deploy)"
EXP_ACCEPT102="cd $REPO && bash scripts/deploy-5.18.102.sh --accept-unpriced $(LOGW102 deploy)"   # with_flags --accept-unpriced, as a refusal prints it
runbook_with102() { printf 'cd %s && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.102 && mkdir -p %s && bash scripts/deploy-5.18.102.sh %s %s' "$REPO" "$OUT102" "$1" "$(LOGW102 deploy)"; }   # --check's deploy with FLAGS
left_tmp102() { ls -d /tmp/dnb-5.18.102-* 2>/dev/null | wc -l | tr -d ' '; }
host_code102() { ls -d "$OUT102"/code-* "$OUT102"/p3b-* "$OUT102"/br1-* "$OUT102"/domainb-* "$OUT102"/tn-* "$OUT102"/qs-* 2>/dev/null | wc -l | tr -d ' '; }
backups102() { ls -d "$OUT102"/backup-* 2>/dev/null | wc -l | tr -d ' '; }
states102() { ls -1 "$OUT102"/state-5.18.102.env* "$OUT102"/rollback-state-* 2>/dev/null | sort | while IFS= read -r f; do printf '%s %s\n' "${f##*/}" "$(sha256sum "$f" | cut -c1-16)"; done | sha256sum | cut -c1-16; }
cache102() { { find "$DATA" "$PD" -maxdepth 2 -name ucrm_quote_catalogue_cache.json 2>/dev/null; find /tmp -maxdepth 6 -path '/tmp/dnb-5.18.102-*' -name ucrm_quote_catalogue_cache.json 2>/dev/null; } | grep -c .; }   # UcrmQuoteLines' page cache, anywhere it could be written
fresh102() {   # the run folder as a new run finds it: the state files and the row digests kept; every log and sheet moved aside
  find "$OUT102" -mindepth 1 -maxdepth 1 -name '*.log' -exec mv -t "$R102/logs/" {} + 2>/dev/null
  find "$OUT102" -mindepth 1 -maxdepth 1 -name 'cash-*.txt' -exec mv -t "$R102/sheets/" {} + 2>/dev/null
  find "$OUT102" -mindepth 1 -maxdepth 1 ! -name 'state-5.18.102.env*' ! -name 'rollback-state-*' ! -name 'cih-rows-*' -exec rm -rf {} +; base_log; }
installed102() {   # installed102 COMMIT — the number of files 5.18.101 → 5.18.102 touches that are NOT installed as COMMIT has them
                   # (a file COMMIT does not hold is not asked about: 5.18.101 holds none of the 21 added ones)
  local f rel bad=0
  for f in $(dl AM "$P101" "$P102"); do
    rel="${f#$P/}"
    if git -C "$REPO" cat-file -e "$1:$f" 2>/dev/null; then
      [ "$(git -C "$REPO" show "$1:$f" | sha256sum | cut -c1-64)" = "$(sha256sum "$PD/$rel" 2>/dev/null | cut -c1-64)" ] || bad=$((bad+1))
    fi
  done
  echo "$bad"
}
left102() { local f n=0; for f in $ADDED102; do [ -e "$PD/$f" ] && n=$((n+1)); done; echo "$n"; }   # the 21 added files on disk
reach102() {   # the installed files, the 21 added ones aside, that name UcrmQuoteLines or carry 5.18.102's two refusals — 0 after a rollback
  ( cd "$PD" && grep -rlF -e 'UcrmQuoteLines' -e 'prices are set in uCRM Products' -e 'no Delivery Acknowledgment PDF on Uganda' --include='*.php' . 2>/dev/null ) \
    | sed 's#^\./##' | grep -vxF -f <(tr ' ' '\n' <<<"$ADDED102") | grep -c .
}
last102() { printf '%03d' "$(cat "$SB/runs")"; }
run102() {   # [--answer WORD] [--script FILE] [--env K=V]…, THEN the script's options — as run101, this release's folders, and
             # the stand-in's limits.log emptied before the run and kept after it beside its request record
  local answer="" script="$DEPLOY102" extra=() n tag o cmd
  while :; do case "${1:-}" in --answer) answer="$2"; shift 2 ;; --script) script="$2"; shift 2 ;; --env) extra+=("$2"); shift 2 ;; *) break ;; esac; done
  : > "$SB/docker.log"; : > "$U99/requests.log"; : > "$U99/limits.log"
  n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"; tag="$(printf '%03d' "$n")"
  vt_host > "$R102/vault/run-$tag.before"; : > "$R102/exec/run-$tag.rec"; : > "$R102/scan/run-$tag.hits"; : > "$R102/tok/run-$tag.tok"
  cmd="$(printf '%q ' env PATH="$SB/datebin:$SB/bin:$PATH" FAKE_TODAY="$AFTER94" DNB_OUT="$OUT102" DNB_RECORDS="$RECS" DNB_WEBSITE="$WEB_OK" GUARD_SECONDS=3 DN_DATA_DIR="$DATA" \
         FAKE_EXEC_LOG="$R102/exec/run-$tag.rec" FAKE_TMP_SCAN="$SCAN96" FAKE_TMP_HITS="$R102/scan/run-$tag.hits" FAKE_TOK_HITS="$R102/tok/run-$tag.tok" \
         ${extra[@]+"${extra[@]}"} bash "$script" --plugin-base "$PLUGIN_BASE" "$@")"
  o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r')"
  vt_host > "$R102/vault/run-$tag.after"; cp "$U99/requests.log" "$R102/ucrm/run-$tag.req"; cp "$U99/limits.log" "$R102/ucrm/run-$tag.lim"
  case "$o" in *"unknown option:"*) echo "run102-$n" >> "$SB/bad-runs" ;; esac
  if [ -n "${R102_MUTANT_OUT:-}" ]; then printf '%s\n' "$o" > "$R102/mutants/run-$tag.txt"; else printf '%s\n' "$o" > "$R102/run-$tag.txt"; fi
  [ -z "${REHEARSE_KEEP:-}" ] || printf '%s\n' "$o" > "$REHEARSE_KEEP/run102-$tag-${answer:-none}-$(basename "$script" .sh)$(printf '%s' "$*" | tr -c 'A-Za-z0-9-' '_').txt"
  printf '%s' "$o"
}
feed102() {   # feed102 WORD JSON TAG — the typed-answer step when R102_PLANT is set: the answer typed only once the run has asked
              # its question — the run's own log (the printed command's tee, in this release's run folder) shows "Type
              # ROLLBACK to put" — after JSON's rows are added through the installed code's own writer (rows_add102): an
              # application saved after stage A's count, while the question waits, as a sale can be on the server. The
              # plant's result goes to plant-TAG.txt — {"rows":N}; or noprompt (the run stopped first) or timeout, and then
              # nothing is planted — and WORD is typed whichever it was
  local i f r=timeout
  for i in $(seq 1 3000); do
    f="$(ls -1t "$OUT102"/rollback-*.log 2>/dev/null | head -1)"
    if [ -n "$f" ] && grep -qF 'Type ROLLBACK to put ' "$f" 2>/dev/null; then r="$(rows_add102 "$2")"; break; fi
    if [ -n "$f" ] && grep -qE '^  STOP: |^== F\. Summary ==$' "$f" 2>/dev/null; then r=noprompt; break; fi
    sleep 0.2
  done
  printf '%s\n' "$r" > "$R102/plant-$3.txt"
  printf '%s\n' "$1"
}
run_line102() {   # run_line102 WORD LINE — a command line exactly as a summary printed it, through a pseudo-terminal, WORD typed;
                  # with R102_PLANT=JSON, WORD is typed by feed102, after JSON's rows are planted at the question
  local answer="$1" line="$2" n tag o cmd
  : > "$SB/docker.log"; : > "$U99/requests.log"; : > "$U99/limits.log"
  n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"; tag="$(printf '%03d' "$n")"
  vt_host > "$R102/vault/run-$tag.before"; : > "$R102/exec/run-$tag.rec"; : > "$R102/scan/run-$tag.hits"; : > "$R102/tok/run-$tag.tok"
  cmd="$(printf '%q ' env PATH="$SB/datebin:$SB/bin:$PATH" FAKE_TODAY="$AFTER94" DNB_OUT="$OUT102" DNB_RECORDS="$RECS" DNB_WEBSITE="$WEB_OK" GUARD_SECONDS=3 DN_DATA_DIR="$DATA" PLUGIN_BASE="$PLUGIN_BASE" \
         FAKE_EXEC_LOG="$R102/exec/run-$tag.rec" FAKE_TMP_SCAN="$SCAN96" FAKE_TMP_HITS="$R102/scan/run-$tag.hits" FAKE_TOK_HITS="$R102/tok/run-$tag.tok" bash -c "$line")"
  o="$( { if [ -n "${R102_PLANT:-}" ]; then feed102 "$answer" "$R102_PLANT" "$tag"; else printf '%s\n' "$answer"; fi; } | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r')"
  vt_host > "$R102/vault/run-$tag.after"; cp "$U99/requests.log" "$R102/ucrm/run-$tag.req"; cp "$U99/limits.log" "$R102/ucrm/run-$tag.lim"
  case "$o" in *"unknown option:"*) echo "line102-$n" >> "$SB/bad-runs" ;; esac
  printf '%s\n' "$o" > "$R102/line-$tag.txt"
  [ -z "${REHEARSE_KEEP:-}" ] || printf '%s\n' "$o" > "$REHEARSE_KEEP/line102-$tag-${answer:-none}.txt"
  printf '%s' "$o"
}
went102() { echo "$(has "$1" 'GO — evidence recorded')$(has "$1" 'Type DEPLOY')"; }   # the run reached the question
quiet102() { echo "$(live)|$(backups102)|$(data94)|$(kept_state)|$(oldname)|$(cache102)"; }   # nothing deployed, backed up or changed; no page cache
nogo102() {   # nogo102 LABEL EXPECTED-TEXT OUTPUT [DATA-DIGEST] — refused with that text and "Nothing was changed", never the
              # question, nothing deployed, backed up or changed: 5.18.101 serving
  check "$(has "$3" "$2")$(has "$3" 'Nothing was changed. Send the log file.')$(went102 "$3")|$(quiet102)" "yesyesnono|$P101|0|${4:-$D102}|$KEPT102|0|0" "$1"
}
checkgo102() {   # checkgo102 OUTPUT — --check said GO: stage A held — nothing asked, nothing backed up, no state written
  echo "$(has "$1" "  5.18.102 (check): GO — stage A holds.")$(has "$1" '  --check           stage A alone, every refusal and note of a deploy')$(went102 "$1")$(fails "$1")|$(backups102)|$(states102)"
}
audit102() { python3 "$H102/rh102.py" audit "$R102/exec/run-$1.rec"; }   # audit102 TAG — copies=<n> novault=<n> plugin=<n> adj=<id|->
hits102() { local n; n="$(grep -c . "$R102/scan/run-$1.hits" 2>/dev/null)"; echo "${n:-0}"; }
vault102() { echo "$(cat "$R102/vault/run-$1.before")>$(cat "$R102/vault/run-$1.after")"; }
uother102() { python3 "$H99/rh99.py" u99other "$R102/ucrm/run-$1.req"; }   # the requests to the stand-in uCRM that were not a GET
ureq102() { python3 "$H102/rh102q.py" u102req "$R102/ucrm/run-$1.req"; }   # …the run's requests by shape (rh102q.py)
lim102() { python3 "$H102/rh102q.py" limits "$R102/ucrm/run-$1.lim"; }     # …the limit values its two list reads asked for
apps102() { sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1; python3 "$H102/rh102q.py" apps "$DATA/plugin.sqlite3"; }   # this rehearsal's own reading of the applications (JSON)
sqn102() { php -r '$p = new PDO("sqlite:" . $argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); echo $p->exec($argv[2]);' "$DATA/plugin.sqlite3" "$1" 2>&1; }   # sqn102 SQL — rows changed
gets102() { sed -n 's/^  uCRM              asked \([0-9][0-9]*\) GET(s) by this run .*/\1/p' <<<"$1" | head -1; }   # the GETs F says this run asked
qor102() {   # qor102 OUTPUT N KEY — KEY of the Nth "uCRM, GET-only" line of a run (QO_R, as the script echoes it)
  awk -v n="$2" 'index($0, "  uCRM, GET-only  ") == 1 { if (++k == n) { print substr($0, 19); exit } }' <<<"$1" | sed -nE "s/^(.* )?$3=([^ ]*).*\$/\2/p" | head -1
}
qol102() { awk 'index($0, "  the copy        ids=") == 1 { print substr($0, 19); exit }' <<<"$1" | sed -nE "s/^(.* )?$2=([^ ]*).*\$/\2/p" | head -1; }   # qol102 OUTPUT KEY — of QO_L
copy_back102() {   # the 5.18.102 copy N minutes ago: lib/KycService.php's time (its stamp_copy, COPY_MARK), the recorded copy time,
                   # the start before it
  touch -d "-$1 minutes" "$PD/lib/KycService.php"; local t; t="$(stat -c %Y "$PD/lib/KycService.php")"
  sed -i -e "s/^COPY_AT=.*/COPY_AT=$t/" -e "s/^DEPLOYED_AT=.*/DEPLOYED_AT=$(date -u -d "@$(( t - 60 ))" +%Y-%m-%dT%H:%M:%SZ)/" "$STATE102"; }
rows_add102() {   # rows_add102 JSON — the catalogue checks' rows added through the INSTALLED code's own writer
                  # (SqliteStore::appendWithId), as the store's user, with a vault of its own; {"rows":N}, or none(<why>)
  local o; o="$(cd "$H102/v" && setpriv --reuid 1000 --regid 1000 --clear-groups -- env DN_VAULT_FILE="$H102/v/seed-vault.json" "$PHPBIN" "$H102/rh102-seed.php" "$PD" "$DATA" "$1" 2>"$H102/v/seed.err")"
  rm -f "$H102/v/seed-vault.json"
  case "$o" in '{"rows":'*) printf '%s' "$o" ;; *) echo "none($(head -c 120 "$H102/v/seed.err"))" ;; esac
}
ANCH102="$SB/anchors102.tsv"
cat > "$ANCH102" <<'ANCH'
qlg1u	deploy	      else stop "NO-GO (QL-G1): row(s) the KYC form offers do not resolve in uCRM through the RELEASE's resolver	      else : "NO-GO (QL-G1): row(s) the KYC form offers do not resolve in uCRM through the RELEASE's resolver
qlg1rp	deploy	      [ "$(ql_n "$p" unread)" = "0" ] || stop "NO-GO (QL-G1): uCRM's Products and Service Plans could not be read through the plugin's own client	      true || stop "NO-GO (QL-G1): uCRM's Products and Service Plans could not be read through the plugin's own client
qlg1rt	deploy	      [ "$t" = "unset" ] || [ "$(ql_n "$t" unread)" = "0" ] || stop "NO-GO (QL-G1): uCRM's Products and Service Plans could not be read through the token client	      true || stop "NO-GO (QL-G1): uCRM's Products and Service Plans could not be read through the token client
qlca1	deploy	        if [ -n "$x" ]; then bad "QL-CA1 the catalogue through the INSTALLED resolver:${x%;} — each row by name on the sheet"	        if [ -n "$x" ]; then ok "QL-CA1 the catalogue through the INSTALLED resolver:${x%;} — each row by name on the sheet"
qlrb2	deploy	    else stop "NO-GO (QL-RB2): ${at}$QL_UNP_PENDING application(s) still waiting for uCRM with no plan price recorded	    else : "NO-GO (QL-RB2): ${at}$QL_UNP_PENDING application(s) still waiting for uCRM with no plan price recorded
qlrb2again	deploy	    qlrb_judge "counted again as ROLLBACK was typed, right before the copy: "	    stop "REHEARSAL-102: the copy would begin here — no count was made as the answer was typed"
qlrb1	deploy	  [ "$qr_in" = "0" ] || QLRB1_BAD="$QLRB1_BAD $qr_rel:names-it×${qr_in:-?}"	  true || QLRB1_BAD="$QLRB1_BAD $qr_rel:names-it×${qr_in:-?}"
copymark	deploy	COPY_MARK="lib/KycService.php"	COPY_MARK="webhook.php"
qlunusable	deploy	if ($rd === "noanswer" && count($GLOBALS["dnbP"]) > $n0) $rd = "unusable";	if (false) $rd = "unusable";
qln1off	deploy	  if [ "$c" = "off" ] || [ "$x" = "off" ]; then note "QL-N1 the client attribute keyed salesPerson:	  if false; then note "QL-N1 the client attribute keyed salesPerson:
qlca2off	deploy	  elif [ "$c" = "off" ] || [ "$rel" = "off" ]; then note "QL-CA2 the client attribute keyed salesPerson, through the installed code's reading:	  elif false; then note "QL-CA2 the client attribute keyed salesPerson, through the installed code's reading:
qlca2ok	deploy	      one:*) if [ "$c" != "off" ] && [ "$rel" != "off" ] && [ "$rel" = "${a#one:}" ]; then ok "QL-CA2	      one:*) if [ "$rel" = "${a#one:}" ]; then ok "QL-CA2
qlattron	deploy	(new UcrmClientTarget($plug, ["on" => $m > 0] + $setting))->salesPersonFieldId()	(new UcrmClientTarget($plug, $setting))->salesPersonFieldId()
qlattrrel	deploy	$out[] = "qlattrrel=" . ($m <= 0 ? "off" : ($rel === null ? "none" : (string)(int)$rel));	$out[] = "qlattrrel=" . ($rel === null ? "none" : (string)(int)$rel);
v1rb	deploy	if do_rollback; then QLV1_RB=ok; else QLV1_RB=failed; fi	do_rollback || true; QLV1_RB=ok
rbnote	deploy	On Uganda, where the quoting organization is in force, the quotations and the Cash amount come again from the plugin's own plans and kits, as $BASELINE_VERSION makes them;	On Uganda the quotations and the Cash amount come again from the plugin's own plans and kits, as $BASELINE_VERSION makes them;
fgate	deploy	    off) F_QST=off ;;	    off|unread) F_QST=off ;;
ANCH
a102() { awk -F'\t' -v k="$1" '$1 == k { print $3; exit }' "$ANCH102"; }
r102() { awk -F'\t' -v k="$1" '$1 == k { print $4; exit }' "$ANCH102"; }
weak102() {   # weak102 NAME KEY… — a copy of the script in the clone's scripts/ with each KEY's anchor replaced, each exactly once
  local name="$1" k dst="$REPO/scripts/.$1.sh"; shift
  cp "$DEPLOY102" "$dst"
  for k in "$@"; do
    python3 - "$dst" "$(a102 "$k")" "$(r102 "$k")" <<'PY' || echo "weak102:$k" >> "$SB/anchor-missing"
import sys
f, a, b = sys.argv[1:4]; s = open(f, encoding='utf-8').read()
assert a and s.count(a) == 1, (a[:60], s.count(a))
open(f, 'w', encoding='utf-8').write(s.replace(a, b))
PY
  done
  echo "$dst"
}
# The readers: 5.18.101's, taught this release's folders in /tmp, by asserted replacements. ────────────────────────────
python3 - "$H101/rh101.py" "$H102/rh102.py" <<'PY' || { echo "readers:rh102" >> "$SB/anchor-missing"; echo "refusing: this rehearsal's readers could not be derived from the previous release's"; exit 2; }
import sys
s = open(sys.argv[1]).read()
reps = [
    ("root = re.compile(r'^/tmp/dnb-5\\.18\\.101-(?:cih-[^/]+/[^/]+/(?:t|d|p0root|k-(?:found|off|on)|c-(?:old|new)|g-(?:i|p|c0|c1)|l|u|t-(?:i|p))/dishnet-hybrid-sudan|code-[^/]+/p3b/[^/]+/dishnet-hybrid-sudan)(?:/|$)')",
     "root = re.compile(r'^/tmp/dnb-5\\.18\\.102-(?:cih-[^/]+/[^/]+/(?:t|d|p0root|k-(?:found|off|on)|c-(?:old|new)|g-(?:i|p|c0|c1)|l|u|t-(?:i|p))/dishnet-hybrid-sudan|code-[^/]+/p3b/[^/]+/dishnet-hybrid-sudan)(?:/|$)')"),
    ("if not env.get('DN_VAULT_FILE', '').startswith('/tmp/dnb-5.18.101-'): novault += 1",
     "if not env.get('DN_VAULT_FILE', '').startswith('/tmp/dnb-5.18.102-'): novault += 1"),
]
for old, new in reps:
    assert s.count(old) == 1, old[:70]
    s = s.replace(old, new)
open(sys.argv[2], 'w').write(s)
PY
# The observers search this release's folders in /tmp too — QL-RB2's copy of the book (qlrb) among them — (the fake
# docker's one list, extended, asserted), and the token observer counts this release's quoting folders beside the
# earlier ones'.
python3 - "$SB/bin/docker" <<'PY' || echo "fakedocker:observers102" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]; s = open(p).read()
old = ' /tmp/dnb-5.18.101-code-*/t4run; do\n'
assert s.count(old) == 1, s.count(old)
assert s.count('/tmp/dnb-5.18.102-') == 0
s = s.replace(old, ' /tmp/dnb-5.18.101-code-*/t4run /tmp/dnb-5.18.102-cih-* /tmp/dnb-5.18.102-ss-* /tmp/dnb-5.18.102-qlrb-* /tmp/dnb-5.18.102-code-*/p3b /tmp/dnb-5.18.102-code-*/t1run /tmp/dnb-5.18.102-code-*/t4run; do\n', 1)
old2 = 'printf \'%s %s\\n\' "$(find /tmp/dnb-5.18.99-cih-* /tmp/dnb-5.18.100-cih-* /tmp/dnb-5.18.101-cih-* -name token.json 2>/dev/null | grep -c .)" "${_rh_m:--}" >> "$FAKE_TOK_HITS"'
assert s.count(old2) == 1, s.count(old2)
s = s.replace(old2, 'printf \'%s %s\\n\' "$(find /tmp/dnb-5.18.99-cih-* /tmp/dnb-5.18.100-cih-* /tmp/dnb-5.18.101-cih-* /tmp/dnb-5.18.102-cih-* -name token.json 2>/dev/null | grep -c .)" "${_rh_m:--}" >> "$FAKE_TOK_HITS"', 1)
assert s.count('/tmp/dnb-5.18.102-') == 7
open(p, 'w').write(s)
PY
# Two triggers of this release's own, in the fake docker's prelude beside 5.18.96's and 5.18.99's hooks (inserted, as theirs
# were, before its row() line — asserted), each armed only by a file of this rehearsal's and spent once:
#   .rbfail102 — the first read of the container's record (exec ucrm cat …/.deployed-commit) that names 5.18.101 after one
#     that named 5.18.102 answers another commit: deploy-hybrid.sh, run by the deploy's own rollback after V1, sees the
#     copy land and its record read back wrong, and exits 1 — the rollback does not verify, while 5.18.101's files are in
#     place and the next read names it (102-6w);
#   .qorfail102 — the first folder the quoting checks make for their reading after the copy (qo_run r: mkdir -m 700
#     …/qo-r-N) refused: that reading stops before its gate probe, and stage A's (qo-a-N) is never touched (102-8).
cat > "$SB/docker102.hook" <<'HOOK'
# ── the next release's rehearsal (5.18.102): two triggers, each armed by a file of the rehearsal's, each spent once ──
if [ -f "@SB@/.rbfail102" ] && [ "$*" = "exec ucrm cat /data/ucrm/data/plugins/dishnet-hybrid-sudan/.deployed-commit" ]; then
  _rh_r="$(tail -n1 "@PD@/.deployed-commit" 2>/dev/null | tr -cd '0-9a-f')"
  if [ "$_rh_r" = "@P102@" ]; then : > "@SB@/.rbfail102.seen"
  elif [ "$_rh_r" = "@P101@" ] && [ -f "@SB@/.rbfail102.seen" ]; then rm -f "@SB@/.rbfail102" "@SB@/.rbfail102.seen"; echo 0000000; exit 0; fi
fi
if [ -f "@SB@/.qorfail102" ]; then
  case "$*" in *" mkdir -m 700 /tmp/dnb-5.18.102-cih-"*"/qo-r-"*) rm -f "@SB@/.qorfail102"; echo "mkdir: cannot create directory: refused by the rehearsal (102-8)" >&2; exit 1 ;; esac
fi
HOOK
python3 - "$SB/bin/docker" "$SB/docker102.hook" "$SB" "$PD" "$P101" "$P102" <<'PY' || echo "fakedocker:hook102" >> "$SB/anchor-missing"
import sys
p, hook, sb, pd, p101, p102 = sys.argv[1:7]
assert p101 and p102 and p101 != p102 and all(c in '0123456789abcdef' for c in p101 + p102), (p101, p102)
s = open(p).read()
h = open(hook).read().replace('@SB@', sb).replace('@PD@', pd).replace('@P101@', p101).replace('@P102@', p102)
assert '@' not in h.replace('"$@"', ''), 'a placeholder left'
lines = s.split('\n')
at = [i for i, l in enumerate(lines) if l.startswith('row() { grep "^$1|" ')]
assert len(at) == 1, len(at)
assert s.count('.rbfail102') == 0 and s.count('.qorfail102') == 0 and s.count('FAKE_TOK_HITS') == 3
lines[at[0]:at[0]] = h.rstrip('\n').split('\n')
s2 = '\n'.join(lines)
assert s2.count('.rbfail102') == 5 and s2.count('.qorfail102') == 2 and s2.count('/qo-r-') == 1 and s2.count('FAKE_TOK_HITS') == 3
assert s2.index('FAKE_TOK_HITS') < s2.index('.rbfail102') < s2.index('row() { grep "^$1|" ')
open(p, 'w').write(s2)
PY
rm -f "$SB/docker102.hook"
check "$(bash -n "$SB/bin/docker" && echo parses)|$(python3 -c 'import ast, sys; [ast.parse(open(f).read()) for f in sys.argv[1:]]; print("parses")' "$H102/rh102.py" "$H102/rh102q.py" 2>&1)|$(for f in "$H102"/rh102-*.php; do php -l "$f" 2>&1 | grep -c '^No syntax errors'; done | tr -d '\n')|$(grep -c '5\.18\.101-' "$H102/rh102.py")" \
      "parses|parses|11|0" \
  "control: the fake docker with this release's folders, this rehearsal's readers (5.18.101's derived, naming none of its folders, and its own) and its two PHP helpers parse"
# …and its two triggers, each fired here by hand: unarmed nothing happens; armed, the record's read answers the truth until
# one that named 5.18.102 has been seen, then another commit once; stage A's folder is never refused, the reading after
# the copy's once. The container's record is put back byte for byte, mtime included.
dk102() { env PATH="$SB/bin:$PATH" bash "$SB/bin/docker" "$@" 2>/dev/null; }
DC102=/data/ucrm/data/plugins/dishnet-hybrid-sudan/.deployed-commit
cp -p "$PD/.deployed-commit" "$SB/dc102.kept"
HK1="$(dk102 exec ucrm cat "$DC102" | tail -n1)"; : > "$SB/.rbfail102"; HK2="$(dk102 exec ucrm cat "$DC102" | tail -n1)"
printf '%s\n' "$P102" > "$PD/.deployed-commit"; HK3="$(dk102 exec ucrm cat "$DC102" | tail -n1):$( [ -f "$SB/.rbfail102.seen" ] && echo seen)"
cp -p "$SB/dc102.kept" "$PD/.deployed-commit"; HK4="$(dk102 exec ucrm cat "$DC102" | tail -n1)"; HK5="$(dk102 exec ucrm cat "$DC102" | tail -n1)"
: > "$SB/.qorfail102"
dk102 exec -u 1000:1000 ucrm true mkdir -m 700 /tmp/dnb-5.18.102-cih-rhctl/qo-a-1; HK6="$?:$( [ -f "$SB/.qorfail102" ] && echo kept || echo spent)"
dk102 exec -u 1000:1000 ucrm true mkdir -m 700 /tmp/dnb-5.18.102-cih-rhctl/qo-r-1; HK7="$?:$( [ -f "$SB/.qorfail102" ] && echo kept || echo spent)"
check "$HK1|$HK2|$HK3|$HK4|$HK5|$(ls -A "$SB" | grep -cE '^\.(rbfail102|rbfail102\.seen|qorfail102)$')|$HK6|$HK7|$(cmp -s "$SB/dc102.kept" "$PD/.deployed-commit" && [ "$(stat -c %Y "$SB/dc102.kept")" = "$(stat -c %Y "$PD/.deployed-commit")" ] && echo same)" \
      "$P101|$P101|$P102:seen|0000000|$P101|0|0:kept|1:spent|same" \
  "control: the fake docker's two triggers — the record read truly, unarmed and armed alike, until 5.18.102's has been seen; then another commit once, and the truth again; stage A's folder made, the reading after the copy's refused once; both spent, the record as it was"
rm -f "$SB/dc102.kept"
# The sign-off reader and the owner-rule reader are 5.18.101's rehearsal's (qs_read101, tn_read101 SCRIPT COMMIT): each
# takes the reader from the script it is given, between that script's own anchors — here 5.18.102's.
n3say102() {   # n3say102 CELLS STATUS — QL-N3's words for one status, from this rehearsal's own cells (rh102q.py apps)
  printf '%s' "$1" | tr ',' '\n' | awk -F'[.:]' -v s="$2" '$1 == s { t += $5; m[$2] += $5; l[$3] += $5; c[$4] += $5 }
      END { printf "%d (marked by 5.18.102 %d, not %d; lines with a productId %d, without one %d, none %d; Cash %d, Credit %d)", t, m["marked"], m["unmarked"], l["pid"], l["nopid"], l["none"], c["cash"], c["credit"] }'
}
d11say102() {   # d11say102 D11 — QL-N3's D11 sentence (the script's words, ql_d11_say), from this rehearsal's own count (rh102q.py
                # apps' d11: pending:<n>,review:<n>,p1:<n>,p2:<n>,fiber:<n>, the release's rules written out there)
  local p r a b f
  p="$(sed -nE 's/.*(^|,)pending:([0-9]+).*/\2/p' <<<"$1")"; r="$(sed -nE 's/.*(^|,)review:([0-9]+).*/\2/p' <<<"$1")"
  a="$(sed -nE 's/.*(^|,)p1:([0-9]+).*/\2/p' <<<"$1")"; b="$(sed -nE 's/.*(^|,)p2:([0-9]+).*/\2/p' <<<"$1")"; f="$(sed -nE 's/.*(^|,)fiber:([0-9]+).*/\2/p' <<<"$1")"
  [ -n "$p" ] && [ -n "$r" ] && [ -n "$a" ] && [ -n "$b" ] && [ -n "$f" ] || { printf 'd11say102: no count in %s' "${1:-nothing}"; return 0; }
  printf 'Whatever uCRM holds, the retry makes no quotation for %s of them (D11, docs/71 §F.4): %s Starlink sale(s) other than a Shifting Connection or an Ownership Change with no "Who installs?" answer — none saved before 5.18.102 carries one — so unpriced (pending %s, review %s; P1, Credit or no cash recorded, %s; P2, Cash with cash collected, %s), and %s Fiber sale(s), never priced from uCRM on this installation%s' \
    "$((p + r + f))" "$((p + r))" "$p" "$r" "$a" "$b" "$f" "$( [ $((p + r + f)) = 0 ] || echo " — each one's quotation is then made by hand in uCRM, or not at all (docs/71 §F.4, §F.5)")"
}
d11n102() {   # d11n102 D11 — F's own clause for the same count (F_D11N): "QL-N3 counted N such waiting at stage A — S Starlink (P1 a, P2 b) and f Fiber"
  local p r a b f
  p="$(sed -nE 's/.*(^|,)pending:([0-9]+).*/\2/p' <<<"$1")"; r="$(sed -nE 's/.*(^|,)review:([0-9]+).*/\2/p' <<<"$1")"
  a="$(sed -nE 's/.*(^|,)p1:([0-9]+).*/\2/p' <<<"$1")"; b="$(sed -nE 's/.*(^|,)p2:([0-9]+).*/\2/p' <<<"$1")"; f="$(sed -nE 's/.*(^|,)fiber:([0-9]+).*/\2/p' <<<"$1")"
  printf 'QL-N3 counted %s such waiting at stage A — %s Starlink (P1 %s, P2 %s) and %s Fiber' "$((p + r + f))" "$((p + r))" "$a" "$b" "$f"
}

echo; echo "== 102-pre. the sandbox as production is: 5.18.101 serving; the catalogue in the stand-in uCRM and the KYC form's rows in the store =="
check "$(live)|$(inst_ver)|$(installed102 "$P101")|$(left102)" "$P101|5.18.101|0|0" "pre-a 5.18.101 serving, every file 5.18.102 changes as 5.18.101 has it (101-7's --redeploy), none of the 21 it adds on disk"
[ -e "$RECS/dnb-5.18.101" ] || ln -s "$OUT101" "$RECS/dnb-5.18.101"
check "$(readlink -f "$RECS/dnb-5.18.101")|$(readlink -f "$RECS/dnb-5.18.100")|$(readlink -f "$RECS/dnb-5.18.99")|$(readlink -f "$RECS/dnb-5.18.98")|$(readlink -f "$RECS/dnb-5.18.97")|$( [ "$(grep -c '^COPY_AT=[0-9][0-9]*$' "$STATE101")" -ge 1 ] && echo copy)|$(grep -c '^STRAY_Q_FAILED=' "$STATE101")" \
      "$(readlink -f "$OUT101")|$(readlink -f "$OUT100")|$(readlink -f "$OUT99")|$(readlink -f "$OUT98")|$(readlink -f "$OUT97")|copy|0" \
  "pre-b 5.18.101's, 5.18.100's, 5.18.99's, 5.18.98's and 5.18.97's records where 5.18.102's A14 looks for them (<records>/dnb-5.18.x), as on the server; 5.18.101's state file holds its copy's time and no failure"
# ── The catalogue: every seeded name a marked secret (RH102SECRET-…, a random tail), every price a figure the sweeps look
# for. The stand-in's base state — written once here, 0600 as 5.18.99's rehearsal wrote it, so that every u99_set keeps it
# — gains Products (the five that price the form's rows, and three that must not count: another organization's, an
# inactive one, an archived one, each spelled like a priced row), Service Plans (the two plans' mirrors with an enabled
# 1-month period at their Products' price, a longer period beside each, and one no Product mirrors), the client
# attributes (EFRIS TIN at id 1; "Sales Person" keyed salesPerson at 47; the same key on a service field and on a field
# whose name ends in TIN — neither counts) and quotation 000314's items, read back by its id. The store's rows (seed102.json)
# are the KYC form's own kinds: two Starlink plans and a Fiber one active, one inactive; two kits active, one inactive; a
# retailer-app package active, one inactive; three applications for QL-N3 and QL-N4. Names to the sweeps' files, never
# printed. ─────────────────────────────────────────────────────────────────────────────────────────────────────────
NAMES102="$SB/names102.pat"; OFFERED102="$SB/offered102.pat"; NOWHERE102="$SB/nowhere102.pat"; AMOUNTS102="$SB/seed102.amounts"
python3 - "$U99/base.json" "$H102/seed102.json" "$NAMES102" "$OFFERED102" "$NOWHERE102" "$AMOUNTS102" <<'PY' || echo "ucrm102:base" >> "$SB/anchor-missing"
import sys, json, os, secrets
base, seedf, names, offered, nowhere, amounts = sys.argv[1:7]
st = json.load(open(base))
assert all(k not in st for k in ('products', 'plans', 'attrs', 'quote_detail', 'price_of')), sorted(st)
assert (os.stat(base).st_mode & 0o777) == 0o600, oct(os.stat(base).st_mode)
N = {k: 'RH102SECRET-%s-%s' % (k, secrets.token_hex(3)) for k in ('Plan-Alpha', 'Plan-Beta', 'Fiber-Delta', 'Plan-Gamma', 'Kit-Standard', 'Router-Mesh', 'Kit-Old', 'Package-Omega', 'Package-Sigma')}
Z = 'RH102QSECRET-Plan-Zeta-' + secrets.token_hex(3)
Q = ['RH102QSECRET-Line-%d-%s' % (i, secrets.token_hex(3)) for i in (1, 2, 3, 4)]
st['products'] = [
    {'id': 9101, 'name': N['Plan-Alpha'], 'price': 418273.55, 'unit': 'month', 'taxable': False, 'taxId': None, 'invoiceLabel': '', 'organizationId': 1},
    {'id': 9102, 'name': N['Plan-Beta'], 'price': 529384.65, 'unit': 'month', 'taxable': False, 'taxId': None, 'invoiceLabel': N['Plan-Beta']},
    {'id': 9103, 'name': N['Kit-Standard'], 'price': 1634275.45, 'unit': 'piece', 'taxable': False, 'taxId': None, 'invoiceLabel': ''},
    {'id': 9104, 'name': N['Router-Mesh'], 'price': 347281.35, 'unit': '', 'taxable': False},
    {'id': 9105, 'name': 'Professional Installation', 'price': 152637.85, 'unit': 'job', 'taxable': False, 'taxId': None},
    {'id': 9106, 'name': N['Plan-Alpha'], 'price': 111111.11, 'organizationId': 2},
    {'id': 9107, 'name': N['Kit-Standard'], 'price': 222222.22, 'isActive': False},
    {'id': 9108, 'name': N['Router-Mesh'], 'price': 333333.33, 'archived': True}]
st['plans'] = [
    {'id': 501, 'name': N['Plan-Alpha'], 'periods': [{'period': 1, 'price': 418273.55, 'enabled': True}, {'period': 3, 'price': 1254363.75, 'enabled': True}]},
    {'id': 502, 'name': N['Plan-Beta'], 'periods': [{'period': 1, 'price': 529384.65, 'enabled': True}, {'period': 12, 'price': 6352615.85, 'enabled': False}]},
    {'id': 503, 'name': Z, 'periods': [{'period': 1, 'price': 99999.95, 'enabled': True}]}]
st['attrs'] = [
    {'id': 1, 'name': 'EFRIS TIN', 'key': 'efrisTin', 'attributeType': 'client'},
    {'id': 47, 'name': 'Sales Person', 'key': 'salesPerson', 'attributeType': 'client'},
    {'id': 48, 'name': 'Sales Person', 'key': 'salesPerson', 'attributeType': 'service'},
    {'id': 50, 'name': 'Sales Person TIN', 'key': 'salesPerson', 'attributeType': 'client'}]
st['quote_detail'] = {'88017409': {'id': 88017409, 'number': '000314', 'items': [
    {'label': Q[0], 'price': 313131.35, 'quantity': 1, 'unit': 'month', 'type': 'product', 'productId': 9101},
    {'label': Q[1], 'price': 212121.25, 'quantity': 1, 'unit': 'piece', 'type': 'custom'},
    {'label': Q[2], 'price': 121212.15, 'quantity': 1, 'type': 'custom'}]}}
t = base + '.tmp'; fd = os.open(t, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600); os.write(fd, json.dumps(st).encode()); os.close(fd); os.replace(t, base)
seed = {
    'subscription_plans.json': [
        {'_rh102': True, 'name': N['Plan-Alpha'], 'type': 'starlink', 'is_active': True, 'customer_price': 777777.75, 'updated_at': 'rel:-3 days'},
        {'_rh102': True, 'name': N['Plan-Beta'], 'is_active': True, 'customer_price': 666666.65, 'updated_at': 'rel:-6 days'},
        {'_rh102': True, 'name': N['Fiber-Delta'], 'type': 'fiber', 'is_active': True, 'updated_at': 'rel:-9 days'},
        {'_rh102': True, 'name': N['Plan-Gamma'], 'is_active': False, 'updated_at': 'rel:-1 day'}],
    'kyc_devices.json': [
        {'_rh102': True, 'title': N['Kit-Standard'], 'is_active': True, 'price': 555555.55, 'created_at': 'rel:-10 days'},
        {'_rh102': True, 'title': N['Router-Mesh'], 'is_active': True, 'created_at': 'rel:-20 days'},
        {'_rh102': True, 'title': N['Kit-Old'], 'is_active': False, 'created_at': 'rel:-2 days'}],
    'kyc_packages.json': [{'_rh102': True, 'name': N['Package-Omega']}, {'_rh102': True, 'name': N['Package-Sigma'], 'is_active': False}],
    'kyc_applications.json': [
        {'_rh102': True, 'crm_sync_status': 'review', 'sales_type': 'Cash', 'quote_items': [{'label': Q[3], 'price': 444444.45, 'quantity': 1}], 'offer_price': 444444.45, 'submitted_at': 'rel:-5 days'},
        {'_rh102': True, 'crm_client_type': 'existing', 'submitted_at': 'rel:-20 days'},
        {'_rh102': True, 'submitted_at': 'rel:-120 days'}]}
def w(p, text):
    fd = os.open(p, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600); os.write(fd, text.encode()); os.close(fd)
w(seedf, json.dumps(seed))
w(names, ''.join(v + '\n' for v in list(N.values()) + [Z] + Q))
w(offered, ''.join(N[k] + '\n' for k in ('Plan-Alpha', 'Plan-Beta', 'Fiber-Delta', 'Kit-Standard', 'Router-Mesh', 'Package-Omega')))
w(nowhere, ''.join(v + '\n' for v in [N['Plan-Gamma'], N['Kit-Old'], N['Package-Sigma'], Z] + Q + ['RH102QSECRET-filler-']))   # the last: the 500 Products of products_n500 (1j), never read into a verdict
w(amounts, ''.join('%.2f\n' % a for a in (418273.55, 529384.65, 1634275.45, 347281.35, 152637.85, 111111.11, 222222.22, 333333.33, 1254363.75,
                                          6352615.85, 99999.95, 313131.35, 212121.25, 121212.15, 777777.75, 666666.65, 555555.55, 444444.45,
                                          287654.35)))   # the last: the cash of 1m's Cash sale with cash collected (D11, P2)
PY
chmod 644 "$H102/seed102.json"   # the store's user reads it through rows_add102's argument, never this file; kept readable for the rehearsal alone
NSECRET102="RH102QSECRET-prefix-$(openssl rand -hex 6)"; printf '%s\n' "$NSECRET102" >> "$NOWHERE102"   # QL-N6's text, typed into the store row once: never printed
U102_0="$(sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1; python3 "$H102/rh102q.py" apps "$DATA/plugin.sqlite3")"
RS102="$(rows_add102 "$(cat "$H102/seed102.json")")"
# The pending application 5.18.99's rehearsal seeded carries no plan price (offer_price): 5.18.101 saves one with every
# application it registers, so a pending application without one is 5.18.102's unpriced kind — QL-RB2's refusal. It is
# given the price 5.18.101 would have saved (1), asserted on exactly that row, so that QL-RB2 counts 0 before this
# rehearsal plants its own — the refusal later is the plant's alone. ─────────────────────────────────────────────────
OP102="$(sqn102 "UPDATE kyc_applications SET data = json_set(data, '\$.offer_price', 1) WHERE json_extract(data, '\$._rh99') = 1 AND json_extract(data, '\$.crm_sync_status') = 'pending' AND json_extract(data, '\$.offer_price') IS NULL")"
U102_1="$(apps102)"
check "$RS102|$OP102|$(python3 "$H102/rh102q.py" kv "$U102_0" unp)|$(python3 "$H102/rh102q.py" kv "$U102_1" unp)|$(sq "SELECT COUNT(*) FROM subscription_plans")|$(sq "SELECT COUNT(*) FROM kyc_devices")|$(sq "SELECT COUNT(*) FROM kyc_packages")" \
      '{"rows":12}|1|pending:1,manual:0,p1:0|pending:0,manual:0,p1:0|4|3|2' \
  "pre-c the KYC form's rows — four plans, three kits, two packages — and three applications added through the installed code's own writer; 5.18.99's one pending application given the plan price 5.18.101 saves: this rehearsal's own reading counted it unpriced-pending before (1) and none after (0)"
u99_set
python3 - "$U99/rh99-ucrm.php" <<'PY' || echo "ucrm102:routes" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]; s = open(p, encoding='utf-8').read()
old = "\n$out(['code' => 404, 'message' => 'Not Found'], 404);"
assert s.count(old) == 1, s.count(old)
assert s.rstrip('\n').endswith(old.lstrip('\n')), 'the final 404 is not the last line'
assert s.count("'/products'") == 0 and s.count('rh102') == 0
add = r'''
// 5.18.102's rehearsal (an asserted insertion before the final 404): the reads 5.18.102's catalogue checks make — GET
// products?limit, service-plans?limit, custom-attributes and billing/quotes/{id} — answered from the state, nothing
// written but limits.log beside this file: the limit VALUE each list read asked for (requests.log keeps the keys only).
// Toggles, each off unless set: products_500 and plans_500 (that list unreadable), token_products_403 (the token client
// refused the products list), products_n500 (200 with a list of 500 Products — the release's LIMIT, so it refuses the
// page: QL-5, review 1), products_html (200 with an HTML page, as a proxy's sign-in page would answer: not a JSON list),
// price_of ({"<product id>": <price>} — that product's price as uCRM shows it after an edit; 0 makes its row unpriced),
// attrs_none (no client attribute keyed salesPerson), attrs_two (a second one).
if ($p === '/rh102/ping') $out(['rh102' => 'catalogue-ready']);
if ($p === '/products' || $p === '/service-plans') {
    @file_put_contents($dir . '/limits.log', substr($p, 1) . ' ' . (is_scalar($q['limit'] ?? null) ? (string)$q['limit'] : '-') . "\n", FILE_APPEND | LOCK_EX);
}
if ($p === '/products') {
    if (!empty($st['products_html'])) { http_response_code(200); header('Content-Type: text/html; charset=UTF-8'); echo "<!DOCTYPE html>\n<html><head><title>Sign in</title></head><body><form method=\"post\"><input name=\"username\"></form></body></html>\n"; exit; }
    if (!empty($st['products_n500'])) { $f = []; for ($i = 1; $i <= 500; $i++) $f[] = ['id' => 20000 + $i, 'name' => 'RH102QSECRET-filler-' . $i, 'price' => 1000 + $i, 'unit' => 'piece', 'taxable' => false]; $out($f); }
    if (!empty($st['products_500'])) $out(['code' => 500, 'message' => 'Internal Server Error'], 500);
    if ($kind === 'token' && !empty($st['token_products_403'])) $out(['code' => 403, 'message' => 'Forbidden'], 403);
    $l = array_values((array)($st['products'] ?? []));
    foreach ((array)($st['price_of'] ?? []) as $pid => $pv) {
        foreach ($l as $i => $r) if (is_array($r) && (string)($r['id'] ?? '') === (string)$pid) $l[$i]['price'] = $pv;
    }
    $out($l);
}
if ($p === '/service-plans') {
    if (!empty($st['plans_500'])) $out(['code' => 500, 'message' => 'Internal Server Error'], 500);
    $out(array_values((array)($st['plans'] ?? [])));
}
if ($p === '/custom-attributes') {
    $a = [];
    foreach ((array)($st['attrs'] ?? []) as $r) {
        if (!empty($st['attrs_none']) && is_array($r) && ($r['key'] ?? '') === 'salesPerson' && (string)($r['attributeType'] ?? 'client') === 'client') continue;
        $a[] = $r;
    }
    if (!empty($st['attrs_two'])) $a[] = ['id' => 49, 'name' => 'Sales Person (second)', 'key' => 'salesPerson', 'attributeType' => 'client'];
    $out($a);
}
if (preg_match('#^/billing/quotes/([0-9]+)$#', $p, $m)) {
    $d = $st['quote_detail'][$m[1]] ?? null;
    if (!is_array($d)) $out(['code' => 404, 'message' => 'Not Found'], 404);
    $out($d);
}'''
s = s.replace(old, add + old, 1)
assert s.count(old) == 1 and s.count("'/rh102/ping'") == 1 and s.count("'/products'") == 2 and s.count("'/service-plans'") == 2 and s.count("'/custom-attributes'") == 1
open(p, 'w', encoding='utf-8').write(s)
PY
for i in $(seq 1 60); do [ "$(curl -s --noproxy '*' -H "X-Auth-App-Key: $APPKEY99" "$U99_URL/api/v2.1/rh102/ping" 2>/dev/null)" = '{"rh102":"catalogue-ready"}' ] && break; sleep 0.1; done   # PHP re-reads the edited router
ug102() { curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "$@"; }
u99_state_102() { python3 -c 'import json, sys; print("base" if json.load(open(sys.argv[1])) == json.load(open(sys.argv[2])) else "changed")' "$U99/base.json" "$U99/state.json" 2>/dev/null || echo unread; }   # the stand-in's state: its base, or changed
: > "$U99/requests.log"; : > "$U99/limits.log"
UC102="$(ug102 -H "X-Auth-App-Key: $APPKEY99" "$U99_URL/api/v2.1/products?limit=500")|$(ug102 -H "x-auth-token: $TOKEN99" "$U99_URL/api/v2.1/service-plans?limit=500")|$(ug102 -H "X-Auth-App-Key: $APPKEY99" "$U99_URL/api/v2.1/custom-attributes")|$(ug102 -H "X-Auth-App-Key: $APPKEY99" "$U99_URL/api/v2.1/billing/quotes/88017409")|$(ug102 -H "X-Auth-App-Key: $APPKEY99" "$U99_URL/api/v2.1/billing/quotes/88017401")|$(ug102 -X POST -H "X-Auth-App-Key: $APPKEY99" "$U99_URL/api/v2.1/products")"
check "$UC102|$(python3 "$H102/rh102q.py" u102req "$U99/requests.log")|$(python3 "$H102/rh102q.py" limits "$U99/limits.log")|$(python3 "$H99/rh99.py" u99other "$U99/requests.log")|$(secrets99 "$U99/requests.log" "$U99/limits.log")" \
      "200|200|200|200|404|404|total=6 other=1 products=1/0 plans=0/1 attrs=1 quote=2 odd=0|n=2 bad=0|POST /products app-key|0" \
  "control: the stand-in answers the four new reads with its key and the token (an unknown quote 404), a POST 404 — and records each by method, path and key, the limit values apart, never a value"
# …and its two answers below 400 that the release must refuse (QL-5, review 1): 500 Products — the release's LIMIT — and an
# HTML page; each through either client, the rows read into this check alone (counted, never printed); the base again after.
u99_set 'products_n500=true'
QN500102="$(curl -s --noproxy '*' -H "X-Auth-App-Key: $APPKEY99" "$U99_URL/api/v2.1/products?limit=500" | python3 -c 'import json, sys; d = json.load(sys.stdin); print(len(d) if isinstance(d, list) else "notlist")' 2>/dev/null)|$(ug102 -H "x-auth-token: $TOKEN99" "$U99_URL/api/v2.1/products?limit=500")"
u99_set 'products_html=true'
QHTML102="$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}:%{content_type}' -H "X-Auth-App-Key: $APPKEY99" "$U99_URL/api/v2.1/products?limit=500")|$(curl -s --noproxy '*' -H "x-auth-token: $TOKEN99" "$U99_URL/api/v2.1/products?limit=500" | python3 -c 'import json, sys
try: json.loads(sys.stdin.read()); print("json")
except ValueError: print("notjson")' 2>/dev/null)"
u99_set
check "$QN500102|${QHTML102%%;*}|${QHTML102##*|}|$(ug102 -H "X-Auth-App-Key: $APPKEY99" "$U99_URL/api/v2.1/products?limit=500")" "500|200|200:text/html|notjson|200" \
  "control: the stand-in's products_n500 answers 200 with a list of exactly 500 Products, and products_html 200 with an HTML page that is not JSON, through the plugin's key and the token alike; the base state answers 200 again"
: > "$U99/requests.log"; : > "$U99/limits.log"
# ── The RELEASE's own resolver on the store's offered rows and the stand-in — not the script's probe: the reference the
# probe's counts are set against (c3), and the proof that the seeded catalogue really resolves. Its code from Git, in a
# folder of this rehearsal's; the store read-only. ────────────────────────────────────────────────────────────────────
REF102="$H102/ref"; rm -rf "$REF102"; mkdir -p "$REF102"; git -C "$REPO" archive "$P102" "$P/lib" | tar -xf - -C "$REF102"
ref102() { sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1; RH102_TOKEN="$TOKEN99" "$PHPBIN" "$H102/rh102-ref.php" "$REF102/$P" "$PD" "$DATA/plugin.sqlite3" "$1" 2>/dev/null; }
REFA102="$(ref102 app)"; REFT102="$(ref102 token)"
u99_set 'price_of={"9104":0}'; REFA102Z="$(ref102 app)"; REFT102Z="$(ref102 token)"; u99_set
check "$REFA102|$REFT102|$REFA102Z|$REFT102Z|$(python3 "$H102/rh102q.py" u102req "$U99/requests.log" | sed -E 's/^total=[0-9]+ //')" \
      "rows:5,ok:5,unpriced:0,fiber:1,zero:0,other:0|rows:5,ok:5,unpriced:0,fiber:1,zero:0,other:0|rows:5,ok:4,unpriced:1,fiber:1,zero:1,other:0|rows:5,ok:4,unpriced:1,fiber:1,zero:1,other:0|other=0 products=2/2 plans=2/2 attrs=0 quote=0 odd=0" \
  "pre-d the RELEASE's own UcrmQuoteLines (from Git) prices every row the KYC form offers here — two plans, two kits, the installation; the Fiber plan apart — through the plugin's key and the token alike; with one Product's price 0, exactly that row is unpriced, for its zero price"
: > "$U99/requests.log"; : > "$U99/limits.log"
O102_0="$(o100)"
check "$( [ "$(qj "$O102_0" sales)" -gt 0 ] && echo sales)|$( [ "$(qj "$O102_0" tech)" -ge 2 ] && echo tech)|$(qj "$O102_0" inbox)|$(set99 kyc_quote_notes_prefix)|$(set99 crm_auth_token)|$(set99 crm_base_url)" "sales|tech|unset|unset/unset|unset/set|unset/unset" \
  "pre-e 5.18.100's rehearsal's staff records for the carried owner checks; kyc_quote_notes_prefix unset in both copies; the admin token in the store row alone, no crm_base_url: the token client reads through the plugin's base URL"
D102="$(data94)"; KEPT102="$(kept_state)"; U102_A="$(apps102)"; N3_102="$(python3 "$H102/rh102q.py" kv "$U102_A" n3)"
D11_102="$(python3 "$H102/rh102q.py" kv "$U102_A" d11)"   # QL-N3's D11 count of the store as seeded, by this rehearsal's own reading (review 2, L1)
PLAN_D102="$(sq "SELECT MAX(substr(json_extract(data, '\$.updated_at'), 1, 10)) FROM subscription_plans")"
KIT_D102="$(sq "SELECT MAX(substr(json_extract(data, '\$.created_at'), 1, 10)) FROM kyc_devices")"
# What every reading of the catalogue must say, as the release's resolver says it (pre-d) and the probe prints it.
QLR0="notprod:0,ambig:0,differs:0,taxable:0,decimals:0,label:0,zero:0"; QLR1="notprod:0,ambig:0,differs:0,taxable:0,decimals:0,label:0,zero:1"
QLC_OK="plug:rows:5,ok:5,unpriced:0,fiber:1,unread:0,other:0;tok:rows:5,ok:5,unpriced:0,fiber:1,unread:0,other:0;dbg:same"
QLR_OK="plug:$QLR0;tok:$QLR0;dbg:same"
QLC_ONE="plug:rows:5,ok:4,unpriced:1,fiber:1,unread:0,other:0;tok:rows:5,ok:4,unpriced:1,fiber:1,unread:0,other:0;dbg:same"
QLR_ONE="plug:$QLR1;tok:$QLR1;dbg:same"
QLPK_OK="plug:rows:1,ok:0,unpriced:1,fiber:0,unread:0,other:0;tok:rows:1,ok:0,unpriced:1,fiber:0,unread:0,other:0;dbg:same"
SAY_OK="5 row(s) — 5 resolve, 0 unpriced, 1 Fiber (apart)"; SAY_ONE="5 row(s) — 4 resolve, 1 unpriced, 1 Fiber (apart)"
QLG1_OK="  ok    QL-G1 every row the KYC form offers resolves through the RELEASE's UcrmQuoteLines on a copy, GET-only — GET products?limit=500, then GET service-plans?limit=500, nothing else asked: through the plugin's own client $SAY_OK; through the token client $SAY_OK — its active plans and kits and the installation product; names on the sheet"
# The unpriced rows, client by client, as ql_unp_say says them — never the two clients' verdicts summed as rows (QL-1):
# with their reasons (QL-G1's NO-GO and note, QL-CA1's notes), and as counts alone (QL-CA1's comparison with stage A).
SAYU_ONE="1 row(s) through the plugin's own client (not priced above 0 1) and 1 through the token client (not priced above 0 1), the same rows judged by each"
UNP_ONE="1 row(s) through the plugin's own client and 1 through the token client, the same rows judged by each"
UNP_NONE="0 row(s) through the plugin's own client and 0 through the token client, the same rows judged by each"
check "${REFA102%%,zero:*}|${REFT102%%,zero:*}" "$(sed -E 's/^plug:(rows:[0-9]+,ok:[0-9]+,unpriced:[0-9]+,fiber:[0-9]+).*/\1/' <<<"$QLC_OK")|$(sed -E 's/.*;tok:(rows:[0-9]+,ok:[0-9]+,unpriced:[0-9]+,fiber:[0-9]+).*/\1/' <<<"$QLC_OK")" \
  "control: what this rehearsal expects the probe to print (rows, resolve, unpriced, Fiber, per client) is what the release's own resolver read in pre-d"

echo; echo "== 102-0. what is rehearsed: 5.18.102 — the controls =="
echo "  the script     $WHICH102"
check "$P102|$P102F" "$(git -C "$REPO" rev-parse --short "origin/$REL102" 2>/dev/null || echo none)|$(git -C "$REPO" rev-parse "origin/$REL102" 2>/dev/null || echo none)" "the script is pinned to the release commit on $REL102, short and in full ($P102)"
check "$(git -C "$REPO" rev-parse --short "$P102^" 2>/dev/null)|$(git -C "$REPO" rev-parse "$P102^" 2>/dev/null)|$(ver "$P102")$(ver "$P101")" "$P101|$(sed -n 's/^BASELINE_COMMIT_FULL="\([^"]*\)".*/\1/p' "$DEPLOY102")|5.18.1025.18.101" \
  "…cut on 5.18.101 ($P101), the script's baseline in full; the pin says 5.18.102, its parent 5.18.101"
check "$(dl D "$P101" "$P102" | grep -c .)|$(dl A "$P101" "$P102" | grep -c .)|$(dl AM "$P101" "$P102" | grep -c .)|$(git -C "$REPO" diff --no-renames --name-only --diff-filter=CRTUXB "$P101" "$P102" | grep -c .)|$(git -C "$REPO" diff --name-only "$P101" "$P102" | grep -vc "^$P/")" "0|$NA102|$N102|0|0" \
  "the delta: $N102 files under the plugin, $NA102 of them added, none deleted, renamed or retyped, nothing outside it"
check "$(dl A "$P101" "$P102" | sed "s#^$P/##" | LC_ALL=C sort | paste -sd' ' -)|$(dl M "$P101" "$P102" | sed "s#^$P/##" | LC_ALL=C sort | paste -sd' ' -)|$(sed -n 's/^EXPECTED_ADDED="\(.*\)"$/\1/p' "$DEPLOY102")|$(sed -n 's/^EXPECTED_CHANGED="\(.*\)"$/\1/p' "$DEPLOY102")" \
      "$ADDED102|$CHANGED102|$ADDED102|$CHANGED102" "…exactly the reviewed files, added and changed, as the script lists them"
check "$(dl AM "$P101" "$P102" | grep -c "^$P/migrations/")" "0" "no migration"
PS102="$(sed -n '/^PIN_SHA256S="$(cat <<.SUMS.$/,/^SUMS$/p' "$DEPLOY102" | grep -E '^[0-9a-f]{64}  ' | while read -r s f; do [ "$(git -C "$REPO" show "$P102:$P/$f" | sha256sum | cut -c1-64)" = "$s" ] && echo ok || echo "bad:$f"; done | sort | uniq -c | tr -s ' ' | paste -sd, -)"
KS102="$(sed -n '/^PREV_KEPT_SHA256S="$(cat <<.SUMS.$/,/^SUMS$/p' "$DEPLOY102" | grep -E '^[0-9a-f]{64}  ' | while read -r s f; do a="$(git -C "$REPO" show "$P101:$P/$f" | sha256sum | cut -c1-64)"; b="$(git -C "$REPO" show "$P102:$P/$f" | sha256sum | cut -c1-64)"; [ "$a|$b" = "$s|$s" ] && echo ok || echo "bad:$f"; done | sort | uniq -c | tr -s ' ' | paste -sd, -)"
PC102="$(sed -n '/^PREV_CHANGED_SHA256S="$(cat <<.SUMS.$/,/^SUMS$/p' "$DEPLOY102" | grep -E '^[0-9a-f]{64}  ' | while read -r s f; do [ "$(git -C "$REPO" show "$P101:$P/$f" | sha256sum | cut -c1-64)" = "$s" ] && echo ok || echo "bad:$f"; done | sort | uniq -c | tr -s ' ' | paste -sd, -)"
check "$PS102|$KS102|$PC102|$(sed -n 's/^PIN_SHA_N=\([0-9]*\)$/\1/p;s/^PREV_KEPT_N=\([0-9]*\)$/\1/p;s/^PREV_CHANGED_N=\([0-9]*\)$/\1/p' "$DEPLOY102" | paste -sd, -)" " 59 ok| 55 ok| 38 ok|55,38,59" \
  "every sha256 the script pins, computed here from Git: the 59 release files at the pin, the 55 kept ones in both commits, the 38 changed ones at 5.18.101 — and the counts it states"
KEEPL102="$(sed -n '/^PREV_KEPT_SHA256S="$(cat <<.SUMS.$/,/^SUMS$/p' "$DEPLOY102")"
check "$(for f in webhook.php cron_quote_wa.php lib/QuoteOrganization.php lib/QuoteTaxLine.php lib/TenantProfile.php lib/PublicPriceFeed.php lib/EfrisClientField.php includes/api/api_retailer.php retailer/index.html lib/SalesNumbersAdmin.php; do grep -cE "  ${f//./\\.}\$" <<<"$KEEPL102"; done | paste -sd' ' -)|$(for f in $ADDED102 $CHANGED102; do grep -cE "  ${f//./\\.}\$" <<<"$KEEPL102"; done | sort -u | paste -sd, -)" \
      "1 1 1 1 1 1 1 1 1 1|0" \
  "…the kept files hold webhook.php (the sign-off 5.18.101 made), cron_quote_wa.php, the quoting files 5.18.102 keeps byte for byte (QuoteOrganization, QuoteTaxLine, TenantProfile, PublicPriceFeed, EfrisClientField), the retailer app's two and 5.18.100's owner rule, once each — and none of the 59 files the release adds or changes"
check "$(sed -n 's/^COPY_MARK="\([^"]*\)".*/\1/p' "$DEPLOY102")|$(grep -cxF "$P/$(sed -n 's/^COPY_MARK="\([^"]*\)".*/\1/p' "$DEPLOY102")" <<<"$(dl M "$P101" "$P102")")|$(sed -n 's/^T1_SIGNATURE="\([^"]*\)".*/\1/p' "$DEPLOY102")" \
      "lib/KycService.php|1|$(sed -n 's/^T1_SIGNATURE="\([^"]*\)".*/\1/p' "$DEPLOY101")" \
  "the copy's time is read off a file this release changes (COPY_MARK, lib/KycService.php — 5.18.101's webhook.php is not touched by it); T1's count 5.18.101's"
H102B="$(sed -n '/^# The deploy\. Run as root/,/^# The rollback is a separate command/p' "$DEPLOY102")"
H102H="$(sed -n '1,/^set -uo pipefail$/p' "$DEPLOY102")"
check "$(grep -cxF '#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.102 && mkdir -p /root/dnb-5.18.102 && bash scripts/deploy-5.18.102.sh 2>&1 | ( umask 077; exec tee /root/dnb-5.18.102/deploy-$(date -u +%Y%m%dT%H%M%SZ).log )' <<<"$H102B")$(grep -cxF '#   cd /opt/dishnet && bash scripts/deploy-5.18.102.sh --after-only 2>&1 | ( umask 077; exec tee /root/dnb-5.18.102/after-$(date -u +%Y%m%dT%H%M%SZ).log )' <<<"$H102B")$(grep -cE -- '--rollback|--put-back|--redeploy|git checkout [0-9a-f]{7}' <<<"$H102B")|$(grep -cE '^#   cd ' <<<"$H102B")|$(grep -cE '^#   cd ' <<<"$H102H")|$(grep -cE '^#   cd .*--(accept-unpriced|rollback)' <<<"$H102H")|$(grep -c '^# The deploy\. Run as root' <<<"$H102H")$(grep -c '^# The rollback is a separate command' <<<"$H102H")" \
      "110|2|2|0|11" "the header's two commands, between its two marker lines (each once), each alone on its line with its own log — the deploy carrying no --accept-unpriced; no rollback among them, and no other command in the header"
check "$(for s102 in '--rollback counts in stage A and again as ROLLBACK is typed, right before the copy' 'rollback (V1, when the public sign-in page redirects) never waits on it' 'redirect — but counts after it and says the counts and the hazard in its notes and its stop line' 'by-hand put-back counts nothing, and every message that prints it says so'; do grep -cF -- "$s102" <<<"$H102H"; done | paste -sd' ' -)|$( [ "$(grep -cF 'waiting for uCRM with no plan price' <<<"$H102H")" -ge 1 ] && echo named)|$(grep -cE 'could not price|saved unpriced|left unpriced' <<<"$H102H")" \
      "1 1 1 1|named|0" \
  "…and the header says QL-RB2's two counts — stage A's and the one as ROLLBACK is typed, before the copy — the deploy's own rollback (V1) counting after it and never waiting, and the by-hand put-back counting nothing; it names the applications as waiting for uCRM with no plan price recorded, never as ones 5.18.102 could not price"
check "$(for s102 in 'An application saved before 5.18.102 carries no "Who' 'Q-CA6 the installed profile,' 'the proof that its Cashbook reads write' 'After it, on Uganda, where the quoting organization is in force, the quotations' "When V1's rollback does not verify, its stop says the rollback did not complete" 'not a product in uCRM (or one with no id, or a name that cannot be'; do grep -cF -- "$s102" <<<"$H102H"; done | paste -sd' ' -)|$(grep -cF 'the proof that the release writes nothing to the book' <<<"$H102H")" \
      "1 1 1 1 1 1|0" \
  "…and review 2's words in the header, each once: D11 (L1), Q-CA6 described (L10), C5's proof as its Cashbook reads (L4), the rollback's on Uganda where the quoting organization is in force (E2-5), V1's rollback that does not verify (E2-3), the reasons' categories by all they hold (L3) — never \"the release writes nothing to the book\""
echo "  …    the sign-off and the owner rule as text, at the pin, at 5.18.101 and at 5.18.100, by the script's own readers:"
check "$(qs_read101 "$DEPLOY102" "$P102")|$(qs_read101 "$DEPLOY102" "$P101")|$(qs_read101 "$DEPLOY102" "$P100")" \
      "$(sed -n 's/^QS_TEXT_PIN="\([^"]*\)".*/\1/p' "$DEPLOY102")|$(sed -n 's/^QS_TEXT_BASE="\([^"]*\)".*/\1/p' "$DEPLOY102")|$(sed -n 's/^QS_TEXT_CONTROL="\([^"]*\)".*/\1/p' "$DEPLOY102")" \
  "control: QS-G1's reader gives the pin QS_TEXT_PIN and 5.18.101 QS_TEXT_BASE (the same line: 5.18.102 keeps webhook.php), and 5.18.100, its negative control, QS_TEXT_CONTROL — all read from Git here"
check "$(tn_read101 "$DEPLOY102" "$P102")|$(tn_read101 "$DEPLOY102" "$P101")|$(tn_read101 "$DEPLOY102" "$P99")" \
      "$(sed -n 's/^TN_TEXT_PIN="\([^"]*\)".*/\1/p' "$DEPLOY102")|$(sed -n 's/^TN_TEXT_BASE="\([^"]*\)".*/\1/p' "$DEPLOY102")|$(sed -n 's/^TN_TEXT_BASE="\([^"]*\)".*/\1/p' "$DEPLOY100")" \
  "control: TN-G1's reader gives the pin and 5.18.101 the same rule (TN_TEXT_PIN, TN_TEXT_BASE: 5.18.102 changes neither file), and 5.18.99 the rule 5.18.100's script called its base"
check "$(sed -n 's/^QS_TEXT_PIN="\([^"]*\)".*/\1/p' "$DEPLOY102")|$(sed -n 's/^QS_TEXT_CONTROL="\([^"]*\)".*/\1/p' "$DEPLOY102")|$(git -C "$REPO" show "$P102:$P/webhook.php" | sha256sum | cut -c1-16)" \
      "new=1 old=0 phrase=1 gate=1|new=0 old=1 phrase=1 gate=1|$(git -C "$REPO" show "$P101:$P/webhook.php" | sha256sum | cut -c1-16)" \
  "…and the reader tells the two lines apart: the pin's new line once, 5.18.100's old one once — webhook.php byte for byte 5.18.101's at the pin"
RB1M102="$(sed -n "/^QL_RB1_MARKS=\"\$(cat <<'MARKS'\$/,/^MARKS\$/p" "$DEPLOY102" | grep -F '|')"
check "$(grep -c . <<<"$RB1M102")|$(while IFS='|' read -r f m n; do printf '%s:%s:%s\n' "$( [ "$(git -C "$REPO" show "$P102:$P/$f" | grep -cF -- "$m")" = "$n" ] && echo pin || echo "pin-differs")" "$(git -C "$REPO" show "$P101:$P/$f" | grep -cF -- "$m")" "$(grep -cxF "$P/$f" <<<"$(dl M "$P101" "$P102")")"; done <<<"$RB1M102" | sort | uniq -c | tr -s ' ' | paste -sd, -)" \
      "8| 8 pin:0:1" \
  "QL-RB1's eight markers, counted in Git: each file names its marker at the pin exactly as the script pins it, 5.18.101's file names it nowhere, and each is a file the release changes"

echo; echo "== 102-c. --check: stage A alone — GO on 5.18.101 as production has it; the catalogue and its notes =="
FP102="$(fp99)"; fresh102; S102C="$(states102)"; OUT="$(run102 --check)"; TC="$(last102)"; SH102C="$(ls -1 "$OUT102"/cash-*.txt 2>/dev/null | head -1)"
check "$(checkgo102 "$OUT")" "yesyesnono0|0|$S102C" "c1 --check is GO, never asks, backs nothing up and writes no state file"
check "$(has "$OUT" "$QLG1_OK")|$(qor102 "$OUT" 1 qlc)|$(qor102 "$OUT" 1 qlr)|$(qor102 "$OUT" 1 qlpk)|$(qor102 "$OUT" 1 qlread)|$(qor102 "$OUT" 1 qlpath)|$(qor102 "$OUT" 1 qlattr)|$(qor102 "$OUT" 1 qlattrrel)|$(qor102 "$OUT" 1 ql314)" \
      "yes|$QLC_OK|$QLR_OK|$QLPK_OK|plug:read;tok:read;dbg:same|ok|one:47|47|notfound" \
  "c2 QL-G1 ok — every row resolves through the pin's resolver, through the plugin's key and the token, the Fiber plan apart; the probe's counts (qlc, qlr, qlpk), read states, its own count of the two lists, the attribute and 000314, as the release's resolver gives them (pre-d)"
check "$(qol102 "$OUT" qloffer)|$(qol102 "$OUT" qln3)|$(qol102 "$OUT" qln4)|$(qol102 "$OUT" qln5)" \
      "plans:3,kits:2,packages:1,written:1|$(python3 "$H102/rh102q.py" kv "$U102_A" n3)|all:$(python3 "$H102/rh102q.py" kv "$U102_A" all),existing:$(python3 "$H102/rh102q.py" kv "$U102_A" existing),nodate:$(python3 "$H102/rh102q.py" kv "$U102_A" nodate),ep:unmeasurable|plan:$PLAN_D102,kit:$KIT_D102" \
  "c3 the local reading: the rows the form offers (three active plans, the Fiber one among them; two active kits; one package), the applications by line shape and sale type and the 90 days' counts as this rehearsal's own reading gives them, the newest plan save and kit as dates"
check "$(noteline "$OUT" QL-N1 'exactly one, #47' 'answers the same')$(noteline "$OUT" QL-N2 'quotation 000314: not found in the book' 'no request was made')$(noteline "$OUT" QL-N3 "pending $(n3say102 "$N3_102" pending); review $(n3say102 "$N3_102" review)" 'none carries 5.18.102')$(noteline "$OUT" QL-N4 "(submitted_at): $(python3 "$H102/rh102q.py" kv "$U102_A" all) — of them an existing customer's $(python3 "$H102/rh102q.py" kv "$U102_A" existing) (a request carrying a customer_id" "$( [ "$(python3 "$H102/rh102q.py" kv "$U102_A" nodate)" = "0" ] && echo 'the record carries none' || echo "; $(python3 "$H102/rh102q.py" kv "$U102_A" nodate) with no date, not counted")")$(noteline "$OUT" QL-N5 "(subscription_plans updated_at) $PLAN_D102, the newest kit added (kyc_devices created_at) $KIT_D102")$(noteline "$OUT" QL-N6 'kyc_quote_notes_prefix unset/unset')$(noteline "$OUT" QL-N7 "through the plugin's own client 1 row(s) — 0 resolve, 1 unpriced, 0 Fiber (apart), unclassified 0" 'never toward the NO-GO')" \
      "yesyesyesyesyesyesyes" \
  "c4 QL-N1–QL-N7 as seeded: the salesPerson attribute #47 (the service field and the tax-named one left out), 000314 not in the book, the pending and review applications by shape and the 90 days as this rehearsal's own reading counts them, the dates, the notes prefix unset, the unpriced package a note only"
check "$(qol102 "$OUT" qln3d11)|$(noteline "$OUT" QL-N3 "After the deploy the retry takes them from uCRM's prices alone (docs/71 §F.4): marked lines are confirmed again and the rest resolved again; a Cash one with cash collected is quoted only at exactly that cash, else not at all (P2). $(d11say102 "$D11_102")")|$(grep '^  note  QL-N3 ' <<<"$OUT" | grep -c "which the retry quotes after the deploy from uCRM's prices alone")" \
      "$D11_102|yes|0" \
  "c4b QL-N3, the quoting organization in force: the retry takes the applications from uCRM's prices alone — and, of them, those it cannot price whatever uCRM holds (no \"Who installs?\" answer saved before 5.18.102; Fiber) counted as this rehearsal's own reading of the release's rules counts them, said with the probe's own count (review 2, L1; D11, docs/71 §F.4); never round 1's \"which the retry quotes … from uCRM's prices alone\""
check "$(okline "$OUT" QS-G1 'as text: the pin')$(okline "$OUT" TN-G1 'as text')$(okline "$OUT" TN-G2 "offer the same $(qj "$O102_0" sales) active salesperson account(s) and the same $(qj "$O102_0" tech) active technician account(s)")$(okline "$OUT" TN-G3 'wa_inbox_roles is unset')$(okline "$OUT" A6 'South Sudan stays exactly as it is')$(okline "$OUT" A22-T1 "under this server's own PHP")$(okline "$OUT" Q-G3 'quoting organization #1')" \
      "yesyesyesyesyesyesyes" \
  "c5 the carried checks ok — QS-G1 (5.18.101's), TN-G1–TN-G3 (5.18.100's), and A6 and A22-T1, whose signatures 5.18.102 carries from 5.18.101 (SS/UG, B2, P90; T1), measured here on the release commit"
check "$(noteline "$OUT" Q-N7 "from Sync All under 5.18.101 (L1); after the deploy, with the quoting organization #1 in force, the three screens show the server's answer instead: a non-admin \"Admin only.\", an admin the plugin's own refusal \"uCRM's product was not changed: on Uganda, prices are set in uCRM Products.\" (D12)")$(noteline "$OUT" Q-N7 'the gate is off')" "yesno" \
  "c5b Q-N7, carried, says what the Sync All screens show after the deploy with the quoting organization in force — the server's \"Admin only.\" and the plugin's own refusal (D12) — never \"the gate is off\""
TOT102C="$(ureq102 "$TC" | sed -E 's/^total=([0-9]+) .*/\1/')"
check "$(ureq102 "$TC" | sed -E 's/^total=[0-9]+ //')|$TOT102C|$(lim102 "$TC")|$(uother102 "$TC")" "other=0 products=1/1 plans=1/1 attrs=2 quote=0 odd=0|$(gets102 "$OUT")|n=4 bad=0|-" \
  "c6 the stand-in was asked the two lists once through each client, with limit=500, the attributes twice (the probe's reading and the release's own lookup), no quote — every request a GET, as many as F counts"
check "$(stat -c %a "$SH102C" 2>/dev/null)|$(stat -c %a "$OUT102")|$(grep -cF -f "$OFFERED102" "$SH102C" 2>/dev/null | awk '{print ($1 >= 6 ? "names" : "few:" $1)}')|$(while IFS= read -r n; do grep -qF -- "$n" "$SH102C" && echo y || echo n; done < "$OFFERED102" | sort -u | paste -sd, -)|$(grep -lF -f "$NOWHERE102" "$SH102C" 2>/dev/null | grep -c .)|$(grep -c '→ uCRM product #910[1-5], ' "$SH102C" 2>/dev/null)" \
      "600|700|names|y|0|10" \
  "c7 the sheet, 0600 in a folder of mode 700, holds every offered row by name — each priced row with its uCRM product and unit through both clients — and none of the inactive rows, the unoffered plan or 000314's lines"
check "$(grep -c '^== QL-G1 the catalogue the KYC form offers — ' "$SH102C" 2>/dev/null)|$(grep -c '^== QL-CA1 the catalogue the KYC form offers — ' "$SH102C" 2>/dev/null)" "1|0" \
  "c7b the sheet's catalogue section carries the check's own name — stage A's reading is QL-G1's (review 2, E2-7); a --check makes no reading after a copy, so no QL-CA1 section"
check "$(fp99)|$(grep -lF -f "$NAMES102" "$R102/run-$TC.txt" "$OUT102"/*.log 2>/dev/null | grep -c .)|$(names100 "$R102/run-$TC.txt")|$(audit102 "$TC" | sed -E 's/^copies=[0-9]+ //; s/ adj=.*//')|$(vault102 "$TC" | awk -F'>' '{print ($1 == $2 ? "same" : "moved")}')|$(cache102)" \
      "$FP102|0|0|novault=0 plugin=0|same|0" \
  "c8 nothing changed — tree, store, vault, settings, ucrm.json; no seeded name in the terminal or the log; every PHP process on a copy with a vault of its own; no page cache written anywhere"

echo; echo "== 102-1. the catalogue's refusals, each before anything changes; the flag; the toggles; the weakened copies =="
u99_set 'price_of={"9104":0}'; fresh102; OUT="$(run102 --answer NO)"; SH102A="$(ls -1 "$OUT102"/cash-*.txt 2>/dev/null | head -1)"
nogo102 "1a one row the form offers priced 0 in uCRM: NO-GO at QL-G1, before the question" "NO-GO (QL-G1): row(s) the KYC form offers do not resolve in uCRM through the RELEASE's resolver — $SAYU_ONE; each by name, with the resolver's reason, on the cash sheet (less " "$OUT"
check "$(grep -cxF "      $EXP_ACCEPT102" <<<"$OUT")|$(qor102 "$OUT" 1 qlc)|$(qor102 "$OUT" 1 qlr)|$(grep -c "not priced (zero): RH102SECRET-Router-Mesh-[0-9a-f]*'s price in uCRM is 0\$" "$SH102A" 2>/dev/null)|$(grep -lF -f "$NAMES102" "$R102/run-$(last102).txt" 2>/dev/null | grep -c .)" \
      "1|$QLC_ONE|$QLR_ONE|2|0" \
  "…the deploy with --accept-unpriced printed alone, its own command; the counts the release's resolver gave (pre-d); the row by name, with its reason, on the sheet alone (through both clients)"
check "$(grep -cE '[0-9]+ row\(s\) the KYC form offers' <<<"$OUT")|$(grep -c 'row(s) the KYC form offers do not resolve' <<<"$OUT")" "0|1" \
  "…the unpriced row said client by client, never as the two clients' verdicts summed into a number of rows (\"2 row(s) the KYC form offers\")"
check "$(has "$OUT" "Fix them in uCRM's Products and Service Plans, or in the row's name in the plugin, as docs/71 §M step 3 says, and run this again")$(has "$OUT" 'Fix them in uCRM Products (docs/71 §M step 3)')" "yesno" \
  "…and where it is fixed: uCRM's Products and Service Plans, or the row's name in the plugin (review 2, L3) — never round 1's \"uCRM Products\" alone"
u99_set 'price_of={"9104":0}' 'attrs_none=true'; fresh102; OUT="$(run102 --check --accept-unpriced)"
check "$(checkgo102 "$OUT" | cut -d'|' -f1)|$(has "$OUT" "  note  QL-G1 --accept-unpriced, the operator's explicit choice: row(s) the KYC form offers do not resolve in uCRM — $SAYU_ONE (each by name, with the resolver's reason, on the sheet). After the deploy a Cash sale of one is refused, and a Credit one registered with no quotation (P1), the agent told — fix them in uCRM's Products and Service Plans, or in the row's name in the plugin, as docs/71 §M step 3 says")|$(has "$OUT" '  --check           stage A alone, every refusal and note of a deploy with --accept-unpriced:')|$(grep -cxF "      $(runbook_with102 --accept-unpriced)" <<<"$OUT")|$(noteline "$OUT" QL-N1 'no client attribute is keyed salesPerson in uCRM')" \
      "yesyesnono0|yes|yes|1|yes" \
  "1b the same with --check --accept-unpriced: GO, QL-G1 a note naming the flag as the operator's choice; the deploy it hands over carries the flag; no salesPerson attribute a note (QL-N1)"
u99_set 'products_500=true'; fresh102; OUT="$(run102 --answer NO --accept-unpriced)"
nogo102 "1c uCRM's Products unreadable (500): NO-GO at QL-G1 that --accept-unpriced does not lift" "NO-GO (QL-G1): uCRM's Products and Service Plans could not be read through the plugin's own client, GET-only (http500) — the release reads them so for Send Quotation" "$OUT"
check "$(has "$OUT" 'No flag lifts it.')|$(qor102 "$OUT" 1 qlread)|$(ureq102 "$(last102)" | sed -E 's/^total=[0-9]+ //')" "yes|plug:http500;tok:http500;dbg:same|other=0 products=1/1 plans=0/0 attrs=2 quote=0 odd=0" \
  "…said so; the service plans not asked once the products failed (one wait, not two), through either client; the attributes read as ever (the probe's reading and the release's own lookup)"
u99_set 'plans_500=true'; fresh102; OUT="$(run102 --answer NO)"
nogo102 "1d uCRM's Service Plans unreadable (500): NO-GO at QL-G1" "NO-GO (QL-G1): uCRM's Products and Service Plans could not be read through the plugin's own client, GET-only (http500)" "$OUT"
u99_set 'token_products_403=true'; fresh102; OUT="$(run102 --answer NO --accept-unpriced)"
nogo102 "1e the token client refused the Products (403), the plugin's key not: NO-GO at QL-G1 that the flag does not lift" "NO-GO (QL-G1): uCRM's Products and Service Plans could not be read through the token client (crm_auth_token, built as KycService::postQuote builds it), GET-only (http403)" "$OUT"
check "$(qor102 "$OUT" 1 qlread)|$(ureq102 "$(last102)" | sed -E 's/^total=[0-9]+ //')" "plug:read;tok:http403;dbg:same|other=0 products=1/1 plans=1/0 attrs=2 quote=0 odd=0" \
  "…the plugin's own client read both lists, the token client only the refused one"
u99_set 'price_of={"9104":0}'; M="$(weak102 qlg1u qlg1u)"; fresh102; OUT="$(R102_MUTANT_OUT=1 run102 --script "$M" --check)"; rm -f "$M"
check "$(has "$OUT" '5.18.102 (check): GO')" "yes" "1f QL-G1's refusal of an unpriced row weakened: a --check GO over it (caught)"
u99_set 'products_500=true'; M="$(weak102 qlg1r qlg1rp qlg1rt)"; fresh102; OUT="$(R102_MUTANT_OUT=1 run102 --script "$M" --check)"; rm -f "$M"
check "$(has "$OUT" '5.18.102 (check): GO')$(okline "$OUT" QL-G1 'every row the KYC form offers resolves' '5 row(s) — 0 resolve, 0 unpriced, 1 Fiber (apart)')" "yesyes" \
  "1g QL-G1's two unreadable refusals weakened: GO, and QL-G1 \"every row resolves\" over a catalogue nothing read — the refusal is load-bearing (caught)"
u99_set 'attrs_two=true'; row_keep100; row_set kyc_quote_notes_prefix "$NSECRET102"
RQ102="$(rows_add102 '{"kyc_applications.json":[{"_rh102":true,"_rh102q314":true,"quote_ref":"000314","quote_id":88017409}]}')"
fresh102; OUT="$(run102 --check --accept-unpriced)"; TQ="$(last102)"; SH102Q="$(ls -1 "$OUT102"/cash-*.txt 2>/dev/null | head -1)"
check "$RQ102|$(checkgo102 "$OUT" | cut -d'|' -f1)|$(qor102 "$OUT" 1 ql314)|$(noteline "$OUT" QL-N2 'read back once through the plugin' "3 item(s) — product, a productId, a unit; custom, no productId, a unit; custom, no productId, no unit")|$(noteline "$OUT" QL-N1 '2 client attributes are keyed salesPerson' 'ambiguous')|$(noteline "$OUT" QL-N6 'kyc_quote_notes_prefix unset/set')" \
      '{"rows":1}|yesyesnono0|read:3items:product.pid1.unit1,custom.pid0.unit1,custom.pid0.unit0|yes|yes|yes' \
  "1h quotation 000314's application in the book: read back once, each item's type, productId and unit — never a label or a price; two salesPerson attributes ambiguous; the notes prefix set in the store row, said as set, never shown"
check "$(has "$OUT" '(typed and not needed: --accept-unpriced — each check held without it; the deploy below is printed without it)')|$(grep -cxF "      $EXP_RUNBOOK102" <<<"$OUT")|$(ureq102 "$TQ" | sed -E 's/^total=[0-9]+ other=0 //')|$(grep -lF -f "$NOWHERE102" "$R102/run-$TQ.txt" "$SH102Q" "$OUT102"/*.log 2>/dev/null | grep -c .)|$(grep -c 'QL-N2 quotation 000314 (uCRM quote #88017409), read back: 3 item(s); their units, in order: month, piece, (none)' "$SH102Q" 2>/dev/null)" \
      "yes|1|products=1/1 plans=1/1 attrs=2 quote=1 odd=0|0|1" \
  "…--accept-unpriced typed over a catalogue that resolves changes nothing — said, and the deploy handed over without it; the quote asked once by its id; its lines' labels and the notes prefix nowhere, not even on the sheet, which carries the units alone"
row_back100; QD102="$(sqn102 "DELETE FROM kyc_applications WHERE json_extract(data, '\$._rh102q314') = 1")"; u99_set
check "$QD102|$(data94)|$(set99 kyc_quote_notes_prefix)" "1|$D102|unset/unset" "…000314's application and the notes prefix taken out again: the data as before, byte for byte"
# ── 1i (review 2, L3): a plan the form offers whose Product has no Service Plan in uCRM — the release's "… is not a service
# plan in uCRM", which the probe counts under "differs": the log names that category by all it holds, never as a price
# that differs alone. The stand-in's service plans without the plan's mirror (502), the base read for its other rows. ────
PLANS102="$(python3 -c 'import json, sys; s = json.load(open(sys.argv[1])); print(json.dumps([p for p in s["plans"] if p.get("id") != 502]))' "$U99/base.json")"
SAYU_PLAN="1 row(s) through the plugin's own client (no matching service plan, no 1-month price, or a price that differs from its service plan 1) and 1 through the token client (no matching service plan, no 1-month price, or a price that differs from its service plan 1), the same rows judged by each"
u99_set "plans=$PLANS102"; fresh102; OUT="$(run102 --answer NO)"; SH102I="$(ls -1 "$OUT102"/cash-*.txt 2>/dev/null | head -1)"
nogo102 "1i a plan the form offers with no Service Plan in uCRM: NO-GO at QL-G1, its reason's category said as what it holds" "NO-GO (QL-G1): row(s) the KYC form offers do not resolve in uCRM through the RELEASE's resolver — $SAYU_PLAN; each by name, with the resolver's reason, on the cash sheet (less " "$OUT"
check "$(qor102 "$OUT" 1 qlc)|$(qor102 "$OUT" 1 qlr)|$(grep -c "not priced (differs): RH102SECRET-Plan-Beta-[0-9a-f]* is not a service plan in uCRM\$" "$SH102I" 2>/dev/null)|$(grep -c 'price differs from its service plan' <<<"$OUT")|$(grep -lF -f "$NAMES102" "$R102/run-$(last102).txt" 2>/dev/null | grep -c .)" \
      "$QLC_ONE|plug:notprod:0,ambig:0,differs:1,taxable:0,decimals:0,label:0,zero:0;tok:notprod:0,ambig:0,differs:1,taxable:0,decimals:0,label:0,zero:0;dbg:same|2|0|0" \
  "…counted under differs through both clients; the row by name with the release's own words on the sheet alone (\"… is not a service plan in uCRM\", through both clients); the log never says \"price differs from its service plan\" of it (review 2, L3)"
u99_set
# ── 1j–1l (review 1, QL-5): uCRM answering below 400 with what the release refuses — 500 Products (its LIMIT) and an HTML
# page — is said as an answer it cannot use, never "no answer", and the flag does not lift it. ──────────────────────────
QL5_SAY="uCRM answered, but not with a list the release can use: 500 rows or more in Products or Service Plans, or a body that is not a JSON list"
u99_set 'products_n500=true'; fresh102; OUT="$(run102 --answer NO --accept-unpriced)"; T1J="$(last102)"; SH102J="$(ls -1 "$OUT102"/cash-*.txt 2>/dev/null | head -1)"
nogo102 "1j uCRM answers 200 with 500 Products — the release's limit: NO-GO at QL-G1 that --accept-unpriced does not lift, the read said as an answer the release cannot use" "NO-GO (QL-G1): uCRM's Products and Service Plans could not be read through the plugin's own client, GET-only ($QL5_SAY) — the release reads them so for Send Quotation" "$OUT"
check "$(has "$OUT" 'No flag lifts it.')|$(qor102 "$OUT" 1 qlread)|$(ureq102 "$T1J" | sed -E 's/^total=[0-9]+ //')|$(grep -c 'uCRM answered, but not with a list the release can use (500 rows or more, or not a JSON list): no row is judged$' "$SH102J" 2>/dev/null)|$(has "$OUT" 'no answer recorded')$(has "$OUT" 'noanswer')|$(grep -lF -e 'RH102QSECRET-filler-' "$R102/run-$T1J.txt" "$SH102J" 2>/dev/null | grep -c .)" \
      "yes|plug:unusable;tok:unusable;dbg:same|other=0 products=1/1 plans=0/0 attrs=2 quote=0 odd=0|2|nono|0" \
  "…said so; each client's read recorded as unusable, never \"noanswer\"; the service plans not asked once the products were refused; the sheet says the same of both clients and judges no row — none of the 500 names anywhere"
u99_set 'products_html=true'; fresh102; OUT="$(run102 --answer NO)"; T1K="$(last102)"
nogo102 "1k uCRM answers 200 with an HTML page — a body that is not a JSON list: NO-GO at QL-G1, said the same way" "NO-GO (QL-G1): uCRM's Products and Service Plans could not be read through the plugin's own client, GET-only ($QL5_SAY)" "$OUT"
check "$(qor102 "$OUT" 1 qlread)|$(ureq102 "$T1K" | sed -E 's/^total=[0-9]+ //')|$(has "$OUT" 'no answer recorded')" "plug:unusable;tok:unusable;dbg:same|other=0 products=1/1 plans=0/0 attrs=2 quote=0 odd=0|no" \
  "…both clients' reads unusable, the service plans not asked"
M="$(weak102 ql5w qlunusable)"; fresh102; OUT="$(R102_MUTANT_OUT=1 run102 --script "$M" --check)"; rm -f "$M"
check "$(has "$OUT" "GET-only ($QL5_SAY)")$(has "$OUT" 'GET-only (no answer recorded)')$(has "$OUT" '5.18.102 (check): GO')" "noyesno" \
  "1l the probe's \"unusable\" weakened: the same HTML answer said as \"no answer recorded\" — 1j's and 1k's assertion does not hold on it (caught; still a NO-GO, nothing changed)"
u99_set
# ── 1m (review 2, L1; docs/71 D11, §F.4): eight applications saved before the deploy, as 5.18.101 saves them — none with a
# "Who installs?" answer but one — through the installed code's own writer. The release's rules leave five unpriced at
# their retry whatever uCRM holds: a pending Credit Starlink New Connection (P1), a Starlink in review spelled with spaces
# and another case, Cash with cash collected (P2), a pending Cash one with none collected (P1), a Fiber one in review —
# and not a Shifting Connection, one DishNet installs, one already quoted or one whose lines carry 5.18.102's mark. Each
# carries a plan price (offer_price), so that QL-RB2 counts none of them. Taken out again after. ─────────────────────────
PLANTD11='{"kyc_applications.json":[
 {"_rh102":true,"_rh102d11":true,"crm_sync_status":"pending","customer_type":"StarLink","sales_type":"Credit","offer_price":1,"submitted_at":"rel:-2 days"},
 {"_rh102":true,"_rh102d11":true,"crm_sync_status":"review","customer_type":" starlink ","connectivity_type":" New Connection ","sales_type":"Cash","amount_charged":287654.35,"offer_price":1,"submitted_at":"rel:-3 days"},
 {"_rh102":true,"_rh102d11":true,"crm_sync_status":"pending","customer_type":"StarLink","sales_type":"Cash","amount_charged":0,"offer_price":1,"submitted_at":"rel:-4 days"},
 {"_rh102":true,"_rh102d11":true,"crm_sync_status":"review","customer_type":"Fiber","offer_price":1,"submitted_at":"rel:-5 days"},
 {"_rh102":true,"_rh102d11":true,"crm_sync_status":"pending","customer_type":"StarLink","connectivity_type":"Shifting Connection","offer_price":1,"submitted_at":"rel:-6 days"},
 {"_rh102":true,"_rh102d11":true,"crm_sync_status":"pending","customer_type":"StarLink","install_by":"dishnet","offer_price":1,"submitted_at":"rel:-7 days"},
 {"_rh102":true,"_rh102d11":true,"crm_sync_status":"review","customer_type":"StarLink","quote_created":1,"offer_price":1,"submitted_at":"rel:-8 days"},
 {"_rh102":true,"_rh102d11":true,"crm_sync_status":"pending","customer_type":"StarLink","quote_lines_source":"ucrm-5.18.102","quote_items":[{"productId":9101,"price":1,"quantity":1}],"offer_price":1,"submitted_at":"rel:-9 days"}]}'
d11add102() { awk -F'[,:]' -v a="$2" -v b="$3" -v c="$4" -v d="$5" -v e="$6" '{ printf "pending:%d,review:%d,p1:%d,p2:%d,fiber:%d", $2 + a, $4 + b, $6 + c, $8 + d, $10 + e }' <<<"$1"; }   # d11add102 D11 +PENDING +REVIEW +P1 +P2 +FIBER
RD11="$(rows_add102 "$PLANTD11")"; D11P="$(python3 "$H102/rh102q.py" kv "$(apps102)" d11)"
check "$RD11|$D11P|$(python3 "$H102/rh102q.py" kv "$(apps102)" unp)" "{\"rows\":8}|$(d11add102 "$D11_102" 2 1 2 1 1)|pending:0,manual:0,p1:0" \
  "1m the plant: eight applications through the installed code's own writer — this rehearsal's own reading of the release's rules counts exactly five more the retry cannot price (pending 2, review 1; P1 2, P2 1; Fiber 1), and QL-RB2's kind none"
fresh102; OUT="$(run102 --check)"
check "$(checkgo102 "$OUT" | cut -d'|' -f1)|$(qol102 "$OUT" qln3d11)|$(noteline "$OUT" QL-N3 "After the deploy the retry takes them from uCRM's prices alone (docs/71 §F.4)" "$(d11say102 "$D11P")")" "yesyesnono0|$D11P|yes" \
  "1m' --check over them: GO, the probe's count of them this rehearsal's own, and QL-N3 says it — whatever uCRM holds, no quotation for each; made by hand in uCRM or not at all (D11)"
check "$(sqn102 "DELETE FROM kyc_applications WHERE json_extract(data, '\$._rh102d11') = 1")|$(data94)|$(python3 "$H102/rh102q.py" kv "$(apps102)" d11)" "8|$D102|$D11_102" \
  "…the eight taken out again: the data and the count as before, byte for byte"

echo; echo "== 102-4. the deploy, as the operator runs it — DEPLOY typed =="
LEDGER102="$(ledger95)"
fresh102; OUT="$(run102 --answer DEPLOY)"; OUT102D="$OUT"; T102D="$(last102)"
echo "  …    the deploy's own tally:$(grep -E '^  checks +[0-9]+ ok, ' <<<"$OUT" | sed -E 's/^  checks +/ /')"
check "$(fails "$OUT")$(has "$OUT" '5.18.102 (deploy): PASSED')$(live)$(installed102 "$P102")$(inst_ver)|$(left102)" "0yes${P102}05.18.102|21" "4 the deploy PASSES: 0 failed, $P102 serving, every file as the pin has it, the 21 it adds among them"
for l in "ok    A0 the release delta is exactly 5.18.102's 59 files — 21 added, 38 changed, each the reviewed one by the sha256 this script pins (59 of 59)" \
         "ok    BR1 the installed plugin is live 5.18.101 ($P101): all " \
         "ok    Q-G1 installed is 5.18.101 ($P101): its manifest says 5.18.101; all 38 files 5.18.102 changes carry the sha256 pinned for 5.18.101, and all 55 files it must not touch" \
         "ok    A14 the stray store is quarantined and the quarantine holds" "ok    A22-T1 under this server's own PHP" "ok    G3 both ECO BANK cards show a figure" \
         "ok    Q-G2 the tenant is Uganda" "ok    Q-G3 quoting organization #1 (from the Uganda profile)" "ok    Q-G4 uCRM's organizations, read once through the plugin's own client" \
         "ok    TN-G1 as text" "ok    TN-G2 on one copy of the book" "ok    TN-G3 wa_inbox_roles is unset" "ok    QS-G1 as text" "${QLG1_OK#  }" "GO — evidence recorded" \
         "088 (5.18.97's) stands beside it, unchanged by 5.18.102 (BR2, BA1)" \
         "ok    container serves $P102" "ok    R1 the installed manifest says 5.18.102" \
         "ok    Q-CA1 installed = 5.18.102's release: all 59 of its files carry the sha256 this script pins; all 55 files of 5.18.101 it must not touch" \
         "ok    Q-CA2 the INSTALLED QuoteOrganization::setting() on a copy reads what stage A read through the pin's: on:1:profile:-" \
         "ok    Q-CA3 nothing was created in uCRM: every request this run made of it, through its GET-only wrappers, was a GET" \
         "ok    Q-CA4 Sync All is admin-only" "ok    Q-CA5 the KYC form, Send Quotation, Quote History, Orders and the LTE dashboard without a session each send the visitor to the sign-in page (302)" \
         "ok    TN-CA1 as text, installed" "ok    TN-CA2 the same counts as stage A's reading through the pin" "ok    TN-CA3 wa_inbox_roles as stage A read it (inbox=unset roles=- odd=0 owners=-)" \
         "ok    QS-CA1 as text, installed: webhook.php ends the summary with the owner's wording on Uganda" \
         "ok    QL-CA1 the INSTALLED UcrmQuoteLines, on a fresh copy and GET-only, gives every row the verdict stage A's pin gave it — the same product, price and unit where it resolves, the same reason where it does not (compared under this run's key, never printed): through the plugin's own client $SAY_OK; through the token client $SAY_OK" \
         "ok    QL-CA2 the client attribute keyed salesPerson reads as stage A read it: exactly one, #47 — the installed release's own lookup answers the same" \
         "ok    Z1 the live vault" "ok    Z2 nothing of the vault, no webhook_secret file, no token copy, no settings file, no copy of the book and no catalogue file (offer, quotation pointer, keyed hash, page cache) is left under this run's folders in the container's /tmp" \
         "  names             QL-G1, QL-N1–QL-N7, QL-CA1, QL-CA2, QL-RB1 and QL-RB2 are 5.18.102's own checks (QL, \"quote lines\");" \
         "  what changed      on this Uganda server (Q-G2), the quoting organization #1 in force (Q-G3, Q-CA2): the KYC quotations take their lines and prices from uCRM's Products and Service Plans" \
         "  the screens       on Uganda, signed in: the KYC form draws each plan and kit at uCRM's price" \
         "  your steps        yours alone — this script does none of them (docs/71 §M):" \
         "an unpriced Credit sale is registered with no quotation (P1) and, on the KYC form, the agent told why — a Credit Starlink New Connection from the retailer app or the mobile API, which send no \"Who installs?\", always is, and the app does not say so: only the Orders card shows it (docs/71 §E.3)" \
         "A Uganda Cash customer is not marked delivery_pdf_sent either: with kyc_messages_like_crm off they would get the plugin's \"Request Confirmed!\" welcome instead of the PDF's caption (docs/07 records it on in Uganda since 25 Sep; this run does not read it)." \
         "An existing customer's kit-only additional service still tells the agent that the new install is being scheduled (docs/71 §P.2)." \
         "A Product marked Taxable in uCRM — the VAT plan's step 3 — stops automatic quotes until a VAT release (docs/71 §M)." \
         "byte for byte (Q-G1, Q-CA1) — though, with the quoting organization in force, what webhook.php and post_kyc.php do on Uganda changes through D9, the Delivery PDF not sent (above); every number in the registry" \
         "                    And, as §M's \"what changes\" says: no catalogue Product marked Taxable in uCRM (the VAT plan's" \
         "                    step 3) until a VAT release — it stops automatic quotes (docs/71 §M)." \
         "                    After the deploy: each application saved before it that the Orders card shows refused P1 or" \
         "                    which the plugin WhatsApps to the customer as every new quote, or make none." \
         "ok    C5 the copy's row digest (every row of cb_ledger, its id and every column), its cb_accounts digest and its sqlite_master digest" \
         "the database — no migration added and nothing written by this run (R7, BA1); the Cashbook's tables (cb_ledger, cb_accounts) and the schema unchanged by every read of 5.18.102's code on the copies (C5, CA2); what a live plugin writes at use is the plugin's own (5.18.102's keys on Uganda applications among it: R7, QL-RB2);" \
         "                    wait; a Cash sale from the retailer app or the mobile API is refused, and a Credit Starlink" \
         "                    New Connection from them gets no quotation, which the app does not say — the Orders card" \
         "                    shows it (docs/71 §E.3)"; do
  check "$(has "$OUT" "$l")" "yes" "4: ${l:0:110}"
done
check "$(has "$OUT" 'files 5.18.102 adds are already on disk')$(has "$OUT" '  FAIL  QL-')$(qor102 "$OUT" 1 qlc)|$(qor102 "$OUT" 2 qlc)|$(qor102 "$OUT" 2 qlr)|$(grep -E '^  uCRM, GET-only  ' <<<"$OUT" | grep -cE '(^| )qlh=|[0-9a-f]{32}')" \
      "nono$QLC_OK|$QLC_OK|$QLR_OK|0" \
  "4a no 5.18.102 file on disk before this first deploy (BR1 says none); stage A's catalogue and the installed resolver's after the copy read the same counts; no keyed hash in what the probe echoes"
check "$(sed -n 's/^QLG1_A=//p' "$STATE102" | tail -1)|$(sed -n 's/^QLATTR_A=//p' "$STATE102" | tail -1)|$(grep -c '^QLG1_NOTED=$' "$STATE102")|$(grep -E '^QL' "$STATE102" | grep -cE '[0-9a-f]{32}|RH102')|$(grep -c 'qlh' "$STATE102")" \
      "$QLC_OK/$QLR_OK|one:47|1|0|0" \
  "4b the state file records stage A's catalogue counts by client and by reason, the attribute's answer and that no flag decided QL-G1 — counts and an id, no name, no keyed hash"
check "$(grep -c "^COPY_AT=$(stat -c %Y "$PD/lib/KycService.php")\$" "$STATE102")|$(epoch99 "$(sed -n 's/^COPY_AT=//p' "$STATE102" | tail -1)")" "1|epoch" \
  "4c the copy's time, as stamp_copy gave lib/KycService.php — a file this release changes"
SUM102="$(sed -n '/^== F\. Summary ==$/,$p' <<<"$OUT")"
check "$(grep -cxF "      $EXP_AFTER102" <<<"$SUM102")$(grep -cxF "      $EXP_QCHECK" <<<"$SUM102")$(grep -cxF "      $EXP_ROLLBACK102" <<<"$SUM102")$(grep -cxF "      $EXP_PUTBACK102" <<<"$SUM102")$(grep -cE '(deploy-5\.18\.102\.sh|quarantine-stray-store\.sh|deploy-hybrid\.sh).*(deploy-5\.18\.102\.sh|quarantine-stray-store\.sh|deploy-hybrid\.sh)' <<<"$OUT")|$(awk '/^  5\.18\.102 \((deploy|after)\): / { v = 1 } index($0, "deploy-5.18.102.sh --rollback") { print (v ? "after" : "before") }' <<<"$OUT" | paste -sd, -)|$(grep -cxF '  Its one explicit choice, needed only over those applications or when that count cannot be made:' <<<"$SUM102")$(grep -cxF '  --rollback-unpriced-pending, typed after --rollback, rolls back all the same — each such application then' <<<"$SUM102")$(grep -cxF '  counts nothing: an application still waiting for uCRM with no plan price recorded is then quoted by' <<<"$SUM102")$(grep -cxF "  5.18.101's cron at price 0 and WhatsApped as above:" <<<"$SUM102")|$(grep -cE 'deploy-5\.18\.102\.sh.*--rollback-unpriced-pending|--rollback-unpriced-pending.*deploy-5\.18\.102\.sh' <<<"$OUT")|$(grep -c 'deploy-5\.18\.102\.sh --accept-unpriced' <<<"$OUT")" \
      "11110|after|1111|0|0" \
  "4d the --after-only, the quarantine's --check, the rollback and the by-hand put-back, each printed exactly, alone on its line; the rollback once, after the verdict; its explicit choice — needed only over those applications or when the count cannot be made — named on lines of its own and never inside a command; the put-back's hazard said before it (it counts nothing); no command with --accept-unpriced (no flag was needed)"
check "$(grep -lF -f "$NAMES102" "$R102/run-$T102D.txt" "$STATE102" "$OUT102"/*.log 2>/dev/null | grep -c .)|$(names100 "$R102/run-$T102D.txt" "$STATE102" "$OUT102"/*.log)|$(secrets99 "$R102/run-$T102D.txt" "$STATE102")|$(audit102 "$T102D" | sed -E 's/^copies=([0-9]+) /\1 /' | awk '{print ($1 >= 20 ? "many" : $1 " only") " " $2 " " $3}')|$(uother102 "$T102D")" "0|0|0|many novault=0 plugin=0|-" \
  "4e no seeded name, no staff record's name and no stand-in secret printed or recorded; every PHP process on a copy with a vault of its own; GETs only"
check "$(ureq102 "$T102D" | sed -E 's/^total=[0-9]+ //')|$(ureq102 "$T102D" | sed -E 's/^total=([0-9]+) .*/\1/')|$(lim102 "$T102D")" "other=0 products=2/2 plans=2/2 attrs=4 quote=0 odd=0|$(gets102 "$OUT")|n=8 bad=0" \
  "4f the stand-in was asked the two lists twice through each client — stage A and after the copy — the attributes four times, every request a GET with limit=500, as many as F counts"
check "$(ledger95)|$(both_copies "$BK97")|$(kept_state)$(oldname)$(host_code102)$(left_tmp102)$(evo_calls)$(cache102)" "$LEDGER102|11|${KEPT102}00000" \
  "4g the migration ledger unchanged; ECO Bank's switch on in both places; the quarantine as it was; no code copy or run folder left; Evolution never called; no page cache"
# Review 2's wording, as this deploy says it (the quoting organization in force, no flag, nothing unpriced): D11 in "what
# changed" and its step, with QL-N3's count of this run's stage A as this rehearsal's own reading gives it (L1); step 3
# ending plainly and the Taxable item no longer numbered (L6); the database's line (DI-1) and C5's (L4); the sheet's two
# catalogue sections each under its own check's name (E2-7); the printed rollback's own block saying what the customer
# sees again on Uganda, the rollback command alone after it (L5). ─────────────────────────────────────────────────────
RBW102="There the customer sees 5.18.101's quotation again: the internal \"Auto-generated on KYC registration … Sales: … | Connection: … | Priority: …\" text back in the quotation's customer notes (docs/71 §A.1); \"Prepared by\" no longer set on a new KYC customer (one already set keeps its attribute: uCRM is not changed); Send Quotation and the lead quotes again take the plugin's prices and accept a line priced 0 (§J); the fallback PDF back to its old header and terms (§K.1). The created quote is no longer read back, so the Orders card records no mismatch (§F.6, staff only). The Delivery Acknowledgment PDF comes back with South Sudan's terms and a South Sudan phone number (§K.2), and a Uganda Credit customer who paid while 5.18.102 was live was not sent it, nor marked as sent, so gets it at their next payment (webhook.php, kept)."
d11t102() { awk -F'[,:]' '{ print $2 + $4 + $10 }' <<<"$1"; }   # d11t102 D11 — pending + review + Fiber
check "$(has "$SUM102" " An application saved before the deploy carries no \"Who installs?\" answer, so at its retry (the cron's, or an admin's Retry) a Starlink sale other than a Shifting Connection or an Ownership Change is unpriced whatever uCRM holds, and a Fiber one is never priced: no quotation is made, and the Orders card shows P1 (Credit, or no cash collected) or P2 (Cash with cash collected) — its quotation is made by hand in uCRM, or not at all (D11, docs/71 §F.4; $(d11n102 "$D11_102")).")|$(grep -cxF "                    P2 gets no quotation from its retry (D11, docs/71 §F.4; QL-N3 counted $(d11t102 "$D11_102") at stage A) — make it by hand in uCRM," <<<"$SUM102")|$(grep -cxF '                       Taxable off, its invoice label empty or its name;' <<<"$SUM102")|$(grep -cE '^ +5\. no catalogue Product marked Taxable' <<<"$OUT")" \
      "yes|1|1|0" \
  "4h F's D11 — an application saved before the deploy is unpriced at its retry whatever uCRM holds — with QL-N3's count of this run's stage A, in \"what changed\" and in its own step (review 2, L1); step 3 ends plainly (no flag, nothing unpriced) and the Taxable item is §M's \"what changes\", no step 5 (L6)"
check "$(has "$OUT" 'nothing written by the release to the book')$(okline "$OUT" C5 "are unchanged by every read of 5.18.102's code" "— 5.18.102's reads write nothing there")$(has "$OUT" '5.18.102 reads, and writes nothing')" "noyesno" \
  "4i the database said as it is — the Cashbook's tables and the schema unchanged by 5.18.102's reads, its own keys on Uganda applications the plugin's (review 2, DI-1, L4) — never \"nothing written by the release to the book\""
SH102D="$(ls -1 "$OUT102"/cash-*.txt 2>/dev/null | head -1)"
check "$(grep -c '^== QL-G1 the catalogue the KYC form offers — ' "$SH102D" 2>/dev/null)|$(grep -c '^== QL-CA1 the catalogue the KYC form offers — ' "$SH102D" 2>/dev/null)|$(awk '/^== QL-G1 the catalogue/ { a = NR } /^== QL-CA1 the catalogue/ { b = NR } END { print ((a && b && a < b) ? "in order" : "a" a "b" b) }' "$SH102D" 2>/dev/null)" "1|1|in order" \
  "4j the deploy's sheet: stage A's catalogue under QL-G1, the reading after the copy under QL-CA1, in that order — each section under its own check's name (review 2, E2-7)"
RBJ102="$(awk 'index($0, "  Only if it is ever needed — the rollback") == 1 { on = 1 } on && index($0, "      ") == 1 { exit } on { sub(/^  /, ""); printf "%s%s", (n++ ? " " : ""), $0 }' <<<"$SUM102")"
check "$(has "$RBJ102" "On Uganda, with the quoting organization in force, the KYC quotations and the Cash amount then come again from the plugin's own plans and kits, saving a plan or a kit and Sync All write uCRM's Products again, and the Delivery Acknowledgment PDF is sent again. $RBW102 Quotes already made in uCRM are not changed")|$(grep -c '+211' <<<"$SUM102")|$(awk -v c="      $EXP_ROLLBACK102" 'index($0, "  Only if it is ever needed — the rollback") == 1 { on = 1 } on && index($0, "      ") == 1 { print ($0 == c ? "alone" : "other"); exit }' <<<"$SUM102")" "yes|0|alone" \
  "4k the printed rollback's own block, its wrapped lines joined: on Uganda, with the quoting organization in force, what the customer sees again — 5.18.101's internal notes text, no \"Prepared by\", lines priced 0, the old PDFs, South Sudan's Delivery PDF terms and phone (review 2, L5) — fixed words, no digit of a phone; the rollback command alone on the line after it"

echo; echo "== 102-5. --after-only as printed; the catalogue changed in uCRM after the deploy; uCRM unreadable =="
copy_back102 30; COPY102="$(sed -n 's/^COPY_AT=//p' "$STATE102" | tail -1)"
[ "$(epoch99 "$COPY102")" = "epoch" ] && set_sched $(( COPY102 + 300 )); base_log
ALINE="$(printed "$OUT102D" "cd $REPO && bash scripts/deploy-5.18.102.sh --after-only")"
check "$ALINE|$(epoch99 "$COPY102")" "$EXP_AFTER102|epoch" "5 the --after-only command, as the deploy's summary printed it; the copy moved thirty minutes back"
fresh102; OUT="$(run_line102 '' "$ALINE")"; OUT102A="$OUT"; TA="$(last102)"
check "$(fails "$OUT")$(has "$OUT" '5.18.102 (after): PASSED')$(okline "$OUT" R19 'the quarantine holds: master ran the retry job at' "after the copy (at $(utc "$COPY102"))")$(okline "$OUT" QS-CA1 'as text, installed')$(okline "$OUT" TN-CA2 'the same counts as stage A')$(okline "$OUT" QL-CA1 "the catalogue through the INSTALLED UcrmQuoteLines, on a fresh copy, GET-only: through the plugin's own client $SAY_OK; through the token client $SAY_OK" 'nothing asked but the two lists')$(has "$OUT" "  QL-CA1          the same counts as stage A read through the pin's (the state file): unpriced $UNP_NONE")$(okline "$OUT" QL-CA2 'reads as stage A read it: exactly one, #47')" \
      "0yesyesyesyesyesyesyes" \
  "5a --after-only PASSES, R19 conclusive; QS-CA1; TN-CA2; QL-CA1 readable and classified, the same counts as the state file's stage A; QL-CA2 as stage A read it"
SH102A5="$(ls -1 "$OUT102"/cash-*.txt 2>/dev/null | head -1)"
check "$(grep -cxF '                       Taxable off, its invoice label empty or its name;' <<<"$OUT")|$(grep -cxF "                    P2 gets no quotation from its retry (D11, docs/71 §F.4; the deploy's QL-N3 said how many, where it could count them) — make it by hand in uCRM," <<<"$OUT")|$(has "$OUT" "the quoting organization #1 in force (Q-G3, Q-CA2): the KYC quotations")|$(grep -c '^== QL-CA1 the catalogue the KYC form offers — ' "$SH102A5" 2>/dev/null)|$(grep -c '^== QL-G1 the catalogue the KYC form offers — ' "$SH102A5" 2>/dev/null)" \
      "1|1|yes|1|0" \
  "5a' F: step 3 ends plainly — this run's reading after the copy finds nothing unpriced, and the deploy took no flag (review 2, L6); D11's step names the deploy's count; the quoting organization in force as the installed code read it; the sheet's one catalogue section is QL-CA1's (E2-7)"
u99_set 'price_of={"9104":0}'; fresh102; OUT="$(run_line102 '' "$ALINE")"
check "$(fails "$OUT")$(has "$OUT" '5.18.102 (after): PASSED')$(okline "$OUT" QL-CA1 "through the plugin's own client $SAY_ONE; through the token client $SAY_ONE")$(noteline "$OUT" QL-CA1 "more unpriced than stage A read, over both clients: now $UNP_ONE; at stage A $UNP_NONE")$(noteline "$OUT" QL-CA1 "row(s) the KYC form offers are unpriced now: $SAYU_ONE")$(has "$OUT" '  FAIL  QL-CA1')|$(grep -cE '[0-9]+ row\(s\) the KYC form offers|unpriced than stage A read: [0-9]+ now' <<<"$OUT")" \
      "0yesyesyesyesno|0" \
  "5b a Product priced 0 in uCRM after the deploy: --after-only PASSES — QL-CA1 a NOTE naming the direction (more unpriced now than stage A read), over both clients, each client's count said apart and with its reason, never a sum of the two as rows; never a FAIL: the operator may fix uCRM, as docs/71 §M step 3 asks"
check "$(noteline "$OUT" QL-CA1 'row(s) the KYC form offers are unpriced now:' "fix them in uCRM's Products and Service Plans, or in the row's name in the plugin, as docs/71 §M step 3 says; each row's own reason is on the sheet")|$(has "$OUT" " This run's reading after the copy finds catalogue rows uCRM does not price (QL-CA1): until each is fixed in uCRM (your steps, 3, below), a Cash sale of one is refused and a Credit one is registered with no quotation (P1).")|$(grep -cxF "                       Taxable off, its invoice label empty or its name — required now: this run's reading after the copy (QL-CA1) finds rows unpriced, each on the sheet;" <<<"$OUT")|$(has "$OUT" 'on --accept-unpriced')" \
      "yes|yes|1|no" \
  "5b' …and F says this run's reading: rows unpriced since the deploy make step 3 required now, with no word of a flag the deploy never took (review 2, L6); the note says where they are fixed (L3)"
u99_set 'products_500=true'; fresh102; OUT="$(run_line102 '' "$ALINE")"
check "$(failline "$OUT" QL-CA1 "the catalogue through the INSTALLED resolver: unreadable through the plugin's own client (http500); unreadable through the token client (http500) — each row by name on the sheet")$(has "$OUT" '5.18.102 (after): 1 FAILED')|$(grep -E '^  FAIL  ' <<<"$OUT" | grep -vc '^  FAIL  QL-CA1 ')" \
      "yesyes|0" "5c uCRM's Products unreadable at an --after-only: QL-CA1 FAILS, naming both clients — and nothing else fails"
check "$(grep -cxF "                       Taxable off, its invoice label empty or its name — this run's reading after the copy gives no count to judge (QL-CA1 above);" <<<"$OUT")|$(has "$OUT" 'required now')" "1|no" \
  "5c' …and F's step 3 says this run's reading gives no count to judge — never \"required now\" over a catalogue it could not read (review 2, L6)"
M="$(weak102 qlca1w qlca1)"; fresh102; OUT="$(R102_MUTANT_OUT=1 run102 --script "$M" --after-only)"; rm -f "$M"
check "$(failline "$OUT" QL-CA1 'unreadable through')$(okline "$OUT" QL-CA1 'the catalogue through the INSTALLED resolver:' 'unreadable through')" "noyes" "5d QL-CA1's failure weakened: it says ok over an unreadable catalogue (caught)"
u99_set
DX102="$(data94)"; fresh102; OUT="$(run102 --check)"
check "$(has "$OUT" "--check is stage A before a deploy, and the container already serves $P102 (5.18.102) — run --after-only instead")$(has "$OUT" 'Nothing was changed. Send the log file.')$(went102 "$OUT")$(has "$OUT" '5.18.102 (check): GO')|$(grep -cxF "      $EXP_AFTER102" <<<"$OUT")|$(live)|$(data94)" "yesyesnonono|1|$P102|$DX102" \
  "5e --check with 5.18.102 live: refused, the --after-only printed instead, alone; nothing changed"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "5f the quarantine's own --check after the --after-only runs, as the runbook orders: PASSED"
KEPT102="$(kept_state)"

echo; echo "== 102-6. the rollback: QL-RB2 — ok; an application saved while ROLLBACK is asked, refused as it is typed, before the copy; refused at stage A over one; the rollback over them, as the operator's choice; QL-RB1 =="
RLINE="$(printed "$OUT102A" "cd $REPO && bash scripts/deploy-5.18.102.sh --rollback")"
check "$RLINE|$(awk '/^  5\.18\.102 \((deploy|after)\): / { v = 1 } index($0, "deploy-5.18.102.sh --rollback") { print (v ? "after" : "before") }' <<<"$OUT102A" | paste -sd, -)" "$EXP_ROLLBACK102|after" "6 the rollback command, as the --after-only's summary printed it after its verdict"
# QL-RB2's words, as qlrb_judge says them at each of this run's two counts: stage A's, with no prefix, and the one made
# as ROLLBACK is typed, right before the copy, with RB2_AGAIN first — one text and one rule (review 1, S1). Applications
# "waiting for uCRM with no plan price recorded", never ones "5.18.102 could not price" (RH-1). ─────────────────────────
RB2_AGAIN="counted again as ROLLBACK was typed, right before the copy: "
RB2_OK="no application is waiting for uCRM with no plan price recorded (no uCRM client, no quote, no lines, no plan price — as 5.18.102 saves the sales it cannot price, or an older one; on a copy of the book, read-only): 5.18.101's cron has none to quote with its plan line at price 0"
RB2_NOGO="application(s) still waiting for uCRM with no plan price recorded — no uCRM client, no quote, no lines and no plan price (on a copy of the book, read-only): as 5.18.102 saves the sales it cannot price; an older application can be so too, and is counted, since 5.18.101 quotes it the same way"
RB2_FLAG="--rollback-unpriced-pending, the operator's explicit choice: 1 application(s) still waiting for uCRM with no plan price recorded — as 5.18.102 saves the sales it cannot price; an older one can be so too. Within minutes of the rollback 5.18.101's cron (KycCrmSync) retries each"
RB2_MANUAL="1 application(s) in review or failed with no plan price recorded (no uCRM client, no quote, no lines, no plan price — as 5.18.102 saves the sales it cannot price; an older one can be so too): after the rollback a person's retry would quote one"
RB2_P1="1 application(s) 5.18.102 refused a quotation (P1, P2 or P3) have a uCRM client: after the rollback the debug retry (retry_quote) could quote one"
rb2_counts() { printf '  unpriced apps   %son a copy of the book, read-only (QL-RB2): pending for uCRM %s, in review or failed %s, refused P1–P3 with a uCRM client %s — counts only' "$1" "$2" "$3" "$4"; }   # rb2_counts PREFIX PENDING MANUAL P1 — the counts line, as qlrb_judge echoes it
starts102() { awk -v p="$2" 'index($0, p) == 1 { n++ } END { print n + 0 }' <<<"$1"; }   # starts102 OUTPUT PREFIX — how many lines begin with PREFIX
flagcmd102() { grep -cE '^ {6}.*--rollback-unpriced-pending|deploy-5\.18\.102\.sh.*--rollback-unpriced-pending' <<<"$1"; }   # a printed command carrying the flag — never
# The three applications, as 5.18.102 saves them: one waiting for uCRM with no plan price recorded, still pending; the same
# in review; and one refused P1 with a uCRM client.
PLANT102='{"kyc_applications.json":[
 {"_rh102":true,"_rh102plant":true,"crm_sync_status":"pending","crm_client_id":0,"quote_items":null,"offer_price":null,"sales_type":"Credit","submitted_at":"rel:-1 hour"},
 {"_rh102":true,"_rh102plant":true,"crm_sync_status":"review","crm_client_id":0,"quote_items":null,"offer_price":null,"sales_type":"Credit","submitted_at":"rel:-2 hours"},
 {"_rh102":true,"_rh102plant":true,"quote_refused":"P1","crm_client_id":71930451,"crm_sync_status":"synced","submitted_at":"rel:-3 hours"}]}'
unplant102() { sqn102 "DELETE FROM kyc_applications WHERE json_extract(data, '\$._rh102plant') = 1"; }   # the plants taken out: rows deleted
S102SHA="$(sha256sum "$STATE102" | cut -c1-64)"; DR0="$(data94)"
fresh102; OUT="$(run_line102 NO "$RLINE")"
check "$(okline "$OUT" QL-RB2 "$RB2_OK")$(has "$OUT" "$(rb2_counts '' 0 0 0)")$(has "$OUT" 'Type ROLLBACK')$(has "$OUT" 'STOP: not confirmed')$(has "$OUT" 'Nothing was changed. Send the log file.')$(has "$OUT" "$RB2_AGAIN")|$(live)|$(installed102 "$P102")|$(data94)|$(sha256sum "$STATE102" | cut -c1-64)" \
      "yesyesyesyesyesno|$P102|0|$DR0|$S102SHA" \
  "6a the rollback with no application waiting for uCRM with no plan price recorded: QL-RB2 ok, the question reached; NO typed — no second count, nothing changed, 5.18.102 serving, the deploy's state file as it was"
RBA102="  note  after the rollback the code is 5.18.101's again. On Uganda, where the quoting organization is in force, the quotations and the Cash amount come again from the plugin's own plans and kits, as 5.18.101 makes them;"
check "$(has "$OUT" "$RBA102")$(grep '^  note  after the rollback the code is ' <<<"$OUT" | grep -cF "$RBW102")" "yes1" \
  "6a' stage A's note on what the rollback brings back says it of Uganda where the quoting organization is in force, as RB and F do (review 2, E2-5) — and what the customer then sees again (L5)"
M="$(weak102 rbnotew rbnote)"; fresh102; OUT="$(R102_MUTANT_OUT=1 run102 --script "$M" --answer NO --rollback)"; rm -f "$M"
check "$(has "$OUT" "$RBA102")$(has "$OUT" "  note  after the rollback the code is 5.18.101's again. On Uganda the quotations and the Cash amount come again")$(has "$OUT" 'STOP: not confirmed')|$(live)|$(data94)" "noyesyes|$P102|$DR0" \
  "6a'' the note's qualifier weakened back to round 1's words: 6a' does not hold on it (caught; NO typed, nothing changed)"
# ── 6b: an application saved AFTER stage A's count, while the question waits (review 1, S1): the backup and a prompt with
# no timeout make stage A's reading minutes old. The harness's typed-answer step (feed102) waits until the run's own log
# shows the question, plants the three through the installed code's own writer, and only then types ROLLBACK. Stage A
# counted none, so the count made as ROLLBACK is typed is the one that must refuse — before TOUCHED and the copy. ─────────
fresh102; OUT="$(R102_PLANT="$PLANT102" run_line102 ROLLBACK "$RLINE")"; T6B="$(last102)"
check "$(cat "$R102/plant-$T6B.txt" 2>/dev/null)|$(okline "$OUT" QL-RB2 "$RB2_OK")$(has "$OUT" "$(rb2_counts '' 0 0 0)")$(has "$OUT" 'Type ROLLBACK to put ')$(has "$OUT" "$(rb2_counts "$RB2_AGAIN" 1 1 1)")|$(has "$OUT" "STOP: NO-GO (QL-RB2): ${RB2_AGAIN}1 $RB2_NOGO")$(has "$OUT" 'run the same rollback command again with --rollback-unpriced-pending typed after --rollback (Options)')$(has "$OUT" 'Nothing was changed. Send the log file.')$(has "$OUT" 'for the documented deploy')$(has "$OUT" 'could not price')|$(flagcmd102 "$OUT")|$(live)|$(installed102 "$P102")|$(backups102)|$(sha256sum "$STATE102" | cut -c1-64)" \
      "{\"rows\":3}|yesyesyesyes|yesyesyesnono|0|$P102|0|1|$S102SHA" \
  "6b three applications saved while the question waits (stage A counted 0 pending), ROLLBACK typed: the count as it is typed reads 1, 1, 1 and refuses, in stage A's words with its prefix, the flag named in words and never in a command — no copy, nothing changed but the backup taken before the question, 5.18.102 serving, the deploy's state file as it was"
check "$(unplant102)|$(data94)|$(python3 "$H102/rh102q.py" kv "$(apps102)" unp)" "3|$DR0|pending:0,manual:0,p1:0" \
  "…the three taken out: the data as before 6b, byte for byte — the refused run changed nothing"
# …and the control on that refusal: a copy whose count as ROLLBACK is typed is replaced by a stop that marks where the
# copy would begin (so that the weakened run rolls nothing back). Stage A counts 0 again, the same three are planted at
# the question, ROLLBACK typed: the run reaches the copy's point with them pending, nothing refusing — caught.
M="$(weak102 qlrb2againw qlrb2again)"; ML="${RLINE/scripts\/deploy-5.18.102.sh/scripts/.qlrb2againw.sh}"
check "$(grep -c 'cd .* && bash scripts/\.qlrb2againw\.sh --rollback 2>&1 | ( umask 077; exec tee ' <<<"$ML")|$( [ "${ML/scripts\/.qlrb2againw.sh/scripts/deploy-5.18.102.sh}" = "$RLINE" ] && echo same)" "1|same" \
  "control: the weakened copy's line is the printed rollback with only the script's name changed"
fresh102; OUT="$(R102_PLANT="$PLANT102" run_line102 ROLLBACK "$ML")"; T6B2="$(last102)"; rm -f "$M"
check "$(cat "$R102/plant-$T6B2.txt" 2>/dev/null)|$(okline "$OUT" QL-RB2 "$RB2_OK")$(has "$OUT" 'STOP: REHEARSAL-102: the copy would begin here')$(has "$OUT" "$RB2_AGAIN")$(has "$OUT" 'NO-GO (QL-RB2)')|$(python3 "$H102/rh102q.py" kv "$(apps102)" unp)|$(live)" \
      "{\"rows\":3}|yesyesnono|pending:1,manual:1,p1:1|$P102" \
  "6b2 the count as ROLLBACK is typed replaced by a marker: with the three saved at the question and stage A's ok in hand, the run reaches the point where the copy begins — nothing refuses (caught; the marker stops it, nothing changed)"
check "$(unplant102)|$(data94)" "3|$DR0" "…the three taken out again: the data as before, byte for byte"
RP102="$(rows_add102 "$PLANT102")"
DP102="$(data94)"
check "$RP102|$(python3 "$H102/rh102q.py" kv "$(apps102)" unp)" '{"rows":3}|pending:1,manual:1,p1:1' \
  "6b3 the same three planted before the run, as 5.18.102 saves them: one waiting for uCRM with no plan price recorded, still pending; one in review; one refused P1 with a uCRM client — this rehearsal's own reading counts 1, 1, 1"
fresh102; OUT="$(run_line102 ROLLBACK "$RLINE")"
check "$(has "$OUT" "STOP: NO-GO (QL-RB2): 1 $RB2_NOGO")$(has "$OUT" "$(rb2_counts '' 1 1 1)")$(has "$OUT" 'run the same rollback command again with --rollback-unpriced-pending typed after --rollback (Options)')$(has "$OUT" 'Nothing was changed. Send the log file.')$(has "$OUT" 'Type ROLLBACK')$(has "$OUT" 'could not price')|$(flagcmd102 "$OUT")|$(live)|$(backups102)|$(data94)|$(installed102 "$P102")|$(sha256sum "$STATE102" | cut -c1-64)" \
      "yesyesyesyesnono|0|$P102|0|$DP102|0|$S102SHA" \
  "6c the rollback as printed, ROLLBACK typed, over them: NO-GO at stage A's QL-RB2 before the question, the counts said, its flag named in words and never in a command — nothing backed up or changed, 5.18.102 serving"
RLINE_U="${RLINE/ --rollback / --rollback --rollback-unpriced-pending }"
check "$(grep -o -- '--rollback-unpriced-pending' <<<"$RLINE_U" | grep -c .)|$( [ "$RLINE_U" != "$RLINE" ] && echo flagged)" "1|flagged" "…the printed rollback, with --rollback-unpriced-pending typed after --rollback, as the refusal says"
fresh102; OUT="$(run_line102 NO "$RLINE_U")"
check "$(has "$OUT" "$(rb2_counts '' 1 1 1)")$(starts102 "$OUT" "  note  QL-RB2 $RB2_FLAG")$(noteline "$OUT" QL-RB2 "$RB2_MANUAL")$(noteline "$OUT" QL-RB2 "$RB2_P1")$(has "$OUT" 'NO-GO (QL-RB2)')$(has "$OUT" 'Type ROLLBACK to put ')$(has "$OUT" 'STOP: not confirmed')$(has "$OUT" "$RB2_AGAIN")$(has "$OUT" 'Nothing was changed. Send the log file.')|$(flagcmd102 "$OUT")|$(live)|$(installed102 "$P102")|$(data94)|$(sha256sum "$STATE102" | cut -c1-64)" \
      "yes1yesyesnoyesyesnoyes|0|$P102|0|$DP102|$S102SHA" \
  "6c2 the same with --rollback-unpriced-pending, NO typed: stage A's refusal becomes its note, the operator's choice, with the review and P1 notes — the question reached; NO typed: no second count, nothing changed"
M="$(weak102 qlrb2w qlrb2)"; fresh102; OUT="$(R102_MUTANT_OUT=1 run102 --script "$M" --answer NO --rollback)"; rm -f "$M"
check "$(has "$OUT" 'Type ROLLBACK')$(has "$OUT" 'NO-GO (QL-RB2)')|$(live)" "yesno|$P102" "6d QL-RB2's refusal weakened: the rollback over a pending application with no plan price recorded reaches its question (caught; NO typed, nothing changed)"
check "$(unplant102)|$(data94)|$(python3 "$H102/rh102q.py" kv "$(apps102)" unp)" "3|$DR0|pending:0,manual:0,p1:0" \
  "…the three taken out: the data as before 6b3, byte for byte"
# ── 6e: the rollback with --rollback-unpriced-pending, the three saved at the question again: stage A counts none (ok, no
# note); the count as ROLLBACK is typed reads 1, 1, 1 and, the flag typed, notes them as the operator's choice — the copy
# goes ahead. RB sets its own reading beside that last count. ────────────────────────────────────────────────────────────
fresh102; OUT="$(R102_PLANT="$PLANT102" run_line102 ROLLBACK "$RLINE_U")"; T6E="$(last102)"; OUT102RB="$OUT"; RLOG="$(ls -1 "$OUT102"/rollback-*.log 2>/dev/null)"
check "$(cat "$R102/plant-$T6E.txt" 2>/dev/null)|$(fails "$OUT")$(has "$OUT" '5.18.102 (rollback): PASSED')$(live)$(installed102 "$P101")$(inst_ver)|$(left102)|$(reach102)" "{\"rows\":3}|0yes${P101}05.18.101|21|0" \
  "6e the three saved at the question, --rollback-unpriced-pending typed: PASSES — 5.18.101 serving, every file it changed 5.18.101's again; the 21 it added on disk, and no installed file but them names UcrmQuoteLines or carries 5.18.102's two refusals"
check "$(okline "$OUT" QL-RB2 "$RB2_OK")$(has "$OUT" "$(rb2_counts '' 0 0 0)")$(has "$OUT" "$(rb2_counts "$RB2_AGAIN" 1 1 1)")|$(starts102 "$OUT" '  note  QL-RB2 --rollback-unpriced-pending')|$(starts102 "$OUT" "  note  QL-RB2 ${RB2_AGAIN}${RB2_FLAG}")$(noteline "$OUT" QL-RB2 "${RB2_AGAIN}${RB2_MANUAL}")$(noteline "$OUT" QL-RB2 "${RB2_AGAIN}${RB2_P1}")|$(awk -v a="  unpriced apps   ${RB2_AGAIN}" -v b='  ok    container serves ' 'index($0, a) == 1 && !x { x = NR } index($0, b) == 1 && !y { y = NR } END { print ((x && y && x < y) ? "before" : "x" x "y" y) }' <<<"$OUT")|$(flagcmd102 "$OUT")|$(grep -c 'could not price' <<<"$OUT")" \
      "yesyesyes|0|1yesyes|before|0|0" \
  "6e: stage A counted none and said ok — no note; the count as ROLLBACK was typed read 1, 1, 1 and noted each in its own words, the pending ones as the operator's choice — before the copy; no command carries the flag; nothing says \"could not price\" (RH-1)"
for l in "ok    RB the installed manifest says 5.18.101" \
         "ok    RB all 38 files 5.18.102 changed are 5.18.101's again, byte for byte — by Git's sha256 and by the 38 this script pins (Q-G1's set); 21 of the 21 files it adds are on disk (deploy-hybrid.sh never deletes), reached by nothing (QL-RB1, below)" \
         "ok    RB 5.18.101's quoting code is in place" \
         "ok    QL-RB1 nothing in 5.18.101 reaches the files 5.18.102 adds: none of the 8 installed files that name its markers in 5.18.102 names one now" \
         "note  QL-RB1 21 of the 21 files 5.18.102 adds are on disk (deploy-hybrid.sh never deletes; counted above) — lib/UcrmQuoteLines.php, the resolver 5.18.102 ran on Uganda while it was live, where the quoting organization was in force, and its tests, tests/test_quote_from_ucrm.php and tests/quote_from_ucrm/, which never ran on the server; none is reached by anything under 5.18.101 (above); a later --redeploy expects them (BR1)" \
         "note  RB the code is 5.18.101 again. The rollback restores code only. 21 of the 21 files 5.18.102 adds are on disk (deploy-hybrid.sh never deletes; counted above, QL-RB1) — lib/UcrmQuoteLines.php" \
         "a Cash sale from the retailer app or the mobile API is taken again. $RBW102 The quotation summary's sign-off stays 5.18.101's: 5.18.102 never changed webhook.php (RB above)." \
         "  what it means     on Uganda, where the quoting organization is in force, the KYC quotations and the Cash amount come again from the plugin's own plans and kits, as 5.18.101 has them; saving a plan or a kit, and Sync All, write uCRM's Products again; the Delivery Acknowledgment PDF is sent again (RB). $RBW102 Quotes already made in uCRM are not changed" \
         "(QL-RB2). 21 of the 21 files 5.18.102 adds are on disk, reached by nothing (QL-RB1). The quotation summary's sign-off" \
         "note  QL-RB2 with 5.18.101 in place, the applications waiting for uCRM with no plan price recorded — no uCRM client, no quote, no lines, no plan price: as 5.18.102 saves the sales it cannot price; an older one can be so too — counted, read-only: 1 still pending for uCRM, 1 awaiting a person's retry (review or failed), 1 refused P1, P2 or P3 with a uCRM client; before the copy, this run's last count (QL-RB2's, before or as ROLLBACK was typed) read 1 pending, 1 awaiting a person's retry and 1 refused P1–P3 with a uCRM client. The rollback rewrites none of them" \
         "5.18.101's cron retries a pending one within minutes and makes its quotation with the plan line at price 0, which uCRM's quote.add webhook WhatsApps to the customer — the rollback went ahead over them on --rollback-unpriced-pending, the operator's explicit choice" \
         "lib/UcrmQuoteLines.php, the resolver that ran on Uganda while 5.18.102 was live, where the quoting organization was in force, and its tests, which never ran on the server:" \
         "nor the resolver's page cache (ucrm_quote_catalogue_cache.json), which stays in the data folder where a draw of the KYC form or Send Quotation left one" \
         "ok    RB the sign-off is still 5.18.101's, as text" \
         "ok    RB the owner rule is 5.18.101's, as text: owners() defined and called, the technician roles once, an admin technician account refused (tech=1 decl=1 sales=1 kind=1 role=1 page=1 old=0)" \
         "ok    RB on a copy of the book, through the installed code: owners() offers the $(qj "$O102_0" sales) active salesperson account(s) and the $(qj "$O102_0" tech) active technician account(s)" \
         "note  RB 1 number(s) in the registry are owned by a technician account, as 5.18.101 allows" \
         "ok    RB cash in hand reads exactly as before the deploy on a copy of the book" "ok    RB 088 stays after the rollback, complete" \
         "  serving           $P101  (plugin 5.18.101)" \
         "  what it means     on Uganda, where the quoting organization is in force, the KYC quotations and the Cash amount come again from the plugin's own plans and kits" \
         "what 5.18.102 wrote on applications stays as written; 1 still waiting for uCRM with no plan price recorded, with 5.18.101 in place: its cron quotes each with the plan line at price 0, and uCRM WhatsApps that quotation to the customer (the rollback went ahead over them on --rollback-unpriced-pending, the operator's explicit choice) (QL-RB2)."; do
  check "$(has "$OUT" "$l")" "yes" "6e: ${l:0:110}"
done
check "$(has "$OUT" 'Fewer are pending than this run')$(has "$OUT" 'they were not pending at this run')$(has "$OUT" 'this run rolled nothing back')" "nonono" \
  "…RB's reading set beside this run's last count before the copy (1 pending then and now): no \"fewer\", no \"not pending at the last count\", and not the wording of a run that rolled nothing back"
check "$(printf '%s\n' "$RLOG" | grep -c .)|$(sha256sum "$STATE102" | cut -c1-64)|$(cat "$OUT102"/rollback-state-*.env 2>/dev/null | grep -c "^RB_COPY_AT=$(stat -c %Y "$PD/lib/KycService.php")\$")|$(sq "SELECT COUNT(*) FROM wa_channels WHERE channel_id = 'sales-901' AND owner_type = 'staff' AND owner_staff_id = ${TECH100:-0}")|$(grep -lF -f "$NAMES102" "$R102/line-$(last102).txt" $RLOG 2>/dev/null | grep -c .)|$(evo_calls)" \
      "1|$S102SHA|1|1|0|0" \
  "6f its own log; the deploy's state file untouched; the rollback's record holding its copy's time as stamp_copy gave lib/KycService.php; the technician's number still his; no seeded name printed; Evolution never called"
check "$(unplant102)|$(data94)|$(python3 "$H102/rh102q.py" kv "$(apps102)" unp)" "3|$D102|pending:0,manual:0,p1:0" \
  "…the three planted applications taken out again: the data as before the plants, byte for byte — the rollback changed no data"
fresh102; D6D="$(data94)"; OUT="$(run102 --answer NO --redeploy)"
check "$(has "$OUT" "NO-GO (A14): a rollback of 5.18.102 by this script copied the plugin at")$(went102 "$OUT")$(live)|$(backups102)|$(data94)" "yesnono$P101|0|$D6D" \
  "6g --redeploy right after the rollback, before the quarantine's --check: NO-GO at A14, never the question; nothing backed up or changed"
# QL-RB1's control on the control: with 5.18.101 serving, a --rollback runs the rollback's checks alone; the stand-in's
# .left102m (an asserted edit of its public.php, spent once at the first request once 5.18.101's manifest is installed —
# section V, after stage A compared the files) copies 5.18.102's includes/api/api_cron_debug.php over the installed one:
# a 5.18.101 file that names UcrmQuoteLines. QL-RB1 must fail naming it, and so must RB's sha256; then its weakened copy
# says ok. The file is put back byte for byte afterwards. ──────────────────────────────────────────────────────────────
python3 - "$SB/web/public.php" <<'PY' || echo "standin:triggers102" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]; s = open(p).read()
old = "$page = $_GET['page'] ?? '';\n"
assert s.count(old) == 1, s.count(old)
assert s.count('left102m') == 0
new = """if (($_GET['page'] ?? '') === 'rh102_probe') { echo 'rh102-triggers-ready'; exit; }
$left102m = is_file($web . '/.left102m') ? trim((string)file_get_contents($web . '/.left102m')) : '';
if ($left102m !== '' && strpos((string)@file_get_contents($root . '/manifest.json'), '"5.18.101"') !== false && $spend('.left102m')) {
    foreach (explode("\\n", $left102m) as $l102m) { $p102m = explode("\\t", $l102m, 2); if (count($p102m) === 2 && $p102m[0] !== '' && $p102m[1] !== '') copy($p102m[0], $root . '/' . $p102m[1]); }   // the next rehearsal: a 5.18.102 file reaching the added ones, left in place after a rollback (QL-RB1)
}
""" + old
s = s.replace(old, new, 1)
assert s.count("'.left102m'") == 1 and s.count('rh102_probe') == 1 and s.count(old) == 1
open(p, 'w').write(s)
PY
for i in $(seq 1 60); do grep -q 'rh102-triggers-ready' <<<"$(pubget page=rh102_probe)" && break; sleep 0.1; done   # PHP re-reads the edited file
check "$(pubget page=rh102_probe)$(ls -A "$SB/web" | grep -c '^\.left102m$')" "rh102-triggers-ready0" "control: the stand-in carries the trigger, unspent and unset"
cp -p "$PD/includes/api/api_cron_debug.php" "$SB/cron102.kept"
git -C "$REPO" show "$P102:$P/includes/api/api_cron_debug.php" > "$SB/cron102.left"; chmod 644 "$SB/cron102.left"
printf '%s\t%s\n' "$SB/cron102.left" "includes/api/api_cron_debug.php" > "$SB/web/.left102m"; chmod 644 "$SB/web/.left102m"
# …and one of the 21 added files gone from disk (review 2, E2-6): what RB, QL-RB1 and F say of the added files is the
# count on disk, never Git's. Put back byte for byte after 6i, before 7's --redeploy (BR1) needs it.
GONE102=tests/quote_from_ucrm/mutants_I.php; cp -p "$PD/$GONE102" "$SB/gone102.kept"; rm -f "$PD/$GONE102"
check "$(left102)|$(git -C "$REPO" show "$P102:$P/$GONE102" | cmp -s - "$SB/gone102.kept" && echo kept)" "20|kept" "control: one added file taken off disk (kept aside, byte for byte the pin's): 20 of the 21 there"
fresh102; OUT="$(run102 --rollback)"
check "$(has "$OUT" "the container already serves $P101 (5.18.101) — nothing to roll back; running the rollback checks")$(spent99 .left102m)|$(cmp -s "$PD/includes/api/api_cron_debug.php" "$SB/cron102.left" && echo left)|$(failline "$OUT" QL-RB1 "reaches what 5.18.102 added, or its readers could not see the release:" 'includes/api/api_cron_debug.php:names-it×1')$(failline "$OUT" RB "installed files that are not 5.18.101's after the rollback:" 'includes/api/api_cron_debug.php')|$( [ "$(fails "$OUT")" -ge 2 ] && echo failed)" \
      "yesspent|left|yesyes|failed" \
  "6h 5.18.101 serving, its rollback checks alone; a 5.18.102 file that names UcrmQuoteLines installed by the stand-in after stage A: QL-RB1 FAILS naming it, and RB's sha256 too"
check "$(noteline "$OUT" QL-RB2 'with 5.18.101 in place, the applications waiting for uCRM with no plan price recorded' "counted, read-only: 0 still pending for uCRM, 0 awaiting a person's retry (review or failed), 0 refused P1, P2 or P3 with a uCRM client; this run rolled nothing back — 5.18.101 ($P101) was already live when it began, put back by hand (which counts nothing) or by an earlier rollback — so it typed no ROLLBACK, made no copy and counted none before. The rollback rewrites none of them")$(has "$OUT" "before the copy, this run's last count")$(has "$OUT" "$(rb2_counts '' 0 0 0)")$(has "$OUT" 'Type ROLLBACK')$(has "$OUT" "wrote on applications stays as written; none still waiting for uCRM with no plan price recorded, with 5.18.101 in place (QL-RB2).")" \
      "yesnononoyes" \
  "…and RB's QL-RB2 says this run rolled nothing back — it typed no ROLLBACK, made no copy and counted none before — never a stage-A reading it did not make (review 1, QL-2/S3); no count before it, no question; F's \"what it means\" says none is waiting"
check "$(noteline "$OUT" QL-RB1 '20 of the 21 files 5.18.102 adds are on disk (deploy-hybrid.sh never deletes; counted above)')$(noteline "$OUT" RB 'The rollback restores code only. 20 of the 21 files 5.18.102 adds are on disk (deploy-hybrid.sh never deletes; counted above, QL-RB1)')$(has "$OUT" '20 of the 21 files 5.18.102 adds are on disk, and QL-RB1 FAILED: what is installed may reach them (QL-RB1)')|$(has "$OUT" '21 of the 21 files')$(has "$OUT" 'reached by nothing (QL-RB1). The quotation')" \
      "yesyesyes|nono" \
  "6h' with one added file gone, QL-RB1, RB and F each say the count on disk — 20 of the 21 — never Git's, and F, QL-RB1 having failed, never \"reached by nothing\" (review 2, E2-6)"
M="$(weak102 qlrb1w qlrb1)"; fresh102; OUT="$(R102_MUTANT_OUT=1 run102 --script "$M" --rollback)"; rm -f "$M"
check "$(okline "$OUT" QL-RB1 'nothing in 5.18.101 reaches the files 5.18.102 adds')$(failline "$OUT" RB "installed files that are not 5.18.101's after the rollback:" 'includes/api/api_cron_debug.php')" "yesyes" \
  "6i QL-RB1's count weakened: it says nothing reaches the added files while one 5.18.101 file names UcrmQuoteLines (caught; RB's sha256 still fails)"
cp -p "$SB/cron102.kept" "$PD/includes/api/api_cron_debug.php"; rm -f "$SB/cron102.kept" "$SB/cron102.left"
cp -p "$SB/gone102.kept" "$PD/$GONE102"; rm -f "$SB/gone102.kept"
check "$(installed102 "$P101")|$(reach102)|$(live)|$(left102)" "0|0|$P101|21" "…the installed file put back: every file 5.18.102 changed 5.18.101's again, nothing reaching the added files; the added file taken off put back, 21 of the 21 on disk"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "6j the quarantine's own --check after the rollback, as RB asks: PASSED"
KEPT102="$(kept_state)"

echo; echo "== 102-6v. a deploy whose public sign-in page redirects rolls itself back (V1), never waiting on QL-RB2 — then counts it =="
# The stand-in's .redirect102 (an asserted edit of its public.php): spent at the first request for the sign-in page once
# 5.18.102's manifest is installed — V1, after the copy (stage A asks for no public page, and 5.18.101's manifest is in
# place then); it answers that one request with a redirect to the sign-in page itself, so V1 reads 200 after one redirect.
# The deploy must roll itself back at once — the public address must never redirect — and only then count QL-RB2 on a copy
# of the book, saying the counts and the hazard in its notes and in its stop line, printing no command (review 1, C1/S2).
# The three applications are planted first, so that each note has a count to say. ──────────────────────────────────────
python3 - "$SB/web/public.php" <<'PY' || echo "standin:redirect102" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]; s = open(p).read()
old = "$page = $_GET['page'] ?? '';\n"
assert s.count(old) == 1, s.count(old)
assert s.count('redirect102') == 0 and s.count('rh102v1') == 0
new = """if (($_GET['page'] ?? '') === 'rh102v1_probe') { echo 'rh102v1-ready'; exit; }
if (($_GET['page'] ?? '') === 'customer_login' && !isset($_GET['rh102v1']) && strpos((string)@file_get_contents($root . '/manifest.json'), '"5.18.102"') !== false && $spend('.redirect102')) { header('Location: ?page=customer_login&rh102v1=1', true, 302); exit; }   // the next rehearsal: the public sign-in page redirecting once 5.18.102 is installed (V1)
""" + old
s = s.replace(old, new, 1)
assert s.count("'.redirect102'") == 1 and s.count('rh102v1_probe') == 1 and s.count(old) == 1
open(p, 'w').write(s)
PY
for i in $(seq 1 60); do grep -q 'rh102v1-ready' <<<"$(pubget page=rh102v1_probe)" && break; sleep 0.1; done   # PHP re-reads the edited file
v1read102() { curl -s --noproxy '*' -o /dev/null -w '%{http_code}:%{num_redirects}' -L "$PLUGIN_BASE?page=customer_login"; }   # what V1 reads: the code after following, and the redirects followed
check "$(pubget page=rh102v1_probe)|$(v1read102)|$(ls -A "$SB/web" | grep -c '^\.redirect102$')" "rh102v1-ready|200:0|0" "control: the stand-in carries the trigger, unset; the sign-in page answers 200 with no redirect"
printf 'x\n' > "$SB/web/.redirect102"; chmod 644 "$SB/web/.redirect102"
check "$(inst_ver)|$(v1read102)|$(ls -A "$SB/web" | grep -c '^\.redirect102$')" "5.18.101|200:0|1" \
  "control on the control: set, it does not fire while 5.18.101's manifest is installed — 200 with no redirect, the trigger unspent — so it can fire only after the copy, at V1"
RV102="$(rows_add102 "$PLANT102")"
check "$RV102|$(python3 "$H102/rh102q.py" kv "$(apps102)" unp)" '{"rows":3}|pending:1,manual:1,p1:1' \
  "6v the three planted again before the deploy: this rehearsal's own reading counts 1, 1, 1"
fresh102; OUT="$(run102 --answer DEPLOY --redeploy)"; T102V="$(last102)"
check "$(failline "$OUT" V1 'the sign-in page on the public address → 200 after 1 redirect(s)')$(has "$OUT" '  ROLLING BACK — the public address must never redirect')$(spent99 .redirect102)|$(live)|$(installed102 "$P101")|$(inst_ver)|$(left102)|$(reach102)" \
      "yesyesspent|$P101|0|5.18.101|21|0" \
  "6v1 V1 FAILS on the redirect (the trigger spent) and the deploy rolls itself back: 5.18.101 serving, every file 5.18.102 changes 5.18.101's again, the 21 it adds on disk and reached by nothing"
check "$(has "$OUT" "  unpriced apps   after this automatic rollback, on a copy of the book, read-only (QL-RB2): pending for uCRM 1, in review or failed 1, refused P1–P3 with a uCRM client 1 — counts only")$(noteline "$OUT" QL-RB2 "after this automatic rollback: 1 application(s) still waiting for uCRM with no plan price recorded — as 5.18.102 saves the sales it cannot price; an older one can be so too (on a copy of the book, read-only). Once 5.18.101 serves, its cron (KycCrmSync) retries each within minutes, rebuilds its plan line at price 0 and makes the quotation, and uCRM's quote.add webhook WhatsApps it to the customer. The rollback did not wait for them: the public address must never redirect")$(noteline "$OUT" QL-RB2 'after this automatic rollback: 1 application(s) in review or failed with no plan price recorded: a person' 'make their quotations in uCRM, and retry none of them by hand under 5.18.101')$(noteline "$OUT" QL-RB2 'after this automatic rollback: 1 application(s) 5.18.102 refused a quotation (P1, P2 or P3) have a uCRM client: the debug retry (retry_quote) could quote one')" \
      "yesyesyesyes" \
  "6v2 after the rollback, QL-RB2 counted on a copy: the counts line, and the hazard said for each kind — the pending one quoted at price 0 by 5.18.101's cron and WhatsApped, the review one by a person's retry, the P1 one by the debug retry"
check "$(has "$OUT" "  STOP: rolled back (serves $P101); QL-RB2 counted after it, on a copy of the book: pending for uCRM 1, in review or failed 1, refused P1–P3 with a uCRM client 1 — each pending one is quoted by 5.18.101's cron with its plan line at price 0, and uCRM WhatsApps it (the note above); send the log file")$(has "$OUT" 'Nothing further was done. Send the log file.')$(has "$OUT" 'did not complete')$(has "$OUT" 'may not be serving yet')|$(awk -v a='  ROLLING BACK — the public address must never redirect' -v b="  the container now serves $P101;" -v c='  unpriced apps   after this automatic rollback, ' -v d='  STOP: rolled back (serves ' 'index($0, a) == 1 && !w { w = NR } index($0, b) == 1 && !x { x = NR } index($0, c) == 1 && !y { y = NR } index($0, d) == 1 && !z { z = NR } END { print ((w && x && y && z && w < x && x < y && y < z) ? "in order" : "w" w "x" x "y" y "z" z) }' <<<"$OUT")" \
      "yesyesnono|in order" \
  "6v3 its stop line carries the count and the hazard — and the order is the rollback first, then the count, then the stop: the rollback never waited on QL-RB2; verified, nothing says it did not complete (6w's words)"
check "$(grep -c -- '--rollback-unpriced-pending' <<<"$OUT")|$(grep -cE '^ {6}(cd |\( cd )' <<<"$OUT")|$(has "$OUT" 'NO-GO (QL-RB2)')$(has "$OUT" 'could not price')$(has "$OUT" '== F. Summary ==')|$(sed -n 's/^RB_COPY_AT=//p' "$STATE102" | tail -1 | grep -cx "$(stat -c %Y "$PD/lib/KycService.php")")|$(uother102 "$T102V")|$(grep -lF -f "$NAMES102" "$R102/run-$T102V.txt" 2>/dev/null | grep -c .)|$(evo_calls)" \
      "0|0|nonono|1|-|0|0" \
  "6v4 the path prints no command — none to copy, the flag never named, no refusal of its own; the deploy's state file holds the rollback's copy time (RB_COPY_AT, as do_rollback writes it when a deploy rolls itself back, for A14); GETs only; no seeded name printed; Evolution never called"
check "$(unplant102)|$(data94)|$(python3 "$H102/rh102q.py" kv "$(apps102)" unp)" "3|$D102|pending:0,manual:0,p1:0" \
  "…the three taken out: the data as before the plants, byte for byte — the deploy and its own rollback changed no data"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "6v5 the quarantine's own --check after the deploy's own rollback, as A14 asks of the next deploy: PASSED"
KEPT102="$(kept_state)"

echo; echo "== 102-6w. the same, with the deploy's own rollback not verifying: it says so, and prints the by-hand put-back, the hazard first =="
# The redirect planted again, and the fake docker's .rbfail102 armed (its control at the start): deploy-hybrid.sh, run by
# the deploy's own rollback after V1, sees the copy land and its record read back wrong once, and exits 1 — do_rollback
# does not verify, while 5.18.101's files are in place and the next read names it. The words must then be the attempt's
# (review 2, E2-3): the counts said as made after it, 5.18.101's cron only as what follows once it serves, and the stop
# saying the rollback did not complete — never "rolled back" — then the put-back's hazard and the put-back alone on its
# own line, as B's failed rollback prints them; the flag never named. Then a copy that ignores do_rollback's answer, as
# round 2's "do_rollback || true" did, run the same way: it says "rolled back" — caught. ──────────────────────────────────
RB2_NC="after this automatic rollback attempt, which did not complete — 5.18.102 may still serve"
PUTBACK_HAZARD102="The put-back below counts nothing before it copies: an application waiting for uCRM with no plan price recorded — as 5.18.102 saves the sales it cannot price; an older one can be so too — is quoted by 5.18.101's cron with its plan line at price 0 once 5.18.101 serves again, and uCRM's quote.add webhook WhatsApps that quotation to the customer."
printf 'x\n' > "$SB/web/.redirect102"; chmod 644 "$SB/web/.redirect102"; : > "$SB/.rbfail102"
RW102="$(rows_add102 "$PLANT102")"
check "$RW102|$(python3 "$H102/rh102q.py" kv "$(apps102)" unp)|$(inst_ver)|$(live)|$(v1read102)|$(ls -A "$SB" | grep -cE '^\.rbfail102(\.seen)?$')" "{\"rows\":3}|pending:1,manual:1,p1:1|5.18.101|$P101|200:0|1" \
  "6w the redirect planted again (unspent while 5.18.101's manifest is installed: 200, no redirect), the rollback's failed read armed and not yet seen, and the three applications: 1, 1, 1"
fresh102; OUT="$(run102 --answer DEPLOY --redeploy)"; T102W="$(last102)"
check "$(failline "$OUT" V1 'the sign-in page on the public address → 200 after 1 redirect(s)')$(has "$OUT" '  ROLLING BACK — the public address must never redirect')$(spent99 .redirect102)|$(ls -A "$SB" | grep -cE '^\.rbfail102(\.seen)?$')|$(has "$OUT" "container still reports '0000000', expected '$P101'")$(has "$OUT" "the checkout is back on ")|$(live)|$(installed102 "$P101")|$(inst_ver)|$(left102)|$(reach102)" \
      "yesyesspent|0|yesyes|$P101|0|5.18.101|21|0" \
  "6w1 V1 FAILS on the redirect and the deploy's own rollback runs — and does not verify: deploy-hybrid.sh read the record back wrong once (the trigger spent) and the checkout returned; the files are 5.18.101's and the record names it again"
check "$(has "$OUT" "  unpriced apps   $RB2_NC, on a copy of the book, read-only (QL-RB2): pending for uCRM 1, in review or failed 1, refused P1–P3 with a uCRM client 1 — counts only")$(noteline "$OUT" QL-RB2 "$RB2_NC: 1 application(s) still waiting for uCRM with no plan price recorded — as 5.18.102 saves the sales it cannot price; an older one can be so too (on a copy of the book, read-only). 5.18.101 may not be serving yet: the put-back printed below, run by hand, puts it back. Once 5.18.101 serves, its cron (KycCrmSync) retries each within minutes, rebuilds its plan line at price 0 and makes the quotation, and uCRM's quote.add webhook WhatsApps it to the customer. The rollback attempt did not wait for them: the public address must never redirect")$(noteline "$OUT" QL-RB2 "$RB2_NC: 1 application(s) in review or failed with no plan price recorded: a person's retry under 5.18.101 would quote one")$(noteline "$OUT" QL-RB2 "$RB2_NC: 1 application(s) 5.18.102 refused a quotation (P1, P2 or P3) have a uCRM client: the debug retry (retry_quote) could quote one from 5.18.101's own prices once 5.18.101 serves — make their quotations in uCRM, never through that retry")|$(grep -c 'after this automatic rollback[:,]' <<<"$OUT")" \
      "yesyesyesyes|0" \
  "6w2 the counts and the three notes said as made after an attempt that did not complete — 5.18.101's cron only as what follows once it serves; never \"after this automatic rollback\" as of one that did (review 2, E2-3)"
check "$(has "$OUT" "  STOP: the automatic rollback did not complete — it did not verify (live=$P101): 5.18.102 may still serve, or some installed files may already be 5.18.101's, and the public address may still redirect. QL-RB2 counted after the attempt, on a copy of the book: pending for uCRM 1, in review or failed 1, refused P1–P3 with a uCRM client 1 — each pending one would be quoted by 5.18.101's cron with its plan line at price 0 once it serves, and uCRM would WhatsApp it (the note above). $PUTBACK_HAZARD102 Put 5.18.101 back by hand, with its own command — then send this log file and that command's log:")|$(awk -v s='  STOP: the automatic rollback did not complete' -v c="      $EXP_PUTBACK102" 'index($0, s) == 1 { n = NR } n && NR == n + 1 { print ($0 == c ? "next-line" : "other"); exit }' <<<"$OUT")|$(grep -cE '^ {6}(cd |\( cd )' <<<"$OUT")|$(grep -cxF "      $EXP_PUTBACK102" <<<"$OUT")|$(has "$OUT" 'STOP: rolled back')$(has "$OUT" 'Nothing further was done. Send the log file.')$(has "$OUT" '== F. Summary ==')|$(grep -c -- '--rollback-unpriced-pending' <<<"$OUT")|$(grep -c 'deploy-5\.18\.102\.sh --rollback' <<<"$OUT")|$(has "$OUT" 'could not price')" \
      "yes|next-line|1|1|noyesno|0|0|no" \
  "6w3 the stop says the rollback did not complete — never \"rolled back\" — with the counts, the put-back's hazard first and the by-hand put-back alone on the next line: the one command printed; no rollback command, the flag never named (review 2, E2-3)"
check "$(sed -n 's/^RB_COPY_AT=//p' "$STATE102" | tail -1 | grep -cx "$(stat -c %Y "$PD/lib/KycService.php")")|$(uother102 "$T102W")|$(grep -lF -f "$NAMES102" "$R102/run-$T102W.txt" 2>/dev/null | grep -c .)|$(evo_calls)" "1|-|0|0" \
  "6w4 the state file holds the attempt's copy time (RB_COPY_AT, for A14); GETs only; no seeded name printed; Evolution never called"
check "$(unplant102)|$(data94)|$(python3 "$H102/rh102q.py" kv "$(apps102)" unp)" "3|$D102|pending:0,manual:0,p1:0" \
  "…the three taken out: the data as before the plants, byte for byte"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "6w5 the quarantine's own --check after the attempt, as A14 asks of the next deploy: PASSED"
KEPT102="$(kept_state)"
M="$(weak102 v1rbw v1rb)"; printf 'x\n' > "$SB/web/.redirect102"; chmod 644 "$SB/web/.redirect102"; : > "$SB/.rbfail102"
fresh102; OUT="$(R102_MUTANT_OUT=1 run102 --script "$M" --answer DEPLOY --redeploy)"; rm -f "$M"
check "$(has "$OUT" "container still reports '0000000', expected '$P101'")$(spent99 .redirect102)|$(ls -A "$SB" | grep -cE '^\.rbfail102(\.seen)?$')|$(has "$OUT" 'STOP: rolled back (serves ')$(has "$OUT" 'did not complete')|$(grep -cxF "      $EXP_PUTBACK102" <<<"$OUT")|$(live)" \
      "yesspent|0|yesno|0|$P101" \
  "6w6 control: with do_rollback's answer ignored again (round 2's \"do_rollback || true\"), the same failed rollback says \"rolled back\" and prints no put-back — 6w3 does not hold on it (caught)"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "6w7 the quarantine's own --check after it: PASSED"
KEPT102="$(kept_state)"

echo; echo "== 102-7. --redeploy after the rollback =="
fresh102; OUT="$(run102 --answer DEPLOY --redeploy)"; OUT102R="$OUT"; T102R="$(last102)"
check "$(fails "$OUT")$(has "$OUT" '5.18.102 (deploy): PASSED')$(live)$(installed102 "$P102")$(inst_ver)" "0yes${P102}05.18.102" "7a --redeploy, DEPLOY typed: PASSED, $P102 serving, every file as the pin has it"
check "$(okline "$OUT" BR1 'the installed plugin is live 5.18.101' '; 21 of the 21 files 5.18.102 adds are already on disk, left by an earlier 5.18.102 and its rollback (deploy-hybrid.sh never deletes) — the copy replaces them')$(noteline "$OUT" BR1 'although --redeploy was not asked for')$(sed -n 's/^REDEPLOY=//p' "$STATE102" | tail -1)$(has "$OUT" "$QLG1_OK")$(okline "$OUT" QL-CA1 "gives every row the verdict stage A's pin gave it")$(okline "$OUT" QS-CA1 'as text, installed')" \
      "yesno1yesyesyes" \
  "7b BR1's clause: 21 of the 21 files 5.18.102 adds already on disk, left by the rollback, and no note about --redeploy (it was asked for); --redeploy recorded; QL-G1, QL-CA1 and QS-CA1"
copy_back102 30; COPY102R="$(sed -n 's/^COPY_AT=//p' "$STATE102" | tail -1)"
[ "$(epoch99 "$COPY102R")" = "epoch" ] && set_sched $(( COPY102R + 300 )); base_log
ALINE2="$(printed "$OUT102R" "cd $REPO && bash scripts/deploy-5.18.102.sh --after-only")"
fresh102; OUT="$(run_line102 '' "$ALINE2")"
check "$ALINE2|$(fails "$OUT")$(has "$OUT" '5.18.102 (after): PASSED')$(okline "$OUT" QL-CA1 'nothing asked but the two lists')" "$EXP_AFTER102|0yesyes" "7c its --after-only, as printed: PASSED, QL-CA1 readable"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "7d the quarantine's own --check, as the runbook orders: PASSED"
KEPT102="$(kept_state)"
rb102() {   # rb102 LABEL — the rollback, ROLLBACK typed, nothing waiting: PASSED, 5.18.101 serving; then the quarantine's --check
  local o; fresh102; o="$(run102 --answer ROLLBACK --rollback)"
  check "$(fails "$o")$(has "$o" '5.18.102 (rollback): PASSED')$(live)$(installed102 "$P101")|$(left102)|$(reach102)" "0yes${P101}0|21|0" "$1 the rollback, ROLLBACK typed: PASSED — 5.18.101 serving, its files back, the 21 added on disk and reached by nothing"
  o="$(qrun -- --check)"
  check "$(has "$o" 'stray-store quarantine (check): PASSED.')" "yes" "…the quarantine's own --check after it, as A14 asks of the next deploy: PASSED"
  KEPT102="$(kept_state)"
}
after_back102() {   # after_back102 — the copy thirty minutes back and master's retry job after it, as 5 does, so that R19 is conclusive
  copy_back102 30; local c; c="$(sed -n 's/^COPY_AT=//p' "$STATE102" | tail -1)"
  [ "$(epoch99 "$c")" = "epoch" ] && set_sched $(( c + 300 )); base_log
}
QNR102="not read after the copy (Q-CA2 above says why)"
QORF102="  FAIL  Q-CA2 Q-CA3 the quoting checks could not be read on a copy and from uCRM — this reading's folder could not be made. Every copy is removed"
QLRF102="  FAIL  QL-CA1 the catalogue could not be read after the copy: the quoting checks' reading through the installed code did not complete (this reading's folder could not be made)"
fq102() { grep -E '^  FAIL  ' <<<"$1" | grep -vxF -e "$QORF102" -e "$QLRF102" | grep -c .; }   # fq102 OUTPUT — FAIL lines other than the reading's two
stagea102() {   # stagea102 OUTPUT — F said on stage A's reading (review 2, E2-2, L2): what changed, the quoting org. line, the screens, what did not, D9; then how many off/unknown words
  echo "$(has "$1" "  what changed      on this Uganda server (Q-G2), the quoting organization #1 in force as stage A read it (Q-G3) — $QNR102; the --after-only below reads it again: the KYC quotations take their lines and prices from uCRM's Products and Service Plans")$(grep -cxF "  quoting org.      #1 in force as stage A read it (Q-G3) — $QNR102: the screens and your steps below are said on that reading" <<<"$1")$(has "$1" "  the screens       on Uganda, signed in: the KYC form draws each plan and kit at uCRM's price")$(has "$1" "  your steps        yours alone — this script does none of them (docs/71 §M):")$(has "$1" "the quoting organization — #1 as stage A read it (Q-G3), $QNR102 — not shown unchanged by this run — the company every quote is made on")$(has "$1" "(Q-G1, Q-CA1) — though, with the quoting organization in force, what webhook.php and post_kyc.php do on Uganda changes through D9, the Delivery PDF not sent (above); every number")|$(grep -cE '^  what changed      on this server the quoting organization is not in force|^  the screens       none changes|^  your steps        none now|^  the screens       not stated|none in force|NOT ESTABLISHED' <<<"$1")"
}

echo; echo "== 102-8. the quoting reading after the copy failing: F says so and keeps stage A's narrative — an --after-only and a deploy; --accept-unpriced, then rows fixed since =="
# The fake docker's .qorfail102 (its control at the start): the folder of the reading after the copy (qo_run r) refused
# once, so that reading stops before its gate probe — Q-CA2 and QL-CA1 FAIL, nothing is read after the copy, and F must
# say the quoting organization as stage A read it, said as such, never as "not in force" (review 2, E2-2, L2). In an
# --after-only run stage A's reading is the deploy's state file's. Then a copy of the summary's gate that reads a reading
# not made as "off" — caught. ────────────────────────────────────────────────────────────────────────────────────────────
: > "$SB/.qorfail102"; fresh102; OUT="$(run_line102 '' "$ALINE2")"
check "$(ls -A "$SB" | grep -c '^\.qorfail102$')|$(grep -cxF "$QORF102" <<<"$OUT")$(grep -cxF "$QLRF102" <<<"$OUT")$(noteline "$OUT" QL-CA2 'the salesPerson attribute was not read after the copy')|$(fq102 "$OUT")|$(has "$OUT" '5.18.102 (after): 2 FAILED')" "0|11yes|0|yes" \
  "8a the --after-only with the reading after the copy refused (the trigger spent): Q-CA2/Q-CA3 and QL-CA1 FAIL saying why, QL-CA2 not read — and nothing else fails"
check "$(stagea102 "$OUT")|$(grep -cxF "                       Taxable off, its invoice label empty or its name — this run's reading after the copy gives no count to judge (QL-CA1 above);" <<<"$OUT")" "yes1yesyesyesyes|0|1" \
  "8a' F keeps stage A's narrative, said as stage A's (the deploy's state file) and as not read after the copy — what changed, the screens and the steps of a quoting organization in force, D9 — never \"not in force\", \"none changes\", \"none now\" or NOT ESTABLISHED; step 3 says there is no count to judge (review 2, E2-2, L2, L6)"
M="$(weak102 fgatew fgate)"; : > "$SB/.qorfail102"; fresh102; OUT="$(R102_MUTANT_OUT=1 run102 --script "$M" --after-only)"; rm -f "$M"
check "$(ls -A "$SB" | grep -c '^\.qorfail102$')|$(stagea102 "$OUT" | cut -c1-2)|$(has "$OUT" '  what changed      on this server the quoting organization is not in force')$(has "$OUT" '  your steps        none now')" "0|no|yesyes" \
  "8a'' the summary's gate weakened to read a reading not made as \"off\": the same run says the quoting organization is not in force, and no steps — 8a' does not hold on it (caught)"
rb102 "8b"
# 8c: a deploy over an unpriced row on --accept-unpriced, the reading after its copy refused: F's step 3 and the unpriced
# sentence say the deploy went ahead over them and this run has no count to judge — "required unless fixed since", never
# "required now" (L6) — on stage A's narrative (E2-2). Then two --after-only runs: the row still unpriced (required now,
# the flag said beside it), and fixed in uCRM (none unpriced now). ─────────────────────────────────────────────────────
u99_set 'price_of={"9104":0}'; : > "$SB/.qorfail102"; fresh102; OUT="$(run102 --answer DEPLOY --redeploy --accept-unpriced)"; OUT102X="$OUT"
check "$(ls -A "$SB" | grep -c '^\.qorfail102$')|$(live)|$(noteline "$OUT" QL-G1 "--accept-unpriced, the operator's explicit choice: row(s) the KYC form offers do not resolve in uCRM — $SAYU_ONE")|$(grep -cxF "$QORF102" <<<"$OUT")$(grep -cxF "$QLRF102" <<<"$OUT")|$(fq102 "$OUT")|$(has "$OUT" '5.18.102 (deploy): 2 FAILED')|$(grep -c '^QLG1_NOTED=1$' "$STATE102")" \
      "0|$P102|yes|11|0|yes|1" \
  "8c the deploy (--redeploy --accept-unpriced, one row priced 0), the reading after its copy refused: QL-G1 the flag's note; 5.18.102 serving; Q-CA2/Q-CA3 and QL-CA1 FAIL and nothing else; the state file records that the flag decided QL-G1"
check "$(stagea102 "$OUT")|$(has "$OUT" " The deploy went ahead over catalogue rows uCRM did not price, on --accept-unpriced — the operator's explicit choice (QL-G1); this run's reading after the copy gives no count to judge (QL-CA1 above), so whether each is fixed is not known: until it is (your steps, 3, below), a Cash sale of one is refused and a Credit one is registered with no quotation (P1).")|$(grep -cxF "                       Taxable off, its invoice label empty or its name — required unless fixed since: the deploy went ahead over unpriced rows on --accept-unpriced (QL-G1), and this run's reading after the copy gives no count to judge (QL-CA1 above);" <<<"$OUT")|$(has "$OUT" 'required now')" \
      "yes1yesyesyesyes|0|yes|1|no" \
  "8c' F on stage A's reading (E2-2, L2): the deploy went ahead over unpriced rows on the flag, and this run's reading gives no count to judge — step 3 \"required unless fixed since\", never \"required now\" (L6)"
after_back102; ALINE3="$(printed "$OUT102X" "cd $REPO && bash scripts/deploy-5.18.102.sh --after-only")"
fresh102; OUT="$(run_line102 '' "$ALINE3")"
check "$ALINE3|$(fails "$OUT")$(has "$OUT" '5.18.102 (after): PASSED')$(has "$OUT" "  QL-CA1          the same counts as stage A read through the pin's (the state file): unpriced $UNP_ONE")$(has "$OUT" "the quoting organization #1 in force (Q-G3, Q-CA2): the KYC quotations")|$(has "$OUT" " The deploy went ahead over catalogue rows uCRM does not price, on --accept-unpriced — the operator's explicit choice (QL-G1) — and this run's reading after the copy finds rows unpriced (QL-CA1): until each is fixed in uCRM (your steps, 3, below), a Cash sale of one is refused and a Credit one is registered with no quotation (P1).")|$(grep -cxF "                       Taxable off, its invoice label empty or its name — required now: this run's reading after the copy (QL-CA1) finds rows unpriced, each on the sheet; the deploy went ahead over unpriced rows on --accept-unpriced (QL-G1);" <<<"$OUT")" \
      "$EXP_AFTER102|0yesyesyes|yes|1" \
  "8d its --after-only as printed, the row still unpriced: PASSES, the quoting organization read again after the copy; QL-CA1 the state file's counts — and F, from this run's own reading and the deploy's flag, \"required now\", the flag said beside it (review 2, L6)"
u99_set; fresh102; OUT="$(run_line102 '' "$ALINE3")"
check "$(fails "$OUT")$(has "$OUT" '5.18.102 (after): PASSED')$(noteline "$OUT" QL-CA1 "fewer unpriced than stage A read, over both clients: now $UNP_NONE; at stage A $UNP_ONE")|$(has "$OUT" " The deploy went ahead over catalogue rows uCRM did not price, on --accept-unpriced — the operator's explicit choice (QL-G1); this run's reading after the copy finds none unpriced now (QL-CA1).")|$(grep -cxF "                       Taxable off, its invoice label empty or its name — the deploy went ahead over unpriced rows on --accept-unpriced (QL-G1); this run's reading after the copy (QL-CA1) finds none unpriced now;" <<<"$OUT")|$(has "$OUT" 'required now')$(has "$OUT" 'required unless fixed since')" \
      "0yesyes|yes|1|nono" \
  "8e the row fixed in uCRM since, the same --after-only: PASSES, QL-CA1 a note of fewer unpriced — and F says the deploy went ahead over them and this run finds none unpriced now, never \"required now\" over rows fixed since (review 2, L6)"
rb102 "8f"

echo; echo "== 102-9. the quoting organization off — --quote-company-off: nothing priced from uCRM, no \"Prepared by\"; QL-N1 and QL-CA2 notes, never an ok; F's own words for it =="
# ucrm_quote_organization_id 0 in both copies of the configuration, one client attribute keyed salesPerson (#47) in uCRM,
# as throughout. With the gate off the release sets no "Prepared by" — UcrmClientTarget::salesPersonFieldId() and
# patchSalesPerson() return first — and quotes as 5.18.101 does (D7): QL-N1 and QL-CA2 say the attribute and that it is
# not used, never an ok; the probe's own reading of the release's lookup is "off"; nothing of the catalogue is read
# (review 2, E2-1, L8). Each weakened, caught. The setting put back at the end. ──────────────────────────────────────────
QLN1_OFF="QL-N1 the client attribute keyed salesPerson: exactly one, #47 — not used: no quoting organization is in force (Q-G3), so 5.18.102 sets no \"Prepared by\" on a new KYC customer; it does so only where the quoting organization is in force (docs/71 §I, D7)"
QLCA2_OFF="QL-CA2 the client attribute keyed salesPerson, through the installed code's reading: exactly one, #47 — not used: no quoting organization is in force (Q-CA2), so 5.18.102 sets no \"Prepared by\" on a new KYC customer"
QLCA1_OFF="  QL-CA1          not asked: no quoting organization is in force (Q-CA2) — 5.18.102 quotes as 5.18.101 does, and nothing is priced from uCRM"
cfg_keep; file_set ucrm_quote_organization_id 0; row_set ucrm_quote_organization_id 0; D9Q="$(data94)"
fresh102; OUT="$(run102 --check)"
nogo102 "9a ucrm_quote_organization_id 0 in both copies, --check: NO-GO at Q-G3 (5.18.99's, carried)" "NO-GO (Q-G3): ucrm_quote_organization_id is 0/off on this server (off:-:explicit-off:-; files/store row)" "$OUT" "$D9Q"
check "$(grep -cxF "      cd $REPO && bash scripts/deploy-5.18.102.sh --quote-company-off $(LOGW102 deploy)" <<<"$OUT")" "1" "9a' …the deploy with --quote-company-off printed alone, its own command — the operator's choice"
fresh102; S9C="$(states102)"; OUT="$(run102 --check --quote-company-off)"; T9C="$(last102)"; SH9C="$(ls -1 "$OUT102"/cash-*.txt 2>/dev/null | head -1)"
check "$(checkgo102 "$OUT")|$(noteline "$OUT" Q-G3 "--quote-company-off, the operator's explicit choice")$(has "$OUT" "  note  QL-G1 not asked: no quoting organization is in force (Q-G3) — 5.18.102 then quotes as 5.18.101 does, nothing is priced from uCRM, and there is nothing to measure (docs/71 D7)")$(grep -cxF "  note  $QLN1_OFF" <<<"$OUT")$(noteline "$OUT" QL-N3 'With no quoting organization in force (Q-G3) the retry quotes them after the deploy as 5.18.101 does: nothing is priced from uCRM (D7)')$(has "$OUT" '  note  QL-N7 not asked: no quoting organization is in force (Q-G3)')|$(grep -E '^  note  QL-N[13] ' <<<"$OUT" | grep -cE 'PATCHed|Whatever uCRM holds|prices alone|\(D11')|$(qor102 "$OUT" 1 qlc)|$(qor102 "$OUT" 1 qlattr)|$(qor102 "$OUT" 1 qlattrrel)" \
      "yesyesnono0|0|$S9C|yesyes1yesyes|0|off|one:47|off" \
  "9b --check --quote-company-off: GO; Q-G3 the flag's note; QL-G1 and QL-N7 not asked; QL-N1 the one attribute, #47, not used — no \"Prepared by\" with the gate off; QL-N3 the retry as 5.18.101's, nothing from uCRM, no D11 sentence (review 2, E2-1); the probe's catalogue off and the release's own lookup off"
check "$(ureq102 "$T9C" | sed -E 's/^total=[0-9]+ //')|$(lim102 "$T9C")|$(grep -cE '^== QL-(G1|CA1) the catalogue' "$SH9C" 2>/dev/null)|$(grep -cxF "      $(runbook_with102 --quote-company-off)" <<<"$OUT")" "other=0 products=0/0 plans=0/0 attrs=1 quote=0 odd=0|n=0 bad=0|0|1" \
  "9b' …uCRM asked no list — the attributes once, the probe's own reading (the release's lookup asks nothing with the gate off), all GETs; the sheet names no catalogue row; the deploy it hands over carries the flag"
M="$(weak102 qln1offw qln1off)"; fresh102; OUT="$(R102_MUTANT_OUT=1 run102 --script "$M" --check --quote-company-off)"; rm -f "$M"
check "$(grep -cxF "  note  $QLN1_OFF" <<<"$OUT")$(noteline "$OUT" QL-N1 '"Prepared by" is PATCHed on each new KYC customer')" "0yes" \
  "9c QL-N1's gate-off branch weakened: with the quoting organization off it says \"Prepared by\" is PATCHed on each new KYC customer — 9b does not hold on it (caught)"
fresh102; OUT="$(run102 --answer DEPLOY --redeploy --quote-company-off)"; OUT102Q="$OUT"; T9D="$(last102)"; SH9D="$(ls -1 "$OUT102"/cash-*.txt 2>/dev/null | head -1)"
check "$(fails "$OUT")$(has "$OUT" '5.18.102 (deploy): PASSED')$(live)$(installed102 "$P102")" "0yes${P102}0" "9d the deploy with --quote-company-off, DEPLOY typed: PASSES, 5.18.102 serving"
check "$(okline "$OUT" Q-CA2 'reads what stage A read through the pin' 'off:-:explicit-off:-')$(grep -cxF "  note  $QLN1_OFF" <<<"$OUT")$(grep -cxF "$QLCA1_OFF" <<<"$OUT")$(grep -cxF "  note  $QLCA2_OFF" <<<"$OUT")|$(grep -c '^  ok    QL-CA2 ' <<<"$OUT")|$(grep -c '"Prepared by" is set on each new KYC customer' <<<"$OUT")|$(qor102 "$OUT" 2 qlattrrel)|$(ureq102 "$T9D" | sed -E 's/^total=[0-9]+ //')|$(grep -cE '^== QL-(G1|CA1) the catalogue' "$SH9D" 2>/dev/null)" \
      "yes111|0|0|off|other=0 products=0/0 plans=0/0 attrs=2 quote=0 odd=0|0" \
  "9d' Q-CA2 the installed gate off, as stage A read it; QL-N1 at stage A and QL-CA2 after the copy each the attribute and that it is not used — never an ok, never \"Prepared by\" set (review 2, E2-1); QL-CA1 not asked; no list asked of uCRM; no catalogue section on the sheet"
check "$(has "$OUT" "  what changed      on this server the quoting organization is not in force (Q-G3: ucrm_quote_organization_id 0/off, the operator's choice), so 5.18.102 quotes as 5.18.101 does (D7)")$(grep -cxF "  the screens       none changes: with the quoting organization not in force, the KYC form, Send Quotation, the" <<<"$OUT")$(grep -cxF "  your steps        none now: with the quoting organization not in force, 5.18.102 quotes as" <<<"$OUT")$(grep -cxF "                    5.18.101 does. This run did NOT read the catalogue: with no quoting organization in" <<<"$OUT")$(grep -cxF "                    force its probe reads none (QL-G1, QL-N7 and QL-CA1 above), and its sheet names" <<<"$OUT")$(grep -cxF "                    refuses once 5.18.102 serves, and an --after-only's probe reads none either — so" <<<"$OUT")$(grep -cxF "                    Change, nor for a Fiber one (D11): each is quoted by hand in uCRM, or not at all" <<<"$OUT")|$(has "$OUT" "the quoting organization — none in force, as under 5.18.101 (Q-G3, Q-CA2) — the company every quote is made on")$(has "$OUT" "5.18.101's byte for byte (Q-G1, Q-CA1); every number in the registry")$(has "$OUT" 'NOT ESTABLISHED')$(has "$OUT" '  quoting org.      ')" \
      "yes111111|yesyesnono" \
  "9d'' F's own words for the gate off: 5.18.102 quotes as 5.18.101 does, no screen changes, no step now — and that this run read no catalogue, that no later run can while the setting is off, and what to check by hand before it is turned on (review 2, L8); the quoting organization none in force, as under 5.18.101; no D9 clause"
after_back102; ALINE9="$(printed "$OUT102Q" "cd $REPO && bash scripts/deploy-5.18.102.sh --after-only")"
fresh102; OUT="$(run_line102 '' "$ALINE9")"
check "$ALINE9|$(fails "$OUT")$(has "$OUT" '5.18.102 (after): PASSED')$(grep -cxF "$QLCA1_OFF" <<<"$OUT")$(grep -cxF "  note  $QLCA2_OFF" <<<"$OUT")|$(grep -c '^  ok    QL-CA2 ' <<<"$OUT")|$(grep -cxF "                    force its probe reads none (QL-CA1 above), and its sheet names" <<<"$OUT")$(grep -cxF "  your steps        none now: with the quoting organization not in force, 5.18.102 quotes as" <<<"$OUT")" \
      "$EXP_AFTER102|0yes11|0|11" \
  "9e its --after-only as printed: PASSES; QL-CA1 not asked; QL-CA2 the attribute, not used — never an ok; F's off words, this run's probe named alone (QL-CA1)"
M="$(weak102 qlca2w qlca2off qlca2ok qlattron qlattrrel)"; fresh102; OUT="$(R102_MUTANT_OUT=1 run102 --script "$M" --after-only)"; rm -f "$M"
check "$(okline "$OUT" QL-CA2 'reads as stage A read it: exactly one, #47' '"Prepared by" is set on each new KYC customer')$(grep -cxF "  note  $QLCA2_OFF" <<<"$OUT")|$(qor102 "$OUT" 1 qlattrrel)" "yes0|47" \
  "9f QL-CA2's gate weakened back to round 1 — the probe's lookup forced on, the two guards of the shell gone: an ok saying \"Prepared by\" is set over a quoting organization that is off — 9d' and 9e do not hold on it (caught)"
rb102 "9g"
cfg_back
check "$(data94)|$(set99 ucrm_quote_organization_id)" "$D102|unset/unset" "…the quoting organization's setting put back in both copies: the data as before, byte for byte"

echo; echo "== 102-10. what the rehearsal left behind, and what it never reached =="
fresh102   # the last logs and sheets into the sweeps' folders
check "$(evo_calls)" "0" "Evolution never called — not by the deploys, their checks, the rollbacks or the code they ran on copies"
check "$(ls -a "$REPO/scripts" | grep -E '^\..+\.sh$' | paste -sd, -)" ".qpin.sh" "no weakened or re-pinned copy is left in the clone's scripts/ — the quarantine's one pinned copy aside"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )" "" "every weakened copy, edit of the stand-ins and planted edit — this rehearsal's and those of the rehearsals it runs — found its anchor exactly once"
rm -f "$SB/anchor-missing"   # the controls below read their own record alone
M="$(weak102 anchorprobe102 no-such-entry 2>/dev/null)"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )" "weak102:no-such-entry " "control on the control: a weakened copy asked for an entry the table does not hold is recorded as such (weak102)"
rm -f "$SB/anchor-missing" "$M"
M="$(weak102 anchorprobe102b copymark)"
cp "$DEPLOY102" "$SB/twice102.sh"; printf '%s\n' "$(a102 copymark)" >> "$SB/twice102.sh"; TW102="$(sha256sum "$SB/twice102.sh" | cut -c1-64)"
TWR102="$(python3 - "$SB/twice102.sh" "$(a102 copymark)" "$(r102 copymark)" <<'PY' 2>/dev/null && echo applied || echo refused
import sys
f, a, b = sys.argv[1:4]; s = open(f, encoding='utf-8').read()
assert a and s.count(a) == 1, (a[:60], s.count(a))
open(f, 'w', encoding='utf-8').write(s.replace(a, b))
PY
)"
check "$(grep -cxF "$(r102 copymark)" "$M")|$(grep -cxF "$(a102 copymark)" "$M")|$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )|$TWR102|$( [ "$(sha256sum "$SB/twice102.sh" | cut -c1-64)" = "$TW102" ] && echo unchanged)" "1|0||refused|unchanged" \
  "control on the control: a weakened copy replaces its anchor exactly once (the copy says COPY_MARK=\"webhook.php\" once, the original line nowhere), and the same replacement, given a script holding that anchor twice, refuses and changes nothing"
rm -f "$SB/anchor-missing" "$M" "$SB/twice102.sh"
check "$( [ -f "$SB/bad-runs" ] && tr '\n' ' ' < "$SB/bad-runs" )" "" "every run took the options it was given — none answered 'unknown option'"
OUT="$(run102 --after-only --rehearsal-unknown-option)"
check "$(has "$OUT" 'unknown option: --rehearsal-unknown-option')$( [ -s "$SB/bad-runs" ] && echo recorded)" "yesrecorded" "control on the control: a run given an option the script does not know is recorded as such"
rm -f "$SB/bad-runs"
OUT="$(run102 --after-only --rollback-unpriced-pending)"
check "$(has "$OUT" '--rollback-unpriced-pending goes with --rollback only')$(has "$OUT" 'PASSED')" "yesno" "…and --rollback-unpriced-pending with --after-only is refused before anything runs"
tok102() { python3 "$H102/rh102.py" tok99 "$R102/tok/run-$1.tok"; }   # tok102 TAG — remote=<n> copied=<n> left=<n>
OBS_BAD=0; OBS_N=0; TOK_BAD=0; GET_BAD=0; ODD_BAD=0; LIM_BAD=0
for rec in "$R102"/exec/run-*.rec; do
  t="${rec##*/run-}"; t="${t%.rec}"; OBS_N=$((OBS_N+1))
  a="$(audit102 "$t")"; case "$a" in *" novault=0 plugin=0 "*) ;; *) OBS_BAD=$((OBS_BAD+1)) ;; esac
  [ "$(hits102 "$t")" = "0" ] || OBS_BAD=$((OBS_BAD+1))
  [ "$(cat "$R102/vault/run-$t.before")" = "$(cat "$R102/vault/run-$t.after")" ] || OBS_BAD=$((OBS_BAD+1))
  case "$(tok102 "$t")" in *" left=0") ;; *) TOK_BAD=$((TOK_BAD+1)) ;; esac
  [ "$(uother102 "$t")" = "-" ] || GET_BAD=$((GET_BAD+1))
  case "$(ureq102 "$t")" in *" odd=0") ;; *) ODD_BAD=$((ODD_BAD+1)) ;; esac
  case "$(lim102 "$t")" in *" bad=0") ;; *) LIM_BAD=$((LIM_BAD+1)) ;; esac
done
check "$( [ "$OBS_N" -ge 20 ] && echo many)|$OBS_BAD|$TOK_BAD|$GET_BAD|$ODD_BAD|$LIM_BAD" "many|0|0|0|0|0" \
  "10 across every run of the 5.18.102 script — the checks, the refusals, the weakened copies, the deploys, the after-onlys and the rollbacks — the copy observers were quiet, every token copy was gone at the call after its probe, and the stand-in uCRM received nothing but GETs of the shapes the release reads, its lists always with limit=500"
check "$(vt_host)|$(grep -cxF "$W96" "$DATA/webhook_secret")" "$VT99_0|1" "…the live vault as it was before the first run of 5.18.99's rehearsal, bytes and time; the planted webhook secret where it was planted"
sweep_files102() { ls -1 "$R102"/run-*.txt "$R102"/line-*.txt "$R102"/mutants/run-*.txt "$R102"/logs/*.log "$OUT102"/*.log "$OUT102"/state-5.18.102.env* "$OUT102"/rollback-state-* "$OUT102"/cih-rows-* 2>/dev/null; }
sheets102() { ls -1 "$R102"/sheets/cash-*.txt 2>/dev/null; }
SALTS102="$SB/salts102.pat"; : > "$SALTS102"; chmod 600 "$SALTS102"
SALTN102="$(python3 "$H102/rh102q.py" salts "$SALTS102" "$R102"/exec/run-*.rec)"
forms102() { python3 "$H102/rh102.py" forms "$SB/forms102.txt" "$SB/seed96.amounts" "$SB/seed97.amounts" "$SB/stmt97.amounts" "$SB/seed98.amounts" "$AMOUNTS102" "$H97"/cash-*.json "$H98"/cash-*.json "$H99"/cash-*.json "$R97"/sheets/cash-*.txt "$R98"/sheets/cash-*.txt "$R99"/sheets/cash-*.txt "$R100"/sheets/cash-*.txt "$R101"/sheets/cash-*.txt "$R102"/sheets/cash-*.txt "$OUT97"/cash-*.txt "$OUT98"/cash-*.txt "$OUT99"/cash-*.txt "$OUT100"/cash-*.txt "$OUT101"/cash-*.txt >/dev/null 2>&1; }
figs102() { forms102; python3 "$H102/rh102.py" hits "$SB/forms102.txt" "$@"; }   # figs102 FILE… — how many hold a figure of the book or a seeded price, in any printed form
check "$( [ "$(sweep_files102 | grep -c .)" -ge 20 ] && echo many)|$( [ "$(sheets102 | grep -c .)" -ge 8 ] && echo sheets)|$(forms102; grep -c . "$SB/forms102.txt" | awk '{print ($1 >= 100 ? "forms" : "few:" $1)}')|$(for a in $(cat "$AMOUNTS102"); do grep -qxF "2:$(python3 -c 'import sys; print("{:,.2f}".format(float(sys.argv[1])))' "$a")" "$SB/forms102.txt" && echo y || echo n; done | sort -u | paste -sd, -)|$( [ "$(ls "$R102"/ucrm/run-*.req 2>/dev/null | grep -c .)" -ge 20 ] && echo records)|$(echo "$SALTN102" | awk '{print ($1 >= 10 ? "keys" : "few:" $1) " " ($2 >= 32 ? "long" : "short:" $2)}')" \
      "many|sheets|forms|y|records|keys long" \
  "the sweep has material: every run's terminal and log of this release's, the state files and row files; its runs' sheets; a hundred and more printed forms of the book's figures, every seeded price among them; every run's request record; the run keys the runs handed their probes, each at least 32 characters"
check "$(figs102 $(sweep_files102))" "0" "10a no figure of the book, of a kept statement or of a seeded price — in any printed form — in any terminal, log, state file or row file of any run of the 5.18.102 script"
check "$(sweep_files102 | xargs grep -lF -f "$NAMES102" 2>/dev/null | grep -c .)|$( { sweep_files102; sheets102; } | xargs grep -lF -f "$NOWHERE102" 2>/dev/null | grep -c .)|$(sheets102 | xargs grep -lF -f "$OFFERED102" 2>/dev/null | grep -c .  | awk '{print ($1 >= 6 ? "sheets" : "few:" $1)}')|$( { sweep_files102; sheets102; ls -1 "$R102"/ucrm/run-*.req; } | xargs grep -lF -f "$SALTS102" 2>/dev/null | grep -c .)" \
      "0|0|sheets|0" \
  "10b no seeded plan, kit, package or product name in any terminal, log, state file or row file — they are on the 0600 sheets alone (positive: the sheets hold them); the inactive rows, the unoffered plan, 000314's lines and the notes prefix nowhere, sheets included; no run key anywhere"
check "$(sweep_files102 | xargs grep -lF -f "$SCAN96" 2>/dev/null | grep -c .)|$(grep -v '^$' "$SEC95" > "$SB/sec102.pat"; sweep_files102 | xargs grep -lF -f "$SB/sec102.pat" 2>/dev/null | grep -c .)|$(secrets99 $(sweep_files102) $(sheets102) "$R102"/ucrm/run-*.req)|$(names100 $(sweep_files102) $(sheets102))" "0|0|0|0" \
  "10c no output, log, state file, sheet or request record holds the planted webhook secret or vault credential, a repair key, a planted Splynx or pdf value, a name, contact, app key or token of the stand-in uCRM's — or a staff record's name"
check "$(noids99 $(sweep_files102))|$(bankhits98 $(sweep_files102))|$(sweep_files102 | xargs grep -lE ' (gh|oroles)=|(^| )qlh=' 2>/dev/null | grep -c .)" "0|0|0" \
  "10d no output, log or state file names a client id the app holds, an account name or number — or carries a keyed hash of the owner or catalogue checks"
printf 'planted %s, %s, %s, client %s, %s, %s, %s and %s\n' "$(grep -m1 '^2:' "$SB/forms102.txt" | cut -c3-)" "$W96" "$(head -1 "$U99_SECRETS")" "$(head -1 "$SB/ids99.pat")" "RH100SECRET-Tech-One" "$(head -1 "$NAMES102")" "$(tail -1 "$NOWHERE102")" "$(head -1 "$SALTS102")" > "$R102/zz-sweep-control.txt"
printf 'priced %s\n' "$(python3 -c 'import sys; print("{:,.2f}".format(float(sys.argv[1])))' "$(head -1 "$AMOUNTS102")")" > "$R102/zz-price-control.txt"
check "$(figs102 "$R102/zz-sweep-control.txt")$(figs102 "$R102/zz-price-control.txt")$(grep -lF -f "$SCAN96" "$R102/zz-sweep-control.txt" | grep -c .)$(secrets99 "$R102/zz-sweep-control.txt")$(noids99 "$R102/zz-sweep-control.txt")$(names100 "$R102/zz-sweep-control.txt")$(grep -lF -f "$NAMES102" "$R102/zz-sweep-control.txt" | grep -c .)$(grep -lF -f "$NOWHERE102" "$R102/zz-sweep-control.txt" | grep -c .)$(grep -lF -f "$SALTS102" "$R102/zz-sweep-control.txt" | grep -c .)" "111111111" \
  "control on the sweeps: a file holding one figure form, one holding a seeded price, the planted secret, a stand-in secret, a client id, a seeded staff name, a seeded catalogue name, a name meant for nowhere and a run key is found by all nine"
rm -f "$R102/zz-sweep-control.txt" "$R102/zz-price-control.txt" "$SB/sec102.pat"
rterr102() {   # rterr102 FILE… — how many of the files hold a bash runtime error of a script ("<x>.sh: line N: …")
  [ "$#" -gt 0 ] || { echo none; return; }
  grep -lE '\.sh: line [0-9]+: .*(bad substitution|unbound variable|syntax error|command not found)' "$@" 2>/dev/null | grep -c .
}
printf '%s\n' 'X=1; Y=2' 'echo "a:${X:+ the copy'"'"'s:$X}${Y:+ the base'"'"'s:$Y} end"' > "$SB/rterr102.sh"
bash "$SB/rterr102.sh" > "$R102/zz-rterr-control.txt" 2>&1
check "$(rterr102 $(sweep_files102))|$(rterr102 "$R102/zz-rterr-control.txt")" "0|1" \
  "10e no terminal or log of any run of the 5.18.102 script holds a bash runtime error; control: a script with an apostrophe inside \${…:+…} is caught"
rm -f "$SB/rterr102.sh" "$R102/zz-rterr-control.txt"
# RH-1 (review 1): every QL-RB2 text says the applications are "waiting for uCRM with no plan price recorded" — the count
# carries no 5.18.102 mark — never ones "5.18.102 could not price". Swept over every run; control: the old words are found.
printf '%s\n' '  note  QL-RB2 1 application(s) 5.18.102 could not price are in review or failed' > "$R102/zz-rh1-control.txt"
check "$(sweep_files102 | xargs grep -lE 'could not price|saved unpriced|left unpriced' 2>/dev/null | grep -c .)|$(grep -lE 'could not price|saved unpriced|left unpriced' "$R102/zz-rh1-control.txt" | grep -c .)" "0|1" \
  "10f no terminal or log of any run of the 5.18.102 script calls an application one 5.18.102 \"could not price\" (RH-1); control: the old words are caught"
rm -f "$R102/zz-rh1-control.txt"
check "$(ls -A "$SB/web" | grep -cE '^\.(redirect102|left102m)$')|$(ls -A "$SB" | grep -cE '^\.(rbfail102|rbfail102\.seen|qorfail102)$')|$(cat "$R102"/plant-*.txt 2>/dev/null | sort | uniq -c | tr -s ' ' | paste -sd, -)|$(live)|$(left102)|$(set99 ucrm_quote_organization_id)|$(u99_state_102)" "0|0| 3 {\"rows\":3}|$P101|21|unset/unset|base" \
  "10g no trigger of this rehearsal's left in the stand-in or the fake docker; every plant at the question (6b, 6b2, 6e) was made — none noprompt or timeout; the sandbox left as 9g's rollback left it: 5.18.101 serving, the 21 added files on disk, the quoting organization's setting unset again, the stand-in uCRM at its base"
# The printed rollback, after the verdict and never in a block with another command (4d, 6) — the two readers those checks
# used, each shown able to fail: a summary that printed the rollback before its verdict, and one line holding two scripts.
printf '%s\n' "      $EXP_ROLLBACK102" "" "  5.18.102 (deploy): PASSED. Send this LOG FILE back" > "$SB/order102.txt"
printf '%s\n' "      $EXP_AFTER102; $EXP_ROLLBACK102" > "$SB/block102.txt"
check "$(awk '/^  5\.18\.102 \((deploy|after)\): / { v = 1 } index($0, "deploy-5.18.102.sh --rollback") { print (v ? "after" : "before") }' "$SB/order102.txt" | paste -sd, -)|$(grep -cE '(deploy-5\.18\.102\.sh|quarantine-stray-store\.sh|deploy-hybrid\.sh).*(deploy-5\.18\.102\.sh|quarantine-stray-store\.sh|deploy-hybrid\.sh)' "$SB/block102.txt")|$(grep -cxF "      $EXP_ROLLBACK102" "$SB/block102.txt")" \
      "before|1|0" \
  "control on the controls of 4d and 6: a rollback printed before the verdict reads \"before\"; a deploy and a rollback on one line are counted, and that line is not the rollback alone"
rm -f "$SB/order102.txt" "$SB/block102.txt"
check "$(left_tmp102)$(host_code102)$(ls -d /tmp/dnb-backup-* 2>/dev/null | grep -c .)$(find /tmp -maxdepth 6 -path '/tmp/dnb-5.18.102-*' -name token.json 2>/dev/null | grep -c .)$(cache102)" "00000" \
  "no run folder, code copy, vault, token copy, backup copy or page cache of this release's left in /tmp, in the data folder or on the host"
check "$(grep -c 'Fatal\|Parse error' "$SB/web.log")|$(grep -c 'Fatal\|Parse error' "$U99/server.log")" "0|0" "the stand-in plugin and the stand-in uCRM served every request without a PHP fatal"
check "$(checkout_state)" "$CHECKOUT0" "this checkout is as the rehearsal found it: same commit, same tracked files"

echo
echo "RESULT: $PASS ok, $FAILN failed  (the script: $WHICH102)"
[ "$FAILN" = "0" ] || echo "  $FAIL_PRE102 of the $FAILN failed in the earlier releases' rehearsals, before this release's own sections. Their scripts are records, left as they are; a refusal of theirs at a check read off a pipeline whose reader exits early is a SIGPIPE false stop, not a defect of 5.18.102's. Read the first FAIL; if it is one, run the rehearsal again — it is never a pass."
[ "$FAILN" = "0" ]
