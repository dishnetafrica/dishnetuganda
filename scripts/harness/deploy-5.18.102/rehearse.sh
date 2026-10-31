#!/usr/bin/env bash
#
# Rehearse scripts/deploy-5.18.102.sh — on Uganda the plugin's KYC quotations take their lines and prices from the uCRM
# Products and Service Plans an admin LINKS to each plan, kit and the installation on the Plans and Hardware screens
# (lib/UcrmQuoteLinks.php, lib/UcrmQuoteLines.php; root docs/71, D1–D14; matched by link, never by spelling) — over the
# live release, 5.18.101, before the operator runs it. The script under rehearsal is the D14 derivation (docs/71 §R.7.8),
# pinned to the re-cut release commit.
#
# The sandbox is the previous release's rehearsal's, built by its own code: scripts/harness/deploy-5.18.101/rehearse.sh,
# read up to just before its own last section (what it left behind, its sweeps) — the cut asserted: its anchor exactly
# once, its last check the one named, and the one line that names its own folder pointed at that folder, also exactly
# once — and run here as it is, which builds every earlier sandbox the same way, from their own rehearsals. By the time
# this file's own sections begin, 5.18.101 has been deployed, rolled back and deployed again with --redeploy there, its
# copy moved half an hour back, its --after-only passed (R19 conclusive) and the quarantine's own --check passed after it:
# production's state on the day. The stand-in uCRM of 5.18.99's rehearsal learns, by an asserted insertion before its
# final 404, the reads 5.18.102's checks make — GET products?limit, service-plans?limit (the catalogue's two lists, and
# the organization field's raw reads of the same two, D14 derivation M-8), custom-attributes and billing/quotes/{id} —
# two answers below 400 the release must refuse (500 Products, an HTML page), and its base state a catalogue in which an
# admin COULD link every plan and kit this rehearsal seeds in the store (through the installed plugin's own writer,
# rh102-seed.php) and "Professional Installation". Links are made only as an admin makes them: through the release's own
# UcrmQuoteLinks::set() and ::end() (rh102-links.php, D14 derivation M-18) — the installed code's once 5.18.102 serves,
# the pin's from Git for a link a rolled-back run left — and, for a state that writer refuses or never makes, through its
# raw planter. The fake docker learns, by an asserted hook, three triggers armed by this rehearsal's files: a read of the
# container's record answered wrong once after the deploy's own rollback copied (6w); the folder of the quoting reading
# after the copy refused once (8); and, at that same folder's making — after stage B, before qo_after's copy of the book
# — links written into the live store, as an admin could while the deploy runs (MD2, MD13). The stand-in dashboard learns
# a trigger that breaks its sign-in gate (MD4).
#
# This rehearsal proves:
#   · the script is pinned to the release commit on release/5.18.102 (short and full), cut on 5.18.101; the delta is
#     exactly the 31 added and 38 changed reviewed files, each file's sha256 as the script pins it, computed here from Git;
#     the 55 kept files by theirs in both commits, the 38 changed ones by their previous form; no migration; T1 carried;
#     the header's two commands between its two marker lines, each alone, neither a rollback; QL-RB1's and QL-RB3's
#     markers counted in Git as the script pins them at the release and absent at 5.18.101; the sign-off reader QS-G1
#     carries, with its control on 5.18.100 (0);
#   · the seeded catalogue: the RELEASE's own UcrmQuoteLines and UcrmQuoteLinks, run here on the store's offered rows
#     against the stand-in, judge every item "not linked" while nothing is linked, and offer something an admin could
#     link for every kind (its own candidate()); on a scratch copy of the book, linked through the pin's own set(), every
#     item resolves, and one product priced 0 makes exactly that item broken (pre);
#   · --check is GO: QL-G1 ok with the counts and candidates the release's resolver gives, every item "not linked" said as
#     the expected reading before the copy, never a refusal (D14 §R.7.1); the notes QL-N1–QL-N7 as seeded — QL-N3's D11
#     count as this rehearsal's own reading of the release's ENTRY rule gives it (E-1) — the stand-in asked only GETs of
#     the two lists, twice per client (the catalogue, then the organization field's raw reads, counted apart), with
#     limit=500; the names, and the hint section "uCRM items with the same spelling", on the 0600 sheet and nowhere else;
#     nothing changed (c);
#   · QL-G1's NO-GO now refuses a KIND uCRM offers nothing an admin could link for — plans, and hardware with the
#     installation — said client by client, the deploy-with-flag command printed alone, its weakened copy caught; with
#     --accept-unpriced GO and its note in the flag's new meaning; an item unpriced before the copy is no longer refused;
#     an unreadable catalogue (products, service plans, the token client) a NO-GO the flag does not lift, its weakened
#     copies caught; the toggles read as notes; QL-N3's D11 count by the entry rule over planted applications, its copy by
#     the installation answer caught (MD11); the organization field's raw reads made inside the counted window caught by
#     QL-G1's own path check (MD9); every refusal changes nothing (1);
#   · the deploy, DEPLOY typed, PASSES: nothing linked right after the copy, QL-CA1 equal to stage A's item by item under
#     this run's QL key, the items still to link counted (step 7); QL-CA3: the Plans and Hardware screens, their pickers and
#     the Orders card refuse a visitor with no session, and the installed post_sync.php guards its three actions; the
#     state file holds the per-item and per-record hashes and APP_MAX — never the key, which is in its own 0600 file; F's
#     summary as D14 says it — "your steps" 2, 4, 7, 8, the agents, the window — and the printed rollback's block with the
#     links' sentences (4);
#   · --after-only before anything is linked FAILS QL-LK1 by design, the --after-only --accept-unpriced command printed
#     alone; with it, PASSES with the flag's note; linked by an admin through the installed release's own writer, it
#     PASSES QL-LK1 with each note counted — whose accounts, made since the copy by identity, stray links, shared products,
#     records ended since the copy, the window's Make-the-quotation count by QLG1_APP_MAX; the window's count by
#     submitted_at caught (MD8) and its count over app-origin or found registrations caught (MD10); rule 8 between a kit
#     and the installation reported broken, and the judge given no kit rows caught (MD14); a product priced 0 and an
#     unreadable uCRM FAIL QL-LK1, the latter QL-CA1 too; the dashboard's sign-in gate broken FAILS QL-CA3 beside V12, BA4,
#     CA4 and Q-CA5 (MD4); requireAdmin taken out of the installed post_sync.php FAILS QL-CA3 beside R1 and Q-CA1 (MD5)
#     (5);
#   · the rollback: QL-RB2 as built (refused at the question and at stage A, its flag), QL-RB3 — the link store left as
#     it was, counted, nothing of 5.18.101 naming it, the unconfirmed applications counted — QL-RB1 and QL-RB3 made to fail
#     by planted files, their weakened copies caught; the 31 added files on disk (6); a deploy whose public sign-in page
#     redirects rolls itself back (6v, 6w), as built;
#   · a link store a rolled-back run left (6L): read at stage A through the PIN's class and judged at QL-G1 — never
#     through ICODE_A (MD7) — with the row stamps carried (MD6: the row unchanged, and the row replaced by a reused id) and
#     the kit rows in their stored shape (MD12: the installation and an offered kit on one product judged by rule 8);
#   · --redeploy over those leftovers, with links written while it runs (7): a plan linked since stage A left out and noted,
#     never a FAIL (MD2's strict comparison caught), a kit relinked to the installation's product leaving the installation
#     out by rule 8 (MD13's comparison without the closure caught), the unchanged leftovers compared and never "linked since
#     stage A"; its --after-only judges links made since the copy by identity, in UTC and in Etc/GMT-5, with no disagreement
#     note (MD3: the copy that reads linked_at caught, M-11);
#   · the quoting reading after the copy refused: F keeps stage A's narrative; QL-G1 decided by --accept-unpriced, said in
#     F; the summary's gate weakened, caught (8); the quoting organization off: nothing read, F's own words (9);
#   · nothing reaches Evolution; across every run the copy observers were quiet, every token copy gone, the stand-in given
#     GETs only, the live vault as it was; no seeded name, link record name, price, stand-in token, run key or QL key in
#     any output, log or state file; no output of the script under rehearsal keeps a superseded remedy — "row's name in
#     the plugin", swept over terminals and logs only (never the sheets), its weakened copy caught (MD1); no trigger left
#     and every plant made; each check with its control on the control (10).
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
grep -qF 'UcrmQuoteLinks::fromRecords($qlOffer["links"], $qlOffer["kits"])' "$H102_ROOT/$F102" || { echo "refusing: $F102 is not the D14 derivation (no UcrmQuoteLinks::fromRecords in its remote reading) — this rehearsal is written for it"; exit 2; }
for f102 in rh102-seed.php rh102-ref.php rh102-links.php rh102q.py; do
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
ADDED102="lib/UcrmQuoteLines.php lib/UcrmQuoteLinks.php tests/quote_from_ucrm/A_resolver.php tests/quote_from_ucrm/B_submission.php tests/quote_from_ucrm/C_retries.php tests/quote_from_ucrm/D_prepared_by.php tests/quote_from_ucrm/E_readback.php tests/quote_from_ucrm/F_send_quote.php tests/quote_from_ucrm/G_documents_screens.php tests/quote_from_ucrm/H_kyc_form.php tests/quote_from_ucrm/I_boundary.php tests/quote_from_ucrm/L_links.php tests/quote_from_ucrm/_lib.php tests/quote_from_ucrm/c_cases.php tests/quote_from_ucrm/c_lib.php tests/quote_from_ucrm/c_proxy.php tests/quote_from_ucrm/c_router.php tests/quote_from_ucrm/f_lib.php tests/quote_from_ucrm/g_api_router.php tests/quote_from_ucrm/h_kyc_form_browser.js tests/quote_from_ucrm/h_router.php tests/quote_from_ucrm/l_lib.php tests/quote_from_ucrm/l_run.php tests/quote_from_ucrm/mutants.php tests/quote_from_ucrm/mutants_C.php tests/quote_from_ucrm/mutants_F.php tests/quote_from_ucrm/mutants_G.php tests/quote_from_ucrm/mutants_H.php tests/quote_from_ucrm/mutants_I.php tests/quote_from_ucrm/mutants_L.php tests/test_quote_from_ucrm.php"
CHANGED102="SAFETY.md includes/api/api_cron_debug.php includes/api/api_products_admin.php includes/post/post_sync.php lib/CrmApiClient.php lib/DeliveryPdfService.php lib/KycCrmSync.php lib/KycService.php lib/NotificationService.php lib/PluginQuotePdf.php lib/QuotationService.php lib/UcrmClientTarget.php manifest.json tabs/admin/settings.php tabs/sales/applications.php tabs/sales/hardware.php tabs/sales/kyc_form.php tabs/sales/product_mapping.php tabs/sales/send_quote.php tabs/sales/subscription_plans.php tests/fixtures/fake_ucrm_kyc.php tests/fixtures/fake_ucrm_quote_org.php tests/fixtures/quote_company_journey.php tests/test_bank_boundaries.php tests/test_cashbook_physical_list.php tests/test_distributor_apply.php tests/test_distributor_link_ucrm.php tests/test_distributor_notify.php tests/test_distributor_registry.php tests/test_distributor_territory.php tests/test_field_cash_in_currency.php tests/test_kyc_crm_messages.php tests/test_notify_kyc_quote_send.php tests/test_notify_kyc_race.php tests/test_quote_company.php tests/test_quote_company_mutants.php tests/test_quote_summary.php tests/test_staff_manual_entry_currency.php"
NA102=$(wc -w <<<"$ADDED102"); NC102=$(wc -w <<<"$CHANGED102"); N102=$((NA102 + NC102))   # 31, 38, 69 (D14: lib/UcrmQuoteLinks.php and nine more test files added)
if git -C "$R" ls-files --error-unmatch "$F102" >/dev/null 2>&1; then
  cmp -s "$R/$F102" "$DEPLOY102" || { echo "refusing: $F102 differs from the committed one — commit it, then rehearse"; exit 2; }
  WHICH102="the committed script"
