# 75 — Uganda: the enquiry follow-up sender, unjammed (5.18.106 design, 15 November 2026)

**Status:** DESIGN, reviewed — written 15 Nov 2026 (evening, UTC), from the read-only report's two runs on the Uganda
server and a read of the code at `release/5.18.105` (7835906); revised the same evening after an adversarial review in
two lenses (correctness against the code; safety and rollout), whose ten findings are taken in §C–§J. Nothing here is
built yet. 5.18.106 is cut from 5.18.105 and can be deployed only after it.

**Asked for:** the owner, after the report: "yes build it" — a failing message is moved aside instead of blocking the
rest, old approved messages expire instead of going out weeks late, and the run stops spending itself on old enquiries.

---

## A. What was measured (the report, 15 Nov 16:32 and 18:22 UTC, `scripts/enquiry-followup-report.php` at 8d4d52e)

Counts only; no number, name or instance is recorded here.

| | |
|---|---|
| last enquiry follow-up sent | 44.5 days ago; none in 7 days |
| approved drafts waiting on open follow-ups | 1,979 — held by only **40** follow-ups (about 49 each) |
| the sender's 10 places (the 10 oldest approved drafts) | 10 of 10 failed since approval, **381,010 times in all** (38,020–38,170 each); first failure 44.4 days ago; every latest failure `HTTP 400 Bad Request`; all on the sales department number |
| failed sends in 7 days, by kind | `HTTP 400 Bad Request` 6,110 |
| the waiting drafts, by the customer's last message | **all 1,979 more than 30 days ago** |
| open enquiry follow-ups | 3,939; by the customer's last message: <3 d 214 · 3–7 d 481 · 7–14 d 421 · >14 d 2,823 |
| the run | judges the 2 earliest-due open follow-ups every 10 minutes (`followup_run_limit` 2), oldest first; 647 SEND drafts in 7 days, all approved automatically |
| settings | `followup_daily_cap` 3 · `followup_max_age_hours` 336 (default) · ledger on, RECORD (enquiry claims off: `quote_chase_enabled` not yet on) |

## B. The causes, as the code at 7835906 shows them

1. **Head-of-line block.** `followup_send.php:117` takes `approvedDrafts(10)` (`ORDER BY d.decided_at`), and a certain
   failure goes to `FollowUpService::recordFailure()` (`:628-633`), which only logs `failed` and leaves the draft
   approved — *"a transport failure is not a decision to abandon the message"*. 5.18.54 (docs/46 row 31) limited only
   the *may-have-been-sent* case. So ten drafts that Evolution refuses with `400` are first again every five minutes,
   for ever, and nothing behind them is tried. (A `400 Bad Request` is, among other cases, Evolution's answer for a
   number not on WhatsApp; the book keeps no more than the short message and the code, so the cause per number is an
   inference, not a fact.)
2. **Re-drafting.** `followup_run.php:103-104` skips a follow-up only while it holds a *pending* draft. `approve()` does
   not move `due_at`, and nothing sends, so a follow-up whose approved draft is stuck stays due: on every pass where it
   is among the earliest due it goes to the gate and the model again, gets a new SEND draft, and is approved again
   (`:210-222`). That is how 40 follow-ups hold 1,979 approved drafts. `recordSend()` (`:585-626`) marks only the
   draft it sent; the follow-up's other approved drafts stay approved. **Released as the code stands, the sender would
   send several of one customer's drafts in one pass** — record mode has no one-per-customer rule, and `attempts` is
   checked by the run's gate, not by the sender. (QuoteChase already guards against exactly this:
   `QuoteChase.php:214` `NOT EXISTS (... d.status IN ('pending','approved'))`.)
3. **The run is spent on old enquiries.** `due()` (`FollowUpService.php:162-173`) is every open enquiry row with
   `due_at <= now`, earliest first. The scan opens only conversations quiet 1–14 days (`followup_scan.php:82-88`),
   but nothing closes a follow-up whose customer has been quiet longer, so 2,823 such rows sit ahead of the 695 whose
   customer wrote in the last week. With 2 a pass, a fresh enquiry waits days to be judged.
4. **The loop that F1 would create.** After any close, a conversation still quiet 1–14 days is opened again by the next
   scan (`followup_scan.php:75-85`: "no OPEN follow-up"), due at once. Claim mode's `send_failed` already loops this
   way; so would F1's.

## C. What 5.18.106 does — five changes, Uganda only

Every change is gated on `StaffJobsGate::applies($config, $dataDir)` (the sender's `$fsUg`), so South Sudan is byte for
byte unchanged (L01–L03 compare its four jobs with 221c9dc). Every new SQL that names `kind` is gated by `hasKind()`
(L03 runs a book without the column). No migration, no new setting, no new screen.

### F1 — a draft Evolution keeps refusing is closed, not retried for ever (sender)

- **Where:** `followup_send.php`, after the ledger block (`:275`) and before `sendText` (`:277`), so `$fsMode` is final
  and nothing is sent or recorded: on Uganda, for an enquiry draft not in claim mode, when the follow-up's refusals since
  the draft's approval number three or more **and** its channel delivered another message after the first of them →
  `close('send_failed', <F1's detail>, 'sender')`, `$fsCount('enquiry_tries_exhausted')`, next draft.
- **What counts — a refusal of this message, not of the number.** `failed` events of the follow-up since the draft's
  approval whose detail is Evolution's answer to the send itself, `... [HTTP 400 on POST /message/sendText/...]`, and not
  an HTML page (`Got an HTML page%`, which `requestUg` writes in the same form, `EvolutionApiService.php:886`). Other
  4xx are left out: a changed key (401), a renamed instance (404) or a proxy's 429 is the whole number's problem.
- **The positive control.** A `400` can still be the number's: the suite itself models
  `instance not connected [HTTP 400 on POST /message/sendText/x]` (`tests/test_notify_evo_retry.php:194`), and the
  report shows no send since the jam began. So the three refusals count only if **the same channel delivered another
  outbound message after the first of them** — a `wa_messages` row with `direction = 'out'` and a `wa_message_id`, on a
  conversation of that channel, `sent_at` later (the `(direction, sent_at)` index). A disconnected number delivers
  nothing, so its drafts are never closed by F1: they wait, as today, and F2/F3 expire the stale ones.
- **New methods:** `FollowUpService::refusals(int $followupId, string $sinceUtc): array` — the count and the first time;
  `FollowUpService::channelDeliveredSince(string $channel, string $sinceUtc): bool`. Each returns "none" on any error
  (an error never closes a follow-up). The ledger cannot be used: record mode keeps one row per ref (`INSERT OR IGNORE`,
  try 1).
- **The detail is not claim mode's:** `'Evolution refused it three times (HTTP 400) while the number delivered other
  messages'` — fixed text; QM26b anchors claim mode's `three tries did not send it` line, which must stay unique.
- The ten measured drafts (refused about 38,000 times each, all `400` on the sales number) close on their first pass after
  the deploy if the sales number has delivered anything since their first refusal; F2 expires them first in any case
  (their customers last wrote more than 30 days ago).
- (Claim mode is not changed: its `tries_exhausted` still counts every not_sent, as 5.18.104 decided; F4 gives such a
  close one second chance.)
- `recordFailure()`'s comment is corrected; its behaviour is not changed.

### F2 — a stale draft is never sent (sender)

- **Where:** `followup_send.php` between the `replied` check (`:169-173`) and the window (`:174`): it clears outside
  sending hours too, before any fence, policy, budget or claim.
- **Rule:** `$fsUg && $dKind === 'enquiry'` and the conversation's `last_customer_at` is older than the scan's own limit,
  read exactly as the scan reads it (`(float)($config['followup_max_age_hours'] ?? 336)`, `(int)($maxAge * 3600)`) and
  measured on **the run's clock** (`$fsClock`, which tests inject; at `time()` nine G cases and KC10, whose fixtures are
  334 h old at their injected time, would expire). → `close('expired', 'the customer last wrote more than N hours ago',
  'sender')`, `$fsCount('enquiry_expired')`.
