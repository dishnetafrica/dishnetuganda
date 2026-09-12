# AI customer follow-up — architecture proposal

Status: **proposal, nothing built.** Written after reading the existing
conversation store, identity path, AI worker, event bus and scheduler.

---

## 1. What already exists

Most of the substrate is here. The feature is smaller than it looks, and the
risky parts are not the ones you would expect.

| Piece | Where | Fit |
|---|---|---|
| Conversation store | `wa_conversations` / `wa_messages` (`lib/ConversationService.php`) | Already carries `crm_client_id`, `channel`, `state`, `last_customer_at`, `last_agent_at`, indexed on `crm_client_id` |
| Canonical customer identity | `DishNetTools::identifyCustomerByPhone()` → `linkToCrm()` | Already refuses to guess on ambiguity (`AiReplyWorker.php:263`) |
| Canonical hardware identity | `equipment_assignments` (`lib/EquipmentAssignment.php`) | CRM client → kit/service line, exact match only |
| Assembled account view | `lib/CustomerAccountService.php` | Services, invoices, payments, equipment for one client |
| Durable work queue | `lib/EventBus.php` — emit/consume/ack/fail, locks, dead letters | Right substrate for "decide later" work |
| Scheduler | `cron/master.php`, ~300s dispatch, budget-aware | Where the sweeps register |
| The brain | `lib/ClaudeWaClient.php` + prompt modules | Generates the message |
| Outbound path | `EvolutionApiService::sendText($channel,$phone,$text)` | One send path, already used by `AiReplyWorker` |
| Veto-an-automatic-send precedent | `lib/EmailReplyPolicy.php` — `ESCALATION_WORDS`, `mayAutoSend()` | Pattern to copy for WhatsApp |
| Human-takeover stand-down | `evo_webhook.php:162` → `markHumanHandling()` | A colleague typing silences the bot |

## 2. What is missing, in order of danger

**2.1 — There is no opt-out. Anywhere.**

Nothing in the plugin honours STOP, "unsubscribe", or "don't message me again"
on WhatsApp. Today that is survivable because the assistant only ever *replies*
— every message it sends was invited by one the customer just sent.

A follow-up system breaks that invariant: it sends the first message. The
moment it ships, unsolicited outbound exists and there is no mechanism to stop
it. **This has to be built first and separately**, and it must gate every
outbound path, not only follow-ups.

**2.2 — A conversation's CRM link is best-effort, and nothing records how
confident it is.**

`crm_client_id` is written by four paths: a phone-tail match at first contact
(`cron_wa_sync.php:224`), the AI worker on identification
(`AiReplyWorker.php:261`), a manual link and a bulk rematch
(`includes/api/api_whatsapp.php:300,320`). A conversation starts unlinked;
whether it ever gets linked depends on which path ran.

The identity lookup itself is sound — it returns `ambiguous` rather than
picking one when several clients share a number's last digits. But the
*result* is stored as a bare integer. Nothing records whether it came from an
exact match, a 9-digit tail, or a person clicking a button.

For replying to an inbound message that hardly matters. For sending an
*unsolicited* one it matters a great deal: a wrong link means following up the
wrong person about someone else's enquiry.

**2.3 — Nothing records what an enquiry was about.**

`category` and `lead_id` exist; neither captures "they asked about DishNet
Home". Your requirement *"what product/service they were interested in"* has
nowhere to live today.

**2.4 — There is no conversation-lifecycle sweeper.**

`cron_wa_bot.php` (auto-close, 24h cooldown reset) is **deliberately disabled**
in `cron/master.php:192` — superseded by the AI brain. Nothing periodically
re-examines a conversation. The 24h `human_active` reset in
`WaAutoReplyService.php:106` only fires when the customer's *next* message
arrives.

So: no existing job walks the conversation table on a timer. The follow-up
scan will be the first, and must not assume anything else keeps state tidy.

## 3. The identity question you have to settle first

You wrote the chain as:

```
Customer → CRM Customer ID → Conversation → Product → Follow-up → AI → Channel
```

That is right for **existing customers**. It excludes the population a
follow-up system mostly exists to serve.

