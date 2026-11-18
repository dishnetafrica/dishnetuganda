TASK A: how 5.18.105's rehearsal works, and what 5.18.106's rehearsal must chain on

Abbreviations. R5 = scripts/harness/deploy-5.18.105/rehearse.sh (1,175 lines; the copies in /home/user/dev105 and /home/user/dishnetuganda are identical, sha256 abfc5993…). R4 = deploy-5.18.104/rehearse.sh. RQ = scripts/harness/quarantine-stray-store/rehearse.sh, the root of the chain. Rnn = deploy-5.18.nn/rehearse.sh. DS = devsnap105.sh and DR = devres105.sh, both in …/scratchpad/b105/rh/.

Nothing in R5, R4, the wrappers or the 104/105 deploy scripts has changed since a7b52e7, the record run's commit (empty `git diff a7b52e7 HEAD`). The pins:
- deploy-5.18.105.sh: EXPECTED_PLUGIN_COMMIT=7835906, BASELINE_COMMIT=994d660.
- deploy-5.18.106.sh: EXPECTED_PLUGIN_COMMIT=bf29cbd, BASELINE_COMMIT=7835906, BASELINE_VERSION=5.18.105.

## 1. How the chain is built

**The guards (R5:34-88).**
- They refuse on a server path (R5:34-39).
- They require F105 to be pinned: short and full pin, and a T1_SIGNATURE (R5:40-48). The helpers rh105q.py and rh105-ref.php must sit beside R5, rh105q.py must parse, and there must be no `__pycache__` (R5:49-51).
- The static verbs guard must read `lk=1 qftool=0 wrapper=0 verbs=0`, and its three planted controls must each be refused (R5:55-78).
- deploy-5.18.104.sh must be pinned to the BASELINE that 105 names (R5:80-83).
- Both wrappers must be committed and equal to HEAD (R5:85-88).

**The cut (R5:91-135).** It works on H104_HARNESS105=$H105_DIR/../deploy-5.18.104/rehearse.sh, with a Python step written to H105_PREFIX:
- The anchor is exactly `echo; echo "== 104-10. what the rehearsal left behind, and what it never reached =="`. It occurs once (R5:97-100), at index > 900 (R5:101).
- The last section heading before the anchor starts with `echo; echo "== 104-7c. the controls of every part` (R5:103-104).
- Its last non-empty line is `fi   # LIVE104`, and that line occurs once (R5:105-108).
- The last `check` line in that body carries `  "7c16 `, once in the whole cut (R5:110-113).
- **The re-point (R5:118-125).** The line `H104_DIR="$(cd "$(dirname "$0")" && pwd)"` becomes `H104_DIR="$(cd "$(dirname "$0")/../deploy-5.18.104" && pwd)"`. Asserted:
  - the old line occurs once;
  - the next line begins `H104_ROOT=`;
  - more than 60 newlines come before it;
  - after the replace, the new line is there once and the old one nowhere, with the length arithmetic.
- 29 needles, each the start of exactly one line (R5:126-130).
- The prefix is then sourced and removed (R5:133-135).
- Afterwards R5 asserts 64 functions with `declare -F` (R5:136-143), 46 variables non-empty (R5:144-148) and `LIVE104=yes` (R5:149), and sets `FAIL_PRE105=$FAILN` (R5:150).

**The chain's depth.** R4 cuts R103 before 103-10 (R4:129-167), and the same pattern goes down to R95, which cuts R94. R94 cuts RQ at RQ's first stage-A section (R94:6-7, 49-56). RQ builds the sandbox: `SB="$(mktemp -d)"` and the EXIT trap `cleanup` (RQ:134-137).

**What the chain leaves when 105's sections begin (R5:12-13).** 5.18.103 serves, 5.18.104's added files are on disk, no allKeys() key is set, and 089–091 are in the ledger.

**How 106 should read 105 "up to its last section".** The last section is 105-10. Its anchor is the exact line at R5:1054:

`echo; echo "== 105-10. what the rehearsal left behind, over 104's records and 105's alike =="`

It occurs once, at index 1053 (> 900). The section before it:
- Heading: `echo; echo "== 105-7. the weakened copies, each caught by the check it weakens; the redeploy =="` (R5:1002).
- Last non-empty line: exactly `rbx105 "105-7e"` (R5:1052), which occurs once.
- Last `check` line in the body: R5:1050 (label `"105-7d LM-ON made to expect…`), which occurs once.

