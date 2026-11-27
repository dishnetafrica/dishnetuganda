# The 5.18.107 rehearsal — review 2 of design-v2.md (27 Nov; two read-only critics)

## The take-check critic (TC)

TAKE-CHECK of wip/rehearsal-5.18.107/design-v2.md (v2:n) against design-review1.md. Read only. No file was changed.

## Findings

**TC1 — blocker. A9 step 1 installs 5.18.105, not 5.18.106.**
- Claim: v2:171 resets with `install_commit $B106`.
- Evidence:
  - In R6's shell, B106 is deploy-5.18.106.sh's BASELINE_COMMIT (R6:212), which is 7835906, 5.18.105 (deploy-5.18.106.sh:776). bf29cbd is P106 (R6:211; deploy-5.18.106.sh:773).
  - install_commit writes its argument into `.deployed-commit` (root:297-301).
  - The next deploy then reads LIVE_BEFORE ≠ bf29cbd (deploy-5.18.107.sh:775) and stops NO-GO (S:12370-12371).
  - So every A9 reset leaves the next first deploy unusable. That covers 107-4, 107-5 and 107-6's first deploys.
  - The error is carried verbatim from RM7.
- Fix: use `install_commit "$P106"`, or a B107 read from deploy-5.18.107.sh:775. Assert that `.deployed-commit` reads bf29cbd before step 2.

**TC2 — major. H4's arming moment is dropped (RM3, RC3).**
- Claim: RM3 said to arm `.boot107` at the DEPLOY hold (csb1run107). v2's H4 row (v2:106), A5 (v2:122) and A8 (v2:152-163) never say when it is armed.
- Evidence:
  - H4 fires on the first `exec ucrm cat …/.deployed-commit` while the record names P106.
  - Stage A makes exactly that call (LIVE_BEFORE at S:12299, via live_commit at S:1853), long before QF_BASE and before MG-G1 (S:8518ff).
  - If the trigger is armed before the run, 092 is applied before MG-G1. MG-G1 then reads it present, and A8's case is not the case it claims to be.
  - v2 also says read_live is "the only container call between QF_BASE and the tar". It is not:
    - qf_php lkrows at S:7579 (it uses dxo, S:3175 and S:7341);
    - vn_baseline's dxo calls at S:9774-9775;
    - deploy-hybrid's `docker inspect` at deploy-hybrid.sh:74.
  - Only the `.deployed-commit` read is unique within that window.
- Fix: arm H4 in csb1run107's action at *Type DEPLOY* (S:13852). Assert it unspent at DEPLOY and fired exactly once after. Reword the uniqueness claim.

**TC3 — major. 107-8's order and exit state cannot be executed as written (A17 vs §B).**
- Evidence:
  - The two orders conflict:
    - A17 item 5 (v2:307-308): answer-anything cases, then H6 and H3, then typed rollbacks, each followed by a `--redeploy` that carries 107-9's assertions.
    - §B 107-8 (v2:476-504) puts the typed rollbacks (items 2 and 3) before H6 (item 4) and H3 (item 5).
  - The exits conflict too: 107-8's exit is "5.18.106 serving" (v2:507), yet A17 ends every typed rollback with a redeploy.
  - H6 leaves bf29cbd's record (deploy-hybrid.sh:123). A `--rollback` with 5.18.106 live is RB_NOTHING: no rb_pre107 and no do_rollback (S:13895, S:13906). So the Q-on and K-on H6 variants and H3 each need a 5.18.107 redeploy first. Neither A17 nor §B lists those redeploys.
  - The exit claims "a typed rollback whose switch-off ran under 5.18.107 and left '0'". No 107-8 step runs BD_OFF_CMD, and A13's fixture "clears" the keys (v2:244-245). 107-9 needs NS-G1 GO with '0' (v2:510), and nothing produces it (RC9's last fix bullet).
- Fix:
  - Write 107-8 as one sequence that names each deploy or redeploy and its entry state.
  - Run BD_OFF_CMD as printed, under 5.18.107, before the final typed rollback.
  - Decide whether 107-9's redeploy is the one inside 107-8.

