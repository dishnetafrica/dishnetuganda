#!/usr/bin/env bash
#
# Rehearse scripts/deploy-5.18.101.sh — the WhatsApp Quotation & Order Summary sent on uCRM's quote.add signs off on
# Uganda as "🛰️ *DishNet Uganda — Starlink, installation & support*" instead of "🚀 *DishNet Internet Services*" (one line
# of webhook.php, behind the gate the tax line uses; South Sudan's line as before) — over the live release, 5.18.100,
# before the operator runs it.
#
# The sandbox is the previous release's rehearsal's, built by its own code: scripts/harness/deploy-5.18.100/rehearse.sh,
# read up to just before its own last section (what it left behind, its sweeps) — the cut asserted: its anchor exactly
# once, its last check the one named, and the one line that names its own folder pointed at that folder, also exactly
# once — and run here as it is, which builds every earlier sandbox the same way, from their own rehearsals. By the time
# this file's own sections begin, 5.18.100 has been deployed, rolled back and deployed again with --redeploy there, its
# copy moved half an hour back, its --after-only passed (R19 conclusive) and the quarantine's own --check passed after it:
# production's state on the day. Its staff records stay: four of 5.18.100's rehearsal's (a technician among them, every
# name a marked secret of that rehearsal's, RH100SECRET-…) and one number in the registry that a technician owns
# (sales-901) — so the owner checks this release carries have something to count. 5.18.100's own reading of who the card
# may offer (rh100-oracle.php) is used again here, read-only.
#
# This rehearsal proves:
#   · the script is pinned to the release commit on release/5.18.101 (short and full), cut on 5.18.100; the delta is
#     exactly the thirteen reviewed files, none added, each file's sha256 as the script pins it, computed here from Git;
#     the sixty-three kept files by theirs in both commits, the thirteen changed ones by their previous form; no
#     migration; T1 and T4 pinned as before; the header's two commands, each alone; the copy's time read off a file this
#     release changes; the sign-off reader and the owner-rule reader give the pin and 5.18.100 what the script expects (0);
#   · --check is GO on 5.18.100 as production has it: QS-G1, and 5.18.100's TN-G1–TN-G3 carried, TN-G2's counts this
#     rehearsal's own reading's; nothing changed (c);
#   · QS-G1 refuses a release that sends the Uganda line to every tenant, re-pinned so that A0 lets it through, and its
#     weakened copy is caught; the same release re-pinned short and full only is refused at A0; TN-G3, carried, still
#     refuses wa_inbox_roles naming a technician role; a hand-edited installed webhook.php is refused before anything
#     changes; nothing changed by any of them (1);
#   · the deploy, DEPLOY typed, PASSES: QS-G1, QS-CA1, the carried checks, stage A's readings in the state file, the copy's
#     time as stamp_copy gave webhook.php, no staff record's name printed (4);
#   · --after-only as printed PASSES; webhook.php hand-edited back to the old line FAILS QS-CA1, and QS-CA1's weakened copy
#     no longer says so; --check with 5.18.101 live refused (5);
#   · the rollback as printed, ROLLBACK typed: PASSED — 5.18.100's files by sha256, its sign-off, its owner rule and its
#     quoting code in place, the technician's number counted; a --redeploy before the quarantine's --check refused at A14
#     (6);
#   · --redeploy after the rollback: PASSED, BR1 with no file of this release's on disk; its --after-only PASSES (7);
#   · nothing reaches Evolution; across every run of the 5.18.101 script the copy observers were quiet, every token copy
#     gone, the stand-in uCRM given GETs only, the live vault as it was; no name of a staff record, no figure of the book,
#     no planted or stand-in secret, no client id, no bash runtime error in any output, log or state file of this
#     release's; no code copy or run folder left in /tmp or on the host; every anchor of this rehearsal and of those it
#     runs found, every run given known options, each with its control on the control; this checkout as found (10).
#     The earlier releases' runs, run again here, are swept by their own rehearsals, as 5.18.100's swept its own alone.
#
# Everything runs in a sandbox; it never touches this checkout, the server or the network, and refuses to run where the
# server could be. Everything seeded is fictitious.
#
# Left as found, knowingly: the earlier releases' scripts that the earlier rehearsals run, and that this one does not run
# again, are records, each pinned byte for byte by its own rehearsal; those up to 5.18.98's still judge statuses read off
# pipelines whose reader exits early, and can stop falsely at a small measured rate. Such a stop fails the rehearsal — it
# is never a pass — and is not a defect of 5.18.101's: the last line says how many failures came before this release's
# own sections.
#
#   bash scripts/harness/deploy-5.18.101/rehearse.sh            REHEARSE_KEEP=<dir> keeps every run's full output
set -u
for f in /data/ucrm /opt/dishnet /var/run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
H101_DIR="$(cd "$(dirname "$0")" && pwd)"
H101_ROOT="$(cd "$H101_DIR/../../.." && pwd)"
F101=scripts/deploy-5.18.101.sh
P101_EARLY="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT="\([^"]*\)".*/\1/p' "$H101_ROOT/$F101" 2>/dev/null)"
P101F_EARLY="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT_FULL="\([^"]*\)".*/\1/p' "$H101_ROOT/$F101" 2>/dev/null)"
T1_EARLY101="$(sed -n 's/^T1_SIGNATURE="\([^"]*\)".*/\1/p' "$H101_ROOT/$F101" 2>/dev/null)"
case "$P101_EARLY" in __*|'') echo "refusing: $F101 is not pinned to a release commit (${P101_EARLY:-none})"; exit 2;; esac
case "$P101F_EARLY" in "$P101_EARLY"*) [ "${#P101F_EARLY}" = "40" ] || { echo "refusing: $F101 does not pin its release commit in full"; exit 2; } ;; *) echo "refusing: $F101's full pin does not begin with its short one"; exit 2;; esac
case "$T1_EARLY101" in [1-9]*' passed, 0 failed rc=0') ;; *) echo "refusing: $F101 does not pin T1's count (T1_SIGNATURE=${T1_EARLY101:-none})"; exit 2;; esac
H100_HARNESS="$H101_DIR/../deploy-5.18.100/rehearse.sh"
H101_PREFIX="$(mktemp)"
python3 - "$H100_HARNESS" "$H101_PREFIX" <<'PY' || { echo "refusing: the previous release's rehearsal could not be cut before its last section (its sweeps)"; rm -f "$H101_PREFIX"; exit 2; }
import sys
src, dst = sys.argv[1], sys.argv[2]
anchor = 'echo; echo "== 100-10. what the rehearsal left behind, and what it never reached =="'
lines = open(src, encoding='utf-8').read().split('\n')
at = [i for i, l in enumerate(lines) if l == anchor]
assert len(at) == 1, len(at)
assert at[0] > 450, at[0]                   # its deploy, rollback and --redeploy come before it
pre = '\n'.join(lines[:at[0]]) + '\n'
# That rehearsal finds its own helpers beside itself ($0's folder): sourced from here, $0 is this file, so its one line
# naming that folder points at its own folder instead — the one edit, asserted.
old_dir = 'H100_DIR="$(cd "$(dirname "$0")" && pwd)"\n'
new_dir = 'H100_DIR="$(cd "$(dirname "$0")/../deploy-5.18.100" && pwd)"   # the next release\'s rehearsal: this one\'s own folder, where its helpers are\n'
assert pre.count(old_dir) == 1, pre.count(old_dir)
pre = pre.replace(old_dir, new_dir, 1)
assert pre.count(new_dir) == 1 and pre.count(old_dir) == 0
for need in ('H99_HARNESS="$H100_DIR/../deploy-5.18.99/rehearse.sh"', 'run100() {', 'run_line100() {', 'fresh100() {', 'nogo100() {',
             'went100() {', 'quiet100() {', 'checkgo100() {', 'audit100() {', 'hits100() {', 'vault100() {', 'uother100() {',
             'copy_back100() {', 'release_variant100() {', 'pinned_copy100() {', 'installed100() {', 'o100() {', 'names100() {',
             'row_keep100() {', 'row_back100() {', 'OUT100="$SB/out100"; mkdir -p "$OUT100"; STATE100="$OUT100/state-5.18.100.env"',
             'R100="$SB/r100"', 'H100="$SB/rh100"', 'REL100=release/5.18.100; DEPLOY100="$REPO/$F100"', 'LOGW100() {',
             'echo; echo "== 100-7. --redeploy after the rollback =="',
             "TECH100=\"$(sq \"SELECT id FROM retailers WHERE json_extract(data, '\\$.name') = 'RH100SECRET-Tech-One'\" 2>/dev/null)\""):
    assert pre.count(need) >= 1, need
last = [l for l in pre.split('\n') if l.strip()][-1]
assert last.startswith('check "$(has "$OUT" \'stray-store quarantine (check): PASSED.\')" "yes" "7d the quarantine\'s own --check, as the runbook orders: PASSED"'), last[:90]
open(dst, 'w', encoding='utf-8').write(pre)
PY
# shellcheck disable=SC1090
. "$H101_PREFIX"
rm -f "$H101_PREFIX"
for f101 in mutant checkout_state row_set sqx sq qj okline failline noteline has fails live data94 kept_state oldname printed qrun \
            fresh99 copy_back99 epoch99 set_sched base_log utc fp99 pinned_copy99 secrets99 noids99 bankhits98 vt_host ledger95 \
            both_copies evo_calls inst_ver ver dl o100 names100 row_keep100 row_back100 release_variant100 pinned_copy100 installed100; do
  declare -F "$f101" >/dev/null || { echo "refusing: the rehearsals this one runs did not define $f101()"; exit 2; }
done
FAIL_PRE101=$FAILN   # the earlier rehearsals' failures, before this release's own sections: the last line names them

# ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
# 5.18.101's own rehearsal, on the sandbox built above: 5.18.100 serving after 100-7's --redeploy, its --after-only and
# the quarantine's --check passed — as production has it.
# ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
REL101=release/5.18.101; DEPLOY101="$REPO/$F101"
CHANGED101="SAFETY.md manifest.json tests/test_bank_boundaries.php tests/test_cashbook_physical_list.php tests/test_distributor_apply.php tests/test_distributor_link_ucrm.php tests/test_distributor_notify.php tests/test_distributor_registry.php tests/test_distributor_territory.php tests/test_quote_company.php tests/test_quote_summary.php tests/test_sales_numbers.php webhook.php"
N101=$(wc -w <<<"$CHANGED101")                       # 13
if git -C "$R" ls-files --error-unmatch "$F101" >/dev/null 2>&1; then
  cmp -s "$R/$F101" "$DEPLOY101" || { echo "refusing: $F101 differs from the committed one — commit it, then rehearse"; exit 2; }
  WHICH101="the committed script"
else cp "$R/$F101" "$DEPLOY101"; WHICH101="the working copy (not yet committed; untracked in the clone)"; fi
git -C "$REPO" fetch -q origin "refs/heads/$REL101:refs/remotes/origin/$REL101" 2>/dev/null || true
P101="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT="\([^"]*\)".*/\1/p' "$DEPLOY101")"
P101F="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT_FULL="\([^"]*\)".*/\1/p' "$DEPLOY101")"
OUT101="$SB/out101"; mkdir -p "$OUT101"; STATE101="$OUT101/state-5.18.101.env"
R101="$SB/r101"; mkdir -p "$R101/logs" "$R101/mutants" "$R101/sheets" "$R101/exec" "$R101/scan" "$R101/vault" "$R101/tok" "$R101/ucrm"
H101="$SB/rh101"; mkdir -p "$H101"
LOGW101() { printf '2>&1 | ( umask 077; exec tee %s/%s-%s.log )' "$OUT101" "$1" "$DATE_TS98"; }   # the script's LOGW, as it prints it
EXP_AFTER101="cd $REPO && bash scripts/deploy-5.18.101.sh --after-only $(LOGW101 after)"
EXP_ROLLBACK101="cd $REPO && bash scripts/deploy-5.18.101.sh --rollback $(LOGW101 rollback)"
EXP_REDEPLOY101="cd $REPO && bash scripts/deploy-5.18.101.sh --redeploy $(LOGW101 redeploy)"
left_tmp101() { ls -d /tmp/dnb-5.18.101-* 2>/dev/null | wc -l | tr -d ' '; }
host_code101() { ls -d "$OUT101"/code-* "$OUT101"/p3b-* "$OUT101"/br1-* "$OUT101"/domainb-* "$OUT101"/tn-* "$OUT101"/qs-* 2>/dev/null | wc -l | tr -d ' '; }
backups101() { ls -d "$OUT101"/backup-* 2>/dev/null | wc -l | tr -d ' '; }
states101() { ls -1 "$OUT101"/state-5.18.101.env* "$OUT101"/rollback-state-* 2>/dev/null | sort | while IFS= read -r f; do printf '%s %s\n' "${f##*/}" "$(sha256sum "$f" | cut -c1-16)"; done | sha256sum | cut -c1-16; }
fresh101() {   # the run folder as a new run finds it: the state files and the row digests kept; every log and sheet moved aside
  find "$OUT101" -mindepth 1 -maxdepth 1 -name '*.log' -exec mv -t "$R101/logs/" {} + 2>/dev/null
  find "$OUT101" -mindepth 1 -maxdepth 1 -name 'cash-*.txt' -exec mv -t "$R101/sheets/" {} + 2>/dev/null
  find "$OUT101" -mindepth 1 -maxdepth 1 ! -name 'state-5.18.101.env*' ! -name 'rollback-state-*' ! -name 'cih-rows-*' -exec rm -rf {} +; base_log; }
installed101() {   # installed101 COMMIT — the number of files 5.18.100 → 5.18.101 touches that are NOT installed as COMMIT has them
  local f rel bad=0
  for f in $(dl AM "$P100" "$P101"); do
    rel="${f#$P/}"
    if git -C "$REPO" cat-file -e "$1:$f" 2>/dev/null; then
      [ "$(git -C "$REPO" show "$1:$f" | sha256sum | cut -c1-64)" = "$(sha256sum "$PD/$rel" 2>/dev/null | cut -c1-64)" ] || bad=$((bad+1))
    fi
  done
  echo "$bad"
}
last101() { printf '%03d' "$(cat "$SB/runs")"; }
run101() {   # [--answer WORD] [--script FILE] [--env K=V]…, THEN the script's options — as run100, this release's folders
  local answer="" script="$DEPLOY101" extra=() n tag o cmd
  while :; do case "${1:-}" in --answer) answer="$2"; shift 2 ;; --script) script="$2"; shift 2 ;; --env) extra+=("$2"); shift 2 ;; *) break ;; esac; done
  : > "$SB/docker.log"; : > "$U99/requests.log"
  n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"; tag="$(printf '%03d' "$n")"
  vt_host > "$R101/vault/run-$tag.before"; : > "$R101/exec/run-$tag.rec"; : > "$R101/scan/run-$tag.hits"; : > "$R101/tok/run-$tag.tok"
  cmd="$(printf '%q ' env PATH="$SB/datebin:$SB/bin:$PATH" FAKE_TODAY="$AFTER94" DNB_OUT="$OUT101" DNB_RECORDS="$RECS" DNB_WEBSITE="$WEB_OK" GUARD_SECONDS=3 DN_DATA_DIR="$DATA" \
         FAKE_EXEC_LOG="$R101/exec/run-$tag.rec" FAKE_TMP_SCAN="$SCAN96" FAKE_TMP_HITS="$R101/scan/run-$tag.hits" FAKE_TOK_HITS="$R101/tok/run-$tag.tok" \
         ${extra[@]+"${extra[@]}"} bash "$script" --plugin-base "$PLUGIN_BASE" "$@")"
  o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r')"
  vt_host > "$R101/vault/run-$tag.after"; cp "$U99/requests.log" "$R101/ucrm/run-$tag.req"
  case "$o" in *"unknown option:"*) echo "run101-$n" >> "$SB/bad-runs" ;; esac
  if [ -n "${R101_MUTANT_OUT:-}" ]; then printf '%s\n' "$o" > "$R101/mutants/run-$tag.txt"; else printf '%s\n' "$o" > "$R101/run-$tag.txt"; fi
  [ -z "${REHEARSE_KEEP:-}" ] || printf '%s\n' "$o" > "$REHEARSE_KEEP/run101-$tag-${answer:-none}-$(basename "$script" .sh)$(printf '%s' "$*" | tr -c 'A-Za-z0-9-' '_').txt"
  printf '%s' "$o"
}
run_line101() {   # run_line101 WORD LINE — a command line exactly as a summary printed it, through a pseudo-terminal, WORD typed
  local answer="$1" line="$2" n tag o cmd
  : > "$SB/docker.log"; : > "$U99/requests.log"
  n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"; tag="$(printf '%03d' "$n")"
  vt_host > "$R101/vault/run-$tag.before"; : > "$R101/exec/run-$tag.rec"; : > "$R101/scan/run-$tag.hits"; : > "$R101/tok/run-$tag.tok"
  cmd="$(printf '%q ' env PATH="$SB/datebin:$SB/bin:$PATH" FAKE_TODAY="$AFTER94" DNB_OUT="$OUT101" DNB_RECORDS="$RECS" DNB_WEBSITE="$WEB_OK" GUARD_SECONDS=3 DN_DATA_DIR="$DATA" PLUGIN_BASE="$PLUGIN_BASE" \
         FAKE_EXEC_LOG="$R101/exec/run-$tag.rec" FAKE_TMP_SCAN="$SCAN96" FAKE_TMP_HITS="$R101/scan/run-$tag.hits" FAKE_TOK_HITS="$R101/tok/run-$tag.tok" bash -c "$line")"
  o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r')"
  vt_host > "$R101/vault/run-$tag.after"; cp "$U99/requests.log" "$R101/ucrm/run-$tag.req"
  case "$o" in *"unknown option:"*) echo "line101-$n" >> "$SB/bad-runs" ;; esac
  printf '%s\n' "$o" > "$R101/line-$tag.txt"
  [ -z "${REHEARSE_KEEP:-}" ] || printf '%s\n' "$o" > "$REHEARSE_KEEP/line101-$tag-${answer:-none}.txt"
  printf '%s' "$o"
}
went101() { echo "$(has "$1" 'GO — evidence recorded')$(has "$1" 'Type DEPLOY')"; }   # the run reached the question
quiet101() { echo "$(live)|$(backups101)|$(data94)|$(kept_state)|$(oldname)"; }     # nothing deployed, backed up or changed
nogo101() {   # nogo101 LABEL EXPECTED-TEXT OUTPUT [DATA-DIGEST] — refused with that text and "Nothing was changed", never the
              # question, nothing deployed, backed up or changed: 5.18.100 serving
  check "$(has "$3" "$2")$(has "$3" 'Nothing was changed. Send the log file.')$(went101 "$3")|$(quiet101)" "yesyesnono|$P100|0|${4:-$D101}|$KEPT101|0" "$1"
}
checkgo101() {   # checkgo101 OUTPUT — --check said GO: stage A held — nothing asked, nothing backed up, no state written
  echo "$(has "$1" "  5.18.101 (check): GO — stage A holds.")$(has "$1" '  --check           stage A alone, every refusal and note of a deploy')$(went101 "$1")$(fails "$1")|$(backups101)|$(states101)"
}
audit101() { python3 "$H101/rh101.py" audit "$R101/exec/run-$1.rec"; }   # audit101 TAG — copies=<n> novault=<n> plugin=<n> adj=<id|->
hits101() { local n; n="$(grep -c . "$R101/scan/run-$1.hits" 2>/dev/null)"; echo "${n:-0}"; }
vault101() { echo "$(cat "$R101/vault/run-$1.before")>$(cat "$R101/vault/run-$1.after")"; }
uother101() { python3 "$H99/rh99.py" u99other "$R101/ucrm/run-$1.req"; }   # the requests to the stand-in uCRM that were not a GET
copy_back101() {   # the 5.18.101 copy N minutes ago: webhook.php's time (its stamp_copy, COPY_MARK), the recorded copy time,
                   # the start before it
  touch -d "-$1 minutes" "$PD/webhook.php"; local t; t="$(stat -c %Y "$PD/webhook.php")"
  sed -i -e "s/^COPY_AT=.*/COPY_AT=$t/" -e "s/^DEPLOYED_AT=.*/DEPLOYED_AT=$(date -u -d "@$(( t - 60 ))" +%Y-%m-%dT%H:%M:%SZ)/" "$STATE101"; }
