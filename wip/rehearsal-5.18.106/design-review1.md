##### feasibility
SUMMARY: Feasibility review of the 5.18.106 rehearsal plan (scratchpad/rh106/design-skeleton.md). Most mechanics are available:
- master104 works while bf29cbd serves, and nothing runs master in the background.
- The index.lock approach for S5 fails `git checkout` before any copy and leaves a state from which --after-only can pass.
- .redirect106 needs its own insertion, and is safe.
- The 400 refusal can be added with one asserted edit to the router.

Two blocking problems would make 106-5 prove the wrong thing:
- **COPY_AT is wall-clock time.** stamp_copy's `touch -c` is not covered by the clock shim. Every row and schedule entry that UJ-ON compares against COPY_AT is stamped by the shimmed clock, about 40 days ahead (shown in 105's kept state files).
- **master104 and after_back105 write UJ-ON's positive-control field themselves.** master104 plants followup_close "run now, duration 0", and set_sched wipes the whole schedule.

Together these make 5a read FAIL instead of NOT YET. After the first F3 expiry they also make every later UJ-ON read "F3 works", so 5g's FAIL, 5i's "nothing stale" and the F3-removed copy's FAIL cannot be reached, and later PASSED runs prove nothing.

Major problems:
- followup_enabled is unset in the chain, so the 5.18.105 sender does nothing in J1.
- Deliveries made by the sender in the same pass count only by chance of second boundaries.
- The chain holds an open enquiry row from 9 Nov (present conversation, approved draft), which breaks S1 unless the 400 gone rows are planted older than it.
- 5b contradicts 5c, and the F3-removed copy is not placed anywhere.
- 7e and 7f, as sequenced, cannot tell the guard from its absence.

Minor problems: the ME-CA1 boundary direction and plugin.log leftovers; the 600 s and 300 s intervals master104 does not enforce; Sunday is outside the sender's window; runners bound to 105; and restoring files between cases.

Estimated runtime for 106's own sections is about 2.5–3 h, against 105's 1h25m.

Q: 1. Does anything run master or a cron in the background? How does the chain invoke master and the close job, and does that work while bf29cbd serves?
A: Nothing runs master in the background. S3 holds.

What stays running:
- Only servers survive the chain: the stand-in public.php, the website, ucrm99 and evo (devsnap105 records four).
- The stand-in public.php boots only the store and never includes master (RQ:215-260).
- The installed public.php's piggyback runs master in-process (public.php:2214-2240), but it is reachable only through servers that serve $PD. Every one of those is killed: R4:1765, 1990, 2299, 2835, 4367 and R5:628, 900, 951, 987.

How master runs: only through master104 LABEL [--set JSON] DUE… (R4:1265-1280).
1. `live104 schedule` plants every job except the DUE ones as run now, with duration_ms 0 (rh104-live.php:358-387, :382).
2. It then runs the INSTALLED cron/master.php with `docker exec -u owner`, under the clock.

The cron stand-in is not master. cron104_up (R4:2672-2691) loops run_worker.php, the reply worker, during rbx105 rollbacks, and only while live=$P104. Under 106, whose rollbacks go to 7835906, it breaks at its first iteration. That is harmless, because 106's rollback has no drain.

The close job was never DUE in 104 or 105. Every master104 call lists quote_chase, followup_send, quote_daily_list or customer_reminders (R4:1563-1623, R5:610-617), so followup_close was only ever planted quiet.

Under bf29cbd master104 works:
- the job regex (rh104-live.php:369) finds 54 jobs, followup_close among them (cron/master.php:238, interval 600, no flag and no uganda gate);
- the helper's common libs are all present, and only FollowUpService changed between 7835906 and bf29cbd.

Two conditions apply:
- the clock must be at least 600 s past followup_close's last_run (master.php:367);
- followup_enabled must be on, or followup_close.php:33 returns (last_run still moves).

