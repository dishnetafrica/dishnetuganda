# The 5.18.106 rehearsal — section plan and settled points (18 Nov; refines root docs/75 §L.10)

Inputs:
- map-mechanics.md: 5.18.105's rehearsal, R5.
- map-cases.md: the §L.10 cases against deploy-5.18.106.sh at 745e668, with release bf29cbd.

## Settled points (from the maps; each is a correction or a narrowing of §L.10)

- **S1 (D1).** "200 oldest gone, one present stale" cannot read NOT YET. The main close loop cancels the gone rows,
  and expireStale expires the present row in the same pass. The case is planted with **400** gone rows older than the
  present row, so both 200-place lists are spent on gone rows. The first close pass is NOT YET (cn ≥ 200, ex = 0); the
  next is PASS.
- **S2 (D2).** The weakened copy that removes F3 from the installed close job runs only after the gone rows are
  cleared. While 200 or more remain, the main loop alone gives NOT YET. Expect UJ-ON's own FAIL "F3 did not expire",
  with the stale count, beside R1's FAIL naming cron/followup_close.php.
- **S3 (D3).** Master runs only when the rehearsal invokes it (to be confirmed: no background cron in the sandbox).
  Nothing runs a close pass between planting and the copy.
- **S4 (D4).** An undecodable kyc_config.json alone reads followup_auto_send off. The rollback then gives AS-RB0's
  plain note naming condition 1, writes nothing and prints no command, and leaves the file byte for byte unchanged.
  The capitals text "DOES NOT DECODE … NO COMMAND IS PRINTED" needs followup_auto_send on from a config.json. That is
  a second sub-case, planted by a fixture and said so.
- **S5 (D5).** "A V1 whose put-back failed" is made by failing do_rollback before the copy: a planted
  `$REPO/.git/index.lock` makes the baseline's `git checkout` fail. 5.18.106 then keeps serving, and its later
  --after-only can PASS. It is not 5.18.104's `.rbfail104`, which copies and then misreads the record.
- **S6 (D6) — a known limit, recorded, not built.** A V1 whose put-back succeeded and which found the key off (after a
  typed rollback that wrote it) is the latest record once rotated. A later redeploy's --after-only offers nothing.
  This matches §L.8's base rule; the turn-back-on command stays the owner's set_config.
- **S7 (B5.8).** K5's weakened copies are the derivation's, run by derive106.py on every derivation (K1–K5 with 12
  copies). The rehearsal does not repeat them; it says so.
- **S8 (D8) — fixtures, each said so where used:**
  - a store-only claim key;
  - a claim key in a config.json (LK-G1 refuses first);
  - a number typed differently in file and row ('3' against 3);
  - an undecodable kyc_config.json, and followup_auto_send on from a config.json;
  - ME-CA1's master log lines (appends to <plugin>/data/plugin.log, stamped as master stamps);
  - UJ_A removed from the state file;
  - an unreadable `qf_php uj`: a fake-docker hook keyed on a trigger file, as 5.18.104's `.rbfail104` is.