ANCH101="$SB/anchors101.tsv"
cat > "$ANCH101" <<'ANCH'
qsg1	deploy	  [ "$tp" = "$QS_TEXT_PIN" ] || stop "NO-GO (QS-G1): the pin's $QS_FILE does not sign the Quotation & Order Summary off as reviewed	  true || stop "NO-GO (QS-G1): the pin's $QS_FILE does not sign the Quotation & Order Summary off as reviewed
qsca1	deploy	  else bad "QS-CA1 the installed $QS_FILE does not sign the summary off as reviewed	  else ok "QS-CA1 the installed $QS_FILE does not sign the summary off as reviewed
copymark	deploy	COPY_MARK="webhook.php"	COPY_MARK="lib/SalesNumbersAdmin.php"
ssline	webhook	($ugPlans !== false ? '🛰️ *DishNet Uganda — Starlink, installation & support*' : '🚀 *DishNet Internet Services*')	(true ? '🛰️ *DishNet Uganda — Starlink, installation & support*' : '🚀 *DishNet Internet Services*')
ANCH
a101() { awk -F'\t' -v k="$1" '$1 == k { print $3; exit }' "$ANCH101"; }
r101() { awk -F'\t' -v k="$1" '$1 == k { print $4; exit }' "$ANCH101"; }
weak101() {   # weak101 NAME KEY… — a copy of the script in the clone's scripts/ with each KEY's anchor replaced, each exactly once
  local name="$1" k dst="$REPO/scripts/.$1.sh"; shift
  cp "$DEPLOY101" "$dst"
  for k in "$@"; do
    python3 - "$dst" "$(a101 "$k")" "$(r101 "$k")" <<'PY' || echo "weak101:$k" >> "$SB/anchor-missing"
import sys
f, a, b = sys.argv[1:4]; s = open(f, encoding='utf-8').read()
assert a and s.count(a) == 1, (a[:60], s.count(a))
open(f, 'w', encoding='utf-8').write(s.replace(a, b))
PY
  done
  echo "$dst"
}
release_variant101() {   # release_variant101 PATH OLD NEW — the release commit with one file edited (OLD exactly once), cut on 5.18.100
  local idx="$SB/variant101.idx" blob tree c
  GIT_INDEX_FILE="$idx" git -C "$REPO" read-tree "$P101"
  blob="$(git -C "$REPO" show "$P101:$P/$1" | python3 -c 'import sys
s = sys.stdin.read(); old, new = sys.argv[1], sys.argv[2]
assert s.count(old) == 1, "variant anchor not unique"
sys.stdout.write(s.replace(old, new))' "$2" "$3" | git -C "$REPO" hash-object -w --stdin)"
  [ "$blob" = "$(printf '' | git -C "$REPO" hash-object --stdin)" ] && echo "release_variant101:$1" >> "$SB/anchor-missing"
  GIT_INDEX_FILE="$idx" git -C "$REPO" update-index --cacheinfo "100644,$blob,$P/$1"
  tree="$(GIT_INDEX_FILE="$idx" git -C "$REPO" write-tree)"; rm -f "$idx"
  c="$(git -C "$REPO" -c user.name=rehearsal -c user.email=rehearsal@example.test commit-tree "$tree" -p "$(git -C "$REPO" rev-parse "$P100")" -m "rehearsal variant: $1")"
  git -C "$REPO" rev-parse --short "$c"
}
pinned_copy101() {   # pinned_copy101 NAME COMMIT FILE SHA256 — pinned_copy99's copy of this release's script, pinned to COMMIT
                     # short and full, with FILE's line re-pinned in PIN_SHA256S ALONE (a file 5.18.101 changes is listed
                     # twice: the release's sha256 there, 5.18.100's in PREV_CHANGED_SHA256S, which stays as it is)
  local dst; dst="$(pinned_copy99 "$1" "$2" "$DEPLOY101")"
  python3 - "$dst" "$3" "$4" <<'PY' || echo "pinned_copy101:$1" >> "$SB/anchor-missing"
import sys, re
dst, f, sha = sys.argv[1:4]
s = open(dst, encoding='utf-8').read()
head = 'PIN_SHA256S="$(cat <<\'SUMS\'\n'
assert s.count(head) == 1, 'PIN_SHA256S'
i = s.index(head) + len(head); j = s.index('\nSUMS\n', i)
rx = re.compile(r'(?m)^[0-9a-f]{64}  ' + re.escape(f) + r'$')
assert len(rx.findall(s[i:j])) == 1, f
assert re.fullmatch(r'[0-9a-f]{64}', sha), sha
s = s[:i] + rx.sub(lambda m: sha + '  ' + f, s[i:j], count=1) + s[j:]
open(dst, 'w', encoding='utf-8').write(s)
PY
  echo "$dst"
}
# The readers: 5.18.100's, taught this release's folders in /tmp, by asserted replacements. ────────────────────────────
python3 - "$H100/rh100.py" "$H101/rh101.py" <<'PY' || { echo "readers:rh101" >> "$SB/anchor-missing"; echo "refusing: this rehearsal's readers could not be derived from the previous release's"; exit 2; }
import sys
s = open(sys.argv[1]).read()
reps = [
    ("root = re.compile(r'^/tmp/dnb-5\\.18\\.100-(?:cih-[^/]+/[^/]+/(?:t|d|p0root|k-(?:found|off|on)|c-(?:old|new)|g-(?:i|p|c0|c1)|l|u|t-(?:i|p))/dishnet-hybrid-sudan|code-[^/]+/p3b/[^/]+/dishnet-hybrid-sudan)(?:/|$)')",
     "root = re.compile(r'^/tmp/dnb-5\\.18\\.101-(?:cih-[^/]+/[^/]+/(?:t|d|p0root|k-(?:found|off|on)|c-(?:old|new)|g-(?:i|p|c0|c1)|l|u|t-(?:i|p))/dishnet-hybrid-sudan|code-[^/]+/p3b/[^/]+/dishnet-hybrid-sudan)(?:/|$)')"),
    ("if not env.get('DN_VAULT_FILE', '').startswith('/tmp/dnb-5.18.100-'): novault += 1",
     "if not env.get('DN_VAULT_FILE', '').startswith('/tmp/dnb-5.18.101-'): novault += 1"),
]
for old, new in reps:
    assert s.count(old) == 1, old[:70]
    s = s.replace(old, new)
