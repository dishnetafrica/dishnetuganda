echo; echo "== 107-infra. 5.18.107's own: the script in the clone, its folders and runners, the stand-in web server on the clock, W.8, the hooks, its own numbers =="
DEPLOY107="$REPO/$F107"
AM0_107="$(grep -c . "$SB/anchor-missing" 2>/dev/null)"; AM0_107="${AM0_107:-0}"; WF0_107="$(grep -c . "$R104/wait104.fail" 2>/dev/null)"; WF0_107="${WF0_107:-0}"
BR0_107="$(grep -c '^run107-' "$SB/bad-runs" 2>/dev/null)"; BR0_107="${BR0_107:-0}"
ST0_107="$(git -C "$R" status --porcelain 2>/dev/null | sha256sum | cut -c1-16)"   # this checkout as the rehearsal found it
# The script, both wrappers and 5.18.106's script into the clone as committed (a development runner's restored clone included).
git -C "$R" ls-files --error-unmatch "$F107" >/dev/null 2>&1 || { echo "refusing: $F107 is not committed — commit it, then rehearse"; exit 2; }
git -C "$R" diff --quiet HEAD -- "$F107" || { echo "refusing: $F107 differs from the committed one — commit it, then rehearse"; exit 2; }
cp "$R/$F107" "$DEPLOY107"
for f107 in scripts/sales-followup.sh scripts/sales-followup-report.sh; do cp "$R/$f107" "$REPO/$f107"; done
cp "$R/$F106_107" "$REPO/$F106_107"
sv107() { sed -nE "s/^$2=\"([^\"]*)\".*/\1/p; t; s/^$2=([^ \";]+).*/\1/p" "${1:-$DEPLOY107}" | head -1; }   # sv107 FILE NAME — a value the script states
P107="$(sv107 "" EXPECTED_PLUGIN_COMMIT)"; P107F="$(sv107 "" EXPECTED_PLUGIN_COMMIT_FULL)"; V107="$(sv107 "" EXPECTED_VERSION)"
B107="$(sv107 "" BASELINE_COMMIT)"; B107F="$(sv107 "" BASELINE_COMMIT_FULL)"; BV107="$(sv107 "" BASELINE_VERSION)"
REL107="$(sv107 "" RELEASE_BRANCH)"
git -C "$REPO" fetch -q origin "refs/heads/$REL107:refs/remotes/origin/$REL107" 2>/dev/null || true
if ! git -C "$REPO" cat-file -e "$P107F^{commit}" 2>/dev/null; then
  # the release branch not pushed: the checkout's own branch that holds the pin (read from Git, never named here)
  for b107 in $(git -C "$R" for-each-ref --contains "$P107F" --format='%(refname:short)' refs/heads 2>/dev/null | head -3); do
    git -C "$REPO" fetch -q origin "refs/heads/$b107:refs/remotes/origin/$b107" 2>/dev/null && break
  done
fi
git -C "$REPO" cat-file -e "$P107F^{commit}" 2>/dev/null || { echo "refusing: the clone does not hold $V107's pin $P107F"; exit 2; }
# 5.18.107's baseline is the chain's P106 — never B106, which is 5.18.105 in R6's shell (v3 A9, review 2 TC1/FM2)
check "$P107|$( [ "$(git -C "$REPO" rev-parse "$P107^{commit}")" = "$P107F" ] && echo full)|$B107|$( [ "$B107F" = "$P106F" ] && echo base106)|$BV107|$( [ "$(git -C "$REPO" rev-parse "$P107F^")" = "$B107F" ] && echo on106)|$(inst_ver)" \
      "${P107F:0:${#P107}}|full|${P106F:0:${#B107}}|base106|$V106|on106|$V106" \
  "107-infra a the committed script pinned to $P107 (its full form the clone's), one commit on $B107 — 5.18.106's own pin, read from both scripts — $V107 after $BV107; $(inst_ver) serving (106-7i6's --redeploy)"
