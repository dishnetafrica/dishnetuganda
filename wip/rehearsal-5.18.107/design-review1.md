# The 5.18.107 rehearsal — review 1 of design-skeleton.md (27 Nov; two read-only critics)

## The mechanics critic (RM)

MECHANICS critic: wip/rehearsal-5.18.107/design-skeleton.md, checked against S = scripts/deploy-5.18.107.sh at d790edf, R6 and the chain, and 771a3c1/bf29cbd. I changed no files.

RM1 — blocker. The stand-in web server is not on the rehearsal's clock, so S27 is false.
- Claim: every boot through public.php runs in that server. That covers V1 in every run and S17's "one boot of the stand-in". Each boot therefore stamps 092's `applied_at` (SQLite `datetime('now')`) and the migration.log lines with the kernel's time. COPY_AT comes from the touch shim and QF_COPY_UNIX from `time()` in qf_php base (S:7256) under run106c's LD_PRELOAD, so both are on the clock. R6 asserts that clock is at least 1 h ahead, and its own comment says about 40 days.
- What follows:
  - (a) Every plain first deploy prints MG-CA1's dated note "092 was applied before this copy began" (S:8435: `absent|au|cu` with `au<cu`). That contradicts S13 and S19 and blurs S18's contrast.
  - (b) S17's order `applied_at < COPY_AT` holds even if the touch plant never fired, because V1's later boot is wall-clock too. So the case observes the wrong thing, and its weakened copy (the bound) would "fail" on a plain deploy as well.
  - (c) S19's `applied_at ≥ QF_COPY_UNIX` cannot be reached by any stand-in boot.
- Evidence:
  - quarantine-stray-store/rehearse.sh:285 starts the server and :930 restarts it as uid 1000, both without LD_PRELOAD.
  - The only clocked php -S servers in the chain are 104's webhook servers (deploy-5.18.104/rehearse.sh:1543, :2079); nothing later restarts the stand-in.
  - devres106.sh:64-71 brings it back with its captured environment (scratchpad b106/rh/trial2.log:37).
  - The `applied_at` default is bf29cbd lib/MigrationRunner.php:59, written at :244-247.
  - rh104-clock.c:4-7 moves the time calls, `datetime('now')` among them, only in processes started with LD_PRELOAD.
  - R6:580-584 and :612-613.
- Fix: in 107-infra, kill $WEB_PID and relaunch the same `php -S 127.0.0.1:$WPORT -t $SB/web` as uid 1000 with `LD_PRELOAD=$CLKPRE104 RH104_CLOCK_FILE=$CLKF104`, in the form of 104:2079. Re-register it in PIDS/WEB_PID.
  - Control: a probe page answered before the boot echoes `time()`. It must read near fnow104 after the relaunch, and the wall clock before it (the control on the control).
  - Make the relaunch idempotent, so it works in either shell: a snapshot restore brings the old environment back.

RM2 — blocker. The v9 settings text cannot be planted (S20 step 4; 107-3 "settings text v9"; §T.11 "v9 over v9's bytes").
- Claim: the script compares the trimmed text's sha256 prefix with a literal, and v9's text is not in the sandbox.
- Evidence:
  - The literal is `SA_TXT_V9="fca936af7760"` at S:8028. It is read by the sa mode (S:7270-7292) and by rb_pre107 (S:8945, :8996-8998).
  - v9's 5,617 bytes exist only on production. The only repository mentions are docs/07-change-control.md:17059 and the design doc; the hash otherwise appears only as that literal.
  - A preimage of a 48-bit prefix is not feasible.
  - So every run of the real script reads "NOT v9" / "not v9".
- Fix:
  - Drop step 4 from 107-pre, saying so.
  - Produce the v9 branch with a pass-control copy: `anc107` replaces `SA_TXT_V9="fca936af7760"` with the prefix of an RH107SECRET- text saved through 5.18.106's real set_config. Assert that the copy differs only in that line.
  - Run the copy in 107-3 (the SA note) and once for rb_pre107 step 6.
  - Every other run expects "not v9". 107-8's "settings text not v9" case then costs nothing extra: fold it into the off-no-marks run.

RM3 — blocker. S19's plant window contradicts itself.
- Claim: S19 says "held at Type DEPLOY as in S18 (after QF_BASE, before the tar)". But the DEPLOY question comes before QF_BASE.
- Evidence:
  - The DEPLOY question is at S:13852. QF_BASE is taken after it, inside qf_keep_and_baseline (S:13861, then LK-B1, `sleep 6`, then QF_BASE at S:7576-7578).
  - A boot at the hold therefore gives `applied_at < QF_COPY_UNIX` by at least 6 s. That is S18's case, with the dated note.
  - The only container call between QF_BASE and the tar is deploy-hybrid's `read_live 3` (deploy-hybrid.sh:86), a `docker exec ucrm cat …/.deployed-commit`.
- Fix: add hook H4 (`.boot107`), a one-shot hook in `$SB/bin/docker` (generated at quarantine root :426).
  - Insert it before the line beginning `row() { grep "^$1|" ` (:429), after 104's `.rbfail104` and R6's A8 `.ujfail106`. Assert the order as R6:723-772 does, then `bash -n`.
  - It fires on exactly `exec ucrm cat /data/ucrm/data/plugins/dishnet-hybrid-sudan/.deployed-commit` while `$PD/.deployed-commit` still names P106.
  - It boots `SqliteStore::create($DATA)` from `$PD/lib` with `setpriv --reuid 1000` php. That inherits the run's LD_PRELOAD (clocked), and uid 1000 avoids a root-owned WAL (rh106-seed.php:15-16).
  - It counts itself, then passes through.
  - Arm it at the DEPLOY hold (csb1run107). Assert the hook fired once and `applied_at ≥ QF_COPY_UNIX`.