else cp "$R/$F102" "$DEPLOY102"; WHICH102="the working copy (not yet committed; untracked in the clone)"; fi
git -C "$REPO" fetch -q origin "refs/heads/$REL102:refs/remotes/origin/$REL102" 2>/dev/null || true
P102="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT="\([^"]*\)".*/\1/p' "$DEPLOY102")"
P102F="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT_FULL="\([^"]*\)".*/\1/p' "$DEPLOY102")"
OUT102="$SB/out102"; mkdir -p "$OUT102"; STATE102="$OUT102/state-5.18.102.env"
R102="$SB/r102"; mkdir -p "$R102/logs" "$R102/mutants" "$R102/sheets" "$R102/exec" "$R102/scan" "$R102/vault" "$R102/tok" "$R102/ucrm"
H102="$SB/rh102"; mkdir -p "$H102/v"; cp "$H102_DIR"/rh102-seed.php "$H102_DIR"/rh102-ref.php "$H102_DIR"/rh102-links.php "$H102_DIR"/rh102q.py "$H102/"
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
states102() { ls -1 "$OUT102"/state-5.18.102.env* "$OUT102"/rollback-state-* "$OUT102"/ql-key-* 2>/dev/null | sort | while IFS= read -r f; do printf '%s %s\n' "${f##*/}" "$(sha256sum "$f" | cut -c1-16)"; done | sha256sum | cut -c1-16; }
cache102() { { find "$DATA" "$PD" -maxdepth 2 -name ucrm_quote_catalogue_cache.json 2>/dev/null; find /tmp -maxdepth 6 -path '/tmp/dnb-5.18.102-*' -name ucrm_quote_catalogue_cache.json 2>/dev/null; } | grep -c .; }   # UcrmQuoteLines' page cache, anywhere it could be written
fresh102() {   # the run folder as a new run finds it: the state files, the QL key file beside them (D14 derivation M-1: an
               # --after-only compares under the deploy's key) and the row digests kept; every log and sheet moved aside
  find "$OUT102" -mindepth 1 -maxdepth 1 -name '*.log' -exec mv -t "$R102/logs/" {} + 2>/dev/null
  find "$OUT102" -mindepth 1 -maxdepth 1 -name 'cash-*.txt' -exec mv -t "$R102/sheets/" {} + 2>/dev/null
  find "$OUT102" -mindepth 1 -maxdepth 1 ! -name 'state-5.18.102.env*' ! -name 'rollback-state-*' ! -name 'ql-key-*' ! -name 'cih-rows-*' -exec rm -rf {} +; base_log; }
installed102() {   # installed102 COMMIT — the number of files 5.18.101 → 5.18.102 touches that are NOT installed as COMMIT has them
                   # (a file COMMIT does not hold is not asked about: 5.18.101 holds none of the 31 added ones)
  local f rel bad=0
  for f in $(dl AM "$P101" "$P102"); do
    rel="${f#$P/}"
    if git -C "$REPO" cat-file -e "$1:$f" 2>/dev/null; then
      [ "$(git -C "$REPO" show "$1:$f" | sha256sum | cut -c1-64)" = "$(sha256sum "$PD/$rel" 2>/dev/null | cut -c1-64)" ] || bad=$((bad+1))
    fi
  done
  echo "$bad"
}
left102() { local f n=0; for f in $ADDED102; do [ -e "$PD/$f" ] && n=$((n+1)); done; echo "$n"; }   # the 31 added files on disk
reach102() {   # the installed files, the 31 added ones aside, that name UcrmQuoteLines, the link class or its store (D14) or carry
               # 5.18.102's two refusals — 0 after a rollback (this rehearsal's own reading beside QL-RB1's and QL-RB3's)
  ( cd "$PD" && grep -rlF -e 'UcrmQuoteLines' -e 'UcrmQuoteLinks' -e 'ucrm_quote_links' -e 'prices are set in uCRM Products' -e 'no Delivery Acknowledgment PDF on Uganda' --include='*.php' . 2>/dev/null ) \
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
  if [ -n "${R102_OUT_DIR:-}" ]; then printf '%s\n' "$o" > "$R102_OUT_DIR/run-$tag.txt"
  elif [ -n "${R102_MUTANT_OUT:-}" ]; then printf '%s\n' "$o" > "$R102/mutants/run-$tag.txt"; else printf '%s\n' "$o" > "$R102/run-$tag.txt"; fi
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
ADMIN102=""   # the account the link writer acts as: an active admin of the sandbox's book (set in 102-pre)
links102() {   # links102 ROOT OPS-JSON — the uCRM links an admin makes, through the code root's own UcrmQuoteLinks (rh102-links.php):
               # ROOT the installed plugin ($PD) once 5.18.102 serves, or the pin's lib from Git (REF102) for a link a rolled-back
               # run left; written in Africa/Kampala, as the server's plugin stamps linked_at; as the store's user, with a vault
               # of its own. {"ops":…,"kinds":[…],"ids":[…],"records":…,"active":…,"two":…}, or none(<why>)
  local o; o="$(cd "$H102/v" && setpriv --reuid 1000 --regid 1000 --clear-groups -- env DN_VAULT_FILE="$H102/v/link-vault.json" "$PHPBIN" "$H102/rh102-links.php" "$1" "$DATA" "$2" Africa/Kampala 2>"$H102/v/link.err")"
  rm -f "$H102/v/link-vault.json"
  case "$o" in '{"ops":'*) printf '%s' "$o" ;; *) echo "none($(head -c 160 "$H102/v/link.err"))" ;; esac
}
plant102() {   # plant102 ROOT OPS-JSON — arms the fake docker's .qorplant102: at the making of the quoting reading's folder after
               # the copy (after stage B, before qo_after's copy of the book) the same writer runs once, its answer kept in
               # $SB/plant102.out — a link written while the deploy runs, as an admin could (MD2, MD13)
  printf '#!/usr/bin/env bash\ncd %q && setpriv --reuid 1000 --regid 1000 --clear-groups -- env DN_VAULT_FILE=%q %q %q %q %q %q Africa/Kampala; rm -f %q\n' \
    "$H102/v" "$H102/v/plant-vault.json" "$PHPBIN" "$H102/rh102-links.php" "$1" "$DATA" "$2" "$H102/v/plant-vault.json" > "$SB/plant102.sh"
  rm -f "$SB/plant102.out"; : > "$SB/.qorplant102"
}
lk102() { sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1; python3 "$H102/rh102q.py" lk "$DATA/plugin.sqlite3" ${1:+"$1"}; }   # lk102 [COPYAT] — the link store, read-only
win102() { sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1; python3 "$H102/rh102q.py" win "$DATA/plugin.sqlite3" "$1"; }   # win102 APPMAX — QL-LK1's window, by the release's rule
unconf102() { sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1; python3 "$H102/rh102q.py" unconf "$DATA/plugin.sqlite3"; }   # QL-RB3's §R.17.3 count
appmax102() { sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1; python3 "$H102/rh102q.py" appmax "$DATA/plugin.sqlite3"; }   # the highest application id
keychk102() { python3 "$H102/rh102q.py" keychk "$OUT102/ql-key-5.18.102" "$OUT102"/state-5.18.102.env* "$OUT102"/rollback-state-* 2>/dev/null; }   # the QL key file's mode and shape, and the key absent from every state file
ANCH102="$SB/anchors102.tsv"
cat > "$ANCH102" <<'ANCH'
qlg1c	deploy	      else stop "NO-GO (QL-G1): uCRM offers nothing an admin could link for ${why#; }	      else : "NO-GO (QL-G1): uCRM offers nothing an admin could link for ${why#; }
qlg1rp	deploy	      [ "$(ql_n "$p" unread)" = "0" ] || stop "NO-GO (QL-G1): uCRM's Products and Service Plans could not be read through the plugin's own client	      true || stop "NO-GO (QL-G1): uCRM's Products and Service Plans could not be read through the plugin's own client
qlg1rt	deploy	      [ "$t" = "unset" ] || [ "$(ql_n "$t" unread)" = "0" ] || stop "NO-GO (QL-G1): uCRM's Products and Service Plans could not be read through the token client	      true || stop "NO-GO (QL-G1): uCRM's Products and Service Plans could not be read through the token client
qlca1	deploy	        if [ -n "$x" ]; then bad "QL-CA1 the catalogue through the INSTALLED resolver:${x%;} — each item by name on the sheet"	        if [ -n "$x" ]; then ok "QL-CA1 the catalogue through the INSTALLED resolver:${x%;} — each item by name on the sheet"
qllk1	deploy	  else bad "QL-LK1 not every item the KYC form offers resolves through the INSTALLED resolver by its uCRM link:	  else note "QL-LK1 not every item the KYC form offers resolves through the INSTALLED resolver by its uCRM link:
qlrb2	deploy	    else stop "NO-GO (QL-RB2): ${at}$QL_UNP_PENDING application(s) still waiting for uCRM with no plan price recorded	    else : "NO-GO (QL-RB2): ${at}$QL_UNP_PENDING application(s) still waiting for uCRM with no plan price recorded
qlrb2again	deploy	    qlrb_judge "counted again as ROLLBACK was typed, right before the copy: "	    stop "REHEARSAL-102: the copy would begin here — no count was made as the answer was typed"
qlrb1	deploy	  [ "$qr_in" = "0" ] || QLRB1_BAD="$QLRB1_BAD $qr_rel:names-it×${qr_in:-?}"	  true || QLRB1_BAD="$QLRB1_BAD $qr_rel:names-it×${qr_in:-?}"
qlrb3m	deploy	  [ "$qr3_in" = "0" ] || QLRB3_BAD="$QLRB3_BAD $qr3_rel:names-$qr3_mark×${qr3_in:-?}"	  true || QLRB3_BAD="$QLRB3_BAD $qr3_rel:names-$qr3_mark×${qr3_in:-?}"
qlrb3s	deploy	        [ "$qr3_in" = "0" ] || { QLRB3_SW_HIT=$((QLRB3_SW_HIT + 1)); [ "$QLRB3_SW_HIT" -gt 5 ] || QLRB3_BAD="$QLRB3_BAD $qr3_rel:names-$qr3_mark×${qr3_in:-?}"; }	        true || { QLRB3_SW_HIT=$((QLRB3_SW_HIT + 1)); [ "$QLRB3_SW_HIT" -gt 5 ] || QLRB3_BAD="$QLRB3_BAD $qr3_rel:names-$qr3_mark×${qr3_in:-?}"; }
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
md1	deploy	a broken one's own reason, on the sheet, says its remedy — in uCRM, or linking it again"	a broken one's own reason, on the sheet, says its remedy — fix it in uCRM's Products and Service Plans, or in the row's name in the plugin, as docs/71 §M step 3 says"
md2	deploy	                            if (isset($lh[$hi])) continue;	                            if (false) continue;
md3	deploy	$ts = $qlTs($r["linked_at_ts"] ?? null); $dis = false;	$ts = strtotime((string)($r["linked_at"] ?? "")); if ($ts === false) $ts = null; $dis = false;
md6	deploy	foreach ($qlRecs as $r) $offer["links"][] = $ql_keep($r, ["id", "item", "row_id", "row_stamp", "product_id", "service_plan_id", "organization_id", "linked_at_ts", "ended_at_ts"]);	foreach ($qlRecs as $r) $offer["links"][] = $ql_keep($r, ["id", "item", "row_id", "product_id", "service_plan_id", "organization_id", "linked_at_ts", "ended_at_ts"]);
md7	deploy	    a) la+=("links=$pin/lib/UcrmQuoteLinks.php" "salt=$QL_KEY" "recs=$sub/recs.txt" "appmaxout=$sub/appmax.txt") ;;	    a) la+=("links=$sub/l/$PLUGIN/lib/UcrmQuoteLinks.php" "salt=$QL_KEY" "recs=$sub/recs.txt" "appmaxout=$sub/appmax.txt") ;;
md8a	deploy	$qst = $pdo->prepare("SELECT id, data FROM kyc_applications WHERE id > ? ORDER BY id"); $qst->execute([(int)$A["appmax"]]);	$qst = $pdo->prepare("SELECT id, data FROM kyc_applications WHERE id > ? ORDER BY id"); $qst->execute([0]);
md8b	deploy	$ap = json_decode((string)$x["data"], true); if (!is_array($ap)) continue; $i = (int)$x["id"];	$ap = json_decode((string)$x["data"], true); if (!is_array($ap)) continue; $i = (int)$x["id"]; if (strtotime((string)($ap["submitted_at"] ?? "")) < (int)($A["copyat"] ?? 0)) continue;
md9	deploy	                        $asked = array_slice($GLOBALS["dnbP"], $n0);	                        if ($nm !== "dbg" && !empty($cat["ok"])) { try { $c->get("products?limit=500"); $c->get("service-plans?limit=500"); } catch (Throwable $x) { } } $asked = array_slice($GLOBALS["dnbP"], $n0);
md10	deploy	if (KycService::canMakeQuotation($ap)) $qlW["make"][] = $i;	if (!empty($ap["crm_client_id"]) && ($ap["quote_refused"] ?? null) === "P1" && empty($ap["quote_created"])) $qlW["make"][] = $i;
md11	deploy	if (!empty($r["quote_created"]) || ($r["quote_sale_entry"] ?? null) === "web") continue;	$ql_mk = ($r["quote_lines_source"] ?? null) === "ucrm-5.18.102" && is_array($r["quote_items"] ?? null) && $r["quote_items"] !== []; if ($ql_mk || !empty($r["quote_created"])) continue; $ql_ty = $r["customer_type"] ?? ""; $ql_ty = trim(is_scalar($ql_ty) ? (string)$ql_ty : ""); $ql_cn = $r["connectivity_type"] ?? "New Connection"; $ql_cn = trim(is_scalar($ql_cn) ? (string)$ql_cn : ""); $ql_by = is_string($r["install_by"] ?? null) ? $r["install_by"] : ""; if (strcasecmp($ql_ty, "Fiber") !== 0 && (strcasecmp($ql_ty, "StarLink") !== 0 || in_array($ql_cn, ["Shifting Connection", "Ownership Change"], true) || $ql_by === "dishnet" || $ql_by === "customer")) continue;
md12a	deploy	foreach ($ql_plans as $r) if (!empty($r["is_active"])) $offer["plans"][] = $ql_keep($r, ["id", "name", "type", "is_active", "created_at", "imported_at"]);	foreach ($ql_plans as $r) if (!empty($r["is_active"])) { $ty = $r["type"] ?? "starlink"; $ty = is_scalar($ty) ? (string)$ty : ""; $offer["plans"][] = ["id" => is_scalar($r["id"] ?? null) ? (string)$r["id"] : "", "name" => is_scalar($r["name"] ?? null) ? (string)$r["name"] : "", "fiber" => strtolower($ty) === "fiber"]; }
md12b	deploy	foreach ($ql_kits as $r) if (!empty($r["is_active"])) $offer["kits"][] = $ql_keep($r, ["id", "title", "type", "is_active", "created_at", "imported_at"]);	foreach ($ql_kits as $r) if (!empty($r["is_active"])) { $ty = $r["type"] ?? "general"; $ty = is_scalar($ty) ? (string)$ty : ""; $offer["kits"][] = ["id" => is_scalar($r["id"] ?? null) ? (string)$r["id"] : "", "name" => is_scalar($r["title"] ?? null) ? (string)$r["title"] : "", "fiber" => strtolower($ty) === "fiber"]; }
md13	deploy	                        if ($r8) {	                        if (false) {
md14	deploy	$qlLinks = UcrmQuoteLinks::fromRecords($qlOffer["links"], $qlOffer["kits"]);	$qlLinks = UcrmQuoteLinks::fromRecords($qlOffer["links"], []);
ss1	deploy	if [ -n "$ACCEPT_UNPRICED" ]; then ACCEPT_UNPRICED=""; note "	if false; then ACCEPT_UNPRICED=""; note "
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
# Three triggers of this release's own, in the fake docker's prelude beside 5.18.96's and 5.18.99's hooks (inserted, as theirs
# were, before its row() line — asserted), each armed only by a file of this rehearsal's and spent once:
#   .rbfail102 — the first read of the container's record (exec ucrm cat …/.deployed-commit) that names 5.18.101 after one
#     that named 5.18.102 answers another commit: deploy-hybrid.sh, run by the deploy's own rollback after V1, sees the
#     copy land and its record read back wrong, and exits 1 — the rollback does not verify, while 5.18.101's files are in
#     place and the next read names it (102-6w);
#   .qorfail102 — the first folder the quoting checks make for their reading after the copy (qo_run r: mkdir -m 700
#     …/qo-r-N) refused: that reading stops before its gate probe, and stage A's (qo-a-N) is never touched (102-8);
#   .qorplant102 — at that same folder's making (after stage B, before qo_after's copy of the book), $SB/plant102.sh run
#     once — rh102-links.php writing into the live store through the installed code, as the store's user: a link an admin
#     makes while the deploy runs (D14 §R.7.2; MD2, MD13). The mkdir then goes ahead: the reading copies the book after it.
cat > "$SB/docker102.hook" <<'HOOK'
# ── the next release's rehearsal (5.18.102): three triggers, each armed by a file of the rehearsal's, each spent once ──
if [ -f "@SB@/.rbfail102" ] && [ "$*" = "exec ucrm cat /data/ucrm/data/plugins/dishnet-hybrid-sudan/.deployed-commit" ]; then
  _rh_r="$(tail -n1 "@PD@/.deployed-commit" 2>/dev/null | tr -cd '0-9a-f')"
  if [ "$_rh_r" = "@P102@" ]; then : > "@SB@/.rbfail102.seen"
  elif [ "$_rh_r" = "@P101@" ] && [ -f "@SB@/.rbfail102.seen" ]; then rm -f "@SB@/.rbfail102" "@SB@/.rbfail102.seen"; echo 0000000; exit 0; fi
fi
if [ -f "@SB@/.qorfail102" ]; then
  case "$*" in *" mkdir -m 700 /tmp/dnb-5.18.102-cih-"*"/qo-r-"*) rm -f "@SB@/.qorfail102"; echo "mkdir: cannot create directory: refused by the rehearsal (102-8)" >&2; exit 1 ;; esac
fi
if [ -f "@SB@/.qorplant102" ]; then
  case "$*" in *" mkdir -m 700 /tmp/dnb-5.18.102-cih-"*"/qo-r-"*) rm -f "@SB@/.qorplant102"; bash "@SB@/plant102.sh" > "@SB@/plant102.out" 2>&1 ;; esac
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
assert s.count('.rbfail102') == 0 and s.count('.qorfail102') == 0 and s.count('.qorplant102') == 0 and s.count('FAKE_TOK_HITS') == 3
lines[at[0]:at[0]] = h.rstrip('\n').split('\n')
s2 = '\n'.join(lines)
assert s2.count('.rbfail102') == 5 and s2.count('.qorfail102') == 2 and s2.count('.qorplant102') == 2 and s2.count('plant102.sh') == 1 and s2.count('/qo-r-') == 2 and s2.count('FAKE_TOK_HITS') == 3
assert s2.index('FAKE_TOK_HITS') < s2.index('.rbfail102') < s2.index('row() { grep "^$1|" ')
open(p, 'w').write(s2)
PY
rm -f "$SB/docker102.hook"
check "$(bash -n "$SB/bin/docker" && echo parses)|$(python3 -c 'import ast, sys; [ast.parse(open(f).read()) for f in sys.argv[1:]]; print("parses")' "$H102/rh102.py" "$H102/rh102q.py" 2>&1)|$(for f in "$H102"/rh102-*.php; do php -l "$f" 2>&1 | grep -c '^No syntax errors'; done | tr -d '\n')|$(grep -c '5\.18\.101-' "$H102/rh102.py")" \
      "parses|parses|111|0" \
  "control: the fake docker with this release's folders, this rehearsal's readers (5.18.101's derived, naming none of its folders, and its own) and its three PHP helpers (seed, ref, links) parse"
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
printf '#!/usr/bin/env bash\necho planted-by-hand\n' > "$SB/plant102.sh"; rm -f "$SB/plant102.out"; : > "$SB/.qorplant102"
dk102 exec -u 1000:1000 ucrm true mkdir -m 700 /tmp/dnb-5.18.102-cih-rhctl/qo-a-1; HK8="$?:$( [ -f "$SB/.qorplant102" ] && echo kept || echo spent):$(cat "$SB/plant102.out" 2>/dev/null)"
dk102 exec -u 1000:1000 ucrm true mkdir -m 700 /tmp/dnb-5.18.102-cih-rhctl/qo-r-1; HK9="$?:$( [ -f "$SB/.qorplant102" ] && echo kept || echo spent):$(cat "$SB/plant102.out" 2>/dev/null)"
rm -f "$SB/plant102.sh" "$SB/plant102.out"
check "$HK1|$HK2|$HK3|$HK4|$HK5|$(ls -A "$SB" | grep -cE '^\.(rbfail102|rbfail102\.seen|qorfail102|qorplant102)$')|$HK6|$HK7|$HK8|$HK9|$(cmp -s "$SB/dc102.kept" "$PD/.deployed-commit" && [ "$(stat -c %Y "$SB/dc102.kept")" = "$(stat -c %Y "$PD/.deployed-commit")" ] && echo same)" \
      "$P101|$P101|$P102:seen|0000000|$P101|0|0:kept|1:spent|0:kept:|0:spent:planted-by-hand|same" \
  "control: the fake docker's three triggers — the record read truly, unarmed and armed alike, until 5.18.102's has been seen; then another commit once, and the truth again; stage A's folder made, the reading after the copy's refused once; the plant run at the reading after the copy's folder once and never at stage A's, its answer kept, the mkdir going ahead; all spent, the record as it was"
rm -f "$SB/dc102.kept"
# The sign-off reader and the owner-rule reader are 5.18.101's rehearsal's (qs_read101, tn_read101 SCRIPT COMMIT): each
# takes the reader from the script it is given, between that script's own anchors — here 5.18.102's.
n3say102() {   # n3say102 CELLS STATUS — QL-N3's words for one status, from this rehearsal's own cells (rh102q.py apps)
  printf '%s' "$1" | tr ',' '\n' | awk -F'[.:]' -v s="$2" '$1 == s { t += $5; m[$2] += $5; l[$3] += $5; c[$4] += $5 }
      END { printf "%d (marked by 5.18.102 %d, not %d; lines with a productId %d, without one %d, none %d; Cash %d, Credit %d)", t, m["marked"], m["unmarked"], l["pid"], l["nopid"], l["none"], c["cash"], c["credit"] }'
}
d11say102() {   # d11say102 D11 — QL-N3's D11 sentence (the script's words, ql_d11_say), from this rehearsal's own count (rh102q.py
                # apps' d11: pending:<n>,review:<n>,p1:<n>,p2:<n>, the release's ENTRY rule written out there — D14 E-1)
  local p r a b
  p="$(sed -nE 's/.*(^|,)pending:([0-9]+).*/\2/p' <<<"$1")"; r="$(sed -nE 's/.*(^|,)review:([0-9]+).*/\2/p' <<<"$1")"
  a="$(sed -nE 's/.*(^|,)p1:([0-9]+).*/\2/p' <<<"$1")"; b="$(sed -nE 's/.*(^|,)p2:([0-9]+).*/\2/p' <<<"$1")"
  [ -n "$p" ] && [ -n "$r" ] && [ -n "$a" ] && [ -n "$b" ] || { printf 'd11say102: no count in %s' "${1:-nothing}"; return 0; }
  printf 'Whatever uCRM holds and whatever an admin links, the retry makes no quotation for %s of them (D11, docs/71 §F.4; E-1): every one with no quotation made — pending %s, review %s; P1, Credit or no cash recorded, %s; P2, Cash with cash collected, %s%s' \
    "$((p + r))" "$p" "$r" "$a" "$b" "$( [ $((p + r)) = 0 ] || echo " — each one's quotation is made by hand in uCRM, or not at all (docs/71 §F.4, §F.5); its Orders card draws no Make the quotation")"
}
d11n102() {   # d11n102 D11 — F's own clause for the same count (F_D11N): "; QL-N3 counted N such waiting at stage A — P1 a, P2 b"
  local p r a b
  p="$(sed -nE 's/.*(^|,)pending:([0-9]+).*/\2/p' <<<"$1")"; r="$(sed -nE 's/.*(^|,)review:([0-9]+).*/\2/p' <<<"$1")"
  a="$(sed -nE 's/.*(^|,)p1:([0-9]+).*/\2/p' <<<"$1")"; b="$(sed -nE 's/.*(^|,)p2:([0-9]+).*/\2/p' <<<"$1")"
  printf '; QL-N3 counted %s such waiting at stage A — P1 %s, P2 %s' "$((p + r))" "$a" "$b"
}

echo; echo "== 102-pre. the sandbox as production is: 5.18.101 serving; the catalogue in the stand-in uCRM and the KYC form's rows in the store =="
check "$(live)|$(inst_ver)|$(installed102 "$P101")|$(left102)" "$P101|5.18.101|0|0" "pre-a 5.18.101 serving, every file 5.18.102 changes as 5.18.101 has it (101-7's --redeploy), none of the 31 it adds on disk"
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
# retailer-app package active, one inactive; three applications for QL-N3 and QL-N4. Every plan and kit carries a
# created_at — the stamp a link is recorded with (UcrmQuoteLinks::rowStamp), so that a row replaced under a reused id is
# told apart (MD6) — and the kits are added so that the highest id is an offered one (Router-Mesh, #3: 102-6L deletes it
# and adds it again, the id coming back). Nothing is linked: 5.18.101 has no link screen. Names to the sweeps' files,
# never printed; the names the link writer records (RH102QSECRET-linkname-/linkrow-/admin) reach nothing: the script never reads them.
# ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
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
        {'_rh102': True, 'name': N['Plan-Alpha'], 'type': 'starlink', 'is_active': True, 'customer_price': 777777.75, 'created_at': 'rel:-40 days', 'updated_at': 'rel:-3 days'},
        {'_rh102': True, 'name': N['Plan-Beta'], 'is_active': True, 'customer_price': 666666.65, 'created_at': 'rel:-41 days', 'updated_at': 'rel:-6 days'},
        {'_rh102': True, 'name': N['Fiber-Delta'], 'type': 'fiber', 'is_active': True, 'created_at': 'rel:-42 days', 'updated_at': 'rel:-9 days'},
        {'_rh102': True, 'name': N['Plan-Gamma'], 'is_active': False, 'created_at': 'rel:-43 days', 'updated_at': 'rel:-1 day'}],
    'kyc_devices.json': [
        {'_rh102': True, 'title': N['Kit-Standard'], 'is_active': True, 'price': 555555.55, 'created_at': 'rel:-10 days'},
        {'_rh102': True, 'title': N['Kit-Old'], 'is_active': False, 'created_at': 'rel:-2 days'},
        {'_rh102': True, 'title': N['Router-Mesh'], 'is_active': True, 'created_at': 'rel:-20 days'}],
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
w(nowhere, ''.join(v + '\n' for v in [N['Plan-Gamma'], N['Kit-Old'], N['Package-Sigma'], Z] + Q + ['RH102QSECRET-filler-']))   # the 500 Products of products_n500 (1j), never read into a verdict
w(amounts, ''.join('%.2f\n' % a for a in (418273.55, 529384.65, 1634275.45, 347281.35, 152637.85, 111111.11, 222222.22, 333333.33, 1254363.75,
                                          6352615.85, 99999.95, 313131.35, 212121.25, 121212.15, 777777.75, 666666.65, 555555.55, 444444.45,
                                          287654.35)))   # the last: the cash of 1m's Cash sale with cash collected (D11, P2)
PY
printf '%s\n' RH102QSECRET-linkname- RH102QSECRET-linkrow- RH102QSECRET-admin > "$SB/linknames102.pat"   # the names the link writer records: read by nothing of the script (SPEC §2.4), so in no terminal, log, state file or sheet
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
# ── The RELEASE's own resolver and link judge on the store's offered rows and the stand-in — not the script's probe: the
# reference the probe's counts are set against (c2), and the proof that the seeded catalogue resolves once linked. Its code
# from Git (lib and profiles), in a folder of this rehearsal's; the store read-only. Before the deploy nothing is linked:
# every item "not linked", and the release's own candidate() finds something an admin could link for every kind. Then a
# scratch copy of the book, linked through the PIN's own UcrmQuoteLinks::set() — each plan to its Product and Service
# Plan, each kit and the installation to its Product, as an admin will after the deploy: every item resolves; one Product
# priced 0, exactly that item is broken (bad). The live book is never linked here. ────────────────────────────────────
REF102="$H102/ref"; rm -rf "$REF102"; mkdir -p "$REF102"; git -C "$REPO" archive "$P102" "$P/lib" "$P/profiles" | tar -xf - -C "$REF102"
ref102() { sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1; RH102_TOKEN="$TOKEN99" "$PHPBIN" "$H102/rh102-ref.php" "$REF102/$P" "$PD" "${2:-$DATA/plugin.sqlite3}" "$1" 2>/dev/null; }
ADMIN102="$(sq "SELECT id FROM retailers WHERE COALESCE(json_extract(data, '\$.is_admin'), 0) IN (1, '1') AND COALESCE(json_extract(data, '\$.is_active'), 0) IN (1, '1') ORDER BY id LIMIT 1")"
LINKALL102='[{"op":"set","item":"plan","row":1,"product":9101,"plan":501,"actor":@A@},{"op":"set","item":"plan","row":2,"product":9102,"plan":502,"actor":@A@},{"op":"set","item":"kit","row":1,"product":9103,"actor":@A@},{"op":"set","item":"kit","row":3,"product":9104,"actor":@A@},{"op":"set","item":"install","product":9105,"actor":@A@}]'
LINKALL102="${LINKALL102//@A@/${ADMIN102:-0}}"   # every item the form offers linked as an admin links it: plan #1 Alpha to 9101 with 501, plan #2 Beta to 9102 with 502, kit #1 to 9103, kit #3 to 9104, the installation to 9105
REFA102="$(ref102 app)"; REFT102="$(ref102 token)"
SCR102="$H102/scratch"; rm -rf "$SCR102"; mkdir -p "$SCR102"; sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1
python3 -c 'import sqlite3, sys; s = sqlite3.connect("file:" + sys.argv[1] + "?mode=ro", uri=True); d = sqlite3.connect(sys.argv[2]); s.backup(d); d.close(); s.close()' "$DATA/plugin.sqlite3" "$SCR102/plugin.sqlite3"
chown -R 1000:1000 "$SCR102"
SCRL102="$(cd "$H102/v" && setpriv --reuid 1000 --regid 1000 --clear-groups -- env DN_VAULT_FILE="$H102/v/scr-vault.json" "$PHPBIN" "$H102/rh102-links.php" "$REF102/$P" "$SCR102" "$LINKALL102" Africa/Kampala 2>/dev/null)"; rm -f "$H102/v/scr-vault.json"
REFAL102="$(ref102 app "$SCR102/plugin.sqlite3")"; REFTL102="$(ref102 token "$SCR102/plugin.sqlite3")"
u99_set 'price_of={"9104":0}'; REFAZ102="$(ref102 app "$SCR102/plugin.sqlite3")"; REFTZ102="$(ref102 token "$SCR102/plugin.sqlite3")"; u99_set
REF_U="rows:5,ok:0,unpriced:5,unlinked:5,fiber:1|unlinked:5,two:0,replaced:0,org:0,gone:0,bad:0,rows200:0,twin:0,nomonth:0,planprice:0,differs:0,apart:0,likeplan:0,rule8:0,other:0|plans:2,hw:3"
REF_L="rows:5,ok:5,unpriced:0,unlinked:0,fiber:1|unlinked:0,two:0,replaced:0,org:0,gone:0,bad:0,rows200:0,twin:0,nomonth:0,planprice:0,differs:0,apart:0,likeplan:0,rule8:0,other:0|plans:2,hw:3"
REF_Z="rows:5,ok:4,unpriced:1,unlinked:0,fiber:1|unlinked:0,two:0,replaced:0,org:0,gone:0,bad:1,rows200:0,twin:0,nomonth:0,planprice:0,differs:0,apart:0,likeplan:0,rule8:0,other:0|plans:2,hw:2"
check "$ADMIN102|$REFA102|$REFT102|$(python3 "$H102/rh102q.py" lk "$DATA/plugin.sqlite3")" "$( [ -n "$ADMIN102" ] && echo "$ADMIN102" || echo none)|$REF_U|$REF_U|absent" \
  "pre-d the RELEASE's own UcrmQuoteLines and UcrmQuoteLinks (from Git) on the store's offered rows: nothing linked — every plan, kit and the installation \"not linked\", the Fiber plan apart; its own candidate() finds two plan pairs and three products an admin could link; through the plugin's key and the token alike; no link store on the live book; an active admin to act as"
check "$(sed -E 's/"ids":\[[0-9,]*\],//' <<<"$SCRL102")|$REFAL102|$REFTL102|$REFAZ102|$REFTZ102|$(python3 "$H102/rh102q.py" lk "$DATA/plugin.sqlite3")|$(python3 "$H102/rh102q.py" u102req "$U99/requests.log" | sed -E 's/^total=[0-9]+ //')" \
      '{"ops":5,"kinds":["linked","linked","linked","linked","linked"],"records":5,"active":5,"two":0}'"|$REF_L|$REF_L|$REF_Z|$REF_Z|absent|other=0 products=3/3 plans=3/3 attrs=0 quote=0 odd=0" \
  "pre-d' on a scratch copy of the book, every item linked through the PIN's own UcrmQuoteLinks::set() (five records, \"linked\" each): every item resolves; one Product priced 0, exactly that item is broken by its price (bad) and one fewer product could be linked for hardware — the live book still holds no link store; GETs only, each reading one GET of each list per client (three readings: pre-d's, linked, priced 0) — candidate() asks nothing"
rm -rf "$SCR102"
: > "$U99/requests.log"; : > "$U99/limits.log"
O102_0="$(o100)"
check "$( [ "$(qj "$O102_0" sales)" -gt 0 ] && echo sales)|$( [ "$(qj "$O102_0" tech)" -ge 2 ] && echo tech)|$(qj "$O102_0" inbox)|$(set99 kyc_quote_notes_prefix)|$(set99 crm_auth_token)|$(set99 crm_base_url)" "sales|tech|unset|unset/unset|unset/set|unset/unset" \
  "pre-e 5.18.100's rehearsal's staff records for the carried owner checks; kyc_quote_notes_prefix unset in both copies; the admin token in the store row alone, no crm_base_url: the token client reads through the plugin's base URL"
D102="$(data94)"; KEPT102="$(kept_state)"; U102_A="$(apps102)"; N3_102="$(python3 "$H102/rh102q.py" kv "$U102_A" n3)"
D11_102="$(python3 "$H102/rh102q.py" kv "$U102_A" d11)"   # QL-N3's D11 count of the store as seeded, by this rehearsal's own reading (review 2, L1)
PLAN_D102="$(sq "SELECT MAX(substr(json_extract(data, '\$.updated_at'), 1, 10)) FROM subscription_plans")"
KIT_D102="$(sq "SELECT MAX(substr(json_extract(data, '\$.created_at'), 1, 10)) FROM kyc_devices")"
# What every reading of the catalogue must say, as the release's resolver says it (pre-d) and the probe prints it — by
# link state: nothing linked (U), everything linked (L). qlr's classes in the script's order (D14 derivation M-3, M-20).
qlr102() {   # qlr102 [CLASS:N …] — one client's qlr part, every class 0 but those named
  local c v out="" k
  for c in unlinked two replaced org gone bad rows200 twin nomonth planprice differs apart likeplan rule8; do
    v=0; for k in "$@"; do [ "${k%%:*}" = "$c" ] && v="${k#*:}"; done
    out="${out:+$out,}$c:$v"
  done
  printf '%s' "$out"
}
qlc102() { printf 'rows:%s,ok:%s,unpriced:%s,unlinked:%s,fiber:%s,unread:0,other:0' "$1" "$2" "$3" "$4" "$5"; }   # qlc102 ROWS OK UNPRICED UNLINKED FIBER
both102() { printf 'plug:%s;tok:%s;dbg:same' "$1" "$1"; }   # both102 PART — the same part through the plugin's and the token client
QLC_U="$(both102 "$(qlc102 5 0 5 5 1)")"; QLR_U="$(both102 "$(qlr102 unlinked:5)")"
QLC_L="$(both102 "$(qlc102 5 5 0 0 1)")"; QLR_L="$(both102 "$(qlr102)")"
QLPK_OK="$(both102 'rows:1,ok:0,unpriced:1,unlinked:0,fiber:0,unread:0,other:0')"
QLCAND_OK="$(both102 'plans:2,hw:3')"; QLROWS_OK="plan:2,planfiber:1,kit:2,kitfiber:0,package:1"
QLORG_OK="$(both102 'p:8/2/1,s:3/0/0')"
say102() { printf '%s item(s) — %s resolve, %s not linked, %s linked but broken, %s Fiber (apart)' "$1" "$2" "$3" "$4" "$5"; }   # the script's ql_say
SAY_U="$(say102 5 0 5 0 1)"; SAY_L="$(say102 5 5 0 0 1)"
ROWS_SAY="2 plan(s) (1 Fiber, apart), 2 kit(s) (0 Fiber, apart) and the installation; 1 kyc_packages row(s), apart (QL-N7)"
CAND_SAY="2 plan pair(s) (a product with its service plan) and 3 product(s) for hardware and the installation"
qlg1ok102() {   # qlg1ok102 SAY — QL-G1's ok line, both clients reading SAY, the form's rows and uCRM's candidates as seeded
  printf "  ok    QL-G1 the catalogue the KYC form offers is read and judged through the RELEASE's UcrmQuoteLines on a copy, GET-only — GET products?limit=500, then GET service-plans?limit=500, nothing else asked by the resolver (the organization field's raw reads come after, below) — with the copy's link records judged by its UcrmQuoteLinks, every answer classified by its why code and exact text: through the plugin's own client %s; through the token client %s; the form offers %s; uCRM offers what an admin could link, by the release's own rules — through the plugin's own client %s; through the token client %s; per item its name, any link recorded on it and uCRM's items with the same spelling (a hint only, never a verdict) on the sheet" "$1" "$1" "$ROWS_SAY" "$CAND_SAY" "$CAND_SAY"
}
QLG1_OK_U="$(qlg1ok102 "$SAY_U")"; QLG1_OK_L="$(qlg1ok102 "$SAY_L")"
# The items that do not resolve, client by client, as ql_unp_say says them — never the two clients' verdicts summed as
# items (QL-1): not linked, and linked but broken with the broken ones' classes.
unps102() { printf "through the plugin's own client %s not linked, %s linked but broken%s; through the token client %s not linked, %s linked but broken%s, the same items judged by each" "$1" "$2" "${3:+ ($3)}" "$1" "$2" "${3:+ ($3)}"; }   # unps102 UNLINKED BROKEN [CLASSES]
UNPS_U="$(unps102 5 0)"
ORG_SAY="products 8 row(s), 2 carrying an organizationId, 1 of them not the quoting organization's; service plans 3 row(s), 0 carrying an organizationId, 0 of them not the quoting organization's"
QLG1_ORG="  note  QL-G1 the organization field, read raw through the same GET-only clients after the catalogue's two lists, counted apart (one GET of each list per client; docs/71 §R.7.1, §D.2): through the plugin's own client $ORG_SAY; through the token client $ORG_SAY — a note, never toward the NO-GO; the organization numbers seen on the sheet"
QLG1_NONE="  note  QL-G1 the link store on the copy: none — nothing was ever linked on this book (5.18.101 has no link screen; only an earlier, rolled-back run of 5.18.102 could have left one, docs/71 §R.7.1)"
QLG1_UNRES="  note  QL-G1 item(s) the KYC form offers do not resolve yet — "   # …ql_unp_say — as expected before the copy …
QLN7_OK="  note  QL-N7 the active kyc_packages rows — offered by the retailer app and the mobile API, never by the KYC form, and on Uganda never priced from uCRM: each is a retailer-app package, which is not linked to uCRM (docs/71 §R.2.5) — through the plugin's own client 1 row(s) — 1 a retailer-app package, not linked to uCRM; 0 priced; 0 unclassified; through the token client 1 row(s) — 1 a retailer-app package, not linked to uCRM; 0 priced; 0 unclassified — a note, never toward the NO-GO (docs/71 §Q D-R4); names on the sheet"
need102() { if [ "$3" = "0" ]; then printf '%s plan(s) and %s kit(s) the KYC form offers are not linked (the installation is)' "$1" "$2"; else printf '%s plan(s), %s kit(s) and the installation are not linked' "$1" "$2"; fi; }   # the script's ql_need_say, nothing else held
check "$(cut -d'|' -f1 <<<"$REF_U" | sed -E 's/,fiber:([0-9]+)$/,fiber:\1,unread:0,other:0/')|$(cut -d'|' -f2 <<<"$REF_U" | sed -E 's/,other:0$//')|$(cut -d'|' -f3 <<<"$REF_U")|$(cut -d'|' -f1 <<<"$REF_L" | sed -E 's/,fiber:([0-9]+)$/,fiber:\1,unread:0,other:0/')" \
      "$(qlc102 5 0 5 5 1)|$(qlr102 unlinked:5)|plans:2,hw:3|$(qlc102 5 5 0 0 1)" \
  "control: what this rehearsal expects the probe to print (rows, resolve, unpriced, not linked, Fiber; the classes; the candidates; per client) is what the release's own resolver and judge read in pre-d and pre-d'"

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
check "$PS102|$KS102|$PC102|$(sed -n 's/^PIN_SHA_N=\([0-9]*\)$/\1/p;s/^PREV_KEPT_N=\([0-9]*\)$/\1/p;s/^PREV_CHANGED_N=\([0-9]*\)$/\1/p' "$DEPLOY102" | paste -sd, -)" " 69 ok| 55 ok| 38 ok|55,38,69" \
  "every sha256 the script pins, computed here from Git: the 69 release files at the pin, the 55 kept ones in both commits, the 38 changed ones at 5.18.101 — and the counts it states"
KEEPL102="$(sed -n '/^PREV_KEPT_SHA256S="$(cat <<.SUMS.$/,/^SUMS$/p' "$DEPLOY102")"
check "$(for f in webhook.php cron_quote_wa.php lib/QuoteOrganization.php lib/QuoteTaxLine.php lib/TenantProfile.php lib/PublicPriceFeed.php lib/EfrisClientField.php includes/api/api_retailer.php retailer/index.html lib/SalesNumbersAdmin.php; do grep -cE "  ${f//./\\.}\$" <<<"$KEEPL102"; done | paste -sd' ' -)|$(for f in $ADDED102 $CHANGED102; do grep -cE "  ${f//./\\.}\$" <<<"$KEEPL102"; done | sort -u | paste -sd, -)" \
      "1 1 1 1 1 1 1 1 1 1|0" \
  "…the kept files hold webhook.php (the sign-off 5.18.101 made), cron_quote_wa.php, the quoting files 5.18.102 keeps byte for byte (QuoteOrganization, QuoteTaxLine, TenantProfile, PublicPriceFeed, EfrisClientField), the retailer app's two and 5.18.100's owner rule, once each — and none of the 69 files the release adds or changes"
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
check "$(for s102 in "5.18.102 is unpriced at its retry, whatever uCRM holds and whatever an admin links (the release's entry rule," 'Q-CA6 the installed profile,' 'the proof that its Cashbook reads write' 'After it, on Uganda, where the quoting organization is in force, the quotations' "When V1's rollback does not verify, its stop says the rollback did not complete" 'a kind of item for which uCRM offers nothing an admin could link'; do grep -cF -- "$s102" <<<"$H102H"; done | paste -sd' ' -)|$(grep -cF 'the proof that the release writes nothing to the book' <<<"$H102H")|$(grep -cE "An application saved before 5\.18\.102 carries no|a name that cannot be|row's name in the plugin" <<<"$H102H")" \
      "1 1 1 1 1 1|0|0" \
  "…and the header's words, each once: D11 by the release's entry rule (E-1), Q-CA6 described (L10), C5's proof as its Cashbook reads (L4), the rollback's on Uganda where the quoting organization is in force (E2-5), V1's rollback that does not verify (E2-3), QL-G1's refusal of a kind uCRM offers nothing an admin could link for (D14 §R.7.1) — never \"the release writes nothing to the book\", never the spelling rule's words (\"Who installs?\" missing, a name that cannot be matched, the row's name in the plugin)"
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
# QL-RB3's (D14 derivation M-16): each changed file that names the link class or its store at the pin, by the count pinned,
# 0 at 5.18.101; the sweep's control, the pin's lib/UcrmQuoteLinks.php, names both; 5.18.101 tracks no file naming either.
RB3M102="$(sed -n "/^QL_RB3_MARKS=\"\$(cat <<'MARKS'\$/,/^MARKS\$/p" "$DEPLOY102" | grep -F '|')"
check "$(grep -c . <<<"$RB3M102")|$(while IFS='|' read -r f m n; do printf '%s:%s:%s\n' "$( [ "$(git -C "$REPO" show "$P102:$P/$f" | grep -cF -- "$m")" = "$n" ] && echo pin || echo "pin-differs")" "$(git -C "$REPO" show "$P101:$P/$f" | grep -cF -- "$m")" "$(grep -cxF "$P/$f" <<<"$(dl M "$P101" "$P102")")"; done <<<"$RB3M102" | sort | uniq -c | tr -s ' ' | paste -sd, -)|$(git -C "$REPO" show "$P102:$P/lib/UcrmQuoteLinks.php" | grep -cF UcrmQuoteLinks | awk '{print ($1 > 0 ? "class" : "noclass")}'):$(git -C "$REPO" show "$P102:$P/lib/UcrmQuoteLinks.php" | grep -cF ucrm_quote_links | awk '{print ($1 > 0 ? "store" : "nostore")}')|$(git -C "$REPO" grep -lF -e UcrmQuoteLinks -e ucrm_quote_links "$P101" -- "$P" 2>/dev/null | grep -c .)" \
      "17| 17 pin:0:1|class:store|0" \
  "QL-RB3's seventeen markers, counted in Git: each file names its needle at the pin exactly as the script pins it, 5.18.101's file names it nowhere, each a file the release changes; the sweep's control, the pin's lib/UcrmQuoteLinks.php, names both the class and the store; no file 5.18.101 tracks names either"

echo; echo "== 102-c. --check: stage A alone — GO on 5.18.101 as production has it, nothing linked; the catalogue and its notes =="
FP102="$(fp99)"; fresh102; S102C="$(states102)"; OUT="$(run102 --check)"; TC="$(last102)"; SH102C="$(ls -1 "$OUT102"/cash-*.txt 2>/dev/null | head -1)"
check "$(checkgo102 "$OUT")|$( [ -e "$OUT102/ql-key-5.18.102" ] && echo key || echo nokey)" "yesyesnono0|0|$S102C|nokey" "c1 --check is GO, never asks, backs nothing up, writes no state file and no QL key file"
check "$(has "$OUT" "$QLG1_OK_U")|$(qor102 "$OUT" 1 qlc)|$(qor102 "$OUT" 1 qlr)|$(qor102 "$OUT" 1 qlpk)|$(qor102 "$OUT" 1 qlread)|$(qor102 "$OUT" 1 qlpath)|$(qor102 "$OUT" 1 qlcand)|$(qor102 "$OUT" 1 qlrows)|$(qor102 "$OUT" 1 qlorg)|$(qor102 "$OUT" 1 qlorgpath)|$(qor102 "$OUT" 1 qlattr)|$(qor102 "$OUT" 1 qlattrrel)|$(qor102 "$OUT" 1 ql314)" \
      "yes|$QLC_U|$QLR_U|$QLPK_OK|plug:read;tok:read;dbg:same|ok|$QLCAND_OK|$QLROWS_OK|$QLORG_OK|ok|one:47|47|notfound" \
  "c2 QL-G1 ok with nothing linked — every item \"not linked\" through the pin's resolver and judge, both clients, the Fiber plan apart; uCRM offers two plan pairs and three products an admin could link (the release's candidate()); the probe's counts, candidates, rows, organization field, read states, its own count of the two lists, the attribute and 000314 — as the release's resolver and judge give them (pre-d)"
check "$(qol102 "$OUT" qloffer)|$(qol102 "$OUT" qllinks)|$(qol102 "$OUT" qlneed)|$(qol102 "$OUT" qln3)|$(qol102 "$OUT" qln4)|$(qol102 "$OUT" qln5)|$(qol102 "$OUT" qln3d11)" \
      "plans:3,kits:2,packages:1,links:0,written:1|absent|plan:2:0,kit:2:0,install:1:0|$(python3 "$H102/rh102q.py" kv "$U102_A" n3)|all:$(python3 "$H102/rh102q.py" kv "$U102_A" all),existing:$(python3 "$H102/rh102q.py" kv "$U102_A" existing),nodate:$(python3 "$H102/rh102q.py" kv "$U102_A" nodate),ep:unmeasurable|plan:$PLAN_D102,kit:$KIT_D102|$D11_102" \
  "c3 the local reading: the rows the form offers (three active plans, the Fiber one among them; two active kits; one package) and no link record; the link store absent (never made — read through the pin's UcrmQuoteLinks by its path, never load()); two plans, two kits and the installation not linked; the applications by line shape, by the release's ENTRY rule (E-1) and over 90 days as this rehearsal's own reading counts them; the newest plan save and kit as dates"
check "$(has "$OUT" "${QLG1_UNRES}${UNPS_U} — as expected before the copy: nothing can be linked before it (5.18.101 has no link screen), so this is no longer a refusal (docs/71 §R.7.1).")|$(has "$OUT" "$QLG1_NONE")|$(has "$OUT" "$QLG1_ORG")|$(grep -c '^  STOP: ' <<<"$OUT")|$(grep -c 'do not resolve in uCRM through the RELEASE' <<<"$OUT")" \
      "yes|yes|yes|0|0" \
  "c3b the items not linked said as the expected reading before the copy — client by client, never summed — never a refusal (D14 §R.7.1); the link store on the copy: none; the organization field read raw after the two lists, a note; never the spelling rule's refusal"
check "$(noteline "$OUT" QL-N1 'exactly one, #47' 'answers the same')$(noteline "$OUT" QL-N2 'quotation 000314: not found in the book' 'no request was made')$(noteline "$OUT" QL-N3 "pending $(n3say102 "$N3_102" pending); review $(n3say102 "$N3_102" review)" 'none carries 5.18.102')$(noteline "$OUT" QL-N4 "(submitted_at): $(python3 "$H102/rh102q.py" kv "$U102_A" all) — of them an existing customer's $(python3 "$H102/rh102q.py" kv "$U102_A" existing) (a request carrying a customer_id" "$( [ "$(python3 "$H102/rh102q.py" kv "$U102_A" nodate)" = "0" ] && echo 'the record carries none' || echo "; $(python3 "$H102/rh102q.py" kv "$U102_A" nodate) with no date, not counted")")$(noteline "$OUT" QL-N5 "(subscription_plans updated_at) $PLAN_D102, the newest kit added (kyc_devices created_at) $KIT_D102")$(noteline "$OUT" QL-N6 'kyc_quote_notes_prefix unset/unset')$(has "$OUT" "$QLN7_OK")" \
      "yesyesyesyesyesyesyes" \
  "c4 QL-N1–QL-N7 as seeded: the salesPerson attribute #47 (the service field and the tax-named one left out), 000314 not in the book, the pending and review applications by shape and the 90 days as this rehearsal's own reading counts them, the dates, the notes prefix unset, the package a retailer-app package not linked to uCRM (D14 §R.2.5) — a note only"
check "$(qol102 "$OUT" qln3d11)|$(noteline "$OUT" QL-N3 "After the deploy their retry takes none of them from uCRM (docs/71 E-1, §R.4.2): the entry rule comes first, and none saved before the deploy is a web registration of 5.18.102's form. $(d11say102 "$D11_102")")|$(grep '^  note  QL-N3 ' <<<"$OUT" | grep -c "After the deploy the retry takes them from uCRM's prices alone")" \
      "$D11_102|yes|0" \
  "c4b QL-N3, the quoting organization in force: by the release's ENTRY rule none saved before the deploy is priced at its retry, whatever uCRM holds and whatever an admin links (E-1) — counted as this rehearsal's own reading of that rule counts them, said with the probe's own count; never the superseded \"the retry takes them from uCRM's prices alone\""
check "$(okline "$OUT" QS-G1 'as text: the pin')$(okline "$OUT" TN-G1 'as text')$(okline "$OUT" TN-G2 "offer the same $(qj "$O102_0" sales) active salesperson account(s) and the same $(qj "$O102_0" tech) active technician account(s)")$(okline "$OUT" TN-G3 'wa_inbox_roles is unset')$(okline "$OUT" A6 'South Sudan stays exactly as it is')$(okline "$OUT" A22-T1 "under this server's own PHP")$(okline "$OUT" Q-G3 'quoting organization #1')" \
      "yesyesyesyesyesyesyes" \
  "c5 the carried checks ok — QS-G1 (5.18.101's), TN-G1–TN-G3 (5.18.100's), and A6 and A22-T1, whose signatures 5.18.102 carries from 5.18.101 (SS/UG, B2, P90; T1), measured here on the release commit"
check "$(noteline "$OUT" Q-N7 "from Sync All under 5.18.101 (L1); after the deploy, with the quoting organization #1 in force, the three screens show the server's answer instead: a non-admin \"Admin only.\", an admin the plugin's own refusal \"uCRM's product was not changed: on Uganda, prices are set in uCRM Products.\" (D12)")$(noteline "$OUT" Q-N7 'the gate is off')" "yesno" \
  "c5b Q-N7, carried, says what the Sync All screens show after the deploy with the quoting organization in force — the server's \"Admin only.\" and the plugin's own refusal (D12) — never \"the gate is off\""
TOT102C="$(ureq102 "$TC" | sed -E 's/^total=([0-9]+) .*/\1/')"
check "$(ureq102 "$TC" | sed -E 's/^total=[0-9]+ //')|$TOT102C|$(lim102 "$TC")|$(uother102 "$TC")" "other=0 products=2/2 plans=2/2 attrs=2 quote=0 odd=0|$(gets102 "$OUT")|n=8 bad=0|-" \
  "c6 the stand-in was asked the two lists twice through each client — the catalogue, then the organization field's raw reads, counted apart (D14 derivation M-8) — every one with limit=500; candidate() asked nothing; the attributes twice (the probe's reading and the release's own lookup), no quote — every request a GET, as many as F counts"
check "$(stat -c %a "$SH102C" 2>/dev/null)|$(stat -c %a "$OUT102")|$(grep -cF -f "$OFFERED102" "$SH102C" 2>/dev/null | awk '{print ($1 >= 6 ? "names" : "few:" $1)}')|$(while IFS= read -r n; do grep -qF -- "$n" "$SH102C" && echo y || echo n; done < "$OFFERED102" | sort -u | paste -sd, -)|$(grep -lF -f "$NOWHERE102" "$SH102C" 2>/dev/null | grep -c .)|$(grep -c '→ not priced (unlinked): ' "$SH102C" 2>/dev/null)|$(grep -c '→ uCRM product #' "$SH102C" 2>/dev/null)|$(grep -c '^== QL-G1 per item: uCRM items with the same spelling — a hint only, never a verdict (' "$SH102C" 2>/dev/null)|$(grep -cF -- "== QL-G1 per item: uCRM items with the same spelling — a hint only, never a verdict (the plugin's own client; " "$SH102C" 2>/dev/null)" \
      "600|700|names|y|0|10|0|1|1" \
  "c7 the sheet, 0600 in a folder of mode 700, holds every offered item by name — each \"not priced (unlinked)\" through both clients, none resolving — and the hint section \"uCRM items with the same spelling\" once, through the first client that read the catalogue — the plugin's own here, as its heading names it (a hint only, never a verdict; the per-client verdicts are the catalogue section's); none of the inactive rows, the unoffered plan, 000314's lines or a link record's name"
check "$(grep -c '^== QL-G1 the catalogue the KYC form offers — ' "$SH102C" 2>/dev/null)|$(grep -c '^== QL-CA1 the catalogue the KYC form offers — ' "$SH102C" 2>/dev/null)|$(grep -c '^== QL-LK1 ' "$SH102C" 2>/dev/null)" "1|0|0" \
  "c7b the sheet's catalogue section carries the check's own name — stage A's reading is QL-G1's (review 2, E2-7); a --check makes no reading after a copy, so no QL-CA1 or QL-LK1 section"
check "$(fp99)|$(grep -lF -f "$NAMES102" "$R102/run-$TC.txt" "$OUT102"/*.log 2>/dev/null | grep -c .)|$(names100 "$R102/run-$TC.txt")|$(audit102 "$TC" | sed -E 's/^copies=[0-9]+ //; s/ adj=.*//')|$(vault102 "$TC" | awk -F'>' '{print ($1 == $2 ? "same" : "moved")}')|$(cache102)|$(lk102)" \
      "$FP102|0|0|novault=0 plugin=0|same|0|absent" \
  "c8 nothing changed — tree, store, vault, settings, ucrm.json; the live book still has no link store (stage A read the copy's through the pin's class, never load(), which would create it); no seeded name in the terminal or the log; every PHP process on a copy with a vault of its own; no page cache written anywhere"

# MD1 (D14 §R.7.1; derivation M-5): the note's remedy back to the spelling rule's — "the row's name in the plugin". Run into a
# folder of its own, never swept: 10's negative sweep over every other terminal and log is set against this one (its
# positive control).
mkdir -p "$R102/md1"; M="$(weak102 md1 md1)"; fresh102; OUT="$(R102_OUT_DIR="$R102/md1" run102 --script "$M" --check)"; rm -f "$M"
check "$(grep -c "row's name in the plugin" "$R102/md1"/run-*.txt 2>/dev/null | awk -F: '{ s += $NF } END { print (s >= 1 ? "found" : "none") }')|$(has "$OUT" 'says its remedy — in uCRM, or linking it again')" "found|no" \
  "c9 MD1 — QL-G1's note telling the operator to fix \"the row's name in the plugin\": its terminal, kept apart, holds the superseded remedy (the positive control of 10's sweep)"

echo; echo "== 102-1. QL-G1's refusals by kind, each before anything changes; the flag; the unreadable; the toggles; the weakened copies =="
# ── 1a (D14 §R.7.1, derivation M-4): QL-G1 refuses a KIND for which uCRM offers nothing an admin could link — here plans:
# the stand-in's Service Plans reduced to the one no Product mirrors (#503), so no product with its service plan passes
# the release's own rules. Every item is "not linked" all the same; that is not what is refused. ─────────────────────────
PLANZ102="$(python3 -c 'import json, sys; s = json.load(open(sys.argv[1])); print(json.dumps([p for p in s["plans"] if p.get("id") == 503]))' "$U99/base.json")"
CANDNO_P="plans, through the plugin's own client; plans, through the token client"
CANDNO_H="hardware and the installation, through the plugin's own client; hardware and the installation, through the token client"
u99_set "plans=$PLANZ102"; fresh102; OUT="$(run102 --answer NO)"; T1A="$(last102)"; SH102A="$(ls -1 "$OUT102"/cash-*.txt 2>/dev/null | head -1)"
nogo102 "1a uCRM offers nothing an admin could link for the plans the form offers: NO-GO at QL-G1, by kind, client by client, before the question" "NO-GO (QL-G1): uCRM offers nothing an admin could link for $CANDNO_P — no product (for a plan, no product with its service plan) passes the release's own rules over the catalogue just read (docs/71 §R.3.1 rules 4–7)" "$OUT"
check "$(grep -cxF "      $EXP_ACCEPT102" <<<"$OUT")|$(qor102 "$OUT" 1 qlcand | sed -E 's/hw:[0-9]+/hw:n/g')|$(qor102 "$OUT" 1 qlc)|$(grep -c '^    uCRM items with the same spelling — a hint only: ' "$SH102A" 2>/dev/null | awk '{print ($1 >= 2 ? "hints" : "few:" $1)}')|$(grep -lF -f "$NAMES102" "$R102/run-$T1A.txt" 2>/dev/null | grep -c .)|$(ureq102 "$T1A" | sed -E 's/^total=[0-9]+ //')" \
      "1|$(both102 'plans:0,hw:n')|$QLC_U|hints|0|other=0 products=2/2 plans=2/2 attrs=2 quote=0 odd=0" \
  "…the deploy with --accept-unpriced printed alone, its own command; the candidates by kind (no plan pair; the hardware count not asserted here: with no service plan spelled like them, the plan products may pass rule 6 as hardware), the items not linked as ever; per item uCRM's items with the same spelling on the sheet alone, a hint; the lists read as in c"
check "$(grep -cE '[0-9]+ (row|item)\(s\) the KYC form offers do not resolve in uCRM' <<<"$OUT")|$(has "$OUT" "row's name in the plugin")$(has "$OUT" 'Fix them in uCRM Products')" "0|nono" \
  "…never the spelling rule's refusal or its remedy (\"the row's name in the plugin\", \"Fix them in uCRM Products\")"
u99_set 'price_of={"9103":0,"9104":0,"9105":0}'; fresh102; OUT="$(run102 --answer NO)"
nogo102 "1a' every product a kit or the installation could be linked to priced 0: NO-GO at QL-G1 for hardware and the installation, client by client" "NO-GO (QL-G1): uCRM offers nothing an admin could link for $CANDNO_H — no product" "$OUT"
check "$(qor102 "$OUT" 1 qlcand)" "$(both102 'plans:2,hw:0')" "…the candidates by kind: two plan pairs, no product for hardware"
u99_set "plans=$PLANZ102" 'attrs_none=true'; fresh102; OUT="$(run102 --check --accept-unpriced)"
check "$(checkgo102 "$OUT" | cut -d'|' -f1)|$(has "$OUT" "  note  QL-G1 --accept-unpriced, the operator's explicit choice: uCRM offers nothing an admin could link for $CANDNO_P — no product (for a plan, no product with its service plan) passes the release's own rules over the catalogue just read (docs/71 §R.3.1 rules 4–7) — so after the deploy every item of that kind stays unpriced, whatever an admin links")|$(has "$OUT" '  --check           stage A alone, every refusal and note of a deploy with --accept-unpriced:')|$(grep -cxF "      $(runbook_with102 --accept-unpriced)" <<<"$OUT")|$(noteline "$OUT" QL-N1 'no client attribute is keyed salesPerson in uCRM')" \
      "yesyesnono0|yes|yes|1|yes" \
  "1b the same with --check --accept-unpriced: GO, QL-G1 a note in the flag's new meaning — a kind uCRM offers nothing linkable for stays unpriced whatever an admin links — the operator's choice; the deploy it hands over carries the flag; no salesPerson attribute a note (QL-N1)"
u99_set 'price_of={"9104":0}'; fresh102; OUT="$(run102 --check)"
check "$(checkgo102 "$OUT" | cut -d'|' -f1)|$(qor102 "$OUT" 1 qlcand)|$(qor102 "$OUT" 1 qlc)|$(has "$OUT" "$QLG1_OK_U")" "yesyesnono0|$(both102 'plans:2,hw:2')|$QLC_U|no" \
  "1b' a product priced 0 before the copy is no longer refused (D14 §R.7.1): GO — one product fewer an admin could link for hardware, every item not linked as before"
check "$(okline "$OUT" QL-G1 'the catalogue the KYC form offers is read and judged' "through the plugin's own client 2 plan pair(s) (a product with its service plan) and 2 product(s) for hardware and the installation")" "yes" "…QL-G1 ok, its candidates said as the release counts them"
PLANS102="$(python3 -c 'import json, sys; s = json.load(open(sys.argv[1])); print(json.dumps([p for p in s["plans"] if p.get("id") != 502]))' "$U99/base.json")"
u99_set "plans=$PLANS102"; fresh102; OUT="$(run102 --check)"
check "$(checkgo102 "$OUT" | cut -d'|' -f1)|$(qor102 "$OUT" 1 qlcand | sed -E 's/hw:[0-9]+/hw:n/g')" "yesyesnono0|$(both102 'plans:1,hw:n')" \
  "1b'' a plan whose Service Plan is gone from uCRM leaves one plan pair to link: a kind with any candidate is not refused — GO"
u99_set 'products_500=true'; fresh102; OUT="$(run102 --answer NO --accept-unpriced)"
nogo102 "1c uCRM's Products unreadable (500): NO-GO at QL-G1 that --accept-unpriced does not lift" "NO-GO (QL-G1): uCRM's Products and Service Plans could not be read through the plugin's own client, GET-only (http500) — the release reads them so for Send Quotation" "$OUT"
check "$(has "$OUT" 'No flag lifts it.')|$(qor102 "$OUT" 1 qlread)|$(qor102 "$OUT" 1 qlcand)|$(ureq102 "$(last102)" | sed -E 's/^total=[0-9]+ //')" "yes|plug:http500;tok:http500;dbg:same|plug:unread;tok:unread;dbg:same|other=0 products=1/1 plans=0/0 attrs=2 quote=0 odd=0" \
  "…said so; the service plans not asked once the products failed, and no raw organization read over a catalogue not read, through either client; the attributes read as ever"
u99_set 'plans_500=true'; fresh102; OUT="$(run102 --answer NO)"
nogo102 "1d uCRM's Service Plans unreadable (500): NO-GO at QL-G1" "NO-GO (QL-G1): uCRM's Products and Service Plans could not be read through the plugin's own client, GET-only (http500)" "$OUT"
check "$(ureq102 "$(last102)" | sed -E 's/^total=[0-9]+ //')" "other=0 products=1/1 plans=1/1 attrs=2 quote=0 odd=0" "…each list asked once per client, no raw organization read"
u99_set 'token_products_403=true'; fresh102; OUT="$(run102 --answer NO --accept-unpriced)"
nogo102 "1e the token client refused the Products (403), the plugin's key not: NO-GO at QL-G1 that the flag does not lift" "NO-GO (QL-G1): uCRM's Products and Service Plans could not be read through the token client (crm_auth_token, built as KycService::postQuote builds it), GET-only (http403)" "$OUT"
check "$(qor102 "$OUT" 1 qlread)|$(ureq102 "$(last102)" | sed -E 's/^total=[0-9]+ //')" "plug:read;tok:http403;dbg:same|other=0 products=2/1 plans=2/0 attrs=2 quote=0 odd=0" \
  "…the plugin's own client read both lists and then both raw (four GETs), the token client only the refused one"
u99_set "plans=$PLANZ102"; M="$(weak102 qlg1cw qlg1c)"; fresh102; OUT="$(R102_MUTANT_OUT=1 run102 --script "$M" --check)"; rm -f "$M"
check "$(has "$OUT" '5.18.102 (check): GO')$(has "$OUT" 'NO-GO (QL-G1): uCRM offers nothing an admin could link for')" "yesno" "1f QL-G1's refusal of a kind uCRM offers nothing linkable for weakened: a --check GO over it (caught)"
u99_set 'products_500=true'; M="$(weak102 qlg1r qlg1rp qlg1rt)"; fresh102; OUT="$(R102_MUTANT_OUT=1 run102 --script "$M" --check)"; rm -f "$M"
check "$(has "$OUT" 'could not be read through the plugin'"'"'s own client, GET-only')$(has "$OUT" 'NO-GO (QL-G1): what uCRM offers that an admin could link was not counted (plug:unread;tok:unread;dbg:same')$(has "$OUT" '5.18.102 (check): GO')" "noyesno" \
  "1g QL-G1's two unreadable refusals weakened: their own refusal is gone (caught) — the run still stops, at the candidates that were never counted, a second guard behind them"
u99_set 'attrs_two=true'; row_keep100; row_set kyc_quote_notes_prefix "$NSECRET102"
RQ102="$(rows_add102 '{"kyc_applications.json":[{"_rh102":true,"_rh102q314":true,"quote_ref":"000314","quote_id":88017409}]}')"
fresh102; OUT="$(run102 --check --accept-unpriced)"; TQ="$(last102)"; SH102Q="$(ls -1 "$OUT102"/cash-*.txt 2>/dev/null | head -1)"
check "$RQ102|$(checkgo102 "$OUT" | cut -d'|' -f1)|$(qor102 "$OUT" 1 ql314)|$(noteline "$OUT" QL-N2 'read back once through the plugin' "3 item(s) — product, a productId, a unit; custom, no productId, a unit; custom, no productId, no unit")|$(noteline "$OUT" QL-N1 '2 client attributes are keyed salesPerson' 'ambiguous')|$(noteline "$OUT" QL-N6 'kyc_quote_notes_prefix unset/set')" \
      '{"rows":1}|yesyesnono0|read:3items:product.pid1.unit1,custom.pid0.unit1,custom.pid0.unit0|yes|yes|yes' \
  "1h quotation 000314's application in the book: read back once, each item's type, productId and unit — never a label or a price; two salesPerson attributes ambiguous; the notes prefix set in the store row, said as set, never shown"
check "$(has "$OUT" '(typed and not needed: --accept-unpriced — each check held without it; the deploy below is printed without it)')|$(grep -cxF "      $EXP_RUNBOOK102" <<<"$OUT")|$(ureq102 "$TQ" | sed -E 's/^total=[0-9]+ other=0 //')|$(grep -lF -f "$NOWHERE102" "$R102/run-$TQ.txt" "$SH102Q" "$OUT102"/*.log 2>/dev/null | grep -c .)|$(grep -c 'QL-N2 quotation 000314 (uCRM quote #88017409), read back: 3 item(s); their units, in order: month, piece, (none)' "$SH102Q" 2>/dev/null)" \
      "yes|1|products=2/2 plans=2/2 attrs=2 quote=1 odd=0|0|1" \
  "…--accept-unpriced typed where uCRM offers something linkable for every kind changes nothing — said, and the deploy handed over without it, though every item is not linked; the quote asked once by its id; its lines' labels and the notes prefix nowhere, not even on the sheet, which carries the units alone"
row_back100; QD102="$(sqn102 "DELETE FROM kyc_applications WHERE json_extract(data, '\$._rh102q314') = 1")"; u99_set
check "$QD102|$(data94)|$(set99 kyc_quote_notes_prefix)" "1|$D102|unset/unset" "…000314's application and the notes prefix taken out again: the data as before, byte for byte"
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
# ── 1m (docs/71 D11, §F.4; D14 E-1): nine applications saved before the deploy, through the installed code's own writer.
# The release's ENTRY rule comes first: every application with no quotation made whose quote_sale_entry is not exactly
# "web" is unpriced at its retry, whatever uCRM holds and whatever an admin links — seven of the nine: the pending and the
# review ones whatever their kind, a Shifting Connection, one DishNet installs and one whose lines carry 5.18.102's mark
# among them; not the one already quoted, nor the one recorded as a web registration of 5.18.102's form. P2 is Cash with
# cash collected, P1 the rest. Each carries a plan price (offer_price), so that QL-RB2 counts none. Taken out again. ──────
PLANTD11='{"kyc_applications.json":[
 {"_rh102":true,"_rh102d11":true,"crm_sync_status":"pending","customer_type":"StarLink","sales_type":"Credit","offer_price":1,"submitted_at":"rel:-2 days"},
 {"_rh102":true,"_rh102d11":true,"crm_sync_status":"review","customer_type":" starlink ","connectivity_type":" New Connection ","sales_type":"Cash","amount_charged":287654.35,"offer_price":1,"submitted_at":"rel:-3 days"},
 {"_rh102":true,"_rh102d11":true,"crm_sync_status":"pending","customer_type":"StarLink","sales_type":"Cash","amount_charged":0,"offer_price":1,"submitted_at":"rel:-4 days"},
 {"_rh102":true,"_rh102d11":true,"crm_sync_status":"review","customer_type":"Fiber","offer_price":1,"submitted_at":"rel:-5 days"},
 {"_rh102":true,"_rh102d11":true,"crm_sync_status":"pending","customer_type":"StarLink","connectivity_type":"Shifting Connection","offer_price":1,"submitted_at":"rel:-6 days"},
 {"_rh102":true,"_rh102d11":true,"crm_sync_status":"pending","customer_type":"StarLink","install_by":"dishnet","offer_price":1,"submitted_at":"rel:-7 days"},
 {"_rh102":true,"_rh102d11":true,"crm_sync_status":"review","customer_type":"StarLink","quote_created":1,"offer_price":1,"submitted_at":"rel:-8 days"},
 {"_rh102":true,"_rh102d11":true,"crm_sync_status":"pending","customer_type":"StarLink","quote_lines_source":"ucrm-5.18.102","quote_items":[{"productId":9101,"price":1,"quantity":1}],"offer_price":1,"submitted_at":"rel:-9 days"},
 {"_rh102":true,"_rh102d11":true,"crm_sync_status":"pending","customer_type":"StarLink","quote_sale_entry":"web","offer_price":1,"submitted_at":"rel:-10 days"}]}'
d11add102() { awk -F'[,:]' -v a="$2" -v b="$3" -v c="$4" -v d="$5" '{ printf "pending:%d,review:%d,p1:%d,p2:%d", $2 + a, $4 + b, $6 + c, $8 + d }' <<<"$1"; }   # d11add102 D11 +PENDING +REVIEW +P1 +P2
RD11="$(rows_add102 "$PLANTD11")"; D11P="$(python3 "$H102/rh102q.py" kv "$(apps102)" d11)"
check "$RD11|$D11P|$(python3 "$H102/rh102q.py" kv "$(apps102)" unp)" "{\"rows\":9}|$(d11add102 "$D11_102" 5 2 6 1)|pending:0,manual:0,p1:0" \
  "1m the plant: nine applications through the installed code's own writer — this rehearsal's own reading of the release's entry rule counts exactly seven more (pending 5, review 2; P1 6, P2 1): not the quoted one, not the web one; QL-RB2's kind none"
fresh102; OUT="$(run102 --check)"
check "$(checkgo102 "$OUT" | cut -d'|' -f1)|$(qol102 "$OUT" qln3d11)|$(noteline "$OUT" QL-N3 'After the deploy their retry takes none of them from uCRM (docs/71 E-1, §R.4.2)' "$(d11say102 "$D11P")")" "yesyesnono0|$D11P|yes" \
  "1m' --check over them: GO, the probe's count by the entry rule this rehearsal's own, and QL-N3 says it — no quotation for each from its retry; made by hand in uCRM or not at all (D11, E-1)"
M="$(weak102 md11 md11)"; fresh102; OUT="$(R102_MUTANT_OUT=1 run102 --script "$M" --check)"; rm -f "$M"
check "$( [ -n "$(qol102 "$OUT" qln3d11)" ] && echo read || echo unread)|$( [ "$(qol102 "$OUT" qln3d11)" = "$D11P" ] && echo same || echo differs)|$(noteline "$OUT" QL-N3 "$(d11say102 "$D11P")")" "read|differs|no" \
  "1m'' MD11 — the count by the superseded installation-answer rule (a Shifting Connection, one DishNet installs and a marked one left out): QL-N3's D11 count is not the entry rule's (caught) — the copy's own count read, so it reached its reading (the one check RC-4)"
check "$(sqn102 "DELETE FROM kyc_applications WHERE json_extract(data, '\$._rh102d11') = 1")|$(data94)|$(python3 "$H102/rh102q.py" kv "$(apps102)" d11)" "9|$D102|$D11_102" \
  "…the nine taken out again: the data and the count as before, byte for byte"
# ── 1n MD9 (D14 derivation M-8): the organization field's raw reads made INSIDE the window the resolver's path check
# counts — QL-G1's own path check must see two more GETs than the two lists and refuse. ────────────────────────────────
M="$(weak102 md9 md9)"; fresh102; OUT="$(R102_MUTANT_OUT=1 run102 --script "$M" --check)"; rm -f "$M"
check "$(has "$OUT" "NO-GO (QL-G1): the RELEASE's resolver asked uCRM, through the plugin client and the token client, for something other than its two lists once each")$(has "$OUT" '5.18.102 (check): GO')|$(qor102 "$OUT" 1 qlpath)" "yesno|bad:plug,tok" \
  "1n MD9 — the raw organization reads moved inside the resolver's counted window: QL-G1's path check refuses, naming both clients (caught)"
echo; echo "== 102-4. the deploy, as the operator runs it — DEPLOY typed; nothing linked right after the copy =="
# MD8's plant, before the deploy (D14 derivation M-13): a web registration the Orders card could quote — saved BEFORE the
# copy, its id at or under QLG1_APP_MAX, stamped two days ahead — so that a window chosen by submitted_at would count it
# and the release's window (ids above QLG1_APP_MAX) never does. Synced, with its plan price: the entry rule's count and
# QL-RB2's two refusing counts (pending, in review or failed) do not move. Make the quotation applies only where
# quote_refused is P1 and a uCRM client is recorded (KycService::canMakeQuotation), so QL-RB2's P1–P3 note — a count, never
# a refusal, and made only by a rollback — counts it: 1. It is taken out in 5w''', before 102-6's first rollback counts.
RMD8="$(rows_add102 '{"kyc_applications.json":[{"_rh102":true,"_rh102md8":true,"crm_sync_status":"synced","crm_client_id":7101,"quote_sale_entry":"web","quote_refused":"P1","offer_price":1,"submitted_at":"rel:+2 days"}]}')"
check "$RMD8|$(python3 "$H102/rh102q.py" kv "$(apps102)" d11)|$(python3 "$H102/rh102q.py" kv "$(apps102)" unp)" '{"rows":1}|'"$D11_102"'|pending:0,manual:0,p1:1' \
  "4-pre MD8's plant: one web registration saved before the copy, stamped ahead — the entry rule's count and QL-RB2's two refusing counts unchanged by it; QL-RB2's P1–P3 note counts it (refused P1 with a uCRM client, as Make the quotation needs)"
D102="$(data94)"; KEPT102="$(kept_state)"
LEDGER102="$(ledger95)"; APPMAX102="$(appmax102)"
fresh102; OUT="$(run102 --answer DEPLOY)"; OUT102D="$OUT"; T102D="$(last102)"
echo "  …    the deploy's own tally:$(grep -E '^  checks +[0-9]+ ok, ' <<<"$OUT" | sed -E 's/^  checks +/ /')"
check "$(fails "$OUT")$(has "$OUT" '5.18.102 (deploy): PASSED')$(live)$(installed102 "$P102")$(inst_ver)|$(left102)" "0yes${P102}05.18.102|31" "4 the deploy PASSES: 0 failed, $P102 serving, every file as the pin has it, the 31 it adds among them"
for l in "ok    A0 the release delta is exactly 5.18.102's 69 files — 31 added, 38 changed, each the reviewed one by the sha256 this script pins (69 of 69)" \
         "ok    BR1 the installed plugin is live 5.18.101 ($P101): all " \
         "ok    Q-G1 installed is 5.18.101 ($P101): its manifest says 5.18.101; all 38 files 5.18.102 changes carry the sha256 pinned for 5.18.101, and all 55 files it must not touch" \
         "ok    A14 the stray store is quarantined and the quarantine holds" "ok    A22-T1 under this server's own PHP" "ok    G3 both ECO BANK cards show a figure" \
         "ok    Q-G2 the tenant is Uganda" "ok    Q-G3 quoting organization #1 (from the Uganda profile)" "ok    Q-G4 uCRM's organizations, read once through the plugin's own client" \
         "ok    TN-G1 as text" "ok    TN-G2 on one copy of the book" "ok    TN-G3 wa_inbox_roles is unset" "ok    QS-G1 as text" "${QLG1_OK_U#  }" "$QLG1_NONE" "GO — evidence recorded" \
         "088 (5.18.97's) stands beside it, unchanged by 5.18.102 (BR2, BA1)" \
         "ok    the QL key kept in $OUT102/ql-key-5.18.102 (mode 0600, never printed) — QL-CA1 and QL-LK1 of a later --after-only compare under it" \
         "ok    container serves $P102" "ok    R1 the installed manifest says 5.18.102" \
         "ok    Q-CA1 installed = 5.18.102's release: all 69 of its files carry the sha256 this script pins; all 55 files of 5.18.101 it must not touch" \
         "ok    Q-CA2 the INSTALLED QuoteOrganization::setting() on a copy reads what stage A read through the pin's: on:1:profile:-" \
         "ok    Q-CA3 nothing was created in uCRM: every request this run made of it, through its GET-only wrappers, was a GET" \
         "ok    Q-CA4 Sync All is admin-only" "ok    Q-CA5 the KYC form, Send Quotation, Quote History, Orders and the LTE dashboard without a session each send the visitor to the sign-in page (302)" \
         "ok    TN-CA1 as text, installed" "ok    TN-CA2 the same counts as stage A's reading through the pin" "ok    TN-CA3 wa_inbox_roles as stage A read it (inbox=unset roles=- odd=0 owners=-)" \
         "ok    QS-CA1 as text, installed: webhook.php ends the summary with the owner's wording on Uganda" \
         "ok    QL-CA1 the INSTALLED UcrmQuoteLines, on a fresh copy and GET-only, gives every item it compares the verdict stage A's pin gave it — the same product, price and unit where it resolves, the same reason where it does not, with the same link record — item by item under this run's QL key, never printed: " \
         "; through the plugin's own client $SAY_U; through the token client $SAY_U" \
         "  note  QL-CA1 $(need102 2 2 1) — link them on the Plans and Hardware screens (Uganda), as an admin, once this run has printed its last line (docs/71 §M step 7), then run --after-only (step 8), which judges every link (QL-LK1); the agents, and the window's web registrations: the summary below" \
         "ok    QL-CA2 the client attribute keyed salesPerson reads as stage A read it: exactly one, #47 — the installed release's own lookup answers the same" \
         "ok    QL-CA3 the Plans and Hardware screens (?page=dashboard&tab=subscription_plans, tab=hardware), one plan's, one kit's and the installation's uCRM link picker (ucrm_link_plan=1, ucrm_link_hw=1, ucrm_link_install=1) and Orders (tab=applications) without a session each send the visitor to the sign-in page (302)" \
         "ok    QL-CA3 the installed includes/post/post_sync.php, read as text (comments aside): ucrm_link_save, ucrm_link_clear and kyc_make_quote are each POST-only, inside QuoteOrganization::setting(...)['on'], and call \$auth->requireAdmin()" \
         "ok    QL-CA3 Product Mapping's Auto-Map and its mapping save stay admin-only" \
         "ok    Z1 the live vault" "ok    Z2 nothing of the vault, no webhook_secret file, no token copy, no settings file, no copy of the book and no catalogue file (offer, quotation pointer, keyed hash, page cache) is left under this run's folders in the container's /tmp" \
         "  names             QL-G1, QL-N1–QL-N7, QL-CA1, QL-CA2, QL-CA3, QL-LK1, QL-RB1, QL-RB2 and QL-RB3 are 5.18.102's own" \
         "  what changed      on this Uganda server (Q-G2), the quoting organization #1 in force (Q-G3, Q-CA2): the KYC quotations take their lines and prices from uCRM, through one resolver, lib/UcrmQuoteLines.php, by the uCRM link an admin records for each item on the Plans and Hardware screens (lib/UcrmQuoteLinks.php; QL-G1, QL-CA1: the counts above)" \
         "Nothing is matched by spelling, and an item not linked is not priced." \
         " Right after the copy nothing is linked (docs/71 §R.6): this run's reading after the copy finds items the KYC form offers that do not resolve (QL-CA1: $UNPS_U) — until an admin links each (your steps, 7, below), a Cash sale of one is refused and a Credit one is registered with no quotation (P1)." \
         "  the screens       on Uganda, signed in: the Plans and Hardware screens carry a \"uCRM link\" column, with Link," \
         "  your steps        yours alone — this script does none of them (docs/71 §M, as §R.6 amends it):" \
         "                    2. in uCRM, a client attribute \"Sales Person\" whose key is exactly salesPerson — System →" \
         "                    4. Settings → \"Quote notes prefix\" left empty, or holding only text meant for customers — it" \
         "                    7. once this run has printed its last line, link each item the KYC form offers, as an admin," \
         "                    8. then --after-only (check it, above): QL-LK1 judges every link and fails until every item" \
         "                    And tell the agents: until an admin says every item is linked, register a Uganda sale as" \
         "A Uganda Cash customer is not marked delivery_pdf_sent either: with kyc_messages_like_crm off they would get the plugin's \"Request Confirmed!\" welcome instead of the PDF's caption (docs/07 records it on in Uganda since 25 Sep; this run does not read it)." \
         "An existing customer's kit-only additional service still tells the agent that the new install is being scheduled (docs/71 §P.2)." \
         "A Product marked Taxable in uCRM — the VAT plan's step 3 — stops automatic quotes until a VAT release (docs/71 §M)." \
         "                    And, as §M's \"what changes\" says: no catalogue Product marked Taxable in uCRM (the VAT plan's" \
         "                    step 3) until a VAT release — it stops automatic quotes (docs/71 §M)." \
         "                    linked, and the Orders card shows P1 or P2 with no Make the quotation (D11, docs/71 §F.4," \
         "                    E-1; QL-N3 counted $(( $(sed -nE 's/.*(^|,)pending:([0-9]+).*/\2/p' <<<"$D11_102") + $(sed -nE 's/.*(^|,)review:([0-9]+).*/\2/p' <<<"$D11_102") )) at stage A) — make its quotation by hand in uCRM, which the plugin WhatsApps to the customer as" \
         "                    every new quote, or make none. The window's web KYC registrations, those whose screen said \"Do" \
         "ok    C5 the copy's row digest (every row of cb_ledger, its id and every column), its cb_accounts digest and its sqlite_master digest" \
         "the database — no migration added and nothing written by this run (R7, BA1)"; do
  check "$(has "$OUT" "$l")" "yes" "4: ${l:0:110}"
done
cmp102() { local k v out=""; for k in same differs new gone left linked ended; do v="$(printf '%s' "$1" | tr ',' '\n' | sed -n "s/^$k://p" | head -1)"; out="$out${out:+,}$k:${v:-?}"; done; printf '%s' "$out"; }   # cmp102 QLCMP — qlcmp's seven counts, in one order
check "$(has "$OUT" 'files 5.18.102 adds are already on disk')$(has "$OUT" '  FAIL  QL-')$(qor102 "$OUT" 1 qlc)|$(qor102 "$OUT" 2 qlc)|$(qor102 "$OUT" 2 qlr)|$(cmp102 "$(qor102 "$OUT" 2 qlcmp)" | sed -E 's/^same:[1-9][0-9]*,/same:n,/')|$(grep -E '^  uCRM, GET-only  ' <<<"$OUT" | grep -cE '(^| )qlh=|[0-9a-f]{32}')|$(qol102 "$OUT" qllinks)" \
      "nono$QLC_U|$QLC_U|$QLR_U|same:n,differs:0,new:0,gone:0,left:0,linked:0,ended:0|0|absent" \
  "4a no 5.18.102 file on disk before this first deploy (BR1 says none); stage A's catalogue and the installed resolver's after the copy read the same counts — every item not linked; QL-CA1 item by item: every item the same verdict, none left out, no link record made or ended; no keyed hash in what the probe echoes; no link store on the fresh copy either"
check "$(sed -n 's/^QLG1_A=//p' "$STATE102" | tail -1)|$(sed -n 's/^QLATTR_A=//p' "$STATE102" | tail -1)|$(grep -c '^QLG1_NOTED=$' "$STATE102")|$(sed -n 's/^QLG1_ITEMS=//p' "$STATE102" | tail -1 | grep -cxE '[0-9a-f]{32}:[0-9a-f]{32}(,[0-9a-f]{32}:[0-9a-f]{32})*')|$(sed -n 's/^QLG1_RECS=//p' "$STATE102" | tail -1)|$(sed -n 's/^QLG1_APP_MAX=//p' "$STATE102" | tail -1)|$(grep -E '^QL' "$STATE102" | grep -c 'RH102')|$(keychk102)" \
      "$QLC_U/$QLR_U|one:47|1|1|none|$APPMAX102|0|600|oneline|absent" \
  "4b the state file records stage A's catalogue counts, the attribute's answer, that no flag decided QL-G1, each item's keyed hash with its verdict's, no link record (none) and QLG1_APP_MAX — the highest application id stage A saw; no name; the QL key in its own file, mode 0600, one QL_KEY= line of 48 hex, and in no state file (D14 derivation M-1)"
check "$(grep -c "^COPY_AT=$(stat -c %Y "$PD/lib/KycService.php")\$" "$STATE102")|$(epoch99 "$(sed -n 's/^COPY_AT=//p' "$STATE102" | tail -1)")" "1|epoch" \
  "4c the copy's time, as stamp_copy gave lib/KycService.php — a file this release changes"
SUM102="$(sed -n '/^== F\. Summary ==$/,$p' <<<"$OUT")"
check "$(grep -cxF "      $EXP_AFTER102" <<<"$SUM102")$(grep -cxF "      $EXP_QCHECK" <<<"$SUM102")$(grep -cxF "      $EXP_ROLLBACK102" <<<"$SUM102")$(grep -cxF "      $EXP_PUTBACK102" <<<"$SUM102")$(grep -cE '(deploy-5\.18\.102\.sh|quarantine-stray-store\.sh|deploy-hybrid\.sh).*(deploy-5\.18\.102\.sh|quarantine-stray-store\.sh|deploy-hybrid\.sh)' <<<"$OUT")|$(awk '/^  5\.18\.102 \((deploy|after)\): / { v = 1 } index($0, "deploy-5.18.102.sh --rollback") { print (v ? "after" : "before") }' <<<"$OUT" | paste -sd, -)|$(grep -cxF '  Its one explicit choice, needed only over those applications or when that count cannot be made:' <<<"$SUM102")$(grep -cxF '  --rollback-unpriced-pending, typed after --rollback, rolls back all the same — each such application then' <<<"$SUM102")$(grep -cxF '  counts nothing: an application still waiting for uCRM with no plan price recorded is then quoted by' <<<"$SUM102")$(grep -cxF "  5.18.101's cron at price 0 and WhatsApped as above:" <<<"$SUM102")|$(grep -cE 'deploy-5\.18\.102\.sh.*--rollback-unpriced-pending|--rollback-unpriced-pending.*deploy-5\.18\.102\.sh' <<<"$OUT")|$(grep -c 'deploy-5\.18\.102\.sh --accept-unpriced' <<<"$OUT")" \
      "11110|after|1111|0|0" \
  "4d the --after-only, the quarantine's --check, the rollback and the by-hand put-back, each printed exactly, alone on its line; the rollback once, after the verdict; its explicit choice — needed only over those applications or when the count cannot be made — named on lines of its own and never inside a command; the put-back's hazard said before it (it counts nothing); no command with --accept-unpriced (no flag was needed)"
check "$(grep -lF -f "$NAMES102" "$R102/run-$T102D.txt" "$STATE102" "$OUT102"/*.log 2>/dev/null | grep -c .)|$(names100 "$R102/run-$T102D.txt" "$STATE102" "$OUT102"/*.log)|$(secrets99 "$R102/run-$T102D.txt" "$STATE102")|$(audit102 "$T102D" | sed -E 's/^copies=([0-9]+) /\1 /' | awk '{print ($1 >= 20 ? "many" : $1 " only") " " $2 " " $3}')|$(uother102 "$T102D")" "0|0|0|many novault=0 plugin=0|-" \
  "4e no seeded name, no staff record's name and no stand-in secret printed or recorded; every PHP process on a copy with a vault of its own; GETs only"
check "$(ureq102 "$T102D" | sed -E 's/^total=[0-9]+ //')|$(ureq102 "$T102D" | sed -E 's/^total=([0-9]+) .*/\1/')|$(lim102 "$T102D")" "other=0 products=4/4 plans=4/4 attrs=4 quote=0 odd=0|$(gets102 "$OUT")|n=16 bad=0" \
  "4f the stand-in was asked the two lists four times through each client — stage A's catalogue and its raw organization reads, then the same after the copy — the attributes four times, every request a GET with limit=500, as many as F counts; QL-CA3's six anonymous requests went to the plugin's own public address, never to uCRM"
check "$(ledger95)|$(both_copies "$BK97")|$(kept_state)$(oldname)$(host_code102)$(left_tmp102)$(evo_calls)$(cache102)" "$LEDGER102|11|${KEPT102}00000" \
  "4g the migration ledger unchanged; ECO Bank's switch on in both places; the quarantine as it was; no code copy or run folder left; Evolution never called; no page cache"
d11t102() { awk -F'[,:]' '{ print $2 + $4 }' <<<"$1"; }   # d11t102 D11 — pending + review (E-1: the Fiber cell is gone)
check "$(has "$SUM102" " Every application saved before the deploy is unpriced at its retry (the cron's, or an admin's Retry), whatever uCRM holds and whatever an admin links — the release's entry rule (E-1): no quotation is made, and the Orders card shows P1 (Credit, or no cash collected) or P2 (Cash with cash collected), with no Make the quotation — its quotation is made by hand in uCRM, or not at all (D11, docs/71 §F.4$(d11n102 "$D11_102")).")|$(grep -cxF "                    E-1; QL-N3 counted $(d11t102 "$D11_102") at stage A) — make its quotation by hand in uCRM, which the plugin WhatsApps to the customer as" <<<"$SUM102")|$(grep -cE '^ +5\. no catalogue Product marked Taxable' <<<"$OUT")|$(grep -c "row's name in the plugin" <<<"$OUT")" \
      "yes|1|0|0" \
  "4h F's D11 by the entry rule (E-1) — every application saved before the deploy unpriced at its retry, whatever is linked — with QL-N3's count of this run's stage A, in \"what changed\" and after the steps; the Taxable item is §M's \"what changes\", no step 5; never the spelling rule's remedy"
check "$(grep -cxF "                       it again — required now: $(need102 2 2 1) — each by name on the sheet (QL-CA1);" <<<"$SUM102")|$(grep -cxF "                       the deploy, below);" <<<"$SUM102")|$(grep -c 'on --accept-unpriced' <<<"$SUM102")" \
      "1|1|0" \
  "4h' F's step 7 says what is left to link, required now — two plans, two kits and the installation, as the local reading counts them — and step 8 the --after-only to run once linked; no word of a flag the deploy never took"
check "$(has "$OUT" 'nothing written by the release to the book')$(okline "$OUT" C5 "are unchanged by every read of 5.18.102's code" "— 5.18.102's reads write nothing there")$(has "$OUT" '5.18.102 reads, and writes nothing')" "noyesno" \
  "4i the database said as it is — the Cashbook's tables and the schema unchanged by 5.18.102's reads, its own keys on Uganda applications the plugin's (review 2, DI-1, L4) — never \"nothing written by the release to the book\""
SH102D="$(ls -1 "$OUT102"/cash-*.txt 2>/dev/null | head -1)"
check "$(grep -c '^== QL-G1 the catalogue the KYC form offers — ' "$SH102D" 2>/dev/null)|$(grep -c '^== QL-CA1 the catalogue the KYC form offers — ' "$SH102D" 2>/dev/null)|$(awk '/^== QL-G1 the catalogue/ { a = NR } /^== QL-CA1 the catalogue/ { b = NR } END { print ((a && b && a < b) ? "in order" : "a" a "b" b) }' "$SH102D" 2>/dev/null)|$(grep -c '^== QL-LK1 ' "$SH102D" 2>/dev/null)" "1|1|in order|0" \
  "4j the deploy's sheet: stage A's catalogue under QL-G1, the reading after the copy under QL-CA1, in that order — each section under its own check's name (review 2, E2-7); a deploy judges no link (no QL-LK1 section)"
RBJ102="$(awk 'index($0, "  Only if it is ever needed — the rollback") == 1 { on = 1 } on && index($0, "      ") == 1 { exit } on { sub(/^  /, ""); printf "%s%s", (n++ ? " " : ""), $0 }' <<<"$SUM102")"
RBW102="There the customer sees 5.18.101's quotation again: the internal \"Auto-generated on KYC registration … Sales: … | Connection: … | Priority: …\" text back in the quotation's customer notes (docs/71 §A.1); \"Prepared by\" no longer set on a new KYC customer (one already set keeps its attribute: uCRM is not changed); Send Quotation and the lead quotes again take the plugin's prices and accept a line priced 0 (§J); the fallback PDF back to its old header and terms (§K.1). The created quote is no longer read back, so the Orders card records no mismatch (§F.6, staff only). The Delivery Acknowledgment PDF comes back with South Sudan's terms and a South Sudan phone number (§K.2), and a Uganda Credit customer who paid while 5.18.102 was live was not sent it, nor marked as sent, so gets it at their next payment (webhook.php, kept)."
RBL102="Quotes already made in uCRM are not changed, what 5.18.102 wrote on applications stays as written, and the 31 files it adds stay on disk, reached by nothing — among them lib/UcrmQuoteLinks.php, the class of the uCRM links (QL-RB1, QL-RB3). The uCRM links an admin made stay in the store; 5.18.101 never reads them; a later deploy of 5.18.102 finds them as they were and judges each at use. A KYC registration made since the copy with no quotation is quoted by an admin in uCRM by hand, after checking that the customer has none. An application on which 5.18.102 could not confirm a quotation may already have one in uCRM: check there before quoting it by hand or with retry_quote, which in 5.18.101 does not look first."
check "$(has "$RBJ102" "On Uganda, with the quoting organization in force, the KYC quotations and the Cash amount then come again from the plugin's own plans and kits, saving a plan or a kit and Sync All write uCRM's Products again, and the Delivery Acknowledgment PDF is sent again. $RBW102 $RBL102")|$(grep -c '+211' <<<"$SUM102")|$(awk -v c="      $EXP_ROLLBACK102" 'index($0, "  Only if it is ever needed — the rollback") == 1 { on = 1 } on && index($0, "      ") == 1 { print ($0 == c ? "alone" : "other"); exit }' <<<"$SUM102")" "yes|0|alone" \
  "4k the printed rollback's own block, its wrapped lines joined: what the customer sees again on Uganda (review 2, L5), and what it leaves of the links — in the store, read by nothing of 5.18.101, judged again by a later deploy — the window's registrations quoted in uCRM by hand, and an unconfirmed quotation looked for in uCRM first (D14 §R.7.4, §R.17.3); no digit of a phone; the rollback command alone on the line after it"

echo; echo "== 102-5. --after-only as printed: before anything is linked, linked by an admin, the window, rule 8; uCRM changed or unreadable; the screens' guards =="
copy_back102 30; COPY102="$(sed -n 's/^COPY_AT=//p' "$STATE102" | tail -1)"
[ "$(epoch99 "$COPY102")" = "epoch" ] && set_sched $(( COPY102 + 300 )); base_log
ALINE="$(printed "$OUT102D" "cd $REPO && bash scripts/deploy-5.18.102.sh --after-only")"
check "$ALINE|$(epoch99 "$COPY102")" "$EXP_AFTER102|epoch" "5 the --after-only command, as the deploy's summary printed it; the copy moved thirty minutes back"
EXP_AFTERU102="cd $REPO && bash scripts/deploy-5.18.102.sh --after-only --accept-unpriced $(LOGW102 after)"
fresh102; OUT="$(run_line102 '' "$ALINE")"; TA="$(last102)"; SH102A5="$(ls -1 "$OUT102"/cash-*.txt 2>/dev/null | head -1)"
check "$(fails "$OUT")|$(has "$OUT" '5.18.102 (after): 1 FAILED')$(failline "$OUT" QL-LK1 "not every item the KYC form offers resolves through the INSTALLED resolver by its uCRM link: $UNPS_U — each by name, with its reason, on the sheet.")$(has "$OUT" 'An --after-only before every offered item is linked fails here by design (docs/71 §R.6 step 8)')|$(grep -cxF "      $EXP_AFTERU102" <<<"$OUT")|$(grep -E '^  FAIL  ' <<<"$OUT" | grep -vc '^  FAIL  QL-LK1 ')" \
      "1|yesyesyes|1|0" \
  "5a --after-only before anything is linked FAILS, by design, at QL-LK1 alone — every item not linked, client by client — and prints the --after-only --accept-unpriced command alone, its own command (D14 derivation M-14)"
check "$(okline "$OUT" R19 'the quarantine holds: master ran the retry job at' "after the copy (at $(utc "$COPY102"))")$(okline "$OUT" QL-CA1 "the catalogue through the INSTALLED UcrmQuoteLines, with the copy's link records, on a fresh copy, GET-only: through the plugin's own client $SAY_U; through the token client $SAY_U" 'QL-LK1 below judges the links')$(noteline "$OUT" QL-CA1 "against stage A's verdicts, item by item (the state file's hashes, under the deploy's QL key — never printed):" 'a note, never a FAIL (D14 derivation M-1)')$(okline "$OUT" QL-CA2 'reads as stage A read it: exactly one, #47')$(okline "$OUT" QL-CA3 'without a session each send the visitor to the sign-in page (302)')|$(grep -cxF "                       it again — required now: QL-LK1 finds items that do not resolve ($UNPS_U), each by name on the sheet;" <<<"$OUT")|$(grep -cxF "                       the deploy, below) — again once each is linked; the window's web registrations wait for it to pass;" <<<"$OUT")|$(grep -c '^== QL-LK1 ' "$SH102A5" 2>/dev/null | awk '{print ($1 >= 1 ? "lk" : "none")}')" \
      "yesyesyesyesyes|1|1|lk" \
  "5a' …R19 conclusive; QL-CA1 readable and classified, its comparison with stage A a note; QL-CA2; QL-CA3; F's steps 7 and 8 say what is required now — QL-LK1's items — and that the window waits for it to pass; the sheet carries QL-LK1's own section"
fresh102; OUT="$(run_line102 '' "$EXP_AFTERU102")"
check "$(fails "$OUT")$(has "$OUT" '5.18.102 (after): PASSED')$(noteline "$OUT" QL-LK1 "--accept-unpriced, the operator's explicit choice — item(s) the KYC form offers left unlinked or broken on purpose: $UNPS_U")|$(has "$OUT" " Items the KYC form offers are left unlinked or broken on purpose, on --accept-unpriced — the operator's explicit choice (QL-LK1: $UNPS_U)")|$(grep -cxF "                       it again — left so on purpose, on --accept-unpriced (QL-LK1: $UNPS_U), each by name on the sheet;" <<<"$OUT")" \
      "0yesyes|yes|1" \
  "5a'' the --after-only --accept-unpriced it printed PASSES: QL-LK1 a note naming the flag as the operator's choice for items left unlinked on purpose; F and step 7 say so"
# ── The deploy command with --accept-unpriced (EXP_ACCEPT102, as QL-G1's refusal prints it), run again from the shell's
# history once 5.18.102 serves (the one check SS-1): the deploy turns into an --after-only's checks, and the flag, typed for
# QL-G1, is cleared there and said, never deciding QL-LK1 — the same FAIL as 5a. Its weakened copy keeps the flag set. ─────
fresh102; OUT="$(run_line102 '' "$EXP_ACCEPT102")"
check "$(fails "$OUT")|$(has "$OUT" '5.18.102 (after): 1 FAILED')$(has "$OUT" '— skipping the deploy, running the checks')$(has "$OUT" '  note  --accept-unpriced was typed with a deploy, for QL-G1, and this run makes no deploy: it decides nothing here — QL-LK1 judges as in an --after-only without it')$(failline "$OUT" QL-LK1 "not every item the KYC form offers resolves through the INSTALLED resolver by its uCRM link: $UNPS_U — each by name, with its reason, on the sheet.")|$(has "$OUT" 'QL-LK1 --accept-unpriced, the operator')$(has "$OUT" '  the flag          --accept-unpriced')" \
      "1|yesyesyesyes|nono" \
  "5a''' the deploy command with --accept-unpriced, run again once 5.18.102 serves: an --after-only's checks, the flag cleared and said, QL-LK1 FAILING as in 5a — never a note on a choice made for QL-G1 (the one check SS-1)"
M="$(weak102 ss1 ss1)"; fresh102; OUT="$(R102_MUTANT_OUT=1 run102 --script "$M" --accept-unpriced)"; rm -f "$M"
check "$(has "$OUT" '— skipping the deploy, running the checks')|$(fails "$OUT")$(has "$OUT" '5.18.102 (after): PASSED')$(has "$OUT" 'QL-LK1 --accept-unpriced, the operator')" "yes|0yesyes" \
  "5a'''' SS-1's weakened copy, the flag left set over the live pin: QL-LK1 a note and PASSED, on a choice made for QL-G1 (caught; the copy reached the switch)"
# ── An admin links every item the form offers, through the INSTALLED release's own writer (UcrmQuoteLinks::set, as the
# Plans and Hardware screens' ucrm_link_save calls it): plan #1 to 9101 with service plan 501, plan #2 to 9102 with 502,
# kit #1 to 9103, kit #3 to 9104, the installation to 9105 — each by uCRM id, as docs/71 §M step 7 says. ───────────────
ADMIN_LINK102="$(links102 "$PD" "$LINKALL102")"
check "$(sed -E 's/"ids":\[[0-9,]*\],//' <<<"$ADMIN_LINK102")|$(lk102 "$COPY102")" \
      '{"ops":5,"kinds":["linked","linked","linked","linked","linked"],"records":5,"active":5,"two":0}|records:5,active:5,two:0|ended:0' \
  "5-link an admin links all five items through the installed release's own set(): five records, each \"linked\", none twice; the store now holds the link table"
D102="$(data94)"   # the link records are data: the baseline moves with each deliberate act of an admin's, never by a run
fresh102; OUT="$(run_line102 '' "$ALINE")"; TB="$(last102)"; OUT102B="$OUT"
check "$(fails "$OUT")$(has "$OUT" '5.18.102 (after): PASSED')$(okline "$OUT" QL-LK1 "every active, non-Fiber plan and kit the KYC form offers, and the installation, resolves through the INSTALLED resolver by its uCRM link, on a fresh copy, GET-only: through the plugin's own client $SAY_L; through the token client $SAY_L")|$(qor102 "$OUT" 1 qlc)|$(qor102 "$OUT" 1 qlr)|$(lk102)" \
      "0yesyes|$QLC_L|$QLR_L|records:5,active:5,two:0" \
  "5b linked by an admin: --after-only PASSES — QL-LK1 ok, every item resolving by its link through both clients; the link store as the admin left it, by this rehearsal's own reading (an --after-only prints no stage-A \"the copy\" line, so the probe's qllinks and qlneed are not read here: QL-LK1's ok line and notes, 5b', and F's step 7, 5b'', say it)"
check "$(noteline "$OUT" QL-LK1 "the active link records on the copy: 5 made by an account that is an active admin now, 0 by one that is not, or no longer")$(noteline "$OUT" QL-LK1 "the link records since the copy, by identity — stage A's keyed id hashes from the state file, never linked_at: 5 made since the copy, 0 read by stage A")$(noteline "$OUT" QL-LK1 "in force on rows the form does not offer (inactive, or Fiber): 0 plan(s) and 0 kit(s); uCRM products backing two or more offered kits: 0 (Q16)")$(noteline "$OUT" QL-LK1 "link records ended since the copy (ended_at_ts against COPY_AT, never ended_at): 0 — the applications quoted under them: Cash 0, Credit 0")$(noteline "$OUT" QL-LK1 'whose linked_at_ts disagrees with its identity')|$(noteline "$OUT" QL-CA1 "left out 5 — link records made since stage A 5, ended or relinked since 0" 'the links an admin made, changed or removed since stage A are why an item is left out')" \
      "yesyesyesyesno|yes" \
  "5b' QL-LK1's notes, counts only: five links by an active admin; five made since the copy by identity (never by linked_at), none a leftover, no disagreement; none on a row the form does not offer, no shared product; none ended; QL-CA1 leaves the five linked items out of its comparison with stage A, a note"
check "$(grep -cxF "                       it again — done: every item the form offers resolves by its uCRM link (QL-LK1);" <<<"$OUT")|$(has "$OUT" " Every item the KYC form offers resolves by its uCRM link (QL-LK1).")|$(grep -lF -e 'RH102QSECRET-linkname-' -e 'RH102QSECRET-linkrow-' -e 'RH102QSECRET-admin' "$R102/line-$TB.txt" "$OUT102"/*.log 2>/dev/null | grep -c .)|$(grep -cF -e 'RH102QSECRET-linkname-' -e 'RH102QSECRET-linkrow-' -e 'RH102QSECRET-admin' "$(ls -1 "$OUT102"/cash-*.txt 2>/dev/null | head -1)" 2>/dev/null | awk '{print ($1 >= 1 ? "sheet" : "none")}')|$(grep -c '(link record #[0-9]*)' "$(ls -1 "$OUT102"/cash-*.txt 2>/dev/null | head -1)" 2>/dev/null | awk '{print ($1 >= 5 ? "records" : "few:" $1)}')|$(sq "SELECT COUNT(*) FROM ucrm_quote_links WHERE data LIKE '%RH102QSECRET-linkname-%' AND data LIKE '%RH102QSECRET-admin%'" | awk '{print ($1 ~ /^[0-9]+$/ && $1 + 0 >= 5 ? "store" : "few:" $1)}')" \
      "1|yes|0|none|records|store" \
  "5b'' F: step 7 done, every item resolving; no link record's stored names (row, product, admin) in the terminal, any log or the 0600 sheet — the script never reads them (SPEC §2.4: never row_name, product_name, linked_by_*); the sheet names each item's link by its record id; control: the live store holds those names"
# ── The window (D14 derivation M-13): four registrations made since the copy, ids above QLG1_APP_MAX — a web one the
# Orders card can quote, one from the retailer app, one with no entry recorded and a web one whose quotation was found in
# uCRM — beside MD8's, saved before the copy and stamped ahead. The release counts make 1, app 2, found 1. ───────────────
RWIN102="$(rows_add102 '{"kyc_applications.json":[
 {"_rh102":true,"_rh102win":true,"crm_sync_status":"synced","crm_client_id":7102,"quote_sale_entry":"web","quote_refused":"P1","offer_price":1,"submitted_at":"rel:now"},
 {"_rh102":true,"_rh102win":true,"crm_sync_status":"synced","crm_client_id":7103,"quote_sale_entry":"app","quote_refused":"P1","offer_price":1,"submitted_at":"rel:now"},
 {"_rh102":true,"_rh102win":true,"crm_sync_status":"synced","crm_client_id":7104,"quote_refused":"P1","offer_price":1,"submitted_at":"rel:now"},
 {"_rh102":true,"_rh102win":true,"crm_sync_status":"synced","crm_client_id":7105,"quote_sale_entry":"web","quote_refused":"P1","quote_found_in_ucrm":"88019901","offer_price":1,"submitted_at":"rel:now"}]}')"
WIN102="$(win102 "$APPMAX102")"
check "$RWIN102|$WIN102" '{"rows":4}|make:1,app:2,found:1' "5w the plant: four registrations since the copy; this rehearsal's own reading of the release's window rule (ids above QLG1_APP_MAX, canMakeQuotation) counts make 1, app 2, found 1"
fresh102; OUT="$(run_line102 '' "$ALINE")"
check "$(fails "$OUT")$(has "$OUT" '5.18.102 (after): PASSED')$(noteline "$OUT" QL-LK1 "for which Make the quotation applies (the Orders card's five conditions, the installed KycService::canMakeQuotation): 1 — an admin uses Make the quotation" "Apart, never in that count: 2 from the retailer app, the mobile API or a form opened before the deploy — quoted in uCRM by hand — and 1 with a quotation found in uCRM")" "0yesyes" \
  "5w' QL-LK1's window: one to quote with Make the quotation, two quoted by hand, one already quoted in uCRM — by id above QLG1_APP_MAX, never by submitted_at; MD8's registration, saved before the copy, not among them"
M="$(weak102 md10 md10)"; fresh102; OUT="$(R102_MUTANT_OUT=1 run102 --script "$M" --after-only)"; rm -f "$M"
check "$(noteline "$OUT" QL-LK1 "the installed KycService::canMakeQuotation): 1 — an admin uses")$(noteline "$OUT" QL-LK1 "the installed KycService::canMakeQuotation): 4 — an admin uses")" "noyes" \
  "5w'' MD10 — the window's Make-the-quotation count by its own three conditions, not canMakeQuotation: the app-origin, the no-entry and the found registrations counted with the web one (caught)"
M="$(weak102 md8 md8a md8b)"; fresh102; OUT="$(R102_MUTANT_OUT=1 run102 --script "$M" --after-only)"; rm -f "$M"
check "$(noteline "$OUT" QL-LK1 "the installed KycService::canMakeQuotation): 1 — an admin uses")$(noteline "$OUT" QL-LK1 "the installed KycService::canMakeQuotation): 2 — an admin uses")" "noyes" \
  "5w''' MD8 — the window chosen by submitted_at over every application: MD8's registration, saved before the copy, counted with the window's (caught)"
check "$(sqn102 "DELETE FROM kyc_applications WHERE json_extract(data, '\$._rh102win') = 1")|$(data94)" "4|$D102" \
  "…the window's four taken out again: the data as before them (the baseline taken after the admin's links), byte for byte"
# MD8's plant was saved before the deploy, so no baseline holds the links without it: its removal is proved by what it
# moved — one row out, none left, QL-RB2's P1–P3 count back to 0 — and the baseline moves with it, as with an admin's act.
check "$(sqn102 "DELETE FROM kyc_applications WHERE json_extract(data, '\$._rh102md8') = 1")|$(sq "SELECT COUNT(*) FROM kyc_applications WHERE json_extract(data, '\$._rh102md8') = 1")|$(python3 "$H102/rh102q.py" kv "$(apps102)" unp)" "1|0|pending:0,manual:0,p1:0" \
  "…and MD8's plant taken out: one row, none left, QL-RB2's P1–P3 count back to 0"
D102="$(data94)"
# ── A corrupt store (D14 derivation M-10; the one check RF-1 and SL-1): two active records for one item, which the release
# never writes, planted raw as copies of plan #1's record in force — first under an item the release does not know (no
# screen shows such a pair, so the FAIL's remedy is a repair by hand), then for plan #1 itself, an item the form offers
# (judged "two links recorded", so unpriced). QL-LK1 FAILS; no flag lifts it; the planted records taken out again leave the
# data as it was, byte for byte. ─────────────────────────────────────────────────────────────────────────────────────────
P1R102="$(sq "SELECT data FROM ucrm_quote_links WHERE json_extract(data, '\$.item') = 'plan' AND CAST(json_extract(data, '\$.row_id') AS INTEGER) = 1 AND json_extract(data, '\$.ended_at_ts') IS NULL")"
CL1_102="$(links102 "$PD" "$(python3 -c 'import json, sys; r = json.loads(sys.argv[1]); r.pop("id", None); r["_rh102c"] = True; a = dict(r, item="rh102x", row_id=1); print(json.dumps([{"op": "raw", "record": a}, {"op": "raw", "record": dict(a)}]))' "$P1R102")")"
check "$(sed -E 's/"ids":\[[0-9,]*\],//' <<<"$CL1_102")|$(lk102 "$COPY102")" '{"ops":2,"kinds":["raw","raw"],"records":7,"active":7,"two":1}|records:7,active:7,two:1|ended:0' \
  "5k the plant: two active records under an item the release does not know — raw, as no writer of the release's makes them; the store now carries one pair twice"
fresh102; OUT="$(run_line102 '' "$ALINE")"
check "$(fails "$OUT")|$(has "$OUT" '5.18.102 (after): 1 FAILED')$(failline "$OUT" QL-LK1 'the link store is corrupt: 1 item(s) carry two active link records on the copy — the release never writes two (docs/71 §R.1.4)' 'but no screen shows a pair on a Fiber row, on a row no longer in its table or under an item the release does not know, and such a pair is not relinked there: send the log file, for a repair by hand' 'No flag lifts it')$(okline "$OUT" QL-LK1 'every active, non-Fiber plan and kit the KYC form offers, and the installation, resolves')" \
      "1|yesyesyes" \
  "5k' QL-LK1 FAILS on the corrupt store, counted store-wide (M-10) though no item the form offers is judged by it, every one of them still resolving; its remedy names the repair by hand a pair no screen shows needs (the one check SL-1)"
fresh102; OUT="$(run_line102 '' "$EXP_AFTERU102")"
check "$(fails "$OUT")|$(has "$OUT" '5.18.102 (after): 1 FAILED')$(failline "$OUT" QL-LK1 'the link store is corrupt: 1 item(s) carry two active link records on the copy' 'No flag lifts it')$(has "$OUT" '  the flag          --accept-unpriced was typed and decided nothing')" \
      "1|yesyesyes" \
  "5k'' …and with --after-only --accept-unpriced: the same FAIL, which no flag lifts — the flag said to have decided nothing"
CL2_102="$(links102 "$PD" "$(python3 -c 'import json, sys; r = json.loads(sys.argv[1]); r.pop("id", None); r["_rh102c"] = True; print(json.dumps([{"op": "raw", "record": r}]))' "$P1R102")")"
check "$(sed -E 's/"ids":\[[0-9,]*\],//' <<<"$CL2_102")|$(lk102 "$COPY102")" '{"ops":1,"kinds":["raw"],"records":8,"active":8,"two":2}|records:8,active:8,two:2|ended:0' \
  "5k''' …and a second active record for plan #1, an item the form offers"
fresh102; OUT="$(run_line102 '' "$ALINE")"
check "$(fails "$OUT")|$(has "$OUT" '5.18.102 (after): 2 FAILED')$(failline "$OUT" QL-LK1 "the link store is corrupt: 2 item(s) carry two active link records on the copy — of the items the form offers judged \"two links recorded\": 1 through the plugin's own client, 1 through the token client — the release never writes two")$(failline "$OUT" QL-LK1 "not every item the KYC form offers resolves through the INSTALLED resolver by its uCRM link: through the plugin's own client 0 not linked, 1 linked but broken (two links recorded 1)")|$(qor102 "$OUT" 1 qlr)" \
      "2|yesyesyes|$(both102 "$(qlr102 two:1)")" \
  "5k'''' plan #1 judged \"two links recorded\" through both clients: the corrupt-store FAIL names it among the items the form offers, and the item, unpriced, FAILS QL-LK1 as any broken one does"
check "$(sqn102 "DELETE FROM ucrm_quote_links WHERE json_extract(data, '\$._rh102c') = 1")|$(lk102 "$COPY102")|$(data94)" "3|records:5,active:5,two:0|ended:0|$D102" \
  "…the three planted records taken out again: the link store as the admin left it, the data as before them, byte for byte"
# ── MD14 (D14 derivation M-9): rule 8 between an offered kit and the installation. set() refuses a kit or the
# installation linked to a product the other holds, so the state is reached as an admin can reach it: kit #3 deactivated
# (the Hardware screen's own act), the installation linked to kit #3's product 9104, kit #3 activated again. The judge,
# given the kit rows, sees both on one product: each broken by rule 8. Its weakened copy gives the judge no kit rows. ────
MD14L="$(links102 "$PD" '[{"op":"rowupd","file":"kyc_devices.json","id":3,"set":{"is_active":false}},{"op":"set","item":"install","product":9104,"actor":'"${ADMIN102:-0}"'},{"op":"rowupd","file":"kyc_devices.json","id":3,"set":{"is_active":true}}]')"
check "$(sed -E 's/"ids":\[[0-9,]*\],//' <<<"$MD14L")" '{"ops":3,"kinds":["updated","linked","updated"],"records":6,"active":5,"two":0}' \
  "5r the plant: kit #3 inactive, the installation relinked to 9104 (its earlier record ended as relinked), kit #3 active again — through the installed code's own writers"
fresh102; OUT="$(run_line102 '' "$ALINE")"
check "$(fails "$OUT")|$(failline "$OUT" QL-LK1 "not every item the KYC form offers resolves through the INSTALLED resolver by its uCRM link: through the plugin's own client 0 not linked, 2 linked but broken (a kit and the installation linked to one product (rule 8) 2)")|$(qor102 "$OUT" 1 qlr)" \
      "1|yes|$(both102 "$(qlr102 rule8:2)")" \
  "5r' rule 8: the offered kit and the installation on one product — both broken (rule8 2, through both clients), QL-LK1 FAILS, each one's reason on the sheet"
M="$(weak102 md14 md14)"; fresh102; OUT="$(R102_MUTANT_OUT=1 run102 --script "$M" --after-only)"; rm -f "$M"
check "$( [ -n "$(qor102 "$OUT" 1 qlr)" ] && echo read || echo unread)|$( [ "$(qor102 "$OUT" 1 qlr)" = "$(both102 "$(qlr102 rule8:2)")" ] && echo same || echo differs)" "read|differs" \
  "5r'' MD14 — the judge given no kit rows (fromRecords(links, [])): the rule-8 verdicts are not the release's (caught) — the copy's own verdicts read, so it reached its reading (the one check RC-4)"
MD14B="$(links102 "$PD" '[{"op":"set","item":"install","product":9105,"actor":'"${ADMIN102:-0}"'}]')"
check "$(sed -E 's/"ids":\[[0-9,]*\],//' <<<"$MD14B")|$(lk102 "$COPY102")" '{"ops":1,"kinds":["linked"],"records":7,"active":5,"two":0}|records:7,active:5,two:0|ended:2' \
  "…the installation linked to 9105 again, as an admin would: seven records, five active, two ended since the copy"
D102="$(data94)"
fresh102; OUT="$(run_line102 '' "$ALINE")"
check "$(fails "$OUT")$(has "$OUT" '5.18.102 (after): PASSED')$(noteline "$OUT" QL-LK1 "link records ended since the copy (ended_at_ts against COPY_AT, never ended_at): 2 — the applications quoted under them: Cash 0, Credit 0")" "0yesyes" \
  "5r''' …--after-only PASSES again; the two records ended since the copy counted by ended_at_ts, no application quoted under them"
# ── uCRM changed or unreadable after the links: a linked product priced 0 FAILS QL-LK1 by its class; Products unreadable
# FAILS QL-CA1 and QL-LK1, which no flag lifts; QL-CA1's failure weakened says ok. ─────────────────────────────────────
u99_set 'price_of={"9104":0}'; fresh102; OUT="$(run_line102 '' "$ALINE")"
check "$(fails "$OUT")|$(failline "$OUT" QL-LK1 "through the plugin's own client 0 not linked, 1 linked but broken (no price, a price of 0, more than 2 decimals, taxable, no product id or an invoice label unlike its name 1); through the token client 0 not linked, 1 linked but broken (no price, a price of 0, more than 2 decimals, taxable, no product id or an invoice label unlike its name 1), the same items judged by each")|$(grep -c "row's name in the plugin" <<<"$OUT")" \
      "1|yes|0" \
  "5c a linked product priced 0 in uCRM after the deploy: QL-LK1 FAILS, the item broken by its price, client by client, its remedy its own reason's — never the spelling rule's"
u99_set 'products_500=true'; fresh102; OUT="$(run_line102 '' "$EXP_AFTERU102")"
check "$(failline "$OUT" QL-CA1 "the catalogue through the INSTALLED resolver: unreadable through the plugin's own client (http500); unreadable through the token client (http500) — each item by name on the sheet")$(failline "$OUT" QL-LK1 "the links could not all be judged — not usable: the plugin's own client's reading; the token client's reading" 'No flag lifts it')$(has "$OUT" '5.18.102 (after): 2 FAILED')|$(grep -E '^  FAIL  ' <<<"$OUT" | grep -vcE '^  FAIL  QL-(CA1|LK1) ')" \
      "yesyesyes|0" "5d uCRM's Products unreadable at an --after-only, --accept-unpriced given: QL-CA1 and QL-LK1 FAIL, naming both clients, the flag lifting neither — and nothing else fails"
check "$(grep -cxF "                       it again — this run's reading gives no count to judge (QL-CA1 and QL-LK1 above);" <<<"$OUT")|$(has "$OUT" 'required now')" "1|no" \
  "5d' …and F's step 7 says this run's reading gives no count to judge — never \"required now\" over a catalogue it could not read"
M="$(weak102 qlca1w qlca1)"; fresh102; OUT="$(R102_MUTANT_OUT=1 run102 --script "$M" --after-only)"; rm -f "$M"
check "$(failline "$OUT" QL-CA1 'unreadable through')$(okline "$OUT" QL-CA1 'the catalogue through the INSTALLED resolver:' 'unreadable through')" "noyes" "5e QL-CA1's failure weakened: it says ok over an unreadable catalogue (caught)"
u99_set
# ── MD4 (D14 §R.7.6 item 1): the dashboard's sign-in gate broken on the stand-in — the Plans and Hardware screens, the
# pickers and Orders answering a visitor with no session with the page (200). QL-CA3 FAILS, beside the carried
# anonymous checks that read the same gate. The stand-in learns the trigger by an asserted insertion; persistent while
# its file is there, and only while 5.18.102's manifest is installed. ──────────────────────────────────────────────────
python3 - "$SB/web/public.php" <<'PY' || echo "standin:gate102" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]; s = open(p).read()
old = "$page = $_GET['page'] ?? '';\n"
assert s.count(old) == 1, s.count(old)
assert s.count('gate102') == 0
new = """if (($_GET['page'] ?? '') === 'rh102g_probe') { echo 'rh102-gate-ready'; exit; }
if (is_file($web . '/.gate102') && strpos((string)@file_get_contents($root . '/manifest.json'), '"5.18.102"') !== false && ($_GET['page'] ?? '') === 'dashboard') {
    echo '<html><body><h1>Dashboard</h1></body></html>'; exit;   // 5.18.102's rehearsal: the sign-in gate broken (MD4)
}
""" + old
s = s.replace(old, new, 1)
assert s.count("'/.gate102'") == 1 and s.count('rh102g_probe') == 1 and s.count(old) == 1
open(p, 'w').write(s)
PY
for i in $(seq 1 60); do grep -q 'rh102-gate-ready' <<<"$(pubget page=rh102g_probe)" && break; sleep 0.1; done   # PHP re-reads the edited file
check "$(pubget page=rh102g_probe)|$(ls -A "$SB/web" | grep -c '^\.gate102$')" "rh102-gate-ready|0" "control: the stand-in carries the gate trigger, unset"
# the failures of a run, by check id (C collation), and the count its verdict line says — R-18's sets, pinned whole
fset102() { printf '%s:%s' "$(grep -aE '^  FAIL  ' <<<"$1" | awk '{print $2}' | LC_ALL=C sort | paste -sd' ' -)" "$(sed -nE 's/^  5\.18\.102 \((after|deploy)\): ([0-9]+) FAILED.*/\2/p' <<<"$1" | head -1)"; }
: > "$SB/web/.gate102"; fresh102; OUT="$(run_line102 '' "$ALINE")"; rm -f "$SB/web/.gate102"
check "$(failline "$OUT" QL-CA3 'a screen where an admin links an item to uCRM, or the Orders card that makes a quotation, does not answer a visitor with no session with the sign-in page as it must:' '(code|where|marker|fatals; expected 302|login|0|00)')|$(grep -cE '^  FAIL  Q-CA5 ' <<<"$OUT")|$(ls -A "$SB/web" | grep -c '^\.gate102$')|$(fset102 "$OUT")" \
      "yes|1|0|BA4 CA4 Q-CA5 QL-CA3 V12:5" \
  "5f MD4 — the dashboard's sign-in gate broken: QL-CA3 FAILS with its tuples, beside Q-CA5, V12, BA4 and CA4 — the carried anonymous checks reading the same gate — and nothing else: 5 FAILED (R-18; the one check RC-2); the trigger removed"
