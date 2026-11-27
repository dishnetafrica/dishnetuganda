# 5.18.106 rehearsal machinery, for 5.18.107 (27 Nov; read-only map of scripts/harness/deploy-5.18.106/, R6 = its rehearse.sh)

All paths are under /home/user/dishnetuganda. R6 means scripts/harness/deploy-5.18.106/rehearse.sh (2,600 lines) and R5 means scripts/harness/deploy-5.18.105/rehearse.sh (1,175 lines).

## (a) The sections of R6, in order
- **1–56, header.** What the file proves; the A1 and master mechanics; the development-runner note (45–48); the cases left out (35–36).
- **57–120, 106-0 guards.** Refuses to run on a server path. Requires `F106` to be pinned (P/T1 signature). The two helpers must be beside it and parse, with no `__pycache__`. Static guard: `rh106q.py verbs` must read `lk=1 qftool=0 wrapper=0 verbs=0`, rh105q agrees, and three planted copies are each refused. 5.18.105's script must be pinned to the baseline, and the wrappers must be committed.
- **122–194, 106-cut.** Cuts R5 and sources it. Asserts every chained function and variable is defined (176–193), requires `LIVE104=yes`, and sets `FAIL_PRE106`.
- **198–787, 106-infra.**
  - a: script, pins and the release fetch (198–226).
  - folders and runners (227–392); b: the ladder from Git; c: the `ONP106` step pairs.
  - master and sender (393–451); seed and settings (452–474); undo helpers (475–495); weakened copies (496–525).
  - d: `sweep105` read from R5 (526–551). e/f: 106's own range `PR106` proved free, plus the pattern files `FORMS106`/`NM106F` (552–579).
  - g: A1 touch shim (580–615). h: A6 Evolution 400 (616–691). i: A7 `.redirect106` (692–722). j: A8 uj hook (723–772). k: EXIT trap (773–788).
- **790–1076, 106-pre.**
  - pre1 896: 5.18.105 redeployed through run105c, its after-only, the quarantine check, and the `$RECS/dnb-5.18.105` link.
  - pre2 918: off chase. pre3 925: followup_* settings plus a `--check`.
  - pre4 943: J1, three refused drafts over five sender passes. pre5 996: J2, 400 gone rows. pre6 1045: J3, a two-draft pair. pre7 1058: the 18 Nov interim.
- **1078–1307, 106-1.** 1a–1k (1108, 1124, 1134, 1163, 1187, 1205, 1218, 1235, 1261, 1293): CS-G1, LK-G1, FE-G1, U4 unreadable, the capitals note and CS-B1, each with its weakened copy.
- **1309–1503.** Part 4 header and helpers.
- **1504–1539, 106-4.** Deploy D1 PASSED, with U1–U5 equal to the mirror.
- **1540–1656, 106-5** on D1: 5a NOT YET (1541), 5b ME-CA1 planted lines (1551), 5c UJ_A dropped (1581), 5d COPY_AT dropped (1589), 5e uj hook (1598), 5f pass 1 NOT YET (1608), 5g limit note (1621), 5h 'now' note (1637), 5i pass 2 PASS (1648).
- **106-5D2–D6, copies of their own:** D2 1657, D3 1681, D4 1705, D5 1717 (F3 removed from the installed close job), D6 1772. Then 5z at 1798–1803.
- **1804–1835.** Part 5 header.
- **1836–2229, 106-6.** Helpers 1837–1943, 6pre 1944, 6a 1963, 6s 2004 (the 5.18.106 sender pass), 6b 2022, 6e 2124, 6c 2151, 6d 2170, 6f 2206.
- **2230–2441, 106-7.** 7a 2231, 7b 2246, 7c 2266, 7d 2288, 7h 2318, 7e 2328, 7f 2354, 7g 2392, 7i 2414 (the known limit), 7z 2436–2441.
- **2443–2600, 106-10.** Heading at 2446, then 10a0 and a–i (Evolution, uCRM, the three sweeps, LK keys, numbers, fixture leftovers, failure files, end state), then `RESULT` at 2598–2600.

## (b) Infrastructure API

