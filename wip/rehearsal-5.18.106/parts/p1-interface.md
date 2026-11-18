Part 1 of scripts/harness/deploy-5.18.106/rehearse.sh (780 lines; bash -n clean on stdin; all 8 embedded <<'PY' blocks and the evopost106 inline Python AST-parse; the uj hook and the touch shim texts each pass bash -n on their own). It ends where 106-pre begins.

The dev-runner block runs from the one line beginning H106_PREFIX="$(mktemp)" to the one line exactly rm -f "$H106_PREFIX". Each occurs once, checked.

GLOBALS SET (for Parts 3–6)
- Paths and pins:
  - H106_DIR, H106_ROOT, F106, F105_106, H105_HARNESS106;
  - DEPLOY106 = $REPO/scripts/deploy-5.18.106.sh;
  - P106/P106F/V106 = bf29cbd / its full form / 5.18.106;
  - B106/B106F/BV106 = 7835906 / its full form / 5.18.105 (all read from the script);
  - REL106, WHICH106, CM106 (COPY_MARK), ADDED106, NA106.
- Folders:
  - OUT106 = $SB/out106; STATE106 = $OUT106/state-5.18.106.env; LKF106 = $OUT106/lk-key-5.18.106.
  - R106 = $SB/r106, with logs, mutants, sheets, exec, scan, vault, tok, ucrm, evo, case, rc, kept and pre.
  - H106 = $SB/rh106, holding v (1000:1000, 0700), ref (the pin's lib/ and tools/ from Git), rh106q.py and rh106-seed.php.
- Baselines: FAIL_PRE106, AM0_106, WF0_106, BR0_106 (count of ^run106- in bad-runs), ST0_106, EVO0_106 (taken at the very end of infra).
- Commands, ladder and step pairs:
  - EXP_AFTER106, EXP_ROLLBACK106, EXP_REDEPLOY106 (as the script prints them);
  - LAD106;
  - ONP106 (asserted equal to ONP105);
  - L1ON106 = steps 1–5 judged + ledger=pass|empty;
  - L2ON106 = L1ON106 + chase.
- Numbers and pattern files:
  - PR106 = '+2567001060'; RX106 and RXC106 are the range regexes (106's and the chain's);
  - FORMS106 = $R106/forms106.pat (0600: chain + 105 + all 100 of 106's range, four forms plus the last nine digits);
  - NM106F = $R106/names106.pat (0600: the chain's names plus the line 'RH106SECRET-');
  - $R106/phones106.txt;
  - NAMES104 now carries +256700106000…+256700106099;
  - PA106/PB106 = ph106 98/99 (used by A6's control).
- Other: AN106 (anchor folder), TOUCH106, DATE106, TRAP0_106.

RUNNERS (none calls copy_back105, after_back105, set_sched or cron104_up)
- run106 [--answer W] [--hold FILE] [--script F] [--env K=V]… OPTION…
  - Prints the run's output.
  - Writes $R106/run-TAG.txt, or mutants/run-TAG.txt when R106_MUTANT_OUT=1.
  - Records: $R106/rc/run-TAG.rc (the script's exit status, via script -e and PIPESTATUS[1]); $R106/rc/run-TAG.pre (backups|states|keysum before the run); $R106/rc/last = TAG; evo/run-TAG.n ("N cron|nocron"); vault, exec, scan, tok and ucrm records.
  - Sets RH106_TOUCH=1 in the script's environment.
  - R106_STREAM tees the live output.
  - A --hold timeout appends run106-hold:TAG to $R104/wait104.fail. "unknown option:" appends run106-N to $SB/bad-runs.
- run106c … — run106 under the clock.
- run_line106c WORD LINE — LINE run via bash -c under the clock, WORD typed, RH106_TOUCH=1. It records like run106; its rc is the line's own (a pipeline's last command).
- last106 — the tag of the latest run106 or run_line106c. rc106 — its exit status, or none.
- fresh106 — logs to R106/logs, cash sheets to R106/sheets, everything else but state-*, rollback-state-*, ql-key-*, cih-rows-* and the lk key deleted; then base_log.
- tidy106 CASE — backup-*, state, rollback-state, lk key and cih-rows moved into $R106/case/CASE.
- keysum106 → sha16 of LKF106, or nokey. backups106, states106 and keyf106 as in 105.
- went106 OUT → yes|no for 'GO — evidence recorded', then yes|no for 'Type DEPLOY'.
- quiet106 → "live|backups added|states added|samekey or keymoved|data94", relative to the last run's .pre record.
- nogo106 LABEL TEXT OUT DATA LIVE — one check: TEXT present, 'Nothing was changed. Send the log file.', never the question, rc 1, nothing changed. Expects "yesyesnono|1|LIVE|0|0|samekey|DATA".
- checkgo106 OUT → "yesnono0|0|0|samekey|0" on a GO (rc 0).
- installed106 COMMIT → the count of files in B106F→P106F (excluding tests/) not installed as COMMIT has them. left106 → 106's added files on disk.
- onall106 OUT, and expect106 STEP=STATE… (as 105's, over ONP106).
- aft106 LABEL PASSED|NOTYET|FAILED [STEP=STATE…] — call directly, never in $( ). Runs fresh106, then run106c --after-only. Never moves the copy back; the caller runs master106 first unless the case is "before any pass". Sets AFT106, AFTT106 and AFTRC106. One check, by verdict:

  | verdict | asserts |
  |---|---|
  | PASSED | 0 FAILs, the PASSED line, rc 0 |
  | NOTYET | 0 FAILs, the "(after-only): NOT YET —" line, no PASSED, rc 3, exactly one "  wait  UJ-ON NOT YET — " line |
  | FAILED | at least one FAIL, the "(after): N FAILED —" line, neither PASSED nor NOT YET, rc 1 (the UJ-ON wait count is not fixed) |

  In every case it also asserts: the step pairs are q105 stcmp-same as expect106 "$@", and Evolution is not called.
- qcheck106 LABEL — check: qrun104c --check PASSED.
- rbx106 LABEL — call directly.
  - Runs fresh106, then run106c --answer ROLLBACK --rollback.
  - Checks: 0 FAILs; "(rollback): PASSED."; rc 0; live = B106; installed106 B106F = 0; digest105 kept; wason104 0; a new rollback-state-*.env written.
  - Then qcheck106 "LABEL'".
  - Sets RBX106_OUT, RBX106_T, and RBX106_REC (the new rollback-state file, or none).
- master106 LABEL [DUE…] — call directly; sets MS106_AT and MS106_OUT.
  - Moves the clock +601 s, then runs master104 "106m-<tag>" followup_close DUE….
  - Checks: rc 0; followup_close's entry (sched104) started within the pass on the clock and ended (duration ≥ 0); the output holds "] [master] RUN followup_close".
- sender106 LABEL [--window] [--step S] [DUE…] — call directly; sets SS106_AT, SS106_OUT and SS106_CLOSE.
  - Clock: --window moves it to kwin106 (09:00 Kampala on the next non-Sunday date); otherwise +S (default 301).
  - followup_send is DUE, plus followup_close whenever inst_ver = 5.18.106.
  - Checks: weekday (not Sunday); hour 8–19 Kampala; followup_send due by master's 300 s interval before the pass; rc 0; followup_send's entry within the pass and ended; "RUN followup_send"; followup_close ran for real, skipped, or not asked — never a plant.
- Small helpers:
  - kwin106 → epoch.
  - lrin106 ENTRY T0 T1 → in|running|out:<s>|none|unread.
  - due106 ENTRY T SECS → due|notdue:<s>|unread.
  - closeok106 CLOSE ENTRY0 ENTRY1 T0 T1 OUT → -|ran|skipped|bad:…|moved:….
  - tag106 LABEL → a file-safe label.
- ctl106 → after|running|before|none|nocopy|unread. It compares followup_close's last_run with COPY_AT in STATE106; before is A2's positive control for NOT YET.
- copyok106 [STATE] → near (|COPY_AT − QF_COPY_AT as UTC| ≤ 60 s) | off:<s> | unread.
- sget106 FILE KEY → the last value of KEY=.
- pig106 → sha16 of both piggyback_last_run.txt places (content and mtime).
- seed106 MODE ARG… — runs php "$H106/rh106-seed.php" MODE ARG… as 1000:1000.
  - cwd $H106/v, DN_VAULT_FILE = a vault copy (removed after), under the clock, proxies cleared.
  - Prints one JSON line starting with '{', or none(<160 chars of stderr>).
- setcfg106 KEY VALUE — the installed tools/set_config.php via the stub docker, -u owner104, DN_DATA_DIR=$PDD104_IN, under the clock.
  - Output cut at '  AI SETTINGS'; appended to $R106/setcfg.txt (unswept).
  - Exit status appended to $R106/setcfg.rc; setrc106 reads the last.
- keep106 NAME FILE → sha16 or absent. back106 NAME → same|differs|present|nokeep:NAME (byte, owner, mode and time via cp -p).
- mark106 FILE → SIZE:sha16 or absent. cut106 FILE MARK → same|moved|gone|differs|present (truncates an append-only file back to its mark).
- anc106 KEY ANCHOR REPL; weak106 NAME KEY… → the copy's path ($REPO/scripts/.NAME.sh). Misses go to anchor-missing as weak106:KEY or weak106:NAME:syntax. ancc106 → "bad|n|keys" over every anchor set.
- uo106 FILE… and tl106 FILE… (as 105-10's). sweep106 → 106's own records (R106 run, mutant, sheet, log and stream files; OUT106 state, rollback-state, cih-rows, *.log and cash; case files except backup-*; never lk-key). sweep105 is extracted from R5's 105-10, unchanged.
- ph106 NN → +2567001060NN, two digits only; a miss goes to anchor-missing.
- nr106 A B → near (±2 s) or off:<d>.
- refuse106 [NUMBER…] — writes $SB/evo/rh106-refuse.list (digits, 0644) and prints the count; with no arguments it removes the list. refused106 → the stand-in's refusal count.
- evopost106 sendText|sendMedia NUMBER → id|refused|<code>:other.
- arm106 [lock] — plants $SB/web/.redirect106, plus .redirect106lock with "lock". lock106 → present|absent for $REPO/.git/index.lock. unlock106 removes it and prints absent.
- ujcall106 SOURCE MODE → <output|empty>:<rc>. The uj trigger is $SB/.ujfail106 (persists until removed); refusals are counted as 'uj' lines in $SB/ujfail106.fired, emptied at the end of infra.
- cleanup106 — runs first in the EXIT trap ("cleanup106; <the chain's trap>"). Removes the index.lock, .ujfail106, .redirect106, .redirect106lock and rh106-refuse.list.

EDITS AND THEIR CONTROLS (checks 106-infra a–k)
- a — pins; b — ladder unchanged; c — ONP106 = ONP105; d — the sweep lists.
- e — the range is free:
  - absent from harnesses, the checkout's tests, the pin's and baseline's tests in Git, $DATA, $PD/data, $RECS, NAMES104 and FORMS105;
  - controls: the chain's range is found in harnesses and $DATA, and git grep finds refusedWhileDelivering in the pin's tests.
- f — the pattern files.
- g — A1, the $SB/datebin/touch shim, gated by RH106_TOUCH. The control covers: the clock at least 3600 s ahead; real touch under LD_PRELOAD gives wall time; the shim gives clock time, wall time without LD_PRELOAD, passes through when not gated, passes -d through, and creates nothing with -c; it comes first on PATH.
- h — A6. Inserted before the line starting `if (is_file(__DIR__ . '/rh104-send.on') && preg_match('#/message/send(Text|Media)/#'`. Controls: before the edit, with the list present, the answer is id; after: list absent → id, listed sendText → 400, other number → id, sendMedia → id; 5 requests logged, 1 refusal counted.
- i — A7. Inserted before `$page = $_GET['page'] ?? '';\n`. The probe is absent before the edit; armed while not 106: 200:0, both triggers unspent, no lock.
- j — A8. Inserted before the line starting `row() { grep "^$1|" `. Planted before the edit, the uj call reads; after: armed → empty:1, other modes and another source read, removed → reads.
- k — the trap. A planted index.lock makes git checkout B106F refuse with HEAD unmoved; cleanup106 removes it.

CONTRACT FOR PART 2
- rh106q.py must parse with ast and be standard library only.
  - `verbs SCRIPT` must print exactly "lk=<n> qftool=<n> wrapper=<n> verbs=<n>" by rh105q.py's verbs rule; porting that function unchanged is enough.
  - On scripts/deploy-5.18.106.sh it must print lk=1 qftool=0 wrapper=0 verbs=0, the same as rh105q.py.
  - Each of the three plants (qf_tool off, bash $QF_WRAPPER off, docker exec … $QF_TOOL off) appended before `main "$@"; exit $?` must read otherwise.
- rh106-seed.php must pass php -l, take argv[1] as MODE, print one JSON line starting '{', accept only ^\+?2567001060[0-9]{2}$ numbers, and use no raw SQL.