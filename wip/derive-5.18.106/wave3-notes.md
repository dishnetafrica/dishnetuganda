# wave 3 notes (partT, partH; drafting agent)

# test_output
One python3 -I process, everything in memory, nothing written. The modules went in on fd3 and fd4, the driver on fd5. derive106.py's check segment (lines 73–308) was exec'd in memory. bash -n was given the text on stdin.

partT sha16 54a992e81c48c1fa lines 308
partH sha16 e36a601d997d0f69 lines 687
  partA 50 50
  partB 15 65
  partCS 7 72
  partUJ 11 83
  partME 2 85
  partL 12 97
  partR 33 130
  partT 74 204
  partH 65 269
bash -n 0 b''
AFTER_CMD" count 5
  keep check: K1–K5; twelve weakened copies (K1, K2, K3, K4 and K5's eight), each refused for its own reason alone
  report wrapper: as committed, pinned to 7835906; its seven inputs unchanged at the pin
  folder check: 323 functions, 3383 subshells; 25 may make the folder, none in a subshell; 29 read it, each after the folder is made
  folder check control: three weakened copies, each refused for its own reason
result lines 16040 efcab31a4a464789233f836295b09b9824149c8e96e2da86cf5eb6fff4a2af31

Before partH (an earlier run, recorded): the driver's K5 found two writers — line 1570, the old `--clear-switches) echo "… sales-followup.sh off …"`, and as_rb1's saveOverrides(. partH splits the parser line so its echo starts a line of its own, and K5 now finds only as_rb1's write.

Not run: a stub execution of the printed §F block and the check-it block with bash -s. A built-in command-parsing safety check refused the command. It contained no removal; the check could not parse it. Those blocks pass bash -n inside the whole script, K1 and K5, and partH's own order and echo-only asserts. Their printed output was not executed.

# notes
Both parts apply on top of partA–partR: partT makes 74 asserted edits, partH 65, 269 in total. bash -n gives 0. The keep check (K1–K5 and its 12 weakened copies), the report-wrapper check, the folder check (DS3-fix1) and its 3 weakened copies all pass. The result is 16,040 lines, sha256 efcab31a…a2af31. Nothing was written anywhere. partH must run after partT: its "every switch kept, unqualified" scan needs partT's new LK title.

ANCHORS CHANGED (for partZ and the rehearsal):
- qf_stage's header comment now begins "# qf_stage — QF-G1–QF-G3, LK-G1, CS-G1, QF-N1–QF-N7 and U1–U5 (CS-G1 and U1–U5 since 5.18.106, root docs/75 §L.3,".
- The stage-A call site is rewritten: the comment, `hdr "A. The sales follow-up (5.18.104, Part F's QF, kept live by 5.18.105's LK; 5.18.106's CS-G1 and U1–U5; read-only, on copies through the pin's code)"`, qf_stage, then the LM-G1/LM-G2 comment, which now names 7835906.
- The stage-R heading is now `hdr "R. The masked lines, installed (5.18.105's LM, kept by $EXPECTED_VERSION;…`.
- The LK rule's title is now "…— every ladder switch kept exactly as found ───…", rebuilt to the old line's width.
- The --clear-switches parser is now TWO code lines: the label `    --clear-switches)   # refused since 5.18.105; …` and `      echo "--clear-switches: refused — … Nothing was run" >&2; exit 64 ;;`. Any carried check that expects one code line naming --clear-switches must now expect two.
- The ECO Bank guard is now `if [ "$MODE" != "rollback" ] && [ "$FAIL" = "0" ] && [ "$NOT_YET" = "0" ] && [ -n "${V3J_WBB:-}" ]; then`. NOT_YET is set to 0 by partUJ (`PASS=0; FAIL=0; NOTE=0; NOT_YET=0`), so set -u is safe.
- New §F block, inserted right after the line holding "as_after_verdict   # 5.18.106 (root docs/75 §L.7, DL6)". Its guard is `if [ "$MODE" = "after" ] && [ "$FAIL" = "0" ] && [ "$NOT_YET" = "0" ]; then`. It prints, in order:
  1. followup_daily_cap with '<CAP>' and $(LOGW daily-cap);
  2. `cd $REPO && bash $QF_WRAPPER on chase`, then `… --check chase`;
  3. followup_run_limit with '<LIMIT>' and $(LOGW run-limit).
  Then it calls as_back_on, once, its only call.
  - Each set_config command prints only when DB_OWNER and PDD_IN are known; otherwise a line says why no command is printed.
  - It never expands $OWNER_AUTOSEND_CMD; it uses the same form as partR's assignment.
  - '<CAP>' and '<LIMIT>' match set_config's placeholder refusal, so pasting a command as printed saves nothing (asserted against set_config at the pin).
- Summary blocks replaced:
  - names; "what it means" (rollback); "what changed"; four "what did not" items.
  - check it: three `echo "      $AFTER_CMD"` — 20 minutes after the copy, after a close pass, next day 15:00–17:00 UTC inside the send window — then $QCHECK_CMD.
  - the screens: both branches now mention the Follow-ups closes.
  - your steps: adds the enquiry-report command and the note that its section E misreads after the copy.
  - "deploy again"; the NOT YET line now says the owner's steps wait for a PASSED run; the rollback paragraph (RB106).
- Header: title, 5.18.106 paragraph, 5.18.106's own and 5.18.105's carried checks, QC-RB1, the catalogue's rollback, QS, TN/Q/98, pilot, commit, operator rules (window 05:00–08:00 UTC Mon–Sat, 14:55–15:10 UTC), after-only, steps, rollback, options (--after-only, --redeploy, --check, --clear-switches, --rollback, --move-list-only, --quote-company-off), never-does, writes, stages A/B/V/R/RB/F.

NAMES INTRODUCED: no variables or functions. The only new strings in code are the LOGW log names daily-cap and run-limit.

partT's §L.6 handling:
- Each line is read from the source by number, after checking the source's sha256 against ctx["src_sha"]. It must stand once and untouched, and exactly one $BASELINE_VERSION use is made literal 5.18.104.
- 9185 and 10902 change their second use; their first, which means the live release, is asserted kept.
- 7567, 8472, 8492, 9163, 9164 and 9181 are asserted unchanged.
- 10965 is asserted as partB left it.

COUNTS: every count in new text (added, changed, kept, pin, runtime) comes from ctx (partA). Every file a new text calls "kept" is asserted kept, outside the delta, and the same at 7835906 and the pin. set_config's keys, placeholder regex, success line and AI SETTINGS cut, and FollowUpPolicy's 8–20 window and Sunday rule, are asserted at the pin.

WHAT partZ CAN ASSERT:
- Absent: /numbers( in)? again/i, "WRITES PHONE", "reached by nothing", "read by nothing".
- as_back_on called once.
- '<CAP>' and '<LIMIT>' once each.
- K5 still finds only as_rb1's write.
- partT's body-mustnot list and partH's header-mustnot list.

JUDGED AS HISTORY AND LEFT (correct as written):
- source lines 1496, 1556, 1581, 6063;
- 7176, 7330, 7429 ("994d660's bytes in both releases" is true);
- 8121, 8202, 8211, 8237, 8292, 10251 (LK and 5.18.105 history);
- 749;
- 12157 and 12881–12911 (A6);
- master's comments (partB's);
- 14365, 14392 (Part V is 5.18.104's);
- 14552, 14794;
- 14821 (5.18.103's prompt, accurate for non-voice turns);
- 15164, 15169, 15216;
- 15513 (partR's);
- the header's Part F/V/O/P paragraphs, which stand as amended.

DECISIONS:
- ECO Bank's read-only check now also waits for NOT_YET=0. Under §L.5 a NOT YET prints exactly one next command, the --after-only, and this check asks only G3's question, so it loses nothing by waiting.
- The enquiry report command is printed under "your steps" (§L.2), with the warning about section E.

NOT DONE / UNVERIFIED:
- The printed §F and check-it blocks were not executed with stub variables: a built-in safety check that could not parse the command refused it (no removal was involved). Their escapes were checked by reading them, and they pass bash -n and the K1/K5 checks inside the whole script.
- No php, test suite, deploy or rehearsal was run, and no file was created or changed.

# wave 3 notes (partZ and the whole driver; drafting agent)

# test_output
The whole driver was run in memory twice, in two separate python3 -I -B processes. It ran derive106.py's own source with argv REPO=/home/user/dev105, RELEASE_REPO=/home/user/b106 and PIN_FULL=bf29cbd7171639f93e674ab174a6d09948b68932, with all ten parts in sys.modules. partA, partB, partCS, partUJ, partME, partL and partR were imported from disk; partT and partH were taken verbatim from the task text; partZ was taken from the text above. Two writes were patched out, each anchor asserted once: bash -n now reads stdin instead of a temp file, and the final write became a "NOT WRITTEN" print. A check then confirmed that no NamedTemporaryFile, os.replace, os.chmod, os.unlink, "w" or .write( was left in the driver.

Run 1 (rc 0):
module partT: 308 lines, 31243 bytes, sha256 54a992e81c48c1fa787b92900abcf7820d0683c48d43a790211555a02d12892b
module partH: 687 lines, 77264 bytes, sha256 e36a601d997d0f691c804ba4fb6726676cb9c09c364dd5490c77764e8c1b8e68
module partZ: 342 lines, 24155 bytes, sha256 ec97d96676aeba8e6fd69481ec5270efae3ea9ed582937bed4355cb34c8968b7
  partA: 50 edits (50 so far)
  partB: 15 edits (65 so far)
  partCS: 7 edits (72 so far)
  partUJ: 11 edits (83 so far)
  partME: 2 edits (85 so far)
  partL: 12 edits (97 so far)
  partR: 33 edits (130 so far)
  partT: 74 edits (204 so far)
  partH: 65 edits (269 so far)
  partZ: 1 edits (270 so far)
  keep check: K1–K5; twelve weakened copies (K1, K2, K3, K4 and K5's eight), each refused for its own reason alone
  report wrapper: as committed, pinned to 7835906; its seven inputs unchanged at the pin
  folder check: 323 functions, 3383 subshells; 25 may make the folder, none in a subshell; 29 read it, each after the folder is made
  folder check control: three weakened copies, each refused for its own reason
  bash -n (stdin): the deploy script rc 0
  bash -n (stdin): scripts/sales-followup-report.sh rc 0
  bash -n (stdin): scripts/sales-followup.sh rc 0
NOT WRITTEN (patched out) /home/user/dev105/scripts/deploy-5.18.106.sh: 16038 lines; added 3, changed 20, kept 455; source sha256 c70820cd69af899b…, result sha256 75f5dada4dc2452366d824974005e1bd448269f5b701a3764a1ab6f32b87eb97
parts: partA 50, partB 15, partCS 7, partUJ 11, partME 2, partL 12, partR 33, partT 74, partH 65, partZ 1; total 270
RESULT 75f5dada4dc2452366d824974005e1bd448269f5b701a3764a1ab6f32b87eb97 16038 lines, 1620974 bytes; partZ: 17 rules, 25 weakened copies

Run 2 (a separate process, rc 0): the parts line and the RESULT line are identical.

BYTE-IDENTICAL: 75f5dada4dc2452366d824974005e1bd448269f5b701a3764a1ab6f32b87eb97
The two runs' whole outputs are identical.

Nothing was written:
- git status --porcelain in /home/user/dev105 is empty.
- /home/user/dev105/scripts/deploy-5.18.106.sh does not exist.
- -B means no __pycache__ was written.

The text returned in modules[0].text was itself checked by json.loads plus sha256: 342 lines, 24155 bytes, sha256 ec97d966…8968b7, a MATCH with the text the driver ran.

# notes
RESULT
- Every part applied, and partZ's 17 rules hold on the result with no refusal.
- Each of the 25 weakened copies is refused by its own rule alone, and every rule Z1–Z17 has at least one weakened copy.
- The driver checks passed: keep_check K1–K5 plus its 12 weakened copies, the report wrapper check, folder_check plus its 3 weakened copies, and bash -n rc 0 on the result and on both wrappers.
- Summary line: 16038 lines; added 3, changed 20, kept 455, as the driver doc expects.
- Result sha256 is 75f5dada4dc2452366d824974005e1bd448269f5b701a3764a1ab6f32b87eb97, byte-identical across two separate processes.
- No earlier part's edit needs to change.

ANCHORS CHANGED
- partZ makes exactly one edit: close(doc, "U5", "as_u5   # U5"), which removes partUJ's slot-U5 marker line under partR's U5 call. close() asserts that the line above starts with ctx["as_slot_before"].
- partZ first refuses unless ctx["as_slot_before"] == "as_u5   # U5".
- No other text is touched.

NAMES INTRODUCED
- The module-level constants: SLOT, V1, WRITE, AS_EXEC, PIPES, PASSED, NY_ELIF, FAILED, OWN_IF, ECO_IF, AFTER, ROLLBACK, PUTBACK, STAGE_R, STEPS, CODE_RETIRED, RETIRED_CODES, NEW_CODES, ONCE, NONE, COUNTS.
- The functions rules(t) (returns every failure together), _code, _body, _ordered, _rep and _at.
- CONTROLS.
- The ctx keys z_rules=17 and z_controls=25.

RETIRED FROM 5.18.105'S partZ
- Its edits (drop_case, the vault lists, the stop text, the here-strings) are already in S105. Z9 asserts the four here-strings stay and their pipe forms are absent.
- Its D3 rule required LM-RB1's "WRITE PHONE NUMBERS AGAIN" and the step-12 notice once each. It is replaced by Z8, an absence check over /numbers( in)? again/i, "WRITES PHONE" and "step-12 notice" (§L.6).
- Its RB_NOTHING D1 rule is carried, re-pointed to rb106, in Z3.

WHAT EACH RULE CATCHES (the weakened copy named here is the one that proves it)
- Z1: the switch tool's on/off/skip/set/erase, or the wrapper, run in code rather than echoed. Copy: qf_tool on chase. It also counts at least 8 qf_tool calls, each show, --check or doctor.
- Z2: any set_config --key write in code other than Q-CA6's throwaway run and OWNER_AUTOSEND_CMD's text. Copy: set_config followup_enabled.
- Z3: rb_pre106 not called just before ROLLBACK is typed. Copy: the call deleted. Z3 also catches:
  - the prompt losing AS_RB0_SAY;
  - rb106 called other than from V1 and RB;
  - the RB_NOTHING branch losing its plain note or its seven LK-RB1 FAILs;
  - --rollback's order (rb_pre106, as_rb1 typed, do_rollback, RB_NOTHING) broken.
- Z4 has four copies:
  - a second saveOverrides;
  - the write without -u "$DB_OWNER";
  - V1's as_rb1 after its do_rollback;
  - eval "$OWNER_AUTOSEND_CMD".

  It pins: exactly one saveOverrides($dd, ['followup_auto_send' => '0']), inside as_rb1; the docker exec -u "$DB_OWNER" -e AS_ROOT="$IN_CONTAINER" line once; as_rb1 called exactly twice (typed, and in V1), each before do_rollback; OWNER_AUTOSEND_CMD assigned once, inside as_owner_cmd, and expanded once, as as_owner_text's printf argument, with no eval, sh -c or pipe.
- Z5: rb_pre105, rb105 or RB105 back, or retired helper names in code. Copy: "# rb_pre105".
- Z6: --clear-switches no longer refused with exit 64 on its own echo line. Copy: the exit 64 dropped.
- Z7: a new code (LK, LM, CS, U1–U5, UJ, ME-CA1, AS-RB0/1/2, RB106) no longer printed by any check, or a retired code printed again. Copy: CS-B1 renamed.
- Z8: the "numbers again" text reintroduced. Copy: a note saying it.
- Z9: a judged grep -q reading from a pipe again (a false result under pipefail/SIGPIPE). Copy: the first here-string reverted. It requires at least 28 here-string greps and the drained form.
- Z10: slot U5 left open, or U5 not where its slot stood, before cih_drop. Copy: the marker re-added.
- Z11 has three copies: NOT YET exiting 0 (exit 3 is required); notyet called outside UJ-ON; an owner step printed in the NOT YET branch. It also pins: notyet() defined once; return 3 once; PASSED, NOT YET, FAILED in that order; the NOT YET branch printing only --after-only.
- Z12: ME-CA1's NOT ESTABLISHED turned into a FAIL. Copy: note changed to bad. It also checks there are exactly 4 such notes and that a real FAIL exists.
- Z13: stage R reordered, or a call duplicated. Two copies.
- Z14: the run folder pointing at 5.18.105, or 994d660 used as the baseline in code. Two copies. It also checks the title and the release branch; the only 5.18.105 identities allowed are state-5.18.105.env and release/5.18.105 (7835906).
- Z15: two printed commands adjacent with no text between, in the summary or the header (root docs/44 §16.9). Copy: ROLLBACK_CMD printed right after AFTER_CMD. It also checks the rollback command is printed once, after the verdict.
- Z16 has two copies: ECO Bank's block printed after a NOT YET; §F's steps out of order. It pins:
  - the §F steps after a PASSED --after-only only, in order cap → on chase → --check chase → run_limit, then as_back_on;
  - writers only on echo lines;
  - [ "$NOT_YET" = "0" ] exactly 5 times;
  - AFTER_CMD printed 4 times, 3 of them in check it;
  - the order verdict → as_after_verdict → §F → ECO → put-back.
- Z17: a carried text re-pointed by partT or partH reverting. Copy: one old phrase re-inserted.

HOW THE TEST WAS RUN
- The 128 KB limit on one argument (MAX_ARG_STRLEN) made earlier all-in-one commands fail with E2BIG at 150 KB and 132.5 KB.
- One attempt held the texts in a background loopback server. The permission classifier denied it, and it was not retried.
- What worked: the modules travelled on stdin with '@'-indentation plus single-character tokens.
  - That transport changed whitespace in two Python continuation lines of partH (lines 672–673 of i_cap…, 41 spaces instead of 42). partH's sha then differed (a256c4d4…), though the result did not (75f5dada…).
  - So the final run instead read partT and partH verbatim from the task text in this agent's own session transcript, read-only. Their sha256s, 54a992e8… and e36a601d…, match the task's own.
  - partZ was read the same way from the transcript and is identical to the text returned.

NOT DONE, AS INSTRUCTED
- No php, no suite, no deploy or rehearsal script.
- No file created, modified or deleted.
- /home/user/rel105 and /root/.claude/uploads were not opened.

Files: /home/user/dev105/wip/derive-5.18.106/derive106.py, /home/user/dev105/wip/derive-5.18.106/d106lib.py, /home/user/dev105/wip/derive-5.18.105/partZ.py (the model).