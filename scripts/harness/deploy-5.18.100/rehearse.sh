#!/usr/bin/env bash
#
# Rehearse scripts/deploy-5.18.100.sh — a technician may own a WhatsApp number on the Salesperson numbers card (the active
# technicians whose account is not an administrator's, labelled " — technician", beside the salespeople as before; the
# number stays a sales line) — over the live release, 5.18.99, before the operator runs it.
#
# The sandbox is the previous release's rehearsal's, built by its own code: scripts/harness/deploy-5.18.99/rehearse.sh,
# read up to just before its own last section (what it left behind, its sweeps) — the cut asserted: its anchor exactly
# once, its last check the one named, and the one line that names its own folder pointed at that folder, also exactly
# once — and run here as it is, which builds every earlier sandbox the same way, from their own rehearsals. By the time
# this file's own sections begin, 5.18.99 has been deployed, rolled back, deployed again with --redeploy and rolled back
# twice more there; its stand-in uCRM is still up. Here 5.18.99 is deployed once more with --redeploy, its copy moved half
# an hour back, its --after-only runs (R19 conclusive), then the quarantine's own --check: production's state on the day.
#
# On it, synthetic only: four staff records added through the installed code's own writer — a technician, a technician
# with no name, a technician leader whose account is an administrator's, an inactive engineer — every name a marked
# secret of this rehearsal's (RH100SECRET-…); later one number in the registry given to the technician, as the card would
# (one row and its trail row, written with SQL: the card itself would ask Evolution), and wa_inbox_roles set and cleared
# in the store row. This rehearsal's own reading of who the card may offer (rh100-oracle.php) is set against the script's.
#
# This rehearsal proves:
#   · the script is pinned to the release commit on release/5.18.100 (short and full), cut on 5.18.99; the delta is
#     exactly the fifteen reviewed files, the class and its page byte for byte the development branch's (tech-100), each
#     file's sha256 as the script pins it, computed here from Git; the sixty kept files by theirs in both commits, the
#     fourteen changed ones by their previous form; no migration; T1 and T4 pinned as before; the header's two commands,
#     each alone; the copy's time read off a file this release changes (0);
#   · --check is GO on 5.18.99 as production has it: TN-G1, TN-G2 (the counts this rehearsal's own), TN-G3; nothing changed
#     (c);
#   · TN-G3 refuses wa_inbox_roles naming a technician role, and a salesperson's, and accepts one naming neither — an
#     entry that is not a known role name counted and never printed, its weakened copy caught; TN-G1
#     refuses a release whose rule takes an administrator's technician account in, re-pinned so that A0 lets it through
#     — and with TN-G1 weakened TN-G2 refuses it on its own; each weakened copy caught (1);
#   · the deploy, DEPLOY typed, PASSES: the TN checks and the carried ones, stage A's readings in the state file, the copy's
#     time as stamp_copy gave lib/SalesNumbersAdmin.php, no name of a staff record printed (4);
#   · --after-only as printed PASSES; a number given to the technician is a note (TN-N1); wa_inbox_roles set to a
#     technician role after the deploy FAILS TN-CA3, and its weakened copy is caught; the technician's role then changed
#     to one outside the six and wa_inbox_roles naming it FAILS TN-CA3 too — the role a number's owner holds now — and
#     that comparison's weakened copy is caught (5);
#   · the rollback as printed, ROLLBACK typed: PASSED — 5.18.99's files by sha256, its owner rule and its quoting code in
#     place, the technician's number counted; a --redeploy before the quarantine's --check refused at A14 (6);
#   · --redeploy after the rollback: PASSED, BR1 noting the one file on disk; its --after-only PASSES (7);
#   · nothing reaches Evolution; across every run of the 5.18.100 script the copy observers were quiet, every token
#     copy gone, the stand-in uCRM given GETs only, the live vault as it was; no name of a staff record, no figure of the
#     book, no planted or stand-in secret, no client id, no bash runtime error in any output, log or state file of this
#     release's; no code copy or run folder left in /tmp or on the host; every anchor of this rehearsal and of those it
#     runs found, every run given known options, each with its control on the control; this checkout as found (10).
#     The earlier releases' runs, run again here, are swept by their own rehearsals, as 5.18.99's swept its own alone.
#
# Everything runs in a sandbox; it never touches this checkout, the server or the network, and refuses to run where the
# server could be. Everything seeded is fictitious.
#
# Left as found, knowingly: the earlier releases' scripts that the earlier rehearsals run, and that this one runs again
# (5.18.99's --redeploy and --after-only in 100-pre, the quarantine's --checks), are records, each pinned byte for byte by
# its own rehearsal; those up to 5.18.98's still judge statuses read off pipelines whose reader exits early, and can stop
# falsely at a small measured rate. Such a stop fails the rehearsal — it is never a pass — and is not a defect of
# 5.18.100's: the last line says how many failures came before this release's own sections.
#
#   bash scripts/harness/deploy-5.18.100/rehearse.sh            REHEARSE_KEEP=<dir> keeps every run's full output
set -u
for f in /data/ucrm /opt/dishnet /var/run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
H100_DIR="$(cd "$(dirname "$0")" && pwd)"
H100_ROOT="$(cd "$H100_DIR/../../.." && pwd)"
F100=scripts/deploy-5.18.100.sh
P100_EARLY="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT="\([^"]*\)".*/\1/p' "$H100_ROOT/$F100" 2>/dev/null)"
P100F_EARLY="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT_FULL="\([^"]*\)".*/\1/p' "$H100_ROOT/$F100" 2>/dev/null)"
T1_EARLY100="$(sed -n 's/^T1_SIGNATURE="\([^"]*\)".*/\1/p' "$H100_ROOT/$F100" 2>/dev/null)"
case "$P100_EARLY" in __*|'') echo "refusing: $F100 is not pinned to a release commit (${P100_EARLY:-none})"; exit 2;; esac
case "$P100F_EARLY" in "$P100_EARLY"*) [ "${#P100F_EARLY}" = "40" ] || { echo "refusing: $F100 does not pin its release commit in full"; exit 2; } ;; *) echo "refusing: $F100's full pin does not begin with its short one"; exit 2;; esac
case "$T1_EARLY100" in [1-9]*' passed, 0 failed rc=0') ;; *) echo "refusing: $F100 does not pin T1's count (T1_SIGNATURE=${T1_EARLY100:-none})"; exit 2;; esac
[ -f "$H100_DIR/rh100-oracle.php" ] || { echo "refusing: this rehearsal's helper rh100-oracle.php is not beside it"; exit 2; }
H99_HARNESS="$H100_DIR/../deploy-5.18.99/rehearse.sh"
H100_PREFIX="$(mktemp)"
python3 - "$H99_HARNESS" "$H100_PREFIX" <<'PY' || { echo "refusing: the previous release's rehearsal could not be cut before its last section (its sweeps)"; rm -f "$H100_PREFIX"; exit 2; }
import sys
src, dst = sys.argv[1], sys.argv[2]
anchor = 'echo; echo "== 99-10. what the rehearsal left behind, and what it never reached =="'
lines = open(src, encoding='utf-8').read().split('\n')
at = [i for i, l in enumerate(lines) if l == anchor]
assert len(at) == 1, len(at)
assert at[0] > 1800, at[0]                  # its deploy, rollback, --redeploy and the rollbacks of 99-8 come before it
pre = '\n'.join(lines[:at[0]]) + '\n'
# That rehearsal finds its own helpers beside itself ($0's folder): sourced from here, $0 is this file, so its one line
# naming that folder points at its own folder instead — the one edit, asserted.
old_dir = 'H99_DIR="$(cd "$(dirname "$0")" && pwd)"\n'
new_dir = 'H99_DIR="$(cd "$(dirname "$0")/../deploy-5.18.99" && pwd)"   # the next release\'s rehearsal: this one\'s own folder, where its helpers are\n'
assert pre.count(old_dir) == 1, pre.count(old_dir)
pre = pre.replace(old_dir, new_dir, 1)
assert pre.count(new_dir) == 1 and pre.count(old_dir) == 0
for need in ('H98_HARNESS="$H99_DIR/../deploy-5.18.98/rehearse.sh"', 'run99() {', 'run_line99() {', 'fresh99() {', 'nogo99() {',
             'went99() {', 'quiet99() {', 'checkgo99() {', 'audit99() {', 'hits99() {', 'vault99() {', 'quietobs99() {',
             'copy_back99() {', 'release_variant99() {', 'pinned_copy99() {', 'installed99() {', 'epoch99() {', 'rows_add99() {',
             'secrets99() {', 'tree99() {', 'fp99() {', 'ureq99() {', 'uother99() {', 'tok99() {', 'weak99() {',
             'OUT99="$SB/out99"; mkdir -p "$OUT99"; STATE99="$OUT99/state-5.18.99.env"', 'R99="$SB/r99"', 'H99="$SB/rh99"',
             'U99="$SB/ucrm99"', 'trap cleanup99 EXIT', 'LOGW99() {',
             'echo; echo "== 99-7. --redeploy after the rollback, all three quoting flags typed where only Q-G5 needs its =="',
             'echo; echo "== 99-8. a rollback whose copy leaves 5.18.99\'s debug route behind =="'):
    assert pre.count(need) >= 1, need
last = [l for l in pre.split('\n') if l.strip()][-1]
assert last.startswith('check "$(has "$OUT" \'stray-store quarantine (check): PASSED.\')" "yes" "…the quarantine\'s own --check after that rollback'), last[:90]
open(dst, 'w', encoding='utf-8').write(pre)
PY
# shellcheck disable=SC1090
. "$H100_PREFIX"
rm -f "$H100_PREFIX"
for f100 in mutant checkout_state row_set sqx qj okline failline noteline has fails live data94 kept_state oldname printed qrun; do
  declare -F "$f100" >/dev/null || { echo "refusing: the rehearsals this one runs did not define $f100()"; exit 2; }
done
FAIL_PRE100=$FAILN   # the earlier rehearsals' failures, before this release's own sections: the last line names them

# ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
# 5.18.100's own rehearsal, on the sandbox built above: 5.18.98 serving after 99-8's rollbacks, 5.18.99 to be deployed
# again as production has it.
# ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
REL100=release/5.18.100; DEPLOY100="$REPO/$F100"
DEV100=tech-100                                      # the change on the development branch
ADDED100="tests/test_sales_numbers_mutants.php"
CHANGED100="SAFETY.md lib/SalesNumbersAdmin.php manifest.json tabs/engage/wa_ai_setup.php tests/test_bank_boundaries.php tests/test_bank_screen.php tests/test_cashbook_physical_list.php tests/test_distributor_apply.php tests/test_distributor_link_ucrm.php tests/test_distributor_notify.php tests/test_distributor_registry.php tests/test_distributor_territory.php tests/test_quote_company.php tests/test_sales_numbers.php"
N100=$(wc -w <<<"$ADDED100 $CHANGED100")            # 15
if git -C "$R" ls-files --error-unmatch "$F100" >/dev/null 2>&1; then
  cmp -s "$R/$F100" "$DEPLOY100" || { echo "refusing: $F100 differs from the committed one — commit it, then rehearse"; exit 2; }
  WHICH100="the committed script"
else cp "$R/$F100" "$DEPLOY100"; WHICH100="the working copy (not yet committed; untracked in the clone)"; fi
git -C "$REPO" fetch -q origin "refs/heads/$REL100:refs/remotes/origin/$REL100" 2>/dev/null || true
git -C "$REPO" fetch -q origin "refs/heads/$DEV100:refs/remotes/origin/$DEV100" 2>/dev/null || true
P100="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT="\([^"]*\)".*/\1/p' "$DEPLOY100")"
P100F="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT_FULL="\([^"]*\)".*/\1/p' "$DEPLOY100")"
T1SIG100="$(sed -n 's/^T1_SIGNATURE="\([^"]*\)".*/\1/p' "$DEPLOY100")"
OUT100="$SB/out100"; mkdir -p "$OUT100"; STATE100="$OUT100/state-5.18.100.env"
R100="$SB/r100"; mkdir -p "$R100/logs" "$R100/mutants" "$R100/sheets" "$R100/exec" "$R100/scan" "$R100/vault" "$R100/tok" "$R100/ucrm"
H100="$SB/rh100"; mkdir -p "$H100/v"; cp "$H100_DIR/rh100-oracle.php" "$H100/"
chmod 644 "$H100/rh100-oracle.php"; chown 1000:1000 "$H100/v"; chmod 700 "$H100/v"
LOGW100() { printf '2>&1 | ( umask 077; exec tee %s/%s-%s.log )' "$OUT100" "$1" "$DATE_TS98"; }   # the script's LOGW, as it prints it
EXP_AFTER100="cd $REPO && bash scripts/deploy-5.18.100.sh --after-only $(LOGW100 after)"
EXP_ROLLBACK100="cd $REPO && bash scripts/deploy-5.18.100.sh --rollback $(LOGW100 rollback)"
EXP_DEPLOY100="cd $REPO && bash scripts/deploy-5.18.100.sh $(LOGW100 deploy)"
EXP_REDEPLOY100="cd $REPO && bash scripts/deploy-5.18.100.sh --redeploy $(LOGW100 redeploy)"
EXP_RUNBOOK100="cd $REPO && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.100 && mkdir -p $OUT100 && bash scripts/deploy-5.18.100.sh $(LOGW100 deploy)"
left_tmp100() { ls -d /tmp/dnb-5.18.100-* 2>/dev/null | wc -l | tr -d ' '; }
host_code100() { ls -d "$OUT100"/code-* "$OUT100"/p3b-* "$OUT100"/br1-* "$OUT100"/domainb-* "$OUT100"/tn-* 2>/dev/null | wc -l | tr -d ' '; }
backups100() { ls -d "$OUT100"/backup-* 2>/dev/null | wc -l | tr -d ' '; }
states100() { ls -1 "$OUT100"/state-5.18.100.env* "$OUT100"/rollback-state-* 2>/dev/null | sort | while IFS= read -r f; do printf '%s %s\n' "${f##*/}" "$(sha256sum "$f" | cut -c1-16)"; done | sha256sum | cut -c1-16; }
fresh100() {   # the run folder as a new run finds it: the state files and the row digests kept; every log and sheet moved aside
  find "$OUT100" -mindepth 1 -maxdepth 1 -name '*.log' -exec mv -t "$R100/logs/" {} + 2>/dev/null
  find "$OUT100" -mindepth 1 -maxdepth 1 -name 'cash-*.txt' -exec mv -t "$R100/sheets/" {} + 2>/dev/null
  find "$OUT100" -mindepth 1 -maxdepth 1 ! -name 'state-5.18.100.env*' ! -name 'rollback-state-*' ! -name 'cih-rows-*' -exec rm -rf {} +; base_log; }
installed100() {   # installed100 COMMIT — the number of files 5.18.99 → 5.18.100 touches that are NOT installed as COMMIT has them
  local f rel bad=0
  for f in $(dl AM "$P99" "$P100"); do
    rel="${f#$P/}"
    if git -C "$REPO" cat-file -e "$1:$f" 2>/dev/null; then
      [ "$(git -C "$REPO" show "$1:$f" | sha256sum | cut -c1-64)" = "$(sha256sum "$PD/$rel" 2>/dev/null | cut -c1-64)" ] || bad=$((bad+1))
    fi
  done
  echo "$bad"
}
last100() { printf '%03d' "$(cat "$SB/runs")"; }
run100() {   # [--answer WORD] [--script FILE] [--env K=V]…, THEN the script's options — as run99, this release's folders
  local answer="" script="$DEPLOY100" extra=() n tag o cmd
  while :; do case "${1:-}" in --answer) answer="$2"; shift 2 ;; --script) script="$2"; shift 2 ;; --env) extra+=("$2"); shift 2 ;; *) break ;; esac; done
  : > "$SB/docker.log"; : > "$U99/requests.log"
  n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"; tag="$(printf '%03d' "$n")"
  vt_host > "$R100/vault/run-$tag.before"; : > "$R100/exec/run-$tag.rec"; : > "$R100/scan/run-$tag.hits"; : > "$R100/tok/run-$tag.tok"
  cmd="$(printf '%q ' env PATH="$SB/datebin:$SB/bin:$PATH" FAKE_TODAY="$AFTER94" DNB_OUT="$OUT100" DNB_RECORDS="$RECS" DNB_WEBSITE="$WEB_OK" GUARD_SECONDS=3 DN_DATA_DIR="$DATA" \
         FAKE_EXEC_LOG="$R100/exec/run-$tag.rec" FAKE_TMP_SCAN="$SCAN96" FAKE_TMP_HITS="$R100/scan/run-$tag.hits" FAKE_TOK_HITS="$R100/tok/run-$tag.tok" \
         ${extra[@]+"${extra[@]}"} bash "$script" --plugin-base "$PLUGIN_BASE" "$@")"
  o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r')"
  vt_host > "$R100/vault/run-$tag.after"; cp "$U99/requests.log" "$R100/ucrm/run-$tag.req"
  case "$o" in *"unknown option:"*) echo "run100-$n" >> "$SB/bad-runs" ;; esac
  if [ -n "${R100_MUTANT_OUT:-}" ]; then printf '%s\n' "$o" > "$R100/mutants/run-$tag.txt"; else printf '%s\n' "$o" > "$R100/run-$tag.txt"; fi
  [ -z "${REHEARSE_KEEP:-}" ] || printf '%s\n' "$o" > "$REHEARSE_KEEP/run100-$tag-${answer:-none}-$(basename "$script" .sh)$(printf '%s' "$*" | tr -c 'A-Za-z0-9-' '_').txt"
  printf '%s' "$o"
}
run_line100() {   # run_line100 WORD LINE — a command line exactly as a summary printed it, through a pseudo-terminal, WORD typed
  local answer="$1" line="$2" n tag o cmd
  : > "$SB/docker.log"; : > "$U99/requests.log"
  n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"; tag="$(printf '%03d' "$n")"
  vt_host > "$R100/vault/run-$tag.before"; : > "$R100/exec/run-$tag.rec"; : > "$R100/scan/run-$tag.hits"; : > "$R100/tok/run-$tag.tok"
  cmd="$(printf '%q ' env PATH="$SB/datebin:$SB/bin:$PATH" FAKE_TODAY="$AFTER94" DNB_OUT="$OUT100" DNB_RECORDS="$RECS" DNB_WEBSITE="$WEB_OK" GUARD_SECONDS=3 DN_DATA_DIR="$DATA" PLUGIN_BASE="$PLUGIN_BASE" \
         FAKE_EXEC_LOG="$R100/exec/run-$tag.rec" FAKE_TMP_SCAN="$SCAN96" FAKE_TMP_HITS="$R100/scan/run-$tag.hits" FAKE_TOK_HITS="$R100/tok/run-$tag.tok" bash -c "$line")"
  o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r')"
  vt_host > "$R100/vault/run-$tag.after"; cp "$U99/requests.log" "$R100/ucrm/run-$tag.req"
  case "$o" in *"unknown option:"*) echo "line100-$n" >> "$SB/bad-runs" ;; esac
  printf '%s\n' "$o" > "$R100/line-$tag.txt"
  [ -z "${REHEARSE_KEEP:-}" ] || printf '%s\n' "$o" > "$REHEARSE_KEEP/line100-$tag-${answer:-none}.txt"
  printf '%s' "$o"
}
went100() { echo "$(has "$1" 'GO — evidence recorded')$(has "$1" 'Type DEPLOY')"; }   # the run reached the question
quiet100() { echo "$(live)|$(backups100)|$(data94)|$(kept_state)|$(oldname)"; }     # nothing deployed, backed up or changed
nogo100() {   # nogo100 LABEL EXPECTED-TEXT OUTPUT [DATA-DIGEST] — refused with that text and "Nothing was changed", never the
              # question, nothing deployed, backed up or changed: 5.18.99 serving
  check "$(has "$3" "$2")$(has "$3" 'Nothing was changed. Send the log file.')$(went100 "$3")|$(quiet100)" "yesyesnono|$P99|0|${4:-$D100}|$KEPT100|0" "$1"
}
checkgo100() {   # checkgo100 OUTPUT — --check said GO: stage A held — nothing asked, nothing backed up, no state written
  echo "$(has "$1" "  5.18.100 (check): GO — stage A holds.")$(has "$1" '  --check           stage A alone, every refusal and note of a deploy')$(went100 "$1")$(fails "$1")|$(backups100)|$(states100)"
}
audit100() { python3 "$H100/rh100.py" audit "$R100/exec/run-$1.rec"; }   # audit100 TAG — copies=<n> novault=<n> plugin=<n> adj=<id|->
hits100() { local n; n="$(grep -c . "$R100/scan/run-$1.hits" 2>/dev/null)"; echo "${n:-0}"; }
vault100() { echo "$(cat "$R100/vault/run-$1.before")>$(cat "$R100/vault/run-$1.after")"; }
uother100() { python3 "$H99/rh99.py" u99other "$R100/ucrm/run-$1.req"; }   # the requests to the stand-in uCRM that were not a GET
copy_back100() {   # the 5.18.100 copy N minutes ago: lib/SalesNumbersAdmin.php's time (its stamp_copy, COPY_MARK), the recorded
                   # copy time, the start before it
  touch -d "-$1 minutes" "$PD/lib/SalesNumbersAdmin.php"; local t; t="$(stat -c %Y "$PD/lib/SalesNumbersAdmin.php")"
  sed -i -e "s/^COPY_AT=.*/COPY_AT=$t/" -e "s/^DEPLOYED_AT=.*/DEPLOYED_AT=$(date -u -d "@$(( t - 60 ))" +%Y-%m-%dT%H:%M:%SZ)/" "$STATE100"; }
o100() {   # o100 — this rehearsal's own reading of who the card may offer, as the store's user, with a vault of its own (JSON)
  ( cd "$H100/v" && setpriv --reuid 1000 --regid 1000 --clear-groups -- env DN_VAULT_FILE="$H100/v/o-vault.json" "$PHPBIN" "$H100/rh100-oracle.php" "$PD" "$DATA" 2>/dev/null ); rm -f "$H100/v/o-vault.json"
}
names100() { local n=0 f; for f in "$@"; do [ -f "$f" ] && grep -qF 'RH100SECRET' -- "$f" && n=$((n+1)); done; echo "$n"; }   # how many name a seeded staff record
ANCH100="$SB/anchors100.tsv"
cat > "$ANCH100" <<'ANCH'
tng1	deploy	  [ "$tp" = "$TN_TEXT_PIN" ] || stop "NO-GO (TN-G1): the pin's owner rule is not the reviewed one	  true || stop "NO-GO (TN-G1): the pin's owner rule is not the reviewed one
tng2	deploy	  [ -z "$s" ] || stop "NO-GO (TN-G2): the owner lists on a copy of the book are not as the rule makes them	  true || stop "NO-GO (TN-G2): the owner lists on a copy of the book are not as the rule makes them
tng3	deploy	        if [ "$how" = "stop" ]; then stop "NO-GO ($id): $m, then run this again. Nothing was changed. Send the log file"; else bad "$id $m"; fi	        if [ "$how" = "stop" ]; then true; else ok "$id $m"; fi
tnca3	deploy	        if [ "$how" = "stop" ]; then stop "NO-GO ($id): $m, then run this again. Nothing was changed. Send the log file"; else bad "$id $m"; fi	        if [ "$how" = "stop" ]; then stop "NO-GO ($id): $m, then run this again. Nothing was changed. Send the log file"; else ok "$id $m"; fi
copymark	deploy	COPY_MARK="lib/SalesNumbersAdmin.php"	COPY_MARK="lib/KycService.php"
techadmin	sna	        if (in_array($role, self::TECHNICIAN_ROLES, true)) return \StaffDirectory::isAdmin($r) ? '' : 'technician';	        if (in_array($role, self::TECHNICIAN_ROLES, true)) return 'technician';
tnown	deploy	          [ "$e" != "-" ] && [ "${e%%:*}" = "$h" ] || continue	          continue
known	deploy	        if (in_array($r, $KNOWN, true)) $shown[] = $r; else $odd++;	        $shown[] = $r;
ANCH
a100() { awk -F'\t' -v k="$1" '$1 == k { print $3; exit }' "$ANCH100"; }
r100() { awk -F'\t' -v k="$1" '$1 == k { print $4; exit }' "$ANCH100"; }
weak100() {   # weak100 NAME KEY… — a copy of the script in the clone's scripts/ with each KEY's anchor replaced, each exactly once
  local name="$1" k dst="$REPO/scripts/.$1.sh"; shift
  cp "$DEPLOY100" "$dst"
  for k in "$@"; do
    python3 - "$dst" "$(a100 "$k")" "$(r100 "$k")" <<'PY' || echo "weak100:$k" >> "$SB/anchor-missing"
import sys
f, a, b = sys.argv[1:4]; s = open(f).read()
assert a and s.count(a) == 1, (a[:60], s.count(a))
open(f, 'w').write(s.replace(a, b))
PY
  done
  echo "$dst"
}
release_variant100() {   # release_variant100 PATH OLD NEW — the release commit with one file edited (OLD exactly once), cut on 5.18.99
  local idx="$SB/variant100.idx" blob tree c
  GIT_INDEX_FILE="$idx" git -C "$REPO" read-tree "$P100"
  blob="$(git -C "$REPO" show "$P100:$P/$1" | python3 -c 'import sys
s = sys.stdin.read(); old, new = sys.argv[1], sys.argv[2]
assert s.count(old) == 1, "variant anchor not unique"
sys.stdout.write(s.replace(old, new))' "$2" "$3" | git -C "$REPO" hash-object -w --stdin)"
  [ "$blob" = "$(printf '' | git -C "$REPO" hash-object --stdin)" ] && echo "release_variant100:$1" >> "$SB/anchor-missing"
  GIT_INDEX_FILE="$idx" git -C "$REPO" update-index --cacheinfo "100644,$blob,$P/$1"
  tree="$(GIT_INDEX_FILE="$idx" git -C "$REPO" write-tree)"; rm -f "$idx"
  c="$(git -C "$REPO" -c user.name=rehearsal -c user.email=rehearsal@example.test commit-tree "$tree" -p "$(git -C "$REPO" rev-parse "$P99")" -m "rehearsal variant: $1")"
  git -C "$REPO" rev-parse --short "$c"
}
row_keep100() { php -r '$p = new PDO("sqlite:" . $argv[1]); echo (string)$p->query("SELECT data FROM kyc_config WHERE id = 0")->fetchColumn();' "$DATA/plugin.sqlite3" > "$SB/kyc-row100.kept"; }   # the store row, byte for byte
row_back100() { php -r '$p = new PDO("sqlite:" . $argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $p->prepare("UPDATE kyc_config SET data = ? WHERE id = 0")->execute([file_get_contents($argv[2])]);' "$DATA/plugin.sqlite3" "$SB/kyc-row100.kept"; }
# The readers: 5.18.99's, taught this release's folders — its run folders in /tmp and the owner checks' code copies (t-i,
# t-p) — by asserted replacements. ──────────────────────────────────────────────────────────────────────────────────
python3 - "$H99/rh99.py" "$H100/rh100.py" <<'PY' || { echo "readers:rh100" >> "$SB/anchor-missing"; echo "refusing: this rehearsal's readers could not be derived from the previous release's"; exit 2; }
import sys
s = open(sys.argv[1]).read()
reps = [
    ("root = re.compile(r'^/tmp/dnb-5\\.18\\.99-(?:cih-[^/]+/[^/]+/(?:t|d|p0root|k-(?:found|off|on)|c-(?:old|new)|g-(?:i|p|c0|c1)|l|u)/dishnet-hybrid-sudan|code-[^/]+/p3b/[^/]+/dishnet-hybrid-sudan)(?:/|$)')",
     "root = re.compile(r'^/tmp/dnb-5\\.18\\.100-(?:cih-[^/]+/[^/]+/(?:t|d|p0root|k-(?:found|off|on)|c-(?:old|new)|g-(?:i|p|c0|c1)|l|u|t-(?:i|p))/dishnet-hybrid-sudan|code-[^/]+/p3b/[^/]+/dishnet-hybrid-sudan)(?:/|$)')"),
    ("if not env.get('DN_VAULT_FILE', '').startswith('/tmp/dnb-5.18.99-'): novault += 1",
     "if not env.get('DN_VAULT_FILE', '').startswith('/tmp/dnb-5.18.100-'): novault += 1"),
]
for old, new in reps:
    assert s.count(old) == 1, old[:70]
    s = s.replace(old, new)
open(sys.argv[2], 'w').write(s)
PY
# The observers search this release's folders in /tmp too (the fake docker's one list, extended, asserted), and the token
# observer counts this release's quoting folders beside 5.18.99's.
python3 - "$SB/bin/docker" <<'PY' || echo "fakedocker:observers100" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]; s = open(p).read()
old = ' /tmp/dnb-5.18.99-code-*/t4run; do\n'
assert s.count(old) == 1, s.count(old)
assert s.count('/tmp/dnb-5.18.100-') == 0
s = s.replace(old, ' /tmp/dnb-5.18.99-code-*/t4run /tmp/dnb-5.18.100-cih-* /tmp/dnb-5.18.100-ss-* /tmp/dnb-5.18.100-code-*/p3b /tmp/dnb-5.18.100-code-*/t1run /tmp/dnb-5.18.100-code-*/t4run; do\n', 1)
old2 = 'printf \'%s %s\\n\' "$(find /tmp/dnb-5.18.99-cih-* -name token.json 2>/dev/null | grep -c .)" "${_rh_m:--}" >> "$FAKE_TOK_HITS"'
assert s.count(old2) == 1, s.count(old2)
s = s.replace(old2, 'printf \'%s %s\\n\' "$(find /tmp/dnb-5.18.99-cih-* /tmp/dnb-5.18.100-cih-* -name token.json 2>/dev/null | grep -c .)" "${_rh_m:--}" >> "$FAKE_TOK_HITS"', 1)
assert s.count('/tmp/dnb-5.18.100-') == 6
open(p, 'w').write(s)
PY
check "$(bash -n "$SB/bin/docker" && echo parses)|$(python3 -c 'import ast, sys; ast.parse(open(sys.argv[1]).read()); print("parses")' "$H100/rh100.py" 2>&1)|$(php -l "$H100/rh100-oracle.php" 2>&1 | grep -c '^No syntax errors')" "parses|parses|1" \
  "control: the fake docker with this release's folders, this rehearsal's readers and its oracle parse"