**TC4 — major. Entry and exit states in 107-5, 107-6 and 107-7 do not chain (RC9, RC6).**
- 107-6's seen=1 deploy enters as "107-4's" (v2:422). By then 107-5's A9 resets have tidied that state file (v2:175). 107-5 exits on its last variant, a FAILED first deploy with the ledger's md5 other (v2:415-418).
- The seen=0 and nowait deploys (v2:449, v2:453) state no entry, but both must be first deploys:
  - nowait needs 092 unseen for 60 s (S:8409-8417, S:8460);
  - seen=0 needs a sighting with no OK line.
- The PARTIAL "fresh deploy" (v2:425-426) run over a live 5.18.107 silently becomes an `--after-only`: S:12363 sets MODE=after.
- 107-7 (v2:460-471) has no exit and no undo of its schedule, budget, unreadable-schedule or ERROR plants. 107-8 enters on "one 5.18.107 deploy" (v2:475), unspecified.
- Fix: state "A9 reset, then a first deploy" for each of these deploys, and give 107-7 an exit with its plants undone.

**TC5 — major. Two §T.11 MG-CA2 cases are dropped. This is a regression from v1, not from the review.**
- §T.11:3485-3486: a redeploy with a planted WARNING and no OK line reads the note and PASSED, with the copy that counts the WARNING as a FAIL.
- §T.11:3499: a redeploy with no 092 line reads the note.
- v1 had both (design-skeleton.md:223, :228). v2's §B has neither (107-5, 107-6, 107-9), and §C does not record dropping them, despite v2:3-4.
- §M's "empty once that event is answered, by the real worker" (§T.11:3528-3529; v1 SK:245) is reduced to "§M, with event ids" (v2:482).
- Fix: add both cases to 107-9's redeploy (S:8471, S:8464-8467, PASSED, the copy), and restore §M's second half.

**TC6 — minor. A10's pollrun107 claim, read against S:8370-8395.**
- Holds:
  - The bound is `date -u +%s` under LD_PRELOAD (S:8381-8382), and the clock file is read on every call (rh104-clock.c:4-7).
  - me_after_poll judges entries and `cu` only (S:8282-8294).
  - A move past `b+360` after t0, during the first `sleep 5`, ends the loop after that one sleep.
- But:
  1. The poll exists only in MODE=after (S:8375). csb1run106, the shape v2 copies, runs `--answer DEPLOY` (R6:891). The runner's mode is unstated.
  2. The keyed line is echoed at S:8380, before t0 is taken at S:8381. The watcher acts on the echo (R6:889). A move that lands before S:8381 is absorbed into t0, and the loop then runs its whole 360-s bound in real time, unnoticed.
  3. In the "later cycle" and "re-dispatch" cases the loop ends by `break` at the first read (S:8387), not by the clock. Moving the clock there is gratuitous.
  4. csb1run's watcher runs its ACTION even when the line never appears, after a 300-s wait (R6:889-890), and the `--hold` feeder blocks the run just as long (R6:288-289). A run whose poll does not start costs 300 s, and its schedule write and clock move land after the run.
- Fix:
  - pollrun107 runs `--after-only`.
  - The no-cycle action only moves the clock, at least 1 s after the echo. The write cases only write.
  - Assert the poll line appeared and the run's real duration (under 30 s). Never run the action without the line.

**TC7 — minor. RC13's sub-cases are only partly taken.**
- 3487, "the ledger's md5 other" on a first deploy (v2:417), is listed with no plant:
  - V1's boot writes the real md5;
  - v2 has no post-boot hook in a deploy run (§C item 2);
  - so "or a line removal" (v2:415) is not available either.
- 3406, `reset=1` on each redeploy, is asserted only in 107-9 (v2:509), and through A17's "carries 107-9's assertions".
- Fix: name 3487's plant (for example, H2 altering the installed 092 before V1, stating that R1 FAILs too) or move it to `--after-only` and record that in §C. Assert `reset=1` on every redeploy.

