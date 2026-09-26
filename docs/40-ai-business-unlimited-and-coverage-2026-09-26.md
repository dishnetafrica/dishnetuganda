# 40 — The WhatsApp AI on two questions: unlimited data for a business, and covering another area

26 September 2026. **A check, not a change.** No plugin code, setting, knowledge-base row or uCRM record was
changed. The fixes in §8 are proposals that wait for the operator's approval.

## 1. The request

> "Also some customer asked about buisness they want to run in that case they need unlimited data plans not
> buisenss plans with gb and when they talked about other area to be covered we already putted cost for outdoor
> accesspoint and mikoritk can you check how our ai replying for those query"

There are two questions here:

- **A. A customer running a business needs unlimited data.** That is a Residential plan, not a Business plan with
  a GB block.
- **B. A customer wants another area covered.** uCRM now prices an outdoor access point and a MikroTik.

## 2. What was measured, and what was not

- **Measured from the repository.** 5.18.43 is live (docs/07, 26 Sep 20:27 UTC), so this is the live code. The
  prompt was built by the live code path, `DishNetTools::getProducts()` → `BrainContext::build()` →
  `DishNetAiBrain`, with the knowledge base seeded from `tools/knowledge_seed.json`. The price list was a sample
  one that includes an "Outdoor Access Point" and a "MikroTik Router". Those names and prices are placeholders;
  the real ones are in uCRM.
- **Not measured.** No model was called: this session holds no AI key. The live uCRM product names and prices,
  the live knowledge-base rows (edits made in the admin tab win over the seed), and the AI's real replies are all
  unmeasured. `scripts/dnb-ai-check.sh` (§7) measures all three on the server.

## 3. Question A — a business that needs unlimited data

**What already works (measured).**
- **Business plans are held back.** The AI does not see them unless the customer needs a public IP or names a
  Business plan (`PlanCatalogue`, 5.18.27).
  - These messages show it only the two Residential plans: "I want to start a WiFi business in my trading centre.
    I need unlimited internet", "Do you have unlimited business plans?", and "I have a shop and I need internet
    for my business".
  - These show it all five: "How much is Business 500?", and "CCTV that I can view from my phone when I am away".
- **The qualification rules** (`ai_qualification`) say: *"WHAT DECIDES THE PLAN IS THE REQUIREMENT, NEVER THE
  LABEL"*, *"A BUSINESS PLAN IS FOR ONE THING — a PUBLIC IP"* and *"THE HIGHER-CAPACITY RESIDENTIAL PLAN IS YOUR
  DEFAULT ANSWER"*.
- **A reply that names a Business plan without naming Residential** gets the priority-data note appended in code
  (`PlanFenceGuard`).

**Gaps (measured).**
- **A-1. The AI is never told that the Residential plans are unlimited.** In the whole sales prompt (44,197
  characters) the word "unlimited" appears four times:
  1. Absolute rule 2: *"Do not describe a null field as unlimited"*. uCRM's plans carry no data limit, so
     this rule is what the AI reads about them.
  2. and 3. Twice about **Business**: *"after that block is used, unlimited standard data continues"*.
  4. Once in the note shown while Business is held back: *"a hotspot with many users needs unlimited standard
     data, not a priority-data cap"*. It names no plan.

  So a customer who asks "do you have unlimited plans?" gets an AI whose only "unlimited" product is a Business
  plan, after its priority block.
- **A-2. The sentences that say it are cut off before the AI reads them.** `KnowledgeBase::promptBlock()` cuts
  every approved fact at 600 characters. Six seeded facts are longer, and the two that matter lose exactly this
  point:
  - `BUSINESS_PLANS` (948 characters) loses *"A business with no public-IP requirement — a shop, a restaurant, a trading
    centre selling wifi, a guesthouse — is usually better served by the higher-capacity Residential plan with its
    unlimited standard data than by a small priority block."*
  - `MANY_USERS_HOTSPOT` (837) loses *"A busy public site with no public-IP requirement usually belongs on the
    higher-capacity Residential plan with unlimited standard data, not on a small Business priority block."*
  - The others cut are `PLAN_SERVICE_MAP` (807), `TOTAL_TO_GET_CONNECTED` (769), `WIFI_VS_SATELLITE_COVERAGE`
    (687) and `INSTALLATION_SCOPE` (630).
