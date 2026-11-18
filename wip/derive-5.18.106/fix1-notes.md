# fix round 1 notes (partUJ, partR; drafting agent)

# test_output
Expected module hashes (as tested): partUJ sha256 ca5aadc533b6641202ac9ad493cbb0d85f994aa9a79282809614fbbda28479a7 (850 lines, 77,885 bytes); partR sha256 79b1320cc83f751226c445409751f222eccb6886b470287307c88abca4f7bdf6 (1,035 lines, 96,469 bytes). Before handover a second process re-ran the edit script from the disk parts and confirmed both modules match these hashes. The texts above were copied from that process's JSON-escaped output. Check both hashes before writing them to disk.

CHAIN (one python3 -I -B process, writes nothing; order: partA, partB, partCS from disk -> partUJ from text -> partME, partL from disk -> partR from text -> partT, partH, partZ from disk):
- part edits: partA 50, partB 15, partCS 7, partUJ 12, partME 2, partL 12, partR 33, partT 74, partH 65, partZ 1 = 271
- keep check K1-K5 passed; twelve weakened copies (K1, K2, K3, K4 and K5's eight) each refused for its own reason alone
- report wrapper: as committed, pinned to 7835906; its seven inputs unchanged at the pin
- folder check: 326 functions, 3,403 subshells; 25 may make the folder, none in a subshell; 29 read it, each after the folder is made; control: its three weakened copies each refused
- bash -n (stdin): the deploy script rc 0; scripts/sales-followup-report.sh rc 0; scripts/sales-followup.sh rc 0
- partZ: all 17 rules applied; its 25 weakened copies each refused
- RESULT 584b3b4b25a8d10ba42aafd56d6ffb4608282a2310b7bee068136bc741bc3cf6, 16,133 lines, 1,631,598 bytes. A second process gave the same result: 271 edits, identical sha256.
- against run1 (75f5dada…, 16,038 lines): 160 lines added, 65 removed; no added comment line is over 120 characters
- No partH or partZ anchor broke.
- First run failed in partR's names-are-new guard: the uj_fe_g1 comment in partUJ named OWNER_AUTOSEND_CMD. Reworded; partT, partH and partZ had failed only as a cascade. Run 2 passed everything.

STUB CHECKS (bash on stdin, the real functions extracted from RESULT, no files, no docker, no php):
FE-G1:
- en=off: prints the capitals notice and the command alone on its line, then the 'followup_enabled = 1' sentence, then STOP 'NO-GO (FE-G1): …'; rc 1. The printed command (docker exec -u 33:33 -e DN_DATA_DIR=/data/pd c1 php /app/plugin/tools/set_config.php --key followup_enabled --value 1 2>&1 | sed '/^  AI SETTINGS$/,$d' 2>&1 | ( umask 077; exec tee …/followup-enabled-1-<ts>.log )) passes bash -n with rc 0.
- en=on: no output, rc 0.
- UJ_A empty: no output, rc 0.
- UJ_A not a whole reading: no output, rc 0.
- DB_OWNER empty with en=off: prints 'No command is printed: the owner of plugin.sqlite3 is not known', then STOP.

uj_on gate split:
- G1 (off now): 'UJ-ON not judged — its gate did not hold: … F3 then has nothing to do, or another limit to do it by'.
- G2 (ug=0 at stage A) and G5 (en off at stage A): 'UJ-ON not judged for this deploy — its gate did not hold at stage A: … F3 may be running now … F3 stays unproven … A judged UJ-ON needs a rollback and then a --redeploy'.
- G3 (limit differs): the same note, plus '— or followup_max_age_hours set back to stage A's 168 h'.
- G4 (off now and differs): the 'now' note, which carries the stage-A clauses as well.

uj_judgeable / uj_gate_a:
- status 0 for a full on-reading, an empty UJ_A and an unread UJ_A.
- status 1 for ug=0, hours=misread and en=off, each with its reason from uj_gate_a.

NOT YET summary:
- N1 (control=not) and N2 (250 cancelled): the wait line and the summary line each carry the counts exactly once.
- Controls: F still FAILs, P still PASSes.

rb_pre106, the four inputs plus extras:
- noowner: capitals 'THE ROLLBACK CANNOT SET followup_auto_send OFF ITSELF (noowner: …' with no command; AS_RB0_SAY 'NOT set off — the rollback cannot write it (above)'.
- unread(no_answer): no capitals, only 'No command is printed: the one reader could not run'; the prompt says 'set off first only if AS-RB1, reading it again, finds it on and the five conditions holding'.
- eff=unread with pre=ok: 'THE ROLLBACK MAY NOT SET … (it could not be read as the jobs read it) … WHILE IT READS ON' with the owner's command; AS_RB0_SAY 'not set off unless AS-RB1 …'.
- eff=on with pre=differ: 'CANNOT … (condition 3: …)' with the command.
- code, and unread with file: 'CANNOT' with no command.
- on with ok, and off: unchanged.

RB note for each AS_RB1 value:
- written and written-check:*: 'nothing else moved but followup_auto_send … wrote one key'.
- unknown:*: 'whether it wrote followup_auto_send '0' … is not known'.
- off, not-written:* and empty: 'wrote no key (AS-RB1: …)'.

as_rb2:
- unread with not-written: a note; AS_RB2_EFF=unread.
- unread with written: FAIL.
- unread with unknown: FAIL.
- RB_NOTHING: a note.
- on with not-written: a note.
- off: ok; AS_RB2_EFF=off.

as_after_verdict:
- written-check while reading off: the plain 'reads off now … nothing to run'.
- written-check while reading on: 'WROTE … BUT ITS READ-BACK DID NOT HOLD', then the command.
- unknown: 'WHETHER … IS NOT KNOWN'.
- not-written: 'DID NOT SET', then the command.
- written: prints nothing.

as_para:
- rb written-check, v1 unknown and rb not-written: each the correct headline.
- v1 written: prints nothing.

as_back_on and as_rb_last:
- The command (--value 1) is offered only when the rollback's RB_COPY_AT is before this state file's COPY_AT, AS_RB1=written and the key reads off.
- Nothing is printed when the rollback is after the copy, the record says off, COPY_AT is missing, the key reads on, MODE is deploy, or no rollback is on record.
- as_rb_last prints [written 3000] and [off 5000] for the two newest-record cases, and [] when no rollback is on record.

# notes
Both modules are revised from their disk versions, which are not modified. Copy the two texts as given and check the hashes in test_output before writing them. All 11 REAL findings in my scope are handled; L8-5, L8-6 and L8-7 (REFUTED) are untouched.

1. DECISION FOR THE OWNER: FE-G1 refuses only on followup_enabled
FE-G1 stops a deploy only when followup_enabled reads off (DL11). When stage A reads StaffJobsGate false or a misread age limit, the deploy still PASSES, and UJ-ON then cannot judge F3 for that deploy: uj_on says F3 stays unproven and names a rollback plus --redeploy. The capitals merges of L8-1 point 1 for those two cases are not built, because DL11 replaces them. Whether FE-G1 should also refuse on those two cases is the owner's call.

2. ANCHORS PARTH OR PARTZ READ — none broke in the chain run
- qf_stage now holds the line '  uj_fe_g1   # FE-G1 — 5.18.106 (DL11): …' between the uj_stage call and as_u5. Z10 and the slot close are unchanged.
- The RB note still starts 'note "RB the code is $BASELINE_VERSION's again where $EXPECTED_VERSION changed it — the four follow-up jobs and lib/FollowUpService.php — and ', but its middle is now a $( case "${AS_RB1:-}" in … esac ) with three branches.
- as_back_on no longer reads REDEPLOY.
- as_rb_last now prints '<AS_RB1> <RB_COPY_AT>'. Only as_back_on reads it.
- The AS_RB0 init line gains '; AS_RB2_EFF=""'.
- as_para and as_after_verdict have new headline arms; their function names and call sites are unchanged.
- rb_pre106's local line is now 'local o sub="" a f c w'.
- The uj notes line gains 'now (in $(kv "$o" tz), the zone the sender reads)'.
- The uj PHP line gains a tz= field.
- QF_PHP gains the DD block (a putenv of DN_DATA_DIR for uj) right after the DN_VAULT_FILE line.

3. NEW NAMES
- partUJ shell functions: uj_fe_g1, uj_gate_a, uj_judgeable.
- partUJ check code: FE-G1.
- partUJ log name: followup-enabled-1.
- partUJ PHP field: tz=.
- partUJ Python constants: FE_CMD, FE_STOP, FE_CALL, DD.
- partR variable: AS_RB2_EFF (on, off or unread), set by as_rb2.

4. FOR PARTH
- The check-it sentence (L8-1 point 3): 'if uj_judgeable; then …; else … $(uj_gate_a "$UJ_A") …; fi'.
- 'What it means' (L8-3): choose the clause by AS_RB2_EFF (on, off or unread).
- L8-2 (LK_AO_ON) is still partH's to do.
- L8-4 wording: header lines ~736-739 and the §F comment at ~15958 should say 'after a deploy that followed a rollback which set it off' rather than '--redeploy's'. BR1's note needs no change.
- DL11 header rules, in this order: step 6 off first (and steps 11 and 12 if on), THEN followup_enabled on, each its own command. Never the reverse.
- Name FE-G1 in four places: the readings paragraph (FE-G1 is the one refusal), the --check option text, the stage-A list, and the summary's names line.
- partT's qf_stage comment and the stage-A hdr also need FE-G1. partZ's Z17 pins that hdr, so partT, partH and partZ must change together.

5. DOCS/75 UPDATES
- §L.4: DL2 now has one exception, FE-G1.
- §L.5: the uj_on gate split (false now, against false only at stage A).
- §L.8: as_back_on no longer depends on --redeploy.
- §L.9: add DL11.

6. WHAT PARTZ SHOULD PIN
Z7: add 'FE-G1' to NEW_CODES.

Pin once:
- FE_CMD, FE_STOP and FE_CALL;
- 'uj_fe_g1() {', 'uj_gate_a() {', 'uj_judgeable() {';
- the DD putenv line, and the tz= line;
- 'UJ-ON not judged for this deploy — its gate did not hold at stage A:';
- the AS_RB2_EFF init, and its assignment in as_rb2;
- 'THE ROLLBACK CANNOT SET followup_auto_send OFF ITSELF (noowner: ';
- "followup_auto_send reads off now, through $BASELINE_VERSION's code (AS-RB1: ";
- the three RB-note branches;
- as_rb2's not-written note;
- '[ "$t" -lt "$c" ] || return 0'.

Pin twice:
- 'notyet "UJ-ON NOT YET — $UJ_NY_SAY$alone; run it again after $UJ_NY_AT"';
- 'THE ROLLBACK $w SET followup_auto_send OFF ITSELF ($c): ';
- "AS-RB1 WROTE followup_auto_send '0', BUT ITS READ-BACK DID NOT HOLD (";
- 'WHETHER AS-RB1 SET followup_auto_send OFF IS NOT KNOWN ('.

Pin as absent:
- 'THE ROLLBACK CANNOT SET followup_auto_send OFF ITSELF ($(as_causes_say';
- "s/^REDEPLOY=//p" inside as_back_on.

New rule, with a weakened copy: FE-G1's call sits after 'cs_check CS-G1' and before as_u5, and FE_CMD stays an echo line.

7. HOW FE-G1 IS BUILT
- FE_CMD is printed by echo, not printf. The driver's _is_display rejects any printf line holding '|', and Z2's display pattern has no printf, so a printf line would count as a K5 writer and a Z2 run. An echo line is display text for both, the same way partH prints its §F set_config lines. K5 stays true with no driver change.
- No 'off chase' command is printed. CS-G1 runs earlier in qf_stage and stops whenever a claim switch is present, so FE-G1 is reached only when none is. partUJ asserts that cs_check shape and the call order.
- With DB_OWNER empty, FE-G1 prints no command and still stops.
- With en missing, or UJ_A not a whole reading, FE-G1 does not refuse (DL2).
- partUJ asserts four facts from Git:
  - tools/set_config.php is identical at 7835906 and the pin;
  - followup_enabled is a bool there with no special handling, and it prints the '  followup_enabled = 1' success line followed by its AI SETTINGS listing (the cut);
  - followup_enabled is not a secret at the pin, and conf keeps it via $n1;
  - dn_tz() reads DN_DATA_DIR's kyc_config.json (UJ-1).

8. OTHER CHOICES BEYOND THE LISTED FINDINGS
- RB-1: the first AS-RB0 note also gained a noowner arm.
- UJ-1: added the tz= field, so the log names the zone that was read.

9. KNOWN LIMIT
A rollback whose git checkout failed records no RB_COPY_AT, so as_rb_last finds the record of the rollback before it. as_back_on is then judged against that older record.

# fix round 1 notes (partH, partZ and the whole driver; drafting agent)

# test_output
The whole driver (wip/derive-5.18.106/derive106.py) ran in memory, twice, in two separate python3 -I -B processes, with argv REPO=/home/user/dev105 RELEASE_REPO=/home/user/b106 PIN_FULL=bf29cbd7171639f93e674ab174a6d09948b68932. The two bash -n tempfiles were patched to stdin and the final write was patched out ("NOT WRITTEN (patched out)").

Modules:
- partA, partB, partCS, partME, partL and partT: read from disk, unchanged.
- partUJ and partR: the first agent's, as given.
  - partUJ sha256 ca5aadc533b6641202ac9ad493cbb0d85f994aa9a79282809614fbbda28479a7, 850 lines.
  - partR sha256 79b1320cc83f751226c445409751f222eccb6886b470287307c88abca4f7bdf6, 1,035 lines.
- partH (revised): sha256 c3490c929356ead99f635700f4d5c688133ec635088f8b5413928641d07a2079, 814 lines, 92,517 bytes.
- partZ (revised): sha256 6f3ae0482441b134c018534585abd4096644cbeffcfa18dd7216f1d56ad8208f, 596 lines, 46,236 bytes.

The run (both processes the same: rc 0, nothing on stderr, log sha256 prefix 4d5cd044a1b42e84 in both):
- Edits: partA 50, partB 15, partCS 7, partUJ 12, partME 2, partL 12, partR 33, partT 74, partH 71, partZ 1. Total 277.
- Keep check K1–K5 passes. Twelve weakened copies (K1, K2, K3, K4 and K5's eight) are each refused for their own reason alone, and K5 still counts exactly one writer, as_rb1's saveOverrides.
- Report wrapper: as committed, pinned to 7835906; its seven inputs are unchanged at the pin.
- Folder check: 326 functions and 3,412 subshells. 25 functions may make the folder, none in a subshell; 29 read it, each after the folder is made. Its three weakened copies are each refused for their own reason.
- partZ: z_rules 28 (Z1–Z28), z_controls 47. Every weakened copy is refused by its own rule alone, and every rule has at least one.
- bash -n (stdin) returns rc 0 for the deploy script, scripts/sales-followup-report.sh and scripts/sales-followup.sh.
- Result (not written): 16,179 lines, 1,637,490 bytes, sha256 a856464398e6b4910c4913e83871b137e30d60c690828830d12e847b881c7d16. Files: added 3, changed 20, kept 455. Source sha256 c70820cd69af899b….
- The two runs are byte-identical: the same result sha256, the same log sha256, the same counts.

Stub checks (bash on stdin with stub functions, no files, docker or php) on the summary texts partH writes:
- check it: an empty UJ_A, or a whole reading whose gate holds, prints the "UJ-ON judges F3 on it" line. ug=0 and hours=misread print "UJ-ON WILL NOT JUDGE F3 FOR THIS DEPLOY" with uj_gate_a's reasons, the leading space and trailing ';' stripped.
- what it means:
  - AS_RB2_EFF=off gives the plain line.
  - on gives the capitals, then: "printed above (AS-RB2)" when RB_NOTHING is set; "AS-RB2's FAIL above says so" when AS_RB1 is written or off; "printed after the verdict" when AS_RB1 is not-written:*, unknown:* or written-check:*.
  - unread or empty gives "whether it reads off now is not shown".
- what changed:
  - deploy: the CS-B1 clause with the steps that are on.
  - after, LK_AO_ON=chase,list: "the steps on now (chase list)".
  - after, LK_AO_ON=-: "(-)".
  - after, LK_AO_ON empty: "(not read — LK-AO above)".
- what did not: deploy gives the CS-G1/CS-B1 clause; after gives "but for your own steps since the deploy".
- §F step 2:
  - chase on: the already-on text, no on chase command, then --check chase.
  - chase not on (LK_AO_ON=list or -): the step text, on chase, then --check chase.
  - LK_AO_ON empty: the show command first, "run on chase only if…", then on chase and --check chase.
  - on chase is printed at most once in every case.

Nothing was written. dev105's git status is clean (0 lines) and scripts/deploy-5.18.106.sh does not exist. b106's git status is clean (0 lines). The parts on disk are unchanged: partH.py e36a601d…, partZ.py ec97d966…, partUJ.py cddbe21b…, partR.py a65f9f96….

# notes
INTEGRITY. Check each module's sha256 before writing it to disk:
- partH: c3490c929356ead99f635700f4d5c688133ec635088f8b5413928641d07a2079 (814 lines).
- partZ: 6f3ae0482441b134c018534585abd4096644cbeffcfa18dd7216f1d56ad8208f (596 lines).

The texts here were hand-transcribed from JSON-escaped printouts. If either hash differs, rebuild read-only from this subagent's transcript, /root/.claude/projects/-home-user-dishnetuganda/24e2e11c-566f-5aa4-a11b-ebc146471ab5/subagents/workflows/wf_8cc82898-5f5/agent-a11aa541721a02da7.jsonl:
- Each blob is a Bash command starting ": <<'BLOB_END'\n#BLOB <tag>\n".
- exec the blob harness-v1, then call build(("H-edits-v1","H-fix-1","H-fix-2","H-fix-3","H-fix-4","Z-edits-v1","Z-fix-1","Z-fix-2","Z-fix-3","Z-fix-4","Z-fix-5")). It returns {partUJ, partR, partH, partZ} with these hashes.
- Then re-run the driver once before committing (expected result sha256 a8564643…7c16, 16,179 lines).

PARTH, per finding:
- L8-1 (point 3). "check it" now reads `if uj_judgeable; then` <UJ-ON judges F3 on it…> `else` <UJ-ON WILL NOT JUDGE F3 FOR THIS DEPLOY: its gate did not hold at stage A ($(uj_gate_a "$UJ_A" …)) … a judged UJ-ON needs a rollback, then a --redeploy whose stage A holds the gate> `fi`. The second $AFTER_CMD is printed in both branches.
- L8-2, three parts:
  - A global LK_AO_ON. LKAO_DEF (two comment lines plus LK_AO_ON="") goes after the LK_* init line. Its one assignment, LKAO_SET (LK_AO_ON="$(qf_kv "$o" on)"), goes right after lk_ao's lk_ok line, so it is set only on a whole reading. mustnot("LK_AO_ON") runs at the top of apply.
  - "what changed" and "what did not" take mode-dependent claim-switch clauses: $MODE=after gives the LK-AO wording; otherwise the CS-B1 / CS-G1 clauses.
  - §F step 2:
    - case ",$LK_AO_ON," in *,chase,* → already on. on chase is not printed, because Git shows sf_on re-stamps quote_chase_not_before and admit has no isOn check; both facts are asserted from the pin.
    - *) → if LK_AO_ON is non-empty, the command. If it is empty (LK-AO could not read), the show command first and "run on chase only if show does not list chase", then the command.
    - --check chase follows in every case.
- L8-3. "what it means" goes by AS_RB2_EFF: off / on (capitals, and where the command is: RB_NOTHING, written|off, or else) / unread.
- L8-4. These texts say "a deploy after a rollback", "--redeploy typed or not": the header's rollback paragraph, the --redeploy option, stage F, the §F block's comment and "deploy again". BR1's note no longer implies nothing is lost: it says the PASSED --after-only reads the latest rollback's record and offers the command to turn followup_auto_send back on, and that --redeploy changes only the run's name, its log's and the deploy command a --check prints. Please spot-check that last clause against partA/partB.
- DL11, FE-G1:
  - Readings paragraph: "U1–U5: notes — FE-G1, below, is their one refusal", plus an FE-G1 sentence (refuses only on a whole reading of off; not read → no refusal).
  - Rules: "FE-G1 refuses otherwise" and the 18 Nov interim order — step 6 (and 11, 12) off first, THEN followup_enabled on, never the reverse.
  - Also covered: the --check wisdom line, the --check and --redeploy options, stages A and F, the summary's names (FE-G1, DL11) and "deploy again".
  - partT's stage-A hdr, its qf_stage comment and its stage-A call comment are re-edited in partH, because PARTS order puts T before H.

PARTZ:
- Z1–Z17 kept; Z7's NEW_CODES gain FE-G1; Z17 pins the new stage-A hdr and refuses the old "5.18.106's CS-G1 and U1–U5;".
- New rules, and what each pins:
  - Z18 FE-G1 (DL11): one call, in qf_stage, after cs_check CS-G1 and uj_stage, before U5. The body starts with the whole-reading guard and ends with the single stop. FE_CMD (the only line carrying followup-enabled-1) is printed by echo before the stop.
  - Z19 UJ-1's putenv DN_DATA_DIR, three lines after the vault rule at QF_PHP's top, plus the tz texts.
  - Z20 the uj_on gate split: false now → "F3 then has nothing to do" (once); false only at stage A → not judged for this deploy; no stage-A text inside g.
  - Z21 UJ-3: two UJ_NY_SAY assignments ending "; $cnt", and both notyet lines are NY_LINE.
  - Z22 RB-1's rb_pre106 arms: noowner in capitals, a cause never empty, an unread reader with no capitals; COUNTS 2 for the shared capitals line.
  - Z23 RB-2: the RB note's case says the one key written only when AS-RB1 wrote it.
  - Z24 RB-4: the not-written:* note, AS_RB2_EFF set first, exactly two `bad "AS-RB2`.
  - Z25: as_back_on's sequence ending `[ "$t" -lt "$c" ]` on the RB_COPY_AT/COPY_AT values, no REDEPLOY in the body, as_rb_last's printf, and BR1's text.
  - Z26 L8-2: LK_AO_ON assigned twice in code (global and lk_ao); the what-changed / what-did-not marks; STEP2 inside the OWN_IF block.
  - Z27 (added) L8-1 and L8-3.
  - Z28 (added, for RB-3 as partR built it): as_after_verdict's and as_para's headline arms.
- Controls: 22 new weakened copies — Z18 ×5, Z19 ×2, Z20 ×2, Z21 ×1, Z22 ×2, Z23 ×1, Z24 ×1, Z25 ×1, Z26 ×3, Z27 ×2, Z28 ×2. Each is refused by its own rule alone; 47 in all.
- New helpers: _seq (sequential marks, end-to-start), _swap, _in_line.
- Z18 checks the log name followup-enabled-1, not `--key followup_enabled`, so Z2's existing control stays Z2-only.

KNOWN GAPS AND FOLLOW-UPS:
- (1) The driver's K5 `_is_display` exempts any line starting with echo, so `echo … | sh` would pass K5. Z18 covers FE-G1's line; a driver-side tightening (refuse a pipe into sh/bash/eval) is suggested.
- (2) FE-G1 refuses only on followup_enabled off. UJ-ON's other gate conditions (ug, hours) stay notes, and "check it" now says UJ-ON will not judge F3 when they failed at stage A. Whether those should refuse too is the first agent's open owner question.
- (3) docs/75 §L.8 and DL11 should record:
  - LK_AO_ON and step 2's three forms;
  - check-it's uj_judgeable branch;
  - "what it means" by AS_RB2_EFF;
  - FE-G1's place and its interim order;
  - the BR1 wording.
- (4) Summary texts the rehearsal reads may need new anchors: check it (if/else), what changed / what did not in --after-only, step 2's text, what it means, the header paragraphs and the stage-A hdr.
- (5) Cosmetic: partZ's own line 2 is 121 characters. It is a Python comment in the module, not a result line.

Both modules hold no phone number, surname, e-mail, key or token.