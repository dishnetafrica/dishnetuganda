# Phase 0 — Activating the Follow-Up Engine That Already Exists

**Date:** 18 September 2026 · **Against:** 5.18.22 (`cce5922`)
**Status:** PLAN ONLY. Nothing changed. Every command below is read-only until
Step 4, and Step 4 is yours to run, not mine.
**Prerequisite:** docs/28 (the Relationship Manager audit).

---

## 0. Two corrections to docs/28

Reading the engine line-by-line turned up two things I got wrong. Both reduce
the work.

### Correction 1 — the sales intelligence is NOT lost when a follow-up closes

docs/28 §C.2 said the evaluator's findings live only on the follow-up row and go
with it. That was wrong in the part that matters. Migration `070_followups.sql`
shows the `followups` table already carries:

```sql
crm_client_id, crm_link_method, lead_id,
topic, product, sales_stage, objection, context_summary
```

`lead_id` joins it to the lead. And closing is not deletion — `close()` sets
`closed_at`, and the schema **forbids** losing it:

```sql
CREATE TRIGGER fu_never_deleted BEFORE DELETE ON followups
  BEGIN SELECT RAISE(ABORT, 'follow-ups are never deleted'); END;

CREATE TRIGGER fu_closed_is_history BEFORE UPDATE ON followups
  WHEN OLD.closed_at IS NOT NULL
  BEGIN SELECT RAISE(ABORT, 'a closed follow-up is history — open a new one'); END;
```

So the history is permanent and protected at the database level.

**What this changes:** Phase 1 is smaller than docs/28 implied. `product`,
`sales_stage` and `objection` are already persisted and already joined to the
lead. Genuinely missing: **`temperature`** (confirmed — the word appears nowhere
in any migration or in the follow-up code), **`expected_purchase_date`**, the
**quote link**, and any *query* that reads what is already stored.

### Correction 2 — the follow-up brain is `ClaudeWaClient`, not `DishNetAiBrain`

`cron/followup_run.php` builds its evaluator on `ClaudeWaClient`. This matters
for Step 4 below, because `ClaudeWaClient:496` contains:

```php
$payPhone = trim((string)($cfgAll['payments_phone'] ?? $cfgAll['alert_whatsapp'] ?? ''));
```

**`alert_whatsapp` is a fallback for the payment phone shown to customers.** If
`payments_phone` is unset, setting the alert number could put your internal sales
number into a follow-up draft as a payment contact. Draft approval would catch
it, but it should not get that far — so the probe checks `payments_phone` first.

---

## 1. The internal sales number — settled, and not the way either of us expected

You spotted the discrepancy correctly. Here is what each resolves to:

| Number | Digits | National part | Verdict |
|---|---|---|---|
| `+21192797217` | 11 | `92797217` (8) | **Malformed.** SS needs 9. `significant()` returns `192797217`, eating a country-code digit |
| `+211927797217` | 12 | `927797217` (9) | **Well-formed** |

So the corrected number is structurally valid. **But it should still not be
used**, and the evidence is inside your own codebase.

`tools/set_alert_number.php` — which exists specifically to set this value —
names that exact number in its header as a documented past incident:

> *"`+211927797217` — a South Sudan number inherited from the Sudan config, so
> every Uganda handover was announced in another country. Five customers waited
> while nobody here was told, and the alerts arriving on that handset were read
> back as a customer wanting a Starlink quote — 24 messages of the assistant
> talking to its own alert channel."*

That is the number you asked me to configure. It has already been the alert
number, it already caused Uganda handovers to be announced in South Sudan, and
it was simultaneously conversation **c109** — a live thread the assistant
answers, so alerts landed in it and were replied to.

**The tool will refuse it.** It checks `wa_conversations` for the last nine
digits and exits non-zero unless `--force` is passed. The guard is deliberately
not a blocklist — the source explains why: *"the guard is not 'never that
number', it is 'never a number this system is already talking to', which is the
actual mechanism and catches the next one too."*

**Recommendation: use a Uganda handset (`+256`) belonging to the person who will
actually act on the lead.** The tool also warns when the country code differs
from every instance you answer on, which is exactly this case. If management in
Juba genuinely should receive Uganda hot leads, that is a business decision you
can make — but make it knowing it has already failed once, and close c109 first.

**I have configured nothing. Step 4a below is where you decide.**

---

## 2. The engine, line by line

### 2.1 The gate chain, in execution order

`FollowUpPolicy::gate()` — pure, no I/O, runs **before** the AI is consulted:

| # | Gate | Action | Note |
|---|---|---|---|
| 1 | `followup_enabled` unset | `skip` | master switch |
| 2 | opted out | **`close`** | earliest real gate — fires even with no follow-up open |
| 3 | customer wrote within the hour | `skip` | "a follow-up would be absurd" |
| 4 | `state = human_active` | **`close`** | a colleague has it |
| 5 | attempts ≥ `max_attempts` (2) | `close` | hard cap |
| — | one open follow-up per conversation | — | **database unique index**, not a branch: *"an application-level check would lose the race it exists to stop"* |

