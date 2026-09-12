# AI customer follow-up — approved design

Status: **direction approved, decisions locked, build in progress.**
First release is **draft-for-approval**: the AI decides and writes, a human
sends. Automatic customer-facing sending stays off behind a master flag until
draft mode has been validated in production.

---

## Part 1 — What already exists

Most of the substrate is here. The feature is smaller than it looks, and the
risky parts are not the ones you would expect.

| Piece | Where | Fit |
|---|---|---|
| Conversation store | `wa_conversations` / `wa_messages` (`lib/ConversationService.php`) | Carries `crm_client_id`, `channel`, `state`, `last_customer_at`, indexed on `crm_client_id` |
| Customer identity | `DishNetTools::identifyCustomerByPhone()` → `linkToCrm()` | Already refuses to guess on ambiguity (`AiReplyWorker.php:263`) |
| Hardware identity | `equipment_assignments` (`lib/EquipmentAssignment.php`) | CRM client → kit / service line, exact match only |
| Account view | `lib/CustomerAccountService.php` | Services, invoices, payments, equipment for one client |
| Durable queue | `lib/EventBus.php` | emit / consume / ack / fail, locks, dead letters |
| Scheduler | `cron/master.php`, ~300s dispatch, budget-aware | Where the jobs register |
| The brain | `lib/ClaudeWaClient.php` + prompt modules | Writes the message |
| Outbound | `EvolutionApiService::sendText($channel,$phone,$text)` | One send path |
| Echo protection | `EvoWebhookGuard::claim()` (`AiReplyWorker.php:150`) | Stops our own message reading as a colleague's |
| Veto precedent | `lib/EmailReplyPolicy.php` | `ESCALATION_WORDS`, `mayAutoSend()` |

### Two facts the implementation must respect

**Timestamps are UTC in storage, localised only for display**
(`ConversationService.php:371`). One conversation once carried two clocks —
the webhook under `Africa/Juba`, the CLI worker under UTC — and ordering by
`sent_at` put every AI reply two hours before the question it answered. So:
the 24h/72h arithmetic is UTC and unambiguous; **quiet hours must convert
UTC → local before comparing.**

**Every outbound message echoes back as `fromMe`**, and an unrecognised
`fromMe` message is read as a colleague typing on the handset and stands the
AI down for 24 hours (`evo_webhook.php:162`). `AiReplyWorker` avoids this by
claiming its own message id in `EvoWebhookGuard` *before* the echo can
arrive. **The follow-up sender must do the same** — decision 13.

## Part 2 — Approved decisions

| # | Decision |
|---|---|
| 1 | Follow up **both** CRM customers and prospects not yet in CRM |
| 2 | **Conversation is the primary anchor.** CRM identity attaches when confidently known; a NULL `crm_client_id` never excludes a prospect |
| 3 | Cadence: follow-up #1 at ~24h of quiet, #2 at ~72h, then close. No third |
| 4 | Quiet hours 08:00–20:00 Kampala, no Sunday, and never while the customer has messaged recently |
| 5 | **Opt-out is built first** and gates *all* automatic outbound, not only follow-ups |
| 6 | CRM link **provenance** is recorded. An uncertain identity is not safe for customer-specific proactive content |
| 7 | Enquiry context is stored: product/service, topic, sales stage, relevant context, follow-up status |
| 8 | AI-driven, not a timer. The scheduler decides *when to evaluate*; the AI decides *whether and what* |
| 9 | The AI reads the complete relevant conversation — question, answer, objections, commitments, stage, and why they may have gone quiet |
| 10 | Contextual messages. No "Hi, just following up" unless it genuinely fits |
| 11 | AI verdicts: `SEND`, `DO_NOT_SEND`, `WAIT`, `ESCALATE_TO_HUMAN` |
| 12 | Stops on: reply, not-interested, no-more-messages, resolved, human takeover, escalation, opt-out, attempts exhausted |
| 13 | Follow-up sender integrates with `EvoWebhookGuard` exactly as `AiReplyWorker` does |
| 14 | **Database-level** guarantee of one active follow-up per conversation |
| 15 | **First release is draft-for-approval.** The AI never sends |
| 16 | Automatic send arrives later, behind a master feature flag |
| 17 | Every decision is audit-logged |

### Decision 3 in practice — sales stage beats the clock

