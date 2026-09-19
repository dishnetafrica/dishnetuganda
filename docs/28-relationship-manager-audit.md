# DishNet AI — Relationship Manager / Sales AI Audit

**Date:** 18 September 2026 · **Against:** 5.18.22 (`14b6ef4`)
**Scope:** Is the AI behaving as a professional ISP Relationship Manager, or only
answering WhatsApp messages?
**Status:** AUDIT + ARCHITECTURE ONLY. No code written, nothing committed, no
configuration, knowledge base, follow-up timing or workflow changed, no customer
messaged.

---

## The one-paragraph answer

**It is a very good conversational sales bot with a human-approved follow-up
mechanism that is switched off, plus a second, independent hot-lead alert system
that is also switched off.** It is not a Relationship Manager today. But the
reason is not missing architecture — most of the machinery you are asking for
already exists, in two places, and neither is running. **The most valuable thing
this audit can tell you is: do not build a third one.**

---

## A. CURRENT STATE — what happens today

### A.1 The lifecycle you asked me to trace

Customer messages → AI responds → asks questions → shows interest → goes quiet →
never buys. Here is what actually happens, verified in code:

| Step | What happens | Evidence |
|---|---|---|
| Message arrives | Evolution → `evo_webhook.php` → `EventBus::emit('ai.reply')` | `evo_webhook.php` |
| AI answers | `AiReplyWorker` → `DishNetAiBrain` → `ReplyPrivacyGuard` → Evolution | `workers/AiReplyWorker.php:81` |
| Conversation stored | `wa_conversations` + `wa_messages`, full transcript | `ConversationService` |
| Lead captured | **Only if** the model emits `<<LEAD {...}>>` **and** `ai_lead_capture` is on **and** the qualification floor passes | `AiLeadService::capture()` |
| Lead → uCRM | `crm.lead.sync` → `UcrmLeadWorker` → uCRM lead client | `UcrmLeadSync` (5.18.19/20) |
| Customer goes quiet | **`followup_scan.php` would open a follow-up row — but returns immediately unless `followup_enabled` is set** | `cron/followup_scan.php:31` |
| Follow-up drafted | `followup_run.php` → `FollowUpEvaluator` → a **draft** | `cron/followup_run.php` |
| Follow-up sent | **Only after a human approves it in the admin screen** | `cron/followup_send.php` |
| Hot lead alerted | `AlertService::notify()` → one number in `alert_whatsapp` — **empty means off** | `lib/AlertService.php:39` |
| Lead never converts | Row sits in `leads.json` / uCRM as a lead. **Nothing else happens.** | — |

**So the honest answer to "what happens next?" is: today, in practice, nothing
proactive.** The conversation is remembered in full. The lead may be recorded.
No follow-up is sent, because the feature is off and, even when on, it requires
a person to approve every message.

### A.2 Your checklist, answered literally

| Does it… | Today |
|---|---|
| remember the lead? | **Yes** — `leads.json` + uCRM lead client |
| remember what they wanted? | **Yes** — `requirement`, `recommended_solution`, `recommended_plan` |
| remember their location? | **Yes** — typed `location`, plus GPS from a pin (5.18.18), written to uCRM `gpsLat`/`gpsLon` |
| remember household/business size? | **Yes** — `users_devices`, `customer_type` |
| remember which product? | **Yes** — `recommended_solution`, `recommended_hardware` |
| remember objections? | **Partly** — `FollowUpEvaluator` extracts `objection`, but only while a follow-up is evaluated. **Not on the lead record.** |
| remember quoted price? | **No** — not a lead field. `quotes_log.json` exists but is not linked to the lead |
| remember if they asked for installation? | **Partly** — only inside free-text `requirement`/`ai_summary` |
| remember when they intended to buy? | **No field.** `next_due_hours` is a follow-up timer, not a stated purchase date |
| remember what stopped the purchase? | **No** — `objection` is transient, see above |
| assign a lead stage? | **Partly** — `FollowUpEvaluator` returns one of `enquired · quoted · deciding · objection · ready`, stored on the *follow-up*, not the lead |
| calculate lead temperature? | **In n8n only** (`Is HOT?`), and n8n is inactive. **Not in the plugin.** |
| schedule a follow-up? | **Yes, when enabled** — `followups.due_at` |
| send a follow-up? | **Only with human approval** |
| change it based on the conversation? | **Yes** — the evaluator reads the whole thread |
| stop if the customer says no? | **Yes** — `ContactOptOut` + `DO_NOT_SEND` |
| escalate hot leads to a human? | **Three separate mechanisms exist, all dormant.** See H |
| notify sales staff? | Same — see H |

