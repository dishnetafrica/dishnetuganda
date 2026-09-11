# Phase 1 design — quote branding from uCRM, and AI-qualified leads

**Nothing in this document is implemented.** One read-only tool (`tools/org_probe.php`)
is included because the branding design needs real field names, and guessing them
is the mistake this whole change exists to end.

---

## Part A — Quotation company details from uCRM

### The ambiguity that has to be resolved first

"Read the organization" is not yet a well-defined instruction on this install:

- Your admin screen shows **DishNet Africa Limited at `/organizations/1/edit`**
- `lib/FtthCrmService.php:12` documents **"Org 2 — DishNet Africa Limited, Org 7 — FTTH Project"**

Taking `organizations[0]` would be a guess, and the thing being guessed is whose
phone number goes on a customer's quotation.

**Resolution: a quote is issued for a client, and a client carries its own
`organizationId`.** That is the answer with no guess in it.

### Lookup order — per field, not per source

```
For each of: name, phone, email, address, taxId, registrationNumber

  1. uCRM organization that the CLIENT belongs to
       clients/{id}.organizationId → organizations/{orgId}.{field}
       (no client on the quote — a lead quote — use the organization
        marked selected/default)

  2. Explicit plugin config          quote_company_{name,phone,email}

  3. Compiled constant               NEVER SILENTLY — see below
```

**Per field matters.** If uCRM holds the name but no phone, the name comes from
uCRM and the phone falls through to config independently. A per-*source*
hierarchy would take all-or-nothing and put a blank on the quote.

### Reaching the constant is a fault, not a fallback

Your instruction: *"must never silently use the old +211/Juba information."*

So the third tier will not be silent. When any field resolves to the compiled
constant, the plugin will:

- write a warning line to the quote log naming the field and the value used,
- surface it in `production-preflight.php` as a failing check,
- report it in `crm_audit.php`,

and for a **`+211` phone on a Uganda install**, refuse rather than print it —
`QuotationService` returns an error and the quote is not sent, because a wrong
callback number on a financial document is worse than a failed send that
somebody notices.

### What I still need before writing it

`tools/org_probe.php` (read-only, in this commit) prints every organization,
which of the branding fields are populated, which organization your clients
actually belong to, and what a quotation prints today. The field names above are
uCRM's documented ones; the probe confirms them against your instance.

```
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/org_probe.php
```

### Files that would change

| File | Change |
|---|---|
| `lib/QuotationService.php` | `companyDetails(?int $clientId): array` replaces the three `?? self::CONST` reads at lines 396-398 and 603 |
| `lib/CrmApiClient.php` | none — `get()` already does it |
| `production-preflight.php` | new check: branding resolves above the constant tier |
| `tools/crm_audit.php` | report the resolved source per field |

Organizations change rarely, so the lookup is cached like the catalogue.
Config keys stay supported as tier 2 — they are not removed, just demoted.

---

## Part B — AI-qualified leads

### The lifecycle

```
WhatsApp conversation                    ← the team keeps working here
        ↓
AI replies (unchanged)
        ↓
  ── reply already sent, never-throw zone ──
        ↓
Did the AI qualify a real opportunity?
   model says yes (<<LEAD>> marker)
        AND
   code agrees (minimum facts present)
        ↓ no  → nothing happens, no record, no noise
        ↓ yes
Find existing lead  (conversation.lead_id, else phone last-9)
        ↓
  found → UPDATE          not found → CREATE (unassigned if no agent)
        ↓
Link both ways: conversation.lead_id ↔ lead.conversation_id
        ↓
Audit line in ai_crm_actions
        ↓
Team continues in WhatsApp; uCRM holds the sales record
```

### What creates a lead, and what does not

Two gates, because a model alone is too eager and a keyword rule alone is too blunt.

**Gate 1 — the model** emits `<<LEAD ...>>` only when it judges there is a real
opportunity. **Gate 2 — the code** refuses to write unless a deterministic floor
is met: a stated **requirement**, plus at least one of **location**, **customer
type**, or **quote requested**. The marker alone never writes.

Never a lead (your examples, and the gate that stops each):

| Message | Why no lead |
|---|---|
| "How much is Starlink?" | no requirement — Gate 2 |
| "Do you install?" | no requirement — Gate 2 |
| "Does it work in Kampala?" | location only, no requirement — Gate 2 |
| general technical questions | no opportunity — Gate 1 |