- **S9.** Every other state goes through the release's real writers: FollowUpService open/draft/approve,
  ConversationService ensureConversation/storeMessage, tools/set_config.php, tools/sales_followup.php (through the
  wrapper), tools/set_evolution.php, the real crons (5.18.105's sender for the refusals; master for close passes), and
  tools/wa_prune_history.php where gone conversations are wanted.
- **S10.** Evolution's stand-in is taught, by one asserted edit with a control, to answer 400 with a JSON message for
  the planted refused numbers only, and a key.id for every other send (5.18.104's send edit is the precedent).
- **S11.** The numbers come from a range the chain leaves free. Their being free is proved by a grep over every
  harness and fixture, never assumed. They are appended to the pattern files for the sweeps.

## Sections

- **106-0 guards.** These follow R5's guards, re-pointed:
  - F106 is pinned: short and full commit, and T1_SIGNATURE.
  - The helpers sit beside the rehearsal and parse.
  - The static verbs guard holds, with its planted controls.
  - deploy-5.18.105.sh is pinned to 106's BASELINE (7835906).
  - Both wrappers are committed and equal to HEAD.
- **106-cut.** R5 is read up to the exact line `echo; echo "== 105-10. what the rehearsal left behind, over 104's
  records and 105's alike =="`, asserted:
  - once, at an index above 900;
  - the section before it begins `echo; echo "== 105-7.`, and its last non-empty line is exactly `rbx105 "105-7e"`;
  - the re-point of `H105_DIR=` happens once, the next line begins `H105_ROOT=`, and the line bound is set to what R5
    has;
  - the needles are each found once.

  After sourcing, 105's functions and variables are asserted present. 105-10's helpers that 106 needs are
  re-defined: uo, tl, sweep.
- **106-pre — production's state, through the real tools.**
  1. 5.18.105 is redeployed (--redeploy, DEPLOY) and PASSED. Its --after-only PASSED. The quarantine's --check is
     run. `$RECS/dnb-5.18.105` links to OUT105.
  2. Step 6 is turned off, leaving steps 1–5 on. LK reads that.
  3. **The jam** (S1, S9, S10):
     - J1: refused drafts, fresh customers, with deliveries on the same channel between 5.18.105 sender passes;
       four or more refused passes.
     - J2: stale follow-ups, and the 400 gone rows of S1 older than one present stale row (kept for 106-5d).
     - Counts are read back.
  4. **The interim of 18 Nov, as the owner ran it:** set_config followup_enabled 0; `on chase` (typed yes);
     `--check chase`.
- **106-1 — the owner's order before the deploy, through the script's own refusals.**
  1. `--check` gives CS-G1 NO-GO (chase on), nothing changed, the off command alone.
  2. Run it as printed. `--check` then gives FE-G1 NO-GO (followup_enabled off), nothing changed, its command alone.
  3. Run that as printed: the log holds `  followup_enabled = 1`. `--check` gives GO.
  4. The variants, each NO-GO with nothing changed: store-only claim key (fixture); a claim key in a config.json
     (LK-G1 first); U4 unreadable with en=off (no FE-G1 refusal: GO, the NOT ESTABLISHED note).
  5. U4's capitals note: followup_max_age_hours 0 gives the note in --check with no DEPLOY clause, and GO. The value
     is then set back.
- **106-4 — the deploy, PASSED.**
  - U1–U5 equal the rehearsal's own reading.
  - The capitals note before `Type DEPLOY` is absent (every gate condition holds).
  - LK-CA1 holds, and R1 / Q-CA1 are re-pinned.
  - J3, the two-draft pair, is planted after the copy while 5.18.106 serves, with master idle.
- **106-5 — --after-only.**
  - a) NOT YET, exit 3, before any close pass; the owner's steps not printed.
  - b) One master close pass, then PASS (F3 works).
  - c) S1: NOT YET after the first pass, PASS after the next.
  - d) ME-CA1, through --after-only runs with planted plugin.log lines: an error inside the straddle (note); after
    it (FAIL); stamped across the boundary (counted); a straddle with no Cycle complete; a copy no cycle straddled;
    no lines (NOT ESTABLISHED, a note).
  - e) UJ_A removed: "judged on this run's values alone".
  - f) This run's reading fails (hook): FAIL and exit 1.
  - g) Limit-only: set 336→504 after the copy. Limit note, UJ_ON_GATE=limit, check it's limit arm, PASSED. Set it
    back, then run before a close pass: a deterministic FAIL (a limit larger than every stale row's age). Then a
    pass, then PASS.
  - h) 'now': followup_enabled 0, giving the 'now' note with the wait clause and check it's 'now' arm. Then 1, a
    pass, and PASS.
  - i) Nothing stale: PASS, "nothing stale for F3 to close".
- **106-6 — the rollback, typed.** Each case is followed by --redeploy back to 5.18.106 (CS-G1 and FE-G1 holding):
  - a) The row holds keys the file lacks. AS-RB1 writes; AS-RB2 reads off; LM-RB1 masked; the RB note "nothing else
    moved but followup_auto_send".
  - b) The file holds a key the row lacks. There is no write. The capitals notice and the command come before
    ROLLBACK. Run as printed: the log holds `  followup_auto_send = 0` and nothing from `  AI SETTINGS` on, and no
    planted phone or e-mail. The rollback run again reads "already off". Control on the control: set_config's uncut
    output on a throwaway copy shows the planted phone. With a wrong container name, docker's error reaches the log.
  - c) '3' against 3 (fixture): condition 3, no write.
  - d) An undecodable kyc_config.json (S4): the plain note, no command, the file unchanged. The sub-case with a
    config.json gives the capitals "DOES NOT DECODE".
  - e) Already off: the plain note.
  - f) Step 6 on at the rollback: the claim-mode notice and the off command alone.
- **106-7 — redeploys and the turn-back-on offer.**
  - a) --redeploy refused by CS-G1 while chase is on, then PASSED. Its --after-only offers the command (after 6a's
    typed rollback that wrote).
  - b) A plain deploy after a rollback: the BR1 note, PASSED, and the --after-only offers.
  - c) V1 with the precondition holding (`.redirect106`): it writes. The put-back succeeds: 5.18.105 serves. Then a
    --redeploy's --after-only offers V1's command (record in .prev).
  - d) V1 with the precondition failing: the stop carries the command.
  - e) S5, V1 written with the put-back failing (index.lock): 5.18.106 serves. The --after-only PASSES and offers
    nothing.
  - f) The same, read on (not-written:differ): nothing offered.
  - g) After a typed rollback that wrote, V1 finds the key off (put-back failing): the --after-only offers the typed
    rollback's command.
  - h) A PASSED deploy's by-hand paragraph prints the full command while the key reads on.
- **106-8 — the weakened copies of deploy-5.18.106.sh** (anc106 / weak106; the anchors read from the script). Each is
  caught by the check it weakens:
  - CS-G1 not stopping;
  - FE-G1 not stopping;
  - U4's capitals note dropped;
  - UJ-ON's gate merged;
  - as_back_on's V1 guard dropped;
  - AS-RB1's condition 3 dropped;
  - the RB note unconditional.
- **106-10 — what is left behind.**
  - Sweeps: no planted number, name or key in any log, in any of its four forms.
  - The judged failure files: anchor-missing, wait.fail and bad-runs.
  - The tally: RESULT, and the failures before 106's own sections.

## The dev runner pair (never committed)

devsnap106 and devres106 are derived from devsnap105 and devres105 (map-mechanics §6). The snapshot is taken once,
after C2, in the trial worktree. It runs for about 10 h.