---

## B. WHAT IS GOOD — keep all of this

Several properties here are better than what most ISPs run, and a redesign must
not lose them.

**B.1 Provenance decides content.** `FollowUpPolicy` distinguishes *answering
someone who wrote to us* from *choosing to message someone*:

```
verified / manual / ai_identified  →  CONTENT_ACCOUNT  (invoices, service, balance)
phone_tail / bulk_rematch / none   →  CONTENT_ENQUIRY  (only what they asked about)
ambiguous                          →  CONTENT_NONE     (no proactive contact at all)
```

The reasoning is recorded in the file: *"A WhatsApp number matching a uCRM
customer on its last nine digits is evidence, not proof. It is good enough to
answer whoever just wrote to us… It is not good enough to START a conversation,
because then WE choose the recipient and a wrong guess puts an account balance in
a stranger's hand."* **This is the single best idea in the system.**

**B.2 Gates run before the AI.** `FollowUpPolicy` is pure — no I/O, no model call.
So a broken, slow or expensive brain can never be the reason a customer is
messaged wrongly.

**B.3 Every transition is audited.** `followup_events` records `created ·
evaluated · skipped · drafted · approved · rejected · sent · failed · cancelled ·
opted_out · closed`. Months later you can answer *why did this person get this
message* with something better than "the AI thought so".

**B.4 Opt-out is real and layered.** `ContactOptOut` has `STOP_WORDS`, two scopes
(`proactive`, `all`) and four message classes (`reply`, `transactional`,
`proactive`, `staff`), and it gates every automatic outbound path.

**B.5 Failure is silence.** `FollowUpEvaluator::refuse()` — *"Every failure lands
here, and every failure is silence."* A malformed model response sends nothing.

**B.6 Timezone is already correct, and your brief was wrong about it.** You asked
me to verify rather than assume South Sudan, which was the right instinct.
`FollowUpPolicy::TZ = 'Africa/Kampala'`, resolved per install via `dn_tz()`, with
this note in the source: *"Uganda and South Sudan are NOT the same offset… South
Sudan left EAT on 31 January 2021 and Africa/Juba has been CAT (UTC+2) ever
since, while Africa/Kampala is EAT (UTC+3)."*

**B.7 The quiet window is stricter than your stated preference.** You said no
follow-ups 22:00–08:00. The code enforces **08:00–20:00** (`HOUR_OPEN = 8`,
`HOUR_CLOSE = 20`) — a narrower, more courteous window. Keep it.

**B.8 Attempts are hard-capped in code.** `FollowUpService::MAX_ATTEMPTS = 2`,
*"regardless of configuration"*. A misconfiguration cannot produce a spam bot.

---

## C. WHAT IS MISSING

### C.1 THE HEADLINE — two dormant systems, and you were about to commission a third

This is the finding that should change your plan.

| System | What it does | State |
|---|---|---|
| **Plugin follow-up engine** | scan → evaluate → draft → **human approve** → send. 2 attempts at 24h/72h, 08:00–20:00, provenance-gated, fully audited | `followup_enabled` **OFF** |
| **n8n hot-lead alerts** | `Is HOT?` → Redis dedup lock → `Build HOT Recipients` → `Notify Sales (HOT)` via Evolution. Also handoff pings, watchdog alerts, daily digest | workflow **`active: false`**, and every recipient list is `[]` |
| **Plugin `AlertService`** | one-number WhatsApp alert with per-key cooldowns | `alert_whatsapp` **empty** |
| **Plugin `cron_lead_alerts.php`** | 3-tier: instant assignment alert → 45-min warning → 60-min supervisor escalation | `lead_alert_enabled` defaults true, but depends on lead assignment being used |

**You asked for §17 (sales-manager hot-lead alerts) and §18 (notify an internal
number). Both are built. Twice.** The n8n node comment even reads:

> `// HOT-only sales alert. WARM/COLD stay silent (dashboard only).`
> `const numbers = [];  // REQUIRED: Uganda staff numbers, e.g. ["2567XXXXXXXX"]. Empty means this alert sends to nobody.`

So there is already a lead-temperature model *and* a hot-lead alert path *and* a
deliberate decision that only HOT interrupts a human. It sends to nobody because
nobody filled in the array.

**Recommendation: switch one on and measure it before designing anything.**

### C.2 The lead record forgets what the follow-up engine learns

`AiLeadService::FIELDS` stores 15 fields; `FollowUpEvaluator` derives
`sales_stage`, `product`, `objection`, `next_due_hours` — and writes them to the
**follow-up row**, not the lead. When the follow-up closes, the sales
intelligence goes with it.