Caveats are in F2 (quiet plants fake UJ-ON's control; set_sched erases it) and F10 (intervals).

Q: 2. The clock: how a run is placed inside the sender's window, and how stale ages are reached.
A: The clock: an LD_PRELOAD shim over clock_gettime, gettimeofday and time (rh104-clock.c:53-67) reads an offset from CLKF104. clk_to104 moves it forward only, and refuses to move back by writing `clock:back` to anchor-missing (R4:1229-1236). kat104, kday104 and kdow104 give Kampala times.

The sender's window: FollowUpPolicy::withinSendingWindow allows 08:00–20:00 local, and also refuses Sunday (FollowUpPolicy.php:182-205). The plan omits Sunday (F11). Where 105 leaves the clock is not established; the record reached Kampala 2026-12-25. So compute the next Mon–Sat date after kday104(fnow104), run clk_to104 "$(kat104 "$D 09:00")", and assert the day and hour before each pass.

Stale ages: staleBefore(config, time()) (FollowUpService.php:197-202) cuts on last_customer_at, never created_at. Under the shim, time() is the clock.

So stale ages need no clock moves:
- storeMessage with an inbound row and an old sent_at sets the conversation's last_customer_at to that sent_at; created_at = datetime('now') does not matter here;
- FollowUpService::open copies last_customer_at from the array it is given and never reads the database (7835906 FollowUpService.php:95-133).

The 336 h default applies while followup_max_age_hours is unset.

The real problem is COPY_AT: it is wall-clock time while everything else is clock time, about +40 days (F1). There is also a pre-existing stale enquiry row (F5).

Q: 3. What the 5.18.105 sender needs under the sandbox, and whether J1's four or more refused passes with deliveries between can be driven in reasonable time.
A: What it needs:
- **followup_enabled on.** The chain leaves it unset, and with step 6 off the sender returns at once (cron/followup_send.php:48-50). See F3.
- **The ledger.** With steps 1–5 on the ledger is on, and with chase off it is in 'record' mode: no claim and no limit on tries. JobBudget allows 45 s.
- **The window**, as in answer 2.
- **Evolution.** The chain's stand-in EVO_URL, with LIVE104=yes and rh104-send.on present (R4:1487, :1532), so deliveries carry a key.id. With the registry on, sends go through requestUg.
- **A channel the automation policy allows**: the one 104-5k and 105-pre's chase sent on. Assert it with one control delivery.
- **Numbers** that are neither colleagues nor opted out.

The passes themselves: recordFailure leaves the draft approved (7835906 FollowUpService.php:628-633), so the same draft refuses on every pass. Each pass is either master104 with followup_send DUE (move the clock +301 s first) or a direct `docker exec` of cron/followup_send.php under the clock. Either way it takes seconds, so 4–5 passes plus plants take about 1–2 min of wall time.

Deliveries made by the sender in the same pass count only by chance of second boundaries (F4). Plant them between passes with storeMessage instead. Note too that the chain's own approved enquiry draft from 9 Nov heads the first pass (F5).

Q: 4. The Evolution stand-in edit for per-number 400s: the router file, its current asserted edits, and a safe anchor.
A: The router is $SB/evo/router.php. Its base (RQ:289-294) is the requests.log line, then `header(...); echo '[]';`.

It carries three asserted edits; the map lists two:
- media (rh104-media.b64): R4:1490-1502;
- send (rh104-send.on, which answers every /message/send(Text|Media)/ with a key.id and returns): R4:1515-1528;
- slow (rh104-slow, inserted before the requests.log line): R4:4011-4022.

The safe anchor is the line beginning `if (is_file(__DIR__ . '/rh104-send.on') && preg_match('#/message/send(Text|Media)/#'`, which occurs once. Insert the 400 block before it, which also puts it after the requests.log line, so refusals are still recorded.

Assert:
- before: 'rh106' absent, the `[]` line once;
- after: one block, positioned requests.log line < rh106 < rh104-send.on.

The block answers only /message/sendText/, and only for numbers in a list file. Its body is JSON `{"status":400,"error":"Bad Request",…}`, with no number echoed. 7835906's requestUg then yields 'Bad Request [HTTP 400 on POST /message/sendText/<instance>]', which matches bf29cbd's F1 LIKE (FollowUpService.php:262-263).

Control: with the list absent, key.id; with it present, 400 for a listed number and key.id for another; both requests logged.

Q: 5. The index.lock approach for S5.
A: Where git runs: do_rollback (deploy-5.18.106.sh:13815-13822) runs `git -C "$REPO" checkout -q 7835906` before anything else. It runs as the script's user, root in the sandbox. $REPO is the sandbox clone ($SB/repo) that the script runs from.

What the lock does: a planted $REPO/.git/index.lock makes the checkout fail (exit 128, "Unable to create …/index.lock: File exists"). do_rollback then prints `bad "git checkout … failed — nothing was deployed"` and returns 1. deploy-hybrid.sh never runs and nothing is copied, and the `git checkout -q "$ref"` that would return the checkout is never reached.

Planting it before the run is safe:
- every other git call in the script only reads (show, diff, archive, log, cat-file, rev-parse, ls-files);
- `git status --porcelain` (:12094) refreshes the index only when it can take the lock, and goes on silently when it cannot;
- deploy-hybrid.sh runs only `git log` (:94).

Add a control: a --check with the lock planted reads GO.

What V1 leaves: it stops with 'the automatic rollback did not complete … live=bf29cbd' (:13931). The state allows a PASS later:
- every STATE line --after-only reads is written before the copy (:13750-13869);
- AS_RB1 is appended (:9063);
- R1 is clean, because nothing was copied back;
- V1 passes once .redirect106 is spent.

UJ-ON still needs a real close pass after the copy (F1, F2).

Remove the lock right after the run, and in the EXIT trap as well.

Q: 6. .redirect106: can 105's .redirect105 branch be reused?
A: No; a new insertion is needed. R5's branch (R5:422-433) fires only while manifest.json holds '"5.18.105"' (V105 is passed in as argv), and it spends '.redirect105'. bf29cbd's manifest says 5.18.106, so that branch never fires under 106.

Insert a second branch before the same anchor, `$page = $_GET['page'] ?? '';\n`, which still occurs once.

Assert:
- 'redirect106' absent;
- "'.redirect105'" once;
- 'rh105_probe' once.

The branch adds:
- an rh106_probe page;
- a 302 to ?page=customer_login&rh106v1=1, only while the manifest holds "5.18.106" and $spend('.redirect106') succeeds.

Its control, as in R5:434-438: armed while 7835906 serves, it reads 200:0 and stays unspent (v1read102, spent99).

No customer_login probe runs before the copy in deploy-5.18.106.sh (grep), so the branch is safe even for a --redeploy with 5.18.106 already serving.

Q: 7. wa_prune_history.php --delete-sudan versus FollowUpService::open on missing conversation ids, for 400 gone rows.
A: FollowUpService::open with an absent id touches only followups (one row) and followup_events (one 'created' row). It reads no conversation, and it takes any id and any last_customer_at. The unique index allows one open row per conversation_id, so the 400 rows need 400 distinct ids.

--delete-sudan (tools/wa_prune_history.php:76-98) does much more:
- VACUUMs a full backup into the data folder on every run;
- deletes every 249… conversation, with its messages and wa_media;
- VACUUMs the live book.

It also needs 400 conversations on 249… numbers. Those lie outside the range rh104-live.php accepts (:109-112) and outside the pattern files. snap1's book holds 46 conversations, all 256…, max id 46; whether 105 adds any is not established.

open() is safer in the chained sandbox. Use ids above anything reachable (e.g. 9,000,001–9,000,400), asserted absent before and after, and say it is the state a prune leaves (F14).

Q: 8. Runtime: 106's own sections against 105's 1h25m.
A: Per-run costs, measured from the reckeep4 file times of 105's record:

| Run | Time |
|---|---|
| --check | 1.0–1.4 min |
| deploy or redeploy | 1.4–3.1 min |
| typed rollback | about 0.9 min |
| --after-only | about 1.7 min |

105's own sections made about 51 runs (tags 699–749) in 1h25m.

The plan implies about 85–95 runs, plus seconds-long work:
- about 21 deploys and redeploys;
- about 26 --after-only runs, the six ME-CA1 runs alone taking about 10 min;
- about 15 --check runs;
- about 10 typed rollbacks;
- about 15 quarantine checks;
- 7 weakened copies;
- J1–J3 and about 10 master passes, each a few seconds.

Estimate: about 2h30m–3h for 106's own sections, roughly twice 105's. Fixes F6–F8 add about 4 redeploys, about 15 min more.

That makes a dev trial from devsnap106 about 3 h. A record run is about 9h50m of chain plus about 3 h, so about 12h40m–13h.

=== RH106-F1 [blocking] 106-4, 106-5 (all), 106-6/7 PASSED --after-only runs, ME-CA1
PROBLEM: COPY_AT is wall-clock time, but every row and entry UJ-ON and ME-CA1 compare with it is stamped by the shimmed clock, about 40 days ahead.

Consequences:
(a) 5a reads FAIL, not NOT YET. Any followup_close entry written under the clock, even one written before the deploy, has last_run > COPY_AT, so the control reads held. With J2's stale rows present and no cancels, that branch ends in FAIL.
(b) on_expired counts every F3 expiry ever stamped as 'since the copy', for this deploy and every later one. So once 5c has expired a row, these cannot be reached:
  - 5g's FAIL;
  - 5i's 'nothing stale';
  - the F3-removed copy's FAIL.
  Every --after-only in 106-6/7 also reads 'PASS — F3 works' with no close pass behind it.
(c) UJ-CA's sends, f1 and f2 counts include events from before the deploy.
(d) ME-CA1 stamps must be computed against the wall-clock COPY_AT, not the clock.
EVIDENCE: Where COPY_AT comes from: stamp_copy runs `touch -c` with no time (deploy-5.18.106.sh:13813). COPY_AT is then read with `stat -c %Y` (:13868-13869), and so is RB_COPY_AT (:13827).

The shim intercepts only clock_gettime, gettimeofday and time (rh104-clock.c:53,60,67), so the kernel stamps the mtime with real time.

Measured in a kept 105 trial (scratchpad/b105/rh/keep-20261113T234850Z/out105/state-5.18.105.env.prev-20261224T081411Z):
- DEPLOYED_AT=2026-12-24T08:06:25Z
- QF_COPY_AT=2026-12-24 08:06:31
- COPY_AT=1794615598, which is 2026-11-14T00:19:58Z

Every other state file in that folder shows the same split.

Where the comparisons happen:
- the UJ control: `$lr > $cu` (:7146);
- on_expired: `e.created_at >= ci` (:7156);
- uj_on checks `ex > 0 → PASS` before the control (:8083);
- ME-CA1 reads cu from STATE (:8189).
PROPOSAL: Make both copy stamps clock time, and do it at the source rather than by rewriting state.

The shim: from the start of 106's own sections (after 106-pre's 5.18.105 runs), put a `touch` shim in `$SB/datebin`, which is first on every run's PATH. For `touch -c FILE…` with no -d, -t or -r, it runs the real `touch -c -d "@$(date +%s)"`. Under LD_PRELOAD, `date` reads the clock. The script's only touch is stamp_copy, so this changes COPY_AT and RB_COPY_AT together.

Its control:
- under the clock, the stamped mtime equals fnow104 ±2 s;
- without LD_PRELOAD, it is the wall clock;
- after 106-4, COPY_AT is within 60 s of QF_COPY_AT.