The cadence is when the AI is *asked*, never what it says. A customer who
said *"I need to discuss with my husband first"* must not receive *"would you
like to proceed?"* — the correct follow-up acknowledges the deliberation
("if you have questions while deciding, I'm happy to help"). A customer who
said *"not interested"* receives nothing, ever, and the row closes
immediately. That judgement is the AI's job and is why `sales_stage` and
`objection` are stored rather than inferred at send time.

### Decision 6 in practice — provenance gates what may be said

| `crm_link_method` | Meaning | Proactive content allowed |
|---|---|---|
| `verified` | Customer authenticated (portal/OTP) or staff confirmed against the record | Account-specific: invoices, service, kit |
| `manual` | A person linked it in the admin inbox | Account-specific |
| `ai_identified` | `identifyCustomerByPhone` returned a single unambiguous match | Account-specific |
| `phone_tail` | 9-digit tail match at first contact (`cron_wa_sync.php:224`) | **Enquiry context only** — never account facts |
| `bulk_rematch` | Batch re-linking pass | **Enquiry context only** |
| `ambiguous` | Several clients share the number | **No proactive send at all** |
| `null` | Not linked — prospect | Enquiry context only (this is normal and fine) |

A phone-tail match is good enough to answer someone who just wrote to us. It
is not good enough to open an unsolicited message with their account details.

## Part 3 — Database changes

Four new tables, two new columns. All in `migrations/069_followups.sql` except
the opt-out, which ships first in `migrations/069a_contact_optouts.sql`.

### 3.1 `contact_optouts` — ships first, on its own

```sql
CREATE TABLE contact_optouts (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  phone         TEXT    NOT NULL,
  channel       TEXT    NOT NULL DEFAULT '*',   -- '*' = every channel
  crm_client_id INTEGER,
  active        INTEGER NOT NULL DEFAULT 1,     -- 0 = superseded by re-consent
  reason        TEXT    NOT NULL,               -- customer_request|not_interested|staff|bounce
  source        TEXT    NOT NULL,               -- keyword|ai_classified|admin|api
  evidence      TEXT,                           -- the exact message that caused it
  created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
  created_by    TEXT    NOT NULL DEFAULT 'system'
);
CREATE UNIQUE INDEX idx_optout_live ON contact_optouts(phone, channel) WHERE active = 1;
CREATE INDEX idx_optout_client ON contact_optouts(crm_client_id) WHERE crm_client_id IS NOT NULL;
```

Never deleted. Re-consent sets `active = 0` on the old row and inserts a new
one, so the history of what someone asked for survives.

### 3.2 `wa_conversations` — two columns

```sql
ALTER TABLE wa_conversations ADD COLUMN crm_link_method TEXT;   -- see the provenance table
ALTER TABLE wa_conversations ADD COLUMN crm_link_at     TEXT;
```

Added through `ConversationService::ensureTables()`'s existing additive
`$addCols` mechanism, which never drops.

### 3.3 `followups` — one open enquiry per conversation

```sql
CREATE TABLE followups (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  conversation_id  INTEGER NOT NULL,
  channel          TEXT    NOT NULL,      -- copied at open: the reply goes back here
  phone            TEXT    NOT NULL,      -- copied at open, for opt-out checks
  crm_client_id    INTEGER,               -- canonical identity, when known
  crm_link_method  TEXT,                  -- provenance AS AT OPEN
  lead_id          INTEGER,

  -- Enquiry context (decision 7)
  topic            TEXT    NOT NULL,      -- plan_enquiry|quote|install|support|other
  product          TEXT,                  -- 'DishNet Home', kit serial, quote id
  sales_stage      TEXT,                  -- enquired|quoted|deciding|objection|ready|unknown
  objection        TEXT,                  -- 'discussing with spouse', 'price', ...
  context_summary  TEXT,                  -- the AI's short account of where this stands

  opened_at        TEXT    NOT NULL DEFAULT (datetime('now')),
  last_customer_at TEXT    NOT NULL,      -- UTC, their last message when this opened
  due_at           TEXT,                  -- UTC, when the AI may next be ASKED
  attempts         INTEGER NOT NULL DEFAULT 0,
  max_attempts     INTEGER NOT NULL DEFAULT 2,
  last_sent_at     TEXT,
  closed_at        TEXT,
  close_reason     TEXT,
  opened_by        TEXT    NOT NULL DEFAULT 'scan'
);

-- DECISION 14. One open follow-up per conversation, guaranteed by the
-- database. A racing scan cannot create a second; the index refuses it.
CREATE UNIQUE INDEX idx_fu_open_conv ON followups(conversation_id) WHERE closed_at IS NULL;
CREATE INDEX idx_fu_due   ON followups(due_at)    WHERE closed_at IS NULL;
CREATE INDEX idx_fu_client ON followups(crm_client_id) WHERE crm_client_id IS NOT NULL;
```