105-7 has no `fi   # LIVE…` line, so 104-7c's assertion of that line cannot be copied.

**The re-point for 106.** It targets `H105_DIR="$(cd "$(dirname "$0")" && pwd)"` at R5:37, which is unique; the next line, R5:38, begins `H105_ROOT=`. Only 36 newlines come before it, so **105's "> 60 lines (in the guards)" bound would fail** and must be adapted.

**Needles for 106.** All of these start lines in R5:
- functions: `run105() {`, `run105c() {`, `run_line105c() {`, `fresh105() {`, `tidy105() {`, `installed105() {`, `left105() {`, `nogo105() {`, `checkgo105() {`, `weak105() {`, `anc105() {`, `aftx104() {`, `aft105() {`, `rbx105() {`, `expect105() {`, `onall105() {`, `lk105() {`, `lkmap105() {`, `counts105() {`, `digest105() {`, `dsnap105() {`, `drest105() {`, `q105() {`, `kv105() {`, `ref105() {`, `copy_back105() {`, `after_back105() {`, `last105() {`, `jset105() {`, `scratch105() {`, `shl105() {`;
- variable lines: `DEPLOY105=`, `OUT105="$SB/out105"; mkdir -p "$OUT105"; STATE105=`, `R105=`, `H105=`.

The cut also removes helpers that 105-10 defines: `uo105`, `tl105` (R5:1067-1068), the two-line `sweep105()` (R5:1077-1078), and `sweep104_105` (R5:1112). 106 must define its own, or extract them the way R5:1094-1111 extracts 104-10's one-liners.

## 2. The sandbox

**Container and paths.**
- `MOUNT="$SB/mount"; PLUGINS=…/ucrm/data/plugins; PD="$PLUGINS/$P"; DATA="$PLUGINS/.$P-data"; VAULT="$PLUGINS/.dishnet-sudan.vault.json"` (RQ:205-206), with `P=dishnet-hybrid-sudan` (RQ:81).
- `IN_CONTAINER=/data/ucrm/data/plugins/$P` (RQ:208). `PDD104_IN` is the data folder in the container (R4:441).
- The repository: `REPO="$SB/repo"`, a clone of $R (RQ:148-149).
- Records: `RECS="$SB/records"`, with the quarantine record at `$RECS/dnb-stray-quarantine/quarantine.env` (RQ:872). `PHPBIN` (RQ:931).

**Stand-in docker.** `$SB/bin/docker` (RQ:423-506) is strict: exec with -d/-i/-u/-e, cp, logs, `ps -aq`, and inspect with exactly the known formats; anything else exits 99.
- It maps `/data/*` to `$MOUNT` and logs every call to `$SB/docker.log`.
- Its container table `$SB/containers` holds `ucrm` (`/data=$MOUNT`), unms and unms-postgres (RQ:419-421).
- `-u` is honoured through setpriv once `$SB/.server-owner` exists (RQ:472, 926).
- Later releases edit the stub with asserted Python edits: R94:153, R96:526/554, R97:414/432, R98:486, R99:804/822/853, R100:289.
- `owner104` reads the book's owner (R4:442). `setcfg KEY VALUE` runs the installed set_config.php through docker exec, with no -u and no clock (RQ:571-573).

**uCRM fake.** `U99="$SB/ucrm99"` with rh99-ucrm.php (R99:730), served on `UPORT99` (R99:772). Requests are logged to `$U99/requests.log` and `limits.log`.

**Evolution fake.**
- `EVO_URL=http://127.0.0.1:$EPORT/rh-evo`, router `$SB/evo/router.php`, which logs METHOD URI to `$SB/evo/requests.log` and answers `[]` (RQ:288-294). `evo_calls` (RQ:604).
- R4 adds two asserted edits: voice-note bytes (R4:1490) and send answers with key.id while `rh104-send.on` exists, a file left on (R4:1515-1530).
- 106's "Evolution refuses with 400" will need a new asserted edit of this router.

**Stand-in plugin page and website.**
- The plugin page is `$SB/web/public.php` at `PLUGIN_BASE=http://127.0.0.1:$WPORT/public.php` (RQ:209-210). Triggers are files in `$SB/web/` that `$spend()` unlinks (RQ:228).
- **.redirect105** is one asserted insertion before `$page = $_GET['page'] ?? '';\n` (R5:422-433):
  - Asserted: the anchor once, no `redirect105` yet, and `'.redirect104'` once.
  - It adds a `rh105_probe` page, and a 302 on customer_login only while manifest.json holds `"5.18.105"` and `.redirect105` can be spent.
  - Its control (R5:434-438) uses `v1read102` (R102:1406) and `spent99` (R99:451).
  - It is planted at R5:993 and R5:1030, and removed at R5:999 and R5:1031.
