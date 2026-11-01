#!/usr/bin/env bash
#
# Rehearse scripts/deploy-5.18.103.sh — on Uganda the uCRM catalogue (lib/UcrmCatalogue.php, tabs/sales/ucrm_catalogue.php;
# root docs/72 §G): a new screen whose switch, once an admin turns it on, makes the KYC form, Send Quotation and the lead
# quotes offer uCRM's own service plans and products by uCRM id, at uCRM's prices (UcrmQuoteLines::offered()) — over the
# live release, 5.18.102, before the operator runs it. The script under rehearsal is derived from 5.18.102's by asserted
# edits (wip/derive-5.18.103/), pinned to the release commit on release/5.18.103; every pin is read from it here and every
# expectation computed from Git or from the release's own code — never a commit, a count of files or a hash written here.
#
# The sandbox is the previous release's rehearsal's, built by its own code: scripts/harness/deploy-5.18.102/rehearse.sh,
# read up to just before its own last section (what it left behind, its sweeps) — the cut asserted: its anchor exactly
# once, after line 470, its last check the one named, and the one line that names its own folder pointed at that folder,
# also exactly once — and run here as it is, which builds every earlier sandbox the same way, from their own rehearsals.
# When this file's own sections begin, 5.18.102's rehearsal has deployed, rolled back and deployed 5.18.102 again, and
# its 9g has rolled it back: 5.18.101 serving, the quoting organization unset in both copies (the Uganda profile's #1 in
# force), the link records of 5.18.102's runs in the store. 103-pre makes production's state on 1 Nov: 5.18.102 deployed
# again, its copy half an hour back, its --after-only and the quarantine's --check passed after it.
#
# The stand-in uCRM of 5.18.99's rehearsal, as 5.18.102's taught it the catalogue's reads, learns by one asserted insertion
# a toggle of this rehearsal's: products_500_at, the ordinals of a run's Products reads that answer 500 (counted in its
# limits.log, emptied before every run) — so that QC-G1's own reading, which comes after QL-G1's catalogue and its raw
# organization reads in the same probe, can be refused alone. The catalogue settings are written only as an admin writes
# them: through the release's own UcrmCatalogue writers (rh103-cat.php) — the INSTALLED code's once 5.18.103 serves, the
# pin's from Git on a scratch copy of the book or for a state the writers never make (two switch records in force, raw).
# A direct sale is the KYC form's post read, priced and shaped by the INSTALLED KycService and UcrmQuoteLines
# (rh103-sale.php: saleFromPost, resolveDirect, G-7 as handleNew applies it, linesRecord), appended through the installed
# store; a web-direct record breaking G-7 is planted the same way. The fake docker learns one trigger (.rbfail103: the
# container's record read wrong once after the deploy's own rollback copied, 6w); the stand-in plugin two (.redirect103:
# the public sign-in page redirecting once 5.18.103 is installed, 6v/6w; .left103m: a 5.18.103 file copied over an
# installed one once 5.18.102 is back, after stage A, 6c).
#
# This rehearsal proves:
#   · the script is pinned to the release commit on release/5.18.103 (short and full), cut on 5.18.102; the delta is
#     exactly the files it lists, added and changed, each one's sha256 as it pins it, computed here from Git; the kept
#     files by theirs in both commits; no migration; the copy mark a file the release changes; the header's two commands
#     between its two marker lines, each alone, neither a rollback; QC-RB1's markers counted in Git as the script pins them
#     at the release and absent at 5.18.102; QL-RB1–QL-RB3 and --rollback-unpriced-pending gone (0);
#   · the seeded catalogue: the RELEASE's own offered() (rh103-ref.php, from Git) offers at least one plan and one hardware
#     item through both clients, and, on a scratch copy of the book, through the pin's own writers, the installation
#     product passes, a standard quotation is usable and a product kept off leaves the hardware (pre);
#   · --check is GO: QC-G1 ok with the counts, the reasons by why code, the installation and the standard quotations the
#     release's offered() gives; QC-N1 "never set", QC-N2, QC-N3, QC-N4 and QC-N7 as this rehearsal's own reading of the
#     store counts them, QC-N5 and QC-N6 as the copy read them; QL-G1 ok; uCRM asked only GETs — the two lists three times
#     per client (QL-G1's, its raw organization reads, QC-G1's), every one with limit=500; names and prices on the 0600
#     sheet alone; nothing changed, no ucrm_catalogue table on the live book, no page cache (c);
#   · QC-G1's refusals, each before anything changes: QL-G1 first over an unreadable catalogue, as the script orders them;
#     QC-G1's own over its own reading refused, through the plugin's client and through the token client; an extra request
#     inside offered()'s window (path), every answer unclassified ("other"), the catalogue file not where the reading loads
#     it (cat=), the items' hashes not made — each shown refusing, then its weakened copy caught; two switch records in force
#     read as off, QC-N1 says so (1);
#   · the deploy, DEPLOY typed, PASSES: QC-CA1 equal to stage A's item by item under this run's QL key, QC-CA2 (the screen
#     and post_sync.php as text), QC-CA3, QL-CA2, QL-CA3; the state file holds QC_A, QC_ITEMS, QCSW_A, QCCAT_A and never the
#     QL key (its own 0600 file); F's "what changed" with the switch off, "your steps" 1–5 and the rollback's block, each
#     printed command alone (4);
#   · --after-only before the switch FAILS QC-ON1 and QC-ON2 by design (QC-ON1 inverted, caught); an admin turns it on
#     through the INSTALLED writers and --after-only PASSES QC-ON1–QC-ON3; a direct sale through the installed code keeps G-7
#     (QC-ON4 ok); a planted web-direct record breaking G-7 FAILS QC-ON4 (QC-ON4 blind to package_choice, caught); the
#     standard quotation's kit archived FAILS QC-ON3; uCRM unreadable FAILS QC-CA1, QC-ON2 and QC-ON3; requireAdmin taken out
#     of the installed post_sync.php's catalogue block FAILS QC-CA2's text read (5);
#   · the rollback, ROLLBACK typed, PASSES: QC-RB2 counted at stage A and in RB (counts in the log, the ids on the sheet
#     alone), QC-RB1 ok and the table counted; a 5.18.103 file left installed FAILS QC-RB1 (its weakened copy caught); a
#     wrong pinned count fails QC-RB1's control (its weakened guard caught) (6); a deploy whose sign-in page redirects rolls
#     itself back and counts QC-RB2 after (6v) — and, its rollback not verifying, prints the put-back alone, the hazard first
#     (6w; the copy that ignores do_rollback's answer caught);
#   · --redeploy with the switch left ON: QC-N1 in capitals, PASSED, its --after-only PASSED (7); the quoting organization
#     off: QC-G1 and QC-CA1 not asked, QC-ON1–QC-ON3 one note, F's own words (8);
#   · nothing reaches Evolution; across every run the copy observers were quiet, every token copy gone, the stand-in given
#     GETs only, the live vault as it was; no seeded name, catalogue record name, customer name, price, stand-in token, run
#     key, QL key or item hash in any output, log or state file — names and prices on the 0600 sheets alone; no retired
#     check line printed; no trigger left; each check with its control on the control (10).
#
# Everything runs in a sandbox; it never touches this checkout, the server or the network, and refuses to run where the
# server could be. Everything seeded is fictitious.
#
#   bash scripts/harness/deploy-5.18.103/rehearse.sh            REHEARSE_KEEP=<dir> keeps every run's full output
set -u
for f in /data/ucrm /opt/dishnet /var/run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
H103_DIR="$(cd "$(dirname "$0")" && pwd)"
H103_ROOT="$(cd "$H103_DIR/../../.." && pwd)"
F103=scripts/deploy-5.18.103.sh
P103_EARLY="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT="\([^"]*\)".*/\1/p' "$H103_ROOT/$F103" 2>/dev/null)"
P103F_EARLY="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT_FULL="\([^"]*\)".*/\1/p' "$H103_ROOT/$F103" 2>/dev/null)"
T1_EARLY103="$(sed -n 's/^T1_SIGNATURE="\([^"]*\)".*/\1/p' "$H103_ROOT/$F103" 2>/dev/null)"
case "$P103_EARLY" in __*|'') echo "refusing: $F103 is not pinned to a release commit (${P103_EARLY:-none})"; exit 2;; esac
case "$P103F_EARLY" in "$P103_EARLY"*) [ "${#P103F_EARLY}" = "40" ] || { echo "refusing: $F103 does not pin its release commit in full"; exit 2; } ;; *) echo "refusing: $F103's full pin does not begin with its short one"; exit 2;; esac
case "$T1_EARLY103" in [1-9]*' passed, 0 failed rc=0') ;; *) echo "refusing: $F103 does not pin T1's count (T1_SIGNATURE=${T1_EARLY103:-none})"; exit 2;; esac
grep -qF 'UcrmCatalogue::fromRecords($qcOffer["catalogue"], true)' "$H103_ROOT/$F103" || { echo "refusing: $F103 is not the QC derivation (no UcrmCatalogue::fromRecords in its remote reading) — this rehearsal is written for it"; exit 2; }
for f103 in rh103-cat.php rh103-ref.php rh103-sale.php rh103q.py; do
  [ -f "$H103_DIR/$f103" ] || { echo "refusing: this rehearsal's helper $f103 is not beside it"; exit 2; }
done
H102_HARNESS="$H103_DIR/../deploy-5.18.102/rehearse.sh"
H103_PREFIX="$(mktemp)"
python3 - "$H102_HARNESS" "$H103_PREFIX" <<'PY' || { echo "refusing: the previous release's rehearsal could not be cut before its last section (its sweeps)"; rm -f "$H103_PREFIX"; exit 2; }
import sys
src, dst = sys.argv[1], sys.argv[2]
anchor = 'echo; echo "== 102-10. what the rehearsal left behind, and what it never reached =="'
lines = open(src, encoding='utf-8').read().split('\n')
at = [i for i, l in enumerate(lines) if l == anchor]
assert len(at) == 1, len(at)
assert at[0] > 470, at[0]                   # its deploy, rollbacks, --redeploy and the quoting organization off come before it
pre = '\n'.join(lines[:at[0]]) + '\n'
# That rehearsal finds its own helpers beside itself ($0's folder): sourced from here, $0 is this file, so its one line
# naming that folder points at its own folder instead — the one edit, asserted.
old_dir = 'H102_DIR="$(cd "$(dirname "$0")" && pwd)"\n'
new_dir = 'H102_DIR="$(cd "$(dirname "$0")/../deploy-5.18.102" && pwd)"   # the next release\'s rehearsal: this one\'s own folder, where its helpers are\n'
assert pre.count(old_dir) == 1, pre.count(old_dir)
pre = pre.replace(old_dir, new_dir, 1)
assert pre.count(new_dir) == 1 and pre.count(old_dir) == 0
for need in ('H101_HARNESS="$H102_DIR/../deploy-5.18.101/rehearse.sh"', 'run102() {', 'run_line102() {', 'fresh102() {', 'nogo102() {',
             'checkgo102() {', 'weak102() {', 'rb102() {', 'after_back102() {', 'copy_back102() {', 'rows_add102() {', 'sqn102() {',
             'apps102() {', 'lk102() {', 'installed102() {', 'LOGW102() {', 'u99_state_102() {', 'ug102() {', 'v1read102() {', 'fset102() {',
             'OUT102="$SB/out102"; mkdir -p "$OUT102"; STATE102="$OUT102/state-5.18.102.env"', 'R102="$SB/r102"', 'H102="$SB/rh102"',
             'REL102=release/5.18.102; DEPLOY102="$REPO/$F102"', 'REF102="$H102/ref"', 'ADMIN102="$(sq ',
             'NAMES102="$SB/names102.pat"; OFFERED102="$SB/offered102.pat"; NOWHERE102="$SB/nowhere102.pat"; AMOUNTS102="$SB/seed102.amounts"',
             'echo; echo "== 102-9. the quoting organization off'):
    assert pre.count(need) == 1, need
last = [l for l in pre.split('\n') if l.strip()][-1]
assert last.startswith('''check "$(data94)|$(set99 ucrm_quote_organization_id)" "$D102|unset/unset" "…the quoting organization's setting put back in both copies: the data as before, byte for byte"'''), last[:90]
open(dst, 'w', encoding='utf-8').write(pre)
PY
# shellcheck disable=SC1090
. "$H103_PREFIX"
rm -f "$H103_PREFIX"
for f103 in check has okline failline noteline fails printed qrun live inst_ver data94 kept_state oldname fp99 sq sqx qj set99 cfg_keep cfg_back \
            file_set row_set u99_set spent99 evo_calls vt_host ledger95 both_copies names100 secrets99 noids99 bankhits98 base_log set_sched epoch99 utc \
            dl ver checkout_state pubget qs_read101 tn_read101 \
            run102 run_line102 fresh102 after_back102 copy_back102 rb102 rows_add102 sqn102 apps102 lk102 installed102 LOGW102 u99_state_102 ug102 \
            v1read102 fset102; do
  declare -F "$f103" >/dev/null || { echo "refusing: the rehearsals this one runs did not define $f103()"; exit 2; }
done
for v103 in SB REPO R P PD DATA VAULT PHPBIN IN_CONTAINER U99 U99_URL U99_SECRETS TOKEN99 APPKEY99 RECS QD PLUGIN_BASE WEB_OK AFTER94 SCAN96 \
            SEC95 W96 BK97 DATE_TS98 EXP_QCHECK OUT96 OUT97 OUT98 OUT99 OUT100 OUT101 OUT102 STATE102 H97 H98 H99 H100 H101 H102 R97 R98 R99 \
            R100 R101 R102 P99 P100 P101 P102 P102F DEPLOY101 DEPLOY102 REF102 CHECKOUT0 VT99_0 NAMES102 OFFERED102 NOWHERE102 AMOUNTS102 \
            D102 KEPT102 EXP_AFTER102; do
  [ -n "${!v103:-}" ] || { echo "refusing: the rehearsals this one runs did not set $v103"; exit 2; }
done
for f103 in rh102q.py rh102.py; do [ -f "$H102/$f103" ] || { echo "refusing: 5.18.102's rehearsal left no $f103 in its folder"; exit 2; }; done
FAIL_PRE103=$FAILN   # the earlier rehearsals' failures, before this release's own sections: the last line names them

# ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
# 5.18.103's own rehearsal, on the sandbox built above.
# ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
REL103=release/5.18.103; DEPLOY103="$REPO/$F103"
if git -C "$R" ls-files --error-unmatch "$F103" >/dev/null 2>&1; then
  cmp -s "$R/$F103" "$DEPLOY103" || { echo "refusing: $F103 differs from the committed one — commit it, then rehearse"; exit 2; }
  WHICH103="the committed script"
else cp "$R/$F103" "$DEPLOY103"; WHICH103="the working copy (not yet committed; untracked in the clone)"; fi
git -C "$REPO" fetch -q origin "refs/heads/$REL103:refs/remotes/origin/$REL103" 2>/dev/null || true
P103="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT="\([^"]*\)".*/\1/p' "$DEPLOY103")"
P103F="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT_FULL="\([^"]*\)".*/\1/p' "$DEPLOY103")"
ADDED103="$(sed -n 's/^EXPECTED_ADDED="\(.*\)"$/\1/p' "$DEPLOY103")"; CHANGED103="$(sed -n 's/^EXPECTED_CHANGED="\(.*\)"$/\1/p' "$DEPLOY103")"
NA103=$(wc -w <<<"$ADDED103"); NC103=$(wc -w <<<"$CHANGED103"); N103=$((NA103 + NC103))   # the script's own lists — never counted here by hand
OUT103="$SB/out103"; mkdir -p "$OUT103"; STATE103="$OUT103/state-5.18.103.env"
R103="$SB/r103"; mkdir -p "$R103/logs" "$R103/mutants" "$R103/sheets" "$R103/exec" "$R103/scan" "$R103/vault" "$R103/tok" "$R103/ucrm"
H103="$SB/rh103"; mkdir -p "$H103/v"; cp "$H103_DIR"/rh103-cat.php "$H103_DIR"/rh103-ref.php "$H103_DIR"/rh103-sale.php "$H103_DIR"/rh103q.py "$H103/"
chmod 755 "$H103"; chmod 644 "$H103"/rh103-*.php "$H103/rh103q.py"; chown 1000:1000 "$H103/v"; chmod 700 "$H103/v"   # the writers' own folder, the store's user's
LOGW103() { printf '2>&1 | ( umask 077; exec tee %s/%s-%s.log )' "$OUT103" "$1" "$DATE_TS98"; }   # the script's LOGW, as it prints it
EXP_AFTER103="cd $REPO && bash scripts/deploy-5.18.103.sh --after-only $(LOGW103 after)"
EXP_ROLLBACK103="cd $REPO && bash scripts/deploy-5.18.103.sh --rollback $(LOGW103 rollback)"
EXP_REDEPLOY103="cd $REPO && bash scripts/deploy-5.18.103.sh --redeploy $(LOGW103 redeploy)"
EXP_PUTBACK103="( cd $REPO && git checkout $P102 && { bash scripts/deploy-hybrid.sh; git checkout -; } ) $(LOGW103 putback)"   # the script's PUTBACK_CMD, as it prints it
runbook_with103() { printf 'cd %s && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.103 && mkdir -p %s && bash scripts/deploy-5.18.103.sh %s %s' "$REPO" "$OUT103" "$1" "$(LOGW103 deploy)"; }
PUTBACK_HAZARD103="The put-back below counts nothing before it copies: the web-direct applications 5.18.103 made are then left unquoted under 5.18.102 (P1, or P2 where cash was collected) — an admin quotes each in uCRM by hand; nothing is quoted at price 0."
left_tmp103() { ls -d /tmp/dnb-5.18.103-* 2>/dev/null | wc -l | tr -d ' '; }
host_code103() { ls -d "$OUT103"/code-* "$OUT103"/p3b-* "$OUT103"/br1-* "$OUT103"/domainb-* "$OUT103"/tn-* "$OUT103"/qs-* 2>/dev/null | wc -l | tr -d ' '; }
backups103() { ls -d "$OUT103"/backup-* 2>/dev/null | wc -l | tr -d ' '; }
states103() { ls -1 "$OUT103"/state-5.18.103.env* "$OUT103"/rollback-state-* "$OUT103"/ql-key-* 2>/dev/null | sort | while IFS= read -r f; do printf '%s %s\n' "${f##*/}" "$(sha256sum "$f" | cut -c1-16)"; done | sha256sum | cut -c1-16; }
cache103() {   # UcrmQuoteLines' page cache and its last good copy (5.18.103, docs/72 §G.5), anywhere a run could write either
  { find "$DATA" "$PD" -maxdepth 2 \( -name ucrm_quote_catalogue_cache.json -o -name ucrm_quote_catalogue_last_good.json \) 2>/dev/null
    find /tmp -maxdepth 6 -path '/tmp/dnb-5.18.103-*' \( -name ucrm_quote_catalogue_cache.json -o -name ucrm_quote_catalogue_last_good.json \) 2>/dev/null; } | grep -c .
}
fresh103() {   # the run folder as a new run finds it: the state files, the QL key file beside them (an --after-only compares
               # under the deploy's key) and the row digests kept; every log and sheet moved aside
  find "$OUT103" -mindepth 1 -maxdepth 1 -name '*.log' -exec mv -t "$R103/logs/" {} + 2>/dev/null
  find "$OUT103" -mindepth 1 -maxdepth 1 -name 'cash-*.txt' -exec mv -t "$R103/sheets/" {} + 2>/dev/null
  find "$OUT103" -mindepth 1 -maxdepth 1 ! -name 'state-5.18.103.env*' ! -name 'rollback-state-*' ! -name 'ql-key-*' ! -name 'cih-rows-*' -exec rm -rf {} +; base_log; }
installed103() {   # installed103 COMMIT — the number of files 5.18.102 → 5.18.103 touches that are NOT installed as COMMIT has them
                   # (a file COMMIT does not hold is not asked about: 5.18.102 holds none of the added ones)
  local f rel bad=0
  for f in $(dl AM "$P102" "$P103"); do
    rel="${f#$P/}"
    if git -C "$REPO" cat-file -e "$1:$f" 2>/dev/null; then
      [ "$(git -C "$REPO" show "$1:$f" | sha256sum | cut -c1-64)" = "$(sha256sum "$PD/$rel" 2>/dev/null | cut -c1-64)" ] || bad=$((bad+1))
    fi
  done
  echo "$bad"
}
left103() { local f n=0; for f in $ADDED103; do [ -e "$PD/$f" ] && n=$((n+1)); done; echo "$n"; }   # the added files on disk
reach103() {   # the installed files, the added ones aside, that name the catalogue's class or its table — 0 after a rollback
  ( cd "$PD" && grep -rlF -e 'UcrmCatalogue' -e 'ucrm_catalogue' --include='*.php' . 2>/dev/null ) \
    | sed 's#^\./##' | grep -vxF -f <(tr ' ' '\n' <<<"$ADDED103") | grep -c .
}
last103() { printf '%03d' "$(cat "$SB/runs")"; }
run103() {   # [--answer WORD] [--script FILE] [--env K=V]…, THEN the script's options — as run102, this release's folders; the
             # stand-in's requests.log and limits.log emptied before the run (products_500_at counts this run's reads) and kept
  local answer="" script="$DEPLOY103" extra=() n tag o cmd
  while :; do case "${1:-}" in --answer) answer="$2"; shift 2 ;; --script) script="$2"; shift 2 ;; --env) extra+=("$2"); shift 2 ;; *) break ;; esac; done
  : > "$SB/docker.log"; : > "$U99/requests.log"; : > "$U99/limits.log"
  n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"; tag="$(printf '%03d' "$n")"
  vt_host > "$R103/vault/run-$tag.before"; : > "$R103/exec/run-$tag.rec"; : > "$R103/scan/run-$tag.hits"; : > "$R103/tok/run-$tag.tok"
  cmd="$(printf '%q ' env PATH="$SB/datebin:$SB/bin:$PATH" FAKE_TODAY="$AFTER94" DNB_OUT="$OUT103" DNB_RECORDS="$RECS" DNB_WEBSITE="$WEB_OK" GUARD_SECONDS=3 DN_DATA_DIR="$DATA" \
         FAKE_EXEC_LOG="$R103/exec/run-$tag.rec" FAKE_TMP_SCAN="$SCAN96" FAKE_TMP_HITS="$R103/scan/run-$tag.hits" FAKE_TOK_HITS="$R103/tok/run-$tag.tok" \
         ${extra[@]+"${extra[@]}"} bash "$script" --plugin-base "$PLUGIN_BASE" "$@")"
  o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r')"
  vt_host > "$R103/vault/run-$tag.after"; cp "$U99/requests.log" "$R103/ucrm/run-$tag.req"; cp "$U99/limits.log" "$R103/ucrm/run-$tag.lim"
  case "$o" in *"unknown option:"*) echo "run103-$n" >> "$SB/bad-runs" ;; esac
  if [ -n "${R103_OUT_DIR:-}" ]; then printf '%s\n' "$o" > "$R103_OUT_DIR/run-$tag.txt"
  elif [ -n "${R103_MUTANT_OUT:-}" ]; then printf '%s\n' "$o" > "$R103/mutants/run-$tag.txt"; else printf '%s\n' "$o" > "$R103/run-$tag.txt"; fi
  [ -z "${REHEARSE_KEEP:-}" ] || printf '%s\n' "$o" > "$REHEARSE_KEEP/run103-$tag-${answer:-none}-$(basename "$script" .sh)$(printf '%s' "$*" | tr -c 'A-Za-z0-9-' '_').txt"
  printf '%s' "$o"
}
run_line103() {   # run_line103 WORD LINE — a command line exactly as a summary printed it, through a pseudo-terminal, WORD typed
  local answer="$1" line="$2" n tag o cmd
  : > "$SB/docker.log"; : > "$U99/requests.log"; : > "$U99/limits.log"
  n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"; tag="$(printf '%03d' "$n")"
  vt_host > "$R103/vault/run-$tag.before"; : > "$R103/exec/run-$tag.rec"; : > "$R103/scan/run-$tag.hits"; : > "$R103/tok/run-$tag.tok"
  cmd="$(printf '%q ' env PATH="$SB/datebin:$SB/bin:$PATH" FAKE_TODAY="$AFTER94" DNB_OUT="$OUT103" DNB_RECORDS="$RECS" DNB_WEBSITE="$WEB_OK" GUARD_SECONDS=3 DN_DATA_DIR="$DATA" PLUGIN_BASE="$PLUGIN_BASE" \
         FAKE_EXEC_LOG="$R103/exec/run-$tag.rec" FAKE_TMP_SCAN="$SCAN96" FAKE_TMP_HITS="$R103/scan/run-$tag.hits" FAKE_TOK_HITS="$R103/tok/run-$tag.tok" bash -c "$line")"
  o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r')"
  vt_host > "$R103/vault/run-$tag.after"; cp "$U99/requests.log" "$R103/ucrm/run-$tag.req"; cp "$U99/limits.log" "$R103/ucrm/run-$tag.lim"
  case "$o" in *"unknown option:"*) echo "line103-$n" >> "$SB/bad-runs" ;; esac
  printf '%s\n' "$o" > "$R103/line-$tag.txt"
  [ -z "${REHEARSE_KEEP:-}" ] || printf '%s\n' "$o" > "$REHEARSE_KEEP/line103-$tag-${answer:-none}.txt"
  printf '%s' "$o"
}
went103() { echo "$(has "$1" 'GO — evidence recorded')$(has "$1" 'Type DEPLOY')"; }   # the run reached the question
catq103() { python3 "$H103/rh103q.py" cat "$DATA/plugin.sqlite3" 2>/dev/null | cut -d'|' -f1; }   # the live book's ucrm_catalogue: absent, or its counts
quiet103() { echo "$(live)|$(backups103)|$(data94)|$(kept_state)|$(oldname)|$(cache103)|$(catq103)"; }   # nothing deployed, backed up or changed
nogo103() {   # nogo103 LABEL EXPECTED-TEXT OUTPUT [DATA-DIGEST [CATALOGUE]] — refused with that text and "Nothing was changed",
              # never the question, nothing deployed, backed up or changed: 5.18.102 serving
  check "$(has "$3" "$2")$(has "$3" 'Nothing was changed. Send the log file.')$(went103 "$3")|$(quiet103)" "yesyesnono|$P102|0|${4:-$D103}|$KEPT103|0|0|${5:-absent}" "$1"
}
checkgo103() {   # checkgo103 OUTPUT — --check said GO: stage A held — nothing asked, nothing backed up, no state written
  echo "$(has "$1" "  5.18.103 (check): GO — stage A holds.")$(has "$1" '  --check           stage A alone, every refusal and note of a deploy')$(went103 "$1")$(fails "$1")|$(backups103)|$(states103)"
}
audit103() { python3 "$H103/rh103.py" audit "$R103/exec/run-$1.rec"; }   # audit103 TAG — copies=<n> novault=<n> plugin=<n> adj=<id|->
hits103() { local n; n="$(grep -c . "$R103/scan/run-$1.hits" 2>/dev/null)"; echo "${n:-0}"; }
vault103() { echo "$(cat "$R103/vault/run-$1.before")>$(cat "$R103/vault/run-$1.after")"; }
uother103() { python3 "$H99/rh99.py" u99other "$R103/ucrm/run-$1.req"; }   # the requests to the stand-in uCRM that were not a GET
ureq103() { python3 "$H102/rh102q.py" u102req "$R103/ucrm/run-$1.req"; }   # …the run's requests by shape (5.18.102's reader: the shapes are the same)
lim103() { python3 "$H102/rh102q.py" limits "$R103/ucrm/run-$1.lim"; }     # …the limit values its list reads asked for
gets103() { sed -n 's/^  uCRM              asked \([0-9][0-9]*\) GET(s) by this run .*/\1/p' <<<"$1" | head -1; }   # the GETs F says this run asked
qor103() {   # qor103 OUTPUT N KEY — KEY of the Nth "uCRM, GET-only" line of a run (QO_R, as the script echoes it)
  awk -v n="$2" 'index($0, "  uCRM, GET-only  ") == 1 { if (++k == n) { print substr($0, 19); exit } }' <<<"$1" | sed -nE "s/^(.* )?$3=([^ ]*).*\$/\2/p" | head -1
}
qol103() { awk 'index($0, "  the copy        ids=") == 1 { print substr($0, 19); exit }' <<<"$1" | sed -nE "s/^(.* )?$2=([^ ]*).*\$/\2/p" | head -1; }   # qol103 OUTPUT KEY — of stage A's QO_L
fset103() { printf '%s:%s' "$(grep -aE '^  FAIL  ' <<<"$1" | awk '{print $2}' | LC_ALL=C sort | paste -sd' ' -)" "$(sed -nE 's/^  5\.18\.103 \((after|deploy|rollback)\): ([0-9]+) FAILED.*/\2/p' <<<"$1" | head -1)"; }   # the failures by check id, and the count the verdict says
copy_back103() {   # the 5.18.103 copy N minutes ago: lib/KycService.php's time (its stamp_copy, COPY_MARK), the recorded copy time, the start before it
  touch -d "-$1 minutes" "$PD/lib/KycService.php"; local t; t="$(stat -c %Y "$PD/lib/KycService.php")"
  sed -i -e "s/^COPY_AT=.*/COPY_AT=$t/" -e "s/^DEPLOYED_AT=.*/DEPLOYED_AT=$(date -u -d "@$(( t - 60 ))" +%Y-%m-%dT%H:%M:%SZ)/" "$STATE103"; }