# ── MD5 (D14 §R.7.6 item 2): requireAdmin() taken out of the INSTALLED post_sync.php's Make-the-quotation block — the text
# read fails beside its control, the pin's file from Git, which reads as it must; the file put back byte for byte. ────
cp -p "$PD/includes/post/post_sync.php" "$SB/postsync102.kept"
python3 - "$PD/includes/post/post_sync.php" <<'PY' || echo "md5:post_sync" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]; s = open(p).read()
old = "        $_mqMe = $auth->requireAdmin();\n"
assert s.count(old) == 1, s.count(old)
open(p, 'w').write(s.replace(old, "        $_mqMe = $auth->requireLogin();\n", 1))
PY
fresh102; OUT="$(run_line102 '' "$ALINE")"
cp -p "$SB/postsync102.kept" "$PD/includes/post/post_sync.php"; rm -f "$SB/postsync102.kept"
check "$(failline "$OUT" QL-CA3 "the installed includes/post/post_sync.php does not guard 5.18.102's link and quotation actions as the release does")|$(grep -cE '^  FAIL  Q-CA1 ' <<<"$OUT")|$(installed102 "$P102")|$(data94)|$(fset102 "$OUT")" \
      "yes|1|0|$D102|Q-CA1 QL-CA3 R1 R5:4" \
  "5g MD5 — requireAdmin() taken out of the installed kyc_make_quote block: QL-CA3 FAILS by its text read, beside Q-CA1 and R1 (the file's sha256) and R5 (every file changed since e076632, post_sync.php among them) — and nothing else: 4 FAILED (R-18 named R1 and Q-CA1; R5 measured, the one check RC-2); the file put back byte for byte, nothing else changed"