RM4 — major. H3 cannot produce "put-back after a rollback that did not verify, with 5.18.106's tools installed".
- Claim: this case, its Q-on and K-on variants and their three weakened copies need the copy to happen and then fail to verify. An index lock prevents the copy.
- Evidence:
  - With the lock, do_rollback's `git checkout` fails and it returns 1 before deploy-hybrid (S:13832). 5.18.107's tools stay installed, which is only the separate "5.18.107's tools remain" case.
- Fix: add an `rbfail107` docker hook in the shape of `.rbfail104` (deploy-5.18.104/rehearse.sh:2505-2520), re-keyed to P107→P106.
  - The first `.deployed-commit` read naming P106 after one naming P107 answers `0000000`, once.
  - deploy-hybrid then exits 1 after copying (deploy-hybrid.sh:144-150), with no 120-s rc=2 wait (S:13834).
  - For H3 in a `--rollback` run, plant `$REPO/.git/index.lock` directly before the run; R6:780-786 is the control. A7's `.redirect106lock` fires only on a V1 request (R6:710).

RM5 — major. A6 is a gate, so reading the MEASURED values "in 107-4" is too late. This also answers O6.
- Evidence:
  - A6 runs in every deploy and `--check` (MODE=deploy; `--check` only sets CHECK_ONLY, S:1804), at S:13100-13145, before qf_stage (S:13661).
  - It stops NO-GO on any mismatch with the interim literals (S:1532, :1538-1539, :13136-13142). 107-2's first case would refuse, not 107-4.
- Where the values print (O6): A6 prints them before its verdict, on plain console lines; the cash sheet is not needed.
  - UG_PIN_SIGNATURE: S:13128, "pin 5.18.107: <UG_PIN>".
  - EP_NOAI_PIN_UG: S:13132, "pin …: <NA_PIN_UG> (Uganda), <NA_PIN_SS> (South Sudan)".
  - EP_NOAI_DONE: S:13131, the live line "live 5.18.106: <NA_LIVE_UG> (Uganda), <NA_LIVE_SS>"; NA_PIN_SS should equal it too.
  - R13 shows UG_NOW and NA_NOW_UG only inside its ok or bad text (S:15037-15046). RB reads NA_RB_* only after a rollback (S:15528-15530).
- Fix: 107-pre runs one `--check`, parses those four lines and records them for the derivation. If any differs from the interim literal, stop the rehearsal there and say so. R13 and RB then serve as cross-checks.

RM6 — major. There is no V1 trigger for 5.18.107.
- Claim: 107-8's V1 cases (no mark; the `--redeploy` ending in V1; control 0 and its weakened copy) need V1 to redirect after a 5.18.107 copy. Nothing in the chain does that.
- Evidence: R6's `.redirect106` fires only while the installed manifest contains "5.18.106" (R6:710). 771a3c1's manifest.json:8 says "5.18.107".
- Fix: add H5, `.redirect107` with an `rh107_probe` page.
  - Insert before `$page = $_GET['page'] ?? '';`, after `.redirect106`'s line, asserted as R6:702-721 does, and probe-polled for OPcache.
  - Add it to cleanup107 and to 107-10's trigger sweep.

RM7 — major (O5). A typed rollback does not give "a fresh book state" for another first deploy.
- Evidence:
  - The rollback writes nothing (S:13901-13903). 092 stays in the ledger and its file stays installed: deploy-hybrid never deletes (deploy-hybrid.sh:24-27; S:12392-12395). The next MG-G1 reads "present", so the next deploy is a redeploy.
  - Each typed rollback with the switch off also costs rb_pre107's 125 s (S:8963).
- Fix: reset between first-deploy variants without a rollback:
  1. `install_commit $B106` (root:297; assert it is defined; chown 1000 as root:929 does);
  2. `drest104` of a snapshot taken at the end of 107-pre (104:1030-1042);
  3. `tidy107` of the state file, LK key and backups (shape of R6:323);
  4. `qcheck106`;
  5. assert MG-G1 absent and no 092 file on disk.

  Nothing may boot the store between the restore and the deploy (RM13).

RM8 — major. Deploy-run-only states have no mechanism.
- Claim: §T.11 places "line removed", "another md5" and "rename" between V1's boot and mg_after inside the deploy run. The skeleton names no hook for that.
- Evidence: see the fix lines.
- Fix:
  - (i) Use the "log made unwritable" variant §T.11 allows: `chmod 0444 $DATA/migration.log` before the run. The boot's `@file_put_contents` fails silently (bf29cbd MigrationRunner.php:313-319) and the run writes `MG_CA2_SEEN=0` (S:8459-8461).
  - (ii) Plant PARTIAL and WARNING lines before the run: mg_after greps the whole log (S:8451-8453).
  - (iii) Plant rename, other md5 and altered bytes after a PASSED deploy and judge them with `--after-only`.
    - MG-CA1, NA-CA1 and MG-CA2 run there (S:14404-14456).
    - R7's second judgement, against the deploy's record, fails on the other md5 (S:14877; db_mig107 S:12045-12049).
    - After 092's bytes are altered, the `--after-only`'s own V1 boot writes the real checksum WARNING (MigrationRunner.php:192-196). That is a real writer, not a fixture.
  - Only S16, the unwritable-log deploy and "first deploy with WARNING only" (with its md5 variant) need runs of their own.

