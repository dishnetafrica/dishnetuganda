# The 5.18.106 rehearsal — design v2 (18 Nov; refines root docs/75 §L.10)

v1 (`design-skeleton.md`) was reviewed by two critics (`design-review1.md`: RH106-F1…F14, RH106-1…16). Every finding
is taken here; where one is narrowed, it says so. The inputs are `map-mechanics.md` (R5 = 5.18.105's rehearsal and
the chain) and `map-cases.md` (S:n = deploy-5.18.106.sh at 745e668; R: = the release at bf29cbd).

## A. Settled points

**A1 — copy time.** COPY_AT is the clock's, not the kernel's (F1, RH106-1). From the first line of 106's own
sections, `$SB/datebin/touch` turns a `touch -c FILE…` given no `-d`, `-t` or `-r` into the real
`touch -c -d "@$(date +%s)" FILE…`. Under LD_PRELOAD that `date` reads the clock. The script's one `touch` is
stamp_copy, so COPY_AT and RB_COPY_AT share the clock's base. Every other use passes straight through.
- Controls: under the clock the stamped mtime equals fnow104 ±2 s; without LD_PRELOAD it is the wall clock. After
  each 106 copy, COPY_AT is within 60 s of QF_COPY_AT.
- No state line is ever rewritten. 105's copy_back is never used in 106.

**A2 — the close job's schedule.**
- master106 wraps master104 and always lists followup_close as DUE after a copy (F2, F10). Before each pass the clock
  moves +601 s. After the pass, `sched104 followup_close` shows last_run equal to that pass's clock time ±5 s with
  duration_ms ≥ 0, and master-*.out holds `RUN followup_close`.
- Before the first pass after a copy, followup_close's last_run is at or before COPY_AT. This is the rehearsal's own
  positive control for NOT YET.
- set_sched is never called. sl_block_retry goes through rh104-live.php's schedule 'only' mode if needed.
- Nothing runs master in the background (F answer 1). The installed public.php's piggyback is reachable only through
  servers that serve $PD, and none runs during 106. Asserted: `piggyback_last_run.txt` is unchanged across each
  plant-to-copy span.

**A3 — 400 gone rows (S1, F5, RH106-10).** FollowUpService::open is called on conversation ids
9 000 001–9 000 400, asserted absent from wa_conversations before and after. One fictitious number of 106's range is
used for all of them. Their last_customer_at is older than every open enquiry follow-up's in the book, the chain's
9 Nov row and every present stale row included.
- At stage A the rehearsal's own reading asserts: the 400 oldest stale rows are all gone; U3 xs_first_cancel=200,
  xs_first_expire=0, xs_all ≥ 401.
- This is the state a prune leaves; wa_prune_history.php is not run (F14).
- docs/75 §L.10 is amended (200 → 400).

**A4 — the sender's window (F11).** Each sender pass is placed on the next Kampala date after kday104(fnow104) whose
kdow104 is not Sunday, at 09:00. Before every pass the day (not Sunday) and the hour (8–19) are asserted. J1's passes
fit in one window.

**A5 — J1's deliveries (F4).** They are planted between sender passes with ConversationService::storeMessage:
outbound, a wa_message_id, the same channel, sent_at the clock's now. Each round is:
pass k → clock +60 s → delivery → clock +241 s → pass k+1.
One delivery is planted before pass 1. After the jam, the rehearsal's own mirror of refusedWhileDelivering reads ≥ 3
for each refused follow-up, and stage A's U1 classes each "F1 closes".

**A6 — Evolution's 400 (F12).** One asserted edit of `$SB/evo/router.php` inserts the block before the line beginning
`if (is_file(__DIR__ . '/rh104-send.on') && preg_match('#/message/send(Text|Media)/#'`.
- Asserted: 'rh106' absent first; the `[]` line once; by position, the requests.log line < the rh106 block <
  rh104-send.on.
- The block answers HTTP 400 with JSON `{"status":400,"error":"Bad Request","response":{"message":["rejected"]}}`,
  only on /message/sendText/, and only for a number whose digits are listed in `rh106-refuse.list`. It never echoes
  the number.
- Control: the list absent gives key.id. With the list present, a listed number gets 400 and another gets key.id.
  Both requests are logged. One pass's failed-event detail matches F1's LIKE.
- `rh104-send.on` exists and LIVE104=yes at 106's start.