- **A-3. Asking for Business prices hands the conversation to staff.** The prospect rule says: *"both residential
  and business when they asked for both; where Business pricing is not in PLANS, say the team confirms that one
  and «ESCALATE»"*. PLANS holds no Business prices for a business that has not asked for a public IP. This one is
  minor: the conversation ends with a person, not with a wrong plan.

## 4. Question B — covering another area

**Where the prices land (measured).** A uCRM product whose name is not in the shop catalogue
(`assets/shop/catalogue.json`) goes to **HARDWARE**, the "one-time items", which every selling number sees. An
outdoor access point and a MikroTik named that way land there, with their prices. A product named exactly like a
catalogue accessory (for example "Router Mini") goes to ACCESSORIES instead.

**Gaps (measured).**
- **B-1. No rule links "cover another area" to those two items.** What the AI reads instead:
  - *"MANY PEOPLE ON ONE CONNECTION … needs a dish, a router, access points and someone to size it … take the site
    details, and «ESCALATE» for a site assessment"* (qualification);
  - *"Anything beyond the kit — extra access points, a mesh, switches … — is quoted separately, never folded into
    the kit price and never promised at a figure you do not have"* (hardware block);
  - *"multi-building cabling … commercial network setups are quoted separately after a site assessment"*
    (`INSTALLATION_SCOPE`).

  Nothing says DishNet sells an outdoor access point and a MikroTik at listed prices. Whether the AI quotes them
  or hands over is left to the model.
- **B-2. The price check refuses a total that multiplies a quantity.** This was measured with the live worker's
  own check (`AiReplyWorker::permittedValues` + `ReplyPrivacyGuard`).
  - Refused: *"Two Outdoor Access Points at UGX 450,000 each come to UGX 900,000 …"* (`foreign:amount`). The
    customer receives *"I'm not able to complete that one automatically…"* and staff are alerted.
  - Passed: one of each item, and kit + installation + access point + MikroTik.

  Covering an area usually takes more than one access point.
- **B-3. They can end up in a home quote.** The total rule reads *"the kit, the installation, and any other
  one-time charge in your data"*. ACCESSORIES carries a guard (*"Never add an accessory into TOTAL TO GET
  CONNECTED unless the customer chose it"*); HARDWARE has none. `--ask` scenario C1 measures this.
- **B-4. The sales number never sees ACCESSORIES.** `BrainContext::build()` keeps plans, hardware and stock only.
  Measured in the render: the ACCESSORIES block is absent on the sales number and present on support. The 20
  accessories of 5.18.11 are invisible to the AI where most sales happen. This is not specific to this question;
  it was found on the way.
- **B-5. A total may combine only the first six HARDWARE items** (`array_slice($hw, 0, 6)`). Today there are two
  kits and an installation. With the two new items that makes five; a seventh product would make every total that
  includes it refused. The check marks any item beyond the sixth.

## 5. The second AI, for completeness

`n8n/DishNet_Uganda_AI_Bot_v1.0.json` imports inactive. The plugin's `AiReplyWorker` is the path Uganda runs
(`UGANDA-PLAN-DECISION-HIERARCHY.md` §6). If the n8n bot were switched on, its rules would differ:
- *"Many concurrent users — a busy hotspot … point them at a heavier plan if the price list has one"*;
- *"Never describe a plan as unlimited unless the price list says it is"*;
- *"If they need routers or access points … quote only what is in the price list, as one-time hardware"*.

The check shows which AI is replying: the plugin's own replies per number over the last 7 days.

## 6. The existing live test does not test the Uganda prompt

`tests/conversation-suite.php` is documented as "run on the server with `--only=ug_`". It has four gaps:
- **It builds the brain without the knowledge base**, so `coverageRules()` falls back to the South Sudan block:
  *"This is DishNet SUDAN…"* and *"We do not sell a separate unlimited-only plan"*.
- **It skips `BrainContext`.**
- **It skips both reply checks.**
- **It prints a reply only when a turn fails.**

So its Uganda results do not show what Uganda customers get. `dnb-ai-check.sh --ask` asks the questions the way
the live worker does.

## 7. The check to run on the server

```
cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && mkdir -p /root/dnb-ai \
  && bash scripts/dnb-ai-check.sh --ask 2>&1 | tee /root/dnb-ai/check-$(date -u +%Y%m%dT%H%M%SZ).log
```