after_back103() {   # the copy thirty minutes back and master's retry job after it, so that R19 is conclusive
  copy_back103 30; local c; c="$(sed -n 's/^COPY_AT=//p' "$STATE103" | tail -1)"
  [ "$(epoch99 "$c")" = "epoch" ] && set_sched $(( c + 300 )); base_log
}
q103() { sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1; python3 "$H103/rh103q.py" "$1" "$DATA/plugin.sqlite3" "${@:2}"; }   # q103 apps|cat|ids [OUT] — this rehearsal's own reading of the store, read-only
qkv103() { python3 "$H103/rh103q.py" kv "$1" "$2"; }
sk103() { printf '%s' "$1" | tr ',' '\n' | sed -n "s/^$2://p" | head -1; }   # sk103 PART KEY — one count of "k:v,k:v"
ref103() {   # ref103 app|token [CODE-ROOT [DATABASE]] — the RELEASE's own offered() over the store's catalogue settings and the
             # stand-in, read-only, as root (rh103-ref.php): the pin's code from Git (REF103) unless a code root is given
  sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1
  RH103_TOKEN="$TOKEN99" "$PHPBIN" "$H103/rh103-ref.php" "${2:-$REF103/$P}" "$PD" "${3:-$DATA/plugin.sqlite3}" "$1" 2>/dev/null
}
cat103() {   # cat103 CODE-ROOT OPS-JSON [DATA-DIR] — the uCRM catalogue's settings an admin makes, through the code root's own
             # UcrmCatalogue writers (rh103-cat.php), in Africa/Kampala, as the store's user, with a vault of its own; JSON, or none(<why>)
  local o; o="$(cd "$H103/v" && setpriv --reuid 1000 --regid 1000 --clear-groups -- env DN_VAULT_FILE="$H103/v/cat-vault.json" "$PHPBIN" "$H103/rh103-cat.php" "$1" "${3:-$DATA}" "$2" Africa/Kampala 2>"$H103/v/cat.err")"
  rm -f "$H103/v/cat-vault.json"
  case "$o" in '{"ops":'*) printf '%s' "$o" ;; *) echo "none($(head -c 160 "$H103/v/cat.err"))" ;; esac
}
sale103() {   # sale103 SALE-JSON [g7] — a direct sale through the INSTALLED code: priced as root, read-only (rh103-sale.php price,
              # its record to a 0600 file), then appended as the store's user (append; with g7, the record breaking G-7)
  local r="$H103/v/sale-$RANDOM$RANDOM.json" o a
  sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1
  o="$(RH103_TOKEN="$TOKEN99" "$PHPBIN" "$H103/rh103-sale.php" price "$PD" "$PD" "$DATA/plugin.sqlite3" "$r" "$1" 2>"$H103/v/sale.err")"
  case "$o" in '{"status":"priced"'*) chown 1000:1000 "$r" ;; *) rm -f "$r"; echo "none(${o:-$(head -c 160 "$H103/v/sale.err")})"; return 0 ;; esac
  a="$(cd "$H103/v" && setpriv --reuid 1000 --regid 1000 --clear-groups -- env DN_VAULT_FILE="$H103/v/sale-vault.json" "$PHPBIN" "$H103/rh103-sale.php" append "$PD" "$DATA" "$r" ${2:+"$2"} 2>"$H103/v/sale.err")"
  rm -f "$r" "$H103/v/sale-vault.json"
  printf '%s|%s' "$o" "${a:-none($(head -c 120 "$H103/v/sale.err"))}"
}
qcsay103() { printf '%s service plan(s) and %s product(s) counted; offered %s plan(s) and %s hardware item(s); %s not offered' "$(sk103 "$1" sp)" "$(sk103 "$1" pr)" "$(sk103 "$1" plans)" "$(sk103 "$1" hw)" "$(sk103 "$1" not)"; }   # the script's qc_say
qcrbsay103() { printf '%s web-direct application(s), %s with no quotation made (pending for uCRM %s, in review %s, failed %s, in uCRM with no quotation %s)' "$(sk103 "$1" direct)" "$(sk103 "$1" unquoted)" "$(sk103 "$1" pending)" "$(sk103 "$1" review)" "$(sk103 "$1" failed)" "$(sk103 "$1" synced)"; }   # the script's qcrb_say
n3say103() { printf 'web %s (the KYC form), app %s (the retailer app, the mobile API, or a form opened before that copy), web-direct %s (an earlier, rolled-back run of 5.18.103), none %s (saved before 5.18.102)%s' "$(sk103 "$1" web)" "$(sk103 "$1" app)" "$(sk103 "$1" direct)" "$(sk103 "$1" none)" "$( [ "$(sk103 "$1" other)" = "0" ] || echo ", another value $(sk103 "$1" other)")"; }
cmpsay103() { printf '%s compared — the same %s, different 0; not offered or left out at stage A 0, at stage A and not now 0' "$1" "$1"; }   # the script's qc_cmp_say, nothing differing
ANCH103="$SB/anchors103.tsv"
cat > "$ANCH103" <<'ANCH'
qcg1p	deploy	      [ "$(ql_n "$p" unread)" = "0" ] || stop "NO-GO (QC-G1): uCRM's Products and Service Plans could not be read through the plugin's own client, GET-only	      true || stop "NO-GO (QC-G1): uCRM's Products and Service Plans could not be read through the plugin's own client, GET-only
qcg1t	deploy	      [ "$t" = "unset" ] || [ "$(ql_n "$t" unread)" = "0" ] || stop "NO-GO (QC-G1): uCRM's Products and Service Plans could not be read through the token client	      true || stop "NO-GO (QC-G1): uCRM's Products and Service Plans could not be read through the token client
qcpathm	deploy	                        $off = $u->offered($qcInstId, $qcOff);	                        $off = $u->offered($qcInstId, $qcOff); try { $c->get("products?limit=500"); } catch (Throwable $x103) { }
qcpathw	deploy	      [ "$x" = "ok" ] || stop "NO-GO (QC-G1): the RELEASE's offered() asked uCRM	      true || stop "NO-GO (QC-G1): the RELEASE's offered() asked uCRM
qcotherm	deploy	                            if (!in_array($w, $qcKnown[$k] ?? [], true) || trim($rs) === "") { $other++; $w = "other"; }	                            if (true) { $other++; $w = "other"; }
qcotherw	deploy	      [ "$w" = "0" ] || stop "NO-GO (QC-G1): $w answer(s) of the RELEASE's offered() cannot be classified	      true || stop "NO-GO (QC-G1): $w answer(s) of the RELEASE's offered() cannot be classified
qcnocatm	deploy	    a) la+=("links=$pin/lib/UcrmQuoteLinks.php" "salt=$QL_KEY" "cat=$pin/lib/UcrmCatalogue.php") ;;	    a) la+=("links=$pin/lib/UcrmQuoteLinks.php" "salt=$QL_KEY" "cat=$pin/lib/UcrmCatalogue.rehearsal-nowhere.php") ;;
qcnocatw	deploy	       stop "NO-GO (QC-G1): what the forms would offer could not be read on the copy (${c:-not read}	       : "NO-GO (QC-G1): what the forms would offer could not be read on the copy (${c:-not read}
qcnocatn	deploy	           '?nocat') stop "NO-GO (QC-G1): the quoting organization is in force	           '?nocat') : "NO-GO (QC-G1): the quoting organization is in force
qchashm	deploy	$qcKeyOk = strlen($qcSalt) >= 32;	$qcKeyOk = false;
qchashw	deploy	      ql_hl_ok i "$QO_QCI" || stop "NO-GO (QC-G1): the items' keyed hashes were not made	      true || stop "NO-GO (QC-G1): the items' keyed hashes were not made
qcon1w	deploy	    on) ok "QC-ON1 the uCRM catalogue is switched ON	    none|off|on) ok "QC-ON1 the uCRM catalogue is switched ON
qcon4w	deploy	                if (($r["package_choice"] ?? null) !== "" || ($r["device_id"] ?? null) !== "" || !$cartOk) $qcBad[] = (int)($r["id"] ?? 0);	                if (($r["device_id"] ?? null) !== "" || !$cartOk) $qcBad[] = (int)($r["id"] ?? 0);
qcrb1m	deploy	  [ "$qr1_in" = "0" ] || QCRB1_BAD="$QCRB1_BAD $qr1_rel:names-$qr1_mark×${qr1_in:-?}"	  true || QCRB1_BAD="$QCRB1_BAD $qr1_rel:names-$qr1_mark×${qr1_in:-?}"
qcrb1s	deploy	        [ "$qr1_in" = "0" ] || { QCRB1_SW_HIT=$((QCRB1_SW_HIT + 1)); [ "$QCRB1_SW_HIT" -gt 5 ] || QCRB1_BAD="$QCRB1_BAD $qr1_rel:names-$qr1_mark×${qr1_in:-?}"; }	        true || { QCRB1_SW_HIT=$((QCRB1_SW_HIT + 1)); [ "$QCRB1_SW_HIT" -gt 5 ] || QCRB1_BAD="$QCRB1_BAD $qr1_rel:names-$qr1_mark×${qr1_in:-?}"; }
qcrb1c	deploy	  [ "$qr1_pn" = "$qr1_pin" ] || { QCRB1_BAD="$QCRB1_BAD $qr1_rel(the-control:the-pin-reads-$qr1_mark×${qr1_pn:-?}-not-$qr1_pin)"; continue; }	  true || { QCRB1_BAD="$QCRB1_BAD $qr1_rel(the-control:the-pin-reads-$qr1_mark×${qr1_pn:-?}-not-$qr1_pin)"; continue; }
v1rb	deploy	if do_rollback; then QCV1_RB=ok; else QCV1_RB=failed; fi	do_rollback || true; QCV1_RB=ok
copymark	deploy	COPY_MARK="lib/KycService.php"	COPY_MARK="webhook.php"
ANCH
# QC-RB1's control, weakened by its pinned count: the first line of the script's own QC_RB1_MARKS block, its count one
# more — an entry written at run time, since the count is the script's (re-derived on the release commit), never this file's.
RB1M103="$(sed -n "/^QC_RB1_MARKS=\"\$(cat <<'MARKS'\$/,/^MARKS\$/p" "$DEPLOY103" | grep -F '|')"
RB1F103="$(head -1 <<<"$RB1M103")"
n103() { case "$1" in ''|*[!0-9]*) echo 0 ;; *) echo "$1" ;; esac; }   # n103 VALUE — a count, or 0: an arithmetic over a reading that failed must FAIL a check, never abort the rehearsal
RB1W103="${RB1F103%|*}|$(( $(n103 "${RB1F103##*|}") + 1 ))"
a103() { awk -F'\t' -v k="$1" '$1 == k { print $3; exit }' "$ANCH103"; }
r103() { awk -F'\t' -v k="$1" '$1 == k { print $4; exit }' "$ANCH103"; }
weak103() {   # weak103 NAME KEY… — a copy of the script in the clone's scripts/ with each KEY's anchor replaced, each exactly once;
              # the key qcrb1n replaces QC_RB1_MARKS's first line (the block's opening line before it, whole) by RB1W103
  local name="$1" k dst="$REPO/scripts/.$1.sh"; shift
  cp "$DEPLOY103" "$dst"
  for k in "$@"; do
    if [ "$k" = "qcrb1n" ]; then
      python3 - "$dst" "$RB1F103" "$RB1W103" <<'PY' || echo "weak103:$k" >> "$SB/anchor-missing"
import sys
f, a, b = sys.argv[1:4]; s = open(f, encoding='utf-8').read()
head = "QC_RB1_MARKS=\"$(cat <<'MARKS'\n"
assert a and a != b and s.count(head + a + '\n') == 1, s.count(head + a + '\n')
s2 = s.replace(head + a + '\n', head + b + '\n', 1)
assert s2.count(head + b + '\n') == 1 and s2.count(head + a + '\n') == 0
open(f, 'w', encoding='utf-8').write(s2)
PY
      continue
    fi
    python3 - "$dst" "$(a103 "$k")" "$(r103 "$k")" <<'PY' || echo "weak103:$k" >> "$SB/anchor-missing"
import sys
f, a, b = sys.argv[1:4]; s = open(f, encoding='utf-8').read()
assert a and s.count(a) == 1, (a[:60], s.count(a))
s2 = s.replace(a, b)
assert s2.count(b) >= 1 and (b.count(a) > 0 or s2.count(a) == 0)
open(f, 'w', encoding='utf-8').write(s2)
PY
  done
  echo "$dst"
}
# The readers: 5.18.102's, taught this release's folders in /tmp, by asserted replacements. ────────────────────────────
python3 - "$H102/rh102.py" "$H103/rh103.py" <<'PY' || { echo "readers:rh103" >> "$SB/anchor-missing"; echo "refusing: this rehearsal's readers could not be derived from the previous release's"; exit 2; }
import sys
s = open(sys.argv[1]).read()
reps = [
    ("root = re.compile(r'^/tmp/dnb-5\\.18\\.102-(?:cih-[^/]+/[^/]+/(?:t|d|p0root|k-(?:found|off|on)|c-(?:old|new)|g-(?:i|p|c0|c1)|l|u|t-(?:i|p))/dishnet-hybrid-sudan|code-[^/]+/p3b/[^/]+/dishnet-hybrid-sudan)(?:/|$)')",
     "root = re.compile(r'^/tmp/dnb-5\\.18\\.103-(?:cih-[^/]+/[^/]+/(?:t|d|p0root|k-(?:found|off|on)|c-(?:old|new)|g-(?:i|p|c0|c1)|l|u|t-(?:i|p))/dishnet-hybrid-sudan|code-[^/]+/p3b/[^/]+/dishnet-hybrid-sudan)(?:/|$)')"),
    ("if not env.get('DN_VAULT_FILE', '').startswith('/tmp/dnb-5.18.102-'): novault += 1",
     "if not env.get('DN_VAULT_FILE', '').startswith('/tmp/dnb-5.18.103-'): novault += 1"),
]
for old, new in reps:
    assert s.count(old) == 1, old[:70]
    s = s.replace(old, new)
    assert s.count(new) == 1 and s.count(old) == 0
open(sys.argv[2], 'w').write(s)
PY
# The observers search this release's folders in /tmp too — QC-RB2's copy of the book (qcrb) among them — (the fake
# docker's one list, extended, asserted), and the token observer counts this release's quoting folders beside the
# earlier ones'.
python3 - "$SB/bin/docker" <<'PY' || echo "fakedocker:observers103" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]; s = open(p).read()
old = ' /tmp/dnb-5.18.102-code-*/t4run; do\n'
assert s.count(old) == 1, s.count(old)
assert s.count('/tmp/dnb-5.18.103-') == 0
s = s.replace(old, ' /tmp/dnb-5.18.102-code-*/t4run /tmp/dnb-5.18.103-cih-* /tmp/dnb-5.18.103-ss-* /tmp/dnb-5.18.103-qcrb-* /tmp/dnb-5.18.103-code-*/p3b /tmp/dnb-5.18.103-code-*/t1run /tmp/dnb-5.18.103-code-*/t4run; do\n', 1)
old2 = '/tmp/dnb-5.18.101-cih-* /tmp/dnb-5.18.102-cih-* -name token.json 2>/dev/null | grep -c .)" "${_rh_m:--}" >> "$FAKE_TOK_HITS"'
assert s.count(old2) == 1, s.count(old2)
s = s.replace(old2, '/tmp/dnb-5.18.101-cih-* /tmp/dnb-5.18.102-cih-* /tmp/dnb-5.18.103-cih-* -name token.json 2>/dev/null | grep -c .)" "${_rh_m:--}" >> "$FAKE_TOK_HITS"', 1)
assert s.count('/tmp/dnb-5.18.103-') == 7
open(p, 'w').write(s)
PY
# One trigger of this release's own, in the fake docker's prelude beside the earlier ones (inserted, as theirs were, before
# its row() line — asserted), armed only by a file of this rehearsal's and spent once:
#   .rbfail103 — the first read of the container's record (exec ucrm cat …/.deployed-commit) that names 5.18.102 after one
#     that named 5.18.103 answers another commit: deploy-hybrid.sh, run by the deploy's own rollback after V1, sees the copy
#     land and its record read back wrong, and exits 1 — the rollback does not verify, while 5.18.102's files are in place
#     and the next read names it (103-6w).
cat > "$SB/docker103.hook" <<'HOOK'
# ── the next release's rehearsal (5.18.103): one trigger, armed by a file of the rehearsal's, spent once ──
if [ -f "@SB@/.rbfail103" ] && [ "$*" = "exec ucrm cat /data/ucrm/data/plugins/dishnet-hybrid-sudan/.deployed-commit" ]; then
  _rh_r3="$(tail -n1 "@PD@/.deployed-commit" 2>/dev/null | tr -cd '0-9a-f')"
  if [ "$_rh_r3" = "@P103@" ]; then : > "@SB@/.rbfail103.seen"
  elif [ "$_rh_r3" = "@P102@" ] && [ -f "@SB@/.rbfail103.seen" ]; then rm -f "@SB@/.rbfail103" "@SB@/.rbfail103.seen"; echo 0000000; exit 0; fi
