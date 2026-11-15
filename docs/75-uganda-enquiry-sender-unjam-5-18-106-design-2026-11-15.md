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
