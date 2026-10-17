#!/usr/bin/env bash
#
# Rehearse scripts/deploy-5.18.95.sh — security release 5.18.95 over 5.18.94: the photo route (K1), the repair page's
# built-in key (K2), the EFRIS link keys (A2/D1), the Splynx key and secret in the code (D5); root docs/07, 17 Oct —
# before the operator runs it.
#
# The sandbox is 5.18.94's rehearsal's, built by its own code: scripts/harness/deploy-5.18.94/rehearse.sh, read up to just
# before its deploy section (the cut asserted: its anchor exactly once) and run here as it is — which builds the
# quarantine's sandbox the same way, from its own rehearsal: 5.18.93 live, the salesperson pilot as the operator left it,
# the REAL quarantine done and its --check passed, the stand-in website and Kampala's date, 5.18.94's own sections 0–2. On
# it, in the runbook's order: the REAL deploy of 5.18.94 (DEPLOY typed), its --after-only until R19 is conclusive and the
# quarantine's --check — production's state since 16 Oct — and then this release's script, through a pseudo-terminal, its
# word typed, as the operator runs it; and every command its summary prints for a later step — the key file's, the
# --after-only, the rollback — run exactly as printed.
#
# The server never runs a test that serves the plugin: the four new tests are sandbox tests, run only in the suites. The
# script proves the fixes by reading the code as TEXT, each check with its control on 5.18.94's copy; this rehearsal
# proves that each check sees what it claims to, and that each control reads 5.18.94's copy and nothing else.
#
# This rehearsal proves:
#   · with 5.18.93 still live the script refuses — 5.18.94 first; then 5.18.94 deployed for real, its --after-only
#     conclusive, the quarantine's --check PASSED: production's state (pre);
#   · the script is pinned to the release commit on release/5.18.95, cut on f4b4c96 (5.18.94); the release's files are
#     exactly the reviewed set — four tests added, nineteen files byte for byte the development branch's change
#     (8357cd1c), public.php and includes/routes.php 5.18.94's with that change's patch applied and nothing else, the
#     manifest and five pins version-only; main.php, master.php, the retry job, its gate, 087, 5.18.94's documents and
#     PdfLinkToken 5.18.94's byte for byte; nothing outside the plugin; the header's deploy command stands alone, with
#     its own log wrapper, and every command the script prints for a later step carries its own; the script reads the
#     key file only by size and counts, names the old built-in key only in grep patterns, and holds none of 5.18.94's
#     Splynx values; a copy whose header carries the rollback inside the deploy command's block, and one that prints the
#     --after-only without its wrapper, are caught (0);
#   · A14 reads 5.18.94's state files: a failure recorded there — or in one kept beside it — refuses until a --check of
#     the quarantine that began after it has PASSED; a time that cannot be read refuses; a copy blind to 5.18.94's files
#     reaches the question (caught); a copy of 5.18.94's recorded after the newest passing --check refuses (rule 3), and
#     a copy blind to it reaches the question (caught); A20 is gone: on 17 October the deploy reaches its question, and a copy with A20 kept
#     refuses (caught) (1);
#   · the repair key file absent, unreadable by the plugin's user, in place, too short, with a space, holding the old key
#     — each said; with a Windows line ending, or blanks and a tab around the key, in place, as public.php's trim() reads it, never a reason to stop, its content never printed; six more forms through
#     the script's own key_state; the backup never holds the key file or a key moved aside (a copy that does is caught); a
#     copy whose key note prints the key is caught by the same assertion; the Splynx key and secret and the
#     pdf_link_secret said present or absent, never a value (a copy that prints them is caught); no planted key or value
#     in any output, log or state file so far (2);
#   · A22: every check reads the pin ok and names its control's defects in 5.18.94's copy exactly; each of seventeen
#     release variants that takes one fix back refuses, naming the check and the defect, nothing changed; a copy whose
#     control is the pin refuses (a control that sees nothing proves nothing), and so do a copy whose checks never read
#     5.18.94's copy and one blind to the one-pass strip (each control must show exactly its check's defects); copies
#     without the refusal, or without either control's refusal, reach the question (caught) (3);
#   · the deploy PASSES: A22 and R23 each fix with its control named exactly, R7 the database, R24 the key file, the
#     state file; the database unchanged — the ledger the sandbox reads before and after is the one the state file and R7
#     name, every other row the same; every later command printed exactly, on its own line, with its own log wrapper, the
#     rollback once and after the verdict; no key, no old key, no Splynx value in its output (4); the key command, run as
#     printed: 48 hex characters, 1000:1000, 0600, only its size, owner and mode shown and logged; run again it moves the
#     old one aside, never removes it (4b);
#   · the --after-only, run as printed: R19 conclusive, R23 with its controls, R24 the key in place, R7 against the
#     deploy's own reading, its log 0600; copies that print the --after-only without its wrapper, the rollback inside the
#     --after-only's block before the verdict, are caught; a copy whose R23 never reads 5.18.94's copy FAILS R23, and one
#     without that failure is caught; a ledger row written
#     during a run FAILS R7 (a copy blind to the ledger is caught); a fix taken out of the installed code FAILS R23 (a copy
#     reading the pin instead is caught); the quarantine's --check PASSES (5);
#   · the rollback, run as printed, ROLLBACK typed: PASSED, every changed file 5.18.94's by sha256, every fix's defect
#     back, the database the same, the key file said, its log 0600, the deploy's state file untouched; deployed again; a
#     5.18.95 file a rollback's copy leaves behind FAILS RB twice — by sha256 and by the fixes still installed — and the
#     key file absent is said with its command (a copy comparing the control with itself is caught); deployed again (6);
#   · nothing reaches Evolution; no output, log or state file of any run holds a key, a planted value or one of 5.18.94's
#     Splynx values, nor the old key's name (the sweep with its control); every trigger spent; the clone, /tmp and the
#     checkout as found (7).
#
# Everything runs in a sandbox; it never touches this checkout, the server or the network, and refuses to run where the
# server could be. Everything seeded is fictitious; the two Splynx values 5.18.94's code holds are read from the clone into
# a 0600 file of the sandbox, only to prove no output carries them, and are never printed.
#
#   bash scripts/harness/deploy-5.18.95/rehearse.sh            REHEARSE_KEEP=<dir> keeps every run's full output
set -u
for f in /data/ucrm /opt/dishnet /var/run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
H95_DIR="$(cd "$(dirname "$0")" && pwd)"
H94_HARNESS="$H95_DIR/../deploy-5.18.94/rehearse.sh"
H94_ANCHOR='echo; echo "== 94-3. the deploy, as the operator runs it =="'
H95_PREFIX="$(mktemp)"
python3 - "$H94_HARNESS" "$H95_PREFIX" "$H94_ANCHOR" <<'PY' || { echo "refusing: 5.18.94's rehearsal could not be cut at its deploy section"; rm -f "$H95_PREFIX"; exit 2; }
import sys
src, dst, anchor = sys.argv[1], sys.argv[2], sys.argv[3]
lines = open(src, encoding='utf-8').read().split('\n')
at = [i for i, l in enumerate(lines) if l == anchor]
assert len(at) == 1, len(at)
assert at[0] > 400, at[0]                  # the quarantine's sandbox, 5.18.94's controls, its A14 and A17–A21 come before it
pre = '\n'.join(lines[:at[0]]) + '\n'
for need in ('Q_HARNESS="$H94_DIR/../quarantine-stray-store/rehearse.sh"', 'run94() {', 'went94() {', 'fresh94() {',
             'kept_state() {', 'oldname() {', 'data94() {', 'OUT94="$SB/out94"', 'STATE94="$OUT94/state-5.18.94.env"',
             'pubget() {', 'P94="$(sed -n', 'echo; echo "== 94-2. A17', 'mkdir -p "$SB/datebin"', 'AFTER94="$(date -d',
             'WEB_OK="http://127.0.0.1:$WPORT94/ok"'):
    assert pre.count(need) >= 1, need
open(dst, 'w', encoding='utf-8').write(pre)
PY
# shellcheck disable=SC1090
. "$H95_PREFIX"
rm -f "$H95_PREFIX"

# ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
# 5.18.95's own rehearsal, on the sandbox built above: 5.18.93 (69c87a6) live, the quarantine done and checked, 5.18.94's
# sections 0–2 run. 5.18.94 is deployed for real first (95-pre), as production has it.
# ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
F95=scripts/deploy-5.18.95.sh; REL95=release/5.18.95; DEPLOY95="$REPO/$F95"
FIX95=8357cd1c                                      # the change on the development branch (its tip, holding both WIP commits)
DEV95_BASE=2e52b0e2                                 # …and the development branch before it
ADDED95="tests/test_kyc_photo_route.php tests/test_emergency_repair_key.php tests/test_efris_pdf_link.php tests/test_splynx_no_code_credentials.php"
HUNKS95="public.php includes/routes.php"            # 5.18.94's, with the change's own hunks alone
TAKEN95="efris_pdf.php tabs/admin/efris.php cron_splynx_sync.php tabs/help/runbook.php tabs/admin/starlink_suspensions.php EMERGENCY_RECOVERY.md MANIFEST.md SAFETY.md tests/test_pdf_link_token.php"
PINS95="tests/test_distributor_apply.php tests/test_distributor_link_ucrm.php tests/test_distributor_notify.php tests/test_distributor_registry.php tests/test_distributor_territory.php"
N95=$(( $(wc -w <<<"$ADDED95 $HUNKS95 $TAKEN95 $PINS95") + 1 ))   # …and the manifest: 21
if git -C "$R" ls-files --error-unmatch "$F95" >/dev/null 2>&1; then
  cmp -s "$R/$F95" "$DEPLOY95" || { echo "refusing: $F95 differs from the committed one — commit it, then rehearse"; exit 2; }
  WHICH95="the committed script"