Plus, outside `gate()`: `contentLevel()` decides what may be said —
`CONTENT_ACCOUNT` / `CONTENT_ENQUIRY` / `CONTENT_NONE` by `crm_link_method`.

### 2.2 The four crons

| Cron | Interval | Does | Cannot do |
|---|---|---|---|
| `followup_scan` | 600s | SQL only — finds quiet conversations, opens a row with `due_at` | *"nothing in this file can reach Evolution"* |
| `followup_run` | 600s | runs gates, asks `ClaudeWaClient`, writes a **draft** | no send exists in the file |
| `followup_send` | 300s | sends drafts a **person approved** | cannot send an unapproved draft |
| `followup_close` | 600s | closes rows overtaken by events (reply, opt-out, takeover) | — |

All four `return` immediately unless `followup_enabled`.

### 2.3 Send-time re-checks — the part that makes approval safe

A person may approve on Monday and the send happen on Tuesday. Before each send
`followup_send.php` re-checks, and closes rather than sending if:

- the conversation no longer exists → `cancelled`
- the customer opted out since approval → `opted_out`
- a colleague took over since approval → `human_closed`
- **the customer replied after approval** (`last_customer_at > decided_at`) → `replied`
- outside 08:00–20:00 → **held, stays approved**, sent when the window opens

Then it sends via `$evo->sendText($chan, $phone, $body, CLASS_PROACTIVE)` and
**claims its own echo id first**, before any bookkeeping — otherwise Evolution's
`fromMe` echo reads as a colleague typing and stands the AI down for 24 hours.

### 2.4 Which number sends it

`$chan` is the follow-up's own channel, copied at open, and the instance is
`$config['evo_instance_' . $chan]`. **A follow-up therefore goes out on the same
number the conversation happened on** — a sales enquiry is followed up from the
sales number. No cross-instance surprise. From docs/27's routing probe,
`evo_instance_support = dishnet_ug`; the probe in Step 2 prints the full map.

### 2.5 Config keys that govern it

| Key | Default | Effect |
|---|---|---|
| `followup_enabled` | unset = off | master switch |
| `followup_not_before` | **unset = the whole history qualifies** | backlog floor — see §3 |
| `followup_max_age_hours` | 336 (14 days) | ignore conversations older than this |
| `followup_daily_cap` | 30 | per-channel per-day ceiling |
| `timezone` | unset = **Africa/Juba (UTC+2)** | the 08:00–20:00 window is measured in it |
| `alert_whatsapp` | unset = alerts off | hot-lead recipient; also the `payments_phone` fallback (§0) |

---

## 3. The one thing that would go wrong

**`followup_not_before` is the single most important value in this plan.**

If it is unset when you switch on, *every* conversation that has ever gone quiet
qualifies in the same morning — including people who enquired months ago and have
forgotten you exist. The doctor already anticipates this and prints:

> `⚠ NO BACKLOG FLOOR, AND n CONVERSATIONS QUALIFY AT ONCE.`
> `Switching on now would work through the entire history, oldest enquiries included.`

Because sending requires approval, the failure mode is a flooded draft queue
rather than a flooded customer base — bad, not catastrophic. **Set the floor
anyway, before enabling.** It costs one command.

---

## 4. Phase 0, exactly

Run these on the uCRM host. **Steps 1–3 are read-only.**

### Step 1 — Where everything stands (read-only)

```
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/followup_doctor.php
```

Prints: `followup_enabled`, whether the window is open now, whether the five
tables exist, open follow-ups, drafts awaiting a person, approved-not-sent, live
opt-outs, the resolved clock, the backlog floor, how many conversations the next
scan would consider, and what the next run would do with each.

### Step 2 — Why each one would be skipped, and the sender map (read-only)

```
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/followup_doctor.php --gates
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/set_alert_number.php
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/config_trace.php timezone
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/config_trace.php payments_phone
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/cron_status.php
```

`set_alert_number.php` with no arguments **reports** the current value and lists
your Evolution instances — it does not set anything. `payments_phone` is the
§0 check: if it is empty, set it before touching `alert_whatsapp`.

### Step 3 — Send me the output

Paste all of it. Redact customer numbers if you prefer; I need the counts, the
flags and the instance names, not the people. I will tell you whether the floor,
the cap and the clock are right **before** anything is switched on.

### Step 4 — Only after Step 3 (these WRITE)

**4a. The alert number.** Choose a Uganda handset, then:

```
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/set_alert_number.php --to 256XXXXXXXXX
```

The tool refuses its own instances, refuses a number already in a conversation,
refuses anything under 9 digits, and notes a foreign country code. If it refuses,
**do not `--force`** — send me what it said.

**4b. The backlog floor, BEFORE enabling.** The doctor prints the exact command
with the current timestamp filled in. It looks like:

```
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/set_config.php \
  --key followup_not_before --value '2026-09-18 20:00:00'
```

Use the timestamp the doctor prints, not this one.