**A7 — .redirect106 and the lock (S5, RH106-11).** One asserted insertion in the stand-in public.php, before
`$page = $_GET['page'] ?? '';\n`.
- Asserted: 'redirect106' absent first; `'.redirect105'` once; 'rh105_probe' once.
- It adds `rh106_probe`, and a 302 on customer_login only while manifest.json holds "5.18.106" and
  `$spend('.redirect106')` succeeds. When `.redirect106lock` is also present, spending also creates
  `$REPO/.git/index.lock`. That is after the deploy's copy and checkout back (V1 probes later), so do_rollback's
  `git checkout 7835906` fails before any copy.
- Control: armed while 7835906 serves, it reads 200:0 and stays unspent.
- After every lock case: the output holds the checkout back and then `git checkout 7835906 failed — nothing was
  deployed`; bf29cbd serves; the lock is removed (also in the EXIT trap) and asserted gone.

**A8 — the uj hook (S8).** One asserted edit of the stand-in docker: while `$SB/.ujfail106` exists, an exec whose
argv carries QF_PHP's `uj` mode exits 1 with no output.
- Control: present, the exec fails; absent, it reads.
- Used for "U4 unreadable" (106-1) and "this run's reading fails" (106-5).

**A9 — every fixture has its undo, asserted (RH106-4).** Each pair below is checked by sha256 or by a reading:
- UJ_A removed / the state file put back byte for byte;
- COPY_AT removed / the same;
- plugin.log lines / plugin.log truncated back to its recorded size and checksum;
- an undecodable kyc_config.json, or a key in a config.json / the file put back by sha256 before any redeploy;
- a store-only claim key / removed, then LK-G1 and CS-G1 read clean;
- the uj hook's trigger and index.lock / removed, asserted gone;
- the F3-removed close job / restored, byte-equal to bf29cbd's, and R1 ok.

**A10 — known limits, recorded in docs/75 and pinned.**
- S6: a V1 that read the key off and whose put-back succeeded, followed by a --redeploy, makes a PASSED --after-only
  offer nothing. This is §L.8's base rule; the owner may decide otherwise. 106-7i asserts it.
- S7: K5's copies are the derivation's.
- Left out, each needing fault injection beyond A8: U4's StaffJobsGate-false branch; AS-RB1's written-check, refused
  and unknown branches.

**A11 — production's state at the deploy (RH106-2).**
- Settings: followup_enabled 1, followup_auto_send 1, followup_daily_cap 3, followup_run_limit 2.
- The ladder: steps 1–5 on, step 6 off.
- The settings are set through set_config as the book's owner, under the clock. Each is asserted by its
  `  <key> = <v>` line and by U4's reading on a copy.

## B. Infrastructure (106-infra), after the cut, before 106-pre

- **Runners**, none of which call copy_back or set_sched (F13):
  - run106, run106c and run_line106c, with DNB_OUT=$OUT106 and the R106 folders;
  - fresh106 and tidy106;
  - aft106: the judged --after-only, compared with expect106;
  - rbx106: ROLLBACK typed, 7835906 serving after it, then `qrun104c --check` PASSED;
  - installed106;
  - master106 (A2) and sender106 (A4, followup_send DUE, the clock +301 s).
- **Numbers.** A range of 106's own, proved free by a grep over every harness and fixture under scripts/harness/ and
  tests/fixtures/, never assumed. It is appended to NAMES104 and FORMS105-style pattern files (four forms plus the
  last nine digits).
- **The helpers beside the rehearsal:**
  - `rh106q.py` — readers only:
    - U1–U3's mirror;
    - refusedWhileDelivering's mirror;
    - the stale and gone order;
    - as_rb_last's mirror;
    - sha256 sets of the four config places;
    - kyc_config.json's keys by place.
  - `rh106-seed.php` — plants, through the pin's own classes and never raw SQL: open, draft and approve; storeMessage
    inbound and outbound; the 400 gone rows; the two-draft pair.
  - Both are parsed at the guards. There must be no `__pycache__`.
- **The edits A1, A6, A7 and A8**, each with its control.

## C. Sections

Every run below is through a 106 runner. Every rollback is rbx106. Every --after-only that is to read PASSED (or a
UJ-ON verdict) is preceded by a master106 pass after its copy, unless the case is "before any pass".

- **106-0 guards.** As R5's, re-pointed:
  - F106 is pinned: short and full commit, and T1_SIGNATURE.
  - The helpers sit beside the rehearsal and parse.
  - The static verbs guard holds, with its planted controls.
  - deploy-5.18.105.sh is pinned to 106's BASELINE.
  - Both wrappers are committed and equal to HEAD.