**TC8 — minor. A2/M0 against partB and the M0 note.**
- (a) v2:57-58 says 107-pre's `--check` "is the in-sandbox measurement the derivation records as measured". ceac392's partB.py:54-60 records M0 as the measurement ("§T.15 M0 … its A6 and R13 read it again").
- (b) partB cites "§T.15 M0", but wip/design-5.18.107/76-…md has no §T.15: the last section, §T.14, starts at :3691. The commit says "to follow".
- (c) §C item 5 (v2:539-540) still says "interim". It does not record that §T.2 (:2652-2654) and §T.12 step 2 (:3600) placed this measurement in the suite, not in M0.
- (d) v2:43 says A6 runs "before stage A's other gates". It runs at S:13106, after S:12370, S:12398, S:12429 and S:12445, and v2's own A17 item 1 says 107-1 stops before A6.
- Holds:
  - M0 as v2 describes it matches partB and the commit message: EP_PHP on 771a3c1 and bf29cbd, UG and SS, with and without wa.no_ai_alert:3.
  - The script is byte-identical (sha256 8934c835…).
  - The host php is php8.3 (/usr/bin/php → php8.3), which the stand-in docker runs (root:474).

**TC9 — minor. A9's helper details.** The three functions exist with the signatures v2 assumes. Step 3 is redundant, and the "sha256-checked" claim is inaccurate.
- install_commit COMMIT: root:297-301; it removes everything but ucrm.json and data (root:298).
- drest104 NAME: 104:1036-1042, after `dsnap104 NAME` (104:1030-1035), and asserted defined at R6:175.
- qcheck106 LABEL: R6:376-380; v2 omits the label.
- tidy106: R6:323.
- Step 3, removing the on-disk 092, does nothing after install_commit, which already removed every plugin file but ucrm.json and data.
- drest104 checks digests, not per-file sha256 as v2 says:
  - data94 over the database dump (master_schedule excluded);
  - the vault;
  - the tables.
- So migration.log and the config files are restored by cp -a but not checked.

**TC10 — minor. RC4's halving mechanics are underspecified.**
- The runner keeps the tail: `substr($keep, 131072)` in 771a3c1 lib/MigrationRunner.php log(). 092's OK line goes only if it lies in the first 128 KiB.
- So the fill must be appended after the OK line, which v2:432 does not say.
- A16's seed ops (v2:288) have no op that gives 071 a mismatched checksum.
- Fix: say "append after", and add a 071 checksum op with its undo.

**TC11 — minor. §C item 2's reason is overstated.**
- The root public.php already runs one-shot triggers that change the book on the first request after the boot (root:229-266: .mutate_cfg, .flip_switch, .chg_087). That is RC5's proposed shape, so this is a choice, not a constraint.
- "The same lines" is also not exact for R7: `--after-only` gives the second judgement against the deploy's record (S:14877), not the deploy's own (S:14874).
- The narrowing itself is justified: the FAILED lead (S:16147-16179) is mode-independent, and mg_after runs in MODE=after.

**TC12 — minor. "Where one is narrowed, it says so" (v2:3-4) does not hold.** Silent narrowings:
- RM3's arming (TC2);
- RM10's OPcache poll of rh107b_probe, absent from v2:103;
- RC16's "records a refusal" for the shim's clock write, absent from v2:104;
- RC9 (TC3, TC4);
- RC13 (TC7).

## v2's other claims checked

- **§C items 1, 3 and 4 hold.**
  - Item 1: neither set_config's `$FLAGS` (line 46 at both commits) has bot_custom_instructions, and SA_TXT_V9 is a 12-hex literal at S:8028.
  - Item 3: rb_pre107 prints no INDETERMINATE (S:8970-8981).
  - Item 4: the texts are at S:8336, S:9115 and S:8988.
- **§C item 5**: see TC8.
- **A9**: see TC1 and TC9.
- **A10**: see TC6.
- **A17**: see TC3 and TC4.
- **Other citations hold**: H2's anchor (R6:589-598, :593); H6's mechanics (deploy-hybrid.sh:123, :144-150); `rb_bd_zero` is at S:8887 (RC10 cited S:8885).