**Runners**
- `run106 [--answer W] [--hold FILE] [--script F] [--env K=V]… OPTS` (277): runs under `script`. It writes the exit status to `$R106/rc/run-NNN.rc` and the before-state (backups|states|keysum) to `.pre`. It records Evolution calls and cron use in `evo/run-NNN.n`, vault before/after, uCRM request records, and exec/scan/tok records, and sets `RH106_TOUCH=1`. `R106_MUTANT_OUT` sends the terminal to `mutants/`; `R106_STREAM` tees it.
- `run106c` (299): run106 under the clock. `run_line106c WORD LINE` (300): a printed command run verbatim.
- `last106`/`rc106` (317–318): the latest run's tag and exit status.
- `fresh106` (319): clears logs and sheets, keeping state, key and digest files.
- `tidy106 CASE` (323): moves backup, state and LK key into `$R106/case/CASE`.
- `went106`, `quiet106`, `nogo106 LABEL TEXT OUT DIGEST LIVE`, `checkgo106` (328–337).
- `aft106 LABEL PASSED|NOTYET|FAILED [STEP=STATE…]` (358): sets `AFT106`, `AFTT106`, `AFTRC106`.
- `rbx106 LABEL` (381): a typed ROLLBACK, PASSED, then `qcheck106`; sets `RBX106_OUT`, `_T`, `_REC`.
- `qcheck106` (376), `dredeploy106` (1344), `dround106 D RBWANT [LIMIT HOURS]` (1460), `v1run106 LABEL [lock]` (1916), `csb1run106 LABEL SCRIPT -- ACTION` (883; runs ACTION while the deploy waits at "Type DEPLOY").

**Master and sender**
- `master106 LABEL [DUE…]` (413): clock +601 s, a real followup_close pass, asserted; sets `MS106_AT`/`MS106_OUT`.
- `sender106 LABEL [--window|--step S] [DUE…]` (434): checks the window, then sets `SS106_AT`/`_OUT`/`_CLOSE`.
- Helpers: `lrin106` (400), `due106` (408), `kwin106` (422; the next non-Sunday 09:00 Kampala), `closeok106` (427), `tag106` (399).

**Plants and settings**
- `seed106 CODE-ROOT DATA OPS [ZONE]` (454): runs as uid 1000 under the clock, with a vault copy and `RH106_RANGE`.
- `plant106 OPS` (800) uses the pin's ref/ code; `seed106r` (807) runs as root for file fixtures.
- Readers and plant helpers: `sd106`, `ag106`, `rwdk106`, `fsl106`, `f5d106`, `apd106`, `dlv106`, `dstale106` (815–882, 1381).
- `setcfg106 KEY VALUE` (466) runs the installed set_config through the stand-in docker as `owner104`; `setrc106` (474). Also `setevo106`/`sevrc106`/`setclr106` (1889–1907).

**Undo helpers (A9)**
- `keep106 NAME FILE` / `back106 NAME` (477/482): byte-for-byte keep and restore, checked by sha256.
- `mark106` / `cut106 FILE MARK` (487/488): an append-only file put back to its mark.
- `dstdrop106 KEY` (1405): one state-file line removed.
- Also `kyceq106` (1877), `still106`, `outsum106` (849–850).

**Weakened copies**
- `anc106 KEY ANCHOR REPL` (499) writes `$SB/anchors106/KEY.a` and `.b`.
- `weak106 NAME KEY…` (500) copies `DEPLOY106` to `$REPO/scripts/.NAME.sh`. Python asserts the anchor is non-empty, different from its replacement, and present exactly once, plus the length arithmetic. A miss is written as `weak106:KEY` to `$SB/anchor-missing`, then `bash -n` runs and the path is printed.
- Usage: `MW=$(weak106 x x); OUT=$(R106_MUTANT_OUT=1 run106c --script "$MW" …); rm -f "$MW"` (e.g. 1119). It is asserted caught by the case's own text being absent.
- `ancc106` (517) is the control on the controls (1101, 2590). 106-10f asserts no `.*.sh` newer than `DEPLOY106` is left.