- **106-cut.** R5 is read up to the exact 105-10 line, with the assertions of map-mechanics §1:
  - the line once, at an index above 900;
  - the 105-7 heading, and the last non-empty line exactly `rbx105 "105-7e"`, once;
  - the last check line, 105-7d's, once;
  - the re-point of `H105_DIR=`, with the next line `H105_ROOT=`. The line bound is measured: at least 30 lines
    come before it;
  - the needles of map-mechanics §1.

  After sourcing, 105's functions and variables are asserted, and FAIL_PRE106 is set. 105-10's one-liners that 106
  needs are re-defined (uo, tl, sweep).
- **106-infra** (B).
- **106-pre — production's state (A11).**
  1. 5.18.105 is redeployed (--redeploy, DEPLOY) and PASSED. Its --after-only PASSED. The quarantine's --check is
     run. `$RECS/dnb-5.18.105` links to OUT105.
  2. `off chase`. LK reads steps 1–5 on.
  3. The settings of A11 are set and asserted.
  4. **J1** (A4, A5, A6). Refused drafts are planted for fresh customers, with their numbers in the refuse list,
     plus a stale refused set for F2. There are five sender passes with planted deliveries between them. The
     chain's 9 Nov draft heads the first pass and is counted.
     - Positive control: each refused draft has ≥ 4 failed 400 events, and the mirror reads ≥ 3.
  5. **J2** (A3). Present stale rows, younger than every gone row and older than the limit, and the 400 gone rows.
     The order is asserted.
  6. **J3.** One pair of approved drafts on one fresh follow-up, planted now with master idle. It is kept fresh, and
     nothing sends until 106-6 (RH106-12).
  7. **The interim of 18 Nov**, as the owner ran it: set_config followup_enabled 0; `on chase` (typed yes);
     `--check chase`.
- **106-1 — the owner's order before the deploy, through the script's refusals.**
  1. `--check` gives CS-G1 NO-GO (`quote_chase_enabled@k,s`), the off command alone, and nothing changed. The book,
     the four config places and the out folder are compared by sha256.
     - Weakened copy CS1 (cs_check returns at once) on this state: caught, because GO or LK-B1 prints instead.
  2. The variants, while followup_enabled is still off (CS-G1 stops before FE-G1):
     - store-only claim keys (fixture): offers_live@s, offers_test@s and chase@s give three commands, highest step
       first, each alone. CS1 is run on this s-only state too. Undo; then clean.
     - LK-G1 refusing first: a claim key in `<plugin>/data/config.json`, then in `<data>/config.json` (fixtures).
       Undo each.
  3. Run CS-G1's off command as printed. `--check` gives FE-G1 NO-GO: its command alone, nothing changed.
     - Weakened copy FE1 (uj_fe_g1 returns at once): caught.
     - With the uj hook (A8), en still off: no FE-G1 refusal, GO, and U1–U4's NOT ESTABLISHED note. Undo.
  4. Run FE-G1's command as printed: its log holds `  followup_enabled = 1`. `--check` gives GO.
  5. **U4's capitals note.** Set followup_max_age_hours 0.
     - `--check`: the note without the DEPLOY clause, then GO.
     - A deploy answered `no`: the note with its DEPLOY clause before `  Type DEPLOY to deploy`, then "not
       confirmed", and nothing changed.
     - Weakened copy U41 (the note's line removed): caught.
     - The limit is then set to 336.
  6. **CS-B1** (RH106-6). A deploy is held at `Type DEPLOY`; `on chase` (typed yes) runs while it waits; then DEPLOY
     is typed. Expected:
     - `  CS-B1 A CLAIM SWITCH IS PRESENT: quote_chase_enabled@k,s`;
     - the off and --check commands, each alone;
     - `NO-GO (CS-B1): a claim switch was turned on during this run, after stage A`;
     - no `NO-GO (LK-B1): a ladder key changed`;
     - nothing copied.

     Weakened copy CSB (the CS-B1 call removed): caught by LK-B1's generic refusal. Then `off chase`.
- **106-4 — the deploy, PASSED (deploy D1, production's).**
  - U1–U5 equal the rehearsal's own reading: U1 has F1 closes for J1 and held for J3; U3 as in A3; U5 reads
    "on … hold".
  - No U4 capitals note. LK-CA1 ok. R1 and Q-CA1 ok. COPY_AT within 60 s of QF_COPY_AT (A1).
- **106-5 — --after-only on D1:**
  - a) Before any pass: NOT YET, exit 3; the owner's steps absent; A2's control.
  - b) ME-CA1, each sub-case with plugin.log restored after it (A9; F9, RH106-14). UJ-ON reads NOT YET here, exit
    3 or 1 as ME-CA1 decides. Stamps are computed from COPY_AT with the installed dn_tz(). The sub-cases:
    - an ERROR inside the straddle: a note;
    - one after it: FAIL;
    - the mirror stamp (local before the copy, after it read as UTC): not counted;
    - a straddle with no Cycle complete;
    - a copy no cycle straddled;
    - no [master] lines: NOT ESTABLISHED, a note;
    - the log absent: COULD NOT BE READ THERE.
  - c) UJ_A removed, then put back (A9): "judged on this run's values alone".
  - d) COPY_AT removed, then put back: UJ-ON's "copy's time is not on record" FAIL and UJ-CA's note.
  - e) The uj hook: "this run's reading could not be made", FAIL, exit 1. Undo.
  - f) Pass 1: NOT YET, with cn ≥ 200 and ex = 0 (A3).
  - g) The limit-only note. Set 504: the limit note, check it's limit arm, PASSED, and the owner's steps present
    (cap, chase and run-limit lines) (RH106-15). Then set 336.
  - h) The 'now' note: followup_enabled 0 gives the note with its wait clause and check it's 'now' arm. Then 1.
  - i) Pass 2: PASS, F3 works. as_back_on offers nothing: no 106 rollback yet.