Never rewrite COPY_AT alone, the way copy_back105 does. as_back_on requires RB_COPY_AT < COPY_AT (:8835), so a one-sided rewrite would offer the command after a rollback that came later.

=== RH106-F2 [blocking] 106-pre J1, 106-5a–c, any --after-only helper
PROBLEM: master104 writes UJ-ON's positive control itself, and the 105 after-only helper erases it. Either way the control stops being a record of a real close pass.

master104: its schedule plant records every job except the DUE ones as run now, with duration_ms 0. That is exactly the form UJ-ON accepts as 'followup_close started after the copy and ended'.

after_back105 (called by aft105): set_sched replaces the whole master_schedule document with a single sl_block_retry entry. So followup_close's entry from a real pass is deleted, and every later run reads NOT YET. copy_back105 also moves COPY_AT to wall-clock minus 30 min.

The plan does not say how 106's --after-only runs are made, or which job lists master104 is given after the copy.
EVIDENCE: master104: R4:1265-1280.
The quiet plant, `'last_run' => time(), …, 'duration_ms' => 0` for every non-due job: rh104-live.php:382.
set_sched: quarantine-stray-store/rehearse.sh:723-726.
SqliteStore::save runs `DELETE FROM [{$table}]` before the insert: lib/SqliteStore.php:440.
after_back105: R5:293; aft105: R5:317-322; copy_back105: R5:291.
The control's condition: deploy-5.18.106.sh:7146.
PROPOSAL: Define run106c, aft106 and rbx106 helpers with no copy_back and no set_sched.

Three rules, each asserted:
- After a copy, every master104 call lists followup_close as DUE.
- Before 5a, `sched104 followup_close` reads none, or a last_run at or before COPY_AT.
- After each close pass, last_run has moved past COPY_AT with duration_ms ≥ 0.

If J1's sender passes go through master104, read followup_close's planted entry before the deploy and require it to be older than the (clock-stamped, per F1) COPY_AT.

=== RH106-F3 [major] 106-pre step 3 (J1) and every close pass
PROBLEM: followup_enabled is unset when the chain ends, and nothing in the plan turns it on before J1.

5.18.105's sender returns at once when both passes are off. With step 6 off, the quote pass is off, and with followup_enabled unset the enquiry pass is off. So J1 makes no refusals and no deliveries, and U1 then has nothing to class as 'F1 closes'.

Both releases' close jobs also return when followup_enabled is off. The interim (step 4) turns it off on purpose afterwards, which is fine.
EVIDENCE: 7835906 cron/followup_send.php:48-50: `$enqOn = !empty($config['followup_enabled'])` … `if (!$enqOn && !$quoteOn) return;`.
followup_close.php:33 in both 7835906 and bf29cbd.
map-mechanics §5: the setting is unset at the end of 105, and no 104 or 105 harness file writes it (grep shows only reads, R4:779 and rh104-ref.php:123).
PROPOSAL: Before J1, run `set_config --key followup_enabled --value 1` as the owner, under the clock, through the real tool. Say it is production's state before the interim, and assert U4's en=on on a copy.

Then keep the plan's order: the jam, then the interim turns it 0, then 106-1 turns it back to 1 through FE-G1's printed command.

=== RH106-F4 [major] 106-pre J1 (refusals with deliveries between)
PROBLEM: Deliveries made by the 5.18.105 sender in the same pass as a refusal count only by chance.

The refused draft was approved first and stays approved, so it heads approvedDrafts' decided_at order in every pass. Any delivery draft approved later is therefore sent after the refusal, in the same second or the next.

F1 skips a delivery whose created_at is not strictly after the previous refusal. A same-second delivery from pass k is never counted for refusal k+1. Whether refusals count then depends on second boundaries, so U1's 'F1 closes' class and the later F1 closes become nondeterministic.

A sender pass cannot deliver 'between passes' without also refusing: the refused draft is always listed first.
EVIDENCE: 7835906 lib/FollowUpService.php:387 (`ORDER BY d.decided_at`) and :628-633 (recordFailure leaves the draft approved).
7835906 cron/followup_send.php:117 (approvedDrafts(10)).
bf29cbd lib/FollowUpService.php:281-282: `while ($d <= $prev) skip; if ($d <= $r) count`.
storeMessage sets created_at = datetime('now') (lib/ConversationService.php:783-786).
PROPOSAL: Plant each delivery between sender passes with ConversationService::storeMessage: outbound, with a wa_message_id, on a conversation of the same channel, sent_at the clock's now.

Sequence each round as: pass k → clk_to104 +60 → storeMessage delivery → clk_to104 +241 → pass k+1. One delivery before the first pass also counts refusal 1.

After the jam, assert the rehearsal's own mirror of refusedWhileDelivering reads ≥3 for each refused follow-up, with the stage-A U1 class 'f1-closes' as the cross-check.

Alternative: teach the stand-in to hold a delivery's answer for more than 1 s.

=== RH106-F5 [major] 106-pre J2 / 106-5c (S1)
PROBLEM: The chain already holds an open enquiry follow-up that S1's plant does not account for. It has:
- a present conversation;
- last_customer_at 2026-11-09 07:58:33;
- an approved draft.

By 106's clock (about 2026-12-25) it is stale and present. expireStale orders by last_customer_at, and the close loop by due_at. If any gone row is younger than this row, pass 1 expires it, ex becomes ≥1, and S1's first pass reads PASS instead of NOT YET.