else cp "$R/$F95" "$DEPLOY95"; WHICH95="the working copy (not yet committed; untracked in the clone)"; fi
git -C "$REPO" fetch -q origin "refs/heads/$REL95:refs/remotes/origin/$REL95" 2>/dev/null || true
P95="$(sed -n 's/^EXPECTED_PLUGIN_COMMIT="\([^"]*\)".*/\1/p' "$DEPLOY95")"
OUT95="$SB/out95"; mkdir -p "$OUT95"; STATE95="$OUT95/state-5.18.95.env"
R95="$SB/r95"; mkdir -p "$R95/logs" "$R95/mutants"   # every run's output, every printed command's log: the sweep's material (7)
SEC95="$SB/secrets95"; ( umask 077; : > "$SEC95" )  # every key, planted value and 5.18.94 Splynx value: never printed, swept for
KEYH="$DATA/emergency_repair_key.txt"                # the repair key file on the host, where the script's KEY_HOST finds it
KWHERE="/data/ucrm/data/plugins/.$P-data/emergency_repair_key.txt"   # …and as it names it, in the container
RH_SPX_KEY="rh-splynx-key-planted-0001"; RH_SPX_SEC="rh-splynx-secret-planted-0002"; RH_PDF="rh-pdf-link-secret-planted-0003"
printf '%s\n' "$RH_SPX_KEY" "$RH_SPX_SEC" "$RH_PDF" >> "$SEC95"
DATE_TS='$(date -u +%Y%m%dT%H%M%SZ)'
LOGW95() { printf '2>&1 | ( umask 077; exec tee %s/%s-%s.log )' "$OUT95" "$1" "$DATE_TS"; }   # the script's LOGW, as it prints it
EXP_AFTER="cd $REPO && bash scripts/deploy-5.18.95.sh --after-only $(LOGW95 after)"
EXP_ROLLBACK="cd $REPO && bash scripts/deploy-5.18.95.sh --rollback $(LOGW95 rollback)"
EXP_DEPLOY="cd $REPO && bash scripts/deploy-5.18.95.sh $(LOGW95 deploy)"
EXP_QCHECK="( umask 077 && cd $REPO && bash scripts/quarantine-stray-store.sh --check 2>&1 | tee $QD/check-$DATE_TS.log )"
EXP_PUTBACK="( cd $REPO && git checkout $P94 && { bash scripts/deploy-hybrid.sh; git checkout -; } ) $(LOGW95 putback)"
EXP_KEYCMD="( set -C; umask 077; f=$(printf '%q' "$KEYH"); "'[ ! -e "$f" ] || mv -- "$f" "$f.replaced-$(date -u +%Y%m%dT%H%M%SZ)"; openssl rand -hex 24 > "$f" && chown 1000:1000 "$f" && chmod 600 "$f" && echo "repair key written: $(wc -c < "$f") bytes, owner $(stat -c %u:%g "$f"), mode $(stat -c %a "$f")" ) '"$(LOGW95 keyfile)"
# What each of the seven checks finds in 5.18.94's copy (f4b4c96), its control — fixed words, read once from Git with the
# script's own checks when this rehearsal was written (17 Oct). A control that reports anything else did not read
# 5.18.94's copy: a check without its control.
declare -A CTL95=(
  [a]="strips no-dotdot-refusal no-backslash-refusal no-realpath-base no-realpath-file no-containment no-is_file"
  [b]="fallback-key old-key-named-4x no-key-rule no-gate prints-the-key echoes-the-key no-placeholder"
  [c]="verify:no-helper verify:not-the-store-copy verify:no-PdfLinkToken verify:dishnet-fallback verify:own-hmac mint:no-helper mint:not-the-store-copy mint:no-ensureSecret mint:no-PdfLinkToken mint:a-link-without-a-key mint:dishnet-fallback mint:own-hmac"
  [d]="literal-in:cron_splynx_sync.php,includes/routes.php,public.php patch:not-410 patch:writes-or-calls"
  [e]="names-the-old-key-1x" [f]="names-the-old-key-1x" [g]="names-the-old-key-1x"
)

left_tmp95() { ls -d /tmp/dnb-5.18.95-* /tmp/dn-master-lock-* /tmp/dn_autopull_* 2>/dev/null | wc -l | tr -d ' '; }
TMP95="$(left_tmp95)"
host_code95() { ls -d "$OUT95"/code-* 2>/dev/null | wc -l | tr -d ' '; }
backups95() { ls -d "$OUT95"/backup-* 2>/dev/null | wc -l | tr -d ' '; }
fresh95() {   # the run folder as a new run finds it: the state files kept, every log a printed command wrote kept for the sweep (7)
  find "$OUT95" -mindepth 1 -maxdepth 1 -name '*.log' -exec mv -t "$R95/logs/" {} + 2>/dev/null
  find "$OUT95" -mindepth 1 -maxdepth 1 ! -name 'state-5.18.95.env*' ! -name 'rollback-state-*' -exec rm -rf {} +; base_log; }
installed95() {   # installed95 COMMIT — the number of files 5.18.94 → 5.18.95 touches that are NOT installed as COMMIT has them
  local f rel bad=0
  for f in $(dl AM "$P94" "$P95"); do
    rel="${f#$P/}"
    if git -C "$REPO" cat-file -e "$1:$f" 2>/dev/null; then
      [ "$(git -C "$REPO" show "$1:$f" | sha256sum | cut -c1-64)" = "$(sha256sum "$PD/$rel" 2>/dev/null | cut -c1-64)" ] || bad=$((bad+1))
    fi
  done
  echo "$bad"
}
run95() {   # [--answer WORD] [--script FILE] [--env K=V]… [--today YYYYMMDD], then the script's options — through a
            # pseudo-terminal, as run94. Today in Kampala is 17 October unless a scenario says otherwise: the day after
            # 5.18.94's documents' date, on which 5.18.94's A20 refused. DNB_WEBSITE names the stand-in: 5.18.95 has no A19
            # and fetches no website, and no copy of it run here could reach the real one. R95_MUTANT_OUT=1 keeps the
            # output out of the sweep (a weakened copy that prints a secret on purpose)
  local answer="" script="$DEPLOY95" extra=() today="$AFTER94" n o cmd
  while :; do case "${1:-}" in --answer) answer="$2"; shift 2 ;; --script) script="$2"; shift 2 ;; --env) extra+=("$2"); shift 2 ;;
    --today) today="$2"; shift 2 ;; *) break ;; esac; done
  : > "$SB/docker.log"
  n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"
  cmd="$(printf '%q ' env PATH="$SB/datebin:$SB/bin:$PATH" FAKE_TODAY="$today" DNB_OUT="$OUT95" DNB_RECORDS="$RECS" DNB_WEBSITE="$WEB_OK" GUARD_SECONDS=3 DN_DATA_DIR="$DATA" ${extra[@]+"${extra[@]}"} bash "$script" --plugin-base "$PLUGIN_BASE" "$@")"
  o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r')"
  case "$o" in *"unknown option:"*) echo "run95-$n" >> "$SB/bad-runs" ;; esac
  if [ -n "${R95_MUTANT_OUT:-}" ]; then printf '%s\n' "$o" > "$R95/mutants/run-$(printf '%03d' "$n").txt"
  else printf '%s\n' "$o" > "$R95/run-$(printf '%03d' "$n").txt"; fi
  [ -n "${REHEARSE_KEEP:-}" ] && printf '%s\n' "$o" > "$REHEARSE_KEEP/run95-$(printf '%03d' "$n")-${answer:-none}-$(basename "$script" .sh)$(printf '%s' "$*" | tr -c 'A-Za-z0-9-' '_').txt"
  printf '%s' "$o"
}
run_line() {   # run_line WORD LINE — a command line exactly as a summary printed it, through a pseudo-terminal, WORD typed at
               # its prompt. Only the sandbox is supplied around it: the fake docker and Kampala's date on PATH, the
               # sandbox's folders, and its public address in PLUGIN_BASE (the server derives its own) — the line itself
               # is run as printed.
  local answer="$1" line="$2" n o cmd
  : > "$SB/docker.log"
  n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"
  cmd="$(printf '%q ' env PATH="$SB/datebin:$SB/bin:$PATH" FAKE_TODAY="$AFTER94" DNB_OUT="$OUT95" DNB_RECORDS="$RECS" DNB_WEBSITE="$WEB_OK" GUARD_SECONDS=3 DN_DATA_DIR="$DATA" PLUGIN_BASE="$PLUGIN_BASE" bash -c "$line")"
  o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r')"
  case "$o" in *"unknown option:"*) echo "line95-$n" >> "$SB/bad-runs" ;; esac
  printf '%s\n' "$o" > "$R95/line-$(printf '%03d' "$n").txt"
  [ -n "${REHEARSE_KEEP:-}" ] && printf '%s\n' "$o" > "$REHEARSE_KEEP/line95-$(printf '%03d' "$n")-${answer:-none}.txt"
  printf '%s' "$o"
}
run_cmd() {   # run_cmd LINE — a printed command that asks nothing (the key file's), run as root exactly as printed
  local o; o="$(bash -c "$1" 2>&1 </dev/null)"; printf '%s\n' "$o" > "$R95/cmd-$(date +%s%N).txt"; printf '%s' "$o"
}
printed() { awk -v p="      $2" 'index($0, p) == 1 { print substr($0, 7); exit }' <<<"$1"; }   # printed OUTPUT PREFIX — the command line a summary printed
went95() { echo "$(has "$1" 'GO — evidence recorded')$(has "$1" 'Type DEPLOY')"; }   # the run reached the question
quiet95() { echo "$(live)|$(backups95)|$(data94)|$(kept_state)|$(oldname)"; }      # 5.18.94 live, no backup, the data and the quarantine as they were
nogo95() {   # nogo95 LABEL EXPECTED-TEXT OUTPUT [DATA-DIGEST] — refused with that text and "Nothing was changed", never the question,
             # nothing deployed, backed up or changed
  check "$(has "$3" "$2")$(has "$3" 'Nothing was changed. Send the log file.')$(went95 "$3")|$(quiet95)" "yesyesnono|$P94|0|${4:-$D95}|$KEPT95|0" "$1"
}
noteline() {   # noteline OUTPUT ID FRAGMENT… — yes when one of the run's "note" lines of check ID carries every FRAGMENT
  local out="$1" id="$2" l f all; shift 2
  while IFS= read -r l; do
    case "$l" in "  note  $id "*) ;; *) continue ;; esac
    all=yes; for f in "$@"; do case "$l" in *"$f"*) ;; *) all=no; break ;; esac; done
    [ "$all" = yes ] && { echo yes; return; }
  done <<<"$out"
  echo no
}
ctl95() {   # ctl95 OUTPUT A22|R23 — of the seven checks, how many name exactly CTL95's defects for 5.18.94's copy, the control:
            # A22's line per check and its ok line, or R23's ok line
  local out="$1" n=0 x
  for x in a b c d e f g; do
    case "$2" in
      A22) grep -qxF -- "$(printf '  %-15s the pin: %s   ·   %s (%s, the control): %s' "fix A22$x" ok "$P94" 5.18.94 "${CTL95[$x]}")" <<<"$out" \
             && [ "$(okline "$out" "A22$x" "— in the pin; 5.18.94's copy, the control, fails the same check (${CTL95[$x]})")" = yes ] && n=$((n+1)) ;;
      R23) [ "$(okline "$out" "R23$x" 'installed:' "— 5.18.94's copy, the control, fails the same check (${CTL95[$x]})")" = yes ] && n=$((n+1)) ;;
    esac
  done
  echo "$n"
}
printed95() {   # printed95 OUTPUT — the summary's commands: how many | how many without their own log wrapper | how many lines of
                # the whole output hold two commands
  local s; s="$(sed -n '/^== F. Summary ==$/,$p' <<<"$1")"
  echo "$(grep -cE '^      (cd |\( )' <<<"$s")|$(grep -E '^      (cd |\( )' <<<"$s" | grep -vcE ' \| \( umask 077; exec tee | \| tee ')|$(grep -cE 'deploy-5\.18\.95\.sh.*(deploy-5\.18\.95\.sh|quarantine-stray-store\.sh)|quarantine-stray-store\.sh.*deploy-5\.18\.95\.sh' <<<"$1")"
}
rb_where95() {   # rb_where95 OUTPUT — each line naming the rollback command: before or after the run's verdict
  awk '/^  5\.18\.95 \((deploy|after)\): / { v = 1 } index($0, "deploy-5.18.95.sh --rollback") { print (v ? "after" : "before") }' <<<"$1" | paste -sd, -
}
hdr95() {   # hdr95 SCRIPT — the header's command block: the deploy command with its own log | anything that is not it (a
            # rollback, a put-back, a setting, a checkout) | the later --after-only with its own log
  local h; h="$(sed -n '/^# Run as root/,/^# The rollback is a separate command/p' "$1")"
  echo "$(grep -cF 'bash scripts/deploy-5.18.95.sh 2>&1 | ( umask 077; exec tee /root/dnb-5.18.95/deploy-$(date -u +%Y%m%dT%H%M%SZ).log )' <<<"$h")$(grep -cE -- '--rollback|--put-back|set_config|git checkout [0-9a-f]{7}' <<<"$h")$(grep -cF 'bash scripts/deploy-5.18.95.sh --after-only 2>&1 | ( umask 077; exec tee /root/dnb-5.18.95/after-$(date -u +%Y%m%dT%H%M%SZ).log )' <<<"$h")"
}
cmds95() {   # cmds95 SCRIPT — the commands it prints for a later step, each defined with its own log wrapper (the --after-only,
             # the rollback, the deploy, the by-hand put-back, the key file's; the quarantine's --check with its own tee) |
             # the lines that print a command (printf, echo) | how many of them name one of those | how many carry the
             # wrapper themselves
  local f="$1"
  printf '%s%s%s%s%s%s|%s|%s|%s|%s\n' \
    "$(grep -cxF 'AFTER_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --after-only $(LOGW after)"' "$f")" \
    "$(grep -cxF 'ROLLBACK_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --rollback $(LOGW rollback)"' "$f")" \
    "$(grep -cxF 'DEPLOY_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh $(LOGW deploy)"' "$f")" \
    "$(grep -cxF 'PUTBACK_CMD="( cd $REPO && git checkout $BASELINE_COMMIT && { bash scripts/deploy-hybrid.sh; git checkout -; } ) $(LOGW putback)"' "$f")" \
    "$(grep -cF '"$KEY_HOST" "${DB_OWNER:-OWNER-OF-plugin.sqlite3}" "$(LOGW keyfile)"' "$f")" \
    "$(grep -cF 'bash scripts/quarantine-stray-store.sh --check 2>&1 | tee $Q_DIR/check-\$(date -u +%Y%m%dT%H%M%SZ).log )"' "$f")" \
    "$(grep -cF "printf '\n      %s'" "$f")" "$(grep -cF 'echo "      $' "$f")" \
    "$(grep -cE "(printf '\\\\n      %s' \"\\\$|echo \"      \\\$)(AFTER_CMD|ROLLBACK_CMD|DEPLOY_CMD|QCHECK_CMD|PUTBACK_CMD|\(key_cmd\))\"" "$f")" \
    "$(grep -F "printf '\n      %s'" "$f" | grep -cF '$(LOGW ')"
}
ledger95() { sq "SELECT COUNT(*) || ':' || COALESCE((SELECT filename || ':' || substr(checksum, 1, 16) FROM _migrations ORDER BY id DESC LIMIT 1), '-:-') FROM _migrations"; }   # the migration ledger as R7 reads it: rows:last migration:checksum
secret_add_file() { tr -d '\r' < "$1" | grep -v '^[[:space:]]*$' >> "$SEC95"; }   # a key file's lines, into the secrets list
key_put() {   # key_put OWNER FORMAT ARG… — the repair key file written with printf, mode 0600, its lines added to the secrets list
  local owner="$1"; shift
  rm -rf "$KEYH"; ( umask 077; printf "$@" > "$KEYH" ); chown "$owner" "$KEYH"; chmod 600 "$KEYH"; secret_add_file "$KEYH"
}
key_gone() { rm -rf "$KEYH" "$KEYH".replaced-*; }
key_shown() { [ -s "$KEYH" ] && { tr -d '\r' < "$KEYH" | grep -v '^[[:space:]]*$' > "$SB/key.pat"; grep -qFf "$SB/key.pat" <<<"$1" && echo yes || echo no; rm -f "$SB/key.pat"; } || echo nokey; }
sweep_files95() { ls -1 "$R95"/*.txt "$R95"/logs/*.log "$OUT95"/*.log "$OUT95"/state-5.18.95.env* "$OUT95"/rollback-state-* 2>/dev/null; }
secret_hits95() {   # how many kept outputs, logs and state files hold any value of the secrets list — a count, never a value
  local f n=0; grep -v '^$' "$SEC95" > "$SB/sec95.pat"
  [ -s "$SB/sec95.pat" ] || { echo nopattern; return; }
  while IFS= read -r f; do [ -f "$f" ] && grep -qFf "$SB/sec95.pat" -- "$f" && n=$((n+1)); done < <(sweep_files95)
  rm -f "$SB/sec95.pat"; echo "$n"
}
oldkey_hits95() { local f n=0; while IFS= read -r f; do [ -f "$f" ] && grep -qiF 'DISHNET_REPAIR' -- "$f" && n=$((n+1)); done < <(sweep_files95); echo "$n"; }

# ── The stand-in public.php learns two more triggers, each spent once: a row in the migration ledger written DURING a run,
# after stage A read the ledger (.add_ledger) — what R7 must catch (5); and a file 5.18.95 changed left behind by a
# rollback's copy (.left95, naming the file to copy) — at the first request once 5.18.94's manifest is installed again,
# so never before the copy — what RB must catch (6). Asserted, as every edit here. ──────────────────────────────────
python3 - "$SB/web/public.php" <<'PY' || echo "standin:ledger" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]; s = open(p).read()
old = "$page = $_GET['page'] ?? '';\n"
assert s.count(old) == 1
new = """if ($spend('.add_ledger')) {   // 5.18.95's rehearsal: a row in the migration ledger, written DURING a run, after stage A read it (R7)
    $pdo->exec("INSERT OR IGNORE INTO _migrations (filename, checksum) VALUES ('999_rehearsal_planted.sql', 'rh00000000000000000000')");
}
$left95 = is_file($web . '/.left95') ? trim((string)file_get_contents($web . '/.left95')) : '';
if ($left95 !== '' && strpos((string)@file_get_contents($root . '/manifest.json'), '"5.18.94"') !== false && $spend('.left95')) {
    copy($left95, $root . '/includes/routes.php');   // 5.18.95's rehearsal: 5.18.95's routes.php left behind by a rollback's copy (RB)
}
if (($_GET['page'] ?? '') === 'rh_ledger_probe') { echo 'ledger-trigger-ready'; exit; }
""" + old
s = s.replace(old, new, 1)
assert s.count("'.add_ledger'") == 1 and s.count("'.left95'") == 1 and s.count('rh_ledger_probe') == 1 and s.count(old) == 1
open(p, 'w').write(s)
PY
for i in $(seq 1 60); do pubget page=rh_ledger_probe | grep -q 'ledger-trigger-ready' && break; sleep 0.1; done   # PHP re-reads the edited file
check "$(pubget page=rh_ledger_probe)$(sq "SELECT COUNT(*) FROM _migrations WHERE filename = '999_rehearsal_planted.sql'")$(ls -A "$SB/web" | grep -c '^\.add_ledger$\|^\.left95$')" "ledger-trigger-ready00" \
  "control: the stand-in carries the two triggers, unspent and unset — no planted row"

echo; echo "== 95-pre. production's state: 5.18.93 live refuses; then 5.18.94 deployed for real, its --after-only, the quarantine's --check =="
# With 5.18.93 still live — before production's own 5.18.94 deploy — the deploy refuses: 5.18.94 first. A22 has read the
# fixes by then (it does not depend on what is live); nothing is changed.
fresh95; OUT="$(run95 --answer NO)"
check "$(has "$OUT" "NO-GO: the container serves $B93; 5.18.95 was built and tested against $P94 (5.18.94) — deploy 5.18.94 first (scripts/deploy-5.18.94.sh)")$(has "$OUT" 'Nothing was changed. Send the log file.')$(went95 "$OUT")$(okline "$OUT" A22a 'in the pin')|$(live)|$(backups95)|$(data94)" "yesyesnonoyes|$B93|0|$D94" \
  "pre-a with 5.18.93 still live, the deploy refuses: 5.18.94 first — A22 read the fixes before it, nothing changed"
copy_back_94() {   # the 5.18.94 copy N minutes ago: lib/LegalContent.php's time (its stamp_copy), the recorded copy time, the start before it
  touch -d "-$1 minutes" "$PD/lib/LegalContent.php"; local t; t="$(stat -c %Y "$PD/lib/LegalContent.php")"
  sed -i -e "s/^COPY_AT=.*/COPY_AT=$t/" -e "s/^DEPLOYED_AT=.*/DEPLOYED_AT=$(date -u -d "@$(( t - 60 ))" +%Y-%m-%dT%H:%M:%SZ)/" "$STATE94"; }
fresh94; OUT="$(run94 --answer DEPLOY)"
check "$(fails "$OUT")$(has "$OUT" '5.18.94 (deploy): PASSED')$(live)$(inst_ver)" "0yes${P94}5.18.94" "pre-b the REAL 5.18.94 deploy, DEPLOY typed, the day before its documents' date in Kampala: PASSES, $P94 serving, manifest 5.18.94"
copy_back_94 30; COPY94="$(sed -n 's/^COPY_AT=//p' "$STATE94" | tail -1)"
set_sched $(( COPY94 + 300 )); base_log; OUT="$(run94 --after-only)"
check "$(fails "$OUT")$(okline "$OUT" R19 'the quarantine holds: master ran the retry job at')$(has "$OUT" '5.18.94 (after): PASSED')" "0yesyes" \
  "pre-c …its --after-only, master's run of the retry job since the copy: R19 conclusive, PASSED"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "pre-d …and the quarantine's own --check, as its runbook orders after it: PASSED with 5.18.94 live"
check "$(grep -c '^STRAY_Q_FAILED=' "$STATE94")$(grep -c '^COPY_AT=' "$STATE94")$(ls -d "$RECS/dnb-5.18.94" >/dev/null 2>&1 && echo found)" "01found" \
  "pre-e …5.18.94's state file, where 5.18.95's A14 looks for it (<records>/dnb-5.18.94): the copy's time, no failure recorded"
KEPT95="$(kept_state)"; QREC95="$(sha256sum "$QREC" | cut -c1-16)"; D95="$(data94)"
check "$( [ -e "$KEYH" ] && echo present || echo absent)$(sq "SELECT COUNT(*) FROM kyc_config WHERE id = 0")" "absent1" \
  "control: no repair key file in the data directory, one settings row in the store — as production may have it"

echo; echo "== 95-0. what is rehearsed: 5.18.95 — the controls =="
echo "  script    $WHICH95, sha256 $(sha256sum "$DEPLOY95" | cut -c1-16)"
case "$P95" in __*|'') echo "  FAIL the script still carries the placeholder pin ($P95)"; exit 2;; esac
check "$P95" "$(git -C "$REPO" rev-parse --short "origin/$REL95" 2>/dev/null || echo none)" "the script is pinned to the release commit on $REL95 ($P95)"
check "$(git -C "$REPO" rev-parse --short "$P95^" 2>/dev/null)$(ver "$P95")$(ver "$P94")" "${P94}5.18.955.18.94" "the release commit is cut on $P94 (5.18.94, the live version) and is 5.18.95"
check "$(dl D "$P94" "$P95" | grep -c .)$(dl A "$P94" "$P95" | grep -c .)$(dl AM "$P94" "$P95" | grep -c .)$(git -C "$REPO" diff --no-renames --name-only --diff-filter=CRTUXB "$P94" "$P95" | grep -c .)" "04${N95}0" \
  "5.18.94 → 5.18.95: nothing removed, copied, renamed or retyped, four files added, $N95 in all"