- **106-5 on copies of their own.** Each is a typed rollback (rbx106), then a --redeploy, then the case. The gates
  must hold at each redeploy: chase off, en on, files decodable.
  - D2 (F7, 5g's FAIL):
    1. Re-plant present stale rows aged 336–504 h by the clock (asserted).
    2. Set 504. --after-only: the limit note.
    3. A pass under 504: nothing expired.
    4. Set 336. --after-only before any pass: FAIL "F3 did not expire", exit 1.
    5. A pass, then PASS.

    The first --after-only after this redeploy is also where the turn-back-on offer is first seen. D2's rollback
    wrote, because the key was on. Run the offered command with run_line106c: its log shows
    `  followup_auto_send = 1`, and the key reads on again.
  - D3 ('now', RH106-8):
    1. Re-plant stale rows.
    2. followup_enabled 0, and a pass: last_run moves, nothing expired.
    3. --after-only: the 'now' note with the wait, and the 'now' arm.
    4. followup_enabled 1. --after-only before any pass: FAIL.
    5. A pass, then PASS.
  - D4 (5i): no present stale row, and fewer than 200 gone rows. A pass, then "PASS — nothing stale for F3 to
    close".
  - D5 (S2, RH106-3):
    1. Re-plant stale rows; assert fewer than 200 gone rows.
    2. Remove F3's block from the installed cron/followup_close.php by an asserted edit, then a pass.
    3. --after-only: `  FAIL  UJ-ON FAIL — F3 did not expire` with stn equal to the rehearsal's count; no UJ-ON
       PASS or NOT YET line; R1 FAIL naming cron/followup_close.php; exit 1.
    4. Restore the file byte-equal, then a pass, then PASS with R1 ok.
  - D6 (the stage-A-only note, RH106-7):
    1. The redeploy is taken with followup_max_age_hours 0. The U4 capitals note with its DEPLOY clause shows,
       then DEPLOY is typed.
    2. --after-only before setting it back: the 'now' note with "Stage A's gate did not hold either".
    3. Set 336, then a pass. --after-only: "UJ-ON not judged for this deploy — its gate did not hold at stage A",
       and check it's "UJ-ON WILL NOT JUDGE F3 FOR THIS DEPLOY".
    4. Weakened copy UJG (the gate's stage-A branch merged into 'now'): caught here.
- **106-6 — the typed rollback's cases.** Each: --redeploy to D (gates holding) → plant → rbx106 → undo. Each
  case's preconditions are in the table below.
  - a) Row keys the file lacks (a production row writer, chosen in drafting: set_evolution --clear-key or the
    screen save). J3 is present, so AS-RB0's F5 capitals line shows with its count. Then: AS-RB1 writes, AS-RB2 off,
    LM-RB1 masked, the RB note "nothing else moved but followup_auto_send".
    - Weakened copy RBN (the RB note unconditional): caught on the next writing case's sibling, 6e "already off",
      where it must not say "wrote one key".
  - b) The file holds a key the row lacks (set_evolution --url). No write; the capitals notice and the command
    before ROLLBACK.
    - ROLLBACK is typed once: as_after_verdict's command after the verdict (RH106-15).
    - Run as printed: the log holds `  followup_auto_send = 0` and nothing from `  AI SETTINGS` on, and no planted
      quote_company_phone or quote_company_email.
    - The rollback, run again from a redeploy, reads "already off".
    - Control on the control: set_config's uncut output on a throwaway copy shows the phone.
    - With a wrong container name, docker's error reaches the log.
    - Weakened copy AC3 (condition 3 removed) with ROLLBACK typed: it writes, which is caught. The write is then
      undone.
  - c) '3' against 3 (fixture): condition 3, no write. Undo.
  - d) An undecodable kyc_config.json (fixture): AS-RB0's plain note naming condition 1, no command, and the file
    unchanged. Sub-case: followup_auto_send on from a config.json (fixture) gives "DOES NOT DECODE … NO COMMAND IS
    PRINTED". Both are undone by sha256 before the next redeploy.
  - e) Already off: the plain note, and AS_RB1=off.
  - f) Step 6 on at the rollback, with the store-only offers keys (fixture): STEP 12, STEP 11 and STEP 6 notices,
    highest first, each command alone. The key is on, so this rollback writes (for 7a). Undo the offers keys.
  - Between cases, a 5.18.106 sender pass in the window (once, at 6a's redeploy). It sends J3's first draft and
    expires the second (F5). F1 closes J1's refused drafts (send_failed); UJ-CA's later readings show both. The next
    rollback's AS-RB0 reads F5 0/0 (RH106-12).
- **106-7 — redeploys and the offer (F8, RH106-5).**
  - a) After 6f, chase is on: --redeploy is refused by CS-G1. Then `off chase`, --redeploy PASSED, a pass, and the
    --after-only offers 6f's command. Run it.
  - b) A typed rollback (it writes) → a plain deploy, no flag: the BR1 note, PASSED, and the --after-only offers.
    Run it.
  - c) V1, precondition holding (.redirect106): it writes and the put-back succeeds, so 5.18.105 serves. Then
    --redeploy, a pass, and the --after-only offers V1's command (record in .prev). Run it.
  - d) V1, precondition failing (a file-only key): the stop carries the command. Then, with 5.18.105 serving and
    the key on, `--rollback`: RB_NOTHING's capitals AS-RB2 and the command (RH106-15).
  - e) A typed rollback that writes → --redeploy → the owner's --value 1 → V1 written with `.redirect106lock`
    (A7). bf29cbd serves.
    - The rehearsal's own reading: as_rb_last is 'written' with t < COPY_AT, and the key reads off.
    - A pass, then --after-only PASSED: offers nothing.
    - Weakened copy BOV (as_back_on's V1 line removed), on this same state: it offers, which is caught.
  - f) The same, read on: a file-only key, so V1 is not-written:differ. Run the printed --value 0, then a pass,
    then --after-only: offers nothing. BOV again: caught.
  - g) A typed rollback that writes → --redeploy (no --value 1) → V1 finds the key off, with the lock → the
    --after-only offers the typed rollback's command.
  - h) A PASSED deploy with the key on: the by-hand paragraph prints the full command.
  - i) A10's known limit: V1 finds the key off and the put-back succeeds, then --redeploy → --after-only offers
    nothing. Pinned and labelled.
