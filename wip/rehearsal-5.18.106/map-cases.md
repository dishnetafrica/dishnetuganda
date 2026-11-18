TASK B: the §L.10 rehearsal cases mapped onto deploy-5.18.106.sh (745e668) and the release at bf29cbd

Citation keys:
- S:n is a line of /home/user/dev105/scripts/deploy-5.18.106.sh.
- R:file:n is a line of /home/user/rel106/dishnet-hybrid-sudan/file.
- T:n is a line of R:tests/quote_followup/U_unjam.php.
- "Fixture" means a state that no production writer makes.
- All seeded values must come from the rehearsal's own fictitious number range.

## 0. The writers a rehearsal can use (shared by every case)

**Enquiry drafts**
- The only production writer is cron/followup_run.php: draft() at R:cron/followup_run.php:184, :197 and :205, and auto-approve at :223.
- It calls a model at hard-coded URLs: R:lib/ClaudeWaClient.php:279 and R:lib/GptWaClient.php:150.
- With no key it returns at once (R:cron/followup_run.php:55-58). So a sandbox cannot drive it.
- The usable real writer is FollowUpService itself: open :95, draft :425 (no guard against a second draft), approve :489 (pending only, decided_at = datetime('now')).
- The precedent is 5.18.104's seed helper: dev105/scripts/harness/deploy-5.18.104/rh104-seed.php:217-223 (open → draft → approve, "never raw SQL").
- The staff approve route is R:includes/api/api_followups.php:95.

**Settings**
- tools/set_config.php manages followup_enabled, followup_daily_cap, followup_max_age_hours, followup_run_limit and followup_auto_send (:125-136), and quote_company_phone / quote_company_email (:185, :187).
- 'number' is display-only (:327), so '0' and 'abc' are saved.
- It writes through PluginConfig::saveOverrides (:652), which mirrors the whole file into the store row.
- It prints `  <key> = <v>` (:670), then the listing headed `  AI SETTINGS` (:267, :671).

**The ladder**
- tools/sales_followup.php on/off writes through sf_save → saveOverrides (:146-152). The `on` command is at :195.
- Wrapper: scripts/sales-followup.sh.

**Conversations and messages**
- ConversationService::ensureConversation (R:lib/ConversationService.php:228).
- ConversationService::storeMessage (:740). created_at is always datetime('now') (:784). An inbound message raises last_customer_at to the given sent_at (:828-833).

**Master**
- cron/master.php writes last_run and duration_ms (:387-424). followup_close runs every 600 s and has no flag (:238).
- Its log lines are `RUN` (:370), `ERROR` (:405) and `Cycle complete` (:437), printed by master_log (:440-443).

## 1. The jam §L.10 asks for (planted while 5.18.105 serves, step 6 off)

Step 6 must be off. With it on, 5.18.105's claim mode closes refused drafts after three tries and the jam does not hold.

### J1. Approved drafts Evolution refuses with 400 while the number delivers other messages