check "$(dl A "$P94" "$P95" | sort | paste -sd' ' -)" "$(for f in $ADDED95; do echo "$P/$f"; done | sort | paste -sd' ' -)" "…the four added are the four new tests"
W95=""; for f in $ADDED95 $HUNKS95 $TAKEN95 $PINS95 manifest.json; do grep -qx "$P/$f" <(dl AM "$P94" "$P95") || W95="$W95 $f"; done
check "${W95:-none}" "none" "…and every file of the reviewed set is in the delta"
check "$(git -C "$REPO" diff --name-only "$P94" "$P95" | grep -vc "^$P/")$(dl AM "$P94" "$P95" | grep -c "^$P/migrations/")" "00" "nothing outside the plugin, and no migration"
check "$(git -C "$REPO" rev-list --count "$DEV95_BASE..$FIX95" -- "$P")" "2" "control: the development branch's change is its two commits on $DEV95_BASE"
T95=""; for f in $ADDED95 $TAKEN95 $PINS95 manifest.json; do [ "$(git -C "$REPO" rev-parse "$P95:$P/$f")" = "$(git -C "$REPO" rev-parse "$FIX95:$P/$f" 2>/dev/null)" ] || T95="$T95 $f"; done
check "${T95:-same}" "same" "the nineteen files taken whole are the development branch's ($FIX95) byte for byte"
hunk95() {   # hunk95 FILE — 5.18.94's file with the development branch's change to it ($DEV95_BASE → $FIX95) applied, against the release's
  local d="$SB/hunk95"; rm -rf "$d"; mkdir -p "$d"
  git -C "$REPO" show "$P94:$P/$1" > "$d/base"
  git -C "$REPO" diff "$DEV95_BASE" "$FIX95" -- "$P/$1" > "$d/change.diff"
  [ -s "$d/change.diff" ] || { echo nochange; return; }
  patch -s --no-backup-if-mismatch -o "$d/out" "$d/base" < "$d/change.diff" >/dev/null 2>&1 || { echo noapply; return; }
  cmp -s "$d/out" <(git -C "$REPO" show "$P95:$P/$1") && echo same || echo differs
}
H95=""; for f in $HUNKS95; do [ "$(hunk95 "$f")" = "same" ] || H95="$H95 $f($(hunk95 "$f"))"; done
check "${H95:-same}" "same" "public.php and includes/routes.php are live's (5.18.94's) with the development branch's change to each applied, and nothing else"
check "$(for f in $HUNKS95; do [ "$(git -C "$REPO" rev-parse "$P95:$P/$f")" = "$(git -C "$REPO" rev-parse "$FIX95:$P/$f")" ] && echo whole || echo hunks; done | paste -sd, -)" "hunks,hunks" \
  "control: neither is the development branch's file whole — the branch tip carries undeployed work in both"
pin_only95() {   # pin_only95 FILE — the release's file is 5.18.94's with every 5.18.94 → 5.18.95 and nothing else
  python3 - <(git -C "$REPO" show "$P94:$P/$1") <(git -C "$REPO" show "$P95:$P/$1") <<'PY'
import sys
a, b = open(sys.argv[1]).read(), open(sys.argv[2]).read()
print('pin' if a.count('5.18.94') >= 1 and a.replace('5.18.94', '5.18.95') == b and a != b else 'other')
PY
}
V95=""; for f in manifest.json $PINS95; do [ "$(pin_only95 "$f")" = "pin" ] || V95="$V95 $f"; done
check "${V95:-pins}" "pins" "the manifest and the five version pins are 5.18.94's with the version alone changed"
check "$(pin_only95 public.php)" "other" "control: a file of the change is not a version pin (the comparison can tell them apart)"
S95=""; for f in main.php cron/master.php cron_starlink_block_retry.php lib/StarlinkRetryScope.php tests/test_master_lock_release.php tests/test_main_auto_pull_retired.php migrations/087_wa_channels.sql lib/LegalContent.php lib/InstallationTerms.php profiles/uganda.json tests/test_legal_overrides.php lib/PdfLinkToken.php; do
  [ "$(git -C "$REPO" rev-parse "$P95:$P/$f")" = "$(git -C "$REPO" rev-parse "$P94:$P/$f")" ] || S95="$S95 $f"; done
check "${S95:-same}" "same" "main.php, master.php, the retry job, its gate, both tests of them, 087, 5.18.94's documents and their test, and PdfLinkToken are 5.18.94's byte for byte"
check "$(git -C "$REPO" rev-parse "$P94:$P/dishnet-mikrotik-control-plane")$(git -C "$REPO" rev-parse "$P94:$P/docs")" \
      "$(git -C "$REPO" rev-parse "$P95:$P/dishnet-mikrotik-control-plane")$(git -C "$REPO" rev-parse "$P95:$P/docs")" "Domain B's tree and the plugin's docs are 5.18.94's"
check "$(hdr95 "$DEPLOY95")" "101" \
  "the header's deploy command stands alone, with its own log wrapper — no rollback, put-back, setting or checkout in its block (docs/44 §16.9) — and the later --after-only is its own, with its own"
HDR_DEPLOY_LINE='#     && bash scripts/deploy-5.18.95.sh 2>&1 | ( umask 077; exec tee /root/dnb-5.18.95/deploy-$(date -u +%Y%m%dT%H%M%SZ).log )'
M="$(mutant hdrrb95 "$DEPLOY95" "$HDR_DEPLOY_LINE" "$HDR_DEPLOY_LINE
#   cd /opt/dishnet && bash scripts/deploy-5.18.95.sh --rollback 2>&1 | ( umask 077; exec tee /root/dnb-5.18.95/rollback-\$(date -u +%Y%m%dT%H%M%SZ).log )")"
check "$(hdr95 "$M")" "111" "0-hdr' a copy whose header carries the rollback inside the deploy command's block is caught by the same count"
rm -f "$M"
check "$(cmds95 "$DEPLOY95")" "111111|6|7|12|1" \
  "every command the script prints for a later step is defined with its own log wrapper — the --after-only, the rollback, the deploy, the put-back, the key file's, and the quarantine's --check with its tee — and each of the thirteen lines that print one names one of them, or carries the wrapper itself"
M="$(mutant fnowrap95s "$DEPLOY95" 'AFTER_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --after-only $(LOGW after)"' 'AFTER_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --after-only"')"
check "$(cmds95 "$M")" "011111|6|7|12|1" "0-cmd' a copy that defines the --after-only without its log wrapper is caught by the same reading (and by its run, 5d')"
rm -f "$M"
check "$(grep -cE -- '--key +(ai_identity_line|wa_flyer_caption|email_badge_line|recording_retention_days|splynx_key|splynx_secret|pdf_link_secret)' "$DEPLOY95")$(grep -cE 'quarantine\.env[^"]*>>|>> *"\$Q_RECORD"|rm [^|;]*\$Q_KEPT|mv [^|;]*\$Q_KEPT' "$DEPLOY95")$(grep -cE '(>|>>) *"?\$KEY_HOST|(cp|mv|rm|touch|chmod|chown|cat|head|tail) [^|;]*\$KEY_HOST' "$DEPLOY95")" "000" \
  "the script sets no setting, never writes the quarantine's record or moves its kept file, and never writes, copies or reads out the repair key file itself"
KS95="$(sed -n '/^key_state() {/,/^}/p' "$DEPLOY95")"
check "$(grep -c '' <<<"$KS95")|$(grep -cE '(^|[^A-Za-z_])(cat|head|tail|less|more|od|xxd|hexdump|base64|strings|sed|awk|read|source|cp|mv|php|python3?)([^A-Za-z_]|$)' <<<"$KS95")|$(grep -oE 'grep -[A-Za-z]+' <<<"$KS95" | grep -vc 'c')|$(grep -c 'wc -c < "$f"' <<<"$KS95")" "14|0|0|1" \
  "key_state reads the key file only as a size (wc -c) and as counts (every grep a -c) — nothing in it could print the content"