open(sys.argv[2], 'w').write(s)
PY
# The observers search this release's folders in /tmp too (the fake docker's one list, extended, asserted), and the token
# observer counts this release's quoting folders beside 5.18.99's and 5.18.100's.
python3 - "$SB/bin/docker" <<'PY' || echo "fakedocker:observers101" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]; s = open(p).read()
old = ' /tmp/dnb-5.18.100-code-*/t4run; do\n'
assert s.count(old) == 1, s.count(old)
assert s.count('/tmp/dnb-5.18.101-') == 0
s = s.replace(old, ' /tmp/dnb-5.18.100-code-*/t4run /tmp/dnb-5.18.101-cih-* /tmp/dnb-5.18.101-ss-* /tmp/dnb-5.18.101-code-*/p3b /tmp/dnb-5.18.101-code-*/t1run /tmp/dnb-5.18.101-code-*/t4run; do\n', 1)
old2 = 'printf \'%s %s\\n\' "$(find /tmp/dnb-5.18.99-cih-* /tmp/dnb-5.18.100-cih-* -name token.json 2>/dev/null | grep -c .)" "${_rh_m:--}" >> "$FAKE_TOK_HITS"'
assert s.count(old2) == 1, s.count(old2)
s = s.replace(old2, 'printf \'%s %s\\n\' "$(find /tmp/dnb-5.18.99-cih-* /tmp/dnb-5.18.100-cih-* /tmp/dnb-5.18.101-cih-* -name token.json 2>/dev/null | grep -c .)" "${_rh_m:--}" >> "$FAKE_TOK_HITS"', 1)
assert s.count('/tmp/dnb-5.18.101-') == 6
open(p, 'w').write(s)
PY
check "$(bash -n "$SB/bin/docker" && echo parses)|$(python3 -c 'import ast, sys; ast.parse(open(sys.argv[1]).read()); print("parses")' "$H101/rh101.py" 2>&1)" "parses|parses" \
  "control: the fake docker with this release's folders, and this rehearsal's readers, parse"