`c175`, the live example: a real sales enquiry, asked about plans, went quiet
— and `in uCRM: not matched to a customer`. Someone asking *"how much is
DishNet Home"* is by definition usually not a client yet. Requiring
`crm_client_id` would refuse to follow up exactly the people worth following
up.

**Proposal: anchor on the conversation, carry canonical identity when it
exists, and be explicit when it does not.**

```
Conversation  ─┬─ crm_client_id   (canonical, when linked)
               ├─ lead_id         (prospect, when captured)
               └─ neither         (anonymous enquiry — still followable)
```

The conversation id is the thing that always exists and is never ambiguous. It
is also what "the SAME communication channel" means in practice: the follow-up
goes back to the thread it came from, so the channel is not a separate lookup
that could disagree.

This keeps your rule intact — where a CRM identity exists it is *the* identity,
exact, never guessed — while not making "not yet a customer" mean "never
contacted again".

## 4. Where the state lives

Two new tables, plus one that is not really about follow-ups at all.

### 4.1 `contact_optouts` — build first, independently

```sql
CREATE TABLE contact_optouts (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  phone         TEXT    NOT NULL,
  channel       TEXT    NOT NULL DEFAULT '*',   -- '*' = every channel
  crm_client_id INTEGER,
  reason        TEXT    NOT NULL,               -- 'customer_request'|'not_interested'|'staff'
  source        TEXT    NOT NULL,               -- 'keyword'|'ai_classified'|'admin'
  evidence      TEXT,                           -- the message that caused it
  created_at    TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX idx_optout ON contact_optouts(phone, channel);
```

Checked by **every** outbound path, not just follow-ups. An opt-out is never
deleted, only superseded by an explicit re-consent row — the same
"history is not editable" rule as the ledger and `equipment_assignments`.

### 4.2 `followups` — one open enquiry per conversation

```sql
CREATE TABLE followups (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  conversation_id  INTEGER NOT NULL,
  channel          TEXT    NOT NULL,      -- copied at open; the reply goes back here
  crm_client_id    INTEGER,               -- canonical identity, when known
  lead_id          INTEGER,
  topic            TEXT    NOT NULL,      -- 'plan_enquiry'|'quote'|'install'|'support'
  topic_detail     TEXT,                  -- 'DishNet Home', quote id, kit serial
  opened_at        TEXT    NOT NULL DEFAULT (datetime('now')),
  last_customer_at TEXT    NOT NULL,      -- their last message when this opened
  due_at           TEXT,                  -- when the AI may next be ASKED
  attempts         INTEGER NOT NULL DEFAULT 0,
  max_attempts     INTEGER NOT NULL DEFAULT 2,
  last_sent_at     TEXT,
  closed_at        TEXT,
  close_reason     TEXT,                  -- replied|not_interested|converted|
                                          -- opted_out|exhausted|human_closed|vetoed
  opened_by        TEXT    NOT NULL       -- 'scan'|'staff:<name>'
);

-- The structural guarantee: ONE open follow-up per conversation. A racing
-- worker cannot create a second one; the database refuses it.
CREATE UNIQUE INDEX idx_fu_open_conv ON followups(conversation_id)
  WHERE closed_at IS NULL;
```

Plus the two triggers that made `equipment_assignments` trustworthy: a closed
row cannot be reopened, and no row can be deleted. Follow-up history is
evidence of what we sent a customer; it is not editable.

### 4.3 `followup_sends` — the audit trail

```sql
CREATE TABLE followup_sends (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  followup_id   INTEGER NOT NULL,
  attempt       INTEGER NOT NULL,
  sent_at       TEXT    NOT NULL DEFAULT (datetime('now')),
  channel       TEXT    NOT NULL,
  phone         TEXT    NOT NULL,
  body          TEXT    NOT NULL,        -- exactly what the customer received
  wa_message_id TEXT,
  model         TEXT,                    -- which brain wrote it
  decided_by    TEXT    NOT NULL         -- 'ai'|'staff:<name>'
);
```

This is your *"clear follow-up history in the CRM"*. It answers, months later,
"what exactly did we send this person and why" — which a `last_sent_at`
timestamp never can.

## 5. Scheduling: the clock decides when to ASK, never what to do