fi
HOOK
python3 - "$SB/bin/docker" "$SB/docker103.hook" "$SB" "$PD" "$P102" "$P103" <<'PY' || echo "fakedocker:hook103" >> "$SB/anchor-missing"
import sys
p, hook, sb, pd, p102, p103 = sys.argv[1:7]
assert p102 and p103 and p102 != p103 and all(c in '0123456789abcdef' for c in p102 + p103), (p102, p103)
s = open(p).read()
h = open(hook).read().replace('@SB@', sb).replace('@PD@', pd).replace('@P102@', p102).replace('@P103@', p103)
assert '@' not in h.replace('"$@"', ''), 'a placeholder left'
lines = s.split('\n')
at = [i for i, l in enumerate(lines) if l.startswith('row() { grep "^$1|" ')]
assert len(at) == 1, len(at)
assert s.count('.rbfail103') == 0 and s.count('.rbfail102') == 5
lines[at[0]:at[0]] = h.rstrip('\n').split('\n')
s2 = '\n'.join(lines)
assert s2.count('.rbfail103') == 5 and s2.count('.rbfail102') == 5 and s2.count('_rh_r3') == 3
assert s2.index('.rbfail102') < s2.index('.rbfail103') < s2.index('row() { grep "^$1|" ')
open(p, 'w').write(s2)
PY
rm -f "$SB/docker103.hook"
check "$(bash -n "$SB/bin/docker" && echo parses)|$(python3 -c 'import ast, sys; [ast.parse(open(f).read()) for f in sys.argv[1:]]; print("parses")' "$H103/rh103.py" "$H103/rh103q.py" 2>&1)|$(for f in "$H103"/rh103-*.php; do php -l "$f" 2>&1 | grep -c '^No syntax errors'; done | tr -d '\n')|$(grep -c '5\.18\.10[12]-' "$H103/rh103.py")" \
      "parses|parses|111|0" \
  "control: the fake docker with this release's folders, this rehearsal's readers (5.18.102's derived, naming none of the earlier folders, and its own) and its three PHP helpers (catalogue, reference, sale) parse"
dk103() { env PATH="$SB/bin:$PATH" bash "$SB/bin/docker" "$@" 2>/dev/null; }
DC103=/data/ucrm/data/plugins/dishnet-hybrid-sudan/.deployed-commit

echo; echo "== 103-pre. production's state on 1 Nov: 5.18.102 deployed again over 5.18.101, its --after-only and the quarantine's --check passed after it =="
check "$(live)|$(inst_ver)|$(set99 ucrm_quote_organization_id)" "$P101|5.18.101|unset/unset" \
  "pre-a the sandbox as 5.18.102's rehearsal left it: 5.18.101 serving (its 9g rolled back), the quoting organization unset in both copies — the Uganda profile's #1 in force"
fresh102; OUT="$(run102 --answer DEPLOY --redeploy)"; OUT102P="$OUT"
check "$(fails "$OUT")$(has "$OUT" '5.18.102 (deploy): PASSED')$(live)$(installed102 "$P102")$(inst_ver)" "0yes${P102}05.18.102" \
  "pre-b 5.18.102 deployed again, --redeploy, DEPLOY typed: PASSED — $P102 serving, every file as its pin has it, as production has it since 1 Nov"
after_back102; ALINE102P="$(printed "$OUT102P" "cd $REPO && bash scripts/deploy-5.18.102.sh --after-only")"
fresh102; OUT="$(run_line102 '' "$ALINE102P")"
check "$ALINE102P|$(fails "$OUT")$(has "$OUT" '5.18.102 (after): PASSED')$(okline "$OUT" R19 'the quarantine holds: master ran the retry job at')" "$EXP_AFTER102|0yesyes" \
  "pre-c its --after-only, as its summary printed it, the copy half an hour back: PASSED, R19 conclusive"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "pre-d the quarantine's own --check after it, as the runbook orders: PASSED — A14 holds for 5.18.103"
[ -e "$RECS/dnb-5.18.102" ] || ln -s "$OUT102" "$RECS/dnb-5.18.102"
check "$(readlink -f "$RECS/dnb-5.18.102")|$(readlink -f "$RECS/dnb-5.18.101")|$(readlink -f "$RECS/dnb-5.18.100")|$(readlink -f "$RECS/dnb-5.18.99")|$( [ "$(grep -c '^COPY_AT=[0-9][0-9]*$' "$STATE102")" -ge 1 ] && echo copy)|$(grep -c '^STRAY_Q_FAILED=' "$STATE102")" \
      "$(readlink -f "$OUT102")|$(readlink -f "$OUT101")|$(readlink -f "$OUT100")|$(readlink -f "$OUT99")|copy|0" \
  "pre-e 5.18.102's records where 5.18.103's A14 looks for them (<records>/dnb-5.18.102), beside the earlier releases', as on the server; its state file holds its copy's time and no failure"
D103="$(data94)"; KEPT103="$(kept_state)"
check "$(catq103)|$(cache103)|$(installed103 "$P102")|$(left103)" "absent|0|0|0" \
  "pre-f no ucrm_catalogue table on the live book (5.18.102 has no catalogue screen), no page cache or last good copy anywhere, every file 5.18.103 changes as 5.18.102 has it, none of those it adds on disk"
# ── The stand-in learns products_500_at — by one asserted insertion before 5.18.102's own Products answer, after its
# limits.log line — so that a run's Nth Products read (counted in that run's limits.log) answers 500. ────────────────────
python3 - "$U99/rh99-ucrm.php" <<'PY' || echo "ucrm103:ordinal" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]; s = open(p, encoding='utf-8').read()
old = "if ($p === '/products') {\n    if (!empty($st['products_html']))"
assert s.count(old) == 1, s.count(old)
assert s.count('rh103') == 0 and s.count('products_500_at') == 0
add = r'''// 5.18.103's rehearsal (an asserted insertion before 5.18.102's own Products answer): products_500_at — the ordinals,
// counted in this run's limits.log (emptied before every run), of the Products reads that answer 500. QC-G1's reading
// comes after QL-G1's catalogue and its raw organization reads in the same probe, so an ordinal past theirs refuses it alone.
if ($p === '/rh103/ping') $out(['rh103' => 'ordinal-ready']);
if ($p === '/products' && is_array($st['products_500_at'] ?? null)) {
    $n103 = 0; foreach ((array)@file($dir . '/limits.log') as $l103) if (strpos((string)$l103, 'products ') === 0) $n103++;
    if (in_array($n103, $st['products_500_at'], true)) $out(['code' => 500, 'message' => 'Internal Server Error'], 500);
}
'''
s = s.replace(old, add + old, 1)
assert s.count(old) == 1 and s.count("'/rh103/ping'") == 1 and s.count('products_500_at') == 3   # the comment's and the two reads'
open(p, 'w', encoding='utf-8').write(s)
PY
for i in $(seq 1 60); do [ "$(curl -s --noproxy '*' -H "X-Auth-App-Key: $APPKEY99" "$U99_URL/api/v2.1/rh103/ping" 2>/dev/null)" = '{"rh103":"ordinal-ready"}' ] && break; sleep 0.1; done   # PHP re-reads the edited router
u99_set 'products_500_at=[2]'; : > "$U99/limits.log"
UO103="$(ug102 -H "X-Auth-App-Key: $APPKEY99" "$U99_URL/api/v2.1/products?limit=500")|$(ug102 -H "x-auth-token: $TOKEN99" "$U99_URL/api/v2.1/products?limit=500")|$(ug102 -H "X-Auth-App-Key: $APPKEY99" "$U99_URL/api/v2.1/service-plans?limit=500")|$(ug102 -H "X-Auth-App-Key: $APPKEY99" "$U99_URL/api/v2.1/products?limit=500")"
u99_set; : > "$U99/limits.log"
check "$UO103|$(ug102 -H "X-Auth-App-Key: $APPKEY99" "$U99_URL/api/v2.1/products?limit=500")|$(ug102 -H "X-Auth-App-Key: $APPKEY99" "$U99_URL/api/v2.1/products?limit=500")|$(u99_state_102)" "200|500|200|200|200|200|base" \
  "control: the stand-in's products_500_at=[2] answers the second Products read of the count 500 — whichever client asks — and the first, the service plans and the third 200; with its base state every read 200 again"
: > "$U99/requests.log"; : > "$U99/limits.log"
# The fake docker's trigger, fired here by hand: unarmed nothing happens; armed, the record's read answers the truth until
# one that named 5.18.103 has been seen, then another commit once. The container's record is put back byte for byte.
cp -p "$PD/.deployed-commit" "$SB/dc103.kept"
HK1="$(dk103 exec ucrm cat "$DC103" | tail -n1)"; : > "$SB/.rbfail103"; HK2="$(dk103 exec ucrm cat "$DC103" | tail -n1)"
printf '%s\n' "$P103" > "$PD/.deployed-commit"; HK3="$(dk103 exec ucrm cat "$DC103" | tail -n1):$( [ -f "$SB/.rbfail103.seen" ] && echo seen)"
cp -p "$SB/dc103.kept" "$PD/.deployed-commit"; HK4="$(dk103 exec ucrm cat "$DC103" | tail -n1)"; HK5="$(dk103 exec ucrm cat "$DC103" | tail -n1)"
check "$HK1|$HK2|$HK3|$HK4|$HK5|$(ls -A "$SB" | grep -cE '^\.(rbfail103|rbfail103\.seen)$')|$(cmp -s "$SB/dc103.kept" "$PD/.deployed-commit" && [ "$(stat -c %Y "$SB/dc103.kept")" = "$(stat -c %Y "$PD/.deployed-commit")" ] && echo same)" \
      "$P102|$P102|$P103:seen|0000000|$P102|0|same" \
  "control: the fake docker's trigger — the record read truly, unarmed and armed alike, until 5.18.103's has been seen; then another commit once, and the truth again; spent, the record as it was"
rm -f "$SB/dc103.kept"
# The stand-in plugin's two triggers (an asserted edit of its public.php, as the earlier rehearsals' are): .redirect103 —
# spent at the first request for the public sign-in page once 5.18.103's manifest is installed (V1, after the copy), its
# answer a redirect to the sign-in page itself; .left103m — spent at the first request once 5.18.102's manifest is
# installed (V, after stage A of a --rollback compared the files), each "<source>\t<installed path>" line copied over the
# installed file: a 5.18.103 file left in place after a rollback (QC-RB1).
python3 - "$SB/web/public.php" <<'PY' || echo "standin:triggers103" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]; s = open(p).read()
old = "$page = $_GET['page'] ?? '';\n"
assert s.count(old) == 1, s.count(old)
assert s.count('redirect103') == 0 and s.count('left103m') == 0
new = """if (($_GET['page'] ?? '') === 'rh103_probe') { echo 'rh103-triggers-ready'; exit; }
if (($_GET['page'] ?? '') === 'customer_login' && !isset($_GET['rh103v1']) && strpos((string)@file_get_contents($root . '/manifest.json'), '"5.18.103"') !== false && $spend('.redirect103')) { header('Location: ?page=customer_login&rh103v1=1', true, 302); exit; }   // the next rehearsal: the public sign-in page redirecting once 5.18.103 is installed (V1)
$left103m = is_file($web . '/.left103m') ? trim((string)file_get_contents($web . '/.left103m')) : '';
if ($left103m !== '' && strpos((string)@file_get_contents($root . '/manifest.json'), '"5.18.102"') !== false && $spend('.left103m')) {
    foreach (explode("\\n", $left103m) as $l103m) { $p103m = explode("\\t", $l103m, 2); if (count($p103m) === 2 && $p103m[0] !== '' && $p103m[1] !== '') copy($p103m[0], $root . '/' . $p103m[1]); }   // the next rehearsal: a 5.18.103 file left in place after a rollback (QC-RB1)
}
""" + old
s = s.replace(old, new, 1)
assert s.count("'.redirect103'") == 1 and s.count("'.left103m'") == 1 and s.count('rh103_probe') == 1 and s.count(old) == 1
open(p, 'w').write(s)
PY
for i in $(seq 1 60); do grep -q 'rh103-triggers-ready' <<<"$(pubget page=rh103_probe)" && break; sleep 0.1; done   # PHP re-reads the edited file
printf 'x\n' > "$SB/web/.redirect103"; chmod 644 "$SB/web/.redirect103"
check "$(pubget page=rh103_probe)|$(inst_ver)|$(v1read102)|$(ls -A "$SB/web" | grep -cE '^\.(redirect103|left103m)$')" "rh103-triggers-ready|5.18.102|200:0|1" \
  "control: the stand-in carries the two triggers; .redirect103 set does not fire while 5.18.102's manifest is installed — 200 with no redirect, the trigger unspent — so it can fire only after a copy of 5.18.103, at V1"
rm -f "$SB/web/.redirect103"
# ── The RELEASE's own offered() on the store and the stand-in — not the script's probe: the reference QC-G1's and QC-CA1's
# counts are set against. Its code from Git (lib and profiles), in a folder of this rehearsal's; the store read-only. Then a
# scratch copy of the book, written through the PIN's own UcrmCatalogue writers as an admin will write the live one after
# the deploy: the installation product passes, the standard quotation is usable, a product kept off leaves the hardware.
# The live book is never written here. ───────────────────────────────────────────────────────────────────────────────────
REF103="$H103/ref"; rm -rf "$REF103"; mkdir -p "$REF103"; git -C "$REPO" archive "$P103" "$P/lib" "$P/profiles" | tar -xf - -C "$REF103"
: > "$U99/requests.log"; : > "$U99/limits.log"
QCREF_A="$(ref103 app)"; QCREF_T="$(ref103 token)"
qcf103() { cut -d'|' -f"$2" <<<"$1" | sed -E 's/^(inst|std|stock|items)://'; }   # qcf103 REF N — the Nth field of a reference line, its label off
QCC_EXP="plug:$(qcf103 "$QCREF_A" 1);tok:$(qcf103 "$QCREF_T" 1)"; QCW_EXP="plug:$(qcf103 "$QCREF_A" 2);tok:$(qcf103 "$QCREF_T" 2)"
QCI_EXP="plug:$(qcf103 "$QCREF_A" 3);tok:$(qcf103 "$QCREF_T" 3)"; QCS_EXP="plug:$(qcf103 "$QCREF_A" 4);tok:$(qcf103 "$QCREF_T" 4)"
QCK_EXP="plug:$(qcf103 "$QCREF_A" 5);tok:$(qcf103 "$QCREF_T" 5)"
ITEMS103=$(( $(n103 "$(qcf103 "$QCREF_A" 6)") + $(n103 "$(qcf103 "$QCREF_T" 6)") ))
QCA103="$(qcf103 "$QCREF_A" 1)"; QCT103="$(qcf103 "$QCREF_T" 1)"
check "$( [ "$QCREF_A" = "$QCREF_T" ] && echo same)|$( [ "$(sk103 "$QCA103" plans)" -ge 1 ] 2>/dev/null && echo plans)|$( [ "$(sk103 "$QCA103" hw)" -ge 1 ] 2>/dev/null && echo hw)|$( [ "$(sk103 "$QCA103" not)" -ge 1 ] 2>/dev/null && echo leftout)|$(sk103 "$QCA103" unread):$(sk103 "$QCA103" other)|$(qcf103 "$QCREF_A" 3)|$(qcf103 "$QCREF_A" 4)|$(catq103)|$(python3 "$H102/rh102q.py" u102req "$U99/requests.log" | sed -E 's/^total=[0-9]+ //; s/ products=.*//')" \
      "same|plans|hw|leftout|0:0|unset|0/0|absent|other=0" \
  "pre-g the RELEASE's own UcrmQuoteLines::offered() (from Git) on the seeded stand-in and the store's settings — none: it offers at least one plan and one hardware item, and leaves out at least one item, each by the release's own why code (none unclassified); the installation not set; no standard quotation; the same through the plugin's key and the token; the live book holds no ucrm_catalogue table; GETs only"
SCR103="$H103/scratch"; rm -rf "$SCR103"; mkdir -p "$SCR103"; sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1
python3 -c 'import sqlite3, sys; s = sqlite3.connect("file:" + sys.argv[1] + "?mode=ro", uri=True); d = sqlite3.connect(sys.argv[2]); s.backup(d); d.close(); s.close()' "$DATA/plugin.sqlite3" "$SCR103/plugin.sqlite3"
chown -R 1000:1000 "$SCR103"
CATON103='[{"op":"install","product":9105,"actor":@A@},{"op":"standard","replace":null,"plan":{"sp":501,"p":9101},"hw":[{"p":9103,"q":1}],"install":true,"actor":@A@},{"op":"mode","on":true,"judged":9105,"actor":@A@}]'
CATON103="${CATON103//@A@/${ADMIN102:-0}}"   # an admin's acts on the screen: the installation 9105, a standard quotation (plan 501 with 9101, kit 9103 ×1, the installation), the switch on
SCRW103="$(cat103 "$REF103/$P" "$CATON103" "$SCR103")"; SCRR103="$(ref103 app "" "$SCR103/plugin.sqlite3")"
SCRH103="$(cat103 "$REF103/$P" '[{"op":"hidden","product":9104,"hidden":true,"actor":'"${ADMIN102:-0}"'}]' "$SCR103")"; SCRK103="$(ref103 app "" "$SCR103/plugin.sqlite3")"
check "$SCRW103|$(qcf103 "$SCRR103" 3)|$(qcf103 "$SCRR103" 4)|$(sk103 "$(qcf103 "$SCRR103" 1)" plans)|$SCRH103|$(( $(n103 "$(sk103 "$(qcf103 "$SCRR103" 1)" hw)") - $(n103 "$(sk103 "$(qcf103 "$SCRK103" 1)" hw)") ))|$(qcf103 "$SCRK103" 2 | tr '+' '\n' | grep -c '^kept_off\.1$')|$(python3 "$H103/rh103q.py" cat "$SCR103/plugin.sqlite3")|$(catq103)" \
      '{"ops":3,"kinds":["set","saved","on"],"ids":[1,2,3],"records":3,"inforce":3,"switch":"on"}|ok|1/1|'"$(sk103 "$QCA103" plans)"'|{"ops":1,"kinds":["hidden"],"ids":[4],"records":4,"inforce":4,"switch":"on"}|1|1|records:4,inforce:4|switch:on|install:set|std:1|hidden:1|absent' \
  "pre-h on a scratch copy of the book, through the PIN's own writers: the installation set (passing, by the release's own rules), a standard quotation saved (usable: its plan, kit and installation offered), the switch on; a product kept off leaves exactly one hardware item, said \"kept off\" — the live book still holds no ucrm_catalogue table"