RM9 — major. ME-CA1's poll cases need a writer during the poll; the skeleton has none.
- Claim: "(L,−1) with a later cycle" and "re-dispatch between two reads" need master_schedule to change after the first read.
- Evidence: the poll is S:8380-8386.
- Fix: give run107 csb1run's watcher form (R6:883-892), keyed on "ME-CA1 waiting up to", whose action writes master_schedule through rh107-seed.
- Also, S3/S26's "at least 360 s of real time" is refuted:
  - The poll measures its bound with `date -u +%s` under the run's LD_PRELOAD (S:8381-8382), and the clock library reads the offset file on every call.
  - The same watcher can move the clock forward by at least the bound (clk_to104). The loop then ends after one `sleep 5` with end=bound.
  - me_after_poll judges entries only, never "now" (S:8282-8294), so the S:8336 text is unchanged.
  - `sleep` itself is real time and is not moved.

RM10 — major. H1 must be inserted before the boot.
- Claim: the stand-in boots at its top, before any page logic, and every chain trigger sits after that.
- Evidence:
  - root:224-226 loads the libs and calls `SqliteStore::create($dataDir)`, which runs the migration runner (SqliteStore.php:233-248).
  - Every chain trigger is at `$page = …` or `if ($page === 'api')`, after the boot (R6:707; 94:169).
  - A 503 placed there would apply 092 first.
- Fix:
  - Anchor on the line `$store = SqliteStore::create($dataDir);` (exactly once) in `$SB/web/public.php`, generated by root:222.
  - Insert the `.noboot107` 503 block and a pre-boot probe (`rh107b_probe`) immediately before the preceding `foreach (['bootstrap_data', …` line, so that probing never applies an on-disk 092. Assert the order.
  - Poll the probe for OPcache (R6:674-679, :717).
- O2 answer:
  - V1 gets 503 with 0 redirects, so the script prints `bad "V1 … → 503 after 0 redirect(s)"`. There is no automatic rollback: that fires only when redirects ≠ 0 (S:13934), and http() has no `--retry` (S:1844).
  - All 13 ledger reads answer `mig092=0`, so MG_WAIT=absent and MG_SAY=na (S:8489). The lead is S:16148-16150 and the verdict line S:16179.
  - Add to S16's expectations: R7 FAILs, "the migration ledger DID NOT MOVE" (S:14874, S:12058).
  - channels.php after mg_after uses raw PDO (771a3c1 tools/channels.php:34-37), so nothing later in that run applies 092.

RM11 — major. H2 can be inserted, but four details must change.
- Anchor: in the generated `$SB/datebin/touch` (R6:589-598), the line `if [ -n "${RH106_TOUCH:-}" ] && [ "${1:-}" = -c ] && [ "$#" -ge 2 ]; then` (R6:593). Insert inside that branch.
- (a) clk_to104 (104:1232-1235) is a function of the rehearsal shell, not available in the shim. Its arithmetic also takes `date -u +%s` as wall time, but inside the shim `date` runs under LD_PRELOAD and would double the offset. The shim must add 3 to the integer in `$CLKF104` and keep the file's mode.
- (b) "One boot of the stand-in" goes to the unclocked server (RM1). Boot with a direct php as uid 1000 (clocked, no root WAL), or fix RM1 first.
- (c) Record a fired counter: the order alone cannot prove the plant fired.
- (d) R6's A1 controls (R6:604-613) ran before this edit, so H2 needs its own. With the trigger absent the shim passes through; with it present the plant fires once and consumes it.
- O3 answer — holds. The script's only touch is stamp_copy's `touch -c` (S:13827), called once per installed $CHANGED file. deploy-hybrid.sh, git, tar and the stand-in docker call none. So the first shim call in a deploy run is stamp_copy's first file, after the tar (S:13880). In a `--rollback` run it is do_rollback's stamp_copy (S:13836).

RM12 — minor. S18's seed logs to the wrong file unless told otherwise.
- Evidence:
  - MigrationRunner's default log is dirname(migrations)/data/migration.log (bf29cbd MigrationRunner.php:51).
  - MG-CA2 reads `$PDD_IN/migration.log` (S:8451). `SqliteStore::create` passes `$dataDir/migration.log` (SqliteStore.php:247).
  - With the default path, stage R reads no OK line: `MG_CA2_SEEN=0`, not the PASSED shape.
- Fix:
  - Pass `$DATA/migration.log` as the runner's log (or boot through SqliteStore::create), as uid 1000 under the clock, and assert one OK line.
  - csb1run106 records sfrc104 as the action's exit code; csb1run107 must record the seed's own.
- O4 answer — holds. bf29cbd's runner globs `migrations/*.sql` on every boot (MigrationRunner.php:180-203) and logs `OK: {file} ({n} stmts, {ms}ms)` (:250). MigrationRunner.php and SqliteStore.php are identical at bf29cbd and 771a3c1: the only difference under migrations and these two files is the added 092. So the OK, PARTIAL and WARNING lines and the 7-statement count are the same whichever release boots.

RM13 — minor. S16's later `--after-only` must start with 092 still unapplied.
- Evidence: master106, sender106 and set_config also boot the store — through cron/master.php, PluginConfig's mirror to the store row (bf29cbd PluginConfig.php:364-379) and 771a3c1 set_config.php:425. So does sf104c.
- Fix:
  - Assert the ledger has no 092 just before the intended boot.
  - Prefer master106 as that "later boot": it is a real writer and is clocked.
  - Compute the trim marker's stamp from the ledger's `applied_at`, never from the clock.