DX102="$(data94)"; fresh102; OUT="$(run102 --check)"
check "$(has "$OUT" "--check is stage A before a deploy, and the container already serves $P102 (5.18.102) — run --after-only instead")$(has "$OUT" 'Nothing was changed. Send the log file.')$(went102 "$OUT")$(has "$OUT" '5.18.102 (check): GO')|$(grep -cxF "      $EXP_AFTER102" <<<"$OUT")|$(live)|$(data94)" "yesyesnonono|1|$P102|$DX102" \
  "5h --check with 5.18.102 live: refused, the --after-only printed instead, alone; nothing changed"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "5i the quarantine's own --check after the --after-only runs, as the runbook orders: PASSED"
KEPT102="$(kept_state)"
echo; echo "== 102-6. the rollback: QL-RB2 — ok; an application saved while ROLLBACK is asked, refused as it is typed, before the copy; refused at stage A over one; the rollback over them, as the operator's choice; QL-RB1; QL-RB3 — the links left in the store, counted =="
RLINE="$(printed "$OUT102B" "cd $REPO && bash scripts/deploy-5.18.102.sh --rollback")"
check "$RLINE|$(awk '/^  5\.18\.102 \((deploy|after)\): / { v = 1 } index($0, "deploy-5.18.102.sh --rollback") { print (v ? "after" : "before") }' <<<"$OUT102B" | paste -sd, -)" "$EXP_ROLLBACK102|after" "6 the rollback command, as the --after-only's summary printed it after its verdict"
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
RUNC102="$(rows_add102 '{"kyc_applications.json":[{"_rh102":true,"_rh102unc":true,"crm_sync_status":"synced","crm_client_id":7201,"offer_price":1,"quote_unconfirmed_at":"rel:-20 minutes","submitted_at":"rel:-25 minutes"},{"_rh102":true,"_rh102unc":true,"crm_sync_status":"synced","quote_sale_entry":"web","quote_refused":"P1","quote_refused_ts":"rel:-30 minutes","offer_price":1,"quote_make_claim_ts":"rel:-15 minutes","submitted_at":"rel:-35 minutes"}]}')"
check "$RUNC102|$(unconf102)|$(python3 "$H102/rh102q.py" kv "$(apps102)" unp)|$(lk102)" '{"rows":2}|2|pending:0,manual:0,p1:0|records:7,active:5,two:0' \
  "6e-pre two applications 5.18.102 could not confirm a quotation on — quote_unconfirmed_at, and a claim a request left — as the release writes them, its refusal stamped by epoch beside its local time (quote_refused_ts), no uCRM client yet, so QL-RB2's P1 count stays 0; this rehearsal's own reading counts 2 (QL-RB3's §R.17.3 rule); the store holds the admin's seven link records, five active"
