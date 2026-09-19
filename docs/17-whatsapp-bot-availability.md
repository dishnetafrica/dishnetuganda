# 17 — The assistant that went quiet: why, and what 5.18.9 changes

**Date:** 15 September 2026 · **Plugin:** 5.18.9 · **Status:** applied 16 September 2026. The repair tool found 41 rows in 18 conversations, all from the notification record, and fixed them (remaining 0). Log baseline at install: `human active, skipping AI` 208; the four replacement lines 0.

## What happened

A prospect wrote to the sales number one evening asking about plans. One
second later a greeting went out from our number — the WhatsApp Business
app's own automatic greeting, which names a different company. Six seconds
after that the assistant answered the plans question correctly. Two minutes
later the prospect asked whether we sell the Standard Kit. The log for that
message reads `human active, skipping AI`, and it was never answered by
anyone until a person noticed it the next day.

The same greeting had gone out to 49 conversations in the previous seven
days. Each time, the plugin took the assistant off that thread for the whole
hand-over cooldown, which was 24 hours by default and 30 minutes at the time,
at the exact moment the customer was asking.

## Why

The webhook receives every message that leaves our number, marked `fromMe`.
Since 5.18.2 it records those and, when the message is one the plugin did
not itself send (its id is not in the dedupe table), it treats it as a
colleague typing on the handset and stands the assistant down. That rule is
right for a colleague. It cannot tell, from one message, that the WhatsApp
Business app's greeting is not a colleague.

Around that, four smaller faults, all found in the same log:

1. **The assistant's own pictures echoed back as a colleague.** The text
   reply claimed its Evolution id so its echo was recognised. The photo,
   document and plans-flyer sends did not, and stored no id either, so each
   caption came back as `fromMe`, inserted as a `Team` message, and paused
   the assistant right after it had shown someone the kit.
2. **Two clocks.** Stamps are stored in UTC. They were read with
   `strtotime()`, which applies the process time zone. The worker spawned by
   the webhook runs in UTC; the same worker run by `cron/master.php` runs in
   Africa/Kampala. On the scheduled path a hand-over stamp read three hours
   old, so the pause did not hold there, and the watchdog read a customer who
   had waited two minutes as having waited three hours. The retry time
   written by the queue on that path sat three hours in the future, so a
   model timeout at 21:58 got its second attempt at 01:00.
3. **A dead letter was silent.** After five failed attempts (about forty
   minutes) the queue marks a message dead. Nothing told the customer or the
   team.
4. **Notification rows stamped ahead.** The record the notification service
   writes into the conversation store used the local clock: 41 rows sat
   three hours ahead of their own creation, so the Inbox showed a reminder
   after messages sent later, and `last_agent_at` in the future made the
   watchdog think that customer had been answered.

## What 5.18.9 changes

**A canned greeting is not a colleague.** Before storing a `fromMe` message
the webhook asks `ConversationService::isCannedHandsetText()`: has our side
already sent this exact text, word for word, to three or more *other*
conversations in the last seven days? If so it is stored under the label
`WhatsApp auto-reply` (so the Inbox still shows what the customer saw), the
model's history names it `[automatic message from our WhatsApp app]` rather
than `[Team, from our team]`, and it never stands the assistant down. Texts
under 40 characters never qualify: "Ok" or "Yes we do", typed by hand in
four chats, is four people. Rows already stored as `Team` count towards the
three, which is how a greeting that has been running for weeks is recognised
on its next appearance. The rule reads only our own outbound rows; nothing a
customer types can trigger it.

**A question that arrives during a pause is parked, not dropped.** When a
colleague is active on the thread, the worker no longer acks the message.
It throws `WorkerDefer`, and `WorkerBase` puts the event back in the queue
via the new `EventBus::defer()` with the attempt count untouched and
`next_retry_at` set to when the pause should end (between 30 seconds and 10
minutes ahead, re-read each time because a pause can be extended). When it
comes back:

- if a person on our side has written in that conversation since the
  question arrived (not the assistant, not the plugin's notifications, not
  the canned greeting), the question was dealt with and the event is acked
  with `a colleague replied after this message arrived — nothing to add`;
- otherwise the assistant answers it as normal, with the full history;
- a question that has been waiting more than `wa_parked_max_minutes`
  (default 120) behind a still-active colleague is dropped with
  `parked question dropped` — by then the colleague has dealt with it or
  the watchdog has paged the team, and a bot answer two hours late reads
  worse than none.

Cooldown 0 keeps its meaning: the assistant never stands down, reads what
the colleague said and carries on.

**Every send claims its echo.** Photo, document and flyer sends now go
through the same `claimOwnEcho()` as the text reply: the Evolution id is
claimed in the webhook's dedupe table before the row is stored, and stored on
the row. The echo loses the idempotency race and never reaches the `fromMe`
branch.

**One clock.** New `lib/UtcClock.php` reads a stored stamp as UTC whatever
the process zone. It is used by the hand-over check in the worker, by
`AlertService::findUnanswered()` and the watchdog's waiting-time line, and
by the worker's parking logic. `EventBus::fail()`, `defer()` and the stale
lock release write `gmdate()`. The notification service's conversation
record writes `gmdate()` and, when Evolution carried the message, the
message id.