**4c. The clock, if Step 2 showed it unset.**

```
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/set_config.php \
  --key timezone --value Africa/Kampala
```

**4d. A conservative cap for the first week.**

```
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/set_config.php \
  --key followup_daily_cap --value 5
```

Five, not thirty. You are testing whether the drafts are any good, not running a
campaign.

**4e. Enable — last, and only last.**

```
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/set_config.php \
  --key followup_enabled --value 1
```

Sending still requires a person. Enabling does not message anybody.

---

## 5. Safe test procedure

### 5.1 Before enabling — the dry run that costs nothing

`followup_doctor.php` **is** the dry run. It reports what the next scan and the
next run *would* do, gate by gate, without opening a row, asking the model or
sending anything. Run it, read the skip reasons, and only proceed when the
"would open" count is a number you are comfortable seeing as drafts.

### 5.2 The first 48 hours — watch, approve nothing

After 4e, leave the draft queue alone for two cycles (about 20 minutes for the
first scan and run) and then look at it in **Engage → follow-up drafts**.

For each draft ask four questions:

1. **Does the reason name something the customer actually said?** The evaluator
   returns a `reason` field, shown to the approver. "Asked about Starlink for a
   10-person home, never came back" is right. "Customer may be interested" is not.
2. **Would you send this yourself?** If you would rewrite it, edit it in the
   screen rather than approving — `edited_body` is preferred over `body` at send.
3. **Is the content level correct?** A `CONTENT_ENQUIRY` draft must not mention a
   balance, an invoice or a service. If one does, stop and tell me — that is a
   bug, not a wording preference.
4. **Is it the right person?** Any draft to a staff number or to one of your own
   instances means the scan is picking up threads it should not.

**Approve nothing on day one.** Rejecting is free and is recorded.

### 5.3 The first real send — one, deliberately

When a draft passes all four questions, approve exactly one. Then within five
minutes:

```
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/followup_doctor.php
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/wa_conversation.php <conv id>
```

Confirm: the message arrived once, it appears in the conversation as an outbound
`followup` message, `approved, not yet sent` has dropped to zero, and — the one
that matters — **the AI did not stand itself down.** If the echo claim failed you
would see the conversation flip to `human_active`. That is the specific
regression this release's echo handling exists to prevent, and one live send is
how you prove it works.

### 5.4 The week

| Watch for | Where | Means |
|---|---|---|
| drafts per day | doctor | is the floor/cap right |
| reply rate to sent follow-ups | conversation threads | are they any good |
| `opted_out` closes | `followup_events` | you are annoying people — stop |
| `replied` closes at send time | `followup_events` | the re-check is working |
| any `CONTENT_ACCOUNT` draft to a `phone_tail` lead | drafts | **bug — tell me at once** |
| hot-lead alerts arriving | the handset from 4a | alerting works |

### 5.5 Stop conditions — switch off immediately if any occur

- A draft contains another customer's information. **Any instance, any severity.**
- A follow-up reaches a number that never messaged you.
- A customer complains about being messaged.
- Two follow-ups reach the same person for one enquiry.
- The AI stands down after a follow-up send (echo claim broken).

```
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/set_config.php \
  --key followup_enabled --value 0
```

---

## 6. Rollback

Every step is a config value, so rollback is complete and immediate.

| Undo | Command |
|---|---|
| Stop everything | `set_config.php --key followup_enabled --value 0` |
| Stop alerts | `set_alert_number.php --off` |
| Loosen the cap | `set_config.php --key followup_daily_cap --value 30` |
| Clear the floor | `set_config.php --key followup_not_before --value ''` |

**No code is deployed in Phase 0**, so there is no ZIP to roll back and no
migration to reverse. Open follow-up rows left behind are harmless: with
`followup_enabled` off, all four crons return immediately, and the rows are
history the schema protects rather than clutter.

The one thing rollback cannot undo is a message already delivered. That is the
entire reason sending stays behind human approval in this phase.

---

## 7. What Phase 0 will tell you

By the end of the week you should be able to answer:

1. **How many leads actually go quiet per week?** The doctor's "would open" count
   is your real inbound nurture volume — currently unknown, and it sizes
   everything downstream.
2. **Are the drafts good enough to send?** This decides whether Phase 2
   (reason-driven timing) is worth building or whether the evaluator prompt needs
   work first.
3. **Does the reason field already carry the customer's situation?** If it does,
   Phase 1 is a schema change plus a query. If it does not, the evaluator prompt
   is the real Phase 1.
4. **Who should actually receive hot-lead alerts?** One week of alerts to one
   handset will settle the +256/+211 question better than any argument.

**Do not start Phase 1 until these four have answers.** Two of them may change
what Phase 1 is.

---

## 8. What I have not done

No code written. No configuration read or changed — I have no network path to
your uCRM or Evolution, which is why every value above is a command for you to
run rather than a number I have quoted. No knowledge base touched. No follow-up
timing altered. No customer or staff number messaged. No alert number set.

The only artefact is this document.