fresh102; OUT="$(R102_PLANT="$PLANT102" run_line102 ROLLBACK "$RLINE_U")"; T6E="$(last102)"; OUT102RB="$OUT"; RLOG="$(ls -1 "$OUT102"/rollback-*.log 2>/dev/null)"
check "$(cat "$R102/plant-$T6E.txt" 2>/dev/null)|$(fails "$OUT")$(has "$OUT" '5.18.102 (rollback): PASSED')$(live)$(installed102 "$P101")$(inst_ver)|$(left102)|$(reach102)" "{\"rows\":3}|0yes${P101}05.18.101|31|0" \
  "6e the three saved at the question, --rollback-unpriced-pending typed: PASSES — 5.18.101 serving, every file it changed 5.18.101's again; the 31 it added on disk, and no installed file but them names UcrmQuoteLines, the link class or its store, or carries 5.18.102's two refusals"
check "$(okline "$OUT" QL-RB2 "$RB2_OK")$(has "$OUT" "$(rb2_counts '' 0 0 0)")$(has "$OUT" "$(rb2_counts "$RB2_AGAIN" 1 1 1)")|$(starts102 "$OUT" '  note  QL-RB2 --rollback-unpriced-pending')|$(starts102 "$OUT" "  note  QL-RB2 ${RB2_AGAIN}${RB2_FLAG}")$(noteline "$OUT" QL-RB2 "${RB2_AGAIN}${RB2_MANUAL}")$(noteline "$OUT" QL-RB2 "${RB2_AGAIN}${RB2_P1}")|$(awk -v a="  unpriced apps   ${RB2_AGAIN}" -v b='  ok    container serves ' 'index($0, a) == 1 && !x { x = NR } index($0, b) == 1 && !y { y = NR } END { print ((x && y && x < y) ? "before" : "x" x "y" y) }' <<<"$OUT")|$(flagcmd102 "$OUT")|$(grep -c 'could not price' <<<"$OUT")" \
      "yesyesyes|0|1yesyes|before|0|0" \
  "6e: stage A counted none and said ok — no note; the count as ROLLBACK was typed read 1, 1, 1 and noted each in its own words, the pending ones as the operator's choice — before the copy; no command carries the flag; nothing says \"could not price\" (RH-1)"