Two jobs, deliberately split, both registered in `cron/master.php`.

**`followup_scan` — cheap, pure SQL, no AI, no sending.**

Finds conversations where the customer spoke last and nothing has happened
since, and opens a `followups` row with a `due_at`. It never sends and never
calls the model. Because it is only SQL it can run every cycle inside the
budget.

**`followup_run` — for each row whose `due_at` has passed.**

Loads the thread, the CRM state and the account view, and asks the brain one
structured question:

```json
{ "send": true|false,
  "reason": "why",
  "topic_detail": "what they were actually asking about",
  "text": "the message, if send",
  "close": "not_interested|converted|null",
  "next_due_hours": 48 }
```

The AI may answer **no** — and closing the row on the AI's own judgement is a
first-class outcome, not a failure. This is what makes it an AI-driven
workflow rather than "send after X days": the clock only decides *when the
question is asked*. The answer is never a template.

`due_at` sets the earliest moment to ask. The AI's `next_due_hours` sets the
next one. Neither is a hardcoded send schedule.

## 6. Safeguards, cheapest and most certain first

Each is a hard gate evaluated **before** the model is consulted, so a broken or
expensive brain can never cause a wrong send. All of them live in one pure,
testable `FollowUpPolicy` class — the `EmailReplyPolicy` pattern.

| # | Gate | Outcome |
|---|---|---|
| 1 | Phone is in `contact_optouts` | Never send. Close `opted_out`. |
| 2 | Customer messaged within the quiet window, or `state = human_active` | Never send — there is an active conversation |
| 3 | An open `followups` row already exists | Cannot happen — DB refuses it |
| 4 | `attempts >= max_attempts` | Close `exhausted` |
| 5 | Thread contains `EmailReplyPolicy::ESCALATION_WORDS` | Never send; flag for a human |
| 6 | Outside business hours, or daily channel cap reached | Defer, do not close |
| 7 | Master config flag off | Never send (default off) |
| 8 | **Then** ask the AI | It may still say no |

**One integration detail that will bite if missed.** Every outbound message
comes back through the Evolution webhook as `fromMe`, and the webhook now reads
an unrecognised `fromMe` message as *a colleague typing on the handset* and
stands the AI down (`evo_webhook.php:162`). `AiReplyWorker` avoids this by
claiming its own message id in `EvoWebhookGuard` before the echo arrives
(`AiReplyWorker.php:150`). **The follow-up sender must do the same**, or every
follow-up it sends will silence the assistant on that conversation for 24
hours.

## 7. What closes a follow-up

| Trigger | Reason | Detected by |
|---|---|---|
| Customer replies | `replied` | webhook, on the next inbound message |
| Customer says no | `not_interested` | AI classification → also write an opt-out |
| Service created / quote accepted | `converted` | uCRM state at decision time |
| Staff reply in thread | `human_closed` | `markHumanHandling()` |
| Attempts used up | `exhausted` | `followup_run` |
| Opt-out recorded | `opted_out` | gate 1 |

## 8. What gets built

```
lib/FollowUpPolicy.php     pure gate functions, no I/O — the testable core
lib/FollowUpService.php    state machine: open, defer, send, close
lib/ContactOptOut.php      opt-out list, honoured by every outbound path
migrations/069_followups.sql
cron/followup_scan.php     SQL only, opens rows
cron/followup_run.php      asks the AI, sends, records
tools/followup_doctor.php  dry run: who would be followed up, and why not
tabs/engage/followups.php  open follow-ups, history, kill switch
```

Config flag `followup_enabled`, **default off**. Absent config = today's
behaviour exactly, per the standing rule.

## 9. Decisions I need before building

1. **Prospects as well as customers?** Recommend yes — `c175` is exactly the
   case, and it has no CRM id.
2. **How many attempts, over what window?** Recommend 2: about 24h after they
   go quiet, then about 72h, then stop.
3. **Quiet hours?** Recommend Africa/Kampala 08:00–20:00, nothing on Sunday.
4. **Draft-for-approval first?** There is precedent — `inbound_mail` "files
   drafts for approval. Never sends." Recommend running the first weeks that
   way, so you read what it would have sent before any customer does.
