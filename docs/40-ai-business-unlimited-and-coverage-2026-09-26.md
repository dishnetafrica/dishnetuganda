# 40 — The WhatsApp AI on two questions: unlimited data for a business, and covering another area

26 September 2026. **A check, not a change.** No plugin code, setting, knowledge-base row or uCRM record was
changed. The fixes in §8 are proposals that wait for the operator's approval.

**27 September:** the check ran on the server (§10), the operator decided both questions, and **5.18.44 was built**
from those decisions (§11). §1–§9 are the record as it stood before; where the build departs from them, §11.3
says so. The deploy command and what to send back are in §12.

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

**`--ask`** then puts ten questions, in eight conversations, to the installed AI on the sales number, through the
same path a customer's message takes. It prints each reply as the customer would receive it, what it did, and
which plans the AI was shown. That is ten model calls on the configured provider. *(As first committed, and as run
on 27 Sep, it asked nine in seven; A4, "I want to sell internet to the people around my shop", was added the same
day — §11.6.)*

**What it does not do:**
- It writes and sends nothing. It reads a copy of the database, made as its owner in a temporary folder that is
  removed at the end. Settings are read without the vault refresh. The price list is fetched with its cache off.
- It prints no private data. Phone numbers, e-mail addresses and kit numbers are masked. Staff are shown as
  "staff", and the name they signed a message with is masked too. No key or token is printed.

**Why a copy.** The rehearsal's first draft opened the live database read-only. SQLite then left its `-wal` and
`-shm` files behind. On the server those files would belong to root, and the plugin could lose write access to
its own database. The journey audit's copy-as-owner rule avoids that; the rehearsal now checks it.

**Rehearsed** in `scripts/harness/ai-check/rehearse.sh`: **63/63, twice** as first committed. Since 27 Sep it
runs against both 5.18.43 and 5.18.44: **183/183, twice** (§11.6).
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

## 10. The check on the server — 27 September 2026, 04:53 UTC

The operator ran §7's command on the server, at repository commit `b32199a`, against the live plugin 5.18.43.
**The report part came back; the `--ask` part did not** — the paste ends after the report. It is in the log file,
`/root/dnb-ai/check-20260927T0453*.log` (§12).

**Which AI answers.** The plugin's own assistant: OpenAI `gpt-4o-mini`, all four switches on, **1,795 replies on
the sales number and 365 on support in the last 7 days**. No extra instructions.

**The price list, live from uCRM.**
- Plans: Residential Lite 249,000, Residential 329,000, Business 50 GB 175,000, 500 GB 285,000, 1 TB 469,000. **No
  plan carries a data limit in uCRM.**
- One-time items, in uCRM's order: Mini Kit + Mini Router 2,249,000 · Standard Kit 2,649,000 · Professional
  Installation 150,000 · **ICT Consultancy Charges 100,000 · Ruijie Reyee RG-RAP6262(G) 1,100,000 · D-Link CAT 6
  outdoor cable 378,000 · MikroTik L009 Series 700,000 · RJ45 Cat6 connectors (pack) 19,500**.
- **The MikroTik and the connectors were the 7th and 8th items**, so every total with either was refused by the
  price check (B-5, now measured).
- 20 accessories, **none shown on the sales number** (B-4).
- The check's own name-guessing marked the Mini kit "network equipment" (its name contains "Router") and missed the
  access point (its name does not say so). 5.18.44 reads roles from the same list the assistant uses (§11.2).

**Knowledge.** Every row on these topics is **as seeded** — nobody has edited one. Six facts were cut at 600
characters. BUSINESS_PLANS lost its sentence on a trading centre selling Wi-Fi belonging on Residential, and
MANY_USERS_HOTSPOT lost its only "unlimited" (A-2, now measured).

