1. **Rule slip.** While building an identifier list for the review, one command redirected output to /tmp/claude-0/-home-user-dishnetuganda/24e2e11c-566f-5aa4-a11b-ebc146471ab5/scratchpad/rh106/.ids.
   - I removed it at once and listed the folder: it holds what it held before. Only its mtime changed.
   - Every later reading was pipes only. No other file was written, and no PHP was run.

2. **What was and was not executed.** Nothing was executed except reads:
   - bash -n over stdin;
   - an ast parse of Part 6's Python;
   - the fixes applied in memory and counted (each old text occurs exactly once; asserted);
   - Python and grep reads of the parts, the script (745e668, sha 3189cee2…), R4/R5 and the two release trees.

   None of 106-10's readings has run. The first dev trial is their test.

3. **106-10a0 infers Part 5's end state from design-v2 §C 106-7i and A10.** Its expected values: P106; key off; "-" for claims; on:336; rblast off:nothing:not-written. The last one rests on these:
   - V1 finding the key off records AS_RB1=off (S:9035);
   - V1's RB_COPY_AT lands in that deploy's state file (S:13827), which the --redeploy keeps as .prev (S:13737) and rblast reads.

   If Part 5 ends otherwise, adjust that line only.

4. **Couplings with Part 5 that could fail 106-10 for a reason outside 106-10.** These are Part 5's choices to keep, not defects found:
   - (a) 6b's uncut set_config control output, if written to any swept file, fails 106-10c.
   - (b) A planted quote_company_phone or quote_company_email that is a 106-range number and is left in kyc_config.json fails 106-10e.
   - (c) A weakened copy left anywhere in $REPO/scripts/.*.sh fails 106-10f.
   - (d) An fx keep folder holding a kept copy fails 106-10f.
   - (e) Removing rh104-send.on would make the refusal-list control read "200:other".

5. **Disagreements found and not fixed, by judgement:**
   - (a) **Two implementations of the state-file fixture.** rh106-seed.php has fx_state_drop and fx_state_back, and p3-interface says Part 4 must use them through seed106r. Part 4 instead built dstdrop106 plus keep106/back106 (p4-open 2, where Part 3's seed106r was missed). Both are asserted and undone. Switching means rewriting 106-5c/5d against an op never run, so I left Part 4 as it is. Recommend dropping the two unused ops from rh106-seed.php at assembly, or a deliberate switch after a dev trial.
   - (b) **Repeated label ids.** Some labels share their id word across checks:
     - 106-pre3a ×4 and 106-pre4c/4d per pass;
     - 106-1d and 106-1e;
     - "106-1a cond" beside "106-1a CS-G1…".

     Each full label is unique at runtime. Changing this was not required.
   - (c) **HELD106=- is a statement, not a reading.** It is consistent with Part 3's own 106-1k1 mirror. If 106-4d fails on held=, the registry's held channel ids must be stated there.

6. **Citations checked and found right.** No fix was needed for these:
   - stop()'s lines: 1689 STOP, 1690 "Nothing was changed", 1691 "Nothing further was done";
   - the CS-G1 and CS-B1 lines 7405-7434, LK-G1 7368/7370, U4 7981-7984, uj_gate_a 7935-7941, FE-G1 8003-8013, 7991;
   - UJ-ON 8062-8100, UJ-CA 8033-8037, ME-CA1 8186-8206 and 8132;
   - AS 8679/8735/8773/8826-8841/9031, R1 14400/14402, Q-CA1 14432, 13737/13776/13806/13838/13840/13841/13869, and the summary 16017/16019/16081/16086-16088/16103-16162;
   - set_config.php:670 in both trees, and lib/EvolutionApiService.php:898-904.

   The three wrong citations found are fixed (S:7959, followup_send:364, sales_followup:170/178).

7. **Not re-run from R5's 105-10.**
   - 105-10e–h (N105/NM105 over the plugin's sinks after 105's copy, and the zone control) are 5.18.105's own masking evidence; R5's record run holds them. 106-10e is a whole-file reading for 106's own range and marker instead. The chain's numbers in sinks written after 106's copies are not judged.
   - QL key files (ql-key-*) and the stand-in's exec records are not swept, as in R5.

8. **106-10c includes master-106m/s-*.out.** By reading, 7835906's and bf29cbd's follow-up jobs print counts only (followup_send.php:362/368, followup_close.php:95), and EvolutionApiService's error_log lines carry no number. A name or number in a master pass would therefore be a real finding, but this is not established by a run.

9. **106-10a's lower bounds** rest on Part 3's 15 refusals (106-pre4e) and R5's EVODRV105. Both are asserted present, or the check fails.