Against your 25-field wishlist:

| Field | Status |
|---|---|
| Lead ID, name, WhatsApp, location, customer type, product/plan interest, household size, use case | **EXISTS** (`FIELDS` + `TRUSTED_FIELDS`) |
| Last conversation / last message / last AI response | **EXISTS** — `wa_messages`, full transcript |
| Lead stage | **PARTIAL** — on the follow-up, not the lead |
| Objections | **PARTIAL** — same |
| Lead temperature | **MISSING** in the plugin (exists in dormant n8n) |
| Budget | **MISSING** |
| Purchase intent / expected purchase date | **MISSING** — the biggest single gap |
| Last quoted price / quotation status | **MISSING** as a link (`quotes_log.json` is not joined to the lead) |
| Installation status | **MISSING** |
| Follow-up stage / next date / reason | **EXISTS** on the follow-up row |
| Assigned salesperson | **PARTIAL** — lead assignment exists in `cron_lead_alerts.php` |
| Human takeover status | **EXISTS** — `wa_conversations.state = 'human_active'` |
| Do-not-contact | **EXISTS** — `ContactOptOut` |

### C.3 No funnel observability

`ReportingService` is financial only — `sales()`, `receivable()`,
`paymentsReceived()`, `grossMargin()`, `salesByProduct()`. **There is no lead
funnel report at all**: no new/active/hot/warm/cold counts, no follow-ups due or
sent, no reply rate, no lead→quote→payment conversion, no time-to-purchase.
`tools/followup_doctor.php` inspects one pipeline's health, which is not the same
thing.

### C.4 No Next Best Action

The evaluator answers one question — *send a message, or not?* — with verdicts
`SEND · DO_NOT_SEND · WAIT · ESCALATE_TO_HUMAN`. There is no notion of *the most
useful next action might not be a message at all*: send a quotation, offer an
installation date, arrange a survey, wait until a stated date, hand to a person.

### C.5 No post-purchase relationship

Lifecycle emails exist (welcome, activation, invoice, pause, resume). There is no
first-week check, no satisfaction touch, no renewal nudge, no upsell trigger
driven by actual service data.

### C.6 No dormant-lead reactivation

Nothing revisits a lead after the two attempts. `MAX_ATTEMPTS = 2` closes it
permanently; there is no 90-day reactivation concept.

---

## D. RELATIONSHIP MANAGER MODEL

The principle you wrote is the right one and should be stated in the prompt
itself: *"My job is not to sell at every message. My job is to understand the
customer, help them, maintain the relationship, identify buying intent, and take
the correct next action."*

Operationally that means three rules:

1. **Every proactive message must name a reason that came from the customer.**
   If the system cannot say *why* it is writing, in terms the customer would
   recognise, it does not write. This is already half-implemented — the evaluator
   returns `reason` — it is simply not enforced as a gate.
2. **Usefulness before asking.** A follow-up that carries information (a plan
   comparison, an installation requirement, a quotation) earns the next reply.
   "Are you ready to buy?" spends it.
3. **The relationship outlives the transaction.** Two attempts then permanent
   silence is not a relationship; it is a short sales sequence. Reactivation is
   what makes it a relationship — see N for the compliance limits.

---

## E. LEAD LIFECYCLE — the state machine

**What exists today:** five stages, on the follow-up row, derived by the model:
`enquired · quoted · deciding · objection · ready` (`FollowUpEvaluator::stage()`),
plus `unknown`. uCRM separately carries `isLead: true` → client.

**Proposed** (`PROPOSED`, nothing built):

```
UNKNOWN ──► NEW_ENQUIRY ──► QUALIFIED ──► INTERESTED ──► QUOTED
                                                           │
                              ┌────────────────────────────┤
                              ▼                            ▼
                      DECISION_PENDING ──────────► PAYMENT_PENDING
                              │                            │
                              ▼                            ▼
                           DORMANT ◄── LOST            PURCHASED
                              │                            │
                              └──► REACTIVATED             ▼
                                                     INSTALLATION
                                                           ▼
                                                    ACTIVE_CUSTOMER
                                                     ▼          ▼
                                                 RENEWAL     UPSELL
                                                     ▼
                                                 RETENTION
```

**The boundary that matters (your §12):** everything up to and including
`PAYMENT_PENDING` is a **lead** and lives in the plugin. From `PURCHASED` onward
it is a **customer** and **uCRM is the source of truth** — client, service,
invoice, payment, ticket. The transition is `UcrmLeadSync` converting the lead
client into a client, and `CrmApiClient::post()` already performs that conversion
as a documented side effect for a payload carrying `clientId`.