- **106-10 — what is left behind.**
  - Sweeps: no planted number, name or key in any log or record, in any of its four forms.
  - anchor-missing, wait.fail and bad-runs are judged against the baselines.
  - The tally: RESULT, with the failures before 106's own sections.

## D. Preconditions per case

Before each case the rehearsal asserts what serves, followup_auto_send's effective value, chase, followup_enabled,
and the latest rollback record as as_rb_last's mirror reads it.

| case | serves before | key | chase | en | latest record before |
|---|---|---|---|---|---|
| 106-4 D1 | 5.18.105 | on | off | on | 105's (none) |
| D2–D6 redeploys | 7835906 after rbx106 | on (offer run after D2) | off | on | the D's own rollback |
| 6a | bf29cbd | on | off | on | any |
| 6b | bf29cbd | on, file key | off | on | — |
| 6e | bf29cbd | off | off | on | — |
| 6f | bf29cbd | on | on (set after the redeploy) | on | — |
| 7e | bf29cbd | on (--value 1) | off | on | typed written, t < the next COPY_AT |
| 7g | bf29cbd | off (left by typed) | off | on | typed written |

Runtime: about 4–4.5 h for 106's own sections (F answer 8 plus the D copies); a record run is about 14 h.

## E. Drafting split (read-only agents return files; extracted from the journal)

- **Part 1:** header, guards, cut, infra (B), runners, the edits A1/A6/A7/A8 with controls.
- **Part 2:** rh106q.py and rh106-seed.php.
- **Part 3:** 106-pre and 106-1.
- **Part 4:** 106-4 and 106-5 (D1–D6).
- **Part 5:** 106-6 and 106-7.
- **Part 6:** 106-10.

Parts 1–2 first, then 3–4 against them, then 5–6. Then a review round.