## Verdict per review-1 finding

- RM1 — TAKEN (A1: relaunch as uid 1000 under the clock; probe plus control on the control; idempotent).
- RM2 — TAKEN (A3). It correctly uses a fixture, not set_config, per RC2.
- RM3 — NARROWED, unjustified: the arming at the DEPLOY hold is dropped, and the uniqueness claim is wrong (TC2).
- RM4 — TAKEN (H3, H6).
- RM5 — TAKEN (A2), extended by M0; one sentence is stale (TC8).
- RM6 — TAKEN (H5).
- RM7 — MISTAKEN: `install_commit $B106` installs 5.18.105 (TC1).
- RM8 — TAKEN (A5's deploy-run plants; post-boot faults in `--after-only`).
- RM9 — TAKEN (A10), with caveats (TC6).
- RM10 — TAKEN (H1; nowait expectations in 107-6); the OPcache poll is silently dropped (TC12).
- RM11 — TAKEN (H2 a–d).
- RM12 — TAKEN (A7).
- RM13 — TAKEN (A4, A16's logline, master106).
- RM14 — TAKEN (A13).
- RM15 — TAKEN (A14).
- RM16 — TAKEN (A15).
- RM17 — TAKEN as a list, but item 5 contradicts §B (TC3).
- RM18 — TAKEN (cleanup107 and the sweep; A18's range).
- RC1 — TAKEN (A1).
- RC2 — TAKEN (A3; §C item 1).
- RC3 — TAKEN through RM3's H4; NARROWED by the same arming gap (TC2).
- RC4 — TAKEN (107-6's 071 halving); the fill placement and the 071 op are missing (TC10).
- RC5 — NARROWED, justified (A5; §C item 2), but the reason given is overstated (TC11).
- RC6 — MISTAKEN in step 1 (TC1), and the entry tags are only partly realised (TC4).
- RC7 — TAKEN (H3 for 3552, H6 for 3542, H5 with its lock variant).
- RC8 — TAKEN (A11 S6; holds against S:9097-9100).
- RC9 — NARROWED, unjustified (TC3, TC4).
- RC10 — TAKEN (A11 S1).
- RC11 — TAKEN (A11 S2, A12).
- RC12 — TAKEN (A11 S5).
- RC13 — NARROWED: 3428, 3453, 3501, 3504, 3516, 3419 and 3460 are taken; 3487 has no plant and 3406 is only partly taken (TC7).
- RC14 — TAKEN (107-3).
- RC15 — TAKEN (A13: W.1 through the wrapper).
- RC16 — TAKEN; "records a refusal" is dropped (TC12).
- RC17 — TAKEN (pollrun107, rbhold107); for pollrun107's caveats see TC6.
- RC18 — TAKEN (A11 S9).
- RC19 — TAKEN (A18).
- RC20 — TAKEN (A18's one-block rule and its control).
- RC21 — TAKEN (3577 moved to 107-6; 107-9's PASSED `--after-only`, then the off lines).
- RC22 — TAKEN (A16 W.8's four controls).
- RC23 — TAKEN, superseded by A10's clock move.
- RC24 — TAKEN (citations and wording).

Files: /home/user/dishnetuganda/wip/rehearsal-5.18.107/design-v2.md, /home/user/dishnetuganda/wip/rehearsal-5.18.107/design-review1.md, /home/user/dishnetuganda/wip/derive-5.18.107/partB.py, /home/user/dishnetuganda/scripts/deploy-5.18.107.sh, /home/user/dishnetuganda/scripts/harness/deploy-5.18.106/rehearse.sh, /home/user/dishnetuganda/scripts/harness/quarantine-stray-store/rehearse.sh, /home/user/dishnetuganda/scripts/harness/deploy-5.18.104/rehearse.sh

## The fresh-eyes mechanics critic (FM)

Fresh-eyes mechanics review of wip/rehearsal-5.18.107/design-v2.md: 10 findings (2 blockers, 2 major, 4 minor, 2 nits), then the points that hold.

FM1 — blocker. H4 (`.boot107`) fires in stage A, at LIVE_BEFORE, not at deploy-hybrid's `read_live 3`.
- Claim: the trigger condition is the exact call `exec ucrm cat /data/ucrm/data/plugins/dishnet-hybrid-sudan/.deployed-commit` while `$PD/.deployed-commit` names P106. The script itself makes that identical call first, at stage A's LIVE_BEFORE, long before MG-G1 and QF_BASE. Armed before the run, H4 lets 5.18.106's runner apply the on-disk 092 before MG-G1 reads the ledger, so A8's assertion `applied_at ≥ QF_COPY_UNIX` cannot hold. A `--check` run with H4 armed fires it the same way.
- Evidence:
  - The calls are identical: `live_commit()` at scripts/deploy-5.18.107.sh:1853 is `docker exec "$CONTAINER" cat "$IN_CONTAINER/.deployed-commit"`. It is first called at S:12299 (LIVE_BEFORE, after stage A's header at S:12077). 771a3c1 scripts/deploy-hybrid.sh:57 (read_live, called at :86) makes the same call.
  - Between the DEPLOY answer (S:13852) and deploy-hybrid (S:13873) there is no live_commit call. Its other callers are S:13834, :13842, :13878, :13887, :13905, :13907, :13944, :13945 and :15982.
  - RM3 said "Arm it at the DEPLOY hold (csb1run107)" (design-review1.md:48). v2 drops that: the H4 row (design-v2.md:106), the plant list ("at deploy-hybrid's read: H4", :121) and A8 (:152–163) never say when H4 is armed.
- Fix: arm `.boot107` from the DEPLOY hold. In the csb1run107 shape, the action creates the trigger, and nothing else in the run matches before deploy-hybrid. Or also require the fake docker's parent command line to be deploy-hybrid.sh. Add a control: with H4 armed before the run, the counter is 0 at the end of stage A.

FM2 — blocker. A9 step 1 `install_commit $B106` installs 5.18.105, not 5.18.106.
- Evidence:
  - R6:212 sets `B106="$(sv106 "" BASELINE_COMMIT)"`, and scripts/deploy-5.18.106.sh:776 has `BASELINE_COMMIT="7835906"` (5.18.105). P106 is bf29cbd (R6:211; deploy-5.18.107.sh:775 BASELINE_COMMIT="bf29cbd").
  - After such a reset, LIVE_BEFORE ≠ BASELINE_COMMIT, and the script stops "NO-GO: the container serves …" (S:12370–12371); BR1 (S:12446–12449) would refuse as well.
  - H4 also never fires, because it is keyed on P106 (design-v2.md:106).
  - So every A9-reset first-deploy variant stops in stage A: 107-5, the fresh deploys of 107-6, and A6/A7/A8 with their weakened copies. A9 step 6's `--check` stops the same way.
- Fix: `install_commit "$P106"`, asserted equal to deploy-5.18.107.sh's BASELINE_COMMIT before use. Then the existing chown (root:929).

FM3 — major. Two §T.11 redeploy cases for MG-CA2 are dropped, and the redeploy framing of three more is changed without a §C entry.
- Dropped:
  - §T.11:3485–3486: a redeploy with a planted 092 WARNING and no OK line → the note, PASSED, plus the weakened copy that counts the WARNING as a FAIL;
  - §T.11:3499: a redeploy with no 092 line → the note.
- Evidence: v1 listed both (design-skeleton.md:223, :229). v2's 107-5 and 107-6 (design-v2.md:409–459) and 107-9 (:508–517) contain neither. In the rehearsal, a redeploy's log still holds the earlier OK line, so S:8471's note is never reached unless that line is removed.
- §T.11:3489, :3492 and :3497 ask for a redeploy; v2 runs them as a `--after-only` after a first deploy (:442–446). §C item 2 (:531) does not record this change of context.
- Fix: add both cases to 107-9 (each plant undone), or record them under §C. Record the context change for the other three.

FM4 — major. The RC4 halving case (design-v2.md:430–435) is underspecified. As written it can be unreachable.
- How the runner halves, at 771a3c1 MigrationRunner.php:
  - it halves only inside `log()` (:297–319), and `log()` runs only for an OK, PARTIAL or WARNING line (:195, :250, :254), so a quiet boot never halves;
  - the test is `filesize > 262144`, made before the line is appended (:295, :306);
  - it keeps the file from byte 131072, starting at the next newline (:309–312);
  - the marker is `[Y-m-d H:i:s] --- earlier entries trimmed ---`, written with `date()` (:314).
- (a) There is no seed op for 071's mismatched checksum, and no undo. A16 lists `ledger092` only (:288). While 071 is mismatched, every boot logs a WARNING and every deploy's or `--check`'s QF-G2 trial prints the 071 note (S:8565–8568).
- (b) "Pre-filled near 256 KiB" does not say where 092's OK line ends up. It is dropped only if it lies in the first 128 KiB.
  - So the pre-fill must be appended after the OK line, and the case should assert the OK line's byte offset.
  - The 071 WARNING line is 121 bytes. If the OK line sits in the second half, one halving keeps it, and a second halving needs about 1,083 more boots (131072 / 121).
- (c) Number of boots: with the file at size S after the pre-fill and one WARNING per boot, about ceil((262145 − S) / 121) + 1 boots. If S > 262144 already, the first boot halves. Bound the loop by that number; do not loop "until".
- (d) Could the script tell the pre-fill apart? No. MG-CA2 reads only grep 092 and line 1 (S:8454–8458, :8479). The other readers grep only 086, 087 and 089–091 (S:8587, :8694, :9798, :10502, :14284, :14315). This holds provided the fictitious lines name none of 086/087/089–092. The file must also stay owned by uid 1000, or the runner's writes fail silently (:313–319).

FM5 — minor. migration.log stamps are on the clock but not all in one timezone.
- Evidence:
  - master106 boots set dn_tz before the store starts: 771a3c1 cron/master.php:4 calls `dn_tz_apply()`, which sets `date_default_timezone_set` (lib/timezone.php:92–94), before `SqliteStore::create` at :60.
  - The stand-in (root:224–226), H2/H4 and the seed set no zone. No `date.timezone` is set under /etc/php*.
  - The script reads the marker "as UTC" (S:8458).
- So A1's claim (:37–39) that "every comparison … migration.log stamp … is between two times on the rehearsal's clock" is true of the clock, not the zone. A master106-written marker (RC4) or OK line is +2 or +3 h when read as UTC.
- Fix: stamp fixture markers in UTC from applied_at. Never assert order on a master-written stamp without converting from dn_tz.

FM6 — minor. Nothing checks the stand-in's log for a PHP fatal after A1's relaunch.
- Evidence: the only `Fatal|Parse error` checks of the stand-in's log are root:2280 and 104:4516. Both come after their rehearsals' cut points, and R6 has none. 107 edits public.php twice (H1, H5) and relaunches the server; A1 (:25–28) does not name the log file.
- Fix: append to `$SB/web.log` as root:930 does. Add a `Fatal|Parse error` check in 107-10, with a baseline taken at 107-infra.

FM7 — minor. Output from the H2 and H4 boots can corrupt what they pass through.
- Evidence:
  - H4 runs inside deploy-hybrid's `$(docker exec … cat … | tail -n 1 | tr -d '[:space:]')` (deploy-hybrid.sh:57–60). Boot output without a trailing newline is glued onto the commit; read_live then sees non-hex and retries.
  - H2's boot prints into stamp_copy's terminal (S:13827 does not capture touch's output), and so into a swept run terminal.
- Fix: send both boots' stdout and stderr to a file of their own, swept in 107-10.

FM8 — minor. The chain's leftover-trigger check is cut and re-read only at the end.
- Evidence:
  - 107-cut stops at R6's 106-10 heading (R6:2446), so 106-10f (R6:2561–2579) runs only at 107-10.
  - `.ujfail106` is never spent (R6:727–728).
  - `.redirect106` fires whenever 5.18.106's manifest is installed (R6:710), and 107-8's rollbacks reinstall it.
- Fix: run 106-10f's leftover reading in 107-infra, before A1.

FM9 — minor. A1's `rh107c_probe` has no stated place.
- Evidence: every probe in the chain sits before `$page`, after the boot (104:2615–2618, 105:426–428, R6:707–709, with the boot at root:226). Placed there, each A1 control request boots the live store, which A4 and A9 (:179) forbid in some windows.
- Fix: put it before the boot, like `rh107b_probe`.

FM10 — nit. Two wording points.
- H4's anchor in the markdown table reads `row() { grep "^$1\|" ` (design-v2.md:106). The generated line is `row() { grep "^$1|" ` (root:429; 104:2528; R6:755). Copied literally, it fails the anchor (anchor-missing would catch it).
- A8's "the only container call between QF_BASE and the tar" is inaccurate: LK_ROWS_B's `qf_php lkrows` (S:7579) and deploy-hybrid's `docker inspect` (deploy-hybrid.sh:74) also come in between. H4 keys on the exact call, so the hook itself is unaffected.

Points verified — holds:
- **A1:**
  - Nothing else restarts the stand-in; only root:285 and :930 start it.
  - rh104-clock.c moves `time()`, `gettimeofday` and `clock_gettime` REALTIME (:53–75), so PHP `time()`, `date()`, DateTime, and SQLite `datetime('now')` with the system SQLite preloaded (104:1222–1226) all move. Kernel file mtimes do not move (:6–7).
  - So migration.log line stamps (MigrationRunner.php:299, :314) and `applied_at` (:59) move; the files' mtimes do not.
  - The stand-in never runs the installed public.php or master: no piggyback, and only install_auth_page.php is required (root:270), which uses no session.
  - SqliteStore runs no time-based maintenance at boot.
  - R6's `.redirect106` gate holds: `"5.18.106"` is in bf29cbd's manifest only, not 771a3c1's.
  - None of R6's 106-10 sweeps reads the stand-in's time, web.log or WEB_PID (R6:2446–2596).
- **Anchors:** H1, H2, H5 and H6 each exist exactly once in the generated text, and every insertion order is possible:
  - H1: root:224–226; `$web` is defined before the boot.
  - H2: R6:593 in the generated touch.
  - H5: R6:707–713, after `.redirect106`.
  - H6: 104:2528 / R6:755.
- **H2, H3, H6:**
  - H2: stamp_copy is the script's only touch (S:13827).
  - H3: holds (S:13832).
  - H6: LIVE_BEFORE reads P107 (seen); then read_live 15 reads P106 and is answered 0000000. deploy-hybrid exits 1 (:145–150) and do_rollback returns 1 (S:13843).
- **A5 (question 3):** every post-boot fault can be judged in `--after-only` with the same observables.
  - MG-CA1, NA-CA1, MG-CA2's WARNING branches, r1 and MG_SAY do not depend on the mode (S:8401–8498).
  - The lead and the rollback block print whenever MODE ≠ rollback (S:16147–16173, :16258–16290).
  - R7's second judgement runs in after mode (S:14875–14879); the md5 plant reads CHANGED through db_mig107 (S:12049).
  - The deploy-only prints are the MG_CA2_SEEN writes and the deploy-mode missing-line notes (S:8459–8474), plus LK-CA1/LK-CA2. §T.11 asks for none of them for a post-boot fault; the deploy-run plants cover the rest. The one exception is the redeploy framing in FM3.
- **A7 timing:** the hold (S:13852) comes before the 6-second pause (S:7573) and QF_BASE (S:7576–7578).
- **A4:** MigrationRunner.php and SqliteStore.php are byte-identical at bf29cbd and 771a3c1 (empty diff).
- **A10 hold keys:** "Type ROLLBACK" (S:13898) and "ME-CA1 waiting up to" (S:8380) are each printed once. A prompt with no newline is still matched by the stream grep, as csb1run106 does (R6:889).