- The public website is `WEB_OK=http://127.0.0.1:$WPORT94/ok` (R94:206-208). `pubget` is at R94:187.

**Clock.**
- An LD_PRELOAD shim: `CLKF104="$H104/clock.off"`, `CLKSO104`, `CLKPRE104` (R4:1221-1227).
- `clk_set104`, `fnow104`, and `clk_to104`, which only moves forward and logs `clock:back:` to anchor-missing (R4:1229-1236). `kat104` and `kday104` give Kampala times (R4:1237-1238).
- The fake `date` lives in `$SB/datebin` (R94:215-221); `FAKE_TODAY=$AFTER94` (R94:227).
- In the record run the clock had reached Kampala 2026-12-23 to 12-25 by 105-pre and 105-5 (record4.log:2856, and the after-only lines). Its exact final offset is **NOT ESTABLISHED**.

**Numbers.**
- The range is `+2567001040NN` (rh104-live.php:111, rh105-ref.php).
- 105's own: PQ105=015, PSTOP1=094, PLN03=095, PLEAD=096, PLN04=097, PSTOP2=098 (R5:440).
- Suffixes 104 uses: literal 11-14, 21-22, 24-29, 50-51, 60-71, 90-93; computed 30-49 (R4:1479), 52-54 (R4:2832), 60-89 (R4:1821), 70-87 (R4:1937/1955/1971).
- Not seen in any literal or computed form: 00-10, 16-20, 23, 55-59, 99. **That they are free is NOT ESTABLISHED.**

**Pattern files.**
- `NAMES104="$SB/names104.pat"`, 0600 (R4:598-600, with appends at R4:1479 and 1821); R5 appends its six numbers (R5:441).
- `$R105/phones105.txt` (R5:442-445).
- `FORMS105="$R105/forms105.pat"`, 0600, written by `q105 phones4`: each number in four forms plus its last nine digits. The record run had 77 numbers and 385 lines (R5:446-448; record4.log:2825).
- `SCAN96` (R96:570).
- `NM105F` and `key.pat` are made only in 105-10 (R5:1076, 1122), so they are cut.

**105's own folders (R5:180-183).** `OUT105="$SB/out105"`, `STATE105=$OUT105/state-5.18.105.env`, `LKF105=$OUT105/lk-key-5.18.105`. `R105="$SB/r105"` with logs, mutants, sheets, exec, scan, vault, tok, ucrm, evo, case, drive and pre. `H105="$SB/rh105"` with v (1000:1000, 0700) and ref, the pin's lib/ and tools/ from Git (R5:192).

## 3. Running the real script, and asserting

**`run105 [--answer W] [--hold FILE] [--script F] [--env K=V]… OPTION…` (R5:221-241).**
- Before the run it clears docker.log and the uCRM logs, and bumps the counter `$SB/runs` to give a 3-digit tag.
- It keeps per run:
  - the vault before and after: `$R105/vault/run-TAG.{before,after}`;
  - the exec record (FAKE_EXEC_LOG), the /tmp scan (FAKE_TMP_SCAN/HITS) and the token hits (FAKE_TOK_HITS);
  - the Evolution count `evo/run-TAG.n` ("calls cron|nocron");
  - `ucrm/run-TAG.{req,lim}`;
  - the output, in `$R105/run-TAG.txt` or `mutants/` when `R105_MUTANT_OUT=1`;
  - an optional `$REHEARSE_KEEP/run105-TAG-ANSWER-…txt`;
  - "unknown option:" recorded in `$SB/bad-runs`.
- Its environment: `PATH=$SB/datebin:$SB/bin`, `FAKE_TODAY`, `DNB_OUT=$OUT105`, `DNB_RECORDS=$RECS`, `DNB_WEBSITE=$WEB_OK`, `GUARD_SECONDS=3`, `DN_DATA_DIR`, `DNB_SALES_LOG_DIR`, `DNB_REPORT_DIR`, and `--plugin-base`.
- **The word is typed** with `printf '%s\n' "$answer" | SHELL=/bin/bash script -qec "$cmd" /dev/null`, a pseudo-terminal (R5:232-234). With `--hold`, the word waits up to 6000×0.05 s for FILE. `R105_STREAM` tees the live output (used by lkb1run105 at R5:716-727 and g4run105 at R5:769-791).

