# CRM Lead + Quotation Automation — Audit & Proposed Integration

Audit only. **No production code was changed to produce this document**, per your
instruction. Every claim cites the file it came from.

---

## Headline

Almost all of this already exists. There is a lead pipeline with round-robin
assignment, a conversation→lead link, a quotation service that posts to uCRM and
uses uCRM's own numbering, and phone-based duplicate protection.

What does not exist is **the AI touching any of it**. The assistant has exactly
one write capability in the entire system — `createSupportRequest()` — and
everything else it can do is read-only.

Two findings change the shape of what I would build:

1. **There is no draft quotation.** `createCrmQuote()` posts the quote *and sends
   it to the customer in the same call*. There is no state between "does not
   exist" and "the customer has it".
2. **There are three lead stores**, and they do not reconcile.

---

## The six models you asked to see

### Customer model — uCRM `clients`

| | |
|---|---|
| Store | uCRM, via `CrmApiClient` |
| Lead flag | `isLead` boolean, or `clientType` (1 = lead, 2 = client) — `DishNetTools.php:523` |
| AI reads it | `identifyCustomerByPhone()` — matches on the **last 9 digits** |
| AI writes it | **Never.** Only `KycService` and `FtthCrmService` create clients |

### Lead model — three separate stores

**1. `leads.json`** — the pipeline the sales team actually works.

Fields in use: `id, customer_name, phone, service_type, source, source_detail,
priority, notes, retailer_id, assigned_to, assigned_name, assigned_by,
assigned_at, daily_assign_to/name/date, status, follow_up_date, call_log,
history, area, address, stale_flagged, stale_reassigned, wa_assigned_notified,
created_at, updated_at`.

- Auto-assignment: `cron_leads.php` — round-robin to the lightest-loaded active
  sales agent, target `lead_drip_size` (default 5) per agent.
- Alerts: `cron_lead_alerts.php`. Recovery: `lib/LeadRecoveryService.php`.
- Statuses seen in code: `open, pending, active, converted, checked_in`, plus
  `won/lost/dead` used as terminal states in filters.

**2. uCRM `isLead` clients** — the billing-side notion. Set by `KycService`
according to whether payment was received (`KycService.php:563-565`).

**3. `web_chat_leads.json`** — website chat leads (`web_chat.php:232-243`),
keyed by `session`, not phone.

**These three do not reconcile.** A website visitor who later messages WhatsApp
exists in two of them with nothing joining the records.

### Quotation model — uCRM `billing/quotes`

`lib/QuotationService.php`:

| Method | What it does |
|---|---|
| `createCrmQuote()` | POST `billing/quotes`, then **immediately** emails it — plugin MailService if `quote_email_via_plugin`, else `PATCH billing/quotes/{id}/send` |
| `sendLeadQuote()` | Quote from a `leads.json` lead; sets `status='quoted'`, `quote_ref`, `quoted_at` |
| `sendManualQuote()`, `sendCashSaleProforma()` | Agent-initiated |
| `buildProformaMessage()` | The WhatsApp proforma text |
| `getQuotes()` | Read back |

- **Numbering is uCRM's own** (e.g. `PF003847`), fetched from the POST response
  or re-fetched. Your §10 is already satisfied — there is no second numbering
  system and none should be added.
- **Branding defaults are South Sudan**: `COMPANY_PHONE = '+211920000000'`,
  `COMPANY_EMAIL = 'info@dishnetafrica.com'`, `CURRENCY = 'USD'`. All three are
  config-overridable (`quote_company_name/phone/email`), so **Uganda must have
  set them** or quotes carry Juba's number. Unverified — see the checklist below.

### Product / price model — uCRM

`DishNetTools::getProducts()` → `service-plans` + `products`, 60s cache, inactive
plans skipped, nulls preserved. Already the single source of truth, already used
by the AI, already covered by `price_check.php`. **Nothing to build here.**

### Conversation model — `wa_conversations` + `wa_messages`

Relevant columns: `phone, channel, crm_client_id, crm_client_name, status, state,
category, last_message_at, last_human_reply_at, message_count`, and — the one
that matters here — **`lead_id INTEGER DEFAULT NULL`** (`ConversationService.php:67`),
already present and already populated by the manual path.

### What the AI can do today

`ai_tools.php` exposes: `products`, `describe_product_schema`, `identify_customer`,
`customer`, `services`, `account`, `invoices`, `line_status` — all reads — and
`support_request`, the only write.

---

## The path that already exists, and is done by hand

`api/index.php:1085-1145` — "Manually converted from WA Inbox". A person in the
inbox presses a button and it:

1. Matches an existing lead on the **last 9 digits** of the phone, skipping
   `won/lost/dead`, and links the conversation to it if found — **§17 duplicate
   protection already works, and is already the right shape**.
2. Otherwise creates the lead, assigns the lightest-loaded sales agent, and sets
   `wa_conversations.lead_id`.

**Your Phase 1 is this function, called by the AI instead of by a person.**