After that point the AI must stop using lead data for anything factual and read
uCRM through `CustomerDataTools`. Mixing the two is how a customer gets told
about a plan they were quoted six months ago instead of the one they are on.

---

## F. FOLLOW-UP ENGINE

### F.1 Exactly what is implemented today

| Property | Value | Source |
|---|---|---|
| Stages | 2 attempts, hard-capped | `FollowUpService::MAX_ATTEMPTS = 2` |
| Delays | attempt 1 at **24h**, attempt 2 at **72h** after the customer's last message | `FollowUpPolicy::SCHEDULE = [1=>24, 2=>72]` |
| Quiet hours | send only **08:00–20:00** | `HOUR_OPEN`/`HOUR_CLOSE` |
| Timezone | per install via `dn_tz()`; Uganda `Africa/Kampala` | `FollowUpPolicy::TZ` |
| Trigger | conversation gone quiet, found by SQL only — no model call | `cron/followup_scan.php` |
| Message logic | `FollowUpEvaluator` reads the whole thread, returns a verdict + drafted message | `lib/FollowUpEvaluator.php` |
| Data used | thread, content level, account facts *only if provenance allows* | `FollowUpPolicy::contentLevel()` |
| Stop conditions | customer replied · opted out · human active · ambiguous identity · `DO_NOT_SEND` · max attempts | `followup_close.php`, `ContactOptOut` |
| Escalation | verdict `ESCALATE_TO_HUMAN` | `FollowUpEvaluator::VERDICTS` |
| **Sending** | **requires human approval — no automatic path exists** | `cron/followup_send.php` |
| Cadence | scan/run/close every 600s, send every 300s | `cron/master.php:219-222` |
| Master switch | `followup_enabled` — **off** | all four crons line ~31 |

The source is explicit that this is deliberate: *"There is no path in this file
from 'the AI decided' to 'the customer received'… Automatic sending is a later
milestone and will be a different decision, not a flag flipped here."*

### F.2 What to change — `PROPOSED`

**Do not replace this engine.** Extend it in four ways:

1. **Reason-driven scheduling, not fixed 24h/72h.** The delay should come from
   the reason (§F.3), not a constant. A customer who said "next month" should not
   be written to tomorrow — today they would be.
2. **A stated-date field.** `expected_purchase_date` on the lead, honoured by the
   scheduler. This is the single highest-value missing field.
3. **Persist the evaluator's findings to the lead**, not just the follow-up:
   `sales_stage`, `objection`, `product`, `temperature`.
4. **Graduated reactivation** beyond attempt 2, subject to N.

### F.3 Reason → timing → message shape

| Reason | Wait | Message should |
|---|---|---|
| `price_enquiry` | 48h | offer the comparison they did not get |
| `quote_pending` | 72h | ask if the quotation was clear, offer to revise |
| `considering_plan` | 72h | give the one fact that decides it |
| `comparing_plans` | 48h | a direct comparison, not a re-pitch |
| `awaiting_approval` (spouse/boss) | 5 days | acknowledge the decision-maker explicitly |
| `awaiting_funds` (salary) | to stated date | nothing before it |
| `future_date` | to stated date − 2 days | reference their own words |
| `installation_not_scheduled` | 24h | offer specific dates |
| `abandoned_order` | 2h, then 24h, then 72h | resume, not restart |
| `callback_requested` | at requested time | keep the promise |
| `technical_unresolved` | 24h | the answer, not a sales ask |
| `interested_inactive` | 7d then 30d | useful information only |

---

## G. NEXT BEST ACTION ENGINE — `PROPOSED`

Replace the binary *send / do not send* with a decision over a fixed action set.
Keeping the set **closed and enumerable** is what makes it testable, and mirrors
`CustomerDataTools::catalogue()`, which is the pattern that already works here.

```
answer_question · send_quotation · send_plan_comparison · ask_location
arrange_site_survey · offer_installation_date · send_payment_link
escalate_to_human · wait_until_date · follow_up_in(n days)
stop_messaging · mark_dormant · reactivate_later
```

Two design rules, both learned from existing code:

- **The engine proposes; the gates dispose.** `FollowUpPolicy` still runs first
  and can veto any action. The model never decides whether contact is permitted,
  only what would be most useful if it is — exactly as the model never decides
  which customer it is authorised to read.
- **An action the system cannot perform resolves to nothing**, logged, like
  `MediaLibrary::find()` returning no file for an invented photo name. Never to a
  nearest guess.