OUT107="$SB/out107"; mkdir -p "$OUT107"; STATE107="$OUT107/state-$V107.env"; LKF107="$OUT107/lk-key-$V107"
R107="$SB/r107"; mkdir -p "$R107/logs" "$R107/mutants" "$R107/sheets" "$R107/exec" "$R107/scan" "$R107/vault" "$R107/tok" "$R107/ucrm" "$R107/evo" "$R107/case" "$R107/rc" "$R107/kept" "$R107/pre" "$R107/boot" "$R107/snap"
H107="$SB/rh107"; mkdir -p "$H107/v" "$H107/ref"; cp "$H107_DIR/rh107q.py" "$H107_DIR/rh107-seed.php" "$H107/"; chmod 755 "$H107"; chmod 644 "$H107"/rh107*
chown 1000:1000 "$H107/v" "$R107/boot"; chmod 700 "$H107/v"
LOGW107() { printf '2>&1 | ( umask 077; exec tee %s/%s-%s.log )' "$OUT107" "$1" "$DATE_TS98"; }   # the script's LOGW, as it prints it
EXP_AFTER107="cd $REPO && bash $F107 --after-only $(LOGW107 after)"
EXP_ROLLBACK107="cd $REPO && bash $F107 --rollback $(LOGW107 rollback)"
EXP_REDEPLOY107="cd $REPO && bash $F107 --redeploy $(LOGW107 redeploy)"
q107() { python3 -I "$H107/rh107q.py" "$@"; }        # this rehearsal's own readers (rh107q.py)
kv107() { q105 kv "$1" "$2"; }                        # one key=value field of a line, by the chain's reader
# The pin's ladder, read by the RELEASE's own code from Git (a copy in this rehearsal's own folder), set against 5.18.106's:
# 5.18.107 changes the ladder (SalesFollowupLadder: W.1's key and its burst-drain step, root docs/76 §W.1), so its keys are
# read here, never assumed equal.
git -C "$REPO" archive "$P107F" "$P/lib" "$P/tools" | tar -xf - -C "$H107/ref"; chown -R 1000:1000 "$H107/ref"
LAD107="$(ref105 ladder "$H107/ref/$P")"
ALLCSV107="$(jf105 "$LAD107" all)"; SETCSV107="$(jf105 "$LAD107" settings)"; STEPMAP107="$(jf105 "$LAD107" stepmap)"
check "$( [ -n "$ALLCSV107" ] && echo read)|$( tr ',' '\n' <<<"$ALLCSV107" | grep -cx 'ai_burst_merge_enabled')|$(git -C "$REPO" diff --quiet "$B107F" "$P107F" -- "$P/lib/SalesFollowupLadder.php" && echo same || echo changed)" \
      "read|1|changed" \
  "107-infra b the pin's ladder read by its own code from Git: allKeys() ($(tr ',' '\n' <<<"$ALLCSV107" | grep -c .) keys) holds ai_burst_merge_enabled (W.1); SalesFollowupLadder.php is among the files $V107 changes"
CM107="$(sv107 "" COPY_MARK)"
ADDED107="$(sed -n 's/^EXPECTED_ADDED="\(.*\)"$/\1/p' "$DEPLOY107")"; NA107=$(wc -w <<<"$ADDED107")
CHANGED107="$(sed -n 's/^EXPECTED_CHANGED="\(.*\)"$/\1/p' "$DEPLOY107")"; NC107=$(wc -w <<<"$CHANGED107")
check "$NA107|$NC107|$(git -C "$REPO" diff --name-only --diff-filter=A "$B107F" "$P107F" -- "$P" | grep -c .)|$(git -C "$REPO" diff --name-only --diff-filter=M "$B107F" "$P107F" -- "$P" | grep -c .)|$CM107" \
      "$(git -C "$REPO" diff --name-only --diff-filter=A "$B107F" "$P107F" -- "$P" | grep -c .)|$(git -C "$REPO" diff --name-only --diff-filter=M "$B107F" "$P107F" -- "$P" | grep -c .)|$(git -C "$REPO" diff --name-only --diff-filter=A "$B107F" "$P107F" -- "$P" | grep -c .)|$(git -C "$REPO" diff --name-only --diff-filter=M "$B107F" "$P107F" -- "$P" | grep -c .)|cron/followup_send.php" \
  "107-infra c the script's lists are Git's delta: $NA107 added, $NC107 changed (root docs/76 §T.2 and §T.15); COPY_MARK cron/followup_send.php"