**What customers were told** (12 + 12 messages shown; phones, e-mails and kit numbers masked by the check).
- **Every "business" message was framed as a Business plan.** *"It sounds like you might need a Business plan"*,
  *"For business needs, we can offer you a Business plan"*, *"Since you're looking to supply internet for a
  business, I can check the Business plan options"*, *"For a business connection, you'll need a plan that offers a
  public IP"*. None led with the Residential plan that was in front of it.
- **A customer with cloud software was recommended all three Business tiers** (c1363); a colleague then sent the
  Residential plans.
- **"Is it unlimited?" was answered "The plans we offer are not unlimited … After reaching their data limits, you
  will still have access but at reduced speeds"** (c1332). For the Residential plans that is false.
- **A would-be reseller was told "we don't have a reselling program"** (c1322) — a fact nobody gave it — and then
  "I don't have specific information about reselling".
- **Not once was the access point or the MikroTik priced.** A support customer named the product — *"Ruijie reyee
  outdoor omnidirectional access point mounted on a pole 2pcs"* — and was told *"I don't have specific information
  about those access points in our system. Could you please confirm the price"* (c1068). It was in uCRM at
  1,100,000 and in the prompt. Elsewhere: *"add additional routers and access points"* with no price (c1377).
- Asked for 2 to 3 km, it said a custom design and a site assessment were needed (c1114) — the right answer for
  kilometres.

**The decisions, the same morning, verbatim.**
- *Can the AI say both Residential plans are unlimited, with no cap?* → **"keep as it is"**.
- *Should the AI quote the outdoor access point and MikroTik prices?* → **"yes lets ai to desing and give price of
  accespoint if avaible in system"**.

## 11. 5.18.44 — as built, 27 September 2026

### 11.1 How the decisions were read

- **"keep as it is"** is read as approval of the wording put to the operator: *"Both Residential plans (Residential
  Lite and Residential) are unlimited, with no data cap. Only the Business plans come with a block of priority
  data (50 GB, 500 GB or 1 TB)."* It is `DishNetAiBrain::UNLIMITED_FACT`. `ai_fact_unlimited` replaces it and
  `omit` switches it off — **if "keep as it is" meant "leave the AI as it is", that is one command** (§12).
- **"design and give price"** is read as: design a starting setup from the network equipment uCRM prices, and
  price it in the same reply — one access point unless the customer names a number, the survey confirming the rest.

### 11.2 What changed — Uganda only

Every change sits behind a switch the South Sudan install does not set (`ai_qualification`, `ai_hardware_expert`,
and a knowledge base). §11.5 is the proof that South Sudan is unchanged.

| | Change | Behind | Where |
|---|---|---|---|
| 1 | **The data-allowance fact**, stated beside the plans, to be repeated word for word; the reply check treats it as the operator's own text, not a quote of the prompt | `ai_qualification`, a knowledge base, a Residential plan listed | `DishNetAiBrain::unlimitedFact`, `operatorText` |
| 2 | **A business gets the Residential plans.** The rule shown while Business is held back: *"A CUSTOMER WHO IS A BUSINESS … is answered with the Residential plans … Never tell a business it needs a Business plan because it is a business. A Business plan is for one thing, a PUBLIC IP"*. The prospect rule gives the Residential prices first and asks the public-IP question once | `ai_qualification` | `PlanCatalogue::askRule`, `DishNetAiBrain` |
| 3 | **Someone who wants to sell internet** is buying a connection and equipment: the higher-capacity Residential plan; *"Never tell them we have no reseller or partner programme: you do not know that"*; partner terms go to a person | `ai_qualification` | `DishNetAiBrain::qualification` |
| 4 | **Approved knowledge up to 1,000 characters** (was 600). No seeded answer is longer | `ai_qualification` | `KnowledgeBase::answerLimit` |
| 5 | **NETWORK EQUIPMENT**, its own list: the router, the access point, the cable, the connectors, the consultancy, each named by what it is for (`assets/shop/network.json`; no price or coverage figure there), with the rule to design and price: one line per item and a TOTAL; one access point, or the customer's number as quantity × price; the survey confirms the count, the cable and the installation; never a distance, area or user count; kilometres go to a person; never inside a home total | `ai_hardware_expert` | `NetworkEquipment`, `DishNetAiBrain::networkBlock` |
| 6 | **The price check** allows any combination of up to ten one-time items, and 2 to 5 of one access point with any of the others — **added to** the totals it allowed before, never instead of them | `ai_hardware_expert` | `AiReplyWorker::permittedAmounts` |
| 7 | **The accessories reach the sales number and the website chat**, name and price only | `ai_hardware_expert` | `BrainContext::catalogue` |
| 8 | **MANY_USERS_HOTSPOT**, the approved row for a site with many users, now says to design and price where the equipment is listed, then hand over to book the survey (994 characters, all of it reaching the assistant) | the knowledge seed; applied by the deploy's stage K | `tools/knowledge_seed.json` |
| 9 | `seed_knowledge.php --dry-run` (a transaction always rolled back) and `--only=KEY` (that row and no other); `ai_fact_unlimited` in `set_config.php` | — | `tools/` |
| 10 | The check reads either version and says which; `tests/conversation-suite.php` asks the way the worker does (P7) | — | `scripts/`, `tests/` |