for l in "ok    RB the installed manifest says 5.18.101" \
         "ok    RB all 38 files 5.18.102 changed are 5.18.101's again, byte for byte — by Git's sha256 and by the 38 this script pins (Q-G1's set); 31 of the 31 files it adds are on disk (deploy-hybrid.sh never deletes), reached by nothing (QL-RB1, below)" \
         "ok    RB 5.18.101's quoting code is in place" \
         "ok    QL-RB1 nothing in 5.18.101 reaches the files 5.18.102 adds: none of the 8 installed files that name its markers in 5.18.102 names one now" \
         "note  QL-RB1 31 of the 31 files 5.18.102 adds are on disk (deploy-hybrid.sh never deletes; counted above) — lib/UcrmQuoteLines.php, the resolver 5.18.102 ran on Uganda while it was live, where the quoting organization was in force, lib/UcrmQuoteLinks.php, the class of the uCRM links an admin made on the Plans and Hardware screens (the links themselves stay in the store: QL-RB3, below), and their tests, tests/test_quote_from_ucrm.php and tests/quote_from_ucrm/, which never ran on the server; none is reached by anything under 5.18.101 (above, and QL-RB3 below); a later --redeploy expects them (BR1)" \
         "note  RB the code is 5.18.101 again. The rollback restores code only. 31 of the 31 files 5.18.102 adds are on disk (deploy-hybrid.sh never deletes; counted above, QL-RB1) — lib/UcrmQuoteLines.php" \
         "a Cash sale from the retailer app or the mobile API is taken again. $RBW102 The quotation summary's sign-off stays 5.18.101's: 5.18.102 never changed webhook.php (RB above)." \
         "  what it means     on Uganda, where the quoting organization is in force, the KYC quotations and the Cash amount come again from the plugin's own plans and kits, as 5.18.101 has them; saving a plan or a kit, and Sync All, write uCRM's Products again; the Delivery Acknowledgment PDF is sent again (RB). $RBW102 Quotes already made in uCRM are not changed" \
         "(QL-RB2). 31 of the 31 files 5.18.102 adds are on disk, reached by nothing (QL-RB1). The uCRM links an admin made stay in the store — 7 record(s), 5 of them active; 5.18.101 never reads them; a later deploy of 5.18.102 finds them as they were and judges each at use (QL-RB3)." \
         "ok    QL-RB3 nothing in 5.18.101 names the uCRM link store 5.18.102 leaves: none of the 17 readings of the installed files 5.18.102 changes that name ucrm_quote_links or UcrmQuoteLinks in it finds one now, and none of the " \
         "note  QL-RB3 the uCRM links an admin made stay in the store, as a rollback restores code only (docs/71 §R.7.4): the table ucrm_quote_links, 7 record(s), 5 of them active (ended_at_ts unset), counted read-only — never through load(), which would create it; 5.18.101 never reads them (above); a later deploy of 5.18.102 finds them as they were and judges each at use (QL-G1)" \
         "note  QL-RB3 2 application(s) on which 5.18.102 could not confirm a quotation (quote_unconfirmed_at or a claim a request left, no quotation recorded; counted read-only — the rollback rewrites none, and 5.18.101 never reads the key): each may already have one in uCRM" \
         "The uCRM links an admin made stay in the store; 5.18.101 never reads them; a later deploy of 5.18.102 finds them as they were and judges each at use (QL-RB3 above). A KYC registration made since the copy with no quotation is quoted by an admin in uCRM by hand, after checking that the customer has none." \
         "note  QL-RB2 with 5.18.101 in place, the applications waiting for uCRM with no plan price recorded — no uCRM client, no quote, no lines, no plan price: as 5.18.102 saves the sales it cannot price; an older one can be so too — counted, read-only: 1 still pending for uCRM, 1 awaiting a person's retry (review or failed), 1 refused P1, P2 or P3 with a uCRM client; before the copy, this run's last count (QL-RB2's, before or as ROLLBACK was typed) read 1 pending, 1 awaiting a person's retry and 1 refused P1–P3 with a uCRM client. The rollback rewrites none of them" \
         "5.18.101's cron retries a pending one within minutes and makes its quotation with the plan line at price 0, which uCRM's quote.add webhook WhatsApps to the customer — the rollback went ahead over them on --rollback-unpriced-pending, the operator's explicit choice" \
         "lib/UcrmQuoteLines.php, the resolver that ran on Uganda while 5.18.102 was live, where the quoting organization was in force, lib/UcrmQuoteLinks.php, the class of the uCRM links an admin made on the Plans and Hardware screens, and their tests, which never ran on the server:" \
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
check "$(lk102)|$(unplant102)|$(sqn102 "DELETE FROM kyc_applications WHERE json_extract(data, '\$._rh102unc') = 1")|$(data94)|$(python3 "$H102/rh102q.py" kv "$(apps102)" unp)" "records:7,active:5,two:0|3|2|$D102|pending:0,manual:0,p1:0" \
  "…the link store exactly as the admin left it — the rollback removes none of it; the five planted applications taken out again: the data as before the plants, byte for byte — the rollback changed no data"
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
cp -p "$PD/lib/KycCrmSync.php" "$SB/sync102.kept"; git -C "$REPO" show "$P102:$P/lib/KycCrmSync.php" > "$SB/sync102.left"; chmod 644 "$SB/sync102.left"
printf '%s\t%s\n%s\t%s\n' "$SB/cron102.left" "includes/api/api_cron_debug.php" "$SB/sync102.left" "lib/KycCrmSync.php" > "$SB/web/.left102m"; chmod 644 "$SB/web/.left102m"
# …and one of the 31 added files gone from disk (review 2, E2-6): what RB, QL-RB1 and F say of the added files is the
# count on disk, never Git's. Put back byte for byte after 6i, before 7's --redeploy (BR1) needs it.
GONE102=tests/quote_from_ucrm/mutants_I.php; cp -p "$PD/$GONE102" "$SB/gone102.kept"; rm -f "$PD/$GONE102"
check "$(left102)|$(git -C "$REPO" show "$P102:$P/$GONE102" | cmp -s - "$SB/gone102.kept" && echo kept)" "30|kept" "control: one added file taken off disk (kept aside, byte for byte the pin's): 30 of the 31 there"
fresh102; OUT="$(run102 --rollback)"
check "$(has "$OUT" "the container already serves $P101 (5.18.101) — nothing to roll back; running the rollback checks")$(spent99 .left102m)|$(cmp -s "$PD/includes/api/api_cron_debug.php" "$SB/cron102.left" && cmp -s "$PD/lib/KycCrmSync.php" "$SB/sync102.left" && echo left)|$(failline "$OUT" QL-RB1 "reaches what 5.18.102 added, or its readers could not see the release:" 'includes/api/api_cron_debug.php:names-it×1')$(failline "$OUT" QL-RB1 "reaches what 5.18.102 added" 'lib/KycCrmSync.php:names-it×6')$(failline "$OUT" QL-RB3 "names the uCRM link store 5.18.102 leaves, or its readers could not see the release:" 'lib/KycCrmSync.php:names-UcrmQuoteLinks×1')$(failline "$OUT" RB "installed files that are not 5.18.101's after the rollback:" 'includes/api/api_cron_debug.php')|$( [ "$(fails "$OUT")" -ge 3 ] && echo failed)" \
      "yesspent|left|yesyesyesyes|failed" \
  "6h 5.18.101 serving, its rollback checks alone; two 5.18.102 files installed by the stand-in after stage A — api_cron_debug.php naming makeQuotation(, KycCrmSync.php naming UcrmQuoteLines and UcrmQuoteLinks: QL-RB1 FAILS naming both, QL-RB3 FAILS naming the link class, and RB's sha256 too"