RM14 — minor. S21 is right about the four places, with two corrections.
- Holds: NS-G1 reads places p, d and k and reports the store row s (S:6420-6423, S:7521-7534).
- (i) W.1's real writer is the switch tool's `set`, through the wrapper (S:7330-7332; 771a3c1 tools/sales_followup.php:103, :147-151), not set_config.
- (ii) Places p and d have no real writer in any release; saveOverrides writes only kyc_config.json and the store row.
- Existing helpers fall short:
  - rh106-seed's fx_file already takes any non-secret key (rh106-seed.php:164-176).
  - Its fx_store_keys takes only the three claim switches (:146-152).
  - dstdrop106 is bound to STATE106 (R6:1405).
- Fix: rh107-seed gets a store-row op for K, Q, W.1 and the text keys, and dstdrop107 works on state-5.18.107.env. After the "switch on, ROLLBACK typed" case, a fixture must clear the switch, since 5.18.106 has no writer for it.

RM15 — minor. S8's plant is not named.
- Evidence: the pin is unavailable when P3B staging fails (S:12526-12540; S:8863; `lk=nopin` at S:7446). The root docker's `FAKE_CP_FAIL` makes every `docker cp` fail (root:456), giving P3B_WHY "the copies could not be put into the container's /tmp" (S:12537).
- Fix: `run107 --env FAKE_CP_FAIL=1`. Assert P3B_WHY's text and record, without judging, the run's other lines that depend on docker cp.

RM16 — minor. S20 mostly holds; step 2 is unproven.
- Holds:
  - Steps 1, 3 and 5 work with the existing helpers: sf104c on chase worked while 5.18.106 served (R6:2208-2211), and qcheck106 is at R6:376.
  - 106-7z's end state allows a PASSED 5.18.106 `--after-only`: it PASSED at 106-7i10, after master106 at 7i9, and nothing changed afterwards (R6:2433-2441).
- Unproven: R6 never ran aft106 with chase on; every call uses L1ON106.
- Fix: run `aft106 … PASSED $L2ON106`; there `chase=judged` (on105, R5:307-309) accepts NOT_YET, which 5.18.106 prints as a note (deploy-5.18.106.sh:8442).

RM17 — major. Time (S26, O5).
- Sleeps in the script, all real time (`sleep` is not moved by the clock):
  - LK-B1, 6 s (S:7573), plus GUARD_SECONDS, 3 s (S:14240; set by R6:284), on each deploy that passes DEPLOY.
  - MG-CA1's wait, 12 × 5 s (S:8408-8413), only when 092 is never seen.
  - rb_pre107, 125 s (S:8963), only when the switch first reads off.
  - ME-CA1's poll, at least 360 s.
  - Avoid plants that make the container not answer: they cost 30 s in deploy-hybrid and 120 s more in the rc=2 waits (S:13834, :13878).
- Sleep totals:
  - As written: about 22 × 125 s, plus 2 × 60 s, plus about 25 × 9 s, plus 360 s — about 58 min.
  - Reordered as below: about 15 min.
- Per-run cost: 5.18.106's own sections (about 120 script-run call sites, 470 checks) took about 2 h 47 min in trial 2 (scratchpad b106/rh/trial2.log:574), about 1.4 min a run.
- Estimate for 107 (my estimate, from that figure): about 3–3.5 h for 107's sections reordered, 3.5–4 h as written. That is on top of the chain: about 9 h 48 min for 105's (docs/74:1834) and about 2.8 h for 106's.
- Orderings that cut time without weakening a case:
  1. 107-1's runs stop at BASE_DIFFERS (S:12399), before A6, so they are cheap.
  2. 107-2 and 107-3 refusals stop in stage A; NS-B1 stops before the copy, so no rollback is needed.
  3. Reset first-deploy variants by restore (RM7), not by typed rollback.
  4. Run one deploy per source of MG_CA2_SEEN (1, 0, nowait). Run master106 once after each, or every `--after-only` exits NOT YET (S:8273-8276, S:16176). Then run its `--after-only` cases, undoing each plant with keep/back.
  5. In 107-8, first run every "answer anything but ROLLBACK" case on one 5.18.107 deploy (they change nothing). Then the H3 case. Then the typed rollbacks, each followed by a `--redeploy` that also carries 107-9's assertions.
  6. Run the rbfail107 Q/K cases, their weakened copies and TD7 with the switch on, which avoids the 125-s sleep. A fixture has to clear K and Q afterwards anyway. Keep "off" for four runs only: off-no-marks, control 0, §M, and the off put-back paragraph.
  7. Move the clock for ME-CA1's bound (RM9).

RM18 — points verified, which hold.
- S15 (O1): within a run, V1 at S:13917 is the first request, in deploy, `--redeploy`, `--after-only` and `--rollback` alike.
  - There is no request to `$PLUGIN_BASE` before S:13917.
  - Stage A's tools boot nothing: `--show` uses PluginConfig::load (bf29cbd tools/set_distributors.php:38-66).
  - deploy-hybrid.sh boots nothing (:120-131).
  - In `--rollback`, V1 boots bf29cbd's code over the 092 file left on disk. 092 is already recorded, so it is skipped, or logged as a WARNING if its md5 differs.
  - `--check` makes no request.
  - Between runs, the rehearsal's own tools also boot the store (see RM13).
- S22: the old docker line ends `--clear-groups --); fi`, which does not occur in the fixed line (root:475, cc6a714). A restored snapshot also brings back the web server's old environment (devres106.sh:64-71); see RM1.
- S23: the anchor at R6:2446 occurs once; the last `== 106-` heading before it is 106-7i's (:2414); the last code line is :2441; `H106_DIR=` is at :61 and is followed by `H106_ROOT=`.
- S24: no `2567001070NN` number in scripts/ or the tests.
- S25 holds.
- O2, O3 and O4: answered in RM10, RM11 and RM12.
- The EXIT trap must also remove `.boot107`, `.redirect107` and the rbfail107 triggers.