echo; echo "== 100-pre. the sandbox as production is: 5.18.99 deployed again, its --after-only and the quarantine's --check passed after its copy; technicians among the staff =="
fresh99; OUT="$(run99 --answer DEPLOY --redeploy)"
check "$(fails "$OUT")$(has "$OUT" '5.18.99 (deploy): PASSED')$(live)$(installed99 "$P99")$(inst_ver)" "0yes${P99}05.18.99" "pre-a 5.18.99 deployed again with --redeploy, DEPLOY typed: PASSED, $P99 serving, every file as its pin has it"
copy_back99 30; COPY99B="$(sed -n 's/^COPY_AT=//p' "$STATE99" | tail -1)"
[ "$(epoch99 "$COPY99B")" = "epoch" ] && set_sched $(( COPY99B + 300 )); base_log
ALINE99="$(printed "$OUT" "cd $REPO && bash scripts/deploy-5.18.99.sh --after-only")"
fresh99; OUT="$(run_line99 '' "$ALINE99")"
check "$(epoch99 "$COPY99B")|$(fails "$OUT")$(has "$OUT" '5.18.99 (after): PASSED')$(okline "$OUT" R19 'the quarantine holds: master ran the retry job at' "after the copy (at $(utc "$COPY99B"))")|$(both_copies "$BK97")" "epoch|0yesyes|11" \
  "pre-b its copy moved thirty minutes back; its --after-only, as printed: PASSED, R19 conclusive; ECO Bank's switch ON in both copies"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "pre-c the quarantine's own --check, as the runbook orders after 5.18.99's --after-only: PASSED, begun after 5.18.99's copy"