- A setting that is not a positive number disables F2 (it never expires on a misread; the scan would open nothing then).

### F3 — stale follow-ups are expired, and never take the run's places (close and run)

- **The run** (`due()`): an optional `?string $staleBeforeUtc = null`; when given, `AND last_customer_at >= ?` (the row's
  own copy — the conversation's is never earlier, and a row whose customer wrote again belongs to close's `replied`).
  The run passes it on Uganda; `followup_doctor.php` and H02 keep today's query. The existing `$kind` line stays byte
  for byte (QM14/QM47 anchor it).
- **The close job:** a separate pass **after** its loop (H05 needs CASE C first), with its own clock (close never sets
  `$now`): up to 200 open enquiry rows whose row **and** conversation `last_customer_at` are older than the limit
  (`LEFT JOIN wa_conversations`, oldest first) → `close('expired', 'the customer last wrote at <time>, older than
  followup_max_age_hours N h', 'closer')`. 200 a pass: the 2,823 measured rows clear in about 15 passes (two and a half
  hours), and with them every approved draft of the 40 stale follow-ups (closed rows leave `approvedDrafts`).
- Prints ` expired=N` beside `closed=` only when non-zero.

### F4 — one follow-up per quiet spell (scan)

- Appended to `$holdSql` (it takes no argument, like `$quoteSql`, so the argument order is unchanged):
  `AND NOT EXISTS (SELECT 1 FROM followups p WHERE p.conversation_id = c.id AND p.last_customer_at >= c.last_customer_at
  [AND COALESCE(p.kind,'enquiry')='enquiry'])` — a follow-up already opened for this spell, open or closed, keeps the
  conversation out. It is the exact complement of close's CASE C (`$lastCust > $fu['last_customer_at']` = a new spell),
  and compares two copies of the same string (not `opened_at`, which a late-stored inbound message can precede).
- Closes then end a spell's follow-ups: exhausted, not interested, escalated, vetoed, the screen's staff_cancelled
  (undone ten minutes later today). A new customer message starts a new spell.
- **One second chance after `send_failed`.** Claim mode's `tries_exhausted` counts every not_sent, outages included,
  and before 5.18.106 the scan reopened such a close. So the clause allows one more follow-up in a spell whose follow-ups
  so far are a single one closed `send_failed`: `NOT EXISTS (... AND COALESCE(p.close_reason, '') <> 'send_failed')
  AND (SELECT COUNT(*) FROM followups p2 WHERE p2.conversation_id = c.id AND p2.last_customer_at >= c.last_customer_at
  [kind]) < 2`. Two follow-ups a spell at most; the loop ends.
- **Performance is UNMEASURED.** `followups(conversation_id)` has only a partial index (`closed_at IS NULL`). The build
  measures the scan on a book of production size (EXPLAIN QUERY PLAN and timing); only if it is slow does 5.18.106 add a
  migration (`CREATE INDEX … ON followups(conversation_id, last_customer_at)`).

### F5 — one approved draft per follow-up (run and sender)

