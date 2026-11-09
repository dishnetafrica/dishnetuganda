#!/usr/bin/env bash
#
# Rehearse scripts/deploy-5.18.104.sh — on Uganda the sales follow-up (root docs/73 §H): a read-only report, quote
# tracking and the day 1/3/7 chase, the morning list and My quotes, voice notes through OpenAI in four rungs, the
# voice-note acknowledgement, offers on the Evolution numbers and the agent's Cc on the quotation e-mail, every switch OFF
# and reached only through tools/sales_followup.php in one order (C6) — over the live release, 5.18.103, before the
# operator runs it. The script under rehearsal is derived from 5.18.103's by asserted edits (wip/derive-5.18.104/), pinned
# to the release commit on release/5.18.104; every pin is read from it here and every expectation computed from Git or
# from the release's own code — never a commit, a count of files, a line count or a hash written here.
#
# The sandbox is the previous release's rehearsal's, built by its own code: scripts/harness/deploy-5.18.103/rehearse.sh,
# read up to just before its own last section (what it left behind, its sweeps) — the cut asserted: its anchor exactly
# once, after line 900, its last check the one 103-8 ends on (read from the file), and the one line that names its own
# folder pointed at that folder, also exactly once — and run here as it is, which builds every earlier sandbox the same
# way, from their own rehearsals. When this file's own sections begin, 5.18.103's rehearsal has deployed, rolled back and
# deployed 5.18.103 again, and its 8e has rolled it back: 5.18.102 serving, the quoting organization unset in both copies
# (the Uganda profile's #1 in force), the uCRM catalogue's settings in the store as its 103-5 admin wrote them — the switch
# on, the installation set, one standard quotation — and one web-direct application. 104-pre makes production's state on
# 4 Nov: 5.18.103 deployed again (production's was a first deploy, 3 Nov, copy 09:01:02 UTC), the uCRM catalogue's switch
# on by an admin's act through the installed writers, its --after-only and the quarantine's --check passed after it.
#
# The development runner: the block that sources the chain — from the one line that begins H104_PREFIX="$(mktemp)" to the
# one line that is exactly rm -f "$H104_PREFIX", the python cut and the source line between them and nothing else — can be
# skipped by a runner that restores the chain's shell from a snapshot; everything after it works in such a shell.
#
# This rehearsal proves:
#   · (pre) production's state on 4 Nov: 5.18.103 deployed again over 5.18.102, DEPLOY typed, PASSED; an admin's switch-on
#     through the INSTALLED 5.18.103 UcrmCatalogue writers ("already" on the sandbox's store, nothing written); its
#     --after-only, as its summary printed it, PASSED with QC-ON1–QC-ON4 ok and R19 conclusive; the quarantine's --check
#     PASSED; 5.18.103's records where 5.18.104's A14 reads them; the fixtures 5.18.104's notes count — fictitious staff
#     (first names only), customers' conversations with inbound text and voice notes, an approved enquiry follow-up, three
#     quotations sent on WhatsApp 10, 20 and 45 days before (as the Send Quotation path stores its text) and two of them
#     e-mailed — path 1 through 5.18.103's QuotationService::emailQuotePdf, path 3 as webhook.php's whQuotationEmail sends
#     through CustomerEmailDispatcher — to the release's own fake relay on 127.0.0.1, the client and its PDF the stand-in
#     uCRM's — written through 5.18.103's own writers and each counted by this rehearsal's own reading of the store, the
#     quotations' headline by the RELEASE's own report on scratch copies before and after as well; the wrapper refusing,
#     no log folder made; none of 089–091's tables or columns on the live book, none of 5.18.104's files installed (pre);
#   · (0) the script is pinned to the release commit on release/5.18.104 (short and full), cut on 5.18.103; the delta is
#     exactly the files it lists, added and changed, each one's sha256 as it pins it, computed here from Git; the kept files
#     by theirs in both commits; migrations 089, 090 and 091 added, no other changed, each by its pinned sha256 and md5, and
#     each _schema_checks count as its own INSERT states it; the copy mark a file the release changes; the header's four
#     commands between its two marker lines, each alone, none a rollback; the retired codes (5.18.102's QL-CA1, QL-LK1,
#     QL-RB1–QL-RB3, --rollback-unpriced-pending) silent; the clone's short form of the pin 7 characters, its objects
#     under Git's 16,384 (0);
#   · (c) --check is GO: stage A alone — QF-G1 (a first deploy, 088 last), QF-G2 (the one trial, its line and its negative
#     control's), VN-G7, QF-G3, QF-G4 (none of the pin's allKeys() set, the count the release's ladder gives), VN-G1, VN-G2,
#     VN-G4, VN-G6, OF-G1 (both), OF-G2 and AC-L1 ok; the notes QF-N1–QF-N7, VN-G1's, VN-G3, VN-G5, OF-N1–OF-N3, AC-N1 (its
#     read form, never its "could not be read" ones) and AC-N2 as this rehearsal's own reading of the store (or the release's
#     own code on a scratch copy) counts them — QF-N3's headline the release's report before the quotations plus what this
#     rehearsal counts they added, AC-N2's every count this rehearsal's reading of quote_mail.log and customer_email_log;
#     5.18.103's carried checks ok, QC-N1 a note, never in capitals, over the switch an admin turned on (DS5); uCRM asked GETs only;
#     nothing changed — no backup, no state file, the live book's digest the same, no 089–091 table or column on the live
#     book, the vault as it was, no run folder left; the older readings each on a copy with a vault of its own, 5.18.104's
#     own readings by mode — those that read live files by design with the live vault unchanged, the others on their
#     copies — each class seen at least once (c);
#   · (1) each QF-G NO-GO refused before anything changes — QF-G1 on a part-applied book and on a copy that cannot be made,
#     QF-G2's trial and its negative control, QF-G3's guard and HK7's control, QF-G4 unread, a key held by <data>/config.json,
#     keys of all four parts on a book 5.18.104 never ran on (with and without --clear-switches) — each guard's weakened copy
#     caught; on a redeploy's book --check --clear-switches GO, the deploy without the flag refused with its own command
#     alone, the deploy with it clearing every key with the pin's tool in the one sequence (the keys on the sheet only), its
#     failures, the read-back and the baseline each planted and each guard weakened; AC-L1 NO-GO on a broken
#     QuoteAgentCopy.php before the copy (1z);
#   · (4) the deploy, DEPLOY typed, PASSED with its notes: every file the pin's, QF-CA1–QF-CA8 ok, every switch OFF by this
#     rehearsal's reading and the installed tool's show, F's steps in the ladder's one order, every printed command alone, the
#     rollback once and after the verdict, the by-hand put-back's two commands in two blocks;
#   · (5) --after-only before any step (every step OFF — not judged); the order enforced; each step on through the installed
#     tool, its --after-only after it; the skip paths (skip voice-measure still reaching chase and offers-test, skip tracking
#     skipping 11 and 12); Part F's live cases — a chase to a fixture customer held while the day's reminder run is in flight,
#     a wrapped reply that stops it, an acknowledgement 25 s after a chase on its handset, an orphan claim counted, --check
#     tracking with its counts (5); the voice rungs in the one order, each at PASS through the installed worker's seam (5v);
#     Part O's offers screen through the installed handlers — tests, Start all, a week of master cycles, a STOP, Pause, off
#     (5o); Part P's copies to the agent, a uCRM-typed quote with none, AC-ON1 PASS, AC-CA2's refusal (5p);
#   · (6) the rollback, ROLLBACK typed, every part on and holding work: the one sequence with every hook before or after its
#     keys as the ladder places them, every *-RB line, V3g ok with its note naming the ai_ keys QF-RB1 removed (DS5, S2); a key held by <data>/config.json
#     stopping it before the put-back; each other stop before the put-back — a hook that throws (the sender's lock held past
#     its wait), a lock not had (the quote pass's), the installed plugin with no tool, the checkout with no wrapper, the run's
#     folder not made (every copy "could not be read") and Part P unreadable at AC-RB1; the by-hand put-back a block of its
#     own, a weakened copy that prints a command beside it caught; Part V's readings over rows that exist (a deferred voice
#     turn left by the bounded drain, an audio row in flight untouched, an R4 body noted, an R3 body FAILed in V1's rollback),
#     Part O's young test left (R18), quote_agent_cc_admins kept at a set value; VN-RB6 with its count unread, AC-RB2 over a
#     planted edit; a 5.18.104 file left installed caught by QF-RB3, QC-RB1 and RB (6); a deploy whose
#     sign-in page redirects rolls itself back, QF-RB1 first (6v), and, its rollback not verifying, prints the put-back alone,
#     the hazard first (6w);
#   · (7f) --redeploy --clear-switches with every part's keys set: the earlier deployment's rows and entries printed; a
#     reminder run begun before the clear recording after the baseline, a master cycle begun before it dispatching quote_chase
#     after the copy, a daily-list run kept waiting by a second connection holding the book's write lock 3 s and claiming in
#     QF-G1's pause, an offer claim and an entry in the copy's start second — QF-CA2, QF-CA3, OF-CA3 PASS; (7p) Part P starting
#     dark, AC-ON1 OFF — not judged after it; (7v) Part V's (a) — the history, audio rows at each rung made by the earlier
#     build's own acts, and a pending acknowledgement event recorded switched_off by the dark build, VN-G6 printing, VN-CA4
#     PASS — and (b) — a note, its acknowledgement and its call between stage A's copy and the clear, an acknowledgement and a
#     call held after their fresh reads into the copy's start second, VN-CA4 PASS — and VN-G6's weakened copy caught; (7o)
#     Part O's acts after the clear — an offer paused by the hook, a test held past the copy's start, a list claim in the
#     copy's start second, and, in a run of its own (one live offer), an admin's Start held before its BEGIN IMMEDIATE —
#     OF-CA3 and QF-CA2 PASS; (7c) every part's controls read FAIL in their own counts, and the weakened copies — the stage-A
#     comparison, "at or after" (over the writers' own rows), no pause, absolute counts and every added row — each caught by
#     the check it weakens;
#   · (10) nothing reaches Evolution but the cases' own sends; across every run the copy observers were quiet, every token
#     copy gone, the stand-in uCRM given GETs only, the live vault as it was; no seeded name, phone, e-mail, staff name, key,
#     token, planted secret, client or staff id (the 0600 sheets their positive controls), figure of the book, bash runtime
#     error or retired check line in any terminal, log, state file, row file or request record; no trigger, actor or
#     injected pause left; nothing left in /tmp; this checkout unchanged — each sweep with its control.
#
# Everything runs in a sandbox; it never touches this checkout, the server or the network, and refuses to run where the
# server could be. Everything seeded is fictitious: every name, phone and e-mail of this rehearsal's is an RH104SECRET-
# value or a number of its own range, listed in its 0600 pattern files for the sweeps.
#
#   bash scripts/harness/deploy-5.18.104/rehearse.sh            REHEARSE_KEEP=<dir> keeps every run's full output
set -u
for f in /data/ucrm /opt/dishnet /var/run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
H104_DIR="$(cd "$(dirname "$0")" && pwd)"
H104_ROOT="$(cd "$H104_DIR/../../.." && pwd)"
F104=scripts/deploy-5.18.104.sh
P104_EARLY="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT="\([^"]*\)".*/\1/p' "$H104_ROOT/$F104" 2>/dev/null)"
P104F_EARLY="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT_FULL="\([^"]*\)".*/\1/p' "$H104_ROOT/$F104" 2>/dev/null)"
T1_EARLY104="$(sed -n 's/^T1_SIGNATURE="\([^"]*\)".*/\1/p' "$H104_ROOT/$F104" 2>/dev/null)"
case "$P104_EARLY" in __*|'') echo "refusing: $F104 is not pinned to a release commit (${P104_EARLY:-none})"; exit 2;; esac
case "$P104F_EARLY" in "$P104_EARLY"*) [ "${#P104F_EARLY}" = "40" ] || { echo "refusing: $F104 does not pin its release commit in full"; exit 2; } ;; *) echo "refusing: $F104's full pin does not begin with its short one"; exit 2;; esac
case "$T1_EARLY104" in [1-9]*' passed, 0 failed rc=0') ;; *) echo "refusing: $F104 does not pin T1's count (T1_SIGNATURE=${T1_EARLY104:-none})"; exit 2;; esac
grep -qxF 'QF_TOOL="tools/sales_followup.php"; QF_REPORT="tools/sales_followup_report.php"; QF_WRAPPER="scripts/sales-followup.sh"' "$H104_ROOT/$F104" \
  || { echo "refusing: $F104 is not the sales follow-up's derivation (no QF_TOOL line of Part F's) — this rehearsal is written for it"; exit 2; }
for f104 in scripts/sales-followup.sh scripts/sales-followup-report.sh; do
  [ -f "$H104_ROOT/$f104" ] || { echo "refusing: the checkout has no $f104 — the deploy and its rollback call it"; exit 2; }
done
for f104 in rh104-seed.php rh104-ref.php rh104q.py rh104-live.php rh104-clock.c; do
  [ -f "$H104_DIR/$f104" ] || { echo "refusing: this rehearsal's helper $f104 is not beside it"; exit 2; }
done
H103_HARNESS="$H104_DIR/../deploy-5.18.103/rehearse.sh"
H104_PREFIX="$(mktemp)"
python3 - "$H103_HARNESS" "$H104_PREFIX" <<'PY' || { echo "refusing: the previous release's rehearsal could not be cut before its last section (its sweeps)"; rm -f "$H104_PREFIX"; exit 2; }
import sys
src, dst = sys.argv[1], sys.argv[2]
anchor = 'echo; echo "== 103-10. what the rehearsal left behind, and what it never reached =="'
lines = open(src, encoding='utf-8').read().split('\n')
at = [i for i, l in enumerate(lines) if l == anchor]
assert len(at) == 1, len(at)
assert at[0] > 900, at[0]                   # its deploy, rollbacks, --redeploy and the quoting organization off come before it
# The cut ends on the last check of its last section before the anchor, 103-8 — read from the file: the last section
# heading before the anchor is 103-8's, and its last non-empty line is the check that puts the quoting organization's
# setting back in both copies.
heads = [i for i in range(at[0]) if lines[i].startswith('echo; echo "== 103-')]
assert heads and lines[heads[-1]].startswith('echo; echo "== 103-8. the quoting organization off'), (lines[heads[-1]][:60] if heads else 'none')
last = [l for l in lines[heads[-1]:at[0]] if l.strip()][-1]
assert last.startswith('check "$(data94)|$(set99 ucrm_quote_organization_id)" "$D103|unset/unset" '), last[:90]
assert sum(1 for l in lines[:at[0]] if l == last) == 1
pre = '\n'.join(lines[:at[0]]) + '\n'
# That rehearsal finds its own helpers beside itself ($0's folder): sourced from here, $0 is this file, so its one line
# naming that folder points at its own folder instead — the one edit, asserted.
old_dir = 'H103_DIR="$(cd "$(dirname "$0")" && pwd)"\n'
new_dir = 'H103_DIR="$(cd "$(dirname "$0")/../deploy-5.18.103" && pwd)"   # the next release\'s rehearsal: this one\'s own folder, where its helpers are\n'
assert pre.count(old_dir) == 1, pre.count(old_dir)
pre = pre.replace(old_dir, new_dir, 1)
assert pre.count(new_dir) == 1 and pre.count(old_dir) == 0
for need in ('H102_HARNESS="$H103_DIR/../deploy-5.18.102/rehearse.sh"', 'run103() {', 'run_line103() {', 'fresh103() {', 'nogo103() {',
             'checkgo103() {', 'weak103() {', 'rb103() {', 'after_back103() {', 'copy_back103() {', 'installed103() {', 'left103() {',
             'reach103() {', 'cat103() {', 'ref103() {', 'sale103() {', 'q103() {', 'qkv103() {', 'catq103() {', 'cache103() {',
             'LOGW103() {', 'last103() {', 'ureq103() {', 'lim103() {', 'uother103() {', 'gets103() {', 'audit103() {', 'vault103() {',
             'qor103() {', 'qol103() {', 'fset103() {', 'dk103() {',
             'REL103=release/5.18.103; DEPLOY103="$REPO/$F103"', 'OUT103="$SB/out103"; mkdir -p "$OUT103"; STATE103="$OUT103/state-5.18.103.env"',
             'R103="$SB/r103"', 'H103="$SB/rh103"', 'REF103="$H103/ref"', "CATON103='", 'ANCH103="$SB/anchors103.tsv"',
             'echo; echo "== 103-8. the quoting organization off'):
    assert ('\n' + pre).count('\n' + need) == 1, need   # each the start of exactly one line
open(dst, 'w', encoding='utf-8').write(pre)
PY
# shellcheck disable=SC1090
. "$H104_PREFIX"
rm -f "$H104_PREFIX"
for f104 in check has okline failline noteline fails printed qrun live inst_ver data94 kept_state oldname fp99 sq sqx qj set99 cfg_keep cfg_back \
            file_set row_set u99_set spent99 evo_calls vt_host ledger95 both_copies names100 secrets99 noids99 bankhits98 base_log set_sched epoch99 utc \
            dl ver checkout_state pubget u99_state_102 ug102 sqn102 \
            run103 run_line103 fresh103 after_back103 copy_back103 installed103 left103 reach103 nogo103 checkgo103 went103 quiet103 rb103 \
            cat103 ref103 sale103 q103 qkv103 catq103 cache103 LOGW103 last103 ureq103 lim103 uother103 gets103 audit103 vault103 qor103 qol103 \
            fset103 dk103 sk103 qcf103 n103 free_port ug102; do
  declare -F "$f104" >/dev/null || { echo "refusing: the rehearsals this one runs did not define $f104()"; exit 2; }
done
for v104 in SB REPO R P PD DATA VAULT MOUNT PHPBIN IN_CONTAINER RECS QD PLUGIN_BASE WEB_OK AFTER94 SCAN96 SEC95 W96 DATE_TS98 \
            U99 U99_URL U99_SECRETS TOKEN99 APPKEY99 EXP_QCHECK CHECKOUT0 VT99_0 NAMES102 H99 \
            OUT99 OUT100 OUT101 OUT102 OUT103 STATE102 STATE103 H102 H103 R103 REF103 P102 P103 P103F DEPLOY103 ADDED103 CHANGED103 NA103 \
            D103 KEPT103 EXP_AFTER103 EXP_ROLLBACK103 EXP_REDEPLOY103 CATON103 F103; do
  [ -n "${!v104:-}" ] || { echo "refusing: the rehearsals this one runs did not set $v104"; exit 2; }
done
for f104 in rh103q.py rh103.py rh103-cat.php rh103-ref.php rh103-sale.php; do [ -f "$H103/$f104" ] || { echo "refusing: 5.18.103's rehearsal left no $f104 in its folder"; exit 2; }; done
FAIL_PRE104=$FAILN   # the earlier rehearsals' failures, before this release's own sections: the last line names them

# ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
# 5.18.104's own rehearsal, on the sandbox built above.
# ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
REL104=release/5.18.104; DEPLOY104="$REPO/$F104"
if git -C "$R" ls-files --error-unmatch "$F104" >/dev/null 2>&1; then
  cmp -s "$R/$F104" "$DEPLOY104" || { echo "refusing: $F104 differs from the committed one — commit it, then rehearse"; exit 2; }
  WHICH104="the committed script"
else cp "$R/$F104" "$DEPLOY104"; WHICH104="the working copy (not yet committed; untracked in the clone)"; fi
# The two wrappers the deploy, its rollback and the operator's steps call (scripts/sales-followup.sh,
# scripts/sales-followup-report.sh): the clone's must be the checkout's, committed or not, as the script is.
for f104 in scripts/sales-followup.sh scripts/sales-followup-report.sh; do
  if git -C "$R" ls-files --error-unmatch "$f104" >/dev/null 2>&1; then
    cmp -s "$R/$f104" "$REPO/$f104" || { echo "refusing: $f104 differs from the committed one — commit it, then rehearse"; exit 2; }
  else cp "$R/$f104" "$REPO/$f104"; fi
done
git -C "$REPO" fetch -q origin "refs/heads/$REL104:refs/remotes/origin/$REL104" 2>/dev/null || true
P104="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT="\([^"]*\)".*/\1/p' "$DEPLOY104")"
P104F="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT_FULL="\([^"]*\)".*/\1/p' "$DEPLOY104")"
ADDED104="$(sed -n 's/^EXPECTED_ADDED="\(.*\)"$/\1/p' "$DEPLOY104")"; CHANGED104="$(sed -n 's/^EXPECTED_CHANGED="\(.*\)"$/\1/p' "$DEPLOY104")"
NA104=$(wc -w <<<"$ADDED104"); NC104=$(wc -w <<<"$CHANGED104"); N104=$((NA104 + NC104))   # the script's own lists — never counted here by hand
OUT104="$SB/out104"; mkdir -p "$OUT104"; STATE104="$OUT104/state-5.18.104.env"
R104="$SB/r104"; mkdir -p "$R104/logs" "$R104/mutants" "$R104/sheets" "$R104/exec" "$R104/scan" "$R104/vault" "$R104/tok" "$R104/ucrm"
SALES104="$R104/sales"; REPORT104="$R104/report"   # the wrappers' log folders in every run of this rehearsal — never /root/dnb-sales or /root/dnb-report
H104="$SB/rh104"; mkdir -p "$H104/v"; cp "$H104_DIR"/rh104-seed.php "$H104_DIR"/rh104-ref.php "$H104_DIR"/rh104q.py "$H104_DIR"/rh104-live.php "$H104_DIR"/rh104-clock.c "$H104/"
chmod 755 "$H104"; chmod 644 "$H104"/rh104-*.php "$H104/rh104q.py" "$H104/rh104-clock.c"; chown 1000:1000 "$H104/v"; chmod 700 "$H104/v"   # the writers' own folder, the store's user's
LOGW104() { printf '2>&1 | ( umask 077; exec tee %s/%s-%s.log )' "$OUT104" "$1" "$DATE_TS98"; }   # the script's LOGW, as it prints it
EXP_AFTER104="cd $REPO && bash scripts/deploy-5.18.104.sh --after-only $(LOGW104 after)"
EXP_ROLLBACK104="cd $REPO && bash scripts/deploy-5.18.104.sh --rollback $(LOGW104 rollback)"
EXP_REDEPLOY104="cd $REPO && bash scripts/deploy-5.18.104.sh --redeploy $(LOGW104 redeploy)"
EXP_PUTBACK104="( cd $REPO && git checkout $P103 && { bash scripts/deploy-hybrid.sh; git checkout -; } ) $(LOGW104 putback)"   # the script's PUTBACK_CMD, as it prints it
runbook_with104() { printf 'cd %s && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.104 && mkdir -p %s && bash scripts/deploy-5.18.104.sh%s %s' "$REPO" "$OUT104" "${1:+ $1}" "$(LOGW104 "${2:-deploy}")"; }
left_tmp104() { ls -d /tmp/dnb-5.18.104-* /tmp/dnb-report-* 2>/dev/null | wc -l | tr -d ' '; }
host_code104() { ls -d "$OUT104"/code-* "$OUT104"/p3b-* "$OUT104"/br1-* "$OUT104"/domainb-* "$OUT104"/tn-* "$OUT104"/qs-* "$OUT104"/qf-hk7-* 2>/dev/null | wc -l | tr -d ' '; }
backups104() { ls -d "$OUT104"/backup-* 2>/dev/null | wc -l | tr -d ' '; }
states104() { ls -1 "$OUT104"/state-5.18.104.env* "$OUT104"/rollback-state-* "$OUT104"/ql-key-* 2>/dev/null | sort | while IFS= read -r f; do printf '%s %s\n' "${f##*/}" "$(sha256sum "$f" | cut -c1-16)"; done | sha256sum | cut -c1-16; }
cache104() {   # UcrmQuoteLines' page cache and its last good copy, anywhere a run of this release could write either
  { find "$DATA" "$PD" -maxdepth 2 \( -name ucrm_quote_catalogue_cache.json -o -name ucrm_quote_catalogue_last_good.json \) 2>/dev/null
    find /tmp -maxdepth 6 -path '/tmp/dnb-5.18.104-*' \( -name ucrm_quote_catalogue_cache.json -o -name ucrm_quote_catalogue_last_good.json \) 2>/dev/null; } | grep -c .
}
fresh104() {   # the run folder as a new run finds it: the state files, the QL key file and the row digests kept; every log and sheet moved aside
  find "$OUT104" -mindepth 1 -maxdepth 1 -name '*.log' -exec mv -t "$R104/logs/" {} + 2>/dev/null
  find "$OUT104" -mindepth 1 -maxdepth 1 -name 'cash-*.txt' -exec mv -t "$R104/sheets/" {} + 2>/dev/null
  find "$OUT104" -mindepth 1 -maxdepth 1 ! -name 'state-5.18.104.env*' ! -name 'rollback-state-*' ! -name 'ql-key-*' ! -name 'cih-rows-*' -exec rm -rf {} +; base_log; }
installed104() {   # installed104 COMMIT — the number of files 5.18.103 → 5.18.104 touches that are NOT installed as COMMIT has them
                   # (a file COMMIT does not hold is not asked about: 5.18.103 holds none of the added ones)
  local f rel bad=0
  for f in $(dl AM "$P103" "$P104"); do
    rel="${f#$P/}"
    if git -C "$REPO" cat-file -e "$1:$f" 2>/dev/null; then
      [ "$(git -C "$REPO" show "$1:$f" | sha256sum | cut -c1-64)" = "$(sha256sum "$PD/$rel" 2>/dev/null | cut -c1-64)" ] || bad=$((bad+1))
    fi
  done
  echo "$bad"
}
left104() { local f n=0; for f in $ADDED104; do [ -e "$PD/$f" ] && n=$((n+1)); done; echo "$n"; }   # the added files on disk
last104() { printf '%03d' "$(cat "$SB/runs")"; }
# 089's, 090's and 091's tables and followups' new columns, read from the release's migrations in Git — what QF-G1 and
# VN-G2 expect absent on a book 5.18.104 never ran on, and what --check must not create on the live book.
MIGF104="$(dl A "$P103" "$P104" | grep "^$P/migrations/" | sed 's#.*/##' | LC_ALL=C sort | paste -sd, -)"
TBL104="$(for m in $(tr ',' ' ' <<<"$MIGF104"); do git -C "$REPO" show "$P104:$P/migrations/$m"; done | grep -oE 'CREATE TABLE (IF NOT EXISTS )?[a-z_]+' | awk '{print $NF}' | LC_ALL=C sort -u | paste -sd, -)"
COL104="$(for m in $(tr ',' ' ' <<<"$MIGF104"); do git -C "$REPO" show "$P104:$P/migrations/$m"; done | grep -oE 'ALTER TABLE followups ADD COLUMN [a-z_]+' | awk '{print $NF}' | LC_ALL=C sort -u | paste -sd, -)"
tables104() { sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1; python3 "$H104/rh104q.py" tables "${1:-$DATA/plugin.sqlite3}" "$TBL104" "$COL104"; }   # t104=<present>/<all> cols=… control=… tables=<n>
mig104() { python3 "$H104/rh104q.py" mig "${1:-$DATA/plugin.sqlite3}" "$MIGF104"; }
q104() { sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1; python3 "$H104/rh104q.py" "$1" "${2:-$DATA/plugin.sqlite3}" "${@:3}"; }   # q104 n2|n7|chan|g5|g6|staffraw [DB] — this rehearsal's own reading, read-only
kv104() { python3 "$H104/rh104q.py" kv "$1" "$2"; }
run104() {   # [--answer WORD] [--script FILE] [--env K=V]…, THEN the script's options — as run103, this release's folders, the
             # wrappers' log folders the rehearsal's own; the stand-in's requests.log and limits.log emptied before the run and kept
  local answer="" script="$DEPLOY104" extra=() n tag o cmd
  while :; do case "${1:-}" in --answer) answer="$2"; shift 2 ;; --script) script="$2"; shift 2 ;; --env) extra+=("$2"); shift 2 ;; *) break ;; esac; done
  : > "$SB/docker.log"; : > "$U99/requests.log"; : > "$U99/limits.log"
  n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"; tag="$(printf '%03d' "$n")"
  vt_host > "$R104/vault/run-$tag.before"; : > "$R104/exec/run-$tag.rec"; : > "$R104/scan/run-$tag.hits"; : > "$R104/tok/run-$tag.tok"
  cmd="$(printf '%q ' env PATH="$SB/datebin:$SB/bin:$PATH" FAKE_TODAY="$AFTER94" DNB_OUT="$OUT104" DNB_RECORDS="$RECS" DNB_WEBSITE="$WEB_OK" GUARD_SECONDS=3 DN_DATA_DIR="$DATA" \
         DNB_SALES_LOG_DIR="$SALES104" DNB_REPORT_DIR="$REPORT104" \
         FAKE_EXEC_LOG="$R104/exec/run-$tag.rec" FAKE_TMP_SCAN="$SCAN96" FAKE_TMP_HITS="$R104/scan/run-$tag.hits" FAKE_TOK_HITS="$R104/tok/run-$tag.tok" \
         ${extra[@]+"${extra[@]}"} bash "$script" --plugin-base "$PLUGIN_BASE" "$@")"
  o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r')"
  vt_host > "$R104/vault/run-$tag.after"; cp "$U99/requests.log" "$R104/ucrm/run-$tag.req"; cp "$U99/limits.log" "$R104/ucrm/run-$tag.lim"
  case "$o" in *"unknown option:"*) echo "run104-$n" >> "$SB/bad-runs" ;; esac
  if [ -n "${R104_OUT_DIR:-}" ]; then printf '%s\n' "$o" > "$R104_OUT_DIR/run-$tag.txt"
  elif [ -n "${R104_MUTANT_OUT:-}" ]; then printf '%s\n' "$o" > "$R104/mutants/run-$tag.txt"; else printf '%s\n' "$o" > "$R104/run-$tag.txt"; fi
  [ -z "${REHEARSE_KEEP:-}" ] || printf '%s\n' "$o" > "$REHEARSE_KEEP/run104-$tag-${answer:-none}-$(basename "$script" .sh)$(printf '%s' "$*" | tr -c 'A-Za-z0-9-' '_').txt"
  printf '%s' "$o"
}
run_line104() {   # run_line104 WORD LINE — a command line exactly as a summary printed it, through a pseudo-terminal, WORD typed
  local answer="$1" line="$2" n tag o cmd
  : > "$SB/docker.log"; : > "$U99/requests.log"; : > "$U99/limits.log"
  n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"; tag="$(printf '%03d' "$n")"
  vt_host > "$R104/vault/run-$tag.before"; : > "$R104/exec/run-$tag.rec"; : > "$R104/scan/run-$tag.hits"; : > "$R104/tok/run-$tag.tok"
  cmd="$(printf '%q ' env PATH="$SB/datebin:$SB/bin:$PATH" FAKE_TODAY="$AFTER94" DNB_OUT="$OUT104" DNB_RECORDS="$RECS" DNB_WEBSITE="$WEB_OK" GUARD_SECONDS=3 DN_DATA_DIR="$DATA" PLUGIN_BASE="$PLUGIN_BASE" \
         DNB_SALES_LOG_DIR="$SALES104" DNB_REPORT_DIR="$REPORT104" \
         FAKE_EXEC_LOG="$R104/exec/run-$tag.rec" FAKE_TMP_SCAN="$SCAN96" FAKE_TMP_HITS="$R104/scan/run-$tag.hits" FAKE_TOK_HITS="$R104/tok/run-$tag.tok" bash -c "$line")"
  o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r')"
  vt_host > "$R104/vault/run-$tag.after"; cp "$U99/requests.log" "$R104/ucrm/run-$tag.req"; cp "$U99/limits.log" "$R104/ucrm/run-$tag.lim"
  case "$o" in *"unknown option:"*) echo "line104-$n" >> "$SB/bad-runs" ;; esac
  printf '%s\n' "$o" > "$R104/line-$tag.txt"
  [ -z "${REHEARSE_KEEP:-}" ] || printf '%s\n' "$o" > "$REHEARSE_KEEP/line104-$tag-${answer:-none}.txt"
  printf '%s' "$o"
}
went104() { echo "$(has "$1" 'GO — evidence recorded')$(has "$1" 'Type DEPLOY')"; }   # the run reached the question
quiet104() { echo "$(live)|$(backups104)|$(data94)|$(kept_state)|$(oldname)|$(cache104)|$(tables104 | cut -d' ' -f1-2)"; }   # nothing deployed, backed up or changed
nogo104() {   # nogo104 LABEL EXPECTED-TEXT OUTPUT [DATA-DIGEST [TABLES]] — refused with that text and "Nothing was changed",
              # never the question, nothing deployed, backed up or changed: 5.18.103 serving, none of 089–091's objects made
  check "$(has "$3" "$2")$(has "$3" 'Nothing was changed. Send the log file.')$(went104 "$3")|$(quiet104)" "yesyesnono|$P103|0|${4:-$D104}|$KEPT104|0|0|${5:-$T0_104}" "$1"
}
checkgo104() {   # checkgo104 OUTPUT — --check said GO: stage A held — nothing asked, nothing backed up, no state written
  echo "$(has "$1" "  5.18.104 (check): GO — stage A holds.")$(has "$1" '  --check           stage A alone, every refusal and note of a deploy')$(went104 "$1")$(fails "$1")|$(backups104)|$(states104)"
}
audit104() { python3 "$H104/rh104.py" audit "$R104/exec/run-$1.rec"; }   # audit104 TAG — copies=<n> novault=<n> plugin=<n> adj=<id|-> (the older readings' code copies; 5.18.104's own readings left to qfaud104)
qfaud104() { python3 "$H104/rh104q.py" qfaudit "$R104/exec/run-$1.rec" "$QFLIVE104"; }   # qfaud104 TAG — copy=<n> env=<n> put=<n> bare=<n> baremodes=… live=<n> livemodes=… (5.18.104's own readings, by mode; QFLIVE104 below)
hits104() { local n; n="$(grep -c . "$R104/scan/run-$1.hits" 2>/dev/null)"; echo "${n:-0}"; }
vault104() { echo "$(cat "$R104/vault/run-$1.before")>$(cat "$R104/vault/run-$1.after")"; }
uother104() { python3 "$H99/rh99.py" u99other "$R104/ucrm/run-$1.req"; }   # the requests to the stand-in uCRM that were not a GET
ureq104() { python3 "$H102/rh102q.py" u102req "$R104/ucrm/run-$1.req"; }   # …the run's requests by shape (5.18.102's reader: the shapes are the same)
lim104() { python3 "$H102/rh102q.py" limits "$R104/ucrm/run-$1.lim"; }     # …the limit values its list reads asked for
gets104() { sed -n 's/^  uCRM              asked \([0-9][0-9]*\) GET(s) by this run .*/\1/p' <<<"$1" | head -1; }   # the GETs F says this run asked
fset104() { printf '%s:%s' "$(grep -aE '^  FAIL  ' <<<"$1" | awk '{print $2}' | LC_ALL=C sort | paste -sd' ' -)" "$(sed -nE 's/^  5\.18\.104 \((after|deploy|rollback)\): ([0-9]+) FAILED.*/\2/p' <<<"$1" | head -1)"; }
wason104() { grep -v '^  note  QC-N3 the applications by entry ' <<<"$1" | grep -c 'was on: '; }   # wason104 OUTPUT — the tool's 'was on' names that reached the terminal: every line carrying 'was on: ', less DS5's QC-N3 note, whose prose says "…sent once it was on: G-9" (trial 5, 1k-d)
sv104() { sed -nE "s/^$2=\"([^\"]*)\".*/\1/p; t; s/^$2=([^ \";]+).*/\1/p" "${1:-$DEPLOY104}" | head -1; }   # sv104 FILE NAME — the value of a line of the script that begins NAME= (quoted or not; a comment after it aside)
# ── The readers: 5.18.103's, taught this release's folders in /tmp, by asserted replacements — and its audit taught to leave
# 5.18.104's own readings (QF_PHP, VN_PHP, OF_PHP, AC_PHP, known by their -r source) to rh104q.py's qfaudit, which judges
# them by mode: the older rule asks every process on a code root for a vault of its own, and those readings by design pass
# the pin's code root to the modes that read live files (QF-G4, VN-G3, OF-G1's key names, the settings and vault they cut)
# with none — judged instead by the live vault staying as it was. ──────────────────────────────────────────────────────
python3 - "$H103/rh103.py" "$H104/rh104.py" <<'PY' || { echo "readers:rh104" >> "$SB/anchor-missing"; echo "refusing: this rehearsal's readers could not be derived from the previous release's"; exit 2; }
import sys
s = open(sys.argv[1]).read()
reps = [
    ("root = re.compile(r'^/tmp/dnb-5\\.18\\.103-(?:cih-[^/]+/[^/]+/(?:t|d|p0root|k-(?:found|off|on)|c-(?:old|new)|g-(?:i|p|c0|c1)|l|u|t-(?:i|p))/dishnet-hybrid-sudan|code-[^/]+/p3b/[^/]+/dishnet-hybrid-sudan)(?:/|$)')",
     "root = re.compile(r'^/tmp/dnb-5\\.18\\.104-(?:cih-[^/]+/[^/]+/(?:t|d|p0root|k-(?:found|off|on)|c-(?:old|new)|g-(?:i|p|c0|c1)|l|u|t-(?:i|p))/dishnet-hybrid-sudan|code-[^/]+/p3b/[^/]+/dishnet-hybrid-sudan)(?:/|$)')"),
    ("if not env.get('DN_VAULT_FILE', '').startswith('/tmp/dnb-5.18.103-'): novault += 1",
     "if not env.get('DN_VAULT_FILE', '').startswith('/tmp/dnb-5.18.104-'): novault += 1"),
    (r"        j = '\n'.join(x for k, x in enumerate(c) if not (k > 0 and c[k - 1] == '-r'))",
     r"        # 5.18.104's own readings — QF_PHP, VN_PHP, OF_PHP, AC_PHP, known by their -r source — are judged by mode in"
     "\n"
     r"        # rh104q.py's qfaudit (a reading of live files by design, or a copy and its vault), never by this older rule"
     "\n"
     r"        if any(k > 0 and c[k - 1] == '-r' and re.match(r'// (?:QF|VN|OF|AC)_PHP\b', x) for k, x in enumerate(c)): continue"
     "\n"
     r"        j = '\n'.join(x for k, x in enumerate(c) if not (k > 0 and c[k - 1] == '-r'))"),
]
for old, new in reps[:2]:
    assert s.count(old) == 1, old[:70]
    s = s.replace(old, new)
    assert s.count(new) == 1 and s.count(old) == 0
old, new = reps[2]   # an insertion before its anchor: the anchor stays, once
assert s.count(old) == 1 and s.count('(?:QF|VN|OF|AC)_PHP') == 0, s.count(old)
s = s.replace(old, new, 1)
assert s.count(old) == 1 and s.count(new) == 1 and s.count('(?:QF|VN|OF|AC)_PHP') == 1
open(sys.argv[2], 'w').write(s)
PY
# The observers search this release's folders in /tmp too — and the report wrapper's (scripts/sales-followup-report.sh's
# /tmp/dnb-report-<UTC>-<pid>: its VACUUM INTO copy of the book, the settings copies and the vault cut for it) — (the fake
# docker's one list, extended, asserted), the token
# observer counts this release's cash and quoting folders beside the earlier ones', and the fake docker learns the one
# exec flag 5.18.104's wrapper gives that it did not know: -it (erase, on chase, on cold read from a terminal; the
# rehearsal's runner is the pseudo-terminal) — each edit asserted.
python3 - "$SB/bin/docker" <<'PY' || echo "fakedocker:observers104" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]; s = open(p).read()
assert s.count('/tmp/dnb-5.18.104-') == 0 and s.count('/tmp/dnb-report-') == 0
add = ['/tmp/dnb-5.18.104-cih-*', '/tmp/dnb-5.18.104-ss-*', '/tmp/dnb-5.18.104-qcrb-*', '/tmp/dnb-5.18.104-code-*/p3b',
       '/tmp/dnb-5.18.104-code-*/t1run', '/tmp/dnb-5.18.104-code-*/t4run']
old = ' /tmp/dnb-5.18.103-code-*/t4run; do\n'
assert s.count(old) == 1, s.count(old)
s = s.replace(old, ' /tmp/dnb-5.18.103-code-*/t4run ' + ' '.join(add) + ' /tmp/dnb-report-*; do\n', 1)
assert s.count('/tmp/dnb-report-') == 1 and s.count(' /tmp/dnb-5.18.104-code-*/t4run /tmp/dnb-report-*; do\n') == 1
old2 = '/tmp/dnb-5.18.103-cih-* -name token.json 2>/dev/null | grep -c .)" "${_rh_m:--}" >> "$FAKE_TOK_HITS"'
assert s.count(old2) == 1, s.count(old2)
s = s.replace(old2, '/tmp/dnb-5.18.103-cih-* /tmp/dnb-5.18.104-cih-* -name token.json 2>/dev/null | grep -c .)" "${_rh_m:--}" >> "$FAKE_TOK_HITS"', 1)
assert s.count('/tmp/dnb-5.18.104-') == len(add) + 1, s.count('/tmp/dnb-5.18.104-')
old3 = '  -i) shift ;;\n'
assert s.count(old3) == 1, s.count(old3)
s = s.replace(old3, '  -i|-t|-it|-ti) shift ;;   # 5.18.104\'s rehearsal: the wrapper\'s -it — the terminal is the pseudo-terminal its runner gives\n', 1)
assert s.count(old3) == 0 and s.count('  -i|-t|-it|-ti) shift ;;') == 1
open(p, 'w').write(s)
PY
dk104() { env PATH="$SB/bin:$PATH" bash "$SB/bin/docker" "$@" 2>/dev/null; }
mkdir -p /tmp/dnb-5.18.104-cih-rhcontrol/qo-a-1/tok; printf '{"crm_auth_token":"x"}\n' > /tmp/dnb-5.18.104-cih-rhcontrol/qo-a-1/tok/token.json
printf 'rh104-observer-control\n' > /tmp/dnb-5.18.104-cih-rhcontrol/webhook_secret; printf 'rh104-observer-control\n' > "$SB/obs104.pat"
mkdir -p /tmp/dnb-report-rhcontrol; printf 'rh104-observer-control\n' > /tmp/dnb-report-rhcontrol/webhook_secret   # the report wrapper's folder's shape
env PATH="$SB/bin:$PATH" FAKE_TOK_HITS="$SB/obs104.tok" FAKE_TMP_SCAN="$SB/obs104.pat" FAKE_TMP_HITS="$SB/obs104.hits" bash "$SB/bin/docker" exec ucrm php -r 'echo 1;' remote /tmp/x >/dev/null 2>&1
rm -f /tmp/dnb-5.18.104-cih-rhcontrol/qo-a-1/tok/token.json
env PATH="$SB/bin:$PATH" FAKE_TOK_HITS="$SB/obs104.tok" bash "$SB/bin/docker" exec ucrm php -r 'echo 1;' token /tmp/x >/dev/null 2>&1
check "$(bash -n "$SB/bin/docker" && echo parses)|$(paste -sd, "$SB/obs104.tok")|$(grep -c '^value /tmp/dnb-5.18.104-cih-rhcontrol/webhook_secret$' "$SB/obs104.hits")$(grep -c '^file /tmp/dnb-5.18.104-cih-rhcontrol/webhook_secret$' "$SB/obs104.hits")$(grep -c '^value /tmp/dnb-report-rhcontrol/webhook_secret$' "$SB/obs104.hits")$(grep -c '^file /tmp/dnb-report-rhcontrol/webhook_secret$' "$SB/obs104.hits")|$(dk104 exec -it ucrm true; echo $?)|$(dk104 exec -z ucrm true; echo $?)|$(python3 -c 'import ast, sys; [ast.parse(open(f).read()) for f in sys.argv[1:]]; print("parses")' "$H104/rh104.py" "$H104/rh104q.py" 2>&1)|$(grep -c '5\.18\.10[0-3]-' "$H104/rh104.py")|$(for f in "$H104"/rh104-*.php; do php -l "$f" 2>&1 | grep -c '^No syntax errors'; done | tr -d '\n')" \
      "parses|1 remote,0 token|1111|0|99|parses|0|111" \
  "control: the fake docker with this release's folders — the token observer counts a token copy in a 5.18.104 cash folder at the call and none once it is gone, the scan finds a planted value and a file named webhook_secret there and in a folder shaped as the report wrapper's (/tmp/dnb-report-…) — and with -it learned (an unknown flag still refused, 99); this rehearsal's readers (5.18.103's derived, naming none of the earlier folders, and its own) and its three PHP helpers (seed, ref, live — the three copied into $H104) parse"
rm -rf /tmp/dnb-5.18.104-cih-rhcontrol /tmp/dnb-report-rhcontrol "$SB/obs104.pat" "$SB/obs104.hits" "$SB/obs104.tok"
# The two audits, on a record of four processes made here in the fake docker's own form (fields \037, records \036): a
# QF_PHP reading of the pin's code root with no vault (QF-G4's shape), a VN_PHP copy reading whose source sets its own vault,
# an older reading with a vault of its own and one without. The older audit counts the two older ones and the one without
# (QF_PHP's left to qfaudit); qfaudit classes QF-G4's as live by design and VN_PHP's as a copy that sets its vault. The
# control on the control: the older audit with the exclusion taken out (an asserted edit) counts QF-G4's as a copy with
# no vault — the reading 104-c would have failed on.
python3 -I - "$SB/aud104.rec" <<'PY'
import sys
recs = [
  ['exec', '-u', '1000:1000', 'ucrm', 'php', '-d', 'display_errors=stderr', '-r', "// QF_PHP — the rehearsal's control\n", 'g4', '/tmp/dnb-5.18.104-code-rhc-1/p3b/pin/dishnet-hybrid-sudan', '/data/ucrm/data/plugins/dishnet-hybrid-sudan', '/data/x'],
  ['exec', '-u', '1000:1000', 'ucrm', 'php', '-r', "// VN_PHP — the rehearsal's control\nif (in_array($mode, ['g5'], true)) putenv('DN_VAULT_FILE=' . 'x');", 'g5', '/tmp/dnb-5.18.104-cih-rhc/qf-vn-1/code', '/tmp/dnb-5.18.104-cih-rhc/qf-vn-1'],
  ['exec', '-u', '1000:1000', '-e', 'DN_VAULT_FILE=/tmp/dnb-5.18.104-cih-rhc/qo-a-1/g-i-vault.json', 'ucrm', 'php', '-r', 'echo 1;', 'gate', '/tmp/dnb-5.18.104-cih-rhc/qo-a-1/g-i/dishnet-hybrid-sudan'],
  ['exec', '-u', '1000:1000', 'ucrm', 'php', '-r', 'echo 2;', 'read', '/tmp/dnb-5.18.104-cih-rhc/a23-1/t/dishnet-hybrid-sudan'],
]
with open(sys.argv[1], 'wb') as f:
    for r in recs:
        f.write(b''.join(x.encode('utf-8') + b'\x1f' for x in r) + b'\x1e')
PY
python3 -I - "$H104/rh104.py" "$SB/aud104-weak.py" <<'PY' || echo "readers:aud104-weak" >> "$SB/anchor-missing"
import sys
s = open(sys.argv[1]).read()
old = "        if any(k > 0 and c[k - 1] == '-r' and re.match(r'// (?:QF|VN|OF|AC)_PHP\\b', x) for k, x in enumerate(c)): continue\n"
assert s.count(old) == 1, s.count(old)
s = s.replace(old, '', 1)
assert s.count(old) == 0 and s.count('(?:QF|VN|OF|AC)_PHP') == 0
open(sys.argv[2], 'w').write(s)
PY
# the modes that read live files by design, read from the script: each mode whose call's first argument is the pin's or the
# installed code root, the live plugin folder, the live book, the live vault's source root or the PHP log's path
QFLIVE104="$(grep -oE '\b(qf|vn|of|ac)_php [a-z0-9]+ "(\$P3B_PIN|\$\{P3B_PIN:-\$IN_CONTAINER\}|\$IN_CONTAINER|\$PDD_IN/plugin\.sqlite3|\$src|\$inst|\$\(dxo php -r)' "$DEPLOY104" | awk '{sub(/_php$/, "", $1); print $1 ":" $2}' | LC_ALL=C sort -u | paste -sd, -)"
check "$(python3 "$H104/rh104.py" audit "$SB/aud104.rec")|$(python3 "$SB/aud104-weak.py" audit "$SB/aud104.rec")|$(python3 "$H104/rh104q.py" qfaudit "$SB/aud104.rec" "$QFLIVE104")|$(case ",$QFLIVE104," in *,qf:g4,*vn:g3,*) echo g4g3 ;; *) echo "missing:$QFLIVE104" ;; esac)" \
      "copies=2 novault=1 plugin=0 adj=-|copies=3 novault=2 plugin=0 adj=-|copy=1 env=0 put=1 bare=0 baremodes=- live=1 livemodes=qf:g4|g4g3" \
  "control: the older audit counts the two older readings and the one with no vault of its own, never QF_PHP's; with the exclusion taken out it counts QF-G4's reading as a copy with no vault (the weakened reader caught); qfaudit classes QF-G4's reading of the pin's code as live by design (the script's own calls give the live modes: QF-G4's and VN-G3's among them) and VN_PHP's as a copy that sets its own vault"
rm -f "$SB/aud104.rec" "$SB/aud104-weak.py"
# The control on check 10 (T-D3): qfaudit still counts a reading of a copy whose source names no vault as bare. Two
# records of OF-CA3's reading of a copy (ca3 DB BASE COPYUNIX, as the script calls it after the copy), each carrying
# OF_PHP's own source read from the script under test: as the script has it, ca3 in the list of modes that name a vault
# never made, in their own folder (put); and with that line taken out (an asserted edit), the same reading bare — the
# reading check 10 refuses in every run. Each in a record of its own, so the older audit's control above keeps its wants.
python3 -I - "$DEPLOY104" "$SB/aud104-of.rec" "$SB/aud104-of-weak.rec" <<'PY' || echo "readers:aud104-of" >> "$SB/anchor-missing"
import sys
t = open(sys.argv[1], encoding='utf-8').read()
head = "OF_PHP=\"$(cat <<'PHP'\n"
assert t.count(head) == 1, t.count(head)
a = t.index(head) + len(head)
src = t[a:t.index('\nPHP\n', a)]
assert src.startswith('// OF_PHP '), src[:16]
line = ("if (in_array($mode, ['pre', 'm1', 'ca2', 'ca3', 'ca5', 'rb', 'rb2'], true)) putenv('DN_VAULT_FILE=' . "
        "dirname((string)($a[0] ?? '')) . '/of-' . $mode . '-vault-none.json');\n")
assert src.count(line) == 1, src.count(line)
weak = src.replace(line, '', 1)
assert weak.count(line) == 0 and len(weak) == len(src) - len(line), weak.count(line)
assert src.count("'ca3'") == 2 and weak.count("'ca3'") == 1, (src.count("'ca3'"), weak.count("'ca3'"))   # its list and its case line
def rec(s):
    r = ['exec', '-u', '1000:1000', 'ucrm', 'php', '-d', 'display_errors=stderr', '-d', 'allow_url_fopen=0', '-r', s,
         'ca3', '/tmp/dnb-5.18.104-cih-rhc/qf-of-1/plugin.sqlite3', '', '0']
    return b''.join(x.encode('utf-8') + b'\x1f' for x in r) + b'\x1e'
for p, s in ((sys.argv[2], src), (sys.argv[3], weak)):
    with open(p, 'wb') as f:
        f.write(rec(s))
PY
check "$(python3 "$H104/rh104q.py" qfaudit "$SB/aud104-of.rec" "$QFLIVE104")|$(python3 "$H104/rh104q.py" qfaudit "$SB/aud104-of-weak.rec" "$QFLIVE104")" \
      "copy=1 env=0 put=1 bare=0 baremodes=- live=0 livemodes=-|copy=1 env=0 put=0 bare=1 baremodes=of:ca3 live=0 livemodes=-" \
  "control on check 10 (T-D3): qfaudit classes OF-CA3's reading of a copy, with OF_PHP's source as the script has it, as one whose source names its vault (put: a vault never made, in its own folder); with that line taken out of the source (an asserted edit) the same reading is bare — what check 10 counts against a run"
rm -f "$SB/aud104-of.rec" "$SB/aud104-of-weak.rec"
DC104=/data/ucrm/data/plugins/dishnet-hybrid-sudan/.deployed-commit
# ── The tool and its wrapper, as the operator and the deploy script run them, and the book's readers. ──────────────────
PDD104_IN="$(dirname "$IN_CONTAINER")/.$P-data"   # the plugin's data directory in the container, as the wrapper finds it beside the plugin
owner104() { dk104 exec ucrm stat -c '%u:%g' "$PDD104_IN/plugin.sqlite3" | tr -cd '0-9:'; }   # the book's owner, as the wrapper reads it
sf104() {   # sf104 [--tty WORD] ARG… — scripts/sales-followup.sh as the operator runs it (the clone's, the INSTALLED tool), its
            # log in this rehearsal's folder; with --tty, through a pseudo-terminal with WORD typed (erase, on chase, on cold)
  local word="" tty="" cmd o
  [ "${1:-}" = "--tty" ] && { tty=1; word="$2"; shift 2; }
  # no mkdir of $SALES104 here: the wrapper makes its own log folder (mode 700) once it has passed its refusals
  if [ -n "$tty" ]; then
    cmd="$(printf '%q ' env PATH="$SB/bin:$PATH" DNB_SALES_LOG_DIR="$SALES104" bash "$REPO/scripts/sales-followup.sh" "$@")"
    o="$(printf '%s\n' "$word" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r'; exit "${PIPESTATUS[1]}")"; SF104_RC=$?
  else o="$(cd "$REPO" && env PATH="$SB/bin:$PATH" DNB_SALES_LOG_DIR="$SALES104" bash "$REPO/scripts/sales-followup.sh" "$@" 2>&1 </dev/null)"; SF104_RC=$?; fi
  printf '%s\n' "$o" >> "$R104/sf.txt"; echo "$SF104_RC" >> "$R104/sf.rc"   # the exit code beside it: sf104 runs in $( ), so its variable never reaches the caller
  printf '%s' "$o"
}
tool104() {   # tool104 ARG… — the INSTALLED tools/sales_followup.php as the wrapper's own exec runs it (the book's owner, the
              # plugin's data directory), without the wrapper: its output and exit code, as the tool prints them
  dk104 exec -u "$(owner104)" -e "DN_DATA_DIR=$PDD104_IN" ucrm php -d log_errors=0 "$IN_CONTAINER/tools/sales_followup.php" "$@" </dev/null
}
# The RELEASE's own code from Git, as a reference: the pin's lib/, profiles/, tools/ and migrations/ (REF104), and a code
# root of it with the live data/config.json beside it, as the script's readings carry it (refroot104); a scratch copy of the
# book with the settings files and the vault beside it (scr104) — read by rh104-ref.php as root, read-only; the live book
# and vault are never opened by it.
REF104="$H104/ref"; rm -rf "$REF104"; mkdir -p "$REF104"; git -C "$REPO" archive "$P104" "$P/lib" "$P/profiles" "$P/tools" "$P/migrations" | tar -xf - -C "$REF104"
refroot104() {   # refroot104 [CODE-ROOT] — a code root for the reference (the pin's from Git unless given), its data/config.json the live one's copy
  local d="$H104/refroot"; rm -rf "$d"; mkdir -p "$d"; cp -R "${1:-$REF104/$P}/." "$d/"; mkdir -p "$d/data"
  [ ! -f "$PD/data/config.json" ] || cp "$PD/data/config.json" "$d/data/config.json"; echo "$d"
}
scr104() {   # scr104 — a scratch copy of the book, its settings files and the vault, marked as the rehearsal's (rh104-scratch)
  local d="$H104/scr"; rm -rf "$d"; mkdir -p "$d"; sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1
  python3 -c 'import sqlite3, sys; s = sqlite3.connect("file:" + sys.argv[1] + "?mode=ro", uri=True); d = sqlite3.connect(sys.argv[2]); s.backup(d); d.close(); s.close()' "$DATA/plugin.sqlite3" "$d/plugin.sqlite3"
  for f in kyc_config.json config.json; do [ ! -f "$DATA/$f" ] || cp "$DATA/$f" "$d/$f"; done
  rm -f "$H104/v/ref-vault.json"; [ ! -f "$VAULT" ] || { cp "$VAULT" "$H104/v/ref-vault.json"; chmod 600 "$H104/v/ref-vault.json"; }
  : > "$d/rh104-scratch"; echo "$d"
}
ref104() {   # ref104 keys [ROOT] | conf|staff [ROOT [SCRATCH]] — the release's own reading (rh104-ref.php), one line, or none(<why>)
  local m="$1" root="${2:-}" dir="${3:-}" o
  [ -n "$root" ] || root="$( [ "$m" = keys ] && echo "$REF104/$P" || refroot104)"
  [ "$m" = keys ] || [ -n "$dir" ] || dir="$(scr104)"
  # DN_DATA_DIR = the scratch copy, as the script's readings set it to theirs: dn_tz()'s fallback reads its kyc_config.json
  # (lib/timezone.php dn_tz_fromDisk), never the empty configuration's zone (trial 2: Africa/Juba)
  o="$(DN_VAULT_FILE="$H104/v/ref-vault.json" DN_DATA_DIR="$dir" "$PHPBIN" "$H104/rh104-ref.php" "$m" "$root" "$dir" 2>"$H104/v/ref.err")"
  case "$o" in *=*) printf '%s' "$o" ;; *) echo "none($(head -c 160 "$H104/v/ref.err"))" ;; esac
}
seed104() {   # seed104 CODE-ROOT OPS-JSON [DATA-DIR] — fixtures through the code root's own writers (rh104-seed.php), in
              # Africa/Kampala, as the store's user, with a vault of its own; JSON, or none(<why>)
  local o; o="$(cd "$H104/v" && setpriv --reuid 1000 --regid 1000 --clear-groups -- env DN_VAULT_FILE="$H104/v/seed-vault.json" "$PHPBIN" "$H104/rh104-seed.php" "$1" "${3:-$DATA}" "$2" Africa/Kampala 2>"$H104/v/seed.err")"
  rm -f "$H104/v/seed-vault.json"
  case "$o" in '{"ops":'*) printf '%s' "$o" ;; *) echo "none($(head -c 160 "$H104/v/seed.err"))" ;; esac
}
# ── The quotation e-mails' stand-ins, both on 127.0.0.1 and each recording what it was asked. The fake relay: the release's
# own tests/fixtures/fake_smtp_server.php (the pin's, from Git), its transcript every message it was handed (MAIL FROM,
# RCPT TO, the message), started for the e-mails and stopped after them. The stand-in uCRM learns, by an asserted edit of
# its router, the client and the quotation PDF of this rehearsal's e-mails — only while its file rh104-mail.json is beside
# the router, GETs only, after its auth; recorded in requests.log like every other request. ──────────────────────────────
RELAY104="$H104/relay"; mkdir -p "$RELAY104"; chmod 700 "$RELAY104"
git -C "$REPO" show "$P104:$P/tests/fixtures/fake_smtp_server.php" > "$RELAY104/fake_smtp_server.php" 2>/dev/null
MAILER104="$H104/v/mail"   # the relay's email_settings.json for the e-mails, the store's user's — never the live data folder's
relay_up104() {   # relay_up104 — the relay on a free port of 127.0.0.1 (RPORT104), its transcript new; ready once the transcript
                  # exists (the fixture writes it only after its socket listens). Run in the main shell, never in $( ).
  RPORT104="$(free_port)"; rm -f "$RELAY104/transcript.json" "$RELAY104/transcript.json.part"
  "$PHPBIN" "$RELAY104/fake_smtp_server.php" "$RPORT104" "$RELAY104/transcript.json" 300 > "$RELAY104/server.log" 2>&1 & RPID104=$!; PIDS+=("$RPID104")
  local i; for i in $(seq 1 50); do [ -f "$RELAY104/transcript.json" ] && break; sleep 0.1; done
}
relay_down104() { [ -z "${RPID104:-}" ] || { kill "$RPID104" 2>/dev/null; wait "$RPID104" 2>/dev/null; }; RPID104=""; }
relay_n104() {   # relay_n104 FROM TO,TO… — the transcript, counts only: sessions, recipients, of them the expected ones, the
                 # sessions whose envelope sender is FROM
  python3 -I -c 'import json, sys
t = json.load(open(sys.argv[1])) if sys.argv[1] else []
w = set(x for x in sys.argv[3].split(",") if x)
r = [x for se in t for x in se.get("rcpt_to", [])]
print("sessions=%d rcpt=%d ours=%d from=%d" % (len(t), len(r), sum(1 for x in r if x in w), sum(1 for se in t if se.get("mail_from") == sys.argv[2])))' \
    "$( [ -f "$RELAY104/transcript.json" ] && echo "$RELAY104/transcript.json")" "$1" "$2" 2>/dev/null || echo unread
}
python3 -I - "$U99/rh99-ucrm.php" <<'PY' || echo "ucrm104:mail" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]; s = open(p, encoding='utf-8').read()
old = "if (preg_match('#^/clients/([0-9]+)$#', $p, $m)) {\n"
assert s.count(old) == 1, s.count(old)
assert s.count('rh104') == 0
add = r'''// 5.18.104's rehearsal (an asserted insertion before the stand-in's own client answer): the client and the quotation PDF
// of the rehearsal's quotation e-mails, only while rh104-mail.json is beside this file — {"clients":{"<id>":{"first":…,
// "last":…,"email":…}},"pdf":[<quote id>,…]}, every name and address an RH104SECRET- value; a client it names answered as
// uCRM does (its billing contact the e-mail), and GET quotes/<id>/pdf (or billing/quotes/<id>/pdf) answered with a small
// PDF; anything else falls through to the answers below.
if ($p === '/rh104/ping') $out(['rh104' => 'mail-ready']);
$rh104 = is_file($dir . '/rh104-mail.json') ? json_decode((string)@file_get_contents($dir . '/rh104-mail.json'), true) : null;
if (is_array($rh104)) {
    if (preg_match('#^/clients/([0-9]+)$#', $p, $m104) && is_array($rh104['clients'][$m104[1]] ?? null)) {
        $c104 = $rh104['clients'][$m104[1]];
        $out(['id' => (int)$m104[1], 'organizationId' => null, 'firstName' => (string)($c104['first'] ?? ''), 'lastName' => (string)($c104['last'] ?? ''),
              'contacts' => [['isBilling' => true, 'email' => (string)($c104['email'] ?? ''), 'phone' => '']]]);
    }
    if (preg_match('#^/(?:billing/)?quotes/([0-9]+)/pdf$#', $p, $m104) && in_array((int)$m104[1], array_map('intval', (array)($rh104['pdf'] ?? [])), true)) {
        http_response_code(200); header('Content-Type: application/pdf');
        echo "%PDF-1.4\n" . str_repeat("% the rehearsal's quotation PDF, no content\n", 4) . "%%EOF\n"; exit;
    }
}
'''
s = s.replace(old, add + old, 1)
assert s.count(old) == 1 and s.count("'/rh104/ping'") == 1 and s.count('rh104-mail.json') == 3
open(p, 'w', encoding='utf-8').write(s)
PY
for i in $(seq 1 60); do [ "$(curl -s --noproxy '*' -H "X-Auth-App-Key: $APPKEY99" "$U99_URL/api/v2.1/rh104/ping" 2>/dev/null)" = '{"rh104":"mail-ready"}' ] && break; sleep 0.1; done   # PHP re-reads the edited router
RH104MAIL='{"clients":{"104921":{"first":"RH104SECRET-Akello","last":"","email":"RH104SECRET-akello@example.test"},"104922":{"first":"RH104SECRET-Mugisha","last":"","email":"RH104SECRET-mugisha@example.test"}},"pdf":[104901,104902]}'
u104pdf() { curl -s --noproxy '*' -H "X-Auth-App-Key: $APPKEY99" "$U99_URL/api/v2.1/quotes/$1/pdf" 2>/dev/null | head -c 8; }
: > "$U99/requests.log"
UM0_104="$(ug102 -H "X-Auth-App-Key: $APPKEY99" "$U99_URL/api/v2.1/clients/104921")|$(ug102 -H "X-Auth-App-Key: $APPKEY99" "$U99_URL/api/v2.1/quotes/104901/pdf")"
( umask 077; printf '%s\n' "$RH104MAIL" > "$U99/rh104-mail.json" )
UM1_104="$(ug102 -H "X-Auth-App-Key: $APPKEY99" "$U99_URL/api/v2.1/clients/104921")|$(ug102 -H "X-Auth-App-Key: $APPKEY99" "$U99_URL/api/v2.1/quotes/104901/pdf")|$(u104pdf 104901)|$(ug102 -H "X-Auth-App-Key: $APPKEY99" "$U99_URL/api/v2.1/quotes/104909/pdf")|$(ug102 -H "X-Auth-App-Key: rh104-wrong-key" "$U99_URL/api/v2.1/quotes/104901/pdf")"
rm -f "$U99/rh104-mail.json"
check "$UM0_104|$UM1_104|$(ug102 -H "X-Auth-App-Key: $APPKEY99" "$U99_URL/api/v2.1/quotes/104901/pdf")|$(python3 "$H99/rh99.py" u99other "$U99/requests.log")|$(grep -c ' /quotes/104901/pdf app-key$' "$U99/requests.log")|$(u99_state_102)|$(grep -lF 'RH104SECRET' "$U99/requests.log" | grep -c .)" \
      "404|404|200|200|%PDF-1.4|404|401|404|-|5|base|0" \
  "control: the stand-in uCRM answers this rehearsal's quotation client and PDF only while its file is there (before and after: 404), an unknown quote 404 and a wrong key 401 even then; every request a GET, recorded by path and key (never a name or an address), its state its base"
: > "$U99/requests.log"
report104() {   # report104 — the RELEASE's own report (the pin's tools/sales_followup_report.php from Git) on a scratch copy of the
                # book, --json, the settings copies and the vault copy beside it as the wrapper runs it: "h30=<n>/<d> h90=<n>/<d>"
                # (its headline: quotes neither answered nor chased within 7 days, of all, over 30 and 90 days), or none(<why>)
  local root dir o
  root="$(refroot104)"; dir="$(scr104)"
  o="$(cd "$dir" && DN_VAULT_FILE="$H104/v/ref-vault.json" TMPDIR="$dir" "$PHPBIN" -d log_errors=0 -d opcache.enable_cli=0 "$root/tools/sales_followup_report.php" "--db=$dir/plugin.sqlite3" "--data=$dir" --json 2>"$H104/v/ref.err")"
  rm -rf "$dir" "$root"
  python3 -I -c 'import json, sys; h = json.loads(sys.stdin.read())["headline"]; print("h30=%d/%d h90=%d/%d" % (h["30"][0], h["30"][1], h["90"][0], h["90"][1]))' <<<"$o" 2>/dev/null \
    || echo "none($(head -c 160 "$H104/v/ref.err"))"
}
QSE104="$REF104/$P/lib/QuoteSendEvidence.php"   # the pin's evidence rules, read as text by this rehearsal's own reader (its EVENTS, its MARKERS)
qdelta104() {   # qdelta104 BEFORE AFTER — two h30=<n>/<d> h90=<n>/<d> lines: what was added, in the same form
  python3 -I -c 'import re, sys
f = lambda s, k: [int(x) for x in re.search(k + r"=([0-9]+)/([0-9]+)", s).groups()]
a, b = sys.argv[1], sys.argv[2]
print(" ".join("%s=%d/%d" % (k, f(b, k)[0] - f(a, k)[0], f(b, k)[1] - f(a, k)[1]) for k in ("h30", "h90")))' "$1" "$2" 2>/dev/null || echo unread
}
sentby104() {   # sentby104 MAIL-LINE — of a mail reading's path 3 rows by status, the sent ones (0 when none)
  python3 -I -c 'import re, sys
m = re.search(r"(?:^| )p3_by_status=([^ ]*)", sys.argv[1])
d = dict(x.rsplit(":", 1) for x in (m.group(1).split(",") if m and m.group(1) not in ("", "-") else []))
print(d.get("sent", "0"))' "$1" 2>/dev/null || echo unread
}
# ── The fixtures: fictitious and this rehearsal's own — staff by first name only, customers' names, every e-mail an
# RH104SECRET- value, every phone a number of its own range (+2567001040NN, which rh104-seed.php refuses to leave). ──────
STAFF104_OPS='[{"op":"staff","name":"RH104SECRET-Amina","role":"sales","phone":"+256700104011","email":"RH104SECRET-amina@example.test","admin":false,"active":true},
{"op":"staff","name":"RH104SECRET-Brian","role":"sales","phone":"+256700104012","email":"RH104SECRET-brian@example.test","admin":false,"active":true},
{"op":"staff","name":"RH104SECRET-Grace","role":"admin","phone":"+256700104013","email":"RH104SECRET-grace@example.test","admin":true,"active":true},
{"op":"staff","name":"RH104SECRET-Daniel","role":"sales","phone":"+256700104014","email":"RH104SECRET-daniel@example.test","admin":false,"active":false},
{"op":"staff","name":"RH104SECRET-Esther","role":"support","phone":null,"email":"RH104SECRET-esther@example.test","admin":false,"active":true}]'
CUST104_OPS='[{"op":"conv","phone":"+256700104021","channel":"sales","name":"RH104SECRET-Okello","msgs":[{"kind":"text","text":"RH104SECRET-hello-kit","ago_min":95},{"kind":"audio","ago_min":85}]},
{"op":"conv","phone":"+256700104022","channel":"support","name":"RH104SECRET-Nakato","msgs":[{"kind":"audio","ago_min":55}]},
{"op":"enquiry","phone":"+256700104021","channel":"sales","body":"RH104SECRET-draft-okello","by":"RH104SECRET-Grace"}]'
# The quotations (QF-N3's and AC-N2's): three sent on WhatsApp 10, 20 and 45 days before, each after its customer's own
# question (spoke-to), each valid 7 days (so that, whenever 5.18.104's tracker first reads them, they are expired: never
# chased, never on a daily list — backfilled, sent before quote_tracking_since); two of them e-mailed, path 1 and path 3.
# The e-mail ops' "mailer" is filled in at the run (@MAILER@: the relay's settings folder).
QUOTE104_OPS='[{"op":"quote","phone":"+256700104027","channel":"sales","name":"RH104SECRET-Akello","ref":"RH104SECRET-Q0-10","in_text":"RH104SECRET-a quotation for the kit please","agent":"RH104SECRET-Amina","ago_min":14400,"gap_min":5,"valid_days":7},
{"op":"quote","phone":"+256700104028","channel":"sales","name":"RH104SECRET-Mugisha","ref":"RH104SECRET-Q0-20","in_text":"RH104SECRET-how much is the kit","agent":"RH104SECRET-Brian","ago_min":28800,"gap_min":5,"valid_days":7},
{"op":"quote","phone":"+256700104029","channel":"support","name":"RH104SECRET-Namuli","ref":"RH104SECRET-Q0-45","in_text":"RH104SECRET-the price please","agent":"RH104SECRET-Amina","ago_min":64800,"gap_min":5,"valid_days":7},
{"op":"quote_mail","path":"app","quote_id":104901,"client_id":104921,"number":"RH104SECRET-Q0-10","name":"RH104SECRET-Akello","mailer":"@MAILER@"},
{"op":"quote_mail","path":"webhook","quote_id":104902,"client_id":104922,"number":"RH104SECRET-Q0-20","name":"RH104SECRET-Mugisha","mailer":"@MAILER@"}]'
MAILFROM104="RH104SECRET-quotes@example.test"; MAILTO104="RH104SECRET-akello@example.test,RH104SECRET-mugisha@example.test"   # the relay's sender, the two clients' addresses (RH104MAIL's)
NAMES104="$SB/names104.pat"; { printf '%s\n' RH104SECRET-; python3 "$H104/rh104q.py" phones "$STAFF104_OPS" "$CUST104_OPS" "$QUOTE104_OPS";
  python3 -I -c 'import json, sys; [print(x) for x in sorted(set(str(o.get(k)) for o in json.loads(sys.argv[1]) for k in ("ref", "number") if o.get(k)))]' "$QUOTE104_OPS";
  tr ',' '\n' <<<"$MAILTO104"; printf '%s\n' "$MAILFROM104"; } > "$NAMES104"; chmod 600 "$NAMES104"

echo; echo "== 104-pre. production's state on 4 Nov: 5.18.103 deployed, the uCRM catalogue's switch on by an admin, its --after-only and the quarantine's --check passed; the fixtures 5.18.104's notes count =="
check "$(live)|$(inst_ver)|$(set99 ucrm_quote_organization_id)" "$P102|5.18.102|unset/unset" \
  "pre-a the sandbox as 5.18.103's rehearsal left it: 5.18.102 serving (its 8e rolled back), the quoting organization unset in both copies — the Uganda profile's #1 in force"
fresh103; OUT="$(run103 --answer DEPLOY --redeploy)"; OUT103P="$OUT"
check "$(fails "$OUT")$(has "$OUT" '5.18.103 (deploy): PASSED')$(live)$(installed103 "$P103")$(inst_ver)|$(noteline "$OUT" QC-N1 'THE uCRM CATALOGUE IS SWITCHED ON in this book'"'"'s store')" "0yes${P103}05.18.103|yes" \
  "pre-b 5.18.103 deployed again, --redeploy, DEPLOY typed: PASSED — $P103 serving, every file as its pin has it; production's was a first deploy (3 Nov, copy 09:01:02 UTC), this sandbox's 103-8 left 5.18.103 rolled back, so --redeploy, as 103-pre redeployed 5.18.102 for the same reason; QC-N1 finds the switch 103-5's admin turned on"
# ── The admin's act of 4 Nov, through the INSTALLED 5.18.103 writers (rh103-cat.php), as the uCRM catalogue screen's post
# handler calls setMode() with the installation it judged: on this sandbox's store the switch is on already (103-5's
# admin, kept by every rollback since: QC-RB1 leaves the table in place), so the writer answers "already" and writes no
# record — production's state, an admin's switch in force, either way. ─────────────────────────────────────────────────
INST104="$(python3 -c 'import json, sys; print([o["product"] for o in json.loads(sys.argv[1]) if o.get("op") == "install"][0])' "$CATON103")"
CATB104="$(python3 "$H103/rh103q.py" cat "$DATA/plugin.sqlite3")"
CATA104="$(cat103 "$PD" '[{"op":"mode","on":true,"judged":'"$INST104"',"actor":'"${ADMIN102:-0}"'}]')"
check "$CATA104|$(python3 "$H103/rh103q.py" cat "$DATA/plugin.sqlite3")" \
      '{"ops":1,"kinds":["already"],"ids":[],"records":'"$(sed -nE 's/^records:([0-9]+),.*/\1/p' <<<"$CATB104")"',"inforce":'"$(sed -nE 's/^records:[0-9]+,inforce:([0-9]+).*/\1/p' <<<"$CATB104")"',"switch":"on"}|'"$CATB104" \
  "pre-c an admin turns the uCRM catalogue on through the installed 5.18.103 writers, judging the installation set: the switch is on, its record in force — the writer says already and the store's catalogue reads as before"
check "$(sed -n 's/.*|switch:\([a-z]*\)|install:\([a-z]*\)|std:\([0-9]*\).*/\1:\2:\3/p' <<<"$CATB104" | awk -F: '{print $1 ":" $2 ":" ($3 >= 1 ? "std" : "nostd")}')" "on:set:std" \
  "…control: this rehearsal's own reading of the table — the switch on, the installation set, at least one standard quotation: production's three saves and switch, as on 4 Nov"
after_back103; ALINE104P="$(printed "$OUT103P" "cd $REPO && bash scripts/deploy-5.18.103.sh --after-only")"
G7N104="$(qkv103 "$(q103 apps)" g7 | sed -nE 's/^direct:([0-9]+),.*/\1/p')"
fresh103; OUT="$(run_line103 '' "$ALINE104P")"
check "$ALINE104P|$(fails "$OUT")$(has "$OUT" '5.18.103 (after): PASSED')$(okline "$OUT" R19 'the quarantine holds: master ran the retry job at')$(okline "$OUT" QC-ON1 'switched ON')$(okline "$OUT" QC-ON2 'is set and passes')$(okline "$OUT" QC-ON3 'is usable now')$(okline "$OUT" QC-ON4 "($G7N104) keeps G-7")" \
      "$EXP_AFTER103|0yesyesyesyesyesyes" \
  "pre-d its --after-only, as its summary printed it, the copy half an hour back: PASSED — R19 conclusive, QC-ON1–QC-ON4 ok over the switch on and the $G7N104 web-direct application(s) this rehearsal's own reading counts, as on 4 Nov (124 ok there)"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "pre-e the quarantine's own --check after it, as the runbook orders: PASSED — A14 holds for 5.18.104"
[ -e "$RECS/dnb-5.18.103" ] || ln -s "$OUT103" "$RECS/dnb-5.18.103"
check "$(readlink -f "$RECS/dnb-5.18.103")|$(readlink -f "$RECS/dnb-5.18.102")|$(readlink -f "$RECS/dnb-5.18.101")|$(readlink -f "$RECS/dnb-5.18.100")|$(readlink -f "$RECS/dnb-5.18.99")|$( [ "$(grep -c '^COPY_AT=[0-9][0-9]*$' "$STATE103")" -ge 1 ] && echo copy)|$(grep -c '^STRAY_Q_FAILED=' "$STATE103")" \
      "$(readlink -f "$OUT103")|$(readlink -f "$OUT102")|$(readlink -f "$OUT101")|$(readlink -f "$OUT100")|$(readlink -f "$OUT99")|copy|0" \
  "pre-f 5.18.103's records where 5.18.104's A14 looks for them (<records>/dnb-5.18.103), beside the earlier releases', as on the server; its state file holds its copy's time and no failure"
# ── The fixtures 5.18.104's notes count, through the INSTALLED 5.18.103 writers (rh104-seed.php), each read back by this
# rehearsal's own reading of the store (rh104q.py), before and after, against what the ops add by the same rules. ──────
B104_STAFF="$(q104 staffraw)"; B104_N7="$(q104 n7)"; B104_G5="$(q104 g5)"; B104_N2="$(q104 n2)"
SEEDS104="$(seed104 "$PD" "$STAFF104_OPS")"; SEEDC104="$(seed104 "$PD" "$CUST104_OPS")"
X104="$(python3 "$H104/rh104q.py" expect "$STAFF104_OPS" "$CUST104_OPS")"
A104_STAFF="$(q104 staffraw)"; A104_N7="$(q104 n7)"; A104_G5="$(q104 g5)"; A104_N2="$(q104 n2)"
d104() { echo $(( $(n103 "$(kv104 "$2" "$3")") - $(n103 "$(kv104 "$1" "$3")") )); }   # d104 BEFORE AFTER KEY — the change of one count
check "$(python3 -c 'import json, sys; a = json.loads(sys.argv[1]); b = json.loads(sys.argv[2]); print("%d/%d|%d/%d|%s" % (a["ops"], len(json.loads(sys.argv[3])), b["ops"], len(json.loads(sys.argv[4])), "ids" if all(d.get("id", 0) > 0 for d in a["done"] + b["done"]) and all(m > 0 for d in b["done"] for m in d.get("messages", [])) else "missing"))' "$SEEDS104" "$SEEDC104" "$STAFF104_OPS" "$CUST104_OPS" 2>/dev/null || echo "unread:${SEEDS104:0:80}|${SEEDC104:0:80}")" \
      "$(python3 -c 'import json, sys; print("%d/%d|%d/%d|ids" % ((len(json.loads(sys.argv[1])),) * 2 + (len(json.loads(sys.argv[2])),) * 2))' "$STAFF104_OPS" "$CUST104_OPS")" \
  "pre-g the fixtures written through 5.18.103's own writers — the staff accounts (appendWithId, as the staff screen keeps them), the customers' conversations and their inbound messages (ConversationService, WaInbound as the webhook's processor stores them) and an enquiry follow-up drafted and approved (FollowUpService): every op done, every row a new id"
check "$(d104 "$B104_STAFF" "$A104_STAFF" active)|$(d104 "$B104_STAFF" "$A104_STAFF" phone)|$(d104 "$B104_STAFF" "$A104_STAFF" admins_with_phone)|$A104_N7|$(d104 "$B104_G5" "$A104_G5" audio)|$(d104 "$B104_N2" "$A104_N2" open)|$(python3 -c 'import sys, re; f = lambda s: int(re.search(r"<1d:([0-9]+)", s).group(1)) if re.search(r"<1d:([0-9]+)", s) else -1; print(f(sys.argv[2]) - f(sys.argv[1]))' "$B104_N2" "$A104_N2")" \
      "$(kv104 "$X104" active)|$(kv104 "$X104" phone)|$(kv104 "$X104" admins_with_phone)|$(python3 "$H104/rh104q.py" merge "$B104_N7" "$(kv104 "$X104" n7)")|$(kv104 "$X104" audio)|$(kv104 "$X104" enquiries)|$(kv104 "$X104" enquiries)" \
  "…this rehearsal's own reading of the store counts what they add: the active accounts, those with a phone and the admins with one, the accounts with a phone by role (QF-N7's form), the inbound voice notes (VN-G5's [AUDIO] rows), the open follow-ups and the approved drafts of under a day (QF-N2's) — the positive controls of the notes 104-c reads"
# ── The quotations, through 5.18.103's own writers (QF-N3's and AC-N2's fixtures, QUOTE104_OPS): three sent on WhatsApp, as
# the Send Quotation path stores its text, and two of them e-mailed to the fake relay — path 1 through the installed
# QuotationService::emailQuotePdf (its own seam for tests given the relay's settings), path 3 as webhook.php's
# whQuotationEmail sends through the installed CustomerEmailDispatcher. Read before and after by this rehearsal's own readers
# (quotes, mail: what the ops ADD, against what they should add by the same rules — qexpect) and, for the headline, by the
# RELEASE's own report on a scratch copy, before and after: what 104-c's QF-N3 and AC-N2 are set against. ──────────────
QB104="$(q104 quotes "$DATA/plugin.sqlite3" "$QSE104")"; MB104="$(q104 mail "$DATA/plugin.sqlite3" "$DATA/quote_mail.log")"; RB104="$(report104)"
mkdir -p "$MAILER104"; chown 1000:1000 "$MAILER104"; chmod 700 "$MAILER104"
relay_up104
printf '{"use_ucrm_email":false,"smtp_host":"127.0.0.1","smtp_port":%s,"smtp_user":"","smtp_pass":"","smtp_enc":"none","smtp_from":"%s"}\n' "$RPORT104" "$MAILFROM104" > "$MAILER104/email_settings.json"
chown 1000:1000 "$MAILER104/email_settings.json"; chmod 600 "$MAILER104/email_settings.json"
( umask 077; printf '%s\n' "$RH104MAIL" > "$U99/rh104-mail.json" ); : > "$U99/requests.log"
EL0_104="$( [ -e "$DATA/email_settings.json" ] && echo set || echo unset)"
SEEDQ104="$(seed104 "$PD" "${QUOTE104_OPS//@MAILER@/$MAILER104}")"
RN104="$(relay_n104 "$MAILFROM104" "$MAILTO104")"; UQ104="$(python3 "$H99/rh99.py" u99other "$U99/requests.log")|$(grep -c ' /quotes/10490[12]/pdf app-key$' "$U99/requests.log")"
relay_down104; rm -f "$U99/rh104-mail.json"; rm -rf "$MAILER104"; : > "$U99/requests.log"
QA104="$(q104 quotes "$DATA/plugin.sqlite3" "$QSE104")"; MA104="$(q104 mail "$DATA/plugin.sqlite3" "$DATA/quote_mail.log")"; RA104="$(report104)"
QX104="$(python3 "$H104/rh104q.py" qexpect "$QUOTE104_OPS")"
check "$(python3 -I -c 'import json, sys; a = json.loads(sys.argv[1]); d = a["done"]; print("%d/%d|%s|%d" % (a["ops"], len(json.loads(sys.argv[2])), "ids" if all(x.get("id", 0) > 0 for x in d) and all(m > 0 for x in d for m in x.get("messages", [])) else "missing", sum(1 for x in d if x.get("op") == "quote_mail" and x.get("sent") is True)))' "$SEEDQ104" "$QUOTE104_OPS" 2>/dev/null || echo "unread:${SEEDQ104:0:120}")|$RN104|$UQ104|$EL0_104>$( [ -e "$DATA/email_settings.json" ] && echo set || echo unset)" \
      "$(python3 -I -c 'import json, sys; o = json.loads(sys.argv[1]); print("%d/%d|ids|%d" % (len(o), len(o), sum(1 for x in o if x["op"] == "quote_mail")))' "$QUOTE104_OPS")|sessions=$(kv104 "$QX104" p1_sent | awk -v w="$(kv104 "$QX104" p3_sent)" '{print $1 + w}') rcpt=$(tr ',' '\n' <<<"$MAILTO104" | grep -c .) ours=$(tr ',' '\n' <<<"$MAILTO104" | grep -c .) from=$(kv104 "$QX104" p1_sent | awk -v w="$(kv104 "$QX104" p3_sent)" '{print $1 + w}')|-|$(kv104 "$QX104" p1_sent | awk -v w="$(kv104 "$QX104" p3_sent)" '{print $1 + w}')|unset>unset" \
  "pre-q the quotations through 5.18.103's own writers: every op done, every message a new id, both e-mails sent — the relay handed exactly one message per e-mail, each to its client's address, from the relay's sender; the stand-in uCRM asked GETs only, each e-mail's PDF once; the live data folder still has no plugin mail of its own (the relay's settings were the op's alone)"
check "$(qdelta104 "$QB104" "$QA104")|$(qdelta104 "$RB104" "$RA104")|$(( $(n103 "$(kv104 "$MA104" p1_sent)") - $(n103 "$(kv104 "$MB104" p1_sent)") ))|$(( $(n103 "$(sentby104 "$MA104")") - $(n103 "$(sentby104 "$MB104")") ))|$(( $(n103 "$(kv104 "$MA104" p3_sent_none)") - $(n103 "$(kv104 "$MB104" p3_sent_none)") ))|$(kv104 "$MA104" p1)/$(kv104 "$MA104" p3)" \
      "$(kv104 "$QX104" h30 | sed 's/^/h30=/') $(kv104 "$QX104" h90 | sed 's/^/h90=/')|$(kv104 "$QX104" h30 | sed 's/^/h30=/') $(kv104 "$QX104" h90 | sed 's/^/h90=/')|$(kv104 "$QX104" p1_sent)|$(kv104 "$QX104" p3_sent)|$(kv104 "$QX104" p3_none)|read/read" \
  "…what they add, by this rehearsal's own readers and by the RELEASE's own report on scratch copies before and after alike: the headline's quotes neither answered nor chased, of all, over 30 days (the 10- and 20-day ones) and 90 days (the 45-day one too); one sent line in quote_mail.log (path 1), one customer_email_log quotation row sent (path 3), its quote id held by no record — the positive controls of QF-N3 and AC-N2 (104-c)"
# The wrapper, while 5.18.103 serves: it finds no installed tool and refuses before it makes its log folder or runs anything
# (DS1's rehearsal need: discovery) — the positive control is the tool's own file, absent from the installed tree and
# present in the pin's; and, for the log folder, 4g's, where the wrapper's first run that passes its refusals makes it
# (mode 700) with its one log (mode 0600) in it. The folder is this rehearsal's (DNB_SALES_LOG_DIR); nothing has made it yet.
SFB104="$( [ -e "$SALES104" ] && echo made || echo absent)"
SFO104="$(sf104 show)"; SFRC104="$(tail -n1 "$R104/sf.rc" 2>/dev/null)"
check "$SFRC104|$(has "$SFO104" "sales-followup: REFUSED — the installed plugin has no tools/sales_followup.php — 5.18.104 is not installed in the 'ucrm' container. Nothing was changed.")|$SFB104>$( [ -e "$SALES104" ] && echo made || echo absent):$(ls -A "$SALES104" 2>/dev/null | grep -c .)|$( [ -e "$PD/tools/sales_followup.php" ] && echo installed || echo absent):$(git -C "$REPO" cat-file -e "$P104:$P/tools/sales_followup.php" 2>/dev/null && echo pinned)" \
      "1|yes|absent>absent:0|absent:pinned" \
  "pre-i scripts/sales-followup.sh, as the operator runs it, while 5.18.103 serves: REFUSED (exit 1) — no installed tool — before it made its log folder (absent before and after: no log written); the tool absent from the installed tree, in the pin (the control on the folder: 4g)"
D104="$(data94)"; KEPT104="$(kept_state)"; T0_104="$(tables104 | cut -d' ' -f1-2)"
check "$T0_104|$(tables104 | cut -d' ' -f3)|$(mig104 | cut -d' ' -f1)|$(cache104)|$(installed104 "$P103")|$(left104)" "t104=0/$(tr ',' '\n' <<<"$TBL104" | grep -c .) cols=0/$(tr ',' '\n' <<<"$COL104" | grep -c .)|control=2/2|mig=0/$(tr ',' '\n' <<<"$MIGF104" | grep -c .)|0|0|0" \
  "pre-h on the live book none of 089–091's tables ($TBL104) or followups' columns ($COL104), as Git's migrations name them, and none of the three in its ledger — the control: followups and wa_messages read; no page cache; every file 5.18.104 changes as 5.18.103 has it, none of those it adds on disk"

echo; echo "== 104-0. what is rehearsed: 5.18.104 — the controls =="
echo "  the script     $WHICH104"
check "$P104|$P104F" "$(git -C "$REPO" rev-parse --short "origin/$REL104" 2>/dev/null || echo none)|$(git -C "$REPO" rev-parse "origin/$REL104" 2>/dev/null || echo none)" "the script is pinned to the release commit on $REL104, short and in full ($P104)"
check "$(git -C "$REPO" rev-parse --short "$P104^" 2>/dev/null)|$(git -C "$REPO" rev-parse "$P104^" 2>/dev/null)|$(ver "$P104")$(ver "$P103")" "$P103|$(sv104 "" BASELINE_COMMIT_FULL)|5.18.1045.18.103" \
  "…cut on 5.18.103 ($P103), the script's baseline in full; the pin says 5.18.104, its parent 5.18.103"
# Git's short form follows the object count (8 characters from 16,384 packed objects: root docs/07 5.18.103 known limit 11,
# the coordinator's measurement of 7 Nov): every chained check that compares a short form with a 7-character pin depends on
# the clone staying under it — measured here, never assumed.
OBJ104="$(git -C "$REPO" count-objects -v | sed -n 's/^in-pack: //p')"
check "$(git -C "$REPO" rev-parse --short "$P104F")|$( [ "$(n103 "$OBJ104")" -ge 1 ] && [ "$(n103 "$OBJ104")" -lt 16384 ] && echo under || echo "over:${OBJ104:-?}")|$(git -C "$REPO" log -1 --format=%h "$P104F" -- "$P")" "$P104|under|$P104" \
  "control: the clone writes the release commit's short form in the pin's 7 characters ($P104), its packed objects ($OBJ104) under Git's 16,384 — the form scripts/deploy-hybrid.sh records"
check "$(dl D "$P103" "$P104" | grep -c .)|$(dl A "$P103" "$P104" | grep -c .)|$(dl AM "$P103" "$P104" | grep -c .)|$(git -C "$REPO" diff --no-renames --name-only --diff-filter=CRTUXB "$P103" "$P104" | grep -c .)|$(git -C "$REPO" diff --name-only "$P103" "$P104" | grep -vc "^$P/")" "0|$NA104|$N104|0|0" \
  "the delta: $N104 files under the plugin, $NA104 of them added, none deleted, renamed or retyped, nothing outside it"
check "$(dl A "$P103" "$P104" | sed "s#^$P/##" | LC_ALL=C sort | paste -sd' ' -)|$(dl M "$P103" "$P104" | sed "s#^$P/##" | LC_ALL=C sort | paste -sd' ' -)" \
      "$(tr ' ' '\n' <<<"$ADDED104" | LC_ALL=C sort | paste -sd' ' -)|$(tr ' ' '\n' <<<"$CHANGED104" | LC_ALL=C sort | paste -sd' ' -)" "…exactly the reviewed files, added and changed, as the script lists them"
PS104="$(sed -n '/^PIN_SHA256S="$(cat <<.SUMS.$/,/^SUMS$/p' "$DEPLOY104" | grep -E '^[0-9a-f]{64}  ' | while read -r s f; do [ "$(git -C "$REPO" show "$P104:$P/$f" | sha256sum | cut -c1-64)" = "$s" ] && echo ok || echo "bad:$f"; done | sort | uniq -c | tr -s ' ' | paste -sd, -)"
KS104="$(sed -n '/^PREV_KEPT_SHA256S="$(cat <<.SUMS.$/,/^SUMS$/p' "$DEPLOY104" | grep -E '^[0-9a-f]{64}  ' | while read -r s f; do a="$(git -C "$REPO" show "$P103:$P/$f" | sha256sum | cut -c1-64)"; b="$(git -C "$REPO" show "$P104:$P/$f" | sha256sum | cut -c1-64)"; [ "$a|$b" = "$s|$s" ] && echo ok || echo "bad:$f"; done | sort | uniq -c | tr -s ' ' | paste -sd, -)"
PC104="$(sed -n '/^PREV_CHANGED_SHA256S="$(cat <<.SUMS.$/,/^SUMS$/p' "$DEPLOY104" | grep -E '^[0-9a-f]{64}  ' | while read -r s f; do [ "$(git -C "$REPO" show "$P103:$P/$f" | sha256sum | cut -c1-64)" = "$s" ] && echo ok || echo "bad:$f"; done | sort | uniq -c | tr -s ' ' | paste -sd, -)"
KN104="$(sed -n 's/^PREV_KEPT_N=\([0-9]*\)$/\1/p' "$DEPLOY104")"
check "$PS104|$KS104|$PC104|$(sed -n 's/^PIN_SHA_N=\([0-9]*\)$/\1/p' "$DEPLOY104"),$(sed -n 's/^PREV_CHANGED_N=\([0-9]*\)$/\1/p' "$DEPLOY104")" " $N104 ok| $KN104 ok| $NC104 ok|$N104,$NC104" \
  "every sha256 the script pins, computed here from Git: the $N104 release files at the pin, the $KN104 kept ones in both commits, the $NC104 changed ones at 5.18.103 — and the counts it states"
KEEPL104="$(sed -n '/^PREV_KEPT_SHA256S="$(cat <<.SUMS.$/,/^SUMS$/p' "$DEPLOY104")"
KEEPW104="tools/set_config.php lib/KycService.php lib/UcrmCatalogue.php lib/QuoteOrganization.php"   # C1's tool byte for byte; 5.18.103's copy mark, its catalogue and the quoting organization, which 5.18.104 keeps
check "$(for f in $KEEPW104; do printf '%s:%s ' "$( [ "$(git -C "$REPO" rev-parse "$P103:$P/$f")" = "$(git -C "$REPO" rev-parse "$P104:$P/$f")" ] && echo same || echo differs)" "$(grep -cE "  ${f//./\\.}\$" <<<"$KEEPL104")"; done)|$(for f in $ADDED104 $CHANGED104; do grep -cE "  ${f//./\\.}\$" <<<"$KEEPL104"; done | sort -u | paste -sd, -)" \
      "$(for f in $KEEPW104; do printf 'same:1 '; done)|0" \
  "…the kept files hold tools/set_config.php (C1: byte for byte), lib/KycService.php, lib/UcrmCatalogue.php and lib/QuoteOrganization.php once each, Git saying each the same at 5.18.103 and the pin — and none of the files the release adds or changes"
# The three migrations: added, each by the sha256 and md5 the script pins (MIG104_SUMS — the md5 the ledger's checksum),
# MIG104_N and MIG104_LAST its own words for them, QF_SC_EXPECT's counts each migration's own _schema_checks INSERT's;
# no migration of an earlier release changed.
MIGS104="$(sed -n "/^MIG104_SUMS=\"\$(cat <<'SUMS'\$/,/^SUMS\$/p" "$DEPLOY104" | grep -E '^[0-9a-f]{64} [0-9a-f]{32}  migrations/')"
check "$(while read -r s m f; do [ "$(git -C "$REPO" show "$P104:$P/$f" | sha256sum | cut -c1-64)|$(git -C "$REPO" show "$P104:$P/$f" | md5sum | cut -c1-32)" = "$s|$m" ] && echo "${f#migrations/}" || echo "bad:${f#migrations/}"; done <<<"$MIGS104" | LC_ALL=C sort | paste -sd, -)|$(sv104 "" MIG104_N)|$(sv104 "" MIG104_LAST)|$(dl M "$P103" "$P104" | grep -c "^$P/migrations/")|$(for m in $(tr ',' ' ' <<<"$MIGF104"); do git -C "$REPO" show "$P104:$P/migrations/$m" | python3 "$H104/rh104q.py" scexp; done | paste -sd' ' -)" \
      "$MIGF104|$(tr ',' '\n' <<<"$MIGF104" | grep -c .)|$(tr ',' '\n' <<<"$MIGF104" | tail -1)|0|$(sv104 "" QF_SC_EXPECT)" \
  "migrations: exactly the ones Git adds ($MIGF104), each by its pinned sha256 and md5; MIG104_N and MIG104_LAST the script's own words for them; none changed; QF_SC_EXPECT's counts as each migration's own _schema_checks INSERT states them"
check "$(sed -n 's/^COPY_MARK="\([^"]*\)".*/\1/p' "$DEPLOY104")|$(grep -cxF "$P/$(sed -n 's/^COPY_MARK="\([^"]*\)".*/\1/p' "$DEPLOY104")" <<<"$(dl M "$P103" "$P104")")|$(case "$(sv104 "" T1_SIGNATURE)" in [1-9]*' passed, 0 failed rc=0') echo pinned ;; *) echo unpinned ;; esac)" \
      "lib/NotificationService.php|1|pinned" \
  "the copy's time is read off a file this release changes (COPY_MARK, lib/NotificationService.php — 5.18.103's lib/KycService.php is kept, never touched by stamp_copy); T1's count pinned, carried as A22-T1"
H104B="$(sed -n '/^# The deploy\. Run as root/,/^# The rollback is a separate command/p' "$DEPLOY104")"
H104H="$(sed -n '1,/^set -uo pipefail$/p' "$DEPLOY104")"
HC104=( '#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.104 && mkdir -p /root/dnb-5.18.104 && bash scripts/deploy-5.18.104.sh 2>&1 | ( umask 077; exec tee /root/dnb-5.18.104/deploy-$(date -u +%Y%m%dT%H%M%SZ).log )'
        '#   cd /opt/dishnet && bash scripts/deploy-5.18.104.sh --after-only 2>&1 | ( umask 077; exec tee /root/dnb-5.18.104/after-$(date -u +%Y%m%dT%H%M%SZ).log )'
        '#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && mkdir -p /root/dnb-report && bash scripts/sales-followup-report.sh 2>&1 | ( umask 077; exec tee /root/dnb-report/report-$(date -u +%Y%m%dT%H%M%SZ).log )'
        '#   cd /opt/dishnet && bash scripts/sales-followup.sh show' )
check "$(for l in "${HC104[@]}"; do grep -cxF -- "$l" <<<"$H104B"; done | paste -sd' ' -)|$(grep -cE -- '--rollback|--put-back|--redeploy|--clear-switches|git checkout [0-9a-f]{7}' <<<"$(grep -E '^#   (cd|\( cd) ' <<<"$H104B")")|$(grep -cE '^#   (cd|\( cd) ' <<<"$H104B")|$(grep -cE '^#   (cd|\( cd) ' <<<"$H104H")|$(grep -cE '^#   (cd|\( cd) .*--(accept-unpriced|rollback)' <<<"$H104H")|$(grep -c '^# The deploy\. Run as root' <<<"$H104H")$(grep -c '^# The rollback is a separate command' <<<"$H104H")" \
      "$(for l in "${HC104[@]}"; do printf '1\n'; done | paste -sd' ' -)|0|${#HC104[@]}|${#HC104[@]}|0|11" \
  "the header's ${#HC104[@]} commands — the deploy, its --after-only, the report and the tool's show — between its two marker lines (each once), each alone on its line, the two runs each with its own log; none a rollback, a put-back, a redeploy or a clear, and no other command in the header"
check "$(grep -cE "^(QL_RB1_MARKS|QL_RB3_MARKS)=|^qlrb_(judge|count)\(\)" "$DEPLOY104")|$(grep -F -- '--rollback-unpriced-pending' "$DEPLOY104" | grep -cvE '^[^#]*#.*--rollback-unpriced-pending')|$(grep -cE '"(QL-RB[123]|QL-LK1|QL-CA1) ' "$DEPLOY104")|$(grep -cE '^    --rollback-unpriced-pending\)' "$DEPLOY104")" "0|0|0|0" \
  "the retired codes stay retired — 5.18.102's QL-RB1–QL-RB3, QL-CA1, QL-LK1 and --rollback-unpriced-pending (docs/72 §G.13): no marker block or reader of theirs, no check line said under their names, the flag only in comments and no option of it; D retires none of 5.18.103's checks"
check "$(bash -n "$DEPLOY104" && echo parses)|$(bash -n "$REPO/scripts/sales-followup.sh" && echo parses)|$(bash -n "$REPO/scripts/sales-followup-report.sh" && echo parses)" "parses|parses|parses" "the script and its two wrappers parse"

echo; echo "== 104-c. --check: stage A alone — GO on 5.18.103 as production has it; every refusal of 5.18.104's passed; its notes =="
SCR104C="$(scr104)"; RK104="$(ref104 keys)"; RC104="$(ref104 conf "" "$SCR104C")"; RS104="$(ref104 staff "" "$SCR104C")"; rm -rf "$SCR104C"
check "$(for k in all settings vswitch vsettings oswitch osettings; do [ "$(n103 "$(kv104 "$RK104" "$k")")" -ge 1 ] && printf y || printf "n:$k"; done)|$(kv104 "$RC104" tenant)|$(kv104 "$RC104" tz)|$(case "$RS104" in staff_active=*) echo read ;; *) echo "${RS104:0:60}" ;; esac)" \
      "yyyyyy|uganda|Africa/Kampala|read" \
  "control: the RELEASE's own reading (rh104-ref.php, the pin's code from Git, on a scratch copy of the book and its settings): its ladder's keys and settings, Part V's and Part O's by its own part column, each at least one; the copy reads as Uganda in Africa/Kampala; its staff read"
FP104="$(fp99)"; fresh104; S104C="$(states104)"; OUT="$(run104 --check)"; TC104="$(last104)"; SH104C="$(ls -1 "$OUT104"/cash-*.txt 2>/dev/null | head -1)"
check "$(checkgo104 "$OUT")|$( [ -e "$OUT104/ql-key-5.18.104" ] && echo key || echo nokey)|$(grep -c '^  STOP: ' <<<"$OUT")|$(grep -cxF "      $(runbook_with104)" <<<"$OUT")|$(grep -c 'deploy-5\.18\.104\.sh --rollback' <<<"$OUT")" "yesyesnono0|0|$S104C|nokey|0|1|0" \
  "c1 --check is GO, never asks, backs nothing up, writes no state file and no QL key file, stops nowhere; it hands over the runbook's deploy, alone on its line with its own log, and no rollback"
MIG88_104="$(sv104 "" MIG88)"; SCX104="$(sv104 "" QF_SC_EXPECT)"; VN090SC104="$(sed -n 's/.*VN_090_SC="\([0-9]*\)".*/\1/p' "$DEPLOY104" | head -1)"
c89_104="$(tr ' ' '\n' <<<"$SCX104" | sed -n 's/^089_quote_followups\.sql://p')"; c91_104="$(tr ' ' '\n' <<<"$SCX104" | sed -n 's/^091_wa_offers\.sql://p')"
TRIAL104="$(sed -n 's/^  migration trial //p' <<<"$OUT" | head -1)"
check "$(has "$OUT" '== A. The sales follow-up (5.18.104, Part F'"'"'s QF; read-only, on copies through the pin'"'"'s code) ==')$(has "$OUT" "  ok    QF-G1 the copy's last migration is ${MIG88_104#migrations/}: no quote_tracker, wa_auto_sends or wa_seen_marks, no followups.kind — a first deploy of 5.18.104; wa_messages read (the positive control)")|$(python3 -c 'import sys; w = [v.split(":")[0] for v in sys.argv[2].split()]; print(",".join(x for x in sys.argv[1].split(",") if x.split(":")[0] in w))' "$(kv104 "$TRIAL104" applied)" "$SCX104")|$(kv104 "$TRIAL104" verdicts)|$(kv104 "$TRIAL104" log)|$(python3 -c 'import sys; a, b = sys.argv[1].split(">"); print("same" if a == b and a.split(":")[0].isdigit() else "differs")' "$(kv104 "$TRIAL104" followups)" 2>/dev/null)|$(grep -c '^  negative control reset=0 cols=24 status=partial partial=1 check=1 row=0$' <<<"$OUT")" \
      "yesyes|$(for v in $SCX104; do printf '%s:ok,' "${v%%:*}"; done | sed 's/,$//')|$(for v in $SCX104; do printf '%s=%s/%s/%s,' "${v%%:*}" "${v##*:}" "${v##*:}" "${v##*:}"; done | sed 's/,$//')|$(for v in $SCX104; do printf '%s=OK,' "${v%%:*}"; done | sed 's/,$//')|same|1" \
  "c2 QF-G1 ok — a first deploy, 088 last ($MIG88_104 as the script names it); QF-G2's one trial on a copy: 089 → 090 → 091 each applied ok, each verdict its _schema_checks row at QF_SC_EXPECT's count, each migration.log line OK, the followups rows and their hash unchanged; Part V's negative control (an 085-shaped wa_media, 24 columns): 090 PARTIAL naming CHECK constraint failed, no 090 row — the lines as the script prints them"
check "$(has "$OUT" "  ok    QF-G2 on a copy, 089 → 090 → 091 through the pin's MigrationRunner: each applied, each migration.log line OK, never PARTIAL, and each _schema_checks row present at its expected count ($SCX104); the pin's doctor reads PASS for all three and 089's 38 names present; the followups rows and their hash unchanged; Part V's negative control on a further copy (an 085-shaped wa_media, 24 columns): 090 PARTIAL naming CHECK constraint failed, no 090 row")$(has "$OUT" "  ok    VN-G7 Part V's share of QF-G2's one trial: 090's migration.log line OK and its _schema_checks row present ($VN090SC104 of $VN090SC104), beside 089's ($c89_104/$c89_104/$c89_104) and 091's ($c91_104/$c91_104/$c91_104); Part V's negative control on a further copy (an 085-shaped wa_media) read 090 PARTIAL naming CHECK constraint failed, with no 090 row")" \
      "yesyes" \
  "c2' QF-G2 ok in its first-deploy words, the pin's doctor PASS for each migration and 089's names present (its words); VN-G7, Part V's read of the same trial, ok — 090's row ($VN090SC104 of $VN090SC104 as the script pins it) beside 089's and 091's at QF_SC_EXPECT's counts"
HK7_104="$(sed -nE 's/^  ok    QF-G3 .* by its own scan: ([0-9]+) Evolution send sites .*/\1/p' <<<"$OUT" | head -1)"
check "$(okline "$OUT" QF-G3 "the record-only hooks — NotificationService's three, once each in sendVia, sendDocument and sendImage, AlertService::sendTo's, StarlinkMailWorker's — and W1–W3 (evo_webhook.php's marks) each present exactly as reviewed" "each of NOTIFY_KINDS' $(wc -w <<<"$(sv104 "" QF_EVENTS)") events a literal in the pin's tree" "(the control: at least $(sv104 "" QF_HK7_MIN), AiReplyWorker::handle among them)")|$( [ "$(n103 "$HK7_104")" -ge "$(n103 "$(sv104 "" QF_HK7_MIN)")" ] && echo atleast)|$(ls -d "$OUT104"/qf-hk7-* 2>/dev/null | grep -c .)|$(ls -d /tmp/dnb-5.18.104-code-*/qf-hk7-* 2>/dev/null | grep -c .)" \
      "yes|atleast|0|0" \
  "c3 QF-G3 ok — the pin's hooks each in its method, W1–W3, every NOTIFY_KINDS event (as many as QF_EVENTS names) a literal in the pin's tree, HK7's scan finding at least QF_HK7_MIN send sites, every one classified; its copy of the pin's tree removed from the host and the container"
check "$(has "$OUT" "  ok    QF-G4 none of the pin's $(kv104 "$RK104" all) switch and record keys (SalesFollowupLadder::allKeys(), every part's) is set — not in kyc_config.json, not in the store row, not in data/config.json or <data>/config.json, and none reads set through PluginConfig::read: 5.18.104 starts dark")$(has "$OUT" "  ok    VN-G1 none of Part V's $(kv104 "$RK104" vswitch) switch and record keys is set — QF-G4's read (ruling R7); Part V clears nothing itself")$(noteline "$OUT" VN-G1 "Part V's settings stored: $(kv104 "$RC104" vset) of $(kv104 "$RK104" vsettings)" "names only; printed, never NO-GO and never cleared (ruling R5)")$(has "$OUT" "  ok    OF-G1 none of Part O's $(kv104 "$RK104" oswitch) switch and record keys (offers_test_enabled, offers_live_enabled, offers_live_since and their skip and override keys) is set — QF-G4's read (ruling R7); Part O clears nothing itself, and its $(kv104 "$RK104" osettings) settings are printed by OF-N3, never NO-GO (ruling R5)")" \
      "yesyesyesyes" \
  "c4 QF-G4 ok over the $(kv104 "$RK104" all) keys the RELEASE's own ladder gives, VN-G1 over Part V's $(kv104 "$RK104" vswitch) and OF-G1 over Part O's $(kv104 "$RK104" oswitch), each by the ladder's own part column; VN-G1's settings note counting the Part V settings the release's own PluginConfig reads set on the copy — 5.18.104 starts dark"
G6R104="$(q104 g6)"
check "$(has "$OUT" '== A. The voice notes and the acknowledgement (5.18.104, Part V'"'"'s VN; read-only, on a copy through the pin'"'"'s code, and the pin'"'"'s text) ==')$(has "$OUT" '  ok    VN-G2 a first deploy: no wa_media, wa_voice_calls or wa_voice_acks on the copy, no 090 row in the ledger or in _schema_checks; wa_messages read (the positive control)')$(okline "$OUT" VN-G4 "as text in the pin: 8c's block holds every gate, policy, store and bus line inside its try/catch")$(has "$OUT" '  ok    VN-G6 a first deploy: no ai.media and no ai.voice_ack event on the copy')|$G6R104" \
      "yesyesyesyes|events=present media=0 ack=0" \
  "c5 Part V's stage A: VN-G2 (none of 090's tables or rows), VN-G4 (the pin's gates as text) and VN-G6 ok — this rehearsal's own reading of the events: the table there (the control), no ai.media or ai.voice_ack event"
G5R104="$(q104 g5)"
check "$(noteline "$OUT" VN-G3 "the OpenAI key is $(kv104 "$RC104" key | sed 's/-/ /') (read in memory through the pin's PluginConfig, never printed); ai_provider $(kv104 "$RC104" provider); ai_enabled ")$(noteline "$OUT" VN-G5 "the voice baseline over 30 days on the copy, counts only: $G5R104 handsets=")$(noteline "$OUT" VN-G5 "the settings Part V reads: tenant=uganda tz=Africa/Kampala")$(noteline "$OUT" VN-G5 'R-01')|$( [ "$(n103 "$(kv104 "$G5R104" audio)")" -ge "$(n103 "$(kv104 "$X104" audio)")" ] && echo seeded)" \
      "yesyesyesyes|seeded" \
  "c6 VN-G3 says the OpenAI key as the release's own PluginConfig reads it (never its value) and ai_provider; VN-G5 counts the inbound voice notes by department channel exactly as this rehearsal's own reading does — the fixtures' among them — reads the copy as Uganda in Kampala, and says R-01's lines or why not"
check "$(has "$OUT" '== A. The offers (5.18.104, Part O'"'"'s OF; read-only, on QF-G2'"'"'s trial copy through the pin'"'"'s code; nothing is sent) ==')$(has "$OUT" '  ok    OF-G1 a first deploy: no wa_offer* table on the copy, and no offers_* switch or stamp set (QF-G4) — both as expected')$(okline "$OUT" OF-G2 "Part O's share of QF-G2's one trial (ruling R10): 091 applied ok, never partial, its migration.log line OK, and its _schema_checks row $(sed -n 's/.*OF_091_SC="\([0-9]*\)".*/\1/p' "$DEPLOY104" | head -1) of $(sed -n 's/.*OF_091_SC="\([0-9]*\)".*/\1/p' "$DEPLOY104" | head -1); OfferSchema::check() empty through the pin's OfferSchema on the trial copy" "the copy read with nothing written")$(noteline "$OUT" OF-N1 'the baseline audience, size all, through the pin'"'"'s store-free OfferAudience::build() on QF-G2'"'"'s trial copy' 'A first deploy:' 'not measured before 089')$(noteline "$OUT" OF-N2 '')$(noteline "$OUT" OF-N3 "as the copy's settings read through the pin's PluginConfig: dn_tz() Africa/Kampala, " "active staff accounts $(kv104 "$RS104" staff_active), $(kv104 "$RS104" staff_no_phone) of them with no phone of record" "active admins with one $(kv104 "$RS104" admins_with_phone) (P-O1-7 needs at least one)")" \
      "yesyesyesyesyesyes" \
  "c7 Part O's stage A: OF-G1 (none of 091's tables) and OF-G2 (091 ok on the trial copy, OfferSchema sound, every table's rows unchanged) ok; OF-N1 the baseline audience, its sources new in 089–091 not measured; OF-N2; OF-N3 in Kampala, the staff counts as the RELEASE's own StaffDirectory reads the store on a scratch copy — the fixtures' accounts among them, one active without a phone"
# AC-N1 has three note forms: the read one, and two that say it could not be read (on the copy; the copy's settings not
# given P7's keys) — after either, AC-N2 still runs. Only the read form counts here, and the Uganda reading is taken from it.
ACN1L104="$(grep -E '^  note  AC-N1 ' <<<"$OUT" | grep -F "quote_email_via_plugin (the app's quotations, path 1)" | grep -F 'accounts, active:' | head -1)"
check "$(has "$OUT" "  ok    AC-L1 PHP $(dk104 exec ucrm php -r 'echo PHP_VERSION;' | tr -cd '0-9.') in the container accepts the pin's $(sv104 "" AC_L1_FILES | sed 's/ /, /g') — each as Git holds it, by its sha256 — before anything is copied (P7S-07)")|$( [ -n "$ACN1L104" ] && echo read || echo unread)|$(grep -c '^  note  AC-N1 ' <<<"$OUT")|$(grep -cE "^  note  AC-N1 (could not be read on the copy|the copy's settings could not be given the keys P7's lines read)" <<<"$OUT")|$(grep -cF 'THE COPY DOES NOT READ AS UGANDA' <<<"$ACN1L104")$(grep -cF "AC-N1 and AC-N2 could not be read" <<<"$OUT")|$(ls /tmp/dnb_ac_lint.* 2>/dev/null | grep -c .)" \
      "yes|read|1|0|00|0" \
  "c8 Part P's stage A: AC-L1 ok over the script's own four files (AC_L1_FILES) under the container's PHP; AC-N1 noted once, in its read form (its two paths' switches, the accounts) — never a 'could not be read' form — and that line reads the copy as Uganda; the lint's temporary copy removed"
# AC-N2 — every count as this rehearsal reads the live quote_mail.log and the book's customer_email_log by AC-N2's own rules,
# the quotations' e-mails of pre-q among them (their positive control there: one path-1 line, one path-3 row, added).
MC104="$(q104 mail "$DATA/plugin.sqlite3" "$DATA/quote_mail.log")"
check "$(noteline "$OUT" AC-N2 "what the copy would have covered over 30 days (UTC) — path 1 (quote_mail.log, the app's, $(kv104 "$MC104" p1)): $(kv104 "$MC104" p1_sent) sent line(s) ($(kv104 "$MC104" p1_other) other, $(kv104 "$MC104" p1_older) older, $(kv104 "$MC104" p1_undated) undated); path 3 (customer_email_log, the webhook's, $(kv104 "$MC104" p3)): by status $(kv104 "$MC104" p3_by_status); of its sent rows, the quote id as today's records know it: $(kv104 "$MC104" p3_sent_kyc) held by a KYC application, $(kv104 "$MC104" p3_sent_log_or_lead) by a quotes_log row or a lead, $(kv104 "$MC104" p3_sent_none) by none")|$MC104" \
      "yes|$MA104" \
  "c8' AC-N2 says what this rehearsal's own reading counts — path 1's sent lines (and the others, the older, the undated), path 3's quotation rows by status and its sent rows' quote ids held by a KYC application, a quotes_log row or a lead, or none — the two e-mails of pre-q among them; the store and the log as pre-q left them"
N7R104="$(q104 n7)"; N2R104="$(q104 n2)"; CHR104="$(q104 chan)"
check "$(noteline "$OUT" QF-N1 "the stored values (as the copy's settings read them): n1=$(kv104 "$RC104" n1 | tr ',' ' ')")$(noteline "$OUT" QF-N1 "dn_tz() $(kv104 "$RC104" tz) sqlite=" " wasender=$(kv104 "$RC104" wasender) channels=$CHR104")$(noteline "$OUT" QF-N2 "enquiry follow-ups on the copy: open=$(kv104 "$N2R104" open) approved=$(kv104 "$N2R104" approved) followup_enabled=$(kv104 "$RC104" n1 | tr ',' '\n' | sed -n 's/^followup_enabled=//p') failed3=$(kv104 "$N2R104" failed3) (approved drafts by age")$(noteline "$OUT" QF-N7 "active accounts with a phone, by role (the daily list's size, R104-10): $N7R104")" \
      "yesyesyesyes" \
  "c9 QF-N1 the stored values in the RELEASE's own words (PluginConfig on a scratch copy), dn_tz(), WASender and the channels by status as this rehearsal's own reading counts them; QF-N2 the open follow-ups and the approved drafts by age; QF-N7 the accounts with a phone by role — each as this rehearsal's own reading of the store counts them, the fixtures among them (pre-g)"
check "$(noteline "$OUT" QF-N3 "the pin's report on the copy — the baseline (its own lines, counts only; owners as owner 1…n, their staff ids on the sheet):")$( [ "$(grep -c '^  report  | ' <<<"$OUT")" -ge 1 ] && echo lines)$(grep -c '^  report  | == sales follow-up report (READ-ONLY)' <<<"$OUT")|$(python3 "$H104/rh104q.py" section "$SH104C" "QF-N3 the report's owners (owner n = staff id): never paste" | cut -d: -f1)|$(noteline "$OUT" QF-N4 "the Message Log's Evolution sends over 30 days, per handset (by role) and per Kampala day" "$( [ -n "$(sq "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'notification_audit_log'")" ] && echo " cap=$(kv104 "$RC104" cap) " || echo ': log=absent')")$(noteline "$OUT" QF-N5 '' '— against reminder_hour (QF-N1)')$(noteline "$OUT" QF-N6 'log=')|$(grep -cxF "  report  |   HEADLINE   quotes neither answered nor chased within 7 days: $(sed -nE 's#^h30=([0-9]+)/([0-9]+) h90=([0-9]+)/([0-9]+)$#\1 of \2 (30 d) · \3 of \4 (90 d)#p' <<<"$RA104")" <<<"$OUT")" \
      "yeslines1|present|yesyesyes|1" \
  "c10 QF-N3 the pin's report on the copy (its own lines, its owners in a section of the sheet alone), its headline a number — the RELEASE's report on a scratch copy after pre-q's quotations ($RA104): what it read before them plus what this rehearsal counts they added; QF-N4 the Message Log's sends against the daily cap the RELEASE's own PluginConfig and WaSendBudget give; QF-N5 and QF-N6 — read, or why not"
check "$(okline "$OUT" QC-G1 'what the forms would offer in direct mode is read and judged')$(noteline "$OUT" QC-N1 "the uCRM catalogue's switch on stage A's copy: on, as an admin set it on the uCRM catalogue screen" "5.18.104 changes nothing of the catalogue")$(okline "$OUT" QL-G1 "the plugin's own catalogue — what the KYC form offers while the uCRM catalogue's switch is off" 'is read and judged')$(okline "$OUT" QS-G1 'as text: the pin')$(okline "$OUT" TN-G1 'as text')$(okline "$OUT" A6 'South Sudan stays exactly as it is')$(okline "$OUT" A22-T1 "under this server's own PHP")$(okline "$OUT" Q-G3 'quoting organization #1')$(okline "$OUT" A14 'the stray store is quarantined and the quarantine holds')|$(has "$OUT" 'THE uCRM CATALOGUE IS SWITCHED ON')$(grep -c '^  [a-zA-Z]*  *QC-N1 [A-Z][A-Z]' <<<"$OUT")" \
      "yesyesyesyesyesyesyesyesyes|no0" \
  "c11 the carried checks ok — QC-G1, QL-G1 (the plugin's own catalogue, the switch-off path), QS-G1, TN-G1, A6, A22-T1 (T1's count as pinned), Q-G3, A14 — and QC-N1 a note in lower case over the switch an admin turned on under 5.18.103: the copy changes nothing of it (DS5; the capitals 5.18.103 carried are gone)"
TOT104C="$(ureq104 "$TC104" | sed -E 's/^total=([0-9]+) .*/\1/')"; UR104C="$(ureq104 "$TC104" | sed -E 's/^total=[0-9]+ //')"
check "$(kv104 "$UR104C" other)|$(kv104 "$UR104C" quote)|$(kv104 "$UR104C" odd)|$( [ "$(kv104 "$UR104C" products)" = "$(kv104 "$UR104C" plans)" ] && [ "$(n103 "$(kv104 "$UR104C" products | cut -d/ -f1)")" -ge 1 ] && echo pairs)|$TOT104C|$(lim104 "$TC104" | sed -E 's/^n=[0-9]+ //')|$(uother104 "$TC104")" "0|0|0|pairs|$(gets104 "$OUT")|bad=0|-" \
  "c12 the stand-in uCRM was asked only GETs, the two lists in pairs through the quoting and catalogue checks' clients, every one with limit=500, as many as F counts"
check "$(stat -c %a "$SH104C" 2>/dev/null)|$(stat -c %a "$OUT104")|$(grep -lF -f "$NAMES104" "$R104/run-$TC104.txt" "$OUT104"/*.log 2>/dev/null | grep -c .)|$(grep -lF -f "$NAMES102" "$R104/run-$TC104.txt" "$OUT104"/*.log 2>/dev/null | grep -c .)|$(names100 "$R104/run-$TC104.txt" "$OUT104"/*.log)|$(printf 'x %s y\n' "$(sed -n 2p "$NAMES104")" > "$R104/zz-c13-control.txt"; grep -lF -f "$NAMES104" "$R104/zz-c13-control.txt" | grep -c .)" \
      "600|700|0|0|0|1" \
  "c13 the sheet 0600 in a folder of mode 700; no name or phone of this rehearsal's fixtures, of 5.18.102's seeds or of a staff record in the terminal or the log — control: a file holding one of the fixtures' phones is found"
rm -f "$R104/zz-c13-control.txt"
QA104C="$(qfaud104 "$TC104")"; AU104C="$(audit104 "$TC104")"
check "$(fp99)|$(data94)|$(kept_state)$(oldname)|$(tables104 | cut -d' ' -f1-2)|$(mig104 | cut -d' ' -f1)|$( [ "$(n103 "$(kv104 "$AU104C" copies)")" -ge 1 ] && echo copies)$(sed -E 's/^copies=[0-9]+//; s/ adj=.*//' <<<"$AU104C")|$(vault104 "$TC104" | awk -F'>' '{print ($1 == $2 ? "same" : "moved")}')|$(cache104)|$(left_tmp104)$(host_code104)|$( [ "$(n103 "$(kv104 "$QA104C" copy)")" -ge 1 ] && [ "$(( $(n103 "$(kv104 "$QA104C" env)") + $(n103 "$(kv104 "$QA104C" put)") ))" -ge 1 ] && echo copies)$( [ "$(n103 "$(kv104 "$QA104C" live)")" -ge 1 ] && case ",$(kv104 "$QA104C" livemodes)," in *,qf:g4,*) echo +live ;; esac)|$(hits104 "$TC104")" \
      "$FP104|$D104|${KEPT104}0|$T0_104|mig=0/$(tr ',' '\n' <<<"$MIGF104" | grep -c .)|copies novault=0 plugin=0|same|0|00|copies+live|0" \
  "c14 nothing changed — tree, store, vault, settings, ucrm.json; the live book's digest the same; none of 089–091's tables, columns or ledger rows on it (QF-G2 trialled them on a copy); the older readings' PHP processes (at least one) each on a copy with a vault of its own; 5.18.104's own readings by mode ($QA104C): those that read a copy at least one with its vault given or set, those that read live files by design (QF-G4's among them) judged by the live vault, unchanged, and by the scan of this release's folders in /tmp at every call — no planted value and no file of a vault's name there (its positive control: the observers' control above); no page cache, no run folder or code copy left"
echo "  …    5.18.104's own readings by mode: $QA104C — live by design: the modes the script calls with the pin's or the installed code root, the live plugin folder, the live book or the PHP log ($QFLIVE104); bare: copy modes that name no vault — none since T-D3 (each copy mode's own source names its copy's vault, or, where it loads no configuration, one never made in its own folder), as check 10 wants in every run"

echo; echo "== 104-1. each QF-G NO-GO, refused before anything changes, its weakened copy caught; --clear-switches with keys of all four parts =="
V104="$(sv104 "" EXPECTED_VERSION)"
check "$V104|$(ver "$P104")" "$(ver "$P104")|$(ver "$P104")" "control: the script's EXPECTED_VERSION ($V104) is the pin's own manifest version — every text below that names the version reads it from the script"
LAD104="$(ref104 ladder "$REF104/$P" -)"   # the release's own ladder (SalesFollowupLadder): steps, optional, cascades, the one sequence
check "$(kv104 "$LAD104" steps | tr ',' '\n' | grep -c .)|$(kv104 "$LAD104" sequence | tr ',' '\n' | grep -c .)" "$(kv104 "$RK104" steps)|$(( $(kv104 "$RK104" steps) - 2 ))" \
  "control: the pin's ladder read through the release's own SalesFollowupLadder — its $(kv104 "$RK104" steps) steps (104-c's count), its one sequence the $(( $(kv104 "$RK104" steps) - 2 )) that are not step 1 or 1b"
DOT0_104="$(ls -A "$REPO/scripts" | grep -c '^\.')"   # dotfiles in the clone's scripts/ before any copy is made here
# ── The planted and the weakened copies of the script: each a copy in the clone's scripts/, each edit an anchor of the
# script's own text found exactly once and replaced once, asserted (weak104, the 103 way). A planted copy makes a guard's
# condition true (a copy of the book that cannot be made, a count the trial cannot meet, a tool that is not there); a
# weakened copy takes the guard itself out — and the refusal this rehearsal reads must then be gone: the guard is what
# refused, never another line. Where the state itself can be made (a table, a key), no copy plants it. ──────────────────
ANCH104="$SB/anchors104.tsv"
sed 's/ @@@ /\t/g' > "$ANCH104" <<'ANCH'
qfg1pin @@@ deploy @@@   [ -n "$P3B_OK" ] || stop "NO-GO (QF-G1): the pin's code could not be copied @@@   P3B_OK=""; P3B_WHY="rh104-planted"; [ -n "$P3B_OK" ] || stop "NO-GO (QF-G1): the pin's code could not be copied
qfg1pinw @@@ deploy @@@ [ -n "$P3B_OK" ] || stop "NO-GO (QF-G1): the pin's code could not be copied @@@ true || stop "NO-GO (QF-G1): the pin's code could not be copied
qfg1copy @@@ deploy @@@   sub="$(qf_copy g1 "$P3B_PIN")" || stop "NO-GO (QF-G1): a copy of the book could not be made @@@   sub="$(qf_copy g1 "$P3B_PIN/rh104-nowhere")" || stop "NO-GO (QF-G1): a copy of the book could not be made
qfg1copyw @@@ deploy @@@ "$P3B_PIN/rh104-nowhere")" || stop "NO-GO (QF-G1): a copy of the book could not be made @@@ "$P3B_PIN/rh104-nowhere")" || : "NO-GO (QF-G1): a copy of the book could not be made
qfg1part @@@ deploy @@@     *) cih_drop "$sub"; stop "NO-GO (QF-G1): the book is neither at 088 nor carrying all of 089, 090 and 091 @@@     *) QF_G1=first; : "NO-GO (QF-G1): the book is neither at 088 nor carrying all of 089, 090 and 091
qfg2w @@@ deploy @@@   [ -z "$bad" ] || stop "NO-GO (QF-G2): the one migration trial @@@   true || stop "NO-GO (QF-G2): the one migration trial
qfg2c2 @@@ deploy @@@   sub="$(qf_copy g2 "$P3B_PIN")" || stop "NO-GO (QF-G2): a second copy of the book could not be made @@@   sub="$(qf_copy g2 "$P3B_PIN/rh104-nowhere")" || stop "NO-GO (QF-G2): a second copy of the book could not be made
qfg2c2w @@@ deploy @@@ "$P3B_PIN/rh104-nowhere")" || stop "NO-GO (QF-G2): a second copy of the book could not be made @@@ "$P3B_PIN/rh104-nowhere")" || : "NO-GO (QF-G2): a second copy of the book could not be made
qfg2c3 @@@ deploy @@@   sub="$(qf_copy g2n "$P3B_PIN")" || stop "NO-GO (QF-G2): a third copy of the book (the negative control) could not be made @@@   sub="$(qf_copy g2n "$P3B_PIN/rh104-nowhere")" || stop "NO-GO (QF-G2): a third copy of the book (the negative control) could not be made
qfg2c3w @@@ deploy @@@ "$P3B_PIN/rh104-nowhere")" || stop "NO-GO (QF-G2): a third copy of the book (the negative control) could not be made @@@ "$P3B_PIN/rh104-nowhere")" || : "NO-GO (QF-G2): a third copy of the book (the negative control) could not be made
qfg3w @@@ deploy @@@   [ -z "$bad" ] || stop "NO-GO (QF-G3): @@@   true || stop "NO-GO (QF-G3):
qfg4rd @@@ deploy @@@   o="$(qf_php g4 "$P3B_PIN" "$IN_CONTAINER" "$PDD_IN")"; QF_G4_LINE= @@@   o="$(qf_php g4 "$P3B_PIN/rh104-nowhere" "$IN_CONTAINER" "$PDD_IN")"; QF_G4_LINE=
qfg4rdw @@@ deploy @@@ *) stop "NO-GO (QF-G4): the pin's keys could not be read against the live files and the store row @@@ *) : "NO-GO (QF-G4): the pin's keys could not be read against the live files and the store row
qfg4held @@@ deploy @@@     [ "$(qf_kv "$o" held)" = "0" ] || stop "NO-GO (QF-G4): @@@     true || stop "NO-GO (QF-G4):
qfg4first @@@ deploy @@@     [ "$QF_G1" = "redeploy" ] || stop "NO-GO (QF-G4): @@@     true || stop "NO-GO (QF-G4):
qfg4flag @@@ deploy @@@     if [ -z "$CLEAR_SWITCHES" ]; then @@@     if false; then
qfclr @@@ deploy @@@ php -d log_errors=0 -d opcache.enable_cli=0 "$d/code/$QF_TOOL" off all --rollback 2>&1 @@@ php -d log_errors=0 -d opcache.enable_cli=0 "$d/code/tools/rh104-nowhere.php" off all --rollback 2>&1
qfclrw @@@ deploy @@@     [ "$rc" = "0" ] || stop "NO-GO (QF-G4): the pin's tool did not clear every key @@@     true || stop "NO-GO (QF-G4): the pin's tool did not clear every key
qfrdset @@@ deploy @@@   [ "$(qf_kv "$o" set)" = "0" ] || stop "NO-GO (QF-G4): $(qf_kv "$o" set) of $EXPECTED_VERSION's keys still read set before the copy @@@   true || stop "NO-GO (QF-G4): $(qf_kv "$o" set) of $EXPECTED_VERSION's keys still read set before the copy
qfbase @@@ deploy @@@   QF_BASE="$(qf_php base "$PDD_IN/plugin.sqlite3" @@@   QF_BASE="$(qf_php base "$PDD_IN/rh104-nowhere.sqlite3"
qfbasew @@@ deploy @@@ *) stop "NO-GO (QF-G1): the redeploy baseline could not be read from the live book @@@ *) : "NO-GO (QF-G1): the redeploy baseline could not be read from the live book
ANCH
# Two plants whose anchor is a value the script states, read from it here, never written in this file: QF_SC_EXPECT's
# first count one more than the migration's own (the trial can then never meet it), and HK7's control far above the
# pin's send sites.
SCX1_104="${SCX104%% *}"; HK7MIN104="$(sv104 "" QF_HK7_MIN)"
printf '%s\t%s\t%s\t%s\n' qfg2sc deploy "QF_SC_EXPECT=\"$SCX1_104 " "QF_SC_EXPECT=\"${SCX1_104%%:*}:$(( $(n103 "${SCX1_104##*:}") + 1 )) " >> "$ANCH104"
printf '%s\t%s\t%s\t%s\n' qfg3hk deploy "QF_HK7_MIN=\"$HK7MIN104\"   # QF-G3:" "QF_HK7_MIN=\"$(( $(n103 "$HK7MIN104") * 1000 + 1000 ))\"   # QF-G3:" >> "$ANCH104"
anc104() { awk -F'\t' -v k="$1" '$1 == k { print $3; exit }' "$ANCH104"; }
rep104() { awk -F'\t' -v k="$1" '$1 == k { print $4; exit }' "$ANCH104"; }
weak104() {   # weak104 NAME KEY… — a copy of the script in the clone's scripts/ with each KEY's anchor replaced, each exactly
              # once, in the order given (a weakened guard after the plant it weakens, its anchor the plant's text)
  local name="$1" k dst="$REPO/scripts/.$1.sh"; shift
  cp "$DEPLOY104" "$dst"
  for k in "$@"; do
    python3 -I - "$dst" "$(anc104 "$k")" "$(rep104 "$k")" <<'PY' || echo "weak104:$k" >> "$SB/anchor-missing"
import sys
f, a, b = sys.argv[1:4]; s = open(f, encoding='utf-8').read()
assert a and a != b and s.count(a) == 1, (a[:70], s.count(a))
s2 = s.replace(a, b, 1)
assert s2.count(b) >= 1 and (b.count(a) > 0 or s2.count(a) == 0)
open(f, 'w', encoding='utf-8').write(s2)
PY
  done
  echo "$dst"
}
# Each anchor read back from the table is in the script exactly once — the table is the script's own text; and each
# replacement differs from it (the control of the controls: an anchor that matched nothing would plant nothing).
ANCC104="$(python3 -I - "$DEPLOY104" "$ANCH104" <<'PY'
import sys
s = open(sys.argv[1], encoding='utf-8').read()
rows = [l.rstrip('\n').split('\t') for l in open(sys.argv[2], encoding='utf-8') if l.strip()]
t = {r[0]: r for r in rows}
bad = []
for r in rows:
    k, a, b = r[0], r[2], r[3]
    base = k[:-1] if k.endswith('w') and k[:-1] in t and 'rh104-' in a else None   # a weakened guard inside its plant
    text = s.replace(t[base][2], t[base][3], 1) if base else s
    if text.count(a) != 1 or a == b or (base and s.count(a) != 0):
        bad.append(k)
print('%d|%d|%s' % (len(bad), len(rows), ','.join(bad) or '-'))
PY
)"
check "$ANCC104" "0|$(grep -c . "$ANCH104")|-" \
  "control: each of the $(grep -c . "$ANCH104") anchors of the planted and weakened copies is the script's own text, found in it exactly once (the weakened ones' anchors inside their plants' replacements), and each replacement differs from its anchor"
# A run of a copy: stage A alone (--check), the copy removed after; nothing of the copy is kept but its output.
mut104() { local m o; m="$(weak104 "$@")"; o="$(R104_MUTANT_OUT=1 run104 --script "$m" --check)"; rm -f "$m"; printf '%s' "$o"; }
ok1_104() { okline "$1" "$2" "${3:-}"; }   # ok1_104 OUTPUT ID [FRAGMENT] — the check's ok line is there
stopat104() { grep -m1 '^  STOP: ' <<<"$1" | cut -c10-90; }   # where a run stopped, its first words (a FAIL label's evidence)

# ── 1a–1c: QF-G1. A book 089 left part-applied — 089's quote_tracker alone, planted raw (no writer of the release makes one
# table of a migration without the rest): refused; then the pin's code and the copy of the book, each through a planted
# copy; each guard's weakened copy caught. ─────────────────────────────────────────────────────────────────────────────
sqx "CREATE TABLE quote_tracker (rh104_planted INTEGER)" >/dev/null   # raw: the one table of 089's that a part-applied 089 would leave
D1A104="$(data94)"; T1A104="$(tables104 | cut -d' ' -f1-2)"
check "$T1A104|$( [ "$D1A104" != "$D104" ] && echo moved )" "t104=1/$(tr ',' '\n' <<<"$TBL104" | grep -c .) cols=0/$(tr ',' '\n' <<<"$COL104" | grep -c .)|moved" \
  "1a the plant: 089's quote_tracker on the live book, alone — this rehearsal's own reading finds one of 089–091's tables and no column, and the data's digest moved"
fresh104; OUT="$(run104 --check)"
nogo104 "1a QF-G1 refuses a part-applied book: neither 088 last nor all of 089, 090 and 091 — the copy's shape in the refusal, the tracker's table counted alone" \
  "NO-GO (QF-G1): the book is neither at 088 nor carrying all of 089, 090 and 091 (last=${MIG88_104#migrations/} mig=000 sc=000 tables=100 kind=0 control=1; mig, sc and tables per migration and per table): a part-applied $V104 — a migration's _schema_checks row is the verdict, and one is missing. Nothing was changed. Send the log file" \
  "$OUT" "$D1A104" "$T1A104"
fresh104; OUT="$(mut104 qfg1pa qfg1part)"
check "$(has "$OUT" 'NO-GO (QF-G1): the book is neither at 088')$(has "$OUT" '  migration trial ')|$(quiet104)" "noyes|$P103|0|$D1A104|$KEPT104|0|0|$T1A104" \
  "1a' …QF-G1's part-applied refusal weakened: its words are gone and the run goes on to QF-G2's trial over the part-applied book (caught) — nothing changed"
check "$(sqn102 "DROP TABLE quote_tracker")|$(data94)|$(tables104 | cut -d' ' -f1-2)" "0|$D104|$T0_104" "…the plant dropped: the live book as before, byte for byte by its rows, none of 089–091's tables"
fresh104; OUT="$(mut104 qfg1pn qfg1pin)"
nogo104 "1b QF-G1 refuses when the pin's code could not be copied into the container's /tmp (a planted copy: the copy's flag cleared)" \
  "NO-GO (QF-G1): the pin's code could not be copied into the container's /tmp (rh104-planted) — QF-G1 to QF-G4 read the book through it. Nothing was changed. Send the log file" "$OUT"
fresh104; OUT="$(mut104 qfg1pnw qfg1pin qfg1pinw)"
check "$(has "$OUT" "NO-GO (QF-G1): the pin's code could not be copied")$(ok1_104 "$OUT" QF-G1 "the copy's last migration is")|$(quiet104)" "noyes|$P103|0|$D104|$KEPT104|0|0|$T0_104" \
  "1b' …and that guard weakened: its refusal is gone and QF-G1 reads the book through the pin's code it still has (caught) — nothing changed"
fresh104; OUT="$(mut104 qfg1cp qfg1copy)"
nogo104 "1c QF-G1 refuses when a copy of the book could not be made (a planted copy: the reading's code root names a folder that is not there) — with the reason qf_copy printed from its \$( ), its first failing step: the settings files could not be copied (DS5, S1; the empty reason gone)" \
  "NO-GO (QF-G1): a copy of the book could not be made — the settings files could not be copied (" "$OUT"
fresh104; OUT="$(mut104 qfg1cpw qfg1copy qfg1copyw)"
check "$(has "$OUT" 'NO-GO (QF-G1): a copy of the book could not be made')$(has "$OUT" 'NO-GO (QF-G1): the book is neither at 088 nor carrying all of 089, 090 and 091 (')|$(quiet104)" "noyes|$P103|0|$D104|$KEPT104|0|0|$T0_104" \
  "1c' …and that guard weakened: its words are gone and the shape's guard behind it stops the run over the copy that was never made (caught) — nothing changed"

# ── 1d–1f: QF-G2, the one trial for every part: a count the trial cannot meet (QF_SC_EXPECT's first, one more), and its
# second and third copies that could not be made; each guard weakened. ────────────────────────────────────────────────
fresh104; OUT="$(mut104 qfg2sc qfg2sc)"
nogo104 "1d QF-G2 refuses a trial whose _schema_checks row does not read at its expected count (a planted copy: ${SCX1_104%%:*}'s count one more than the migration's own INSERT states)" \
  "NO-GO (QF-G2): the one migration trial (089 → 090 → 091 through the pin's runner, on a copy) did not read as reviewed: ${SCX1_104%%:*}:verdict. Nothing was changed. Send the log file" "$OUT"
fresh104; OUT="$(mut104 qfg2scw qfg2sc qfg2w)"
check "$(has "$OUT" 'NO-GO (QF-G2): the one migration trial')$(ok1_104 "$OUT" QF-G2 "on a copy, 089 → 090 → 091 through the pin's MigrationRunner")|$(quiet104)" "noyes|$P103|0|$D104|$KEPT104|0|0|$T0_104" \
  "1d' …and QF-G2's verdict weakened: its refusal is gone and QF-G2 reads ok over a count the trial never met (caught) — nothing changed"
fresh104; OUT="$(mut104 qfg2c2 qfg2c2)"
nogo104 "1e QF-G2 refuses when its second copy (the trial's) could not be made — with the reason qf_copy printed (DS5, S1)" \
  "NO-GO (QF-G2): a second copy of the book could not be made — the settings files could not be copied (" "$OUT"
fresh104; OUT="$(mut104 qfg2c2w qfg2c2 qfg2c2w)"
check "$(has "$OUT" 'NO-GO (QF-G2): a second copy')$(grep -c '^  STOP: NO-GO (' <<<"$OUT")|$(quiet104)" "no1|$P103|0|$D104|$KEPT104|0|0|$T0_104" \
  "1e' …and that guard weakened: its words are gone and a later refusal stops the run over the trial that never ran ($(stopat104 "$OUT")) (caught) — nothing changed"
fresh104; OUT="$(mut104 qfg2c3 qfg2c3)"
nogo104 "1f QF-G2 refuses when its third copy (Part V's negative control) could not be made — with the reason qf_copy printed (DS5, S1)" \
  "NO-GO (QF-G2): a third copy of the book (the negative control) could not be made — the settings files could not be copied (" "$OUT"
fresh104; OUT="$(mut104 qfg2c3w qfg2c3 qfg2c3w)"
check "$(has "$OUT" 'NO-GO (QF-G2): a third copy')$(has "$OUT" 'NO-GO (QF-G2): the one migration trial (089 → 090 → 091 through the pin'"'"'s runner, on a copy) did not read as reviewed:')$(has "$OUT" ' negative-control(')|$(quiet104)" "noyesyes|$P103|0|$D104|$KEPT104|0|0|$T0_104" \
  "1f' …and that guard weakened: its words are gone and the trial's own verdict refuses, naming the negative control that never ran (caught) — nothing changed"

# ── 1g: QF-G3, HK7's control set far above the pin's send sites (a planted copy); the guard weakened. ───────────────────
fresh104; OUT="$(mut104 qfg3hk qfg3hk)"
nogo104 "1g QF-G3 refuses when HK7's scan finds fewer send sites than its control (a planted copy: QF_HK7_MIN, the script's $HK7MIN104, made $(( $(n103 "$HK7MIN104") * 1000 + 1000 )))" \
  "NO-GO (QF-G3): the pin's record-only hooks, W1–W3, its events or its send-site inventory are not as reviewed: hk7:the-control-found-" "$OUT"
check "$(has "$OUT" "-sites-under-$(( $(n103 "$HK7MIN104") * 1000 + 1000 )). Nothing was changed. Send the log file")" "yes" "…its words name the count the control was set to"
fresh104; OUT="$(mut104 qfg3hkw qfg3hk qfg3w)"
check "$(has "$OUT" 'NO-GO (QF-G3)')$(ok1_104 "$OUT" QF-G3 'as text in the pin')|$(quiet104)" "noyes|$P103|0|$D104|$KEPT104|0|0|$T0_104" \
  "1g' …and QF-G3's refusal weakened: QF-G3 reads ok over a control it never met (caught) — nothing changed"

# ── 1h–1j: QF-G4, the one rule for every part's keys. Unread (a planted copy); a key held by <data>/config.json (written
# into that file raw: the switch tool never writes it — the refusal's own words); keys of all four parts set on a book
# 5.18.104 never ran on, through 5.18.103's own writer of keys (PluginConfig::saveOverrides, the one the tool uses, kept
# byte for byte by 5.18.104), with and without --clear-switches; each guard weakened. ─────────────────────────────────
fresh104; OUT="$(mut104 qfg4rd qfg4rd)"
nogo104 "1h QF-G4 refuses when the pin's keys could not be read (a planted copy: the ladder's code root is not there)" \
  "NO-GO (QF-G4): the pin's keys could not be read against the live files and the store row (" "$OUT"
fresh104; OUT="$(mut104 qfg4rdw qfg4rd qfg4rdw)"
check "$(has "$OUT" "NO-GO (QF-G4): the pin's keys could not be read")$(has "$OUT" ' of those keys are held by data/config.json or <data>/config.json')|$(quiet104)" "noyes|$P103|0|$D104|$KEPT104|0|0|$T0_104" \
  "1h' …and that guard weakened: its words are gone and the held-keys guard behind it refuses over a reading that never came (caught) — nothing changed"
# The keys a case sets, from the pin's own ladder (rh104-live.php partkeys): per part, the first row's switch keys and
# its since-stamp, the ledger's two, the record key — Part P's included; and allKeys(), the names QF-G4 reads.
live104() {   # live104 MODE ARG… — rh104-live.php as the store's user, with a copy of the live vault of its own (removed after),
              # under the rehearsal's clock when CLK104 is set (104-5); one line of JSON, or none(<why>)
  local o v="$H104/v/live-vault.json"
  rm -f "$v"; [ ! -f "$VAULT" ] || { cp "$VAULT" "$v"; chown 1000:1000 "$v"; chmod 600 "$v"; }
  o="$(cd "$H104/v" && setpriv --reuid 1000 --regid 1000 --clear-groups -- env DN_VAULT_FILE="$v" ${CLK104:+LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104"} \
        "$PHPBIN" -d log_errors=0 "$H104/rh104-live.php" "$@" 2>"$H104/v/live.err")"
  rm -f "$v"
  case "$o" in '{'*) printf '%s' "$o" ;; *) echo "none($(head -c 160 "$H104/v/live.err"))" ;; esac
}
PK104="$(live104 partkeys "$REF104/$P" '["F","V","O","P"]')"
PKC104="$(python3 -I -c 'import json, sys; print(json.dumps(json.loads(sys.argv[1])["changes"]))' "$PK104" 2>/dev/null)"
ALLK104="$(python3 -I -c 'import json, sys; print(json.dumps(json.loads(sys.argv[1])["all"]))' "$PK104" 2>/dev/null)"
ALLCSV104="$(python3 -I -c 'import json, sys; print(",".join(json.loads(sys.argv[1])))' "$ALLK104" 2>/dev/null)"
NK104="$(python3 -I -c 'import json, sys; print(len(json.loads(sys.argv[1])))' "$PKC104" 2>/dev/null)"
# …in allKeys()' order, as QF-G4's note names them, in capitals, the commas spaces
UPK104="$(python3 -I -c 'import json, sys; c = json.loads(sys.argv[1]); a = json.loads(sys.argv[2]); print(" ".join(k.upper() for k in a if k in c))' "$PKC104" "$ALLK104" 2>/dev/null)"
LEDK104="$(python3 -I -c 'import json, sys; c = json.loads(sys.argv[1]); print([k for k in c if c[k] == "on"][0])' "$PKC104" 2>/dev/null)"
check "$(python3 -I -c 'import json, sys; c = json.loads(sys.argv[1]); a = json.loads(sys.argv[2]); print("%s|%s" % ("in" if all(k in a for k in c) else "out", len(a)))' "$PKC104" "$ALLK104" 2>/dev/null)|$(kv104 "$RK104" all)|$( [ "$(n103 "$NK104")" -ge 6 ] && echo parts)" \
      "in|$(kv104 "$RK104" all)|$(kv104 "$RK104" all)|parts" \
  "control: the keys this section sets are the pin's own — a switch key and its stamp of each part's first row (Parts F, V, O and P), the ledger's two and the record key, $NK104 in all, every one in allKeys(), whose $(kv104 "$RK104" all) names are the count the release's ladder gives (104-c)"
# 1i: the ledger's key held by <data>/config.json
CFGX104="absent"; [ -f "$DATA/config.json" ] && { CFGX104="present"; cp -p "$DATA/config.json" "$SB/config104.kept"; }
python3 -I - "$DATA/config.json" "$LEDK104" <<'PY'   # raw: <data>/config.json is never the tool's to write — that is the refusal
import json, os, sys
p, k = sys.argv[1], sys.argv[2]
d = {}
if os.path.exists(p):
    d = json.load(open(p, encoding='utf-8'))
d[k] = 'on'
json.dump(d, open(p, 'w', encoding='utf-8'))
PY
chown 1000:1000 "$DATA/config.json"
D1I104="$(data94)"
fresh104; OUT="$(run104 --check)"
nogo104 "1i QF-G4 refuses a key held by <data>/config.json, which the switch tool never writes" \
  "NO-GO (QF-G4): 1 of those keys are held by data/config.json or <data>/config.json, which the switch tool never writes (its off would read back FAIL key_outside_overrides) — remove them there by hand with the plugin's own Settings, never with this script. Nothing was changed. Send the log file" \
  "$OUT" "$D1I104"
check "$(noteline "$OUT" QF-G4 "$(tr 'a-z' 'A-Z' <<<"$LEDK104") — 1 OF $V104'S KEYS SET (keys=$(kv104 "$RK104" all) set=1 file=0 store=0 " "held=1)")" "yes" \
  "…its note names the key in capitals, one set, in neither kyc_config.json nor the store row, held"
fresh104; OUT="$(mut104 qfg4hw qfg4held)"
check "$(has "$OUT" 'of those keys are held by')$(has "$OUT" "NO-GO (QF-G4): 1 of $V104's keys are set on a book $V104 never ran on (QF-G1: 088 last)")|$(quiet104)" "noyes|$P103|0|$D1I104|$KEPT104|0|0|$T0_104" \
  "1i' …and that guard weakened: its words are gone and the first-deploy guard behind it refuses (caught) — nothing changed"
if [ "$CFGX104" = present ]; then cp -p "$SB/config104.kept" "$DATA/config.json"; rm -f "$SB/config104.kept"; else rm -f "$DATA/config.json"; fi
check "$(data94)" "$D104" "…<data>/config.json as it was: the live data as before"
# 1j: keys of all four parts through 5.18.103's own writer, on a book 5.18.104 never ran on
cfg_keep
KW104="$(live104 keys "$PD" "$DATA" "$PKC104" "$ALLK104")"
KS104="$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" "$ALLCSV104")"
D1J104="$(data94)"
check "$KW104|$(kv104 "$KS104" set)" "{\"ok\":true,\"n\":$NK104}|$NK104" \
  "1j the plant, through the installed 5.18.103's PluginConfig::saveOverrides: $NK104 switch and record keys of Parts F, V, O and P set — this rehearsal's own reading of kyc_config.json and the store row finds the $NK104 (the positive control)"
fresh104; OUT="$(run104 --check)"
QF4FIRST104="NO-GO (QF-G4): $NK104 of $V104's keys are set on a book $V104 never ran on (QF-G1: 088 last) — set by hand; --clear-switches would run the pin's tool, which migrates the live book before the backup's copy is the only one. Nothing was changed. Send the log file"
nogo104 "1j QF-G4 refuses keys of every part set on a book $V104 never ran on" "$QF4FIRST104" "$OUT" "$D1J104"
check "$(noteline "$OUT" QF-G4 "$UPK104 — $NK104 OF $V104'S KEYS SET (keys=$(kv104 "$RK104" all) set=$NK104 file=$NK104 store=$NK104 ")" "yes" \
  "…its note names every key in capitals, in allKeys()' order, each in the settings file and the store row"
fresh104; OUT="$(run104 --check --clear-switches)"
nogo104 "1j-b …and with --clear-switches: the same refusal — on a first deploy the pin's tool would migrate the live book before the backup's copy (DS1's reading)" "$QF4FIRST104" "$OUT" "$D1J104"
fresh104; OUT="$(run104 --answer DEPLOY --clear-switches)"
nogo104 "1j-c …and the deploy itself with --clear-switches: refused in stage A, never asked to type DEPLOY, nothing backed up" "$QF4FIRST104" "$OUT" "$D1J104"
fresh104; OUT="$(mut104 qfg4fw qfg4first)"
check "$(has "$OUT" 'never ran on (QF-G1: 088 last)')$(has "$OUT" "NO-GO (QF-G4): $NK104 of $V104's switch and record keys are set (left by an earlier $V104 that was rolled back without its off)")|$(quiet104)" \
      "noyes|$P103|0|$D1J104|$KEPT104|0|0|$T0_104" \
  "1j' …and the first-deploy guard weakened: its words are gone and the redeploy's refusal speaks instead, over a book that never had $V104 (caught) — nothing changed"
cfg_back
check "$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" "$ALLCSV104")" set)|$(data94)" "0|$D104" "…both copies of the settings put back as they were: none of the keys set, the live data as before"

# ── 1k: --clear-switches on a redeploy's book. A copy of the data folder is kept; the live book is brought to the shape a
# rolled-back 5.18.104 leaves — 089, 090 and 091 applied by the PIN's own MigrationRunner (SqliteStore::create of the pin's
# code root, as the first boot of 5.18.104 applies them) — and keys of all four parts set through the pin's own writer.
# Then stage A with --clear-switches (nothing cleared), the deploy without it (refused, its own command printed alone),
# the guard weakened; the deploy with it, DEPLOY typed: the pin's tool clears every key, off all --rollback with every
# part's hooks, before the copy — the checkout stopped right after by a lock on the clone's index (git's own refusal:
# the deploy never reaches deploy-hybrid.sh, so 5.18.103 keeps serving); the tool's failures, the keys still read set and
# the baseline unread, each planted and each guard weakened. The data folder put back after. ─────────────────────────
dsnap104() {   # dsnap104 NAME — a copy of the data folder (the book, the settings files, every file in it) and of the vault,
               # with this rehearsal's digest of them, kept in its own folder
  local d="$R104/dsnap/$1"; sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1
  rm -rf "$d"; mkdir -p "$d"; cp -a "$DATA" "$d/data"; [ ! -f "$VAULT" ] || cp -p "$VAULT" "$d/vault.json"
  data94 > "$d/digest"; vt_host > "$d/vault.digest"; tables104 | cut -d' ' -f1-2 > "$d/tables"
}
drest104() {   # drest104 NAME — the data folder and the vault put back exactly as dsnap104 kept them; prints this rehearsal's
               # digests after, against those kept (same|moved)
  local d="$R104/dsnap/$1"
  [ -d "$d/data" ] || { echo "nosnap"; return 1; }
  rm -rf "$DATA"; cp -a "$d/data" "$DATA"; [ ! -f "$d/vault.json" ] || cp -p "$d/vault.json" "$VAULT"
  echo "$( [ "$(data94)" = "$(cat "$d/digest")" ] && echo same || echo moved)|$( [ "$(vt_host)" = "$(cat "$d/vault.digest")" ] && echo same || echo moved)|$( [ "$(tables104 | cut -d' ' -f1-2)" = "$(cat "$d/tables")" ] && echo same || echo moved)"
}
tidy104() {   # tidy104 CASE — what a run that typed DEPLOY and stopped before the copy left in the run folder (the backup, the
              # state files, the QL key file) moved into this rehearsal's own folder for the case, so the next deploy is a first
  local d="$R104/logs/case-$1"; mkdir -p "$d"
  find "$OUT104" -mindepth 1 -maxdepth 1 \( -name 'backup-*' -o -name 'state-5.18.104.env*' -o -name 'ql-key-*' -o -name 'cih-rows-*' \) -exec mv -t "$d/" {} + 2>/dev/null
  echo "$(backups104)$(ls "$OUT104"/state-5.18.104.env* 2>/dev/null | grep -c .)"
}
lockrepo104() { : > "$REPO/.git/index.lock"; }   # git's own lock on the clone's index: its next checkout refuses
unlockrepo104() { rm -f "$REPO/.git/index.lock"; }
# The clone is on its branch as the chain left it (BRANCH, read by 5.18.93's header when it cloned; no rehearsal since
# checks out in it, and every deploy returns it there — the deploy records DEPLOY_REF from symbolic-ref): the control's
# checkout is that branch onto itself, which takes the index lock and moves nothing — never a --detach, which would leave
# every later deploy and rollback recording a bare commit as the checkout to return to.
HEADB104="$(git -C "$REPO" rev-parse HEAD)"; BRB104="$(git -C "$REPO" symbolic-ref -q --short HEAD)"
lockrepo104
git -C "$REPO" checkout -q "$BRANCH" >/dev/null 2>&1; LKRC104=$?
unlockrepo104
git -C "$REPO" checkout -q "$BRANCH" >/dev/null 2>&1; LKRC2_104=$?
check "$BRB104|$( [ "$LKRC104" != "0" ] && echo refused)|$LKRC2_104|$(git -C "$REPO" rev-parse HEAD)|$(git -C "$REPO" symbolic-ref -q --short HEAD)|$( [ -e "$REPO/.git/index.lock" ] && echo locked || echo free)" \
      "$BRANCH|refused|0|$HEADB104|$BRANCH|free" \
  "control: the clone on its branch ($BRANCH) as the chain left it; with the index locked a checkout in it (its own branch onto itself, harmless) refuses; the lock removed, the same checkout goes through — the refusal is the lock's — and HEAD and the branch stay where they were"
stoprd104() {   # stoprd104 LABEL TEXT OUTPUT — a deploy run (DEPLOY typed) refused before the copy with TEXT, "Nothing further was
                # done", 5.18.103 still serving, no file of 5.18.104's installed
  check "$(has "$3" "$2")$(has "$3" 'Nothing further was done. Send the log file.')$(went104 "$3")|$(live)|$(installed104 "$P103")|$(left104)" "yesyesyesyes|$P103|0|0" "$1"
}
REF104S="$(refroot104)"   # the pin's code root, its data/config.json the live one's copy: SqliteStore::create from it migrates
dsnap104 rd
RDOPEN104="$(live104 open "$REF104S" "$DATA")"
RDKEYS104="$(live104 keys "$REF104S" "$DATA" "$PKC104" "$ALLK104")"
DRD104="$(data94)"; TRD104="$(tables104 | cut -d' ' -f1-2)"
check "$(sed -nE 's/.*"opened":(true|false).*/\1/p' <<<"$RDOPEN104")|$(mig104 | cut -d' ' -f1)|$TRD104|$RDKEYS104|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" "$ALLCSV104")" set)|$(live)" \
      "true|mig=$(tr ',' '\n' <<<"$MIGF104" | grep -c .)/$(tr ',' '\n' <<<"$MIGF104" | grep -c .)|t104=$(tr ',' '\n' <<<"$TBL104" | grep -c .)/$(tr ',' '\n' <<<"$TBL104" | grep -c .) cols=$(tr ',' '\n' <<<"$COL104" | grep -c .)/$(tr ',' '\n' <<<"$COL104" | grep -c .)|{\"ok\":true,\"n\":$NK104}|$NK104|$P103" \
  "1k the redeploy's book, made by the release's own code: the pin's SqliteStore::create applied 089, 090 and 091 (the ledger holds all three, every table and column there) and the pin's saveOverrides set the $NK104 keys of all four parts — 5.18.103 still serving, as after a rollback"
fresh104; OUT="$(run104 --check --clear-switches)"
check "$(checkgo104 "$OUT" | cut -d'|' -f1)|$(noteline "$OUT" QF-G1 "089, 090 AND 091 ARE ALREADY APPLIED ON THIS BOOK" "A REDEPLOY of $V104 after its rollback" "(--redeploy was not given: said, not refused")|$(ok1_104 "$OUT" QF-G2 "none pending on this redeploy's copy")|$(noteline "$OUT" QF-G4 "--clear-switches with --check: nothing is cleared — the deploy with it clears these $NK104 key(s), after DEPLOY is typed, with the pin's tool")|$(noteline "$OUT" QF-G4 "$UPK104 — $NK104 OF $V104'S KEYS SET")|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" "$ALLCSV104")" set)|$(data94)" \
      "yesyesnono0|yes|yes|yes|yes|$NK104|$DRD104" \
  "1k-a --check --clear-switches on that book: GO — QF-G1 says the redeploy in capitals (--redeploy not given: said, not refused), QF-G2 in its redeploy form, QF-G4 names the $NK104 keys and says the deploy with the flag clears them; nothing cleared, nothing changed"
fresh104; OUT="$(run104 --answer DEPLOY)"
nogo104 "1k-b the deploy without --clear-switches: refused in stage A, never asked — the keys left by an earlier $V104, the clear's own command offered" \
  "NO-GO (QF-G4): $NK104 of $V104's switch and record keys are set (left by an earlier $V104 that was rolled back without its off): a redeploy starts dark. To clear them with the pin's own tool — off all --rollback, every part's off-hooks — after you type DEPLOY and before the copy, its own command, alone:" \
  "$OUT" "$DRD104" "$TRD104"
check "$(grep -A1 -F "after you type DEPLOY and before the copy, its own command, alone:" <<<"$OUT" | tail -1)|$(grep -A2 -F "after you type DEPLOY and before the copy, its own command, alone:" <<<"$OUT" | tail -1 | grep -c '^      ')|$(grep -c 'deploy-5\.18\.104\.sh --rollback' <<<"$OUT")" \
      "      cd $REPO && bash scripts/deploy-5.18.104.sh --clear-switches $(LOGW104 deploy)|0|0" \
  "…its own command, alone on the next line — the deploy as asked with --clear-switches added, its own log — no other command beside it, and no rollback anywhere in the run"
fresh104; OUT="$(mut104 qfg4flw qfg4flag)"
check "$(has "$OUT" 'a redeploy starts dark. To clear them')$(noteline "$OUT" QF-G4 "--clear-switches with --check: nothing is cleared")|$(data94)" "noyes|$DRD104" \
  "1k-b' …and that guard weakened: its words are gone and the run goes on as if --clear-switches had been given (caught) — nothing changed"
# The clear's failures, each before the copy, after DEPLOY: the tool not there (a planted copy) — and its guard weakened,
# which leaves every key set for the read-back to refuse; and that read-back weakened too, which lets the run reach the
# baseline over keys still set. The checkout is locked for each, so none can deploy.
fresh104; lockrepo104; OUT="$(R104_MUTANT_OUT=1 run104 --script "$(weak104 qfclr qfclr)" --answer DEPLOY --clear-switches)"; unlockrepo104; rm -f "$REPO/scripts/.qfclr.sh"
stoprd104 "1k-c the pin's tool not where the clear runs it (a planted copy): NO-GO after DEPLOY, before the copy — nothing was copied" \
  "NO-GO (QF-G4): the pin's tool did not clear every key (exit " "$OUT"
check "$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" "$ALLCSV104")" set)|$(tidy104 1k-c)" "$NK104|00" "…every key still set; the backup and the state file the run left moved aside, so the next deploy finds none"
fresh104; lockrepo104; OUT="$(R104_MUTANT_OUT=1 run104 --script "$(weak104 qfclrw qfclr qfclrw)" --answer DEPLOY --clear-switches)"; unlockrepo104; rm -f "$REPO/scripts/.qfclrw.sh"
stoprd104 "1k-c' …that guard weakened: its words are gone and the read-back refuses — $NK104 keys still read set before the copy (caught)" \
  "NO-GO (QF-G4): $NK104 of $V104's keys still read set before the copy (" "$OUT"
check "$(has "$OUT" "the pin's tool did not clear every key")|$(tidy104 1k-c2)" "no|00" "…the tool's refusal absent from it; the run's leavings moved aside"
fresh104; lockrepo104; OUT="$(R104_MUTANT_OUT=1 run104 --script "$(weak104 qfrdw qfclr qfclrw qfrdset)" --answer DEPLOY --clear-switches)"; unlockrepo104; rm -f "$REPO/scripts/.qfrdw.sh"
check "$(has "$OUT" 'keys still read set before the copy')$(ok1_104 "$OUT" QF-G1 'the redeploy baseline, read-only from the live book just before the copy: rows=')$(has "$OUT" "  STOP: git checkout $P104 failed — nothing was deployed")|$(live)|$(installed104 "$P103")|$(tidy104 1k-c3)" \
      "noyesyes|$P103|0|00" \
  "1k-c'' …and the read-back weakened as well: its words are gone and the run reaches the redeploy baseline over keys still set (caught) — stopped at the locked checkout, 5.18.103 serving"
# The clear itself: DEPLOY typed, --clear-switches, the checkout locked so that the run stops right after the clear, the
# pause and the baseline — the pin's tool, off all --rollback, as the book's owner, on the live data folder.
fresh104; lockrepo104; OUT="$(run104 --answer DEPLOY --clear-switches)"; TK104="$(last104)"; unlockrepo104
SHK104="$(ls -1 "$OUT104"/cash-*.txt 2>/dev/null | head -1)"; NSHK104="$(ls -1 "$OUT104"/cash-*.txt 2>/dev/null | grep -c .)"   # fresh104 moved every earlier sheet aside: this run's alone
PKCCSV104="$(python3 -I -c 'import json, sys; print(",".join(json.loads(sys.argv[1])))' "$PKC104" 2>/dev/null)"   # the keys 1k set, by name
check "$(noteline "$OUT" QF-G4 "--clear-switches, the operator's explicit choice: once DEPLOY is typed, before the copy, the pin's tool runs off all --rollback")$(has "$OUT" "  clear           the pin's tools/sales_followup.php off all --rollback, as the book's owner, on the live data folder (QF-G4, --clear-switches)")$(okline "$OUT" QF-G4 "--clear-switches: the pin's tool ran off all --rollback in the one sequence, every part's off-hooks, and exited 0; $NK104 key(s) it reported on are on the sheet")$(has "$OUT" '  …     waiting 6 s (busy_timeout plus 1 s) after QF-G4 before the redeploy baseline (QF-G1; Q20-F01)')$(ok1_104 "$OUT" QF-G1 'the redeploy baseline, read-only from the live book just before the copy: rows=')$(has "$OUT" "  STOP: git checkout $P104 failed — nothing was deployed")" \
      "yesyesyesyesyesyes" \
  "1k-d the deploy with --clear-switches, DEPLOY typed: the pin's tool ran off all --rollback with every part's hooks and exited 0, the $NK104 keys it reported on to the sheet (the count the ok line carries); QF-G1's pause, then the redeploy baseline; the run stopped at the locked checkout, after the clear"
check "$(wason104 "$(printf '%s\n' '  note  QC-N3 the applications by entry (… a KYC form drawn with the switch off and sent once it was on: G-9)' 'was on: offers_live_enabled' '  tool    | was on: cold_enabled')")|$(wason104 '  note  QC-N3 the applications by entry (… sent once it was on: G-9)')" "2|0" \
  "control: the 'was on' reader (wason104) leaves out only DS5's QC-N3 note, whose prose carries the words, and still counts a tool's 'was on' name, bare or as the log would show it"
SEQ104="$(kv104 "$LAD104" sequence)"
check "$(grep '^  tool    | OFF ' <<<"$OUT" | sed 's/^  tool    | OFF //' | grep -vE '^(sending — |the record keys and agent-cc$)' | paste -sd, -)|$(grep -cE '^  tool    \| OFF (sending — |the record keys and agent-cc$)' <<<"$OUT")|$(wason104 "$OUT")|$NSHK104:$(python3 "$H104/rh104q.py" seckeys "$SHK104" "QF-G4 the keys --clear-switches cleared (key names; no value)" "$PKCCSV104")|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" "$ALLCSV104")" set)|$(live)|$(installed104 "$P103")|$(left104)" \
      "$SEQ104|2|0|1:present lines=1 keys=$NK104/$NK104 none=0 other=0|0|$P103|0|0" \
  "…the tool's own lines in the log — each OFF in the ladder's one sequence (SalesFollowupLadder::SEQUENCE), then the ledger's and the record keys' — never its 'was on' names, which only the sheet holds — the sheet's section of the cleared keys holds every one of the $NK104 keys 1k set and no other word, never 'none reported' (the positive control of the terminal's zero 'was on' lines: the tool printed them, the log withheld them); every key now unset by this rehearsal's own reading; 5.18.103 serving, no file of 5.18.104's installed"
check "$(grep -lF -f "$NAMES104" "$R104/run-$TK104.txt" "$OUT104"/*.log 2>/dev/null | grep -c .)|$(tidy104 1k-d)" "0|00" "…no fixture name or phone in its terminal or log; its leavings moved aside"
# The baseline unread (a planted copy; no key set now, so no clear runs, and the read-back passes): NO-GO after DEPLOY,
# before the copy; its guard weakened.
fresh104; lockrepo104; OUT="$(R104_MUTANT_OUT=1 run104 --script "$(weak104 qfbase qfbase)" --answer DEPLOY)"; unlockrepo104; rm -f "$REPO/scripts/.qfbase.sh"
stoprd104 "1k-e the redeploy baseline unread from the live book (a planted copy: the book's path wrong): NO-GO before the copy" \
  "NO-GO (QF-G1): the redeploy baseline could not be read from the live book (" "$OUT"
check "$(has "$OUT" '— QF-CA2 and QF-CA3 would have nothing to judge against. Nothing was copied. Send the log file')|$(tidy104 1k-e)" "yes|00" "…in its own words; the run's leavings moved aside"
fresh104; lockrepo104; OUT="$(R104_MUTANT_OUT=1 run104 --script "$(weak104 qfbasw qfbase qfbasew)" --answer DEPLOY)"; unlockrepo104; rm -f "$REPO/scripts/.qfbasw.sh"
check "$(has "$OUT" 'the redeploy baseline could not be read')$(ok1_104 "$OUT" QF-G1 'the redeploy baseline, read-only from the live book just before the copy:')|$(live)|$(installed104 "$P103")|$(tidy104 1k-e2)" "noyes|$P103|0|00" \
  "1k-e' …and that guard weakened: its words are gone and QF-G1 says it read a baseline it never read (caught) — stopped before any copy ($(stopat104 "$OUT")), 5.18.103 serving"
check "$(drest104 rd)|$(data94)|$(tables104 | cut -d' ' -f1-2)|$(mig104 | cut -d' ' -f1)|$(live)" "same|same|same|$D104|$T0_104|mig=0/$(tr ',' '\n' <<<"$MIGF104" | grep -c .)|$P103" \
  "…the data folder and the vault put back as the copy kept them: the live book 088-shaped again, none of 089–091 in its ledger, the data as before — production's state on 4 Nov for the deploy"
# ── 1z: AC-L1, Part P's stage-A lint (P7S-07), driven where stage A is reached — this book, 088-shaped with 5.18.103
# serving, on which 104-c's --check was GO — D §H.5.14's case "AC-L1 on a deliberately broken lib/QuoteAgentCopy.php → NO-GO
# before the copy". A weakened copy of the script (an asserted edit, its anchor AC-L1's own sha256 test) hands the lint the
# pin's QuoteAgentCopy.php cut to half its bytes AFTER its sha256 has passed, so it is the container's own php -l that
# rejects it, never the sha256. AC-L1 is the last check of stage A (after QF-G4, Part V's and Part O's): the NO-GO names
# that one file and no other, the run reached it (OF-G2 ok before it), and nothing was copied, backed up or changed. Once
# 5.18.104 serves, --check stops before stage A, so 104-5p's 5p8 is this case's control from the script's own text. ──────
M1Z104="$REPO/scripts/.acl1.sh"; cp "$DEPLOY104" "$M1Z104"
python3 -I - "$M1Z104" <<'PY' || echo "weak104:acl1" >> "$SB/anchor-missing"
import sys
f = sys.argv[1]; s = open(f, encoding='utf-8').read()
a = '       && [ "$(sha256sum "/tmp/dnb_ac_lint.$$" | cut -c1-64)" = "$want" ] \\\n'
b = a + '       && { [ "$f" != lib/QuoteAgentCopy.php ] || { head -c "$(( $(wc -c < "/tmp/dnb_ac_lint.$$") / 2 ))" "/tmp/dnb_ac_lint.$$" > "/tmp/dnb_ac_lint.$$.rh" && mv -f "/tmp/dnb_ac_lint.$$.rh" "/tmp/dnb_ac_lint.$$"; }; } \\\n'
assert s.count(a) == 1, s.count(a)
s2 = s.replace(a, b, 1)
assert s2.count(b) == 1 and s2.count('/tmp/dnb_ac_lint.$$.rh') == 2 and s2.count(a) == 1 and s2.count('[ "$f" != lib/QuoteAgentCopy.php ]') == 1
open(f, 'w', encoding='utf-8').write(s2)
PY
fresh104; OUT="$(R104_MUTANT_OUT=1 run104 --script "$M1Z104" --check)"; rm -f "$M1Z104"
nogo104 "1z AC-L1 on a deliberately broken lib/QuoteAgentCopy.php (a weakened copy: the lint handed the pin's file cut to half, after its sha256 passed): NO-GO before anything is copied (D §H.5.14, P7S-07)" \
  "in the container rejects the pin's lib/QuoteAgentCopy.php, or Git does not hold it as reviewed — P7's first call, before the quotation's POST, would stop Uganda's quoting." "$OUT"
ACL1Z104="$(has "$OUT" "  STOP: NO-GO (AC-L1): PHP ")"
check "$ACL1Z104|$(okline "$OUT" OF-G2 "Part O's share of QF-G2's one trial")|$(okline "$OUT" AC-L1 'accepts the pin')|$(grep -lF -f "$NAMES104" "$R104/mutants/run-$(last104).txt" 2>/dev/null | grep -c .)" "yes|yes|no|0" \
  "…its STOP line is AC-L1's, naming lib/QuoteAgentCopy.php alone (the three other files accepted); the run had reached the end of stage A (OF-G2 ok) and no AC-L1 ok line was printed; no fixture name in its terminal"
check "$(ls -A "$REPO/scripts" | grep -c '^\.')|$( [ -e "$REPO/.git/index.lock" ] && echo locked || echo free)|$(git -C "$REPO" rev-parse HEAD)|$(git -C "$REPO" symbolic-ref -q --short HEAD)" "$DOT0_104|free|$HEADB104|$BRANCH" \
  "…no planted or weakened copy left in the clone, its index free, its HEAD as before and on its branch ($BRANCH) — the deploy's checkout returns there"

echo; echo "== 104-4. the deploy, as the operator runs it — DEPLOY typed; PASSED with its notes; every switch OFF; F's steps in the ladder's one order, the rollback alone after the verdict =="
EVO4_104="$(evo_calls)"
fresh104; OUT="$(run104 --answer DEPLOY)"; OUT104D="$OUT"; T104D="$(last104)"
echo "  …    the deploy's own tally:$(grep -E '^  checks +[0-9]+ ok, ' <<<"$OUT" | tail -1 | sed -E 's/^  checks +/ /')"
check "$(fails "$OUT")$(has "$OUT" "  $V104 (deploy): PASSED.")$(live)$(installed104 "$P104")$(inst_ver)|$(left104)|$( [ "$(grep -c '^  note  ' <<<"$OUT")" -ge 1 ] && echo notes)|$(sed -nE 's/^  checks +[0-9]+ ok, 0 failed, ([0-9]+) notes$/\1/p' <<<"$OUT" | tail -1 | grep -c '^[1-9]')" \
      "0yes${P104}0${V104}|$NA104|notes|1" \
  "4 the deploy PASSES with its notes: 0 failed, $P104 serving, every file as the pin has it, the $NA104 it adds among them; F's tally counts its notes"
check "$(grep -cF "  the checkout is on $BRANCH (" <<<"$OUT")$(grep -cxF "  the checkout is back on $BRANCH" <<<"$OUT")|$(git -C "$REPO" symbolic-ref -q --short HEAD)" "11|$BRANCH" \
  "4' the deploy's checkout: on the clone's branch ($BRANCH) before it checked out the pin by its hash, back on it after (DEPLOY_REF from symbolic-ref) — the clone left on its branch"
CM104="$(sv104 "" COPY_MARK)"
check "$(grep -cx 'QF_G1=first' "$STATE104")$(grep -cx 'QF_G4_SET=0' "$STATE104")$(grep -cx 'QF_BASE=' "$STATE104")$(grep -cx 'QF_COPY_AT=' "$STATE104")|$(grep -c "^COPY_AT=$(stat -c %Y "$PD/$CM104")\$" "$STATE104")|$(grep -c '^QF_' "$STATE104")" \
      "1111|1|4" \
  "4a the state file: QF_G1=first, QF_G4_SET=0 and, a first deploy, no redeploy baseline and no copy's start (QF_BASE=, QF_COPY_AT=), each once and no other QF_ key; COPY_AT the time stamp_copy gave $CM104"
for l in "QF-CA1|on a fresh copy after the copy: 089, 090 and 091 each in the migration ledger once" \
         "QF-CA2|a first deploy: quote_tracker, wa_auto_sends, quote_list_sends and wa_seen_marks hold no row after the copy" \
         "QF-CA3|a first deploy: master_schedule.json, read on the copy with" "QF-CA3|the installed master registers the four jobs after notify_retry" \
         "QF-CA4|on a copy, through the installed FollowUpService: a planted open quote follow-up and its approved draft are returned by neither" \
         "QF-CA5|the installed tool's show on a copy: all $(kv104 "$RK104" steps) steps off" "QF-CA5|with the profile forced to South Sudan on a copy, the installed tool's show says \"not this install\"" \
         "QF-CA6|through the installed code on a copy, its own vault read:" "QF-CA8|on a copy, through the installed code, with wa_send_ledger=on in the copy's configuration"; do
  check "$(okline "$OUT" "${l%%|*}" "${l#*|}")" "yes" "4b ${l%%|*} ok: ${l#*|}"
done
check "$(noteline "$OUT" QF-CA7 "the live vault's sha256 and time are judged by Z1 at the end of this run")|$(grep -cE '^  FAIL  (QF|VN|VA|OF|AC)-' <<<"$OUT")|$(evo_calls)" "yes|0|$EVO4_104" \
  "4c QF-CA7 a note, Z1 the judge; no line of 5.18.104's own checks a FAIL; Evolution not called by the deploy"
SUM104="$(sed -n '/^== F\. Summary ==$/,$p' <<<"$OUT")"
SL104="$(printf '%s\n' "$SUM104" > "$R104/sum104d.txt"; python3 "$H104/rh104q.py" stepline "$R104/sum104d.txt")"
check "$(kv104 "$SL104" steps)|$(kv104 "$SL104" optional)" "$(kv104 "$LAD104" steps)|$(kv104 "$LAD104" optional)" \
  "4d F's \"your steps\" name the steps in the ladder's one order, as the release's SalesFollowupLadder::steps() gives it — report, agent-cc, ack, tracking, list, voice-measure, chase, voice-dry-run, voice-staff, voice-reply, cold, offers-test, offers-live — the two it calls optional the rows ORDER passes ($(kv104 "$LAD104" optional))"
RAN104="$(grep -oE "on report --ran [0-9]{4}-[0-9]{2}-[0-9]{2}\$" <<<"$SUM104" | head -1 | sed 's/^on report --ran //')"
for l in "      cd $REPO && mkdir -p /root/dnb-report && bash scripts/sales-followup-report.sh 2>&1 | ( umask 077; exec tee /root/dnb-report/report-\$(date -u +%Y%m%dT%H%M%SZ).log )" \
         "      cd $REPO && bash scripts/sales-followup.sh show" \
         "      cd $REPO && bash scripts/sales-followup.sh on report --ran $RAN104" \
         "      cd $REPO && bash scripts/sales-followup.sh --check report" \
         "      $EXP_AFTER104" "      $EXP_QCHECK" "      $EXP_ROLLBACK104" \
         "      cd $REPO && bash scripts/sales-followup.sh off all --rollback" "      $EXP_PUTBACK104"; do
  check "$(grep -cxF -- "$l" <<<"$SUM104")" "1" "4e printed once, alone on its line: ${l:6:110}"
done
check "$(epoch99 "$(date -d "$RAN104" +%s 2>/dev/null)")|$( [ "$RAN104" = "$(TZ=Africa/Kampala date +%Y-%m-%d)" ] && echo today)" "epoch|today" \
  "…its on report --ran carries a date, the deploy's own day in Kampala (expanded as the summary printed it)"
BLK104="$(python3 "$H104/rh104q.py" blocks "$R104/run-$T104D.txt")"
check "$(kv104 "$BLK104" mixed)|$(awk '/^  '"${V104//./\\.}"' \((deploy|after)\): / { v = 1 } index($0, "deploy-5.18.104.sh --rollback") { print (v ? "after" : "before") }' <<<"$OUT" | paste -sd, -)|$(awk -v c="      $EXP_ROLLBACK104" 'index($0, "  Only if it is ever needed — the rollback") == 1 { on = 1 } on && index($0, "      ") == 1 { print ($0 == c ? "alone" : "other"); exit }' <<<"$SUM104")|$(grep -B1 -xF "      $EXP_ROLLBACK104" <<<"$SUM104" | head -1 | grep -c '^      ')|$(grep -A1 -xF "      $EXP_ROLLBACK104" <<<"$SUM104" | tail -1 | grep -c '^      ')" \
      "0|after|alone|0|0" \
  "4f no copyable block of the run holds a rollback, a put-back or the tool's off all --rollback beside another command ($(kv104 "$BLK104" blocks) blocks: $(kv104 "$BLK104" kinds | cut -c1-120)…); the rollback printed once, after the verdict, its block's one command, alone"
check "$(grep -A1 -xF '      cd '"$REPO"' && bash scripts/sales-followup.sh off all --rollback' <<<"$SUM104" | tail -1 | grep -c '^      ')|$(grep -A1 -xF '      cd '"$REPO"' && bash scripts/sales-followup.sh off all --rollback' <<<"$SUM104" | tail -1 | grep -c "and only once that has exited 0, the put-back")" "0|1" \
  "…the by-hand put-back's two commands each in its own block: the tool's off all --rollback, then a line of prose, then the put-back"
# Every switch OFF: this rehearsal's own reading of both copies of the settings, and the installed tool's own show, as the
# operator runs it (scripts/sales-followup.sh show).
KSD104="$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" "$ALLCSV104")"
SHOW104="$(sf104 show)"; SHRC104="$(tail -n1 "$R104/sf.rc")"
NSTEPS104="$(kv104 "$RK104" steps)"
check "$(kv104 "$KSD104" set)|$SHRC104|$(grep -cE '^  (1b agent-cc \(optional, P7\)|[0-9]+ [a-z-]+( \(optional\))?): off(;|$)' <<<"$SHOW104")|$(grep -cE '^  (1b agent-cc \(optional, P7\)|[0-9]+ [a-z-]+( \(optional\))?): (on|skipped)' <<<"$SHOW104")|$(grep -cx '  the ledger: off' <<<"$SHOW104")|$(sed -n '/^keys:$/,/^dn_tz(): /p' <<<"$SHOW104" | grep -c '^  ')|$(grep -cx 'dn_tz(): Africa/Kampala' <<<"$SHOW104")" \
      "0|0|$NSTEPS104|0|1|0|1" \
  "4g every switch OFF: none of allKeys() set in either copy of the settings, by this rehearsal's own reading; the installed tool's show, through scripts/sales-followup.sh as the operator runs it (exit 0): all $NSTEPS104 steps off, the ledger off, no key listed, dn_tz() Kampala"
check "$(python3 "$H104/rh104q.py" ledger "$DATA/plugin.sqlite3" "$(date -u +%s)" | cut -d' ' -f1)|$(tables104 | cut -d' ' -f1-2)|$(grep -c 'exit 0 · this log: ' <<<"$SHOW104")|$(ls -1 "$SALES104"/followup-*.log 2>/dev/null | grep -c .)|$(stat -c %a "$SALES104" 2>/dev/null)|$(stat -c %a "$SALES104"/followup-*.log 2>/dev/null | sort -u | paste -sd, -)" \
      "rows=0|t104=$(tr ',' '\n' <<<"$TBL104" | grep -c .)/$(tr ',' '\n' <<<"$TBL104" | grep -c .) cols=$(tr ',' '\n' <<<"$COL104" | grep -c .)/$(tr ',' '\n' <<<"$COL104" | grep -c .)|1|1|700|600" \
  "…the ledger empty after the copy, 089–091's tables and columns on the live book (applied at the first boot), the wrapper's run logged once in its own folder — the folder the wrapper made itself, mode 700, its one log mode 0600 (pre-i's positive control: there the refusing wrapper made none)"
check "$(grep -lF -f "$NAMES104" "$R104/run-$T104D.txt" "$STATE104" "$OUT104"/*.log "$SALES104"/*.log 2>/dev/null | grep -c .)|$(names100 "$R104/run-$T104D.txt" "$STATE104" "$OUT104"/*.log)|$(secrets99 "$R104/run-$T104D.txt" "$STATE104")|$(uother104 "$T104D")" "0|0|0|-" \
  "4h no fixture name or phone, no staff record's name and no stand-in secret printed or recorded; uCRM asked GETs only"

echo; echo "== 104-5. --after-only before any step; the ladder through the installed tool, step by step, each --after-only after it; the order enforced; the skip paths; Part F's live cases =="
# ── The clock: a shim compiled here from this rehearsal's own source (rh104-clock.c) and given, with LD_PRELOAD, to the runs
# of this section — the tool, the deploy's --after-only, master, the webhook's server and the live acts — so that the days
# the ladder needs (L-ON's 24 h, step 6's 72 h, VN-ON1's 48 h) and a chase's day 1 pass as they would on the server; the
# offset is a file it reads at every call, moved only forward. Its control: PHP's time(), SQLite's datetime('now') and the
# shell's date under it read the planted offset, and nothing outside it moves. ──────────────────────────────────────────
CLKF104="$H104/clock.off"; CLKSO104="$H104/rh104-clock.so"; CLK104=""
# The system SQLite preloaded AFTER the shim: PHP loads its extensions with RTLD_DEEPBIND, so pdo_sqlite's libsqlite3, loaded
# by PHP itself, would bind gettimeofday() to libc past the shim — PHP's time() moved and SQLite's 'now' not (trial 2:
# off by exactly -86400). Loaded at start-up instead, it is relocated in the global scope, where the shim comes first.
SQL3LIB104="$(ldconfig -p 2>/dev/null | awk '/libsqlite3\.so\.0 /{print $NF; exit}')"
CLKPRE104="$CLKSO104${SQL3LIB104:+ $SQL3LIB104}"
printf '0\n' > "$CLKF104"; chmod 644 "$CLKF104"
gcc -shared -fPIC -O2 -o "$CLKSO104" "$H104/rh104-clock.c" -ldl 2>"$R104/clock-gcc.err"; GCC104=$?; chmod 755 "$CLKSO104" 2>/dev/null
clk_set104() { printf '%s\n' "$1" > "$CLKF104"; }
clk_off104() { n103 "$(tr -cd '0-9-' < "$CLKF104" | sed 's/^-0*$/0/')"; }
fnow104() { echo $(( $(date -u +%s) + $(cat "$CLKF104") )); }   # what the clock reads now
clk_to104() {   # clk_to104 EPOCH — the clock moved forward so that it reads EPOCH now; never back (a refusal is recorded)
  local o=$(( $1 - $(date -u +%s) ))
  if [ "$o" -lt "$(cat "$CLKF104")" ]; then echo "clock:back:$1" >> "$SB/anchor-missing"; return 1; fi
  clk_set104 "$o"
}
kat104() { TZ=Africa/Kampala date -d "$1" +%s; }                    # kat104 'YYYY-MM-DD HH:MM[:SS]' — that Kampala moment
kday104() { TZ=Africa/Kampala date -d "@$1" +%Y-%m-%d; }            # the Kampala date of an epoch
kdow104() { TZ=Africa/Kampala date -d "@$1" +%u; }                  # its weekday, 1 Monday … 7 Sunday
clk_set104 86400
CK1_104="$(env LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" "$PHPBIN" -r 'echo time();' 2>/dev/null)"
CK2_104="$(env LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" "$PHPBIN" -r '$p = new PDO("sqlite::memory:"); echo $p->query("SELECT strftime(\"%s\", \"now\")")->fetchColumn();' 2>/dev/null)"
CK3_104="$(env LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" date -u +%s)"; CK0_104="$(date -u +%s)"
CK4_104="$("$PHPBIN" -r 'echo time();')"
clk_set104 0
CK5_104="$(env LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" "$PHPBIN" -r 'echo time();' 2>/dev/null)"
nr104() { local d=$(( $(n103 "$1") - $2 )); [ "$d" -ge -5 ] && [ "$d" -le 5 ] && echo near || echo "off:$d"; }   # within 5 s
check "$GCC104$( [ -n "$SQL3LIB104" ] && [ -f "$SQL3LIB104" ] || echo :nosqlite)|$(nr104 "$CK1_104" $(( CK0_104 + 86400 )))|$(nr104 "$CK2_104" $(( CK0_104 + 86400 )))|$(nr104 "$CK3_104" $(( CK0_104 + 86400 )))|$(nr104 "$CK4_104" "$CK0_104")|$(nr104 "$CK5_104" "$CK0_104")" \
      "0|near|near|near|near|near" \
  "control: the clock compiled; under it, a day ahead, PHP's time(), SQLite's datetime('now') and date read a day ahead; without it, and under it at 0, the wall clock"
CLK104=1   # from here on this section's runs carry the clock (the live acts through live104 read it from CLK104)
run104c() { run104 --env "LD_PRELOAD=$CLKPRE104" --env "RH104_CLOCK_FILE=$CLKF104" "$@"; }
sf104c() {   # sf104c [--tty WORD] ARG… — sf104, under the clock
  local word="" tty="" cmd o
  [ "${1:-}" = "--tty" ] && { tty=1; word="$2"; shift 2; }
  # as sf104: the wrapper makes its own log folder
  if [ -n "$tty" ]; then
    cmd="$(printf '%q ' env PATH="$SB/bin:$PATH" DNB_SALES_LOG_DIR="$SALES104" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" bash "$REPO/scripts/sales-followup.sh" "$@")"
    o="$(printf '%s\n' "$word" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r'; exit "${PIPESTATUS[1]}")"; SF104_RC=$?
  else o="$(cd "$REPO" && env PATH="$SB/bin:$PATH" DNB_SALES_LOG_DIR="$SALES104" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" bash "$REPO/scripts/sales-followup.sh" "$@" 2>&1 </dev/null)"; SF104_RC=$?; fi
  printf '%s\n' "$o" >> "$R104/sf.txt"; echo "$SF104_RC" >> "$R104/sf.rc"
  printf '%s' "$o"
}
sfrc104() { tail -n1 "$R104/sf.rc"; }
master104() {   # master104 LABEL [--set JSON] DUE… — one master cycle of the INSTALLED cron/master.php, as the book's owner in the
                # container, under the clock: every job but the DUE ones first recorded as run at this moment through the
                # store's own save (a sandbox must not run Starlink's, Google Drive's or EFRIS's jobs), so the cycle dispatches
                # those alone; --set JSON ({job: {last_run, duration_ms}}) writes those jobs' entries in master's own form with
                # the same save (rh104-live.php schedule's "set") — a run still going (-1) or finished
  local lab="$1" due st="{}" j; shift
  [ "${1:-}" = "--set" ] && { st="$2"; shift 2; }
  due="$(python3 -I -c 'import json, sys; print(json.dumps(sys.argv[1:]))' "$@")"
  live104 schedule "$PD" "$DATA" "{\"due\":$due,\"set\":$st}" > "$R104/logs/master-$lab.plant"
  # a plant the helper refused leaves the schedule as it stood, and master would then dispatch every job whose interval has
  # passed (Starlink's, Google Drive's, EFRIS's, Splynx's…): it is never run unplanted — "plant" is answered instead
  case "$(head -c 9 "$R104/logs/master-$lab.plant" 2>/dev/null)" in '{"quiet":') ;; *) echo plant; return 0 ;; esac
  env PATH="$SB/bin:$PATH" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" bash "$SB/bin/docker" exec -u "$(owner104)" -e "DN_DATA_DIR=$PDD104_IN" ucrm \
    php -d log_errors=0 "$IN_CONTAINER/cron/master.php" </dev/null > "$R104/logs/master-$lab.out" 2>&1
  echo "$?"
}
sched104() { python3 -I - "$DATA/plugin.sqlite3" "$1" <<'PY'   # sched104 JOB — master_schedule.json's entry for JOB: last_run:duration_ms, or none
import sqlite3, json, sys
c = sqlite3.connect('file:' + sys.argv[1] + '?mode=ro', uri=True)
try:
    r = None
    for t in ('master_schedule',):
        try:
            r = c.execute('SELECT data FROM [%s] ORDER BY id DESC LIMIT 1' % t).fetchone()
        except sqlite3.Error:
            r = None
    d = json.loads(r[0]) if r else {}
    e = d.get(sys.argv[2]) if isinstance(d, dict) else None
    print('%s:%s' % (e.get('last_run'), e.get('duration_ms')) if isinstance(e, dict) else 'none')
finally:
    c.close()
PY
}
ledger104() { sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1; python3 "$H104/rh104q.py" ledger "$DATA/plugin.sqlite3" "$(fnow104)"; }
# The --after-only lines of each step, read from the script: its qf_on_one calls (code and step), Part V's pairs
# (VN_STEPS) and AC-ON1's own note — a step that is off prints "<code> OFF — not judged (<step> is off)".
ONP104="QF-ON0:ack QF-ON3:ledger $(grep -oE 'qf_on_one "\$(sub|1)" [A-Z]+-ON[0-9] [a-z-]+ ' "$DEPLOY104" | awk '{print $3 ":" $4}' | grep -v '^QF-ON0:' | sort -u | paste -sd' ' -) $(for p in $(sv104 "" VN_STEPS); do printf '%s:%s ' "${p##*:}" "${p%%:*}"; done)$(grep -q 'note "AC-ON1 OFF — not judged (agent-cc is off)"' "$DEPLOY104" && echo AC-ON1:agent-cc)"
onst104() {   # onst104 OUTPUT CODE STEP — how an --after-only run judged a step: off, pass, notyet:<code>, fail, or none; QF-ON0's
              # off is the ledger's (its skip-ack form is L-ON's own line), and QF-ON3 reads off, empty (on, no claim), pass or fail
  local o="$1" c="$2" s="$3"
  if [ "$c" = QF-ON3 ]; then
    if [ "$(noteline "$o" QF-ON3 'OFF — not judged (the ledger, wa_send_ledger, is off)')" = yes ]; then echo off
    elif [ "$(noteline "$o" QF-ON3 'the ledger is on and holds no proactive claim to judge')" = yes ]; then echo empty
    elif [ "$(okline "$o" QF-ON3 "the ledger's proactive claims")" = yes ]; then echo pass
    elif grep -q '^  FAIL  QF-ON3 ' <<<"$o"; then echo fail; else echo none; fi
    return
  fi
  if [ "$c" = QF-ON0 ] && [ "$(noteline "$o" QF-ON0 'OFF — not judged (the ledger, wa_send_ledger, is off)')" = yes ]; then echo off; return; fi
  if [ "$c" = QF-ON0 ] && [ "$(okline "$o" QF-ON0 'L-ON: PASS — the ledger on with step 2 off')" = yes ]; then echo pass; return; fi
  if [ "$(noteline "$o" "$c" "OFF — not judged ($s is off)")" = yes ]; then echo off
  elif [ "$(okline "$o" "$c" "$s: PASS")" = yes ]; then echo pass
  elif [ "$(noteline "$o" "$c" "$s: NOT_YET ")" = yes ]; then echo "notyet:$(grep -m1 -E "^  note  $c $s: NOT_YET " <<<"$o" | sed -E "s/^  note  $c $s: NOT_YET ([a-z_]+).*/\1/")"
  elif grep -q "^  FAIL  $c " <<<"$o"; then echo fail; else echo none; fi
}
onall104() {   # onall104 OUTPUT — every pair's state, code:step=state, in the script's order
  local p; for p in $ONP104; do printf '%s=%s ' "$p" "$(onst104 "$1" "${p%%:*}" "${p#*:}")"; done | sed 's/ $//'
}
expect104() {   # expect104 PAIR=STATE… — the line onall104 must read: every pair off but those named
  local p e out=""
  for p in $ONP104; do e=off; for x in "$@"; do [ "${x%%=*}" = "$p" ] && e="${x#*=}"; done; out="$out $p=$e"; done
  echo "${out# }"
}
idle104() {   # idle104 — once the live cases run: wait until no worker the webhook spawned (run_worker.php, run_media_worker.php)
              # is running and the stand-in Evolution's log has stood still for 2 s, at most 90 s (a timeout is recorded)
  [ -n "${WHPID104:-}" ] || return 0
  local i n0 n1
  for i in $(seq 1 90); do
    n0="$(evo_calls)"; sleep 1; n1="$(evo_calls)"
    if ! pgrep -f "$PD/run_(media_)?worker\.php" >/dev/null 2>&1 && [ "$n0" = "$n1" ]; then sleep 1; [ "$(evo_calls)" = "$n1" ] && return 0; fi
  done
  echo "idle104:timeout" >> "$SB/anchor-missing"; return 1
}
aft104() {   # aft104 LABEL [PAIR=STATE…] — --after-only under the clock: PASSED, and every pair's line as STATE says (off,
             # pass, empty, notyet, notyet:<code>, or judged: a PASS or a NOT_YET), the rest OFF — not judged; Evolution not
             # called by the run; its output in $AFT104, its run's number in $AFTT104
  local lab="$1"; shift
  idle104
  local e0; e0="$(evo_calls)"
  fresh104; AFT104="$(run104c --after-only)"; AFTT104="$(last104)"
  check "$(fails "$AFT104")$(has "$AFT104" "  $V104 (after): PASSED.")|$(python3 "$H104/rh104q.py" stcmp "$(onall104 "$AFT104")" "$(expect104 "$@")")|$(( $(evo_calls) - e0 ))" "0yes|same|0" \
    "$lab --after-only under the clock ($(kday104 "$(fnow104)")): PASSED — each step's own line as the step stands$( [ $# -gt 0 ] && echo " ($*)" || echo " (every one OFF — not judged)"), the rest OFF — not judged; Evolution not called by it"
}
# ── 5: --after-only before any step, the copy half an hour back and master's retry job after it (R19 conclusive), the
# command as the deploy's summary printed it: every part's line OFF — not judged, the ledger's two included. ─────────────
copy_back104() { local cm="$PD/$CM104" t; touch -d "-$1 minutes" "$cm"; t="$(stat -c %Y "$cm")"
  sed -i -e "s/^COPY_AT=.*/COPY_AT=$t/" -e "s/^DEPLOYED_AT=.*/DEPLOYED_AT=$(date -u -d "@$(( t - 60 ))" +%Y-%m-%dT%H:%M:%SZ)/" "$STATE104"; }
after_back104() { copy_back104 30; local c; c="$(sed -n 's/^COPY_AT=//p' "$STATE104" | tail -1)"; [ "$(epoch99 "$c")" = "epoch" ] && set_sched $(( c + 300 )); base_log; }
after_back104; ALINE104="$(printed "$OUT104D" "cd $REPO && bash scripts/deploy-5.18.104.sh --after-only")"
fresh104; OUT="$(run_line104 '' "$ALINE104")"
check "$ALINE104|$(fails "$OUT")$(has "$OUT" "  $V104 (after): PASSED.")|$(onall104 "$OUT")|$(noteline "$OUT" QF-ON0 'OFF — not judged (the ledger, wa_send_ledger, is off)')$(noteline "$OUT" QF-ON3 'OFF — not judged (the ledger, wa_send_ledger, is off)')$(noteline "$OUT" QF-ON5 'the marks per day and direction over 14 days, and the R-05 race count: marks=')" \
      "$EXP_AFTER104|0yes|$(expect104)|yesyesyes" \
  "5 --after-only before any step, as the deploy's summary printed it: PASSED — QF-ON0 and QF-ON3 OFF — not judged (the ledger off), every step's line OFF — not judged ($(wc -w <<<"$ONP104") of them: QF-ON1, QF-ON2, QF-ON4, VA-ON1, VN-ON1–VN-ON4, OF-ON1, OF-ON2, AC-ON1), QF-ON5 its note"
check "$(okline "$OUT" QF-CA3 'the installed master registers the four jobs after notify_retry')$(noteline "$OUT" QF-CA2 "QF-CA2 and QF-CA3's schedule rows judge the dark build in the deploy run")$(noteline "$OUT" QF-CA5 "the installed tool's show on a copy: $NSTEPS104 of $NSTEPS104 steps off")" "yesyesyes" \
  "…and its after-the-copy readings in their --after-only forms: QF-CA3's registration judged, QF-CA2/QF-CA3's dark build a note, QF-CA5's steps a note ($NSTEPS104 of $NSTEPS104 off)"

# ── 5a–5c: the order enforced before step 1; step 1 (report) by the summary's own two command lines; agent-cc refused
# without plugin mail, then plugin mail set by the release's own tools/email_setup.php (fictitious, on 127.0.0.1, nothing
# sent), saved with step 1 run and every later step off. ─────────────────────────────────────────────────────────────
OUT="$(sf104c on ack)"; R5A104="$(sfrc104)"; KA104="$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" "$ALLCSV104")" set)"
check "$R5A104|$(has "$OUT" '  step 1 (report) is neither on nor recorded skipped')$(has "$OUT" 'REFUSED order:report — nothing was saved')|$KA104" "2|yesyes|0" \
  "5a on ack before step 1: REFUSED order:report (exit 2), nothing saved — the order is the engine's, never a row's"
OUT="$(sf104c on agent-cc)"; check "$(sfrc104)|$(has "$OUT" 'REFUSED order:report — nothing was saved')" "2|yes" "5a' …and on agent-cc before step 1: REFUSED order:report too (ruling R11: agent-cc follows step 1)"
OUT="$(sf104c skip report --reason "rehearsal")"; check "$(sfrc104)|$(has "$OUT" 'REFUSED not_skippable — nothing was saved')" "2|yes" "5a'' skip report: REFUSED not_skippable — step 1 is never skipped"
OUT="$(sf104c skip agent-cc --reason "rehearsal")"; check "$(sfrc104)|$(has "$OUT" 'optional: the order passes it while it is off')$(has "$OUT" 'REFUSED optional — nothing was saved')" "2|yesyes" "5a''' skip agent-cc: REFUSED optional"
RLINE104="$(printed "$OUT104D" "cd $REPO && bash scripts/sales-followup.sh on report --ran")"; CLINE104="$(printed "$OUT104D" "cd $REPO && bash scripts/sales-followup.sh --check report")"
OUT="$(cd "$REPO" && env PATH="$SB/bin:$PATH" DNB_SALES_LOG_DIR="$SALES104" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" bash -c "$RLINE104" 2>&1 </dev/null)"; R5B104=$?
OUT2="$(cd "$REPO" && env PATH="$SB/bin:$PATH" DNB_SALES_LOG_DIR="$SALES104" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" bash -c "$CLINE104" 2>&1 </dev/null)"; R5C104=$?
check "$R5B104|$(has "$OUT" 'ON report: sf_report_ran_at')|$R5C104|$(grep -cx 'report: PASS' <<<"$OUT2")$(grep -cx '  report: PASS' <<<"$OUT2")" "0|yes|0|11" \
  "5b step 1 by the deploy's summary, as printed — on report --ran <the deploy's day>, accepted by the installed tool (ON report: sf_report_ran_at), then its --check report: PASS"
aft104 "5b' after step 1:"
OUT="$(sf104c on agent-cc)"
check "$(sfrc104)|$(has "$OUT" 'plugin mail is not configured (Settings → System → Email)')$(has "$OUT" 'REFUSED mail_not_configured — nothing was saved')" "2|yesyes" \
  "5c on agent-cc with no plugin mail: REFUSED mail_not_configured, nothing saved (Part P's precondition, email_settings.json alone)"
ESO104="$(env PATH="$SB/bin:$PATH" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" bash "$SB/bin/docker" exec -u "$(owner104)" -e "DN_DATA_DIR=$PDD104_IN" ucrm php -d log_errors=0 "$IN_CONTAINER/tools/email_setup.php" \
         --user RH104SECRET-mail@example.test --host 127.0.0.1 --port 2525 --enc none --pass RH104SECRET-smtp-not-a-password --no-quote </dev/null 2>&1)"; R5D104=$?
# the mode is the release's own: SecureFile::adopt's default, read from the pin (0640 — the web process's group reads it;
# trial 3 read 640 against a typed 600), never typed
SFM104="$(git -C "$REPO" show "$P104:$P/lib/SecureFile.php" 2>/dev/null | sed -nE 's/.*function adopt\(.*int \$mode = 0([0-7]{3})\).*/\1/p')"
check "$R5D104|$(grep -c 'configuration written to email_settings.json' <<<"$ESO104")|$( [ -f "$DATA/email_settings.json" ] && stat -c '%u:%a' "$DATA/email_settings.json")|${SFM104:-unread}" "0|1|1000:${SFM104:-unread}|${SFM104:-unread}" \
  "…plugin mail set through the release's own tools/email_setup.php (fictitious, SMTP on 127.0.0.1, its password an RH104SECRET- value, no test e-mail): email_settings.json written, the book's owner's, at the mode SecureFile::adopt gives it (read from the pin)"
OUT="$(sf104c on agent-cc)"
check "$(sfrc104)|$(has "$OUT" 'plugin mail: plugin SMTP')$(has "$OUT" 'nothing on WhatsApp changes')$(grep -c '^ON agent-cc: quote_agent_cc_enabled, quote_agent_cc_since$' <<<"$OUT")|$(grep -cF 'RH104SECRET' <<<"$OUT")" "0|yesyes1|0" \
  "5c' on agent-cc with step 1 run and every later step off: saved (ruling R11) — its two keys, plugin mail read from email_settings.json, nothing on WhatsApp; no address of the fixture's printed"
aft104 "5c'' after agent-cc:" "AC-ON1:agent-cc=judged"

# ── 5d–5h: step 2 and the ledger; the order enforced while a need has not passed; the day the ledger needs passes on the
# clock; then tracking, list (its need accepted after 72 h, as rule 4 allows), voice-measure and chase — each --after-only
# after it. Each `on` saves nothing it refuses, and each line is the installed tool's own. ─────────────────────────────
OUT="$(sf104c on ack)"
check "$(sfrc104)|$(has "$OUT" '_schema_checks 089_quote_followups.sql: present')$(has "$OUT" '_schema_checks 090_wa_voice.sql: present')$(has "$OUT" 'dn_tz(): Africa/Kampala')$(has "$OUT" 'ai_enabled: on')$(has "$OUT" 'the ledger starts recording every automated send; nothing is deferred yet')|$(grep -c '^ON ack: wa_voice_ack_enabled, wa_voice_ack_since, wa_send_ledger, wa_send_ledger_since$' <<<"$OUT")" \
      "0|yesyesyesyesyes|1" \
  "5d on ack: step 1 PASS, both _schema_checks rows present, dn_tz() Kampala, ai_enabled on: ON — P5's two keys and the ledger's two, the ledger's interplay said"
OUT="$(sf104c --check ack)"
check "$(sfrc104)|$(grep -cx 'ack: NOT_YET too_soon' <<<"$OUT")$(grep -cx '  L-ON: NOT_YET too_soon' <<<"$OUT")$(grep -cx '  VA-ON1: NOT_YET too_soon' <<<"$OUT")" "0|111" \
  "…--check ack: one status, NOT_YET too_soon — L-ON's and VA-ON1's each beneath it (each needs a day)"
aft104 "5d' after ack:" "QF-ON0:ack=notyet:too_soon" "QF-ON3:ledger=empty" "VA-ON1:ack=notyet:too_soon" "AC-ON1:agent-cc=judged"
OUT="$(sf104c on tracking)"
check "$(sfrc104)|$(has "$OUT" '  the ledger: L-ON reads NOT_YET too_soon')$(has "$OUT" 'REFUSED need_not_pass:sending — nothing was saved')|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" quote_tracking_enabled,quote_tracking_since)" set)" "2|yesyes|0" \
  "5e the order enforced: on tracking while its need, the ledger's L-ON, has not passed — REFUSED need_not_pass:sending, nothing saved"
# ── 5e-o (Part O, ruling R6): on offers-test refused by the ledger's L-ON — the one moment it reads NOT_YET (too_soon, its
# first day) — on a copy of this book, kept and put back: the order is first made to pass offers-test (every lower step on,
# recorded skipped or optional) by recording tracking skipped (its cascade: list, chase, cold and both offer steps — `on`
# clears a step's own skip) and voice-measure skipped (its cascade: 7–9); then the first need, the ledger's L-ON, refuses —
# pass_only — nothing saved. The accept is 104-5o's 5o'. ────────────────────────────────────────────────────────────────
dsnap104 o5e
OUT="$(sf104c skip tracking --reason "the order passed for offers-test, the rehearsal")"; R5EO1_104="$(sfrc104)"
OUT2="$(sf104c skip voice-measure --reason "the order passed for offers-test, the rehearsal")"; R5EO2_104="$(sfrc104)"
check "$R5EO1_104$R5EO2_104|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" sf_skip_tracking,sf_skip_offers-test,sf_skip_voice-measure)" set)" "00|3" \
  "control (5e-o): on a copy, tracking and voice-measure recorded skipped through the tool — offers-test among tracking's cascade — so that the order passes offers-test (every lower step on, skipped or optional)"
OUT="$(sf104c on offers-test)"
check "$(sfrc104)|$(has "$OUT" '  the ledger: L-ON reads NOT_YET too_soon (this need is met only by PASS)')$(has "$OUT" 'REFUSED need_not_pass:sending — nothing was saved')|$(grep -c '^ON offers-test' <<<"$OUT")|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" offers_test_enabled,sf_skip_offers-test)" names)" \
      "2|yesyes|0|sf_skip_offers-test" \
  "5e-o on offers-test before the ledger has run a day, the order passed: REFUSED need_not_pass:sending — the ledger's L-ON reads NOT_YET too_soon and this need is met only by PASS (ruling R6); offers_test_enabled unset and its skip still recorded (nothing saved)"
check "$(drest104 o5e)" "same|same|same" "…the copy put back: the book as 5e left it"
FA104="$(fnow104)"; clk_to104 $(( FA104 + 25 * 3600 ))
OUT="$(sf104c --check ack)"
check "$(sfrc104)|$(grep -cx '  L-ON: PASS' <<<"$OUT")|$(grep -cE '^ack: (PASS|NOT_YET no_traffic)$' <<<"$OUT")" "0|1|1" \
  "5e' the clock a day on (25 h): L-ON PASS; step 2's one status the worse of it and VA-ON1 (NOT_YET no_traffic while no voice note came)"
dsnap104 s1   # the book with step 2 on a day: the skip-tracking path's own copy (5o)
OUT="$(sf104c on tracking)"
check "$(sfrc104)|$(has "$OUT" '  the ledger: L-ON PASS')|$(grep -c '^ON tracking: quote_tracking_enabled, quote_tracking_since$' <<<"$OUT")" "0|yes|1" "5f on tracking: its need met (the ledger: L-ON PASS) — ON"
aft104 "5f' after tracking:" "QF-ON0:ack=notyet" "QF-ON3:ledger=empty" "VA-ON1:ack=notyet" "AC-ON1:agent-cc=judged" "QF-ON1:tracking=notyet"
OUT="$(sf104c on list)"
check "$(sfrc104)|$(has "$OUT" '  step 3 (tracking): Q1-ON reads NOT_YET no_traffic (it may be accepted once it has been on for 72 h)')$(has "$OUT" 'REFUSED need_not_yet:tracking — nothing was saved')" "2|yesyes" \
  "5g the order enforced again: on list while step 3 reads NOT_YET no_traffic and has not been on 72 h — REFUSED need_not_yet:tracking"
OUT="$(sf104c on voice-measure)"
check "$(sfrc104)|$(has "$OUT" 'REFUSED order:list — nothing was saved')$(has "$OUT" '  step 4 (list) is neither on nor recorded skipped')$(has "$OUT" '_schema_checks 090_wa_voice.sql: present')|$(grep -c '^ON voice-measure: ai_media_enabled, ai_media_since$' <<<"$OUT")" "2|yesyesno|0" \
  "5g' …and on voice-measure, a later step, refused by the order while step 4 is neither on nor skipped — REFUSED order:list, before its precondition is read; nothing saved"
# ── 5g-o (Part O, ruling R6): on offers-test refused by step 3's Q1-ON — while it reads NOT_YET no_traffic and tracking has
# not been on 72 h (no --accept-not-yet can take it yet) — on a copy, kept and put back: the order made to pass it by
# recording list, voice-measure (7–9 with it) and chase (cold with it) skipped; the ledger's L-ON now PASS, then step 3's
# need refuses, nothing saved. ──────────────────────────────────────────────────────────────────────────────────────────
dsnap104 o5g
R5GO104=""; for st104 in list voice-measure chase; do OUT="$(sf104c skip "$st104" --reason "the order passed for offers-test, the rehearsal")"; R5GO104="$R5GO104$(sfrc104)"; done
check "$R5GO104|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" sf_skip_list,sf_skip_voice-measure,sf_skip_chase,sf_skip_tracking,quote_tracking_enabled)" names)" \
      "000|sf_skip_list,sf_skip_voice-measure,sf_skip_chase,quote_tracking_enabled" \
  "control (5g-o): on a copy, list, voice-measure and chase recorded skipped through the tool, tracking on (not skipped) — so that the order passes offers-test"
OUT="$(sf104c on offers-test)"
check "$(sfrc104)|$(has "$OUT" '  step 3 (tracking): Q1-ON reads NOT_YET no_traffic (it may be accepted once it has been on for 72 h)')$(has "$OUT" 'REFUSED need_not_yet:tracking — nothing was saved')|$(grep -c '^ON offers-test' <<<"$OUT")|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" offers_test_enabled,quote_tracking_enabled)" names)" \
      "2|yesyes|0|quote_tracking_enabled" \
  "5g-o on offers-test with the ledger's L-ON PASS and step 3's Q1-ON NOT_YET no_traffic, on less than 72 h: REFUSED need_not_yet:tracking (ruling R6) — offers_test_enabled unset (tracking's switch, read beside it, set); nothing saved"
check "$(drest104 o5g)" "same|same|same" "…the copy put back: the book as 5g' left it"
FB104="$(fnow104)"; clk_to104 $(( FB104 + 73 * 3600 ))
OUT="$(sf104c on list --accept-not-yet "no quotation yet in the rehearsal")"
check "$(sfrc104)|$(has "$OUT" '  step 3 (tracking): Q1-ON NOT_YET no_traffic, accepted')|$(grep -c '^ON list: quote_daily_list_enabled, quote_daily_list_since, sf_override_tracking$' <<<"$OUT")" "0|yes|1" \
  "5g'' the clock 73 h on: on list with --accept-not-yet — step 3's NOT_YET no_traffic accepted (on 72 h), recorded in sf_override_tracking: ON"
aft104 "5g''' after list:" "QF-ON0:ack=notyet" "QF-ON3:ledger=empty" "VA-ON1:ack=notyet" "AC-ON1:agent-cc=judged" "QF-ON1:tracking=notyet" "QF-ON2:list=notyet"
dsnap104 s15   # steps 1–4 on, voice-measure not yet: the skip-voice-measure path's own copy (5r)
OUT="$(sf104c on voice-measure)"
check "$(sfrc104)|$(has "$OUT" '_schema_checks 090_wa_voice.sql: present')|$(grep -c '^ON voice-measure: ai_media_enabled, ai_media_since$' <<<"$OUT")" "0|yes|1" "5h on voice-measure: the ledger on, 090's row present — ON"
aft104 "5h' after voice-measure:" "QF-ON0:ack=notyet" "QF-ON3:ledger=empty" "VA-ON1:ack=notyet" "AC-ON1:agent-cc=judged" "QF-ON1:tracking=notyet" "QF-ON2:list=notyet" "VN-ON1:voice-measure=notyet:too_soon"
dsnap104 s2   # steps 1–5 on, the chase not yet: kept (the live cases change the book; RH3 reads what they leave)
# Before the chase: the persona confirmed (a setting, the tool's set), as the operator would read B-1 first; then on chase,
# typed yes at the terminal.
OUT="$(sf104c set persona-confirmed "$(kday104 "$(fnow104)")")"; check "$(sfrc104)" "0" "5i set persona-confirmed to the clock's Kampala day: saved (a setting, never a switch)"
OUT="$(sf104c --tty yes on chase)"; CHON104="$(fnow104)"   # when the chase came on, by the clock: step 10's 72 h run from here
check "$(sfrc104)|$(has "$OUT" 'W2: installed')$(has "$OUT" 'wa_seen_marks: present')$(has "$OUT" 'persona: confirmed (sf_persona_confirmed set)')$(has "$OUT" 'Type yes to switch the quote chase on:')$(has "$OUT" "enquiry follow-ups now share each number's budget and the one-message-a-day rule (")|$(grep -c '^ON chase: quote_chase_enabled, quote_chase_not_before, sf_chase_first_on$' <<<"$OUT")" \
      "0|yesyesyesyesyes|1" \
  "5i' on chase through scripts/sales-followup.sh with a terminal, yes typed: tracking on 72 h with its need accepted, W2 and the marks there, the persona confirmed — ON, sf_chase_first_on written once; the enquiry interplay said"
OUT="$(sf104c on chase)"; check "$(sfrc104)|$(has "$OUT" "'on chase' reads from the terminal: run it in a terminal, never with its input piped")" "1|yes" \
  "…and the wrapper refuses on chase with no terminal (its input piped), changing nothing"
aft104 "5i'' after chase:" "QF-ON0:ack=notyet" "QF-ON3:ledger=empty" "VA-ON1:ack=notyet" "AC-ON1:agent-cc=judged" "QF-ON1:tracking=judged" "QF-ON2:list=notyet" "VN-ON1:voice-measure=notyet" "QF-ON4:chase=notyet"

# ── 5j–5r: Part F's live cases, on the main path with steps 1–6 on. A quotation sent to a fixture customer on the sales
# number; master's cycles over the next day on the clock — the chase drafted on day 1, held at its claim while the day's
# payment reminders have not run (ReminderDay), the reminder run recorded, then the chase sent through the stand-in
# Evolution; 25 s later an acknowledgement of another customer's voice note on the same handset (Part V's VK9), through the
# real webhook and its worker; a claim left in flight on another handset; the customer's wrapped reply through the real
# webhook, which stops the chase. Then --after-only: QF-ON0, QF-ON3 and VA-ON1 PASS, the orphan counted as not yet swept;
# --check tracking PASS with its two counts in a note; and the next `on` admitted on both paths. Every act is the release's:
# the quotation stored as the Send Quotation path stores its text (ConversationService::storeMessage), the cycles the
# installed cron/master.php, the replies the installed evo_webhook.php served on 127.0.0.1, the orphan WaSendBudget's own
# claim. Fictitious: RH104SECRET- names and texts, the rehearsal's own phones (added to the sweeps' pattern file). ──────
KATO104="+256700104025"; WASS104="+256700104024"; ORPH104="+256700104026"; QREF104="RH104SECRET-Q1"
python3 "$H104/rh104q.py" phones "[{\"phone\":\"$KATO104\"},{\"phone\":\"$WASS104\"},{\"phone\":\"$ORPH104\"}$(for i in $(seq 30 49); do printf ',{"phone":"+2567001040%s"}' "$i"; done)]" >> "$NAMES104"
# The webhook's token into a pattern file of the sweeps' (0600, the store's user's, written by the helper, never printed): no
# run, log or terminal may carry it.
: > "$SB/secrets104.pat"; chown 1000:1000 "$SB/secrets104.pat"; chmod 600 "$SB/secrets104.pat"
SEC104="$(live104 secrets "$PD" "$DATA" "$SB/secrets104.pat")"
check "$(qj "$SEC104" claude 2>/dev/null)|$(qj "$SEC104" openai 2>/dev/null)|$(qj "$SEC104" webhook 2>/dev/null)|$(qj "$SEC104" ai_enabled 2>/dev/null)" "unset|unset|set|on" \
  "control: no model provider's key is set (claude_api_key, openai_api_key), so the assistant can reach no provider (DishNetAiBrain::isConfigured() false) and nothing below calls one; the webhook's own secret is set (EvoWebhookGuard's autoSecret); ai_enabled on (step 2's precondition)"
LIVE104=no; [ "$(qj "$SEC104" claude 2>/dev/null)|$(qj "$SEC104" openai 2>/dev/null)|$(qj "$SEC104" webhook 2>/dev/null)" = "unset|unset|set" ] && LIVE104=yes
if [ "$LIVE104" = yes ]; then
  # The stand-in Evolution learns a voice note's bytes (its media answer), only while this rehearsal's file is there —
  # an asserted edit of its router; it still records every request it is sent.
  python3 -I - "$SB/evo/router.php" <<'PY' || echo "evo:media104" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]; s = open(p).read()
old = "header('Content-Type: application/json'); echo '[]';\n"
assert s.count(old) == 1 and s.count('rh104-media.b64') == 0
new = ("if (is_file(__DIR__ . '/rh104-media.b64') && strpos($_SERVER['REQUEST_URI'], '/chat/getBase64FromMediaMessage/') !== false) {   // 5.18.104's rehearsal: a voice note's bytes\n"
       "    header('Content-Type: application/json'); echo json_encode(['base64' => trim((string)file_get_contents(__DIR__ . '/rh104-media.b64')), 'mimetype' => 'audio/ogg; codecs=opus']); return;\n"
       "}\n" + old)
s = s.replace(old, new, 1)
assert s.count('rh104-media.b64') == 2 and s.count(old) == 1
open(p, 'w').write(s)
PY
  EV0_104="$(evo_calls)"
  M0_104="$(curl -s --noproxy '*' -X POST -d '{}' "$EVO_URL/chat/getBase64FromMediaMessage/rh-sales")"
  python3 "$H104/rh104q.py" ogg > "$SB/evo/rh104-media.b64"
  M1_104="$(curl -s --noproxy '*' -X POST -d '{}' "$EVO_URL/chat/getBase64FromMediaMessage/rh-sales" | python3 -I -c 'import json, sys, base64; d = json.load(sys.stdin); b = base64.b64decode(d["base64"]); print("ogg" if b[:4] == b"OggS" and b"OpusHead" in b else "other", d["mimetype"])' 2>/dev/null)"
  check "$EV0_104|$M0_104|$M1_104|$(( $(evo_calls) - EV0_104 ))" "0|[]|ogg audio/ogg; codecs=opus|2" \
    "control: the stand-in Evolution, called by nothing so far, answers '[]' for a voice note's bytes until this rehearsal's file is there, then a 7-second Ogg Opus note, base64 — and records both requests"
  # ── The stand-in Evolution, taught to answer a send as Evolution answers it — a message id (key.id) — only while this
  # (Moved here from 104-5o by the trials: 104-5's own live sends — the chase, VK9's acknowledgement — need the id too;
  # answered '[]', the acknowledgement is recorded sent with no id and VA-ON1 reads ledger_mismatch, trial 3.)
  # rehearsal's file rh104-send.on is beside its router (an asserted edit; it still records every request, by method and
  # path alone, and keeps the ids it gave, nothing else). Its '[]' carries no id, and the release records a test so answered
  # `uncertain`, never `sent` (EvolutionOfferGateway::send: MAYBE_SENT no_message_id), so no test could pass C-O1-1. The
  # file is left there for the rest of the rehearsal: 104-6's offer tests are sends too. ──
  python3 -I - "$SB/evo/router.php" <<'PY' || echo "evo:send104" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]; s = open(p).read()
old = "header('Content-Type: application/json'); echo '[]';\n"
assert s.count(old) == 1 and s.count('rh104-send.on') == 0, (s.count(old), s.count('rh104-send.on'))
new = ("if (is_file(__DIR__ . '/rh104-send.on') && preg_match('#/message/send(Text|Media)/#', $_SERVER['REQUEST_URI'])) {   // 5.18.104's rehearsal: a send answered as Evolution answers it\n"
       "    $n = (int)@file_get_contents(__DIR__ . '/rh104-send.n') + 1; file_put_contents(__DIR__ . '/rh104-send.n', (string)$n);\n"
       "    $b = json_decode((string)file_get_contents('php://input'), true); $num = preg_replace('/\\D/', '', (string)($b['number'] ?? ''));\n"
       "    $id = 'RH104EVO' . str_pad((string)$n, 6, '0', STR_PAD_LEFT); file_put_contents(__DIR__ . '/rh104-send.ids', $id . \"\\n\", FILE_APPEND);\n"
       "    header('Content-Type: application/json'); echo json_encode(['key' => ['remoteJid' => $num . '@s.whatsapp.net', 'fromMe' => true, 'id' => $id], 'status' => 'PENDING']); return;\n"
       "}\n" + old)
s = s.replace(old, new, 1)
assert s.count(old) == 1 and s.count('rh104-send.on') == 1 and s.count("'RH104EVO'") == 1
open(p, 'w').write(s)
PY
  EVS0_104="$(evo_calls)"
  ESA104="$(curl -s --noproxy '*' -X POST -H 'Content-Type: application/json' -d '{"number":"256700104050","text":"RH104SECRET-control"}' "$EVO_URL/message/sendText/rh-sales" 2>/dev/null)"
  : > "$SB/evo/rh104-send.on"
  ESB104="$(curl -s --noproxy '*' -X POST -H 'Content-Type: application/json' -d '{"number":"256700104050","text":"RH104SECRET-control"}' "$EVO_URL/message/sendText/rh-sales" 2>/dev/null | python3 -I -c 'import json, sys
try:
    d = json.load(sys.stdin); print("id" if str(d.get("key", {}).get("id", "")).startswith("RH104EVO") else "other")
except ValueError:
    print("unread")' 2>/dev/null)"
  check "$ESA104|$ESB104|$(( $(evo_calls) - EVS0_104 ))|$(grep -c '^RH104EVO' "$SB/evo/rh104-send.ids" 2>/dev/null)|$(grep -c 'RH104SECRET\|256700' "$SB/evo/requests.log" 2>/dev/null)" "[]|id|2|1|0" \
    "control: the stand-in Evolution answers a send '[]' (no message id) until this rehearsal's file is there, then as Evolution answers it, with a message id; both requests recorded, by method and path alone (no number, no text), the one id it gave kept"
  # The real webhook, the installed evo_webhook.php, served on 127.0.0.1 by PHP's own server as the plugin's user, under
  # the clock (its spawned workers inherit it).
  WHP104="$(free_port)"; WHU104="http://127.0.0.1:$WHP104/evo_webhook.php"
  setpriv --reuid 1000 --regid 1000 --clear-groups -- env LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" "$PHPBIN" -S "127.0.0.1:$WHP104" -t "$PD" >"$R104/webhook-server.log" 2>&1 & WHPID104=$!; PIDS+=("$WHPID104")
  for i in $(seq 1 50); do [ "$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "$WHU104")" = "405" ] && break; sleep 0.1; done
  check "$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "$WHU104")|$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -d '{"event":"messages.upsert"}' "$WHU104")" "405|401" \
    "control: the installed evo_webhook.php served on 127.0.0.1 — a GET refused (405), a POST without its token refused (401)"
  # The quote day: the first Kampala day at least an hour on whose 08:20 is ahead of the clock, neither it nor the next
  # day a Sunday (the chase's window skips Sundays: QuoteChasePolicy::inWindow)
  F3_104="$(fnow104)"; QD104=""
  for d in 0 1 2 3 4 5 6 7; do
    t="$(kat104 "$(kday104 $(( F3_104 + 3600 + d * 86400 ))) 08:20")"
    [ "$t" -gt $(( F3_104 + 600 )) ] && [ "$(kdow104 "$t")" != "7" ] && [ "$(kdow104 $(( t + 86400 )))" != "7" ] && { QD104="$(kday104 "$t")"; break; }
  done
  QD1_104="$(kday104 $(( $(kat104 "$QD104 12:00") + 86400 )))"; QD3_104="$(kday104 $(( $(kat104 "$QD104 12:00") + 3 * 86400 )))"
  check "$(epoch99 "$(kat104 "$QD104 08:20")")|$( [ "$(kdow104 "$(kat104 "$QD104 08:20")")" != 7 ] && [ "$(kdow104 "$(kat104 "$QD1_104 08:20")")" != 7 ] && echo weekdays)|$(python3 -I -c 'import sys; print("in" if 8 <= 8 < 20 else "out")' )" \
        "epoch|weekdays|in" "control: the quote day ($QD104) and its day 1 ($QD1_104), neither a Sunday, ahead of the clock; 08:20 Kampala inside the window the ledger reads by default ($(kv104 "$LAD104" window))"
  # 5j: the quotation, at QD 08:20 Kampala, five minutes after the customer's own question (spoke-to); cycle A at 08:25:
  # quote_chase tracks it and opens its chase.
  clk_to104 "$(kat104 "$QD104 08:20")"
  Q104="$(live104 quote "$PD" "$DATA" "{\"phone\":\"$KATO104\",\"channel\":\"sales\",\"name\":\"RH104SECRET-Kato\",\"ref\":\"$QREF104\",\"in_text\":\"RH104SECRET-the price for the kit please\",\"gap_min\":5}")"
  check "$(python3 -I -c 'import json, sys; d = json.loads(sys.argv[1]); print("ids" if d["conv"] > 0 and d["in"] > 0 and d["quote"] > 0 else "missing")' "$Q104" 2>/dev/null || echo "unread:${Q104:0:80}")" "ids" \
    "5j the quotation sent to a fixture customer on the sales number, as the Send Quotation path stores its text — the customer's question five minutes before it — through the installed ConversationService"
  clk_to104 "$(kat104 "$QD104 08:25")"; MA104="$(master104 A quote_chase followup_send)"
  TA104="$(python3 "$H104/rh104q.py" tracker "$DATA/plugin.sqlite3" "ref:$QREF104")"
  check "$MA104|$TA104" "0|status=open hold=- followup=quote closed=open drafts=0/0" \
    "5j' cycle A (QD 08:25), the installed master dispatching quote_chase: the quotation tracked (open, no hold), its chase opened as a quote follow-up, nothing drafted before day 1"
  # 5k: day 1. Cycle B (08:25) drafts the chase; cycle C (08:31) — no reminder run today at or after the reminder hour —
  # claims nothing (reminders_first: a chase never goes before a reminder the same day). Cycle D (09:05): a reminder run
  # IN PROGRESS — master_schedule's customer_reminders entry in master's own in-flight form, last_run 09:02 today and
  # duration_ms -1, as cron/master.php writes it before it includes the job. A sandbox cycle cannot hold a job open while
  # another reads the schedule (master runs its jobs one after another in one process), so that entry is written through
  # the store's own save with the cycle's plant (master104 --set; a state no writer makes for this purpose, said here, as
  # the plant itself is); master does not dispatch the job again that day (JobWindow: once a day, its last_run today's),
  # so the entry is still in flight when followup_send reads it: held. Cycle E (09:11): the same run recorded FINISHED, as
  # master records it after the job (the same last_run, its duration written), with that cycle's plant: the chase is
  # claimed and sent, after the finish. Day 3's cycle (5n') runs customer_reminders itself, master's own run recorded.
  # Each cycle's followup_send entry is that cycle's own — the positive control that the sender ran and chose to hold.
  RMH104="$(kv104 "$RC104" remh)"; RMF104="$(n103 "${RMH104%%-*}")"; RMU104="$(n103 "${RMH104##*-}")"   # the pin's ReminderDay::hours() on the settings
  RMO104="$(kat104 "$QD1_104 $(printf '%02d' "$RMF104"):00")"; RMS104="$(kat104 "$QD1_104 09:02")"; RMD104="$(kat104 "$QD1_104 09:09")"
  ran104() { local e; e="$(sched104 "$1")"; [ "$e" != none ] && [ "$(n103 "${e%%:*}")" -ge "$2" ] && [ "${e##*:}" != "-1" ] && echo ran || echo "not:$e"; }   # ran104 JOB FROM — its entry this cycle's, finished
  check "$(epoch99 "$RMO104")|$( [ "$(kat104 "$QD1_104 08:31")" -lt "$RMO104" ] && [ "$RMO104" -le "$RMS104" ] && [ "$(kat104 "$QD1_104 09:15")" -lt "$(kat104 "$QD1_104 $(printf '%02d' "$RMU104"):00")" ] && echo placed)" "epoch|placed" \
    "control: the reminder hours the pin's ReminderDay::hours() reads from the settings ($RMH104) open between cycle C (08:31) and the in-flight run's start (09:02) and close after the day's last cycle — the cycles below straddle the reminder hour"
  clk_to104 "$(kat104 "$QD1_104 08:25")"; MB104="$(master104 B quote_chase followup_send quote_daily_list)"
  TB104="$(python3 "$H104/rh104q.py" tracker "$DATA/plugin.sqlite3" "ref:$QREF104")"
  clk_to104 "$(kat104 "$QD1_104 08:31")"; MC104="$(master104 C followup_send quote_daily_list)"
  LC104="$(ledger104)"; TC104="$(python3 "$H104/rh104q.py" tracker "$DATA/plugin.sqlite3" "ref:$QREF104")"; SC104="$(sched104 customer_reminders)"
  check "$MB104|$(kv104 "$TB104" drafts)|$MC104|$(ran104 followup_send "$(kat104 "$QD1_104 08:31")")|$(kv104 "$LC104" quote)|$(kv104 "$TC104" drafts)|$(case "$SC104" in none) echo notrun ;; *) [ "$(n103 "${SC104%%:*}")" -lt "$RMO104" ] && echo notrun || echo ran ;; esac)" \
        "0|1/1|0|ran|0/0|1/1|notrun" \
    "5k day 1 on the clock: cycle B drafts the chase, approved as quote_chase; cycle C (08:31) — followup_send ran (its entry this cycle's), no reminder run recorded today at or after the reminder hour (master_schedule's entry is this cycle's own plant, before the hour) — makes no quote claim and leaves the draft approved: held (reminders_first, ReminderDay)"
  clk_to104 "$(kat104 "$QD1_104 09:05")"
  MD104="$(master104 D --set "{\"customer_reminders\":{\"last_run\":$RMS104,\"duration_ms\":-1}}" quote_chase followup_send quote_daily_list)"
  LD104="$(ledger104)"; TD104="$(python3 "$H104/rh104q.py" tracker "$DATA/plugin.sqlite3" "ref:$QREF104")"; SD104="$(sched104 customer_reminders)"
  check "$MD104|$(ran104 followup_send "$(kat104 "$QD1_104 09:05")")|$SD104|$(kv104 "$LD104" quote)|$(kv104 "$TD104" drafts)" "0|ran|$RMS104:-1|0/0|1/1" \
    "5k' cycle D (09:05): a reminder run in progress — customer_reminders' entry today after the reminder hour with duration_ms -1 (master's in-flight form, through the store's own save), not run again by master and still in flight after the cycle — followup_send ran and made no quote claim, the draft still approved: held until the run finishes"
  clk_to104 "$(kat104 "$QD1_104 09:11")"; EVE0_104="$(grep -c '/message/sendText/rh-sales' "$SB/evo/requests.log" 2>/dev/null)"
  ME104="$(master104 E --set "{\"customer_reminders\":{\"last_run\":$RMS104,\"duration_ms\":$(( (RMD104 - RMS104) * 1000 ))}}" followup_send quote_daily_list)"
  LE104="$(ledger104)"; QAT104="$(kv104 "$LE104" quote_at)"; SE104="$(sched104 customer_reminders)"
  check "$ME104|$SE104|$(kv104 "$LE104" quote)|$(( $(grep -c '/message/sendText/rh-sales' "$SB/evo/requests.log" 2>/dev/null) - $(n103 "$EVE0_104") ))|$( [ "$(n103 "$QAT104")" -ge "$RMD104" ] && echo after-the-reminders)" \
        "0|$RMS104:$(( (RMD104 - RMS104) * 1000 ))|1/1|1|after-the-reminders" \
    "5k'' cycle E (09:11): the same reminder run recorded finished (09:02, its duration written, as master records it after the job) — the chase sent: one quote claim on the ledger, settled sent, one sendText to the sales instance at the stand-in Evolution, claimed after the run finished"
  # 5l: VK9 — 25 s after the chase's claim, another customer's voice note to the same number through the real webhook;
  # its worker (spawned by the webhook, under the clock) claims the acknowledgement on the same handset.
  clk_to104 $(( $(n103 "$QAT104") + 25 ))
  W1_104="$(live104 webhook "$PD" "$DATA" "$WHU104" "{\"channel\":\"sales\",\"phone\":\"$WASS104\",\"name\":\"RH104SECRET-Wasswa\",\"kind\":\"audio\",\"text\":\"\",\"id\":\"RH104VK9A\"}")"
  for i in $(seq 1 120); do [ "$(kv104 "$(ledger104)" voice_ack)" -ge 1 ] 2>/dev/null && break; sleep 0.5; done
  LK104="$(ledger104)"; DK104="$(kv104 "$LK104" ack_after_quote_s)"
  check "$(qj "$W1_104" http 2>/dev/null)|$(kv104 "$LK104" voice_ack)|$( [ "$(n103 "$DK104")" -ge 20 ] && [ "$(n103 "$DK104")" -le 45 ] && echo "25s-ish")" "200|1|25s-ish" \
    "5l Part V's VK9: a voice note to the sales number 25 s after the chase, through the real webhook — its acknowledgement claimed on the same handset ${DK104:-?} s after the chase's claim (the ack's own gap, not the proactive spacing)"
  # 5m: a claim left in flight — WaSendBudget's own claim on the support line, never settled; no later claim on its
  # handset. It is the orphan L-ON and QF-ON3 count as not yet swept once 900 s have passed.
  clk_to104 $(( $(n103 "$QAT104") + 40 ))
  OC104="$(live104 claim "$PD" "$DATA" "{\"channel\":\"support\",\"kind\":\"enquiry\",\"ref\":\"rh104-orphan-1\",\"phone\":\"$ORPH104\"}")"
  check "$(qj "$OC104" ok 2>/dev/null)|$( [ "$(n103 "$(qj "$OC104" claim 2>/dev/null)")" -ge 1 ] && echo claim)|$(kv104 "$(ledger104)" open_claims)" "True|claim|1" \
    "5m a claim through the installed WaSendBudget on the support line, never settled: one claim in flight, the only one"
  # 5n: the customer's wrapped reply (an ephemeralMessage) through the real webhook, ten minutes after the chase; cycle F
  # tracks it: the quotation answered, its chase closed as replied; cycle G on day 3 sends nothing more.
  clk_to104 $(( $(n103 "$QAT104") + 600 )); MK0_104="$(n103 "$(sq "SELECT COUNT(*) FROM wa_seen_marks WHERE direction = 'in'")")"
  W2_104="$(live104 webhook "$PD" "$DATA" "$WHU104" "{\"channel\":\"sales\",\"phone\":\"$KATO104\",\"name\":\"RH104SECRET-Kato\",\"kind\":\"wrapped\",\"text\":\"RH104SECRET-yes thank you\",\"id\":\"RH104WRAP1\"}")"
  idle104; clk_to104 $(( $(n103 "$QAT104") + 1200 )); MF104="$(master104 F quote_chase followup_send quote_daily_list)"
  TF104="$(python3 "$H104/rh104q.py" tracker "$DATA/plugin.sqlite3" "ref:$QREF104")"
  check "$(qj "$W2_104" http 2>/dev/null)|$MF104|$(kv104 "$TF104" status)|$(kv104 "$TF104" closed)|$( [ "$(n103 "$(sq "SELECT COUNT(*) FROM wa_seen_marks WHERE direction = 'in'")")" -gt "$MK0_104" ] && echo marked)" "200|0|answered|replied|marked" \
    "5n the customer's wrapped reply through the real webhook — stored, its inbound mark written (W1) — stops the chase: cycle F reads the quotation answered and closes its chase as replied"
  clk_to104 "$(kat104 "$QD3_104 09:15")"; MG104="$(master104 G quote_chase followup_send customer_reminders quote_daily_list)"
  check "$MG104|$(kv104 "$(ledger104)" quote)|$(ran104 customer_reminders "$(kat104 "$QD3_104 $(printf '%02d' "$RMF104"):00")")" "0|1/1|ran" \
    "5n' …and day 3's cycle sends nothing more: still the one quote claim; that cycle master runs customer_reminders itself (its entry an earlier day's, so JobWindow finds it due) — its own run recorded today after the reminder hour, finished"
  # 5o: --after-only, on day 3: QF-ON0 (step 2's one status, L-ON with VA-ON1) PASS with the orphan counted as not yet
  # swept, QF-ON3 PASS — the acknowledgement left to VA-ON1, the orphan counted — VA-ON1 PASS, the chase's Q3-ON PASS.
  clk_to104 "$(kat104 "$QD3_104 10:00")"
  aft104 "5o day 3:" "QF-ON0:ack=pass" "QF-ON3:ledger=pass" "VA-ON1:ack=pass" "AC-ON1:agent-cc=judged" "QF-ON1:tracking=pass" "QF-ON2:list=judged" "VN-ON1:voice-measure=judged" "QF-ON4:chase=pass"
  check "$(okline "$AFT104" QF-ON0 'ack: PASS' 'orphans_unswept=1')$(okline "$AFT104" QF-ON3 'orphans_unswept=1' 'voice_ack_left_to_VA-ON1=1')$(okline "$AFT104" QF-ON3 'outside_window=0,cap_day=0,cap_hour=0,spacing=0,phone_today=0,ledger_orphan=0')" "yesyesno" \
    "5o' …QF-ON0's counts and QF-ON3's carry the claim in flight as orphans_unswept=1 — counted, never FAIL — and QF-ON3 leaves the acknowledgement to VA-ON1 (Q15-F02, Q16-F06); no failure code"
  check "$(grep -lF -f "$NAMES104" "$R104/run-$AFTT104.txt" "$OUT104"/*.log "$R104/webhook-server.log" 2>/dev/null | grep -c .)|$(grep -lF -f "$SB/secrets104.pat" "$R104/run-$AFTT104.txt" "$OUT104"/*.log "$R104/webhook-server.log" "$R104"/logs/master-*.out 2>/dev/null | grep -c .)|$(grep -c . "$SB/secrets104.pat")" "0|0|1" \
    "5o'' no fixture's name or phone in the run's terminal, its log or the webhook's server log; the webhook's token in none of them nor in master's output (the control: the pattern file holds it)"
  # 5p: twenty more voice notes to the sales number, 25 s apart, each through the real webhook — their bytes fetched by the
  # media worker it spawns (the stand-in's Ogg note), acknowledged — so that VN-ON1 has its 20 measured rows.
  for i in $(seq 0 19); do
    clk_to104 $(( $(kat104 "$QD3_104 10:05") + 25 * i ))
    live104 webhook "$PD" "$DATA" "$WHU104" "{\"channel\":\"sales\",\"phone\":\"+2567001040$(( 30 + i ))\",\"name\":\"RH104SECRET-Caller\",\"kind\":\"audio\",\"text\":\"\",\"id\":\"RH104VN$i\"}" >> "$R104/logs/vn-posts.json"; echo >> "$R104/logs/vn-posts.json"
    idle104   # each note's workers finished before the clock moves on: the acknowledgements' gaps are the clock's
  done
  for i in $(seq 1 120); do [ "$(sq "SELECT COUNT(*) FROM wa_media WHERE kind = 'audio' AND fetched_bytes IS NOT NULL")" -ge 21 ] 2>/dev/null && break; sleep 0.5; done
  check "$(grep -c '"http":200' "$R104/logs/vn-posts.json")|$(sq "SELECT COUNT(*) FROM wa_media WHERE kind = 'audio' AND fetched_bytes IS NOT NULL")" "20|21" \
    "5p twenty voice notes through the real webhook, each 200; with the first, 21 audio rows whose bytes the media worker fetched from the stand-in"
  # 5q: --check tracking with chases running: PASS, its two counts a note (rule 3: step 6's own chases never turn step 3
  # to FAIL); --check voice-measure PASS; the next `on` admitted on the main path — on voice-dry-run, voice-measure at PASS —
  # by the order, the running checks and its needs, and refused by its own precondition: the privacy line, which nothing
  # has set yet (the release's VoiceSteps reads it before the OpenAI key); nothing saved. The book is then kept as mainend:
  # steps 1–6 on, step 7 neither on nor recorded skipped, its privacy line unset — the book 104-5v, 104-5o and 104-5p
  # read (the privacy line set, and the key refusal behind it, are 104-5v's cases on that book; 104-5o skips step 7 on
  # its own copy, its cascade recording 8 and 9, before it asks for offers-test). Steps 7–12 below run on the live folder
  # after it, which 5u replaces.
  # 5p': the last note's acknowledgement decided. The release keeps acknowledgements on a number its gap apart
  # (wa_voice_ack_gap_s, 20 s) from the last claim on it; a note's worker that claims a few seconds late leaves the next
  # acknowledgement deferred by `spacing`, which the next note's worker takes — but the twentieth has no next note (the
  # record trial: one left pending, counted in 104-6's rollback reading). One more run of the installed reply worker,
  # spawned as the webhook spawns it (its user, its clock), 55 s after the last note, takes it as that next spawn would.
  clk_to104 $(( $(kat104 "$QD3_104 10:05") + 25 * 19 + 55 ))
  ( cd "$PD" && setpriv --reuid 1000 --regid 1000 --clear-groups -- env LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" "$PHPBIN" "$PD/run_worker.php" >/dev/null 2>&1 )
  idle104
  check "$(sq "SELECT COUNT(*) FROM events WHERE event_type = 'ai.voice_ack' AND status <> 'done'")|$( [ "$(n103 "$(sq "SELECT COUNT(*) FROM events WHERE event_type = 'ai.voice_ack'")")" -ge 21 ] && echo all21)" "0|all21" \
    "5p' every voice note's acknowledgement decided, none left pending (the 21 notes' events at least, VK9's and 5p's twenty): a last one deferred by the gap taken by one more run of the installed reply worker, as the webhook's next spawn would take it"
  X104=$(( $(kat104 "$QD3_104 10:05") + 25 * 20 + 60 )); [ "$(fnow104)" -ge "$X104" ] || clk_to104 "$X104"   # forward only: the drain above may already have taken the clock past it
  OUT="$(sf104c --check tracking)"
  check "$(sfrc104)|$(grep -cx 'tracking: PASS' <<<"$OUT")|$(grep -cx '    note: since the chase first came on: 1 quote follow-ups opened, 1 quote claims (step 6'"'"'s, judged by Q3-ON)' <<<"$OUT")" "0|1|1" \
    "5q --check tracking with chases running: PASS — Q1-ON's two counts printed as a note line (1 quote follow-up opened, 1 quote claim, step 6's), never judged (§H.2.12.3 rule 3)"
  OUT="$(sf104c --check voice-measure)"; check "$(sfrc104)|$(grep -cx 'voice-measure: PASS' <<<"$OUT")" "0|1" "5q' --check voice-measure: VN-ON1 PASS (its 21 measured rows, nothing R1 did to anyone)"
  PLK104="$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" ai_media_voice_privacy_line,quote_chase_enabled)" set)"
  OUT="$(sf104c on voice-dry-run)"
  check "$PLK104|$(sfrc104)|$(has "$OUT" '  step 5 (voice-measure): VN-ON1 PASS')$(has "$OUT" '  ai_media_voice_privacy_line: not a date on or before today')$(has "$OUT" 'REFUSED privacy_line_missing — nothing was saved')$(has "$OUT" 'openai key: ')$(has "$OUT" 'earlier_fail')|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" ai_media_voice,ai_transcription_provider,ai_media_voice_privacy_line,sf_skip_voice-dry-run)" set)" \
        "1|2|yesyesyesnono|0" \
    "5q'' the next on, on the main path, the privacy line not set (this rehearsal's reading: of the line and the chase's switch, only the switch set): on voice-dry-run admitted by the order, no earlier FAIL running and its pass_only need (step 5: VN-ON1 PASS) — then refused by its own precondition, privacy_line_missing, before the key is read; nothing saved"
  idle104; dsnap104 main5   # the main path with the chase run: kept for the skip-voice-measure path, then put back
  dsnap104 mainend          # …and the same book as the main path's end: steps 1–6 on, step 7 neither on nor skipped (5z, RH3)
fi

# ── 5r: the main path on to step 12, on the live folder after mainend was kept (5q''): nothing here reaches the book the
# later sections read, and 5u replaces the folder. Step 7 cannot come on here (the OpenAI key is never stored by this
# rehearsal, so nothing can call OpenAI): it is skipped, the skip recording 8 and 9 with it (the ladder's own cascade).
# Then step 10 (cold, optional) once the chase has been on 72 h by the clock, its statement typed at the terminal; step
# 11; step 12 refused by its need, step 11's check not yet PASS. --after-only after each. ─────────────────────────────
lbl104() {   # lbl104 STEP… — show's labels of the steps, as an alternation (the ladder's own numbers, from labels=)
  local x out=""; for x in "$@"; do out="$out|$(kv104 "$LAD104" labels | tr '|' '\n' | awk -F: -v s="$x" '$2 == s { print $1 " " $2 }')"; done; echo "${out#|}"; }
if [ "$LIVE104" = yes ]; then
  SKVM104="$(kv104 "$LAD104" skip_voice-measure)"; SKVD104="${SKVM104#*,}"
  check "${SKVM104%%,*}|$( [ "$SKVD104" != "$SKVM104" ] && echo rest)" "voice-dry-run|rest" \
    "control: the ladder's cascade from voice-measure starts with voice-dry-run (its only dependant), so voice-dry-run's own cascade is the rest of it: ${SKVD104//,/, }"
  OUT="$(sf104c skip voice-dry-run --reason "no OpenAI key in the rehearsal")"
  check "$(sfrc104)|$(grep -cxF "SKIPPED voice-dry-run; also skipped: ${SKVD104//,/, }" <<<"$OUT")|$(grep -cE "^  ($(lbl104 voice-dry-run ${SKVD104//,/ })): skipped \\(" <<<"$(sf104c show)")" "0|1|$(( 1 + $(tr ',' '\n' <<<"$SKVD104" | grep -c .) ))" \
    "5r skip voice-dry-run: SKIPPED, with ${SKVD104//,/ and } recorded skipped by the cascade — show reads the three skipped"
  aft104 "5r' after the skip:" "QF-ON0:ack=pass" "QF-ON3:ledger=pass" "VA-ON1:ack=pass" "AC-ON1:agent-cc=judged" "QF-ON1:tracking=pass" "QF-ON2:list=judged" "VN-ON1:voice-measure=pass" "QF-ON4:chase=pass"
  F5R104="$(fnow104)"; [ "$F5R104" -ge $(( CHON104 + 73 * 3600 )) ] || clk_to104 $(( CHON104 + 73 * 3600 ))
  # The ON line as the tool composes it (sf_on: the statement it asked for is written first, then the row's switch keys and
  # its stamp — the release's ladder read through rh104-ref.php's on_cold=), never a literal order.
  ONCOLD104="ON cold: $(kv104 "$LAD104" on_cold | sed 's/,/, /g')"
  OUT="$(sf104c --tty "support assistant on, the rehearsal statement" on cold)"
  check "$(sfrc104)|$(has "$OUT" "  step 6 (chase): Q3-ON PASS")$(has "$OUT" "Type the state of support's assistant, as it is now (one line, no number):")|$(grep -cxF "$ONCOLD104" <<<"$OUT")|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" "$(kv104 "$LAD104" on_cold)")" set)" \
        "0|yesyes|1|$(kv104 "$LAD104" on_cold | tr ',' '\n' | grep -c .)" \
    "5s on cold, the chase on 72 h by the clock and its Q3-ON PASS: the statement asked for and typed at the terminal — ON, its line in sf_on's order ($ONCOLD104), every key it names set by this rehearsal's own reading"
  aft104 "5s' after cold:" "QF-ON0:ack=pass" "QF-ON3:ledger=pass" "VA-ON1:ack=pass" "AC-ON1:agent-cc=judged" "QF-ON1:tracking=pass" "QF-ON2:list=judged" "VN-ON1:voice-measure=pass" "QF-ON4:chase=pass" "QF-ON4:cold=judged"
  OUT="$(sf104c on offers-test)"
  check "$(sfrc104)|$(has "$OUT" '  the ledger: L-ON PASS')$(has "$OUT" '  step 3 (tracking): Q1-ON PASS')$(has "$OUT" 'P-O1-7 active admins with a phone: ')|$(grep -c '^ON offers-test: offers_test_enabled$' <<<"$OUT")" "0|yesyesyes|1" \
    "5t on offers-test: its two needs met (the ledger's L-ON PASS, step 3's Q1-ON PASS), Part O's precondition read (an active admin with a phone) — ON"
  aft104 "5t' after offers-test:" "QF-ON0:ack=pass" "QF-ON3:ledger=pass" "VA-ON1:ack=pass" "AC-ON1:agent-cc=judged" "QF-ON1:tracking=pass" "QF-ON2:list=judged" "VN-ON1:voice-measure=pass" "QF-ON4:chase=pass" "QF-ON4:cold=judged" "OF-ON1:offers-test=judged"
  OUT="$(sf104c on offers-live)"
  check "$(sfrc104)|$(grep -cE '^REFUSED need_not_(yet|pass):offers-test — nothing was saved$' <<<"$OUT")|$(grep -c '^  step 11 (offers-test): offers-test reads NOT_YET ' <<<"$OUT")|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" offers_live_enabled,offers_live_since)" set)" "2|1|1|0" \
    "5t'' on offers-live while step 11's check reads NOT_YET (no test offer sent): REFUSED by its need, nothing saved — the order and the needs are the engine's"

  # ── 5u: the skip-voice-measure path, on the copy kept before step 5 (s15): skip voice-measure, its cascade with it;
  # then on chase — the order passes a skipped step 5 — and on offers-test, both admitted and ON. ─────────────────────
  check "$(drest104 s15)" "same|same|same" "5u the skip-voice-measure path: the data folder and the vault put back as kept before step 5 (steps 1–4 on)"
  OUT="$(sf104c skip voice-measure --reason "voice later, the rehearsal")"
  check "$(sfrc104)|$(grep -cxF "SKIPPED voice-measure; also skipped: ${SKVM104//,/, }" <<<"$OUT")" "0|1" "5u' skip voice-measure: SKIPPED, ${SKVM104//,/, } recorded skipped with it"
  aft104 "5u'' after the skip:" "QF-ON0:ack=judged" "QF-ON3:ledger=empty" "VA-ON1:ack=judged" "AC-ON1:agent-cc=judged" "QF-ON1:tracking=notyet" "QF-ON2:list=notyet"
  OUT="$(sf104c set persona-confirmed "$(kday104 "$(fnow104)")")"; check "$(sfrc104)" "0" "…set persona-confirmed on this copy too (a setting)"
  OUT="$(sf104c --tty yes on chase)"
  check "$(sfrc104)|$(has "$OUT" '  step 3 (tracking): Q1-ON NOT_YET no_traffic, accepted')|$(grep -c '^ON chase: quote_chase_enabled, quote_chase_not_before, sf_chase_first_on$' <<<"$OUT")" "0|yes|1" \
    "5v on chase with step 5 skipped: the order passes it — tracking's NOT_YET no_traffic accepted (recorded at step 4), ON"
  OUT="$(sf104c on offers-test)"
  check "$(sfrc104)|$(has "$OUT" '  the ledger: L-ON PASS')$(has "$OUT" '  step 3 (tracking): Q1-ON NOT_YET no_traffic, accepted')|$(grep -c '^ON offers-test: offers_test_enabled$' <<<"$OUT")" "0|yesyes|1" \
    "5v' …and on offers-test: steps 7–9 skipped with 5, step 10 optional and off — the order passes them; its needs met — ON"
  aft104 "5v'' after offers-test:" "QF-ON0:ack=judged" "QF-ON3:ledger=empty" "VA-ON1:ack=judged" "AC-ON1:agent-cc=judged" "QF-ON1:tracking=notyet" "QF-ON2:list=notyet" "QF-ON4:chase=notyet" "OF-ON1:offers-test=judged"

  # ── 5w: the same skip with chases running, on the copy kept after the live cases (main5): voice-measure turned off (the
  # tool's off, its hook), then skipped; --check tracking still PASS with its note, and the next on — offers-test, step 10
  # optional and off — admitted. ────────────────────────────────────────────────────────────────────────────────────
  check "$(drest104 main5)" "same|same|same" "5w the main path's copy after the live cases (main5) put back: the chase sent, answered and closed, the voice notes measured"
  OUT="$(sf104c off voice-measure)"; check "$(sfrc104)|$(grep -cx 'OFF voice-measure' <<<"$OUT")" "0|1" "5w' off voice-measure: OFF, its hook run"
  OUT="$(sf104c skip voice-measure --reason "voice later, the rehearsal")"
  check "$(sfrc104)|$(grep -cxF "SKIPPED voice-measure; also skipped: ${SKVM104//,/, }" <<<"$OUT")" "0|1" "…then skipped, ${SKVM104//,/, } with it"
  OUT="$(sf104c --check tracking)"
  check "$(sfrc104)|$(grep -cx 'tracking: PASS' <<<"$OUT")|$(grep -cx '    note: since the chase first came on: 1 quote follow-ups opened, 1 quote claims (step 6'"'"'s, judged by Q3-ON)' <<<"$OUT")" "0|1|1" \
    "5w'' with chases running and voice-measure skipped: --check tracking PASS, its two counts a note line"
  OUT="$(sf104c on offers-test)"
  check "$(sfrc104)|$(has "$OUT" '  step 3 (tracking): Q1-ON PASS')|$(grep -c '^ON offers-test: offers_test_enabled$' <<<"$OUT")" "0|yes|1" "5w''' …and the next on, offers-test: admitted on this path too — ON"
  aft104 "5w'''' after offers-test:" "QF-ON0:ack=pass" "QF-ON3:ledger=pass" "VA-ON1:ack=pass" "AC-ON1:agent-cc=judged" "QF-ON1:tracking=pass" "QF-ON2:list=judged" "QF-ON4:chase=pass" "OF-ON1:offers-test=judged"
fi

# ── 5x: the skip-tracking path, on the copy kept with step 2 on a day (s1): skip tracking — the ladder's cascade records
# 4, 6, 10, 11 and 12 skipped with it (ruling R6: offers need tracking too); on voice-measure, the order passing the
# skipped 3 and 4; on chase refused, its need recorded skipped. ────────────────────────────────────────────────────────
SKTR104="$(kv104 "$LAD104" skip_tracking)"
check "$(tr ',' '\n' <<<"$SKTR104" | grep -cxE 'offers-test|offers-live')|$(tr ',' '\n' <<<"$SKTR104" | grep -cx chase)" "2|1" \
  "control: the ladder's own cascade from tracking holds chase and both offer steps (ruling R6): ${SKTR104//,/, }"
check "$(drest104 s1)" "same|same|same" "5x the skip-tracking path: the data folder and the vault put back as kept with step 2 on a day (5e')"
OUT="$(sf104c skip tracking --reason "no quotations yet, the rehearsal")"
SHOWX104="$(sf104c show)"
NSK104="$(( $(tr ',' '\n' <<<"$SKTR104" | grep -c .) + 1 ))"
check "$(sfrc104)|$(grep -cxF "SKIPPED tracking; also skipped: ${SKTR104//,/, }" <<<"$OUT")|$(grep -cE ': skipped \([0-9]{4}-[0-9]{2}-[0-9]{2} [0-9:]{8}\)' <<<"$SHOWX104")|$(grep -cE "^  ($(lbl104 offers-test offers-live)): skipped \\(" <<<"$SHOWX104")" "0|1|$NSK104|2" \
  "5x' skip tracking: SKIPPED, ${SKTR104//,/, } recorded with it — show reads $NSK104 steps skipped, steps 11 and 12 among them (R6)"
aft104 "5x'' after the skip:" "QF-ON0:ack=judged" "QF-ON3:ledger=empty" "VA-ON1:ack=judged" "AC-ON1:agent-cc=judged"
OUT="$(sf104c on voice-measure)"; check "$(sfrc104)|$(grep -c '^ON voice-measure: ai_media_enabled, ai_media_since$' <<<"$OUT")" "0|1" "5y on voice-measure: the order passes the skipped steps 3 and 4 — ON"
OUT="$(sf104c --tty yes on chase)"
check "$(sfrc104)|$(has "$OUT" 'REFUSED need_off:tracking — nothing was saved')$(has "$OUT" '  step 3 (tracking) is not on (recorded skipped, which no need accepts)')|$(grep -c '^ON chase' <<<"$OUT")" "2|yesyes|0" \
  "5y' on chase on this path: REFUSED need_off:tracking — a skipped need is never met; nothing saved"
aft104 "5y'' after voice-measure:" "QF-ON0:ack=judged" "QF-ON3:ledger=empty" "VA-ON1:ack=judged" "AC-ON1:agent-cc=judged" "VN-ON1:voice-measure=notyet"
if [ "$LIVE104" = yes ]; then
  check "$(drest104 mainend)|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" quote_chase_enabled,ai_media_voice,sf_skip_voice-dry-run,quote_chase_cold,offers_test_enabled,ai_media_voice_privacy_line)" set)" "same|same|same|1" \
    "5z the main path put back as the live cases left it (mainend, kept at 5q''): steps 1–6 on, step 7 neither on nor recorded skipped, cold and offers-test off, the privacy line unset (of those keys only the chase's switch set) — the book 104-5v, 104-5o and 104-5p read"
else
  check "$(drest104 s2)" "same|same|same" "5z the live cases did not run (see their control): the main path put back at steps 1–5 (s2)"
fi
# The live cases' stand-ins stopped: the webhook's server; the stand-in Evolution's requests the live acts caused moved
# into this rehearsal's own log (counted there), so that 104-10's reading of what the deploys sent starts from none; the
# media fixture removed (the router's edit is inert without it); the helper's error file removed.
if [ -n "${WHPID104:-}" ]; then kill "$WHPID104" 2>/dev/null; wait "$WHPID104" 2>/dev/null; fi
idle104
EVL104="$(evo_calls)"; [ ! -f "$SB/evo/requests.log" ] || mv "$SB/evo/requests.log" "$R104/evo-live.log"; rm -f "$SB/evo/rh104-media.b64"
check "$( [ -z "${WHPID104:-}" ] || { kill -0 "$WHPID104" 2>/dev/null && echo running || echo stopped; } )|$( [ "$LIVE104" != yes ] || [ "$(n103 "$EVL104")" -ge 2 ] && echo recorded)|$(evo_calls)|$(grep -c 'sendText' "$R104/evo-live.log" 2>/dev/null | grep -c '^[1-9]')|$( [ -e "$SB/evo/rh104-media.b64" ] && echo media || echo none)" \
      "$( [ "$LIVE104" = yes ] && echo stopped)|recorded|0|$( [ "$LIVE104" = yes ] && echo 1 || echo 0)|none" \
  "5z' the webhook's server stopped; the $EVL104 requests the live acts sent to the stand-in Evolution (their sendText among them) kept in this rehearsal's own log — none of them a deploy's (each --after-only above added none); the media fixture gone"
rm -f "$H104/v/live.err" "$H104/v/live-vault.json"
check "$(ls -A "$H104/v" | grep -c '^live')|$(grep -lF -f "$NAMES104" "$OUT104"/*.log 2>/dev/null | grep -c .)" "0|0" "…no vault copy or error file of the live helper's left; no fixture name or phone in any log of the runs"
# ── 104-5v: the voice rungs, in the one order, past the ladder (104-5's mainend). The acknowledgement (2, VA-ON1) and
# voice-measure (5, VN-ON1) are on and at PASS from the live cases (5o, 5p); voice-dry-run (7) is refused until the privacy
# line is recorded (`set ai_media_voice_privacy_line <date>`, a date not after today), then refused by its OpenAI key; the
# key set; and 7, 8 and 9 come on in the one order, each fed voice notes through the real webhook and judged at PASS by
# --after-only (VN-ON2, VN-ON3, VN-ON4) once its time on has passed on the clock. NOTHING here can reach OpenAI: the
# installed OpenAiTranscriber's endpoint is api.openai.com and the one production construction (the factory) names no
# other, so the factory is never let build one — the INSTALLED MediaWorker is run from this rehearsal's helper through its
# own seam, useTranscriber(), given the installed OpenAiTranscriber pointed at this rehearsal's stand-in on 127.0.0.1 (the
# adapter's LOOPBACK rule; the release's tests/voice_media/_lib.php vm_run does the same); the webhook is served with exec
# disabled (its own guard), so it spawns neither run_media_worker.php (whose factory would name the real endpoint) nor
# run_worker.php — the installed reply worker is run by this rehearsal as cron runs it, and master's ai_media job is never
# made due. The key is fictitious (an RH104SECRET- value, in the 0600 secrets pattern file the sweeps read) and is set where
# uCRM's own Configuration screen writes it (data/config.json beside the code: the manifest's openai_api_key field) — the
# plugin's own two writers of it cannot set it here: PluginConfig::saveAiSettings makes ai_provider the key's provider (the
# assistant would then be configured for OpenAI), and post_admin.php's save_settings rewrites the settings form's other
# fields from a partial post (crm_auth_token among them). The assistant must stay unconfigured, and in the chain's sandbox
# it would not: 5.18.93's seed stores ai_provider=openai in kyc_config.json (carried by every later rehearsal, changed by
# none), PluginConfig::files merges that file last, and DishNetAiBrain's openai branch takes openai_api_key — so with the
# key stored, the installed reply worker run below (RH2's failed ai.reply events retried, rung 9's voice turns) would post
# to api.openai.com. So on vdr's copy, BEFORE the key, ai_provider is set to claude through the release's own writer of
# keys (PluginConfig::saveOverrides, which refuses secrets only), openai read before and claude after; rungs 7–9 and
# every reply-worker run are GATED on 5v4's reading (provider claude, no Anthropic key, no external brain, the assistant
# unconfigured: DishNetAiBrain::isConfigured() false) — failing it, the key is removed at once and nothing runs — and
# each reply-worker run re-reads it first. The book (ai_provider openai again), the vault and data/config.json are put
# back after (vdr). ───────────────────────────────────────────────────────────────────────────────────────────────────
echo; echo "== 104-5v. the voice rungs in the one order: VA-ON1 and VN-ON1 at PASS; voice-dry-run refused until the privacy line, then by its key; 7, 8 and 9 on in turn, each at PASS =="
# The installed transcriber's endpoint, its loopback rule and the seams, read from the RELEASE's own code (never executed here)
OATE104="$(git -C "$REPO" show "$P104:$P/lib/OpenAiTranscriber.php" 2>/dev/null | sed -n "s/.*const ENDPOINT *= *'\([^']*\)'.*/\1/p" | head -1)"
OATL104="$(git -C "$REPO" show "$P104:$P/lib/OpenAiTranscriber.php" 2>/dev/null | grep -c "const LOOPBACK")"
OATC104="$(git -C "$REPO" show "$P104:$P/lib/Transcriber.php" 2>/dev/null | grep -c 'return new OpenAiTranscriber($config);')"
OAVE104="$(git -C "$REPO" show "$P104:$P/lib/MediaPolicy.php" 2>/dev/null | grep -c "ai_transcription_provider.*=== 'openai'")"
OAUT104="$(git -C "$REPO" show "$P104:$P/workers/MediaWorker.php" 2>/dev/null | grep -c 'public function useTranscriber(?TranscriberPort $transcriber): void')"
OAEX104="$(git -C "$REPO" show "$P104:$P/evo_webhook.php" 2>/dev/null | grep -c "&& !in_array('exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true);")"
OAMD104="$(git -C "$REPO" show "$P104:$P/lib/MediaPolicy.php" 2>/dev/null | sed -n "s/.*const STT_DEFAULT_MODEL *= *'\([^']*\)'.*/\1/p" | head -1)"
check "$OATE104|$( [ "$OATL104" -ge 1 ] && echo loopback-rule)|$OATC104|$( [ "$OAVE104" -ge 1 ] && echo openai-only)|$OAUT104|$OAEX104|$( [ -n "$OAMD104" ] && echo model)|$(qj "$SEC104" openai 2>/dev/null)" \
      "https://api.openai.com/v1/audio/transcriptions|loopback-rule|1|openai-only|1|2|model|unset" \
  "control (from the release's own code): the installed OpenAiTranscriber's endpoint is api.openai.com; its loopback rule accepts a 127.0.0.1 endpoint only for an object given one, and its one production construction (lib/Transcriber.php's factory) passes none — so the installed MediaWorker is driven here only through its own seam, useTranscriber() (the release's tests' way); evo_webhook.php spawns its two workers only where exec is not disabled (both spawns guarded); voiceEnabled requires the provider openai; the default model is the pin's ($OAMD104); no OpenAI key stored so far (LIVE104's control)"
# The main path at mainend (the live cases' book) or s2: step 2 and step 5 on. Their checks through the installed tool.
if [ "$LIVE104" = yes ]; then check "$(drest104 mainend)" "same|same|same" "5v the main path at mainend (the live cases' book): steps 1–6 on, the voice notes measured"
else check "$(drest104 s2)" "same|same|same" "5v the live cases did not run (see their control): the main path at steps 1–5 (s2)"; fi
OUTA104="$(sf104c --check ack)"; OUTM104="$(sf104c --check voice-measure)"
check "$(sfrc104)|$(grep -cx 'ack: PASS' <<<"$OUTA104")$(grep -cx '  VA-ON1: PASS' <<<"$OUTA104")|$(grep -cx 'voice-measure: PASS' <<<"$OUTM104")$(grep -cx '  VN-ON1: PASS' <<<"$OUTM104")" "0|$( [ "$LIVE104" = yes ] && echo 11 || echo 00 )|$( [ "$LIVE104" = yes ] && echo 11 || echo 00 )" \
  "5v' --check ack and --check voice-measure through the installed tool: $( [ "$LIVE104" = yes ] && echo "both PASS — VA-ON1 (the acknowledgements of the live cases) and VN-ON1 (the measured voice notes)" || echo "not driven without the live cases (steps 2 and 5 never came on without them)" )"
# The numbers and addresses the rest of the parts use, into the sweeps' pattern file before any is used: the voice notes'
# callers and the offers' warm customers (+256700104060–89), the offers screen's admin's phone (+256700104050) and its
# e-mail as RetailerAuth stores it (lower case — the pattern RH104SECRET- matches only the capitals), and the staff's
# e-mails in the lower case the agent's copy puts them in (QuoteAgentCopy::usableAddress, 104-5p).
OADM_PH104="+256700104050"; OADM_EM104="rh104secret-ruth@example.test"
python3 "$H104/rh104q.py" phones "[{\"phone\":\"$OADM_PH104\"}$(for i in $(seq 60 89); do printf ',{"phone":"+2567001040%s"}' "$i"; done)]" >> "$NAMES104"
{ printf '%s\n' "$OADM_EM104"; python3 -I -c 'import json, sys; [print(str(o["email"]).lower()) for o in json.loads(sys.argv[1]) if o.get("email")]' "$STAFF104_OPS"; } >> "$NAMES104"
check "$(grep -cxF -- "$OADM_PH104" "$NAMES104")|$(grep -cxF -- "+256700104061" "$NAMES104")|$(grep -cxF -- "$OADM_EM104" "$NAMES104")|$(grep -cxF -- "rh104secret-amina@example.test" "$NAMES104")|$(stat -c %a "$NAMES104")" "1|1|1|1|600" \
  "control: the pattern file holds the admin's phone, a warm customer's, the admin's lower-case e-mail and a staff member's lower-case e-mail before any of them is used (mode 0600)"
if [ "$LIVE104" = yes ]; then
  dsnap104 vdr   # the mainend book, to put back after the voice rungs
  # mainend was kept at 5q'' before anything set the privacy line (RH2-fix1), so no raw edit is needed — none could be
  # made by the tool, which never unsets a setting. The control: this rehearsal's reading finds the line unset in both
  # copies of the settings, beside the chase's switch, which the same reading finds set.
  check "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" ai_media_voice_privacy_line,quote_chase_enabled)" "set=1 names=quote_chase_enabled" \
    "control: on mainend the privacy line is unset and the chase's switch set (this rehearsal's own reading of kyc_config.json and the store row)"
  OUT="$(sf104c on voice-dry-run)"
  check "$(sfrc104)|$(has "$OUT" '  step 5 (voice-measure): VN-ON1 PASS')$(has "$OUT" '  ai_media_voice_privacy_line: not a date on or before today')$(has "$OUT" 'REFUSED privacy_line_missing — nothing was saved')|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" ai_media_voice,ai_transcription_provider,ai_media_voice_privacy_line)" set)" \
        "2|yesyesyes|0" \
    "5v'' on voice-dry-run with the privacy line unset: admitted by the order and VN-ON1's pass_only need (step 5 PASS), then REFUSED privacy_line_missing — step 7's own precondition; nothing saved"
  OUT="$(sf104c set ai_media_voice_privacy_line "$(kday104 "$(fnow104)")")"
  check "$(sfrc104)|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" ai_media_voice_privacy_line)" set)" "0|1" "…the privacy line set to the clock's Kampala day (a setting, never a switch; a date not after today)"
  OUT="$(sf104c on voice-dry-run)"
  check "$(sfrc104)|$(has "$OUT" '  step 5 (voice-measure): VN-ON1 PASS')$(has "$OUT" '  openai key: not set')$(has "$OUT" 'REFUSED key_not_set — nothing was saved')|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" ai_media_voice,ai_transcription_provider)" set)" \
        "2|yesyesyes|0" \
    "5v''' on voice-dry-run with the privacy line now set, still REFUSED key_not_set — the precondition passes the privacy line and refuses on the OpenAI key, which nothing has stored yet; nothing saved"
  # ── The assistant's provider off openai on vdr's copy, before any key is stored (the section's opening comment). The
  # controls: before the write, ai_provider reads openai in kyc_config.json (this rehearsal's own reading) and as
  # DishNetAiBrain's constructor reads it (the helper) — the reading can see openai, so the claude after is the write's;
  # after it, claude in the file and in the store row (the writer mirrors it) and through PluginConfig. ──
  PVB104="$(live104 secrets "$PD" "$DATA")"; PVKB104="$(python3 "$H104/rh104q.py" keyval "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" ai_provider)"
  check "$(kv104 "$PVKB104" file)|$(qj "$PVB104" provider 2>/dev/null)|$(qj "$PVB104" openai 2>/dev/null)|$(qj "$PVB104" claude 2>/dev/null)|$(qj "$PVB104" shopbot 2>/dev/null)|$(qj "$PVB104" brain 2>/dev/null)" \
        "openai|openai|unset|unset|unset|unconfigured" \
    "control: on vdr's copy, before any key, ai_provider reads openai — in kyc_config.json (this rehearsal's own reading; the store row: $(kv104 "$PVKB104" store)) and as DishNetAiBrain's constructor reads it — no provider key, no external brain, the assistant unconfigured"
  PVW104="$(live104 provider "$PD" "$DATA" '{"ai_provider":"claude"}')"
  PVA104="$(live104 secrets "$PD" "$DATA")"
  check "$(qj "$PVW104" ok 2>/dev/null)|$(qj "$PVW104" provider 2>/dev/null)|$(python3 "$H104/rh104q.py" keyval "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" ai_provider)|$(qj "$PVA104" provider 2>/dev/null)|$(qj "$PVA104" brain 2>/dev/null)" \
        "True|claude|file=claude store=claude|claude|unconfigured" \
    "5v3 ai_provider set to claude on vdr's copy through the installed PluginConfig::saveOverrides (the helper's provider mode, which writes that one key and value alone): read back claude through PluginConfig, in kyc_config.json and in the store row the writer mirrors — before any key is stored; vdr's put-back restores openai"
  # ── The key: fictitious, generated here, into the 0600 secrets pattern file first, then where uCRM's Configuration
  # screen writes it (data/config.json beside the code — uCRM's writer, which this sandbox does not drive, so the file is
  # written as that screen leaves it, raw, said so; the file kept first and put back after). Never on a command line,
  # never printed: handed to python in its environment alone.
  OAK104="RH104SECRET-sk-$(od -An -tx1 -N12 /dev/urandom | tr -d ' \n')"
  printf '%s\n' "$OAK104" >> "$SB/secrets104.pat"
  CFJ104="$PD/data/config.json"; CFJX104=absent; [ -f "$CFJ104" ] && { CFJX104=present; cp -p "$CFJ104" "$R104/config-json.kept"; }
  RH104_OAK="$OAK104" python3 -I - "$CFJ104" <<'PY'
import json, os, sys
p = sys.argv[1]
d = json.load(open(p, encoding='utf-8')) if os.path.exists(p) else {}
d['openai_api_key'] = os.environ['RH104_OAK']
json.dump(d, open(p, 'w', encoding='utf-8'))
PY
  [ "$CFJX104" = present ] || { chown 1000:1000 "$CFJ104"; chmod 600 "$CFJ104"; }
  SECV104="$(live104 secrets "$PD" "$DATA")"
  check "$(qj "$SECV104" openai 2>/dev/null)|$(qj "$SECV104" claude 2>/dev/null)|$(qj "$SECV104" provider 2>/dev/null)|$(qj "$SECV104" shopbot 2>/dev/null)|$(qj "$SECV104" brain 2>/dev/null)|$(grep -cxF -- "$OAK104" "$SB/secrets104.pat")|$(stat -c %a "$SB/secrets104.pat")" \
        "set|unset|claude|unset|unconfigured|1|600" \
    "5v4 the OpenAI key set where uCRM's Configuration screen writes it: read set through PluginConfig::load — and the assistant still unconfigured: its provider claude (5v3's write; DishNetAiBrain's own reading), no Anthropic key, no external brain (shopbot_ai_url, which the reply worker would ask first), isConfigured() false, so nothing answers a customer; the key in the 0600 secrets pattern file (the sweeps'), never printed"
  # THE GATE: rungs 7–9 and every reply-worker run only while 5v4's reading holds — the key set, ai_provider claude, no
  # Anthropic key, no external brain (shopbot_ai_url), the assistant unconfigured. Failing it, the key comes out at once
  # (data/config.json as it was), the skip is recorded, and neither worker is ever run.
  VGATE104=no
  [ "$(qj "$SECV104" openai 2>/dev/null)|$(qj "$SECV104" provider 2>/dev/null)|$(qj "$SECV104" claude 2>/dev/null)|$(qj "$SECV104" shopbot 2>/dev/null)|$(qj "$SECV104" brain 2>/dev/null)" = "set|claude|unset|unset|unconfigured" ] && VGATE104=yes
  check "$VGATE104" "yes" "5v-gate the gate holds on 5v4's reading — rungs 7–9 and the reply worker may run (a FAIL here: they are skipped, the key removed at once)"
  if [ "$VGATE104" = yes ]; then   # (the gate's branch runs to 5v11; its lines keep the section's indentation, the python inside them is column-bound)
  # ── The stand-ins, all on 127.0.0.1: the release's own fake OpenAI (from Git), its answer to every call an RH104SECRET-
  # transcript; the stand-in Evolution's voice-note bytes (its media file again: 5z removed it); the real webhook, served
  # with exec disabled. ──
  FOP104="$(free_port)"; FOU104="http://127.0.0.1:$FOP104"; rm -f "$H104"/v/fake_openai_state_*.json 2>/dev/null
  git -C "$REPO" show "$P104:$P/tests/fixtures/fake_openai_server.php" > "$H104/v/fake_openai_server.php" 2>/dev/null
  setpriv --reuid 1000 --regid 1000 --clear-groups -- env TMPDIR="$H104/v" "$PHPBIN" -S "127.0.0.1:$FOP104" -t "$H104/v" "$H104/v/fake_openai_server.php" >"$R104/openai-server.log" 2>&1 & FOPID104=$!; PIDS+=("$FOPID104")
  for i in $(seq 1 50); do curl -s --noproxy '*' "$FOU104/__test/state" 2>/dev/null | grep -q FAKE-OPENAI-TEST && break; sleep 0.1; done
  FOD104="$(curl -s --noproxy '*' -X POST -d '{"status":200,"body":{"text":"RH104SECRET-hello, the price of the kit please"}}' "$FOU104/__test/default" 2>/dev/null)"
  foa104() {   # the stand-in OpenAI's record, counts only: calls to its transcriptions path, those carrying the stored key as
               # their bearer (its sha256 prefix, as the fake records it — the key compared in python's environment, never
               # printed), the models asked for
    curl -s --noproxy '*' "$FOU104/__test/state" 2>/dev/null | RH104_OAK="$OAK104" python3 -I -c 'import hashlib, json, os, sys
try:
    d = json.load(sys.stdin)
except ValueError:
    d = {}
k = hashlib.sha256(os.environ["RH104_OAK"].encode()).hexdigest()[:12]
c = [x for x in d.get("calls", []) if x.get("path") == "/v1/audio/transcriptions"]
print("calls=%d keyed=%d model=%s" % (len(c), sum(1 for x in c if x.get("auth") == "present" and x.get("auth_sha") == k), ",".join(sorted(set(str(x.get("model", "")) for x in c))) or "-"))' 2>/dev/null || echo unread
  }
  python3 "$H104/rh104q.py" ogg > "$SB/evo/rh104-media.b64"
  WHPV104="$(free_port)"; WHUV104="http://127.0.0.1:$WHPV104/evo_webhook.php"
  setpriv --reuid 1000 --regid 1000 --clear-groups -- env LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" "$PHPBIN" -d disable_functions=exec -S "127.0.0.1:$WHPV104" -t "$PD" >"$R104/voice-webhook.log" 2>&1 & WHPIDV104=$!; PIDS+=("$WHPIDV104")
  for i in $(seq 1 50); do [ "$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "$WHUV104")" = "405" ] && break; sleep 0.1; done
  check "$(grep -c '"default":true' <<<"$FOD104")|$(foa104)|$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "$WHUV104")|$( [ -s "$SB/evo/rh104-media.b64" ] && echo media)" "1|calls=0 keyed=0 model=-|405|media" \
    "control: the stand-in OpenAI is this rehearsal's own on 127.0.0.1 (the release's fake, from Git), its answer set to an RH104SECRET- transcript and called by nothing so far; the real webhook served (exec disabled), a GET refused; the stand-in Evolution's voice-note bytes there again"
  vw104() { live104 mediaworker "$PD" "$DATA" "{\"endpoint\":\"$FOU104/v1/audio/transcriptions\",\"max\":60}"; }   # the INSTALLED MediaWorker, its transcriber the stand-in's (the seam)
  rw104() {   # the INSTALLED reply worker (run_worker.php), once, as cron runs it: as the book's owner, under the clock — only
              # while the gate holds AND a fresh reading still finds the assistant unconfigured (provider claude, no Anthropic
              # key, no external brain); else it is not run and "refused" is recorded (5v7'/5v9' then FAIL)
    local g; g="$(live104 secrets "$PD" "$DATA")"
    if [ "${VGATE104:-no}" != yes ] || [ "$(qj "$g" provider 2>/dev/null)|$(qj "$g" claude 2>/dev/null)|$(qj "$g" shopbot 2>/dev/null)|$(qj "$g" brain 2>/dev/null)" != "claude|unset|unset|unconfigured" ]; then
      echo refused >> "$R104/logs/voice-worker.rc"; return 0
    fi
    env PATH="$SB/bin:$PATH" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" bash "$SB/bin/docker" exec -u "$(owner104)" -e "DN_DATA_DIR=$PDD104_IN" ucrm \
      php -d log_errors=0 "$IN_CONTAINER/run_worker.php" </dev/null >> "$R104/logs/voice-worker.out" 2>&1; echo "$?" >> "$R104/logs/voice-worker.rc"
  }
  vnote104() {   # vnote104 N FIRST TAG [after] — N voice notes to the sales number through the real webhook, 25 s apart on the
                 # clock, from +2567001040FIRST onwards; after each the reply worker (its acknowledgement decided, as the
                 # webhook's spawn would), the media worker (the rung's own act) and, with "after", the reply worker again (a
                 # voice turn the reply rung queued)
    local n="$1" first="$2" tag="$3" k
    for k in $(seq 0 $(( n - 1 ))); do
      clk_to104 $(( $(fnow104) + 25 ))
      live104 webhook "$PD" "$DATA" "$WHUV104" "{\"channel\":\"sales\",\"phone\":\"+2567001040$(( first + k ))\",\"name\":\"RH104SECRET-Caller\",\"kind\":\"audio\",\"text\":\"\",\"id\":\"RH104V$tag$k\"}" >> "$R104/logs/voice-posts.json"; echo >> "$R104/logs/voice-posts.json"
      rw104; vw104 >> "$R104/logs/voice-media.json"; echo >> "$R104/logs/voice-media.json"
      [ "${4:-}" != after ] || rw104
    done
  }
  : > "$R104/logs/voice-posts.json"; : > "$R104/logs/voice-media.json"; : > "$R104/logs/voice-worker.rc"
  # ── Rung 7, voice-dry-run: on with the key set; twelve voice notes, each transcribed by the stand-in through the installed
  # worker's seam and acted on by nobody; 48 h on the clock; VN-ON2 PASS. ──
  OUT="$(sf104c on voice-dry-run)"; VDRON104="$(fnow104)"
  check "$(sfrc104)|$(has "$OUT" '  step 5 (voice-measure): VN-ON1 PASS')$(has "$OUT" '  ai_media_voice_privacy_line: ok')$(has "$OUT" '  openai key: set')$(has "$OUT" "  ai_media_openai_stt_model: $OAMD104")|$(grep -cxF "ON voice-dry-run: $(kv104 "$LAD104" on_voice-dry-run | sed 's/,/, /g')" <<<"$OUT")|$(grep -cF -- "$OAK104" <<<"$OUT")" \
        "0|yesyesyesyes|1|0" \
    "5v7 on voice-dry-run, the privacy line recorded and the key set: its need met (VN-ON1 PASS), its precondition's three lines ok — ON, its line in sf_on's order ($(kv104 "$LAD104" on_voice-dry-run)); the key's value nowhere in the tool's output"
  vnote104 12 70 D
  VC7_104="$(q104 vcalls "$DATA/plugin.sqlite3" "$VDRON104")"; FO7_104="$(foa104)"
  check "$(grep -c '"http":200' "$R104/logs/voice-posts.json")|$(kv104 "$VC7_104" calls)/$(kv104 "$VC7_104" ok)|$(kv104 "$VC7_104" byrung)|$(kv104 "$VC7_104" esc)|$(kv104 "$VC7_104" turns)|$FO7_104|$(grep -cv '^0$' "$R104/logs/voice-worker.rc")" \
        "12|12/12|dry_run:12|0|0|calls=12 keyed=12 model=$OAMD104|0" \
    "5v7' twelve voice notes through the real webhook (each 200), each transcribed through the installed MediaWorker's seam by the stand-in alone — 12 calls on wa_voice_calls, all ok, every one dry_run; the stand-in saw 12 calls, each carrying the stored key as its bearer (by its sha256 prefix) and the pin's default model; nobody acted on: no hand-over by the media worker, no voice turn; the reply worker ran clean each time"
  clk_to104 $(( VDRON104 + 49 * 3600 ))
  OUT="$(sf104c --check voice-dry-run)"
  check "$(sfrc104)|$(grep -cx 'voice-dry-run: PASS' <<<"$OUT")$(grep -cx '  VN-ON2: PASS' <<<"$OUT")" "0|11" "5v7'' 49 h on the clock: --check voice-dry-run PASS — VN-ON2 (OpenAI accepts WhatsApp's Opus, every call within its own cap, the dry run acted on nobody, the key in no log)"
  aft104 "5v7''' after voice-dry-run's 48 h:" "QF-ON0:ack=judged" "QF-ON3:ledger=judged" "VA-ON1:ack=judged" "AC-ON1:agent-cc=judged" "QF-ON1:tracking=judged" "QF-ON2:list=judged" "VN-ON1:voice-measure=judged" "QF-ON4:chase=judged" "VN-ON2:voice-dry-run=pass"
  # ── Rung 8, voice-staff: on (its need VN-ON2 PASS); three voice notes, each handed to a person by the media worker;
  # seven days on the clock; VN-ON3 PASS. ──
  OUT="$(sf104c on voice-staff)"; VSON104="$(fnow104)"
  # Whether the tool prints a precondition line is the ladder row's: voice-staff's 'pre' is empty at the pin, so the tool
  # asks for none and prints none (VoiceSteps' 'no own precondition' case is never reached for it — trial 4). Read from Git.
  VSPRE104="$(git -C "$REPO" show "$P104:$P/lib/SalesFollowupLadder.php" 2>/dev/null | grep -A3 "^        'voice-staff' => \[" | grep -c "'pre' => \[\]")"
  VSPREW104="$( [ "$VSPRE104" = 1 ] && echo no || echo yes)"
  check "$(sfrc104)|$(has "$OUT" '  step 7 (voice-dry-run): VN-ON2 PASS')$(has "$OUT" '  no own precondition')|$(grep -cxF "ON voice-staff: $(kv104 "$LAD104" on_voice-staff | sed 's/,/, /g')" <<<"$OUT")|$VSPRE104" "0|yes$VSPREW104|1|1" \
    "5v8 on voice-staff: its need, step 7's VN-ON2, at PASS — ON ($(kv104 "$LAD104" on_voice-staff))"
  vnote104 3 82 S
  VC8_104="$(q104 vcalls "$DATA/plugin.sqlite3" "$VSON104")"
  check "$(kv104 "$VC8_104" calls)/$(kv104 "$VC8_104" ok)|$(kv104 "$VC8_104" byrung)|$( [ "$(n103 "$(kv104 "$VC8_104" esc)")" -ge 3 ] && echo handed-over)|$(kv104 "$VC8_104" turns)|$(foa104)" \
        "3/3|handover:3|handed-over|0|calls=15 keyed=15 model=$OAMD104" \
    "5v8' three voice notes on the hand-over rung: each transcribed through the seam (3 calls, ok, handover) and handed to a person by the media worker (a wa.escalation of its own each) — no voice turn; the stand-in saw 15 calls in all, every one with the stored key"
  clk_to104 $(( VSON104 + 7 * 86400 + 3600 ))
  OUT="$(sf104c --check voice-staff)"
  check "$(sfrc104)|$(grep -cx 'voice-staff: PASS' <<<"$OUT")$(grep -cx '  VN-ON3: PASS' <<<"$OUT")" "0|11" "5v8'' seven days on the clock: --check voice-staff PASS — VN-ON3 (every note handed to a person once decided, bodies untouched, no holding line after an acknowledgement)"
  aft104 "5v8''' after voice-staff's seven days:" "QF-ON0:ack=judged" "QF-ON3:ledger=judged" "VA-ON1:ack=judged" "AC-ON1:agent-cc=judged" "QF-ON1:tracking=judged" "QF-ON2:list=judged" "VN-ON1:voice-measure=judged" "QF-ON4:chase=judged" "VN-ON2:voice-dry-run=judged" "VN-ON3:voice-staff=pass"
  # ── Rung 9, voice-reply: on for the sales number with the owner's approval (its options), its need VN-ON3 PASS; three
  # voice notes past the switch's margin, each understood, its one voice turn queued and taken by the reply worker (the
  # assistant unconfigured, so the turn is handed over, never answered); 72 h on the clock; VN-ON4 PASS. ──
  OUT="$(sf104c on voice-reply --channels sales --owner-approved "$(kday104 "$(fnow104)")")"; VRON104="$(fnow104)"
  check "$(sfrc104)|$(has "$OUT" '  step 8 (voice-staff): VN-ON3 PASS')|$(grep -cxF "ON voice-reply: $(kv104 "$LAD104" on_voice-reply | sed 's/,/, /g')" <<<"$OUT")" "0|yes|1" \
    "5v9 on voice-reply --channels sales --owner-approved <the clock's Kampala day>: its need, step 8's VN-ON3, at PASS — ON ($(kv104 "$LAD104" on_voice-reply))"
  clk_to104 $(( VRON104 + 120 ))   # past VN-ON4's switch margin (60 s)
  vnote104 3 85 R after
  VC9_104="$(q104 vcalls "$DATA/plugin.sqlite3" "$VRON104")"
  check "$(kv104 "$VC9_104" calls)/$(kv104 "$VC9_104" ok)|$(kv104 "$VC9_104" byrung)|$( [ "$(n103 "$(kv104 "$VC9_104" turns)")" -ge 3 ] && echo turns)|$(foa104)|$(grep -cv '^0$' "$R104/logs/voice-worker.rc")" \
        "3/3|reply:3|turns|calls=18 keyed=18 model=$OAMD104|0" \
    "5v9' three voice notes on the reply rung, on the listed number: each transcribed through the seam (3 calls, ok, reply) and given its voice turn (ai.reply events naming the note), taken by the reply worker (which ran clean every time); 18 calls at the stand-in in all, each with the stored key"
  clk_to104 $(( VRON104 + 73 * 3600 ))
  OUT="$(sf104c --check voice-reply)"
  check "$(sfrc104)|$(grep -cx 'voice-reply: PASS' <<<"$OUT")$(grep -cx '  VN-ON4: PASS' <<<"$OUT")" "0|11" "5v9'' 72 h on the clock: --check voice-reply PASS — VN-ON4 (each understood note on a listed number one turn, judged as the reply worker could know it; no acknowledgement on a reply number)"
  aft104 "5v9''' after voice-reply's 72 h:" "QF-ON0:ack=judged" "QF-ON3:ledger=judged" "VA-ON1:ack=judged" "AC-ON1:agent-cc=judged" "QF-ON1:tracking=judged" "QF-ON2:list=judged" "VN-ON1:voice-measure=judged" "QF-ON4:chase=judged" "VN-ON2:voice-dry-run=judged" "VN-ON3:voice-staff=judged" "VN-ON4:voice-reply=pass"
  AFTV104="$AFTT104"
  # ── The key never printed: in no output of this section — the tool's terminal lines, the --after-only runs' terminals
  # and logs, the wrapper's, the workers', the plugin's own logs (beside the code and in the data folder), the stand-ins'.
  # The controls: the pattern holds it, and it was in effect (every stand-in call carried it). ──
  KHIT104="$(grep -lF -- "$OAK104" "$R104"/sf.txt "$R104"/run-*.txt "$R104"/logs/voice-* "$R104"/voice-webhook.log "$R104"/openai-server.log "$OUT104"/*.log "$SALES104"/* "$DATA"/*.log "$PD"/data/*.log 2>/dev/null | grep -c .)"
  check "$KHIT104|$(grep -lF -f "$NAMES104" "$R104/run-$AFTV104.txt" "$R104"/logs/voice-worker.out "$R104"/voice-webhook.log "$R104"/openai-server.log 2>/dev/null | grep -c .)|$(grep -cxF -- "$OAK104" "$SB/secrets104.pat")|$(foa104 | sed -E 's/^calls=([0-9]+) keyed=([0-9]+).*/\1=\2/')" "0|0|1|18=18" \
    "5v10 the OpenAI key in no output of the voice rungs — the tool's lines, the --after-only runs' terminals and logs, the wrapper's log, the reply worker's, the plugin's logs beside the code and in the data folder, the webhook's and the stand-in's; no caller's number in them either (the controls: the pattern file holds the key, and all 18 stand-in calls carried it)"
  # ── Put back: the stand-ins stopped, the media file removed, the stand-in Evolution's requests (the media fetches and the
  # acknowledgements) moved into this rehearsal's own log, data/config.json as it was, the book and the vault as vdr kept
  # them. ──
  for p104 in "${FOPID104:-}" "${WHPIDV104:-}"; do [ -z "$p104" ] || { kill "$p104" 2>/dev/null; wait "$p104" 2>/dev/null; }; done; FOPID104=""; WHPIDV104=""
  rm -f "$SB/evo/rh104-media.b64" "$H104/v/fake_openai_server.php" "$H104"/v/fake_openai_state_*.json
  EVV104="$(evo_calls)"; [ ! -f "$SB/evo/requests.log" ] || mv "$SB/evo/requests.log" "$R104/evo-voice.log"
  if [ "$CFJX104" = present ]; then cp -p "$R104/config-json.kept" "$CFJ104"; rm -f "$R104/config-json.kept"; else rm -f "$CFJ104"; fi
  check "$(drest104 vdr)|$( [ -f "$CFJ104" ] && { grep -cF -- "$OAK104" "$CFJ104"; true; } || echo 0)|$(qj "$(live104 secrets "$PD" "$DATA")" openai 2>/dev/null)|$(evo_calls)|$( [ "$(n103 "$EVV104")" -ge 18 ] && echo kept)|$(kv104 "$(python3 "$H104/rh104q.py" keyval "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" ai_provider)" file)" "same|same|same|0|unset|0|kept|openai" \
    "5v11 the book and the vault put back as the voice rungs found them (vdr), data/config.json as it was (the key gone from it), the key read unset again, ai_provider openai again as the chain left it; the $EVV104 requests the voice rungs sent to the stand-in Evolution (the media fetches among them) kept in this rehearsal's own log"
  else
    # the gate failed: the key out at once, before anything else — neither worker was run, no stand-in started
    if [ "$CFJX104" = present ]; then cp -p "$R104/config-json.kept" "$CFJ104"; rm -f "$R104/config-json.kept"; else rm -f "$CFJ104"; fi
    echo "  note  RH-NOTE 5v SKIPPED rungs 7–9: 5v4's reading did not hold (openai=$(qj "$SECV104" openai 2>/dev/null) provider=$(qj "$SECV104" provider 2>/dev/null) claude=$(qj "$SECV104" claude 2>/dev/null) shopbot=$(qj "$SECV104" shopbot 2>/dev/null) brain=$(qj "$SECV104" brain 2>/dev/null)) — the key removed at once, the reply and media workers never run"
    check "$( [ -f "$CFJ104" ] && { grep -cF -- "$OAK104" "$CFJ104"; true; } || echo 0)|$(qj "$(live104 secrets "$PD" "$DATA")" openai 2>/dev/null)|$( [ -s "$R104/logs/voice-worker.rc" ] && echo ran || echo none)|$(drest104 vdr)" "0|unset|none|same|same|same" \
      "5v-gate' the key out of data/config.json at once and read unset, no reply-worker run recorded, the book and the vault put back (vdr) — rungs 7–9 skipped (the gate's FAIL above)"
  fi
  rm -f "$H104/v/live.err" "$H104/v/live-vault.json"
else
  echo "  note  RH-NOTE 5v the privacy line, the key and rungs 7–9 run only with the live cases (LIVE104=$LIVE104): steps 2 and 5 never came on without them"
fi

# ── 104-5o: Part O's cases (§H.4.18's rehearsal list), on the live-cases book. Ruling R6's refusals — on offers-test
# refused while the ledger's L-ON is not PASS, and while step 3's Q1-ON reads an unaccepted NOT_YET — are 104-5's 5e-o and
# 5g-o, each on a copy of the book at the one moment its need had not passed (the ledger's day and step 3's 72 h pass on
# the clock and never come back); the accept is here (5o'). offers-test is admitted only when the ORDER passes it — every
# lower step on, recorded skipped or optional (SalesFollowupLadder::admit, rule 2) — and mainend has steps 1–6 on and 7–9
# neither on nor recorded skipped, so the order refuses it at voice-dry-run first, shown, nothing saved; voice-dry-run is
# then skipped on this section's copy (its cascade records 8 and 9) and the order passes. Then the screen, through the
# RELEASE's own handlers (includes/post/post_offers.php) as an admin's session posts them, never raw: the stand-in
# Evolution taught to answer a send as Evolution answers it (a message id); the real webhook served, exec disabled, for the
# echoes and the STOP; an admin made through the installed RetailerAuth::createRetailer and signed in through the plugin's
# own do_login; a draft; a test of each wording to the admin's own phone, its echo delivered through the real webhook while
# the test settles, recorded `other`; --check offers-test PASS; on offers-live; warm customers on each handset the book's
# department channels name (two in this sandbox — D asks three, which would need 5.18.90's channel registry switched on;
# a deviation the coordinator accepted, item 27 of the coordinator's notes, printed at 5o9-a); Start "all"; a week of
# master cycles, the day's reminder run first and judged on its own, master's entry carried through the offers cycle
# 300 s later, the wait shown before the first weekday's reminder hour; a STOP reply through the real webhook; Pause
# after the third weekday, while the offer still runs; off offers-live after the week (the offer paused). ──────────────
echo; echo "== 104-5o. Part O: the offers screen through the installed handlers — a test of each wording, Start all over the handsets, a week, a STOP, Pause, off offers-live =="
if [ "$LIVE104" = yes ]; then
  check "$(drest104 mainend)" "same|same|same" "5o the offers cases start from mainend (steps 1–6 on, the chase answered; steps 7–9 neither on nor recorded skipped)"
  # Step 7's label as show prints it (labels=, read through rh104-ref.php) against the release's own STEPS row read from
  # Git as text — the number the order refusal names, never written here. The skip keys of 7–9: voice-dry-run's and its
  # cascade's (SKVD104, the ladder's own, 5r's control).
  L7_5O104="$(lbl104 voice-dry-run)"
  N7_5O104="$(git -C "$REPO" show "$P104:$P/lib/SalesFollowupLadder.php" 2>/dev/null | sed -n "s/.*'voice-dry-run' => \['n' => '\([0-9a-z]*\)'.*/\1/p" | head -1)"
  OSK5O104="sf_skip_voice-dry-run$(for x in ${SKVD104//,/ }; do printf ',sf_skip_%s' "$x"; done)"
  check "$L7_5O104|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" "ai_media_voice,ai_media_voice_handover,ai_media_voice_reply,$OSK5O104,quote_chase_enabled")" names)" \
        "$N7_5O104 voice-dry-run|quote_chase_enabled" \
    "control: on mainend steps 7–9 are neither on (their switches unset) nor recorded skipped (no ${OSK5O104//,/, }), beside the chase's switch the same reading finds set; step 7's label ($L7_5O104) is the release's own row"
  OUT="$(sf104c on offers-test)"
  check "$(sfrc104)|$(grep -cxF "  step ${L7_5O104%% *} (${L7_5O104#* }) is neither on nor recorded skipped" <<<"$OUT")$(grep -cxF 'REFUSED order:voice-dry-run — nothing was saved' <<<"$OUT")|$(grep -c '^ON offers-test' <<<"$OUT")|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" offers_test_enabled,quote_chase_enabled)" names)" \
        "2|11|0|quote_chase_enabled" \
    "5o-a on offers-test straight from mainend: REFUSED order:voice-dry-run — step 7 neither on nor recorded skipped, a non-optional lower step (rule 2, read before any need); offers_test_enabled unset (the chase's switch, read beside it, set)"
  OUT="$(sf104c skip voice-dry-run --reason "no OpenAI key in the rehearsal")"
  check "$(sfrc104)|$(grep -cxF "SKIPPED voice-dry-run; also skipped: ${SKVD104//,/, }" <<<"$OUT")|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" "$OSK5O104")" set)|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" ai_media_voice,ai_media_voice_handover,ai_media_voice_reply,quote_chase_enabled)" names)" \
        "0|1|$(( 1 + $(tr ',' '\n' <<<"$SKVD104" | grep -c .) ))|quote_chase_enabled" \
    "5o-b skip voice-dry-run through the tool on this section's copy: SKIPPED, ${SKVD104//,/ and } recorded skipped by the cascade — every sf_skip_ key of 7–9 set, none of their switches (the chase's, read beside them, set)"
  check "$(grep -cE "^  ($(lbl104 voice-dry-run ${SKVD104//,/ })): skipped \\(" <<<"$(sf104c show)")" "$(( 1 + $(tr ',' '\n' <<<"$SKVD104" | grep -c .) ))" \
    "control: before offers-test is asked for again, show reads steps 7–9 recorded skipped — the order now passes them (a skipped voice step serves a later non-voice step)"
  OUT="$(sf104c on offers-test)"
  check "$(sfrc104)|$(has "$OUT" '  the ledger: L-ON PASS')$(has "$OUT" '  step 3 (tracking): Q1-ON PASS')|$(grep -c '^ON offers-test: offers_test_enabled$' <<<"$OUT")|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" offers_test_enabled)" set)" "0|yesyes|1|1" \
    "5o' on offers-test again, steps 7–9 now recorded skipped: the order passes, its two needs met (the ledger's L-ON PASS, step 3's Q1-ON PASS) — ON, ruling R6's accept after 5e-o's and 5g-o's refusals (the admin screen exists only while it is on)"
  # before any test, --check offers-test reads NOT_YET no_test (C-O1-1: no recorded test of any wording), and on offers-live
  # refuses by its need — the tool-driven anchors, independent of the admin HTTP flow below
  OUT="$(sf104c --check offers-test)"
  check "$(sfrc104)|$(grep -cx 'offers-test: NOT_YET no_test' <<<"$OUT")" "0|1" "5o'' --check offers-test before any test: NOT_YET no_test (C-O1-1 needs a recorded test of each wording)"
  OUT="$(sf104c on offers-live)"
  check "$(sfrc104)|$(grep -cE '^REFUSED need_not_(yet|pass):offers-test — nothing was saved$' <<<"$OUT")|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" offers_live_enabled)" set)" "2|1|0" \
    "5o''' on offers-live before a test: REFUSED by its need (offers-test's check reads NOT_YET) — nothing saved"
  # The stand-in Evolution already answers a send as Evolution answers it, with a message id (rh104-send.on, set since
  # 104-5's live cases began — its edit and its control are there).
  # ── The real webhook, served with exec disabled (it spawns no worker; the STOP is recorded by the webhook itself,
  # evo_webhook.php's ContactOptOut::detect), for the tests' echoes and the STOP reply. ──
  WHPO104="$(free_port)"; WHUO104="http://127.0.0.1:$WHPO104/evo_webhook.php"
  setpriv --reuid 1000 --regid 1000 --clear-groups -- env LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" "$PHPBIN" -d disable_functions=exec -S "127.0.0.1:$WHPO104" -t "$PD" >"$R104/offers-webhook.log" 2>&1 & WHPIDO104=$!; PIDS+=("$WHPIDO104")
  for i in $(seq 1 50); do [ "$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "$WHUO104")" = "405" ] && break; sleep 0.1; done
  # ── The installed plugin served over HTTP, as the dashboard serves it (public.php), under the clock, as the store's user —
  # so the screen's POST handlers run exactly as on the server. Before every request the piggyback's marker
  # (<data>/piggyback_last_run.txt) is set to the clock's now, as the piggyback writes it (time()) — raw, a state no writer
  # makes for this purpose, said here — so no admin page load runs cron/master.php in-process (public.php runs it when
  # more than 300 s have passed): no job of master's can reach Starlink, Google Drive, EFRIS or Splynx from this server;
  # and master_schedule is planted quiet at its start, as master104 plants it. ──
  live104 schedule "$PD" "$DATA" '{"due":[]}' > "$R104/logs/offers-web.plant"
  # …and the served plugin's session files stamped with the clock's now: public.php keeps them in <plugin>/data/sessions with
  # gc_probability 1/100 and gc_maxlifetime 8 h, and PHP's files handler compares a file's mtime (the kernel's, never moved
  # by the clock) with time() (moved: days ahead here), so a request that drew the 1 % deleted the admin's live session at
  # random (trials 3 and 4: the save, then a test, landed on the login page). Stamping them keeps them as young as they are.
  wfresh104() { printf '%s' "$(fnow104)" > "$DATA/piggyback_last_run.txt"; chown 1000:1000 "$DATA/piggyback_last_run.txt" 2>/dev/null
                [ ! -d "$PD/data/sessions" ] || find "$PD/data/sessions" -maxdepth 1 -type f -name 'sess_*' -exec touch -d "@$(fnow104)" {} + 2>/dev/null; }
  WPO104="$(free_port)"; WPB104="http://127.0.0.1:$WPO104/public.php"
  wfresh104
  setpriv --reuid 1000 --regid 1000 --clear-groups -- env LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" DN_DATA_DIR="$DATA" "$PHPBIN" -S "127.0.0.1:$WPO104" -t "$PD" >"$R104/offers-web.log" 2>&1 & WPPID104=$!; PIDS+=("$WPPID104")
  for i in $(seq 1 50); do wfresh104; [ "$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "$WPB104?page=login")" = "200" ] && break; sleep 0.2; done
  # …and kept so for the server's whole life by a stamper beside it (every 0.2 s): trial 7 lost the admin's session file in
  # a minute with no request between two stamps (5o9's Start landed on the sign-in page), so the stamps no longer wait for
  # the requests; any action that lands on the sign-in page is recorded (wpost104, below) and 5o13' fails on it by name.
  ( while kill -0 "$WPPID104" 2>/dev/null; do wfresh104; sleep 0.2; done ) & WFRPID104=$!; PIDS+=("$WFRPID104")
  : > "$R104/logs/session-loss.txt"
  check "$( [ "$(curl -s --noproxy '*' "$WPB104?page=login" 2>/dev/null | grep -c '?page=login&m=forgot')" -ge 1 ] && echo marker)|$(kill -0 "$WFRPID104" 2>/dev/null && echo stamping)" "marker|stamping" \
    "control: the sign-in page carries the link the session-loss record looks for (the positive control of 5o13''s zero), and the stamper runs beside the served plugin"
  WJAR104="$H104/v/admin.jar"; rm -f "$WJAR104"
  # The day's token, from a page load WITHOUT the session (tests/wa_offers/C_screen.php's way): a signed-in GET of the login
  # page redirects with no body. The token decoded as the page encodes it.
  wtok104() { wfresh104; curl -s --noproxy '*' "$WPB104?page=login" 2>/dev/null | python3 -I -c 'import html, re, sys
m = re.search(r"name=\"_csrf\" value=\"([^\"]+)\"", sys.stdin.read()); print(html.unescape(m.group(1)) if m else "")' 2>/dev/null; }
  wpost104() {   # wpost104 FIELD… (k=v) — an offers action posted as the admin's session posts it: the day's token, from our own page
                 # (Sec-Fetch-Site), to the tab; the redirect followed; the page it lands on printed for the caller to read
    local args=(--data-urlencode "_csrf=$(wtok104)") f
    for f in "$@"; do args+=(--data-urlencode "$f"); done
    # each redirect followed as a request of its own, after a stamp (never curl -L): a request that writes the session leaves
    # its file with the kernel's time, which the clock never moves, so the next request — a followed redirect, with no stamp
    # between — could draw PHP's 1 % clean-up over the admin's own file and, writing nothing new, never put it back (trial 7)
    wfresh104
    local o u n=0
    o="$(curl -s --noproxy '*' -b "$WJAR104" -c "$WJAR104" -H 'Sec-Fetch-Site: same-origin' -w '\n@rh104@%{redirect_url}' "${args[@]}" "$WPB104?page=dashboard&tab=wa_offers" 2>/dev/null)"
    u="${o##*$'\n'@rh104@}"; o="${o%$'\n'@rh104@*}"
    while [ -n "$u" ] && [ "$n" -lt 5 ]; do
      wfresh104; n=$((n + 1))
      o="$(curl -s --noproxy '*' -b "$WJAR104" -c "$WJAR104" -H 'Sec-Fetch-Site: same-origin' -w '\n@rh104@%{redirect_url}' "$u" 2>/dev/null)"
      u="${o##*$'\n'@rh104@}"; o="${o%$'\n'@rh104@*}"
    done
    case "$o" in *'?page=login&m=forgot'*) printf '%s %s\n' "$(fnow104)" "$(printf '%s\n' "$@" | sed -n 's/^action=//p' | head -1)" >> "$R104/logs/session-loss.txt" ;; esac   # the sign-in page's own link: the session was lost
    printf '%s' "$o"
  }
  ocount104() { python3 -I -c 'import sqlite3, sys
try:
    c = sqlite3.connect("file:" + sys.argv[1] + "?mode=ro", uri=True); print(c.execute(sys.argv[2]).fetchone()[0])
except Exception:
    print(-1)' "$DATA/plugin.sqlite3" "$1" 2>/dev/null; }
  # The admin, through the installed RetailerAuth::createRetailer (its e-mail stored lower-case, the password hashed; a
  # fictitious first name, phone and address; the password generated here, into the secrets pattern file first, handed to
  # the helper in its environment alone)
  OAPW104="RH104SECRET-pw-$(od -An -tx1 -N9 /dev/urandom | tr -d ' \n')"; printf '%s\n' "$OAPW104" >> "$SB/secrets104.pat"
  export RH104_ADMIN_PW="$OAPW104"
  OADM104="$(live104 admin "$PD" "$DATA" "{\"name\":\"RH104SECRET-Ruth\",\"email\":\"$OADM_EM104\",\"phone\":\"$OADM_PH104\"}")"
  unset RH104_ADMIN_PW
  WTOKC104="$(wtok104)"
  wfresh104
  WLOGIN104="$(curl -s --noproxy '*' -b "$WJAR104" -c "$WJAR104" -o /dev/null -w '%{redirect_url}' --data-urlencode "_csrf=$WTOKC104" --data-urlencode 'action=do_login' \
                  --data-urlencode "identifier=$OADM_EM104" --data-urlencode "password=$OAPW104" "$WPB104?page=login" 2>/dev/null)"
  check "$( wfresh104; [ "$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "$WPB104?page=login")" = "200" ] && echo served)|$(qj "$OADM104" made 2>/dev/null)$(qj "$OADM104" found 2>/dev/null)$(qj "$OADM104" lower 2>/dev/null)$(qj "$OADM104" admin 2>/dev/null)$(qj "$OADM104" must_change 2>/dev/null)|$( [ "${#WTOKC104}" -ge 16 ] && echo token)|$(case "$WLOGIN104" in *page=dashboard*) echo in ;; *) echo "out:${WLOGIN104:0:40}" ;; esac)" \
        "served|TrueTrueTrueTrueFalse|token|in" \
    "5o4 the installed plugin served over HTTP (public.php); an admin made through the installed RetailerAuth::createRetailer (stored, found by its lower-case e-mail, an admin, no password change forced); the day's token read from a page load without the session (the positive control: non-empty); signed in through the plugin's own do_login — redirected to the dashboard"
  W1OF104="RH104SECRET-first offer wording about the kit, long enough to pass the twenty character floor"
  W2OF104="RH104SECRET-second offer wording, different from the first, also well over twenty characters long"
  PSAVE104="$(wpost104 'action=offer_save' 'offer=0' 'title=RH104SECRET offer' "wording_1=$W1OF104" "wording_2=$W2OF104" 'wording_3=' 'size=all')"
  OFID104="$(ocount104 'SELECT COALESCE(MAX(id),0) FROM wa_offers')"
  OF5_104="$(q104 offer "$DATA/plugin.sqlite3" "$OFID104")"
  check "$( [ "$(n103 "$OFID104")" -ge 1 ] && echo saved)|$(kv104 "$OF5_104" state)|$(kv104 "$OF5_104" hash)|$(grep -c "Draft #$OFID104 was saved" <<<"$PSAVE104")|$(grep -c "\] \[wa_offers\] offer_save offer=$OFID104 code=ok$" "$PD/data/plugin.log" 2>/dev/null)" "saved|draft|ok|1|1" \
    "5o5 offer_save through the installed handler: one draft in wa_offers, read by its own columns (state draft, content_hash 64 hex — the positive control of every later reading); the screen's flash; the plugin's log (data/plugin.log beside the code, PluginLog's) carries the action, the offer's id and code=ok"
  # ── 5o6: a test of each wording to the admin's own phone, on the sales channel (its instance the book's, the handset the
  # stand-in answers for); after each, the echo Evolution sends of that very message (the id it gave) delivered through the
  # real webhook — after the send returned, while the test settles: the gateway claimed it at once, so the webhook drops it
  # as ours; each test recorded in the ledger as kind other (ref offer_test:<id>), never a proactive claim. ──
  OCH104=sales
  check "$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" evo_instance_sales)" set)|$(kv104 "$OF5_104" tests)" "1|-" \
    "control: the book names the sales channel's Evolution instance (evo_instance_sales set) — the handset the tests go through; no test yet"
  STX0_104="$(grep -c '/message/sendText/' "$SB/evo/requests.log" 2>/dev/null)"; : > "$R104/logs/offer-echo.json"; OTF104=""; OEIDS104=""
  for v in 1 2; do
    PTEST104="$(wpost104 'action=offer_test' "offer=$OFID104" "variant=$v" "channel=$OCH104")"
    # the page prints the flash through h() — the apostrophe as &#039; — so it is read decoded (trial 4: '00' with both sent)
    OTF104="$OTF104$(python3 -I -c 'import html, sys; sys.stdout.write(html.unescape(sys.stdin.read()))' <<<"$PTEST104" | grep -c "Test #[0-9]* of wording $v was sent to your account's phone")"
    EID104="$(tail -n1 "$SB/evo/rh104-send.ids" 2>/dev/null)"; OEIDS104="$OEIDS104${OEIDS104:+,}'$EID104'"
    WV104="$W1OF104"; [ "$v" = 2 ] && WV104="$W2OF104"
    live104 webhook "$PD" "$DATA" "$WHUO104" "{\"channel\":\"$OCH104\",\"phone\":\"$OADM_PH104\",\"name\":\"RH104SECRET-Ruth\",\"kind\":\"text\",\"text\":\"$WV104\",\"id\":\"$EID104\",\"from_me\":true}" >> "$R104/logs/offer-echo.json"; echo >> "$R104/logs/offer-echo.json"
  done
  OF6_104="$(q104 offer "$DATA/plugin.sqlite3" "$OFID104")"; TL6_104="$(q104 testled "$DATA/plugin.sqlite3" "$OFID104")"
  check "$(kv104 "$OF6_104" tests)|$(kv104 "$TL6_104" n)/$(kv104 "$TL6_104" sent)|$(( $(n103 "$(grep -c '/message/sendText/' "$SB/evo/requests.log" 2>/dev/null)") - $(n103 "$STX0_104") ))|$OTF104|$(grep -c '"http":200' "$R104/logs/offer-echo.json")|$(sq "SELECT COUNT(*) FROM evo_webhook_seen WHERE message_id IN ($OEIDS104)")|$(sq "SELECT COUNT(*) FROM wa_messages WHERE wa_message_id IN ($OEIDS104)")|$(kv104 "$(ledger104)" quote)" \
        "1:sent/recorded/claimed,2:sent/recorded/claimed|2/2|2|11|2|2|0|$(kv104 "$LE104" quote)" \
    "5o6 a test of each wording through the installed offer_test, the stand-in Evolution answering as Evolution does: exactly two sendText calls; each test sent, recorded in the ledger (kind other, ref offer_test:<id>, sent — never a proactive claim: the quote claims as they were) and its echo claimed; each echo delivered through the real webhook afterwards (200) and dropped as ours (its id held once in evo_webhook_seen, no message stored for it)"
  OUT="$(sf104c --check offers-test)"
  check "$(sfrc104)|$(grep -cx 'offers-test: PASS' <<<"$OUT")" "0|1" "5o7 --check offers-test after both tests: PASS (C-O1-1: every wording tested and recorded on the current content; C-O1-3: each sent test's echo claimed)"
  OUT="$(sf104c on offers-live)"
  check "$(sfrc104)|$(grep -c '^ON offers-live: offers_live_enabled, offers_live_since$' <<<"$OUT")|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" offers_live_enabled,offers_live_since)" set)" "0|1|2" \
    "5o8 on offers-live: step 11's check PASS — ON, offers_live_since stamped"
  # ── Warm conversations on every handset the book's department channels name (EvolutionOfferGateway::handsetFor of sales
  # and support; accounts is never a candidate): three customers on each, each having written within the warm window and
  # been answered by the assistant since (so nobody is awaiting a reply) — through the installed ConversationService. The
  # sandbox names two handsets (sales, and support on its shared instance); three would need 5.18.90's channel registry,
  # which this rehearsal does not switch on (a deviation from D the coordinator accepted, item 27). The warmest on sales
  # (+256700104061) is the one who replies STOP. ──
  NH104="$(kv104 "$(python3 "$H104/rh104q.py" handsets "$DATA/plugin.sqlite3" "$DATA/kyc_config.json")" n)"
  WARM104='{"convs":[
{"phone":"+256700104061","channel":"sales","name":"RH104SECRET-Apio","text":"RH104SECRET-thanks for the kit details","reply":"RH104SECRET-you are welcome","ago_min":300,"reply_after_min":5},
{"phone":"+256700104062","channel":"sales","name":"RH104SECRET-Odong","text":"RH104SECRET-thanks for the kit details","reply":"RH104SECRET-you are welcome","ago_min":400,"reply_after_min":5},
{"phone":"+256700104063","channel":"sales","name":"RH104SECRET-Akot","text":"RH104SECRET-thanks for the kit details","reply":"RH104SECRET-you are welcome","ago_min":500,"reply_after_min":5},
{"phone":"+256700104064","channel":"support","name":"RH104SECRET-Ajok","text":"RH104SECRET-thanks for the help","reply":"RH104SECRET-you are welcome","ago_min":310,"reply_after_min":5},
{"phone":"+256700104065","channel":"support","name":"RH104SECRET-Okot","text":"RH104SECRET-thanks for the help","reply":"RH104SECRET-you are welcome","ago_min":410,"reply_after_min":5},
{"phone":"+256700104066","channel":"support","name":"RH104SECRET-Adong","text":"RH104SECRET-thanks for the help","reply":"RH104SECRET-you are welcome","ago_min":510,"reply_after_min":5}]}'
  WRM104="$(live104 warm "$PD" "$DATA" "$WARM104")"
  check "$(qj "$WRM104" warm 2>/dev/null)|$( [ "$(n103 "$NH104")" -ge 2 ] && echo handsets)" "6|handsets" \
    "5o9-a six warm conversations, three on each department handset, through the installed ConversationService (the customer's text, then the assistant's answer); the book names $NH104 handsets an offer may use"
  # D's case names three handsets; with the channel registry off (as the chain leaves it) the release's audience takes its
  # handsets from the department channels' instances alone (OfferAudience::build → EvolutionOfferGateway::handsetFor →
  # EvolutionApiService::instanceFor over evo_instance_sales/support/account), and the fixture's support and account share
  # one instance, as production's do. A third needs the registry switched on with a third department number — routing
  # every later section (the webhook's, the audience's owner types, L-ON's handset note) through it — so it is not made
  # here: a deviation from D, ACCEPTED by the coordinator (item 27 of the coordinator's notes), and printed as such.
  OAH104="$(git -C "$REPO" show "$P104:$P/lib/OfferAudience.php" 2>/dev/null | grep -c '\$h = \$gw->handsetFor((string)\$c\[.channel.\]);')"
  echo "  note  RH-NOTE 5o DEVIATION from D §H.4.18 (\"Start all over three handsets\"), ACCEPTED by the coordinator (item 27 of the coordinator's notes): $NH104 handsets here, because with the channel registry off the release's audience takes its handsets from the department channels' instances (handsetFor, $OAH104 call site(s) in OfferAudience at the pin) and production's support and account share one instance, so a third would need 5.18.90's registry switched on and would route every later section through it"
  # ── 5o9: Start "all" through the installed offer_start, with the content as stored ──
  # the clock a minute on first: O-D19 pauses a running offer whose latest Start is not LATER than offers_live_since
  # (OfferSender::liveSinceGuard, <=), and 5o8's stamp and this Start fell in the same second (trial 5: the tool's log and
  # the started event both 10:31:57; paused_by_switch at the first weekday's cycle, nothing claimed all week)
  clk_to104 $(( $(fnow104) + 60 ))
  PSTART104="$(wpost104 'action=offer_start' "offer=$OFID104" "content_hash=$(sq "SELECT content_hash FROM wa_offers WHERE id = $OFID104")")"
  OF9_104="$(q104 offer "$DATA/plugin.sqlite3" "$OFID104")"
  WQ104=""; for i in 61 62 63 64 65 66; do WQ104="$WQ104$(kv104 "$(q104 offer "$DATA/plugin.sqlite3" "$OFID104" "+2567001040$i")" who | cut -d/ -f1) "; done
  check "$(kv104 "$OF9_104" state)|$(grep -c "Offer #$OFID104 was started:" <<<"$PSTART104")|$(kv104 "$OF9_104" handsets)|${WQ104% }" \
        "running|1|$NH104|queued queued queued queued queued queued" \
    "5o9 Start 'all' through the installed offer_start: the offer running, the screen's flash; its audience queued across all $NH104 handsets ($(kv104 "$OF9_104" byh) recipients per handset), each of the six warm customers among them"
  # ── 5o10: a simulated week of master cycles, Kampala mornings from the day after the clock's (never back), Sunday
  # skipped, at hours the pin itself reads on the book as it stands (rh104-ref.php conf: ReminderDay::hours() and
  # WaSendBudget::window()). Each weekday two cycles, each master104's. The reminders cycle at <reminder hour>:30:
  # customer_reminders alone due, so cron/master.php runs it itself (JobWindow: its entry an earlier day's) while every
  # other job, offers_send included, is planted run at that moment — the run JUDGED RIGHT AFTER ITS OWN CYCLE (its entry
  # this cycle's, finished). Then the clock moves 300 s — offers_send's interval is 240 s, so the reminders cycle's plant
  # of it is old enough — and the offers cycle runs with offers_send due and customer_reminders' entry CARRIED exactly as
  # master wrote it (master104 --set): master dispatches offers_send alone (JobWindow: the reminders ran today),
  # ReminderDay reads master's run, never a plant, and the entry reads unchanged after (carried). Which jobs master
  # dispatched in each cycle is read from its own RUN lines, and that offers_send ran in the offers cycle from its own
  # entry. The day's offer claims are read over the whole Kampala day, so a claim made before the reminder run reads
  # first_after=no instead of falling outside the window. On the first weekday, before its reminders cycle, an early
  # cycle at <the window's first hour>:30 — inside the offer window and before the reminder hour, so JobWindow does not
  # run the reminders and ReminderDay holds: offers_send due, customer_reminders' entry carried as the schedule holds it
  # (5o's plant at the screen's start, before today's opening); offers_send runs and holds — its own line,
  # code=reminders_pending — the entry unchanged, nothing claimed that day before the reminder run: the wait itself, its
  # positive control the same reader's claims after the reminder run that day. After the first weekday's offers cycle,
  # +256700104061 — sent that day — replies STOP through the real webhook; 5o11 is read right after the second weekday's
  # offers cycle, the run that marks it. On each of the first three weekdays a claim on every handset (one judged
  # recipient per handset per run, three warm customers on each). After the third weekday's offers cycle the offer is
  # paused through the screen (5o12), while it still runs: OfferSender's step 8 closes a running offer with nothing
  # queued or claimed as done at its next run, and a done offer cannot be paused — so the later weekdays' offers cycles
  # run offers_send over a paused offer and claim nothing. off offers-live comes after the week. ──
  rn104() { local l="${1%%:*}" d="${1##*:}"; printf '%s:%s' "$l" "${d%.0}"; }   # rn104 ENTRY — last_run:duration_ms, master's round()ed float duration as an integer
  remset104() {   # remset104 ENTRY — master104 --set's JSON carrying ENTRY as customer_reminders' entry; nothing when ENTRY is not master's form
    local e l d; e="$(rn104 "$1")"; l="${e%%:*}"; d="${e##*:}"
    [[ "$l" =~ ^[1-9][0-9]*$ ]] && [[ "$d" =~ ^(-1|[0-9]+)$ ]] || return 0
    printf '{"customer_reminders":{"last_run":%s,"duration_ms":%s}}' "$l" "$d"
  }
  mrun104() { local r; r="$(grep -oE '\[master\] RUN [a-z0-9_]+$' "$R104/logs/master-$1.out" 2>/dev/null | sed 's/^.* RUN //' | paste -sd, -)"; echo "${r:--}"; }   # mrun104 LABEL — the jobs that cycle's master dispatched, in its order (its own RUN lines), or -
  hh104() { printf '%02d' "$(n103 "$1")"; }   # hh104 HOUR — two digits
  SCRO104="$(scr104)"; RCO104="$(ref104 conf "" "$SCRO104")"; rm -rf "$SCRO104"
  OWIN104="$(kv104 "$RCO104" win)"; OWF104="$(n103 "${OWIN104%%-*}")"; OWT104="$(n103 "${OWIN104##*-}")"
  LWIN104="$(kv104 "$LAD104" window)"
  ODAY104="$(kday104 $(( $(fnow104) + 86400 )))"; OWDAYS104=0
  for d in 0 1 2 3 4 5 6; do [ "$(kdow104 $(( $(kat104 "$ODAY104 12:00") + d * 86400 )))" = 7 ] || OWDAYS104=$((OWDAYS104 + 1)); done
  check "$(kv104 "$RCO104" remh)|$( [ "$OWF104" -ge "$(n103 "${LWIN104%%-*}")" ] && [ "$OWF104" -lt "$OWT104" ] && [ "$OWT104" -le "$(n103 "${LWIN104##*-}")" ] && echo narrow)|$( [ "$OWF104" -lt "$RMF104" ] && [ $(( RMF104 + 1 )) -le "$RMU104" ] && [ $(( RMF104 + 1 )) -le "$OWT104" ] && echo placed)|$( [ "$OWDAYS104" -ge 4 ] && echo days)" \
        "$RMH104|narrow|placed|days" \
    "control: 5o10's hours, read through the pin on the book as it stands (rh104-ref.php conf) — the reminder hours ReminderDay::hours() gives ($RMH104, as at 104-c, so the hour 5n' and 5k used holds) and the offer window WaSendBudget::window() gives ($OWIN104, inside the defaults $LWIN104); the window opens before the reminder hour, so the first weekday's early cycle at $(hh104 "$OWF104"):30 is inside it and before the reminders (JobWindow not due, ReminderDay not done), and the reminders cycle at $(hh104 "$RMF104"):30 and the offers cycle 300 s later end before the reminders' until-hour and the window's close; $OWDAYS104 weekdays in the week, more than the three that send"
  : > "$R104/logs/offer-week.txt"; OWN104=0; STOP104=""; WSTOP104=""; OWE104="none"; OF11_104=""; PPAUSE104=""; OSP104=""
  for d in 0 1 2 3 4 5 6; do
    dd104="$(kday104 $(( $(kat104 "$ODAY104 12:00") + d * 86400 )))"
    [ "$(kdow104 "$(kat104 "$dd104 12:00")")" = "7" ] && continue
    D0_104="$(kat104 "$dd104 00:00")"; first104=no; [ "$OWN104" = 0 ] && first104=yes
    if [ "$first104" = yes ]; then owm104="$(kat104 "$dd104 $(hh104 "$OWF104"):30")"; else owm104="$(kat104 "$dd104 $(hh104 "$RMF104"):30")"; fi
    clk_to104 "$owm104" || { echo "d=$d clock" >> "$R104/logs/offer-week.txt"; continue; }
    OWN104=$((OWN104 + 1))
    if [ "$first104" = yes ]; then
      # the early cycle: offers_send due, customer_reminders' entry carried as the schedule holds it
      tearly104="$(fnow104)"; eb104="$(sched104 customer_reminders)"; est104="$(remset104 "$eb104")"
      if [ -n "$est104" ]; then re104="$(master104 "offer-early-$d" --set "$est104" offers_send)"; else re104=nocarry; fi
      EW104="rc=$re104 runs=$(mrun104 "offer-early-$d") ran=$(ran104 offers_send "$tearly104") before=$( [ -n "$est104" ] && [ "$(n103 "${eb104%%:*}")" -lt "$(kat104 "$dd104 $(hh104 "$RMF104"):00")" ] && echo yes || echo no) carried=$( [ -n "$est104" ] && [ "$(rn104 "$(sched104 customer_reminders)")" = "$(rn104 "$eb104")" ] && echo yes || echo no) held=$(grep -c '\[offers_send\] .* code=reminders_pending$' "$R104/logs/master-offer-early-$d.out" 2>/dev/null)"
      clk_to104 "$(kat104 "$dd104 $(hh104 "$RMF104"):30")" || echo "d=$d clock:reminders" >> "$R104/logs/offer-week.txt"
    fi
    # the reminders cycle: customer_reminders alone due; judged right after it
    ts104="$(fnow104)"
    rr104="$(master104 "offer-rem-$d" customer_reminders)"; rem104="$(sched104 customer_reminders)"; rran104="$(ran104 customer_reminders "$ts104")"
    st104="$(remset104 "$rem104")"
    # the offers cycle, 300 s on: offers_send due, the reminder run's entry carried as master wrote it
    clk_to104 $(( $(fnow104) + 300 )); tsend104="$(fnow104)"
    if [ -n "$st104" ]; then rs104="$(master104 "offer-send-$d" --set "$st104" offers_send)"; else rs104=nocarry; fi
    rca104="$( [ -n "$st104" ] && [ "$(rn104 "$(sched104 customer_reminders)")" = "$(rn104 "$rem104")" ] && echo yes || echo no)"
    oc104="$(q104 oclaims "$DATA/plugin.sqlite3" "$D0_104" $(( D0_104 + 86400 )))"
    nf104="$(kv104 "$oc104" first)"
    hs104="$(kv104 "$oc104" byh | tr ',' '\n' | grep -c '[0-9]')"
    printf 'w=%s rc=%s%s runs=%s/%s ran=%s sran=%s carried=%s first_after=%s hs=%s\n' "$OWN104" "$rr104" "$rs104" "$(mrun104 "offer-rem-$d")" "$(mrun104 "offer-send-$d")" \
      "$rran104" "$(ran104 offers_send "$tsend104")" "$rca104" \
      "$( [ "$(n103 "$nf104")" = 0 ] && echo none || { [ "$(n103 "$nf104")" -ge "$(n103 "${rem104%%:*}")" ] && echo yes || echo no; } )" "$hs104" >> "$R104/logs/offer-week.txt"
    if [ "$first104" = yes ]; then
      # the wait's reading: the day's claims before the reminder run (the early cycle's included; that run judged
      # today's), beside the same day's handsets claimed after it — the positive control of that zero, by the same reader
      OWE104="$EW104 rem=$rran104 claims=$(kv104 "$(q104 oclaims "$DATA/plugin.sqlite3" "$D0_104" "$(n103 "${rem104%%:*}")")" n) after=$hs104"
      STOP104="$(kv104 "$(q104 offer "$DATA/plugin.sqlite3" "$OFID104" "+256700104061")" who)"
      clk_to104 $(( $(fnow104) + 300 ))
      WSTOP104="$(live104 webhook "$PD" "$DATA" "$WHUO104" '{"channel":"sales","phone":"+256700104061","name":"RH104SECRET-Apio","kind":"text","text":"STOP","id":"RH104STOP1"}')"
    fi
    [ "$OWN104" = 2 ] && OF11_104="$(q104 offer "$DATA/plugin.sqlite3" "$OFID104" "+256700104061")"
    if [ "$OWN104" = 3 ]; then
      PPAUSE104="$(wpost104 'action=offer_pause' "offer=$OFID104")"
      OSP104="$(kv104 "$(q104 offer "$DATA/plugin.sqlite3" "$OFID104")" state)"
    fi
  done
  OWD1_104="rc=00 runs=customer_reminders/offers_send ran=ran sran=ran carried=yes first_after=yes hs=$NH104"
  OWK104="$(grep -E '^w=[1-3] ' "$R104/logs/offer-week.txt" | sed -E 's/^w=[0-9]+ //' | paste -sd'/' -)"
  OWR104="$(grep -cxE 'w=[4-9] rc=00 runs=customer_reminders/offers_send ran=ran sran=ran carried=yes first_after=none hs=0' "$R104/logs/offer-week.txt")"
  check "$OWE104" "rc=0 runs=offers_send ran=ran before=yes carried=yes held=1 rem=ran claims=0 after=$NH104" \
    "5o10-a the first weekday at $(hh104 "$OWF104"):30, inside the offer window and before the reminder hour: an offers cycle with customer_reminders' entry carried as the schedule holds it (written before today's opening; unchanged after — master did not run the reminders, JobWindow not due) — master dispatched offers_send alone and it ran; it held (its own line: code=reminders_pending) and nothing was claimed that day before the reminder run (that day's own, judged ran); ReminderDay's wait itself, its positive control the same reader's claims on every one of the $NH104 handsets after the reminder run that day"
  check "$OWN104|$(grep -c . "$R104/logs/offer-week.txt")|$OWK104|$OWR104|$(grep -lF -f "$NAMES104" "$R104"/logs/master-offer-*.out 2>/dev/null | grep -c .)" \
        "$OWDAYS104|$OWDAYS104|$OWD1_104/$OWD1_104/$OWD1_104|$(( OWDAYS104 - 3 ))|0" \
    "5o10 a simulated week of master cycles ($OWN104 of the week's $OWDAYS104 weekdays, Sunday skipped, no clock move refused): each weekday the reminders cycle — master dispatching customer_reminders alone (its own RUN lines), the run judged finished right after its own cycle (last_run at or after it, duration not -1) — then, 300 s on, the offers cycle with that entry carried — master dispatching offers_send alone, its entry this cycle's and finished, ReminderDay reading master's run, the entry unchanged after; both cycles exit 0; every offer claim of the Kampala day at or after the day's reminder run; on each of the first three weekdays a claim on every one of the $NH104 handsets; on the later weekdays, the offer paused (5o12), the same two cycles and no claim; no name or number in master's output"
  check "$STOP104|$(qj "$WSTOP104" http 2>/dev/null)|$( [ "$(n103 "$(sq "SELECT COUNT(*) FROM contact_optouts WHERE active = 1 AND phone LIKE '%700104061'")")" -ge 1 ] && echo opted-out)|$(kv104 "$OF11_104" who)|$(kv104 "$OF11_104" state)|$(grep -lF -f "$SB/secrets104.pat" "$R104/offers-webhook.log" 2>/dev/null | grep -c .)" \
        "sent/-|200|opted-out|sent/set|running|0" \
    "5o11 a STOP reply through the real webhook from a recipient the first weekday's run had sent to (sent, no STOP mark yet): the number opted out (contact_optouts, active), its recipient row marked stop_at by the second weekday's run (OfferStore::marksPass: a STOP within 72 h of the send), read right after that run — the offer still running then (a STOP never pauses an offer by itself); no token or password in the webhook's log"
  OUT="$(sf104c off offers-live)"
  check "$OSP104|$(grep -c "\] \[wa_offers\] offer_pause offer=$OFID104 code=ok$" "$PD/data/plugin.log" 2>/dev/null)|$(sfrc104)|$(grep -cx 'OFF offers-live' <<<"$OUT")|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" offers_live_enabled,offers_live_since)" set)|$(kv104 "$(q104 offer "$DATA/plugin.sqlite3" "$OFID104")" state)" \
        "paused|1|0|1|0|paused" \
    "5o12 Pause through the installed offer_pause after the third weekday's offers cycle, while the offer still ran (the offer paused; its line in the plugin's log; the later weekdays claimed nothing, 5o10), then off offers-live after the week: step 12's keys cleared, the offer left paused"
  # The stand-ins of these cases stopped; the stand-in Evolution's requests (the tests, the offer sends, the control)
  # moved into this rehearsal's own log (counted there), so that 104-10's reading of what the deploys sent starts from
  # none; the session's jar, the helper's error file and its vault copy removed.
  for p104 in "${WPPID104:-}" "${WFRPID104:-}" "${WHPIDO104:-}"; do [ -z "$p104" ] || { kill "$p104" 2>/dev/null; wait "$p104" 2>/dev/null; }; done; WPPID104=""; WFRPID104=""; WHPIDO104=""
  EVO5O104="$(evo_calls)"; [ ! -f "$SB/evo/requests.log" ] || mv "$SB/evo/requests.log" "$R104/evo-offers.log"
  rm -f "$WJAR104" "$H104/v/live.err" "$H104/v/live-vault.json" "$H104/v/oadmin.err"
    # A KNOWN OLDER ISSUE, ruled by the owner on 8 Nov: evo_webhook.php logs an opted-out customer's number ("opt-out
  # recorded for <number> (matched …)", since 88c317a, 12 Sep — 5.18.103 does it as deployed; 5.18.104 does not change it).
  # 5.18.105 masks it, before step 12 (offers-live) is switched on. 5o11's STOP writes exactly one such line: left out of the
  # name scan by its exact form, counted (1, the positive control that the form is the one the exclusion names), and printed
  # as a note — every other line of these logs is scanned as before.
  OPTOUT104='^\[[^]]*\] \[evo_webhook\] opt-out recorded for \+?[0-9]+ \(matched "[a-z]+"\)$'
  nlog104() { local f n=0; for f in "$@"; do [ -f "$f" ] || continue; grep -vE "$OPTOUT104" "$f" | grep -qF -f "$NAMES104" && n=$((n+1)); done; echo "$n"; }   # nlog104 FILE… — the files holding a fixture name outside the known older line
  NOPT104="$(grep -cE "$OPTOUT104" "$R104/offers-webhook.log" 2>/dev/null)"
  echo "  note  RH-NOTE KNOWN OLDER ISSUE (owner's ruling, 8 Nov: masked in 5.18.105, before offers are switched on): the webhook logs an opted-out customer's number — ${NOPT104:-0} such line(s) in this section's webhook log, left out of 5o13's name scan by their exact form"
check "$(nlog104 "$R104/offers-web.log" "$R104/offers-webhook.log" "$R104"/logs/master-offer-*.out "$PD/data/plugin.log")|${NOPT104:-0}|$(grep -lF -f "$SB/secrets104.pat" "$R104/offers-web.log" "$R104/offers-webhook.log" "$PD/data/plugin.log" 2>/dev/null | grep -c .)|$(grep -c "\] \[wa_offers\] offer_\(save\|test\|start\|pause\) offer=$OFID104 code=" "$PD/data/plugin.log" 2>/dev/null)|$(grep -cxF -- "$OADM_PH104" "$NAMES104")|$(grep -cxF -- "$OAPW104" "$SB/secrets104.pat")|$(evo_calls)|$( [ "$(n103 "$EVO5O104")" -ge 4 ] && echo kept)|$(ls -A "$H104/v" | grep -cE '^(live|admin|oadmin|fake_openai)')" \
        "0|1|0|5|1|1|0|kept|0" \
    "5o13 no fixture name, number, e-mail or wording in the served plugin's log, the webhook's, master's or the plugin's own log (data/plugin.log, where post_offers.php writes) — the known older opt-out line aside, exactly one (5o11's STOP) — and no token or password; the positive controls: the plugin's log carries the five actions by id and code (save, two tests, start, pause), and the pattern files hold the admin's phone and password; the stand-in Evolution's $EVO5O104 requests kept in this rehearsal's own log; no record file of the helper's left"
  check "$(cat "$R104/logs/session-loss.txt" 2>/dev/null | grep -c .)" "0" \
    "5o13' the admin's session held through every offers action (none landed on the sign-in page: $(cut -d' ' -f2 "$R104/logs/session-loss.txt" 2>/dev/null | paste -sd, -))"
  dsnap104 offerend   # the offers book, for RH4
else
  echo "  note  RH-NOTE 5o Part O's live cases run only with the live cases (LIVE104=$LIVE104): the stand-in Evolution and the real webhook"
fi

# ── 104-5p: Part P (the agent's visible copy of the quotation e-mail; root docs/73 §H.5.14's rehearsal cases). D's first two
# cases — on agent-cc refused without plugin mail, and refused before step 1 has run then saved with step 1 run and every
# later step off (ruling R11) — are 104-5's 5a', 5c and 5c': the only moments the book had no plugin mail and step 1 had
# not run (mainend holds both; its control below). Here, through the RELEASE's own writers (rh104-seed.php under the
# clock: path 1 QuotationService::emailQuotePdf with the agent as the logged-in actor; path 3 whQuotationEmail's steps
# with its two P7 hunks, as 5.18.104's webhook.php runs them; the KYC application through the store's own record writer),
# to this rehearsal's fake relay on 127.0.0.1, each client and PDF the stand-in uCRM's: an app quote and a KYC quote each
# copied to the agent — one session, the client's RCPT then the agent's, the agent alone in Cc, decided added — and a
# quote typed in uCRM, which the webhook path e-mails like every quote uCRM announces (D P-D4 and §H.5's table, row 3) and
# resolves to no agent: one session to the client alone, no copy, decided no_agent. Then --after-only AC-ON1 at PASS;
# AC-CA2's refusal over hostile copied settings with a weakened copy of the script that skips the fresh-file step; and
# AC-L1, whose NO-GO is driven in 104-1 (1z) where stage A is reached — 5p8 is its control here. ──────────────────────────
echo; echo "== 104-5p. Part P: quotations copied to the agent (app, KYC), a quote typed in uCRM with none; AC-ON1 at PASS; AC-CA2's refusal on hostile copied settings; AC-L1 (driven in 1z) =="
if [ "$LIVE104" = yes ]; then check "$(drest104 mainend)" "same|same|same" "5p the agent-copy cases start from mainend"; else check "$(drest104 s2)" "same|same|same" "5p the agent-copy cases start from s2 (steps 1–5 on)"; fi
dsnap104 agentstart
check "$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" quote_agent_cc_enabled,quote_agent_cc_since)" set)|$(python3 -I -c 'import json, sys
try:
    d = json.load(open(sys.argv[1], encoding="utf-8")); print("plugin" if not d.get("use_ucrm_email") and str(d.get("smtp_host", "")).strip() else "other")
except (OSError, ValueError):
    print("absent")' "$DATA/email_settings.json")" "2|plugin" \
  "5p control: on this book agent-cc is on (both its keys set) over plugin SMTP set by the release's tools/email_setup.php — 104-5's 5c' saved it with step 1 run and every later step off, after 5c refused it without plugin mail and 5a' before step 1 (D's first two cases, ruling R11)"
seed104c() {   # seed104c CODE-ROOT OPS-JSON — seed104 under the rehearsal's clock (the decisions' and the e-mail log's stamps the clock's)
  local o; o="$(cd "$H104/v" && setpriv --reuid 1000 --regid 1000 --clear-groups -- env DN_VAULT_FILE="$H104/v/seed-vault.json" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" \
                "$PHPBIN" "$H104/rh104-seed.php" "$1" "$DATA" "$2" Africa/Kampala 2>"$H104/v/seed.err")"
  rm -f "$H104/v/seed-vault.json"
  case "$o" in '{"ops":'*) printf '%s' "$o" ;; *) echo "none($(head -c 160 "$H104/v/seed.err"))" ;; esac
}
# New quotations of this section's own (104-pre e-mailed 104901 and 104902 and holds their claims): three clients, each
# with its billing e-mail and its PDF at the stand-in uCRM while this section's file is beside its router.
RH104MAIL5P='{"clients":{"104931":{"first":"RH104SECRET-Okwir","last":"","email":"RH104SECRET-okwir@example.test"},"104932":{"first":"RH104SECRET-Atim","last":"","email":"RH104SECRET-atim@example.test"},"104933":{"first":"RH104SECRET-Lanyero","last":"","email":"RH104SECRET-lanyero@example.test"}},"pdf":[104911,104912,104913]}'
PCL104="RH104SECRET-okwir@example.test,RH104SECRET-atim@example.test,RH104SECRET-lanyero@example.test"
PAG104="RH104SECRET-amina@example.test,RH104SECRET-brian@example.test"   # the actor (app) and the KYC application's sales person (webhook): STAFF104_OPS's
printf '%s\n' "$PCL104" | tr ',' '\n' | tr 'A-Z' 'a-z' >> "$NAMES104"
mkdir -p "$MAILER104"; chown 1000:1000 "$MAILER104"; chmod 700 "$MAILER104"
relay_up104
printf '{"use_ucrm_email":false,"smtp_host":"127.0.0.1","smtp_port":%s,"smtp_user":"","smtp_pass":"","smtp_enc":"none","smtp_from":"%s","quote_email_via_plugin":true,"email_cc_contacts":true}\n' "$RPORT104" "$MAILFROM104" > "$MAILER104/email_settings.json"
chown 1000:1000 "$MAILER104/email_settings.json"; chmod 600 "$MAILER104/email_settings.json"
( umask 077; printf '%s\n' "$RH104MAIL5P" > "$U99/rh104-mail.json" ); : > "$U99/requests.log"
PQOPS104='[{"op":"kyc_app","quote_id":104912,"client_id":104932,"sales_person":"RH104SECRET-Brian"},
{"op":"quote_mail","path":"app","quote_id":104911,"client_id":104931,"number":"RH104SECRET-Q5-1","name":"RH104SECRET-Okwir","agent":"RH104SECRET-Amina","mailer":"@MAILER@"},
{"op":"quote_mail","path":"webhook","quote_id":104912,"client_id":104932,"number":"RH104SECRET-Q5-2","name":"RH104SECRET-Atim","mailer":"@MAILER@"},
{"op":"quote_mail","path":"webhook","quote_id":104913,"client_id":104933,"number":"RH104SECRET-Q5-3","name":"RH104SECRET-Lanyero","mailer":"@MAILER@"}]'
PQSEED104="$(seed104c "$PD" "${PQOPS104//@MAILER@/$MAILER104}")"
relay_down104; rm -f "$U99/rh104-mail.json"
check "$(python3 -I -c 'import json, sys
try:
    a = json.loads(sys.argv[1]); d = a.get("done", [])
    print("%d|%s|%s" % (a.get("ops", 0), ",".join(str(x.get("sent")) for x in d if x.get("op") == "quote_mail"), ",".join(str(x.get("p7")) for x in d if x.get("op") == "quote_mail")))
except ValueError:
    print("unread")' "$PQSEED104" 2>/dev/null)|$(python3 "$H99/rh99.py" u99other "$U99/requests.log")" \
      "4|True,True,True|-,added,no_agent|-" \
  "5p''' the three quotation e-mails through the release's own senders, agent-cc on: all three sent; the webhook path's P7 decision added for the KYC quote and no_agent for the quote typed in uCRM (the app path's is QuotationService's own row, read below); the stand-in uCRM asked GETs alone"
check "$(python3 "$H104/rh104q.py" relaycc "$RELAY104/transcript.json" "$PCL104" "$PAG104")|$(q104 acdec "$DATA/plugin.sqlite3" 104911,104912,104913)" \
      "sessions=3 kinds=CA,CA,C|104911=plugin:added 104912=webhook:added 104913=webhook:no_agent" \
  "5p'''b the relay's transcript, by this rehearsal's own reader: the app quote (path 1, the agent the logged-in actor) and the KYC quote (path 3, the application's sales person) each ONE session — the client's RCPT then the agent's, the agent alone in the Cc header of that same message — and each decided added; the quote typed in uCRM one session to the client alone, no agent in any RCPT or Cc, decided no_agent (its positive controls: the two sessions above carry the agent)"
rm -rf "$MAILER104"; : > "$U99/requests.log"
# --after-only AC-ON1 at PASS: agent-cc is on and the e-mails above gave the window its decisions; every other pair as the
# book stands (mainend: steps 1–6 on and agent-cc; s2: steps 1–5), each judged. AC-ON1's window ends MARGIN seconds before
# now (QuoteAgentCopy::MARGIN, read from the pin), so the clock first moves past it — read straight after the sends, the
# window held none of them: NOT_YET no_traffic (trial 4).
ACM104="$(git -C "$REPO" show "$P104:$P/lib/QuoteAgentCopy.php" 2>/dev/null | sed -nE 's/^ *private const MARGIN = ([0-9]+);.*/\1/p')"
[ -n "$ACM104" ] && clk_to104 $(( $(fnow104) + ACM104 + 60 )) || echo "acm104:margin" >> "$SB/anchor-missing"
if [ "$LIVE104" = yes ]; then
  aft104 "5p5 after the copies:" "QF-ON0:ack=judged" "QF-ON3:ledger=judged" "VA-ON1:ack=judged" "AC-ON1:agent-cc=pass" "QF-ON1:tracking=judged" "QF-ON2:list=judged" "VN-ON1:voice-measure=judged" "QF-ON4:chase=judged"
else
  aft104 "5p5 after the copies:" "QF-ON0:ack=judged" "QF-ON3:ledger=judged" "VA-ON1:ack=judged" "AC-ON1:agent-cc=pass" "QF-ON1:tracking=judged" "QF-ON2:list=judged" "VN-ON1:voice-measure=judged"
fi
AFTP104="$AFTT104"; GP0_104="$(gets104 "$AFT104")"
check "$(okline "$AFT104" AC-ON1 'agent-cc: PASS')|$(grep -lF -f "$NAMES104" "$R104/run-$AFTP104.txt" "$OUT104"/*.log 2>/dev/null | grep -c .)" "yes|0" \
  "5p6 --after-only AC-ON1: PASS — every plugin quotation e-mail in the window has its decision, each added one names its agent (path 1 from quote_mail.log, plugin mail as it reads now); no fixture name or address in its terminal or log"
# ── AC-CA2's refusal (P7S-02). The live email_settings.json made hostile, as the plugin's Settings → System → Email form
# leaves it with uCRM's mailer and the sent-mail copy on (raw — that form is an admin page this rehearsal does not drive
# for it; fictitious values): the seven keys AC-CA2's harness writes, with use_ucrm_email and sent_copy_enabled ON, and
# the sent copy's own host, port, user and password pointing at a stand-in IMAP of this rehearsal's on 127.0.0.1, which
# records every connection it is offered. A control run first (the script as it is: AC-CA2 writes its fresh file, so its
# copy reaches nothing); then a weakened copy of the script — asserted edits — whose AC-CA2 skips the fresh-file step:
# it copies the live settings beside the copy and into each sandbox in place of the fresh file, keeps them, and no longer
# refuses on their key set; AC-CA2's last guard refuses — use_ucrm_email/sent_copy_enabled on, in the installed tree's
# sandbox (u) — with nothing driven. The stand-in IMAP is offered nothing in either run, and the stand-in uCRM is asked
# exactly the GETs of the control run (AC-CA2's own uCRM is the pin's in-process recording fake; it never reaches ours). ──
cp -p "$DATA/email_settings.json" "$R104/email-settings.kept"
IMP104="$(free_port)"; : > "$R104/imap.log"
python3 -I - "$IMP104" "$R104/imap.log" <<'PY' > /dev/null 2>&1 &
import socket, sys
s = socket.socket(); s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1); s.bind(('127.0.0.1', int(sys.argv[1]))); s.listen(8)
while True:
    c, _ = s.accept()
    with open(sys.argv[2], 'a') as f:
        f.write('connection\n')
    try:
        c.sendall(b'* OK the rehearsal stand-in IMAP\r\n'); c.settimeout(2); c.recv(512)
    except OSError:
        pass
    c.close()
PY
IMPID104=$!; PIDS+=("$IMPID104")
for i in $(seq 1 50); do python3 -I -c 'import socket, sys; socket.create_connection(("127.0.0.1", int(sys.argv[1])), 1).close()' "$IMP104" 2>/dev/null && break; sleep 0.1; done
for i in $(seq 1 30); do [ "$(grep -c . "$R104/imap.log")" -ge 1 ] && break; sleep 0.1; done
IMC0_104="$(grep -c . "$R104/imap.log")"; : > "$R104/imap.log"   # the stand-in's positive control: the probe above was recorded
printf '%s\n' RH104SECRET-imap-not-a-password >> "$SB/secrets104.pat"
python3 -I - "$DATA/email_settings.json" "$IMP104" <<'PY'
import json, sys
json.dump({'use_ucrm_email': True, 'sent_copy_enabled': True, 'smtp_host': '127.0.0.1', 'smtp_port': 2525, 'smtp_enc': '',
           'smtp_from': 'RH104SECRET-quotes@example.test', 'quote_email_via_plugin': True,
           'sent_copy_host': '127.0.0.1', 'sent_copy_port': int(sys.argv[2]), 'sent_copy_user': 'RH104SECRET-imap@example.test',
           'sent_copy_pass': 'RH104SECRET-imap-not-a-password'}, open(sys.argv[1], 'w'))
PY
check "$( [ "$(n103 "$IMC0_104")" -ge 1 ] && echo heard)|$(python3 -I -c 'import json, sys; d = json.load(open(sys.argv[1])); print("%s%s%s" % (d["use_ucrm_email"], d["sent_copy_enabled"], d["sent_copy_port"] == int(sys.argv[2])))' "$DATA/email_settings.json" "$IMP104" 2>/dev/null)|$(stat -c '%a' "$DATA/email_settings.json")" "heard|TrueTrueTrue|${SFM104:-unread}" \
  "control: the stand-in IMAP on 127.0.0.1 records a connection offered to it (a probe, then its record emptied); the live email_settings.json now hostile — uCRM's mailer and the sent copy on, the sent copy pointed at that stand-in — its mode kept"
fresh104; OUT="$(run104c --after-only)"; TCA104="$(last104)"; GC1_104="$(gets104 "$OUT")"
check "$(okline "$OUT" AC-CA2 'the copy first made unable to reach a live system')|$(grep -c . "$R104/imap.log")" "yes|0" \
  "5p7 the control run (the script as it is) over the hostile live settings: AC-CA2 ok — its fresh email_settings.json made the copy unable to reach a live system before anything ran; the stand-in IMAP offered nothing"
M5P104="$REPO/scripts/.acca2skip.sh"; cp "$DEPLOY104" "$M5P104"
python3 -I - "$M5P104" <<'PY' || echo "weak104:acca2skip" >> "$SB/anchor-missing"
import sys
f = sys.argv[1]; s = open(f, encoding='utf-8').read()
reps = [
    # the live settings copied beside AC-CA2's copy, as a harness that copies the data folder's files would
    ('  if dxo cp -R "$P3B_BASE" "$sub/b103" \\\n',
     '  if dxo sh -c \'[ ! -f "$1" ] || cp "$1" "$2"\' _ "$PDD_IN/email_settings.json" "$sub/email_settings.json" && dxo cp -R "$P3B_BASE" "$sub/b103" \\\n'),
    # …kept (no longer removed with the copy's other settings files)
    ("$dir . '/vault.json', $dir . '/email_settings.json',", "$dir . '/vault.json',"),
    # the fresh-file step skipped: the copied settings put where the fresh file would go
    ("&& $put($T . '/data/email_settings.json', json_encode($ES7, JSON_UNESCAPED_SLASHES));",
     "&& @copy(dirname($T, 2) . '/email_settings.json', $T . '/data/email_settings.json');"),
    # …and their key set no longer refused, so the guard on use_ucrm_email/sent_copy_enabled is the one that speaks
    ("            if ($k !== $want) return 'email_settings_keys';\n", ""),
]
for a, b in reps:
    assert s.count(a) == 1, (a[:70], s.count(a))
    s = s.replace(a, b, 1)
    assert s.count(a) == 0 and (b == '' or s.count(b) == 1), a[:70]
assert s.count("dirname($T, 2) . '/email_settings.json'") == 1 and s.count("return 'email_settings_on'") == 1
open(f, 'w', encoding='utf-8').write(s)
PY
fresh104; OUT="$(R104_MUTANT_OUT=1 run104c --script "$M5P104" --after-only)"; TCM104="$(last104)"; GC2_104="$(gets104 "$OUT")"; rm -f "$M5P104"
check "$(has "$OUT" 'AC-CA2 REFUSED, nothing driven: email_settings_on_u')$(grep -c 'c6a=same' <<<"$OUT")$(okline "$OUT" AC-CA2 'the copy first made unable to reach a live system')|$(grep -c . "$R104/imap.log")|$(n103 "$GC2_104")=$(n103 "$GC1_104")|$(python3 "$H99/rh99.py" u99other "$R104/ucrm/run-$TCM104.req")|$(grep -lF -f "$NAMES104" "$R104/mutants/run-$TCM104.txt" "$OUT104"/*.log 2>/dev/null | grep -c .)|$(grep -lF -f "$SB/secrets104.pat" "$R104/mutants/run-$TCM104.txt" "$OUT104"/*.log 2>/dev/null | grep -c .)" \
      "yes0no|0|$(n103 "$GC1_104")=$(n103 "$GC1_104")|-|0|0" \
  "5p7' AC-CA2's refusal (P7S-02), the fresh-file step skipped in a weakened copy (the live settings copied into its sandboxes, kept, their key set no longer refused): AC-CA2 REFUSED, nothing driven — email_settings_on_u (uCRM's mailer and the sent copy on, in the installed tree's sandbox) — no case ran (no c6a=same, no ok line); the stand-in IMAP offered nothing; the stand-in uCRM asked the control run's GETs ($(n103 "$GC1_104")), GETs alone; no fixture name, address or secret in the run's terminal or log"
if [ -n "${IMPID104:-}" ]; then kill "$IMPID104" 2>/dev/null; wait "$IMPID104" 2>/dev/null; fi; IMPID104=""
cp -p "$R104/email-settings.kept" "$DATA/email_settings.json"; rm -f "$R104/email-settings.kept"
# AC-L1: its NO-GO is driven in 104-1 (1z), where stage A is reached; once 5.18.104 serves, --check stops before stage A.
# This is that case's control from the script's own text: the four files it lints, each with its pinned sha256, the
# container's php -l, and its one NO-GO.
check "$(sv104 "" AC_L1_FILES | grep -c 'lib/QuoteAgentCopy.php')|$(printf '%s\n' "$(sv104 "" AC_L1_SUMS)" | grep -c 'lib/QuoteAgentCopy.php:')|$(grep -c 'NO-GO (AC-L1): PHP' "$DEPLOY104")|$( [ "$(grep -c 'docker exec -i "$CONTAINER" php -l' "$DEPLOY104")" -ge 1 ] && echo lint)|${ACL1Z104:-not-run}" \
      "1|1|1|lint|yes" \
  "5p8 AC-L1's mechanism, the control of 1z's driven NO-GO: a stage-A lint of the pin's lib/QuoteAgentCopy.php and the three files P7 changes, each by its sha256, with the container's php -l, one NO-GO (P7S-07) — and 1z drove that NO-GO, naming lib/QuoteAgentCopy.php alone"
check "$(drest104 agentstart)|$( [ -e "$U99/rh104-mail.json" ] && echo left || echo gone)|$( [ -n "${RPID104:-}" ] && echo relay || echo down)" "same|same|same|gone|down" \
  "5p9 the book put back as the agent-copy cases found it (the quotation e-mails went to the relay, not the store's quotes; the live settings as they were); the stand-in uCRM's mail file gone, the relay down"
rm -f "$H104/v/live.err" "$H104/v/live-vault.json"

# ══ 104-6, 104-6v, 104-6w (RH4): the rollback in the one sequence, with every part on ═════════════════════════════════════
# The rollback as the operator runs it — its own command, exactly as the deploy's summary printed it after its verdict (104-4),
# ROLLBACK typed — over 5.18.104 with every part's keys on and every part holding work in flight (root docs/73 §H.2.16,
# §H.3.25, §H.4.19, §H.5.15; rulings R7, R11, R12, R14, R17): an open chase (a quotation tracked by the installed master's
# quote_chase, its quote follow-up opened, its day-1 draft approved, nothing sending it); a running offer (made, tested and
# started through the release's own OfferStore and OfferSender) with two claimed recipients — one holding its ledger claim,
# one without — and a test left sending; three voice notes recorded through the real webhook and not yet fetched (their
# audio rows pending, their ai.media and ai.voice_ack events pending: the webhook is served with exec disabled — its own
# fallback, "main.php picks the work up within a minute" — so it spawns no worker); one wa_voice_calls reservation (VN-RB6's
# question); plugin mail and agent-cc on; and every switch key of every row set through the release's own
# PluginConfig::saveOverrides — steps 7–9 among them, set by that writer rather than the tool's `on` (104-5v drives them on
# through the tool on its own copy of the book, its key put back after; on this book no OpenAI key is stored: nothing here can reach OpenAI). The run goes under the clock, the book's stamps being its own; while
# it runs a stand-in for cron runs the INSTALLED reply worker (run_worker.php, as main.php starts it) from the moment P5's
# switch reads off — when the ack hook's drain waits — so each ai.voice_ack event records switched_off and none is sent;
# the media worker is never run, so the ai.media events stay pending and are counted (ruling R14). Then a key held by
# <data>/config.json stops the rollback before the put-back (FAIL key_outside_overrides), and with QF-RB1's guard weakened
# AC-RB1's own read stops it; a 5.18.104 file left installed after the put-back is caught by QF-RB3 (beside QC-RB1 and
# RB's sha256), its weakened copy caught; then 104-6v and 104-6w as 5.18.103's: a deploy whose public sign-in page
# redirects rolls itself back, and the same with its own rollback not verifying prints the by-hand put-back alone, the
# hazard first. Fixtures are fictitious (RH104SECRET- names and wordings, the rehearsal's own phones, in its 0600 pattern
# file); counts go to the log, ids to the run's 0600 sheet alone. ─────────────────────────────────────────────────────────
echo; echo "== 104-6. the rollback, ROLLBACK typed, every part on and holding work: the one sequence, each part's hook and end state before the put-back, every *-RB line; a key held by <data>/config.json; a 5.18.104 file left installed =="
V103="$(sv104 "" BASELINE_VERSION)"; B103="$(sv104 "" BASELINE_COMMIT)"
check "$V103|$B103|$(ver "$B103")" "$(ver "$P103")|$P103|$(ver "$P103")" \
  "control: the script's rollback target — BASELINE_COMMIT $B103, BASELINE_VERSION $V103 — is 5.18.103's pin as the chain's own records name it, its manifest's version from Git; every line below that names it reads it from the script"
PH104="$(sv104 "" PUTBACK_HAZARD)"; PH104="${PH104//'$EXPECTED_VERSION'/$V104}"; PH104="${PH104//'$BASELINE_VERSION'/$V103}"
check "$(grep -c '^PUTBACK_HAZARD="' "$DEPLOY104")|$(grep -c '\$' <<<"$PH104")|$( [ -n "$PH104" ] && echo read)" "1|0|read" \
  "control: the put-back's hazard as the script states it (its one PUTBACK_HAZARD line, the two version names put in as it expands them) — no variable left"
MN104="$(python3 -I -c 'import sys; n = [x[:3] for x in sys.argv[1].split(",") if x]; print(", ".join(n[:-1]) + " and " + n[-1])' "$MIGF104" 2>/dev/null)"
L12_104="$(lbl104 offers-live)"; L11_104="$(lbl104 offers-test)"
check "$MN104|${L12_104%% *}|${L11_104%% *}|$( [ -n "$(kv104 "$LAD104" hooks | tr -d '-')" ] && echo hooks)|$(kv104 "$LAD104" vkeys | tr ',' '\n' | grep -c .)|$(kv104 "$LAD104" okeys | tr ',' '\n' | grep -c .)" \
      "$(tr ',' '\n' <<<"$MIGF104" | cut -c1-3 | python3 -I -c 'import sys; n = sys.stdin.read().split(); print(", ".join(n[:-1]) + " and " + n[-1])')|$(kv104 "$LAD104" labels | tr '|' '\n' | sed -n 's/^\([0-9a-z]*\):offers-live$/\1/p')|$(kv104 "$LAD104" labels | tr '|' '\n' | sed -n 's/^\([0-9a-z]*\):offers-test$/\1/p')|hooks|$(kv104 "$RK104" vswitch)|$(kv104 "$RK104" oswitch)" \
  "control: what the rollback's lines are read against, from the release's own code — its migrations from Git ($MN104), the steps' numbers and its hooks' places from the release's ladder ($(kv104 "$LAD104" hooks | tr ',' ' ')), Part V's and Part O's keys by the ladder's own part column, as many as 104-c's reading counted"
# ── The rollback's triggers, beside 5.18.103's (the same shapes, this release's names). In the fake docker, .rbfail104 — the
# first read of the container's record that names 5.18.103 after one that named 5.18.104 answers another commit
# (deploy-hybrid.sh, run by a deploy's own rollback after V1, sees its record read back wrong and exits 1: 104-6w). In the
# stand-in plugin, .redirect104 — the public sign-in page redirecting once 5.18.104's manifest is installed (V1: 104-6v,
# 104-6w) — and .left104m — at the first request once 5.18.103's manifest is installed (V, after stage A of a --rollback
# compared the files), each "<source>\t<installed path>" line copied over the installed file: a 5.18.104 file left in
# place after the put-back (104-6j). Each inserted by an asserted edit, armed only by a file of this rehearsal's, spent once.
cat > "$SB/docker104.hook" <<'HOOK'
# ── the next release's rehearsal (5.18.104): one trigger, armed by a file of the rehearsal's, spent once ──
if [ -f "@SB@/.rbfail104" ] && [ "$*" = "exec ucrm cat /data/ucrm/data/plugins/dishnet-hybrid-sudan/.deployed-commit" ]; then
  _rh_r4="$(tail -n1 "@PD@/.deployed-commit" 2>/dev/null | tr -cd '0-9a-f')"
  if [ "$_rh_r4" = "@P104@" ]; then : > "@SB@/.rbfail104.seen"
  elif [ "$_rh_r4" = "@P103@" ] && [ -f "@SB@/.rbfail104.seen" ]; then rm -f "@SB@/.rbfail104" "@SB@/.rbfail104.seen"; echo 0000000; exit 0; fi
fi
HOOK
python3 -I - "$SB/bin/docker" "$SB/docker104.hook" "$SB" "$PD" "$P103" "$P104" <<'PY' || echo "fakedocker:hook104" >> "$SB/anchor-missing"
import sys
p, hook, sb, pd, p103, p104 = sys.argv[1:7]
assert p103 and p104 and p103 != p104 and all(c in '0123456789abcdef' for c in p103 + p104), (p103, p104)
s = open(p).read()
h = open(hook).read().replace('@SB@', sb).replace('@PD@', pd).replace('@P103@', p103).replace('@P104@', p104)
assert '@' not in h.replace('"$@"', ''), 'a placeholder left'
lines = s.split('\n')
at = [i for i, l in enumerate(lines) if l.startswith('row() { grep "^$1|" ')]
assert len(at) == 1, len(at)
assert s.count('.rbfail104') == 0 and s.count('.rbfail103') == 5, (s.count('.rbfail104'), s.count('.rbfail103'))
lines[at[0]:at[0]] = h.rstrip('\n').split('\n')
s2 = '\n'.join(lines)
assert s2.count('.rbfail104') == 5 and s2.count('.rbfail103') == 5 and s2.count('_rh_r4') == 3
assert s2.index('.rbfail103') < s2.index('.rbfail104') < s2.index('row() { grep "^$1|" ')
open(p, 'w').write(s2)
PY
rm -f "$SB/docker104.hook"
cp -p "$PD/.deployed-commit" "$SB/dc104.kept"
HK1_104="$(dk104 exec ucrm cat "$DC104" | tail -n1)"; : > "$SB/.rbfail104"; HK2_104="$(dk104 exec ucrm cat "$DC104" | tail -n1):$( [ -f "$SB/.rbfail104.seen" ] && echo seen)"
printf '%s\n' "$P103" > "$PD/.deployed-commit"; HK3_104="$(dk104 exec ucrm cat "$DC104" | tail -n1)"; HK4_104="$(dk104 exec ucrm cat "$DC104" | tail -n1)"
cp -p "$SB/dc104.kept" "$PD/.deployed-commit"; HK5_104="$(dk104 exec ucrm cat "$DC104" | tail -n1)"
check "$(bash -n "$SB/bin/docker" && echo parses)|$HK1_104|$HK2_104|$HK3_104|$HK4_104|$HK5_104|$(ls -A "$SB" | grep -cE '^\.rbfail104(\.seen)?$')|$(cmp -s "$SB/dc104.kept" "$PD/.deployed-commit" && [ "$(stat -c %Y "$SB/dc104.kept")" = "$(stat -c %Y "$PD/.deployed-commit")" ] && echo same)" \
      "parses|$P104|$P104:seen|0000000|$P103|$P104|0|same" \
  "control: the fake docker's trigger — unarmed the record read truly; armed, a read naming 5.18.104 is seen and answered truly, the next naming 5.18.103 answered by another commit once, then the truth again; spent, the record as it was, byte and time"
rm -f "$SB/dc104.kept"
# ── RH4-fix1: four faults more in the fake docker, beside .rbfail104 and of its shape (an asserted insertion before its row()
# helper, each armed by a file of this rehearsal's and spent once), each answering "?rh104" with exit 3 instead of running
# the call: a reading the script makes in the container, whose failure root docs/73 names a path for (§H.2.16, §H.3.25,
# §H.5.14; DS2-fix1 R03, DS3-fix1, DS4): .rhf104.cihv the run's folder's vault copy at cih_init (QF-RB1's cih_main: its
# note, then every reading on a copy "could not be read"); .rhf104.acrb1 AC-RB1's read-back through the installed code (its
# stop when Part P cannot be read); .rhf104.rb3 QF-RB3's live read of the keys and the wa_voice_calls count; .rhf104.vncopy
# VN-RB5/VN-RB6's copy of the book (with .rb3: VN-RB6's count unread). Each is told by the mode word the script passes after
# its PHP source (vault, rb1, rb3) or by the copy's own folder (vn-rb5-…), never by a value typed here. ──
cat > "$SB/docker104f.hook" <<'HOOK'
# ── the next release's rehearsal (5.18.104, RH4-fix1): four faults, each armed by a file of the rehearsal's, spent once ──
if [ "${1:-}" = exec ] && compgen -G "@SB@/.rhf104.*" >/dev/null; then
  _rh_f4=""; _rh_n4=0; _rh_j4=0
  for _rh_a4 in "$@"; do _rh_j4=$((_rh_j4 + 1)); if [ "$_rh_n4" = 0 ] && [ "$_rh_a4" = "-r" ]; then _rh_n4=$((_rh_j4 + 2)); fi; done
  _rh_m4=""; if [ "$_rh_n4" -gt 0 ] && [ "$_rh_n4" -le "$#" ]; then _rh_m4="${!_rh_n4}"; fi
  case "$_rh_m4|${!#}" in
    "vault|/tmp/dnb-@V104@-cih-"*/*) ;;
    "vault|/tmp/dnb-@V104@-cih-"*) [ ! -f "@SB@/.rhf104.cihv" ] || _rh_f4=cihv ;;
    "rb1|"*) [ ! -f "@SB@/.rhf104.acrb1" ] || _rh_f4=acrb1 ;;
    "rb3|"*) [ ! -f "@SB@/.rhf104.rb3" ] || _rh_f4=rb3 ;;
  esac
  if [ -z "$_rh_f4" ] && [ -f "@SB@/.rhf104.vncopy" ]; then
    for _rh_a4 in "$@"; do case "$_rh_a4" in "/tmp/dnb-@V104@-cih-"*"/vn-rb5-"*"/plugin.sqlite3") _rh_f4=vncopy ;; esac; done
  fi
  if [ -n "$_rh_f4" ]; then rm -f "@SB@/.rhf104.$_rh_f4"; echo "$_rh_f4" >> "@SB@/rhf104.fired"; printf '%s' '?rh104'; exit 3; fi
fi
HOOK
python3 -I - "$SB/bin/docker" "$SB/docker104f.hook" "$SB" "$V104" <<'PY' || echo "fakedocker:faults104" >> "$SB/anchor-missing"
import sys
p, hook, sb, v = sys.argv[1:5]
assert sb.startswith('/') and v and '"' not in sb + v, (sb, v)
s = open(p).read()
h = open(hook).read().replace('@SB@', sb).replace('@V104@', v)
assert '@SB@' not in h and '@V104@' not in h, 'a placeholder left'
lines = s.split('\n')
at = [i for i, l in enumerate(lines) if l.startswith('row() { grep "^$1|" ')]
assert len(at) == 1, len(at)
assert s.count('.rhf104.') == 0 and s.count('_rh_f4') == 0 and s.count('_rh_r4') == 3, (s.count('.rhf104.'), s.count('_rh_f4'), s.count('_rh_r4'))
lines[at[0]:at[0]] = h.rstrip('\n').split('\n')
s2 = '\n'.join(lines)
assert s2.count('.rhf104.') == h.count('.rhf104.') == 6 and s2.count('_rh_r4') == 3 and s2.count('rhf104.fired') == 1
assert s2.index('_rh_r4') < s2.index('_rh_f4') < s2.index('row() { grep "^$1|" ')
open(p, 'w').write(s2)
PY
rm -f "$SB/docker104f.hook"
FCT104="/tmp/dnb-$V104-cih-rh104ctl"   # a folder of the run folder's shape, never made: each call below answers from PHP alone
fcall104() {   # fcall104 KIND — one call of each shape the four faults watch for, through the fake docker as the script makes it
  case "$1" in
    cihv)    dk104 exec -u 1000:1000 ucrm php -d display_errors=stderr -r 'echo "vault=present keys=0";' vault /data/x /data/y /data/z "$FCT104" ;;
    cihsub)  dk104 exec -u 1000:1000 ucrm php -d display_errors=stderr -r 'echo "vault=present keys=0";' vault /data/x /data/y /data/z "$FCT104/qo-a-1" ;;
    acrb1)   dk104 exec -u 1000:1000 ucrm php -d display_errors=stderr -d allow_url_fopen=0 -r 'echo "on=0 set=0 held=- admins=absent";' rb1 /data/x /data/y ;;
    rb3)     dk104 exec -u 1000:1000 ucrm php -d display_errors=stderr -r 'echo "set=0";' rb3 /data/x /data/x /data/y ;;
    vncopy)  dk104 exec -u 1000:1000 ucrm php -d display_errors=stderr -r 'echo "copy=ok n=1 side=no";' /data/y/plugin.sqlite3 "$FCT104/vn-rb5-1/plugin.sqlite3" keys ;;
    vnother) dk104 exec -u 1000:1000 ucrm php -d display_errors=stderr -r 'echo "copy=ok n=1 side=no";' /data/y/plugin.sqlite3 "$FCT104/vn-ofrb-2/plugin.sqlite3" keys ;;
  esac
}
F0_104="$(fcall104 cihv)|$(fcall104 acrb1)|$(fcall104 rb3)|$(fcall104 vncopy)"
: > "$SB/rhf104.fired"; for f in cihv acrb1 rb3 vncopy; do : > "$SB/.rhf104.$f"; done
F1_104="$(fcall104 cihsub)|$(fcall104 vnother)|$(ls -A "$SB" | grep -cE '^\.rhf104\.')"
F2_104="$(fcall104 cihv)|$(fcall104 acrb1)|$(fcall104 rb3)|$(fcall104 vncopy)|$(ls -A "$SB" | grep -cE '^\.rhf104\.')|$(paste -sd, "$SB/rhf104.fired")"
F3_104="$(fcall104 cihv)|$(fcall104 acrb1)|$(fcall104 rb3)|$(fcall104 vncopy)|$( [ -e "$FCT104" ] && echo made || echo none)"
check "$(bash -n "$SB/bin/docker" && echo parses)|$F0_104|$F1_104|$F2_104|$F3_104" \
      "parses|vault=present keys=0|on=0 set=0 held=- admins=absent|set=0|copy=ok n=1 side=no|vault=present keys=0|copy=ok n=1 side=no|4|?rh104|?rh104|?rh104|?rh104|0|cihv,acrb1,rb3,vncopy|vault=present keys=0|on=0 set=0 held=- admins=absent|set=0|copy=ok n=1 side=no|none" \
  "control: the fake docker's four faults — unarmed each call answered by PHP as asked; armed, a call of another shape (a reading's own folder under the run folder, another copy's) passes untouched and spends nothing; each watched call answered \"?rh104\" once, in order, every trigger spent; then the truth again"
: > "$SB/rhf104.fired"
python3 -I - "$SB/web/public.php" "$V104" "$V103" <<'PY' || echo "standin:triggers104" >> "$SB/anchor-missing"
import sys
p, v104, v103 = sys.argv[1:4]
assert v104 and v103 and v104 != v103
s = open(p).read()
old = "$page = $_GET['page'] ?? '';\n"
assert s.count(old) == 1, s.count(old)
assert s.count('redirect104') == 0 and s.count('left104m') == 0 and s.count("'.redirect103'") == 1 and s.count("'.left103m'") == 1
new = ("if (($_GET['page'] ?? '') === 'rh104_probe') { echo 'rh104-triggers-ready'; exit; }\n"
       "if (($_GET['page'] ?? '') === 'customer_login' && !isset($_GET['rh104v1']) && strpos((string)@file_get_contents($root . '/manifest.json'), '\"" + v104 + "\"') !== false && $spend('.redirect104')) { header('Location: ?page=customer_login&rh104v1=1', true, 302); exit; }   // the next rehearsal: the public sign-in page redirecting once " + v104 + " is installed (V1)\n"
       "$left104m = is_file($web . '/.left104m') ? trim((string)file_get_contents($web . '/.left104m')) : '';\n"
       "if ($left104m !== '' && strpos((string)@file_get_contents($root . '/manifest.json'), '\"" + v103 + "\"') !== false && $spend('.left104m')) {\n"
       "    foreach (explode(\"\\n\", $left104m) as $l104m) { $p104m = explode(\"\\t\", $l104m, 2); if (count($p104m) === 2 && $p104m[0] !== '' && $p104m[1] !== '') copy($p104m[0], $root . '/' . $p104m[1]); }   // the next rehearsal: a " + v104 + " file left in place after the put-back (QF-RB3)\n"
       "}\n")
s = s.replace(old, new + old, 1)
assert s.count("'.redirect104'") == 1 and s.count("'.left104m'") == 1 and s.count('rh104_probe') == 1 and s.count(old) == 1
assert s.count('explode("\\n", $left104m)') == 1 and s.count('explode("\\t", $l104m, 2)') == 1
open(p, 'w').write(s)
PY
for i in $(seq 1 60); do grep -q 'rh104-triggers-ready' <<<"$(pubget page=rh104_probe)" && break; sleep 0.1; done   # PHP re-reads the edited file
EVOW104=evo_webhook.php
printf 'x\n' > "$SB/web/.redirect104"; chmod 644 "$SB/web/.redirect104"
TR1_104="$(v1read102)|$( [ -e "$SB/web/.redirect104" ] && echo unspent || echo spent)|$(v1read102)"
git -C "$REPO" show "$P104:$P/$EVOW104" > "$SB/evow104.left" 2>/dev/null; chmod 644 "$SB/evow104.left"
printf '%s\t%s\n' "$SB/evow104.left" "$EVOW104" > "$SB/web/.left104m"; chmod 644 "$SB/web/.left104m"
pubget page=customer_login >/dev/null
TR2_104="$( [ -e "$SB/web/.left104m" ] && echo unspent || echo spent)|$(cmp -s "$PD/$EVOW104" "$SB/evow104.left" && echo pin)"
rm -f "$SB/web/.left104m"
check "$(pubget page=rh104_probe)|$(inst_ver)|$TR1_104|$TR2_104" "rh104-triggers-ready|$V104|200:1|spent|200:0|unspent|pin" \
  "control: the stand-in carries this rehearsal's two triggers — .redirect104 armed while $V104's manifest is installed redirects the sign-in page once (200 after 1 redirect, spent) and then not (200:0); .left104m armed does not fire while $V104's manifest is installed (unspent, the installed $EVOW104 the pin's) — it can fire only once $V103's is"
# The rollback's own runners: its printed line under the clock (run_line104 with the clock's two variables), the quarantine's
# --check under it too (the rollback's copy time is the clock's, and A14 of a later deploy asks for a --check begun after
# it), and the stand-in for cron (above).
run_line104c() {   # run_line104c WORD LINE — run_line104, under the rehearsal's clock: a command line exactly as a summary printed it
  local answer="$1" line="$2" n tag o cmd
  : > "$SB/docker.log"; : > "$U99/requests.log"; : > "$U99/limits.log"
  n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"; tag="$(printf '%03d' "$n")"
  vt_host > "$R104/vault/run-$tag.before"; : > "$R104/exec/run-$tag.rec"; : > "$R104/scan/run-$tag.hits"; : > "$R104/tok/run-$tag.tok"
  cmd="$(printf '%q ' env PATH="$SB/datebin:$SB/bin:$PATH" FAKE_TODAY="$AFTER94" DNB_OUT="$OUT104" DNB_RECORDS="$RECS" DNB_WEBSITE="$WEB_OK" GUARD_SECONDS=3 DN_DATA_DIR="$DATA" PLUGIN_BASE="$PLUGIN_BASE" \
         DNB_SALES_LOG_DIR="$SALES104" DNB_REPORT_DIR="$REPORT104" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" \
         FAKE_EXEC_LOG="$R104/exec/run-$tag.rec" FAKE_TMP_SCAN="$SCAN96" FAKE_TMP_HITS="$R104/scan/run-$tag.hits" FAKE_TOK_HITS="$R104/tok/run-$tag.tok" bash -c "$line")"
  o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r')"
  vt_host > "$R104/vault/run-$tag.after"; cp "$U99/requests.log" "$R104/ucrm/run-$tag.req"; cp "$U99/limits.log" "$R104/ucrm/run-$tag.lim"
  case "$o" in *"unknown option:"*) echo "line104-$n" >> "$SB/bad-runs" ;; esac
  printf '%s\n' "$o" > "$R104/line-$tag.txt"
  [ -z "${REHEARSE_KEEP:-}" ] || printf '%s\n' "$o" > "$REHEARSE_KEEP/line104-$tag-${answer:-none}.txt"
  printf '%s' "$o"
}
qrun104c() { qrun --env "LD_PRELOAD=$CLKPRE104" --env "RH104_CLOCK_FILE=$CLKF104" -- "$@"; }   # the quarantine's own run, under the clock
ackleft104() { python3 -I -c 'import sqlite3, sys
try:
    c = sqlite3.connect("file:" + sys.argv[1] + "?mode=ro", uri=True)
    print(c.execute("SELECT COUNT(*) FROM events WHERE event_type = ? AND status IN (?, ?)", ("ai.voice_ack", "pending", "processing")).fetchone()[0])
except Exception:
    print(-1)' "$DATA/plugin.sqlite3" 2>/dev/null; }   # the acknowledgement events still to be decided
ackoff104() { python3 -I -c 'import json, sys
try:
    d = json.load(open(sys.argv[1], encoding="utf-8"))
except Exception:
    d = {}
v = d.get("wa_voice_ack_enabled") if isinstance(d, dict) else None
sys.exit(0 if v is None or str(v).strip() == "" else 1)' "$DATA/kyc_config.json" 2>/dev/null; }   # P5's switch reads off in kyc_config.json
cron104_up() {   # cron104_up — the cron a server runs, for the length of one rollback: the INSTALLED reply worker (run_worker.php,
                 # as main.php starts it) as the book's owner under the clock, once a second from the moment P5's switch reads off
                 # — when the ack hook's drain begins to wait — until no acknowledgement event is left, the record names another
                 # commit, or 1800 s (RH4-fix1: in 6a step 5 first waits its whole drain bound for 6-6's deferred voice turn).
                 # Never the media worker. Its output kept for the sweeps; run in the main shell, never in $( ).
  : > "$R104/cron104.on"; : > "$R104/logs/cron104.runs"
  ( n=0
    while [ -f "$R104/cron104.on" ] && [ "$n" -lt 1800 ]; do
      n=$((n + 1))
      [ "$(live)" = "$P104" ] || break
      if ackoff104; then
        env PATH="$SB/bin:$PATH" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" bash "$SB/bin/docker" exec -u "$(owner104)" -e "DN_DATA_DIR=$PDD104_IN" ucrm \
          php -d log_errors=0 "$IN_CONTAINER/run_worker.php" </dev/null >> "$R104/logs/cron104.out" 2>&1
        echo run >> "$R104/logs/cron104.runs"
        [ "$(ackleft104)" = "0" ] && break
      fi
      sleep 1
    done ) &
  CRPID104=$!; PIDS+=("$CRPID104")
}
cron104_down() { rm -f "$R104/cron104.on"; [ -z "${CRPID104:-}" ] || wait "$CRPID104" 2>/dev/null; CRPID104=""; }
rb104r() { sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1; python3 "$H104/rh104q.py" rb104 "$DATA/plugin.sqlite3" "$@"; }   # rb104r [A B] — this rehearsal's reading of what the hooks act on
dx104() { data_dump | grep -vE '^(master_schedule|kyc_config|kyc):' | sha256sum | cut -c1-16; }   # the book and the vault but the settings row and file, which QF-RB1's saves rewrite even with every key unset
# RH4-fix1: a lock held as a sender mid-send holds it (6l), and a probe of it — each a process of the book's owner, flock(2)
# as PHP's flock() takes it — and the rows no writer of this sandbox can make (6-6, 6v): each said where it is planted.
mkdir -p "$R104/hold104"; chown 1000:1000 "$R104/hold104"; chmod 700 "$R104/hold104"; HOLDPID104=""
hold104() {   # hold104 FILE TAG MAXS — FILE held with LOCK_EX by a process of the book's owner from the moment $R104/hold104/TAG.held
              # appears until TAG.go does or MAXS seconds pass, TAG.released after; run in the main shell, never in $( )
  rm -f "$R104/hold104/$2".*
  ( cd "$R104/hold104" && exec setpriv --reuid 1000 --regid 1000 --clear-groups -- python3 -I -c 'import fcntl, os, sys, time
f, m, mx = sys.argv[1], sys.argv[2], int(sys.argv[3])
fd = os.open(f, os.O_RDWR | os.O_CREAT, 0o644)
fcntl.flock(fd, fcntl.LOCK_EX)
open(m + ".held", "w").write("%d\n" % int(time.time()))
t0 = time.time()
while not os.path.exists(m + ".go") and time.time() - t0 < mx:
    time.sleep(0.2)
fcntl.flock(fd, fcntl.LOCK_UN); os.close(fd)
open(m + ".released", "w").write("%d\n" % int(time.time()))' "$1" "$R104/hold104/$2" "$3" ) >/dev/null 2>&1 &
  HOLDPID104=$!; PIDS+=("$HOLDPID104")
  local i; for i in $(seq 1 100); do [ -f "$R104/hold104/$2.held" ] && return 0; sleep 0.1; done; return 1
}
unhold104() { : > "$R104/hold104/$1.go"; [ -z "${HOLDPID104:-}" ] || wait "$HOLDPID104" 2>/dev/null; HOLDPID104=""; }   # unhold104 TAG
probe104() {   # probe104 FILE — whether a second process of the book's owner could take FILE's lock now: busy, free or unopenable
  ( cd "$R104/hold104" && setpriv --reuid 1000 --regid 1000 --clear-groups -- python3 -I -c 'import fcntl, os, sys
try:
    fd = os.open(sys.argv[1], os.O_RDWR)
except OSError:
    print("unopenable"); sys.exit(0)
try:
    fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB); print("free"); fcntl.flock(fd, fcntl.LOCK_UN)
except OSError:
    print("busy")
os.close(fd)' "$1" ) 2>/dev/null
}
cat > "$H104/rbraw104.py" <<'PY'
# 104-6's raw rows (RH4-fix1), as the book's owner under the rehearsal's clock: states no writer of this sandbox makes — R4
# transcribes a voice note only through OpenAI, which nothing here may reach; a media worker mid-call is a worker stopped
# mid-call — each on the conversation of 6-2's first voice note (the webhook's own row names it), every text RH104SECRET-.
#   r4 LABEL       a voice note R4 transcribed and labelled (VoiceTranscription's own writes: the message body LABEL plus the
#                  transcript, its wa_media row understood at rung reply) and its one ai.reply turn with origin voice, as
#                  VoiceTranscription emits it, left pending and deferred an hour on (as WorkerDefer leaves a turn parked
#                  behind a person): msg=<id> media=<id> event=<id>
#   r3 LABEL       the same body labelled at rung handover — what R3 must never write (F10): msg=<id> media=<id>
#   inflight ST    an audio row in status ST, as the media worker leaves one mid-call (its attempt counted, its rung reply):
#                  media=<id>
#   drop T:ID …    this rehearsal's own plants taken out again (events, wa_media, wa_messages only): dropped=<n>
#   settle ID      a test of the rehearsal's own still sending, settled uncertain as P6's hook settles a stale one (091 never
#                  lets a test be deleted: wot_never_deleted): settled=<n>
import json, sqlite3, sys, time
db, mode, args = sys.argv[1], sys.argv[2], sys.argv[3:]
c = sqlite3.connect(db, timeout=15); c.execute('PRAGMA busy_timeout = 15000')
now = int(time.time())
st = lambda t: time.strftime('%Y-%m-%d %H:%M:%S', time.gmtime(t))
def base():
    r = c.execute("SELECT conversation_id, instance, channel, remote_jid FROM wa_media WHERE wa_message_id = ?", ('RH104RB0',)).fetchone()
    assert r is not None, 'no voice note of 6-2 on the book'
    return r
def note(tag, label, rung):
    conv, inst, ch, jid = base()
    body = label + ' RH104SECRET-the transcript of a voice note left by the rollback rehearsal, ' + tag
    m = c.execute("INSERT INTO wa_messages (conversation_id, direction, role, body, media_type, wa_message_id, sent_at, created_at) "
                  "VALUES (?, 'in', 'customer', ?, 'audio', ?, ?, ?)", (conv, body, 'RH104RB' + tag, st(now), st(now))).lastrowid
    w = c.execute("INSERT INTO wa_media (conversation_id, message_id, wa_message_id, instance, channel, remote_jid, kind, mimetype, seconds, "
                  "status, attempts, voice_rung, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 'audio', 'audio/ogg', 7, 'understood', 1, ?, ?, ?)",
                  (conv, m, 'RH104RB' + tag, inst, ch, jid, rung, st(now), st(now))).lastrowid
    return conv, inst, ch, jid, m, w, body
if mode == 'r4':
    conv, inst, ch, jid, m, w, body = note('R4', args[0], 'reply')
    ph = c.execute('SELECT phone FROM wa_conversations WHERE id = ?', (conv,)).fetchone()
    p = {'channel': ch, 'whatsapp_instance': inst, 'customer_phone': ph[0] if ph else '', 'message': body, 'push_name': 'RH104SECRET-Caller',
         'wa_message_id': 'RH104RBR4', 'remote_jid': jid, 'received_at': time.strftime('%Y-%m-%dT%H:%M:%S+00:00', time.gmtime(now)),
         'location': None, 'origin': 'voice', 'voice': {'media_id': w, 'seconds': 7, 'chars': len(body)}}
    e = c.execute("INSERT INTO events (event_type, entity_type, entity_id, payload, status, priority, attempts, next_retry_at, created_at, created_by) "
                  "VALUES ('ai.reply', 'conversation', ?, ?, 'pending', 3, 1, ?, ?, 'media_worker')",
                  (conv, json.dumps(p, ensure_ascii=False, separators=(',', ':')), st(now + 3600), st(now))).lastrowid
    c.commit(); print('msg=%d media=%d event=%d' % (m, w, e))
elif mode == 'r3':
    conv, inst, ch, jid, m, w, body = note('R3', args[0], 'handover')
    c.commit(); print('msg=%d media=%d' % (m, w))
elif mode == 'inflight':
    conv, inst, ch, jid = base()
    w = c.execute("INSERT INTO wa_media (conversation_id, message_id, wa_message_id, instance, channel, remote_jid, kind, mimetype, seconds, "
                  "status, attempts, voice_rung, created_at, updated_at) VALUES (?, NULL, 'RH104RBF1', ?, ?, ?, 'audio', 'audio/ogg', 7, ?, 1, 'reply', ?, ?)",
                  (conv, inst, ch, jid, args[0], st(now - 30), st(now - 20))).lastrowid
    c.commit(); print('media=%d' % w)
elif mode == 'drop':
    n = 0
    for a in args:
        t, i = a.split(':')
        assert t in ('events', 'wa_media', 'wa_messages') and i.isdigit() and int(i) > 0, a
        n += c.execute('DELETE FROM ' + t + ' WHERE id = ?', (int(i),)).rowcount
    c.commit(); print('dropped=%d' % n)
elif mode == 'settle':
    assert args and args[0].isdigit() and int(args[0]) > 0
    n = c.execute("UPDATE wa_offer_tests SET state = 'uncertain', settled_at = ? WHERE id = ? AND state = 'sending'", (st(now), int(args[0]))).rowcount
    c.commit(); print('settled=%d' % n)
else:
    raise SystemExit('a mode this helper does not know')
PY
chmod 644 "$H104/rbraw104.py"
rbraw104() {   # rbraw104 MODE ARG… — rbraw104.py on the live book as its owner, under the clock; one line, or none(<why>)
  local o
  o="$(cd "$DATA" && setpriv --reuid 1000 --regid 1000 --clear-groups -- env LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" python3 -I "$H104/rbraw104.py" "$DATA/plugin.sqlite3" "$@" 2>>"$R104/logs/rbraw104.err")"
  case "$o" in [a-z]*=*) printf '%s' "$o" ;; *) echo "none($(tail -n1 "$R104/logs/rbraw104.err" 2>/dev/null | head -c 120))" ;; esac
}
# ── 6-setup: the book with every part on and holding work. ───────────────────────────────────────────────────────────
if [ "$LIVE104" = yes ]; then
  check "$(drest104 mainend)" "same|same|same" "6 the rollback's book starts from mainend (104-5): steps 1–6 on, the first chase answered and closed, 21 voice notes measured; agent-cc and steps 7–12 off"
  RMH6_104="$(kv104 "$RC104" remh)"; RMF6_104="$(n103 "${RMH6_104%%-*}")"; RMU6_104="$(n103 "${RMH6_104##*-}")"
  F6_104="$(fnow104)"; D6_104=""
  for d in 1 2 3 4 5 6 7 8; do
    t="$(kat104 "$(kday104 $(( F6_104 + d * 86400 ))) 08:20")"
    [ "$t" -gt $(( F6_104 + 600 )) ] && [ "$(kdow104 "$t")" != "7" ] && [ "$(kdow104 $(( t + 86400 )))" != "7" ] && { D6_104="$(kday104 "$t")"; break; }
  done
  D61_104="$(kday104 $(( $(kat104 "$D6_104 12:00") + 86400 )))"
  check "$(epoch99 "$(kat104 "$D6_104 08:20")")|$( [ "$(kdow104 "$(kat104 "$D61_104 08:20")")" != 7 ] && echo weekday)|$( [ "$RMF6_104" -ge 1 ] && [ "$RMF6_104" -le 9 ] && [ "$RMU6_104" -ge 10 ] && echo hours)" "epoch|weekday|hours" \
    "control: the chase's day ($D6_104) and its day 1 ($D61_104), neither a Sunday, ahead of the clock; the reminder hours the pin's ReminderDay reads ($RMH6_104) open by 09:02, when the day's reminder run is recorded finished, and close after 09:30, when the offer's recipients are claimed"
  Q6PH104="+256700104051"; QREF6_104="RH104SECRET-Q6"
  python3 "$H104/rh104q.py" phones '[{"phone":"+256700104051"},{"phone":"+256700104052"},{"phone":"+256700104053"},{"phone":"+256700104054"}]' >> "$NAMES104"
  # 6-1: the open chase — the quotation as the Send Quotation path stores its text, the customer's question five minutes
  # before it; the installed master's quote_chase tracks it (D 08:25) and on day 1 drafts the chase, approved; no
  # followup_send is run, so nothing sends it.
  clk_to104 "$(kat104 "$D6_104 08:20")"
  Q6_104="$(live104 quote "$PD" "$DATA" "{\"phone\":\"$Q6PH104\",\"channel\":\"sales\",\"name\":\"RH104SECRET-Opio\",\"ref\":\"$QREF6_104\",\"in_text\":\"RH104SECRET-a price for two kits please\",\"gap_min\":5}")"
  clk_to104 "$(kat104 "$D6_104 08:25")"; M6A104="$(master104 rb-a quote_chase)"
  clk_to104 "$(kat104 "$D61_104 08:25")"; M6B104="$(master104 rb-b quote_chase)"
  T6_104="$(python3 "$H104/rh104q.py" tracker "$DATA/plugin.sqlite3" "ref:$QREF6_104")"
  check "$(python3 -I -c 'import json, sys; d = json.loads(sys.argv[1]); print("ids" if d["conv"] > 0 and d["in"] > 0 and d["quote"] > 0 else "missing")' "$Q6_104" 2>/dev/null || echo "unread:${Q6_104:0:80}")|$M6A104|$M6B104|$T6_104" \
        "ids|0|0|status=open hold=- followup=quote closed=open drafts=1/1" \
    "6-1 an open chase: a quotation to a fixture customer on the sales number through the installed ConversationService; the installed master's quote_chase tracked it and opened its quote follow-up, and on day 1 drafted the chase, approved — nothing sends it"
  # 6-2: three voice notes through the real webhook, served with exec disabled (the webhook's own fallback when it cannot
  # spawn a worker): each recorded for the media worker (a pending audio row, an ai.media event) and its acknowledgement
  # queued (an ai.voice_ack event) — and nothing takes them up: the state a rollback meets seconds after notes arrive.
  WHP6_104="$(free_port)"; WHU6_104="http://127.0.0.1:$WHP6_104/evo_webhook.php"
  setpriv --reuid 1000 --regid 1000 --clear-groups -- env LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" "$PHPBIN" -d disable_functions=exec -S "127.0.0.1:$WHP6_104" -t "$PD" >"$R104/rb-webhook.log" 2>&1 & WHPID6_104=$!; PIDS+=("$WHPID6_104")
  for i in $(seq 1 50); do [ "$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "$WHU6_104")" = "405" ] && break; sleep 0.1; done
  VB6_104="$(rb104r)"; : > "$R104/logs/rb-posts.json"
  for i in 0 1 2; do
    clk_to104 $(( $(kat104 "$D61_104 09:00") + 25 * i ))
    live104 webhook "$PD" "$DATA" "$WHU6_104" "{\"channel\":\"sales\",\"phone\":\"+2567001040$(( 52 + i ))\",\"name\":\"RH104SECRET-Caller\",\"kind\":\"audio\",\"text\":\"\",\"id\":\"RH104RB$i\"}" >> "$R104/logs/rb-posts.json"; echo >> "$R104/logs/rb-posts.json"
  done
  sleep 2; WKS6_104="$(pgrep -f "$PD/run_(media_)?worker\.php" 2>/dev/null | grep -c .)"
  if [ -n "${WHPID6_104:-}" ]; then kill "$WHPID6_104" 2>/dev/null; wait "$WHPID6_104" 2>/dev/null; fi; WHPID6_104=""
  VA6_104="$(rb104r)"
  dv6() { echo $(( $(n103 "$(kv104 "$VA6_104" "$1")") - $(n103 "$(kv104 "$VB6_104" "$1")") )); }
  check "$(grep -c '"http":200' "$R104/logs/rb-posts.json")|$WKS6_104|$(dv6 ap)|$(dv6 em)|$(dv6 ea)|$(kv104 "$VA6_104" ap)" "3|0|3|3|3|3" \
    "6-2 three voice notes through the real webhook (exec disabled: no worker spawned): three audio rows pending, three ai.media and three ai.voice_ack events pending, by this rehearsal's own reading — the only pending audio rows on the book"
  # 6-3: every part on — every switch key of every row, its stamp and options, the ledger's and the record keys, each where
  # it does not read set already, through the release's own PluginConfig::saveOverrides (steps 7–9 included: a state the
  # tool's own `on` reaches only with an OpenAI key and weeks on the clock — 104-5v's, on its own copy — said here, and a state that calls nothing: no OpenAI key is stored on this book).
  clk_to104 "$(kat104 "$D61_104 09:05")"
  AO104="$(live104 allon "$PD" "$DATA" '{"statement":"RH104SECRET-support assistant on, the rehearsal statement","channels":"sales"}')"
  # 6-4': two warm conversations on sales for 6-4's audience, through the installed ConversationService as 5o9-a makes its
  # six. The book is mainend's (104-5's end), whose own customers last wrote before this clock's warm window
  # (offers_warm_days, 30 by default): the clock only moves forward, and 104-5v and 104-5p took it weeks on — trial 4's
  # Start answered nobody_eligible (their audience read at 15 Nov: 2 eligible; at 7 Dec: 0). +256700104070/71 are in the
  # sweep's pattern file already (5o's 60–89).
  WRM6_104="$(live104 warm "$PD" "$DATA" '{"convs":[
{"phone":"+256700104070","channel":"sales","name":"RH104SECRET-Abalo","text":"RH104SECRET-thanks for the kit details","reply":"RH104SECRET-you are welcome","ago_min":150,"reply_after_min":5},
{"phone":"+256700104071","channel":"sales","name":"RH104SECRET-Opio","text":"RH104SECRET-thanks for the kit details","reply":"RH104SECRET-you are welcome","ago_min":180,"reply_after_min":5}]}')"
  check "$(qj "$WRM6_104" warm 2>/dev/null)" "2" \
    "6-4' two warm conversations on sales, through the installed ConversationService (the customer's text, then the assistant's answer) — the offer's audience on this clock"
  # 6-4: the running offer, through the release's own writers: a draft, a test of each wording to the fixture admin's own
  # phone through the stand-in Evolution, a test left sending 20 minutes before (raw: only a stopped sender leaves one),
  # then Start; the day's reminder run recorded finished (master's own form, through the store's save: a state a sandbox
  # cycle cannot hold, as 104-5's 5k'' says); then two of its recipients left claimed as a stopped sender leaves them —
  # one with its ledger claim (WaSendBudget's own claim under its stable ref), one without.
  clk_to104 "$(kat104 "$D61_104 09:10")"; EV6_104="$(evo_calls)"
  OP6_104="$(live104 offerprep "$PD" "$DATA" '{"title":"RH104SECRET offer of the rollback","w1":"RH104SECRET-first wording of the rollback offer, well over twenty characters","w2":"RH104SECRET-second wording of the rollback offer, unlike the first one","channel":"sales"}')"
  OF6_104="$(n103 "$(qj "$OP6_104" offer 2>/dev/null)")"
  clk_to104 "$(kat104 "$D61_104 09:12")"
  OS6_104="$(live104 offerstale "$PD" "$DATA" "{\"offer\":$OF6_104,\"age_s\":1200}")"
  clk_to104 "$(kat104 "$D61_104 09:13")"
  OT6_104="$(live104 offerstart "$PD" "$DATA" "{\"offer\":$OF6_104}")"
  live104 schedule "$PD" "$DATA" "{\"due\":[],\"set\":{\"customer_reminders\":{\"last_run\":$(kat104 "$D61_104 09:02"),\"duration_ms\":420000}}}" > "$R104/logs/rb-schedule.plant"
  clk_to104 "$(kat104 "$D61_104 09:30")"
  OC6_104="$(live104 offerclaim "$PD" "$DATA" "{\"offer\":$OF6_104}")"
  if [ "$(qj "$OC6_104" a 2>/dev/null)" = "0" ] && [ "$(n103 "$(qj "$OC6_104" retry_at 2>/dev/null)")" -gt "$(fnow104)" ]; then   # the ledger's own deferral, once: the clock to its retry_at
    clk_to104 $(( $(qj "$OC6_104" retry_at) + 5 )); OC6_104="$(live104 offerclaim "$PD" "$DATA" "{\"offer\":$OF6_104}")"
  fi
  RA6_104="$(n103 "$(qj "$OC6_104" a 2>/dev/null)")"; RB6_104="$(n103 "$(qj "$OC6_104" b 2>/dev/null)")"
  check "$( [ "$OF6_104" -ge 1 ] && echo draft)|$(qj "$OP6_104" tests 2>/dev/null)|$( [ "$(( $(evo_calls) - EV6_104 ))" -ge 2 ] && echo evolution)|$(qj "$OS6_104" stale 2>/dev/null)|$(qj "$OT6_104" ok 2>/dev/null)$(qj "$OT6_104" code 2>/dev/null)|$( [ "$(n103 "$(qj "$OT6_104" queued 2>/dev/null)")" -ge 2 ] && echo queued)|$( [ "$RA6_104" -ge 1 ] && [ "$RB6_104" -ge 1 ] && [ "$RA6_104" != "$RB6_104" ] && echo claimed)" \
        "draft|sent,sent|evolution|1|True|queued|claimed" \
    "6-4 a running offer through the release's own OfferStore and OfferSender: a draft, each wording tested to the fixture admin's own phone through the stand-in Evolution (sent, recorded), a test left sending 20 minutes before, Start (queued $(qj "$OT6_104" queued 2>/dev/null)); two recipients left claimed as a stopped sender leaves them — one holding its ledger claim, one without"
  # 6-5: one wa_voice_calls row, through the release's VoiceBudget::reserve — the reservation the media worker writes before
  # a call to OpenAI, which never comes (VN-RB6's question: whenever any row exists, the instruction to rotate the key)
  VC6_104="$(live104 vcall "$PD" "$DATA")"
  # The stand-in Evolution's requests these fixtures caused (the offer's two tests) moved into this rehearsal's own log, so
  # that 104-10's "Evolution never called" reads the deploys' alone, from none
  EVR6_104="$(evo_calls)"; [ ! -f "$SB/evo/requests.log" ] || mv "$SB/evo/requests.log" "$R104/evo-rb.log"
  KB6_104="$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" "$ALLCSV104")"; RBB104="$(rb104r "$RA6_104" "$RB6_104")"
  check "$(qj "$VC6_104" ok 2>/dev/null)$(qj "$VC6_104" reason 2>/dev/null)|$(grep -c '' "$R104/evo-rb.log" 2>/dev/null || echo 0)|$(evo_calls)|$RBB104" \
        "True|$EVR6_104|0|oq=$(kv104 "$RBB104" oq) od=$(kv104 "$RBB104" od) cr=$(kv104 "$RBB104" cr) ex=$(kv104 "$RBB104" ex) ap=3 as=$(kv104 "$RBB104" as) em=$(kv104 "$RBB104" em) ea=3 ks=$(kv104 "$RBB104" ks) vc=1 or_=1 op=0 cl=1 cn=1 ts=1 ra=claimed/-/other/open rb=claimed/-/noledger" \
    "6-5 a wa_voice_calls reservation through the release's VoiceBudget (no call made); the stand-in Evolution's $EVR6_104 request(s) moved to this rehearsal's own log; this rehearsal's reading before the rollback: 3 audio rows pending, 3 acknowledgement events, one reservation, one offer running with one claimed recipient holding a ledger claim (open) and one without, one test still sending"
  check "$( [ "$(n103 "$(kv104 "$RBB104" oq)")" -ge 1 ] && [ "$(n103 "$(kv104 "$RBB104" od)")" -ge 1 ] && echo chase)|$( [ "$(n103 "$(kv104 "$RBB104" em)")" -ge 3 ] && echo media)" "chase|media" \
    "…the positive controls of the hooks' counts below: at least the one open quote follow-up and its approved draft (oq $(kv104 "$RBB104" oq), od $(kv104 "$RBB104" od)), at least the three ai.media events (em $(kv104 "$RBB104" em))"
else
  echo "  note  RH-NOTE 6 the live cases did not run (LIVE104=$LIVE104): the rollback runs over every part's keys alone — no chase, offer, voice note or reservation for its hooks to act on, so their counts are not shown here"
  check "$(drest104 s2)" "same|same|same" "6 the rollback's book starts from s2 (steps 1–5 on)"
  AO104="$(live104 allon "$PD" "$DATA" '{"statement":"RH104SECRET-support assistant on, the rehearsal statement","channels":"sales"}')"
  KB6_104="$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" "$ALLCSV104")"; RA6_104=0; RB6_104=0; RBB104="$(rb104r)"
fi
SHOW6_104="$(sf104c show)"; NON104="$(kv104 "$KB6_104" set)"; KBN104="$(kv104 "$KB6_104" names)"
check "$(qj "$AO104" ok 2>/dev/null)|$(qj "$AO104" after 2>/dev/null)|$(grep -cE '^  (1b agent-cc \(optional, P7\)|[0-9]+ [a-z-]+( \(optional\))?): on( since [0-9: -]+)?(;|$)' <<<"$SHOW6_104")|$(grep -c 'RH104SECRET' <<<"$SHOW6_104")" \
      "True|$NON104|$NSTEPS104|0" \
  "6-3 every part on: the release's own saveOverrides wrote what did not read set already — the installed tool's show, as the operator runs it, reads all $NSTEPS104 steps on; $NON104 of allKeys() set, by this rehearsal's reading and by PluginConfig::read alike; no statement or name printed"
VWON104="$(python3 -I -c 'import sys; a = set(x for x in sys.argv[1].split(",") if x and x != "-"); print(sum(1 for x in sys.argv[2].split(",") if x in a))' "$KBN104" "$(kv104 "$LAD104" vkeys)")"
OWON104="$(python3 -I -c 'import sys; a = set(x for x in sys.argv[1].split(",") if x and x != "-"); print(sum(1 for x in sys.argv[2].split(",") if x in a))' "$KBN104" "$(kv104 "$LAD104" okeys)")"
V3GW104="$(python3 -I -c 'import re, sys; ks = [k for k in sys.argv[1].split(",") if re.match(r"^(ai_|evo_|openai_|claude_)", k)]; print("\n".join(["files." + k for k in ks] + ["store." + k for k in ks]))' "$KBN104" | LC_ALL=C sort | paste -sd, -)"
# RH4-fix1 (6s): Part P's setting quote_agent_cc_admins given a value through the installed tool's own `set` (the writer
# ruling R5 leaves the settings to), so that AC-RB1's "as it was" and the rule that the rollback leaves the setting alone are
# shown over a value that could differ from the default, never over "absent" twice.
ADB104="$(python3 "$H104/rh104q.py" admins "$DATA/plugin.sqlite3" "$DATA/kyc_config.json")"
SA6_104="$(sf104c set agent-cc-admins 0)"; SAR6_104="$(sfrc104)"
ADM6_104="$(python3 "$H104/rh104q.py" admins "$DATA/plugin.sqlite3" "$DATA/kyc_config.json")"
check "$ADB104|$(grep -cx 'SET quote_agent_cc_admins = 0' <<<"$SA6_104")|$SAR6_104|$ADM6_104|$(grep -c 'RH104SECRET' <<<"$SA6_104")" "file=absent store=absent|1|0|file=0 store=0|0" \
  "6s quote_agent_cc_admins set to 0 through the installed tool (set agent-cc-admins 0, as the operator would): absent in both copies before by this rehearsal's own reading, 0 in the settings file and the store row after — the value AC-RB1 reads before the tool and again after it"
dsnap104 rbfull   # the book as the rollback finds it, for each run below that stops before the put-back
# ── 6k: a key held by <data>/config.json — raw, as 104-1's 1i plants it: that file is never the tool's to write — Part P's
# switch there beside the store's own copy. The installed tool's off all --rollback clears every key it owns and runs every
# hook, then its read-back finds Part P's switch still read on: FAIL key_outside_overrides, naming the file and never the
# value, and the agent copy still reading on; it exits 3, and QF-RB1 stops the rollback before the put-back, the by-hand
# put-back printed as its own block. Then QF-RB1's guard weakened: the run goes on to AC-RB1, whose own read stops it. ──
KPK104=quote_agent_cc_enabled
plant104k() {   # plant104k — Part P's switch, as `on agent-cc` writes it (the ladder's own value), into <data>/config.json
  python3 -I - "$DATA/config.json" "$KPK104" "$(git -C "$REPO" show "$P104:$P/lib/SalesFollowupLadder.php" 2>/dev/null | sed -n "s/.*'switch' => \['$KPK104' => '\([^']*\)'\].*/\1/p" | head -1)" <<'PY'
import json, os, sys
p, k, v = sys.argv[1:4]
assert v, 'no value read from the ladder'
d = json.load(open(p, encoding='utf-8')) if os.path.exists(p) else {}
d[k] = v
json.dump(d, open(p, 'w', encoding='utf-8'))
PY
  chown 1000:1000 "$DATA/config.json"
}
check "$(kv104 "$LAD104" lastkeys | tr ',' '\n' | grep -cx "$KPK104")|$(tr ',' '\n' <<<"$KBN104" | grep -cx "$KPK104")" "1|1" \
  "control: the planted key is one of Part P's two (lastKeys(), the ladder's own) and is set in the store's copy too — the tool clears that one; the planted one shows through"
plant104k
EVK104="$(evo_calls)"
cron104_up; fresh104; OUT="$(run104c --answer ROLLBACK --rollback)"; T6K104="$(last104)"; cron104_down
SHK6_104="$(ls -1 "$OUT104"/cash-*.txt 2>/dev/null | head -1)"
check "$(has "$OUT" "  STOP: QF-RB1 FAILED: the installed tool's off all --rollback exited 3 — a hook threw, a lock was not taken in 70 s, or an end state or a read-back did not hold")$(grep -cxF "  tool    | FAIL key_outside_overrides $KPK104 — held by <data>/config.json" <<<"$OUT")$(grep -cxF '  tool    | FAIL key_outside_overrides — the agent copy still reads on' <<<"$OUT")$(grep -cxF "  tool    | rollback: every $V104 key is unset" <<<"$OUT")|$(has "$OUT" "The rollback STOPS before the put-back: $V103 is not put back while any part reads unclean.")$(has "$OUT" 'Nothing further was done. Send the log file.')$(has "$OUT" "checking out $B103 ($V103) for the documented deploy")|$(live)|$(installed104 "$P104")|$(inst_ver)" \
      "yes210|yesyesno|$P104|0|$V104" \
  "6k ROLLBACK typed with Part P's switch also held by <data>/config.json: the installed tool's read-back names it — FAIL key_outside_overrides, held by <data>/config.json, twice (its cleared keys' read-back and allKeys()'), the agent copy still reading on — never 'every key is unset'; it exits 3 and QF-RB1 stops the rollback before the put-back: $P104 still serving, every file as installed, no checkout of $B103"
BH104="$(grep -oE '^  echo "      UPDATE [^"]*"' "$DEPLOY104" | sed -E 's/^  echo "(.*)"$/\1/')"
check "$(grep -c '^  BY HAND, ONLY IF QF-RB1 CANNOT RUN — SQL on the live book, as its owner, in one sqlite3 session, open rows only$' <<<"$OUT")|$(printf '%s\n' "$BH104" | grep -c .)|$(n=0; while IFS= read -r l; do [ -n "$l" ] && [ "$(grep -cxF -- "$l" <<<"$OUT")" = "1" ] && n=$((n+1)); done <<<"$BH104"; echo "$n")|$(python3 -I -c 'import sys
t = sys.argv[1].split("\n"); a = [x for x in sys.argv[2].split(",") if x]
try:
    i = [k for k, l in enumerate(t) if l.startswith("  (Part P'"'"'s two, quote_agent_cc_enabled and quote_agent_cc_since, among them; the settings stay):")][0]
except IndexError:
    print("absent"); sys.exit(0)
w = []
for l in t[i + 1:]:
    if not l.startswith("      "):
        break
    w += l.split()
print("all" if sorted(w) == sorted(a) else "differs:%d/%d" % (len(w), len(a)))' "$OUT" "$ALLCSV104")|$(awk 'index($0, "  BY HAND, ONLY IF QF-RB1 CANNOT RUN") == 1 && !b { b = NR } index($0, "  STOP: QF-RB1 FAILED") == 1 && !s { s = NR } END { print (b && s && b < s) ? "before" : "b" b "s" s }' <<<"$OUT")" \
      "1|6|6|all|before" \
  "6k' …the by-hand put-back printed once, before the stop — Part F's two and Part O's four statements as the script holds them (§H.4.19, ruling R17), each printed once, then every key of allKeys() by name, Part P's two among them, and nothing else"
s4_104() {   # s4_104 OUTPUT — QF-RB1's FAILED stop (DS5, S4): the tool's command alone on its line (once), then a prose line, then
             # the rollback's own command alone, the stop's last line before stop()'s own: "1111"
  local t="      cd $REPO && bash scripts/sales-followup.sh off all --rollback"
  printf '%s%s%s%s' "$(grep -cxF -- "$t" <<<"$1")" \
    "$(grep -A1 -xF -- "$t" <<<"$1" | tail -1 | grep -cxF -- "  once it has exited 0, or the by-hand put-back is done, the rollback, its own command, alone:")" \
    "$(grep -A2 -xF -- "$t" <<<"$1" | tail -1 | grep -cxF -- "      $EXP_ROLLBACK104")" \
    "$(grep -A3 -xF -- "$t" <<<"$1" | tail -1 | grep -cxF -- "  Nothing further was done. Send the log file.")"
}
check "$(s4_104 "$OUT")|$(kv104 "$(python3 "$H104/rh104q.py" blocks "$R104/run-$T6K104.txt")" mixed)|$(grep -cE '^  (ok    |FAIL  |note  )(OF|VN|AC)-RB' <<<"$OUT")|$(wason104 "$OUT")|$(python3 "$H104/rh104q.py" seckeys "$SHK6_104" "QF-RB1 the keys that were on before the rollback's off all (key names; no value)" "$KBN104")|$(( $(evo_calls) - EVK104 ))" \
      "1111|0|0|0|present lines=1 keys=$NON104/$NON104 none=0 other=0|0" \
  "6k'' …the tool's own command printed for when the cause is cleared — alone on its line, then a prose line, then the rollback's own command alone, the stop's last line (DS5, S4); no copyable block of the run holds it beside another command; no OF-RB, VN-RB or AC-RB line (they read QF-RB1's result, which never came); the keys that were on on the sheet alone ($NON104, never in the log); Evolution not called"
check "$(drest104 rbfull)" "same|same|same" "…the book put back as the rollback found it (rbfull): every key on again, every hook's subject as before"
printf '%s\t%s\t%s\t%s\n' qfrb1rcw rollback '  if [ "$rc" != "0" ]; then' '  if false; then' >> "$ANCH104"
printf '%s\t%s\t%s\t%s\n' qfrb3w rollback ' workers/StarlinkMailWorker.php evo_webhook.php cron/event_processor.php; do' ' workers/StarlinkMailWorker.php cron/event_processor.php; do' >> "$ANCH104"
printf '%s\t%s\t%s\t%s\n' v1rbw deploy 'if do_rollback; then QCV1_RB=ok; else QCV1_RB=failed; fi' 'do_rollback || true; QCV1_RB=ok' >> "$ANCH104"
check "$(for k in qfrb1rcw qfrb3w v1rbw; do a="$(anc104 "$k")"; b="$(rep104 "$k")"; printf '%s:%s ' "$k" "$( [ "$(grep -cF -- "$a" "$DEPLOY104")" = "1" ] && [ "$a" != "$b" ] && echo once)"; done | sed 's/ $//')" \
      "qfrb1rcw:once qfrb3w:once v1rbw:once" \
  "control: the rollback's three weakened copies' anchors — QF-RB1's guard on the tool's exit, QF-RB3's list of 5.18.103's files, do_rollback's answer in V1's automatic rollback — are each the script's own text, found exactly once, and each replacement differs"
plant104k
cron104_up; fresh104; OUT="$(R104_MUTANT_OUT=1 run104c --script "$(weak104 qfrb1rc qfrb1rcw)" --answer ROLLBACK --rollback)"; cron104_down; rm -f "$REPO/scripts/.qfrb1rc.sh"
check "$(has "$OUT" 'QF-RB1 FAILED: the installed tool')$(okline "$OUT" QF-RB1 "the installed tool's off all --rollback ran the one sequence")$(has "$OUT" "  STOP: AC-RB1 FAILED: after QF-RB1, Part P still reads on (1) or its keys still read set (1 of 2; held by: data_config) through the installed PluginConfig::read — FAIL key_outside_overrides")$(has "$OUT" "checking out $B103 ($V103) for the documented deploy")|$(live)|$(installed104 "$P104")" \
      "noyesyesno|$P104|0" \
  "6k''' …QF-RB1's guard on the tool's exit weakened: its stop is gone and QF-RB1 says ok over the failed tool (caught) — and AC-RB1's own read-back through the installed QuoteAgentCopy::state() stops the rollback before the put-back, naming the file that holds the key (data_config): $P104 still serving"
check "$(drest104 rbfull)" "same|same|same" "…the book put back as the rollback found it (rbfull)"
# ── 6l (RH4-fix1): a lock not had, and a hook that throws (root docs/73 §H.2.16: "a hook that throws, or a lock not taken in
# 70 s (B11-02) … its non-zero exit stops the rollback before the put-back too"; §H.4.19: "If P6's hook throws … FAIL off_hook
# <step>, clears nothing further"). The sender's lock (OfferSwitch::LOCK_FILE) held by a process of the book's owner, as a
# sender mid-send holds it, for longer than the hook waits for it (OfferSwitch::$lockWaitS): P6's hook at the first step of
# the one sequence throws, and the tool prints FAIL off_hook, clears nothing and exits 3. Then the quote pass's lock (the
# tool's own sf_lock names its file) held past the tool's own wait (SalesFollowupLadder::LOCK_WAIT_S): the offers steps clear
# and their hooks run, and the first step that takes that lock cannot — FAIL lock_busy, its keys stay set. Each stops the
# rollback before the put-back. Every name and wait is read from the release, never typed; each case costs its wait in real
# seconds (the hook's clock and the tool's are the wall's, moved by the rehearsal's clock offset alone). ──
if [ "$LIVE104" = yes ]; then OFX104="1 1 0 1 1"; else OFX104="0 0 0 0 0"; fi   # P6's hook over this book: paused, settled uncertain, already settled, requeued, tests made uncertain
read -r OX1_104 OX2_104 OX3_104 OX4_104 OX5_104 <<<"$OFX104"
OSW104="$(git -C "$REPO" show "$P104:$P/lib/OfferSwitch.php" 2>/dev/null)"
OLF104="$(sed -n "s/^ *const LOCK_FILE = '\([a-z_.]*\)';.*/\1/p" <<<"$OSW104")"
OLW104="$(sed -n 's/^ *public static \$lockWaitS = \([0-9]*\);.*/\1/p' <<<"$OSW104")"
STL104="$(sed -n 's/^ *const STALE_S = \([0-9]*\);.*/\1/p' <<<"$OSW104")"
SFL104="$(git -C "$REPO" show "$P104:$P/tools/sales_followup.php" 2>/dev/null | sed -n "s/^ *\$file = \$which === 'quote' ? '\([a-z_.]*\)' : '\([a-z_.]*\)';.*/\1 \2/p")"
QLF104="${SFL104%% *}"; QOF104="${SFL104##* }"
git -C "$REPO" show "$P104:$P/lib/SalesFollowupLadder.php" > "$SB/ladder104.php" 2>/dev/null
TLW104="$(sed -n 's/^ *const LOCK_WAIT_S = \([0-9]*\);.*/\1/p' "$SB/ladder104.php")"
QLS104="$(python3 -I - "$SB/ladder104.php" "$(kv104 "$LAD104" sequence)" <<'PY'
import re, sys
s = open(sys.argv[1], encoding='utf-8').read(); seq = [x for x in sys.argv[2].split(',') if x]
a = s.index('const STEPS = ['); b = s.index('\n    ];', a); s = s[a:b]
heads = [(m.start(), m.group(1)) for m in re.finditer(r"^        '([a-z0-9-]+)' => \[", s, re.M)]
q = [n for i, (p, n) in enumerate(heads) if "'locks' => ['quote']" in s[p:(heads[i + 1][0] if i + 1 < len(heads) else len(s))]]
print(next((x for x in seq if x in q), '-'))   # the first step of the one sequence whose keys clear under the quote pass's lock
PY
)"
rm -f "$SB/ladder104.php"
SEQ1_104="$(kv104 "$LAD104" sequence | cut -d, -f1)"
QPRE104="$(python3 -I -c 'import sys; s = sys.argv[1].split(","); q = sys.argv[2]; print(",".join("OFF " + x for x in s[:s.index(q)]) if q in s else "-")' "$(kv104 "$LAD104" sequence)" "$QLS104")"
OKEYS104="$(kv104 "$LAD104" okeys)"; CKEYS104="$(kv104 "$LAD104" "on_$QLS104")"
CKON104="$(python3 -I -c 'import sys; a = set(x for x in sys.argv[1].split(",") if x and x != "-"); print(sum(1 for x in sys.argv[2].split(",") if x in a))' "$KBN104" "$CKEYS104")"
check "$( [ -n "$OLF104" ] && [ "$OLF104" = "$QOF104" ] && echo same)|$( [ -n "$QLF104" ] && [ "$QLF104" != "$OLF104" ] && echo other)|$( [ "$(n103 "$OLW104")" -ge 1 ] && [ "$(n103 "$TLW104")" -ge 1 ] && [ "$(n103 "$STL104")" -ge 1 ] && echo read)|$(tr ',' '\n' <<<"$(kv104 "$LAD104" hooks)" | grep -cx "$SEQ1_104:before")|$( [ "$QLS104" != "-" ] && [ "$QPRE104" != "-" ] && echo quote)|$( [ "$OWON104" -ge 1 ] && [ "$(n103 "$CKON104")" -ge 1 ] && echo on)" \
      "same|other|read|1|quote|on" \
  "control: what 6l reads from the release — the sender's lock file ($OLF104, OfferSwitch::LOCK_FILE, the one the tool's sf_lock names for offers too) and the hook's wait ($OLW104 s); the quote pass's lock file ($QLF104, the tool's) and the tool's wait ($TLW104 s); the one sequence's first step ($SEQ1_104) with its hook before its keys, and the first step that takes the quote pass's lock ($QLS104, after ${QPRE104//OFF /}); Part O's keys on ($OWON104) and $QLS104's ($CKON104) by this rehearsal's reading"
hold104 "$DATA/$OLF104" ol "$(( $(n103 "$OLW104") + 900 ))" || echo "hold104:ol" >> "$SB/anchor-missing"
HP1_104="$(probe104 "$DATA/$OLF104")"; RBL0_104="$(rb104r "$RA6_104" "$RB6_104")"
T0L104="$(date +%s)"; fresh104; OUT="$(run104c --answer ROLLBACK --rollback)"; T6L104="$(last104)"; T1L104="$(date +%s)"
HPA_104="$(probe104 "$DATA/$OLF104")"; unhold104 ol; HP2_104="$(probe104 "$DATA/$OLF104")"
SHL6_104="$(ls -1 "$OUT104"/cash-*.txt 2>/dev/null | head -1)"
check "$HP1_104|$HPA_104|$HP2_104|$( [ $(( T1L104 - T0L104 )) -ge "$(n103 "$OLW104")" ] && echo waited)|$(grep -cxF "  tool    | FAIL off_hook $SEQ1_104" <<<"$OUT")|$(grep -c '^  tool    | OFF ' <<<"$OUT")|$(grep -c '^  tool    |   hook ' <<<"$OUT")|$(grep -c '^  tool    | FAIL ' <<<"$OUT")|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" "$OKEYS104")" set)|$(rb104r "$RA6_104" "$RB6_104")" \
      "busy|busy|free|waited|1|0|0|1|$OWON104|$RBL0_104" \
  "6l ROLLBACK typed while a sender holds $OLF104 past P6's wait (the holder held it throughout, free once let go; the run lasted at least the hook's $OLW104 s): the tool prints FAIL off_hook $SEQ1_104 and nothing else of its own — no step OFF, no hook's counts — so Part O's $OWON104 key(s) stay set, and the book is as it was by this rehearsal's reading: the offer still running, its recipients still claimed, the test still sending (the hook's transaction never began)"
check "$(has "$OUT" "  STOP: QF-RB1 FAILED: the installed tool's off all --rollback exited 3 — a hook threw, a lock was not taken in 70 s, or an end state or a read-back did not hold")$(has "$OUT" 'Nothing further was done. Send the log file.')$(has "$OUT" "checking out $B103 ($V103) for the documented deploy")|$(grep -c '^  BY HAND, ONLY IF QF-RB1 CANNOT RUN' <<<"$OUT")|$(n=0; while IFS= read -r l; do [ -n "$l" ] && [ "$(grep -cxF -- "$l" <<<"$OUT")" = "1" ] && n=$((n+1)); done <<<"$BH104"; echo "$n")|$(awk 'index($0, "  BY HAND, ONLY IF QF-RB1 CANNOT RUN") == 1 && !b { b = NR } index($0, "  STOP: QF-RB1 FAILED") == 1 && !s { s = NR } END { print (b && s && b < s) ? "before" : "b" b "s" s }' <<<"$OUT")|$(kv104 "$(python3 "$H104/rh104q.py" blocks "$R104/run-$T6L104.txt")" mixed)|$(grep -cE '^  (ok    |FAIL  |note  )(OF|VN|AC)-RB' <<<"$OUT")|$(python3 "$H104/rh104q.py" seckeys "$SHL6_104" "QF-RB1 the keys that were on before the rollback's off all (key names; no value)" "$KBN104")|$(live)|$(installed104 "$P104")|$(s4_104 "$OUT")" \
      "yesyesno|1|$(printf '%s\n' "$BH104" | grep -c .)|before|0|0|present lines=1 keys=0/$NON104 none=1 other=0|$P104|0|1111" \
  "6l' …QF-RB1 stops the rollback before the put-back (its exit 3), the by-hand put-back printed once before the stop with its six statements, each once, a block of its own; no OF-RB, VN-RB or AC-RB line; the sheet says none was on to clear (\"none reported\"); $P104 still serving, every file as installed, no checkout of $B103; the stop ends with the tool's command alone, a prose line and the rollback's own command alone, last (DS5, S4)"
check "$(drest104 rbfull)" "same|same|same" "…the book put back as the rollback found it (rbfull)"
hold104 "$DATA/$QLF104" ql "$(( $(n103 "$TLW104") + 900 ))" || echo "hold104:ql" >> "$SB/anchor-missing"
HQ1_104="$(probe104 "$DATA/$QLF104")"; RBQ0_104="$(rb104r "$RA6_104" "$RB6_104")"
T0Q104="$(date +%s)"; fresh104; OUT="$(run104c --answer ROLLBACK --rollback)"; T6LQ104="$(last104)"; T1Q104="$(date +%s)"
HQA_104="$(probe104 "$DATA/$QLF104")"; unhold104 ql; HQ2_104="$(probe104 "$DATA/$QLF104")"
SHLQ6_104="$(ls -1 "$OUT104"/cash-*.txt 2>/dev/null | head -1)"; RBQ1_104="$(rb104r "$RA6_104" "$RB6_104")"
check "$HQ1_104|$HQA_104|$HQ2_104|$( [ $(( T1Q104 - T0Q104 )) -ge "$(n103 "$TLW104")" ] && echo waited)|$(grep -E '^  tool    \| (OFF |FAIL )' <<<"$OUT" | sed 's/^  tool    | //' | paste -sd, -)|$(sed -n "s/^  tool    |   hook $SEQ1_104: //p" <<<"$OUT" | head -1)" \
      "busy|busy|free|waited|$QPRE104,FAIL lock_busy $QLS104 — its keys stay set|paused=$OX1_104 settled_uncertain=$OX2_104 already_settled=$OX3_104 requeued=$OX4_104 tests_uncertain=$OX5_104" \
  "6l'' ROLLBACK typed while the quote pass holds $QLF104 past the tool's wait: the one sequence clears ${QPRE104//OFF /} (each hook run, before its keys: the offer paused, its claims settled or returned) and then cannot take that lock for $QLS104 — FAIL lock_busy $QLS104, its keys stay set — and goes no further"
SKQ104="$(python3 "$H104/rh104q.py" seckeys "$SHLQ6_104" "QF-RB1 the keys that were on before the rollback's off all (key names; no value)" "$OKEYS104")"
KQ104="$(sed -nE 's/.* keys=([0-9]+)\/.*/\1/p' <<<"$SKQ104")"   # the keys the tool cleared that were on, as its sheet names them — every one Part O's (other=0)
check "$(kv104 "$RBQ1_104" oq)|$(kv104 "$RBQ1_104" od)|$( [ "$(n103 "$KQ104")" -ge 1 ] && echo cleared)|$(sed -E 's/ keys=[0-9]+\//:/' <<<"$SKQ104")|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" "$OKEYS104")" set)|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" "$CKEYS104")" set)|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" "$ALLCSV104")" set)|$(has "$OUT" "  STOP: QF-RB1 FAILED: the installed tool's off all --rollback exited 3")$(has "$OUT" "checking out $B103 ($V103) for the documented deploy")|$(grep -c '^  BY HAND, ONLY IF QF-RB1 CANNOT RUN' <<<"$OUT")|$(kv104 "$(python3 "$H104/rh104q.py" blocks "$R104/run-$T6LQ104.txt")" mixed)|$(live)|$(installed104 "$P104")" \
      "$(kv104 "$RBQ0_104" oq)|$(kv104 "$RBQ0_104" od)|cleared|present lines=1:$(tr ',' '\n' <<<"$OKEYS104" | grep -c .) none=0 other=0|$(( OWON104 - $(n103 "$KQ104") ))|$CKON104|$(( NON104 - $(n103 "$KQ104") ))|yesno|1|0|$P104|0" \
  "6l''' …the chase still open by this rehearsal's reading (its quote follow-ups and drafts as before: Part F's hook never ran); the $KQ104 key(s) the tool cleared, on the sheet, every one Part O's, and by this rehearsal's own reading exactly those unset — Part O's other keys, $QLS104's $CKON104 and $(( NON104 - $(n103 "$KQ104") )) of allKeys() still set; QF-RB1 stops before the put-back, the by-hand put-back printed once, a block of its own; $P104 still serving, no checkout of $B103"
check "$(drest104 rbfull)" "same|same|same" "…the book put back as the rollback found it (rbfull)"
# ── 6m (RH4-fix1): QF-RB1's two NO-GOs, each before any key is cleared (deploy-5.18.104.sh's qf_rb1): the installed plugin with
# no tools/sales_followup.php (not 5.18.104 as installed: the by-hand put-back printed, nothing put back) and the checkout
# with no wrapper (QF_TOOL and QF_WRAPPER read from the script). Then the control on the blocks reader (RH4-R05): the
# by-hand put-back read as blocks of their own, its runs of six-space lines counted from qf_byhand's own text — and a
# weakened copy that echoes the tool's command right after qf_byhand's last line, shown caught as a mixed block. ──
QFT104="$(sv104 "" QF_TOOL)"; QFW104="$(sed -n 's/^QF_TOOL="[^"]*"; QF_REPORT="[^"]*"; QF_WRAPPER="\([^"]*\)"$/\1/p' "$DEPLOY104")"
BHR104="$(python3 -I - "$DEPLOY104" <<'PY'
import sys
s = open(sys.argv[1], encoding='utf-8').read().split('\n')
i = s.index('qf_byhand() {'); j = next(k for k in range(i + 1, len(s)) if s[k] == '}')
runs, prev = 0, False
for l in s[i + 1:j]:   # the lines of qf_byhand that print six-space lines, in runs: each run one copyable block
    six = l.startswith('  echo "      ') or l.rstrip().endswith("sed 's/^/      /'")
    runs += 1 if six and not prev else 0
    prev = six
print(runs)
PY
)"
python3 -I - "$DEPLOY104" "$ANCH104" <<'PY' || echo "anchors:byhandw" >> "$SB/anchor-missing"
import sys
t = open(sys.argv[1], encoding='utf-8').read(); s = t.split('\n')
i = s.index('qf_byhand() {'); j = next(k for k in range(i + 1, len(s)) if s[k] == '}')
a = [l for l in s[i + 1:j] if l.rstrip().endswith("sed 's/^/      /'")]
assert len(a) == 1 and t.count(a[0]) == 1 and '\t' not in a[0], len(a)
b = a[0] + '; echo "      cd $REPO && bash $QF_WRAPPER off all --rollback"'   # the tool's command printed right after the key names
open(sys.argv[2], 'a', encoding='utf-8').write('byhandw\trollback\t%s\t%s\n' % (a[0], b))
PY
check "$( [ -n "$QFT104" ] && [ -n "$QFW104" ] && echo read)|$(git -C "$REPO" show "$P104:$P/$QFT104" 2>/dev/null | cmp -s - "$PD/$QFT104" && echo installed)|$(git -C "$REPO" ls-files --error-unmatch -- "$QFW104" >/dev/null 2>&1 && echo tracked)|$( [ "$(n103 "$BHR104")" -ge 2 ] && echo runs)|$(a="$(anc104 byhandw)"; b="$(rep104 byhandw)"; [ "$(grep -cF -- "$a" "$DEPLOY104")" = "1" ] && [ "$a" != "$b" ] && echo once)" \
      "read|installed|tracked|runs|once" \
  "control: the script's own names for the tool ($QFT104, installed as the pin has it) and the wrapper ($QFW104, tracked in the clone); qf_byhand prints its six-space lines in $BHR104 runs (Part F's two statements, Part O's four, the key names); the weakened copy's anchor, qf_byhand's last line, the script's own text once, its replacement different"
mv "$PD/$QFT104" "$SB/qft104.kept"
fresh104; OUT="$(run104c --answer ROLLBACK --rollback)"; T6M104="$(last104)"; BLK6M104="$(python3 "$H104/rh104q.py" blocks "$R104/run-$T6M104.txt")"
check "$(has "$OUT" "  STOP: NO-GO (QF-RB1): the installed plugin has no $QFT104 — it is not $V104 as installed, so the one sequence cannot run; nothing was put back. The by-hand put-back is above, its own block")|$(grep -c '^  BY HAND, ONLY IF QF-RB1 CANNOT RUN' <<<"$OUT")|$(n=0; while IFS= read -r l; do [ -n "$l" ] && [ "$(grep -cxF -- "$l" <<<"$OUT")" = "1" ] && n=$((n+1)); done <<<"$BH104"; echo "$n")|$(awk 'index($0, "  BY HAND, ONLY IF QF-RB1 CANNOT RUN") == 1 && !b { b = NR } index($0, "  STOP: NO-GO (QF-RB1)") == 1 && !s { s = NR } END { print (b && s && b < s) ? "before" : "b" b "s" s }' <<<"$OUT")|$(grep -c '^  tool    | ' <<<"$OUT")|$(has "$OUT" "checking out $B103 ($V103) for the documented deploy")|$(kv104 "$BLK6M104" byhand)|$(kv104 "$BLK6M104" mixed)|$(live)" \
      "yes|1|$(printf '%s\n' "$BH104" | grep -c .)|before|0|no|$BHR104|0|$P104" \
  "6m ROLLBACK typed over an installed plugin with no $QFT104: NO-GO (QF-RB1) — the one sequence cannot run, nothing put back — the by-hand put-back printed once before the stop, its six statements each once; no tool line; no checkout of $B103; the blocks reader sees its $BHR104 runs each a block holding by-hand lines, none beside a command (mixed 0)"
M="$(weak104 byhand byhandw)"; fresh104; OUT="$(R104_MUTANT_OUT=1 run104c --script "$M" --answer ROLLBACK --rollback)"; rm -f "$M"
BLKW104="$(python3 "$H104/rh104q.py" blocks "$R104/mutants/run-$(last104).txt")"
check "$(has "$OUT" "  STOP: NO-GO (QF-RB1): the installed plugin has no $QFT104")|$(grep -cxF "      cd $REPO && bash $QFW104 off all --rollback" <<<"$OUT")|$(kv104 "$BLKW104" byhand)|$(kv104 "$BLKW104" mixed)|$(live)" \
      "yes|1|$BHR104|1|$P104" \
  "6m' control on the control (RH4-R05): a weakened copy printing the tool's command right after qf_byhand's last line — the blocks reader reads that block mixed (1): the by-hand put-back beside a command, caught"
mv "$SB/qft104.kept" "$PD/$QFT104"
check "$(git -C "$REPO" show "$P104:$P/$QFT104" 2>/dev/null | cmp -s - "$PD/$QFT104" && echo pin)|$(installed104 "$P104")|$(left104)|$(live)" "pin|0|$NA104|$P104" "…$QFT104 put back as the pin has it; every file installed as before"
git -C "$REPO" update-index --skip-worktree -- "$QFW104"; mv "$REPO/$QFW104" "$SB/qfw104.kept"
check "$( [ -e "$REPO/$QFW104" ] && echo present || echo gone)|$(git -C "$REPO" status --porcelain --untracked-files=no | grep -c .)|$(git -C "$REPO" ls-files -v -- "$QFW104" | cut -c1)" "gone|0|S" \
  "control: the wrapper taken out of the clone's working tree with git told to skip it (skip-worktree, its own index flag) — the script's tracked-edits count (git status --porcelain --untracked-files=no) reads 0, as on a checkout whose branch has no wrapper: the only way to the NO-GO below, which the tracked-edits refusal reaches first otherwise (script_findings)"
fresh104; OUT="$(run104c --answer ROLLBACK --rollback)"
check "$(has "$OUT" "  STOP: NO-GO (QF-RB1): the checkout has no $QFW104 — pull the branch again; nothing was put back")|$(grep -c '^  BY HAND, ONLY IF QF-RB1 CANNOT RUN' <<<"$OUT")|$(grep -c '^  tool    | ' <<<"$OUT")|$(has "$OUT" "checking out $B103 ($V103) for the documented deploy")|$(live)|$(installed104 "$P104")" \
      "yes|0|0|no|$P104|0" \
  "6m'' ROLLBACK typed with no wrapper in the checkout: NO-GO (QF-RB1) — pull the branch again, nothing put back; no tool line; as the script prints it, no by-hand put-back on this path (script_findings); no checkout of $B103"
mv "$SB/qfw104.kept" "$REPO/$QFW104"; git -C "$REPO" update-index --no-skip-worktree -- "$QFW104"
check "$(git -C "$REPO" status --porcelain --untracked-files=no | grep -c .)|$(git -C "$REPO" ls-files -v -- "$QFW104" | cut -c1)|$(git -C "$REPO" diff --quiet -- "$QFW104" && echo same)" "0|H|same" "…the wrapper put back and git's flag cleared: the clone as it was"
check "$(drest104 rbfull)" "same|same|same" "…the book put back as the rollback found it (rbfull)"
# ── 6n (RH4-fix1): QF-RB1's run folder not made (DS3-fix1: "the vault read failing at QF-RB1's cih_main") — the fake docker's
# .rhf104.cihv answers the folder's vault copy "?rh104": QF-RB1 notes it and runs on (its own tool and read-backs read no
# copy), every reading on a copy says it could not be read, and no half-made folder is left; then .rhf104.acrb1 answers
# AC-RB1's read-back "?rh104", and AC-RB1 stops the rollback before the put-back (it never puts back over a part it cannot
# read). One run, both stops of their own, nothing put back. ──
CIH0_104="$(ls -d /tmp/dnb-"$V104"-cih-* 2>/dev/null | grep -c .)"
: > "$SB/rhf104.fired"; : > "$SB/.rhf104.cihv"; : > "$SB/.rhf104.acrb1"
cron104_up; fresh104; OUT="$(run104c --answer ROLLBACK --rollback)"; T6N104="$(last104)"; cron104_down
SHN6_104="$(ls -1 "$OUT104"/cash-*.txt 2>/dev/null | head -1)"
check "$(noteline "$OUT" QF-RB1 "the run's folder in the container's /tmp could not be made (?rh104) — the readings on copies of the book below each say they could not be read; QF-RB1's own tool and read-backs read none")|$(grep -cxF "  tool    | rollback: every $V104 key is unset" <<<"$OUT")$(okline "$OUT" QF-RB1 "the installed tool's off all --rollback ran the one sequence")|$(failline "$OUT" OF-RB1 "Part O's end state could not be read on a copy of the book (no copy)")$(okline "$OUT" VN-RB1b "pending audio row(s) settled skipped/switched_off")$(failline "$OUT" VN-RB3 "and VN-RB4 could not be read on a copy of the book (no copy)")|$(sed -n '/^== VN-RB2\/VN-RB4 what Part V/{n;p}' "$SHN6_104" 2>/dev/null)|$CIH0_104:$(ls -d /tmp/dnb-"$V104"-cih-* 2>/dev/null | grep -c .)" \
      "yes|1yes|yesyesyes|not read|0:0" \
  "6n ROLLBACK typed with the run's folder's vault copy refused (the fake docker's fault, spent): QF-RB1 notes that the folder could not be made, and runs the one sequence all the same — the tool's own read-back clean, QF-RB1 ok; OF-RB1's end state and VN-RB3/VN-RB4 each FAIL as not read on a copy, VN-RB1b's own count from the tool's lines still ok; the drains' ids on the sheet \"not read\"; no folder of the run left in /tmp"
check "$(has "$OUT" "  STOP: AC-RB1 FAILED: Part P's state could not be read back through the installed code (?rh104) — the rollback does not put back over a part it cannot read. Nothing was put back. The by-hand put-back is above")|$(grep -c '^  BY HAND, ONLY IF QF-RB1 CANNOT RUN' <<<"$OUT")|$(awk 'index($0, "  BY HAND, ONLY IF QF-RB1 CANNOT RUN") == 1 && !b { b = NR } index($0, "  STOP: AC-RB1 FAILED") == 1 && !s { s = NR } END { print (b && s && b < s) ? "before" : "b" b "s" s }' <<<"$OUT")|$(kv104 "$(python3 "$H104/rh104q.py" blocks "$R104/run-$T6N104.txt")" mixed)|$(has "$OUT" "checking out $B103 ($V103) for the documented deploy")|$(ls -A "$SB" | grep -cE '^\.rhf104\.')|$(paste -sd, "$SB/rhf104.fired")|$(live)|$(installed104 "$P104")" \
      "yes|1|before|0|no|0|cihv,acrb1|$P104|0" \
  "6n' …AC-RB1's read-back through the installed code refused (the second fault, spent): AC-RB1 stops the rollback before the put-back — Part P cannot be read, so nothing is put back — the by-hand put-back printed once before the stop, a block of its own; no checkout of $B103; both faults fired once, in order, none left armed"
: > "$SB/rhf104.fired"
check "$(drest104 rbfull)" "same|same|same" "…the book put back as the rollback found it (rbfull)"
# ── 6-6 (RH4-fix1): what the hooks must leave and what they must act on, beside 6-1–6-5's — states no writer of this sandbox
# makes (rbraw104.py's own lines say why), planted just before the rollback so that each is what it should be when QF-RB1
# runs: a voice note R4 transcribed (its body R4's label, read from the release's VoiceTranscription::LABEL) and its voice turn
# left pending, deferred past the rollback (step 5's drain waits for it, bounded: VN-RB2; VN-RB3's note reads its body); an
# audio row mid-call, in the status MediaWorker writes before its fetch (VN-RB1b: step 5's hook settles pending rows and
# touches nothing in flight); and a test of the running offer still in its send, a few minutes old (OfferSender::sendTest's
# own INSERT through the helper, its age from the clock): under 15 minutes when QF-RB1 begins, the hook must leave it
# (ruling R18). Taken out again in 6x (the test settled: 091 never lets one be deleted). Non-live: none of the four. ──
VTL104="$(git -C "$REPO" show "$P104:$P/lib/VoiceTranscription.php" 2>/dev/null | sed -n "s/^ *public const LABEL = '\(.*\)';.*/\1/p")"
MFS104="$(git -C "$REPO" show "$P104:$P/workers/MediaWorker.php" 2>/dev/null | sed -n "s/.*\$this->update(\$mediaId, \['status' => '\([a-z_]*\)', 'attempts' => \$attempt.*/\1/p")"
MST104="$(git -C "$REPO" show "$P104:$P/workers/MediaWorker.php" 2>/dev/null | sed -n "s/^ *public const SETTLED = \[\(.*\)\];.*/\1/p" | tr -d "' ")"
DBS104="$(git -C "$REPO" show "$P104:$P/lib/VoiceSteps.php" 2>/dev/null | sed -n 's/^ *public static \$drainBoundS = \([0-9]*\);.*/\1/p')"
YAGE104=120   # the young test's age when planted: well under the hook's STALE_S once the rollback's stage A has run (control below)
check "$(printf '%s\n' "$VTL104" | grep -c .)|$( [ "$VTL104" = "$(sed -n "s/^\$LABEL = '\(.*\)';.*/\1/p" "$DEPLOY104")" ] && echo script)|$(printf '%s\n' "$MFS104" | grep -c .)|$(tr ',' '\n' <<<"$MST104,pending" | grep -cxF -- "$MFS104")|$( [ "$(n103 "$DBS104")" -ge 1 ] && echo bound)|$( [ $(( YAGE104 + 600 )) -lt "$(n103 "$STL104")" ] && echo young)" \
      "1|script|1|0|bound|young" \
  "control: what 6-6 plants is read from the release — R4's label (VoiceTranscription::LABEL, the one VN_PHP's reading compares, as the script holds it), the status the media worker writes before its fetch ($MFS104: neither pending nor settled), the drain's bound (VoiceSteps::\$drainBoundS, $DBS104 s); the young test's $YAGE104 s, with ten minutes for the rollback's stage A, under the hook's STALE_S ($STL104 s)"
VT6_104=0; R4N104=0; YC6_104=0; YT6_104=0; IF6_104=0; EV6R4_104=0; R4G104=0; R4M104=0
if [ "$LIVE104" = yes ]; then
  P4R_104="$(rbraw104 r4 "$VTL104")"; IFR_104="$(rbraw104 inflight "$MFS104")"
  YTR_104="$(live104 offerstale "$PD" "$DATA" "{\"offer\":$OF6_104,\"age_s\":$YAGE104,\"young\":true}")"
  R4G104="$(n103 "$(kv104 "$P4R_104" msg)")"; R4M104="$(n103 "$(kv104 "$P4R_104" media)")"; EV6R4_104="$(n103 "$(kv104 "$P4R_104" event)")"
  IF6_104="$(n103 "$(kv104 "$IFR_104" media)")"; YT6_104="$(n103 "$(qj "$YTR_104" id 2>/dev/null)")"
  VL6_104="$(python3 "$H104/rh104q.py" vlabels "$DATA/plugin.sqlite3" "$VTL104")"
  VT6_104="$(n103 "$(kv104 "$VL6_104" turns)")"; R4N104="$(n103 "$(kv104 "$VL6_104" r4)")"; YC6_104=1
  RBP0_104="$(python3 "$H104/rh104q.py" rbrows "$DATA/plugin.sqlite3" "$YT6_104" "$IF6_104" "$EV6R4_104")"
  check "$( [ "$R4G104" -ge 1 ] && [ "$R4M104" -ge 1 ] && [ "$EV6R4_104" -ge 1 ] && [ "$IF6_104" -ge 1 ] && [ "$YT6_104" -ge 1 ] && echo planted)|$(qj "$YTR_104" young 2>/dev/null)|$VL6_104|$(sed -E 's#/[0-9T:-]+( |$)#/stamp\1#g' <<<"$RBP0_104")|$(kv104 "$(rb104r "$RA6_104" "$RB6_104")" ts)|$(kv104 "$(rb104r)" ap)" \
        "planted|1|r4=1 r3=0 turns=1 turn_ids=$EV6R4_104|test=sending media=$MFS104/1/stamp event=pending/1/stamp|2|$(kv104 "$RBB104" ap)" \
    "6-6 planted, by this rehearsal's own reading: one R4-labelled body and its voice turn (pending, deferred), the only ones on the book; an audio row $MFS104, in flight; a second test still sending, beside the stale one; the pending audio rows still the webhook's $(kv104 "$RBB104" ap) — the in-flight row is not among them"
fi
# ── 6a: the rollback, as the deploy's summary printed it after its verdict, ROLLBACK typed. ──────────────────────────────
RLINE6_104="$(printed "$OUT104D" "cd $REPO && bash scripts/deploy-5.18.104.sh --rollback")"
check "$RLINE6_104|$(awk '/^  '"${V104//./\\.}"' \((deploy|after)\): / { v = 1 } index($0, "deploy-5.18.104.sh --rollback") { print (v ? "after" : "before") }' <<<"$OUT104D" | paste -sd, -)|$(grep 'deploy-5\.18\.104\.sh --rollback' <<<"$OUT104D" | grep -cE 'deploy-5\.18\.104\.sh .*deploy-5\.18\.104\.sh|--redeploy|--after-only|sales-followup\.sh|deploy-hybrid\.sh')" \
      "$EXP_ROLLBACK104|after|0" \
  "6 the rollback's command, as the deploy's summary printed it — once, after its verdict, and no line of that run carries it beside another command (the deploy's, --redeploy, --after-only, the tool's, the put-back)"
S104SHA="$(sha256sum "$STATE104" | cut -c1-64)"; QCRB104="$(qkv103 "$(q103 apps)" qcrb2)"; CATR104="$(catq103 | sed -nE 's/^records:([0-9]+),.*/\1/p')"
EMID6_104="$(python3 "$H104/rh104q.py" emids "$DATA/plugin.sqlite3")"; EVA104="$(evo_calls)"
cron104_up; fresh104; OUT="$(run_line104c ROLLBACK "$RLINE6_104")"; T6A104="$(last104)"; cron104_down; OUT6A104="$OUT"
SH6_104="$(ls -1 "$OUT104"/cash-*.txt 2>/dev/null | head -1)"; RBL6_104="$(ls -1t "$OUT104"/rollback-*.log 2>/dev/null | head -1)"
RBA104="$(rb104r "$RA6_104" "$RB6_104")"
check "$(fset104 "$OUT")|$(has "$OUT" "  $V104 (rollback): PASSED.")|$(live)|$(installed104 "$P103")|$(inst_ver)|$(left104)|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" "$ALLCSV104")" set)" \
      ":|yes|$P103|0|$V103|$NA104|0" \
  "6a ROLLBACK typed, every part on: the one sequence ran and every part read clean, then $V103 was put back and checked — $P103 serving, every file $V104 changed $V103's again, the $NA104 it adds left on disk, no key of allKeys() set by this rehearsal's reading; no FAIL — V3g's own keys excused (next; DS5, S2)"
V3GG104="$(sed -n "s/^  note  V3g .* removed $V104's own key(s), by name, nothing else among the AI and WhatsApp settings: \(.*\) — the switch tool's keys (SalesFollowupLadder::allKeys()), cleared by design; no value\$/\1/p" <<<"$OUT" | tr ' ' '\n' | grep . | LC_ALL=C sort | paste -sd, -)"   # v3gg104's reading, written out: that is defined below (7)
check "$V3GG104|$( [ -n "$V3GW104" ] && echo named)|$(okline "$OUT" V3g "unchanged by this run but for $V104's own keys its switch tool removed")$(noteline "$OUT" V3g "QF-RB1's off all --rollback removed $V104's own key(s)")$(grep -c '^  FAIL  V3g ' <<<"$OUT")" "$V3GW104|named|yesyes0" \
  "6a' …V3g, carried from 5.18.103, compares every ai_ key before the run with after it, and QF-RB1 clears Part V's ai_ keys by design: V3g reads ok and its note names exactly the Part V ai_ keys that were on, in both copies (this rehearsal's own list), nothing else, as removed by QF-RB1's off all --rollback — never a FAIL (DS5, S2; the positive control: the same names the FAIL carried before)"
TL6_104="$(grep '^  tool    | ' <<<"$OUT" | sed 's/^  tool    | //')"
SQG104="$(printf '%s\n' "$TL6_104" | awk '/^OFF sending — /{print "OFF sending"; next} /^OFF /{print; next} /^  hook [a-z-]+: /{sub(/^  hook /, "hook "); sub(/:.*/, ""); print}' | paste -sd, -)"
SQW104="$(python3 -I -c 'import sys
seq = [x for x in sys.argv[1].split(",") if x]; hk = dict(h.split(":", 1) for h in sys.argv[2].split(",") if ":" in h); o = []
for s in seq:
    if hk.get(s) == "before": o.append("hook " + s)
    o.append("OFF " + s)
    if hk.get(s) == "after": o.append("hook " + s)
print(",".join(o + ["OFF sending", "OFF the record keys and agent-cc"]))' "$(kv104 "$LAD104" sequence)" "$(kv104 "$LAD104" hooks)")"
check "$SQG104|$(wason104 "$OUT")|$(grep -cxF "quotation e-mails are as $V103 from the next one" <<<"$TL6_104")$(grep -cxF "rollback: every $V104 key is unset" <<<"$TL6_104")$(grep -c '^FAIL ' <<<"$TL6_104")" \
      "$SQW104|0|110" \
  "6a'' the tool's own lines, as QF-RB1 shows them: the one sequence — O (12, 11), F (10, 6), V (9, 8, 7, 5), 4, 3, 2, the ledger, then the record keys and Part P's two, last (rulings R7, R11) — each step's hook before or after its keys clear as the release's ladder places it; Part P's gate read back, every key read unset, no FAIL; its 'was on' names in no line of the log"
HL6_104="$(sed -n 's/^  hook offers-live: //p' <<<"$TL6_104" | head -1)"; HT6_104="$(sed -n 's/^  hook offers-test: //p' <<<"$TL6_104" | head -1)"
HC6_104="$(sed -n 's/^  hook chase: //p' <<<"$TL6_104" | head -1)"; H56_104="$(sed -n 's/^  hook voice-measure: //p' <<<"$TL6_104" | head -1)"; H26_104="$(sed -n 's/^  hook ack: //p' <<<"$TL6_104" | head -1)"
check "$HL6_104|$HT6_104|$HC6_104" \
      "paused=$OX1_104 settled_uncertain=$OX2_104 already_settled=$OX3_104 requeued=$OX4_104 tests_uncertain=$OX5_104|paused=0 settled_uncertain=0 already_settled=0 requeued=0 tests_uncertain=0|closed=$(kv104 "$RBB104" oq) expired=$(kv104 "$RBB104" od) remaining=0" \
  "6b Part O's hook, BEFORE its keys clear (ruling R12): at offers-live the offer paused, its claimed recipient holding a ledger claim settled uncertain, the one without returned to queued, the test left sending made uncertain; at offers-test nothing left to do; Part F's hook at chase, under the quote pass's lock: every open quote follow-up closed as rollback, its drafts expired, none remaining"
check "$(kv104 "$H56_104" settled)|$(kv104 "$H56_104" voice_turns_left)|$( [ "$VT6_104" = 0 ] || [ "$(n103 "$(kv104 "$H56_104" waited_s)")" -ge "$(n103 "$DBS104")" ] && echo waited5)|$(kv104 "$H56_104" ai_media_events_pending)|$(kv104 "$H26_104" ack_events_left)|$(kv104 "$H26_104" ack_events_left_processing)|$( [ "$(n103 "$(kv104 "$H26_104" ack_events_at_start)")" -le "$(n103 "$(kv104 "$RBB104" ea)")" ] && [ -n "$(kv104 "$H26_104" waited_s)" ] && echo bounded)" \
      "$(kv104 "$RBB104" ap)|$VT6_104|waited5|$(kv104 "$RBB104" em)|0|0|bounded" \
  "6b' Part V's hooks (ruling R14): at voice-measure, after its keys clear, exactly the pending audio rows settled ($(kv104 "$RBB104" ap): the webhook's, never 6-6's row in flight), the voice turn 6-6 left deferred still left ($VT6_104) once the drain waited its whole bound ($DBS104 s, VoiceSteps::\$drainBoundS: no worker may take a deferred turn), the ai.media events left pending and counted ($(kv104 "$RBB104" em)); at ack, after P5's keys and before the ledger, the acknowledgement events drained — none left, none in a worker's hands ($(kv104 "$H26_104" ack_events_at_start) at its start)"
RBO104="$(kv104 "$RBB104" or_)"
for l in "ok|QF-RB1|the installed tool's off all --rollback ran the one sequence|and exited 0; read again here: no $V104 key set in kyc_config.json or the store row, 0 open quote follow-ups; $NON104 key(s) that were on are on the sheet" \
         "ok|OF-RB1|the one sequence began with Part O — ${L12_104%% *} (offers-live), then ${L11_104%% *} (offers-test) — each step's OfferSwitch::offHook run on the tool's own connection with why rollback BEFORE its keys cleared (rulings R7, R13), and QF-RB1 read every key back unset; $OWON104 of Part O's keys were on, by name on the sheet with QF-RB1's" \
         "note|OF-RB1|Part O's hook, by step (counts only): offers-live — offers paused $(kv104 "$HL6_104" paused), claimed recipients with a ledger claim settled uncertain (maybe sent, ruling R2) $(kv104 "$HL6_104" settled_uncertain) ($(kv104 "$HL6_104" already_settled) already settled by the ledger), without one returned to queued $(kv104 "$HL6_104" requeued) (ruling R12), tests left sending over 15 minutes made uncertain $(kv104 "$HL6_104" tests_uncertain); offers-test — 0, 0 (0), 0, 0" \
         "ok|OF-RB1|Part O's end state on a copy of the book after QF-RB1: no offer running, no recipient claimed, no test still sending that had been sending over 15 minutes when QF-RB1 began (its hooks ran after that);|($RBO104 paused_by_rollback event(s) in all; the paused offers by id on the sheet)|($(kv104 "$HL6_104" settled_uncertain) uncertain as stale claims), tests still sending that were under 15 minutes old when QF-RB1 began $YC6_104 — left by the hook, as it must leave them (ruling R18)" \
         "ok|VN-RB1|the one sequence cleared Part V's rows in their order — 9, 8, 7, 5, then 2 with P5's keys before the ledger (rulings R7, R11) — and QF-RB1 read every key back unset; $VWON104 of Part V's keys were on, by name on the sheet with QF-RB1's" \
         "ok|VN-RB1b|step 5's hook, after Part V's media keys cleared: $(kv104 "$RBB104" ap) pending audio row(s) settled skipped/switched_off, nothing in flight touched (ruling R14)" \
         "note|VN-RB2|the drain, by step, each bounded: step 5's hook waited $(kv104 "$H56_104" waited_s) s for the voice turns ($VT6_104 left); step 2's hook, after P5's keys and before the ledger, waited|($(kv104 "$H26_104" ack_events_at_start) at its start, 0 left, 0 of them in a worker's hands); now: $VT6_104 voice turn(s) and 0 acknowledgement event(s) (their ids on the sheet) — $V103 would answer any voice turn left as text, from the labelled transcript" \
         "note|VN-RB3|$R4N104 message body(ies) carry R4's transcript label — $V103 reads them as labelled customer text" \
         "ok|VN-RB3|no body below R4 carries the transcript label (R3 leaves the body [AUDIO], F10)" \
         "note|VN-RB4|events left pending: ai.media $(kv104 "$RBB104" em), ai.voice_ack 0 (ids on the sheet) — $V103's Uganda event_processor acknowledges them as unknown, which is harmless; pending audio rows 0 (QF-RB1's floor)" \
         "ok|AC-RB1|the one sequence cleared Part P's two keys last, with the record keys, after the ledger (ruling R11; no hook), and read the gate back (\"quotation e-mails are as $V103 from the next one\"); read again here through the installed QuoteAgentCopy::state() and PluginConfig::read: P7 off, quote_agent_cc_enabled and quote_agent_cc_since unset and in no file, quote_agent_cc_admins as it was ($(kv104 "$ADM6_104" file))" \
         "note|QF-RB2|the tables, the columns, the ledger and the marks $V104 made stay ($MN104; their 400-day and never-deleted triggers), and $V103 reads none of them; the record-only hooks go with the code" \
         "ok|container|serves $B103 ($V103)" \
         "ok|RB|the installed manifest says $V103" \
         "ok|QF-RB3|after the put-back:|are $V103's byte for byte — master registers none of the four jobs, and the record-only hooks and the marks are gone with the code; no $V104 key is set in kyc_config.json or the store row; 0 open quote follow-ups" \
         "note|VN-RB5|090's three tables and its _schema_checks row stay; nothing in $V103 reads them" \
         "note|OF-RB2|the four offer tables and 091's _schema_checks row (present) stay, and $V103 reads none of them;|resumes only when an admin presses Resume, after a redeploy, once offers-test and offers-live are switched on again with their checks; none is running" \
         "note|AC-RB2|P7's two collections stay — quote_agent_makers|and $V103 reads neither (none of its PHP files, as installed now, names them); a redeploy starts dark, and P7 comes back only with on agent-cc, judged by AC-ON" \
         "ok|QC-RB1|nothing in $V103 reaches what $V104 adds and leaves:|$NA104 of the $NA104 files $V104 adds are on disk, reached by nothing (QF-RB2)" \
         "note|QC-RB1|the uCRM catalogue's table stays on the book: $CATR104 record(s) — $V103's own screen, read by it again after this rollback, as $V104 left it" \
         "note|QC-RB2|before the rollback, on a copy of the book: $(qcrbsay103 "$QCRB104") — the rollback changes nothing for them: $V103 retries and quotes each exactly as $V104 does (the same KycService and UcrmQuoteLines), so none is to be quoted in uCRM by hand because of it; ids on the sheet (for the record)" \
         "note|QC-RB2|after the rollback, on a copy of the book: $(qcrbsay103 "$QCRB104")"; do
  IFS='|' read -r k6 i6 f1 f2 f3 <<<"$l"
  case "$k6" in ok) r6="$(okline "$OUT" "$i6" "$f1" ${f2:+"$f2"} ${f3:+"$f3"})" ;; *) r6="$(noteline "$OUT" "$i6" "$f1" ${f2:+"$f2"} ${f3:+"$f3"})" ;; esac
  check "$r6" "yes" "6c $k6 $i6: ${f1:0:100}"
done
if [ "$LIVE104" = yes ]; then VC104="$(kv104 "$RBB104" vc)"
  check "$(grep -cxF "  INSTRUCTION TO THE OWNER (VN-RB6) — OpenAI was called from here: $VC104 wa_voice_calls row(s), so audio went to OpenAI with the stored key." <<<"$OUT")$(grep -cxF "    1. $V103's settings page prints the stored OpenAI key into its page source." <<<"$OUT")$(grep -cxF "    2. Rotate the key at OpenAI, on OpenAI's own pages, and save the new one in the settings page." <<<"$OUT")$(grep -cF "    3. The first call's date is on the sheet ($SH6_104 — " <<<"$OUT")|$(grep -cxF "  tool    | VN-RB6: OpenAI was called from here; rotate the OpenAI key on OpenAI's own pages" <<<"$OUT")|$(grep -c '^  FAIL  VN-RB6 ' <<<"$OUT")" \
        "1111|1|0" \
    "6c' VN-RB6, a wa_voice_calls row on the book ($VC104): after the put-back, the INSTRUCTION TO THE OWNER, not a note — $V103's settings page prints the stored key; rotate it at OpenAI and save the new one; the first call's date on the sheet — and the tool's own one-line notice in QF-RB1's lines; never a FAIL (the count was read)"
else
  check "$(grep -c 'INSTRUCTION TO THE OWNER (VN-RB6)' <<<"$OUT")|$(grep -c '^  FAIL  VN-RB6 ' <<<"$OUT")|$(kv104 "$RBB104" vc)" "0|0|0" "6c' VN-RB6: no wa_voice_calls row (this rehearsal's reading: 0) — no instruction, no FAIL"
fi
ORD6_104="$(awk -v co="checking out $B103 ($V103) for the documented deploy" '
  index($0, "  tool    |   hook ack: ") == 1 && !h { h = NR } index($0, "  ok    QF-RB1 ") == 1 && !q1 { q1 = NR } index($0, "  ok    OF-RB1 ") == 1 && !o1 { o1 = NR }
  index($0, "  ok    VN-RB1 ") == 1 && !v1 { v1 = NR } index($0, "  ok    AC-RB1 ") == 1 && !a1 { a1 = NR } index($0, "  note  QF-RB2 ") == 1 && !q2 { q2 = NR }
  index($0, co) && !c { c = NR } index($0, "  ok    QF-RB3 ") == 1 && !q3 { q3 = NR } index($0, "  INSTRUCTION TO THE OWNER (VN-RB6)") == 1 && !v6 { v6 = NR }
  index($0, "  note  OF-RB2 ") == 1 && !o2 { o2 = NR } index($0, "  note  AC-RB2 ") == 1 && !a2 { a2 = NR }
  END { print (h && q1 && o1 && v1 && a1 && q2 && c && q3 && o2 && a2 && h < q1 && q1 < o1 && o1 < v1 && v1 < a1 && a1 < q2 && q2 < c && c < q3 && q3 < o2 && o2 < a2 && (!v6 || (q3 < v6 && v6 < o2))) ? "in order" : "h" h "q1" q1 "o1" o1 "v1" v1 "a1" a1 "q2" q2 "c" c "q3" q3 "v6" v6 "o2" o2 "a2" a2 }' <<<"$OUT")"
check "$ORD6_104" "in order" \
  "6d all before the put-back: the tool's hooks, QF-RB1's own read, OF-RB1, VN-RB1–VN-RB4, AC-RB1 and QF-RB2 come before $B103 is checked out for the documented deploy; QF-RB3, VN-RB5–VN-RB6, OF-RB2 and AC-RB2 after it"
EMSH104="$(grep -F 'ai.media events pending (pending, failed or processing; event ids): ' "$SH6_104" 2>/dev/null | head -1 | sed 's/.*event ids): //')"
check "$(stat -c %a "$SH6_104" 2>/dev/null)|$(python3 "$H104/rh104q.py" seckeys "$SH6_104" "QF-RB1 the keys that were on before the rollback's off all (key names; no value)" "$KBN104")|$(python3 "$H104/rh104q.py" section "$SH6_104" "VN-RB2/VN-RB4 what Part V's drains left, by event id (never paste)")|$EMSH104|$(python3 "$H104/rh104q.py" section "$SH6_104" "OF-RB1 the offers the rollback left paused (offer ids; never paste)")|$(grep -cxE '(paused offers: offer [0-9]+(, offer [0-9]+)*|paused offers: none)' "$SH6_104" 2>/dev/null)" \
      "600|present lines=1 keys=$NON104/$NON104 none=0 other=0|present:3|$EMID6_104|present:1|1" \
  "6e the ids on the run's 0600 sheet alone: QF-RB1's keys that were on ($NON104, by name, no other word); the drains' leavings by event id — the ai.media events exactly the ones this rehearsal's own reading found pending before the rollback; the offers the rollback left paused"
if [ "$LIVE104" = yes ]; then
  check "$(grep -cxE "paused offers: offer $OF6_104" "$SH6_104" 2>/dev/null)|$(python3 "$H104/rh104q.py" section "$SH6_104" "VN-RB6 the first call to OpenAI from here (the earliest wa_voice_calls row)")|$(sed -n '/^== VN-RB6 the first call to OpenAI from here/{n;p}' "$SH6_104" 2>/dev/null | grep -cxE '[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2} UTC')" "1|present:1|1" \
    "6e' …the offer this section started, by id, paused; VN-RB6's first call's date, on the sheet alone"
  check "$(sed -n 's/^voice turns left (ai.reply with origin voice, pending or processing; event ids): //p' "$SH6_104" 2>/dev/null)|$(sed -n 's/^acknowledgement events left (ai.voice_ack, pending or processing; event ids): //p' "$SH6_104" 2>/dev/null)" "$EV6R4_104|none" \
    "6e'' …the voice turn VN-RB2 counts as left, by id on the sheet alone — the one 6-6 planted, deferred; no acknowledgement event left"
fi
check "$(cat "$R104/line-$T6A104.txt" "$RBL6_104" 2>/dev/null | grep -cE 'event ids\): |paused offers: offer |^ *(was on: )')|$(grep -lF -f "$NAMES104" "$R104/line-$T6A104.txt" "$RBL6_104" "$SALES104"/followup-*.log "$R104/logs/cron104.out" "$R104/rb-webhook.log" 2>/dev/null | grep -c .)|$(grep -lF -f "$SB/secrets104.pat" "$R104/line-$T6A104.txt" "$RBL6_104" "$SALES104"/followup-*.log "$R104/logs/cron104.out" "$R104/rb-webhook.log" 2>/dev/null | grep -c .)|$(names100 "$R104/line-$T6A104.txt" "$RBL6_104")|$(secrets99 "$R104/line-$T6A104.txt" "$RBL6_104")|$(uother104 "$T6A104")|$(( $(evo_calls) - EVA104 ))" \
      "0|0|0|0|0|-|0" \
  "6f counts in the log, ids on the sheet: no event or offer id line and no 'was on' name in the terminal or the log; no fixture name or phone in them, the wrapper's logs, the cron stand-in's output or the webhook's server log; the webhook's token, staff records' names and the stand-ins' secrets in none; uCRM asked GETs only; Evolution not called by the rollback (each acknowledgement recorded, none sent)"
BLK6_104="$(python3 "$H104/rh104q.py" blocks "$R104/line-$T6A104.txt")"
check "$(kv104 "$BLK6_104" mixed)|$(grep -cxF "      $EXP_REDEPLOY104" <<<"$OUT")|$(grep -A1 -xF "      $EXP_REDEPLOY104" <<<"$OUT" | tail -1 | grep -c '^      ')|$(grep -cxF "      $EXP_QCHECK" <<<"$OUT")|$(grep -c 'deploy-5\.18\.104\.sh --rollback' <<<"$OUT")|$(cat "$OUT104"/rollback-state-*.env 2>/dev/null | grep -c "^RB_COPY_AT=$(stat -c %Y "$PD/$CM104")\$")|$(sha256sum "$STATE104" | cut -c1-64)" \
      "0|1|0|1|0|1|$S104SHA" \
  "6g the rollback's own summary: the quarantine's --check and --redeploy, each its own command, alone (the redeploy's next line no command); no copyable block holds two commands, and no line names the rollback; its record holds its copy's time as stamp_copy gave $CM104 (RB_COPY_AT, for A14); the deploy's state file untouched"
if [ "$LIVE104" = yes ]; then
  dr6() { echo $(( $(n103 "$(kv104 "$RBA104" "$1")") - $(n103 "$(kv104 "$RBB104" "$1")") )); }
  check "$(kv104 "$RBA104" oq)|$(kv104 "$RBA104" od)|$(dr6 cr)|$(dr6 ex)|$(kv104 "$RBA104" ap)|$(dr6 as)|$(kv104 "$RBA104" em)|$(kv104 "$RBA104" ea)|$(dr6 ks)|$(kv104 "$RBA104" vc)" \
        "0|0|$(kv104 "$RBB104" oq)|$(kv104 "$RBB104" od)|0|$(kv104 "$RBB104" ap)|$(kv104 "$RBB104" em)|0|$(kv104 "$RBB104" ea)|$(kv104 "$RBB104" vc)" \
    "6h this rehearsal's own reading after the rollback: every open quote follow-up closed as rollback, its draft expired; every pending audio row settled skipped/switched_off; the ai.media events still pending, as many as before (ruling R14); every acknowledgement event decided — each a skipped/switched_off acknowledgement, none sent; the reservation stays"
  check "$(kv104 "$RBA104" or_)|$(dr6 op)|$(kv104 "$RBA104" cl)|$(kv104 "$RBA104" cn)|$(kv104 "$RBA104" ts)|$(kv104 "$RBA104" ra)|$(kv104 "$RBA104" rb)" \
        "0|1|0|0|$YC6_104|uncertain/stale_claim/same/uncertain|queued/nb/noledger" \
    "6h' …Part O as ruling R12 reads it: the offer paused, none running; the recipient holding a ledger claim uncertain as a stale claim, its sent_at its claimed_at, its ledger row settled uncertain (maybe sent); the one without back to queued, a not_before set, no ledger claim; 0 claimed; the stale test settled, and only 6-6's young one ($YC6_104) still sending (ruling R18)"
  check "$(python3 "$H104/rh104q.py" rbrows "$DATA/plugin.sqlite3" "$YT6_104" "$IF6_104" "$EV6R4_104")|$(python3 "$H104/rh104q.py" vlabels "$DATA/plugin.sqlite3" "$VTL104")" "$RBP0_104|$VL6_104" \
    "6h''' …6-6's plants as the hooks must leave them, by this rehearsal's own reading: the young test still sending (the hook leaves a test under 15 minutes old); the audio row in flight untouched — its status, its attempt and its updated_at as before (VN-RB1b: nothing in flight touched); the deferred voice turn as before, still pending (no worker took it); R4's labelled body still the one VN-RB3's note counted"
fi
if [ "$LIVE104" = yes ]; then   # 5i set it on the main path, before mainend; on s2 it was never set, and a zero beside a zero proves nothing
  check "$(kv104 "$(python3 "$H104/rh104q.py" keysset "$R104/dsnap/rbfull/data/plugin.sqlite3" "$R104/dsnap/rbfull/data/kyc_config.json" sf_persona_confirmed)" set)|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" sf_persona_confirmed)" set)" "1|1" \
    "6h'' …the settings stay (ruling R5): sf_persona_confirmed, set as the rollback found it (5i), still set after it"
fi
check "$(python3 "$H104/rh104q.py" admins "$R104/dsnap/rbfull/data/plugin.sqlite3" "$R104/dsnap/rbfull/data/kyc_config.json")|$(python3 "$H104/rh104q.py" admins "$DATA/plugin.sqlite3" "$DATA/kyc_config.json")" "$ADM6_104|$ADM6_104" \
  "6h'''' …and quote_agent_cc_admins too (ruling R5; AC-RB1's \"as it was\"): the value the rollback found (6s's $(kv104 "$ADM6_104" file), in the settings file and the store row of rbfull's copy, the control), the same in both after it"
if [ "$LIVE104" = yes ]; then   # 6-6's plants taken out again — no writer deletes them; RH4-fix1 leaves the book for 6j, 6v, 6w and 104-7 as before it
  DROP6_104="$(rbraw104 drop "events:$EV6R4_104" "wa_media:$R4M104" "wa_messages:$R4G104" "wa_media:$IF6_104")|$(rbraw104 settle "$YT6_104")"
  check "$DROP6_104|$(python3 "$H104/rh104q.py" rbrows "$DATA/plugin.sqlite3" "$YT6_104" "$IF6_104" "$EV6R4_104")|$(python3 "$H104/rh104q.py" vlabels "$DATA/plugin.sqlite3" "$VTL104")|$(kv104 "$(rb104r)" ts)" \
        "dropped=4|settled=1|test=uncertain media=- event=-|r4=0 r3=0 turns=0 turn_ids=-|0" \
    "6x …6-6's plants taken out again (raw, as they came: no writer removes a voice turn, an audio row or a message), and its young test settled uncertain as the next hook would settle it (091 never lets a test be deleted) — so that 104-6v's hooks find nothing to act on and what follows meets the book the rollback left: no labelled body, no voice turn, no test sending"
fi
OUT="$(qrun104c --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "6i the quarantine's own --check after the rollback, as its summary orders (under the clock, after the rollback's copy): PASSED"
KEPT104="$(kept_state)"
# ── 6j: a 5.18.104 file left installed after the put-back. With 5.18.103 serving a --rollback runs its rollback checks alone;
# the stand-in's .left104m (spent at V's first request, after stage A compared the files) copies the pin's evo_webhook.php
# over the installed one — a file of QF-RB3's list, which QC-RB1 pins naming 5.18.104's words. QF-RB3 must fail naming it,
# and QC-RB1 and RB's sha256 too; then QF-RB3's weakened copy says ok while the others still name it. Put back byte for byte.
EVN104="$(awk -v f="$EVOW104" '/^QC_RB1_MARKS=/ { on = 1; next } on && /^MARKS$/ { exit } on { n = index($0, "|"); if (substr($0, 1, n - 1) == f) print substr($0, n + 1) }' "$DEPLOY104")"
cp -p "$PD/$EVOW104" "$SB/evow104.kept"
arm104m() { cp -p "$SB/evow104.kept" "$PD/$EVOW104"; printf '%s\t%s\n' "$SB/evow104.left" "$EVOW104" > "$SB/web/.left104m"; chmod 644 "$SB/web/.left104m"; }
arm104m
check "$( [ "$(n103 "$EVN104")" -ge 1 ] && echo pinned)|$(git -C "$REPO" show "$P103:$P/$EVOW104" 2>/dev/null | cmp -s - "$PD/$EVOW104" && echo base)|$(cmp -s "$PD/$EVOW104" "$SB/evow104.left" && echo same || echo differs)|$(grep -cF ' workers/StarlinkMailWorker.php evo_webhook.php cron/event_processor.php; do' "$DEPLOY104")" "pinned|base|differs|1" \
  "control: the installed $EVOW104 is $V103's after the rollback and the pin's differs from it; QF-RB3 reads it among 5.18.103's files, QC-RB1 pins it naming $V104's words $EVN104 time(s)"
fresh104; OUT="$(run104c --rollback)"
check "$(noteline "$OUT" the "container already serves $B103 ($V103) — nothing to roll back; running the rollback checks")$(spent99 .left104m)|$(cmp -s "$PD/$EVOW104" "$SB/evow104.left" && echo left)|$(failline "$OUT" QF-RB3 "after the put-back, not as $V103 must read: $EVOW104 — the by-hand put-back is above")$(failline "$OUT" QC-RB1 "$V103's installed code names what $V104 leaves, or its readers could not see the release:" "$EVOW104:names-a-word×$EVN104")$(failline "$OUT" RB "installed files that are not $V103's after the rollback:" "$EVOW104")|$(grep -c '^  BY HAND, ONLY IF QF-RB1 CANNOT RUN' <<<"$OUT")" \
      "yesspent|left|yesyesyes|1" \
  "6j $V103 serving, its rollback checks alone; a $V104 file installed by the stand-in after stage A: QF-RB3 FAILS naming it (the by-hand put-back printed beside it), and QC-RB1 and RB's sha256 name it too"
BLK6J104="$(python3 "$H104/rh104q.py" blocks "$R104/run-$(last104).txt")"; BLK6K104="$(python3 "$H104/rh104q.py" blocks "$R104/run-$T6K104.txt")"
check "$(kv104 "$BLK6J104" byhand)|$(kv104 "$BLK6J104" mixed)|$(kv104 "$BLK6K104" byhand)|$(kv104 "$BLK6K104" mixed)" "$BHR104|0|$BHR104|0" \
  "6jb 6j and 6k as the blocks reader sees them (RH4-R05): in each run the by-hand put-back is $BHR104 blocks holding its lines (qf_byhand's own runs, the positive control that the reader sees it), none beside a command"
arm104m; M="$(weak104 qfrb3 qfrb3w)"; fresh104; OUT="$(R104_MUTANT_OUT=1 run104c --script "$M" --rollback)"; rm -f "$M"
check "$(okline "$OUT" QF-RB3 "after the put-back:" "are $V103's byte for byte")$(failline "$OUT" QC-RB1 "$EVOW104:names-a-word×$EVN104")$(failline "$OUT" RB "installed files that are not $V103's after the rollback:" "$EVOW104")|$(spent99 .left104m)" "yesyesyes|spent" \
  "6j' QF-RB3's reading weakened — $EVOW104 taken out of its list of $V103's files: it says the installed code is $V103's while a $V104 file sits installed (caught); QC-RB1 and RB's sha256 still name it"
cp -p "$SB/evow104.kept" "$PD/$EVOW104"; rm -f "$SB/evow104.kept" "$SB/evow104.left"
check "$(installed104 "$P103")|$(live)|$(left104)|$(inst_ver)" "0|$P103|$NA104|$V103" "…the installed file put back: every file $V104 changed $V103's again"
OUT="$(qrun104c --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "6j'' the quarantine's own --check after the rollback checks, as RB asks: PASSED"
KEPT104="$(kept_state)"
# ── 6q (RH4-fix1): VN-RB6 when the wa_voice_calls count cannot be read (DS2-fix1 R03; ruling R7: the instruction prints
# whenever any row exists, and an unread count cannot say there is none). $V103 serving, the rollback checks alone (QF-RB3,
# VN-RB5–VN-RB6, OF-RB2, AC-RB2 run there); the fake docker refuses QF-RB3's live read (.rhf104.rb3) and VN-RB5/VN-RB6's copy
# of the book (.rhf104.vncopy): VN-RB6 FAILs — never taken for none — and the instruction prints in its unread wording, the
# sheet saying "first call: not read"; QF-RB3 FAILs as unread, the by-hand put-back beside it. The book holds a row (the
# control: this rehearsal's own count), so the instruction is owed either way. ──
VCQ104="$(kv104 "$(rb104r)" vc)"
: > "$SB/rhf104.fired"; : > "$SB/.rhf104.rb3"; : > "$SB/.rhf104.vncopy"
fresh104; OUT="$(run104c --rollback)"; T6Q104="$(last104)"; SHQ6_104="$(ls -1 "$OUT104"/cash-*.txt 2>/dev/null | head -1)"
check "$(noteline "$OUT" the "container already serves $B103 ($V103) — nothing to roll back; running the rollback checks")|$(failline "$OUT" QF-RB3 "after the put-back, not as $V103 must read:" " keys-unread(?rh104)" "— the by-hand put-back is above")|$(grep -c '^  BY HAND, ONLY IF QF-RB1 CANNOT RUN' <<<"$OUT")|$(noteline "$OUT" VN-RB5 "could not be read on a copy (no copy); QF-RB3's own read counts ? wa_voice_calls row(s)")|$(failline "$OUT" VN-RB6 "the wa_voice_calls count could not be read (on a copy: no copy; QF-RB3's live read: not read) — never taken for none: the instruction to the owner below prints all the same (ruling R7)")|$( [ "$(n103 "$VCQ104")" -ge 1 ] && echo rows || echo none)" \
      "yes|yes|1|yes|yes|$( [ "$LIVE104" = yes ] && echo rows || echo none)" \
  "6q $V103 serving, its rollback checks alone, QF-RB3's live read and VN-RB5/VN-RB6's copy both refused (the fake docker's faults): QF-RB3 FAILs as unread, the by-hand put-back beside it; VN-RB5 says it could not read its copy and QF-RB3's count is unknown; VN-RB6 FAILs — the count never taken for none — over a book that holds $VCQ104 row(s) by this rehearsal's own reading"
check "$(grep -cxF "  INSTRUCTION TO THE OWNER (VN-RB6) — the wa_voice_calls count could not be read: if OpenAI was ever called from here, audio went to OpenAI with the stored key." <<<"$OUT")$(grep -cxF "    1. $V103's settings page prints the stored OpenAI key into its page source." <<<"$OUT")$(grep -cxF "    2. Rotate the key at OpenAI, on OpenAI's own pages, and save the new one in the settings page — if OpenAI was ever called from here; the count could not be read, so do it unless you know it never was." <<<"$OUT")$(grep -c "^    3\. The first call's date is on the sheet (.*): it says \"first call: not read\"\.\$" <<<"$OUT")|$(sed -n '/^== VN-RB6 the first call to OpenAI from here/{n;p}' "$SHQ6_104" 2>/dev/null)|$(awk 'index($0, "  FAIL  VN-RB6 ") == 1 && !f { f = NR } index($0, "  INSTRUCTION TO THE OWNER (VN-RB6)") == 1 && !i { i = NR } END { print (f && i && f < i) ? "after" : "f" f "i" i }' <<<"$OUT")|$(grep -c '^  FAIL  AC-RB2 ' <<<"$OUT")|$(ls -A "$SB" | grep -cE '^\.rhf104\.')|$(paste -sd, "$SB/rhf104.fired")|$(live)" \
      "1111|first call: not read|after|0|0|rb3,vncopy|$P103" \
  "6q' …the INSTRUCTION TO THE OWNER in its unread wording, after the FAIL — the key printed by $V103's settings page, rotate it unless you know OpenAI was never called, the sheet saying \"first call: not read\" — and on the sheet exactly that; no AC-RB2 FAIL on a clean install (6p's control); both faults fired once, in order, none left armed; $P103 still serving"
: > "$SB/rhf104.fired"
# ── 6p (RH4-fix1): AC-RB2 over an installed $V103 file planted to name one of P7's collections (DS4: a planted edit → bad).
# The file is chosen from $V103's own tree (tests/ first), one $V104 does not touch and the script names nowhere — so no
# check before RB (stage A's comparison of the files $V104 changes) and no other check after it (QC-RB1's words, RB's sha256)
# can see the plant: AC-RB2 alone names it. One comment line, asserted in, the file put back byte for byte after. ──
ACF104="$(python3 -I - "$REPO" "$P103" "$P104" "$P" "$PD" "$DEPLOY104" <<'PY'
import subprocess, sys
repo, p103, p104, pl, pd, dep = sys.argv[1:7]
g = lambda *a: subprocess.run(['git', '-C', repo] + list(a), capture_output=True, check=True).stdout
changed = set(x for x in g('diff', '--name-only', p103, p104, '--', pl).decode().split('\n') if x)
script = open(dep, encoding='utf-8').read()
php = [x for x in g('ls-tree', '-r', '--name-only', p103, '--', pl).decode().split('\n') if x.endswith('.php')]
for f in sorted(php, key=lambda x: (not x.startswith(pl + '/tests/'), x)):
    rel = f[len(pl) + 1:]
    if f in changed or rel in script or rel.startswith('data/'):
        continue
    blob = g('show', p103 + ':' + f)
    try:
        disk = open(pd + '/' + rel, 'rb').read()
    except OSError:
        continue
    if disk == blob and blob.startswith(b'<?php\n') and b'quote_agent_' not in blob and b'QuoteAgentCopy' not in blob:
        print(rel)
        break
PY
)"
[ -z "$ACF104" ] || cp -p "$PD/$ACF104" "$SB/acf104.kept"
[ -n "$ACF104" ] && python3 -I - "$PD/$ACF104" <<'PY' || echo "plant:acrb2" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]; s = open(p, encoding='utf-8').read()
m = '// rh104 planted (AC-RB2): a reading of quote_agent_copies, never a 5.18.103 line\n'
assert s.startswith('<?php\n') and s.count('quote_agent_') == 0 and s.count(m) == 0
s2 = s.replace('<?php\n', '<?php\n' + m, 1)
assert s2.count(m) == 1 and s2.count('quote_agent_copies') == 1 and s2.startswith('<?php\n' + m) and len(s2) == len(s) + len(m)
open(p, 'w', encoding='utf-8').write(s2)
PY
check "$( [ -n "$ACF104" ] && echo chosen)|$(git -C "$REPO" diff --name-only "$P103" "$P104" -- "$P/$ACF104" | grep -c .)|$(grep -cF -- "$ACF104" "$DEPLOY104")|$(grep -c 'quote_agent_copies' "$PD/$ACF104")|$(git -C "$REPO" show "$P103:$P/$ACF104" 2>/dev/null | cmp -s - "$SB/acf104.kept" && echo kept)" \
      "chosen|0|0|1|kept" \
  "control: the planted file is $V103's own, one $V104 does not change and the script never names, as installed byte for byte before; it now names quote_agent_copies once"
fresh104; OUT="$(run104c --rollback)"; T6P104="$(last104)"
check "$(failline "$OUT" AC-RB2 "whether $V103's installed files name P7's collections could not be shown: $V103's PHP files naming them 1, the control (lib/QuoteAgentCopy.php, left on disk) 1 of 1")|$(grep -c '^  FAIL  AC-RB2 ' <<<"$OUT")|$(noteline "$OUT" AC-RB2 "P7's two collections stay — quote_agent_makers")|$(failline "$OUT" QC-RB1 "$ACF104")$(failline "$OUT" RB "$ACF104")|$(live)" \
      "yes|1|yes|nono|$P103" \
  "6p $V103 serving, its rollback checks alone, an installed $V103 file naming quote_agent_copies: AC-RB2 FAILs — $V103's PHP files naming P7's collections 1, the control 1 of 1 — and still notes the collections; neither QC-RB1 nor RB's sha256 names the file (AC-RB2's own catch)"
[ -z "$ACF104" ] || cp -p "$SB/acf104.kept" "$PD/$ACF104"; rm -f "$SB/acf104.kept"
check "$(git -C "$REPO" show "$P103:$P/$ACF104" 2>/dev/null | cmp -s - "$PD/$ACF104" && echo base)|$(grep -c 'quote_agent_copies' "$PD/$ACF104")|$(installed104 "$P103")|$(left104)" "base|0|0|$NA104" "…the file put back as $V103 has it; every file $V104 changed $V103's"
OUT="$(qrun104c --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "6p' the quarantine's own --check after the rollback checks, as RB asks: PASSED"
KEPT104="$(kept_state)"
echo; echo "== 104-6v. a deploy whose public sign-in page redirects rolls itself back (V1) — QF-RB1 first, then the put-back, then QC-RB2 =="
printf 'x\n' > "$SB/web/.redirect104"; chmod 644 "$SB/web/.redirect104"
# RH4-fix1 (DS2: "a planted labelled body at R3 (bad, the rollback goes on)"): a body labelled as R4 labels it but at rung
# handover — what R3 must never write (F10), and no writer here can (rbraw104.py's r3) — planted before the run: QF-RB1's
# VN-RB3 in this automatic rollback must FAIL on it, and the rollback still put $V103 back (6v1). Taken out after 6v5.
R3G104=0; R3M104=0
if [ "$LIVE104" = yes ]; then
  P3R_104="$(rbraw104 r3 "$VTL104")"; R3G104="$(n103 "$(kv104 "$P3R_104" msg)")"; R3M104="$(n103 "$(kv104 "$P3R_104" media)")"
  check "$( [ "$R3G104" -ge 1 ] && [ "$R3M104" -ge 1 ] && echo planted)|$(python3 "$H104/rh104q.py" vlabels "$DATA/plugin.sqlite3" "$VTL104")" "planted|r4=0 r3=1 turns=0 turn_ids=-" \
    "6v0 one body labelled at rung handover planted, by this rehearsal's own reading the only labelled body on the book"
fi
QCRB104="$(qkv103 "$(q103 apps)" qcrb2)"; DX6V104="$(dx104)"; EV6V104="$(evo_calls)"
fresh104; OUT="$(run104c --answer DEPLOY --redeploy)"; T104V="$(last104)"; SH104V="$(ls -1 "$OUT104"/cash-*.txt 2>/dev/null | head -1)"; OUT6V104="$OUT"
check "$(failline "$OUT" V1 'the sign-in page on the public address → 200 after 1 redirect(s)')$(has "$OUT" '  ROLLING BACK — the public address must never redirect')$(spent99 .redirect104)|$(live)|$(installed104 "$P103")|$(inst_ver)|$(left104)|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" "$ALLCSV104")" set)" \
      "yesyesspent|$P103|0|$V103|$NA104|0" \
  "6v1 --redeploy, DEPLOY typed: V1 FAILS on the redirect (the trigger spent) and the deploy rolls itself back — $P103 serving, its files back, the added ones on disk, every key still unset"
check "$(okline "$OUT" QF-RB1 "the installed tool's off all --rollback ran the one sequence" "0 key(s) that were on are on the sheet")$(okline "$OUT" OF-RB1 'the one sequence began with Part O')$(okline "$OUT" VN-RB1 "0 of Part V's keys were on")$(okline "$OUT" AC-RB1 'the one sequence cleared Part P')|$(okline "$OUT" QF-RB3 "after the put-back:" "are $V103's byte for byte")$(grep -c '^  FAIL  QF-RB3 ' <<<"$OUT")|$(noteline "$OUT" VN-RB5 "090's three tables")$(noteline "$OUT" OF-RB2 'the four offer tables')$(noteline "$OUT" AC-RB2 "P7's two collections stay")|$(grep -c 'INSTRUCTION TO THE OWNER (VN-RB6)' <<<"$OUT")|$(grep -cxF "  tool    | VN-RB6: OpenAI was called from here; rotate the OpenAI key on OpenAI's own pages" <<<"$OUT")" \
      "yesyesyesyes|yes0|yesyesyes|$( [ "$LIVE104" = yes ] && echo 1 || echo 0)|$( [ "$LIVE104" = yes ] && echo 1 || echo 0)" \
  "6v2 QF-RB1 ran in the automatic rollback too, before the put-back — the one sequence with every hook (nothing on: 0 keys to the sheet), OF-RB1, VN-RB1 and AC-RB1 reading its result; once the put-back verified, QF-RB3 reads it as RB does after a --rollback (DS5, S3): ok, with VN-RB5, OF-RB2 and AC-RB2's notes, and VN-RB6's instruction to the owner where OpenAI was called from here, beside the tool's one-line notice"
QV1_104="QC-RB2 counted after it, on a copy of the book, for the record only: $(qcrbsay103 "$QCRB104") (ids on the sheet) — $V104 changes nothing of how a web-direct application is quoted, so whichever release now serves handles each the same way; none is quoted in uCRM by hand because of this rollback"
check "$(noteline "$OUT" QC-RB2 "${QV1_104#QC-RB2 }")$(has "$OUT" "  STOP: rolled back (serves $P103); QF-RB3 above reads the put-back; $QV1_104; on Uganda the uCRM catalogue — its screen, its settings, its switch and the direct quoting — is $V103's and stays exactly as it was: $V104 changes none of it and its rollback touches none of it (root docs/73 §H.2.1, §H.2.16); send the log file")$(has "$OUT" 'did not complete')$(has "$OUT" 'is quoted in uCRM by hand (ids on the sheet)')|$(awk 'index($0, "  ROLLING BACK") == 1 && !a { a = NR } index($0, "  ok    QF-RB1 ") == 1 && !q { q = NR } index($0, "  note  QC-RB2 counted after it") == 1 && !b { b = NR } index($0, "  ok    QF-RB3 ") == 1 && !r { r = NR } index($0, "  STOP: rolled back") == 1 && !c { c = NR } END { print ((a && q && b && r && c && a < q && q < b && b < r && r < c) ? "in order" : "a" a "q" q "b" b "r" r "c" c) }' <<<"$OUT")" \
      "yesyesnono|in order" \
  "6v3 QC-RB2 counted after the rollback, on a copy — its note and its stop line say the count, for the record: $V104 changes nothing of how a web-direct application is quoted, none is quoted by hand because of the rollback, and the uCRM catalogue is $V103's, untouched (DS5) — in that order: ROLLING BACK, QF-RB1, the count, QF-RB3 (DS5, S3), the stop"
check "$(grep -cE '^ {6}(cd |\( cd )' <<<"$OUT")|$(has "$OUT" '== F. Summary ==')|$(sed -n 's/^RB_COPY_AT=//p' "$STATE104" | tail -1 | grep -cx "$(stat -c %Y "$PD/$CM104")")|$(grep -c "^== QC-RB2 the web-direct applications with no quotation — after V1's automatic rollback (" "$SH104V" 2>/dev/null)|$(uother104 "$T104V")|$(grep -lF -f "$NAMES104" "$R104/run-$T104V.txt" "$OUT104"/*.log 2>/dev/null | grep -c .)|$(( $(evo_calls) - EV6V104 ))|$(dx104)" \
      "0|no|1|1|-|0|0|$DX6V104" \
  "6v4 the path prints no command; the deploy's state file holds the rollback's copy time (RB_COPY_AT, for A14); the ids on the sheet under \"after V1's automatic rollback\"; GETs only; no fixture name; Evolution not called; the book and the vault as before but the settings, which QF-RB1's saves rewrite"
OUT="$(qrun104c --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "6v5 the quarantine's own --check after the deploy's own rollback, as A14 asks of the next deploy: PASSED"
KEPT104="$(kept_state)"
if [ "$LIVE104" = yes ]; then
  check "$(failline "$OUT6V104" VN-RB3 "1 body(ies) below R4 carry the transcript label — R3 must leave the body [AUDIO] (F10); $V103 will show them as customer text")|$(noteline "$OUT6V104" VN-RB3 "0 message body(ies) carry R4's transcript label")|$(okline "$OUT6V104" VN-RB3 "no body below R4 carries the transcript label")|$(fset104 "$OUT6V104" | sed 's/:.*//' | tr ' ' '\n' | grep -cx VN-RB3)|$(live)|$(installed104 "$P103")" \
        "yes|yes|no|1|$P103|0" \
    "6v6 …in that automatic rollback VN-RB3 FAILed on the body labelled at R3 (1, and R4's note 0) — never its ok line — and the rollback went on: $P103 put back and serving, every file $V104 changed $V103's (6v1)"
  check "$(rbraw104 drop "wa_media:$R3M104" "wa_messages:$R3G104")|$(python3 "$H104/rh104q.py" vlabels "$DATA/plugin.sqlite3" "$VTL104")" "dropped=2|r4=0 r3=0 turns=0 turn_ids=-" \
    "6v7 …the planted body taken out again (raw, as it came), so that 104-6w and 104-7 meet the book as before it"
fi
echo; echo "== 104-6w. the same, with the deploy's own rollback not verifying: it says so, and prints the by-hand put-back alone, the hazard first =="
printf 'x\n' > "$SB/web/.redirect104"; chmod 644 "$SB/web/.redirect104"; : > "$SB/.rbfail104"
check "$(inst_ver)|$(live)|$(v1read102)|$(ls -A "$SB" | grep -cE '^\.rbfail104(\.seen)?$')" "$V103|$P103|200:0|1" \
  "6w the redirect planted again (unspent while $V103's manifest is installed), the rollback's failed read armed and not yet seen"
QCRB104="$(qkv103 "$(q103 apps)" qcrb2)"; DX6W104="$(dx104)"; EV6W104="$(evo_calls)"
fresh104; OUT="$(run104c --answer DEPLOY --redeploy)"; T104W="$(last104)"; SH104W="$(ls -1 "$OUT104"/cash-*.txt 2>/dev/null | head -1)"
check "$(failline "$OUT" V1 'the sign-in page on the public address → 200 after 1 redirect(s)')$(has "$OUT" '  ROLLING BACK — the public address must never redirect')$(spent99 .redirect104)|$(ls -A "$SB" | grep -cE '^\.rbfail104(\.seen)?$')|$(has "$OUT" "container still reports '0000000', expected '$P103'")$(has "$OUT" "the checkout is back on ")$(okline "$OUT" QF-RB1 "the installed tool's off all --rollback ran the one sequence")|$(live)|$(installed104 "$P103")|$(inst_ver)|$(left104)" \
      "yesyesspent|0|yesyesyes|$P103|0|$V103|$NA104" \
  "6w1 V1 FAILS, QF-RB1 runs and the deploy's own rollback copies — and does not verify: deploy-hybrid.sh read the record back wrong once (the trigger spent) and the checkout returned; the files are $V103's and the record names it again"
SW104="  STOP: the automatic rollback did not complete — it did not verify (live=$P103): $V104 may still serve, or some installed files may already be $V103's, and the public address may still redirect. QC-RB2 counted after it, on a copy of the book, for the record only: $(qcrbsay103 "$QCRB104") (ids on the sheet) — $V104 changes nothing of how a web-direct application is quoted, so whichever release now serves handles each the same way; none is quoted in uCRM by hand because of this rollback. $PH104 Put $V103 back by hand, with its own command — then send this log file and that command's log:"
check "$(grep -cxF "$SW104" <<<"$OUT")|$(grep -A1 -xF "$SW104" <<<"$OUT" | tail -1 | grep -cxF "      $EXP_PUTBACK104")|$(grep -A2 -xF "$SW104" <<<"$OUT" | tail -1 | grep -c '^      ')|$(grep -cE '^ {6}(cd |\( cd )' <<<"$OUT")|$(has "$OUT" 'STOP: rolled back (serves ')|$(grep -c "^== QC-RB2 the web-direct applications with no quotation — after V1's automatic rollback attempt (" "$SH104W" 2>/dev/null)|$(kv104 "$(python3 "$H104/rh104q.py" blocks "$R104/run-$T104W.txt")" mixed)" \
      "1|1|0|1|no|1|0" \
  "6w2 the stop says the rollback did not complete — never \"rolled back\" — with QC-RB2's count, the put-back's hazard first and the by-hand put-back alone on the next line, no command after it: the one command printed; no copyable block holds two; the ids on the sheet under \"attempt\""
check "$(sed -n 's/^RB_COPY_AT=//p' "$STATE104" | tail -1 | grep -cx "$(stat -c %Y "$PD/$CM104")")|$(uother104 "$T104W")|$(grep -lF -f "$NAMES104" "$R104/run-$T104W.txt" "$OUT104"/*.log 2>/dev/null | grep -c .)|$(( $(evo_calls) - EV6W104 ))|$(dx104)" "1|-|0|0|$DX6W104" \
  "6w3 the state file holds the attempt's copy time (RB_COPY_AT, for A14); GETs only; no fixture name; Evolution not called; the book and the vault as before but the settings"
OUT="$(qrun104c --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "6w4 the quarantine's own --check after the attempt: PASSED"
KEPT104="$(kept_state)"
M="$(weak104 v1rb v1rbw)"; printf 'x\n' > "$SB/web/.redirect104"; chmod 644 "$SB/web/.redirect104"; : > "$SB/.rbfail104"
fresh104; OUT="$(R104_MUTANT_OUT=1 run104c --script "$M" --answer DEPLOY --redeploy)"; rm -f "$M"
check "$(has "$OUT" "container still reports '0000000', expected '$P103'")$(spent99 .redirect104)|$(ls -A "$SB" | grep -cE '^\.rbfail104(\.seen)?$')|$(has "$OUT" 'STOP: rolled back (serves ')$(has "$OUT" 'did not complete')|$(grep -cxF "      $EXP_PUTBACK104" <<<"$OUT")|$(live)" \
      "yesspent|0|yesno|0|$P103" \
  "6w5 control: with do_rollback's answer ignored (\"do_rollback || true\"), the same failed rollback says \"rolled back\" and prints no put-back — 6w2 does not hold on it (caught)"
OUT="$(qrun104c --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "6w6 the quarantine's own --check after it: PASSED"
KEPT104="$(kept_state)"
check "$(ls -A "$REPO/scripts" | grep -c '^\.')|$( [ -e "$REPO/.git/index.lock" ] && echo locked || echo free)|$(git -C "$REPO" symbolic-ref -q --short HEAD)|$(ls -A "$SB/web" | grep -cE '^\.(redirect104|left104m)$')|$(ls -A "$SB" | grep -cE '^\.(rbfail104|rbfail104\.seen|rhf104\..*)$')|$( [ -e "$R104/cron104.on" ] && echo on || echo off)|$(pgrep -f "$PD/run_(media_)?worker\.php" 2>/dev/null | grep -c .)|$(ls "$R104/hold104"/*.held 2>/dev/null | grep -c .):$(ls "$R104/hold104"/*.released 2>/dev/null | grep -c .):${HOLDPID104:-none}|$(git -C "$REPO" ls-files -v | grep -c '^S ')" \
      "$DOT0_104|free|$BRANCH|0|0|off|0|2:2:none|0" \
  "…no weakened copy left in the clone, its index free and on its branch; no trigger of this section's left armed (the fake docker's four faults among them), the cron stand-in stopped, no worker running; both locks 6l held were let go, no holder left; no file of the clone hidden from git"
rm -f "$H104/v/live.err" "$H104/v/live-vault.json"   # the live acts' error file and vault copy, as 5o' leaves them for 104-10's sweep (live104 made them again here)

# ══ 104-7: --redeploy after the rollback (root docs/73 §H.2.15.4's --redeploy case, Part V's §H.3.25 redeploy case, Part O's
# §H.4.18 redeploy cases, Part P's §H.5.14 "--redeploy, starting dark"). 104-6w left 5.18.103 serving over a book 5.18.104 ran
# on (089–091 applied, its rows in the four tables, an offer paused, voice history). Each case is a whole --redeploy, DEPLOY
# typed, under the rehearsal's clock (the book's stamps are the clock's), followed by the rollback (rbx104) so that the next
# case is a redeploy again. The deploy's own moments — QF-G4's second read (the moment it "returns", after the clear), QF-G1's
# baseline call (the copy's start is that call's time()), the first read of the container's record that names the pin (the
# copy done) — are seen by the fake docker, which this section teaches one hook (an asserted insertion, armed only while
# $SB/.tl104 exists): it records each moment's start and end in a timeline and may hold the deploy before a moment (<ev>.<n>.hold
# until .go) or after it (<ev>.<n>.after until .release), so that writers of the EARLIER build — the pin's own code, from Git,
# run from copies of its tree in this rehearsal's actors' folder (never the release, never the installed plugin), each pause
# an asserted insertion into that COPY — act exactly at the moments D names: a reminder run, a master cycle and daily-list
# runs begun before the clear, a second sqlite connection holding the book's write lock 3 s, a voice note and its
# acknowledgement made between stage A's copy and the clear, an acknowledgement and an OpenAI call held before their claim
# and reservation, an offer test held by a slow stand-in Evolution, an admin's Start held before its BEGIN IMMEDIATE. Rows no
# writer makes — a claim, a decision, a call, an echo, a test stamped in a chosen second — are planted raw (plant104), said so.
# Cost, stated: every judgement of QF-CA2, QF-CA3, VN-CA4 and OF-CA3 is a whole deploy, so the controls of the four parts share
# one run (104-7c's C1: each planted row read in its own check's count) and the weakened copies are grouped by the form they
# weaken (one stage-A comparison, one "at or after", one without the pause, one absolute count) — each check's FAIL is its own
# weakening's, and each has an unweakened run over the same acts that reads PASS.
if [ "$LIVE104" != yes ]; then
  echo "  RH-NOTE 104-7: the live cases' preconditions did not hold (104-5's control) — the redeploy cases need the stand-in Evolution, the webhook's secret and an assistant no provider can reach; 104-7f–104-7c are not run"
else
echo; echo "== 104-7f. --redeploy --clear-switches with every part's keys set: the earlier deployment's rows and entries printed; a reminder run, a master cycle and a daily-list run begun before the clear; claims in the copy's start second — QF-CA2, QF-CA3 and OF-CA3 read PASS =="
ACT104="$H104/act104"; rm -rf "${ACT104:?}"; mkdir -p "$ACT104"; chown 1000:1000 "$ACT104"; chmod 755 "$ACT104"; : > "$R104/wait104.fail"; ACTPIDS104=()
PINT104="$H104/pin104"; rm -rf "${PINT104:?}"; mkdir -p "$PINT104"
git -C "$REPO" archive "$P104" "$P" | tar -xf - -C "$PINT104" --exclude="$P/tests" --exclude="$P/data"
DDM104="$MOUNT/${PDD104_IN#/data/}"   # the plugin's data folder as the fake docker maps the container's /data path
treesum104() {   # treesum104 git|files — the pin's tree (tests/ and data/ aside) by file and sha256, from Git or from the copy
  if [ "$1" = git ]; then git -C "$REPO" archive "$P104" "$P" | tar -tf - | grep -v "^$P/tests/\|^$P/data/" | grep -v '/$' | while read -r f; do printf '%s %s\n' "${f#$P/}" "$(git -C "$REPO" show "$P104:$f" | sha256sum | cut -c1-16)"; done | LC_ALL=C sort | sha256sum | cut -c1-16
  else (cd "$PINT104/$P" && find . -type f | sed 's#^\./##' | LC_ALL=C sort | while read -r f; do printf '%s %s\n' "$f" "$(sha256sum "$f" | cut -c1-16)"; done) | sha256sum | cut -c1-16; fi
}
check "$( [ -f "$PINT104/$P/cron/quote_daily_list.php" ] && [ -f "$PINT104/$P/workers/MediaWorker.php" ] && [ -f "$PINT104/$P/lib/QuoteDailyList.php" ] && echo tree)|$( [ -e "$PINT104/$P/tests" ] && echo tests || echo notests)|$(treesum104 files)|$(realpath "$DDM104")" \
      "tree|notests|$(treesum104 git)|$(realpath "$DATA")" \
  "7x control: the earlier build's code is the pin's tree from Git, tests/ and data/ aside, every file byte for byte as the release holds it (its digest from the files equal to the one computed from Git); the fake docker maps the plugin's data folder to the live one"
# ── The fake docker's moments, one asserted insertion before its last line (the exec), inert unless $SB/.tl104 exists. ──
cat > "$SB/docker104m.hook" <<'HOOK'
# ── 5.18.104's rehearsal (RH5): the deploy's moments, armed only while @SB@/.tl104 exists — QF_PHP's g4 and base calls, the
# clear (the pin's tool, off all --rollback, on the live data folder) and the first read of the container's record that names
# the pin (the copy done); each moment's start and end in the timeline, a hold before it (<ev>.<n>.hold until .go) or after it
# (<ev>.<n>.after until .release), each wait bounded (300 s); its output passed through unchanged ──
if [ -d "@SB@/.tl104" ]; then
  _rh5_e=""; _rh5_p=""
  for _rh5_a in "${args[@]}"; do
    if [ "$_rh5_p" = r ]; then case "$_rh5_a" in '// QF_PHP'*) _rh5_p=q ;; *) _rh5_p="" ;; esac
    elif [ "$_rh5_p" = q ]; then case "$_rh5_a" in g4|base) _rh5_e="$_rh5_a" ;; esac; break
    elif [ "$_rh5_a" = -r ]; then _rh5_p=r; fi
  done
  case " ${args[*]} " in *"/code/tools/sales_followup.php off all --rollback "*) [ "${DN_DATA_DIR:-}" = "@DDM@" ] && _rh5_e=clear ;; esac
  [ "${args[0]:-}" = cat ] && case "${args[1]:-}" in */.deployed-commit) _rh5_e=rec ;; esac
  if [ -n "$_rh5_e" ]; then
    _rh5_d="@SB@/.tl104"; _rh5_o="$(mktemp "$_rh5_d/out.XXXXXX")"; _rh5_k=""
    if [ "$_rh5_e" != rec ]; then
      _rh5_n=$(( $(cat "$_rh5_d/$_rh5_e.n" 2>/dev/null || echo 0) + 1 )); echo "$_rh5_n" > "$_rh5_d/$_rh5_e.n"; _rh5_k="$_rh5_d/$_rh5_e.$_rh5_n"
      if [ -f "$_rh5_k.align" ]; then _rh5_i=0; while [ "$(date +%N | cut -c1)" != 0 ] && [ "$_rh5_i" -lt 300 ]; do sleep 0.01; _rh5_i=$((_rh5_i+1)); done; fi
      printf '%s %s start %s\n' "$_rh5_e" "$_rh5_n" "$(date +%s.%N)" >> "$_rh5_d/timeline"; : > "$_rh5_k.start"
      if [ -f "$_rh5_k.hold" ]; then _rh5_i=0; while [ ! -f "$_rh5_k.go" ] && [ "$_rh5_i" -lt 6000 ]; do sleep 0.05; _rh5_i=$((_rh5_i+1)); done; fi
    fi
    "${pre[@]}" "${args[@]}" > "$_rh5_o"; _rh5_rc=$?
    if [ "$_rh5_e" = rec ] && grep -q '@P104@' "$_rh5_o" && [ ! -f "$_rh5_d/copied.1.end" ]; then
      _rh5_e=copied; _rh5_n=1; _rh5_k="$_rh5_d/copied.1"; printf 'copied 1 start %s\n' "$(date +%s.%N)" >> "$_rh5_d/timeline"
    fi
    if [ -n "$_rh5_k" ]; then
      cp "$_rh5_o" "$_rh5_k.out"; printf '%s %s end %s\n' "$_rh5_e" "$_rh5_n" "$(date +%s.%N)" >> "$_rh5_d/timeline"; : > "$_rh5_k.end"
      if [ -f "$_rh5_k.after" ]; then _rh5_i=0; while [ ! -f "$_rh5_k.release" ] && [ "$_rh5_i" -lt 6000 ]; do sleep 0.05; _rh5_i=$((_rh5_i+1)); done; fi
    fi
    cat "$_rh5_o"; rm -f -- "$_rh5_o"
    exit "$_rh5_rc"
  fi
fi
HOOK
python3 -I - "$SB/bin/docker" "$SB/docker104m.hook" "$SB" "$DDM104" "$P104" <<'PY' || echo "fakedocker:moments104" >> "$SB/anchor-missing"
import sys
p, hook, sb, ddm, p104 = sys.argv[1:6]
assert len(p104) >= 7 and all(c in '0123456789abcdef' for c in p104) and ddm.startswith('/'), (p104, ddm)
s = open(p).read()
h = open(hook).read().replace('@SB@', sb).replace('@DDM@', ddm).replace('@P104@', p104)
assert '@' not in h.replace('"${args[@]}"', '').replace('"${pre[@]}"', '').replace('${args[*]}', ''), 'a placeholder left'
old = 'exec "${pre[@]}" "${args[@]}"\n'
assert s.count(old) == 1 and s.count('.tl104') == 0, (s.count(old), s.count('.tl104'))
s2 = s.replace(old, h + old, 1)
assert s2.count(old) == 1 and s2.count('@SB@') == 0 and s2.count(sb + '/.tl104') == 3 and s2.index('_rh5_e=""') < s2.index(old)
open(p, 'w').write(s2)
PY
rm -f "$SB/docker104m.hook"
tl_arm104() { rm -rf "${SB:?}/.tl104"; mkdir -p "$SB/.tl104"; chmod 755 "$SB/.tl104"; local x; for x in "$@"; do : > "$SB/.tl104/$x"; done; }   # tl_arm104 FLAG… — e.g. g4.2.hold base.1.after base.1.align
tl_disarm104() { cp "$SB/.tl104/timeline" "$R104/logs/tl-$1.txt" 2>/dev/null || : > "$R104/logs/tl-$1.txt"; rm -rf "${SB:?}/.tl104"; }       # tl_disarm104 CASE — the timeline kept, the folder gone
wfile104() {   # wfile104 PATH [SECS] — wait until PATH exists (default 60 s); a timeout is recorded in wait104.fail, never silent
  local i=0 lim=$(( ${2:-60} * 20 ))
  while [ ! -e "$1" ] && [ "$i" -lt "$lim" ]; do sleep 0.05; i=$((i+1)); done
  [ -e "$1" ] || { echo "wait104:${1##*/}" >> "$R104/wait104.fail"; return 1; }
}
wsec104() { local i=0; while [ "$(fnow104)" -lt "$1" ] && [ "$i" -lt 100 ]; do sleep 0.05; i=$((i+1)); done; }   # wsec104 EPOCH — until the clock reads EPOCH
tlu104() { sed -n 's/.* unix=\([0-9][0-9]*\).*/\1/p' "$SB/.tl104/$1.out" 2>/dev/null | head -1; }   # tlu104 base.N — the copy's start, as that call printed it
tlt104() {   # tlt104 TIMELINE EVENT 2|3 — that moment's start (2) or end (3) second, from rh104q tl's "EVENT=<start>-<end>" tokens:
             # the whole token matched by its name (never a prefix of another, never text before it), empty when absent
  local t
  for t in $1; do
    [ "${t%%=*}" = "$2" ] || continue
    [[ "${t#*=}" =~ ^([0-9]+|-)-([0-9]+|-)$ ]] && printf '%s\n' "${BASH_REMATCH[$(( $3 - 1 ))]}"
    return 0
  done
}
TLT0_104='g4.1=11-12 clear.1=13-14 g4.10=90-91 base.1=15--'
check "$(tlt104 "$TLT0_104" clear.1 2),$(tlt104 "$TLT0_104" clear.1 3)|$(tlt104 "$TLT0_104" g4.1 2),$(tlt104 "$TLT0_104" g4.1 3)|$(tlt104 "$TLT0_104" base.1 2),$(tlt104 "$TLT0_104" base.1 3)|$(tlt104 "$TLT0_104" copied.1 2)" \
      "13,14|11,12|15,-|" \
  "7x' control: the timeline reader takes an event that is not first by its whole token (clear.1 after g4.1: 13 and 14, not the text before it), g4.1 never as a prefix of g4.10, an end not yet written as -, and an absent event as nothing"
# The control of the hook: a QF_PHP-shaped call through the fake docker is recorded, held and passed through; a call of
# another source is not; the clear's shape on the live data folder is; the record read naming 5.18.103 is no copy.
tl_arm104 base.1.hold
( env PATH="$SB/bin:$PATH" bash "$SB/bin/docker" exec -u 1000:1000 ucrm php -r '// QF_PHP rh104 control
echo "rows=1 at=x unix=7";' base x > "$R104/tl-control.out" 2>&1 ) & TLC104=$!
wfile104 "$SB/.tl104/base.1.start" 30; TLH104="$( [ -f "$SB/.tl104/base.1.end" ] && echo ran || echo held)"
: > "$SB/.tl104/base.1.go"; wait "$TLC104"
env PATH="$SB/bin:$PATH" bash "$SB/bin/docker" exec -u 1000:1000 ucrm php -r '// VN_PHP rh104 control
echo 1;' base x >/dev/null 2>&1
env PATH="$SB/bin:$PATH" bash "$SB/bin/docker" exec -u 1000:1000 -e "DN_DATA_DIR=$PDD104_IN" ucrm echo x/code/tools/sales_followup.php off all --rollback >/dev/null 2>&1
TLR104="$(env PATH="$SB/bin:$PATH" bash "$SB/bin/docker" exec ucrm cat "$IN_CONTAINER/.deployed-commit" 2>/dev/null | tail -n1)"
check "$TLH104|$(cat "$R104/tl-control.out")|$(cat "$SB/.tl104/base.n" 2>/dev/null)|$(cat "$SB/.tl104/clear.n" 2>/dev/null)|$TLR104|$( [ -f "$SB/.tl104/copied.1.end" ] && echo copied || echo none)|$(python3 "$H104/rh104q.py" tl "$SB/.tl104/timeline" | tr ' ' '\n' | sed 's/=.*//' | paste -sd, -)" \
      "held|rows=1 at=x unix=7|1|1|$P103|none|base.1,clear.1" \
  "7x control: the fake docker's moments hook — a QF_PHP base call held until let go, then run, its output passed through unchanged; a VN_PHP call not counted; the clear's shape on the live data folder counted; the record read naming $V103 is no copy — each moment in the timeline"
tl_disarm104 control; rm -f "$R104/tl-control.out"
check "$(bash -n "$SB/bin/docker" && echo parses)|$( [ -e "$SB/.tl104" ] && echo armed || echo inert)|$(env PATH="$SB/bin:$PATH" bash "$SB/bin/docker" exec -u 1000:1000 ucrm php -r '// QF_PHP rh104 inert
echo "inert";' base x 2>/dev/null)" "parses|inert|inert" "7x control: …the fake docker parses, and with the folder gone the hook is inert: the same call simply runs"
# ── The actors: copies of the pin's tree, each with a ucrm.json naming the live data folder (getDataDir's first rule) and the
# live data/config.json's copy; run as the store's user, under the clock, with a vault copy of their own (removed when each
# ends); each injected pause an asserted insertion before an anchor line found exactly once in that COPY. ──
pinroot104() {   # pinroot104 NAME — an actor's code root: a copy of the pin's tree in the actors' folder; prints its path
  local d="$ACT104/$1"; rm -rf "${d:?}"; mkdir -p "$d"; cp -a "$PINT104/$P/." "$d/"; mkdir -p "$d/data"
  [ ! -f "$PD/data/config.json" ] || cp "$PD/data/config.json" "$d/data/config.json"
  printf '{"pluginDataDir":"%s"}\n' "$DATA" > "$d/ucrm.json"; chown -R 1000:1000 "$d"; echo "$d"
}
pause104() {   # pause104 FILE ANCHOR [PHP-CONDITION] — the injected pause, in an actor's COPY only (its path under act104/): one line
               # before ANCHOR (a whole line, found exactly once): while RH104_PAUSE names a path (and CONDITION holds), touch
               # <path>.ready, wait at most 600 s for <path>.go, touch <path>.begin; asserted, the anchor kept once
  python3 -I - "$1" "$2" "${3:-true}" <<'PY' || echo "pause104:${1##*/}" >> "$SB/anchor-missing"
import sys
f, a, cond = sys.argv[1], sys.argv[2], sys.argv[3]
assert '/act104/' in f, f
s = open(f, encoding='utf-8').read()
assert a.strip() and s.count(a) == 1 and ('\n' + a + '\n') in s and s.count('RH104_PAUSE') == 0, (a[:60], s.count(a))
ind = a[:len(a) - len(a.lstrip(' '))]
line = (ind + "if ((string)getenv('RH104_PAUSE') !== '' && (" + cond + ")) { $rh104p = (string)getenv('RH104_PAUSE'); @touch($rh104p . '.ready'); "
        "$rh104t = microtime(true); while (!is_file($rh104p . '.go') && microtime(true) - $rh104t < 600) usleep(20000); @touch($rh104p . '.begin'); }"
        "   // 5.18.104's rehearsal: an injected pause, in this COPY only\n")
s2 = s.replace('\n' + a + '\n', '\n' + line + a + '\n', 1)
assert s2.count(a) == 1 and s2.count('RH104_PAUSE') == 2 and s2.index(line) < s2.index(a) and s2.count(line) == 1
open(f, 'w', encoding='utf-8').write(s2)
PY
  "$PHPBIN" -l "$1" >/dev/null 2>&1 || echo "pause104:lint:${1##*/}" >> "$SB/anchor-missing"
}
actor104() {   # actor104 NAME PAUSE-PATH SCRIPT ARG… — a writer of the earlier build in the background: php SCRIPT as the store's
               # user, under the clock, its own vault copy (gone when it ends), RH104_PAUSE=PAUSE-PATH (none when empty); its
               # output in act104/NAME.out, its exit code in NAME.rc
  local n="$1" pz="$2"; shift 2
  local v="$ACT104/$n.vault.json"; rm -f "${v:?}" "${ACT104:?}/${n:?}.rc"; [ ! -f "$VAULT" ] || { cp "$VAULT" "$v"; chown 1000:1000 "$v"; chmod 600 "$v"; }
  ( cd "$ACT104" && setpriv --reuid 1000 --regid 1000 --clear-groups -- env DN_VAULT_FILE="$v" DN_DATA_DIR="$DATA" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" ${pz:+RH104_PAUSE="$pz"} \
      "$PHPBIN" -d log_errors=0 "$@" > "$ACT104/$n.out" 2> "$ACT104/$n.err"; echo "$?" > "$ACT104/$n.rc"; rm -f "${v:?}" ) &
  ACTPIDS104+=("$!"); PIDS+=("$!")
}
actwait104() { local p; for p in "${ACTPIDS104[@]:-}"; do [ -z "$p" ] || wait "$p" 2>/dev/null; done; ACTPIDS104=(); }   # every actor ended
lock104() {   # lock104 SECS MARKER — a second sqlite connection on the live book, as the store's user, under the clock: BEGIN IMMEDIATE
              # (the book's write lock), MARKER.locked with its time, held SECS, COMMIT, MARKER.released with its time
  ( cd "$ACT104" && setpriv --reuid 1000 --regid 1000 --clear-groups -- env LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" python3 -I -c 'import sqlite3, sys, time
db, secs, m = sys.argv[1], float(sys.argv[2]), sys.argv[3]
c = sqlite3.connect(db, timeout=10, isolation_level=None)
c.execute("PRAGMA busy_timeout = 10000")
c.execute("BEGIN IMMEDIATE")
open(m + ".locked", "w").write("%.3f\n" % time.time())
time.sleep(secs)
c.execute("COMMIT")
open(m + ".released", "w").write("%.3f\n" % time.time())
c.close()' "$DATA/plugin.sqlite3" "$1" "$2" ) &
  PIDS+=("$!")
}
# plant104 KIND EPOCH TAG — one row no writer makes, raw (each writer stamps its rows "now"; these carry a chosen second), as the
# store's user, on a handset of its own (i:rh104-plant: no real handset's spacing or caps), each settled (no claim left in
# flight): claim (a quote claim), offer (an offer claim), ack (a voice_ack claim), acksent / ackunc (a wa_voice_acks decision
# sent / uncertain with its voice_ack ledger row claimed that second), call (a wa_voice_calls reservation on the newest audio
# row, at that row's next free attempt: 090 makes (media_id, attempt) unique, and by C1 104-7v (b) has left a real call at
# attempt 1 there — trials 3, 4 and 5 planted (newest, 1) and were refused), echo (an evo_webhook_seen offer.send), test (a
# wa_offer_tests row started that second), testrow (a ledger record offer_test:<id> naming no test). Prints the row's table
# and id.
plant104() {
  ( cd "$ACT104" && setpriv --reuid 1000 --regid 1000 --clear-groups -- python3 -I -c 'import sqlite3, sys, time
db, kind, t, tag = sys.argv[1], sys.argv[2], int(sys.argv[3]), sys.argv[4]
st = time.strftime("%Y-%m-%d %H:%M:%S", time.gmtime(t)); day = time.strftime("%Y-%m-%d", time.gmtime(t + 3 * 3600))   # Kampala, UTC+3
c = sqlite3.connect(db, timeout=15); c.execute("PRAGMA busy_timeout = 15000")
H = "i:rh104-plant"
def led(k, ref, mode, outcome, pro, wf, wt, cd, ch):
    cur = c.execute("INSERT INTO wa_auto_sends (handset, kind, ref, try, mode, proactive, phone, local_day, gap_s, claimed_at, outcome, settled_at, detail, win_from, win_to, cap_day, cap_hour) VALUES (?, ?, ?, 1, ?, ?, NULL, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?)",
                    (H, k, ref, mode, pro, day, st, outcome, st, "rh104_planted", wf, wt, cd, ch))
    return "wa_auto_sends", cur.lastrowid
if kind == "claim": r = led("quote", "rh104-plant:" + tag, "claim", "not_sent", 1, 8, 19, 60, 30)
elif kind == "offer": r = led("offer", "offer:rh104-" + tag + ":1", "claim", "not_sent", 1, 8, 19, 60, 30)
elif kind == "ack": r = led("voice_ack", "ack:rh104-" + tag, "claim", "not_sent", 0, 7, 21, 60, None)
elif kind in ("acksent", "ackunc"):
    o = "sent" if kind == "acksent" else "uncertain"
    led("voice_ack", "ack:rh104-dec-" + tag, "claim", o, 0, 7, 21, 60, None)
    conv = c.execute("SELECT COALESCE(MAX(id), 0) FROM conversations").fetchone()[0] if c.execute("SELECT 1 FROM sqlite_master WHERE name = ?", ("conversations",)).fetchone() else 0
    cur = c.execute("INSERT INTO wa_voice_acks (wa_message_id, conversation_id, channel, handset, outcome, reason, variant, persona, ledger_ref, period_hours, gap_s, local_day, sent_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NULL, ?, 0, ?, 24, 60, ?, ?, ?, ?)",
                    ("RH104PLANT" + tag, conv, "sales", H, o, "A", "ack:rh104-dec-" + tag, day, st, st, st))
    r = ("wa_voice_acks", cur.lastrowid)
elif kind == "call":
    m = c.execute("SELECT COALESCE(MAX(id), 0), COALESCE(MAX(conversation_id), 0) FROM wa_media").fetchone()
    # 090: UNIQUE (media_id, attempt), and by C1 the newest audio row holds a real call at attempt 1 (104-7v b, the held note
    # B2; trials 3, 4 and 5 were refused here and planted no call), so the plant takes the next free attempt on that row
    a = c.execute("SELECT COALESCE(MAX(attempt), 0) + 1 FROM wa_voice_calls WHERE media_id = ?", (m[0],)).fetchone()[0]
    cur = c.execute("INSERT INTO wa_voice_calls (media_id, conversation_id, attempt, model, local_day, seconds_reserved, seconds_billed, cap_day_s, voice_rung, outcome, reason, created_at, settled_at) VALUES (?, ?, ?, ?, ?, 7, 0, 1800, ?, ?, ?, ?, ?)",
                    (m[0], m[1], a, "whisper-1", day, "dry_run", "failed", "rh104_planted", st, st))
    r = ("wa_voice_calls", cur.lastrowid)
elif kind == "echo":
    cur = c.execute("INSERT INTO evo_webhook_seen (message_id, instance, event, received_at) VALUES (?, ?, ?, ?)", ("RH104PLANT" + tag, "rh104-plant", "offer.send", st))
    r = ("evo_webhook_seen", cur.lastrowid)
elif kind == "test":
    o = c.execute("SELECT id, content_hash FROM wa_offers ORDER BY id DESC LIMIT 1").fetchone()
    cur = c.execute("INSERT INTO wa_offer_tests (offer_id, content_hash, variant, staff_id, channel, handset, state, reason, started_at, settled_at) VALUES (?, ?, 1, 0, ?, ?, ?, ?, ?, ?)",
                    (o[0], o[1], "sales", H, "failed", "rh104_planted", st, st))
    r = ("wa_offer_tests", cur.lastrowid)
elif kind == "testrow": r = led("other", "offer_test:999" + str(t)[-6:], "record", "sent", 0, None, None, None, None)
else: raise SystemExit("plant104: unknown kind")
c.commit(); c.close()
print("%s:%d" % r)' "$DATA/plugin.sqlite3" "$1" "$2" "$3" ) 2>>"$R104/logs/plant104.err"
}
entry104() {   # entry104 JOB EPOCH — master_schedule.json's entry for JOB with that last_run (a finished run, master's own form),
               # through the store's own save (rh104-live.php schedule, "only": every other entry as it stands) — a state no
               # writer makes at a chosen second (master stamps its cycle's own start)
  live104 schedule "$PINR104" "$DATA" "{\"only\":true,\"set\":{\"$1\":{\"last_run\":$2,\"duration_ms\":1000}}}"
}
cav104() { printf '%s\n' "$1" > "$R104/logs/rh5-$2.txt"; python3 "$H104/rh104q.py" ca104 "$R104/logs/rh5-$2.txt"; }   # cav104 OUTPUT NAME — the run's judgements (ca104)
v3gw104() { python3 -I -c 'import re, sys; ks = [k for k in sys.argv[1].split(",") if re.match(r"^(ai_|evo_|openai_|claude_)", k)]; print(",".join(sorted(["files." + k for k in ks] + ["store." + k for k in ks])))' "$1"; }   # the V3g names a set of keys makes CHANGED
v3gg104() { sed -n "s/^  note  V3g .* removed $V104's own key(s), by name, nothing else among the AI and WhatsApp settings: \(.*\) — the switch tool's keys (SalesFollowupLadder::allKeys()), cleared by design; no value\$/\1/p" <<<"$1" | tr ' ' '\n' | grep . | LC_ALL=C sort | paste -sd, -; }   # DS5 (S2): the names V3g's note says the switch tool removed — once its FAIL's (7f3's defect)
setnames104() { python3 -I - "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" "$ALLCSV104" <<'PY'   # the allKeys() names set now, in either copy (names only)
import json, sqlite3, sys
db, f, names = sys.argv[1], sys.argv[2], sys.argv[3].split(',')
try:
    k = json.load(open(f, encoding='utf-8'))
except Exception:
    k = {}
try:
    c = sqlite3.connect('file:' + db + '?mode=ro', uri=True); r = c.execute('SELECT data FROM kyc_config WHERE id = 0').fetchone(); s = json.loads(r[0]) if r else {}; c.close()
except Exception:
    s = {}
def setv(v):
    return v is not None and v != '' and v is not False
print(','.join(n for n in names if (isinstance(k, dict) and setv(k.get(n))) or (isinstance(s, dict) and setv(s.get(n)))))
PY
}
rbx104() {   # rbx104 LABEL — back to 5.18.103 after a redeploy, so the next case is a redeploy again: the rollback, ROLLBACK typed, under
             # the clock, the cron stand-in on (the ack hook's drain); PASSED, 5.18.103 serving, every file its own, the added ones on
             # disk, no key set; then the quarantine's own --check (A14 of the next deploy)
  local o
  cron104_up; fresh104; o="$(run104c --answer ROLLBACK --rollback)"; cron104_down
  check "$(fails "$o")$(has "$o" "  $V104 (rollback): PASSED.")|$(live)|$(installed104 "$P103")|$(left104)|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" "$ALLCSV104")" set)" \
        "0yes|$P103|0|$NA104|0" "$1 the rollback after it, ROLLBACK typed: PASSED — $P103 serving, every file $V104 changed $V103's, the $NA104 it adds on disk, no key set"
  o="$(qrun104c --check)"
  check "$(has "$o" 'stray-store quarantine (check): PASSED.')" "yes" "$1' …the quarantine's own --check after it, as A14 asks of the next deploy: PASSED"
  KEPT104="$(kept_state)"
}
kday_next104() {   # kday_next104 HH:MM — the next Kampala day, Monday to Saturday, whose HH:MM is at least an hour ahead of the clock: that moment
  local f t d; f="$(fnow104)"
  for d in 0 1 2 3 4 5 6 7 8; do t="$(kat104 "$(kday104 $(( f + 3600 + d * 86400 ))) $1")"; [ "$t" -gt $(( f + 3600 )) ] && [ "$(kdow104 "$t")" != 7 ] && { echo "$t"; return; }; done
}
dlwait104() {   # dlwait104 MARKER — a daily-list actor at its injected pause (its fresh read done, before its claim): ready, or why not
  wfile104 "$1.ready" 60 && echo ready || echo "not:$(tr -d '\n' < "$1.out" 2>/dev/null | cut -c1-120)"
}
PINR104="$(pinroot104 pin)"   # the earlier build's plain root: the reminder run, the key writer, the schedule's save, the tests and the offers
EVR5_0="$(evo_calls)"
check "$(live)|$(inst_ver)|$(left104)|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" "$ALLCSV104")" set)|$(mig104 | cut -d' ' -f1)|$( [ -f "$PINR104/ucrm.json" ] && grep -c "\"pluginDataDir\":\"$DATA\"" "$PINR104/ucrm.json")" \
      "$P103|$V103|$NA104|0|mig=$(tr ',' '\n' <<<"$MIGF104" | grep -c .)/$(tr ',' '\n' <<<"$MIGF104" | grep -c .)|1" \
  "7f control: the book as 104-6w left it — $P103 serving, the $NA104 files $V104 adds on disk, no key of allKeys() set, 089–091 in the ledger (a redeploy's book); the earlier build's root names the live data folder"
maxid104() { python3 -I -c 'import sqlite3, sys
c = sqlite3.connect("file:" + sys.argv[1] + "?mode=ro", uri=True)
try:
    print(c.execute("SELECT COALESCE(MAX(id), 0) FROM [%s]" % sys.argv[2]).fetchone()[0])
except sqlite3.Error:
    print(-1)' "$DATA/plugin.sqlite3" "$1"; }   # maxid104 TABLE — its largest id now (read-only)
four104() { python3 -I -c 'import sqlite3, sys
c = sqlite3.connect("file:" + sys.argv[1] + "?mode=ro", uri=True)
print(",".join(str(c.execute("SELECT COUNT(*) FROM [%s]" % t).fetchone()[0]) for t in ("quote_tracker", "wa_auto_sends", "quote_list_sends", "wa_seen_marks")))' "$DATA/plugin.sqlite3"; }   # the four tables' rows now, QF_PHP base's order
golet104() { local f; for f in "$@"; do : > "$f.go"; done; }   # golet104 MARKER… — let every paused actor go (so none is left waiting)
# ── R1: the clock to the next Monday–Saturday 08:30 Kampala (the daily list's window); every part's keys through the pin's
# own writer of keys (rh104-live.php allon, PluginConfig::saveOverrides); the earlier deployment's four entries read, any
# the earlier runs never wrote planted in master's own form (said: the plant is master's, at an earlier moment). ──
inwin104() { local h; h="$(TZ=Africa/Kampala date -d "@$(fnow104)" +%-H)"; [ "$h" -ge 8 ] && [ "$h" -lt 11 ] && [ "$(kdow104 "$(fnow104)")" != 7 ] && echo window; }   # the daily list's window on the clock: Monday to Saturday, 08:00–11:00 Kampala
clk_to104 "$(kday_next104 08:30)"
AL1_104="$(live104 allon "$PINR104" "$DATA" '{"statement":"RH104SECRET-a cold statement of the rehearsal","channels":"sales,support"}')"
SN1_104="$(setnames104)"; V3W1_104="$(v3gw104 "$SN1_104")"
for j in quote_chase quote_daily_list ai_media offers_send; do
  case ",$(kv104 "$(python3 "$H104/rh104q.py" sched4 "$DATA/plugin.sqlite3")" entries)," in *",$j:"*) ;; *) entry104 "$j" $(( $(fnow104) - 172800 )) >/dev/null ;; esac
done
E1B104="$(python3 "$H104/rh104q.py" sched4 "$DATA/plugin.sqlite3")"
check "$(qj "$AL1_104" ok 2>/dev/null)|$(qj "$AL1_104" after 2>/dev/null)|$(tr ',' '\n' <<<"$SN1_104" | grep -c .)|$(kv104 "$E1B104" n)|$( [ -n "$V3W1_104" ] && echo vkeys)|$(inwin104)" \
      "True|$(kv104 "$RK104" onkeys)|$(kv104 "$RK104" onkeys)|4|vkeys|window" \
  "7f1 every part's keys set through the pin's own writer of keys — all $(kv104 "$RK104" onkeys) of allKeys() but the skip and override markers ($(kv104 "$RK104" all) in all) read set by this rehearsal's own reading, Part V's ai_ keys among them; the four jobs' master_schedule.json entries on the book (the earlier deployment's, master's form); the clock in the daily list's window, Monday to Saturday 08:00–11:00 Kampala"
python3 "$H104/rh104q.py" phones '[{"phone":"+256700104061"},{"phone":"+256700104062"},{"phone":"+256700104063"},{"phone":"+256700104064"},{"phone":"+256700104065"},{"phone":"+256700104066"},{"phone":"+256700104067"},{"phone":"+256700104068"},{"phone":"+256700104069"}]' >> "$NAMES104"
# ── The writers of the earlier build that begin before the clear: a reminder run (held after its configuration is read, the
# ledger on); a master cycle (the pin's cron/master.php, a COPY with a pause before it dispatches quote_chase, every other job
# recorded as just run through the store's own save, the four's entries kept); a daily-list run (a COPY of the pin's
# lib/QuoteDailyList.php with a pause after its fresh read, before its claim's BEGIN IMMEDIATE). ──
A1_104="$ACT104/r1"; MAXL1_104="$(maxid104 wa_auto_sends)"
actor104 rem1 "" "$H104/rh104-live.php" remind "$PINR104" "$DATA" "{\"phone\":\"+256700104061\",\"name\":\"RH104SECRET-Kizza\",\"invoice\":\"RH104SECRET-INV-61\",\"marker\":\"$A1_104.rem\"}"
MR1_104="$(pinroot104 r1m)"; pause104 "$MR1_104/cron/master.php" '    master_log("RUN {$_m_name}");' "\$_m_name === 'quote_chase'"
MS1_104="$(live104 schedule "$MR1_104" "$DATA" '{"due":["quote_chase"],"keep":["quote_daily_list","ai_media","offers_send"]}')"
actor104 m1 "$A1_104.master" "$MR1_104/cron/master.php"
DR1_104="$(pinroot104 r1d)"; pause104 "$DR1_104/lib/QuoteDailyList.php" "        \$cl = \$ledger->claim(\$inst, 'staff_list', \$ref);"
actor104 dl1 "$A1_104.dl1" "$DR1_104/cron/quote_daily_list.php"
wfile104 "$A1_104.rem.ready" 60; wfile104 "$A1_104.master.ready" 60; D1R104="$(dlwait104 "$A1_104.dl1")"
check "$( [ -f "$A1_104.rem.ready" ] && echo rem)|$(qj "$MS1_104" due 2>/dev/null)|$( [ -f "$A1_104.master.ready" ] && echo master)|$D1R104|$(grep -c 'RH104_PAUSE' "$MR1_104/cron/master.php" "$DR1_104/lib/QuoteDailyList.php" | cut -d: -f2 | paste -sd, -)|$(grep -rl 'RH104_PAUSE' "$PD" "$PINT104" 2>/dev/null | grep -c .)" \
      "rem|1|master|ready|1,1|0" \
  "7f2 before the run, and so before the clear: the reminder run has read its configuration (the ledger on) and waits; the master cycle has passed quote_chase's flag on its cycle's own configuration and waits before dispatching it; the daily-list run has made its fresh read and waits before its claim — each pause in an actor's COPY alone (one line of the rehearsal's in each, naming RH104_PAUSE twice: grep counts lines), none in the installed plugin or the pin's tree"
o_r1() {   # R1's moments, in order; each wait bounded, a timeout recorded
  local d="$SB/.tl104" a="$A1_104" u
  if [ "$D1R104" = ready ]; then
    # (1) QF-G4's second read, after the clear, held: the second connection takes the book's write lock for 3 s, the daily-list
    #     run is let into its claim's BEGIN IMMEDIATE, and only then QF-G4 reads — returning while the claim waits on the lock
    wfile104 "$d/g4.2.start" 600 || return
    lock104 3 "$a.lock"; wfile104 "$a.lock.locked" 30 || return
    : > "$a.dl1.go"; wfile104 "$a.dl1.begin" 30 || return; sleep 0.5
    : > "$d/g4.2.go"
  fi
  # (2) the baseline read: the four tables counted while the deploy waits after it; in the copy's start second an offer claim
  #     planted; the reminder let go (it records after the baseline)
  wfile104 "$d/base.1.end" 600 || return
  u="$(tlu104 base.1)"; four104 > "$a.basecount"
  plant104 offer "$u" r1 > "$a.plant.offer"
  : > "$a.rem.go"; wfile104 "$a.rem.done" 60
  : > "$d/base.1.release"
  # (3) the copy done (the first record read naming the pin): in a later second than the copy's start, master's cycle dispatches
  #     quote_chase; once it has ended, an entry of the four planted with the copy's start second as its last_run
  wfile104 "$d/copied.1.end" 900 || return
  wsec104 $(( u + 1 )); fnow104 > "$a.master.at"; : > "$a.master.go"; wfile104 "$ACT104/m1.rc" 120
  entry104 ai_media "$u" > "$a.plant.entry"
  : > "$d/copied.1.release"
}
if [ "$D1R104" = ready ]; then tl_arm104 g4.2.hold base.1.after copied.1.after; else tl_arm104 base.1.after copied.1.after; fi
o_r1 > "$R104/logs/orch-r1.txt" 2>&1 & OP1_104=$!
fresh104; OUT="$(run104c --answer DEPLOY --redeploy --clear-switches)"; T1R104="$(last104)"; OUT7F104="$OUT"; SH7F104="$(ls -1 "$OUT104"/cash-*.txt 2>/dev/null | head -1)"
kill "$OP1_104" 2>/dev/null; wait "$OP1_104" 2>/dev/null; golet104 "$A1_104.rem" "$A1_104.master" "$A1_104.dl1"; actwait104
TL1_104="$(python3 "$H104/rh104q.py" tl "$SB/.tl104/timeline")"; U1_104="$(tlu104 base.1)"; tl_disarm104 r1
CA1_104="$(cav104 "$OUT" r1)"
check "$(fset104 "$OUT")|$(has "$OUT" "  $V104 (deploy): PASSED.")|$(live)|$(installed104 "$P104")|$(inst_ver)|$(v3gg104 "$OUT")|$(okline "$OUT" V3g "but for $V104's own keys its switch tool removed")$(noteline "$OUT" V3g "QF-G4's --clear-switches removed $V104's own key(s)")$(grep -c '^  FAIL  V3g ' <<<"$OUT")" ":|yes|$P104|0|$V104|$V3W1_104|yesyes0" \
  "7f3 --redeploy --clear-switches, DEPLOY typed: PASSED — $P104 serving, every file the pin's; V3g, carried from 5.18.103, compares every ai_ key at stage A with after the run, and QF-G4's clear (the operator's explicit choice) clears Part V's ai_ keys: V3g reads ok and its note names exactly those, in both copies (this rehearsal's own list), as removed by QF-G4's --clear-switches — never a FAIL (DS5, S2)"
check "$(noteline "$OUT" QF-G4 "--clear-switches, the operator's explicit choice: once DEPLOY is typed, before the copy, the pin's tool runs off all --rollback")$(okline "$OUT" QF-G4 "--clear-switches: the pin's tool ran off all --rollback in the one sequence, every part's off-hooks, and exited 0")$(has "$OUT" '  …     waiting 6 s (busy_timeout plus 1 s) after QF-G4 before the redeploy baseline (QF-G1; Q20-F01)')|$(noteline "$OUT" QF-G1 "089, 090 AND 091 ARE ALREADY APPLIED ON THIS BOOK" "A REDEPLOY of $V104 after its rollback")|$(kv104 "$CA1_104" base)|$(kv104 "$CA1_104" vnbase)$(kv104 "$CA1_104" ofbase)|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" "$ALLCSV104")" set)" \
      "yesyesyes|yes|ok|notenote|0" \
  "7f4 …the pin's tool cleared every key after DEPLOY, before the copy; QF-G1 says the redeploy in capitals; the 6 s pause, then the baseline in QF-G1's one call, VN-G6's and OF-G1's beside it; every key unset after"
check "$(kv104 "$CA1_104" baseentries)|$(kv104 "$CA1_104" baserows)|$(kv104 "$CA1_104" ca3)|$(kv104 "$CA1_104" ca3base)|$(kv104 "$CA1_104" of1)|$(kv104 "$CA1_104" g6)" \
      "$(kv104 "$E1B104" entries)|$(cat "$A1_104.basecount" 2>/dev/null)|ok|$(kv104 "$E1B104" entries)|note|note" \
  "7f5 the earlier deployment's rows in the four tables and its four master_schedule.json entries, found and printed: the baseline's rows as this rehearsal counted the four tables at that moment, its entries as this rehearsal read them before the run — QF-CA3's line prints them as the baseline's; OF-G1 says the earlier offers in capitals, VN-G6 counts the history"
RC1_104="$(python3 "$H104/rh104q.py" claimat "$DATA/plugin.sqlite3" reminder "$MAXL1_104")"
check "$(cat "$ACT104/rem1.rc" 2>/dev/null)|$(qj "$(cat "$ACT104/rem1.out" 2>/dev/null)" ok 2>/dev/null)|$(tr ',' '\n' <<<"$RC1_104" | grep -c .)|$(cut -d: -f2 <<<"${RC1_104%%,*}")|$( [ "$(cut -d: -f1 <<<"${RC1_104%%,*}")" -gt "$(n103 "$(kv104 "$CA1_104" basemax)")" ] 2>/dev/null && echo above)|$( [ "$(cut -d: -f3 <<<"${RC1_104%%,*}")" -ge "$(n103 "$U1_104")" ] 2>/dev/null && echo after)|$(kv104 "$CA1_104" ca2)|$(kv104 "$CA1_104" ca2added | tr ',' '\n' | grep -cx 'record/reminder:1')" \
      "0|True|1|record|above|after|ok|1" \
  "7f6 the reminder run begun before the clear (its configuration read with the ledger on) recorded through the stand-in Evolution AFTER stage A's copy and after the baseline: one record row of kind reminder above the baseline's largest id, stamped at or after the copy's start — QF-CA2 reads PASS and prints it among what was added (record/reminder:1)"
SC1_104="$(python3 "$H104/rh104q.py" sched4 "$DATA/plugin.sqlite3")"; QCE1_104="$(tr ',' '\n' <<<"$(kv104 "$SC1_104" entries)" | sed -n 's/^quote_chase://p')"
check "$(cat "$ACT104/m1.rc" 2>/dev/null)|$( [ -f "$A1_104.master.begin" ] && echo dispatched)|$( [ "$(n103 "$QCE1_104")" -lt "$(n103 "$U1_104")" ] 2>/dev/null && echo before)|$( [ "$QCE1_104" != "$(tr ',' '\n' <<<"$(kv104 "$E1B104" entries)" | sed -n 's/^quote_chase://p')" ] && echo written)|$(tr ',' '\n' <<<"$(kv104 "$CA1_104" ca3now)" | grep -cx "quote_chase:$QCE1_104")|$( [ "$(n103 "$(tlt104 "$TL1_104" copied.1 3)")" -le "$(n103 "$(cat "$A1_104.master.at" 2>/dev/null)")" ] 2>/dev/null && echo aftercopy)" \
      "0|dispatched|before|written|1|aftercopy" \
  "7f7 the master cycle begun before the clear dispatched quote_chase AFTER the copy (let go once the first record read named the pin): its entry written then, its last_run the cycle's own start, before the copy's start — QF-CA3 reads PASS and prints it"
DC1_104="$(python3 "$H104/rh104q.py" claimat "$DATA/plugin.sqlite3" staff_list "$MAXL1_104")"
if [ "$D1R104" = ready ]; then
  check "$( [ -f "$A1_104.dl1.begin" ] && echo begun)|$(tr ',' '\n' <<<"$DC1_104" | grep -c .)|$(cut -d: -f2 <<<"${DC1_104%%,*}")|$( [ "$(cut -d: -f1 <<<"${DC1_104%%,*}")" -le "$(n103 "$(kv104 "$CA1_104" basemax)")" ] 2>/dev/null && echo below)|$( [ "$(cut -d: -f3 <<<"${DC1_104%%,*}")" -gt "$(n103 "$(tlt104 "$TL1_104" g4.2 3)")" ] 2>/dev/null && echo later)|$( [ "$(cut -d: -f3 <<<"${DC1_104%%,*}")" -lt "$(n103 "$(tlt104 "$TL1_104" base.1 2)")" ] 2>/dev/null && echo inpause)|$(python3 -I -c 'import sys; a, b = float(open(sys.argv[1]).read()), float(open(sys.argv[2]).read()); print("held3" if 3.0 <= b - a < 5.0 else "held:%.1f" % (b - a))' "$A1_104.lock.locked" "$A1_104.lock.released" 2>/dev/null)|$(kv104 "$CA1_104" ca2)" \
        "begun|1|claim|below|later|inpause|held3|ok" \
    "7f8 the daily-list run whose fresh read came before the clear, its claim's BEGIN IMMEDIATE begun before QF-G4 returned and kept waiting by a second connection holding the book's write lock 3 s (within the 5 s busy_timeout): it claimed in a later second than QF-G4's return, during QF-G1's pause — below the baseline's largest id and in its counts; QF-CA2 reads PASS"
else
  check "$D1R104" "ready" "7f8' the daily-list run reached its claim (its fresh read before the clear) — without it the lock-held case cannot be shown"
fi
check "$(cat "$A1_104.plant.offer" 2>/dev/null | cut -d: -f1)|$(kv104 "$CA1_104" ca2same)|$(kv104 "$CA1_104" ca2before)|$(kv104 "$CA1_104" ca2added | tr ',' '\n' | grep -cx 'claim/offer:1')|$(qj "$(cat "$A1_104.plant.entry" 2>/dev/null)" set 2>/dev/null)|$(tr ',' '\n' <<<"$(kv104 "$CA1_104" ca3now)" | grep -cx "ai_media:$U1_104")|$(kv104 "$CA1_104" of3)|$(kv104 "$CA1_104" of3claims)" \
      "wa_auto_sends|1|0|1|1|1|ok|1" \
  "7f9 in the copy's start second: an offer claim planted with that second as its claimed_at (raw — a state no writer makes) and an entry of the four (ai_media) with that second as its last_run (master's form, the store's save) — QF-CA2 counts the claim in that second and reads PASS, QF-CA3 prints the entry and reads PASS, and Part O's OF-CA3 counts the offer claim among those at or before that second and reads PASS"
check "$(kv104 "$CA1_104" vn4)|$(kv104 "$CA1_104" vn4ackssame)|$(kv104 "$CA1_104" vn4callssame)|$(grep -cx 'QF_G1=redeploy' "$STATE104")|$(sed -n 's/^QF_BASE=//p' "$STATE104" | tail -1 | grep -c "unix=$U1_104")|$(grep -lF -f "$NAMES104" "$R104/run-$T1R104.txt" "$OUT104"/*.log 2>/dev/null | grep -c .)|$(uother104 "$T1R104")|$(python3 "$H104/rh104q.py" tl "$R104/logs/tl-r1.txt" | tr ' ' '\n' | sed 's/=.*//' | paste -sd, -)|$(grep -c . "$R104/wait104.fail")" \
      "ok|0|0|1|1|0|-|g4.1,clear.1,g4.2,base.1,copied.1|0" \
  "7f10 …VN-CA4 reads PASS (nothing of Part V in a later second); the state file records the redeploy and its baseline, the copy's start the base call's own; no fixture name or phone in the terminal or the log; uCRM asked GETs only; the moments in D's order (stage A's QF-G4, the clear, QF-G4 again, the baseline, the copy); no wait of this rehearsal's timed out"
echo; echo "== 104-7p. Part P on the redeploy: it starts dark — the agent's copy keys among those the clear took, AC-ON1 OFF — not judged after it =="
check "$(noteline "$OUT7F104" QF-G4 "QUOTE_AGENT_CC_ENABLED" "QUOTE_AGENT_CC_SINCE")|$(python3 "$H104/rh104q.py" seckeys "$SH7F104" "QF-G4 the keys --clear-switches cleared (key names; no value)" "$SN1_104" | sed -E 's/ other=.*//')|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" quote_agent_cc_enabled,quote_agent_cc_since)" set)" \
      "yes|present lines=1 keys=$(tr ',' '\n' <<<"$SN1_104" | grep -c .)/$(tr ',' '\n' <<<"$SN1_104" | grep -c .) none=0|0" \
  "7p1 --redeploy, starting dark: QF-G4 named Part P's two keys among those set (quote_agent_cc_enabled, quote_agent_cc_since, in capitals); the sheet's section of the keys the clear took holds every key that was set, Part P's among them; both unset after the run"
SH7P104="$(sf104c show)"
check "$(sfrc104)|$(grep -cE '^  1b agent-cc \(optional, P7\): off(;|$)' <<<"$SH7P104")|$(grep -cE '^  (1b agent-cc \(optional, P7\)|[0-9]+ [a-z-]+( \(optional\))?): (on|skipped)' <<<"$SH7P104")|$(grep -cx '  the ledger: off' <<<"$SH7P104")" "0|1|0|1" \
  "7p2 the installed tool's show after the redeploy, as the operator runs it: agent-cc off, no step on or skipped, the ledger off — each step is switched on again with its check (Part P's on agent-cc then asks for step 1 and plugin mail again)"
aft104 "7p3 after the redeploy:"
check "$(noteline "$AFT104" AC-ON1 'OFF — not judged (agent-cc is off)')|$(noteline "$AFT104" QF-CA2 "QF-CA2 and QF-CA3's schedule rows judge the dark build in the deploy run")" "yes|yes" \
  "7p3' …AC-ON1 OFF — not judged; QF-CA2/QF-CA3's dark build a note in --after-only, as the deploy run alone judges it"
rbx104 "7p4"
echo; echo "== 104-7v. Part V's redeploy case: (a) the history (audio rows at each rung) and an acknowledgement event left pending, the dark build's worker records it switched_off — VN-G6 prints, VN-CA4 PASS; (b) a voice note and its acknowledgement between stage A's copy and the clear, an acknowledgement and a call held into the copy's start second — VN-CA4 PASS =="
# ── The earlier build's webhook: the pin's evo_webhook.php served on 127.0.0.1 from an actor's copy of the pin's tree, as the
# store's user, under the clock, exec disabled (its own guard: it spawns no worker), with a vault copy of its own; the token
# read through the pin's own EvoWebhookGuard by the live acts' helper, never printed. ──
WR104="$(pinroot104 wh)"; cp -p "$VAULT" "$ACT104/wh.vault.json" 2>/dev/null; chown 1000:1000 "$ACT104/wh.vault.json" 2>/dev/null; chmod 600 "$ACT104/wh.vault.json" 2>/dev/null
WHP7_104="$(free_port)"; WHU7_104="http://127.0.0.1:$WHP7_104/evo_webhook.php"
( cd "$ACT104" && exec setpriv --reuid 1000 --regid 1000 --clear-groups -- env DN_VAULT_FILE="$ACT104/wh.vault.json" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" "$PHPBIN" -d disable_functions=exec -S "127.0.0.1:$WHP7_104" -t "$WR104" >"$R104/logs/wh7.log" 2>&1 ) & WHPID7_104=$!; PIDS+=("$WHPID7_104")
for i in $(seq 1 50); do [ "$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "$WHU7_104")" = "405" ] && break; sleep 0.1; done
python3 "$H104/rh104q.py" ogg > "$SB/evo/rh104-media.b64"   # the stand-in Evolution's voice-note bytes, for the media worker's fetch (removed at the end)
vpost104() {   # vpost104 CHANNEL PHONE TAG — one voice note to the earlier build's webhook (the helper's audio payload)
  live104 webhook "$PINR104" "$DATA" "$WHU7_104" "{\"kind\":\"audio\",\"channel\":\"$1\",\"phone\":\"$2\",\"name\":\"RH104SECRET-Caller-$3\",\"text\":\"RH104SECRET-voice-$3\",\"id\":\"RH104V7$3\"}"
}
nullkeys104() { python3 -I -c 'import json, sys; print(json.dumps({k: None for k in sys.argv[1].split(",") if k}))' "$1"; }   # nullkeys104 NAMES — {name: null…}, the keys mode's unset
check "$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "$WHU7_104")|$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -d '{"event":"messages.upsert"}' "$WHU7_104")|$( [ -s "$SB/evo/rh104-media.b64" ] && echo media)" "405|401|media" \
  "7v control: the earlier build's webhook served on 127.0.0.1 from the pin's copy (exec disabled) — a GET refused, a POST without its token refused; the stand-in Evolution holds a voice note's bytes"
# ── Part V's stand-ins, for (a)'s rungs and for (b) (RH5-fix1: set up before (a), not in (b)): the release's fake OpenAI from
# Git on 127.0.0.1 (its answer an RH104SECRET- transcript); a fictitious key, in the media worker's COPY's data/config.json
# alone (uCRM's Configuration screen writes it there; the live plugin never holds one); three copies of the pin's tree — a
# reply worker's plain copy, a reply worker's copy whose lib/VoiceAck.php pauses after step 13's fresh read before its claim,
# and the media worker's copy whose lib/VoiceTranscription.php pauses after process's fresh read (H.3.7 step 3) before step
# 4's reservation (RH5-fix1, R02: MediaWorker's own call of process() comes before that fresh read, so a pause there let the
# read fall after the clear — process answered switched_off and reserved nothing). Each pause is inert while RH104_PAUSE is
# unset (the rungs below run the copy without it). The reply workers run only while the assistant reads unconfigured (no
# provider key, no external brain) — RH3's gate, read on both copies first. ──
OAI7_104="$ACT104/oai"; mkdir -p "$OAI7_104"; chown 1000:1000 "$OAI7_104"
git -C "$REPO" show "$P104:$P/tests/fixtures/fake_openai_server.php" > "$OAI7_104/fake_openai_server.php" 2>/dev/null
OAP7_104="$(free_port)"; OAU7_104="http://127.0.0.1:$OAP7_104"
( cd "$OAI7_104" && exec setpriv --reuid 1000 --regid 1000 --clear-groups -- env TMPDIR="$OAI7_104" "$PHPBIN" -S "127.0.0.1:$OAP7_104" -t "$OAI7_104" "$OAI7_104/fake_openai_server.php" >"$R104/logs/oai7.log" 2>&1 ) & OAPID7_104=$!; PIDS+=("$OAPID7_104")
for i in $(seq 1 50); do curl -s --noproxy '*' "$OAU7_104/__test/state" 2>/dev/null | grep -q FAKE-OPENAI-TEST && break; sleep 0.1; done
curl -s --noproxy '*' -X POST -d '{"status":200,"body":{"text":"RH104SECRET-the price of the kit please"}}' "$OAU7_104/__test/default" >/dev/null 2>&1
OAK7_104="RH104SECRET-sk-$(od -An -tx1 -N12 /dev/urandom | tr -d ' \n')"; printf '%s\n' "$OAK7_104" >> "$SB/secrets104.pat"
oa7calls104() { curl -s --noproxy '*' "$OAU7_104/__test/state" 2>/dev/null | python3 -I -c 'import json, sys
try:
    d = json.load(sys.stdin)
except ValueError:
    d = {}
print(sum(1 for x in d.get("calls", []) if x.get("path") == "/v1/audio/transcriptions"))' 2>/dev/null || echo unread; }
A3_104="$ACT104/r3"
VR1_104="$(pinroot104 r3w1)"; VR2_104="$(pinroot104 r3w2)"; VM3_104="$(pinroot104 r3m)"
pause104 "$VR2_104/lib/VoiceAck.php" "        \$c = \$ledger->claim(\$instance, 'voice_ack', \$ref);"
pause104 "$VM3_104/lib/VoiceTranscription.php" "        // 4. The budget, before the call."
RH104_OAK="$OAK7_104" python3 -I - "$VM3_104/data/config.json" <<'PY'   # the fictitious key, in the media worker's COPY alone (uCRM's Configuration screen writes it there)
import json, os, sys
p = sys.argv[1]
d = json.load(open(p, encoding='utf-8')) if os.path.exists(p) else {}
d['openai_api_key'] = os.environ['RH104_OAK']
json.dump(d, open(p, 'w', encoding='utf-8'))
PY
chown 1000:1000 "$VM3_104/data/config.json"; chmod 600 "$VM3_104/data/config.json"
G7A_104="$(live104 secrets "$VR1_104" "$DATA")"; G7B_104="$(live104 secrets "$VR2_104" "$DATA")"
GATE7_104=no; [ "$(qj "$G7A_104" claude 2>/dev/null)|$(qj "$G7A_104" openai 2>/dev/null)|$(qj "$G7A_104" shopbot 2>/dev/null)|$(qj "$G7A_104" brain 2>/dev/null)|$(qj "$G7B_104" openai 2>/dev/null)|$(qj "$G7B_104" brain 2>/dev/null)" = "unset|unset|unset|unconfigured|unset|unconfigured" ] && GATE7_104=yes
# The privacy line: MediaPolicy::voiceEnabled requires it (a date not after today) on every read — without it the media worker's
# mode is fetch_only and nothing is transcribed. A setting, never a switch: the installed tool's `set` writes it through
# PluginConfig::saveOverrides, the pin's writer of keys here (5.18.103 serves); `off` never clears it, so 7c16 unsets it again.
PLB7_104="$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" ai_media_voice_privacy_line)" set)"
PLW7_104="$(live104 keys "$PINR104" "$DATA" "{\"ai_media_voice_privacy_line\":\"$(kday104 "$(fnow104)")\"}" '["ai_media_voice_privacy_line"]')"
PLA7_104="$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" ai_media_voice_privacy_line)" set)"
check "$GATE7_104|$(oa7calls104)|$(grep -cF "$OAK7_104" "$VM3_104/data/config.json" 2>/dev/null)|$(grep -lF "$OAK7_104" "$PD/data/config.json" "$DATA/kyc_config.json" "$DATA/plugin.sqlite3" 2>/dev/null | grep -c .)|$(grep -c 'RH104_PAUSE' "$VR2_104/lib/VoiceAck.php" "$VM3_104/lib/VoiceTranscription.php" | cut -d: -f2 | paste -sd, -)|$(grep -rl 'RH104_PAUSE' "$PD" "$PINT104" "$VR1_104" 2>/dev/null | grep -c .)|$PLB7_104|$(qj "$PLW7_104" ok 2>/dev/null)|$PLA7_104" \
      "yes|0|1|0|1,1|0|0|True|1" \
  "7v0 Part V's stand-ins: the assistant unconfigured on both reply workers' copies (RH3's gate); the release's fake OpenAI on 127.0.0.1, called by nothing; the fictitious key in the media worker's copy alone, in no live file; one pause line in each paused COPY (the acknowledgement's and process's — RH104_PAUSE named twice on it), none in the installed plugin, the pin's tree or the plain copy; the privacy line unset (this rehearsal's reading), then recorded through the pin's writer of keys (the clock's Kampala day) and read set"
# ── (a): the history (audio rows at each rung, calls, acknowledgements, ai.media and ai.voice_ack events done and dead — a dead
# one made through EventBus's own failure path where the earlier runs left none). The rungs (RH5-fix1, R08: the book 104-6
# left holds audio rows measured at voice-measure only — 104-5v's rungs 7–9 ran on a copy that was put back): per rung that
# rung's switches through the pin's writer of keys (allon "only", cumulative — dry_run, then handover, then reply on the sales
# number with its owner's approval), one voice note to the earlier build's webhook 25 s on the clock, and the media worker's
# COPY (its pause inert) transcribing it through the fake OpenAI and settling it at that rung, as 104-5v drove the installed
# worker's seam; the reply rung's voice turn taken by the reply worker's plain COPY (R4 reads off on that copy's own fresh
# read — the key is the media worker copy's alone — so AiReplyWorker hands the turn to a person, never answered), so no
# ai.reply waits for the dark build; then every key unset through the same writer. Then P5 on through
# the pin's writer, a voice note to the earlier build's webhook queues its acknowledgement event, then the keys unset again,
# no hook run — the event left pending before stage A's copy, as the rollback's bounded drain can leave one; ai_enabled on. ──
VH0_104="$(python3 "$H104/rh104q.py" vhist "$DATA/plugin.sqlite3")"
for t in ai.media ai.voice_ack; do
  case ",$(kv104 "$VH0_104" "$( [ "$t" = ai.media ] && echo evmedia || echo evack)")," in *",dead:"*) ;; *) live104 deadev "$PINR104" "$DATA" "{\"type\":\"$t\"}" >/dev/null ;; esac
done
cnt104() { tr ',' '\n' <<<"$(kv104 "$1" "$2")" | awk -F: -v k="$3" '$1 == k { print $2 }' | head -1; }   # cnt104 READING FIELD NAME — NAME's count in that field's list (empty when absent)
dlt104() { echo $(( $(n103 "$(cnt104 "$2" "$3" "$4")") - $(n103 "$(cnt104 "$1" "$3" "$4")") )); }    # dlt104 BEFORE AFTER FIELD NAME — how many more NAME rows AFTER reads than BEFORE
python3 "$H104/rh104q.py" phones '[{"phone":"+256700104090"},{"phone":"+256700104091"},{"phone":"+256700104092"},{"phone":"+256700104093"}]' >> "$NAMES104"
rung104() {   # rung104 STEPS-JSON CHANNELS PHONE TAG — one rung of (a)'s history: STEPS on through the pin's writer (the rungs below
              # kept), a voice note on the sales number 25 s on the clock, the media worker's COPY once (no pause); one line each
  live104 allon "$PINR104" "$DATA" "{\"statement\":\"RH104SECRET-a cold statement of the rehearsal\",\"channels\":\"$2\",\"only\":$1}" >> "$R104/logs/rung7.json"; echo >> "$R104/logs/rung7.json"   # one answer a line: grep -c counts lines (trial 5: three answers on one line read 1)
  clk_to104 $(( $(fnow104) + 25 ))
  vpost104 sales "$3" "$4" >> "$R104/logs/rung7.json"; echo >> "$R104/logs/rung7.json"
  live104 mediaworker "$VM3_104" "$DATA" "{\"endpoint\":\"$OAU7_104/v1/audio/transcriptions\",\"max\":60}" >> "$R104/logs/rung7.json"; echo >> "$R104/logs/rung7.json"
}
: > "$R104/logs/rung7.json"; RG0_104="$(python3 "$H104/rh104q.py" vhist "$DATA/plugin.sqlite3")"; OAR0_104="$(oa7calls104)"
rung104 '["voice-measure","voice-dry-run"]' sales +256700104090 AD
rung104 '["voice-staff"]' sales +256700104091 AH
rung104 '["voice-reply"]' sales +256700104092 AR
if [ "$GATE7_104" = yes ]; then actor104 ra "" "$VR1_104/run_worker.php"; wfile104 "$ACT104/ra.rc" 120; else echo refused > "$ACT104/ra.rc"; fi
KUR_104="$(live104 keys "$PINR104" "$DATA" "$(nullkeys104 "$(setnames104)")" "$ALLK104")"
RG1_104="$(python3 "$H104/rh104q.py" vhist "$DATA/plugin.sqlite3")"
check "$(n103 "$(cnt104 "$RG0_104" evmedia pending)")|$(grep -c '"ok":true' "$R104/logs/rung7.json")|$(grep -c '"http":200' "$R104/logs/rung7.json")|$(for r in dry_run handover reply; do printf '%s,' "$(dlt104 "$RG0_104" "$RG1_104" rungs "$r/understood")"; done)|$(for r in dry_run handover reply; do printf '%s,' "$(dlt104 "$RG0_104" "$RG1_104" callrungs "$r/ok")"; done)|$(( $(n103 "$(oa7calls104)") - $(n103 "$OAR0_104") ))|$(cat "$ACT104/ra.rc" 2>/dev/null)|$(kv104 "$RG1_104" aireply | tr ',' '\n' | grep -cE '^(pending|processing):')|$(qj "$KUR_104" ok 2>/dev/null)|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" "$ALLCSV104")" set)" \
      "0|3|3|1,1,1,|1,1,1,|3|0|0|True|0" \
  "7v1r (a)'s rungs, by the earlier build's own acts (no ai.media pending before them): each rung's switches written through the pin's writer, each voice note 200 at the earlier build's webhook, one audio row more settled understood at each of dry_run, handover and reply and one OpenAI call more at each, ok (this rehearsal's reading, by voice_rung and status), the fake OpenAI called three times; the reply rung's voice turn taken by the reply worker's plain copy (no ai.reply left pending); every key unset again"
ALA_104="$(live104 allon "$PINR104" "$DATA" '{"statement":"RH104SECRET-a cold statement of the rehearsal","channels":"sales,support","only":["ack"]}')"
VPA_104="$(vpost104 sales +256700104062 A1)"; ACKA_104="$(ackleft104)"
KUA_104="$(live104 keys "$PINR104" "$DATA" "$(nullkeys104 "$(setnames104)")" "$ALLK104")"
VHA_104="$(python3 "$H104/rh104q.py" vhist "$DATA/plugin.sqlite3")"; SECA_104="$(live104 secrets "$PINR104" "$DATA")"
check "$(qj "$ALA_104" ok 2>/dev/null)|$(qj "$VPA_104" http 2>/dev/null)|$ACKA_104|$(qj "$KUA_104" ok 2>/dev/null)|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" "$ALLCSV104")" set)|$(qj "$SECA_104" ai_enabled 2>/dev/null)" \
      "True|200|1|True|0|on" \
  "7v1 (a)'s setting: P5 on through the pin's own writer of keys, a voice note to the earlier build's webhook queued its acknowledgement event — one ai.voice_ack pending — then every key unset through the same writer (no hook ran: the event stays pending, as the rollback's bounded drain can leave one); ai_enabled on"
check "$(for k in media evmedia evack; do v="$(kv104 "$VHA_104" "$k")"; [ "$v" != "-" ] && [ "$v" != absent ] && echo -n "$k "; done)|$(for r in fetch_only dry_run handover reply; do [ "$(n103 "$(cnt104 "$VHA_104" rungs "$r/understood")$(cnt104 "$VHA_104" rungs "$r/fetched")")" -ge 1 ] && echo -n "$r "; done)|$( [ "$(n103 "$(kv104 "$VHA_104" calls)")" -ge 1 ] && echo calls)|$( [ "$(kv104 "$VHA_104" acks)" != "-" ] && echo acks)|$(kv104 "$VHA_104" evmedia | tr ',' '\n' | grep -cE '^(done|dead):')|$(kv104 "$VHA_104" evack | tr ',' '\n' | grep -cE '^(done|dead|pending):')" \
      "media evmedia evack |fetch_only dry_run handover reply |calls|acks|2|3" \
  "7v1' …the voice history on the book (this rehearsal's own reading): audio rows at each rung — measured at voice-measure (fetched, 104-5's), understood at dry_run, handover and reply (7v1r's) — OpenAI calls, acknowledgement decisions, ai.media events done and dead, ai.voice_ack events done, dead and the one pending"
VMX_A104="$(maxid104 wa_voice_acks)"
cronv104_up() {   # the cron a server runs, for one redeploy: from the moment the container's record names the pin (the copy
                  # done) and P5's switch reads off, the INSTALLED reply worker (run_worker.php, as main.php starts it) as the
                  # book's owner under the clock, once a second, until no acknowledgement event is left or 900 s; never the media
                  # worker. Run in the main shell, never in $( ).
  : > "$R104/cronv104.on"
  ( n=0
    while [ -f "$R104/cronv104.on" ] && [ "$n" -lt 900 ]; do
      n=$((n + 1))
      if [ "$(live)" = "$P104" ] && ackoff104; then
        env PATH="$SB/bin:$PATH" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" bash "$SB/bin/docker" exec -u "$(owner104)" -e "DN_DATA_DIR=$PDD104_IN" ucrm \
          php -d log_errors=0 "$IN_CONTAINER/run_worker.php" </dev/null >> "$R104/logs/cronv104.out" 2>&1
        [ "$(ackleft104)" = "0" ] && break
      fi
      sleep 1
    done ) &
  CVPID104=$!; PIDS+=("$CVPID104")
}
cronv104_down() { rm -f "$R104/cronv104.on"; [ -z "${CVPID104:-}" ] || wait "$CVPID104" 2>/dev/null; CVPID104=""; }
tl_arm104 copied.1.after
o_r2() { local d="$SB/.tl104" i; wfile104 "$d/copied.1.end" 900 || return; for i in $(seq 1 240); do [ "$(ackleft104)" = "0" ] && break; sleep 0.5; done; [ "$(ackleft104)" = "0" ] || echo "wait104:ackdrain-r2" >> "$R104/wait104.fail"; : > "$d/copied.1.release"; }
o_r2 > "$R104/logs/orch-r2.txt" 2>&1 & OP2_104=$!
EV2_104="$(evo_calls)"; cronv104_up
fresh104; OUT="$(run104c --answer DEPLOY --redeploy)"; T2R104="$(last104)"
cronv104_down; kill "$OP2_104" 2>/dev/null; wait "$OP2_104" 2>/dev/null; tl_disarm104 r2
CA2_104="$(cav104 "$OUT" r2)"
check "$(fset104 "$OUT")|$(has "$OUT" "  $V104 (deploy): PASSED.")|$(live)|$(installed104 "$P104")|$(kv104 "$CA2_104" g6)|$(kv104 "$CA2_104" g6media)|$(kv104 "$CA2_104" g6ack)|$(grep -c '^  STOP: NO-GO (VN-G6)' <<<"$OUT")" \
      ":|yes|$P104|0|note|$(kv104 "$VHA_104" evmedia | tr ',' '\n' | awk -F: '{s += $2} END {print s + 0}')|$(kv104 "$VHA_104" evack | tr ',' '\n' | awk -F: '{s += $2} END {print s + 0}')|0" \
  "7v2 (a) --redeploy, DEPLOY typed: PASSED, $P104 serving — VN-G6 prints the earlier deployment's events, by status, as history (ai.media and ai.voice_ack, as many as this rehearsal counts), never NO-GO"
check "$(kv104 "$CA2_104" vn4)|$(kv104 "$CA2_104" vn4dec)|$(kv104 "$CA2_104" vn4rows)|$(kv104 "$CA2_104" vn4ev)|$(ackleft104)|$(python3 -I -c 'import sqlite3, sys
c = sqlite3.connect("file:" + sys.argv[1] + "?mode=ro", uri=True)
print(",".join("%s/%s" % (r[0], r[1] or "-") for r in c.execute("SELECT outcome, reason FROM wa_voice_acks WHERE id > ? ORDER BY id", (int(sys.argv[2]),))))' "$DATA/plugin.sqlite3" "$VMX_A104")|$(( $(evo_calls) - EV2_104 ))" \
      "ok|skipped/switched_off:1|0,0,1|0,0|0|skipped/switched_off|0" \
  "7v3 (a) …the dark build's reply worker (the installed run_worker.php, run as cron runs it, once $P104 served) recorded the pending event switched_off, before VN-CA4's copy: VN-CA4 reads PASS and prints that decision (skipped/switched_off:1) and the earlier rows as history; no acknowledgement sent — Evolution not called"
rbx104 "7v4"
# ── The weakened copy of VN-G6 (the first of Part V's four): its redeploy branch taken out — the first deploy's rule then reads
# (a)'s history as an unreviewed pipeline's and refuses. Stage A alone (--check), 5.18.103 serving again; the unweakened
# --check its control: GO, VN-G6 the history's note. ──
printf '%s\t%s\t%s\t%s\n' vng6r deploy "    note \"VN-G6 a redeploy: an earlier deployment's events are history, never a fault" \
  "    [ \"\$(qf_kv \"\$o\" media)|\$(qf_kv \"\$o\" ack)\" = \"0|0\" ] || { cih_drop \"\$sub\"; stop \"NO-GO (VN-G6): a book \$EXPECTED_VERSION never ran on holds ai.media or ai.voice_ack events (\${o#events=present }) — an unreviewed media pipeline wrote them. Nothing was changed. Send the log file\"; }; : \"VN-G6 a redeploy: an earlier deployment's events are history, never a fault" >> "$ANCH104"
D7V5_104="$(data94)"; T7V5_104="$(tables104 | cut -d' ' -f1-2)"
fresh104; OUT="$(run104c --check)"
check "$(checkgo104 "$OUT" | cut -d'|' -f1)|$(noteline "$OUT" VN-G6 "a redeploy: an earlier deployment's events are history, never a fault")" "yesyesnono0|yes" \
  "7v5 control: --check on (a)'s book, 5.18.103 serving: GO, VN-G6 printing the history as a note"
fresh104; M="$(weak104 vng6r vng6r)"; OUT="$(R104_MUTANT_OUT=1 run104c --script "$M" --check)"; rm -f "${M:?}"
nogo104 "7v5' VN-G6 weakened — its redeploy branch taken out: NO-GO over (a)'s history (caught), nothing changed" \
  "NO-GO (VN-G6): a book $V104 never ran on holds ai.media or ai.voice_ack events (" "$OUT" "$D7V5_104" "$T7V5_104"
# ── (b): over the earlier build with the voice steps on, --redeploy --clear-switches. Every part's keys but voice-reply's
# (step 9: while R4 answers a number, an acknowledgement is never claimed — VoiceAck's step 13 skips it p4_answers — so (b)'s
# held acknowledgement needs step 9 off; said here as a deviation), channels sales and support; the privacy line recorded
# (7v0), the fictitious key in the media worker's COPY alone, the transcriber the release's fake on 127.0.0.1 through the
# worker's own seam. Between stage A's copy and the clear (the clear held): a voice note on the support number, its
# acknowledgement by the reply worker's plain COPY and its transcription by the media worker's COPY with no pause (so that the
# held call below is the only one the held worker has to make — RH5-fix1: one run of MediaWorker takes every pending ai.media
# event, and a second call after the release could fall in a later second); then a voice note on the sales number (vheld104),
# whose acknowledgement the paused COPY of lib/VoiceAck.php holds after step 13's fresh read, before its claim, and whose call
# the paused COPY of lib/VoiceTranscription.php holds after process's fresh read (H.3.7 step 3: voiceEnabled on the
# configuration read again, before the clear) and before step 4's reservation. The clear's ack hook then waits its bound for
# the held acknowledgement; both are let go after the baseline, so the claim and the reservation fall in the copy's start
# second. The moment the clear is let go is recorded (cleargo): "before the clear" is read against it. The reply workers run
# only while the assistant reads unconfigured (RH3's gate, 7v0). ──
vheld104() {   # vheld104 MARKER TAG PHONE — inside a held clear: a voice note on the sales number to the earlier build's webhook;
               # its acknowledgement by the reply worker's COPY whose lib/VoiceAck.php waits at MARKER.ack (after step 13's
               # read, before its claim), its call by the media worker's COPY whose lib/VoiceTranscription.php waits at
               # MARKER.call (after process's fresh read, before the reservation); back once both wait (actors TAGw, TAGvm)
  vpost104 sales "$3" "$2" > "$1.note2"
  [ "$GATE7_104" = yes ] && { actor104 "${2}w" "$1.ack" "$VR2_104/run_worker.php"; wfile104 "$1.ack.ready" 90; }
  actor104 "${2}vm" "$1.call" "$H104/rh104-live.php" mediaworker "$VM3_104" "$DATA" "{\"endpoint\":\"$OAU7_104/v1/audio/transcriptions\",\"max\":60}"
  wfile104 "$1.call.ready" 90
}
ALB_104="$(live104 allon "$PINR104" "$DATA" '{"statement":"RH104SECRET-a cold statement of the rehearsal","channels":"sales,support","except":["voice-reply"]}')"
SN3_104="$(setnames104)"; V3W3_104="$(v3gw104 "$SN3_104")"; OAB0_104="$(oa7calls104)"
check "$GATE7_104|$(qj "$ALB_104" ok 2>/dev/null)|$(grep -c 'RH104_PAUSE' "$VR2_104/lib/VoiceAck.php" "$VM3_104/lib/VoiceTranscription.php" | cut -d: -f2 | paste -sd, -)|$(grep -lF "$OAK7_104" "$PD/data/config.json" "$DATA/kyc_config.json" 2>/dev/null | grep -c .)|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" ai_media_voice_privacy_line)" set)|$(grep -c . "$R104/wait104.fail")" \
      "yes|True|1,1|0|1|0" \
  "7v6 (b)'s setting: the assistant unconfigured on both reply workers' copies (RH3's gate); every part's keys but voice-reply's set through the pin's writer; one pause line in each paused COPY (lib/VoiceAck.php's and lib/VoiceTranscription.php's); the fictitious OpenAI key in no live file; the privacy line still recorded; no wait timed out"
MXA3_104="$(maxid104 wa_auto_sends)"; MXC3_104="$(maxid104 wa_voice_calls)"; MXV3_104="$(maxid104 wa_voice_acks)"
o_r3() {   # R3's moments: the clear held while the earlier build makes a note, its acknowledgement and its transcription, a
           # second note, and holds an acknowledgement and a call; the clear let go (cleargo); both let go after the baseline
  local d="$SB/.tl104" a="$A3_104"
  wfile104 "$d/clear.1.start" 600 || return
  vpost104 support +256700104063 B1 > "$a.note1"
  [ "$GATE7_104" = yes ] && { actor104 w1 "" "$VR1_104/run_worker.php"; wfile104 "$ACT104/w1.rc" 120; }
  actor104 vm1 "" "$H104/rh104-live.php" mediaworker "$VM3_104" "$DATA" "{\"endpoint\":\"$OAU7_104/v1/audio/transcriptions\",\"max\":60}"; wfile104 "$ACT104/vm1.rc" 120
  vheld104 "$a" B2 +256700104064
  fnow104 > "$a.cleargo"; : > "$d/clear.1.go"
  wfile104 "$d/base.1.end" 900 || return
  : > "$a.ack.go"; : > "$a.call.go"
  wfile104 "$ACT104/B2w.rc" 90; wfile104 "$ACT104/B2vm.rc" 90
  : > "$d/base.1.release"
}
tl_arm104 clear.1.hold base.1.after base.1.align
o_r3 > "$R104/logs/orch-r3.txt" 2>&1 & OP3_104=$!
EV3_104="$(evo_calls)"
fresh104; OUT="$(run104c --answer DEPLOY --redeploy --clear-switches)"; T3R104="$(last104)"
kill "$OP3_104" 2>/dev/null; wait "$OP3_104" 2>/dev/null; golet104 "$A3_104.ack" "$A3_104.call"; actwait104
TL3_104="$(python3 "$H104/rh104q.py" tl "$SB/.tl104/timeline")"; U3_104="$(tlu104 base.1)"; tl_disarm104 r3
CA3_104="$(cav104 "$OUT" r3)"
VA3_104="$(python3 "$H104/rh104q.py" claimat "$DATA/plugin.sqlite3" voice_ack "$MXA3_104")"; VC3_104="$(python3 "$H104/rh104q.py" callsat "$DATA/plugin.sqlite3" "$MXC3_104")"
CS3_104="$(n103 "$(tlt104 "$TL3_104" clear.1 2)")"; CG3_104="$(n103 "$(cat "$A3_104.cleargo" 2>/dev/null)")"
check "$(fset104 "$OUT")|$(live)|$(installed104 "$P104")|$(v3gg104 "$OUT")|$(qj "$(cat "$A3_104.note1" 2>/dev/null)" http 2>/dev/null)|$(qj "$(cat "$A3_104.note2" 2>/dev/null)" http 2>/dev/null)|$(cat "$ACT104/w1.rc" 2>/dev/null)|$(cat "$ACT104/vm1.rc" 2>/dev/null)" \
      ":|$P104|0|$V3W3_104|200|200|0|0" \
  "7v7 (b) --redeploy --clear-switches, DEPLOY typed: $P104 serving; no FAIL — V3g's note names exactly the ai_ keys the clear took (DS5, S2); between stage A's copy and the clear, a voice note on each number reached the earlier build's webhook, the reply worker's plain copy ran and the media worker's copy transcribed the first"
check "$(tr ',' '\n' <<<"$VA3_104" | awk -F: -v s="$CS3_104" -v g="$CG3_104" '$2 == "claim" && $3 + 0 >= s + 0 && $3 + 0 <= g + 0' | grep -c .)|$( [ -f "$A3_104.ack.begin" ] && echo held)|$(tr ',' '\n' <<<"$VA3_104" | awk -F: -v u="$(n103 "$U3_104")" '$2 == "claim" && $3 + 0 == u + 0' | grep -c .)|$( [ -f "$A3_104.call.begin" ] && echo held)|$(tr ',' '\n' <<<"$VC3_104" | awk -F: -v u="$(n103 "$U3_104")" '$2 + 0 == u + 0' | grep -c .)|$(tr ',' '\n' <<<"$VC3_104" | awk -F: -v s="$CS3_104" -v g="$CG3_104" '$2 + 0 >= s + 0 && $2 + 0 <= g + 0' | grep -c .)|$( [ "$CS3_104" -ge 1 ] && [ "$CG3_104" -ge "$CS3_104" ] && [ "$(n103 "$U3_104")" -gt "$CG3_104" ] && echo ordered)|$(( $(n103 "$(oa7calls104)") - $(n103 "$OAB0_104") ))" \
      "1|held|1|held|1|1|ordered|2" \
  "7v8 (b) …the first note's acknowledgement claimed and its call reserved between the clear's start and the moment it was let go (before the clear ran, after stage A's copy); the second's acknowledgement, its step-13 read before the clear, held before its claim until the ack hook's bounded drain had ended, claimed in the copy's start second; its call, process's fresh read before the clear, held before its reservation, reserved in that second — the moments in that order (the clear's start, its release, the copy's start), the fake OpenAI called twice in (b)"
check "$(kv104 "$CA3_104" vn4)|$(kv104 "$CA3_104" vn4ackssame)|$(kv104 "$CA3_104" vn4callssame)|$( [ "$(n103 "$(kv104 "$CA3_104" vn4rows | cut -d, -f2)")" -ge 1 ] && echo calls)|$(grep -c . "$R104/wait104.fail")|$(grep -lF -f "$NAMES104" "$R104/run-$T3R104.txt" "$OUT104"/*.log 2>/dev/null | grep -c .)|$(grep -lF "$OAK7_104" "$R104/run-$T3R104.txt" "$OUT104"/*.log "$PD/data/config.json" 2>/dev/null | grep -c .)" \
      "ok|1|1|calls|0|0|0" \
  "7v9 (b) VN-CA4 reads PASS with the claim and the reservation counted in the copy's start second and printed; no wait timed out; no fixture name or phone, and no OpenAI key, in the terminal, the log or the live files"
rbx104 "7v10"
echo; echo "== 104-7o. Part O on the redeploy: what moved after correct acts — an offer running at the clear, a test held by a slow Evolution past the copy's start, a list claim in the copy's start second; and, in a run of its own (one live offer, O-D1), an admin's Start held before its BEGIN IMMEDIATE — OF-CA3 and QF-CA2 read PASS =="
# ── The stand-in Evolution learns to hold one send (an asserted insertion before its first line): while rh104-slow is beside
# its router, a sendText touches rh104-slow.in and waits — at most 30 s, the release's offer gateway gives up at 15 — for
# rh104-slow.go, then is answered as before (rh104-slow spent). ──
python3 -I - "$SB/evo/router.php" <<'PY' || echo "evo:slow104" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]; s = open(p).read()
old = "file_put_contents(__DIR__ . '/requests.log', $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . \"\\n\", FILE_APPEND);\n"
assert s.count(old) == 1 and s.count('rh104-slow') == 0, (s.count(old), s.count('rh104-slow'))
new = ("if (is_file(__DIR__ . '/rh104-slow') && strpos($_SERVER['REQUEST_URI'], '/message/sendText/') !== false) {   // 5.18.104's rehearsal (RH5): one send held\n"
       "    @unlink(__DIR__ . '/rh104-slow'); @touch(__DIR__ . '/rh104-slow.in'); $t104 = microtime(true);\n"
       "    while (!is_file(__DIR__ . '/rh104-slow.go') && microtime(true) - $t104 < 30) usleep(50000);\n"
       "}\n" + old)
s2 = s.replace(old, new, 1)
assert s2.count(old) == 1 and s2.count("'/rh104-slow'") == 2 and s2.index('rh104-slow') < s2.index(old)
open(p, 'w').write(s2)
PY
for i in $(seq 1 30); do curl -s --noproxy '*' -o /dev/null "$EVO_URL/chat/rh104-probe" 2>/dev/null; grep -q 'rh104-slow' "$SB/evo/router.php" && break; done
EVP104="/${EVO_URL#http://*/}"   # the stand-in Evolution's base path, as its log records a request
EVS4_104="$(evo_calls)"; : > "$SB/evo/rh104-slow"
( curl -s --noproxy '*' -o /dev/null -X POST -H 'Content-Type: application/json' -d '{"number":"256700104069","text":"RH104SECRET-control"}' "$EVO_URL/message/sendText/rh-support" ) & SLC104=$!
wfile104 "$SB/evo/rh104-slow.in" 20; SLH104="$( kill -0 "$SLC104" 2>/dev/null && echo held || echo answered)"
: > "$SB/evo/rh104-slow.go"; wait "$SLC104"
check "$SLH104|$( [ -e "$SB/evo/rh104-slow" ] && echo armed || echo spent)|$(tail -n +"$(( $(n103 "$EVS4_104") + 1 ))" "$SB/evo/requests.log" 2>/dev/null | paste -sd, -)|$(grep -c 'RH104SECRET\|256700' "$SB/evo/requests.log" 2>/dev/null)" \
      "held|spent|POST $EVP104/message/sendText/rh-support|0" \
  "7o control: the stand-in Evolution holds one send while this rehearsal's file is beside it — held until let go, then answered and recorded once, on its release (the hold sits before the router's logging line): the one new line of its log is that POST, by method and path alone; the file spent"
rm -f "$SB/evo/rh104-slow.in" "$SB/evo/rh104-slow.go"
liveoff104() { python3 "$H104/rh104q.py" liveoffers "$DATA/plugin.sqlite3"; }   # the offers holding the one live slot ("<id>:<state>" or -), and how many the table holds
offstop104() {   # offstop104 LABEL ID — the offer ID, holding the one live slot (091, O-D1: running or paused), stopped through the release's
                 # own writer — OfferStore::stop as the screen's offer_stop handler calls it, by the rehearsal's admin; the book read
                 # before (ID paused there: the reading's positive control) and after (the slot free, every offer still counted)
  local b a r
  b="$(liveoff104)"; r="$(live104 offerstop "$PINR104" "$DATA" "{\"offer\":$(n103 "$2")}")"; a="$(liveoff104)"
  check "$(kv104 "$b" live)|$(qj "$r" ok 2>/dev/null)|$(kv104 "$a" live)|$( [ "$(n103 "$(kv104 "$a" n)")" -ge 1 ] && [ "$(kv104 "$a" n)" = "$(kv104 "$b" n)" ] && echo counted)" \
        "$(n103 "$2"):paused|True|-|counted" \
    "$1 the one live slot (091: an offer running or paused holds it, and OfferStore::start answers another_live while it is taken — O-D1) freed through the release's own writer: the offer the earlier case left paused read so on the book (this rehearsal's reading), stopped as the admin stops it on the screen (OfferStore::stop), no offer live after it, every offer still counted"
}
# ── R4: the next Monday–Saturday 08:30 Kampala (a new day for the list); 104-6's offer, paused by the rollback (6e') with its
# live slot kept by every hook since, stopped first through the release's writer (RH5-fix1, R01); every part's keys through
# the pin's writer; warm conversations for an audience (the pin's ConversationService, as warm earlier); offer #1 made, tested
# and Started — running at the clear; offer #2 made and tested (the pin's OfferStore and OfferSender, as the screen's handlers
# make them, on the support number), a draft; a daily-list run begun before the clear, held at its injected pause in a COPY.
# The admin's Start of offer #2 is R4b's, a run of its own: with offer #1 holding the live slot (paused by the clear, still
# live), a Start of offer #2 after the baseline could only answer another_live. ──
offstop104 "7o0" "$OF6_104"
clk_to104 "$(kday_next104 08:30)"
AL4_104="$(live104 allon "$PINR104" "$DATA" '{"statement":"RH104SECRET-a cold statement of the rehearsal","channels":"sales,support"}')"
clk_to104 $(( $(fnow104) + 60 ))   # offer #1's Start later than allon's offers_live_since (O-D19's <=; as 5o9, trial 5)
SN4_104="$(setnames104)"; V3W4_104="$(v3gw104 "$SN4_104")"
WM4_104="$(live104 warm "$PINR104" "$DATA" '{"convs":[{"phone":"+256700104065","name":"RH104SECRET-Warm-65","channel":"sales","text":"RH104SECRET-is the kit in stock","reply":"RH104SECRET-yes it is","ago_min":200,"reply_after_min":5},{"phone":"+256700104066","name":"RH104SECRET-Warm-66","channel":"support","text":"RH104SECRET-my dish is fine now","reply":"RH104SECRET-good to hear","ago_min":300,"reply_after_min":4},{"phone":"+256700104067","name":"RH104SECRET-Warm-67","channel":"sales","text":"RH104SECRET-what does it cost","reply":"RH104SECRET-a quote is coming","ago_min":400,"reply_after_min":6}]}')"
OP41_104="$(live104 offerprep "$PINR104" "$DATA" '{"title":"RH104SECRET-offer seven-a","w1":"RH104SECRET-wording one seven-a","w2":"RH104SECRET-wording two seven-a","channel":"support"}')"
O41_104="$(qj "$OP41_104" offer 2>/dev/null)"; OS41_104="$(live104 offerstart "$PINR104" "$DATA" "{\"offer\":${O41_104:-0}}")"
OP42_104="$(live104 offerprep "$PINR104" "$DATA" '{"title":"RH104SECRET-offer seven-b","w1":"RH104SECRET-wording one seven-b","w2":"RH104SECRET-wording two seven-b","channel":"support"}')"
O42_104="$(qj "$OP42_104" offer 2>/dev/null)"
check "$(qj "$AL4_104" ok 2>/dev/null)|$(qj "$WM4_104" warm 2>/dev/null)|$(qj "$OP41_104" tests 2>/dev/null)|$(qj "$OS41_104" ok 2>/dev/null)|$(qj "$OP42_104" tests 2>/dev/null)|$(python3 "$H104/rh104q.py" offer "$DATA/plugin.sqlite3" "${O41_104:-0}" 2>/dev/null | grep -o 'state=[a-z]*')|$(python3 "$H104/rh104q.py" offer "$DATA/plugin.sqlite3" "${O42_104:-0}" 2>/dev/null | grep -o 'state=[a-z]*')|$(kv104 "$(liveoff104)" live)" \
      "True|3|sent,sent|True|sent,sent|state=running|state=draft|$(n103 "$O41_104"):running" \
  "7o1 R4's setting: every part's keys set; three warm conversations; offer #1 made, its two wordings tested and Started — running, the one live offer; offer #2 made and tested, a draft — each through the pin's own writers"
A4_104="$ACT104/r4"; MXA4_104="$(maxid104 wa_auto_sends)"; MXT4_104="$(maxid104 wa_offer_tests)"; MXE4_104="$(maxid104 wa_offer_events)"
DR4_104="$(pinroot104 r4d)"; pause104 "$DR4_104/lib/QuoteDailyList.php" "        \$cl = \$ledger->claim(\$inst, 'staff_list', \$ref);"
actor104 dl2 "$A4_104.dl2" "$DR4_104/cron/quote_daily_list.php"
D2R104="$(dlwait104 "$A4_104.dl2")"
check "$D2R104|$(grep -c 'RH104_PAUSE' "$DR4_104/lib/QuoteDailyList.php")" "ready|1" \
  "7o2 before the run: the daily-list run's fresh read made, held before its claim — its pause in its COPY alone (one line)"
o_r4() {   # R4's moments: just before the clear a test of offer #2 pressed, its send held by the stand-in; the clear let go once the
           # send waits (cleargo recorded); after the baseline, in the copy's start second, the list claim and the test's answer
  local d="$SB/.tl104" a="$A4_104"
  wfile104 "$d/clear.1.start" 600 || return
  : > "$SB/evo/rh104-slow"
  actor104 t4 "" "$H104/rh104-live.php" offertest "$PINR104" "$DATA" "{\"offer\":${O42_104:-0},\"variant\":1,\"channel\":\"support\"}"
  wfile104 "$SB/evo/rh104-slow.in" 30
  fnow104 > "$a.cleargo"; : > "$d/clear.1.go"
  wfile104 "$d/base.1.end" 900 || return
  : > "$a.dl2.go"; : > "$SB/evo/rh104-slow.go"
  wfile104 "$ACT104/dl2.rc" 90; wfile104 "$ACT104/t4.rc" 90
  : > "$d/base.1.release"
}
tl_arm104 clear.1.hold base.1.after base.1.align
o_r4 > "$R104/logs/orch-r4.txt" 2>&1 & OP4_104=$!
fresh104; OUT="$(run104c --answer DEPLOY --redeploy --clear-switches)"; T4R104="$(last104)"
kill "$OP4_104" 2>/dev/null; wait "$OP4_104" 2>/dev/null; golet104 "$A4_104.dl2"; : > "$SB/evo/rh104-slow.go"; actwait104
TL4_104="$(python3 "$H104/rh104q.py" tl "$SB/.tl104/timeline")"; U4_104="$(tlu104 base.1)"; tl_disarm104 r4
rm -f "$SB/evo/rh104-slow" "$SB/evo/rh104-slow.in" "$SB/evo/rh104-slow.go"
CA4_104="$(cav104 "$OUT" r4)"
OT4_104="$(python3 -I -c 'import sqlite3, sys
c = sqlite3.connect("file:" + sys.argv[1] + "?mode=ro", uri=True)
r = c.execute("SELECT id, state, CAST(strftime(?, started_at) AS INTEGER), CAST(strftime(?, settled_at) AS INTEGER) FROM wa_offer_tests WHERE id > ? ORDER BY id", ("%s", "%s", int(sys.argv[2]))).fetchall()
e = c.execute("SELECT COUNT(*), MIN(CAST(strftime(?, at) AS INTEGER)), MAX(CAST(strftime(?, at) AS INTEGER)) FROM wa_offer_events WHERE id > ? AND action = ?", ("%s", "%s", int(sys.argv[3]), "paused_by_rollback")).fetchone()
print("tests=%s rb=%d:%s:%s" % (",".join("%d:%s:%s:%s" % x for x in r) or "-", e[0], e[1], e[2]))' "$DATA/plugin.sqlite3" "$MXT4_104" "$MXE4_104")"
DC4_104="$(python3 "$H104/rh104q.py" claimat "$DATA/plugin.sqlite3" staff_list "$MXA4_104")"
CG4_104="$(n103 "$(cat "$A4_104.cleargo" 2>/dev/null)")"
check "$(fset104 "$OUT")|$(live)|$(installed104 "$P104")|$(v3gg104 "$OUT")|$(kv104 "$CA4_104" of3)|$(kv104 "$CA4_104" ca2)" ":|$P104|0|$V3W4_104|ok|ok" \
  "7o3 R4 --redeploy --clear-switches, DEPLOY typed: $P104 serving; no FAIL — V3g's note names the ai_ keys the clear took (DS5, S2); OF-CA3 and QF-CA2 read PASS"
check "$(sed -nE 's/^tests=([0-9]+):([a-z]+):([0-9]+):([0-9]+).*/\2/p' <<<"$OT4_104")|$( [ "$(sed -nE 's/^tests=[0-9]+:[a-z]+:([0-9]+):.*/\1/p' <<<"$OT4_104")" -le "$CG4_104" ] 2>/dev/null && [ "$CG4_104" -ge 1 ] && echo pressed)|$( [ "$(sed -nE 's/^tests=[0-9]+:[a-z]+:[0-9]+:([0-9]+).*/\1/p' <<<"$OT4_104")" -ge "$(n103 "$U4_104")" ] 2>/dev/null && echo settled)|$( [ "$(n103 "$(kv104 "$CA4_104" of3testrows)")" -ge 1 ] && echo recorded)" \
      "sent|pressed|settled|recorded" \
  "7o4 the test pressed just before the clear, held by the slow stand-in Evolution until after the copy's start, then recorded and settled: its row started at or before the moment the clear was let go, settled at or after the copy's start; its ledger row (offer_test:<id>) added after the baseline and counted, never FAIL"
check "$(sed -nE 's/.* rb=([0-9]+):([0-9]+):.*/\1/p' <<<"$OT4_104")|$( [ "$(sed -nE 's/.* rb=[0-9]+:([0-9]+):.*/\1/p' <<<"$OT4_104")" -ge "$(n103 "$(tlt104 "$TL4_104" clear.1 2)")" ] 2>/dev/null && [ "$(n103 "$(tlt104 "$TL4_104" clear.1 2)")" -ge 1 ] && echo inclear)|$(kv104 "$CA4_104" of3events | tr ',' '\n' | grep -c '^paused_by_rollback:')|$(cnt104 "$CA4_104" of3offers paused)|$(n103 "$(cnt104 "$CA4_104" of3offers running)")|$(kv104 "$(liveoff104)" live)" \
      "1|inclear|0|1|0|$(n103 "$O41_104"):paused" \
  "7o5 offer #1, running at the clear, paused by Part O's hook in it (one paused_by_rollback event, stamped at or after the clear's start — after stage A's copy), still holding the live slot: OF-CA3 counts NO event added — its baseline is read after QF-G4's clear (root docs/73 §H.4.18, Q18-O03: stage A's copy is not that baseline, because of exactly this event), so the hook's event is history (R4b's Start, held past the baseline, is the one it counts: 7o12) — and prints the offers by state, one paused and none running"
if [ "$D2R104" = ready ]; then
  check "$( [ -f "$A4_104.dl2.begin" ] && echo held)|$(tr ',' '\n' <<<"$DC4_104" | awk -F: -v u="$(n103 "$U4_104")" '$2 == "claim" && $3 + 0 == u + 0' | grep -c .)|$(kv104 "$CA4_104" ca2same | awk '{print ($1 >= 1 ? "same" : "none")}')|$(kv104 "$CA4_104" ca2added | tr ',' '\n' | grep -c '^claim/staff_list:')" \
        "held|1|same|1" \
    "7o6 in the copy's start second (Q19-F01): a list claim by the daily-list run whose fresh read came before the clear, held before its BEGIN IMMEDIATE and let go after the baseline — claimed in that second, counted and printed by QF-CA2, which reads PASS"
else
  check "$D2R104" "ready" "7o6' the second daily-list run reached its claim (its fresh read before the clear) — without it the copy's start second's list claim cannot be shown"
fi
check "$(grep -c . "$R104/wait104.fail")|$(grep -lF -f "$NAMES104" "$R104/run-$T4R104.txt" "$OUT104"/*.log 2>/dev/null | grep -c .)|$(uother104 "$T4R104")" "0|0|-" \
  "7o7 …no wait of this rehearsal's timed out; no fixture name or phone in the terminal or the log; uCRM asked GETs only"
rbx104 "7o8"
# ── R4b (RH5-fix1, R01): the admin's Start, in a run of its own. Migration 091 holds one live offer — live_slot = 1 while an
# offer is running OR paused, a unique index on it — so OfferStore::start answers another_live while the slot is taken, and
# the off-hooks pause an offer with its slot kept. D's Q18-O03 case puts an offer running at the clear (paused by P6's hook,
# still live) and a successful Start of another offer in one redeploy, which O-D1 forbids: a design finding, recorded in the
# notes. So offer #1 is stopped through the writer first, and --redeploy --clear-switches runs with every part's keys set and
# no offer live: the admin's Start of offer #2, its request's configuration read before the run (and so before the clear),
# held by the injected pause in a COPY of lib/OfferStore.php before its BEGIN IMMEDIATE until after the baseline. ──
offstop104 "7o9" "$O41_104"
AL4B_104="$(live104 allon "$PINR104" "$DATA" '{"statement":"RH104SECRET-a cold statement of the rehearsal","channels":"sales,support"}')"
SN4B_104="$(setnames104)"; V3W4B_104="$(v3gw104 "$SN4B_104")"
A4B_104="$ACT104/r4b"; MXE4B_104="$(maxid104 wa_offer_events)"; MXR4B_104="$(maxid104 wa_offer_recipients)"
SR4_104="$(pinroot104 r4s)"; pause104 "$SR4_104/lib/OfferStore.php" "            if (\$pdo->exec('BEGIN IMMEDIATE') !== false) return true;"
fnow104 > "$A4B_104.launch"   # the Start launched (the clock it runs under): its $now is read after this, before its pause
actor104 st4 "$A4B_104.start" "$H104/rh104-live.php" offerstart "$SR4_104" "$DATA" "{\"offer\":${O42_104:-0}}"
wfile104 "$A4B_104.start.ready" 60
check "$(qj "$AL4B_104" ok 2>/dev/null)|$( [ -f "$A4B_104.start.ready" ] && echo held)|$(grep -c 'RH104_PAUSE' "$SR4_104/lib/OfferStore.php")|$(kv104 "$(liveoff104)" live)|$(python3 "$H104/rh104q.py" offer "$DATA/plugin.sqlite3" "${O42_104:-0}" 2>/dev/null | grep -o 'state=[a-z]*')" \
      "True|held|1|-|state=draft" \
  "7o10 R4b's setting: every part's keys set; no offer live (this rehearsal's reading); offer #2 a draft; the admin's Start of it, its request's configuration read, held before its BEGIN IMMEDIATE — its pause in its COPY alone (one line)"
o_r4b() {   # R4b's moment: when the baseline ends, what the held Start had done by then (passed its pause? ended?) and the
            # events' largest id then, recorded; then, in the copy's start second, the Start let go
  local d="$SB/.tl104" a="$A4B_104"
  wfile104 "$d/base.1.end" 900 || return
  printf '%s,%s,%s\n' "$( [ -e "$a.start.begin" ] && echo begun || echo held)" "$( [ -e "$ACT104/st4.rc" ] && echo ended || echo running)" "$(maxid104 wa_offer_events)" > "$a.atbase"
  : > "$a.start.go"; wfile104 "$ACT104/st4.rc" 90
  : > "$d/base.1.release"
}
tl_arm104 base.1.after base.1.align
o_r4b > "$R104/logs/orch-r4b.txt" 2>&1 & OP4B_104=$!
fresh104; OUT="$(run104c --answer DEPLOY --redeploy --clear-switches)"; T4BR104="$(last104)"
kill "$OP4B_104" 2>/dev/null; wait "$OP4B_104" 2>/dev/null; golet104 "$A4B_104.start"; actwait104
U4B_104="$(tlu104 base.1)"; TL4B_104="$(python3 "$H104/rh104q.py" tl "$SB/.tl104/timeline")"; tl_disarm104 r4b
CA4B_104="$(cav104 "$OUT" r4b)"
OS4B_104="$(python3 -I -c 'import sqlite3, sys
c = sqlite3.connect("file:" + sys.argv[1] + "?mode=ro", uri=True)
o, mr, me = int(sys.argv[2]), int(sys.argv[3]), int(sys.argv[4])
n = c.execute("SELECT COUNT(*) FROM wa_offer_recipients WHERE offer_id = ? AND id > ?", (o, mr)).fetchone()[0]
e = c.execute("SELECT COUNT(*), MIN(CAST(strftime(?, at) AS INTEGER)), MIN(id) FROM wa_offer_events WHERE offer_id = ? AND id > ? AND action = ?", ("%s", o, me, "started")).fetchone()
print("recipients=%d started=%d at=%s id=%s" % (n, e[0], e[1], e[2]))' "$DATA/plugin.sqlite3" "$(n103 "$O42_104")" "$MXR4B_104" "$MXE4B_104")"
check "$(fset104 "$OUT")|$(live)|$(installed104 "$P104")|$(v3gg104 "$OUT")|$(kv104 "$CA4B_104" of3)|$(kv104 "$CA4B_104" ca2)" ":|$P104|0|$V3W4B_104|ok|ok" \
  "7o11 R4b --redeploy --clear-switches, DEPLOY typed: $P104 serving; no FAIL — V3g's note names the ai_ keys the clear took (DS5, S2); OF-CA3 and QF-CA2 read PASS"
check "$(cat "$ACT104/st4.rc" 2>/dev/null)|$(qj "$(cat "$ACT104/st4.out" 2>/dev/null)" ok 2>/dev/null)|$( [ -f "$A4B_104.start.begin" ] && echo held)|$(cut -d, -f1,2 "$A4B_104.atbase" 2>/dev/null)|$(kv104 "$OS4B_104" started)|$( [ "$(n103 "$(kv104 "$OS4B_104" id)")" -gt "$(n103 "$(cut -d, -f3 "$A4B_104.atbase" 2>/dev/null)")" ] && [ "$(n103 "$(cut -d, -f3 "$A4B_104.atbase" 2>/dev/null)")" -ge 1 ] && echo above)|$( [ "$(n103 "$(cat "$A4B_104.launch" 2>/dev/null)")" -ge 1 ] && [ "$(n103 "$(kv104 "$OS4B_104" at)")" -ge "$(n103 "$(cat "$A4B_104.launch" 2>/dev/null)")" ] && [ "$(n103 "$(tlt104 "$TL4B_104" clear.1 2)")" -ge "$(n103 "$(kv104 "$OS4B_104" at)")" ] && echo requested)|$( [ "$(n103 "$(kv104 "$OS4B_104" recipients)")" -ge 1 ] && [ "$(kv104 "$OS4B_104" recipients)" = "$(qj "$(cat "$ACT104/st4.out" 2>/dev/null)" queued 2>/dev/null)" ] && echo queued)|$(kv104 "$CA4B_104" of3added | tr ',' '\n' | sed -n 's/^recipients://p')|$(kv104 "$CA4B_104" of3events | tr ',' '\n' | grep -c '^started:')|$(kv104 "$(liveoff104)" live)" \
      "0|True|held|held,running|1|above|requested|queued|$(kv104 "$OS4B_104" recipients)|1|$(n103 "$O42_104"):running" \
  "7o12 the admin's Start whose request read its configuration before the clear, held before its BEGIN IMMEDIATE until after the baseline: when the baseline ended it had neither passed its pause nor ended (its exit code and its begin marker, both read after the run, written only after); then it began and started offer #2 — its started event's id above the events' largest when the baseline ended, its stamp the request's own moment, at or after the Start was launched and at or before the clear's start (OfferStore::start stamps the time its caller read before its BEGIN IMMEDIATE, so the hold never moves it, and a stamp before the copy's start is not late), its recipients as many as the Start queued (this rehearsal's reading) — and OF-CA3 counts those recipients and that event among the rows added and reads PASS; offer #2 now the one live offer"
check "$(grep -c . "$R104/wait104.fail")|$(grep -lF -f "$NAMES104" "$R104/run-$T4BR104.txt" "$OUT104"/*.log 2>/dev/null | grep -c .)|$(uother104 "$T4BR104")" "0|0|-" \
  "7o12' …no wait of this rehearsal's timed out; no fixture name or phone in the terminal or the log; uCRM asked GETs only"
rbx104 "7o13"
echo; echo "== 104-7c. the controls of every part, each read FAIL; the weakened copies of the script, each caught by the check it weakens =="
# ── The weakened copies, each an asserted edit of the script's own text (weak104, the table above): W1 the retired stage-A
# comparison (QF-CA2's rows and QF-CA3's entries compared with stage A's, VN-CA4 reading every row above stage A's largest id,
# OF-CA3 comparing with stage A's — the baseline taken in stage A, beside QF-G4's first read); W2 "at or after" the copy's
# start (QF-CA2's claims, QF-CA3's entries, VN-CA4's claims and calls); W3 the baselines and the copy's start taken as soon as
# QF-G4 returns, without the pause; W4 VN-CA4 judging absolute counts and OF-CA3 judging every added row FAIL. ──
sed 's/ @@@ /\t/g' >> "$ANCH104" <<'ANCH'
w1a @@@ deploy @@@   QF_G4_SET="$(qf_kv "$o" set)"; QF_G4_NAMES="${o##* names=}" @@@   QF_G4_SET="$(qf_kv "$o" set)"; QF_G4_NAMES="${o##* names=}"; [ "$QF_G1" != redeploy ] || { vn_base_prep; of_base_prep; QF_BASE_A="$(qf_php base "$PDD_IN/plugin.sqlite3" "${VN_BASE_F:-}" "${OF_BASE_F:-}")"; VN_BASE_A="$(dxo cat "$VN_BASE_F")"; OF_BASE_A="$(dxo cat "$OF_BASE_F")"; }   # rh104-weakened W1: stage A's counts
w1b @@@ deploy @@@     if [ "$(qf_kv "$o" claims_late)" = "0" ]; then ok "QF-CA2 a redeploy @@@     if [ "$(qf_kv "$o" rows)" = "$(qf_kv "${QF_BASE_A:-}" rows)" ]; then ok "QF-CA2 a redeploy
w1c @@@ deploy @@@     elif [ "$(qf_kv "$o" entries_late)" = "0" ]; then ok "QF-CA3 a redeploy @@@     elif [ "$(qf_kv "$o" entries)" = "$(qf_kv "${QF_BASE_A:-}" entries)" ]; then ok "QF-CA3 a redeploy
w1d @@@ deploy @@@     o4="$(vn_php ca4 "$sub/plugin.sqlite3" "$(qf_kv "${QF_BASE:-}" max)" "$(qf_kv "${VN_BASE:-}" max)" "$(qf_kv "${VN_BASE:-}" evmax)" @@@     o4="$(vn_php ca4 "$sub/plugin.sqlite3" "$(qf_kv "${QF_BASE_A:-}" max)" "$(qf_kv "${VN_BASE_A:-}" max)" "$(qf_kv "${VN_BASE_A:-}" evmax)"
w1e @@@ deploy @@@       elif [ "$(qf_kv "$o4" ack_claims_late)|$(qf_kv "$o4" acks_late)|$(qf_kv "$o4" calls_late)" = "0|0|0" ]; then @@@       elif [ "$(qf_kv "$o4" added)|$(qf_kv "$o4" ev_added)" = "0,0,0|0,0" ]; then
w1f @@@ deploy @@@ "$( [ "${QF_G1:-}" = "redeploy" ] && printf '%s' "${OF_BASE:-}")" "${QF_COPY_UNIX:-0}")" @@@ "$( [ "${QF_G1:-}" = "redeploy" ] && printf '%s' "${OF_BASE_A:-}")" "${QF_COPY_UNIX:-0}")"
w1g @@@ deploy @@@       if [ "$v" = "0|0|0|0" ] && [ -z "$n" ]; then @@@       if [ "$v|$(qf_kv "$o" added_claims)|$(qf_kv "$o" added_tests)|$(qf_kv "$o" added_test_rows)|$(qf_kv "$o" added_echoes)|$(qf_kv "$o" added)|$(qf_kv "$o" added_events)" = "0|0|0|0|0|0|0|0|offers:0,recipients:0|-" ] && [ -z "$n" ]; then
w2a @@@ deploy @@@ if ($t > $cu) $late++; elseif ($t === $cu) $same++; else $early++; @@@ if ($t >= $cu) $late++; elseif ($t === $cu) $same++; else $early++;
w2b @@@ deploy @@@ if ($lr > $cu) $elate++; @@@ if ($lr >= $cu) $elate++;
w2c @@@ deploy @@@ $when = function (int $t) use ($cu): string { return $t > $cu ? 'late' : @@@ $when = function (int $t) use ($cu): string { return $t >= $cu ? 'late' :
w3a @@@ deploy @@@   printf '  …     waiting 6 s (busy_timeout plus 1 s) after QF-G4 before the redeploy baseline (QF-G1; Q20-F01)\n'; sleep 6 @@@   : 'rh104-weakened W3: no pause before the redeploy baseline'
w4a @@@ deploy @@@       elif [ "$(qf_kv "$o4" ack_claims_late)|$(qf_kv "$o4" acks_late)|$(qf_kv "$o4" calls_late)" = "0|0|0" ]; then @@@       elif [ "$(qf_kv "$o4" rows)|$(qf_kv "$o4" events)" = "0,0,0|0,0" ]; then
w4b @@@ deploy @@@       if [ "$v" = "0|0|0|0" ] && [ -z "$n" ]; then @@@       if [ "$v|$(qf_kv "$o" added_claims)|$(qf_kv "$o" added_tests)|$(qf_kv "$o" added_test_rows)|$(qf_kv "$o" added_echoes)|$(qf_kv "$o" added)|$(qf_kv "$o" added_events)" = "0|0|0|0|0|0|0|0|offers:0,recipients:0|-" ] && [ -z "$n" ]; then
ANCH
ANCR5_104="$(python3 -I - "$DEPLOY104" "$ANCH104" <<'PY'
import sys
s = open(sys.argv[1], encoding='utf-8').read()
rows = [l.rstrip('\n').split('\t') for l in open(sys.argv[2], encoding='utf-8') if l.strip()]
mine = [r for r in rows if r[0] in ('vng6r', 'w1a', 'w1b', 'w1c', 'w1d', 'w1e', 'w1f', 'w1g', 'w2a', 'w2b', 'w2c', 'w3a', 'w4a', 'w4b')]
bad = [r[0] for r in mine if len(r) != 4 or s.count(r[2]) != 1 or r[2] == r[3]]
keys = [r[0] for r in rows]
dup = [k for k in set(keys) if keys.count(k) > 1]
print('%d|%d|%s|%s' % (len(bad), len(mine), ','.join(bad) or '-', ','.join(sorted(dup)) or '-'))
PY
)"
check "$ANCR5_104" "0|14|-|-" "7c control: each of the 14 anchors of 104-7's weakened copies (VN-G6's, W1's seven, W2's three, W3's, W4's two) is the script's own text, found in it exactly once, each replacement different; no key of the table twice"
carried104() {   # carried104 COPY KEY… — how many of the KEYs' edits the weakened COPY carries: the replacement there, the anchor gone
                 # (unless the replacement holds it) — read from the table itself, never a typed count
  python3 -I - "$1" "$ANCH104" "${@:2}" <<'PY'
import sys
s = open(sys.argv[1], encoding='utf-8').read()
t = {r[0]: r for r in (l.rstrip('\n').split('\t') for l in open(sys.argv[2], encoding='utf-8') if l.strip())}
print(sum(1 for k in sys.argv[3:] if k in t and len(t[k]) == 4 and s.count(t[k][3]) >= 1 and (t[k][2] in t[k][3] or s.count(t[k][2]) == 0)))
PY
}
tabnames104() {   # tabnames104 REGEX KEY… — how many of the KEYs' replacements in the table match REGEX (each replacement one line)
  python3 -I - "$ANCH104" "$1" "${@:2}" <<'PY'
import re, sys
t = {r[0]: r for r in (l.rstrip('\n').split('\t') for l in open(sys.argv[1], encoding='utf-8') if l.strip())}
print(sum(1 for k in sys.argv[3:] if k in t and len(t[k]) == 4 and re.search(sys.argv[2], t[k][3])))
PY
}
W1K104="w1a w1b w1c w1d w1e w1f w1g"; W2K104="w2a w2b w2c"; BASEA104='QF_BASE_A|VN_BASE_A|OF_BASE_A'
M="$(weak104 w1chk $W1K104)"; W1N104="$(grep -cE "$BASEA104" "$M")"; W1C104="$(carried104 "$M" $W1K104)"; W1P104="$(bash -n "$M" && echo parses)"; rm -f "${M:?}"
M="$(weak104 w2chk $W2K104)"; W2N104="$(grep -cF '>= $cu' "$M")"; W2C104="$(carried104 "$M" $W2K104)"; rm -f "${M:?}"
M="$(weak104 w3chk w3a)"; W3N104="$(grep -c 'sleep 6' "$M")|$(grep -c 'rh104-weakened W3' "$M")"; W3C104="$(carried104 "$M" w3a)"; rm -f "${M:?}"
check "$W1N104|$W1C104|$W1P104|$W2N104|$W2C104|$W3N104|$W3C104|$(grep -cE "$BASEA104" "$DEPLOY104"),$(grep -cF '>= $cu' "$DEPLOY104")|$(cat "$SB/anchor-missing" 2>/dev/null | grep -c '^weak104:w[1-4]')" \
      "$(tabnames104 "$BASEA104" $W1K104)|$(wc -w <<<"$W1K104" | tr -d ' ')|parses|$(tabnames104 '>= \$cu' $W2K104)|$(wc -w <<<"$W2K104" | tr -d ' ')|$(( $(grep -c 'sleep 6' "$DEPLOY104") - 1 ))|1|1|0,0|0" \
  "7c' control: the weakened copies carry exactly their edits, each counted from the table (RH5-fix1, R04: never a typed count) — W1 names stage A's baselines on as many lines as its replacements name them, carries every one of its edits and parses; W2 judges \"at or after\" in as many places as its replacements, every edit carried; W3's pause gone, its marker once; the unweakened script names neither"
# ── C1: the controls of the four parts, one plain --redeploy (no key set: nothing cleared, V3g quiet). After the baseline,
# before the copy, each control planted raw with a stamp in a LATER second than the copy's start: a claim (Part F's QF-CA2);
# an entry of the four (QF-CA3); a voice_ack claim, a sent and an uncertain decision each with its ledger row so claimed, a
# call (Part V's VN-CA4); an offer ledger row, an offer.send echo, a test row and an offer_test:<id> ledger row naming no test
# (Part O's OF-CA3). Each check reads FAIL with its own count. ──
A5_104="$ACT104/c1"
o_c1() {
  local d="$SB/.tl104" a="$A5_104" u k
  wfile104 "$d/base.1.end" 900 || return
  u="$(tlu104 base.1)"
  for k in claim ack acksent ackunc call offer echo test testrow; do printf '%s %s\n' "$k" "$(plant104 "$k" $(( u + 1 )) "c1$k")" >> "$a.plants"; done
  entry104 ai_media $(( u + 1 )) > "$a.entry"
  : > "$d/base.1.release"
}
MXP5_104="$(maxid104 wa_auto_sends)|$(maxid104 wa_voice_acks)|$(maxid104 wa_voice_calls)|$(maxid104 evo_webhook_seen)|$(maxid104 wa_offer_tests)"
tl_arm104 base.1.after
o_c1 > "$R104/logs/orch-c1.txt" 2>&1 & OP5_104=$!
EV5_104="$(evo_calls)"
fresh104; OUT="$(run104c --answer DEPLOY --redeploy)"; T5R104="$(last104)"
kill "$OP5_104" 2>/dev/null; wait "$OP5_104" 2>/dev/null; tl_disarm104 c1
CA5_104="$(cav104 "$OUT" c1)"
check "$(grep -c . "$A5_104.plants" 2>/dev/null)|$(awk '{print $2}' "$A5_104.plants" 2>/dev/null | awk -F: -v m="$MXP5_104" 'BEGIN { split(m, x, "|"); t["wa_auto_sends"] = x[1]; t["wa_voice_acks"] = x[2]; t["wa_voice_calls"] = x[3]; t["evo_webhook_seen"] = x[4]; t["wa_offer_tests"] = x[5] } ($1 in t) && $2 + 0 > t[$1] + 0 { n++ } END { print n + 0 }')|$(qj "$(cat "$A5_104.entry" 2>/dev/null)" set 2>/dev/null)|$( [ -s "$R104/logs/plant104.err" ] && echo errors || echo clean)" \
      "9|9|1|clean" \
  "7c1 C1's plants, each read back: nine rows, every one above its table's largest id before the run (this rehearsal's own reading), and the entry of the four written — the controls exist (the positive control of each FAIL below)"
check "$(fset104 "$OUT")|$(live)|$(installed104 "$P104")|$(kv104 "$CA5_104" ca2)|$(kv104 "$CA5_104" ca2late)|$(kv104 "$CA5_104" ca3)|$(kv104 "$CA5_104" ca3late)" \
      "OF-CA3 QF-CA2 QF-CA3 VN-CA4:4|$P104|0|fail|5|fail|1" \
  "7c2 Part F's controls: a claim stamped in a later second than the copy's start reads FAIL at QF-CA2 — 5 claims late, the planted quote claim with the three voice_ack claims and the offer claim beside it (every claim of any kind is QF-CA2's) — and an entry of the four with a last_run in such a second reads FAIL at QF-CA3 (1)"
check "$(kv104 "$CA5_104" vn4)|$(kv104 "$CA5_104" vn4late)" "fail|3/2/1" \
  "7c3 Part V's controls, each read FAIL at VN-CA4: the voice_ack claim and the two decisions' ledger rows (3 claims late), the sent and the uncertain decision judged by their ledger rows (2), the call (1)"
check "$(kv104 "$CA5_104" of3)|$(kv104 "$CA5_104" of3late)|$(( $(evo_calls) - EV5_104 ))" "fail|1/1/1/1|0" \
  "7c4 Part O's controls, each read FAIL at OF-CA3: the offer ledger row, the offer.send echo, the test row and the offer_test:<id> ledger row naming no test, each above the baseline with a stamp after the copy's start (1/1/1/1); Evolution not called by the run"
rbx104 "7c5"
# ── W2 ("at or after"), over writer-made rows (RH5-fix1, R07): (b) again under the weakened copy — every part's keys but
# voice-reply's, --redeploy --clear-switches; inside the held clear a voice note on the sales number whose acknowledgement and
# call the same paused COPIES hold (vheld104: after step 13's read and after process's fresh read, before the claim and the
# reservation) and let go after the baseline, so the earlier build's own voice_ack claim and wa_voice_calls reservation fall
# in the copy's start second — no voice row planted; beside them an offer claim and an entry of the four planted in that
# second (Part F's half, planted as D plants them for 7f9). What the weakened copy must count late is read off the book by
# this rehearsal (rh104q atleast: every claim, voice_ack claim, sent or uncertain decision and call at or after the copy's
# start), never typed; the unweakened 7f9 and 7v9 read PASS over such rows. ──
AL6_104="$(live104 allon "$PINR104" "$DATA" '{"statement":"RH104SECRET-a cold statement of the rehearsal","channels":"sales,support","except":["voice-reply"]}')"
SN6_104="$(setnames104)"; V3W6_104="$(v3gw104 "$SN6_104")"; G6B_104="$(live104 secrets "$VR2_104" "$DATA")"
A6_104="$ACT104/w2"; MXV6_104="$(maxid104 wa_voice_acks)"; MXC6_104="$(maxid104 wa_voice_calls)"; OAW0_104="$(oa7calls104)"
check "$(qj "$AL6_104" ok 2>/dev/null)|$GATE7_104|$(qj "$G6B_104" openai 2>/dev/null)|$(qj "$G6B_104" brain 2>/dev/null)|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" ai_media_voice_privacy_line)" set)|$(ackleft104)" \
      "True|yes|unset|unconfigured|1|0" \
  "7c6- W2's setting: every part's keys but voice-reply's set through the pin's writer; the assistant still unconfigured on the held reply worker's copy (RH3's gate, read again); the privacy line still recorded (7v0); no acknowledgement event waiting before the note"
o_w2() {   # W2's moments: inside the held clear the note, its acknowledgement and its call held; after the baseline, in the copy's
           # start second, the offer claim and the entry planted, the held two let go
  local d="$SB/.tl104" a="$A6_104" u
  wfile104 "$d/clear.1.start" 600 || return
  vheld104 "$a" W2 +256700104093
  : > "$d/clear.1.go"
  wfile104 "$d/base.1.end" 900 || return
  u="$(tlu104 base.1)"
  printf '%s %s\n' offer "$(plant104 offer "$u" w2offer)" >> "$a.plants"
  entry104 ai_media "$u" > "$a.entry"
  : > "$a.ack.go"; : > "$a.call.go"
  wfile104 "$ACT104/W2w.rc" 90; wfile104 "$ACT104/W2vm.rc" 90
  : > "$d/base.1.release"
}
tl_arm104 clear.1.hold base.1.after base.1.align
o_w2 > "$R104/logs/orch-w2.txt" 2>&1 & OP6_104=$!
M="$(weak104 w2 $W2K104)"; fresh104; OUT="$(R104_MUTANT_OUT=1 run104c --script "$M" --answer DEPLOY --redeploy --clear-switches)"; rm -f "${M:?}"
kill "$OP6_104" 2>/dev/null; wait "$OP6_104" 2>/dev/null; golet104 "$A6_104.ack" "$A6_104.call"; actwait104
U6_104="$(n103 "$(tlu104 base.1)")"; tl_disarm104 w2
CA6_104="$(cav104 "$OUT" w2)"
AT6_104="$(python3 "$H104/rh104q.py" atleast "$DATA/plugin.sqlite3" "$(n103 "$(kv104 "$CA6_104" basemax)")" "$MXV6_104" "$MXC6_104" "$U6_104")"
E6L_104="$(tr ',' '\n' <<<"$(kv104 "$(python3 "$H104/rh104q.py" sched4 "$DATA/plugin.sqlite3")" entries)" | awk -F: -v u="$U6_104" 'NF == 2 && $2 + 0 >= u + 0' | grep -c .)"
check "$(grep -c '^offer wa_auto_sends:' "$A6_104.plants" 2>/dev/null)|$(qj "$(cat "$A6_104.entry" 2>/dev/null)" set 2>/dev/null)|$(qj "$(cat "$A6_104.note2" 2>/dev/null)" http 2>/dev/null)|$( [ -f "$A6_104.ack.begin" ] && echo held)|$( [ -f "$A6_104.call.begin" ] && echo held)|$(kv104 "$AT6_104" same_ack)|$(kv104 "$AT6_104" same_calls)|$(( $(n103 "$(oa7calls104)") - $(n103 "$OAW0_104") ))|$( [ "$U6_104" -ge 1 ] && echo copy)" \
      "1|1|200|held|held|1|1|1|copy" \
  "7c6 W2's acts, the positive control of its FAILs: the offer claim and the entry planted in the copy's start second; the voice note 200 at the earlier build's webhook, its acknowledgement and its call held by the COPIES and let go after the baseline — the earlier build's own voice_ack claim and wa_voice_calls reservation in that second (this rehearsal's reading of the book), the fake OpenAI called once"
check "$(fset104 "$OUT")|$(v3gg104 "$OUT")|$(kv104 "$CA6_104" ca2late)|$(kv104 "$CA6_104" ca3late)|$(kv104 "$CA6_104" vn4late)|$(kv104 "$CA6_104" of3)" \
      "QF-CA2 QF-CA3 VN-CA4:3|$V3W6_104|$(kv104 "$AT6_104" claims)|$E6L_104|$(kv104 "$AT6_104" ack)/$(kv104 "$AT6_104" dec)/$(kv104 "$AT6_104" calls)|ok" \
  "7c6' W2, judging \"at or after\" the copy's start: FAIL over the claims and the entry in its second — QF-CA2 counting every claim the book holds at or after it (the earlier build's acknowledgement claim and the planted offer claim), QF-CA3 the entry, VN-CA4 the writers' claim, the decision its send recorded and the reservation — each the count this rehearsal reads off the book, each caught; V3g no FAIL, its note naming the clear's keys (DS5, S2); OF-CA3, not weakened, PASS over the same offer claim"
rbx104 "7c7"
# ── W4: a plain --redeploy; in the copy's start second an offer claim planted (7f9's). VN-CA4 judging absolute counts FAILs over
# (a)'s history; OF-CA3 judging every added row FAILs over that claim; QF-CA2, not weakened, reads PASS. ──
A7_104="$ACT104/w4"
o_w4() { local d="$SB/.tl104" a="$A7_104" u; wfile104 "$d/base.1.end" 900 || return; u="$(tlu104 base.1)"; plant104 offer "$u" w4 > "$a.plant"; : > "$d/base.1.release"; }
tl_arm104 base.1.after
o_w4 > "$R104/logs/orch-w4.txt" 2>&1 & OP7_104=$!
M="$(weak104 w4 w4a w4b)"; fresh104; OUT="$(R104_MUTANT_OUT=1 run104c --script "$M" --answer DEPLOY --redeploy)"; rm -f "${M:?}"
kill "$OP7_104" 2>/dev/null; wait "$OP7_104" 2>/dev/null; tl_disarm104 w4
CA7_104="$(cav104 "$OUT" w4)"
check "$(cut -d: -f1 "$A7_104.plant" 2>/dev/null)|$(fset104 "$OUT")|$(kv104 "$CA7_104" vn4)|$(kv104 "$CA7_104" vn4late)|$(kv104 "$CA7_104" of3)|$(kv104 "$CA7_104" of3late)|$(kv104 "$CA7_104" ca2)|$(kv104 "$CA7_104" ca2same)" \
      "wa_auto_sends|OF-CA3 VN-CA4:2|fail|0/0/0|fail|0/0/0/0|ok|1" \
  "7c8 W4: VN-CA4 judging absolute counts reads FAIL over (a)'s history with nothing late (0/0/0) — caught; OF-CA3 judging every added row reads FAIL over the copy's-start-second offer claim with nothing late — caught; QF-CA2 reads PASS over it"
rbx104 "7c9"
# ── W3 (no pause): every part's keys, --clear-switches, a new day in the list's window; the daily-list run and the second
# connection as in 7f8. Without the pause the baseline is taken as soon as QF-G4 returns, before the list claim the lock keeps
# waiting: the claim lands above it in a later second — QF-CA2 FAILs over it (caught). ──
clk_to104 "$(kday_next104 08:30)"
AL8_104="$(live104 allon "$PINR104" "$DATA" '{"statement":"RH104SECRET-a cold statement of the rehearsal","channels":"sales,support"}')"
A8_104="$ACT104/w3"; MXA8_104="$(maxid104 wa_auto_sends)"
DR8_104="$(pinroot104 w3d)"; pause104 "$DR8_104/lib/QuoteDailyList.php" "        \$cl = \$ledger->claim(\$inst, 'staff_list', \$ref);"
actor104 dl3 "$A8_104.dl3" "$DR8_104/cron/quote_daily_list.php"; D3R104="$(dlwait104 "$A8_104.dl3")"
o_w3() { local d="$SB/.tl104" a="$A8_104"
  wfile104 "$d/g4.2.start" 600 || return
  lock104 3 "$a.lock"; wfile104 "$a.lock.locked" 30 || return
  : > "$a.dl3.go"; wfile104 "$a.dl3.begin" 30 || return; sleep 0.5; : > "$d/g4.2.go"; }
tl_arm104 g4.2.hold
o_w3 > "$R104/logs/orch-w3.txt" 2>&1 & OP8_104=$!
M="$(weak104 w3 w3a)"; fresh104; OUT="$(R104_MUTANT_OUT=1 run104c --script "$M" --answer DEPLOY --redeploy --clear-switches)"; rm -f "${M:?}"
kill "$OP8_104" 2>/dev/null; wait "$OP8_104" 2>/dev/null; golet104 "$A8_104.dl3"; actwait104
TL8_104="$(python3 "$H104/rh104q.py" tl "$SB/.tl104/timeline")"; tl_disarm104 w3
CA8_104="$(cav104 "$OUT" w3)"; DC8_104="$(python3 "$H104/rh104q.py" claimat "$DATA/plugin.sqlite3" staff_list "$MXA8_104")"
check "$D3R104|$(has "$OUT" '  …     waiting 6 s (busy_timeout plus 1 s) after QF-G4 before the redeploy baseline')|$(fset104 "$OUT")|$(kv104 "$CA8_104" ca2late)|$( [ "$(cut -d: -f1 <<<"${DC8_104%%,*}")" -gt "$(n103 "$(kv104 "$CA8_104" basemax)")" ] 2>/dev/null && echo above)" \
      "ready|no|QF-CA2:1|1|above" \
  "7c10 W3, the baselines and the copy's start taken as soon as QF-G4 returns, without the pause: the list claim the lock kept waiting lands above the baseline in a later second — QF-CA2 FAILs over it (caught), its one FAIL: V3g's note names the clear's keys (DS5, S2)"
rbx104 "7c11"
# ── W1 (the retired stage-A comparison): every part's keys, --clear-switches; a reminder run begun before the clear that records
# after the baseline (7f6's); a voice note and its acknowledgement between stage A's copy and the clear (7v7's); an offer
# running at the clear, paused by Part O's hook after stage A's copy (7o5's). QF-CA2 compared with stage A's counts FAILs over
# the reminder run; VN-CA4 reading every row above stage A's largest id FAILs over the note and its acknowledgement; OF-CA3
# compared with stage A's copy FAILs over the hook's paused_by_rollback event — each caught. The stage-A baseline is a second
# base call (base.1), so the real baseline is base.2 here. Offer #2 (started in R4b, paused by its rollback, still live) is
# stopped through the release's writer first, so that offer #3 can start (one live offer, O-D1; RH5-fix1, R01). ──
offstop104 "7c12-0" "$O42_104"
AL9_104="$(live104 allon "$PINR104" "$DATA" '{"statement":"RH104SECRET-a cold statement of the rehearsal","channels":"sales,support"}')"
OP93_104="$(live104 offerprep "$PINR104" "$DATA" '{"title":"RH104SECRET-offer seven-c","w1":"RH104SECRET-wording one seven-c","w2":"RH104SECRET-wording two seven-c","channel":"support"}')"
O93_104="$(qj "$OP93_104" offer 2>/dev/null)"; OS93_104="$(live104 offerstart "$PINR104" "$DATA" "{\"offer\":${O93_104:-0}}")"
A9_104="$ACT104/w1"; VR9_104="$(pinroot104 w1w)"
actor104 rem9 "" "$H104/rh104-live.php" remind "$PINR104" "$DATA" "{\"phone\":\"+256700104068\",\"name\":\"RH104SECRET-Kizza-W1\",\"invoice\":\"RH104SECRET-INV-68\",\"marker\":\"$A9_104.rem\"}"
wfile104 "$A9_104.rem.ready" 60
check "$(qj "$AL9_104" ok 2>/dev/null)|$(qj "$OS93_104" ok 2>/dev/null)|$( [ -f "$A9_104.rem.ready" ] && echo rem)|$GATE7_104" "True|True|rem|yes" \
  "7c12 W1's setting: every part's keys set, offer #3 made, tested and Started (running), the reminder run waiting with the ledger on, the assistant still unconfigured"
o_w1() { local d="$SB/.tl104" a="$A9_104"
  wfile104 "$d/clear.1.start" 600 || return
  vpost104 support +256700104069 W1 > "$a.note"
  [ "$GATE7_104" = yes ] && { actor104 w9 "" "$VR9_104/run_worker.php"; wfile104 "$ACT104/w9.rc" 120; }
  : > "$d/clear.1.go"
  wfile104 "$d/base.2.end" 900 || return
  : > "$a.rem.go"; wfile104 "$a.rem.done" 60; : > "$d/base.2.release"; }
tl_arm104 clear.1.hold base.2.after
o_w1 > "$R104/logs/orch-w1.txt" 2>&1 & OP9_104=$!
M="$(weak104 w1 w1a w1b w1c w1d w1e w1f w1g)"; fresh104; OUT="$(R104_MUTANT_OUT=1 run104c --script "$M" --answer DEPLOY --redeploy --clear-switches)"; rm -f "${M:?}"
kill "$OP9_104" 2>/dev/null; wait "$OP9_104" 2>/dev/null; golet104 "$A9_104.rem"; actwait104
TL9_104="$(python3 "$H104/rh104q.py" tl "$SB/.tl104/timeline")"; tl_disarm104 w1
CA9_104="$(cav104 "$OUT" w1)"
check "$(python3 "$H104/rh104q.py" tl "$R104/logs/tl-w1.txt" | tr ' ' '\n' | sed 's/=.*//' | paste -sd, -)|$(qj "$(cat "$A9_104.note" 2>/dev/null)" http 2>/dev/null)|$(cat "$ACT104/rem9.rc" 2>/dev/null)|$(fset104 "$OUT")" \
      "g4.1,base.1,clear.1,g4.2,base.2,copied.1|200|0|OF-CA3 QF-CA2 VN-CA4:3" \
  "7c13 W1, compared with stage A: its baseline read in stage A beside QF-G4's first read (base.1), the real one after the clear (base.2); the note reached the earlier build's webhook, the reminder recorded — FAIL at QF-CA2, VN-CA4 and OF-CA3, and none at V3g, whose note names the clear's keys (DS5, S2)"
check "$(kv104 "$CA9_104" ca2)|$(kv104 "$CA9_104" ca3)|$(kv104 "$CA9_104" vn4)|$(kv104 "$CA9_104" vn4late)|$(kv104 "$CA9_104" of3)|$(kv104 "$CA9_104" of3late)" "fail|ok|fail|0/0/0|fail|0/0/0/0" \
  "7c14 …each caught by the check it weakens: QF-CA2's counts compared with stage A's FAIL over the reminder run (QF-CA3's entries, unchanged since stage A, still ok); VN-CA4 FAILs over the note and its acknowledgement made after stage A's copy, nothing late; OF-CA3 FAILs over the hook's paused_by_rollback event after stage A's copy, nothing late — the unweakened 7f6, 7v7 and 7o5 read PASS over the same acts"
rbx104 "7c15"
# ── What 104-7 leaves: the earlier build's webhook and the fake OpenAI (which served (a)'s rungs, (b) and W2) stopped; the
# privacy line 7v0 recorded unset again through the same writer (a setting: `off` never clears it, and no later case needs it);
# its vault copy and every actor's copy removed (no injected pause left anywhere), the stand-in Evolution's voice-note file
# removed, the moments hook inert; the stand-in Evolution's log of the cases' own sends moved aside (104-10 reads what is
# left), counted. ──
kill "$WHPID7_104" 2>/dev/null; wait "$WHPID7_104" 2>/dev/null; kill "$OAPID7_104" 2>/dev/null; wait "$OAPID7_104" 2>/dev/null; actwait104
PLU7_104="$(live104 keys "$PINR104" "$DATA" '{"ai_media_voice_privacy_line":null}' '["ai_media_voice_privacy_line"]')"
PLE7_104="$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" ai_media_voice_privacy_line)" set)"
EVR5_104=$(( $(evo_calls) - EVR5_0 )); cp "$SB/evo/requests.log" "$R104/evo-rh5.log" 2>/dev/null; : > "$SB/evo/requests.log"
rm -f "$SB/evo/rh104-media.b64" "$SB/evo/rh104-slow" "$SB/evo/rh104-slow.in" "$SB/evo/rh104-slow.go"
rm -rf "${ACT104:?}" "${PINT104:?}"; rm -f "$H104/v/live.err" "$H104/v/live-vault.json"
check "$( [ -e "$SB/.tl104" ] && echo armed || echo inert)|$( [ -e "$ACT104" ] && echo actors || echo none)|$(grep -rl 'RH104_PAUSE' "$PD" "$H104" 2>/dev/null | grep -c .)|$(ls -A "$SB/evo" | grep -c '^rh104-\(slow\|media\)')|$(pgrep -f "$WR104" 2>/dev/null | grep -c .)|$(pgrep -f "$OAI7_104/fake_openai_server.php" 2>/dev/null | grep -c .)|$(qj "$PLU7_104" ok 2>/dev/null)|$PLE7_104|$(grep -c . "$R104/wait104.fail")|$( [ "$EVR5_104" -ge 1 ] && echo sends)|$(grep -cvE "^(GET|POST) $EVP104/" "$R104/evo-rh5.log")" \
      "inert|none|0|0|0|0|True|0|0|sends|0" \
  "7c16 104-7 leaves nothing armed: the moments hook inert, no actor's copy, no injected pause anywhere (the installed plugin, the rehearsal's folders), no stand-in file of 104-7's, the earlier build's webhook and the fake OpenAI stopped; the privacy line unset again (read set at 7v0 and 7c6-, unset now); no wait timed out; the stand-in Evolution was asked only on its own base path, by the cases' own sends and media reads ($EVR5_104 requests, moved aside)"
fi   # LIVE104

echo; echo "== 104-10. what the rehearsal left behind, and what it never reached =="
fresh104   # the last logs and sheets into the sweeps' folders
check "$(evo_calls)" "0" "Evolution never called — not by the deploys, their checks, the rollbacks or the code they ran on copies (every case's own sends counted and moved aside where the case made them: 104-5's, 104-6's, 104-7's)"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )" "" "every weakened copy, edit of the stand-ins, injected pause and planted edit — this rehearsal's and those of the rehearsals it runs — found its anchor exactly once"
rm -f "$SB/anchor-missing"   # the controls below read their own record alone
M="$(weak104 anchorprobe104 no-such-entry 2>/dev/null)"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )" "weak104:no-such-entry " "control on the control: a weakened copy asked for an entry the table does not hold is recorded as such (weak104)"
rm -f "$SB/anchor-missing" "${M:?}"
M="$(weak104 anchorprobe104b w3a)"
cp "$DEPLOY104" "$SB/twice104.sh"; printf '%s\n' "$(anc104 w3a)" >> "$SB/twice104.sh"; TW104="$(sha256sum "$SB/twice104.sh" | cut -c1-64)"
TWR104="$(python3 -I - "$SB/twice104.sh" "$(anc104 w3a)" "$(rep104 w3a)" <<'PY' 2>/dev/null && echo applied || echo refused
import sys
f, a, b = sys.argv[1:4]; s = open(f, encoding='utf-8').read()
assert a and s.count(a) == 1, (a[:60], s.count(a))
open(f, 'w', encoding='utf-8').write(s.replace(a, b))
PY
)"
check "$(grep -cxF "$(rep104 w3a)" "$M")|$(grep -cxF "$(anc104 w3a)" "$M")|$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )|$TWR104|$( [ "$(sha256sum "$SB/twice104.sh" | cut -c1-64)" = "$TW104" ] && echo unchanged)" "1|0||refused|unchanged" \
  "control on the control: a weakened copy replaces its anchor exactly once (W3's copy holds its no-pause line once, the original pause line nowhere), and the same replacement, given a script holding that anchor twice, refuses and changes nothing"
rm -f "$SB/anchor-missing" "${M:?}" "$SB/twice104.sh"
check "$(ls -a "$REPO/scripts" | grep -E '^\..+\.sh$' | grep -vx '\.qpin\.sh' | paste -sd, -)" "" "no weakened, planted or re-pinned copy is left in the clone's scripts/ — the quarantine's one pinned copy aside"
check "$( [ -f "$SB/bad-runs" ] && tr '\n' ' ' < "$SB/bad-runs" )" "" "every run took the options it was given — none answered 'unknown option'"
OUT="$(run104 --after-only --rehearsal-unknown-option)"
check "$(has "$OUT" 'unknown option: --rehearsal-unknown-option')$( [ -s "$SB/bad-runs" ] && echo recorded)" "yesrecorded" "control on the control: a run given an option the script does not know is recorded as such"
rm -f "$SB/bad-runs"
OUT="$(run104 --rollback --rollback-unpriced-pending)"
check "$(has "$OUT" 'unknown option: --rollback-unpriced-pending')$(has "$OUT" 'PASSED')|$(live)" "yesno|$P103" "…and --rollback-unpriced-pending, retired with QL-RB2 (5.18.103), is still an option the script does not know: refused before anything runs"
rm -f "$SB/bad-runs"
# ── Across every run of the 5.18.104 script: the copy observers, the token copies, the stand-in uCRM's requests, the live
# vault — as 103-10, this release's folders. 5.18.104's own readings (QF_PHP, VN_PHP, OF_PHP, AC_PHP) are judged by mode
# (qfaud104): those that read live files by design with the live vault unchanged, every other on its copy and its vault. ──
tok104() { python3 "$H104/rh104.py" tok99 "$R104/tok/run-$1.tok"; }   # tok104 TAG — remote=<n> copied=<n> left=<n>
OBS_BAD=0; OBS_N=0; TOK_BAD=0; GET_BAD=0; ODD_BAD=0; LIM_BAD=0; QFA_BAD=0
for rec in "$R104"/exec/run-*.rec; do
  t="${rec##*/run-}"; t="${t%.rec}"; OBS_N=$((OBS_N+1))
  a="$(audit104 "$t")"; case "$a" in *" novault=0 plugin=0 "*) ;; *) OBS_BAD=$((OBS_BAD+1)) ;; esac
  case "$(qfaud104 "$t")" in *" bare=0 "*) ;; *) QFA_BAD=$((QFA_BAD+1)) ;; esac
  [ "$(hits104 "$t")" = "0" ] || OBS_BAD=$((OBS_BAD+1))
  [ "$(cat "$R104/vault/run-$t.before")" = "$(cat "$R104/vault/run-$t.after")" ] || OBS_BAD=$((OBS_BAD+1))
  case "$(tok104 "$t")" in *" left=0") ;; *) TOK_BAD=$((TOK_BAD+1)) ;; esac
  [ "$(uother104 "$t")" = "-" ] || GET_BAD=$((GET_BAD+1))
  case "$(ureq104 "$t")" in *" odd=0") ;; *) ODD_BAD=$((ODD_BAD+1)) ;; esac
  case "$(lim104 "$t")" in *" bad=0") ;; *) LIM_BAD=$((LIM_BAD+1)) ;; esac
done
check "$( [ "$OBS_N" -ge 40 ] && echo many)|$OBS_BAD|$QFA_BAD|$TOK_BAD|$GET_BAD|$ODD_BAD|$LIM_BAD" "many|0|0|0|0|0|0" \
  "10 across every run of the 5.18.104 script — the checks, the refusals, the weakened copies, the deploys and redeploys, the after-onlys and the rollbacks — the copy observers were quiet, every older reading on a copy had a vault of its own and 5.18.104's own readings a vault or a live-file mode by design, every token copy was gone at the call after its probe, and the stand-in uCRM received nothing but GETs of the shapes the release reads, its lists always with limit=500 — no e-mail asked of it"
check "$(vt_host)|$(grep -cxF "$W96" "$DATA/webhook_secret")" "$VT99_0|1" "…the live vault as it was before the first run of 5.18.99's rehearsal, bytes and time; the planted webhook secret where it was planted"
# ── The sweeps: every terminal, log, state file, row file, sheet and request record of every 5.18.104 run, and the
# rehearsal's own records of them (its copies of 104-7's terminals, its orchestrators' and actors' logs). ──
sweep_files104() { ls -1 "$R104"/run-*.txt "$R104"/line-*.txt "$R104"/mutants/run-*.txt "$R104"/logs/*.log "$R104"/logs/rh5-*.txt "$R104"/logs/orch-*.txt "$R104"/logs/case-*/* "$R104/sf.txt" "$OUT104"/*.log "$OUT104"/state-5.18.104.env* "$OUT104"/rollback-state-* "$OUT104"/cih-rows-* "$SALES104"/*.log "$REPORT104"/*.log 2>/dev/null | grep -v '/cash-[^/]*\.txt$'; }
sheets104() { ls -1 "$R104"/sheets/cash-*.txt "$R104"/logs/case-*/cash-*.txt 2>/dev/null; }
terms104() { ls -1 "$R104"/run-*.txt "$R104"/line-*.txt "$R104"/mutants/run-*.txt "$R104"/logs/*.log "$R104"/logs/rh5-*.txt "$OUT104"/*.log "$SALES104"/*.log 2>/dev/null; }
reqs104() { ls -1 "$R104"/ucrm/run-*.req 2>/dev/null; }
forms104() { python3 "$H104/rh104.py" forms "$SB/forms104.txt" "$SB/seed96.amounts" "$SB/seed97.amounts" "$SB/stmt97.amounts" "$SB/seed98.amounts" "$AMOUNTS102" "$H97"/cash-*.json "$H98"/cash-*.json "$H99"/cash-*.json "$R97"/sheets/cash-*.txt "$R98"/sheets/cash-*.txt "$R99"/sheets/cash-*.txt "$R100"/sheets/cash-*.txt "$R101"/sheets/cash-*.txt "$R102"/sheets/cash-*.txt "$R103"/sheets/cash-*.txt "$R104"/sheets/cash-*.txt "$OUT97"/cash-*.txt "$OUT98"/cash-*.txt "$OUT99"/cash-*.txt "$OUT100"/cash-*.txt "$OUT101"/cash-*.txt "$OUT102"/cash-*.txt "$OUT103"/cash-*.txt >/dev/null 2>&1; }
figs104() { forms104; python3 "$H104/rh104.py" hits "$SB/forms104.txt" "$@"; }   # figs104 FILE… — how many hold a figure of the book or a seeded price, in any printed form
check "$( [ "$(sweep_files104 | grep -c .)" -ge 60 ] && echo many)|$( [ "$(sheets104 | grep -c .)" -ge 8 ] && echo sheets)|$(forms104; grep -c . "$SB/forms104.txt" | awk '{print ($1 >= 100 ? "forms" : "few:" $1)}')|$( [ "$(reqs104 | grep -c .)" -ge 40 ] && echo records)|$(stat -c %a $(sheets104) 2>/dev/null | sort -u | paste -sd, -)" \
      "many|sheets|forms|records|600" \
  "the sweep has material: every run's terminal and log of this release's, the state files and row files, the rehearsal's own copies of them; its runs' sheets, each mode 0600; a hundred and more printed forms of the book's figures; every run's request record"
check "$(figs104 $(sweep_files104))" "0" "10a no figure of the book, of a kept statement or of a seeded price — in any printed form — in any terminal, log, state file or row file of any run of the 5.18.104 script"
# 5.18.102's "nowhere" names, on the sheets less its one unoffered plan: since 5.18.103 the catalogue's reading (QC) names a
# plan it does not offer on the 0600 sheet by design ("not offered: service plan #… — no uCRM product is spelled like…"),
# so that one name is the sheets' (trial 3: 73 sheets); everywhere else, and every other nowhere name on the sheets, nowhere.
NWS104="$SB/nowhere104-sheets.pat"; grep -v '^RH102QSECRET-Plan-Zeta-' "$NOWHERE102" > "$NWS104"; chmod 600 "$NWS104"
check "$(sweep_files104 | xargs grep -lF -f "$NAMES104" 2>/dev/null | grep -c .)|$(sweep_files104 | xargs grep -lF -f "$NAMES102" 2>/dev/null | grep -c .)|$( { sweep_files104; reqs104; } | xargs grep -lF -f "$NOWHERE102" 2>/dev/null | grep -c .)|$(sheets104 | xargs grep -lF -f "$NWS104" 2>/dev/null | grep -c .)|$(names100 $(sweep_files104) $(sheets104))|$(( $(grep -c . "$NOWHERE102") - $(grep -c . "$NWS104") ))" \
      "0|0|0|0|0|1" \
  "10b no RH104SECRET- name, phone, e-mail or quotation number of this rehearsal's (staff by first name, customers, the redeploy cases' callers and warm conversations) and no earlier release's seeded name in any terminal, log, state file or row file; the names meant for nowhere nowhere — request records included, and on the sheets every one but the unoffered plan 5.18.103's catalogue reading names there (exactly one name set aside); no staff record's name"
grep -v '^$' "$SEC95" > "$SB/sec104.pat" 2>/dev/null
check "$( { sweep_files104; sheets104; reqs104; } | xargs grep -lF -f "$SCAN96" 2>/dev/null | grep -c .)|$( { sweep_files104; sheets104; } | xargs grep -lF -f "$SB/sec104.pat" 2>/dev/null | grep -c .)|$(secrets99 $(sweep_files104) $(sheets104) $(reqs104))|$( { sweep_files104; sheets104; reqs104; } | xargs grep -lF -f "$SB/secrets104.pat" 2>/dev/null | grep -c .)|$(grep -c . "$SB/secrets104.pat")" \
      "0|0|0|0|$(grep -c . "$SB/secrets104.pat")" \
  "10c no output, log, state file, sheet or request record holds a planted secret (the webhook secret, a vault credential, a repair key, a planted Splynx or pdf value), a stand-in uCRM token or app key, the webhook's token, or an OpenAI key of this rehearsal's (the fictitious ones of 104-5v and 104-7v, never printed)"
# Ids only on the sheets: every line of a 5.18.104 sheet that carries an id (a number after "#" or "id") is a fixed string no
# terminal or log may hold — the sheets themselves the positive control.
python3 -I - "$SB/ids104.pat" $(sheets104) <<'PY'
import re, sys
out, files = sys.argv[1], sys.argv[2:]
seen = set()
for f in files:
    try:
        for l in open(f, encoding='utf-8', errors='replace'):
            l = l.rstrip('\n')
            if len(l.strip()) >= 12 and re.search(r'(#|\bid[s]?\b[^0-9]{0,12})\d+', l):
                seen.add(l.strip())
    except OSError:
        pass
open(out, 'w', encoding='utf-8').write(''.join(x + '\n' for x in sorted(seen)))
PY
chmod 600 "$SB/ids104.pat"
check "$(noids99 $(sweep_files104))|$(bankhits98 $(sweep_files104))|$(if [ -s "$SB/ids104.pat" ]; then sweep_files104 | grep -v '/state-\|/rollback-state-\|/cih-rows-' | xargs grep -lF -f "$SB/ids104.pat" 2>/dev/null | grep -c .; else echo none; fi)|$( [ -s "$SB/ids104.pat" ] && sheets104 | xargs grep -lF -f "$SB/ids104.pat" 2>/dev/null | grep -c . | awk '{print ($1 >= 1 ? "sheets" : "none")}' || echo none)" \
      "0|0|0|sheets" \
  "10d no output, log or state file names a client id the app holds or an account name or number, and no line of a sheet that carries an id (staff, offer, event, client) appears in any terminal or log — ids on the 0600 sheets alone (positive: the sheets hold those lines)"
printf 'planted %s, %s, %s, client %s, %s, %s and %s\n' "$(grep -m1 '^2:' "$SB/forms104.txt" | cut -c3-)" "$W96" "$(head -1 "$U99_SECRETS")" "$(head -1 "$SB/ids99.pat")" "RH100SECRET-Tech-One" "$(sed -n 2p "$NAMES104")" "$(head -1 "$SB/secrets104.pat")" > "$R104/zz-sweep-control.txt"
printf 'sheet line %s\n' "$(head -1 "$SB/ids104.pat")" > "$R104/zz-ids-control.txt"
check "$(figs104 "$R104/zz-sweep-control.txt")$(grep -lF -f "$SCAN96" "$R104/zz-sweep-control.txt" | grep -c .)$(secrets99 "$R104/zz-sweep-control.txt")$(noids99 "$R104/zz-sweep-control.txt")$(names100 "$R104/zz-sweep-control.txt")$(grep -lF -f "$NAMES104" "$R104/zz-sweep-control.txt" | grep -c .)$(grep -lF -f "$SB/secrets104.pat" "$R104/zz-sweep-control.txt" | grep -c .)$( [ -s "$SB/ids104.pat" ] && grep -lF -f "$SB/ids104.pat" "$R104/zz-ids-control.txt" | grep -c . || echo 0)" "11111111" \
  "control on the sweeps: a file holding one figure form, the planted secret, a stand-in secret, a client id, a seeded staff name, one of this rehearsal's names or phones, one of its tokens or keys, and one holding a sheet's id line, is found by all eight"
rm -f "$R104/zz-sweep-control.txt" "$R104/zz-ids-control.txt" "$SB/sec104.pat"
rterr104() {   # rterr104 FILE… — how many of the files hold a bash runtime error of a script ("<x>.sh: line N: …")
  [ "$#" -gt 0 ] || { echo none; return; }
  grep -lE '\.sh: line [0-9]+: .*(bad substitution|unbound variable|syntax error|command not found|integer expression expected)' "$@" 2>/dev/null | grep -c .
}
printf '%s\n' 'X=1; Y=2' 'echo "a:${X:+ the copy'"'"'s:$X}${Y:+ the base'"'"'s:$Y} end"' > "$SB/rterr104.sh"
bash "$SB/rterr104.sh" > "$R104/zz-rterr-control.txt" 2>&1
check "$(rterr104 $(terms104))|$(rterr104 "$R104/zz-rterr-control.txt")" "0|1" \
  "10f no terminal or log of any run of the 5.18.104 script (or of its wrappers) holds a bash runtime error; control: a script with an apostrophe inside \${…:+…} is caught"
rm -f "$SB/rterr104.sh" "$R104/zz-rterr-control.txt"
printf '%s\n' '  ok    QL-CA1 the catalogue' '  note  QL-RB2 counted' '  FAIL  QL-LK1 not every item' > "$R104/zz-retired-control.txt"
check "$(terms104 | xargs grep -lE '^  (ok    |FAIL  |note  )QL-(CA1|LK1|RB[123]) ' 2>/dev/null | grep -c .)|$(grep -cE '^  (ok    |FAIL  |note  )QL-(CA1|LK1|RB[123]) ' "$R104/zz-retired-control.txt")|$(terms104 | xargs grep -lE '^  (ok    |note  )QF-' 2>/dev/null | grep -c . | awk '{print ($1 >= 10 ? "qf" : "few:" $1)}')" "0|3|qf" \
  "10g no terminal or log of any run says a retired check (5.18.102's QL-CA1, QL-LK1, QL-RB1–QL-RB3) under its name — 5.18.104's own QF lines everywhere; control: each retired line is counted"
rm -f "$R104/zz-retired-control.txt"
pz104() {   # pz104 — files naming RH104_PAUSE in the installed plugin, the rehearsal's folders and the clone's scripts/, less
            # this rehearsal's own source in the clone (committed, it names RH104_PAUSE by design: the injector, its asserts
            # and actor104's env), then "/own" when that copy is absent or byte-identical to the running rehearsal, else
            # "/changed" — so setting it aside can never hide a pause. 10h's bare count read 1 for that copy in trials 4 and 5,
            # whose clone devres moved forward onto a tree holding it (trials 2 and 3: the clone at 9b9cd3a, no copy, 0)
  local own="$REPO/scripts/harness/deploy-5.18.104/rehearse.sh"
  echo "$(grep -rl 'RH104_PAUSE' "$PD" "$H104" "$REPO/scripts" 2>/dev/null | grep -vxF "$own" | grep -c .)/$( { [ ! -e "$own" ] || cmp -s "$own" "$H104_DIR/rehearse.sh"; } && echo own || echo changed)"
}
check "$(ls -A "$SB/web" | grep -cE '^\.(redirect104|left104m)$')|$(ls -A "$SB" | grep -cE '^\.(rbfail104|rbfail104\.seen|tl104|rhf104\..*)$')|$(ls -A "$SB/evo" 2>/dev/null | grep -cE '^rh104-(slow|media)')|$( [ -e "$H104/act104" ] || [ -e "$H104/pin104" ] && echo left || echo gone)|$(pz104)|$( [ -e "$R104/cron104.on" ] || [ -e "$R104/cronv104.on" ] && echo on || echo off)|$(pgrep -f "$H104/act104|$PD/run_(media_)?worker\.php|fake_openai_server\.php" 2>/dev/null | grep -c .)" \
      "0|0|0|gone|0/own|off|0" \
  "10h no trigger of this rehearsal's left armed — the stand-in's, the fake docker's, the moments hook's folder, the slow stand-in's files; no actor's copy and no injected pause anywhere (the installed plugin, the rehearsal's folders, the clone's scripts — this rehearsal's own committed source in the clone set aside, and only while it is absent or byte for byte the one running: /own; a run from uncommitted edits reads /changed); the cron stand-ins stopped; no worker, actor or fake OpenAI still running"
printf '%s\n' "<?php if ((string)getenv('RH104_PAUSE') !== '') { }" > "$REPO/scripts/.pz104-control.php"
check "$(pz104)" "1/own" "control on 10h's pause sweep: a file naming RH104_PAUSE planted in the clone's scripts/ is counted — only this rehearsal's own committed source, unchanged, is set aside"
rm -f "$REPO/scripts/.pz104-control.php"
cp -p "$REPO/scripts/harness/deploy-5.18.104/rehearse.sh" "$SB/pz104.keep" && echo '# pz104 control' >> "$REPO/scripts/harness/deploy-5.18.104/rehearse.sh"
check "$(pz104)" "0/changed" "control on the control: the clone's copy of this rehearsal, altered, is no longer its own — the sweep reads /changed"
cp -p "$SB/pz104.keep" "$REPO/scripts/harness/deploy-5.18.104/rehearse.sh"; rm -f "$SB/pz104.keep"
check "$(pz104)" "0/own" "…and put back, it is its own again"
check "$(live)|$(left104)|$(kv104 "$(python3 "$H104/rh104q.py" keysset "$DATA/plugin.sqlite3" "$DATA/kyc_config.json" "$ALLCSV104")" set)|$(set99 ucrm_quote_organization_id)|$(u99_state_102)|$(mig104 | cut -d' ' -f1)" \
      "$P103|$NA104|0|unset/unset|base|mig=$(tr ',' '\n' <<<"$MIGF104" | grep -c .)/$(tr ',' '\n' <<<"$MIGF104" | grep -c .)" \
  "10i the sandbox left as the last rollback left it: $P103 serving, the $NA104 files $V104 adds on disk, no key of allKeys() set, the quoting organization's setting unset, the stand-in uCRM at its base, 089–091 in the ledger (a later rehearsal finds a book $V104 ran on, as production would after a rollback)"
printf '%s\n' "      $EXP_ROLLBACK104" "" "  $V104 (deploy): PASSED. Send this LOG FILE back" > "$SB/order104.txt"
printf '%s\n' "      $EXP_AFTER104; $EXP_ROLLBACK104" > "$SB/block104.txt"
check "$(awk '/^  '"${V104//./\\.}"' \((deploy|after)\): / { v = 1 } index($0, "deploy-5.18.104.sh --rollback") { print (v ? "after" : "before") }' "$SB/order104.txt" | paste -sd, -)|$(grep -cE '(deploy-5\.18\.104\.sh|quarantine-stray-store\.sh|deploy-hybrid\.sh|sales-followup\.sh).*(deploy-5\.18\.104\.sh|quarantine-stray-store\.sh|deploy-hybrid\.sh|sales-followup\.sh)' "$SB/block104.txt")|$(grep -cxF "      $EXP_ROLLBACK104" "$SB/block104.txt")|$(python3 "$H104/rh104q.py" blocks "$SB/block104.txt" | sed -nE 's/.*mixed=([0-9]+).*/\1/p')" \
      "before|1|0|1" \
  "control on the controls of 4f, 6 and 6w: a rollback printed before the verdict reads \"before\"; a deploy and a rollback on one line are counted, that line is not the rollback alone, and the block reader counts it mixed — the deploy and its rollback are never in one copyable block"
rm -f "$SB/order104.txt" "$SB/block104.txt"
check "$(left_tmp104)$(host_code104)$(ls -d /tmp/dnb-backup-* 2>/dev/null | grep -c .)$(find /tmp -maxdepth 6 -path '/tmp/dnb-5.18.104-*' -name token.json 2>/dev/null | grep -c .)$(cache104)$(ls -A "$H104/v" | grep -cvE '^(seed|ref)\.err$|^ref-vault\.json$')" "000000" \
  "no run folder, code copy, report folder, backup copy, token copy or page cache of this release's left in /tmp, in the data folder or on the host; no record file or vault of this rehearsal's writers left but the reference's own vault copy"
rm -f "$H104/v/ref-vault.json"
check "$(grep -c 'Fatal\|Parse error' "$SB/web.log")|$(grep -c 'Fatal\|Parse error' "$U99/server.log")" "0|0" "the stand-in plugin and the stand-in uCRM served every request without a PHP fatal"
check "$(checkout_state)" "$CHECKOUT0" "this checkout is as the rehearsal found it: same commit, same tracked files"

echo
echo "RESULT: $PASS ok, $FAILN failed  (the script: $WHICH104)"
[ "$FAILN" = "0" ] || echo "  $FAIL_PRE104 of the $FAILN failed in the earlier releases' rehearsals, before this release's own sections. Their scripts are records, left as they are; a refusal of theirs at a check read off a pipeline whose reader exits early is a SIGPIPE false stop, not a defect of 5.18.104's. Read the first FAIL; if it is one, run the rehearsal again — it is never a pass."
[ "$FAILN" = "0" ]
