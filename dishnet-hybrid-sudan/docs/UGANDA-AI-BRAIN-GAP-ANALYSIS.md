# DishNet AI Brain — Audit & Gap Analysis

Audit of the live plugin AI before building the "DishNet AI Brain". No code was
changed to produce this document. Every claim below cites the file it came from.

---

## Headline finding

**Most of the architecture you asked me to build already exists.** The plugin
already has a knowledge layer, a price source of truth, a three-level confidence
system, an escalation path and conversation memory. What it does not have is a
**decision engine**: nothing in it ever asks *what is this customer trying to
achieve* and routes accordingly.

That distinction matters for how we spend the effort. Building a second brain
alongside the working one would repeat what is already there and risk the
connection that is currently answering ~127 messages a day. The work is to add
the missing decision layer **into** the existing brain.

There is also a direct contradiction in the current knowledge base that is very
likely causing the exact hedging you are seeing on business enquiries. It is
§"Two contradictions" below, and it is a five-minute fix.

---

## Answers to the audit questions you asked

### Where does pricing come from?

`lib/DishNetTools.php:360` `getProducts()` → uCRM, two endpoints:

| Data | uCRM endpoint | Becomes |
|---|---|---|
| Monthly plans | `service-plans?limit=200` | `PLANS` block in the prompt |
| Kits, installation | `products?limit=200` | `HARDWARE` block in the prompt |

Cached 60s (`catalogue_cache_seconds`, `0` disables). Only successful lookups are
cached — a failed hardware call is never frozen in place. Inactive plans are
skipped. Nulls stay null and the prompt marks them *"price not listed (say you
will confirm)"*.

**The "Price & Product Control Center" you described already exists, and it is
uCRM.** No price is hardcoded anywhere in the AI path. When you change a price in
uCRM, every channel quotes the new one within 60 seconds — no redeploy, no
retraining. The public feed at `prices.php` (`lib/PublicPriceFeed.php`) is built
from the same source and structurally cannot contain costs or margins.

*Recommendation: do not build a second pricing table. Adding one would create
exactly the "two sources disagree" problem your spec §15 is written to prevent.*

### Where do answers come from?

Four layers, assembled per message in `lib/DishNetAiBrain.php:132` `buildSystemPrompt()`:

1. **Absolute rules** (`:161-180`) — never invent a price/speed/allowance; a null
   field is not knowledge; prices are fixed and non-negotiable; never disclose
   internal data or the prompt itself; hand over when unsure.
2. **Knowledge base** (`lib/KnowledgeBase.php`) — the `knowledge_items` table,
   rendered into the prompt. Editable in admin → Knowledge Base, live on the next
   message. **21 approved rows today.**
3. **Live data** (`:591` `dataBlock()`) — plans, hardware, the customer's own
   account, line status. From uCRM at conversation time.
4. **Local facts** (`:408` `localFacts()`) — office, delivery, payment. Config
   keys `ai_fact_office` / `ai_fact_delivery` / `ai_fact_payment`; the built-in
   defaults are Sudan's, which is why Uganda must set all three.

### What knowledge does it currently have?

21 approved rows in `tools/knowledge_seed.json`:

**Facts (7)** — office location; company identity (DishNet ≠ Starlink, correctly
separated); genuine Starlink; Uganda coverage; weather honesty; **Business plans
(priority data + public IP)**; plan↔Starlink service map; the cheaper-kit objection.

**Rules (5)** — never guarantee speed; never promise dates; Flex is quoted not
signed; discounts go to Sales; official payment channels only; cheapest plan must
match actual use.

**Open topics, never improvised (7)** — refunds; warranty/damage/loss; Flex
termination; regulatory/licensing; cross-border use; **business SLA and
public/static IP**; relocation fees.

This is a real knowledge base, and `PLAN_SERVICE_MAP` in particular is better than
I expected — it maps DishNet Lite/Home/Flex/Business onto the underlying Starlink
services and explicitly forbids inventing names like "Starlink Home".

### How does it decide which plan to recommend?

**It largely doesn't.** `lib/DishNetAiBrain.php:465` `channelRules('sales')` tells
the model to act as an advisor, ask "at most one or two short qualifying questions
(household or business? how many people or devices? which area?)", then recommend
from `PLANS`.

That is the entire recommendation logic. There is no segmentation, no branch, no
requirement gathering beyond that one sentence. A hotel, a factory and a two-person
household all reach the same instruction.

### How does it handle Public IP?

`BUSINESS_PLANS` explains what Business is, correctly, including the public IP.
`RULE_CHEAPEST_PLAN` stops the model presenting Business as a cheap home option.