### 11.3 Where the build departs from §8, and why

- **P3/P4 — quantities.** §8 proposed one of each and never a multiplication, with the price check unchanged.
  Built: one access point unless the customer names a number, then *quantity × price*, and the price check allows
  2 to 5 of an access point. The operator's word was "design", and c1068 asked for two by name. **Only access points
  are multiplied** (§11.4 F-1 says why).
- **P2 — the limit is gated.** 1,000 characters only where the install qualifies, so an install with its own long
  approved answers reads them exactly as before.
- **P6 — the website chat too.** Found while testing: `web_chat.php` passes the whole catalogue to `BrainContext`,
  so the contract change alone would have shown **every** install's website chat the accessories, South Sudan's
  included. The South Sudan fingerprints (§11.5) caught it before anything shipped; both callers now go through one
  rule, `BrainContext::catalogue`.
- **The reply check's copy of the fact is gated like the prompt**, so South Sudan's reply check is unchanged too.
- **MANY_USERS_HOTSPOT (new).** It told the assistant to *"hand over for a site assessment"* — the opposite of the
  decision — and approved knowledge outranks a prompt rule (*"answer these topics from here, exactly and only"*).
  The live row is as seeded (§10), so the release corrects it through the tool's own mechanism, `--refresh-seeded`,
  limited to that row (`--only`), after a dry run. The 5.18.4 correction of PLAN_SERVICE_MAP is the precedent.
- **The SELL INTERNET rule is conditional.** Its first draft pointed at the design rule unconditionally; where no
  network equipment is listed it now says to take the site details for an assessment instead.
- **P7** is built: knowledge base at the worker's limit, the worker's own context, both reply checks on WhatsApp,
  every reply printed, and six scenarios: `ug_unlimited`, `ug_business_unlimited`, `ug_sell_internet`,
  `ug_cover_other_building`, `ug_two_access_points`, `ug_home_total_no_network`.

### 11.4 Findings from the build, measured

- **F-1. The wider price check lets more round figures through — the cost of allowing designs.** Measured on the
  live catalogue, round amounts from 100,000 to 10,000,000 in steps of 50,000 (199 of them): **5.18.43 permits 14;
  5.18.44 permits 67**, because every combination of ten listed prices is now a legitimate total and those prices
  are round. A wrong figure that happens to equal a real combination passes; the check cannot tell which item a
  figure belongs to. The first draft multiplied every network item and permitted 81 — ten "ICT consultancy" charges
  made every 100,000 up to a million legal — so **only access points are multiplied**. An invented access-point
  price (1,234,000 in the tests) is still refused.