KC95="$(sed -n '/^key_cmd() {/,/^}/p' "$DEPLOY95")"
check "$(grep -c 'openssl rand -hex 24 > "$f"' <<<"$KC95")$(grep -c 'openssl rand' "$DEPLOY95")$(grep -cF 'echo "repair key written: $(wc -c < "$f") bytes, owner $(stat -c %%u:%%g "$f"), mode $(stat -c %%a "$f")"' <<<"$KC95")$(grep -c 'set -C; umask 077' <<<"$KC95")" "1111" \
  "the key command writes the key with openssl straight into the file (noclobber, umask 077) and prints only its size, owner and mode"
check "$(grep -c 'DISHNET_REPAIR' "$DEPLOY95")$(grep 'DISHNET_REPAIR' "$DEPLOY95" | grep -vc 'grep -')" "50" \
  "the script names the old built-in key on five lines, each a grep pattern that counts or refuses it — never a value it prints or sets"
cat > "$SB/spx95.py" <<'PY'
import re, sys
s = sys.stdin.read()
v = sorted(set(m[1] for m in re.findall(r"""['"]splynx_(key|secret)['"]\s*(?:\]\s*=|=>)\s*['"]([^'"]+)['"]""", s)))
open(sys.argv[1], 'w').write(''.join(x + '\n' for x in v))
print(len(v))
PY
( umask 077; for f in cron_splynx_sync.php includes/routes.php public.php; do git -C "$REPO" show "$P94:$P/$f"; done | python3 -I "$SB/spx95.py" "$SB/spx.vals" > "$SB/spx.n" )
SPXC="$(git -C "$REPO" show "$P94:$P/cron_splynx_sync.php" | grep -cFf "$SB/spx.vals")"
check "$(cat "$SB/spx.n")|$(for f in cron_splynx_sync.php includes/routes.php public.php; do git -C "$REPO" show "$P95:$P/$f"; done | grep -cFf "$SB/spx.vals")|$(grep -cFf "$SB/spx.vals" "$DEPLOY95")|$( [ "${SPXC:-0}" -ge 1 ] && echo seen)" "2|0|0|seen" \
  "5.18.94's code holds two Splynx values (read into a 0600 file, never printed); the release's three files hold neither, nor does the script — the control: 5.18.94's cron_splynx_sync.php shows them"
cat "$SB/spx.vals" >> "$SEC95"
check "$(bash -n "$DEPLOY95" && echo parses)" "parses" "the script parses"
check "$(git -C "$REPO" log -1 --format=%h "$P95" -- $P)|$(git -C "$REPO" log -1 --format=%h "$P94" -- $P)" "$P95|$P94" \
  "control: the clone, in one pack as a fresh clone is, writes the 7-character short forms the script names"

echo; echo "== 95-1. A14: a failure 5.18.94's own runs recorded is not forgotten; A20 is gone =="
printf 'STRAY_Q_FAILED=%s\n' "$(date -u +%s)" >> "$STATE94"
fresh95; OUT="$(run95 --answer NO)"
nogo95 "1a a failure in 5.18.94's state file (STRAY_Q_FAILED, as its R19 or RB writes it), newer than the newest passing --check: NO-GO at A14" \
  "NO-GO (A14): a run of this script or of 5.18.94's found the quarantine broken at" "$OUT"
check "$(has "$OUT" '(STRAY_Q_FAILED in dnb-5.18.94/state-5.18.94.env)')$(has "$OUT" 'and the newest passing --check of the quarantine began before that')$(grep -cxF "      $EXP_QCHECK" <<<"$OUT")" "yesyes1" \
  "…it names 5.18.94's file, and prints the quarantine's --check on its own line, with its own log"
M="$(mutant a14prev95 "$DEPLOY95" 'for f in "$STATE" "$STATE".prev-* "$PREV_STATE" "$PREV_STATE".prev-*; do' 'for f in "$STATE" "$STATE".prev-*; do')"
fresh95; OUT="$(R95_MUTANT_OUT=1 run95 --answer NO --script "$M")"; rm -f "$M"
check "$(went95 "$OUT")$(has "$OUT" 'STOP: not confirmed')" "yesyesyes" "1a' a copy blind to 5.18.94's state files — reading only its own, as 5.18.94's A14 did — reaches the question over it (caught)"
sed -i '/^STRAY_Q_FAILED=/d' "$STATE94"; printf 'STRAY_Q_FAILED=%s\n' "$(date -u +%s)" > "$STATE94.prev-20261016T120000Z"
fresh95; OUT="$(run95 --answer NO)"
nogo95 "1b …in a state file a later deploy of 5.18.94 kept beside its own: NO-GO at A14, naming it" "(STRAY_Q_FAILED in dnb-5.18.94/state-5.18.94.env.prev-20261016T120000Z)" "$OUT"
rm -f "$STATE94.prev-20261016T120000Z"
printf 'STRAY_Q_FAILED=soon\n' >> "$STATE94"
fresh95; OUT="$(run95 --answer NO)"
nogo95 "1c a recorded failure whose time cannot be read: NO-GO at A14" \
  "recorded the quarantine broken (STRAY_Q_FAILED in dnb-5.18.94/state-5.18.94.env) at a time that cannot be read" "$OUT"
sed -i '/^STRAY_Q_FAILED=/d' "$STATE94"
printf 'STRAY_Q_FAILED=%s\n' "$(( $(date -u +%s) - 30 ))" >> "$STATE94"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "1d the quarantine's own --check, begun after that failure: PASSED"
QREC95="$(sha256sum "$QREC" | cut -c1-16)"; KEPT95="$(kept_state)"
fresh95; OUT="$(run95 --answer NO)"
check "$(noteline "$OUT" A14 "a run of this script or of 5.18.94's found the quarantine broken at" '(STRAY_Q_FAILED in dnb-5.18.94/state-5.18.94.env)' 'after it, PASSED — so this deploy goes on')$(okline "$OUT" A14 'the quarantine holds')$(went95 "$OUT")$(has "$OUT" 'STOP: not confirmed')" "yesyesyesyesyes" \
  "1e …then A14 passes and says the failure; a word other than DEPLOY stops the run at the question"
check "$(grep -cE '^  (ok    |note  |FAIL  )A20 |\(A20\)' <<<"$OUT")$(went95 "$OUT")" "0yesyes" \
  "1f on 17 October in Kampala — the day after 5.18.94's documents' date, every 5.18.95 run's today here — the deploy names no A20 and reaches its question"