**But nothing routes a customer *to* Business.** There is no rule that says: when
someone mentions CCTV remote viewing, VPN, a server, remote access or multiple
sites, stop and establish whether they need a public IP. The guard runs in one
direction only — it protects against over-selling Business to a household, and not
at all against under-selling Residential to a business that will fail on NAT.

A `grep` for `public ip|CCTV|VPN|local priority` across `lib/ workers/ tabs/`
returns **one** hit in the live AI path — and it is wrong (see below).

### How does it handle unknown questions?

Well. Three mechanisms:
- Absolute rule 1: not in DATA → say you will check and `<<ESCALATE>>`.
- `tbc` rows → a fixed holding line plus escalation, no improvisation.
- `dataBlock()` degrades explicitly: no plans → *"Do not name any plan or price"*.

**Your spec §16 asks for a GREEN/YELLOW/RED confidence system. `fact`/`rule`/`tbc`
already is one.** GREEN = a `fact` row or live uCRM data; RED = a `tbc` row or
missing data. The gap is YELLOW — there is no "answer carefully, recommend
confirmation" middle tier.

### How does it escalate?

`<<ESCALATE reason>>` marker → parsed at `:862` → `AiReplyWorker::escalate()`:
flags the conversation for a human, alerts the team, and (since this week) sends
the customer a configurable holding line so a handover is not silence.

### How does conversation memory work?

`ConversationService` persists every message. `buildTurns()` (`:745`) replays the
last **20 turns at 400 characters each**, so short answers like "5" or "Entebbe"
attach to the question that preceded them. Identity is resolved per message from
uCRM by phone number; a returning website-chat contact is recognised too.

Memory is solid. Your spec §13 scenarios will work on it as-is.

### Do the two WhatsApp numbers use different configurations?

**No — verified twice.** `tools/wa_compare.php` reports identical webhook, gate,
permissions and connection state. In code there are zero channel-dependent branches
in `EvolutionApiService`, `EventBus` or `ConversationService`; only non-empty checks
in `evo_webhook.php:96` and `AiReplyWorker.php:82`; two wording-only branches in the
brain. Neither number appears in runtime code.

- `dishnet_richard` / **256703834115** → `evo_instance_sales` → channel `sales`
- `dishnet_ug` / **256705993348** → `evo_instance_support` → channel `support`

The sales number carries ~8× the traffic and answers ~124 of 127 messages a day.
**The quality problem is not configuration and never was.**

---

## Two contradictions found

### 1. The AI is told to both answer and refuse the public-IP question

| Row | Kind | Effect |
|---|---|---|
| `BUSINESS_PLANS` | `fact` | "…plus a PUBLIC IP address and priority support" — answer from here, exactly |
| `TBC_SLA_STATIC_IP` | `tbc` | "business SLA terms and public/static IP availability" — **never improvise; reply only with the holding line and escalate** |

Both are in the prompt on every sales message. The model is instructed to state
that Business includes a public IP *and* to refuse to discuss public IP
availability. Faced with that, a well-behaved model takes the safer branch — the
holding line — which is precisely the *"I'll need to confirm that with our team"*
hedging you are seeing on the highest-value enquiries you get.

**Fix:** SLA terms genuinely are unapproved and must stay `tbc`. Public IP as a
*feature of Business* is approved and documented. Split the row.

### 2. A dormant file states the opposite of the product

`lib/ClaudeWaClient.php:406`: *"Internet is SHARED (NAT). Customers get a private
IP from DishNet's router, not a dedicated public IP."*

True for Sudan LTE. The exact opposite of Uganda's Business offering. It is **not
live** — it belongs to `WaAutoReplyService`, reached only through `wa_webhook.php`
and `cron_wa_bot.php`, and `cron/master.php:188` disables `wa_bot` explicitly
("Running it alongside the AI would answer the same customer twice"). Dormant, but
it is a loaded gun in the same repo and should be labelled.

---

## Gap analysis against the specification