- **F-2. Pre-existing, not changed: the prompt-digits rule.** `ReplyPrivacyGuard` also accepts an amount whose
  digits occur anywhere in the prompt's digits run together. On the same 199 round amounts, **5 pass only that way
  in 5.18.43 (800,000 · 1,950,000 · 7,800,000 · 9,700,000 · 10,000,000) and 5 in 5.18.44 (500,000 · 1,500,000 ·
  5,000,000 · 9,700,000 · 10,000,000)**. The fix — whole numbers only — changes the reply check on both installs,
  so it is **proposal P8, for approval**, not part of this release. The test suite records it as a gap, so the
  day it is fixed that assertion has to be rewritten on purpose.
- **F-3. The check's old name-guessing was wrong both ways** (§10); 5.18.44 mode uses the assistant's own roles.
- **F-4. `seed_knowledge.php` ignores `DN_DATA_DIR`** and finds its database from its own path. Measured when a dry
  run in a test sandbox, invoked through a linked `tools/`, seeded this workspace's development database; the rows
  were removed and nothing else was touched. On the server there is one plugin, so it is harmless there; the
  suite now copies `tools/` for real and checks the development database afterwards.

### 11.5 South Sudan: unchanged, measured

- **All 50 prompts byte-identical to 5.18.43** — two South Sudan-shaped configurations × five messages × (sales,
  support and account in the legacy shape; the sales number's contract; the website chat), against fingerprints
  taken from commit `04155df` (`tests/fixtures/ai_prompt_golden_south_sudan.json`). A control: the website chat
  without the catalogue rule moves the fingerprint.
- **The price check's list, value for value and in order**, identical to 5.18.43's for fixed inputs with the
  hardware module off (a sha1 golden).
- The reply check's operator text unchanged (empty for those configurations); approved knowledge cut at 600.

### 11.6 Proofs

- `tests/test_ai_unlimited_and_network.php` — **159 assertions**, including the tool executed against a throwaway
  database (dry run writes nothing; `--only` touches one row; a person's row is never touched). **Nineteen weakened
  copies of the code each fail it**, from "the network list on every install" and "the website chat skipping the
  catalogue rule" to "a dry run that writes".
- No existing suite needed changing — every change is behind a switch those suites leave off.
- `scripts/harness/ai-check/rehearse.sh` runs the check against **both 5.18.43 and 5.18.44**: **183/183, twice**,
  with thirteen weakened copies of the check (eight on both versions, four more on 5.18.44, one more on 5.18.43).
- The full suite, twice: **209 suites, exit 0; the 188 that print totals report 8,426 passed, 0 failed, on both runs.**

### 11.7 What a prompt cannot promise

`gpt-4o-mini` follows long rule sets loosely: §10 shows it framing every business as Business with the Residential
plans in front of it. The prompt now says the right thing, and the price check keeps it from quoting a price that
is not in uCRM. **Whether it follows is measured by `--ask` after the deploy, not assumed.**

## 12. Deploy, and what to send back

1. **The baseline:** the `--ask` part of `/root/dnb-ai/check-20260927T0453*.log` — the ten answers from 5.18.43.
2. **The deploy:**
   ```
   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg \
     && mkdir -p /root/dnb-5.18.44 \
     && bash scripts/deploy-5.18.44.sh 2>&1 | tee /root/dnb-5.18.44/deploy-$(date -u +%Y%m%dT%H%M%SZ).log
   ```
   It takes a backup, asks for `DEPLOY`, deploys, corrects MANY_USERS_HOTSPOT (stage K: a dry run first, that row
   only, only while still as seeded), re-checks the public pages (stage V), then asks the assistant the ten
   questions (stage AI) and prints the replies. Send back **the log file**.
3. **To switch the data-allowance fact off**, as the plugin's owner (the docs/20 form):
   ```
   docker exec -u $(stat -c %u:%g /home/unms/data/ucrm/ucrm/data/plugins/dishnet-hybrid-sudan) -w /data/ucrm/data/plugins/dishnet-hybrid-sudan ucrm php tools/set_config.php --key ai_fact_unlimited --value omit
   ```
   `--clear` in place of `--value omit` goes back to the approved wording.