---

## H. HUMAN ESCALATION

### H.1 What exists — three independent mechanisms, all dormant

1. **`AiReplyWorker::escalate()`** → `EventBus::emit('wa.escalation')`, fired by
   the `<<ESCALATE>>` marker.
2. **`AlertService::notify($key, $text, $cooldownMin)`** → one WhatsApp number in
   `alert_whatsapp`, with per-key cooldowns. *"Alerts people learn to ignore are
   worse than none."* Empty number = off; the preflight warns.
3. **`cron_lead_alerts.php`**, every 5 minutes — assignment alert → 45-minute
   warning → 60-minute supervisor escalation (`lead_supervisor_phone`).
4. **n8n** `Is HOT?` → Redis lock → `Notify Sales (HOT)` via Evolution. Inactive.

### H.2 When to escalate — `PROPOSED`, consolidating your list

Immediate: customer ready to pay · explicitly asks for a person · business with a
real public-IP requirement · multi-site · NGO/corporate · Starlink enterprise or
POC · large installation · complex networking · unhappy customer · AI confidence
low · requirement outside the knowledge base (the `tbc` holding line already
marks these).

Not escalation: an ordinary price question, a plan comparison, a coverage
question. Escalating those trains the team to ignore alerts, which is the failure
`AlertService`'s cooldown design already anticipates.

---

## I. THE +21192797217 ALERT SYSTEM

### I.1 First — the number you gave me is not valid, and I would not configure it

```
+21192797217  →  digits: 21192797217  (11)
country code 211 = South Sudan
national part    = 92797217  (8 digits)
South Sudan mobile numbers carry 9 digits after 211  (e.g. 921443009)
```

**It is one digit short.** Worse, it fails silently rather than loudly:
`CustomerIdentity::significant()` takes the last nine digits and returns
`'192797217'` — **eating a digit of the country code** and producing a plausible
but wrong national number. `MIN_SIGNIFICANT` is 9, so the value passes the length
check while meaning something different from what you intended.

**Two further concerns before this number is used at all:**

- **It is a South Sudan number for a Uganda sales alert.** Your own n8n README
  records this exact failure: *"on 10 September, Uganda's handover alerts had
  been going to a +211 number for weeks because the alert number was inherited
  from the Sudan config."* The n8n node comment expects `2567XXXXXXXX`.
- **It must not be one of the plugin's own WhatsApp numbers.** The README again:
  an alert sent to `256703834115` or `256705993348` *"arrives as a customer
  message, gets answered, and the answer lands back on the sender."*

**Action: confirm the full, correct number before anything is configured.** If
the Uganda sales manager is the recipient, it should almost certainly be a `+256`
handset.

### I.2 Can the AI send such a message? — Yes, four ways, three already built

| Path | Exists | Assessment |
|---|---|---|
| **A** AI → Evolution → number | **Yes** — `AlertService::notify()` | Works, but the send decision sits inside the reply worker |
| **B** AI → n8n → Evolution → number | **Yes** — `Is HOT?` → `Notify Sales (HOT)`, with Redis dedup | **Recommended.** See below |
| **C** AI → DishNet integration API → n8n → number | Partly — `EventBus` is the API | Extra hop, no extra safety |
| **D** AI → uCRM → automation → number | No | uCRM notifications are not built for this; adds a dependency on billing for a sales alert |

### I.3 Recommendation — your instinct was right, with one correction

**Adopt B, and your reasoning for it is sound**: a separate workflow is easier to
audit, log, retry and expand to several salespeople, and it keeps an internal
WhatsApp number out of the reply path.

The correction: **B is already implemented in n8n and switched off** — the
workflow is `active: false` and `Build HOT Recipients` is `numbers = []`. You do
not need it designed. You need it enabled, pointed at a verified number, and
watched. It already has the two properties that matter: a Redis dedup lock so one
lead produces one alert, and a HOT-only rule so warm and cold stay on the
dashboard.

**But B has a precondition you must settle first.** n8n's hot-lead detection runs
inside the n8n *conversation* bot — the one that must not run at the same time as
the plugin brain, or every customer gets two replies. So you cannot simply switch
n8n on for alerts while the plugin answers.

Two honest options:

- **B-now (smallest step):** use the plugin's `AlertService` — set
  `alert_whatsapp` to the verified number. One config value, working code,
  cooldowns included, no second conversation engine. Accept that the send
  decision lives in the reply worker.
- **B-proper (target):** extract n8n's alert branch into a **separate workflow**
  with no conversation nodes, triggered by a webhook the plugin calls on
  `wa.escalation`. That gives your clean separation without two bots answering
  customers. This is a small n8n job, not a rebuild.