check "$(noteline "$OUT" QL-RB2 'with 5.18.101 in place, the applications waiting for uCRM with no plan price recorded' "counted, read-only: 0 still pending for uCRM, 0 awaiting a person's retry (review or failed), 0 refused P1, P2 or P3 with a uCRM client; this run rolled nothing back — 5.18.101 ($P101) was already live when it began, put back by hand (which counts nothing) or by an earlier rollback — so it typed no ROLLBACK, made no copy and counted none before. The rollback rewrites none of them")$(has "$OUT" "before the copy, this run's last count")$(has "$OUT" "$(rb2_counts '' 0 0 0)")$(has "$OUT" 'Type ROLLBACK')$(has "$OUT" "wrote on applications stays as written; none still waiting for uCRM with no plan price recorded, with 5.18.101 in place (QL-RB2).")" \
      "yesnononoyes" \
  "…and RB's QL-RB2 says this run rolled nothing back — it typed no ROLLBACK, made no copy and counted none before — never a stage-A reading it did not make (review 1, QL-2/S3); no count before it, no question; F's \"what it means\" says none is waiting"
check "$(noteline "$OUT" QL-RB1 '30 of the 31 files 5.18.102 adds are on disk (deploy-hybrid.sh never deletes; counted above)')$(noteline "$OUT" RB 'The rollback restores code only. 30 of the 31 files 5.18.102 adds are on disk (deploy-hybrid.sh never deletes; counted above, QL-RB1)')$(has "$OUT" '30 of the 31 files 5.18.102 adds are on disk, and QL-RB1 FAILED: what is installed may reach them (QL-RB1)')|$(has "$OUT" '31 of the 31 files')$(has "$OUT" 'reached by nothing (QL-RB1)')" \
      "yesyesyes|nono" \
  "6h' with one added file gone, QL-RB1, RB and F each say the count on disk — 30 of the 31 — never Git's, and F, QL-RB1 having failed, never \"reached by nothing\" (review 2, E2-6)"