`close_reason` vocabulary: `replied`, `not_interested`, `converted`,
`opted_out`, `exhausted`, `human_closed`, `escalated`, `vetoed`,
`staff_cancelled`.

Two triggers, the pair that made `equipment_assignments` trustworthy:

```sql
-- A closed follow-up is history. It is never reopened or rewritten.
CREATE TRIGGER fu_closed_is_history BEFORE UPDATE ON followups
  WHEN OLD.closed_at IS NOT NULL
  BEGIN SELECT RAISE(ABORT, 'a closed follow-up is history'); END;

-- Follow-up history is evidence of what we sent a customer.
CREATE TRIGGER fu_never_deleted BEFORE DELETE ON followups
  BEGIN SELECT RAISE(ABORT, 'follow-ups are never deleted'); END;
```

### 3.4 `followup_drafts` — decision 15

```sql
CREATE TABLE followup_drafts (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  followup_id   INTEGER NOT NULL,
  attempt       INTEGER NOT NULL,
  verdict       TEXT    NOT NULL,        -- SEND|DO_NOT_SEND|WAIT|ESCALATE_TO_HUMAN
  reason        TEXT    NOT NULL,        -- the AI's own reasoning, shown to the approver
  body          TEXT,                    -- the proposed message (SEND only)
  trigger_note  TEXT    NOT NULL,        -- why this conversation came up now
  model         TEXT,
  created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
  status        TEXT    NOT NULL DEFAULT 'pending',  -- pending|approved|rejected|expired|sent
  decided_at    TEXT,
  decided_by    TEXT,
  decided_note  TEXT
);
CREATE UNIQUE INDEX idx_draft_pending ON followup_drafts(followup_id) WHERE status = 'pending';
CREATE INDEX idx_draft_status ON followup_drafts(status, created_at);
```

One pending draft per follow-up, again enforced by the database.

### 3.5 `followup_sends` — what the customer actually received

```sql
CREATE TABLE followup_sends (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  followup_id   INTEGER NOT NULL,
  draft_id      INTEGER,
  attempt       INTEGER NOT NULL,
  sent_at       TEXT    NOT NULL DEFAULT (datetime('now')),
  channel       TEXT    NOT NULL,
  phone         TEXT    NOT NULL,
  body          TEXT    NOT NULL,        -- exactly what went out
  wa_message_id TEXT,
  decided_by    TEXT    NOT NULL         -- 'staff:<name>' in draft mode, 'ai' in auto mode
);
CREATE TRIGGER fus_never_deleted BEFORE DELETE ON followup_sends
  BEGIN SELECT RAISE(ABORT, 'sent messages are never deleted'); END;
```

### 3.6 `followup_events` — decision 17

```sql
CREATE TABLE followup_events (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  followup_id    INTEGER,
  conversation_id INTEGER,
  event          TEXT NOT NULL,
  detail         TEXT,
  actor          TEXT NOT NULL DEFAULT 'system',
  created_at     TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_fue_fu ON followup_events(followup_id, id);
```

Event vocabulary — every one of the eleven the decision names:
`created`, `evaluated`, `skipped`, `drafted`, `approved`, `rejected`, `sent`,
`failed`, `cancelled`, `opted_out`, `closed`.

## Part 4 — Workers and jobs

| Job | Interval | Does | Never does |
|---|---|---|---|
| `followup_scan` | 300s | Pure SQL. Finds quiet conversations, opens `followups` rows with `due_at` | Call the AI. Send |
| `followup_evaluate` | 300s | For each due row: builds context, asks the AI, writes a `followup_drafts` row | Send |
| `followup_send` | 300s | Sends **approved** drafts only; claims the echo in `EvoWebhookGuard` first | Send anything not approved (until decision 16 flag is on) |
| `followup_close` | 300s | Closes rows whose customer replied, who were taken over, or who opted out | Send |