**The other run helpers.**
- `run105c` adds the clock (R5:242).
- `run_line105c WORD LINE` runs a command line exactly as a summary printed it, through `bash -c` (R5:243-256). `printed` pulls that line (R95:196) and is used at R5:963-964.
- `fresh105` keeps state-*, rollback-state-*, ql-key-*, cih-rows-* and lk-key, moves *.log and cash-*.txt aside, and deletes everything else, backup-* included (R5:258-261). `tidy105` (R5:262-266).
- The script's own logs are `LOGW105`: `$OUT105/NAME-$DATE_TS98.log` (R5:184).
- 5.18.104's script runs through `run104c` (R4:1252).
- The tool runs through `sf104c [--tty WORD]`, whose exit code goes to `$R104/sf.rc`, read by `sfrc104` (R4:1253-1264).
- The quarantine runs through `qrun104c --check` (R4:2658).

**Assertions.**
- `check GOT WANT LABEL` adds to PASS, or adds to FAILN and prints `  FAIL label (got…, want…)` (RQ:141). GOT is a `|`-joined composite built from:
  - `has` (RQ:142);
  - `fails`, which counts the script's own `^  FAIL  ` lines, two spaces (RQ:144);
  - `okline`/`failline` (RQ:645-657) and `noteline` (R95:203);
  - `went105`, `quiet105`, `nogo105` and `checkgo105` (R5:270-276);
  - `aft105`, which judges an --after-only by `q105 stcmp` against `expect105` (R5:297-322);
  - `rbx105`: ROLLBACK typed, the quarantine's --check after it (R5:323-331);
  - `installed105` (R5:277-286).
- **Failures recorded outside `check`:** `$SB/anchor-missing`, `$R104/wait104.fail` and `$SB/bad-runs`. These are judged only in 105-10i (R5:1166-1168), which is cut, so 106 must judge them itself. The baselines are `AM0_105` and `WF0_105` (R5:156); the other baselines are `ST0_105` (R5:157) and `EVO0_105` (R5:449).
- **The tally (R5:1174-1175).** It prints `RESULT: $PASS passed, $FAILN failed — of them $FAIL_PRE105 before …` and the exit status is `[ "$FAILN" = 0 ]`. The record run gave 2,710 passed and 3 failed, all three inherited: 98-0, 99-0 and 100-0 (record4.log:895, 1218, 1571, last line).

## 4. Weakened copies (R5:332-419)

- `anc105 KEY ANCHOR REPLACEMENT` writes `$SB/anchors105/KEY.{a,b}` (R5:334-335). `blk105` cuts a block through its first `  fi` (R5:336-345).
- `weak105 NAME KEY…` copies DEPLOY105 to `$REPO/scripts/.NAME.sh` and, for each key, asserts in Python:
  - the anchor is there once;
  - the replacement count and the length arithmetic hold;
  - and either the anchor is gone or it is contained in the replacement.
- A miss appends `weak105:KEY`, and a copy that fails `bash -n` appends `weak105:NAME:syntax`, to anchor-missing (R5:346-362).
- **16 anchors**: base103, held, heldset, part, lkb1, clearsw, lkrows, mbase, ugsig, vng4 (5.18.104's block), clear, noca1, qfca5 (5.18.104's block), v1clr, nokeep and lmold (R5:366-407).
- **The control (ANCC105):** every anchor occurs exactly once in DEPLOY105, and each replacement differs (R5:410-419; record4.log:2823).
- **How a copy is judged:** `R105_MUTANT_OUT=1 run105c --script "$M105" …`. The check asserts that the guarding text is gone or that the run went on, and the copy is then deleted with `rm -f`. The wrappers are `nogo7a105` (R5:1005-1008) and `dcopy105` (R5:1013-1017); each deploying copy is followed by `rbx105` and `drest105 L2`.

## 5. The state at the end of 105, and 106's first section

**Before 105-10 (R5:1003-1052).**
- The book is put back at L2 (R5:1003).
- 105-7c: `--redeploy` with DEPLOY typed, PASSED (R5:1045-1047).
- 105-7d: a weakened --after-only.
- 105-7e: `rbx105` (R5:1052).