Send back the **log file**.

**What it shows, in four parts:**
1. Which AI answers, and its switches.
2. The price list exactly as the AI sees it, per number, with a ★ on the plans a "business, unlimited" customer
   is shown and every piece of network equipment marked.
3. Each knowledge-base row on these topics, and what of it the AI never sees.
4. Recent real conversations on these topics: the last 60 days, `DAYS=` to change, at most 12 per topic and two
   per conversation. Each shows the customer's message and the reply they got, and what that reply did.

**`--ask`** then puts nine questions, in seven conversations, to the installed AI on the sales number, through the
same path a customer's message takes. It prints each reply as the customer would receive it, what it did, and
which plans the AI was shown. That is nine model calls on the configured provider.

**What it does not do:**
- It writes and sends nothing. It reads a copy of the database, made as its owner in a temporary folder that is
  removed at the end. Settings are read without the vault refresh. The price list is fetched with its cache off.
- It prints no private data. Phone numbers, e-mail addresses and kit numbers are masked. Staff are shown as
  "staff", and the name they signed a message with is masked too. No key or token is printed.

**Why a copy.** The rehearsal's first draft opened the live database read-only. SQLite then left its `-wal` and
`-shm` files behind. On the server those files would belong to root, and the plugin could lose write access to
its own database. The journey audit's copy-as-owner rule avoids that; the rehearsal now checks it.

**Rehearsed** in `scripts/harness/ai-check/rehearse.sh`: **63/63, twice**.
- **What it runs against.** A fake uCRM, a fake AI provider and a fake `docker exec`, over a database built by
  the real migrations and knowledge seed.
- **Planted details.** The database carries a phone, an e-mail, a kit number, a staff name, an event's phone, the
  API key and the uCRM token. None appears in the output.
- **Nothing changes.** The database stays byte-identical and no file appears in the data directory.
- **It reads the copy, never the live file.** A conversation planted only in the copy is shown, and the check
  refuses to run when handed the live file.
- **Eight weakened copies are each caught:**
  - masking off;
  - the live database read instead of the copy;
  - a write through the connection;
  - the sales number asked without its context contract;
  - the price check skipped;
  - the knowledge base not loaded;
  - the plans reported before the Business filter;
  - the time window ignored.

## 8. Proposed changes — not made, for approval

| | Change | Where | Needs from the operator |
|---|---|---|---|
| **P1** | A stated fact that both Residential plans have unlimited standard data, shown to the AI as a BUSINESS FACT it may repeat (a new `ai_fact_*` setting, the `ai_fact_payment` pattern) | `DishNetAiBrain::localFacts`, `operatorText` | **The wording.** Are Residential Lite *and* Residential both without a cap in Uganda? |
| **P2** | Stop losing approved knowledge: raise the 600-character cut (to 1,000) and add a test that no seeded fact exceeds it | `KnowledgeBase::promptBlock` | — |
| **P3** | A coverage rule: to cover another area or building, quote the outdoor access point and the MikroTik from the price list, one unit each. Say the site survey confirms how many access points and the installation, then hand over | qualification block (sales rules) | **Yes or no:** quote the prices, or keep handing over without them? And the two products' exact uCRM names |
| **P4** | The AI quotes each item's unit price and never multiplies a quantity; the survey confirms the count. The price check stays as it is | same block | — |
| **P5** | Network equipment is never added to a total unless the customer chose it — the accessories guard, extended | sales rules | — |
| **P6** | The sales number sees ACCESSORIES (name and price only), like HARDWARE | `BrainContext::build` | — |
| **P7** | `tests/conversation-suite.php` builds the context the way the worker does (knowledge base, `BrainContext`, both checks), prints every reply, and gains the A and B scenarios | the test | — |

P1–P6 would ship as one plugin release, with tests and weakened copies as usual. They change what the AI says to
customers, so the wording of P1 and the decision in P3 come first.

## 9. What is needed

1. Run the command in §7 and send the log file.
2. From the log, or from uCRM → Products: the exact names of the outdoor access point and the MikroTik.
3. The P1 wording: are both Residential plans unlimited?
4. The P3 decision: when a customer wants another area covered, should the AI quote the access point and MikroTik
   prices, or keep handing over for a survey?