**Recommended sequence: B-now to get alerts flowing this week, B-proper when you
want multiple salespeople and routing.**

### I.4 The message format

Generating your §20 template automatically is straightforward — every field is
already available at escalation time (`AiLeadService::FIELDS` + the conversation
row + the evaluator's `sales_stage`/`objection`). Two constraints:

- **It is a staff message, so it is class `staff` in `ContactOptOut`** — not
  `proactive`. A salesperson must not be able to STOP themselves out of work, and
  a customer opt-out must never suppress an internal alert.
- **Never put account or financial data a salesperson would not otherwise see
  into it.** The lead's own stated requirement, yes. A uCRM balance, no —
  `ShopBotPayload`'s default-deny reasoning applies to internal recipients too.

---

## J. uCRM INTEGRATION — where information belongs

| Data | Owner | Note |
|---|---|---|
| Customer, service, invoice, payment, ticket | **uCRM** | Source of truth. Already true |
| Lead before purchase | **Plugin** (`leads.json`) + a uCRM lead client | Already true since 5.18.19 |
| Conversation transcript | **Plugin** (`wa_conversations`/`wa_messages`) | Already true |
| Follow-up state + audit | **Plugin** (`followups`, `followup_events`) | Already true |
| Lead stage / temperature / objection / expected date | **Plugin lead record** | `PROPOSED` — today scattered or missing |
| Quotation | **uCRM quote** + `quotes_log.json` | Link them — `PROPOSED` |
| Opt-out | **Plugin** (`ContactOptOut`) | Already true |
| Sales alert history | **Plugin** (`alert_locks.json`) or n8n execution log | Either, not both |

**Your "no duplicate databases" requirement is already satisfied for customers.**
There is exactly one customer record and it is in uCRM. Google Sheets and
CloudBSS do not appear anywhere in this repository — if either holds live
customer data, that is where the duplication actually is, and I still cannot see
them (`UNKNOWN — NEEDS VERIFICATION`, as in docs/27 §10).

**The AI currently knows, for an identified customer:** account status, plan,
service status and period end, balance, latest invoice, last payment — five
tools, via `CustomerDataTools::BRAIN_TOOLS`. It deliberately does **not** get
customer ID or service ID. Installation status is not currently exposed.

---

## K. N8N INTEGRATION — what n8n should own

**Own:** scheduled orchestration and fan-out — the hot-lead alert workflow,
digests, watchdogs, multi-recipient routing, retries with visible execution logs.

**Not own:** live conversation (one engine only), the customer record, follow-up
state (the plugin's audit trail is stronger), business writes to uCRM.

**Retire:** the 139-node conversation bot, *if* the plugin brain stays. Keeping it
as a fallback means keeping a second prompt, a second price path and a second set
of staff lists in sync forever.

---

## L. WHATAI INTEGRATION

As established in docs/27 and confirmed by you: **"WhatAI" is your own
`DishNetAiBrain`.** It owns conversation, and should also own the *judgement*
calls — intent, temperature, next best action, draft wording — while owning none
of the *permission* calls, which stay in `FollowUpPolicy`, `ContactOptOut` and
`CustomerDataTools`.

If an external brain is ever adopted, the `shopbot_ai_url` seam and
`ShopBotPayload::CONTRACT` already define exactly what it may see.

---

## M. SECURITY

Unchanged from docs/27 and it holds here: identity is resolved by the server,
tools are scoped to the authenticated customer, the model never chooses whose
data it reads, and `ReplyPrivacyGuard` checks every outbound reply.

Three additions specific to proactive selling:

1. **Proactive contact is a higher bar than replying**, and
   `FollowUpPolicy::contentLevel()` already encodes it (B.1). Any new engine must
   route through it rather than around it.
2. **Internal alerts are class `staff`** (I.4) — separate from customer opt-out
   in both directions.
3. **A lead record is PII.** `AiLeadService::FIELDS` is an allowlist and
   `clean()` drops anything else; `TRUSTED_FIELDS` deliberately keeps GPS out of
   model-supplied values. Adding `budget` or `decision_maker` means adding
   personal data — collect only what changes the next action, per your §14.

---

## N. WHATSAPP COMPLIANCE

`REQUIRES VERIFICATION` on the specifics, but the shape is clear and it
constrains D and F.3 materially.

- **You are on Evolution API** (Baileys-style instances), **not the WhatsApp
  Business Platform.** Meta's template and 24-hour-window rules are enforced by
  the Cloud API, which you do not use. That does **not** make unsolicited
  messaging safe — it moves the risk from policy rejection to **account banning**,
  which is worse because there is no appeal queue and the number is your sales
  line.
- **Practical rule: proactive messages only to people who wrote to you first, and
  only about what they asked.** That is exactly what `CONTENT_ENQUIRY` encodes.
- **Dormant reactivation at 90 days (your §29) is the riskiest item in your
  brief.** It is a message to someone who has not written in three months. Before
  building it: cap volume per day, require the original enquiry to be evidenced,
  honour opt-out permanently, and make the message genuinely useful rather than a
  re-pitch. `REQUIRES VERIFICATION` — confirm your tolerance for the ban risk on
  a number that is also your inbound sales line.
- Migrating to the Cloud API would change this calculus, and is a separate
  programme (docs/27 §14).

---

## O. TEST PLAN — `PROPOSED`

The suite already has `test_followup_policy.php`, `test_followup_lifecycle.php`,
`test_ai_lead_capture.php`, `test_lead_matcher.php`, `test_ucrm_lead_sync.php`.
Extend in the house style (`t()/is_()/ok()/bad()`, fakes over mocks).

**Per-scenario, table-driven:** new enquiry · qualified · hot · warm · cold · no
response · price objection · technical objection · future purchase date · quote
generated · payment pending · abandoned order · purchased · says no · asks not to
be contacted · human takeover · internal escalation.

**The assertions that actually protect you:**

| Test | Asserts |
|---|---|
| Stated future date honoured | a lead saying "next month" gets **nothing** before that date — today's fixed 24h/72h would write tomorrow |
| Opt-out beats everything | `STOP` then a HOT verdict sends **zero** customer messages |
| Human active beats everything | no follow-up while `state = 'human_active'` |
| Provenance gate | `phone_tail` lead never receives account facts |
| Quiet hours | 20:01 Kampala sends nothing; 08:00 does |
| Max attempts | attempt 3 is impossible even with config set absurdly |
| **Escalation targeting** | a HOT lead produces **exactly one** alert to the configured number, and **a malformed number produces a loud failure, not a silent wrong send** — see I.1 |
| Alert dedup | the same lead triggering twice inside the cooldown produces one alert |
| Staff class | a customer opt-out does **not** suppress an internal alert; a staff STOP does not suppress customer replies |
| Lead↔customer boundary | once `PURCHASED`, facts come from uCRM, never the lead record |

---

## P. IMPLEMENTATION ROADMAP

Ordered so that nothing new is built until what exists has been measured. No
phase starts without your approval.

### Phase 0 — Verify and switch on what exists *(no code)*
1. **Confirm the correct sales number** (I.1). Nothing else in this section
   proceeds until that is settled.
2. Set `alert_whatsapp` to it. Hot-lead alerts start flowing — working code, no
   build.
3. Turn `followup_enabled` **on** with sending still human-approved. Watch the
   draft queue for a week.
4. Run `tools/followup_doctor.php` to confirm the pipeline is healthy.

**Risk:** very low. **Rollback:** blank the config values.
**Why first:** you may discover the existing engine is most of what you wanted.

### Phase 1 — Lead record remembers what the evaluator learns
Persist `sales_stage`, `objection`, `product`, `temperature` to the lead. Add
`expected_purchase_date`. Link `quotes_log.json` to the lead.
**Risk:** low, additive. **Test:** fields survive follow-up closure.

### Phase 2 — Reason-driven timing
Replace `SCHEDULE = [1=>24, 2=>72]` with the reason→wait table (F.3), honouring
`expected_purchase_date`. Keep `MAX_ATTEMPTS`, quiet hours and all gates.
**Risk:** medium — this changes when real customers are messaged. Pilot on one
number. **Rollback:** revert to the constant.

### Phase 3 — Funnel observability
A lead funnel report in `ReportingService`: counts by stage and temperature,
follow-ups due/sent, reply rate, lead→quote→payment conversion, time to purchase.
**Risk:** none — read-only. **Do this before Phase 4**, so automatic sending is
judged on numbers.

### Phase 4 — Next Best Action
The closed action set (G), still proposing into the existing gates.
**Risk:** medium. **Rollback:** fall back to the binary verdict.

### Phase 5 — B-proper alert workflow
Extract n8n's alert branch into a standalone workflow with no conversation nodes,
triggered by the plugin on `wa.escalation`. Multi-salesperson routing.
**Risk:** low, provided the n8n *conversation* bot stays inactive.

### Phase 6 — Automatic sending *(a decision, not a flag)*
Only after Phase 3 gives you reply rates and complaint counts. The source already
frames this correctly: *"Automatic sending is a later milestone and will be a
different decision."*

### Phase 7 — Post-purchase and reactivation
First-week check, renewal nudge, data-driven upsell. Dormant reactivation only
after N is settled.

### Not scheduled
Relationship score (§32). **My recommendation: do not build it.** Lead
temperature plus next-best-action plus the funnel report answer every operational
question you listed. A composite score would be a number nobody can act on, and
your own brief says not to make this unnecessarily complicated.

---

## Fifteen scenarios (§9)

Format: *what they said → what is stored → when → what is sent → if they reply /
if they don't → when it stops.* All `PROPOSED` except where marked.

1. **"How much is Starlink?"** → `enquired`, COLD, product Starlink → 48h → the
   plan comparison they never got → reply: qualify → no reply: one more at 7d →
   stops after attempt 2.
2. **"Home, 10 people, tell me the price"** *(the real 18 Sept conversation)* →
   `enquired`→`quoted`, WARM, users 10 → 48h → "you asked about a connection for
   ten people — here is what the total covers" → stops after 2.
3. **"I'll discuss with my husband"** → `deciding`, WARM, objection
   `awaiting_approval` → **5 days**, not 24h → acknowledges the decision-maker
   explicitly → if they reply "still deciding", one further touch at 14d.
4. **"I'll buy next month"** → `deciding`, WARM, `expected_purchase_date` → **at
   that date − 2 days**, nothing before → references their own words.
5. **"Waiting for salary"** → `awaiting_funds` → to the stated date; if none
   given, month-end → payment options, no pressure.
6. **"Can you install tomorrow?"** → `ready`, **HOT** → **immediate human
   escalation**, no automated follow-up → AI holds the conversation until a
   person arrives.
7. **"Where do I send payment?"** → `ready`, **HOT** → escalate + payment details
   *(payment link is GAP 2 in docs/27)*.
8. **Quotation sent, silence** → `quoted` → 72h → "was the quotation clear? I can
   revise it" → then 7d → stops.
9. **Order started, abandoned** → `PAYMENT_PENDING` → **2h**, then 24h, then 72h
   → resume where they stopped, never restart → temperature HOT→WARM→COLD across
   the three → salesperson notified at the second.
10. **"Vodafone/other ISP is cheaper"** → `objection: price_comparison`, WARM →
    48h → what the price includes, not a discount → no discounting authority.
11. **"Do you cover Mukono?"** → `enquired` + location → 24h → coverage answer +
    one qualifying question.
12. **CCTV enquiry** → `qualified`, WARM, `cctv_remote_access` → 24h → per
    5.18.22: lead with Residential 400, public IP quoted separately, hand over.
13. **Business / NGO / multi-site** → **immediate escalation**, no automation.
14. **"I went with another ISP"** → `LOST`, reason captured → **acknowledge
    warmly, stop immediately**, no counter-offer → retained for reactivation only
    subject to N.
15. **"Stop messaging me"** → `ContactOptOut` → **everything stops at once**
    *(EXISTING, already works)* → only a reply from them reopens contact.

---

## §35 — THE HONEST ANSWER

**DishNet's AI is not a Relationship Manager today. It is an unusually
well-engineered conversational sales bot, plus a relationship-manager follow-up
engine that has never been switched on, plus a hot-lead alert system that has
been built twice and is pointed at nobody.**

What genuinely qualifies as relationship-manager behaviour and already exists:
the conversation is remembered in full; leads are captured with requirement,
location, plan and GPS; they reach uCRM idempotently; the evaluator reads a whole
thread and forms a view on stage, product and objection; provenance decides what
may be said; opt-out is honoured everywhere; every decision is audited.

What does not exist: temperature in the plugin, an expected purchase date,
reason-driven timing, next-best-action, funnel visibility, post-purchase
lifecycle, reactivation — and, above all, **anything at all actually happening
when a customer goes quiet**, because the engine is off and its sending path
deliberately requires a person.

The gap between where you are and a real Relationship Manager is **much smaller
than it looks, and almost none of it is new architecture.** It is switching on
what exists, measuring it, and then adding memory (Phase 1) and timing (Phase 2).

The thing most likely to go wrong is not technical. It is building a third
follow-up engine on top of two dormant ones — which is precisely what you said
you wanted to avoid, and precisely what the next step would have been.

---

## What I did not do

No code written. Nothing committed. No configuration, knowledge base, follow-up
timing or WhatsApp workflow changed. No customer messaged. The only artefact is
this document, left untracked for your review.