## The completeness critic (RC)

Completeness and correctness critique of `wip/rehearsal-5.18.107/design-skeleton.md` (SK:n), checked against §T.11 (design doc 3386–3596), §T.3/§T.6/§T.7 and `scripts/deploy-5.18.107.sh` at d790edf (S:n). Read only; nothing was changed.

## Findings

**RC1 — blocker. S27 is false, and S13, S15, S17 and S19 inherit the error.**
- **Claim.** SK:151–153 says no kernel time enters a comparison. It does: S15's own boot path stamps 092 with the kernel's time.
- **Evidence.**
  - The stand-in web server runs without the clock (quarantine-stray-store/rehearse.sh:285 and :930).
  - No chain rehearsal restarts it. A grep of deploy-5.18.10[0-6] for `SB/web` and `-S` finds only the evo and webhook servers under LD_PRELOAD.
  - 092's `applied_at` is SQLite's `DEFAULT (datetime('now'))` (771a3c1 lib/MigrationRunner.php:59), written in that unclocked process.
  - `QF_COPY_UNIX` comes from `qf_php base` (S:7576–7577). The stand-in docker keeps the caller's LD_PRELOAD (quarantine:472), so it is on the rehearsal's clock.
  - That clock is about forty days ahead (R6:580–583; the A1 check asserts `ahead`).
  - So every sandbox first deploy meets `au < cu` at S:8435 and prints *092 was applied before this copy began*, 107-4's plain deploy included.
  - Knock-on effects:
    - S13 and S19 ("no dated note", `applied_at ≥ QF_COPY_UNIX`) would fail.
    - S17's `applied_at < COPY_AT` would hold for the wrong reason, and its weakened copy would fail whatever the plant does.
    - The marker-against-`applied_at` cases (§T.11:3459, 3473, 3477) would be judged against wall time.
- **Fix.**
  - In 107-infra, restart the stand-in web server under the clock, `env LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104"`, as deploy-5.18.104/rehearse.sh:1543 does for its webhook server.
  - Control: a probe page returns PHP `time()` and SQLite `strftime('%s','now')`, both within 2 s of `fnow104`. Control on the control: before the restart it reads wall time.
  - Every plant that applies 092 (S17, S19) goes through that clocked server or through a clocked CLI.
  - Restate S27 to match.

**RC2 — blocker. The *v9* stage-A note cannot be rehearsed, and S20 step 4 contradicts S24 and §T.11:3595.**
- **Claim.** S20 step 4 (SK:112) and 107-3 (SK:198) plant "the settings text at v9's bytes, through the real `set_config`". §T.11:3422 asks for the same.
- **Evidence.**
  - SA reads v9 only by sha256 prefix (`SA_TXT_V9`, S:8028).
  - The v9 text is not in the repository: a grep finds the prefix only in the script, the derivation and the docs. docs/07:17059 gives v9's size and prefix and says it carries an *OFFICE PHONE NUMBER* paragraph.
  - Neither `set_config` can write it:
    - bf29cbd's refuses any key not in `$FLAGS` (tools/set_config.php:366–367 at bf29cbd), and `bot_custom_instructions` is not among them;
    - the pin's has no such key either.
  - Planting production's bytes would also break "Everything seeded is fictitious" (§T.11:3595) and S24's RH107SECRET- rule.