# The sign-off reader and the owner-rule reader, taken from the script itself (its own text, between its own anchors).
qs_read101() {   # qs_read101 SCRIPT COMMIT — the script's qs_text on COMMIT's webhook.php, from Git
  bash -c '. <(sed -n "/^QS_FILE=\"webhook.php\"$/,/^QS_TEXT_BASE=/p;/^qs_text() {$/,/^}$/p" "$1"); qs_text "$(git -C "$2" show "$3:$4/webhook.php")"' _ "$1" "$REPO" "$2" "$P"
}
tn_read101() {   # tn_read101 SCRIPT COMMIT — the script's tn_text on COMMIT's two owner-rule files, from Git
  bash -c '. <(sed -n "/^SNA_LIB=/,/^TN_TEXT_BASE=/p;/^tn_text() {/,/^}/p" "$1"); d="$(mktemp -d)"; git -C "$2" show "$3:$4/lib/SalesNumbersAdmin.php" > "$d/l"; git -C "$2" show "$3:$4/tabs/engage/wa_ai_setup.php" > "$d/p"; tn_text "$d/l" "$d/p"; rm -rf "$d"' _ "$1" "$REPO" "$2" "$P"
}

echo; echo "== 101-pre. the sandbox as production is: 5.18.100 serving, its --after-only and the quarantine's --check passed after its copy =="
check "$(live)|$(inst_ver)|$(installed100 "$P100")" "$P100|5.18.100|0" "pre-a 5.18.100 serving, every file as its pin has it (100-7's --redeploy)"
[ -e "$RECS/dnb-5.18.100" ] || ln -s "$OUT100" "$RECS/dnb-5.18.100"
check "$(readlink -f "$RECS/dnb-5.18.100")|$(readlink -f "$RECS/dnb-5.18.99")|$(readlink -f "$RECS/dnb-5.18.98")|$(readlink -f "$RECS/dnb-5.18.97")|$(readlink -f "$RECS/dnb-5.18.96")" \
      "$(readlink -f "$OUT100")|$(readlink -f "$OUT99")|$(readlink -f "$OUT98")|$(readlink -f "$OUT97")|$(readlink -f "$OUT96")" \
  "pre-b 5.18.100's, 5.18.99's, 5.18.98's, 5.18.97's and 5.18.96's records where 5.18.101's A14 looks for them (<records>/dnb-5.18.x), as on the server"
O101_0="$(o100)"; D101="$(data94)"; KEPT101="$(kept_state)"
check "$( [ "$(qj "$O101_0" sales)" -gt 0 ] && echo sales)|$( [ "$(qj "$O101_0" tech)" -ge 2 ] && echo tech)|$(qj "$O101_0" numbers | tr ',' '\n' | sed -n 's/^tech://p')|$(qj "$O101_0" inbox)" "sales|tech|1|unset" \
  "pre-c 5.18.100's rehearsal's staff records and its technician's number are there — the carried owner checks have salespeople, technicians and one technician's number to count; wa_inbox_roles unset"
EXP_NUM101="$(qj "$O101_0" numbers)"