[ -e "$RECS/dnb-5.18.99" ] || ln -s "$OUT99" "$RECS/dnb-5.18.99"
check "$(readlink -f "$RECS/dnb-5.18.99")|$(readlink -f "$RECS/dnb-5.18.98")|$(readlink -f "$RECS/dnb-5.18.97")|$(readlink -f "$RECS/dnb-5.18.96")|$(readlink -f "$RECS/dnb-5.18.95")" \
      "$(readlink -f "$OUT99")|$(readlink -f "$OUT98")|$(readlink -f "$OUT97")|$(readlink -f "$OUT96")|$(readlink -f "$OUT95")" \
  "pre-d 5.18.99's, 5.18.98's, 5.18.97's, 5.18.96's and 5.18.95's records where 5.18.100's A14 looks for them (<records>/dnb-5.18.9x), as on the server"
O100_PRE="$(o100)"
SEED100='{"retailers.json":[
 {"_rh99":true,"_rh100":true,"name":"RH100SECRET-Tech-One","role":"support","is_active":true},
 {"_rh99":true,"_rh100":true,"name":"","role":"support_engineer","is_active":true},
 {"_rh99":true,"_rh100":true,"name":"RH100SECRET-Tech-Lead","role":"support_leader","is_active":true,"is_admin":true},
 {"_rh99":true,"_rh100":true,"name":"RH100SECRET-Tech-Gone","role":"support_engineer","is_active":false}]}'