# Each weakened copy starts where 6h did: the two files 5.18.101's at stage A, the trigger armed to install them after it
# (else the rollback's own stage-A guard stops it before QL-RB1 and QL-RB3 — run 2); spent99 shows it got past stage A.
arm102m() { cp -p "$SB/cron102.kept" "$PD/includes/api/api_cron_debug.php"; cp -p "$SB/sync102.kept" "$PD/lib/KycCrmSync.php"; printf '%s\t%s\n%s\t%s\n' "$SB/cron102.left" "includes/api/api_cron_debug.php" "$SB/sync102.left" "lib/KycCrmSync.php" > "$SB/web/.left102m"; chmod 644 "$SB/web/.left102m"; }
arm102m; M="$(weak102 qlrb1w qlrb1)"; fresh102; OUT="$(R102_MUTANT_OUT=1 run102 --script "$M" --rollback)"; rm -f "$M"
check "$(okline "$OUT" QL-RB1 'nothing in 5.18.101 reaches the files 5.18.102 adds')$(failline "$OUT" RB "installed files that are not 5.18.101's after the rollback:" 'includes/api/api_cron_debug.php')|$(spent99 .left102m)" "yesyes|spent" \
  "6i QL-RB1's count weakened: it says nothing reaches the added files while two installed files name its markers (caught; RB's sha256 still fails)"
arm102m; M="$(weak102 qlrb3w qlrb3m qlrb3s)"; fresh102; OUT="$(R102_MUTANT_OUT=1 run102 --script "$M" --rollback)"; rm -f "$M"
check "$(okline "$OUT" QL-RB3 'nothing in 5.18.101 names the uCRM link store 5.18.102 leaves')$(failline "$OUT" QL-RB3 'names the uCRM link store')|$(spent99 .left102m)" "yesno|spent" \
  "6i' QL-RB3's marker reading and its sweep both weakened: it says nothing names the link store while KycCrmSync.php names UcrmQuoteLinks (caught)"
arm102m; M="$(weak102 qlrb3mw qlrb3m)"; fresh102; OUT="$(R102_MUTANT_OUT=1 run102 --script "$M" --rollback)"; rm -f "$M"
check "$(failline "$OUT" QL-RB3 'names the uCRM link store' 'lib/KycCrmSync.php:names-UcrmQuoteLinks×1')$(okline "$OUT" QL-RB3 'nothing in 5.18.101 names the uCRM link store')|$(spent99 .left102m)" "yesno|spent" \
  "6i'' the marker reading alone weakened: the sweep over every file 5.18.101 tracks still names it — each guard holds on its own (the control on 6i')"
cp -p "$SB/cron102.kept" "$PD/includes/api/api_cron_debug.php"; rm -f "$SB/cron102.kept" "$SB/cron102.left"
cp -p "$SB/sync102.kept" "$PD/lib/KycCrmSync.php"; rm -f "$SB/sync102.kept" "$SB/sync102.left"
cp -p "$SB/gone102.kept" "$PD/$GONE102"; rm -f "$SB/gone102.kept"
check "$(installed102 "$P101")|$(reach102)|$(live)|$(left102)" "0|0|$P101|31" "…the installed file put back: every file 5.18.102 changed 5.18.101's again, nothing reaching the added files; the added file taken off put back, 31 of the 31 on disk"
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
      "yesyesspent|$P101|0|5.18.101|31|0" \
  "6v1 V1 FAILS on the redirect (the trigger spent) and the deploy rolls itself back: 5.18.101 serving, every file 5.18.102 changes 5.18.101's again, the 31 it adds on disk and reached by nothing"
check "$(has "$OUT" "  unpriced apps   after this automatic rollback, on a copy of the book, read-only (QL-RB2): pending for uCRM 1, in review or failed 1, refused P1–P3 with a uCRM client 1 — counts only")$(noteline "$OUT" QL-RB2 "after this automatic rollback: 1 application(s) still waiting for uCRM with no plan price recorded — as 5.18.102 saves the sales it cannot price; an older one can be so too (on a copy of the book, read-only). Once 5.18.101 serves, its cron (KycCrmSync) retries each within minutes, rebuilds its plan line at price 0 and makes the quotation, and uCRM's quote.add webhook WhatsApps it to the customer. The rollback did not wait for them: the public address must never redirect")$(noteline "$OUT" QL-RB2 'after this automatic rollback: 1 application(s) in review or failed with no plan price recorded: a person' 'make their quotations in uCRM, and retry none of them by hand under 5.18.101')$(noteline "$OUT" QL-RB2 'after this automatic rollback: 1 application(s) 5.18.102 refused a quotation (P1, P2 or P3) have a uCRM client: the debug retry (retry_quote) could quote one')" \
      "yesyesyesyes" \
  "6v2 after the rollback, QL-RB2 counted on a copy: the counts line, and the hazard said for each kind — the pending one quoted at price 0 by 5.18.101's cron and WhatsApped, the review one by a person's retry, the P1 one by the debug retry"
check "$(has "$OUT" "  STOP: rolled back (serves $P101); QL-RB2 counted after it, on a copy of the book: pending for uCRM 1, in review or failed 1, refused P1–P3 with a uCRM client 1 — each pending one is quoted by 5.18.101's cron with its plan line at price 0, and uCRM WhatsApps it (the note above); the uCRM links an admin made stay in the store, and 5.18.101 never reads them; a later deploy of 5.18.102 finds them as they were and judges each at use. A KYC registration made since the copy with no quotation is quoted by an admin in uCRM by hand, after checking that the customer has none. An application on which 5.18.102 could not confirm a quotation may already have one in uCRM: check there before quoting it by hand or with retry_quote, which in 5.18.101 does not look first (docs/71 §R.7.4, §R.17.3, §R.17.6); send the log file")$(has "$OUT" 'Nothing further was done. Send the log file.')$(has "$OUT" 'did not complete')$(has "$OUT" 'may not be serving yet')|$(awk -v a='  ROLLING BACK — the public address must never redirect' -v b="  the container now serves $P101;" -v c='  unpriced apps   after this automatic rollback, ' -v d='  STOP: rolled back (serves ' 'index($0, a) == 1 && !w { w = NR } index($0, b) == 1 && !x { x = NR } index($0, c) == 1 && !y { y = NR } index($0, d) == 1 && !z { z = NR } END { print ((w && x && y && z && w < x && x < y && y < z) ? "in order" : "w" w "x" x "y" y "z" z) }' <<<"$OUT")" \
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
      "yesyesspent|0|yesyes|$P101|0|5.18.101|31|0" \
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

echo; echo "== 102-6L. the links a rolled-back run left: read at stage A through the PIN's class, judged at QL-G1 — row stamps and stored kit rows (MD6, MD7, MD12) =="
# 5.18.101 serves; the store holds the admin's seven link records, five active (5b–5r). A --check reads them on the copy
# through the pin's own lib/UcrmQuoteLinks.php, by its path (never through ICODE_A, 5.18.101's code, which has none — MD7),
# and judges each as at use: every item resolves. The plan and kit rows go to the judge in their stored shape, created_at
# among them, so that a row replaced under a reused id is told apart (MD6) and rule 8 sees the offered kits (MD12). The
# states are made as they can be made — the Hardware screen's own acts and the pin's own set() (rh102-links.php on REF102)
# — and undone the same way. ─────────────────────────────────────────────────────────────────────────────────────────────
links_pin102() { links102 "$REF102/$P" "$1"; }   # the pin's writer, for a store a rolled-back run left (5.18.101 serving)
LKL102="  note  QL-G1 the link store on the copy, read through the pin's UcrmQuoteLinks by its own path: 7 record(s), 5 active — left by an earlier, rolled-back run of 5.18.102 (docs/71 §R.7.1); each active one is judged above as at use (resolves, or broken by class), its verdict on the sheet with the record"
fresh102; OUT="$(run102 --check)"
check "$(checkgo102 "$OUT" | cut -d'|' -f1)|$(has "$OUT" "$QLG1_OK_L")|$(has "$OUT" "$LKL102")|$(qol102 "$OUT" qllinks)|$(qor102 "$OUT" 1 qlc)|$(qor102 "$OUT" 1 qlr)|$(lk102)" \
      "yesyesnono0|yes|yes|records:7,active:5,two:0|$QLC_L|$QLR_L|records:7,active:5,two:0" \
  "6L-a --check over the links a rolled-back run left: GO — the link store read through the pin's class on the copy and counted, every item resolving by its leftover link through both clients; the live store untouched"
M="$(weak102 md7 md7)"; fresh102; OUT="$(R102_MUTANT_OUT=1 run102 --script "$M" --check)"; rm -f "$M"
check "$(has "$OUT" "NO-GO (QL-G1): the quoting organization is in force, and the RELEASE's lib/UcrmQuoteLinks.php was not where the local reading loads it, the pin's own file by its path (?nolinks)")$(has "$OUT" '5.18.102 (check): GO')" "yesno" \
  "6L-b MD7 — the local reading loading the link class from ICODE_A, 5.18.101's code: nothing there, so the leftover links go unjudged — QL-G1 refuses (?nolinks) where the pin's path was right (caught)"
M="$(weak102 md6 md6)"; fresh102; OUT="$(R102_MUTANT_OUT=1 run102 --script "$M" --check)"; rm -f "$M"
check "$( [ "$(qor102 "$OUT" 1 qlr)" = "$QLR_L" ] && echo same || echo differs)|$(qor102 "$OUT" 1 qlr | sed -nE 's/^plug:.*replaced:([0-9]+).*/\1/p')" "differs|4" \
  "6L-c MD6 — the row stamp dropped from the offer's link records: every leftover plan and kit link judged \"linked before its row was replaced\" (replaced 4 through the plugin's own client) over rows nobody replaced (caught)"
KIT3_102="$(sq "SELECT data FROM kyc_devices WHERE id = 3")"
RPL102="$(links_pin102 "$(python3 -c 'import json, sys; r = json.loads(sys.argv[1]); r.pop("id", None); r["created_at"] = "rel:-1 hour"; print(json.dumps([{"op": "rowdel", "file": "kyc_devices.json", "id": 3}, {"op": "rowadd", "file": "kyc_devices.json", "row": r}]))' "$KIT3_102")")"
check "$(sed -E 's/"ids":\[[0-9,]*\],//' <<<"$RPL102")" '{"ops":2,"kinds":["deleted:1","added:3"],"records":7,"active":5,"two":0}' \
  "6L-d the plant: kit #3 deleted and added again — the store's MAX(id)+1 gives it id 3 back, a new created_at; its link record stays as 5.18.101 leaves it (no link screen)"
fresh102; OUT="$(run102 --check)"
check "$(checkgo102 "$OUT" | cut -d'|' -f1)|$(qor102 "$OUT" 1 qlr)|$(qor102 "$OUT" 1 qlc)" "yesyesnono0|$(both102 "$(qlr102 replaced:1)")|$(both102 "$(qlc102 5 4 1 0 1)")" \
  "6L-d' …the kit under a reused id: its leftover link judged \"linked before its row was replaced\" — one item broken, through both clients; QL-G1 still GO (a broken item before the copy is a note, D14 §R.7.1)"
M="$(weak102 md6 md6)"; fresh102; OUT="$(R102_MUTANT_OUT=1 run102 --script "$M" --check)"; rm -f "$M"
check "$( [ -n "$(qor102 "$OUT" 1 qlr)" ] && echo read || echo unread)|$( [ "$(qor102 "$OUT" 1 qlr)" = "$(both102 "$(qlr102 replaced:1)")" ] && echo same || echo differs)" "read|differs" "6L-d'' MD6 over the replaced row: not the release's verdicts either (caught) — the copy's own verdicts read, so it reached its reading (the one check RC-4)"
RBK102="$(links_pin102 "$(python3 -c 'import json, sys; r = json.loads(sys.argv[1]); r.pop("id", None); print(json.dumps([{"op": "rowdel", "file": "kyc_devices.json", "id": 3}, {"op": "rowadd", "file": "kyc_devices.json", "row": r}]))' "$KIT3_102")")"
check "$(sed -E 's/"ids":\[[0-9,]*\],//' <<<"$RBK102")|$(sq "SELECT data FROM kyc_devices WHERE id = 3" | python3 -c 'import json, sys; a = json.loads(sys.stdin.read()); b = json.loads(sys.argv[1]); a.pop("id", None); b.pop("id", None); print("same" if a == b else "differs")' "$KIT3_102")" \
      '{"ops":2,"kinds":["deleted:1","added:3"],"records":7,"active":5,"two":0}|same' "…kit #3 put back as it was, id 3, every field as before"
# MD12: rule 8 among the leftovers — kit #3 deactivated, the installation linked to kit #3's product through the pin's
# set(), kit #3 activated again (5.18.101's Hardware screen has no link column, so nothing stops it): both broken.
R8L102="$(links_pin102 '[{"op":"rowupd","file":"kyc_devices.json","id":3,"set":{"is_active":false}},{"op":"set","item":"install","product":9104,"actor":'"${ADMIN102:-0}"'},{"op":"rowupd","file":"kyc_devices.json","id":3,"set":{"is_active":true}}]')"
check "$(sed -E 's/"ids":\[[0-9,]*\],//' <<<"$R8L102")" '{"ops":3,"kinds":["updated","linked","updated"],"records":8,"active":5,"two":0}' "6L-e the plant: the installation and offered kit #3 on one product among the leftovers"
fresh102; OUT="$(run102 --check)"
check "$(checkgo102 "$OUT" | cut -d'|' -f1)|$(qor102 "$OUT" 1 qlr)" "yesyesnono0|$(both102 "$(qlr102 rule8:2)")" \
  "6L-e' …QL-G1 judges both by rule 8 — the kit rows in their stored shape — a note before the copy, GO"
M="$(weak102 md12 md12a md12b)"; fresh102; OUT="$(R102_MUTANT_OUT=1 run102 --script "$M" --check)"; rm -f "$M"
check "$(qor102 "$OUT" 1 qlr | sed -nE 's/^plug:.*rule8:([0-9]+).*/\1/p')" "0" \
  "6L-f MD12 — the plan and kit rows rebuilt in another shape for the judge: rule 8 no longer seen (rule8 0) over the same leftovers (caught)"
R8B102="$(links_pin102 '[{"op":"set","item":"install","product":9105,"actor":'"${ADMIN102:-0}"'}]')"
check "$(sed -E 's/"ids":\[[0-9,]*\],//' <<<"$R8B102")|$(lk102)" '{"ops":1,"kinds":["linked"],"records":9,"active":5,"two":0}|records:9,active:5,two:0' \
  "…the installation linked to 9105 again through the pin's set(): nine records, five active"
D102="$(data94)"; KEPT102="$(kept_state)"

echo; echo "== 102-7. --redeploy over the leftovers, with links written while it runs; its --after-only in two zones (MD2, MD13, MD3) =="
# The leftovers made two hours earlier (every record's epoch and display string moved back together), and plan Beta's link
# ended through the pin's end(): stage A reads plan #1, kit #1, kit #3 and the installation linked, plan #2 not. While
# the deploy runs — at the making of the quoting reading's folder after the copy, through the INSTALLED code's writer —
# an admin links plan #2 (set) and kit #1 is relinked to the installation's product (force: set() refuses it, rule 8).
# QL-CA1 must leave out plan #2 (its own records changed), kit #1 (relinked) and, by rule 8, the installation (its
# product is the new one of a changed kit record): 3 left out, the unchanged leftovers compared, and never "linked since
# stage A" for them. MD2 compares the changed items strictly; MD13 drops the rule-8 closure: each FAILS its deploy. ─────
rb102() {   # rb102 LABEL — the rollback, ROLLBACK typed, nothing waiting: PASSED, 5.18.101 serving; then the quarantine's --check
  local o; fresh102; o="$(run102 --answer ROLLBACK --rollback)"
  check "$(fails "$o")$(has "$o" '5.18.102 (rollback): PASSED')$(live)$(installed102 "$P101")|$(left102)|$(reach102)" "0yes${P101}0|31|0" "$1 the rollback, ROLLBACK typed: PASSED — 5.18.101 serving, its files back, the 31 added on disk and reached by nothing"
  o="$(qrun -- --check)"
  check "$(has "$o" 'stray-store quarantine (check): PASSED.')" "yes" "…the quarantine's own --check after it, as A14 asks of the next deploy: PASSED"
  KEPT102="$(kept_state)"
}
PRE7102="$(links_pin102 '[{"op":"end","item":"plan","row":2,"why":"unlinked","actor":'"${ADMIN102:-0}"'}]')"
check "$(sed -E 's/"ids":\[[0-9,]*\],//' <<<"$PRE7102")" '{"ops":1,"kinds":["ended:1"],"records":9,"active":4,"two":0}' \
  "7-pre plan #2's link ended (unlinked) through the pin's end() — four active leftovers"
D102="$(data94)"
PLANT7_BOTH='[{"op":"set","item":"plan","row":2,"product":9102,"plan":502,"actor":@A@},{"op":"force","item":"kit","row":1,"product":9105,"actor":@A@}]'; PLANT7_BOTH="${PLANT7_BOTH//@A@/${ADMIN102:-0}}"
PLANT7_BETA='[{"op":"set","item":"plan","row":2,"product":9102,"plan":502,"actor":@A@}]'; PLANT7_BETA="${PLANT7_BETA//@A@/${ADMIN102:-0}}"
PLANT7_KIT='[{"op":"force","item":"kit","row":1,"product":9105,"actor":@A@}]'; PLANT7_KIT="${PLANT7_KIT//@A@/${ADMIN102:-0}}"
plantout102() { sed -E 's/"ids":\[[0-9,]*\],//' "$SB/plant102.out" 2>/dev/null; }
# MD2 — the strict comparison: plan #2 linked while the deploy runs is compared and FAILS QL-CA1
M="$(weak102 md2 md2)"; plant102 "$PD" "$PLANT7_BETA"; fresh102; OUT="$(R102_MUTANT_OUT=1 run102 --script "$M" --answer DEPLOY --redeploy)"; rm -f "$M"
check "$(ls -A "$SB" | grep -c '^\.qorplant102$')|$(plantout102 | sed -E 's/,"records".*//')|$(failline "$OUT" QL-CA1 "the INSTALLED resolver's verdicts are not stage A's, item by item")|$(live)" \
      '0|{"ops":1,"kinds":["linked"]|yes|'"$P102" \
  "7-MD2 the strict comparison — no item left out for its own changed records: plan #2, linked while the deploy ran, compared and the deploy's QL-CA1 FAILS (caught; the plant spent at the reading's folder)"
rb102 "7-MD2'"
check "$(sed -E 's/"ids":\[[0-9,]*\],//' <<<"$(links_pin102 '[{"op":"end","item":"plan","row":2,"why":"unlinked","actor":'"${ADMIN102:-0}"'}]')")" '{"ops":1,"kinds":["ended:1"],"records":10,"active":4,"two":0}' "…plan #2 unlinked again, as before 7-MD2"
# MD13 — no rule-8 closure: kit #1 relinked to the installation's product while the deploy runs; the installation is compared
M="$(weak102 md13 md13)"; plant102 "$PD" "$PLANT7_KIT"; fresh102; OUT="$(R102_MUTANT_OUT=1 run102 --script "$M" --answer DEPLOY --redeploy)"; rm -f "$M"
check "$(ls -A "$SB" | grep -c '^\.qorplant102$')|$(plantout102 | sed -E 's/,"records".*//')|$(failline "$OUT" QL-CA1 "the INSTALLED resolver's verdicts are not stage A's, item by item")|$(live)" \
      '0|{"ops":1,"kinds":["forced"]|yes|'"$P102" \
  "7-MD13 the comparison without rule 8's closure: the installation, whose product kit #1 now names, compared — its verdict broken by rule 8 now — and QL-CA1 FAILS (caught)"
rb102 "7-MD13'"
check "$(sed -E 's/"ids":\[[0-9,]*\],//' <<<"$(links_pin102 '[{"op":"set","item":"kit","row":1,"product":9103,"actor":'"${ADMIN102:-0}"'}]')")|$(lk102)" '{"ops":1,"kinds":["linked"],"records":12,"active":4,"two":0}|records:12,active:4,two:0' \
  "…kit #1 linked to 9103 again through the pin's set(), as before 7-MD13"
D102="$(data94)"
# Every record two hours earlier, its epoch and its display string together (the shift), once and only now: stage A of the
# next run reads them all, and an --after-only with the copy thirty minutes back must still find each one before it —
# while a Kampala display string read as UTC lands after it (MD3's UTC run, 7f-utc).
SH7102="$(links_pin102 '[{"op":"shift","by":-7200}]')"
check "$(sed -E 's/"ids":\[[0-9,]*\],//' <<<"$SH7102")" '{"ops":1,"kinds":["shift:12"],"records":12,"active":4,"two":0}' "7-pre' every one of the twelve records moved two hours back"
D102="$(data94)"
# The release's own comparison, both plants at once
plant102 "$PD" "$PLANT7_BOTH"; fresh102; OUT="$(run102 --answer DEPLOY --redeploy)"; OUT102R="$OUT"; T102R="$(last102)"
check "$(fails "$OUT")$(has "$OUT" '5.18.102 (deploy): PASSED')$(live)$(installed102 "$P102")$(inst_ver)|$(ls -A "$SB" | grep -c '^\.qorplant102$')|$(plantout102 | sed -E 's/,"records".*//')" \
      "0yes${P102}05.18.102|0|"'{"ops":2,"kinds":["linked","forced"]' \
  "7a --redeploy, DEPLOY typed, links written while it ran: PASSED, $P102 serving, every file as the pin has it; the plant spent at the reading's folder, after stage B"
check "$(okline "$OUT" BR1 'the installed plugin is live 5.18.101' '; 31 of the 31 files 5.18.102 adds are already on disk, left by an earlier 5.18.102 and its rollback (deploy-hybrid.sh never deletes) — the copy replaces them')$(cmp102 "$(qor102 "$OUT" 2 qlcmp)" | sed -E 's/^same:[1-9][0-9]*,/same:n,/')|$(noteline "$OUT" QL-CA1 'linked since stage A: 2 link record(s); ended or relinked since stage A: 1 — 3 item(s) left out of the comparison — their own link records changed, or, by rule 8, a kit'"'"'s or the installation'"'"'s did')" \
      "yessame:n,differs:0,new:0,gone:0,left:3,linked:2,ended:1|yes" \
  "7b BR1's clause, 31 of the 31 on disk; QL-CA1 leaves out plan #2, kit #1 and — by rule 8 — the installation, compares the unchanged leftovers equal, and notes the two records linked and the one relinked since stage A"
check "$(has "$OUT" "${LKL102/7 record(s), 5 active/12 record(s), 4 active}")|$(okline "$OUT" QL-G1 'the catalogue the KYC form offers is read and judged')" "yes|yes" \
  "7b' stage A read the leftovers through the pin's class — twelve records, four active — and said so; QL-G1 ok"
copy_back102 30; COPY102R="$(sed -n 's/^COPY_AT=//p' "$STATE102" | tail -1)"
[ "$(epoch99 "$COPY102R")" = "epoch" ] && set_sched $(( COPY102R + 300 )); base_log
ALINE2="$(printed "$OUT102R" "cd $REPO && bash scripts/deploy-5.18.102.sh --after-only")"
fresh102; OUT="$(run_line102 '' "$ALINE2")"
check "$ALINE2|$(fails "$OUT")|$(failline "$OUT" QL-LK1 "through the plugin's own client 0 not linked, 2 linked but broken (a kit and the installation linked to one product (rule 8) 2)")|$(noteline "$OUT" QL-LK1 "never linked_at: 2 made since the copy, 12 read by stage A (left by an earlier, rolled-back run)")$(noteline "$OUT" QL-LK1 'whose linked_at_ts disagrees with its identity')" \
      "$EXP_AFTER102|1|yes|yesno" \
  "7c its --after-only, as printed: QL-LK1 FAILS on rule 8 (kit #1 and the installation on one product), and tells the two records made since the copy from the twelve stage A read, by identity — no disagreement"
FIX7102="$(links102 "$PD" '[{"op":"set","item":"kit","row":1,"product":9103,"actor":'"${ADMIN102:-0}"'}]')"
check "$(sed -E 's/"ids":\[[0-9,]*\],//;s/,"records".*//' <<<"$FIX7102")" '{"ops":1,"kinds":["linked"]' "7c' an admin relinks kit #1 to its own product, through the installed release's set()"
D102="$(data94)"
fresh102; OUT="$(run_line102 '' "$ALINE2")"
check "$(fails "$OUT")$(has "$OUT" '5.18.102 (after): PASSED')$(okline "$OUT" QL-LK1 "resolves through the INSTALLED resolver by its uCRM link" "$SAY_L")$(noteline "$OUT" QL-LK1 "never linked_at: 3 made since the copy, 12 read by stage A")$(noteline "$OUT" QL-LK1 'whose linked_at_ts disagrees with its identity')" "0yesyesyesno" \
  "7d the --after-only again: PASSED — every item resolving; three records made since the copy (the relinked kit's two among them), twelve stage A read, by identity; no disagreement"
# MD3 (D14 derivation M-11): the same --after-only in two zones — PHP's date.timezone UTC and Etc/GMT-5 (the records' display
# strings are Africa/Kampala's) — through an ini folder added to PHP's scan path. The release decides by identity, with
# linked_at_ts as the cross-check: the same counts and no disagreement note in either zone. The copy that reads linked_at
# (strtotime of the display string) disagrees in both.
mkdir -p "$H102/tz-utc" "$H102/tz-gmt5"; printf 'date.timezone = UTC\n' > "$H102/tz-utc/zz-rh102-tz.ini"; printf 'date.timezone = Etc/GMT-5\n' > "$H102/tz-gmt5/zz-rh102-tz.ini"; chmod -R a+rX "$H102/tz-utc" "$H102/tz-gmt5"
for z in utc gmt5; do
  fresh102; OUT="$(run102 --env "PHP_INI_SCAN_DIR=:$H102/tz-$z" --after-only)"
  check "$(fails "$OUT")$(has "$OUT" '5.18.102 (after): PASSED')$(noteline "$OUT" QL-LK1 "never linked_at: 3 made since the copy, 12 read by stage A")$(noteline "$OUT" QL-LK1 'whose linked_at_ts disagrees with its identity')" "0yesyesno" \
    "7e-$z the --after-only under PHP's date.timezone $z: the same counts by identity, no disagreement note (MD3, M-11)"
  M="$(weak102 md3 md3)"; fresh102; OUT="$(R102_MUTANT_OUT=1 run102 --script "$M" --env "PHP_INI_SCAN_DIR=:$H102/tz-$z" --after-only)"; rm -f "$M"
  check "$(noteline "$OUT" QL-LK1 'whose linked_at_ts disagrees with its identity')" "yes" "7f-$z MD3 — linked_at read as a time in PHP's zone $z: the Kampala display strings disagree with their identity (caught)"
done
rm -rf "$H102/tz-utc" "$H102/tz-gmt5"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "7g the quarantine's own --check, as the runbook orders: PASSED"
KEPT102="$(kept_state)"
after_back102() {   # after_back102 — the copy thirty minutes back and master's retry job after it, as 5 does, so that R19 is conclusive
  copy_back102 30; local c; c="$(sed -n 's/^COPY_AT=//p' "$STATE102" | tail -1)"
  [ "$(epoch99 "$c")" = "epoch" ] && set_sched $(( c + 300 )); base_log
}
QNR102="not read after the copy (Q-CA2 above says why)"
QORF102="  FAIL  Q-CA2 Q-CA3 the quoting checks could not be read on a copy and from uCRM — this reading's folder could not be made. Every copy is removed"
QLRF102="  FAIL  QL-CA1 the catalogue could not be read after the copy: the quoting checks' reading through the installed code did not complete (this reading's folder could not be made)"
QLLF102="  FAIL  QL-LK1 the links were not judged: the reading through the installed code did not complete (this reading's folder could not be made). No flag lifts it"
fq102() { grep -E '^  FAIL  ' <<<"$1" | grep -vxF -e "$QORF102" -e "$QLRF102" -e "$QLLF102" | grep -c .; }   # fq102 OUTPUT — FAIL lines other than the reading's three
stagea102() {   # stagea102 OUTPUT — F said on stage A's reading (review 2, E2-2, L2): what changed, the quoting org. line, the screens, the steps; then how many off/unknown words
  echo "$(has "$1" "  what changed      on this Uganda server (Q-G2), the quoting organization #1 in force as stage A read it (Q-G3) — $QNR102; the --after-only below reads it again: the KYC quotations take their lines and prices from uCRM, through one resolver")$(grep -cxF "  quoting org.      #1 in force as stage A read it (Q-G3) — $QNR102: the screens and your steps below are said on that reading" <<<"$1")$(has "$1" "  the screens       on Uganda, signed in: the Plans and Hardware screens carry a \"uCRM link\" column, with Link,")$(has "$1" "  your steps        yours alone — this script does none of them (docs/71 §M, as §R.6 amends it):")|$(grep -cE '^  what changed      on this server the quoting organization is not in force|^  the screens       none changes|^  your steps        none now|^  the screens       not stated|none in force|NOT ESTABLISHED' <<<"$1")"
}

echo; echo "== 102-8. the quoting reading after the copy failing: F says so and keeps stage A's narrative — an --after-only and a deploy; --accept-unpriced over a kind uCRM offered nothing linkable for =="
: > "$SB/.qorfail102"; fresh102; OUT="$(run_line102 '' "$ALINE2")"
check "$(ls -A "$SB" | grep -c '^\.qorfail102$')|$(grep -cxF "$QORF102" <<<"$OUT")$(grep -cxF "$QLRF102" <<<"$OUT")$(grep -cxF "$QLLF102" <<<"$OUT")$(noteline "$OUT" QL-CA2 'the salesPerson attribute was not read after the copy')|$(fq102 "$OUT")|$(has "$OUT" '5.18.102 (after): 3 FAILED')" \
      "0|111yes|0|yes" \
  "8a the --after-only with the reading after the copy refused (the trigger spent): Q-CA2/Q-CA3, QL-CA1 and QL-LK1 FAIL saying why — QL-LK1's \"no flag lifts it\" — QL-CA2 not read, and nothing else fails"
check "$(stagea102 "$OUT")|$(grep -cxF "                       it again — this run's reading gives no count to judge (QL-CA1 and QL-LK1 above);" <<<"$OUT")" "yes1yesyes|0|1" \
  "8a' F keeps stage A's narrative, said as stage A's (the deploy's state file) and as not read after the copy — never \"not in force\", \"none changes\" or \"none now\"; step 7: no count to judge"
M="$(weak102 fgatew fgate)"; : > "$SB/.qorfail102"; fresh102; OUT="$(R102_MUTANT_OUT=1 run102 --script "$M" --after-only)"; rm -f "$M"
check "$(ls -A "$SB" | grep -c '^\.qorfail102$')|$(stagea102 "$OUT" | cut -c1-2)|$(has "$OUT" '  what changed      on this server the quoting organization is not in force')$(has "$OUT" '  your steps        none now')" "0|no|yesyes" \
  "8a'' the summary's gate weakened to read a reading not made as \"off\": the same run says the quoting organization is not in force, and no steps — 8a' does not hold on it (caught)"
rb102 "8b"
# 8c: a deploy on --accept-unpriced over a kind uCRM offers nothing linkable for (plans: the Service Plans reduced to #503),
# the reading after its copy refused: QL-G1 the flag's note, F's own sentence for it and step 7's (prepare uCRM first).
u99_set "plans=$PLANZ102"; : > "$SB/.qorfail102"; fresh102; OUT="$(run102 --answer DEPLOY --redeploy --accept-unpriced)"; OUT102X="$OUT"
check "$(ls -A "$SB" | grep -c '^\.qorfail102$')|$(live)|$(noteline "$OUT" QL-G1 "--accept-unpriced, the operator's explicit choice: uCRM offers nothing an admin could link for $CANDNO_P")|$(grep -cxF "$QORF102" <<<"$OUT")$(grep -cxF "$QLRF102" <<<"$OUT")|$(fq102 "$OUT")|$(grep -c '^QLG1_NOTED=1$' "$STATE102")" \
      "0|$P102|yes|11|0|1" \
  "8c the deploy (--redeploy --accept-unpriced, no plan pair in uCRM), the reading after its copy refused: QL-G1 the flag's note; 5.18.102 serving; Q-CA2/Q-CA3 and QL-CA1 FAIL and nothing else; the state file records that the flag decided QL-G1"
check "$(stagea102 "$OUT")|$(has "$OUT" " The deploy went ahead on --accept-unpriced — the operator's explicit choice (QL-G1): uCRM offered nothing an admin could link for a kind of item, which stays unpriced, whatever an admin links, until uCRM is prepared as docs/71 §M step 3 lists and its items are linked (your steps, 7, below).")|$(grep -cF 'the deploy went ahead on --accept-unpriced (QL-G1) over a kind uCRM offered nothing linkable for: prepare uCRM first, as step 3 lists;' <<<"$OUT")" \
      "yes1yesyes|0|yes|1" \
  "8c' F on stage A's reading (E2-2, L2), with the flag's own sentence and step 7's — prepare uCRM first — in the flag's D14 meaning"
u99_set; after_back102; ALINE3="$(printed "$OUT102X" "cd $REPO && bash scripts/deploy-5.18.102.sh --after-only")"
fresh102; OUT="$(run_line102 '' "$ALINE3")"
check "$ALINE3|$(fails "$OUT")$(has "$OUT" '5.18.102 (after): PASSED')$(okline "$OUT" QL-LK1 'resolves through the INSTALLED resolver by its uCRM link')" "$EXP_AFTER102|0yesyes" \
  "8d uCRM's Service Plans back: its --after-only as printed PASSES, every item resolving by its link"
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
check "$(has "$OUT" "  what changed      on this server the quoting organization is not in force (Q-G3: ucrm_quote_organization_id 0/off, the operator's choice), so 5.18.102 quotes as 5.18.101 does (D7)")$(grep -cxF "  the screens       none changes: with the quoting organization not in force, the KYC form, Send Quotation, the" <<<"$OUT")$(grep -cxF "  your steps        none now: with the quoting organization not in force, 5.18.102 quotes as" <<<"$OUT")$(grep -cxF "                    5.18.101 does. This run did NOT read the catalogue: with no quoting organization in" <<<"$OUT")$(grep -cxF "                    force its probe reads none (QL-G1, QL-N7 and QL-CA1 above), and its sheet names" <<<"$OUT")$(grep -cxF "                    refuses once 5.18.102 serves, and an --after-only's probe reads none either — so" <<<"$OUT")$(grep -cxF "                    unpriced at its retry (E-1, D11): the Orders card shows P1 or P2 — each is quoted by hand in" <<<"$OUT")|$(has "$OUT" "the quoting organization — none in force, as under 5.18.101 (Q-G3, Q-CA2) — the company every quote is made on")$(has "$OUT" "5.18.101's byte for byte (Q-G1, Q-CA1); every number in the registry")$(has "$OUT" 'NOT ESTABLISHED')$(has "$OUT" '  quoting org.      ')" \
      "yes111111|yesyesnono" \
  "9d'' F's own words for the gate off: 5.18.102 quotes as 5.18.101 does, no screen changes, no step now — and that this run read no catalogue, that no later run can while the setting is off, and what to check by hand before it is turned on (review 2, L8); the quoting organization none in force, as under 5.18.101; no D9 clause"
after_back102; ALINE9="$(printed "$OUT102Q" "cd $REPO && bash scripts/deploy-5.18.102.sh --after-only")"
fresh102; OUT="$(run_line102 '' "$ALINE9")"
check "$ALINE9|$(fails "$OUT")$(has "$OUT" '5.18.102 (after): PASSED')$(grep -cxF "$QLCA1_OFF" <<<"$OUT")$(grep -cxF "  note  $QLCA2_OFF" <<<"$OUT")|$(grep -c '^  ok    QL-CA2 ' <<<"$OUT")|$(grep -cxF "                    force its probe reads none (QL-CA1 and QL-LK1 above), and its sheet names" <<<"$OUT")$(grep -cxF "  your steps        none now: with the quoting organization not in force, 5.18.102 quotes as" <<<"$OUT")" \
      "$EXP_AFTER102|0yes11|0|11" \
  "9e its --after-only as printed: PASSES; QL-CA1 not asked; QL-CA2 the attribute, not used — never an ok; F's off words, this run's probe named alone (QL-CA1)"
check "$(noteline "$OUT" QL-LK1 'not asked: no quoting organization is in force (Q-CA2) — 5.18.102 then quotes as 5.18.101 does, nothing is priced from uCRM, and no link is used')$(grep -c '^  FAIL  QL-LK1 ' <<<"$OUT")" "yes0" \
  "9e' …QL-LK1 not asked with the quoting organization off: no link is used — a note, never a FAIL, though links are in the store"
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
  "10d no output, log or state file names a client id the app holds, an account name or number — or carries an owner check hash label (gh=, oroles=) or a qlh= field — the values of the catalogue hashes, which the state file holds since M-1, are swept by the next check"
printf 'planted %s, %s, %s, client %s, %s, %s, %s and %s\n' "$(grep -m1 '^2:' "$SB/forms102.txt" | cut -c3-)" "$W96" "$(head -1 "$U99_SECRETS")" "$(head -1 "$SB/ids99.pat")" "RH100SECRET-Tech-One" "$(head -1 "$NAMES102")" "$(tail -1 "$NOWHERE102")" "$(head -1 "$SALTS102")" > "$R102/zz-sweep-control.txt"
printf 'priced %s\n' "$(python3 -c 'import sys; print("{:,.2f}".format(float(sys.argv[1])))' "$(head -1 "$AMOUNTS102")")" > "$R102/zz-price-control.txt"
check "$(figs102 "$R102/zz-sweep-control.txt")$(figs102 "$R102/zz-price-control.txt")$(grep -lF -f "$SCAN96" "$R102/zz-sweep-control.txt" | grep -c .)$(secrets99 "$R102/zz-sweep-control.txt")$(noids99 "$R102/zz-sweep-control.txt")$(names100 "$R102/zz-sweep-control.txt")$(grep -lF -f "$NAMES102" "$R102/zz-sweep-control.txt" | grep -c .)$(grep -lF -f "$NOWHERE102" "$R102/zz-sweep-control.txt" | grep -c .)$(grep -lF -f "$SALTS102" "$R102/zz-sweep-control.txt" | grep -c .)" "111111111" \
  "control on the sweeps: a file holding one figure form, one holding a seeded price, the planted secret, a stand-in secret, a client id, a seeded staff name, a seeded catalogue name, a name meant for nowhere and a run key is found by all nine"
rm -f "$R102/zz-sweep-control.txt" "$R102/zz-price-control.txt" "$SB/sec102.pat"
# The names the link writer records (row_name, product_name, linked_by_*): read by nothing of the script (SPEC §2.4,
# "Never"), so in no terminal, log, state file or sheet — the store holding them is the control.
# The QL key (D14 derivation M-1): one QL_KEY= line in its own 0600 file per deploy, kept beside as .prev on the next — in no terminal, log, state file or sheet. Each with its control.
cat "$OUT102"/ql-key-5.18.102* 2>/dev/null | sed -n 's/^QL_KEY=//p' | sort -u > "$SB/qlkeys102.pat"; chmod 600 "$SB/qlkeys102.pat"
printf 'linked %s%s\n' "$(head -1 "$SB/linknames102.pat")" "9104" > "$R102/zz-link-control.txt"; printf 'key %s\n' "$(head -1 "$SB/qlkeys102.pat")" > "$R102/zz-key-control.txt"
check "$(sweep_files102 | xargs grep -lF -f "$SB/linknames102.pat" 2>/dev/null | grep -c .)|$(sheets102 | xargs grep -lF -f "$SB/linknames102.pat" 2>/dev/null | grep -c . | awk '{print ($1 >= 1 ? "sheets" : "none")}')|$(sq "SELECT COUNT(*) FROM ucrm_quote_links WHERE data LIKE '%RH102QSECRET-linkname-%' AND data LIKE '%RH102QSECRET-admin%'" | awk '{print ($1 ~ /^[0-9]+$/ && $1 + 0 >= 5 ? "store" : "few:" $1)}')|$(grep -c . "$SB/qlkeys102.pat" | awk '{print ($1 >= 4 ? "keys" : "few:" $1)}')|$( { sweep_files102; sheets102; } | xargs grep -lF -f "$SB/qlkeys102.pat" 2>/dev/null | grep -c .)|$(grep -lF -f "$SB/linknames102.pat" "$R102/zz-link-control.txt" | grep -c .)$(grep -lF -f "$SB/qlkeys102.pat" "$R102/zz-key-control.txt" | grep -c .)|$(for f in "$OUT102"/ql-key-5.18.102*; do stat -c %a "$f"; done | sort -u | paste -sd, -)" \
      "0|none|store|keys|0|11|600" \
  "10h no link record's stored names (product, row, admin) in any terminal, log, state file or sheet — the script never reads them (SPEC §2.4), and the live store holds them; no QL key of any deploy in any output, log, state file or sheet — each in its own file, mode 0600; controls: a file holding either is caught"
rm -f "$R102/zz-link-control.txt" "$R102/zz-key-control.txt" "$SB/qlkeys102.pat"
# The catalogue checks' keyed hashes (D14 derivation M-1, D-R5; the one check RC-5): QLG1_ITEMS= and QLG1_RECS= in the state
# file, mode 0600 — by value in no terminal, log, sheet or request record; the state files, which hold them, are the control.
sed -n 's/^QLG1_\(ITEMS\|RECS\)=//p' "$OUT102"/state-5.18.102.env* 2>/dev/null | tr ',:' '\n\n' | grep -xE '[0-9a-f]{32}' | sort -u > "$SB/qlhash102.pat"; chmod 600 "$SB/qlhash102.pat"
printf 'hashed %s\n' "$(head -1 "$SB/qlhash102.pat")" > "$R102/zz-hash-control.txt"
check "$(grep -c . "$SB/qlhash102.pat" | awk '{print ($1 >= 10 ? "hashes" : "few:" $1)}')|$( { sweep_files102 | grep -vE '/(rollback-)?state-'; sheets102; ls -1 "$R102"/ucrm/run-*.req; } | xargs grep -lF -f "$SB/qlhash102.pat" 2>/dev/null | grep -c .)|$(grep -lF -f "$SB/qlhash102.pat" "$R102/zz-hash-control.txt" | grep -c .)" "hashes|0|1" \
  "10d' no keyed hash of the catalogue checks, by value (QLG1_ITEMS, QLG1_RECS), in any terminal, log, sheet or request record of any run (D14 derivation M-1, D-R5; the one check RC-5); the state files hold them, ten and more (the control), and a file holding one is found"
rm -f "$R102/zz-hash-control.txt" "$SB/qlhash102.pat"
# MD1's negative sweep (D14 §R.7.1): no terminal or log of any run says the superseded remedy "the row's name in the
# plugin" — the sheets are never swept for it (a name on them is not a remedy); the positive control is MD1's own
# terminal, kept apart in r102/md1 (c9).
terms102() { ls -1 "$R102"/run-*.txt "$R102"/line-*.txt "$R102"/mutants/run-*.txt "$R102"/logs/*.log "$OUT102"/*.log 2>/dev/null; }
check "$(terms102 | xargs grep -lF "row's name in the plugin" 2>/dev/null | grep -c .)|$( [ "$(terms102 | grep -c .)" -ge 20 ] && echo many)|$(grep -lF "row's name in the plugin" "$R102/md1"/run-*.txt 2>/dev/null | grep -c . | awk '{print ($1 >= 1 ? "control" : "none")}')" "0|many|control" \
  "10i no terminal or log of any run holds the superseded remedy \"the row's name in the plugin\" — the sheets not swept for it; control: MD1's terminal, kept apart, holds it"
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
RH1_OK102="                    differs, and draws, for an admin, Make the quotation on a web registration left unpriced;"
printf '%s\n' '  note  QL-RB2 2 application(s) left unpriced are pending' "$RH1_OK102" >> "$R102/zz-rh1-control.txt"
check "$(sweep_files102 | xargs grep -hE 'could not price|saved unpriced|left unpriced' 2>/dev/null | grep -cvxF -- "$RH1_OK102")|$(grep -hE 'could not price|saved unpriced|left unpriced' "$R102/zz-rh1-control.txt" | grep -cvxF -- "$RH1_OK102")|$(sweep_files102 | xargs grep -lxF -- "$RH1_OK102" 2>/dev/null | grep -c . | awk '{print ($1 >= 1 ? "seen" : "none")}')" "0|2|seen" \
  "10f no terminal or log of any run of the 5.18.102 script calls an application one 5.18.102 \"could not price\", \"saved unpriced\" or \"left unpriced\" (RH-1) — but \"the screens\" line on the Orders card's Make the quotation, exempt by its whole line; controls: the old words and another \"left unpriced\" line are each counted, and the exempt line was seen"
rm -f "$R102/zz-rh1-control.txt"
check "$(ls -A "$SB/web" | grep -cE '^\.(redirect102|left102m|gate102)$')|$(ls -A "$SB" | grep -cE '^\.(rbfail102|rbfail102\.seen|qorfail102|qorplant102)$')|$(cat "$R102"/plant-*.txt 2>/dev/null | sort | uniq -c | tr -s ' ' | paste -sd, -)|$(live)|$(left102)|$(set99 ucrm_quote_organization_id)|$(u99_state_102)" "0|0| 3 {\"rows\":3}|$P101|31|unset/unset|base" \
  "10g no trigger of this rehearsal's left in the stand-in or the fake docker (.gate102 and .qorplant102 among them); every plant at the question (6b, 6b2, 6e) was made — none noprompt or timeout; the sandbox left as 9g's rollback left it: 5.18.101 serving, the 31 added files on disk, the quoting organization's setting unset again, the stand-in uCRM at its base"
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