left107() { local f n=0; for f in $ADDED107; do [ -e "$PD/$f" ] && n=$((n+1)); done; echo "$n"; }   # 5.18.107's added files on disk
installed107() {   # installed107 COMMIT — the files $BV107 → $V107 touches outside tests/ that are NOT installed as COMMIT has them
  local f rel bad=0
  for f in $(git -C "$REPO" diff --name-only --diff-filter=AM "$B107F" "$P107F" -- "$P" | grep -v "^$P/tests/"); do
    rel="${f#$P/}"
    if git -C "$REPO" cat-file -e "$1:$f" 2>/dev/null; then
      [ "$(git -C "$REPO" show "$1:$f" | sha256sum | cut -c1-64)" = "$(sha256sum "$PD/$rel" 2>/dev/null | cut -c1-64)" ] || bad=$((bad+1))
    fi
  done
  echo "$bad"
}
# The --after-only lines of each step, read from the script as 5.18.106's rehearsal reads its own (onst104's reader).
ONP107="QF-ON0:ack QF-ON3:ledger $(grep -oE 'qf_on_one "\$(sub|1)" [A-Z]+-ON[0-9] [a-z-]+ ' "$DEPLOY107" | awk '{print $3 ":" $4}' | grep -v '^QF-ON0:' | sort -u | paste -sd' ' -)"
check "$( [ "$(wc -w <<<"$ONP107")" -ge "$(wc -w <<<"$ONP106")" ] && echo some)|$(for p in $ONP106; do grep -qwF -- "$p" <<<"$ONP107" || echo "lost:$p"; done | paste -sd, -)" "some|" \
  "107-infra d the --after-only's step pairs read from the script ($(wc -w <<<"$ONP107") pairs): every one of 5.18.106's kept"