It also heads J1's first sender pass, since its decided_at is the oldest.
EVIDENCE: Read in memory from scratchpad/b105/rh/snap1/files.tar (the book at the end of 104's chain; nothing was written).
- followups: (1, 'enquiry', last_customer_at 2026-11-09 07:58:33, due_at 2026-11-10 07:58:33, open, conversation present).
- followup_drafts: (1, followup 1, 'approved', decided_at 2026-11-09 09:23:33).

The ordering it must lose to: expireStale's SELECT (deploy-5.18.106.sh:7116-7120, `ORDER BY f.last_customer_at`) and the close loop, allOpen ORDER BY due_at (map-cases D1). Whether 105's sections changed this row is not established.
PROPOSAL: At plant time, read the oldest open enquiry row whose conversation is present (its last_customer_at and due_at). Plant all 400 gone rows older than it, and every other present stale row, J2's included, younger than every gone row.

Assert it at stage A from U3:
- xs_first_cancel=200;
- xs_first_expire=0;
- xs_all ≥ 401.

Count the chain draft in J1's first pass explicitly.

=== RH106-F6 [major] 106-5b, 106-5c, and S2 (the F3-removed weakened copy)
PROBLEM: 5b and 5c cannot both hold on the same book, and S2's weakened copy has no step.

5b vs 5c: 5b expects 'one master close pass, then PASS (F3 works)'. With S1's 400 gone rows planted before the deploy, the first pass cancels 400 and expires nothing, so it reads NOT YET. That is 5c's first half.

The F3-removed copy: S2 describes it, but no section places it. It can read FAIL only while no F3 expiry has happened since the copy, because ex > 0 is checked first. That leaves exactly the window between 5c's first and second passes.
EVIDENCE: uj_on checks the PASS on expiries first (deploy-5.18.106.sh:8083); the FAIL branch is :8095. The plan's 5b and 5c are in design-skeleton.md lines 90-91; S2 is line 13.

§L.10 requires this weakened copy (docs/75:723-724).
PROPOSAL: Merge 5b into 5c and place the copy in that sequence:
1. 5a: NOT YET.
2. Pass 1 (clock +601 s), then --after-only: NOT YET, with cn ≥ 200 and ex = 0.
3. Install the F3-removed followup_close.php into $PD; pass 2.
4. --after-only: UJ-ON 'FAIL — F3 did not expire', with its stale count, and R1's FAIL naming cron/followup_close.php. No PASS line and no NOT YET line.
5. Restore the file, asserted byte-equal to bf29cbd's.
6. Pass 3, then --after-only: PASS, F3 works.

=== RH106-F7 [major] 106-5g, 106-5i
PROBLEM: 5g's deterministic FAIL and 5i's 'nothing stale' cannot be reached on 106-4's deploy after 5c. Once one row has expired since the copy, every UJ-ON of that deploy reads 'PASS — F3 works'.

5g is also missing a step. A FAIL needs a close pass that ran after the copy while the limit was 504 h, and expired nothing. Without that pass the control reads not, which is NOT YET, or it holds from 5c's earlier passes, where ex > 0 gives PASS.
EVIDENCE: ex > 0 → PASS comes before the control (deploy-5.18.106.sh:8083); on_expired counts closes since the copy (:7156).
The FAIL needs: control held, ex = 0, cn < 200 and st = 1 (:8095).
The limit note: :8079.
PROPOSAL: Give 5g and 5i a --redeploy each. This depends on F1's clock-stamped COPY_AT, without which no later deploy sees ex = 0.

5g:
1. Re-plant present stale rows aged between 336 h and 504 h.
2. Set 504; --after-only reads the limit note.
3. A close pass under 504 that expires nothing.
4. Set 336 back; --after-only reads FAIL, exit 1.
5. A pass, then PASS.

5i: on a deploy with no present stale row, one close pass, then PASS 'nothing stale for F3 to close'.

=== RH106-F8 [major] 106-7d/7e/7f, 106-6a, 106-8 (as_back_on guard copy)
PROBLEM: 7e and 7f, as sequenced, would show 'nothing offered' whether or not as_back_on's V1 guard exists, so they do not test it.

What as_back_on needs before it would offer:
- as_rb_last's newest record (newest RB_COPY_AT among the .prev and rollback-state files) carries AS_RB1=written;
- that RB_COPY_AT is earlier than this deploy's COPY_AT;
- the key reads off.

Why that fails as planned:
- After 7d (V1 whose precondition fails; the put-back succeeds), the newest record is 7d's AS_RB1=not-written, so 7e offers nothing even without the guard.
- 7f omits the owner's printed --value 0, so the key reads on and nothing would be offered anyway.

Also, followup_auto_send is never turned on anywhere in the plan. AS-RB1 writes only when it reads on, and 6a and 7e both need it to write.
EVIDENCE: as_rb_last: deploy-5.18.106.sh:8805-8815. as_back_on's tests: :8829-8839. AS-RB1 writes only when the key reads on and the five conditions hold (map-cases B5(c), S:8989-8998).
Plan lines 120-126.
PROPOSAL: Before 106-6a: `set_config followup_auto_send 1` through the real tool.

Before 7e and before 7f:
1. A typed rollback that writes.
2. A --redeploy.
3. The owner's --value 1.
4. Then the V1 deploy.

In 7f, run the printed --value 0 command.

Before each of those --after-only runs, assert the rehearsal's own reading:
- as_rb_last is 'written', with t < COPY_AT;
- the key reads off.

Run 106-8's 'V1 guard dropped' copy on 7e's exact state, right after 7e.

=== RH106-F9 [minor] 106-5d (ME-CA1)
PROBLEM: The 'across the boundary (counted)' sub-case cannot be built as the map describes it, and the plugin.log plants are never cleaned up.

The boundary case: dn_tz() is east of UTC, so a stamp after the copy in local time is also after it read as UTC. Only the mirror can be built: a stamp at or before the copy in local time but after it read as UTC (within 3 h before the copy). That one must NOT be counted.

Cleanup: planted lines persist in $PD/data/plugin.log. One 'ERROR after the straddle' line makes every later --after-only of this deploy (5e–5i) FAIL on ME-CA1.
EVIDENCE: ME_PHP reads stamps in dn_tz() (deploy-5.18.106.sh:8126-8150). master_log uses date() (bf29cbd cron/master.php:440-443).
master104 sends master's output to `$R104/logs/master-*.out`, not plugin.log (R4:1278), so every [master] line in plugin.log is a fixture.
me_ca1 FAILs on err_after (:8207).
PROPOSAL: Compute stamps from STATE's COPY_AT using the installed lib/timezone.php's dn_tz(). Do not use fnow104, and do not assume Kampala.

Assert the discriminating plant: a local stamp at COPY_AT−1 h is not counted.

Around each 5d sub-case, `cp -p` plugin.log aside and restore it afterwards, asserted by checksum. Do the same with the state file around 5e (UJ_A removed), so that 5g's limit note still has stage A's hours.

=== RH106-F10 [minor] J1 sender passes; every close pass in 106-5/6/7
PROBLEM: master104 does not force a due job to run. master skips a job whose interval has not passed since its own last_run: 600 s for followup_close, 300 s for followup_send. The skip is silent: the cycle exits 0 and the control does not move.

The plan never moves the clock between passes.
EVIDENCE: bf29cbd cron/master.php:367 (`if ($_m_elapsed < $_m_interval) continue;`); the intervals are at :238 and :241.
master104 echoes only master's exit status (R4:1277-1279).
PROPOSAL: Before each pass, run clk_to104 +601 (close) or +301 (send).

After each pass, assert both:
- `sched104 <job>` shows last_run moved to that pass's clock time, with duration_ms ≥ 0;
- the master-*.out file holds `RUN <job>`.

=== RH106-F11 [minor] 106-pre J1 (the sender's window)
PROBLEM: The plan places J1 in '08:00–20:00 Kampala', but the window also excludes Sunday.

The clock can only move forward, and where 105 left it is not established (the record reached Kampala 2026-12-25). A hard-coded day can therefore be refused, or land on a Sunday. Either way every draft stays approved and nothing is sent.
EVIDENCE: FollowUpPolicy::withinSendingWindow returns not-ok when dow === 0 (lib/FollowUpPolicy.php:182-205).
clk_to104 refuses to move back and logs `clock:back` to anchor-missing (R4:1231-1235).
PROPOSAL: Derive the day at run time: the next Kampala date after kday104(fnow104) whose kdow104 is not 7. Then run clk_to104 "$(kat104 "$D 09:00")", and assert the hour is 8–19 and the day is not Sunday before every sender pass.

Keep J1 within one window: 5 passes take about 30 min of clock.

=== RH106-F12 [minor] S10 (Evolution stand-in edit)
PROBLEM: The edit's anchor and position are not specified, and the map lists only two of the router's three existing edits.

The rh104-send block answers every /message/send(Text|Media)/ with a key.id and returns. A 400 block placed after it would never fire.

Deliveries need rh104-send.on present and LIVE104=yes. Without it, sends get `[]` with no id and never count as a delivery.
EVIDENCE: The base router: RQ:289-294.
The three asserted edits:
- media: R4:1490;
- send: R4:1515-1528, file created at R4:1532 and never removed in 104 or 105;
- slow: R4:4011-4022, inserted before the requests.log line.

Why a JSON body matters: 7835906's requestUg builds `<message|error> [HTTP 400 on POST /message/sendText/<instance>]` from a JSON answer, and an HTML answer gives 'Got an HTML page…', which F1 excludes (bf29cbd lib/FollowUpService.php:262-263).
PROPOSAL: Anchor: the line beginning `if (is_file(__DIR__ . '/rh104-send.on') && preg_match('#/message/send(Text|Media)/#'`, which occurs once. Insert before it.

Asserts:
- 'rh106' is absent first;
- the `[]` line occurs once;
- requests.log line < rh106 block < rh104-send.on, by position.

The block answers 400 only for /message/sendText/, and only when the body number's digits are in a list file beside the router. Its body is JSON `{"status":400,"error":"Bad Request",…}` and never echoes the number.

Control:
- list absent: key.id;
- list present: 400 for a listed number, key.id for another;
- both requests are logged;
- one sender pass's failed-event detail matches F1's LIKE.

Assert `$SB/evo/rh104-send.on` exists, and LIVE104=yes, at 106's start.

=== RH106-F13 [minor] 106-pre … 106-8 (runners)
PROBLEM: The plan names no runner of its own, and the cut-carried helpers are bound to 5.18.105:
- run105, run105c and run_line105c hard-code DNB_OUT=$OUT105 and R105's folders;
- rbx105 asserts '$V105 (rollback): PASSED' and that live is $B105 (994d660);
- aft105 calls after_back105 (F2).

Used for 106, these would write into 105's records, or check the wrong version.
EVIDENCE: R5:221-256 and R5:317-331.
PROPOSAL: Define run106, run106c, run_line106c, fresh106, aft106 and rbx106. They use DNB_OUT=$OUT106 and R106 folders, with no copy_back or set_sched.

rbx106 expects 7835906 serving after the rollback, then the quarantine's --check.

Keep cron104_up out of them. Under 106 it exits at once, because live is never $P104 (R4:2681).

=== RH106-F14 [minor] S9 / J2 (gone conversations)
PROBLEM: tools/wa_prune_history.php --delete-sudan is the riskier writer for 400 gone rows. Each run it:
- VACUUMs a full backup of the book into the data folder;
- deletes every 249… conversation, with its messages and wa_media;
- VACUUMs the live book.

It also needs 400 conversations on 249… numbers, which lie outside the range the rehearsal's own helpers accept and outside its pattern files.
EVIDENCE: tools/wa_prune_history.php:76-98.
rh104-live.php:109-112 accepts only `^\+?2567001040[0-9]{2}$`.
snap1's book holds 46 conversations, all 256…, max id 46 (read in memory). Whether 105 adds any is not established.
PROPOSAL: Use FollowUpService::open with conversation ids that no conversation holds. It touches only followups, plus one 'created' event per row.

Pick ids above any id the sandbox can reach (e.g. 9,000,001–9,000,400), and assert they are absent from wa_conversations before and after. Give each row last_customer_at older than every present stale row (F5), and reuse one fictitious number.

Say it is 'the state a prune leaves', and keep the prune tool out of the chained sandbox.

##### completeness
SUMMARY: The plan covers most of §L.10's cases, but as written it would not prove several of them. Two blocking defects. (1) UJ-ON's "since the copy" counts and its control compare times from the clock shim with a COPY_AT taken from the file's real timestamp. COPY_AT is the COPY_MARK file's kernel mtime (touch -c, then stat %Y; deploy-5.18.106.sh:13813, 13868). Expired events' created_at and master's last_run come from the shim, which the chain left about 39 days ahead. So after the first F3 expiry, every later copy reads "UJ-ON PASS — F3 works" (ex>0 is tested first, :8083). Within 106-4's single deploy, ex>0 is also permanent. The result: 5b and 5c are the same sequence; 5g's deterministic FAIL, 5i's "nothing stale" and S2's F3-removed copy cannot be reached; and the control can hold on a pass made before the copy. (2) 106-pre never turns followup_enabled on. 5.18.105's sender runs its enquiry pass only while followup_enabled is on (7835906 followup_send.php:48, 50, 117), so J1's refusals are never recorded. followup_auto_send, followup_daily_cap 3 and followup_run_limit 2, production's values (§A), are not set either, so U5 at the deploy does not read production's state. Major: §L.10's F3-removed weakened copy is in S2 but in no section. CS-B1 has no case and no weakened copy. U4's capitals note "before DEPLOY" is dropped, so UJ-ON's stage-A-only branch and the "will not judge" arm of the summary's "check it" are never reached. The 'now' arm lacks its run before the close pass. Fixtures and in-deploy edits are never undone: removing UJ_A stops 5g's limit note from firing, ME-CA1's planted FAIL line breaks later --after-only runs, and an undecodable kyc_config.json makes FE-G1 and LK-G1 refuse the --redeploy that 106-6 says follows each case. followup_auto_send and the latest rollback record are not sequenced: 7a's offer cannot come from 6a, because as_rb_last takes the newest record, and A14 needs a quarantine --check after every rollback. The weakened copies, all placed at the end in 106-8, lose the states that would catch them. S1 must cover every present stale row. S5's index.lock must be planted in the middle of the run. Settled points S1, S4, S5, S6 and S7 are right on the code, but they narrow §L.10 and must be recorded in docs/75.

Q: 1. Which new or changed checks, or branches of them, does no section reach?
A: Never reached: CS-B1 (deploy-5.18.106.sh:7433, 7465). uj_on's stage-A-only branch (:8073-8074) and the summary's 'check it' arm for a deploy UJ-ON will not judge (:16019-16021). U4's capitals note with its DEPLOY clause (:7985). The 'now' arm's run before the pass. S2's F3-removed copy. U1's 'held' class and AS-RB0's F5 capitals line (J3 is never judged). CS-G1's and rb_pre106's step 11 and 12 branches and their order. as_after_verdict. as_para rb:*. RB_NOTHING's capitals AS-RB2. UJ-ON and UJ-CA when COPY_AT is missing. ME-CA1's mirror stamp and its NOT ESTABLISHED form for an absent log. The owner's steps printed after a PASSED --after-only. Unreachable as ordered or on one deploy: 5g's FAIL, 5i's 'nothing stale' and S2 (RH106-1). The 'as_back_on V1 guard' and 'AS-RB1 condition 3' copies placed in 106-8 (RH106-9). Reached in principle: CS-G1 for k,s and s-only, FE-G1, U1–U5, UJ-CA, UJ-ON's PASS, NOT YET and limit note, ME-CA1's main cases, AS-RB0's off, on:ok and on:differ, AS-RB1 written and off, AS-RB2, rb106, as_back_on and the 'NOT YET' summary. U4's StaffJobsGate-false branch and AS-RB1's written-check, refused and unknown branches would need fault injection; the plan should list them as left out.

Q: 2. Are settled points S1–S11 right, and do they change §L.10?
A: S1: the mechanism is right (expireStale orders by last_customer_at, FollowUpService.php:218-238; the main loop by due_at, followup_close.php:43-48). But the 400 gone rows must be older than every present stale row, the chain's leftovers included. It changes §L.10 (200 → 400), so docs/75 must record it. S2: right, but no section places it. S3: plausible; note that the installed public.php can run master after 300 s (R4:2064-2068), and master104's planting writes every job's entry. S4: right (PluginConfig::read reads files only, :76-91); it narrows §L.10's 'cause 1's notice', so record it. S5: the mechanism is right, but the lock must appear mid-run (RH106-11), and .redirect106 needs defining. S6: right (as_rb_last reads state.prev, :8807-8818); record it in docs/75 and pin it as a check. S7: right (derive106.py:244-267); record the narrowing. S8: the list is incomplete. It lacks index.lock, the F3-removed installed close job, and the restores of plugin.log, UJ_A and kyc_config.json. S9: right, but must add set_config for followup_enabled, followup_auto_send, followup_daily_cap and followup_run_limit. S10: right. S11: right; the gone rows may share numbers, because open()'s unique index is per conversation (FollowUpService.php:95-131).

Q: 3. What state hazards does the order of sections create?
A: (a) UJ-ON's PASS is permanent: within a deploy by design, and across deploys because the clock shim runs ahead of the COPY_AT taken from the file's timestamp (RH106-1). (b) A writing rollback leaves followup_auto_send off before cases that need it on; the attribution of 7a and 7b to 6a is wrong; A14 needs a quarantine --check after each rollback (RH106-5). (c) Removing UJ_A in 5e kills 5g's limit note. ME-CA1's planted FAIL poisons later --after-only runs. The undecodable kyc_config.json blocks the following --redeploy through FE-G1 and LK-G1 (RH106-4). (d) The 400 gone rows could be eroded before the copy by any master cycle, including public.php's in-process run (RH106-10). (e) J3 survives only with no sender pass and no aging under the clock until 6a (RH106-12). (f) The weakened copies at the end lose their states (RH106-9). (g) The clock only moves forward: 5g's 336–504 h window and J3's freshness must be planned against it. The claim switch is sequenced coherently (CS-G1 → off chase → FE-G1 → GO; 6f → 7a).

Q: 4. What does 5.18.105's rehearsal leave, and does 106-pre reach production's state faithfully?
A: What 5.18.105 leaves is described correctly: L2, 994d660 serving, followup_* unset (no harness writes them; deploy-5.18.104/rh104-ref.php:123 only reads them). 106-pre is not faithful. It never sets followup_enabled 1, so J1 cannot be planted (7835906 followup_send.php:48, 50, 117). It never sets followup_auto_send 1 (production approves automatically, docs/75:22), followup_daily_cap 3 or followup_run_limit 2, so 106-4's U5 and AS-RB0 read off where production will read on. The interim and 106-1's order (CS-G1 refusal → off chase → FE-G1 refusal → followup_enabled 1 → GO) do match production. Redeploying 5.18.105 with step 6 still on differs from production's history, but only A14 reads PREV_STATE (deploy-5.18.106.sh:1538, 13180), so it is harmless.

Q: 5. What does §L.10 or its 18 Nov addendum list that the plan drops without saying so?
A: (1) The F3-removed weakened copy of the installed close job (docs/75:722-724). (2) U4's capitals note before DEPLOY (:746-747). (3) The 'now' arm's run before that pass (:748-749). (4) CS-G1 'in each config.json', and each case's weakened copy (:716-717). (5) ME-CA1's mirror side of the boundary (:720). (6) Judging J3, the two-draft follow-up planted in the jam (:715). Recorded, not silent: K5's eight copies (S7). The 200 → 400 change (S1) and S4's undecodable sub-case are recorded only in the plan, not yet in docs/75. CS-B1 is not named in §L.10, but the script adds it and the plan never reaches it.

=== RH106-1 [blocking] 106-5 (and every later --after-only); the rehearsal's clock against COPY_AT
PROBLEM: UJ-ON's results are permanent, within a deploy by design and across deploys because of a sandbox artefact. So 5b and 5c collide, and 5g's FAIL, 5i and S2's copy cannot be reached. NOT YET can also turn into a judgement on a close pass made before the copy.
EVIDENCE: In deploy-5.18.106.sh, uj_on tests ex>0 first, so any expiry since the copy reads PASS (:8083). on_expired is followup_events.created_at >= gmdate(COPY_AT) (:7156), and the control is last_run > COPY_AT (:7146). COPY_AT is stat %Y of COPY_MARK after stamp_copy's `touch -c` (:13813, :13868), which is the kernel's real time: rh104-clock.c intercepts only clock_gettime, gettimeofday and time (:53, :60, :67). created_at (SQLite 'now') and master's last_run follow the shim, which the record run had at Kampala 23–25 Dec against a real 14–15 Nov (map-mechanics.md:90). So every expiry the rehearsal ever makes is 'since the copy' for every later copy. master104's planting writes every non-due job's entry, followup_close included, at time() (deploy-5.18.104/rh104-live.php:382), so the control holds before any pass after a copy. after_back105 calls set_sched (deploy-5.18.105/rehearse.sh:293), and set_sched saves master_schedule.json whole (quarantine-stray-store/rehearse.sh:723-727; SqliteStore::save DELETEs, lib/SqliteStore.php:440). A port of it would erase the pass made after the copy and read NOT YET for ever. On 106-4's deploy, J2's 400 gone rows make the first pass NOT YET (S1), so 5b's 'one pass, then PASS' (design-skeleton.md:91) is 5c's second pass. After that PASS, 5g's FAIL (:98-100), 5i (:103) and S2 (:13-15) read PASS instead.
PROPOSAL: (1) After each 5.18.106 copy, before any close pass, an after_back106 puts COPY_MARK's mtime and the state file's COPY_AT and DEPLOYED_AT on the clock's base: touch -d @$(( $(fnow104) - M )), with M larger than R19's and V4's gap and later than the last followup_close run and closer event before the copy. Alternatively, put a `touch` in $SB/datebin that applies RH104_CLOCK_FILE when no time is given, so that COPY_AT and RB_COPY_AT share the clock's base. (2) Write sl_block_retry through rh104-live.php's schedule 'only' mode (:373-377), never set_sched. (3) Before each judged --after-only, a control from the rehearsal's own reading: followup_close's last_run and the closer's expired and cancelled events against COPY_AT (5a: none at or after it; 5b: exactly the passes run since). (4) Re-sequence 106-5. On 106-4's deploy: 5a, pass 1, 5c's NOT YET, pass 2, PASS (this is 5b; it is not a separate case). 5d, 5e, 5f and 5h's note need no fresh copy. 5g's FAIL, the 'now' run before the pass, 5i and S2's copy each get their own copy (typed rollback, the quarantine's --check, --redeploy), with no expiry since it.

=== RH106-2 [blocking] 106-pre (production's state; J1)
PROBLEM: followup_enabled is never turned on before the jam, so 5.18.105's sender records no refusal and J1 is never planted. 106-pre also does not reach production's followup_* state.
EVIDENCE: 5.18.105's rehearsal leaves followup_* unset (map-mechanics.md:178; a grep of the chain's harnesses finds only reads, e.g. deploy-5.18.104/rh104-ref.php:123). In 7835906, cron/followup_send.php:48 sets $enqOn = !empty(followup_enabled), :50 returns when neither pass is on, and :117 lists enquiry drafts only while $enqOn. The plan runs J1 at design-skeleton.md:69-71, before the interim's 'followup_enabled 0' (:74), and nowhere sets it to 1. Production per docs/75:22 (§A): followup_daily_cap 3, run limit 2, '647 SEND drafts … all approved automatically', so followup_auto_send is on. docs/75:727 calls row-only keys production's normal state. U5 (deploy-5.18.106.sh:8762-8780) and AS-RB0's 'on' branches (:8934-8957) need it on.
PROPOSAL: In 106-pre, after `off chase` and before J1, set through tools/set_config.php as the book's owner: followup_enabled 1, followup_auto_send 1, followup_daily_cap 3 and followup_run_limit 2. Assert each by its '  <key> = <v>' line and by U4's reading. Give J1 a positive control: by the rehearsal's own reading, each refused draft has four or more 400 'failed' events and refusedWhileDelivering ≥ 3. Only then run the interim (followup_enabled 0, `on chase`, `--check chase`). 106-4's U5 then reads 'on … hold', as production's will.

=== RH106-3 [major] 106-5 (S2)
PROBLEM: §L.10's weakened copy of the release (F3 removed from the installed close job) is discussed in S2 but placed in no section.
EVIDENCE: docs/75:722-724 ('asserting UJ-ON's own FAIL line with its stale count and no UJ-ON PASS or NOT YET line, R1's failure expected beside it'). design-skeleton.md:13-15 (S2) against 89-103 (106-5's a–i), where no case installs the mutated cron/followup_close.php. FAIL needs ex=0 since the copy and cn<200 (deploy-5.18.106.sh:8091-8095), which RH106-1 shows needs its own copy.
PROPOSAL: Add 106-5j on its own copy, after S1's gone rows are cleared. First assert fewer than 200 gone rows open and at least one present stale row. Remove the F3 block (followup_close.php:84-93) from the installed file by an asserted edit before any pass, then run one master104 followup_close pass. On --after-only, expect '  FAIL  UJ-ON FAIL — F3 did not expire' with stn equal to the rehearsal's count; no UJ-ON PASS or NOT YET line; 'R1 installed files that differ: cron/followup_close.php' (:14400); exit 1. Then restore the file (rollback and redeploy) and assert R1 ok.

=== RH106-4 [major] 106-1, 106-5, 106-6 (fixtures and in-deploy edits)
PROBLEM: No fixture is undone, and the order turns that into failures. Removing UJ_A in 5e makes 5g's limit note impossible. ME-CA1's planted FAIL line breaks every later --after-only of the same deploy. The undecodable kyc_config.json, and followup_auto_send held in a config.json, make the --redeploy that 106-6 says follows each case refuse.
EVIDENCE: uj_on computes lim only when stage A's reading is on record: `alone` is set at deploy-5.18.106.sh:8063, and the lim and ga block runs only if [ -z "$alone" ] at :8067. In ME_PHP, an ERROR stamped after the straddle counts for as long as COPY_AT stays the same (:8172-8176); a non-zero count is bad (:8207). PluginConfig::read reads files only (lib/PluginConfig.php:76-91), so an undecodable kyc_config.json reads followup_enabled off and FE-G1 stops (deploy-5.18.106.sh:8003-8014); LK-G1 also refuses an unread place (:7366-7367). Plan: 5d (design-skeleton.md:93-95) and 5e (:96) come before 5g (:98) on one deploy; 106-6d (:112-113) is followed by '--redeploy back' (:104); store-only key, config.json key, '3' against 3, the uj hook and index.lock (:31-37, :23).
PROPOSAL: Pair every fixture with its undo and a check that the undo held. UJ_A: copy the line aside and put it back right after 5e, asserting the state file byte for byte. ME-CA1: record plugin.log's size before each planting and truncate back after each sub-case, or give 5d its own copy and run it last. kyc_config.json and the config.json key: restore byte for byte by sha256 before the --redeploy. Store-only key and the claim key in a config.json: remove them and show that LK-G1 and CS-G1 read clean. The uj hook's trigger and index.lock: remove and assert gone.

=== RH106-5 [major] 106-6 / 106-7 (followup_auto_send and the latest rollback record)
PROBLEM: Every writing rollback leaves followup_auto_send off. Most later cases need it on, and the plan never turns it back on. 7a's offer is attributed to 6a, but as_rb_last takes the newest rollback, which is 6f's, after 6e's 'already off'. A14 refuses a --redeploy without a quarantine --check after the rollback.
EVIDENCE: rb_pre106's capitals notice and command come only when eff=on (deploy-5.18.106.sh:8939-8957). as_rb1 writes only on (:8989-8998, :9045). as_rb_last picks the newest RB_COPY_AT (:8805-8818); as_back_on offers only when that record is 'written' and the key reads off (:8826-8856). Cases needing the key on: 6b, 6c, the config.json sub-case of 6d, 7c, 7d, 7e, 7f and 7h (design-skeleton.md:107-113, 120-128). 7a reads '(after 6a's typed rollback that wrote)' (:117-118), after 6b–6f, and 6e leaves it off (:114). A14 requires a --check that began after every rollback (:13180-13205); rbx105 runs qrun104c --check after each (deploy-5.18.105/rehearse.sh:323-331).
PROPOSAL: Write a per-case precondition table: key on or off; the latest rollback record (AS_RB1, RB_COPY_AT); what serves. Before each case that needs the key on, turn it on by running, through run_line105c, the turn-back-on command a PASSED --after-only offered (this also proves that command), or set_config --value 1 where none was offered. Make 7a follow 6f with the key on at 6f, so that 6f's rollback writes; arrange the same before 7b. Follow every typed rollback by qrun104c --check. Precede every --after-only expected PASSED by a master104 followup_close pass after its copy.

=== RH106-6 [major] 106-1 / 106-8 (CS-B1)
PROBLEM: CS-B1, the claim-switch check after DEPLOY is typed, has no case and no weakened copy.
EVIDENCE: cs_check CS-B1 runs inside qf_keep_and_baseline, before LK-B1's map comparison (deploy-5.18.106.sh:7459-7466); its own stop is at :7433. design-skeleton.md:76-88 and 129-137 never mention it. 5.18.105's precedent is lkb1run105, which uses --hold and R105_STREAM (map-mechanics.md:120).
PROPOSAL: Hold a deploy at 'Type DEPLOY', run `on chase` (typed yes) while it waits, then type DEPLOY. Assert: '  CS-B1 A CLAIM SWITCH IS PRESENT: quote_chase_enabled@k,s'; the off command alone; the --check command alone; 'NO-GO (CS-B1): a claim switch was turned on during this run, after stage A'; no 'NO-GO (LK-B1): a ladder key changed' line; nothing copied (installed files still 5.18.105's). Then `off chase`. Add a weakened copy without the CS-B1 call, caught because the specific text is absent and LK-B1's generic refusal appears instead.

=== RH106-7 [major] 106-1 (5), 106-4 (U4's capitals note before DEPLOY)
PROBLEM: §L.10's 'U4's capitals note … before DEPLOY' is dropped: only --check is run, and 106-4 asserts the note is absent. As a result, uj_on's stage-A-only branch and the 'will not judge' arm of the summary's 'check it' are never reached, and the copy 'UJ-ON's gate merged' has no case that can catch it.
EVIDENCE: docs/75:746-747. The clause ', ANSWER ANYTHING BUT DEPLOY BELOW' appears only when CHECK_ONLY is empty (deploy-5.18.106.sh:7985). The ga branch is at :8073-8074; check it's uj_judgeable arms at :16016-16021. Plan: design-skeleton.md:82-83, 86, 133-134.
PROPOSAL: In 106-1, with followup_max_age_hours 0, run a deploy answered 'no'. Assert the note with its DEPLOY clause before '  Type DEPLOY to deploy', then 'not confirmed', with nothing changed. On a copy of its own, answer DEPLOY; set the limit back; run a close pass. The --after-only must give 'UJ-ON not judged for this deploy — its gate did not hold at stage A' and the summary's 'UJ-ON WILL NOT JUDGE F3 FOR THIS DEPLOY' arm. Run the gate-merged copy there.

=== RH106-8 [major] 106-5 g/h (the run before the close pass)
PROBLEM: The 'now' arm is missing §L.10's 'run before that pass'. 5g does not say that a close pass runs under the other limit before the limit is set back, and without one the control is 'not' and the run reads NOT YET, not FAIL.
EVIDENCE: docs/75:747-749 ('(judged after a close pass, and a run before that pass) … (the same)'). The 'now' note itself warns that such a run 'can read FAIL' (deploy-5.18.106.sh:8071). followup_close returns while followup_enabled is off (cron/followup_close.php:33), but master still writes last_run. design-skeleton.md:98-102.
PROPOSAL: On a fresh copy: followup_enabled 0, then one close pass (the control holds, nothing expired), then the 'now' note and the 'now' arm of check it. Then followup_enabled 1 and an --after-only before any pass: FAIL 'F3 did not expire' (stale rows present, cn < 200). Then a pass, then PASS. For 5g, run one pass under 504 explicitly before setting the limit back, and first assert that every present stale row is between 336 and 504 h old by the clock.

=== RH106-9 [major] 106-8 (the weakened copies' states)
PROBLEM: All seven copies run after 106-7, when the states that would catch them no longer exist.
EVIDENCE: as_back_on's V1 guard (deploy-5.18.106.sh:8829) is caught only on 7e's or 7f's state: this deploy's AS_RB1 written or not-written:differ, the key off, and an older 'written' record. 7g and 7h replace that state (design-skeleton.md:123-128). AS-RB1's condition 3 needs a 'differ' book with the key on at ROLLBACK. The CS-G1 and FE-G1 copies need 5.18.105 serving with chase on, or followup_enabled off. 5.18.105 ran its copies with rbx105 and drest105 L2 after each deploying copy (map-mechanics.md:153).
PROPOSAL: Run each copy beside the case it weakens. The V1-guard copy's --after-only runs right after 7e's or 7f's real one, on the same state. The condition-3 copy runs at 6b or 6c with ROLLBACK typed, its write asserted and then undone. The gate copy runs at 5g, 5h and the stage-A case. The CS-G1 and FE-G1 copies run inside 106-1. Where a copy stays in 106-8, state how the state is rebuilt and assert it before the copy runs.

=== RH106-10 [major] S1 / J2 (the 400 gone rows)
PROBLEM: S1 holds only if the 400 gone rows are older than every present stale open enquiry row: the chain's leftovers, J1's stale refused set (for F2) and J2's other stale rows. As written, 'older than one present stale row' lets pass 1 expire a present row and read PASS. J2 also points at '106-5d' where it means 5c.
EVIDENCE: expireStale orders by f.last_customer_at, and a gone row is cancelled (lib/FollowUpService.php:218-238). The main loop runs allOpen ORDER BY due_at (cron/followup_close.php:43-48), and due_at = last_customer_at + 24 h (lib/FollowUpPolicy.php:231-238). J1's stale set is at map-cases.md:47. design-skeleton.md:9-12 and :72. A further early-consumption path: the installed public.php runs master in-process once 300 s have passed since piggyback_last_run.txt (deploy-5.18.104/rehearse.sh:2064-2068).
PROPOSAL: Plant the 400 gone rows older than every open enquiry follow-up in the book. At the copy, assert by the rehearsal's own reading that the 400 oldest rows that are stale by the limit are all gone conversations. Fix the reference to 5c. Confirm that no request reaches the installed public.php between planting and the copy, or keep piggyback_last_run.txt at the clock's now.

=== RH106-11 [major] S5 / 106-7 e–g (index.lock)
PROBLEM: If the lock is planted before the run, the deploy stops at its own checkout and copies nothing. If it appears between the copy and the checkout back, the deploy records a FAIL. It must appear after the copy and the checkout back, and before V1's do_rollback. The stand-in `.redirect106` is used (7c) but never defined.
EVIDENCE: The deploy's own checkout stops with 'git checkout … failed — nothing was deployed' (deploy-5.18.106.sh:13855). The checkout back gives bad on failure (:13863). do_rollback's checkout is at :13818; V1 is at :13923. design-skeleton.md:22-24, 120. S10 (:42-43) covers only Evolution's edit, while .redirect105 needed its own asserted edit gated on 5.18.105's manifest (deploy-5.18.105/rehearse.sh:422-438).
PROPOSAL: Define .redirect106 as an asserted edit of the stand-in public.php (manifest '5.18.106', with its probe and control). Let the trigger also create $REPO/.git/index.lock when it is spent. Assert the output holds 'the checkout is back on' and then 'git checkout 7835906 failed — nothing was deployed', and that 5.18.106 serves. Remove the lock and assert it gone.

=== RH106-12 [minor] 106-4 (J3)
PROBLEM: J3 is planted after the copy and never judged. U1's 'held' class and U3's F5 forecast at stage A are never reached.
EVIDENCE: AS-RB0's capitals line on F5's count is at deploy-5.18.106.sh:8892; U1 classes 'held' at :7055; design-skeleton.md:88. No section asserts either. J3 also needs no sender pass, and no staleness under the moving clock, until the rollback.
PROPOSAL: Plant one pair before the copy, with master idle, so that stage A's U1 shows 'held' and U3 shows F5 '1/2'. Keep it fresh and send nothing until 6a. At 6a, assert AS-RB0's 'OPEN ENQUIRY FOLLOW-UP(S) HOLD TWO OR MORE APPROVED DRAFTS' with its count; at a later rollback, assert the 0/0 plain note.

=== RH106-13 [minor] 106-1 (CS-G1 variants)
PROBLEM: Three gaps against §L.10's CS-G1 bullet. It says 'in each config.json'; the plan has one. It says each case's weakened copy is caught; the plan does not say which case the single CS-G1 copy runs on. The order 'highest step first' and the step 11 and step 12 commands are never shown, in CS-G1 or in rb_pre106.
EVIDENCE: docs/75:716-717. design-skeleton.md:80-81, 131. cs_check's three branches are at deploy-5.18.106.sh:7417-7431; rb_pre106's STEP 12 and 11 notices are at :8895-8907.
PROPOSAL: Run the LK-G1 case for <plugin>/data/config.json and for <data>/config.json. Run the CS-G1 copy on both the k,s case and the s-only case. Extend the store-only fixture to offers_live_enabled@s and offers_test_enabled@s beside chase, and assert the three commands in order, each alone.

=== RH106-14 [minor] 106-5d (ME-CA1)
PROBLEM: §L.10 asks for errors 'on both sides of the boundary'. The plan has only the side that counts; the mirror case, which must not count, is missing. Real master cycles in the sandbox never write plugin.log, which the plan does not state.
EVIDENCE: docs/75:719-721. map-cases.md:142 gives the mirror case. master_log echoes to stdout (cron/master.php:440-443), and master104 sends that to master-*.out (deploy-5.18.104/rehearse.sh:1278). design-skeleton.md:93-95.
PROPOSAL: Add the mirror: a local stamp before the copy whose UTC reading is after it, counted 0. Assert both NOT ESTABLISHED forms: the log absent ('COULD NOT BE READ THERE') and no [master] lines (the control). State that ME-CA1 is judged over planted lines only, or append master104's output to plugin.log as uCRM's runner would.

=== RH106-15 [minor] 106-5 to 106-7 (summary and rollback texts)
PROBLEM: Several new branches are reached by no section, and none is said to be left out.
EVIDENCE: as_after_verdict, after a typed ROLLBACK that did not write while the key read on (deploy-5.18.106.sh:8782-8803, 16089). as_para rb:* for a typed rollback whose put-back failed (:8748-8750, :13892). RB_NOTHING's capitals AS-RB2 with the owner's command (:9139-9142). UJ-ON's FAIL and UJ-CA's note when COPY_AT is missing (:8077, :8051). Plan 5a asserts the owner's steps are absent, but no PASSED --after-only asserts them present (:16095-16147).
PROPOSAL: Add these cases. In 6b, type ROLLBACK once and assert as_after_verdict's command. After 7d (5.18.105 serves, key on), run --rollback and expect RB_NOTHING's capitals note and command. Remove COPY_AT from a copy of the state file (a fixture, said so). In 5b, assert the owner's steps with '<CAP>', the chase lines that match LK_AO_ON, and the run-limit command. Any branch left out should be listed as such.

=== RH106-16 [minor] Settled points S1, S4, S5, S6, S7 against docs/75 §L.10
PROBLEM: S1, S4, S5, S6 and S7 are right on the code but change what §L.10 promised. The plan records them only in a scratch file, and S6 is 'recorded, not built'.
EVIDENCE: S1 (400, not 200) against docs/75:725. S4: cause 1's notice only with followup_auto_send on from a config.json (lib/PluginConfig.php:76-91; deploy-5.18.106.sh:8931-8932, 8725) against docs/75:734. S6: as_rb_last reads state.prev (:8807-8818), so after a V1 whose put-back succeeded nothing is offered, against docs/75:745-746. S7: K5's copies run in the derivation (wip/derive-5.18.106/derive106.py:244-267) against docs/75:737.
PROPOSAL: Add a dated §L.10 note to docs/75 that records each narrowing and its reason. Pin S6 as a check, labelled as the known limit for the owner's decision: a V1 that read the key off and put back successfully, then a --redeploy whose PASSED --after-only offers nothing. A change of behaviour is then noticed.