All four register in `cron/master.php` and are no-ops unless `followup_enabled`
is set. Absent config = today's behaviour exactly.

The split matters: `followup_scan` is cheap enough to run every cycle because
it touches no network and no model. Only rows it opens ever cost a model call.

## Part 5 — Gates, in order

Evaluated **before** the model is consulted, so a broken or expensive brain can
never cause a wrong send. All pure functions in `lib/FollowUpPolicy.php`.

| # | Gate | Outcome |
|---|---|---|
| 1 | `followup_enabled` is off | Nothing happens at all |
| 2 | Phone or client in `contact_optouts` | Close `opted_out`, log, never send |
| 3 | Customer messaged within the active window | Skip — there is a live conversation |
| 4 | `state = human_active` | Close `human_closed` |
| 5 | Open follow-up already exists | Cannot happen — the database refuses it |
| 6 | `attempts >= max_attempts` | Close `exhausted` |
| 7 | Thread matches `EmailReplyPolicy::ESCALATION_WORDS` | Close `escalated`, flag for a human |
| 8 | `crm_link_method = 'ambiguous'` | Skip — identity is not safe for proactive contact |
| 9 | Outside 08:00–20:00 Kampala, or Sunday | Defer `due_at`, do not close |
| 10 | Daily per-channel cap reached | Defer |
| 11 | **Then** ask the AI | It may still answer `DO_NOT_SEND` or `WAIT` |
| 12 | Draft mode (default) | Write a draft. A human decides |

## Part 6 — The AI contract

Input: the whole relevant thread, the CRM state where identity is trustworthy,
the enquiry context, and the attempt number. Output, strictly:

```json
{
  "verdict": "SEND" | "DO_NOT_SEND" | "WAIT" | "ESCALATE_TO_HUMAN",
  "reason": "one sentence, shown to the approver",
  "sales_stage": "enquired|quoted|deciding|objection|ready|unknown",
  "product": "what they were actually asking about, or null",
  "objection": "what is holding them back, or null",
  "message": "the follow-up text — required for SEND, null otherwise",
  "next_due_hours": 48
}
```

`DO_NOT_SEND` closes the row. `WAIT` re-arms `due_at` without spending an
attempt. `ESCALATE_TO_HUMAN` closes the row and raises a staff alert.

## Part 7 — Admin surface

**Screen: Engage → Follow-ups** (`tabs/engage/followups.php`)

Pending drafts, showing everything decision 15 requires: customer or
conversation, the prior thread, why the follow-up triggered, the AI's verdict
and reason, the proposed message, which attempt it is, the scheduled send
time, and the CRM identity **with its provenance**. Approve, edit-and-approve,
or reject with a note. Plus tabs for open follow-ups, sent history, and the
opt-out list. A kill switch that closes every open follow-up at once.

**APIs** (`includes/api/api_followups.php`, admin-authenticated):

| Method | Path | Does |
|---|---|---|
| `GET` | `?action=drafts` | Pending drafts with full context |
| `POST` | `?action=approve` | Approve a draft (optionally with edited text) |
| `POST` | `?action=reject` | Reject with a reason |
| `POST` | `?action=cancel` | Close a follow-up |
| `GET` | `?action=history` | Sends and events for one conversation |
| `POST` | `?action=optout` | Add or lift an opt-out |

**CLI:** `tools/followup_doctor.php` — read-only. Which conversations would be
picked up, which gate stopped each one, what the AI decided, and what is
queued. The `binding_doctor` pattern: a check that could not run must report
UNKNOWN, never a pass.

## Part 8 — Build order

1. **Opt-out** — table, `lib/ContactOptOut.php`, STOP-keyword detection on
   inbound, and the gate wired into every automatic outbound path. Shipped and
   verifiable before any proactive send exists. *(Decision 5.)*
2. **Provenance** — the two columns, set in all four linking paths.
3. **Schema** — `followups`, drafts, sends, events, with the indexes and
   triggers that make the guarantees structural.
4. **Policy** — `lib/FollowUpPolicy.php`, pure and fully tested.
5. **Scan** — SQL only.
6. **Evaluate** — AI verdict → draft.
7. **Admin screen + APIs** — approve/reject.
8. **Send** — approved drafts only, with the `EvoWebhookGuard` claim.
9. **Doctor + docs.**

Automatic sending (decision 16) is not in this build. It arrives only after
draft mode has been run against real conversations and the messages read
right.