A20_94="$(awk 'index($0, "  DATED_YMD=\"$(date -d \"$UG_LEGAL_DATED\"") == 1 { p = 1 } p { print } p && index($0, "  else note \"A20 ") == 1 { exit }' "$DEPLOY94")"
check "$(grep -c '' <<<"$A20_94")$(grep -c 'NO-GO (A20)' <<<"$A20_94")" "52" "control: 5.18.94's A20, cut from its script — five lines, its two refusals"
A20_OLD="# A19 (the website) and A20 (the documents' date) were 5.18.94's own preconditions, and are gone in 5.18.95: the documents"
M="$(mutant a20kept95 "$DEPLOY95" "$A20_OLD" "if [ \"\$MODE\" = \"deploy\" ]; then   # a weakened copy: 5.18.94's A20, kept
$A20_94
fi
$A20_OLD")"
fresh95; OUT="$(R95_MUTANT_OUT=1 run95 --answer NO --script "$M" --today "$AFTER94")"; rm -f "$M"
check "$(has "$OUT" "NO-GO (A20): the Uganda documents are dated 16 October 2026, before today in Kampala ($AFTER94)")$(went95 "$OUT")|$(quiet95)" "yesnono|$P94|0|$D95|$KEPT95|0" \
  "1f' a copy with 5.18.94's A20 kept refuses on 17 October, as it would on every day after 16 October (caught: the script itself reaches its question)"
# The quarantine's rule 3: after any plugin upload, its --check. A copy of 5.18.94's recorded after the newest passing
# --check — here in a state file a later deploy of 5.18.94 kept beside its own — refuses; a copy blind to it is caught.
printf 'COPY_AT=%s\n' "$(date -u +%s)" > "$STATE94.prev-20261017T000100Z"
fresh95; OUT="$(run95 --answer NO)"
nogo95 "1g a copy of 5.18.94's recorded after the newest passing --check of the quarantine (its rule 3: a --check after every plugin upload): NO-GO at A14" \
  "came after the newest passing --check of the quarantine" "$OUT"
check "$(has "$OUT" '(dnb-5.18.94/state-5.18.94.env.prev-20261017T000100Z)')$(grep -cxF "      $EXP_QCHECK" <<<"$OUT")" "yes1" \
  "…it names 5.18.94's file, and prints the quarantine's --check on its own line, with its own log"
M="$(mutant a14copy95 "$DEPLOY95" '[ "$QCL_T" -gt "$PREV_COPY" ] || stop' 'true || stop')"
fresh95; OUT="$(R95_MUTANT_OUT=1 run95 --answer NO --script "$M")"; rm -f "$M"
check "$(went95 "$OUT")$(has "$OUT" 'STOP: not confirmed')" "yesyesyes" "1g' a copy blind to 5.18.94's copy time reaches the question over it (caught)"
rm -f "$STATE94.prev-20261017T000100Z"
fresh95; OUT="$(run95 --answer NO)"
check "$(okline "$OUT" A14 'the quarantine holds' "it began after 5.18.94's copy (")$(went95 "$OUT")" "yesyesyes" \
  "1h …that planted copy time taken out: A14 passes again and says its --check began after 5.18.94's copy (the pre section's)"
sed -i '/^STRAY_Q_FAILED=/d' "$STATE94"
check "$(grep -c '^STRAY_Q_FAILED=' "$STATE94")|$(quiet95 | cut -d'|' -f1,3-)|$(sha256sum "$QREC" | cut -c1-16)" "0|$P94|$D95|$KEPT95|0|$QREC95" \
  "…every planted line taken out: 5.18.94 live, the data, the quarantine and its record as before — the script read the record, never wrote it"

echo; echo "== 95-2. A23: the repair key file in each state, the Splynx key and secret, the pdf_link_secret — said, never a value =="
OLDKEY95="DISHNET_REPAIR"   # the old built-in key: public since it was written in the code; planted only in variants and a test file
fresh95; OUT="$(run95 --answer NO)"; OUT95A="$OUT"
check "$(noteline "$OUT" A23a "($KWHERE): it is ABSENT." 'the emergency-repair page is SHUT — 403 for everyone —' 'today, under 5.18.94, the page opens with the old built-in key, to anyone')$(noteline "$OUT" A23b 'the Splynx key is ABSENT from the settings (splynx_key absent, splynx_secret absent;' 'Splynx sync reports "not configured"')$(noteline "$OUT" A23c 'the pdf_link_secret is absent from the settings')$(went95 "$OUT")" "yesyesyesyesyes" \
  "2a no key file, no Splynx key or secret, no pdf_link_secret: each said — the page shut after this deploy, open to anyone today — and the run reaches the question (a note never stops it)"
key_put root:root '%s\n' "$(openssl rand -hex 24)"
fresh95; OUT="$(run95 --answer NO)"
check "$(noteline "$OUT" A23a "($KWHERE): the plugin's PHP user (1000:1000, the owner of plugin.sqlite3) cannot read it.")$(key_shown "$OUT")$(went95 "$OUT")" "yesnoyesyes" \
  "2b a key file root's own, 0600 — what a plain openssl > file leaves: the plugin's user cannot read it, said; the key never printed"
key_put 1000:1000 '%s\n' "$(openssl rand -hex 24)"
( umask 077; openssl rand -hex 24 > "$KEYH.replaced-20261017T000000Z" ); chown 1000:1000 "$KEYH.replaced-20261017T000000Z"; secret_add_file "$KEYH.replaced-20261017T000000Z"
fresh95; OUT="$(run95 --answer NO)"; BKD="$(ls -d "$OUT95"/backup-* 2>/dev/null | head -1)"
check "$(okline "$OUT" A23a "the repair key file is in place ($KWHERE: 32-64 bytes, one line of at least 32 printable characters with no space, readable by the plugin's PHP user)" 'its content was not read')$(key_shown "$OUT")$(went95 "$OUT")" "yesnoyesyes" \
  "2c a good key file — one line of 48 hex characters, the plugin's user's, 0600: A23a ok, the key never printed"
check "$(tar -tzf "$BKD/data.tar.gz" 2>/dev/null | grep -c 'emergency_repair_key')$(tar -tzf "$BKD/data.tar.gz" 2>/dev/null | grep -c '/kyc_config.json$')$(has "$OUT" 'and without the repair key file, never copied')" "01yes" \
  "…the backup GO takes holds neither the key file nor a key moved aside, and holds the data folder's settings file (the control)"
M="$(mutant noexclude95 "$DEPLOY95" '    ex+=("--exclude=$(basename "$DATA_HOST")/$KEY_NAME" "--exclude=$(basename "$DATA_HOST")/$KEY_NAME.replaced-*")   # 5.18.95: the repair key is never copied' '    :')"
fresh95; OUT="$(R95_MUTANT_OUT=1 run95 --answer NO --script "$M")"; rm -f "$M"; BKD="$(ls -d "$OUT95"/backup-* 2>/dev/null | head -1)"
check "$(tar -tzf "$BKD/data.tar.gz" 2>/dev/null | grep -c 'emergency_repair_key')" "2" "2c' a copy without the exclusion puts both keys in its backup (caught by the same listing)"
A_WC='  n="$(wc -c < "$f" 2>/dev/null | tr -d '"' '"')"'
M="$(mutant catkey95 "$DEPLOY95" "$A_WC" "$A_WC"'; echo "  key   $(cat "$f")" >&2')"
fresh95; OUT="$(R95_MUTANT_OUT=1 run95 --answer NO --script "$M")"; rm -f "$M"
check "$(key_shown "$OUT")" "yes" "2c'' a copy whose key note reads the file out — the key note printing the content — is caught by the assertion the script passes (the key in its output)"
key_put 1000:1000 '%s\n' "rh-short-$(openssl rand -hex 5)"
fresh95; OUT="$(run95 --answer NO)"
check "$(noteline "$OUT" A23a "it is not one line of at least 32 printable characters with no space (1-31 bytes; 1 line(s), 1 not blank, 0 of the key's form")$(key_shown "$OUT")$(went95 "$OUT")" "yesnoyesyes" \
  "2d a key of 19 characters: too short, said by its size bucket; never printed"
KH1="$(openssl rand -hex 12)"; KH2="$(openssl rand -hex 12)"; key_put 1000:1000 '%s %s\n' "$KH1" "$KH2"; printf '%s\n' "$KH1" "$KH2" >> "$SEC95"
fresh95; OUT="$(run95 --answer NO)"
check "$(noteline "$OUT" A23a "it is not one line of at least 32 printable characters with no space (32-64 bytes; 1 line(s), 1 not blank, 0 of the key's form — a space inside the key, or a second line, counts against it, as public.php reads the file)")$(key_shown "$OUT")$(grep -cF -e "$KH1" -e "$KH2" <<<"$OUT")$(went95 "$OUT")" "yesno0yesyes" \
  "2e a key with a space inside (two halves of 24 characters): not the key's form, said by its size and counts; neither half printed"
key_put 1000:1000 '%s\r\n' "$(openssl rand -hex 24)"
fresh95; OUT="$(run95 --answer NO)"
check "$(okline "$OUT" A23a "the repair key file is in place ($KWHERE: 32-64 bytes, one line of at least 32 printable characters with no space, readable by the plugin's PHP user)")$(key_shown "$OUT")$(went95 "$OUT")" "yesnoyesyes" \
  "2f a key with a Windows line ending: in place, as public.php's trim() reads the file — never called shut; never printed"
key_put 1000:1000 '%s\n' "$OLDKEY95-$(openssl rand -hex 10)"
fresh95; OUT="$(run95 --answer NO)"
check "$(noteline "$OUT" A23a 'it holds the old built-in key, which 5.18.95 refuses')$(key_shown "$OUT")$(has "$OUT" "$OLDKEY95")$(went95 "$OUT")" "yesnonoyesyes" \
  "2g a key file holding the old built-in key: refused by 5.18.95, said — neither the key nor the old key's name printed"
# Six more forms through the script's own key_state and key_note, cut from it (asserted) and run on their own: fast, and
# exactly the code the deploy runs.
KEYF="$SB/keyfuncs95.sh"
sed -n '/^key_state() {/,/^}/p;/^kf() {/p;/^key_note() {/,/^}/p' "$DEPLOY95" > "$KEYF"
check "$(grep -c '^key_state() {\|^kf() {\|^key_note() {' "$KEYF")" "3" "control: key_state, kf and key_note cut from the script"
keynote_on() {   # the script's own key_state and key_note on the key file as it is now; prints the note and the state line
  ( ok() { printf '  ok    %s\n' "$*"; }; note() { printf '  note  %s\n' "$*"; }; bad() { printf '  FAIL  %s\n' "$*"; }
    export PATH="$SB/bin:$PATH"; KEY_NAME=emergency_repair_key.txt; PDD_IN="/data/ucrm/data/plugins/.$P-data"; KEY_HOST="$KEYH"
    DB_OWNER=1000:1000; CONTAINER=ucrm; BASELINE_VERSION=5.18.94; EXPECTED_VERSION=5.18.95; KEY_OK=""; KEY_STATE=""; KEY_SAYS=""
    # shellcheck disable=SC1090
    . "$KEYF"; key_note A23a before; echo "  state $KEY_STATE" )
}
key_put 1000:1000 '%s'; O="$(keynote_on)"
check "$(noteline "$O" A23a "(0 bytes; 0 line(s), 0 not blank, 0 of the key's form")" "yes" "2h an empty key file: said, by its size"
key_put 1000:1000 '%s\n%s\n' "$(openssl rand -hex 24)" "$(openssl rand -hex 24)"; O="$(keynote_on)"
check "$(noteline "$O" A23a "(65-256 bytes; 2 line(s), 2 not blank, 2 of the key's form")$(key_shown "$O")" "yesno" "2i two keys on two lines: not one line, said"
rm -rf "$KEYH"; mkdir "$KEYH"; O="$(keynote_on)"; rm -rf "$KEYH"
check "$(noteline "$O" A23a 'something that is not a regular file is at its name')" "yes" "2j a folder at the key file's name: said"
key_put 1000:1000 '%s\n\n' "$(openssl rand -hex 24)"; O1="$(keynote_on)"
key_put 1000:1000 '%s' "$(openssl rand -hex 24)"; O2="$(keynote_on)"
key_put 1000:1000 '%s\n' "$(openssl rand -hex 150)"; O3="$(keynote_on)"
check "$(okline "$O1" A23a 'in place' '32-64 bytes')$(okline "$O2" A23a 'in place' '32-64 bytes')$(okline "$O3" A23a 'in place' '257+ bytes')$(key_shown "$O3")" "yesyesyesno" \
  "2k one key line followed by a blank line, one with no line ending, and one of 300 characters: each in place, as public.php's trim and its rule read them"
K4="$(openssl rand -hex 24)"; printf '%s\n' "$K4" >> "$SEC95"; key_put 1000:1000 ' \t%s \t\r\n\r\n' "$K4"; O4="$(keynote_on)"
check "$(okline "$O4" A23a 'in place' '32-64 bytes')$(grep -cF -- "$K4" <<<"$O4")" "yes0" \
  "2k' a key with blanks and a tab around it, a Windows line ending, then a blank line: in place, as public.php's trim() reads the file; never printed"
key_gone
# The Splynx key and secret and the pdf_link_secret, planted (fictitious) in the store copy: present, never a value.
cfg_keep; vault_keep
row_set splynx_key "$RH_SPX_KEY"; row_set splynx_secret "$RH_SPX_SEC"; row_set pdf_link_secret "$RH_PDF"; DX="$(data94)"
fresh95; OUT="$(run95 --answer NO)"
check "$(noteline "$OUT" A23b 'the Splynx key and secret are present in the settings (the store copy of kyc_config.json; their values never read out)' 'replace them in Splynx')$(okline "$OUT" A23c 'the pdf_link_secret is present in the settings (its value never read out)')$(grep -cF -e "$RH_SPX_KEY" -e "$RH_SPX_SEC" -e "$RH_PDF" <<<"$OUT")$(went95 "$OUT")|$(data94)" "yesyes0yesyes|$DX" \
  "2l the Splynx key and secret and the pdf_link_secret present: said present — none of the three values in the output; nothing written"
M="$(mutant cfgp95 "$DEPLOY95" '(empty(is_string($v) ? trim($v) : $v) ? "absent" : "present")' '(is_string($v) ? $v : "absent")')"
fresh95; OUT="$(R95_MUTANT_OUT=1 run95 --answer NO --script "$M")"; rm -f "$M"
check "$(has "$OUT" "$RH_SPX_KEY")" "yes" "2l' a copy whose reading returns the values is caught by the same assertion (a value in its output)"
row_unset splynx_secret
fresh95; OUT="$(run95 --answer NO)"
check "$(noteline "$OUT" A23b 'the Splynx secret is ABSENT from the settings (splynx_key present, splynx_secret absent;')$(grep -cF -e "$RH_SPX_KEY" -e "$RH_PDF" <<<"$OUT")" "yes0" \
  "2m the key without the secret: said — Splynx sync is refused until the secret is entered"
cfg_back; vault_back
check "$(data94)|$( [ -e "$KEYH" ] && echo present || echo absent)|$(live)|$(kept_state)" "$D95|absent|$P94|$KEPT95" "…each put back: the data as before, no key file, 5.18.94 live, the quarantine as it was"
check "$( [ "$(grep -c . "$SEC95")" -ge 15 ] && echo listed)|$(secret_hits95)|$(oldkey_hits95)" "listed|0|0" \
  "2n no output, log or state file of this section's runs holds any planted key, a half of one, a planted Splynx or pdf value, one of 5.18.94's Splynx values, or the old key's name — the weakened copies that print one on purpose aside"

echo; echo "== 95-3. A22: each fix taken back in a release variant refuses; each control reads 5.18.94's copy =="
check "$(ctl95 "$OUT95A" A22)" "7" \
  "3 every one of the seven checks reads the pin ok and names its control's defects in 5.18.94's copy exactly — on its line and in its ok line, in fixed words"
release_variant95() {   # release_variant95 PATH OLD NEW — the release commit with one file edited (OLD exactly once), cut on 5.18.94
  local idx="$SB/variant95.idx" blob tree c
  GIT_INDEX_FILE="$idx" git -C "$REPO" read-tree "$P95"
  blob="$(git -C "$REPO" show "$P95:$P/$1" | python3 -c 'import sys
s = sys.stdin.read(); old, new = sys.argv[1], sys.argv[2]
assert s.count(old) == 1, "variant anchor not unique"
sys.stdout.write(s.replace(old, new))' "$2" "$3" | git -C "$REPO" hash-object -w --stdin)"
  [ "$blob" = "$(printf '' | git -C "$REPO" hash-object --stdin)" ] && echo "release_variant95:$1" >> "$SB/anchor-missing"
  GIT_INDEX_FILE="$idx" git -C "$REPO" update-index --cacheinfo "100644,$blob,$P/$1"
  tree="$(GIT_INDEX_FILE="$idx" git -C "$REPO" write-tree)"; rm -f "$idx"
  c="$(git -C "$REPO" -c user.name=rehearsal -c user.email=rehearsal@example.test commit-tree "$tree" -p "$(git -C "$REPO" rev-parse "$P94")" -m "rehearsal variant: $1")"
  git -C "$REPO" rev-parse --short "$c"
}
v95() {   # v95 LABEL EXPECTED-TEXT PATH OLD NEW — the pin with one fix taken back: refused at A22 (the variant cut on 5.18.94, the script pinned to it)
  local v s o; v="$(release_variant95 "$3" "$4" "$5")"; s="$(pinned_copy "v95-$RANDOM" "$v" "$DEPLOY95")"; fresh95; o="$(run95 --answer NO --script "$s")"; rm -f "$s"
  nogo95 "$1" "$2" "$o"
}
NOGO22="NO-GO (A22): the pin does not carry 5.18.95's fixes as they were reviewed:"
W22="— this script is for another build"
# ── the variants (begin) ──
VA_OLD="        || in_array('', \$fSeg, true) || in_array('.', \$fSeg, true) || in_array('..', \$fSeg, true)) {"
VA_NEW="        || in_array('', \$fSeg, true) || in_array('.', \$fSeg, true)) {"
VB_OLD='    $f    = trim($f);'; VB_NEW="    \$f    = str_replace('../', '', trim(\$f));"
v95 "3a K1 without the '..' segment's refusal: NO-GO at A22" "$NOGO22 A22a(no-dotdot-refusal) $W22" includes/routes.php "$VA_OLD" "$VA_NEW"
v95 "3b K1 with the one-pass strip back: NO-GO at A22" "$NOGO22 A22a(strips) $W22" includes/routes.php "$VB_OLD" "$VB_NEW"
v95 "3c K1 without the backslash refusal — the '.\\.' vector: NO-GO at A22" "$NOGO22 A22a(no-backslash-refusal) $W22" includes/routes.php \
  "if (\$f === '' || \$f[0] === '/' || strpos(\$f, chr(92)) !== false || strpos(\$f, chr(0)) !== false" "if (\$f === '' || \$f[0] === '/' || strpos(\$f, chr(0)) !== false"
v95 "3d K1 without the containment of the realpath()'d file in its folder: NO-GO at A22" "$NOGO22 A22a(no-containment) $W22" includes/routes.php \
  '$full === false || strpos($full, $fBase . DIRECTORY_SEPARATOR) !== 0 || !is_file($full)) {' '$full === false || !is_file($full)) {'
v95 "3e K2 with the built-in key back as the fallback: NO-GO at A22" "$NOGO22 A22b(fallback-key old-key-named-2x) $W22" public.php \
  "    \$storedKey = is_string(\$storedKey) ? trim(\$storedKey) : '';" "    \$storedKey = is_string(\$storedKey) ? trim(\$storedKey) : '$OLDKEY95';"
v95 "3f K2 printing the stored key in an action line: NO-GO at A22" "$NOGO22 A22b(prints-the-key echoes-the-key no-placeholder) $W22" public.php \
  '    echo "  ?page=emergency_repair&key=YOUR-KEY&action=vacuum\n";' '    echo "  ?page=emergency_repair&key={$storedKey}&action=vacuum\n";'
v95 "3g K2 comparing the key without hash_equals: NO-GO at A22" "$NOGO22 A22b(no-gate) $W22" public.php '!hash_equals($storedKey, $givenKey)' '$storedKey !== $givenKey'
v95 "3h K2 accepting a key of any length — the 32-character rule taken back: NO-GO at A22" "$NOGO22 A22b(no-key-rule) $W22" public.php \
  '    $keyUsable = strlen($storedKey) >= 32 && ' '    $keyUsable = strlen($storedKey) >= 1 && '
v95 "3i K2 no longer refusing the old built-in key as a stored key: NO-GO at A22" "$NOGO22 A22b(old-key-named-0x) $W22" public.php \
  "=== 1
        && stripos(\$storedKey, '$OLDKEY95') === false;" "=== 1;"
v95 "3j the EFRIS link verified by its own HMAC with the 'dishnet' fallback: NO-GO at A22" "$NOGO22 A22c(verify:no-PdfLinkToken verify:dishnet-fallback verify:own-hmac) $W22" efris_pdf.php \
  'if (!PdfLinkToken::verify($file, $token, $linkCfg) || !is_file($path)) {' "if (!hash_equals(hash_hmac('sha256', \$file, (string)(\$linkCfg['webhook_secret'] ?? 'dishnet')), (string)\$token) || !is_file(\$path)) {"
v95 "3k the EFRIS tab making a link with no key: NO-GO at A22" "$NOGO22 A22c(mint:a-link-without-a-key) $W22" tabs/admin/efris.php "if (\$tok === '') {" "if (false) {"
v95 "3l a Splynx key written in the code again (a fictitious one): NO-GO at A22, naming the file only" "$NOGO22 A22d(literal-in:cron_splynx_sync.php) $W22" cron_splynx_sync.php \
  "if (empty(\$config['splynx_url']))     \$config['splynx_url']    = 'https://my.dishnetafrica.com';" "if (empty(\$config['splynx_url']))     \$config['splynx_url']    = 'https://my.dishnetafrica.com';
if (empty(\$config['splynx_key']))     \$config['splynx_key']    = 'rh-planted-not-a-key';"
v95 "3m ?page=splynx_patch answering 200 again: NO-GO at A22" "$NOGO22 A22d(patch:not-410) $W22" includes/routes.php '    http_response_code(410);' '    http_response_code(200);'
v95 "3n ?page=splynx_patch writing the settings again: NO-GO at A22" "$NOGO22 A22d(patch:writes-or-calls) $W22" includes/routes.php \
  '    http_response_code(410);' '    http_response_code(410); file_put_contents($dataDir . "/kyc_config.json", "");'
v95 "3o the Runbook naming the old key again: NO-GO at A22" "$NOGO22 A22e(names-the-old-key-1x) $W22" tabs/help/runbook.php \
  '?page=emergency_repair&amp;key=YOUR-KEY</p>' "?page=emergency_repair&amp;key=$OLDKEY95</p>"
v95 "3p the Starlink Suspensions box naming the old key again: NO-GO at A22" "$NOGO22 A22f(names-the-old-key-1x) $W22" tabs/admin/starlink_suspensions.php \
  'follow the Emergency Repair steps in the Runbook (System menu, Runbook).' "follow the Emergency Repair steps in the Runbook (System menu, Runbook) — key $OLDKEY95."
v95 "3q the recovery notes naming the old key again: NO-GO at A22" "$NOGO22 A22g(names-the-old-key-1x) $W22" EMERGENCY_RECOVERY.md \
  'public.php?page=emergency_repair&key=YOUR-KEY' "public.php?page=emergency_repair&key=$OLDKEY95"
# ── the variants (end) ──
M="$(mutant ctlpin95 "$DEPLOY95" 'SEC_CONTROL="$BASELINE_COMMIT"' 'SEC_CONTROL="$EXPECTED_PLUGIN_COMMIT"')"
fresh95; OUT="$(R95_MUTANT_OUT=1 run95 --answer NO --script "$M")"; rm -f "$M"
nogo95 "3r a copy whose control is the pin itself refuses: each check's control finds nothing wrong, so its pass on the pin proves nothing" \
  "NO-GO (A22): a check of the fixes finds nothing wrong in 5.18.94's code, its control: A22a(ok) A22b(ok) A22c(ok) A22d(ok) A22e(ok) A22f(ok) A22g(ok)" "$OUT"
M="$(mutant ctlpin95b "$DEPLOY95" 'SEC_CONTROL="$BASELINE_COMMIT"' 'SEC_CONTROL="$EXPECTED_PLUGIN_COMMIT"' '[ -z "$A22_CTL" ] || stop' 'true || stop')"
fresh95; OUT="$(R95_MUTANT_OUT=1 run95 --answer NO --script "$M")"; rm -f "$M"
check "$(went95 "$OUT")$(ctl95 "$OUT" A22)" "yesyes0" "3r' …a copy without that refusal reaches the question on checks that see nothing (caught — and no control names 5.18.94's defects)"
S="$(pinned_copy "v95nobad" "$(release_variant95 includes/routes.php "$VA_OLD" "$VA_NEW")" "$DEPLOY95")"
M="$(mutant nobad95 "$S" '[ -z "$A22_BAD" ] || stop' 'true || stop')"; rm -f "$S"
fresh95; OUT="$(R95_MUTANT_OUT=1 run95 --answer NO --script "$M")"; rm -f "$M"
check "$(went95 "$OUT")" "yesyes" "3a' a copy without the refusal reaches the question over variant 3a (caught)"
S="$(pinned_copy "v95nostrip" "$(release_variant95 includes/routes.php "$VB_OLD" "$VB_NEW")" "$DEPLOY95")"
M="$(mutant nostrip95 "$S" "  sec_has \"\$b\" 'str_replace(' && w=\"\$w strips\"" '  true')"
fresh95; OUT="$(R95_MUTANT_OUT=1 run95 --answer NO --script "$M")"; rm -f "$M"
nogo95 "3b' a copy blind to the one-pass strip is refused by the script itself over variant 3b: its control no longer shows 'strips' in 5.18.94's copy" \
  "NO-GO (A22): a check of the fixes does not find in 5.18.94's code, its control, exactly the defects it was written to find: A22a(no-dotdot-refusal no-backslash-refusal no-realpath-base no-realpath-file no-containment no-is_file)" "$OUT"
M="$(mutant nostrip95b "$S" "  sec_has \"\$b\" 'str_replace(' && w=\"\$w strips\"" '  true' '[ -z "$A22_CTLX" ] || stop' 'true || stop')"; rm -f "$S"
fresh95; OUT="$(R95_MUTANT_OUT=1 run95 --answer NO --script "$M")"; rm -f "$M"
check "$(went95 "$OUT")$(okline "$OUT" A22a 'in the pin')" "yesyesyes" "3b'' …and one without that refusal too reaches the question over variant 3b and calls the photo route fixed (caught)"
A22NOCTL_OLD='    a22v_p="$(sec_one "$a22c" pin)"; a22v_b="$(sec_one "$a22c" base)"; a22i="A22$(sec_id "$a22c")"'
A22NOCTL_NEW='    a22v_p="$(sec_one "$a22c" pin)"; a22v_b="defect"; a22i="A22$(sec_id "$a22c")"'
M="$(mutant a22noctl95 "$DEPLOY95" "$A22NOCTL_OLD" "$A22NOCTL_NEW")"
fresh95; OUT="$(R95_MUTANT_OUT=1 run95 --answer NO --script "$M")"; rm -f "$M"
nogo95 "3s a copy whose static checks never read 5.18.94's copy — every control a word that names no defect — is refused by the script itself: no control shows exactly the defects its check was written to find" \
  "NO-GO (A22): a check of the fixes does not find in 5.18.94's code, its control, exactly the defects it was written to find: A22a(defect) A22b(defect) A22c(defect) A22d(defect) A22e(defect) A22f(defect) A22g(defect)" "$OUT"
M="$(mutant a22noctl95b "$DEPLOY95" "$A22NOCTL_OLD" "$A22NOCTL_NEW" '[ -z "$A22_CTLX" ] || stop' 'true || stop')"
fresh95; OUT="$(R95_MUTANT_OUT=1 run95 --answer NO --script "$M")"; rm -f "$M"
check "$(went95 "$OUT")$(ctl95 "$OUT" A22)$(grep -cE '^  ok    A22[a-g] .* — in the pin; ' <<<"$OUT")" "yesyes07" \
  "3s' …a copy without that refusal reaches the question, every fix called ok with no control behind it (caught: no control names 5.18.94's defects)"
check "$(quiet95 | cut -d'|' -f1,3-)|$(sha256sum "$QREC" | cut -c1-16)" "$P94|$D95|$KEPT95|0|$QREC95" "…after section 3: 5.18.94 live, the data and the quarantine as before"

echo; echo "== 95-4. the deploy, as the operator runs it — DEPLOY typed; the database unchanged =="
LEDGER0="$(ledger95)"; DBSZ0="$(stat -c %s "$DATA/plugin.sqlite3")"
check "$(case "$LEDGER0" in [1-9]*:*:*) echo read ;; *) echo "unread($LEDGER0)" ;; esac)" "read" "control: the sandbox reads its own migration ledger (rows:last migration:checksum) before the deploy"
fresh95; OUT="$(run95 --answer DEPLOY)"; OUT95D="$OUT"
echo "  …    the deploy's own tally:$(grep -E '^  checks +[0-9]+ ok, ' <<<"$OUT" | sed -E 's/^  checks +/ /')"
check "$(fails "$OUT")$(has "$OUT" '5.18.95 (deploy): PASSED')$(live)" "0yes$P95" "the deploy PASSES: 0 failed, the container serves $P95"
for l in "ok    A0 the release delta is exactly 5.18.95's $N95 files — 4 added, $(( N95 - 4 )) changed, no migration" \
         "ok    A7 Domain B untouched" \
         "ok    A22a K1 the photo route" "ok    A22b K2 the repair page" "ok    A22c A2/D1 the EFRIS link" "ok    A22d D5 Splynx" \
         "ok    A22e K2 tabs/help/runbook.php prints no built-in key — in the pin; 5.18.94's copy, the control, fails the same check (names-the-old-key-1x)" \
         "ok    A22f K2 tabs/admin/starlink_suspensions.php prints no built-in key" "ok    A22g K2 EMERGENCY_RECOVERY.md prints no built-in key" \
         "ok    A14 the stray store is quarantined and the quarantine holds" \
         "ok    A12 on this server's own configuration the pin's gate stops the Starlink retry job" \
         "ok    A13 the pin's retry job" "ok    A15 under this server's own PHP" "ok    A16 under this server's own PHP" \
         "ok    A17 5.18.94's documents, unchanged by 5.18.95, under this server's own PHP" \
         "note  A17 pending request(s) made under INSTALLATION-TERMS-v1.0 (v1.0:1) keep INSTALLATION-TERMS-v1.0" \
         "ok    A18 none of ai_identity_line, wa_flyer_caption or email_badge_line says UCC" \
         "ok    A21 call recordings are deleted after 365 day(s)" \
         "note  A23a the repair key file ($KWHERE): it is ABSENT." "note  A23b the Splynx key is ABSENT" "note  A23c the pdf_link_secret is absent" \
         "  database        size=" \
         "GO — evidence recorded" "and without the repair key file, never copied" \
         "checking out $P95 (5.18.95, the release commit) for the documented deploy" \
         "ok    container serves $P95" \
         "ok    V3f " "ok    V3h " "ok    V3i " \
         "ok    V13 the public Terms and Privacy are 5.18.94's, unchanged by 5.18.95: version 1.2, the identity sentence, no \"UCC\"; app_legal_version answers 1.2 / 1.2, dated 16 October 2026" \
         "ok    R1 all $N95 files 5.18.95 changes are installed exactly as $P95 has them (4 new)" \
         "ok    R1 the installed manifest says 5.18.95" \
         "ok    R5 all" \
         "ok    R7 the database since this run's stage A: the migration ledger's last row the same" \
         "ok    R11 " "ok    R12 " "ok    R16 " "ok    R18 the installed retry job" \
         "note  R19 the quarantine holds — nothing at the old name, nothing a new store makes, the kept file unchanged — but master has not completed a run of the retry job since the copy" \
         "ok    R20 under this server's own PHP the installed test of master's lock passes" \
         "ok    R21 under this server's own PHP the installed test of the retired auto-pull passes" \
         "ok    R22 the installed documents are 5.18.94's, unchanged by 5.18.95" \
         "ok    R23a installed: K1 the photo route" "ok    R23b installed: K2 the repair page" "ok    R23c installed: A2/D1 the EFRIS link" "ok    R23d installed: D5 Splynx" \
         "ok    R23e installed: K2 tabs/help/runbook.php" "ok    R23f installed: K2 tabs/admin/starlink_suspensions.php" "ok    R23g installed: K2 EMERGENCY_RECOVERY.md" \
         "note  R24 the repair key file ($KWHERE): it is ABSENT. The emergency-repair page is SHUT — 403 for everyone —" \
         "note  R24b the pdf_link_secret is absent from the settings" \
         "what changed      four holes closed" \
         "  repair key        NOT in place ($KWHERE): it is ABSENT" \
         "The emergency-repair page is SHUT — 403 for everyone — until the file is in place. To write a new random key,"; do
  check "$(has "$OUT" "$l")" "yes" "4: ${l:0:110}"
done
check "$(ctl95 "$OUT" A22)$(ctl95 "$OUT" R23)" "77" "every fix read with its control, before (A22, the pin) and after (R23, the installed code): each control names 5.18.94's defects exactly"
check "$(notes "$OUT")$(has "$OUT" 'ok    V4 no fatal or parse error of')" "7yes" \
  "seven notes — A17's pending v1.0 request, A23a/b/c (no key file, no Splynx key, no pdf_link_secret), R19 not yet conclusive, R24 and R24b — and V4 ok"
check "$(okline "$OUT" A5 'mn=on/on')$(okline "$OUT" A9 "$SHAPE_ON")$(okline "$OUT" A11 'policy=on/on')$(okline "$OUT" R3 'migration 087' 'unchanged')" "yesyesyesyes" \
  "the pilot as the operator left it: A5, A9, A11 read it on, R3 finds 087's rows unchanged"
check "$(ledger95)|$(data94)|$( [ "$(stat -c %s "$DATA/plugin.sqlite3")" -ge "$DBSZ0" ] && echo not-smaller)|$(sed -n 's/^DB_BEFORE=size=[0-9]* ledger=//p' "$STATE95" | tail -1)|$(okline "$OUT" R7 "the database since this run's stage A: the migration ledger's last row the same ($LEDGER0; rows:last migration:checksum)")" \
      "$LEDGER0|$D95|not-smaller|$LEDGER0|yes" \
  "4 the database unchanged: the migration ledger as the sandbox reads it, before and after, every other table's rows the same, the file not smaller — and the state file and R7 name that same ledger"
SUM95="$(sed -n '/^== F. Summary ==$/,$p' <<<"$OUT")"
check "$(grep -cxF "      $EXP_AFTER" <<<"$SUM95")$(grep -cxF "      $EXP_QCHECK" <<<"$SUM95")$(grep -cxF "      $EXP_KEYCMD" <<<"$SUM95")$(grep -cxF "      $EXP_ROLLBACK" <<<"$SUM95")$(grep -cxF "      $EXP_PUTBACK" <<<"$SUM95")$(grep -cxF "      $EXP_DEPLOY" <<<"$OUT")" "111110" \
  "every later command printed exactly, on its own line — the --after-only, the quarantine's --check, the key file's, the rollback and the by-hand put-back — and the deploy's own never"
check "$(printed95 "$OUT")" "5|0|0" "…five commands in the summary, each with its own log wrapper on its line, and no line holding two commands"
check "$(grep -c 'deploy-5.18.95.sh --rollback' <<<"$OUT")$(rb_where95 "$OUT")$(awk '/^  5\.18\.95 \(deploy\): PASSED/ {p=1} p && index($0, "git checkout '"$P94"'") {print "after"; exit}' <<<"$OUT")" "1afterafter" \
  "the rollback command is printed once, on its own line, after the verdict — and the by-hand put-back after it"
check "$(has "$OUT" "$OLDKEY95")$(grep -cFf "$SB/spx.vals" <<<"$OUT")$(grep -cE -- "$LEAK_RX|$NUM_RX" <<<"$OUT")$(has "$OUT" 'UCC Authorised')" "no00no" \
  "the log names neither the old built-in key nor a Splynx value of 5.18.94's code, no setting's value, no Evolution address, key or instance, and no number"
check "$(installed95 "$P95")$(inst_ver)$(sha256sum "$PD/public.php" | cut -c1-64)" "05.18.95$(git -C "$REPO" show "$P95:$P/public.php" | sha256sum | cut -c1-64)" \
  "every file 5.18.94 → 5.18.95 changes is installed exactly as the pin has it; manifest 5.18.95"
check "$(data94)$(kept_state)$(oldname)$(sha256sum "$QREC" | cut -c1-16)$( [ -e "$KEYH" ] && echo key || echo nokey)" "$D95${KEPT95}0${QREC95}nokey" \
  "the deploy wrote no record or setting, left the quarantine as it was, and wrote no key file"
check "$(grep -c "^COPY_AT=$(stat -c %Y "$PD/public.php")\$" "$STATE95")$(grep -c '^Q_HOLDS_BEFORE=yes$' "$STATE95")$(grep -c '^STRAY_Q_FAILED=' "$STATE95")$(grep -cE '^DB_BEFORE=size=[0-9]+ ledger=[0-9]+:[^ ]+$' "$STATE95")$(has "$OUT" "  database        $(sed -n 's/^DB_BEFORE=//p' "$STATE95" | tail -1)")" "1101yes" \
  "the state file records the copy's time (as stamp_copy gave public.php), that the quarantine held before, the database as stage A read it; no failure"
check "$(host_code95)$(left_tmp95)$(evo_calls)" "0${TMP95}0" "the run left no copy of the code on the host or in /tmp, and never called Evolution"

echo; echo "== 95-4b. the key file's command, run exactly as the summary printed it =="
KLINE="$(printed "$OUT95D" '( set -C; umask 077; f=')"
check "$KLINE" "$EXP_KEYCMD" "4b the key command, as printed: openssl into the file, the plugin's user's, 0600, its own log"
KO1="$(run_cmd "$KLINE")"; KLOG="$(ls -1 "$OUT95"/keyfile-*.log 2>/dev/null)"
check "$(stat -c '%s %u:%g %a' "$KEYH" 2>/dev/null)|$(grep -cE '^[0-9a-f]{48}$' "$KEYH")|$(grep -c '' "$KEYH")" "49 1000:1000 600|1|1" \
  "…a key of 48 hexadecimal characters on one line, 49 bytes, the plugin's user's, 0600"
check "$KO1|$(printf '%s\n' "$KLOG" | grep -c .)|$(stat -c %a "$KLOG" 2>/dev/null)|$(cat "$KLOG" 2>/dev/null)" "repair key written: 49 bytes, owner 1000:1000, mode 600|1|600|repair key written: 49 bytes, owner 1000:1000, mode 600" \
  "…it shows only the file's size, owner and mode, and its own log, 0600, holds that line alone"
check "$(key_shown "$KO1")$(key_shown "$(cat "$KLOG")")" "nono" "…the key itself in neither"
secret_add_file "$KEYH"; KEY1="$(sha256sum "$KEYH" | cut -c1-64)"
sleep 1; KO2="$(run_cmd "$KLINE")"
check "$KO2|$(ls -1 "$KEYH".replaced-* 2>/dev/null | grep -c .)|$(sha256sum "$KEYH".replaced-* 2>/dev/null | cut -c1-64)|$( [ "$(sha256sum "$KEYH" | cut -c1-64)" != "$KEY1" ] && echo new)|$(stat -c '%s %u:%g %a' "$KEYH")" \
      "repair key written: 49 bytes, owner 1000:1000, mode 600|1|$KEY1|new|49 1000:1000 600" \
  "…run again, it moves the earlier key aside, byte for byte, and writes a new one — nothing removed"
secret_add_file "$KEYH"
check "$(key_shown "$KO2")$(data94)" "no$D95" "…the new key not shown either; the plugin's data as before"

echo; echo "== 95-5. --after-only, run exactly as the deploy's summary printed it =="
copy_back95() {   # the 5.18.95 copy N minutes ago: public.php's time (its stamp_copy), the recorded copy time, the start before it
  touch -d "-$1 minutes" "$PD/public.php"; local t; t="$(stat -c %Y "$PD/public.php")"
  sed -i -e "s/^COPY_AT=.*/COPY_AT=$t/" -e "s/^DEPLOYED_AT=.*/DEPLOYED_AT=$(date -u -d "@$(( t - 60 ))" +%Y-%m-%dT%H:%M:%SZ)/" "$STATE95"; }
copy_back95 30; COPY95="$(sed -n 's/^COPY_AT=//p' "$STATE95" | tail -1)"
set_sched $(( COPY95 + 300 )); base_log
ALINE="$(printed "$OUT95D" "cd $REPO && bash scripts/deploy-5.18.95.sh --after-only")"
check "$ALINE" "$EXP_AFTER" "5 the --after-only command, as the deploy's summary printed it"
fresh95; OUT="$(run_line '' "$ALINE")"; ALOG="$(ls -1 "$OUT95"/after-*.log 2>/dev/null)"
check "$(fails "$OUT")$(has "$OUT" '5.18.95 (after): PASSED')$(okline "$OUT" R19 'the quarantine holds: master ran the retry job at' "after the copy (at $(utc "$COPY95"))")" "0yesyes" \
  "5a master's run of the job since the copy: R19 conclusive — the quarantine holds — PASSED"
check "$(ctl95 "$OUT" R23)$(okline "$OUT" R24 "the repair key file is in place ($KWHERE: 32-64 bytes, one line of the key's form, readable by the plugin's PHP user)")$(okline "$OUT" R7 "the database since this run's stage A")$(okline "$OUT" R7 "the database since the deploy's stage A (its state file)")$(has "$OUT" '  repair key        in place (')$(grep -c '^      ( set -C; umask 077; f=' <<<"$OUT")" "7yesyesyesyes0" \
  "…R23 every fix installed, each control naming 5.18.94's defects; R24 the key file in place; R7 against this run's stage A and the deploy's own reading; the summary prints no key command now"
check "$(printed95 "$OUT")|$(grep -cxF "      $EXP_AFTER" <<<"$OUT")$(grep -cxF "      $EXP_QCHECK" <<<"$OUT")$(grep -cxF "      $EXP_ROLLBACK" <<<"$OUT")$(grep -cxF "      $EXP_PUTBACK" <<<"$OUT")|$(rb_where95 "$OUT")" "4|0|0|1111|after" \
  "…its summary prints four commands, each alone with its own log wrapper — the --after-only again, the quarantine's --check, the rollback and the put-back — the rollback once, after the verdict"
check "$(printf '%s\n' "$ALOG" | grep -c .)|$(stat -c %a "$ALOG" 2>/dev/null)|$(grep -c '5.18.95 (after): PASSED' "$ALOG" 2>/dev/null)|$(key_shown "$OUT")$(key_shown "$(cat "$ALOG" 2>/dev/null)")" "1|600|1|nono" \
  "…its own log, 0600, holds the run; the key in neither"
# The printed commands, weakened: each copy run as the --after-only it is, each caught by the assertion the script passes.
M="$(mutant fnowrap95 "$DEPLOY95" 'AFTER_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --after-only $(LOGW after)"' 'AFTER_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --after-only"')"
base_log; OUT="$(R95_MUTANT_OUT=1 run95 --script "$M" --after-only)"; rm -f "$M"
check "$(printed95 "$OUT")|$(grep -cxF "      $EXP_AFTER" <<<"$OUT")" "4|1|0|0" \
  "5d' a copy that prints the --after-only without its log wrapper is caught: one command of four without it, and not the line the runbook gives"
FRB_A='  echo "      $AFTER_CMD"'
FRB_B="  echo \"                    then the quarantine's own --check, its own command, alone:\""
M="$(mutant frbblock95 "$DEPLOY95" "$FRB_A
$FRB_B" "$FRB_A
  echo \"      \$ROLLBACK_CMD\"
$FRB_B")"
base_log; OUT="$(R95_MUTANT_OUT=1 run95 --script "$M" --after-only)"; rm -f "$M"
check "$(rb_where95 "$OUT")|$(grep -c 'deploy-5.18.95.sh --rollback' <<<"$OUT")" "before,after|2" \
  "5e' a copy that prints the rollback inside the --after-only's block, before the verdict, is caught: printed twice, the first before the verdict"
R23NOCTL_OLD='  r23v_i="$(sec_one "$r23c" inst)"; r23v_b="$(sec_one "$r23c" base)"; r23i="R23$(sec_id "$r23c")"'
R23NOCTL_NEW='  r23v_i="$(sec_one "$r23c" inst)"; r23v_b="defect"; r23i="R23$(sec_id "$r23c")"'
M="$(mutant r23noctl95 "$DEPLOY95" "$R23NOCTL_OLD" "$R23NOCTL_NEW")"
base_log; OUT="$(R95_MUTANT_OUT=1 run95 --script "$M" --after-only)"; rm -f "$M"
check "$(fails "$OUT")|$(for x in a b c d e f g; do failline "$OUT" "R23$x" "the check's control, 5.18.94's copy, does not show exactly the defects it was written to find (defect)"; done | grep -c yes)|$(for x in a b c d e f g; do okline "$OUT" "R23$x" 'installed:'; done | grep -c yes)" "7|7|0" \
  "5f a copy whose R23 never reads 5.18.94's copy FAILS each of the seven checks itself: no control shows exactly the defects its check was written to find"
M="$(mutant r23noctl95b "$DEPLOY95" "$R23NOCTL_OLD" "$R23NOCTL_NEW" 'if [ "$r23v_b" != "$(sec_ctl_want "$r23c")" ]; then bad' 'if false; then bad')"
base_log; OUT="$(R95_MUTANT_OUT=1 run95 --script "$M" --after-only)"; rm -f "$M"
check "$(ctl95 "$OUT" R23)|$(fails "$OUT")|$(for x in a b c d e f g; do okline "$OUT" "R23$x" 'installed:'; done | grep -c yes)" "0|0|7" \
  "5f' …a copy without that failure passes every check — and is caught: no control names 5.18.94's defects"
touch "$SB/web/.add_ledger"; base_log; OUT="$(run95 --after-only)"
check "$(failline "$OUT" R7 'the migration ledger CHANGED' "since this run's stage A")$(failline "$OUT" R7 'the migration ledger CHANGED' "since the deploy's stage A (its state file)")$(has "$OUT" '5.18.95 (after): ')$( [ -e "$SB/web/.add_ledger" ] && echo unspent || echo spent)$(sq "SELECT COUNT(*) FROM _migrations WHERE filename = '999_rehearsal_planted.sql'")" "yesyesyesspent1" \
  "5b a row written in the migration ledger during the run, after stage A read it: R7 FAILS against both readings"
sqx "DELETE FROM _migrations WHERE filename = '999_rehearsal_planted.sql'" >/dev/null
M="$(mutant r7ledger95 "$DEPLOY95" '  if [ "$bl" != "$nl" ]; then bad "$1 the migration ledger CHANGED' '  if false; then bad "$1 the migration ledger CHANGED')"
touch "$SB/web/.add_ledger"; base_log; OUT="$(R95_MUTANT_OUT=1 run95 --script "$M" --after-only)"; rm -f "$M"
check "$(okline "$OUT" R7 "the database since this run's stage A")$(failline "$OUT" R7 'the migration ledger CHANGED')$(sq "SELECT COUNT(*) FROM _migrations WHERE filename = '999_rehearsal_planted.sql'")" "yesno1" \
  "5b' a copy blind to the ledger passes R7 over a row written during its run (caught)"
sqx "DELETE FROM _migrations WHERE filename = '999_rehearsal_planted.sql'" >/dev/null
check "$(sq "SELECT COUNT(*) FROM _migrations WHERE filename = '999_rehearsal_planted.sql'")$(ledger95)" "0$LEDGER0" "…the planted row taken out again: the ledger as before the deploy"
cp -p "$PD/public.php" "$SB/public95.kept"
edit_installed public.php "    \$storedKey = is_string(\$storedKey) ? trim(\$storedKey) : '';" "    \$storedKey = is_string(\$storedKey) ? trim(\$storedKey) : '$OLDKEY95';"
base_log; OUT="$(run95 --after-only)"
check "$(failline "$OUT" R23b 'the installed code does not carry the fix (fallback-key old-key-named-2x)')$(okline "$OUT" R23a 'installed:')$(has "$OUT" "$OLDKEY95")" "yesyesno" \
  "5c the built-in key put back into the INSTALLED public.php: R23b FAILS, naming the defect in fixed words; R23a still ok; the key's name never printed"
M="$(mutant r23pin95 "$DEPLOY95" '    inst) cat -- "$DEST/$2" 2>/dev/null ;;' '    inst) git -C "$REPO" show "$EXPECTED_PLUGIN_COMMIT:$PLUGIN/$2" 2>/dev/null ;;')"
base_log; OUT="$(R95_MUTANT_OUT=1 run95 --script "$M" --after-only)"; rm -f "$M"
check "$(okline "$OUT" R23b 'installed:')" "yes" "5c' a copy that reads the pin where it should read the installed file passes R23b over the edit (caught)"
cat "$SB/public95.kept" > "$PD/public.php"; touch -r "$SB/public95.kept" "$PD/public.php"
check "$(installed95 "$P95")$(stat -c '%u:%g' "$PD/public.php")$(stat -c %Y "$PD/public.php")" "01000:1000$(stat -c %Y "$SB/public95.kept")" "…the installed file put back: its bytes, owner and time"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')$(has "$OUT" 'note  K2 the container serves')" "yesyes" \
  "5g the quarantine's own --check, as the runbook orders after this: PASSED with 5.18.95 live"
QREC95="$(sha256sum "$QREC" | cut -c1-16)"; KEPT95="$(kept_state)"

echo; echo "== 95-6. the rollback, run exactly as the deploy's summary printed it — ROLLBACK typed — and deployed again =="
RLINE="$(printed "$OUT95D" "cd $REPO && bash scripts/deploy-5.18.95.sh --rollback")"
check "$RLINE" "$EXP_ROLLBACK" "6 the rollback command, as the deploy's summary printed it after its verdict"
S95SHA="$(sha256sum "$STATE95" | cut -c1-64)"
fresh95; OUT="$(run_line ROLLBACK "$RLINE")"; RLOG="$(ls -1 "$OUT95"/rollback-*.log 2>/dev/null)"
check "$(fails "$OUT")$(has "$OUT" '5.18.95 (rollback): PASSED')$(live)" "0yes$P94" "6a the rollback PASSES: 5.18.94 serving again"
for l in "ok    container serves $P94 (5.18.94)" \
         "ok    RB the installed manifest says 5.18.94" \
         "ok    RB all 17 files 5.18.95 changed are 5.18.94's again, byte for byte (sha256); the 4 tests it added stay on disk, reached by nothing" \
         "ok    RB the four fixes are gone with the rollback, as 5.18.94 has them" \
         "ok    RB the database across the rollback: the migration ledger's last row the same ($LEDGER0;" \
         "ok    RB the repair key file is in place ($KWHERE: 32-64 bytes, readable by the plugin's PHP user) — 5.18.94's repair page takes its key from it, so the old built-in key does not open it" \
         "ok    RB the documents are still 5.18.94's" \
         "ok    RB every authorisation record still matches its terms under 5.18.94's code" \
         "ok    RB the quarantine still holds, as its record says" \
         "ok    V13 the public Terms and Privacy are 5.18.94's, after the rollback as before it" \
         "note  RB the code is 5.18.94 again. The rollback restores code only. The files 5.18.95 added stay on disk" \
         "  serving           $P94  (plugin 5.18.94)" \
         "  what it means     5.18.94's four holes are open again" \
         "  repair key        in place ("; do
  check "$(has "$OUT" "$l")" "yes" "6a: ${l:0:110}"
done
check "$(grep -cxF "      $EXP_QCHECK" <<<"$OUT")$(grep -cxF "      $EXP_DEPLOY" <<<"$OUT")$(grep -c 'deploy-5.18.95.sh --rollback' <<<"$OUT")$(grep -c '^      ( set -C; umask 077; f=' <<<"$OUT")|$(printed95 "$OUT")" "1100|2|0|0" \
  "…its summary prints the quarantine's --check and the deploy again, each alone with its own log — never a rollback, and no key command (the key is in place)"
check "$(installed95 "$P94")$(inst_ver)$(for f in $ADDED95; do [ -f "$PD/$f" ] && echo kept; done | grep -c kept)" "05.18.944" "every changed file as 5.18.94 has it, manifest 5.18.94; the four added tests stay on disk"
check "$(printf '%s\n' "$RLOG" | grep -c .)|$(stat -c %a "$RLOG" 2>/dev/null)|$(grep -c '5.18.95 (rollback): PASSED' "$RLOG" 2>/dev/null)|$(sha256sum "$STATE95" | cut -c1-64)|$(ls "$OUT95"/rollback-state-*.env 2>/dev/null | grep -c .)" "1|600|1|$S95SHA|1" \
  "…its own log, 0600; the deploy's state file untouched — the rollback's record is a file of its own"
check "$(data94)$(ledger95)$(kept_state)$(oldname)$(sha256sum "$QREC" | cut -c1-16)$(host_code95)$(left_tmp95)$(key_shown "$OUT")" "$D95$LEDGER0${KEPT95}0${QREC95}0${TMP95}no" \
  "the rollback changed no data, not the ledger and not the quarantine, left no copy of the code, and printed no key"
OUT="$(qrun -- --check)"
check "$(has "$OUT" 'stray-store quarantine (check): PASSED.')" "yes" "…the quarantine's own --check after the rollback, as RB asks: PASSED"
QREC95="$(sha256sum "$QREC" | cut -c1-16)"; KEPT95="$(kept_state)"
# Deployed again over the rollback: the key in place now, and the backup without it.
fresh95; OUT="$(run95 --answer DEPLOY)"; BKD="$(ls -d "$OUT95"/backup-* 2>/dev/null | tail -1)"
check "$(fails "$OUT")$(has "$OUT" '5.18.95 (deploy): PASSED')$(live)" "0yes$P95" "6b deployed again over the rollback: PASSED, $P95 serving"
check "$(okline "$OUT" A23a 'the repair key file is in place' "$KWHERE")$(okline "$OUT" R24 'the repair key file is in place')$(okline "$OUT" A14 'the quarantine holds')$(has "$OUT" '  repair key        in place (')$(grep -c '^      ( set -C; umask 077; f=' <<<"$OUT")$(key_shown "$OUT")" "yesyesyesyes0no" \
  "…A23a and R24 find the key file in place, A14 the --check after the rollback; the summary prints no key command; the key in no line"
check "$(tar -tzf "$BKD/data.tar.gz" 2>/dev/null | grep -c 'emergency_repair_key')$(tar -tzf "$BKD/data.tar.gz" 2>/dev/null | grep -c '/kyc_config.json$')$(ls -1 "$KEYH" "$KEYH".replaced-* 2>/dev/null | grep -c .)" "012" \
  "…its backup holds neither the key file nor the key moved aside — both on disk — and holds the data folder's settings file (the control)"
# A rollback whose copy leaves a file 5.18.95 changed behind — 5.18.95's includes/routes.php, put back by the stand-in at
# the first request once 5.18.94's manifest is installed, so after the copy and before RB — with the key file moved away:
# RB FAILS on both of its checks of the code, and says the key file with its command.
git -C "$REPO" show "$P95:$P/includes/routes.php" > "$SB/routes95.left"; chmod 644 "$SB/routes95.left"
mv "$KEYH" "$SB/key95.aside"
printf '%s\n' "$SB/routes95.left" > "$SB/web/.left95"
fresh95; OUT="$(run95 --answer ROLLBACK --rollback)"
check "$(live)|$( [ -e "$SB/web/.left95" ] && echo unspent || echo spent)|$(cmp -s "$PD/includes/routes.php" "$SB/routes95.left" && echo left)|$(fails "$OUT")|$(has "$OUT" '5.18.95 (rollback): 2 FAILED')" "$P94|spent|left|2|yes" \
  "6c a rollback whose copy leaves 5.18.95's includes/routes.php behind: 5.18.94 serving, the file 5.18.95's, two checks FAIL"
check "$(failline "$OUT" RB "installed files that are not 5.18.94's after the rollback: includes/routes.php")$(failline "$OUT" RB "the installed code is not 5.18.94's where the four fixes were" "a(installed: ok; 5.18.94's: strips")" "yesyes" \
  "…RB FAILS by sha256, naming the file, and finds the photo route's fix still installed where 5.18.94's defect should be"
check "$(noteline "$OUT" RB "the repair key file ($KWHERE): it is ABSENT. 5.18.94's repair page, back now, opens before any login with the old built-in key when the file is absent")$(has "$OUT" "  repair key        NOT in place ($KWHERE): it is ABSENT")$(has "$OUT" "5.18.94's repair page opens before any login while the file is absent or empty. To write a new random key,")$(grep -cxF "      $EXP_KEYCMD" <<<"$OUT")|$(printed95 "$OUT")" "yesyesyes1|3|0|0" \
  "…and with no key file, RB says what 5.18.94's page does then, and the summary prints the key command, alone, with its own log"
git -C "$REPO" show "$P94:$P/includes/routes.php" > "$PD/includes/routes.php"; mv "$SB/key95.aside" "$KEYH"
check "$(installed95 "$P94")$(stat -c '%s %u:%g %a' "$KEYH")$(stat -c '%u:%g' "$PD/includes/routes.php")" "049 1000:1000 6001000:1000" "…routes.php put back as 5.18.94's and the key file with it: every file 5.18.94's again"
fresh95; OUT="$(run95 --answer DEPLOY)"
check "$(fails "$OUT")$(has "$OUT" '5.18.95 (deploy): PASSED')$(live)" "0yes$P95" "6d deployed again: PASSED, $P95 serving"
printf '%s\n' "$SB/routes95.left" > "$SB/web/.left95"
M="$(mutant rbself95 "$DEPLOY95" '  rbv_i="$(sec_one "$rbc" inst)"; rbv_b="$(sec_one "$rbc" base)"' '  rbv_i="$(sec_one "$rbc" base)"; rbv_b="$(sec_one "$rbc" base)"')"
fresh95; OUT="$(R95_MUTANT_OUT=1 run95 --answer ROLLBACK --script "$M" --rollback)"; rm -f "$M"
check "$(live)|$( [ -e "$SB/web/.left95" ] && echo unspent || echo spent)|$(okline "$OUT" RB 'the four fixes are gone with the rollback')$(failline "$OUT" RB "installed files that are not 5.18.94's after the rollback: includes/routes.php")" "$P94|spent|yesyes" \
  "6e' a copy that compares the control with itself calls the fixes gone over the same file left behind (caught) — the sha256 check still sees it"
git -C "$REPO" show "$P94:$P/includes/routes.php" > "$PD/includes/routes.php"
check "$(installed95 "$P94")" "0" "…routes.php put back as 5.18.94's again"
fresh95; OUT="$(run95 --answer DEPLOY)"
check "$(fails "$OUT")$(has "$OUT" '5.18.95 (deploy): PASSED')$(live)$(okline "$OUT" A23a 'the repair key file is in place')$(ctl95 "$OUT" A22)$(ctl95 "$OUT" R23)" "0yes${P95}yes77" "6f deployed again: PASSED, $P95 serving, the key in place, every fix with its control"
check "$(installed95 "$P95")$(inst_ver)$(data94)$(ledger95)$(kept_state)$(oldname)" "05.18.95$D95$LEDGER0${KEPT95}0" "…every file as the pin has it; the data, the ledger and the quarantine as before"

echo; echo "== 95-7. what the rehearsal left behind, and what it never reached =="
fresh95   # the last logs into the sweep's folder
check "$(evo_calls)" "0" "Evolution never called — not by the quarantine, the deploys, their checks or the installed code they ran"
check "$(ls -a "$REPO/scripts" | grep -E '^\..+\.sh$' | paste -sd, -)" ".qpin.sh" "no weakened or re-pinned copy is left in the clone's scripts/ — the quarantine's one pinned copy aside"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )" "" "every weakened copy, release variant and planted edit found its anchor exactly once — none was skipped"
M="$(mutant anchorprobe95 "$DEPLOY95" 'NO SUCH ANCHOR IN THE SCRIPT' 'x' 2>/dev/null)"
check "$( [ -f "$SB/anchor-missing" ] && tr '\n' ' ' < "$SB/anchor-missing" )$(test -e "$M" && echo made || echo none)" "mutant:anchorprobe95 none" \
  "control on the control: a weakened copy whose anchor is missing is recorded as such, and no copy is made"
rm -f "$SB/anchor-missing"
check "$( [ -f "$SB/bad-runs" ] && tr '\n' ' ' < "$SB/bad-runs" )" "" "every run took the options it was given — none answered 'unknown option'"
OUT="$(run95 --after-only --rehearsal-unknown-option)"
check "$(has "$OUT" 'unknown option: --rehearsal-unknown-option')$( [ -s "$SB/bad-runs" ] && echo recorded)" "yesrecorded" "control on the control: a run given an option the script does not know is recorded as such"
rm -f "$SB/bad-runs"
check "$(ls -A "$SB/web" | grep -c '^\.add_ledger$\|^\.left95$')" "0" "every trigger the rehearsal planted was spent"
check "$( [ "$(sweep_files95 | grep -c .)" -ge 30 ] && echo many)$( [ "$(grep -c . "$SEC95")" -ge 15 ] && echo many)" "manymany" \
  "the sweep has material: every run's output, every printed command's log, the state files — and a list of the keys, planted values and 5.18.94's Splynx values"
check "$(secret_hits95)$(oldkey_hits95)" "00" \
  "7 no output, log or state file of any run holds a repair key, a planted Splynx or pdf value, one of 5.18.94's Splynx values, or the old key's name (the weakened copies that print one on purpose aside)"
printf 'planted %s\nand %s\n' "$RH_SPX_KEY" "$OLDKEY95" > "$R95/zz-sweep-control.txt"
check "$(secret_hits95)$(oldkey_hits95)" "11" "control on the sweep: a file holding a planted value and the old key's name is found by both"
rm -f "$R95/zz-sweep-control.txt"
check "$(left_tmp95)$(host_code95)" "${TMP95}0" "no copy of the code, throwaway layout or test folder is left in /tmp or on the host"
check "$(grep -c 'Fatal\|Parse error' "$SB/web.log")" "0" "the stand-in served every request without a PHP fatal"
check "$(checkout_state)" "$CHECKOUT0" "this checkout is as the rehearsal found it: same commit, same tracked files"

echo; echo "rehearsal: $PASS passed, $FAILN failed ($(cat "$SB/runs") runs of the deploy scripts, $(cat "$SB/qruns" 2>/dev/null || echo 0) of the quarantine)"
[ "$FAILN" = "0" ]