**Serving.** **994d660 (5.18.104)**, every file 5.18.104's (record4.log:2980, 2995).

**The ladder: L2** (pre L2, R5:632-635; record4.log:2859).
- On: report (`sf_report_ran_at`), ack (`wa_voice_ack_enabled`), tracking, list, voice-measure and chase (`quote_chase_enabled`): steps 1–6.
- Off: agent-cc and offers. voice-dry-run is not skipped. Nothing is held in either config.json.
- persona-confirmed is set (pre-e1).
- rbx105 asserts every key byte-identical across the rollback.

**The ledger.**
- The ack ledger reads pass|empty.
- One quote claim settled sent: the chase (pre-e4, R5:614-621).
- PQ105's STOP is an active opt-out (pre-e5).
- 104's planted offer claims were removed (pre-a0, R5:515-540).
- The STOPs from LN-01 and 105-6g were undone by `drest105 L2`.

**The followup_* settings.** 5.18.104's --check read `followup_enabled`, `followup_auto_send` and `followup_daily_cap` as **unset** (reckeep4/run104-603-none-deploy-5.18.104--check.txt, QF-N1). No 104 or 105 harness file writes them (grep). Unset reads off, through `empty($config['followup_enabled'])` (7835906:cron/followup_send.php:48). Unset at the end of 105 is inferred from that grep, not measured there.

**The quarantine.** The last `qrun104c --check` PASSED after 105-7e (R5:329-330; record4.log:2981).

**Leftovers.**
- `$RECS/dnb-5.18.104` is a symlink to OUT104 (R5:560).
- `OUT105`: state, rollback-state, lk-key (0600), cih-rows, the logs.
- `$R104/dsnap/105-{L1,L2,L3,L4,g4pre}`.
- `$H105/{inst,scr-ln02..04,inst-ln02,crm,v}`.
- `R105/case/*`, which holds backups.
- `.redirect105` is absent, but its branch stays in public.php.
- act104 and pin104 were removed (R5:845).

**106's first section.**
1. `fresh105; run105c --answer DEPLOY --redeploy`: PASSED, `live=$P105`, LK-CA1 ok (as R5:858-860).
2. `aft105 LABEL $L2ON105`, which makes the copy 30 min old (R5:293, 317-322).
3. `qrun104c --check`: PASSED.
4. `ln -sfn "$OUT105" "$RECS/dnb-5.18.105"`, because 106's PREV_STATE is `${DNB_RECORDS}/dnb-$BASELINE_VERSION/state-…` (deploy-5.18.106.sh:1538).
5. `sf104c off chase`: steps 1–5 on, checked with `lk105`'s `on`.
6. The interim, through the real writers:
   - `followup_enabled 0` via set_config as the owner (`-u $(owner104) -e DN_DATA_DIR=$PDD104_IN`, the clock);
   - `sf104c --tty yes on chase` (as pre-e2, R5:601);
   - `sf104c --check chase`.
7. Before 106's deploy:
   - `sf104c off chase` first;
   - **then** `followup_enabled 1`, best run as FE-G1 prints it (deploy-5.18.106.sh:8008) through a `run_line`-style helper.

   The order is in deploy-5.18.106.sh:505-516 and docs/75 §L.8.

**Caveats for 106.**
- The cron stand-in breaks unless `live=$P104` (R4:2672-2691), so it never runs during 106's rollbacks.
- 5.18.104's rehearsal's `aftx104` and `cron104_*` expect 5.18.104 to be serving.

## 6. The development runner pair

**DS (devsnap105.sh).** Invoked as `SNAP105=<empty dir> bash -c '. devsnap105.sh' <worktree>/…/deploy-5.18.104/rehearse.sh`, so `$0` is 104's rehearsal (DS:1-11).
- It cuts on the same anchor, with a lighter assertion set (DS:19-36) and the re-point carrying a comment (DS:29).
- It sources the cut and records `chain.start`, `chain.end` and `chain.counts` (DS:37-43).
- It saves the trap, the options and shopt, the functions, and every variable except the specials and the readonly ones (DS:45-65).
- It records servers: processes with ` -S ` whose cmdline or fds name `$SB`, keeping argv, env, cwd, out, err and owner. It then kills them (DS:66-85).
- It tars `$SB` and the new /tmp entries with numeric owners (DS:87-90).
- The snapshot snap1: chain 2026-11-09 05:16:25 → 11:40:21Z, 2,557 passed and 3 failed, four servers (web, website, ucrm99, evo), files.tar 780 MB.