rm -rf "$SCR103"
# What this rehearsal's own reading of the store says (rh103q.py apps): QC-N3's entries, QC-N4's waiting web applications,
# G-7's web-direct records, QC-RB2's, QC-N7's plan types.
A103_0="$(q103 apps)"
check "$(qkv103 "$A103_0" g7)|$(qkv103 "$A103_0" qcrb2)|$( [ -n "$(qkv103 "$A103_0" plantype)" ] && echo read)" "direct:0,bad:0|direct:0,unquoted:0,pending:0,review:0,failed:0,synced:0|read" \
  "pre-i this rehearsal's own reading of the store: no web-direct application (only a run of 5.18.103 could save one), so none for G-7 or QC-RB2; the plugin's active plans read by type"
echo; echo "== 103-0. what is rehearsed: 5.18.103 — the controls =="
echo "  the script     $WHICH103"
check "$P103|$P103F" "$(git -C "$REPO" rev-parse --short "origin/$REL103" 2>/dev/null || echo none)|$(git -C "$REPO" rev-parse "origin/$REL103" 2>/dev/null || echo none)" "the script is pinned to the release commit on $REL103, short and in full ($P103)"
check "$(git -C "$REPO" rev-parse --short "$P103^" 2>/dev/null)|$(git -C "$REPO" rev-parse "$P103^" 2>/dev/null)|$(ver "$P103")$(ver "$P102")" "$P102|$(sed -n 's/^BASELINE_COMMIT_FULL="\([^"]*\)".*/\1/p' "$DEPLOY103")|5.18.1035.18.102" \
  "…cut on 5.18.102 ($P102), the script's baseline in full; the pin says 5.18.103, its parent 5.18.102"
check "$(dl D "$P102" "$P103" | grep -c .)|$(dl A "$P102" "$P103" | grep -c .)|$(dl AM "$P102" "$P103" | grep -c .)|$(git -C "$REPO" diff --no-renames --name-only --diff-filter=CRTUXB "$P102" "$P103" | grep -c .)|$(git -C "$REPO" diff --name-only "$P102" "$P103" | grep -vc "^$P/")" "0|$NA103|$N103|0|0" \
  "the delta: $N103 files under the plugin, $NA103 of them added, none deleted, renamed or retyped, nothing outside it"
check "$(dl A "$P102" "$P103" | sed "s#^$P/##" | LC_ALL=C sort | paste -sd' ' -)|$(dl M "$P102" "$P103" | sed "s#^$P/##" | LC_ALL=C sort | paste -sd' ' -)" \
      "$(tr ' ' '\n' <<<"$ADDED103" | LC_ALL=C sort | paste -sd' ' -)|$(tr ' ' '\n' <<<"$CHANGED103" | LC_ALL=C sort | paste -sd' ' -)" "…exactly the reviewed files, added and changed, as the script lists them"
check "$(dl AM "$P102" "$P103" | grep -c "^$P/migrations/")" "0" "no migration"
PS103="$(sed -n '/^PIN_SHA256S="$(cat <<.SUMS.$/,/^SUMS$/p' "$DEPLOY103" | grep -E '^[0-9a-f]{64}  ' | while read -r s f; do [ "$(git -C "$REPO" show "$P103:$P/$f" | sha256sum | cut -c1-64)" = "$s" ] && echo ok || echo "bad:$f"; done | sort | uniq -c | tr -s ' ' | paste -sd, -)"
KS103="$(sed -n '/^PREV_KEPT_SHA256S="$(cat <<.SUMS.$/,/^SUMS$/p' "$DEPLOY103" | grep -E '^[0-9a-f]{64}  ' | while read -r s f; do a="$(git -C "$REPO" show "$P102:$P/$f" | sha256sum | cut -c1-64)"; b="$(git -C "$REPO" show "$P103:$P/$f" | sha256sum | cut -c1-64)"; [ "$a|$b" = "$s|$s" ] && echo ok || echo "bad:$f"; done | sort | uniq -c | tr -s ' ' | paste -sd, -)"
PC103="$(sed -n '/^PREV_CHANGED_SHA256S="$(cat <<.SUMS.$/,/^SUMS$/p' "$DEPLOY103" | grep -E '^[0-9a-f]{64}  ' | while read -r s f; do [ "$(git -C "$REPO" show "$P102:$P/$f" | sha256sum | cut -c1-64)" = "$s" ] && echo ok || echo "bad:$f"; done | sort | uniq -c | tr -s ' ' | paste -sd, -)"
KN103="$(sed -n 's/^PREV_KEPT_N=\([0-9]*\)$/\1/p' "$DEPLOY103")"
check "$PS103|$KS103|$PC103|$(sed -n 's/^PIN_SHA_N=\([0-9]*\)$/\1/p' "$DEPLOY103"),$(sed -n 's/^PREV_CHANGED_N=\([0-9]*\)$/\1/p' "$DEPLOY103")" " $N103 ok| $KN103 ok| $NC103 ok|$N103,$NC103" \
  "every sha256 the script pins, computed here from Git: the $N103 release files at the pin, the $KN103 kept ones in both commits, the $NC103 changed ones at 5.18.102 — and the counts it states"
KEEPL103="$(sed -n '/^PREV_KEPT_SHA256S="$(cat <<.SUMS.$/,/^SUMS$/p' "$DEPLOY103")"
check "$(for f in webhook.php cron_quote_wa.php api/index.php lib/QuoteOrganization.php lib/QuoteTaxLine.php lib/TenantProfile.php lib/PublicPriceFeed.php lib/UcrmQuoteLinks.php prices.php shop.php tools/set_config.php; do grep -cE "  ${f//./\\.}\$" <<<"$KEEPL103"; done | paste -sd' ' -)|$(for f in $ADDED103 $CHANGED103; do grep -cE "  ${f//./\\.}\$" <<<"$KEEPL103"; done | sort -u | paste -sd, -)" \
      "1 1 1 1 1 1 1 1 1 1 1|0" \
  "…the kept files hold webhook.php, cron_quote_wa.php, api/index.php, the quoting files 5.18.103 keeps byte for byte (QuoteOrganization, QuoteTaxLine, TenantProfile, PublicPriceFeed, UcrmQuoteLinks), prices.php, shop.php and tools/set_config.php, once each — and none of the files the release adds or changes"
check "$(sed -n 's/^COPY_MARK="\([^"]*\)".*/\1/p' "$DEPLOY103")|$(grep -cxF "$P/$(sed -n 's/^COPY_MARK="\([^"]*\)".*/\1/p' "$DEPLOY103")" <<<"$(dl M "$P102" "$P103")")|$(case "$(sed -n 's/^T1_SIGNATURE="\([^"]*\)".*/\1/p' "$DEPLOY103")" in [1-9]*' passed, 0 failed rc=0') echo pinned ;; *) echo unpinned ;; esac)" \
      "lib/KycService.php|1|pinned" \
  "the copy's time is read off a file this release changes (COPY_MARK, lib/KycService.php); T1's count pinned, carried as A22-T1 (c5)"
H103B="$(sed -n '/^# The deploy\. Run as root/,/^# The rollback is a separate command/p' "$DEPLOY103")"
H103H="$(sed -n '1,/^set -uo pipefail$/p' "$DEPLOY103")"
check "$(grep -cxF '#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.103 && mkdir -p /root/dnb-5.18.103 && bash scripts/deploy-5.18.103.sh 2>&1 | ( umask 077; exec tee /root/dnb-5.18.103/deploy-$(date -u +%Y%m%dT%H%M%SZ).log )' <<<"$H103B")$(grep -cxF '#   cd /opt/dishnet && bash scripts/deploy-5.18.103.sh --after-only 2>&1 | ( umask 077; exec tee /root/dnb-5.18.103/after-$(date -u +%Y%m%dT%H%M%SZ).log )' <<<"$H103B")$(grep -cE -- '--rollback|--put-back|--redeploy|git checkout [0-9a-f]{7}' <<<"$H103B")|$(grep -cE '^#   cd ' <<<"$H103B")|$(grep -cE '^#   cd ' <<<"$H103H")|$(grep -cE '^#   cd .*--(accept-unpriced|rollback)' <<<"$H103H")|$(grep -c '^# The deploy\. Run as root' <<<"$H103H")$(grep -c '^# The rollback is a separate command' <<<"$H103H")" \
      "110|2|2|0|11" "the header's two commands, between its two marker lines (each once), each alone on its line with its own log — the deploy carrying no --accept-unpriced; no rollback among them, and no other command in the header"
echo "  …    the sign-off and the owner rule as text, at the pin, at 5.18.102 and at 5.18.100, by the script's own readers:"
check "$(qs_read101 "$DEPLOY103" "$P103")|$(qs_read101 "$DEPLOY103" "$P102")|$(qs_read101 "$DEPLOY103" "$P100")" \
      "$(sed -n 's/^QS_TEXT_PIN="\([^"]*\)".*/\1/p' "$DEPLOY103")|$(sed -n 's/^QS_TEXT_BASE="\([^"]*\)".*/\1/p' "$DEPLOY103")|$(sed -n 's/^QS_TEXT_CONTROL="\([^"]*\)".*/\1/p' "$DEPLOY103")" \
  "control: QS-G1's reader gives the pin QS_TEXT_PIN and 5.18.102 QS_TEXT_BASE (5.18.103 keeps webhook.php), and 5.18.100, its negative control, QS_TEXT_CONTROL — all read from Git here"
check "$(tn_read101 "$DEPLOY103" "$P103")|$(tn_read101 "$DEPLOY103" "$P102")" \
      "$(sed -n 's/^TN_TEXT_PIN="\([^"]*\)".*/\1/p' "$DEPLOY103")|$(sed -n 's/^TN_TEXT_BASE="\([^"]*\)".*/\1/p' "$DEPLOY103")" \
  "control: TN-G1's reader gives the pin and 5.18.102 the rule the script pins (TN_TEXT_PIN, TN_TEXT_BASE)"
RB1N103="$(grep -c . <<<"$RB1M103")"
check "$RB1N103|$(sed -n 's/^\[ "\$QCRB1_N" = "\([0-9]*\)" \].*/\1/p' "$DEPLOY103")|$(while IFS='|' read -r f m n; do printf '%s:%s:%s\n' "$( [ "$(git -C "$REPO" show "$P103:$P/$f" | grep -cF -- "$m")" = "$n" ] && echo pin || echo "pin-differs")" "$(git -C "$REPO" show "$P102:$P/$f" 2>/dev/null | grep -cF -- "$m")" "$(grep -cxF "$P/$f" <<<"$(dl M "$P102" "$P103")")"; done <<<"$RB1M103" | sort | uniq -c | tr -s ' ' | paste -sd, -)|$(git -C "$REPO" show "$P103:$P/lib/UcrmCatalogue.php" | grep -cF UcrmCatalogue | awk '{print ($1 > 0 ? "class" : "noclass")}'):$(git -C "$REPO" show "$P103:$P/lib/UcrmCatalogue.php" | grep -cF ucrm_catalogue | awk '{print ($1 > 0 ? "table" : "notable")}')|$(git -C "$REPO" grep -lF -e UcrmCatalogue -e ucrm_catalogue "$P102" -- "$P" 2>/dev/null | grep -c .)" \
      "$RB1N103|$RB1N103| $RB1N103 pin:0:1|class:table|0" \
  "QC-RB1's markers, counted in Git: as many as the script's own count says; each file names its needle at the pin exactly as the script pins it, 5.18.102's file names it nowhere, each a file the release changes; the sweep's control, the pin's lib/UcrmCatalogue.php, names both the class and the table; no file 5.18.102 tracks names either"
check "$(grep -cE "^(QL_RB1_MARKS|QL_RB3_MARKS)=|^qlrb_(judge|count)\(\)" "$DEPLOY103")|$(grep -F -- '--rollback-unpriced-pending' "$DEPLOY103" | grep -cvE '^[^#]*#.*--rollback-unpriced-pending')|$(grep -cE '"(QL-RB[123]|QL-LK1|QL-CA1) ' "$DEPLOY103")" "0|0|0" \
  "QL-RB1–QL-RB3, QL-CA1, QL-LK1 and --rollback-unpriced-pending are retired: no marker block or reader of theirs, no check line said under their names, and the flag only in comments (103-10 shows the option refused)"
echo; echo "== 103-c. --check: stage A alone — GO on 5.18.102 as production has it; what the forms would offer from uCRM; its notes =="
FP103="$(fp99)"; fresh103; S103C="$(states103)"; OUT="$(run103 --check)"; TC="$(last103)"; SH103C="$(ls -1 "$OUT103"/cash-*.txt 2>/dev/null | head -1)"
check "$(checkgo103 "$OUT")|$( [ -e "$OUT103/ql-key-5.18.103" ] && echo key || echo nokey)" "yesyesnono0|0|$S103C|nokey" "c1 --check is GO, never asks, backs nothing up, writes no state file and no QL key file"
QCG1_OK103="  ok    QC-G1 what the forms would offer in direct mode is read and judged through the RELEASE's UcrmQuoteLines::offered() on a copy, GET-only — GET products?limit=500, then GET service-plans?limit=500, nothing else — with the copy's catalogue settings (none: the table was never written on this book), every answer classified by the release's why codes: through the plugin's own client $(qcsay103 "$QCA103"); through the token client $(qcsay103 "$QCT103"); per item its name, uCRM ids, price and unit, or its reason, on the sheet"
check "$(has "$OUT" "$QCG1_OK103")|$(qor103 "$OUT" 1 qcc)|$(qor103 "$OUT" 1 qcwhy)|$(qor103 "$OUT" 1 qcread)|$(qor103 "$OUT" 1 qcpath)|$(qor103 "$OUT" 1 qcinst)|$(qor103 "$OUT" 1 qcstd)|$(qor103 "$OUT" 1 qcstock)" \
      "yes|$QCC_EXP|$QCW_EXP|plug:read;tok:read|ok|$QCI_EXP|$QCS_EXP|$QCK_EXP" \
  "c2 QC-G1 ok — the probe's counts, its not-offered by why code, its read states and path, the installation and the standard quotations, the offered hardware without a stock category of its title: through both clients, exactly as the release's own offered() gives them (pre-g)"
check "$(noteline "$OUT" QC-G1 'not offered, by why — through the plugin' 'a note, never toward the NO-GO')$(noteline "$OUT" QC-N1 "the uCRM catalogue's switch on stage A's copy: never set — after the copy 5.18.103 behaves as 5.18.102 until an admin turns it on on the uCRM catalogue screen")$(noteline "$OUT" QC-N2 "the installation product (the copy's: unset): through the plugin's own client not set; through the token client not set" "standard quotations defined and usable now: 0 defined, 0 usable (the token client: 0 defined, 0 usable)")|$(qol103 "$OUT" qccat)|$(qol103 "$OUT" qcsw)|$(qol103 "$OUT" qcinst)|$(qol103 "$OUT" qcstd)" \
      "yesyesyes|absent|none|unset|0" \
  "c3 the notes on the copy's settings: QC-G1's left-out items by why, QC-N1 never set, QC-N2 the installation not set and no standard quotation, through both clients; the local reading: no table, no switch, no installation, no standard quotation"
check "$(qol103 "$OUT" qcentry)|$(qol103 "$OUT" qcwebp1)|$(qol103 "$OUT" qcg7)|$(qol103 "$OUT" qcplantype)" "$(qkv103 "$A103_0" entry)|$(qkv103 "$A103_0" webp1)|$(qkv103 "$A103_0" g7)|$(qkv103 "$A103_0" plantype)" \
  "c3b the local reading's applications by entry, 5.18.102's waiting web P1s, the web-direct records by G-7 and the active plans by type, as this rehearsal's own reading of the store counts them"
WEBP1_103="$(qkv103 "$A103_0" webp1)"; STK103="$(qcf103 "$QCREF_A" 5)"
check "$(noteline "$OUT" QC-N3 "the applications by entry (quote_sale_entry, which every application saved since 5.18.102's copy carries): $(n3say103 "$(qkv103 "$A103_0" entry)")")$(if [ "$WEBP1_103" = "0" ]; then noteline "$OUT" QC-N4 'no web application of 5.18.102 is waiting with P1 and no quotation'; else noteline "$OUT" QC-N4 "$WEBP1_103 web application(s) of 5.18.102 refused P1 with no quotation"; fi)$(if [ "$STK103" = "0" ]; then noteline "$OUT" QC-N5 'every hardware item offered through the plugin' "($(qol103 "$OUT" qcstock))"; else noteline "$OUT" QC-N5 "$STK103 hardware item(s) offered from uCRM have no active stock category of the same title ($(qol103 "$OUT" qcstock))"; fi)$(noteline "$OUT" QC-N6 "public_price_exclude ($(qol103 "$OUT" qcppx))")$(noteline "$OUT" QC-N7 "the plugin's own active plans by type: $(qkv103 "$A103_0" plantype | sed 's/:/ /g; s/,/, /g') — with the switch on none of them is drawn")" \
      "yesyesyesyesyes" \
  "c4 QC-N3–QC-N7 as seeded: the entries and the waiting web P1s by this rehearsal's own count, the hardware with no stock category of its title by the release's own reading, public_price_exclude as the copy read it, the plan types"
check "$(okline "$OUT" QL-G1 'the catalogue the KYC form offers is read and judged')$(okline "$OUT" QS-G1 'as text: the pin')$(okline "$OUT" TN-G1 'as text')$(okline "$OUT" A6 'South Sudan stays exactly as it is')$(okline "$OUT" A22-T1 "under this server's own PHP")$(okline "$OUT" Q-G3 'quoting organization #1')|$(grep -c '^  STOP: ' <<<"$OUT")" \
      "yesyesyesyesyesyes|0" \
  "c5 the carried checks ok — QL-G1 (5.18.102's), QS-G1, TN-G1, A6 and A22-T1 (T1's count as pinned), Q-G3; no stop"
TOT103C="$(ureq103 "$TC" | sed -E 's/^total=([0-9]+) .*/\1/')"
check "$(ureq103 "$TC" | sed -E 's/^total=[0-9]+ //')|$TOT103C|$(lim103 "$TC")|$(uother103 "$TC")" "other=0 products=3/3 plans=3/3 attrs=2 quote=0 odd=0|$(gets103 "$OUT")|n=12 bad=0|-" \
  "c6 the stand-in was asked the two lists three times through each client — QL-G1's catalogue, its raw organization reads and QC-G1's offered(), each products?limit=500 then service-plans?limit=500 — every one with limit=500; the attributes twice; every request a GET, as many as F counts"
RH103_SECTION_OUT="$H103/v/qcsec.txt"; export RH103_SECTION_OUT
QCSEC103="$(python3 "$H103/rh103q.py" section "$SH103C" '== QC what the forms offer from uCRM in direct mode — ')"
check "$(stat -c %a "$SH103C" 2>/dev/null)|$(stat -c %a "$OUT103")|$(python3 "$H103/rh103q.py" sheet "$SH103C")|$( [ "${QCSEC103:-0}" -ge 3 ] 2>/dev/null && echo lines)|$(grep -cF -f "$NAMES102" "$RH103_SECTION_OUT" 2>/dev/null | awk '{print ($1 >= 1 ? "names" : "none")}')|$(grep -lF -f "$NAMES102" "$R103/run-$TC.txt" "$OUT103"/*.log 2>/dev/null | grep -c .)" \
      "600|700|offer:1,set:1,rb2:0,on4:0|lines|names|0" \
  "c7 the sheet, 0600 in a folder of mode 700: one section of what the forms would offer — each item by name with its uCRM ids, price and unit, or its reason — and one of the catalogue's settings and the applications; the seeded names there and in no terminal or log"
rm -f "$RH103_SECTION_OUT"
check "$(fp99)|$(names100 "$R103/run-$TC.txt")|$(audit103 "$TC" | sed -E 's/^copies=[0-9]+ //; s/ adj=.*//')|$(vault103 "$TC" | awk -F'>' '{print ($1 == $2 ? "same" : "moved")}')|$(cache103)|$(catq103)" \
      "$FP103|0|novault=0 plugin=0|same|0|absent" \
  "c8 nothing changed — tree, store, vault, settings, ucrm.json; no ucrm_catalogue table on the live book (stage A read the copy's through the pin's UcrmCatalogue, which asks sqlite_master first); every PHP process on a copy with a vault of its own; no page cache or last good copy written anywhere"
echo; echo "== 103-1. QC-G1's refusals, each before anything changes; its weakened copies; two switch records in force =="
u99_set 'products_500=true'; fresh103; OUT="$(run103 --answer NO)"
nogo103 "1a uCRM's Products unreadable (500): NO-GO at QL-G1 first, as the script orders its checks — QC-G1 never reached" "NO-GO (QL-G1): uCRM's Products and Service Plans could not be read through the plugin's own client" "$OUT"
check "$(grep -c '^  ok    QC-G1 \|NO-GO (QC-G1)' <<<"$OUT")" "0" "…QC-G1 neither passed nor refused: the run stopped before it"
u99_set 'products_500_at=[5,6]'; fresh103; OUT="$(run103 --answer NO)"; T1B="$(last103)"
nogo103 "1b QC-G1's own reading refused (the fifth and sixth Products reads, after QL-G1's four): NO-GO at QC-G1 through the plugin's own client" "NO-GO (QC-G1): uCRM's Products and Service Plans could not be read through the plugin's own client, GET-only (http500) — the uCRM catalogue screen, Send Quotation and the lead quotes read them so. No flag lifts it." "$OUT"
check "$(okline "$OUT" QL-G1 'the catalogue the KYC form offers is read and judged')|$(qor103 "$OUT" 1 qcread)|$(qor103 "$OUT" 1 qcpath)|$(ureq103 "$T1B" | sed -E 's/^total=[0-9]+ //')" "yes|plug:http500;tok:http500|ok|other=0 products=3/3 plans=2/2 attrs=2 quote=0 odd=0" \
  "…QL-G1 ok in the same run; QC-G1's reading through both clients http500, its path the release's own — no service plans asked once the products failed (path ok)"
u99_set 'products_500_at=[6]'; fresh103; OUT="$(run103 --answer NO)"; T1C="$(last103)"
nogo103 "1c the token client's reading refused alone (the sixth Products read): NO-GO at QC-G1 through the token client" "NO-GO (QC-G1): uCRM's Products and Service Plans could not be read through the token client (crm_auth_token), GET-only (http500) — the KYC form, the submission and the retries read them so. No flag lifts it." "$OUT"
check "$(qor103 "$OUT" 1 qcread)|$(ureq103 "$T1C" | sed -E 's/^total=[0-9]+ //')" "plug:read;tok:http500|other=0 products=3/3 plans=3/2 attrs=2 quote=0 odd=0" "…the plugin's own client read both lists, the token client only the refused one"
u99_set 'products_500_at=[5,6]'; M="$(weak103 qcg1w qcg1p qcg1t)"; fresh103; OUT="$(R103_MUTANT_OUT=1 run103 --script "$M" --check)"; rm -f "$M"
check "$(has "$OUT" 'NO-GO (QC-G1): uCRM'"'"'s Products and Service Plans could not be read')$(has "$OUT" 'NO-GO (QC-G1): the items'"'"' keyed hashes were not made (none)')$(has "$OUT" '5.18.103 (check): GO')" "noyesno" \
  "1d QC-G1's two unreadable refusals weakened: their own refusal is gone (caught) — the run still stops, at the item hashes no client could make, a second guard behind them"
u99_set
M="$(weak103 qcpathm qcpathm)"; fresh103; OUT="$(R103_MUTANT_OUT=1 run103 --script "$M" --check)"; rm -f "$M"
check "$(has "$OUT" "NO-GO (QC-G1): the RELEASE's offered() asked uCRM, through the plugin client and the token client, for something other than its two lists once each (GET products?limit=500, then GET service-plans?limit=500)")$(has "$OUT" '5.18.103 (check): GO')|$(qor103 "$OUT" 1 qcpath)|$(quiet103)" "yesno|bad:plug,tok|$P102|0|$D103|$KEPT103|0|0|absent" \
  "1e a probe whose offered() window holds one more GET than its two lists, through each client: QC-G1's path check refuses, naming both clients — nothing changed"
M="$(weak103 qcpathw qcpathm qcpathw)"; fresh103; OUT="$(R103_MUTANT_OUT=1 run103 --script "$M" --check)"; rm -f "$M"
check "$(has "$OUT" '5.18.103 (check): GO')$(has "$OUT" "NO-GO (QC-G1): the RELEASE's offered() asked uCRM")|$(qor103 "$OUT" 1 qcpath)" "yesno|bad:plug,tok" "1e' …and with QC-G1's path check weakened: a --check GO over the same window (caught)"
NOTN103=$(( 2 * $(n103 "$(sk103 "$QCA103" not)") ))
M="$(weak103 qcotherm qcotherm)"; fresh103; OUT="$(R103_MUTANT_OUT=1 run103 --script "$M" --check)"; rm -f "$M"
check "$(has "$OUT" "NO-GO (QC-G1): $NOTN103 answer(s) of the RELEASE's offered() cannot be classified (\"other\": a why code outside the release's set, or an empty reason)")$(has "$OUT" '5.18.103 (check): GO')|$(quiet103)" "yesno|$P102|0|$D103|$KEPT103|0|0|absent" \
  "1f a probe that cannot classify any answer of offered(): QC-G1 refuses, counting every item left out through both clients — nothing changed"
M="$(weak103 qcotherw qcotherm qcotherw)"; fresh103; OUT="$(R103_MUTANT_OUT=1 run103 --script "$M" --check)"; rm -f "$M"
check "$(has "$OUT" '5.18.103 (check): GO')$(has "$OUT" 'cannot be classified')" "yesno" "1f' …and with that refusal weakened: a --check GO over unclassified answers (caught)"
M="$(weak103 qcnocatm qcnocatm)"; fresh103; OUT="$(R103_MUTANT_OUT=1 run103 --script "$M" --check)"; rm -f "$M"
check "$(has "$OUT" "NO-GO (QC-G1): the quoting organization is in force, and the RELEASE's lib/UcrmCatalogue.php was not where the local reading loads it, the pin's own file by its path (?nocat) — the catalogue's settings on the copy cannot be read. No flag lifts it.")$(has "$OUT" '5.18.103 (check): GO')|$(qol103 "$OUT" qccat)|$(quiet103)" "yesno|?nocat|$P102|0|$D103|$KEPT103|0|0|absent" \
  "1g the local reading pointed at no lib/UcrmCatalogue.php (cat=): it reads ?nocat, the remote reading has no settings to judge (nooffer), and QC-G1 refuses in the settings' own words — nothing changed"
M="$(weak103 qcnocatn qcnocatm qcnocatn)"; fresh103; OUT="$(R103_MUTANT_OUT=1 run103 --script "$M" --check)"; rm -f "$M"
check "$(has "$OUT" "NO-GO (QC-G1): what the forms would offer could not be read on the copy (nooffer: the copy's catalogue settings, stock categories or public_price_exclude were not read). No flag lifts it.")$(has "$OUT" '(?nocat) — the catalogue')$(has "$OUT" '5.18.103 (check): GO')" "yesnono" "1g' …with the ?nocat refusal weakened: its words are gone and the general refusal still stops the run (nooffer) — the words came from that line"
M="$(weak103 qcnocatw qcnocatm qcnocatn qcnocatw)"; fresh103; OUT="$(R103_MUTANT_OUT=1 run103 --script "$M" --check)"; rm -f "$M"
check "$(has "$OUT" '5.18.103 (check): GO')$(has "$OUT" 'NO-GO (QC-G1)')" "yesno" "1g'' …and with the general refusal weakened too: a --check GO with no catalogue settings read (caught)"
M="$(weak103 qchashm qchashm)"; fresh103; OUT="$(R103_MUTANT_OUT=1 run103 --script "$M" --check)"; rm -f "$M"
check "$(has "$OUT" "NO-GO (QC-G1): the items' keyed hashes were not made (?nokey) — QC-CA1 would have nothing to compare after the copy, item by item.")$(has "$OUT" '5.18.103 (check): GO')|$(quiet103)" "yesno|$P102|0|$D103|$KEPT103|0|0|absent" \
  "1h a probe that makes no item hash: QC-G1 refuses — QC-CA1 would have nothing to compare — nothing changed"
M="$(weak103 qchashw qchashm qchashw)"; fresh103; OUT="$(R103_MUTANT_OUT=1 run103 --script "$M" --check)"; rm -f "$M"
check "$(has "$OUT" '5.18.103 (check): GO')$(has "$OUT" "keyed hashes were not made")" "yesno" "1h' …and with that refusal weakened: a --check GO with no hash to compare after the copy (caught)"
# ── 1i: two switch records in force — a state the release's writers never make (setMode ends the one in force first),
# planted raw through the PIN's writer on the live book (5.18.102 serves and reads none of it): the release reads it as off
# and QC-N1 says so; QC-G1 still ok. The table dropped again: the data as before, byte for byte. ───────────────────────────
TWO103="$(cat103 "$REF103/$P" '[{"op":"raw","record":{"kind":"mode","on":true}},{"op":"raw","record":{"kind":"mode","on":true}}]')"
check "$TWO103|$(python3 "$H103/rh103q.py" cat "$DATA/plugin.sqlite3")" '{"ops":2,"kinds":["raw","raw"],"ids":[1,2],"records":2,"inforce":2,"switch":"two"}|records:2,inforce:2|switch:two|install:unset|std:0|hidden:0' \
  "1i the plant: two switch records in force, raw — the table made by the first write"
fresh103; OUT="$(run103 --check)"
check "$(checkgo103 "$OUT" | cut -d'|' -f1)|$(noteline "$OUT" QC-N1 'two switch records are in force on stage A'"'"'s copy — the release reads that as off (it never writes two); an admin sets it again on the uCRM catalogue screen')|$(okline "$OUT" QC-G1 "with the copy's catalogue settings (2 record(s), 2 in force)")|$(qol103 "$OUT" qcsw)" \
      "yesyesnono0|yes|yes|two" \
  "1i' --check over them: GO; QC-N1 says the two records are read as off; QC-G1 ok with the copy's two records"
check "$(sqn102 "DROP TABLE ucrm_catalogue")|$(catq103)|$(data94)" "0|absent|$D103" "…the table dropped again: the live book as before, byte for byte"
echo; echo "== 103-4. the deploy, as the operator runs it — DEPLOY typed; QC-CA1 equal to stage A's item by item =="
LEDGER103="$(ledger95)"
fresh103; OUT="$(run103 --answer DEPLOY)"; OUT103D="$OUT"; T103D="$(last103)"
echo "  …    the deploy's own tally:$(grep -E '^  checks +[0-9]+ ok, ' <<<"$OUT" | sed -E 's/^  checks +/ /')"
check "$(fails "$OUT")$(has "$OUT" '5.18.103 (deploy): PASSED')$(live)$(installed103 "$P103")$(inst_ver)|$(left103)" "0yes${P103}05.18.103|$NA103" "4 the deploy PASSES: 0 failed, $P103 serving, every file as the pin has it, the $NA103 it adds among them"
QCCA1_OK103="  ok    QC-CA1 the INSTALLED UcrmQuoteLines::offered(), on a fresh copy and GET-only, offers exactly what stage A's pin offered, item by item (compared under this run's QL key, never printed: $(cmpsay103 "$ITEMS103")) — the same plans and hardware at the same uCRM ids, prices and units, the same reason for each item left out; through the plugin's own client $(qcsay103 "$QCA103")"
for l in "ok    A0 the release delta is exactly 5.18.103's $N103 files — $NA103 added, $NC103 changed, each the reviewed one by the sha256 this script pins ($N103 of $N103)" \
         "ok    BR1 the installed plugin is live 5.18.102 ($P102): all " "ok    Q-G1 installed is 5.18.102 ($P102)" \
         "ok    A14 the stray store is quarantined and the quarantine holds" "ok    A22-T1 under this server's own PHP" "ok    Q-G3 quoting organization #1" \
         "${QCG1_OK103#  }" "GO — evidence recorded" \
         "ok    the QL key kept in $OUT103/ql-key-5.18.103 (mode 0600, never printed) — QC-CA1 of a later --after-only compares under it" \
         "ok    container serves $P103" "ok    R1 the installed manifest says 5.18.103" "ok    Q-CA3 nothing was created in uCRM" \
         "${QCCA1_OK103#  }" \
         "ok    QC-CA2 the uCRM catalogue screen (?page=dashboard&tab=ucrm_catalogue) without a session sends the visitor to the sign-in page (302) — none of its actions in the answer, no PHP fatal" \
         "ok    QC-CA2 the installed includes/post/post_sync.php, read as text (comments aside): the uCRM catalogue's five actions — ucrm_cat_mode, ucrm_cat_install, ucrm_cat_hidden, ucrm_cat_standard_save and ucrm_cat_standard_end — are POST-only, inside QuoteOrganization::setting(...)['on'], and call \$auth->requireAdmin(), then the account's fresh re-read, then the own-page check, before any write — beside its control, the pin's from Git, which reads the same" \
         "ok    QL-CA2 the client attribute keyed salesPerson reads as stage A read it: exactly one, #47" \
         "ok    QL-CA3 the Plans and Hardware screens" "ok    Z1 the live vault" "ok    Z2 nothing of the vault" \
         "  names             QC-G1, QC-N1–QC-N7, QC-CA1–QC-CA3, QC-ON1–QC-ON4, QC-RB1 and QC-RB2 are 5.18.103's own checks"; do
  check "$(has "$OUT" "$l")" "yes" "4: ${l:0:110}"
done
check "$(has "$OUT" "ok    QC-CA3 Send Quotation and the lead quotes re-price each picked uCRM line inside QuotationService from a fresh read: the installed post_leads.php calls QuotationService::directForm( and ::pickedLine(, and lib/QuotationService.php defines directForm, pickedLine and directLines and refuses a changed price")|$(qor103 "$OUT" 1 qcc)|$(qor103 "$OUT" 2 qcc)|$(qor103 "$OUT" 2 qcwhy)|$(qor103 "$OUT" 2 qcinst)|$(grep -E '^  uCRM, GET-only  ' <<<"$OUT" | grep -cE '(^| )qch=|[0-9a-f]{32}')|$(grep -cE '^  (ok    |FAIL  |note  )QL-(CA1|LK1|RB[123]) ' <<<"$OUT")" \
      "yes|$QCC_EXP|$QCC_EXP|$QCW_EXP|$QCI_EXP|0|0" \
  "4a QC-CA3 as the release reads it; stage A's catalogue and the installed offered()'s after the copy read the same counts, reasons and installation; no keyed hash in what the probe echoes; no retired check line"
check "$(python3 "$H103/rh103q.py" stateqc "$STATE103")|$(grep -c '^QLG1_NOTED=$' "$STATE103")|$(python3 "$H102/rh102q.py" keychk "$OUT103/ql-key-5.18.103" "$OUT103"/state-5.18.103.env* "$OUT103"/rollback-state-* 2>/dev/null)" \
      "qca:$QCC_EXP|items:$ITEMS103:shaped|sw:none|cat:absent|names:0|1|600|oneline|absent" \
  "4b the state file records QC_A (stage A's counts by client), QC_ITEMS (each item's keyed hash with its verdict's, as many as the release's items through both clients), the switch and the table as stage A read them — no name; no flag decided QL-G1; the QL key in its own file, mode 0600, one QL_KEY= line of 48 hex, and in no state file"
check "$(grep -c "^COPY_AT=$(stat -c %Y "$PD/lib/KycService.php")\$" "$STATE103")|$(epoch99 "$(sed -n 's/^COPY_AT=//p' "$STATE103" | tail -1)")" "1|epoch" "4c the copy's time, as stamp_copy gave lib/KycService.php — a file this release changes"
SUM103="$(sed -n '/^== F\. Summary ==$/,$p' <<<"$OUT")"
check "$(grep -cxF "      $EXP_AFTER103" <<<"$SUM103")$(grep -cxF "      $EXP_QCHECK" <<<"$SUM103")$(grep -cxF "      $EXP_ROLLBACK103" <<<"$SUM103")$(grep -cxF "      $EXP_PUTBACK103" <<<"$SUM103")$(grep -cE '(deploy-5\.18\.103\.sh|quarantine-stray-store\.sh|deploy-hybrid\.sh).*(deploy-5\.18\.103\.sh|quarantine-stray-store\.sh|deploy-hybrid\.sh)' <<<"$OUT")|$(awk '/^  5\.18\.103 \((deploy|after)\): / { v = 1 } index($0, "deploy-5.18.103.sh --rollback") { print (v ? "after" : "before") }' <<<"$OUT" | paste -sd, -)|$(grep -c -- '--rollback-unpriced-pending' <<<"$OUT")|$(grep -c 'deploy-5\.18\.103\.sh --accept-unpriced' <<<"$OUT")" \
      "11110|after|0|0" \
  "4d the --after-only, the quarantine's --check, the rollback and the by-hand put-back, each printed exactly, alone on its line; the rollback once, after the verdict; --rollback-unpriced-pending nowhere; no command with --accept-unpriced (no flag was needed)"
check "$(has "$SUM103" "  what changed      on this Uganda server (Q-G2), the quoting organization #1 in force (Q-G3, Q-CA2): a new screen, the uCRM catalogue — in the menu for those who may see Plans or Hardware, its settings an admin's")$(has "$SUM103" "Its switch is off. With it off — as a deploy leaves it — 5.18.103 quotes exactly as 5.18.102: the KYC quotations through the uCRM link an admin records for each plan, kit and the installation.")$(has "$SUM103" "($WEBP1_103 waiting now, QC-N4). The retailer app and the mobile API keep the plugin's own lists. No migration (R7).")$(has "$SUM103" "  check it          once an admin has turned the uCRM catalogue on (your steps, 3, below) — an --after-only before that fails QC-ON1 by design — and at least 20 minutes after the copy (")" \
      "yesyesyesyes" \
  "4e F's \"what changed\": the new screen, its switch off as the deploy leaves it, 5.18.103 quoting as 5.18.102 until it is on, the waiting web applications as the copy counted them; \"check it\" after the switch is on"
for l in "  your steps        yours alone — this script does none of them (docs/72 §G.3, §G.13):" \
         "                    1. once this run has printed its last line, open the uCRM catalogue screen (Uganda), as an" \
         "                    2. where QC-N5 counted hardware with no stock category of the same title, title those" \
         "                    3. turn the switch on — the screen refuses while no plan is offered from uCRM;" \
         "                    4. then --after-only (check it, above): QC-ON1–QC-ON4 judge the switch, the installation" \
         "                    5. tell the agents: on Uganda the KYC form, Send Quotation and the lead quotes now offer uCRM's" \
         "                       $WEBP1_103 waiting now, their ids on the sheet); the retailer app and the mobile API keep the" \
         "                    Until step 3, 5.18.103 behaves as 5.18.102: nothing else is needed for it to" \
         "  Only if it is ever needed — the rollback, never pasted together with any other command (root docs/44 §16.9)." \
         "  are not changed, and the $NA103 files 5.18.103 adds stay on disk, reached by nothing." \
         "  counts nothing: the web-direct applications are then left unquoted, as above, and not counted:"; do
  check "$(grep -cxF -- "$l" <<<"$SUM103")" "1" "4f: ${l:0:110}"
done
check "$(awk -v c="      $EXP_ROLLBACK103" 'index($0, "  Only if it is ever needed — the rollback") == 1 { on = 1 } on && index($0, "      ") == 1 { print ($0 == c ? "alone" : "other"); exit }' <<<"$SUM103")|$(grep -A1 -xF '  counts nothing: the web-direct applications are then left unquoted, as above, and not counted:' <<<"$SUM103" | tail -1 | grep -cxF "      $EXP_PUTBACK103")" \
      "alone|1" "4g the rollback's block ends with the rollback command alone on its line; the put-back's hazard said before the put-back, alone on the next line"
check "$(grep -lF -f "$NAMES102" "$R103/run-$T103D.txt" "$STATE103" "$OUT103"/*.log 2>/dev/null | grep -c .)|$(names100 "$R103/run-$T103D.txt" "$STATE103" "$OUT103"/*.log)|$(secrets99 "$R103/run-$T103D.txt" "$STATE103")|$(audit103 "$T103D" | sed -E 's/^copies=([0-9]+) /\1 /' | awk '{print ($1 >= 20 ? "many" : $1 " only") " " $2 " " $3}')|$(uother103 "$T103D")" "0|0|0|many novault=0 plugin=0|-" \
  "4h no seeded name, no staff record's name and no stand-in secret printed or recorded; every PHP process on a copy with a vault of its own; GETs only"
check "$(ureq103 "$T103D" | sed -E 's/^total=[0-9]+ //')|$(ureq103 "$T103D" | sed -E 's/^total=([0-9]+) .*/\1/')|$(lim103 "$T103D")" "other=0 products=4/4 plans=4/4 attrs=4 quote=0 odd=0|$(gets103 "$OUT")|n=16 bad=0" \
  "4i the stand-in was asked the two lists four times through each client — stage A's three readings, then QC-CA1's after the copy (QL-CA1 retired: no catalogue reading of 5.18.102's after it) — the attributes four times, every request a GET with limit=500, as many as F counts"
SH103D="$(ls -1 "$OUT103"/cash-*.txt 2>/dev/null | head -1)"
check "$(python3 "$H103/rh103q.py" sheet "$SH103D")|$(ledger95)|$(both_copies "$BK97")|$(kept_state)$(oldname)$(host_code103)$(left_tmp103)$(evo_calls)$(cache103)|$(catq103)" "offer:2,set:2,rb2:0,on4:0|$LEDGER103|11|${KEPT103}00000|absent" \
  "4j the deploy's sheet: what the forms offer and the catalogue's settings, at stage A and after the copy; the migration ledger unchanged; ECO Bank's switch on in both places; the quarantine as it was; no code copy or run folder left; Evolution never called; no page cache; no ucrm_catalogue table made by the deploy"
echo; echo "== 103-5. --after-only: before the switch, by design a FAIL; the switch turned on by an admin; a direct sale; G-7; a standard quotation unusable; uCRM unreadable; the screen's guard =="
after_back103; COPY103="$(sed -n 's/^COPY_AT=//p' "$STATE103" | tail -1)"
ALINE="$(printed "$OUT103D" "cd $REPO && bash scripts/deploy-5.18.103.sh --after-only")"
check "$ALINE|$(epoch99 "$COPY103")" "$EXP_AFTER103|epoch" "5 the --after-only command, as the deploy's summary printed it; the copy moved thirty minutes back"
fresh103; OUT="$(run_line103 '' "$ALINE")"; TA="$(last103)"
check "$(fset103 "$OUT")|$(failline "$OUT" QC-ON1 "the uCRM catalogue is not switched on (none) — turn it on on the uCRM catalogue screen (Uganda), as an admin, then run --after-only again; until then 5.18.103 behaves as 5.18.102 (a run before it is on fails here by design)")$(failline "$OUT" QC-ON2 'the installation product: not set — a sale DishNet installs is unpriced in direct mode (P1, the agent told)')$(noteline "$OUT" QC-ON3 'no standard quotation is defined')$(noteline "$OUT" QC-ON4 'no web-direct application on the copy yet')" \
      "QC-ON1 QC-ON2:2|yesyesyesyes" \
  "5a --after-only before the switch is on FAILS, by design, at QC-ON1 (not switched on) and QC-ON2 (the installation not set) and nothing else; QC-ON3 (no standard quotation) and QC-ON4 (no direct sale yet) notes"
check "$(okline "$OUT" R19 'the quarantine holds: master ran the retry job at' "after the copy (at $(utc "$COPY103"))")$(okline "$OUT" QC-CA1 "what the forms offer, through the INSTALLED UcrmQuoteLines::offered() on a fresh copy, GET-only: through the plugin's own client $(qcsay103 "$QCA103"); through the token client $(qcsay103 "$QCT103"); not offered, by why: ")$(noteline "$OUT" QC-CA1 "against stage A's verdicts, item by item (the state file's hashes, under the deploy's QL key — never printed): $(cmpsay103 "$ITEMS103")")|$(ureq103 "$TA" | sed -E 's/^total=[0-9]+ //')|$(lim103 "$TA")" \
      "yesyesyes|other=0 products=1/1 plans=1/1 attrs=2 quote=0 odd=0|n=4 bad=0" \
  "5a' …R19 conclusive; QC-CA1 ok, its comparison with stage A's a note — every item the same; uCRM asked the two lists once per client, GETs only"
M="$(weak103 qcon1w qcon1w)"; fresh103; OUT="$(R103_MUTANT_OUT=1 run103 --script "$M" --after-only)"; rm -f "$M"
check "$(okline "$OUT" QC-ON1 'the uCRM catalogue is switched ON')|$(fset103 "$OUT")" "yes|QC-ON2:1" "5b QC-ON1 inverted: it says the catalogue is switched ON over a switch never set (caught) — the copy's QC-ON2 still fails"
# ── An admin turns it on through the INSTALLED release's own writers, as the uCRM catalogue screen's post handler calls
# them: the installation product (9105, judged passing on the screen's fresh read: pre-h), a standard quotation (plan 501
# with 9101, kit 9103 ×1, the installation), the switch on with the installation it judged. ─────────────────────────────
CATL103="$(cat103 "$PD" "$CATON103")"
check "$CATL103|$(python3 "$H103/rh103q.py" cat "$DATA/plugin.sqlite3")" \
      '{"ops":3,"kinds":["set","saved","on"],"ids":[1,2,3],"records":3,"inforce":3,"switch":"on"}|records:3,inforce:3|switch:on|install:set|std:1|hidden:0' \
  "5-on an admin turns the uCRM catalogue on through the installed release's own writers: the installation set, a standard quotation saved, the switch on — three records, the table made by the first"
D103="$(data94)"   # the catalogue's records are data: the baseline moves with each deliberate act of an admin's, never by a run
QCON_A="$(ref103 app "$PD")"; QCON_T="$(ref103 token "$PD")"; QCAON103="$(qcf103 "$QCON_A" 1)"; QCTON103="$(qcf103 "$QCON_T" 1)"
ITEMSON103=$(( $(n103 "$(qcf103 "$QCON_A" 6)") + $(n103 "$(qcf103 "$QCON_T" 6)") ))
check "$(qcf103 "$QCON_A" 3)|$(qcf103 "$QCON_A" 4)|$(qcf103 "$QCON_T" 3)|$(qcf103 "$QCON_T" 4)" "ok|1/1|ok|1/1" \
  "control: the INSTALLED offered() over the live book's settings: the installation passing, the standard quotation usable, through both clients"
fresh103; OUT="$(run_line103 '' "$ALINE")"
check "$(fails "$OUT")$(has "$OUT" '5.18.103 (after): PASSED')$(okline "$OUT" QC-ON1 'the uCRM catalogue is switched ON (the copy'"'"'s record): the KYC form, Send Quotation and the lead quotes offer uCRM'"'"'s plans and hardware on Uganda, and the plugin'"'"'s Plans and Hardware are hidden')$(okline "$OUT" QC-ON2 'the installation product is set and passes through the release'"'"'s own rules, through the plugin'"'"'s own client and the token client')$(okline "$OUT" QC-ON3 'every standard quotation (1) is usable now: its plan, hardware and installation are all offered')$(noteline "$OUT" QC-ON4 'no web-direct application on the copy yet')|$(qor103 "$OUT" 1 qcc)|$(qor103 "$OUT" 1 qcinst)|$(qor103 "$OUT" 1 qcstd)|$(has "$OUT" 'Its switch is ON.')|$(noteline "$OUT" QC-CA1 "against stage A's verdicts, item by item" "$ITEMSON103 compared — the same $((ITEMSON103 - 2)), different 2; not offered or left out at stage A 0, at stage A and not now $((ITEMS103 - ITEMSON103))")|$(grep -cE '^  FAIL  QC-CA1 ' <<<"$OUT")" \
      "0yesyesyesyesyes|plug:$QCAON103;tok:$QCTON103|plug:ok;tok:ok|plug:1/1;tok:1/1|yes|yes|0" \
  "5c the --after-only once the switch is on: PASSED — QC-ON1, QC-ON2 and QC-ON3 ok, QC-ON4 a note (no direct sale yet); the probe's counts the installed offered()'s over the new settings; F says the switch is ON; QC-CA1's comparison with stage A a note — the installation's verdict changed through each client, the product now the installation no longer hardware — never a FAIL"
# ── A direct sale, as the KYC form posts it with the switch on, through the INSTALLED code (rh103-sale.php): plan 501 with
# 9101, kit 9103 ×1, DishNet installs, Credit — priced from the stand-in, G-7 applied as handleNew applies it, the record
# appended through the installed store, pending for uCRM with no quotation. ──────────────────────────────────────────────
SALE103='{"plan":"501:9101","cart":[{"p":9103,"q":1}],"customer_type":"StarLink","install_by":"dishnet","sales_type":"Credit"}'
SALEO103="$(sale103 "$SALE103")"; A103_S="$(q103 apps)"
check "$SALEO103|$(qkv103 "$A103_S" g7)|$(qkv103 "$A103_S" qcrb2)|$(qkv103 "$A103_S" entry | sed -E 's/.*,direct:([0-9]+),.*/\1/')" \
      '{"status":"priced","lines":3,"entry":"web-direct","g7":"pc:empty,dev:empty,cart:ids0","install":"yes"}|{"rows":1}|direct:1,bad:0|direct:1,unquoted:1,pending:1,review:0,failed:0,synced:0|1' \
  "5e a direct sale through the installed code: priced (the plan, the kit and the installation — three lines), stored web-direct with package_choice and device_id empty and only id 0 in its cart (G-7); this rehearsal's own reading: one web-direct application, keeping G-7, pending for uCRM with no quotation"
D103="$(data94)"
fresh103; OUT="$(run_line103 '' "$ALINE")"; OUT103B="$OUT"
check "$(fails "$OUT")$(has "$OUT" '5.18.103 (after): PASSED')$(okline "$OUT" QC-ON4 'every web-direct application on the copy (1) keeps G-7: package_choice and device_id empty, and only id 0 in hw_cart_json and in quote_sale_cart, if any — nothing 5.18.102 would match to a plugin row after a rollback')" "0yesyes" \
  "5f --after-only over the direct sale: PASSED, QC-ON4 ok — the one web-direct application keeps G-7"
# ── A web-direct record breaking G-7 (package_choice '1', a plugin plan id), which no writer of the release makes. ─────
G7O103="$(sale103 "$SALE103" g7)"; A103_G="$(q103 apps)"
check "$G7O103|$(qkv103 "$A103_G" g7)" '{"status":"priced","lines":3,"entry":"web-direct","g7":"pc:empty,dev:empty,cart:ids0","install":"yes"}|{"rows":1}|direct:2,bad:1' \
  "5g the plant: the same sale's record with package_choice '1' — two web-direct applications, one breaking G-7, by this rehearsal's own reading"
fresh103; OUT="$(run_line103 '' "$ALINE")"; SH103G="$(ls -1 "$OUT103"/cash-*.txt 2>/dev/null | head -1)"
G7ID103="$(sq "SELECT id FROM kyc_applications WHERE json_extract(data, '\$._rh103g7') = 1")"
check "$(fset103 "$OUT")|$(failline "$OUT" QC-ON4 '1 of 2 web-direct application(s) break G-7 (package_choice or device_id set, or in hw_cart_json or quote_sale_cart an id that is not 0 or a cart that does not read) — ids on the sheet. Send the log file')|$(grep -cxF "  QC-ON4 web-direct applications that break G-7 (package_choice, device_id, or in hw_cart_json or quote_sale_cart an id that is not 0 or a cart that does not read): #$G7ID103" "$SH103G" 2>/dev/null)|$(grep -E 'QC-ON4|G-7' "$R103/line-$(last103).txt" | grep -cE "#$G7ID103([^0-9]|\$)")" \
      "QC-ON4:1|yes|1|0" \
  "5g' QC-ON4 FAILS on the record breaking G-7, and nothing else; its id on the sheet alone, never in the terminal"
M="$(weak103 qcon4w qcon4w)"; fresh103; OUT="$(R103_MUTANT_OUT=1 run103 --script "$M" --after-only)"; rm -f "$M"
check "$(okline "$OUT" QC-ON4 'every web-direct application on the copy (2) keeps G-7')$(fails "$OUT")" "yes0" "5h QC-ON4 blind to package_choice: it says both web-direct applications keep G-7 (caught)"
check "$(sqn102 "DELETE FROM kyc_applications WHERE json_extract(data, '\$._rh103g7') = 1")|$(data94)|$(qkv103 "$(q103 apps)" g7)" "1|$D103|direct:1,bad:0" "…the plant taken out: the data as before it, byte for byte"
# ── The standard quotation's kit archived in uCRM: the standard quotation can no longer be used — QC-ON3 FAILS. ─────────
ARCH103="$(python3 -c 'import json, sys; s = json.load(open(sys.argv[1])); print(json.dumps([dict(p, archived=True) if p.get("id") == 9103 else p for p in s["products"]]))' "$U99/base.json")"
u99_set "products=$ARCH103"
check "$(qcf103 "$(ref103 app "$PD")" 4)" "1/0" "control: with kit 9103 archived in the stand-in, the INSTALLED offered() and standardFrom() find the standard quotation unusable"
fresh103; OUT="$(run_line103 '' "$ALINE")"
check "$(fset103 "$OUT")|$(failline "$OUT" QC-ON3 '1 of 1 standard quotation(s) cannot be used now — an item in it is no longer offered; the uCRM catalogue screen, and the sheet, say which')$(okline "$OUT" QC-ON2 'the installation product is set and passes')$(okline "$OUT" QC-ON4 '(1) keeps G-7')" \
      "QC-ON3:1|yesyesyes" \
  "5j the standard quotation's kit archived in uCRM: QC-ON3 FAILS, and nothing else — the installation still passing, the direct sale still keeping G-7"
u99_set 'products_500=true'; fresh103; OUT="$(run_line103 '' "$ALINE")"; u99_set
check "$(fset103 "$OUT")|$(failline "$OUT" QC-CA1 "what the forms offer, through the INSTALLED offered(): unreadable through the plugin's own client (http500); unreadable through the token client (http500) — each item by name on the sheet")$(failline "$OUT" QC-ON2 'the installation product: not read (uCRM unreadable)')$(failline "$OUT" QC-ON3 '1 of 1 standard quotation(s) cannot be used now')" \
      "QC-CA1 QC-ON2 QC-ON3:3|yesyesyes" \
  "5k uCRM's Products unreadable at an --after-only: QC-CA1 FAILS naming both clients, QC-ON2 cannot read the installation and QC-ON3 no standard quotation usable — and nothing else"
check "$(u99_state_102)|$(data94)" "base|$D103" "…the stand-in at its base again; the data unchanged by the runs"
# ── requireAdmin() taken out of the INSTALLED post_sync.php's catalogue block: QC-CA2's text read fails beside its control,
# the pin's file from Git, which reads as it must; the file put back byte for byte. ──────────────────────────────────────
cp -p "$PD/includes/post/post_sync.php" "$SB/postsync103.kept"
python3 - "$PD/includes/post/post_sync.php" <<'PY' || echo "qcca2:post_sync" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]; s = open(p).read()
old = "        $_c3Me   = $auth->requireAdmin();\n"
assert s.count(old) == 1, s.count(old)
s2 = s.replace(old, "        $_c3Me   = $auth->requireLogin();\n", 1)
assert s2.count(old) == 0 and s2.count("        $_c3Me   = $auth->requireLogin();\n") == 1
open(p, 'w').write(s2)
PY
fresh103; OUT="$(run_line103 '' "$ALINE")"
cp -p "$SB/postsync103.kept" "$PD/includes/post/post_sync.php"; rm -f "$SB/postsync103.kept"
check "$(failline "$OUT" QC-CA2 "the installed includes/post/post_sync.php does not guard the uCRM catalogue's actions as the release does (" 'ucrm_cat:no-requireAdmin')|$(okline "$OUT" QC-CA2 'the uCRM catalogue screen (?page=dashboard&tab=ucrm_catalogue) without a session sends the visitor to the sign-in page (302)')|$(installed103 "$P103")|$(data94)" \
      "yes|yes|0|$D103" \
  "5l requireAdmin() taken out of the installed catalogue block: QC-CA2 FAILS by its text read, naming it, while the screen's anonymous refusal still holds; the file put back byte for byte, nothing else changed"
DX103="$(data94)"; fresh103; OUT="$(run103 --check)"
check "$(has "$OUT" "--check is stage A before a deploy, and the container already serves $P103 (5.18.103) — run --after-only instead")$(has "$OUT" 'Nothing was changed. Send the log file.')$(went103 "$OUT")$(has "$OUT" '5.18.103 (check): GO')|$(live)|$(data94)" "yesyesnonono|$P103|$DX103" \
  "5m --check with 5.18.103 live: refused, nothing changed"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "5n the quarantine's own --check after the --after-only runs, as the runbook orders: PASSED"
KEPT103="$(kept_state)"
echo; echo "== 103-6. the rollback: QC-RB2 at stage A and in RB, QC-RB1 and the table counted; a 5.18.103 file left installed; QC-RB1's control =="
RLINE="$(printed "$OUT103B" "cd $REPO && bash scripts/deploy-5.18.103.sh --rollback")"
check "$RLINE|$(awk '/^  5\.18\.103 \((deploy|after)\): / { v = 1 } index($0, "deploy-5.18.103.sh --rollback") { print (v ? "after" : "before") }' <<<"$OUT103B" | paste -sd, -)" "$EXP_ROLLBACK103|after" "6 the rollback command, as the --after-only's summary printed it after its verdict"
QCRB103="$(qkv103 "$(q103 apps)" qcrb2)"; S103SHA="$(sha256sum "$STATE103" | cut -c1-64)"
IDSF103="$H103/v/ids.txt"; q103 ids "$IDSF103" >/dev/null
fresh103; OUT="$(run_line103 ROLLBACK "$RLINE")"; T6A="$(last103)"; OUT103RB="$OUT"; SH103R="$(ls -1 "$OUT103"/cash-*.txt 2>/dev/null | head -1)"
check "$(fails "$OUT")$(has "$OUT" '5.18.103 (rollback): PASSED')$(live)$(installed103 "$P102")$(inst_ver)|$(left103)|$(reach103)" "0yes${P102}05.18.102|$NA103|0" \
  "6a ROLLBACK typed: PASSES — 5.18.102 serving, every file it changed 5.18.102's again; the $NA103 it added on disk, and no installed file but them names the catalogue's class or its table"
for l in "note  QC-RB2 before the rollback, on a copy of the book: $(qcrbsay103 "$QCRB103") — after it each with no quotation is quoted in uCRM by hand; ids on the sheet" \
         "ok    RB the installed manifest says 5.18.102" \
         "ok    QC-RB1 nothing in 5.18.102 reaches the uCRM catalogue 5.18.103 leaves: none of the $RB1N103 readings of the installed files 5.18.103 changes that name UcrmCatalogue or ucrm_catalogue in it finds one now, and none of the " \
         "of the $NA103 files 5.18.103 adds are on disk, reached by nothing" \
         "note  QC-RB1 the uCRM catalogue's table stays on the book, as the rollback leaves it: 3 record(s) — read by nothing under 5.18.102; a later deploy of 5.18.103 finds the switch, the installation product, the products kept off and the standard quotations as they were" \
         "note  QC-RB2 after the rollback, on a copy of the book: $(qcrbsay103 "$QCRB103") — 5.18.102 reads each as one from the app (P1, or P2 where cash was collected) and never quotes it: an admin quotes each in uCRM by hand, after checking the customer has none (ids on the sheet)" \
         "  what it means     on Uganda, where the quoting organization is in force, the uCRM catalogue screen is gone, the Plans and Hardware screens are back with their uCRM link pickers"; do
  check "$(has "$OUT" "$l")" "yes" "6a: ${l:0:110}"
done
RH103_IDS_OUT="$H103/v/rb2.txt"; export RH103_IDS_OUT
check "$(python3 "$H103/rh103q.py" sheet "$SH103R" | sed -E 's/^offer:[0-9]+,set:[0-9]+,//')|$(python3 "$H103/rh103q.py" rb2ids "$SH103R" 'stage A, before the rollback')/$(cmp -s "$RH103_IDS_OUT" "$IDSF103" && echo same)|$(python3 "$H103/rh103q.py" rb2ids "$SH103R" 'RB, after the rollback')/$(cmp -s "$RH103_IDS_OUT" "$IDSF103" && echo same)|$(cat "$R103/line-$T6A.txt" $(ls -1 "$OUT103"/rollback-*.log 2>/dev/null) | grep -F 'QC-RB2' | grep -cE "#($(tr -cd '0-9,' < "$IDSF103" | tr ',' '|'))([^0-9]|\$)")" \
      "rb2:2,on4:0|found/same|found/same|0" \
  "6b the rollback's sheet holds QC-RB2's two sections — stage A's and RB's — each with the web-direct ids this rehearsal's own reading finds; no id in the terminal or the log"
rm -f "$RH103_IDS_OUT" "$IDSF103"
check "$(grep -c -- '--rollback-unpriced-pending' <<<"$OUT")|$(grep -cE '^  (ok    |FAIL  |note  )QL-(RB[123]|LK1|CA1) ' <<<"$OUT")|$(sha256sum "$STATE103" | cut -c1-64)|$(cat "$OUT103"/rollback-state-*.env 2>/dev/null | grep -c "^RB_COPY_AT=$(stat -c %Y "$PD/lib/KycService.php")\$")|$(grep -lF -f "$NAMES102" "$R103/line-$T6A.txt" $(ls -1 "$OUT103"/rollback-*.log 2>/dev/null) 2>/dev/null | grep -c .)|$(evo_calls)|$(data94)|$(catq103)" \
      "0|0|$S103SHA|1|0|0|$D103|records:3,inforce:3" \
  "6c no flag and no retired check line; the deploy's state file untouched; the rollback's record holds its copy's time as stamp_copy gave lib/KycService.php; no seeded name; Evolution never called; the data as before — the catalogue's table left in place, as written"
fresh103; D6D="$(data94)"; OUT="$(run103 --answer NO --redeploy)"
check "$(has "$OUT" "NO-GO (A14): a rollback of 5.18.103 by this script copied the plugin at")$(went103 "$OUT")$(live)|$(backups103)|$(data94)" "yesnono$P102|0|$D6D" \
  "6d --redeploy right after the rollback, before the quarantine's --check: NO-GO at A14, never the question; nothing backed up or changed"
# ── QC-RB1 over a 5.18.103 file left installed: with 5.18.102 serving, a --rollback runs the rollback's checks alone; the
# stand-in's .left103m (spent at V's first request, after stage A compared the files) copies the pin's
# includes/navigation.php — which names UcrmCatalogue and ucrm_catalogue — over the installed one. QC-RB1 must fail naming
# it, and RB's sha256 too; then its weakened copy says ok. The file put back byte for byte. ──────────────────────────────
NAV103=includes/navigation.php
NAVN103="$(grep -F "$NAV103|UcrmCatalogue|" <<<"$RB1M103" | head -1 | cut -d'|' -f3)"
cp -p "$PD/$NAV103" "$SB/nav103.kept"; git -C "$REPO" show "$P103:$P/$NAV103" > "$SB/nav103.left"; chmod 644 "$SB/nav103.left"
arm103m() { cp -p "$SB/nav103.kept" "$PD/$NAV103"; printf '%s\t%s\n' "$SB/nav103.left" "$NAV103" > "$SB/web/.left103m"; chmod 644 "$SB/web/.left103m"; }
arm103m
check "$( [ -n "$NAVN103" ] && [ "$NAVN103" -ge 1 ] 2>/dev/null && echo pinned)|$(grep -cF UcrmCatalogue "$SB/nav103.left" | awk -v n="$NAVN103" '{print ($1 == n ? "as-pinned" : "differs:" $1)}')|$(grep -cF UcrmCatalogue "$PD/$NAV103")" "pinned|as-pinned|0" \
  "control: QC-RB1 pins $NAV103 naming UcrmCatalogue; the pin's file names it as pinned, the installed 5.18.102 file not at all"
fresh103; OUT="$(run103 --rollback)"
check "$(has "$OUT" "the container already serves $P102 (5.18.102) — nothing to roll back; running the rollback checks")$(spent99 .left103m)|$(cmp -s "$PD/$NAV103" "$SB/nav103.left" && echo left)|$(failline "$OUT" QC-RB1 "5.18.102's installed code names the uCRM catalogue 5.18.103 leaves, or its readers could not see the release:" "$NAV103:names-UcrmCatalogue×$NAVN103")$(failline "$OUT" RB "installed files that are not 5.18.102's after the rollback:" "$NAV103")" \
      "yesspent|left|yesyes" \
  "6e 5.18.102 serving, its rollback checks alone; a 5.18.103 file installed by the stand-in after stage A: QC-RB1 FAILS naming it, and RB's sha256 too"
arm103m; M="$(weak103 qcrb1w qcrb1m qcrb1s)"; fresh103; OUT="$(R103_MUTANT_OUT=1 run103 --script "$M" --rollback)"; rm -f "$M"
check "$(okline "$OUT" QC-RB1 'nothing in 5.18.102 reaches the uCRM catalogue 5.18.103 leaves')$(failline "$OUT" RB "installed files that are not 5.18.102's after the rollback:" "$NAV103")|$(spent99 .left103m)" "yesyes|spent" \
  "6f QC-RB1's reading of the installed files and its sweep weakened: it says nothing reaches the catalogue while an installed file names it (caught; RB's sha256 still fails)"
cp -p "$SB/nav103.kept" "$PD/$NAV103"
M="$(weak103 qcrb1n qcrb1n)"; fresh103; OUT="$(R103_MUTANT_OUT=1 run103 --script "$M" --rollback)"; rm -f "$M"
check "$(failline "$OUT" QC-RB1 "(the-control:the-pin-reads-$(cut -d'|' -f2 <<<"$RB1F103")×$(cut -d'|' -f3 <<<"$RB1F103")-not-$(cut -d'|' -f3 <<<"$RB1W103"))")$(okline "$OUT" QC-RB1 'nothing in 5.18.102 reaches')" "yesno" \
  "6g a copy pinning one marker's count wrong: QC-RB1's control refuses — the pin from Git does not read as pinned — over an installed tree that names nothing"
M="$(weak103 qcrb1cw qcrb1n qcrb1c)"; fresh103; OUT="$(R103_MUTANT_OUT=1 run103 --script "$M" --rollback)"; rm -f "$M"
check "$(okline "$OUT" QC-RB1 'nothing in 5.18.102 reaches')$(has "$OUT" 'the-control:the-pin-reads-')" "yesno" "6g' …and with the control's guard weakened too: QC-RB1 ok over a pin that does not read as pinned (caught)"
cp -p "$SB/nav103.kept" "$PD/$NAV103"; rm -f "$SB/nav103.kept" "$SB/nav103.left"
check "$(installed103 "$P102")|$(reach103)|$(live)|$(left103)|$(data94)" "0|0|$P102|$NA103|$D103" "…the installed file put back: every file 5.18.103 changed 5.18.102's again, nothing reaching the catalogue, the data as before"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "6h the quarantine's own --check after the rollback, as RB asks: PASSED"
KEPT103="$(kept_state)"
echo; echo "== 103-6v. a deploy whose public sign-in page redirects rolls itself back (V1) — then counts QC-RB2 =="
printf 'x\n' > "$SB/web/.redirect103"; chmod 644 "$SB/web/.redirect103"
fresh103; OUT="$(run103 --answer DEPLOY --redeploy)"; T103V="$(last103)"; SH103V="$(ls -1 "$OUT103"/cash-*.txt 2>/dev/null | head -1)"
check "$(failline "$OUT" V1 'the sign-in page on the public address → 200 after 1 redirect(s)')$(has "$OUT" '  ROLLING BACK — the public address must never redirect')$(spent99 .redirect103)|$(live)|$(installed103 "$P102")|$(inst_ver)|$(left103)|$(reach103)|$(noteline "$OUT" QC-N1 'THE uCRM CATALOGUE IS SWITCHED ON in this book'"'"'s store')" \
      "yesyesspent|$P102|0|5.18.102|$NA103|0|yes" \
  "6v1 V1 FAILS on the redirect (the trigger spent) and the deploy rolls itself back: 5.18.102 serving, its files back, the added ones on disk and reached by nothing; its stage A said the switch is ON (QC-N1, in capitals)"
check "$(noteline "$OUT" QC-RB2 "counted after it, on a copy of the book: $(qcrbsay103 "$QCRB103") — each with no quotation is quoted in uCRM by hand (ids on the sheet)")$(has "$OUT" "  STOP: rolled back (serves $P102); QC-RB2 counted after it, on a copy of the book: $(qcrbsay103 "$QCRB103") — each with no quotation is quoted in uCRM by hand (ids on the sheet); the uCRM catalogue's settings stay in the store, read by nothing under 5.18.102; a later deploy of 5.18.103 finds them as they were (docs/72 §G.10); send the log file")$(has "$OUT" 'did not complete')|$(awk 'index($0, "  ROLLING BACK") == 1 && !a { a = NR } index($0, "  note  QC-RB2 counted after it") == 1 && !b { b = NR } index($0, "  STOP: rolled back") == 1 && !c { c = NR } END { print ((a && b && c && a < b && b < c) ? "in order" : "a" a "b" b "c" c) }' <<<"$OUT")" \
      "yesyesno|in order" \
  "6v2 QC-RB2 counted after the rollback, on a copy — its note and its stop line say the count and that each is quoted in uCRM by hand — in that order: the rollback never waited on the count"
check "$(grep -c -- '--rollback-unpriced-pending' <<<"$OUT")|$(grep -cE '^ {6}(cd |\( cd )' <<<"$OUT")|$(has "$OUT" '== F. Summary ==')|$(sed -n 's/^RB_COPY_AT=//p' "$STATE103" | tail -1 | grep -cx "$(stat -c %Y "$PD/lib/KycService.php")")|$(grep -c "^== QC-RB2 the web-direct applications with no quotation — after V1's automatic rollback (" "$SH103V" 2>/dev/null)|$(uother103 "$T103V")|$(grep -lF -f "$NAMES102" "$R103/run-$T103V.txt" 2>/dev/null | grep -c .)|$(evo_calls)|$(data94)" \
      "0|0|no|1|1|-|0|0|$D103" \
  "6v3 the path prints no command and no flag; the deploy's state file holds the rollback's copy time (RB_COPY_AT, for A14); the ids on the sheet, under \"after V1's automatic rollback\"; GETs only; no seeded name; Evolution never called; the data as before"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "6v4 the quarantine's own --check after the deploy's own rollback, as A14 asks of the next deploy: PASSED"
KEPT103="$(kept_state)"
echo; echo "== 103-6w. the same, with the deploy's own rollback not verifying: it says so, and prints the by-hand put-back, the hazard first =="
printf 'x\n' > "$SB/web/.redirect103"; chmod 644 "$SB/web/.redirect103"; : > "$SB/.rbfail103"
check "$(inst_ver)|$(live)|$(v1read102)|$(ls -A "$SB" | grep -cE '^\.rbfail103(\.seen)?$')" "5.18.102|$P102|200:0|1" "6w the redirect planted again (unspent while 5.18.102's manifest is installed), the rollback's failed read armed and not yet seen"
fresh103; OUT="$(run103 --answer DEPLOY --redeploy)"; T103W="$(last103)"; SH103W="$(ls -1 "$OUT103"/cash-*.txt 2>/dev/null | head -1)"
check "$(failline "$OUT" V1 'the sign-in page on the public address → 200 after 1 redirect(s)')$(has "$OUT" '  ROLLING BACK — the public address must never redirect')$(spent99 .redirect103)|$(ls -A "$SB" | grep -cE '^\.rbfail103(\.seen)?$')|$(has "$OUT" "container still reports '0000000', expected '$P102'")$(has "$OUT" "the checkout is back on ")|$(live)|$(installed103 "$P102")|$(inst_ver)|$(left103)|$(reach103)" \
      "yesyesspent|0|yesyes|$P102|0|5.18.102|$NA103|0" \
  "6w1 V1 FAILS and the deploy's own rollback runs — and does not verify: deploy-hybrid.sh read the record back wrong once (the trigger spent) and the checkout returned; the files are 5.18.102's and the record names it again"
SW103="  STOP: the automatic rollback did not complete — it did not verify (live=$P102): 5.18.103 may still serve, or some installed files may already be 5.18.102's, and the public address may still redirect. QC-RB2 counted after it, on a copy of the book: $(qcrbsay103 "$QCRB103") — each with no quotation is quoted in uCRM by hand (ids on the sheet). $PUTBACK_HAZARD103 Put 5.18.102 back by hand, with its own command — then send this log file and that command's log:"
check "$(grep -cxF "$SW103" <<<"$OUT")|$(grep -A1 -xF "$SW103" <<<"$OUT" | tail -1 | grep -cxF "      $EXP_PUTBACK103")|$(grep -cE '^ {6}(cd |\( cd )' <<<"$OUT")|$(has "$OUT" 'STOP: rolled back (serves ')|$(grep -c -- '--rollback-unpriced-pending' <<<"$OUT")|$(grep -c "^== QC-RB2 the web-direct applications with no quotation — after V1's automatic rollback attempt (" "$SH103W" 2>/dev/null)" \
      "1|1|1|no|0|1" \
  "6w2 the stop says the rollback did not complete — never \"rolled back\" — with QC-RB2's count, the put-back's hazard first and the by-hand put-back alone on the next line: the one command printed; the flag never named; the ids on the sheet under \"attempt\""
check "$(sed -n 's/^RB_COPY_AT=//p' "$STATE103" | tail -1 | grep -cx "$(stat -c %Y "$PD/lib/KycService.php")")|$(uother103 "$T103W")|$(grep -lF -f "$NAMES102" "$R103/run-$T103W.txt" 2>/dev/null | grep -c .)|$(evo_calls)|$(data94)" "1|-|0|0|$D103" \
  "6w3 the state file holds the attempt's copy time (RB_COPY_AT, for A14); GETs only; no seeded name; Evolution never called; the data as before"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "6w4 the quarantine's own --check after the attempt: PASSED"
KEPT103="$(kept_state)"
M="$(weak103 v1rbw v1rb)"; printf 'x\n' > "$SB/web/.redirect103"; chmod 644 "$SB/web/.redirect103"; : > "$SB/.rbfail103"
fresh103; OUT="$(R103_MUTANT_OUT=1 run103 --script "$M" --answer DEPLOY --redeploy)"; rm -f "$M"
check "$(has "$OUT" "container still reports '0000000', expected '$P102'")$(spent99 .redirect103)|$(ls -A "$SB" | grep -cE '^\.rbfail103(\.seen)?$')|$(has "$OUT" 'STOP: rolled back (serves ')$(has "$OUT" 'did not complete')|$(grep -cxF "      $EXP_PUTBACK103" <<<"$OUT")|$(live)" \
      "yesspent|0|yesno|0|$P102" \
  "6w5 control: with do_rollback's answer ignored (\"do_rollback || true\"), the same failed rollback says \"rolled back\" and prints no put-back — 6w2 does not hold on it (caught)"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "6w6 the quarantine's own --check after it: PASSED"
KEPT103="$(kept_state)"
echo; echo "== 103-7. --redeploy with the switch left ON in the store: QC-N1 in capitals; PASSED; its --after-only PASSED =="
rb103() {   # rb103 LABEL — the rollback, ROLLBACK typed: PASSED, 5.18.102 serving; then the quarantine's --check
  local o; fresh103; o="$(run103 --answer ROLLBACK --rollback)"
  check "$(fails "$o")$(has "$o" '5.18.103 (rollback): PASSED')$(live)$(installed103 "$P102")|$(left103)|$(reach103)" "0yes${P102}0|$NA103|0" "$1 the rollback, ROLLBACK typed: PASSED — 5.18.102 serving, its files back, the added ones on disk and reached by nothing"
  o="$(qrun -- --check)"
  check "$(has "$o" 'stray-store quarantine (check): PASSED.')" "yes" "…the quarantine's own --check after it, as A14 asks of the next deploy: PASSED"
  KEPT103="$(kept_state)"
}
fresh103; OUT="$(run103 --answer DEPLOY --redeploy)"; OUT103R="$OUT"
check "$(fails "$OUT")$(has "$OUT" '5.18.103 (deploy): PASSED')$(live)$(installed103 "$P103")$(inst_ver)|$(noteline "$OUT" QC-N1 'THE uCRM CATALOGUE IS SWITCHED ON in this book'"'"'s store — an earlier, rolled-back run of 5.18.103 left it on (docs/72 §G.10). FROM THE COPY, AGENTS SEE uCRM'"'"'S PLANS AND HARDWARE AT ONCE')|$(okline "$OUT" BR1 'the installed plugin is live 5.18.102' "; $NA103 of the $NA103 files 5.18.103 adds are already on disk, left by an earlier 5.18.103 and its rollback (deploy-hybrid.sh never deletes) — the copy replaces them")" \
      "0yes${P103}05.18.103|yes|yes" \
  "7a --redeploy, DEPLOY typed, the switch left ON by 103-5: PASSED — QC-N1 says so in capitals at stage A; BR1 finds the added files on disk"
check "$(has "$OUT" "  ok    QC-CA1 the INSTALLED UcrmQuoteLines::offered(), on a fresh copy and GET-only, offers exactly what stage A's pin offered, item by item (compared under this run's QL key, never printed: $(cmpsay103 "$ITEMSON103"))")|$(okline "$OUT" QC-G1 "with the copy's catalogue settings (3 record(s), 3 in force)")|$(qor103 "$OUT" 1 qcinst)|$(qor103 "$OUT" 1 qcstd)|$(has "$OUT" 'Its switch is ON.')|$(grep -cxF '                    3. turn the switch on — the screen refuses while no plan is offered from uCRM (it is ON already: QC-N1);' <<<"$OUT")|$(python3 "$H103/rh103q.py" stateqc "$STATE103" | cut -d'|' -f3-4)" \
      "yes|yes|plug:ok;tok:ok|plug:1/1;tok:1/1|yes|1|sw:on|cat:records:3,inforce:3" \
  "7b QC-CA1 equal to stage A's item by item over the switched-on settings; QC-G1 read the copy's three records; F says the switch is ON and step 3 that it is already; the state file records the switch and the table as stage A read them"
after_back103; ALINE7="$(printed "$OUT103R" "cd $REPO && bash scripts/deploy-5.18.103.sh --after-only")"
fresh103; OUT="$(run_line103 '' "$ALINE7")"
check "$ALINE7|$(fails "$OUT")$(has "$OUT" '5.18.103 (after): PASSED')$(okline "$OUT" QC-ON1 'switched ON')$(okline "$OUT" QC-ON2 'is set and passes')$(okline "$OUT" QC-ON3 'every standard quotation (1) is usable now')$(okline "$OUT" QC-ON4 '(1) keeps G-7')" "$EXP_AFTER103|0yesyesyesyesyes" \
  "7c its --after-only, as printed: PASSED — QC-ON1–QC-ON4 ok"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "7d the quarantine's own --check, as the runbook orders: PASSED"
KEPT103="$(kept_state)"
rb103 "7e"
echo; echo "== 103-8. the quoting organization off — --quote-company-off: QC-G1 and QC-CA1 not asked, QC-ON1–QC-ON3 one note; F's own words =="
cfg_keep; file_set ucrm_quote_organization_id 0; row_set ucrm_quote_organization_id 0; D8Q103="$(data94)"
fresh103; OUT="$(run103 --check)"
nogo103 "8a ucrm_quote_organization_id 0 in both copies, --check: NO-GO at Q-G3 (5.18.99's, carried)" "NO-GO (Q-G3): ucrm_quote_organization_id is 0/off on this server" "$OUT" "$D8Q103" "records:3,inforce:3"
fresh103; S8C="$(states103)"; OUT="$(run103 --check --quote-company-off)"; T8C="$(last103)"; SH8C="$(ls -1 "$OUT103"/cash-*.txt 2>/dev/null | head -1)"
check "$(checkgo103 "$OUT")|$(noteline "$OUT" QC-G1 'not asked: no quoting organization is in force (Q-G3) — the uCRM catalogue lives under it, so 5.18.103 then offers nothing from uCRM and its screen is not shown (docs/72 §G.10)')$(noteline "$OUT" QC-N1 "the uCRM catalogue's switch not read: no quoting organization is in force (Q-G3) — the switch lives under it")$(grep -cE '^  note  QC-N(2|5|6) ' <<<"$OUT")|$(qor103 "$OUT" 1 qcc)|$(qol103 "$OUT" qccat)|$(ureq103 "$T8C" | sed -E 's/^total=[0-9]+ other=0 //; s/ attrs=.*//')|$(python3 "$H103/rh103q.py" sheet "$SH8C")" \
      "yesyesnono0|0|$S8C|yesyes0|off|off|products=0/0 plans=0/0|offer:0,set:1,rb2:0,on4:0" \
  "8b --check --quote-company-off: GO; QC-G1 not asked, QC-N1 not read, no installation, stock or exclusion note; the probe reads off; uCRM asked no list; the sheet has no offer section"
fresh103; OUT="$(run103 --answer DEPLOY --redeploy --quote-company-off)"; OUT103Q="$OUT"
check "$(fails "$OUT")$(has "$OUT" '5.18.103 (deploy): PASSED')$(live)$(installed103 "$P103")|$(grep -cxF "  QC-CA1          not asked: no quoting organization is in force (Q-CA2) — the uCRM catalogue lives under it" <<<"$OUT")|$(has "$OUT" "  what changed      on this server the quoting organization is not in force (Q-G3: ucrm_quote_organization_id 0/off, the operator's choice), so 5.18.103 quotes as 5.18.102 does with it off (D7): the uCRM catalogue lives under it, so its screen is not shown and nothing is offered from uCRM; every screen as before. No migration (R7)")|$(grep -cxF '  the screens       none changes: with the quoting organization not in force, the uCRM catalogue is not shown' <<<"$OUT")$(grep -cxF '  your steps        none now: with the quoting organization not in force, 5.18.103 quotes as' <<<"$OUT")|$(grep -cE '^  (ok    |FAIL  )QC-CA1 ' <<<"$OUT")" \
      "0yes${P103}0|1|yes|11|0" \
  "8c the deploy with --quote-company-off, DEPLOY typed: PASSED; QC-CA1 not asked; F's own words — 5.18.103 quotes as 5.18.102 does with it off, no screen changes, no step now"
after_back103; ALINE8="$(printed "$OUT103Q" "cd $REPO && bash scripts/deploy-5.18.103.sh --after-only")"
fresh103; OUT="$(run_line103 '' "$ALINE8")"
check "$ALINE8|$(fails "$OUT")$(has "$OUT" '5.18.103 (after): PASSED')$(noteline "$OUT" 'QC-ON1–QC-ON3' 'not asked: no quoting organization is in force (Q-CA2) — the uCRM catalogue lives under it, so 5.18.103 quotes as 5.18.102 does with it off (D7), and its screen is not shown')$(okline "$OUT" QC-ON4 '(1) keeps G-7')|$(grep -cE '^  (ok    |FAIL  )QC-ON[123] ' <<<"$OUT")" \
      "$EXP_AFTER103|0yesyesyes|0" \
  "8d its --after-only as printed: PASSED; QC-ON1–QC-ON3 one note, never an ok or a FAIL; QC-ON4 still reads G-7 over the web-direct record"
rb103 "8e"
cfg_back
check "$(data94)|$(set99 ucrm_quote_organization_id)" "$D103|unset/unset" "…the quoting organization's setting put back in both copies: the data as before, byte for byte"

echo; echo "== 103-10. what the rehearsal left behind, and what it never reached =="
fresh103   # the last logs and sheets into the sweeps' folders
check "$(evo_calls)" "0" "Evolution never called — not by the deploys, their checks, the rollbacks or the code they ran on copies"
check "$(ls -a "$REPO/scripts" | grep -E '^\..+\.sh$' | paste -sd, -)" ".qpin.sh" "no weakened or re-pinned copy is left in the clone's scripts/ — the quarantine's one pinned copy aside"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )" "" "every weakened copy, edit of the stand-ins and planted edit — this rehearsal's and those of the rehearsals it runs — found its anchor exactly once"
rm -f "$SB/anchor-missing"   # the controls below read their own record alone
M="$(weak103 anchorprobe103 no-such-entry 2>/dev/null)"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )" "weak103:no-such-entry " "control on the control: a weakened copy asked for an entry the table does not hold is recorded as such (weak103)"
rm -f "$SB/anchor-missing" "$M"
M="$(weak103 anchorprobe103b copymark)"
cp "$DEPLOY103" "$SB/twice103.sh"; printf '%s\n' "$(a103 copymark)" >> "$SB/twice103.sh"; TW103="$(sha256sum "$SB/twice103.sh" | cut -c1-64)"
TWR103="$(python3 - "$SB/twice103.sh" "$(a103 copymark)" "$(r103 copymark)" <<'PY' 2>/dev/null && echo applied || echo refused
import sys
f, a, b = sys.argv[1:4]; s = open(f, encoding='utf-8').read()
assert a and s.count(a) == 1, (a[:60], s.count(a))
open(f, 'w', encoding='utf-8').write(s.replace(a, b))
PY
)"
check "$(grep -cxF "$(r103 copymark)" "$M")|$(grep -cxF "$(a103 copymark)" "$M")|$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )|$TWR103|$( [ "$(sha256sum "$SB/twice103.sh" | cut -c1-64)" = "$TW103" ] && echo unchanged)" "1|0||refused|unchanged" \
  "control on the control: a weakened copy replaces its anchor exactly once (the copy says COPY_MARK=\"webhook.php\" once, the original line nowhere), and the same replacement, given a script holding that anchor twice, refuses and changes nothing"
rm -f "$SB/anchor-missing" "$M" "$SB/twice103.sh"
M="$(weak103 anchorprobe103c qcrb1n)"
check "$(sed -n "/^QC_RB1_MARKS=\"\$(cat <<'MARKS'\$/,/^MARKS\$/p" "$M" | grep -F '|' | head -1)|$(grep -cxF "$RB1F103" "$M")|$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )" "$RB1W103|0|" \
  "control on the control: the marker-count copy changes exactly the block's first line — to the count one more — and leaves the original line nowhere"
rm -f "$SB/anchor-missing" "$M"
check "$( [ -f "$SB/bad-runs" ] && tr '\n' ' ' < "$SB/bad-runs" )" "" "every run took the options it was given — none answered 'unknown option'"
OUT="$(run103 --after-only --rehearsal-unknown-option)"
check "$(has "$OUT" 'unknown option: --rehearsal-unknown-option')$( [ -s "$SB/bad-runs" ] && echo recorded)" "yesrecorded" "control on the control: a run given an option the script does not know is recorded as such"
rm -f "$SB/bad-runs"
OUT="$(run103 --rollback --rollback-unpriced-pending)"
check "$(has "$OUT" 'unknown option: --rollback-unpriced-pending')$(has "$OUT" 'PASSED')|$(live)" "yesno|$P102" "…and --rollback-unpriced-pending, retired with QL-RB2, is now an option the script does not know: refused before anything runs"
rm -f "$SB/bad-runs"
tok103() { python3 "$H103/rh103.py" tok99 "$R103/tok/run-$1.tok"; }   # tok103 TAG — remote=<n> copied=<n> left=<n>
OBS_BAD=0; OBS_N=0; TOK_BAD=0; GET_BAD=0; ODD_BAD=0; LIM_BAD=0
for rec in "$R103"/exec/run-*.rec; do
  t="${rec##*/run-}"; t="${t%.rec}"; OBS_N=$((OBS_N+1))
  a="$(audit103 "$t")"; case "$a" in *" novault=0 plugin=0 "*) ;; *) OBS_BAD=$((OBS_BAD+1)) ;; esac
  [ "$(hits103 "$t")" = "0" ] || OBS_BAD=$((OBS_BAD+1))
  [ "$(cat "$R103/vault/run-$t.before")" = "$(cat "$R103/vault/run-$t.after")" ] || OBS_BAD=$((OBS_BAD+1))
  case "$(tok103 "$t")" in *" left=0") ;; *) TOK_BAD=$((TOK_BAD+1)) ;; esac
  [ "$(uother103 "$t")" = "-" ] || GET_BAD=$((GET_BAD+1))
  case "$(ureq103 "$t")" in *" odd=0") ;; *) ODD_BAD=$((ODD_BAD+1)) ;; esac
  case "$(lim103 "$t")" in *" bad=0") ;; *) LIM_BAD=$((LIM_BAD+1)) ;; esac
done
check "$( [ "$OBS_N" -ge 20 ] && echo many)|$OBS_BAD|$TOK_BAD|$GET_BAD|$ODD_BAD|$LIM_BAD" "many|0|0|0|0|0" \
  "10 across every run of the 5.18.103 script — the checks, the refusals, the weakened copies, the deploys, the after-onlys and the rollbacks — the copy observers were quiet, every token copy was gone at the call after its probe, and the stand-in uCRM received nothing but GETs of the shapes the release reads, its lists always with limit=500"
check "$(vt_host)|$(grep -cxF "$W96" "$DATA/webhook_secret")" "$VT99_0|1" "…the live vault as it was before the first run of 5.18.99's rehearsal, bytes and time; the planted webhook secret where it was planted"
sweep_files103() { ls -1 "$R103"/run-*.txt "$R103"/line-*.txt "$R103"/mutants/run-*.txt "$R103"/logs/*.log "$OUT103"/*.log "$OUT103"/state-5.18.103.env* "$OUT103"/rollback-state-* "$OUT103"/cih-rows-* 2>/dev/null; }
sheets103() { ls -1 "$R103"/sheets/cash-*.txt 2>/dev/null; }
terms103() { ls -1 "$R103"/run-*.txt "$R103"/line-*.txt "$R103"/mutants/run-*.txt "$R103"/logs/*.log "$OUT103"/*.log 2>/dev/null; }
# The names this rehearsal's writers record (the catalogue's product names, the standard quotations' titles, the admin)
# may be on the sheets; the direct sale's customer name nowhere, sheets included; 5.18.102's "nowhere" names stay nowhere —
# but the uCRM service plan no product mirrors (#503), which QC-G1's sheet section names as not offered.
AMOUNTS103="$SB/seed103.amounts"
python3 -c 'import json, sys
s = json.load(open(sys.argv[1])); pr = {p["id"]: p["price"] for p in s["products"]}
t = round(pr[9101] + pr[9103] + pr[9105], 2)
fd = open(sys.argv[2], "w"); fd.write("%.2f\n" % t); fd.close()' "$U99/base.json" "$AMOUNTS103"; chmod 600 "$AMOUNTS103"   # the standard quotation's and the direct sale's total: on the sheet at most
printf '%s\n' RH103QSECRET- > "$SB/names103.pat"; cat "$NAMES102" >> "$SB/names103.pat"; chmod 600 "$SB/names103.pat"
{ grep -v '^RH102QSECRET-Plan-Zeta-' "$NOWHERE102"; cat "$SB/linknames102.pat" 2>/dev/null; printf '%s\n' RH103QSECRET-Guest RH103QSECRET-Direct; } > "$SB/nowhere103.pat"; chmod 600 "$SB/nowhere103.pat"
SALTS103="$SB/salts103.pat"; : > "$SALTS103"; chmod 600 "$SALTS103"
SALTN103="$(python3 "$H102/rh102q.py" salts "$SALTS103" "$R103"/exec/run-*.rec)"
forms103() { python3 "$H103/rh103.py" forms "$SB/forms103.txt" "$SB/seed96.amounts" "$SB/seed97.amounts" "$SB/stmt97.amounts" "$SB/seed98.amounts" "$AMOUNTS102" "$AMOUNTS103" "$H97"/cash-*.json "$H98"/cash-*.json "$H99"/cash-*.json "$R97"/sheets/cash-*.txt "$R98"/sheets/cash-*.txt "$R99"/sheets/cash-*.txt "$R100"/sheets/cash-*.txt "$R101"/sheets/cash-*.txt "$R102"/sheets/cash-*.txt "$R103"/sheets/cash-*.txt "$OUT97"/cash-*.txt "$OUT98"/cash-*.txt "$OUT99"/cash-*.txt "$OUT100"/cash-*.txt "$OUT101"/cash-*.txt "$OUT102"/cash-*.txt >/dev/null 2>&1; }
figs103() { forms103; python3 "$H103/rh103.py" hits "$SB/forms103.txt" "$@"; }   # figs103 FILE… — how many hold a figure of the book or a seeded price, in any printed form
check "$( [ "$(sweep_files103 | grep -c .)" -ge 20 ] && echo many)|$( [ "$(sheets103 | grep -c .)" -ge 8 ] && echo sheets)|$(forms103; grep -c . "$SB/forms103.txt" | awk '{print ($1 >= 100 ? "forms" : "few:" $1)}')|$(for a in $(cat "$AMOUNTS103" "$AMOUNTS102"); do grep -qxF "2:$(python3 -c 'import sys; print("{:,.2f}".format(float(sys.argv[1])))' "$a")" "$SB/forms103.txt" && echo y || echo n; done | sort -u | paste -sd, -)|$( [ "$(ls "$R103"/ucrm/run-*.req 2>/dev/null | grep -c .)" -ge 20 ] && echo records)|$(echo "$SALTN103" | awk '{print ($1 >= 10 ? "keys" : "few:" $1) " " ($2 >= 32 ? "long" : "short:" $2)}')" \
      "many|sheets|forms|y|records|keys long" \
  "the sweep has material: every run's terminal and log of this release's, the state files and row files; its runs' sheets; a hundred and more printed forms of the book's figures, every seeded price and this rehearsal's totals among them; every run's request record; the run keys the runs handed their probes, each at least 32 characters"
check "$(figs103 $(sweep_files103))" "0" "10a no figure of the book, of a kept statement, of a seeded price or of a standard quotation's total — in any printed form — in any terminal, log, state file or row file of any run of the 5.18.103 script"
check "$(sweep_files103 | xargs grep -lF -f "$SB/names103.pat" 2>/dev/null | grep -c .)|$(sheets103 | xargs grep -lF -f "$NAMES102" 2>/dev/null | grep -c . | awk '{print ($1 >= 6 ? "sheets" : "few:" $1)}')|$(sheets103 | xargs grep -lF RH103QSECRET- 2>/dev/null | grep -c . | awk '{print ($1 >= 1 ? "sheets" : "none")}')|$( { sweep_files103; sheets103; } | xargs grep -lF -f "$SB/nowhere103.pat" 2>/dev/null | grep -c .)|$( { sweep_files103; sheets103; ls -1 "$R103"/ucrm/run-*.req; } | xargs grep -lF -f "$SALTS103" 2>/dev/null | grep -c .)" \
      "0|sheets|sheets|0|0" "10b no seeded uCRM or plugin name and no name the catalogue's writers recorded (products, standard quotations, the admin) in any terminal, log, state file or row file — on the 0600 sheets alone (positive: the sheets hold both); the direct sale's customer, the inactive rows, the link records' names nowhere, sheets included; no run key anywhere"
check "$(sweep_files103 | xargs grep -lF -f "$SCAN96" 2>/dev/null | grep -c .)|$(grep -v '^$' "$SEC95" > "$SB/sec103.pat"; sweep_files103 | xargs grep -lF -f "$SB/sec103.pat" 2>/dev/null | grep -c .)|$(secrets99 $(sweep_files103) $(sheets103) "$R103"/ucrm/run-*.req)|$(names100 $(sweep_files103) $(sheets103))" "0|0|0|0" \
  "10c no output, log, state file, sheet or request record holds the planted webhook secret or vault credential, a repair key, a planted Splynx or pdf value, a name, contact, app key or token of the stand-in uCRM's — or a staff record's name"
check "$(noids99 $(sweep_files103))|$(bankhits98 $(sweep_files103))|$(sweep_files103 | xargs grep -lE ' (gh|oroles)=|(^| )(qlh|qch)=' 2>/dev/null | grep -c .)" "0|0|0" \
  "10d no output, log or state file names a client id the app holds, an account name or number — or carries an owner check hash label (gh=, oroles=) or a qlh=/qch= field — the hash values themselves are swept next"
printf 'planted %s, %s, %s, client %s, %s, %s, %s, %s and %s\n' "$(grep -m1 '^2:' "$SB/forms103.txt" | cut -c3-)" "$W96" "$(head -1 "$U99_SECRETS")" "$(head -1 "$SB/ids99.pat")" "RH100SECRET-Tech-One" "$(sed -n 2p "$SB/names103.pat")" "RH103QSECRET-admin" "$(tail -1 "$SB/nowhere103.pat")" "$(head -1 "$SALTS103")" > "$R103/zz-sweep-control.txt"
printf 'priced %s\n' "$(python3 -c 'import sys; print("{:,.2f}".format(float(sys.argv[1])))' "$(head -1 "$AMOUNTS103")")" > "$R103/zz-price-control.txt"
check "$(figs103 "$R103/zz-sweep-control.txt")$(figs103 "$R103/zz-price-control.txt")$(grep -lF -f "$SCAN96" "$R103/zz-sweep-control.txt" | grep -c .)$(secrets99 "$R103/zz-sweep-control.txt")$(noids99 "$R103/zz-sweep-control.txt")$(names100 "$R103/zz-sweep-control.txt")$(grep -lF -f "$SB/names103.pat" "$R103/zz-sweep-control.txt" | grep -c .)$(grep -lF -f "$SB/nowhere103.pat" "$R103/zz-sweep-control.txt" | grep -c .)$(grep -lF -f "$SALTS103" "$R103/zz-sweep-control.txt" | grep -c .)" "111111111" \
  "control on the sweeps: a file holding one figure form, one holding this rehearsal's total, the planted secret, a stand-in secret, a client id, a seeded staff name, a seeded catalogue name, a name meant for nowhere and a run key is found by all nine"
rm -f "$R103/zz-sweep-control.txt" "$R103/zz-price-control.txt" "$SB/sec103.pat"
# The QL key (one QL_KEY= line in its own 0600 file per deploy) and the item hashes the state file holds (QC_ITEMS): in no
# terminal, log, sheet or request record — the key file and the state files, which hold them, are the controls.
cat "$OUT103"/ql-key-5.18.103* 2>/dev/null | sed -n 's/^QL_KEY=//p' | sort -u > "$SB/qlkeys103.pat"; chmod 600 "$SB/qlkeys103.pat"
sed -n 's/^QC_ITEMS=//p' "$OUT103"/state-5.18.103.env* 2>/dev/null | tr ',:' '\n\n' | grep -xE '[0-9a-f]{32}' | sort -u > "$SB/qchash103.pat"; chmod 600 "$SB/qchash103.pat"
printf 'key %s\n' "$(head -1 "$SB/qlkeys103.pat")" > "$R103/zz-key-control.txt"; printf 'hashed %s\n' "$(head -1 "$SB/qchash103.pat")" > "$R103/zz-hash-control.txt"
check "$(grep -c . "$SB/qlkeys103.pat" | awk '{print ($1 >= 3 ? "keys" : "few:" $1)}')|$( { sweep_files103; sheets103; } | xargs grep -lF -f "$SB/qlkeys103.pat" 2>/dev/null | grep -c .)|$(grep -c . "$SB/qchash103.pat" | awk '{print ($1 >= 10 ? "hashes" : "few:" $1)}')|$( { sweep_files103 | grep -vE '/(rollback-)?state-'; sheets103; ls -1 "$R103"/ucrm/run-*.req; } | xargs grep -lF -f "$SB/qchash103.pat" 2>/dev/null | grep -c .)|$(grep -lF -f "$SB/qlkeys103.pat" "$R103/zz-key-control.txt" | grep -c .)$(grep -lF -f "$SB/qchash103.pat" "$R103/zz-hash-control.txt" | grep -c .)|$(stat -c %a "$OUT103/ql-key-5.18.103")" \
      "keys|0|hashes|0|11|600" \
  "10e no QL key of any deploy and no item hash of QC-G1's or QC-CA1's (QC_ITEMS) in any terminal, log, sheet or request record; the keys in their own 0600 files, the hashes in the state files, ten and more; controls: a file holding either is caught"
rm -f "$R103/zz-key-control.txt" "$R103/zz-hash-control.txt" "$SB/qlkeys103.pat" "$SB/qchash103.pat"
rterr103() {   # rterr103 FILE… — how many of the files hold a bash runtime error of a script ("<x>.sh: line N: …")
  [ "$#" -gt 0 ] || { echo none; return; }
  grep -lE '\.sh: line [0-9]+: .*(bad substitution|unbound variable|syntax error|command not found)' "$@" 2>/dev/null | grep -c .
}
printf '%s\n' 'X=1; Y=2' 'echo "a:${X:+ the copy'"'"'s:$X}${Y:+ the base'"'"'s:$Y} end"' > "$SB/rterr103.sh"
bash "$SB/rterr103.sh" > "$R103/zz-rterr-control.txt" 2>&1
check "$(rterr103 $(sweep_files103))|$(rterr103 "$R103/zz-rterr-control.txt")" "0|1" \
  "10f no terminal or log of any run of the 5.18.103 script holds a bash runtime error; control: a script with an apostrophe inside \${…:+…} is caught"
rm -f "$SB/rterr103.sh" "$R103/zz-rterr-control.txt"
printf '%s\n' '  ok    QL-CA1 the catalogue' '  note  QL-RB2 counted' '  FAIL  QL-LK1 not every item' > "$R103/zz-retired-control.txt"
check "$(terms103 | xargs grep -lE '^  (ok    |FAIL  |note  )QL-(CA1|LK1|RB[123]) ' 2>/dev/null | grep -c .)|$(grep -cE '^  (ok    |FAIL  |note  )QL-(CA1|LK1|RB[123]) ' "$R103/zz-retired-control.txt")|$(terms103 | xargs grep -lE '^  (ok    |note  )QC-' 2>/dev/null | grep -c . | awk '{print ($1 >= 10 ? "qc" : "few:" $1)}')" "0|3|qc" \
  "10g no terminal or log of any run says a check retired by 5.18.103 (QL-CA1, QL-LK1, QL-RB1–QL-RB3) under its name — its own QC lines everywhere; control: each retired line is counted"
rm -f "$R103/zz-retired-control.txt"
check "$(ls -A "$SB/web" | grep -cE '^\.(redirect103|left103m)$')|$(ls -A "$SB" | grep -cE '^\.(rbfail103|rbfail103\.seen)$')|$(live)|$(left103)|$(set99 ucrm_quote_organization_id)|$(u99_state_102)|$(catq103)|$(qkv103 "$(q103 apps)" g7)" "0|0|$P102|$NA103|unset/unset|base|records:3,inforce:3|direct:1,bad:0" \
  "10h no trigger of this rehearsal's left in the stand-in or the fake docker; the sandbox left as 8e's rollback left it: 5.18.102 serving, the added files on disk, the quoting organization's setting unset again, the stand-in uCRM at its base, the catalogue's settings in the store as the admin wrote them and the one direct sale as 5.18.103 saved it (a later rehearsal finds both, as production would after a rollback)"
printf '%s\n' "      $EXP_ROLLBACK103" "" "  5.18.103 (deploy): PASSED. Send this LOG FILE back" > "$SB/order103.txt"
printf '%s\n' "      $EXP_AFTER103; $EXP_ROLLBACK103" > "$SB/block103.txt"
check "$(awk '/^  5\.18\.103 \((deploy|after)\): / { v = 1 } index($0, "deploy-5.18.103.sh --rollback") { print (v ? "after" : "before") }' "$SB/order103.txt" | paste -sd, -)|$(grep -cE '(deploy-5\.18\.103\.sh|quarantine-stray-store\.sh|deploy-hybrid\.sh).*(deploy-5\.18\.103\.sh|quarantine-stray-store\.sh|deploy-hybrid\.sh)' "$SB/block103.txt")|$(grep -cxF "      $EXP_ROLLBACK103" "$SB/block103.txt")" \
      "before|1|0" \
  "control on the controls of 4d and 6: a rollback printed before the verdict reads \"before\"; a deploy and a rollback on one line are counted, and that line is not the rollback alone"
rm -f "$SB/order103.txt" "$SB/block103.txt"
check "$(left_tmp103)$(host_code103)$(ls -d /tmp/dnb-backup-* 2>/dev/null | grep -c .)$(find /tmp -maxdepth 6 -path '/tmp/dnb-5.18.103-*' -name token.json 2>/dev/null | grep -c .)$(cache103)$(ls -A "$H103/v" | grep -cvE '^(cat|sale)\.err$')" "000000" \
  "no run folder, code copy, vault, token copy, backup copy, page cache or last good copy of this release's left in /tmp, in the data folder or on the host; no record file or vault of this rehearsal's writers left"
check "$(grep -c 'Fatal\|Parse error' "$SB/web.log")|$(grep -c 'Fatal\|Parse error' "$U99/server.log")" "0|0" "the stand-in plugin and the stand-in uCRM served every request without a PHP fatal"
check "$(checkout_state)" "$CHECKOUT0" "this checkout is as the rehearsal found it: same commit, same tracked files"

echo
echo "RESULT: $PASS ok, $FAILN failed  (the script: $WHICH103)"
[ "$FAILN" = "0" ] || echo "  $FAIL_PRE103 of the $FAILN failed in the earlier releases' rehearsals, before this release's own sections. Their scripts are records, left as they are; a refusal of theirs at a check read off a pipeline whose reader exits early is a SIGPIPE false stop, not a defect of 5.18.103's. Read the first FAIL; if it is one, run the rehearsal again — it is never a pass."
[ "$FAILN" = "0" ]