**A dead job tells someone.** `EventBus::fail()` now reports whether the
failure was the last one; `WorkerBase` calls a new `onDead()` hook once.
The AI worker's `onDead()` runs the existing hand-over: the thread is marked
`needs_human`, the alert number is paged with the reason
(`no reply after 5 attempts (...)`), and the customer receives the configured
`ai_handover_message`, once. With that line empty the customer still
receives nothing, as before — set it in Engage → WhatsApp → AI setup.

**Repair for the rows already written on the wrong clock.**
`tools/wa_clock_repair.php` lists, and with `--fix` corrects, every message
row whose `sent_at` is more than 30 minutes after its own `created_at`
(which SQLite writes itself, in UTC, at insertion — a message cannot be sent
after the row recording it was created). It sets `sent_at = created_at` and
recomputes `last_message_at`, `last_customer_at` and `last_agent_at` for the
affected conversations. It prints counts only.

## What did not change

The security invariant is untouched. The assistant still receives only the
conversation it is answering, still never chooses which customer it is
authorised to see, and no customer-typed content acquired any new effect:
the canned rule reads our own outbound rows, the parking rule reads our own
outbound rows, and the hand-over message is the operator's configured text.
The prompt corpus hash is unchanged from 5.18.4 through 5.18.9.

## Settings

| Key | Where | Default | Meaning |
|---|---|---|---|
| `wa_human_cooldown_minutes` | Engage → WhatsApp → AI setup, or `tools/set_config.php` | 1440 | How long the assistant stays quiet after a colleague types. 0 = never. Recommended 5–30 now that parked questions are answered when it ends. |
| `wa_parked_max_minutes` | `tools/set_config.php --key wa_parked_max_minutes --value N` | 120 | A parked question older than this behind a still-active colleague is dropped. |
| `ai_handover_message` | Engage → WhatsApp → AI setup | empty | The line the customer gets on hand-over, including after a dead job. Empty = nothing is sent. |

The canned rule's numbers (40 characters, 3 other conversations, 7 days) are
constants in `ConversationService`; they are documented in the code beside
their reasons.

## Tests

`tests/test_bot_stays_awake.php` (59 assertions) drives the real
`ConversationService`, `EventBus`, `WorkerBase` and `AiReplyWorker` against
the fake Evolution server with a brain that never leaves the process: the
canned rule with its three negatives (short text, two conversations, rows
older than the window); the flyer send claiming and recording its id and the
echo losing the race; the hand-over pause holding under Africa/Kampala and
UTC; `findUnanswered()` under Kampala; the retry time in UTC; a parked
question acked when a colleague answers it, answered when nobody does,
dropped after the cap, and never parked at cooldown 0; the fifth failure
paging the team and messaging the customer while the first failure stays
quiet; and the repair tool's dry run, `--fix` and second run. Eleven
mutations of the new code each make the test fail (canned rule off, webhook
pausing on canned, flyer echo unclaimed, UTC helper on the process zone,
`AlertService` back on `strtotime`, park replaced by drop, `WorkerBase`
acking a deferred event, colleague-answered check ignored, dead job silent,
`fail()` on the local clock, `--fix` a no-op). `test_human_takes_over.php`
and `test_human_reply_visible.php` were re-pinned to the new webhook shape.

## Server steps after upload

Confirm the version:

```
docker exec ucrm grep -c '"version": "5.18.9"' /data/ucrm/data/plugins/dishnet-hybrid-sudan/manifest.json
```

Repair the rows written on the wrong clock (report first, then fix):

```
docker exec -u $(stat -c %u:%g /home/unms/data/ucrm/ucrm/data/plugins/dishnet-hybrid-sudan) -w /data/ucrm/data/plugins/dishnet-hybrid-sudan ucrm php tools/wa_clock_repair.php
docker exec -u $(stat -c %u:%g /home/unms/data/ucrm/ucrm/data/plugins/dishnet-hybrid-sudan) -w /data/ucrm/data/plugins/dishnet-hybrid-sudan ucrm php tools/wa_clock_repair.php --fix
```

Watch the new log lines appear over the following days (counts only):

```
docker exec ucrm sh -c "for w in 'parked for' 'a colleague replied after this message arrived' 'parked question dropped' 'HANDOFF to human — no reply after' 'human active, skipping AI'; do printf '%-52s %s\n' \"\$w\" \"\$(grep -c \"\$w\" /data/ucrm/data/plugins/.dishnet-hybrid-sudan-data/ai_platform.log)\"; done"
```

`human active, skipping AI` should stop growing from the moment 5.18.9 is
installed; the first three lines are what replaces it.

Two things only the operator can do:

- **Switch off the WhatsApp Business app's Greeting message and Away message
  on both numbers** (WhatsApp Business → Business tools → Greeting message /
  Away message). 5.18.9 stops them silencing the assistant, but they still
  reach every new customer under another company's name, one second before
  the assistant answers.
- **Set the cooldown deliberately.** It was lowered to 5 minutes as an
  interim measure while the greeting was pausing the assistant. With parked
  questions now answered when the pause ends, 5–30 minutes are both
  reasonable; 30 gives a colleague more room, 5 answers sooner.