---

## The dangerous integration point

`createCrmQuote()` has no draft. Creating is sending:

```php
$resp = $this->crm->post('billing/quotes', $payload);
...
if (!$viaPlugin) { $this->crm->patch("billing/quotes/{$quoteId}/send"); }
```

Any AI path that reaches this function is an AI sending a priced commercial
document to a customer with no human between. Your §8, §20 and §23 all exist to
prevent exactly that, and the current code cannot express the distinction.

I am not proposing to add draft/approve states to uCRM quotes. There is a
simpler answer below that gets the same guarantee without touching the money path.

---

## Gap analysis

| § | Requirement | Status |
|---|---|---|
| 1 | Phone → CRM lead | ◐ Exists, manual only |
| 2 | Intent-graded lead creation | ✗ No intent grading |
| 3–4 | Quotation decision engine | ✗ Nothing decides |
| 5 | Quote from price source of truth | ✓ uCRM already |
| 6–7 | Separate hardware/monthly/install; Business unpriced | ✓ Prompt already; Business already RED |
| 8 | Draft vs final | ✗ **No draft state exists** |
| 9 | Quotation document | ✓ `QuotePdfService`, `PluginQuotePdf` |
| 10 | Numbering | ✓ uCRM's own — do not add another |
| 11 | Conversation→Customer→Lead→Quote chain | ◐ `lead_id` exists; chain is manual |
| 12 | AI sales summary | ✗ |
| 13 | Lead priority | ◐ Field exists, nothing sets it intelligently |
| 14 | Follow-up task | ◐ Assignment + alerts exist |
| 15 | Human handoff without abandoning | ✓ Built today |
| 16 | Works on WhatsApp + website | ◐ Separate stores, unjoined |
| 17 | Duplicate protection | ✓ Last-9 match, already correct |
| 18 | Never invent data | ✓ Prompt rule; ✗ unenforced at write |
| 19 | Audit trail | ✗ |
| 21 | Qualification score | ✗ |
| 22 | CRM tool layer | ✗ One write exists |
| 23 | READ/DRAFT/WRITE/SEND permissions | ✗ |

---

## Proposed integration — smallest safe

You said it yourself, and it is the right split: **lead creation is low risk, a
quotation is a financial document.** I would build them as two phases with a
hard wall between.

### Phase 1 — the AI creates and updates leads (proposed)

- **`lib/AiLeadService.php`** — `findOrCreate()` reusing the *existing* last-9
  dedupe. I would lift that matcher out of `api/index.php` into one shared
  function so there is one dedupe rule in the codebase, not two copies that
  drift.
- **`<<LEAD ...>>` marker**, alongside the existing `<<ESCALATE>>`, `<<QUOTE>>`
  and `<<FLYER>>` — the same mechanism already parsed at
  `DishNetAiBrain.php:862`. The model emits structured qualification; it does
  not call an API.
- **Parsed post-send in the never-throw zone** of `AiReplyWorker`, so a CRM
  failure can never cost the customer their reply.
- Writes only: `phone, customer_name, area, customer_type, requirement,
  recommended, public_ip_required, users, existing_internet, source
  ('whatsapp_ai' / 'webchat_ai'), source_detail, priority, ai_summary, ai_score,
  status`. **Anything not established is null.** Never a guess, enforced in the
  service, not just asked for in the prompt.
- Links `wa_conversations.lead_id`, exactly as the manual path does.
- **`ai_crm_actions` audit log** — action, phone, conversation, what changed,
  why, confidence (§19).

### Phase 2 — quotation, draft only, never sent by the AI (proposed)

The AI **never calls `createCrmQuote()`**. Instead:

- It sets the lead to `quotation_required` and attaches a **line-item draft
  built by code from the live catalogue** — the model names products, the
  service resolves prices from uCRM and refuses if any line is unpriced.
- A person opens the lead and presses the existing send button.

That gives you §8, §20 and §23 without inventing an approval state machine, and
without the AI ever touching the money path. If you later want residential
quotes to send automatically, that becomes one gated config flag over a path
that has already been proven by hand.

### Not proposed

A fourth lead store. A second numbering system. A parallel CRM. Any change to
`createCrmQuote()`. Any n8n. Reconciling the three lead stores is real work and
worth doing, but it is not this task and I would not fold it in silently.

---

## Verify on the server before Phase 1

These are cheap and would each change the build:

1. **Quote branding** — is `quote_company_phone` set for Uganda, or are quotes
   going out with `+211920000000`?
2. **Are there active sales agents** in `retailers.json` with role
   `sales/field_agent/sales_staff` and `is_active`? Without one, auto-assignment
   silently assigns to nobody.
3. **How large is `leads.json`** — it is loaded whole and rewritten on every
   change (`$store->save('leads.json', $leads)`). If it is already thousands of
   rows, an AI writing on every qualified conversation changes the cost of that.
4. **Is `web_chat_leads.json` in use**, or superseded?