echo; echo "== 101-0. what is rehearsed: 5.18.101 — the controls =="
echo "  the script     $WHICH101"
check "$P101|$P101F" "$(git -C "$REPO" rev-parse --short "origin/$REL101" 2>/dev/null || echo none)|$(git -C "$REPO" rev-parse "origin/$REL101" 2>/dev/null || echo none)" "the script is pinned to the release commit on $REL101, short and in full ($P101)"
check "$(git -C "$REPO" rev-parse --short "$P101^" 2>/dev/null)|$(git -C "$REPO" rev-parse "$P101^" 2>/dev/null)|$(ver "$P101")$(ver "$P100")" "$P100|$(sed -n 's/^BASELINE_COMMIT_FULL="\([^"]*\)".*/\1/p' "$DEPLOY101")|5.18.1015.18.100" \
  "…cut on 5.18.100 ($P100), the script's baseline in full; the pin says 5.18.101, its parent 5.18.100"
check "$(dl D "$P100" "$P101" | grep -c .)|$(dl A "$P100" "$P101" | grep -c .)|$(dl AM "$P100" "$P101" | grep -c .)|$(git -C "$REPO" diff --no-renames --name-only --diff-filter=CRTUXB "$P100" "$P101" | grep -c .)|$(git -C "$REPO" diff --name-only "$P100" "$P101" | grep -vc "^$P/")" "0|0|$N101|0|0" \
  "the delta: $N101 files under the plugin, none added, none deleted, renamed or retyped, nothing outside it"
check "$(dl M "$P100" "$P101" | sed "s#^$P/##" | LC_ALL=C sort | paste -sd' ' -)|$(sed -n 's/^EXPECTED_ADDED="\(.*\)"$/\1/p' "$DEPLOY101")|$(sed -n 's/^EXPECTED_CHANGED="\(.*\)"$/\1/p' "$DEPLOY101")" \
      "$CHANGED101||$CHANGED101" "…exactly the reviewed files, as the script lists them; nothing listed as added"
check "$(dl AM "$P100" "$P101" | grep -c "^$P/migrations/")" "0" "no migration"
PS101="$(sed -n '/^PIN_SHA256S="$(cat <<.SUMS.$/,/^SUMS$/p' "$DEPLOY101" | grep -E '^[0-9a-f]{64}  ' | while read -r s f; do [ "$(git -C "$REPO" show "$P101:$P/$f" | sha256sum | cut -c1-64)" = "$s" ] && echo ok || echo "bad:$f"; done | sort | uniq -c | tr -s ' ' | paste -sd, -)"
KS101="$(sed -n '/^PREV_KEPT_SHA256S="$(cat <<.SUMS.$/,/^SUMS$/p' "$DEPLOY101" | grep -E '^[0-9a-f]{64}  ' | while read -r s f; do a="$(git -C "$REPO" show "$P100:$P/$f" | sha256sum | cut -c1-64)"; b="$(git -C "$REPO" show "$P101:$P/$f" | sha256sum | cut -c1-64)"; [ "$a|$b" = "$s|$s" ] && echo ok || echo "bad:$f"; done | sort | uniq -c | tr -s ' ' | paste -sd, -)"
PC101="$(sed -n '/^PREV_CHANGED_SHA256S="$(cat <<.SUMS.$/,/^SUMS$/p' "$DEPLOY101" | grep -E '^[0-9a-f]{64}  ' | while read -r s f; do [ "$(git -C "$REPO" show "$P100:$P/$f" | sha256sum | cut -c1-64)" = "$s" ] && echo ok || echo "bad:$f"; done | sort | uniq -c | tr -s ' ' | paste -sd, -)"
check "$PS101|$KS101|$PC101" " 13 ok| 63 ok| 13 ok" "every sha256 the script pins, computed here from Git: the 13 release files at the pin, the 63 kept ones in both commits, the 13 changed ones at 5.18.100"
check "$(sed -n '/^PREV_KEPT_SHA256S="$(cat <<.SUMS.$/,/^SUMS$/p' "$DEPLOY101" | grep -cE '  (webhook\.php|lib/SalesNumbersAdmin\.php|tabs/engage/wa_ai_setup\.php|cron_quote_wa\.php|lib/QuoteTaxLine\.php|lib/TenantProfile\.php)$')|$(sed -n '/^PREV_KEPT_SHA256S="$(cat <<.SUMS.$/,/^SUMS$/p' "$DEPLOY101" | grep -c '  webhook\.php$')" "5|0" \
  "…the kept files hold 5.18.100's owner rule (the class and its page), the retry of a quote's WhatsApp and the two files of its gate — and not webhook.php, which this release changes"
check "$(sed -n 's/^COPY_MARK="\([^"]*\)".*/\1/p' "$DEPLOY101")|$(grep -cxF "$P/$(sed -n 's/^COPY_MARK="\([^"]*\)".*/\1/p' "$DEPLOY101")" <<<"$(dl M "$P100" "$P101")")|$(sed -n 's/^T1_SIGNATURE="\([^"]*\)".*/\1/p' "$DEPLOY101")|$(sed -n 's/^T1_SIGNATURE="\([^"]*\)".*/\1/p' "$DEPLOY100")" \
      "webhook.php|1|$(sed -n 's/^T1_SIGNATURE="\([^"]*\)".*/\1/p' "$DEPLOY100")|$(sed -n 's/^T1_SIGNATURE="\([^"]*\)".*/\1/p' "$DEPLOY100")" \
  "the copy's time is read off a file this release changes (COPY_MARK, webhook.php — 5.18.100's lib/SalesNumbersAdmin.php is not touched by it); T1's count 5.18.100's"
H101B="$(sed -n '/^# The deploy\. Run as root/,/^# The rollback is a separate command/p' "$DEPLOY101")"
check "$(grep -cxF '#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.101 && mkdir -p /root/dnb-5.18.101 && bash scripts/deploy-5.18.101.sh 2>&1 | ( umask 077; exec tee /root/dnb-5.18.101/deploy-$(date -u +%Y%m%dT%H%M%SZ).log )' <<<"$H101B")$(grep -cxF '#   cd /opt/dishnet && bash scripts/deploy-5.18.101.sh --after-only 2>&1 | ( umask 077; exec tee /root/dnb-5.18.101/after-$(date -u +%Y%m%dT%H%M%SZ).log )' <<<"$H101B")$(grep -cE -- '--rollback|--put-back|--redeploy|git checkout [0-9a-f]{7}' <<<"$H101B")|$(grep -cE '^#   cd ' <<<"$H101B")" \
      "110|2" "the header's two commands, each alone on its line with its own log; no rollback among them"
echo "  …    the sign-off and the owner rule as text, at the pin and at 5.18.100, by the script's own readers:"
check "$(qs_read101 "$DEPLOY101" "$P101")|$(qs_read101 "$DEPLOY101" "$P100")|$(qs_read101 "$DEPLOY101" "$P99")" \
      "$(sed -n 's/^QS_TEXT_PIN="\([^"]*\)".*/\1/p' "$DEPLOY101")|$(sed -n 's/^QS_TEXT_BASE="\([^"]*\)".*/\1/p' "$DEPLOY101")|$(sed -n 's/^QS_TEXT_BASE="\([^"]*\)".*/\1/p' "$DEPLOY101")" \
  "control: QS-G1's reader gives the pin QS_TEXT_PIN, and 5.18.100 and 5.18.99 QS_TEXT_BASE, all read from Git here"
check "$(tn_read101 "$DEPLOY101" "$P101")|$(tn_read101 "$DEPLOY101" "$P100")|$(tn_read101 "$DEPLOY101" "$P99")" \
      "$(sed -n 's/^TN_TEXT_PIN="\([^"]*\)".*/\1/p' "$DEPLOY101")|$(sed -n 's/^TN_TEXT_BASE="\([^"]*\)".*/\1/p' "$DEPLOY101")|$(sed -n 's/^TN_TEXT_BASE="\([^"]*\)".*/\1/p' "$DEPLOY100")" \
  "control: TN-G1's reader gives the pin and 5.18.100 the same rule (TN_TEXT_PIN, TN_TEXT_BASE), and 5.18.99 the rule 5.18.100's script called its base"

echo; echo "== 101-c. --check: stage A alone — GO on 5.18.100 as production has it; the sign-off, the owner lists, wa_inbox_roles =="
FP101="$(fp99)"; fresh101; S101C="$(states101)"; OUT="$(run101 --check)"; TC="$(last101)"
check "$(checkgo101 "$OUT")" "yesyesnono0|0|$S101C" "c1 --check is GO, never asks, backs nothing up and writes no state file (the state files as before the run)"
check "$(okline "$OUT" QS-G1 'the pin' 'DishNet Uganda — Starlink, installation & support' "the installed webhook.php is 5.18.100's, the old line once")" "yes" "c2 QS-G1 ok — the pin's line and the installed 5.18.100's"
check "$(okline "$OUT" TN-G1 'The installed two read the same')$(okline "$OUT" TN-G2 "the installed owners() and the pin's offer the same $(qj "$O101_0" sales) active salesperson account(s) and the same $(qj "$O101_0" tech) active technician account(s)" "($(qj "$O101_0" techadmin) left out)")$(okline "$OUT" TN-G3 'wa_inbox_roles is unset in the live store row')" "yesyesyes" \
  "c3 5.18.100's TN-G1, TN-G2 and TN-G3, carried, ok — TN-G2's counts this rehearsal's own reading's"
check "$(noteline "$OUT" TN-N1 "on the copy: $(python3 -c 'import sys
n = dict(x.split(":") for x in sys.argv[1].split(","))
print("a salesperson %s · a technician %s · an admin technician account %s · an inactive account %s · another role %s · no staff row %s · a department %s" % tuple(n[k] for k in ("sales","tech","techadmin","inactive","other","unknown","none")))' "$EXP_NUM101")")" "yes" \
  "c4 TN-N1 the registry's numbers by their owner's kind, as this rehearsal reads them — the technician's number among them"
check "$(fp99)|$(names100 "$R101/run-$TC.txt")|$(audit101 "$TC" | sed -E 's/^copies=[0-9]+ //; s/ adj=.*//')|$(vault101 "$TC" | awk -F'>' '{print ($1 == $2 ? "same" : "moved")}')|$(uother101 "$TC")" "$FP101|0|novault=0 plugin=0|same|-" \
  "c5 nothing changed — tree, store, vault, settings, ucrm.json; no staff record's name printed; every PHP process on a copy with a vault of its own; GETs only"

echo; echo "== 101-1. a deploy's refusals, each before anything changes; the new check's weakened copy =="
VAR101="$(release_variant101 webhook.php "$(a101 ssline)" "$(r101 ssline)")"
VSUM101="$(git -C "$REPO" show "$VAR101:$P/webhook.php" | sha256sum | cut -c1-64)"
check "$(git -C "$REPO" rev-parse --short "$VAR101^")|$(git -C "$REPO" diff --name-only "$P101" "$VAR101" | paste -sd, -)|$(qs_read101 "$DEPLOY101" "$VAR101")" "$P100|$P/webhook.php|new=0 old=0 phrase=1 gate=1" \
  "control: the variant is cut on 5.18.100 and differs from the release in webhook.php alone — the Uganda line sent to every tenant, which the reader sees"
fresh101; OUT="$(run101 --answer NO --script "$(pinned_copy99 var101 "$VAR101" "$DEPLOY101")")"
nogo101 "1a a release whose summary signs off as DishNet Uganda on every tenant, re-pinned short and full but not its sha256: refused at A0" "the pin's files are not the reviewed 5.18.101's by sha256" "$OUT"
VC101="$(pinned_copy101 var101s "$VAR101" webhook.php "$VSUM101")"
WH100_LINE="$(sed -n '/^PREV_CHANGED_SHA256S="$(cat <<.SUMS.$/,/^SUMS$/p' "$DEPLOY101" | grep -E '  webhook\.php$')"
check "$(diff "$DEPLOY101" "$VC101" 2>/dev/null | grep -c '^>')|$(diff "$DEPLOY101" "$VC101" 2>/dev/null | grep -c '^> EXPECTED_PLUGIN_COMMIT')|$(sed -n '/^PIN_SHA256S="$(cat <<.SUMS.$/,/^SUMS$/p' "$VC101" 2>/dev/null | grep -cxF "$VSUM101  webhook.php")|$(sed -n '/^PREV_CHANGED_SHA256S="$(cat <<.SUMS.$/,/^SUMS$/p' "$VC101" 2>/dev/null | grep -E '  webhook\.php$')" \
      "3|2|1|$WH100_LINE" \
  "control: that copy differs from the script in three lines — the two commit pins and webhook.php's line in the release's sha256 list, now the variant's; 5.18.100's line for it (PREV_CHANGED_SHA256S) as it was"
fresh101; OUT="$(run101 --answer NO --script "$VC101")"
nogo101 "1b …its sha256 re-pinned too: A0 lets it through, QS-G1 refuses it as text" "NO-GO (QS-G1): the pin's webhook.php does not sign the Quotation & Order Summary off as reviewed, read as text (new=0 old=0 phrase=1 gate=1" "$OUT"
python3 - "$VC101" "$(a101 qsg1)" "$(r101 qsg1)" <<'PY' || echo "weak101:vc-qsg1" >> "$SB/anchor-missing"
import sys
f, a, b = sys.argv[1:4]; s = open(f, encoding='utf-8').read(); assert s.count(a) == 1; open(f, 'w', encoding='utf-8').write(s.replace(a, b))
PY
fresh101; OUT="$(R101_MUTANT_OUT=1 run101 --script "$VC101" --check)"; rm -f "$VC101" "$REPO/scripts/.var101.sh"
check "$(has "$OUT" '5.18.101 (check): GO')" "yes" "1c QS-G1's refusal weakened: a --check GO over that release (caught)"
row_keep100; row_set wa_inbox_roles "support"; fresh101; DPRE="$(data94)"; OUT="$(run101 --answer NO)"
nogo101 "1d 5.18.100's TN-G3, carried: wa_inbox_roles granting a technician role the shared Inbox — NO-GO, before the question" \
  "NO-GO (TN-G3): wa_inbox_roles grants the shared Inbox to support, and support own, or may own, a number" "$OUT" "$DPRE"
row_back100
check "$(qj "$(o100)" inbox)|$(data94)" "unset|$D101" "…the store row put back byte for byte, wa_inbox_roles unset: the data as before"
cp -p "$PD/webhook.php" "$SB/webhook101.kept"
python3 - "$PD/webhook.php" <<'PY' || echo "plant101:installed-webhook" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]; s = open(p, encoding='utf-8').read()
a = '            $msg .= "\\n\\n🚀 *DishNet Internet Services*";'
assert s.count(a) == 1
open(p, 'w', encoding='utf-8').write(s.replace(a, '            $msg .= "\\n\\n🛰️ *DishNet Uganda*";'))
PY
fresh101; OUT="$(run101 --answer NO)"
check "$(has "$OUT" "NO-GO: the container's record says $P100 (5.18.100), but these installed files are not 5.18.100's: webhook.php — a copy that did not complete")$(has "$OUT" 'Nothing was changed.')$(went101 "$OUT")|$(live)|$(backups101)" "yesyesnono|$P100|0" \
  "1e the installed webhook.php edited by hand: refused before anything changes, the file named, the put-back printed; nothing deployed or backed up"
cp -p "$SB/webhook101.kept" "$PD/webhook.php"; rm -f "$SB/webhook101.kept"
check "$(installed100 "$P100")|$(data94)|$(live)" "0|$D101|$P100" "…the installed webhook.php put back: every file 5.18.100's, the data as before, 5.18.100 serving"

echo; echo "== 101-4. the deploy, as the operator runs it — DEPLOY typed =="
LEDGER101="$(ledger95)"
fresh101; OUT="$(run101 --answer DEPLOY)"; OUT101D="$OUT"; T101D="$(last101)"
echo "  …    the deploy's own tally:$(grep -E '^  checks +[0-9]+ ok, ' <<<"$OUT" | sed -E 's/^  checks +/ /')"
check "$(fails "$OUT")$(has "$OUT" '5.18.101 (deploy): PASSED')$(live)$(installed101 "$P101")$(inst_ver)" "0yes${P101}05.18.101" "4 the deploy PASSES: 0 failed, $P101 serving, every file as the pin has it"
for l in "ok    A0 the release delta is exactly 5.18.101's 13 files — 0 added, 13 changed, each the reviewed one by the sha256 this script pins (13 of 13)" \
         "ok    BR1 the installed plugin is live 5.18.100 ($P100): all " \
         "ok    Q-G1 installed is 5.18.100 ($P100): its manifest says 5.18.100; all 13 files 5.18.101 changes carry the sha256 pinned for 5.18.100, and all 63 files it must not touch" \
         "ok    A14 the stray store is quarantined and the quarantine holds" "ok    A22-T1 under this server's own PHP" "ok    G3 both ECO BANK cards show a figure" \
         "ok    Q-G2 the tenant is Uganda" "ok    Q-G3 quoting organization #1 (from the Uganda profile)" "ok    Q-G4 uCRM's organizations, read once through the plugin's own client" \
         "ok    TN-G1 as text" "ok    TN-G2 on one copy of the book" "ok    TN-G3 wa_inbox_roles is unset" "ok    QS-G1 as text" "GO — evidence recorded" \
         "ok    container serves $P101" "ok    R1 the installed manifest says 5.18.101" \
         "ok    Q-CA1 installed = 5.18.101's release: all 13 of its files carry the sha256 this script pins; all 63 files of 5.18.100 it must not touch" \
         "ok    Q-CA2 the INSTALLED QuoteOrganization::setting() on a copy reads what stage A read through the pin's: on:1:profile:-" \
         "ok    TN-CA1 as text, installed" "ok    TN-CA2 on a new copy of the book, through the installed code: owners() offers the $(qj "$O101_0" sales) active salesperson account(s) and the $(qj "$O101_0" tech) active technician account(s)" \
         "ok    TN-CA2 the same counts as stage A's reading through the pin" "ok    TN-CA3 wa_inbox_roles as stage A read it (inbox=unset roles=- odd=0 owners=-) — every entry, compared as keyed hashes" \
         "ok    QS-CA1 as text, installed: webhook.php ends the summary with the owner's wording on Uganda" \
         "ok    Z1 the live vault" "ok    Z2 nothing of the vault, no webhook_secret file, no token copy" \
         "  names             QS-G1 and QS-CA1 are 5.18.101's own; TN-G1–TN-G3, TN-N1, TN-N2 and TN-CA1–TN-CA3 are 5.18.100's;" \
         "  what changed      on this Uganda server (Q-G2), the WhatsApp Quotation & Order Summary the plugin sends when a quote is made in uCRM now ends \"🛰️ *DishNet Uganda — Starlink, installation & support*\"" \
         "  your steps        none"; do
  check "$(has "$OUT" "$l")" "yes" "4: ${l:0:110}"
done
check "$(sed -n 's/^TN_A_P=//p' "$STATE101" | tail -1 | grep -c '^code=owners offered=')$(sed -n 's/^TN_A_INB=//p' "$STATE101" | tail -1)|$(grep -E '^TN_A_(P|INB)=' "$STATE101" | grep -cE ' (sig|oroles|gh)=')" "1inbox=unset roles=- odd=0 owners=-|0" \
  "4a the state file records stage A's owner reading and wa_inbox_roles — counts and role names, no keyed hash (sig, oroles, gh)"
check "$(grep -c "^COPY_AT=$(stat -c %Y "$PD/webhook.php")\$" "$STATE101")|$(epoch99 "$(sed -n 's/^COPY_AT=//p' "$STATE101" | tail -1)")" "1|epoch" \
  "4b the copy's time, as stamp_copy gave webhook.php — a file this release changes"
SUM101="$(sed -n '/^== F\. Summary ==$/,$p' <<<"$OUT")"
check "$(grep -cxF "      $EXP_AFTER101" <<<"$SUM101")$(grep -cxF "      $EXP_QCHECK" <<<"$SUM101")$(grep -cxF "      $EXP_ROLLBACK101" <<<"$SUM101")$(grep -cE '(deploy-5\.18\.101\.sh|quarantine-stray-store\.sh).*(deploy-5\.18\.101\.sh|quarantine-stray-store\.sh)' <<<"$OUT")" "1110" \
  "4c the --after-only, the quarantine's --check and the rollback, each printed exactly, alone on its line"
check "$(names100 "$R101/run-$T101D.txt" "$STATE101" "$OUT101"/*.log)|$(secrets99 "$R101/run-$T101D.txt" "$STATE101")|$(audit101 "$T101D" | sed -E 's/^copies=([0-9]+) /\1 /' | awk '{print ($1 >= 20 ? "many" : $1 " only") " " $2 " " $3}')|$(uother101 "$T101D")" "0|0|many novault=0 plugin=0|-" \
  "4d no staff record's name and no stand-in secret printed or recorded; every PHP process on a copy with a vault of its own; GETs only"
check "$(ledger95)|$(both_copies "$BK97")|$(kept_state)$(oldname)$(host_code101)$(left_tmp101)$(evo_calls)" "$LEDGER101|11|${KEPT101}0000" \
  "4e the migration ledger unchanged; ECO Bank's switch on in both places; the quarantine as it was; no code copy or run folder left; Evolution never called"

echo; echo "== 101-5. --after-only as printed; webhook.php hand-edited after the deploy =="
copy_back101 30; COPY101="$(sed -n 's/^COPY_AT=//p' "$STATE101" | tail -1)"
[ "$(epoch99 "$COPY101")" = "epoch" ] && set_sched $(( COPY101 + 300 )); base_log
ALINE="$(printed "$OUT101D" "cd $REPO && bash scripts/deploy-5.18.101.sh --after-only")"
check "$ALINE|$(epoch99 "$COPY101")" "$EXP_AFTER101|epoch" "5 the --after-only command, as the deploy's summary printed it; the copy moved thirty minutes back"
fresh101; OUT="$(run_line101 '' "$ALINE")"; OUT101A="$OUT"
check "$(fails "$OUT")$(has "$OUT" '5.18.101 (after): PASSED')$(okline "$OUT" R19 'the quarantine holds: master ran the retry job at' "after the copy (at $(utc "$COPY101"))")$(okline "$OUT" QS-CA1 'as text, installed')$(okline "$OUT" TN-CA2 'the same counts as stage A')$(okline "$OUT" TN-CA3 'as stage A read it' 'its known role names and the count of its other entries')" "0yesyesyesyesyes" \
  "5a --after-only PASSES, R19 conclusive; QS-CA1; TN-CA2 and TN-CA3 against the deploy's own readings from the state file"
cp -p "$PD/webhook.php" "$SB/webhook101.kept"; WT101="$(stat -c %Y "$PD/webhook.php")"
python3 - "$PD/webhook.php" <<'PY' || echo "plant101:after-webhook" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]; s = open(p, encoding='utf-8').read()
new = [l for l in s.split('\n') if 'DishNet Uganda — Starlink, installation & support' in l]
assert len(new) == 1
open(p, 'w', encoding='utf-8').write(s.replace(new[0], '            $msg .= "\\n\\n🚀 *DishNet Internet Services*";'))
PY
touch -d "@$WT101" "$PD/webhook.php"
fresh101; OUT="$(run_line101 '' "$ALINE")"
check "$(failline "$OUT" QS-CA1 'the installed webhook.php does not sign the summary off as reviewed, read as text (new=0 old=1 phrase=1 gate=1')$(has "$OUT" '5.18.101 (after): ')$(has "$OUT" '5.18.101 (after): PASSED')" "yesyesno" \
  "5b webhook.php hand-edited back to the old line after the deploy: QS-CA1 FAILS, naming what it read"
M="$(weak101 qsca1w qsca1)"; fresh101; OUT="$(R101_MUTANT_OUT=1 run101 --script "$M" --after-only)"; rm -f "$M"
check "$(failline "$OUT" QS-CA1 'does not sign the summary off')$(okline "$OUT" QS-CA1 'does not sign the summary off')" "noyes" "5c QS-CA1's failure weakened: it says ok over the old line (caught)"
cp -p "$SB/webhook101.kept" "$PD/webhook.php"; rm -f "$SB/webhook101.kept"
check "$(installed101 "$P101")|$(stat -c %Y "$PD/webhook.php")" "0|$WT101" "…the installed webhook.php put back, byte for byte and its time: every file the pin's"
DX101="$(data94)"; fresh101; OUT="$(run101 --check)"
check "$(has "$OUT" "--check is stage A before a deploy, and the container already serves $P101 (5.18.101) — run --after-only instead")$(has "$OUT" 'Nothing was changed. Send the log file.')$(went101 "$OUT")$(has "$OUT" '5.18.101 (check): GO')|$(grep -cxF "      $EXP_AFTER101" <<<"$OUT")|$(live)|$(data94)" "yesyesnonono|1|$P101|$DX101" \
  "5d --check with 5.18.101 live: refused, the --after-only printed instead, alone; nothing changed"

echo; echo "== 101-6. the rollback, run exactly as printed — ROLLBACK typed; the sign-off 5.18.100's; --redeploy too soon =="
RLINE="$(printed "$OUT101A" "cd $REPO && bash scripts/deploy-5.18.101.sh --rollback")"
check "$RLINE" "$EXP_ROLLBACK101" "6 the rollback command, as the --after-only's summary printed it after its verdict"
S101SHA="$(sha256sum "$STATE101" | cut -c1-64)"; DR0="$(data94)"
fresh101; OUT="$(run_line101 ROLLBACK "$RLINE")"; RLOG="$(ls -1 "$OUT101"/rollback-*.log 2>/dev/null)"
check "$(fails "$OUT")$(has "$OUT" '5.18.101 (rollback): PASSED')$(live)$(installed101 "$P100")$(inst_ver)" "0yes${P100}05.18.100" "6a the rollback PASSES: 5.18.100 serving again, every file as it had them"
for l in "ok    RB the installed manifest says 5.18.100" \
         "ok    RB all 13 files 5.18.101 changed are 5.18.100's again, byte for byte" \
         "ok    RB 5.18.99's quoting code is in place" \
         "ok    RB the owner rule is 5.18.100's, as text: owners() defined and called, the technician roles once, an admin technician account refused (tech=1 decl=1 sales=1 kind=1 role=1 page=1 old=0)" \
         "ok    RB on a copy of the book, through the installed code: owners() offers the $(qj "$O101_0" sales) active salesperson account(s) and the $(qj "$O101_0" tech) active technician account(s)" \
         "note  RB 1 number(s) in the registry are owned by a technician account, as 5.18.100 allows" \
         "ok    RB the sign-off is 5.18.100's again, as text: the old line once, the new line nowhere (new=0 old=1 phrase=1 gate=1)" \
         "ok    RB cash in hand reads exactly as before the deploy on a copy of the book" "ok    RB 088 stays after the rollback, complete" \
         "  serving           $P100  (plugin 5.18.100)" \
         "  what it means     the WhatsApp Quotation & Order Summary sent on a uCRM quote ends \"🚀 *DishNet Internet Services*\" again"; do
  check "$(has "$OUT" "$l")" "yes" "6a: ${l:0:110}"
done
check "$(printf '%s\n' "$RLOG" | grep -c .)|$(sha256sum "$STATE101" | cut -c1-64)|$(grep -c "^RB_COPY_AT=$(stat -c %Y "$PD/webhook.php")\$" "$OUT101"/rollback-state-*.env 2>/dev/null)|$(sq "SELECT COUNT(*) FROM wa_channels WHERE channel_id = 'sales-901' AND owner_type = 'staff' AND owner_staff_id = ${TECH100:-0}")" "1|$S101SHA|1|1" \
  "6b its own log; the deploy's state file untouched; the rollback's record holding its copy's time as stamp_copy gave webhook.php; the technician's number still his"
check "$(data94)|$(names100 "$R101/line-$(last101).txt" "$RLOG")|$(evo_calls)" "$DR0|0|0" "6c the rollback changed no data; no staff record's name printed; Evolution never called"
fresh101; D6D="$(data94)"; OUT="$(run101 --answer NO --redeploy)"
check "$(has "$OUT" "NO-GO (A14): a rollback of 5.18.101 by this script copied the plugin at")$(went101 "$OUT")$(live)|$(backups101)|$(data94)" "yesnono$P100|0|$D6D" \
  "6d --redeploy right after the rollback, before the quarantine's --check: NO-GO at A14, never the question; nothing backed up or changed"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "…the quarantine's own --check after the rollback, as RB asks: PASSED"
KEPT101="$(kept_state)"

echo; echo "== 101-7. --redeploy after the rollback =="
fresh101; OUT="$(run101 --answer DEPLOY --redeploy)"; OUT101R="$OUT"
check "$(fails "$OUT")$(has "$OUT" '5.18.101 (deploy): PASSED')$(live)$(installed101 "$P101")$(inst_ver)" "0yes${P101}05.18.101" "7a --redeploy, DEPLOY typed: PASSED, $P101 serving, every file as the pin has it"
check "$(okline "$OUT" BR1 'the installed plugin is live 5.18.100')$(has "$OUT" 'files 5.18.101 adds are already on disk')$(sed -n 's/^REDEPLOY=//p' "$STATE101" | tail -1)$(okline "$OUT" QS-CA1 'as text, installed')" "yesno1yes" \
  "7b BR1 finds no file of this release's on disk (it adds none); --redeploy recorded; QS-CA1"
copy_back101 30; COPY101R="$(sed -n 's/^COPY_AT=//p' "$STATE101" | tail -1)"
[ "$(epoch99 "$COPY101R")" = "epoch" ] && set_sched $(( COPY101R + 300 )); base_log
ALINE2="$(printed "$OUT101R" "cd $REPO && bash scripts/deploy-5.18.101.sh --after-only")"
fresh101; OUT="$(run_line101 '' "$ALINE2")"
check "$ALINE2|$(fails "$OUT")$(has "$OUT" '5.18.101 (after): PASSED')" "$EXP_AFTER101|0yes" "7c its --after-only, as printed: PASSED"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "7d the quarantine's own --check, as the runbook orders: PASSED"

echo; echo "== 101-10. what the rehearsal left behind, and what it never reached =="
fresh101   # the last logs and sheets into the sweeps' folders
check "$(evo_calls)" "0" "Evolution never called — not by the deploys, their checks, the rollback or the code they ran on copies"
check "$(ls -a "$REPO/scripts" | grep -E '^\..+\.sh$' | paste -sd, -)" ".qpin.sh" "no weakened or re-pinned copy is left in the clone's scripts/ — the quarantine's one pinned copy aside"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )" "" "every weakened copy, release variant, pinned copy and planted edit — this rehearsal's and those of the rehearsals it runs — found its anchor exactly once"
rm -f "$SB/anchor-missing"   # the controls below read their own record alone
M="$(weak101 anchorprobe101 no-such-entry 2>/dev/null)"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )" "weak101:no-such-entry " "control on the control: a weakened copy asked for an entry the table does not hold is recorded as such (weak101)"
rm -f "$SB/anchor-missing" "$M"
M="$(pinned_copy101 pinprobe101 "$P101" lib/KycService.php "$(printf '0%.0s' $(seq 1 64))" 2>/dev/null)"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )|$(grep -c '^0\{64\}  ' "$M" 2>/dev/null)" "pinned_copy101:pinprobe101 |0" \
  "control on the control: a copy re-pinned for a file the release's sha256 list does not hold (lib/KycService.php, among the kept files) is recorded as such, and its line is replaced nowhere (pinned_copy101)"
rm -f "$SB/anchor-missing" "$M"
check "$( [ -f "$SB/bad-runs" ] && tr '\n' ' ' < "$SB/bad-runs" )" "" "every run took the options it was given — none answered 'unknown option'"
OUT="$(run101 --after-only --rehearsal-unknown-option)"
check "$(has "$OUT" 'unknown option: --rehearsal-unknown-option')$( [ -s "$SB/bad-runs" ] && echo recorded)" "yesrecorded" "control on the control: a run given an option the script does not know is recorded as such"
rm -f "$SB/bad-runs"
tok101() { python3 "$H101/rh101.py" tok99 "$R101/tok/run-$1.tok"; }   # tok101 TAG — remote=<n> copied=<n> left=<n>
OBS_BAD=0; OBS_N=0; TOK_BAD=0; GET_BAD=0
for rec in "$R101"/exec/run-*.rec; do
  t="${rec##*/run-}"; t="${t%.rec}"; OBS_N=$((OBS_N+1))
  a="$(audit101 "$t")"; case "$a" in *" novault=0 plugin=0 "*) ;; *) OBS_BAD=$((OBS_BAD+1)) ;; esac
  [ "$(hits101 "$t")" = "0" ] || OBS_BAD=$((OBS_BAD+1))
  [ "$(cat "$R101/vault/run-$t.before")" = "$(cat "$R101/vault/run-$t.after")" ] || OBS_BAD=$((OBS_BAD+1))
  case "$(tok101 "$t")" in *" left=0") ;; *) TOK_BAD=$((TOK_BAD+1)) ;; esac
  [ "$(uother101 "$t")" = "-" ] || GET_BAD=$((GET_BAD+1))
done
check "$( [ "$OBS_N" -ge 15 ] && echo many)|$OBS_BAD|$TOK_BAD|$GET_BAD" "many|0|0|0" \
  "10 across every run of the 5.18.101 script — the check, the refusals, the weakened copies, the deploy, the after-onlys, the rollback and the redeploy — the copy observers were quiet, every token copy was gone at the call after its probe, and the stand-in uCRM received nothing but GETs"
check "$(vt_host)|$(grep -cxF "$W96" "$DATA/webhook_secret")" "$VT99_0|1" "…the live vault as it was before the first run of 5.18.99's rehearsal, bytes and time; the planted webhook secret where it was planted"
sweep_files101() { ls -1 "$R101"/run-*.txt "$R101"/line-*.txt "$R101"/mutants/run-*.txt "$R101"/logs/*.log "$OUT101"/*.log "$OUT101"/state-5.18.101.env* "$OUT101"/rollback-state-* "$OUT101"/cih-rows-* 2>/dev/null; }
forms101() { python3 "$H101/rh101.py" forms "$SB/forms101.txt" "$SB/seed96.amounts" "$SB/seed97.amounts" "$SB/stmt97.amounts" "$SB/seed98.amounts" "$H97"/cash-*.json "$H98"/cash-*.json "$H99"/cash-*.json "$R97"/sheets/cash-*.txt "$R98"/sheets/cash-*.txt "$R99"/sheets/cash-*.txt "$R100"/sheets/cash-*.txt "$R101"/sheets/cash-*.txt "$OUT97"/cash-*.txt "$OUT98"/cash-*.txt "$OUT99"/cash-*.txt "$OUT100"/cash-*.txt "$OUT101"/cash-*.txt >/dev/null 2>&1; }
figs101() { forms101; python3 "$H101/rh101.py" hits "$SB/forms101.txt" "$@"; }   # figs101 FILE… — how many hold a figure of the book, in any printed form
check "$( [ "$(sweep_files101 | grep -c .)" -ge 15 ] && echo many)|$( [ "$(ls "$R101"/sheets/cash-*.txt 2>/dev/null | grep -c .)" -ge 3 ] && echo sheets)|$(forms101; grep -c . "$SB/forms101.txt" | awk '{print ($1 >= 100 ? "forms" : "few:" $1)}')|$( [ "$(ls "$R101"/ucrm/run-*.req 2>/dev/null | grep -c .)" -ge 15 ] && echo records)" "many|sheets|forms|records" \
  "the sweep has material: every run's terminal and log of this release's, the state files and row files; its runs' sheets; a hundred and more printed forms of the book's figures; every run's request record"
check "$(figs101 $(sweep_files101))" "0" "10a no figure of the book or of a kept statement — in any printed form — in any terminal, log, state file or row file of any run of the 5.18.101 script: only in the sheets"
check "$(sweep_files101 | xargs grep -lF -f "$SCAN96" 2>/dev/null | grep -c .)|$(grep -v '^$' "$SEC95" > "$SB/sec101.pat"; sweep_files101 | xargs grep -lF -f "$SB/sec101.pat" 2>/dev/null | grep -c .)|$(secrets99 $(sweep_files101) "$R101"/sheets/cash-*.txt "$R101"/ucrm/run-*.req)|$(names100 $(sweep_files101) "$R101"/sheets/cash-*.txt)" "0|0|0|0" \
  "10b no output, log, state file, sheet or request record holds the planted webhook secret or vault credential, a repair key, a planted Splynx or pdf value, a name, contact or key of the stand-in uCRM's — or a staff record's name"
check "$(noids99 $(sweep_files101))|$(bankhits98 $(sweep_files101))|$(sweep_files101 | xargs grep -lE ' (gh|oroles)=' 2>/dev/null | grep -c .)" "0|0|0" \
  "10c no output, log or state file names a client id the app holds, an account name or number — or carries a keyed hash of the owner checks"
printf 'planted %s, %s, %s, client %s and %s\n' "$(grep -m1 '^2:' "$SB/forms101.txt" | cut -c3-)" "$W96" "$(head -1 "$U99_SECRETS")" "$(head -1 "$SB/ids99.pat")" "RH100SECRET-Tech-One" > "$R101/zz-sweep-control.txt"
check "$(figs101 "$R101/zz-sweep-control.txt")$(grep -lF -f "$SCAN96" "$R101/zz-sweep-control.txt" | grep -c .)$(secrets99 "$R101/zz-sweep-control.txt")$(noids99 "$R101/zz-sweep-control.txt")$(names100 "$R101/zz-sweep-control.txt")" "11111" \
  "control on the sweeps: a file holding one figure form, the planted secret, a stand-in secret, a client id and a seeded staff name is found by all five"
rm -f "$R101/zz-sweep-control.txt" "$SB/sec101.pat"
rterr101() {   # rterr101 FILE… — how many of the files hold a bash runtime error of a script ("<x>.sh: line N: …")
  [ "$#" -gt 0 ] || { echo none; return; }
  grep -lE '\.sh: line [0-9]+: .*(bad substitution|unbound variable|syntax error|command not found)' "$@" 2>/dev/null | grep -c .
}
printf '%s\n' 'X=1; Y=2' 'echo "a:${X:+ the copy'"'"'s:$X}${Y:+ the base'"'"'s:$Y} end"' > "$SB/rterr101.sh"
bash "$SB/rterr101.sh" > "$R101/zz-rterr-control.txt" 2>&1
check "$(rterr101 $(sweep_files101))|$(rterr101 "$R101/zz-rterr-control.txt")" "0|1" \
  "10d no terminal or log of any run of the 5.18.101 script holds a bash runtime error; control: a script with an apostrophe inside \${…:+…} is caught"
rm -f "$SB/rterr101.sh" "$R101/zz-rterr-control.txt"
check "$(left_tmp101)$(host_code101)$(ls -d /tmp/dnb-backup-* 2>/dev/null | grep -c .)$(find /tmp -maxdepth 6 -path '/tmp/dnb-5.18.101-*' -name token.json 2>/dev/null | grep -c .)" "0000" \
  "no run folder, code copy, vault, token copy or backup copy of this release's left in /tmp or on the host"
check "$(grep -c 'Fatal\|Parse error' "$SB/web.log")|$(grep -c 'Fatal\|Parse error' "$U99/server.log")" "0|0" "the stand-in plugin and the stand-in uCRM served every request without a PHP fatal"
check "$(checkout_state)" "$CHECKOUT0" "this checkout is as the rehearsal found it: same commit, same tracked files"

echo
echo "RESULT: $PASS ok, $FAILN failed  (the script: $WHICH101)"
[ "$FAILN" = "0" ] || echo "  $FAIL_PRE101 of the $FAILN failed in the earlier releases' rehearsals, before this release's own sections. Their scripts are records, left as they are; a refusal of theirs at a check read off a pipeline whose reader exits early is a SIGPIPE false stop, not a defect of 5.18.101's. Read the first FAIL; if it is one, run the rehearsal again — it is never a pass."
[ "$FAILN" = "0" ]