**The drafts**
- Plant them with FollowUpService::open, draft and approve (rh104-seed's enquiry op).
- Their customers must be fresh (quiet < followup_max_age_hours). Otherwise F2 closes them first: F2 is at R:cron/followup_send.php:189-195, before F1 at :299-309. U1 would then class them "F2 expires" (S:7070), not "F1 closes" (S:7071).
- Plant a stale refused set as well, for the F2 path.

**The refusals (real writer)**
- 5.18.105's own sender: 7835906's followup_send.php:117 and :336 → EvolutionApiService::sendText (R:lib/EvolutionApiService.php:633) → requestUg's detail `<msg> [HTTP 400 on POST /message/sendText/<instance>]` (:898-904) → FollowUpService::recordFailure (:795).
- This needs the rehearsal's stand-in Evolution, which today answers `[]` to everything (harness/deploy-5.18.90/rehearse.sh:237-241). It must be taught, by an asserted edit, to answer 400 with a JSON message for the planted refused numbers only, and a key.id for everyone else.
- The precedent is 5.18.104's send edit (harness/deploy-5.18.104/rehearse.sh:1515-1527).

**The deliveries (real writer)**
- The same sender delivering other drafts on the same channel: storeMessage with wa_message_id (R:cron/followup_send.php:379-384).
- F1 counts a refusal only if a delivery's created_at falls in (previous refusal, this refusal]. For the first refusal the interval starts at approval (R:lib/FollowUpService.php:256-296).
- So the deliveries must land between sender passes. The first refusal of a pass-ordered run is usually uncounted, so four or more refused passes are needed.

**How section U plants the same thing**
- U04 (T:138-156) and U07b (T:285-297).
- Refusals by qu_refuse (T:26-31): the real recordFailure, then a fixture UPDATE of created_at.
- Deliveries by qu_delivered (T:34-44) → qf_msg, a raw INSERT, i.e. a fixture (R:tests/quote_followup/F_stops.php:28-34).
- U10 (T:321-357) is the closest to real writers: the real sender cron against fake_evo_server's per-instance refusal (R:tests/fixtures/fake_evo_server.php:55-65, :105-108). Its deliveries are still qu_delivered.
- Note that the fake refuses by instance, not by number, so it cannot deliver on the same instance while refusing.

### J2. Stale follow-ups, the oldest of them gone conversations

**Stale (real writer)**
- Store an inbound message with an old sent_at through storeMessage. This sets last_customer_at.
- Then FollowUpService::open, which copies it; due_at = last_customer_at + 24 h (R:lib/FollowUpPolicy.php:231-238).
- Alternatively, the real scan (cron/followup_scan.php:60, :89-105), with followup_max_age_hours raised for a while through set_config and then set back. The scan takes 25 a pass (followup_scan_limit).

**Gone (real writer)**
- tools/wa_prune_history.php --delete-sudan (:84-95) deletes the 249… conversations, their messages and wa_media, and leaves their follow-ups in place.
- It removes every 249… conversation in the sandbox, so the rehearsal's range must be chosen for it.
- Otherwise use FollowUpService::open with an id that no conversation holds, as U14 and U16 do (T:479-489, T:536-547).
- Section U ages rows by a fixture UPDATE: $stale at T:80-83; U15 at T:499-510.

**Caution**
- 5.18.105's close job already cancels gone rows, up to 200 a pass (7835906 followup_close.php:43-46).
- Master must not run a close pass between planting and the copy.

### J3. One follow-up holding two approved drafts

- §B.2's real mechanism, 5.18.105's run re-drafting a follow-up whose draft is waiting, needs the model, so it is unreachable.
- Plant with draft + approve twice on one follow-up (both drafts are the same attempt). Section U does this in U11 (T:359-380) and U12 (T:382-398); there decided_at is moved by a fixture UPDATE.
- Under 5.18.106 the sender holds the second draft (enquiry_second_draft, R:cron/followup_send.php:133-134) and expires it after the send (:389; FollowUpService::expireOtherApproved :330).
- For the rollback's F5 capitals note (S:8892), the pair must still exist at rollback time. Plant it while 5.18.106 serves, outside the sending window or with master idle.

## 2. The §L.10 bullets

### B1. The jam: "UJ-ON must read NOT YET or PASS, never FAIL"

**(a)** uj_on, S:8060-8119.
- PASS lines: "UJ-ON PASS — F3 works: …" (S:8083) and "UJ-ON PASS — nothing stale for F3 to close: …" (S:8090).
- NOT YET line: "UJ-ON NOT YET — …" (S:8094, S:8099).
- FAIL lines: "UJ-ON FAIL — F3 did not expire: …" (S:8095) and the others at S:8062, :8079, :8082, :8089, :8100.

**(b)** J1–J3.

**(c)** UJ-ON reads a copy only (qf_copy). It never runs expireStale() or close() (S:7114-7121).

**(d)** Correct F3 cannot read FAIL here, because FAIL needs fewer than 200 cancels and a stale present row (S:8095). But see D1–D3.

### B2. CS-G1

**(a)** cs_found (S:7390-7397) and cs_check (S:7405-7437), called at S:8227 right after lk_g1 (S:8226).
- Assert the line "  CS-G1 A CLAIM SWITCH IS PRESENT: <key>@<k|s|k,s> …" (S:7414).
- Assert each `off` command alone on its line (S:7419-7432).
- Assert "Then run --check again" with the --check command (S:7434-7435).
- Assert "  STOP: NO-GO (CS-G1): a claim switch is present (…)…" and "  Nothing was changed. Send the log file." (S:7437, S:1688-1693). Exit 1.
- When none is present: ok "CS-G1 none of the three claim switches …" (S:7410).
- In a config.json: LK-G1's held rule stops first with "NO-GO (LK-G1): N of the ladder's switch and record keys (SalesFollowupLadder::allKeys()) are held by … (quote_chase_enabled)" (S:7374). quote_chase_enabled, offers_test_enabled and offers_live_enabled are in allKeys() (R:lib/SalesFollowupLadder.php:78, :101, :106, :195-209).
- Then GO: "  5.18.106 (check): GO — stage A holds." (S:15907).

**(b)**
- File and row: `bash scripts/sales-followup.sh on chase` (sf_on → saveOverrides mirrors the file into the row).
- Store-only: a fixture (SqliteStore save of the row alone), as §L.10 allows.
- A config.json holding the key: also a fixture. No production writer writes either config.json, and LK-G1's own text says so (S:7374). §L.10 does not mark this one as a fixture.

**(c)** The script runs no switch verb: the `off` commands are printed only. It writes no state, backup or key file, and the book, the file and the row stay byte for byte as they were.

**(d)** Reachable. The weakened copies are the rehearsal's own edits of cs_found or cs_check.

### B3. The deploy PASSED, U1–U5, and ME-CA1

**(a) U1–U5**
- uj_stage (S:7988-7992) prints uj_notes (S:7964-7976): U1 ×2, U2 and U3.
- uj_u4 (S:7978-7986) prints U4.
- as_u5 (S:8762-8780) prints "U5 whether a rollback could write followup_auto_send …".
- The fields come from QF_PHP uj (S:6955-7189). The rehearsal's independent reading must mirror U1's classing order (S:7043-7076), including approvedDrafts(10) with the registry's held numbers.

**(a) ME-CA1**
- me_ca1 (S:8186-8210) over ME_PHP (S:8126-8183), called at S:15359.
- An error inside the straddling cycle is a note: "ME-CA1 N ERROR line(s) … inside the cycle it straddled (… up to its own Cycle complete | the next cycle's first RUN of a job it had run | the end of the file)" (S:8205).
- An error after it is a FAIL: "ME-CA1 N ERROR line(s) of the four follow-up jobs … (by job: …) — in the 24 h before the copy, for comparison: N" (S:8207).
- The ok line is at S:8206.
- Without the lines: "ME-CA1 NOT ESTABLISHED — THE POSITIVE CONTROL DID NOT HOLD IN …" (S:8200), or "… COULD NOT BE READ THERE (log=absent …)" (S:8194). Both are notes; PASSED is unaffected.

**(b) ME-CA1 lines**
- Fixture appends to <plugin>/data/plugin.log (R:lib/PluginLog.php:23), stamped in dn_tz() with no config (R:lib/timezone.php:43-60), in master's formats.
- "Both sides of the boundary" means an error whose local stamp is after the copy but whose UTC reading would be before it. It must count; its mirror image must not.
- A straddle with no "Cycle complete" ends at a repeated RUN or at EOF (S:8148-8157).
- "A copy no cycle straddled": a "Cycle complete" stamped just before the copy, so the straddle is 0 and any later follow-up ERROR is a FAIL.

**(c)** It prints counts only, never a line. It never stops the run.

### B4. `--after-only`

1. **NOT YET with exit 3, then PASS.**
   - NOT YET comes from control=not (S:8097-8099; the control is computed at S:7147).
   - Summary: "  5.18.106 (after-only): NOT YET — <say>; run it again after <time>", then the AFTER_CMD alone (S:16086-16088).
   - The count line ends ", 1 not yet" (S:16081). The script returns 3 (S:16194).
   - It must NOT print "PASSED", the owner's steps (S:16101) or ECO Bank's check (S:16160).
   - PASS comes after a real master close pass. The control is last_run > COPY_AT with duration_ms ≥ 0, written by master.php (:390, :417).
2. **The weakened copy (F3 removed from the installed close job).**
   - Expect the FAIL at S:8095 with `$stn` stale, and no PASS or "wait" line.
   - Expect R1's FAIL "R1 installed files that differ: cron/followup_close.php" (S:14400). See D2.
3. **Nothing stale.** "UJ-ON PASS — nothing stale for F3 to close" (S:8090). The book needs no stale open row at the copy and no expired close since it.
4. **200 gone and one present stale row.** See D1.
5. **UJ_A absent.** Fixture: delete the `UJ_A=` line from the state file.
   - Every UJ-ON line ends "; judged on this run's values alone — stage A's reading is not on record (UJ_A in <state>)" (S:8063).
   - UJ-CA says "UJ-CA stage A's reading is not on record …" (S:8033).
6. **This run's reading fails.** "  FAIL  UJ-ON FAIL — this run's reading could not be made (…)" (S:8062), and UJ-CA's NOT ESTABLISHED (S:8038). Exit 1.
   - Planting it needs fault injection on `qf_php uj` or on its copy (S:8029-8031), e.g. a fake-docker hook in the style of 5.18.104's `.rbfail104`. This is not a writer state.

**(c)** The run is read-only; nothing is closed by the script.

### B5. The rollback

The typed rollback runs rb_pre106 (S:8858-8960) before the prompt (S:13880-13882), then as_rb1 typed (S:13889 → S:8962-9065), then do_rollback, then rb106 (S:9084-9124) and as_rb2 (S:9133-9158).

**1. Row holding keys the file lacks (AS-RB1 must write)**
- ok "AS-RB1 followup_auto_send set off before the put-back (typed) — …" (S:9041).
- note "the settings row reads '0' too" (S:9058).
- ok on the LK map and on V3g across the write (S:9064ff).
- ok "AS-RB2 followup_auto_send reads off … ; AS-RB1 set it before the put-back" (S:9140).
- LM-RB1 by lm_rb1 (S:7889).
- Planting:
  - followup_auto_send '1' through set_config, which keeps file and row equal.
  - A row-only key from a production row writer, e.g. tools/set_evolution.php --clear-key → PluginConfig::clearEvolutionKey (R:lib/PluginConfig.php:257; it removes evo_api_key from the file only; side effect: the sender loses its key), or a screen save such as R:includes/post/post_admin.php:749.
  - Whether the chained sandbox already holds row-only keys is NOT ESTABLISHED.

**2. File holds a key the row lacks (no write)**
- Before the prompt: "THE ROLLBACK CANNOT SET followup_auto_send OFF ITSELF (condition 3: the file and the store row disagree on <key>): … RUN YOUR OWN COMMAND BELOW, ALONE, THEN RUN THE ROLLBACK AGAIN:" (S:8944).
- Then as_owner_text with the differ paragraph and the command (S:8705-8744, built by as_owner_cmd S:12333-12337), and "It prints the line "  followup_auto_send = 0"…".
- The prompt carries "followup_auto_send NOT set off — the rollback cannot write it (above)" (S:8939).
- Planting: tools/set_evolution.php --url … → saveEvolutionCredentials writes the file only (R:lib/PluginConfig.php:215-237; R:tools/set_evolution.php:96, :137); or the screen's saveAiSettings (R:tabs/engage/wa_ai_setup.php:127).
- After "run as printed":
  - The command's log holds `  followup_auto_send = 0` and nothing at or after `  AI SETTINGS`.
  - It holds no planted quote_company_phone or quote_company_email (both planted through set_config :185 and :187).
  - The rollback run again prints "AS-RB0 followup_auto_send already reads off …" (S:8932), and AS-RB1 notes "already reads off — nothing was written" (S:9045).
- Control on the control: set_config's uncut output on a throwaway copy shows the phone.
- With a wrong container name, docker's error reaches the log through the `2>&1 | sed` pipe.

**3. The same key typed differently ('3' against 3)**
- Expect "condition 3 … disagree on <key>" (the reader uses ===, S:8605-8609).
- No production writer known here leaves a number typed differently in file and row (set_config writes strings; the row writers' types were not established). Treat it as a fixture unless one is found.

**4. kyc_config.json that does not decode.** Fixture (writeKycFile always writes JSON). See D4.

**5. Already off.** Plain note at S:8932, then S:9045; AS_RB1=off.

**6. Step 6 on.** `on chase` while 5.18.106 serves gives "STEP 6 (CHASE) IS ON: … CLAIM MODE …" (S:8915), then `      cd <repo> && bash scripts/sales-followup.sh off chase` alone (S:8917).

**7. V1's automatic rollback**
- It fires only in deploy mode on a redirect (S:13923). Reuse 5.18.105's `.redirect105` stand-in (harness/deploy-5.18.105/rehearse.sh:420-438).
- When the precondition holds: AS-RB1 writes, and the stop message ends with no command, because as_para v1:written returns nothing (S:8752).
- When it fails: "AS-RB1 FOLLOWUP_AUTO_SEND READS ON AND WAS NOT SET OFF: …" (S:9047), and the stop "rolled back (serves …) … send the log file", followed by "AS-RB1 DID NOT SET followup_auto_send OFF (…)" and the command (S:13928, S:8757-8758).
- AS-RB2 notes "… printed in the stop message below" (S:9147).

**8. K5's eight weakened copies.** K5 is not in the script (one comment mention, S:8001). It lives in the derivation: wip/derive-5.18.106/derive106.py:145-191. A rehearsal reaches it only by running the derivation's check over weakened copies.

**(c)** Exactly one write in the whole script: saveOverrides(['followup_auto_send' => '0']), and only when the key reads on and all five conditions hold (S:8989-8998). The script never runs set_config, never runs a switch verb, never clears a key, and changes neither config.json (checked at S:9026).

### B6. `--redeploy`

1. **Refused while a claim switch is on.** The same CS-G1 stop as B2 (stage A runs for --redeploy too).
2. **PASSED, then its `--after-only` offers the turn-back-on.**
   - as_back_on (S:8826-8856), reached only on a PASSED --after-only (S:16101, S:16147).
   - Lines: "followup_auto_send reads off: the latest rollback set it off (AS_RB1=written in its record; its copy at …, before this deploy's at …) …" (S:8853), then as_owner_text 1 with `--value 1 … autosend-1-<ts>.log` and "It prints the line "  followup_auto_send = 1"…".
   - After a typed rollback the record is rollback-state-*.env (STATE_W, S:13732; AS_RB1 at S:9063; RB_COPY_AT at S:13827).
   - After V1's rollback the record is the rotated state.prev-* (S:13736-13737). This needs V1's put-back to have succeeded.
3. **The by-hand paragraph of a PASSED deploy.** as_para sum (S:16191, S:8753). The command prints only if followup_auto_send reads on at that moment; reading off, it prints "It already reads off …: nothing to run." (S:8717).
4. **Limit-only.** See B12.

## 3. The 18 Nov cases

### B7. FE-G1

**(a)**
- uj_fe_g1 (S:8003-8016), after uj_stage (S:8249-8250). CS-G1 stops first (S:8227).
- Lines:
  - "  FE-G1 FOLLOWUP_ENABLED READS OFF AS THE JOBS READ IT …" (S:8006);
  - the command alone (S:8008), of the form `docker exec -u <owner> -e DN_DATA_DIR=<pdd> <ctr> php <in>/tools/set_config.php --key followup_enabled --value 1 2>&1 | sed '/^  AI SETTINGS$/,$d' 2>&1 | ( umask 077; exec tee <OUT>/followup-enabled-1-$(date …).log )`;
  - the success-line sentence (S:8009);
  - "  STOP: NO-GO (FE-G1): followup_enabled reads off — …"
- Exit 1.
- U4's own note is at S:7986.
- Run as printed: the log holds `  followup_enabled = 1`, then "(check): GO" (S:15907).
- U4 unreadable: "U1–U4 could not be read on the copy (…) — NOT ESTABLISHED; …" (S:7991), no FE-G1 line, and GO.

**(b)**
- `set_config --key followup_enabled --value 0`. U4 reads it through `!empty(...)` (S:6994).
- Claim switches must be absent first (`off chase`).
- The unreadable U4 needs fault injection on `qf_php uj`. Keep en=off there so that "no refusal" is the discriminating result.

**(c)** The script does not run the command, and writes no state, backup or LK/QL key file.

### B8. A plain deploy after a rollback (no `--redeploy`)

**(a)**
- The BR1 note "BR1 N of 5.18.106's own files are on disk although --redeploy was not asked for: … offering your own command to turn followup_auto_send back on …" (S:12451).
- The state file is rotated (S:13736), the same as with --redeploy.
- Its PASSED --after-only prints as_back_on as in B6.2.

**(b)** A typed rollback with AS_RB1=written, then `bash scripts/deploy-5.18.106.sh` with no flag.

### B9. A V1 that wrote, or read the key on, and whose put-back failed: its `--after-only` offers nothing

**(a)** as_back_on's first test (S:8829): any AS_RB1 in this deploy's state file other than '', off, not-written:owner-unknown or not-written:unread returns at once. Assert that neither the S:8853 line nor any `autosend-1-` command appears.

**(b), discriminating version**
1. A typed rollback that wrote.
2. The owner's `--value 1`.
3. A deploy with V1 armed and the put-back made to fail.
4. V1 writes (AS_RB1=written).
5. The key reads off, an older "written" record exists, and the deploy's COPY_AT is later than it.
6. Without the rule, the --after-only would offer.

For the "read it on" variant:
- Make the precondition fail with a file-only key: AS_RB1=not-written:differ (S:9046).
- The owner runs the printed `--value 0`.
- Then the PASSED --after-only offers nothing.

**(d)** See D5.

### B10. A V1 that found the key already off after a typed rollback

**(a)**
- V1 records AS_RB1=off (S:9045). The stop carries as_para rb:off: "followup_auto_send read off before the put-back was tried (AS-RB1 wrote nothing)…" (S:8750).
- as_rb_last (S:8805-8818) returns the typed rollback's "written <t>" (rollback-state-*.env), so as_back_on offers its command.

**(d)** See D5 and D6.

### B11. U4's capitals note on a non-positive age limit

**(a)**
- U4 says "followup_max_age_hours not a positive number — F2 and F3 inactive …" (S:7982).
- Then "U4 UJ-ON WILL NOT JUDGE F3 FOR THIS DEPLOY: followup_max_age_hours was not a positive number at stage A. STAGE A'S READING IS KEPT … PUT IT RIGHT, ANSWER ANYTHING BUT DEPLOY BELOW, THEN RUN THIS AGAIN" (S:7985). In --check the clause ", ANSWER ANYTHING BUT DEPLOY BELOW" is absent.
- It is a note: --check still reads GO.
- In a deploy it must come before "  Type DEPLOY to deploy …" (S:13838).

**(b)** `set_config --key followup_max_age_hours --value 0` (or `abc`). staleBefore then returns null (R:lib/FollowUpService.php:198-203).

### B12. UJ-ON's limit-only and 'now' notes, with the summary's "check it"

**(a)**
- Limit-only: UJ_ON_GATE=limit, note "UJ-ON not judged on this run — stage A's gate held, but followup_max_age_hours is X h now and was Y h at stage A …" (S:8075).
- 'now': UJ_ON_GATE=now, note "UJ-ON not judged — its gate did not hold: followup_enabled is off now; …" (S:8071).
- Both are notes, so the run reads PASSED (S:16084) and prints the owner's §F steps (S:16101-16146). The design leaves this open (§L.9).
- "check it" (S:16017): for limit, "UJ-ON judges F3 on it only once followup_max_age_hours is back at stage A's Y h and a close pass has run after that …"; for now, "… only once its gate holds now … and a close pass has run after that …"; otherwise, "… until one has it reads NOT YET (exit status 3) …".

**(b)**
- Use set_config after the copy: followup_max_age_hours other than stage A's, or followup_enabled '0'.
- Then set it back. Run --after-only once before a master close pass and once after it.
- Master runs followup_close even while it is disabled (no flag; the job returns at R:cron/followup_close.php:33), so last_run moves and the control holds.
- To make "a run before that pass" read FAIL deterministically, use a limit larger than every stale row's age. Assert the FAIL at S:8095 and exit 1; after a pass, PASS.

## 4. (d) Cases the script cannot reach as written, or that contradict §L.10

**D1. "200 oldest stale rows gone, one present stale: NOT YET, then PASS" is not reached with 200.**
- followup_close's main loop runs first. It takes allOpen(200,'enquiry') ORDER BY due_at (R:lib/FollowUpService.php:347-353) and closes gone rows 'cancelled' by 'closer' (R:cron/followup_close.php:43-48).
- That happens before expireStale (:87-91), and due_at follows last_customer_at, so the oldest rows come first.
- With exactly 200 gone rows the main loop cancels them all and expireStale expires the present one on the same pass. That reads PASS at once.
- NOT YET (cn ≥ 200 and ex = 0, S:8091-8094) needs at least 400 gone rows older than the present row (both 200-place lists spent on gone rows), or the main loop's places filled by rows that are due earlier.
- U3's first-pass forecast (S:7124-7128, printed S:7972) mirrors expireStale only. It will not equal the first pass's actual closes when gone rows are among the 200 earliest due.

**D2. The weakened copy (F3 removed) reads NOT YET, not FAIL, while 200 or more gone rows remain.** The main loop alone gives cn ≥ 200 (S:8091). Run that case only after the gone rows are cleared.

**D3. Pre-deploy erosion.** 5.18.105's close job cancels up to 200 gone rows a pass (7835906 followup_close.php:43-46) before the copy. Master's cycles must be held between planting and the copy.

**D4. An undecodable kyc_config.json does not produce cause 1's notice by itself.**
- PluginConfig::read ignores the file (R:lib/PluginConfig.php:81-88), and followup_auto_send is not a vault key (R:lib/ConfigVault.php:36ff). So the key reads off.
- rb_pre106 then takes the "already reads off" branch (S:8931-8932), which never calls as_owner_text. The capitals text "DOES NOT DECODE … NO COMMAND IS PRINTED" (S:8725) does not appear.
- Condition 1 still shows in AS-RB0's plain note (S:8889) and in U5 (S:8766).
- To get the notice, followup_auto_send must also read on from a config.json, which is a fixture.

**D5. A V1 "whose put-back failed" reaches `--after-only` only if do_rollback fails before copying.**
- One way is `git checkout` of the baseline failing (S:13818): an index.lock or a fake git.
- 5.18.104's `.rbfail104` pattern copies 7835906 and then misreads the record (harness/deploy-5.18.104/rehearse.sh:2505-2516). That leaves 7835906 serving, and --after-only stops with "the container serves …, not bf29cbd" (S:12387).
- as_back_on is reached only on a PASSED --after-only (S:16101).

**D6. A V1 that found the key off and whose put-back succeeded, followed by `--redeploy`: nothing is offered.**
- V1's RB_COPY_AT and AS_RB1=off (S:13827, S:9063) rotate into state.prev-* (S:13737).
- as_rb_last reads that file (S:8808) and picks the newest RB_COPY_AT, so it returns "off". as_back_on then offers nothing (S:8835), although the typed rollback set the key off.
- This matches §L.8's literal base rule. It contradicts the 18 Nov rationale ("set nothing off, so the latest earlier rollback's record decides"), which as_rb_last applies only to this deploy's own state file. This is the owner's decision; flag it.

**D7.** K5 (B5.8) is the derivation's check, not the script's.

**D8.** "Store-only" claim key, a claim key in a config.json, a number typed differently in file and row, an undecodable kyc_config.json, ME-CA1's log lines, UJ_A removed, and an unreadable uj reading all need fixtures or fault injection. Only the first is marked so in §L.10.

**D9. NOT ESTABLISHED.**
- Whether the chained sandbox already has followup_enabled on, row-only keys, or open enquiry rows that would take the close job's places.
- How the 5.18.105 harness drives master's clock.