- **The run** (`due()`, appended **only when F3's `$staleBeforeUtc` is given** — the Uganda run; H02, QM14,
  `followup_doctor.php` and South Sudan keep today's query): `AND NOT EXISTS (SELECT 1 FROM followup_drafts d WHERE
  d.followup_id = followups.id AND (d.status IN ('pending', 'approved') OR (d.status = 'uncertain' AND d.attempt =
  followups.attempts + 1)))`.
  - `approved`: a follow-up waiting for the sender is not judged again (QuoteChase's guard, `QuoteChase.php:214`).
  - `pending`: a draft waiting for a person no longer takes one of the run's places (`followup_run.php:104` stays as the
    backstop) — two account-level drafts that `mayAutoSend` refuses no longer stop the run.
  - `uncertain` of the current attempt: `recordUncertain` moves no `due_at`, so today the next pass drafts that attempt
    again — in record mode a second message minutes after one that may have arrived (5.18.54's case, open since). The
    follow-up now waits for a reply, F3's expiry or a person.
- **The sender:** a follow-up is handled once a pass (the first of its drafts in queue order; the others are skipped
  this pass); after a send, the follow-up's other approved drafts become `expired` (the status migration 070 documents,
  used today by quote drafts only) with one `skipped` event, `'other approved drafts expired: N'`, actor `sender`. F1 and
  F2 close the follow-up, which takes all of its drafts out of the list at once.

## D. The order of a sender pass after 5.18.106 (Uganda, enquiry drafts)

no message → conversation gone → colleague → owned hold → opt-out → a person has it → replied → **F2 expired** → window →
quote checks → fence → registry → policy → ledger (claim: tries_exhausted) → **F1 refused three times** → send →
success (**F5 siblings expired**) / failure.

## E. What the owner sees after the deploy

- Within the first close passes (10 minutes each): the stale follow-ups close as `expired` — about 2,800 — and with
  them the 1,979 stale approved drafts. Nothing is sent to any of those customers.
- On the first sending pass: the ten refused drafts close `send_failed` (if F2 has not already expired them — they are
  all more than 30 days old, so it will).
- Then the run judges the recent follow-ups (customers quiet 1–14 days), one approved draft each, and the sender sends
  them — about `followup_daily_cap` (3) a number a day: the run stops judging a number once that many were sent today,
  but it counts finished sends only, so drafts approved before the morning's first send can take a number a few over.
- F1 closes a draft only while its number delivers other messages; with the number down, drafts wait and the stale ones
  expire.
- The report (run again) shows `expired` and `send_failed` among the closes; its section C, D and J readings change.

## F. The owner's settings after the deploy (not code)

1. **Raise `followup_daily_cap`** — 3 sends a number a day is far below the enquiries arriving (about 100 opened a
   day). The send ledger paces each number once claim mode is on.
2. **Then turn on step 6 (`on chase`).** It turns on `quote_chase_enabled`, which also switches enquiry sends to the
   ledger's claim mode: at most 60 proactive messages a number a day, 15 an hour, one per customer a day.
3. Consider raising `followup_run_limit` (2) once sends flow.

**Until 5.18.106 is deployed, none of the three switches that make enquiry sends claim may come on** —
`quote_chase_enabled` (step 6), `offers_test_enabled`, `offers_live_enabled` (`WaSendBudget.php:173`). With any of them
on today, claim mode would close the ten refused drafts after three tries each and then start sending the stale backlog
from the sales number — month-old drafts, up to two per customer. The deploy checks all three are off before it starts.

## G. Decisions taken here (the owner may overrule)

| | decision | why |
|---|---|---|
| G1 | Uganda only; South Sudan unchanged | convention; L01–L03 |
| G2 | F1 counts only `400` answers to sendText (no HTML page), and only while the number delivers other messages | an outage, a changed key or a disconnected number must not close follow-ups; the measured case is `400` |
| G3 | F1 reuses the reason `send_failed` with its own fixed detail; counter `enquiry_tries_exhausted` | one reason for one outcome; QM26b keeps claim mode's line unique; G03 forbids a `ledger_` counter |
| G4 | the age limit is the scan's own `followup_max_age_hours` | one definition of "too old" |
| G5 | a misread limit (not a positive number) disables F2 and F3 | never expire on a misread |
| G6 | closes leave drafts `approved` (as every close does); only F5's siblings become `expired` | the closed row takes them out of every list |
| G7 | F4 by `last_customer_at`, not `opened_at`; one second follow-up after a single `send_failed` | complement of CASE C; a late-stored message; claim mode's outages |
| G8 | no migration unless the build measures the scan slow | 5.18.105 shipped none |
| G9 | F5 also keeps out a pending draft and an uncertain draft of the current attempt | the run's places; 5.18.54's second message |

## H. Known limits (recorded, not fixed here)

- `followup_close` still looks at only the 200 earliest-due rows for a reply (CASE C); F3 cuts the open rows from 3,939
  to about 1,100, which the window reaches sooner.
- Record mode's ledger keeps one row per ref, so a draft that fails and then succeeds loses its send from the ledger
  (5.18.104, §H.2.5.5). F1 makes it rarer.
- The Follow-ups screen still lists a pending draft of a closed follow-up until Reject.

## I. Deploy and rollback (for `deploy-5.18.106.sh`)

- **Before:** the three claim switches are off (§F); report, read-only, the last outbound delivery (`wa_messages`
  direction out with a `wa_message_id`) per channel — F1's positive control — and the counts §A measured.
- **Rollback to 5.18.105 switches `followup_auto_send` off first.** The jam has been hiding 5.18.105's re-drafting: once
  F1–F3 clear the head, a rollback would bring back a run that drafts a follow-up whose draft is waiting, and a sender
  that sends both (a draft held overnight by the window, a second one approved at 08:00, both sent in the same pass).
  With automatic approval off, a person approves each draft.
- **After:** the first close passes expire the stale rows; the report, run again, shows `expired` and `send_failed`
  among the closes and the sender's front moving.

## J. The build (after B2, Monday 16 Nov)

On `release/5.18.106` from 7835906, as one commit; then the 5.18.104/105 pattern: an `r106` layer (the N05 marker rule,
TL14's added-line config reads — `followup_max_age_hours` is not in `SalesFollowupLadder::PREEXISTING` — and log_mask
D/E), re-pointing H03 and H05 (wall-clock fixtures now older than 336 h) and G04 (assert `ledger_tries_exhausted=1` so
QM26b still means something), new cases for F1–F5 with weakened copies, the suite twice, `deploy-5.18.106.sh` derived
from 5.18.105's, a rehearsal chained on 5.18.105's, and the docs/07 entry. The read-only report is updated to mirror
F3/F5's `due()` after the deploy.

## K. The build (15 Nov 2026, before B2): what was measured, and where it departs from this design

Built on `/home/user/b106` from 7835906, not yet committed (§J's order holds: the release suite and the deploy script
come after B2). Every change below is in the release's `SAFETY.md` 5.18.106 section.

**Measured — the scan (G8).** One scan query on synthetic books (conversations / quiet 1–14 days / follow-ups):

| book | 5.18.105 | + F4 | + F4 + index |
|---|---|---|---|
| 8,000 / 800 / 6,000 | 0.37 s | 0.67 s | 0.48 s |
| 15,000 / 1,500 / 10,000 | 0.97 s | 2.1 s | 0.86 s |
| 30,000 / 3,000 / 20,000 | 3.6 s | 7.5 s | 3.8 s |

F4 roughly doubles the scan; with `followups(conversation_id, last_customer_at)` it costs nothing. Not added: the scan
runs once in ten minutes inside master's 300-second cycle, so at production's size F4 adds about a second to one read.
5.18.104's quote clause already scans `followups` per candidate (by phone) — the pre-existing cost. Recorded as a known
limit; the index is one migration if the scan is ever slow.

**Departures, each from a review of the build (two read-only reviewers) or a failing test, each with its case:**

1. **F1 counts a refusal only when the number delivered between it and the refusal before** (for the first, since the
   approval) — not "a delivery after the first of them" (§C F1). As designed, an outage of three passes or more, then the
   number's recovery, would have closed every follow-up the outage passes tried, untried while the number was up (review
   R106-RT-1). `refusals()` and `channelDeliveredSince()` became one walk, `refusedWhileDelivering()`, which reads both
   lists in time order and stops at three. U04b is the outage; U10 (a) the outage through the real sender.
2. **F5's `due()` keeps a follow-up holding a pending draft** (G9 also named pending). `tests/test_staff_whatsapp.php`
   (5.18.50's M1) failed: the run's colleague, gone and owned-number closes come before its own pending skip, and leaving
   the row out of `due()` took them away. The run never drafts again behind a pending draft (it skips it), so only the
   run's places were at stake; that stays as before, recorded as a limit. Approved and the current attempt's uncertain
   drafts are still left out — the re-drafting this design found.
3. **The sender never sends a draft whose attempt is taken** — a send recorded for it, or a sibling sent or settled
   uncertain — and expires it (`enquiry_attempt_taken`). The after-send expiry alone left two gaps (review R106-RT-3): an
   expiry that fails silently, and the may-have-been-sent path, which expires no sibling (5.18.54's case, G9's aim).
4. **Close texts as §C wrote them**: F2 "the customer last wrote more than N hours ago"; F3 "the customer last wrote at
   <time>, older than followup_max_age_hours N h"; N as configured, never truncated (review R106-RT-5).
5. **Recorded, not changed:** a number that is down still holds the sender's ten places outside claim mode until F2/F3
   expire its customers (review R106-RT-2; as before 5.18.106). F5 stays on under a misread limit (review R106-RT-4; G5
   names F2 and F3 only).
6. **From the second review (of these fixes):** F1 places a delivery by `created_at` (when `storeMessage` stored it, UTC;
   the only writer of `wa_messages`), never `sent_at` — the Inbox's media sends write `sent_at` in Kampala time
   (`HumanTakeover::LOCAL_EVENTS`) and an import carries WhatsApp's own time, and either would put a delivery inside an
   outage; `sent_at` only bounds the read to its index, a day early. Refusals stream in id order and deliveries are read
   only when there is a refusal. The attempt check also reads `followup_sends` (`recordSend` writes it first, so a send
   whose later bookkeeping failed still counts). Recorded, not changed: a number that flaps — down at each of three
   passes, up between them — can still close a follow-up never tried while up; it needs an outage timed to every pass,
   and F4 gives one second chance. Each with its case (U04c, U05b, U06c–e, U12b iv–v) and its weakened copy.

**Tests.** Section U of `tests/test_quote_followup.php` (`tests/quote_followup/U_unjam.php`): F1–F5 through the real crons,
South Sudan beside each, the 5.18.106 boundary against 7835906 from Git (U20–U23: the declared list, every hunk marked,
the held files, and the layer rebuilding 7835906 file for file), and the added runtime lines under the PHP 7.4 rules
with no new setting (U24). `tests/quote_followup/mutants_U.php`: 67 weakened copies. `tests/fixtures/r106_revert.php` takes
5.18.106 out before the 5.18.105 layer, so every older boundary still judges 7835906's tree. Found while running the
affected files: `tests/test_wa_send_budget.php` HK11 fails on any Kampala Sunday, on 5.18.105 too — its claim moment is
today at 10:00 and the ledger's window is closed on Sundays (`WaSendBudget::inWindow`); B1 ran on a Saturday and B2 runs
on a Monday, so neither meets it. 5.18.104's test, recorded, not changed here. `tests/test_sales_followup_tool.php` CK12 failed the same way on Sunday 15 November at 20:28 UTC (*the fixture's claim was
not ok: window*, its claim taken through the ledger on the real clock), on 5.18.106's tree; it passed in B1 on the Saturday.
Not run on 5.18.105 on a Sunday, so its pre-existence is inferred from the refusal reason, not shown; the suite passes on a
weekday decide it.

**Run on the final code, Sunday 15 November** (one PHP tree at a time; exit status 0 for each): section U 85 passed, 0
failed; its 67 weakened copies all caught (152 passed, 0 failed); all of `test_quote_followup.php` 605 passed, 0 failed;
section N's weakened copies 37 passed, 0 failed.

**The affected files, rerun on the final code (r3, Sunday 15 November 22:17–23:22 UTC)**, one at a time, each with its
own vault: 24 of 27 exit 0 (HK11 passed: Kampala was already Monday). Three exit 1, none of them 5.18.106's:

- `tests/test_cashbook_physical_list.php` (5.18.98's): one control in section I, *the readers were run, and both the
  maintenance and the daily-report reads hold cash and not-cash rows*, got `[true,["cash","noncash"],[],true]`. Its reader
  (`PL_READER`) asks `date()` for *yesterday* inside the sandbox while the book's rows are dated in Kampala time, and
  between 21:00 and 24:00 UTC Kampala's date is UTC's plus one, so the daily report reads a day the book holds no rows
  for. **Shown, not inferred:** the same file on an unchanged checkout of 7835906 at 23:24 UTC failed the same control
  with the same values (495 passed, 1 failed). On 5.18.106's tree it passed at 19:42 and 20:19 UTC (r1, r2), and in B1
  it ran between 12:09 and 13:07 UTC (its 35th file) and passed. 5.18.106 changes only its version line. Recorded, not
  changed here.
- `tests/test_cashbook_physical_list_mutants.php`: its control runs the file above first and fails with it, so no copy
  was judged. It is rerun in the suite passes.
- `tests/test_migration_integrity.php`, *while the run that trimmed it is still recorded*: one of B1's seven
  container-only failures on 7835906, unchanged.

The reading rule for 5.18.105's B2 and for 5.18.106's suite passes: a failure of that control in a file that ran between
21:00 and 24:00 UTC is the clock, not the release. B2 starts at 00:00 UTC, and B1 reached the file within 1 h 20 min of
its start.

**Committed** as `release/5.18.106` = `bf29cbd` (one commit on 7835906, 23 files, local only; not pushed without the
operator's word). §J's order holds from here: the release suite twice after B2, then `deploy-5.18.106.sh`, its
rehearsal, and the docs/07 entry.

## L. `scripts/deploy-5.18.106.sh` — the design (16 Nov, before any derivation; revised after review)

Written while 5.18.105's pass B2 runs (no PHP beside it), from two read-only maps of `scripts/deploy-5.18.105.sh` against
the 5.18.106 delta, then reviewed by two read-only reviewers (facts against the code; design and safety): 43 findings,
one blocker (the rollback write's precondition), every one applied below. Nothing here is derived, run or deployed.
`S105:n` is a line of 5.18.105's script as committed in `2a41c9d`. The owner may overrule any decision in §L.9.

### L.1 How it is made

- **Derived from `scripts/deploy-5.18.105.sh` exactly as committed** (14,745 lines, sha256 `c70820cd69af899b…`) by asserted
  edits (`wip/derive-5.18.106/`): every edit asserts its anchor, its count, the number of replacements and its result,
  and any failure exits non-zero and writes nothing. `d105lib.py` is carried as `d106lib.py`; the driver takes the pin as
  a parameter and refuses one whose manifest does not say 5.18.106 or that is not one commit on `7835906`. Derived twice,
  byte for byte the same; `bash -n` on the result.
- **Most of 5.18.105's derivation cannot be re-run**: its edits transformed 5.18.104's text, and those anchors are gone.
  Its Git assertions (lists, master, signatures, `fromMe`, `AC_L1_SUMS`, the LM hunks, the report wrapper's seven pins) are
  reused; its edits are not.
- **The whole-text checks, each with weakened copies it must refuse for its own reason**: K1 (no clear or by-hand
  put-back; the switch tool only `show`, `--check` or `doctor`; `$QF_WRAPPER` only on echo lines — CS-G1's remedies are
  printed as literal text on echo lines); K2 (`lk_ca1` defined with both verdicts, called once, from `qf_after`); K4 (the
  never-fell count without `list_last:%`); K3 carried, its weakened copy re-anchored on V1's new line; and a new **K5**
  (§L.7): exactly one function writes a key, called from the typed rollback and from V1 and nowhere else, one key, one
  value, through `saveOverrides`, as `-u "$DB_OWNER"`, before `do_rollback`. K5's weakened copies: a second call site; a
  second key; a write without `-u "$DB_OWNER"`; the write placed after `do_rollback`.

### L.2 Identity, lists and carried constants

- **The pin** `bf29cbd` (short and full, from Git), version 5.18.106, branch `release/5.18.106` (pushed only on the owner's
  word); **the baseline** `7835906`, 5.18.105, live; **the run's folder** `/root/dnb-5.18.106`.
- **The lists, from Git, never typed**: the kept files (5.18.105's 386 kept plus its 89 delta, less 5.18.106's 20 changed:
  **455**), at `7835906`'s sha256 and asserted equal at the pin; the **20** changed (7 from 5.18.105's kept list, 13 from
  its delta), at `7835906`'s sha256 (what must be live before and what a rollback puts back); the **23** of the delta at the
  pin (20 changed, 3 added, the added all tests). The derivation asserts 5.18.105's own counts (386, 89, 46) as its
  starting point, so a different source is refused.
- **No migration**: A0 refuses a pin that carries one; 089–091 held at the pin by sha256 and md5, as before.
- **Unchanged and re-asserted from Git**: `cron/master.php` (`b59e7ce5…`, both pins), the event processor's signatures,
  `NotificationService`, the ladder's three files, and T1's and T4's files. **T1's count, 548, is a run count, not a Git
  fact**: it is confirmed by 5.18.106's two release-suite passes before the script is pinned.
- **The PREV chain shifts by one**, and a ninth entry (5.18.97) is added so that its quarantine record is not dropped —
  the reason 5.18.105 added an eighth. A14's loop and its three messages follow.
- **`COPY_MARK` moves to `cron/followup_send.php`**: `evo_webhook.php` is not in 5.18.106's delta, and `stamp_copy` stamps
  only the delta, so the copy's time would be read from a file it never stamped.
- **QC-RB1's words** become `r106_revert`, `U_unjam` and `mutants_U` (none named at `7835906`); its control is the pin's
  `tests/fixtures/r106_revert.php`, its line count read off Git by the derivation and pinned. Its ok line becomes *no file
  `7835906` tracks names one*, with a note: `7835906`'s `tests/test_quote_followup.php` loads `U_unjam.php` and
  `mutants_U.php` by glob — tests only; no runtime file and no server-run test (T1, T4) reaches them. Every *reached by
  nothing* text follows (S105:494, 555, 14458).
- **The two reports** (DL7): `scripts/sales-followup-report.sh` (P1) stays pinned to `7835906` — the report,
  `lib/QuoteSendEvidence.php` and its five installed files are unchanged at `bf29cbd`, each asserted from Git, and the
  summary's sentence that it is pinned to the deploying release is reworded. `scripts/enquiry-followup-report.php` still
  runs (its two pinned files are unchanged), but its section E front mirrors 5.18.105's `due()` and misreads after the
  copy until it is updated; the summary says so beside its command, and UJ-CA and UJ-ON (§L.5) take the place of §I's
  *after* reading.

### L.3 New — the three claim switches must be off (CS-G1, CS-B1)

docs/75 §F: until 5.18.106 serves, none of `quote_chase_enabled` (step 6), `offers_test_enabled` (step 11) and
`offers_live_enabled` (step 12) may be on, since each puts enquiry sends into the ledger's claim mode.

- **CS-G1** (stage A, and so `--check`): **NO-GO if any of the three keys is present, with any value**, in
  `<data>/kyc_config.json` or its store row. In `<plugin>/data/config.json` or `<data>/config.json` LK-G1's held rule
  already refuses first (any `allKeys()` key there), and the rehearsal asserts that too. CS-G1 reads LK-G1's own map; no
  new reader.
- **Its message** names the keys and places, never a value, and prints one command per present switch, each alone on its
  line with its own log, highest step first (`off offers-live`, `off offers-test`, `off chase`), with what each also does:
  `off offers-test` turns `offers-live` off too and pauses a running offer; `off chase` turns `cold` (step 10) off too.
  Then `--check` again. For a key held in a `config.json`, LK-G1's own remedy (remove it by hand) stands.
- **Why presence, not truth.** The readers disagree: `enquiryClaims` and master's flag rule cast to a string (a float 1.0
  reads on there, off in the ladder's `truthy()` and `OfferSwitch::on`), and `navigation.php` gates on `!empty()` before
  `OfferSwitch` decides. `off <step>` unsets the key in file and store, so absent is the one state every reader agrees on.
  No production writer leaves a claim key present but off: the tool's `on` writes `'1'`, `set_config.php` refuses these
  keys, no screen and no manifest entry writes them.
- **CS-B1**: the same on LK-B1's map, after `DEPLOY` is typed and before the copy.
- **LK-G1's two notes go** — the capitals note on `offers_live_enabled` and the plain note on `quote_chase_enabled`
  (S105:7038-7039), and their description in `lk_g1`'s comment (S105:7025-7026): they said the opposite of §F. Every
  other LK check is carried unchanged.
- In `--after-only` a step the owner turned on after the copy is LK-AO's note, as before: by then 5.18.106 serves.
- The script still runs no switch-tool verb.

### L.4 New — the readings before the deploy (UJ, notes only)

A new `QF_PHP` mode, on the copy of the book QF already makes (`VACUUM INTO`, opened read-only), through the pin's code,
printing counts, UTC times and generic channel labels (`sales`, `support`, `account`, `retailer`, `-N` suffixes, else
`other`) — never a phone, a message, an instance name or an event's detail. **In the deploy run its values go to the state
file (`UJ_A`)** for `--after-only` to compare; **`--check` prints them and writes nothing**. None refuses.

- **U1, F1's positive control**: per channel, the last outbound delivery (`wa_messages` direction out with a
  `wa_message_id`, placed by `created_at` as F1 places it) and the count in the last 24 h; a channel with none in 30 days
  says so. Each of the sender's ten places, in the sender's order, classed *F2 expires (stale)* / *F1 closes* / *waits*,
  with the ledger's mode (claim mode leaves F1 out).
- **U2, §A's counts**: the last enquiry send and sends in 7 days; approved drafts and the follow-ups holding them, split
  by the customer's last message against the age limit (the stale part is what F2 expires; the fresh part bounds what the
  sender can send in its first passes); the ten places with their failures since approval; failed sends in 7 days,
  F1-countable or not; open enquiry follow-ups by age bucket; due now, under 5.18.105's `due()` and the pin's.
- **U3, forecasts**: F3's count by `expireStale`'s own predicate, the passes it takes at 200 a pass, and **for the first
  200 rows in `expireStale`'s order (oldest first) how many expire and how many close `cancelled`** (a gone conversation);
  F5's follow-ups holding two or more approved drafts.
- **U4, the settings as the jobs read them**: `followup_enabled`, `followup_auto_send` (and its presence per place),
  `followup_daily_cap`, `followup_run_limit`, `followup_scan_limit`, `followup_max_age_hours` (and whether F2 and F3 are
  active: a limit that is not a positive number disables them), the ledger's mode, `StaffJobsGate::applies()` (if false,
  F1–F5 are inert, said in capitals), and **whether a rollback could write `followup_auto_send`** (§L.7's precondition,
  key names only).
- **The copy's settings carry three more keys**: `followup_max_age_hours`, `followup_run_limit` and `followup_scan_limit`
  join `conf`'s keep list (S105:6222-6240), or every reading would see their defaults. None is a secret or a vault key.
- **Closes are permanent** (`fu_closed_is_history`, migration 070): each leaves its row and its event, but nothing
  reopens it, so U2 and U3 are the only record of the state before the first passes and of what they were expected to
  close. Stage A prints them before `DEPLOY` is asked.

### L.5 New — after the copy and in `--after-only`

- **R1 and Q-CA1** re-pinned: the 23 files at the pin's sha256, the 455 kept at `7835906`'s, `php -l` on the runtime
  files, the manifest at 5.18.106. LK-CA1 (equal to LK-B1) implies the three claim switches are still absent.
- **ME-CA1, master's errors from the straddling cycle** (counts only, never a line or a message). Its positive control is
  at least one `[master] RUN followup_` line after the copy in the same file; without it ME-CA1 reads NOT ESTABLISHED,
  never PASS (master's output reaches `data/plugin.log` only as master is driven there). Stamps are read in the plugin's
  own zone (master writes local time). A `[master] ERROR followup_(close|scan|run|send)` line before the first
  `[master] Cycle complete` stamped after the copy belongs to the straddling cycle: a note. One after it is a FAIL. (A new
  `followup_send` or `followup_run` with the old class loaded throws before it sends or judges; a new `followup_close`
  throws after its CASE closes.) The same in `--after-only`.
- **UJ-CA (notes)**: U1–U3 again, beside stage A's.
- **UJ-ON (judged, `--after-only` only).** Judged only when, at stage A and now, `followup_enabled` is on,
  `StaffJobsGate::applies()` reads Uganda and the age limit is a positive number; otherwise a note naming which condition
  held. Its positive control is `followup_close`'s schedule row with a run begun and ended after the copy.
  - **PASS** when at least one enquiry follow-up closed `expired` since the copy by the actor `closer`, its `closed`
    event's detail beginning `expired — the customer last wrote at ` (an enquiry row: `COALESCE(kind,'enquiry')='enquiry'`).
    Quote follow-ups have closed `expired` since 5.18.104 through `QuoteTracker::STATUS_TO_CLOSE`, with other actors;
    kind, actor and detail together single out F3.
  - **FAIL** when the positive control holds, none closed `expired` by `closer`, fewer than 200 closed `cancelled` by
    `closer` since the copy (so the cap does not explain it), and the book still holds an open enquiry row, its
    conversation present, that `expireStale`'s predicate selects at the start time of that close run.
  - **NOT YET** otherwise, with the counts (cancelled by `closer`, closed `replied` since stage A, still stale).
  - **NOT YET keeps `--after-only` from reading PASSED**; the summary then says when to run it again, and prints the
    owner's §F steps only after a PASSED `--after-only`.
- **The other 5.18.106 readings are notes**: F2's closes (enquiry, actor `sender`, detail beginning `expired — the
  customer last wrote more than `) and F5's attempt-taken expiries come at any hour, but only for drafts in the sender's
  ten places; F1's closes (`send_failed — Evolution refused it three times (HTTP 400)`), the after-send sibling expiries,
  the sends and the front moving (U1's last outbound per channel and the last enquiry send against the copy) come only
  inside the sender's own window.

### L.6 Carried, re-pointed

- **LM becomes "5.18.105's masking still holds"**: none of 5.18.106's 23 files holds a masked block, and all 21 files
  of `LM_HUNKS` (and `lib/SalesFollowupChecks.php`) are byte for byte the same at `7835906` and the pin. `LM_HUNKS` is kept
  byte for byte and asserted equal to Git's `994d660`→`7835906` diff. LM-G1's control is fixed to `994d660`, not
  `$BASELINE_COMMIT` (at `7835906` the masked lines are already in place, so the carried control would refuse); the masked
  form is required at the pin, at the baseline and as installed. **LM-RB1 is inverted**: after a rollback to `7835906` the
  masked form must still be there. **Every text saying the masked lines write numbers again after a rollback goes**
  (S105:486, 492, 553, 7475, 7768, 7797-7800, 11575, 12601, 14458, 14728-14729); partZ asserts that no *numbers again*,
  *NUMBERS AGAIN* or *WRITES PHONE* text survives. LM-G2, LM-CA1, LM-CA2 and LM-ON are carried.
- **The whole LK family** is carried: the ladder is kept as found.
- **QF, VN, VA, OF, AC, QC** are carried. Where a text uses `$BASELINE_VERSION` for 089–091's origin it becomes the literal
  5.18.104 — exactly S105:7492, 7495, 7625, 7626, 8527, 9185 (its second use), 9229, 9236 and 14638. Where it means the live
  release (S105:7567, 8472, 8492, 9163, 9164, 9181, 9185's first use) it stays. QF-G3's counts and HK7's send-site
  inventory are re-read at `7835906` and the pin (the delta adds no `sendText` call). QF-CA4's `due()` call is unchanged in
  meaning: the new parameters default to 5.18.105's query.
- **R7, V3g, BR1, A6, A14–A16, A22, T1/T4** carried. A14's dates in the header are worded by rule (the quarantine's
  `--check` begun after 5.18.105's copy) until 5.18.105's own copy is on record.
- **The rollback functions are renamed** `rb_pre106` and `rb106` (partZ's rules and the folder check follow).

### L.7 The rollback (RB106) — the one write in the script

docs/75 §I: a rollback to 5.18.105 switches `followup_auto_send` off first, because 5.18.105's run drafts a follow-up
whose draft is waiting and its sender sends both. With automatic approval off, a person approves each draft.

- **The writer and its precondition.** The only writer is the plugin's own,
  `PluginConfig::saveOverrides($dataDir, ['followup_auto_send' => '0'])`. It writes `<data>/kyc_config.json` and then
  mirrors **the whole file** into the store row (`array_merge($store, $file)`). Keys held only in the store row (screens
  and jobs write it directly) are kept by that merge and do not matter. Keys the file holds that the store lacks or holds
  differently would be overwritten in the store — and three writers put keys in the file alone (the Evolution
  credentials, the AI settings, the Evolution key's clearing), secrets among them. So **the write happens only if every
  key of the file other than `followup_auto_send` is present in the store row with the same JSON value, type included**,
  compared by keyed hash in memory; a mismatch is named by key, never valued. On production a mismatch is likely; then
  the hazard of §I is the owner's to remove, as below.
- **Before `ROLLBACK` is typed** (`rb_pre106`, read-only): LK-RB0 as before; `followup_auto_send` in each of the four
  places and its effective value; the precondition's result (key names only); F5's count, and when it is above 0, in
  capitals, that 5.18.105's sender will send each of those follow-ups' approved drafts in one pass; that 5.18.105's scan
  will open new follow-ups for recently quiet conversations whose follow-up 5.18.106 closed; and for each claim switch
  that is on, in capitals, that under 5.18.105 it means claim mode, with its own `off` command alone on its line.
  - **If the precondition fails, it says so before the question, in capitals**: *THE ROLLBACK CANNOT SET
    followup_auto_send OFF ITSELF (kyc_config.json and its store row disagree on <names>): 5.18.105 WILL THEN APPROVE AND
    SEND AUTOMATICALLY. To avoid that, answer anything but ROLLBACK — nothing has been changed — run your own command
    below, alone, then run the rollback again.* The command is printed full and runnable, alone, with its own log
    (`docker exec -u <the book's owner> <container> php <plugin>/tools/set_config.php --key followup_auto_send --value 0`,
    built from the script's own owner and container values), and before it, in capitals, that it also copies
    `kyc_config.json` into the store row, so <names> take the file's values there, and that it refreshes the vault (a
    later run's Z1 reads that) — the owner's decision.