**Clock** (from 5.18.104's rehearsal)
- `clk_set104` (1229), `clk_off104` (1230), `fnow104` (1231), `clk_to104 EPOCH` (1232, forward only), `kat104`/`kday104`/`kdow104` (1237–1239).
- `CLKPRE104` is the LD_PRELOAD library and `CLKF104` the offset file. R6 adds `nr106` and `tst106` (600–602).

**Assertions** (quarantine root)
- `PASS=0; FAILN=0` and `check GOT WANT LABEL` (140–141); `has` (142); `fails` (144); `line_with`/`okline`/`failline` (648–660).
- `noteline` (deploy-5.18.95:203); `live`/`inst_ver` (378–379).

**Readers and preconditions**
- State files: `sget106 FILE KEY` (338), `copyok106` (339), `ctl106` (345), `pig106` (353), `backups106`/`states106`/`keyf106`/`keysum106` (273–276), `rsl106`/`newrec106` (1914–1915).
- Through rh106q: `q106 rblast`, `dclose106`, `dsto106`, `dnow106`, `dnooffer106` (1326–1332).
- Preconditions: `cond106` (864), `dpre106` (1333).

**rh106q.py** (dispatcher 1068–1094) prints one key=value line. Its modes are `verbs`, `u1mirror`, `u2u3`, `rwd`, `staleorder`, `rblast`, `cfgsha`, `kycplaces` and `closer`. Exit 2 is an unknown mode; exit 3 is `unread:<Error>`.

**rh106-seed.php** takes `<code root> <data> <ops JSON> [tz]` and needs `DN_VAULT_FILE` and `RH106_RANGE`.
- Book ops: `enquiry`, `pair`, `inbound`, `delivery`, `gone`, `fx_store_keys[_undo]`, `fx_type3[_undo]`.
- File ops: `fx_file`, `fx_file_back`, `fx_state_drop`, `fx_state_back`.
- Exit 2 means refused before opening; exit 3 means refused after N ops.

## (c) The cut
**How 106-cut works** (122–173). Python on R5:
- The anchor is exactly `echo; echo "== 105-10. what the rehearsal left behind, over 104's records and 105's alike =="` (R5:1054), found once, at index > 900.
- The last `== 105-` heading before it must be 105-7's (R5:1002). The last non-empty line must be `rbx105 "105-7e"` (R5:1052), once in the cut, and the last check is 105-7d's.
- One re-point: `H105_DIR="$(cd "$(dirname "$0")" && pwd)"` becomes `…"$0")/../deploy-5.18.105" && pwd)"`. It must be followed by `H105_ROOT=`, have at least 30 lines before it, and come before `H104_HARNESS105=`. The edit is counted.
- A list of R5 function and variable heads must each start exactly one line. The result is written to `H106_PREFIX`, sourced (172) and removed (173).

**The development-runner block** runs from line 125 (`H106_PREFIX="$(mktemp)"`) to line 173 (`rm -f "$H106_PREFIX"`). devres106, which is uncommitted (scratchpad b106/rh/devres106.sh:135–136), skips it after restoring a devsnap106 shell. Everything from line 174 on (the defined-asserts, then copying the committed script and wrappers into the clone, 203–209) runs in either shell.

**What a 5.18.107 cut needs**
- The anchor (R6:2446), exactly once:
  `echo; echo "== 106-10. what the rehearsal left behind, over 104's, 105's and 106's records alike: the sweeps, the fixtures' leftovers, the chain's failure files =="`
- Differences from 105's shape:
  - The last `== 106-` heading before the anchor is 106-7i's (2414), not 106-7's.
  - The last non-empty lines before the anchor are three `# ═══…` and `# 106-10 —` box-comment lines (2443–2445). The last code line is the `echo "  note  RH-NOTE 106-6 and 106-7 hand 106-10: …"` (2441), and the last check is 106-7z (2437–2439, label `"106-7z what 106-10 begins from:`).
  - The re-point line is R6:61 `H106_DIR="$(cd "$(dirname "$0")" && pwd)"`, which becomes `/../deploy-5.18.106`. `H106_ROOT=` follows it, 60 lines precede it, and `H105_HARNESS106=` comes after it (123). `$0` is used only there.
- `sweep106` is already inside the cut (531), so 107 does not need to re-read it the way 106 re-read `sweep105`.

**Chain state at the cut** (106-7z, 2436–2441):
- `$P106` (5.18.106) is serving, from 106-7i6's `--redeploy`. Steps 1–5 are on and step 6 (chase) is off (`L1ON106`, 7i10). No claim switch.
- Settings: followup_enabled on, followup_max_age_hours `$LIM106`=336, followup_auto_send off (`k:off,s:off,p:-,d:-`), followup_daily_cap 3 (a string in file and store row), followup_run_limit 2 (from 106-pre3). The Evolution keys are in the file and the store row and agree; quote_company_phone and email are in neither.
- The latest rollback record is V1's (AS_RB1=off), kept beside the state file. The typed rollback's (written) record is older.
- No index lock, no `.redirect106*`, no refusal list, no `.ujfail106`, no weakened copy.
- Still on disk: `$OUT106` (state-5.18.106.env plus `.prev-*`, rollback-state-*, lk-key, backups); `$R106` (including `kept/`, which 106-10f removes); `$H106/ref`; the `$RECS/dnb-5.18.105` link; and the edited `router.php`, `public.php` and `bin/docker`, plus `datebin/touch` (all inert without their triggers).
- `cleanup106` is in the EXIT trap. `RF4_106`, `J1A106`, `EVO0_106` and `AM0_106` are set.

## (d) Unfinished and known-broken
docs/75 §S (lines 798–803) records why the record run never completed:
- A container restart cut the first run.
- On the rerun, the stand-in docker's `-u` deletes failed on the session's unreadable `rm` wrapper; the script was not at fault.
- Trial 2 (106's own sections) had passed 470 ok, 0 failed, and the owner deployed without the record run.
- The "Open" list (821–827) includes "the rehearsal harness's fix, before 5.18.107's rehearsal" and the ME-CA1 defect (it reads plugin.log, where master writes nothing).

In the harness:
- Left out for want of fault injection (35–36): U4's StaffJobsGate-false branch, and AS-RB1's written-check, refused and unknown branches.
- One known limit is pinned for the owner (7i11, 2435).
- The seed ops `inbound`, `fx_state_drop` and `fx_state_back` are unexercised (rh106-seed.php:20–21).
- Scratchpad logs: the rerun log shows the -u FAIL at line 166; record.cut1 is truncated.

## (e) cc6a714 (W.8)
- **File:** scripts/harness/quarantine-stray-store/rehearse.sh.
- **The change, at line 472 → 475:**
  - before: `pre=(setpriv --reuid … --regid … --clear-groups --)`
  - after: `pre=(setpriv … --clear-groups -- env PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin)`
- The comment is at 418–421 and the controls at 949–965: printenv PATH, `rm` is /usr/bin/rm, a uid-1000 delete exits 0, and the control on the control is a sed-undone copy that gets exit 2 and a kept file.
- **How a restored snapshot lacks it:** `$SB/bin/docker` is generated once by the root's heredoc (426) when the chain builds the sandbox. The snapshots (snap1–4, 18–20 Nov) predate 26 Nov, and devres skips the `H10x_PREFIX` block, so the restored docker is the old one. 5.18.107 must re-apply the one-line edit, asserted (anchor `--clear-groups --); fi` exactly once), after a restore.

## (f) Fault-injection hooks, and the pattern to follow
Each hook is an asserted Python insertion into a stand-in, checked by a control before the edit (trigger planted, nothing happens), a check after it, and a check once the trigger is removed. Each has a trigger file, and `cleanup106` (776) removes the triggers.

- **Docker** (`$SB/bin/docker`, A8, 723–772).
  - The hook text is a heredoc using an `@SB@` placeholder, written to `$SB/docker106.hook`, substituted and inserted before the line starting `row() { grep "^$1|" `.
  - It asserts 104's hook is present (`_rh_r4`==3, `_rh_f4`>0) and the order `rindex('_rh_f4') < '.ujfail106' < row()`, then runs `bash -n`.
  - It uses private variables `_rh_*6`. The trigger `$SB/.ujfail106` is not spent (it holds until removed); the counter is `$SB/ujfail106.fired`.
- **Evolution** (`$SB/evo/router.php`, A6, 616–691).
  - Inserted before the line starting `if (is_file(__DIR__ . '/rh104-send.on') && preg_match('#/message/send(Text|Media)/#'`, after the requests.log line.
  - Trigger `rh106-refuse.list` (`refuse106`), counter `rh106-refused.n`.
  - It must wait out OPcache's `revalidate_freq`+1 seconds (678). `evopost106` provides the controls.
- **Website** (`$SB/web/public.php`, A7, 692–722).
  - Inserted before `$page = $_GET['page'] ?? '';`, after `.redirect105`.
  - A probe page `rh106_probe` answers `rh106-triggers-ready`, polled after the edit.
  - Triggers are one-shot files consumed by the root's `$spend()` (quarantine-stray-store/rehearse.sh:228), gated on the installed manifest's version. `.redirect106lock` touches `$REPO/.git/index.lock`.
- **Others.**
  - `$SB/datebin/touch` (A1, 589–598) acts only under `RH106_TOUCH`. A second writer is refused (`datebin:touch106:present`), so 5.18.107 should reuse it.
  - 104's `.rbfail104`, `rh104-send.on`, `rh104-slow` and `FAKE_EXEC_D_FAIL` remain.