- **Fix.**
  - Drop "v9's bytes" and "through the real set_config".
  - Plant an RH107SECRET- text with a fixture writer (said so) and assert *NOT v9 as the workers read it* and *the store row: not v9*.
  - Show the v9 branch either with a positive-control copy (`SA_TXT_V9` anchor-replaced by that text's prefix, labelled a reader control, never a production reading), or record it as not rehearsed.
  - List this under "Where §T.11 and the script differ", for the owner.

**RC3 — major. S19's timing contradicts itself.**
- **Claim.** SK:95 has the boot "held at *Type DEPLOY* as in S18 (after `QF_BASE`, before the tar)".
- **Evidence.**
  - The DEPLOY prompt (S:13852) comes before `qf_keep_and_baseline`, which takes LK-B1, the 6-s pause and only then `QF_BASE` (S:7562–7577).
  - `csb1run106` acts when *Type DEPLOY* appears (R6:883–894), so a boot there is before `QF_BASE`. That is S18's case: dated note, `applied_at < QF_COPY_UNIX`.
  - §T.11:3442 needs `applied_at` at or after `QF_COPY_UNIX`, before tar reaches 092.
- **Fix.** Trigger the S19 boot between `QF_BASE` and the tar. For example, a one-shot trigger at the start of the sandbox's `$REPO/scripts/deploy-hybrid.sh`, before its `tar` (root deploy-hybrid.sh:120), booting through the clocked stand-in (RC1). Assert `applied_at ≥ QF_COPY_UNIX` before judging.

**RC4 — major. One MG-CA2 case is dropped.**
- **Claim.** §T.11 lists 14 MG-CA2 missing-line cases; 107-6 (SK:216–228) lists 13.
- **Evidence.** Missing is §T.11:3466–3472:
  - *`--after-only` after a first deploy whose stage R read the OK line, the log then trimmed*;
  - the book holds 071's mismatched checksum, so every boot logs a WARNING, and real boots halve the log;
  - expected: the note naming 071's WARNING and the halving (S:8479 marker branch);
  - two weakened copies: one ignores both `MG_CA2_SEEN` and the marker; one treats `MG_CA2_SEEN=1` as 0;
  - its control is the no-sighting, no-marker case.
- **Fix.**
  - Add the case with both copies and the control.
  - Halving fires above 256 KiB and keeps 128 KiB (771a3c1 MigrationRunner.php:295–315). Pre-fill `migration.log` near that size with fictitious lines, said so, then make clocked boots until the marker appears.
  - Assert 092's OK line is gone and the marker is line 1 before judging.

**RC5 — major. No hook plants faults after the first boot and before stage R reads.**
- **Claim.** These faults must appear after the copy (after 092 is applied, or as the boot does it), inside the deploy run:
  - `wa_no_ai` renamed after the boot;
  - another md5 in the ledger;
  - a PARTIAL line in the deploy run;
  - stage R's OK line removed;
  - a WARNING beside the OK line;
  - the sentinel row;
  - 092's installed bytes altered after the copy.

  S15 puts the boot at V1 (S:13917), and R1 (S:14416) and `mg_after` (S:14456) run later. H1–H3 (SK:173–174) cover none of this.
- **Evidence.**
  - Nothing in the script between S:13905 and S:14456 runs container code on the live data except HTTP requests (awk over that range).
  - The stand-in boots at the top of public.php (quarantine:223–226), before any A7-style anchor (`$page = …`, R6:707).
- **Fix.**
  - Add H4: one-shot triggers in the stand-in public.php, spent after the boot on the first request once the installed manifest says 5.18.107.
  - Each does one fault: rename, rewrite the checksum, append or remove a log line, insert the sentinel row, or alter `$DEST/migrations/092`. Each must land before R1.
  - Probe page and controls as A7 has them.
  - State that H1 (`.noboot107`) is inserted before the `foreach ([...'SqliteStore'])` / `SqliteStore::create` lines, not at A7's anchor. Control: a request under H1 leaves the ledger without 092.

**RC6 — major. 107-5 and 107-6 have no first-deploy reset.**
- **Claim.** 107-5 says "Each on a fresh book state, rolled back to 5.18.106 between them by the typed rollback" (SK:207–208), and many 107-6 cases need MG-G1 absent. After 107-4 and any rollback, MG-G1 reads present.
- **Evidence.**
  - §T.7: after a rollback "the list and its rows" and "092 and the nine classes on disk" stay.
  - MG-G1 keys every first-deploy note (S:8435, S:8470–8474).
  - With 092 left on disk under 5.18.106, any request re-applies it. Every such case then becomes S19's.
- **Fix.**
  - Define the reset: restore `plugin.sqlite3`, `migration.log` and the config files from a keep made in 107-pre (`keep106`/`back106`, sha256-checked), and remove `$DEST/migrations/092_wa_no_ai.sql`, said so.
  - Assert MG-G1 reads absent (a `--check`) before each first-deploy case.
  - Undo it at the end.
  - Tag every 107-5 and 107-6 case "first deploy" or "redeploy", with its entry state.

**RC7 — major. H3 is assigned to the wrong case, and two rollback hooks are missing.**
- **Claim.** 107-8 (SK:253) plants "the put-back after a rollback that did not verify (H3)". H3 is the `.git/index.lock` for the checkout (SK:173–174).
- **Evidence.**
  - A failed `git checkout bf29cbd` returns before deploy-hybrid (S:13829–13832). 5.18.107's tools stay, which is §T.11:3552's case, not 3542's.
  - §T.11:3542 needs 5.18.106's tools installed and `lib/BurstMerge.php` *the pin's*, so the copy must complete and verification fail.
  - The chain's `.rbfail104` is keyed to @P104@/@P103@ (deploy-5.18.104/rehearse.sh:2514–2517).
  - V1's cases (§T.11:3534–3538) need a redirect armed while the manifest says 5.18.107. R6's A7 fires only for 5.18.106 (R6:707–711).
- **Fix.**
  - Add `.rbfail107`: the docker hook answers the record read with a non-bf29cbd commit once, after the rollback's copy. Use it for 3542 and its Q and K variants.
  - Keep H3 for 3552.
  - Add `.redirect107` (plus a lock variant) gated on 5.18.107's manifest, with a probe and controls, for `v1run107`.

**RC8 — major. S6 is wrong.**
- **Claim.** SK:45–46 says each of the four files reads *not read* when the reading is not made.
- **Evidence.** `pb_tool` returns `absent` for a missing `lib/BurstMerge.php` and `unread` only for the other three (S:9066). At the `BASE_DIFFERS` stop the tree is 5.18.106's, so BurstMerge is absent. §T.11 removes only "the three files present at `bf29cbd`".
- **Fix.** Assert three files *not read* and `lib/BurstMerge.php` *absent*, with all three commands *may be refused* (S:9098–9100).

**RC9 — major. Undo and repair are unspecified outside 107-6.**
- **Claim.** These leave state a later section depends on:
  - 107-2's plants: the pairing table, a partial 092 row with its PARTIAL log line, 092 with other bytes;
  - 107-3's fixtures: switches in p/d/k/s, `'0'`, text keys, `StaffJobsGate` false, NS-B1's switch;
  - 107-8's switch-on rollbacks (§T.11:3518, 3529, 3542–3551), which leave W.1, K or Q on under 5.18.106, which cannot write them.
- **Evidence.**
  - S:7528: 5.18.106's tools refuse these keys; the repair is the owner's authorised `kyc_config.json` edit.
  - 107-9 needs NS-G1 GO "with the `'0'` that §K's switch-off left" (SK:258).
- **Fix.**
  - Give each section its entry and exit state.
  - Undo 107-2 and 107-3 plants with `keep`/`back` plus `cut106` on `migration.log`, asserting MG-G1 absent and the keys absent afterwards.
  - After each switch-on rollback, repair with a fixture writing `kyc_config.json` (the known-limits route, said so).
  - Order 107-8 so the last rollback before 107-9 comes after `BD_OFF_CMD` ran under 5.18.107, leaving `'0'`.

**RC10 — minor. S1's case can pass without testing the control-0 rule.**
- **Claim.** S1 asserts the capitals, the drain command, §K's rule text and no *drain holds*. The first three print in every not-held case (S:8975–8981).
- **Evidence.** `rb_bd_zero` (S:8885) fails on any count above 0. A plant with (a) or (b) above 0 would pass S1's assertions whatever the control did.
- **Fix.**
  - Plant: switch off, (a)=0 and (b)=0 in both readings, control 0, said so.
  - Assert *control 0* in both RB107 readings and no *drain holds*.
  - Add a weakened copy that drops `control != 0` from `rb_bd_zero`, caught by *drain holds* appearing.

**RC11 — minor. Some absence assertions are vacuous.**
- **Claim.** Each of these strings is never printed by the script, so asserting its absence proves nothing on its own:
  - S2's *the note does not name `notify_retry`* (only `ME2_JOBS` names are printed, S:8243, 8362);
  - S4's *the old reason appears nowhere* (grep count 0);
  - §T.11's *never "nothing to drain"* (3540) and *never "no mark to drain"* (3534). Both appear only in comments (S:145, 8889, 9092, 13935).
- **Fix.**
  - Keep each only as the observable of a named weakened copy that prints the forbidden text: §T.11:3545's old-reason copy exists; add copies for the put-back and V1-headline wordings, or say there are none.
  - Make the substantive assertions the positive texts:
    - *once 5.18.106's tools are installed none can write it* (S:9115);
    - *One reading, not a drain.* (S:9124);
    - *this run wrote no mark* with the count.
  - For S2, rely on "no ME-CA2 FAIL", with the first ME-CA2 case (same plant on one of the seven, FAIL) as its positive control.

**RC12 — minor. S5 gives no real observable for "no *nothing is saved* beside" a running switch-off.**
- **Evidence.**
  - The switch-off line (S:9102) never carries the sentence in any branch. It goes on the §K-rule line whenever the switch-off or the drain is not *runs* (S:9107).
  - In §T.11's "only `lib/SalesFollowupLadder.php` the pin's" case it therefore prints, because the drain is refused.
  - For the K/Q lines it is appended at S:9118 only when `ws` is not *runs*.
- **Fix.**
  - For a running switch-off: assert *runs, and leaves '0'* and that *Where the switch-off does not run* (S:9108) is absent.
  - For K/Q worded *runs*: assert their *take nothing as saved.* line (S:9117) ends without *A refusal changes nothing*. The sentence on the rule line is expected there.

**RC13 — minor. Sub-cases dropped or merged away (each quoted from §T.11).**
- 3428: "MG-CA1 still PASSES … and in a later `--after-only`". 107-5 drops the later `--after-only`.
- 3453: PARTIAL "in the deploy run and in `--after-only`", and "A weakened copy that prints the MG-G1 redeploy-refusal consequence there is caught". 107-6 lists neither.
- 3487: "with the ledger's md5 other as well, MG-CA1 FAIL". This second sub-case is dropped.
- 3501: "the deploy run: NOT YET, as a note". Not listed in 107-4 or 107-7.
- 3504: "then a planted ERROR: FAIL". Dropped.
- 3516: "the redeploy sentence" (S:8975 *A REDEPLOY'S NS-G1 REFUSES*) and "any other answer changes nothing". Dropped.
- 3419: "`no_ai_alert_cooldown_hours` among them". Dropped.
- 3406: "`reset=1` on each redeploy below". Asserted only in 107-9.
- 3460–3462 (stage R): "or the log made unwritable". Dropped.

Fix: add each one, with its plant.

**RC14 — minor. 107-3 lists the store row among the refused places.**
- **Claim.** 107-3 has "(p, d, k, s), each refused" (SK:194).
- **Evidence.** NS-G1 refuses only p, d and k. The store row is reported, never judged (S:7517–7531).
- **Fix.** Refuse p, d and k; keep "the store row alone → GO with the note" as its own case.

**RC15 — minor. S21 names the wrong writer for W.1.**
- **Claim.** SK:116–119 has K, Q and W.1 turned on through 5.18.107's `set_config`.
- **Evidence.** The pin's `set_config` does not manage `ai_burst_merge_enabled` (grep of 771a3c1 tools/set_config.php: 0). Its writer is the switch tool, `W1_ON_CMD` (S:7332).
- **Fix.** W.1 through the pin's wrapper; K and Q through `set_config`.

**RC16 — minor. The S17 and S18 plant mechanics are underspecified.**
- **Evidence.**
  - S17 calls `clk_to104`, a rehearsal function the generated shim cannot call (R6:589–598).
  - S18's `MigrationRunner` logs to `dirname($migrationsDir)/data/migration.log` unless it is given `$dataDir/migration.log`, as SqliteStore does (MigrationRunner.php:51; SqliteStore.php:247).
- **Fix.**
  - The shim writes `CLKF104` forward only and records a refusal.
  - `mig092` passes the live data folder's log, so 092's OK line appears; assert it.
  - In S17, also assert `applied_at ≥ QF_COPY_UNIX`, which makes "no dated note" meaningful.

**RC17 — minor. The concurrent writers are missing.**
- **Evidence.**
  - ME-CA1's "a later cycle" and "re-dispatch" need an entry written while the poll runs (S:8381–8389).
  - §T.11:3518's mark must be written while *Type ROLLBACK* waits (S:13898).
  - `csb1run106` holds only on *Type DEPLOY* (R6:887).
- **Fix.** Add `pollrun107`, keyed on *ME-CA1 waiting up to* in the stream, and `rbhold107`, keyed on *Type ROLLBACK*, both using `run106c --hold`.

**RC18 — minor. S9's event-id assertion cannot work for its own case.**
- **Claim.** In §T.11:3518 the mark is written after `rb_pre107`'s reading, so its event id cannot appear in `rb_bd_ids` (S:8957), and `rb107` lists conversations only (S:8896).
- **Fix.**
  - Assert `rb_pre107`'s reading does not list the mark (the timing control) and `rb107`'s note lists its conversation.
  - Assert event ids in §M's case (3530), where the mark exists before the reading.

**RC19 — minor. The number and text rules have gaps.**
- **Evidence.**
  - §T.11:3497 plants a row on `NA_SENTINEL_1` (S:861), which is not in S24's range.
  - S24 does not add the RH107SECRET- marker to the names pattern (R6:575 adds RH106SECRET-).
- **Fix.**
  - Declare the sentinel as the one seeded value outside the range (the script's constant, a country-code-then-0 number). Sweep for its row and remove it.
  - Add RH107SECRET- to the names pattern.
  - Sweep `setoff-`/`seton-` logs and `mutants/`.

**RC20 — minor. "No deploy and rollback in one block" has no definition (SK:261).**
- **Evidence.** The summary prints `BD_OFF_CMD`, `BD_DRAIN_CMD` and `ROLLBACK_CMD` with prose between them (S:16266–16284). The rule needs a testable form.
- **Fix.** Over every run's output:
  - no line holds both a `--rollback` and a deploy/`--redeploy`/`--after-only` command;
  - no two command lines (six-space indent) from {deploy, redeploy, after, rollback, putback} are adjacent without a prose line between them.

  Control: a copy that prints `REDEPLOY_CMD` next to `ROLLBACK_CMD` is caught.

**RC21 — minor. Two cases sit in the wrong place.**
- **Evidence.**
  - §T.11:3577's `--after-only` summary case needs 5.18.107 serving, but sits in 107-8 (SK:255).
  - 107-9 runs owner steps 1 and 3 (SK:259–260). They print only after a PASSED `--after-only` (S:16187). Running them turns K and W.1 on.
- **Fix.**
  - Move 3577 into 107-6, before any rollback.
  - In 107-9, insert a PASSED `--after-only` after the redeploy. After steps 1 and 3, turn K and W.1 off with the printed off lines, then assert 107-10's end state.

**RC22 — minor. S22 keeps one of §W.8's controls.**
- **Evidence.** §T.11 says "with the same controls (§W.8)". The root has four (quarantine:949–965): PATH, `rm` at /usr/bin, a uid-1000 delete exiting 0, and the sed-undone copy exiting 2. SK:127 keeps only `printenv PATH`.
- **Fix.** Carry all four.

**RC23 — minor. S26's poll time is overstated.**
- **Evidence.** The poll ends at the first later entry (S:8384–8389). Only the no-cycle case runs the full bound (`b+360`, S:8379).
- **Fix.** "Up to the bound; 360 s with the budget planted 0, in the no-cycle case only."

**RC24 — minor. Citations and wording.**
- S15 has "after R1 at S:14456". R1 is at S:14416; `mg_after` is called at S:14456.
- The `EP_NOAI_DONE` source is inconsistent. 107-0 takes it from A6/R13 in 107-4 (SK:168), and 107-8 says "measured here" (SK:248). A6 compares it at stage A (S:13139).
- S12's "only fault is MG-CA2's" contradicts the PARTIAL FAIL. It should read: only finding is MG-CA2's NOT ESTABLISHED note.
- S7: only "NOT" is in capitals (S:8066).

**No settled point wrongly concludes "the script is right" against a §T decision.** S1 matches §T.7 step 4, S2 §T.6, S9 §T.7's `rb107`, S11 §T.3 item 8, S12 §T.3 item 6 ("not a FAIL (TD11)") and S13 §T.3 item 6 ("No bound on `applied_at`").

## Holds (verified)
- **S3:** the text and the bound `b+360` (b below 0 → 0; master's budget read as `(int)(…?? 300)`, S:8226).
- **S4:** the text at S:9115/9119.
- **S8:** `lk=nopin` (S:7447) leaves `LK_RB0` empty, so `rb_kq` returns unread and *COULD NOT BE READ* (S:8988).
- **S10:** the branch texts at S:8479; *the runner halves the log* prints in other branches, so its absence is meaningful.
- **S11:** R7 *CHANGED* via `db_mig107` (S:12045–12049).
- **S14:** S:7528, then *Nothing further was done.*, exit 1 (S:1834–1835).
- **S16:** V1's 503 has 0 redirects, so there is no automatic rollback (S:13926). `MG_SAY=na`. The MG-CA2 text at S:8473.
- **S23:** the cut anchors (R6:2414, 2440–2446, 61–62).
- **S25:** K7 is consistent with §T.14.
- **O3:** only `stamp_copy` calls touch (S:13827).
- **O4:** `MigrationRunner.php` and `SqliteStore.php` are identical at bf29cbd and 771a3c1.
- **O1:** the V section runs in every mode.
- **107-1** covers all eight mixed-tree cases.