- **AS-RB1, after `ROLLBACK` is typed and before `do_rollback`**, and in V1's automatic rollback before its `do_rollback`:
  - Run through the script's own owner-run PHP (`docker exec -u "$DB_OWNER"`, as `dxo`/`qf_php` do; `DB_OWNER` is read
    in every mode), loading `PluginConfig`, `SqliteStore` and `MigrationRunner` from the installed tree after their
    sha256 are checked against `7835906` (all three are kept files). **Never as root**: as root SQLite could leave a
    root-owned `-wal`/`-shm` beside the book (S105:11028's rule). If `DB_OWNER` is empty, nothing is written: a FAIL.
  - **The write opens the live book read-write** through `SqliteStore::create` (its WAL pragmas, `ensureIndexes()`, the
    migration runner applying nothing). R7 (the ledger the same, 091 last) is read right after it, on both paths.
  - **Write `'0'`, never clear**: the file wins in `PluginConfig::files()`, so a value held in a `config.json` cannot
    show through.
  - **Read back**: the file reads `'0'` and the key is effectively off through the installed `PluginConfig::read()`
    (`load()`'s files in `load()`'s order, without `ConfigVault::apply`) and `FILTER_VALIDATE_BOOLEAN` as
    `FollowUpPolicy::mayAutoSend` reads it — FAIL otherwise; every other key of the file and of the store row has the
    hash it had just before the write, each place compared whole; `<plugin>/data/config.json` and `<data>/config.json`
    unchanged; the LK map and V3g's keys unchanged. The store row's `'0'` is a note: the writer mirrors best effort, and
    no reader of `followup_auto_send` reads the store.
  - **No write** when the precondition fails, when the key is already off, or when the rollback has nothing to roll back
    (`RB_NOTHING`).
  - **A failed or skipped write never blocks the rollback** (5.18.105's rule, docs/74 §H.5.3): a FAIL in capitals, and
    the owner's command (above) printed after the verdict, alone; in V1, inside V1's own stop message, beside the
    rollback's outcome.
  - **No wait**: a `followup_run` pass that began before the write is 5.18.106's own, whose `due()` leaves out every
    follow-up holding an approved draft; a pass of 5.18.105's run can begin only after `do_rollback` and reads its
    configuration then.
  - If `do_rollback` fails after the write, 5.18.106 keeps serving with automatic approval off, and the run says so.
- **Then `do_rollback`** — `7835906` put back by the documented deploy alone — **and RB106, read-only**: the 20 changed
  files at `7835906`'s sha256, the 455 kept unchanged, master as before; LK-RB1 (keys, `show` and rows equal to LK-RB0);
  LM-RB1 inverted (§L.6); **AS-RB2**, `followup_auto_send` effectively off through `7835906`'s code (FAIL, unless AS-RB1
  recorded why it did not write); R7; V3g; QC-RB1 (§L.2); and every RB check docs/74 §H.5.4 carries (the event
  processor's signatures, QC-RB2 counted again, the sign-off, the owner rule, ECO Bank, the documents, the quarantine,
  the VN-RB5, OF-RB2 and AC-RB2 notes reworded to `7835906`).
- **What stays**: every close the release made — nothing reopens them; the drafts it set `expired`; the ladder as found;
  `followup_auto_send` off. **What comes back**: 5.18.105's sender and run, with automatic approval off. After a later
  `--redeploy` it stays off: turning it on again is the owner's own command, alone, only once 5.18.106 serves and its
  `--after-only` has PASSED.
- **The texts that said the script never writes a key or opens the live book read-write** are reworded to name this one
  write, once: S105:102-103, 427-436, 487-491, 552-566, 585-588, 7757-7764, 12566, 14458 and 14726-14730. K5 asserts their
  new wording.

### L.8 The header's rules for the operator

- **5.18.105 must be live, its `--after-only` PASSED**, and the quarantine's `--check` begun after its copy.
- **The three claim switches off** (CS-G1 refuses otherwise).
- Carried: not between 14:55 and 15:10 UTC; not while the morning list is being sent (05:00–08:00 UTC, Monday to
  Saturday); no `scripts/sales-followup.sh` while the deploy, its `--after-only` or its rollback runs; the deploy and its
  rollback never in one copyable block; every printed later command carries its own log on its line.
- **After a PASSED `--after-only`, the owner's own steps (§F), each its own command, never run by the script**: raise
  `followup_daily_cap`; then step 6 (`on chase`); later, consider `followup_run_limit`. The summary prints them, one per
  line.
- `--after-only` at least 20 minutes after the copy, again after a close pass has run (UJ-ON needs one), and the next day
  inside the send window.

### L.9 Decisions (the owner may overrule)

| | decision | why |
|---|---|---|
| DL1 | CS-G1 refuses on any value present, not only a true one | the readers disagree on truth; absent is the one state they agree on; no writer leaves a claim key present but off |
| DL2 | the readings of §L.4 are notes, never a refusal | §I calls them a report; they are the record of the state before the permanent closes |
| DL3 | UJ-ON is judged in `--after-only`, with PASS/FAIL/NOT YET as §L.5 defines them, and NOT YET keeps `--after-only` from PASSED | an F3 that does nothing on a book with stale rows is the failure that matters most; the owner's claim steps wait for proof |
| DL4 | the rollback writes `followup_auto_send` `'0'` in both the typed rollback and V1's automatic one | the hazard of §I does not depend on how the rollback started |
| DL5 | the write only when every file key but this one holds the same value in the store row; otherwise the owner is told before `ROLLBACK`, with their own command and what it overwrites | the plugin's writer mirrors the whole file; file-only keys, secrets among them, would be copied silently |
| DL6 | a failed or skipped write never blocks the rollback | 5.18.105's rule (docs/74 §H.5.3) |
| DL7 | `sales-followup-report.sh` stays pinned to `7835906`; the enquiry report's section E is updated later, and UJ-CA/UJ-ON replace §I's *after* reading meanwhile | unchanged inputs; the deploy's pinned set stays small |
| DL8 | a `--redeploy` of 5.18.106 after a rollback is refused by CS-G1 while a claim switch is on | §F, strictly. The trade: 5.18.105 then serves in claim mode until the owner turns the step off — `rb_pre106` already printed that command — and `off` ends claim mode at once |
| DL9 | LM keeps its codes; the rollback functions are renamed `rb_pre106`, `rb106` | the codes name checks the owner has read; the functions name the release they roll back |

### L.10 The rehearsal

Chained on 5.18.105's (`scripts/harness/deploy-5.18.105/rehearse.sh`), read up to its last section. **5.18.105's
rehearsal ends at L2 (steps 1–6 on, offers off) with `994d660` serving after its last rollback** (105-7e, 105-10j). So
5.18.106's sections first redeploy 5.18.105 (`--redeploy`, PASSED), run its `--after-only` and the quarantine's `--check`,
then turn step 6 off through the real tool — steps 1–5 on, production's state. Then:

- **the jam, planted through the real writers**: approved drafts Evolution refuses with 400 while the number delivers
  other messages; stale follow-ups, the oldest of them gone conversations (UJ-ON must read NOT YET or PASS, never FAIL);
  a follow-up holding two approved drafts;
- **CS-G1**: a claim switch in `kyc_config.json` and in the store row (the store-only case planted by a fixture writer,
  said so), each refused by CS-G1 with its weakened copy caught; in each `config.json`, LK-G1's held rule refusing first;
  then GO;
- **the deploy, PASSED**, with U1–U4 equal to the rehearsal's own independent reading; **ME-CA1** over a planted
  straddling error stamped in local time on both sides of the boundary, and its NOT ESTABLISHED without a RUN line;
- **`--after-only`** before and after a close pass (NOT YET, then UJ-ON PASS); its weakened copy (F3 removed from the
  installed close job) FAIL;
- **the rollback**: with the store row holding keys the file lacks (production's normal state; AS-RB1 must write,
  AS-RB2 off, LM-RB1 masked); with the file holding a key the store lacks (no write; the capitals notice before
  `ROLLBACK`; the command printed alone); with `followup_auto_send` already off (no write); V1's automatic rollback;
  K5's four weakened copies;
- **`--redeploy`** refused while a claim switch is on, then PASSED.

Everything seeded is fictitious, from the rehearsal's own number range.

### L.11 Order

5.18.105's B2, then its deploy and `--after-only` (the owner's); 5.18.106's suite twice on its release commit (T1's 548
confirmed there); the derivation; the rehearsal (dev trials, then a record run); the docs/07 entry; then, on the owner's
word, `release/5.18.106` pushed and the deploy. Until 5.18.106 serves, steps 6, 11 and 12 stay off (§F).