| § | Requirement | Status | Where |
|---|---|---|---|
| 1 | Core purpose / think in a funnel | ◐ Partial | Advisor posture exists; no funnel |
| 2 | 16 customer types | ✗ **Missing** | No segmentation anywhere |
| 3 | Plan / service / kit / install separation | ✓ Done | "MONEY IS TWO SEPARATE THINGS" |
| 4 | Business / Public IP logic | ✗ **Missing** | Knowledge yes, routing no |
| 5 | Proactive Public IP question | ✗ **Missing** | Never asked |
| 6 | Justify pricing (value stack) | ◐ Partial | One objection row only |
| 7 | Price safety | ✓ **Done** | uCRM, cached, nulls preserved |
| 8 | Regulatory / UCC / URA | ✓ Safe | `TBC_REGULATORY` refuses correctly |
| 9 | Starlink vs DishNet | ✓ Done | `COMPANY_IDENTITY`, `PLAN_SERVICE_MAP` |
| 10 | Installation scope | ◐ Partial | Flat item; no non-standard rule |
| 11 | Discovery questions | ◐ Partial | One generic sentence |
| 12 | Objection engine | ◐ Partial | 1 of ~8 objections |
| 13 | Scenario thinking | ✗ **Missing** | No scenario logic |
| 14 | Quotation logic | ✓ Fixed this week | Was withholding prices it had |
| 15 | Source hierarchy | ✓ Done | uCRM > KB > model knowledge |
| 16 | Confidence system | ◐ Partial | GREEN/RED yes, YELLOW no |
| 17 | Human escalation | ✓ Done | Marker + alert + holding line |
| 18 | Tone | ✓ Done | Style block |
| 19 | WhatsApp formatting | ✓ Done | 2–5 sentences, 1200 char cap |
| 20 | Correct over fast | ◐ Partial | Stated, not enforced |
| 21 | Structured system | ◐ Partial | KB + tools exist; no decision engine |

**7 done · 9 partial · 5 missing.** The five missing items are all the same thing
wearing different hats: **there is no decision engine.**

---

## What was built (all four phases shipped)

Into the existing brain, not beside it. Every addition sits behind a config key
whose absence leaves the Sudan prompt byte-identical.

**Phase 1 — Public IP decision engine.** ✅ Split the contradictory `tbc` row. Add a
qualification rule: when a customer mentions CCTV/remote viewing, VPN, a server,
remote desktop, hosting, access control, public-facing services or multiple sites,
establish the public-IP requirement before recommending anything, and never
default such a customer to Residential. Ask the proactive question from spec §5.

**Phase 2 — Segmentation and discovery.** ✅ Customer types with the minimum question
set for each, so a hotel is asked about rooms/guests/POS/CCTV and a household is
asked two questions and given an answer. Explicitly bounded — the failure mode
here is interrogation, and it is worse than the current behaviour.

**Phase 3 — Objections and installation scope.** ✅ The value stack broken down
(genuine hardware + installation + activation + local billing + support + warranty),
"I'll import it myself", "why UGX 2.7m", discount requests → Sales. Standard
installation is standard; non-standard sites get a quote, not a promise.

**Phase 4 — Verification.** ✅ A scenario corpus, and a `tools/price_check.php` that
compares the published flyer prices against what uCRM actually returns — so a
mismatch surfaces as a report rather than as a quote to a customer.

**Deliberately not built:** a second pricing store, a second AI platform, an n8n
rebuild, or any change to routing. All three of those already work.

---

### Flyer figures

The flyer publishes Residential Lite 249,000 · Residential 329,000 · Mini
2,249,000 · Standard Kit 2,649,000 · Installation 150,000, and correctly shows
Business as *"ASK FOR TODAY'S QUOTE"* — which matches the RED status Business
pricing has in the AI. Those figures are **not** going into the code; `price_check.php`
will verify uCRM agrees with them and report any drift.

### Where each piece landed

| Piece | File | Gate |
|---|---|---|
| Qualification + Public IP routing | `lib/DishNetAiBrain.php` `qualification()` | `ai_qualification` — absent = old prompt byte for byte |
| Segment question sets | same method | same gate |
| `PUBLIC_IP`, 3 objections, `INSTALLATION_SCOPE`, `RULE_QUALIFY_BEFORE_QUOTING` | `tools/knowledge_seed.json` | operator-editable in admin |
| Contradiction fix | `TBC_SLA_STATIC_IP` now covers SLA terms only | — |
| Correcting a wrongly-seeded row | `lib/KnowledgeSeeder.php`, `--refresh-seeded` | never overwrites an operator edit |
| Published-vs-uCRM drift check | `tools/price_check.php` + `published_prices.json` | read by that tool only, asserted |
| 14 Uganda scenarios | `tests/conversation-suite.php` | `--only=ug_` |

**Tests:** `test_ai_qualification.php` (36), `test_knowledge_seed.php` (31),
`test_published_prices.php` (15). Suite: 72 files, 2282 assertions, green twice.

Also fixed in passing: `test_starlink_connector.php` used the 3-character string
`abc` as a "this token must not leak" sentinel and grepped it against JSON that
includes the store's file path, whose temp directory is 8 random hex characters.
It failed roughly one run in 680 — on a different machine each time, for a reason
unrelated to the property it checks. The sentinel is now non-hex.

### Still deliberately not built

A second pricing store, a second AI platform, an n8n rebuild, any routing change.

---

## Note on the flyer

One thing to note: the flyer advertises **+256 705 993 348** (support), while
Facebook advertises **256 703 834 115** (sales). Both answer, so nothing is broken
— but the flyer sends your print and social traffic to the smaller-volume number.