Becomes a lead:

| Message | What satisfies Gate 2 |
|---|---|
| "I need Starlink for my hotel in Entebbe" | requirement + location + type |
| "Please send me a quotation" | quote requested |
| "I want it installed next week" | requirement + intent |
| "30-room hotel, unreliable internet, need CCTV remote access" | requirement + type + location-able |

### Duplicate prevention

1. `wa_conversations.lead_id` already set → that is the lead. No search.
2. Otherwise match on the **last 9 digits** of the phone, skipping
   `won/lost/dead` — **the exact rule `api/index.php:1085` already uses**.
3. Found → update. Not found → create.

That matcher is currently written inline in the manual path. It would be lifted
into one shared function and *called* by both, so there is one dedupe rule in
the codebase rather than two copies that drift apart.

The AI updating an existing lead never overwrites a human's edit with a null —
it fills gaps and appends to the summary.

### Unassigned is a valid state

Per your item 3: with no sales agent configured, the lead is created with
`assigned_to = null`, `status = 'open'`, `ai_qualified = true`. **No agent is
invented, and lead creation is never blocked on assignment.** When agents exist
later, the existing round-robin in `cron_leads.php` picks up unassigned leads
with no change.

### What is captured

All nullable. **Unknown stays null** — enforced in the service, not merely
requested in the prompt, so a chatty model cannot fill a field by talking.

`customer_name, phone, company, location, customer_type, requirement,
users_devices, existing_internet, recommended_solution, recommended_plan,
recommended_hardware, public_ip_required (yes/no/unknown),
cctv_remote_access (yes/no/unknown/na), quote_requested (yes/no),
ai_summary, conversation_id, source ('whatsapp_ai' | 'webchat_ai'),
status, ai_qualified, ai_score, created_at, updated_at`

### Files that would change

| File | Change |
|---|---|
| `lib/AiLeadService.php` | **new** — findOrCreate, gap-fill update, audit |
| `lib/LeadMatcher.php` | **new** — the one last-9 matcher, called by both paths |
| `api/index.php` | the inline matcher replaced by the shared call — behaviour identical |
| `lib/DishNetAiBrain.php` | `<<LEAD>>` marker + when to emit it |
| `workers/AiReplyWorker.php` | parse and dispatch, in the never-throw zone |
| `web_chat.php` | same call, `source = 'webchat_ai'` |
| `lib/ConversationService.php` | `linkLead()` |

Gated on **`ai_lead_capture`**; absent = nothing happens, so South Sudan and
this install until you switch it on.

---

## Part C — what does not change

**The sales logic is untouched.** `qualification()` is not modified by any of
this. The lead *records* what the AI recommended; it has no vote in how the AI
recommends. Business is still decided by a genuine public-IP requirement and
never by the customer calling themselves a business; the higher-capacity
residential plan is still the preferred answer for heavy use; CCTV is still
asked about rather than assumed.

**Neither number changes.** No routing, webhook, instance mapping, `EventBus`,
or lifecycle code is touched. 705 993 348 and 703 834 115 continue on the same
path they use today, which `wa_compare.php` reports side by side. Lead capture
hangs off the reply path *after* the customer has been answered — a CRM failure
cannot cost anyone a reply.

---

## Regression tests to be written

**Branding:** client's org wins over config; config wins over the constant; a
missing field falls through independently of the others; a `+211` phone on a
Uganda install refuses instead of printing; no client (lead quote) uses the
default organization; unreachable uCRM degrades to config rather than to Juba.

**Leads:** each non-qualifying example writes nothing; each qualifying one
writes once; **twenty messages from one phone produce one lead**; an existing
lead is updated not duplicated; a `won` lead is not resurrected; unknown fields
stay null; no agent → unassigned, never invented; conversation links both ways;
a CRM failure still leaves the customer answered; the marker alone without the
facts writes nothing.

Plus live scenarios in `conversation-suite.php` for your list.

---

## To proceed

1. Run `org_probe.php` and send me the output — it settles the organization id
   and the real field names.
2. Confirm the refusal behaviour: **should a `+211` phone block the quote**, or
   warn loudly and send anyway? I have designed it to block.

Then I build Part A and Part B, and show you the diff before it is deployed.