**DR (devres105.sh).** Invoked as `SNAP105=… bash -c '. devres105.sh' …/deploy-5.18.105/rehearse.sh` (DR:1-2).
1. It refuses if the paths already exist, then untars (DR:11-17).
2. `devres_qrec` re-records the quarantine's identities for the new inodes: `Q_DATA_KEY`, `Q_KEY_BEFORE`, `Q_ENTS_AFTER` and `Q_KEPT_CTIME` (DR:24-51).
3. It sources the variables and functions, re-spawns the servers and maps every `*PID*` variable and `PIDS` to the new ones (DR:53-77).
4. It restores the trap, wrapped so `devres_keep` runs first and saves r105, out105, rh105, out104, records, and the r104 files newer than the chain (DR:79-92).
5. `devres_forward` requires `$R` to be this worktree on `$BRANCH`, clean, and fast-forwards `$REPO` to HEAD. If the clone writes 8-character commits it sets `core.abbrev 7` (DR:100-122). That is trial 6's stop: trial6-start1-abbrev.log.
6. It runs R5 minus the block from the line beginning `H105_PREFIX="$(mktemp)"` to the line exactly `rm -f "$H105_PREFIX"`, each asserted once (DR:123-135).

**The shortcut hooks.** `REHEARSE_SHORTCUT105` replaces pre-d and pre-e with `drest104 s2` and `mainend`, each followed by `off agent-cc` (R5:563-577), and lowers 105-10h's optout floor from 2 to 1 (R5:1164). It mattered only while 105's own sections ran; trials 1 and 4p used it.

**What a devsnap106/devres106 pair must change.**
- `SNAP106`, also in the skip list.
- `$0` becomes 105's rehearsal.
- The anchor becomes 105-10's (R5:1054), with the re-point of R5:37 (and its new line-count bound) and the assertions in §1.
- The body cut becomes `H106_PREFIX="$(mktemp)"` … `rm -f "$H106_PREFIX"`.
- `devres_keep` adds r106, out106 and rh106, plus the r105 and out105 files newer than the chain.
- `devres_qrec` must be re-verified against a record that now carries 105's extra checks.
- The snapshot must be taken in the trial worktree. Expect roughly 9h50m: the record run reached 105-7e's quarantine check at 05:41:29, against a start of 19:53:46.
- Any `REHEARSE_SHORTCUT106` would be new.
- Slips in the current pair's headers: DS:1-8 still speaks of devres104, and DR:1 says it is "derived from devres105.sh".

## 7. Runtime

These come from file times (birth and mtime) and from the run-file times in reckeep4. record4.log itself carries no timestamps.

| Run | Start → end (UTC) | Length |
|---|---|---|
| Record run (record4.log) | 2026-11-14 19:53:46 → 15 Nov 05:41:41 | about 9h48m |
| …chain through 104-7c (last 104 quarantine check) | → 04:16:09 | about 8h22m |
| …105's setup and sections (first 105 run 04:16:22, last 05:41:21) | 04:16 → 05:41 | about 1h25m |
| …105-10 | | about 12 s |
| Dev trial 1 (shortcut) | 13 Nov 17:57 → 19:07 | 69 min |
| Dev trial 2 | 19:09 → 20:26 | 77 min |
| Dev trial 3 | 20:28 → 21:43 | 75 min |
| Dev trial 4p (shortcut, partial) | 22:14 → 22:21 | 7 min |
| Dev trial 5 | 23:48 → 14 Nov 01:12 | 84 min |
| Dev trial 6-start1 (stopped at abbrev) | 18:13 → 18:15 | 2.5 min |
| Dev trial 6 | 18:17 → 19:47 | 90 min |

Results: trials 3, 5 and 6 and the record each read 2,710 passed and 3 failed (all inherited); trial 1 read 2,680/20; trial 2 read 2,707/5.

Files are in /home/user/dev105/scripts/harness/{deploy-5.18.105,deploy-5.18.104,quarantine-stray-store}/ and /tmp/claude-0/-home-user-dishnetuganda/24e2e11c-566f-5aa4-a11b-ebc146471ab5/scratchpad/b105/rh/ (devsnap105.sh, devres105.sh, record4.log, reckeep4/, snap1/).