RS100="$(rows_add99 "$SEED100")"
O100_0="$(o100)"; D100="$(data94)"; QREC100="$(sha256sum "$QREC" | cut -c1-16)"; KEPT100="$(kept_state)"
check "$RS100|$(qj "$O100_0" tech)-$(qj "$O100_PRE" tech)|$(qj "$O100_0" techadmin)-$(qj "$O100_PRE" techadmin)|$(qj "$O100_0" unnamedtech)-$(qj "$O100_PRE" unnamedtech)|$(qj "$O100_0" sales)-$(qj "$O100_PRE" sales)|$(qj "$O100_0" inbox)" \
      "{\"rows\":4}|$(( $(qj "$O100_PRE" tech) + 2 ))-$(qj "$O100_PRE" tech)|$(( $(qj "$O100_PRE" techadmin) + 1 ))-$(qj "$O100_PRE" techadmin)|$(( $(qj "$O100_PRE" unnamedtech) + 1 ))-$(qj "$O100_PRE" unnamedtech)|$(qj "$O100_PRE" sales)-$(qj "$O100_PRE" sales)|unset" \
  "pre-e four staff records added through the installed code's own writer — this rehearsal's own reading counts two more technicians the card may offer (one with no name), one more administrator's technician account, the salespeople as before; wa_inbox_roles unset"
check "$( [ "$(qj "$O100_0" sales)" -gt 0 ] && echo sales)|$( [ "$(qj "$O100_0" tech)" -ge 2 ] && echo tech)" "sales|tech" \
  "pre-e' …neither list is empty: the TN checks' counts are not vacuous"
EXP_NUM100="$(qj "$O100_0" numbers)"