onall107() { local p; for p in $ONP107; do printf '%s=%s ' "$p" "$(onst104 "$1" "${p%%:*}" "${p#*:}")"; done | sed 's/ $//'; }
expect107() {   # expect107 STEP=STATE… — the line onall107 must read: every pair off but the steps named
  local p s out="" e
  for p in $ONP107; do e=off; for s in "$@"; do case "$s" in *=*) [ "${p#*:}" = "${s%%=*}" ] && e="${s#*=}" ;; esac; done; out="$out $p=$e"; done
  echo "${out# }"
}
L2ON107="$L2ON106"   # steps 1–6 on: production's state before 5.18.107 (v2 A15) — the chase in claim mode

# ── The runs. run107 [--answer WORD] [--hold FILE] [--script FILE] [--env K=V]… OPTION… — run106's form on this release's
# folders: the script's exit status kept ($R107/rc/run-<n>.rc, read by rc107 — PASSED 0, FAILED or NO-GO 1, NOT YET 3), the
# run's state before it (backups, state files, LK key, read by quiet107), and RH106_TOUCH set, so the date folder's touch
# gives stamp_copy the clock's time (5.18.106's A1, which 5.18.107's copies share). ──
keysum107() { if [ -e "$LKF107" ]; then sha256sum < "$LKF107" | cut -c1-16; else echo nokey; fi; }
backups107() { ls -d "$OUT107"/backup-* 2>/dev/null | wc -l | tr -d ' '; }
states107() { ls -1 "$OUT107/state-$V107.env"* "$OUT107"/rollback-state-* 2>/dev/null | wc -l | tr -d ' '; }
run107() {
  local answer="" script="$DEPLOY107" extra=() hold="" n tag o cmd e0 c0
  while :; do case "${1:-}" in --answer) answer="$2"; shift 2 ;; --hold) hold="$2"; shift 2 ;; --script) script="$2"; shift 2 ;; --env) extra+=("$2"); shift 2 ;; *) break ;; esac; done
  : > "$SB/docker.log"; : > "$U99/requests.log"; : > "$U99/limits.log"
  n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"; tag="$(printf '%03d' "$n")"; echo "$tag" > "$R107/rc/last"
  printf '%s|%s|%s\n' "$(backups107)" "$(states107)" "$(keysum107)" > "$R107/rc/run-$tag.pre"
  date -u +%s > "$R107/rc/run-$tag.t0"
  vt_host > "$R107/vault/run-$tag.before"; : > "$R107/exec/run-$tag.rec"; : > "$R107/scan/run-$tag.hits"; : > "$R107/tok/run-$tag.tok"; e0="$(evo_calls)"; c0="$(grep -c . "$R104/logs/cron104.runs" 2>/dev/null)"
  cmd="$(printf '%q ' env PATH="$SB/datebin:$SB/bin:$PATH" FAKE_TODAY="$AFTER94" DNB_OUT="$OUT107" DNB_RECORDS="$RECS" DNB_WEBSITE="$WEB_OK" GUARD_SECONDS=3 DN_DATA_DIR="$DATA" \
         DNB_SALES_LOG_DIR="$SALES104" DNB_REPORT_DIR="$REPORT104" RH106_TOUCH=1 \
         FAKE_EXEC_LOG="$R107/exec/run-$tag.rec" FAKE_TMP_SCAN="$SCAN96" FAKE_TMP_HITS="$R107/scan/run-$tag.hits" FAKE_TOK_HITS="$R107/tok/run-$tag.tok" \
         ${extra[@]+"${extra[@]}"} bash "$script" --plugin-base "$PLUGIN_BASE" "$@")"
  if [ -n "$hold" ]; then
    o="$( { i=0; while [ ! -e "$hold" ] && [ "$i" -lt 6000 ]; do sleep 0.05; i=$((i+1)); done; [ -e "$hold" ] || echo "run107-hold:$tag" >> "$R104/wait104.fail"; printf '%s\n' "$answer"; } \
          | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tee "${R107_STREAM:-/dev/null}" | tr -d '\r'; echo "${PIPESTATUS[1]}" > "$R107/rc/run-$tag.rc")"
  else o="$(printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tee "${R107_STREAM:-/dev/null}" | tr -d '\r'; echo "${PIPESTATUS[1]}" > "$R107/rc/run-$tag.rc")"; fi
  date -u +%s > "$R107/rc/run-$tag.t1"
  echo "$(( $(evo_calls) - e0 )) $( [ "$(n103 "$(grep -c . "$R104/logs/cron104.runs" 2>/dev/null)")" -gt "$(n103 "$c0")" ] && echo cron || echo nocron)" > "$R107/evo/run-$tag.n"
  vt_host > "$R107/vault/run-$tag.after"; cp "$U99/requests.log" "$R107/ucrm/run-$tag.req"; cp "$U99/limits.log" "$R107/ucrm/run-$tag.lim"
  case "$o" in *"unknown option:"*) echo "run107-$n" >> "$SB/bad-runs" ;; esac
  if [ -n "${R107_MUTANT_OUT:-}" ]; then printf '%s\n' "$o" > "$R107/mutants/run-$tag.txt"; else printf '%s\n' "$o" > "$R107/run-$tag.txt"; fi
  [ -z "${REHEARSE_KEEP:-}" ] || printf '%s\n' "$o" > "$REHEARSE_KEEP/run107-$tag-${answer:-none}-$(basename "$script" .sh)$(printf '%s' "$*" | tr -c 'A-Za-z0-9-' '_').txt"
  printf '%s' "$o"
}
run107c() { run107 --env "LD_PRELOAD=$CLKPRE104" --env "RH104_CLOCK_FILE=$CLKF104" "$@"; }   # under the rehearsal's clock
run_line107c() {   # run_line107c WORD LINE — a command line exactly as a summary printed it, under the clock, WORD typed; its exit status kept as run107's
  local n tag o cmd e0 c0
  : > "$SB/docker.log"; : > "$U99/requests.log"; : > "$U99/limits.log"
  n=$(( $(cat "$SB/runs" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$SB/runs"; tag="$(printf '%03d' "$n")"; echo "$tag" > "$R107/rc/last"
  printf '%s|%s|%s\n' "$(backups107)" "$(states107)" "$(keysum107)" > "$R107/rc/run-$tag.pre"
  vt_host > "$R107/vault/run-$tag.before"; : > "$R107/exec/run-$tag.rec"; : > "$R107/scan/run-$tag.hits"; : > "$R107/tok/run-$tag.tok"; e0="$(evo_calls)"; c0="$(grep -c . "$R104/logs/cron104.runs" 2>/dev/null)"
  cmd="$(printf '%q ' env PATH="$SB/datebin:$SB/bin:$PATH" FAKE_TODAY="$AFTER94" DNB_OUT="$OUT107" DNB_RECORDS="$RECS" DNB_WEBSITE="$WEB_OK" GUARD_SECONDS=3 DN_DATA_DIR="$DATA" PLUGIN_BASE="$PLUGIN_BASE" \
         DNB_SALES_LOG_DIR="$SALES104" DNB_REPORT_DIR="$REPORT104" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" RH106_TOUCH=1 \
         FAKE_EXEC_LOG="$R107/exec/run-$tag.rec" FAKE_TMP_SCAN="$SCAN96" FAKE_TMP_HITS="$R107/scan/run-$tag.hits" FAKE_TOK_HITS="$R107/tok/run-$tag.tok" bash -c "$2")"
  o="$(printf '%s\n' "$1" | SHELL=/bin/bash script -qec "$cmd" /dev/null 2>&1 | tr -d '\r'; echo "${PIPESTATUS[1]}" > "$R107/rc/run-$tag.rc")"
  echo "$(( $(evo_calls) - e0 )) $( [ "$(n103 "$(grep -c . "$R104/logs/cron104.runs" 2>/dev/null)")" -gt "$(n103 "$c0")" ] && echo cron || echo nocron)" > "$R107/evo/run-$tag.n"
  vt_host > "$R107/vault/run-$tag.after"; cp "$U99/requests.log" "$R107/ucrm/run-$tag.req"; cp "$U99/limits.log" "$R107/ucrm/run-$tag.lim"
  case "$o" in *"unknown option:"*) echo "run107-$n" >> "$SB/bad-runs" ;; esac
  printf '%s\n' "$o" > "$R107/run-$tag.txt"
  printf '%s' "$o"
}
last107() { cat "$R107/rc/last" 2>/dev/null || echo none; }                    # the tag of the latest run107 / run_line107c
rc107() { cat "$R107/rc/run-$(last107).rc" 2>/dev/null || echo none; }         # its exit status
secs107() { local t="$(last107)"; echo $(( $(cat "$R107/rc/run-$t.t1" 2>/dev/null || echo 0) - $(cat "$R107/rc/run-$t.t0" 2>/dev/null || echo 0) )); }   # its real duration
fresh107() {   # the run folder as a new run finds it: the state files, the key files and the row digests kept; every log and sheet moved aside
  find "$OUT107" -mindepth 1 -maxdepth 1 -name '*.log' -exec mv -t "$R107/logs/" {} + 2>/dev/null
  find "$OUT107" -mindepth 1 -maxdepth 1 -name 'cash-*.txt' -exec mv -t "$R107/sheets/" {} + 2>/dev/null
  find "$OUT107" -mindepth 1 -maxdepth 1 ! -name "state-$V107.env*" ! -name 'rollback-state-*' ! -name 'ql-key-*' ! -name 'cih-rows-*' ! -name "lk-key-$V107" -exec rm -rf {} +; base_log; }
tidy107() {   # tidy107 CASE — a deploy's backup, state files and LK key moved into the case's own folder, so the next deploy is a first deploy again (v3 A9 step 4)
  local d="$R107/case/$1"; mkdir -p "$d"
  find "$OUT107" -mindepth 1 -maxdepth 1 \( -name 'backup-*' -o -name "state-$V107.env*" -o -name 'rollback-state-*' -o -name "lk-key-$V107" -o -name 'cih-rows-*' \) -exec mv -t "$d/" {} + 2>/dev/null; :
}
went107() { echo "$(has "$1" 'GO — evidence recorded')$(has "$1" 'Type DEPLOY')"; }   # the run reached the question
quiet107() {   # quiet107 — what the latest run changed: LIVE|backups added|state files added|samekey or keymoved
  local p; p="$(cat "$R107/rc/run-$(last107).pre" 2>/dev/null)"
  echo "$(live)|$(( $(backups107) - $(n103 "$(cut -d'|' -f1 <<<"$p")") ))|$(( $(states107) - $(n103 "$(cut -d'|' -f2 <<<"$p")") ))|$( [ -n "$p" ] && [ "$(keysum107)" = "$(cut -d'|' -f3 <<<"$p")" ] && echo samekey || echo keymoved)|$(data94)"
}
nogo107() {   # nogo107 LABEL EXPECTED-TEXT OUTPUT DATA-DIGEST LIVE — refused with that text, "Nothing was changed", exit 1, never the question; nothing changed
  check "$(has "$3" "$2")$(has "$3" 'Nothing was changed. Send the log file.')$(went107 "$3")|$(rc107)|$(quiet107)" "yesyesnono|1|$5|0|0|samekey|$4" "$1"
}
checkgo107() { echo "$(has "$1" "  $V107 (check): GO — stage A holds.")$(went107 "$1")$(fails "$1")|$(cut -d'|' -f2-4 <<<"$(quiet107)")|$(rc107)"; }   # GO: yesnono0|0|0|samekey|0
sget107() { sed -n "s/^$2=//p" "$1" 2>/dev/null | tail -1; }                   # sget107 FILE KEY — the last value a state file records for KEY
aft107() {   # aft107 LABEL VERDICT [STEP=STATE…] — 5.18.107's --after-only under the clock (the copy as the deploy recorded it, never
             # moved back; no schedule written): PASSED (exit 0, no FAIL), NOTYET (exit 3, no FAIL, the NOT YET line, no PASSED) or
             # FAILED (exit 1, a FAIL); each pair as named, the rest OFF; Evolution not called. The caller runs master106 first
             # unless its case is "before any pass". AFT107 its output, AFTT107 its tag, AFTRC107 its exit status — call directly
  local lab="$1" want="$2" e0 f p y x got exp; shift 2; idle104; e0="$(evo_calls)"
  fresh107; AFT107="$(run107c --after-only)"; AFTT107="$(last107)"; AFTRC107="$(rc107)"
  f="$(fails "$AFT107")"; p="$(has "$AFT107" "  $V107 (after): PASSED.")"; y="$(has "$AFT107" "  $V107 (after-only): NOT YET — ")"
  x="$(grep -E "^  ${V107//./\\.} \(after\): [0-9]+ FAILED — " <<<"$AFT107" | grep -c .)"
  case "$want" in
    PASSED) got="$f|$p$y$x|$AFTRC107"; exp="0|yesno0|0" ;;
    NOTYET) got="$f|$p$y$x|$AFTRC107"; exp="0|noyes0|3" ;;
    FAILED) got="$( [ "$(n103 "$f")" -gt 0 ] && echo some || echo none)|$p$y$x|$AFTRC107"; exp="some|nono1|1" ;;
    *) got="verdict:$want"; exp="PASSED|NOTYET|FAILED" ;;
  esac
  check "$got|$(q105 stcmp "$(onall107 "$AFT107")" "$(expect107 "$@")")|$(( $(evo_calls) - e0 ))" "$exp|same|0" \
    "$lab $V107's --after-only under the clock ($(kday104 "$(fnow104)")): $want (exit status $AFTRC107) — each pair as the step stands$( [ $# -gt 0 ] && echo " ($*)"), the rest OFF — Evolution not called"
}
rbx107() {   # rbx107 LABEL — back to 5.18.106 after a deploy of 5.18.107: ROLLBACK typed, under the clock; PASSED, exit 0, $B107 serving,
             # every file 5.18.107 changes $BV107's, every ladder key as before it; then qcheck106. RBX107_OUT its output, RBX107_T
             # its tag, RBX107_REC the record it wrote (or none) — call it directly
  local o k0 r0
  k0="$(digest105)"; r0="$(ls -1 "$OUT107"/rollback-state-*.env 2>/dev/null)"
  fresh107; o="$(run107c --answer ROLLBACK --rollback)"; RBX107_OUT="$o"; RBX107_T="$(last107)"
  RBX107_REC="$(ls -1 "$OUT107"/rollback-state-*.env 2>/dev/null | grep -vxF -f <(printf '%s\n' "$r0") | tail -1)"; RBX107_REC="${RBX107_REC:-none}"
  check "$(fails "$o")$(has "$o" "  $V107 (rollback): PASSED.")|$(rc107)|$(live)|$(installed107 "$B107F")|$( [ "$(digest105)" = "$k0" ] && echo kept)|$( [ "$RBX107_REC" != none ] && echo record)" \
        "0yes|0|$B107|0|kept|record" "$1 the rollback, ROLLBACK typed, under the clock: PASSED (exit 0) — $B107 serving, every file $V107 changes $BV107's again, every ladder key as before it (this rehearsal's reading)"
  qcheck106 "$1'"
}
# The held runs (v2 A10, v3): the script waits at a question (or a line) while an ACTION runs. csb1run107 holds at "Type DEPLOY"
# and records the ACTION's own exit status; rbhold107 at "Type ROLLBACK"; pollrun107 at ME-CA1's poll line, in --after-only,
# acting 1 s after it (so the poll's t0, S:8381, is taken first) and never acting when the line does not come.
hold107() {   # hold107 LABEL KEY ANSWER [--script S] [--opt OPTION] -- ACTION… — the shared shape; HOLDOUT107 the output, HOLDRC107 ACTION's exit status, HOLDSAW107 yes|no
  local lab="$1" key="$2" ans="$3" scr="$DEPLOY107" opt="" st hf op; shift 3
  while :; do case "${1:-}" in --script) scr="$2"; shift 2 ;; --opt) opt="$2"; shift 2 ;; --) shift; break ;; *) break ;; esac; done
  st="$R107/logs/stream-$lab.txt"; hf="$R107/logs/hold-$lab"; rm -f "$hf" "$R107/logs/act-$lab.rc" "$R107/logs/saw-$lab"; : > "$st"
  ( i=0; while ! grep -q -- "$key" "$st" 2>/dev/null && [ "$i" -lt 6000 ]; do sleep 0.05; i=$((i+1)); done
    if grep -q -- "$key" "$st" 2>/dev/null; then : > "$R107/logs/saw-$lab"; [ "$key" != 'ME-CA1 waiting up to' ] || sleep 1
      "$@" > "$R107/logs/act-$lab.txt" 2>&1; echo "$?" > "$R107/logs/act-$lab.rc"
    else echo "hold107:$lab:noline" >> "$R104/wait104.fail"; echo noline > "$R107/logs/act-$lab.rc"; fi
    : > "$hf" ) & op=$!
  fresh107
  if [ -n "$opt" ]; then HOLDOUT107="$(R107_STREAM="$st" run107c --answer "$ans" --hold "$hf" --script "$scr" "$opt")"
  else HOLDOUT107="$(R107_STREAM="$st" run107c --answer "$ans" --hold "$hf" --script "$scr")"; fi
  wait "$op" 2>/dev/null
  HOLDRC107="$(cat "$R107/logs/act-$lab.rc" 2>/dev/null)"; HOLDSAW107="$( [ -e "$R107/logs/saw-$lab" ] && echo yes || echo no)"
}
csb1run107() { local lab="$1" scr="$2"; shift 2; [ "${1:-}" = -- ] && shift; hold107 "$lab" 'Type DEPLOY' DEPLOY --script "$scr" -- "$@"; }       # csb1run107 LABEL SCRIPT -- ACTION…
rbhold107() { local lab="$1" ans="$2"; shift 2; [ "${1:-}" = -- ] && shift; hold107 "$lab" 'Type ROLLBACK' "$ans" --opt --rollback -- "$@"; }     # rbhold107 LABEL ANSWER -- ACTION…
pollrun107() { local lab="$1" scr="$2"; shift 2; [ "${1:-}" = -- ] && shift; hold107 "$lab" 'ME-CA1 waiting up to' '' --script "$scr" --opt --after-only -- "$@"; }   # pollrun107 LABEL SCRIPT -- ACTION…