echo; echo "== 100-0. what is rehearsed: 5.18.100 — the controls =="
echo "  the script     $WHICH100"
check "$P100|$P100F" "$(git -C "$REPO" rev-parse --short "origin/$REL100" 2>/dev/null || echo none)|$(git -C "$REPO" rev-parse "origin/$REL100" 2>/dev/null || echo none)" "the script is pinned to the release commit on $REL100, short and in full ($P100)"
check "$(git -C "$REPO" rev-parse --short "$P100^" 2>/dev/null)|$(git -C "$REPO" rev-parse "$P100^" 2>/dev/null)|$(ver "$P100")$(ver "$P99")" "$P99|$(sed -n 's/^BASELINE_COMMIT_FULL="\([^"]*\)".*/\1/p' "$DEPLOY100")|5.18.1005.18.99" \
  "…cut on 5.18.99 ($P99), the script's baseline in full; the pin says 5.18.100, its parent 5.18.99"
check "$(dl D "$P99" "$P100" | grep -c .)|$(dl A "$P99" "$P100" | grep -c .)|$(dl AM "$P99" "$P100" | grep -c .)|$(git -C "$REPO" diff --no-renames --name-only --diff-filter=CRTUXB "$P99" "$P100" | grep -c .)|$(git -C "$REPO" diff --name-only "$P99" "$P100" | grep -vc "^$P/")" "0|1|$N100|0|0" \
  "the delta: $N100 files under the plugin, one added, none deleted, renamed or retyped, nothing outside it"
check "$(dl A "$P99" "$P100" | sed "s#^$P/##" | LC_ALL=C sort | paste -sd' ' -)|$(dl M "$P99" "$P100" | sed "s#^$P/##" | LC_ALL=C sort | paste -sd' ' -)|$(sed -n 's/^EXPECTED_ADDED="\(.*\)"$/\1/p' "$DEPLOY100")|$(sed -n 's/^EXPECTED_CHANGED="\(.*\)"$/\1/p' "$DEPLOY100")" \
      "$ADDED100|$CHANGED100|$ADDED100|$CHANGED100" "…exactly the reviewed files, as the script lists them"
check "$(dl AM "$P99" "$P100" | grep -c "^$P/migrations/")" "0" "no migration"
PS100="$(sed -n '/^PIN_SHA256S="$(cat <<.SUMS.$/,/^SUMS$/p' "$DEPLOY100" | grep -E '^[0-9a-f]{64}  ' | while read -r s f; do [ "$(git -C "$REPO" show "$P100:$P/$f" | sha256sum | cut -c1-64)" = "$s" ] && echo ok || echo "bad:$f"; done | sort | uniq -c | tr -s ' ' | paste -sd, -)"
KS100="$(sed -n '/^PREV_KEPT_SHA256S="$(cat <<.SUMS.$/,/^SUMS$/p' "$DEPLOY100" | grep -E '^[0-9a-f]{64}  ' | while read -r s f; do a="$(git -C "$REPO" show "$P99:$P/$f" | sha256sum | cut -c1-64)"; b="$(git -C "$REPO" show "$P100:$P/$f" | sha256sum | cut -c1-64)"; [ "$a|$b" = "$s|$s" ] && echo ok || echo "bad:$f"; done | sort | uniq -c | tr -s ' ' | paste -sd, -)"
PC100="$(sed -n '/^PREV_CHANGED_SHA256S="$(cat <<.SUMS.$/,/^SUMS$/p' "$DEPLOY100" | grep -E '^[0-9a-f]{64}  ' | while read -r s f; do [ "$(git -C "$REPO" show "$P99:$P/$f" | sha256sum | cut -c1-64)" = "$s" ] && echo ok || echo "bad:$f"; done | sort | uniq -c | tr -s ' ' | paste -sd, -)"
check "$PS100|$KS100|$PC100" " 15 ok| 60 ok| 14 ok" "every sha256 the script pins, computed here from Git: the 15 release files at the pin, the 60 kept ones in both commits, the 14 changed ones at 5.18.99"
check "$(for f in lib/SalesNumbersAdmin.php tabs/engage/wa_ai_setup.php; do [ "$(git -C "$REPO" show "$P100:$P/$f" | sha256sum)" = "$(git -C "$REPO" show "origin/$DEV100:$P/$f" 2>/dev/null | sha256sum)" ] && echo same; done | grep -c same)" "2" \
  "the class and its page are the development branch's ($DEV100), byte for byte"
check "$(sed -n 's/^COPY_MARK="\([^"]*\)".*/\1/p' "$DEPLOY100")|$(grep -cxF "$P/$(sed -n 's/^COPY_MARK="\([^"]*\)".*/\1/p' "$DEPLOY100")" <<<"$(dl M "$P99" "$P100")")|$(sed -n 's/^T1_SIGNATURE="\([^"]*\)".*/\1/p' "$DEPLOY100")|$(sed -n 's/^T1_SIGNATURE="\([^"]*\)".*/\1/p' "$DEPLOY99")" \
      "lib/SalesNumbersAdmin.php|1|$(sed -n 's/^T1_SIGNATURE="\([^"]*\)".*/\1/p' "$DEPLOY99")|$(sed -n 's/^T1_SIGNATURE="\([^"]*\)".*/\1/p' "$DEPLOY99")" \
  "the copy's time is read off a file this release changes (COPY_MARK, lib/SalesNumbersAdmin.php — 5.18.99's lib/KycService.php is not touched by it); T1's count 5.18.99's"
H100B="$(sed -n '/^# The deploy\. Run as root/,/^# The rollback is a separate command/p' "$DEPLOY100")"
check "$(grep -cxF '#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.100 && mkdir -p /root/dnb-5.18.100 && bash scripts/deploy-5.18.100.sh 2>&1 | ( umask 077; exec tee /root/dnb-5.18.100/deploy-$(date -u +%Y%m%dT%H%M%SZ).log )' <<<"$H100B")$(grep -cxF '#   cd /opt/dishnet && bash scripts/deploy-5.18.100.sh --after-only 2>&1 | ( umask 077; exec tee /root/dnb-5.18.100/after-$(date -u +%Y%m%dT%H%M%SZ).log )' <<<"$H100B")$(grep -cE -- '--rollback|--put-back|--redeploy|git checkout [0-9a-f]{7}' <<<"$H100B")|$(grep -cE '^#   cd ' <<<"$H100B")" \
      "110|2" "the header's two commands, each alone on its line with its own log; no rollback among them"
echo "  …    the rule as text, at the pin and at 5.18.99, by the script's own reader:"
TNT_PIN="$(bash -c '. <(sed -n "/^SNA_LIB=/,/^TN_TEXT_BASE=/p;/^tn_text() {/,/^}/p" "$1"); d="$(mktemp -d)"; git -C "$2" show "$3:$4/lib/SalesNumbersAdmin.php" > "$d/l"; git -C "$2" show "$3:$4/tabs/engage/wa_ai_setup.php" > "$d/p"; tn_text "$d/l" "$d/p"; rm -rf "$d"' _ "$DEPLOY100" "$REPO" "$P100" "$P")"
TNT_BASE="$(bash -c '. <(sed -n "/^SNA_LIB=/,/^TN_TEXT_BASE=/p;/^tn_text() {/,/^}/p" "$1"); d="$(mktemp -d)"; git -C "$2" show "$3:$4/lib/SalesNumbersAdmin.php" > "$d/l"; git -C "$2" show "$3:$4/tabs/engage/wa_ai_setup.php" > "$d/p"; tn_text "$d/l" "$d/p"; rm -rf "$d"' _ "$DEPLOY100" "$REPO" "$P99" "$P")"
check "$TNT_PIN|$TNT_BASE" "$(sed -n 's/^TN_TEXT_PIN="\([^"]*\)".*/\1/p' "$DEPLOY100")|$(sed -n 's/^TN_TEXT_BASE="\([^"]*\)".*/\1/p' "$DEPLOY100")" \
  "control: TN-G1's reader gives the pin TN_TEXT_PIN and 5.18.99 TN_TEXT_BASE, both read from Git here"

echo; echo "== 100-c. --check: stage A alone — GO on 5.18.99 as production has it; the owner lists and wa_inbox_roles =="
FP100="$(fp99)"; fresh100; S100C="$(states100)"; OUT="$(run100 --check)"; TC="$(last100)"
check "$(checkgo100 "$OUT")" "yesyesnono0|0|$S100C" "c1 --check is GO, never asks, backs nothing up and writes no state file (the state files as before the run)"
check "$(okline "$OUT" TN-G1 'the pin' 'declares the technician roles once')$(okline "$OUT" TN-G2 "the installed salespeople() offers the $(qj "$O100_0" sales) active salesperson account(s) and nobody else" "the pin's owners() offers the same $(qj "$O100_0" sales) and the $(qj "$O100_0" tech) active technician account(s)" "($(qj "$O100_0" techadmin) left out)")$(okline "$OUT" TN-G3 'wa_inbox_roles is unset in the live store row')" "yesyesyes" \
  "c2 TN-G1, TN-G2 and TN-G3 ok — TN-G2's counts this rehearsal's own reading's"
check "$(noteline "$OUT" TN-N1 "on the copy: $(python3 -c 'import sys
n = dict(x.split(":") for x in sys.argv[1].split(","))
print("a salesperson %s · a technician %s · an admin technician account %s · an inactive account %s · another role %s · no staff row %s · a department %s" % tuple(n[k] for k in ("sales","tech","techadmin","inactive","other","unknown","none")))' "$EXP_NUM100")")" "yes" \
  "c3 TN-N1 the registry's numbers by their owner's kind, as this rehearsal reads them"
check "$(fp99)|$(names100 "$R100/run-$TC.txt")|$(audit100 "$TC" | sed -E 's/^copies=[0-9]+ //; s/ adj=.*//')|$(vault100 "$TC" | awk -F'>' '{print ($1 == $2 ? "same" : "moved")}')|$(uother100 "$TC")" "$FP100|0|novault=0 plugin=0|same|-" \
  "c4 nothing changed — tree, store, vault, settings, ucrm.json; no staff record's name printed; every PHP process on a copy with a vault of its own; GETs only"

echo; echo "== 100-1. a deploy's owner refusals, each before anything changes; every new check's weakened copy =="
row_keep100; row_set wa_inbox_roles "support,accountant"; fresh100; DPRE="$(data94)"; OUT="$(run100 --answer NO)"
nogo100 "1a wa_inbox_roles granting a technician role the shared Inbox: NO-GO at TN-G3, before the question" \
  "NO-GO (TN-G3): wa_inbox_roles grants the shared Inbox to support,accountant, and support own, or after 5.18.100 may own, a number" "$OUT" "$DPRE"
row_set wa_inbox_roles "sales_staff"; fresh100; DPRE="$(data94)"; OUT="$(run100 --answer NO)"
nogo100 "1b …naming a sales role: NO-GO at TN-G3 too" "NO-GO (TN-G3): wa_inbox_roles grants the shared Inbox to sales_staff, and sales_staff own" "$OUT" "$DPRE"
row_set wa_inbox_roles "support"; M="$(weak100 tng3w tng3)"; fresh100; OUT="$(R100_MUTANT_OUT=1 run100 --script "$M" --check)"; rm -f "$M"
check "$(has "$OUT" '5.18.100 (check): GO')" "yes" "1c TN-G3's refusal weakened: a --check GO over a technician role in the Inbox (caught)"
row_set wa_inbox_roles " Accountant , admin, rh100secret-inbox"; fresh100; OUT="$(run100 --check)"
check "$(has "$OUT" '5.18.100 (check): GO')$(okline "$OUT" TN-G3 'grants the shared Inbox to accountant and 1 entry that is not a known role name (not shown) — none of them owns' "and none is the role a number's owner holds now")$(has "$OUT" 'rh100secret')$(grep -cE ' (gh|oroles|sig)=' <<<"$OUT")" "yesyesno0" \
  "1d …naming neither — admin dropped as WhatsAppAccess drops it, the case and the spaces too, and free text that is not a known role name counted, never printed: GO, TN-G3 ok; no keyed hash printed"
M="$(weak100 knownw known)"; fresh100; OUT="$(R100_MUTANT_OUT=1 run100 --script "$M" --check)"; rm -f "$M"
check "$(has "$OUT" 'rh100secret')" "yes" "1d' the probe weakened to show every entry: the free text printed (caught)"
row_back100
check "$(qj "$(o100)" inbox)|$(data94)" "unset|$D100" "…the store row put back byte for byte, wa_inbox_roles unset: the data as before"
VAR100="$(release_variant100 lib/SalesNumbersAdmin.php "$(a100 techadmin)" "$(r100 techadmin)")"
VSUM100="$(git -C "$REPO" show "$VAR100:$P/lib/SalesNumbersAdmin.php" | sha256sum | cut -c1-64)"
check "$(git -C "$REPO" rev-parse --short "$VAR100^")|$(git -C "$REPO" diff --name-only "$P100" "$VAR100" | paste -sd, -)" "$P99|$P/lib/SalesNumbersAdmin.php" \
  "control: the variant is cut on 5.18.99 and differs from the release in the class alone"
fresh100; OUT="$(run100 --answer NO --script "$(pinned_copy99 var100 "$VAR100" "$DEPLOY100")")"
nogo100 "1e a release whose rule takes an administrator's technician account in, re-pinned short and full but not its sha256: refused at A0" "the pin's files are not the reviewed 5.18.100's by sha256" "$OUT"
VC100="$(pinned_copy99 var100s "$VAR100" "$DEPLOY100" lib/SalesNumbersAdmin.php "$VSUM100")"
fresh100; OUT="$(run100 --answer NO --script "$VC100")"
nogo100 "1f …its sha256 re-pinned too: A0 lets it through, TN-G1 refuses it as text" "NO-GO (TN-G1): the pin's owner rule is not the reviewed one" "$OUT"
check "$(has "$OUT" "kind=0")" "yes" "…naming the rule's line it does not find (kind=0)"
python3 - "$VC100" "$(a100 tng1)" "$(r100 tng1)" <<'PY' || echo "weak100:vc-tng1" >> "$SB/anchor-missing"
import sys
f, a, b = sys.argv[1:4]; s = open(f).read(); assert s.count(a) == 1; open(f, 'w').write(s.replace(a, b))
PY
fresh100; OUT="$(run100 --answer NO --script "$VC100")"
nogo100 "1g …and with TN-G1 weakened, TN-G2 refuses the same release on its own, on the copy: the pin's list is not the rule's" "NO-GO (TN-G2): the owner lists on a copy of the book are not as the rule makes them: the pin's list (owners: match no" "$OUT"
python3 - "$VC100" "$(a100 tng2)" "$(r100 tng2)" <<'PY' || echo "weak100:vc-tng2" >> "$SB/anchor-missing"
import sys
f, a, b = sys.argv[1:4]; s = open(f).read(); assert s.count(a) == 1; open(f, 'w').write(s.replace(a, b))
PY
fresh100; OUT="$(R100_MUTANT_OUT=1 run100 --script "$VC100" --check)"; rm -f "$VC100" "$REPO/scripts/.var100.sh"
check "$(has "$OUT" '5.18.100 (check): GO')" "yes" "1h …with both weakened, a --check GO over that release (caught)"
check "$(data94)|$(live)" "$D100|$P99" "…nothing changed by any of them; 5.18.99 serving"

echo; echo "== 100-4. the deploy, as the operator runs it — DEPLOY typed =="
LEDGER100="$(ledger95)"
fresh100; OUT="$(run100 --answer DEPLOY)"; OUT100D="$OUT"; T100D="$(last100)"
echo "  …    the deploy's own tally:$(grep -E '^  checks +[0-9]+ ok, ' <<<"$OUT" | sed -E 's/^  checks +/ /')"
check "$(fails "$OUT")$(has "$OUT" '5.18.100 (deploy): PASSED')$(live)$(installed100 "$P100")$(inst_ver)" "0yes${P100}05.18.100" "4 the deploy PASSES: 0 failed, $P100 serving, every file as the pin has it"
for l in "ok    A0 the release delta is exactly 5.18.100's 15 files — 1 added, 14 changed, each the reviewed one by the sha256 this script pins (15 of 15)" \
         "ok    BR1 the installed plugin is live 5.18.99 ($P99): all " \
         "ok    Q-G1 installed is 5.18.99 ($P99): its manifest says 5.18.99; all 14 files 5.18.100 changes carry the sha256 pinned for 5.18.99, and all 60 files it must not touch" \
         "ok    A14 the stray store is quarantined and the quarantine holds" "ok    A22-T1 under this server's own PHP" "ok    G3 both ECO BANK cards show a figure" \
         "ok    Q-G2 the tenant is Uganda" "ok    Q-G3 quoting organization #1 (from the Uganda profile)" "ok    Q-G4 uCRM's organizations, read once through the plugin's own client" \
         "ok    TN-G1 as text" "ok    TN-G2 on one copy of the book" "ok    TN-G3 wa_inbox_roles is unset" "GO — evidence recorded" \
         "ok    container serves $P100" "ok    R1 the installed manifest says 5.18.100" \
         "ok    Q-CA1 installed = 5.18.100's release: all 15 of its files carry the sha256 this script pins; all 60 files of 5.18.99 it must not touch" \
         "ok    Q-CA2 the INSTALLED QuoteOrganization::setting() on a copy reads what stage A read through the pin's: on:1:profile:-" \
         "ok    TN-CA1 as text, installed" "ok    TN-CA2 on a new copy of the book, through the installed code: owners() offers the $(qj "$O100_0" sales) active salesperson account(s) and the $(qj "$O100_0" tech) active technician account(s)" \
         "ok    TN-CA2 the same counts as stage A's reading through the pin" "ok    TN-CA3 wa_inbox_roles as stage A read it (inbox=unset roles=- odd=0 owners=-) — every entry, compared as keyed hashes" \
         "ok    Z1 the live vault" "ok    Z2 nothing of the vault, no webhook_secret file, no token copy" \
         "  names             TN-G1–TN-G3, TN-N1, TN-N2 and TN-CA1–TN-CA3 are 5.18.100's own" \
         "  what changed      on this Uganda server, the WhatsApp AI screen's Salesperson numbers card: for a new number and for a new owner its two lists offer the $(qj "$O100_0" sales) active salesperson account(s), as before, and the $(qj "$O100_0" tech) active technician account(s)"; do
  check "$(has "$OUT" "$l")" "yes" "4: ${l:0:110}"
done
check "$(sed -n 's/^TN_A_P=//p' "$STATE100" | tail -1 | grep -c '^code=owners offered=')$(sed -n 's/^TN_A_INB=//p' "$STATE100" | tail -1)|$(grep -E '^TN_A_(P|INB)=' "$STATE100" | grep -cE ' (sig|oroles|gh)=')|$(grep -c '^WA87_BEFORE=.* sig=[0-9a-f]' "$STATE100")" "1inbox=unset roles=- odd=0 owners=-|0|1" \
  "4a the state file records stage A's owner reading and wa_inbox_roles — counts and role names, no keyed hash (sig, oroles, gh) on either line; control: 087's own reading beside them keeps its sig, as 5.18.99's did"
check "$(grep -c "^COPY_AT=$(stat -c %Y "$PD/lib/SalesNumbersAdmin.php")\$" "$STATE100")|$(epoch99 "$(sed -n 's/^COPY_AT=//p' "$STATE100" | tail -1)")" "1|epoch" \
  "4b the copy's time, as stamp_copy gave lib/SalesNumbersAdmin.php — a file this release changes"
SUM100="$(sed -n '/^== F\. Summary ==$/,$p' <<<"$OUT")"
check "$(grep -cxF "      $EXP_AFTER100" <<<"$SUM100")$(grep -cxF "      $EXP_QCHECK" <<<"$SUM100")$(grep -cxF "      $EXP_ROLLBACK100" <<<"$SUM100")$(grep -cE '(deploy-5\.18\.100\.sh|quarantine-stray-store\.sh).*(deploy-5\.18\.100\.sh|quarantine-stray-store\.sh)' <<<"$OUT")" "1110" \
  "4c the --after-only, the quarantine's --check and the rollback, each printed exactly, alone on its line"
check "$(names100 "$R100/run-$T100D.txt" "$STATE100" "$OUT100"/*.log)|$(secrets99 "$R100/run-$T100D.txt" "$STATE100")|$(audit100 "$T100D" | sed -E 's/^copies=([0-9]+) /\1 /' | awk '{print ($1 >= 20 ? "many" : $1 " only") " " $2 " " $3}')|$(uother100 "$T100D")" "0|0|many novault=0 plugin=0|-" \
  "4d no staff record's name and no stand-in secret printed or recorded; every PHP process on a copy with a vault of its own; GETs only"
check "$(ledger95)|$(both_copies "$BK97")|$(kept_state)$(oldname)$(host_code100)$(left_tmp100)$(evo_calls)" "$LEDGER100|11|${KEPT100}0000" \
  "4e the migration ledger unchanged; ECO Bank's switch on in both places; the quarantine as it was; no code copy or run folder left; Evolution never called"

echo; echo "== 100-5. --after-only as printed; a technician's number; wa_inbox_roles set after the deploy =="
copy_back100 30; COPY100="$(sed -n 's/^COPY_AT=//p' "$STATE100" | tail -1)"
[ "$(epoch99 "$COPY100")" = "epoch" ] && set_sched $(( COPY100 + 300 )); base_log
ALINE="$(printed "$OUT100D" "cd $REPO && bash scripts/deploy-5.18.100.sh --after-only")"
check "$ALINE|$(epoch99 "$COPY100")" "$EXP_AFTER100|epoch" "5 the --after-only command, as the deploy's summary printed it; the copy moved thirty minutes back"
fresh100; OUT="$(run_line100 '' "$ALINE")"; OUT100A="$OUT"
check "$(fails "$OUT")$(has "$OUT" '5.18.100 (after): PASSED')$(okline "$OUT" R19 'the quarantine holds: master ran the retry job at' "after the copy (at $(utc "$COPY100"))")$(okline "$OUT" TN-CA2 'the same counts as stage A')$(okline "$OUT" TN-CA3 'as stage A read it' 'its known role names and the count of its other entries')" "0yesyesyesyes" \
  "5a --after-only PASSES, R19 conclusive; TN-CA2 and TN-CA3 against the deploy's own readings from the state file"
TECH100="$(sq "SELECT id FROM retailers WHERE json_extract(data, '\$.name') = 'RH100SECRET-Tech-One'" 2>/dev/null)"
case "$TECH100" in ''|*[!0-9]*) TECH100="$(php -r 'foreach (["bootstrap_data","StoreInterface","JsonStore","SqliteStore"] as $l) require_once $argv[1] . "/lib/$l.php"; $GLOBALS["dataDir"] = $argv[2]; foreach (SqliteStore::create($argv[2])->load("retailers.json") as $r) if (($r["name"] ?? "") === "RH100SECRET-Tech-One") { echo (int)$r["id"]; break; }' "$PD" "$DATA" 2>/dev/null)" ;; esac
sqx "INSERT INTO wa_channels (channel_id, evo_instance, display_name, role, owner_type, owner_staff_id, ai_enabled, handover_to, status) VALUES ('sales-901', 'rh100-instance', 'Sales — rehearsal (technician)', 'sales', 'staff', ${TECH100:-0}, 0, 'owner', 'disabled')" >/dev/null
sqx "INSERT INTO wa_channel_log (channel_id, action, old_value, new_value, actor, reason) VALUES ('sales-901', 'created', NULL, 'disabled', 'rehearsal', 'a technician''s number, as the card adds it')" >/dev/null
O100_N="$(o100)"
check "$(sq "SELECT COUNT(*) FROM wa_channels WHERE channel_id = 'sales-901' AND owner_type = 'staff'")|$(qj "$O100_N" numbers | tr ',' '\n' | sed -n 's/^tech://p')" "1|1" \
  "5b a number given to the technician on the card, after the run — one registry row and its trail row; this rehearsal's own reading: one number a technician owns"
fresh100; OUT="$(run_line100 '' "$ALINE")"
check "$(fails "$OUT")$(has "$OUT" '5.18.100 (after): PASSED')$(noteline "$OUT" TN-N1 'a technician 1')" "0yesyes" "5c --after-only again: PASSED, TN-N1 noting the technician's number"
row_keep100; row_set wa_inbox_roles "support_leader"; fresh100; OUT="$(run_line100 '' "$ALINE")"
check "$(failline "$OUT" TN-CA3 'wa_inbox_roles grants the shared Inbox to support_leader, and support_leader own, or after 5.18.100 may own, a number')$(noteline "$OUT" TN-CA3 'changed since the deploy')$(has "$OUT" '5.18.100 (after): 1 FAILED')" "yesyesyes" \
  "5d wa_inbox_roles set to a technician role after the deploy: TN-CA3 FAILS, and notes it changed since stage A"
M="$(weak100 tnca3w tnca3)"; fresh100; OUT="$(R100_MUTANT_OUT=1 run100 --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" '5.18.100 (after): PASSED')" "yes" "5e TN-CA3's failure weakened: PASSED over it (caught)"
sqx "UPDATE retailers SET data = json_set(data, '\$.role', 'accountant') WHERE id = ${TECH100:-0}" >/dev/null
check "$(qj "$(o100)" tech)" "$(( $(qj "$O100_N" tech) - 1 ))" "control: the technician's staff row now holds the role accountant — one technician fewer by this rehearsal's own reading; his number still his"
row_set wa_inbox_roles "accountant"; fresh100; OUT="$(run_line100 '' "$ALINE")"
check "$(failline "$OUT" TN-CA3 "wa_inbox_roles grants the shared Inbox to accountant, and the role a number's owner holds now is among them (accountant)")$(has "$OUT" '5.18.100 (after): 1 FAILED')$(grep -cE ' (gh|oroles|sig)=' <<<"$OUT")" "yesyes0" \
  "5g (review TN-1) a number's owner now holds a role outside the six (his number keeps working under any active owner), and wa_inbox_roles names it: TN-CA3 FAILS — compared as keyed hashes, none printed"
M="$(weak100 tnownw tnown)"; fresh100; OUT="$(R100_MUTANT_OUT=1 run100 --script "$M" --after-only)"; rm -f "$M"
check "$(has "$OUT" '5.18.100 (after): PASSED')" "yes" "5h the owners' roles never compared (weakened): PASSED over it (caught)"
sqx "UPDATE retailers SET data = json_set(data, '\$.role', 'support') WHERE id = ${TECH100:-0}" >/dev/null
check "$(qj "$(o100)" tech)" "$(qj "$O100_N" tech)" "…the technician's role put back: the technicians as before"
row_back100
check "$(qj "$(o100)" inbox)" "unset" "…the store row put back byte for byte, wa_inbox_roles unset"
DX100="$(data94)"; fresh100; OUT="$(run100 --check)"
check "$(has "$OUT" "--check is stage A before a deploy, and the container already serves $P100 (5.18.100) — run --after-only instead")$(has "$OUT" 'Nothing was changed. Send the log file.')$(went100 "$OUT")$(has "$OUT" '5.18.100 (check): GO')|$(grep -cxF "      $EXP_AFTER100" <<<"$OUT")|$(live)|$(data94)" "yesyesnonono|1|$P100|$DX100" \
  "5f --check with 5.18.100 live: refused, the --after-only printed instead, alone; nothing changed"

echo; echo "== 100-6. the rollback, run exactly as printed — ROLLBACK typed; the technician's number counted; --redeploy too soon =="
RLINE="$(printed "$OUT100A" "cd $REPO && bash scripts/deploy-5.18.100.sh --rollback")"
check "$RLINE" "$EXP_ROLLBACK100" "6 the rollback command, as the --after-only's summary printed it after its verdict"
S100SHA="$(sha256sum "$STATE100" | cut -c1-64)"; DR0="$(data94)"
fresh100; OUT="$(run_line100 ROLLBACK "$RLINE")"; RLOG="$(ls -1 "$OUT100"/rollback-*.log 2>/dev/null)"
check "$(fails "$OUT")$(has "$OUT" '5.18.100 (rollback): PASSED')$(live)$(installed100 "$P99")$(inst_ver)" "0yes${P99}05.18.99" "6a the rollback PASSES: 5.18.99 serving again, every file as it had them"
for l in "ok    RB the installed manifest says 5.18.99" \
         "ok    RB all 14 files 5.18.100 changed are 5.18.99's again, byte for byte" \
         "ok    RB 5.18.99's quoting code is in place" \
         "ok    RB the owner rule is 5.18.99's again, as text: salespeople() defined and called, no technician roles (tech=0 decl=0 sales=1 kind=0 role=1 page=0 old=2)" \
         "ok    RB on a copy of the book, through the installed code: salespeople() offers the $(qj "$O100_0" sales) active salesperson account(s) and nobody else" \
         "note  RB 1 number(s) in the registry are owned by a technician account" \
         "ok    RB cash in hand reads exactly as before the deploy on a copy of the book" "ok    RB 088 stays after the rollback, complete" \
         "  serving           $P99  (plugin 5.18.99)"; do
  check "$(has "$OUT" "$l")" "yes" "6a: ${l:0:110}"
done
check "$(printf '%s\n' "$RLOG" | grep -c .)|$(sha256sum "$STATE100" | cut -c1-64)|$(grep -c "^RB_COPY_AT=$(stat -c %Y "$PD/lib/SalesNumbersAdmin.php")\$" "$OUT100"/rollback-state-*.env 2>/dev/null)|$(sq "SELECT COUNT(*) FROM wa_channels WHERE channel_id = 'sales-901' AND owner_type = 'staff' AND owner_staff_id = ${TECH100:-0}")" "1|$S100SHA|1|1" \
  "6b its own log; the deploy's state file untouched; the rollback's record holding its copy's time as stamp_copy gave lib/SalesNumbersAdmin.php; the technician's number still his"
check "$(data94)|$(names100 "$R100/line-$(last100).txt" "$RLOG")|$(evo_calls)" "$DR0|0|0" "6c the rollback changed no data; no staff record's name printed; Evolution never called"
fresh100; D6D="$(data94)"; OUT="$(run100 --answer NO --redeploy)"
check "$(has "$OUT" "NO-GO (A14): a rollback of 5.18.100 by this script copied the plugin at")$(went100 "$OUT")$(live)|$(backups100)|$(data94)" "yesnono$P99|0|$D6D" \
  "6d --redeploy right after the rollback, before the quarantine's --check: NO-GO at A14, never the question; nothing backed up or changed"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "…the quarantine's own --check after the rollback, as RB asks: PASSED"
QREC100="$(sha256sum "$QREC" | cut -c1-16)"; KEPT100="$(kept_state)"

echo; echo "== 100-7. --redeploy after the rollback =="
fresh100; OUT="$(run100 --answer DEPLOY --redeploy)"; OUT100R="$OUT"
check "$(fails "$OUT")$(has "$OUT" '5.18.100 (deploy): PASSED')$(live)$(installed100 "$P100")$(inst_ver)" "0yes${P100}05.18.100" "7a --redeploy, DEPLOY typed: PASSED, $P100 serving, every file as the pin has it"
check "$(okline "$OUT" BR1 'the installed plugin is live 5.18.99' '1 of the 1 files 5.18.100 adds are already on disk')$(sed -n 's/^REDEPLOY=//p' "$STATE100" | tail -1)$(noteline "$OUT" TN-N1 'a technician 1')" "yes1yes" \
  "7b BR1 the one file on disk, as --redeploy expects; --redeploy recorded; TN-N1 the technician's number"
copy_back100 30; COPY100R="$(sed -n 's/^COPY_AT=//p' "$STATE100" | tail -1)"
[ "$(epoch99 "$COPY100R")" = "epoch" ] && set_sched $(( COPY100R + 300 )); base_log
ALINE2="$(printed "$OUT100R" "cd $REPO && bash scripts/deploy-5.18.100.sh --after-only")"
fresh100; OUT="$(run_line100 '' "$ALINE2")"
check "$ALINE2|$(fails "$OUT")$(has "$OUT" '5.18.100 (after): PASSED')" "$EXP_AFTER100|0yes" "7c its --after-only, as printed: PASSED"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "7d the quarantine's own --check, as the runbook orders: PASSED"

echo; echo "== 100-10. what the rehearsal left behind, and what it never reached =="
fresh100   # the last logs and sheets into the sweeps' folders
check "$(evo_calls)" "0" "Evolution never called — not by the deploys, their checks, the rollback or the code they ran on copies"
check "$(ls -a "$REPO/scripts" | grep -E '^\..+\.sh$' | paste -sd, -)" ".qpin.sh" "no weakened or re-pinned copy is left in the clone's scripts/ — the quarantine's one pinned copy aside"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )" "" "every weakened copy, release variant, pinned copy and planted edit — this rehearsal's and those of the rehearsals it runs — found its anchor exactly once"
rm -f "$SB/anchor-missing"   # the control below reads its own record alone
M="$(weak100 anchorprobe100 no-such-entry 2>/dev/null)"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )" "weak100:no-such-entry " "control on the control: a weakened copy asked for an entry the table does not hold is recorded as such (weak100)"
rm -f "$SB/anchor-missing" "$M"
check "$( [ -f "$SB/bad-runs" ] && tr '\n' ' ' < "$SB/bad-runs" )" "" "every run took the options it was given — none answered 'unknown option'"
OUT="$(run100 --after-only --rehearsal-unknown-option)"
check "$(has "$OUT" 'unknown option: --rehearsal-unknown-option')$( [ -s "$SB/bad-runs" ] && echo recorded)" "yesrecorded" "control on the control: a run given an option the script does not know is recorded as such"
rm -f "$SB/bad-runs"
tok100() { python3 "$H100/rh100.py" tok99 "$R100/tok/run-$1.tok"; }   # tok100 TAG — remote=<n> copied=<n> left=<n>
OBS_BAD=0; OBS_N=0; TOK_BAD=0; GET_BAD=0
for rec in "$R100"/exec/run-*.rec; do
  t="${rec##*/run-}"; t="${t%.rec}"; OBS_N=$((OBS_N+1))
  a="$(audit100 "$t")"; case "$a" in *" novault=0 plugin=0 "*) ;; *) OBS_BAD=$((OBS_BAD+1)) ;; esac
  [ "$(hits100 "$t")" = "0" ] || OBS_BAD=$((OBS_BAD+1))
  [ "$(cat "$R100/vault/run-$t.before")" = "$(cat "$R100/vault/run-$t.after")" ] || OBS_BAD=$((OBS_BAD+1))
  case "$(tok100 "$t")" in *" left=0") ;; *) TOK_BAD=$((TOK_BAD+1)) ;; esac
  [ "$(uother100 "$t")" = "-" ] || GET_BAD=$((GET_BAD+1))
done
check "$( [ "$OBS_N" -ge 20 ] && echo many)|$OBS_BAD|$TOK_BAD|$GET_BAD" "many|0|0|0" \
  "10 across every run of the 5.18.100 script — the check, the refusals, the weakened copies, the deploy, the after-onlys, the rollback and the redeploy — the copy observers were quiet, every token copy was gone at the call after its probe, and the stand-in uCRM received nothing but GETs"
check "$(vt_host)|$(grep -cxF "$W96" "$DATA/webhook_secret")" "$VT99_0|1" "…the live vault as it was before the first run of 5.18.99's rehearsal, bytes and time; the planted webhook secret where it was planted"
sweep_files100() { ls -1 "$R100"/run-*.txt "$R100"/line-*.txt "$R100"/mutants/run-*.txt "$R100"/logs/*.log "$OUT100"/*.log "$OUT100"/state-5.18.100.env* "$OUT100"/rollback-state-* "$OUT100"/cih-rows-* 2>/dev/null; }
forms100() { python3 "$H100/rh100.py" forms "$SB/forms100.txt" "$SB/seed96.amounts" "$SB/seed97.amounts" "$SB/stmt97.amounts" "$SB/seed98.amounts" "$H97"/cash-*.json "$H98"/cash-*.json "$H99"/cash-*.json "$R97"/sheets/cash-*.txt "$R98"/sheets/cash-*.txt "$R99"/sheets/cash-*.txt "$R100"/sheets/cash-*.txt "$OUT97"/cash-*.txt "$OUT98"/cash-*.txt "$OUT99"/cash-*.txt "$OUT100"/cash-*.txt >/dev/null 2>&1; }
figs100() { forms100; python3 "$H100/rh100.py" hits "$SB/forms100.txt" "$@"; }   # figs100 FILE… — how many hold a figure of the book, in any printed form
check "$( [ "$(sweep_files100 | grep -c .)" -ge 15 ] && echo many)|$( [ "$(ls "$R100"/sheets/cash-*.txt 2>/dev/null | grep -c .)" -ge 3 ] && echo sheets)|$(forms100; grep -c . "$SB/forms100.txt" | awk '{print ($1 >= 100 ? "forms" : "few:" $1)}')|$( [ "$(ls "$R100"/ucrm/run-*.req 2>/dev/null | grep -c .)" -ge 20 ] && echo records)" "many|sheets|forms|records" \
  "the sweep has material: every run's terminal and log of this release's, the state files and row files; its runs' sheets; a hundred and more printed forms of the book's figures; every run's request record"
check "$(figs100 $(sweep_files100))" "0" "10a no figure of the book or of a kept statement — in any printed form — in any terminal, log, state file or row file of any run of the 5.18.100 script: only in the sheets"
check "$(sweep_files100 | xargs grep -lF -f "$SCAN96" 2>/dev/null | grep -c .)|$(grep -v '^$' "$SEC95" > "$SB/sec100.pat"; sweep_files100 | xargs grep -lF -f "$SB/sec100.pat" 2>/dev/null | grep -c .)|$(secrets99 $(sweep_files100) "$R100"/sheets/cash-*.txt "$R100"/ucrm/run-*.req)|$(names100 $(sweep_files100) "$R100"/sheets/cash-*.txt)" "0|0|0|0" \
  "10b no output, log, state file, sheet or request record holds the planted webhook secret or vault credential, a repair key, a planted Splynx or pdf value, a name, contact or key of the stand-in uCRM's — or a staff record's name"
check "$(noids99 $(sweep_files100))|$(bankhits98 $(sweep_files100))|$(sweep_files100 | xargs grep -lE ' (gh|oroles)=' 2>/dev/null | grep -c .)" "0|0|0" \
  "10c no output, log or state file names a client id the app holds, an account name or number — or carries a keyed hash of the owner checks"
printf 'planted %s, %s, %s, client %s and %s\n' "$(grep -m1 '^2:' "$SB/forms100.txt" | cut -c3-)" "$W96" "$(head -1 "$U99_SECRETS")" "$(head -1 "$SB/ids99.pat")" "RH100SECRET-Tech-One" > "$R100/zz-sweep-control.txt"
check "$(figs100 "$R100/zz-sweep-control.txt")$(grep -lF -f "$SCAN96" "$R100/zz-sweep-control.txt" | grep -c .)$(secrets99 "$R100/zz-sweep-control.txt")$(noids99 "$R100/zz-sweep-control.txt")$(names100 "$R100/zz-sweep-control.txt")" "11111" \
  "control on the sweeps: a file holding one figure form, the planted secret, a stand-in secret, a client id and a seeded staff name is found by all five"
rm -f "$R100/zz-sweep-control.txt" "$SB/sec100.pat"
rterr100() {   # rterr100 FILE… — how many of the files hold a bash runtime error of a script ("<x>.sh: line N: …")
  [ "$#" -gt 0 ] || { echo none; return; }
  grep -lE '\.sh: line [0-9]+: .*(bad substitution|unbound variable|syntax error|command not found)' "$@" 2>/dev/null | grep -c .
}
printf '%s\n' 'X=1; Y=2' 'echo "a:${X:+ the copy'"'"'s:$X}${Y:+ the base'"'"'s:$Y} end"' > "$SB/rterr100.sh"
bash "$SB/rterr100.sh" > "$R100/zz-rterr-control.txt" 2>&1
check "$(rterr100 $(sweep_files100))|$(rterr100 "$R100/zz-rterr-control.txt")" "0|1" \
  "10d no terminal or log of any run of the 5.18.100 script holds a bash runtime error; control: a script with an apostrophe inside \${…:+…} is caught"
rm -f "$SB/rterr100.sh" "$R100/zz-rterr-control.txt"
check "$(left_tmp100)$(host_code100)$(ls -d /tmp/dnb-backup-* 2>/dev/null | grep -c .)$(find /tmp -maxdepth 6 -path '/tmp/dnb-5.18.100-*' -name token.json 2>/dev/null | grep -c .)" "0000" \
  "no run folder, code copy, vault, token copy or backup copy of this release's left in /tmp or on the host"
check "$(grep -c 'Fatal\|Parse error' "$SB/web.log")|$(grep -c 'Fatal\|Parse error' "$U99/server.log")" "0|0" "the stand-in plugin and the stand-in uCRM served every request without a PHP fatal"
check "$(checkout_state)" "$CHECKOUT0" "this checkout is as the rehearsal found it: same commit, same tracked files"

echo
echo "RESULT: $PASS ok, $FAILN failed  (the script: $WHICH100)"
[ "$FAILN" = "0" ] || echo "  $FAIL_PRE100 of the $FAILN failed in the earlier releases' rehearsals, before this release's own sections. Their scripts are records, left as they are; a refusal of theirs at a check read off a pipeline whose reader exits early is a SIGPIPE false stop, not a defect of 5.18.100's. Read the first FAIL; if it is one, run the rehearsal again — it is never a pass."
[ "$FAILN" = "0" ]
